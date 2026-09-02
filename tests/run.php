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

/* -------------------------------------------------------------------------
 * ADVTN_Manual_Feed_Parser::parse()
 *
 * The feed is served from a host whose unmatched paths answer 200 with a
 * single-page app's HTML. A client trusting the status code would record
 * success on every request while nothing arrived — on every subscribed site,
 * from one renamed function or one dropped rewrite. So validity is a property
 * of the body, never of the status.
 * ---------------------------------------------------------------------- */

$advtn_feed_ok = wp_json_encode(
	array(
		'feed'  => array( 'slug' => 'pbn-sample', 'version' => 6 ),
		'items' => array(
			array(
				'url'          => 'https://example.com/one',
				'title'        => 'One',
				'excerpt'      => 'First excerpt',
				'image_url'    => 'https://example.com/one.png',
				'site_name'    => 'Example',
				'published_at' => '2026-08-24 09:00:00',
				'expires_at'   => '2026-08-31 00:00:00',
				'position'     => 3,
			),
			array( 'url' => 'https://example.com/two', 'title' => 'Two' ),
		),
	)
);

$advtn_parsed = ADVTN_Manual_Feed_Parser::parse( $advtn_feed_ok );

advtn_assert_same( true, $advtn_parsed['ok'], 'feed parse: a well-formed payload succeeds' );
advtn_assert_same( 2, $advtn_parsed['count'], 'feed parse: both items are mapped' );
advtn_assert_same( 0, $advtn_parsed['skipped'], 'feed parse: nothing skipped in a clean payload' );
advtn_assert_same( '6', $advtn_parsed['version'], 'feed parse: the version is read as a string for ETag use' );
advtn_assert_same( 'https://example.com/one', $advtn_parsed['rows'][0]['url'], 'feed parse: url mapped' );
advtn_assert_same( 'One', $advtn_parsed['rows'][0]['title'], 'feed parse: title mapped' );
advtn_assert_same( 'First excerpt', $advtn_parsed['rows'][0]['excerpt'], 'feed parse: excerpt mapped' );
advtn_assert_same( 'https://example.com/one.png', $advtn_parsed['rows'][0]['image_url'], 'feed parse: image mapped' );
advtn_assert_same( 'Example', $advtn_parsed['rows'][0]['site_name'], 'feed parse: site name mapped' );
advtn_assert_same( '2026-08-24 09:00:00', $advtn_parsed['rows'][0]['published_at'], 'feed parse: published_at passed through for the validator to normalize' );
advtn_assert_same( '2026-08-31 00:00:00', $advtn_parsed['rows'][0]['expires_at'], 'feed parse: expires_at passed through' );
advtn_assert_same( 3, $advtn_parsed['rows'][0]['position'], 'feed parse: position is an int' );

// The feed has already dropped disabled rows, so anything that arrives is meant
// to show. A row landing disabled would be stored and never rendered, which
// reads as the feed being broken.
advtn_assert_same( true, $advtn_parsed['rows'][0]['enabled'], 'feed parse: rows arrive enabled' );

// Absent optional fields become empty strings and 0, not nulls: that is what
// ADVTN_Manual::validate() expects, and a null would arrive as the string
// 'null' and be parsed as a date.
advtn_assert_same( '', $advtn_parsed['rows'][1]['excerpt'], 'feed parse: a missing excerpt is an empty string' );
advtn_assert_same( '', $advtn_parsed['rows'][1]['image_url'], 'feed parse: a missing image is an empty string' );
advtn_assert_same( '', $advtn_parsed['rows'][1]['expires_at'], 'feed parse: a missing expiry is an empty string' );
advtn_assert_same( 0, $advtn_parsed['rows'][1]['position'], 'feed parse: a missing position falls where it may' );

// THE case this class exists for.
$advtn_spa = ADVTN_Manual_Feed_Parser::parse( '<!doctype html><html><head><title>Hawkeye</title></head><body><div id="root"></div></body></html>' );
advtn_assert_same( false, $advtn_spa['ok'], 'feed parse: an HTML body is a failure, not an empty feed' );
advtn_assert_same( ADVTN_Manual_Feed_Parser::CODE_NOT_JSON, $advtn_spa['code'], 'feed parse: an HTML body is reported as not-json' );
advtn_assert_same( array(), $advtn_spa['rows'], 'feed parse: a failure yields no rows' );

$advtn_no_feed = ADVTN_Manual_Feed_Parser::parse( '{"items":[]}' );
advtn_assert_same( false, $advtn_no_feed['ok'], 'feed parse: a body with no feed object is a failure' );
advtn_assert_same( ADVTN_Manual_Feed_Parser::CODE_SHAPE, $advtn_no_feed['code'], 'feed parse: a missing feed object is a shape error' );

$advtn_no_items = ADVTN_Manual_Feed_Parser::parse( '{"feed":{"version":1}}' );
advtn_assert_same( false, $advtn_no_items['ok'], 'feed parse: a body with no items array is a failure' );

/*
 * PHP cannot tell a JSON list from an object whose keys happen to be "0", "1",
 * … — json_decode with assoc=true produces the same array for both, and the
 * TypeScript on the other end can tell them apart where this cannot. It makes
 * no difference: such an object yields exactly the rows the list would, so it
 * is accepted rather than machinery being added to refuse something harmless.
 */
$advtn_items_numeric_object = ADVTN_Manual_Feed_Parser::parse( '{"feed":{"version":1},"items":{"0":{"url":"https://example.com/a","title":"A"}}}' );
advtn_assert_same( true, $advtn_items_numeric_object['ok'], 'feed parse: an object keyed 0,1,… is indistinguishable from a list here, and yields the same rows' );
advtn_assert_same( 1, $advtn_items_numeric_object['count'], 'feed parse: and it maps the same row' );

// A shape that is genuinely not a list is still refused, which is the case
// worth having.
$advtn_items_keyed = ADVTN_Manual_Feed_Parser::parse( '{"feed":{"version":1},"items":{"first":{"url":"https://example.com/a","title":"A"}}}' );
advtn_assert_same( false, $advtn_items_keyed['ok'], 'feed parse: an object with real keys is not an items list' );
advtn_assert_same( ADVTN_Manual_Feed_Parser::CODE_SHAPE, $advtn_items_keyed['code'], 'feed parse: and it is reported as a shape error' );

advtn_assert_same( false, ADVTN_Manual_Feed_Parser::parse( '"just a string"' )['ok'], 'feed parse: valid JSON that is not an object is a failure' );
advtn_assert_same( false, ADVTN_Manual_Feed_Parser::parse( '' )['ok'], 'feed parse: an empty body is a failure' );

// An empty list is a real answer, and the only way to clear the network
// deliberately. It must be distinguishable from every failure above.
$advtn_empty = ADVTN_Manual_Feed_Parser::parse( '{"feed":{"version":4},"items":[]}' );
advtn_assert_same( true, $advtn_empty['ok'], 'feed parse: a validated empty list succeeds' );
advtn_assert_same( 0, $advtn_empty['count'], 'feed parse: an empty list carries no rows' );
advtn_assert_same( '4', $advtn_empty['version'], 'feed parse: an empty list still carries its version' );

