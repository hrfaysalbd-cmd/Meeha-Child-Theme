<?php
/**
 * Studio: card types, capability, settings tab `photocard`, option helpers, ad creatives, dates.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

/**
 * Card types shown as studio tabs (order = tab order).
 *
 * @return array id => [label, presets(id=>label), photo(bool), ad(bool)]
 */
function meeha_studio_types() {
	$types = array(
		'news'       => array(
			'label'   => 'নিউজ কার্ড',
			'presets' => meeha_studio_news_presets(),
			'photo'   => true,
			'ad'      => true,
		),
		'morning'    => array(
			'label'   => 'শুভ সকাল',
			'presets' => array( 'bhor' => 'মিহা ভোর', 'nilpar' => 'নীল পাড়', 'side' => 'পার্শ্ব-ফিতা' ),
			'photo'   => true,
			'ad'      => true,
		),
		'opinion'    => array(
			'label'   => 'মতামত',
			'presets' => array( 'blue' => 'মিহা নীল', 'navy' => 'রাতের নীল', 'paper' => 'সাদা কাগজ' ),
			'photo'   => false,
			'ad'      => true,
		),
		'feature'    => array(
			'label'   => 'ফিচার',
			'presets' => array( 'shade' => 'ছায়া', 'vf2' => 'দুই কোণ', 'band' => 'নীল পাটাতন' ),
			'photo'   => true,
			'ad'      => true,
		),
		'breaking'   => array(
			'label'   => 'ব্রেকিং / সর্বশেষ',
			'presets' => array( 'breaking' => 'ব্রেকিং (লাল)', 'latest' => 'সর্বশেষ (নীল)' ),
			'photo'   => true,
			'ad'      => true,
		),
		'quote'      => array(
			'label'   => 'উক্তি',
			'presets' => meeha_studio_quote_frames(),
			'photo'   => false,
			'ad'      => true,
		),
		'literature' => array(
			'label'   => 'সাহিত্য',
			'presets' => array( 'paper' => 'কাগজ', 'window' => 'জানালা ছবি', 'modern' => 'আধুনিক' ),
			'photo'   => true,
			'ad'      => true,
		),
		'poem'       => array(
			'label'   => 'কবিতা',
			'presets' => array( 'paper' => 'কাগজ ও লতা', 'night' => 'রাতের আকাশ', 'photo' => 'ছবির ওপর' ),
			'photo'   => true,
			'ad'      => true,
		),
		'birthday'   => array(
			'label'   => 'জন্মদিন',
			'presets' => array( 'confetti' => 'উৎসব', 'night' => 'তারার রাত', 'balloon' => 'বেলুন' ),
			'photo'   => true,
			'ad'      => true,
		),
		'day'        => array(
			'label'   => 'দিবস',
			'presets' => meeha_studio_day_themes(),
			'photo'   => true,
			'ad'      => true, // except the শহীদ দিবস theme (dignity rule, enforced by cards.js + studio).
		),
		'condolence' => array(
			'label'   => 'শোক',
			'presets' => array( 'dark' => 'কালো', 'light' => 'ধূসর-সাদা' ),
			'photo'   => true,
			'ad'      => false, // dignity rule: never an ad strip.
		),
		'category'   => array(
			'label'   => 'বিভাগ ফ্রেম',
			'presets' => array( 'shade' => 'ছায়া', 'vf2' => 'দুই কোণ', 'band' => 'নীল পাটাতন' ),
			'photo'   => true,
			'ad'      => true,
		),
	);
	foreach ( meeha_studio_deco_presets() as $type => $deco ) {
		if ( isset( $types[ $type ] ) ) {
			$types[ $type ]['presets'] += $deco;
		}
	}
	return apply_filters( 'meeha_studio_types', $types );
}

/**
 * রেডিমেড নকশা: built-in presets that are a stock layout plus a bundled PNG frame (assets/frames/png/).
 * The layout + file for each id live in cards.js (DECO); keep the ids in sync. Off with the readymade_frames setting.
 *
 * @return array type => [ preset => label ]
 */
