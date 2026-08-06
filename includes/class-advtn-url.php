<?php
/**
 * URL normalization, hashing and validation.
 *
 * normalize() defines dedupe identity, so it must be stable and
 * order-independent: the same article reached by two different tracking URLs
 * has to collapse to one hash.
 *
 * @package Advision\TrendingNow
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ADVTN_URL {

	/**
	 * Exact query parameter names dropped during normalization.
	 *
	 * @var string[]
	 */
	private const DROP_EXACT = array(
		'fbclid',
		'gclid',
		'gbraid',
		'wbraid',
		'msclkid',
		'mc_cid',
		'mc_eid',
		'ref',
		'source',
		'_ga',
		'igshid',
		'yclid',
	);

	/**
	 * Query parameter prefixes dropped during normalization.
	 *
	 * @var string[]
	 */
	private const DROP_PREFIX = array( 'utm_', 'oly_' );

	/**
	 * Canonical form of a URL for dedupe purposes.
	 *
	 * Scheme is forced to https for hashing only; the original URL is what
	 * gets stored and rendered.
	 *
	 * @param string $url Raw URL.
	 * @return string Normalized URL, or '' when unparseable.
	 */
	public static function normalize( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}

		$scheme = strtolower( $parts['scheme'] ?? 'https' );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return '';
		}

		$host = self::strip_www( strtolower( (string) $parts['host'] ) );
		if ( '' === $host ) {
			return '';
		}

		$path = (string) ( $parts['path'] ?? '/' );
		if ( '' === $path ) {
			$path = '/';
		}
		if ( '/' !== $path ) {
			$path = rtrim( $path, '/' );
			if ( '' === $path ) {
				$path = '/';
			}
		}

		$query = self::clean_query( (string) ( $parts['query'] ?? '' ) );

		// Fragment is dropped unconditionally.
		return 'https://' . $host . $path . ( '' !== $query ? '?' . $query : '' );
	}

	/**
	 * sha1 of the normalized URL. The table's uniqueness guarantee.
	 *
	 * @param string $url Raw URL.
	 * @return string 40-char hex, or '' when the URL is unusable.
	 */
	public static function hash( string $url ): string {
		$normalized = self::normalize( $url );
		return '' === $normalized ? '' : sha1( $normalized );
	}

	/**
	 * Lowercased, www-stripped host.
	 *
	 * @param string $url Raw URL.
	 * @return string Host, or '' on failure.
	 */
	public static function host( string $url ): string {
		$parts = wp_parse_url( trim( $url ) );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}
		return self::strip_www( strtolower( (string) $parts['host'] ) );
	}

	/**
	 * Whether a URL is safe to fetch or store.
	 *
	 * Rejects non-http(s) and, unless ADVTN_ALLOW_LOCAL_URLS is defined,
	 * anything resolving to a private or loopback address.
	 *
	 * @param string $url Raw URL.
	 * @return bool
	 */
	public static function is_valid( string $url ): bool {
		$url = trim( $url );
		if ( '' === $url ) {
			return false;
		}

		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return false;
		}

		$allow_local = defined( 'ADVTN_ALLOW_LOCAL_URLS' ) && ADVTN_ALLOW_LOCAL_URLS;

		if ( $allow_local ) {
			// wp_http_validate_url() rejects private ranges outright, so only
			// do the cheap structural check when local URLs are permitted.
			return false !== filter_var( $url, FILTER_VALIDATE_URL );
		}

		return false !== wp_http_validate_url( $url );
	}

	/**
	 * Host of the current site, normalized the same way item hosts are.
	 *
	 * @return string
	 */
	public static function local_host(): string {
		return self::host( home_url( '/' ) );
	}

	/**
	 * Strip a single leading "www." label.
	 *
	 * @param string $host Lowercased host.
	 * @return string
	 */
	private static function strip_www( string $host ): string {
		if ( 0 === strpos( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}
		return trim( $host, '.' );
	}

	/**
	 * Drop tracking params, sort the remainder, rebuild the query string.
	 *
	 * @param string $query Raw query string without the leading '?'.
	 * @return string
	 */
	private static function clean_query( string $query ): string {
		if ( '' === $query ) {
			return '';
		}

		$args = array();
		parse_str( $query, $args );

		foreach ( array_keys( $args ) as $key ) {
			$lower = strtolower( (string) $key );

			if ( in_array( $lower, self::DROP_EXACT, true ) ) {
				unset( $args[ $key ] );
				continue;
			}

			foreach ( self::DROP_PREFIX as $prefix ) {
				if ( 0 === strpos( $lower, $prefix ) ) {
					unset( $args[ $key ] );
					continue 2;
				}
			}
		}

		if ( empty( $args ) ) {
			return '';
		}

		ksort( $args );

		return http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
	}
}
