<?php
/**
 * Turns a feed response body into curated-link rows, or into a typed refusal.
 *
 * Pure and free of WordPress state so the dependency-free harness can exercise
 * it, which matters more here than anywhere else in the plugin: this class is
 * the only thing that decides whether a response was real.
 *
 * The feed is served from a host whose unmatched paths answer 200 with a
 * single-page app's HTML. A client that treated 2xx as success would record
 * success on every request while nothing arrived — on every subscribed site,
 * from one renamed function or one dropped rewrite. So validity is a property
 * of the body, never of the status code: the payload must carry both a `feed`
 * object and an `items` array or it did not come from the feed.
 *
 * @package Advision\TrendingNow
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ADVTN_Manual_Feed_Parser {

	/** The body was not JSON at all — an HTML error page, or a truncated response. */
	public const CODE_NOT_JSON = 'not_json';

	/** Valid JSON that is not a feed payload. */
	public const CODE_SHAPE = 'shape';

	/**
	 * Parse a response body.
	 *
	 * @param string $body Raw response body.
	 * @return array{ok:bool,code:string,error:string,slug:string,version:string,count:int,skipped:int,rows:array<int,array<string,mixed>>}
	 */
	public static function parse( string $body ): array {
		$decoded = json_decode( trim( $body ), true );

		if ( null === $decoded ) {
			return self::failure(
				self::CODE_NOT_JSON,
				__( 'The response was not JSON. A feed URL answering with an HTML page is usually the wrong path rather than a broken feed.', 'trending-now' )
			);
		}

		if ( ! is_array( $decoded ) || ! isset( $decoded['feed'] ) || ! is_array( $decoded['feed'] ) ) {
			return self::failure(
				self::CODE_SHAPE,
				__( 'The response carried no feed object.', 'trending-now' )
			);
		}

		if ( ! isset( $decoded['items'] ) || ! self::is_list( $decoded['items'] ) ) {
			return self::failure(
				self::CODE_SHAPE,
				__( 'The response carried no items list.', 'trending-now' )
			);
		}

		$rows    = array();
		$skipped = 0;

		foreach ( $decoded['items'] as $raw ) {
			$row = self::map_row( $raw );

			if ( null === $row ) {
				++$skipped;
				continue;
			}

			$rows[] = $row;
		}

		$version = isset( $decoded['feed']['version'] ) && is_scalar( $decoded['feed']['version'] )
			? (string) $decoded['feed']['version']
			: '';

		/*
		 * Which feed answered. Hawkeye compares this against the feed it pushed
		 * for; a mismatch means the site moved and its roster row is stale.
		 *
		 * Read out of the payload and never echoed from anything the caller
		 * supplied — an echoed value would make the comparison agree with
		 * itself, which is worse than not comparing.
		 */
		$slug = isset( $decoded['feed']['slug'] ) && is_scalar( $decoded['feed']['slug'] )
			? (string) $decoded['feed']['slug']
			: '';

		return array(
			'ok'      => true,
			'code'    => '',
			'error'   => '',
			'slug'    => $slug,
			'version' => $version,
			'count'   => count( $rows ),
			'skipped' => $skipped,
			'rows'    => $rows,
		);
	}

	/**
	 * Map one feed item to the curated-link row shape.
	 *
	 * Deliberately shallow. Whether a URL is usable, whether a title survives
	 * stripping, whether an image is valid — all of that belongs to
	 * ADVTN_Manual::validate(), which is the one implementation of it. This
	 * rejects only what cannot be a row at all, so the count of skipped items
	 * means "the feed sent something unusable" rather than "the feed sent
	 * something I decided against".
	 *
	 * @param mixed $raw Raw item.
	 * @return array<string,mixed>|null
	 */
	private static function map_row( $raw ): ?array {
		if ( ! is_array( $raw ) ) {
			return null;
		}

		$url   = isset( $raw['url'] ) && is_scalar( $raw['url'] ) ? trim( (string) $raw['url'] ) : '';
		$title = isset( $raw['title'] ) && is_scalar( $raw['title'] ) ? trim( (string) $raw['title'] ) : '';

		if ( '' === $url || '' === $title ) {
			return null;
		}

		return array(
			'url'          => $url,
			'title'        => $title,
			'excerpt'      => self::text( $raw, 'excerpt' ),
			'image_url'    => self::text( $raw, 'image_url' ),
			'site_name'    => self::text( $raw, 'site_name' ),
			'published_at' => self::text( $raw, 'published_at' ),
			'expires_at'   => self::text( $raw, 'expires_at' ),
			'position'     => isset( $raw['position'] ) ? (int) $raw['position'] : 0,
			// The feed filters disabled rows out before serving, so anything
			// that arrives is meant to be on display. Storing it disabled would
			// look exactly like the feed not working.
			'enabled'      => true,
		);
	}

	/**
	 * Read one optional string field.
	 *
	 * Empty string rather than null: ADVTN_Manual::validate() casts to string,
	 * so a null would arrive as the literal 'null' and be parsed as a date.
	 * Being explicit keeps the row shape identical to the admin form's.
	 *
	 * @param array<string,mixed> $raw Raw item.
	 * @param string              $key Field name.
	 * @return string
	 */
	private static function text( array $raw, string $key ): string {
		return isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) ? trim( (string) $raw[ $key ] ) : '';
	}

	/**
	 * Whether a value is a JSON array rather than a JSON object.
	 *
	 * `array_is_list()` is PHP 8.1. This plugin's floor is 7.4.
	 *
	 * @param mixed $value Decoded value.
	 * @return bool
	 */
	private static function is_list( $value ): bool {
		if ( ! is_array( $value ) ) {
			return false;
		}

		if ( array() === $value ) {
			return true;
		}

		return array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	/**
	 * A refusal, shaped identically to a success so callers need no branching
	 * to read `rows` or `count`.
	 *
	 * @param string $code    One of the CODE_* constants.
	 * @param string $message Human-readable reason.
	 * @return array{ok:bool,code:string,error:string,slug:string,version:string,count:int,skipped:int,rows:array<int,array<string,mixed>>}
	 */
	private static function failure( string $code, string $message ): array {
		return array(
			'ok'      => false,
			'code'    => $code,
			'error'   => $message,
			'slug'    => '',
			'version' => '',
			'count'   => 0,
			'skipped' => 0,
			'rows'    => array(),
		);
	}
}
