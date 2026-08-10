<?php
/**
 * Dependency-free test runner: `php tests/run.php`.
 *
 * Covers the pure logic that dedupe correctness rests on. Anything that needs
 * $wpdb belongs in a WP integration suite, not here.
 *
 * @package Advision\TrendingNow
 */

declare( strict_types=1 );

require_once __DIR__ . '/bootstrap.php';

$advtn_passed = 0;
$advtn_failed = 0;

/**
 * Assert two values match.
 *
 * @param mixed  $expected Expected value.
 * @param mixed  $actual   Actual value.
 * @param string $label    Test name.
 * @return void
 */
function advtn_assert_same( $expected, $actual, string $label ): void {
	global $advtn_passed, $advtn_failed;

	if ( $expected === $actual ) {
		++$advtn_passed;
		return;
	}

	++$advtn_failed;
	printf(
		"FAIL  %s\n        expected: %s\n        actual:   %s\n",
		$label,
		var_export( $expected, true ),
		var_export( $actual, true )
	);
}

/* -------------------------------------------------------------------------
 * ADVTN_URL::normalize()
 * ---------------------------------------------------------------------- */

$advtn_normalize_cases = array(
	array( 'https://example.com/article/', 'https://example.com/article', 'strips a trailing slash' ),
	array( 'https://example.com/', 'https://example.com/', 'keeps a bare root path' ),
	array( 'https://example.com', 'https://example.com/', 'supplies a root path' ),
	array( 'http://example.com/a', 'https://example.com/a', 'forces https for hashing' ),
	array( 'https://WWW.Example.COM/A', 'https://example.com/A', 'lowercases host, keeps path case' ),
	array( 'https://example.com/a#section', 'https://example.com/a', 'drops the fragment' ),
	array( 'https://example.com/a?utm_source=x&utm_medium=y', 'https://example.com/a', 'drops utm_*' ),
	array( 'https://example.com/a?fbclid=1&gclid=2&msclkid=3', 'https://example.com/a', 'drops click ids' ),
	array( 'https://example.com/a?oly_enc_id=9', 'https://example.com/a', 'drops oly_*' ),
	array( 'https://example.com/a?b=2&a=1', 'https://example.com/a?a=1&b=2', 'sorts query params' ),
	array( 'https://example.com/a?a=1&b=2', 'https://example.com/a?a=1&b=2', 'stable under existing order' ),
	array( 'https://example.com/a?id=7&utm_campaign=z', 'https://example.com/a?id=7', 'keeps meaningful params' ),
	array( '  https://example.com/a  ', 'https://example.com/a', 'trims surrounding whitespace' ),
	array( 'ftp://example.com/a', '', 'rejects non-http schemes' ),
	array( 'not a url', '', 'rejects garbage' ),
	array( '', '', 'rejects an empty string' ),
);

foreach ( $advtn_normalize_cases as $advtn_case ) {
	advtn_assert_same( $advtn_case[1], ADVTN_URL::normalize( $advtn_case[0] ), 'normalize: ' . $advtn_case[2] );
}

/* -------------------------------------------------------------------------
 * Hash identity — the acceptance criterion for dedupe
 * ---------------------------------------------------------------------- */

advtn_assert_same(
	ADVTN_URL::hash( 'https://example.com/story/' ),
	ADVTN_URL::hash( 'http://www.example.com/story?utm_source=newsletter&fbclid=abc' ),
	'hash: trailing slash + www + tracking params collapse to one identity'
);

advtn_assert_same(
	true,
	ADVTN_URL::hash( 'https://example.com/a' ) !== ADVTN_URL::hash( 'https://example.com/b' ),
	'hash: distinct paths stay distinct'
);

advtn_assert_same( 40, strlen( ADVTN_URL::hash( 'https://example.com/a' ) ), 'hash: sha1 length' );
advtn_assert_same( '', ADVTN_URL::hash( 'javascript:alert(1)' ), 'hash: unusable URL yields an empty hash' );

/* -------------------------------------------------------------------------
 * ADVTN_URL::host()
 * ---------------------------------------------------------------------- */

