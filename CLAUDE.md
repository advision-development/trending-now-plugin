# CLAUDE.md

Guidance for Claude Code when working in this repository.

## What this is

A WordPress plugin (`trending-now`) that renders a server-side "Trending Now" block of
links aggregated from owned WordPress sites and Google News, plus a paginated `/trending/`
archive. Its purpose is **crawl discovery** — giving every new URL in the network a
guaranteed window of exposure in an internally-linked, server-rendered block.

The repository root *is* the plugin. `docs/trending-now-plugin-spec.md` is the
authoritative specification; read it before changing behaviour.

- Targets WordPress 6.4+, PHP 8.1+.
- Prefix for everything (classes, functions, options, hooks, DB table, CSS, REST):
  `advtn` / `ADVTN` / `Advision\TrendingNow`.
- Text domain: `trending-now`.

## Commands

```bash
composer install       # pulls woocommerce/action-scheduler into vendor/
php tests/run.php      # dependency-free unit tests (URL normalization, HMAC)
composer test          # same thing
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l   # lint
```

Local WordPress (two instances: site under test + a source site to ingest from):

```bash
bin/dev up && bin/dev seed && bin/dev configure && bin/dev ingest && bin/dev verify
bin/dev wp <wp-cli command>    # site under test  (http://localhost:8080)
bin/dev src <wp-cli command>   # source site      (http://127.0.0.1:8081)
bin/dev reset                  # destroy volumes
```

The two sites must stay on **different host strings**: items whose host matches the
local site are dropped as self-links, so putting both on `localhost` makes ingestion
silently return nothing. See README for the rest of the stack's constraints.

WP-CLI (optional, only registered when `WP_CLI` is defined):

```bash
wp trending-now ingest [--source=<id>] [--force] [--sync]
wp trending-now flush [--all] [--source=<id>] [--host=<host>] [--status=<s>] [--yes]
wp trending-now select | render [--uncached] | status | prune | unlock | purge
```

## Architecture

Single-table, three-option design. No custom post type, no client-side rendering.

```
trending-now.php          bootstrap, constants, autoloader, activation hooks
includes/
  class-advtn-plugin.php      singleton + tiny DI container (advtn()->repository(), etc.)
  class-advtn-schema.php      dbDelta schema, version-gated migrations
  class-advtn-settings.php    typed accessors + sanitization for the three options
  class-advtn-url.php         normalize / hash / host / is_valid
  class-advtn-repository.php  ALL SQL
  class-advtn-ingest.php      run() -> run_source() -> finalize()
  class-advtn-scheduler.php   Action Scheduler (WP-Cron fallback)
  class-advtn-lock.php        atomic add_option() lock
  class-advtn-selector.php    three-tier slot allocation + rotation
  class-advtn-renderer.php    HTML build + option-backed render cache
  class-advtn-archive.php     rewrite rule, template, pagination, robots
  class-advtn-rest.php        /ingest, /items, /status
  class-advtn-hmac.php        sign + verify
  sources/                    one class per source type behind ADVTN_Source_Interface
  class-advtn-manual.php      curated links: option CRUD, expiry, table sync
admin/                        menu, four tab views, AJAX test-fetch
templates/                    widget-list, widget-cards, archive (theme-overridable)
blocks/trending-now/          block.json + build-free editor script
```

Autoloading is a naming convention, not Composer: `ADVTN_Source_WP_REST` resolves to
`includes/sources/class-advtn-source-wp-rest.php`. Search order is `includes/`,
`includes/sources/`, `admin/`.

### Data

One table, `{prefix}advtn_items`, keyed by `UNIQUE KEY url_hash`. Three options:
`advtn_settings` (autoload yes), `advtn_sources` and `advtn_source_state` (autoload no).
Everything else — `advtn_current_selection`, `advtn_render_cache_*`,
`advtn_render_cache_keys`, `advtn_log`, `advtn_ingest_lock`, `advtn_last_ingest`,
`advtn_hub_items_cache` — is a standalone non-autoloaded option.

### Flow

`advtn_ingest_cycle` (hourly) → due-check → lock → staggered `advtn_ingest_source` per
source → `advtn_finalize_cycle` → mark stale, prune, rebuild selection, purge render
cache, release lock.

The REST trigger (`POST /wp-json/advtn/v1/ingest`, HMAC-signed) calls the same
`ADVTN_Ingest::run()`. It is the primary path; WP-Cron is the safety net.

## Hard rules

These come from the spec and from failures already paid for. Do not relax them.

1. **All SQL lives in `ADVTN_Repository`.** Nothing else touches `$wpdb` — including
   admin views. Every query uses `$wpdb->prepare()`, `IN (…)` lists included (build
   placeholders dynamically).
2. **Never fetch a feed during a pageview.** Not on cache miss, not ever.
3. **Server-side rendering only.** No AJAX, no `fetch()`, no hydration, no redirect
   wrappers or click tracking on the links. Anchor text is the real title.
4. **Empty means empty.** With no items, `render()` returns `''` — no container, no
   "no items" message.
5. **Render cache is an option, not a transient**, with no expiry. Object caches evict
   transients under memory pressure; the HTML must survive. Only
   `advtn_finalize_cycle` busts it.
