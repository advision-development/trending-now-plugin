<?php
/**
 * Plugin Name: Trending Now dev host allowlist
 * Description: Local test environment only. Never ship this.
 *
 * wp_http_validate_url() rejects any host that resolves into a private or
 * loopback range. wp_safe_remote_get() enforces that, and fetch_feed() uses
 * wp_safe_remote_get() internally — so the RSS source cannot reach the
 * containerised stand-in site without this, no matter what the plugin's own
 * ADVTN_ALLOW_LOCAL_URLS says.
 *
 * @package Advision\TrendingNow
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter(
	'http_request_host_is_external',
	static function ( $external, $host ) {
		$allowed = array( 'source', 'wordpress', 'localhost', '127.0.0.1' );

		return in_array( (string) $host, $allowed, true ) ? true : $external;
	},
	10,
	2
);
