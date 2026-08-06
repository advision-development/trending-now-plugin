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
		$args = array(
			'layout'       => (string) ( $attributes['layout'] ?? 'list' ),
			'show_images'  => ! empty( $attributes['showImages'] ),
			'show_source'  => ! isset( $attributes['showSource'] ) || (bool) $attributes['showSource'],
			'show_date'    => ! isset( $attributes['showDate'] ) || (bool) $attributes['showDate'],
			'show_see_all' => ! isset( $attributes['showSeeAll'] ) || (bool) $attributes['showSeeAll'],
		);

		if ( ! empty( $attributes['limit'] ) ) {
			$args['limit'] = (int) $attributes['limit'];
		}
		if ( ! empty( $attributes['heading'] ) ) {
			$args['heading'] = (string) $attributes['heading'];
		}

		return $this->renderer->render( $args );
	}
}
