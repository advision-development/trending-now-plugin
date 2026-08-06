# Trending Now — WordPress Plugin Specification

**Version:** 1.0 (implementation spec)
**Target:** WordPress 6.4+, PHP 8.1+, MySQL 5.7+/MariaDB 10.3+
**Prefix:** `advtn` / `ADVTN` / `Advision\TrendingNow` — used for all functions, classes, DB tables, options, hooks, CSS classes, and REST namespaces.

---

## 1. Purpose and scope

### What this plugin does

Renders a server-side "Trending Now" section on a WordPress site containing a configurable number of links (default 30) aggregated from:

1. **Owned sites** — other WordPress installs in the network, pulled via their public REST API (RSS fallback).
2. **News sources** — authoritative third-party articles pulled from the GDELT DOC 2.0 API, filtered to a domain allowlist.

It also exposes a paginated "see all" archive at `/trending/` containing the full retained set.

### Primary goal

Improve crawl discovery of newly published URLs across the network by giving every new article a guaranteed window of exposure in an internally-linked, server-rendered block on higher-authority properties.

### Deployment path

- **Phase 1:** one site, `direct` mode (site fetches its own sources).
- **Phase 2:** 10–15 sites. One install designated `hub`; the rest switch to `hub` mode and pull a pre-assembled list from the hub's REST endpoint. Same codebase, one setting changed.

### Explicit non-goals

Do not implement any of the following. Each has been considered and rejected.

| Rejected | Reason |
|---|---|
| Google Indexing API submission | Only supports `JobPosting` and `BroadcastEvent`. Using it for articles violates ToS and is a known manual-action trigger. |
| Client-side / AJAX rendering of links | Defeats the entire purpose. JS-injected links are crawled far less reliably. |
| Custom post type for link storage | Bloats `wp_posts`, leaks into core sitemaps and search, no editorial UI is needed. |
| Elementor / Bricks native widget APIs | Both accept shortcodes. Not worth the maintenance surface. |
| Google News RSS as a source | Returns `news.google.com/rss/articles/CBMi…` redirect URLs, not publisher URLs, and resists server-side resolution. |
| Fetching any feed during a pageview | Ever. Under any condition, including cache miss. |
| WP-CLI as a required dependency | Not available on all target hosts. Register the command only if `WP_CLI` exists. |
| `DISABLE_WP_CRON` | Nothing else drives the Action Scheduler queue on these hosts. Leave WP-Cron enabled. |

---

## 2. Architecture

### 2.1 Operating modes

Setting: `mode` ∈ `direct` | `hub` | `spoke`

- **`direct`** — fetches configured sources itself, builds its own selection. Phase 1 default.
- **`hub`** — same as `direct`, plus serves the assembled item list over REST to spokes.
- **`spoke`** — has no sources of its own. Fetches a ready list from `hub_url` once per ingest cycle, stores it in the local `advtn_items` table, and runs local selection/rendering as normal.

A spoke never talks to source sites. This avoids 15 sites × 15 sources = 225 redundant daily fetches with divergent results.

### 2.2 Source provider interface

All ingestion goes through one interface so adding a source type is additive.

```php
interface ADVTN_Source_Interface {
    /** Machine key: 'wp_rest', 'rss', 'gdelt', 'hub' */
    public function get_type(): string;

    /**
     * Fetch and normalize items for one configured source.
     * MUST NOT write to the database. MUST NOT throw.
     *
     * @param array $config Source config row (see §4.2).
     * @return ADVTN_Fetch_Result
     */
    public function fetch( array $config ): ADVTN_Fetch_Result;

    /**
     * Validate/sanitize a source config row from the settings screen.
     * @return array|WP_Error
     */
    public function validate_config( array $config );
}
```

```php
final class ADVTN_Fetch_Result {
    public array   $items    = [];   // array of normalized item arrays, see §5.1
    public bool    $ok       = false;
    public ?string $error    = null; // human-readable, shown in admin
    public ?int    $http_code = null;
    public int     $duration_ms = 0;
}
```

### 2.3 Class map

```
trending-now/
├── trending-now.php                  Bootstrap, constants, autoload, activation hooks
├── uninstall.php                     Drops table + options if 'delete_data_on_uninstall'
├── composer.json                     woocommerce/action-scheduler
├── includes/
│   ├── class-advtn-plugin.php        Singleton, wires hooks, DI container
│   ├── class-advtn-activator.php     Table creation, rewrite flush, default settings, secret gen
│   ├── class-advtn-schema.php        dbDelta schema + version-gated migrations
│   ├── class-advtn-settings.php      Typed get/set over the options, defaults, sanitization
│   ├── class-advtn-url.php           Normalization + hashing + allowlist validation
│   ├── class-advtn-repository.php    All SQL. Nothing else touches $wpdb.
│   ├── class-advtn-ingest.php        Orchestration: run(), run_source(), finalize()
│   ├── class-advtn-scheduler.php     Action Scheduler registration, staggering, due-checks
│   ├── class-advtn-lock.php          Atomic add_option() lock
│   ├── class-advtn-selector.php      Slot allocation + rotation algorithm
│   ├── class-advtn-renderer.php      HTML generation, cache read/write
│   ├── class-advtn-shortcode.php     [trending_now]
│   ├── class-advtn-block.php         Gutenberg block, PHP render_callback
│   ├── class-advtn-archive.php       Rewrite rule, query var, template loader
│   ├── class-advtn-rest.php          /ingest, /items, /status
│   ├── class-advtn-hmac.php          Sign + verify
│   ├── class-advtn-cli.php           Conditional on class_exists('WP_CLI')
│   ├── class-advtn-logger.php        Ring-buffer log in an option
│   └── sources/
│       ├── interface-advtn-source.php
│       ├── class-advtn-fetch-result.php
│       ├── class-advtn-source-wp-rest.php
│       ├── class-advtn-source-rss.php
│       ├── class-advtn-source-gdelt.php
│       └── class-advtn-source-hub.php
├── admin/
│   ├── class-advtn-admin.php         Menu, asset enqueue, form handling
│   └── views/
│       ├── settings.php
│       ├── sources.php
│       └── diagnostics.php
├── blocks/trending-now/block.json
├── templates/
│   ├── widget-list.php
│   ├── widget-cards.php
│   └── archive.php
└── assets/css/trending-now.css       Fallback only; runtime CSS is inlined (§8.4)
```

