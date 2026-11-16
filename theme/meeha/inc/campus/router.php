<?php
/**
 * Hub router: one WordPress page (with `[meeha_campus]`) serves every view of the section at
 * {page path}/{view}/{id}/ (plain permalinks: ?mc_view=&mc_id=). Views come from `meeha_campus_views()`; the
 * plugin adds its own. Also: test mode, SEO (title, canonical, robots for core, Rank Math and Yoast), page-cache
 * bypass for personal views, template key, ad slots.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

/**
 * Hub page id (0 when not set up or not a page).
 *
 * @return int
 */
function meeha_campus_hub_id() {
	$id = (int) meeha_opt( 'campus', 'hub_page', 0 );
	return ( $id && 'page' === get_post_type( $id ) ) ? $id : 0;
}

/**
 * Is the plugin engine active?
 *
 * @return bool
 */
function meeha_campus_has_engine() {
	return (bool) apply_filters( 'meeha_campus_has_engine', false );
}

/**
 * URL of a view.
 *
 * @param string     $view View.
 * @param string|int $id   Id segment.
 * @param array      $args Query args.
 * @return string
 */
function meeha_campus_url( $view = 'home', $id = '', $args = array() ) {
	$hub = meeha_campus_hub_id();
	if ( ! $hub ) {
		return home_url( '/' );
	}
	$base = get_permalink( $hub );
	$view = sanitize_key( (string) $view );
	$id   = preg_replace( '/[^A-Za-z0-9\-]/', '', (string) $id );
	if ( '' === $view || 'home' === $view ) {
		$url = $base;
	} elseif ( get_option( 'permalink_structure' ) && false === strpos( $base, '?' ) ) {
		$url = trailingslashit( $base ) . $view . '/' . ( '' !== $id ? $id . '/' : '' );
	} else {
		$url = add_query_arg( array_filter( array( 'mc_view' => $view, 'mc_id' => $id ) ), $base );
	}
	return $args ? add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $args ) ), $url ) : $url;
}

/**
 * Rewrite rule for the hub's sub-views.
 */
function meeha_campus_rewrites() {
	$hub = meeha_campus_hub_id();
	if ( ! $hub ) {
		return;
	}
	$path = trim( (string) get_page_uri( $hub ), '/' );
	if ( '' === $path ) {
		return;
	}
	add_rewrite_rule( '^' . preg_quote( $path, '#' ) . '/([a-z][a-z0-9-]*)(?:/([A-Za-z0-9-]+))?/?$', 'index.php?page_id=' . $hub . '&mc_view=$matches[1]&mc_id=$matches[2]', 'top' );
}
add_action( 'init', 'meeha_campus_rewrites' );

/**
 * Flush rules once when the hub page or its path changed (or the plugin asked for it on activation).
 */
function meeha_campus_maybe_flush() {
	$hub  = meeha_campus_hub_id();
	$path = $hub ? (string) get_page_uri( $hub ) : '';
	if ( get_option( 'meeha_campus_flush' ) || get_option( 'mhp_flush_rewrite' ) || get_option( 'meeha_campus_rw_path' ) !== $path ) {
		flush_rewrite_rules( false );
		update_option( 'meeha_campus_rw_path', $path, true );
		delete_option( 'meeha_campus_flush' );
		delete_option( 'mhp_flush_rewrite' );
	}
}
add_action( 'init', 'meeha_campus_maybe_flush', 99 );

/**
 * Flush when the hub page's slug/parent changes.
 *
 * @param int $post_id Post.
 */
function meeha_campus_page_saved( $post_id ) {
	if ( (int) $post_id === meeha_campus_hub_id() ) {
		update_option( 'meeha_campus_flush', 1, false );
	}
}
add_action( 'save_post_page', 'meeha_campus_page_saved' );

/**
 * Query vars.
 *
 * @param array $vars Vars.
 * @return array
 */
function meeha_campus_query_vars( $vars ) {
	$vars[] = 'mc_view';
	$vars[] = 'mc_id';
	return $vars;
}
add_filter( 'query_vars', 'meeha_campus_query_vars' );

