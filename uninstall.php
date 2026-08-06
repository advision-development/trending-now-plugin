<?php
/**
 * Uninstall handler.
 *
 * Only destroys data when `delete_data_on_uninstall` is true.
 *
 * @package Advision\TrendingNow
 */

declare( strict_types=1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$advtn_settings = get_option( 'advtn_settings', array() );

if ( empty( $advtn_settings['delete_data_on_uninstall'] ) ) {
	return;
}

global $wpdb;

// Custom table.
$advtn_table = $wpdb->prefix . 'advtn_items';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name cannot be a placeholder; value is composed from $wpdb->prefix.
$wpdb->query( "DROP TABLE IF EXISTS `{$advtn_table}`" );

// Every advtn_* option, including the dynamic render-cache keys.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$advtn_option_names = $wpdb->get_col(
	$wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'advtn_' ) . '%' )
);

foreach ( (array) $advtn_option_names as $advtn_option_name ) {
	delete_option( $advtn_option_name );
}

// Transients used for rate limiting, replay guards and archive counts.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$advtn_transients = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_advtn_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_advtn_' ) . '%'
	)
);

foreach ( (array) $advtn_transients as $advtn_transient ) {
	delete_option( $advtn_transient );
}

wp_cache_flush();
