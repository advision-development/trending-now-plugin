# Changelog

All notable changes to this project are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.3.0] — 2026-09-01

### Security

- **The updater installed whatever URL the release response named.** `resolve_package()`
  took `browser_download_url` out of GitHub's JSON and handed it to WordPress, which
  downloads it, unzips it over the plugin directory and runs it on the next request — and
  when no asset matched it fell back to `zipball_url`, also verbatim. Nothing checked the
  host, the owner or the repository.

  The download URL is now checked against a prefix compiled into the plugin,
  `https://github.com/advision-development/trending-now-plugin/releases/download/`, and
  refused if it contains `..`. The second check is not belt-and-braces: HTTP clients
  resolve dot segments out of a path before sending the request (RFC 3986), so
  `…/releases/download/../../../../someone/their-repo/…` starts with that prefix, passes,
  and downloads from another account — still on `github.com`, still answered `200`, and not
  this plugin. The asset's **name** has to match `trending-now-*.zip` as well, so a release
  carrying several files cannot have one of the others installed as the plugin.

  With a GitHub token the asset still goes through `api.github.com`, because the storage
  redirect rejects an `Authorization` header. That URL is built here from the same pinned
  prefix and an integer id, so it is safe by construction and deliberately not
  prefix-checked afterwards — a guard no input can reach is a guard no test can hold in
  place.

### Added

- **The plugin keeps itself updated.** `auto_update_plugin` is answered for this plugin
  only, so a release installs itself on WordPress's next update run instead of waiting for
  somebody to open each site's Plugins screen. A network running several versions of this
  plugin serves several different widgets, which is the reason.

  **The way out is a filter**, `advtn_auto_update`, so a site that must not take unattended
  updates can refuse from its own mu-plugin. The *Keep this plugin updated* setting is the
  site-level switch and turns off checking entirely.

  Worth stating plainly: every other check here assumes the danger is a tampered answer, and
  none of them help if a release is genuinely published from the pinned repository by
  somebody who should not have been able to publish it. Unattended updates turn that from
  "every site whose operator clicked" into "every site". The mitigation is the release
  account, not this code.

- **The Plugins screen says what the last check knew.** WordPress's auto-update toggle is
  replaced with a sentence, the state of the last check, and a *Check for a new release now*
  link. The toggle is replaced rather than left in place because it could be switched off
  and change nothing — a control that looks like it works and does not.


- **A site now says which site it is when it fetches the feed.** Two optional query
  parameters go out with the request the plugin already makes every few hours:

  ```
  GET <feed url>&site=<home_url()>&v=<plugin version>
  ```

  There is no new endpoint and no handshake. A feed that does not know these parameters
  serves exactly what it served before, so this needs no coordinated deploy — and the
  central console can finally tell one subscriber from another. Until now the only thing
  distinguishing them was an IP address, and an IP is not a site: measured across the
  network on 2026-09-01, 144 hostnames resolve to 150 addresses with one shared server
  holding seven of them.

  **`site` is `home_url()` and never a field somebody types.** A typed field is a field
  filled in wrong, and the far end turns this value into an address it will later contact —
  so a mistake there is a request aimed at somebody else's site. `home_url()` is the value
  WordPress already uses to build every link it prints.

  Empty values are omitted rather than sent blank: a parameter present and empty is a claim
  that this site has no address, where absent is the truthful "this plugin did not say".

### Fixed

- **A failed check is now remembered for an hour.** Every failure returned before the
  transient was written, so a site whose check failed asked GitHub again on the next check.
  GitHub allows 60 unauthenticated requests an hour **per IP** and a hosting provider's
  sites share one, which is how one rate-limited site is what keeps it rate-limited. The
  reason is stored with it and printed, because a check that silently found nothing is
  indistinguishable from one that never ran.

- **Diagnostics no longer fetches while rendering.** The Latest release row called
  `latest_release()`, so every load of that tab was another request to the API. It reads
  `status()` now, which never leaves the site.

- **An up-to-date plugin reports itself instead of staying silent.** `check_for_update()`
  answered `false` when no update was available, which puts the plugin in neither
  `$updates->response` nor `$updates->no_update` — and WordPress reads `no_update` to decide
  whether a row offers automatic updates at all, which is where the state and the re-check
  link are printed. `wp_update_plugins()` compares the versions and routes the answer
  itself, so a known release is now always reported. It cannot cause a downgrade: a release
  behind the installed copy fails that comparison and lands in `no_update`.

