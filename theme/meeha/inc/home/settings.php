<?php
/**
 * Settings tab `home` (মিহা থিম › হোমপেজ), category resolution by slug, one-time legacy import.
 *
 * Category fields are stored as ids but default to EMPTY, which means "resolve the default slugs on this
 * site" — live category ids are never hard-coded.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

/**
 * Default category slugs per setting key.
 *
 * @return array<string,string[]>
 */
function meeha_home_slug_defaults() {
	return (array) apply_filters(
		'meeha_home_slug_defaults',
		array(
			'hero_cats'         => array(),
			'hero_exclude'      => array( 'editorial', 'sub-editorial', 'readers-words', 'bengali-culture' ),
			'inv_cats'          => array( 'search', 'meeha-special' ),
			'mb_cats'           => array( 'motherland' ),
			'world_cats'        => array( 'world-politics', 'middle-est', 'asia' ),
			'op_editorial_cats' => array( 'editorial' ),
			'op_columnist_cats' => array( 'sub-editorial', 'readers-words' ),
			'trio_1'            => array( 'economy' ),
			'trio_2'            => array( 'sports' ),
			'trio_3'            => array( 'entertainment' ),
			'trio_pool'         => array( 'tech-world', 'religion', 'probas-life', 'viral' ),
			'lit_cats'          => array( 'bengali-culture' ),
		)
	);
}

/**
 * Every category (one cached query per request; WP primes the term cache with it).
 *
 * @return WP_Term[] term_id => term
 */
function meeha_home_all_cats() {
	static $all = null;
	if ( null === $all ) {
		$all   = array();
		$terms = get_terms(
			array(
				'taxonomy'               => 'category',
				'hide_empty'             => false,
				'update_term_meta_cache' => false,
			)
		);
		foreach ( is_wp_error( $terms ) ? array() : $terms as $t ) {
			$all[ (int) $t->term_id ] = $t;
		}
	}
	return $all;
}

/**
 * Category id by slug (from the request-cached category list — no query per slug).
 *
 * @param string $slug Slug.
 * @return int 0 when missing.
 */
function meeha_home_cat_id( $slug ) {
	static $by = null;
	if ( null === $by ) {
		$by = array();
		foreach ( meeha_home_all_cats() as $t ) {
			$by[ $t->slug ] = (int) $t->term_id;
		}
	}
	return isset( $by[ $slug ] ) ? $by[ $slug ] : 0;
}

/**
 * Resolved category ids for a setting key: the stored override when not empty, else the slug defaults.
 *
 * @param string $key Setting key.
 * @return int[]
 */
function meeha_home_cats( $key ) {
	$v = meeha_opt( 'home', $key );
	$v = is_array( $v ) ? $v : ( $v ? array( $v ) : array() );
	$v = array_values( array_filter( array_map( 'intval', $v ) ) );
	if ( $v ) {
		return $v;
	}
	$d   = meeha_home_slug_defaults();
	$out = array();
	foreach ( isset( $d[ $key ] ) ? $d[ $key ] : array() as $slug ) {
		$id = meeha_home_cat_id( $slug );
		if ( $id ) {
			$out[] = $id;
		}
	}
	return $out;
}

/**
 * Human list of the default categories of a key (for field descriptions).
 *
 * @param string $key Key.
 * @return string
 */
function meeha_home_default_label( $key ) {
	if ( ! is_admin() || wp_doing_ajax() ) {
		return ''; // descriptions are only shown on the settings page — no term lookups on the front end.
	}
	$d     = meeha_home_slug_defaults();
	$names = array();
	foreach ( isset( $d[ $key ] ) ? $d[ $key ] : array() as $slug ) {
		$t       = get_category_by_slug( $slug );
		$names[] = $t ? $t->name : $slug;
	}
	return $names ? 'ফাঁকা রাখলে ডিফল্ট: ' . implode( ', ', $names ) . '।' : 'ফাঁকা রাখলে সব বিভাগ (বাদ-তালিকা ছাড়া)।';
}

/**
 * Default section list (sortable rows; label = title shown on the page).
 *
 * @return array
 */
