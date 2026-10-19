<?php
/**
 * Template Name: পাতা — পূর্ণ প্রস্থ
 *
 * Full container width, for shortcode pages (সাহিত্য-সংস্কৃতি, একনজরে, ভিডিও…). Tick "শিরোনাম লুকান"
 * (page sidebar) to drop the title block when the shortcode brings its own heading.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

meeha_enqueue_bundle( 'page' );
get_header();
?>
<main id="main" class="m-page m-page--full">
	<?php
	while ( have_posts() ) :
		the_post();
		$meeha_hide = (bool) get_post_meta( get_the_ID(), '_meeha_hide_title', true );
		$meeha_app  = meeha_page_is_app( get_the_ID() ); // app roots contain themselves (page.css); bands stay full-bleed.
		?>
		<article id="post-<?php the_ID(); ?>" <?php post_class( $meeha_app ? 'm-page__article m-page__article--app' : 'm-page__article' ); ?>>
			<?php if ( ! $meeha_hide ) : ?>
			<div class="m-container">
				<header class="m-page__head">
					<?php meeha_breadcrumb(); ?>
					<h1 class="m-page__title"><?php the_title(); ?></h1>
					<?php if ( has_excerpt() ) : ?>
						<p class="m-page__lede"><?php echo esc_html( get_the_excerpt() ); ?></p>
					<?php endif; ?>
				</header>
			</div>
			<?php endif; ?>
			<div class="m-page__body m-page__body--full">
				<?php the_content(); ?>
			</div>
		</article>
		<?php
	endwhile;
	?>
</main>
<?php
get_footer();
