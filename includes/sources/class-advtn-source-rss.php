<?php
/**
 * RSS/Atom source, for sites where the REST API is unavailable.
 *
 * @package Advision\TrendingNow
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ADVTN_Source_RSS extends ADVTN_Source_Base {

	/**
	 * {@inheritDoc}
	 */
	public function get_type(): string {
		return 'rss';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return __( 'RSS / Atom feed', 'trending-now' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function fetch( array $config ): ADVTN_Fetch_Result {
		$url = trim( (string) ( $config['url'] ?? '' ) );

		if ( ! ADVTN_URL::is_valid( $url ) ) {
			return ADVTN_Fetch_Result::failure( __( 'Feed URL is missing or invalid.', 'trending-now' ) );
		}

		if ( ! function_exists( 'fetch_feed' ) ) {
			require_once ABSPATH . WPINC . '/feed.php';
		}

		$limit   = max( 1, min( 100, (int) ( $config['limit'] ?? 10 ) ) );
		$started = microtime( true );

		// SimplePie caches feeds for 12h by default, which would silently serve
		// stale data on a daily ingest. Shorten it for this call only.
		add_filter( 'wp_feed_cache_transient_lifetime', array( $this, 'cache_lifetime' ), 100 );
		$feed = fetch_feed( $url );
		remove_filter( 'wp_feed_cache_transient_lifetime', array( $this, 'cache_lifetime' ), 100 );

		$result              = new ADVTN_Fetch_Result();
		$result->duration_ms = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( is_wp_error( $feed ) ) {
			$result->error = $feed->get_error_message();
			return $result;
		}

		$available = (int) $feed->get_item_quantity();
		$count     = min( $limit, $available );

		$site_name = (string) ( $config['label'] ?? '' );
		if ( '' === $site_name ) {
			$site_name = self::plain_text( (string) $feed->get_title() );
		}

		$items = array();

		foreach ( $feed->get_items( 0, $count ) as $entry ) {
			$item = $this->make_item(
				array(
					'url'          => (string) $entry->get_permalink(),
					'title'        => (string) $entry->get_title(),
					'excerpt'      => wp_trim_words( wp_strip_all_tags( (string) $entry->get_description() ), 30, '' ),
					'image_url'    => $this->entry_image( $entry ),
					// SimplePie returns dates in UTC.
					'published_at' => (string) $entry->get_date( 'Y-m-d H:i:s' ),
					'site_name'    => $site_name,
					'source_type'  => 'rss',
				)
			);

			if ( null !== $item ) {
				$items[] = $item;
			}
		}

		$result->ok        = true;
		$result->items     = $items;
		$result->raw_count = $available;
		$result->http_code = 200;

		return $result;
	}

	/**
	 * {@inheritDoc}
	 */
	public function validate_config( array $config ) {
		$clean = $this->base_config( $config );
		$url   = trim( (string) ( $config['url'] ?? '' ) );

		if ( '' === $url ) {
			return new WP_Error( 'advtn_missing_url', __( 'A feed URL is required for RSS sources.', 'trending-now' ) );
		}

		if ( ! ADVTN_URL::is_valid( $url ) ) {
			return new WP_Error( 'advtn_invalid_url', __( 'That feed URL is not a valid public http(s) address.', 'trending-now' ) );
		}

		$clean['url'] = esc_url_raw( $url );

		if ( '' === $clean['label'] ) {
			$clean['label'] = ADVTN_URL::host( $url );
		}

		return $clean;
	}

	/**
	 * Feed cache lifetime override, in seconds.
	 *
	 * @return int
	 */
	public function cache_lifetime(): int {
		return 1;
	}

	/**
	 * Best-effort image extraction: enclosure, then media:thumbnail /
	 * media:content.
	 *
	 * @param SimplePie_Item $entry Feed item.
	 * @return string
	 */
	private function entry_image( $entry ): string {
		$enclosure = $entry->get_enclosure();

		if ( $enclosure ) {
			$thumb = (string) $enclosure->get_thumbnail();
			if ( '' !== $thumb ) {
				return $thumb;
			}

			$link = (string) $enclosure->get_link();
			$type = (string) $enclosure->get_type();
			if ( '' !== $link && ( '' === $type || 0 === strpos( $type, 'image/' ) ) ) {
				return $link;
			}
		}

		foreach ( array( 'thumbnail', 'content' ) as $tag ) {
			$nodes = $entry->get_item_tags( 'http://search.yahoo.com/mrss/', $tag );
			if ( ! empty( $nodes[0]['attribs']['']['url'] ) ) {
				return (string) $nodes[0]['attribs']['']['url'];
			}
		}

		return '';
	}
}
