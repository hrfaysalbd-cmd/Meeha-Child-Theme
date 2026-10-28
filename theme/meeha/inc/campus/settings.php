<?php
/**
 * Settings tab `campus` (শিক্ষা ও ক্যাম্পাস): hub page + test mode, category mapping, masthead, home sections,
 * countdowns, employer/advertiser packages, partner box and footer note. Payment, test and quiz options live in the
 * plugin's own tab (প্রস্তুতি ও পেমেন্ট).
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

/**
 * Home sections (sortable defaults).
 *
 * @return array
 */
function meeha_campus_section_defaults() {
	$rows = array(
		'countdown' => 'কাউন্টডাউন (ফল/শেষ তারিখ)',
		'quiz'      => 'আজকের কুইজ',
		'mystats'   => 'আমার অগ্রগতি',
		'lesson'    => 'আজকের নির্বাচিত পাঠ',
		'news'      => 'শীর্ষ খবর',
		'ad'        => 'বিজ্ঞাপন (সেকশন ব্যানার)',
		'closing'   => 'আবেদন শেষ হচ্ছে',
		'featured'  => 'ফিচার্ড নিয়োগদাতা',
		'prep'      => 'পরীক্ষার প্রস্তুতি',
		'stories'   => 'সাফল্যের গল্প',
		'events'    => 'ইভেন্ট',
		'guides'    => 'ক্যারিয়ার গাইড',
		'alerts'    => 'জব অ্যালার্ট',
		'employer'  => 'নিয়োগদাতা/বিজ্ঞাপনদাতা',
		'cv'        => 'সিভি স্টুডিও',
		'consult'   => 'ক্যারিয়ার পরামর্শ',
		'pubs'      => 'প্রকাশনা',
	);
	$out  = array();
	foreach ( $rows as $id => $label ) {
		$out[] = array(
			'id'      => $id,
			'label'   => $label,
			'enabled' => 1,
		);
	}
	return $out;
}

/**
 * Register the tab.
 */
