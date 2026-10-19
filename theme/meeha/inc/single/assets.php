<?php
/**
 * Single-post assets, head hints and body classes.
 *
 * - bundle `single` (single.css critical + single.js deferred) on post singulars; `literature` on literature posts;
 * - the lead image is preloaded (LCP) — role 'lead';
 * - the reader's font scale is applied in <head> before first paint (CSS variable --m-font-scale on <html>,
 *   used by .m-prose) — no layout jump after load;
 * - no ads on literature (filter on A's meeha_ad_html);
 * - comments bundle: on demand for posts; normally enqueued on quote/video singulars that show comments.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

/**
 * Is the current request a D-rendered post single (post type `post` or a generic singular using single.php)?
 *
 * @return bool
 */
function meeha_single_is_article_view() {
	return is_singular() && ! is_page() && ! is_singular( array( 'meeha_photocard', 'meeha_quote', 'meeha_video', 'attachment' ) );
}

add_action(
	'wp',
	function () {
		if ( is_admin() || ! meeha_single_is_article_view() ) {
			return;
		}
		$post = get_queried_object();
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		$variant                        = meeha_single_variant( $post );
		$GLOBALS['meeha_single_variant'] = $variant;
		meeha_enqueue_bundle( 'single' );
		if ( 'literature' === $variant ) {
			meeha_enqueue_bundle( 'literature' );
		}
		// LCP: the lead image (not shown by default on opinion pieces or poems).
		$genre = ( 'literature' === $variant && function_exists( 'meeha_lit_genre_of' ) ) ? meeha_lit_genre_of( $post ) : '';
		if ( 'opinion' !== $variant && 'poem' !== $genre && has_post_thumbnail( $post ) ) {
			meeha_preload_img( (int) get_post_thumbnail_id( $post ), 'lead' );
		}
	}
);

/* Font scale before paint + `m-dekjs` (the dek may be clamped: single.js adds the toggle) — article views only. */
add_action(
	'wp_head',
	function () {
		if ( ! meeha_single_is_article_view() ) {
			return;
		}
		echo "<script>document.documentElement.classList.add('m-dekjs');try{var s=parseFloat(localStorage.getItem('meeha_font_scale'));if(s>=.85&&s<=1.4&&s!==1)document.documentElement.style.setProperty('--m-font-scale',s)}catch(e){}</script>\n";
	},
	1
);

/* Literature pages: preload the literary font too. */
add_filter(
	'meeha_preload_fonts',
	function ( $fonts ) {
		if ( 'literature' === meeha_current_template_key() ) {
			$fonts[] = 'meeha-literary-bn-400.woff2';
		}
		return $fonts;
	}
);

/* No ads on literature pages (editorial dignity, DESIGN-SPEC §10.2 / §22). */
add_filter(
	'meeha_ad_html',
	function ( $html ) {
		return ( ! is_admin() && 'literature' === meeha_current_template_key() ) ? '' : $html;
	}
);

/* Body classes. */
add_filter(
	'body_class',
	function ( $classes ) {
		if ( meeha_single_is_article_view() ) {
			$v         = isset( $GLOBALS['meeha_single_variant'] ) ? $GLOBALS['meeha_single_variant'] : meeha_single_variant( get_queried_object() );
			$classes[] = 'm-single';
			$classes[] = 'm-single--' . sanitize_html_class( $v );
			if ( 'literature' === $v ) {
				$classes[] = 'm-lit';
			}
		}
		return $classes;
	}
);
