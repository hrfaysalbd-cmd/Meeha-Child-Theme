<?php
/**
 * Foundation settings tabs: হেডার, ফুটার, বিজ্ঞাপন, সোশ্যাল, পারফরম্যান্স, SEO (টুলস lives in tools.php).
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'MEEHA_CORRESPONDENT_APPLY_URL' ) ) {
	define( 'MEEHA_CORRESPONDENT_APPLY_URL', 'https://correspondent.meeha.news/apply' );
}

/**
 * Ad slot catalogue (DESIGN-SPEC §18).
 *
 * @return array id => [label, size(leader|mpu|half|strip), default_on]
 */
function meeha_ad_slots() {
	return apply_filters(
		'meeha_ad_slots',
		array(
			'header_leader'  => array( 'হেডারের নিচে (লিডারবোর্ড)', 'leader', 0 ),
			'home_leader'    => array( 'হোম — ট্রেন্ডিংয়ের পরে (লিডারবোর্ড)', 'leader', 1 ),
			'home_mpu_1'     => array( 'হোম — মাতৃভূমি পাশে (৩০০ × ২৫০)', 'mpu', 0 ),
			'home_mpu_2'     => array( 'হোম — মতামতের পরে, শুধু মোবাইল (৩০০ × ২৫০)', 'mpu', 0 ),
			'article_1'      => array( 'খবরের ভেতরে — ৩য় অনুচ্ছেদের পরে (৩০০ × ২৫০)', 'mpu', 0 ),
			'article_2'      => array( 'খবরের ভেতরে — ৮ম অনুচ্ছেদের পরে (৩০০ × ২৫০)', 'mpu', 0 ),
			'article_side'   => array( 'খবরের পাশে, ডেস্কটপ (৩০০ × ৬০০)', 'half', 0 ),
			'archive_infeed' => array( 'বিভাগ পাতা — ৬ নম্বর খবরের পরে', 'leader', 0 ),
			'archive_side'   => array( 'বিভাগ পাতার পাশে (৩০০ × ২৫০)', 'mpu', 0 ),
		)
	);
}

/**
 * Field list for the ads tab.
 *
 * @return array
 */