function meeha_studio_deco_presets() {
	// Read the stored option directly: meeha_studio_opt() can fall back to the settings fields, which list these presets.
	$stored = get_option( 'meeha_opt_photocard', array() );
	if ( is_array( $stored ) && array_key_exists( 'readymade_frames', $stored ) && empty( $stored['readymade_frames'] ) ) {
		return array();
	}
	$news = array(
		'surjo'        => 'সূর্য নীল',
		'nokshi'       => 'নকশি লাল-সবুজ',
		'sonali'       => 'বিশেষ সোনালি',
		'sorboshesh'   => 'সর্বশেষ ট্যাগ',
		'breakingband' => 'ব্রেকিং ব্যান্ড',
		'exclusive'    => 'এক্সক্লুসিভ ট্যাগ',
		'special'      => 'মিহা স্পেশাল',
		'bracket'      => 'নীল কর্নার ব্র্যাকেট',
		'dheu'         => 'সবুজ ঢেউ',
		'dheubreaking' => 'ঢেউ ব্রেকিং',
	);
	return array(
		'news'       => $news,
		'feature'    => $news,
		'category'   => $news,
		'opinion'    => array(
			'sompadokiyo' => 'সম্পাদকীয়',
			'bishleshon'  => 'বিশ্লেষণ',
			'kolom'       => 'খোলা কলম',
		),
		'day'        => array(
			'bijoy'       => 'বিজয় দিবস (সবুজ পাড়)',
			'swadhinota'  => 'স্বাধীনতা দিবস (লাল-সবুজ পাড়)',
			'ekushe'      => 'অমর একুশে (বর্ণমালা ও মিনার)',
			'boishakhpar' => 'শুভ নববর্ষ (লাল ফোঁটা)',
			'eidpar'      => 'ঈদ মোবারক (সোনালি পাড়)',
			'sharodiya'   => 'শারদীয় শুভেচ্ছা (কাশফুল)',
		),
		'birthday'   => array(
			'utsob'  => 'কনফেটি ও বেলুন',
			'sonali' => 'সোনালি পাড়',
		),
		'condolence' => array(
			'kalopar'  => 'কালো পাড়',
			'konafita' => 'কোণের ফিতা (শ্রদ্ধাঞ্জলি)',
		),
	);
}

/**
 * নিউজ কার্ড presets (featured-image card used by the single-post download, CONTRACT §9.1/§9.3).
 *
 * @return array
 */
function meeha_studio_news_presets() {
	return array(
		'classic'  => 'ক্লাসিক',
		'overlay'  => 'ছায়া',
		'band'     => 'নীল পাটাতন',
		'frame'    => 'সাদা ফ্রেম',
		'split'    => 'পাশাপাশি',
		'headline' => 'বড় শিরোনাম',
	);
}

/**
 * দিবস themes (artwork drawn by cards.js).
 *
 * @return array
 */
function meeha_studio_day_themes() {
	return array(
		'generic'      => 'সাধারণ (মিহা নীল)',
		'matribhasha'  => 'শহীদ দিবস ও মাতৃভাষা',
		'independence' => 'স্বাধীনতা দিবস',
		'victory'      => 'বিজয় দিবস',
		'boishakh'     => 'পহেলা বৈশাখ',
		'eid'          => 'ঈদ',
		'puja'         => 'শারদীয় দুর্গোৎসব',
		'newyear'      => 'ইংরেজি নববর্ষ',
		'environment'  => 'পরিবেশ দিবস',
	);
}

/**
 * Default দিবস list: "নাম | উপশিরোনাম/তারিখ | থিম | বার্তা" per line.
 *
 * @return string
 */
function meeha_studio_default_days() {
	return implode(
		"\n",
		array(
			'শহীদ দিবস ও আন্তর্জাতিক মাতৃভাষা দিবস | ২১ ফেব্রুয়ারি | matribhasha | ভাষাশহীদদের প্রতি বিনম্র শ্রদ্ধা।',
			'আন্তর্জাতিক নারী দিবস | ৮ মার্চ | generic | সমতা, সম্মান আর সুযোগে এগিয়ে যাক প্রত্যেক নারী।',
			'মহান স্বাধীনতা ও জাতীয় দিবস | ২৬ মার্চ | independence | স্বাধীনতার বীর শহীদদের প্রতি গভীর শ্রদ্ধা।',
			'শুভ নববর্ষ | পহেলা বৈশাখ | boishakh | নতুন বছর বয়ে আনুক সুখ, শান্তি ও সমৃদ্ধি।',
			'মে দিবস | ১ মে | generic | শ্রমজীবী মানুষের অধিকার ও মর্যাদার প্রতি সংহতি।',
			'বিশ্ব পরিবেশ দিবস | ৫ জুন | environment | সবুজ পৃথিবী গড়ি, প্রকৃতিকে ভালোবাসি।',
			'ঈদ মোবারক | ঈদুল ফিতর | eid | ঈদের আনন্দ ছড়িয়ে পড়ুক সবার ঘরে ঘরে।',
			'ঈদ মোবারক | ঈদুল আজহা | eid | ত্যাগের মহিমায় উদ্ভাসিত হোক সবার জীবন।',
			'শারদীয় শুভেচ্ছা | শারদীয় দুর্গোৎসব | puja | উৎসবের আনন্দে ভরে উঠুক সবার জীবন।',
			'মহান বিজয় দিবস | ১৬ ডিসেম্বর | victory | বীর মুক্তিযোদ্ধা ও শহীদদের প্রতি গভীর শ্রদ্ধা।',
			'শুভ নববর্ষ | ইংরেজি নববর্ষ | newyear | নতুন বছর হোক শান্তি, সুস্থতা ও সাফল্যের।',
		)
	);
}

/**
 * Parsed দিবস presets from settings.
 *
 * @return array list of [title, subtitle, theme, message]
 */
