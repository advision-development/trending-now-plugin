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
	 * Source types that count as third-party news rather than network links.
	 *
	 * Drives the news/network slot split, the `rel` attribute on external
	 * links and the `--news` modifier in the templates. Kept in one place so
	 * adding a news provider does not mean hunting for hardcoded 'gdelt'.
	 *
	 * @return string[]
	 */
	public static function news_types(): array {
		/**
		 * Filters which source types are treated as news.
		 *
		 * @param string[] $types Source type keys.
		 */
		// 'gdelt' has no provider any more, but rows ingested while it did are
		// still news: dropping it here would silently move them into the
		// network pool and strip their rel attribute and --news modifier.
		return (array) apply_filters( 'advtn_news_source_types', array( 'serpapi', 'gdelt' ) );
	}

	/**
	 * Whether a source type is third-party news.
	 *
	 * @param string $type Source type key.
	 * @return bool
	 */
	public static function is_news_type( string $type ): bool {
		return in_array( $type, self::news_types(), true );
	}

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
		$timeout = $this->settings->get_int( 'http_timeout', 1, 60 );

		$defaults = array(
			'timeout'     => $timeout,
			'redirection' => 3,
			'user-agent'  => self::USER_AGENT,
			'headers'     => array( 'Accept' => 'application/json' ),
		);

		// WordPress passes `timeout` straight through to Requests but never
		// touches `connect_timeout`, which Requests hardcodes to 10 seconds and
		// which covers the TLS handshake. An API that throttles by stalling the
		// handshake — GDELT does exactly this — therefore fails at 10s with
		// "Connection timed out", no matter how high http_timeout is set. Lift
		// the connect ceiling to match, for this request only.
		$raise_connect_timeout = static function ( &$handle ) use ( $timeout ): void {
			if ( is_resource( $handle ) || $handle instanceof \CurlHandle ) {
				curl_setopt( $handle, CURLOPT_CONNECTTIMEOUT, $timeout );
			}
		};

		add_action( 'http_api_curl', $raise_connect_timeout, 10, 1 );
		$response = wp_remote_get( $url, array_merge( $defaults, $args ) );
		remove_action( 'http_api_curl', $raise_connect_timeout, 10 );

		$ms = (int) round( ( microtime( true ) - $started ) * 1000 );

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
	 * The timeout in force for one source, in seconds.
	 *
	 * A row's own `timeout` overrides the global `http_timeout`; 0, '' or an
	 * absent key inherits it. The per-row ceiling is 120 against the global's
	 * 60 on purpose: the global is a blunt default applied to every source,
	 * where a per-row override is a considered choice about one provider.
	 *
	 * Governs the *request* timeout only. http_get() computes the connect
	 * ceiling from the global `http_timeout` before the merge that lets a caller
	 * override the request timeout, so a row set to 120 still fails fast on a
	 * stalled TLS handshake — deliberately.
	 *
	 * Public rather than protected so it can be exercised directly.
	 *
	 * @param array<string,mixed> $config Source config row.
	 * @return int
	 */
	public function config_timeout( array $config ): int {
		$override = (int) ( $config['timeout'] ?? 0 );

		if ( $override > 0 ) {
			return max( 1, min( 120, $override ) );
		}

		return $this->settings->get_int( 'http_timeout', 1, 60 );
	}

	/**
	 * Build a normalized item, or null when it must be rejected.
	 *
	 * Rejection rules (spec §5.1): invalid or non-http(s) URL, empty title
	 * after sanitization, or an item pointing at this site's own host.
	 *
	 * @param array<string,mixed> $raw               Partially mapped item.
	 * @param bool                $allow_local_host  Permit links back to this site.
	 * @return array<string,mixed>|null
	 */
	protected function make_item( array $raw, bool $allow_local_host = false ): ?array {
		$url = trim( (string) ( $raw['url'] ?? '' ) );

		if ( ! ADVTN_URL::is_valid( $url ) ) {
			return null;
		}

		$host = ADVTN_URL::host( $url );
		if ( '' === $host ) {
			return null;
		}

		if ( ! $allow_local_host && $host === ADVTN_URL::local_host() ) {
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
			'timeout'       => max( 0, min( 120, (int) ( $config['timeout'] ?? 0 ) ) ),
			'stagger_index' => max( 0, (int) ( $config['stagger_index'] ?? 0 ) ),
		);
	}
}