advtn_assert_same( 'example.com', ADVTN_URL::host( 'https://www.example.com/a/b' ), 'host: strips www' );
advtn_assert_same( 'news.example.com', ADVTN_URL::host( 'https://news.example.com/' ), 'host: keeps other subdomains' );
advtn_assert_same( 'example.com', ADVTN_URL::host( 'HTTP://EXAMPLE.COM' ), 'host: lowercases' );
advtn_assert_same( '', ADVTN_URL::host( 'nonsense' ), 'host: empty on failure' );
advtn_assert_same( 'mysite.example', ADVTN_URL::local_host(), 'host: local host resolves from home_url()' );

/* -------------------------------------------------------------------------
 * ADVTN_URL::is_valid()
 * ---------------------------------------------------------------------- */

advtn_assert_same( true, ADVTN_URL::is_valid( 'https://example.com/a' ), 'is_valid: https passes' );
advtn_assert_same( true, ADVTN_URL::is_valid( 'http://example.com/a' ), 'is_valid: http passes' );
advtn_assert_same( false, ADVTN_URL::is_valid( 'ftp://example.com/a' ), 'is_valid: ftp rejected' );
advtn_assert_same( false, ADVTN_URL::is_valid( 'javascript:alert(1)' ), 'is_valid: javascript rejected' );
advtn_assert_same( false, ADVTN_URL::is_valid( 'http://127.0.0.1/a' ), 'is_valid: loopback rejected' );
advtn_assert_same( false, ADVTN_URL::is_valid( 'http://192.168.1.10/a' ), 'is_valid: private range rejected' );
advtn_assert_same( false, ADVTN_URL::is_valid( '' ), 'is_valid: empty rejected' );

/* -------------------------------------------------------------------------
 * ADVTN_HMAC::sign()
 * ---------------------------------------------------------------------- */

$advtn_expected = hash_hmac( 'sha256', "1780000000\n" . '{"force":true}', 'secret' );

advtn_assert_same(
	$advtn_expected,
	ADVTN_HMAC::sign( 1780000000, '{"force":true}', 'secret' ),
	'hmac: message is timestamp + LF + raw body'
);

advtn_assert_same(
	hash_hmac( 'sha256', "1780000000\n", 'secret' ),
	ADVTN_HMAC::sign( 1780000000, '', 'secret' ),
	'hmac: GET signs an empty body'
);

advtn_assert_same(
	true,
	ADVTN_HMAC::sign( 1780000000, '', 'a' ) !== ADVTN_HMAC::sign( 1780000000, '', 'b' ),
	'hmac: different secrets diverge'
);

/* -------------------------------------------------------------------------
 * SerpAPI error classification
 *
 * The credit-exhaustion branch is the one thing that cannot be rehearsed
 * against a live key, so the mapping is pinned here instead.
 * ---------------------------------------------------------------------- */

$advtn_error_cases = array(
	array( 'Your account has run out of searches.', 'credits' ),
	array( 'Your account ran out of searches', 'credits' ),
	array( 'You have no searches left this month.', 'credits' ),
	array( 'Account is out of credits', 'credits' ),
	array( 'You have exceeded your monthly searches limit. Please upgrade your plan.', 'credits' ),
	array( "You've exceeded your searches per hour rate limit.", 'rate_limit' ),
	array( 'Too many requests, please slow down.', 'rate_limit' ),
	array( 'Invalid API key. Your API key should be here: https://serpapi.com/manage-api-key', 'auth' ),
	array( 'Missing API key', 'auth' ),
	array( "Google hasn't returned any results for this query.", 'no_results' ),
	array( 'Something else entirely went wrong', 'other' ),
	array( '', 'other' ),
);

foreach ( $advtn_error_cases as $advtn_case ) {
	advtn_assert_same(
		$advtn_case[1],
		ADVTN_Source_SerpAPI::classify_error( $advtn_case[0] ),
		'serpapi classify: ' . ( '' === $advtn_case[0] ? '(empty)' : mb_substr( $advtn_case[0], 0, 44 ) )
	);
}

