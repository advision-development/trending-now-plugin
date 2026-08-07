<?php
/**
 * Archive template for the "see all" page.
 *
 * Uses the same card markup and classes as the widget's news layout, so the
 * two stay identical without a second stylesheet to keep in step.
 *
 * Override from a theme at {theme}/trending-now/archive.php.
 *
 * @package Advision\TrendingNow
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$advtn_archive  = advtn()->archive();
$advtn_settings = advtn()->settings();
$advtn_renderer = advtn()->renderer();

$advtn_prefix = $advtn_settings->get_string( 'class_prefix' );
$advtn_items  = $advtn_archive->current_items();
$advtn_page   = $advtn_archive->current_page();
$advtn_pages  = $advtn_archive->total_pages();
$advtn_intro  = $advtn_settings->get_string( 'archive_intro' );

$advtn_show_images = $advtn_settings->get_bool( 'show_images' );
$advtn_show_icons  = $advtn_settings->get_bool( 'show_icons' );
$advtn_show_source = $advtn_settings->get_bool( 'show_source' );
$advtn_show_date   = $advtn_settings->get_bool( 'show_date' );
$advtn_show_excerpt = $advtn_settings->get_bool( 'show_excerpt' );

$advtn_archive->render_header();

// The widget emits this itself; the archive has to ask, and gets nothing back
// if a widget on the same page already did.
echo $advtn_renderer->inline_css_once(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- generated stylesheet.
?>
<main id="primary" class="<?php echo esc_attr( $advtn_prefix ); ?>-archive" role="main">
	<header class="<?php echo esc_attr( $advtn_prefix ); ?>-archive__header">
		<h1 class="<?php echo esc_attr( $advtn_prefix ); ?>-archive__title"><?php echo esc_html( $advtn_settings->get_string( 'heading_text' ) ); ?></h1>
		<?php if ( '' !== $advtn_intro ) : ?>
			<div class="<?php echo esc_attr( $advtn_prefix ); ?>-archive__intro"><?php echo wp_kses_post( wpautop( $advtn_intro ) ); ?></div>
		<?php endif; ?>
		<?php if ( $advtn_pages > 1 ) : ?>
			<p class="<?php echo esc_attr( $advtn_prefix ); ?>-archive__meta">
				<?php
				printf(
					/* translators: 1: current page, 2: total pages. */
					esc_html__( 'Page %1$d of %2$d', 'trending-now' ),
					(int) $advtn_page,
					(int) $advtn_pages
				);
				?>
			</p>
		<?php endif; ?>
	</header>

	<?php if ( empty( $advtn_items ) ) : ?>
		<p><?php esc_html_e( 'Nothing here yet.', 'trending-now' ); ?></p>
	<?php else : ?>
		<div class="<?php echo esc_attr( $advtn_prefix ); ?> <?php echo esc_attr( $advtn_prefix ); ?>--news">
			<ul class="<?php echo esc_attr( $advtn_prefix ); ?>__items">
				<?php
				foreach ( $advtn_items as $advtn_index => $advtn_item ) :
					$advtn_is_news = ADVTN_Source_Base::is_news_type( (string) ( $advtn_item['source_type'] ?? '' ) );
					$advtn_date    = $advtn_show_date ? $advtn_renderer->item_date( $advtn_item ) : null;
					$advtn_icon    = $advtn_show_icons ? $advtn_renderer->source_icon( $advtn_item ) : '';
					$advtn_thumb   = $advtn_show_images ? (string) ( $advtn_item['image_url'] ?? '' ) : '';
					?>
					<li class="<?php echo esc_attr( $advtn_prefix ); ?>__item <?php echo esc_attr( $advtn_prefix ); ?>__item--<?php echo $advtn_is_news ? 'news' : 'network'; ?>">
						<div class="<?php echo esc_attr( $advtn_prefix ); ?>__body">
							<?php if ( $advtn_show_source || null !== $advtn_date || '' !== $advtn_icon ) : ?>
								<p class="<?php echo esc_attr( $advtn_prefix ); ?>__meta">
									<?php if ( '' !== $advtn_icon ) : ?>
										<img class="<?php echo esc_attr( $advtn_prefix ); ?>__icon" src="<?php echo esc_url( $advtn_icon ); ?>" alt="" width="16" height="16" loading="lazy" decoding="async" />
									<?php endif; ?>
									<?php if ( $advtn_show_source && ! empty( $advtn_item['site_name'] ) ) : ?>
										<span class="<?php echo esc_attr( $advtn_prefix ); ?>__source"><?php echo esc_html( (string) $advtn_item['site_name'] ); ?></span>
									<?php endif; ?>
									<?php if ( null !== $advtn_date ) : ?>
										<time class="<?php echo esc_attr( $advtn_prefix ); ?>__date" datetime="<?php echo esc_attr( $advtn_date['iso'] ); ?>"><?php echo esc_html( $advtn_date['label'] ); ?></time>
									<?php endif; ?>
								</p>
							<?php endif; ?>

							<a class="<?php echo esc_attr( $advtn_prefix ); ?>__link" href="<?php echo esc_url( (string) $advtn_item['url'] ); ?>"<?php echo $advtn_renderer->link_attributes( $advtn_item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in link_attributes(). ?>><?php echo esc_html( (string) $advtn_item['title'] ); ?></a>

							<?php if ( $advtn_show_excerpt && ! empty( $advtn_item['excerpt'] ) ) : ?>
								<p class="<?php echo esc_attr( $advtn_prefix ); ?>__excerpt"><?php echo esc_html( (string) $advtn_item['excerpt'] ); ?></p>
							<?php endif; ?>
						</div>

						<?php if ( '' !== $advtn_thumb ) : ?>
							<div class="<?php echo esc_attr( $advtn_prefix ); ?>__media">
								<img
									class="<?php echo esc_attr( $advtn_prefix ); ?>__thumb"
									src="<?php echo esc_url( $advtn_thumb ); ?>"
									alt=""
									width="240"
									height="135"
									decoding="async"
									<?php echo 0 === (int) $advtn_index ? 'fetchpriority="high"' : 'loading="lazy"'; ?>
								/>
							</div>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>

	<?php if ( $advtn_pages > 1 ) : ?>
		<nav class="<?php echo esc_attr( $advtn_prefix ); ?>-archive__nav" aria-label="<?php esc_attr_e( 'Archive pagination', 'trending-now' ); ?>">
			<?php
			echo wp_kses_post(
				paginate_links(
					array(
						'base'      => $advtn_archive->page_url( 1 ) . '%_%',
						'format'    => 'page/%#%/',
						'current'   => $advtn_page,
						'total'     => $advtn_pages,
						'mid_size'  => 2,
						'prev_text' => __( '&laquo; Previous', 'trending-now' ),
						'next_text' => __( 'Next &raquo;', 'trending-now' ),
					)
				) ?? ''
			);
			?>
		</nav>
	<?php endif; ?>
</main>
<?php
$advtn_archive->render_footer();