// One unusable row must not cost a site its whole list.
$advtn_partial = ADVTN_Manual_Feed_Parser::parse(
	wp_json_encode(
		array(
			'feed'  => array( 'version' => 2 ),
			'items' => array(
				array( 'url' => 'https://example.com/good', 'title' => 'Good' ),
				array( 'title' => 'No URL at all' ),
				'not even an array',
				array( 'url' => 'https://example.com/untitled' ),
			),
		)
	)
);

advtn_assert_same( true, $advtn_partial['ok'], 'feed parse: unusable rows do not fail the payload' );
advtn_assert_same( 1, $advtn_partial['count'], 'feed parse: only the usable row is mapped' );
advtn_assert_same( 3, $advtn_partial['skipped'], 'feed parse: unusable rows are counted rather than silently dropped' );
advtn_assert_same( array( 0 ), array_keys( $advtn_partial['rows'] ), 'feed parse: rows are re-indexed as a list' );

advtn_assert_same( '11', ADVTN_Manual_Feed_Parser::parse( '{"feed":{"version":"11"},"items":[]}' )['version'], 'feed parse: a string version is accepted as given' );
advtn_assert_same( '', ADVTN_Manual_Feed_Parser::parse( '{"feed":{},"items":[]}' )['version'], 'feed parse: an absent version means no ETag to send' );

/* -------------------------------------------------------------------------
 * A forced fetch asks unconditionally
 *
 * --force skips two gates, and only one of them is a convenience. Skipping the
 * interval saves a wait; skipping the stored ETag is what makes force able to
 * repair anything at all. A conditional request says "I already hold version
 * N", so a site whose list went missing would be told 304 and left exactly as
 * broken as it was — with the fetch reporting success.
 * ---------------------------------------------------------------------- */

$advtn_etag = new ReflectionMethod( 'ADVTN_Manual_Feed', 'conditional_etag' );

// No-op since 8.1 and deprecated in 8.5, but the plugin's floor is 7.4 and
// there the call is what makes the invoke() below legal.
if ( PHP_VERSION_ID < 80100 ) {
	$advtn_etag->setAccessible( true );
}

advtn_assert_same( '"v6"', $advtn_etag->invoke( null, '"v6"', false ), 'feed force: an ordinary fetch sends the stored ETag' );
advtn_assert_same( '', $advtn_etag->invoke( null, '"v6"', true ), 'feed force: a forced fetch sends no ETag, so 304 cannot refuse the repair' );
advtn_assert_same( '', $advtn_etag->invoke( null, '', false ), 'feed force: a first fetch has no ETag to send' );

/* -------------------------------------------------------------------------
 * Feed subscription settings
 * ---------------------------------------------------------------------- */

$advtn_feed_defaults = ADVTN_Settings::defaults();

advtn_assert_same( '', $advtn_feed_defaults['manual_feed_url'], 'feed settings: no feed URL by default' );
advtn_assert_same( '', $advtn_feed_defaults['manual_feed_token'], 'feed settings: no token by default' );
advtn_assert_same( 6, $advtn_feed_defaults['manual_feed_interval_hours'], 'feed settings: six hours by default' );
advtn_assert_same( false, $advtn_feed_defaults['manual_feed_enabled'], 'feed settings: subscription off by default' );

$advtn_feed_clean = ADVTN_Settings::sanitize(
	array(
		'manual_feed_url'            => '  https://hawkeye-advision.web.app/trending/feed?feed=pbn-sample  ',
		'manual_feed_token'          => "tok_ABC-123\n",
		'manual_feed_interval_hours' => '0',
		'manual_feed_enabled'        => '1',
	)
);

// The whole endpoint, query string included — not a base other paths hang off,
// which is why untrailingslashit() is wrong here and right for hub_url.
advtn_assert_same( 'https://hawkeye-advision.web.app/trending/feed?feed=pbn-sample', $advtn_feed_clean['manual_feed_url'], 'feed settings: the URL is trimmed and kept whole, query string included' );
advtn_assert_same( 'tok_ABC-123', $advtn_feed_clean['manual_feed_token'], 'feed settings: the token keeps its printable characters and loses whitespace' );
advtn_assert_same( 1, $advtn_feed_clean['manual_feed_interval_hours'], 'feed settings: the interval clamps up to one hour' );
advtn_assert_same( true, $advtn_feed_clean['manual_feed_enabled'], 'feed settings: the subscription toggle is a boolean' );

advtn_assert_same( 168, ADVTN_Settings::sanitize( array( 'manual_feed_interval_hours' => 5000 ) )['manual_feed_interval_hours'], 'feed settings: the interval clamps down to a week' );
advtn_assert_same( '', ADVTN_Settings::sanitize( array( 'manual_feed_url' => 'javascript:alert(1)' ) )['manual_feed_url'], 'feed settings: a non-http(s) URL is dropped' );
advtn_assert_same( 'https://hawkeye-advision.web.app/trending/feed/', ADVTN_Settings::sanitize( array( 'manual_feed_url' => 'https://hawkeye-advision.web.app/trending/feed/' ) )['manual_feed_url'], 'feed settings: the URL is left exactly as given, trailing slash included' );

// Both halves are required. A URL with the toggle off is a subscription somebody
// paused and wants back without retyping; a toggle with no URL is a half-filled
// form.
$GLOBALS['advtn_test_options'] = array(
	'advtn_settings' => array( 'manual_feed_url' => 'https://x.test/feed?feed=a', 'manual_feed_enabled' => true ),
);
advtn_assert_same( true, ( new ADVTN_Settings() )->feed_is_active(), 'feed settings: a URL plus the toggle means subscribed' );

$GLOBALS['advtn_test_options'] = array(
	'advtn_settings' => array( 'manual_feed_url' => '', 'manual_feed_enabled' => true ),
);
advtn_assert_same( false, ( new ADVTN_Settings() )->feed_is_active(), 'feed settings: the toggle alone is not a subscription' );

$GLOBALS['advtn_test_options'] = array(
	'advtn_settings' => array( 'manual_feed_url' => 'https://x.test/feed?feed=a', 'manual_feed_enabled' => false ),
);
advtn_assert_same( false, ( new ADVTN_Settings() )->feed_is_active(), 'feed settings: a paused subscription is not active' );

// The interval is baked into the scheduled action, so a change to it has to
// leave something behind for `init` to act on. Deferred through an option
// rather than done here, because these settings are equally reachable from
// WP-CLI and a migration, where the scheduler may not be loaded.
$GLOBALS['advtn_test_options'] = array();
( new ADVTN_Settings() )->update( array( 'manual_feed_interval_hours' => 12 ) );
advtn_assert_same( 1, get_option( 'advtn_reschedule_feed' ), 'feed settings: changing the interval leaves a reschedule flag' );