function meeha_campus_register_settings() {
	$date = function ( $v ) {
		$v = trim( (string) $v );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : '';
	};
	meeha_register_settings_tab(
		'campus',
		array(
			'title'    => 'শিক্ষা ও ক্যাম্পাস',
			'order'    => 45,
			'cap'      => 'design',
			'desc'     => 'চাকরি, পরীক্ষার প্রস্তুতি ও কুইজের সেকশন। একটি পাতায় [meeha_campus] শর্টকোড রেখে নিচে তার আইডি দিন। পেমেন্ট, টেস্ট ও কুইজের নিয়ম "প্রস্তুতি ও পেমেন্ট" ট্যাবে।',
			'fields'   => array(
				array( 'type' => 'heading', 'label' => 'সেকশনের পাতা' ),
				array( 'key' => 'hub_page', 'type' => 'post_id', 'label' => 'হাব পাতার আইডি', 'default' => 0, 'desc' => 'যে পাতায় [meeha_campus] আছে। পাতার ঠিকানাই সেকশনের ঠিকানা (যেমন /campus-test/); নিচের সব পাতা হবে /campus-test/jobs/ ইত্যাদি।' ),
				array( 'key' => 'beta', 'type' => 'checkbox', 'label' => 'পরীক্ষামূলক মোড', 'default' => 1, 'desc' => 'চালু থাকলে শুধু লগইন করা স্টাফ (লেখক বা তার ওপরের ভূমিকা) সেকশনটি দেখবেন; অন্যরা "পাওয়া যায়নি" দেখবে এবং সার্চ ইঞ্জিনকে noindex বলা হবে। লাইভ করার সময় বন্ধ করুন।' ),
				array( 'type' => 'heading', 'label' => 'বিভাগ (খবরের উৎস)' ),
				array( 'key' => 'cat_main', 'type' => 'category', 'label' => 'মূল বিভাগ', 'default' => 0, 'desc' => 'খালি = "শিক্ষা ও ক্যাম্পাস" নামের বিভাগ।' ),
				array( 'key' => 'cat_edu', 'type' => 'category', 'label' => 'শিক্ষা', 'default' => 0, 'desc' => 'নিচের সবগুলো খালি থাকলে মূল বিভাগের উপবিভাগ থেকে নাম মিলিয়ে নেওয়া হয়।' ),
				array( 'key' => 'cat_employment', 'type' => 'category', 'label' => 'নিয়োগ', 'default' => 0 ),
				array( 'key' => 'cat_career', 'type' => 'category', 'label' => 'ক্যারিয়ার', 'default' => 0 ),
				array( 'key' => 'cat_success', 'type' => 'category', 'label' => 'সাফল্যের গল্প', 'default' => 0 ),
				array( 'key' => 'cat_campus', 'type' => 'category', 'label' => 'ক্যাম্পাস', 'default' => 0 ),
				array( 'type' => 'heading', 'label' => 'মোবাইলে অ্যাপের মতো' ),
				array( 'key' => 'app_mode', 'type' => 'checkbox', 'label' => 'অ্যাপ মোড', 'default' => 1, 'desc' => 'চালু থাকলে মোবাইল ও ট্যাবে (১০২৪px-এর কম চওড়া পর্দায়) সেকশনের পাতায় খবরের সাইটের হেডার, মেনু, টিকার আর ফুটার লুকানো থাকে; ওপরে সেকশনের নিজস্ব বার আর "খবরে ফিরুন" বাটন দেখায়। ডেস্কটপ আগের মতো।' ),
				array( 'key' => 'news_url', 'type' => 'url', 'label' => '"খবরে ফিরুন" কোথায় নেবে', 'default' => '', 'desc' => 'খালি = সাইটের প্রথম পাতা।' ),
				array( 'key' => 'news_label', 'type' => 'text', 'label' => 'বাটনের লেখা', 'default' => 'খবরে ফিরুন' ),
				array( 'key' => 'app_name', 'type' => 'text', 'label' => 'ফোনে রাখলে অ্যাপের নাম', 'default' => 'মিহা ক্যাম্পাস', 'desc' => 'পাঠক "হোম স্ক্রিনে যোগ করুন" দিলে এই নামে আইকন বসে। আইকন হিসেবে সাইটের Site Icon (Appearance → Customize → Site Identity) ব্যবহার হয়।' ),
				array( 'type' => 'heading', 'label' => 'মাস্টহেড' ),
				array( 'key' => 'eyebrow', 'type' => 'text', 'label' => 'ওপরের ছোট লেখা', 'default' => 'মিহা নিউজ' ),
				array( 'key' => 'title', 'type' => 'text', 'label' => 'শিরোনাম', 'default' => 'শিক্ষা ও ক্যাম্পাস' ),
				array( 'key' => 'lede', 'type' => 'text', 'label' => 'উপশিরোনাম', 'default' => 'খবর, চাকরি আর পরীক্ষার প্রস্তুতি, এক জায়গায়' ),
				array( 'type' => 'heading', 'label' => 'হোম পাতা' ),
				array( 'key' => 'sections', 'type' => 'sortable', 'label' => 'অংশের ক্রম', 'default' => meeha_campus_section_defaults(), 'desc' => 'কোনো অংশে দেখানোর মতো কিছু না থাকলে (যেমন খোলা বিজ্ঞপ্তি বা ইভেন্ট নেই) সেটি নিজে থেকেই লুকিয়ে থাকে।' ),
				array( 'key' => 'news_count', 'type' => 'number', 'label' => 'শীর্ষ খবরে কয়টি', 'default' => 4, 'min' => 2, 'max' => 10 ),
				array(
					'key'    => 'countdowns',
					'type'   => 'repeater',
					'label'  => 'কাউন্টডাউন',
					'max'    => 8,
					'desc'   => 'যেমন: এইচএসসির ফল প্রকাশ, ভর্তি পরীক্ষা। তারিখ পেরোলে নিজে থেকেই সরে যায়। এর পাশে ৭ দিনের মধ্যে শেষ হওয়া চাকরির বিজ্ঞপ্তিও দেখায়।',
					'fields' => array(
						array( 'key' => 'label', 'type' => 'text', 'label' => 'কী' ),
						array( 'key' => 'date', 'type' => 'text', 'label' => 'তারিখ (YYYY-MM-DD)', 'sanitize' => $date ),
						array( 'key' => 'url', 'type' => 'url', 'label' => 'লিংক (খবর)' ),
					),
				),
				array( 'key' => 'whatsapp_url', 'type' => 'url', 'label' => 'হোয়াটসঅ্যাপ চ্যানেলের লিংক', 'default' => '', 'desc' => 'থাকলে জব অ্যালার্টের অংশে "হোয়াটসঅ্যাপে" বোতাম দেখায়।' ),
				array( 'type' => 'heading', 'label' => 'নিয়োগদাতা ও বিজ্ঞাপনদাতা' ),
				array(
					'key'     => 'job_packages',
					'type'    => 'repeater',
					'label'   => 'বিজ্ঞপ্তির প্যাকেজ',
					'max'     => 6,
					'default' => array(
						array( 'name' => 'সাধারণ বিজ্ঞপ্তি', 'price' => '', 'desc' => 'যাচাইয়ের পর চাকরির তালিকায় প্রকাশ, শেষ তারিখ পর্যন্ত।' ),
						array( 'name' => 'ফিচার্ড বিজ্ঞপ্তি', 'price' => '', 'desc' => 'তালিকার ওপরে ও হোম পাতার "ফিচার্ড নিয়োগদাতা" অংশে, নির্ধারিত দিন পর্যন্ত।' ),
					),
					'desc'    => 'দাম খালি রাখলে "যোগাযোগ করুন" দেখাবে।',
					'fields'  => array(
						array( 'key' => 'name', 'type' => 'text', 'label' => 'নাম' ),
						array( 'key' => 'price', 'type' => 'text', 'label' => 'দাম (যেমন ৳১,৫০০)' ),
						array( 'key' => 'desc', 'type' => 'text', 'label' => 'বিবরণ' ),
					),
				),
				array(
					'key'     => 'ad_packages',
					'type'    => 'repeater',
					'label'   => 'বিজ্ঞাপন ও স্পনসরশিপ',
					'max'     => 6,
					'default' => array(
						array( 'name' => 'দৈনিক কুইজ স্পনসরশিপ', 'price' => '', 'desc' => 'কুইজের পাতায় ও হোমের কুইজ অংশে "সৌজন্যে" লাইন ও লোগো।' ),
						array( 'name' => 'সেকশন ব্যানার', 'price' => '', 'desc' => 'শিক্ষা ও ক্যাম্পাসের পাতাগুলোতে ব্যানার ও তালিকার ভেতরের বিজ্ঞাপন।' ),
					),
					'fields'  => array(
						array( 'key' => 'name', 'type' => 'text', 'label' => 'নাম' ),
						array( 'key' => 'price', 'type' => 'text', 'label' => 'দাম' ),
						array( 'key' => 'desc', 'type' => 'text', 'label' => 'বিবরণ' ),
					),
				),
				array( 'key' => 'contact', 'type' => 'textarea', 'label' => 'বিজ্ঞাপন বিভাগের যোগাযোগ', 'default' => '', 'desc' => 'ফোন/ইমেইল; নিয়োগদাতার পাতার নিচে দেখায়।' ),
				array( 'type' => 'heading', 'label' => 'সহযোগী (ঐচ্ছিক)' ),
				array( 'key' => 'partner_title', 'type' => 'text', 'label' => 'শিরোনাম', 'default' => '' ),
				array( 'key' => 'partner_text', 'type' => 'textarea', 'label' => 'লেখা', 'default' => '', 'desc' => 'যেমন কোনো কোচিং বা প্রকাশনার সঙ্গে চুক্তি। খালি থাকলে অংশটি দেখায় না। বিজ্ঞাপন হলে "বিজ্ঞাপন" শব্দটি স্পষ্ট রাখুন।' ),
				array( 'key' => 'partner_url', 'type' => 'url', 'label' => 'লিংক', 'default' => '' ),
				array( 'key' => 'partner_label', 'type' => 'text', 'label' => 'বোতামের লেখা', 'default' => 'বিস্তারিত' ),
				array( 'type' => 'heading', 'label' => 'ফুটার' ),
				array( 'key' => 'footer_note', 'type' => 'textarea', 'label' => 'সেকশনের নিচের নোট', 'default' => 'চাকরির বিজ্ঞপ্তি প্রকাশের আগে মূল সূত্রের সঙ্গে মিলিয়ে নেওয়া হয়। তবু আবেদনের আগে মূল সার্কুলার দেখে নিন; মিহা নিউজ কোনো নিয়োগে মধ্যস্থতা করে না এবং কারও কাছে টাকা চায় না।' ),
			),
			'sanitize' => 'meeha_campus_settings_sanitize',
		)
	);
}
add_action( 'meeha_register_settings', 'meeha_campus_register_settings' );