function meeha_ad_fields() {
	$date_sanitize = function ( $v ) {
		$v = trim( (string) $v );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : '';
	};
	$fields = array(
		array(
			'type'  => 'heading',
			'label' => 'বিজ্ঞাপনের নিয়ম',
			'desc'  => 'প্রতিটি স্লট একটি নির্দিষ্ট মাপের বাক্স — খালি বা বন্ধ থাকলে বাক্সটিই ছাপা হয় না (লেআউট সরে না)। অ্যানিমেটেড GIF ১৫০ KB-এর বেশি হলে গ্রহণ করা হয় না — WebP/MP4 করে আপলোড করুন। HTML কোড শুধু "unfiltered_html" অনুমতি থাকলে হুবহু সংরক্ষিত হয়; তৃতীয় পক্ষের স্ক্রিপ্ট পাতা লোড শেষে চলে।',
		),
	);
	foreach ( meeha_ad_slots() as $slot => $def ) {
		$fields[] = array( 'type' => 'heading', 'label' => $def[0], 'desc' => '<code>' . $slot . '</code>' );
		$fields[] = array( 'key' => $slot . '_on', 'type' => 'checkbox', 'label' => 'চালু', 'default' => (int) $def[2] );
		$fields[] = array(
			'key'     => $slot . '_type',
			'type'    => 'select',
			'label'   => 'ধরন',
			'default' => 'image',
			'options' => array(
				'image'     => 'ছবি + লিংক',
				'html'      => 'HTML কোড',
				'shortcode' => 'শর্টকোড (যেমন [adinserter block="1"])',
				'gam'       => 'Google Ad Manager ইউনিট',
			),
		);
		$fields[] = array( 'key' => $slot . '_image', 'type' => 'image', 'label' => 'ছবি (ডেস্কটপ / সব)' );
		if ( 'leader' === $def[1] ) {
			$fields[] = array( 'key' => $slot . '_image_m', 'type' => 'image', 'label' => 'ছবি (মোবাইল ৩২০ × ১০০)', 'desc' => 'ফাঁকা রাখলে ওপরের ছবিই মোবাইলে দেখাবে।' );
		}
		$fields[] = array( 'key' => $slot . '_link', 'type' => 'url', 'label' => 'লিংক' );
		$fields[] = array( 'key' => $slot . '_alt', 'type' => 'text', 'label' => 'ছবির বিকল্প লেখা', 'default' => 'বিজ্ঞাপন' );
		$fields[] = array( 'key' => $slot . '_code', 'type' => 'code', 'label' => 'HTML কোড' );
		$fields[] = array( 'key' => $slot . '_shortcode', 'type' => 'text', 'label' => 'শর্টকোড', 'placeholder' => '[adinserter block="1"]' );
		$fields[] = array( 'key' => $slot . '_gam', 'type' => 'text', 'label' => 'GAM ইউনিট পাথ', 'placeholder' => '/1234567/meeha_' . $slot );
		$fields[] = array(
			'key'     => $slot . '_device',
			'type'    => 'select',
			'label'   => 'ডিভাইস',
			'default' => 'all',
			'options' => array(
				'all'     => 'সব',
				'mobile'  => 'শুধু মোবাইল',
				'desktop' => 'শুধু ডেস্কটপ',
			),
		);
		$fields[] = array( 'key' => $slot . '_start', 'type' => 'text', 'label' => 'শুরুর তারিখ', 'placeholder' => 'YYYY-MM-DD', 'sanitize' => $date_sanitize );
		$fields[] = array( 'key' => $slot . '_end', 'type' => 'text', 'label' => 'শেষ তারিখ', 'placeholder' => 'YYYY-MM-DD', 'sanitize' => $date_sanitize );
		$fields[] = array( 'key' => $slot . '_label', 'type' => 'checkbox', 'label' => '"বিজ্ঞাপন" লেবেল দেখান', 'default' => 1 );
	}
	$fields[] = array(
		'type'  => 'heading',
		'label' => 'ফটোকার্ডের বিজ্ঞাপন স্ট্রিপ (card_strip)',
		'desc'  => '১০৮০ × ১২০ ছবি — ফটোকার্ড স্টুডিওতে "নিচে বিজ্ঞাপন স্ট্রিপ" হিসেবে বাছাই করা যায়।',
	);
	$fields[] = array(
		'key'    => 'card_strip',
		'type'   => 'repeater',
		'label'  => 'স্ট্রিপ ক্রিয়েটিভ',
		'max'    => 20,
		'fields' => array(
			array( 'key' => 'label', 'type' => 'text', 'label' => 'নাম' ),
			array( 'key' => 'image', 'type' => 'image', 'label' => 'ছবি (১০৮০ × ১২০)' ),
			array( 'key' => 'link', 'type' => 'url', 'label' => 'লিংক' ),
			array( 'key' => 'on', 'type' => 'checkbox', 'label' => 'চালু', 'default' => 1 ),
		),
	);
	return $fields;
}

/**
 * Ads tab sanitizer: rejects animated GIFs > 150 KB, warns about any creative > 150 KB.
 *
 * @param array $clean Clean values.
 * @param array $raw   Raw.
 * @param array $old   Old.
 * @return array
 */
function meeha_ads_sanitize( $clean, $raw, $old ) {
	$check = function ( $id ) {
		$id = (int) $id;
		if ( ! $id ) {
			return 'ok';
		}
		$file = get_attached_file( $id );
		if ( ! $file || ! file_exists( $file ) ) {
			return 'ok';
		}
		$size = filesize( $file );
		if ( $size > 150 * KB_IN_BYTES ) {
			return 'image/gif' === get_post_mime_type( $id ) ? 'reject' : 'warn';
		}
		return 'ok';
	};
	foreach ( $clean as $k => $v ) {
		if ( ! preg_match( '/_image(_m)?$/', $k ) ) {
			continue;
		}
		$r = $check( $v );
		if ( 'reject' === $r ) {
			$clean[ $k ] = isset( $old[ $k ] ) ? $old[ $k ] : 0;
			meeha_settings_notice( 'GIF ছবিটি ১৫০ KB-এর বেশি, তাই গ্রহণ করা হয়নি (' . $k . ')। WebP বা ছোট GIF ব্যবহার করুন।', 'error' );
		} elseif ( 'warn' === $r ) {
			meeha_settings_notice( 'সতর্কতা: ' . $k . ' ছবিটি ১৫০ KB-এর বেশি — পাতা ধীর হবে।', 'warning' );
		}
	}
	if ( ! empty( $clean['card_strip'] ) ) {
		foreach ( $clean['card_strip'] as $i => $row ) {
			if ( 'reject' === $check( isset( $row['image'] ) ? $row['image'] : 0 ) ) {
				$clean['card_strip'][ $i ]['image'] = 0;
				meeha_settings_notice( 'ফটোকার্ড স্ট্রিপের GIF ১৫০ KB-এর বেশি — গ্রহণ করা হয়নি।', 'error' );
			}
		}
	}
	return $clean;
}

