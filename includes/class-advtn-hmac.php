<?php
/**
 * Shared-secret request signing for the REST endpoints.
 *
 * No WordPress user account is involved: a machine trigger should not need a
 * real user in the loop, which is why application passwords are not used here.
 *
 * @package Advision\TrendingNow
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ADVTN_HMAC {

	public const HEADER_TIMESTAMP = 'X-ADVTN-Timestamp';
	public const HEADER_SIGNATURE = 'X-ADVTN-Signature';

	/** Maximum accepted clock skew, in seconds. */
	public const MAX_SKEW = 300;

	/** Replay-guard window, in seconds. */
	public const REPLAY_TTL = 300;

	/** Requests allowed per endpoint per RATE_WINDOW. */
	public const RATE_LIMIT = 30;

	/** Rate-limit window, in seconds. */
	public const RATE_WINDOW = 300;

	/**
	 * Compute a signature.
	 *
	 * message = timestamp . "\n" . raw_request_body (empty string for GET)
	 *
	 * @param int    $timestamp Unix seconds.
	 * @param string $body      Raw request body.
	 * @param string $secret    Shared secret.
	 * @return string Lowercase hex digest.
	 */
	public static function sign( int $timestamp, string $body, string $secret ): string {
		return hash_hmac( 'sha256', $timestamp . "\n" . $body, $secret );
	}

	/**
	 * Verify an inbound request.
	 *
	 * @param WP_REST_Request $request  Request object.
	 * @param string          $secret   Expected shared secret.
	 * @param string          $endpoint Endpoint key, for rate limiting.
	 * @return true|WP_Error
	 */
	public static function verify( WP_REST_Request $request, string $secret, string $endpoint ) {
		if ( '' === $secret ) {
			return new WP_Error(
				'advtn_no_secret',
				__( 'No shared secret is configured on this site.', 'trending-now' ),
				array( 'status' => 401 )
			);
		}

		$timestamp = (string) $request->get_header( self::HEADER_TIMESTAMP );
		$signature = strtolower( trim( (string) $request->get_header( self::HEADER_SIGNATURE ) ) );

		if ( '' === $timestamp || '' === $signature ) {
			return new WP_Error(
				'advtn_missing_auth',
				__( 'Missing signature headers.', 'trending-now' ),
				array( 'status' => 401 )
			);
		}

		if ( abs( time() - (int) $timestamp ) > self::MAX_SKEW ) {
			return new WP_Error(
				'advtn_timestamp_skew',
				__( 'Request timestamp is outside the accepted window.', 'trending-now' ),
				array( 'status' => 401 )
			);
		}

		$rate = self::check_rate_limit( $endpoint );
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		$expected = self::sign( (int) $timestamp, (string) $request->get_body(), $secret );

		if ( ! hash_equals( $expected, $signature ) ) {
			ADVTN_Logger::log( 'warning', 'Rejected a REST request with an invalid signature.', array( 'endpoint' => $endpoint ) );
			return new WP_Error(
				'advtn_bad_signature',
				__( 'Signature verification failed.', 'trending-now' ),
				array( 'status' => 401 )
			);
		}

		$replay_key = 'advtn_replay_' . sha1( $signature );
		if ( false !== get_transient( $replay_key ) ) {
			return new WP_Error(
				'advtn_replay',
				__( 'This request has already been processed.', 'trending-now' ),
				array( 'status' => 401 )
			);
		}
		set_transient( $replay_key, 1, self::REPLAY_TTL );

		return true;
	}

	/**
	 * Fixed-window rate limit, tracked in a transient.
	 *
	 * @param string $endpoint Endpoint key.
	 * @return true|WP_Error
	 */
	private static function check_rate_limit( string $endpoint ) {
		$key   = 'advtn_rate_' . md5( $endpoint );
		$count = (int) get_transient( $key );

		if ( $count >= self::RATE_LIMIT ) {
			return new WP_Error(
				'advtn_rate_limited',
				__( 'Too many requests.', 'trending-now' ),
				array( 'status' => 429 )
			);
		}

		set_transient( $key, $count + 1, self::RATE_WINDOW );

		return true;
	}
}
