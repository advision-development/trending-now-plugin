<?php
/**
 * Manual links tab.
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

$advtn_manual = advtn()->manual();
$advtn_links  = $advtn_manual->all();
$advtn_limit  = $settings->get_int( 'widget_limit', 1, 200 );

/**
 * Render one curated link row.
 *
 * @param array<string,mixed> $link  Link row, or defaults for a blank row.
 * @param int                 $index Row index.
 * @param int                 $limit Widget limit, for the position hint.
 * @return void
 */
$advtn_render_link = static function ( array $link, int $index, int $limit ): void {
	$manual    = advtn()->manual();
	$expired   = $manual->is_expired( $link );
	$remaining = $manual->expires_in( $link );
	?>
	<div class="advtn-source advtn-manual<?php echo $expired ? ' is-expired' : ''; ?>">
		<div class="advtn-source__bar">
			<strong class="advtn-source__title"><?php echo esc_html( '' !== (string) ( $link['title'] ?? '' ) ? (string) $link['title'] : __( 'New link', 'trending-now' ) ); ?></strong>
			<?php if ( $expired ) : ?>
				<span class="advtn-badge advtn-badge--bad"><?php esc_html_e( 'expired', 'trending-now' ); ?></span>
			<?php elseif ( null !== $remaining ) : ?>
				<span class="advtn-badge advtn-badge--ok">
					<?php
					printf(
						/* translators: %s: human-readable duration, e.g. "2 days". */
						esc_html__( '%s left', 'trending-now' ),
						esc_html( human_time_diff( time(), time() + $remaining ) )
					);
					?>
				</span>
			<?php else : ?>
				<span class="advtn-badge"><?php esc_html_e( 'no expiry', 'trending-now' ); ?></span>
			<?php endif; ?>
			<span class="advtn-source__spacer"></span>
			<?php if ( ! empty( $link['position'] ) ) : ?>
				<span class="advtn-source__count">
					<?php
					printf(
						/* translators: %d: slot number. */
						esc_html__( 'slot %d', 'trending-now' ),
						(int) $link['position']
					);
					?>
				</span>
			<?php endif; ?>
		</div>

		<input type="hidden" name="links[<?php echo esc_attr( (string) $index ); ?>][id]" value="<?php echo esc_attr( (string) ( $link['id'] ?? '' ) ); ?>" />
		<input type="hidden" name="links[<?php echo esc_attr( (string) $index ); ?>][created_at]" value="<?php echo esc_attr( (string) ( $link['created_at'] ?? '' ) ); ?>" />

		<div class="advtn-source__grid">
			<label class="advtn-wide">
				<span><?php esc_html_e( 'URL', 'trending-now' ); ?> <em class="advtn-req">*</em></span>
				<input type="url" name="links[<?php echo esc_attr( (string) $index ); ?>][url]" value="<?php echo esc_attr( (string) ( $link['url'] ?? '' ) ); ?>" placeholder="https://example.com/article/" />
				<em><?php esc_html_e( 'Links to this site are allowed here — unlike ingested sources, a curated self-link is a deliberate choice.', 'trending-now' ); ?></em>
			</label>

			<label class="advtn-wide">
				<span><?php esc_html_e( 'Title', 'trending-now' ); ?> <em class="advtn-req">*</em></span>
				<input type="text" name="links[<?php echo esc_attr( (string) $index ); ?>][title]" value="<?php echo esc_attr( (string) ( $link['title'] ?? '' ) ); ?>" />
				<em><?php esc_html_e( 'Becomes the anchor text.', 'trending-now' ); ?></em>
			</label>

			<label class="advtn-wide">
				<span><?php esc_html_e( 'Excerpt', 'trending-now' ); ?></span>
				<input type="text" name="links[<?php echo esc_attr( (string) $index ); ?>][excerpt]" value="<?php echo esc_attr( (string) ( $link['excerpt'] ?? '' ) ); ?>" />
			</label>

			<label class="advtn-wide">
				<span><?php esc_html_e( 'Image URL', 'trending-now' ); ?></span>
				<input type="url" name="links[<?php echo esc_attr( (string) $index ); ?>][image_url]" value="<?php echo esc_attr( (string) ( $link['image_url'] ?? '' ) ); ?>" />
			</label>

			<label>
				<span><?php esc_html_e( 'Source name', 'trending-now' ); ?></span>
				<input type="text" name="links[<?php echo esc_attr( (string) $index ); ?>][site_name]" value="<?php echo esc_attr( (string) ( $link['site_name'] ?? '' ) ); ?>" placeholder="<?php esc_attr_e( 'defaults to the host', 'trending-now' ); ?>" />
			</label>

			<label>
				<span><?php esc_html_e( 'Published', 'trending-now' ); ?></span>
				<input type="datetime-local" name="links[<?php echo esc_attr( (string) $index ); ?>][published_at]" value="<?php echo esc_attr( str_replace( ' ', 'T', substr( (string) ( $link['published_at'] ?? '' ), 0, 16 ) ) ); ?>" />
				<em><?php esc_html_e( 'UTC. Defaults to when it was added.', 'trending-now' ); ?></em>
			</label>

			<label>
				<span><?php esc_html_e( 'Position', 'trending-now' ); ?></span>
				<input type="number" min="0" max="200" name="links[<?php echo esc_attr( (string) $index ); ?>][position]" value="<?php echo esc_attr( (string) ( $link['position'] ?? 0 ) ); ?>" />
				<em>
					<?php
					printf(
						/* translators: %d: widget limit. */
						esc_html__( 'Slot in the list, 1 = first. 0 lets it fall where it may. The widget shows %d.', 'trending-now' ),
						(int) $limit
					);
					?>
				</em>
			</label>

			<label class="advtn-wide advtn-expiry">
				<span><?php esc_html_e( 'Expires', 'trending-now' ); ?></span>
				<input type="datetime-local" class="advtn-expires" name="links[<?php echo esc_attr( (string) $index ); ?>][expires_at]" value="<?php echo esc_attr( str_replace( ' ', 'T', substr( (string) ( $link['expires_at'] ?? '' ), 0, 16 ) ) ); ?>" />
				<span class="advtn-expiry__presets">
					<button type="button" class="button button-small advtn-expiry-set" data-hours="24"><?php esc_html_e( '+1 day', 'trending-now' ); ?></button>
					<button type="button" class="button button-small advtn-expiry-set" data-hours="72"><?php esc_html_e( '+3 days', 'trending-now' ); ?></button>
					<button type="button" class="button button-small advtn-expiry-set" data-hours="168"><?php esc_html_e( '+1 week', 'trending-now' ); ?></button>
					<button type="button" class="button button-small advtn-expiry-set" data-hours="720"><?php esc_html_e( '+30 days', 'trending-now' ); ?></button>
					<button type="button" class="button button-small advtn-expiry-clear"><?php esc_html_e( 'Never', 'trending-now' ); ?></button>
				</span>
				<em><?php esc_html_e( 'UTC. Leave empty to keep it up indefinitely. The list is rebuilt automatically the moment a link expires, rather than waiting for the next ingest.', 'trending-now' ); ?></em>
			</label>

			<label class="advtn-check">
				<input type="checkbox" name="links[<?php echo esc_attr( (string) $index ); ?>][enabled]" value="1" <?php checked( ! empty( $link['enabled'] ) ); ?> />
				<span><?php esc_html_e( 'Enabled', 'trending-now' ); ?></span>
			</label>
		</div>

		<div class="advtn-source__foot">
			<label class="advtn-delete"><input type="checkbox" name="links[<?php echo esc_attr( (string) $index ); ?>][_delete]" value="1" /> <?php esc_html_e( 'Delete on save', 'trending-now' ); ?></label>
		</div>
	</div>
	<?php
};
?>

