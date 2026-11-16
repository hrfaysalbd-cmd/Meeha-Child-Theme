<?php
/**
 * Homepage (DESIGN-SPEC §8): sections S1–S12 in the order configured under মিহা থিম › হোমপেজ.
 * No carousel; the lead image is the LCP element (eager + fetchpriority + preload).
 * A paged front page (/page/2/) shows the plain latest-posts archive.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

if ( is_paged() ) {
	require MEEHA_DIR . '/archive.php';
	return;
}

meeha_enqueue_bundle( 'home' );
foreach ( (array) apply_filters( 'meeha_home_extra_bundles', array() ) as $meeha_b ) {
	meeha_enqueue_bundle( $meeha_b );
}
$meeha_plan = meeha_home_prepare();
if ( ! empty( $meeha_plan['s1']['lead'] ) ) {
	$meeha_thumb = (int) get_post_thumbnail_id( $meeha_plan['s1']['lead'] );
	if ( $meeha_thumb ) {
		meeha_preload_img( $meeha_thumb, 'lead' );
	}
}
unset( $meeha_plan, $meeha_thumb, $meeha_b );

get_header();
?>
<main id="main" class="m-home">
<?php meeha_home_render(); ?>
</main>
<?php
get_footer();
