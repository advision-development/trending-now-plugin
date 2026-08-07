# Changelog

All notable changes to this project are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **Manual links.** A new admin tab holding a hand-curated list that mixes into the same
  widget as ingested links, with the same item fields plus a **position** (1-based slot,
  `0` for no preference, out-of-range clamped) and an **expiry** with quick presets and a
  live time-remaining readout. Curated links are stored in the items table through a
  synthetic source, so they deduplicate against anything a source also finds, appear in
  the archive, and keep the same display counters; they reserve their slots before the
  tiers run. Links back to the local site are permitted here — the self-link rule exists
  to catch a source echoing your own content, which is not the same as choosing one by
  hand. Expiry marks the row `stale` rather than deleting it, and schedules an immediate
  rebuild so a timer does not wait up to a full ingest interval to take effect.

- **Google News source via SerpAPI** (`serpapi`), alongside the existing GDELT provider:
  query, optional domain allowlist, country and language, with the key held once rather
  than per source. Grouped topic results are flattened, and the publisher name comes from
  the result rather than being inferred from the host. Verified against the live API: real
  publisher URLs, dates parsed to UTC, thumbnails present, no `excerpt` (Google News does
  not return one) and the allowlist dropping 19 of 29 results with no leaks.
- **Credentials can come from `wp-config.php` constants** — `ADVTN_SERPAPI_KEY` and
  `ADVTN_GITHUB_TOKEN` — which keeps them out of the database and out of any settings
  export. The stored option remains the fallback, and the admin field is disabled with a
  note when a constant is in force, so an empty box is never mistaken for an unset key.
- **Credit exhaustion is handled as its own failure.** SerpAPI reports it as a plain
  message that reads much like a rate limit, but one needs a human to top up and the
  other clears itself, so they are classified separately and worded differently.
  Diagnostics shows the remaining balance, checked on demand so viewing the panel never
  spends a credit.
- **Self-update from GitHub releases.** The plugin declares an `Update URI`, so
  WordPress routes update checks to the repository instead of wordpress.org — which also
  prevents an unrelated plugin sharing the slug from being installed over it. Prefers the
  attached release zip, falls back to the generated zipball with the directory renamed,
  and supports a token for private repositories. Verified end to end: a 0.9.0 install
  updated itself to the published 1.0.0.
- `advtn_news_source_types` filter. Which source types count as news now lives in one
  place and drives the news/network slot split, the `rel` attribute on external links and
  the `--news` template modifier — previously all three hardcoded `gdelt`.

### Removed

- **The GDELT news source.** Free, but it answered in 10–20 seconds against a rate limit
  of roughly one request every five seconds whose penalty outlasted its own window, and
  while throttled it returned misleading errors — a bare, valid `domain:espn.com` came
  back as "The specified domain is too short or too long." In practice a failing GDELT
  source also consumed most of the inline ingest budget, pushing healthy sources into the
  background queue. SerpAPI covers the same need in a couple of seconds.

  `gdelt` source rows are dropped on upgrade (DB version 2) so they cannot sit there
  failing every cycle. Items already ingested are kept, remain classified as news — the
  type stays in `news_types()` for exactly that reason — and age out through the normal
  stale and retention rules.

### Fixed

- **SerpAPI rejected every query.** The request sent `so` (sort order) alongside `q`,
  which the API refuses outright — sort only applies to topic and section browsing, not
  free-text search. Caught on the first live call, and only because the new error
  surfacing reported what the API actually said.
- **GDELT requests failed at 10 seconds regardless of `http_timeout`.** WordPress passes
  `timeout` through to Requests but never sets `connect_timeout`, which Requests
  hardcodes to 10 seconds and which covers the TLS handshake. GDELT throttles by
  stalling that handshake — measured at ~12s — so every request died as
  `cURL error 28: Connection timed out` with no indication that the real cause was rate
  limiting. Outbound requests now raise the connect ceiling to match `http_timeout`.
- **GDELT's own error text was discarded.** It reports several failures as plain text
  with an HTTP 200 — "Your query was too short or too long.", "The specified domain is
  too short or too long.", the rate-limit notice — and all of them collapsed into a
  generic "Malformed JSON" or "not JSON" message. The response body is now surfaced in
  the error and the log.
- HTTP 429 from GDELT is called out explicitly, with the one-request-per-five-seconds
  rule and a warning that the penalty outlasts that window.

### Changed

- `http_timeout` ceiling raised from 30 to 60 seconds, with help text noting that a GDELT
  source needs roughly 30.
- Consecutive GDELT requests within one run are spaced at least five seconds apart, so
  two GDELT sources cannot trip the limit on each other.

## [1.0.0] — 2026-08-06

First release. Implements `docs/trending-now-plugin-spec.md` in full — Phase 1 plus the
Phase 2 hub/spoke work.

