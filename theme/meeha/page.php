<?php
/**
 * Default page template "পাতা": narrow column, breadcrumb, H1, optional lede (excerpt), last-updated line,
 * `.m-prose` body. No sidebar, no ads.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

meeha_enqueue_bundle( 'page' );
get_header();
?>
<main id="main" class="m-page">
	<div class="m-container">
	<?php
	while ( have_posts() ) :
		the_post();
		// Shortcode-driven pages (accounts, glance, latest, quotes, literature…) style themselves and use the normal
		// container width; plain prose styles and the 46rem reading column would squeeze/leak into them.
		$meeha_is_app_page = meeha_page_is_app( get_the_ID() );
		?>
		<article id="post-<?php the_ID(); ?>" <?php post_class( $meeha_is_app_page ? 'm-page__article m-page__article--app' : 'm-page__article' ); ?>>
			<?php if ( ! get_post_meta( get_the_ID(), '_meeha_hide_title', true ) ) : ?>
			<header class="m-page__head">
				<?php meeha_breadcrumb(); ?>
				<h1 class="m-page__title"><?php the_title(); ?></h1>
				<?php if ( has_excerpt() ) : ?>
					<p class="m-page__lede"><?php echo esc_html( get_the_excerpt() ); ?></p>
				<?php endif; ?>
				<p class="m-page__updated">সর্বশেষ হালনাগাদ: <time datetime="<?php echo esc_attr( get_the_modified_date( 'c' ) ); ?>"><?php echo esc_html( meeha_bn_digits( get_the_modified_date( 'j' ) ) . ' ' . meeha_bengali_month( (int) get_the_modified_date( 'n' ) ) . ' ' . meeha_bn_digits( get_the_modified_date( 'Y' ) ) ); ?></time></p>
			</header>
			<?php else : ?>
				<h1 class="m-vh"><?php the_title(); ?></h1>
			<?php endif; ?>
			<div class="m-page__body<?php echo $meeha_is_app_page ? ' m-page__body--app' : ' m-prose'; ?>">
				<?php
				the_content();
				wp_link_pages(
					array(
						'before' => '<nav class="m-pager" aria-label="অংশ">',
						'after'  => '</nav>',
					)
				);
				?>
			</div>
		</article>
		<?php
		if ( comments_open() || get_comments_number() ) {
			echo '<div class="m-page__comments">';
			comments_template();
			echo '</div>';
		}
	endwhile;
	?>
	</div>
</main>
<?php
get_footer();
