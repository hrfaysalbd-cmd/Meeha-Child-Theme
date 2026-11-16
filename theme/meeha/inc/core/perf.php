<?php
/**
 * Front-end performance: emoji/embeds off, block CSS only where content blocks render, MailPoet fonts + CSS out,
 * bangla-fonts-collection out, jQuery only when something depends on it, optional delayed Site Kit gtag.
 * Everything is behind a setting in মিহা থিম › পারফরম্যান্স.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shortcut for a performance setting.
 *
 * @param string $key Key.
 * @param int    $def Default.
 * @return bool
 */
function meeha_perf( $key, $def = 1 ) {
	return (bool) meeha_opt( 'performance', $key, $def );
}

/**
 * Is this a front-end page view we may optimise?
 *
 * @return bool
 */
function meeha_perf_frontend() {
	return ! is_admin() && ! is_customize_preview() && ! wp_doing_ajax() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) && ! is_feed() && ! is_embed();
}

/**
 * Does the current page render post content (where core block CSS is needed)?
 *
 * @return bool
 */
function meeha_page_has_block_content() {
	$has = is_singular() || is_attachment();
	return (bool) apply_filters( 'meeha_page_has_block_content', $has );
}

/* ---- emoji + embeds ---------------------------------------------------- */
add_action(
	'init',
	function () {
		if ( is_admin() ) {
			return;
		}
		if ( meeha_perf( 'disable_emoji' ) ) {
			remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
			remove_action( 'wp_print_styles', 'print_emoji_styles' );
			remove_action( 'wp_enqueue_scripts', 'wp_enqueue_emoji_styles' );
			remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
			remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
			remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
			add_filter( 'emoji_svg_url', '__return_false' );
		}
		if ( meeha_perf( 'disable_embeds' ) ) {
			remove_action( 'wp_head', 'wp_oembed_add_host_js' );
		}
		remove_action( 'wp_head', 'wp_generator' );
		remove_action( 'wp_head', 'wlwmanifest_link' );
	}
);

/* ---- core block CSS: per-block loading, and none on non-content pages ---- */
add_filter( 'should_load_separate_core_block_assets', '__return_true' );

/**
 * Dequeue unneeded styles (runs late on wp_enqueue_scripts and again right before printing).
 */
function meeha_perf_dequeue_styles() {
	if ( ! meeha_perf_frontend() ) {
		return;
	}
	global $wp_styles;
	if ( meeha_perf( 'trim_block_css' ) ) {
		if ( ! meeha_page_has_block_content() ) {
			foreach ( array( 'wp-block-library', 'wp-block-library-theme', 'global-styles', 'core-block-supports', 'core-block-supports-duotone' ) as $h ) {
				wp_dequeue_style( $h );
			}
		}
		wp_dequeue_style( 'classic-theme-styles' );
	}
	if ( ! $wp_styles instanceof WP_Styles ) {
		return;
	}
	foreach ( (array) $wp_styles->queue as $h ) {
		$src = isset( $wp_styles->registered[ $h ] ) ? (string) $wp_styles->registered[ $h ]->src : '';
		if ( meeha_perf( 'mailpoet_trim' ) && ( 0 === strpos( $h, 'mailpoet_custom_fonts' ) || 'mailpoet_public' === $h || false !== strpos( $src, 'fonts.googleapis.com/css?family=Abril' ) ) ) {
			wp_dequeue_style( $h );
		}
		if ( meeha_perf( 'bangla_fonts_trim' ) && ( false !== strpos( $src, '/plugins/bangla-fonts' ) || 0 === strpos( $h, 'bangla-font' ) || 0 === strpos( $h, 'bfc-' ) ) ) {
			wp_dequeue_style( $h );
		}
		if ( 'wp-embed' === $h && meeha_perf( 'disable_embeds' ) ) {
			wp_dequeue_style( $h );
		}
	}
}
add_action( 'wp_enqueue_scripts', 'meeha_perf_dequeue_styles', 1000 );
add_action( 'wp_print_styles', 'meeha_perf_dequeue_styles', 1000 );
add_action( 'wp_print_footer_scripts', 'meeha_perf_dequeue_styles', 1 );

add_filter(
	'mailpoet_display_custom_fonts',
	function ( $show ) {
		return meeha_perf( 'mailpoet_trim' ) ? false : $show;
	}
);

