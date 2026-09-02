<?php
/**
 * WP-CLI commands. Convenience only — nothing in the plugin depends on CLI
 * availability.
 *
 * @package Advision\TrendingNow
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Manage the Trending Now aggregator.
 */
final class ADVTN_CLI {

	/**
	 * Run an ingest cycle.
	 *
	 * ## OPTIONS
	 *
	 * [--source=<id>]
	 * : Only ingest this source id.
	 *
	 * [--force]
	 * : Bypass the due-check (never the lock).
	 *
	 * [--sync]
	 * : Fetch sources inline instead of queueing scheduled actions.
	 *
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function ingest( array $args, array $assoc_args ): void {
		unset( $args );

		$force  = isset( $assoc_args['force'] );
		$source = isset( $assoc_args['source'] ) ? (string) $assoc_args['source'] : null;
		$sync   = isset( $assoc_args['sync'] );

		if ( $sync ) {
			$this->ingest_sync( $source );
			return;
		}

		$result = advtn()->ingest()->run( $force, $source );

		switch ( $result['status'] ) {
			case 'locked':
				WP_CLI::warning( sprintf( 'Lock held for %ds.', (int) $result['lock_age_seconds'] ) );
				break;
			case 'not_due':
				WP_CLI::log( sprintf( 'Not due. Last ingest: %s', $result['last_ingest'] ?: 'never' ) );
				break;
			case 'error':
				WP_CLI::error( 'Failed to schedule. See the plugin log.' );
				break;
			default:
				WP_CLI::success( sprintf( 'Scheduled %d source(s): %s', count( $result['scheduled'] ), implode( ', ', $result['scheduled'] ) ) );
		}
	}

	/**
	 * Fetch the curated-links feed.
	 *
	 * ## OPTIONS
	 *
	 * [--force]
	 * : Bypass the due-check. Never bypasses the validity check.
	 *
	 * ## EXAMPLES
	 *
	 *     wp trending-now feed-fetch --force
	 *
	 * @subcommand feed-fetch
	 *
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function feed_fetch( array $args, array $assoc_args ): void {
		unset( $args );

		/*
		 * NO TRIGGER, DELIBERATELY. `TRIGGERS` is closed and mirrored in three
		 * places that cannot import each other — here, the feed's normaliser
		 * and the console's renderer — so a fifth name invented on this side is
		 * a name the other two drop. `manual` is the nearest fit and it is
		 * wrong: it reads on screen as "somebody pressing Fetch now", and
		 * nobody pressed anything. An absent trigger is the honest "this fetch
		 * did not say", which is what the screens already render for it.
		 */
		$result = advtn()->manual_feed()->fetch( isset( $assoc_args['force'] ) );

