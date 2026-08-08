# Ingest Diagnostics Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a slow source have its own timeout, make latency drift visible before it causes an outage, and let an operator retry one source from the admin and see the result go live.

**Architecture:** A per-row `timeout` field overrides the global `http_timeout`, plumbed through the `$args` array `http_get()` already merges. A new dependency-free `ADVTN_Attempts` class keeps the last 20 attempts per source in the existing `advtn_source_state` option, rendered on the Sources tab with a p50/max/timeout summary. An "Ingest now" button reuses `ADVTN_Ingest::run_source()` + `finalize()` behind the existing lock.

**Tech Stack:** WordPress 6.4+, PHP 7.4, no build step, no Composer autoload. Tests run through `php tests/run.php` against shimmed core functions.

**Spec:** `docs/superpowers/specs/2026-08-08-ingest-diagnostics-design.md`

## Global Constraints

Every task's requirements implicitly include this section.

- **PHP 7.4 syntax only.** No `str_contains`, `str_starts_with`, `str_ends_with`, union types, constructor promotion, `match`, nullsafe `?->`, or named arguments. Typed properties and arrow functions are fine.
- **Prefix everything** `advtn` / `ADVTN`. Text domain `trending-now`.
- **All SQL lives in `ADVTN_Repository`.** No `$wpdb` anywhere else, including admin views.
- **Never fetch a feed during a pageview.** Everything here runs from an explicit admin action or a scheduled cycle.
- **Escape at the point of output** — `esc_url`, `esc_html`, `esc_attr`, no exceptions. Admin forms need a nonce plus `current_user_can( 'manage_options' )`.
- **Datetimes are UTC**, via `gmdate()`. Never `NOW()`, never `date()`.
- **One bad source must never abort a cycle.** The ring append now runs inside the failure path, so it must itself be failure-proof.
- **Every PHP file** opens with `declare( strict_types=1 );` and an `ABSPATH` guard, uses tabs, and carries a docblock on every class and method.
- **Autoloading is a naming convention**, not Composer: `ADVTN_Attempts` resolves to `includes/class-advtn-attempts.php`. No registration needed.
- Lint: `find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l`
- Test: `php tests/run.php`

---

### Task 1: Per-source timeout override

Adds the `timeout` field, the resolver, the three `http_get()` call sites, and the RSS fix. The RSS half is a pre-existing bug: `ADVTN_Source_RSS` goes through `fetch_feed()`, which builds a SimplePie instance WordPress never wires our timeout to, so `http_timeout` has never applied to RSS sources at all.

**Files:**
- Modify: `includes/sources/class-advtn-source-base.php` — `base_config()` (~line 190), new `config_timeout()`
- Modify: `includes/sources/class-advtn-source-wp-rest.php:62`
- Modify: `includes/sources/class-advtn-source-hub.php:62`
- Modify: `includes/sources/class-advtn-source-serpapi.php:84`
- Modify: `includes/sources/class-advtn-source-rss.php` — `fetch()` around line 49
- Modify: `admin/views/sources.php` — new field in the row
- Modify: `tests/bootstrap.php` — `get_option` / `update_option` shims
- Test: `tests/run.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: `ADVTN_Source_Base::config_timeout( array $config ): int` — **public**, not protected, so the harness can call it on a concrete provider without building a test double. Source config rows gain a `timeout` key: `int`, `0` meaning "inherit the global".

- [ ] **Step 1: Add the option shims**

`ADVTN_Settings::get_int()` reads through `all()` → `get_option()`, which the harness does not stub — it only ever exercised the static `sanitize()`. Add to `tests/bootstrap.php`, before the block of `require_once` lines at the bottom:

```php
/**
 * Backing store for the get_option()/update_option() stubs.
 *
 * @var array<string,mixed>
 */
$GLOBALS['advtn_test_options'] = array();

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Stub of get_option().
	 *
	 * @param string $name    Option name.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	function get_option( string $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['advtn_test_options'] ) ? $GLOBALS['advtn_test_options'][ $name ] : $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * Stub of update_option().
	 *
	 * @param string $name     Option name.
	 * @param mixed  $value    Value.
	 * @param mixed  $autoload Ignored.
	 * @return bool
	 */
	function update_option( string $name, $value, $autoload = null ): bool {
		$GLOBALS['advtn_test_options'][ $name ] = $value;
		return true;
	}
}
```

`ADVTN_Settings` caches settings per instance in `$this->cache`, so a test that changes the option must build a fresh `new ADVTN_Settings()` to see it. That is why the tests below construct one each time.

- [ ] **Step 2: Write the failing tests**

Append to `tests/run.php`, before the final separator and the summary `printf`:

```php
/* ---------------------------------------------------------------------- */
/* Per-source timeout override                                             */
/* ---------------------------------------------------------------------- */

