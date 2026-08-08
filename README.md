# Trending Now

A WordPress plugin that renders a server-side **Trending Now** block of links aggregated
from owned WordPress sites and third-party news, plus a paginated archive at `/trending/`.

The goal is **crawl discovery**: giving every newly published URL across a network a
guaranteed window of exposure in an internally-linked, server-rendered block on
higher-authority properties.

[![Version](https://img.shields.io/badge/version-1.1.6-blue)](CHANGELOG.md)
[![WordPress](https://img.shields.io/badge/WordPress-6.4%2B-21759b)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4)](https://www.php.net)
[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-green)](LICENSE)

---

## Contents

- [Why this exists](#why-this-exists)
- [Requirements](#requirements)
- [Install](#install)
- [Quick start](#quick-start)
- [Sources](#sources)
- [Manual links](#manual-links)
- [Displaying the widget](#displaying-the-widget)
- [The archive](#the-archive)
- [How selection works](#how-selection-works)
- [Scheduling and triggering](#scheduling-and-triggering)
- [REST API](#rest-api)
- [Admin reference](#admin-reference)
- [WP-CLI](#wp-cli)
- [Operating modes](#operating-modes)
- [Network footprint](#network-footprint)
- [Settings reference](#settings-reference)
- [Hooks](#hooks)
- [Development](#development)
- [Troubleshooting](#troubleshooting)
- [What this deliberately does not do](#what-this-deliberately-does-not-do)

---

## Why this exists

Search engines have to *find* a URL before they can index it. On a network of sites, new
articles often sit unlinked from anywhere crawled frequently, so discovery lags publishing
by days.

This plugin puts every new URL into a plain, server-rendered `<a href>` list on pages that
get crawled often — the homepage and top-level section pages — and keeps it there for a
guaranteed number of days rather than letting the next day's publishing burst push it out.

**It improves discovery, not selection.** If Googlebot is already fetching your new URLs
and declining to index them, that is a content-quality problem and this plugin will not
move it. Check your server logs before assuming otherwise.

## Requirements

| | |
|---|---|
| WordPress | 6.4+ |
| PHP | 7.4+ |
| MySQL / MariaDB | 5.7+ / 10.3+ |
| Composer | for Action Scheduler (optional — falls back to WP-Cron) |

PHP 7.4 is supported but end-of-life upstream — it stopped receiving security fixes in
November 2022. It is here for legacy installs, not as a recommendation. The plugin is
developed and tested against 8.x.

Loopback HTTP requests must succeed for Action Scheduler's queue runner. The Diagnostics
tab tests this for you.

## Install

```bash
git clone git@github.com:advision-development/trending-now-plugin.git trending-now
cd trending-now
composer install --no-dev
```

Place the directory at `wp-content/plugins/trending-now` and activate it. Activation
creates the `{prefix}advtn_items` table, seeds defaults, generates the ingest secret and
flushes rewrite rules.

Or grab the packaged zip from the [latest release](https://github.com/advision-development/trending-now-plugin/releases)
— it has `vendor/` bundled and no development files — and install it through
**Plugins → Add New → Upload Plugin**.

## Quick start

1. **Trending Now → Sources → Add source.** Point a `wp_rest` source at another site in
   your network (its site root, not a feed URL).
2. Press **Test fetch** on that row. It performs a live fetch and shows the first three
   normalized items, timing and HTTP status — without writing anything.
3. **Save sources.**
4. **Diagnostics → Run ingest now.** This fetches everything in that one request and
   rebuilds the selection before the page reloads.
5. Put `[trending_now]` on your homepage, or add the **Trending Now** block.

If nothing renders, Diagnostics will say why — see [Troubleshooting](#troubleshooting).

## Sources

| Type | Use for | Notes |
|---|---|---|
| `wp_rest` | Owned WordPress sites | `/wp-json/wp/v2/posts`. Preferred: `/feed/` is capped by the remote site's `posts_per_rss` with no per-request override. |
| `rss` | Sites with the REST API disabled | SimplePie via `fetch_feed()`, with its 12-hour cache disabled for the call. |
| `serpapi` | Third-party news | Google News via [SerpAPI](https://serpapi.com). Needs a key; **one search credit per fetch**. |

Each row has a label, an item limit per cycle, a timeout, an enabled toggle, and
drag-to-reorder (order sets the ingest stagger). SerpAPI rows additionally take a query, an
optional allowed-domain list, country and language.

**Timeout** overrides the global `http_timeout` for that one row. Blank or `0` inherits the
global; otherwise it is clamped to 1–120 seconds — double the global's ceiling of 60,
because a per-row override is a considered choice about one provider, not a blunt default
applied to everything. It sets how long that source's *request* may take; the TLS connect
phase still follows the global `http_timeout` regardless.

The tab exports the whole list as JSON and imports it back — merging on source id or
replacing outright — so a source set can be moved between installs. Imported rows are
validated exactly as though typed into the form; bad rows are reported and skipped.

**Recent attempts.** A row that has been fetched at least once shows a disclosure with its
last 20 attempts: timestamp, ok/fail, duration, HTTP code and error, newest first, plus a
summary line of count, median (`p50`) and max duration next to the timeout currently in
force. This is what turns "it failed once, three weeks ago" into "it has been drifting
toward its timeout for the last six hours."

**Test fetch** versus **Ingest now.** Every saved, enabled row has both, side by side.
**Test fetch** runs the fetch and shows the first three normalized items, timing and HTTP
status — it writes nothing. **Ingest now** writes: it fetches that one source for real,
upserts whatever it finds, rebuilds the selection and purges caches, exactly like a normal
cycle but for a single source. It also ignores that source's failure backoff, which is the
point of pressing it on a source that has been failing — see
[Troubleshooting](#troubleshooting).

The two read different things. Test fetch uses whatever is on screen, saved or not; **Ingest
now uses the saved row**, because it goes through the stored configuration. Change a field
and you must save before the retry will use it — the button leaves the page, so the admin
confirms first rather than silently discarding the edit. Ingest now is also *not* a
completed cycle: it does not update `last_ingest`, so it neither defers the next scheduled
run of your other sources nor clears the stale-ingest banner.

A SerpAPI source runs in one of two modes. **Top stories** is Google News' mainstream
front page for the country and language you pick — no query needed, and the usual source
of a general news mix. **Search query** takes a query string, where Google operators such
as `site:` work. Either mode takes an optional domain allowlist, applied after the
response, since an aggregator returns whoever it likes unless constrained.

> Top stories returns roughly ten articles per fetch. If your news share needs more than
> that — say 50% of a 30-link widget — add a second news source rather than expecting one
> call to fill it.

> **Cost.** One credit per fetch, not per item, so a source on a daily cycle costs about
> 30 a month regardless of `limit`. Identical repeat queries are served from SerpAPI's own
> cache and are not billed, so re-testing a row while configuring it is free.

A GDELT provider shipped in 1.0.0 and was removed: free, but 10–20s per request and a rate
limit of roughly one request per five seconds whose penalty outlasts its own window. Any
`gdelt` source rows are dropped automatically on upgrade; items already ingested from it
are kept, still counted as news, and age out normally.

## Manual links

**Trending Now → Manual links** is a hand-curated list that mixes into the same widget as
ingested links. Each entry takes the same fields an ingested item has — URL, title,
excerpt, image, source name, publish date — plus two of its own:

- **Position** — the slot it should occupy, 1-based. `0` means "include it, but let it
  fall where it may". Out-of-range positions clamp to the end rather than dropping the
  link.
- **Expires** — a UTC timestamp, with `+1 day` / `+3 days` / `+1 week` / `+30 days`
  shortcuts, or empty for indefinite. The row shows the time remaining at a glance.

Curated links are stored in the items table like everything else, so they deduplicate
against anything a source also finds, appear in the archive, and carry the same display
counters. They reserve their slots before the tiers run, so a widget limit of 30 with
three curated links leaves 27 for automatic selection.

Two deliberate differences from ingested sources:

- **Links back to this site are allowed.** The self-link rule exists to catch a source
  echoing your own content; choosing one by hand is a different thing.
- **Expiry marks the row stale rather than deleting it** — off the widget, kept in the
  archive. When a timer runs out the list is rebuilt immediately via a scheduled action,
  rather than waiting for the next ingest cycle, which on the default interval could be
  20 hours away.

## Displaying the widget

Three entry points, one renderer.

**Shortcode** — works in Elementor's Text Editor widget, Bricks' Shortcode element,
Gutenberg, classic widgets and theme templates:

```
[trending_now limit="30" layout="news" heading="Trending Now"
              show_images="1" show_source="1" show_date="1"
              show_icons="0" show_excerpt="0" show_see_all="1"]
```

**Block** — *Trending Now*, with the same options in the inspector.

Every display attribute is optional. Omit one and it inherits the setting; supply it and
it wins for that instance. Off by default for excerpts, since not every source provides
one — GDELT never did, and Google News does not either.

Three layouts, and **`news` is the default** — what you get with a bare `[trending_now]`.
It is the Google News / MSN style: source name and a relative
timestamp, the headline, and a thumbnail on the right. Thumbnails carry fixed dimensions
so they reserve their space instead of shifting the layout, and everything below the first
card is lazy-loaded — the first is fetched eagerly because it is usually the one above the
fold. **`list`** is compact text links, **`cards`** a grid with excerpts. An item with no
thumbnail simply lets its headline span the full width, as Google News does.

Enabling **site icons** puts a publisher favicon beside each source name, as in the
Google News and MSN feeds. The URL is derived from the stored host — a news API's own
`icon` field turns out to be exactly that anyway — so it costs no ingest work and covers
your own sites as well as third-party ones. The image is fetched by the visitor's browser,
not the server, so the no-HTTP-during-render rule still holds; it does mean Google's
favicon service sees your visitors, which is why it is off by default. Filter
`advtn_source_icon_url` to self-host or swap providers.

Timestamps come in two styles, set by `date_style`. **`relative`** shows `45m` or `6h`
inside the last day and a date before that, which is what makes a feed read as current at
a glance. **`date`** always shows the date.

Either way the label is recalculated on every request from the `datetime` attribute
already in the markup, not baked into the cached HTML. Without that, an article rendered
at `42m` would still claim `42m` a full ingest interval later — up to 20 hours of a page
insisting day-old news is minutes old.

> **Relative stamps advertise your publishing rhythm.** On a once-a-day cycle every item
> drifts towards `20h` or `23h` together, which reads as a batch update rather than a live
> feed. If that matters more to you than the freshness cue, use `date`.

**Template tags:**

```php
advtn_render( array( 'limit' => 20, 'layout' => 'cards' ) );  // echoes
$html = advtn_get_html();                                     // returns
```

Templates live in `templates/` and are overridable from a theme at
`{theme}/trending-now/{template}.php` — `widget-list.php`, `widget-cards.php`,
`archive.php`.

### Markup and CSS

```html
<section class="advtn advtn--list" aria-labelledby="advtn-heading-a1b2">
  <h2 class="advtn__heading" id="advtn-heading-a1b2">Trending Now</h2>
  <ul class="advtn__items">
    <li class="advtn__item advtn__item--network">
      <a class="advtn__link" href="https://example.com/article/">Article title</a>
      <span class="advtn__source">example.com</span>
      <time class="advtn__date" datetime="2026-08-05T14:22:00+00:00">Aug 5</time>
    </li>
  </ul>
  <p class="advtn__more"><a class="advtn__more-link" href="/trending/">See all…</a></p>
</section>
```

`advtn` is the configurable `class_prefix`. The stylesheet is generated from it and
inlined once per page inside the cached HTML, so there is no extra request and no
hard-coded prefix. `assets/css/trending-now.css` is an unenqueued reference copy.

Because the widget renders after `wp_head`, that `<style>` sits in the body, which the
W3C Nu validator flags. For a strictly clean validation run, filter `advtn_inline_css` to
return `''` and enqueue your own stylesheet.

## The archive

A paginated "see all" page at `/trending/` (slug configurable), listing the full retained
set — active and stale — newest first. It renders with the **same card markup and classes
as the widget's news layout**, so the two match without a second stylesheet to keep in
step, and it honours the same display settings for thumbnails, icons, source names and
timestamps. Only the first thumbnail is eager; the rest lazy-load, which matters more here
than in the widget since a page holds fifty of them.

- Proper `<title>`, canonical, `og:` tags and `rel="prev"` / `rel="next"`.
- Out-of-range pages redirect rather than 404.
- Indexable by default, and registered in the core sitemap while it is.
- Setting `archive_noindex` emits `noindex, follow` and drops it from the sitemap.
- Works on both block and classic themes.

**On indexability:** the default is indexable, because the page is a discovery vehicle and
you want it crawled often — the 90-day retention cap, pagination and intro copy are what
keep it defensible. `noindex, follow` looks like the safe choice but decays: Google crawls
long-term noindexed pages progressively less and eventually treats their links as
`nofollow`, which removes the only thing you wanted from the page.

Add real introductory copy via the **Intro copy** setting. A page of pure links with no
context looks like exactly what it is.

## How selection works

Runs once per cycle, after ingest and pruning.

**Slots.** `widget_limit` splits into news (`news_share_pct`) and network. Either side
reallocates to the other when it underfills. A per-source cap of `max_source_share_pct`
keeps one source from dominating.

> **The cap and the news share have to agree.** `news_share_pct` of 50 with a single news
> source needs `max_source_share_pct` at 50 too, or the cap throttles that source to a
> fraction of its quota and the shortfall is backfilled with network links. Either raise
> the cap or spread the news across more sources.

**Age cutoff.** `max_age_hours` drops anything published longer ago than that — `72` by
default for a three-day window, `0` to disable. It applies to the widget **and** to
`/trending/`, so an article that was on the list yesterday falls off both today once it
crosses the line. Rows outside the window stay in the table until retention prunes them,
so they still deduplicate against anything a source finds again.

It applies before the tiers, so it beats the exposure floor — and the two are measured
from different clocks. The floor counts from when an item was *first shown*, the cutoff
from when it was *published*, so an item ingested six hours after publication needs six
hours of slack between them or its guaranteed run is cut short. That is why the defaults
ship at 72 hours against a 2-day floor rather than a 3-day one. The Settings screen warns
when the floor meets or exceeds the cutoff. Curated links are exempt, since they carry
their own expiry.

**Three tiers, filled in order:**

1. **Pinned** — already shown and still inside `exposure_floor_days`. Held
   unconditionally, even past the per-source cap.
2. **Never shown** — newest first.
3. **Least shown** — fewest impressions first, then newest.

Sorting purely by date would let a burst of 40 new posts crowd out yesterday's posts
before they were ever crawled. The exposure floor is what prevents that: every URL gets a
guaranteed run of consecutive days rather than a single-cycle appearance.

The per-source cap is a **preference, not a hard limit**. A final pass lifts it rather
than leaving slots empty while usable items sit unselected. After selection, items are
reordered so no more than two consecutive links share a host.

The committed order lives in `advtn_current_selection`, so "what is live right now" is
answerable without re-running selection.

## Scheduling and triggering

`advtn_ingest_cycle` runs hourly and returns immediately unless `ingest_interval_hours`
has elapsed since the last **completed** cycle. When due it takes a lock and queues one
staggered action per source, then a finalize action that prunes, marks stale, rebuilds the
selection, purges the render cache and releases the lock.

A due-check rather than a fixed clock time: a missed window just runs late instead of
being skipped.

> Nothing an ingest writes is **visible** until finalize runs — that is what commits the
> selection and busts the cache. With `n` sources it lands roughly `n × stagger + 5`
> minutes after the trigger. Diagnostics lists the pending actions and their due times.

**The deterministic path is an external trigger** — one n8n workflow, one HTTP node per
site. WP-Cron is the safety net, not the mechanism.

```bash
TS=$(date +%s)
BODY='{"force":false}'
SIG=$(printf '%s\n%s' "$TS" "$BODY" | openssl dgst -sha256 -hmac "$SECRET" -r | cut -d' ' -f1)

curl -X POST https://site.com/wp-json/advtn/v1/ingest \
  -H "Content-Type: application/json" \
  -H "X-ADVTN-Timestamp: $TS" \
  -H "X-ADVTN-Signature: $SIG" \
  -d "$BODY"
```

Idempotency comes from the lock plus the due-check, so a retried webhook is harmless.

### Retention

Items not seen for 7 days become `stale`: dropped from the widget, kept in the archive.
Items older than `retention_days` (default 90) are deleted in batches of 500 under a time
budget — never one unbounded `DELETE`. The retention cap is what keeps the archive a real
page rather than an unbounded link dump.

## REST API

Namespace `advtn/v1`. All endpoints are HMAC-signed; no WordPress user is involved.

```
message   = timestamp + "\n" + raw_request_body      (empty string for GET)
signature = hash_hmac('sha256', message, secret)     lowercase hex
headers   = X-ADVTN-Timestamp, X-ADVTN-Signature
```

Requests are rejected outside a 300-second clock skew, replayed signatures are refused for
300 seconds, and each endpoint is capped at 30 requests per 5 minutes.

| Endpoint | Purpose | Responses |
|---|---|---|
| `POST /ingest` | Trigger a cycle. Body `{"force":false,"source":"src_…"}`, both optional. | `202` scheduled · `200` not due · `409` lock held · `401` auth |
| `GET /items` | Hub mode only. Serves the assembled list to spokes. Params `limit`, `exclude_host`, `since`, `types`. | `200` · `403` not a hub · `401` auth |
| `GET /status` | Everything on the Diagnostics panel, as JSON. | `200` · `401` auth |

`force: true` bypasses the due-check but never the lock. Point your monitoring at
`/status` and alert on `last_ingest` older than 30 hours. A row's own **Ingest now** button
deliberately does not touch `last_ingest` — only a whole cycle does — so the alert keeps
firing while an operator retries one source.

`/status` returns each source's full runtime state, which since the attempt ring includes
its last 20 attempts. Budget roughly 5 KB per source rather than a fixed few hundred bytes:
a 30-source install answers with about 150 KB. It is HMAC-signed and not public, but poll it
on a sane interval and do not log the body wholesale.

## Admin reference

**Settings** — mode, display, rotation, retention, links, archive, scheduling, security.
Hub fields appear only in the modes that use them: the hub URL in spoke mode, the shared
secret in hub and spoke.

**Sources** — repeatable rows with a per-row timeout, **Test fetch**, **Ingest now**,
drag-to-reorder, type-specific fields, a **Recent attempts** history once a row has been
fetched, and JSON import/export.

**Diagnostics** — the panel that matters when ingestion silently stops:

- Last successful ingest, with a red banner past 30 hours.
- Per-source table: last run, last success, HTTP code, duration, items seen and new,
  consecutive failures, backoff, last error.
- Queued scheduled actions and their due times.
- Item counts by status and type, never-shown count, last-7-days count.
- The live selection with `times_shown` and `first_shown_at`.
- Render cache keys and byte sizes; lock status and age.
- Loopback test, WP-Cron status, Action Scheduler status and pending count.
- Last 200 log entries, filterable by level.
- **Stored items browser** — filter by source, host, type, status or a title/URL search,
  then delete a single row, a checked selection, everything matching the filter, or the
  whole table.
- Buttons: Run ingest now, Rebuild selection, Purge render cache, Test loopback, Release
  lock, Clear log.

A filtered delete refuses to run with no filter set, so a dropped dropdown cannot become a
full wipe. Deleting only clears stored rows — if a source still lists an article it
returns on the next ingest, so disable or remove the source first.

## WP-CLI

Optional. Registered only when `WP_CLI` is defined; nothing depends on it.

```bash
wp trending-now ingest [--source=<id>] [--force] [--sync]
wp trending-now select
wp trending-now render [--uncached]
wp trending-now status
wp trending-now prune
wp trending-now flush [--all] [--source=<id>] [--host=<host>] [--status=<s>] [--yes]
wp trending-now purge      # render cache
wp trending-now unlock     # release a stuck ingest lock
```

## Operating modes

- **`direct`** — fetches its own sources. Single-site default.
- **`hub`** — same, plus serves the assembled list to spokes over REST.
- **`spoke`** — no sources of its own; pulls a ready list from `hub_url` once per cycle
  and runs local selection and rendering as normal.

A spoke never contacts source sites. With 15 sites and 15 sources that would be 225
redundant daily fetches producing divergent results. Rolling out is one setting change:
designate one install as the hub, switch the rest to spoke.

## Network footprint

Identical markup and identical link sets across a dozen domains is itself a fingerprint.
Vary these per site — the plugin is built to let you, and it will not get done later:

- `class_prefix`, `widget_limit`, `news_share_pct`, `heading_text`, `archive_slug`.
- Give each site a **partially overlapping subset** of sources, not the full list.
- Override templates per theme so the markup structure diverges.

Selection order already varies naturally through per-site `times_shown` and
`first_shown_at`.

**Placement.** A sitewide footer puts every URL on thousands of pages, but sitewide
boilerplate links are heavily discounted. The homepage and top-level section pages get
crawled most often — that is where this earns its keep.

## Settings reference

| Setting | Default | Notes |
|---|---|---|
| `mode` | `direct` | `direct` · `hub` · `spoke` |
| `widget_limit` | 30 | Links in the widget |
| `max_age_hours` | 72 | 0–720. Hide anything older, in the widget and on `/trending/`. `0` disables |
| `layout` | `news` | `list` · `news` · `cards`. Default for shortcode, block and template tag |
| `show_images` / `show_source` / `show_date` | on / on / on | Display defaults |
| `show_icons` | off | Publisher favicon beside the source name |
| `show_excerpt` | off | Excerpt under the headline |
| `date_style` | `relative` | `relative` (45m, 6h, then a date) · `date` (always a date) |
| `news_share_pct` | 20 | 0–50. Slots reserved for third-party news |
| `max_source_share_pct` | 20 | 5–100. Soft cap per source |
| `exposure_floor_days` | 2 | Guaranteed consecutive days once shown. Keep under `max_age_hours` |
| `retention_days` | 90 | Hard cap on table size. `/trending/` is bounded by `max_age_hours` |
| `ingest_interval_hours` | 20 | Due-check threshold, not a schedule |
| `stagger_minutes` | 7 | Gap between per-source jobs |
| `batch_max_sources` | 3 | Sources per queue batch |
| `batch_time_budget` | 20 | Seconds before bailing and requeueing |
| `http_timeout` | 5 | 1–60. Per outbound request. 30 is a safe ceiling |
| `source_fail_backoff` | 3600 | Seconds skipped after a failure, ×min(fails, 6) |
| `archive_slug` | `trending` | Vary per site |
| `archive_per_page` | 50 | 5–200 |
| `archive_noindex` | `false` | See [The archive](#the-archive) |
| `archive_enabled` | `true` | |
| `archive_intro` | — | Real copy at the top of the archive |
| `link_target_blank` | `true` | Adds `rel="noopener"` |
| `link_rel_external` | `''` | `''` · `nofollow` · `sponsored`. **News items only** |
| `heading_text` | `Trending Now` | |
| `see_all_text` | `See all trending stories` | |
| `class_prefix` | `advtn` | Vary per site |
| `hub_url` / `hub_secret` | — | Hub and spoke modes |
| `ingest_secret` | generated | Signs `/ingest` and `/status` |
| `serpapi_key` | — | Required by SerpAPI sources. See constants below |
| `github_token` | — | Only needed if the plugin repo is private |
| `auto_update` | `true` | Offer updates from GitHub releases |
| `purge_page_cache` | `true` | Clear the host page cache when the list changes |
| `delete_data_on_uninstall` | `false` | |

`link_rel_external` applies **only** to news items (`serpapi`). Internal network
links stay plain followed links — that is the entire point of the plugin.

### Credentials as constants

Both credentials can be defined in `wp-config.php` instead of being stored in the
database, which keeps them out of backups, settings exports and the admin screen:

```php
define( 'ADVTN_SERPAPI_KEY',  '…' );
define( 'ADVTN_GITHUB_TOKEN', '…' );
```

A constant wins over the stored option, and the matching admin field is disabled with a
note so an empty box is not mistaken for an unset key.

## Hooks

| Hook | Type | Purpose |
|---|---|---|
| `advtn_source_map` | filter | Register an additional source type |
| `advtn_inline_css` | filter | Replace or suppress the generated stylesheet |
| `advtn_source_icon_url` | filter | Change or suppress the publisher favicon URL |
| `advtn_purge_page_cache` | action | Clear a page cache or CDN the plugin does not know |
| `advtn_ingest_cycle` | action | Hourly due-check |
| `advtn_ingest_source` | action | Ingest one source (`$source_id`) |
| `advtn_finalize_cycle` | action | Prune, select, render, unlock |

A new source type implements `ADVTN_Source_Interface` — extend `ADVTN_Source_Base` — and
registers through `advtn_source_map`.

## Updating

The plugin carries an `Update URI` header pointing at its GitHub repository, so
WordPress routes update checks here instead of to wordpress.org — which also removes any
risk of an unrelated plugin with the same slug being installed over it.

New releases appear on the Plugins screen like any other update, and WordPress's own
per-plugin auto-update toggle works normally. **Diagnostics → Check for updates** forces
an immediate check; results are cached for six hours otherwise.

The updater prefers the `.zip` asset attached to the release, because it unpacks to a
clean `trending-now/` directory with `vendor/` bundled. It falls back to GitHub's
generated zipball, which unpacks to `owner-repo-<sha>/` and is renamed on the way in.

If the repository is private, set a **GitHub token** under Settings → Security: a
fine-grained personal access token with read-only Contents access. Without it the check
reports "no release found". The token is only ever sent to `api.github.com` — never to
the release CDN, which rejects it anyway.

### Cutting a release

```bash
# bump Version: and ADVTN_VERSION in trending-now.php, add a CHANGELOG entry
git tag -a v1.0.1 -m "Trending Now 1.0.1" && git push origin v1.0.1
gh release create v1.0.1 trending-now-1.0.1.zip --title "Trending Now 1.0.1" --notes-file -
```

The tag may be `v1.0.1` or `1.0.1`; the leading `v` is stripped before comparison.

## Development

```bash
php tests/run.php    # URL normalization, hash identity, HMAC signing
composer test        # same

find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
```

The test runner needs no WordPress: `tests/bootstrap.php` shims the handful of core
functions the pure-logic classes touch.

### Local environment

`docker compose` brings up two WordPress instances — the site under test and a stand-in
"owned network site" to ingest from — on deliberately **different host strings**, because
items whose host matches the local site are discarded as self-links and a shared hostname
would silently ingest nothing.

```bash
bin/dev up          # start containers, install both sites, activate the plugin
bin/dev seed        # 5 posts on the source site, 4 with a featured image
bin/dev configure   # register sources, place the shortcode on the front page
bin/dev ingest      # synchronous cycle
bin/dev verify      # assert real links render
bin/dev status      # diagnostics JSON
bin/dev wp <cmd>    # wp-cli against the site under test
bin/dev src <cmd>   # wp-cli against the source site
bin/dev logs
bin/dev reset       # destroy containers and volumes
```

| | |
|---|---|
| Site under test | <http://localhost:8080> — `admin` / `admin` |
| Source site | <http://127.0.0.1:8081> — `admin` / `admin` |

Copy `.env.example` to `.env` and fill in `SERPAPI_KEY` / `GITHUB_TOKEN`. Docker Compose
loads that file automatically and passes both through as the constants above, so local
testing uses real credentials without ever writing them to the database. `.env` is
gitignored.

Stack notes:

- Apache also listens on **8080 inside** the container, because the site's `home_url` is
  `http://localhost:8080` and WordPress loopback requests — which Action Scheduler
  depends on — target that port from within the container.
- `wp-config.php` reads `WORDPRESS_CONFIG_EXTRA` through `getenv()` per request, so the
  wp-cli services share the web container's environment block. Without that, constants
  defined there go silently missing in CLI runs.
- `ADVTN_ALLOW_LOCAL_URLS` is defined in the container only. It relaxes
  `wp_http_validate_url()`, which otherwise rejects loopback and private hosts outright.
  **Never define it in production.**
- A dev-only mu-plugin allowlists the container hosts for `fetch_feed()`, which goes
  through `wp_safe_remote_get()` and enforces that rejection independently.

Architecture, invariants and the list of rejected approaches are in [CLAUDE.md](CLAUDE.md).
The authoritative specification is [docs/trending-now-plugin-spec.md](docs/trending-now-plugin-spec.md).

## Troubleshooting

**"I ran an ingest and nothing changed."** Check Diagnostics → last completed cycle and
the lock. A *scheduled* cycle changes nothing until its finalize action runs. Use **Run
ingest now**, which does the whole thing inline.

**Fewer links than `widget_limit`.** You may simply not have that many items yet — check
the item counts. Otherwise `news_share_pct` reserving slots with no news source configured,
or all items stale.

**A source shows an error and stops being tried.** That is the backoff: `consec_fails`
raises `backoff_until` by `source_fail_backoff × min(fails, 6)`. **Run ingest now**
ignores backoff and retries immediately. To retry just that one source instead of every
enabled one, use its row's own **Ingest now** button on the Sources tab.

**A source times out intermittently.** `cURL error 28` names the ceiling it hit — "timed
out after 5001 milliseconds" is the 5-second `http_timeout` default, not a network fault.
Open that source's **Recent attempts** on the Sources tab: if p50 has been climbing toward
the timeout, raise that row's **Timeout** rather than the global, **save the form**, and
only then press **Ingest now** — it runs against the saved row, so retrying before saving
repeats the fetch at the old timeout. SerpAPI does a live scrape and can exceed 5 seconds
under load; 20–30 is a reasonable value for it. Owned `wp_rest` sources should keep failing
fast.

**The widget and the archive show different sets.** Almost always a full-page cache
holding two snapshots taken at different times — the homepage cached after an ingest
cycle, the archive before it. The plugin purges WP Rocket, LiteSpeed, W3 Total Cache,
WP Super Cache, WP Fastest Cache, Cachify, SG Optimizer, Nginx Helper, Comet Cache,
Breeze and Autoptimize when the list changes; Diagnostics reports which it can see, and
`advtn_purge_page_cache` covers anything else, including a CDN. Also worth checking: the
archive lists **everything retained**, fifty per page, so a widget item can legitimately
sit several pages in — the widget is a curated selection, not the first N of the archive.

**A slow or failing source starves the others.** *Run ingest now* works to a
`batch_time_budget` (20s by default) and queues whatever it cannot reach. One source
timing out at 12s will push the rest into the background queue, where they wait on cron.
Fix or disable the failing source rather than raising the budget.

**`401` from the REST API.** Clock skew over 300 seconds, a replayed signature, or the
message was built wrong — it is `timestamp + "\n" + raw body`, with an *empty* body for
GET.

**SerpAPI says the account is out of searches.** That is credit exhaustion, not a
transient error — it will not clear on its own. Top up or upgrade at serpapi.com, or
disable the source. **Diagnostics → Check SerpAPI credits** shows the remaining balance
without spending one.

**Action Scheduler shows nothing pending and nothing runs.** Check the loopback test. HTTP
auth, an aggressive WAF or firewalled self-requests all break it, and its queue runner
cannot work without it.

**Widget vanished after a settings change.** `class_prefix` changes every class name in
the markup. Check what you are grepping for.

## What this deliberately does not do

Each of these was considered and rejected. Please do not add them.

| Rejected | Reason |
|---|---|
| GDELT as a news source | Shipped in 1.0.0, then removed: 10–20s per request and a rate limit of one request per five seconds, with a penalty that outlasts it and misleading error text while throttled. |
| Google Indexing API submission | Only supports `JobPosting` and `BroadcastEvent`. Using it for articles violates the ToS and is a known manual-action trigger. |
| Client-side / AJAX rendering | Defeats the entire purpose. JS-injected links are crawled far less reliably. |
| Custom post type for links | Bloats `wp_posts`, leaks into core sitemaps and search, and no editorial UI is needed. |
| Elementor / Bricks native widgets | Both accept shortcodes. Not worth the maintenance surface. |
| Google News RSS as a source | Returns `news.google.com` redirect URLs, not publisher URLs, and resists server-side resolution. |
| Fetching a feed during a pageview | Ever. Under any condition, including a cache miss. |
| Requiring WP-CLI | Not available on all target hosts. |

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
