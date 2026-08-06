<?php
/**
 * The "see all" archive: rewrite rule, template, pagination, robots.
 *
 * @package Advision\TrendingNow
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ADVTN_Archive {

	public const QUERY_FLAG = 'advtn_archive';
	public const QUERY_PAGE = 'advtn_page';
	public const COUNT_TTL  = 900;

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
	 * Bind rewrite, query var and template hooks.
	 *
	 * Rules register at priority 1 so the deferred flush at priority 5 has
	 * something to write. flush_rewrite_rules() itself is never called on a
	 * plain init.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'init', array( $this, 'add_rewrite_rules' ), 1 );
		add_filter( 'query_vars', array( $this, 'add_query_vars' ) );
		add_action( 'template_redirect', array( $this, 'prepare_response' ) );
		add_filter( 'template_include', array( $this, 'template_include' ) );
		add_filter( 'document_title_parts', array( $this, 'document_title' ) );
		add_filter( 'wp_robots', array( $this, 'robots' ) );
		add_action( 'wp_head', array( $this, 'head_tags' ), 1 );
		add_filter( 'wp_sitemaps_add_provider', array( $this, 'sitemap_provider' ), 10, 2 );
		add_action( 'init', array( $this, 'register_sitemap_provider' ), 20 );
	}

	/**
	 * Register the archive rewrite rules.
	 *
	 * @return void
	 */
	public function add_rewrite_rules(): void {
		if ( ! $this->settings->get_bool( 'archive_enabled' ) ) {
			return;
		}

		$slug = $this->settings->get_string( 'archive_slug' );
		if ( '' === $slug ) {
			return;
		}

		add_rewrite_rule( '^' . $slug . '/?$', 'index.php?' . self::QUERY_FLAG . '=1&' . self::QUERY_PAGE . '=1', 'top' );
		add_rewrite_rule( '^' . $slug . '/page/([0-9]+)/?$', 'index.php?' . self::QUERY_FLAG . '=1&' . self::QUERY_PAGE . '=$matches[1]', 'top' );
	}

	/**
	 * Whitelist the archive query vars.
	 *
	 * @param string[] $vars Registered query vars.
	 * @return string[]
	 */
	public function add_query_vars( array $vars ): array {
		$vars[] = self::QUERY_FLAG;
		$vars[] = self::QUERY_PAGE;

		return $vars;
	}

	/**
	 * Whether the current request is the archive.
	 *
	 * @return bool
	 */
	public function is_archive_request(): bool {
		return $this->settings->get_bool( 'archive_enabled' ) && (bool) get_query_var( self::QUERY_FLAG );
	}

	/**
	 * Current 1-based page number.
	 *
	 * @return int
	 */
	public function current_page(): int {
		return max( 1, (int) get_query_var( self::QUERY_PAGE, 1 ) );
	}

	/**
	 * Total pages available.
	 *
	 * @return int
	 */
	public function total_pages(): int {
		$per_page = $this->settings->get_int( 'archive_per_page', 5, 200 );

		return max( 1, (int) ceil( $this->total_items() / $per_page ) );
	}

	/**
	 * Total archive items, cached briefly to avoid a COUNT per pageview.
	 *
	 * @return int
	 */
	public function total_items(): int {
		$cached = get_transient( 'advtn_archive_count' );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		$count = $this->repository->archive_count();
		set_transient( 'advtn_archive_count', $count, self::COUNT_TTL );

		return $count;
	}

	/**
	 * Items for the current page.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function current_items(): array {
		$per_page = $this->settings->get_int( 'archive_per_page', 5, 200 );
		$offset   = ( $this->current_page() - 1 ) * $per_page;

		return $this->repository->archive_page( $per_page, $offset );
	}

	/**
	 * Permalink for a given archive page.
	 *
	 * @param int $page Page number.
	 * @return string
	 */
	public function page_url( int $page ): string {
		$base = home_url( '/' . $this->settings->get_string( 'archive_slug' ) . '/' );

		return $page > 1 ? $base . 'page/' . $page . '/' : $base;
	}

	/**
	 * Force a 200 and redirect out-of-range pages.
	 *
	 * @return void
	 */
	public function prepare_response(): void {
		if ( ! $this->is_archive_request() ) {
			return;
		}

		global $wp_query;

		$wp_query->is_404 = false;
		status_header( 200 );

		$page = $this->current_page();
		if ( $page > 1 && $page > $this->total_pages() ) {
			wp_safe_redirect( $this->page_url( 1 ), 302 );
			exit;
		}
	}

	/**
	 * Swap in the archive template.
	 *
	 * @param string $template Resolved template path.
	 * @return string
	 */
	public function template_include( $template ): string {
		if ( ! $this->is_archive_request() ) {
			return (string) $template;
		}

		$custom = advtn()->renderer()->locate_template( 'archive.php' );

		return '' !== $custom ? $custom : (string) $template;
	}

	/**
	 * Archive document title.
	 *
	 * @param array<string,string> $parts Title parts.
	 * @return array<string,string>
	 */
	public function document_title( $parts ): array {
		if ( ! $this->is_archive_request() ) {
			return (array) $parts;
		}

		$parts = (array) $parts;
		$page  = $this->current_page();

		$parts['title'] = $this->settings->get_string( 'heading_text' );

		if ( $page > 1 ) {
			/* translators: %d: page number. */
			$parts['title'] .= sprintf( __( ' — Page %d', 'trending-now' ), $page );
		}

		unset( $parts['tagline'] );

		return $parts;
	}

	/**
	 * Apply the configured robots directive.
	 *
	 * @param array<string,mixed> $robots Robots directives.
	 * @return array<string,mixed>
	 */
	public function robots( $robots ): array {
		$robots = (array) $robots;

		if ( ! $this->is_archive_request() ) {
			return $robots;
		}

		if ( $this->settings->get_bool( 'archive_noindex' ) ) {
			$robots['noindex'] = true;
			$robots['follow']  = true;
			unset( $robots['index'] );
		}

		return $robots;
	}

	/**
	 * Canonical, prev/next and Open Graph tags.
	 *
	 * @return void
	 */
	public function head_tags(): void {
		if ( ! $this->is_archive_request() ) {
			return;
		}

		$page  = $this->current_page();
		$total = $this->total_pages();
		$url   = $this->page_url( $page );

		echo '<link rel="canonical" href="' . esc_url( $url ) . '" />' . "\n";

		if ( $page > 1 ) {
			echo '<link rel="prev" href="' . esc_url( $this->page_url( $page - 1 ) ) . '" />' . "\n";
		}
		if ( $page < $total ) {
			echo '<link rel="next" href="' . esc_url( $this->page_url( $page + 1 ) ) . '" />' . "\n";
		}

		echo '<meta property="og:type" content="website" />' . "\n";
		echo '<meta property="og:title" content="' . esc_attr( $this->settings->get_string( 'heading_text' ) ) . '" />' . "\n";
		echo '<meta property="og:url" content="' . esc_url( $url ) . '" />' . "\n";
	}

	/**
	 * Add the archive to the core sitemap when it is indexable.
	 *
	 * Nothing to exclude when noindex is set: the archive is not a post
	 * object, so core never lists it on its own.
	 *
	 * @return void
	 */
	public function register_sitemap_provider(): void {
		if ( ! function_exists( 'wp_register_sitemap_provider' ) || ! class_exists( 'WP_Sitemaps_Provider' ) ) {
			return;
		}

		if ( ! $this->settings->get_bool( 'archive_enabled' ) || $this->settings->get_bool( 'archive_noindex' ) ) {
			return;
		}

		wp_register_sitemap_provider( 'advtn-archive', new ADVTN_Sitemap_Provider( $this ) );
	}

	/**
	 * Belt and braces: drop our provider if noindex gets flipped on.
	 *
	 * @param WP_Sitemaps_Provider|false $provider Provider instance.
	 * @param string                     $name     Provider name.
	 * @return WP_Sitemaps_Provider|false
	 */
	public function sitemap_provider( $provider, $name ) {
		if ( 'advtn-archive' === $name && $this->settings->get_bool( 'archive_noindex' ) ) {
			return false;
		}

		return $provider;
	}
}
