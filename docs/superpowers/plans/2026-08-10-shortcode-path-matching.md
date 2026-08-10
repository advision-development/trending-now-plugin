# Shortcode and Block Path Matching Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let one attribute on the shortcode and the block limit rendering to a comma-separated list of page paths, so a site-wide widget can carry a homepage-only block.

**Architecture:** A new pure-static `ADVTN_Path_Match` owns all path logic, split so the comparison functions take their inputs as arguments and only one thin wrapper reads `$_SERVER` and `home_url()` — that split is what makes the subdirectory-install case testable. Both callers gate before `ADVTN_Renderer::render()`, so the path list never enters the cache key.

**Tech Stack:** WordPress 6.4+, PHP 7.4, no build step. Tests run through `php tests/run.php` against shimmed core functions.

**Spec:** `docs/superpowers/specs/2026-08-10-shortcode-path-matching-design.md`

## Global Constraints

Every task's requirements implicitly include this section.

- **PHP 7.4 syntax only.** No `str_contains`, `str_starts_with`, `str_ends_with`, union types, constructor promotion, `match`, nullsafe `?->`, or named arguments. Typed properties and arrow functions are fine.
- **Prefix everything** `advtn` / `ADVTN`. Text domain `trending-now`.
- **No `$wpdb`, no HTTP.** Nothing here fetches anything.
- **Escape at the point of output** — `esc_url`, `esc_html`, `esc_attr`, no exceptions.
- **Empty means empty.** A non-matching placement returns `''` — no container, no wrapper.
- **The gate never enters `$args`.** `ADVTN_Renderer::render()` keys its cache on `md5( wp_json_encode( $args ) )`; a path list in there would mint a separate cached copy of identical HTML per list.
- **Every PHP file** opens with `declare( strict_types=1 );` and an `ABSPATH` guard, uses tabs, and carries a docblock on every class and method.
- **Autoloading is a naming convention**, not Composer: `ADVTN_Path_Match` resolves to `includes/class-advtn-path-match.php`. No registration needed.
- The JavaScript has **no build step and no dependencies** — plain ES5-compatible browser JS in the existing `wp.element` style.
- Lint: `find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l`
- Test: `php tests/run.php` (currently **96 passed / 0 failed**)

---

### Task 1: ADVTN_Path_Match

The whole feature's logic, and the only part with test coverage. Four of the five methods are pure functions of their arguments; `current_path()` is a three-line wrapper over the two that are not.

**Two methods here are not in the spec's stated API.** The spec §1 lists `normalize()`,
`current_path()` and `matches()`; this plan also produces `path_from()` and
`matches_path()`. That is an addition, not a contradiction — the spec's own Testing section
requires covering the subdirectory-install case and the absent-`REQUEST_URI` case, and
neither is reachable while the base path and the current path are read inside the function
under test. Splitting the pure comparison out is what makes those tests possible.

**Files:**
- Create: `includes/class-advtn-path-match.php`
- Modify: `tests/bootstrap.php` — one new `require_once`
- Test: `tests/run.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `ADVTN_Path_Match::normalize( string $path ): string`
  - `ADVTN_Path_Match::path_from( string $request_uri, string $home_path ): string`
  - `ADVTN_Path_Match::current_path(): string`
  - `ADVTN_Path_Match::matches_path( string $current, string $list ): bool`
  - `ADVTN_Path_Match::matches( string $list ): bool`

- [ ] **Step 1: Write the failing tests**

Append to `tests/run.php`, before the final `/* ---- */` separator and the summary `printf`:

```php
/* ---------------------------------------------------------------------- */
/* Path matching                                                           */
/* ---------------------------------------------------------------------- */

