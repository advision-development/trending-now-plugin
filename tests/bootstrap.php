<?php
/**
 * Minimal shims so the pure-logic classes can be exercised without a full
 * WordPress install. Only the handful of core functions those classes touch
 * are stubbed.
 *
 * @package Advision\TrendingNow
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
define( 'ADVTN_TEST_HOME', 'https://mysite.example/' );

if ( ! function_exists( 'wp_parse_url' ) ) {
	/**
	 * Stub of wp_parse_url().
	 *
	 * @param string $url       URL.
	 * @param int    $component Component constant.
	 * @return mixed
	 */
	function wp_parse_url( string $url, int $component = -1 ) {
		return parse_url( $url, $component );
	}
}

if ( ! function_exists( 'wp_http_validate_url' ) ) {
	/**
	 * Stub of wp_http_validate_url(): structural check plus a private-range
	 * rejection, matching core's intent closely enough for these tests.
	 *
	 * @param string $url URL.
	 * @return string|false
	 */
	function wp_http_validate_url( string $url ) {
		if ( false === filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return false;
		}

		$host = parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			return false;
		}

		if ( in_array( $host, array( 'localhost', '127.0.0.1' ), true ) ) {
			return false;
		}

		if ( filter_var( $host, FILTER_VALIDATE_IP ) && ! filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return false;
		}

		return $url;
	}
}

if ( ! function_exists( 'home_url' ) ) {
	/**
	 * Stub of home_url().
	 *
	 * @param string $path Path to append.
	 * @return string
	 */
	function home_url( string $path = '' ): string {
		return rtrim( ADVTN_TEST_HOME, '/' ) . '/' . ltrim( $path, '/' );
	}
}

require_once dirname( __DIR__ ) . '/includes/class-advtn-url.php';
require_once dirname( __DIR__ ) . '/includes/class-advtn-hmac.php';