meeha_register_settings_tab(
	'header',
	array(
		'title'  => 'হেডার',
		'order'  => 20,
		'cap'    => 'design',
		'fields' => array(
			array( 'type' => 'heading', 'label' => 'লোগো ও ট্যাগলাইন', 'desc' => 'ছবি না দিলে থিমের নিজস্ব SVG লোগো (নীল, অন্ধকার মোডে হালকা) দেখাবে।' ),
			array( 'key' => 'logo', 'type' => 'image', 'label' => 'প্রধান লোগো (SVG/PNG)', 'desc' => 'ডেস্কটপে ৩০০ × ৫১, মোবাইলে ১৪৮ × ২৫ জায়গায় বসবে।' ),
			array( 'key' => 'logo_compact', 'type' => 'image', 'label' => 'ছোট লোগো', 'desc' => 'স্টিকি মেনু ও মোবাইলের জন্য (ঐচ্ছিক)।' ),
			array( 'key' => 'logo_dark', 'type' => 'image', 'label' => 'অন্ধকার মোডের লোগো', 'desc' => 'হালকা রঙের লোগো (ঐচ্ছিক)।' ),
			array( 'key' => 'tagline', 'type' => 'text', 'label' => 'ট্যাগলাইন', 'default' => 'অনুসন্ধানী ও সর্বশেষ বাংলা সংবাদ' ),
			array( 'type' => 'heading', 'label' => 'তারিখ' ),
			array( 'key' => 'date_bangla', 'type' => 'checkbox', 'label' => 'বঙ্গাব্দ দেখান', 'default' => 1 ),
			array( 'key' => 'date_hijri', 'type' => 'checkbox', 'label' => 'হিজরি দেখান', 'default' => 1 ),
			array(
				'key'     => 'hijri_offset',
				'type'    => 'select',
				'label'   => 'হিজরি তারিখ সমন্বয়',
				'default' => '0',
				'options' => array(
					'-1' => '−১ দিন',
					'0'  => '০ (বাংলাদেশ, স্বাভাবিক)',
					'1'  => '+১ দিন',
				),
				'desc'    => 'চাঁদ দেখা কমিটির ঘোষণার সঙ্গে না মিললে সমন্বয় করুন। "০" = উম্ম আল-কুরা থেকে এক দিন পিছিয়ে (বাংলাদেশের স্বাভাবিক হিসাব)।',
			),
			array( 'type' => 'heading', 'label' => 'শিরোনাম-টিকার' ),
			array(
				'key'     => 'ticker_mode',
				'type'    => 'select',
				'label'   => 'টিকার মোড',
				'default' => 'auto',
				'cap'     => 'editorial',
				'options' => array(
					'auto'     => 'স্বয়ংক্রিয় (ব্রেকিং থাকলে ব্রেকিং, না থাকলে সর্বশেষ)',
					'breaking' => 'শুধু ব্রেকিং',
					'off'      => 'বন্ধ',
				),
			),
			array( 'key' => 'ticker_count', 'type' => 'number', 'label' => 'শিরোনামের সংখ্যা', 'default' => 8, 'min' => 3, 'max' => 15 ),
			array( 'key' => 'breaking_hours', 'type' => 'number', 'label' => 'ব্রেকিং কত ঘণ্টা থাকবে', 'default' => 6, 'min' => 1, 'max' => 72, 'unit' => 'ঘণ্টা' ),
			array( 'key' => 'ticker_speed', 'type' => 'number', 'label' => 'গতি (এক চক্র)', 'default' => 60, 'min' => 20, 'max' => 240, 'unit' => 'সেকেন্ড' ),
			array( 'key' => 'ticker_category', 'type' => 'category', 'label' => 'সর্বশেষ টিকারের বিভাগ', 'desc' => 'ফাঁকা = সব বিভাগ।' ),
			array( 'key' => 'latest_label', 'type' => 'text', 'label' => 'সর্বশেষ লেবেল', 'default' => 'সর্বশেষ' ),
			array( 'key' => 'breaking_label', 'type' => 'text', 'label' => 'ব্রেকিং লেবেল', 'default' => 'ব্রেকিং' ),
			array( 'type' => 'heading', 'label' => 'হেডারের বোতাম ও অ্যাকাউন্ট' ),
			array( 'key' => 'cta_label', 'type' => 'text', 'label' => 'বোতামের লেখা', 'default' => 'সংবাদদাতা হোন' ),
			array( 'key' => 'cta_url', 'type' => 'url', 'label' => 'বোতামের লিংক', 'default' => MEEHA_CORRESPONDENT_APPLY_URL ),
			array( 'key' => 'show_account', 'type' => 'checkbox', 'label' => 'অ্যাকাউন্ট আইকন দেখান', 'default' => 1 ),
			array( 'key' => 'sitemap_url', 'type' => 'url', 'label' => 'জাভাস্ক্রিপ্ট ছাড়া "সব বিভাগ" লিংক', 'desc' => 'ফাঁকা থাকলে ফুটারের বিভাগ-তালিকায় যাবে।' ),
			array(
				'type'  => 'heading',
				'label' => 'মেনু',
				'desc'  => 'মেনু বদলাতে <a href="' . esc_url( admin_url( 'nav-menus.php' ) ) . '">চেহারা › মেনু</a>-তে যান: প্রধান মেনু (primary), ড্রয়ার (drawer — সাব-মেনু থাকলে প্রতিটি মূল আইটেম একটি দল হয়), ফুটার কলাম ১–৩ (footer-1..3), নিচের আইনি লিংক (bottom-menu)।',
			),
		),
	)
);

