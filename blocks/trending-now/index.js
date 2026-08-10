/**
 * Editor registration for the Trending Now block.
 *
 * Deliberately build-free: no JSX, no bundler. The block is a thin wrapper —
 * all rendering happens in PHP via ServerSideRender.
 */
( function ( blocks, element, blockEditor, components, ServerSideRender, i18n ) {
	'use strict';

	var el = element.createElement;
	var __ = i18n.__;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelBody = components.PanelBody;
	var TextControl = components.TextControl;
	var SelectControl = components.SelectControl;
	var ToggleControl = components.ToggleControl;

	blocks.registerBlockType( 'advision/trending-now', {
		edit: function ( props ) {
			var attributes = props.attributes;

			function set( key ) {
				return function ( value ) {
					var patch = {};
					patch[ key ] = value;
					props.setAttributes( patch );
				};
			}

			return el(
				element.Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Trending Now', 'trending-now' ) },
						el( TextControl, {
							label: __( 'Heading', 'trending-now' ),
							value: attributes.heading || '',
							onChange: set( 'heading' ),
							help: __( 'Leave empty to use the plugin default.', 'trending-now' ),
						} ),
						el( TextControl, {
							label: __( 'Number of links', 'trending-now' ),
							type: 'number',
							value: attributes.limit || '',
							onChange: function ( value ) {
								props.setAttributes( { limit: value === '' ? undefined : parseInt( value, 10 ) } );
							},
							help: __( 'Leave empty to use the plugin default.', 'trending-now' ),
						} ),
						el( SelectControl, {
							label: __( 'Layout', 'trending-now' ),
							value: attributes.layout,
							options: [
								{ label: __( 'List', 'trending-now' ), value: 'list' },
								{ label: __( 'Cards', 'trending-now' ), value: 'cards' },
							],
							onChange: set( 'layout' ),
						} ),
						el( ToggleControl, {
							label: __( 'Show images', 'trending-now' ),
							checked: !! attributes.showImages,
							onChange: set( 'showImages' ),
						} ),
						el( ToggleControl, {
							label: __( 'Show source', 'trending-now' ),
							checked: !! attributes.showSource,
							onChange: set( 'showSource' ),
						} ),
						el( ToggleControl, {
							label: __( 'Show date', 'trending-now' ),
							checked: !! attributes.showDate,
							onChange: set( 'showDate' ),
						} ),
						el( ToggleControl, {
							label: __( 'Show "see all" link', 'trending-now' ),
							checked: !! attributes.showSeeAll,
							onChange: set( 'showSeeAll' ),
						} ),
						el( TextControl, {
							label: __( 'Only show on these paths', 'trending-now' ),
							value: attributes.matchPath || '',
							onChange: set( 'matchPath' ),
							help: __( 'Comma-separated, e.g. /,/archive. Leave empty to show everywhere. Matching is exact, so /archive does not cover /archive/page/2/.', 'trending-now' ),
						} )
					)
				),
				el(
					'div',
					blockEditor.useBlockProps ? blockEditor.useBlockProps() : {},
					el( ServerSideRender, {
						block: 'advision/trending-now',
						attributes: attributes,
					} )
				)
			);
		},

		// Rendered in PHP.
		save: function () {
			return null;
		},
	} );
} )(
	window.wp.blocks,
	window.wp.element,
	window.wp.blockEditor,
	window.wp.components,
	window.wp.serverSideRender,
	window.wp.i18n
);