/**
 * Build a provider instance against a chosen global http_timeout.
 *
 * @param int $global Global http_timeout value.
 * @return ADVTN_Source_SerpAPI
 */
function advtn_provider_with_timeout( int $global ): ADVTN_Source_SerpAPI {
	$GLOBALS['advtn_test_options']['advtn_settings'] = array( 'http_timeout' => $global );

	return new ADVTN_Source_SerpAPI( new ADVTN_Settings() );
}

$advtn_p = advtn_provider_with_timeout( 5 );

advtn_assert_same( 30, $advtn_p->config_timeout( array( 'timeout' => 30 ) ), 'timeout: an override wins over the global' );
advtn_assert_same( 5, $advtn_p->config_timeout( array( 'timeout' => 0 ) ), 'timeout: zero inherits the global' );
advtn_assert_same( 5, $advtn_p->config_timeout( array() ), 'timeout: an absent key inherits the global' );
advtn_assert_same( 5, $advtn_p->config_timeout( array( 'timeout' => '' ) ), 'timeout: an empty string inherits the global' );
advtn_assert_same( 120, $advtn_p->config_timeout( array( 'timeout' => 9999 ) ), 'timeout: an override clamps to the 120s ceiling' );
advtn_assert_same( 1, $advtn_p->config_timeout( array( 'timeout' => 1 ) ), 'timeout: one second is allowed' );
advtn_assert_same( 5, $advtn_p->config_timeout( array( 'timeout' => -8 ) ), 'timeout: a negative override inherits rather than clamping to 1' );

$advtn_p20 = advtn_provider_with_timeout( 20 );
advtn_assert_same( 20, $advtn_p20->config_timeout( array() ), 'timeout: the global is read per instance, not cached across them' );
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `php tests/run.php`

Expected: a PHP fatal — `Call to undefined method ADVTN_Source_SerpAPI::config_timeout()`. That is the failure signal.

- [ ] **Step 4: Add the resolver and the config field**

In `includes/sources/class-advtn-source-base.php`, add this method next to `http_get()`:

```php
	/**
	 * The timeout in force for one source, in seconds.
	 *
	 * A row's own `timeout` overrides the global `http_timeout`; 0, '' or an
	 * absent key inherits it. The per-row ceiling is 120 against the global's
	 * 60 on purpose: the global is a blunt default applied to every source,
	 * where a per-row override is a considered choice about one provider.
	 *
	 * Public rather than protected so it can be exercised directly.
	 *
	 * @param array<string,mixed> $config Source config row.
	 * @return int
	 */
	public function config_timeout( array $config ): int {
		$override = (int) ( $config['timeout'] ?? 0 );

		if ( $override > 0 ) {
			return max( 1, min( 120, $override ) );
		}

		return $this->settings->get_int( 'http_timeout', 1, 60 );
	}
```

Then in `base_config()`, add one entry after `'limit'`:

```php
			'timeout'       => max( 0, min( 120, (int) ( $config['timeout'] ?? 0 ) ) ),
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php tests/run.php`

Expected: PASS, `0 failed`.

- [ ] **Step 6: Pass the timeout at the three http_get() call sites**

`http_get()` merges caller `$args` over its own `$defaults`, so an explicit `timeout` wins with no change to that method.

`includes/sources/class-advtn-source-wp-rest.php:62`:

```php
		$res    = $this->http_get( $endpoint, array( 'timeout' => $this->config_timeout( $config ) ) );
```

`includes/sources/class-advtn-source-hub.php:62` — this call already passes an `$args` array carrying the HMAC headers. Add the key alongside them; do not replace the array:

```php
		$res    = $this->http_get(
			$endpoint,
			array(
				'timeout' => $this->config_timeout( $config ),
				'headers' => array(
					'Accept'            => 'application/json',
					'X-ADVTN-Timestamp' => (string) $timestamp,
					// GET has an empty body, so the signed message is just the
					// timestamp and the separator.
					'X-ADVTN-Signature' => ADVTN_HMAC::sign( $timestamp, '', $secret ),
				),
			)
		);
```

`includes/sources/class-advtn-source-serpapi.php:84`:

```php
		$res    = $this->http_get( $endpoint, array( 'timeout' => $this->config_timeout( $config ) ) );
```

**Leave `class-advtn-source-serpapi.php:274` alone.** That is the account/credit check against a different, consistently fast endpoint. A 30s override set for the news call should not make a credential check hang for 30s.

- [ ] **Step 7: Wire the timeout into the RSS path**

`ADVTN_Source_RSS::fetch()` never calls `http_get()`, so none of the above reaches it. Add the `wp_feed_options` hook around the `fetch_feed()` call, mirroring the `wp_feed_cache_transient_lifetime` add/remove already there — same discipline, same reason: never leave a global filter registered past the call it was for.

Replace the three lines around `class-advtn-source-rss.php:49` with:

```php
		// SimplePie caches feeds for 12h by default, which would silently serve
		// stale data on a daily ingest. Shorten it for this call only.
		add_filter( 'wp_feed_cache_transient_lifetime', array( $this, 'cache_lifetime' ), 100 );

		// fetch_feed() builds its own SimplePie instance and WordPress never
		// wires our timeout to it, so http_timeout has never applied to an RSS
		// source at all — it has been sitting on SimplePie's own default. This
		// is the only hook that reaches the object before it fetches.
		$advtn_timeout   = $this->config_timeout( $config );
		$advtn_set_timeout = static function ( $feed ) use ( $advtn_timeout ): void {
			$feed->set_timeout( $advtn_timeout );
		};
		add_action( 'wp_feed_options', $advtn_set_timeout, 10, 1 );

		$feed = fetch_feed( $url );

		remove_action( 'wp_feed_options', $advtn_set_timeout, 10 );
		remove_filter( 'wp_feed_cache_transient_lifetime', array( $this, 'cache_lifetime' ), 100 );
```

- [ ] **Step 8: Add the field to the Sources tab**

In `admin/views/sources.php`, add a field to the row's field grid — next to the existing "Items per cycle" input, not inside a `data-types` block, since it applies to every type:

```php
			<label>
				<span><?php esc_html_e( 'Timeout (seconds)', 'trending-now' ); ?></span>
				<input
					type="number"
					min="0"
					max="120"
					name="sources[<?php echo esc_attr( (string) $index ); ?>][timeout]"
					value="<?php echo esc_attr( (string) ( $source['timeout'] ?? 0 ) ); ?>"
					placeholder="<?php echo esc_attr( sprintf( /* translators: %d: global timeout in seconds. */ __( 'global (%d)', 'trending-now' ), advtn()->settings()->get_int( 'http_timeout', 1, 60 ) ) ); ?>"
				/>
				<em><?php esc_html_e( 'Blank or 0 uses the global setting. Raise it for a slow provider — SerpAPI does a live scrape and can exceed the 5s default under load.', 'trending-now' ); ?></em>
			</label>
```

- [ ] **Step 9: Lint and run the tests**

Run: `find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l`
Expected: `No syntax errors detected` for every file.

Run: `php tests/run.php`
Expected: PASS, `0 failed`.

- [ ] **Step 10: Commit**

```bash
git add includes/sources/ admin/views/sources.php tests/bootstrap.php tests/run.php
git commit -m "feat: add a per-source HTTP timeout override"
```

---

### Task 2: Attempt ring and log enrichment

A dependency-free `ADVTN_Attempts` holds the ring logic and its summary, wired into both of `ADVTN_Ingest`'s state-writing paths. The log gains the two fields that would have made last night's timeout explain itself.

**Files:**
- Create: `includes/class-advtn-attempts.php`
- Modify: `includes/class-advtn-ingest.php` — `run_source()` success branch (~line 250) and `record_failure()` (~line 437)
- Modify: `tests/bootstrap.php` — one new require
- Test: `tests/run.php`

**Interfaces:**
- Consumes: `ADVTN_Source_Base::config_timeout( array $config ): int` from Task 1.
- Produces:
  - `ADVTN_Attempts::MAX` (int, 20), `ADVTN_Attempts::ERROR_MAX` (int, 120)
  - `ADVTN_Attempts::record( array $ring, bool $ok, int $ms, ?int $code, string $error ): array`
  - `ADVTN_Attempts::summary( array $ring ): array` returning `array( 'count' => int, 'p50' => int, 'max' => int )`
  - `advtn_source_state[<id>]['attempts']` — the ring, newest last.

- [ ] **Step 1: Write the failing tests**

Append to `tests/run.php`, before the final separator and summary `printf`:

```php
/* ---------------------------------------------------------------------- */
/* Attempt ring                                                            */
/* ---------------------------------------------------------------------- */

$advtn_ring = array();
for ( $advtn_i = 1; $advtn_i <= 25; $advtn_i++ ) {
	$advtn_ring = ADVTN_Attempts::record( $advtn_ring, true, $advtn_i * 100, 200, '' );
}

advtn_assert_same( 20, count( $advtn_ring ), 'attempts: the ring caps at 20 entries' );
advtn_assert_same( 600, $advtn_ring[0]['ms'], 'attempts: the oldest entries are dropped first' );
advtn_assert_same( 2500, $advtn_ring[19]['ms'], 'attempts: the newest entry is last' );
advtn_assert_same( true, $advtn_ring[19]['ok'], 'attempts: a success records ok true' );
advtn_assert_same( '', $advtn_ring[19]['err'], 'attempts: a success records an empty error' );
advtn_assert_same( 200, $advtn_ring[19]['code'], 'attempts: the http code is kept' );
advtn_assert_same( true, isset( $advtn_ring[19]['t'] ) && 19 === strlen( (string) $advtn_ring[19]['t'] ), 'attempts: the timestamp is a UTC datetime string' );

$advtn_long  = str_repeat( 'x', 400 );
$advtn_fail  = ADVTN_Attempts::record( array(), false, 5006, null, $advtn_long );

advtn_assert_same( false, $advtn_fail[0]['ok'], 'attempts: a failure records ok false' );
advtn_assert_same( 5006, $advtn_fail[0]['ms'], 'attempts: a failure keeps its elapsed time' );
advtn_assert_same( null, $advtn_fail[0]['code'], 'attempts: a null http code survives' );
advtn_assert_same( 120, strlen( $advtn_fail[0]['err'] ), 'attempts: a long error truncates at write time' );

advtn_assert_same(
	array( 'count' => 0, 'p50' => 0, 'max' => 0 ),
	ADVTN_Attempts::summary( array() ),
	'attempts: an empty ring summarises to zeroes'
);

$advtn_odd = array();
foreach ( array( 100, 300, 200 ) as $advtn_ms ) {
	$advtn_odd = ADVTN_Attempts::record( $advtn_odd, true, $advtn_ms, 200, '' );
}
advtn_assert_same(
	array( 'count' => 3, 'p50' => 200, 'max' => 300 ),
	ADVTN_Attempts::summary( $advtn_odd ),
	'attempts: an odd count takes the middle value'
);

$advtn_even = array();
foreach ( array( 100, 400, 200, 300 ) as $advtn_ms ) {
	$advtn_even = ADVTN_Attempts::record( $advtn_even, true, $advtn_ms, 200, '' );
}
advtn_assert_same(
	array( 'count' => 4, 'p50' => 250, 'max' => 400 ),
	ADVTN_Attempts::summary( $advtn_even ),
	'attempts: an even count averages the two middle values'
);

$advtn_one = ADVTN_Attempts::record( array(), true, 2010, 200, '' );
advtn_assert_same(
	array( 'count' => 1, 'p50' => 2010, 'max' => 2010 ),
	ADVTN_Attempts::summary( $advtn_one ),
	'attempts: a single entry is its own median and max'
);

advtn_assert_same(
	20,
	count( ADVTN_Attempts::record( array_fill( 0, 400, array( 'ms' => 1 ) ), true, 50, 200, '' ) ),
	'attempts: an oversized stored ring is trimmed on the next write'
);
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php tests/run.php`

Expected: a PHP fatal — `Class "ADVTN_Attempts" not found`.

- [ ] **Step 3: Create the class**

Create `includes/class-advtn-attempts.php`:

```php
<?php
/**
 * Per-source attempt history: a short ring of recent fetches and its summary.
 *
 * Source state keeps only the most recent run, so a source drifting from two
 * seconds toward its timeout over an afternoon is invisible until it crosses
 * the line. This is what makes that drift a number.
 *
 * Pure static and free of WordPress state so the dependency-free harness can
 * exercise it.
 *
 * @package Advision\TrendingNow
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ADVTN_Attempts {

	/** How many attempts to keep per source. */
	public const MAX = 20;

	/** Error messages are truncated to this many characters at write time. */
	public const ERROR_MAX = 120;

	/**
	 * Append one attempt and trim to the cap.
	 *
	 * Errors are truncated here rather than at read time: an untruncated cURL
	 * message can carry a long URL, and twenty of those per source is what
	 * turns a diagnostic aid into a bloated option.
	 *
	 * @param array<int,array<string,mixed>> $ring  Existing ring, newest last.
	 * @param bool                           $ok    Whether the fetch succeeded.
	 * @param int                            $ms    Elapsed milliseconds.
	 * @param int|null                       $code  HTTP status, null on a transport error.
	 * @param string                         $error Error message, '' on success.
	 * @return array<int,array<string,mixed>>
	 */
	public static function record( array $ring, bool $ok, int $ms, ?int $code, string $error ): array {
		$ring[] = array(
			't'    => gmdate( 'Y-m-d H:i:s' ),
			'ok'   => $ok,
			'ms'   => max( 0, $ms ),
			'code' => $code,
			'err'  => mb_substr( $error, 0, self::ERROR_MAX ),
		);

		if ( count( $ring ) > self::MAX ) {
			$ring = array_slice( $ring, -self::MAX );
		}

		return array_values( $ring );
	}

	/**
	 * Median, maximum and count over a ring.
	 *
	 * The median rather than a mean, so one outlier does not hide an otherwise
	 * healthy set of fetches.
	 *
	 * @param array<int,array<string,mixed>> $ring Ring, newest last.
	 * @return array{count:int,p50:int,max:int}
	 */
	public static function summary( array $ring ): array {
		$times = array();

		foreach ( $ring as $entry ) {
			if ( isset( $entry['ms'] ) ) {
				$times[] = (int) $entry['ms'];
			}
		}

		if ( empty( $times ) ) {
			return array(
				'count' => 0,
				'p50'   => 0,
				'max'   => 0,
			);
		}

		sort( $times );
		$count  = count( $times );
		$middle = (int) floor( ( $count - 1 ) / 2 );

		$p50 = 0 === $count % 2
			? (int) round( ( $times[ $middle ] + $times[ $middle + 1 ] ) / 2 )
			: $times[ $middle ];

		return array(
			'count' => $count,
			'p50'   => $p50,
			'max'   => max( $times ),
		);
	}
}
```