meeha_register_settings_tab(
	'footer',
	array(
		'title'  => 'ফুটার',
		'order'  => 30,
		'cap'    => 'design',
		'fields' => array(
			array( 'key' => 'about', 'type' => 'textarea', 'label' => 'আমাদের কথা', 'default' => 'মিহা নিউজ একটি স্বাধীন বাংলা সংবাদমাধ্যম। ক্ষমতার জবাবদিহি, মানুষের অধিকার আর তথ্যের সত্যতা যাচাই আমাদের সাংবাদিকতার কেন্দ্রে।' ),
			array( 'key' => 'logo', 'type' => 'image', 'label' => 'ফুটারের লোগো (সাদা)', 'desc' => 'ফাঁকা = থিমের সাদা SVG লোগো।' ),
			array( 'type' => 'heading', 'label' => 'সম্পাদকীয় তথ্য', 'desc' => 'ফাঁকা ঘর ফুটারে দেখাবে না।' ),
			array( 'key' => 'editor', 'type' => 'text', 'label' => 'সম্পাদক' ),
			array( 'key' => 'publisher', 'type' => 'text', 'label' => 'প্রকাশক' ),
			array( 'key' => 'address', 'type' => 'text', 'label' => 'ঠিকানা' ),
			array( 'key' => 'phone', 'type' => 'text', 'label' => 'বার্তাকক্ষ (ফোন)' ),
			array( 'key' => 'email', 'type' => 'email', 'label' => 'ইমেইল' ),
			array( 'type' => 'heading', 'label' => 'নিউজলেটার (সাইটের একমাত্র সাবস্ক্রাইব ফর্ম)' ),
			array( 'key' => 'newsletter_enabled', 'type' => 'checkbox', 'label' => 'নিউজলেটার অংশ দেখান', 'default' => 1 ),
			array( 'key' => 'newsletter_form_id', 'type' => 'number', 'label' => 'MailPoet ফর্ম আইডি', 'default' => 1, 'min' => 0, 'max' => 99999, 'desc' => '০ = MailPoet নয়, থিমের নিজস্ব ফর্ম।' ),
			array( 'key' => 'newsletter_title', 'type' => 'text', 'label' => 'শিরোনাম', 'default' => 'সকালের একনজরে, আপনার ইনবক্সে' ),
			array( 'key' => 'newsletter_text', 'type' => 'textarea', 'label' => 'লেখা', 'rows' => 2, 'default' => 'প্রতিদিন সকাল ৭টায় দিনের গুরুত্বপূর্ণ খবর আর সপ্তাহের অনুসন্ধানী প্রতিবেদন। বিনা মূল্যে, যেকোনো সময় বাতিল করা যায়।' ),
			array( 'type' => 'heading', 'label' => 'সংবাদদাতা কার্ড' ),
			array( 'key' => 'corr_title', 'type' => 'text', 'label' => 'শিরোনাম', 'default' => 'আপনার এলাকার খবর পাঠান' ),
			array( 'key' => 'corr_text', 'type' => 'textarea', 'label' => 'লেখা', 'rows' => 2, 'default' => 'মিহা নিউজের সংবাদদাতা অ্যাপে খবর, ছবি ও ভিডিও পাঠান। সম্পাদকীয় যাচাইয়ের পর প্রকাশ হয়।' ),
			array( 'key' => 'corr_label', 'type' => 'text', 'label' => 'বোতাম', 'default' => 'সংবাদদাতা হোন' ),
			array( 'key' => 'corr_url', 'type' => 'url', 'label' => 'লিংক', 'default' => MEEHA_CORRESPONDENT_APPLY_URL ),
			array( 'type' => 'heading', 'label' => 'লিংক কলাম', 'desc' => 'লিংকগুলো আসে মেনু লোকেশন footer-1, footer-2, footer-3 থেকে (না থাকলে পুরোনো ফুটার সেটিংসের কলাম)।' ),
			array( 'key' => 'col1_title', 'type' => 'text', 'label' => 'কলাম ১', 'default' => 'সংবাদ' ),
			array( 'key' => 'col2_title', 'type' => 'text', 'label' => 'কলাম ২', 'default' => 'মতামত ও বিশেষ' ),
			array( 'key' => 'col3_title', 'type' => 'text', 'label' => 'কলাম ৩', 'default' => 'আরও' ),
			array( 'key' => 'social_title', 'type' => 'text', 'label' => 'সোশ্যাল অংশের শিরোনাম', 'default' => 'সঙ্গে থাকুন' ),
			array( 'type' => 'heading', 'label' => 'কপিরাইট ও আইনি লিংক' ),
			array( 'key' => 'copyright', 'type' => 'text', 'label' => 'কপিরাইট', 'default' => '© {year} মিহা নিউজ। সর্বস্বত্ব সংরক্ষিত।', 'desc' => '{year} = চলতি বছর (বাংলা অঙ্কে)।' ),
			array(
				'key'    => 'legal_links',
				'type'   => 'repeater',
				'label'  => 'আইনি লিংক (বিকল্প)',
				'desc'   => 'মেনু লোকেশন bottom-menu-এ মেনু থাকলে সেটিই দেখাবে; না থাকলে এই তালিকা।',
				'max'    => 12,
				'fields' => array(
					array( 'key' => 'label', 'type' => 'text', 'label' => 'লেখা' ),
					array( 'key' => 'url', 'type' => 'url', 'label' => 'লিংক' ),
				),
			),
		),
	)
);

