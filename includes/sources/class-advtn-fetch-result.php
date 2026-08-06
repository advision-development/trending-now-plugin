<?php
/**
 * Value object returned by every source fetch.
 *
 * @package Advision\TrendingNow
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ADVTN_Fetch_Result {

	/**
	 * Normalized item arrays (spec §5.1).
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $items = array();

	/**
	 * Whether the fetch succeeded.
	 *
	 * @var bool
	 */
	public bool $ok = false;

	/**
	 * Human-readable error, shown in admin.
	 *
	 * @var string|null
	 */
	public ?string $error = null;

	/**
	 * HTTP status of the outbound request, when there was one.
	 *
	 * @var int|null
	 */
	public ?int $http_code = null;

	/**
	 * Wall-clock duration of the fetch.
	 *
	 * @var int
	 */
	public int $duration_ms = 0;

	/**
	 * How many raw records were returned before normalization/filtering.
	 *
	 * @var int
	 */
	public int $raw_count = 0;

	/**
	 * Build a success result.
	 *
	 * @param array<int,array<string,mixed>> $items     Normalized items.
	 * @param int|null                       $http_code HTTP status.
	 * @param int                            $raw_count Pre-filter record count.
	 * @return self
	 */
	public static function success( array $items, ?int $http_code = null, int $raw_count = 0 ): self {
		$result              = new self();
		$result->items       = $items;
		$result->ok          = true;
		$result->http_code   = $http_code;
		$result->raw_count   = $raw_count ?: count( $items );
		return $result;
	}

	/**
	 * Build a failure result.
	 *
	 * @param string   $error     Human-readable message.
	 * @param int|null $http_code HTTP status, when known.
	 * @return self
	 */
	public static function failure( string $error, ?int $http_code = null ): self {
		$result            = new self();
		$result->ok        = false;
		$result->error     = $error;
		$result->http_code = $http_code;
		return $result;
	}
}
