<?php
/**
 * Category archive page - grid of posts + "আরও পড়ুন" (load more) + sidebar
 * with the area-based news filter and most-read.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
$meeha_cat = get_queried_object();
?>

<main id="meeha-single">
	<div class="meeha-container meeha-category-layout">

		<div class="meeha-category-main">
			<header class="meeha-category-header">
				<h1><?php single_cat_title(); ?></h1>
				<?php if ( category_description() ) : ?>
					<div class="meeha-category-header__desc"><?php echo wp_kses_post( category_description() ); ?></div>
				<?php endif; ?>
			</header>

			<div class="meeha-grid" id="meeha-category-grid">
				<?php
				while ( have_posts() ) :
					the_post();
					?>
					<a href="<?php the_permalink(); ?>" class="meeha-card">
						<div class="meeha-card__image"><?php meeha_card_thumbnail( 'medium' ); ?></div>
						<h3 class="meeha-card__title"><?php the_title(); ?></h3>
						<span class="meeha-meta"><?php echo esc_html( meeha_bengali_time_ago( get_the_time( 'U' ) ) ); ?></span>
					</a>
					<?php
				endwhile;
				?>
			</div>

			<p class="meeha-glance__loading" id="meeha-category-loading" hidden>লোড হচ্ছে...</p>
			<?php if ( $meeha_cat && $meeha_cat->count > get_query_var( 'posts_per_page', 10 ) ) : ?>
				<button type="button" class="meeha-glance__more" id="meeha-category-more" data-category="<?php echo esc_attr( $meeha_cat->term_id ); ?>" data-offset="<?php echo esc_attr( get_query_var( 'posts_per_page', 10 ) ); ?>">আরও পড়ুন</button>
			<?php endif; ?>
		</div>

		<?php meeha_render_single_sidebar( 0 ); ?>

	</div>
</main>

<?php get_footer(); ?>