advtn_assert_same( '/', ADVTN_Path_Match::normalize( '/' ), 'path normalize: root stays root' );
advtn_assert_same( '/', ADVTN_Path_Match::normalize( '//' ), 'path normalize: repeated slashes reduce to root' );
advtn_assert_same( '', ADVTN_Path_Match::normalize( '' ), 'path normalize: empty stays empty, it is NOT the root' );
advtn_assert_same( '', ADVTN_Path_Match::normalize( '   ' ), 'path normalize: whitespace-only is empty' );
advtn_assert_same( '/archive', ADVTN_Path_Match::normalize( '/archive' ), 'path normalize: a plain path is unchanged' );
advtn_assert_same( '/archive', ADVTN_Path_Match::normalize( '/archive/' ), 'path normalize: a trailing slash is dropped' );
advtn_assert_same( '/archive', ADVTN_Path_Match::normalize( 'archive' ), 'path normalize: a leading slash is added' );
advtn_assert_same( '/archive', ADVTN_Path_Match::normalize( ' /archive/ ' ), 'path normalize: surrounding whitespace is trimmed' );
advtn_assert_same( '/archive', ADVTN_Path_Match::normalize( '/Archive' ), 'path normalize: lowercased' );
advtn_assert_same( '/', ADVTN_Path_Match::normalize( '/?utm_source=x' ), 'path normalize: the query string is not part of the path' );
advtn_assert_same( '/archive', ADVTN_Path_Match::normalize( '/archive?page=2' ), 'path normalize: query dropped from a deeper path' );
advtn_assert_same( '/archive', ADVTN_Path_Match::normalize( '/archive#top' ), 'path normalize: the fragment is dropped' );
advtn_assert_same( '/my page', ADVTN_Path_Match::normalize( '/my%20page' ), 'path normalize: percent-encoding is decoded' );
advtn_assert_same( '/a/b', ADVTN_Path_Match::normalize( '/a//b/' ), 'path normalize: interior repeated slashes collapse' );

advtn_assert_same( '/archive', ADVTN_Path_Match::path_from( '/archive', '/' ), 'path from: a root install passes the path through' );
advtn_assert_same( '/archive', ADVTN_Path_Match::path_from( '/archive/', '' ), 'path from: an empty base is treated as root' );
advtn_assert_same( '/', ADVTN_Path_Match::path_from( '/', '/' ), 'path from: the root of a root install is the root' );
advtn_assert_same( '/', ADVTN_Path_Match::path_from( '/blog/', '/blog' ), 'path from: the root of a subdirectory install is the root' );
advtn_assert_same( '/', ADVTN_Path_Match::path_from( '/blog', '/blog/' ), 'path from: base and request agree regardless of trailing slashes' );
advtn_assert_same( '/archive', ADVTN_Path_Match::path_from( '/blog/archive', '/blog' ), 'path from: the base is stripped from a deeper path' );
advtn_assert_same( '/archive/page/2', ADVTN_Path_Match::path_from( '/blog/archive/page/2/', '/blog' ), 'path from: the base is stripped from a deep path' );
advtn_assert_same( '/blogging', ADVTN_Path_Match::path_from( '/blogging', '/blog' ), 'path from: a base is only stripped at a segment boundary' );
advtn_assert_same( '', ADVTN_Path_Match::path_from( '', '/blog' ), 'path from: no request path yields no path' );

advtn_assert_same( true, ADVTN_Path_Match::matches_path( '/anything', '' ), 'path matches: an empty list gates nothing' );
advtn_assert_same( true, ADVTN_Path_Match::matches_path( '/anything', '  ' ), 'path matches: a whitespace list gates nothing' );
advtn_assert_same( true, ADVTN_Path_Match::matches_path( '/anything', ',,' ), 'path matches: a list of separators gates nothing' );
advtn_assert_same( true, ADVTN_Path_Match::matches_path( '/', '/' ), 'path matches: the root matches a root entry' );
advtn_assert_same( true, ADVTN_Path_Match::matches_path( '/archive', '/,/archive' ), 'path matches: a hit on the second entry' );
advtn_assert_same( true, ADVTN_Path_Match::matches_path( '/archive', '/ , /archive/ ' ), 'path matches: entries are normalized before comparing' );
advtn_assert_same( false, ADVTN_Path_Match::matches_path( '/news', '/,/archive' ), 'path matches: an unlisted path does not match' );
advtn_assert_same( false, ADVTN_Path_Match::matches_path( '/archive', '/' ), 'path matches: the root entry does not match a deeper path' );
advtn_assert_same( false, ADVTN_Path_Match::matches_path( '/archive/page/2', '/,/archive' ), 'path matches: matching is exact, not prefix' );
advtn_assert_same( false, ADVTN_Path_Match::matches_path( '/archive-2024', '/,/archive' ), 'path matches: a path that merely starts the same does not match' );
advtn_assert_same( false, ADVTN_Path_Match::matches_path( '', '/' ), 'path matches: an unknown current path fails a non-empty list' );
advtn_assert_same( true, ADVTN_Path_Match::matches_path( '', '' ), 'path matches: an unknown current path still passes an empty list' );

