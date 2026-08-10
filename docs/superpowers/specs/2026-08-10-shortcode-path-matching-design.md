# Path matching for the shortcode and block — design

Date: 2026-08-10
Status: approved, ready for planning
Branch: `feat/shortcode-path-matching`, off `main` at v1.1.6

## Problem

Some themes will not render a shortcode on the homepage. The workaround is to put the
widget in a widget area instead — but a widget area is site-wide, so the block that was
meant for the homepage now appears on every page on the site.

The plugin has no way to say "render here, not there". `ADVTN_Shortcode::render()` and
`ADVTN_Block::render()` both hand straight off to `ADVTN_Renderer::render()`, which knows
nothing about the request.

## Goals

- One attribute on the shortcode and the block that limits rendering to a list of paths.
- No effect whatsoever on existing placements that do not use it.
- No new render-cache variants.

## Non-goals

- Fixing the theme restriction. A theme that strips shortcodes from the homepage will
  still strip them; the widget remains how the block reaches the homepage. This spec fixes
  the widget appearing *everywhere*, nothing more.
- Prefix or wildcard matching. Rejected during design: `/archive` silently swallowing forty
  child URLs is worse than making the user add a second entry, and a prefix rule needs
  segment-boundary care (`/news` must not catch `/newsletter`) for a case nobody has asked
  for. If it is wanted later, a trailing `*` is the additive way in.
- A global setting. Path gating is a property of one placement, not of the site.
- Gating the `advtn_trending_now()` template tag. A theme template already controls its own
  placement; the tag's caller has `if` statements.

## 1. `ADVTN_Path_Match`

New file `includes/class-advtn-path-match.php`. Pure static, no constructor, no injected
services — the same shape as `ADVTN_URL` and `ADVTN_Attempts`, and for the same reason: it
is then reachable from the dependency-free harness.

```php
ADVTN_Path_Match::normalize( string $path ): string
ADVTN_Path_Match::current_path(): string
ADVTN_Path_Match::matches( string $list ): bool
```

### `normalize()`

Returns a comparable path: exactly one leading slash, no trailing slash, `/` for the root.
Lowercased. Query string and fragment removed. Percent-decoded.

**An empty input returns `''`, not `/`.** This is load-bearing rather than pedantic: if an
empty string normalized to the root, then an absent `REQUEST_URI` would silently satisfy a
list containing `/`, which is exactly the fail-open behaviour `current_path()` is written to
avoid. Only a genuine `/` — or a path that reduces to one, like `//` — produces the root.

`strtolower()` is byte-safe on UTF-8 — it maps only `A`–`Z` — so a non-ASCII slug is left
alone and compares case-sensitively. That is a documented limitation, not a bug to chase:
WordPress slugs are lowercase ASCII by default, and the alternative is depending on
`mb_strtolower`, which core does not polyfill.

### `current_path()`

`$_SERVER['REQUEST_URI']`, normalized, with the site's own base path removed so a
subdirectory install behaves: on a site at `https://example.com/blog`, a request for
`/blog/` yields `/`, and `/blog/archive` yields `/archive`. The base comes from
`wp_parse_url( home_url(), PHP_URL_PATH )`.

**When `REQUEST_URI` is absent it returns `''`**, which fails a non-empty list. That is
deliberate: the only contexts without a request path are WP-CLI and cron, and rendering
where the path cannot be verified is worse than rendering nothing. It does not affect
`wp trending-now render`, which calls the renderer directly and never passes through the
gate.

### `matches()`

- An empty list — absent, `''`, or only separators and whitespace — returns `true`. Nothing
  existing changes, and a typo that empties the attribute fails toward the current
  behaviour rather than blanking the widget everywhere.
- Otherwise the list is split on commas, each entry trimmed and normalized, and entries
  that normalize to `''` dropped — **dropped after normalizing, not before**, so a stray
  `,,` or a `" "` entry cannot become an empty needle that an empty `current_path()` would
  match. What remains is compared for equality against `current_path()`.

## 2. Shortcode

