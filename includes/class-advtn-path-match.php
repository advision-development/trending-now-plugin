<?php
/**
 * Path matching for placements that must not render site-wide.
 *
 * A widget area is site-wide by construction, so a block meant for the
 * homepage appears on every page. This is how a placement says "here, not
 * there".
 *
 * Everything except current_path() is a pure function of its arguments, which
 * is deliberate: it is what lets the dependency-free harness cover the
 * subdirectory-install case without a settable home_url().
 *
 * @package Advision\TrendingNow
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ADVTN_Path_Match {

	/**
	 * Reduce a path to a comparable form.
	 *
	 * One leading slash, no trailing slash, `/` for the root, lowercased, with
	 * the query string and fragment removed and percent-encoding decoded.
	 *
	 * An empty input returns '' rather than '/'. That is load-bearing: if empty
	 * normalized to the root, an absent REQUEST_URI would silently satisfy a
	 * list containing '/', which is the fail-open behaviour current_path() is
	 * written to avoid.
	 *
	 * strtolower() maps only A-Z, so it is byte-safe on UTF-8: a non-ASCII slug
	 * is left intact and compares case-sensitively. Documented rather than
	 * chased — WordPress slugs are lowercase ASCII, and the alternative is
	 * depending on mb_strtolower(), which core does not polyfill.
	 *
	 * @param string $path Raw path, or anything path-shaped.
	 * @return string
	 */
	public static function normalize( string $path ): string {
		$path = explode( '#', $path, 2 )[0];
		$path = explode( '?', $path, 2 )[0];
		$path = rawurldecode( trim( $path ) );

		if ( '' === trim( $path ) ) {
			return '';
		}

		$path = (string) preg_replace( '#/+#', '/', $path );
		$path = rtrim( '/' . ltrim( $path, '/' ), '/' );

		return '' === $path ? '/' : strtolower( $path );
	}

	/**
	 * The request path with the site's own base removed.
	 *
	 * On an install at https://example.com/blog, a request for /blog/ yields /
	 * and /blog/archive yields /archive — so a list written as "/" means this
	 * site's homepage rather than the server root.
	 *
	 * The base is only stripped at a segment boundary, so a base of /blog does
	 * not eat the leading characters of /blogging.
	 *
	 * @param string $request_uri Raw REQUEST_URI.
	 * @param string $home_path   Path component of home_url().
	 * @return string
	 */
	public static function path_from( string $request_uri, string $home_path ): string {
		$path = self::normalize( $request_uri );
		$base = self::normalize( $home_path );

		if ( '' === $path || '' === $base || '/' === $base ) {
			return $path;
		}

		if ( 0 === strpos( $path, $base . '/' ) ) {
			return self::normalize( substr( $path, strlen( $base ) ) );
		}

		return $path === $base ? '/' : $path;
	}

	/**
	 * The normalized path of the current request.
	 *
	 * Returns '' when there is no request path at all — WP-CLI and cron. That
	 * fails a non-empty list, which is the safe direction: rendering where the
	 * path cannot be verified is worse than rendering nothing. It does not
	 * affect `wp trending-now render`, which calls the renderer directly and
	 * never passes through the gate.
	 *
	 * @return string
	 */
	public static function current_path(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared against a configured list, never output.
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';

		if ( '' === $uri ) {
			return '';
		}

		return self::path_from( $uri, (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) );
	}

	/**
	 * Whether a path satisfies a comma-separated list.
	 *
	 * An empty list gates nothing, so an absent attribute — or a typo that
	 * empties one — falls back to today's behaviour rather than blanking the
	 * placement everywhere.
	 *
	 * Entries are dropped after normalizing, not before, so a stray ",," cannot
	 * become an empty needle that an empty $current would match.
	 *
	 * @param string $current Normalized current path.
	 * @param string $list    Raw comma-separated list.
	 * @return bool
	 */
	public static function matches_path( string $current, string $list ): bool {
		$needles = array();

		foreach ( explode( ',', $list ) as $entry ) {
			$entry = self::normalize( $entry );

			if ( '' !== $entry ) {
				$needles[] = $entry;
			}
		}

		if ( empty( $needles ) ) {
			return true;
		}

		return in_array( $current, $needles, true );
	}

	/**
	 * Whether the current request satisfies a comma-separated list.
	 *
	 * @param string $list Raw comma-separated list.
	 * @return bool
	 */
	public static function matches( string $list ): bool {
		return self::matches_path( self::current_path(), $list );
	}
}
