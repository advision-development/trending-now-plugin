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
	 * the query string and fragment removed, percent-encoding decoded, repeated
	 * slashes collapsed and surrounding whitespace stripped. Whitespace is
	 * trimmed *after* decoding, so a `%20` at either end cannot survive into the
	 * result — a trailing one used to defeat the rtrim() and leave the trailing
	 * slash the return contract promises to remove.
	 *
	 * Input carrying an explicit `scheme://` keeps only its path component, so
	 * a pasted `https://example.com/archive` normalizes to `/archive` rather
	 * than to the nonsense `/https:/example.com/archive` that collapsing the
	 * `//` produces — that being the likeliest way to write a list entry that
	 * can never match anything. A URL with no path at all yields `/`. The
	 * scheme is parsed by hand rather than with wp_parse_url() so this method
	 * stays reachable from the dependency-free harness.
	 *
	 * Protocol-relative input is deliberately *not* treated as a URL:
	 * `//example.com/archive` still collapses to `/example.com/archive`.
	 * Telling a host from a doubled slash means guessing, and in a list of
	 * paths a doubled slash is much the likelier typo.
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
		$path = trim( rawurldecode( $path ) );

		if ( '' === $path ) {
			return '';
		}

		$scheme = array();

		if ( preg_match( '#^[a-z][a-z0-9+.\-]*://#i', $path, $scheme ) ) {
			$authority = substr( $path, strlen( $scheme[0] ) );
			$boundary  = strpos( $authority, '/' );
			$path      = false === $boundary ? '/' : substr( $authority, $boundary );
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
	 * $current is normalized here rather than trusted, so a caller that hands
	 * over a raw path gets the comparison it expects instead of a silent miss.
	 * Re-normalizing the already-normalized value matches() passes changes
	 * nothing — with one exception, and it does not fail in the safe direction.
	 *
	 * normalize() is not idempotent for a doubly-encoded sequence: current_path()
	 * decodes `/tr%2565nding` once to `/tr%65nding`, and this second normalize
	 * decodes it again to `/trending`, which then matches a `/trending` needle it
	 * should not. That is an unwanted MATCH, not an unwanted miss. It is
	 * unreachable as real content — sanitize_title() strips `%`, so no post, page
	 * or term slug can hold a percent sequence, and any URL that survives two
	 * decodes into a needle is therefore a 404 — so the effect is a widget
	 * appearing on a 404 page, showing nothing it does not already publish where
	 * it was meant to. Percent-encoded non-ASCII slugs are genuinely idempotent:
	 * UTF-8 continuation bytes are all >= 0x80 and can never decode to 0x25.
	 *
	 * Do not build a prefix or wildcard rule on the assumption that this cannot
	 * over-match. Under the trailing `*` the spec reserves for section matching,
	 * the same double decode stops being a 404 curiosity and becomes a
	 * section-wide leak. Decode once, or compare before decoding.
	 *
	 * The fail-closed case survives regardless: normalize( '' ) is '' and '' is
	 * never a needle.
	 *
	 * @param string $current Current path; normalized here, so either form works.
	 * @param string $list    Raw comma-separated list.
	 * @return bool
	 */
	public static function matches_path( string $current, string $list ): bool {
		$current = self::normalize( $current );
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