<p class="description">
	<?php esc_html_e( 'Hand-picked links, mixed into the same list as ingested ones. They are stored alongside everything else, so they appear in the archive, are deduplicated against anything a source also finds, and are never dropped by the staleness sweep while they are on this list.', 'trending-now' ); ?>
</p>

<?php if ( ! empty( $advtn_links ) ) : ?>
	<p class="description">
		<?php
		printf(
			/* translators: 1: active link count, 2: widget limit. */
			esc_html__( '%1$d of these are live right now, taking %1$d of the widget\'s %2$d slots.', 'trending-now' ),
			count( $advtn_manual->active() ),
			(int) $advtn_limit
		);
		?>
	</p>
<?php endif; ?>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="advtn-form" id="advtn-manual-form">
	<?php wp_nonce_field( 'advtn_save_manual' ); ?>
	<input type="hidden" name="action" value="advtn_save_manual" />

	<div id="advtn-manual-links">
		<?php
		foreach ( $advtn_links as $advtn_index => $advtn_link ) {
			$advtn_render_link( $advtn_link, (int) $advtn_index, $advtn_limit );
		}
		?>
	</div>

	<template id="advtn-manual-template">
		<?php $advtn_render_link( array( 'enabled' => true, 'position' => 0 ), 9999, $advtn_limit ); ?>
	</template>

	<p>
		<button type="button" class="button" id="advtn-add-link"><?php esc_html_e( 'Add link', 'trending-now' ); ?></button>
	</p>

	<?php submit_button( __( 'Save links', 'trending-now' ) ); ?>
</form>
