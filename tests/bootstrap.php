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

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Stub of apply_filters(): no filters registered in this harness.
	 *
	 * @param string $tag   Filter name.
	 * @param mixed  $value Value to filter.
	 * @return mixed
	 */
	function apply_filters( string $tag, $value ) {
		return $value;
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	/**
	 * Stub of wp_strip_all_tags().
	 *
	 * @param string $text     Input.
	 * @param bool   $remove_breaks Collapse whitespace.
	 * @return string
	 */
	function wp_strip_all_tags( string $text, bool $remove_breaks = false ): string {
		$text = strip_tags( $text );
		return $remove_breaks ? trim( (string) preg_replace( '/[\r\n\t ]+/', ' ', $text ) ) : trim( $text );
	}
}

if ( ! function_exists( '__' ) ) {
	/**
	 * Stub of __().
	 *
	 * @param string $text   Text.
	 * @param string $domain Text domain.
	 * @return string
	 */
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

require_once dirname( __DIR__ ) . '/includes/class-advtn-url.php';
require_once dirname( __DIR__ ) . '/includes/class-advtn-hmac.php';
require_once dirname( __DIR__ ) . '/includes/sources/interface-advtn-source.php';
require_once dirname( __DIR__ ) . '/includes/sources/class-advtn-source-base.php';
require_once dirname( __DIR__ ) . '/includes/sources/class-advtn-source-serpapi.php';