**Hard rule:** all SQL lives in `ADVTN_Repository`. Every query uses `$wpdb->prepare()`. No exceptions, including in admin views.

---

## 3. Database schema

Single custom table. Created via `dbDelta()` in `ADVTN_Schema::install()`, guarded by `advtn_db_version`.

```sql
CREATE TABLE {$wpdb->prefix}advtn_items (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  url_hash char(40) NOT NULL,
  url varchar(2048) NOT NULL,
  source_id varchar(32) NOT NULL,
  source_type varchar(16) NOT NULL,
  site_name varchar(191) NOT NULL DEFAULT '',
  host varchar(191) NOT NULL DEFAULT '',
  title text NOT NULL,
  excerpt text NULL,
  image_url varchar(2048) NULL,
  published_at datetime NULL,
  first_seen datetime NOT NULL,
  last_seen datetime NOT NULL,
  first_shown_at datetime NULL,
  last_shown_at datetime NULL,
  times_shown int(10) unsigned NOT NULL DEFAULT 0,
  status varchar(12) NOT NULL DEFAULT 'active',
  PRIMARY KEY  (id),
  UNIQUE KEY url_hash (url_hash),
  KEY status_published (status, published_at),
  KEY selection (status, times_shown, published_at),
  KEY source_seen (source_id, last_seen),
  KEY host (host)
) {$charset_collate};
```

**dbDelta gotchas — get these right or the table silently fails to create:**
- Two spaces after `PRIMARY KEY`.
- Lowercase column types.
- One field per line.
- `KEY` not `INDEX`.
- Key names must match exactly on re-run or dbDelta will attempt duplicate index creation.

**Column notes:**
- `url_hash` = `sha1( ADVTN_URL::normalize( $url ) )`. The uniqueness guarantee. `url` itself is never indexed.
- `host` is the registrable host after `www.` stripping. Used for per-source diversity quotas and `exclude_host` in hub mode.
- `published_at` is UTC. For GDELT this is `seendate`, which is *discovery* time, not publish time — acceptable, but note it in a code comment.
- `status` ∈ `active` | `stale`. `stale` = not seen in the last N ingest cycles; retained for the archive but excluded from widget selection.
- `first_shown_at` drives the exposure floor (§7.2). Never overwrite once set.

**No `score` column.** Selection is deterministic (§7); there is no relevance model.

### 3.1 Retention and pruning

At the end of every successful ingest (`ADVTN_Ingest::finalize()`):

1. `status = 'stale'` where `last_seen < NOW() - INTERVAL 7 DAY`.
2. `DELETE` where `first_seen < NOW() - INTERVAL {retention_days} DAY` (default 90).
3. Delete in batches of 500 with a `LIMIT` clause; loop with a time budget. Never a single unbounded `DELETE`.

The retention window caps total archive size, which is what keeps `/trending/` defensible rather than an unbounded link dump.

---

## 4. Configuration

Three options. Do not sprawl beyond these.

### 4.1 `advtn_settings` — autoload `yes`

Small scalar config, read on most requests.

```php
[
  'mode'                    => 'direct',   // direct|hub|spoke
  'widget_limit'            => 30,
  'news_share_pct'          => 20,         // 0-50; % of slots reserved for source_type=gdelt
  'max_source_share_pct'    => 20,         // 5-100; cap on any single source's slots
  'exposure_floor_days'     => 3,          // min consecutive days an item stays once shown
  'retention_days'          => 90,
  'ingest_interval_hours'   => 20,         // due-check threshold, not a strict schedule
  'stagger_minutes'         => 7,          // gap between per-source jobs
  'batch_max_sources'       => 3,          // sources processed per Action Scheduler batch
  'batch_time_budget'       => 20,         // seconds; bail and requeue past this
  'http_timeout'            => 5,          // seconds per outbound request
  'source_fail_backoff'     => 3600,       // seconds to skip a source after failure
  'archive_slug'            => 'trending',
  'archive_per_page'        => 50,
  'archive_noindex'         => false,      // see §9.2 before changing
  'archive_enabled'         => true,
  'link_target_blank'       => true,
  'link_rel_external'       => '',         // '' | 'nofollow' | 'sponsored'  (news items only)
  'heading_text'            => 'Trending Now',
  'see_all_text'            => 'See all trending stories',
  'class_prefix'            => 'advtn',    // vary per site — see §11
  'hub_url'                 => '',         // spoke mode
  'hub_secret'              => '',         // spoke mode; shared with hub
  'ingest_secret'           => '',         // generated on activation; inbound trigger auth
  'delete_data_on_uninstall'=> false,
]
```

`link_rel_external` applies **only** to `source_type = 'gdelt'`. Internal network links must remain plain followed links — that is the point of the plugin.

### 4.2 `advtn_sources` — autoload `no`

Ordered array of source config rows.

```php
[
  [
    'id'            => 'src_7f3a91',       // 'src_' . substr(md5(uniqid()),0,6); immutable
    'label'         => 'Example Sports',
    'type'          => 'wp_rest',          // wp_rest|rss|gdelt
    'enabled'       => true,
    'url'           => 'https://example.com',   // site root for wp_rest; full feed URL for rss
    'limit'         => 10,                 // max items to ingest per cycle, 1-100
    'stagger_index' => 0,                  // assigned on save; position * stagger_minutes
  ],
  [
    'id'            => 'src_b2c8e4',
    'label'         => 'Sports news (GDELT)',
    'type'          => 'gdelt',
    'enabled'       => true,
    'limit'         => 40,
    'gdelt_query'   => 'sourcelang:english (sportsbook OR "betting odds")',
    'gdelt_domains' => [ 'espn.com', 'cbssports.com', 'si.com', 'theathletic.com' ],
    'gdelt_timespan'=> '2d',
  ],
]
```

### 4.3 `advtn_source_state` — autoload `no`

Runtime state, keyed by source id. Written after every fetch attempt. This is the diagnostics data.

