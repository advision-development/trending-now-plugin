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

// Core time constants the pure-logic classes do arithmetic with.
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

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

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * Stub of sanitize_text_field().
	 *
	 * @param string $str Input.
	 * @return string
	 */
	function sanitize_text_field( string $str ): string {
		$str = wp_strip_all_tags( $str, true );
		return trim( (string) preg_replace( '/[\r\n\t]+/', ' ', $str ) );
	}
}

if ( ! function_exists( 'sanitize_title' ) ) {
	/**
	 * Stub of sanitize_title().
	 *
	 * @param string $title Input.
	 * @return string
	 */
	function sanitize_title( string $title ): string {
		$title = strtolower( wp_strip_all_tags( $title, true ) );
		$title = (string) preg_replace( '/[^a-z0-9_-]+/', '-', $title );
		return trim( $title, '-' );
	}
}

if ( ! function_exists( 'wp_kses_post' ) ) {
	/**
	 * Stub of wp_kses_post(): permissive, enough for these tests.
	 *
	 * @param string $data Input.
	 * @return string
	 */
	function wp_kses_post( string $data ): string {
		return $data;
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	/**
	 * Stub of esc_url_raw(): drops anything that is not http(s).
	 *
	 * @param string $url URL.
	 * @return string
	 */
	function esc_url_raw( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}

		$scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );

		return in_array( $scheme, array( 'http', 'https' ), true ) ? $url : '';
	}
}

if ( ! function_exists( 'untrailingslashit' ) ) {
	/**
	 * Stub of untrailingslashit().
	 *
	 * @param string $value Input.
	 * @return string
	 */
	function untrailingslashit( string $value ): string {
		return rtrim( $value, '/\\' );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * Stub of wp_json_encode().
	 *
	 * @param mixed $data  Data.
	 * @param int   $flags json_encode flags.
	 * @return string|false
	 */
	function wp_json_encode( $data, int $flags = 0 ) {
		return json_encode( $data, $flags ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}
}

/**
 * Options, backed by an array a test can write to.
 *
 * Only enough to let ADVTN_Settings resolve, which is all the pure-logic
 * classes need. Anything that actually wants the database belongs in a WP
 * integration suite.
 *
 * @var array<string,mixed>
 */
$GLOBALS['advtn_test_options'] = array();

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Read a stubbed option.
	 *
	 * @param string $option  Option name.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	function get_option( string $option, $default = false ) {
		return array_key_exists( $option, $GLOBALS['advtn_test_options'] )
			? $GLOBALS['advtn_test_options'][ $option ]
			: $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * Stub of update_option().
	 *
	 * @param string $name     Option name.
	 * @param mixed  $value    Value.
	 * @param mixed  $autoload Ignored.
	 * @return bool
	 */
	function update_option( string $name, $value, $autoload = null ): bool {
		$GLOBALS['advtn_test_options'][ $name ] = $value;
		return true;
	}
}

require_once dirname( __DIR__ ) . '/includes/class-advtn-url.php';
require_once dirname( __DIR__ ) . '/includes/class-advtn-path-match.php';
require_once dirname( __DIR__ ) . '/includes/class-advtn-attempts.php';
require_once dirname( __DIR__ ) . '/includes/class-advtn-manual-feed-parser.php';
require_once dirname( __DIR__ ) . '/includes/class-advtn-manual-feed.php';
require_once dirname( __DIR__ ) . '/includes/class-advtn-hmac.php';
require_once dirname( __DIR__ ) . '/includes/class-advtn-sync-key.php';
require_once dirname( __DIR__ ) . '/includes/sources/interface-advtn-source.php';
require_once dirname( __DIR__ ) . '/includes/sources/class-advtn-source-base.php';
require_once dirname( __DIR__ ) . '/includes/sources/class-advtn-source-serpapi.php';
require_once dirname( __DIR__ ) . '/includes/class-advtn-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-advtn-updater.php';
require_once dirname( __DIR__ ) . '/includes/class-advtn-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-advtn-archive.php';
