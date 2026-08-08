# Ingest diagnostics: per-source timeout, attempt history, manual ingest — design

Date: 2026-08-08
Status: approved, ready for planning
Branch: `feat/ingest-diagnostics`, stacked on `feat/archive-seo` (both target 1.2.0, and
both touch `ADVTN_Admin`)

## Problem

A SerpAPI source that had been fetching successfully all afternoon began failing overnight:

```
{"source_id":"src_9b0aef","error":"cURL error 28: Operation timed out after 5001
milliseconds with 0 bytes received","http_code":null,"consec_fails":2}
```

Nothing broke. `http_timeout` defaults to `5`, and `ADVTN_Source_Base::http_get()` applies
that value as both the request timeout and — via its `http_api_curl` hook — the connect
timeout. The failure fired at 5001ms against a 5000ms ceiling, and the recorded
`duration_ms` of 5006 is the same number from the other side. The backoff confirms the
rest: `source_fail_backoff` (3600) × `min( consec_fails, 6 )` gives 7200s, and the
observed 13:20:49 → 15:20:49 window is exactly two hours.

SerpAPI's Google News endpoint performs a live scrape, so its latency varies with load. A
local test returned in 2010ms; overnight it went past five seconds and the same code
started timing out. The 5s default is simply too tight for that provider.

Three gaps turned a tunable-too-low into an outage nobody saw coming:

1. **`http_timeout` is global.** One value covers a SerpAPI call that wants 30s and a
   `wp_rest` call to an owned site that should fail fast. Raising it to suit the slowest
   provider makes every dead source stall the cycle for six times longer.
2. **No latency history.** `advtn_source_state` keeps only the most recent run, so a
   source drifting from 2s toward 5s over an afternoon is invisible until it crosses the
   line.
3. **No way to retry one source and see it land.** `ADVTN_Ingest::test_fetch()` backs the
   admin **Test fetch** button but deliberately writes nothing. `wp trending-now ingest
   --source=<id> --sync` does write, but CLAUDE.md rule 12 forbids depending on WP-CLI,
   and it is not available on all target hosts.

A fourth, smaller gap made the log less useful than it should have been:
`ADVTN_Ingest::record_failure()` logs `error`, `http_code` and `consec_fails`, but not
`duration_ms` and not the timeout in force. The log said "timed out after 5001
milliseconds" without ever naming the setting responsible.

## Goals

- Let a slow provider have a longer timeout without slowing down every other source.
- Make latency drift visible before it becomes an outage.
- Let an operator retry one source from the admin and see the result go live.
- Make a timeout failure explain itself in the log.

## Non-goals

- A slow-fetch warning threshold. The attempt ring makes drift visible; adding a second
  tunable and a new class of log noise is not worth it yet.
- Per-source-type timeout defaults. The per-row override covers the case without hiding
  numbers from the operator.
- Changing the global `http_timeout` default of 5. It is correct for the `wp_rest` sources
  that make up most of a network.
- Retrying automatically on timeout. A retry inside a cycle competes with
  `batch_time_budget` and doubles the cost of a genuinely dead source.

## 1. Per-source timeout override

A new optional `timeout` field on every source config row.

- Validated in `ADVTN_Source_Base::validate_config()` beside `label` / `enabled` / `limit`,
  so all four source types inherit it from one change.
- `0`, blank or absent means "inherit the global `http_timeout`". Otherwise clamped to
  1–120. The ceiling is above the global's 60 because a deliberate per-source override is
  a considered choice, where the global is a blunt default.
- Rendered on the Sources tab as a narrow number input with a `blank = global (N)`
  placeholder that names the current global.

### Plumbing

`http_get()` already merges caller `$args` over its own `$defaults`, so an explicit
`timeout` in `$args` wins with no change to that method's body. `ADVTN_Source_Base` gains:

```php
protected function config_timeout( array $config ): int
```

returning the row's override when set and `$this->settings->get_int( 'http_timeout', 1, 60 )`
otherwise. Three call sites pass it:

- `includes/sources/class-advtn-source-wp-rest.php:62`
- `includes/sources/class-advtn-source-hub.php:62`
- `includes/sources/class-advtn-source-serpapi.php:84`

`class-advtn-source-serpapi.php:274` — the account/credit check — keeps the global. It hits
a different, consistently fast endpoint, and a 30s override set for the news call should
not make a credential check hang for 30s.

The `http_api_curl` hook inside `http_get()` that raises `CURLOPT_CONNECTTIMEOUT` already
reads the effective timeout from its enclosing scope, so it follows the override with no
further change.

### RSS

`ADVTN_Source_RSS` does not use `http_get()`. It calls `fetch_feed()`, which builds a
SimplePie instance whose timeout WordPress never wires to ours — so **`http_timeout` is
silently ignored for every RSS source today**, at SimplePie's own 15s default. That is a
pre-existing bug, in scope here because this change is what makes it visible.

Fix with the `wp_feed_options` action, mirroring the `wp_feed_cache_transient_lifetime`
filter already used in that file: add the hook immediately before `fetch_feed()`, call
`$feed->set_timeout( $n )`, remove it immediately after. Same add/remove discipline, same
reason — never leave a global filter registered past the call it was for.

## 2. Per-source attempt ring

`advtn_source_state[<source_id>]['attempts']` holds the last 20 attempts, newest last:

```php
array(
	't'    => '2026-08-08 13:20:49',   // UTC, gmdate()
	'ok'   => false,
	'ms'   => 5006,
	'code' => null,                    // int|null HTTP status
	'err'  => 'cURL error 28: Operati…' // '' on success, else truncated to 120 chars
)
```

