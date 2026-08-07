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

		// Curated links are editorial decisions, so they are placed rather than
		// competing: they reserve their slots before the tiers run, and are
		// excluded from the candidate pool so they cannot also be picked.
		$manual    = $this->manual_rows();
		$auto_slots = max( 0, $limit - count( $manual ) );

		$news_slots    = (int) floor( $auto_slots * $this->settings->get_int( 'news_share_pct', 0, 50 ) / 100 );
		$network_slots = $auto_slots - $news_slots;
		$cap           = max( 1, (int) ceil( $limit * $this->settings->get_int( 'max_source_share_pct', 5, 100 ) / 100 ) );

		$selected      = array();
		$source_counts = array();

		foreach ( $manual as $row ) {
			$selected[ (int) $row['id'] ] = $row;
		}

		$this->fill( 'network', $network_slots, $selected, $source_counts, $cap, $floor );
		$this->fill( 'news', $news_slots, $selected, $source_counts, $cap, $floor );

		// Reallocate whatever either category left on the table.
		$remaining = $auto_slots - ( count( $selected ) - count( $manual ) );
		if ( $remaining > 0 ) {
			$this->fill( 'any', $remaining, $selected, $source_counts, $cap, $floor );
		}

		// Last resort: never render fewer items than are available (spec §7.1).
		// The per-source cap buys diversity across a dozen-odd sources; with
		// only a few configured — or when pinned items have already eaten a
		// source's quota — enforcing it would leave slots empty while usable
		// items sit unselected. Diversity is preferred, not mandatory.
		$remaining = $auto_slots - ( count( $selected ) - count( $manual ) );
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

		// Placement happens after ordering, so pull them back out first.
		$manual_ids = array();
		foreach ( $manual as $row ) {
			$manual_ids[ (int) $row['id'] ] = true;
		}

		$rows = array_values(
			array_filter( $selected, static fn( $row ) => ! isset( $manual_ids[ (int) $row['id'] ] ) )
		);

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

		return $this->place_manual( $this->space_hosts( $rows ), $manual, $limit );
	}

	/**
	 * Curated rows that exist in the table, newest editorial first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function manual_rows(): array {
		$rows = advtn()->manual()->selection_rows();

		// A position of 0 means "no opinion"; those are appended rather than
		// spliced, so sorting them last keeps the splice indexes meaningful.
		usort(
			$rows,
			static function ( array $a, array $b ): int {
				$pa = (int) ( $a['_position'] ?? 0 );
				$pb = (int) ( $b['_position'] ?? 0 );

				if ( 0 === $pa || 0 === $pb ) {
					return ( 0 === $pa ? 1 : 0 ) <=> ( 0 === $pb ? 1 : 0 );
				}

				return $pa <=> $pb;
			}
		);

		return $rows;
	}

	/**
	 * Splice curated links into their configured slots.
	 *
	 * Positions are 1-based and clamped: asking for slot 40 in a 30-slot widget
	 * puts the link last rather than dropping it.
	 *
	 * @param array<int,array<string,mixed>> $rows   Ordered automatic rows.
	 * @param array<int,array<string,mixed>> $manual Curated rows.
	 * @param int                            $limit  Widget limit.
	 * @return array<int,array<string,mixed>>
	 */
	private function place_manual( array $rows, array $manual, int $limit ): array {
		if ( empty( $manual ) ) {
			return array_slice( $rows, 0, $limit );
		}

		$unpositioned = array();

		foreach ( $manual as $row ) {
			if ( 0 === (int) ( $row['_position'] ?? 0 ) ) {
				$unpositioned[] = $row;
			}
		}

		// No stated position means "just include it", so it joins the front of
		// the automatic run and takes its chances on ordering.
		$out = array_merge( $unpositioned, $rows );

		foreach ( $manual as $row ) {
			$position = (int) ( $row['_position'] ?? 0 );

			if ( $position < 1 ) {
				continue;
			}

			$index = min( $position - 1, count( $out ) );
			array_splice( $out, $index, 0, array( $row ) );
		}

		return array_slice( $out, 0, $limit );
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
