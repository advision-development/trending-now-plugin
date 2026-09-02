<?php
/**
 * Editorially curated links.
 *
 * The list itself lives in an option, because it is configuration rather than
 * harvested data. The links are also written into the items table through a
 * synthetic source, so they dedupe against anything ingested, appear in the
 * archive, and carry the same display counters as everything else.
 *
 * Position and expiry stay here rather than in the table: they describe the
 * editorial decision, not the article.
 *
 * @package Advision\TrendingNow
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ADVTN_Manual {

	public const OPTION    = 'advtn_manual_links';
	public const SOURCE_ID = 'src_manual';
	public const HOOK      = 'advtn_manual_expire';

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
	 * Bind the expiry sweep.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( self::HOOK, array( $this, 'on_expiry' ) );
	}

	/**
	 * Every configured link, in list order.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function all(): array {
		$links = get_option( self::OPTION, array() );

		return is_array( $links ) ? array_values( $links ) : array();
	}

	/**
	 * Links that should be on display right now.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function active(): array {
		return array_values(
			array_filter(
				$this->all(),
				fn( $link ) => ! empty( $link['enabled'] ) && ! $this->is_expired( $link )
			)
		);
	}

	/**
	 * Whether a link's timer has run out.
	 *
	 * @param array<string,mixed> $link Link row.
	 * @return bool
	 */
	public function is_expired( array $link ): bool {
		$expires = (string) ( $link['expires_at'] ?? '' );

		if ( '' === $expires ) {
			return false;
		}

		$timestamp = strtotime( $expires . ' UTC' );

		return false !== $timestamp && $timestamp <= time();
	}

	/**
	 * Seconds until a link expires; null when it never does.
	 *
	 * @param array<string,mixed> $link Link row.
	 * @return int|null
	 */
	public function expires_in( array $link ): ?int {
		$expires = (string) ( $link['expires_at'] ?? '' );

		if ( '' === $expires ) {
			return null;
		}

		$timestamp = strtotime( $expires . ' UTC' );

		return false === $timestamp ? null : $timestamp - time();
	}

	/**
	 * Replace the whole list, validating each row.
	 *
	 * `changed` reports whether the list a visitor would be served differs from
	 * the one that was stored before this call. Callers use it to decide whether
	 * a selection rebuild is warranted — see `served_list_changed()` for why
	 * that decision cannot be left to the caller's own `===`.
	 *
	 * @param array<int,array<string,mixed>> $rows Raw rows from the form.
	 * @return array{links:array<int,array<string,mixed>>,errors:array<int,string>,changed:bool}
	 */
	public function save( array $rows ): array {
		$clean  = array();
		$errors = array();
		$seen   = array();

		foreach ( $rows as $index => $row ) {
			if ( ! is_array( $row ) || ! empty( $row['_delete'] ) ) {
				continue;
			}

			// A blank row that was added and never filled in.
			if ( '' === trim( (string) ( $row['url'] ?? '' ) ) && '' === trim( (string) ( $row['title'] ?? '' ) ) ) {
				continue;
			}

			$validated = $this->validate( $row );

			if ( is_wp_error( $validated ) ) {
				/* translators: 1: row number, 2: error message. */
				$errors[] = sprintf( __( 'Row %1$d: %2$s', 'trending-now' ), (int) $index + 1, $validated->get_error_message() );
				continue;
			}

			// Two rows pointing at the same article would collapse to one item
			// on write and silently lose one of the two positions.
			$hash = ADVTN_URL::hash( $validated['url'] );

			if ( isset( $seen[ $hash ] ) ) {
				/* translators: %s: link title. */
				$errors[] = sprintf( __( 'Duplicate URL skipped: %s', 'trending-now' ), $validated['title'] );
				continue;
			}

			$seen[ $hash ] = true;
			$clean[]       = $validated;
		}

		$previous = $this->all();

		update_option( self::OPTION, $clean, false );

		$this->forget_removed( $previous, $clean );
		$this->sync();
		$this->schedule_next_expiry();

		return array(
			'links'   => $clean,
			'errors'  => $errors,
			'changed' => self::served_list_changed( $previous, $clean ),
		);
	}

	/**
	 * Whether two stored lists would put different links in front of a visitor.
	 *
	 * Pure and static so the decision is testable: `tests/run.php` runs without
	 * `$wpdb`, and the thing this decision protects — `mark_shown()` — is SQL.
	 * The decision is the part that can be wrong, so the decision is what is
	 * asserted on.
	 *
	 * WHY THIS EXISTS. A caller cannot just compare the two lists with `===`.
	 * `validate()` mints `id` from `uniqid()` and defaults `created_at` to
	 * `gmdate()` whenever the incoming row carries neither — and the feed
	 * payload carries neither: `ADVTN_Manual_Feed_Parser::map_row()` emits no
	 * `id` and no `created_at`. So two commits of a byte-identical feed produce
	 * two lists that differ in every row, and a `===` guard would be a guard
	 * that never fires. That is the trap this method is here to not fall into.
	 *
	 * WHAT IS COMPARED, and why it is these fields. Everything the feed can
	 * actually say, in the order it said it:
	 *
	 * - `url`, `title`, `excerpt`, `image_url`, `site_name`, `published_at`
	 *   are what `sync()` writes into the items table, so any of them moving
	 *   changes what is rendered.
	 * - `position` and list order decide placement in `ADVTN_Selector`.
	 * - `expires_at` and `enabled` decide whether a row is served at all.
	 *
	 * And the two that are deliberately excluded:
	 *
	 * - `id` is a local handle. It is re-minted on every feed commit, it never
	 *   reaches the rendered output (the items table is keyed on `url_hash`),
	 *   and including it would make every comparison unequal.
	 * - `created_at` is re-minted the same way for the same reason. It reaches
	 *   output only as `sync()`'s fallback when `published_at` is empty, and
	 *   treating a locally-generated timestamp as feed news would again mean
	 *   the guard never fires.
	 *
	 * This is a comparison of the *served list*, not of the response bytes: a
	 * feed that re-orders its JSON keys, changes its `version`, or re-serves
	 * the same links reads as unchanged, which is the point.
	 *
	 * @param array<int,array<string,mixed>> $before List as stored before.
	 * @param array<int,array<string,mixed>> $after  List as stored after.
	 * @return bool
	 */
	public static function served_list_changed( array $before, array $after ): bool {
		return self::comparable( $before ) !== self::comparable( $after );
	}

	/**
	 * Reduce a stored list to the fields that decide what is served.
	 *
	 * `array_values()` because a list that lost its middle row keeps the outer
	 * keys 0 and 2, and two lists that differ only in key numbering are the
	 * same list. Every field is cast so that `0` and `'0'`, or `false` and
	 * `''`, cannot read as a change — the two callers reach this with rows
	 * built by `validate()` on one side and rows read back out of an option on
	 * the other, and PHP's serializer does not promise those round-trip with
	 * identical types.
	 *
	 * @param array<int,array<string,mixed>> $list Stored list.
	 * @return array<int,array<string,string|int|bool>>
	 */
	private static function comparable( array $list ): array {
		$out = array();

		foreach ( array_values( $list ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$out[] = array(
				'url'          => (string) ( $row['url'] ?? '' ),
				'title'        => (string) ( $row['title'] ?? '' ),
				'excerpt'      => (string) ( $row['excerpt'] ?? '' ),
				'image_url'    => (string) ( $row['image_url'] ?? '' ),
				'site_name'    => (string) ( $row['site_name'] ?? '' ),
				'published_at' => (string) ( $row['published_at'] ?? '' ),
				'expires_at'   => (string) ( $row['expires_at'] ?? '' ),
				'position'     => (int) ( $row['position'] ?? 0 ),
				'enabled'      => ! empty( $row['enabled'] ),
			);
		}

		return $out;
	}

	/**
	 * Validate and normalize one row.
	 *
	 * @param array<string,mixed> $row Raw row.
	 * @return array<string,mixed>|WP_Error
	 */
	public function validate( array $row ) {
		$url = trim( (string) ( $row['url'] ?? '' ) );

		if ( '' === $url ) {
			return new WP_Error( 'advtn_manual_no_url', __( 'A URL is required.', 'trending-now' ) );
		}

		if ( ! ADVTN_URL::is_valid( $url ) ) {
			return new WP_Error( 'advtn_manual_bad_url', __( 'That URL is not a valid public http(s) address.', 'trending-now' ) );
		}

		$title = trim( wp_strip_all_tags( (string) ( $row['title'] ?? '' ) ) );

		if ( '' === $title ) {
			return new WP_Error( 'advtn_manual_no_title', __( 'A title is required — it becomes the anchor text.', 'trending-now' ) );
		}

		$image = trim( (string) ( $row['image_url'] ?? '' ) );

		if ( '' !== $image && ! ADVTN_URL::is_valid( $image ) ) {
			return new WP_Error( 'advtn_manual_bad_image', __( 'That image URL is not valid.', 'trending-now' ) );
		}

		return array(
			'id'           => ! empty( $row['id'] ) ? (string) preg_replace( '/[^a-z0-9_]/', '', (string) $row['id'] ) : 'man_' . substr( md5( uniqid( '', true ) ), 0, 6 ),
			'url'          => esc_url_raw( $url ),
			'title'        => mb_substr( $title, 0, 500 ),
			'excerpt'      => mb_substr( trim( wp_strip_all_tags( (string) ( $row['excerpt'] ?? '' ) ) ), 0, 500 ),
			'image_url'    => '' !== $image ? esc_url_raw( $image ) : '',
			'site_name'    => mb_substr( sanitize_text_field( (string) ( $row['site_name'] ?? '' ) ), 0, 191 ),
			'published_at' => $this->clean_datetime( (string) ( $row['published_at'] ?? '' ) ),
			'expires_at'   => $this->clean_datetime( (string) ( $row['expires_at'] ?? '' ) ),
			'position'     => max( 0, min( 200, (int) ( $row['position'] ?? 0 ) ) ),
			'enabled'      => ! empty( $row['enabled'] ),
			'created_at'   => $this->clean_datetime( (string) ( $row['created_at'] ?? '' ) ) ?: gmdate( 'Y-m-d H:i:s' ),
		);
	}

	/**
	 * Write the active links into the items table.
	 *
	 * Called on save as well as on every ingest cycle: on save so a new link
	 * shows up without waiting for cron, and on cycle so `last_seen` keeps
	 * moving and the stale sweep leaves them alone.
	 *
	 * @return int Rows written.
	 */
	public function sync(): int {
		$this->retire_expired();

		$written = 0;

		foreach ( $this->active() as $link ) {
			$outcome = $this->repository->upsert_item(
				array(
					'url'          => $link['url'],
					'title'        => $link['title'],
					'excerpt'      => '' !== $link['excerpt'] ? $link['excerpt'] : null,
					'image_url'    => '' !== $link['image_url'] ? $link['image_url'] : null,
					'published_at' => '' !== $link['published_at'] ? $link['published_at'] : $link['created_at'],
					'site_name'    => '' !== $link['site_name'] ? $link['site_name'] : ADVTN_URL::host( $link['url'] ),
					'source_type'  => 'manual',
				),
				self::SOURCE_ID
			);

			if ( 'skip' !== $outcome ) {
				++$written;
			}
		}

		return $written;
	}

	/**
	 * Mark expired or disabled links stale.
	 *
	 * Leaving the row `active` would not stop it showing: it would merely stop
	 * being *placed* and carry on competing as an ordinary candidate, which is
	 * indistinguishable from the timer doing nothing. `stale` already means
	 * exactly what is wanted here — out of the widget, kept in the archive —
	 * and a re-enabled link goes back to `active` on the next sync.
	 *
	 * @return int Rows changed.
	 */
	public function retire_expired(): int {
		$hashes = array();

		foreach ( $this->all() as $link ) {
			if ( ! empty( $link['enabled'] ) && ! $this->is_expired( $link ) ) {
				continue;
			}

			$hash = ADVTN_URL::hash( (string) ( $link['url'] ?? '' ) );

			if ( '' !== $hash ) {
				$hashes[] = $hash;
			}
		}

		return $this->repository->set_status_by_hashes( $hashes, 'stale', self::SOURCE_ID );
	}

	/**
	 * The active links joined to their stored rows, ready for selection.
	 *
	 * @return array<int,array<string,mixed>> Item rows carrying `_position`.
	 */
	public function selection_rows(): array {
		$active = $this->active();

		if ( empty( $active ) ) {
			return array();
		}

		$by_hash = array();

		foreach ( $active as $link ) {
			$hash = ADVTN_URL::hash( $link['url'] );

			if ( '' !== $hash ) {
				$by_hash[ $hash ] = $link;
			}
		}

		$rows = $this->repository->get_by_hashes( array_keys( $by_hash ) );
		$out  = array();

		foreach ( $rows as $row ) {
			$link = $by_hash[ (string) $row['url_hash'] ] ?? null;

			if ( null === $link ) {
				continue;
			}

			$row['_position'] = (int) $link['position'];
			$row['_manual']   = true;
			$out[]            = $row;
		}

		return $out;
	}

	/**
	 * Earliest future expiry across the list.
	 *
	 * @return int|null Unix timestamp, or null when nothing expires.
	 */
	public function next_expiry(): ?int {
		$next = null;

		foreach ( $this->all() as $link ) {
			if ( empty( $link['enabled'] ) ) {
				continue;
			}

			$remaining = $this->expires_in( $link );

			if ( null === $remaining || $remaining <= 0 ) {
				continue;
			}

			$at = time() + $remaining;

			if ( null === $next || $at < $next ) {
				$next = $at;
			}
		}

		return $next;
	}

	/**
	 * Queue a rebuild for the moment the next link expires.
	 *
	 * Without this an expired link would sit in the cached HTML until the next
	 * ingest cycle, which on the default interval is up to 20 hours — not much
	 * of a timer.
	 *
	 * @return void
	 */
	public function schedule_next_expiry(): void {
		$scheduler = advtn()->scheduler();
		$scheduler->unschedule( self::HOOK );

		$next = $this->next_expiry();

		if ( null === $next ) {
			return;
		}

		$scheduler->schedule_single( $next + 30, self::HOOK );
	}

	/**
	 * Rebuild the selection when a link's timer runs out.
	 *
	 * @return void
	 */
	public function on_expiry(): void {
		$this->retire_expired();
		advtn()->selector()->build_and_commit();
		advtn()->renderer()->purge_cache();
		ADVTN_Page_Cache::purge();
		$this->schedule_next_expiry();

		ADVTN_Logger::log( 'info', 'Manual link expired; selection rebuilt.' );
	}

	/**
	 * Delete stored rows for links that have left the list.
	 *
	 * @param array<int,array<string,mixed>> $before Previous list.
	 * @param array<int,array<string,mixed>> $after  New list.
	 * @return void
	 */
	private function forget_removed( array $before, array $after ): void {
		$kept = array();

		foreach ( $after as $link ) {
			$kept[ ADVTN_URL::hash( (string) $link['url'] ) ] = true;
		}

		$gone = array();

		foreach ( $before as $link ) {
			$hash = ADVTN_URL::hash( (string) ( $link['url'] ?? '' ) );

			if ( '' !== $hash && ! isset( $kept[ $hash ] ) ) {
				$gone[] = $hash;
			}
		}

		if ( empty( $gone ) ) {
			return;
		}

		// Only remove rows this feature owns; an identical URL that also
		// arrived from a real source stays put.
		$this->repository->delete_manual_by_hashes( $gone, self::SOURCE_ID );
	}

	/**
	 * Normalize a datetime-local or date string to UTC storage format.
	 *
	 * @param string $value Raw value.
	 * @return string 'Y-m-d H:i:s' or ''.
	 */
	private function clean_datetime( string $value ): string {
		$value = trim( str_replace( 'T', ' ', $value ) );

		if ( '' === $value ) {
			return '';
		}

		$timestamp = strtotime( $value . ' UTC' );

		return false === $timestamp ? '' : gmdate( 'Y-m-d H:i:s', $timestamp );
	}
}