function meeha_studio_days() {
	$themes = meeha_studio_day_themes();
	$out    = array();
	foreach ( preg_split( '/\r\n|\r|\n/', (string) meeha_studio_opt( 'days', meeha_studio_default_days() ) ) as $ln ) {
		$parts = array_map( 'trim', explode( '|', $ln ) );
		if ( '' === $parts[0] ) {
			continue;
		}
		$theme = isset( $parts[2] ) ? sanitize_key( $parts[2] ) : 'generic';
		$out[] = array(
			'title'    => sanitize_text_field( $parts[0] ),
			'subtitle' => isset( $parts[1] ) ? sanitize_text_field( $parts[1] ) : '',
			'theme'    => isset( $themes[ $theme ] ) ? $theme : 'generic',
			'message'  => isset( $parts[3] ) ? sanitize_text_field( $parts[3] ) : '',
		);
	}
	return $out;
}

/**
 * The seven উক্তি frames (term meta `frame` values on meeha_quote_type).
 *
 * @return array
 */
function meeha_studio_quote_frames() {
	return array(
		'politics'   => 'রাজনীতি',
		'faith'      => 'ধর্ম ও আধ্যাত্মিক',
		'literature' => 'সাহিত্য',
		'inspire'    => 'অনুপ্রেরণা',
		'history'    => 'ইতিহাস',
		'sports'     => 'খেলা',
		'opinion'    => 'মতামত',
	);
}

/**
 * Allowed canvas sizes.
 *
 * @param bool $only_enabled Respect the settings tab.
 * @return array 'WxH' => label
 */
function meeha_studio_sizes( $only_enabled = true ) {
	$all = array(
		'1080x1080' => '১০৮০ × ১০৮০ (বর্গ)',
		'1080x1350' => '১০৮০ × ১৩৫০ (পোর্ট্রেট)',
		'1200x630'  => '১২০০ × ৬৩০ (লিংক)',
		'1080x1920' => '১০৮০ × ১৯২০ (স্টোরি)',
	);
	if ( $only_enabled ) {
		if ( ! meeha_studio_opt( 'size_story', 1 ) ) {
			unset( $all['1080x1920'] );
		}
		if ( ! meeha_studio_opt( 'size_portrait', 1 ) ) {
			unset( $all['1080x1350'] );
		}
		if ( ! meeha_studio_opt( 'size_landscape', 1 ) ) {
			unset( $all['1200x630'] );
		}
	}
	return $all;
}

/* --------------------------------------------------------------------------
 * Capability `meeha_make_cards`
 * ------------------------------------------------------------------------ */

/**
 * Grant `meeha_make_cards` (administrator, meeha_publisher, editor, author). Never correspondent/subscriber.
 */
function meeha_studio_grant_caps() {
	foreach ( apply_filters( 'meeha_studio_card_roles', array( 'administrator', 'meeha_publisher', 'editor', 'author' ) ) as $role_name ) {
		$role = get_role( $role_name );
		if ( $role && ! $role->has_cap( 'meeha_make_cards' ) ) {
			$role->add_cap( 'meeha_make_cards' );
		}
	}
	update_option( 'meeha_studio_caps_ver', MEEHA_STUDIO_CAPS_VERSION . '|' . ( get_role( 'meeha_publisher' ) ? 'p' : '' ) );
}
add_action( 'after_switch_theme', 'meeha_studio_grant_caps' );
add_action(
	'init',
	function () {
		// Version bump, or the publisher role (newsroom-os) appeared after activation.
		$want = MEEHA_STUDIO_CAPS_VERSION . '|' . ( get_role( 'meeha_publisher' ) ? 'p' : '' );
		if ( get_option( 'meeha_studio_caps_ver' ) !== $want ) {
			meeha_studio_grant_caps();
		}
	},
	20
);

/**
 * May the user manage studio defaults (frames of others, built-in presets, ad strip)?
 *
 * @return bool
 */
function meeha_studio_can_manage() {
	return current_user_can( 'manage_meeha_design' ) || current_user_can( 'manage_options' );
}

/**
 * May the current user upload custom frames? Administrators always; others per settings roles.
 *
 * @return bool
 */
function meeha_studio_can_upload_frames() {
	if ( ! current_user_can( 'meeha_make_cards' ) ) {
		return false;
	}
	if ( current_user_can( 'manage_options' ) ) {
		return true;
	}
	$user = wp_get_current_user();
	foreach ( array( 'meeha_publisher', 'editor', 'author' ) as $role ) {
		if ( in_array( $role, (array) $user->roles, true ) && meeha_studio_opt( 'upload_role_' . $role, 'meeha_publisher' === $role ? 1 : 0 ) ) {
			return true;
		}
	}
	return false;
}

/* --------------------------------------------------------------------------
 * Settings tab `photocard`
 * ------------------------------------------------------------------------ */

/**
 * Default sortable list of built-in presets (ids "{type}-{preset}").
 *
 * @return array
 */
