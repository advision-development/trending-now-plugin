<?php
/**
 * Self-update from GitHub releases.
 *
 * Hooks the `update_plugins_{$hostname}` filter that WordPress 5.8+ derives
 * from the plugin's `Update URI` header. That header also stops wordpress.org
 * from ever answering for this slug, which is the failure mode that makes
 * naive GitHub updaters overwrite a plugin with an unrelated one.
 *
 * **This is the most dangerous class in the plugin, by a distance.** Everything
 * else reads or renders. This one hands WordPress a URL and WordPress downloads
 * it, unzips it over the plugin directory and runs it on the next request. A
 * wrong answer here is arbitrary PHP on ~160 sites. So:
 *
 * - **The download URL is checked against a pinned host, owner and repository**
 *   rather than taken from the response. The response is JSON from a remote
 *   server; if it is tampered with, or the repository moves, the answer is to
 *   install nothing rather than to install from wherever the JSON points.
 * - **There is no zipball fallback.** GitHub's generated archive carries the
 *   development tree with no `vendor/`, so Action Scheduler would be absent and
 *   the plugin would degrade to WP-Cron silently. No recognised asset means no
 *   update offered.
 * - **TLS verification is never relaxed.** A plugin that would rather install
 *   something than nothing is a delivery mechanism.
 * - **A version is only ever offered upwards**, with both sides padded to three
 *   components first — `version_compare( '1.2', '1.2.0' )` reports less-than, so
 *   an unpadded comparison clears a site that has an update waiting.
 *
 * **Failures are cached too.** GitHub allows 60 unauthenticated requests an hour
 * per IP and a hosting provider's sites share one, so retrying on every check is
 * how one rate-limited site becomes every site on that address.
 *
 * @package Advision\TrendingNow
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ADVTN_Updater {

	/**
	 * Owner and repository, as they appear in a GitHub URL path.
	 *
	 * @var string
	 */
	public const REPO = 'advision-development/trending-now-plugin';

	/** The only host a package may be downloaded from. */
	private const HOST = 'github.com';

	/**
	 * The plugin's directory name, and the prefix of the zip we publish.
	 *
	 * **Deliberately not the repository name.** `bin/release` builds
	 * `trending-now-<version>.zip` while the repository is
	 * `trending-now-plugin`, so a check that used one value for both — as the
	 * sibling scanner plugins do, where the two happen to match — would reject
	 * every legitimate release.
	 *
	 * @var string
	 */
	private const SLUG = 'trending-now';

	public const TRANSIENT = 'advtn_latest_release';
	public const TTL       = 6 * HOUR_IN_SECONDS;

	/**
	 * How long a failed check is remembered.
	 *
	 * Shorter than a success, so a transient outage does not hide an update for
	 * a whole cycle, and long enough that a rate-limited site is not what keeps
	 * it rate-limited.
	 */
	public const FAILURE_TTL = HOUR_IN_SECONDS;

	/** The admin-post endpoint behind "check for a new release now". */
	public const CHECK_ACTION = 'advtn_check_release';

	/** Marks our own outbound calls so the token is attached to nothing else. */
	private const AUTH_FLAG = 'advtn_github_auth';

	/**
	 * Settings service.
	 *
	 * @var ADVTN_Settings
	 */
	private ADVTN_Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param ADVTN_Settings $settings Settings service.
	 */
	public function __construct( ADVTN_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Bind update hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_filter( 'update_plugins_github.com', array( $this, 'check_for_update' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'normalize_source_dir' ), 10, 4 );
		add_filter( 'http_request_args', array( $this, 'authorize_request' ), 10, 2 );
		add_filter( 'plugins_api', array( $this, 'plugin_details' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( $this, 'flush_after_update' ), 10, 2 );

		// Applied unattended — see automatically() for the reasoning and the way out.
		add_filter( 'auto_update_plugin', array( $this, 'automatically' ), 10, 2 );
		add_filter( 'plugin_auto_update_setting_html', array( $this, 'explain_auto_update' ), 10, 2 );
		add_action( 'admin_post_' . self::CHECK_ACTION, array( $this, 'handle_check' ) );
	}

	/**
	 * Answer WordPress's update check for this plugin.
	 *
	 * **A known release is reported whether or not it is newer**, and core
	 * decides which list it belongs in. `wp_update_plugins()` in 6.4 routes the
	 * answer itself:
	 *
	 *     if ( version_compare( $update->new_version, $plugin_data['Version'], '>' ) ) {
	 *         $updates->response[ $plugin_file ] = $update;
	 *     } else {
	 *         $updates->no_update[ $plugin_file ] = $update;
	 *     }
	 *
	 * So answering `false` for an up-to-date plugin puts it in **neither** list —
	 * and WordPress reads `no_update` to decide whether a row offers automatic
	 * updates at all. An up-to-date plugin that stayed silent would lose its
	 * auto-update cell, which is where `explain_auto_update()` prints the state of
	 * the last check and the re-check link. The sibling scanner plugins populate
	 * `no_update` by hand for the same reason; here core does it.
	 *
	 * It also cannot cause a downgrade: a release behind the installed copy — a
	 * release deleted, a tag moved — fails core's comparison and lands in
	 * `no_update`.
	 *
	 * @param array|false $update      Existing update payload.
	 * @param array       $plugin_data Plugin headers.
	 * @param string      $plugin_file Plugin basename.
	 * @return array|false
	 */
	public function check_for_update( $update, array $plugin_data, string $plugin_file ) {
		if ( ADVTN_BASENAME !== $plugin_file ) {
			return $update;
		}

		if ( ! $this->settings->get_bool( 'auto_update' ) ) {
			return false;
		}

		$release = $this->latest_release();

		if ( is_wp_error( $release ) ) {
			return false;
		}

		return array(
			'id'            => self::HOST . '/' . self::REPO,
			'slug'          => self::SLUG,
			'plugin'        => ADVTN_BASENAME,
			'new_version'   => $release['version'],
			'version'       => $release['version'],
			'url'           => $release['url'],
			'package'       => $release['package'],
			'requires'      => '6.4',
			'requires_php'  => '7.4',
			'tested'        => get_bloginfo( 'version' ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Unattended updates
	 * ------------------------------------------------------------------ */

	/**
	 * Let this plugin update itself without anybody present.
	 *
	 * WordPress offers an "Enable auto-updates" toggle for anything that reports
	 * update information, and leaving it off means a release only lands on a site
	 * somebody visited. Across a network that is how a fleet ends up several
	 * versions behind while every release looks shipped — the sibling scanner
	 * plugins have that written down after four releases went out with none of
	 * them in place.
	 *
	 * This is not free, and the trade is worth stating. Every check in this file
	 * assumes the danger is a *tampered answer*: a URL pointing somewhere else, a
	 * response that is not GitHub's. None of them help if the release is genuinely
	 * published from the pinned repository by somebody who should not have been
	 * able to publish it — a compromised release is correctly hosted, correctly
	 * named, and passes everything here. Unattended updates turn that from "every
	 * site whose operator clicked" into "every site". The mitigation is the
	 * release account, not this code, and the way out is the filter below.
	 *
	 * @param bool|null $update Whether WordPress intends to update it.
	 * @param mixed     $item   The plugin being considered.
	 * @return bool|null
	 */
	public function automatically( $update, $item ) {
		if ( ! is_object( $item ) || ! isset( $item->plugin ) || ADVTN_BASENAME !== $item->plugin ) {
			// Not ours. Handing back what arrived leaves every other plugin's
			// setting alone — returning true here would quietly switch unattended
			// updates on site-wide.
			return $update;
		}

		if ( ! $this->settings->get_bool( 'auto_update' ) ) {
			// Nothing is being offered, so there is nothing to install. Answering
			// false as well would be a second switch saying the same thing.
			return $update;
		}

		/**
		 * Filter whether this plugin updates itself unattended.
		 *
		 * The escape hatch, and it is code rather than a checkbox on purpose —
		 * see explain_auto_update() for why the checkbox is gone. A site that
		 * must not take unattended updates returns false here from its own
		 * mu-plugin.
		 *
		 * @param bool $auto Whether to update unattended.
		 */
		return (bool) apply_filters( 'advtn_auto_update', true );
	}

	/**
	 * Say why the auto-update toggle is not there.
	 *
	 * The toggle is replaced rather than left in place, and that is the whole
	 * reason this method exists. `automatically()` answers the filter regardless
	 * of what the checkbox says, so the control WordPress would print here could
	 * be switched off and change nothing — which is a control that looks like it
	 * works and does not.
	 *
	 * The state of the last check goes here too. A row showing no update cannot
	 * be told apart from a check that never ran or one that failed, and this cell
	 * is where somebody wondering is already looking.
	 *
	 * @param string $html   The markup WordPress was going to print.
	 * @param string $plugin Plugin file being rendered.
	 * @return string
	 */
	public function explain_auto_update( $html, $plugin ) {
		if ( ADVTN_BASENAME !== $plugin ) {
			return $html;
		}

		if ( ! $this->settings->get_bool( 'auto_update' ) ) {
			return '<span class="description">'
				. esc_html__( 'Update checks are switched off for this plugin under Trending Now → Settings.', 'trending-now' )
				. '</span>';
		}

		return '<span class="description">'
			. esc_html__( 'Updates install themselves. A network running several versions of this plugin serves several different widgets, so this one keeps itself current.', 'trending-now' )
			. '<br />' . esc_html( $this->status_text() )
			. '<br /><a href="' . esc_url( self::check_url() ) . '">' . esc_html__( 'Check for a new release now', 'trending-now' ) . '</a>'
			. '</span>';
	}

	/**
	 * Where pressing "check now" goes.
	 *
	 * @return string
	 */
	public static function check_url(): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::CHECK_ACTION ), self::CHECK_ACTION );
	}

	/**
	 * Re-check on request, then go back to the Plugins screen.
	 *
	 * Two caches sit between a published release and a row on that screen: this
	 * plugin's, and WordPress's own `update_plugins`, which it refreshes twice a
	 * day. `force_check()` clears both, because clearing only the first leaves
	 * somebody pressing a button that changes nothing they can see.
	 *
	 * @return void
	 */
	public function handle_check(): void {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'You do not have permission to check for plugin updates on this site.', 'trending-now' ) );
		}

		check_admin_referer( self::CHECK_ACTION );

		$this->force_check();

		wp_safe_redirect( admin_url( 'plugins.php' ) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * The release
	 * ------------------------------------------------------------------ */

	/**
	 * The latest published release, cached — successes and failures alike.
	 *
	 * @param bool $force Bypass the cache.
	 * @return array{version:string,url:string,package:string,body:string,published:string,asset:bool,checked:int}|WP_Error
	 */
	public function latest_release( bool $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::TRANSIENT );

			if ( is_array( $cached ) ) {
				// A remembered failure carries its reason and no version, so a
				// rate-limited site stops asking rather than asking harder.
				return isset( $cached['version'] )
					? $cached
					: new WP_Error( 'advtn_release_unavailable', (string) ( $cached['reason'] ?? '' ) );
			}
		}

		$response = wp_remote_get(
			'https://api.' . self::HOST . '/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout' => 15,
				// Never relaxed, and written out rather than left to the default:
				// this downloads code.
				'sslverify' => true,
				'headers'   => array(
					'Accept'               => 'application/vnd.github+json',
					'X-GitHub-Api-Version' => '2022-11-28',
				),
				self::AUTH_FLAG => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->remember_failure( __( 'the site could not reach github.com', 'trending-now' ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 404 === $code ) {
			return $this->remember_failure( __( 'github.com has no published release to report, or the repository is private and the token cannot see it', 'trending-now' ) );
		}

		if ( 401 === $code || 403 === $code || 429 === $code ) {
			// 60 unauthenticated requests an hour per IP, and a hosting
			// provider's sites share one — so this is usually somebody else's
			// traffic rather than this site's credentials.
			return $this->remember_failure( __( 'github.com refused the request. On shared hosting that is usually its hourly limit being reached by other sites on the same address; a private repository needs a token with contents read access, under Settings → Security', 'trending-now' ) );
		}

		if ( 200 !== $code || ! is_array( $body ) ) {
			return $this->remember_failure(
				/* translators: %d: HTTP status code. */
				sprintf( __( 'github.com answered with status %d', 'trending-now' ), $code )
			);
		}

		$version = self::version_of( (string) ( $body['tag_name'] ?? '' ) );

		if ( '' === $version ) {
			return $this->remember_failure( __( 'the latest release is not tagged with a version this plugin would compare against', 'trending-now' ) );
		}

		$package = self::package_in(
			is_array( $body['assets'] ?? null ) ? $body['assets'] : array(),
			$this->token()
		);

		if ( '' === $package['url'] ) {
			// No zipball fallback: that archive is the development tree with no
			// vendor/, so it installs a plugin whose Action Scheduler is absent.
			return $this->remember_failure( __( 'the latest release carries no zip this plugin will install', 'trending-now' ) );
		}

		$release = array(
			'version'   => $version,
			'url'       => (string) ( $body['html_url'] ?? 'https://' . self::HOST . '/' . self::REPO . '/releases' ),
			'package'   => $package['url'],
			'asset'     => $package['is_asset'],
			'body'      => (string) ( $body['body'] ?? '' ),
			'published' => (string) ( $body['published_at'] ?? '' ),
			'checked'   => time(),
		);

		set_transient( self::TRANSIENT, $release, self::TTL );

		return $release;
	}

	/**
	 * Store why a check failed, and answer with it.
	 *
	 * The reason is named rather than guessed at: a check that silently found
	 * nothing is indistinguishable from one that never ran, which is the question
	 * somebody staring at a plugin row with no update actually has.
	 *
	 * @param string $reason Human-readable cause.
	 * @return WP_Error
	 */
	private function remember_failure( string $reason ): WP_Error {
		set_transient(
			self::TRANSIENT,
			array(
				'failed'  => true,
				'reason'  => $reason,
				'checked' => time(),
			),
			self::FAILURE_TTL
		);

		return new WP_Error( 'advtn_release_unavailable', $reason );
	}

	/**
	 * The download URL of the one asset this plugin will install.
	 *
	 * The pinning lives here. Everything in a release response is remote text,
	 * including the URL WordPress is about to download and unzip over the plugin
	 * directory, so it is checked against the host, owner and repository compiled
	 * into this file rather than trusted.
	 *
	 * Static and taking the token as a parameter so the decision is testable
	 * without a site.
	 *
	 * @param array<int,mixed> $assets Release assets.
	 * @param string           $token  Configured GitHub token, or an empty string.
	 * @return array{url:string,is_asset:bool}
	 */
	public static function package_in( array $assets, string $token = '' ): array {
		$prefix = 'https://' . self::HOST . '/' . self::REPO . '/releases/download/';

		foreach ( $assets as $asset ) {
			if ( ! is_array( $asset ) ) {
				continue;
			}

			// The zip this plugin publishes. A release carrying several files must
			// not have one of the others installed as the plugin.
			//
			// substr() and strpos() rather than str_starts_with()/str_ends_with(),
			// which are PHP 8.0+; this plugin still runs on 7.4 installs.
			$name = strtolower( (string) ( $asset['name'] ?? '' ) );

			if ( 0 !== strpos( $name, self::SLUG . '-' ) || '.zip' !== substr( $name, -4 ) ) {
				continue;
			}

			if ( '' !== $token ) {
				/*
				 * With a token the asset is fetched through the API, because
				 * `browser_download_url` redirects to signed storage that rejects
				 * an Authorization header and so cannot serve a private
				 * repository.
				 *
				 * This URL is built here from a pinned prefix and an integer, so
				 * it is safe by construction. That is why it is deliberately not
				 * prefix-checked afterwards: a guard no input can reach is a guard
				 * no test can hold in place.
				 */
				$id = (int) ( $asset['id'] ?? 0 );

				if ( $id <= 0 ) {
					continue;
				}

				return array(
					'url'      => 'https://api.' . self::HOST . '/repos/' . self::REPO . '/releases/assets/' . $id,
					'is_asset' => true,
				);
			}

			$url = (string) ( $asset['browser_download_url'] ?? '' );

			/*
			 * One check, and it pins the host exactly: a URL's authority ends at
			 * the first slash after the scheme, so a prefix reaching into the path
			 * cannot be satisfied by a lookalike. `https://github.com.evil.test/…`
			 * does not start with this string, and neither does `http://` — the
			 * scheme is in the prefix too.
			 */
			if ( 0 !== strpos( $url, $prefix ) ) {
				continue;
			}

			/*
			 * A prefix pins the host but not the repository, because HTTP clients
			 * resolve `..` out of a path before sending it — RFC 3986's
			 * remove_dot_segments. So
			 * …/trending-now-plugin/releases/download/../../../../someone/their-repo/…
			 * starts with the prefix, passes, and downloads from another account's
			 * release. Still on github.com, still served 200, and not this plugin.
			 */
			if ( false !== strpos( $url, '..' ) ) {
				continue;
			}

			return array(
				'url'      => $url,
				'is_asset' => true,
			);
		}

		return array(
			'url'      => '',
			'is_asset' => false,
		);
	}

	/* ---------------------------------------------------------------------
	 * Versions
	 * ------------------------------------------------------------------ */

	/**
	 * The version a tag names.
	 *
	 * Validated rather than trimmed. `ltrim( $tag, 'vV' )` also eats the leading
	 * characters of anything starting with those letters and lets a tag naming a
	 * branch through as though it were a version.
	 *
	 * @param string $tag Tag name, with or without a leading v.
	 * @return string Empty when the tag is not a version.
	 */
	public static function version_of( string $tag ): string {
		$tag = trim( $tag );

		if ( 0 === strpos( $tag, 'v' ) || 0 === strpos( $tag, 'V' ) ) {
			$tag = substr( $tag, 1 );
		}

		return self::normalize( $tag, false );
	}

	/**
	 * A version padded to three numeric components.
	 *
	 * @param string $version Version string.
	 * @param bool   $pad     Whether to pad to three components.
	 * @return string Empty when it is not a version.
	 */
	public static function normalize( string $version, bool $pad = true ): string {
		$version = trim( $version );

		// Digits and dots only. A tag carrying a suffix this plugin does not
		// publish is not a version to compare against.
		if ( ! preg_match( '~^[0-9]+(\.[0-9]+){0,2}$~', $version ) ) {
			return '';
		}

		if ( ! $pad ) {
			return $version;
		}

		$parts = explode( '.', $version );

		while ( count( $parts ) < 3 ) {
			$parts[] = '0';
		}

		return implode( '.', $parts );
	}

	/**
	 * Whether one version is newer than another.
	 *
	 * Both sides padded first. `version_compare( '1.2', '1.2.0' )` reports
	 * less-than, so an unpadded comparison against a two-component header clears
	 * a site that has an update waiting.
	 *
	 * @param string $remote    The release's version.
	 * @param string $installed The version running.
	 * @return bool
	 */
	public static function is_newer( string $remote, string $installed ): bool {
		$remote    = self::normalize( $remote );
		$installed = self::normalize( $installed );

		if ( '' === $remote || '' === $installed ) {
			return false;
		}

		return version_compare( $remote, $installed, '>' );
	}

	/* ---------------------------------------------------------------------
	 * What the last check knows
	 * ------------------------------------------------------------------ */

	/**
	 * The last check's outcome, read from the cache with no request.
	 *
	 * Exists because a plugin row showing no update cannot be told apart from a
	 * check that never ran, one that failed, or one that ran before the release
	 * was published — and because a screen that renders this must not issue an
	 * HTTP request to do it.
	 *
	 * @return array{state:string,version:string,reason:string,checked:int}
	 */
	public function status(): array {
		$cached = get_transient( self::TRANSIENT );

		if ( ! is_array( $cached ) ) {
			return array(
				'state'   => 'never',
				'version' => '',
				'reason'  => '',
				'checked' => 0,
			);
		}

		$checked = isset( $cached['checked'] ) ? (int) $cached['checked'] : 0;

		if ( ! isset( $cached['version'] ) ) {
			return array(
				'state'   => 'failed',
				'version' => '',
				'reason'  => (string) ( $cached['reason'] ?? '' ),
				'checked' => $checked,
			);
		}

		return array(
			'state'   => self::is_newer( (string) $cached['version'], ADVTN_VERSION ) ? 'available' : 'current',
			'version' => (string) $cached['version'],
			'reason'  => '',
			'checked' => $checked,
		);
	}

	/**
	 * One sentence describing the last check.
	 *
	 * @return string
	 */
	public function status_text(): string {
		$status = $this->status();

		switch ( $status['state'] ) {
			case 'never':
				return __( 'This site has not checked for a new release yet.', 'trending-now' );

			case 'failed':
				return sprintf(
					/* translators: %s: why the check failed. */
					__( 'The last check did not succeed: %s.', 'trending-now' ),
					$status['reason']
				);

			case 'available':
				return sprintf(
					/* translators: %s: version number. */
					__( 'Release %s is available. WordPress installs it on its next update run, which it makes twice a day.', 'trending-now' ),
					$status['version']
				);
		}

		return sprintf(
			/* translators: %s: version number. */
			__( 'Up to date. The newest release is %s.', 'trending-now' ),
			$status['version']
		);
	}

	/* ---------------------------------------------------------------------
	 * Transport and installation
	 * ------------------------------------------------------------------ */

	/**
	 * Attach credentials and headers to our GitHub requests only.
	 *
	 * The package download is issued by the upgrader, not by us, so it cannot
	 * carry our flag — it is matched by URL instead, and only for github.com
	 * hosts.
	 *
	 * @param array  $args Request arguments.
	 * @param string $url  Request URL.
	 * @return array
	 */
	public function authorize_request( $args, $url ) {
		$args = (array) $args;
		$host = strtolower( (string) wp_parse_url( (string) $url, PHP_URL_HOST ) );

		$ours = ! empty( $args[ self::AUTH_FLAG ] )
			|| in_array( $host, array( 'api.github.com', 'github.com', 'codeload.github.com', 'objects.githubusercontent.com' ), true );

		if ( ! $ours ) {
			return $args;
		}

		$args['headers'] = isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : array();

		if ( ! isset( $args['headers']['User-Agent'] ) ) {
			$args['headers']['User-Agent'] = ADVTN_Source_Base::USER_AGENT;
		}

		$token = $this->token();

		// Never send the token anywhere but GitHub's own API. The signed
		// storage redirect rejects it outright.
		if ( '' !== $token && 'api.github.com' === $host ) {
			$args['headers']['Authorization'] = 'Bearer ' . $token;

			// Asset downloads need the binary representation; the JSON default
			// would hand back metadata instead of the zip.
			if ( false !== strpos( (string) $url, '/releases/assets/' ) ) {
				$args['headers']['Accept'] = 'application/octet-stream';
			}
		}

		return $args;
	}

	/**
	 * Force the unpacked directory to match the installed plugin folder.
	 *
	 * Kept even though the zipball fallback is gone: an asset built anywhere but
	 * `bin/release` could still unpack under another name, and installing it
	 * would leave a second, inactive copy beside the original rather than
	 * replacing it.
	 *
	 * @param string      $source        Unpacked source directory.
	 * @param string      $remote_source Parent of the source directory.
	 * @param WP_Upgrader $upgrader      Upgrader instance.
	 * @param array       $args          Extra arguments.
	 * @return string|WP_Error
	 */
	public function normalize_source_dir( $source, $remote_source, $upgrader = null, $args = array() ) {
		global $wp_filesystem;

		$plugin = is_array( $args ) && isset( $args['plugin'] ) ? (string) $args['plugin'] : '';

		if ( ADVTN_BASENAME !== $plugin || ! $wp_filesystem ) {
			return $source;
		}

		$desired = trailingslashit( (string) $remote_source ) . self::SLUG;

		if ( untrailingslashit( (string) $source ) === $desired ) {
			return $source;
		}

		if ( $wp_filesystem->exists( $desired ) ) {
			$wp_filesystem->delete( $desired, true );
		}

		if ( ! $wp_filesystem->move( $source, $desired ) ) {
			return new WP_Error( 'advtn_rename_failed', __( 'Could not normalize the update directory name.', 'trending-now' ) );
		}

		return trailingslashit( $desired );
	}

	/**
	 * Populate the "View details" modal.
	 *
	 * Without this, the link WordPress prints beside an available update opens a
	 * modal reporting that the plugin does not exist.
	 *
	 * @param false|object|array $result Existing result.
	 * @param string             $action Requested action.
	 * @param object             $args   Request arguments.
	 * @return false|object|array
	 */
	public function plugin_details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! isset( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}

		$release = $this->latest_release();

		if ( is_wp_error( $release ) ) {
			return $result;
		}

		return (object) array(
			'name'          => 'Trending Now',
			'slug'          => self::SLUG,
			'version'       => $release['version'],
			'author'        => '<a href="https://github.com/advision-development">Advision Development</a>',
			'homepage'      => 'https://' . self::HOST . '/' . self::REPO,
			'requires'      => '6.4',
			'requires_php'  => '7.4',
			'tested'        => get_bloginfo( 'version' ),
			'last_updated'  => $release['published'],
			'download_link' => $release['package'],
			'sections'      => array(
				'description' => wpautop( esc_html__( 'Server-rendered Trending Now link block aggregated from owned WordPress sites and third-party news, built for crawl discovery.', 'trending-now' ) ),
				// Release notes as GitHub returned them. Escaped, because this is
				// remote text rendered inside wp-admin.
				'changelog'   => wpautop( esc_html( $release['body'] ) ),
			),
		);
	}

	/**
	 * Drop the cached release after an update so the next check is honest.
	 *
	 * @param WP_Upgrader $upgrader Upgrader instance.
	 * @param array       $extra    Update context.
	 * @return void
	 */
	public function flush_after_update( $upgrader, $extra ): void {
		unset( $upgrader );

		if ( ! is_array( $extra ) || 'update' !== ( $extra['action'] ?? '' ) || 'plugin' !== ( $extra['type'] ?? '' ) ) {
			return;
		}

		if ( in_array( ADVTN_BASENAME, (array) ( $extra['plugins'] ?? array() ), true ) ) {
			delete_transient( self::TRANSIENT );
			ADVTN_Logger::log( 'info', 'Plugin updated from GitHub.', array( 'version' => ADVTN_VERSION ) );
		}
	}

	/**
	 * Clear caches so the next admin screen re-checks immediately.
	 *
	 * @return array|WP_Error The freshly fetched release.
	 */
	public function force_check() {
		delete_transient( self::TRANSIENT );

		// WordPress's own list, or the row keeps showing what it decided this
		// morning.
		delete_site_transient( 'update_plugins' );

		return $this->latest_release( true );
	}

	/**
	 * Configured GitHub token.
	 *
	 * @return string
	 */
	private function token(): string {
		return $this->settings->get_secret( 'github_token' );
	}
}