```php
[
  'src_7f3a91' => [
    'last_run'      => '2026-08-06 04:07:11',
    'last_success'  => '2026-08-06 04:07:11',
    'last_error'    => null,
    'http_code'     => 200,
    'duration_ms'   => 812,
    'items_seen'    => 10,
    'items_new'     => 3,
    'consec_fails'  => 0,
    'backoff_until' => null,
  ],
]
```

Additional standalone options:
- `advtn_last_ingest` (datetime, autoload `no`) — completion time of last full cycle.
- `advtn_current_selection` (ordered array of item IDs, autoload `no`) — the live set (§7.3).
- `advtn_render_cache_{md5(args)}` (string, autoload `no`) — pre-rendered HTML, no expiry (§8.3).
- `advtn_render_cache_keys` (array, autoload `no`) — registry of the above for purging.
- `advtn_log` (array, autoload `no`) — capped ring buffer, 200 entries.
- `advtn_ingest_lock` (int timestamp, autoload `no`) — see §6.3.

---

## 5. Ingestion

### 5.1 Normalized item shape

Every source returns items in this exact shape. Missing values are `null`, not empty string.

```php
[
  'url'          => 'https://example.com/article-slug/',  // absolute, http(s) only
  'title'        => 'Plain text title, entities decoded',
  'excerpt'      => 'Plain text, tags stripped, ~200 chars' | null,
  'image_url'    => 'https://…/image.jpg' | null,
  'published_at' => '2026-08-05 14:22:00',                 // UTC, Y-m-d H:i:s
  'site_name'    => 'Example Sports',
  'source_type'  => 'wp_rest',
]
```

Reject and skip an item if: URL fails `wp_http_validate_url()`, scheme is not http/https, title is empty after sanitization, or host is on the local site's own host (never link to yourself through this widget).

### 5.2 URL normalization — `ADVTN_URL::normalize()`

Determines dedupe identity. Must be stable and order-independent.

1. Trim, `wp_parse_url()`. Bail on failure.
2. Lowercase scheme and host. Force `https` for hashing purposes only (store original URL as returned).
3. Strip leading `www.`.
4. Drop the fragment entirely.
5. Remove query params matching: `utm_*`, `fbclid`, `gclid`, `gbraid`, `wbraid`, `msclkid`, `mc_cid`, `mc_eid`, `ref`, `source`, `_ga`, `igshid`, `yclid`, `oly_*`.
6. Sort remaining params by key, rebuild.
7. Remove a trailing slash unless the path is `/`.
8. Return `scheme://host/path[?sorted-query]`.

`ADVTN_URL::hash( $url )` = `sha1( normalize( $url ) )`.

Also provide `ADVTN_URL::host( $url )` returning the lowercased, `www.`-stripped host.

### 5.3 Source: `wp_rest`

Preferred for all owned sites. `/feed/` is capped by the `posts_per_rss` option (default 10) with no per-request override, which would mean changing a setting on every source site.

```
GET {url}/wp-json/wp/v2/posts
  ?per_page={min(limit,100)}
  &orderby=date
  &order=desc
  &status=publish
  &_embed=wp:featuredmedia
  &_fields=id,link,title,excerpt,date_gmt,_embedded
```

- `_fields` must include `_embedded` or the embed payload is stripped.
- `per_page` hard max is 100.
- Request via `wp_remote_get()` with `timeout => http_timeout`, `redirection => 3`, `user-agent => 'AdvisionTrendingNow/1.0'`.
- Accept `200` only. Treat `401`/`403` as a configuration error (REST likely disabled) and surface a suggestion to switch that source to `rss`.

Field mapping:

| Item field | Source path |
|---|---|
| `url` | `link` |
| `title` | `html_entity_decode( wp_strip_all_tags( title.rendered ), ENT_QUOTES, 'UTF-8' )` |
| `excerpt` | `wp_trim_words( wp_strip_all_tags( excerpt.rendered ), 30 )` |
| `image_url` | `_embedded['wp:featuredmedia'][0]['media_details']['sizes']['medium_large']['source_url']` → fall back to `['medium']` → fall back to `source_url` → `null` |
| `published_at` | `date_gmt` (already UTC, reformat to `Y-m-d H:i:s`) |
| `site_name` | source config `label` |

The `_embedded` key is absent when a post has no featured image. Guard every level of that array access.

### 5.4 Source: `rss`

Fallback for sources with REST unavailable. Use `fetch_feed()` (SimplePie, ships with core).

- Filter `wp_feed_cache_transient_lifetime` to `1` for this call only (add the filter, call, remove it) — SimplePie's default 12h cache would silently serve stale data and make debugging miserable.
- Cap at `min( limit, $feed->get_item_quantity() )`.
- `published_at` from `$item->get_date('Y-m-d H:i:s')`, which SimplePie returns in UTC.
- `image_url` from enclosure, else `media:thumbnail` / `media:content` via `$item->get_item_tags()`, else `null`.
- `excerpt` from `get_description()`, stripped and trimmed.
- Handle `is_wp_error()` from `fetch_feed()`.

### 5.5 Source: `gdelt`

**Endpoint:** `https://api.gdeltproject.org/api/v2/doc/doc`

No API key. Free. Returns real publisher URLs, which is why it replaced the Google News RSS idea.

```
GET https://api.gdeltproject.org/api/v2/doc/doc
  ?query={urlencoded query}
  &mode=ArtList
  &format=json
  &maxrecords={min(limit,250)}
  &timespan={gdelt_timespan}
  &sort=DateDesc
```

Query construction: combine `gdelt_query` with the domain allowlist as an OR group.

```
(domain:espn.com OR domain:cbssports.com OR domain:si.com) sourcelang:english (sportsbook OR "betting odds")
```

Response (`mode=ArtList`, `format=json`):

```json
{ "articles": [
  { "url": "https://…", "url_mobile": "", "title": "…",
    "seendate": "20260806T041500Z", "socialimage": "https://…",
    "domain": "espn.com", "language": "English", "sourcecountry": "United States" }
] }
```

Mapping: `url` → `url`; `title` → `title`; `socialimage` → `image_url`; `domain` → `site_name`; `seendate` parsed from `Ymd\THis\Z` → `published_at`. **There is no excerpt field** — set `excerpt` to `null` and make sure templates handle that.

