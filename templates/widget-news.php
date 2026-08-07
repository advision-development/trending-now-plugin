<?php
/**
 * News layout — source line, headline, right-hand thumbnail.
 *
 * Modelled on the Google News / MSN feed card. The thumbnail is not itself a
 * link: a second anchor to the same URL would dilute the one that carries the
 * headline as its anchor text, which is the whole point of the widget.
 *
 * Override from a theme at {theme}/trending-now/widget-news.php.
 *
 * @var array<int,array<string,mixed>> $items       Ordered item rows.
 * @var array<string,mixed>            $args        Normalized display args.
 * @var string                         $prefix      Class prefix.
 * @var string                         $heading_id  Unique heading id.
 * @var string                         $archive_url "See all" URL, or ''.
 * @var ADVTN_Renderer                 $renderer    Renderer instance.
 * @var ADVTN_Settings                 $settings    Settings instance.
 *
 * @package Advision\TrendingNow
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$p = $prefix;
?>
<section class="<?php echo esc_attr( $p ); ?> <?php echo esc_attr( $p ); ?>--news" aria-labelledby="<?php echo esc_attr( $heading_id ); ?>">
	<?php if ( '' !== $args['heading'] ) : ?>
		<h2 class="<?php echo esc_attr( $p ); ?>__heading" id="<?php echo esc_attr( $heading_id ); ?>"><?php echo esc_html( $args['heading'] ); ?></h2>
	<?php endif; ?>
	<ul class="<?php echo esc_attr( $p ); ?>__items">
		<?php
		foreach ( $items as $index => $item ) :
			$is_news = ADVTN_Source_Base::is_news_type( (string) ( $item['source_type'] ?? '' ) );
			$date    = $args['show_date'] ? $renderer->item_date( $item ) : null;
			$thumb   = $args['show_images'] ? (string) ( $item['image_url'] ?? '' ) : '';
			?>
			<li class="<?php echo esc_attr( $p ); ?>__item <?php echo esc_attr( $p ); ?>__item--<?php echo $is_news ? 'news' : 'network'; ?>">
				<div class="<?php echo esc_attr( $p ); ?>__body">
					<?php if ( $args['show_source'] || null !== $date || $args['show_icons'] ) : ?>
						<p class="<?php echo esc_attr( $p ); ?>__meta">
							<?php
							$advtn_icon = $args['show_icons'] ? $renderer->source_icon( $item ) : '';
							if ( '' !== $advtn_icon ) :
								?>
								<img class="<?php echo esc_attr( $p ); ?>__icon" src="<?php echo esc_url( $advtn_icon ); ?>" alt="" width="16" height="16" loading="lazy" decoding="async" />
								<?php
							endif;
							?>
							<?php if ( $args['show_source'] && ! empty( $item['site_name'] ) ) : ?>
								<span class="<?php echo esc_attr( $p ); ?>__source"><?php echo esc_html( (string) $item['site_name'] ); ?></span>
							<?php endif; ?>
							<?php if ( null !== $date ) : ?>
								<time class="<?php echo esc_attr( $p ); ?>__date" datetime="<?php echo esc_attr( $date['iso'] ); ?>"><?php echo esc_html( $date['label'] ); ?></time>
							<?php endif; ?>
						</p>
					<?php endif; ?>

					<a class="<?php echo esc_attr( $p ); ?>__link" href="<?php echo esc_url( (string) $item['url'] ); ?>"<?php echo $renderer->link_attributes( $item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in link_attributes(). ?>><?php echo esc_html( (string) $item['title'] ); ?></a>

					<?php if ( $args['show_excerpt'] && ! empty( $item['excerpt'] ) ) : ?>
						<p class="<?php echo esc_attr( $p ); ?>__excerpt"><?php echo esc_html( (string) $item['excerpt'] ); ?></p>
					<?php endif; ?>
				</div>

				<?php if ( '' !== $thumb ) : ?>
					<div class="<?php echo esc_attr( $p ); ?>__media">
						<img
							class="<?php echo esc_attr( $p ); ?>__thumb"
							src="<?php echo esc_url( $thumb ); ?>"
							alt=""
							width="240"
							height="135"
							decoding="async"
							<?php
							// The first card is usually above the fold, so lazy-loading
							// it would delay the one image that matters. Everything
							// below it defers.
							echo 0 === (int) $index ? 'fetchpriority="high"' : 'loading="lazy"';
							?>
						/>
					</div>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>
	<?php if ( '' !== $archive_url ) : ?>
		<p class="<?php echo esc_attr( $p ); ?>__more">
			<a class="<?php echo esc_attr( $p ); ?>__more-link" href="<?php echo esc_url( $archive_url ); ?>"><?php echo esc_html( $settings->get_string( 'see_all_text' ) ); ?></a>
		</p>
	<?php endif; ?>
</section>