/**
 * Tab sanitize: warn when the hub page lacks the shortcode; schedule a rewrite flush when it changes.
 *
 * @param array $clean Clean values.
 * @param array $raw   Raw.
 * @param array $old   Old.
 * @return array
 */
function meeha_campus_settings_sanitize( $clean, $raw, $old ) {
	$hub = isset( $clean['hub_page'] ) ? (int) $clean['hub_page'] : 0;
	if ( $hub ) {
		$p = get_post( $hub );
		if ( ! $p || 'page' !== $p->post_type ) {
			meeha_settings_notice( 'হাব পাতার আইডিটি কোনো পাতার নয়।' );
			$clean['hub_page'] = isset( $old['hub_page'] ) ? (int) $old['hub_page'] : 0;
		} elseif ( ! has_shortcode( $p->post_content, 'meeha_campus' ) ) {
			meeha_settings_notice( '"' . $p->post_title . '" পাতায় [meeha_campus] শর্টকোড নেই। শর্টকোডটি যোগ করুন, না হলে সেকশন দেখাবে না।', 'warning' );
		}
	}
	if ( ( isset( $old['hub_page'] ) ? (int) $old['hub_page'] : 0 ) !== (int) $clean['hub_page'] ) {
		update_option( 'meeha_campus_flush', 1, false );
	}
	return $clean;
}
