<?php
/**
 * Diagnostics tab.
 *
 * On hosts without WP-CLI this panel is the only visibility when ingestion
 * silently stops, so it is deliberately verbose.
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

$advtn_status    = advtn()->rest()->status_payload();
$advtn_selection = advtn()->selector()->current_rows();
$advtn_sources   = $settings->sources();
$advtn_state     = $settings->state();

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display filter only.
$advtn_level = isset( $_GET['level'] ) ? sanitize_key( wp_unslash( $_GET['level'] ) ) : '';
$advtn_log   = ADVTN_Logger::entries( '' !== $advtn_level ? $advtn_level : null );

/**
 * Yes/no badge.
 *
 * @param bool $value Condition.
 * @return string
 */
$advtn_badge = static function ( bool $value ): string {
	return $value
		? '<span class="advtn-badge advtn-badge--ok">' . esc_html__( 'yes', 'trending-now' ) . '</span>'
		: '<span class="advtn-badge advtn-badge--bad">' . esc_html__( 'no', 'trending-now' ) . '</span>';
};
?>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="advtn-inline-form">
	<?php wp_nonce_field( 'advtn_action' ); ?>
	<input type="hidden" name="action" value="advtn_action" />
	<button type="submit" class="button button-primary" name="advtn_do" value="run_ingest"><?php esc_html_e( 'Run ingest now', 'trending-now' ); ?></button>
	<span class="description advtn-inline-note"><?php esc_html_e( 'Fetches every source in this request and rebuilds the selection before the page reloads.', 'trending-now' ); ?></span>
	<button type="submit" class="button" name="advtn_do" value="rebuild_selection"><?php esc_html_e( 'Rebuild selection', 'trending-now' ); ?></button>
	<button type="submit" class="button" name="advtn_do" value="purge_cache"><?php esc_html_e( 'Purge render cache', 'trending-now' ); ?></button>
	<button type="submit" class="button" name="advtn_do" value="test_loopback"><?php esc_html_e( 'Test loopback', 'trending-now' ); ?></button>
	<button type="submit" class="button" name="advtn_do" value="check_updates"><?php esc_html_e( 'Check for updates', 'trending-now' ); ?></button>
	<?php if ( '' !== $settings->get_secret( 'serpapi_key' ) ) : ?>
		<button type="submit" class="button" name="advtn_do" value="check_serpapi"><?php esc_html_e( 'Check SerpAPI credits', 'trending-now' ); ?></button>
	<?php endif; ?>
	<button type="submit" class="button advtn-confirm" name="advtn_do" value="release_lock"><?php esc_html_e( 'Release lock', 'trending-now' ); ?></button>
	<button type="submit" class="button advtn-confirm" name="advtn_do" value="clear_log"><?php esc_html_e( 'Clear log', 'trending-now' ); ?></button>
</form>