$GLOBALS['advtn_test_options'] = array();
( new ADVTN_Settings() )->update( array( 'manual_feed_enabled' => true ) );
advtn_assert_same( 1, get_option( 'advtn_reschedule_feed' ), 'feed settings: subscribing leaves a reschedule flag' );

// Nothing to reschedule when the cadence did not move.
$GLOBALS['advtn_test_options'] = array();
( new ADVTN_Settings() )->update( array( 'heading_text' => 'Something else' ) );
advtn_assert_same( false, get_option( 'advtn_reschedule_feed' ), 'feed settings: an unrelated change leaves no flag' );

$GLOBALS['advtn_test_options'] = array();

/* -------------------------------------------------------------------------
 * ADVTN_Updater::package_in()
 *
 * The pinning. Everything in a release response is remote text, including the
 * URL WordPress is about to download and unzip over the plugin directory.
 * ---------------------------------------------------------------------- */

$advtn_download = 'https://github.com/advision-development/trending-now-plugin/releases/download/';

/**
 * One release asset, as GitHub reports it.
 *
 * @param string $name Asset file name.
 * @param string $url  Browser download URL.
 * @param int    $id   Asset id.
 * @return array<string,mixed>
 */
function advtn_asset( string $name, string $url, int $id = 7 ): array {
	return array(
		'name'                 => $name,
		'browser_download_url' => $url,
		'id'                   => $id,
	);
}

$advtn_good_url = $advtn_download . 'v1.3.0/trending-now-1.3.0.zip';

advtn_assert_same(
	$advtn_good_url,
	ADVTN_Updater::package_in( array( advtn_asset( 'trending-now-1.3.0.zip', $advtn_good_url ) ) )['url'],
	'updater: the zip we publish is accepted'
);

// A URL's authority ends at the first slash after the scheme, so the pinned
// prefix cannot be satisfied by a host that merely starts with ours.
advtn_assert_same(
	'',
	ADVTN_Updater::package_in(
		array(
			advtn_asset(
				'trending-now-1.3.0.zip',
				'https://github.com.evil.test/advision-development/trending-now-plugin/releases/download/v1.3.0/trending-now-1.3.0.zip'
			),
		)
	)['url'],
	'updater: a lookalike host is refused'
);

// The scheme is inside the prefix, so this cannot be downgraded either.
advtn_assert_same(
	'',
	ADVTN_Updater::package_in(
		array( advtn_asset( 'trending-now-1.3.0.zip', 'http://github.com/advision-development/trending-now-plugin/releases/download/v1.3.0/trending-now-1.3.0.zip' ) )
	)['url'],
	'updater: plain http is refused'
);

// HTTP clients resolve `..` out of a path before sending it — RFC 3986's
// remove_dot_segments — so a prefix pins the host but not the repository.
// This starts with the prefix, and downloads from another account.
advtn_assert_same(
	'',
	ADVTN_Updater::package_in(
		array(
			advtn_asset(
				'trending-now-1.3.0.zip',
				$advtn_download . '../../../../someone/their-repo/releases/download/v1/trending-now-1.3.0.zip'
			),
		)
	)['url'],
	'updater: a dot-segment escape to another repository is refused'
);

// A release carrying several files must not have one of the others installed
// as the plugin.
advtn_assert_same(
	'',
	ADVTN_Updater::package_in(
		array( advtn_asset( 'something-else.zip', $advtn_download . 'v1.3.0/something-else.zip' ) )
	)['url'],
	'updater: an asset that is not our zip is refused'
);

advtn_assert_same(
	'',
	ADVTN_Updater::package_in(
		array( advtn_asset( 'trending-now-1.3.0.tar.gz', $advtn_download . 'v1.3.0/trending-now-1.3.0.tar.gz' ) )
	)['url'],
	'updater: an asset that is not a zip is refused'
);

advtn_assert_same(
	'',
	ADVTN_Updater::package_in( array() )['url'],
	'updater: a release with no assets offers nothing'
);

advtn_assert_same(
	false,
	ADVTN_Updater::package_in( array() )['is_asset'],
	'updater: nothing found is not an asset'
);

// A decoy first: the loop must keep going rather than settle for what it hit.
advtn_assert_same(
	$advtn_good_url,
	ADVTN_Updater::package_in(
		array(
			advtn_asset( 'readme.zip', $advtn_download . 'v1.3.0/readme.zip' ),
			advtn_asset( 'trending-now-1.3.0.zip', $advtn_good_url ),
		)
	)['url'],
	'updater: a decoy asset does not stop the search'
);

// With a token the asset goes through the API, because browser_download_url
// redirects to signed storage that rejects an Authorization header. The URL is
// built from a pinned prefix and an integer, so it is safe by construction.
advtn_assert_same(
	'https://api.github.com/repos/advision-development/trending-now-plugin/releases/assets/42',
	ADVTN_Updater::package_in(
		array( advtn_asset( 'trending-now-1.3.0.zip', $advtn_good_url, 42 ) ),
		'ghp_token'
	)['url'],
	'updater: with a token the asset is fetched through the API'
);

// A hostile response could name an asset with no usable id. Falling through to
// browser_download_url would send the site to storage that cannot serve a
// private repository, which reads as the token being wrong.
advtn_assert_same(
	'',
	ADVTN_Updater::package_in(
		array( advtn_asset( 'trending-now-1.3.0.zip', $advtn_good_url, 0 ) ),
		'ghp_token'
	)['url'],
	'updater: with a token an asset with no id is refused'
);

/* -------------------------------------------------------------------------
 * ADVTN_Updater version comparison
 * ---------------------------------------------------------------------- */

advtn_assert_same( '1.2.0', ADVTN_Updater::normalize( '1.2' ), 'updater: a version is padded to three components' );
advtn_assert_same( '', ADVTN_Updater::normalize( '1.2.0-beta' ), 'updater: a suffixed version is not a version' );

// version_compare( '1.2', '1.2.0' ) reports less-than, so an unpadded
// comparison against a two-component header clears a site that has an update
// waiting.
advtn_assert_same( false, ADVTN_Updater::is_newer( '1.2', '1.2.0' ), 'updater: 1.2 is not newer than 1.2.0' );
advtn_assert_same( false, ADVTN_Updater::is_newer( '1.2.0', '1.2' ), 'updater: 1.2.0 is not newer than 1.2' );
advtn_assert_same( true, ADVTN_Updater::is_newer( '1.3', '1.2.0' ), 'updater: 1.3 is newer than 1.2.0' );
advtn_assert_same( true, ADVTN_Updater::is_newer( '0.10', '0.9' ), 'updater: 0.10 is newer than 0.9' );
advtn_assert_same( false, ADVTN_Updater::is_newer( '1.2.0-rc1', '1.1.0' ), 'updater: a version this plugin does not publish is never newer' );
advtn_assert_same( false, ADVTN_Updater::is_newer( '', '1.2.0' ), 'updater: an empty version is never newer' );

