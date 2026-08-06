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
		<tr><th><?php esc_html_e( 'Versions', 'trending-now' ); ?></th><td><?php echo esc_html( sprintf( 'plugin %s / db %s', (string) $advtn_status['plugin_version'], (string) $advtn_status['db_version'] ) ); ?></td></tr>
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
