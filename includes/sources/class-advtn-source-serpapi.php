<?php
/**
 * Google News via SerpAPI.
 *
 * Same job as the GDELT provider — authoritative third-party articles to sit
 * alongside the network's own links — but paid, faster and with far better
 * coverage. It is the sensible choice when GDELT's latency or its rate limit
 * is a problem.
 *
 * Unlike Google News RSS, SerpAPI resolves to real publisher URLs, which is
 * what makes it usable here at all.
 *
 * @package Advision\TrendingNow
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ADVTN_Source_SerpAPI extends ADVTN_Source_Base {

	public const ENDPOINT         = 'https://serpapi.com/search.json';
	public const ACCOUNT_ENDPOINT = 'https://serpapi.com/account.json';

	/** Cached account snapshot, so Diagnostics does not bill a search per view. */
	public const ACCOUNT_TRANSIENT = 'advtn_serpapi_account';
	public const ACCOUNT_TTL       = 900;

	/**
	 * {@inheritDoc}
	 */
	public function get_type(): string {
		return 'serpapi';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return __( 'Google News (SerpAPI)', 'trending-now' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function fetch( array $config ): ADVTN_Fetch_Result {
		$key = $this->api_key();

		if ( '' === $key ) {
			return ADVTN_Fetch_Result::failure( __( 'No SerpAPI key is configured. Add it under Settings → Security.', 'trending-now' ) );
		}

		$query = trim( (string) ( $config['serp_query'] ?? '' ) );

		if ( '' === $query ) {
			return ADVTN_Fetch_Result::failure( __( 'A search query is required for SerpAPI sources.', 'trending-now' ) );
		}

		$domains = $this->clean_domains( (array) ( $config['serp_domains'] ?? array() ) );

		$endpoint = add_query_arg(
			array(
				'engine'  => 'google_news',
				'q'       => rawurlencode( $query ),
				'gl'      => $this->clean_locale( (string) ( $config['serp_country'] ?? 'us' ), 'us' ),
				'hl'      => $this->clean_locale( (string) ( $config['serp_language'] ?? 'en' ), 'en' ),
				// No `so` here: SerpAPI rejects it outright alongside `q`
				// ("`q` and `so` parameters can't be used together"), because
				// sort order only applies to topic and section browsing.
				// Ordering does not matter much anyway — the selector re-sorts
				// by published_at.
				'api_key' => rawurlencode( $key ),
			),
			self::ENDPOINT
		);

		$res    = $this->http_get( $endpoint );
		$result = new ADVTN_Fetch_Result();

		$result->duration_ms = $res['ms'];
		$result->http_code   = $res['code'];

		if ( is_wp_error( $res['response'] ) ) {
			$result->error = $this->redact( $res['response']->get_error_message(), $key );
			return $result;
		}

		$decoded = json_decode( $res['body'], true );

		if ( ! is_array( $decoded ) ) {
			/* translators: 1: HTTP status code, 2: start of the response body. */
			$result->error = sprintf(
				__( 'SerpAPI returned an unparseable response (HTTP %1$d): %2$s', 'trending-now' ),
				(int) $res['code'],
				$this->redact( mb_substr( trim( wp_strip_all_tags( $res['body'] ) ), 0, 200 ), $key )
			);
			return $result;
		}

		// SerpAPI reports failures in an `error` field, and does so on a 200 as
		// well as on 4xx — so the status alone is not enough to detect one.
		$reported = '';
		if ( isset( $decoded['error'] ) && is_string( $decoded['error'] ) ) {
			$reported = trim( $decoded['error'] );
		}

		if ( '' !== $reported ) {
			$result->error = $this->explain_error( $reported, (int) $res['code'] );

			ADVTN_Logger::log(
				'error',
				'SerpAPI returned an error.',
				array(
					'source_id' => (string) ( $config['id'] ?? '' ),
					'http_code' => $res['code'],
					'kind'      => self::classify_error( $reported ),
					'reported'  => $this->redact( $reported, $key ),
				)
			);

			// A key that is out of searches will keep being out of searches;
			// refresh the cached account snapshot so Diagnostics agrees.
			if ( 'credits' === self::classify_error( $reported ) ) {
				delete_transient( self::ACCOUNT_TRANSIENT );
			}

			return $result;
		}

		if ( 200 !== $res['code'] ) {
			/* translators: %d: HTTP status code. */
			$result->error = sprintf( __( 'Unexpected HTTP status %d from SerpAPI.', 'trending-now' ), (int) $res['code'] );
			return $result;
		}

		$news = isset( $decoded['news_results'] ) && is_array( $decoded['news_results'] ) ? $decoded['news_results'] : array();

		if ( empty( $news ) ) {
			// A valid search that simply matched nothing.
			$result->ok = true;
			return $result;
		}

		$limit    = max( 1, min( 100, (int) ( $config['limit'] ?? 20 ) ) );
		$items    = array();
		$rejected = 0;
		$seen     = 0;

		foreach ( $this->flatten( $news ) as $entry ) {
			++$seen;

			if ( count( $items ) >= $limit ) {
				break;
			}

			$url  = (string) ( $entry['link'] ?? '' );
			$host = ADVTN_URL::host( $url );

			// Optional allowlist, applied the same way as for GDELT: a news
			// aggregator returns whoever it likes unless you constrain it.
			if ( ! empty( $domains ) && ! $this->host_allowed( $host, $domains ) ) {
				++$rejected;
				continue;
			}

			$item = $this->make_item(
				array(
					'url'          => $url,
					'title'        => (string) ( $entry['title'] ?? '' ),
					'excerpt'      => (string) ( $entry['snippet'] ?? '' ),
					'image_url'    => (string) ( $entry['thumbnail'] ?? '' ),
					'published_at' => self::parse_date( (string) ( $entry['date'] ?? '' ) ),
					'site_name'    => $this->source_name( $entry, $host ),
					'source_type'  => 'serpapi',
				)
			);

			if ( null !== $item ) {
				$items[] = $item;
			}
		}

		if ( $rejected > 0 ) {
			ADVTN_Logger::log(
				'debug',
				'SerpAPI items dropped by the domain allowlist.',
				array(
					'source_id' => (string) ( $config['id'] ?? '' ),
					'rejected'  => $rejected,
				)
			);
		}

		// A successful search consumed a credit, so the cached balance is stale.
		delete_transient( self::ACCOUNT_TRANSIENT );

		$result->ok        = true;
		$result->items     = $items;
		$result->raw_count = $seen;

		return $result;
	}

	/**
	 * {@inheritDoc}
	 */
	public function validate_config( array $config ) {
		$clean = $this->base_config( $config );

		$query = trim( (string) ( $config['serp_query'] ?? '' ) );

		if ( '' === $query ) {
			return new WP_Error( 'advtn_missing_query', __( 'A search query is required for SerpAPI sources.', 'trending-now' ) );
		}

		$raw_domains = $config['serp_domains'] ?? array();
		if ( is_string( $raw_domains ) ) {
			$raw_domains = preg_split( '/[\s,]+/', $raw_domains ) ?: array();
		}

		$clean['limit']         = max( 1, min( 100, (int) ( $config['limit'] ?? 20 ) ) );
		$clean['serp_query']    = sanitize_text_field( $query );
		$clean['serp_domains']  = $this->clean_domains( (array) $raw_domains );
		$clean['serp_country']  = $this->clean_locale( (string) ( $config['serp_country'] ?? 'us' ), 'us' );
		$clean['serp_language'] = $this->clean_locale( (string) ( $config['serp_language'] ?? 'en' ), 'en' );
		$clean['url']           = '';

		if ( '' === $clean['label'] ) {
			$clean['label'] = __( 'Google News (SerpAPI)', 'trending-now' );
		}

		return $clean;
	}

	/* ---------------------------------------------------------------------
	 * Account / credits
	 * ------------------------------------------------------------------ */

	/**
	 * Account snapshot, including how many searches remain.
	 *
	 * Cached, because Diagnostics reads it on every view. The account endpoint
	 * does not itself consume a search, but it is still a network round trip.
	 *
	 * @param bool $force Bypass the cache.
	 * @return array<string,mixed>|WP_Error
	 */
	public function account( bool $force = false ) {
		$key = $this->api_key();

		if ( '' === $key ) {
			return new WP_Error( 'advtn_no_key', __( 'No SerpAPI key is configured.', 'trending-now' ) );
		}

		if ( ! $force ) {
			$cached = get_transient( self::ACCOUNT_TRANSIENT );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$res = $this->http_get( add_query_arg( array( 'api_key' => rawurlencode( $key ) ), self::ACCOUNT_ENDPOINT ) );

		if ( is_wp_error( $res['response'] ) ) {
			return new WP_Error( 'advtn_account_failed', $this->redact( $res['response']->get_error_message(), $key ) );
		}

		$decoded = json_decode( $res['body'], true );

		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'advtn_account_failed', __( 'SerpAPI returned an unparseable account response.', 'trending-now' ) );
		}

		if ( isset( $decoded['error'] ) && is_string( $decoded['error'] ) ) {
			return new WP_Error( 'advtn_account_error', $this->explain_error( trim( $decoded['error'] ), (int) $res['code'] ) );
		}

		$snapshot = array(
			'plan'             => (string) ( $decoded['plan_name'] ?? '' ),
			'searches_left'    => isset( $decoded['total_searches_left'] ) ? (int) $decoded['total_searches_left'] : null,
			'plan_left'        => isset( $decoded['plan_searches_left'] ) ? (int) $decoded['plan_searches_left'] : null,
			'this_month_usage' => isset( $decoded['this_month_usage'] ) ? (int) $decoded['this_month_usage'] : null,
			'per_month'        => isset( $decoded['searches_per_month'] ) ? (int) $decoded['searches_per_month'] : null,
			'checked_at'       => gmdate( 'Y-m-d H:i:s' ),
		);

		set_transient( self::ACCOUNT_TRANSIENT, $snapshot, self::ACCOUNT_TTL );

		return $snapshot;
	}

	/**
	 * Classify a SerpAPI error message.
	 *
	 * Kept static and free of WordPress so the mapping is unit-testable —
	 * running a real account out of credits to verify it is not an option.
	 *
	 * @param string $message Raw message from the API.
	 * @return string One of 'credits', 'rate_limit', 'auth', 'no_results', 'other'.
	 */
	public static function classify_error( string $message ): string {
		$m = strtolower( trim( $message ) );

		if ( '' === $m ) {
			return 'other';
		}

		$credits = array(
			'run out of searches',
			'ran out of searches',
			'out of searches',
			'no searches left',
			'out of credits',
			'insufficient credits',
			'exceeded your monthly',
			'monthly searches limit',
			'upgrade your plan',
		);

		foreach ( $credits as $needle ) {
			if ( false !== strpos( $m, $needle ) ) {
				return 'credits';
			}
		}

		if ( false !== strpos( $m, 'rate limit' ) || false !== strpos( $m, 'searches per hour' ) || false !== strpos( $m, 'too many requests' ) || false !== strpos( $m, 'slow down' ) ) {
			return 'rate_limit';
		}

		if ( false !== strpos( $m, 'invalid api key' ) || false !== strpos( $m, 'missing api key' ) || false !== strpos( $m, 'no api key' ) || false !== strpos( $m, 'unauthor' ) ) {
			return 'auth';
		}

		if ( false !== strpos( $m, "hasn't returned any results" ) || false !== strpos( $m, 'no results' ) ) {
			return 'no_results';
		}

		return 'other';
	}

	/**
	 * Parse SerpAPI's Google News date into the storage format.
	 *
	 * Observed as "08/06/2026, 07:00 AM, +0000 UTC"; relative and ISO forms
	 * are tolerated as a fallback.
	 *
	 * @param string $date Raw date string.
	 * @return string 'Y-m-d H:i:s' in UTC, or '' when unparseable.
	 */
	public static function parse_date( string $date ): string {
		$date = trim( $date );

		if ( '' === $date ) {
			return '';
		}

		// The trailing zone name duplicates the numeric offset and confuses
		// strict parsing.
		$normalized = trim( (string) preg_replace( '/\s+UTC$/i', '', $date ) );

		foreach ( array( 'm/d/Y, h:i A, O', 'm/d/Y, H:i, O', 'm/d/Y, h:i A', 'm/d/Y' ) as $format ) {
			$parsed = DateTimeImmutable::createFromFormat( $format, $normalized, new DateTimeZone( 'UTC' ) );

			if ( false !== $parsed ) {
				return $parsed->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
			}
		}

		$timestamp = strtotime( $normalized );

		return false === $timestamp ? '' : gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	/* ---------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------ */

	/**
	 * Turn a raw API message into something an operator can act on.
	 *
	 * @param string $reported Raw message from SerpAPI.
	 * @param int    $code     HTTP status.
	 * @return string
	 */
	private function explain_error( string $reported, int $code ): string {
		switch ( self::classify_error( $reported ) ) {
			case 'credits':
				return sprintf(
					/* translators: %s: the message SerpAPI returned. */
					__( 'SerpAPI credits exhausted — no searches left on this key. Top up or upgrade the plan at serpapi.com, or disable this source until the quota resets. SerpAPI said: %s', 'trending-now' ),
					$reported
				);

			case 'rate_limit':
				return sprintf(
					/* translators: %s: the message SerpAPI returned. */
					__( 'SerpAPI rate limit reached. This clears on its own; the source will retry after its backoff. SerpAPI said: %s', 'trending-now' ),
					$reported
				);

			case 'auth':
				return sprintf(
					/* translators: %s: the message SerpAPI returned. */
					__( 'SerpAPI rejected the API key. Check it under Settings → Security. SerpAPI said: %s', 'trending-now' ),
					$reported
				);

			case 'no_results':
				return sprintf(
					/* translators: %s: the message SerpAPI returned. */
					__( 'SerpAPI returned no results for this query. SerpAPI said: %s', 'trending-now' ),
					$reported
				);

			default:
				return sprintf(
					/* translators: 1: HTTP status code, 2: the message SerpAPI returned. */
					__( 'SerpAPI error (HTTP %1$d): %2$s', 'trending-now' ),
					$code,
					$reported
				);
		}
	}

	/**
	 * Flatten grouped results into a single list of story entries.
	 *
	 * Topic and section searches return `news_results` entries that wrap a
	 * `highlight` plus a `stories` array rather than being stories themselves.
	 *
	 * @param array<int,mixed> $news Raw news_results.
	 * @return array<int,array<string,mixed>>
	 */
	private function flatten( array $news ): array {
		$out = array();

		foreach ( $news as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$grouped = false;

			if ( isset( $entry['highlight'] ) && is_array( $entry['highlight'] ) ) {
				$out[]   = $entry['highlight'];
				$grouped = true;
			}

			if ( isset( $entry['stories'] ) && is_array( $entry['stories'] ) ) {
				foreach ( $entry['stories'] as $story ) {
					if ( is_array( $story ) ) {
						$out[] = $story;
					}
				}
				$grouped = true;
			}

			if ( ! $grouped ) {
				$out[] = $entry;
			}
		}

		return $out;
	}

	/**
	 * Publisher name for an entry.
	 *
	 * `source` is an object on Google News results but a plain string on some
	 * other engines, so both are accepted.
	 *
	 * @param array<string,mixed> $entry    Story entry.
	 * @param string              $fallback Host to fall back to.
	 * @return string
	 */
	private function source_name( array $entry, string $fallback ): string {
		$source = $entry['source'] ?? null;

		if ( is_array( $source ) && ! empty( $source['name'] ) ) {
			return (string) $source['name'];
		}

		if ( is_string( $source ) && '' !== trim( $source ) ) {
			return trim( $source );
		}

		return $fallback;
	}

	/**
	 * The configured key.
	 *
	 * @return string
	 */
	private function api_key(): string {
		return $this->settings->get_secret( 'serpapi_key' );
	}

	/**
	 * Remove the API key from a string before it is shown or logged.
	 *
	 * @param string $text Text that may embed the key.
	 * @param string $key  The key.
	 * @return string
	 */
	private function redact( string $text, string $key ): string {
		if ( '' === $key ) {
			return $text;
		}

		return str_replace( array( $key, rawurlencode( $key ) ), '[redacted]', $text );
	}

	/**
	 * Two-letter locale code, lowercased.
	 *
	 * @param string $value    Raw value.
	 * @param string $fallback Default when invalid.
	 * @return string
	 */
	private function clean_locale( string $value, string $fallback ): string {
		$value = strtolower( trim( $value ) );

		return preg_match( '/^[a-z]{2}(-[a-z]{2})?$/', $value ) ? $value : $fallback;
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

			if ( false !== strpos( $domain, '/' ) || false !== strpos( $domain, ':' ) ) {
				$domain = ADVTN_URL::host( $domain );
			}

			$domain = (string) preg_replace( '/^www\./', '', $domain );
			$domain = (string) preg_replace( '/[^a-z0-9.\-]/', '', $domain );

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
}
