<?php
/**
 * Settings tab `single` (একক পোস্ট) + one-time import of the legacy `meeha_single_settings` option.
 *
 * Legacy option (never modified): `meeha_single_settings` — only `font_step_percent` and `comments_heading`
 * were ever read by the old theme (the other toggles were dead), so only those two are imported.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

/**
 * Default correction-note sentence.
 */
define( 'MEEHA_CORRECTION_TEXT', 'এই প্রতিবেদনে কোনো তথ্যগত ভুল চোখে পড়লে সংশোধনী নীতি অনুযায়ী আমাদের জানান।' );

/**
 * Field definitions of the `single` tab.
 *
 * @return array
 */
function meeha_single_settings_fields() {
	return array(
		array( 'type' => 'heading', 'label' => 'পড়া ও তথ্য' ),
		array( 'key' => 'reading_wpm', 'type' => 'number', 'label' => 'পড়ার গতি (শব্দ/মিনিট)', 'default' => 200, 'min' => 80, 'max' => 600, 'desc' => '"১২ মিনিটে পড়ুন" হিসাবের জন্য। বাংলা পাঠে গড়ে ২০০।' ),
		array( 'key' => 'show_updated', 'type' => 'checkbox', 'label' => 'হালনাগাদের সময় দেখান', 'default' => 1, 'desc' => 'প্রকাশের ১০ মিনিট পরে সম্পাদনা হলে বাইলাইনে "হালনাগাদ" সময় দেখাবে।' ),
		array( 'key' => 'font_step', 'type' => 'number', 'label' => 'লেখা ছোট/বড় করার ধাপ (%)', 'default' => 10, 'min' => 1, 'max' => 30, 'desc' => 'A−/A+ বোতামে প্রতি চাপে কত শতাংশ বদলাবে (সীমা ৮৫%–১৪০%)।' ),
		array( 'key' => 'summary_button', 'type' => 'checkbox', 'label' => '"সারাংশ পড়ুন" বোতাম', 'default' => 1, 'desc' => 'পোস্টে সারাংশ (মেটাবক্স) থাকলে দেখাবে।' ),
		array( 'key' => 'card_button', 'type' => 'checkbox', 'label' => 'ছবির কোণে "ফটোকার্ড ডাউনলোড" বোতাম', 'default' => 1, 'desc' => 'পাঠক এক চাপে খবরের ফটোকার্ড (স্টুডিওর ডিফল্ট নিউজ ফ্রেম) নামাতে পারবেন।' ),
		array( 'type' => 'heading', 'label' => 'শেয়ার ও প্রতিক্রিয়া' ),
		array(
			'key'     => 'share_networks',
			'type'    => 'sortable',
			'label'   => 'শেয়ার বোতাম (ক্রম ও চালু/বন্ধ)',
			'default' => array(
				array( 'id' => 'fb', 'label' => 'ফেসবুক', 'enabled' => 1 ),
				array( 'id' => 'wa', 'label' => 'হোয়াটসঅ্যাপ', 'enabled' => 1 ),
				array( 'id' => 'x', 'label' => 'এক্স', 'enabled' => 1 ),
				array( 'id' => 'tg', 'label' => 'টেলিগ্রাম', 'enabled' => 1 ),
				array( 'id' => 'copy', 'label' => 'লিংক কপি', 'enabled' => 1 ),
				array( 'id' => 'native', 'label' => 'অন্য অ্যাপে (মোবাইল)', 'enabled' => 1 ),
			),
		),
		array( 'key' => 'react_q_news', 'type' => 'text', 'label' => 'প্রতিক্রিয়ার প্রশ্ন — খবর', 'default' => 'প্রতিবেদনটি আপনার কাছে কেমন লাগল?' ),
		array( 'key' => 'react_important', 'type' => 'text', 'label' => 'খবর: important', 'default' => 'গুরুত্বপূর্ণ' ),
		array( 'key' => 'react_informative', 'type' => 'text', 'label' => 'খবর: informative', 'default' => 'তথ্যবহুল' ),
		array( 'key' => 'react_worrying', 'type' => 'text', 'label' => 'খবর: worrying', 'default' => 'উদ্বেগজনক' ),
		array( 'key' => 'react_disagree', 'type' => 'text', 'label' => 'খবর/মতামত: disagree', 'default' => 'একমত নই' ),
		array( 'key' => 'react_q_opinion', 'type' => 'text', 'label' => 'প্রতিক্রিয়ার প্রশ্ন — মতামত', 'default' => 'লেখাটির সঙ্গে আপনি কতটা একমত?' ),
		array( 'key' => 'react_agree', 'type' => 'text', 'label' => 'মতামত: agree', 'default' => 'একমত' ),
		array( 'key' => 'react_partly', 'type' => 'text', 'label' => 'মতামত: partly', 'default' => 'আংশিক একমত' ),
		array( 'key' => 'react_q_lit', 'type' => 'text', 'label' => 'প্রতিক্রিয়ার প্রশ্ন — সাহিত্য', 'default' => 'লেখাটি ছুঁয়ে গেলে জানান' ),
		array( 'key' => 'react_love', 'type' => 'text', 'label' => 'সাহিত্য: love', 'default' => 'ভালো লেগেছে' ),
		array( 'type' => 'heading', 'label' => 'লেখার ভেতরে' ),
		array( 'key' => 'readalso_para', 'type' => 'number', 'label' => '"আরও পড়ুন" কার্ড — অনুচ্ছেদের পরে', 'default' => 5, 'min' => 0, 'max' => 30, 'desc' => '০ = বন্ধ। সম্পাদক নিজে কার্ড বসালে স্বয়ংক্রিয় কার্ড বসবে না।' ),
		array( 'key' => 'ad_para_1', 'type' => 'number', 'label' => 'বিজ্ঞাপন ১ (article_1) — অনুচ্ছেদের পরে', 'default' => 3, 'min' => 0, 'max' => 30, 'desc' => '০ = বন্ধ। মতামতে কমপক্ষে ৪র্থ অনুচ্ছেদের পরে; সাহিত্যে বিজ্ঞাপন নেই।' ),
		array( 'key' => 'ad_para_2', 'type' => 'number', 'label' => 'বিজ্ঞাপন ২ (article_2) — অনুচ্ছেদের পরে', 'default' => 8, 'min' => 0, 'max' => 40, 'desc' => 'পরে অন্তত ৩টি অনুচ্ছেদ থাকলে তবেই বসবে।' ),
		array( 'key' => 'related_count', 'type' => 'number', 'label' => '"এ বিষয়ে আরও" — কয়টি', 'default' => 4, 'min' => 0, 'max' => 8 ),
		array( 'type' => 'heading', 'label' => 'সাইডবার (ডেস্কটপ, ১০২৪ পিক্সেল+)', 'desc' => 'মোবাইলে সাইডবার দেখায় না (সর্বাধিক পঠিত লেখার নিচে থাকে)। শেষ ব্লকটি স্ক্রলের সময় স্থির থাকে; দীর্ঘ লেখার সূচিপত্র থাকলে সেটিও তার সঙ্গে স্থির থাকে।' ),
		array(
			'key'     => 'sidebar_blocks',
			'type'    => 'sortable',
			'label'   => 'সাইডবারের ব্লক (চালু/বন্ধ, ক্রম, শিরোনাম)',
			'default' => array(
				array( 'id' => 'latest', 'label' => 'সর্বশেষ', 'enabled' => 1 ),
				array( 'id' => 'trending', 'label' => 'ট্রেন্ডিং', 'enabled' => 1 ),
				array( 'id' => 'mostread', 'label' => 'সর্বাধিক পঠিত', 'enabled' => 1 ),
				array( 'id' => 'area', 'label' => 'এলাকার খবর খুঁজুন', 'enabled' => 1 ),
				array( 'id' => 'ad', 'label' => 'বিজ্ঞাপন (article_side)', 'enabled' => 1 ),
			),
		),
		array( 'key' => 'sidebar_latest_count', 'type' => 'number', 'label' => 'সর্বশেষ — খবরের সংখ্যা', 'default' => 6, 'min' => 3, 'max' => 10 ),
		array( 'key' => 'sidebar_trending_count', 'type' => 'number', 'label' => 'ট্রেন্ডিং — খবরের সংখ্যা', 'default' => 5, 'min' => 3, 'max' => 8 ),
		array( 'key' => 'sidebar_mostread_count', 'type' => 'number', 'label' => 'সর্বাধিক পঠিত — খবরের সংখ্যা', 'default' => 5, 'min' => 3, 'max' => 10 ),
		array(
			'key'   => 'sidebar_area_cats',
			'type'  => 'categories',
			'label' => 'এলাকার খবর দেখানো হবে যে ক্যাটেগরিতে',
			'desc'  => 'এসব বিভাগ (ও তাদের উপবিভাগ)-এর খবরের পাতায় "এলাকার খবর খুঁজুন" দেখাবে। ডিফল্ট: মাতৃভূমি ও রাজনীতি।',
		),

		array( 'type' => 'heading', 'label' => 'মন্তব্য' ),
		array( 'key' => 'comments_collapsed', 'type' => 'checkbox', 'label' => 'মন্তব্য শুরুতে গুটানো থাকবে', 'default' => 1, 'desc' => 'পাঠক "মন্তব্য দেখুন ও লিখুন" চাপলে মন্তব্য ও তার স্টাইল/স্ক্রিপ্ট লোড হবে।' ),
		array( 'key' => 'comments_heading', 'type' => 'text', 'label' => 'মন্তব্য অংশের শিরোনাম', 'default' => 'মন্তব্য' ),
		array( 'key' => 'comments_note', 'type' => 'text', 'label' => 'গুটানো অবস্থার লেখা', 'default' => 'মন্তব্য দেখতে বা লিখতে চাপ দিন। মন্তব্য সম্পাদকীয় নীতিমালা মেনে প্রকাশ হয়।' ),
		array( 'type' => 'heading', 'label' => 'সংশোধনী ও মতামত' ),
		array( 'key' => 'correction_text', 'type' => 'textarea', 'label' => 'সংশোধনী নোট', 'default' => MEEHA_CORRECTION_TEXT, 'desc' => 'লেখার "সংশোধনী নীতি" অংশটি নিচের পাতার লিংক হবে। পাশে ছোট "রিপোর্ট করুন" বোতাম থাকে।' ),
		array( 'key' => 'policy_url', 'type' => 'url', 'label' => 'সংশোধনী নীতির পাতা (URL)', 'default' => '', 'desc' => 'খালি থাকলে "correction-policy" বা "সংশোধনী নীতি" নামের পাতা খোঁজা হয়।' ),
		array( 'key' => 'opinion_disclaimer', 'type' => 'textarea', 'label' => 'মতামত-লেখার দায়মুক্তি', 'default' => 'এই লেখার মতামত লেখকের নিজস্ব। এটি মিহা নিউজের সম্পাদকীয় অবস্থানের প্রতিফলন নয়।', 'desc' => 'সম্পাদকীয় (সম্পাদকীয় বিভাগ) লেখায় দেখানো হয় না।' ),
		array( 'key' => 'opinion_submit_url', 'type' => 'url', 'label' => '"লেখা পাঠানোর নিয়ম" পাতা', 'default' => '' ),
		array( 'key' => 'tips_email', 'type' => 'email', 'label' => 'লেখক-বাক্সে তথ্য দেওয়ার ইমেইল', 'default' => '', 'desc' => 'অনুসন্ধানী প্রতিবেদনের লেখক-বাক্সে দেখাবে (যেমন tips@meeha.news)।' ),
	);
}

