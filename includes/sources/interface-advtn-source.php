<?php
/**
 * Contract every source provider implements.
 *
 * @package Advision\TrendingNow
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface ADVTN_Source_Interface {

	/**
	 * Machine key: 'wp_rest', 'rss', 'gdelt', 'hub'.
	 *
	 * @return string
	 */
	public function get_type(): string;

	/**
	 * Human label for the admin type selector.
	 *
	 * @return string
	 */
	public function get_label(): string;

	/**
	 * Fetch and normalize items for one configured source.
	 *
	 * MUST NOT write to the database. MUST NOT throw.
	 *
	 * @param array<string,mixed> $config Source config row.
	 * @return ADVTN_Fetch_Result
	 */
	public function fetch( array $config ): ADVTN_Fetch_Result;

	/**
	 * Validate and sanitize a source config row from the settings screen.
	 *
	 * @param array<string,mixed> $config Raw config row.
	 * @return array<string,mixed>|WP_Error
	 */
	public function validate_config( array $config );
}