function meeha_home_default_sections() {
	return array(
		array( 'id' => 's1', 'label' => 'প্রধান খবর', 'enabled' => 1 ),
		array( 'id' => 's2', 'label' => 'ট্রেন্ডিং', 'enabled' => 1 ),
		array( 'id' => 's3', 'label' => 'একনজরে', 'enabled' => 1 ),
		array( 'id' => 's4', 'label' => 'অনুসন্ধান', 'enabled' => 1 ),
		array( 'id' => 's5', 'label' => 'মাতৃভূমি', 'enabled' => 1 ),
		array( 'id' => 's13', 'label' => 'শিক্ষা ও ক্যাম্পাস', 'enabled' => 1 ),
		array( 'id' => 's6', 'label' => 'ভিডিও', 'enabled' => 1 ),
		array( 'id' => 's7', 'label' => 'বিশ্ব', 'enabled' => 1 ),
		array( 'id' => 's8', 'label' => 'মতামত', 'enabled' => 1 ),
		array( 'id' => 's9', 'label' => 'অর্থব্যবস্থা · খেলা · বিনোদন', 'enabled' => 1 ),
		array( 'id' => 's10', 'label' => 'জনপ্রিয়', 'enabled' => 1 ),
		array( 'id' => 's11', 'label' => 'বাঙালি সাহিত্য-সংস্কৃতি', 'enabled' => 1 ),
		array( 'id' => 's12', 'label' => 'উক্তি', 'enabled' => 1 ),
	);
}

/**
 * Field definitions of the tab.
 *
 * @return array
 */