function meeha_studio_default_presets() {
	$out = array();
	foreach ( meeha_studio_types() as $type => $t ) {
		if ( 'quote' === $type ) {
			continue; // quote frames follow the quote type; always available.
		}
		foreach ( $t['presets'] as $pid => $label ) {
			$out[] = array(
				'id'      => $type . '-' . $pid,
				'label'   => $label,
				'enabled' => 1,
			);
		}
	}
	return $out;
}

/**
 * Field list for the settings tab (also the source of defaults when A's framework is missing).
 *
 * @return array
 */
function meeha_studio_settings_fields() {
	return array(
		array( 'type' => 'heading', 'label' => 'নিউজ কার্ড (পোস্টের ফটোকার্ড ডাউনলোড)' ),
		array(
			'key'     => 'news_default_frame',
			'type'    => 'select',
			'label'   => 'নিউজ কার্ডের ডিফল্ট ফ্রেম',
			'default' => 'classic',
			'options' => meeha_studio_news_presets() + ( meeha_studio_deco_presets()['news'] ?? array() ),
			'desc'    => 'পোস্টের ছবির কোণের ডাউনলোড বোতাম এই ফ্রেমে কার্ড বানায়; স্টুডিওতেও এটি আগে বাছাই থাকে।',
		),
		array(
			'key'     => 'news_default_size',
			'type'    => 'select',
			'label'   => 'পাঠকের ডাউনলোডের মাপ',
			'default' => '1080x1080',
			'options' => array( '1080x1080' => '১০৮০ × ১০৮০', '1080x1350' => '১০৮০ × ১৩৫০', '1200x630' => '১২০০ × ৬৩০' ),
		),
		array(
			'key'     => 'news_cta',
			'type'    => 'text',
			'label'   => 'কার্ডে লেখা (CTA)',
			'default' => 'বিস্তারিত কমেন্টে',
			'desc'    => 'পাঠকের ডাউনলোড করা নিউজ কার্ডে (আর স্টুডিওর নিউজ কার্ডে) ছোট বোতামের মতো বসে — যেমন "বিস্তারিত কমেন্টে"। খালি রাখলে দেখাবে না।',
		),
		array( 'type' => 'heading', 'label' => 'পাঠকের কার্ডে বিজ্ঞাপন' ),
		array(
			'key'     => 'news_reader_ad',
			'type'    => 'checkbox',
			'label'   => 'পাঠকের কার্ডে বিজ্ঞাপন',
			'default' => '' !== meeha_studio_legacy_reader_ad() ? 1 : 0,
			'desc'    => 'চালু থাকলে পাঠক যে নিউজ কার্ড ডাউনলোড করেন তার নিচে ১০৮০ × ১২০ বিজ্ঞাপন স্ট্রিপ বসে (১২০০ × ৬৩০ মাপে বসে না)।',
		),
		array(
			'key'   => 'news_reader_ad_image',
			'type'  => 'image',
			'label' => 'বিজ্ঞাপনের ছবি (১০৮০ × ১২০)',
			'desc'  => 'খালি থাকলে আগের থিমের ফটোকার্ড বিজ্ঞাপন, না থাকলে মিহা থিম › বিজ্ঞাপন › card_strip-এর ছবি বসে।',
		),
		array( 'type' => 'heading', 'label' => 'টেমপ্লেটের ডিফল্ট লেখা' ),
		array(
			'key'     => 'morning_titles',
			'type'    => 'sortable',
			'label'   => 'শুভ সকাল — শিরোনামের প্রিসেট',
			'default' => array(
				array( 'id' => 'shubho_shokal', 'label' => 'শুভ সকাল', 'enabled' => 1 ),
				array( 'id' => 'shubho_ratri', 'label' => 'শুভ রাত্রি', 'enabled' => 1 ),
				array( 'id' => 'shubho_juma', 'label' => 'শুভ জুমা', 'enabled' => 1 ),
			),
			'desc'    => 'প্রথম চালু শিরোনামটি ডিফল্ট; স্টুডিওতে বাকিগুলো এক ক্লিকে বসানো যায়।',
		),
		array( 'key' => 'default_credit', 'type' => 'text', 'label' => 'ছবির ডিফল্ট ক্রেডিট', 'default' => 'মিহা নিউজ' ),
		array( 'key' => 'opinion_label', 'type' => 'text', 'label' => 'মতামত কার্ডের লেবেল', 'default' => 'মতামত' ),
		array( 'key' => 'feature_kicker', 'type' => 'text', 'label' => 'ফিচার কার্ডের ডিফল্ট কিকার', 'default' => 'মিহা স্পেশাল' ),
		array( 'key' => 'literature_label', 'type' => 'text', 'label' => 'সাহিত্য কার্ডের ডিফল্ট লেবেল', 'default' => 'সাহিত্য', 'desc' => 'সাহিত্যের পোস্ট আনলে তার ধরন (গল্প, প্রবন্ধ…) নিজে থেকে বসে।' ),
		array( 'key' => 'recent_count', 'type' => 'number', 'label' => 'স্টুডিওতে সাম্প্রতিক পোস্ট (কয়টি দেখাবে)', 'default' => 5, 'min' => 3, 'max' => 20 ),
		array( 'key' => 'condolence_heading', 'type' => 'text', 'label' => 'শোক কার্ডের শিরোনাম', 'default' => 'গভীর শোক' ),
		array(
			'key'     => 'condolence_pre',
			'type'    => 'sortable',
			'label'   => 'শোক কার্ডের প্রথম লাইন (ঐচ্ছিক)',
			'default' => array(
				array( 'id' => 'inna', 'label' => 'ইন্না লিল্লাহি ওয়া ইন্না ইলাইহি রাজিউন', 'enabled' => 1 ),
				array( 'id' => 'shroddha', 'label' => 'গভীর শোক ও শ্রদ্ধাঞ্জলি', 'enabled' => 1 ),
			),
		),
		array( 'key' => 'condolence_message', 'type' => 'text', 'label' => 'শোক কার্ডের ডিফল্ট বার্তা', 'default' => 'তাঁর বিদেহী আত্মার শান্তি কামনা করি এবং শোকসন্তপ্ত পরিবারের প্রতি গভীর সমবেদনা জানাই।' ),
		array( 'key' => 'birthday_heading', 'type' => 'text', 'label' => 'জন্মদিন কার্ডের শিরোনাম', 'default' => 'শুভ জন্মদিন' ),
		array( 'key' => 'birthday_message', 'type' => 'text', 'label' => 'জন্মদিন কার্ডের ডিফল্ট বার্তা', 'default' => 'জন্মদিনে রইল অফুরান শুভকামনা — মিহা নিউজ পরিবার।' ),
		array(
			'key'     => 'days',
			'type'    => 'textarea',
			'label'   => 'দিবসের তালিকা',
			'default' => meeha_studio_default_days(),
			'desc'    => 'প্রতি লাইনে একটি: নাম | উপশিরোনাম বা তারিখ | থিম | বার্তা। থিম: generic, matribhasha, independence, victory, boishakh, eid, puja, newyear, environment। শহীদ দিবস (matribhasha) থিমে বিজ্ঞাপন বসে না।',
		),
		array( 'key' => 'watermark', 'type' => 'text', 'label' => 'কার্ডে সাইটের ঠিকানা', 'default' => 'meeha.news', 'desc' => 'মতামত/ফিচার/ব্রেকিং কার্ডের কোণে ছোট অক্ষরে বসে। খালি রাখলে দেখাবে না।' ),
		array(
			'key'     => 'logo_source',
			'type'    => 'radio',
			'label'   => 'কার্ডের লোগো',
			'default' => 'vector',
			'options' => array(
				'vector' => 'থিমের ভেক্টর লোগো (টেমপ্লেটের রঙে)',
				'custom' => 'সাইটের লোগো ছবি (কাস্টমাইজার › সাইট আইডেন্টিটি) — সাদা গোলাকার প্লেটের ওপর বসে',
			),
		),
		array(
			'key'   => 'logo_light',
			'type'  => 'image',
			'label' => 'হালকা/সাদা লোগো (গাঢ় পটভূমির জন্য)',
			'desc'  => 'ঐচ্ছিক। দিলে গাঢ় রঙের কার্ডে এটি প্লেট ছাড়া বসে; না দিলে সব কার্ডে সাইটের লোগো সাদা গোলাকার প্লেটের ওপর বসে।',
		),
		array( 'type' => 'heading', 'label' => 'বিল্ট-ইন ফ্রেম ও মাপ' ),
		array(
			'key'     => 'presets',
			'type'    => 'sortable',
			'label'   => 'বিল্ট-ইন ফ্রেম (চালু/বন্ধ, নাম)',
			'default' => meeha_studio_default_presets(),
			'desc'    => 'বন্ধ করা ফ্রেম স্টুডিওর ফ্রেম-তালিকায় দেখাবে না। প্রতিটি ধরনের অন্তত প্রথম ফ্রেমটি সবসময় থাকে।',
		),
		array( 'key' => 'size_portrait', 'type' => 'checkbox', 'label' => '১০৮০ × ১৩৫০ (পোর্ট্রেট) মাপ চালু', 'default' => 1 ),
		array( 'key' => 'size_landscape', 'type' => 'checkbox', 'label' => '১২০০ × ৬৩০ (লিংক প্রিভিউ) মাপ চালু', 'default' => 1 ),
		array( 'key' => 'size_story', 'type' => 'checkbox', 'label' => '১০৮০ × ১৯২০ (ফেসবুক/ইনস্টাগ্রাম স্টোরি) মাপ চালু', 'default' => 1, 'desc' => 'স্টোরিতে কার্ডটি মাঝখানে বসে, পেছনে তারই ঝাপসা রং; ওপরে লোগো, নিচে ছোট লেখা। অ্যাপের নিজের বোতামের জায়গা ফাঁকা থাকে।' ),
		array( 'key' => 'story_cta', 'type' => 'text', 'label' => 'স্টোরির নিচের লেখা', 'default' => 'বিস্তারিত পড়ুন meeha.news-এ', 'desc' => 'স্টোরি মাপে কার্ডের নিচে বসে। শোক কার্ডে বসে না।' ),
		array( 'key' => 'readymade_frames', 'type' => 'checkbox', 'label' => 'রেডিমেড নকশা ও ফ্রেম দেখান', 'default' => 1, 'desc' => 'নিউজ, ফিচার ও বিভাগে ১০টি, মতামতে ৩টি, দিবসে ৬টি, জন্মদিনে ২টি আর শোকে ২টি রেডিমেড নকশা (লেখা-ছবির লেআউটসহ), ব্রেকিং কার্ডে রেডিমেড ফ্রেম; চার মাপেই। বন্ধ করলে শুধু আগের বিল্ট-ইন নকশা আর আপলোড করা ফ্রেম থাকে।' ),
		array( 'key' => 'ad_default', 'type' => 'checkbox', 'label' => 'নতুন কার্ডে বিজ্ঞাপন স্ট্রিপ ডিফল্টে টিক দেওয়া থাকবে', 'default' => 0, 'desc' => 'শোক কার্ডে কখনো বিজ্ঞাপন বসে না।' ),
		array( 'type' => 'heading', 'label' => 'অনুমতি, ইতিহাস ও তারিখ' ),
		array( 'key' => 'upload_role_meeha_publisher', 'type' => 'checkbox', 'label' => 'প্রকাশক (meeha_publisher) নিজের ফ্রেম আপলোড করতে পারবেন', 'default' => 1, 'desc' => 'অ্যাডমিনিস্ট্রেটর সবসময় পারেন।' ),
		array( 'key' => 'upload_role_editor', 'type' => 'checkbox', 'label' => 'সম্পাদক (editor) নিজের ফ্রেম আপলোড করতে পারবেন', 'default' => 0 ),
		array( 'key' => 'upload_role_author', 'type' => 'checkbox', 'label' => 'লেখক (author) নিজের ফ্রেম আপলোড করতে পারবেন', 'default' => 0 ),
		array( 'key' => 'history', 'type' => 'number', 'label' => 'সাম্প্রতিক কার্ড (কয়টি দেখাবে)', 'default' => 12, 'min' => 0, 'max' => 48 ),
		array(
			'key'     => 'hijri_offset',
			'type'    => 'select',
			'label'   => 'হিজরি সমন্বয় (স্টুডিওর ডিফল্ট)',
			'default' => '',
			'options' => array(
				''   => 'হেডার সেটিং অনুসারে',
				'-1' => '−১ দিন',
				'0'  => '০ (বাংলাদেশ)',
				'1'  => '+১ দিন',
			),
		),
	);
}

