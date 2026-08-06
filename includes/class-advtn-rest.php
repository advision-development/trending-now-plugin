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
	 * Register all three routes.
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
						'sanitize_callback' => static fn( $v ) => preg_replace( '/[^a-z0-9_]/', '', (string) $v ),
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
	 * Signature check for /status.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function authorize_status( WP_REST_Request $request ) {
		return ADVTN_HMAC::verify( $request, $this->settings->get_string( 'ingest_secret' ), 'status' );
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