meeha_register_settings_tab(
	'single',
	array(
		'title'  => 'একক পোস্ট',
		'order'  => 25,
		'cap'    => 'design',
		'desc'   => 'খবর, অনুসন্ধান, মতামত ও সাহিত্যের একক পাতার আচরণ।',
		'fields' => meeha_single_settings_fields(),
	)
);

/**
 * Read a `single` setting.
 *
 * @param string $key     Key.
 * @param mixed  $default Default.
 * @return mixed
 */
function meeha_single_opt( $key, $default = null ) {
	return meeha_opt( 'single', $key, $default );
}

/**
 * One-time import of legacy option arrays owned by D (`meeha_single_settings` → single,
 * `meeha_photocard_settings` → quotes). The legacy options are only read.
 *
 * @param bool $force Re-run even if already imported.
 * @return array What was imported.
 */
function meeha_d_import_legacy( $force = false ) {
	$done = array();
	if ( $force || false === get_option( 'meeha_opt_single', false ) ) {
		$legacy = get_option( 'meeha_single_settings', array() );
		$new    = array();
		if ( is_array( $legacy ) ) {
			if ( isset( $legacy['font_step_percent'] ) ) {
				$new['font_step'] = max( 1, min( 30, absint( $legacy['font_step_percent'] ) ) );
			}
			if ( ! empty( $legacy['comments_heading'] ) ) {
				$new['comments_heading'] = sanitize_text_field( $legacy['comments_heading'] );
			}
		}
		if ( $new ) {
			$cur = get_option( 'meeha_opt_single', array() );
			meeha_opt_update( 'single', array_merge( is_array( $cur ) ? $cur : array(), $new ) );
			$done['single'] = $new;
		}
	}
	if ( $force || false === get_option( 'meeha_opt_quotes', false ) ) {
		$legacy = get_option( 'meeha_photocard_settings', array() );
		if ( is_array( $legacy ) && ! empty( $legacy['poll_duration_days'] ) ) {
			$cur = get_option( 'meeha_opt_quotes', array() );
			$new = array( 'poll_days' => max( 1, min( 365, absint( $legacy['poll_duration_days'] ) ) ) );
			meeha_opt_update( 'quotes', array_merge( is_array( $cur ) ? $cur : array(), $new ) );
			$done['quotes'] = $new;
		}
	}
	return $done;
}
add_action( 'after_switch_theme', 'meeha_d_import_legacy', 20 );
add_action(
	'admin_init',
	function () {
		if ( ! get_option( 'meeha_d_legacy_imported' ) ) {
			meeha_d_import_legacy();
			update_option( 'meeha_d_legacy_imported', 1, true );
		}
	}
);

