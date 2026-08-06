<?php
/**
 * Settings tab.
 *
 * @var ADVTN_Admin      $admin    Admin controller.
 * @var ADVTN_Settings   $settings Settings service.
 * @var ADVTN_Repository $repository Repository service.
 *
 * @package Advision\TrendingNow
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$advtn_s = $settings->all();
?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="advtn-form">
	<?php wp_nonce_field( 'advtn_save_settings' ); ?>
	<input type="hidden" name="action" value="advtn_save_settings" />

	<h2><?php esc_html_e( 'Mode', 'trending-now' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="advtn-mode"><?php esc_html_e( 'Operating mode', 'trending-now' ); ?></label></th>
			<td>
				<select id="advtn-mode" name="advtn[mode]">
					<?php
					$advtn_modes = array(
						'direct' => __( 'Direct — fetch my own sources', 'trending-now' ),
						'hub'    => __( 'Hub — fetch sources and serve them to spokes', 'trending-now' ),
						'spoke'  => __( 'Spoke — pull a ready list from a hub', 'trending-now' ),
					);
					foreach ( $advtn_modes as $advtn_key => $advtn_label ) :
						?>
						<option value="<?php echo esc_attr( $advtn_key ); ?>" <?php selected( $advtn_s['mode'], $advtn_key ); ?>><?php echo esc_html( $advtn_label ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php esc_html_e( 'A spoke never contacts source sites directly; it pulls one pre-assembled list from the hub.', 'trending-now' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="advtn-hub-url"><?php esc_html_e( 'Hub URL', 'trending-now' ); ?></label></th>
			<td>
				<input type="url" class="regular-text" id="advtn-hub-url" name="advtn[hub_url]" value="<?php echo esc_attr( (string) $advtn_s['hub_url'] ); ?>" placeholder="https://hub.example.com" />
				<p class="description"><?php esc_html_e( 'Spoke mode only. Site root of the hub install.', 'trending-now' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="advtn-hub-secret"><?php esc_html_e( 'Hub shared secret', 'trending-now' ); ?></label></th>
			<td>
				<input type="text" class="regular-text code" id="advtn-hub-secret" name="advtn[hub_secret]" value="<?php echo esc_attr( (string) $advtn_s['hub_secret'] ); ?>" />
				<p class="description"><?php esc_html_e( 'Must match on the hub and every spoke.', 'trending-now' ); ?></p>
			</td>
		</tr>
	</table>

	<h2><?php esc_html_e( 'Display', 'trending-now' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="advtn-widget-limit"><?php esc_html_e( 'Links in the widget', 'trending-now' ); ?></label></th>
			<td><input type="number" min="1" max="200" id="advtn-widget-limit" name="advtn[widget_limit]" value="<?php echo esc_attr( (string) $advtn_s['widget_limit'] ); ?>" /></td>
		</tr>
		<tr>
			<th scope="row"><label for="advtn-heading-text"><?php esc_html_e( 'Heading', 'trending-now' ); ?></label></th>
			<td><input type="text" class="regular-text" id="advtn-heading-text" name="advtn[heading_text]" value="<?php echo esc_attr( (string) $advtn_s['heading_text'] ); ?>" /></td>
		</tr>
		<tr>
			<th scope="row"><label for="advtn-see-all-text"><?php esc_html_e( '"See all" link text', 'trending-now' ); ?></label></th>
			<td><input type="text" class="regular-text" id="advtn-see-all-text" name="advtn[see_all_text]" value="<?php echo esc_attr( (string) $advtn_s['see_all_text'] ); ?>" /></td>
		</tr>
		<tr>
			<th scope="row"><label for="advtn-class-prefix"><?php esc_html_e( 'CSS class prefix', 'trending-now' ); ?></label></th>
			<td>
				<input type="text" id="advtn-class-prefix" name="advtn[class_prefix]" value="<?php echo esc_attr( (string) $advtn_s['class_prefix'] ); ?>" />
				<p class="description"><?php esc_html_e( 'Vary this per site. Identical markup across a network of domains is itself a fingerprint.', 'trending-now' ); ?></p>
			</td>
		</tr>
	</table>

	<h2><?php esc_html_e( 'Rotation', 'trending-now' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="advtn-news-share"><?php esc_html_e( 'News share (%)', 'trending-now' ); ?></label></th>
			<td>
				<input type="number" min="0" max="50" id="advtn-news-share" name="advtn[news_share_pct]" value="<?php echo esc_attr( (string) $advtn_s['news_share_pct'] ); ?>" />
				<p class="description"><?php esc_html_e( 'Share of slots reserved for GDELT news items. Reallocated to network links when underfilled.', 'trending-now' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="advtn-max-source-share"><?php esc_html_e( 'Max share per source (%)', 'trending-now' ); ?></label></th>
			<td><input type="number" min="5" max="100" id="advtn-max-source-share" name="advtn[max_source_share_pct]" value="<?php echo esc_attr( (string) $advtn_s['max_source_share_pct'] ); ?>" /></td>
		</tr>
		<tr>
			<th scope="row"><label for="advtn-exposure-floor"><?php esc_html_e( 'Exposure floor (days)', 'trending-now' ); ?></label></th>
			<td>
				<input type="number" min="0" max="30" id="advtn-exposure-floor" name="advtn[exposure_floor_days]" value="<?php echo esc_attr( (string) $advtn_s['exposure_floor_days'] ); ?>" />
				<p class="description"><?php esc_html_e( 'Guaranteed consecutive days in the widget once an item first appears. Raise it if Googlebot only hits the homepage every few days.', 'trending-now' ); ?></p>
			</td>
		</tr>
	</table>

	<h2><?php esc_html_e( 'Retention', 'trending-now' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="advtn-retention"><?php esc_html_e( 'Retention (days)', 'trending-now' ); ?></label></th>
			<td>
				<input type="number" min="1" max="3650" id="advtn-retention" name="advtn[retention_days]" value="<?php echo esc_attr( (string) $advtn_s['retention_days'] ); ?>" />
				<p class="description"><?php esc_html_e( 'Caps total archive size, which is what keeps the archive defensible rather than an unbounded link dump.', 'trending-now' ); ?></p>
			</td>
		</tr>
	</table>

	<h2><?php esc_html_e( 'Links', 'trending-now' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Open in a new tab', 'trending-now' ); ?></th>
			<td><label><input type="checkbox" name="advtn[link_target_blank]" value="1" <?php checked( ! empty( $advtn_s['link_target_blank'] ) ); ?> /> <?php esc_html_e( 'Add target="_blank" (and rel="noopener")', 'trending-now' ); ?></label></td>
		</tr>
		<tr>
			<th scope="row"><label for="advtn-rel-external"><?php esc_html_e( 'rel on news links', 'trending-now' ); ?></label></th>
			<td>
				<select id="advtn-rel-external" name="advtn[link_rel_external]">
					<option value="" <?php selected( (string) $advtn_s['link_rel_external'], '' ); ?>><?php esc_html_e( 'None (followed)', 'trending-now' ); ?></option>
					<option value="nofollow" <?php selected( (string) $advtn_s['link_rel_external'], 'nofollow' ); ?>>nofollow</option>
					<option value="sponsored" <?php selected( (string) $advtn_s['link_rel_external'], 'sponsored' ); ?>>sponsored</option>
				</select>
				<p class="description"><?php esc_html_e( 'Applies to GDELT news items only. Network links are always plain followed links — that is the point of the plugin.', 'trending-now' ); ?></p>
			</td>
		</tr>
	</table>

	<h2><?php esc_html_e( 'Archive', 'trending-now' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Enable the archive', 'trending-now' ); ?></th>
			<td><label><input type="checkbox" name="advtn[archive_enabled]" value="1" <?php checked( ! empty( $advtn_s['archive_enabled'] ) ); ?> /> <?php esc_html_e( 'Serve the "see all" page', 'trending-now' ); ?></label></td>
		</tr>
		<tr>
			<th scope="row"><label for="advtn-archive-slug"><?php esc_html_e( 'Archive slug', 'trending-now' ); ?></label></th>
			<td>
				<code><?php echo esc_html( trailingslashit( home_url() ) ); ?></code>
				<input type="text" id="advtn-archive-slug" name="advtn[archive_slug]" value="<?php echo esc_attr( (string) $advtn_s['archive_slug'] ); ?>" />
				<p class="description"><?php esc_html_e( 'Vary per site: /trending/, /whats-hot/, /latest-news/. Rewrite rules are flushed automatically when this changes.', 'trending-now' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="advtn-archive-per-page"><?php esc_html_e( 'Items per page', 'trending-now' ); ?></label></th>
			<td><input type="number" min="5" max="200" id="advtn-archive-per-page" name="advtn[archive_per_page]" value="<?php echo esc_attr( (string) $advtn_s['archive_per_page'] ); ?>" /></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Indexing', 'trending-now' ); ?></th>
			<td>
				<label><input type="checkbox" name="advtn[archive_noindex]" value="1" <?php checked( ! empty( $advtn_s['archive_noindex'] ) ); ?> /> <?php esc_html_e( 'Emit noindex, follow', 'trending-now' ); ?></label>
				<p class="description">
					<?php esc_html_e( 'Default is indexable, because the page is a discovery vehicle and you want it crawled often; the retention cap, pagination and intro copy are what keep it defensible. Long-term noindexed pages get crawled progressively less and Google eventually treats their links as nofollow, so noindex decays exactly the property you are buying.', 'trending-now' ); ?>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="advtn-archive-intro"><?php esc_html_e( 'Intro copy', 'trending-now' ); ?></label></th>
			<td>
				<textarea id="advtn-archive-intro" name="advtn[archive_intro]" rows="4" class="large-text"><?php echo esc_textarea( (string) $advtn_s['archive_intro'] ); ?></textarea>
				<p class="description"><?php esc_html_e( 'A page of pure links with no context looks like exactly what it is.', 'trending-now' ); ?></p>
			</td>
		</tr>
	</table>

	<h2><?php esc_html_e( 'Scheduling', 'trending-now' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="advtn-interval"><?php esc_html_e( 'Ingest interval (hours)', 'trending-now' ); ?></label></th>
			<td>
				<input type="number" min="1" max="168" id="advtn-interval" name="advtn[ingest_interval_hours]" value="<?php echo esc_attr( (string) $advtn_s['ingest_interval_hours'] ); ?>" />
				<p class="description"><?php esc_html_e( 'A due-check threshold, not a fixed clock time. A missed window just runs late.', 'trending-now' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="advtn-stagger"><?php esc_html_e( 'Stagger between sources (minutes)', 'trending-now' ); ?></label></th>
			<td><input type="number" min="0" max="120" id="advtn-stagger" name="advtn[stagger_minutes]" value="<?php echo esc_attr( (string) $advtn_s['stagger_minutes'] ); ?>" /></td>
		</tr>
		<tr>
			<th scope="row"><label for="advtn-batch-max"><?php esc_html_e( 'Sources per batch', 'trending-now' ); ?></label></th>
			<td><input type="number" min="1" max="25" id="advtn-batch-max" name="advtn[batch_max_sources]" value="<?php echo esc_attr( (string) $advtn_s['batch_max_sources'] ); ?>" /></td>
		</tr>
		<tr>
			<th scope="row"><label for="advtn-batch-budget"><?php esc_html_e( 'Batch time budget (seconds)', 'trending-now' ); ?></label></th>
			<td><input type="number" min="5" max="120" id="advtn-batch-budget" name="advtn[batch_time_budget]" value="<?php echo esc_attr( (string) $advtn_s['batch_time_budget'] ); ?>" /></td>
		</tr>
		<tr>
			<th scope="row"><label for="advtn-http-timeout"><?php esc_html_e( 'HTTP timeout (seconds)', 'trending-now' ); ?></label></th>
			<td><input type="number" min="1" max="30" id="advtn-http-timeout" name="advtn[http_timeout]" value="<?php echo esc_attr( (string) $advtn_s['http_timeout'] ); ?>" /></td>
		</tr>
		<tr>
			<th scope="row"><label for="advtn-backoff"><?php esc_html_e( 'Failure backoff (seconds)', 'trending-now' ); ?></label></th>
			<td><input type="number" min="60" max="86400" id="advtn-backoff" name="advtn[source_fail_backoff]" value="<?php echo esc_attr( (string) $advtn_s['source_fail_backoff'] ); ?>" /></td>
		</tr>
	</table>

	<h2><?php esc_html_e( 'Security', 'trending-now' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="advtn-ingest-secret"><?php esc_html_e( 'Ingest secret', 'trending-now' ); ?></label></th>
			<td>
				<input type="text" readonly class="large-text code advtn-copyable" id="advtn-ingest-secret" value="<?php echo esc_attr( (string) $advtn_s['ingest_secret'] ); ?>" />
				<input type="hidden" name="advtn[ingest_secret]" value="<?php echo esc_attr( (string) $advtn_s['ingest_secret'] ); ?>" />
				<button type="button" class="button advtn-copy" data-target="advtn-ingest-secret"><?php esc_html_e( 'Copy', 'trending-now' ); ?></button>
				<p class="description">
					<?php esc_html_e( 'Signs POST /wp-json/advtn/v1/ingest and GET /wp-json/advtn/v1/status. Message is timestamp + "\n" + raw body; HMAC-SHA256, hex, in X-ADVTN-Signature with X-ADVTN-Timestamp.', 'trending-now' ); ?>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Uninstall', 'trending-now' ); ?></th>
			<td><label><input type="checkbox" name="advtn[delete_data_on_uninstall]" value="1" <?php checked( ! empty( $advtn_s['delete_data_on_uninstall'] ) ); ?> /> <?php esc_html_e( 'Drop the items table and every advtn_* option on uninstall', 'trending-now' ); ?></label></td>
		</tr>
	</table>

	<?php submit_button(); ?>
</form>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="advtn-inline-form">
	<?php wp_nonce_field( 'advtn_action' ); ?>
	<input type="hidden" name="action" value="advtn_action" />
	<button type="submit" class="button advtn-confirm" name="advtn_do" value="regenerate_ingest_secret"><?php esc_html_e( 'Regenerate ingest secret', 'trending-now' ); ?></button>
	<button type="submit" class="button advtn-confirm" name="advtn_do" value="regenerate_hub_secret"><?php esc_html_e( 'Regenerate hub secret', 'trending-now' ); ?></button>
</form>