// Credit exhaustion must never be mistaken for a transient rate limit: one
// needs a human to top up, the other clears itself.
advtn_assert_same(
	true,
	'credits' !== ADVTN_Source_SerpAPI::classify_error( "You've exceeded your searches per hour rate limit." ),
	'serpapi classify: hourly rate limit is not credit exhaustion'
);

/* -------------------------------------------------------------------------
 * SerpAPI date parsing
 * ---------------------------------------------------------------------- */

$advtn_date_cases = array(
	array( '08/06/2026, 07:00 AM, +0000 UTC', '2026-08-06 07:00:00', 'Google News format' ),
	array( '08/06/2026, 07:00 PM, +0000 UTC', '2026-08-06 19:00:00', 'afternoon' ),
	array( '12/31/2025, 11:59 PM, +0000 UTC', '2025-12-31 23:59:00', 'year boundary' ),
	array( '2026-08-06T07:00:00Z', '2026-08-06 07:00:00', 'ISO 8601 fallback' ),
	array( '', '', 'empty' ),
	array( 'not a date at all', '', 'garbage' ),
);

foreach ( $advtn_date_cases as $advtn_case ) {
	advtn_assert_same( $advtn_case[1], ADVTN_Source_SerpAPI::parse_date( $advtn_case[0] ), 'serpapi date: ' . $advtn_case[2] );
}

/* -------------------------------------------------------------------------
 * News source types
 * ---------------------------------------------------------------------- */

advtn_assert_same( true, ADVTN_Source_Base::is_news_type( 'gdelt' ), 'news types: gdelt' );
advtn_assert_same( true, ADVTN_Source_Base::is_news_type( 'serpapi' ), 'news types: serpapi' );
advtn_assert_same( false, ADVTN_Source_Base::is_news_type( 'wp_rest' ), 'news types: wp_rest is network' );
advtn_assert_same( false, ADVTN_Source_Base::is_news_type( 'rss' ), 'news types: rss is network' );

/* -------------------------------------------------------------------------
 * Curated link placement
 *
 * Position handling is pure list surgery, so it is worth pinning without a
 * database: 1-based, clamped, and 0 meaning "no opinion".
 * ---------------------------------------------------------------------- */

/**
 * Mirror of ADVTN_Selector::place_manual() for the pure ordering rules.
 *
 * @param array<int,string>    $auto   Automatic titles in order.
 * @param array<int,array{0:string,1:int}> $manual Title and position pairs.
 * @param int                  $limit  Widget limit.
 * @return array<int,string>
 */
function advtn_place( array $auto, array $manual, int $limit ): array {
	$out = array();

	foreach ( $manual as $entry ) {
		if ( 0 === $entry[1] ) {
			$out[] = $entry[0];
		}
	}

	$out = array_merge( $out, $auto );

	foreach ( $manual as $entry ) {
		if ( $entry[1] < 1 ) {
			continue;
		}
		array_splice( $out, min( $entry[1] - 1, count( $out ) ), 0, array( $entry[0] ) );
	}

	return array_slice( $out, 0, $limit );
}

$advtn_auto = array( 'a', 'b', 'c', 'd', 'e' );

advtn_assert_same(
	array( 'M', 'a', 'b', 'c', 'd' ),
	advtn_place( $advtn_auto, array( array( 'M', 1 ) ), 5 ),
	'placement: position 1 goes first'
);

advtn_assert_same(
	array( 'a', 'b', 'M', 'c', 'd' ),
	advtn_place( $advtn_auto, array( array( 'M', 3 ) ), 5 ),
	'placement: position 3 lands third'
);

advtn_assert_same(
	array( 'M1', 'a', 'M3', 'b', 'c' ),
	advtn_place( $advtn_auto, array( array( 'M1', 1 ), array( 'M3', 3 ) ), 5 ),
	'placement: two positions hold simultaneously'
);

advtn_assert_same(
	array( 'M', 'a', 'b', 'c', 'd' ),
	advtn_place( $advtn_auto, array( array( 'M', 0 ) ), 5 ),
	'placement: position 0 joins the front of the automatic run'
);

advtn_assert_same(
	'M',
	advtn_place( $advtn_auto, array( array( 'M', 99 ) ), 6 )[5],
	'placement: an out-of-range slot clamps to the end rather than dropping'
);