add_action(
	'init',
	function () {
		if ( ! function_exists( 'meeha_register_settings_tab' ) ) {
			return;
		}
		meeha_register_settings_tab(
			'photocard',
			array(
				'title'  => 'ফটোকার্ড',
				'order'  => 50,
				'cap'    => 'design',
				'desc'   => 'ফটোকার্ড স্টুডিওর বিল্ট-ইন টেমপ্লেটের ডিফল্ট। কার্ড তৈরি করতে: ফটোকার্ড › স্টুডিও। বিজ্ঞাপন স্ট্রিপের ছবি: মিহা থিম › বিজ্ঞাপন (card_strip)।',
				'fields' => meeha_studio_settings_fields(),
			)
		);
	},
	5
);

/**
 * Read a studio option (A's meeha_opt when available; otherwise raw option + field defaults).
 *
 * @param string $key     Key.
 * @param mixed  $default Default.
 * @return mixed
 */
function meeha_studio_opt( $key, $default = null ) {
	if ( function_exists( 'meeha_opt' ) && function_exists( 'meeha_settings_tab' ) && meeha_settings_tab( 'photocard' ) ) {
		return meeha_opt( 'photocard', $key, $default );
	}
	$stored = get_option( 'meeha_opt_photocard', array() );
	if ( is_array( $stored ) && array_key_exists( $key, $stored ) ) {
		return $stored[ $key ];
	}
	foreach ( meeha_studio_settings_fields() as $f ) {
		if ( isset( $f['key'] ) && $key === $f['key'] && array_key_exists( 'default', $f ) ) {
			return $f['default'];
		}
	}
	return $default;
}

