<?php
/**
 * Cross-request ingest lock.
 *
 * add_option() is atomic: it is backed by the unique index on option_name and
 * returns false when the row already exists. update_option() has a read-then-
 * write race window, and wp_cache_add() is per-request without a confirmed
 * persistent object cache — neither is usable here.
 *
 * @package Advision\TrendingNow
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ADVTN_Lock {

	public const KEY = 'advtn_ingest_lock';
	public const TTL = 900;

	/**
	 * Try to take the lock, stealing it if the holder is stale.
	 *
	 * @return bool True when the caller now owns the lock.
	 */
	public static function acquire(): bool {
		if ( add_option( self::KEY, time(), '', 'no' ) ) {
			return true;
		}

		$held = (int) get_option( self::KEY, 0 );

		if ( ( time() - $held ) > self::TTL ) {
			// Previous run died without releasing. Take over.
			update_option( self::KEY, time(), false );
			ADVTN_Logger::log( 'warning', 'Stale ingest lock taken over.', array( 'age_seconds' => time() - $held ) );
			return true;
		}

		return false;
	}

	/**
	 * Release the lock. Safe to call when not held.
	 *
	 * @return void
	 */
	public static function release(): void {
		delete_option( self::KEY );
	}

	/**
	 * Whether a lock row exists.
	 *
	 * @return bool
	 */
	public static function is_held(): bool {
		return false !== get_option( self::KEY, false );
	}

	/**
	 * Seconds since the lock was taken, or null when free.
	 *
	 * @return int|null
	 */
	public static function age(): ?int {
		$held = get_option( self::KEY, false );
		if ( false === $held ) {
			return null;
		}
		return max( 0, time() - (int) $held );
	}
}