advtn_assert_same(
	3,
	count( advtn_place( $advtn_auto, array( array( 'M', 1 ) ), 3 ) ),
	'placement: never exceeds the widget limit'
);

/* ---------------------------------------------------------------------- */
/* Per-source timeout override                                             */
/* ---------------------------------------------------------------------- */

/**
 * Build a provider instance against a chosen global http_timeout.
 *
 * @param int $global Global http_timeout value.
 * @return ADVTN_Source_SerpAPI
 */
function advtn_provider_with_timeout( int $global ): ADVTN_Source_SerpAPI {
	$GLOBALS['advtn_test_options']['advtn_settings'] = array( 'http_timeout' => $global );

	return new ADVTN_Source_SerpAPI( new ADVTN_Settings() );
}

$advtn_p = advtn_provider_with_timeout( 5 );

advtn_assert_same( 30, $advtn_p->config_timeout( array( 'timeout' => 30 ) ), 'timeout: an override wins over the global' );
advtn_assert_same( 5, $advtn_p->config_timeout( array( 'timeout' => 0 ) ), 'timeout: zero inherits the global' );
advtn_assert_same( 5, $advtn_p->config_timeout( array() ), 'timeout: an absent key inherits the global' );
advtn_assert_same( 5, $advtn_p->config_timeout( array( 'timeout' => '' ) ), 'timeout: an empty string inherits the global' );
advtn_assert_same( 120, $advtn_p->config_timeout( array( 'timeout' => 9999 ) ), 'timeout: an override clamps to the 120s ceiling' );
advtn_assert_same( 1, $advtn_p->config_timeout( array( 'timeout' => 1 ) ), 'timeout: one second is allowed' );
advtn_assert_same( 5, $advtn_p->config_timeout( array( 'timeout' => -8 ) ), 'timeout: a negative override inherits rather than clamping to 1' );

$advtn_p20 = advtn_provider_with_timeout( 20 );
advtn_assert_same( 20, $advtn_p20->config_timeout( array() ), 'timeout: the global is read per instance, not cached across them' );

/* ---------------------------------------------------------------------- */
/* Attempt ring                                                            */
/* ---------------------------------------------------------------------- */

$advtn_ring = array();
for ( $advtn_i = 1; $advtn_i <= 25; $advtn_i++ ) {
	$advtn_ring = ADVTN_Attempts::record( $advtn_ring, true, $advtn_i * 100, 200, '' );
}

advtn_assert_same( 20, count( $advtn_ring ), 'attempts: the ring caps at 20 entries' );
advtn_assert_same( 600, $advtn_ring[0]['ms'], 'attempts: the oldest entries are dropped first' );
advtn_assert_same( 2500, $advtn_ring[19]['ms'], 'attempts: the newest entry is last' );
advtn_assert_same( true, $advtn_ring[19]['ok'], 'attempts: a success records ok true' );
advtn_assert_same( '', $advtn_ring[19]['err'], 'attempts: a success records an empty error' );
advtn_assert_same( 200, $advtn_ring[19]['code'], 'attempts: the http code is kept' );
advtn_assert_same( true, isset( $advtn_ring[19]['t'] ) && 19 === strlen( (string) $advtn_ring[19]['t'] ), 'attempts: the timestamp is a UTC datetime string' );

$advtn_long  = str_repeat( 'x', 400 );
$advtn_fail  = ADVTN_Attempts::record( array(), false, 5006, null, $advtn_long );

advtn_assert_same( false, $advtn_fail[0]['ok'], 'attempts: a failure records ok false' );
advtn_assert_same( 5006, $advtn_fail[0]['ms'], 'attempts: a failure keeps its elapsed time' );
advtn_assert_same( null, $advtn_fail[0]['code'], 'attempts: a null http code survives' );
advtn_assert_same( 120, strlen( $advtn_fail[0]['err'] ), 'attempts: a long error truncates at write time' );

advtn_assert_same(
	0,
	ADVTN_Attempts::record( array(), true, -5, 200, '' )[0]['ms'],
	'attempts: a negative elapsed time clamps to zero'
);