// current_path() is the one method that reads request state.
$advtn_saved_uri = $_SERVER['REQUEST_URI'] ?? null;

$_SERVER['REQUEST_URI'] = '/archive/?utm_source=x';
advtn_assert_same( '/archive', ADVTN_Path_Match::current_path(), 'current path: read from REQUEST_URI, query dropped' );

$_SERVER['REQUEST_URI'] = '/';
advtn_assert_same( '/', ADVTN_Path_Match::current_path(), 'current path: the homepage is the root' );

unset( $_SERVER['REQUEST_URI'] );
advtn_assert_same( '', ADVTN_Path_Match::current_path(), 'current path: absent REQUEST_URI yields no path' );
advtn_assert_same( false, ADVTN_Path_Match::matches( '/' ), 'current path: with no request path, a list fails closed' );
advtn_assert_same( true, ADVTN_Path_Match::matches( '' ), 'current path: with no request path, an empty list still passes' );

if ( null === $advtn_saved_uri ) {
	unset( $_SERVER['REQUEST_URI'] );
} else {
	$_SERVER['REQUEST_URI'] = $advtn_saved_uri;
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php tests/run.php`

Expected: a PHP fatal — `Class "ADVTN_Path_Match" not found`. That is the failure signal; the class does not exist yet.

- [ ] **Step 3: Create the class**

Create `includes/class-advtn-path-match.php`:

```php
<?php
/**
 * Path matching for placements that must not render site-wide.
 *
 * A widget area is site-wide by construction, so a block meant for the
 * homepage appears on every page. This is how a placement says "here, not
 * there".
 *
 * Everything except current_path() is a pure function of its arguments, which
 * is deliberate: it is what lets the dependency-free harness cover the
 * subdirectory-install case without a settable home_url().
 *
 * @package Advision\TrendingNow
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ADVTN_Path_Match {

	/**
	 * Reduce a path to a comparable form.
	 *
	 * One leading slash, no trailing slash, `/` for the root, lowercased, with
	 * the query string and fragment removed and percent-encoding decoded.
	 *
	 * An empty input returns '' rather than '/'. That is load-bearing: if empty
	 * normalized to the root, an absent REQUEST_URI would silently satisfy a
	 * list containing '/', which is the fail-open behaviour current_path() is
	 * written to avoid.
	 *
	 * strtolower() maps only A-Z, so it is byte-safe on UTF-8: a non-ASCII slug
	 * is left intact and compares case-sensitively. Documented rather than
	 * chased — WordPress slugs are lowercase ASCII, and the alternative is
	 * depending on mb_strtolower(), which core does not polyfill.
	 *
	 * @param string $path Raw path, or anything path-shaped.
	 * @return string
	 */
	public static function normalize( string $path ): string {
		$path = explode( '#', $path, 2 )[0];
		$path = explode( '?', $path, 2 )[0];
		$path = rawurldecode( trim( $path ) );

		if ( '' === trim( $path ) ) {
			return '';
		}

		$path = (string) preg_replace( '#/+#', '/', $path );
		$path = rtrim( '/' . ltrim( $path, '/' ), '/' );

		return '' === $path ? '/' : strtolower( $path );
	}

	/**
	 * The request path with the site's own base removed.
	 *
	 * On an install at https://example.com/blog, a request for /blog/ yields /
	 * and /blog/archive yields /archive — so a list written as "/" means this
	 * site's homepage rather than the server root.
	 *
	 * The base is only stripped at a segment boundary, so a base of /blog does
	 * not eat the leading characters of /blogging.
	 *
	 * @param string $request_uri Raw REQUEST_URI.
	 * @param string $home_path   Path component of home_url().
	 * @return string
	 */
	public static function path_from( string $request_uri, string $home_path ): string {
		$path = self::normalize( $request_uri );
		$base = self::normalize( $home_path );

		if ( '' === $path || '' === $base || '/' === $base ) {
			return $path;
		}

		if ( 0 === strpos( $path, $base . '/' ) ) {
			return self::normalize( substr( $path, strlen( $base ) ) );
		}

		return $path === $base ? '/' : $path;
	}

	/**
	 * The normalized path of the current request.
	 *
	 * Returns '' when there is no request path at all — WP-CLI and cron. That
	 * fails a non-empty list, which is the safe direction: rendering where the
	 * path cannot be verified is worse than rendering nothing. It does not
	 * affect `wp trending-now render`, which calls the renderer directly and
	 * never passes through the gate.
	 *
	 * @return string
	 */
	public static function current_path(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared against a configured list, never output.
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';

		if ( '' === $uri ) {
			return '';
		}

		return self::path_from( $uri, (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) );
	}

	/**
	 * Whether a path satisfies a comma-separated list.
	 *
	 * An empty list gates nothing, so an absent attribute — or a typo that
	 * empties one — falls back to today's behaviour rather than blanking the
	 * placement everywhere.
	 *
	 * Entries are dropped after normalizing, not before, so a stray ",," cannot
	 * become an empty needle that an empty $current would match.
	 *
	 * @param string $current Normalized current path.
	 * @param string $list    Raw comma-separated list.
	 * @return bool
	 */
	public static function matches_path( string $current, string $list ): bool {
		$needles = array();

		foreach ( explode( ',', $list ) as $entry ) {
			$entry = self::normalize( $entry );

			if ( '' !== $entry ) {
				$needles[] = $entry;
			}
		}

		if ( empty( $needles ) ) {
			return true;
		}

		return in_array( $current, $needles, true );
	}

	/**
	 * Whether the current request satisfies a comma-separated list.
	 *
	 * @param string $list Raw comma-separated list.
	 * @return bool
	 */
	public static function matches( string $list ): bool {
		return self::matches_path( self::current_path(), $list );
	}
}
```

- [ ] **Step 4: Require the class in the test bootstrap**

Add to the `require_once` block at the bottom of `tests/bootstrap.php`:

```php
require_once dirname( __DIR__ ) . '/includes/class-advtn-path-match.php';
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php tests/run.php`
Expected: PASS, `0 failed`. 40 new assertions take the count from 96 to 136.

- [ ] **Step 6: Lint**

Run: `find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l`
Expected: `No syntax errors detected` for every file.

- [ ] **Step 7: Commit**

```bash
git add includes/class-advtn-path-match.php tests/bootstrap.php tests/run.php
git commit -m "feat: add ADVTN_Path_Match"
```

---

### Task 2: Gate the shortcode

**Files:**
- Modify: `includes/class-advtn-shortcode.php` — the `shortcode_atts()` defaults and the top of `render()`

**Interfaces:**
- Consumes: `ADVTN_Path_Match::matches( string $list ): bool` from Task 1.
- Produces: shortcode attributes `match_path` and `matchpath`.

- [ ] **Step 1: Add both attribute spellings**

In `includes/class-advtn-shortcode.php`, add two entries to the `shortcode_atts()` defaults array, after `'show_see_all' => '1',`:

```php
				'match_path'   => '',
				'matchpath'    => '',
