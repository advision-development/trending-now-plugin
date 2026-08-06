<?php
/**
 * Admin menu, form handling and AJAX.
 *
 * @package Advision\TrendingNow
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ADVTN_Admin {

	public const MENU_SLUG  = 'trending-now';
	public const CAPABILITY = 'manage_options';

	/**
	 * Settings service.
	 *
	 * @var ADVTN_Settings
	 */
	private ADVTN_Settings $settings;

	/**
	 * Repository service.
	 *
	 * @var ADVTN_Repository
	 */
	private ADVTN_Repository $repository;

	/**
	 * Constructor.
	 *
	 * @param ADVTN_Settings   $settings   Settings service.
	 * @param ADVTN_Repository $repository Repository service.
	 */
	public function __construct( ADVTN_Settings $settings, ADVTN_Repository $repository ) {
		$this->settings   = $settings;
		$this->repository = $repository;
	}

	/**
	 * Bind admin hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_advtn_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_advtn_save_sources', array( $this, 'handle_save_sources' ) );
		add_action( 'admin_post_advtn_action', array( $this, 'handle_action' ) );
		add_action( 'wp_ajax_advtn_test_fetch', array( $this, 'ajax_test_fetch' ) );
		add_action( 'admin_notices', array( $this, 'stale_ingest_notice' ) );
	}

	/**
	 * Top-level menu plus one submenu per tab.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		add_menu_page(
			__( 'Trending Now', 'trending-now' ),
			__( 'Trending Now', 'trending-now' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_page' ),
			'dashicons-chart-line',
			58
		);
	}

	/**
	 * Admin CSS/JS, only on the plugin screen.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		if ( 'toplevel_page_' . self::MENU_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style( 'advtn-admin', ADVTN_PLUGIN_URL . 'admin/assets/admin.css', array(), ADVTN_VERSION );
		wp_enqueue_script( 'advtn-admin', ADVTN_PLUGIN_URL . 'admin/assets/admin.js', array(), ADVTN_VERSION, true );

		wp_localize_script(
			'advtn-admin',
			'advtnAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'advtn_test_fetch' ),
				'i18n'    => array(
					'testing'   => __( 'Testing…', 'trending-now' ),
					'testFetch' => __( 'Test fetch', 'trending-now' ),
					'failed'    => __( 'Request failed.', 'trending-now' ),
					'confirm'   => __( 'Are you sure?', 'trending-now' ),
				),
			)
		);
	}

	/**
	 * Current tab key.
	 *
	 * @return string
	 */
	public function current_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab switch.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings';

		return in_array( $tab, array( 'settings', 'sources', 'diagnostics' ), true ) ? $tab : 'settings';
	}

	/**
	 * Render the tabbed admin page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'trending-now' ) );
		}

		$tab        = $this->current_tab();
		$settings   = $this->settings;
		$repository = $this->repository;
		$admin      = $this;

		$tabs = array(
			'settings'    => __( 'Settings', 'trending-now' ),
			'sources'     => __( 'Sources', 'trending-now' ),
			'diagnostics' => __( 'Diagnostics', 'trending-now' ),
		);

		echo '<div class="wrap advtn-admin">';
		echo '<h1>' . esc_html__( 'Trending Now', 'trending-now' ) . '</h1>';

		$this->render_notice();

		echo '<h2 class="nav-tab-wrapper">';
		foreach ( $tabs as $key => $label ) {
			printf(
				'<a href="%s" class="nav-tab %s">%s</a>',
				esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=' . $key ) ),
				$key === $tab ? 'nav-tab-active' : '',
				esc_html( $label )
			);
		}
		echo '</h2>';

		$view = ADVTN_PATH . 'admin/views/' . $tab . '.php';
		if ( is_readable( $view ) ) {
			include $view;
		}

		echo '</div>';
	}

	/* ---------------------------------------------------------------------
	 * Form handlers
	 * ------------------------------------------------------------------ */

	/**
	 * Persist the settings form.
	 *
	 * @return void
	 */
	public function handle_save_settings(): void {
		$this->guard( 'advtn_save_settings' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		$raw = isset( $_POST['advtn'] ) && is_array( $_POST['advtn'] ) ? (array) wp_unslash( $_POST['advtn'] ) : array();

		// An unchecked checkbox posts nothing, and update() merges over the
		// current values — so absence has to be made explicit or a box can
		// never be turned back off.
		foreach ( array( 'archive_noindex', 'archive_enabled', 'link_target_blank', 'delete_data_on_uninstall' ) as $flag ) {
			$raw[ $flag ] = ! empty( $raw[ $flag ] );
		}

		$before = $this->settings->all();
		$after  = $this->settings->update( (array) $raw );

		// A slug change needs new rewrite rules, and the render cache embeds
		// the prefix, the heading and the archive URL.
		if ( $before['archive_slug'] !== $after['archive_slug'] || $before['archive_enabled'] !== $after['archive_enabled'] ) {
			update_option( 'advtn_flush_rewrites', 1, false );
		}

		advtn()->renderer()->purge_cache();

		$this->redirect( 'settings', 'saved' );
	}

	/**
	 * Persist the sources form.
	 *
	 * @return void
	 */
	public function handle_save_sources(): void {
		$this->guard( 'advtn_save_sources' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		$rows = isset( $_POST['sources'] ) && is_array( $_POST['sources'] ) ? (array) wp_unslash( $_POST['sources'] ) : array();

		$clean  = array();
		$errors = array();

		foreach ( (array) $rows as $row ) {
			if ( ! is_array( $row ) || ! empty( $row['_delete'] ) ) {
				continue;
			}

			// A brand-new blank row: skip it rather than erroring.
			if ( '' === trim( (string) ( $row['label'] ?? '' ) ) && '' === trim( (string) ( $row['url'] ?? '' ) ) && '' === trim( (string) ( $row['gdelt_query'] ?? '' ) ) ) {
				continue;
			}

			$type     = (string) ( $row['type'] ?? '' );
			$provider = advtn()->source( $type );

			if ( null === $provider ) {
				/* translators: %s: source type key. */
				$errors[] = sprintf( __( 'Unknown source type "%s".', 'trending-now' ), $type );
				continue;
			}

			$validated = $provider->validate_config( $row );

			if ( is_wp_error( $validated ) ) {
				$errors[] = sprintf( '%s: %s', (string) ( $row['label'] ?? $type ), $validated->get_error_message() );
				continue;
			}

			$validated['_order'] = (int) ( $row['order'] ?? count( $clean ) );
			$clean[]             = $validated;
		}

		usort( $clean, static fn( $a, $b ) => $a['_order'] <=> $b['_order'] );
		$clean = array_map(
			static function ( $row ) {
				unset( $row['_order'] );
				return $row;
			},
			$clean
		);

		$this->settings->save_sources( $clean );
		$this->settings->prune_state();

		if ( ! empty( $errors ) ) {
			set_transient( 'advtn_admin_errors', $errors, 60 );
			$this->redirect( 'sources', 'partial' );
		}

		$this->redirect( 'sources', 'saved' );
	}

	/**
	 * Diagnostics buttons and the secret regenerator.
	 *
	 * @return void
	 */
	public function handle_action(): void {
		$this->guard( 'advtn_action' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		$action = isset( $_POST['advtn_do'] ) ? sanitize_key( wp_unslash( $_POST['advtn_do'] ) ) : '';
		$tab    = 'diagnostics';
		$notice = 'done';

		switch ( $action ) {
			case 'run_ingest':
				$result = advtn()->ingest()->run( true );
				$notice = 'locked' === $result['status'] ? 'locked' : 'ingest_started';
				break;

			case 'rebuild_selection':
				advtn()->selector()->build_and_commit();
				advtn()->renderer()->purge_cache();
				$notice = 'selection_rebuilt';
				break;

			case 'purge_cache':
				advtn()->renderer()->purge_cache();
				$notice = 'cache_purged';
				break;

			case 'release_lock':
				ADVTN_Lock::release();
				$notice = 'lock_released';
				break;

			case 'clear_log':
				ADVTN_Logger::clear();
				$notice = 'log_cleared';
				break;

			case 'test_loopback':
				$notice = ADVTN_REST::loopback_ok( true ) ? 'loopback_ok' : 'loopback_failed';
				break;

			case 'regenerate_ingest_secret':
				$this->settings->update( array( 'ingest_secret' => ADVTN_Activator::generate_secret() ) );
				$tab    = 'settings';
				$notice = 'secret_regenerated';
				break;

			case 'regenerate_hub_secret':
				$this->settings->update( array( 'hub_secret' => ADVTN_Activator::generate_secret() ) );
				$tab    = 'settings';
				$notice = 'secret_regenerated';
				break;

			default:
				$notice = 'unknown';
		}

		$this->redirect( $tab, $notice );
	}

	/**
	 * AJAX: run a source fetch without writing anything.
	 *
	 * @return void
	 */
	public function ajax_test_fetch(): void {
		check_ajax_referer( 'advtn_test_fetch', 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'trending-now' ) ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
		$config = isset( $_POST['config'] ) && is_array( $_POST['config'] ) ? (array) wp_unslash( $_POST['config'] ) : array();

		$type     = isset( $config['type'] ) ? sanitize_key( (string) $config['type'] ) : '';
		$provider = advtn()->source( $type );

		if ( null === $provider ) {
			wp_send_json_error( array( 'message' => __( 'Unknown source type.', 'trending-now' ) ), 400 );
		}

		$validated = $provider->validate_config( (array) $config );

		if ( is_wp_error( $validated ) ) {
			wp_send_json_error( array( 'message' => $validated->get_error_message() ), 400 );
		}

		$result = advtn()->ingest()->test_fetch( $validated );

		wp_send_json_success(
			array(
				'ok'          => $result->ok,
				'error'       => $result->error,
				'http_code'   => $result->http_code,
				'duration_ms' => $result->duration_ms,
				'raw_count'   => $result->raw_count,
				'item_count'  => count( $result->items ),
				'items'       => array_map(
					static function ( array $item ): array {
						return array(
							'title'        => (string) $item['title'],
							'url'          => (string) $item['url'],
							'published_at' => (string) ( $item['published_at'] ?? '' ),
							'site_name'    => (string) ( $item['site_name'] ?? '' ),
							'has_image'    => ! empty( $item['image_url'] ),
						);
					},
					array_slice( $result->items, 0, 3 )
				),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Notices and helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Red banner when ingestion has silently stopped.
	 *
	 * @return void
	 */
	public function stale_ingest_notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || 'toplevel_page_' . self::MENU_SLUG !== $screen->id ) {
			return;
		}

		$last = (string) get_option( ADVTN_Ingest::OPTION_LAST_INGEST, '' );
		$ts   = '' !== $last ? strtotime( $last . ' UTC' ) : false;

		if ( false !== $ts && ( time() - $ts ) < 30 * HOUR_IN_SECONDS ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'Ingestion has not completed in over 30 hours.', 'trending-now' ),
			esc_html(
				'' === $last
					? __( 'It has never run on this site.', 'trending-now' )
					: sprintf(
						/* translators: %s: UTC datetime. */
						__( 'Last completed cycle: %s UTC.', 'trending-now' ),
						$last
					)
			)
		);
	}

	/**
	 * Render the redirect notice, if any.
	 *
	 * @return void
	 */
	private function render_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$notice = isset( $_GET['advtn_notice'] ) ? sanitize_key( wp_unslash( $_GET['advtn_notice'] ) ) : '';

		if ( '' === $notice ) {
			return;
		}

		$messages = array(
			'saved'              => array( 'success', __( 'Saved.', 'trending-now' ) ),
			'partial'            => array( 'warning', __( 'Saved, but some rows were rejected.', 'trending-now' ) ),
			'ingest_started'     => array( 'success', __( 'Ingest cycle scheduled.', 'trending-now' ) ),
			'locked'             => array( 'warning', __( 'An ingest cycle is already running.', 'trending-now' ) ),
			'selection_rebuilt'  => array( 'success', __( 'Selection rebuilt.', 'trending-now' ) ),
			'cache_purged'       => array( 'success', __( 'Render cache purged.', 'trending-now' ) ),
			'lock_released'      => array( 'success', __( 'Lock released.', 'trending-now' ) ),
			'log_cleared'        => array( 'success', __( 'Log cleared.', 'trending-now' ) ),
			'loopback_ok'        => array( 'success', __( 'Loopback request succeeded.', 'trending-now' ) ),
			'loopback_failed'    => array( 'error', __( 'Loopback request failed. Action Scheduler cannot run on this host until that is fixed.', 'trending-now' ) ),
			'secret_regenerated' => array( 'success', __( 'Secret regenerated. Update any external caller.', 'trending-now' ) ),
		);

		if ( isset( $messages[ $notice ] ) ) {
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				esc_attr( $messages[ $notice ][0] ),
				esc_html( $messages[ $notice ][1] )
			);
		}

		$errors = get_transient( 'advtn_admin_errors' );
		if ( is_array( $errors ) && ! empty( $errors ) ) {
			delete_transient( 'advtn_admin_errors' );
			echo '<div class="notice notice-error"><ul style="margin:.5em 0 .5em 1.5em;list-style:disc">';
			foreach ( $errors as $error ) {
				echo '<li>' . esc_html( (string) $error ) . '</li>';
			}
			echo '</ul></div>';
		}
	}

	/**
	 * Capability plus nonce check for every POST handler.
	 *
	 * @param string $action Nonce action.
	 * @return void
	 */
	private function guard( string $action ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'trending-now' ) );
		}

		check_admin_referer( $action );
	}

	/**
	 * Redirect back to a tab with a notice code.
	 *
	 * @param string $tab    Tab key.
	 * @param string $notice Notice code.
	 * @return void
	 */
	private function redirect( string $tab, string $notice ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => self::MENU_SLUG,
					'tab'          => $tab,
					'advtn_notice' => $notice,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