Hardening (all of these have been observed in practice):
- GDELT occasionally returns malformed JSON. Check `json_decode() === null` and fail the source cleanly rather than emitting a PHP warning.
- It sometimes returns an HTML error page with a `200` status. Verify `Content-Type` contains `json` and that `articles` is an array.
- **Post-filter against `gdelt_domains` server-side even though the query restricts by domain.** The query language is fuzzy and near-domain matches leak through.
- One request per source per cycle, max. GDELT publishes no hard rate limit but asks for restraint; a daily cycle is well within it.
- Verify field names on the first live run — if GDELT has changed the schema, log the raw first record once and adjust.

### 5.6 Source: `hub` (spoke mode)

```
GET {hub_url}/wp-json/advtn/v1/items?limit=200&exclude_host={local host}
```

HMAC-signed with `hub_secret` (§10.1). Response items are already in normalized shape (§5.1) plus `source_id` and `source_type`. Spoke writes them to its local table using its own `source_id = 'src_hub'` so local selection, exposure floors, and `times_shown` remain per-site.

### 5.7 Upsert logic — `ADVTN_Repository::upsert_item()`

Single statement, relying on the unique index. Preserves display history on re-ingest.

```sql
INSERT INTO {prefix}advtn_items
  (url_hash, url, source_id, source_type, site_name, host, title, excerpt,
   image_url, published_at, first_seen, last_seen, status)
VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, 'active')
ON DUPLICATE KEY UPDATE
  title        = VALUES(title),
  excerpt      = VALUES(excerpt),
  image_url    = VALUES(image_url),
  published_at = COALESCE(published_at, VALUES(published_at)),
  last_seen    = VALUES(last_seen),
  status       = 'active';
```

Never update `first_seen`, `first_shown_at`, `last_shown_at`, or `times_shown` on conflict. `published_at` uses `COALESCE` so an existing real publish date is not overwritten by a later, less accurate `seendate`.

Return whether the row was an insert (`$wpdb->insert_id` behavior with `ON DUPLICATE KEY`: affected rows is 1 for insert, 2 for update) to populate `items_new`.

---

## 6. Scheduling and execution

### 6.1 Action Scheduler

Require `woocommerce/action-scheduler` via Composer. It does **not** need WP-CLI: its default runner attaches to a WP-Cron hook and processes batches via loopback requests, with an admin UI at **Tools → Scheduled Actions**.

Prerequisites to check and surface in diagnostics:
- Loopback requests must succeed. Add a Site Health test, or link to **Site Health → Info → "Loopback request"**. Hosts with HTTP auth, aggressive WAFs, or firewalled self-requests break this.
- WP-Cron must remain enabled (`DISABLE_WP_CRON` not set).

Actions to register:

| Hook | Args | Purpose |
|---|---|---|
| `advtn_ingest_cycle` | — | Recurring, hourly. Due-check, then enqueue per-source jobs. |
| `advtn_ingest_source` | `source_id` | One source. Retries 2×, isolated failure. |
| `advtn_finalize_cycle` | — | Prune, select, render, cache-bust, release lock. |

Recurring registration on `init`, idempotent:

```php
if ( false === as_next_scheduled_action( 'advtn_ingest_cycle' ) ) {
    as_schedule_recurring_action( time() + 60, HOUR_IN_SECONDS, 'advtn_ingest_cycle', [], 'advtn' );
}
```

### 6.2 Cycle flow

`advtn_ingest_cycle` fires hourly and does almost nothing most of the time:

1. **Due-check.** If `advtn_last_ingest` is newer than `ingest_interval_hours` ago, return immediately. Use a due-check, not a fixed clock time — a missed 04:00 window under WP-Cron just means it runs at 07:12 when traffic arrives, and nothing breaks.
2. Acquire the lock (§6.3). If unavailable, return.
3. For each enabled source, in order: skip if `backoff_until` is in the future; otherwise `as_schedule_single_action( time() + ( $stagger_index * stagger_minutes * 60 ), 'advtn_ingest_source', [ $source_id ], 'advtn' )`.
4. Schedule `advtn_finalize_cycle` for `time() + ( count($sources) * stagger_minutes * 60 ) + 300`.

Staggering matters: 15 outbound HTTP requests in one page load is exactly what makes a visitor wait. Spreading a daily refresh over an hour is completely acceptable.

### 6.3 Locking — mandatory

The first target site gets decent traffic, so multiple concurrent requests will each try to spawn cron. Use an atomic `add_option()`. It is backed by a unique index on `option_name` and returns `false` if the row exists — unlike `update_option`, which has a race window.

```php
final class ADVTN_Lock {
    const KEY = 'advtn_ingest_lock';
    const TTL = 900; // seconds

    public static function acquire(): bool {
        if ( add_option( self::KEY, time(), '', 'no' ) ) {
            return true;
        }
        $held = (int) get_option( self::KEY, 0 );
        if ( ( time() - $held ) > self::TTL ) {
            // Stale — previous run died. Take over.
            update_option( self::KEY, time(), false );
            return true;
        }
        return false;
    }

    public static function release(): void {
        delete_option( self::KEY );
    }
}
```

Do **not** use `wp_cache_add()` for this. Without a confirmed persistent object cache it is per-request and worthless — and these hosts vary.

Wrap the work in `try { … } finally { ADVTN_Lock::release(); }` so a fatal does not wedge ingestion for 15 minutes. Also release in `advtn_finalize_cycle` unconditionally.

### 6.4 Per-request budget

Cron runs inside a real HTTP request, so `max_execution_time` applies.

- One source per `advtn_ingest_source` action.
- `http_timeout` = 5s per outbound call.
- Hard cap `batch_max_sources` (3) per Action Scheduler batch.
- Track elapsed time from the start of the batch; at `batch_time_budget` (20s), stop and leave remaining actions queued.
- Wrap each source's fetch in `try/catch( \Throwable )`. One bad source must never abort the cycle.

### 6.5 Failure backoff