- [ ] **Step 4: Require the class in the test bootstrap**

Add to the `require_once` block at the bottom of `tests/bootstrap.php`:

```php
require_once dirname( __DIR__ ) . '/includes/class-advtn-attempts.php';
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php tests/run.php`

Expected: PASS, `0 failed`.

- [ ] **Step 6: Wire the ring into the success path**

In `includes/class-advtn-ingest.php`, inside `run_source()`, replace the `update_source_state()` call in the success branch so it also writes the ring. Read the current state first — `update_source_state()` merges a patch, so the ring has to be fetched, appended to, and written back:

```php
		$now   = gmdate( 'Y-m-d H:i:s' );
		$state = $this->settings->source_state( $source_id );

		$this->settings->update_source_state(
			$source_id,
			array(
				'last_run'      => $now,
				'last_success'  => $now,
				'last_error'    => null,
				'http_code'     => $result->http_code,
				'duration_ms'   => $result->duration_ms,
				'items_seen'    => $seen,
				'items_new'     => $new,
				'consec_fails'  => 0,
				'backoff_until' => null,
				'attempts'      => ADVTN_Attempts::record(
					isset( $state['attempts'] ) && is_array( $state['attempts'] ) ? $state['attempts'] : array(),
					true,
					$result->duration_ms,
					$result->http_code,
					''
				),
			)
		);
```

- [ ] **Step 7: Wire the ring and the new log fields into the failure path**

`record_failure()` already reads `$state` for `consec_fails`, so the ring append costs nothing extra there. Replace the body from `$this->settings->update_source_state(` onward:

```php
		$this->settings->update_source_state(
			$source_id,
			array(
				'last_run'      => gmdate( 'Y-m-d H:i:s' ),
				'last_error'    => $result->error,
				'http_code'     => $result->http_code,
				'duration_ms'   => $result->duration_ms,
				'consec_fails'  => $fails,
				'backoff_until' => gmdate( 'Y-m-d H:i:s', time() + $backoff ),
				'attempts'      => ADVTN_Attempts::record(
					isset( $state['attempts'] ) && is_array( $state['attempts'] ) ? $state['attempts'] : array(),
					false,
					$result->duration_ms,
					$result->http_code,
					(string) $result->error
				),
			)
		);

		ADVTN_Logger::log(
			'error',
			'Source fetch failed.',
			array(
				'source_id'    => $source_id,
				'error'        => $result->error,
				'http_code'    => $result->http_code,
				'consec_fails' => $fails,
				'duration_ms'  => $result->duration_ms,
				'timeout'      => $this->timeout_for( $source_id ),
			)
		);
```

- [ ] **Step 8: Add the timeout lookup and enrich the success log**

`record_failure()` has only a source id, so it needs a way back to the effective timeout. Add this private method to `ADVTN_Ingest`:

```php
	/**
	 * The timeout in force for one source, for logging.
	 *
	 * A timeout failure whose log entry does not name the setting that caused
	 * it makes the operator go and look the number up. Returns the global when
	 * the source or its provider cannot be resolved.
	 *
	 * @param string $source_id Source id.
	 * @return int
	 */
	private function timeout_for( string $source_id ): int {
		$config = $this->source_config( $source_id );
		$source = null !== $config ? advtn()->source( (string) ( $config['type'] ?? '' ) ) : null;

		if ( null === $config || ! $source instanceof ADVTN_Source_Base ) {
			return $this->settings->get_int( 'http_timeout', 1, 60 );
		}

		return $source->config_timeout( $config );
	}
```

Then add the same field to the success log in `run_source()`:

```php
		ADVTN_Logger::log(
			'info',
			'Source ingested.',
			array(
				'source_id'   => $source_id,
				'items_seen'  => $seen,
				'items_new'   => $new,
				'duration_ms' => $result->duration_ms,
				'timeout'     => $this->timeout_for( $source_id ),
			)
		);
```

A successful-but-slow fetch is then comparable against its own ceiling without cross-referencing settings — which is the whole point.

- [ ] **Step 9: Lint and run the tests**

Run: `find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l`
Expected: `No syntax errors detected` for every file.

Run: `php tests/run.php`
Expected: PASS, `0 failed`.

- [ ] **Step 10: Commit**