advtn_assert_same( '1.3.0', ADVTN_Updater::version_of( 'v1.3.0' ), 'updater: a leading v is stripped' );
advtn_assert_same( '1.3.0', ADVTN_Updater::version_of( '1.3.0' ), 'updater: a bare version tag is kept' );
advtn_assert_same( '', ADVTN_Updater::version_of( 'main' ), 'updater: a tag naming a branch is not a version' );
advtn_assert_same( '', ADVTN_Updater::version_of( 'v1.2.0-rc1' ), 'updater: a pre-release tag is not a version' );

/* -------------------------------------------------------------------------
 * ADVTN_Manual_Feed::identity()
 *
 * What a site says about itself on a feed fetch. Two query parameters and no
 * new endpoint: the feed already fetches every few hours, and a feed that does
 * not know these parameters serves exactly what it served before.
 * ---------------------------------------------------------------------- */

advtn_assert_same(
	array( 'site' => 'https://mysite.example', 'v' => '1.2.0' ),
	ADVTN_Manual_Feed::identity( 'https://mysite.example', '1.2.0' ),
	'identity: sends the home URL and the version'
);

// A parameter present and empty is a claim that this site has no address.
// Absent is the truthful "this plugin did not say", and the far end treats a
// missing value as bookkeeping it simply does not do.
advtn_assert_same(
	array( 'v' => '1.2.0' ),
	ADVTN_Manual_Feed::identity( '', '1.2.0' ),
	'identity: omits an empty home rather than sending it blank'
);

advtn_assert_same(
	array( 'site' => 'https://mysite.example' ),
	ADVTN_Manual_Feed::identity( 'https://mysite.example', '' ),
	'identity: omits an empty version'
);

advtn_assert_same(
	array(),
	ADVTN_Manual_Feed::identity( '', '' ),
	'identity: says nothing when it has nothing to say'
);

advtn_assert_same(
	array( 'site' => 'https://mysite.example', 'v' => '1.2.0' ),
	ADVTN_Manual_Feed::identity( '  https://mysite.example  ', "\t1.2.0\n" ),
	'identity: trims both'
);

// A subdirectory install sends its whole home. The far end keeps only the
// origin, so the directory is lost there — recorded rather than worked around,
// because measured across the network on 2026-09-01 no install is in one.
advtn_assert_same(
	array( 'site' => 'https://mysite.example/blog', 'v' => '1.2.0' ),
	ADVTN_Manual_Feed::identity( 'https://mysite.example/blog', '1.2.0' ),
	'identity: sends a subdirectory home unchanged'
);

// home_url() is the only source. There is deliberately no setting for this: a
// typed field is a field filled in wrong, and the far end turns this value into
// an address it will later contact.
advtn_assert_same(
	array( 'site' => rtrim( ADVTN_TEST_HOME, '/' ), 'v' => '9.9.9' ),
	ADVTN_Manual_Feed::identity( rtrim( home_url(), '/' ), '9.9.9' ),
	'identity: what home_url() gives is what is sent'
);

/* ---------------------------------------------------------------------- */

/* -------------------------------------------------------------------------
 * ADVTN_Sync_Key
 *
 * The credential Hawkeye presents to say "fetch now". A leaked one buys an
 * attacker one thing: making a site re-read the feed it re-reads every six
 * hours anyway. That is the whole reason it is not `ingest_secret`, which also
 * authorizes /ingest and /status.
 * ---------------------------------------------------------------------- */

$advtn_key_a = ADVTN_Sync_Key::generate();
$advtn_key_b = ADVTN_Sync_Key::generate();

advtn_assert_same( 64, strlen( $advtn_key_a ), 'sync key: 64 hex characters' );
advtn_assert_same( 1, preg_match( '/^[0-9a-f]{64}$/', $advtn_key_a ), 'sync key: lowercase hex only' );
advtn_assert_same( false, $advtn_key_a === $advtn_key_b, 'sync key: two generated keys differ' );

advtn_assert_same( true, ADVTN_Sync_Key::is_wellformed( $advtn_key_a ), 'wellformed: a generated key' );
advtn_assert_same( false, ADVTN_Sync_Key::is_wellformed( '' ), 'wellformed: empty is not a key' );
advtn_assert_same( false, ADVTN_Sync_Key::is_wellformed( str_repeat( 'a', 63 ) ), 'wellformed: too short' );
advtn_assert_same( false, ADVTN_Sync_Key::is_wellformed( str_repeat( 'a', 65 ) ), 'wellformed: too long' );
advtn_assert_same( false, ADVTN_Sync_Key::is_wellformed( str_repeat( 'A', 64 ) ), 'wellformed: uppercase is not the stored form' );
advtn_assert_same( false, ADVTN_Sync_Key::is_wellformed( str_repeat( 'z', 64 ) ), 'wellformed: non-hex characters' );

advtn_assert_same( true, ADVTN_Sync_Key::matches( $advtn_key_a, $advtn_key_a, '' ), 'matches: the current key' );

// The previous key is accepted so a deliberate regeneration does not blind
// Hawkeye until that site's next fetch, up to six hours. There is no automatic
// rotation; this exists for the operator who regenerated because they suspected
// a leak, and who is the last person who should be told to wait.
advtn_assert_same( true, ADVTN_Sync_Key::matches( $advtn_key_b, $advtn_key_a, $advtn_key_b ), 'matches: the previous key' );

advtn_assert_same( false, ADVTN_Sync_Key::matches( ADVTN_Sync_Key::generate(), $advtn_key_a, $advtn_key_b ), 'matches: an unrelated key' );

// Empty is the state of a site that has never fetched. Treating it as a match
// would make every such site answerable by anybody who found the route.
advtn_assert_same( false, ADVTN_Sync_Key::matches( '', '', '' ), 'matches: nothing presented against nothing stored' );
advtn_assert_same( false, ADVTN_Sync_Key::matches( '', $advtn_key_a, '' ), 'matches: nothing presented' );
advtn_assert_same( false, ADVTN_Sync_Key::matches( $advtn_key_a, '', '' ), 'matches: nothing stored' );
advtn_assert_same( false, ADVTN_Sync_Key::matches( $advtn_key_a, '', $advtn_key_a ), 'matches: a previous key with no current one is not accepted' );

// A prefix is not a match — but read what holds that. It is `is_wellformed()`'s
// {64} quantifier, which returns before any comparison is reached, NOT
// hash_equals(). The comment here used to credit hash_equals, and the plan
// claimed a mutation test on it; both were wrong. `0 === strpos( $current,
// $presented )` passes every assertion in this block, and so does `===`.
advtn_assert_same( false, ADVTN_Sync_Key::matches( substr( $advtn_key_a, 0, 32 ), $advtn_key_a, '' ), 'matches: a prefix of the key' );
advtn_assert_same( false, ADVTN_Sync_Key::matches( strtoupper( $advtn_key_a ), $advtn_key_a, '' ), 'matches: the key in a different case' );