```

Both are needed, and this is the one thing about this task that is not obvious. `shortcode_parse_atts()` lowercases attribute *names*, so `matchPath="/,/archive"` in markup arrives as `matchpath` — and `shortcode_atts()` discards keys it does not know about, so a `match_path`-only attribute would make that markup a silent no-op. `match_path` is the canonical spelling and matches the snake_case of every other attribute here; `matchpath` is what makes the camelCase spelling work.

- [ ] **Step 2: Gate before anything else**

Insert at the very top of `render()`'s body, immediately after the `shortcode_atts()` assignment and before `$args` is built:

```php
			// Both spellings, canonical first. A widget area is site-wide, so
			// this is how a placement inside one says "render here, not there".
			//
			// The gate sits here rather than in the renderer on purpose:
			// ADVTN_Renderer::render() keys its cache on the args hash, so a
			// path list in $args would mint a separate cached copy of identical
			// HTML for every distinct list.
			$advtn_match = '' !== (string) $atts['match_path'] ? (string) $atts['match_path'] : (string) $atts['matchpath'];

			if ( ! ADVTN_Path_Match::matches( $advtn_match ) ) {
				return '';
			}
```

Do **not** add `match_path` or `matchpath` to `$args`. The loop below that copies attributes into `$args` iterates an explicit list and will not pick them up; leave that list alone.

- [ ] **Step 3: Lint and run the tests**

Run: `find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l`
Expected: `No syntax errors detected` for every file.

Run: `php tests/run.php`
Expected: PASS, `0 failed`, still 136. This task adds no test cases — the shortcode callback needs WordPress, and its gate is one line calling a function Task 1 covers exhaustively. Do not shim `add_shortcode` to reach it.

- [ ] **Step 4: Commit**

```bash
git add includes/class-advtn-shortcode.php
git commit -m "feat: gate the shortcode on matchPath"
```

---

### Task 3: Gate the block

**Files:**
- Modify: `blocks/trending-now/block.json` — the `attributes` object
- Modify: `blocks/trending-now/index.js` — one control in the existing panel
- Modify: `includes/class-advtn-block.php` — the top of `render()`

**Interfaces:**
- Consumes: `ADVTN_Path_Match::matches( string $list ): bool` from Task 1.
- Produces: block attribute `matchPath` (string).

- [ ] **Step 1: Add the block attribute**

In `blocks/trending-now/block.json`, add to the `attributes` object, after `showSeeAll`:

```json
		"matchPath": {
			"type": "string"
		}
