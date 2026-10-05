<?php
/**
 * Meeha News Child Theme
 * functions.php
 *
 * এই ফাইল parent থিম (Newsmatic) এর ফাইল স্পর্শ করে না —
 * থিম আপডেট হলেও এই কাস্টমাইজেশন নিরাপদ থাকবে।
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // সরাসরি অ্যাক্সেস বন্ধ
}

define( 'MEEHA_CHILD_VERSION', '1.0.0' );
define( 'MEEHA_CHILD_DIR', get_stylesheet_directory() );
define( 'MEEHA_CHILD_URI', get_stylesheet_directory_uri() );

/**
 * ধাপ ১: Parent + Child স্টাইল লোড করা
 */
function meeha_child_enqueue_styles() {
	// Parent থিমের মূল স্টাইল
	wp_enqueue_style(
		'newsmatic-parent-style',
		get_template_directory_uri() . '/style.css',
		array(),
		MEEHA_CHILD_VERSION
	);

	// Child থিমের style.css (ছোটখাটো override-এর জন্য)
	wp_enqueue_style(
		'meeha-child-style',
		get_stylesheet_directory_uri() . '/style.css',
		array( 'newsmatic-parent-style' ),
		MEEHA_CHILD_VERSION
	);

	// ফুটার - সব পেজেই লোড হয় (কাস্টম ফুটার চালু থাকলে)
	if ( meeha_get_footer_settings()['enabled'] ) {
		wp_enqueue_style(
			'meeha-footer-style',
			MEEHA_CHILD_URI . '/assets/css/meeha-footer.css',
			array( 'meeha-child-style' ),
			MEEHA_CHILD_VERSION
		);
		wp_enqueue_script(
			'meeha-footer-script',
			MEEHA_CHILD_URI . '/assets/js/meeha-footer.js',
			array(),
			MEEHA_CHILD_VERSION,
			true
		);
		// হোমপেজের নিউজলেটার ফর্মের মতোই একই meeha_newsletter_signup অ্যাকশন
		// পুনর্ব্যবহার করা হচ্ছে - কিন্তু window.meehaAjax আগে শুধু হোমপেজে
		// লোড হতো, ফুটার এখন সব পেজেই আছে তাই এখানেও লোকালাইজ করা দরকার
		wp_add_inline_script(
			'meeha-footer-script',
			'window.meehaAjax = window.meehaAjax || { url: "' . esc_url( admin_url( 'admin-ajax.php' ) ) . '", nonce: "' . esc_js( wp_create_nonce( 'meeha_newsletter_nonce' ) ) . '" };',
			'before'
		);
	}

	// হেডার (header.php) সব পেজেই দেখা যায়, তাই এর CSS/JS ও ফন্ট শর্তহীনভাবে লোড হবে।
	// হ্যান্ডেল নাম নিচের is_front_page()/is_single() ব্লকেও একই রাখা হয়েছে, তাই
	// WordPress দুবার রিকোয়েস্ট পাঠাবে না — একবারই এনকিউ হবে।
	wp_enqueue_style(
		'meeha-google-fonts',
		'https://fonts.googleapis.com/css2?family=Baloo+Da+2:wght@500;600;700;800&family=Hind+Siliguri:wght@400;500;600;700&family=Inter:wght@400;500;600&display=swap',
		array(),
		null
	);

	wp_enqueue_style(
		'meeha-header-style',
		MEEHA_CHILD_URI . '/assets/css/meeha-header.css',
		array( 'meeha-child-style', 'meeha-google-fonts' ),
		MEEHA_CHILD_VERSION
	);

	wp_enqueue_script(
		'meeha-header-script',
		MEEHA_CHILD_URI . '/assets/js/meeha-header.js',
		array(),
		MEEHA_CHILD_VERSION,
		true
	);

	// হোমপেজ কাস্টম ডিজাইন — শুধু হোমপেজেই লোড হবে, বাকি পেজে না,
	// যাতে অন্য পেজের লোড স্পিড কমে না যায়
	if ( is_front_page() ) {
		// Google Fonts — আলাদা করে enqueue করা হচ্ছে (CSS @import নয়),
		// কারণ @import একটার পর একটা রিকোয়েস্ট চেইন তৈরি করে যা ধীরগতির;
		// এভাবে ব্রাউজার সব ফন্ট একসাথে সমান্তরালে লোড করতে পারে
		wp_enqueue_style(
			'meeha-google-fonts',
			'https://fonts.googleapis.com/css2?family=Baloo+Da+2:wght@500;600;700;800&family=Hind+Siliguri:wght@400;500;600;700&family=Inter:wght@400;500;600&display=swap',
			array(),
			null
		);

		wp_enqueue_style(
			'meeha-homepage-style',
			MEEHA_CHILD_URI . '/assets/css/meeha-homepage.css',
			array( 'meeha-child-style', 'meeha-google-fonts' ),
			MEEHA_CHILD_VERSION
		);

		wp_enqueue_script(
			'meeha-homepage-script',
			MEEHA_CHILD_URI . '/assets/js/meeha-homepage.js',
			array(),
			MEEHA_CHILD_VERSION,
			true // ফুটারে লোড হবে, পেজ রেন্ডার আটকাবে না
		);

		wp_add_inline_script(
			'meeha-homepage-script',
			'window.meehaAjax = { url: "' . esc_url( admin_url( 'admin-ajax.php' ) ) . '", nonce: "' . esc_js( wp_create_nonce( 'meeha_newsletter_nonce' ) ) . '" };',
			'before'
		);
	}

	// সিঙ্গেল আর্টিকেল পেজে শুধু সেই পেজের CSS/JS লোড হবে
	if ( is_single() ) {
		wp_enqueue_style(
			'meeha-google-fonts',
			'https://fonts.googleapis.com/css2?family=Baloo+Da+2:wght@500;600;700;800&family=Hind+Siliguri:wght@400;500;600;700&family=Inter:wght@400;500;600&display=swap',
			array(),
			null
		);

		wp_enqueue_style(
			'meeha-single-style',
			MEEHA_CHILD_URI . '/assets/css/meeha-single.css',
			array( 'meeha-child-style', 'meeha-google-fonts' ),
			MEEHA_CHILD_VERSION
		);

		wp_enqueue_script(
			'meeha-single-script',
			MEEHA_CHILD_URI . '/assets/js/meeha-single.js',
			array(),
			MEEHA_CHILD_VERSION,
			true
		);

		// PHP সেটিংস থেকে readmore-height ও ফন্ট-স্টেপ JS-এ পাঠানো হচ্ছে,
		// যাতে অ্যাডমিন প্যানেল থেকে বদলালে সাথে সাথে কাজ করে
		$single_settings = meeha_get_single_settings();
		wp_add_inline_script(
			'meeha-single-script',
			'window.meehaReadmoreHeight = ' . (int) $single_settings['readmore_height'] . ';' .
			'window.meehaFontStepPercent = ' . (int) $single_settings['font_step_percent'] . ';' .
			'window.meehaPollAjax = { url: "' . esc_url( admin_url( 'admin-ajax.php' ) ) . '", nonce: "' . esc_js( wp_create_nonce( 'meeha_poll_nonce' ) ) . '" };' .
			'window.meehaHomeUrl = "' . esc_url( home_url( '/' ) ) . '";',
			'before'
		);

		// সাইডবারের "জনপ্রিয়/ট্রেন্ড/নতুন" ট্যাব-উইজেটের CSS/JS (আগে শুধু হোমপেজে লোড হতো)
		meeha_enqueue_tabbed_widget_assets( 'meeha-single-style' );
	}
}
add_action( 'wp_enqueue_scripts', 'meeha_child_enqueue_styles' );

/**
 * Blocks Elementor's CSS/JS on pages the theme fully designs itself
 * (homepage, category archives, single posts, and any page using our own
 * account/photocard/latest-news shortcodes) - Elementor was loading its
 * assets globally on every page regardless of whether that page actually
 * uses the Elementor page builder. Matches by handle prefix ("elementor",
 * "eicons") rather than an exact handle list, so it keeps working even if
 * Elementor renames or adds handles in future updates.
 */
function meeha_is_theme_designed_page() {
	if ( is_front_page() || is_category() || ( is_single() && 'post' === get_post_type() ) ) {
		return true;
	}
	global $post;
	if ( ! is_a( $post, 'WP_Post' ) ) {
		return false;
	}
	$our_shortcodes = array(
		'meeha_login',
		'meeha_register',
		'meeha_my_account',
		'meeha_lost_password',
		'meeha_correspondent_apply',
		'meeha_news_submit_form',
		'meeha_photocards',
		'meeha_photocard',
		'meeha_latest_feed',
	);
	foreach ( $our_shortcodes as $sc ) {
		if ( has_shortcode( $post->post_content, $sc ) ) {
			return true;
		}
	}
	return false;
}

function meeha_block_elementor_assets() {
	if ( is_admin() || ! meeha_is_theme_designed_page() ) {
		return;
	}
	global $wp_styles, $wp_scripts;
	if ( $wp_styles instanceof WP_Styles ) {
		foreach ( array_keys( $wp_styles->registered ) as $handle ) {
			if ( 0 === strpos( $handle, 'elementor' ) || 0 === strpos( $handle, 'eicons' ) ) {
				wp_dequeue_style( $handle );
			}
		}
	}
	if ( $wp_scripts instanceof WP_Scripts ) {
		foreach ( array_keys( $wp_scripts->registered ) as $handle ) {
			if ( 0 === strpos( $handle, 'elementor' ) ) {
				wp_dequeue_script( $handle );
			}
		}
	}
}
// late priority so this runs after Elementor (and anything else) has already enqueued
add_action( 'wp_enqueue_scripts', 'meeha_block_elementor_assets', 999 );
add_action( 'wp_print_styles', 'meeha_block_elementor_assets', 999 );
add_action( 'wp_print_scripts', 'meeha_block_elementor_assets', 999 );

/**
 * ধাপ ২: হোমপেজ সেটিংস প্যানেল লোড করা (Admin > Meeha হোমপেজ)
 */
require_once MEEHA_CHILD_DIR . '/inc/admin-settings.php';

/**
 * ধাপ ২ক: কাস্টম হেডার (header.php) — মেনু, লোগো, আইকন, বিজ্ঞাপন-ব্যানার
 * সংক্রান্ত সব হেল্পার ফাংশন এই আলাদা ফাইলে রাখা হয়েছে (functions.php
 * এমনিতেই অনেক বড় হয়ে গেছে), যাতে হেডার নিয়ে ভবিষ্যতে কাজ করা সহজ হয়।
 */
require_once MEEHA_CHILD_DIR . '/inc/header.php';

/**
 * ধাপ ২খ: সংবাদদাতা (Correspondent) সিস্টেম — রোল, আবেদন/অনুমোদন,
 * "আমার একাউন্ট"-এর নিউজ ড্যাশবোর্ড, সম্পাদনার অনুরোধ, Forminator সেফটি-নেট
 */
require_once MEEHA_CHILD_DIR . '/inc/correspondent.php';
require_once MEEHA_CHILD_DIR . '/inc/security.php';
require_once MEEHA_CHILD_DIR . '/inc/footer-settings.php';

/**
 * Photocard browse UI: search + tabs (All / Most Read / quote-type terms)
 */
require_once MEEHA_CHILD_DIR . '/inc/photocard-browse.php';

/**
 * Two new homepage sections: 4-column topic boxes, and a tabbed sidebar
 * (জনপ্রিয়/ট্রেন্ড/নতুন) paired with a category block
 */
require_once MEEHA_CHILD_DIR . '/inc/homepage-sections.php';

/**
 * থিম সাপোর্ট + মেনু লোকেশন — header.php-এর জন্য দরকার
 *
 * 'primary' লোকেশনটা 'add_theme_support'/'register_nav_menus'-এর মতোই
 * ইডেমপোটেন্ট: প্যারেন্ট থিম (Newsmatic) আগে থেকেই 'primary' রেজিস্টার করে
 * থাকলে এখানের কল সেটাকেই নতুন লেবেল দেবে, কোনো কনফ্লিক্ট হবে না। প্যারেন্ট
 * যদি ভিন্ন slug ব্যবহার করে থাকে, তাহলে এটা একটা নতুন লোকেশন হিসেবে যোগ
 * হবে — Appearance → Menus-এ গিয়ে "Meeha হেডার মেনু (primary)"-এর সাথে
 * পছন্দের মেনু অ্যাসাইন করে দিলেই হেডারে মেনু দেখাবে।
 */
function meeha_child_setup() {
	register_nav_menus( array(
		'primary' => 'Meeha হেডার মেনু (প্রাইমারি)',
	) );

	// লোগো আপলোডের জন্য (Appearance → Customize → Site Identity)।
	// প্যারেন্ট থিম আগে থেকেই সাপোর্ট করে থাকলে এখানে আর যোগ করার দরকার নেই,
	// যাতে প্যারেন্টে সেট করা সাইজ/ক্রপ আচরণ ভেঙে না যায়।
	if ( ! current_theme_supports( 'custom-logo' ) ) {
		add_theme_support( 'custom-logo', array(
			'height'      => 60,
			'width'       => 220,
			'flex-height' => true,
			'flex-width'  => true,
		) );
	}
}
add_action( 'after_setup_theme', 'meeha_child_setup' );

/**
 * ধাপ ৩: হেল্পার ফাংশন — সেটিংস থেকে নিরাপদে ডেটা বের করে আনা
 *
 * এই ফাংশনগুলো front-page.php-তে ব্যবহার হবে, যাতে টেমপ্লেট ফাইলে
 * সরাসরি get_option() ছড়িয়ে-ছিটিয়ে না থাকে।
 */

require_once get_stylesheet_directory() . '/inc/related-post.php';



// "সর্বশেষ" সেকশন — কোনো নির্দিষ্ট ক্যাটাগরি ছাড়া সাইট-জুড়ে সবচেয়ে নতুন পোস্ট
function meeha_get_latest_settings() {
	$settings = get_option( 'meeha_homepage_settings', array() );
	$count    = ! empty( $settings['latest_count'] ) ? max( 3, min( 12, (int) $settings['latest_count'] ) ) : 6;
	// মোবাইলে কয়টা দেখাবে - ডেক্সটপ কাউন্টের চেয়ে বেশি হতে পারবে না
	$mobile_count = ! empty( $settings['latest_mobile_count'] ) ? (int) $settings['latest_mobile_count'] : min( 4, $count );
	return array(
		'enabled'      => ! empty( $settings['latest_enabled'] ),
		'count'        => $count,
		'mobile_count' => max( 1, min( $count, $mobile_count ) ),
	);
}

// হিরো সেকশনের ক্যাটাগরি আইডি ফেরত দেয়
function meeha_get_hero_category() {
	$settings = get_option( 'meeha_homepage_settings', array() );
	return isset( $settings['hero_category'] ) ? (int) $settings['hero_category'] : 0;
}

// হিরো সেকশনের পাশে দেখানো "হাইলাইট ক্যাটাগরি" (লিড পোস্টের বাইরে আরও ৩টা পর্যন্ত)
function meeha_get_hero_highlight_categories() {
	$settings = get_option( 'meeha_homepage_settings', array() );
	$cats     = array();
	for ( $i = 1; $i <= 3; $i++ ) {
		$id = isset( $settings[ 'hero_highlight_cat_' . $i ] ) ? (int) $settings[ 'hero_highlight_cat_' . $i ] : 0;
		if ( $id ) {
			$cats[] = $id;
		}
	}
	return $cats;
}

// প্রতিটা হাইলাইট ক্যাটাগরি থেকে কতটা পোস্ট দেখাবে (ডিফল্ট ২)
function meeha_get_hero_count() {
	$settings = get_option( 'meeha_homepage_settings', array() );
	$count    = isset( $settings['hero_count'] ) ? (int) $settings['hero_count'] : 2;
	return max( 1, min( 3, $count ) ); // প্রতি ক্যাটাগরিতে ১-৩টার মধ্যে সীমাবদ্ধ
}

// মোবাইলে প্রতিটা হাইলাইট ক্যাটাগরি থেকে কতটা পোস্ট দেখাবে (ডিফল্ট ১, "লিস্ট" স্টাইলে প্রযোজ্য)
function meeha_get_hero_count_mobile() {
	$settings = get_option( 'meeha_homepage_settings', array() );
	$desktop  = meeha_get_hero_count();
	$count    = isset( $settings['hero_count_mobile'] ) ? (int) $settings['hero_count_mobile'] : 1;
	return max( 1, min( $desktop, $count ) );
}

// হিরোর টপ-স্টোরিতে (নতুন ডিজাইনে) মডারেট সাইজের ছবি দেখাবে কিনা - বন্ধ করলে
// শুধু ক্যাটাগরি+টাইটেল+এক্সসার্প্ট দেখাবে, একদমই টেক্সট-অনলি টপ স্টোরি (AFP-স্টাইল)
function meeha_get_hero_top_image_enabled() {
	$settings = get_option( 'meeha_homepage_settings', array() );
	return ! isset( $settings['hero_top_image'] ) || ! empty( $settings['hero_top_image'] );
}

// একটি নির্দিষ্ট সেকশন ব্লকের সেটিংস ফেরত দেয় (১-৫ নম্বর ব্লক)
function meeha_get_section_block( $index ) {
	$settings = get_option( 'meeha_homepage_settings', array() );
	$key      = 'section_' . (int) $index;

	if ( empty( $settings[ $key ]['category'] ) ) {
		return false; // এই ব্লকটি সেট করা হয়নি
	}

	$count        = ! empty( $settings[ $key ]['count'] ) ? max( 2, min( 8, (int) $settings[ $key ]['count'] ) ) : 4;
	$mobile_count = ! empty( $settings[ $key ]['mobile_count'] ) ? (int) $settings[ $key ]['mobile_count'] : min( 2, $count );

	return array(
		'category'     => (int) $settings[ $key ]['category'],
		'title'        => ! empty( $settings[ $key ]['title'] ) ? sanitize_text_field( $settings[ $key ]['title'] ) : '',
		'count'        => $count,
		'mobile_count' => max( 1, min( $count, $mobile_count ) ),
		'layout'       => ! empty( $settings[ $key ]['layout'] ) && 'list' === $settings[ $key ]['layout'] ? 'list' : 'grid',
	);
}

/**
 * একটা সেকশনের জন্য "মোবাইলে কতগুলো দেখাবে" সীমাবদ্ধ করতে একটা ছোট ইনলাইন
 * <style> ট্যাগ বসায় - আলাদা কোনো JS বা দ্বিতীয় কুয়েরি ছাড়াই, শুধু CSS দিয়ে।
 * ডেক্সটপে (৭৬৮px+) সবকটা পোস্টই দেখা যায়, তার নিচে অতিরিক্তগুলো লুকানো থাকে।
 * $layout 'list' হলে ধরে নেওয়া হয় প্রথম আইটেমটা .meeha-feature-list__lead
 * (সবসময় দেখাবে) আর বাকিগুলো .meeha-feature-list__rest > li।
 */
function meeha_mobile_count_style( $wrapper_id, $desktop_count, $mobile_count, $layout = 'grid' ) {
	$desktop_count = (int) $desktop_count;
	$mobile_count  = max( 1, (int) $mobile_count );
	if ( $mobile_count >= $desktop_count ) {
		return; // লুকানোর কিছু নেই, ডেক্সটপ আর মোবাইল কাউন্ট একই বা মোবাইলই বেশি
	}
	if ( 'list' === $layout ) {
		$keep_in_rest = max( 0, $mobile_count - 1 ); // লিড বাদে বাকিদের মধ্যে কতগুলো রাখবে
		printf(
			'<style>@media (max-width:767px){#%1$s .meeha-feature-list__rest li:nth-child(n+%2$d){display:none}}</style>',
			esc_attr( $wrapper_id ),
			(int) ( $keep_in_rest + 1 )
		);
	} else {
		printf(
			'<style>@media (max-width:767px){#%1$s .meeha-grid>a:nth-child(n+%2$d){display:none}}</style>',
			esc_attr( $wrapper_id ),
			(int) ( $mobile_count + 1 )
		);
	}
}

// Headline ticker: on/off, label, and which category to pull from - shown site-wide, below the header
function meeha_get_breaking_settings() {
	$settings = get_option( 'meeha_homepage_settings', array() );
	return array(
		'enabled'  => ! empty( $settings['breaking_enabled'] ),
		'label'    => ! empty( $settings['breaking_label'] ) ? sanitize_text_field( $settings['breaking_label'] ) : 'হেডলাইন',
		'category' => isset( $settings['breaking_category'] ) ? (int) $settings['breaking_category'] : 0,
	);
}

// সর্বাধিক পঠিত সেকশন চালু আছে কিনা, কতটা দেখাবে
// গুগল নিউজ ফলো বাটনের লিংক — Admin > Meeha হোমপেজ থেকে সেট করা হয়
function meeha_get_google_news_url() {
	$settings = get_option( 'meeha_homepage_settings', array() );
	return ! empty( $settings['google_news_url'] ) ? esc_url_raw( $settings['google_news_url'] ) : '';
}

// নিউজলেটার ব্যান্ডের হেডলাইন টেক্সট
function meeha_get_newsletter_heading() {
	$settings = get_option( 'meeha_homepage_settings', array() );
	return ! empty( $settings['newsletter_heading'] )
		? sanitize_text_field( $settings['newsletter_heading'] )
		: 'প্রতিদিনের গুরুত্বপূর্ণ খবর সরাসরি আপনার ইনবক্সে';
}

// MailPoet ফর্ম আইডি — Admin > Meeha হোমপেজ থেকে সেট করা যায় (হার্ডকোড "1" অনুমান না করে)
function meeha_get_mailpoet_form_id() {
	$settings = get_option( 'meeha_homepage_settings', array() );
	return ! empty( $settings['mailpoet_form_id'] ) ? sanitize_text_field( $settings['mailpoet_form_id'] ) : '';
}

// ফটোকার্ডের ফুটার বিজ্ঞাপন — Admin > Meeha হোমপেজ > ফটোকার্ড থেকে চালু/বন্ধ ও ছবি সেট করা যায়।
// চালু থাকলে এটা সাইটের সব ফটোকার্ডেই (অটো + ম্যানুয়াল কোটেশন কার্ড) দেখাবে।
function meeha_get_photocard_settings() {
	$settings = get_option( 'meeha_photocard_settings', array() );
	return array(
		'footer_ad_enabled'  => ! empty( $settings['footer_ad_enabled'] ),
		'footer_ad_image'    => ! empty( $settings['footer_ad_image'] ) ? esc_url_raw( $settings['footer_ad_image'] ) : '',
		'poll_duration_days' => ! empty( $settings['poll_duration_days'] ) ? absint( $settings['poll_duration_days'] ) : 7,
	);
}

// ফটোকার্ডে বসানোর জন্য সাইট লোগো (Appearance > Customize > Site Identity থেকে)।
// লোগো সেট করা না থাকলে JS সাইটের নাম টেক্সট হিসেবে বসিয়ে দেয়, যাতে লোগো ছাড়াও কার্ড খালি না দেখায়।
function meeha_get_photocard_branding() {
	$logo_id  = get_theme_mod( 'custom_logo' );
	$logo_url = $logo_id ? wp_get_attachment_image_url( $logo_id, 'full' ) : '';
	return array(
		'logo_url'  => $logo_url ? esc_url( $logo_url ) : '',
		'site_name' => get_bloginfo( 'name' ),
	);
}

/**
 * ধাপ ৯: নিউজলেটার সাবস্ক্রাইব — সরাসরি কাজ করা AJAX ফর্ম, যদি MailPoet
 * ফর্ম আইডি সেট করা না থাকে বা MailPoet না থাকে তাহলে এটাই ব্যবহার হবে।
 * ইমেইলগুলো একটা কাস্টম পোস্ট টাইপে জমা হয়, আর অ্যাডমিনকে নোটিফিকেশন যায়।
 */
function meeha_register_subscriber_post_type() {
	register_post_type( 'meeha_subscriber', array(
		'label'        => 'সাবস্ক্রাইবার',
		'public'       => false,
		'show_ui'      => true,
		'show_in_menu' => 'meeha-homepage-settings',
		'supports'     => array( 'title' ),
		'labels'       => array(
			'name'          => 'সাবস্ক্রাইবাররা',
			'singular_name' => 'সাবস্ক্রাইবার',
			'all_items'     => 'সব সাবস্ক্রাইবার',
		),
	) );
}
add_action( 'init', 'meeha_register_subscriber_post_type' );

function meeha_handle_newsletter_signup() {
	check_ajax_referer( 'meeha_newsletter_nonce', 'nonce' );

	$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';

	if ( ! is_email( $email ) ) {
		wp_send_json_error( array( 'message' => 'সঠিক ইমেইল ঠিকানা দিন।' ) );
	}

	// একই ইমেইল আগে থেকে থাকলে আবার যোগ করা হবে না।
	// get_page_by_title() ব্যবহার করা হচ্ছে না কারণ এটা WordPress 6.2 থেকে deprecated —
	// সাইটে ডিবাগ মোড চালু থাকলে এই deprecation নোটিসটাই JSON রেসপন্সের আগে বসে গিয়ে
	// পুরো AJAX রেসপন্স ভেঙে দিতে পারে (ব্রাউজার তখন "সমস্যা হয়েছে" দেখায় বা কিছুই দেখায় না)।
	$existing_query = new WP_Query( array(
		'post_type'      => 'meeha_subscriber',
		'title'          => $email,
		'post_status'    => 'publish',
		'posts_per_page' => 1,
		'fields'         => 'ids',
	) );
	if ( $existing_query->have_posts() ) {
		wp_send_json_success( array( 'message' => 'আপনি ইতিমধ্যে সাবস্ক্রাইব করেছেন।' ) );
	}

	wp_insert_post( array(
		'post_type'   => 'meeha_subscriber',
		'post_title'  => $email,
		'post_status' => 'publish',
	) );

	$admin_email = get_option( 'admin_email' );
	wp_mail( $admin_email, 'নতুন নিউজলেটার সাবস্ক্রাইবার', $email . ' নিউজলেটারে সাবস্ক্রাইব করেছেন।' );

	wp_send_json_success( array( 'message' => 'ধন্যবাদ! আপনি সফলভাবে সাবস্ক্রাইব করেছেন।' ) );
}
add_action( 'wp_ajax_meeha_newsletter_signup', 'meeha_handle_newsletter_signup' );
add_action( 'wp_ajax_nopriv_meeha_newsletter_signup', 'meeha_handle_newsletter_signup' );

// View count badge shown on single posts (unrelated to Most Read selection below)
function meeha_get_view_count( $post_id ) {
	return (int) get_post_meta( $post_id, '_meeha_views', true );
}

// Increment view count once per visitor per post per day (transient-based dedupe)
function meeha_track_view() {
	if ( ! is_singular( array( 'post', 'meeha_photocard' ) ) ) {
		return;
	}
	$post_id = get_queried_object_id();
	if ( ! $post_id ) {
		return;
	}
	$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
	$key = 'meeha_view_' . $post_id . '_' . md5( $ip );
	if ( get_transient( $key ) ) {
		return;
	}
	set_transient( $key, 1, DAY_IN_SECONDS );
	$count = (int) get_post_meta( $post_id, '_meeha_views', true );
	update_post_meta( $post_id, '_meeha_views', $count + 1 );
}
add_action( 'wp_head', 'meeha_track_view' );