/* ---- legacy Google Fonts handle: newsroom-os registers `meeha-google-fonts` (Baloo/Hind/Inter) only when it is
   not registered yet — the theme claims the handle as an empty alias so nothing is downloaded. ---- */
add_action(
	'wp_enqueue_scripts',
	function () {
		if ( ! wp_style_is( 'meeha-google-fonts', 'registered' ) ) {
			wp_register_style( 'meeha-google-fonts', false, array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		}
	},
	1
);

/* ---- scripts: wp-embed, MailPoet defer, jQuery only when needed ---- */

/**
 * Does any queued script (recursively) depend on jQuery, or carry jQuery inline code?
 *
 * @param WP_Scripts $s Scripts.
 * @return bool
 */
function meeha_jquery_needed( $s ) {
	$jq = array( 'jquery', 'jquery-core', 'jquery-migrate' );
	foreach ( $jq as $h ) {
		if ( $s->get_data( $h, 'after' ) || $s->get_data( $h, 'before' ) || $s->get_data( $h, 'data' ) ) {
			return true;
		}
	}
	$seen  = array();
	$stack = array_values( array_diff( (array) $s->queue, $jq ) );
	while ( $stack ) {
		$h = array_pop( $stack );
		if ( isset( $seen[ $h ] ) || ! isset( $s->registered[ $h ] ) ) {
			continue;
		}
		$seen[ $h ] = true;
		foreach ( (array) $s->registered[ $h ]->deps as $d ) {
			if ( in_array( $d, $jq, true ) ) {
				return true;
			}
			$stack[] = $d;
		}
	}
	return false;
}

/**
 * Trim scripts right before they print (head and footer).
 */
function meeha_perf_scripts() {
	if ( ! meeha_perf_frontend() ) {
		return;
	}
	global $wp_scripts;
	if ( ! $wp_scripts instanceof WP_Scripts ) {
		return;
	}
	if ( meeha_perf( 'disable_embeds' ) ) {
		wp_dequeue_script( 'wp-embed' );
	}
	if ( isset( $wp_scripts->registered['mailpoet_public'] ) && ! $wp_scripts->get_data( 'mailpoet_public', 'strategy' ) ) {
		wp_script_add_data( 'mailpoet_public', 'strategy', 'defer' );
	}
	if ( meeha_perf( 'jquery_trim' ) && ! apply_filters( 'meeha_keep_jquery', false ) && ! is_user_logged_in() ) {
		if ( array_intersect( array( 'jquery', 'jquery-core', 'jquery-migrate' ), (array) $wp_scripts->queue ) && ! meeha_jquery_needed( $wp_scripts ) ) {
			wp_dequeue_script( 'jquery' );
			wp_dequeue_script( 'jquery-core' );
			wp_dequeue_script( 'jquery-migrate' );
		}
	}
}
add_action( 'wp_print_scripts', 'meeha_perf_scripts', 1000 );
add_action( 'wp_print_footer_scripts', 'meeha_perf_scripts', 1 );

/* ---- Site Kit gtag: delay until first interaction or N seconds (opt-in) ---- */
add_filter(
	'script_loader_tag',
	function ( $tag, $handle, $src ) {
		if ( 'google_gtagjs' !== $handle || ! meeha_perf_frontend() || ! meeha_perf( 'delay_gtag', 0 ) ) {
			return $tag;
		}
		$GLOBALS['meeha_delayed_gtag'] = $src;
		return '<script type="text/plain" id="google_gtagjs-js" data-meeha-delay-src="' . esc_url( $src ) . '"></script>' . "\n";
	},
	20,
	3
);

add_action(
	'wp_footer',
	function () {
		if ( empty( $GLOBALS['meeha_delayed_gtag'] ) ) {
			return;
		}
		$ms = max( 1, (int) meeha_opt( 'performance', 'gtag_delay', 5 ) ) * 1000;
		?>
<script>(function(){var d=false,ev=['pointerdown','keydown','touchstart','scroll'];function go(){if(d)return;d=true;ev.forEach(function(e){removeEventListener(e,go,{passive:true})});var o=document.querySelector('script[data-meeha-delay-src]');if(!o)return;var s=document.createElement('script');s.async=true;s.src=o.getAttribute('data-meeha-delay-src');document.head.appendChild(s);}ev.forEach(function(e){addEventListener(e,go,{passive:true,once:true})});setTimeout(go,<?php echo (int) $ms; ?>);})();</script>
		<?php
	},
	99
);