function meeha_home_fields() {
	$win  = array(
		'today' => 'আজ',
		'7d'    => '৭ দিন',
		'30d'   => '৩০ দিন',
	);
	$cats = function ( $key, $label, $extra = '' ) {
		return array(
			'key'   => $key,
			'type'  => 'categories',
			'label' => $label,
			'desc'  => trim( meeha_home_default_label( $key ) . ' ' . $extra ),
		);
	};
	return array(
		array( 'type' => 'heading', 'label' => 'সেকশন', 'desc' => 'টেনে বা ↑↓ দিয়ে ক্রম বদলান; টিক তুলে দিলে সেকশনটি লুকাবে; লেখা বদলালে সেকশনের শিরোনাম বদলাবে। বিজ্ঞাপন নিজ নিজ সেকশনের সঙ্গে থাকে (লিডারবোর্ড ট্রেন্ডিংয়ের পরে, MPU মাতৃভূমির পাশে ও মতামতের পরে)।' ),
		array(
			'key'     => 'sections',
			'type'    => 'sortable',
			'label'   => 'হোমপেজের সেকশন',
			'default' => meeha_home_default_sections(),
		),
		array( 'key' => 'dedupe', 'type' => 'checkbox', 'label' => 'একই খবর দুবার নয়', 'default' => 1, 'text' => 'চালু', 'desc' => 'ওপরের বিভাগ-সেকশনে দেখানো খবর নিচের বিভাগ-সেকশনে বাদ যাবে (র‍্যাংক তালিকা ও একনজরে বাদে)।' ),

		array( 'type' => 'heading', 'label' => 'লিড প্যাকেজ (S1)' ),
		array( 'key' => 'lead_post_id', 'type' => 'post_id', 'label' => 'লিড পিন (পোস্ট আইডি)', 'cap' => 'editorial', 'desc' => 'এই খবরটি লিডে থাকবে, নিচের সময় পর্যন্ত। ০ = স্বয়ংক্রিয়।' ),
		array( 'key' => 'lead_hours', 'type' => 'number', 'label' => 'লিড পিনের মেয়াদ', 'default' => 12, 'min' => 1, 'max' => 72, 'unit' => 'ঘণ্টা', 'cap' => 'editorial' ),
		$cats( 'hero_cats', 'হিরো বিভাগ' ),
		$cats( 'hero_exclude', 'হিরো থেকে বাদ' ),
		array( 'key' => 'use_sticky', 'type' => 'checkbox', 'label' => '"শীর্ষ" খবর', 'default' => 1, 'text' => 'গত ৭২ ঘণ্টার স্টিকি পোস্টকে লিডে অগ্রাধিকার দিন' ),
		array( 'key' => 'latest_title', 'type' => 'text', 'label' => 'সর্বশেষ তালিকার শিরোনাম', 'default' => 'সর্বশেষ' ),
		array( 'key' => 'latest_count', 'type' => 'number', 'label' => 'সর্বশেষ তালিকায় খবর', 'default' => 7, 'min' => 5, 'max' => 12, 'desc' => 'মোবাইলে প্রথম ৫টি দেখাবে।' ),

		array( 'type' => 'heading', 'label' => 'ট্রেন্ডিং (S2)', 'desc' => 'স্কোর = (গত ৬ ঘণ্টার ভিউ + ০.৫ × ৬–২৪ ঘণ্টা + ০.২ × ২৪–৪৮ ঘণ্টা) ÷ (বয়স + ২)^১.২; শুধু ৪৮ ঘণ্টার কম পুরোনো খবর। সম্পাদকের পিন (পোস্টের "মিহা সম্পাদকীয়" বাক্স) শেষের ঘরগুলোতে বসে।' ),
		array( 'key' => 'trend_count', 'type' => 'number', 'label' => 'খবরের সংখ্যা', 'default' => 5, 'min' => 3, 'max' => 8 ),
		array( 'key' => 'trend_threshold', 'type' => 'number', 'label' => 'ন্যূনতম ভিউ (৬ ঘণ্টায়)', 'default' => 10, 'min' => 1, 'max' => 5000, 'desc' => 'এর কম হলে ট্রেন্ডিং ধরা হবে না; তখন গত ২৪ ঘণ্টার সর্বাধিক পঠিত দিয়ে ঘর ভরবে।' ),
		array( 'key' => 'trend_pins', 'type' => 'number', 'label' => 'সর্বোচ্চ পিন', 'default' => 2, 'min' => 0, 'max' => 2 ),
		array( 'key' => 'trend_pin_hours', 'type' => 'number', 'label' => 'পিনের মেয়াদ', 'default' => 24, 'min' => 1, 'max' => 72, 'unit' => 'ঘণ্টা' ),

		array( 'type' => 'heading', 'label' => 'একনজরে (S3)' ),
		array(
			'key'     => 'glance_mode',
			'type'    => 'select',
			'label'   => 'উৎস',
			'default' => 'flagged_auto',
			'cap'     => 'editorial',
			'options' => array(
				'flagged_auto' => 'চিহ্নিত খবর; না থাকলে সর্বশেষ',
				'flagged'      => 'শুধু চিহ্নিত খবর',
				'auto'         => 'সর্বশেষ খবর',
			),
		),
		array( 'key' => 'glance_count', 'type' => 'number', 'label' => 'লাইনের সংখ্যা', 'default' => 8, 'min' => 4, 'max' => 12, 'cap' => 'editorial', 'desc' => 'মোবাইলে প্রথম ৬টি।' ),
		array( 'key' => 'glance_url', 'type' => 'url', 'label' => '"দিনের সব খবর" লিংক', 'desc' => 'ফাঁকা = [meeha_at_a_glance] শর্টকোডের পাতা।' ),

		array( 'type' => 'heading', 'label' => 'অনুসন্ধান (S4)' ),
		$cats( 'inv_cats', 'বিভাগ', '"অনুসন্ধান" চিহ্নিত খবরও আসবে।' ),
		array( 'key' => 'inv_note', 'type' => 'text', 'label' => 'উপশিরোনাম', 'default' => 'ক্ষমতার জবাবদিহি খোঁজে মিহা নিউজের অনুসন্ধানী ডেস্ক' ),
		array( 'key' => 'inv_fallback', 'type' => 'category', 'label' => 'বিকল্প বিভাগ', 'desc' => 'একটিও খবর না থাকলে এই বিভাগ থেকে।' ),

		array( 'type' => 'heading', 'label' => 'মাতৃভূমি ও এলাকার খবর (S5)' ),
		$cats( 'mb_cats', 'বিভাগ' ),
		array( 'key' => 'mb_count', 'type' => 'number', 'label' => 'খবরের সংখ্যা', 'default' => 5, 'min' => 2, 'max' => 9 ),
		array(
			'key'     => 'mb_layout',
			'type'    => 'select',
			'label'   => 'নকশা',
			'default' => 'mosaic',
			'options' => array(
				'mosaic' => 'মোজাইক (বড় + ২×২)',
				'list'   => 'বড় + তালিকা',
			),
		),
		array( 'key' => 'mb_area', 'type' => 'checkbox', 'label' => 'এলাকার খবর বাক্স', 'default' => 1, 'text' => 'পাশে দেখান' ),
		array( 'key' => 'mb_fallback', 'type' => 'category', 'label' => 'বিকল্প বিভাগ' ),

		array( 'type' => 'heading', 'label' => 'ভিডিও (S6)' ),
		array( 'key' => 'video_count', 'type' => 'number', 'label' => 'ভিডিওর সংখ্যা', 'default' => 10, 'min' => 3, 'max' => 16, 'desc' => '৩টির কম হলে সেকশন লুকাবে।' ),
		array( 'key' => 'video_url', 'type' => 'url', 'label' => '"সব ভিডিও" লিংক', 'desc' => 'ফাঁকা = [meeha_video_reels] শর্টকোডের পাতা।' ),

		array( 'type' => 'heading', 'label' => 'বিশ্ব ও সর্বাধিক পঠিত (S7)' ),
		$cats( 'world_cats', 'বিভাগ' ),
		array( 'key' => 'world_count', 'type' => 'number', 'label' => 'খবরের সংখ্যা', 'default' => 4, 'min' => 2, 'max' => 7 ),
		array(
			'key'     => 'world_layout',
			'type'    => 'select',
			'label'   => 'নকশা',
			'default' => 'feature',
			'options' => array(
				'feature' => 'বড় ছবি পাশে + ৩ কলাম',
				'list'    => 'তালিকা',
			),
		),
		array( 'key' => 'world_fallback', 'type' => 'category', 'label' => 'বিকল্প বিভাগ' ),
		array( 'key' => 'mostread_title', 'type' => 'text', 'label' => 'সর্বাধিক পঠিত — শিরোনাম', 'default' => 'সর্বাধিক পঠিত' ),
		array( 'key' => 'mostread_count', 'type' => 'number', 'label' => 'সর্বাধিক পঠিত — সংখ্যা', 'default' => 5, 'min' => 3, 'max' => 10 ),
		array( 'key' => 'mostread_home', 'type' => 'select', 'label' => 'ডিফল্ট সময় — হোম', 'default' => 'today', 'options' => $win ),
		array( 'key' => 'mostread_single', 'type' => 'select', 'label' => 'ডিফল্ট সময় — খবরের পাতা', 'default' => '7d', 'options' => $win ),
		array( 'key' => 'mostread_archive', 'type' => 'select', 'label' => 'ডিফল্ট সময় — বিভাগ পাতা', 'default' => '7d', 'options' => $win ),

		array( 'type' => 'heading', 'label' => 'মতামত (S8)' ),
		$cats( 'op_editorial_cats', 'সম্পাদকীয় বিভাগ' ),
		$cats( 'op_columnist_cats', 'কলাম বিভাগ' ),
		array( 'key' => 'op_count', 'type' => 'number', 'label' => 'কলামের সংখ্যা', 'default' => 3, 'min' => 2, 'max' => 6, 'desc' => 'সম্পাদকীয় না থাকলে একটি বেশি কলাম দেখাবে।' ),

		array( 'type' => 'heading', 'label' => 'তিন কলাম (S9)', 'desc' => 'কোনো কলামে ২টির কম খবর থাকলে বিকল্প তালিকার পরের বিভাগ বসবে।' ),
		array( 'key' => 'trio_1', 'type' => 'category', 'label' => 'কলাম ১', 'desc' => meeha_home_default_label( 'trio_1' ) . ' (উপবিভাগসহ)' ),
		array( 'key' => 'trio_2', 'type' => 'category', 'label' => 'কলাম ২', 'desc' => meeha_home_default_label( 'trio_2' ) ),
		array( 'key' => 'trio_3', 'type' => 'category', 'label' => 'কলাম ৩', 'desc' => meeha_home_default_label( 'trio_3' ) ),
		$cats( 'trio_pool', 'বিকল্প তালিকা' ),
		array( 'key' => 'trio_count', 'type' => 'number', 'label' => 'প্রতি কলামে খবর', 'default' => 3, 'min' => 2, 'max' => 5 ),

		array( 'type' => 'heading', 'label' => 'জনপ্রিয় (S10)', 'desc' => 'স্কোর = প্রতিক্রিয়া × ক + শেয়ার × খ + মন্তব্য × গ (ভিউ গোনা হয় না)।' ),
		array( 'key' => 'pop_count', 'type' => 'number', 'label' => 'খবরের সংখ্যা', 'default' => 4, 'min' => 4, 'max' => 8, 'desc' => 'স্কোর > ০ এমন খবর ৪টির কম হলে সেকশন লুকাবে।' ),
		array( 'key' => 'pop_days', 'type' => 'number', 'label' => 'সময়সীমা', 'default' => 30, 'min' => 7, 'max' => 90, 'unit' => 'দিন' ),
		array( 'key' => 'pop_w_reactions', 'type' => 'number', 'label' => 'প্রতিক্রিয়ার ওজন (ক)', 'default' => 1, 'min' => 0, 'max' => 20 ),
		array( 'key' => 'pop_w_shares', 'type' => 'number', 'label' => 'শেয়ারের ওজন (খ)', 'default' => 3, 'min' => 0, 'max' => 20 ),
		array( 'key' => 'pop_w_comments', 'type' => 'number', 'label' => 'মন্তব্যের ওজন (গ)', 'default' => 4, 'min' => 0, 'max' => 20 ),

		array( 'type' => 'heading', 'label' => 'সাহিত্য ও উক্তি (S11–S12)' ),
		array( 'key' => 'lit_count', 'type' => 'number', 'label' => 'সাহিত্য — লেখার সংখ্যা', 'default' => 4, 'min' => 2, 'max' => 6 ),
		array( 'key' => 'quotes_count', 'type' => 'number', 'label' => 'উক্তি — সংখ্যা', 'default' => 8, 'min' => 3, 'max' => 12 ),

		array( 'type' => 'heading', 'label' => 'ভিউ গণনা', 'desc' => meeha_home_view_mode_note() ),
		array(
			'key'     => 'view_mode',
			'type'    => 'radio',
			'label'   => 'ভিউ গণনা',
			'default' => 'beacon',
			'options' => array(
				'beacon' => 'বীকন (সঠিক, ক্যাশ করা পাতাও)',
				'legacy' => 'পুরোনো পদ্ধতি (সার্ভারে)',
			),
		),
		array( 'key' => 'view_delay', 'type' => 'number', 'label' => 'বীকনের আগে দেখার সময়', 'default' => 5, 'min' => 2, 'max' => 30, 'unit' => 'সেকেন্ড' ),
		array( 'key' => 'view_skip_editors', 'type' => 'checkbox', 'label' => 'সম্পাদকদের ভিউ', 'default' => 1, 'text' => 'লগ-ইন করা সম্পাদক/প্রশাসকের ভিউ গুনবেন না' ),
		array( 'key' => 'live_on', 'type' => 'checkbox', 'label' => 'রিয়েলটাইম ভিজিটর', 'default' => 1, 'text' => 'এখন সাইটে কতজন আছেন গুনুন (পাবলিশার ড্যাশবোর্ডে দেখায়; খোলা পাতা প্রতি মিনিটে একটি ছোট সংকেত পাঠায়)' ),
	);
}