<h2><?php esc_html_e( 'Environment', 'trending-now' ); ?></h2>
<table class="widefat striped advtn-kv">
	<tbody>
		<tr><th><?php esc_html_e( 'Mode', 'trending-now' ); ?></th><td><code><?php echo esc_html( (string) $advtn_status['mode'] ); ?></code></td></tr>
		<tr><th><?php esc_html_e( 'Last completed cycle (UTC)', 'trending-now' ); ?></th><td><?php echo esc_html( '' !== $advtn_status['last_ingest'] ? (string) $advtn_status['last_ingest'] : __( 'never', 'trending-now' ) ); ?></td></tr>
		<tr><th><?php esc_html_e( 'Age (hours)', 'trending-now' ); ?></th><td><?php echo esc_html( null !== $advtn_status['last_ingest_age_h'] ? (string) $advtn_status['last_ingest_age_h'] : '—' ); ?></td></tr>
		<tr><th><?php esc_html_e( 'Cycle due', 'trending-now' ); ?></th><td><?php echo wp_kses_post( $advtn_badge( (bool) $advtn_status['ingest_due'] ) ); ?></td></tr>
		<tr><th><?php esc_html_e( 'Lock held', 'trending-now' ); ?></th><td><?php echo wp_kses_post( $advtn_badge( (bool) $advtn_status['lock_held'] ) ); ?> <?php echo null !== $advtn_status['lock_age_seconds'] ? esc_html( sprintf( '(%ds)', (int) $advtn_status['lock_age_seconds'] ) ) : ''; ?></td></tr>
		<tr><th><?php esc_html_e( 'Items table exists', 'trending-now' ); ?></th><td><?php echo wp_kses_post( $advtn_badge( (bool) $advtn_status['table_exists'] ) ); ?></td></tr>
		<tr><th><?php esc_html_e( 'Loopback request', 'trending-now' ); ?></th><td><?php echo wp_kses_post( $advtn_badge( (bool) $advtn_status['loopback_ok'] ) ); ?> <span class="description"><?php esc_html_e( 'Action Scheduler processes batches over loopback requests. HTTP auth, aggressive WAFs and firewalled self-requests break this.', 'trending-now' ); ?></span></td></tr>
		<tr><th><?php esc_html_e( 'WP-Cron enabled', 'trending-now' ); ?></th><td><?php echo wp_kses_post( $advtn_badge( (bool) $advtn_status['wp_cron_enabled'] ) ); ?></td></tr>
		<tr>
			<th><?php esc_html_e( 'Action Scheduler', 'trending-now' ); ?></th>
			<td>
				<?php echo wp_kses_post( $advtn_badge( (bool) $advtn_status['action_scheduler'] ) ); ?>
				<?php
				printf(
					/* translators: %d: pending action count. */
					esc_html__( '%d pending', 'trending-now' ),
					(int) $advtn_status['pending_actions']
				);
				?>
				<a href="<?php echo esc_url( admin_url( 'tools.php?page=action-scheduler' ) ); ?>"><?php esc_html_e( 'Scheduled Actions', 'trending-now' ); ?></a>
				<?php if ( empty( $advtn_status['action_scheduler'] ) ) : ?>
					<span class="description"><?php esc_html_e( 'Not loaded — run composer install. Falling back to plain WP-Cron.', 'trending-now' ); ?></span>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Page cache', 'trending-now' ); ?></th>
			<td>
				<?php
				$advtn_caches = ADVTN_Page_Cache::detected();
				echo esc_html( empty( $advtn_caches ) ? __( 'none detected', 'trending-now' ) : implode( ', ', $advtn_caches ) );
				echo ' ';
				echo wp_kses_post( $advtn_badge( $settings->get_bool( 'purge_page_cache' ) ) );
				esc_html_e( ' purged on change', 'trending-now' );
				?>
			</td>
		</tr>
		<tr><th><?php esc_html_e( 'Versions', 'trending-now' ); ?></th><td><?php echo esc_html( sprintf( 'plugin %s / db %s', (string) $advtn_status['plugin_version'], (string) $advtn_status['db_version'] ) ); ?></td></tr>
		<tr>
			<th><?php esc_html_e( 'Latest release', 'trending-now' ); ?></th>
			<td>
				<?php
				/*
				 * Read from the cache, never fetched here. This used to call
				 * latest_release(), so every render of this tab was another
				 * request to the GitHub API — and GitHub allows 60 an hour per
				 * IP, which a hosting provider's sites share.
				 */
				if ( ! $settings->get_bool( 'auto_update' ) ) {
					esc_html_e( 'Update checks are disabled.', 'trending-now' );
				} else {
					$advtn_update = advtn()->updater()->status();

					switch ( $advtn_update['state'] ) {
						case 'available':
							echo '<span class="advtn-badge advtn-badge--bad">' . esc_html__( 'update available', 'trending-now' ) . '</span> ';
							echo esc_html( $advtn_update['version'] );
							break;

						case 'failed':
							echo '<span class="advtn-badge advtn-badge--bad">' . esc_html__( 'unavailable', 'trending-now' ) . '</span> ';
							echo esc_html( $advtn_update['reason'] );
							break;

						case 'never':
							echo '<span class="advtn-badge">' . esc_html__( 'not checked yet', 'trending-now' ) . '</span>';
							break;

						default:
							echo '<span class="advtn-badge advtn-badge--ok">' . esc_html__( 'up to date', 'trending-now' ) . '</span> ';
							echo esc_html( $advtn_update['version'] );
							break;
					}
				}
				?>
			</td>
		</tr>
		<?php if ( '' !== $settings->get_secret( 'serpapi_key' ) ) : ?>
			<tr>
				<th><?php esc_html_e( 'SerpAPI credits', 'trending-now' ); ?></th>
				<td>
					<?php
					$advtn_account = get_transient( ADVTN_Source_SerpAPI::ACCOUNT_TRANSIENT );

					if ( ! is_array( $advtn_account ) ) {
						esc_html_e( 'Not checked yet — use "Check SerpAPI credits" above.', 'trending-now' );
					} else {
						$advtn_left = $advtn_account['searches_left'];
						printf(
							'<span class="advtn-badge advtn-badge--%1$s">%2$s</span> %3$s',
							esc_attr( ( null !== $advtn_left && $advtn_left <= 0 ) ? 'bad' : 'ok' ),
							esc_html( null === $advtn_left ? '?' : (string) $advtn_left ),
							esc_html(
								sprintf(
									/* translators: 1: plan name, 2: usage this month, 3: check time. */
									__( 'searches left on "%1$s" · %2$s used this month · checked %3$s UTC', 'trending-now' ),
									(string) $advtn_account['plan'],
									null === $advtn_account['this_month_usage'] ? '?' : (string) $advtn_account['this_month_usage'],
									(string) $advtn_account['checked_at']
								)
							)
						);
					}
					?>
				</td>
			</tr>
		<?php endif; ?>
	</tbody>
