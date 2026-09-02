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
	 * When the next run of a hook is due; 0 when nothing is pending.
	 *
	 * Asked through this class rather than with a bare `wp_next_scheduled()`,
	 * because `schedule_single()` puts the action wherever Action Scheduler is
	 * available and WP-Cron knows nothing about it there. A caller that queues
	 * through the scheduler and then checks with `wp_next_scheduled()` would be
	 * told "nothing pending" every time and would queue without limit.
	 *
	 * The timestamp is worth returning rather than a bool: an event that is due
	 * but has not run — the state of every WP-Cron entry on a host whose
	 * loopback is blocked, which the `loopback_ok` diagnostic exists because it
	 * happens — comes back as a time in the past. That is the difference
	 * between "a repair is coming" and "a repair is wedged", and a bool cannot
	 * express it.
	 *
	 * @param string       $hook Hook name.
	 * @param array<mixed> $args Hook args.
	 * @return int Unix time of the next run, or 0.
	 */
	public function next_scheduled( string $hook, array $args = array() ): int {
		if ( $this->has_action_scheduler() ) {
			$next = as_next_scheduled_action( $hook, $args, self::GROUP );

			// Action Scheduler answers `true` for an action that is pending or
			// in progress but carries no scheduled date. One is queued, so this
			// must not read as none; there is no timestamp to report, so it
			// reports as due now.
			if ( true === $next ) {
				return time();
			}

			return is_int( $next ) ? $next : 0;
		}

		$next = wp_next_scheduled( $hook, $args );

		return is_int( $next ) ? $next : 0;
	}

	/**
	 * Queue a recurring action, or its WP-Cron equivalent.
	 *
	 * WP-Cron has no arbitrary interval, so the fallback registers a schedule
	 * named for the interval in seconds. Two hooks wanting the same interval
	 * therefore share one schedule rather than each adding their own.
	 *
	 * Idempotent: an action already pending for this hook is left where it is.
	 * Rescheduling it on every call would push its next run further out each
	 * time and it would never fire.
	 *
	 * @param int          $timestamp First run.
	 * @param int          $interval  Seconds between runs.
	 * @param string       $hook      Hook name.
	 * @param array<mixed> $args      Hook args.
	 * @return int|string Action id, or 0 when one was already pending.
	 */
	public function schedule_recurring( int $timestamp, int $interval, string $hook, array $args = array() ) {
		$interval = max( MINUTE_IN_SECONDS, $interval );

		if ( $this->has_action_scheduler() ) {
			if ( false !== as_next_scheduled_action( $hook, $args, self::GROUP ) ) {
				return 0;
			}

			return as_schedule_recurring_action( $timestamp, $interval, $hook, $args, self::GROUP );
		}

		if ( wp_next_scheduled( $hook, $args ) ) {
			return 0;
		}

		$name = 'advtn_every_' . $interval;

		add_filter(
			'cron_schedules', // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected
			static function ( $schedules ) use ( $name, $interval ) {
				$schedules[ $name ] = array(
					'interval' => $interval,
					/* translators: %d: interval in seconds. */
					'display'  => sprintf( __( 'Every %d seconds (Trending Now)', 'trending-now' ), $interval ),
				);

				return $schedules;
			}
		);

		return wp_schedule_event( $timestamp, $name, $hook, $args ) ? 1 : 0;
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
	 * Every hook this plugin can queue has to be listed here, in both lists.
	 * A hook that is missing survives deactivation as an orphan cron entry: it
	 * fires into no listener, and a reactivation inside its window performs one
	 * unexpected forced fetch. `HOOK_RETRY` was exactly that until fix round 1.
	 *
	 * @return void
	 */
	public function unschedule_all(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), self::GROUP );
			as_unschedule_all_actions( self::HOOK_CYCLE );
			as_unschedule_all_actions( self::HOOK_SOURCE );
			as_unschedule_all_actions( self::HOOK_FINALIZE );
			as_unschedule_all_actions( ADVTN_Manual_Feed::HOOK );
			as_unschedule_all_actions( ADVTN_Manual_Feed::HOOK_RETRY );
		}

		foreach ( array( self::HOOK_CYCLE, self::HOOK_SOURCE, self::HOOK_FINALIZE, ADVTN_Manual::HOOK, ADVTN_Manual_Feed::HOOK, ADVTN_Manual_Feed::HOOK_RETRY ) as $hook ) {
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