```

camelCase is correct here — it matches `showImages`, `showSeeAll` and every other attribute in this file. It is also the spelling the shortcode accepts via its alias, so the same string works in both places.

- [ ] **Step 2: Add the editor control**

In `blocks/trending-now/index.js`, add a `TextControl` inside the existing `PanelBody`, after the last control in that panel:

```js
						el( TextControl, {
							label: __( 'Only show on these paths', 'trending-now' ),
							value: attributes.matchPath || '',
							onChange: set( 'matchPath' ),
							help: __( 'Comma-separated, e.g. /,/archive. Leave empty to show everywhere. Matching is exact, so /archive does not cover /archive/page/2/.', 'trending-now' ),
						} ),
```

`set` and `TextControl` are already defined in that file's scope; do not redeclare them.

- [ ] **Step 3: Gate render(), but not in the editor**

In `includes/class-advtn-block.php`, insert at the very top of `render()`'s body, before `$args` is built:

```php
		// The block previews through wp-server-side-render, which reaches this
		// callback over REST carrying the editor's own URL — so gating there
		// would render the block blank while it is being edited and read as
		// broken. Front end only.
		$advtn_editing = ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_admin();

		if ( ! $advtn_editing && ! ADVTN_Path_Match::matches( (string) ( $attributes['matchPath'] ?? '' ) ) ) {
			return '';
		}
```

Do **not** add `matchPath` to `$args`. The `foreach` below maps a fixed list of camelCase attributes to snake_case arg keys; leave that list alone.

- [ ] **Step 4: Lint and check the JavaScript**

Run: `find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l`
Expected: `No syntax errors detected` for every file.

Run: `node --check blocks/trending-now/index.js`
Expected: no output.

Run: `python3 -c "import json; json.load(open('blocks/trending-now/block.json')); print('block.json parses')"`
Expected: `block.json parses`.

Run: `php tests/run.php`
Expected: PASS, `0 failed`, still 136. This task adds no test cases — it is block registration and an editor control, which the dependency-free harness cannot reach.

- [ ] **Step 5: Commit**

```bash
git add blocks/trending-now/block.json blocks/trending-now/index.js includes/class-advtn-block.php
git commit -m "feat: gate the block on matchPath"
```

---

### Task 4: Documentation

**Files:**
- Modify: `README.md` — the shortcode and block attribute documentation, plus Troubleshooting
- Modify: `docs/trending-now-plugin-spec.md` — the shortcode/block section
- Modify: `CLAUDE.md` — Conventions
- Modify: `CHANGELOG.md` — a new `[Unreleased]` section

**Interfaces:**
- Consumes: everything from Tasks 1-3.
- Produces: nothing.

- [ ] **Step 1: Verify before writing**

Read `includes/class-advtn-path-match.php` and the changed parts of the two callers first, then confirm the names you are about to document:

```bash
grep -n "match_path\|matchpath" includes/class-advtn-shortcode.php
grep -n "matchPath" includes/class-advtn-block.php blocks/trending-now/block.json blocks/trending-now/index.js
grep -n "public static function" includes/class-advtn-path-match.php
```

A documented attribute that does not exist under that exact spelling is worse than none.

- [ ] **Step 2: Document the attribute in the README**

The README has **no shortcode attribute table** — attributes are shown as a code sample
under "Displaying the widget", followed by prose. Extend the sample rather than inventing a
table:

```
[trending_now limit="30" layout="news" heading="Trending Now"
              show_images="1" show_source="1" show_date="1"
              show_icons="0" show_excerpt="0" show_see_all="1"
              match_path="/,/archive"]