`match_path` joins the `shortcode_atts()` defaults, and so does `matchpath`.

Both are needed. `shortcode_parse_atts()` lowercases attribute *names*, so
`matchPath="/,/archive"` in markup arrives as `matchpath` — and `shortcode_atts()` discards
keys it does not know about, so a `match_path`-only attribute would make that markup a
silent no-op. `match_path` is the canonical name and matches the snake_case of every other
shortcode attribute; `matchpath` is the alias that makes the camelCase spelling work. The
two are coalesced, `match_path` winning if somebody sets both.

The gate runs before anything else in the callback:

```php
if ( ! ADVTN_Path_Match::matches( $list ) ) {
	return '';
}
```

## 3. Block

A `matchPath` string attribute in `blocks/trending-now/block.json` — camelCase is correct
here, matching `showImages` and `showSeeAll` — plus a `TextControl` in the existing
inspector panel.

`ADVTN_Block::render()` applies the same gate, **except in the editor**. The block previews
through `wp-server-side-render`, which reaches the render callback over REST with the
editor's own URL, so an unguarded gate would render the block blank while it is being
edited and read as broken. Bypass when `defined( 'REST_REQUEST' ) && REST_REQUEST`, or
`is_admin()`.

## 4. Why the gate sits outside the renderer

`ADVTN_Renderer::render()` keys its cache on `md5( wp_json_encode( $args ) )`. Passing the
path list into `$args` would mint a separate cached copy of byte-identical HTML for every
distinct list, for no benefit — and worse, it would invite a future change that varies the
*output* by path, which the cache cannot express because the key has no path in it.

So the gate lives in the two callers, returns `''` before `render()` is reached, and the
list never enters `$args`. A placement that does render shares the same cache variant it
always did.

The host page cache needs no change: it keys on the URL, so per-path output is correct by
construction.

## Testing

`tests/run.php` covers `ADVTN_Path_Match` end to end, since `home_url()` is already
shimmed and `$_SERVER['REQUEST_URI']` is settable from a test:

- `normalize()`: root, no leading slash, trailing slash, both slashes, a query string, a
  fragment, percent-encoding, mixed case, and an empty string.
- `current_path()`: a plain path, a path with a query string, a subdirectory install where
  the base must be stripped, the subdirectory root, and `REQUEST_URI` absent.
- `matches()`: empty list; a list of only commas and spaces; a single exact hit; a miss; a
  hit on the second entry; entries written with and without trailing slashes against a
  request with the opposite; `/` matching the root and not matching `/archive`; and
  `/archive` not matching `/archive/page/2/`, which is the whole point of choosing exact
  matching.

The shortcode and block callbacks need WordPress and belong to an integration suite; their
gate is one line calling a fully covered function.

## Constraints

- PHP 7.4: no `str_contains`, `str_starts_with`, `str_ends_with`, union types, constructor
  promotion, `match`, or nullsafe operators.
- No `$wpdb`. No HTTP. Nothing fetched during a pageview.
- `declare( strict_types=1 )` and an `ABSPATH` guard on the new file.
- Escape at output — the block's inspector value is stored as a block attribute and is only
  ever compared, never printed, but the editor field follows the existing controls' shape.
- Empty means empty: a non-match returns `''`, no container, no wrapper.

## Acceptance criteria

1. `[trending_now]` with no `match_path` renders exactly as it does today, on every path.
2. `[trending_now matchPath="/,/archive"]` renders on `/` and `/archive` and nothing else —
   the camelCase spelling works.
3. `[trending_now match_path="/,/archive"]` behaves identically.
4. `/archive/` in the list matches a request for `/archive`, and the reverse.
5. `/archive` does not render on `/archive/page/2/`.
6. `/?utm_source=x` still matches `/`.
7. On a subdirectory install, `/` matches the site's homepage rather than the server root.
8. A block with `matchPath` set renders in the editor regardless of the editor's URL.
9. A non-matching placement adds no entry to `advtn_render_cache_keys`.
10. `php tests/run.php` passes, including the new cases.
11. All PHP files lint clean under PHP 7.4.