/**
 * "ট্রেন্ডিং" পোস্ট লিস্ট — এডমিন ম্যানুয়ালি ঠিক করে দেয় (Posts লিস্টের
 * "ট্রেন্ডিং" কলাম/Quick Edit থেকে), কোনো অ্যালগরিদম না। আগে এই একই ফ্ল্যাগ
 * "সার্বাধিক পঠিত"-এর জন্য ব্যবহার হতো, কিন্তু সেটা এখন ভিউ-কাউন্ট দিয়ে
 * সম্পূর্ণ ডাইনামিক (দেখুন meeha_get_dynamic_most_read_posts) - তাই এই
 * ম্যানুয়াল ফ্ল্যাগ সিস্টেমটা এখন "ট্রেন্ডিং" ট্যাব নিয়ন্ত্রণের জন্য
 * পুনর্ব্যবহার করা হচ্ছে (এডিটর সরাসরি বেছে দিতে পারেন কোন পোস্ট ট্রেন্ডিং-এ
 * দেখাবে, স্বয়ংক্রিয় ৭-দিনের ভিউ-ভিত্তিক হিসাবের বদলে)।
 *
 * কিছু পপুলারিটি-ট্র্যাকিং প্লাগইন WP_Query-তে গ্লোবালি হুক করে নিজের স্ট্যাটস
 * টেবিল JOIN করে দিতে পারে (একাধিক রো/পোস্ট), তাই posts_groupby ফিল্টার +
 * PHP-লেভেল ডিডুপ - দুটো সুরক্ষাই রাখা হয়েছে।
 */
function meeha_get_trending_posts( $count, $exclude_id = 0 ) {
	$count = max( 1, (int) $count );
	$args  = array(
		'post_type'      => 'post',
		'post_status'    => 'publish',
		'meta_key'       => '_meeha_trending',
		'meta_value'     => '1',
		'orderby'        => 'date',
		'order'          => 'DESC',
		// fetch generously above $count - if a plugin's join multiplies rows,
		// there still need to be enough rows left after dedup below
		'posts_per_page' => max( 200, $count * 20 ),
		'no_found_rows'  => true,
	);
	if ( $exclude_id ) {
		$args['post__not_in'] = array( (int) $exclude_id );
	}
	// posts_groupby ফিল্টার দিয়ে সত্যিকারের GROUP BY {posts}.ID বসানো হচ্ছে,
	// শুধু এই কুয়েরির জন্যই (হুক করেই সাথে সাথে সরিয়ে ফেলা হচ্ছে)।
	global $wpdb;
	$meeha_force_groupby = function ( $groupby ) use ( $wpdb ) {
		return "{$wpdb->posts}.ID";
	};
	add_filter( 'posts_groupby', $meeha_force_groupby );
	$query = new WP_Query( $args );
	remove_filter( 'posts_groupby', $meeha_force_groupby );

	$seen  = array();
	$posts = array();
	foreach ( $query->posts as $p ) {
		if ( isset( $seen[ $p->ID ] ) ) {
			continue;
		}
		$seen[ $p->ID ] = true;
		$posts[]        = $p;
		if ( count( $posts ) >= $count ) {
			break;
		}
	}
	return $posts;
}

// হোমপেজের আলাদা "সার্বাধিক পঠিত" সেকশনের সেটিংস (আগে সরানো হয়েছিল, আবার
// ফিরিয়ে আনা হলো - সাইডবারের ট্যাব-উইজেটের "জনপ্রিয়" ট্যাব থেকে এটা আলাদা
// জায়গায়, আলাদা একটা পূর্ণাঙ্গ গ্রিড সেকশন হিসেবে দেখানোর জন্য)
function meeha_get_most_read_section_settings() {
	$settings     = get_option( 'meeha_homepage_settings', array() );
	$count        = ! empty( $settings['most_read_section_count'] ) ? max( 3, min( 10, (int) $settings['most_read_section_count'] ) ) : 6;
	$mobile_count = ! empty( $settings['most_read_section_mobile_count'] ) ? (int) $settings['most_read_section_mobile_count'] : min( 4, $count );
	return array(
		'enabled'      => ! empty( $settings['most_read_section_enabled'] ),
		'count'        => $count,
		'mobile_count' => max( 1, min( $count, $mobile_count ) ),
		'heading'      => ! empty( $settings['most_read_section_heading'] ) ? sanitize_text_field( $settings['most_read_section_heading'] ) : 'সর্বাধিক পঠিত',
	);
}

/**
 * "সার্বাধিক পঠিত" এখন ডাইনামিক — ম্যানুয়াল টিক-ফ্ল্যাগের বদলে থিমের নিজস্ব
 * ভিউ-কাউন্টার (_meeha_views, "ট্রেন্ড" ট্যাবেও একই ডেটা ব্যবহার হয়) দিয়ে
 * সাজানো, আর সময়সীমা অনুযায়ী ফিল্টার করা যায়। JOIN-এর বদলে PHP-তে সর্ট করা
 * হচ্ছে - এই থিমেই আগে JOIN-ভিত্তিক ভিউ-কাউন্ট সর্টিং ডুপ্লিকেট/ভুল রেজাল্টের
 * সমস্যা তৈরি করেছিল বলে সেই একই নিরাপদ প্যাটার্ন অনুসরণ করা হলো।
 */
function meeha_get_most_read_time_ranges() {
	return array(
		'all'       => 'সব সময়ের',
		'year'      => 'এই বছরের',
		'6months'   => 'গত ছয় মাসের',
		'3months'   => 'গত তিন মাসের',
		'lastmonth' => 'শেষ মাসের',
		'thismonth' => 'চলতি মাসের',
	);
}

function meeha_get_dynamic_most_read_posts( $count, $range = 'all' ) {
	$count = max( 1, (int) $count );
	$args  = array(
		'post_type'      => 'post',
		'post_status'    => 'publish',
		// ভিউ-কাউন্ট অনুযায়ী PHP-তে সর্ট করার জন্য যথেষ্ট বড় একটা পুল টেনে
		// আনা হচ্ছে (একদম নিখুঁত না হলেও, সাধারণ ব্যবহারের জন্য যথেষ্ট)
		'posts_per_page' => max( 150, $count * 15 ),
		'orderby'        => 'date',
		'order'          => 'DESC',
		'no_found_rows'  => true,
	);

	if ( 'thismonth' === $range ) {
		$args['date_query'] = array( array(
			'year'  => (int) current_time( 'Y' ),
			'month' => (int) current_time( 'n' ),
		) );
	} elseif ( 'lastmonth' === $range ) {
		$ts = strtotime( 'first day of last month', current_time( 'timestamp' ) );
		$args['date_query'] = array( array(
			'year'  => (int) date( 'Y', $ts ),
			'month' => (int) date( 'n', $ts ),
		) );
	} elseif ( 'year' === $range ) {
		$args['date_query'] = array( array( 'after' => '1 year ago' ) );
	} elseif ( '6months' === $range ) {
		$args['date_query'] = array( array( 'after' => '6 months ago' ) );
	} elseif ( '3months' === $range ) {
		$args['date_query'] = array( array( 'after' => '3 months ago' ) );
	}
	// 'all' (বা অচেনা মান) হলে কোনো date_query থাকবে না - সব সময়ের পোস্ট

	$query = new WP_Query( $args );
	$posts = $query->posts;
	usort(
		$posts,
		function ( $a, $b ) {
			$va = (int) get_post_meta( $a->ID, '_meeha_views', true );
			$vb = (int) get_post_meta( $b->ID, '_meeha_views', true );
			if ( $va === $vb ) {
				return strtotime( $b->post_date ) <=> strtotime( $a->post_date );
			}
			return $vb <=> $va;
		}
	);
	return array_slice( $posts, 0, $count );
}

function meeha_render_most_read_cards( $posts ) {
	global $post;
	foreach ( $posts as $p ) {
		$post = $p; // phpcs:ignore
		setup_postdata( $post );
		?>
		<a href="<?php the_permalink(); ?>" class="meeha-card">
			<div class="meeha-card__image"><?php meeha_card_thumbnail( 'medium' ); ?></div>
			<h3 class="meeha-card__title"><?php the_title(); ?></h3>
			<span class="meeha-meta"><?php echo esc_html( number_format_i18n( meeha_get_view_count( get_the_ID() ) ) ); ?> বার পঠিত</span>
		</a>
		<?php
	}
	wp_reset_postdata();
}

function meeha_handle_ajax_most_read_filter() {
	check_ajax_referer( 'meeha_mostread_nonce', 'nonce' );
	$range    = isset( $_POST['range'] ) ? sanitize_key( $_POST['range'] ) : 'all';
	$settings = meeha_get_most_read_section_settings();
	$posts    = meeha_get_dynamic_most_read_posts( $settings['count'], $range );

	ob_start();
	if ( $posts ) {
		meeha_render_most_read_cards( $posts );
	} else {
		echo '<p class="meeha-tabbed-list__empty">এই সময়সীমায় কোনো পোস্ট পাওয়া যায়নি।</p>';
	}
	wp_send_json_success( array( 'html' => ob_get_clean() ) );
}
add_action( 'wp_ajax_meeha_mostread_filter', 'meeha_handle_ajax_most_read_filter' );
add_action( 'wp_ajax_nopriv_meeha_mostread_filter', 'meeha_handle_ajax_most_read_filter' );

function meeha_render_most_read_section( $wrap_container = false ) {
	$settings = meeha_get_most_read_section_settings();
	if ( ! $settings['enabled'] ) {
		return;
	}
	$posts = meeha_get_dynamic_most_read_posts( $settings['count'], 'all' );
	if ( ! $posts ) {
		return;
	}
	meeha_mobile_count_style( 'meeha-most-read-section', count( $posts ), $settings['mobile_count'], 'grid' );
	wp_enqueue_script(
		'meeha-mostread-script',
		MEEHA_CHILD_URI . '/assets/js/meeha-mostread.js',
		array(),
		MEEHA_CHILD_VERSION,
		true
	);
	wp_add_inline_script(
		'meeha-mostread-script',
		'window.meehaMostReadAjax = { url: "' . esc_url( admin_url( 'admin-ajax.php' ) ) . '", nonce: "' . esc_js( wp_create_nonce( 'meeha_mostread_nonce' ) ) . '" };',
		'before'
	);
	// হোমপেজে এই ফাংশনটা আগে থেকেই একটা .meeha-container-এর ভেতরে কল হয়,
	// কিন্তু সিঙ্গেল পোস্টে কমেন্টের নিচে সরাসরি কল হয় - সেখানে নিজের
	// কন্টেইনার না থাকলে সেকশনটা স্ক্রিনের একদম কিনারা পর্যন্ত পুরো-প্রস্থ
	// হয়ে যায়। তাই $wrap_container=true দিলে নিজের .meeha-container যোগ হয়।
	?>
	<section class="meeha-section<?php echo $wrap_container ? ' meeha-container' : ''; ?>" id="meeha-most-read-section">
		<div class="meeha-section__head">
			<span class="meeha-eyebrow-bar"></span>
			<h2><?php echo esc_html( $settings['heading'] ); ?></h2>
		</div>
		<div class="meeha-mostread-filters" id="meeha-mostread-filters">
			<?php $first = true; ?>
			<?php foreach ( meeha_get_most_read_time_ranges() as $key => $label ) : ?>
				<button type="button" class="meeha-mostread-filter<?php echo $first ? ' is-active' : ''; ?>" data-range="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></button>
				<?php $first = false; ?>
			<?php endforeach; ?>
		</div>
		<div class="meeha-grid" id="meeha-most-read-grid">
			<?php meeha_render_most_read_cards( $posts ); ?>
		</div>
	</section>
	<?php
}

/**
 * "Most Read" flag - editable via the standard post edit screen (meta box,
 * side panel) and via Quick Edit, both using WordPress's normal save_post
 * flow (no custom AJAX). The Posts list column shows a read-only indicator;
 * editing happens through Update/Publish or Quick Edit's own Update button.
 *
 * Both UI paths share one nonce field/name, so a single save function
 * handles either submission.
 */
function meeha_trending_add_column( $columns ) {
	$columns['meeha_trending'] = 'ট্রেন্ডিং';
	return $columns;
}
add_filter( 'manage_post_posts_columns', 'meeha_trending_add_column' );

function meeha_trending_render_column( $column, $post_id ) {
	if ( 'meeha_trending' !== $column ) {
		return;
	}
	$checked = get_post_meta( $post_id, '_meeha_trending', true );
	echo $checked
		? '<span style="color:#146356;font-weight:600;">✓ হ্যাঁ</span>'
		: '<span style="color:#999;">—</span>';
	// hidden value Quick Edit's JS reads to pre-check the box when it opens
	echo '<div class="hidden meeha-trending-inline-value">' . esc_html( $checked ? '1' : '0' ) . '</div>';
}
add_action( 'manage_post_posts_custom_column', 'meeha_trending_render_column', 10, 2 );

function meeha_trending_quick_edit_field( $column_name, $post_type ) {
	if ( 'meeha_trending' !== $column_name || 'post' !== $post_type ) {
		return;
	}
	wp_nonce_field( 'meeha_save_trending', 'meeha_trending_nonce' );
	?>
	<fieldset class="inline-edit-col-right">
		<div class="inline-edit-col">
			<label class="alignleft">
				<input type="checkbox" name="meeha_trending" value="1" />
				<span class="checkbox-title">ট্রেন্ডিং হিসেবে দেখান</span>
			</label>
		</div>
	</fieldset>
	<?php
}
add_action( 'quick_edit_custom_box', 'meeha_trending_quick_edit_field', 10, 2 );

/**
 * "Topic" (কিকার/eyebrow) meta field - a short label shown in a different
 * color right before the post title in list-style layouts (currently the
 * "একনজরে"/glance component). Falls back to the post's first category if
 * left blank, so older posts without a topic set still show something.
 */
function meeha_add_topic_meta_box() {
	add_meta_box( 'meeha_topic_box', 'টপিক (টাইটেলের আগে দেখাবে)', 'meeha_render_topic_meta_box', 'post', 'side', 'high' );
}
add_action( 'add_meta_boxes', 'meeha_add_topic_meta_box' );

function meeha_render_topic_meta_box( $post ) {
	wp_nonce_field( 'meeha_save_topic', 'meeha_topic_nonce' );
	$topic = get_post_meta( $post->ID, '_meeha_topic', true );
	?>
	<input type="text" name="meeha_topic" value="<?php echo esc_attr( $topic ); ?>" class="widefat" placeholder="যেমন: আন্তর্জাতিক অপরাধ ট্রাইব্যুনাল" />
	<p class="description">খালি রাখলে পোস্টের ক্যাটাগরি দেখাবে।</p>
	<?php
}