meeha_register_settings_tab(
	'ads',
	array(
		'title'    => 'বিজ্ঞাপন',
		'order'    => 40,
		'cap'      => 'design',
		'fields'   => meeha_ad_fields(),
		'sanitize' => 'meeha_ads_sanitize',
	)
);

meeha_register_settings_tab(
	'social',
	array(
		'title'  => 'সোশ্যাল',
		'order'  => 60,
		'cap'    => 'design',
		'fields' => array(
			array( 'key' => 'facebook', 'type' => 'url', 'label' => 'ফেসবুক' ),
			array( 'key' => 'youtube', 'type' => 'url', 'label' => 'ইউটিউব' ),
			array( 'key' => 'x', 'type' => 'url', 'label' => 'এক্স (টুইটার)' ),
			array( 'key' => 'instagram', 'type' => 'url', 'label' => 'ইনস্টাগ্রাম' ),
			array( 'key' => 'whatsapp', 'type' => 'url', 'label' => 'হোয়াটসঅ্যাপ চ্যানেল' ),
			array( 'key' => 'telegram', 'type' => 'url', 'label' => 'টেলিগ্রাম' ),
			array( 'key' => 'x_handle', 'type' => 'text', 'label' => 'এক্স হ্যান্ডেল', 'placeholder' => '@meehanews' ),
			array( 'key' => 'og_image', 'type' => 'image', 'label' => 'ডিফল্ট শেয়ার-ছবি (OG)', 'desc' => '১২০০ × ৬৩০ — যে পাতায় নিজস্ব ছবি নেই।' ),
		),
	)
);

