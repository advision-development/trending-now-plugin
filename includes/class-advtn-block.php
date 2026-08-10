<?php
/**
 * Gutenberg block. A thin wrapper around the renderer with no rendering logic
 * of its own.
 *
 * @package Advision\TrendingNow
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ADVTN_Block {

	/**
	 * Renderer service.
	 *
	 * @var ADVTN_Renderer
	 */
	private ADVTN_Renderer $renderer;

	/**
	 * Constructor.
	 *
	 * @param ADVTN_Renderer $renderer Renderer service.
	 */
	public function __construct( ADVTN_Renderer $renderer ) {
		$this->renderer = $renderer;
	}

	/**
	 * Register the block type on init.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'init', array( $this, 'register_block' ) );
	}

	/**
	 * Register from block.json with a PHP render callback.
	 *
	 * @return void
	 */
	public function register_block(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		$metadata = ADVTN_PATH . 'blocks/trending-now';

		if ( ! is_readable( $metadata . '/block.json' ) ) {
			return;
		}

		// Registered by handle so the editor dependencies are explicit; the
		// script is build-free, so there is no generated .asset.php to read.
		wp_register_script(
			'advtn-block-editor',
			ADVTN_PLUGIN_URL . 'blocks/trending-now/index.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render', 'wp-i18n' ),
			ADVTN_VERSION,
			true
		);

		register_block_type(
			$metadata,
			array( 'render_callback' => array( $this, 'render' ) )
		);
	}

	/**
	 * Block render callback.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @return string
	 */
	public function render( array $attributes = array() ): string {
		// The block previews through wp-server-side-render, which reaches this
		// callback over REST carrying the editor's own URL — so gating there
		// would render the block blank while it is being edited and read as
		// broken. But REST_REQUEST alone is not scoped to that preview: it is
		// set for the whole request lifecycle on any /wp-json/* route,
		// including anonymous reads like GET /wp/v2/pages/<id>?context=view
		// that return this same rendered block. Requiring edit_posts closes
		// that gap, because the block-renderer endpoint the editor calls
		// already requires it in its own permission callback. is_admin()
		// separately covers admin-rendered contexts, such as the Widgets
		// screen, where REST_REQUEST is not set at all.
		$advtn_editing = ( defined( 'REST_REQUEST' ) && REST_REQUEST && current_user_can( 'edit_posts' ) ) || is_admin();

		if ( ! $advtn_editing && ! ADVTN_Path_Match::matches( (string) ( $attributes['matchPath'] ?? '' ) ) ) {
			return '';
		}

		$args = array(
			'show_see_all' => ! isset( $attributes['showSeeAll'] ) || (bool) $attributes['showSeeAll'],
		);

		// Unset attributes inherit the Settings defaults.
		if ( ! empty( $attributes['layout'] ) ) {
			$args['layout'] = (string) $attributes['layout'];
		}
		foreach ( array( 'showImages' => 'show_images', 'showSource' => 'show_source', 'showDate' => 'show_date', 'showIcons' => 'show_icons', 'showExcerpt' => 'show_excerpt' ) as $attr => $key ) {
			if ( isset( $attributes[ $attr ] ) ) {
				$args[ $key ] = (bool) $attributes[ $attr ];
			}
		}

		if ( ! empty( $attributes['limit'] ) ) {
			$args['limit'] = (int) $attributes['limit'];
		}
		if ( ! empty( $attributes['heading'] ) ) {
			$args['heading'] = (string) $attributes['heading'];
		}

		return $this->renderer->render( $args );
	}
}
