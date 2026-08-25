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
	 * @param bool $force Skip the due-check and the stored ETag. Never skips the
	 *                    validity check: a forced fetch still has to be answered
	 *                    by something shaped like a feed before anything is kept.
	 * @return array{status:string,message:string,count:int,skipped:int}
	 */
	public function fetch( bool $force = false ): array {
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

		$response = $this->request( $url, $force );

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

			return $this->outcome( 'not_modified', __( 'The feed has not changed since the last fetch.', 'trending-now' ) );
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
	 * @return array{status:string,message:string,count:int,skipped:int}
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
	 * @param string $url   Feed URL.
	 * @param bool   $force  Ask unconditionally, ignoring the stored ETag.
	 * @return array{response:array|WP_Error,code:int|null,body:string,ms:int,etag:string}
	 */
	private function request( string $url, bool $force = false ): array {
		$started = microtime( true );
		$token   = $this->settings->get_secret( 'manual_feed_token' );
		$etag    = self::conditional_etag( (string) ( $this->state()['etag'] ?? '' ), $force );

		$headers = array( 'Accept' => 'application/json' );

		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
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
	 * @return array{status:string,message:string,count:int,skipped:int}
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
	 * @return array{status:string,message:string,count:int,skipped:int}
	 */
	private function outcome( string $status, string $message ): array {
		return array(
			'status'  => $status,
			'message' => $message,
			'count'   => 0,
			'skipped' => 0,
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
