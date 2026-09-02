<?php
/**
 * REST controller for the advtn/v1 namespace.
 *
 * @package Advision\TrendingNow
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ADVTN_REST {

	public const NAMESPACE_V1     = 'advtn/v1';
	public const OPTION_HUB_ITEMS = 'advtn_hub_items_cache';
	public const HUB_CACHE_MAX    = 500;

	/**
	 * How long to wait before the one retry, in seconds.
	 *
	 * Sixty, matching the far end's serving cache, plus nothing: the cache TTL
	 * is the invalidation, so a fetch after it has elapsed reads Firestore.
	 */
	private const SYNC_RETRY_DELAY = 60;

	/**
	 * Seconds between log lines for refused pushes.
	 *
	 * An hour, not the rate-limit window. The log is a 200-row ring shared with
	 * everything else this plugin records, and one line per five minutes under
	 * sustained probing would fill it in under a day.
	 */
	private const SYNC_REFUSAL_LOG_INTERVAL = HOUR_IN_SECONDS;

	/**
	 * Settings service.
	 *
	 * @var ADVTN_Settings
	 */
	private ADVTN_Settings $settings;

	/**
	 * Repository service.
	 *
	 * @var ADVTN_Repository
	 */
	private ADVTN_Repository $repository;

	/**
	 * Constructor.
	 *
	 * @param ADVTN_Settings   $settings   Settings service.
	 * @param ADVTN_Repository $repository Repository service.
	 */
	public function __construct( ADVTN_Settings $settings, ADVTN_Repository $repository ) {
		$this->settings   = $settings;
		$this->repository = $repository;
	}

	/**
	 * Register all five routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE_V1,
			'/ingest',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_ingest' ),
				'permission_callback' => array( $this, 'authorize_ingest' ),
				'args'                => array(
					'force'  => array(
						'type'     => 'boolean',
						'default'  => false,
						'required' => false,
					),
					'source' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => static fn( $v ) => (string) preg_replace( '/[^a-z0-9_]/', '', (string) $v ),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/feed-fetch',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_feed_fetch' ),
				'permission_callback' => array( $this, 'authorize_feed_fetch' ),
				'args'                => array(
					'force' => array(
						'type'     => 'boolean',
						'default'  => true,
						'required' => false,
					),
				),
			)
		);

		/*
		 * A new route with its own credential, and not an argument to
		 * /feed-fetch.
		 *
		 * /feed-fetch is HMAC-signed with ingest_secret, and that secret also
		 * authorizes /ingest and /status. The far end holding one per site would
		 * be a store of credentials that can trigger ingest across the whole
		 * network and read each site's source configuration. This route does one
		 * thing and holds a credential that can do only that thing.
		 */
		register_rest_route(
			self::NAMESPACE_V1,
			'/sync',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_sync' ),
				'permission_callback' => array( $this, 'authorize_sync' ),
				'args'                => array(
					/*
					 * Both are labels, never instructions. Neither can change
					 * what this site fetches — the URL is this site's own
					 * setting — and that is the only reason they are safe to
					 * accept from a caller at all.
					 */
					'expectedFeed'    => array(
						'type'              => 'string',
						'required'          => false,
						'default'           => '',
						// Cast, like the four other preg_replace sanitizers in
						// this codebase. preg_replace() returns string|null —
						// only on a backtrack limit or malformed UTF-8, but the
						// declared type here is 'string' and a null stored
						// against it is a lie the next reader inherits.
						'sanitize_callback' => static fn( $v ) => (string) preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $v ) ),
					),
					'expectedVersion' => array(
						'type'     => 'string',
						'required' => false,
						'default'  => '',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/items',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_items' ),
				'permission_callback' => array( $this, 'authorize_items' ),
				'args'                => array(
					'limit'        => array(
						'type'    => 'integer',
						'default' => 200,
						'minimum' => 1,
						'maximum' => 500,
					),
					'exclude_host' => array( 'type' => 'string' ),
					'since'        => array( 'type' => 'string' ),
					'types'        => array( 'type' => 'string' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_status' ),
				'permission_callback' => array( $this, 'authorize_status' ),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Authorization
	 * ------------------------------------------------------------------ */

	/**
	 * Signature check for /ingest.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function authorize_ingest( WP_REST_Request $request ) {
		return ADVTN_HMAC::verify( $request, $this->settings->get_string( 'ingest_secret' ), 'ingest' );
	}

	/**
	 * Signature check for /feed-fetch.
	 *
	 * The same secret as /ingest. Both are "an external scheduler telling this
	 * site to do its job now", and a second secret would be a second thing to
	 * rotate across the network for no gain in what it protects. The endpoint
	 * name differs, so the two keep separate rate-limit buckets.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function authorize_feed_fetch( WP_REST_Request $request ) {
		return ADVTN_HMAC::verify( $request, $this->settings->get_string( 'ingest_secret' ), 'feed-fetch' );
	}

	/**
	 * Signature check for /status.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function authorize_status( WP_REST_Request $request ) {
		return ADVTN_HMAC::verify( $request, $this->settings->get_string( 'ingest_secret' ), 'status' );
	}

	/**
	 * Fetch the curated-links feed now.
	 *
	 * Defaults to forcing, because an explicit trigger means now — the local
	 * timer is the thing that respects the due-check. This route exists for
	 * hosts where WP-Cron only ticks when somebody visits, which on a quiet
	 * site is not a schedule; it is the escape hatch, not the mechanism.
	 *
	 * A failed fetch answers 502 rather than 200-with-a-failure. A caller that
	 * has to read the body to learn it failed is a caller that will not.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_feed_fetch( WP_REST_Request $request ): WP_REST_Response {
		$result = advtn()->manual_feed()->fetch( (bool) $request->get_param( 'force' ), 'rest' );

		return new WP_REST_Response( $result, 'failed' === $result['status'] ? 502 : 200 );
	}

	/**
	 * Make this site re-read its feed now.
	 *
	 * `fetch( true )` skips the due-check *and* the stored ETag. The second is
	 * not a convenience: If-None-Match is this site asserting "I already hold
	 * version N", which is exactly the assertion somebody forcing a fetch has
	 * stopped believing.
	 *
	 * **The retry is scheduled, not slept through.** The far end's serving cache
	 * is 60 seconds with no invalidation available, so a push fired just after a
	 * save hands this site the previous version. Waiting 60 seconds inside the
	 * request would put the response past a default nginx `fastcgi_read_timeout`
	 * of 60 s — the pusher would read a 504 as a failed push and prune a site
	 * that had synced correctly. Scheduling answers immediately and lets this
	 * site repair itself.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_sync( WP_REST_Request $request ): WP_REST_Response {
		$result = advtn()->manual_feed()->fetch( true, 'sync' );

		$retry_queued = false;
		$retry_at     = 0;

		if ( ADVTN_Manual_Feed::retry_needed(
			(string) $result['feed'],
			(string) $request->get_param( 'expectedFeed' ),
			(string) $result['version'],
			(string) $request->get_param( 'expectedVersion' )
		) ) {
			/*
			 * Through ADVTN_Scheduler, not `wp_schedule_single_event()`.
			 *
			 * Every other deferred unit of work in this plugin goes through it,
			 * and it prefers Action Scheduler, which is what puts the pending
			 * retry in `pending_summary()` — the Diagnostics queue panel and
			 * `status_payload()['pending_queue']`. An operator asking "the push
			 * said stale, did the retry run?" had nowhere to look before.
			 *
			 * The pending check has to go through the same class for the same
			 * reason. Where Action Scheduler is available `schedule_single()`
			 * queues there and WP-Cron knows nothing about it, so the old bare
			 * `wp_next_scheduled()` guard would answer "nothing pending" on
			 * every push and queue without limit — the opposite of the property
			 * it was written to hold.
			 */
			$scheduler = advtn()->scheduler();
			$retry_at  = $scheduler->next_scheduled( ADVTN_Manual_Feed::HOOK_RETRY );

			// Exactly one, and only if nothing is already queued. A retry that
			// retried would turn a version that never moves into a site that
			// never stops fetching.
			if ( 0 === $retry_at ) {
				$scheduler->schedule_single( time() + self::SYNC_RETRY_DELAY, ADVTN_Manual_Feed::HOOK_RETRY );
				$retry_at = $scheduler->next_scheduled( ADVTN_Manual_Feed::HOOK_RETRY );
			}

			// Reported, not assumed. This used to be a flat `true` set outside
			// the guard, so the answer was identical whether a retry had just
			// been queued, was queued and will run, was queued and never will,
			// or had failed to queue at all. `retry_at` tells those apart: a
			// timestamp in the past is a retry that is overdue, which is what a
			// host with a blocked loopback looks like, and a stale site that
			// promises a repair forever was how that state stayed invisible.
			$retry_queued     = $retry_at > 0;
			$result['status'] = 'stale';
		}

		return new WP_REST_Response(
			array(
				'status'       => $result['status'],
				'feed'         => $result['feed'],
				'version'      => $result['version'],
				'count'        => $result['count'],
				'skipped'      => $result['skipped'],
				'retry_queued' => $retry_queued,
				'retry_at'     => $retry_at,
			),
			'failed' === $result['status'] ? 502 : 200
		);
	}

	/**
	 * Sync-key check for /sync.
	 *
	 * The rate limit runs first and unconditionally, so a caller cannot use the
	 * endpoint as an oracle that answers faster when the key is absent than when
	 * it is merely wrong.
	 *
	 * One refusal for every reason. "No key configured", "no header sent" and
	 * "wrong key" are the same answer, because distinguishing them tells an
	 * unauthenticated caller which sites have been pushed to before.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function authorize_sync( WP_REST_Request $request ) {
		/*
		 * First, and before the header is even read. The cost of that is real
		 * and is accepted here rather than left implicit: the bucket is per
		 * endpoint and not per caller, so every refused anonymous probe spends
		 * a slot a legitimate push needs, and 30 requests per 300 seconds from
		 * one client is enough to keep it spent.
		 *
		 * It runs first anyway because moving it after the key check turns the
		 * endpoint into a timing oracle that answers faster for a site that has
		 * no key than for one whose key is merely wrong — which is exactly the
		 * map of "which sites have been pushed to" that the single refusal
		 * message exists to withhold. Making the bucket per caller would fix
		 * both, but a caller cannot be identified without something it cannot
		 * forge, and choosing that is a spec decision rather than a fix.
		 *
		 * What is done about it here is to make the state legible:
		 * `record_sync()` below counts refusals where an operator can see them.
		 */
		$rate = ADVTN_HMAC::rate_limit( 'sync' );

		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		$presented = (string) $request->get_header( ADVTN_Sync_Key::HEADER );

		if ( ! ADVTN_Sync_Key::matches(
			$presented,
			$this->settings->get_string( 'sync_key' ),
			$this->settings->get_string( 'sync_key_previous' )
		) ) {
			$this->record_sync_refusal();

			return new WP_Error(
				'advtn_sync_refused',
				__( 'This request was not accepted.', 'trending-now' ),
				array( 'status' => 401 )
			);
		}

		advtn()->manual_feed()->record_sync( true );

		return true;
	}

	/**
	 * Note a refused push, and log it if it opens a new burst.
	 *
	 * The marker is written every time; the log line is throttled. An
	 * internet-facing route that logged every refusal would let an
	 * unauthenticated caller evict the whole 200-row ring, so the count in the
	 * state option carries the magnitude and the log carries the fact.
	 *
	 * The refusal itself is unchanged by this: same message, same 401, no
	 * branch on which reason it was. One refusal for every reason is a property
	 * of the *response*, and nothing here reaches the response.
	 *
	 * @return void
	 */
	private function record_sync_refusal(): void {
		$feed  = advtn()->manual_feed();
		$state = $feed->state();

		$log = ADVTN_Manual_Feed::refusal_is_new_burst(
			(string) ( $state['last_sync_refused_at'] ?? '' ),
			time(),
			self::SYNC_REFUSAL_LOG_INTERVAL
		);

		$feed->record_sync( false );

		if ( $log ) {
			// Matches ADVTN_HMAC::verify()'s line for the signed routes. The
			// route left no trace at all when probed, where every other
			// authenticated route in this plugin left one.
			ADVTN_Logger::log( 'warning', 'Refused a push to /sync: the presented key did not match.' );
		}
	}

	/**
	 * Hub-only signature check for /items.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function authorize_items( WP_REST_Request $request ) {
		if ( 'hub' !== $this->settings->get_string( 'mode' ) ) {
			return new WP_Error(
				'advtn_not_hub',
				__( 'This site is not running in hub mode.', 'trending-now' ),
				array( 'status' => 403 )
			);
		}

		return ADVTN_HMAC::verify( $request, $this->settings->get_string( 'hub_secret' ), 'items' );
	}

	/* ---------------------------------------------------------------------
	 * Handlers
	 * ------------------------------------------------------------------ */

	/**
	 * Trigger an ingest cycle. Same code path as cron, different adapter.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_ingest( WP_REST_Request $request ): WP_REST_Response {
		$force  = (bool) $request->get_param( 'force' );
		$source = (string) $request->get_param( 'source' );

		$result = advtn()->ingest()->run( $force, '' !== $source ? $source : null );

		switch ( $result['status'] ) {
			case 'locked':
				return new WP_REST_Response(
					array(
						'scheduled'        => array(),
						'cycle_due'        => $result['cycle_due'],
						'lock_age_seconds' => $result['lock_age_seconds'],
						'message'          => __( 'An ingest cycle is already running.', 'trending-now' ),
					),
					409
				);

			case 'scheduled':
				return new WP_REST_Response(
					array(
						'scheduled' => $result['scheduled'],
						'cycle_due' => true,
					),
					202
				);

			case 'error':
				return new WP_REST_Response(
					array(
						'scheduled' => array(),
						'message'   => __( 'Failed to schedule the ingest cycle. See the plugin log.', 'trending-now' ),
					),
					500
				);

			default:
				return new WP_REST_Response(
					array(
						'scheduled'   => array(),
						'cycle_due'   => false,
						'last_ingest' => $result['last_ingest'],
					),
					200
				);
		}
	}

	/**
	 * Serve the assembled item list to spokes, from cache.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_items( WP_REST_Request $request ): WP_REST_Response {
		$cache = get_option( self::OPTION_HUB_ITEMS, null );

		if ( ! is_array( $cache ) || ! isset( $cache['items'] ) ) {
			$cache = $this->rebuild_items_cache();
		}

		$items = is_array( $cache['items'] ) ? $cache['items'] : array();

		$exclude = array_values(
			array_filter(
				array_map(
					static function ( $host ) {
						$host = strtolower( trim( (string) $host ) );
						if ( '' === $host ) {
							return '';
						}
						// Accept a bare host or a full URL.
						return ADVTN_URL::host( false !== strpos( $host, '//' ) ? $host : 'https://' . $host );
					},
					explode( ',', (string) $request->get_param( 'exclude_host' ) )
				)
			)
		);

		$types = array_filter( array_map( 'trim', explode( ',', (string) $request->get_param( 'types' ) ) ) );

		$since     = (string) $request->get_param( 'since' );
		$since_ts  = '' !== $since ? strtotime( $since ) : false;
		$limit     = max( 1, min( 500, (int) $request->get_param( 'limit' ) ) );
		$filtered  = array();

		foreach ( $items as $item ) {
			if ( ! empty( $exclude ) && in_array( (string) ( $item['host'] ?? '' ), $exclude, true ) ) {
				continue;
			}

			if ( ! empty( $types ) && ! in_array( (string) ( $item['source_type'] ?? '' ), $types, true ) ) {
				continue;
			}

			if ( false !== $since_ts ) {
				$published = strtotime( (string) ( $item['published_at'] ?? '' ) . ' UTC' );
				if ( false === $published || $published < $since_ts ) {
					continue;
				}
			}

			$filtered[] = $item;

			if ( count( $filtered ) >= $limit ) {
				break;
			}
		}

		return new WP_REST_Response(
			array(
				'generated_at' => (string) ( $cache['generated_at'] ?? gmdate( 'c' ) ),
				'count'        => count( $filtered ),
				'items'        => $filtered,
			),
			200
		);
	}

	/**
	 * Diagnostics payload for n8n monitoring.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_status(): WP_REST_Response {
		return new WP_REST_Response( $this->status_payload(), 200 );
	}

	/**
	 * Everything the diagnostics panel shows, as an array.
	 *
	 * @return array<string,mixed>
	 */
	public function status_payload(): array {
		$last_ingest = (string) get_option( ADVTN_Ingest::OPTION_LAST_INGEST, '' );
		$last_ts     = '' !== $last_ingest ? strtotime( $last_ingest . ' UTC' ) : false;

		return array(
			'plugin_version'     => ADVTN_VERSION,
			'db_version'         => (string) get_option( ADVTN_Schema::VERSION_OPTION, '0' ),
			'mode'               => $this->settings->get_string( 'mode' ),
			'last_ingest'        => $last_ingest,
			'last_ingest_age_h'  => false !== $last_ts ? round( ( time() - $last_ts ) / HOUR_IN_SECONDS, 2 ) : null,
			'ingest_due'         => advtn()->ingest()->is_due(),
			'sources'            => $this->settings->state(),
			'source_count'       => count( $this->settings->enabled_sources() ),
			'counts'             => $this->repository->counts(),
			'counts_by_source'   => $this->repository->counts_by_source(),
			'selection_size'     => count( advtn()->selector()->current_ids() ),
			'cache'              => advtn()->renderer()->cache_status(),
			'cache_populated'    => ! empty( array_filter( advtn()->renderer()->cache_status() ) ),
			'lock_held'          => ADVTN_Lock::is_held(),
			'lock_age_seconds'   => ADVTN_Lock::age(),
			'loopback_ok'        => self::loopback_ok(),
			'wp_cron_enabled'    => ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ),
			'action_scheduler'   => advtn()->scheduler()->has_action_scheduler(),
			'pending_actions'    => advtn()->scheduler()->pending_count(),
			'pending_queue'      => advtn()->scheduler()->pending_summary(),
			'table_exists'       => ADVTN_Schema::table_exists(),
		);
	}

	/**
	 * Rebuild the cached hub payload. Called from finalize.
	 *
	 * @return array{generated_at:string,items:array<int,array<string,mixed>>}
	 */
	public function rebuild_items_cache(): array {
		$payload = array(
			'generated_at' => gmdate( 'c' ),
			'items'        => $this->repository->hub_items( self::HUB_CACHE_MAX ),
		);

		update_option( self::OPTION_HUB_ITEMS, $payload, false );

		return $payload;
	}

	/**
	 * Loopback test result, cached for five minutes.
	 *
	 * Action Scheduler's runner depends on loopback requests succeeding; hosts
	 * with HTTP auth, aggressive WAFs or firewalled self-requests break it.
	 *
	 * @param bool $force Recheck even when a cached result exists.
	 * @return bool
	 */
	public static function loopback_ok( bool $force = false ): bool {
		$cached = get_transient( 'advtn_loopback_ok' );

		if ( ! $force && false !== $cached ) {
			return '1' === (string) $cached;
		}

		$response = wp_remote_get(
			home_url( '/' ),
			array(
				'timeout'   => 10,
				'sslverify' => false,
				'headers'   => array( 'Cache-Control' => 'no-cache' ),
			)
		);

		$ok = ! is_wp_error( $response ) && (int) wp_remote_retrieve_response_code( $response ) < 500;

		set_transient( 'advtn_loopback_ok', $ok ? '1' : '0', 5 * MINUTE_IN_SECONDS );

		return $ok;
	}
}