### Added

**Ingestion**

- Four source providers behind `ADVTN_Source_Interface`: `wp_rest`, `rss`, `gdelt` and
  `hub`, sharing `ADVTN_Source_Base` for HTTP and item normalization.
- URL normalization defines dedupe identity — tracking parameters, `www`, trailing
  slashes, scheme and fragments all collapse to one `sha1` hash.
- Single custom table `{prefix}advtn_items` behind `ADVTN_Repository`; upserts preserve
  display history, so re-ingesting never resets counters.
- Per-source failure backoff, isolated failures, and item rejection for invalid URLs,
  empty titles and links back to the site's own host.
- GDELT hardening: domain allowlist re-applied server-side, malformed JSON and HTML
  error pages served with `200` both handled cleanly.

**Scheduling**

- Action Scheduler orchestration with an hourly due-check, atomic `add_option()` lock,
  per-source stagger and a finalize step. Falls back to WP-Cron when the Composer
  dependency is absent.
- HMAC-signed `POST /wp-json/advtn/v1/ingest` as the deterministic trigger, with clock
  skew, replay and rate-limit protection.

**Selection**

- Three-tier rotation: pinned within the exposure floor, then never-shown, then
  least-shown, with a news share and a per-source cap.
- Host-adjacency spacing so no more than two consecutive links share a host.

**Rendering**

- Server-rendered only, from a no-expiry option-backed cache busted solely by finalize.
  A warm request costs one query and zero outbound HTTP.
- Shortcode, Gutenberg block and template tags over one renderer; theme-overridable
  templates; per-site configurable class prefix with an inlined stylesheet.
- Paginated `/trending/` archive with canonical, `rel="prev"`/`rel="next"`, robots
  control and core sitemap registration while indexable.

**Operations**

- Admin with settings, sources (live per-row test fetch, drag-to-reorder, JSON
  import/export) and a diagnostics panel covering source state, queued actions, item
  counts, the live selection, cache, lock, loopback and a ring-buffer log.
- Stored-items browser with filtered, selected, single and full deletion.
- Optional WP-CLI commands; nothing depends on their availability.
- Docker environment (`docker compose` + `bin/dev`) running two WordPress instances so
  `wp_rest` ingestion can be exercised end to end.

### Fixed

Found by running the plugin against real WordPress rather than by inspection.

- **Featured images never resolved on `wp_rest` sources.** The REST server builds
  `_embedded` by walking `$data['_links']`, and `_fields` strips `_links` out of `$data`
  before that runs — so requesting `_embedded` alone returns neither. Both are now
  requested.
- **The archive logged a PHP deprecation on every request.** `get_header()` and
  `get_footer()` fall back to theme-compat shims on block themes, which are the modern
  default. The archive now emits its own document via `block_header_area()` and
  `block_footer_area()` when appropriate.
- **The widget under-filled its slots.** Pinned items consume a source's quota while
  themselves bypassing the cap, so a source whose older items were all inside the
  exposure floor had nothing left for anything newer. Selection now makes a final pass
  with the cap lifted, per the spec's requirement never to render fewer items than are
  available.
- **`$wpdb->prepare()` coerces `null` to `''`**, which would have written empty strings
  into nullable datetime and URL columns. Nullable columns now emit a literal `NULL`.
- **Unchecked settings checkboxes could never be turned off**, because an absent value
  merged over the stored `true`.
- **GDELT and source-type fields stayed visible** when they did not apply:
  `.advtn-source__grid label { display: flex }` outranks the user-agent `[hidden]` rule.
- **"Run ingest now" appeared to do nothing.** It queued a staggered cycle, so nothing
  changed until finalize ran — minutes later, and only if the queue advanced at all. It
  now runs inline within the batch time budget and always finalizes.

### Security

- Every query prepared, including dynamically built `IN (…)` lists; all SQL confined to
  `ADVTN_Repository`.
- Output escaped at the point of output; admin forms nonce- and capability-checked.
- Source URLs validated on save and again immediately before every fetch, with private
  and loopback ranges rejected unless a constant explicitly permits them.
- `hash_equals()` for every signature comparison; secrets never written to the log.
- `uninstall.php` guards on `WP_UNINSTALL_PLUGIN` and only destroys data when
  `delete_data_on_uninstall` is set.

### Known gaps

- Elementor and Bricks rendering is untested against those plugins; the shortcode path
  they both use is exercised.
- Hub/spoke has not been run end to end between two installs. The `/items` endpoint's
  mode gating and auth are tested.
- No W3C validation run. The inlined `<style>` sits in the body by design and will be
  flagged; see the README for how to move it.
- GDELT's malformed-JSON branch is covered by code inspection only — a malformed
  response could not be forced on demand.

[1.0.0]: https://github.com/advision-development/trending-now-plugin/releases/tag/v1.0.0