/**
 * Enabled entries of a sortable option.
 *
 * @param string $key Option key.
 * @return array id => label
 */
function meeha_studio_enabled_list( $key ) {
	$out = array();
	foreach ( (array) meeha_studio_opt( $key, array() ) as $item ) {
		if ( is_array( $item ) && ! empty( $item['enabled'] ) && isset( $item['id'] ) ) {
			$out[ (string) $item['id'] ] = isset( $item['label'] ) && '' !== $item['label'] ? (string) $item['label'] : (string) $item['id'];
		}
	}
	return $out;
}

/**
 * Built-in presets for one type after settings (labels/enabled). The first preset of a type is always kept.
 *
 * @param string $type Type.
 * @return array preset => label
 */
function meeha_studio_presets_for( $type ) {
	$types = meeha_studio_types();
	if ( ! isset( $types[ $type ] ) ) {
		return array();
	}
	$all = $types[ $type ]['presets'];
	if ( 'quote' === $type ) {
		return $all;
	}
	$conf = array();
	foreach ( (array) meeha_studio_opt( 'presets', array() ) as $item ) {
		if ( is_array( $item ) && isset( $item['id'] ) && 0 === strpos( (string) $item['id'], $type . '-' ) ) {
			$conf[ substr( (string) $item['id'], strlen( $type ) + 1 ) ] = $item;
		}
	}
	$out   = array();
	$first = true;
	foreach ( $all as $pid => $label ) {
		$c = isset( $conf[ $pid ] ) ? $conf[ $pid ] : null;
		if ( $first || ! $c || ! empty( $c['enabled'] ) ) {
			$out[ $pid ] = $c && ! empty( $c['label'] ) ? (string) $c['label'] : $label;
		}
		$first = false;
	}
	return $out;
}