// The one candidate that does reach hash_equals: full length, well formed, and
// differing only in the last character. It exercises the comparison rather than
// the length guard. It does NOT hold the constant-time property — no assertion
// can, which is why the docblock says that property is held by review.
advtn_assert_same(
	false,
	ADVTN_Sync_Key::matches( substr( $advtn_key_a, 0, 63 ) . ( 'f' === substr( $advtn_key_a, -1 ) ? '0' : 'f' ), $advtn_key_a, '' ),
	'matches: a full-length key differing in one character'
);

// The previous key is compared unconditionally. With no previous key stored
// there is nothing to match, and `hash_equals( '', $presented )` says so
// without a `&&` that would skip the call — the state of every site that has
// never pressed Replace.
advtn_assert_same( true, ADVTN_Sync_Key::matches( $advtn_key_a, $advtn_key_a, '' ), 'matches: the current key with no previous key stored' );
advtn_assert_same( false, ADVTN_Sync_Key::matches( $advtn_key_b, $advtn_key_a, '' ), 'matches: an unrelated key with no previous key stored' );
advtn_assert_same( false, ADVTN_Sync_Key::matches( $advtn_key_a, $advtn_key_b, 'not a key' ), 'matches: a malformed previous key is not a match' );

/* -------------------------------------------------------------------------
 * The sync key survives a settings save
 *
 * sanitize() starts from defaults() and assigns only the keys it knows. A key
 * it does not handle is reset on every save — so this is not a nicety, it is
 * the difference between a credential and a credential that works until
 * somebody presses Save Settings.
 * ---------------------------------------------------------------------- */

$GLOBALS['advtn_test_options'] = array();

$advtn_stored_key = ADVTN_Sync_Key::generate();
$advtn_old_key    = ADVTN_Sync_Key::generate();

advtn_assert_same( '', ADVTN_Settings::defaults()['sync_key'], 'settings: a fresh install has no sync key' );
advtn_assert_same( '', ADVTN_Settings::defaults()['sync_key_previous'], 'settings: a fresh install has no previous key' );

$advtn_clean = ADVTN_Settings::sanitize(
	array(
		'sync_key'          => $advtn_stored_key,
		'sync_key_previous' => $advtn_old_key,
	)
);

advtn_assert_same( $advtn_stored_key, $advtn_clean['sync_key'], 'sanitize: a well-formed key is kept' );
advtn_assert_same( $advtn_old_key, $advtn_clean['sync_key_previous'], 'sanitize: a well-formed previous key is kept' );

// The value is never typed by a person, so anything that is not the stored
// shape is a value that arrived by a route nobody designed. Dropped rather
// than repaired.
advtn_assert_same( '', ADVTN_Settings::sanitize( array( 'sync_key' => 'not-a-key' ) )['sync_key'], 'sanitize: a malformed key is dropped, not repaired' );
advtn_assert_same( '', ADVTN_Settings::sanitize( array( 'sync_key' => strtoupper( $advtn_stored_key ) ) )['sync_key'], 'sanitize: an uppercase key is not the stored form' );
advtn_assert_same( '', ADVTN_Settings::sanitize( array() )['sync_key'], 'sanitize: absent means empty' );

// The regression this task exists for. Saving an unrelated field must not
// clear the credential.
$advtn_settings = new ADVTN_Settings();
$advtn_settings->update( array( 'sync_key' => $advtn_stored_key ) );
$advtn_settings->update( array( 'heading_text' => 'Something Else' ) );

advtn_assert_same(
	$advtn_stored_key,
	$advtn_settings->get_string( 'sync_key' ),
	'settings: saving an unrelated field leaves the sync key alone'
);
advtn_assert_same( 'Something Else', $advtn_settings->get_string( 'heading_text' ), 'settings: the unrelated field did change' );

$GLOBALS['advtn_test_options'] = array();

/* -------------------------------------------------------------------------
 * identity() gains a trigger
 *
 * A claim the site makes about itself, which this project normally refuses to
 * record — admitted here because it is a label on a log row rather than an
 * authorization, and there is no other way to know from the log side why a
 * fetch happened. The job document is the authoritative record of "we pushed";
 * where the two disagree, the job document is right.
 * ---------------------------------------------------------------------- */

advtn_assert_same(
	array( 'site' => 'https://mysite.test', 'v' => '1.4.0' ),
	ADVTN_Manual_Feed::identity( 'https://mysite.test', '1.4.0' ),
	'identity: no trigger means no trigger parameter'
);

advtn_assert_same(
	array( 'site' => 'https://mysite.test', 'v' => '1.4.0', 'trigger' => 'sync' ),
	ADVTN_Manual_Feed::identity( 'https://mysite.test', '1.4.0', 'sync' ),
	'identity: a trigger is appended after site and v'
);

advtn_assert_same(
	array( 'site' => 'https://mysite.test', 'v' => '1.4.0' ),
	ADVTN_Manual_Feed::identity( 'https://mysite.test', '1.4.0', '   ' ),
	'identity: a whitespace trigger is nothing, not a blank parameter'
);

// A closed vocabulary, and refused rather than sanitised. The value reaches a
// log column on the far end; anything this side cannot name is a value nobody
// chose.
advtn_assert_same(
	array( 'site' => 'https://mysite.test', 'v' => '1.4.0' ),
	ADVTN_Manual_Feed::identity( 'https://mysite.test', '1.4.0', 'something-else' ),
	'identity: an unknown trigger is dropped, not passed through'
);

advtn_assert_same(
	array( 'site' => 'https://mysite.test', 'v' => '1.4.0', 'trigger' => 'manual' ),
	ADVTN_Manual_Feed::identity( 'https://mysite.test', '1.4.0', 'manual' ),
	'identity: manual is a known trigger'
);

// The existing contract, restated so a change to the signature cannot lose it.
advtn_assert_same(
	array(),
	ADVTN_Manual_Feed::identity( '', '', 'sync' ),
	'identity: a trigger alone says nothing about which site this is'
);

/* -------------------------------------------------------------------------
 * The parser reads which feed answered
 *
 * Hawkeye compares this against the feed it pushed for. A site that moved from
 * feed A to feed B is still in A's roster until something prunes it, and
 * pushing it would record `ok` for a site that did not refresh A — a false
 * success, which is the exact shape of failure this project has already paid
 * for once.
 * ---------------------------------------------------------------------- */

$advtn_feed_body = wp_json_encode(
	array(
		'feed'  => array( 'slug' => 'pbn-sample', 'name' => 'PBN Sample', 'version' => 41 ),
		'items' => array(
			array( 'url' => 'https://example.com/a', 'title' => 'A' ),
		),
	)
);

$advtn_parsed = ADVTN_Manual_Feed_Parser::parse( (string) $advtn_feed_body );