On a source failure: increment `consec_fails`, set `backoff_until = time() + source_fail_backoff * min( consec_fails, 6 )`, record `last_error` and `http_code`. On success, reset `consec_fails` to 0 and clear `backoff_until`. A dead source must not be hammered every cycle.

### 6.6 External trigger — the primary path

WP-Cron is the safety net, not the mechanism. Trigger deterministically from n8n.

```
POST https://site.com/wp-json/advtn/v1/ingest
Headers:
  Content-Type: application/json
  X-ADVTN-Timestamp: 1780000000
  X-ADVTN-Signature: <hmac-sha256>
Body: {"force": false}
```

- `force: true` bypasses the due-check but **not** the lock.
- Handler calls the same `ADVTN_Ingest::run()` as the cron path. Same code, different adapter.
- Returns `202` with the scheduled action IDs, or `409` if the lock is held.

n8n setup: one workflow, daily schedule, one HTTP Request node per site. As the rollout grows this stays a single workflow with a list of endpoints, and failures land somewhere you actually look. Idempotency is guaranteed by the lock plus due-check, so a retried webhook is harmless.

### 6.7 WP-CLI (optional)

```php
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    WP_CLI::add_command( 'trending-now', 'ADVTN_CLI' );
}
```

Commands: `ingest [--source=<id>] [--force]`, `select`, `render`, `status`, `prune`. Convenience only — nothing may depend on CLI availability.

---

## 7. Selection and rotation

Runs once per cycle in `advtn_finalize_cycle`, after ingest and pruning. This is the part that actually serves the indexing goal.

### 7.1 Slot allocation

Given `widget_limit` = 30, `news_share_pct` = 20, `max_source_share_pct` = 20:

- News slots: `floor( 30 * 0.20 )` = 6, filled from `source_type = 'gdelt'`.
- Network slots: 24.
- Per-source cap: `ceil( 30 * 0.20 )` = 6. No single source may exceed this.

If a category underfills (e.g. only 3 news items available), reallocate the remainder to the other category. Never render fewer items than available.

### 7.2 Ordering within the network pool

Do **not** simply sort by date. With 30 slots and a daily refresh, date-sorting means a burst of 40 new posts crowds out the previous day's posts before they were ever crawled.

Three tiers, filled in order:

**Tier 1 — Pinned (exposure floor).** Items already shown but still inside their guaranteed window:

```sql
WHERE status = 'active'
  AND first_shown_at IS NOT NULL
  AND first_shown_at > ( NOW() - INTERVAL {exposure_floor_days} DAY )
ORDER BY published_at DESC
```

These occupy their slots unconditionally. Each new URL is guaranteed ~3 consecutive days of exposure rather than a single-cycle appearance.

**Tier 2 — Never shown.** Fill remaining slots with the newest unseen URLs:

```sql
WHERE status = 'active' AND times_shown = 0
ORDER BY published_at DESC
```

**Tier 3 — Least shown.** If slots remain:

```sql
WHERE status = 'active' AND times_shown > 0
ORDER BY times_shown ASC, published_at DESC
```

Apply the per-source cap while filling each tier: track a running count per `source_id` and skip candidates that would exceed the cap. If pinned Tier 1 items alone exceed the cap for a source, honor the pin — the floor takes precedence over diversity.

Enforce host-level dedupe as well: at most 2 items from the same `host` may appear adjacently in the final ordering. Interleave after selection.

### 7.3 Commit

1. Write ordered item IDs to `advtn_current_selection`.
2. For each selected item: `times_shown = times_shown + 1`, `last_shown_at = NOW()`, and `first_shown_at = COALESCE( first_shown_at, NOW() )`. Single batched `UPDATE … WHERE id IN (…)` for the counters, plus one for the `COALESCE`.
3. Re-render and cache (§8.3).

`advtn_current_selection` exists so "what is live right now" is answerable from the admin screen without re-running selection. It is the single source of truth for rendering.

---

## 8. Rendering

### 8.1 Non-negotiable constraints

- **Server-side only.** The links must be present in the initial HTML response body.
- **No AJAX, no `fetch()`, no client-side hydration, no lazy-loading of the list.**
- Plain `<a href="…">` elements. No redirect wrappers, no click tracking, no query parameters appended, no `data-href` swapping.
- Anchor text is the real post title.
- **Zero HTTP requests during render.** Zero database queries in the cached path.
- If the cache is empty and the DB has no items, render **nothing** — no empty container, no "no items" message.

### 8.2 Entry points

All three call the same `ADVTN_Renderer::render( array $args ): string`.

**Shortcode** — works in Elementor, Bricks, Gutenberg, widgets, and theme templates. This is why the page-builder APIs are skipped.

```
[trending_now limit="30" layout="list" heading="Trending Now" show_images="0" show_source="1" show_see_all="1"]
```

**Gutenberg block** — `blocks/trending-now/block.json` with `"render": "file:./render.php"` or a `render_callback` registered in PHP. Attributes mirror the shortcode. `"supports": { "html": false }`. The block is a thin wrapper; it must contain no rendering logic of its own.

**Template tag** — `advtn_render( array $args = [] )` echoes; `advtn_get_html( array $args = [] )` returns. For direct theme placement.

### 8.3 Cache

- Cache key: `'advtn_render_cache_' . md5( wp_json_encode( $normalized_args ) )`.
- Stored as an **option with `autoload = 'no'`**, not a transient. Transients can be evicted by an object cache under memory pressure; options cannot. The cached HTML must survive.
- **No expiry.** Only `advtn_finalize_cycle` busts it.
- Maintain `advtn_render_cache_keys` as a registry. On cache-bust, delete every registered key. Cap the registry at 20 variants; refuse to create new variants beyond that and fall back to an uncached DB render for the overflow (log a warning — it means someone is passing unbounded shortcode args).
- On cache miss: rebuild from `advtn_current_selection` with a single `WHERE id IN (…)` query, then write the cache. **Never** fetch a source.
- If `advtn_current_selection` is empty, fall back to a direct selection query against the table.

Consequence: if ingest has not run in three days, the widget still renders the last known good set. A broken cron degrades to stale links, never to a blank widget or a slow homepage.

### 8.4 Markup