/**
 * Registered views: theme's own + the plugin's (filter `meeha_campus_views`).
 * Keys: title (string|callable), tab, root, back, needs_id, bar, nocache, noindex, chrome, render.
 *
 * @return array
 */
function meeha_campus_views() {
	static $views = null;
	if ( null === $views ) {
		$views = apply_filters(
			'meeha_campus_views',
			array(
				'home'   => array( 'title' => '', 'tab' => 'home', 'root' => true, 'render' => 'meeha_campus_render_home' ),
				'news'   => array( 'title' => 'meeha_campus_title_news', 'tab' => 'home', 'back' => 'home', 'render' => 'meeha_campus_render_news' ),
				'career' => array( 'title' => 'ক্যারিয়ার গাইড', 'tab' => 'home', 'back' => 'home', 'render' => 'meeha_campus_render_career' ),
				'search' => array( 'title' => 'খুঁজুন', 'tab' => 'home', 'back' => 'home', 'noindex' => true, 'render' => 'meeha_campus_render_search' ),
			)
		);
	}
	return $views;
}

/**
 * Is this request the hub page?
 *
 * @return bool
 */
function meeha_campus_is_hub() {
	$hub = meeha_campus_hub_id();
	return $hub && did_action( 'wp' ) && is_page( $hub );
}

/**
 * Current view: view, id, def (null when unknown).
 *
 * @return array
 */
function meeha_campus_view() {
	static $cur = null;
	if ( null !== $cur && did_action( 'wp' ) ) {
		return $cur;
	}
	$view  = sanitize_key( (string) get_query_var( 'mc_view' ) );
	$view  = '' === $view ? 'home' : $view;
	$id    = preg_replace( '/[^A-Za-z0-9\-]/', '', (string) get_query_var( 'mc_id' ) );
	$views = meeha_campus_views();
	$def   = isset( $views[ $view ] ) ? wp_parse_args( $views[ $view ], array( 'title' => '', 'tab' => 'home', 'root' => false, 'back' => 'home', 'needs_id' => false, 'bar' => true, 'nocache' => false, 'noindex' => false, 'chrome' => '', 'render' => null ) ) : null;
	if ( $def && $def['needs_id'] && '' === $id ) {
		$def = null;
	}
	$out = array(
		'view' => $view,
		'id'   => $id,
		'def'  => $def,
	);
	if ( did_action( 'wp' ) ) {
		$cur = $out;
	}
	return $out;
}

/**
 * Title of the current view.
 *
 * @return string
 */
function meeha_campus_view_title() {
	$v = meeha_campus_view();
	if ( ! $v['def'] ) {
		return 'পাওয়া যায়নি';
	}
	$t = $v['def']['title'];
	if ( is_callable( $t ) ) {
		$t = call_user_func( $t, $v['id'] );
	}
	if ( 'home' === $v['view'] || '' === (string) $t ) {
		return (string) meeha_opt( 'campus', 'title', 'শিক্ষা ও ক্যাম্পাস' );
	}
	return (string) $t;
}

/**
 * Test mode keeps the section to staff.
 *
 * @return bool
 */
function meeha_campus_beta_blocked() {
	return (bool) meeha_opt( 'campus', 'beta', 1 ) && ! current_user_can( 'edit_posts' );
}

/**
 * Should the current view stay out of search engines?
 *
 * @return bool
 */
function meeha_campus_noindex() {
	$v = meeha_campus_view();
	return (bool) meeha_opt( 'campus', 'beta', 1 ) || ! $v['def'] || ! empty( $v['def']['noindex'] ) || isset( $_GET['pg'] ) && (int) $_GET['pg'] > 20; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
}

/**
 * Keep personal pages out of page caches.
 */
function meeha_campus_nocache() {
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
	do_action( 'litespeed_control_set_nocache', 'meeha campus personal view' );
	nocache_headers();
}

/**
 * Request setup on the hub: 404s, cache bypass.
 */
