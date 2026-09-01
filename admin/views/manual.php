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

$advtn_feed         = advtn()->manual_feed();
$advtn_feed_state   = $advtn_feed->state();
$advtn_feed_active  = $settings->feed_is_active();
$advtn_feed_next    = $advtn_feed->next_due();
$advtn_feed_ring    = isset( $advtn_feed_state['attempts'] ) && is_array( $advtn_feed_state['attempts'] ) ? $advtn_feed_state['attempts'] : array();
$advtn_feed_summary = ADVTN_Attempts::summary( $advtn_feed_ring );

/**
 * Render one curated link row.
 *
 * @param array<string,mixed> $link  Link row, or defaults for a blank row.
 * @param int                 $index Row index.
 * @param int                 $limit Widget limit, for the position hint.
 * @return void
 */
$advtn_render_link = static function ( array $link, int $index, int $limit, bool $locked = false ): void {
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
				<input type="url" name="links[<?php echo esc_attr( (string) $index ); ?>][url]"<?php disabled( $locked ); ?> value="<?php echo esc_attr( (string) ( $link['url'] ?? '' ) ); ?>" placeholder="https://example.com/article/" />
				<em><?php esc_html_e( 'Links to this site are allowed here — unlike ingested sources, a curated self-link is a deliberate choice.', 'trending-now' ); ?></em>
			</label>

			<label class="advtn-wide">
				<span><?php esc_html_e( 'Title', 'trending-now' ); ?> <em class="advtn-req">*</em></span>
				<input type="text" name="links[<?php echo esc_attr( (string) $index ); ?>][title]"<?php disabled( $locked ); ?> value="<?php echo esc_attr( (string) ( $link['title'] ?? '' ) ); ?>" />
				<em><?php esc_html_e( 'Becomes the anchor text.', 'trending-now' ); ?></em>
			</label>

			<label class="advtn-wide">
				<span><?php esc_html_e( 'Excerpt', 'trending-now' ); ?></span>
				<input type="text" name="links[<?php echo esc_attr( (string) $index ); ?>][excerpt]"<?php disabled( $locked ); ?> value="<?php echo esc_attr( (string) ( $link['excerpt'] ?? '' ) ); ?>" />
			</label>

			<label class="advtn-wide">
				<span><?php esc_html_e( 'Image URL', 'trending-now' ); ?></span>
				<input type="url" name="links[<?php echo esc_attr( (string) $index ); ?>][image_url]"<?php disabled( $locked ); ?> value="<?php echo esc_attr( (string) ( $link['image_url'] ?? '' ) ); ?>" />
			</label>

			<label>
				<span><?php esc_html_e( 'Source name', 'trending-now' ); ?></span>
				<input type="text" name="links[<?php echo esc_attr( (string) $index ); ?>][site_name]"<?php disabled( $locked ); ?> value="<?php echo esc_attr( (string) ( $link['site_name'] ?? '' ) ); ?>" placeholder="<?php esc_attr_e( 'defaults to the host', 'trending-now' ); ?>" />
			</label>

			<label>
				<span><?php esc_html_e( 'Published', 'trending-now' ); ?></span>
				<input type="datetime-local" name="links[<?php echo esc_attr( (string) $index ); ?>][published_at]"<?php disabled( $locked ); ?> value="<?php echo esc_attr( str_replace( ' ', 'T', substr( (string) ( $link['published_at'] ?? '' ), 0, 16 ) ) ); ?>" />
				<em><?php esc_html_e( 'UTC. Defaults to when it was added.', 'trending-now' ); ?></em>
			</label>

			<label>
				<span><?php esc_html_e( 'Position', 'trending-now' ); ?></span>
				<input type="number" min="0" max="200" name="links[<?php echo esc_attr( (string) $index ); ?>][position]"<?php disabled( $locked ); ?> value="<?php echo esc_attr( (string) ( $link['position'] ?? 0 ) ); ?>" />
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
				<input type="datetime-local" class="advtn-expires" name="links[<?php echo esc_attr( (string) $index ); ?>][expires_at]"<?php disabled( $locked ); ?> value="<?php echo esc_attr( str_replace( ' ', 'T', substr( (string) ( $link['expires_at'] ?? '' ), 0, 16 ) ) ); ?>" />
				<span class="advtn-expiry__presets">
					<button type="button"<?php disabled( $locked ); ?> class="button button-small advtn-expiry-set" data-hours="24"><?php esc_html_e( '+1 day', 'trending-now' ); ?></button>
					<button type="button"<?php disabled( $locked ); ?> class="button button-small advtn-expiry-set" data-hours="72"><?php esc_html_e( '+3 days', 'trending-now' ); ?></button>
					<button type="button"<?php disabled( $locked ); ?> class="button button-small advtn-expiry-set" data-hours="168"><?php esc_html_e( '+1 week', 'trending-now' ); ?></button>
					<button type="button"<?php disabled( $locked ); ?> class="button button-small advtn-expiry-set" data-hours="720"><?php esc_html_e( '+30 days', 'trending-now' ); ?></button>
					<button type="button"<?php disabled( $locked ); ?> class="button button-small advtn-expiry-clear"><?php esc_html_e( 'Never', 'trending-now' ); ?></button>
				</span>
				<em><?php esc_html_e( 'UTC. Leave empty to keep it up indefinitely. The list is rebuilt automatically the moment a link expires, rather than waiting for the next ingest.', 'trending-now' ); ?></em>
			</label>

			<label class="advtn-check">
				<input type="checkbox" name="links[<?php echo esc_attr( (string) $index ); ?>][enabled]"<?php disabled( $locked ); ?> value="1" <?php checked( ! empty( $link['enabled'] ) ); ?> />
				<span><?php esc_html_e( 'Enabled', 'trending-now' ); ?></span>
			</label>
		</div>

		<div class="advtn-source__foot">
			<label class="advtn-delete"><input type="checkbox" name="links[<?php echo esc_attr( (string) $index ); ?>][_delete]"<?php disabled( $locked ); ?> value="1" /> <?php esc_html_e( 'Delete on save', 'trending-now' ); ?></label>
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

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="advtn-form">
	<?php wp_nonce_field( 'advtn_save_feed' ); ?>
	<input type="hidden" name="action" value="advtn_save_feed" />

	<h2><?php esc_html_e( 'Feed subscription', 'trending-now' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Subscribe this site to a curated list maintained elsewhere. While subscribed, the links below are a read-only mirror of that list and are replaced on every fetch.', 'trending-now' ); ?>
	</p>

	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="advtn-feed-url"><?php esc_html_e( 'Feed URL', 'trending-now' ); ?></label></th>
			<td>
				<input type="url" class="large-text code" id="advtn-feed-url" name="manual_feed_url"
					value="<?php echo esc_attr( $settings->get_string( 'manual_feed_url' ) ); ?>"
					placeholder="https://hawkeye-advision.web.app/trending/feed?feed=pbn-tier1" />
				<p class="description"><?php esc_html_e( 'The whole address, including the part naming the feed. Copy it from the feed’s own page.', 'trending-now' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="advtn-feed-token"><?php esc_html_e( 'Auth token', 'trending-now' ); ?></label></th>
			<td>
				<?php if ( $settings->secret_is_constant( 'manual_feed_token' ) ) : ?>
					<input type="text" class="regular-text" value="<?php esc_attr_e( 'Set in wp-config.php', 'trending-now' ); ?>" disabled />
				<?php else : ?>
					<input type="password" class="regular-text" id="advtn-feed-token" name="manual_feed_token"
						value="<?php echo esc_attr( $settings->get_string( 'manual_feed_token' ) ); ?>" autocomplete="off" />
				<?php endif; ?>
				<p class="description">
					<?php esc_html_e( 'Sent as a bearer token. Leave it empty for a public feed — no header is sent at all then. ADVTN_MANUAL_FEED_TOKEN in wp-config.php overrides this.', 'trending-now' ); ?>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="advtn-feed-interval"><?php esc_html_e( 'Fetch every', 'trending-now' ); ?></label></th>
			<td>
				<input type="number" min="1" max="168" step="1" id="advtn-feed-interval" name="manual_feed_interval_hours"
					value="<?php echo esc_attr( (string) $settings->get_int( 'manual_feed_interval_hours', 1, 168 ) ); ?>" />
				<?php esc_html_e( 'hours', 'trending-now' ); ?>
				<p class="description">
					<?php esc_html_e( 'At least this often, not exactly. WordPress runs its schedule on pageviews, so a missed window runs late rather than being skipped.', 'trending-now' ); ?>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Subscribed', 'trending-now' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="manual_feed_enabled" value="1" <?php checked( $settings->get_bool( 'manual_feed_enabled' ) ); ?> />
					<?php esc_html_e( 'Replace this site’s curated links with the feed', 'trending-now' ); ?>
				</label>
				<p class="description"><?php esc_html_e( 'Unticking this leaves the current links in place and editable again. Nothing disappears from the front end.', 'trending-now' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Push credential', 'trending-now' ); ?></th>
			<td>
				<?php if ( '' === $settings->get_string( 'sync_key' ) ) : ?>
					<p class="description"><?php esc_html_e( 'None yet. This site invents one the first time it fetches a feed, and the feed learns it from that request — there is nothing to copy anywhere.', 'trending-now' ); ?></p>
				<?php else : ?>
					<p class="description"><?php esc_html_e( 'In place. It lets the feed tell this site to re-read now instead of waiting for the timer, and it can do nothing else — not trigger an ingest, not read this site\'s sources.', 'trending-now' ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
	</table>

	<?php if ( ! empty( $advtn_feed_state ) ) : ?>
		<ul class="advtn-feed-status">
			<li>
				<?php esc_html_e( 'Last fetch:', 'trending-now' ); ?>
				<strong><?php echo esc_html( (string) ( $advtn_feed_state['last_attempt_at'] ?? '—' ) ); ?></strong>
				<?php if ( isset( $advtn_feed_state['http_code'] ) && null !== $advtn_feed_state['http_code'] ) : ?>
					<span class="advtn-badge"><?php echo esc_html( 'HTTP ' . (int) $advtn_feed_state['http_code'] ); ?></span>
				<?php endif; ?>
			</li>
			<li>
				<?php esc_html_e( 'Links stored:', 'trending-now' ); ?>
				<strong><?php echo esc_html( (string) (int) ( $advtn_feed_state['item_count'] ?? 0 ) ); ?></strong>
			</li>
			<li>
				<?php esc_html_e( 'Next fetch:', 'trending-now' ); ?>
				<strong>
					<?php
					echo esc_html(
						null === $advtn_feed_next
							? __( 'due now', 'trending-now' )
							/* translators: %s: human-readable duration. */
							: sprintf( __( 'in %s', 'trending-now' ), human_time_diff( time(), $advtn_feed_next ) )
					);
					?>
				</strong>
			</li>
			<?php if ( ! empty( $advtn_feed_state['error'] ) ) : ?>
				<li>
					<span class="advtn-badge advtn-badge--bad"><?php esc_html_e( 'last error', 'trending-now' ); ?></span>
					<?php echo esc_html( (string) $advtn_feed_state['error'] ); ?>
				</li>
			<?php endif; ?>
			<?php if ( $advtn_feed_summary['count'] > 0 ) : ?>
				<li class="description">
					<?php
					printf(
						/* translators: 1: attempt count, 2: median ms, 3: max ms. */
						esc_html__( '%1$d recent attempts, median %2$dms, max %3$dms', 'trending-now' ),
						(int) $advtn_feed_summary['count'],
						(int) $advtn_feed_summary['p50'],
						(int) $advtn_feed_summary['max']
					);
					?>
				</li>
			<?php endif; ?>
		</ul>
	<?php endif; ?>

	<?php submit_button( __( 'Save subscription', 'trending-now' ), 'primary', 'submit', false ); ?>
</form>

<?php if ( '' !== $settings->get_string( 'sync_key' ) ) : ?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="advtn-inline-form" style="margin: 12px 0 20px;">
		<?php wp_nonce_field( 'advtn_action' ); ?>
		<input type="hidden" name="action" value="advtn_action" />
		<button type="submit" class="button advtn-confirm" name="advtn_do" value="regenerate_sync_key"><?php esc_html_e( 'Replace push credential', 'trending-now' ); ?></button>
		<span class="description"><?php esc_html_e( 'Replace it if you think it leaked. The old one keeps working until the feed learns the new one, which happens on this site\'s next fetch.', 'trending-now' ); ?></span>
	</form>
<?php endif; ?>

<?php if ( $advtn_feed_active ) : ?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin: 12px 0 20px;">
		<?php wp_nonce_field( 'advtn_fetch_feed' ); ?>
		<input type="hidden" name="action" value="advtn_fetch_feed" />
		<?php submit_button( __( 'Fetch now', 'trending-now' ), 'secondary', 'submit', false ); ?>
		<span class="description"><?php esc_html_e( 'Ignores the interval and fetches immediately.', 'trending-now' ); ?></span>
	</form>

	<div class="notice notice-info inline">
		<p><?php esc_html_e( 'These links come from the feed and are replaced on every fetch. Untick “Subscribed” above to edit them here.', 'trending-now' ); ?></p>
	</div>
<?php endif; ?>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="advtn-form" id="advtn-manual-form">
	<?php wp_nonce_field( 'advtn_save_manual' ); ?>
	<input type="hidden" name="action" value="advtn_save_manual" />

	<div id="advtn-manual-links">
		<?php
		foreach ( $advtn_links as $advtn_index => $advtn_link ) {
			$advtn_render_link( $advtn_link, (int) $advtn_index, $advtn_limit, $advtn_feed_active );
		}
		?>
	</div>

	<template id="advtn-manual-template">
		<?php if ( ! $advtn_feed_active ) : ?>
			<?php $advtn_render_link( array( 'enabled' => true, 'position' => 0 ), 9999, $advtn_limit ); ?>
		<?php endif; ?>
	</template>

	<p>
		<button type="button" class="button" id="advtn-add-link"><?php esc_html_e( 'Add link', 'trending-now' ); ?></button>
	</p>

	<?php if ( ! $advtn_feed_active ) : ?>
		<?php submit_button( __( 'Save links', 'trending-now' ) ); ?>
	<?php endif; ?>
</form>