Templates in `templates/`, overridable from the theme at `{theme}/trending-now/{template}.php`. `{p}` = `class_prefix` setting.

```html
<section class="{p} {p}--list" aria-labelledby="{p}-heading-a1b2">
  <h2 class="{p}__heading" id="{p}-heading-a1b2">Trending Now</h2>
  <ul class="{p}__items">
    <li class="{p}__item {p}__item--network">
      <a class="{p}__link" href="https://example.com/article/">Article title here</a>
      <span class="{p}__source">example.com</span>
      <time class="{p}__date" datetime="2026-08-05T14:22:00+00:00">Aug 5</time>
    </li>
    <li class="{p}__item {p}__item--news">
      <a class="{p}__link" href="https://espn.com/story/" rel="nofollow">News title</a>
      <span class="{p}__source">espn.com</span>
    </li>
  </ul>
  <p class="{p}__more">
    <a class="{p}__more-link" href="https://site.com/trending/">See all trending stories</a>
  </p>
</section>
```

- Escape with `esc_url()`, `esc_html()`, `esc_attr()` at every output point. No exceptions.
- `rel` attribute: only on `--news` items, only if `link_rel_external` is non-empty. Network items are always plain followed links.
- `target="_blank"` adds `rel="noopener"` (merged with any existing `rel`).
- Images (`show_images=1`): `loading="lazy"`, `decoding="async"`, explicit `width`/`height` if known. Never lazy-load in a way that affects the `<a>` itself.
- Heading ID suffix is a short hash of the args so multiple instances on one page remain valid HTML.

### 8.5 CSS

Generate CSS at render time from `class_prefix` and inline it in a `<style>` tag inside the cached HTML blob, once per page (guard with a static flag). Reasons: the prefix is configurable per site, and it avoids an extra HTTP request on the homepage. Keep it under ~1.5KB — flex/grid layout, no resets, no font declarations, inherits everything it can from the theme.

Ship `assets/css/trending-media.css` as an unenqueued reference copy for anyone who wants to override.

---

## 9. The "see all" archive

### 9.1 Route

- Rewrite rule: `^{archive_slug}/?$` and `^{archive_slug}/page/([0-9]+)/?$` → `index.php?advtn_archive=1&advtn_page=$matches[1]`.
- Register `advtn_archive` and `advtn_page` via `query_vars` filter.
- Flush rewrites on activation, deactivation, and whenever `archive_slug` changes. Never call `flush_rewrite_rules()` on `init`.
- Load `templates/archive.php` via `template_include`, theme-overridable at `{theme}/trending-now/archive.php`.
- Paginate at `archive_per_page` (50). Order `published_at DESC`. Use `SQL_CALC_FOUND_ROWS`-free counting: a separate `COUNT(*)` query, cached in a short transient (15 min).
- Output `rel="prev"` / `rel="next"` link tags and `wp_link_pages`-style navigation.
- Set a proper `<title>`, `og:` tags, and a canonical to the paginated URL.
- Include a short block of real introductory copy at the top, editable via a setting. A page of pure links with no context is exactly what a link dump looks like.

### 9.2 Indexing decision

Default `archive_noindex = false` (indexable). Make it a setting, and document the tradeoff in the settings UI:

- **Indexable** — Google crawls and indexes. The 90-day retention cap plus pagination plus intro copy is what keeps it defensible. This is the default because the page is a discovery vehicle and you want it crawled often.
- **`noindex, follow`** — Google crawls for discovery without indexing the aggregation. The catch: long-term noindexed pages get crawled progressively less over time, and Google has stated it eventually treats them as effectively `nofollow`. So it decays as a discovery vehicle, which is the opposite of what you want.

Whichever is set, exclude the archive from the core sitemap only if `archive_noindex` is true.

---

## 10. REST API

Namespace `advtn/v1`. Registered on `rest_api_init`.

### 10.1 HMAC authentication

Used by all three endpoints. No WordPress user account is involved — you do not want a real user in the loop for a machine trigger, which is why application passwords are not used here.

**Signing:**

```
message   = timestamp . "\n" . raw_request_body   // empty string for GET
signature = hash_hmac( 'sha256', message, secret )
```

**Headers:** `X-ADVTN-Timestamp` (unix seconds), `X-ADVTN-Signature` (lowercase hex).

**Verification (`ADVTN_HMAC::verify()`):**
1. Both headers present, else `401`.
2. `abs( time() - $timestamp ) <= 300`, else `401` with `code: 'advtn_timestamp_skew'`.
3. Recompute and compare with `hash_equals()`. Never `==`.
4. Replay guard: store `sha1($signature)` in a 300-second transient; reject if already present.
5. Rate limit: max 30 requests per 5 minutes per endpoint, tracked in a transient. Return `429`.

Secrets: 32 random bytes, hex-encoded, generated by `ADVTN_Activator` using `wp_generate_password( 64, false, false )` or `random_bytes()`. Displayed once in the admin with a regenerate button. Store in `advtn_settings`.

### 10.2 `POST /advtn/v1/ingest`

Body: `{ "force": false, "source": "src_7f3a91" }` (both optional).

| Status | Meaning |
|---|---|
| `202` | Accepted. Body: `{ "scheduled": ["src_…"], "cycle_due": true }` |
| `200` | Not due, nothing scheduled. Body: `{ "scheduled": [], "cycle_due": false, "last_ingest": "…" }` |
| `409` | Lock held. Body includes `lock_age_seconds`. |
| `401` | Auth failure. |

### 10.3 `GET /advtn/v1/items` (hub mode only)

Returns `403` unless `mode === 'hub'`.

Params: `limit` (1–500, default 200), `exclude_host` (comma-separated), `since` (ISO 8601), `types` (comma-separated `source_type` values).

Response:

```json
{
  "generated_at": "2026-08-06T04:12:00Z",
  "count": 200,
  "items": [
    { "url": "…", "title": "…", "excerpt": "…", "image_url": "…",
      "published_at": "2026-08-05 14:22:00", "site_name": "…",
      "source_type": "wp_rest", "host": "example.com" }
  ]
}
```

Cache the response body in an option, regenerated on `advtn_finalize_cycle`. Serving spokes must not hit the DB.

