<?php
/**
 * Slot allocation and rotation.
 *
 * Date-sorting alone would let a burst of 40 new posts crowd out yesterday's
 * posts before they were ever crawled, which defeats the point. Three tiers
 * fix that: pinned items inside their exposure floor, then never-shown items,
 * then least-shown items.
 *
 * @package Advision\TrendingNow
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ADVTN_Selector {

	public const OPTION_SELECTION = 'advtn_current_selection';

	/**
	 * Tier keys in fill order.
	 *
	 * @var string[]
	 */
	private const TIERS = array( 'pinned', 'unseen', 'least_shown' );

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
	 * Build the ordered selection without committing it.
	 *
	 * @return array<int,array<string,mixed>> Ordered item rows.
	 */
	public function build(): array {
		$limit = $this->settings->get_int( 'widget_limit', 1, 200 );
		$floor = $this->settings->get_int( 'exposure_floor_days', 0, 30 );

		$news_slots    = (int) floor( $limit * $this->settings->get_int( 'news_share_pct', 0, 50 ) / 100 );
		$network_slots = $limit - $news_slots;
		$cap           = max( 1, (int) ceil( $limit * $this->settings->get_int( 'max_source_share_pct', 5, 100 ) / 100 ) );

		$selected      = array();
		$source_counts = array();

		$this->fill( 'network', $network_slots, $selected, $source_counts, $cap, $floor );
		$this->fill( 'news', $news_slots, $selected, $source_counts, $cap, $floor );

		// Reallocate whatever either category left on the table.
		$remaining = $limit - count( $selected );
		if ( $remaining > 0 ) {
			$this->fill( 'any', $remaining, $selected, $source_counts, $cap, $floor );
		}

		// Last resort: never render fewer items than are available (spec §7.1).
		// The per-source cap buys diversity across a dozen-odd sources; with
		// only a few configured — or when pinned items have already eaten a
		// source's quota — enforcing it would leave slots empty while usable
		// items sit unselected. Diversity is preferred, not mandatory.
		$remaining = $limit - count( $selected );
		if ( $remaining > 0 ) {
			$relaxed = $this->fill( 'any', $remaining, $selected, $source_counts, PHP_INT_MAX, $floor );

			if ( $relaxed > 0 ) {
				ADVTN_Logger::log(
					'debug',
					'Per-source cap relaxed to fill otherwise-empty slots.',
					array(
						'cap'    => $cap,
						'filled' => $relaxed,
					)
				);
			}
		}

		$rows = array_values( $selected );

		usort(
			$rows,
			static function ( array $a, array $b ): int {
				$tier = ( $a['_tier'] ?? 9 ) <=> ( $b['_tier'] ?? 9 );
				if ( 0 !== $tier ) {
					return $tier;
				}

				$date = strcmp( (string) ( $b['published_at'] ?? '' ), (string) ( $a['published_at'] ?? '' ) );
				if ( 0 !== $date ) {
					return $date;
				}

				return (int) $b['id'] <=> (int) $a['id'];
			}
		);

		return $this->space_hosts( $rows );
	}

	/**
	 * Build, persist, and stamp display counters.
	 *
	 * @return int[] Ordered item ids.
	 */
	public function build_and_commit(): array {
		$rows = $this->build();
		$ids  = array_map( static fn( $row ) => (int) $row['id'], $rows );

		update_option( self::OPTION_SELECTION, $ids, false );

		if ( ! empty( $ids ) ) {
			$this->repository->mark_shown( $ids );
		}

		return $ids;
	}

	/**
	 * The committed selection's ids.
	 *
	 * @return int[]
	 */
	public function current_ids(): array {
		$ids = get_option( self::OPTION_SELECTION, array() );

		return is_array( $ids ) ? array_values( array_map( 'intval', $ids ) ) : array();
	}

	/**
	 * Drop ids from the committed selection without rebuilding it.
	 *
	 * Used after a deletion. Rebuilding here would be wrong: build_and_commit()
	 * stamps times_shown, so tidying up would inflate every counter.
	 *
	 * @param int[] $ids Ids to forget. Empty means forget everything.
	 * @return void
	 */
	public function forget( array $ids = array() ): void {
		if ( empty( $ids ) ) {
			update_option( self::OPTION_SELECTION, array(), false );
			return;
		}

		$remaining = array_values( array_diff( $this->current_ids(), array_map( 'intval', $ids ) ) );

		update_option( self::OPTION_SELECTION, $remaining, false );
	}

	/**
	 * The committed selection's rows, in order.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function current_rows(): array {
		$ids = $this->current_ids();

		return empty( $ids ) ? array() : $this->repository->get_by_ids( $ids );
	}

	/**
	 * Fill up to $slots from one pool, walking the tiers in order.
	 *
	 * @param string                              $pool          'network', 'news' or 'any'.
	 * @param int                                 $slots         Slots to fill.
	 * @param array<int,array<string,mixed>>      $selected      Selected rows, keyed by id. Passed by reference.
	 * @param array<string,int>                   $source_counts Running per-source tally. Passed by reference.
	 * @param int                                 $cap           Per-source slot cap.
	 * @param int                                 $floor         Exposure floor in days.
	 * @return int Slots actually filled.
	 */
	private function fill( string $pool, int $slots, array &$selected, array &$source_counts, int $cap, int $floor ): int {
		if ( $slots <= 0 ) {
			return 0;
		}

		$taken = 0;

		foreach ( self::TIERS as $index => $tier ) {
			if ( $taken >= $slots ) {
				break;
			}

			$need = $slots - $taken;

			// Over-fetch: candidates skipped by the per-source cap still need
			// replacements from the same query.
			$fetch = min( 500, ( $need * 5 ) + 50 );

			$candidates = $this->repository->candidates( $tier, $pool, $fetch, array_keys( $selected ), $floor );

			foreach ( $candidates as $row ) {
				if ( $taken >= $slots ) {
					break;
				}

				$id = (int) $row['id'];
				if ( isset( $selected[ $id ] ) ) {
					continue;
				}

				$source_id = (string) $row['source_id'];

				// A pinned item honours its exposure floor even when that
				// pushes a source past its diversity cap.
				if ( 'pinned' !== $tier && ( $source_counts[ $source_id ] ?? 0 ) >= $cap ) {
					continue;
				}

				$row['_tier']                = $index + 1;
				$selected[ $id ]             = $row;
				$source_counts[ $source_id ] = ( $source_counts[ $source_id ] ?? 0 ) + 1;
				++$taken;
			}
		}

		return $taken;
	}

	/**
	 * Reorder so no more than two consecutive items share a host.
	 *
	 * @param array<int,array<string,mixed>> $rows Ordered rows.
	 * @return array<int,array<string,mixed>>
	 */
	private function space_hosts( array $rows ): array {
		$out     = array();
		$pending = $rows;

		while ( ! empty( $pending ) ) {
			$chosen = null;

			foreach ( $pending as $key => $row ) {
				if ( ! $this->run_of_two( $out, (string) ( $row['host'] ?? '' ) ) ) {
					$chosen = $key;
					break;
				}
			}

			// Every remaining candidate shares the host: accept the run.
			if ( null === $chosen ) {
				$chosen = array_key_first( $pending );
			}

			$out[] = $pending[ $chosen ];
			unset( $pending[ $chosen ] );
		}

		return $out;
	}

	/**
	 * Whether the last two emitted items already share this host.
	 *
	 * @param array<int,array<string,mixed>> $out  Emitted rows.
	 * @param string                         $host Candidate host.
	 * @return bool
	 */
	private function run_of_two( array $out, string $host ): bool {
		$count = count( $out );

		if ( $count < 2 || '' === $host ) {
			return false;
		}

		return (string) ( $out[ $count - 1 ]['host'] ?? '' ) === $host
			&& (string) ( $out[ $count - 2 ]['host'] ?? '' ) === $host;
	}
}
