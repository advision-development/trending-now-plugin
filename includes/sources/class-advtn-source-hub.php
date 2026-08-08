<?php
/**
 * Spoke-mode source: pulls a pre-assembled list from the hub.
 *
 * A spoke never talks to source sites directly — 15 sites × 15 sources would
 * mean 225 redundant daily fetches with divergent results.
 *
 * @package Advision\TrendingNow
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ADVTN_Source_Hub extends ADVTN_Source_Base {

	public const SOURCE_ID = 'src_hub';

	/**
	 * {@inheritDoc}
	 */
	public function get_type(): string {
		return 'hub';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return __( 'Hub', 'trending-now' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function fetch( array $config ): ADVTN_Fetch_Result {
		$hub_url = untrailingslashit( (string) ( $config['url'] ?? $this->settings->get_string( 'hub_url' ) ) );
		$secret  = (string) ( $config['secret'] ?? $this->settings->get_string( 'hub_secret' ) );

		if ( ! ADVTN_URL::is_valid( $hub_url ) ) {
			return ADVTN_Fetch_Result::failure( __( 'Hub URL is missing or invalid.', 'trending-now' ) );
		}

		if ( '' === $secret ) {
			return ADVTN_Fetch_Result::failure( __( 'Hub secret is not configured.', 'trending-now' ) );
		}

		$limit = max( 1, min( 500, (int) ( $config['limit'] ?? 200 ) ) );

		$endpoint = add_query_arg(
			array(
				'limit'        => $limit,
				'exclude_host' => ADVTN_URL::local_host(),
			),
			$hub_url . '/wp-json/advtn/v1/items'
		);

		$timestamp = time();

		$res    = $this->http_get(
			$endpoint,
			array(
				'timeout' => $this->config_timeout( $config ),
				'headers' => array(
					'Accept'            => 'application/json',
					'X-ADVTN-Timestamp' => (string) $timestamp,
					// GET has an empty body, so the signed message is just the
					// timestamp and the separator.
					'X-ADVTN-Signature' => ADVTN_HMAC::sign( $timestamp, '', $secret ),
				),
			)
		);
		$result = new ADVTN_Fetch_Result();

		$result->duration_ms = $res['ms'];
		$result->http_code   = $res['code'];

		if ( is_wp_error( $res['response'] ) ) {
			$result->error = $res['response']->get_error_message();
			return $result;
		}

		if ( 200 !== $res['code'] ) {
			/* translators: %d: HTTP status code. */
			$result->error = sprintf( __( 'Hub returned HTTP %d.', 'trending-now' ), (int) $res['code'] );
			return $result;
		}

		$decoded = json_decode( $res['body'], true );

		if ( ! is_array( $decoded ) || ! isset( $decoded['items'] ) || ! is_array( $decoded['items'] ) ) {
			$result->error = __( 'Hub response did not contain an items array.', 'trending-now' );
			return $result;
		}

		$items = array();

		foreach ( $decoded['items'] as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$item = $this->make_item(
				array(
					'url'          => (string) ( $raw['url'] ?? '' ),
					'title'        => (string) ( $raw['title'] ?? '' ),
					'excerpt'      => (string) ( $raw['excerpt'] ?? '' ),
					'image_url'    => (string) ( $raw['image_url'] ?? '' ),
					'published_at' => (string) ( $raw['published_at'] ?? '' ),
					'site_name'    => (string) ( $raw['site_name'] ?? '' ),
					// Preserve the upstream type so news share and rel
					// handling stay correct on the spoke.
					'source_type'  => in_array( (string) ( $raw['source_type'] ?? '' ), array( 'wp_rest', 'rss', 'serpapi', 'gdelt' ), true )
						? (string) $raw['source_type']
						: 'wp_rest',
				)
			);

			if ( null !== $item ) {
				$items[] = $item;
			}
		}

		$result->ok        = true;
		$result->items     = $items;
		$result->raw_count = count( $decoded['items'] );

		return $result;
	}

	/**
	 * {@inheritDoc}
	 */
	public function validate_config( array $config ) {
		$clean          = $this->base_config( $config );
		$clean['id']    = self::SOURCE_ID;
		$clean['label'] = __( 'Hub', 'trending-now' );
		$clean['limit'] = max( 1, min( 500, (int) ( $config['limit'] ?? 200 ) ) );
		$clean['url']   = untrailingslashit( esc_url_raw( (string) ( $config['url'] ?? '' ) ) );

		return $clean;
	}

	/**
	 * The synthetic source row used when the site is in spoke mode.
	 *
	 * @param ADVTN_Settings $settings Settings service.
	 * @return array<string,mixed>
	 */
	public static function virtual_config( ADVTN_Settings $settings ): array {
		return array(
			'id'            => self::SOURCE_ID,
			'label'         => __( 'Hub', 'trending-now' ),
			'type'          => 'hub',
			'enabled'       => true,
			'url'           => $settings->get_string( 'hub_url' ),
			'secret'        => $settings->get_string( 'hub_secret' ),
			'limit'         => 200,
			'stagger_index' => 0,
		);
	}
}
