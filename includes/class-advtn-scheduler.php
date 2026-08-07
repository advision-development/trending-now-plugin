<?php
/**
 * Action Scheduler registration, staggering and due-checks.
 *
 * Action Scheduler does not need WP-CLI: its default runner attaches to a
 * WP-Cron hook and processes batches over loopback requests. When the Composer
 * dependency is missing entirely the plugin degrades to plain WP-Cron rather
 * than fataling, and diagnostics reports which runner is active.
 *
 * @package Advision\TrendingNow
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ADVTN_Scheduler {

	public const GROUP          = 'advtn';
	public const HOOK_CYCLE     = 'advtn_ingest_cycle';
	public const HOOK_SOURCE    = 'advtn_ingest_source';
	public const HOOK_FINALIZE  = 'advtn_finalize_cycle';

	/**
	 * Settings service.
	 *
	 * @var ADVTN_Settings
	 */
	private ADVTN_Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param ADVTN_Settings $settings Settings service.
	 */
	public function __construct( ADVTN_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Bind the three scheduled hooks and the queue-runner budgets.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( self::HOOK_CYCLE, array( $this, 'run_cycle' ) );
		add_action( self::HOOK_SOURCE, array( $this, 'run_source' ), 10, 1 );
		add_action( self::HOOK_FINALIZE, array( $this, 'run_finalize' ) );

		add_filter( 'action_scheduler_queue_runner_batch_size', array( $this, 'filter_batch_size' ) );
		add_filter( 'action_scheduler_queue_runner_time_limit', array( $this, 'filter_time_limit' ) );
	}

	/**
	 * Whether Action Scheduler is loaded.
	 *
	 * @return bool
	 */
	public function has_action_scheduler(): bool {
		return function_exists( 'as_schedule_recurring_action' ) && function_exists( 'as_next_scheduled_action' );
	}

	/**
	 * Idempotently register the hourly cycle check.
	 *
	 * @return void
	 */
	public function ensure_recurring_action(): void {
		if ( $this->has_action_scheduler() ) {
			if ( false === as_next_scheduled_action( self::HOOK_CYCLE, array(), self::GROUP ) ) {
				as_schedule_recurring_action( time() + 60, HOUR_IN_SECONDS, self::HOOK_CYCLE, array(), self::GROUP );
			}
			return;
		}

		if ( ! wp_next_scheduled( self::HOOK_CYCLE ) ) {
			wp_schedule_event( time() + 60, 'hourly', self::HOOK_CYCLE );
		}
	}

	/**
	 * Queue a one-off action.
	 *
	 * @param int          $timestamp Unix time to run at.
	 * @param string       $hook      Hook name.
	 * @param array<mixed> $args      Hook args.
	 * @return int|string Action id, or 0 on failure.
	 */
	public function schedule_single( int $timestamp, string $hook, array $args = array() ) {
		if ( $this->has_action_scheduler() ) {
			return as_schedule_single_action( $timestamp, $hook, $args, self::GROUP );
		}

		return wp_schedule_single_event( $timestamp, $hook, $args ) ? 1 : 0;
	}

	/**
	 * Remove pending actions for one hook.
	 *
	 * @param string $hook Hook name.
	 * @return void
	 */
	public function unschedule( string $hook ): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( $hook, array(), self::GROUP );
			as_unschedule_all_actions( $hook );
		}

		wp_clear_scheduled_hook( $hook );
	}

	/**
	 * Remove every scheduled action this plugin owns.
	 *
	 * @return void
	 */
	public function unschedule_all(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), self::GROUP );
			as_unschedule_all_actions( self::HOOK_CYCLE );
			as_unschedule_all_actions( self::HOOK_SOURCE );
			as_unschedule_all_actions( self::HOOK_FINALIZE );
		}

		foreach ( array( self::HOOK_CYCLE, self::HOOK_SOURCE, self::HOOK_FINALIZE, ADVTN_Manual::HOOK ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	/**
	 * Count of pending actions in the plugin's group.
	 *
	 * @return int
	 */
	public function pending_count(): int {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return 0;
		}

		$actions = as_get_scheduled_actions(
			array(
				'group'    => self::GROUP,
				'status'   => 'pending',
				'per_page' => 100,
			),
			'ids'
		);

		return is_array( $actions ) ? count( $actions ) : 0;
	}

	/**
	 * Pending actions with their due times, for diagnostics.
	 *
	 * Without this the queue is invisible: an operator sees "scheduled" and no
	 * change, with nothing on screen explaining that the work is minutes out.
	 *
	 * @return array<int,array{hook:string,args:string,when:string}>
	 */
	public function pending_summary(): array {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return array();
		}

		$actions = as_get_scheduled_actions(
			array(
				'group'    => self::GROUP,
				'status'   => 'pending',
				'per_page' => 50,
				'orderby'  => 'date',
				'order'    => 'ASC',
			)
		);

		if ( ! is_array( $actions ) ) {
			return array();
		}

		$out = array();

		foreach ( $actions as $action ) {
			if ( ! is_object( $action ) || ! method_exists( $action, 'get_hook' ) ) {
				continue;
			}

			$when     = '';
			$schedule = method_exists( $action, 'get_schedule' ) ? $action->get_schedule() : null;

			if ( $schedule && method_exists( $schedule, 'get_date' ) ) {
				$date = $schedule->get_date();
				$when = $date ? $date->format( 'Y-m-d H:i:s' ) : '';
			}

			$args = method_exists( $action, 'get_args' ) ? (array) $action->get_args() : array();

			$out[] = array(
				'hook' => (string) $action->get_hook(),
				'args' => implode( ', ', array_map( 'strval', $args ) ),
				'when' => $when,
			);
		}

		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Hook callbacks
	 * ------------------------------------------------------------------ */

	/**
	 * Hourly entry point. Does nothing most of the time.
	 *
	 * @return void
	 */
	public function run_cycle(): void {
		advtn()->ingest()->run( false );
	}

	/**
	 * Ingest exactly one source.
	 *
	 * @param string $source_id Source id.
	 * @return void
	 */
	public function run_source( string $source_id = '' ): void {
		if ( '' === $source_id ) {
			return;
		}

		advtn()->ingest()->run_source( $source_id );
	}

	/**
	 * Prune, select, render, unlock.
	 *
	 * @return void
	 */
	public function run_finalize(): void {
		advtn()->ingest()->finalize();
	}

	/* ---------------------------------------------------------------------
	 * Queue runner budgets
	 * ------------------------------------------------------------------ */

	/**
	 * Cap how many actions run per batch.
	 *
	 * @param int $size Default batch size.
	 * @return int
	 */
	public function filter_batch_size( $size ): int {
		return min( (int) $size, $this->settings->get_int( 'batch_max_sources', 1, 25 ) );
	}

	/**
	 * Cap how long a batch may run.
	 *
	 * @param int $limit Default time limit in seconds.
	 * @return int
	 */
	public function filter_time_limit( $limit ): int {
		return min( (int) $limit, $this->settings->get_int( 'batch_time_budget', 5, 120 ) );
	}
}