advtn_assert_same(
	array( 'count' => 0, 'p50' => 0, 'max' => 0 ),
	ADVTN_Attempts::summary( array() ),
	'attempts: an empty ring summarises to zeroes'
);

$advtn_odd = array();
foreach ( array( 100, 300, 200 ) as $advtn_ms ) {
	$advtn_odd = ADVTN_Attempts::record( $advtn_odd, true, $advtn_ms, 200, '' );
}
advtn_assert_same(
	array( 'count' => 3, 'p50' => 200, 'max' => 300 ),
	ADVTN_Attempts::summary( $advtn_odd ),
	'attempts: an odd count takes the middle value'
);

$advtn_even = array();
foreach ( array( 100, 400, 200, 300 ) as $advtn_ms ) {
	$advtn_even = ADVTN_Attempts::record( $advtn_even, true, $advtn_ms, 200, '' );
}
advtn_assert_same(
	array( 'count' => 4, 'p50' => 250, 'max' => 400 ),
	ADVTN_Attempts::summary( $advtn_even ),
	'attempts: an even count averages the two middle values'
);

$advtn_one = ADVTN_Attempts::record( array(), true, 2010, 200, '' );
advtn_assert_same(
	array( 'count' => 1, 'p50' => 2010, 'max' => 2010 ),
	ADVTN_Attempts::summary( $advtn_one ),
	'attempts: a single entry is its own median and max'
);

advtn_assert_same(
	20,
	count( ADVTN_Attempts::record( array_fill( 0, 400, array( 'ms' => 1 ) ), true, 50, 200, '' ) ),
	'attempts: an oversized stored ring is trimmed on the next write'
);

/* ---------------------------------------------------------------------- */
/* Maximum age cutoff                                                      */
/* ---------------------------------------------------------------------- */

/**
 * Build a settings instance against a chosen max_age_hours.
 *
 * @param mixed $hours Raw setting value.
 * @return ADVTN_Settings
 */
function advtn_settings_with_max_age( $hours ): ADVTN_Settings {
	$GLOBALS['advtn_test_options']['advtn_settings'] = array( 'max_age_hours' => $hours );

	return new ADVTN_Settings();
}

advtn_assert_same(
	'',
	advtn_settings_with_max_age( 0 )->max_age_cutoff(),
	'max age: 0 disables the cutoff entirely'
);

advtn_assert_same(
	gmdate( 'Y-m-d H:i:s', time() - ( 72 * HOUR_IN_SECONDS ) ),
	advtn_settings_with_max_age( 72 )->max_age_cutoff(),
	'max age: 72 hours resolves to a cutoff three days back'
);

advtn_assert_same(
	gmdate( 'Y-m-d H:i:s', time() - ( 720 * HOUR_IN_SECONDS ) ),
	advtn_settings_with_max_age( 9999 )->max_age_cutoff(),
	'max age: an over-range value clamps to the 720-hour ceiling'
);

advtn_assert_same(
	'',
	advtn_settings_with_max_age( -5 )->max_age_cutoff(),
	'max age: a negative value clamps to 0 and disables the cutoff'
);

// The shipped defaults have to leave the exposure floor room to finish: the
// floor counts from first_shown_at and the cutoff from published_at, so an
// equal pair is cut short by any ingest lag at all.
$advtn_defaults = ADVTN_Settings::sanitize( array() );

advtn_assert_same( 72, $advtn_defaults['max_age_hours'], 'defaults: the cutoff ships at 72 hours' );
advtn_assert_same( 2, $advtn_defaults['exposure_floor_days'], 'defaults: the exposure floor ships at 2 days' );
advtn_assert_same(
	true,
	( $advtn_defaults['exposure_floor_days'] * 24 ) < $advtn_defaults['max_age_hours'],
	'defaults: the exposure floor leaves slack under the cutoff'
);

/* ---------------------------------------------------------------------- */
/* Path matching                                                           */
/* ---------------------------------------------------------------------- */

