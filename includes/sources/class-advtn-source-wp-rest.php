<?php
/**
 * Owned-site source over the WordPress REST API.
 *
 * Preferred over /feed/ because RSS output is capped by the remote site's
 * `posts_per_rss` option with no per-request override, which would mean
 * changing a setting on every source site.
 *
 * @package Advision\TrendingNow
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ADVTN_Source_WP_REST extends ADVTN_Source_Base {

	/**
	 * {@inheritDoc}
	 */
	public function get_type(): string {
		return 'wp_rest';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return __( 'WordPress REST API', 'trending-now' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function fetch( array $config ): ADVTN_Fetch_Result {
		$base = untrailingslashit( trim( (string) ( $config['url'] ?? '' ) ) );

		if ( ! ADVTN_URL::is_valid( $base ) ) {
			return ADVTN_Fetch_Result::failure( __( 'Source URL is missing or invalid.', 'trending-now' ) );
		}

		$limit = max( 1, min( 100, (int) ( $config['limit'] ?? 10 ) ) );

		$endpoint = add_query_arg(
			array(
				'per_page' => $limit,
				'orderby'  => 'date',
				'order'    => 'desc',
				'status'   => 'publish',
				'_embed'   => 'wp:featuredmedia',
				// Both _embedded AND _links must be listed. The server builds
				// _embedded by walking $data['_links'], and _fields strips
				// _links out of $data before that runs — so asking for
				// _embedded alone returns neither. Verified against WP 6.9.
				'_fields'  => 'id,link,title,excerpt,date_gmt,_embedded,_links',
			),
			$base . '/wp-json/wp/v2/posts'
		);

		$res    = $this->http_get( $endpoint, array( 'timeout' => $this->config_timeout( $config ) ) );
		$result = new ADVTN_Fetch_Result();

		$result->duration_ms = $res['ms'];
		$result->http_code   = $res['code'];

		if ( is_wp_error( $res['response'] ) ) {
			$result->error = $res['response']->get_error_message();
			return $result;
		}

		if ( 401 === $res['code'] || 403 === $res['code'] ) {
			$result->error = sprintf(
				/* translators: %d: HTTP status code. */
				__( 'HTTP %d — the REST API is likely disabled or restricted on that site. Switch this source to the RSS type.', 'trending-now' ),
				(int) $res['code']
			);
			return $result;
		}

		if ( 200 !== $res['code'] ) {
			/* translators: %d: HTTP status code. */
			$result->error = sprintf( __( 'Unexpected HTTP status %d.', 'trending-now' ), (int) $res['code'] );
			return $result;
		}

		$posts = json_decode( $res['body'], true );

		if ( ! is_array( $posts ) ) {
			$result->error = __( 'Response was not valid JSON.', 'trending-now' );
			return $result;
		}

		$site_name = (string) ( $config['label'] ?? '' );
		$items     = array();

		foreach ( $posts as $post ) {
			if ( ! is_array( $post ) ) {
				continue;
			}

			$item = $this->make_item(
				array(
					'url'          => (string) ( $post['link'] ?? '' ),
					'title'        => (string) ( $post['title']['rendered'] ?? '' ),
					'excerpt'      => isset( $post['excerpt']['rendered'] )
						? wp_trim_words( wp_strip_all_tags( (string) $post['excerpt']['rendered'] ), 30, '' )
						: '',
					'image_url'    => $this->featured_image( $post ),
					'published_at' => $this->to_utc( (string) ( $post['date_gmt'] ?? '' ) ),
					'site_name'    => '' !== $site_name ? $site_name : ADVTN_URL::host( (string) ( $post['link'] ?? '' ) ),
					'source_type'  => 'wp_rest',
				)
			);

			if ( null !== $item ) {
				$items[] = $item;
			}
		}

		$result->ok        = true;
		$result->items     = $items;
		$result->raw_count = count( $posts );

		return $result;
	}

	/**
	 * {@inheritDoc}
	 */
	public function validate_config( array $config ) {
		$clean = $this->base_config( $config );
		$url   = untrailingslashit( trim( (string) ( $config['url'] ?? '' ) ) );

		if ( '' === $url ) {
			return new WP_Error( 'advtn_missing_url', __( 'A site URL is required for WordPress REST sources.', 'trending-now' ) );
		}

		if ( ! ADVTN_URL::is_valid( $url ) ) {
			return new WP_Error( 'advtn_invalid_url', __( 'That site URL is not a valid public http(s) address.', 'trending-now' ) );
		}

		$clean['url'] = esc_url_raw( $url );

		if ( '' === $clean['label'] ) {
			$clean['label'] = ADVTN_URL::host( $url );
		}

		return $clean;
	}

	/**
	 * Resolve the featured image, preferring medium_large.
	 *
	 * `_embedded` is absent entirely when a post has no featured image, so
	 * every level of this access is guarded.
	 *
	 * @param array<string,mixed> $post Decoded post object.
	 * @return string Image URL or ''.
	 */
	private function featured_image( array $post ): string {
		$media = $post['_embedded']['wp:featuredmedia'][0] ?? null;

		if ( ! is_array( $media ) ) {
			return '';
		}

		$sizes = $media['media_details']['sizes'] ?? null;

		if ( is_array( $sizes ) ) {
			foreach ( array( 'medium_large', 'medium' ) as $size ) {
				if ( ! empty( $sizes[ $size ]['source_url'] ) ) {
					return (string) $sizes[ $size ]['source_url'];
				}
			}
		}

		return ! empty( $media['source_url'] ) ? (string) $media['source_url'] : '';
	}

	/**
	 * Reformat an already-UTC REST date to the storage format.
	 *
	 * @param string $date_gmt Value of the date_gmt field.
	 * @return string
	 */
	private function to_utc( string $date_gmt ): string {
		$date_gmt = trim( $date_gmt );
		if ( '' === $date_gmt ) {
			return '';
		}

		$timestamp = strtotime( $date_gmt . ' UTC' );

		return false === $timestamp ? '' : gmdate( 'Y-m-d H:i:s', $timestamp );
	}
}
