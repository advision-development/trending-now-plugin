<?php
/**
 * Public template tags for direct theme placement.
 *
 * @package Advision\TrendingNow
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'advtn_get_html' ) ) {
	/**
	 * Return the rendered widget HTML.
	 *
	 * @param array<string,mixed> $args Display arguments; see the shortcode.
	 * @return string
	 */
	function advtn_get_html( array $args = array() ): string {
		return advtn()->renderer()->render( $args );
	}
}

if ( ! function_exists( 'advtn_render' ) ) {
	/**
	 * Echo the rendered widget HTML.
	 *
	 * @param array<string,mixed> $args Display arguments; see the shortcode.
	 * @return void
	 */
	function advtn_render( array $args = array() ): void {
		// Output is fully escaped inside the templates.
		echo advtn_get_html( $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
