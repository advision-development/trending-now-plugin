<?php
/**
 * Sources tab.
 *
 * @var ADVTN_Admin      $admin      Admin controller.
 * @var ADVTN_Settings   $settings   Settings service.
 * @var ADVTN_Repository $repository Repository service.
 *
 * @package Advision\TrendingNow
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$advtn_sources = $settings->sources();
$advtn_state   = $settings->state();
$advtn_counts  = $repository->counts_by_source();

/**
 * Render one source row.
 *
 * @param array<string,mixed> $source Source row, or defaults for a blank row.
 * @param int                 $index  Row index.
 * @param array<string,mixed> $state  Runtime state for this source.
 * @param int                 $items  Stored item count for this source.
 * @return void
 */
$advtn_render_row = static function ( array $source, int $index, array $state = array(), int $items = 0 ): void {
	$type    = (string) ( $source['type'] ?? 'wp_rest' );
	$id      = (string) ( $source['id'] ?? '' );
	$domains = (array) ( $source['gdelt_domains'] ?? array() );
	?>
	<div class="advtn-source" draggable="true" data-index="<?php echo esc_attr( (string) $index ); ?>">
		<div class="advtn-source__bar">
			<span class="advtn-source__handle" aria-hidden="true">⋮⋮</span>
			<strong class="advtn-source__title"><?php echo esc_html( '' !== (string) ( $source['label'] ?? '' ) ? (string) $source['label'] : __( 'New source', 'trending-now' ) ); ?></strong>
			<?php if ( '' !== $id ) : ?>
				<code class="advtn-source__id"><?php echo esc_html( $id ); ?></code>
				<span class="advtn-source__count">
					<?php
					printf(
						/* translators: %d: stored item count. */
						esc_html__( '%d stored', 'trending-now' ),
						(int) $items
					);
					?>
				</span>
			<?php endif; ?>
			<span class="advtn-source__spacer"></span>
			<button type="button" class="button-link advtn-move" data-dir="up" title="<?php esc_attr_e( 'Move up', 'trending-now' ); ?>">&uarr;</button>
			<button type="button" class="button-link advtn-move" data-dir="down" title="<?php esc_attr_e( 'Move down', 'trending-now' ); ?>">&darr;</button>
		</div>

		<input type="hidden" name="sources[<?php echo esc_attr( (string) $index ); ?>][id]" value="<?php echo esc_attr( $id ); ?>" />
		<input type="hidden" class="advtn-order" name="sources[<?php echo esc_attr( (string) $index ); ?>][order]" value="<?php echo esc_attr( (string) $index ); ?>" />

		<div class="advtn-source__grid">
			<label>
				<span><?php esc_html_e( 'Label', 'trending-now' ); ?></span>
				<input type="text" name="sources[<?php echo esc_attr( (string) $index ); ?>][label]" value="<?php echo esc_attr( (string) ( $source['label'] ?? '' ) ); ?>" />
			</label>

			<label>
				<span><?php esc_html_e( 'Type', 'trending-now' ); ?></span>
				<select class="advtn-type" name="sources[<?php echo esc_attr( (string) $index ); ?>][type]">
					<option value="wp_rest" <?php selected( $type, 'wp_rest' ); ?>><?php esc_html_e( 'WordPress REST API', 'trending-now' ); ?></option>
					<option value="rss" <?php selected( $type, 'rss' ); ?>><?php esc_html_e( 'RSS / Atom feed', 'trending-now' ); ?></option>
					<option value="gdelt" <?php selected( $type, 'gdelt' ); ?>><?php esc_html_e( 'GDELT news', 'trending-now' ); ?></option>
				</select>
			</label>

			<label>
				<span><?php esc_html_e( 'Items per cycle', 'trending-now' ); ?></span>
				<input type="number" min="1" max="250" name="sources[<?php echo esc_attr( (string) $index ); ?>][limit]" value="<?php echo esc_attr( (string) ( $source['limit'] ?? 10 ) ); ?>" />
			</label>

			<label class="advtn-check">
				<input type="checkbox" name="sources[<?php echo esc_attr( (string) $index ); ?>][enabled]" value="1" <?php checked( ! empty( $source['enabled'] ) ); ?> />
				<span><?php esc_html_e( 'Enabled', 'trending-now' ); ?></span>
			</label>

			<label class="advtn-field-url advtn-wide">
				<span><?php esc_html_e( 'URL', 'trending-now' ); ?></span>
				<input type="url" name="sources[<?php echo esc_attr( (string) $index ); ?>][url]" value="<?php echo esc_attr( (string) ( $source['url'] ?? '' ) ); ?>" placeholder="https://example.com" />
				<em><?php esc_html_e( 'Site root for REST sources; full feed URL for RSS.', 'trending-now' ); ?></em>
			</label>

			<label class="advtn-field-gdelt advtn-wide">
				<span><?php esc_html_e( 'GDELT query', 'trending-now' ); ?></span>
				<input type="text" name="sources[<?php echo esc_attr( (string) $index ); ?>][gdelt_query]" value="<?php echo esc_attr( (string) ( $source['gdelt_query'] ?? '' ) ); ?>" placeholder='sourcelang:english (sportsbook OR "betting odds")' />
			</label>

			<label class="advtn-field-gdelt advtn-wide">
				<span><?php esc_html_e( 'Allowed domains', 'trending-now' ); ?></span>
				<input type="text" name="sources[<?php echo esc_attr( (string) $index ); ?>][gdelt_domains]" value="<?php echo esc_attr( implode( ', ', array_map( 'strval', $domains ) ) ); ?>" placeholder="espn.com, cbssports.com, si.com" />
				<em><?php esc_html_e( 'Comma separated. Enforced again after the response, because the query language is fuzzy.', 'trending-now' ); ?></em>
			</label>

			<label class="advtn-field-gdelt">
				<span><?php esc_html_e( 'Timespan', 'trending-now' ); ?></span>
				<input type="text" name="sources[<?php echo esc_attr( (string) $index ); ?>][gdelt_timespan]" value="<?php echo esc_attr( (string) ( $source['gdelt_timespan'] ?? '2d' ) ); ?>" placeholder="2d" />
			</label>
		</div>

		<div class="advtn-source__foot">
			<button type="button" class="button advtn-test"><?php esc_html_e( 'Test fetch', 'trending-now' ); ?></button>
			<label class="advtn-delete"><input type="checkbox" name="sources[<?php echo esc_attr( (string) $index ); ?>][_delete]" value="1" /> <?php esc_html_e( 'Delete on save', 'trending-now' ); ?></label>
			<?php if ( ! empty( $state['last_error'] ) ) : ?>
				<span class="advtn-source__error"><?php echo esc_html( (string) $state['last_error'] ); ?></span>
			<?php endif; ?>
		</div>

		<div class="advtn-test-result" hidden></div>
	</div>
	<?php
};
?>
<p class="description">
	<?php esc_html_e( 'Order sets the ingest stagger: the first source runs immediately, each later source waits another stagger interval. Do not configure every network site on every install — each site should carry a partially overlapping subset.', 'trending-now' ); ?>