advtn_assert_same( true, $advtn_parsed['ok'], 'parse: a well-formed payload' );
advtn_assert_same( 'pbn-sample', $advtn_parsed['slug'], 'parse: the slug is read out of the feed object' );
advtn_assert_same( '41', $advtn_parsed['version'], 'parse: the version is still read' );

// Absent rather than guessed. A feed serving no slug is one this plugin cannot
// compare, and inventing one from the request would make every comparison
// agree with itself.
$advtn_no_slug = ADVTN_Manual_Feed_Parser::parse(
	(string) wp_json_encode( array( 'feed' => array( 'version' => 7 ), 'items' => array() ) )
);

advtn_assert_same( '', $advtn_no_slug['slug'], 'parse: a payload with no slug reports none' );
advtn_assert_same( true, $advtn_no_slug['ok'], 'parse: a missing slug is not a parse failure' );

// A non-scalar slug is not a slug. An array here would become "Array" under a
// string cast and compare equal to nothing, which is right by accident rather
// than on purpose.
$advtn_odd_slug = ADVTN_Manual_Feed_Parser::parse(
	(string) wp_json_encode( array( 'feed' => array( 'slug' => array( 'x' ) ), 'items' => array() ) )
);

advtn_assert_same( '', $advtn_odd_slug['slug'], 'parse: a non-scalar slug reports none' );

// The failure shape carries the key too, so every caller can read it without
// checking which branch it came from.
$advtn_broken = ADVTN_Manual_Feed_Parser::parse( 'not json at all' );

advtn_assert_same( false, $advtn_broken['ok'], 'parse: garbage is not a feed' );
advtn_assert_same( '', $advtn_broken['slug'], 'parse: a failure still carries a slug key' );

/* -------------------------------------------------------------------------
 * ADVTN_Manual_Feed::retry_needed()
 *
 * FEED_CACHE_MS on the far end is 60 seconds, per instance, with no
 * invalidation available — the callable that saves runs in a different
 * instance from the one serving, so nothing in the writer's process reaches
 * the reader's memory. The TTL is the invalidation.
 *
 * So a push fired right after a save hands this site the previous version and
 * its ETag, and the button reports success having changed nothing. The retry
 * is what closes that window.
 * ---------------------------------------------------------------------- */

advtn_assert_same( true, ADVTN_Manual_Feed::retry_needed( 'pbn-sample', 'pbn-sample', '40', '41' ), 'retry: the slug matches and the version arrived older' );
advtn_assert_same( false, ADVTN_Manual_Feed::retry_needed( 'pbn-sample', 'pbn-sample', '41', '41' ), 'retry: the expected version arrived' );
advtn_assert_same( false, ADVTN_Manual_Feed::retry_needed( 'pbn-sample', 'pbn-sample', '42', '41' ), 'retry: a newer version than expected is not a reason to fetch again' );

// A stale roster row must not trigger a retry against another feed's version
// numbers. Two feeds' version counters are unrelated integers, so comparing
// them is comparing nothing.
advtn_assert_same( false, ADVTN_Manual_Feed::retry_needed( 'other-feed', 'pbn-sample', '3', '41' ), 'retry: the slug differs, so the version is not compared' );

// No expectation is not an expectation of zero.
advtn_assert_same( false, ADVTN_Manual_Feed::retry_needed( 'pbn-sample', 'pbn-sample', '40', '' ), 'retry: no expected version means nothing to be behind' );
advtn_assert_same( false, ADVTN_Manual_Feed::retry_needed( 'pbn-sample', '', '40', '41' ), 'retry: no expected feed means no comparison to make' );
advtn_assert_same( false, ADVTN_Manual_Feed::retry_needed( '', 'pbn-sample', '40', '41' ), 'retry: a feed that answered no slug cannot be compared' );

// Versions are integers on the wire. A non-numeric value is not a version, and
// treating it as 0 would make every push retry.
advtn_assert_same( false, ADVTN_Manual_Feed::retry_needed( 'pbn-sample', 'pbn-sample', 'abc', '41' ), 'retry: a non-numeric answered version is not behind' );
advtn_assert_same( false, ADVTN_Manual_Feed::retry_needed( 'pbn-sample', 'pbn-sample', '40', 'abc' ), 'retry: a non-numeric expectation is no expectation' );

// String comparison would say '9' > '41'. This has to be numeric.
advtn_assert_same( true, ADVTN_Manual_Feed::retry_needed( 'pbn-sample', 'pbn-sample', '9', '41' ), 'retry: versions compare as numbers, not as strings' );

/* -------------------------------------------------------------------------
 * Regenerating the sync key
 *
 * Not automatic and not on a schedule. The worst an attacker does with a
 * captured key is make one site re-read its own feed, which it does every six
 * hours anyway — so automatic rotation buys almost nothing and costs a real
 * fragility: the far end's roster write is best-effort, so any dropped write
 * would leave it holding a key the site no longer accepts.
 * ---------------------------------------------------------------------- */

$GLOBALS['advtn_test_options'] = array();

$advtn_feed_settings = new ADVTN_Settings();
$advtn_first_key     = ADVTN_Sync_Key::generate();
$advtn_feed_settings->update( array( 'sync_key' => $advtn_first_key ) );

$advtn_feed = new ADVTN_Manual_Feed( $advtn_feed_settings, new ADVTN_Manual( $advtn_feed_settings, new ADVTN_Repository() ) );

advtn_assert_same( true, $advtn_feed->regenerate_sync_key(), 'regenerate: reports success' );

$advtn_second_key = $advtn_feed_settings->get_string( 'sync_key' );

advtn_assert_same( false, $advtn_second_key === $advtn_first_key, 'regenerate: the current key changed' );
advtn_assert_same( true, ADVTN_Sync_Key::is_wellformed( $advtn_second_key ), 'regenerate: the new key is well formed' );

// The old one is kept so the far end, which learns keys only by being called,
// is not blind until this site's next fetch — up to six hours, and the person
// who just regenerated because they suspected a leak is the last one who
// should be told to wait.
advtn_assert_same( $advtn_first_key, $advtn_feed_settings->get_string( 'sync_key_previous' ), 'regenerate: the old key becomes the previous one' );
advtn_assert_same( true, ADVTN_Sync_Key::matches( $advtn_first_key, $advtn_second_key, $advtn_feed_settings->get_string( 'sync_key_previous' ) ), 'regenerate: the old key still matches' );

// Two values, never three. A second regeneration drops the oldest.
$advtn_feed->regenerate_sync_key();

advtn_assert_same( $advtn_second_key, $advtn_feed_settings->get_string( 'sync_key_previous' ), 'regenerate: the second regeneration drops the oldest key' );
advtn_assert_same(
	false,
	ADVTN_Sync_Key::matches( $advtn_first_key, $advtn_feed_settings->get_string( 'sync_key' ), $advtn_feed_settings->get_string( 'sync_key_previous' ) ),
	'regenerate: the key from two regenerations ago no longer matches'
);