- **Versions are padded to three components before being compared.**
  `version_compare( '1.2', '1.2.0' )` reports less-than, so an unpadded comparison against a
  two-component header cleared a site that had an update waiting. A tag is also validated
  rather than trimmed — `ltrim( $tag, 'vV' )` let a tag naming a branch through as though it
  were a version.

- **There is no zipball fallback.** GitHub's generated archive is the development tree with
  no `vendor/`, so it installed a plugin whose Action Scheduler was absent and which
  degraded to WP-Cron silently. No recognised asset now means no update offered.

### Notes for anybody debugging this later

- **The asset name and the repository name are different strings**, and both are pinned.
  `bin/release` builds `trending-now-<version>.zip` while the repository is
  `trending-now-plugin`. The sibling scanner plugins use one constant for both because in
  them the two happen to match; copying that check here would refuse every legitimate
  release.

- **The release cache is a blog transient, not a site transient.** The scanners use site
  transients and record that uninstall then has to delete them as such. These are separate
  single-site installs rather than a network, so the two are equivalent here — and
  `uninstall.php` clears `_transient_advtn_%` through `$wpdb`, which a change of scope would
  quietly step around.

- **Only the origin survives on the other side.** The path is discarded there, so a
  subdirectory install sends its full home and loses the directory. Recorded rather than
  worked around: no install in the network is in a subdirectory, measured rather than
  assumed.

- `ADVTN_Manual_Feed::identity()` is pure and static so the decision is testable without
  WordPress. The URL is assembled by `add_query_arg()`, which is core's job — the parameters
  are what this plugin decides, and a stub of core's URL builder could differ from it while
  the test still passed.

## [1.2.0] — 2026-08-25

### Added

- **A site can subscribe its curated links to a feed.** Instead of the same links being
  typed into every site in a network, one list is maintained centrally and each site pulls
  it on its own schedule. While subscribed, **Manual links** becomes a read-only mirror:
  the rows render disabled and every fetch replaces them. Unticking *Subscribed* leaves the
  links in place and editable again — turning off a sync must not delete content, and
  nothing changes on the front end at that moment.

  Four settings — `manual_feed_url`, `manual_feed_token`, `manual_feed_interval_hours`
  (default 6) and `manual_feed_enabled`. The token is optional: a public feed needs none,
  and the `Authorization` header is then omitted entirely rather than sent empty.

- **`POST /wp-json/advtn/v1/feed-fetch`**, signed with the same secret as `/ingest`, and
  `wp trending-now feed-fetch [--force]`. The local timer is the mechanism; this is the
  escape hatch for a site quiet enough that WP-Cron rarely fires.

### Notes for anybody debugging this later

- **A response is judged by its body, never its status code.** A feed served from a
  single-page app's host answers `200` with HTML for any unrecognised path, so one letter
  wrong in the URL looks exactly like success — on every subscribed site, for as long as
  nobody checks. A payload counts only if it carries both a `feed` object and an `items`
  list.

- **A failed fetch changes nothing**, verified byte-for-byte. This runs unattended on sites
  nobody is watching, so a feed answering badly must cost them nothing.

- **A `401` does not mean the token is wrong.** A feed answers `401` both for a gated feed
  and for one that does not exist, identically and on purpose, so that nobody can discover
  which feeds exist by guessing names. It is the one refusal this plugin cannot diagnose,
  and the message says so rather than sending somebody to check the field most likely to be
  correct.

- **`--force` skips the stored ETag as well as the interval**, and that half is not a
  convenience. `If-None-Match` is the site claiming "I already hold version N" — precisely
  what somebody forcing a fetch has stopped believing. Found by wiping one site's links and
  forcing a fetch: the feed answered `304`, the plugin reported *"The feed has not changed
  since the last fetch"*, and the empty site stayed empty. Recovery would have had to wait
  for an unrelated edit upstream to bump the version, on every site at once, with nothing on
  screen explaining the wait.

### Fixed

- **The local development stack could not start from a clean checkout.** `.gitignore`'s
  `*.sql` excluded `.docker/init-db.sql`, which `docker-compose.yml` mounts — so Docker
  created a *directory* at the mount point and MariaDB died with `Can't read from a
  directory 'stdin'`, an error naming neither the file nor the reason.

## [1.1.7] — 2026-08-10

### Added

