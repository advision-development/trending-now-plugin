<?php
/**
 * Activation / deactivation lifecycle.
 *
 * @package Advision\TrendingNow
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ADVTN_Activator {

	/**
	 * Create the table, seed defaults, generate secrets, flush rewrites.
	 *
	 * @return void
	 */
	public static function activate(): void {
		ADVTN_Schema::install();

		$settings = get_option( 'advtn_settings' );
		$settings = is_array( $settings ) ? $settings : array();
		$settings = array_merge( ADVTN_Settings::defaults(), $settings );

		if ( empty( $settings['ingest_secret'] ) ) {
			$settings['ingest_secret'] = self::generate_secret();
		}

		update_option( 'advtn_settings', $settings, true );

		if ( false === get_option( 'advtn_sources' ) ) {
			add_option( 'advtn_sources', array(), '', false );
		}
		if ( false === get_option( 'advtn_source_state' ) ) {
			add_option( 'advtn_source_state', array(), '', false );
		}
		if ( false === get_option( 'advtn_current_selection' ) ) {
			add_option( 'advtn_current_selection', array(), '', false );
		}
		if ( false === get_option( 'advtn_render_cache_keys' ) ) {
			add_option( 'advtn_render_cache_keys', array(), '', false );
		}
		if ( false === get_option( 'advtn_log' ) ) {
			add_option( 'advtn_log', array(), '', false );
		}

		// Rewrite rules are registered on init; register them now so the flush
		// below has something to write.
		advtn()->archive()->add_rewrite_rules();
		flush_rewrite_rules( false );

		ADVTN_Logger::log( 'info', 'Plugin activated.', array( 'version' => ADVTN_VERSION ) );
	}

	/**
	 * Unschedule everything and clean rewrites. Leaves data alone.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		advtn()->scheduler()->unschedule_all();
		ADVTN_Lock::release();
		flush_rewrite_rules( false );

		ADVTN_Logger::log( 'info', 'Plugin deactivated.' );
	}

	/**
	 * 32 random bytes, hex encoded.
	 *
	 * @return string
	 */
	public static function generate_secret(): string {
		try {
			return bin2hex( random_bytes( 32 ) );
		} catch ( \Throwable $e ) {
			return wp_generate_password( 64, false, false );
		}
	}
}