		switch ( $result['status'] ) {
			case 'failed':
				WP_CLI::error( $result['message'] );
				break;
			case 'ok':
				WP_CLI::success( $result['message'] );
				break;
			default:
				WP_CLI::log( $result['message'] );
		}
	}

	/**
	 * Rebuild and commit the selection.
	 *
	 * @return void
	 */
	public function select(): void {
		$ids = advtn()->selector()->build_and_commit();
		advtn()->renderer()->purge_cache();

		WP_CLI::success( sprintf( 'Selected %d item(s).', count( $ids ) ) );
	}

	/**
	 * Print the rendered widget HTML.
	 *
	 * ## OPTIONS
	 *
	 * [--uncached]
	 * : Bypass the render cache.
	 *
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function render( array $args, array $assoc_args ): void {
		unset( $args );

		$renderer = advtn()->renderer();
		$html     = isset( $assoc_args['uncached'] ) ? $renderer->render_uncached() : $renderer->render();

		WP_CLI::log( '' === $html ? '(empty)' : $html );
	}

	/**
	 * Show diagnostics as JSON.
	 *
	 * @return void
	 */
	public function status(): void {
		WP_CLI::log( (string) wp_json_encode( advtn()->rest()->status_payload(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * Mark stale items and delete anything past the retention window.
	 *
	 * @return void
	 */
	public function prune(): void {
		$repository = advtn()->repository();
		$settings   = advtn()->settings();

		$stale   = $repository->mark_stale( 7 );
		$deleted = $repository->prune( $settings->get_int( 'retention_days', 1, 3650 ) );

		WP_CLI::success( sprintf( 'Marked %d stale, deleted %d.', $stale, $deleted ) );
	}

	/**
	 * Delete stored items.
	 *
	 * Sources, settings and secrets are untouched; the next ingest repopulates
	 * from whatever is still enabled.
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Empty the items table.
	 *
	 * [--source=<id>]
	 * : Only items belonging to this source id.
	 *
	 * [--host=<host>]
	 * : Only items on this host, e.g. 127.0.0.1.
	 *
	 * [--status=<status>]
	 * : Only items with this status: active or stale.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp trending-now flush --host=127.0.0.1
	 *     wp trending-now flush --all --yes
	 *
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function flush( array $args, array $assoc_args ): void {
		unset( $args );

		$repository = advtn()->repository();

		$filters = array(
			'source_id' => isset( $assoc_args['source'] ) ? (string) $assoc_args['source'] : '',
			'host'      => isset( $assoc_args['host'] ) ? (string) $assoc_args['host'] : '',
			'status'    => isset( $assoc_args['status'] ) ? (string) $assoc_args['status'] : '',
		);

		$targeted = ! empty( array_filter( $filters ) );
		$all      = isset( $assoc_args['all'] );

		if ( ! $all && ! $targeted ) {
			WP_CLI::error( 'Pass --all, or narrow it with --source, --host or --status.' );
		}

		$matching = $all ? $repository->counts()['total'] : $repository->count_where( $filters );

		if ( 0 === $matching ) {
			WP_CLI::success( 'Nothing matched; no rows deleted.' );
			return;
		}

		WP_CLI::confirm(
			sprintf( 'Delete %d item(s)%s?', $matching, $all ? ' (the entire table)' : '' ),
			$assoc_args
		);

		if ( $all ) {
			$repository->delete_all();
			advtn()->selector()->forget();
			$deleted = $matching;
		} else {
			$deleted = $repository->delete_where( $filters );

			// Drop ids that no longer exist rather than rebuilding, which
			// would inflate times_shown on every survivor.
			$live      = advtn()->selector()->current_ids();
			$surviving = array_map( 'intval', array_column( $repository->get_by_ids( $live ), 'id' ) );
			advtn()->selector()->forget( array_diff( $live, $surviving ) );
		}

		advtn()->renderer()->purge_cache();
		delete_transient( 'advtn_archive_count' );

		WP_CLI::success( sprintf( 'Deleted %d item(s).', $deleted ) );
	}

	/**
	 * Release a stuck ingest lock.
	 *
	 * @return void
	 */
	public function unlock(): void {
		ADVTN_Lock::release();
		WP_CLI::success( 'Lock released.' );
	}

	/**
	 * Purge every cached render variant.
	 *
	 * @return void
	 */
	public function purge(): void {
		$count = advtn()->renderer()->purge_cache();
		WP_CLI::success( sprintf( 'Purged %d cached variant(s).', $count ) );
	}

	/**
	 * Fetch every source inline, then finalize. Useful on a workstation.
	 *
	 * @param string|null $only_source Restrict to one source id.
	 * @return void
	 */
	private function ingest_sync( ?string $only_source ): void {
		$ingest  = advtn()->ingest();
		$sources = $ingest->cycle_sources();

		if ( null !== $only_source ) {
			$sources = array_values( array_filter( $sources, static fn( $s ) => ( $s['id'] ?? '' ) === $only_source ) );
		}

		if ( empty( $sources ) ) {
			WP_CLI::warning( 'No matching enabled sources.' );
			return;
		}

		if ( ! ADVTN_Lock::acquire() ) {
			WP_CLI::error( sprintf( 'Lock held for %ds.', (int) ADVTN_Lock::age() ) );
		}

		foreach ( $sources as $source ) {
			$id     = (string) $source['id'];
			$result = $ingest->run_source( $id );

			if ( $result->ok ) {
				WP_CLI::log( sprintf( '%s: %d item(s) in %dms.', $id, count( $result->items ), $result->duration_ms ) );
			} else {
				WP_CLI::warning( sprintf( '%s: %s', $id, (string) $result->error ) );
			}
		}

		// A --source run ingests one source, so it must not stamp the
		// whole-cycle timestamp: doing so would defer every other source's
		// scheduled run, exactly as it did from the admin button before
		// finalize() learned to tell the two apart.
		$ingest->finalize( null === $only_source );

		WP_CLI::success( null === $only_source ? 'Cycle complete.' : 'Source complete.' );
	}
}
