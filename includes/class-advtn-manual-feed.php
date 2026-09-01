<?php
/**
 * The curated-links subscription: fetch a remote list and make it this site's.
 *
 * Thin on purpose. What a valid response is lives in
 * ADVTN_Manual_Feed_Parser, and what a valid link is lives in
 * ADVTN_Manual::validate() — this class owns the request, the state option and
 * the order of the commit.
 *
 * The commit is deliberately not new code. Rows go through
 * ADVTN_Manual::save(), which already validates each one, forgets rows that
 * left the list, syncs the items table and reschedules the expiry timer. A
 * second write path would be a second sanitization implementation, and the two
 * would drift.
 *
 * A failed fetch changes nothing. That is the whole contract: this runs
 * unattended on sites nobody is watching, and a feed that answers badly must
 * cost them nothing.
 *
 * @package Advision\TrendingNow
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ADVTN_Manual_Feed {

	public const OPTION_STATE = 'advtn_manual_feed_state';
	public const HOOK         = 'advtn_manual_feed_fetch';

	/** The one-shot retry a pushed fetch queues when it arrived behind. */
	public const HOOK_RETRY = 'advtn_manual_feed_sync_retry';

	/** Matches the plugin's other outbound requests. */
	public const USER_AGENT = 'AdvisionTrendingNow/1.0';

	/**
	 * Settings service.
	 *
	 * @var ADVTN_Settings
	 */
	private ADVTN_Settings $settings;

	/**
	 * Curated links service.
	 *
	 * @var ADVTN_Manual
	 */
	private ADVTN_Manual $manual;

	/**
	 * Constructor.
	 *
	 * @param ADVTN_Settings $settings Settings service.
	 * @param ADVTN_Manual   $manual   Curated links service.
	 */
	public function __construct( ADVTN_Settings $settings, ADVTN_Manual $manual ) {
		$this->settings = $settings;
		$this->manual   = $manual;
	}

	/**
	 * Bind the scheduled fetch.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( self::HOOK, array( $this, 'on_scheduled_fetch' ) );
		add_action( self::HOOK_RETRY, array( $this, 'on_sync_retry' ) );
	}

	/**
	 * The ETag to send on this request, or an empty string to ask outright.
	 *
	 * A forced fetch must ask unconditionally. `If-None-Match` is a claim about
	 * this site — "I already hold version N" — and a 304 answers by leaving the
	 * stored list untouched. But the stored list is exactly what a human reaching
	 * for --force has stopped trusting: they force a fetch *because* the site
	 * looks wrong. Sending the ETag anyway lets the feed reply "you already have
	 * it" to a site that has nothing, and the repair is refused in the one case
	 * it was asked for. Recovery would then have to wait for an unrelated edit
	 * upstream to change the version — on every site at once, and with nothing
	 * on screen explaining the wait.
	 *
	 * The interval gate is skipped for convenience. This one is skipped for
	 * correctness.
	 *
	 * @param string $stored ETag held from the last successful fetch.
	 * @param bool   $force  Whether this fetch was forced.
	 * @return string
	 */
	private static function conditional_etag( string $stored, bool $force ): string {
		return $force ? '' : $stored;
	}

	/**
	 * Known triggers.
	 *
	 * A closed vocabulary rather than a free string. The value lands in a log
	 * column on the far end, so anything this side cannot name is a value
	 * nobody chose — refused rather than sanitised, because sanitising invents
	 * a value where dropping admits there was none.
	 *
	 * @var array<int,string>
	 */
	private const TRIGGERS = array( 'sync', 'manual' );

	/**
	 * What this site says about itself on a feed fetch.
	 *
	 * Two query parameters, and they are the whole of the plugin's half of the
	 * subscriber roster. There is no new endpoint and no handshake: the feed
	 * already fetches every few hours, so identifying itself on that request
	 * costs nothing and needs no coordinated deploy — the feed ignores
	 * parameters it does not know, and a feed that does not know these two
	 * serves exactly what it served before.
	 *
	 * **`site` is `home_url()` and never a field somebody types.** A typed field
	 * is a field filled in wrong, and the far end turns this value into an
	 * address it will later contact — so a mistake here is a request aimed at
	 * somebody else's site. `home_url()` is the one value WordPress already
	 * holds and already uses to build every link it prints.
	 *
	 * Only the origin survives on the other side; the path is discarded there,
	 * so a subdirectory install sends its full home and loses the directory.
	 * That is recorded rather than worked around: measured across the network on
	 * 2026-09-01, no install is in a subdirectory.
	 *
	 * Empty values are omitted rather than sent blank. A parameter that is
	 * present and empty is a claim that this site has no address, where absent
	 * is the truthful "this plugin did not say".
	 *
	 * Pure and static so the decision is testable without WordPress; the URL is
	 * assembled by `add_query_arg()`, which is core's job and not worth a stub
	 * that could differ from it.
	 *
	 * @param string $home    The site's home URL.
	 * @param string $version The running plugin version.
	 * @param string $trigger Why this fetch is happening. One of TRIGGERS, or
	 *                        empty for the ordinary scheduled fetch.
	 * @return array<string,string>
	 */
	public static function identity( string $home, string $version, string $trigger = '' ): array {
		$identity = array();
		$home     = trim( $home );
		$version  = trim( $version );
		$trigger  = trim( $trigger );

		if ( '' !== $home ) {
			$identity['site'] = $home;
		}

		if ( '' !== $version ) {
			$identity['v'] = $version;
		}

		// A trigger alone says nothing about which site this is: it is a label
		// on the other two, not a third fact, so it is withheld when neither
		// site nor version made it into the identity.
		if ( ! empty( $identity ) && in_array( $trigger, self::TRIGGERS, true ) ) {
			$identity['trigger'] = $trigger;
		}

		return $identity;
	}

	/**
	 * This site's sync key, generating one the first time it is needed.
	 *
	 * Generated here rather than on activation, because a site that never
	 * subscribes to a feed never needs a push credential — and a credential
	 * that exists on every install is a credential on installs nobody is
	 * watching.
	 *
	 * A generation failure is not fatal. `random_bytes()` throws where no
	 * source of randomness is available, and the fetch is the product: a site
	 * that cannot invent a key still pulls its links, it just cannot be pushed.
	 * Hawkeye reads that absence as `unpushable` and reports it by name.
	 *
	 * @return string The key, or '' if one could not be made.
	 */
	public function ensure_sync_key(): string {
		$key = $this->settings->get_string( 'sync_key' );

		if ( ADVTN_Sync_Key::is_wellformed( $key ) ) {
			return $key;
		}

		try {
			$key = ADVTN_Sync_Key::generate();
		} catch ( Throwable $e ) {
			ADVTN_Logger::log( 'warning', 'Could not generate a sync key; this site cannot be pushed to.' );
			return '';
		}

		$this->settings->update( array( 'sync_key' => $key ) );

		return $key;
	}

	/**
	 * Should this site fetch once more, in a minute?
	 *
	 * Pure and static so the decision is testable without WordPress or a clock.
	 * The scheduling is the caller's job and is one line; this is the part worth
	 * holding in place.
	 *
	 * **The version is only compared when the slug matches.** A roster row on
	 * the far end can be stale — a site that moved from one feed to another is
	 * still listed under the old one until something prunes it — and two feeds'
	 * version counters are unrelated integers. Comparing them is comparing
	 * nothing, and it would schedule a retry that could never succeed.
	 *
	 * Numeric comparison, deliberately: as strings, '9' sorts after '41'.
	 *
	 * @param string $answered_slug    The slug the payload carried.
	 * @param string $expected_feed    The slug the pusher expected, or ''.
	 * @param string $answered_version The version the payload carried.
	 * @param string $expected_version The version the pusher expected, or ''.
	 * @return bool
	 */
	public static function retry_needed(
		string $answered_slug,
		string $expected_feed,
		string $answered_version,
		string $expected_version
	): bool {
		if ( '' === $answered_slug || '' === $expected_feed || $answered_slug !== $expected_feed ) {
			return false;
		}

		if ( ! is_numeric( $answered_version ) || ! is_numeric( $expected_version ) ) {
			return false;
		}

		return (float) $answered_version < (float) $expected_version;
	}

	/**
	 * Stored fetch state.
	 *
	 * @return array<string,mixed>
	 */
	public function state(): array {
		$state = get_option( self::OPTION_STATE, array() );

		return is_array( $state ) ? $state : array();
	}

	/**
	 * Whether enough time has passed since the last attempt.
	 *
	 * A due-check rather than a fixed clock time, matching the ingest cycle: a
	 * missed window runs late instead of being skipped. It counts from the last
	 * *attempt* rather than the last success, so a feed that is failing is not
	 * retried on every pageview.
	 *
	 * @return bool
	 */
	public function is_due(): bool {
		return null === $this->next_due();
	}

	/**
	 * When the next fetch is allowed, or null when one is due now.
	 *
	 * @return int|null Unix timestamp.
	 */
	public function next_due(): ?int {
		$last = (string) ( $this->state()['last_attempt_at'] ?? '' );

		if ( '' === $last ) {
			return null;
		}

		$timestamp = strtotime( $last . ' UTC' );

		if ( false === $timestamp ) {
			return null;
		}

		$next = $timestamp + ( $this->settings->get_int( 'manual_feed_interval_hours', 1, 168 ) * HOUR_IN_SECONDS );

		return $next <= time() ? null : $next;
	}

	/**
	 * Fetch the feed and, if it answered properly, make it this site's list.
	 *
	 * @param bool   $force   Skip the due-check and the stored ETag. Never skips
	 *                        the validity check: a forced fetch still has to be
	 *                        answered by something shaped like a feed before
	 *                        anything is kept.
	 * @param string $trigger Why this fetch is happening. Passed through to
	 *                        identity() so the request log can say why.
	 * @return array{status:string,message:string,count:int,skipped:int,feed:string,version:string}
	 */
	public function fetch( bool $force = false, string $trigger = '' ): array {
		if ( ! $this->settings->feed_is_active() ) {
			return $this->outcome( 'subscribed_off', __( 'This site is not subscribed to a feed.', 'trending-now' ) );
		}

		if ( ! $force && ! $this->is_due() ) {
			return $this->outcome( 'not_due', __( 'The feed is not due yet.', 'trending-now' ) );
		}

		$url = $this->settings->get_string( 'manual_feed_url' );

		if ( ! ADVTN_URL::is_valid( $url ) ) {
			return $this->fail( __( 'The feed URL is not a valid public http(s) address.', 'trending-now' ), null, 0 );
		}

		$response = $this->request( $url, $force, $trigger );

		if ( is_wp_error( $response['response'] ) ) {
			return $this->fail( $response['response']->get_error_message(), null, $response['ms'] );
		}

		// A 304 means the version already held is current. Nothing to commit,
		// and deliberately not a failure: the attempt counts, so the next one is
		// due from here.
		if ( 304 === $response['code'] ) {
			$this->write_state(
				array(
					'last_attempt_at' => gmdate( 'Y-m-d H:i:s' ),
					'http_code'       => 304,
					'error'           => '',
				),
				true,
				$response['ms'],
				304,
				''
			);

			return array(
				'status'  => 'not_modified',
				'message' => __( 'The feed has not changed since the last fetch.', 'trending-now' ),
				'count'   => 0,
				'skipped' => 0,
				'feed'    => (string) ( $this->state()['feed'] ?? '' ),
				'version' => (string) ( $this->state()['version'] ?? '' ),
			);
		}

		if ( 200 !== $response['code'] ) {
			/*
			 * A 401 does not mean the token is wrong.
			 *
			 * The feed answers 401 for a gated feed *and* for one that does not
			 * exist, deliberately: telling them apart would let anybody map the
			 * network's feed names by guessing slugs. So this is the one refusal
			 * the plugin cannot diagnose, and saying "check the token" would send
			 * somebody to look at the one field that may be perfectly correct —
			 * a mistyped slug produces exactly this.
			 */
			$message = ( 401 === $response['code'] || 403 === $response['code'] )
				? __( 'The feed refused this request. Either the token is wrong or missing, or the feed named in the URL does not exist — the server answers the same way for both on purpose, so check the slug as well as the token.', 'trending-now' )
				/* translators: %d: HTTP status code. */
				: sprintf( __( 'The feed returned HTTP %d.', 'trending-now' ), (int) $response['code'] );

			return $this->fail( $message, $response['code'], $response['ms'] );
		}

		$parsed = ADVTN_Manual_Feed_Parser::parse( $response['body'] );

		if ( ! $parsed['ok'] ) {
			return $this->fail( $parsed['error'], $response['code'], $response['ms'] );
		}

		return $this->commit( $parsed, $response );
	}

	/**
	 * Commit a validated payload.
	 *
	 * An empty list is honoured. "Empty means empty" is the plugin's rule
	 * everywhere else, and clearing every subscribed site at once is something
	 * somebody will legitimately want to do — but it is logged as a warning,
	 * because it is also what an upstream mistake looks like.
	 *
	 * @param array<string,mixed>                                                       $parsed   Parser result.
	 * @param array{response:array|WP_Error,code:int|null,body:string,ms:int,etag:string} $response Transport result.
	 * @return array{status:string,message:string,count:int,skipped:int,feed:string,version:string}
	 */
	private function commit( array $parsed, array $response ): array {
		$result = $this->manual->save( $parsed['rows'] );

		advtn()->selector()->build_and_commit();
		advtn()->renderer()->purge_cache();
		ADVTN_Page_Cache::purge();

		$stored  = count( $result['links'] );
		$skipped = (int) $parsed['skipped'] + count( $result['errors'] );

		$this->write_state(
			array(
				'last_attempt_at' => gmdate( 'Y-m-d H:i:s' ),
				'last_success_at' => gmdate( 'Y-m-d H:i:s' ),
				'http_code'       => (int) $response['code'],
				'error'           => '',
				'item_count'      => $stored,
				'skipped'         => $skipped,
				'feed'            => (string) $parsed['slug'],
				'version'         => (string) $parsed['version'],
				'etag'            => (string) $response['etag'],
			),
			true,
			(int) $response['ms'],
			(int) $response['code'],
			''
		);

		if ( 0 === $stored ) {
			ADVTN_Logger::log( 'warning', 'Feed fetch returned no usable links; the curated list is now empty.' );
		} else {
			ADVTN_Logger::log( 'info', sprintf( 'Feed fetch stored %d curated link(s), skipped %d.', $stored, $skipped ) );
		}

		return array(
			'status'  => 'ok',
			/* translators: 1: stored count, 2: skipped count. */
			'message' => sprintf( __( 'Stored %1$d link(s), skipped %2$d.', 'trending-now' ), $stored, $skipped ),
			'count'   => $stored,
			'skipped' => $skipped,
			'feed'    => (string) $parsed['slug'],
			'version' => (string) $parsed['version'],
		);
	}

	/**
	 * Perform the request.
	 *
	 * `wp_remote_get` rather than `wp_safe_remote_get`, matching
	 * ADVTN_Source_Base: the URL is configuration an administrator typed, not a
	 * value taken from a feed.
	 *
	 * The Authorization header is omitted entirely when no token is configured,
	 * rather than sent empty — a public feed needs none, and an empty bearer is
	 * a credential that looks present and is not.
	 *
	 * @param string $url     Feed URL.
	 * @param bool   $force   Ask unconditionally, ignoring the stored ETag.
	 * @param string $trigger Why this fetch is happening, passed through to identity().
	 * @return array{response:array|WP_Error,code:int|null,body:string,ms:int,etag:string}
	 */
	private function request( string $url, bool $force = false, string $trigger = '' ): array {
		$started = microtime( true );
		$token   = $this->settings->get_secret( 'manual_feed_token' );
		$etag    = self::conditional_etag( (string) ( $this->state()['etag'] ?? '' ), $force );

		// Appended rather than concatenated: the configured URL already carries
		// `?feed=<slug>`, and add_query_arg() is what gets the separator right
		// whether or not it does.
		$url = add_query_arg( self::identity( home_url(), ADVTN_VERSION, $trigger ), $url );

		$headers = array( 'Accept' => 'application/json' );

		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		/*
		 * The key travels on the fetch this site already makes, which is the
		 * whole reason nothing has to be distributed to fifty installs: Hawkeye
		 * learns it by being called. A header rather than a query parameter —
		 * a parameter lands in the far end's access logs and in any CDN in
		 * front of them, the same reason the feed token is in Authorization.
		 */
		$sync_key = $this->ensure_sync_key();

		if ( '' !== $sync_key ) {
			$headers[ ADVTN_Sync_Key::HEADER ] = $sync_key;
		}

		if ( '' !== $etag ) {
			$headers['If-None-Match'] = $etag;
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => $this->settings->get_int( 'http_timeout', 1, 60 ),
				'redirection' => 3,
				'user-agent'  => self::USER_AGENT,
				'headers'     => $headers,
			)
		);

		$ms = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( is_wp_error( $response ) ) {
			return array(
				'response' => $response,
				'code'     => null,
				'body'     => '',
				'ms'       => $ms,
				'etag'     => '',
			);
		}

		return array(
			'response' => $response,
			'code'     => (int) wp_remote_retrieve_response_code( $response ),
			'body'     => (string) wp_remote_retrieve_body( $response ),
			'ms'       => $ms,
			'etag'     => (string) wp_remote_retrieve_header( $response, 'etag' ),
		);
	}

	/**
	 * Record a failure. The stored list is untouched.
	 *
	 * @param string   $message Reason.
	 * @param int|null $code    HTTP status, null on a transport error.
	 * @param int      $ms      Elapsed milliseconds.
	 * @return array{status:string,message:string,count:int,skipped:int,feed:string,version:string}
	 */
	private function fail( string $message, ?int $code, int $ms ): array {
		$message = $this->redact( $message );

		$this->write_state(
			array(
				'last_attempt_at' => gmdate( 'Y-m-d H:i:s' ),
				'http_code'       => $code,
				'error'           => $message,
			),
			false,
			$ms,
			$code,
			$message
		);

		ADVTN_Logger::log( 'error', 'Feed fetch failed: ' . $message );

		return array(
			'status'  => 'failed',
			'message' => $message,
			'count'   => 0,
			'skipped' => 0,
			'feed'    => '',
			'version' => '',
		);
	}

	/**
	 * Remove the token from a string bound for a log or a screen.
	 *
	 * ADVTN_Logger scrubs keys, but a token inside a message body — a cURL
	 * error quoting the URL it was handed, for instance — is not a key.
	 *
	 * @param string $message Raw message.
	 * @return string
	 */
	private function redact( string $message ): string {
		$token = $this->settings->get_secret( 'manual_feed_token' );

		return '' === $token ? $message : str_replace( $token, '[redacted]', $message );
	}

	/**
	 * Merge a state patch and append to the attempt ring.
	 *
	 * The ring goes through ADVTN_Attempts::record() so it cannot drift from
	 * the one the Sources tab shows: the cap and the error truncation are
	 * defined once.
	 *
	 * @param array<string,mixed> $patch Fields to write.
	 * @param bool                $ok    Whether the attempt succeeded.
	 * @param int                 $ms    Elapsed milliseconds.
	 * @param int|null            $code  HTTP status.
	 * @param string              $error Error message.
	 * @return void
	 */
	private function write_state( array $patch, bool $ok, int $ms, ?int $code, string $error ): void {
		$state = $this->state();
		$ring  = isset( $state['attempts'] ) && is_array( $state['attempts'] ) ? $state['attempts'] : array();

		$state             = array_merge( $state, $patch );
		$state['attempts'] = ADVTN_Attempts::record( $ring, $ok, $ms, $code, $error );

		update_option( self::OPTION_STATE, $state, false );
	}

	/**
	 * A non-fetch outcome.
	 *
	 * @param string $status  Status key.
	 * @param string $message Human-readable reason.
	 * @return array{status:string,message:string,count:int,skipped:int,feed:string,version:string}
	 */
	private function outcome( string $status, string $message ): array {
		return array(
			'status'  => $status,
			'message' => $message,
			'count'   => 0,
			'skipped' => 0,
			'feed'    => '',
			'version' => '',
		);
	}

	/* ---------------------------------------------------------------------
	 * Scheduling
	 * ------------------------------------------------------------------ */

	/**
	 * The scheduled entry point. Respects the due-check.
	 *
	 * @return void
	 */
	public function on_scheduled_fetch(): void {
		$this->fetch( false );
	}

	/**
	 * The one-shot retry after a pushed fetch arrived behind.
	 *
	 * Forced, because the point is to get past the far end's serving cache and
	 * a due-check would refuse — the ordinary fetch happened a minute ago. The
	 * outcome goes to the state and the log like any other; nobody is waiting on
	 * a response, so there is nothing to return.
	 *
	 * @return void
	 */
	public function on_sync_retry(): void {
		$this->fetch( true, 'sync' );
	}

	/**
	 * Register or clear the recurring fetch to match the current settings.
	 *
	 * The interval is baked in when the action is scheduled, so this runs
	 * whenever the setting changes — see the `advtn_reschedule_feed` flag in
	 * ADVTN_Settings::update().
	 *
	 * The interval it scheduled is recorded, because this also runs on every
	 * `init`: unscheduling and rescheduling each time would push the next run a
	 * minute further out on every pageview and it would never fire.
	 *
	 * @return void
	 */
	public function reschedule(): void {
		$scheduler = advtn()->scheduler();
		$interval  = $this->settings->get_int( 'manual_feed_interval_hours', 1, 168 ) * HOUR_IN_SECONDS;
		$state     = $this->state();

		if ( ! $this->settings->feed_is_active() ) {
			$scheduler->unschedule( self::HOOK );
			update_option( self::OPTION_STATE, array_merge( $state, array( 'scheduled_interval' => 0 ) ), false );

			return;
		}

		if ( (int) ( $state['scheduled_interval'] ?? 0 ) !== $interval ) {
			$scheduler->unschedule( self::HOOK );
			update_option( self::OPTION_STATE, array_merge( $state, array( 'scheduled_interval' => $interval ) ), false );
		}

		$scheduler->schedule_recurring( time() + MINUTE_IN_SECONDS, $interval, self::HOOK );
	}
}
