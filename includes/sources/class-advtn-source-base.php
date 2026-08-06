<?php
/**
 * Shared plumbing for source providers: HTTP, item normalization, rejection
 * rules.
 *
 * @package Advision\TrendingNow
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class ADVTN_Source_Base implements ADVTN_Source_Interface {

	public const USER_AGENT = 'AdvisionTrendingNow/1.0';

	/**
	 * Settings service.
	 *
	 * @var ADVTN_Settings
	 */
	protected ADVTN_Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param ADVTN_Settings $settings Settings service.
	 */
	public function __construct( ADVTN_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Perform an outbound GET through the WP HTTP API.
	 *
	 * @param string               $url  Absolute URL.
	 * @param array<string,mixed>  $args Extra wp_remote_get args.
	 * @return array{response:array|WP_Error,code:int|null,body:string,ms:int}
	 */
	protected function http_get( string $url, array $args = array() ): array {
		$started = microtime( true );

		$defaults = array(
			'timeout'     => $this->settings->get_int( 'http_timeout', 1, 30 ),
			'redirection' => 3,
			'user-agent'  => self::USER_AGENT,
			'headers'     => array( 'Accept' => 'application/json' ),
		);

		$response = wp_remote_get( $url, array_merge( $defaults, $args ) );
		$ms       = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( is_wp_error( $response ) ) {
			return array(
				'response' => $response,
				'code'     => null,
				'body'     => '',
				'ms'       => $ms,
			);
		}

		return array(
			'response' => $response,
			'code'     => (int) wp_remote_retrieve_response_code( $response ),
			'body'     => (string) wp_remote_retrieve_body( $response ),
			'ms'       => $ms,
		);
	}

	/**
	 * Build a normalized item, or null when it must be rejected.
	 *
	 * Rejection rules (spec §5.1): invalid or non-http(s) URL, empty title
	 * after sanitization, or an item pointing at this site's own host.
	 *
	 * @param array<string,mixed> $raw Partially mapped item.
	 * @return array<string,mixed>|null
	 */
	protected function make_item( array $raw ): ?array {
		$url = trim( (string) ( $raw['url'] ?? '' ) );

		if ( ! ADVTN_URL::is_valid( $url ) ) {
			return null;
		}

		$host = ADVTN_URL::host( $url );
		if ( '' === $host || $host === ADVTN_URL::local_host() ) {
			return null;
		}

		$title = self::plain_text( (string) ( $raw['title'] ?? '' ) );
		if ( '' === $title ) {
			return null;
		}

		$excerpt = isset( $raw['excerpt'] ) ? self::plain_text( (string) $raw['excerpt'] ) : '';
		$excerpt = '' !== $excerpt ? mb_substr( $excerpt, 0, 500 ) : null;

		$image = isset( $raw['image_url'] ) ? trim( (string) $raw['image_url'] ) : '';
		$image = ( '' !== $image && ADVTN_URL::is_valid( $image ) ) ? $image : null;

		$published = isset( $raw['published_at'] ) ? trim( (string) $raw['published_at'] ) : '';
		$published = '' !== $published ? $published : null;

		return array(
			'url'          => $url,
			'title'        => mb_substr( $title, 0, 500 ),
			'excerpt'      => $excerpt,
			'image_url'    => $image,
			'published_at' => $published,
			'site_name'    => mb_substr( self::plain_text( (string) ( $raw['site_name'] ?? $host ) ), 0, 191 ),
			'source_type'  => (string) ( $raw['source_type'] ?? $this->get_type() ),
		);
	}

	/**
	 * Strip tags, decode entities, collapse whitespace.
	 *
	 * @param string $value Raw string.
	 * @return string
	 */
	protected static function plain_text( string $value ): string {
		$value = wp_strip_all_tags( $value, true );
		$value = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$value = preg_replace( '/\s+/u', ' ', $value ) ?? $value;

		return trim( $value );
	}

	/**
	 * Common config validation shared by all types.
	 *
	 * @param array<string,mixed> $config Raw row.
	 * @return array<string,mixed>
	 */
	protected function base_config( array $config ): array {
		return array(
			'id'            => ! empty( $config['id'] ) ? preg_replace( '/[^a-z0-9_]/', '', (string) $config['id'] ) : ADVTN_Settings::new_source_id(),
			'label'         => sanitize_text_field( (string) ( $config['label'] ?? '' ) ),
			'type'          => $this->get_type(),
			'enabled'       => ! empty( $config['enabled'] ),
			'limit'         => max( 1, min( 100, (int) ( $config['limit'] ?? 10 ) ) ),
			'stagger_index' => max( 0, (int) ( $config['stagger_index'] ?? 0 ) ),
		);
	}
}