</table>

<?php if ( ! empty( $advtn_status['pending_queue'] ) ) : ?>
	<h2><?php esc_html_e( 'Queued work', 'trending-now' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Scheduled actions waiting to run. Sources are staggered, and the selection and render cache only change once the finalize action completes — so the widget will not reflect a scheduled cycle until then.', 'trending-now' ); ?></p>
	<table class="widefat striped">
		<thead><tr><th><?php esc_html_e( 'Action', 'trending-now' ); ?></th><th><?php esc_html_e( 'Args', 'trending-now' ); ?></th><th><?php esc_html_e( 'Due (UTC)', 'trending-now' ); ?></th></tr></thead>
		<tbody>
			<?php foreach ( (array) $advtn_status['pending_queue'] as $advtn_job ) : ?>
				<tr>
					<td><code><?php echo esc_html( (string) $advtn_job['hook'] ); ?></code></td>
					<td><?php echo esc_html( '' !== $advtn_job['args'] ? (string) $advtn_job['args'] : '—' ); ?></td>
					<td><?php echo esc_html( '' !== $advtn_job['when'] ? (string) $advtn_job['when'] : '—' ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>

<h2><?php esc_html_e( 'Sources', 'trending-now' ); ?></h2>
<table class="widefat striped">
	<thead>
		<tr>
			<th><?php esc_html_e( 'Source', 'trending-now' ); ?></th>
			<th><?php esc_html_e( 'Last run', 'trending-now' ); ?></th>
			<th><?php esc_html_e( 'Last success', 'trending-now' ); ?></th>
			<th><?php esc_html_e( 'HTTP', 'trending-now' ); ?></th>
			<th><?php esc_html_e( 'ms', 'trending-now' ); ?></th>
			<th><?php esc_html_e( 'Seen', 'trending-now' ); ?></th>
			<th><?php esc_html_e( 'New', 'trending-now' ); ?></th>
			<th><?php esc_html_e( 'Fails', 'trending-now' ); ?></th>
			<th><?php esc_html_e( 'Backoff until', 'trending-now' ); ?></th>
			<th><?php esc_html_e( 'Last error', 'trending-now' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php if ( empty( $advtn_sources ) ) : ?>
			<tr><td colspan="10"><?php esc_html_e( 'No sources configured.', 'trending-now' ); ?></td></tr>
		<?php endif; ?>
		<?php
		foreach ( $advtn_sources as $advtn_source ) :
			$advtn_id  = (string) ( $advtn_source['id'] ?? '' );
			$advtn_row = isset( $advtn_state[ $advtn_id ] ) ? (array) $advtn_state[ $advtn_id ] : array();
			$advtn_bad = ! empty( $advtn_row['last_error'] );
			?>
			<tr class="<?php echo $advtn_bad ? 'advtn-row--error' : ''; ?>">
				<td>
					<strong><?php echo esc_html( (string) ( $advtn_source['label'] ?? $advtn_id ) ); ?></strong><br />
					<code><?php echo esc_html( $advtn_id ); ?></code> · <?php echo esc_html( (string) ( $advtn_source['type'] ?? '' ) ); ?>
					<?php if ( empty( $advtn_source['enabled'] ) ) : ?>
						<em>(<?php esc_html_e( 'disabled', 'trending-now' ); ?>)</em>
					<?php endif; ?>
				</td>
				<td><?php echo esc_html( (string) ( $advtn_row['last_run'] ?? '—' ) ); ?></td>
				<td><?php echo esc_html( (string) ( $advtn_row['last_success'] ?? '—' ) ); ?></td>
				<td><?php echo esc_html( (string) ( $advtn_row['http_code'] ?? '—' ) ); ?></td>
				<td><?php echo esc_html( (string) ( $advtn_row['duration_ms'] ?? '—' ) ); ?></td>
				<td><?php echo esc_html( (string) ( $advtn_row['items_seen'] ?? '—' ) ); ?></td>
				<td><?php echo esc_html( (string) ( $advtn_row['items_new'] ?? '—' ) ); ?></td>
				<td><?php echo esc_html( (string) ( $advtn_row['consec_fails'] ?? 0 ) ); ?></td>
				<td><?php echo esc_html( (string) ( $advtn_row['backoff_until'] ?? '—' ) ); ?></td>
				<td><?php echo esc_html( (string) ( $advtn_row['last_error'] ?? '' ) ); ?></td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>

<h2><?php esc_html_e( 'Items', 'trending-now' ); ?></h2>
<table class="widefat striped advtn-kv">
	<tbody>
		<tr><th><?php esc_html_e( 'Total', 'trending-now' ); ?></th><td><?php echo esc_html( (string) $advtn_status['counts']['total'] ); ?></td></tr>
		<tr><th><?php esc_html_e( 'Active', 'trending-now' ); ?></th><td><?php echo esc_html( (string) $advtn_status['counts']['active'] ); ?></td></tr>
		<tr><th><?php esc_html_e( 'Stale', 'trending-now' ); ?></th><td><?php echo esc_html( (string) $advtn_status['counts']['stale'] ); ?></td></tr>
		<tr><th><?php esc_html_e( 'Never shown', 'trending-now' ); ?></th><td><?php echo esc_html( (string) $advtn_status['counts']['never_shown'] ); ?></td></tr>
		<tr><th><?php esc_html_e( 'Published in the last 7 days', 'trending-now' ); ?></th><td><?php echo esc_html( (string) $advtn_status['counts']['last_7_days'] ); ?></td></tr>
		<tr>
			<th><?php esc_html_e( 'By type', 'trending-now' ); ?></th>
			<td>
				<?php
				$advtn_pairs = array();
				foreach ( (array) $advtn_status['counts']['by_type'] as $advtn_type => $advtn_n ) {
					$advtn_pairs[] = $advtn_type . ': ' . (int) $advtn_n;
				}
				echo esc_html( ! empty( $advtn_pairs ) ? implode( ' · ', $advtn_pairs ) : '—' );
				?>
			</td>
		</tr>
	</tbody>
</table>

<?php
$advtn_filters  = $admin->item_filters();
$advtn_per_page = 25;
$advtn_ipage    = $admin->item_page();
$advtn_matching = $repository->count_where( $advtn_filters );
$advtn_ipages   = max( 1, (int) ceil( $advtn_matching / $advtn_per_page ) );
$advtn_ipage    = min( $advtn_ipage, $advtn_ipages );
$advtn_rows     = $repository->browse( $advtn_filters, $advtn_per_page, ( $advtn_ipage - 1 ) * $advtn_per_page );
$advtn_filtered = ! empty( array_filter( $advtn_filters ) );
$advtn_base     = admin_url( 'admin.php?page=' . ADVTN_Admin::MENU_SLUG . '&tab=diagnostics' );
?>

<h2>
	<?php
	printf(
		/* translators: 1: rows matching the filter, 2: total rows stored. */
		esc_html__( 'Stored items — %1$d shown of %2$d', 'trending-now' ),
		(int) $advtn_matching,
		(int) $advtn_status['counts']['total']
	);
	?>
</h2>
<p class="description"><?php esc_html_e( 'Deleting an item removes it from the table, the live selection and the archive. It will come back on the next ingest if its source still lists it — disable or remove the source first if you want it gone for good.', 'trending-now' ); ?></p>

<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="advtn-item-filters">
	<input type="hidden" name="page" value="<?php echo esc_attr( ADVTN_Admin::MENU_SLUG ); ?>" />
	<input type="hidden" name="tab" value="diagnostics" />

	<select name="f_source">
		<option value=""><?php esc_html_e( 'All sources', 'trending-now' ); ?></option>
		<?php foreach ( $advtn_sources as $advtn_src ) : ?>
			<option value="<?php echo esc_attr( (string) $advtn_src['id'] ); ?>" <?php selected( $advtn_filters['source_id'], (string) $advtn_src['id'] ); ?>>
				<?php echo esc_html( (string) ( $advtn_src['label'] ?? $advtn_src['id'] ) ); ?>
			</option>
		<?php endforeach; ?>
	</select>

	<select name="f_host">
		<option value=""><?php esc_html_e( 'All hosts', 'trending-now' ); ?></option>
		<?php foreach ( $repository->hosts() as $advtn_h ) : ?>
			<option value="<?php echo esc_attr( $advtn_h['host'] ); ?>" <?php selected( $advtn_filters['host'], $advtn_h['host'] ); ?>>
				<?php echo esc_html( $advtn_h['host'] . ' (' . $advtn_h['n'] . ')' ); ?>
			</option>
		<?php endforeach; ?>
	</select>

	<select name="f_type">
		<option value=""><?php esc_html_e( 'All types', 'trending-now' ); ?></option>
		<?php foreach ( array( 'wp_rest', 'rss', 'serpapi' ) as $advtn_t ) : ?>
			<option value="<?php echo esc_attr( $advtn_t ); ?>" <?php selected( $advtn_filters['source_type'], $advtn_t ); ?>><?php echo esc_html( $advtn_t ); ?></option>
		<?php endforeach; ?>
	</select>

	<select name="f_status">
		<option value=""><?php esc_html_e( 'Any status', 'trending-now' ); ?></option>
		<option value="active" <?php selected( $advtn_filters['status'], 'active' ); ?>><?php esc_html_e( 'active', 'trending-now' ); ?></option>
		<option value="stale" <?php selected( $advtn_filters['status'], 'stale' ); ?>><?php esc_html_e( 'stale', 'trending-now' ); ?></option>
	</select>

	<input type="search" name="f_search" value="<?php echo esc_attr( $advtn_filters['search'] ); ?>" placeholder="<?php esc_attr_e( 'title or URL', 'trending-now' ); ?>" />
	<button type="submit" class="button"><?php esc_html_e( 'Filter', 'trending-now' ); ?></button>
	<?php if ( $advtn_filtered ) : ?>
		<a class="button-link" href="<?php echo esc_url( $advtn_base ); ?>"><?php esc_html_e( 'Reset', 'trending-now' ); ?></a>
	<?php endif; ?>
</form>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<?php wp_nonce_field( 'advtn_action' ); ?>
	<input type="hidden" name="action" value="advtn_action" />
	<?php foreach ( array( 'f_source' => 'source_id', 'f_host' => 'host', 'f_type' => 'source_type', 'f_status' => 'status', 'f_search' => 'search' ) as $advtn_field => $advtn_key ) : ?>
		<input type="hidden" name="<?php echo esc_attr( $advtn_field ); ?>" value="<?php echo esc_attr( $advtn_filters[ $advtn_key ] ); ?>" />
	<?php endforeach; ?>

	<table class="widefat striped advtn-items">
		<thead>
			<tr>
				<td class="check-column"><input type="checkbox" class="advtn-check-all" /></td>
				<th><?php esc_html_e( 'Title', 'trending-now' ); ?></th>
				<th><?php esc_html_e( 'Host', 'trending-now' ); ?></th>
				<th><?php esc_html_e( 'Source', 'trending-now' ); ?></th>
				<th><?php esc_html_e( 'Published', 'trending-now' ); ?></th>
				<th><?php esc_html_e( 'Shown', 'trending-now' ); ?></th>
				<th><?php esc_html_e( 'Status', 'trending-now' ); ?></th>
				<th></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $advtn_rows ) ) : ?>
				<tr><td colspan="8"><?php esc_html_e( 'No items match.', 'trending-now' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $advtn_rows as $advtn_row ) : ?>
				<tr>
					<th scope="row" class="check-column">
						<input type="checkbox" name="item_ids[]" value="<?php echo esc_attr( (string) $advtn_row['id'] ); ?>" />
					</th>
					<td><a href="<?php echo esc_url( (string) $advtn_row['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( (string) $advtn_row['title'] ); ?></a></td>
					<td><?php echo esc_html( (string) $advtn_row['host'] ); ?></td>
					<td><code><?php echo esc_html( (string) $advtn_row['source_id'] ); ?></code><br /><?php echo esc_html( (string) $advtn_row['source_type'] ); ?></td>
					<td><?php echo esc_html( (string) ( $advtn_row['published_at'] ?? '—' ) ); ?></td>
					<td><?php echo esc_html( (string) $advtn_row['times_shown'] ); ?></td>
					<td><?php echo esc_html( (string) $advtn_row['status'] ); ?></td>
					<td>
						<button type="submit" class="button-link delete advtn-confirm" name="advtn_delete_item" value="<?php echo esc_attr( (string) $advtn_row['id'] ); ?>"><?php esc_html_e( 'Delete', 'trending-now' ); ?></button>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<p class="advtn-item-actions">
		<button type="submit" class="button advtn-confirm" name="advtn_do" value="delete_selected_items"><?php esc_html_e( 'Delete selected', 'trending-now' ); ?></button>

		<?php if ( $advtn_filtered ) : ?>
			<button type="submit" class="button advtn-confirm" name="advtn_do" value="delete_filtered_items">
				<?php
				printf(
					/* translators: %d: rows matching the current filter. */
					esc_html__( 'Delete all %d matching this filter', 'trending-now' ),
					(int) $advtn_matching
				);
				?>
			</button>
		<?php endif; ?>

		<?php if ( $advtn_ipages > 1 ) : ?>
			<span class="advtn-item-pager">
				<?php
				echo wp_kses_post(
					paginate_links(
						array(
							'base'      => add_query_arg( 'item_page', '%#%', $advtn_base . ( $advtn_filtered ? '&' . http_build_query( array_filter( array( 'f_source' => $advtn_filters['source_id'], 'f_host' => $advtn_filters['host'], 'f_type' => $advtn_filters['source_type'], 'f_status' => $advtn_filters['status'], 'f_search' => $advtn_filters['search'] ) ) ) : '' ) ),
							'format'    => '',
							'current'   => $advtn_ipage,
							'total'     => $advtn_ipages,
							'mid_size'  => 1,
							'prev_text' => '&laquo;',
							'next_text' => '&raquo;',
						)
					) ?? ''
				);
				?>
			</span>
		<?php endif; ?>
	</p>
</form>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="advtn-danger">
	<?php wp_nonce_field( 'advtn_action' ); ?>
	<input type="hidden" name="action" value="advtn_action" />
	<button type="submit" class="button advtn-confirm-hard" name="advtn_do" value="delete_all_items">
		<?php
		printf(
			/* translators: %d: total rows stored. */
			esc_html__( 'Delete everything (%d items)', 'trending-now' ),
			(int) $advtn_status['counts']['total']
		);
		?>
	</button>
	<span class="description"><?php esc_html_e( 'Empties the items table. Sources, settings and secrets are untouched, and the next ingest repopulates from whatever is still enabled.', 'trending-now' ); ?></span>
</form>

<h2>
	<?php
	printf(
		/* translators: %d: number of items in the live selection. */
		esc_html__( 'Current selection (%d)', 'trending-now' ),
		count( $advtn_selection )
	);
	?>
</h2>
<table class="widefat striped">
	<thead>
		<tr>
			<th>#</th>
			<th><?php esc_html_e( 'Title', 'trending-now' ); ?></th>
			<th><?php esc_html_e( 'Source', 'trending-now' ); ?></th>
			<th><?php esc_html_e( 'Shown', 'trending-now' ); ?></th>
			<th><?php esc_html_e( 'First shown', 'trending-now' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php if ( empty( $advtn_selection ) ) : ?>
			<tr><td colspan="5"><?php esc_html_e( 'No selection committed yet.', 'trending-now' ); ?></td></tr>
		<?php endif; ?>
		<?php foreach ( $advtn_selection as $advtn_i => $advtn_item ) : ?>
			<tr>
				<td><?php echo esc_html( (string) ( $advtn_i + 1 ) ); ?></td>
				<td><a href="<?php echo esc_url( (string) $advtn_item['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( (string) $advtn_item['title'] ); ?></a></td>
				<td><?php echo esc_html( (string) $advtn_item['site_name'] . ' · ' . (string) $advtn_item['source_type'] ); ?></td>
				<td><?php echo esc_html( (string) $advtn_item['times_shown'] ); ?></td>
				<td><?php echo esc_html( (string) ( $advtn_item['first_shown_at'] ?? '—' ) ); ?></td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>

<h2><?php esc_html_e( 'Render cache', 'trending-now' ); ?></h2>
<table class="widefat striped">
	<thead><tr><th><?php esc_html_e( 'Option key', 'trending-now' ); ?></th><th><?php esc_html_e( 'Bytes', 'trending-now' ); ?></th></tr></thead>
	<tbody>
		<?php if ( empty( $advtn_status['cache'] ) ) : ?>
			<tr><td colspan="2"><?php esc_html_e( 'No cached variants.', 'trending-now' ); ?></td></tr>
		<?php endif; ?>
		<?php foreach ( (array) $advtn_status['cache'] as $advtn_key => $advtn_bytes ) : ?>
			<tr><td><code><?php echo esc_html( (string) $advtn_key ); ?></code></td><td><?php echo esc_html( (string) $advtn_bytes ); ?></td></tr>
		<?php endforeach; ?>
	</tbody>
</table>

<h2><?php esc_html_e( 'Log', 'trending-now' ); ?></h2>
<p class="advtn-log-filter">
	<?php
	$advtn_levels = array( '' => __( 'all', 'trending-now' ) ) + array_combine( ADVTN_Logger::LEVELS, ADVTN_Logger::LEVELS );
	foreach ( $advtn_levels as $advtn_key => $advtn_label ) :
		$advtn_url = admin_url( 'admin.php?page=' . ADVTN_Admin::MENU_SLUG . '&tab=diagnostics' . ( '' !== $advtn_key ? '&level=' . $advtn_key : '' ) );
		?>
		<a class="button button-small <?php echo $advtn_level === $advtn_key ? 'button-primary' : ''; ?>" href="<?php echo esc_url( $advtn_url ); ?>"><?php echo esc_html( (string) $advtn_label ); ?></a>
	<?php endforeach; ?>
</p>
<table class="widefat striped advtn-log">
	<thead>
		<tr>
			<th><?php esc_html_e( 'Time (UTC)', 'trending-now' ); ?></th>
			<th><?php esc_html_e( 'Level', 'trending-now' ); ?></th>
			<th><?php esc_html_e( 'Message', 'trending-now' ); ?></th>
			<th><?php esc_html_e( 'Context', 'trending-now' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php if ( empty( $advtn_log ) ) : ?>
			<tr><td colspan="4"><?php esc_html_e( 'Log is empty.', 'trending-now' ); ?></td></tr>
		<?php endif; ?>
		<?php foreach ( $advtn_log as $advtn_entry ) : ?>
			<tr class="advtn-log--<?php echo esc_attr( (string) ( $advtn_entry['level'] ?? 'info' ) ); ?>">
				<td><?php echo esc_html( (string) ( $advtn_entry['time'] ?? '' ) ); ?></td>
				<td><?php echo esc_html( (string) ( $advtn_entry['level'] ?? '' ) ); ?></td>
				<td><?php echo esc_html( (string) ( $advtn_entry['message'] ?? '' ) ); ?></td>
				<td><code><?php echo esc_html( (string) wp_json_encode( $advtn_entry['context'] ?? array() ) ); ?></code></td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>
