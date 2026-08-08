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

	// Mirrors applyType() in admin.js so the wrong fields never flash on load.
	$for_types = static fn( array $types ): string => in_array( $type, $types, true ) ? '' : ' hidden';
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
					<option value="serpapi" <?php selected( $type, 'serpapi' ); ?>><?php esc_html_e( 'Google News (SerpAPI)', 'trending-now' ); ?></option>
				</select>
			</label>

			<label>
				<span><?php esc_html_e( 'Items per cycle', 'trending-now' ); ?></span>
				<input type="number" min="1" max="250" name="sources[<?php echo esc_attr( (string) $index ); ?>][limit]" value="<?php echo esc_attr( (string) ( $source['limit'] ?? 10 ) ); ?>" />
			</label>

			<label>
				<span><?php esc_html_e( 'Timeout (seconds)', 'trending-now' ); ?></span>
				<input
					type="number"
					min="0"
					max="120"
					name="sources[<?php echo esc_attr( (string) $index ); ?>][timeout]"
					value="<?php echo esc_attr( (string) ( $source['timeout'] ?? 0 ) ); ?>"
					placeholder="<?php echo esc_attr( sprintf( /* translators: %d: global timeout in seconds. */ __( 'global (%d)', 'trending-now' ), advtn()->settings()->get_int( 'http_timeout', 1, 60 ) ) ); ?>"
				/>
				<em><?php esc_html_e( 'Blank or 0 uses the global setting. Raise it for a slow provider — SerpAPI does a live scrape and can exceed the 5s default under load.', 'trending-now' ); ?></em>
			</label>

			<label class="advtn-check">
				<input type="checkbox" name="sources[<?php echo esc_attr( (string) $index ); ?>][enabled]" value="1" <?php checked( ! empty( $source['enabled'] ) ); ?> />
				<span><?php esc_html_e( 'Enabled', 'trending-now' ); ?></span>
			</label>

			<label class="advtn-type-field advtn-wide" data-types="wp_rest rss"<?php echo esc_attr( $for_types( array( 'wp_rest', 'rss' ) ) ); ?>>
				<span><?php esc_html_e( 'URL', 'trending-now' ); ?></span>
				<input type="url" name="sources[<?php echo esc_attr( (string) $index ); ?>][url]" value="<?php echo esc_attr( (string) ( $source['url'] ?? '' ) ); ?>" placeholder="https://example.com" />
				<em><?php esc_html_e( 'Site root for REST sources; full feed URL for RSS.', 'trending-now' ); ?></em>
			</label>

												<label class="advtn-type-field" data-types="serpapi"<?php echo esc_attr( $for_types( array( 'serpapi' ) ) ); ?>>
				<span><?php esc_html_e( 'Feed', 'trending-now' ); ?></span>
				<select name="sources[<?php echo esc_attr( (string) $index ); ?>][serp_mode]">
					<option value="top_stories" <?php selected( (string) ( $source['serp_mode'] ?? 'search' ), 'top_stories' ); ?>><?php esc_html_e( 'Top stories (mainstream front page)', 'trending-now' ); ?></option>
					<option value="search" <?php selected( (string) ( $source['serp_mode'] ?? 'search' ), 'search' ); ?>><?php esc_html_e( 'Search query', 'trending-now' ); ?></option>
				</select>
				<em><?php esc_html_e( 'Top stories returns what Google News leads with for the country and language below — no query needed.', 'trending-now' ); ?></em>
			</label>

			<label class="advtn-type-field advtn-wide" data-types="serpapi"<?php echo esc_attr( $for_types( array( 'serpapi' ) ) ); ?>>
				<span><?php esc_html_e( 'Search query', 'trending-now' ); ?></span>
				<input type="text" name="sources[<?php echo esc_attr( (string) $index ); ?>][serp_query]" value="<?php echo esc_attr( (string) ( $source['serp_query'] ?? '' ) ); ?>" placeholder="sports betting odds" />
				<em><?php esc_html_e( 'Only used when Feed is set to Search query. Passed to Google News as-is; operators such as site:example.com and quoted phrases work.', 'trending-now' ); ?></em>
			</label>

			<label class="advtn-type-field advtn-wide" data-types="serpapi"<?php echo esc_attr( $for_types( array( 'serpapi' ) ) ); ?>>
				<span><?php esc_html_e( 'Allowed domains', 'trending-now' ); ?></span>
				<input type="text" name="sources[<?php echo esc_attr( (string) $index ); ?>][serp_domains]" value="<?php echo esc_attr( implode( ', ', array_map( 'strval', (array) ( $source['serp_domains'] ?? array() ) ) ) ); ?>" placeholder="espn.com, reuters.com" />
				<em><?php esc_html_e( 'Optional. Leave empty to accept whatever Google News returns; otherwise results are filtered to these hosts after the response.', 'trending-now' ); ?></em>
			</label>

			<label class="advtn-type-field" data-types="serpapi"<?php echo esc_attr( $for_types( array( 'serpapi' ) ) ); ?>>
				<span><?php esc_html_e( 'Country', 'trending-now' ); ?></span>
				<input type="text" name="sources[<?php echo esc_attr( (string) $index ); ?>][serp_country]" value="<?php echo esc_attr( (string) ( $source['serp_country'] ?? 'us' ) ); ?>" placeholder="us" />
				<em><?php esc_html_e( 'Two-letter code, e.g. us, gb, au.', 'trending-now' ); ?></em>
			</label>

			<label class="advtn-type-field" data-types="serpapi"<?php echo esc_attr( $for_types( array( 'serpapi' ) ) ); ?>>
				<span><?php esc_html_e( 'Language', 'trending-now' ); ?></span>
				<input type="text" name="sources[<?php echo esc_attr( (string) $index ); ?>][serp_language]" value="<?php echo esc_attr( (string) ( $source['serp_language'] ?? 'en' ) ); ?>" placeholder="en" />
			</label>

			<label class="advtn-type-field" data-types="serpapi"<?php echo esc_attr( $for_types( array( 'serpapi' ) ) ); ?>>
				<span><?php esc_html_e( 'Items per cycle', 'trending-now' ); ?></span>
				<em><?php esc_html_e( 'Set above. Each fetch costs one SerpAPI search credit regardless of how many items come back.', 'trending-now' ); ?></em>
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

