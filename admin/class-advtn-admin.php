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

	/** Envelope identifiers for the sources export format. */
	public const EXPORT_SCHEMA  = 'advtn.sources';
	public const EXPORT_VERSION = 1;

	/** Ceiling on an imported payload. A source list is never this big. */
	public const IMPORT_MAX_BYTES = 1048576;

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
		add_action( 'admin_post_advtn_save_manual', array( $this, 'handle_save_manual' ) );
		add_action( 'admin_post_advtn_action', array( $this, 'handle_action' ) );
		add_action( 'admin_post_advtn_export_sources', array( $this, 'handle_export_sources' ) );
		add_action( 'admin_post_advtn_import_sources', array( $this, 'handle_import_sources' ) );
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
					'replace'   => __( 'Replace discards every source currently configured on this site. Continue?', 'trending-now' ),
					'deleteAll' => __( 'This empties the entire items table. Sources and settings are kept, but every stored article and its display history is gone. Continue?', 'trending-now' ),
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

		return in_array( $tab, array( 'settings', 'sources', 'manual', 'diagnostics' ), true ) ? $tab : 'settings';
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
			'manual'      => __( 'Manual links', 'trending-now' ),
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
		foreach ( array( 'archive_noindex', 'archive_enabled', 'link_target_blank', 'delete_data_on_uninstall', 'auto_update', 'show_images', 'show_source', 'show_date', 'show_icons' ) as $flag ) {
			$raw[ $flag ] = ! empty( $raw[ $flag ] );
		}

		// Settings::update() schedules the rewrite flush itself when the archive
		// slug or its enabled state changes. The render cache is busted here
		// because it embeds the class prefix, the heading and the archive URL.
		$this->settings->update( (array) $raw );

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
			if ( '' === trim( (string) ( $row['label'] ?? '' ) ) && '' === trim( (string) ( $row['url'] ?? '' ) ) && '' === trim( (string) ( $row['serp_query'] ?? '' ) ) ) {
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
	 * Persist the curated links form.
	 *
	 * @return void
	 */
	public function handle_save_manual(): void {
		$this->guard( 'advtn_save_manual' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		$rows = isset( $_POST['links'] ) && is_array( $_POST['links'] ) ? (array) wp_unslash( $_POST['links'] ) : array();

		$result = advtn()->manual()->save( $rows );

		// Curated links are placed by hand, so the operator should see the
		// result immediately rather than at the next cycle.
		advtn()->selector()->build_and_commit();
		advtn()->renderer()->purge_cache();

		$active = count( advtn()->manual()->active() );

		set_transient(
			'advtn_admin_summary',
			sprintf(
				/* translators: 1: saved count, 2: live count. */
				__( 'Saved %1$d link(s); %2$d live in the widget now.', 'trending-now' ),
				count( $result['links'] ),
				$active
			),
			60
		);

		if ( ! empty( $result['errors'] ) ) {
			set_transient( 'advtn_admin_errors', $result['errors'], 60 );
			$this->redirect( 'manual', 'partial' );
		}

		$this->redirect( 'manual', 'saved' );
	}

	/**
	 * Stream the configured sources as a JSON download.
	 *
	 * @return void
	 */
	public function handle_export_sources(): void {
		$this->guard( 'advtn_export_sources' );

		$payload = array(
			'schema'      => self::EXPORT_SCHEMA,
			'version'     => self::EXPORT_VERSION,
			'exported_at' => gmdate( 'c' ),
			'site'        => home_url( '/' ),
			'sources'     => $this->settings->sources(),
		);

		$filename = sprintf( 'trending-now-sources-%s.json', gmdate( 'Ymd-His' ) );

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );

		echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		exit;
	}

	/**
	 * Import sources from an uploaded file or pasted JSON.
	 *
	 * @return void
	 */
	public function handle_import_sources(): void {
		$this->guard( 'advtn_import_sources' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		$strategy = isset( $_POST['advtn_import_mode'] ) ? sanitize_key( wp_unslash( $_POST['advtn_import_mode'] ) ) : 'merge';
		$strategy = in_array( $strategy, array( 'merge', 'replace' ), true ) ? $strategy : 'merge';

		$raw = $this->read_import_payload();

		if ( is_wp_error( $raw ) ) {
			set_transient( 'advtn_admin_errors', array( $raw->get_error_message() ), 60 );
			$this->redirect( 'sources', 'import_failed' );
		}

		$decoded = json_decode( (string) $raw, true );

		if ( ! is_array( $decoded ) ) {
			set_transient( 'advtn_admin_errors', array( __( 'That file is not valid JSON.', 'trending-now' ) ), 60 );
			$this->redirect( 'sources', 'import_failed' );
		}

		// Accept either a full export envelope or a bare array of rows.
		$rows = isset( $decoded['sources'] ) && is_array( $decoded['sources'] ) ? $decoded['sources'] : $decoded;

		if ( empty( $rows ) || ! is_array( $rows ) ) {
			set_transient( 'advtn_admin_errors', array( __( 'No sources found in that file.', 'trending-now' ) ), 60 );
			$this->redirect( 'sources', 'import_failed' );
		}

		$imported = array();
		$errors   = array();

		foreach ( $rows as $index => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$provider = advtn()->source( (string) ( $row['type'] ?? '' ) );

			if ( null === $provider ) {
				/* translators: 1: row number, 2: source type key. */
				$errors[] = sprintf( __( 'Row %1$d: unknown source type "%2$s".', 'trending-now' ), (int) $index + 1, (string) ( $row['type'] ?? '' ) );
				continue;
			}

			$validated = $provider->validate_config( $row );

			if ( is_wp_error( $validated ) ) {
				/* translators: 1: row number or label, 2: error message. */
				$errors[] = sprintf( __( 'Row %1$s: %2$s', 'trending-now' ), (string) ( $row['label'] ?? (string) ( (int) $index + 1 ) ), $validated->get_error_message() );
				continue;
			}

			$imported[] = $validated;
		}

		if ( empty( $imported ) ) {
			$errors[] = __( 'Nothing was imported.', 'trending-now' );
			set_transient( 'advtn_admin_errors', $errors, 60 );
			$this->redirect( 'sources', 'import_failed' );
		}

		$final = 'replace' === $strategy ? $imported : $this->merge_sources( $this->settings->sources(), $imported );

		$this->settings->save_sources( $final );
		$this->settings->prune_state();

		set_transient(
			'advtn_admin_summary',
			sprintf(
				/* translators: 1: number of rows imported, 2: merge or replace, 3: resulting total. */
				__( 'Imported %1$d source(s) using "%2$s". The list now has %3$d.', 'trending-now' ),
				count( $imported ),
				$strategy,
				count( $final )
			),
			60
		);

		if ( ! empty( $errors ) ) {
			set_transient( 'advtn_admin_errors', $errors, 60 );
		}

		ADVTN_Logger::log(
			'info',
			'Sources imported.',
			array(
				'imported' => count( $imported ),
				'strategy' => $strategy,
				'rejected' => count( $errors ),
			)
		);

		$this->redirect( 'sources', empty( $errors ) ? 'imported' : 'partial' );
	}

	/**
	 * Overlay imported rows onto the existing list, matching on id.
	 *
	 * @param array<int,array<string,mixed>> $existing Current rows.
	 * @param array<int,array<string,mixed>> $incoming Validated imported rows.
	 * @return array<int,array<string,mixed>>
	 */
	private function merge_sources( array $existing, array $incoming ): array {
		$by_id = array();

		foreach ( $existing as $row ) {
			$by_id[ (string) ( $row['id'] ?? '' ) ] = $row;
		}

		foreach ( $incoming as $row ) {
			$by_id[ (string) ( $row['id'] ?? '' ) ] = $row;
		}

		unset( $by_id[''] );

		return array_values( $by_id );
	}

	/**
	 * Pull the import JSON from the upload or the textarea.
	 *
	 * @return string|WP_Error
	 */
	private function read_import_payload() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		$file = isset( $_FILES['advtn_import_file'] ) ? $_FILES['advtn_import_file'] : null;

		if ( is_array( $file ) && ! empty( $file['tmp_name'] ) && UPLOAD_ERR_NO_FILE !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
				return new WP_Error( 'advtn_upload_failed', __( 'The upload did not complete.', 'trending-now' ) );
			}

			$tmp = (string) $file['tmp_name'];

			if ( ! is_uploaded_file( $tmp ) ) {
				return new WP_Error( 'advtn_bad_upload', __( 'That upload could not be verified.', 'trending-now' ) );
			}

			if ( (int) ( $file['size'] ?? 0 ) > self::IMPORT_MAX_BYTES ) {
				return new WP_Error( 'advtn_upload_too_large', __( 'That file is too large to be a source list.', 'trending-now' ) );
			}

			$contents = file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local upload temp file, not a remote fetch.

			return false === $contents
				? new WP_Error( 'advtn_upload_unreadable', __( 'That file could not be read.', 'trending-now' ) )
				: $contents;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		$pasted = isset( $_POST['advtn_import_json'] ) ? trim( (string) wp_unslash( $_POST['advtn_import_json'] ) ) : '';

		if ( '' === $pasted ) {
			return new WP_Error( 'advtn_no_import', __( 'Choose a file or paste some JSON first.', 'trending-now' ) );
		}

		if ( strlen( $pasted ) > self::IMPORT_MAX_BYTES ) {
			return new WP_Error( 'advtn_paste_too_large', __( 'That payload is too large to be a source list.', 'trending-now' ) );
		}

		return $pasted;
	}

	/**
	 * Diagnostics buttons and the secret regenerator.
	 *
	 * @return void
	 */
	public function handle_action(): void {
		$this->guard( 'advtn_action' );

		// A per-row Delete button submits only its own name/value, so it never
		// carries advtn_do. Handle it before the switch.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		if ( isset( $_POST['advtn_delete_item'] ) ) {
			$this->delete_items( array( (int) wp_unslash( $_POST['advtn_delete_item'] ) ) );
			$this->redirect( 'diagnostics', 'items_deleted' );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		$action = isset( $_POST['advtn_do'] ) ? sanitize_key( wp_unslash( $_POST['advtn_do'] ) ) : '';
		$tab    = 'diagnostics';
		$notice = 'done';

		switch ( $action ) {
			case 'run_ingest':
				$result = advtn()->ingest()->run_now();

				if ( 'locked' === $result['status'] ) {
					$notice = 'locked';
					break;
				}

				$parts = array();

				foreach ( $result['ran'] as $source_id => $count ) {
					/* translators: 1: source id, 2: item count. */
					$parts[] = sprintf( __( '%1$s: %2$d item(s)', 'trending-now' ), $source_id, $count );
				}
				foreach ( $result['failed'] as $source_id => $error ) {
					/* translators: 1: source id, 2: error message. */
					$parts[] = sprintf( __( '%1$s FAILED — %2$s', 'trending-now' ), $source_id, $error );
				}
				if ( ! empty( $result['queued'] ) ) {
					/* translators: %s: comma-separated source ids. */
					$parts[] = sprintf( __( 'queued for the background runner (time budget reached): %s', 'trending-now' ), implode( ', ', $result['queued'] ) );
				}

				set_transient(
					'advtn_admin_summary',
					sprintf(
						/* translators: 1: per-source detail, 2: resulting selection size. */
						__( 'Ingest complete. %1$s. The live selection now holds %2$d item(s).', 'trending-now' ),
						empty( $parts ) ? __( 'No enabled sources', 'trending-now' ) : implode( '; ', $parts ),
						count( advtn()->selector()->current_ids() )
					),
					60
				);

				$notice = empty( $result['failed'] ) ? 'ingest_done' : 'ingest_partial';
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

			case 'delete_selected_items':
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
				$ids = isset( $_POST['item_ids'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['item_ids'] ) ) : array();

				if ( empty( $ids ) ) {
					$notice = 'nothing_selected';
					break;
				}

				$this->delete_items( $ids );
				$notice = 'items_deleted';
				break;

			case 'delete_filtered_items':
				$filters = $this->item_filters();

				if ( empty( array_filter( $filters ) ) ) {
					// Never let a dropped filter turn into "delete everything".
					$notice = 'filter_required';
					break;
				}

				$deleted = $this->repository->delete_where( $filters );
				$this->after_delete();

				set_transient(
					'advtn_admin_summary',
					sprintf(
						/* translators: 1: rows deleted, 2: the filter that matched them. */
						__( 'Deleted %1$d item(s) matching %2$s.', 'trending-now' ),
						$deleted,
						$this->describe_filters( $filters )
					),
					60
				);

				$notice = 'items_deleted';
				break;

			case 'delete_all_items':
				$total = $this->repository->counts()['total'];
				$this->repository->delete_all();
				$this->after_delete();

				set_transient(
					'advtn_admin_summary',
					sprintf(
						/* translators: %d: rows deleted. */
						__( 'Deleted all %d item(s). The next ingest will repopulate from the enabled sources.', 'trending-now' ),
						$total
					),
					60
				);

				ADVTN_Logger::log( 'warning', 'All items deleted from the admin.', array( 'rows' => $total ) );

				$notice = 'items_deleted';
				break;

			case 'check_serpapi':
				$account = advtn()->source( 'serpapi' )->account( true );

				if ( is_wp_error( $account ) ) {
					set_transient( 'advtn_admin_errors', array( $account->get_error_message() ), 60 );
					$notice = 'done';
					break;
				}

				set_transient(
					'advtn_admin_summary',
					sprintf(
						/* translators: 1: plan name, 2: searches remaining, 3: usage this month. */
						__( 'SerpAPI plan "%1$s": %2$s search(es) left, %3$s used this month.', 'trending-now' ),
						$account['plan'],
						null === $account['searches_left'] ? '?' : (string) $account['searches_left'],
						null === $account['this_month_usage'] ? '?' : (string) $account['this_month_usage']
					),
					60
				);
				$notice = 'done';
				break;

			case 'check_updates':
				$release = advtn()->updater()->force_check();

				if ( is_wp_error( $release ) ) {
					set_transient( 'advtn_admin_errors', array( $release->get_error_message() ), 60 );
					$notice = 'done';
					break;
				}

				set_transient(
					'advtn_admin_summary',
					version_compare( $release['version'], ADVTN_VERSION, '>' )
						? sprintf(
							/* translators: 1: available version, 2: installed version. */
							__( 'Version %1$s is available. You have %2$s — install it from the Plugins screen.', 'trending-now' ),
							$release['version'],
							ADVTN_VERSION
						)
						: sprintf(
							/* translators: %s: installed version. */
							__( 'Up to date. Latest release matches the installed version (%s).', 'trending-now' ),
							ADVTN_VERSION
						),
					60
				);
				$notice = 'done';
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
	 * Item-browser filters, read from the request.
	 *
	 * @return array<string,string>
	 */
	public function item_filters(): array {
		// phpcs:disable WordPress.Security.NonceVerification -- read-only filters; destructive use is nonce-checked by the caller.
		$request = 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ? $_POST : $_GET;

		$filters = array(
			'source_id'   => isset( $request['f_source'] ) ? sanitize_text_field( wp_unslash( $request['f_source'] ) ) : '',
			'host'        => isset( $request['f_host'] ) ? sanitize_text_field( wp_unslash( $request['f_host'] ) ) : '',
			'source_type' => isset( $request['f_type'] ) ? sanitize_key( wp_unslash( $request['f_type'] ) ) : '',
			'status'      => isset( $request['f_status'] ) ? sanitize_key( wp_unslash( $request['f_status'] ) ) : '',
			'search'      => isset( $request['f_search'] ) ? sanitize_text_field( wp_unslash( $request['f_search'] ) ) : '',
		);
		// phpcs:enable

		if ( ! in_array( $filters['status'], array( '', 'active', 'stale' ), true ) ) {
			$filters['status'] = '';
		}
		if ( ! in_array( $filters['source_type'], array( '', 'wp_rest', 'rss', 'serpapi', 'gdelt' ), true ) ) {
			$filters['source_type'] = '';
		}

		return $filters;
	}

	/**
	 * Current page of the item browser.
	 *
	 * @return int
	 */
	public function item_page(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- pagination only.
		return max( 1, (int) ( $_GET['item_page'] ?? 1 ) );
	}

	/**
	 * Human-readable rendering of an active filter set.
	 *
	 * @param array<string,string> $filters Filters.
	 * @return string
	 */
	private function describe_filters( array $filters ): string {
		$parts = array();

		foreach ( array_filter( $filters ) as $key => $value ) {
			$parts[] = $key . '=' . $value;
		}

		return empty( $parts ) ? __( 'everything', 'trending-now' ) : implode( ', ', $parts );
	}

	/**
	 * Delete rows by id and report the count.
	 *
	 * @param int[] $ids Item ids.
	 * @return int
	 */
	private function delete_items( array $ids ): int {
		$deleted = $this->repository->delete_by_ids( $ids );

		advtn()->selector()->forget( $ids );
		advtn()->renderer()->purge_cache();

		set_transient(
			'advtn_admin_summary',
			sprintf(
				/* translators: %d: rows deleted. */
				_n( 'Deleted %d item.', 'Deleted %d items.', $deleted, 'trending-now' ),
				$deleted
			),
			60
		);

		return $deleted;
	}

	/**
	 * Shared cleanup after a bulk delete.
	 *
	 * The stored selection may now point at rows that are gone, and the cached
	 * HTML certainly still shows them.
	 *
	 * @return void
	 */
	private function after_delete(): void {
		$live      = advtn()->selector()->current_ids();
		$surviving = array_column( $this->repository->get_by_ids( $live ), 'id' );

		advtn()->selector()->forget( array_diff( $live, array_map( 'intval', $surviving ) ) );
		advtn()->renderer()->purge_cache();
		delete_transient( 'advtn_archive_count' );
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
			'ingest_done'        => array( 'success', __( 'Ingest cycle ran and finished.', 'trending-now' ) ),
			'ingest_partial'     => array( 'warning', __( 'Ingest cycle finished, but at least one source failed.', 'trending-now' ) ),
			'locked'             => array( 'warning', __( 'An ingest cycle is already running.', 'trending-now' ) ),
			'selection_rebuilt'  => array( 'success', __( 'Selection rebuilt.', 'trending-now' ) ),
			'cache_purged'       => array( 'success', __( 'Render cache purged.', 'trending-now' ) ),
			'lock_released'      => array( 'success', __( 'Lock released.', 'trending-now' ) ),
			'log_cleared'        => array( 'success', __( 'Log cleared.', 'trending-now' ) ),
			'loopback_ok'        => array( 'success', __( 'Loopback request succeeded.', 'trending-now' ) ),
			'loopback_failed'    => array( 'error', __( 'Loopback request failed. Action Scheduler cannot run on this host until that is fixed.', 'trending-now' ) ),
			'secret_regenerated' => array( 'success', __( 'Secret regenerated. Update any external caller.', 'trending-now' ) ),
			'imported'           => array( 'success', __( 'Sources imported.', 'trending-now' ) ),
			'items_deleted'      => array( 'success', __( 'Items deleted.', 'trending-now' ) ),
			'nothing_selected'   => array( 'warning', __( 'No items were selected.', 'trending-now' ) ),
			'filter_required'    => array( 'warning', __( 'Narrow the list with a filter first — use "Delete everything" if you really mean all of it.', 'trending-now' ) ),
			'import_failed'      => array( 'error', __( 'Import failed. Nothing was changed.', 'trending-now' ) ),
		);

		if ( isset( $messages[ $notice ] ) ) {
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				esc_attr( $messages[ $notice ][0] ),
				esc_html( $messages[ $notice ][1] )
			);
		}

		$summary = get_transient( 'advtn_admin_summary' );
		if ( is_string( $summary ) && '' !== $summary ) {
			delete_transient( 'advtn_admin_summary' );
			printf( '<div class="notice notice-info"><p>%s</p></div>', esc_html( $summary ) );
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
