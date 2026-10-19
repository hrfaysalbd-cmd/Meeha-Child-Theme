<?php
/**
 * Single post (owner D). Variants (DESIGN-SPEC §10): news (default), investigative long-read, opinion, literature.
 * Other public post types without their own template fall back to the news layout.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

get_header();

while ( have_posts() ) :
	the_post();
	$meeha_post    = get_post();
	$meeha_variant = meeha_single_variant( $meeha_post );
	$meeha_part    = in_array( $meeha_variant, array( 'opinion', 'literature' ), true ) ? $meeha_variant : 'news';
	get_template_part(
		'template-parts/single/content',
		$meeha_part,
		array(
			'post'    => $meeha_post,
			'variant' => $meeha_variant,
		)
	);
endwhile;

get_footer();