### 10.4 `GET /advtn/v1/status`

Diagnostics for n8n monitoring. Returns everything on the diagnostics panel as JSON: `last_ingest`, per-source state, item counts by status and type, `selection_size`, `cache_populated`, `lock_held`, `loopback_ok`, plugin and DB versions. Have n8n alert on `last_ingest` older than 30 hours.

---

## 11. Network footprint

Identical widget markup and identical link sets across 10–15 domains is itself a network fingerprint. Build the variation in, because it will not be added later.

- `class_prefix` is per-site configurable and used everywhere in the markup.
- `widget_limit`, `news_share_pct`, and `heading_text` vary per site.
- Selection ordering already varies naturally via per-site `times_shown` and `first_shown_at`.
- Do **not** configure every source site on every install. Each site should carry a partially overlapping subset.
- `archive_slug` varies per site (`/trending/`, `/whats-hot/`, `/latest-news/`).
- Templates are theme-overridable so markup structure can diverge.

**Placement guidance for the operator, not the code:** a sitewide footer block puts every URL on a link from thousands of pages, but sitewide boilerplate links are heavily discounted. The homepage and top-level section pages get crawled most often. That is where this earns its keep.

---

## 12. Admin UI

Top-level menu **Trending Now**, capability `manage_options`, with three tabs.

### 12.1 Settings

All of `advtn_settings`, grouped: Mode, Display, Rotation, Retention, Links, Archive, Scheduling, Security. Nonce-verified, sanitized per field with typed callbacks, range-clamped. Show the ingest secret with a copy button and a regenerate action.

### 12.2 Sources

Repeatable rows: label, type, URL, limit, enabled toggle, drag-to-reorder (order sets `stagger_index`). Type selector reveals type-specific fields. Per-row "Test fetch" button that runs `fetch()` synchronously via AJAX and shows the first 3 normalized items plus timing and HTTP code — without writing to the database. This is the single most useful thing in the admin.

### 12.3 Diagnostics — build this properly

Without CLI on some of these hosts, this panel is the only visibility when ingestion silently stops. Silent failure over weeks is precisely the failure mode already under investigation elsewhere.

Show:
- Last successful ingest, with a red banner if older than 30 hours.
- Per-source table: last run, last success, HTTP code, duration, items seen, items new, `consec_fails`, `backoff_until`, last error message.
- Item counts: total, active, stale, by `source_type`, never-shown count, count published in the last 7 days.
- Current selection: the live ordered list with source, `times_shown`, and `first_shown_at`.
- Cache status: which render keys are populated and their byte sizes.
- Lock status and age.
- Loopback request test result, WP-Cron enabled/disabled, Action Scheduler pending count, link to **Tools → Scheduled Actions**.
- Last 200 log entries from `advtn_log`, filterable by level.
- Buttons: **Run ingest now**, **Rebuild selection**, **Purge render cache**, **Release lock** (with confirmation).

### 12.4 Logger

`ADVTN_Logger::log( string $level, string $message, array $context = [] )`. Levels `debug|info|warning|error`. Ring buffer capped at 200 entries in the `advtn_log` option. `debug` only recorded when `WP_DEBUG` is true. Never log secrets or full signatures.

---

## 13. Security checklist

- Every `$wpdb` call uses `prepare()`. No string interpolation into SQL, including `IN (…)` lists — build placeholders dynamically.
- All output escaped at the point of output: `esc_url`, `esc_html`, `esc_attr`, `esc_url_raw` for stored URLs.
- Admin forms: `wp_nonce_field()` + `check_admin_referer()` + `current_user_can( 'manage_options' )`.
- AJAX: `check_ajax_referer()` + capability check.
- Source URLs validated with `wp_http_validate_url()` on save and again before every fetch. Reject non-http(s), reject private/loopback IP ranges unless a constant explicitly permits it.
- All outbound requests through `wp_remote_get()` — never cURL directly, never `file_get_contents()`.
- `hash_equals()` for every signature comparison.
- `uninstall.php` guards on `WP_UNINSTALL_PLUGIN` and only drops data if `delete_data_on_uninstall` is true.
- Text domain `trending-now`, all strings translatable.

---

## 14. Acceptance criteria

Phase 1 is complete when all of the following pass.

**Ingestion**
- [ ] A `wp_rest` source returns ≥1 correctly mapped item, including featured image resolution and a post with no featured image.
- [ ] An `rss` source returns items with correct UTC dates and does not serve SimplePie's 12h cache.
- [ ] A `gdelt` source returns items restricted to the domain allowlist, with `excerpt` null and templates handling it.
- [ ] Malformed GDELT JSON fails the source cleanly with a logged error and no PHP notice.
- [ ] Re-running ingest twice creates zero duplicate rows.
- [ ] Two URLs differing only by `?utm_source=…` and a trailing slash produce the same `url_hash`.
- [ ] A source returning HTTP 500 sets `backoff_until` and is skipped on the next cycle.
- [ ] One source timing out does not prevent the other sources from ingesting.
- [ ] Items from the local site's own host are never ingested.

**Scheduling**
- [ ] `advtn_ingest_cycle` runs hourly and returns immediately when not due.
- [ ] Two simultaneous ingest triggers result in exactly one run; the second returns `409`.
- [ ] A lock older than 900s is taken over.
- [ ] A fatal error mid-ingest still releases the lock.
- [ ] Sources are staggered by `stagger_minutes`; no page load makes more than `batch_max_sources` outbound requests.
- [ ] The signed REST trigger schedules a cycle and returns `202`.
- [ ] An unsigned or stale-timestamp request returns `401`.
- [ ] The plugin activates and functions on a host without WP-CLI.

**Selection**
- [ ] A newly ingested article appears in the widget within one cycle.
- [ ] An article shown today is still present 2 days later (exposure floor), even after 100 newer articles are ingested.
- [ ] No single source exceeds `max_source_share_pct` of slots, except via honored Tier 1 pins.
- [ ] News items occupy approximately `news_share_pct` of slots and reallocate when underfilled.
- [ ] `times_shown` increments exactly once per cycle per displayed item.
- [ ] `first_shown_at` is set once and never overwritten.

