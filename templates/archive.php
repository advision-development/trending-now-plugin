<?php
/**
 * Archive template for the "see all" page.
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

get_header();
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
		<ul class="<?php echo esc_attr( $advtn_prefix ); ?>-archive__items">
			<?php
			foreach ( $advtn_items as $advtn_item ) :
				$advtn_is_news = 'gdelt' === ( $advtn_item['source_type'] ?? '' );
				$advtn_date    = $advtn_renderer->item_date( $advtn_item );
				?>
				<li class="<?php echo esc_attr( $advtn_prefix ); ?>-archive__item <?php echo esc_attr( $advtn_prefix ); ?>-archive__item--<?php echo $advtn_is_news ? 'news' : 'network'; ?>">
					<a class="<?php echo esc_attr( $advtn_prefix ); ?>-archive__link" href="<?php echo esc_url( (string) $advtn_item['url'] ); ?>"<?php echo $advtn_renderer->link_attributes( $advtn_item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in link_attributes(). ?>><?php echo esc_html( (string) $advtn_item['title'] ); ?></a>
					<?php if ( ! empty( $advtn_item['site_name'] ) ) : ?>
						<span class="<?php echo esc_attr( $advtn_prefix ); ?>-archive__source"><?php echo esc_html( (string) $advtn_item['site_name'] ); ?></span>
					<?php endif; ?>
					<?php if ( null !== $advtn_date ) : ?>
						<time class="<?php echo esc_attr( $advtn_prefix ); ?>-archive__date" datetime="<?php echo esc_attr( $advtn_date['iso'] ); ?>"><?php echo esc_html( $advtn_date['label'] ); ?></time>
					<?php endif; ?>
					<?php if ( ! empty( $advtn_item['excerpt'] ) ) : ?>
						<p class="<?php echo esc_attr( $advtn_prefix ); ?>-archive__excerpt"><?php echo esc_html( (string) $advtn_item['excerpt'] ); ?></p>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
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
get_footer();