meeha_register_settings_tab(
	'performance',
	array(
		'title'  => 'পারফরম্যান্স',
		'order'  => 70,
		'cap'    => 'admin',
		'desc'   => 'LiteSpeed Cache থাকলে: "CSS Combine/Minify" ও "JS Combine" বন্ধ রাখুন বা এই থিমের ফাইল (assets/dist) বাদ দিন — থিম নিজেই বান্ডল ও মিনিফাই করে; ইনলাইন ক্রিটিক্যাল CSS (&lt;style id="meeha-critical"&gt;) LiteSpeed-এর "Load CSS Asynchronously/UCSS" থেকে বাদ দিন; থিম পরিবর্তনের পর সব ক্যাশ পার্জ করুন।',
		'fields' => array(
			array( 'key' => 'critical_css', 'type' => 'checkbox', 'label' => 'ক্রিটিক্যাল CSS ইনলাইন', 'default' => 1, 'desc' => 'বন্ধ করলে সব CSS সাধারণ স্টাইলশিট হিসেবে লোড হবে।' ),
			array( 'key' => 'preload_fonts', 'type' => 'checkbox', 'label' => 'প্রধান দুই ফন্ট আগে লোড (preload)', 'default' => 1 ),
			array( 'key' => 'disable_emoji', 'type' => 'checkbox', 'label' => 'ইমোজি স্ক্রিপ্ট বন্ধ', 'default' => 1 ),
			array( 'key' => 'disable_embeds', 'type' => 'checkbox', 'label' => 'wp-embed বন্ধ', 'default' => 1 ),
			array( 'key' => 'trim_block_css', 'type' => 'checkbox', 'label' => 'ব্লক CSS শুধু কনটেন্ট পাতায়', 'default' => 1, 'desc' => 'হোম, আর্কাইভ, সার্চে wp-block-library, classic-theme-styles, global-styles বাদ।' ),
			array( 'key' => 'mailpoet_trim', 'type' => 'checkbox', 'label' => 'MailPoet-এর ফন্ট ও CSS বাদ', 'default' => 1, 'desc' => 'ফর্মের নকশা থিমের ফুটার CSS-এ।' ),
			array( 'key' => 'bangla_fonts_trim', 'type' => 'checkbox', 'label' => 'bangla-fonts-collection প্লাগইনের ফন্ট বাদ', 'default' => 1 ),
			array( 'key' => 'jquery_trim', 'type' => 'checkbox', 'label' => 'দরকার না হলে jQuery বাদ', 'default' => 1, 'desc' => 'কোনো এনকিউ করা স্ক্রিপ্ট jQuery-র ওপর নির্ভর করলে jQuery থেকে যায়।' ),
			array( 'key' => 'delay_gtag', 'type' => 'checkbox', 'label' => 'Google Analytics (Site Kit) দেরিতে লোড', 'default' => 0, 'desc' => 'প্রথম ক্লিক/স্ক্রল বা ৫ সেকেন্ড পর gtag চালু হবে। ঝুঁকি: ৫ সেকেন্ডের আগে পাতা ছেড়ে যাওয়া পাঠক গোনা হবে না (বাউন্স কম দেখাবে)।' ),
			array( 'key' => 'gtag_delay', 'type' => 'number', 'label' => 'সর্বোচ্চ দেরি', 'default' => 5, 'min' => 1, 'max' => 15, 'unit' => 'সেকেন্ড' ),
			array( 'key' => 'elementor_block', 'type' => 'checkbox', 'label' => 'থিমের নিজস্ব পাতায় Elementor-এর CSS/JS বাদ', 'default' => 1 ),
		),
	)
);

meeha_register_settings_tab(
	'seo',
	array(
		'title'  => 'SEO',
		'order'  => 80,
		'cap'    => 'editorial',
		'desc'   => 'শিরোনাম, মেটা ও স্কিমা Rank Math দেয়; এখানে শুধু থিমের অংশ।',
		'fields' => array(
			array( 'key' => 'breadcrumb', 'type' => 'checkbox', 'label' => 'ব্রেডক্রাম্ব দেখান', 'default' => 1 ),
			array( 'key' => 'google_news_url', 'type' => 'url', 'label' => 'Google News পাতার লিংক', 'desc' => 'দিলে ফুটারে "Google News-এ অনুসরণ করুন" লিংক দেখাবে।' ),
			array( 'key' => 'news_name', 'type' => 'text', 'label' => 'প্রকাশনার নাম (Google News)', 'default' => 'মিহা নিউজ' ),
			array( 'key' => 'schema_logo', 'type' => 'image', 'label' => 'স্কিমার লোগো' ),
		),
	)
);