/**
 * Short Bengali note about the counting mode (shown on the tab).
 *
 * @return string
 */
function meeha_home_view_mode_note() {
	return 'বীকন: পাঠক পাতাটি অন্তত কয়েক সেকেন্ড দেখলে ব্রাউজার থেকে একবার গোনা হয় — LiteSpeed-এ ক্যাশ করা পাতাও; বট ও একই আইপির ২৪ ঘণ্টার পুনরাবৃত্তি বাদ। আগে ক্যাশ করা পাতার ভিউ গোনা হতো না, তাই বীকনে ভিউ-সংখ্যা আগের চেয়ে বাড়বে — নিউজরুম প্লাগইনের ভিউ-ভিত্তিক বোনাসও সেই অনুযায়ী বাড়তে পারে। পুরোনো পদ্ধতি: পাতা তৈরির সময় সার্ভারে গোনা (ক্যাশ করা পাতা বাদ পড়ে)।';
}

meeha_register_settings_tab(
	'home',
	array(
		'title'  => 'হোমপেজ',
		'order'  => 15,
		'cap'    => 'design',
		'desc'   => 'হোমপেজের সেকশন, লিড, তালিকার নিয়ম ও ভিউ গণনা। বিভাগের ঘর ফাঁকা রাখলে থিম নিজে সঠিক বিভাগ খুঁজে নেয় (স্লাগ দিয়ে)।',
		'fields' => meeha_home_fields(),
		'render' => 'meeha_home_settings_status',
	)
);