advtn_assert_same( '/', ADVTN_Path_Match::normalize( '/' ), 'path normalize: root stays root' );
advtn_assert_same( '/', ADVTN_Path_Match::normalize( '//' ), 'path normalize: repeated slashes reduce to root' );
advtn_assert_same( '', ADVTN_Path_Match::normalize( '' ), 'path normalize: empty stays empty, it is NOT the root' );
advtn_assert_same( '', ADVTN_Path_Match::normalize( '   ' ), 'path normalize: whitespace-only is empty' );
advtn_assert_same( '/archive', ADVTN_Path_Match::normalize( '/archive' ), 'path normalize: a plain path is unchanged' );
advtn_assert_same( '/archive', ADVTN_Path_Match::normalize( '/archive/' ), 'path normalize: a trailing slash is dropped' );
advtn_assert_same( '/archive', ADVTN_Path_Match::normalize( 'archive' ), 'path normalize: a leading slash is added' );
advtn_assert_same( '/archive', ADVTN_Path_Match::normalize( ' /archive/ ' ), 'path normalize: surrounding whitespace is trimmed' );
advtn_assert_same( '/archive', ADVTN_Path_Match::normalize( '/Archive' ), 'path normalize: lowercased' );
advtn_assert_same( '/', ADVTN_Path_Match::normalize( '/?utm_source=x' ), 'path normalize: the query string is not part of the path' );
advtn_assert_same( '/archive', ADVTN_Path_Match::normalize( '/archive?page=2' ), 'path normalize: query dropped from a deeper path' );
advtn_assert_same( '/archive', ADVTN_Path_Match::normalize( '/archive#top' ), 'path normalize: the fragment is dropped' );
advtn_assert_same( '/my page', ADVTN_Path_Match::normalize( '/my%20page' ), 'path normalize: percent-encoding is decoded' );
advtn_assert_same( '/a/b', ADVTN_Path_Match::normalize( '/a//b/' ), 'path normalize: interior repeated slashes collapse' );

// Whitespace the decode introduces is trimmed, or the return contract is a lie:
// a trailing one stops rtrim() and keeps the trailing slash it promises to drop.
advtn_assert_same( '/archive', ADVTN_Path_Match::normalize( '/archive%20' ), 'path normalize: an encoded trailing space is trimmed after decoding' );
advtn_assert_same( '/archive', ADVTN_Path_Match::normalize( '%20/archive' ), 'path normalize: an encoded leading space is trimmed after decoding' );
advtn_assert_same( '/archive', ADVTN_Path_Match::normalize( '/archive/%20' ), 'path normalize: an encoded space cannot preserve a trailing slash' );

// A pasted full URL is the likeliest way to write an entry that never matches.
advtn_assert_same( '/archive', ADVTN_Path_Match::normalize( 'https://example.com/archive' ), 'path normalize: a full https URL keeps only its path' );
advtn_assert_same( '/archive', ADVTN_Path_Match::normalize( 'http://example.com/archive/' ), 'path normalize: a full http URL keeps only its path' );
advtn_assert_same( '/', ADVTN_Path_Match::normalize( 'https://example.com' ), 'path normalize: a URL with no path is the root' );
advtn_assert_same( '/a/b', ADVTN_Path_Match::normalize( 'https://example.com/a/b?x=1' ), 'path normalize: a URL keeps its path and drops its query' );

// Protocol-relative input is left alone on purpose: telling a host from a
// doubled slash means guessing, and a doubled slash is the likelier typo here.
advtn_assert_same( '/example.com/archive', ADVTN_Path_Match::normalize( '//example.com/archive' ), 'path normalize: protocol-relative input is treated as a path, not a URL' );

advtn_assert_same( '/archive', ADVTN_Path_Match::path_from( '/archive', '/' ), 'path from: a root install passes the path through' );
advtn_assert_same( '/archive', ADVTN_Path_Match::path_from( '/archive/', '' ), 'path from: an empty base is treated as root' );
advtn_assert_same( '/', ADVTN_Path_Match::path_from( '/', '/' ), 'path from: the root of a root install is the root' );
advtn_assert_same( '/', ADVTN_Path_Match::path_from( '/blog/', '/blog' ), 'path from: the root of a subdirectory install is the root' );
advtn_assert_same( '/', ADVTN_Path_Match::path_from( '/blog', '/blog/' ), 'path from: base and request agree regardless of trailing slashes' );
advtn_assert_same( '/archive', ADVTN_Path_Match::path_from( '/blog/archive', '/blog' ), 'path from: the base is stripped from a deeper path' );
advtn_assert_same( '/archive/page/2', ADVTN_Path_Match::path_from( '/blog/archive/page/2/', '/blog' ), 'path from: the base is stripped from a deep path' );
advtn_assert_same( '/blogging', ADVTN_Path_Match::path_from( '/blogging', '/blog' ), 'path from: a base is only stripped at a segment boundary' );
advtn_assert_same( '', ADVTN_Path_Match::path_from( '', '/blog' ), 'path from: no request path yields no path' );

