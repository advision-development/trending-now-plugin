<?php
/**
 * HTML generation and the render cache.
 *
 * Hard constraints: server-side only, zero HTTP requests during render, zero
 * database queries on a warm cache, and an empty string (not an empty
 * container) when there is nothing to show.
 *
 * @package Advision\TrendingNow
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ADVTN_Renderer {

	public const CACHE_PREFIX   = 'advtn_render_cache_';
	public const CACHE_REGISTRY = 'advtn_render_cache_keys';
	public const MAX_VARIANTS   = 20;

	/**
	 * Placeholder swapped for the inline stylesheet at output time so the CSS
	 * is emitted once per page even when the blob is reused.
	 */
	private const CSS_MARKER = '<!--advtn:css-->';

	/**
	 * Whether the inline stylesheet has already gone out this request.
	 *
	 * @var bool
	 */
	private static bool $css_emitted = false;

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
	 * Render the widget, using the cache when possible.
	 *
	 * @param array<string,mixed> $args Display arguments.
	 * @return string HTML, or '' when there is nothing to render.
	 */
	public function render( array $args = array() ): string {
		$args = $this->normalize_args( $args );
		$key  = self::CACHE_PREFIX . md5( (string) wp_json_encode( $args ) );

		$cached = get_option( $key, false );

		if ( is_string( $cached ) ) {
			return $this->emit( $cached );
		}

		if ( ! $this->register_variant( $key ) ) {
			// Over the variant cap: render uncached rather than growing the
			// registry without bound.
			ADVTN_Logger::log(
				'warning',
				'Render cache variant cap reached; rendering uncached.',
				array( 'variants' => self::MAX_VARIANTS )
			);

			return $this->emit( $this->build( $args ) );
		}

		$html = $this->build( $args );

		update_option( $key, $html, false );

		return $this->emit( $html );
	}

	/**
	 * Build HTML directly, bypassing the cache entirely.
	 *
	 * @param array<string,mixed> $args Display arguments.
	 * @return string
	 */
	public function render_uncached( array $args = array() ): string {
		return $this->emit( $this->build( $this->normalize_args( $args ) ) );
	}

	/**
	 * Delete every cached variant and reset the registry.
	 *
	 * @return int Number of keys deleted.
	 */
	public function purge_cache(): int {
		$keys = get_option( self::CACHE_REGISTRY, array() );
		$keys = is_array( $keys ) ? $keys : array();

		foreach ( $keys as $key ) {
			delete_option( (string) $key );
		}

		update_option( self::CACHE_REGISTRY, array(), false );

		return count( $keys );
	}

	/**
	 * Cached variants with their byte sizes, for diagnostics.
	 *
	 * @return array<string,int>
	 */
	public function cache_status(): array {
		$keys = get_option( self::CACHE_REGISTRY, array() );
		$keys = is_array( $keys ) ? $keys : array();

		$out = array();
		foreach ( $keys as $key ) {
			$value       = get_option( (string) $key, '' );
			$out[ $key ] = is_string( $value ) ? strlen( $value ) : 0;
		}

		return $out;
	}

	/**
	 * Canonical, order-stable argument set. Also the cache key input.
	 *
	 * @param array<string,mixed> $args Raw arguments.
	 * @return array<string,mixed>
	 */
	public function normalize_args( array $args ): array {
		// Defaults come from Settings so the admin screen actually controls the
		// widget; the shortcode and block override per instance.
		$defaults = array(
			'limit'        => $this->settings->get_int( 'widget_limit', 1, 200 ),
			'layout'       => $this->settings->get_string( 'layout' ),
			'heading'      => $this->settings->get_string( 'heading_text' ),
			'show_images'  => $this->settings->get_bool( 'show_images' ),
			'show_source'  => $this->settings->get_bool( 'show_source' ),
			'show_icons'   => $this->settings->get_bool( 'show_icons' ),
			'show_date'    => $this->settings->get_bool( 'show_date' ),
			'show_see_all' => true,
		);

		$args = array_merge( $defaults, array_intersect_key( $args, $defaults ) );

		$args['limit']        = max( 1, min( 200, (int) $args['limit'] ) );
		$args['layout']       = in_array( $args['layout'], ADVTN_Settings::layouts(), true ) ? $args['layout'] : 'list';
		$args['heading']      = sanitize_text_field( (string) $args['heading'] );
		$args['show_images']  = $this->to_bool( $args['show_images'] );
		$args['show_source']  = $this->to_bool( $args['show_source'] );
		$args['show_icons']   = $this->to_bool( $args['show_icons'] );
		$args['show_date']    = $this->to_bool( $args['show_date'] );
		$args['show_see_all'] = $this->to_bool( $args['show_see_all'] );

		ksort( $args );

		return $args;
	}

	/**
	 * Escaped anchor attributes for one item.
	 *
	 * `rel` is applied only to news items and only when configured. Network
	 * links stay plain followed links — that is the entire point of the plugin.
	 *
	 * @param array<string,mixed> $item Item row.
	 * @return string Leading-space-prefixed attribute string.
	 */
	public function link_attributes( array $item ): string {
		$attrs = array();
		$rel   = array();

		if ( ADVTN_Source_Base::is_news_type( (string) ( $item['source_type'] ?? '' ) ) ) {
			$configured = $this->settings->get_string( 'link_rel_external' );
			if ( '' !== $configured ) {
				$rel[] = $configured;
			}
		}

		if ( $this->settings->get_bool( 'link_target_blank' ) ) {
			$attrs[] = 'target="_blank"';
			$rel[]   = 'noopener';
		}

		if ( ! empty( $rel ) ) {
			$attrs[] = 'rel="' . esc_attr( implode( ' ', array_unique( $rel ) ) ) . '"';
		}

		return empty( $attrs ) ? '' : ' ' . implode( ' ', $attrs );
	}

	/**
	 * Machine-readable and human date pair for an item.
	 *
	 * @param array<string,mixed> $item Item row.
	 * @return array{iso:string,label:string}|null
	 */
	public function item_date( array $item ): ?array {
		$published = (string) ( $item['published_at'] ?? '' );
		if ( '' === $published ) {
			return null;
		}

		$timestamp = strtotime( $published . ' UTC' );
		if ( false === $timestamp ) {
			return null;
		}

		return array(
			'iso'   => gmdate( 'c', $timestamp ),
			'label' => $this->date_label( $timestamp ),
		);
	}

	/**
	 * The visible timestamp for a publication time.
	 *
	 * @param int $timestamp Unix time.
	 * @return string
	 */
	private function date_label( int $timestamp ): string {
		if ( 'relative' !== $this->settings->get_string( 'date_style' ) ) {
			return (string) wp_date( 'M j', $timestamp );
		}

		$age = time() - $timestamp;

		// Under a day, a relative stamp reads as "this is current" at a glance,
		// which a bare date does not. Older than that, the date is the more
		// useful fact. Mirrors how Google News and MSN present it.
		if ( $age < 0 || $age >= DAY_IN_SECONDS ) {
			return (string) wp_date( 'M j', $timestamp );
		}

		return $age < HOUR_IN_SECONDS
			/* translators: %d: whole minutes. */
			? sprintf( _x( '%dm', 'minutes ago', 'trending-now' ), max( 1, (int) floor( $age / MINUTE_IN_SECONDS ) ) )
			/* translators: %d: whole hours. */
			: sprintf( _x( '%dh', 'hours ago', 'trending-now' ), (int) floor( $age / HOUR_IN_SECONDS ) );
	}

	/**
	 * Recompute relative timestamps against the current time.
	 *
	 * The cached blob holds whatever the label was when it was built, and the
	 * cache is only busted once per ingest cycle — so without this an article
	 * rendered at "42m" still claims "42m" the following day. The `datetime`
	 * attribute is already in the markup, so the true age is recoverable
	 * without touching the database.
	 *
	 * @param string $html Rendered HTML.
	 * @return string
	 */
	private function refresh_dates( string $html ): string {
		if ( 'relative' !== $this->settings->get_string( 'date_style' ) || false === strpos( $html, '__date"' ) ) {
			return $html;
		}

		$prefix = preg_quote( $this->settings->get_string( 'class_prefix' ), '/' );

		return (string) preg_replace_callback(
			'/(<time class="' . $prefix . '__date" datetime="([^"]+)"[^>]*>)([^<]*)(<\/time>)/',
			function ( array $m ): string {
				$timestamp = strtotime( $m[2] );

				return false === $timestamp
					? $m[0]
					: $m[1] . esc_html( $this->date_label( $timestamp ) ) . $m[4];
			},
			$html
		);
	}

	/**
	 * Favicon URL for an item's publisher.
	 *
	 * Derived from the stored host rather than fetched and cached during
	 * ingest, because the icon a news API hands back is itself just a favicon
	 * service URL built from the domain — deriving it costs nothing, needs no
	 * schema change, and covers network sources too.
	 *
	 * The request is made by the visitor's browser, not the server, so the
	 * plugin's no-HTTP-during-render rule still holds. It is a third party
	 * seeing your visitors' IPs, though, which is why it is off by default;
	 * filter `advtn_source_icon_url` to self-host or swap providers.
	 *
	 * @param array<string,mixed> $item Item row.
	 * @return string URL, or '' when unavailable.
	 */
	public function source_icon( array $item ): string {
		$host = (string) ( $item['host'] ?? '' );

		if ( '' === $host ) {
			return '';
		}

		$url = 'https://www.google.com/s2/favicons?sz=64&domain=' . rawurlencode( $host );

		/**
		 * Filters the favicon URL used beside a source name.
		 *
		 * Return '' to omit the icon for this item.
		 *
		 * @param string              $url  Icon URL.
		 * @param string              $host Item host.
		 * @param array<string,mixed> $item Item row.
		 */
		return (string) apply_filters( 'advtn_source_icon_url', $url, $host, $item );
	}

	/**
	 * URL of the "see all" archive, or '' when the archive is disabled.
	 *
	 * @return string
	 */
	public function archive_url(): string {
		if ( ! $this->settings->get_bool( 'archive_enabled' ) ) {
			return '';
		}

		return home_url( '/' . $this->settings->get_string( 'archive_slug' ) . '/' );
	}

	/* ---------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------ */

	/**
	 * Produce the cacheable blob: template output with a CSS placeholder.
	 *
	 * @param array<string,mixed> $args Normalized arguments.
	 * @return string
	 */
	private function build( array $args ): string {
		$rows = advtn()->selector()->current_rows();

		if ( empty( $rows ) ) {
			// No committed selection yet — fall back to a direct query rather
			// than rendering nothing on a fresh install.
			$rows = $this->repository->recent_active( $args['limit'], $this->settings->max_age_cutoff() );
		}

		if ( empty( $rows ) ) {
			return '';
		}

		$items = array_slice( $rows, 0, $args['limit'] );

		$template = $this->locate_template( 'widget-' . $args['layout'] . '.php' );
		if ( '' === $template ) {
			return '';
		}

		$prefix      = $this->settings->get_string( 'class_prefix' );
		$heading_id  = $prefix . '-heading-' . substr( md5( (string) wp_json_encode( $args ) ), 0, 4 );
		$archive_url = $args['show_see_all'] ? $this->archive_url() : '';
		$renderer    = $this;
		$settings    = $this->settings;

		ob_start();
		include $template;
		$html = (string) ob_get_clean();

		$html = trim( $html );

		return '' === $html ? '' : self::CSS_MARKER . $html;
	}

	/**
	 * Swap the CSS placeholder for the stylesheet, once per request.
	 *
	 * @param string $html Cached or freshly built blob.
	 * @return string
	 */
	private function emit( string $html ): string {
		if ( '' === $html ) {
			return '';
		}

		$html = $this->refresh_dates( $html );

		if ( self::$css_emitted ) {
			return str_replace( self::CSS_MARKER, '', $html );
		}

		self::$css_emitted = true;

		return str_replace( self::CSS_MARKER, $this->css(), $html );
	}

	/**
	 * Add a cache key to the registry, refusing past the variant cap.
	 *
	 * @param string $key Option key.
	 * @return bool False when the cap is reached.
	 */
	private function register_variant( string $key ): bool {
		$keys = get_option( self::CACHE_REGISTRY, array() );
		$keys = is_array( $keys ) ? $keys : array();

		if ( in_array( $key, $keys, true ) ) {
			return true;
		}

		if ( count( $keys ) >= self::MAX_VARIANTS ) {
			return false;
		}

		$keys[] = $key;
		update_option( self::CACHE_REGISTRY, $keys, false );

		return true;
	}

	/**
	 * Resolve a template, preferring a theme override.
	 *
	 * @param string $file Template filename.
	 * @return string Absolute path, or '' when missing.
	 */
	public function locate_template( string $file ): string {
		$override = locate_template( array( 'trending-now/' . $file ) );

		if ( $override ) {
			return $override;
		}

		$path = ADVTN_PATH . 'templates/' . $file;

		return is_readable( $path ) ? $path : '';
	}

	/**
	 * The inline stylesheet, but only the first time it is asked for.
	 *
	 * The widget normally emits it through emit(); the archive renders its own
	 * markup and so has to ask, and must not duplicate it when a widget is also
	 * on the page.
	 *
	 * @return string
	 */
	public function inline_css_once(): string {
		if ( self::$css_emitted ) {
			return '';
		}

		self::$css_emitted = true;

		return $this->css();
	}

	/**
	 * Inline stylesheet, generated from the configured class prefix.
	 *
	 * Inlined rather than enqueued because the prefix is per-site and because
	 * the homepage should not pay for an extra HTTP request.
	 *
	 * @return string
	 */
	public function css(): string {
		$p = $this->settings->get_string( 'class_prefix' );

		// Selectors are deliberately compounded (.p.p--news) and the
		// structural properties marked important. This markup lands inside
		// arbitrary themes, and a theme rule like `.entry-content ul li`
		// (0,2,1) outranks a plain `.p--news .p__item` (0,2,0) — which shows
		// up as bullets reappearing and the row losing `display:flex`, so the
		// thumbnail drops below the headline instead of sitting beside it.
		$css = ".{$p}{margin:2rem 0}"
			. ".{$p} .{$p}__heading{margin:0 0 .75rem}"
			. ".{$p} .{$p}__items{list-style:none!important;margin:0!important;padding:0!important;display:grid;gap:.5rem}"
			. ".{$p} .{$p}__items>.{$p}__item{list-style:none!important;margin:0;text-indent:0}"
			. ".{$p} .{$p}__items>.{$p}__item::marker{content:none}"
			. ".{$p}.{$p}--cards .{$p}__items{grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:1rem}"
			. ".{$p} .{$p}__item{display:flex;flex-wrap:wrap;align-items:baseline;gap:.4rem .6rem;line-height:1.4}"
			. ".{$p}.{$p}--cards .{$p}__item{display:block}"
			. ".{$p} .{$p}__link{font-weight:600;text-decoration:none}"
			. ".{$p} .{$p}__link:hover,.{$p} .{$p}__link:focus{text-decoration:underline}"
			. ".{$p} .{$p}__source,.{$p} .{$p}__date{font-size:.8em;opacity:.7}"
			. ".{$p} .{$p}__excerpt{flex-basis:100%;margin:.15rem 0 0;font-size:.9em;opacity:.85}"
			. ".{$p} .{$p}__thumb{display:block;width:100%;height:auto;margin-bottom:.5rem;border-radius:4px}"
			. ".{$p} .{$p}__more{margin:1rem 0 0}"
			. "@media(max-width:600px){.{$p} .{$p}__item{gap:.25rem .5rem}}"
			// News layout: card rows with the thumbnail on the right, after the
			// Google News / MSN feed. Colours inherit from the theme, with
			// currentColor tints so it sits correctly on light and dark.
			. ".{$p}.{$p}--news .{$p}__items{gap:0;display:block}"
			. ".{$p}.{$p}--news .{$p}__items>.{$p}__item{display:flex!important;flex-wrap:nowrap!important;align-items:flex-start;justify-content:space-between;gap:1rem;padding:.9rem 0;border-bottom:1px solid rgba(128,128,128,.25)}"
			. ".{$p}.{$p}--news .{$p}__items>.{$p}__item:last-child{border-bottom:0}"
			// flex:1 means basis 0, so a long headline cannot claim the whole
			// line and shove the thumbnail onto the next one.
			. ".{$p}.{$p}--news .{$p}__body{flex:1;min-width:0}"
			. ".{$p}.{$p}--news .{$p}__meta{display:flex;align-items:center;gap:.4rem;margin:0 0 .25rem;font-size:.78em;opacity:.7;line-height:1.2}"
			. ".{$p}.{$p}--news .{$p}__source{font-weight:600}"
			. ".{$p}.{$p}--news .{$p}__source+.{$p}__date::before{content:'\u{b7}';margin-right:.4rem;opacity:.7}"
			. ".{$p}.{$p}--news .{$p}__link{display:block;font-size:1.02em;font-weight:600;line-height:1.35}"
			. ".{$p}.{$p}--news .{$p}__media{flex:0 0 auto;width:120px;margin:0}"
			. ".{$p}.{$p}--news .{$p}__thumb{display:block;width:120px;height:auto;aspect-ratio:16/9;object-fit:cover;border-radius:8px;margin:0}"
			. "@media(max-width:480px){.{$p}.{$p}--news .{$p}__media,.{$p}.{$p}--news .{$p}__thumb{width:88px}}"
			. ".{$p} .{$p}__icon{display:inline-block;width:16px;height:16px;border-radius:3px;vertical-align:-3px;flex:0 0 auto;margin:0}"
			. ".{$p}-archive{max-width:820px;margin:0 auto;padding:0 1rem}"
			. ".{$p}-archive__header{margin:0 0 1.5rem}"
			. ".{$p}-archive__intro{opacity:.85}"
			. ".{$p}-archive__meta{font-size:.85em;opacity:.7}"
			. ".{$p}-archive__nav{display:flex;justify-content:center;gap:.4rem;margin:2rem 0;flex-wrap:wrap}"
			. ".{$p}-archive__nav .page-numbers{padding:.35rem .7rem;border:1px solid rgba(128,128,128,.35);border-radius:6px;text-decoration:none}"
			. ".{$p}-archive__nav .page-numbers.current{font-weight:700;border-color:currentColor}";

		/**
		 * Filters the inline widget stylesheet.
		 *
		 * @param string $css    Generated CSS.
		 * @param string $prefix Class prefix.
		 */
		$css = trim( (string) apply_filters( 'advtn_inline_css', $css, $p ) );

		// Filtering this to '' suppresses the tag entirely, for anyone who
		// would rather enqueue assets/css/trending-now.css themselves.
		return '' === $css ? '' : '<style id="' . esc_attr( $p ) . '-inline-css">' . $css . '</style>';
	}

	/**
	 * Coerce shortcode-style truthy values.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	private function to_bool( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		return in_array( strtolower( (string) $value ), array( '1', 'true', 'yes', 'on' ), true );
	}
}