/* --------------------------------------------------------------------------
 * Ad strip creatives, dates, logo
 * ------------------------------------------------------------------------ */

/**
 * Card ad-strip creatives (1080 × 120): A's `meeha_ad_creative('card_strip')` (single creative or a list), then the
 * legacy `meeha_photocard_settings.footer_ad_image` (read only), filterable.
 *
 * @return array list of [id, label, url, link, w, h]
 */
function meeha_studio_ad_creatives() {
	$out = array();
	if ( function_exists( 'meeha_ad_creative' ) ) {
		$c    = meeha_ad_creative( 'card_strip' );
		$list = ( is_array( $c ) && isset( $c['url'] ) ) ? array( $c ) : ( is_array( $c ) ? $c : array() );
		foreach ( $list as $i => $row ) {
			if ( is_array( $row ) && ! empty( $row['url'] ) ) {
				$out[] = array(
					'id'    => 'slot-' . $i,
					'label' => ! empty( $row['label'] ) ? (string) $row['label'] : 'বিজ্ঞাপন স্ট্রিপ (মিহা থিম › বিজ্ঞাপন)' . ( $i ? ' ' . ( $i + 1 ) : '' ),
					'url'   => esc_url_raw( $row['url'] ),
					'link'  => isset( $row['link'] ) ? esc_url_raw( $row['link'] ) : '',
					'w'     => isset( $row['w'] ) ? (int) $row['w'] : 1080,
					'h'     => isset( $row['h'] ) ? (int) $row['h'] : 120,
				);
			}
		}
	}
	$legacy = get_option( 'meeha_photocard_settings', array() );
	if ( is_array( $legacy ) && ! empty( $legacy['footer_ad_image'] ) ) {
		$out[] = array(
			'id'    => 'legacy',
			'label' => 'পুরোনো ফটোকার্ড বিজ্ঞাপন (আগের থিম)',
			'url'   => esc_url_raw( $legacy['footer_ad_image'] ),
			'link'  => '',
			'w'     => 1080,
			'h'     => 120,
		);
	}
	return apply_filters( 'meeha_studio_ad_creatives', $out );
}

/**
 * Legacy reader-card ad (old theme `meeha_photocard_settings`): image URL when it was switched on, else ''.
 *
 * @return string
 */
function meeha_studio_legacy_reader_ad() {
	$l = get_option( 'meeha_photocard_settings', array() );
	return ( is_array( $l ) && ! empty( $l['footer_ad_enabled'] ) && ! empty( $l['footer_ad_image'] ) ) ? esc_url_raw( $l['footer_ad_image'] ) : '';
}

/**
 * Ad strip for reader downloads: the tab's own image → legacy photocard ad → first card_strip creative.
 *
 * @return array|null url, fallback
 */
function meeha_studio_reader_ad() {
	$id = (int) meeha_studio_opt( 'news_reader_ad_image', 0 );
	$u  = $id ? wp_get_attachment_image_url( $id, 'full' ) : '';
	if ( $u ) {
		return array(
			'url'      => esc_url_raw( $u ),
			'fallback' => esc_url_raw( meeha_studio_original_url( $id ) ),
		);
	}
	$l = get_option( 'meeha_photocard_settings', array() );
	if ( is_array( $l ) && ! empty( $l['footer_ad_image'] ) ) {
		return array(
			'url'      => esc_url_raw( $l['footer_ad_image'] ),
			'fallback' => '',
		);
	}
	$ads = meeha_studio_ad_creatives();
	return $ads ? array(
		'url'      => $ads[0]['url'],
		'fallback' => '',
	) : null;
}

/**
 * One-time import (2.0.1): the old theme's reader-card ad — if it was switched on, the new "পাঠকের কার্ডে বিজ্ঞাপন"
 * is switched on with that image (when it is in the media library). The legacy option is only read.
 */
