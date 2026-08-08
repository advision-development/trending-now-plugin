<?php
/**
 * Every SQL statement in the plugin lives here. Nothing else touches $wpdb.
 *
 * All stored datetimes are UTC. Cutoffs are computed in PHP with gmdate() and
 * passed as prepared parameters rather than using MySQL NOW(), which follows
 * the database server's timezone and would silently skew retention and the
 * exposure floor on hosts that are not set to UTC.
 *
 * @package Advision\TrendingNow
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ADVTN_Repository {

	/**
	 * Items table name.
	 *
	 * @return string
	 */
	public function table(): string {
		return ADVTN_Schema::table();
	}

	/* ---------------------------------------------------------------------
	 * Writes
	 * ------------------------------------------------------------------ */

	/**
	 * Insert or refresh one item.
	 *
	 * Display history (first_seen, first_shown_at, last_shown_at, times_shown)
	 * is never touched on conflict. published_at uses COALESCE so a real
	 * publish date is not overwritten by a later, less accurate discovery date.
	 *
	 * @param array<string,mixed> $item      Normalized item (see spec §5.1).
	 * @param string              $source_id Owning source id.
	 * @return string One of 'insert', 'update', 'skip'.
	 */
	public function upsert_item( array $item, string $source_id ): string {
		global $wpdb;

		$url = (string) ( $item['url'] ?? '' );
		$hash = ADVTN_URL::hash( $url );

		if ( '' === $hash ) {
			return 'skip';
		}

		$now   = gmdate( 'Y-m-d H:i:s' );
		$table = $this->table();

		// $wpdb->prepare() coerces null to an empty string, which would store
		// '' in nullable datetime and URL columns. Emit a literal NULL for
		// those instead. Column names come from this fixed list, never input.
		$fields = array(
			'url_hash'     => $hash,
			'url'          => esc_url_raw( $url ),
			'source_id'    => $source_id,
			'source_type'  => (string) ( $item['source_type'] ?? '' ),
			'site_name'    => mb_substr( (string) ( $item['site_name'] ?? '' ), 0, 191 ),
			'host'         => mb_substr( ADVTN_URL::host( $url ), 0, 191 ),
			'title'        => (string) ( $item['title'] ?? '' ),
			'excerpt'      => ! empty( $item['excerpt'] ) ? (string) $item['excerpt'] : null,
			'image_url'    => ! empty( $item['image_url'] ) ? esc_url_raw( (string) $item['image_url'] ) : null,
			'published_at' => ! empty( $item['published_at'] ) ? (string) $item['published_at'] : null,
			'first_seen'   => $now,
			'last_seen'    => $now,
		);

		$columns = array();
		$slots   = array();
		$params  = array();

		foreach ( $fields as $column => $value ) {
			$columns[] = $column;

			if ( null === $value ) {
				$slots[] = 'NULL';
				continue;
			}

			$slots[]  = '%s';
			$params[] = $value;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name and the fixed column list above.
		$sql = "INSERT INTO {$table} (" . implode( ', ', $columns ) . ", status)
			VALUES (" . implode( ', ', $slots ) . ", 'active')
			ON DUPLICATE KEY UPDATE
			  title        = VALUES(title),
			  excerpt      = VALUES(excerpt),
			  image_url    = VALUES(image_url),
			  published_at = COALESCE(published_at, VALUES(published_at)),
			  last_seen    = VALUES(last_seen),
			  status       = 'active'";
		// phpcs:enable

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$result = $wpdb->query( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( false === $result ) {
			return 'skip';
		}

		// ON DUPLICATE KEY: affected rows is 1 for an insert, 2 for an update,
		// 0 when the update changed nothing.
		return 1 === (int) $wpdb->rows_affected ? 'insert' : 'update';
	}

	/**
	 * Stamp display counters for the committed selection.
	 *
	 * Two statements: one for the counters, one for the write-once
	 * first_shown_at.
	 *
	 * @param int[] $ids Item ids.
	 * @return void
	 */
	public function mark_shown( array $ids ): void {
		global $wpdb;

		$ids = $this->clean_ids( $ids );
		if ( empty( $ids ) ) {
			return;
		}

		$now          = gmdate( 'Y-m-d H:i:s' );
		$table        = $this->table();
		$placeholders = $this->placeholders( $ids );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$table} SET times_shown = times_shown + 1, last_shown_at = %s WHERE id IN ({$placeholders})",
				array_merge( array( $now ), $ids )
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$table} SET first_shown_at = %s WHERE first_shown_at IS NULL AND id IN ({$placeholders})",
				array_merge( array( $now ), $ids )
			)
		);
	}

	/**
	 * Mark items not seen in $days as stale.
	 *
	 * Stale items stay in the archive but drop out of widget selection.
	 *
	 * @param int $days Staleness window.
	 * @return int Rows changed.
	 */
	public function mark_stale( int $days ): int {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, $days ) * DAY_IN_SECONDS ) );
		$table  = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$table} SET status = 'stale' WHERE status = 'active' AND last_seen < %s",
				$cutoff
			)
		);

		return (int) $rows;
	}

	/**
	 * Delete items past the retention window, in bounded batches.
	 *
	 * @param int $retention_days Retention window in days.
	 * @param int $batch_size     Rows per DELETE.
	 * @param int $time_budget    Seconds to spend before bailing.
	 * @return int Rows deleted.
	 */
	public function prune( int $retention_days, int $batch_size = 500, int $time_budget = 10 ): int {
		global $wpdb;

		$cutoff  = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, $retention_days ) * DAY_IN_SECONDS ) );
		$table   = $this->table();
		$batch   = max( 1, min( 5000, $batch_size ) );
		$started = microtime( true );
		$deleted = 0;

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$rows = (int) $wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"DELETE FROM {$table} WHERE first_seen < %s LIMIT %d",
					$cutoff,
					$batch
				)
			);

			$deleted += $rows;

			if ( ( microtime( true ) - $started ) > $time_budget ) {
				break;
			}
		} while ( $rows === $batch );

		return $deleted;
	}

	/**
	 * Truncate the table. Used by CLI/admin resets only.
	 *
	 * @return void
	 */
	public function delete_all(): void {
		global $wpdb;
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "TRUNCATE TABLE {$table}" );
	}

	/**
	 * Delete specific rows.
	 *
	 * @param int[] $ids Item ids.
	 * @return int Rows deleted.
	 */
	public function delete_by_ids( array $ids ): int {
		global $wpdb;

		$ids = $this->clean_ids( $ids );
		if ( empty( $ids ) ) {
			return 0;
		}

		$table        = $this->table();
		$placeholders = $this->placeholders( $ids );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"DELETE FROM {$table} WHERE id IN ({$placeholders})",
				$ids
			)
		);
	}

	/**
	 * Delete every row matching a filter.
	 *
	 * Refuses an empty filter — callers that mean "everything" must say so via
	 * delete_all(), so a dropped filter can never silently wipe the table.
	 *
	 * @param array<string,string> $filters source_id, host, source_type, status, search.
	 * @return int Rows deleted.
	 */
	public function delete_where( array $filters ): int {
		global $wpdb;

		$clause = $this->build_where( $filters );

		if ( empty( $clause['params'] ) ) {
			return 0;
		}

		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"DELETE FROM {$table} WHERE " . $clause['sql'],
				$clause['params']
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Reads
	 * ------------------------------------------------------------------ */

	/**
	 * Fetch rows by id, returned in the order the ids were given.
	 *
	 * @param int[] $ids Item ids.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_by_ids( array $ids ): array {
		global $wpdb;

		$ids = $this->clean_ids( $ids );
		if ( empty( $ids ) ) {
			return array();
		}

		$table        = $this->table();
		$placeholders = $this->placeholders( $ids );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE id IN ({$placeholders})",
				$ids
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return array();
		}

		$by_id = array();
		foreach ( $rows as $row ) {
			$by_id[ (int) $row['id'] ] = $row;
		}

		$ordered = array();
		foreach ( $ids as $id ) {
			if ( isset( $by_id[ $id ] ) ) {
				$ordered[] = $by_id[ $id ];
			}
		}

		return $ordered;
	}

	/**
	 * Fetch rows by url_hash.
	 *
	 * @param string[] $hashes url_hash values.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_by_hashes( array $hashes ): array {
		global $wpdb;

		$hashes = array_values( array_unique( array_filter( array_map( 'strval', $hashes ) ) ) );

		if ( empty( $hashes ) ) {
			return array();
		}

		$table        = $this->table();
		$placeholders = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id, url_hash, url, title, excerpt, image_url, published_at, site_name, host, source_id, source_type, times_shown, first_shown_at, status
				 FROM {$table} WHERE url_hash IN ({$placeholders})",
				$hashes
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Set the status of rows owned by a source, matched on url_hash.
	 *
	 * @param string[] $hashes    url_hash values.
	 * @param string   $status    'active' or 'stale'.
	 * @param string   $source_id Owning source id.
	 * @return int Rows changed.
	 */
	public function set_status_by_hashes( array $hashes, string $status, string $source_id ): int {
		global $wpdb;

		$hashes = array_values( array_unique( array_filter( array_map( 'strval', $hashes ) ) ) );

		if ( empty( $hashes ) || ! in_array( $status, array( 'active', 'stale' ), true ) ) {
			return 0;
		}

		$table        = $this->table();
		$placeholders = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$table} SET status = %s WHERE source_id = %s AND url_hash IN ({$placeholders})",
				array_merge( array( $status, $source_id ), $hashes )
			)
		);
	}

	/**
	 * Delete rows owned by a given source, matched on url_hash.
	 *
	 * Scoped to the source so an identical URL that also arrived from a real
	 * feed is left alone.
	 *
	 * @param string[] $hashes    url_hash values.
	 * @param string   $source_id Owning source id.
	 * @return int Rows deleted.
	 */
	public function delete_manual_by_hashes( array $hashes, string $source_id ): int {
		global $wpdb;

		$hashes = array_values( array_unique( array_filter( array_map( 'strval', $hashes ) ) ) );

		if ( empty( $hashes ) ) {
			return 0;
		}

		$table        = $this->table();
		$placeholders = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"DELETE FROM {$table} WHERE source_id = %s AND url_hash IN ({$placeholders})",
				array_merge( array( $source_id ), $hashes )
			)
		);
	}

	/**
	 * Selection candidates for one tier and pool.
	 *
	 * @param string $tier                One of 'pinned', 'unseen', 'least_shown'.
	 * @param string $pool                'news', 'network' or 'any'; see ADVTN_Source_Base::news_types().
	 * @param int    $limit               Max rows.
	 * @param int[]  $exclude_ids         Ids already chosen.
	 * @param int    $exposure_floor_days Pinned window, for the 'pinned' tier.
	 * @param string $max_age_cutoff      Oldest acceptable published_at, or ''.
	 * @return array<int,array<string,mixed>>
	 */
	public function candidates( string $tier, string $pool, int $limit, array $exclude_ids = array(), int $exposure_floor_days = 3, string $max_age_cutoff = '' ): array {
		global $wpdb;

		$limit = max( 0, $limit );
		if ( 0 === $limit ) {
			return array();
		}

		$table  = $this->table();
		$where  = array( 'status = %s' );
		$params = array( 'active' );

		$news_types = ADVTN_Source_Base::news_types();

		if ( ! empty( $news_types ) && in_array( $pool, array( 'news', 'network' ), true ) ) {
			$slots    = implode( ',', array_fill( 0, count( $news_types ), '%s' ) );
			$where[]  = ( 'news' === $pool ? 'source_type IN (' : 'source_type NOT IN (' ) . $slots . ')';
			$params   = array_merge( $params, $news_types );
		}

		switch ( $tier ) {
			case 'pinned':
				$where[]  = 'first_shown_at IS NOT NULL';
				$where[]  = 'first_shown_at > %s';
				$params[] = gmdate( 'Y-m-d H:i:s', time() - ( max( 0, $exposure_floor_days ) * DAY_IN_SECONDS ) );
				$order    = 'published_at DESC, id DESC';
				break;

			case 'unseen':
				$where[] = 'times_shown = 0';
				$order   = 'published_at DESC, id DESC';
				break;

			case 'least_shown':
				$where[] = 'times_shown > 0';
				$order   = 'times_shown ASC, published_at DESC, id DESC';
				break;

			default:
				return array();
		}

		// Curated links are exempt: they carry their own expiry, so an editor
		// pinning something older than the cutoff is a deliberate choice.
		if ( '' !== $max_age_cutoff ) {
			$where[]  = '( source_type = %s OR ( published_at IS NOT NULL AND published_at >= %s ) )';
			$params[] = 'manual';
			$params[] = $max_age_cutoff;
		}

		$exclude_ids = $this->clean_ids( $exclude_ids );
		if ( ! empty( $exclude_ids ) ) {
			$where[] = 'id NOT IN (' . $this->placeholders( $exclude_ids ) . ')';
			$params  = array_merge( $params, $exclude_ids );
		}

		$params[] = $limit;

		$sql = "SELECT id, url, title, excerpt, image_url, published_at, site_name, host, source_id, source_type, times_shown, first_shown_at
			FROM {$table}
			WHERE " . implode( ' AND ', $where ) . "
			ORDER BY {$order}
			LIMIT %d";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Newest active items, used as the render fallback when no selection exists.
	 *
	 * @param int    $limit          Max rows.
	 * @param string $max_age_cutoff Oldest acceptable published_at, or ''.
	 * @return array<int,array<string,mixed>>
	 */
	public function recent_active( int $limit, string $max_age_cutoff = '' ): array {
		global $wpdb;

		$limit  = max( 1, min( 500, $limit ) );
		$table  = $this->table();
		$params = array();
		$age    = '';

		if ( '' !== $max_age_cutoff ) {
			$age      = ' AND ( source_type = %s OR ( published_at IS NOT NULL AND published_at >= %s ) )';
			$params[] = 'manual';
			$params[] = $max_age_cutoff;
		}

		$params[] = $limit;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id, url, title, excerpt, image_url, published_at, site_name, host, source_id, source_type, times_shown, first_shown_at
				 FROM {$table}
				 WHERE status = 'active'{$age}
				 ORDER BY published_at DESC, id DESC
				 LIMIT %d",
				$params
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * The age clause shared by the archive's page and count queries.
	 *
	 * Deliberately identical to the one in candidates() and recent_active():
	 * curated links are exempt because they carry their own expiry, and a row
	 * with no published_at cannot be judged against a cutoff so it is dropped.
	 * The archive and the widget have to agree on what "current" means, or an
	 * item vanishes from one and not the other for no reason a reader can see.
	 *
	 * @param string             $max_age_cutoff Oldest acceptable published_at, or ''.
	 * @param array<int,mixed>   $params         Prepared parameters, appended to by reference.
	 * @return string SQL fragment beginning with WHERE, or ''.
	 */
	private function archive_age_where( string $max_age_cutoff, array &$params ): string {
		if ( '' === $max_age_cutoff ) {
			return '';
		}

		$params[] = 'manual';
		$params[] = $max_age_cutoff;

		return ' WHERE ( source_type = %s OR ( published_at IS NOT NULL AND published_at >= %s ) )';
	}

	/**
	 * A page of archive items. Includes stale rows; excludes anything past the
	 * age cutoff.
	 *
	 * @param int    $per_page       Page size.
	 * @param int    $offset         Row offset.
	 * @param string $max_age_cutoff Oldest acceptable published_at, or ''.
	 * @return array<int,array<string,mixed>>
	 */
	public function archive_page( int $per_page, int $offset, string $max_age_cutoff = '' ): array {
		global $wpdb;

		$per_page = max( 1, min( 200, $per_page ) );
		$offset   = max( 0, $offset );
		$table    = $this->table();

		$params = array();
		$where  = $this->archive_age_where( $max_age_cutoff, $params );

		$params[] = $per_page;
		$params[] = $offset;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id, url, title, excerpt, image_url, published_at, site_name, host, source_type
				 FROM {$table}{$where}
				 ORDER BY published_at DESC, id DESC
				 LIMIT %d OFFSET %d",
				$params
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Total archive row count, honouring the same age cutoff as archive_page().
	 *
	 * @param string $max_age_cutoff Oldest acceptable published_at, or ''.
	 * @return int
	 */
	public function archive_count( string $max_age_cutoff = '' ): int {
		global $wpdb;

		$table  = $this->table();
		$params = array();
		$where  = $this->archive_age_where( $max_age_cutoff, $params );

		if ( '' === $where ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$table}{$where}",
				$params
			)
		);
	}

	/**
	 * Items for the hub /items endpoint.
	 *
	 * @param int      $limit         Max rows (1-500).
	 * @param string[] $exclude_hosts Hosts to omit.
	 * @param string   $since         UTC datetime lower bound on published_at.
	 * @param string[] $types         source_type filter.
	 * @return array<int,array<string,mixed>>
	 */
	public function hub_items( int $limit, array $exclude_hosts = array(), string $since = '', array $types = array() ): array {
		global $wpdb;

		$limit  = max( 1, min( 500, $limit ) );
		$table  = $this->table();
		$where  = array( 'status = %s' );
		$params = array( 'active' );

		$exclude_hosts = array_values( array_filter( array_map( 'strval', $exclude_hosts ) ) );
		if ( ! empty( $exclude_hosts ) ) {
			$where[] = 'host NOT IN (' . implode( ',', array_fill( 0, count( $exclude_hosts ), '%s' ) ) . ')';
			$params  = array_merge( $params, $exclude_hosts );
		}

		if ( '' !== $since ) {
			$where[]  = 'published_at >= %s';
			$params[] = $since;
		}

		$types = array_values( array_filter( array_map( 'strval', $types ) ) );
		if ( ! empty( $types ) ) {
			$where[] = 'source_type IN (' . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ')';
			$params  = array_merge( $params, $types );
		}

		$params[] = $limit;

		$sql = "SELECT url, title, excerpt, image_url, published_at, site_name, host, source_type
			FROM {$table}
			WHERE " . implode( ' AND ', $where ) . '
			ORDER BY published_at DESC, id DESC
			LIMIT %d';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Counts for the diagnostics panel and /status.
	 *
	 * @return array<string,mixed>
	 */
	public function counts(): array {
		global $wpdb;

		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$by_status = $wpdb->get_results( "SELECT status, COUNT(*) AS c FROM {$table} GROUP BY status", ARRAY_A );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$by_type = $wpdb->get_results( "SELECT source_type, COUNT(*) AS c FROM {$table} GROUP BY source_type", ARRAY_A );

		$counts = array(
			'total'       => 0,
			'active'      => 0,
			'stale'       => 0,
			'by_type'     => array(),
			'never_shown' => 0,
			'last_7_days' => 0,
		);

		foreach ( (array) $by_status as $row ) {
			$counts['total'] += (int) $row['c'];
			if ( isset( $counts[ $row['status'] ] ) ) {
				$counts[ $row['status'] ] = (int) $row['c'];
			}
		}

		foreach ( (array) $by_type as $row ) {
			$counts['by_type'][ (string) $row['source_type'] ] = (int) $row['c'];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$counts['never_shown'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE times_shown = 0" );

		$week_ago = gmdate( 'Y-m-d H:i:s', time() - ( 7 * DAY_IN_SECONDS ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$counts['last_7_days'] = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$table} WHERE published_at >= %s",
				$week_ago
			)
		);

		return $counts;
	}

	/**
	 * Per-source row counts, for diagnostics.
	 *
	 * @return array<string,int>
	 */
	public function counts_by_source(): array {
		global $wpdb;

		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT source_id, COUNT(*) AS c FROM {$table} GROUP BY source_id", ARRAY_A );

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['source_id'] ] = (int) $row['c'];
		}

		return $out;
	}

	/**
	 * A filtered, paginated page of items for the admin browser.
	 *
	 * @param array<string,string> $filters  See build_where().
	 * @param int                  $per_page Page size.
	 * @param int                  $offset   Row offset.
	 * @return array<int,array<string,mixed>>
	 */
	public function browse( array $filters, int $per_page, int $offset ): array {
		global $wpdb;

		$per_page = max( 1, min( 200, $per_page ) );
		$offset   = max( 0, $offset );
		$table    = $this->table();
		$clause   = $this->build_where( $filters );

		$sql = "SELECT id, url, title, host, site_name, source_id, source_type, published_at,
					   first_seen, last_seen, first_shown_at, times_shown, status
				FROM {$table}
				WHERE " . $clause['sql'] . '
				ORDER BY last_seen DESC, id DESC
				LIMIT %d OFFSET %d';

		$params   = $clause['params'];
		$params[] = $per_page;
		$params[] = $offset;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Row count for the same filter as browse().
	 *
	 * @param array<string,string> $filters See build_where().
	 * @return int
	 */
	public function count_where( array $filters ): int {
		global $wpdb;

		$table  = $this->table();
		$clause = $this->build_where( $filters );

		$sql = "SELECT COUNT(*) FROM {$table} WHERE " . $clause['sql'];

		if ( empty( $clause['params'] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			return (int) $wpdb->get_var( $sql );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $clause['params'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Distinct hosts with their row counts, most rows first.
	 *
	 * @return array<int,array{host:string,n:int}>
	 */
	public function hosts(): array {
		global $wpdb;

		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT host, COUNT(*) AS n FROM {$table} GROUP BY host ORDER BY n DESC, host ASC", ARRAY_A );

		return array_map(
			static fn( $row ) => array(
				'host' => (string) $row['host'],
				'n'    => (int) $row['n'],
			),
			(array) $rows
		);
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Turn a filter array into a WHERE fragment plus its parameters.
	 *
	 * Yields `1=1` with no parameters when nothing is filtered, which is how
	 * delete_where() detects an unfiltered call and refuses it.
	 *
	 * @param array<string,string> $filters source_id, host, source_type, status, search.
	 * @return array{sql:string,params:array<int,string>}
	 */
	private function build_where( array $filters ): array {
		global $wpdb;

		$where  = array();
		$params = array();

		foreach ( array( 'source_id', 'host', 'source_type', 'status' ) as $column ) {
			$value = isset( $filters[ $column ] ) ? trim( (string) $filters[ $column ] ) : '';

			if ( '' !== $value ) {
				// Column names come from this fixed list, never from input.
				$where[]  = "{$column} = %s";
				$params[] = $value;
			}
		}

		$search = isset( $filters['search'] ) ? trim( (string) $filters['search'] ) : '';

		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(title LIKE %s OR url LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}

		return array(
			'sql'    => empty( $where ) ? '1=1' : implode( ' AND ', $where ),
			'params' => $params,
		);
	}

	/**
	 * Cast to positive unique ints.
	 *
	 * @param mixed[] $ids Raw ids.
	 * @return int[]
	 */
	private function clean_ids( array $ids ): array {
		$ids = array_map( 'intval', $ids );
		$ids = array_values( array_unique( array_filter( $ids, static fn( $id ) => $id > 0 ) ) );
		return $ids;
	}

	/**
	 * Build a `%d,%d,…` placeholder list.
	 *
	 * @param int[] $ids Ids.
	 * @return string
	 */
	private function placeholders( array $ids ): string {
		return implode( ',', array_fill( 0, count( $ids ), '%d' ) );
	}
}
