<?php
/**
 * Core sitemap provider for the archive pages.
 *
 * @package Advision\TrendingNow
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_Sitemaps_Provider' ) ) {
	return;
}

final class ADVTN_Sitemap_Provider extends WP_Sitemaps_Provider {

	/**
	 * Archive service.
	 *
	 * @var ADVTN_Archive
	 */
	private ADVTN_Archive $archive;

	/**
	 * Constructor.
	 *
	 * @param ADVTN_Archive $archive Archive service.
	 */
	public function __construct( ADVTN_Archive $archive ) {
		$this->archive     = $archive;
		$this->name        = 'advtn-archive';
		$this->object_type = 'advtn-archive';
	}

	/**
	 * One sitemap URL per archive page.
	 *
	 * @param int    $page_num       Sitemap page number.
	 * @param string $object_subtype Unused.
	 * @return array<int,array<string,string>>
	 */
	public function get_url_list( $page_num, $object_subtype = '' ) {
		unset( $object_subtype );

		if ( 1 !== (int) $page_num ) {
			return array();
		}

		$urls  = array();
		$total = min( $this->archive->total_pages(), 1000 );

		for ( $i = 1; $i <= $total; $i++ ) {
			$urls[] = array( 'loc' => $this->archive->page_url( $i ) );
		}

		return $urls;
	}

	/**
	 * Sitemap index page count.
	 *
	 * @param string $object_subtype Unused.
	 * @return int
	 */
	public function get_max_num_pages( $object_subtype = '' ) {
		unset( $object_subtype );

		return 1;
	}
}