```bash
git add includes/class-advtn-attempts.php includes/class-advtn-ingest.php tests/bootstrap.php tests/run.php
git commit -m "feat: record a per-source attempt ring and log the timeout in force"
```

---

### Task 3: Sources tab — attempt history and "Ingest now"

The operator-facing half: see the history next to the timeout you would change, and retry one source without WP-CLI.

**Files:**
- Modify: `admin/views/sources.php` — the row foot (~line 131)
- Modify: `admin/class-advtn-admin.php` — `register_hooks()` (~line 93), new handler, `render_notice()` (~line 975)
- Modify: `admin/assets/admin.css` — history styling

**Interfaces:**
- Consumes: `ADVTN_Attempts::summary()` from Task 2, `advtn_source_state[<id>]['attempts']`, `ADVTN_Source_Base::config_timeout()` from Task 1.
- Produces: `admin_post_advtn_ingest_source` action; notice codes `ingest_source_done`, `ingest_source_failed`, `ingest_source_unknown`.

- [ ] **Step 1: Render the attempt history in the row**

`admin/views/sources.php`'s row closure already receives `$state`. Add this directly above the existing `advtn-source__foot` div:

```php
		<?php
		$advtn_attempts = isset( $state['attempts'] ) && is_array( $state['attempts'] ) ? $state['attempts'] : array();
		if ( ! empty( $advtn_attempts ) ) :
			$advtn_stats   = ADVTN_Attempts::summary( $advtn_attempts );
			$advtn_source  = advtn()->source( (string) ( $source['type'] ?? '' ) );
			$advtn_ceiling = $advtn_source instanceof ADVTN_Source_Base
				? $advtn_source->config_timeout( $source )
				: advtn()->settings()->get_int( 'http_timeout', 1, 60 );
			?>
			<details class="advtn-attempts">
				<summary>
					<?php
					printf(
						/* translators: 1: attempt count, 2: median ms, 3: max ms, 4: timeout in seconds. */
						esc_html__( 'Recent attempts (%1$d) — p50 %2$dms, max %3$dms, timeout %4$ds', 'trending-now' ),
						(int) $advtn_stats['count'],
						(int) $advtn_stats['p50'],
						(int) $advtn_stats['max'],
						(int) $advtn_ceiling
					);
					?>
				</summary>
				<ul class="advtn-attempts__list">
					<?php foreach ( array_reverse( $advtn_attempts ) as $advtn_attempt ) : ?>
						<li class="advtn-attempts__row advtn-attempts__row--<?php echo empty( $advtn_attempt['ok'] ) ? 'fail' : 'ok'; ?>">
							<span class="advtn-attempts__time"><?php echo esc_html( (string) ( $advtn_attempt['t'] ?? '' ) ); ?></span>
							<span class="advtn-attempts__status"><?php echo empty( $advtn_attempt['ok'] ) ? esc_html__( 'FAIL', 'trending-now' ) : esc_html__( 'ok', 'trending-now' ); ?></span>
							<span class="advtn-attempts__ms"><?php echo esc_html( (string) ( $advtn_attempt['ms'] ?? 0 ) ); ?>ms</span>
							<?php if ( ! empty( $advtn_attempt['code'] ) ) : ?>
								<span class="advtn-attempts__code"><?php echo esc_html( (string) $advtn_attempt['code'] ); ?></span>
							<?php endif; ?>
							<?php if ( ! empty( $advtn_attempt['err'] ) ) : ?>
								<span class="advtn-attempts__err"><?php echo esc_html( (string) $advtn_attempt['err'] ); ?></span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			</details>
		<?php endif; ?>
```

Newest first in the display (`array_reverse`) even though the ring stores newest last — the operator reads the top line first.

- [ ] **Step 2: Add the "Ingest now" button**

In the same file, in the `advtn-source__foot` div, directly after the existing **Test fetch** button. It is a link styled as a button, not a submit, because the surrounding `<form>` posts the whole source list to `advtn_save_sources` and this must not carry that payload:

```php
			<?php if ( ! empty( $source['id'] ) ) : ?>
				<a
					class="button advtn-ingest-now"
					href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=advtn_ingest_source&source=' . rawurlencode( (string) $source['id'] ) ), 'advtn_ingest_source' ) ); ?>"
				><?php esc_html_e( 'Ingest now', 'trending-now' ); ?></a>
			<?php endif; ?>
```

Add a note under the button explaining what it does differently from Test fetch, since the two sit side by side:

```php
			<em class="advtn-ingest-now__hint"><?php esc_html_e( 'Test fetch shows what would be ingested. Ingest now writes it, rebuilds the list and purges caches.', 'trending-now' ); ?></em>
```

- [ ] **Step 3: Register the handler**

In `admin/class-advtn-admin.php`, add one line to `register_hooks()` after the `advtn_import_sources` registration:

