<?php
/**
 * Template Name: পাতা — ফাঁকা ক্যানভাস (হেডার-ফুটার ছাড়া)
 *
 * r49 — a landing-page canvas: no site header, menu, ticker, footer, floating ads or pop-up; the page's
 * content (usually one shortcode such as [meeha_advertise] or [meeha_app_landing]) fills the whole
 * width. The theme's fonts, colours and dark mode still apply, and wp_head()/wp_footer() still run
 * (SEO, analytics, push). Tick "শিরোনাম লুকান" to hide the page title too.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

meeha_enqueue_bundle( 'page' );
?><!doctype html>
<html <?php language_attributes(); ?> dir="ltr">
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<script>try{var t=localStorage.getItem('m-theme');if(t)document.documentElement.dataset.theme=t}catch(e){}</script>
<?php wp_head(); ?>
</head>
<body id="top" <?php body_class( 'm-canvas' ); ?>>
<?php
meeha_print_inline_sprite();
wp_body_open();
?>
<main id="main" class="m-page m-page--canvas">
	<?php
	while ( have_posts() ) :
		the_post();
		$meeha_hide = (bool) get_post_meta( get_the_ID(), '_meeha_hide_title', true );
		?>
		<article id="post-<?php the_ID(); ?>" <?php post_class( 'm-page__article m-page__article--canvas' ); ?>>
			<?php if ( ! $meeha_hide ) : ?>
				<div class="m-container">
					<header class="m-page__head">
						<h1 class="m-page__title"><?php the_title(); ?></h1>
					</header>
				</div>
			<?php endif; ?>
			<div class="m-page__body m-page__body--canvas">
				<?php the_content(); ?>
			</div>
		</article>
		<?php
	endwhile;
	?>
</main>
<?php wp_footer(); ?>
</body>
</html>