</p>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="advtn-form" id="advtn-sources-form">
	<?php wp_nonce_field( 'advtn_save_sources' ); ?>
	<input type="hidden" name="action" value="advtn_save_sources" />

	<div id="advtn-sources">
		<?php
		foreach ( $advtn_sources as $advtn_index => $advtn_source ) {
			$advtn_id = (string) ( $advtn_source['id'] ?? '' );
			$advtn_render_row(
				$advtn_source,
				(int) $advtn_index,
				isset( $advtn_state[ $advtn_id ] ) ? (array) $advtn_state[ $advtn_id ] : array(),
				(int) ( $advtn_counts[ $advtn_id ] ?? 0 )
			);
		}
		?>
	</div>

	<template id="advtn-source-template">
		<?php
		// Blank id on purpose: the template is cloned client-side, so a baked-in
		// id would be shared by every added row. validate_config() mints one
		// per row on save.
		$advtn_render_row(
			array(
				'id'      => '',
				'type'    => 'wp_rest',
				'enabled' => true,
				'limit'   => 10,
			),
			9999
		);
		?>
	</template>

	<p>
		<button type="button" class="button" id="advtn-add-source"><?php esc_html_e( 'Add source', 'trending-now' ); ?></button>
	</p>

	<?php submit_button( __( 'Save sources', 'trending-now' ) ); ?>
</form>