```php
		add_action( 'admin_post_advtn_ingest_source', array( $this, 'handle_ingest_source' ) );
```

- [ ] **Step 4: Write the handler**

Add to `admin/class-advtn-admin.php`, next to the other `handle_*` methods:

```php
	/**
	 * Fetch one source, write its items and commit the result.
	 *
	 * Deliberately calls run_source() directly rather than going through
	 * run(): the backoff check lives in run()'s scheduling loop, so this path
	 * already ignores it. That is the point — a source with two consecutive
	 * failures is parked for two hours, which is exactly when an operator
	 * reaches for this button.
	 *
	 * finalize() runs even when the fetch failed. A failed fetch writes
	 * nothing, so it is close to a no-op with a cache purge attached, and
	 * branching on success would add a second path for no gain. It also
	 * releases the lock, which is why it sits in the finally.
	 *
	 * @return void
	 */
	public function handle_ingest_source(): void {
		$this->guard( 'advtn_ingest_source' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified in guard().
		$source_id = isset( $_GET['source'] ) ? preg_replace( '/[^a-z0-9_]/', '', (string) wp_unslash( $_GET['source'] ) ) : '';

		if ( '' === (string) $source_id ) {
			$this->redirect( 'sources', 'ingest_source_unknown' );
		}

		// The spec asks for a notice naming the lock age. Reuse the existing
		// 'locked' notice instead: every other locked path in this admin uses
		// it, and a bespoke message for one button is worse than a consistent
		// one. ADVTN_Lock::age() is on the Diagnostics tab for the operator who
		// needs the number.
		if ( ! ADVTN_Lock::acquire() ) {
			$this->redirect( 'sources', 'locked' );
		}

		$notice = 'ingest_source_failed';

		try {
			$result = advtn()->ingest()->run_source( (string) $source_id );
			$notice = $result->ok ? 'ingest_source_done' : 'ingest_source_failed';
		} catch ( \Throwable $e ) {
			ADVTN_Logger::log(
				'error',
				'Manual single-source ingest failed.',
				array(
					'source_id' => (string) $source_id,
					'error'     => $e->getMessage(),
				)
			);
		} finally {
			// Releases the lock, rebuilds the selection and busts the caches.
			advtn()->ingest()->finalize();
		}

		$this->redirect( 'sources', $notice );
	}
```

`redirect()` calls `exit`, so the early returns above do not need one.

- [ ] **Step 5: Add the notice messages**

In `render_notice()`'s `$messages` array, add three entries:

```php
			'ingest_source_done'    => array( 'success', __( 'Source ingested. The list has been rebuilt and caches purged.', 'trending-now' ) ),
			'ingest_source_failed'  => array( 'error', __( 'That source failed to ingest. Its recent attempts are listed on its row.', 'trending-now' ) ),
			'ingest_source_unknown' => array( 'error', __( 'No such source.', 'trending-now' ) ),
```

- [ ] **Step 6: Style the history**

Append to `admin/assets/admin.css`:

```css
/* Attempt history ------------------------------------------------------ */

.advtn-attempts {
	margin: 8px 0 0;
	font-size: 12px;
}

.advtn-attempts > summary {
	cursor: pointer;
	color: #50575e;
}

.advtn-attempts__list {
	margin: 6px 0 0;
	padding: 6px 8px;
	max-height: 220px;
	overflow-y: auto;
	background: #f6f7f7;
	border: 1px solid #dcdcde;
	border-radius: 4px;
	list-style: none;
}

.advtn-attempts__row {
	display: flex;
	gap: 10px;
	align-items: baseline;
	padding: 2px 0;
	font-variant-numeric: tabular-nums;
}

.advtn-attempts__row--fail {
	color: #b32d2e;
}

.advtn-attempts__ms {
	min-width: 64px;
	text-align: right;
}

.advtn-attempts__err {
	flex: 1 1 auto;
	overflow-wrap: anywhere;
}

.advtn-ingest-now__hint {
	display: block;
	margin-top: 6px;
	color: #646970;
	font-style: normal;
	font-size: 12px;
}
```

- [ ] **Step 7: Lint and run the tests**

Run: `find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l`
Expected: `No syntax errors detected` for every file.

Run: `php tests/run.php`
Expected: PASS, `0 failed`. This task adds no test cases — it is admin UI and a handler, which the dependency-free harness cannot reach. Do not shim the WordPress admin to reach them.

- [ ] **Step 8: Commit**

```bash
git add admin/views/sources.php admin/class-advtn-admin.php admin/assets/admin.css
git commit -m "feat: show per-source attempt history and add Ingest now"
```

---

### Task 4: Documentation

**Files:**
- Modify: `docs/trending-now-plugin-spec.md` — the sources and settings sections
- Modify: `CLAUDE.md` — architecture tree and conventions
- Modify: `README.md` — Sources and Settings reference
- Modify: `CHANGELOG.md` — the existing `[Unreleased]` section

