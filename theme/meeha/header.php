<?php
/**
 * Site header: date bar (mobile), masthead, section nav, ticker, optional header ad.
 * Compatible with Elementor's `elementor_header_footer` template and newsroom-os single-video.php
 * (both call get_header()/get_footer()).
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;
?><!doctype html>
<html <?php language_attributes(); ?> dir="ltr">
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<script>try{var t=localStorage.getItem('m-theme');if(t)document.documentElement.dataset.theme=t}catch(e){}</script>
<?php wp_head(); ?>
</head>
<body id="top" <?php body_class(); ?>>
<?php
meeha_print_inline_sprite();
wp_body_open();
?>
<a class="m-skip" href="#main">মূল কনটেন্টে যান</a>
<?php
get_template_part( 'template-parts/header/datebar' );
get_template_part( 'template-parts/header/masthead' );
get_template_part( 'template-parts/header/nav' );
if ( meeha_show_ticker() ) {
	get_template_part( 'template-parts/header/ticker' );
}
$meeha_header_ad = meeha_ad( 'header_leader' );
if ( $meeha_header_ad ) {
	echo '<div class="m-container m-adband m-adband--header">' . $meeha_header_ad . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built and escaped in meeha_ad().
}
unset( $meeha_header_ad );