**Rendering**
- [ ] `curl -s <homepage> | grep -c 'advtn__link'` returns the expected count. **All links present in raw HTML with JS disabled.**
- [ ] Query Monitor shows **zero** database queries attributable to the widget on a warm cache.
- [ ] Query Monitor shows **zero** HTTP requests during any pageview.
- [ ] Shortcode renders identically inside an Elementor Text Editor widget, a Bricks Shortcode element, and a Gutenberg block.
- [ ] Two instances on one page produce valid HTML (unique heading IDs).
- [ ] With ingestion stopped for 3 days, the widget still renders the last known set.
- [ ] With an empty table, the widget outputs an empty string — no container, no message.
- [ ] Markup passes W3C validation; the section has a proper heading and `aria-labelledby`.

**Archive**
- [ ] `/trending/` returns 200 with 50 items and working pagination.
- [ ] `/trending/page/2/` returns a distinct set with correct `rel="prev"`/`rel="next"`.
- [ ] Changing `archive_slug` flushes rewrites and the new URL works without a manual permalink save.
- [ ] `archive_noindex = true` emits `<meta name="robots" content="noindex,follow">` and excludes the page from the core sitemap.

**Retention**
- [ ] Items not seen in 7 days become `stale` and drop out of widget selection but remain in the archive.
- [ ] Items older than `retention_days` are deleted in batches with no long-running query.

**Diagnostics**
- [ ] A broken source is visibly flagged on the diagnostics panel with its error message.
- [ ] `GET /advtn/v1/status` returns valid JSON suitable for n8n alerting.
- [ ] "Run ingest now" works from the admin.

**Hygiene**
- [ ] `wp plugin verify-checksums`-clean structure; no PHP notices with `WP_DEBUG` and `WP_DEBUG_LOG` on.
- [ ] Deactivation unschedules all Action Scheduler actions and flushes rewrites.
- [ ] Uninstall with the flag off leaves data intact; with it on, removes the table and every `advtn_*` option.

---

## 15. Build order

Work in this sequence. Each step is independently verifiable.

| # | Deliverable |
|---|---|
| 1 | Bootstrap, constants, autoloader, activator, `ADVTN_Schema` table creation. Verify the table exists with correct indexes. |
| 2 | `ADVTN_Settings` + `ADVTN_URL` (normalize/hash/host) + unit tests for normalization edge cases. |
| 3 | `ADVTN_Repository`: upsert, fetch by IDs, selection queries, prune. Test against seeded fixture rows. |
| 4 | `ADVTN_Source_WP_REST` + the `Fetch_Result` shape. Test against a real network site. |
| 5 | `ADVTN_Renderer` + shortcode + `templates/widget-list.php`, rendering straight from the DB, uncached. Get links on a page. |
| 6 | `ADVTN_Selector` with the three tiers and quotas. Verify against seeded data before wiring to cron. |
| 7 | Render cache (options, no expiry, key registry) + cache-bust on selection commit. Verify zero queries warm. |
| 8 | `ADVTN_Lock`, `ADVTN_Ingest`, `ADVTN_Scheduler` with Action Scheduler. Verify concurrency and the due-check. |
| 9 | `ADVTN_HMAC` + `POST /ingest` + `GET /status`. Wire the n8n workflow. |
| 10 | Admin: settings, sources with per-row Test fetch, diagnostics panel, logger. |
| 11 | `ADVTN_Source_RSS`, then `ADVTN_Source_GDELT` with allowlist post-filtering and malformed-response hardening. |
| 12 | `ADVTN_Archive`: rewrite, template, pagination, robots handling. |
| 13 | Gutenberg block wrapping the existing renderer. Optional `widget-cards.php` layout. |
| 14 | Pruning, retention, stale marking, uninstall, i18n pass, README. |
| 15 | **Phase 2:** `GET /items` on the hub, `ADVTN_Source_Hub`, `spoke` mode, per-site footprint variation. |

Steps 1–14 are Phase 1 on a single site. Step 15 is ~2 days once Phase 1 is stable.

---

## 16. Open decisions for the operator

Not blockers for implementation; defaults are set. Revisit after two weeks of data.

1. **Widget placement.** Homepage plus top-level section pages, versus sitewide footer. Sitewide gets more raw links; boilerplate links are discounted more. Start with homepage + section pages.
2. **Archive indexability.** Default indexable. Reconsider if the page attracts no impressions and is consuming crawl budget.
3. **`link_rel_external`.** Default empty (followed) on news links. Outbound links to authoritative sites do not transfer authority *to* you and will not make your pages index — the reason to include them is that a trending widget containing only your own network's links looks like exactly what it is. That is a real reason. It is not a ranking mechanism, so do not tune it as one.
4. **`exposure_floor_days`.** Default 3. If server logs show Googlebot hitting the homepage only every few days, raise to 5–7.
5. **News share.** Default 20%. Lower it if network URL volume exceeds available slots.

---

## Appendix: worth running in parallel

This plugin improves **discovery** — Google finding URLs it did not know about. The reported symptoms (sites not deindexed, older pages fine, some new articles indexed, changed over a few weeks) are the signature of a **selection** problem: Google crawls the URL and declines to index it. More internal links do not move that needle.

Two diagnostics cost nothing and leave no footprint:

1. **Server access logs.** Grep Googlebot, verify by reverse DNS (a large share of self-declared Googlebot traffic is fake), then answer: is Googlebot requesting the new URLs at all, what status codes is it receiving, and what is the crawl-volume trend over the last 8 weeks. If Googlebot is fetching them and they are still not indexed, this plugin is decoration. If Googlebot never touches them, it helps.

2. **pgvector similarity.** Run pairwise cosine similarity across articles from the last 8 weeks against articles from 4–6 months ago. If the recent cohort clusters noticeably tighter, the problem is pipeline output, not linking. Bucket indexed versus not-indexed URLs by which of the three production flows generated them — if one flow's output is disproportionately unindexed, that is the cheapest finding available.

Also check what changed 4–8 weeks ago: template edits, a plugin update introducing a stray `noindex` or bad canonical, sitemap breakage, host or IP change.

These determine whether Phase 2 is worth building, or whether the effort belongs in the content pipeline instead.
