<?php
/**
 * The credential Hawkeye presents to make this site re-read its feed.
 *
 * Deliberately not `ingest_secret`. That secret also authorizes /ingest and
 * /status, so Hawkeye holding one per site would be a store of credentials that
 * can trigger ingest across the whole network and read each site's source
 * configuration. This one does exactly one thing to exactly one site: make it
 * fetch a feed it fetches anyway.
 *
 * It is called a key rather than a nonce because it is not single-use, and
 * because `nonce` in WordPress means `wp_nonce_*`, which has different
 * semantics and a different lifetime — somebody reading "nonce" here would
 * reach for `wp_verify_nonce()`.
 *
 * @package Advision\TrendingNow
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ADVTN_Sync_Key {

	/** Length of a key in hex characters. 64 hex = 32 bytes of entropy. */
	public const LENGTH = 64;

	/**
	 * The header the key travels in.
	 *
	 * A header rather than a query parameter, and that is not a style choice: a
	 * query parameter lands in Cloud Run's access logs and in any CDN in front
	 * of them. The feed token already travels in `Authorization` for the same
	 * reason.
	 */
	public const HEADER = 'X-ADVTN-Sync-Key';

	/**
	 * A new key.
	 *
	 * `random_bytes()` throws rather than degrading if the platform has no
	 * usable source of randomness, which is the behaviour worth having: a key
	 * generated from a weak source is worse than no key, because it looks like
	 * one.
	 *
	 * @return string 64 lowercase hex characters.
	 * @throws Exception If no source of randomness is available.
	 */
	public static function generate(): string {
		return bin2hex( random_bytes( self::LENGTH / 2 ) );
	}

	/**
	 * Is this the shape a stored key has?
	 *
	 * Lowercase only, because that is what `bin2hex()` produces and what is
	 * stored. Accepting either case here would mean the comparison had to
	 * normalise, and a comparison that normalises is one more step between the
	 * presented bytes and `hash_equals()`.
	 *
	 * @param string $value Candidate.
	 * @return bool
	 */
	public static function is_wellformed( string $value ): bool {
		return 1 === preg_match( '/^[0-9a-f]{' . self::LENGTH . '}$/', $value );
	}

	/**
	 * Does the presented key match the current one, or the previous one?
	 *
	 * **Both comparisons always run.** Returning early on the first match would
	 * make "matched the current key" and "matched the previous key" take
	 * measurably different times, and the second is the interesting one to an
	 * attacker probing a site that has just regenerated. Until fix round 1 the
	 * second comparison was behind `is_wellformed( $previous ) &&`, so `&&`
	 * short-circuited it away for every site that has never regenerated — the
	 * claim above described the intent and not the code.
	 *
	 * **THIS PROPERTY IS HELD BY REVIEW, NOT BY THE SUITE.** Timing is not
	 * unit-testable, and `tests/run.php` contains no assertion that would
	 * notice `hash_equals()` being replaced by `===`, or an early return being
	 * added on a current-key match. The plan for this feature claimed a
	 * mutation test covered it; that claim was false and is corrected in the
	 * plan and here. If you simplify this method, nothing will fail. Read it
	 * instead:
	 *
	 * - `hash_equals()`, never `===` or a substring test.
	 * - No early return between the two comparisons.
	 * - Neither comparison behind a `&&`.
	 *
	 * Empty is never a match. An empty stored key is a site that has never
	 * fetched, and an empty presented key is a caller that sent no header —
	 * treating either as a match would open the route to anybody who found it.
	 * Note that the two length guards below, not `hash_equals()`, are what
	 * reject a short or malformed candidate: a prefix of the key never reaches
	 * a comparison at all.
	 *
	 * @param string $presented What arrived on the request.
	 * @param string $current   The key this site is using.
	 * @param string $previous  The key it used before the last regeneration.
	 * @return bool
	 */
	public static function matches( string $presented, string $current, string $previous ): bool {
		if ( ! self::is_wellformed( $presented ) ) {
			return false;
		}

		// A previous key with no current one is not accepted: that state means
		// the current key was cleared, and a cleared credential has to stop
		// working rather than fall back to the one it replaced.
		if ( ! self::is_wellformed( $current ) ) {
			return false;
		}

		/*
		 * Two unconditional comparisons. No `&&`, and no `is_wellformed()` on
		 * `$previous`.
		 *
		 * That check was dropped rather than reordered because no input can
		 * reach it: `$presented` is already known well formed by the guard
		 * above, so a `hash_equals()` hit on `$previous` proves
		 * `$previous === $presented` and therefore that `$previous` is well
		 * formed too. It could never change an outcome, and this project's rule
		 * is that a guard no input can reach is a guard no test can hold in
		 * place — see ADVTN_Updater, where the same reasoning removed the same
		 * kind of check.
		 *
		 * The empty case that check appeared to be for is covered anyway:
		 * `hash_equals( '', $presented )` is false for any well-formed
		 * candidate, so the previous key being empty — the state of every site
		 * that has never pressed Replace — is not a match.
		 */
		$hit_current  = hash_equals( $current, $presented );
		$hit_previous = hash_equals( $previous, $presented );

		return $hit_current || $hit_previous;
	}
}
