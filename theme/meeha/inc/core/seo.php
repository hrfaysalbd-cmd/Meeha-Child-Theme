<?php
/**
 * SEO light: OG/Twitter fallback (legacy function name kept — the newsroom-os plugin checks it exists; no-op when
 * Yoast / Rank Math / AIOSEO is active), noindex on 404.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

/**
 * Open Graph + Twitter meta fallback. (legacy, FP:1077 — same output; adds the default share image from
 * মিহা থিম › সোশ্যাল before falling back to the custom logo, and twitter:site when an X handle is set.)
 */
function meeha_output_social_meta_tags() {
	if ( defined( 'WPSEO_VERSION' ) || class_exists( 'RankMath' ) || defined( 'AIOSEO_VERSION' ) ) {
		return;
	}

	$image        = '';
	$image_width  = 0;
	$image_height = 0;
	$logo_id      = get_theme_mod( 'custom_logo' );

	if ( is_singular() ) {
		$post     = get_queried_object();
		$title    = get_the_title( $post );
		$raw_desc = has_excerpt( $post ) ? get_the_excerpt( $post ) : $post->post_content;
		$summary  = meeha_get_summary( $post->ID );
		if ( $summary ) {
			$raw_desc = $summary;
		}
		$desc = post_password_required( $post ) ? get_bloginfo( 'description' ) : wp_trim_words( wp_strip_all_tags( strip_shortcodes( $raw_desc ) ), 30 );
		$url  = get_permalink( $post );
		$type = 'post' === get_post_type( $post ) ? 'article' : 'website';

		$thumb_id = get_post_thumbnail_id( $post );
		if ( $thumb_id ) {
			$img_data = wp_get_attachment_image_src( $thumb_id, 'large' );
			if ( $img_data ) {
				list( $image, $image_width, $image_height ) = $img_data;
			}
		}
	} else {
		$title = get_bloginfo( 'name' );
		$desc  = get_bloginfo( 'description' );
		$url   = home_url( add_query_arg( array(), '' ) );
		$type  = 'website';
	}

	$og_default = (int) meeha_opt( 'social', 'og_image', 0 );
	foreach ( array( $og_default, (int) $logo_id ) as $fallback ) {
		if ( ! $image && $fallback ) {
			$img_data = wp_get_attachment_image_src( $fallback, 'full' );
			if ( $img_data ) {
				list( $image, $image_width, $image_height ) = $img_data;
			}
		}
	}
	$handle = trim( (string) meeha_opt( 'social', 'x_handle', '' ) );
	?>
	<meta property="og:type" content="<?php echo esc_attr( $type ); ?>" />
	<meta property="og:title" content="<?php echo esc_attr( $title ); ?>" />
	<meta property="og:description" content="<?php echo esc_attr( $desc ); ?>" />
	<meta property="og:url" content="<?php echo esc_url( $url ); ?>" />
	<meta property="og:site_name" content="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" />
	<meta property="og:locale" content="bn_BD" />
	<?php if ( $image ) : ?>
		<meta property="og:image" content="<?php echo esc_url( $image ); ?>" />
		<meta property="og:image:secure_url" content="<?php echo esc_url( $image ); ?>" />
		<?php if ( $image_width && $image_height ) : ?>
			<meta property="og:image:width" content="<?php echo esc_attr( $image_width ); ?>" />
			<meta property="og:image:height" content="<?php echo esc_attr( $image_height ); ?>" />
		<?php endif; ?>
		<meta name="twitter:card" content="summary_large_image" />
		<meta name="twitter:image" content="<?php echo esc_url( $image ); ?>" />
	<?php else : ?>
		<meta name="twitter:card" content="summary" />
	<?php endif; ?>
	<meta name="twitter:title" content="<?php echo esc_attr( $title ); ?>" />
	<meta name="twitter:description" content="<?php echo esc_attr( $desc ); ?>" />
	<?php if ( $handle ) : ?>
	<meta name="twitter:site" content="<?php echo esc_attr( '@' . ltrim( $handle, '@' ) ); ?>" />
	<?php endif; ?>
	<?php
}
add_action( 'wp_head', 'meeha_output_social_meta_tags', 1 );

add_filter(
	'wp_robots',
	function ( $robots ) {
		if ( is_404() ) {
			$robots['noindex'] = true;
			$robots['follow']  = true;
		}
		return $robots;
	}
);