Appended from both paths in `ADVTN_Ingest`: the success branch of `run_source()` and
`record_failure()`. One private helper does the append-and-trim so the two cannot drift.

Twenty entries at roughly 120 bytes each is ~2.4KB per source. `advtn_source_state` is
already non-autoloaded, so this does not touch the autoload budget.

Errors are truncated at write time, not at read time — an untruncated cURL error with a
long URL in it can run to several hundred characters, and twenty of those per source is
what turns a diagnostic aid into a bloated option.

### Display

The Sources tab renders the ring per row: the attempt list, plus a summary line of
`p50 / max / timeout`. The summary is the part that matters — it turns "it feels slow"
into a number next to the ceiling it is approaching. p50 over the ring, not a rolling
average, so one outlier does not hide a healthy median.

## 3. Admin "Ingest now"

A button beside **Test fetch** on each source row.

`admin-post` handler, not AJAX: the request can legitimately take 30 seconds, and it needs
a redirect plus an admin notice afterwards — which is exactly what the existing
`handle_save_*` handlers already do. The AJAX `advtn_test_fetch` path stays as it is.

Sequence:

1. `guard( 'advtn_ingest_source' )` — nonce plus `current_user_can( 'manage_options' )`.
2. **Bypass `backoff_until`.** A source with `consec_fails: 2` is parked for two hours,
   which is precisely when an operator reaches for this button. A retry that silently does
   nothing for two hours is worse than no button.
3. Take the ingest lock via `ADVTN_Lock`. Without it this races a running cycle. If the
   lock is held, redirect with a notice naming the age rather than waiting.
4. `ADVTN_Ingest::run_source( $source_id )` — existing method, no new ingest logic. It
   already records state, appends to the ring and logs.
5. `ADVTN_Ingest::finalize()` — commit the selection and bust the render and page caches.
6. Release the lock in a `finally`.

Report seen/new counts and duration in the notice on success, and the error on failure.

**Finalize runs unconditionally**, including after a failed fetch. Two reasons: a failed
fetch writes nothing, so `finalize()` is close to a no-op plus a cache purge; and branching
on success adds a second code path for a case that costs nothing. The purge is the one real
side effect — a single-source retry busts the whole site's page cache. That is accepted:
the alternative is rows that are written but invisible until the next cycle, which is the
complaint this feature exists to fix.

`finalize()`'s stale-marking is time-based on `last_seen`, not cycle-based, so running it
after a single source cannot prematurely stale another source's items.

## 4. Log enrichment

`ADVTN_Ingest::record_failure()`'s log context gains `duration_ms` and `timeout` — the
value actually in force for that source, override or global. The success path already
logs `duration_ms`; it gains `timeout` too, so a successful-but-slow fetch is comparable
against its ceiling without cross-referencing settings.

No new log levels, no new storage. `ADVTN_Logger::scrub()` already covers the context.

## Testing

`tests/run.php` runs without WordPress. What is reachable there:

- `ADVTN_Source_Base::config_timeout()` — override wins; `0`, `''` and absent all fall back
  to the global; out-of-range clamps to 1–120. The harness has no `get_option()` shim
  today — it only exercises `ADVTN_Settings::sanitize()`, which is static — so one has to
  be added, backed by a settable array so a test can choose the global. That shim is
  reusable and worth having regardless.
- The ring append helper — caps at 20, drops oldest first, truncates `err` at 120 chars,
  records `ok` correctly for both outcomes, and leaves `err` empty on success.
- The p50/max summary — median of an odd count, median of an even count (the mean of the
  two middle values, rounded to an int), and a single-entry ring where p50 and max are the
  same number.

Extract the ring helper and the summary as static, WordPress-free functions so they are
reachable at all; that is the same split that made the SEO builders testable.

Not reachable, and belonging to an integration suite: the admin handler, the lock
interaction, `finalize()`, and the RSS `wp_feed_options` wiring.

## Constraints

- PHP 7.4 target: no `str_contains`, `str_starts_with`, `str_ends_with`, union types,
  constructor promotion, `match`, or nullsafe operators.
- All SQL stays in `ADVTN_Repository`.
- Never fetch during a pageview — this feature only ever fetches from an explicit admin
  action or a scheduled cycle.
- Datetimes are UTC via `gmdate()`.
- Escape at output; the admin form needs a nonce and `manage_options`.
- One bad source must never abort a cycle — the ring append must itself be failure-proof,
  since it now runs inside the failure path.
- `declare( strict_types=1 )` and an `ABSPATH` guard on every touched file.

## Acceptance criteria

1. A source row with `timeout` set to 30 survives a SerpAPI fetch that takes 12 seconds,
   while a `wp_rest` row with the field blank still gives up at the global 5.
2. Clearing the field returns that source to the global, and the placeholder names the
   current global value.
3. An RSS source honours its timeout — verifiable against a deliberately slow endpoint,
   where before this change it waited on SimplePie's own default.
4. Twenty successful fetches leave exactly 20 ring entries, the oldest dropped.
5. A failed fetch appends an entry with `ok: false`, the elapsed ms, and a truncated error.
6. The Sources tab shows p50, max and the effective timeout for a source with history, and
   degrades cleanly for one with none.
7. **Ingest now** on a source inside its backoff window fetches anyway.
8. **Ingest now** while a cycle holds the lock reports the lock age and does not run.
9. After a successful **Ingest now**, new items appear in the rendered widget without a
   further cycle.
10. A timeout failure's log entry names both `duration_ms` and the `timeout` in force.
11. `php tests/run.php` passes, including the new cases.
12. All PHP files lint clean under PHP 7.4.
