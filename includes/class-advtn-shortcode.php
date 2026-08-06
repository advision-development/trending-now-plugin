<?php
/**
 * [trending_now] shortcode.
 *
 * Elementor, Bricks, Gutenberg, widgets and theme templates all accept
 * shortcodes, which is why no page-builder-native widget APIs are used.
 *
 * @package Advision\TrendingNow
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ADVTN_Shortcode {

	public const TAG = 'trending_now';

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
	 * Register the shortcode.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_shortcode( self::TAG, array( $this, 'render' ) );
	}

	/**
	 * Shortcode callback.
	 *
	 * @param array<string,mixed>|string $atts Raw attributes.
	 * @return string
	 */
	public function render( $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'limit'        => '',
				'layout'       => 'list',
				'heading'      => '',
				'show_images'  => '0',
				'show_source'  => '1',
				'show_date'    => '1',
				'show_see_all' => '1',
			),
			is_array( $atts ) ? $atts : array(),
			self::TAG
		);

		$args = array(
			'layout'       => (string) $atts['layout'],
			'show_images'  => $atts['show_images'],
			'show_source'  => $atts['show_source'],
			'show_date'    => $atts['show_date'],
			'show_see_all' => $atts['show_see_all'],
		);

		// Empty attributes fall through to the configured defaults rather than
		// creating a distinct cache variant.
		if ( '' !== (string) $atts['limit'] ) {
			$args['limit'] = (int) $atts['limit'];
		}
		if ( '' !== (string) $atts['heading'] ) {
			$args['heading'] = (string) $atts['heading'];
		}

		return $this->renderer->render( $args );
	}
}
