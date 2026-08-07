<?php
/**
 * Tell the host's page cache when the widget's contents change.
 *
 * The plugin busts its own render cache at the end of every ingest cycle, but
 * a full-page cache holds the finished HTML and knows nothing about that. The
 * visible result is two pages disagreeing: a homepage cached after the cycle
 * shows new links while the archive, cached before it, does not — which reads
 * as the plugin selecting inconsistently when it is really two snapshots taken
 * at different times.
 *
 * @package Advision\TrendingNow
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ADVTN_Page_Cache {

	/**
	 * Purge whichever page cache is installed.
	 *
	 * Every integration is guarded, so an unknown or absent plugin is simply
	 * skipped. Failures are swallowed: a cache that will not clear must never
	 * take down an ingest cycle.
	 *
	 * @return string[] Names of the caches that were asked to purge.
	 */
	public static function purge(): array {
		if ( ! advtn()->settings()->get_bool( 'purge_page_cache' ) ) {
			return array();
		}

		$purged = array();

		/**
		 * Fires before the built-in integrations run.
		 *
		 * Hook this for a host or CDN the plugin does not know about.
		 */
		do_action( 'advtn_purge_page_cache' );

		$integrations = array(
			'WP Rocket'        => static fn() => function_exists( 'rocket_clean_domain' ) ? rocket_clean_domain() : null,
			'LiteSpeed Cache'  => static fn() => did_action( 'litespeed_loaded' ) ? do_action( 'litespeed_purge_all' ) : null,
			'W3 Total Cache'   => static fn() => function_exists( 'w3tc_flush_all' ) ? w3tc_flush_all() : null,
			'WP Super Cache'   => static fn() => function_exists( 'wp_cache_clear_cache' ) ? wp_cache_clear_cache() : null,
			'WP Fastest Cache' => static fn() => isset( $GLOBALS['wp_fastest_cache'] ) && method_exists( $GLOBALS['wp_fastest_cache'], 'deleteCache' )
				? $GLOBALS['wp_fastest_cache']->deleteCache( true )
				: null,
			'Cachify'          => static fn() => has_action( 'cachify_flush_cache' ) ? do_action( 'cachify_flush_cache' ) : null,
			'SG Optimizer'     => static fn() => function_exists( 'sg_cachepress_purge_cache' ) ? sg_cachepress_purge_cache() : null,
			'Nginx Helper'     => static fn() => has_action( 'rt_nginx_helper_purge_all' ) ? do_action( 'rt_nginx_helper_purge_all' ) : null,
			'Comet Cache'      => static fn() => class_exists( 'comet_cache' ) ? comet_cache::clear() : null,
			'Breeze'           => static fn() => has_action( 'breeze_clear_all_cache' ) ? do_action( 'breeze_clear_all_cache' ) : null,
			'Autoptimize'      => static fn() => class_exists( 'autoptimizeCache' ) ? autoptimizeCache::clearall() : null,
		);

		foreach ( $integrations as $name => $flush ) {
			try {
				if ( self::is_present( $name ) ) {
					$flush();
					$purged[] = $name;
				}
			} catch ( \Throwable $e ) {
				ADVTN_Logger::log( 'warning', 'Page cache purge failed.', array( 'cache' => $name, 'error' => $e->getMessage() ) );
			}
		}

		if ( ! empty( $purged ) ) {
			ADVTN_Logger::log( 'info', 'Page cache purged.', array( 'caches' => implode( ', ', $purged ) ) );
		}

		return $purged;
	}

	/**
	 * Which page caches this install appears to have.
	 *
	 * @return string[]
	 */
	public static function detected(): array {
		$found = array();

		foreach ( array( 'WP Rocket', 'LiteSpeed Cache', 'W3 Total Cache', 'WP Super Cache', 'WP Fastest Cache', 'Cachify', 'SG Optimizer', 'Nginx Helper', 'Comet Cache', 'Breeze', 'Autoptimize' ) as $name ) {
			if ( self::is_present( $name ) ) {
				$found[] = $name;
			}
		}

		return $found;
	}

	/**
	 * Whether a given page cache is active.
	 *
	 * @param string $name Integration name.
	 * @return bool
	 */
	private static function is_present( string $name ): bool {
		switch ( $name ) {
			case 'WP Rocket':
				return function_exists( 'rocket_clean_domain' );
			case 'LiteSpeed Cache':
				return did_action( 'litespeed_loaded' ) > 0;
			case 'W3 Total Cache':
				return function_exists( 'w3tc_flush_all' );
			case 'WP Super Cache':
				return function_exists( 'wp_cache_clear_cache' );
			case 'WP Fastest Cache':
				return isset( $GLOBALS['wp_fastest_cache'] ) && method_exists( $GLOBALS['wp_fastest_cache'], 'deleteCache' );
			case 'Cachify':
				return has_action( 'cachify_flush_cache' ) > 0;
			case 'SG Optimizer':
				return function_exists( 'sg_cachepress_purge_cache' );
			case 'Nginx Helper':
				return has_action( 'rt_nginx_helper_purge_all' ) > 0;
			case 'Comet Cache':
				return class_exists( 'comet_cache' );
			case 'Breeze':
				return has_action( 'breeze_clear_all_cache' ) > 0;
			case 'Autoptimize':
				return class_exists( 'autoptimizeCache' );
		}

		return false;
	}
}