/**
 * Extra status block under the fields: legacy import report, metrics status, search index.
 *
 * @param string $tab    Tab.
 * @param array  $values Values.
 */
function meeha_home_settings_status( $tab, $values ) {
	global $wpdb;
	echo '<h2>অবস্থা</h2><table class="widefat striped" style="max-width:780px"><tbody>';
	$mode = 'legacy' === $values['view_mode'] ? 'পুরোনো পদ্ধতি (সার্ভারে)' : 'বীকন (সঠিক, ক্যাশ করা পাতাও)';
	echo '<tr><th>এখন ভিউ গণনা</th><td><strong>' . esc_html( $mode ) . '</strong></td></tr>';
	if ( function_exists( 'meeha_metrics_ready' ) && meeha_metrics_ready() ) {
		// 2.4.2: real view numbers instead of the old "ঘণ্টাভিত্তিক ভিউ-সারি", which was a ROW count (one row per
		// post per hour with any views) and was easily mistaken for a view count.
		$h     = meeha_metrics_table( 'hourly' );
		$rows  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$h}" ); // phpcs:ignore WordPress.DB.PreparedSQL
		$today = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(views),0) FROM {$h} WHERE `hour` >= %s", meeha_metrics_hour( meeha_metrics_local_midnight() ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$d7    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(views),0) FROM {$h} WHERE `hour` >= %s", meeha_metrics_hour( meeha_metrics_local_midnight() - 6 * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$life  = (int) $wpdb->get_var( "SELECT COALESCE(SUM(CAST(meta_value AS UNSIGNED)),0) FROM {$wpdb->postmeta} WHERE meta_key = '_meeha_views'" );
		$last  = get_option( 'meeha_metrics_last_run' );
		$lag   = function_exists( 'meeha_cron_lag' ) ? meeha_cron_lag() : 0;
		$resc  = get_option( 'meeha_cron_rescued' );
		echo '<tr><th>আজকের ভিউ</th><td><strong>' . esc_html( meeha_bn_digits( $today ) ) . '</strong> (রাত ১২টা থেকে)</td></tr>';
		echo '<tr><th>গত ৭ দিনের ভিউ</th><td>' . esc_html( meeha_bn_digits( $d7 ) ) . '</td></tr>';
		echo '<tr><th>সব সময়ের মোট ভিউ</th><td>' . esc_html( meeha_bn_digits( $life ) ) . ' <span class="description">(সব খবর, ফটোকার্ড ও ভিডিও)</span></td></tr>';
		echo '<tr><th>ঘণ্টার হিসাবের সারি</th><td>' . esc_html( meeha_bn_digits( $rows ) ) . ' <span class="description">— এটি ভিউ নয়: প্রতিটি খবরের প্রতি ঘণ্টার জন্য একটি সারি</span></td></tr>';
		if ( function_exists( 'meeha_live_summary' ) && meeha_live_enabled() ) {
			$live = meeha_live_summary( 0 );
			echo '<tr><th>এখন সাইটে</th><td><strong>' . esc_html( meeha_bn_digits( $live['now'] ) ) . ' জন</strong> · আজ ' . esc_html( meeha_bn_digits( $live['today'] ) ) . ' জন ভিজিটর</td></tr>';
		}
		echo '<tr><th>তালিকা শেষ হিসাব</th><td>' . esc_html( is_array( $last ) ? meeha_bengali_time_ago( (int) $last['time'], time() ) : 'এখনো হয়নি (WP-Cron প্রতি ১০ মিনিটে চলে)' ) . '</td></tr>';
		echo '<tr><th>WP-Cron</th><td>';
		if ( $lag > 15 * MINUTE_IN_SECONDS ) {
			echo '<strong style="color:#b32d2e">' . esc_html( meeha_bn_digits( (int) floor( $lag / 60 ) ) . ' মিনিট পিছিয়ে' ) . '</strong> — সার্ভার নিজের wp-cron.php ডাকতে পারছে না বা DISABLE_WP_CRON চালু। থিম এখন ভিজিটরের সংকেত থেকে নিজেই বকেয়া কাজ চালায়; তবু হোস্টিংয়ের Cron Jobs-এ প্রতি ৫ মিনিটে <code>wget -q -O - ' . esc_html( site_url( 'wp-cron.php?doing_wp_cron' ) ) . ' &gt;/dev/null</code> যোগ করা সবচেয়ে ভালো।';
		} else {
			echo 'ঠিক আছে';
		}
		if ( is_array( $resc ) ) {
			echo '<br><span class="description">থিম শেষবার নিজে চালিয়েছে: ' . esc_html( meeha_bengali_time_ago( (int) $resc['time'], time() ) ) . '</span>';
		}
		echo '</td></tr>';
	}
	if ( function_exists( 'meeha_search_index_status' ) ) {
		$s = meeha_search_index_status();
		echo '<tr><th>সার্চ ইনডেক্স</th><td>' . esc_html( meeha_bn_digits( $s['done'] ) . ' / ' . meeha_bn_digits( $s['total'] ) . ' লেখা তৈরি' );
		if ( current_user_can( 'manage_meeha_design' ) ) {
			echo ' <button type="submit" class="button" formaction="' . esc_url( admin_url( 'admin-post.php?action=meeha_search_reindex' ) ) . '">ইনডেক্স নতুন করে তৈরি করুন</button>';
		}
		echo '</td></tr>';
	}
	echo '</tbody></table>';
	$report = get_option( 'meeha_home_import_report' );
	if ( is_array( $report ) && $report ) {
		echo '<h2>পুরোনো হোমপেজ সেটিং থেকে আনা হয়েছে</h2><ul style="list-style:disc;padding-left:20px">';
		foreach ( $report as $line ) {
			echo '<li>' . esc_html( $line ) . '</li>';
		}
		echo '</ul>';
	}
}

