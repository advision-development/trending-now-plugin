# Trending Now

A WordPress plugin that renders a server-side "Trending Now" block of links aggregated
from owned WordPress sites and third-party news, plus a paginated archive at
`/trending/`.

The goal is **crawl discovery**: every newly published URL in the network gets a
guaranteed window of exposure in an internally-linked, server-rendered block on
higher-authority properties.

- **Requires:** WordPress 6.4+, PHP 8.1+, MySQL 5.7+ / MariaDB 10.3+
- **License:** GPL-2.0-or-later

## Install

```bash
git clone git@github.com:advision-development/trending-now-plugin.git trending-now
cd trending-now
composer install
```

Copy or symlink the directory into `wp-content/plugins/trending-now` and activate.
Activation creates the `{prefix}advtn_items` table, seeds defaults, generates the ingest
secret and flushes rewrite rules.

`composer install` pulls in Action Scheduler. Without it the plugin still runs, falling
back to plain WP-Cron; the Diagnostics tab says which runner is active.

## Configure

**Trending Now → Settings** covers mode, display, rotation, retention, links, archive,
scheduling and security. **Sources** is a repeatable list with a per-row *Test fetch*
button that runs a live fetch and shows the first three normalized items without writing
to the database. **Diagnostics** is where you look when ingestion silently stops.

Three source types:

| Type | Use for | Notes |
|---|---|---|
| `wp_rest` | Owned WordPress sites | `/wp-json/wp/v2/posts`; preferred, since `/feed/` is capped by the remote `posts_per_rss` |
| `rss` | Sites with REST disabled | SimplePie via `fetch_feed()`, with its 12h cache disabled for the call |
| `gdelt` | Third-party news | GDELT DOC 2.0, no API key, filtered to a domain allowlist |

## Display

All three entry points call the same renderer.

```
[trending_now limit="30" layout="list" heading="Trending Now" show_images="0" show_source="1" show_see_all="1"]
```

```php
advtn_render( array( 'limit' => 20, 'layout' => 'cards' ) );  // echoes
$html = advtn_get_html();                                     // returns
```

Plus the **Trending Now** Gutenberg block. The shortcode works inside Elementor's Text
Editor widget and Bricks' Shortcode element, which is why no page-builder-native widget
APIs are used.

Templates live in `templates/` and are overridable from a theme at
`{theme}/trending-now/{template}.php`. The runtime stylesheet is generated from the
configured class prefix and inlined once per page inside the cached HTML;
`assets/css/trending-now.css` is an unenqueued reference copy.

Because the widget renders after `wp_head`, that `<style>` tag sits in the body — which
the W3C Nu validator flags. If you need a strictly clean validation run, filter
`advtn_inline_css` to return an empty string and enqueue
`assets/css/trending-now.css` yourself (renaming its selectors to match your prefix).

## Operating modes

- **`direct`** — fetches its own sources. Phase 1 default.
- **`hub`** — same, plus serves the assembled list to spokes over REST.
- **`spoke`** — no sources of its own; pulls a ready list from `hub_url` once per cycle.
  A spoke never contacts source sites, which avoids 15 sites × 15 sources of redundant
  daily fetches.

## Scheduling

`advtn_ingest_cycle` runs hourly and returns immediately unless
`ingest_interval_hours` has elapsed since the last completed cycle. When due it takes a
lock and queues one staggered action per source, then a finalize action that prunes,
rebuilds the selection, purges the render cache and releases the lock.

The deterministic path is an external trigger — one n8n workflow, one HTTP node per site:

```
POST https://site.com/wp-json/advtn/v1/ingest
X-ADVTN-Timestamp: 1780000000
X-ADVTN-Signature: <hex hmac-sha256 of "{timestamp}\n{raw body}">
Content-Type: application/json

{"force": false}
```

Responses: `202` scheduled, `200` not due, `409` lock held, `401` auth failure.
Idempotency comes from the lock plus the due-check, so a retried webhook is harmless.

`GET /wp-json/advtn/v1/status` (same signing scheme) returns everything on the
Diagnostics panel as JSON. Alert on `last_ingest` older than 30 hours.

## How selection works

Slots are split between news (`news_share_pct`) and network links, with a per-source cap
of `max_source_share_pct`; either side reallocates when it underfills. Within each pool
three tiers fill in order:

1. **Pinned** — already shown, still inside `exposure_floor_days`. Held unconditionally,
   even past the per-source cap.
2. **Never shown** — newest first.
3. **Least shown** — fewest impressions first.

Sorting purely by date would let a burst of new posts push out yesterday's posts before
they were ever crawled; the exposure floor is what prevents that. After selection, items
are reordered so no more than two consecutive links share a host.

## Retention

Items not seen for 7 days become `stale`: excluded from the widget, retained in the
archive. Items older than `retention_days` (default 90) are deleted in batches of 500
under a time budget. The retention cap is what keeps the archive a real page rather than
an unbounded link dump.

## Development

```bash
php tests/run.php    # URL normalization, hash identity, HMAC signing
```

### Local environment

`docker compose` brings up two WordPress instances: the site under test and a stand-in
"owned network site" to ingest from. They are deliberately reachable on different host
strings — `localhost:8080` and `127.0.0.1:8081` — because items whose host matches the
local site are discarded as self-links, so a shared hostname would silently ingest
nothing.

```bash
bin/dev up          # start containers, install both sites, activate the plugin
bin/dev seed        # 5 posts on the source site, 4 with a featured image
bin/dev configure   # register a wp_rest + a gdelt source, place the shortcode
bin/dev ingest      # synchronous ingest cycle
bin/dev verify      # assert the rendered widget contains real links
bin/dev status      # diagnostics JSON
bin/dev wp <cmd>    # wp-cli against the site under test
bin/dev src <cmd>   # wp-cli against the source site
bin/dev logs        # tail both sites
bin/dev reset       # destroy containers and volumes
```

| | |
|---|---|
| Site under test | <http://localhost:8080> — `admin` / `admin` |
| Source site | <http://127.0.0.1:8081> — `admin` / `admin` |

Notes on the stack:

- Apache also listens on 8080 inside the container. The site's `home_url` is
  `http://localhost:8080`, so WordPress loopback requests — which Action Scheduler's
  queue runner depends on — target that port from *inside* the container.
- `WORDPRESS_CONFIG_EXTRA` is read through `getenv()` on every request, so the wp-cli
  services share the same environment block as the web container. Without that,
  constants defined there are silently missing from CLI runs.
- `ADVTN_ALLOW_LOCAL_URLS` is defined in the container only. It relaxes
  `wp_http_validate_url()`, which otherwise rejects loopback and private-range hosts
  outright. Never define it in production.
- GDELT routinely takes 10s+ to answer and rate-limits with `429`, so the local stack
  raises `http_timeout` well above the 5s production default.

Architecture notes, invariants and the list of explicitly rejected approaches are in
[CLAUDE.md](CLAUDE.md). The authoritative specification is
[docs/trending-now-plugin-spec.md](docs/trending-now-plugin-spec.md).
