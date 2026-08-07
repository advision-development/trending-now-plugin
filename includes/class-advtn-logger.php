<?php
/**
 * Ring-buffer logger backed by a single non-autoloaded option.
 *
 * @package Advision\TrendingNow
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ADVTN_Logger {

	public const OPTION   = 'advtn_log';
	public const MAX_ROWS = 200;

	/**
	 * Valid levels, least to most severe.
	 *
	 * @var string[]
	 */
	public const LEVELS = array( 'debug', 'info', 'warning', 'error' );

	/**
	 * Append an entry. `debug` is dropped unless WP_DEBUG is on.
	 *
	 * Never pass secrets or full signatures in $context.
	 *
	 * @param string              $level   One of LEVELS.
	 * @param string              $message Human-readable message.
	 * @param array<string,mixed> $context Extra structured data.
	 * @return void
	 */
	public static function log( string $level, string $message, array $context = array() ): void {
		if ( ! in_array( $level, self::LEVELS, true ) ) {
			$level = 'info';
		}

		if ( 'debug' === $level && ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
			return;
		}

		$entries = get_option( self::OPTION, array() );
		if ( ! is_array( $entries ) ) {
			$entries = array();
		}

		$entries[] = array(
			'time'    => gmdate( 'Y-m-d H:i:s' ),
			'level'   => $level,
			'message' => (string) $message,
			'context' => self::scrub( $context ),
		);

		if ( count( $entries ) > self::MAX_ROWS ) {
			$entries = array_slice( $entries, -self::MAX_ROWS );
		}

		update_option( self::OPTION, $entries, false );
	}

	/**
	 * All entries, newest first, optionally filtered by level.
	 *
	 * @param string|null $level Level filter.
	 * @return array<int,array<string,mixed>>
	 */
	public static function entries( ?string $level = null ): array {
		$entries = get_option( self::OPTION, array() );
		if ( ! is_array( $entries ) ) {
			return array();
		}

		$entries = array_reverse( $entries );

		if ( null !== $level && in_array( $level, self::LEVELS, true ) ) {
			$entries = array_values( array_filter( $entries, static fn( $e ) => ( $e['level'] ?? '' ) === $level ) );
		}

		return $entries;
	}

	/**
	 * Empty the buffer.
	 *
	 * @return void
	 */
	public static function clear(): void {
		update_option( self::OPTION, array(), false );
	}

	/**
	 * Redact anything that looks like a credential and cap value length.
	 *
	 * @param array<string,mixed> $context Raw context.
	 * @return array<string,mixed>
	 */
	private static function scrub( array $context ): array {
		$out = array();

		foreach ( $context as $key => $value ) {
			$lower = strtolower( (string) $key );

			if ( false !== strpos( $lower, 'secret' ) || false !== strpos( $lower, 'signature' ) || false !== strpos( $lower, 'token' ) || false !== strpos( $lower, 'password' ) || false !== strpos( $lower, 'api_key' ) || false !== strpos( $lower, 'apikey' ) ) {
				$out[ $key ] = '[redacted]';
				continue;
			}

			if ( is_scalar( $value ) || null === $value ) {
				$out[ $key ] = is_string( $value ) ? mb_substr( $value, 0, 500 ) : $value;
				continue;
			}

			$out[ $key ] = mb_substr( (string) wp_json_encode( $value ), 0, 500 );
		}

		return $out;
	}
}