/* --------------------------------------------------------------------------
 * Lead pin timestamp + cache busting on save
 * ------------------------------------------------------------------------ */

add_action(
	'meeha_settings_saved',
	function ( $tab, $clean, $old ) {
		if ( 'home' !== $tab ) {
			return;
		}
		$new_lead = isset( $clean['lead_post_id'] ) ? (int) $clean['lead_post_id'] : 0;
		$old_lead = isset( $old['lead_post_id'] ) ? (int) $old['lead_post_id'] : 0;
		if ( $new_lead && $new_lead !== $old_lead ) {
			update_option( 'meeha_home_lead_at', time(), false );
		}
		meeha_home_bust();
		if ( function_exists( 'meeha_metrics_flush_lists' ) ) {
			meeha_metrics_flush_lists();
			delete_transient( 'meeha_popular' );
		}
	},
	10,
	3
);

/* --------------------------------------------------------------------------
 * One-time import from the legacy option `meeha_homepage_settings` (never modified).
 * ------------------------------------------------------------------------ */

/**
 * Import legacy homepage settings into `meeha_opt_home` (first run only).
 *
 * @param bool $force Run even when already imported (tests).
 * @return array Report lines.
 */
function meeha_home_import_legacy( $force = false ) {
	if ( ! $force && get_option( 'meeha_home_imported' ) ) {
		return array();
	}
	$legacy = get_option( 'meeha_homepage_settings', array() );
	$report = array();
	$vals   = array();
	if ( is_array( $legacy ) && $legacy ) {
		$valid = function ( $id ) {
			$id = (int) $id;
			return ( $id > 0 && term_exists( $id, 'category' ) ) ? $id : 0;
		};
		$name  = function ( $id ) {
			$t = get_term( (int) $id, 'category' );
			return $t && ! is_wp_error( $t ) ? $t->name : (string) $id;
		};
		// Hero: the old lead category + highlight categories become the hero categories.
		$hero = array();
		foreach ( array( 'hero_category', 'hero_highlight_cat_1', 'hero_highlight_cat_2', 'hero_highlight_cat_3' ) as $k ) {
			if ( ! empty( $legacy[ $k ] ) && $valid( $legacy[ $k ] ) && ! in_array( (int) $legacy[ $k ], $hero, true ) ) {
				$hero[] = (int) $legacy[ $k ];
			}
		}
		if ( $hero ) {
			$vals['hero_cats'] = $hero;
			$report[]          = 'হিরো বিভাগ: ' . implode( ', ', array_map( $name, $hero ) ) . ' (ফাঁকা করলে সব বিভাগ থেকে লিড আসবে)।';
		}
		// Category sections 1–5 → the section whose default tree contains the category.
		$targets = array(
			'world_cats'        => array( 'world-politics', 'middle-est', 'asia' ),
			'inv_cats'          => array( 'search', 'meeha-special' ),
			'mb_cats'           => array( 'motherland' ),
			'op_editorial_cats' => array( 'editorial' ),
			'op_columnist_cats' => array( 'sub-editorial', 'readers-words' ),
			'trio_1'            => array( 'economy' ),
			'trio_2'            => array( 'sports' ),
			'trio_3'            => array( 'entertainment' ),
		);
		$labels  = array(
			'world_cats'        => 'বিশ্ব',
			'inv_cats'          => 'অনুসন্ধান',
			'mb_cats'           => 'মাতৃভূমি',
			'op_editorial_cats' => 'মতামত (সম্পাদকীয়)',
			'op_columnist_cats' => 'মতামত (কলাম)',
			'trio_1'            => 'তিন কলাম ১',
			'trio_2'            => 'তিন কলাম ২',
			'trio_3'            => 'তিন কলাম ৩',
			'trio_pool'         => 'তিন কলামের বিকল্প তালিকা',
		);
		$pool    = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			$s   = isset( $legacy[ 'section_' . $i ] ) && is_array( $legacy[ 'section_' . $i ] ) ? $legacy[ 'section_' . $i ] : array();
			$cid = isset( $s['category'] ) ? $valid( $s['category'] ) : 0;
			if ( ! $cid ) {
				continue;
			}
			$hit = '';
			foreach ( $targets as $key => $slugs ) {
				$roots = array();
				foreach ( $slugs as $slug ) {
					$rid = meeha_home_cat_id( $slug );
					if ( $rid ) {
						$roots[] = $rid;
					}
				}
				$tree = function_exists( 'meeha_metrics_cat_tree' ) ? meeha_metrics_cat_tree( $roots ) : $roots;
				if ( in_array( $cid, $tree, true ) ) {
					$hit = $key;
					break;
				}
			}
			if ( '' === $hit ) {
				$pool[] = $cid;
				$report[] = 'সেকশন ' . meeha_bn_digits( $i ) . ' (' . $name( $cid ) . ') → ' . $labels['trio_pool'] . '।';
				continue;
			}
			if ( in_array( $hit, array( 'trio_1', 'trio_2', 'trio_3' ), true ) ) {
				$vals[ $hit ] = $cid;
			} else {
				$cur = isset( $vals[ $hit ] ) ? $vals[ $hit ] : meeha_home_cats( $hit );
				if ( ! in_array( $cid, $cur, true ) ) {
					$cur[] = $cid;
				}
				$vals[ $hit ] = $cur;
			}
			$report[] = 'সেকশন ' . meeha_bn_digits( $i ) . ' (' . $name( $cid ) . ') → ' . $labels[ $hit ] . '।';
		}
		if ( $pool ) {
			$vals['trio_pool'] = array_values( array_unique( array_merge( $pool, meeha_home_cats( 'trio_pool' ) ) ) );
		}
		if ( ! empty( $legacy['most_read_section_heading'] ) ) {
			$vals['mostread_title'] = sanitize_text_field( $legacy['most_read_section_heading'] );
			$report[]               = 'সর্বাধিক পঠিত শিরোনাম: ' . $vals['mostread_title'] . '।';
		}
		if ( ! empty( $legacy['most_read_section_count'] ) ) {
			$vals['mostread_count'] = max( 3, min( 10, (int) $legacy['most_read_section_count'] ) );
		}
		if ( ! empty( $legacy['latest_count'] ) ) {
			$vals['latest_count'] = max( 5, min( 12, (int) $legacy['latest_count'] ) );
		}
		if ( isset( $legacy['tabbed_enabled'] ) || isset( $legacy['topicbox_enabled'] ) ) {
			$report[] = 'পুরোনো "টপিক বক্স" ও "ট্যাব সেকশন" নতুন নকশায় নেই — ট্রেন্ডিং, সর্বাধিক পঠিত ও জনপ্রিয় সেই কাজ করে।';
		}
	}
	if ( $vals ) {
		$stored = get_option( 'meeha_opt_home', array() );
		$stored = is_array( $stored ) ? $stored : array();
		// Never overwrite values the site owner already saved in the new tab.
		meeha_opt_update( 'home', array_merge( $vals, $stored ) );
	}
	update_option( 'meeha_home_imported', time(), true );
	update_option( 'meeha_home_import_report', $report, false );
	return $report;
}
add_action(
	'init',
	function () {
		if ( ! get_option( 'meeha_home_imported' ) ) {
			meeha_home_import_legacy();
		}
	},
	30
);