**Interfaces:**
- Consumes: everything from Tasks 1-3.
- Produces: nothing.

- [ ] **Step 1: Verify before writing**

Read `includes/class-advtn-attempts.php` and the changed parts of `includes/sources/class-advtn-source-base.php` and `includes/class-advtn-ingest.php` before documenting anything. Then check the claims you are about to make:

```bash
grep -n "'timeout'" includes/sources/class-advtn-source-base.php
grep -rn "config_timeout" includes/
grep -n "MAX\|ERROR_MAX" includes/class-advtn-attempts.php
```

A documented field or method that does not exist under that exact name is worse than no documentation.

- [ ] **Step 2: Update the spec**

In `docs/trending-now-plugin-spec.md`, add the `timeout` key to the source config row documentation, and add a short subsection covering the attempt ring and the manual single-source ingest. Cover:

- `timeout`: per-row, `0` inherits the global `http_timeout`, clamped 1–120, and why the per-row ceiling is higher than the global's 60.
- The ring: 20 entries, stored in `advtn_source_state`, errors truncated to 120 characters at write time, and why (option size).
- **Ingest now**: bypasses backoff by construction, takes the lock, and finalizes unconditionally — including that a single-source retry purges the whole site's page cache.
- The RSS fix: `http_timeout` never reached SimplePie before, so RSS sources sat on its own default.

- [ ] **Step 3: Update CLAUDE.md**

Add to the architecture tree, after the `class-advtn-logger.php` line:

```
  class-advtn-attempts.php    per-source attempt ring + p50/max summary (pure static)
```

Add these conventions bullets:

```markdown
- `http_timeout` is a global floor, not the whole story: a source row's own `timeout`
  overrides it, and `ADVTN_Source_Base::config_timeout()` is the only place that resolves
  the two. Providers pass the result in `http_get()`'s `$args`, which already wins over
  its defaults. RSS is the exception — it goes through `fetch_feed()`, so its timeout has
  to be set on the SimplePie object via `wp_feed_options`, added and removed around the
  call like the cache-lifetime filter beside it.
- The admin "Ingest now" calls `ADVTN_Ingest::run_source()` directly rather than `run()`.
  That is deliberate: the backoff check lives in `run()`'s scheduling loop, so calling
  `run_source()` bypasses it, which is the entire point of a manual retry on a source that
  has been failing.
- Anything that writes source state writes the attempt ring with it. Both paths go through
  `ADVTN_Attempts::record()` so the cap and the error truncation cannot drift apart.
```

- [ ] **Step 4: Update the README**

Add the `timeout` field to the Sources section's field description, and document the **Ingest now** button beside **Test fetch**, making the difference explicit: Test fetch shows what would be ingested and writes nothing; Ingest now writes, rebuilds the list and purges caches.

Add a Troubleshooting entry for the case this feature was built for:

```markdown
**A source times out intermittently.** `cURL error 28` names the ceiling it hit — "timed
out after 5001 milliseconds" is the 5-second `http_timeout` default, not a network fault.
Open that source's **Recent attempts** on the Sources tab: if p50 has been climbing toward
the timeout, raise that row's **Timeout** rather than the global. SerpAPI does a live
scrape and can exceed 5 seconds under load; 20–30 is a reasonable value for it. Owned
`wp_rest` sources should keep failing fast.
```

- [ ] **Step 5: Update the changelog**

Add to the existing `## [Unreleased]` section — do not create a new one, and do not bump the version in `trending-now.php`. Follow the register of the `[1.1.4]` entry: say why, and what it cost.

Cover the per-source timeout, the attempt ring, **Ingest now**, the enriched failure log, and — under `### Fixed` — that `http_timeout` never applied to RSS sources at all because `fetch_feed()` builds its own SimplePie instance.

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
git add docs/trending-now-plugin-spec.md CLAUDE.md README.md CHANGELOG.md
git commit -m "docs: document the per-source timeout, attempt ring and Ingest now"
```

---

## Acceptance

Work through spec §"Acceptance criteria" (items 1–12) against the running stack at `http://localhost:8080` before calling this done. The ones no single task verifies in isolation:

- **Criterion 3** — an RSS source honours its timeout. Point one at a deliberately slow endpoint and confirm it now gives up at the configured value rather than SimplePie's own default.
- **Criterion 7** — **Ingest now** on a source inside its backoff window fetches anyway. Force it with `bin/dev wp eval` to write a future `backoff_until` into `advtn_source_state`, then press the button.
- **Criterion 8** — **Ingest now** while the lock is held reports the lock age. Acquire the lock with `bin/dev wp eval 'ADVTN_Lock::acquire();'` first, and release it afterwards with `bin/dev wp trending-now unlock`.
- **Criterion 9** — after a successful **Ingest now**, new items appear in the rendered widget without a further cycle.