6. **`link_rel_external` applies only to news types** (`ADVTN_Source_Base::news_types()`). Internal network
   links stay plain followed links — that is the entire point of the plugin.
7. **Datetimes are UTC everywhere.** Cutoffs are computed in PHP with `gmdate()` and
   passed as prepared parameters; never `NOW()`, which follows the DB server timezone.
8. **`$wpdb->prepare()` coerces `null` to `''`.** For nullable columns emit a literal
   `NULL` in the statement instead (see `ADVTN_Repository::upsert_item()`).
9. **Never overwrite `first_seen`, `first_shown_at`, `last_shown_at` or `times_shown`
   on upsert.** The exposure floor depends on `first_shown_at` being write-once.
10. **The lock is `add_option()`**, which is atomic via the unique index on
    `option_name`. Not `update_option()`, not `wp_cache_add()`. Always release in a
    `finally`.
11. **One bad source must never abort a cycle.** Every fetch is wrapped in
    `try/catch(\Throwable)`; failures set `backoff_until`.
12. **No WP-CLI dependency.** Register commands only when `WP_CLI` is defined.
13. **Do not set `DISABLE_WP_CRON`.** Nothing else drives the Action Scheduler queue on
    these hosts.
14. Escape at the point of output — `esc_url`, `esc_html`, `esc_attr` — with no
    exceptions. Admin forms need nonce + `current_user_can( 'manage_options' )`.
15. `flush_rewrite_rules()` runs on activation/deactivation and on a deferred flag after
    a slug change — never on a plain `init`.

## Explicitly rejected

Do not propose or implement these; each was considered and rejected in the spec:
Google Indexing API submission, client-side rendering, a custom post type for links,
Elementor/Bricks native widget APIs, Google News RSS as a source, fetching during a
pageview, requiring WP-CLI, and GDELT (removed after 1.0.0 — see the README). Note that
`gdelt` deliberately remains in `ADVTN_Source_Base::news_types()` so rows ingested before
the removal keep their news classification.

## Conventions

- WordPress coding standards: tabs, Yoda-free but `esc_*` everywhere, docblocks on every
  class and method, `declare( strict_types=1 )` at the top of every PHP file, and an
  `ABSPATH` guard.
- Prefer adding a source type over branching inside an existing one — implement
  `ADVTN_Source_Interface` (extend `ADVTN_Source_Base`) and register it via the
  `advtn_source_map` filter. If it is a news provider, add it to
  `ADVTN_Source_Base::news_types()`; that one list drives the news/network slot split,
  the `rel` attribute and the `--news` template modifier. Admin fields declare
  `data-types="..."` and are shown server-side and by `applyType()` in admin.js — no new
  branches needed.
- Credentials live in `advtn_settings`, not in source rows, and must be redacted before
  going anywhere near an error message or the log. `ADVTN_Logger` scrubs keys containing
  secret/signature/token/password/api_key, but a key embedded in a URL inside a message
  body will not be caught — redact explicitly, as the SerpAPI provider does.
- Selection is deterministic. There is no score column and no relevance model; if you
  find yourself adding one, re-read spec §7.
- The per-source cap is a *preference*, not a hard limit. `ADVTN_Selector::build()` makes
  a final pass with the cap lifted, because spec §7.1 also says never render fewer items
  than are available — with only a few sources configured, or when pinned items have
  eaten a source's quota, enforcing the cap strictly leaves slots empty.
- After deleting rows, drop the dead ids from `advtn_current_selection` with
  `ADVTN_Selector::forget()` and purge the render cache. Do **not** call
  `build_and_commit()` to tidy up — it stamps `times_shown`, so housekeeping would
  inflate every counter.
- Updates go through `ADVTN_Updater`, hooked on `update_plugins_github.com`, which
  WordPress derives from the `Update URI` header. Removing that header hands the slug
  back to wordpress.org. The GitHub token is only ever sent to `api.github.com` — the
  release CDN redirect rejects it, and sending it further would leak it.
- Curated links live in the `advtn_manual_links` option but are also written into the
  items table through `ADVTN_Source_Manual`, so they dedupe, archive and count like
  anything else. Expiry sets the row to `stale` rather than deleting it — leaving it
  `active` would merely stop it being *placed* while it carried on competing as an
  ordinary candidate, which looks exactly like the timer not working.
- Nothing an ingest writes is visible until `finalize()` runs: it is what commits the
  selection and busts the render cache. When debugging "my items are not showing", check
  `advtn_last_ingest` and the lock before suspecting the fetch.
- Per-site variation is a feature, not drift: `class_prefix`, `widget_limit`,
  `news_share_pct`, `heading_text` and `archive_slug` are meant to differ across the
  network (spec §11).

## Testing

`tests/run.php` runs without WordPress by shimming the handful of core functions the
pure-logic classes touch (`tests/bootstrap.php`). It covers URL normalization, hash
identity, host extraction, URL validation and HMAC signing. Anything needing `$wpdb`
belongs in a WP integration suite, not here.

Acceptance criteria for the whole plugin are in spec §14 — treat that as the definition
of done for behavioural work.