```

Then add a prose paragraph after the "Every display attribute is optional" paragraph,
covering:

- Comma-separated list of paths; empty or absent renders everywhere.
- Matching is **exact** and trailing-slash-insensitive: `/archive` covers `/archive` and `/archive/`, but not `/archive/page/2/` and not `/archive-2024`.
- Query strings are ignored, so `/?utm_source=x` still matches `/`.
- On a subdirectory install, `/` means the site's homepage, not the server root.
- Both `match_path` and `matchPath` work on the shortcode, because WordPress lowercases shortcode attribute names.

Add a Troubleshooting entry for the case this exists for:

```markdown
**The widget appears on every page.** A widget area is site-wide, so a widget placed there
renders everywhere. Add `match_path` to limit it: `[trending_now match_path="/"]` renders on
the homepage only. This does not change the theme restriction that pushed you into a widget
in the first place — it stops the workaround leaking onto every other page.
```

- [ ] **Step 3: Update the spec**

Document the attribute on both surfaces in the shortcode/block section, and record the two decisions the spec's rationale rests on:

- Matching is exact rather than prefix, so `/archive` cannot silently swallow its children. A trailing `*` is the additive way in if a section-wide rule is ever wanted.
- The gate lives in the two callers, not in `ADVTN_Renderer`, because `render()` keys its cache on `md5( wp_json_encode( $args ) )` — a path list in `$args` would cache identical HTML once per list, and would invite a later change that varies output by a path the key cannot express.
- The block bypasses the gate in the editor, because `wp-server-side-render` reaches the render callback over REST with the editor's URL.

- [ ] **Step 4: Add a CLAUDE.md convention**

```markdown
- Path gating lives in `ADVTN_Shortcode` and `ADVTN_Block`, never in `ADVTN_Renderer`. The
  renderer keys its cache on the args hash, so a path list in `$args` caches byte-identical
  HTML once per distinct list — and invites a later change that varies output by a path the
  key cannot express. Both callers return `''` before `render()` is reached.
- `ADVTN_Path_Match` is pure static except `current_path()`, which is the only method that
  touches `$_SERVER` or `home_url()`. Keep it that way: that split is what makes the
  subdirectory-install and absent-REQUEST_URI cases testable in the harness.
- The shortcode accepts `match_path` and `matchpath`. `shortcode_parse_atts()` lowercases
  attribute names, so a `match_path`-only attribute makes the documented `matchPath`
  spelling a silent no-op. Any future multi-word shortcode attribute has the same trap.
```

- [ ] **Step 5: Add the changelog entry**

Add a new `## [Unreleased]` section above `## [1.1.6]`. Follow the register of the `[1.1.6]` entry — say why, and what it cost. Cover: the widget-area problem that prompted it, the exact-match choice, both spellings on the shortcode, the editor bypass, and that a non-matching placement adds no cache variant.

Do **not** bump the version in `trending-now.php`.

- [ ] **Step 6: Verify and commit**

Run: `find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l`
Expected: `No syntax errors detected` for every file.

Run: `php tests/run.php`
Expected: PASS, `0 failed`.

Run the PHP 7.4 lint, since 7.4 is the floor and the dev machine is on 8.x:

```bash
docker run --rm -v "$PWD":/app -w /app php:7.4-cli sh -c 'find . -name "*.php" -not -path "./vendor/*" -exec php -l {} \;'
```

Expected: `No syntax errors detected` for every file.

```bash
git add README.md docs/trending-now-plugin-spec.md CLAUDE.md CHANGELOG.md
git commit -m "docs: document matchPath on the shortcode and block"
```

---

## Acceptance

Work through spec §"Acceptance criteria" (items 1-11) against the running stack at `http://localhost:8080` before calling this done. The ones no single task verifies in isolation:

- **Criteria 2 and 3** — put `[trending_now matchPath="/,/archive"]` on a page and confirm it renders on `/` and the archive slug and nowhere else, then repeat with `match_path` and confirm identical behaviour. The camelCase spelling is the one a user will type, so test it first.
- **Criterion 7** — the subdirectory case. Unit-tested through `path_from()`, but worth one live check if a subdirectory install is available; note it as untested live if not.
- **Criterion 8** — open a post containing the block in the editor with `matchPath` set to a path that is not the editor's URL, and confirm the preview still renders.
- **Criterion 9** — with a non-matching placement on the page, confirm `advtn_render_cache_keys` gains no entry:

```bash
bin/dev wp option get advtn_render_cache_keys --format=json
```

Compare before and after loading a page whose placement does not match.