advtn_assert_same( true, ADVTN_Path_Match::matches_path( '/anything', '' ), 'path matches: an empty list gates nothing' );
advtn_assert_same( true, ADVTN_Path_Match::matches_path( '/anything', '  ' ), 'path matches: a whitespace list gates nothing' );
advtn_assert_same( true, ADVTN_Path_Match::matches_path( '/anything', ',,' ), 'path matches: a list of separators gates nothing' );
advtn_assert_same( true, ADVTN_Path_Match::matches_path( '/', '/' ), 'path matches: the root matches a root entry' );
advtn_assert_same( true, ADVTN_Path_Match::matches_path( '/archive', '/,/archive' ), 'path matches: a hit on the second entry' );
advtn_assert_same( true, ADVTN_Path_Match::matches_path( '/archive', '/ , /archive/ ' ), 'path matches: entries are normalized before comparing' );
advtn_assert_same( false, ADVTN_Path_Match::matches_path( '/news', '/,/archive' ), 'path matches: an unlisted path does not match' );
advtn_assert_same( false, ADVTN_Path_Match::matches_path( '/archive', '/' ), 'path matches: the root entry does not match a deeper path' );
advtn_assert_same( false, ADVTN_Path_Match::matches_path( '/archive/page/2', '/,/archive' ), 'path matches: matching is exact, not prefix' );
advtn_assert_same( false, ADVTN_Path_Match::matches_path( '/archive-2024', '/,/archive' ), 'path matches: a path that merely starts the same does not match' );
advtn_assert_same( false, ADVTN_Path_Match::matches_path( '', '/' ), 'path matches: an unknown current path fails a non-empty list' );
advtn_assert_same( true, ADVTN_Path_Match::matches_path( '', '' ), 'path matches: an unknown current path still passes an empty list' );

// $current is normalized here rather than trusted, so a raw path compares.
advtn_assert_same( true, ADVTN_Path_Match::matches_path( '/Archive/', '/archive' ), 'path matches: an un-normalized current path is normalized before comparing' );
advtn_assert_same( false, ADVTN_Path_Match::matches_path( '   ', '/' ), 'path matches: a blank current path stays fail-closed after normalizing' );

// current_path() is the one method that reads request state.
$advtn_saved_uri = $_SERVER['REQUEST_URI'] ?? null;

$_SERVER['REQUEST_URI'] = '/archive/?utm_source=x';
advtn_assert_same( '/archive', ADVTN_Path_Match::current_path(), 'current path: read from REQUEST_URI, query dropped' );

$_SERVER['REQUEST_URI'] = '/';
advtn_assert_same( '/', ADVTN_Path_Match::current_path(), 'current path: the homepage is the root' );

unset( $_SERVER['REQUEST_URI'] );
advtn_assert_same( '', ADVTN_Path_Match::current_path(), 'current path: absent REQUEST_URI yields no path' );
advtn_assert_same( false, ADVTN_Path_Match::matches( '/' ), 'current path: with no request path, a list fails closed' );
advtn_assert_same( true, ADVTN_Path_Match::matches( '' ), 'current path: with no request path, an empty list still passes' );

if ( null === $advtn_saved_uri ) {
	unset( $_SERVER['REQUEST_URI'] );
} else {
	$_SERVER['REQUEST_URI'] = $advtn_saved_uri;
}

/* ---------------------------------------------------------------------- */

printf( "\n%d passed, %d failed\n", $advtn_passed, $advtn_failed );

exit( $advtn_failed > 0 ? 1 : 0 );