/**
 * Default area-widget categories (মাতৃভূমি `motherland`, রাজনীতি `country-politics`) are stored once, so the
 * settings field shows them and the front end needs no slug lookups.
 */
add_action(
	'init',
	function () {
		$stored = get_option( 'meeha_opt_single', array() );
		if ( is_array( $stored ) && array_key_exists( 'sidebar_area_cats', $stored ) ) {
			return;
		}
		$ids = array();
		foreach ( array( 'motherland', 'country-politics' ) as $slug ) {
			$t = get_category_by_slug( $slug );
			if ( $t ) {
				$ids[] = (int) $t->term_id;
			}
		}
		meeha_opt_update( 'single', array( 'sidebar_area_cats' => $ids ) );
	},
	25
);

/* Reading speed setting feeds A's meeha_reading_time() via its filter. */
add_filter(
	'meeha_reading_wpm',
	function ( $wpm ) {
		$v = (int) meeha_single_opt( 'reading_wpm', 200 );
		return $v > 0 ? $v : $wpm;
	}
);

/**
 * Enabled share network ids, in order.
 *
 * @return string[]
 */
function meeha_single_share_networks() {
	$out = array();
	foreach ( (array) meeha_single_opt( 'share_networks', array() ) as $n ) {
		if ( ! empty( $n['enabled'] ) && ! empty( $n['id'] ) ) {
			$out[] = $n['id'];
		}
	}
	return $out ? $out : array( 'fb', 'wa', 'x', 'copy' );
}

/**
 * URL of the correction-policy page.
 *
 * @return string
 */
function meeha_single_policy_url() {
	$url = (string) meeha_single_opt( 'policy_url', '' );
	if ( '' !== $url ) {
		return $url;
	}
	$cached = wp_cache_get( 'meeha_policy_url', 'meeha' );
	if ( false !== $cached ) {
		return (string) $cached;
	}
	$url = '';
	foreach ( array( 'correction-policy', 'corrections', 'songshodhoni-niti' ) as $slug ) {
		$p = get_page_by_path( $slug );
		if ( $p && 'publish' === $p->post_status ) {
			$url = get_permalink( $p );
			break;
		}
	}
	if ( '' === $url ) {
		$q = get_posts(
			array(
				'post_type'      => 'page',
				'title'          => 'সংশোধনী নীতি',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		$url = $q ? get_permalink( $q[0] ) : '';
	}
	wp_cache_set( 'meeha_policy_url', $url, 'meeha', HOUR_IN_SECONDS );
	return $url;
}
