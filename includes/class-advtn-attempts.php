<?php
/**
 * Per-source attempt history: a short ring of recent fetches and its summary.
 *
 * Source state keeps only the most recent run, so a source drifting from two
 * seconds toward its timeout over an afternoon is invisible until it crosses
 * the line. This is what makes that drift a number.
 *
 * Pure static and free of WordPress state so the dependency-free harness can
 * exercise it.
 *
 * @package Advision\TrendingNow
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ADVTN_Attempts {

	/** How many attempts to keep per source. */
	public const MAX = 20;

	/** Error messages are truncated to this many characters at write time. */
	public const ERROR_MAX = 120;

	/**
	 * Append one attempt and trim to the cap.
	 *
	 * Errors are truncated here rather than at read time: an untruncated cURL
	 * message can carry a long URL, and twenty of those per source is what
	 * turns a diagnostic aid into a bloated option.
	 *
	 * @param array<int,array<string,mixed>> $ring  Existing ring, newest last.
	 * @param bool                           $ok    Whether the fetch succeeded.
	 * @param int                            $ms    Elapsed milliseconds.
	 * @param int|null                       $code  HTTP status, null on a transport error.
	 * @param string                         $error Error message, '' on success.
	 * @return array<int,array<string,mixed>>
	 */
	public static function record( array $ring, bool $ok, int $ms, ?int $code, string $error ): array {
		$ring[] = array(
			't'    => gmdate( 'Y-m-d H:i:s' ),
			'ok'   => $ok,
			'ms'   => max( 0, $ms ),
			'code' => $code,
			'err'  => mb_substr( $error, 0, self::ERROR_MAX ),
		);

		if ( count( $ring ) > self::MAX ) {
			$ring = array_slice( $ring, -self::MAX );
		}

		return array_values( $ring );
	}

	/**
	 * Median, maximum and count over a ring.
	 *
	 * The median rather than a mean, so one outlier does not hide an otherwise
	 * healthy set of fetches.
	 *
	 * @param array<int,array<string,mixed>> $ring Ring, newest last.
	 * @return array{count:int,p50:int,max:int}
	 */
	public static function summary( array $ring ): array {
		$times = array();

		foreach ( $ring as $entry ) {
			if ( isset( $entry['ms'] ) ) {
				$times[] = (int) $entry['ms'];
			}
		}

		if ( empty( $times ) ) {
			return array(
				'count' => 0,
				'p50'   => 0,
				'max'   => 0,
			);
		}

		sort( $times );
		$count  = count( $times );
		$middle = (int) floor( ( $count - 1 ) / 2 );

		$p50 = 0 === $count % 2
			? (int) round( ( $times[ $middle ] + $times[ $middle + 1 ] ) / 2 )
			: $times[ $middle ];

		return array(
			'count' => $count,
			'p50'   => $p50,
			'max'   => max( $times ),
		);
	}
}
