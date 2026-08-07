<?php
/**
 * GDELT DOC 2.0 news source.
 *
 * No API key, free, and returns real publisher URLs — which is why it is used
 * instead of Google News RSS, whose news.google.com/rss/articles/… redirect
 * URLs resist server-side resolution.
 *
 * @package Advision\TrendingNow
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ADVTN_Source_GDELT extends ADVTN_Source_Base {

	public const ENDPOINT = 'https://api.gdeltproject.org/api/v2/doc/doc';

	/**
	 * GDELT asks for no more than one request every five seconds.
	 *
	 * Exceeding it earns a 429 whose penalty outlasts the stated window, and
	 * while throttled GDELT returns misleading error text — "the specified
	 * domain is too short or too long" for a perfectly valid single domain —
	 * so a tripped rate limit is easily mistaken for a broken query.
	 */
	public const MIN_REQUEST_GAP = 5;

	/**
	 * Unix timestamp of the last outbound GDELT request this process made.
	 *
	 * @var float
	 */
	private static float $last_request_at = 0.0;

	/**
	 * {@inheritDoc}
	 */
	public function get_type(): string {
		return 'gdelt';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return __( 'GDELT news', 'trending-now' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function fetch( array $config ): ADVTN_Fetch_Result {
		$domains = $this->clean_domains( (array) ( $config['gdelt_domains'] ?? array() ) );
		$limit   = max( 1, min( 250, (int) ( $config['limit'] ?? 40 ) ) );

		$endpoint = add_query_arg(
			array(
				'query'      => rawurlencode( $this->build_query( (string) ( $config['gdelt_query'] ?? '' ), $domains ) ),
				'mode'       => 'ArtList',
				'format'     => 'json',
				'maxrecords' => $limit,
				'timespan'   => $this->clean_timespan( (string) ( $config['gdelt_timespan'] ?? '2d' ) ),
				'sort'       => 'DateDesc',
			),
			self::ENDPOINT
		);

		$this->respect_rate_limit();

		$res    = $this->http_get( $endpoint );
		$result = new ADVTN_Fetch_Result();

		$result->duration_ms = $res['ms'];
		$result->http_code   = $res['code'];

		if ( is_wp_error( $res['response'] ) ) {
			$message = $res['response']->get_error_message();

			// The default 5s http_timeout is below GDELT's typical response
			// time, so this is the single most common way it fails.
			if ( false !== stripos( $message, 'timed out' ) || false !== stripos( $message, 'timeout' ) ) {
				$message .= ' ' . sprintf(
					/* translators: %d: configured timeout in seconds. */
					__( 'GDELT commonly takes 10-20 seconds to answer; the HTTP timeout is currently %d seconds. Raise it under Settings → Scheduling.', 'trending-now' ),
					$this->settings->get_int( 'http_timeout', 1, 60 )
				);
			}

			$result->error = $message;
			return $result;
		}

		if ( 429 === $res['code'] ) {
			$result->error = __( 'GDELT rate limit hit (HTTP 429). It allows roughly one request every five seconds, and the penalty outlasts that window. Wait a few minutes before retrying, and avoid repeated Test fetch clicks on GDELT rows.', 'trending-now' );
			return $result;
		}

		if ( 200 !== $res['code'] ) {
			/* translators: 1: HTTP status code, 2: start of the response body. */
			$result->error = sprintf( __( 'Unexpected HTTP status %1$d. Response began: %2$s', 'trending-now' ), (int) $res['code'], $this->snippet( $res['body'] ) );
			return $result;
		}

		$decoded = json_decode( $res['body'], true );

		// GDELT reports several failures as plain text with a 200 status —
		// "Your query was too short or too long.", "The specified domain is
		// too short or too long.", the rate-limit notice, or an HTML error
		// page. Surface whatever it actually said: a generic "not JSON" hides
		// the one piece of information that would explain the failure.
		if ( null === $decoded || ! is_array( $decoded ) ) {
			$body = $this->snippet( $res['body'] );

			$result->error = '' === $body
				? __( 'GDELT returned an empty or unparseable response.', 'trending-now' )
				/* translators: %s: start of the response body. */
				: sprintf( __( 'GDELT rejected the request: %s', 'trending-now' ), $body );

			ADVTN_Logger::log(
				'error',
				'GDELT returned a non-JSON response.',
				array(
					'source_id'    => (string) ( $config['id'] ?? '' ),
					'http_code'    => $res['code'],
					'content_type' => strtolower( (string) wp_remote_retrieve_header( $res['response'], 'content-type' ) ),
					'snippet'      => $body,
				)
			);

			return $result;
		}

		if ( ! isset( $decoded['articles'] ) || ! is_array( $decoded['articles'] ) ) {
			// An empty result set has no `articles` key at all.
			$result->ok = true;
			return $result;
		}

		$articles = $decoded['articles'];
		$items    = array();
		$rejected = 0;

		foreach ( $articles as $article ) {
			if ( ! is_array( $article ) ) {
				continue;
			}

			$url  = (string) ( $article['url'] ?? '' );
			$host = ADVTN_URL::host( $url );

			// The GDELT query language is fuzzy and near-domain matches leak
			// through, so the allowlist is enforced again here.
			if ( ! empty( $domains ) && ! $this->host_allowed( $host, $domains ) ) {
				++$rejected;
				continue;
			}

			$item = $this->make_item(
				array(
					'url'   => $url,
					'title' => (string) ( $article['title'] ?? '' ),
					// GDELT has no excerpt field at all.
					'excerpt'      => '',
					'image_url'    => (string) ( $article['socialimage'] ?? '' ),
					// `seendate` is discovery time, not publish time. Accepted
					// deliberately: it is the only date GDELT exposes.
					'published_at' => $this->parse_seendate( (string) ( $article['seendate'] ?? '' ) ),
					'site_name'    => (string) ( $article['domain'] ?? $host ),
					'source_type'  => 'gdelt',
				)
			);

			if ( null !== $item ) {
				$items[] = $item;
			}
		}

		if ( $rejected > 0 ) {
			ADVTN_Logger::log(
				'debug',
				'GDELT items dropped by the domain allowlist.',
				array(
					'source_id' => (string) ( $config['id'] ?? '' ),
					'rejected'  => $rejected,
				)
			);
		}

		$result->ok        = true;
		$result->items     = $items;
		$result->raw_count = count( $articles );

		return $result;
	}

	/**
	 * {@inheritDoc}
	 */
	public function validate_config( array $config ) {
		$clean = $this->base_config( $config );

		$query = trim( (string) ( $config['gdelt_query'] ?? '' ) );
		if ( '' === $query ) {
			return new WP_Error( 'advtn_missing_query', __( 'A GDELT query is required.', 'trending-now' ) );
		}

		$raw_domains = $config['gdelt_domains'] ?? array();
		if ( is_string( $raw_domains ) ) {
			$raw_domains = preg_split( '/[\s,]+/', $raw_domains ) ?: array();
		}

		$domains = $this->clean_domains( (array) $raw_domains );
		if ( empty( $domains ) ) {
			return new WP_Error( 'advtn_missing_domains', __( 'At least one allowed domain is required for GDELT sources.', 'trending-now' ) );
		}

		$clean['limit']          = max( 1, min( 250, (int) ( $config['limit'] ?? 40 ) ) );
		$clean['gdelt_query']    = sanitize_text_field( $query );
		$clean['gdelt_domains']  = $domains;
		$clean['gdelt_timespan'] = $this->clean_timespan( (string) ( $config['gdelt_timespan'] ?? '2d' ) );
		$clean['url']            = '';

		if ( '' === $clean['label'] ) {
			$clean['label'] = __( 'GDELT news', 'trending-now' );
		}

		return $clean;
	}

	/**
	 * Combine the operator's query with the domain allowlist as an OR group.
	 *
	 * @param string   $query   Operator query fragment.
	 * @param string[] $domains Allowlisted domains.
	 * @return string
	 */
	public function build_query( string $query, array $domains ): string {
		$query = trim( $query );

		if ( empty( $domains ) ) {
			return $query;
		}

		$group = '(' . implode( ' OR ', array_map( static fn( $d ) => 'domain:' . $d, $domains ) ) . ')';

		return '' !== $query ? $group . ' ' . $query : $group;
	}

	/**
	 * Space consecutive GDELT calls within this process.
	 *
	 * Two GDELT sources in one inline run would otherwise fire back to back
	 * and trip the limit on the second, which then looks like a broken query.
	 *
	 * @return void
	 */
	private function respect_rate_limit(): void {
		$elapsed = microtime( true ) - self::$last_request_at;

		if ( self::$last_request_at > 0.0 && $elapsed < self::MIN_REQUEST_GAP ) {
			usleep( (int) ( ( self::MIN_REQUEST_GAP - $elapsed ) * 1000000 ) );
		}

		self::$last_request_at = microtime( true );
	}

	/**
	 * First line of a response body, collapsed and trimmed for display.
	 *
	 * @param string $body Raw response body.
	 * @return string
	 */
	private function snippet( string $body ): string {
		$body = trim( wp_strip_all_tags( $body ) );
		$body = (string) preg_replace( '/\s+/u', ' ', $body );

		return mb_substr( $body, 0, 200 );
	}

	/**
	 * Normalize a domain list to lowercase bare hosts.
	 *
	 * @param mixed[] $domains Raw domains.
	 * @return string[]
	 */
	private function clean_domains( array $domains ): array {
		$out = array();

		foreach ( $domains as $domain ) {
			$domain = strtolower( trim( (string) $domain ) );
			if ( '' === $domain ) {
				continue;
			}

			// Tolerate a pasted URL.
			if ( false !== strpos( $domain, '/' ) || false !== strpos( $domain, ':' ) ) {
				$domain = ADVTN_URL::host( $domain );
			}

			$domain = preg_replace( '/^www\./', '', $domain ) ?? $domain;
			$domain = preg_replace( '/[^a-z0-9.\-]/', '', $domain ) ?? '';

			if ( '' !== $domain && false !== strpos( $domain, '.' ) ) {
				$out[] = $domain;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Whether a host matches the allowlist exactly or as a subdomain.
	 *
	 * @param string   $host    Item host.
	 * @param string[] $domains Allowlist.
	 * @return bool
	 */
	private function host_allowed( string $host, array $domains ): bool {
		if ( '' === $host ) {
			return false;
		}

		foreach ( $domains as $domain ) {
			if ( $host === $domain || str_ends_with( $host, '.' . $domain ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Validate a GDELT timespan expression such as 15min, 24h, 2d, 3w.
	 *
	 * @param string $timespan Raw value.
	 * @return string
	 */
	private function clean_timespan( string $timespan ): string {
		$timespan = strtolower( trim( $timespan ) );

		return preg_match( '/^\d{1,3}(min|h|d|w|m)$/', $timespan ) ? $timespan : '2d';
	}

	/**
	 * Parse GDELT's Ymd\THis\Z stamp into the storage format.
	 *
	 * @param string $seendate Raw seendate.
	 * @return string
	 */
	private function parse_seendate( string $seendate ): string {
		$seendate = trim( $seendate );
		if ( '' === $seendate ) {
			return '';
		}

		$date = DateTimeImmutable::createFromFormat( 'Ymd\THis\Z', $seendate, new DateTimeZone( 'UTC' ) );

		if ( false === $date ) {
			$timestamp = strtotime( $seendate );
			return false === $timestamp ? '' : gmdate( 'Y-m-d H:i:s', $timestamp );
		}

		return $date->format( 'Y-m-d H:i:s' );
	}
}