- **`match_path` (shortcode) and `matchPath` (block) limit a placement to specific paths.**
  Some themes will not render a shortcode on the homepage at all, so the widget goes into a
  theme widget area instead — and a widget area is site-wide, so the same placement then
  renders on every page, not just the one it was meant for. The new attribute takes a
  comma-separated list of paths (`/,/trending` for the homepage plus the archive at its
  default slug); an empty or absent value renders everywhere, which is exactly what every
  install that predates this attribute already does.

  Matching, in the new `ADVTN_Path_Match`, is **exact**, not prefix: `/archive` matches
  `/archive` and `/archive/` but not `/archive/page/2/` or `/archive-2024`. A prefix match
  was rejected because one list entry would then silently swallow an entire section; a
  trailing `*` is the additive way in if section-wide matching is ever wanted. The shortcode
  accepts both `match_path` and `matchpath`, because `shortcode_parse_atts()` lowercases the
  attribute name while parsing the tag, before `shortcode_atts()` ever gets to merge it
  against defaults — a `match_path`-only implementation would have silently dropped the
  documented `matchPath` spelling the moment anyone typed it with a capital P, since
  `shortcode_atts()` would receive `matchpath` and find no default under that key.

  Both sides of the comparison go through `ADVTN_Path_Match::normalize()`: one leading slash,
  no trailing slash, lowercase, no query string or fragment, repeated slashes collapsed,
  percent-encoding decoded and whitespace trimmed *after* decoding, so an encoded trailing
  space cannot survive to defeat the trailing-slash rule. An entry written as a full URL keeps
  only its path — `https://example.com/trending` means `/trending`, rather than matching
  nothing on every page and looking exactly like a broken plugin — while protocol-relative
  input stays a path, because telling a host from a doubled slash means guessing and a doubled
  slash is the likelier typo in a list of paths.

  The gate lives in `ADVTN_Shortcode` and `ADVTN_Block`, not in `ADVTN_Renderer`: `render()`
  keys its cache on the args hash, and a path list folded into `$args` would mint one cached
  copy of identical HTML per distinct list, for no benefit. A placement whose path does not
  match costs nothing beyond that check either — it returns `''` before the renderer is ever
  called, so a non-matching placement adds no render-cache variant.

  The block's editor preview bypasses the gate, because `wp-server-side-render` calls the
  render callback over REST carrying the *editor's* own URL, not the reader's — gating there
  would render the block blank while it is being edited and read as broken. `is_admin()`
  separately covers admin-rendered contexts such as the Widgets screen, where `REST_REQUEST`
  is never set.

  Neither flag identifies an editor by itself, so the bypass requires
  `current_user_can( 'edit_posts' )` alongside **both** of them. `REST_REQUEST` stays true for
  the life of any `/wp-json/*` request, not just the editor's preview call: an anonymous
  `GET /wp/v2/pages/<id>` also qualifies, and would otherwise hand back that page's rendered
  block regardless of `matchPath`. `is_admin()` has the identical hole — `WP_ADMIN` is defined
  for `wp-admin/admin-ajax.php`, which dispatches `wp_ajax_nopriv_*` actions to logged-out
  visitors, so a theme that renders post content over admin-ajax (infinite scroll, "load
  more", AJAX page transitions) would bypass the gate for anonymous readers. The capability
  check costs a genuine preview nothing, because the block-renderer endpoint the editor's
  preview calls already enforces it in its own permission callback, and nothing in core
  renders this block under `is_admin()` for a user who cannot `edit_posts` — the block widgets
  screen and the site editor both preview over REST. The two flag checks are evaluated first,
  so a front-end pageview never reaches `current_user_can()` and never loads a user to find
  out it is not editing. One accepted gap: a logged-in editor browsing the public REST API
  still bypasses the gate, for content they can already edit.

## [1.1.6] — 2026-08-08

### Fixed

- **The archive paginated over a set it was no longer showing.** 1.1.5 made
  `max_age_hours` bound `/trending/`, but the archive's item count is cached in a
  transient for 15 minutes and nothing invalidated it when the underlying set changed. A
  site with 2,500 retained rows and 350 inside the window offered 50 pages of which 7 had
  anything on them, and the pages past the seventh returned `200` with an empty list —
  `prepare_response()` could not redirect them, because it decides what is out of range by
  reading the same stale number.

  `ADVTN_Ingest::finalize()` now drops the count. It already marks rows stale, prunes them
  and purges both the render cache and the host page cache; the count is derived from the
  same rows and had simply been left out. That omission outlived its own TTL, because the
  page cache purge re-primes the archive HTML on the next request and bakes whatever count
  is current into pages that then live for the page cache's lifetime.

  Two further guards, because a 15-minute cache over a window that moves every second will
  always be slightly wrong at the edges: a page past the first that resolves to no rows now
  redirects to page 1 rather than serving an empty page `200`, and the upgrade to database
  version 3 clears any count cached by an earlier version instead of leaving it for the
  first visitor to trip over.

  `ADVTN_Archive::current_items()` is memoized so the new emptiness check costs no extra
  query — an archive pageview still issues exactly one.

## [1.1.5] — 2026-08-08

### Changed

- **`max_age_hours` now bounds the archive as well as the widget, and defaults to 72.**
  It previously defaulted to `0` — no cutoff at all — and applied only to widget
  selection, so `/trending/` listed everything retained: on a 90-day retention that is a
  three-month wall of links, most of them long past the window the plugin exists to
  provide. An article published four days ago was on the widget yesterday and gone today,
  but sat on the archive for another twelve weeks. Both surfaces now use the same clause,
  including its two exemptions — curated links keep their own expiry, and a row with no
  publish date cannot be judged against a cutoff so it is dropped.

  Rows outside the window stay in the table until `retention_days` prunes them, so they
  still deduplicate against anything a source finds again. Retention now bounds the table;
  `max_age_hours` bounds what is displayed.

  **This changes the shipped default, not existing installs.** Stored settings win over
  defaults, so a site that already has a value keeps it — check Settings → Maximum age if
  you want the new behaviour on an install that predates this release.

- **`exposure_floor_days` defaults to 2, down from 3.** The floor counts from
  `first_shown_at` and the cutoff from `published_at`, so an equal pair only looks safe:
  an item ingested six hours after publication has its guaranteed run cut six hours short.
  Two days against a 72-hour cutoff leaves a day of slack for ingest lag. The Settings
  screen's mismatch warning now fires when the floor meets *or* exceeds the cutoff — it
  previously required strictly greater, so the exactly-equal case that breaks passed
  silently.

### Added

- **Per-source HTTP timeout, a 20-entry attempt history, and a manual single-source retry.**
  Built after a live incident: a SerpAPI source fetched fine all afternoon, then failed
  overnight with `cURL error 28: Operation timed out after 5001 milliseconds`. Nothing was
  broken — `http_timeout` defaults to 5 seconds and SerpAPI's endpoint does a live scrape
  whose latency varies — but there was no history showing the drift beforehand, and no way
  to retry that one source short of waiting for the next scheduled cycle or its failure
  backoff to expire.

  Each source row now has its own **Timeout**: blank or `0` inherits the global
  `http_timeout`, otherwise it is clamped to 1–120 seconds by the single
  `ADVTN_Source_Base::config_timeout()` every provider calls. The override changes the
  *request* timeout only: `http_get()` still computes the TLS connect ceiling from the
  global before a per-call override can win, so a provider given a longer timeout still
  fails fast on a stalled handshake rather than a slow response.

  Every fetch, success or failure, now appends to a 20-entry ring kept per source
  (`ADVTN_Attempts`), surfaced on the Sources tab as **Recent attempts**: timestamp,
  ok/fail, duration, HTTP code and error, newest first, plus a summary of count, median and
  max duration next to the timeout currently in force. Error messages are truncated to 120
  characters at write time, not read time — an untruncated cURL message can carry a long
  URL, and twenty of those per source is what turns a diagnostic aid into a bloated option.
  Both the success and failure paths funnel through the same recorder so the cap and the
  truncation cannot drift apart between them. Source success and failure log entries also
  now record the timeout that was in force at fetch time; resolving it means asking the
  provider for its `config_timeout()`, which is wrapped in the same defensive
  `try/catch(\Throwable)` as everything else that touches a third-party provider, so a
  throwing constructor registered through `advtn_source_map` can take down neither a log
  line nor the Sources tab.

  Each enabled row also gets an **Ingest now** button beside **Test fetch**. Where Test
  fetch shows what would be ingested and writes nothing, Ingest now calls
  `ADVTN_Ingest::run_source()` directly, which bypasses that source's failure backoff by
  construction — the backoff check lives in `run()`'s scheduling loop, not in
  `run_source()` — writes the result, and finalizes whether or not the fetch succeeded:
  rebuilding the selection and purging both the render cache and the host's page cache,
  exactly like a full cycle would. A single-source retry is therefore not a free action on
  a site sitting behind a page cache.

  It does **not** stamp `advtn_last_ingest`. `ADVTN_Ingest::finalize()` takes a
  `bool $complete_cycle = true` parameter gating that one write, and the button's handler
  passes `false`; every other caller takes the default. Refreshing one source is not a
  completed cycle, and three things read that option — the due-check that defers the next
  scheduled cycle and the `{"force":false}` external trigger, the admin's 30-hour red
  banner, and `last_ingest` in `GET /status`. Stamping it from a one-source retry would
  make the button an operator presses *because* a source is failing the very thing that
  hides ingestion having stopped, for up to `ingest_interval_hours`.

  The button is hidden on a disabled row — `source_config()` resolves any configured row,
  so it would otherwise write a switched-off source's items into the live selection — and
  it runs against the **saved** row rather than what is on screen, the opposite of Test
  fetch. The row hint says so, and because the control is a link inside the sources form,
  the admin JS now confirms before navigating away from unsaved edits rather than
  discarding a timeout you have just typed.

### Fixed

- **`http_timeout` never applied to `rss` sources.** `fetch_feed()` builds its own SimplePie
  instance and WordPress never wires the setting to it, so an RSS source has always run on
  SimplePie's own default timeout, not `http_timeout`, no matter what it was set to. Fixed
  through the only hook that reaches the SimplePie object before it fetches,
  `wp_feed_options`, added and removed around the call the same way the cache-lifetime
  filter beside it already was.

## [1.1.4] — 2026-08-07

### Changed

- **The minimum PHP version is now 7.4**, down from 8.1. Only two things actually
  required 8.x — two `str_ends_with()` calls, in the release-asset check and the SerpAPI
  domain allowlist — and both are plain `substr()` comparisons now. Nothing else in the
  codebase used 8.0+ syntax: typed properties and arrow functions, the two features it
  leans on hardest, both landed in 7.4.

  Verified by running the plugin on PHP 7.4.33 with current WordPress, not by inspection:
  activation, schema, a live SerpAPI fetch through both the top-stories and allowlist
  paths, `wp_rest` against real sources, curated links, selection, all three layouts, the
  archive, the updater and the page-cache purge — with an empty error log throughout.

  PHP 7.4 has been end-of-life since November 2022. This is for legacy installs, not a
  recommendation.

## [1.1.3] — 2026-08-07

### Added

- **Page cache purging.** The plugin busted its own render cache at the end of each cycle
  but never told the host's full-page cache, so the cached HTML kept whatever it had
  captured. On a live site this showed up as the widget and the archive disagreeing —
  two snapshots taken nearly two hours apart, which reads as the plugin selecting
  inconsistently. WP Rocket, LiteSpeed, W3 Total Cache, WP Super Cache, WP Fastest Cache,
  Cachify, SG Optimizer, Nginx Helper, Comet Cache, Breeze and Autoptimize are purged when
  the list changes; `advtn_purge_page_cache` covers anything else. Every integration is
  guarded and failures are swallowed, since a cache that will not clear must not take down
  an ingest cycle.
- **`show_excerpt`** on the shortcode, the block and the display settings, available in
  all three layouts and on the archive. Off by default: plenty of sources return no
  excerpt at all, so switching it on globally would give a ragged mix of items with and
  without. `show_icons` is now exposed on the shortcode and block too, which it should
  have been when it was added.

## [1.1.2] — 2026-08-07

### Fixed

- **The widget's own styles lost to the host theme.** Two separate causes, both visible on
  a live site as bullet points reappearing and the thumbnail dropping below the headline
  instead of sitting beside it.

  `list-style: none` was set on the `<ul>` only. That value *inherits* to each `<li>`, and
  an inherited value loses to **any** direct declaration on the element — even a generic
  `li { list-style: disc }` at specificity (0,0,1). It is now set on the list items
  themselves, with `::marker { content: none }` behind it.

  The structural rules were also written at specificity (0,2,0), which a routine theme
  selector such as `.entry-content ul li` (0,2,1) outranks. Selectors are now compounded
  on the root class, and `display`, `flex-wrap`, `list-style`, `margin` and `padding` are
  marked important — this markup is dropped into arbitrary themes and has to win.

## [1.1.1] — 2026-08-07

### Fixed

- **Relative timestamps were frozen at build time.** The label was baked into the cached
  HTML, and the cache is only busted once per ingest cycle — so an article rendered at
  `42m` still claimed `42m` up to twenty hours later. Labels are now recalculated on every
  request from the `datetime` attribute already in the markup, which costs no database
  access and keeps the cached blob valid. The rewrite runs whichever style is configured,
  so changing `date_style` takes effect immediately rather than waiting for a purge.
- **The news layout put the thumbnail below the headline instead of beside it.** The base
  `__item` rule sets `flex-wrap: wrap` for the list layout, and the news rule never reset
  it — so with `flex-basis: auto` a long headline claimed the whole line and pushed the
  image onto the next one. The body is now `flex: 1` (basis 0) and the row explicitly
  `flex-wrap: nowrap`.
- **The archive looked nothing like the widget.** It now uses the same card markup,
  classes and display settings, rather than its own unstyled list.
- **The newest articles were rendered last.** The final list was sorted by selection tier
  before publication date, so anything already shown (tier 1, held by the exposure floor)
  outranked anything brand new (tier 2) — on a 12-slot widget the two freshest stories sat
  at positions 11 and 12, below items a day older. Tiers decide which items are selected;
  they no longer decide the order they appear in, which is now simply newest first.
  Curated links still hold their configured slots.

### Added

- **Maximum age cutoff** (`max_age_hours`). Hides anything published longer ago than the
  configured window — 48 for a two-day feed, 0 to disable. Applied before the selection
  tiers, so it outranks the exposure floor; the admin warns when a floor is set longer
  than the cutoff, because that combination promises a run the cutoff will not let
  finish. Curated links are exempt, since they carry their own expiry.
- **`news` is now the default layout**, with thumbnails on — a bare `[trending_now]`
  renders the card style with the image pinned right, rather than a plain text list.
  Sites that never chose a layout pick this up on upgrade; anything set explicitly, in
  settings or per shortcode, is untouched.
- **`news` layout** — source name, relative timestamp, headline and a right-hand
  thumbnail, after the Google News and MSN feed cards. Thumbnails carry fixed dimensions
  so they reserve their space rather than shifting the layout as they load, everything
  below the first card is lazy-loaded, and the first is fetched eagerly because it is
  usually the one above the fold.
- **Timestamp style toggle** (`date_style`). `relative` shows `45m` or `6h` inside the
  last day and a date beyond it; `date` always shows the date. Relative stamps read as
  live, but on a once-a-day cycle every item drifts towards `20h` together, which
  advertises the batch update — the toggle is there to trade one against the other.
- **Site icons** beside source names (`show_icons`, off by default). The URL is derived
  from the stored host rather than fetched during ingest — a news API's own icon field is
  itself just a favicon-service URL built from the domain, so deriving it needs no schema
  change and covers network sources too. Loaded by the visitor's browser, lazily and at a
  fixed 16px, so the render-time no-HTTP rule is unaffected; it does expose visitors to a
  third-party favicon service, hence the default and the `advtn_source_icon_url` filter.
- **Relative timestamps.** Under an hour reads `45m`, under a day `6h`, and anything
  older keeps its date. The `datetime` attribute stays a full ISO timestamp.
- **Display settings.** Layout, thumbnails, source names and timestamps are now
  configurable in the admin and act as the defaults for the shortcode, block and template
  tag; each entry point overrides only what it explicitly sets, so an omitted attribute
  inherits rather than forcing a value.
- **SerpAPI Top stories mode.** Google News' mainstream front page for a country and
  language, with no query. Confirmed against the live API — NPR, CNBC, USA Today, CNN and
  the New York Times, all with thumbnails. Note that it returns about ten articles per
  fetch, so a large news share wants more than one source.

## [1.1.0] — 2026-08-07

> **Upgrading from 1.0.0 requires one manual install.** The update mechanism ships *in*
> this release, so a 1.0.0 site has no way to discover it — upload the zip or pull once,
> and every later release arrives on the Plugins screen by itself.

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

[1.1.4]: https://github.com/advision-development/trending-now-plugin/releases/tag/v1.1.4
[1.1.3]: https://github.com/advision-development/trending-now-plugin/releases/tag/v1.1.3
[1.1.2]: https://github.com/advision-development/trending-now-plugin/releases/tag/v1.1.2
[1.1.1]: https://github.com/advision-development/trending-now-plugin/releases/tag/v1.1.1
[1.1.0]: https://github.com/advision-development/trending-now-plugin/releases/tag/v1.1.0
[1.0.0]: https://github.com/advision-development/trending-now-plugin/releases/tag/v1.0.0
