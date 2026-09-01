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

$advtn_s    = $settings->all();
$advtn_mode = (string) $advtn_s['mode'];

/**
 * Emit the hidden attribute unless the current mode is in $modes.
 *
 * Rendered server-side as well as toggled in JS, so the irrelevant rows never
 * flash on load and stay hidden if the script fails.
 *
 * @param string[] $modes Modes this row applies to.
 * @param string   $mode  Current mode.
 * @return string
 */
$advtn_mode_attr = static function ( array $modes, string $mode ): string {
	return in_array( $mode, $modes, true ) ? '' : ' hidden';
};
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
		<tr class="advtn-mode-row" data-modes="spoke"<?php echo esc_attr( $advtn_mode_attr( array( 'spoke' ), $advtn_mode ) ); ?>>
			<th scope="row"><label for="advtn-hub-url"><?php esc_html_e( 'Hub URL', 'trending-now' ); ?></label></th>
			<td>
				<input type="url" class="regular-text" id="advtn-hub-url" name="advtn[hub_url]" value="<?php echo esc_attr( (string) $advtn_s['hub_url'] ); ?>" placeholder="https://hub.example.com" />
				<p class="description"><?php esc_html_e( 'Site root of the hub install to pull the assembled list from.', 'trending-now' ); ?></p>
			</td>
		</tr>
		<tr class="advtn-mode-row" data-modes="hub spoke"<?php echo esc_attr( $advtn_mode_attr( array( 'hub', 'spoke' ), $advtn_mode ) ); ?>>
			<th scope="row"><label for="advtn-hub-secret"><?php esc_html_e( 'Hub shared secret', 'trending-now' ); ?></label></th>
			<td>
				<input type="text" class="regular-text code" id="advtn-hub-secret" name="advtn[hub_secret]" value="<?php echo esc_attr( (string) $advtn_s['hub_secret'] ); ?>" />
				<p class="description"><?php esc_html_e( 'Must match on the hub and every spoke. The hub verifies requests with it; a spoke signs with it.', 'trending-now' ); ?></p>
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
			<th scope="row"><label for="advtn-layout"><?php esc_html_e( 'Layout', 'trending-now' ); ?></label></th>
			<td>
				<select id="advtn-layout" name="advtn[layout]">
					<?php
					$advtn_layouts = array(
						'list'  => __( 'List — compact text links', 'trending-now' ),
						'news'  => __( 'News — source, headline and thumbnail (Google News style)', 'trending-now' ),
						'cards' => __( 'Cards — grid with images and excerpts', 'trending-now' ),
					);
					foreach ( $advtn_layouts as $advtn_key => $advtn_label ) :
						?>
						<option value="<?php echo esc_attr( $advtn_key ); ?>" <?php selected( (string) $advtn_s['layout'], $advtn_key ); ?>><?php echo esc_html( $advtn_label ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php esc_html_e( 'The default for the shortcode, the block and the template tag. Any of them can still override it per instance.', 'trending-now' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Show', 'trending-now' ); ?></th>
			<td>
				<label><input type="checkbox" name="advtn[show_images]" value="1" <?php checked( ! empty( $advtn_s['show_images'] ) ); ?> /> <?php esc_html_e( 'Thumbnails', 'trending-now' ); ?></label><br />
				<label><input type="checkbox" name="advtn[show_source]" value="1" <?php checked( ! empty( $advtn_s['show_source'] ) ); ?> /> <?php esc_html_e( 'Source name', 'trending-now' ); ?></label><br />
				<label><input type="checkbox" name="advtn[show_icons]" value="1" <?php checked( ! empty( $advtn_s['show_icons'] ) ); ?> /> <?php esc_html_e( 'Site icons beside the source name', 'trending-now' ); ?></label><br />
				<label><input type="checkbox" name="advtn[show_excerpt]" value="1" <?php checked( ! empty( $advtn_s['show_excerpt'] ) ); ?> /> <?php esc_html_e( 'Excerpt', 'trending-now' ); ?></label><br />
				<label><input type="checkbox" name="advtn[show_date]" value="1" <?php checked( ! empty( $advtn_s['show_date'] ) ); ?> /> <?php esc_html_e( 'Timestamp', 'trending-now' ); ?></label>
				<p style="margin:.5em 0 0">
					<label for="advtn-date-style"><strong><?php esc_html_e( 'Timestamp style', 'trending-now' ); ?></strong></label><br />
					<select id="advtn-date-style" name="advtn[date_style]">
						<option value="relative" <?php selected( (string) $advtn_s['date_style'], 'relative' ); ?>><?php esc_html_e( 'Relative under a day (45m, 6h), date beyond', 'trending-now' ); ?></option>
						<option value="date" <?php selected( (string) $advtn_s['date_style'], 'date' ); ?>><?php esc_html_e( 'Always a date (Aug 7)', 'trending-now' ); ?></option>
					</select>
				</p>
				<p class="description">
					<?php esc_html_e( 'Relative stamps are recalculated on every request, so they stay accurate between ingest cycles rather than freezing at whatever they said when the page was cached.', 'trending-now' ); ?>
					<?php esc_html_e( 'They do advertise your publishing rhythm, though: on a once-a-day cycle every item drifts towards "20h" or "23h" together, which reads as a batch update. Choose dates if that matters more than the freshness cue.', 'trending-now' ); ?>
				</p>
				<p class="description"><?php esc_html_e( 'Thumbnails are lazy-loaded below the first card and carry fixed dimensions, so they do not shift the layout as they arrive. Timestamps show as 45m or 6h within the last day, and a date before that.', 'trending-now' ); ?></p>
				<p class="description"><?php esc_html_e( 'Site icons are fetched by the visitor\'s browser from Google\'s favicon service, which means that service sees your visitors. Filter advtn_source_icon_url to self-host them or use another provider.', 'trending-now' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="advtn-max-age"><?php esc_html_e( 'Maximum age (hours)', 'trending-now' ); ?></label></th>
			<td>
				<input type="number" min="0" max="720" id="advtn-max-age" name="advtn[max_age_hours]" value="<?php echo esc_attr( (string) $advtn_s['max_age_hours'] ); ?>" />
				<p class="description">
					<?php esc_html_e( 'Hide anything published longer ago than this, in the widget and on the archive alike. 0 disables the cutoff; 72 means nothing older than three days. Curated links are exempt — they have their own expiry.', 'trending-now' ); ?>
					<?php
					$advtn_age   = (int) $advtn_s['max_age_hours'];
					$advtn_floor = (int) $advtn_s['exposure_floor_days'];
					// >= rather than >: an equal pair only looks safe. The floor
					// is measured from first_shown_at and the cutoff from
					// published_at, so any ingest lag at all makes an equal pair
					// a floor that cannot finish.
					if ( $advtn_age > 0 && ( $advtn_floor * 24 ) >= $advtn_age ) :
						?>
						<br /><strong>
						<?php
						printf(
							/* translators: 1: exposure floor in days, 2: cutoff in hours. */
							esc_html__( 'Note: the exposure floor is %1$d days and the cutoff is %2$d hours, so items are dropped before their guaranteed run finishes — the floor counts from when an item was first shown, the cutoff from when it was published, so any delay between the two eats the difference. Lower the floor.', 'trending-now' ),
							$advtn_floor,
							$advtn_age
						);
						?>
						</strong>
					<?php endif; ?>
				</p>
			</td>
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
				<p class="description"><?php esc_html_e( 'Share of slots reserved for third-party news items. Reallocated to network links when underfilled.', 'trending-now' ); ?></p>
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
				<p class="description"><?php esc_html_e( 'Applies to third-party news items only. Network links are always plain followed links — that is the point of the plugin.', 'trending-now' ); ?></p>
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
			<td>
				<input type="number" min="1" max="60" id="advtn-http-timeout" name="advtn[http_timeout]" value="<?php echo esc_attr( (string) $advtn_s['http_timeout'] ); ?>" />
				<p class="description"><?php esc_html_e( 'Per outbound request. WordPress REST and RSS sources answer in well under a second, but SerpAPI usually answers in a couple of seconds. 30 is a safe ceiling.', 'trending-now' ); ?></p>
			</td>
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
			<th scope="row"><label for="advtn-serpapi-key"><?php esc_html_e( 'SerpAPI key', 'trending-now' ); ?></label></th>
			<td>
				<input type="password" class="large-text code" id="advtn-serpapi-key" name="advtn[serpapi_key]" value="<?php echo esc_attr( (string) $advtn_s['serpapi_key'] ); ?>" autocomplete="off" <?php disabled( $settings->secret_is_constant( 'serpapi_key' ) ); ?> />
				<?php if ( $settings->secret_is_constant( 'serpapi_key' ) ) : ?>
					<p class="description"><strong><?php esc_html_e( 'Supplied by the ADVTN_SERPAPI_KEY constant; this field is ignored.', 'trending-now' ); ?></strong></p>
				<?php endif; ?>
				<p class="description">
					<?php esc_html_e( 'Required by "Google News (SerpAPI)" sources. Each fetch spends one search credit, so one source on a daily cycle costs about 30 a month. Get a key at serpapi.com.', 'trending-now' ); ?>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="advtn-github-token"><?php esc_html_e( 'GitHub token', 'trending-now' ); ?></label></th>
			<td>
				<input type="password" class="large-text code" id="advtn-github-token" name="advtn[github_token]" value="<?php echo esc_attr( (string) $advtn_s['github_token'] ); ?>" autocomplete="off" <?php disabled( $settings->secret_is_constant( 'github_token' ) ); ?> />
				<?php if ( $settings->secret_is_constant( 'github_token' ) ) : ?>
					<p class="description"><strong><?php esc_html_e( 'Supplied by the ADVTN_GITHUB_TOKEN constant; this field is ignored.', 'trending-now' ); ?></strong></p>
				<?php endif; ?>
				<p class="description">
					<?php esc_html_e( 'Only needed because the plugin repository is private. A fine-grained personal access token with read-only Contents access is enough. Without it, update checks return "no release found".', 'trending-now' ); ?>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Updates', 'trending-now' ); ?></th>
			<td>
				<label><input type="checkbox" name="advtn[auto_update]" value="1" <?php checked( ! empty( $advtn_s['auto_update'] ) ); ?> /> <?php esc_html_e( 'Keep this plugin updated from GitHub releases, without being asked', 'trending-now' ); ?></label>
				<p class="description"><?php esc_html_e( 'Surfaces new releases on the Plugins screen like any other update. WordPress still asks before installing unless you enable its own auto-update toggle for this plugin.', 'trending-now' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Page cache', 'trending-now' ); ?></th>
			<td>
				<label><input type="checkbox" name="advtn[purge_page_cache]" value="1" <?php checked( ! empty( $advtn_s['purge_page_cache'] ) ); ?> /> <?php esc_html_e( 'Purge the page cache when the list changes', 'trending-now' ); ?></label>
				<p class="description">
					<?php
					$advtn_caches = ADVTN_Page_Cache::detected();
					echo esc_html(
						empty( $advtn_caches )
							? __( 'No supported page cache detected.', 'trending-now' )
							: sprintf(
								/* translators: %s: comma-separated cache plugin names. */
								__( 'Detected: %s.', 'trending-now' ),
								implode( ', ', $advtn_caches )
							)
					);
					?>
					<?php esc_html_e( 'Without this the widget and the archive can show different sets, because a page cache keeps whichever HTML it captured — one page cached after an ingest cycle and another before it will simply disagree. Hook advtn_purge_page_cache for a cache or CDN not listed.', 'trending-now' ); ?>
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
	<span class="advtn-mode-row" data-modes="hub spoke"<?php echo esc_attr( $advtn_mode_attr( array( 'hub', 'spoke' ), $advtn_mode ) ); ?>>
		<button type="submit" class="button advtn-confirm" name="advtn_do" value="regenerate_hub_secret"><?php esc_html_e( 'Regenerate hub secret', 'trending-now' ); ?></button>
	</span>
</form>