<hr />

<h2><?php esc_html_e( 'Import / export', 'trending-now' ); ?></h2>
<p class="description">
	<?php esc_html_e( 'Move a source list between installs. Every imported row is validated exactly as though it had been typed into the form, so a bad row is reported and skipped rather than saved.', 'trending-now' ); ?>
</p>

<div class="advtn-portability">
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="advtn-portability__export">
		<?php wp_nonce_field( 'advtn_export_sources' ); ?>
		<input type="hidden" name="action" value="advtn_export_sources" />
		<h3><?php esc_html_e( 'Export', 'trending-now' ); ?></h3>
		<p class="description">
			<?php
			printf(
				/* translators: %d: number of configured sources. */
				esc_html__( 'Downloads all %d configured source(s) as JSON. Runtime state, counters and secrets are not included.', 'trending-now' ),
				count( $advtn_sources )
			);
			?>
		</p>
		<p><button type="submit" class="button" <?php disabled( empty( $advtn_sources ) ); ?>><?php esc_html_e( 'Download JSON', 'trending-now' ); ?></button></p>
	</form>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="advtn-portability__import">
		<?php wp_nonce_field( 'advtn_import_sources' ); ?>
		<input type="hidden" name="action" value="advtn_import_sources" />
		<h3><?php esc_html_e( 'Import', 'trending-now' ); ?></h3>

		<p>
			<label for="advtn-import-file"><strong><?php esc_html_e( 'JSON file', 'trending-now' ); ?></strong></label><br />
			<input type="file" id="advtn-import-file" name="advtn_import_file" accept="application/json,.json" />
		</p>

		<p>
			<label for="advtn-import-json"><strong><?php esc_html_e( '…or paste JSON', 'trending-now' ); ?></strong></label><br />
			<textarea id="advtn-import-json" name="advtn_import_json" rows="5" class="large-text code" placeholder='{"sources":[{"label":"Example","type":"wp_rest","url":"https://example.com","limit":10,"enabled":true}]}'></textarea>
			<span class="description"><?php esc_html_e( 'An exported file, or a bare array of source rows. A chosen file wins over pasted text.', 'trending-now' ); ?></span>
		</p>

		<fieldset>
			<legend class="screen-reader-text"><?php esc_html_e( 'Import strategy', 'trending-now' ); ?></legend>
			<p>
				<label>
					<input type="radio" name="advtn_import_mode" value="merge" checked="checked" />
					<?php esc_html_e( 'Merge — update rows whose id matches, append the rest', 'trending-now' ); ?>
				</label><br />
				<label>
					<input type="radio" name="advtn_import_mode" value="replace" />
					<?php esc_html_e( 'Replace — discard the current list entirely', 'trending-now' ); ?>
				</label>
			</p>
		</fieldset>

		<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Import sources', 'trending-now' ); ?></button></p>
	</form>
</div>
