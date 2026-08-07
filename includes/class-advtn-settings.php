<?php
/**
 * Typed access to the three plugin options.
 *
 * @package Advision\TrendingNow
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ADVTN_Settings {

	public const OPTION_SETTINGS = 'advtn_settings';
	public const OPTION_SOURCES  = 'advtn_sources';
	public const OPTION_STATE    = 'advtn_source_state';

	/**
	 * Memoized settings array.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $cache = null;

	/**
	 * Canonical defaults. Also the sanitization schema's source of truth.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'mode'                     => 'direct',
			'widget_limit'             => 30,
			'max_age_hours'            => 0,
			// Out of the box this is the Google News style card: source line,
			// headline, thumbnail pinned right. Images default on because the
			// layout is built around them.
			'layout'                   => 'news',
			'show_images'              => true,
			'show_source'              => true,
			'show_icons'               => false,
			'show_excerpt'             => false,
			'show_date'                => true,
			'date_style'               => 'relative',
			'news_share_pct'           => 20,
			'max_source_share_pct'     => 20,
			'exposure_floor_days'      => 3,
			'retention_days'           => 90,
			'ingest_interval_hours'    => 20,
			'stagger_minutes'          => 7,
			'batch_max_sources'        => 3,
			'batch_time_budget'        => 20,
			'http_timeout'             => 5,
			'source_fail_backoff'      => 3600,
			'archive_slug'             => 'trending',
			'archive_per_page'         => 50,
			'archive_noindex'          => false,
			'archive_enabled'          => true,
			'archive_intro'            => '',
			'link_target_blank'        => true,
			'link_rel_external'        => '',
			'heading_text'             => 'Trending Now',
			'see_all_text'             => 'See all trending stories',
			'class_prefix'             => 'advtn',
			'hub_url'                  => '',
			'hub_secret'               => '',
			'ingest_secret'            => '',
			'serpapi_key'              => '',
			'github_token'             => '',
			'auto_update'              => true,
			'delete_data_on_uninstall' => false,
		);
	}

	/**
	 * Full settings array, defaults merged.
	 *
	 * @return array<string,mixed>
	 */
	public function all(): array {
		if ( null === $this->cache ) {
			$stored      = get_option( self::OPTION_SETTINGS, array() );
			$stored      = is_array( $stored ) ? $stored : array();
			$this->cache = array_merge( self::defaults(), $stored );
		}
		return $this->cache;
	}

	/**
	 * Read one setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback when the key is unknown.
	 * @return mixed
	 */
	public function get( string $key, $default = null ) {
		$all = $this->all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Integer accessor with clamping.
	 *
	 * @param string $key Setting key.
	 * @param int    $min Minimum.
	 * @param int    $max Maximum.
	 * @return int
	 */
	public function get_int( string $key, int $min, int $max ): int {
		return max( $min, min( $max, (int) $this->get( $key, 0 ) ) );
	}

	/**
	 * Boolean accessor.
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	public function get_bool( string $key ): bool {
		return (bool) $this->get( $key, false );
	}

	/**
	 * String accessor.
	 *
	 * @param string $key Setting key.
	 * @return string
	 */
	public function get_string( string $key ): string {
		return (string) $this->get( $key, '' );
	}

	/**
	 * Read a credential, preferring a wp-config.php constant.
	 *
	 * `serpapi_key` maps to `ADVTN_SERPAPI_KEY`, and so on. A constant keeps the
	 * secret out of the database and out of any settings export, which is what
	 * you want on a real host; the stored option stays as the fallback so the
	 * admin screen still works for people who have nowhere to put a constant.
	 *
	 * @param string $key Setting key.
	 * @return string
	 */
	public function get_secret( string $key ): string {
		$constant = 'ADVTN_' . strtoupper( $key );

		if ( defined( $constant ) ) {
			$value = trim( (string) constant( $constant ) );

			if ( '' !== $value ) {
				return $value;
			}
		}

		return trim( $this->get_string( $key ) );
	}

	/**
	 * Whether a credential is being supplied by a constant.
	 *
	 * The admin field is disabled when it is, so an empty box is not mistaken
	 * for an unset key.
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	public function secret_is_constant( string $key ): bool {
		$constant = 'ADVTN_' . strtoupper( $key );

		return defined( $constant ) && '' !== trim( (string) constant( $constant ) );
	}

	/**
	 * Persist a partial settings update after sanitization.
	 *
	 * @param array<string,mixed> $values Raw values.
	 * @return array<string,mixed> The stored settings.
	 */
	public function update( array $values ): array {
		$before = $this->all();
		$merged = array_merge( $before, $values );
		$clean  = self::sanitize( $merged );

		update_option( self::OPTION_SETTINGS, $clean, true );
		$this->cache = $clean;

		// The archive's rewrite rules are built from these two, so anything
		// that changes them has to schedule a flush. This lives here rather
		// than in the admin handler because the settings are equally reachable
		// from WP-CLI, a migration or a provisioning script — and a slug change
		// that never flushes leaves the archive quietly 404ing.
		if ( $before['archive_slug'] !== $clean['archive_slug'] || $before['archive_enabled'] !== $clean['archive_enabled'] ) {
			update_option( 'advtn_flush_rewrites', 1, false );
		}

		return $clean;
	}

	/**
	 * Sanitize and range-clamp an entire settings array.
	 *
	 * @param array<string,mixed> $input Raw input.
	 * @return array<string,mixed>
	 */
	public static function sanitize( array $input ): array {
		$d   = self::defaults();
		$out = $d;

		$mode          = isset( $input['mode'] ) ? (string) $input['mode'] : $d['mode'];
		$out['mode']   = in_array( $mode, array( 'direct', 'hub', 'spoke' ), true ) ? $mode : 'direct';

		$out['widget_limit']          = self::clamp_int( $input['widget_limit'] ?? $d['widget_limit'], 1, 200 );
		// 0 disables the cutoff entirely; 720 is 30 days.
		$out['max_age_hours']         = self::clamp_int( $input['max_age_hours'] ?? $d['max_age_hours'], 0, 720 );
		$out['news_share_pct']        = self::clamp_int( $input['news_share_pct'] ?? $d['news_share_pct'], 0, 50 );
		$out['max_source_share_pct']  = self::clamp_int( $input['max_source_share_pct'] ?? $d['max_source_share_pct'], 5, 100 );
		$out['exposure_floor_days']   = self::clamp_int( $input['exposure_floor_days'] ?? $d['exposure_floor_days'], 0, 30 );
		$out['retention_days']        = self::clamp_int( $input['retention_days'] ?? $d['retention_days'], 1, 3650 );
		$out['ingest_interval_hours'] = self::clamp_int( $input['ingest_interval_hours'] ?? $d['ingest_interval_hours'], 1, 168 );
		$out['stagger_minutes']       = self::clamp_int( $input['stagger_minutes'] ?? $d['stagger_minutes'], 0, 120 );
		$out['batch_max_sources']     = self::clamp_int( $input['batch_max_sources'] ?? $d['batch_max_sources'], 1, 25 );
		$out['batch_time_budget']     = self::clamp_int( $input['batch_time_budget'] ?? $d['batch_time_budget'], 5, 120 );
		// GDELT routinely needs 10-20s, well past the 5s default, so the ceiling
		// has to leave room for it.
		$out['http_timeout']          = self::clamp_int( $input['http_timeout'] ?? $d['http_timeout'], 1, 60 );
		$out['source_fail_backoff']   = self::clamp_int( $input['source_fail_backoff'] ?? $d['source_fail_backoff'], 60, 86400 );
		$out['archive_per_page']      = self::clamp_int( $input['archive_per_page'] ?? $d['archive_per_page'], 5, 200 );

		$slug                  = sanitize_title( (string) ( $input['archive_slug'] ?? $d['archive_slug'] ) );
		$out['archive_slug']   = '' !== $slug ? $slug : $d['archive_slug'];

		$prefix                = preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) ( $input['class_prefix'] ?? $d['class_prefix'] ) ) );
		$out['class_prefix']   = '' !== (string) $prefix ? (string) $prefix : $d['class_prefix'];

		$layout         = (string) ( $input['layout'] ?? $d['layout'] );
		$out['layout']  = in_array( $layout, self::layouts(), true ) ? $layout : 'list';

		$out['show_images'] = ! empty( $input['show_images'] );
		$out['show_source'] = ! empty( $input['show_source'] );
		$out['show_icons']  = ! empty( $input['show_icons'] );
		$out['show_excerpt'] = ! empty( $input['show_excerpt'] );
		$out['show_date']   = ! empty( $input['show_date'] );

		$style             = (string) ( $input['date_style'] ?? $d['date_style'] );
		$out['date_style'] = in_array( $style, array( 'relative', 'date' ), true ) ? $style : 'relative';

		$out['archive_noindex']          = ! empty( $input['archive_noindex'] );
		$out['archive_enabled']          = ! empty( $input['archive_enabled'] );
		$out['link_target_blank']        = ! empty( $input['link_target_blank'] );
		$out['delete_data_on_uninstall'] = ! empty( $input['delete_data_on_uninstall'] );

		$rel                        = (string) ( $input['link_rel_external'] ?? '' );
		$out['link_rel_external']   = in_array( $rel, array( '', 'nofollow', 'sponsored' ), true ) ? $rel : '';

		$out['heading_text']  = sanitize_text_field( (string) ( $input['heading_text'] ?? $d['heading_text'] ) );
		$out['see_all_text']  = sanitize_text_field( (string) ( $input['see_all_text'] ?? $d['see_all_text'] ) );
		$out['archive_intro'] = wp_kses_post( (string) ( $input['archive_intro'] ?? '' ) );

		$hub_url          = trim( (string) ( $input['hub_url'] ?? '' ) );
		$out['hub_url']   = '' !== $hub_url ? untrailingslashit( esc_url_raw( $hub_url ) ) : '';

		$out['auto_update'] = ! empty( $input['auto_update'] );

		// Credentials: printable, no whitespace. SerpAPI keys are hex; GitHub
		// tokens carry underscores and are longer.
		$out['serpapi_key']  = (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) ( $input['serpapi_key'] ?? '' ) );
		$out['github_token'] = (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) ( $input['github_token'] ?? '' ) );

		$out['hub_secret']    = preg_replace( '/[^A-Za-z0-9]/', '', (string) ( $input['hub_secret'] ?? '' ) ) ?? '';
		$out['ingest_secret'] = preg_replace( '/[^A-Za-z0-9]/', '', (string) ( $input['ingest_secret'] ?? '' ) ) ?? '';

		return $out;
	}

	/**
	 * Available widget layouts.
	 *
	 * @return string[]
	 */
	public static function layouts(): array {
		return array( 'list', 'cards', 'news' );
	}

	/**
	 * The published_at cutoff, or '' when no cutoff is configured.
	 *
	 * @return string 'Y-m-d H:i:s' in UTC, or ''.
	 */
	public function max_age_cutoff(): string {
		$hours = $this->get_int( 'max_age_hours', 0, 720 );

		return $hours > 0 ? gmdate( 'Y-m-d H:i:s', time() - ( $hours * HOUR_IN_SECONDS ) ) : '';
	}

	/**
	 * Clamp an arbitrary scalar to an integer range.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $min   Minimum.
	 * @param int   $max   Maximum.
	 * @return int
	 */
	private static function clamp_int( $value, int $min, int $max ): int {
		return max( $min, min( $max, (int) $value ) );
	}

	/* ---------------------------------------------------------------------
	 * Sources
	 * ------------------------------------------------------------------ */

	/**
	 * All configured source rows in display order.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function sources(): array {
		$sources = get_option( self::OPTION_SOURCES, array() );
		return is_array( $sources ) ? array_values( $sources ) : array();
	}

	/**
	 * Only enabled source rows.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function enabled_sources(): array {
		return array_values(
			array_filter(
				$this->sources(),
				static fn( $s ) => ! empty( $s['enabled'] )
			)
		);
	}

	/**
	 * Look up one source row by id.
	 *
	 * @param string $source_id Source id.
	 * @return array<string,mixed>|null
	 */
	public function source( string $source_id ): ?array {
		foreach ( $this->sources() as $source ) {
			if ( ( $source['id'] ?? '' ) === $source_id ) {
				return $source;
			}
		}
		return null;
	}

	/**
	 * Replace the whole source list, reassigning stagger indexes by position.
	 *
	 * @param array<int,array<string,mixed>> $sources Ordered source rows.
	 * @return void
	 */
	public function save_sources( array $sources ): void {
		$out = array();
		$i   = 0;

		foreach ( $sources as $source ) {
			if ( empty( $source['id'] ) ) {
				continue;
			}
			$source['stagger_index'] = $i++;
			$out[]                   = $source;
		}

		update_option( self::OPTION_SOURCES, $out, false );
	}

	/**
	 * Generate an immutable source id.
	 *
	 * @return string
	 */
	public static function new_source_id(): string {
		return 'src_' . substr( md5( uniqid( '', true ) ), 0, 6 );
	}

	/* ---------------------------------------------------------------------
	 * Runtime source state
	 * ------------------------------------------------------------------ */

	/**
	 * Full runtime state map.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function state(): array {
		$state = get_option( self::OPTION_STATE, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * State for one source.
	 *
	 * @param string $source_id Source id.
	 * @return array<string,mixed>
	 */
	public function source_state( string $source_id ): array {
		$state = $this->state();
		return isset( $state[ $source_id ] ) && is_array( $state[ $source_id ] ) ? $state[ $source_id ] : array();
	}

	/**
	 * Merge a partial state update for one source.
	 *
	 * @param string              $source_id Source id.
	 * @param array<string,mixed> $patch     Fields to write.
	 * @return void
	 */
	public function update_source_state( string $source_id, array $patch ): void {
		$state               = $this->state();
		$current             = isset( $state[ $source_id ] ) && is_array( $state[ $source_id ] ) ? $state[ $source_id ] : array();
		$state[ $source_id ] = array_merge( $current, $patch );

		update_option( self::OPTION_STATE, $state, false );
	}

	/**
	 * Drop state rows for sources that no longer exist.
	 *
	 * @return void
	 */
	public function prune_state(): void {
		$ids   = array_column( $this->sources(), 'id' );
		$ids[] = 'src_hub';
		$state = array_intersect_key( $this->state(), array_flip( $ids ) );

		update_option( self::OPTION_STATE, $state, false );
	}
}
