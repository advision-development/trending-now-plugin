<?php
/**
 * Self-update from GitHub releases.
 *
 * Hooks the `update_plugins_{$hostname}` filter that WordPress 5.8+ derives
 * from the plugin's `Update URI` header. That header also stops wordpress.org
 * from ever answering for this slug, which is the failure mode that makes
 * naive GitHub updaters overwrite a plugin with an unrelated one.
 *
 * @package Advision\TrendingNow
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ADVTN_Updater {

	public const REPO      = 'advision-development/trending-now-plugin';
	public const TRANSIENT = 'advtn_latest_release';
	public const TTL       = 6 * HOUR_IN_SECONDS;

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
	}

	/**
	 * Answer WordPress's update check for this plugin.
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

		$installed = (string) ( $plugin_data['Version'] ?? ADVTN_VERSION );

		if ( ! version_compare( $release['version'], $installed, '>' ) ) {
			return false;
		}

		return array(
			'id'            => 'github.com/' . self::REPO,
			'slug'          => 'trending-now',
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

	/**
	 * The latest published release, cached.
	 *
	 * @param bool $force Bypass the cache.
	 * @return array{version:string,url:string,package:string,body:string,published:string,asset:bool}|WP_Error
	 */
	public function latest_release( bool $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::TRANSIENT );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout'   => 15,
				'headers'   => array(
					'Accept'               => 'application/vnd.github+json',
					'X-GitHub-Api-Version' => '2022-11-28',
				),
				self::AUTH_FLAG => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 404 === $code ) {
			return new WP_Error(
				'advtn_no_release',
				__( 'No published release found, or the repository is private and the token cannot see it.', 'trending-now' )
			);
		}

		if ( 401 === $code || 403 === $code ) {
			return new WP_Error(
				'advtn_github_auth',
				__( 'GitHub rejected the request. A private or internal repository needs a personal access token with repo/contents read access, set under Settings → Security.', 'trending-now' )
			);
		}

		if ( 200 !== $code || ! is_array( $body ) ) {
			return new WP_Error(
				'advtn_github_failed',
				/* translators: %d: HTTP status code. */
				sprintf( __( 'Unexpected HTTP status %d from the GitHub API.', 'trending-now' ), $code )
			);
		}

		$tag     = (string) ( $body['tag_name'] ?? '' );
		$version = ltrim( $tag, 'vV' );

		if ( '' === $version ) {
			return new WP_Error( 'advtn_no_tag', __( 'The latest release has no tag name.', 'trending-now' ) );
		}

		$package = $this->resolve_package( is_array( $body['assets'] ?? null ) ? $body['assets'] : array() );

		$release = array(
			'version'   => $version,
			'url'       => (string) ( $body['html_url'] ?? 'https://github.com/' . self::REPO . '/releases' ),
			'package'   => '' !== $package['url'] ? $package['url'] : (string) ( $body['zipball_url'] ?? '' ),
			'asset'     => $package['is_asset'],
			'body'      => (string) ( $body['body'] ?? '' ),
			'published' => (string) ( $body['published_at'] ?? '' ),
		);

		set_transient( self::TRANSIENT, $release, self::TTL );

		return $release;
	}

	/**
	 * Pick the download URL for a release.
	 *
	 * Prefers the attached plugin zip, which unpacks to a `trending-now/`
	 * directory. GitHub's generated zipball unpacks to `owner-repo-sha/` and
	 * ships the development files, so it is only a fallback.
	 *
	 * With a token configured the asset is fetched through the API endpoint:
	 * `browser_download_url` redirects to a signed S3 URL that rejects an
	 * Authorization header, so it cannot be used for a private repository.
	 *
	 * @param array<int,mixed> $assets Release assets.
	 * @return array{url:string,is_asset:bool}
	 */
	private function resolve_package( array $assets ): array {
		$token = $this->token();

		foreach ( $assets as $asset ) {
			if ( ! is_array( $asset ) ) {
				continue;
			}

			$name = (string) ( $asset['name'] ?? '' );

			// substr() rather than str_ends_with(), which is PHP 8.0+; this
			// plugin still runs on 7.4 installs.
			if ( '.zip' !== substr( strtolower( $name ), -4 ) ) {
				continue;
			}

			$url = '' !== $token
				? 'https://api.github.com/repos/' . self::REPO . '/releases/assets/' . (int) ( $asset['id'] ?? 0 )
				: (string) ( $asset['browser_download_url'] ?? '' );

			if ( '' !== $url ) {
				return array(
					'url'      => $url,
					'is_asset' => true,
				);
			}
		}

		return array(
			'url'      => '',
			'is_asset' => false,
		);
	}

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
	 * A zipball unpacks to `owner-repo-<sha>/`; leaving that alone would
	 * install a second, inactive copy alongside the original.
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

		$desired = trailingslashit( (string) $remote_source ) . 'trending-now';

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
	 * @param false|object|array $result Existing result.
	 * @param string             $action Requested action.
	 * @param object             $args   Request arguments.
	 * @return false|object|array
	 */
	public function plugin_details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! isset( $args->slug ) || 'trending-now' !== $args->slug ) {
			return $result;
		}

		$release = $this->latest_release();

		if ( is_wp_error( $release ) ) {
			return $result;
		}

		return (object) array(
			'name'          => 'Trending Now',
			'slug'          => 'trending-now',
			'version'       => $release['version'],
			'author'        => '<a href="https://github.com/advision-development">Advision Development</a>',
			'homepage'      => 'https://github.com/' . self::REPO,
			'requires'      => '6.4',
			'requires_php'  => '7.4',
			'tested'        => get_bloginfo( 'version' ),
			'last_updated'  => $release['published'],
			'download_link' => $release['package'],
			'sections'      => array(
				'description' => wpautop( esc_html__( 'Server-rendered Trending Now link block aggregated from owned WordPress sites and third-party news, built for crawl discovery.', 'trending-now' ) ),
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
