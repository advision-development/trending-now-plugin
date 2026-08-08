<?php
/**
 * Ingest orchestration: due-check, per-source fetch, finalize.
 *
 * @package Advision\TrendingNow
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ADVTN_Ingest {

	public const OPTION_LAST_INGEST = 'advtn_last_ingest';

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
	 * Start a cycle: due-check, lock, enqueue staggered per-source jobs.
	 *
	 * @param bool        $force       Bypass the due-check (never the lock).
	 * @param string|null $only_source Restrict the cycle to one source id.
	 * @return array{status:string,scheduled:array<int,string>,cycle_due:bool,last_ingest:string,lock_age_seconds:int|null}
	 */
	public function run( bool $force = false, ?string $only_source = null ): array {
		$last_ingest = (string) get_option( self::OPTION_LAST_INGEST, '' );
		$due         = $this->is_due();

		$response = array(
			'status'           => 'not_due',
			'scheduled'        => array(),
			'cycle_due'        => $due,
			'last_ingest'      => $last_ingest,
			'lock_age_seconds' => null,
		);

		if ( ! $due && ! $force ) {
			return $response;
		}

		if ( ! ADVTN_Lock::acquire() ) {
			$response['status']           = 'locked';
			$response['lock_age_seconds'] = ADVTN_Lock::age();
			return $response;
		}

		try {
			$sources = $this->cycle_sources();

			if ( null !== $only_source ) {
				$sources = array_values(
					array_filter( $sources, static fn( $s ) => ( $s['id'] ?? '' ) === $only_source )
				);
			}

			$stagger   = $this->settings->get_int( 'stagger_minutes', 0, 120 ) * MINUTE_IN_SECONDS;
			$scheduler = advtn()->scheduler();
			$now       = time();
			$scheduled = array();
			$slot      = 0;

			foreach ( $sources as $source ) {
				$source_id = (string) ( $source['id'] ?? '' );
				if ( '' === $source_id ) {
					continue;
				}

				if ( $this->in_backoff( $source_id ) ) {
					ADVTN_Logger::log( 'info', 'Source skipped: still in failure backoff.', array( 'source_id' => $source_id ) );
					continue;
				}

				$offset = (int) ( $source['stagger_index'] ?? $slot ) * $stagger;
				$scheduler->schedule_single( $now + $offset, ADVTN_Scheduler::HOOK_SOURCE, array( $source_id ) );

				$scheduled[] = $source_id;
				++$slot;
			}

			// Finalize always runs, even with nothing scheduled, because it is
			// what releases the lock.
			$finalize_at = $now + ( max( 1, count( $sources ) ) * $stagger ) + 300;
			$scheduler->schedule_single( $finalize_at, ADVTN_Scheduler::HOOK_FINALIZE );

			$response['status']    = 'scheduled';
			$response['scheduled'] = $scheduled;
			$response['cycle_due'] = true;

			ADVTN_Logger::log(
				'info',
				'Ingest cycle scheduled.',
				array(
					'sources'     => count( $scheduled ),
					'forced'      => $force,
					'finalize_at' => gmdate( 'Y-m-d H:i:s', $finalize_at ),
				)
			);
		} catch ( \Throwable $e ) {
			ADVTN_Lock::release();
			ADVTN_Logger::log( 'error', 'Ingest cycle failed to schedule.', array( 'error' => $e->getMessage() ) );

			$response['status'] = 'error';
		}

		return $response;
	}

	/**
	 * Run a whole cycle inline and finalize it before returning.
	 *
	 * The scheduled path deliberately staggers sources so no single request
	 * makes a pile of outbound calls. That is wrong for an operator pressing a
	 * button: they get no visible change for however long the stagger plus the
	 * finalize buffer adds up to, and on a quiet site the queue may not advance
	 * at all. This runs sources directly, within the batch time budget, queues
	 * anything it could not reach, and always finalizes so the widget reflects
	 * the result immediately.
	 *
	 * Failure backoff is ignored: an explicit manual run is a retry request.
	 *
	 * @return array{status:string,ran:array<string,int>,failed:array<string,string>,queued:array<int,string>,lock_age_seconds:int|null}
	 */
	public function run_now(): array {
		$response = array(
			'status'           => 'ok',
			'ran'              => array(),
			'failed'           => array(),
			'queued'           => array(),
			'lock_age_seconds' => null,
		);

		if ( ! ADVTN_Lock::acquire() ) {
			$response['status']           = 'locked';
			$response['lock_age_seconds'] = ADVTN_Lock::age();
			return $response;
		}

		$budget  = $this->settings->get_int( 'batch_time_budget', 5, 120 );
		$started = microtime( true );

		try {
			foreach ( $this->cycle_sources() as $source ) {
				$source_id = (string) ( $source['id'] ?? '' );
				if ( '' === $source_id ) {
					continue;
				}

				if ( ( microtime( true ) - $started ) > $budget ) {
					advtn()->scheduler()->schedule_single( time(), ADVTN_Scheduler::HOOK_SOURCE, array( $source_id ) );
					$response['queued'][] = $source_id;
					continue;
				}

				$result = $this->run_source( $source_id );

				if ( $result->ok ) {
					$response['ran'][ $source_id ] = count( $result->items );
				} else {
					$response['failed'][ $source_id ] = (string) $result->error;
				}
			}
		} catch ( \Throwable $e ) {
			ADVTN_Logger::log( 'error', 'Manual ingest run failed.', array( 'error' => $e->getMessage() ) );
			$response['status'] = 'error';
		} finally {
			// Releases the lock, rebuilds the selection and busts the cache.
			$this->finalize();
		}

		if ( ! empty( $response['queued'] ) ) {
			// Those sources land after this finalize, so they need another one.
			advtn()->scheduler()->schedule_single( time() + 120, ADVTN_Scheduler::HOOK_FINALIZE );
		}

		return $response;
	}

	/**
	 * Fetch one source and write its items. Never throws.
	 *
	 * @param string $source_id Source id.
	 * @return ADVTN_Fetch_Result
	 */
	public function run_source( string $source_id ): ADVTN_Fetch_Result {
		$config = $this->source_config( $source_id );

		if ( null === $config ) {
			ADVTN_Logger::log( 'warning', 'Ingest requested for an unknown source.', array( 'source_id' => $source_id ) );
			return ADVTN_Fetch_Result::failure( __( 'Unknown source.', 'trending-now' ) );
		}

		$started = microtime( true );

		try {
			$result = $this->fetch( $config );
		} catch ( \Throwable $e ) {
			// One bad source must never abort the cycle.
			$result              = ADVTN_Fetch_Result::failure( $e->getMessage() );
			$result->duration_ms = (int) round( ( microtime( true ) - $started ) * 1000 );
		}

		if ( ! $result->ok ) {
			$this->record_failure( $source_id, $result );
			return $result;
		}

		$new  = 0;
		$seen = 0;

		foreach ( $result->items as $item ) {
			$outcome = $this->repository->upsert_item( $item, $source_id );

			if ( 'skip' === $outcome ) {
				continue;
			}

			++$seen;
			if ( 'insert' === $outcome ) {
				++$new;
			}
		}

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

		return $result;
	}

	/**
	 * Run a source's fetch without writing anything. Used by the admin
	 * "Test fetch" button.
	 *
	 * @param array<string,mixed> $config Source config row.
	 * @return ADVTN_Fetch_Result
	 */
	public function test_fetch( array $config ): ADVTN_Fetch_Result {
		try {
			return $this->fetch( $config );
		} catch ( \Throwable $e ) {
			return ADVTN_Fetch_Result::failure( $e->getMessage() );
		}
	}

	/**
	 * Prune, mark stale, rebuild the selection, refresh caches, unlock.
	 *
	 * @return void
	 */
	public function finalize(): void {
		try {
			$stale = $this->repository->mark_stale( 7 );

			$deleted = $this->repository->prune(
				$this->settings->get_int( 'retention_days', 1, 3650 ),
				500,
				$this->settings->get_int( 'batch_time_budget', 5, 120 )
			);

			$selection = advtn()->selector()->build_and_commit();

			advtn()->renderer()->purge_cache();

			// A full-page cache holds the finished HTML and knows nothing about
			// our render cache; without this the widget and the archive drift
			// apart by however long the page cache lives.
			ADVTN_Page_Cache::purge();

			if ( 'hub' === $this->settings->get_string( 'mode' ) ) {
				advtn()->rest()->rebuild_items_cache();
			}

			update_option( self::OPTION_LAST_INGEST, gmdate( 'Y-m-d H:i:s' ), false );
			$this->settings->prune_state();

			ADVTN_Logger::log(
				'info',
				'Cycle finalized.',
				array(
					'marked_stale'   => $stale,
					'pruned'         => $deleted,
					'selection_size' => count( $selection ),
				)
			);
		} catch ( \Throwable $e ) {
			ADVTN_Logger::log( 'error', 'Finalize failed.', array( 'error' => $e->getMessage() ) );
		} finally {
			// Unconditional: a fatal here must not wedge ingestion for 15
			// minutes.
			ADVTN_Lock::release();
		}
	}

	/**
	 * Whether enough time has passed since the last completed cycle.
	 *
	 * A due-check rather than a fixed clock time: a missed 04:00 window under
	 * WP-Cron just means it runs at 07:12 when traffic arrives.
	 *
	 * @return bool
	 */
	public function is_due(): bool {
		$last = (string) get_option( self::OPTION_LAST_INGEST, '' );

		if ( '' === $last ) {
			return true;
		}

		$timestamp = strtotime( $last . ' UTC' );
		if ( false === $timestamp ) {
			return true;
		}

		$interval = $this->settings->get_int( 'ingest_interval_hours', 1, 168 ) * HOUR_IN_SECONDS;

		return ( time() - $timestamp ) >= $interval;
	}

	/**
	 * Source rows this cycle should process.
	 *
	 * In spoke mode the site has no sources of its own; it pulls one
	 * pre-assembled list from the hub instead.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function cycle_sources(): array {
		$sources = 'spoke' === $this->settings->get_string( 'mode' )
			? array( ADVTN_Source_Hub::virtual_config( $this->settings ) )
			: $this->settings->enabled_sources();

		// Curated links are refreshed alongside real sources so last_seen keeps
		// moving and the stale sweep leaves them alone. Cheap: no HTTP.
		if ( ! empty( advtn()->manual()->active() ) ) {
			array_unshift( $sources, ADVTN_Source_Manual::virtual_config() );
		}

		return $sources;
	}

	/**
	 * Resolve a source id to its config row.
	 *
	 * @param string $source_id Source id.
	 * @return array<string,mixed>|null
	 */
	private function source_config( string $source_id ): ?array {
		if ( ADVTN_Source_Hub::SOURCE_ID === $source_id ) {
			return ADVTN_Source_Hub::virtual_config( $this->settings );
		}

		if ( ADVTN_Manual::SOURCE_ID === $source_id ) {
			return ADVTN_Source_Manual::virtual_config();
		}

		return $this->settings->source( $source_id );
	}

	/**
	 * Dispatch to the right provider.
	 *
	 * @param array<string,mixed> $config Source config row.
	 * @return ADVTN_Fetch_Result
	 */
	private function fetch( array $config ): ADVTN_Fetch_Result {
		$type   = (string) ( $config['type'] ?? '' );
		$source = advtn()->source( $type );

		if ( null === $source ) {
			/* translators: %s: source type key. */
			return ADVTN_Fetch_Result::failure( sprintf( __( 'No provider registered for source type "%s".', 'trending-now' ), $type ) );
		}

		// Re-validate the URL immediately before the request, not just on save.
		if ( in_array( $type, array( 'wp_rest', 'rss', 'hub' ), true ) && ! ADVTN_URL::is_valid( (string) ( $config['url'] ?? '' ) ) ) {
			return ADVTN_Fetch_Result::failure( __( 'Source URL failed validation at fetch time.', 'trending-now' ) );
		}

		return $source->fetch( $config );
	}

	/**
	 * Record a failure and extend the backoff window.
	 *
	 * @param string             $source_id Source id.
	 * @param ADVTN_Fetch_Result $result    Failed result.
	 * @return void
	 */
	private function record_failure( string $source_id, ADVTN_Fetch_Result $result ): void {
		$state = $this->settings->source_state( $source_id );
		$fails = (int) ( $state['consec_fails'] ?? 0 ) + 1;

		$backoff = $this->settings->get_int( 'source_fail_backoff', 60, 86400 ) * min( $fails, 6 );

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
	}

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

	/**
	 * Whether a source is inside its failure backoff window.
	 *
	 * @param string $source_id Source id.
	 * @return bool
	 */
	private function in_backoff( string $source_id ): bool {
		$until = $this->settings->source_state( $source_id )['backoff_until'] ?? null;

		if ( empty( $until ) ) {
			return false;
		}

		$timestamp = strtotime( (string) $until . ' UTC' );

		return false !== $timestamp && $timestamp > time();
	}
}