function meeha_studio_fx_import() {
	if ( get_option( 'meeha_studio_fx1' ) || ! function_exists( 'meeha_opt_update' ) ) {
		return;
	}
	$stored = get_option( 'meeha_opt_photocard', array() );
	$stored = is_array( $stored ) ? $stored : array();
	$upd    = array();
	$legacy = meeha_studio_legacy_reader_ad();
	if ( '' !== $legacy ) {
		if ( empty( $stored['news_reader_ad'] ) ) {
			$upd['news_reader_ad'] = 1;
		}
		if ( empty( $stored['news_reader_ad_image'] ) ) {
			$aid = (int) attachment_url_to_postid( $legacy );
			if ( $aid ) {
				$upd['news_reader_ad_image'] = $aid;
			}
		}
	}
	if ( $upd ) {
		meeha_opt_update( 'photocard', $upd );
	}
	update_option( 'meeha_studio_fx1', 1, false );
}
add_action( 'init', 'meeha_studio_fx_import', 30 );

/**
 * Unscaled original URL of an attachment (fallback when a CDN/WebP variant of the sized URL fails on the canvas).
 *
 * @param int $id Attachment id.
 * @return string
 */
function meeha_studio_original_url( $id ) {
	$u = function_exists( 'wp_get_original_image_url' ) ? wp_get_original_image_url( $id ) : '';
	return $u ? (string) $u : (string) wp_get_attachment_url( $id );
}

/**
 * Default Hijri offset: studio setting, else A's header setting, else 0.
 *
 * @return int
 */
function meeha_studio_hijri_offset() {
	$v = (string) meeha_studio_opt( 'hijri_offset', '' );
	if ( '' !== $v ) {
		return max( -1, min( 1, (int) $v ) );
	}
	return function_exists( 'meeha_opt' ) ? (int) meeha_opt( 'header', 'hijri_offset', 0 ) : 0;
}

/**
 * Dates for a Y-m-d (Asia/Dhaka) via A's meeha_dates().
 *
 * @param string|null $ymd    Date or null for today.
 * @param int|null    $offset Hijri offset.
 * @return array
 */
function meeha_studio_dates( $ymd = null, $offset = null ) {
	if ( null === $offset ) {
		$offset = meeha_studio_hijri_offset();
	}
	if ( function_exists( 'meeha_dates' ) ) {
		return meeha_dates( $ymd ? $ymd : null, (int) $offset );
	}
	return array( 'weekday' => '', 'gregorian' => '', 'bangla' => '', 'hijri' => '' );
}

/**
 * Today in Asia/Dhaka as Y-m-d.
 *
 * @return string
 */
function meeha_studio_today() {
	try {
		$d = new DateTime( 'now', new DateTimeZone( 'Asia/Dhaka' ) );
		return $d->format( 'Y-m-d' );
	} catch ( Exception $e ) {
		return gmdate( 'Y-m-d', time() + 6 * HOUR_IN_SECONDS );
	}
}

/**
 * Renderer configuration (window.MeehaCardsConfig) — shared by the studio and the front end.
 *
 * @return array
 */
function meeha_cards_config() {
	$logo = array( 'mode' => 'vector' );
	if ( 'custom' === meeha_studio_opt( 'logo_source', 'vector' ) ) {
		$id = (int) get_theme_mod( 'custom_logo' );
		$u  = $id ? wp_get_attachment_image_url( $id, 'full' ) : '';
		if ( $u ) {
			$logo = array(
				'mode'     => 'image',
				'url'      => esc_url_raw( $u ),
				'fallback' => esc_url_raw( meeha_studio_original_url( $id ) ),
			);
			$lid  = (int) meeha_studio_opt( 'logo_light', 0 );
			$lu   = $lid ? wp_get_attachment_image_url( $lid, 'full' ) : '';
			if ( $lu ) {
				$logo['light'] = array(
					'url'      => esc_url_raw( $lu ),
					'fallback' => esc_url_raw( meeha_studio_original_url( $lid ) ),
				);
			}
		}
	}
	$fonts = MEEHA_URI . '/assets/fonts/';
	return apply_filters(
		'meeha_cards_config',
		array(
			'base'      => MEEHA_URI . '/',
			'fonts'     => array(
				'serif'   => $fonts . 'meeha-serif-bn-700.woff2',
				'sans'    => $fonts . 'meeha-sans-bn-400.woff2',
				'sans600' => $fonts . 'meeha-sans-bn-600.woff2',
				'lit'     => $fonts . 'meeha-literary-bn-400.woff2',
			),
			'logo'      => $logo,
			'newsDefault' => (string) meeha_studio_opt( 'news_default_frame', 'classic' ),
			'watermark' => (string) meeha_studio_opt( 'watermark', 'meeha.news' ),
			'cta'       => (string) meeha_studio_opt( 'news_cta', 'বিস্তারিত কমেন্টে' ),
			'storyCta'  => (string) meeha_studio_opt( 'story_cta', 'বিস্তারিত পড়ুন meeha.news-এ' ),
		)
	);
}