$GLOBALS['advtn_test_options'] = array();

/* -------------------------------------------------------------------------
 * A redirect on the feed request is refused, not followed
 *
 * The request carries the shared feed token and this site's sync key, and
 * WordPress re-sends the whole header set on every hop including a cross-host
 * one. `redirection => 0` is what stops that; this is the classification that
 * turns the resulting 3xx into a message naming the real problem.
 * ---------------------------------------------------------------------- */

foreach ( array( 300, 301, 302, 303, 307, 308, 399 ) as $advtn_redirect_code ) {
	advtn_assert_same( true, ADVTN_Manual_Feed::is_redirect( $advtn_redirect_code ), 'redirect: HTTP ' . $advtn_redirect_code . ' is a refused redirect' );
}

// 304 is in the 3xx range and is NOT a redirect: it is the feed saying the
// version already held is current, and it is a success. Classifying it here
// would turn every unmodified scheduled fetch into a reported configuration
// error — on every subscribed site, four times a day.
advtn_assert_same( false, ADVTN_Manual_Feed::is_redirect( 304 ), 'redirect: 304 is not a redirect, it is an unmodified feed' );

advtn_assert_same( false, ADVTN_Manual_Feed::is_redirect( 200 ), 'redirect: 200 is not a redirect' );
advtn_assert_same( false, ADVTN_Manual_Feed::is_redirect( 299 ), 'redirect: 299 is below the range' );
advtn_assert_same( false, ADVTN_Manual_Feed::is_redirect( 400 ), 'redirect: 400 is above the range' );
advtn_assert_same( false, ADVTN_Manual_Feed::is_redirect( 401 ), 'redirect: a gated feed is not a redirect' );
advtn_assert_same( false, ADVTN_Manual_Feed::is_redirect( 500 ), 'redirect: a server error is not a redirect' );

// A transport error leaves no code at all. It has its own failure path and must
// not be reported as a redirect.
advtn_assert_same( false, ADVTN_Manual_Feed::is_redirect( null ), 'redirect: no status code is not a redirect' );

/* -------------------------------------------------------------------------
 * The log redacts every credential this plugin holds
 *
 * The scrub list is matched as substrings, and `sync_key` matched none of the
 * original six — `api_key` was there, plain `key` was not. Nothing logged it at
 * the time, which is exactly the shape of trap this covers: the guard has to
 * hold before somebody adds the diagnostic, not after.
 * ---------------------------------------------------------------------- */

$GLOBALS['advtn_test_options'] = array();

ADVTN_Logger::clear();
ADVTN_Logger::log(
	'info',
	'settings saved',
	array(
		'ingest_secret'      => 'secret-value',
		'manual_feed_token'  => 'token-value',
		'serpapi_key'        => 'serpapi-value',
		'sync_key'           => 'sync-value',
		'sync_key_previous'  => 'previous-value',
		'signature'          => 'signature-value',
		'password'           => 'password-value',
		'apikey'             => 'apikey-value',
		'manual_feed_url'    => 'https://feed.example/pbn-sample',
	)
);

$advtn_log_context = ADVTN_Logger::entries()[0]['context'];

foreach (
	array( 'ingest_secret', 'manual_feed_token', 'serpapi_key', 'sync_key', 'sync_key_previous', 'signature', 'password', 'apikey' )
	as $advtn_cred
) {
	advtn_assert_same( '[redacted]', $advtn_log_context[ $advtn_cred ], 'log scrub: ' . $advtn_cred . ' is redacted' );
}

// Not everything is a credential. Redacting the feed URL would take away the
// one field an operator needs to see to diagnose a wrong slug — the failure
// mode the feed answers 401 for and cannot name.
advtn_assert_same( 'https://feed.example/pbn-sample', $advtn_log_context['manual_feed_url'], 'log scrub: an ordinary setting is left alone' );

ADVTN_Logger::clear();
$GLOBALS['advtn_test_options'] = array();

/* -------------------------------------------------------------------------
 * Throttling the log line for a refused push
 *
 * /sync is internet-facing and can be refused 30 times per five minutes. The
 * HMAC routes log every refusal; mirroring that here would let an
 * unauthenticated caller evict the whole 200-row log ring, which would destroy
 * the record the line was added to provide. One line per burst instead, with
 * the magnitude kept in a counter that cannot be flooded.
 * ---------------------------------------------------------------------- */

$advtn_burst_now = strtotime( '2026-09-01 12:00:00 UTC' );

advtn_assert_same( true, ADVTN_Manual_Feed::refusal_is_new_burst( '', $advtn_burst_now, HOUR_IN_SECONDS ), 'refusal burst: the first refusal ever is logged' );
advtn_assert_same( true, ADVTN_Manual_Feed::refusal_is_new_burst( 'not a date', $advtn_burst_now, HOUR_IN_SECONDS ), 'refusal burst: an unreadable timestamp is logged' );
advtn_assert_same( false, ADVTN_Manual_Feed::refusal_is_new_burst( '2026-09-01 11:59:00', $advtn_burst_now, HOUR_IN_SECONDS ), 'refusal burst: a refusal a minute after the last is not logged again' );
advtn_assert_same( true, ADVTN_Manual_Feed::refusal_is_new_burst( '2026-09-01 11:00:00', $advtn_burst_now, HOUR_IN_SECONDS ), 'refusal burst: exactly one interval later opens a new burst' );
advtn_assert_same( true, ADVTN_Manual_Feed::refusal_is_new_burst( '2026-09-01 09:00:00', $advtn_burst_now, HOUR_IN_SECONDS ), 'refusal burst: long after the last opens a new burst' );

// The stored timestamp is UTC, like every other datetime in this plugin. Read
// as local time on a host west of UTC it would look like the future, and
// `$now - $last` would go negative — a refusal that never opens a burst and so
// never logs at all.
advtn_assert_same( false, ADVTN_Manual_Feed::refusal_is_new_burst( '2026-09-01 12:00:00', $advtn_burst_now, HOUR_IN_SECONDS ), 'refusal burst: a refusal in the same second is not a new burst' );

/* -------------------------------------------------------------------------
 * Whether a commit changed the served list
 *
 * This is the decision that stops a fetch which changed nothing from calling
 * build_and_commit(), which stamps times_shown and sets the write-once
 * first_shown_at. The stamp is SQL and this harness never reaches $wpdb, so
 * the decision is extracted and the decision is what is asserted on.
 * ---------------------------------------------------------------------- */

$advtn_served_row = static function ( array $overrides = array() ): array {
	return array_merge(
		array(
			'id'           => 'man_aaaaaa',
			'url'          => 'https://example.com/a',
			'title'        => 'A',
			'excerpt'      => '',
			'image_url'    => '',
			'site_name'    => '',
			'published_at' => '2026-09-01 00:00:00',
			'expires_at'   => '',
			'position'     => 0,
			'enabled'      => true,
			'created_at'   => '2026-09-01 00:00:00',
		),
		$overrides
	);
};