function meeha_campus_template_redirect() {
	if ( ! meeha_campus_is_hub() ) {
		return;
	}
	$v = meeha_campus_view();
	if ( meeha_campus_beta_blocked() ) {
		meeha_campus_nocache();
		status_header( 404 );
		return;
	}
	if ( isset( $_GET['mc_manifest'] ) && meeha_campus_app_mode() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		meeha_campus_manifest();
	}
	if ( ! $v['def'] ) {
		status_header( 404 );
	}
	if ( is_user_logged_in() || ( $v['def'] && $v['def']['nocache'] ) || ! empty( $_COOKIE['mhp_flash'] ) ) {
		meeha_campus_nocache();
	}
}
add_action( 'template_redirect', 'meeha_campus_template_redirect', 1 );

/**
 * Web app manifest for the hub (so "Add to Home screen" opens the section full-screen, like an app).
 */
function meeha_campus_manifest() {
	$name  = trim( (string) meeha_opt( 'campus', 'app_name', 'মিহা ক্যাম্পাস' ) );
	$name  = '' !== $name ? $name : 'মিহা ক্যাম্পাস';
	$start = meeha_campus_url();
	$icons = array();
	foreach ( array( 192, 512 ) as $size ) {
		$src = get_site_icon_url( $size );
		if ( $src ) {
			$icons[] = array(
				'src'     => $src,
				'sizes'   => $size . 'x' . $size,
				'type'    => 'image/png',
				'purpose' => 'any',
			);
		}
	}
	$out = array(
		'id'               => wp_make_link_relative( $start ),
		'name'             => $name,
		'short_name'       => $name,
		'description'      => (string) meeha_opt( 'campus', 'lede', '' ),
		'lang'             => 'bn',
		'dir'              => 'ltr',
		'start_url'        => add_query_arg( 'utm_source', 'homescreen', $start ),
		'scope'            => $start,
		'display'          => 'standalone',
		'background_color' => '#F4F6F9',
		'theme_color'      => '#025AB2',
		'icons'            => $icons,
	);
	header( 'Content-Type: application/manifest+json; charset=utf-8' );
	header( 'Cache-Control: public, max-age=86400' );
	status_header( 200 );
	echo wp_json_encode( $out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	exit;
}

/**
 * Head tags for app mode: manifest and iOS home-screen hints.
 */
function meeha_campus_app_head() {
	if ( ! meeha_campus_is_hub() || ! meeha_campus_app_mode() || meeha_campus_beta_blocked() ) {
		return;
	}
	$name = trim( (string) meeha_opt( 'campus', 'app_name', 'মিহা ক্যাম্পাস' ) );
	echo '<link rel="manifest" href="' . esc_url( add_query_arg( 'mc_manifest', '1', meeha_campus_url() ) ) . '">' . "\n";
	echo '<meta name="mobile-web-app-capable" content="yes">' . "\n";
	echo '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n";
	echo '<meta name="apple-mobile-web-app-status-bar-style" content="default">' . "\n";
	echo '<meta name="apple-mobile-web-app-title" content="' . esc_attr( '' !== $name ? $name : 'মিহা ক্যাম্পাস' ) . '">' . "\n";
}
add_action( 'wp_head', 'meeha_campus_app_head', 3 );

/**
 * Render the hub with the section's own template, whatever page template is chosen.
 *
 * @param string $template Template.
 * @return string
 */
function meeha_campus_template_include( $template ) {
	if ( meeha_campus_is_hub() ) {
		return __DIR__ . '/template.php';
	}
	return $template;
}
add_filter( 'template_include', 'meeha_campus_template_include', 50 );

/**
 * Template key (critical CSS / bundle).
 *
 * @param string $key Key.
 * @return string
 */
function meeha_campus_template_key( $key ) {
	return meeha_campus_is_hub() ? 'campus' : $key;
}
add_filter( 'meeha_template_key', 'meeha_campus_template_key' );

/**
 * The shortcode is a theme screen (Elementor assets off, app container).
 *
 * @param array $codes Codes.
 * @return array
 */
function meeha_campus_shortcodes( $codes ) {
	$codes[] = 'meeha_campus';
	return $codes;
}
add_filter( 'meeha_theme_shortcodes', 'meeha_campus_shortcodes' );

/**
 * `[meeha_campus]`: marker for the hub page. Elsewhere it renders a link to the section.
 *
 * @return string
 */
function meeha_campus_shortcode() {
	if ( meeha_campus_is_hub() ) {
		return '';
	}
	if ( ! meeha_campus_hub_id() ) {
		return current_user_can( 'edit_theme_options' ) ? '<p>শিক্ষা ও ক্যাম্পাস: মিহা থিম › শিক্ষা ও ক্যাম্পাস ট্যাবে এই পাতার আইডি দিন।</p>' : '';
	}
	return '<p><a class="m-btn" href="' . esc_url( meeha_campus_url() ) . '">' . esc_html( (string) meeha_opt( 'campus', 'title', 'শিক্ষা ও ক্যাম্পাস' ) ) . '</a></p>';
}
add_shortcode( 'meeha_campus', 'meeha_campus_shortcode' );

/**
 * Section ad slots (auto-added to মিহা থিম › বিজ্ঞাপন).
 *
 * @param array $slots Slots.
 * @return array
 */
function meeha_campus_ad_slots( $slots ) {
	$slots['campus_banner'] = array( 'শিক্ষা ও ক্যাম্পাস — সেকশন ব্যানার', 'leader', 0 );
	$slots['campus_infeed'] = array( 'শিক্ষা ও ক্যাম্পাস — চাকরির তালিকার ভেতরে', 'leader', 0 );
	$slots['campus_mpu']    = array( 'শিক্ষা ও ক্যাম্পাস — বিজ্ঞপ্তির পাতায় (৩০০ × ২৫০)', 'mpu', 0 );
	return $slots;
}
add_filter( 'meeha_ad_slots', 'meeha_campus_ad_slots' );

/**
 * Body classes.
 *
 * @param array $c Classes.
 * @return array
 */
function meeha_campus_body_class( $c ) {
	if ( meeha_campus_is_hub() ) {
		$v   = meeha_campus_view();
		$c[] = 'mc-body';
		if ( meeha_campus_app_mode() ) {
			$c[] = 'mc-app';
		}
		$c[] = 'mc-view-' . sanitize_html_class( $v['view'] );
		if ( $v['def'] && 'exam' === $v['def']['chrome'] ) {
			$c[] = 'mc-exam';
		}
	}
	return $c;
}
add_filter( 'body_class', 'meeha_campus_body_class' );

/* ---------------- SEO ---------------- */

/**
 * Canonical URL of the current view (keeps only ?pg).
 *
 * @return string
 */
function meeha_campus_canonical() {
	$v    = meeha_campus_view();
	$args = array();
	if ( isset( $_GET['pg'] ) && (int) $_GET['pg'] > 1 ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$args['pg'] = (int) $_GET['pg']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}
	return meeha_campus_url( $v['view'], $v['id'], $args );
}

/**
 * Full document title.
 *
 * @return string
 */
function meeha_campus_doc_title() {
	$v       = meeha_campus_view();
	$section = (string) meeha_opt( 'campus', 'title', 'শিক্ষা ও ক্যাম্পাস' );
	$t       = meeha_campus_view_title();
	return ( 'home' === $v['view'] ? $section : $t . ' — ' . $section ) . ' | ' . get_bloginfo( 'name' );
}

/**
 * Core title.
 *
 * @param string $title Title.
 * @return string
 */
function meeha_campus_pre_title( $title ) {
	return meeha_campus_is_hub() ? meeha_campus_doc_title() : $title;
}
add_filter( 'pre_get_document_title', 'meeha_campus_pre_title', 20 );
add_filter( 'rank_math/frontend/title', 'meeha_campus_pre_title', 20 );
add_filter( 'wpseo_title', 'meeha_campus_pre_title', 20 );

/**
 * Canonical (core, Rank Math, Yoast).
 *
 * @param string $url URL.
 * @return string
 */
function meeha_campus_canonical_filter( $url ) {
	return meeha_campus_is_hub() ? meeha_campus_canonical() : $url;
}
add_filter( 'get_canonical_url', 'meeha_campus_canonical_filter', 20 );
add_filter( 'rank_math/frontend/canonical', 'meeha_campus_canonical_filter', 20 );
add_filter( 'wpseo_canonical', 'meeha_campus_canonical_filter', 20 );

/**
 * Robots (core wp_robots).
 *
 * @param array $robots Robots.
 * @return array
 */
function meeha_campus_wp_robots( $robots ) {
	if ( meeha_campus_is_hub() && meeha_campus_noindex() ) {
		$robots['noindex'] = true;
		$robots['follow']  = true;
		unset( $robots['index'], $robots['max-image-preview'] );
	}
	return $robots;
}
add_filter( 'wp_robots', 'meeha_campus_wp_robots', 20 );

/**
 * Robots (Rank Math).
 *
 * @param array $robots Robots.
 * @return array
 */
function meeha_campus_rm_robots( $robots ) {
	if ( meeha_campus_is_hub() && meeha_campus_noindex() ) {
		$robots['index']  = 'noindex';
		$robots['follow'] = 'follow';
	}
	return $robots;
}
add_filter( 'rank_math/frontend/robots', 'meeha_campus_rm_robots', 20 );

/**
 * Robots (Yoast).
 *
 * @param string $robots Robots.
 * @return string
 */
function meeha_campus_yoast_robots( $robots ) {
	return ( meeha_campus_is_hub() && meeha_campus_noindex() ) ? 'noindex, follow' : $robots;
}
add_filter( 'wpseo_robots', 'meeha_campus_yoast_robots', 20 );

/**
 * Category of the section by role (setting, else matched by name/slug under the main category).
 *
 * @param string $key main|edu|employment|career|success|campus.
 * @return int Term id (0 = none).
 */
function meeha_campus_cat( $key ) {
	static $cache = array();
	if ( isset( $cache[ $key ] ) ) {
		return $cache[ $key ];
	}
	$id = (int) meeha_opt( 'campus', 'cat_' . $key, 0 );
	if ( ! $id ) {
		$names = array(
			'main'       => array( 'শিক্ষা ও ক্যাম্পাস', 'education-and-campus' ),
			'edu'        => array( 'শিক্ষা', 'education' ),
			'employment' => array( 'নিয়োগ', 'employment' ),
			'career'     => array( 'ক্যারিয়ার', 'career' ),
			'success'    => array( 'সাফল্যের গল্প', 'success-story' ),
			'campus'     => array( 'ক্যাম্পাস', 'campus' ),
		);
		if ( 'main' === $key ) {
			$t = get_term_by( 'slug', $names['main'][1], 'category' );
			if ( ! $t ) {
				$found = get_terms(
					array(
						'taxonomy'   => 'category',
						'hide_empty' => false,
						'name'       => $names['main'][0],
						'number'     => 1,
					)
				);
				$t     = $found && ! is_wp_error( $found ) ? $found[0] : null;
			}
			$id = $t ? (int) $t->term_id : 0;
		} elseif ( isset( $names[ $key ] ) ) {
			$parent = meeha_campus_cat( 'main' );
			$args   = array(
				'taxonomy'   => 'category',
				'hide_empty' => false,
				'number'     => 50,
			);
			if ( $parent ) {
				$args['parent'] = $parent;
			}
			$terms = $parent ? get_terms( $args ) : array();
			foreach ( is_array( $terms ) ? $terms : array() as $t ) {
				if ( $t->name === $names[ $key ][0] || $t->slug === $names[ $key ][1] || urldecode( $t->slug ) === $names[ $key ][0] ) {
					$id = (int) $t->term_id;
					break;
				}
			}
		}
	}
	$cache[ $key ] = $id;
	return $id;
}