function meeha_save_topic_meta_box( $post_id ) {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! isset( $_POST['meeha_topic_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['meeha_topic_nonce'] ) ), 'meeha_save_topic' ) ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	if ( isset( $_POST['meeha_topic'] ) ) {
		update_post_meta( $post_id, '_meeha_topic', sanitize_text_field( wp_unslash( $_POST['meeha_topic'] ) ) );
	}
}
add_action( 'save_post_post', 'meeha_save_topic_meta_box' );

// Also expose the "Topic" field in Quick Edit, same convention as the
// "Most Read" checkbox - a hidden per-row value plus a JS pre-fill.
function meeha_topic_add_column( $columns ) {
	$columns['meeha_topic'] = 'টপিক';
	return $columns;
}
add_filter( 'manage_post_posts_columns', 'meeha_topic_add_column' );

function meeha_topic_render_column( $column, $post_id ) {
	if ( 'meeha_topic' !== $column ) {
		return;
	}
	$topic = get_post_meta( $post_id, '_meeha_topic', true );
	echo esc_html( $topic ? $topic : '—' );
	echo '<div class="hidden meeha-topic-inline-value">' . esc_html( $topic ) . '</div>';
}
add_action( 'manage_post_posts_custom_column', 'meeha_topic_render_column', 10, 2 );

function meeha_topic_quick_edit_field( $column_name, $post_type ) {
	if ( 'meeha_topic' !== $column_name || 'post' !== $post_type ) {
		return;
	}
	wp_nonce_field( 'meeha_save_topic', 'meeha_topic_nonce' );
	?>
	<fieldset class="inline-edit-col-right">
		<div class="inline-edit-col">
			<label>
				<span class="title">টপিক</span>
				<input type="text" name="meeha_topic" value="" placeholder="যেমন: আন্তর্জাতিক অপরাধ ট্রাইব্যুনাল" />
			</label>
		</div>
	</fieldset>
	<?php
}
add_action( 'quick_edit_custom_box', 'meeha_topic_quick_edit_field', 10, 2 );

/**
 * Topic label with fallback to the post's first category. Call this from
 * within The Loop (relies on the_category()'s current-post context), or
 * pass a specific $post_id.
 */
function meeha_get_topic_label( $post_id = 0 ) {
	$post_id = $post_id ? $post_id : get_the_ID();
	$topic   = get_post_meta( $post_id, '_meeha_topic', true );
	if ( $topic ) {
		return $topic;
	}
	$cats = get_the_category( $post_id );
	return ! empty( $cats ) ? $cats[0]->name : '';
}

function meeha_add_trending_meta_box() {
	add_meta_box( 'meeha_trending_box', 'ট্রেন্ডিং', 'meeha_render_trending_meta_box', 'post', 'side', 'default' );
}
add_action( 'add_meta_boxes', 'meeha_add_trending_meta_box' );

function meeha_render_trending_meta_box( $post ) {
	wp_nonce_field( 'meeha_save_trending', 'meeha_trending_nonce' );
	$checked = get_post_meta( $post->ID, '_meeha_trending', true );
	?>
	<label>
		<input type="checkbox" name="meeha_trending" value="1" <?php checked( $checked, '1' ); ?> />
		এই পোস্টটা "ট্রেন্ডিং" ট্যাবে দেখান
	</label>
	<p class="description">Update/প্রকাশ করলেই হোমপেজ ও ক্যাটাগরি পেজের "ট্রেন্ড" ট্যাবে দেখাবে।</p>
	<?php
}

// fires for both the meta box (regular Update/Publish) and Quick Edit's own save
function meeha_save_trending( $post_id ) {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! isset( $_POST['meeha_trending_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['meeha_trending_nonce'] ) ), 'meeha_save_trending' ) ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	if ( isset( $_POST['meeha_trending'] ) ) {
		update_post_meta( $post_id, '_meeha_trending', '1' );
	} else {
		delete_post_meta( $post_id, '_meeha_trending' );
	}
}
add_action( 'save_post_post', 'meeha_save_trending' );

/**
 * Pre-checks the Quick Edit checkbox from the hidden value in the column
 * (WP core doesn't do this automatically for custom fields). Enqueued as a
 * real file with jquery/inline-edit-post as explicit dependencies.
 */
function meeha_trending_quick_edit_script( $hook ) {
	if ( 'edit.php' !== $hook ) {
		return;
	}
	$screen = get_current_screen();
	if ( ! $screen || 'post' !== $screen->post_type ) {
		return;
	}
	wp_enqueue_script(
		'meeha-admin-trending',
		MEEHA_CHILD_URI . '/assets/js/meeha-admin-trending.js',
		array( 'jquery', 'inline-edit-post' ),
		MEEHA_CHILD_VERSION,
		true
	);
}
add_action( 'admin_enqueue_scripts', 'meeha_trending_quick_edit_script' );

/**
 * ধাপ ৪খ: কার্ডের ছবি — ফিচার্ড ইমেজ না থাকলেও একটা প্লেসহোল্ডার দেখাবে,
 * যাতে গ্রিডের সব কার্ড একই উচ্চতার থাকে (ছবি না থাকলে কার্ড ছোট হয়ে
 * সারি এলোমেলো দেখানোর সমস্যাটাই ছিল আসল "ইমেজ পোরশন ইস্যু")
 */
function meeha_card_thumbnail( $size = 'medium' ) {
	if ( has_post_thumbnail() ) {
		the_post_thumbnail( $size, array( 'alt' => get_the_title() ) );
	} else {
		echo '<span class="meeha-card__placeholder" aria-hidden="true">' . esc_html( mb_substr( get_the_title(), 0, 1, 'UTF-8' ) ) . '</span>';
	}
}

/**
 * ধাপ ৬: সিঙ্গেল আর্টিকেল পেজের হেল্পার ফাংশনগুলো
 */

// আনুমানিক পড়ার সময় হিসাব করে (বাংলা কনটেন্টের জন্য মিনিট প্রতি ~২০০ শব্দ ধরে)
function meeha_reading_time( $content ) {
	$word_count = str_word_count( wp_strip_all_tags( $content ) );
	// str_word_count বাংলা ইউনিকোডে সবসময় নির্ভুল না, তাই একটা ন্যূনতম ধরে রাখা হচ্ছে
	$minutes = max( 1, (int) ceil( $word_count / 200 ) );
	return $minutes;
}

/**
 * বাংলা সংখ্যা/তারিখ/সময় হেল্পার — সাইটজুড়ে "2 days আগে", "8 September 2026,
 * 5:34 am" এই ধরনের ইংরেজি-মিশ্রিত টেক্সট ঠিক করতে। WordPress-এর নিজস্ব
 * human_time_diff()/get_the_date() লোকেল থাকলেও সংখ্যা বাংলায় বদলায় না এবং
 * এই সাইটে বাংলা মাসের নামও অনুবাদ করা ছিল না - তাই এখানে ম্যানুয়ালি করা হলো।
 */
function meeha_bn_digits( $value ) {
	static $en = array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' );
	static $bn = array( '০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯' );
	return str_replace( $en, $bn, (string) $value );
}

function meeha_bengali_month( $english_month ) {
	$months = array(
		'January'   => 'জানুয়ারি',
		'February'  => 'ফেব্রুয়ারি',
		'March'     => 'মার্চ',
		'April'     => 'এপ্রিল',
		'May'       => 'মে',
		'June'      => 'জুন',
		'July'      => 'জুলাই',
		'August'    => 'আগস্ট',
		'September' => 'সেপ্টেম্বর',
		'October'   => 'অক্টোবর',
		'November'  => 'নভেম্বর',
		'December'  => 'ডিসেম্বর',
	);
	return isset( $months[ $english_month ] ) ? $months[ $english_month ] : $english_month;
}

// "৮ সেপ্টেম্বর ২০২৬, ৫:৩৪ পূর্বাহ্ণ" স্টাইলে - $post_id না দিলে বর্তমান লুপ পোস্ট ধরবে
function meeha_bengali_date( $post_id = null, $with_time = true ) {
	$day   = get_the_date( 'j', $post_id );
	$month = get_the_date( 'F', $post_id );
	$year  = get_the_date( 'Y', $post_id );
	$out   = meeha_bn_digits( $day ) . ' ' . meeha_bengali_month( $month ) . ' ' . meeha_bn_digits( $year );
	if ( $with_time ) {
		$hm      = get_the_date( 'g:i', $post_id );
		$ampm    = get_the_date( 'a', $post_id );
		$ampm_bn = ( 'am' === $ampm ) ? 'পূর্বাহ্ণ' : 'অপরাহ্ণ';
		$out    .= ', ' . meeha_bn_digits( $hm ) . ' ' . $ampm_bn;
	}
	return $out;
}

// "২ দিন আগে" স্টাইলে - human_time_diff()-এর বাংলা সংস্করণ (একই যুক্তি, শুধু
// আউটপুট বাংলা সংখ্যা+এককে)
function meeha_bengali_time_ago( $from_timestamp, $to_timestamp = null ) {
	$from_timestamp = (int) $from_timestamp;
	$to_timestamp   = null === $to_timestamp ? current_time( 'timestamp' ) : (int) $to_timestamp; // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
	$diff           = max( 0, $to_timestamp - $from_timestamp );

	if ( $diff < 60 ) {
		return 'এইমাত্র';
	}

	$units = array(
		YEAR_IN_SECONDS  => 'বছর',
		30 * DAY_IN_SECONDS => 'মাস',
		WEEK_IN_SECONDS  => 'সপ্তাহ',
		DAY_IN_SECONDS   => 'দিন',
		HOUR_IN_SECONDS  => 'ঘণ্টা',
		MINUTE_IN_SECONDS => 'মিনিট',
	);
	foreach ( $units as $seconds => $label ) {
		$count = (int) floor( $diff / $seconds );
		if ( $count >= 1 ) {
			return meeha_bn_digits( $count ) . ' ' . $label . ' আগে';
		}
	}
	return 'এইমাত্র';
}

/**
 * Open Graph মেটা ট্যাগই (og:image) ছিল না, তাই ফেসবুক জানতোই না কোন ছবিটা
 * ব্যবহার করবে। এখন প্রতিটা আর্টিকেলের ফিচার্ড ইমেজ og:image হিসেবে বসছে।
 * কোনো SEO প্লাগইন (Yoast/RankMath/AIOSEO) থাকলে সেটাই এই কাজ করে, তাই তখন
 * ডুপ্লিকেট ট্যাগ এড়াতে এখানে কিছু আউটপুট হবে না।
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
		$title    = get_the_title();
		$raw_desc = get_the_excerpt() ? get_the_excerpt() : get_the_content();
		$desc     = wp_trim_words( wp_strip_all_tags( $raw_desc ), 30 );
		$url      = get_permalink();
		$type     = 'post' === get_post_type() ? 'article' : 'website';

		$thumb_id = get_post_thumbnail_id( get_the_ID() );
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

	if ( ! $image && $logo_id ) {
		$img_data = wp_get_attachment_image_src( $logo_id, 'full' );
		if ( $img_data ) {
			list( $image, $image_width, $image_height ) = $img_data;
		}
	}
	?>
	<meta property="og:type" content="<?php echo esc_attr( $type ); ?>" />
	<meta property="og:title" content="<?php echo esc_attr( $title ); ?>" />
	<meta property="og:description" content="<?php echo esc_attr( $desc ); ?>" />
	<meta property="og:url" content="<?php echo esc_url( $url ); ?>" />
	<meta property="og:site_name" content="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" />
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
	<?php
}
add_action( 'wp_head', 'meeha_output_social_meta_tags', 1 );

// শেয়ার বাটন — কোনো ভারী প্লাগইনের ওপর নির্ভর না করে সরাসরি শেয়ার-URL দিয়ে বানানো
function meeha_share_buttons( $position = 'top' ) {
	$post_id = get_the_ID();
	$url     = rawurlencode( get_permalink() );
	$title   = rawurlencode( get_the_title() );
	$counts  = meeha_get_share_counts( $post_id );
	?>
	<div class="meeha-share meeha-share--<?php echo esc_attr( $position ); ?>" data-post-id="<?php echo esc_attr( $post_id ); ?>">
		<a class="meeha-share__btn meeha-share__btn--fb" target="_blank" rel="noopener noreferrer" data-share-type="facebook"
			href="https://www.facebook.com/sharer/sharer.php?u=<?php echo esc_attr( $url ); ?>"><span class="meeha-share__icon" aria-hidden="true">f</span><span class="meeha-share__label">ফেসবুক</span> <span class="meeha-share__count" data-count="facebook"><?php echo esc_html( $counts['facebook'] ); ?></span></a>
		<a class="meeha-share__btn meeha-share__btn--wa" target="_blank" rel="noopener noreferrer" data-share-type="whatsapp"
			href="https://api.whatsapp.com/send?text=<?php echo esc_attr( $title . ' ' . $url ); ?>"><span class="meeha-share__icon" aria-hidden="true">☎</span><span class="meeha-share__label">হোয়াটসঅ্যাপ</span> <span class="meeha-share__count" data-count="whatsapp"><?php echo esc_html( $counts['whatsapp'] ); ?></span></a>
		<a class="meeha-share__btn meeha-share__btn--x" target="_blank" rel="noopener noreferrer" data-share-type="x"
			href="https://twitter.com/intent/tweet?text=<?php echo esc_attr( $title ); ?>&amp;url=<?php echo esc_attr( $url ); ?>"><span class="meeha-share__icon" aria-hidden="true">𝕏</span><span class="meeha-share__label">এক্স</span> <span class="meeha-share__count" data-count="x"><?php echo esc_html( $counts['x'] ); ?></span></a>
		<button type="button" class="meeha-share__btn meeha-share__btn--copy meeha-share__copy" data-url="<?php echo esc_url( get_permalink() ); ?>" data-share-type="copy"><span class="meeha-share__icon" aria-hidden="true">🔗</span><span class="meeha-share__label">লিংক কপি করুন</span> <span class="meeha-share__count" data-count="copy"><?php echo esc_html( $counts['copy'] ); ?></span></button>
	</div>
	<p class="meeha-share__total"><span class="meeha-share__total-label-long">মোট শেয়ার</span><span class="meeha-share__total-label-short">মোঃ</span>: <span data-total-count><?php echo esc_html( array_sum( $counts ) ); ?></span></p>
	<?php
}

// share counts stored as one serialized meta value: { facebook: N, whatsapp: N, x: N, copy: N }
function meeha_get_share_counts( $post_id ) {
	$counts = get_post_meta( $post_id, '_meeha_share_counts', true );
	$defaults = array( 'facebook' => 0, 'whatsapp' => 0, 'x' => 0, 'copy' => 0 );
	return is_array( $counts ) ? array_merge( $defaults, $counts ) : $defaults;
}

function meeha_handle_track_share() {
	check_ajax_referer( 'meeha_poll_nonce', 'nonce' );
	$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
	$type    = isset( $_POST['share_type'] ) ? sanitize_key( wp_unslash( $_POST['share_type'] ) ) : '';
	$valid_types = array( 'facebook', 'whatsapp', 'x', 'copy' );

	if ( ! $post_id || ! in_array( $type, $valid_types, true ) ) {
		wp_send_json_error();
	}

	$counts = meeha_get_share_counts( $post_id );
	$counts[ $type ]++;
	update_post_meta( $post_id, '_meeha_share_counts', $counts );

	wp_send_json_success( array( 'counts' => $counts, 'total' => array_sum( $counts ) ) );
}
add_action( 'wp_ajax_meeha_track_share', 'meeha_handle_track_share' );
add_action( 'wp_ajax_nopriv_meeha_track_share', 'meeha_handle_track_share' );

// সিঙ্গেল পোস্টের কমেন্টের নিচে "সাম্প্রতিক খবর" - সাইডবারের সংক্ষিপ্ত
// তালিকার চেয়ে বড়, পূর্ণ-প্রস্থের কার্ড গ্রিড হিসেবে
function meeha_render_recent_section( $exclude_id = 0 ) {
	$args = array(
		'posts_per_page'      => 8,
		'ignore_sticky_posts' => true,
		'no_found_rows'       => true,
	);
	if ( $exclude_id ) {
		$args['post__not_in'] = array( (int) $exclude_id );
	}
	$q = new WP_Query( $args );
	if ( ! $q->have_posts() ) {
		wp_reset_postdata();
		return;
	}
	?>
	<section class="meeha-section meeha-container">
		<div class="meeha-section__head">
			<span class="meeha-eyebrow-bar"></span>
			<h2>সাম্প্রতিক খবর</h2>
		</div>
		<div class="meeha-grid">
			<?php while ( $q->have_posts() ) : $q->the_post(); ?>
				<a href="<?php the_permalink(); ?>" class="meeha-card">
					<div class="meeha-card__image"><?php meeha_card_thumbnail( 'medium' ); ?></div>
					<h3 class="meeha-card__title"><?php the_title(); ?></h3>
					<span class="meeha-meta"><?php echo esc_html( meeha_bengali_time_ago( get_the_time( 'U' ) ) ); ?></span>
				</a>
			<?php endwhile; ?>
		</div>
	</section>
	<?php
	wp_reset_postdata();
}

// প্রাসঙ্গিক পোস্ট সেকশন — একই ক্যাটাগরির সাম্প্রতিক পোস্ট, বর্তমান পোস্ট বাদে
function meeha_render_related_posts( $current_post_id, $category_id ) {
	if ( ! $category_id ) {
		return;
	}

	$related = new WP_Query( array(
		'cat'                 => $category_id,
		'posts_per_page'      => 4,
		'post__not_in'        => array( $current_post_id ),
		'ignore_sticky_posts' => true,
	) );

	if ( ! $related->have_posts() ) {
		wp_reset_postdata();
		return;
	}
	?>
	<section class="meeha-section meeha-container">
		<div class="meeha-section__head">
			<span class="meeha-eyebrow-bar"></span>
			<h2>সম্পর্কিত খবর</h2>
		</div>
		<div class="meeha-grid">
			<?php while ( $related->have_posts() ) : $related->the_post(); ?>
				<a href="<?php the_permalink(); ?>" class="meeha-card">
					<div class="meeha-card__image"><?php meeha_card_thumbnail( 'medium' ); ?></div>
					<h3 class="meeha-card__title"><?php the_title(); ?></h3>
					<span class="meeha-meta"><?php echo esc_html( meeha_bengali_time_ago( get_the_time( 'U' ) ) ); ?></span>
				</a>
			<?php endwhile; ?>
		</div>
	</section>
	<?php
	wp_reset_postdata();
}

/**
 * একটি ক্যাটাগরি-সেকশন রেন্ডার করা — এখন দুই ধরনের লেআউট সাপোর্ট করে
 * (grid বা list), যাতে পাঁচটা সেকশন একই রকম দেখতে না লাগে — Admin > Meeha
 * হোমপেজ থেকে প্রতিটার জন্য আলাদা করে বেছে নেওয়া যায়।
 */
function meeha_render_category_section( $index ) {
	$block = meeha_get_section_block( $index );
	if ( ! $block || 0 === $block['category'] ) {
		return; // এই সেকশন সেট করা নেই, কিছু দেখাবে না
	}

	$term = get_term( $block['category'] );
	if ( is_wp_error( $term ) || ! $term ) {
		return;
	}

	$title = $block['title'] ? $block['title'] : $term->name;

	$query = new WP_Query( array(
		'cat'                 => $block['category'],
		'posts_per_page'      => $block['count'],
		'ignore_sticky_posts' => true,
	) );

	if ( ! $query->have_posts() ) {
		wp_reset_postdata();
		return;
	}

	$wrapper_id = 'meeha-section-' . (int) $index;
	meeha_mobile_count_style( $wrapper_id, $block['count'], $block['mobile_count'], $block['layout'] );
	?>
	<section class="meeha-section" id="<?php echo esc_attr( $wrapper_id ); ?>">
		<div class="meeha-section__head">
			<span class="meeha-eyebrow-bar"></span>
			<h2><?php echo esc_html( $title ); ?></h2>
			<a class="meeha-section__more" href="<?php echo esc_url( get_category_link( $block['category'] ) ); ?>">সব দেখুন →</a>
		</div>

		<?php if ( 'list' === $block['layout'] ) : ?>

			<div class="meeha-feature-list">
				<?php
				$is_lead = true;
				while ( $query->have_posts() ) :
					$query->the_post();
					if ( $is_lead ) :
						$is_lead = false;
						?>
						<a href="<?php the_permalink(); ?>" class="meeha-feature-list__lead">
							<div class="meeha-card__image"><?php meeha_card_thumbnail( 'medium_large' ); ?></div>
							<h3 class="meeha-card__title"><?php the_title(); ?></h3>
							<span class="meeha-meta"><?php echo esc_html( meeha_bengali_time_ago( get_the_time( 'U' ) ) ); ?></span>
						</a>
						<ul class="meeha-feature-list__rest">
						<?php
					else :
						?>
						<li>
							<a href="<?php the_permalink(); ?>">
								<span class="meeha-feature-list__title"><?php the_title(); ?></span>
								<span class="meeha-meta"><?php echo esc_html( meeha_bengali_time_ago( get_the_time( 'U' ) ) ); ?></span>
							</a>
						</li>
						<?php
					endif;
				endwhile;
				?>
				</ul>
			</div>

		<?php else : ?>

			<div class="meeha-grid">
				<?php while ( $query->have_posts() ) : $query->the_post(); ?>
					<a href="<?php the_permalink(); ?>" class="meeha-card">
						<div class="meeha-card__image"><?php meeha_card_thumbnail( 'medium' ); ?></div>
						<h3 class="meeha-card__title"><?php the_title(); ?></h3>
						<span class="meeha-meta"><?php echo esc_html( meeha_bengali_time_ago( get_the_time( 'U' ) ) ); ?></span>
					</a>
				<?php endwhile; ?>
			</div>

		<?php endif; ?>
	</section>
	<?php
	wp_reset_postdata();
}

/**
 * ধাপ ৭: সিঙ্গেল আর্টিকেল পেজের সাইডবার — ডেস্কটপে খালি জায়গা যেন
 * না থাকে, তাই সাম্প্রতিক খবর + সর্বাধিক পঠিত + (থাকলে) বিজ্ঞাপন স্লট।
 * মোবাইলে এটা আর্টিকেলের নিচে চলে আসে (CSS দিয়ে সামলানো)।
 */
function meeha_render_single_sidebar( $current_post_id ) {
	?>
	<aside class="meeha-sidebar">

		<?php meeha_render_area_sidebar(); ?>

		<?php
		$recent = new WP_Query( array(
			'posts_per_page'      => 5,
			'post__not_in'        => array( $current_post_id ),
			'ignore_sticky_posts' => true,
		) );
		if ( $recent->have_posts() ) :
			?>
			<div class="meeha-sidebar__block">
				<div class="meeha-section__head">
					<span class="meeha-eyebrow-bar"></span>
					<h2>সাম্প্রতিক খবর</h2>
				</div>
				<ul class="meeha-sidebar__list">
					<?php while ( $recent->have_posts() ) : $recent->the_post(); ?>
						<li>
							<a href="<?php the_permalink(); ?>">
								<span class="meeha-sidebar__thumb"><?php meeha_card_thumbnail( 'thumbnail' ); ?></span>
								<span class="meeha-sidebar__title"><?php the_title(); ?></span>
							</a>
						</li>
					<?php endwhile; ?>
				</ul>
			</div>
			<?php
		endif;
		wp_reset_postdata();
		?>

		<?php
		// আগে এখানে একটা আলাদা প্লেইন "সার্বাধিক পঠিত" লিস্ট ছিল। এখন হোমপেজের
		// "জনপ্রিয়/ট্রেন্ড/নতুন" ট্যাব-উইজেটটাই এখানে (একক/ক্যাটাগরি সাইডবারে) পুনর্ব্যবহার
		// করা হচ্ছে, যাতে দুই জায়গায় দুই রকম "জনপ্রিয় পোস্ট" ডিজাইন/কুয়েরি না থাকে।
		meeha_render_tabbed_widget_compact();
		?>

		<?php if ( shortcode_exists( 'adinserter' ) ) : ?>
			<div class="meeha-sidebar__block meeha-sidebar__ad">
				<span class="meeha-sidebar__ad-label">বিজ্ঞাপন</span>
				<?php echo do_shortcode( '[adinserter block="1"]' ); ?>
			</div>
		<?php endif; ?>

	</aside>
	<?php
}

/**
 * ধাপ ১০: "সারাংশ পড়ুন" ফিচার — পোস্ট এডিটরে একটা বক্সে AI/ম্যানুয়াল সামারি
 * পেস্ট করে রাখা যাবে, আর্টিকেল পেজে বাটনে ক্লিক করলে পপ-আপে দেখাবে।
 * সামারি খালি থাকলে বাটনটাই দেখাবে না।
 */
function meeha_add_summary_meta_box() {
	add_meta_box(
		'meeha_summary_box',
		'Meeha সারাংশ (AI সামারি)',
		'meeha_render_summary_meta_box',
		'post',
		'normal',
		'high'
	);
}
add_action( 'add_meta_boxes', 'meeha_add_summary_meta_box' );

function meeha_render_summary_meta_box( $post ) {
	wp_nonce_field( 'meeha_save_summary', 'meeha_summary_nonce' );
	$summary = get_post_meta( $post->ID, '_meeha_summary', true );
	?>
	<p class="description">এই আর্টিকেলের সংক্ষিপ্ত সারাংশ এখানে পেস্ট করুন (ChatGPT/Claude দিয়ে বানিয়ে আনতে পারেন, বা নিজে লিখুন)। খালি রাখলে "সারাংশ পড়ুন" বাটনটা আর্টিকেলে দেখাবে না।</p>
	<textarea name="meeha_summary" rows="6" style="width:100%;"><?php echo esc_textarea( $summary ); ?></textarea>
	<?php
}

function meeha_save_summary_meta_box( $post_id ) {
	if ( ! isset( $_POST['meeha_summary_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['meeha_summary_nonce'] ) ), 'meeha_save_summary' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	if ( isset( $_POST['meeha_summary'] ) ) {
		update_post_meta( $post_id, '_meeha_summary', sanitize_textarea_field( wp_unslash( $_POST['meeha_summary'] ) ) );
	}
}
add_action( 'save_post', 'meeha_save_summary_meta_box' );

function meeha_get_summary( $post_id ) {
	return get_post_meta( $post_id, '_meeha_summary', true );
}

/**
 * ধাপ ১১: ফটোকার্ড সিস্টেম
 * কাস্টম পোস্ট টাইপ + শর্টকোড, যেখানে উক্তি (কোটেশন) পোস্ট করলে ব্যাকগ্রাউন্ড
 * ছবি + টেক্সট + সাইট ব্র্যান্ডিং স্বয়ংক্রিয়ভাবে একটা ফ্রেমে বসে ডাউনলোডযোগ্য
 * ছবি তৈরি হয় (ব্রাউজারে Canvas দিয়ে, সার্ভারে কোনো ইমেজ-প্রসেসিং লাইব্রেরি
 * ছাড়াই — তাই যেকোনো হোস্টিংয়ে নির্ভরযোগ্যভাবে কাজ করবে)।
 */
function meeha_register_photocard_post_type() {
	register_post_type( 'meeha_photocard', array(
		'label'        => 'ফটোকার্ড',
		'public'       => true,
		'show_ui'      => true,
		'show_in_menu' => true,
		'menu_icon'    => 'dashicons-format-quote',
		'supports'     => array( 'title', 'thumbnail' ),
		'has_archive'  => true,
		'rewrite'      => array( 'slug' => 'photocard' ),
		'labels'       => array(
			'name'          => 'ফটোকার্ড',
			'singular_name' => 'ফটোকার্ড',
			'add_new'       => 'নতুন ফটোকার্ড',
			'add_new_item'  => 'নতুন ফটোকার্ড যোগ করুন',
			'edit_item'     => 'ফটোকার্ড এডিট করুন',
			'all_items'     => 'সব ফটোকার্ড',
		),
	) );

	// Quote type taxonomy (political, motivational, etc) - admin can add more terms
	register_taxonomy( 'meeha_quote_type', 'meeha_photocard', array(
		'label'             => 'উক্তির ধরন',
		'hierarchical'      => true,
		'public'            => true,
		'show_ui'           => true,
		'show_admin_column' => true,
		'rewrite'           => array( 'slug' => 'quote-type' ),
		'labels'            => array(
			'name'          => 'উক্তির ধরন',
			'singular_name' => 'ধরন',
			'add_new_item'  => 'নতুন ধরন যোগ করুন',
			'edit_item'     => 'ধরন এডিট করুন',
		),
	) );
}
add_action( 'init', 'meeha_register_photocard_post_type' );

// Seed a couple of default quote-type terms on first load, admin can add more anytime
function meeha_seed_default_quote_types() {
	if ( get_option( 'meeha_quote_types_seeded' ) ) {
		return;
	}
	$defaults = array( 'রাজনৈতিক উক্তি', 'মোটিভেশনাল উক্তি' );
	foreach ( $defaults as $term_name ) {
		if ( ! term_exists( $term_name, 'meeha_quote_type' ) ) {
			wp_insert_term( $term_name, 'meeha_quote_type' );
		}
	}
	update_option( 'meeha_quote_types_seeded', 1 );
}
add_action( 'init', 'meeha_seed_default_quote_types', 20 );

/**
 * এডমিনে "সব ফটোকার্ড" লিস্টে ভোট সংখ্যা ও ফলাফল কলাম যোগ করা
 */
function meeha_photocard_admin_columns( $columns ) {
	$columns['meeha_poll_status'] = 'ভোট';
	return $columns;
}
add_filter( 'manage_meeha_photocard_posts_columns', 'meeha_photocard_admin_columns' );

function meeha_photocard_admin_column_content( $column, $post_id ) {
	if ( 'meeha_poll_status' !== $column ) {
		return;
	}

	$poll = meeha_get_poll_data( $post_id );
	if ( empty( $poll['enabled'] ) ) {
		echo '<span style="color:#999;">ভোট চালু নেই</span>';
		return;
	}

	if ( $poll['is_closed'] ) {
		$winner = $poll['count_a'] >= $poll['count_b'] ? $poll['option_a'] : $poll['option_b'];
		printf(
			'<strong>ফলাফল প্রকাশিত</strong><br />মোট: %s<br />%s: %s, %s: %s<br />বিজয়ী: <strong style="color:#146356;">%s</strong>',
			esc_html( number_format_i18n( $poll['total'] ) ),
			esc_html( $poll['option_a'] ),
			esc_html( number_format_i18n( $poll['count_a'] ) ),
			esc_html( $poll['option_b'] ),
			esc_html( number_format_i18n( $poll['count_b'] ) ),
			esc_html( $winner )
		);
	} else {
		printf(
			'<span style="color:#146356;">● লাইভ চলছে</span><br />এখন পর্যন্ত: %s জন<br />%s: %s, %s: %s',
			esc_html( number_format_i18n( $poll['total'] ) ),
			esc_html( $poll['option_a'] ),
			esc_html( number_format_i18n( $poll['count_a'] ) ),
			esc_html( $poll['option_b'] ),
			esc_html( number_format_i18n( $poll['count_b'] ) )
		);
	}
}
add_action( 'manage_meeha_photocard_posts_custom_column', 'meeha_photocard_admin_column_content', 10, 2 );

function meeha_add_photocard_meta_box() {
	add_meta_box( 'meeha_photocard_attribution', 'বক্তার নাম / পরিচয়', 'meeha_render_photocard_meta_box', 'meeha_photocard', 'normal', 'high' );
}
add_action( 'add_meta_boxes', 'meeha_add_photocard_meta_box' );

function meeha_render_photocard_meta_box( $post ) {
	wp_nonce_field( 'meeha_save_photocard_attribution', 'meeha_photocard_nonce' );
	$attribution = get_post_meta( $post->ID, '_meeha_attribution', true );
	$poll_enabled = get_post_meta( $post->ID, '_meeha_poll_enabled', true );
	$option_a     = get_post_meta( $post->ID, '_meeha_poll_option_a', true );
	$option_b     = get_post_meta( $post->ID, '_meeha_poll_option_b', true );
	$duration     = get_post_meta( $post->ID, '_meeha_poll_duration_days', true );
	?>
	<p class="description">
		শিরোনাম (Title) ঘরে মূল উক্তি/কোটেশন লিখুন। ফিচার্ড ইমেজে বক্তার ছবি বসান
		(এটাই কার্ডে গোল আকারে দেখাবে) — ছবি ছাড়া ফটোকার্ড তৈরি হবে না।
	</p>
	<p>
		<label>বক্তার নাম ও পরিচয়<br />
		<input type="text" name="meeha_attribution" placeholder="যেমন: আব্দুল করিম, রাজনীতিবিদ" value="<?php echo esc_attr( $attribution ); ?>" style="width:100%;" />
		</label>
	</p>
	<hr />
	<p>
		<label>
			<input type="checkbox" name="meeha_poll_enabled" value="1" <?php checked( $poll_enabled ); ?> />
			এই উক্তির সাথে একটা ভোট/পোল যোগ করুন (পাঠকরা "ডাউনলোড" বাটনের পাশে একটা "ভোট দিন" বাটন দেখবে)
		</label>
	</p>
	<p>
		<label>অপশন ১ (ডিফল্ট: একমত)<br />
		<input type="text" name="meeha_poll_option_a" value="<?php echo esc_attr( $option_a ? $option_a : 'একমত' ); ?>" style="width:100%;" />
		</label>
	</p>
	<p>
		<label>অপশন ২ (ডিফল্ট: দ্বিমত)<br />
		<input type="text" name="meeha_poll_option_b" value="<?php echo esc_attr( $option_b ? $option_b : 'দ্বিমত' ); ?>" style="width:100%;" />
		</label>
	</p>
	<p>
		<label>এই পোলের মেয়াদ (দিন) — খালি রাখলে সাধারণ ডিফল্ট মেয়াদ প্রযোজ্য হবে<br />
		<input type="number" min="1" max="90" name="meeha_poll_duration_days" placeholder="ডিফল্ট: <?php echo esc_attr( meeha_get_poll_duration_days() ); ?> দিন" value="<?php echo esc_attr( $duration ); ?>" style="width:120px;" />
		</label>
	</p>
	<p class="description">
		ডিফল্ট মেয়াদ Admin → Meeha হোমপেজ → ফটোকার্ড পেজ থেকে সবার জন্য একসাথে বদলানো যায়।
		এখানে আলাদা সংখ্যা দিলে শুধু এই একটা পোলের জন্যই সেটা প্রযোজ্য হবে। মেয়াদ শেষ না হওয়া
		পর্যন্ত চূড়ান্ত ফলাফল কাউকে দেখানো হয় না — মেয়াদ শেষ হলে স্বয়ংক্রিয়ভাবে বার-চার্টে দেখাবে।
	</p>
	<?php
}

function meeha_save_photocard_attribution( $post_id ) {
	if ( ! isset( $_POST['meeha_photocard_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['meeha_photocard_nonce'] ) ), 'meeha_save_photocard_attribution' ) ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	if ( isset( $_POST['meeha_attribution'] ) ) {
		update_post_meta( $post_id, '_meeha_attribution', sanitize_text_field( wp_unslash( $_POST['meeha_attribution'] ) ) );
	}
	update_post_meta( $post_id, '_meeha_poll_enabled', isset( $_POST['meeha_poll_enabled'] ) ? 1 : 0 );
	if ( isset( $_POST['meeha_poll_option_a'] ) ) {
		update_post_meta( $post_id, '_meeha_poll_option_a', sanitize_text_field( wp_unslash( $_POST['meeha_poll_option_a'] ) ) );
	}
	if ( isset( $_POST['meeha_poll_option_b'] ) ) {
		update_post_meta( $post_id, '_meeha_poll_option_b', sanitize_text_field( wp_unslash( $_POST['meeha_poll_option_b'] ) ) );
	}
	if ( isset( $_POST['meeha_poll_duration_days'] ) && '' !== trim( wp_unslash( $_POST['meeha_poll_duration_days'] ) ) ) {
		update_post_meta( $post_id, '_meeha_poll_duration_days', absint( $_POST['meeha_poll_duration_days'] ) );
	} else {
		delete_post_meta( $post_id, '_meeha_poll_duration_days' );
	}
}
add_action( 'save_post_meeha_photocard', 'meeha_save_photocard_attribution' );

/**
 * ধাপ ১৩: পোল/ভোট সিস্টেম — প্রতিটা উক্তির (ফটোকার্ড পোস্টের) সাথে একটা
 * হ্যাঁ/না ধরনের ভোট যোগ করা যায়। একটা ফোন নাম্বার দিয়ে একবারই ভোট দেওয়া যাবে।
 * ভোট চলাকালীন ফলাফল কাউকে দেখানো হয় না — মেয়াদ শেষ হলে স্বয়ংক্রিয়ভাবে
 * ফলাফল বার-চার্ট আকারে দেখানো শুরু হয়।
 */
function meeha_poll_votes_table() {
	global $wpdb;
	return $wpdb->prefix . 'meeha_poll_votes';
}

function meeha_maybe_create_poll_table() {
	if ( '1' === get_option( 'meeha_poll_table_v1' ) ) {
		return;
	}
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$table_name      = meeha_poll_votes_table();
	$charset_collate = $wpdb->get_charset_collate();
	$sql = "CREATE TABLE $table_name (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		post_id BIGINT UNSIGNED NOT NULL,
		voter_name VARCHAR(191) NOT NULL,
		voter_phone VARCHAR(32) NOT NULL,
		voter_email VARCHAR(191) NULL,
		choice VARCHAR(1) NOT NULL,
		created_at DATETIME NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY post_phone (post_id, voter_phone),
		KEY post_id (post_id)
	) $charset_collate;";
	dbDelta( $sql );
	update_option( 'meeha_poll_table_v1', '1' );
}
add_action( 'init', 'meeha_maybe_create_poll_table' );

// পোলের মেয়াদ (দিন) — Admin > Meeha হোমপেজ > ফটোকার্ড থেকে সব পোলের জন্য একসাথে সেট করা হয়
function meeha_get_poll_duration_days() {
	$settings = get_option( 'meeha_photocard_settings', array() );
	$days     = isset( $settings['poll_duration_days'] ) ? absint( $settings['poll_duration_days'] ) : 7;
	return $days > 0 ? $days : 7;
}

/**
 * একটা উক্তি-পোস্টের পোলের বর্তমান অবস্থা — চালু আছে কিনা, শেষ হয়েছে কিনা,
 * অপশনগুলো কী, আর লাইভ ভোট সংখ্যা। ভোট চলাকালীনও মোট সংখ্যা (total) সবসময়
 * দেখানো হয় ("লাইভ" ব্যাজের জন্য) — শুধু চূড়ান্ত ফলাফল (কে জিতল) মেয়াদ শেষ
 * না হওয়া পর্যন্ত গোপন থাকে।
 */
function meeha_get_poll_data( $post_id ) {
	$enabled = (bool) get_post_meta( $post_id, '_meeha_poll_enabled', true );
	if ( ! $enabled ) {
		return array( 'enabled' => false );
	}

	$option_a = get_post_meta( $post_id, '_meeha_poll_option_a', true );
	$option_b = get_post_meta( $post_id, '_meeha_poll_option_b', true );
	$option_a = $option_a ? $option_a : 'একমত';
	$option_b = $option_b ? $option_b : 'দ্বিমত';

	// প্রতি-পোস্ট আলাদা মেয়াদ সেট করা থাকলে সেটাই প্রযোজ্য, নাহলে গ্লোবাল ডিফল্ট
	$override_duration = get_post_meta( $post_id, '_meeha_poll_duration_days', true );
	$duration_days     = $override_duration ? absint( $override_duration ) : meeha_get_poll_duration_days();
	$post_time         = get_post_time( 'U', true, $post_id );
	$end_time          = $post_time + ( $duration_days * DAY_IN_SECONDS );
	$is_closed         = time() >= $end_time;

	$result = array(
		'enabled'   => true,
		'option_a'  => $option_a,
		'option_b'  => $option_b,
		'end_time'  => $end_time,
		'is_closed' => $is_closed,
		'count_a'   => 0,
		'count_b'   => 0,
		'total'     => 0,
	);

	// লাইভ কাউন্ট সবসময় হিসাব হয় — খোলা অবস্থাতেও "X জন ভোট দিয়েছেন" দেখানোর জন্য
	global $wpdb;
	$table = meeha_poll_votes_table();
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table নিজেদের কোডেই বানানো, ইউজার ইনপুট নয়
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT choice, COUNT(*) as cnt FROM $table WHERE post_id = %d GROUP BY choice", $post_id ) );
	foreach ( $rows as $row ) {
		if ( 'a' === $row->choice ) {
			$result['count_a'] = (int) $row->cnt;
		} elseif ( 'b' === $row->choice ) {
			$result['count_b'] = (int) $row->cnt;
		}
	}
	$result['total'] = $result['count_a'] + $result['count_b'];
	if ( $is_closed ) {
		if ( $result['count_a'] === $result['count_b'] ) {
			$result['winner'] = 'tie';
		} else {
			$result['winner'] = $result['count_a'] > $result['count_b'] ? 'a' : 'b';
		}
	}

	return $result;
}

/**
 * মার্কিতে দেখানোর জন্য সাম্প্রতিক ভোটারদের তালিকা — ফোন নাম্বার মাস্ক করা
 * (প্রথম ৩ + *** + শেষ ৩ ডিজিট), নাম আংশিক প্রকাশ্য (ঠিক যেমন কমেন্টে হয়)
 */
function meeha_get_recent_voters( $post_id, $limit = 20 ) {
	global $wpdb;
	$table = meeha_poll_votes_table();
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT voter_name, voter_phone FROM $table WHERE post_id = %d ORDER BY id DESC LIMIT %d", $post_id, $limit ) );

	$voters = array();
	foreach ( $rows as $row ) {
		$phone = preg_replace( '/[^0-9]/', '', $row->voter_phone );
		if ( strlen( $phone ) >= 6 ) {
			$masked = substr( $phone, 0, 3 ) . '***' . substr( $phone, -3 );
		} else {
			$masked = str_repeat( '*', max( 0, strlen( $phone ) - 2 ) ) . substr( $phone, -2 );
		}
		$voters[] = array(
			'name'  => $row->voter_name,
			'phone' => $masked,
		);
	}
	return $voters;
}

/**
 * ভোট সাবমিট — AJAX। ফোন নাম্বার দিয়ে ডুপ্লিকেট ভোট আটকানো হয় (DB UNIQUE KEY দিয়ে)।
 * নাম-ইমেইল-ফোন প্রকাশ্যে কোথাও দেখানো হয় না (কমেন্টের ইমেইলের মতোই ব্যক্তিগত রাখা হয়)।
 */
function meeha_handle_poll_vote() {
	check_ajax_referer( 'meeha_poll_nonce', 'nonce' );

	$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
	$choice  = isset( $_POST['choice'] ) ? sanitize_text_field( wp_unslash( $_POST['choice'] ) ) : '';
	$name    = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
	$phone   = isset( $_POST['phone'] ) ? preg_replace( '/[^0-9+]/', '', wp_unslash( $_POST['phone'] ) ) : '';
	$email   = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';

	if ( ! $post_id || 'meeha_photocard' !== get_post_type( $post_id ) ) {
		wp_send_json_error( array( 'message' => 'পোলটি খুঁজে পাওয়া যায়নি।' ) );
	}
	if ( ! in_array( $choice, array( 'a', 'b' ), true ) ) {
		wp_send_json_error( array( 'message' => 'অনুগ্রহ করে একটা অপশন বাছাই করুন।' ) );
	}
	if ( strlen( $name ) < 2 ) {
		wp_send_json_error( array( 'message' => 'নাম লিখুন।' ) );
	}
	if ( strlen( $phone ) < 6 ) {
		wp_send_json_error( array( 'message' => 'সঠিক ফোন নাম্বার দিন।' ) );
	}

	$poll = meeha_get_poll_data( $post_id );
	if ( empty( $poll['enabled'] ) ) {
		wp_send_json_error( array( 'message' => 'এই উক্তিতে ভোট চালু নেই।' ) );
	}
	if ( ! empty( $poll['is_closed'] ) ) {
		wp_send_json_error( array( 'message' => 'ভোটের মেয়াদ শেষ হয়ে গেছে।' ) );
	}

	global $wpdb;
	$table    = meeha_poll_votes_table();
	$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE post_id = %d AND voter_phone = %s", $post_id, $phone ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	if ( $existing ) {
		wp_send_json_error( array( 'message' => 'এই নাম্বার দিয়ে আগেই একবার ভোট দেওয়া হয়েছে।' ) );
	}

	$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$table,
		array(
			'post_id'     => $post_id,
			'voter_name'  => $name,
			'voter_phone' => $phone,
			'voter_email' => $email ? $email : null,
			'choice'      => $choice,
			'created_at'  => current_time( 'mysql' ),
		),
		array( '%d', '%s', '%s', '%s', '%s', '%s' )
	);

	if ( ! $inserted ) {
		wp_send_json_error( array( 'message' => 'এই নাম্বার দিয়ে আগেই ভোট দেওয়া হয়ে থাকতে পারে।' ) );
	}

	if ( ! empty( $_POST['meeha_register'] ) ) {
		meeha_maybe_create_account( $name, $email );
	}
	if ( $email ) {
		meeha_add_to_mailpoet_list( $email, $name );
	}

	wp_send_json_success( array( 'message' => 'আপনার মূল্যবান ভোট সংরক্ষিত হয়েছে।' ) );
}
add_action( 'wp_ajax_meeha_poll_vote', 'meeha_handle_poll_vote' );
add_action( 'wp_ajax_nopriv_meeha_poll_vote', 'meeha_handle_poll_vote' );

/**
 * লাইভ ভোট-গণনা + মার্কির জন্য সাম্প্রতিক ভোটার তালিকা — পেজ রিলোড ছাড়াই
 * প্রতি কিছুক্ষণ পরপর JS দিয়ে রিফ্রেশ করার জন্য।
 */
function meeha_handle_poll_live_tally() {
	check_ajax_referer( 'meeha_poll_nonce', 'nonce' );

	$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
	if ( ! $post_id || 'meeha_photocard' !== get_post_type( $post_id ) ) {
		wp_send_json_error();
	}

	$poll = meeha_get_poll_data( $post_id );
	if ( empty( $poll['enabled'] ) ) {
		wp_send_json_error();
	}

	wp_send_json_success( array(
		'total'  => $poll['total'],
		'voters' => meeha_get_recent_voters( $post_id, 20 ),
	) );
}
add_action( 'wp_ajax_meeha_poll_live_tally', 'meeha_handle_poll_live_tally' );
add_action( 'wp_ajax_nopriv_meeha_poll_live_tally', 'meeha_handle_poll_live_tally' );

// Track actual downloads (not previews) so we can show "most downloaded" quotes
function meeha_handle_track_download() {
	check_ajax_referer( 'meeha_poll_nonce', 'nonce' );

	$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
	if ( ! $post_id || 'meeha_photocard' !== get_post_type( $post_id ) ) {
		wp_send_json_error();
	}

	$count = (int) get_post_meta( $post_id, '_meeha_download_count', true );
	update_post_meta( $post_id, '_meeha_download_count', $count + 1 );

	wp_send_json_success( array( 'count' => $count + 1 ) );
}
add_action( 'wp_ajax_meeha_track_download', 'meeha_handle_track_download' );
add_action( 'wp_ajax_nopriv_meeha_track_download', 'meeha_handle_track_download' );

/**
 * একটা উক্তি-কার্ডের HTML — সাদামাটা টেমপ্লেট: কোট আইকন, গোলাকার (সার্কেল)
 * ক্লিপ করা ছবি, উক্তি, নিচে ছোট করে নাম ও পরিচয়। আসল ডাউনলোডযোগ্য PNG
 * ক্লিকের সময় JS দিয়ে Canvas-এ একই ডিজাইনে বানানো হয়।
 */
function meeha_render_photocard_item( $post_obj ) {
	$quote       = get_the_title( $post_obj );
	$attribution = get_post_meta( $post_obj->ID, '_meeha_attribution', true );
	$image_url   = get_the_post_thumbnail_url( $post_obj, 'large' );

	if ( ! $image_url ) {
		return; // ছবি ছাড়া কার্ড দেখানো যাবে না
	}

	// "আব্দুল করিম, রাজনীতিবিদ" ফরম্যাট থাকলে নাম ও টাইটেল আলাদা করে দেখানো হয়
	$name  = $attribution;
	$title = '';
	if ( strpos( $attribution, ',' ) !== false ) {
		$parts = array_map( 'trim', explode( ',', $attribution, 2 ) );
		$name  = $parts[0];
		$title = isset( $parts[1] ) ? $parts[1] : '';
	}

	$branding = meeha_get_photocard_branding();
	$ad       = meeha_get_photocard_settings();
	$poll     = meeha_get_poll_data( $post_obj->ID );
	$share_text = meeha_get_summary( $post_obj->ID );
	if ( ! $share_text ) {
		$share_text = wp_trim_words( $quote, 25 );
	}
	?>
	<div class="meeha-photocard meeha-quote-card"
		data-layout="quote-simple"
		data-file-id="<?php echo esc_attr( $post_obj->ID ); ?>"
		data-post-id="<?php echo esc_attr( $post_obj->ID ); ?>"
		data-quote="<?php echo esc_attr( $quote ); ?>"
		data-kicker=""
		data-attribution="<?php echo esc_attr( $attribution ); ?>"
		data-image="<?php echo esc_url( $image_url ); ?>"
		data-date="<?php echo esc_attr( get_the_date( 'j F Y', $post_obj ) ); ?>"
		data-logo="<?php echo esc_url( $branding['logo_url'] ); ?>"
		data-sitename="<?php echo esc_attr( $branding['site_name'] ); ?>"
		data-ad-enabled="<?php echo $ad['footer_ad_enabled'] ? '1' : '0'; ?>"
		data-ad-image="<?php echo esc_url( $ad['footer_ad_image'] ); ?>"
		data-permalink="<?php echo esc_url( get_permalink( $post_obj ) ); ?>"
		data-share-text="<?php echo esc_attr( $share_text ); ?>">

		<div class="meeha-quote-card__body">
			<span class="meeha-quote-card__icon" aria-hidden="true">&#8220;</span>
			<p class="meeha-quote-card__text"><?php echo esc_html( wp_trim_words( $quote, 30 ) ); ?></p>
			<div class="meeha-quote-card__avatar">
				<img src="<?php echo esc_url( $image_url ); ?>" alt="<?php echo esc_attr( $name ); ?>" loading="lazy" />
			</div>
			<?php if ( $name ) : ?>
				<p class="meeha-quote-card__name"><?php echo esc_html( $name ); ?></p>
			<?php endif; ?>
			<?php if ( $title ) : ?>
				<p class="meeha-quote-card__role"><?php echo esc_html( $title ); ?></p>
			<?php endif; ?>
		</div>

		<div class="meeha-quote-card__actions">
			<button type="button" class="meeha-photocard__download">ডাউনলোড করুন</button>
			<?php if ( ! empty( $poll['enabled'] ) ) : ?>
				<a class="meeha-quote-card__vote" href="<?php echo esc_url( get_permalink( $post_obj ) . '#meeha-poll' ); ?>">
					<?php echo $poll['is_closed'] ? 'ফলাফল দেখুন' : 'ভোট দিন'; ?>
				</a>
			<?php endif; ?>
		</div>
	</div>
	<?php
}

/**
 * ধাপ ১২: প্রতিটা আর্টিকেলের জন্য অটোমেটিক সোশ্যাল মিডিয়া ফটোকার্ড
 * (যমুনা/অন্যান্য নিউজ সাইটের মতো) — ফিচার ইমেজ + টাইটেল + সাইট লোগো দিয়ে,
 * কোনো ম্যানুয়াল এন্ট্রি ছাড়াই প্রতিটা সিঙ্গেল পোস্টে এটা স্বয়ংক্রিয়ভাবে বসবে।
 * ফুটার বিজ্ঞাপন চালু থাকলে এখানেও দেখাবে (Admin > Meeha হোমপেজ > ফটোকার্ড থেকে নিয়ন্ত্রিত)।
 */
function meeha_render_auto_photocard( $post_id ) {
	$image_url = get_the_post_thumbnail_url( $post_id, 'large' );
	if ( ! $image_url ) {
		return; // ফিচার্ড ইমেজ ছাড়া অটো-কার্ড বানানো সম্ভব না
	}

	$title    = get_the_title( $post_id );
	$cats     = get_the_category( $post_id );
	$kicker   = ! empty( $cats ) ? $cats[0]->name : '';
	$branding = meeha_get_photocard_branding();
	$ad       = meeha_get_photocard_settings();
	$share_text = meeha_get_summary( $post_id );
	if ( ! $share_text ) {
		$share_text = wp_trim_words( get_the_excerpt( $post_id ), 25 );
	}
	?>
	<div class="meeha-photocard meeha-photocard--auto"
		data-layout="banded"
		data-file-id="<?php echo esc_attr( $post_id ); ?>"
		data-quote="<?php echo esc_attr( $title ); ?>"
		data-kicker="<?php echo esc_attr( $kicker ); ?>"
		data-attribution=""
		data-image="<?php echo esc_url( $image_url ); ?>"
		data-date="<?php echo esc_attr( get_the_date( 'j F Y', $post_id ) ); ?>"
		data-logo="<?php echo esc_url( $branding['logo_url'] ); ?>"
		data-sitename="<?php echo esc_attr( $branding['site_name'] ); ?>"
		data-ad-enabled="<?php echo $ad['footer_ad_enabled'] ? '1' : '0'; ?>"
		data-ad-image="<?php echo esc_url( $ad['footer_ad_image'] ); ?>"
		data-permalink="<?php echo esc_url( get_permalink( $post_id ) ); ?>"
		data-share-text="<?php echo esc_attr( $share_text ); ?>">
		<button type="button" class="meeha-photocard__download meeha-photocard__download--auto">
			<span class="meeha-photocard__download-icon" aria-hidden="true">⤓</span>
			সোশ্যাল মিডিয়া কার্ড ডাউনলোড করুন
		</button>
	</div>
	<?php
}

/**
 * শর্টকোড — [meeha_photocards] সব ফটোকার্ডের গ্রিড দেখাবে (ডেডিকেটেড পেজের জন্য),
 * [meeha_photocard id="123"] একটা নির্দিষ্ট কার্ড দেখাবে (সিঙ্গেল পোস্টে বসানোর জন্য)
 */
function meeha_photocards_shortcode( $atts ) {
	$atts = shortcode_atts( array(
		'id'    => 0,
		'count' => 12,
	), $atts, 'meeha_photocards' );

	ob_start();

	if ( (int) $atts['id'] > 0 ) {
		$single_card = get_post( (int) $atts['id'] );
		if ( $single_card && 'meeha_photocard' === $single_card->post_type ) {
			echo '<div class="meeha-photocard-grid meeha-photocard-grid--single">';
			meeha_render_photocard_item( $single_card );
			echo '</div>';
		}
	} else {
		meeha_render_photocard_browse_shell( (int) $atts['count'] );
	}

	return ob_get_clean();
}
add_shortcode( 'meeha_photocards', 'meeha_photocards_shortcode' );
add_shortcode( 'meeha_photocard', 'meeha_photocards_shortcode' );

/**
 * শর্টকোড যে পেজে ব্যবহার হয়েছে, শুধু সেই পেজেই CSS/JS লোড হবে —
 * বাকি সাইটে বাড়তি লোড পড়বে না
 */
function meeha_maybe_enqueue_photocard_assets() {
	global $post;

	if ( is_post_type_archive( 'meeha_photocard' ) ) {
		meeha_enqueue_photocard_assets_now();
		return;
	}

	if ( ! is_a( $post, 'WP_Post' ) ) {
		return;
	}

	$has_shortcode = has_shortcode( $post->post_content, 'meeha_photocards' ) || has_shortcode( $post->post_content, 'meeha_photocard' );

	// প্রতিটা আর্টিকেল পেজেই এখন অটো-ফটোকার্ড থাকে, তাই is_single()-এও লোড হবে
	if ( ! $has_shortcode && ! is_single() ) {
		return;
	}

	meeha_enqueue_photocard_assets_now();
}

function meeha_enqueue_photocard_assets_now() {
	wp_enqueue_style(
		'meeha-google-fonts',
		'https://fonts.googleapis.com/css2?family=Baloo+Da+2:wght@500;600;700;800&family=Hind+Siliguri:wght@400;500;600;700&family=Inter:wght@400;500;600&display=swap',
		array(),
		null
	);
	wp_enqueue_style(
		'meeha-photocard-style',
		MEEHA_CHILD_URI . '/assets/css/meeha-photocard.css',
		array( 'meeha-google-fonts' ),
		MEEHA_CHILD_VERSION
	);
	wp_enqueue_script(
		'meeha-photocard-script',
		MEEHA_CHILD_URI . '/assets/js/meeha-photocard.js',
		array(),
		MEEHA_CHILD_VERSION,
		true
	);
	wp_enqueue_style(
		'meeha-photocard-browse-style',
		MEEHA_CHILD_URI . '/assets/css/meeha-photocard-browse.css',
		array( 'meeha-photocard-style' ),
		MEEHA_CHILD_VERSION
	);
	wp_enqueue_style(
		'meeha-glance-style', // reused for the .meeha-glance__more "load more" button
		MEEHA_CHILD_URI . '/assets/css/meeha-glance.css',
		array(),
		MEEHA_CHILD_VERSION
	);
	wp_enqueue_script(
		'meeha-photocard-browse-script',
		MEEHA_CHILD_URI . '/assets/js/meeha-photocard-browse.js',
		array(),
		MEEHA_CHILD_VERSION,
		true
	);
	wp_add_inline_script(
		'meeha-photocard-browse-script',
		'window.meehaPcBrowseAjax = { url: "' . esc_url( admin_url( 'admin-ajax.php' ) ) . '", nonce: "' . esc_js( wp_create_nonce( 'meeha_pc_browse_nonce' ) ) . '" };',
		'before'
	);
}
add_action( 'wp_enqueue_scripts', 'meeha_maybe_enqueue_photocard_assets' );
function meeha_get_single_settings() {
	$settings = get_option( 'meeha_single_settings', array() );
	return array(
		'sidebar_enabled'       => ! isset( $settings['sidebar_enabled'] ) || $settings['sidebar_enabled'],
		'share_buttons_enabled' => ! isset( $settings['share_buttons_enabled'] ) || $settings['share_buttons_enabled'],
		'author_box_enabled'    => ! isset( $settings['author_box_enabled'] ) || $settings['author_box_enabled'],
		'related_posts_enabled' => ! isset( $settings['related_posts_enabled'] ) || $settings['related_posts_enabled'],
		'progress_bar_enabled'  => ! isset( $settings['progress_bar_enabled'] ) || $settings['progress_bar_enabled'],
		'readmore_enabled'      => ! isset( $settings['readmore_enabled'] ) || $settings['readmore_enabled'],
		'readmore_height'       => ! empty( $settings['readmore_height'] ) ? max( 300, (int) $settings['readmore_height'] ) : 900,
		'comments_enabled'      => ! isset( $settings['comments_enabled'] ) || $settings['comments_enabled'],
		'comments_heading'      => ! empty( $settings['comments_heading'] ) ? sanitize_text_field( $settings['comments_heading'] ) : 'মন্তব্য করুন',
		// ফন্ট +/- বাটনে প্রতি ক্লিকে কত % বাড়বে/কমবে (ডিফল্ট ১০%)
		'font_step_percent'     => ! empty( $settings['font_step_percent'] ) ? max( 1, min( 30, (int) $settings['font_step_percent'] ) ) : 10,
	);
}
/**
 * ধাপ ১৪: "একনজরে" — [meeha_at_a_glance] শর্টকোড। সব খবরের একটা ফিল্টারযোগ্য,
 * ইনফিনিট-স্ক্রল লিস্ট — থাম্ব + টাইটেল + সময় + ক্যাটাগরি ব্যাজ, ক্লিক করলে
 * নিচে অ্যাকর্ডিয়নের মতো এক্সারপ্ট এক্সপান্ড হয় (পপ-আপ না)।
 */
function meeha_at_a_glance_shortcode() {
	$categories = get_categories( array( 'hide_empty' => true ) );

	ob_start();
	?>
	<div class="meeha-glance-layout">
	<div class="meeha-glance" id="meeha-glance">

		<div class="meeha-glance__filters">
			<div class="meeha-glance__quick-filters" id="meeha-glance-quick-filters">
				<button type="button" class="meeha-glance__quick-btn is-active" data-range="all">সবগুলো</button>
				<button type="button" class="meeha-glance__quick-btn" data-range="today">আজকের</button>
				<button type="button" class="meeha-glance__quick-btn" data-range="yesterday">গতকালকের</button>
				<button type="button" class="meeha-glance__quick-btn" data-range="3days">গত তিন দিনের</button>
				<button type="button" class="meeha-glance__quick-btn" data-range="7days">গত সাত দিনের</button>
			</div>

			<div class="meeha-glance__advanced-filters">
				<input type="date" class="meeha-glance__date" id="meeha-glance-date" aria-label="নির্দিষ্ট তারিখ বাছাই করুন" />
				<select class="meeha-glance__category" id="meeha-glance-category" aria-label="ক্যাটাগরি বাছাই করুন">
					<option value="0">সব ক্যাটাগরি</option>
					<?php foreach ( $categories as $cat ) : ?>
						<option value="<?php echo esc_attr( $cat->term_id ); ?>"><?php echo esc_html( $cat->name ); ?></option>
					<?php endforeach; ?>
				</select>
				<input type="search" class="meeha-glance__search" id="meeha-glance-search" placeholder="খবর খুঁজুন..." aria-label="খবর খুঁজুন" />
			</div>
		</div>

		<div class="meeha-glance__list" id="meeha-glance-list"></div>

		<p class="meeha-glance__empty" id="meeha-glance-empty" hidden>কোনো খবর পাওয়া যায়নি।</p>
		<p class="meeha-glance__loading" id="meeha-glance-loading">লোড হচ্ছে...</p>

		<button type="button" class="meeha-glance__more" id="meeha-glance-more" hidden>আরও দেখুন</button>
	</div>

	<aside class="meeha-sidebar">
		<?php meeha_render_area_sidebar(); ?>
	</aside>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'meeha_at_a_glance', 'meeha_at_a_glance_shortcode' );

/**
 * একনজরে-র একটা লিস্ট-আইটেম — থাম্ব (ওপরে বাম কোণে ক্যাটাগরি ব্যাজ), টাইটেল,
 * সময়, আর ক্লিকে-এক্সপান্ড-হওয়া এক্সারপ্ট
 */
function meeha_render_glance_item() {
	$cats     = get_the_category();
	$cat_name = ! empty( $cats ) ? $cats[0]->name : '';
	?>
	<div class="meeha-glance__item">
		<button type="button" class="meeha-glance__item-head" aria-expanded="false">
			<span class="meeha-glance__thumb">
				<?php if ( has_post_thumbnail() ) : ?>
					<img src="<?php echo esc_url( get_the_post_thumbnail_url( get_the_ID(), 'thumbnail' ) ); ?>" alt="" loading="lazy" />
				<?php endif; ?>
				<?php if ( $cat_name ) : ?>
					<span class="meeha-glance__cat"><?php echo esc_html( $cat_name ); ?></span>
				<?php endif; ?>
			</span>
			<span class="meeha-glance__info">
				<span class="meeha-glance__title"><?php $topic = meeha_get_topic_label(); if ( $topic ) : ?><span class="meeha-glance__topic"><?php echo esc_html( $topic ); ?></span> <?php endif; ?><?php the_title(); ?></span>
				<span class="meeha-glance__time"><?php echo esc_html( meeha_bengali_time_ago( get_the_time( 'U' ) ) ); ?></span>
			</span>
			<span class="meeha-glance__chevron" aria-hidden="true">⌄</span>
		</button>
		<div class="meeha-glance__excerpt">
			<p><?php echo esc_html( wp_trim_words( get_the_excerpt(), 40 ) ); ?></p>
			<a href="<?php the_permalink(); ?>" class="meeha-glance__readmore">সম্পূর্ণ পড়ুন →</a>
		</div>
	</div>
	<?php
}

/**
 * একনজরে-র AJAX ফিড — ফিল্টার (রেঞ্জ/তারিখ/ক্যাটাগরি/সার্চ) + পেজিনেশন
 * (লোড-মোর / ইনফিনিট স্ক্রলের জন্য) হ্যান্ডল করে
 */
function meeha_handle_glance_fetch() {
	check_ajax_referer( 'meeha_glance_nonce', 'nonce' );

	$paged    = isset( $_POST['page'] ) ? max( 1, absint( $_POST['page'] ) ) : 1;
	$search   = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
	$category = isset( $_POST['category'] ) ? absint( $_POST['category'] ) : 0;
	$range    = isset( $_POST['range'] ) ? sanitize_text_field( wp_unslash( $_POST['range'] ) ) : 'all';
	$date     = isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : '';

	$args = array(
		'post_type'      => 'post',
		'post_status'    => 'publish',
		'posts_per_page' => 12,
		'paged'          => $paged,
		'orderby'        => 'date',
		'order'          => 'DESC',
	);

	if ( $search ) {
		$args['s'] = $search;
	}
	if ( $category ) {
		$args['cat'] = $category;
	}

	// নির্দিষ্ট তারিখ বাছাই করা থাকলে সেটাই প্রাধান্য পায়, নাহলে কুইক-ফিল্টার রেঞ্জ প্রযোজ্য হয়
	if ( $date && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
		list( $y, $m, $d ) = explode( '-', $date );
		$args['date_query'] = array(
			array( 'year' => (int) $y, 'month' => (int) $m, 'day' => (int) $d ),
		);
	} elseif ( $range && 'all' !== $range ) {
		$tz  = wp_timezone();
		$now = new DateTime( 'now', $tz );
		$before = null;

		switch ( $range ) {
			case 'yesterday':
				$after  = ( clone $now )->modify( '-1 day' )->setTime( 0, 0, 0 );
				$before = ( clone $now )->setTime( 0, 0, 0 );
				break;
			case '3days':
				$after = ( clone $now )->modify( '-3 days' )->setTime( 0, 0, 0 );
				break;
			case '7days':
				$after = ( clone $now )->modify( '-7 days' )->setTime( 0, 0, 0 );
				break;
			case 'today':
			default:
				$after = ( clone $now )->setTime( 0, 0, 0 );
				break;
		}

		$date_query = array(
			'after'     => $after->format( 'Y-m-d H:i:s' ),
			'inclusive' => true,
		);
		if ( $before ) {
			$date_query['before'] = $before->format( 'Y-m-d H:i:s' );
		}
		$args['date_query'] = array( $date_query );
	}

	$query = new WP_Query( $args );

	ob_start();
	if ( $query->have_posts() ) {
		while ( $query->have_posts() ) {
			$query->the_post();
			meeha_render_glance_item();
		}
	}
	$html = ob_get_clean();
	wp_reset_postdata();

	wp_send_json_success( array(
		'html'     => $html,
		'has_more' => $paged < $query->max_num_pages,
		'found'    => (int) $query->found_posts,
	) );
}
add_action( 'wp_ajax_meeha_glance_fetch', 'meeha_handle_glance_fetch' );
add_action( 'wp_ajax_nopriv_meeha_glance_fetch', 'meeha_handle_glance_fetch' );

/**
 * শর্টকোড যে পেজে বসানো আছে শুধু সেই পেজেই CSS/JS লোড হবে
 */
function meeha_maybe_enqueue_glance_assets() {
	global $post;
	if ( ! is_a( $post, 'WP_Post' ) || ! has_shortcode( $post->post_content, 'meeha_at_a_glance' ) ) {
		return;
	}

	wp_enqueue_style(
		'meeha-google-fonts',
		'https://fonts.googleapis.com/css2?family=Baloo+Da+2:wght@500;600;700;800&family=Hind+Siliguri:wght@400;500;600;700&family=Inter:wght@400;500;600&display=swap',
		array(),
		null
	);
	wp_enqueue_style(
		'meeha-glance-style',
		MEEHA_CHILD_URI . '/assets/css/meeha-glance.css',
		array( 'meeha-google-fonts' ),
		MEEHA_CHILD_VERSION
	);
	wp_enqueue_style(
		'meeha-single-style',
		MEEHA_CHILD_URI . '/assets/css/meeha-single.css',
		array( 'meeha-glance-style' ),
		MEEHA_CHILD_VERSION
	);
	wp_enqueue_script(
		'meeha-glance-script',
		MEEHA_CHILD_URI . '/assets/js/meeha-glance.js',
		array(),
		MEEHA_CHILD_VERSION,
		true
	);
	wp_add_inline_script(
		'meeha-glance-script',
		'window.meehaGlanceAjax = { url: "' . esc_url( admin_url( 'admin-ajax.php' ) ) . '", nonce: "' . esc_js( wp_create_nonce( 'meeha_glance_nonce' ) ) . '" };',
		'before'
	);

	// এরিয়া উইজেট সাইডবারের জন্য meeha-single.js (তার area-widget হ্যান্ডলার) লোড করা হচ্ছে
	wp_enqueue_script(
		'meeha-single-script',
		MEEHA_CHILD_URI . '/assets/js/meeha-single.js',
		array(),
		MEEHA_CHILD_VERSION,
		true
	);
	wp_add_inline_script(
		'meeha-single-script',
		'window.meehaPollAjax = { url: "' . esc_url( admin_url( 'admin-ajax.php' ) ) . '", nonce: "' . esc_js( wp_create_nonce( 'meeha_poll_nonce' ) ) . '" };',
		'before'
	);
}
add_action( 'wp_enqueue_scripts', 'meeha_maybe_enqueue_glance_assets' );
/**
 * Quote sidebar - most downloaded quotes list, used on single quote page.
 */
function meeha_render_quote_sidebar( $current_id ) {
	$query = new WP_Query( array(
		'post_type'      => 'meeha_photocard',
		'post_status'    => 'publish',
		'posts_per_page' => 5,
		'post__not_in'   => array( $current_id ),
		'meta_key'       => '_meeha_download_count',
		'orderby'        => 'meta_value_num',
		'order'          => 'DESC',
		'no_found_rows'  => true,
	) );

	if ( ! $query->have_posts() ) {
		return;
	}
	?>
	<aside class="meeha-quote-sidebar">
		<h3 class="meeha-most-read__title">সার্বাধিক ডাউনলোড উক্তি</h3>
		<ol class="meeha-most-read__list">
			<?php
			$rank = 1;
			while ( $query->have_posts() ) :
				$query->the_post();
				$downloads = (int) get_post_meta( get_the_ID(), '_meeha_download_count', true );
				?>
				<li class="meeha-most-read__item">
					<span class="meeha-most-read__rank"><?php echo esc_html( $rank ); ?></span>
					<a href="<?php the_permalink(); ?>" class="meeha-most-read__link">
						<span class="meeha-most-read__thumb"><?php meeha_card_thumbnail( 'thumbnail' ); ?></span>
						<span class="meeha-most-read__title-text">
							<?php the_title(); ?>
							<small class="meeha-quote-sidebar__count"><?php echo esc_html( $downloads ); ?> ডাউনলোড</small>
						</span>
					</a>
				</li>
				<?php
				$rank++;
			endwhile;
			?>
		</ol>
	</aside>
	<?php
	wp_reset_postdata();
}

/**
 * Related quotes by shared quote-type term, shown below content on single quote page.
 */
function meeha_render_related_quotes( $current_id ) {
	$terms = get_the_terms( $current_id, 'meeha_quote_type' );
	if ( ! $terms || is_wp_error( $terms ) ) {
		return;
	}
	$term_ids = wp_list_pluck( $terms, 'term_id' );

	$query = new WP_Query( array(
		'post_type'      => 'meeha_photocard',
		'post_status'    => 'publish',
		'posts_per_page' => 4,
		'post__not_in'   => array( $current_id ),
		'no_found_rows'  => true,
		'tax_query'      => array( array(
			'taxonomy' => 'meeha_quote_type',
			'field'    => 'term_id',
			'terms'    => $term_ids,
		) ),
	) );

	if ( ! $query->have_posts() ) {
		return;
	}
	?>
	<section class="meeha-related-quotes">
		<h2 class="meeha-poll__title">সম্পর্কিত উক্তি</h2>
		<div class="meeha-photocard-grid">
			<?php
			while ( $query->have_posts() ) :
				$query->the_post();
				meeha_render_photocard_item( get_post() );
			endwhile;
			?>
		</div>
	</section>
	<?php
	wp_reset_postdata();
}

/**
 * Quote archive page shortcode-style item card (small, for section grids).
 */
function meeha_render_quote_archive_card( $post_obj ) {
	$attribution = get_post_meta( $post_obj->ID, '_meeha_attribution', true );
	$name        = strpos( $attribution, ',' ) !== false ? trim( explode( ',', $attribution )[0] ) : $attribution;
	?>
	<a href="<?php echo esc_url( get_permalink( $post_obj ) ); ?>" class="meeha-quote-archive-card">
		<span class="meeha-quote-archive-card__thumb">
			<?php if ( has_post_thumbnail( $post_obj ) ) : ?>
				<img src="<?php echo esc_url( get_the_post_thumbnail_url( $post_obj, 'thumbnail' ) ); ?>" alt="" loading="lazy" />
			<?php endif; ?>
		</span>
		<span class="meeha-quote-archive-card__body">
			<span class="meeha-quote-archive-card__title"><?php echo esc_html( wp_trim_words( get_the_title( $post_obj ), 12 ) ); ?></span>
			<?php if ( $name ) : ?><span class="meeha-quote-archive-card__name"><?php echo esc_html( $name ); ?></span><?php endif; ?>
		</span>
	</a>
	<?php
}

/**
 * A single archive section (title + horizontal row of quote cards).
 */
function meeha_render_quote_archive_section( $title, $query ) {
	if ( ! $query->have_posts() ) {
		return;
	}
	?>
	<section class="meeha-quote-archive-section">
		<h2 class="meeha-quote-archive-section__title"><?php echo esc_html( $title ); ?></h2>
		<div class="meeha-quote-archive-section__row">
			<?php
			while ( $query->have_posts() ) {
				$query->the_post();
				meeha_render_quote_archive_card( get_post() );
			}
			?>
		</div>
	</section>
	<?php
	wp_reset_postdata();
}

// AJAX feed for the quote archive filter bar (search + quote-type + pagination)
function meeha_handle_quote_archive_fetch() {
	check_ajax_referer( 'meeha_glance_nonce', 'nonce' );

	$paged    = isset( $_POST['page'] ) ? max( 1, absint( $_POST['page'] ) ) : 1;
	$search   = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
	$type     = isset( $_POST['type'] ) ? absint( $_POST['type'] ) : 0;

	$args = array(
		'post_type'      => 'meeha_photocard',
		'post_status'    => 'publish',
		'posts_per_page' => 12,
		'paged'          => $paged,
		'orderby'        => 'date',
		'order'          => 'DESC',
	);
	if ( $search ) {
		$args['s'] = $search;
	}
	if ( $type ) {
		$args['tax_query'] = array( array(
			'taxonomy' => 'meeha_quote_type',
			'field'    => 'term_id',
			'terms'    => $type,
		) );
	}

	$query = new WP_Query( $args );

	ob_start();
	if ( $query->have_posts() ) {
		while ( $query->have_posts() ) {
			$query->the_post();
			meeha_render_photocard_item( get_post() );
		}
	}
	$html = ob_get_clean();
	wp_reset_postdata();

	wp_send_json_success( array(
		'html'     => $html,
		'has_more' => $paged < $query->max_num_pages,
		'found'    => (int) $query->found_posts,
	) );
}
add_action( 'wp_ajax_meeha_quote_archive_fetch', 'meeha_handle_quote_archive_fetch' );
add_action( 'wp_ajax_nopriv_meeha_quote_archive_fetch', 'meeha_handle_quote_archive_fetch' );

// Load archive/filter assets only on the quote archive page
function meeha_maybe_enqueue_quote_archive_assets() {
	if ( ! is_post_type_archive( 'meeha_photocard' ) ) {
		return;
	}
	wp_enqueue_style(
		'meeha-glance-style',
		MEEHA_CHILD_URI . '/assets/css/meeha-glance.css',
		array(),
		MEEHA_CHILD_VERSION
	);
	wp_enqueue_script(
		'meeha-quote-archive-script',
		MEEHA_CHILD_URI . '/assets/js/meeha-quote-archive.js',
		array(),
		MEEHA_CHILD_VERSION,
		true
	);
	wp_add_inline_script(
		'meeha-quote-archive-script',
		'window.meehaGlanceAjax = { url: "' . esc_url( admin_url( 'admin-ajax.php' ) ) . '", nonce: "' . esc_js( wp_create_nonce( 'meeha_glance_nonce' ) ) . '" };',
		'before'
	);
}
add_action( 'wp_enqueue_scripts', 'meeha_maybe_enqueue_quote_archive_assets' );

/**
 * "সর্বশেষ" shortcode - same visual language as homepage (hero + card grid).
 * Query is strict newest-to-oldest across all categories. Top block
 * auto-refreshes every N seconds; below that a category-tab explorer.
 */

// Shared hero+grid renderer - used for both initial render and the AJAX refresh
function meeha_render_latest_top( $hero_count = 1, $grid_count = 12 ) {
	// আগে এখানে পুরনো .meeha-hero* ক্লাস ব্যবহার হতো, যেটার CSS হিরো
	// রিডিজাইনের সময় সরিয়ে ফেলা হয়েছিল - ফলে এই পেজটা পুরোপুরি আনস্টাইলড/
	// ভাঙা দেখাচ্ছিল। এখন হোমপেজের নতুন .meeha-newshero* ক্লাস (Al Jazeera/
	// AFP-স্টাইল) দিয়েই বসানো হলো, একই CSS পুনর্ব্যবহার করে। এখানে হাইলাইট
	// ক্যাটাগরির দরকার নেই - শুধু সবচেয়ে নতুন ১টা পোস্ট টপ স্টোরি হিসেবে,
	// আর তার পরেরগুলো নিচে ক্রম অনুযায়ী (তারিখ অনুযায়ী, নতুন থেকে পুরনো)।
	$hero_query = new WP_Query( array(
		'posts_per_page'      => 1,
		'ignore_sticky_posts' => true,
	) );

	if ( $hero_query->have_posts() ) :
		$hero_query->the_post();
		$show_top_image = meeha_get_hero_top_image_enabled();
		?>
		<section class="meeha-newshero">
			<a href="<?php the_permalink(); ?>" class="meeha-newshero__top">
				<?php if ( $show_top_image ) : ?>
					<div class="meeha-newshero__top-image"><?php meeha_card_thumbnail( 'large' ); ?></div>
				<?php endif; ?>
				<div class="meeha-newshero__top-text">
					<?php $cats = get_the_category(); ?>
					<span class="meeha-eyebrow"><?php echo esc_html( $cats ? $cats[0]->name : '' ); ?></span>
					<h1 class="meeha-newshero__title"><?php the_title(); ?></h1>
					<p class="meeha-newshero__excerpt"><?php echo esc_html( wp_trim_words( get_the_excerpt(), 26 ) ); ?></p>
					<span class="meeha-meta"><?php echo esc_html( meeha_bengali_time_ago( get_the_time( 'U' ) ) ); ?></span>
				</div>
			</a>
		</section>
		<?php
	endif;
	wp_reset_postdata();

	$grid_query = new WP_Query( array(
		'posts_per_page'      => $grid_count,
		'offset'               => 1,
		'ignore_sticky_posts' => true,
	) );
	if ( $grid_query->have_posts() ) :
		?>
		<div class="meeha-grid">
			<?php while ( $grid_query->have_posts() ) : $grid_query->the_post(); ?>
				<a href="<?php the_permalink(); ?>" class="meeha-card">
					<div class="meeha-card__image"><?php meeha_card_thumbnail( 'medium' ); ?></div>
					<h3 class="meeha-card__title"><?php the_title(); ?></h3>
					<span class="meeha-meta"><?php echo esc_html( meeha_bengali_time_ago( get_the_time( 'U' ) ) ); ?></span>
				</a>
			<?php endwhile; ?>
		</div>
		<?php
	endif;
	wp_reset_postdata();
}

function meeha_latest_feed_shortcode() {
	$categories      = get_categories( array( 'hide_empty' => true ) );
	$default_cat     = meeha_get_hero_category(); // reuse homepage's configured hero category as default tab
	if ( ! $default_cat && $categories ) {
		$default_cat = $categories[0]->term_id;
	}

	ob_start();
	?>
	<div class="meeha-latest-page" id="meeha-latest-page" data-refresh-seconds="30">

		<div id="meeha-latest-top">
			<?php meeha_render_latest_top(); ?>
		</div>

		<p class="meeha-glance__loading" id="meeha-latest-loading" hidden>লোড হচ্ছে...</p>
		<div class="meeha-grid" id="meeha-latest-more-wrap"></div>
		<button type="button" class="meeha-glance__more" id="meeha-latest-more">আরও দেখুন</button>

		<?php if ( $categories ) : ?>
			<section class="meeha-section meeha-latest-cats">
				<div class="meeha-section__head">
					<span class="meeha-eyebrow-bar"></span>
					<h2>ক্যাটাগরি অনুযায়ী দেখুন</h2>
				</div>
				<div class="meeha-latest-cats__tabs" id="meeha-latest-cats-tabs">
					<?php foreach ( $categories as $cat ) : ?>
						<button type="button" class="meeha-latest-cats__tab<?php echo (int) $cat->term_id === (int) $default_cat ? ' is-active' : ''; ?>" data-category="<?php echo esc_attr( $cat->term_id ); ?>">
							<?php echo esc_html( $cat->name ); ?>
						</button>
					<?php endforeach; ?>
				</div>
				<div class="meeha-grid" id="meeha-latest-cats-grid">
					<?php
					if ( $default_cat ) {
						$cat_query = new WP_Query( array(
							'cat'                 => $default_cat,
							'posts_per_page'      => 8,
							'ignore_sticky_posts' => true,
						) );
						while ( $cat_query->have_posts() ) :
							$cat_query->the_post();
							?>
							<a href="<?php the_permalink(); ?>" class="meeha-card">
								<div class="meeha-card__image"><?php meeha_card_thumbnail( 'medium' ); ?></div>
								<h3 class="meeha-card__title"><?php the_title(); ?></h3>
								<span class="meeha-meta"><?php echo esc_html( meeha_bengali_time_ago( get_the_time( 'U' ) ) ); ?></span>
							</a>
							<?php
						endwhile;
						wp_reset_postdata();
					}
					?>
				</div>
			</section>
		<?php endif; ?>

	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'meeha_latest_feed', 'meeha_latest_feed_shortcode' );

// AJAX: refresh the hero+grid top block - always newest N, used for periodic auto-refresh
function meeha_handle_latest_top_refresh() {
	check_ajax_referer( 'meeha_glance_nonce', 'nonce' );
	ob_start();
	meeha_render_latest_top();
	wp_send_json_success( array( 'html' => ob_get_clean() ) );
}
add_action( 'wp_ajax_meeha_latest_top_refresh', 'meeha_handle_latest_top_refresh' );
add_action( 'wp_ajax_nopriv_meeha_latest_top_refresh', 'meeha_handle_latest_top_refresh' );

// AJAX: load more cards below the initial top block, offset-based (site-wide, newest first)
function meeha_handle_latest_load_more() {
	check_ajax_referer( 'meeha_glance_nonce', 'nonce' );
	$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 13;

	$query = new WP_Query( array(
		'posts_per_page'      => 8,
		'offset'              => $offset,
		'ignore_sticky_posts' => true,
	) );

	ob_start();
	if ( $query->have_posts() ) {
		while ( $query->have_posts() ) {
			$query->the_post();
			?>
			<a href="<?php the_permalink(); ?>" class="meeha-card">
				<div class="meeha-card__image"><?php meeha_card_thumbnail( 'medium' ); ?></div>
				<h3 class="meeha-card__title"><?php the_title(); ?></h3>
				<span class="meeha-meta"><?php echo esc_html( meeha_bengali_time_ago( get_the_time( 'U' ) ) ); ?></span>
			</a>
			<?php
		}
	}
	$html = ob_get_clean();
	wp_reset_postdata();

	wp_send_json_success( array(
		'html'     => $html,
		'has_more' => $query->post_count === 8,
	) );
}
add_action( 'wp_ajax_meeha_latest_load_more', 'meeha_handle_latest_load_more' );
add_action( 'wp_ajax_nopriv_meeha_latest_load_more', 'meeha_handle_latest_load_more' );

// AJAX: switch the category-tab grid
function meeha_handle_latest_category_tab() {
	check_ajax_referer( 'meeha_glance_nonce', 'nonce' );
	$cat = isset( $_POST['category'] ) ? absint( $_POST['category'] ) : 0;
	if ( ! $cat ) {
		wp_send_json_error();
	}

	$query = new WP_Query( array(
		'cat'                 => $cat,
		'posts_per_page'      => 8,
		'ignore_sticky_posts' => true,
	) );

	ob_start();
	if ( $query->have_posts() ) {
		while ( $query->have_posts() ) {
			$query->the_post();
			?>
			<a href="<?php the_permalink(); ?>" class="meeha-card">
				<div class="meeha-card__image"><?php meeha_card_thumbnail( 'medium' ); ?></div>
				<h3 class="meeha-card__title"><?php the_title(); ?></h3>
				<span class="meeha-meta"><?php echo esc_html( meeha_bengali_time_ago( get_the_time( 'U' ) ) ); ?></span>
			</a>
			<?php
		}
	} else {
		echo '<p class="meeha-glance__empty">এই ক্যাটাগরিতে কোনো খবর নেই।</p>';
	}
	$html = ob_get_clean();
	wp_reset_postdata();

	wp_send_json_success( array( 'html' => $html ) );
}
add_action( 'wp_ajax_meeha_latest_category_tab', 'meeha_handle_latest_category_tab' );
add_action( 'wp_ajax_nopriv_meeha_latest_category_tab', 'meeha_handle_latest_category_tab' );

function meeha_maybe_enqueue_latest_assets() {
	global $post;
	if ( ! is_a( $post, 'WP_Post' ) || ! has_shortcode( $post->post_content, 'meeha_latest_feed' ) ) {
		return;
	}
	// reuse homepage design tokens (.meeha-hero, .meeha-grid, .meeha-card, .meeha-section)
	wp_enqueue_style(
		'meeha-google-fonts',
		'https://fonts.googleapis.com/css2?family=Baloo+Da+2:wght@500;600;700;800&family=Hind+Siliguri:wght@400;500;600;700&family=Inter:wght@400;500;600&display=swap',
		array(),
		null
	);
	wp_enqueue_style(
		'meeha-homepage-style',
		MEEHA_CHILD_URI . '/assets/css/meeha-homepage.css',
		array( 'meeha-child-style', 'meeha-google-fonts' ),
		MEEHA_CHILD_VERSION
	);
	wp_enqueue_style(
		'meeha-glance-style', // shared .meeha-glance__loading/__more/__empty styles
		MEEHA_CHILD_URI . '/assets/css/meeha-glance.css',
		array(),
		MEEHA_CHILD_VERSION
	);
	wp_enqueue_script(
		'meeha-latest-script',
		MEEHA_CHILD_URI . '/assets/js/meeha-latest.js',
		array(),
		MEEHA_CHILD_VERSION,
		true
	);
	wp_add_inline_script(
		'meeha-latest-script',
		'window.meehaGlanceAjax = { url: "' . esc_url( admin_url( 'admin-ajax.php' ) ) . '", nonce: "' . esc_js( wp_create_nonce( 'meeha_glance_nonce' ) ) . '" };',
		'before'
	);
}
add_action( 'wp_enqueue_scripts', 'meeha_maybe_enqueue_latest_assets' );

/**
 * Auto-registration system: readers can opt in (via comment form or poll
 * vote) to get a Meeha News account. Username/password are generated and
 * emailed automatically. Accounts are plain 'subscriber' role with zero
 * wp-admin access - front-end reading/commenting/voting only.
 */

function meeha_is_auto_registration_enabled() {
	$settings = get_option( 'meeha_registration_settings', array() );
	return ! isset( $settings['enabled'] ) || $settings['enabled'];
}

function meeha_get_registration_email_template() {
	$settings = get_option( 'meeha_registration_settings', array() );
	$default_subject = '{site_name}-এ আপনার একাউন্ট তৈরি হয়েছে';
	$default_body    = "প্রিয় {name},\n\nমন্তব্য/ভোট দেওয়ার সময় আপনি {site_name}-এ রেজিস্ট্রেশনের অনুরোধ করেছিলেন। আপনার একাউন্ট তৈরি হয়ে গেছে।\n\nইউজারনেম: {username}\nপাসওয়ার্ড: {password}\n\nলগইন করুন: {login_url}\n\nধন্যবাদ,\n{site_name}";
	return array(
		'subject' => ! empty( $settings['email_subject'] ) ? $settings['email_subject'] : $default_subject,
		'body'    => ! empty( $settings['email_body'] ) ? $settings['email_body'] : $default_body,
	);
}

/**
 * Creates a subscriber account for the given name/email if one doesn't
 * already exist, and emails the generated credentials using the admin's
 * editable template. Silently no-ops if disabled, invalid email, or the
 * email is already registered (never creates duplicates).
 */
function meeha_maybe_create_account( $name, $email, $phone = '' ) {
	if ( ! meeha_is_auto_registration_enabled() ) {
		return;
	}
	if ( ! is_email( $email ) || email_exists( $email ) ) {
		return;
	}

	// build a unique username from the email's local part
	$base_username = sanitize_user( current( explode( '@', $email ) ), true );
	if ( ! $base_username ) {
		$base_username = 'user';
	}
	$username = $base_username;
	$suffix   = 1;
	while ( username_exists( $username ) ) {
		$username = $base_username . $suffix;
		$suffix++;
	}

	$password = wp_generate_password( 12, false );
	$user_id  = wp_insert_user( array(
		'user_login' => $username,
		'user_pass'  => $password,
		'user_email' => $email,
		'display_name' => $name ? $name : $username,
		'role'       => 'subscriber',
	) );

	if ( is_wp_error( $user_id ) ) {
		return;
	}

	if ( $phone ) {
		update_user_meta( $user_id, 'meeha_phone', sanitize_text_field( $phone ) );
	}

	$template = meeha_get_registration_email_template();
	$tokens   = array(
		'{name}'      => $name ? $name : $username,
		'{username}'  => $username,
		'{password}'  => $password,
		'{site_name}' => get_bloginfo( 'name' ),
		'{login_url}' => wp_login_url(),
	);
	$subject = strtr( $template['subject'], $tokens );
	$body    = strtr( $template['body'], $tokens );

	wp_mail( $email, $subject, $body );
}

// Hook: comment form registration checkbox
function meeha_handle_comment_registration( $comment_id, $comment_approved, $commentdata ) {
	if ( empty( $_POST['meeha_register'] ) ) {
		return;
	}
	$name  = isset( $commentdata['comment_author'] ) ? sanitize_text_field( $commentdata['comment_author'] ) : '';
	$email = isset( $commentdata['comment_author_email'] ) ? sanitize_email( $commentdata['comment_author_email'] ) : '';
	$phone = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
	meeha_maybe_create_account( $name, $email, $phone );
}
add_action( 'comment_post', 'meeha_handle_comment_registration', 10, 3 );

/**
 * Hide the admin bar and block wp-admin access for subscriber-level accounts
 * created via auto-registration. They should only ever use the front end.
 */
// hide the admin bar on the front end for everyone except administrators
function meeha_restrict_subscriber_backend() {
	if ( current_user_can( 'manage_options' ) ) {
		return; // administrators keep the admin bar
	}
	add_filter( 'show_admin_bar', '__return_false' );
}
add_action( 'init', 'meeha_restrict_subscriber_backend' );

function meeha_block_subscriber_wp_admin() {
	if ( ! is_admin() || wp_doing_ajax() || ! is_user_logged_in() ) {
		return;
	}
	$user = wp_get_current_user();
	if ( in_array( 'subscriber', (array) $user->roles, true ) ) {
		wp_redirect( home_url( '/' ) );
		exit;
	}
}
add_action( 'admin_init', 'meeha_block_subscriber_wp_admin' );

/**
 * Registration settings admin page - enable toggle, editable email template,
 * and stub fields for future Google/Facebook OAuth login.
 */
function meeha_add_registration_settings_page() {
	add_submenu_page(
		'meeha-homepage-settings',
		'Meeha রেজিস্ট্রেশন সেটিংস',
		'রেজিস্ট্রেশন',
		'manage_options',
		'meeha-registration-settings',
		'meeha_render_registration_settings_page'
	);
}
add_action( 'admin_menu', 'meeha_add_registration_settings_page' );

function meeha_handle_registration_settings_save() {
	if ( ! isset( $_POST['meeha_registration_nonce'] ) ) {
		return;
	}
	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['meeha_registration_nonce'] ) ), 'meeha_save_registration_settings' ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$settings = array(
		'enabled'         => isset( $_POST['reg_enabled'] ) ? 1 : 0,
		'email_subject'   => isset( $_POST['email_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['email_subject'] ) ) : '',
		'email_body'      => isset( $_POST['email_body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['email_body'] ) ) : '',
		'google_client_id'     => isset( $_POST['google_client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['google_client_id'] ) ) : '',
		'google_client_secret' => isset( $_POST['google_client_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['google_client_secret'] ) ) : '',
		'facebook_app_id'      => isset( $_POST['facebook_app_id'] ) ? sanitize_text_field( wp_unslash( $_POST['facebook_app_id'] ) ) : '',
		'facebook_app_secret'  => isset( $_POST['facebook_app_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['facebook_app_secret'] ) ) : '',
		'mailpoet_list_id'     => isset( $_POST['mailpoet_list_id'] ) ? absint( $_POST['mailpoet_list_id'] ) : 0,
	);
	update_option( 'meeha_registration_settings', $settings );

	add_action( 'admin_notices', function () {
		echo '<div class="notice notice-success is-dismissible"><p>রেজিস্ট্রেশন সেটিংস সংরক্ষণ করা হয়েছে।</p></div>';
	} );
}
add_action( 'admin_init', 'meeha_handle_registration_settings_save' );

function meeha_render_registration_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$settings = get_option( 'meeha_registration_settings', array() );
	$template = meeha_get_registration_email_template();
	?>
	<div class="wrap">
		<h1>Meeha রেজিস্ট্রেশন সেটিংস</h1>
		<p>মন্তব্য বা ভোট দেওয়ার সময় পাঠক চাইলে চেকবক্স টিক দিয়ে সাথে সাথে একটা একাউন্ট পাবেন — ইউজারনেম/পাসওয়ার্ড অটোমেটিক তৈরি হয়ে ইমেইলে চলে যাবে। এই একাউন্টগুলো শুধু সাবস্ক্রাইবার লেভেলের, wp-admin ড্যাশবোর্ডে ঢুকতে পারবে না।</p>

		<form method="post">
			<?php wp_nonce_field( 'meeha_save_registration_settings', 'meeha_registration_nonce' ); ?>

			<table class="form-table">
				<tr>
					<th scope="row">চালু রাখুন</th>
					<td><label><input type="checkbox" name="reg_enabled" value="1" <?php checked( meeha_is_auto_registration_enabled() ); ?> /> কমেন্ট/ভোট ফর্মে রেজিস্ট্রেশন চেকবক্স দেখান</label></td>
				</tr>
				<tr>
					<th scope="row">ইমেইল সাবজেক্ট</th>
					<td><input type="text" name="email_subject" value="<?php echo esc_attr( $template['subject'] ); ?>" style="width:100%;max-width:500px;" /></td>
				</tr>
				<tr>
					<th scope="row">ইমেইল বডি</th>
					<td>
						<textarea name="email_body" rows="8" style="width:100%;max-width:500px;"><?php echo esc_textarea( $template['body'] ); ?></textarea>
						<p class="description">ব্যবহারযোগ্য টোকেন: <code>{name}</code> <code>{username}</code> <code>{password}</code> <code>{site_name}</code> <code>{login_url}</code></p>
					</td>
				</tr>
			</table>

			<h2 class="title">MailPoet ইন্টিগ্রেশন</h2>
			<p class="description">কমেন্ট, ভোট, বা রিপোর্ট ফর্মে যে ইমেইল দেওয়া হবে সেটা স্বয়ংক্রিয়ভাবে এই লিস্টে যোগ হবে (আলাদা একাউন্ট রেজিস্ট্রেশন থেকে সম্পূর্ণ স্বতন্ত্র)। List ID খুঁজে পেতে MailPoet → Lists পেজে গিয়ে লিস্টের ওপর মাউস রাখলে URL-এ id দেখা যাবে।</p>
			<table class="form-table">
				<tr>
					<th scope="row">MailPoet List ID</th>
					<td><input type="number" name="mailpoet_list_id" value="<?php echo esc_attr( isset( $settings['mailpoet_list_id'] ) ? $settings['mailpoet_list_id'] : '' ); ?>" style="width:100px;" placeholder="যেমন: 1" /></td>
				</tr>
			</table>

			<h2 class="title">Google / Facebook লগইন</h2>
			<p class="description">ক্রেডেনশিয়াল (ID ও Secret দুটোই) দিলে লগইন ও রেজিস্ট্রেশন পেজে সংশ্লিষ্ট বাটন নিজে থেকেই দেখাবে। কনসোলে যে "Authorized redirect URI" বসাতে হবে সেটা দেখুন: <a href="<?php echo esc_url( admin_url( 'admin.php?page=meeha-security-settings' ) ); ?>">নিরাপত্তা ও লগইন</a> পেজে।</p>
			<table class="form-table">
				<tr>
					<th scope="row">Google Client ID</th>
					<td><input type="text" name="google_client_id" value="<?php echo esc_attr( isset( $settings['google_client_id'] ) ? $settings['google_client_id'] : '' ); ?>" style="width:100%;max-width:500px;" /></td>
				</tr>
				<tr>
					<th scope="row">Google Client Secret</th>
					<td><input type="text" name="google_client_secret" value="<?php echo esc_attr( isset( $settings['google_client_secret'] ) ? $settings['google_client_secret'] : '' ); ?>" style="width:100%;max-width:500px;" /></td>
				</tr>
				<tr>
					<th scope="row">Facebook App ID</th>
					<td><input type="text" name="facebook_app_id" value="<?php echo esc_attr( isset( $settings['facebook_app_id'] ) ? $settings['facebook_app_id'] : '' ); ?>" style="width:100%;max-width:500px;" /></td>
				</tr>
				<tr>
					<th scope="row">Facebook App Secret</th>
					<td><input type="text" name="facebook_app_secret" value="<?php echo esc_attr( isset( $settings['facebook_app_secret'] ) ? $settings['facebook_app_secret'] : '' ); ?>" style="width:100%;max-width:500px;" /></td>
				</tr>
			</table>

			<?php submit_button( 'সেটিংস সংরক্ষণ করুন' ); ?>
		</form>
	</div>
	<?php
}

/**
 * MailPoet integration: adds an email to a configured MailPoet list via
 * their official public API (API::MP('v1')). Used from comment, poll vote,
 * and report forms so every reader interaction grows the newsletter list,
 * independent of the separate "create a site account" opt-in.
 */
function meeha_get_mailpoet_list_id() {
	$settings = get_option( 'meeha_registration_settings', array() );
	return isset( $settings['mailpoet_list_id'] ) ? absint( $settings['mailpoet_list_id'] ) : 0;
}

function meeha_add_to_mailpoet_list( $email, $name = '' ) {
	if ( ! is_email( $email ) || ! class_exists( '\MailPoet\API\API' ) ) {
		return;
	}
	$list_id = meeha_get_mailpoet_list_id();
	if ( ! $list_id ) {
		return;
	}
	try {
		$mailpoet = \MailPoet\API\API::MP( 'v1' );
		$name_parts = explode( ' ', trim( $name ), 2 );
		$mailpoet->addSubscriber(
			array(
				'email'      => $email,
				'first_name' => $name_parts[0] ?? '',
				'last_name'  => $name_parts[1] ?? '',
			),
			array( $list_id ),
			array( 'send_confirmation_email' => false )
		);
	} catch ( \Exception $e ) {
		// subscriber already exists, or MailPoet list/config issue - safe to ignore
	}
}

// wire comment form emails into the MailPoet list too (separate from account registration)
function meeha_add_commenter_to_mailpoet( $comment_id, $comment_approved, $commentdata ) {
	$email = isset( $commentdata['comment_author_email'] ) ? sanitize_email( $commentdata['comment_author_email'] ) : '';
	$name  = isset( $commentdata['comment_author'] ) ? sanitize_text_field( $commentdata['comment_author'] ) : '';
	meeha_add_to_mailpoet_list( $email, $name );
}
add_action( 'comment_post', 'meeha_add_commenter_to_mailpoet', 10, 3 );

/**
 * Report / fact-check system. Custom table keeps this separate from public
 * comments - reports are for editorial review, never shown on the site.
 */
function meeha_reports_table() {
	global $wpdb;
	return $wpdb->prefix . 'meeha_reports';
}

function meeha_maybe_create_reports_table() {
	if ( '1' === get_option( 'meeha_reports_table_v1' ) ) {
		return;
	}
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$table_name      = meeha_reports_table();
	$charset_collate = $wpdb->get_charset_collate();
	$sql = "CREATE TABLE $table_name (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		post_id BIGINT UNSIGNED NOT NULL,
		report_type VARCHAR(40) NOT NULL,
		reporter_name VARCHAR(191) NOT NULL,
		reporter_phone VARCHAR(32) NULL,
		reporter_email VARCHAR(191) NULL,
		message TEXT NULL,
		evidence_url VARCHAR(500) NULL,
		created_at DATETIME NOT NULL,
		PRIMARY KEY (id),
		KEY post_id (post_id)
	) $charset_collate;";
	dbDelta( $sql );
	update_option( 'meeha_reports_table_v1', '1' );
}
add_action( 'init', 'meeha_maybe_create_reports_table' );

function meeha_get_report_types() {
	return array(
		'verify'    => 'সত্যতা যাচাই অনুরোধ',
		'false'     => 'অসত্য নিউজ',
		'partial'   => 'আংশিক সত্য',
		'sensitive' => 'সত্য কিন্তু সামাজিক সেন্সিটিভ',
		'opinion'   => 'মতামত আছে',
	);
}

// report button + modal form, placed near the share buttons on single posts
function meeha_render_report_button( $post_id ) {
	$types = meeha_get_report_types();
	?>
	<button type="button" class="meeha-report-btn" data-post-id="<?php echo esc_attr( $post_id ); ?>">
		<span aria-hidden="true">🚩</span> রিপোর্ট করুন
	</button>
	<template id="meeha-report-form-template">
		<form class="meeha-report-form" id="meeha-report-form">
			<label>রিপোর্টের ধরন
				<select name="report_type" required>
					<option value="">বাছাই করুন</option>
					<?php foreach ( $types as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<input type="text" name="name" placeholder="আপনার নাম" required />
			<input type="tel" name="phone" placeholder="ফোন নাম্বার" required />
			<input type="email" name="email" id="meeha-report-email" placeholder="ইমেইল" />
			<textarea name="message" placeholder="আপনার বক্তব্য" rows="4" required></textarea>
			<label class="meeha-report-form__file-label">প্রমাণ (ছবি, ঐচ্ছিক)
				<input type="file" name="evidence" accept="image/*" />
			</label>
			<?php if ( ! is_user_logged_in() && meeha_is_auto_registration_enabled() ) : ?>
				<label class="meeha-poll__register-label">
					<input type="checkbox" name="meeha_register" value="1" onchange="document.getElementById('meeha-report-email').required = this.checked;" />
					আপনি কি মিহা নিউজে রেজিস্ট্রেশন করতে চান? (একাউন্টের তথ্য ইমেইলে পাঠানো হবে)
				</label>
			<?php endif; ?>
			<button type="submit" class="meeha-poll__submit">রিপোর্ট জমা দিন</button>
			<p class="meeha-poll__msg" id="meeha-report-msg" role="status"></p>
		</form>
	</template>
	<?php
}

function meeha_handle_report_submit() {
	check_ajax_referer( 'meeha_poll_nonce', 'nonce' );

	$post_id     = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
	$report_type = isset( $_POST['report_type'] ) ? sanitize_text_field( wp_unslash( $_POST['report_type'] ) ) : '';
	$name        = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
	$phone       = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
	$email       = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
	$message     = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';

	if ( ! $post_id || ! array_key_exists( $report_type, meeha_get_report_types() ) || strlen( $name ) < 2 || strlen( $message ) < 5 ) {
		wp_send_json_error( array( 'message' => 'সব প্রয়োজনীয় তথ্য সঠিকভাবে দিন।' ) );
	}

	$evidence_url = '';
	if ( ! empty( $_FILES['evidence']['name'] ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		$uploaded = wp_handle_upload( $_FILES['evidence'], array( 'test_form' => false ) );
		if ( ! empty( $uploaded['url'] ) ) {
			$evidence_url = $uploaded['url'];
		}
	}

	global $wpdb;
	$wpdb->insert( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		meeha_reports_table(),
		array(
			'post_id'        => $post_id,
			'report_type'    => $report_type,
			'reporter_name'  => $name,
			'reporter_phone' => $phone,
			'reporter_email' => $email ? $email : null,
			'message'        => $message,
			'evidence_url'   => $evidence_url,
			'created_at'     => current_time( 'mysql' ),
		),
		array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
	);

	// notify admin immediately
	$types = meeha_get_report_types();
	$admin_email = get_option( 'admin_email' );
	$subject = 'নতুন রিপোর্ট: ' . ( $types[ $report_type ] ?? $report_type );
	$body    = "পোস্ট: " . get_permalink( $post_id ) . "\nধরন: " . ( $types[ $report_type ] ?? $report_type ) .
		"\nনাম: $name\nফোন: $phone\nইমেইল: $email\nবক্তব্য: $message" .
		( $evidence_url ? "\nপ্রমাণ: $evidence_url" : '' );
	wp_mail( $admin_email, $subject, $body );

	if ( $email ) {
		meeha_add_to_mailpoet_list( $email, $name );
	}
	if ( ! empty( $_POST['meeha_register'] ) ) {
		meeha_maybe_create_account( $name, $email, $phone );
	}

	wp_send_json_success( array( 'message' => 'আপনার রিপোর্ট জমা হয়েছে, ধন্যবাদ।' ) );
}
add_action( 'wp_ajax_meeha_report_submit', 'meeha_handle_report_submit' );
add_action( 'wp_ajax_nopriv_meeha_report_submit', 'meeha_handle_report_submit' );

// simple admin page to review submitted reports
function meeha_add_reports_admin_page() {
	add_submenu_page(
		'meeha-homepage-settings',
		'Meeha রিপোর্ট',
		'রিপোর্ট',
		'manage_options',
		'meeha-reports',
		'meeha_render_reports_admin_page'
	);
}
add_action( 'admin_menu', 'meeha_add_reports_admin_page' );

function meeha_render_reports_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	global $wpdb;
	$table = meeha_reports_table();
	$rows  = $wpdb->get_results( "SELECT * FROM $table ORDER BY created_at DESC LIMIT 100" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$types = meeha_get_report_types();
	?>
	<div class="wrap">
		<h1>পাঠকদের রিপোর্ট</h1>
		<table class="widefat striped">
			<thead>
				<tr>
					<th>তারিখ</th>
					<th>পোস্ট</th>
					<th>ধরন</th>
					<th>নাম</th>
					<th>ফোন</th>
					<th>ইমেইল</th>
					<th>বক্তব্য</th>
					<th>প্রমাণ</th>
				</tr>
			</thead>
			<tbody>
				<?php if ( $rows ) : foreach ( $rows as $r ) : ?>
					<tr>
						<td><?php echo esc_html( $r->created_at ); ?></td>
						<td><a href="<?php echo esc_url( get_permalink( $r->post_id ) ); ?>" target="_blank"><?php echo esc_html( get_the_title( $r->post_id ) ); ?></a></td>
						<td><?php echo esc_html( $types[ $r->report_type ] ?? $r->report_type ); ?></td>
						<td><?php echo esc_html( $r->reporter_name ); ?></td>
						<td><?php echo esc_html( $r->reporter_phone ); ?></td>
						<td><?php echo esc_html( $r->reporter_email ); ?></td>
						<td><?php echo esc_html( wp_trim_words( $r->message, 20 ) ); ?></td>
						<td><?php if ( $r->evidence_url ) : ?><a href="<?php echo esc_url( $r->evidence_url ); ?>" target="_blank">দেখুন</a><?php endif; ?></td>
					</tr>
				<?php endforeach; else : ?>
					<tr><td colspan="8">এখনো কোনো রিপোর্ট জমা পড়েনি।</td></tr>
				<?php endif; ?>
			</tbody>
		</table>
	</div>
	<?php
}

/**
 * Sends generic wp_login_url() calls (comment forms, admin bar "Log in",
 * etc.) to our own custom-designed login page instead of wp-login.php.
 *
 * Previously this also force-rewrote the /meeha-login/ URL itself to load
 * wp-login.php directly ('^meeha-login/?$' -> wp-login.php) - that was from
 * before the real [meeha_login] page existed at that same slug, and it was
 * hijacking every request to /meeha-login/ before WordPress ever got a
 * chance to serve the actual page, permanently showing the default WP login
 * screen instead of our design. That rewrite rule has been removed.
 */
function meeha_custom_login_url( $login_url, $redirect, $force_reauth ) {
	$custom = meeha_get_account_page_url( 'login' );
	if ( $redirect ) {
		$custom = add_query_arg( 'redirect_to', urlencode( $redirect ), $custom );
	}
	if ( $force_reauth ) {
		$custom = add_query_arg( 'reauth', '1', $custom );
	}
	return $custom;
}
add_filter( 'login_url', 'meeha_custom_login_url', 10, 3 );

/**
 * One-time flush: the old rewrite rule above was saved into the DB's
 * cached rewrite_rules option, so removing the add_rewrite_rule() call
 * alone doesn't clear it - WordPress needs an explicit flush to regenerate
 * that option without the stale entry. Runs once (version-guarded) rather
 * than on every request, since flushing is relatively expensive.
 */
function meeha_flush_login_rewrite_once() {
	if ( '1' !== get_option( 'meeha_login_rewrite_flushed' ) ) {
		flush_rewrite_rules();
		update_option( 'meeha_login_rewrite_flushed', '1' );
	}
}
add_action( 'init', 'meeha_flush_login_rewrite_once', 20 );

// swap the WordPress logo on the login page for the site's own logo
function meeha_login_logo_css() {
	$branding = meeha_get_photocard_branding();
	if ( ! $branding['logo_url'] ) {
		return;
	}
	?>
	<style>
		#login h1 a, .login h1 a {
			background-image: url('<?php echo esc_url( $branding['logo_url'] ); ?>');
			background-size: contain;
			background-repeat: no-repeat;
			width: 260px;
			height: 80px;
		}
	</style>
	<?php
}
add_action( 'login_enqueue_scripts', 'meeha_login_logo_css' );

function meeha_login_logo_url() {
	return home_url( '/' );
}
add_filter( 'login_headerurl', 'meeha_login_logo_url' );

function meeha_login_logo_title() {
	return get_bloginfo( 'name' );
}
add_filter( 'login_headertext', 'meeha_login_logo_title' );

// flush rewrite rules once after the new rule is registered
function meeha_maybe_flush_rewrites() {
	if ( '1' !== get_option( 'meeha_rewrites_flushed_v1' ) ) {
		flush_rewrite_rules();
		update_option( 'meeha_rewrites_flushed_v1', '1' );
	}
}
add_action( 'init', 'meeha_maybe_flush_rewrites', 20 );

/**
 * Emoji reaction system - shown at the very end of the post for an
 * emotional touch. One choice per visitor (tracked client-side via
 * localStorage), switchable, counts stored as post meta.
 */
function meeha_get_reaction_types() {
	return array(
		'like'  => '👍',
		'love'  => '❤️',
		'haha'  => '😆',
		'wow'   => '😮',
		'sad'   => '😢',
		'angry' => '😡',
	);
}

function meeha_get_reaction_counts( $post_id ) {
	$counts   = get_post_meta( $post_id, '_meeha_reactions', true );
	$defaults = array_fill_keys( array_keys( meeha_get_reaction_types() ), 0 );
	return is_array( $counts ) ? array_merge( $defaults, $counts ) : $defaults;
}

function meeha_render_reactions( $post_id ) {
	$types  = meeha_get_reaction_types();
	$counts = meeha_get_reaction_counts( $post_id );
	?>
	<div class="meeha-reactions" data-post-id="<?php echo esc_attr( $post_id ); ?>">
		<p class="meeha-reactions__title">পোস্টটি কেমন লাগলো?</p>
		<div class="meeha-reactions__row">
			<?php foreach ( $types as $key => $emoji ) : ?>
				<button type="button" class="meeha-reactions__btn" data-reaction="<?php echo esc_attr( $key ); ?>">
					<span class="meeha-reactions__emoji"><?php echo esc_html( $emoji ); ?></span>
					<span class="meeha-reactions__count" data-reaction-count="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $counts[ $key ] ); ?></span>
				</button>
			<?php endforeach; ?>
		</div>
	</div>
	<?php
}

function meeha_handle_reaction() {
	check_ajax_referer( 'meeha_poll_nonce', 'nonce' );
	$post_id  = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
	$type     = isset( $_POST['reaction'] ) ? sanitize_key( wp_unslash( $_POST['reaction'] ) ) : '';
	$previous = isset( $_POST['previous'] ) ? sanitize_key( wp_unslash( $_POST['previous'] ) ) : '';
	$types    = meeha_get_reaction_types();

	if ( ! $post_id || ! array_key_exists( $type, $types ) ) {
		wp_send_json_error();
	}

	$counts = meeha_get_reaction_counts( $post_id );
	if ( $previous && array_key_exists( $previous, $types ) && $previous !== $type && $counts[ $previous ] > 0 ) {
		$counts[ $previous ]--; // switching reaction - remove the old one
	}
	if ( $previous !== $type ) {
		$counts[ $type ]++;
	}
	update_post_meta( $post_id, '_meeha_reactions', $counts );

	wp_send_json_success( array( 'counts' => $counts ) );
}
add_action( 'wp_ajax_meeha_reaction', 'meeha_handle_reaction' );
add_action( 'wp_ajax_nopriv_meeha_reaction', 'meeha_handle_reaction' );

/**
 * Front-end account pages: [meeha_login], [meeha_register],
 * [meeha_my_account], [meeha_lost_password]. Admin places each shortcode
 * on its own page. All submit via AJAX for a smooth, no-reload experience;
 * login/register set the WP auth cookie directly from the AJAX response.
 */

function meeha_account_asset_enqueue() {
	global $post;
	if ( ! is_a( $post, 'WP_Post' ) ) {
		return;
	}
	$shortcodes = array( 'meeha_login', 'meeha_register', 'meeha_my_account', 'meeha_lost_password' );
	$has_any = false;
	foreach ( $shortcodes as $sc ) {
		if ( has_shortcode( $post->post_content, $sc ) ) {
			$has_any = true;
			break;
		}
	}
	if ( ! $has_any ) {
		return;
	}
	wp_enqueue_style(
		'meeha-account-style',
		MEEHA_CHILD_URI . '/assets/css/meeha-account.css',
		array(),
		MEEHA_CHILD_VERSION
	);
	wp_enqueue_script(
		'meeha-account-script',
		MEEHA_CHILD_URI . '/assets/js/meeha-account.js',
		array(),
		MEEHA_CHILD_VERSION,
		true
	);
	wp_add_inline_script(
		'meeha-account-script',
		'window.meehaAccountAjax = { url: "' . esc_url( admin_url( 'admin-ajax.php' ) ) . '", nonce: "' . esc_js( wp_create_nonce( 'meeha_account_nonce' ) ) . '", homeUrl: "' . esc_url( home_url( '/' ) ) . '" };',
		'before'
	);
}
add_action( 'wp_enqueue_scripts', 'meeha_account_asset_enqueue' );

function meeha_login_shortcode() {
	if ( is_user_logged_in() ) {
		return '<p class="meeha-account-msg">আপনি ইতিমধ্যে লগইন করা আছেন। <a href="' . esc_url( home_url( '/' ) ) . '">হোমে ফিরুন</a></p>';
	}
	ob_start();
	?>
	<?php echo meeha_render_social_buttons(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
	<form class="meeha-account-form" id="meeha-login-form">
		<h2>লগইন করুন</h2>
		<input type="text" name="username" placeholder="ইউজারনেম অথবা ইমেইল" required />
		<input type="password" name="password" placeholder="পাসওয়ার্ড" required />
		<button type="submit" class="meeha-account-form__submit">লগইন করুন</button>
		<p class="meeha-account-msg" id="meeha-login-msg" role="status"></p>
		<p class="meeha-account-links">
			<a href="<?php echo esc_url( meeha_get_account_page_url( 'lost-password' ) ); ?>">পাসওয়ার্ড ভুলে গেছেন?</a>
			· <a href="<?php echo esc_url( meeha_get_account_page_url( 'register' ) ); ?>">নতুন একাউন্ট খুলুন</a>
		</p>
	</form>

	<form class="meeha-account-form" id="meeha-otp-form" hidden>
		<h2>ওটিপি যাচাই</h2>
		<p class="meeha-account-form__hint" id="meeha-otp-hint"></p>
		<input type="hidden" name="token" value="" />
		<input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="৬ সংখ্যার ওটিপি" required />
		<button type="submit" class="meeha-account-form__submit">যাচাই করে লগইন করুন</button>
		<p class="meeha-account-msg" id="meeha-otp-msg" role="status"></p>
		<p class="meeha-account-links"><a href="#" id="meeha-otp-resend">ওটিপি আবার পাঠান</a></p>
	</form>
	<?php
	return ob_get_clean();
}
add_shortcode( 'meeha_login', 'meeha_login_shortcode' );

function meeha_register_shortcode() {
	if ( is_user_logged_in() ) {
		return '<p class="meeha-account-msg">আপনি ইতিমধ্যে লগইন করা আছেন। <a href="' . esc_url( home_url( '/' ) ) . '">হোমে ফিরুন</a></p>';
	}
	ob_start();
	?>
	<?php echo meeha_render_social_buttons(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
	<form class="meeha-account-form" id="meeha-register-form">
		<h2>নতুন একাউন্ট</h2>
		<input type="text" name="name" placeholder="আপনার নাম" required />
		<input type="email" name="email" placeholder="ইমেইল" required />
		<input type="tel" name="phone" placeholder="ফোন নাম্বার (ঐচ্ছিক)" />
		<input type="password" name="password" placeholder="পাসওয়ার্ড" required minlength="6" />
		<button type="submit" class="meeha-account-form__submit">একাউন্ট খুলুন</button>
		<p class="meeha-account-msg" id="meeha-register-msg" role="status"></p>
		<p class="meeha-account-links">
			<a href="<?php echo esc_url( meeha_get_account_page_url( 'login' ) ); ?>">আগে থেকেই একাউন্ট আছে? লগইন করুন</a>
		</p>
	</form>
	<?php
	return ob_get_clean();
}
add_shortcode( 'meeha_register', 'meeha_register_shortcode' );

function meeha_lost_password_shortcode() {
	ob_start();
	?>
	<form class="meeha-account-form" id="meeha-lost-password-form">
		<h2>পাসওয়ার্ড রিসেট</h2>
		<p class="meeha-account-form__hint">আপনার ইমেইল দিন, পাসওয়ার্ড রিসেট করার লিংক পাঠানো হবে।</p>
		<input type="email" name="email" placeholder="ইমেইল" required />
		<button type="submit" class="meeha-account-form__submit">রিসেট লিংক পাঠান</button>
		<p class="meeha-account-msg" id="meeha-lost-password-msg" role="status"></p>
	</form>
	<?php
	return ob_get_clean();
}
add_shortcode( 'meeha_lost_password', 'meeha_lost_password_shortcode' );

function meeha_my_account_shortcode() {
	if ( ! is_user_logged_in() ) {
		return '<p class="meeha-account-msg">দেখতে হলে আগে <a href="' . esc_url( meeha_get_account_page_url( 'login' ) ) . '">লগইন করুন</a> অথবা <a href="' . esc_url( meeha_get_account_page_url( 'register' ) ) . '">নতুন একাউন্ট খুলুন</a>।</p>';
	}
	$user  = wp_get_current_user();
	$phone = get_user_meta( $user->ID, 'meeha_phone', true );

	// Always show the tab: meeha_render_correspondent_dashboard() itself
	// handles every state (never applied / pending / rejected / approved),
	// including the "apply here" prompt for users who haven't applied yet.
	$correspondent_sub_tabs = array( 'new', 'all', 'published', 'pending', 'requests' );
	$requested_tab          = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	$active_tab             = in_array( $requested_tab, $correspondent_sub_tabs, true ) ? 'correspondent' : 'profile';

	ob_start();
	?>
	<div class="meeha-account-page">
		<h2>আমার একাউন্ট</h2>

		<div class="meeha-account-tabs" role="tablist">
			<button type="button" class="meeha-account-tab<?php echo 'profile' === $active_tab ? ' is-active' : ''; ?>" data-account-tab="profile">প্রোফাইল</button>
			<button type="button" class="meeha-account-tab<?php echo 'correspondent' === $active_tab ? ' is-active' : ''; ?>" data-account-tab="correspondent">সংবাদদাতা</button>
			<button type="button" class="meeha-account-tab<?php echo 'security' === $active_tab ? ' is-active' : ''; ?>" data-account-tab="security">নিরাপত্তা</button>
		</div>

		<div class="meeha-account-tab-panel" data-account-panel="profile" <?php echo 'profile' === $active_tab ? '' : 'hidden'; ?>>
			<div class="meeha-account-avatar-block">
				<img src="<?php echo esc_url( get_avatar_url( $user->ID, array( 'size' => 96 ) ) ); ?>" alt="" class="meeha-account-avatar" id="meeha-account-avatar-preview" />
				<form id="meeha-avatar-form">
					<label class="meeha-account-avatar-upload">
						ছবি বদলান
						<input type="file" name="avatar" accept="image/*" id="meeha-avatar-input" hidden />
					</label>
					<p class="meeha-account-msg" id="meeha-avatar-msg" role="status"></p>
				</form>
			</div>

			<form class="meeha-account-form" id="meeha-profile-form">
				<label>নাম<input type="text" name="name" value="<?php echo esc_attr( $user->display_name ); ?>" /></label>
				<label>ইমেইল<input type="email" value="<?php echo esc_attr( $user->user_email ); ?>" disabled /></label>
				<label>ফোন নাম্বার<input type="tel" name="phone" value="<?php echo esc_attr( $phone ); ?>" /></label>
				<label>এরিয়া
					<?php
					meeha_render_area_fields(
						get_user_meta( $user->ID, 'meeha_division', true ),
						get_user_meta( $user->ID, 'meeha_district', true ),
						get_user_meta( $user->ID, 'meeha_upazila', true ),
						'meeha_user'
					);
					?>
				</label>
				<?php if ( function_exists( 'meeha_2fa_required_for_user' ) && meeha_2fa_required_for_user( $user ) ) : ?>
				<label>লগইন ওটিপি কোন মাধ্যমে পেতে চান
					<select name="meeha_2fa_method">
						<option value="email" <?php selected( get_user_meta( $user->ID, 'meeha_2fa_method', true ), 'email' ); ?>>ইমেইল</option>
						<?php if ( meeha_sms_is_ready() ) : ?>
						<option value="sms" <?php selected( get_user_meta( $user->ID, 'meeha_2fa_method', true ), 'sms' ); ?>>মোবাইল SMS (ফোন নাম্বার দেওয়া থাকতে হবে)</option>
						<?php endif; ?>
					</select>
				</label>
				<?php endif; ?>
				<label class="meeha-account-checkbox-label">
					<input type="checkbox" name="show_area" value="1" <?php checked( get_user_meta( $user->ID, 'meeha_show_area', true ) ); ?> />
					আমি আমার এরিয়া দেখাতে চাই (কমেন্টে নামের পাশে দেখাবে)
				</label>
				<button type="submit" class="meeha-account-form__submit">সংরক্ষণ করুন</button>
				<p class="meeha-account-msg" id="meeha-profile-msg" role="status"></p>
			</form>
			<?php meeha_area_cascade_script(); ?>
		</div>

		<div class="meeha-account-tab-panel" data-account-panel="correspondent" <?php echo 'correspondent' === $active_tab ? '' : 'hidden'; ?>>
			<?php meeha_render_correspondent_dashboard( $user ); ?>
		</div>

		<div class="meeha-account-tab-panel" data-account-panel="security" <?php echo 'security' === $active_tab ? '' : 'hidden'; ?>>
			<form class="meeha-account-form" id="meeha-password-form">
				<h3>পাসওয়ার্ড বদলান</h3>
				<input type="password" name="current_password" placeholder="বর্তমান পাসওয়ার্ড" required />
				<input type="password" name="new_password" placeholder="নতুন পাসওয়ার্ড" required minlength="6" />
				<button type="submit" class="meeha-account-form__submit">পাসওয়ার্ড বদলান</button>
				<p class="meeha-account-msg" id="meeha-password-msg" role="status"></p>
			</form>
		</div>

		<a href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>" class="meeha-account-logout">লগআউট</a>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'meeha_my_account', 'meeha_my_account_shortcode' );

/**
 * Finds which page has a given account shortcode, so links between
 * login/register/lost-password stay correct without hardcoding slugs.
 */
function meeha_get_account_page_url( $which ) {
	$map = array(
		'login'         => 'meeha_login',
		'register'      => 'meeha_register',
		'my-account'    => 'meeha_my_account',
		'lost-password' => 'meeha_lost_password',
	);
	if ( ! isset( $map[ $which ] ) ) {
		return home_url( '/' );
	}
	$cached = get_transient( 'meeha_account_page_' . $which );
	if ( false !== $cached ) {
		return $cached;
	}
	$url = home_url( '/' );
	$all_pages = get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'posts_per_page' => -1 ) );
	foreach ( $all_pages as $p ) {
		if ( has_shortcode( $p->post_content, $map[ $which ] ) ) {
			$url = get_permalink( $p );
			break;
		}
	}
	set_transient( 'meeha_account_page_' . $which, $url, HOUR_IN_SECONDS );
	return $url;
}

// AJAX: login - এখন inc/security.php-তে (লগইন লক + ওটিপি ধাপসহ)

// AJAX: self-service registration with a user-chosen password
function meeha_handle_ajax_register() {
	check_ajax_referer( 'meeha_account_nonce', 'nonce' );
	$name     = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
	$email    = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
	$phone    = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
	$password = isset( $_POST['password'] ) ? $_POST['password'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

	if ( ! is_email( $email ) || strlen( $password ) < 6 || strlen( $name ) < 2 ) {
		wp_send_json_error( array( 'message' => 'সব তথ্য সঠিকভাবে পূরণ করুন (পাসওয়ার্ড কমপক্ষে ৬ অক্ষর)।' ) );
	}
	if ( email_exists( $email ) ) {
		wp_send_json_error( array( 'message' => 'এই ইমেইল দিয়ে আগেই একাউন্ট আছে।' ) );
	}

	$base_username = sanitize_user( current( explode( '@', $email ) ), true );
	if ( ! $base_username ) {
		$base_username = 'user';
	}
	$username = $base_username;
	$suffix   = 1;
	while ( username_exists( $username ) ) {
		$username = $base_username . $suffix;
		$suffix++;
	}

	$user_id = wp_insert_user( array(
		'user_login'   => $username,
		'user_pass'    => $password,
		'user_email'   => $email,
		'display_name' => $name,
		'role'         => 'subscriber',
	) );
	if ( is_wp_error( $user_id ) ) {
		wp_send_json_error( array( 'message' => 'একাউন্ট তৈরি করা যায়নি।' ) );
	}
	if ( $phone ) {
		update_user_meta( $user_id, 'meeha_phone', $phone );
	}
	meeha_add_to_mailpoet_list( $email, $name );

	wp_set_current_user( $user_id );
	wp_set_auth_cookie( $user_id, true );

	wp_send_json_success( array( 'message' => 'একাউন্ট তৈরি হয়েছে!', 'redirect' => home_url( '/' ) ) );
}
add_action( 'wp_ajax_nopriv_meeha_ajax_register', 'meeha_handle_ajax_register' );

// AJAX: lost password - uses WP core's own reset-token flow (secure, not reinvented)
function meeha_handle_ajax_lost_password() {
	check_ajax_referer( 'meeha_account_nonce', 'nonce' );
	$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
	if ( ! is_email( $email ) || ! email_exists( $email ) ) {
		// same message regardless, so this can't be used to check which emails are registered
		wp_send_json_success( array( 'message' => 'এই ইমেইলে একাউন্ট থাকলে রিসেট লিংক পাঠানো হয়েছে।' ) );
	}
	retrieve_password( $email );
	wp_send_json_success( array( 'message' => 'এই ইমেইলে একাউন্ট থাকলে রিসেট লিংক পাঠানো হয়েছে।' ) );
}
add_action( 'wp_ajax_nopriv_meeha_ajax_lost_password', 'meeha_handle_ajax_lost_password' );

// AJAX: update name/phone from My Account
function meeha_handle_ajax_profile_update() {
	check_ajax_referer( 'meeha_account_nonce', 'nonce' );
	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => 'লগইন করুন।' ) );
	}
	$user_id = get_current_user_id();
	$name    = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
	$phone   = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';

	if ( $name ) {
		wp_update_user( array( 'ID' => $user_id, 'display_name' => $name ) );
	}
	update_user_meta( $user_id, 'meeha_phone', $phone );
	if ( isset( $_POST['meeha_2fa_method'] ) ) {
		$m2 = sanitize_key( wp_unslash( $_POST['meeha_2fa_method'] ) );
		if ( in_array( $m2, array( 'email', 'sms' ), true ) ) {
			update_user_meta( $user_id, 'meeha_2fa_method', $m2 );
		}
	}

	foreach ( array( 'division', 'district', 'upazila' ) as $field ) {
		if ( isset( $_POST[ 'meeha_user_' . $field ] ) ) {
			update_user_meta( $user_id, 'meeha_' . $field, sanitize_text_field( wp_unslash( $_POST[ 'meeha_user_' . $field ] ) ) );
		}
	}
	update_user_meta( $user_id, 'meeha_show_area', isset( $_POST['show_area'] ) ? 1 : 0 );

	wp_send_json_success( array( 'message' => 'প্রোফাইল আপডেট হয়েছে।' ) );
}
add_action( 'wp_ajax_meeha_ajax_profile_update', 'meeha_handle_ajax_profile_update' );

// AJAX: change password from My Account
function meeha_handle_ajax_password_change() {
	check_ajax_referer( 'meeha_account_nonce', 'nonce' );
	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => 'লগইন করুন।' ) );
	}
	$user = wp_get_current_user();
	$current  = isset( $_POST['current_password'] ) ? $_POST['current_password'] : ''; // phpcs:ignore
	$new_pass = isset( $_POST['new_password'] ) ? $_POST['new_password'] : ''; // phpcs:ignore

	if ( ! wp_check_password( $current, $user->user_pass, $user->ID ) ) {
		wp_send_json_error( array( 'message' => 'বর্তমান পাসওয়ার্ড ভুল।' ) );
	}
	if ( strlen( $new_pass ) < 6 ) {
		wp_send_json_error( array( 'message' => 'নতুন পাসওয়ার্ড কমপক্ষে ৬ অক্ষরের হতে হবে।' ) );
	}
	wp_set_password( $new_pass, $user->ID );
	wp_send_json_success( array( 'message' => 'পাসওয়ার্ড বদলানো হয়েছে। আবার লগইন করুন।', 'redirect' => meeha_get_account_page_url( 'login' ) ) );
}
add_action( 'wp_ajax_meeha_ajax_password_change', 'meeha_handle_ajax_password_change' );

// AJAX: avatar upload - stored as a user meta URL, used by the get_avatar filter below
function meeha_handle_ajax_avatar_upload() {
	check_ajax_referer( 'meeha_account_nonce', 'nonce' );
	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => 'লগইন করুন।' ) );
	}
	if ( empty( $_FILES['avatar']['name'] ) ) {
		wp_send_json_error( array( 'message' => 'ছবি বাছাই করুন।' ) );
	}
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';

	$uploaded = wp_handle_upload( $_FILES['avatar'], array( 'test_form' => false ) );
	if ( empty( $uploaded['url'] ) ) {
		wp_send_json_error( array( 'message' => 'আপলোড ব্যর্থ হয়েছে।' ) );
	}
	update_user_meta( get_current_user_id(), 'meeha_avatar', $uploaded['url'] );
	wp_send_json_success( array( 'message' => 'ছবি আপডেট হয়েছে।', 'url' => $uploaded['url'] ) );
}
add_action( 'wp_ajax_meeha_ajax_avatar_upload', 'meeha_handle_ajax_avatar_upload' );

// use the custom avatar (My Account upload) in place of Gravatar wherever WordPress shows an avatar, including comments
function meeha_custom_avatar_url( $url, $id_or_email ) {
	$user_id = 0;
	if ( is_numeric( $id_or_email ) ) {
		$user_id = (int) $id_or_email;
	} elseif ( is_object( $id_or_email ) && ! empty( $id_or_email->user_id ) ) {
		$user_id = (int) $id_or_email->user_id; // comment object
	} elseif ( is_string( $id_or_email ) ) {
		$user = get_user_by( 'email', $id_or_email );
		if ( $user ) {
			$user_id = $user->ID;
		}
	}
	if ( $user_id ) {
		$custom = get_user_meta( $user_id, 'meeha_avatar', true );
		if ( $custom ) {
			return $custom;
		}
	}
	return $url;
}
add_filter( 'get_avatar_url', 'meeha_custom_avatar_url', 10, 2 );

/**
 * Author designation field (e.g. "নিজস্ব প্রতিবেদক", "জেলা প্রতিনিধি"),
 * editable on each user's profile, shown next to their name in the byline.
 */
function meeha_add_author_title_field( $user ) {
	?>
	<h2>Meeha প্রতিবেদক তথ্য</h2>
	<table class="form-table">
		<tr>
			<th><label for="meeha_author_title">পদবি</label></th>
			<td>
				<input type="text" name="meeha_author_title" id="meeha_author_title" value="<?php echo esc_attr( get_user_meta( $user->ID, 'meeha_author_title', true ) ); ?>" class="regular-text" placeholder="যেমন: নিজস্ব প্রতিবেদক, জেলা প্রতিনিধি" />
				<p class="description">আর্টিকেলের বাইলাইনে নামের পাশে এটা দেখাবে।</p>
			</td>
		</tr>
	</table>
	<?php
}
add_action( 'show_user_profile', 'meeha_add_author_title_field' );
add_action( 'edit_user_profile', 'meeha_add_author_title_field' );

function meeha_save_author_title_field( $user_id ) {
	if ( ! current_user_can( 'edit_user', $user_id ) ) {
		return;
	}
	if ( isset( $_POST['meeha_author_title'] ) ) {
		update_user_meta( $user_id, 'meeha_author_title', sanitize_text_field( wp_unslash( $_POST['meeha_author_title'] ) ) );
	}
}
add_action( 'personal_options_update', 'meeha_save_author_title_field' );
add_action( 'edit_user_profile_update', 'meeha_save_author_title_field' );

/**
 * Area-based news system (এরিয়া ভিত্তিক খবর) - Bangladesh Division/District
 * data below is fully structured; Upazila/Thana (~500 entries) is a free-text
 * field instead of a hardcoded dropdown, to avoid an error-prone manual list.
 * Used on: post meta box, user profile (journalist beat + subscriber opt-in),
 * a sidebar area-filter widget, and optionally shown next to commenters.
 */
function meeha_get_bd_divisions() {
	return array(
		'ঢাকা'        => array( 'ঢাকা', 'গাজীপুর', 'নারায়ণগঞ্জ', 'টাঙ্গাইল', 'কিশোরগঞ্জ', 'মানিকগঞ্জ', 'মুন্সিগঞ্জ', 'নরসিংদী', 'রাজবাড়ী', 'ফরিদপুর', 'গোপালগঞ্জ', 'মাদারীপুর', 'শরীয়তপুর' ),
		'চট্টগ্রাম'    => array( 'চট্টগ্রাম', 'কক্সবাজার', 'রাঙ্গামাটি', 'বান্দরবান', 'খাগড়াছড়ি', 'ফেনী', 'নোয়াখালী', 'লক্ষ্মীপুর', 'কুমিল্লা', 'চাঁদপুর', 'ব্রাহ্মণবাড়িয়া' ),
		'রাজশাহী'      => array( 'রাজশাহী', 'নাটোর', 'নওগাঁ', 'চাঁপাইনবাবগঞ্জ', 'পাবনা', 'সিরাজগঞ্জ', 'বগুড়া', 'জয়পুরহাট' ),
		'খুলনা'        => array( 'খুলনা', 'বাগেরহাট', 'সাতক্ষীরা', 'যশোর', 'ঝিনাইদহ', 'মাগুরা', 'নড়াইল', 'কুষ্টিয়া', 'চুয়াডাঙ্গা', 'মেহেরপুর' ),
		'বরিশাল'       => array( 'বরিশাল', 'পটুয়াখালী', 'ভোলা', 'পিরোজপুর', 'বরগুনা', 'ঝালকাঠি' ),
		'সিলেট'        => array( 'সিলেট', 'মৌলভীবাজার', 'হবিগঞ্জ', 'সুনামগঞ্জ' ),
		'রংপুর'        => array( 'রংপুর', 'দিনাজপুর', 'ঠাকুরগাঁও', 'পঞ্চগড়', 'নীলফামারী', 'লালমনিরহাট', 'কুড়িগ্রাম', 'গাইবান্ধা' ),
		'ময়মনসিংহ'    => array( 'ময়মনসিংহ', 'জামালপুর', 'শেরপুর', 'নেত্রকোণা' ),
	);
}

function meeha_render_area_fields( $division, $district, $upazila, $prefix = 'meeha' ) {
	$divisions = meeha_get_bd_divisions();
	$upazilas  = $district ? meeha_get_upazilas_for_district( $district ) : array();
	?>
	<select name="<?php echo esc_attr( $prefix ); ?>_division" class="meeha-area-division" data-target="<?php echo esc_attr( $prefix ); ?>_district">
		<option value="">বিভাগ বাছাই করুন</option>
		<?php foreach ( array_keys( $divisions ) as $div ) : ?>
			<option value="<?php echo esc_attr( $div ); ?>" <?php selected( $division, $div ); ?>><?php echo esc_html( $div ); ?></option>
		<?php endforeach; ?>
	</select>
	<select name="<?php echo esc_attr( $prefix ); ?>_district" id="<?php echo esc_attr( $prefix ); ?>_district" class="meeha-area-district" data-selected="<?php echo esc_attr( $district ); ?>" data-target="<?php echo esc_attr( $prefix ); ?>_upazila">
		<option value="">জেলা বাছাই করুন</option>
		<?php if ( $division && isset( $divisions[ $division ] ) ) : ?>
			<?php foreach ( $divisions[ $division ] as $dist ) : ?>
				<option value="<?php echo esc_attr( $dist ); ?>" <?php selected( $district, $dist ); ?>><?php echo esc_html( $dist ); ?></option>
			<?php endforeach; ?>
		<?php endif; ?>
	</select>
	<select name="<?php echo esc_attr( $prefix ); ?>_upazila" id="<?php echo esc_attr( $prefix ); ?>_upazila" class="meeha-area-upazila" data-selected="<?php echo esc_attr( $upazila ); ?>">
		<option value="">থানা/উপজেলা বাছাই করুন</option>
		<?php foreach ( $upazilas as $upz ) : ?>
			<option value="<?php echo esc_attr( $upz ); ?>" <?php selected( $upazila, $upz ); ?>><?php echo esc_html( $upz ); ?></option>
		<?php endforeach; ?>
	</select>
	<?php
}

function meeha_area_cascade_script() {
	$divisions = meeha_get_bd_divisions();

	// resolve upazila lists (admin overrides applied) for every district, keyed by district name
	$upazila_map = array();
	foreach ( $divisions as $districts ) {
		foreach ( $districts as $dist ) {
			$upazila_map[ $dist ] = meeha_get_upazilas_for_district( $dist );
		}
	}
	?>
	<script>
	( function () {
		var areaData    = <?php echo wp_json_encode( $divisions ); ?>;
		var upazilaData = <?php echo wp_json_encode( $upazila_map ); ?>;

		document.addEventListener( 'change', function ( e ) {
			if ( e.target.classList.contains( 'meeha-area-division' ) ) {
				var districtTargetId = e.target.getAttribute( 'data-target' );
				var districtSelect   = document.getElementById( districtTargetId );
				if ( ! districtSelect ) {
					return;
				}
				var districts = areaData[ e.target.value ] || [];
				districtSelect.innerHTML = '<option value="">জেলা বাছাই করুন</option>';
				districts.forEach( function ( d ) {
					var opt = document.createElement( 'option' );
					opt.value = d;
					opt.textContent = d;
					districtSelect.appendChild( opt );
				} );
				// division বদলালে উপজেলা ড্রপডাউনও খালি করে দেওয়া হচ্ছে
				var upazilaSelect = document.getElementById( districtSelect.getAttribute( 'data-target' ) );
				if ( upazilaSelect ) {
					upazilaSelect.innerHTML = '<option value="">থানা/উপজেলা বাছাই করুন</option>';
				}
				return;
			}

			if ( e.target.classList.contains( 'meeha-area-district' ) ) {
				var upazilaTargetId = e.target.getAttribute( 'data-target' );
				var upazilaSel      = document.getElementById( upazilaTargetId );
				if ( ! upazilaSel ) {
					return;
				}
				var upazilas = upazilaData[ e.target.value ] || [];
				upazilaSel.innerHTML = '<option value="">থানা/উপজেলা বাছাই করুন</option>';
				upazilas.forEach( function ( u ) {
					var opt = document.createElement( 'option' );
					opt.value = u;
					opt.textContent = u;
					upazilaSel.appendChild( opt );
				} );
			}
		} );
	} )();
	</script>
	<?php
}

// post meta box: pick this article's area
function meeha_add_area_meta_box() {
	add_meta_box( 'meeha_area_box', 'এরিয়া (অঞ্চল)', 'meeha_render_area_meta_box', 'post', 'side' );
}
add_action( 'add_meta_boxes', 'meeha_add_area_meta_box' );

function meeha_render_area_meta_box( $post ) {
	wp_nonce_field( 'meeha_save_area', 'meeha_area_nonce' );
	$division = get_post_meta( $post->ID, '_meeha_division', true );
	$district = get_post_meta( $post->ID, '_meeha_district', true );
	$upazila  = get_post_meta( $post->ID, '_meeha_upazila', true );
	meeha_render_area_fields( $division, $district, $upazila, 'meeha_post' );
	meeha_area_cascade_script();
}

function meeha_save_area_meta( $post_id ) {
	if ( ! isset( $_POST['meeha_area_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['meeha_area_nonce'] ) ), 'meeha_save_area' ) ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	foreach ( array( 'division', 'district', 'upazila' ) as $field ) {
		if ( isset( $_POST[ 'meeha_post_' . $field ] ) ) {
			update_post_meta( $post_id, '_meeha_' . $field, sanitize_text_field( wp_unslash( $_POST[ 'meeha_post_' . $field ] ) ) );
		}
	}
}
add_action( 'save_post_post', 'meeha_save_area_meta' );

// user profile: journalist's beat area + subscriber opt-in to show their area on comments
function meeha_add_user_area_field( $user ) {
	$division = get_user_meta( $user->ID, 'meeha_division', true );
	$district = get_user_meta( $user->ID, 'meeha_district', true );
	$upazila  = get_user_meta( $user->ID, 'meeha_upazila', true );
	?>
	<h2>Meeha এরিয়া</h2>
	<table class="form-table">
		<tr>
			<th>এরিয়া</th>
			<td><?php meeha_render_area_fields( $division, $district, $upazila, 'meeha_user' ); ?></td>
		</tr>
		<tr>
			<th>দেখানো</th>
			<td>
				<label><input type="checkbox" name="meeha_show_area" value="1" <?php checked( get_user_meta( $user->ID, 'meeha_show_area', true ) ); ?> /> আমি আমার এরিয়া দেখাতে চাই (কমেন্টে নামের পাশে দেখাবে)</label>
			</td>
		</tr>
	</table>
	<?php meeha_area_cascade_script(); ?>
	<?php
}
add_action( 'show_user_profile', 'meeha_add_user_area_field' );
add_action( 'edit_user_profile', 'meeha_add_user_area_field' );

function meeha_save_user_area_field( $user_id ) {
	if ( ! current_user_can( 'edit_user', $user_id ) ) {
		return;
	}
	foreach ( array( 'division', 'district', 'upazila' ) as $field ) {
		if ( isset( $_POST[ 'meeha_user_' . $field ] ) ) {
			update_user_meta( $user_id, 'meeha_' . $field, sanitize_text_field( wp_unslash( $_POST[ 'meeha_user_' . $field ] ) ) );
		}
	}
	update_user_meta( $user_id, 'meeha_show_area', isset( $_POST['meeha_show_area'] ) ? 1 : 0 );
}
add_action( 'personal_options_update', 'meeha_save_user_area_field' );
add_action( 'edit_user_profile_update', 'meeha_save_user_area_field' );

// sidebar widget: cascading area filter with inline AJAX results
function meeha_render_area_sidebar() {
	?>
	<div class="meeha-sidebar__block meeha-area-widget">
		<div class="meeha-section__head">
			<span class="meeha-eyebrow-bar"></span>
			<h2>এরিয়া ভিত্তিক খবর</h2>
		</div>
		<?php meeha_render_area_fields( '', '', '', 'meeha_filter' ); ?>
		<button type="button" class="meeha-area-widget__search" id="meeha-area-search-btn">খুঁজুন</button>
		<div class="meeha-area-widget__results" id="meeha-area-results"></div>
	</div>
	<?php meeha_area_cascade_script(); ?>
	<?php
}

function meeha_handle_area_filter() {
	check_ajax_referer( 'meeha_poll_nonce', 'nonce' );
	$division = isset( $_POST['division'] ) ? sanitize_text_field( wp_unslash( $_POST['division'] ) ) : '';
	$district = isset( $_POST['district'] ) ? sanitize_text_field( wp_unslash( $_POST['district'] ) ) : '';
	$upazila  = isset( $_POST['upazila'] ) ? sanitize_text_field( wp_unslash( $_POST['upazila'] ) ) : '';

	$meta_query = array( 'relation' => 'AND' );
	if ( $division ) {
		$meta_query[] = array( 'key' => '_meeha_division', 'value' => $division );
	}
	if ( $district ) {
		$meta_query[] = array( 'key' => '_meeha_district', 'value' => $district );
	}
	if ( $upazila ) {
		$meta_query[] = array( 'key' => '_meeha_upazila', 'value' => $upazila, 'compare' => 'LIKE' );
	}
	if ( count( $meta_query ) === 1 ) {
		wp_send_json_success( array( 'html' => '<p class="meeha-glance__empty">অন্তত একটা এরিয়া বাছাই করুন।</p>' ) );
	}

	$query = new WP_Query( array(
		'post_type'      => 'post',
		'post_status'    => 'publish',
		'posts_per_page' => 10,
		'meta_query'     => $meta_query,
		'no_found_rows'  => true,
	) );

	ob_start();
	if ( $query->have_posts() ) {
		echo '<ul class="meeha-area-widget__list">';
		while ( $query->have_posts() ) {
			$query->the_post();
			echo '<li><a href="' . esc_url( get_permalink() ) . '">' . esc_html( get_the_title() ) . '</a></li>';
		}
		echo '</ul>';
	} else {
		echo '<p class="meeha-glance__empty">এই এরিয়ায় কোনো খবর পাওয়া যায়নি।</p>';
	}
	$html = ob_get_clean();
	wp_reset_postdata();

	wp_send_json_success( array( 'html' => $html ) );
}
add_action( 'wp_ajax_meeha_area_filter', 'meeha_handle_area_filter' );
add_action( 'wp_ajax_nopriv_meeha_area_filter', 'meeha_handle_area_filter' );

// show a commenter's area next to their name, only if they opted in via My Account
function meeha_show_commenter_area( $author, $comment_id, $comment ) {
	$comment = get_comment( $comment_id );
	if ( ! $comment || ! $comment->user_id ) {
		return $author;
	}
	if ( ! get_user_meta( $comment->user_id, 'meeha_show_area', true ) ) {
		return $author;
	}
	$division = get_user_meta( $comment->user_id, 'meeha_division', true );
	$district = get_user_meta( $comment->user_id, 'meeha_district', true );
	$area = trim( $district . ( $district && $division ? ', ' : '' ) . $division );
	if ( $area ) {
		$author .= ' <span class="meeha-comment-area">(' . esc_html( $area ) . ')</span>';
	}
	return $author;
}
add_filter( 'get_comment_author', 'meeha_show_commenter_area', 10, 3 );

/**
 * Default upazila/thana list per district (best-effort from general
 * knowledge - admin should review/correct via the override settings page,
 * since exact completeness for ~500 entries can't be fully guaranteed).
 * Admin overrides (comma-separated, per district) always take priority.
 */
function meeha_get_default_upazilas() {
	return array(
		// ঢাকা বিভাগ
		'ঢাকা'          => array( 'ধামরাই', 'দোহার', 'কেরানীগঞ্জ', 'নবাবগঞ্জ', 'সাভার' ),
		'গাজীপুর'       => array( 'গাজীপুর সদর', 'কালিয়াকৈর', 'কালীগঞ্জ', 'কাপাসিয়া', 'শ্রীপুর' ),
		'নারায়ণগঞ্জ'   => array( 'নারায়ণগঞ্জ সদর', 'আড়াইহাজার', 'বন্দর', 'রূপগঞ্জ', 'সোনারগাঁও' ),
		'টাঙ্গাইল'      => array( 'টাঙ্গাইল সদর', 'বাসাইল', 'ভূয়াপুর', 'দেলদুয়ার', 'ঘাটাইল', 'গোপালপুর', 'কালিহাতী', 'মধুপুর', 'মির্জাপুর', 'নাগরপুর', 'সখীপুর', 'ধনবাড়ী' ),
		'কিশোরগঞ্জ'    => array( 'কিশোরগঞ্জ সদর', 'অষ্টগ্রাম', 'বাজিতপুর', 'ভৈরব', 'হোসেনপুর', 'ইটনা', 'করিমগঞ্জ', 'কটিয়াদী', 'কুলিয়ারচর', 'মিঠামইন', 'নিকলী', 'পাকুন্দিয়া', 'তাড়াইল' ),
		'মানিকগঞ্জ'    => array( 'মানিকগঞ্জ সদর', 'দৌলতপুর', 'ঘিওর', 'হরিরামপুর', 'সাটুরিয়া', 'শিবালয়', 'সিংগাইর' ),
		'মুন্সিগঞ্জ'    => array( 'মুন্সিগঞ্জ সদর', 'গজারিয়া', 'লৌহজং', 'শ্রীনগর', 'সিরাজদিখান', 'টঙ্গিবাড়ী' ),
		'নরসিংদী'      => array( 'নরসিংদী সদর', 'বেলাবো', 'মনোহরদী', 'পলাশ', 'রায়পুরা', 'শিবপুর' ),
		'রাজবাড়ী'      => array( 'রাজবাড়ী সদর', 'বালিয়াকান্দি', 'গোয়ালন্দ', 'পাংশা', 'কালুখালী' ),
		'ফরিদপুর'      => array( 'ফরিদপুর সদর', 'আলফাডাঙ্গা', 'বোয়ালমারী', 'ভাঙ্গা', 'চরভদ্রাসন', 'মধুখালী', 'নগরকান্দা', 'সদরপুর', 'সালথা' ),
		'গোপালগঞ্জ'    => array( 'গোপালগঞ্জ সদর', 'কাশিয়ানী', 'কোটালীপাড়া', 'মুকসুদপুর', 'টুংগীপাড়া' ),
		'মাদারীপুর'    => array( 'মাদারীপুর সদর', 'কালকিনি', 'ডাসার', 'রাজৈর', 'শিবচর' ),
		'শরীয়তপুর'    => array( 'শরীয়তপুর সদর', 'ভেদরগঞ্জ', 'ডামুড্যা', 'গোসাইরহাট', 'নড়িয়া', 'জাজিরা' ),

		// চট্টগ্রাম বিভাগ
		'চট্টগ্রাম'     => array( 'চট্টগ্রাম সদর', 'আনোয়ারা', 'বাঁশখালী', 'বোয়ালখালী', 'চন্দনাইশ', 'ফটিকছড়ি', 'হাটহাজারী', 'লোহাগাড়া', 'মিরসরাই', 'পটিয়া', 'রাঙ্গুনিয়া', 'রাউজান', 'সাতকানিয়া', 'সীতাকুণ্ড', 'সন্দ্বীপ' ),
		'কক্সবাজার'     => array( 'কক্সবাজার সদর', 'চকরিয়া', 'কুতুবদিয়া', 'মহেশখালী', 'পেকুয়া', 'রামু', 'টেকনাফ', 'উখিয়া' ),
		'রাঙ্গামাটি'    => array( 'রাঙ্গামাটি সদর', 'বাঘাইছড়ি', 'বরকল', 'বিলাইছড়ি', 'জুরাছড়ি', 'কাউখালী', 'কাপ্তাই', 'লংগদু', 'নানিয়ারচর', 'রাজস্থলী' ),
		'বান্দরবান'     => array( 'বান্দরবান সদর', 'আলীকদম', 'লামা', 'নাইক্ষ্যংছড়ি', 'রুমা', 'রোয়াংছড়ি', 'থানচি' ),
		'খাগড়াছড়ি'    => array( 'খাগড়াছড়ি সদর', 'দীঘিনালা', 'লক্ষ্মীছড়ি', 'মহালছড়ি', 'মানিকছড়ি', 'মাটিরাঙ্গা', 'পানছড়ি', 'রামগড়', 'গুইমারা' ),
		'ফেনী'          => array( 'ফেনী সদর', 'ছাগলনাইয়া', 'দাগনভূঞা', 'ফুলগাজী', 'পরশুরাম', 'সোনাগাজী' ),
		'নোয়াখালী'     => array( 'নোয়াখালী সদর', 'বেগমগঞ্জ', 'চাটখিল', 'কোম্পানীগঞ্জ', 'হাতিয়া', 'কবিরহাট', 'সেনবাগ', 'সুবর্ণচর', 'সোনাইমুড়ি' ),
		'লক্ষ্মীপুর'    => array( 'লক্ষ্মীপুর সদর', 'কমলনগর', 'রায়পুর', 'রামগঞ্জ', 'রামগতি' ),
		'কুমিল্লা'      => array( 'কুমিল্লা সদর', 'বরুড়া', 'ব্রাহ্মণপাড়া', 'চান্দিনা', 'চৌদ্দগ্রাম', 'দাউদকান্দি', 'দেবিদ্বার', 'হোমনা', 'লাকসাম', 'মেঘনা', 'মনোহরগঞ্জ', 'মুরাদনগর', 'নাঙ্গলকোট', 'তিতাস', 'বুড়িচং', 'লালমাই' ),
		'চাঁদপুর'       => array( 'চাঁদপুর সদর', 'ফরিদগঞ্জ', 'হাইমচর', 'হাজীগঞ্জ', 'কচুয়া', 'মতলব উত্তর', 'মতলব দক্ষিণ', 'শাহরাস্তি' ),
		'ব্রাহ্মণবাড়িয়া' => array( 'ব্রাহ্মণবাড়িয়া সদর', 'আশুগঞ্জ', 'বাঞ্ছারামপুর', 'বিজয়নগর', 'কসবা', 'নবীনগর', 'নাসিরনগর', 'সরাইল', 'আখাউড়া' ),

		// রাজশাহী বিভাগ
		'রাজশাহী'       => array( 'রাজশাহী সদর', 'বাগমারা', 'বাঘা', 'চারঘাট', 'দুর্গাপুর', 'গোদাগাড়ী', 'মোহনপুর', 'পবা', 'পুঠিয়া', 'তানোর' ),
		'নাটোর'         => array( 'নাটোর সদর', 'বাগাতিপাড়া', 'বড়াইগ্রাম', 'গুরুদাসপুর', 'লালপুর', 'নলডাঙ্গা', 'সিংড়া' ),
		'নওগাঁ'         => array( 'নওগাঁ সদর', 'আত্রাই', 'বদলগাছী', 'ধামইরহাট', 'মহাদেবপুর', 'নিয়ামতপুর', 'মান্দা', 'পত্নীতলা', 'পোরশা', 'রানীনগর', 'সাপাহার' ),
		'চাঁপাইনবাবগঞ্জ' => array( 'চাঁপাইনবাবগঞ্জ সদর', 'ভোলাহাট', 'গোমস্তাপুর', 'নাচোল', 'শিবগঞ্জ' ),
		'পাবনা'         => array( 'পাবনা সদর', 'আটঘরিয়া', 'বেড়া', 'ভাঙ্গুড়া', 'চাটমোহর', 'ফরিদপুর', 'ঈশ্বরদী', 'সাঁথিয়া', 'সুজানগর' ),
		'সিরাজগঞ্জ'    => array( 'সিরাজগঞ্জ সদর', 'বেলকুচি', 'চৌহালী', 'কামারখন্দ', 'কাজিপুর', 'রায়গঞ্জ', 'শাহজাদপুর', 'তাড়াশ', 'উল্লাপাড়া' ),
		'বগুড়া'        => array( 'বগুড়া সদর', 'আদমদীঘি', 'বগুড়া সদর', 'দুপচাঁচিয়া', 'গাবতলী', 'কাহালু', 'নন্দীগ্রাম', 'সারিয়াকান্দি', 'শাজাহানপুর', 'শিবগঞ্জ', 'শেরপুর', 'ধুনট', 'সোনাতলা' ),
		'জয়পুরহাট'     => array( 'জয়পুরহাট সদর', 'আক্কেলপুর', 'কালাই', 'ক্ষেতলাল', 'পাঁচবিবি' ),

		// খুলনা বিভাগ
		'খুলনা'         => array( 'খুলনা সদর', 'বটিয়াঘাটা', 'দাকোপ', 'দিঘলিয়া', 'ডুমুরিয়া', 'কয়রা', 'পাইকগাছা', 'ফুলতলা', 'রূপসা', 'তেরখাদা' ),
		'বাগেরহাট'      => array( 'বাগেরহাট সদর', 'চিতলমারী', 'ফকিরহাট', 'কচুয়া', 'মংলা', 'মোরেলগঞ্জ', 'রামপাল', 'শরণখোলা' ),
		'সাতক্ষীরা'     => array( 'সাতক্ষীরা সদর', 'আশাশুনি', 'দেবহাটা', 'কালিগঞ্জ', 'কলারোয়া', 'শ্যামনগর', 'তালা' ),
		'যশোর'          => array( 'যশোর সদর', 'অভয়নগর', 'বাঘারপাড়া', 'চৌগাছা', 'ঝিকরগাছা', 'কেশবপুর', 'মণিরামপুর', 'শার্শা' ),
		'ঝিনাইদহ'       => array( 'ঝিনাইদহ সদর', 'হরিণাকুণ্ডু', 'কালীগঞ্জ', 'কোটচাঁদপুর', 'মহেশপুর', 'শৈলকুপা' ),
		'মাগুরা'        => array( 'মাগুরা সদর', 'মহম্মদপুর', 'শালিখা', 'শ্রীপুর' ),
		'নড়াইল'        => array( 'নড়াইল সদর', 'কালিয়া', 'লোহাগড়া' ),
		'কুষ্টিয়া'      => array( 'কুষ্টিয়া সদর', 'ভেড়ামারা', 'দৌলতপুর', 'খোকসা', 'কুমারখালী', 'মিরপুর' ),
		'চুয়াডাঙ্গা'    => array( 'চুয়াডাঙ্গা সদর', 'আলমডাঙ্গা', 'দামুড়হুদা', 'জীবননগর' ),
		'মেহেরপুর'      => array( 'মেহেরপুর সদর', 'গাংনী', 'মুজিবনগর' ),

		// বরিশাল বিভাগ
		'বরিশাল'        => array( 'বরিশাল সদর', 'আগৈলঝাড়া', 'বাবুগঞ্জ', 'বাকেরগঞ্জ', 'বানারীপাড়া', 'গৌরনদী', 'হিজলা', 'মেহেন্দিগঞ্জ', 'মুলাদী', 'উজিরপুর' ),
		'পটুয়াখালী'    => array( 'পটুয়াখালী সদর', 'বাউফল', 'দশমিনা', 'গলাচিপা', 'কলাপাড়া', 'মির্জাগঞ্জ', 'দুমকি', 'রাঙ্গাবালী' ),
		'ভোলা'          => array( 'ভোলা সদর', 'বোরহানউদ্দিন', 'চরফ্যাশন', 'দৌলতখান', 'লালমোহন', 'মনপুরা', 'তজুমদ্দিন' ),
		'পিরোজপুর'      => array( 'পিরোজপুর সদর', 'ভান্ডারিয়া', 'কাউখালী', 'মঠবাড়িয়া', 'নাজিরপুর', 'নেসারাবাদ', 'জিয়ানগর' ),
		'বরগুনা'        => array( 'বরগুনা সদর', 'আমতলী', 'বামনা', 'বেতাগী', 'পাথরঘাটা', 'তালতলী' ),
		'ঝালকাঠি'       => array( 'ঝালকাঠি সদর', 'কাঁঠালিয়া', 'নলছিটি', 'রাজাপুর' ),

		// সিলেট বিভাগ
		'সিলেট'         => array( 'সিলেট সদর', 'বালাগঞ্জ', 'বিয়ানীবাজার', 'বিশ্বনাথ', 'কোম্পানীগঞ্জ', 'ফেঞ্চুগঞ্জ', 'গোলাপগঞ্জ', 'গোয়াইনঘাট', 'জৈন্তাপুর', 'কানাইঘাট', 'জকিগঞ্জ', 'ওসমানীনগর', 'দক্ষিণ সুরমা' ),
		'মৌলভীবাজার'   => array( 'মৌলভীবাজার সদর', 'বড়লেখা', 'কমলগঞ্জ', 'কুলাউড়া', 'জুড়ী', 'রাজনগর', 'শ্রীমঙ্গল' ),
		'হবিগঞ্জ'      => array( 'হবিগঞ্জ সদর', 'আজমিরীগঞ্জ', 'বাহুবল', 'বানিয়াচং', 'চুনারুঘাট', 'লাখাই', 'মাধবপুর', 'নবীগঞ্জ', 'শায়েস্তাগঞ্জ' ),
		'সুনামগঞ্জ'    => array( 'সুনামগঞ্জ সদর', 'বিশ্বম্ভরপুর', 'ছাতক', 'দক্ষিণ সুনামগঞ্জ', 'দিরাই', 'দোয়ারাবাজার', 'জগন্নাথপুর', 'জামালগঞ্জ', 'শাল্লা', 'তাহিরপুর' ),

		// রংপুর বিভাগ
		'রংপুর'         => array( 'রংপুর সদর', 'বদরগঞ্জ', 'গঙ্গাচড়া', 'কাউনিয়া', 'মিঠাপুকুর', 'পীরগাছা', 'পীরগঞ্জ', 'তারাগঞ্জ' ),
		'দিনাজপুর'      => array( 'দিনাজপুর সদর', 'বিরল', 'বিরামপুর', 'বিরগঞ্জ', 'বীরগঞ্জ', 'বোচাগঞ্জ', 'চিরিরবন্দর', 'ফুলবাড়ী', 'ঘোড়াঘাট', 'হাকিমপুর', 'কাহারোল', 'খানসামা', 'নবাবগঞ্জ', 'পার্বতীপুর' ),
		'ঠাকুরগাঁও'    => array( 'ঠাকুরগাঁও সদর', 'বালিয়াডাঙ্গী', 'হরিপুর', 'পীরগঞ্জ', 'রানীশংকৈল' ),
		'পঞ্চগড়'      => array( 'পঞ্চগড় সদর', 'আটোয়ারী', 'বোদা', 'দেবীগঞ্জ', 'তেঁতুলিয়া' ),
		'নীলফামারী'    => array( 'নীলফামারী সদর', 'ডিমলা', 'ডোমার', 'জলঢাকা', 'কিশোরগঞ্জ', 'সৈয়দপুর' ),
		'লালমনিরহাট'   => array( 'লালমনিরহাট সদর', 'আদিতমারী', 'কালীগঞ্জ', 'হাতীবান্ধা', 'পাটগ্রাম' ),
		'কুড়িগ্রাম'    => array( 'কুড়িগ্রাম সদর', 'ভুরুঙ্গামারী', 'চর রাজিবপুর', 'চিলমারী', 'ফুলবাড়ী', 'নাগেশ্বরী', 'রাজারহাট', 'রৌমারী', 'উলিপুর' ),
		'গাইবান্ধা'    => array( 'গাইবান্ধা সদর', 'ফুলছড়ি', 'গোবিন্দগঞ্জ', 'পলাশবাড়ী', 'সাদুল্লাপুর', 'সাঘাটা', 'সুন্দরগঞ্জ' ),

		// ময়মনসিংহ বিভাগ
		'ময়মনসিংহ'    => array( 'ময়মনসিংহ সদর', 'ভালুকা', 'ফুলবাড়িয়া', 'গফরগাঁও', 'গৌরীপুর', 'হালুয়াঘাট', 'ঈশ্বরগঞ্জ', 'মুক্তাগাছা', 'নান্দাইল', 'তারাকান্দা', 'ত্রিশাল', 'ফুলপুর', 'ধোবাউড়া' ),
		'জামালপুর'     => array( 'জামালপুর সদর', 'বকশীগঞ্জ', 'দেওয়ানগঞ্জ', 'ইসলামপুর', 'মাদারগঞ্জ', 'মেলান্দহ', 'সরিষাবাড়ী' ),
		'শেরপুর'       => array( 'শেরপুর সদর', 'নালিতাবাড়ী', 'নকলা', 'শ্রীবরদী', 'ঝিনাইগাতী' ),
		'নেত্রকোণা'    => array( 'নেত্রকোণা সদর', 'আটপাড়া', 'বারহাট্টা', 'দুর্গাপুর', 'কলমাকান্দা', 'কেন্দুয়া', 'মদন', 'মোহনগঞ্জ', 'পূর্বধলা', 'খালিয়াজুরী' ),
	);
}

function meeha_get_upazilas_for_district( $district ) {
	$overrides = get_option( 'meeha_upazila_overrides', array() );
	if ( ! empty( $overrides[ $district ] ) ) {
		return array_filter( array_map( 'trim', explode( ',', $overrides[ $district ] ) ) );
	}
	$defaults = meeha_get_default_upazilas();
	return isset( $defaults[ $district ] ) ? $defaults[ $district ] : array();
}

// admin page: pick a district, review/paste-override its comma-separated upazila list
function meeha_add_upazila_settings_page() {
	add_submenu_page(
		'meeha-homepage-settings',
		'Meeha উপজেলা সেটিংস',
		'উপজেলা',
		'manage_options',
		'meeha-upazila-settings',
		'meeha_render_upazila_settings_page'
	);
}
add_action( 'admin_menu', 'meeha_add_upazila_settings_page' );

function meeha_handle_upazila_settings_save() {
	if ( ! isset( $_POST['meeha_upazila_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['meeha_upazila_nonce'] ) ), 'meeha_save_upazila' ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$district = isset( $_POST['upazila_district'] ) ? sanitize_text_field( wp_unslash( $_POST['upazila_district'] ) ) : '';
	$list     = isset( $_POST['upazila_list'] ) ? sanitize_textarea_field( wp_unslash( $_POST['upazila_list'] ) ) : '';
	if ( ! $district ) {
		return;
	}
	$overrides = get_option( 'meeha_upazila_overrides', array() );
	$overrides[ $district ] = $list;
	update_option( 'meeha_upazila_overrides', $overrides );

	add_action( 'admin_notices', function () use ( $district ) {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $district ) . '-এর উপজেলা তালিকা সংরক্ষণ করা হয়েছে।</p></div>';
	} );
}
add_action( 'admin_init', 'meeha_handle_upazila_settings_save' );

function meeha_render_upazila_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$divisions = meeha_get_bd_divisions();
	$selected_district = isset( $_GET['district'] ) ? sanitize_text_field( wp_unslash( $_GET['district'] ) ) : '';
	$current_list = $selected_district ? implode( ', ', meeha_get_upazilas_for_district( $selected_district ) ) : '';
	?>
	<div class="wrap">
		<h1>উপজেলা সেটিংস</h1>
		<p>প্রতিটা জেলার উপজেলা/থানার তালিকা এখান থেকে ঠিক করে দিতে পারবেন। ডিফল্ট তালিকা (সবচেয়ে পরিচিত অনুযায়ী দেওয়া) ভুল/অসম্পূর্ণ থাকলে এখানে কমা দিয়ে সঠিক তালিকা লিখে সংরক্ষণ করুন — এটাই তখন থেকে ব্যবহৃত হবে।</p>

		<form method="get">
			<input type="hidden" name="page" value="meeha-upazila-settings" />
			<select name="district" onchange="this.form.submit()">
				<option value="">জেলা বাছাই করুন</option>
				<?php foreach ( $divisions as $div => $districts ) : ?>
					<optgroup label="<?php echo esc_attr( $div ); ?>">
						<?php foreach ( $districts as $dist ) : ?>
							<option value="<?php echo esc_attr( $dist ); ?>" <?php selected( $selected_district, $dist ); ?>><?php echo esc_html( $dist ); ?></option>
						<?php endforeach; ?>
					</optgroup>
				<?php endforeach; ?>
			</select>
		</form>

		<?php if ( $selected_district ) : ?>
			<form method="post" style="margin-top:16px;max-width:600px;">
				<?php wp_nonce_field( 'meeha_save_upazila', 'meeha_upazila_nonce' ); ?>
				<input type="hidden" name="upazila_district" value="<?php echo esc_attr( $selected_district ); ?>" />
				<p><strong><?php echo esc_html( $selected_district ); ?></strong>-এর উপজেলা তালিকা (কমা দিয়ে আলাদা করুন):</p>
				<textarea name="upazila_list" rows="6" style="width:100%;"><?php echo esc_textarea( $current_list ); ?></textarea>
				<?php submit_button( 'সংরক্ষণ করুন' ); ?>
			</form>
		<?php endif; ?>
	</div>
	<?php
}

// AJAX: category page "আরও পড়ুন" - offset-based load more
function meeha_handle_category_load_more() {
	check_ajax_referer( 'meeha_poll_nonce', 'nonce' );
	$cat_id = isset( $_POST['category'] ) ? absint( $_POST['category'] ) : 0;
	$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;

	if ( ! $cat_id ) {
		wp_send_json_error();
	}

	$query = new WP_Query( array(
		'cat'                 => $cat_id,
		'posts_per_page'      => 10,
		'offset'              => $offset,
		'ignore_sticky_posts' => true,
	) );

	ob_start();
	if ( $query->have_posts() ) {
		while ( $query->have_posts() ) {
			$query->the_post();
			?>
			<a href="<?php the_permalink(); ?>" class="meeha-card">
				<div class="meeha-card__image"><?php meeha_card_thumbnail( 'medium' ); ?></div>
				<h3 class="meeha-card__title"><?php the_title(); ?></h3>
				<span class="meeha-meta"><?php echo esc_html( meeha_bengali_time_ago( get_the_time( 'U' ) ) ); ?></span>
			</a>
			<?php
		}
	}
	$html = ob_get_clean();
	wp_reset_postdata();

	wp_send_json_success( array( 'html' => $html, 'has_more' => $query->post_count === 10 ) );
}
add_action( 'wp_ajax_meeha_category_load_more', 'meeha_handle_category_load_more' );
add_action( 'wp_ajax_nopriv_meeha_category_load_more', 'meeha_handle_category_load_more' );

// category.php reuses homepage design tokens (.meeha-grid, .meeha-card) + single-post sidebar assets
function meeha_maybe_enqueue_category_assets() {
	if ( ! is_category() ) {
		return;
	}
	wp_enqueue_style(
		'meeha-homepage-style',
		MEEHA_CHILD_URI . '/assets/css/meeha-homepage.css',
		array( 'meeha-child-style' ),
		MEEHA_CHILD_VERSION
	);
	wp_enqueue_style(
		'meeha-single-style',
		MEEHA_CHILD_URI . '/assets/css/meeha-single.css',
		array( 'meeha-homepage-style' ),
		MEEHA_CHILD_VERSION
	);
	wp_enqueue_style(
		'meeha-glance-style',
		MEEHA_CHILD_URI . '/assets/css/meeha-glance.css',
		array(),
		MEEHA_CHILD_VERSION
	);
	wp_enqueue_script(
		'meeha-single-script',
		MEEHA_CHILD_URI . '/assets/js/meeha-single.js',
		array(),
		MEEHA_CHILD_VERSION,
		true
	);
	wp_enqueue_script(
		'meeha-category-script',
		MEEHA_CHILD_URI . '/assets/js/meeha-category.js',
		array( 'meeha-single-script' ),
		MEEHA_CHILD_VERSION,
		true
	);
	wp_add_inline_script(
		'meeha-single-script',
		'window.meehaPollAjax = { url: "' . esc_url( admin_url( 'admin-ajax.php' ) ) . '", nonce: "' . esc_js( wp_create_nonce( 'meeha_poll_nonce' ) ) . '" };',
		'before'
	);

	// সাইডবারের "জনপ্রিয়/ট্রেন্ড/নতুন" ট্যাব-উইজেটের CSS/JS
	meeha_enqueue_tabbed_widget_assets( 'meeha-single-style' );
}
add_action( 'wp_enqueue_scripts', 'meeha_maybe_enqueue_category_assets' );