$advtn_served_base = array( $advtn_served_row() );

advtn_assert_same( false, ADVTN_Manual::served_list_changed( array(), array() ), 'served list: two empty lists are unchanged' );
advtn_assert_same( true, ADVTN_Manual::served_list_changed( array(), $advtn_served_base ), 'served list: the first link is a change' );
advtn_assert_same( true, ADVTN_Manual::served_list_changed( $advtn_served_base, array() ), 'served list: clearing the list is a change' );
advtn_assert_same( false, ADVTN_Manual::served_list_changed( $advtn_served_base, $advtn_served_base ), 'served list: the same list twice is unchanged' );

// THE ONE THAT MAKES THE GUARD REAL. ADVTN_Manual_Feed_Parser::map_row() emits
// no id and no created_at, so validate() mints both from uniqid()/gmdate() on
// every commit. Two commits of a byte-identical feed therefore differ in those
// two fields in every row. A guard that compared whole rows would never fire.
advtn_assert_same(
	false,
	ADVTN_Manual::served_list_changed(
		$advtn_served_base,
		array( $advtn_served_row( array( 'id' => 'man_zzzzzz', 'created_at' => '2026-09-02 11:22:33' ) ) )
	),
	'served list: a re-minted id and created_at are not a change'
);

// Everything the feed can actually say is a change, because every one of these
// alters what a visitor is served: the six sync() writes into the items table,
// position and order decide placement, expires_at and enabled decide whether
// the row is served at all.
foreach (
	array(
		'url'          => 'https://example.com/b',
		'title'        => 'B',
		'excerpt'      => 'new excerpt',
		'image_url'    => 'https://example.com/i.png',
		'site_name'    => 'Example',
		'published_at' => '2026-09-02 00:00:00',
		'expires_at'   => '2026-09-09 00:00:00',
		'position'     => 3,
	) as $advtn_field => $advtn_value
) {
	advtn_assert_same(
		true,
		ADVTN_Manual::served_list_changed( $advtn_served_base, array( $advtn_served_row( array( $advtn_field => $advtn_value ) ) ) ),
		'served list: ' . $advtn_field . ' moving is a change'
	);
}

advtn_assert_same(
	true,
	ADVTN_Manual::served_list_changed( $advtn_served_base, array( $advtn_served_row( array( 'enabled' => false ) ) ) ),
	'served list: enabled moving is a change'
);

// Order is part of the served list: ADVTN_Selector splices positioned rows into
// the order it was given, so the same two links the other way round is a
// different list.
$advtn_served_two = array(
	$advtn_served_row(),
	$advtn_served_row( array( 'url' => 'https://example.com/b', 'title' => 'B' ) ),
);

advtn_assert_same(
	true,
	ADVTN_Manual::served_list_changed( $advtn_served_two, array_reverse( $advtn_served_two ) ),
	'served list: the same links reordered is a change'
);
advtn_assert_same( true, ADVTN_Manual::served_list_changed( $advtn_served_base, $advtn_served_two ), 'served list: an added link is a change' );

// A list read back out of an option has been through PHP's serializer, which
// does not promise int/bool round-trip against rows validate() just built. The
// cast in comparable() is what stops '0' vs 0 reading as a change and firing a
// rebuild on every single fetch.
advtn_assert_same(
	false,
	ADVTN_Manual::served_list_changed(
		$advtn_served_base,
		array( $advtn_served_row( array( 'position' => '0', 'enabled' => 1 ) ) )
	),
	'served list: a loosely-typed position and enabled are not a change'
);

// A list that lost its middle row keeps the outer keys 0 and 2. Key numbering
// is not part of what is served.
$advtn_served_gappy = array(
	0 => $advtn_served_row(),
	2 => $advtn_served_row( array( 'url' => 'https://example.com/b', 'title' => 'B' ) ),
);

advtn_assert_same(
	false,
	ADVTN_Manual::served_list_changed( $advtn_served_two, $advtn_served_gappy ),
	'served list: non-sequential keys are not a change'
);

$GLOBALS['advtn_test_options'] = array();

/* -------------------------------------------------------------------------
 * Which clock asked for this fetch
 *
 * Four callers, and until now three of them said nothing. The cron is the one
 * that matters: "the last fetch was a push" and "the last fetch was the timer"
 * are the two answers somebody debugging a link that did not appear actually
 * needs, and an empty trigger could mean either.
 * ---------------------------------------------------------------------- */

advtn_assert_same(
	array( 'site' => 'https://mysite.test', 'v' => '1.4.0', 'trigger' => 'cron' ),
	ADVTN_Manual_Feed::identity( 'https://mysite.test', '1.4.0', 'cron' ),
	'identity: cron is a known trigger'
);

advtn_assert_same(
	array( 'site' => 'https://mysite.test', 'v' => '1.4.0', 'trigger' => 'rest' ),
	ADVTN_Manual_Feed::identity( 'https://mysite.test', '1.4.0', 'rest' ),
	'identity: rest is a known trigger'
);

// The vocabulary stays closed. The value lands in a log column on the far end,
// so a name this side cannot produce is a value nobody chose.
advtn_assert_same(
	array( 'site' => 'https://mysite.test', 'v' => '1.4.0' ),
	ADVTN_Manual_Feed::identity( 'https://mysite.test', '1.4.0', 'cronjob' ),
	'identity: a near-miss trigger is dropped, not corrected'
);

/*
 * The words a person reads. Never the token: printing `cron` prints the
 * mechanism, and the whole point of this field is that somebody who is not
 * reading the source can tell a push from a timer.
 */
advtn_assert_same( 'a push from the feed', ADVTN_Manual_Feed::trigger_words( 'sync' ), 'words: sync' );
advtn_assert_same( 'the six-hourly timer', ADVTN_Manual_Feed::trigger_words( 'cron' ), 'words: cron' );
advtn_assert_same( 'somebody pressing Fetch now', ADVTN_Manual_Feed::trigger_words( 'manual' ), 'words: manual' );
advtn_assert_same( 'a signed request to this site', ADVTN_Manual_Feed::trigger_words( 'rest' ), 'words: rest' );

// Absent is not a value. Every fetch made before this shipped says nothing, and
// on a six-hour timer those are most of a first day — so this reads as "did not
// say" rather than as a guess, for the same reason the Sync tab stopped
// blaming old plugins for three different things in 2f22ad5.
advtn_assert_same( 'not recorded', ADVTN_Manual_Feed::trigger_words( '' ), 'words: absent says it did not say' );
advtn_assert_same( 'not recorded', ADVTN_Manual_Feed::trigger_words( 'whatever' ), 'words: an unknown token is not invented into a cause' );

/* ---------------------------------------------------------------------- */

printf( "\n%d passed, %d failed\n", $advtn_passed, $advtn_failed );

exit( $advtn_failed > 0 ? 1 : 0 );
