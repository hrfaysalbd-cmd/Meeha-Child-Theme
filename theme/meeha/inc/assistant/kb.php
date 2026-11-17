<?php
/**
 * r47 — জিজ্ঞাসার ভান্ডার (knowledge base): the assistant now answers questions ABOUT Meeha itself —
 * "মিহা কি", "কিভাবে লেখা পাঠাব", "কিভাবে রিপোর্ট করব", যোগাযোগ, বিজ্ঞাপন, অ্যাপ — not only news queries.
 *
 * Three layers, first match wins:
 *   1. the desk's own entries (settings textarea, option meeha_opt_assistant[kb]) — "train/input" without code;
 *   2. built-in entries below, whose answers pull live text from the site's own pages where one exists
 *      (আমাদের সম্পর্কে, নীতিমালা পাতা, অ্যাপ পাতা), so the answer follows the site;
 *   3. nothing matched → the normal news intents run as before (intents.php).
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

/**
 * Find a published page by candidate slugs, then by a title fragment, then by a shortcode it contains.
 * Cached per request. Returns WP_Post|null.
 *
 * @param string[] $slugs     Slugs to try.
 * @param string   $title     Title fragment (Bengali ok), '' to skip.
 * @param string   $shortcode Shortcode tag the page holds, '' to skip.
 * @return WP_Post|null
 */
function meeha_as_find_page( $slugs, $title = '', $shortcode = '' ) {
	static $cache = array();
	$key = md5( wp_json_encode( array( $slugs, $title, $shortcode ) ) );
	if ( array_key_exists( $key, $cache ) ) {
		return $cache[ $key ];
	}
	$found = null;
	foreach ( (array) $slugs as $slug ) {
		$p = get_page_by_path( $slug );
		if ( $p && 'publish' === $p->post_status ) {
			$found = $p;
			break;
		}
	}
	if ( ! $found && '' !== $title ) {
		$q = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				's'              => $title,
				'orderby'        => 'menu_order',
			)
		);
		if ( $q && false !== mb_stripos( $q[0]->post_title, $title ) ) {
			$found = $q[0];
		}
	}
	if ( ! $found && '' !== $shortcode ) {
		$q = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				's'              => $shortcode,
			)
		);
		if ( $q && has_shortcode( (string) $q[0]->post_content, $shortcode ) ) {
			$found = $q[0];
		}
	}
	$cache[ $key ] = $found;
	return $found;
}

/**
 * A short readable excerpt of a page (for "মিহা কি" the আমাদের সম্পর্কে page speaks for itself).
 *
 * @param WP_Post $p     Page.
 * @param int     $words Word cap.
 * @return string
 */
function meeha_as_page_excerpt( $p, $words = 60 ) {
	$text = $p->post_excerpt ? $p->post_excerpt : $p->post_content;
	$text = wp_strip_all_tags( strip_shortcodes( (string) $text ) );
	$text = trim( preg_replace( '/\s+/u', ' ', $text ) );
	return $text ? wp_trim_words( $text, $words, '…' ) : '';
}

/**
 * Built-in KB entries. Each: patterns (normalised needles, ANY matches), text (string|callable),
 * links (array of [label, url|null]) — null links are dropped.
 *
 * @return array[]
 */
function meeha_as_kb_builtin() {
	$about   = meeha_as_find_page( array( 'about', 'about-us', 'amader-somporke' ), 'আমাদের সম্পর্কে' );
	$contact = meeha_as_find_page( array( 'contact', 'jogajog', 'contact-us' ), 'যোগাযোগ' );
	$ads_pg  = meeha_as_find_page( array( 'advertise', 'advertising-policy', 'biggapon' ), 'বিজ্ঞাপন' );
	$app_pg  = meeha_as_find_page( array( 'app', 'apps', 'meeha-app' ), 'অ্যাপ', 'meeha_app_landing' );
	$corr    = meeha_as_find_page( array( 'corrections-policy' ), 'সংশোধন নীতি' );
	$edit_pg = meeha_as_find_page( array( 'editorial-policy' ), 'সম্পাদকীয় নীতি' );
	$privacy = meeha_as_find_page( array( 'privacy', 'privacy-policy' ), 'গোপনীয়তা' );
	$site    = get_bloginfo( 'name' );
	$u       = function ( $p ) {
		return $p ? get_permalink( $p ) : null;
	};
	$campus  = function_exists( 'meeha_campus_url' ) ? meeha_campus_url() : null;
	$fb      = (string) meeha_opt( 'social', 'facebook', '' );
	$yt      = (string) meeha_opt( 'social', 'youtube', '' );
	$wa      = (string) meeha_opt( 'social', 'whatsapp', '' );

	return array(
		array(
			'id'       => 'about',
			'patterns' => array( 'মিহা কি', 'মিহা নিউজ কি', 'মিহা কী', 'মিহা নিউজ কী', 'আপনারা কারা', 'তোমরা কারা', 'তুমি কে', 'আপনি কে', 'এই সাইট কি', 'কে চালায়', 'সম্পর্কে জানতে', 'আমাদের সম্পর্কে', 'মিহার পরিচয়', 'পরিচিতি', 'about' ),
			'text'     => function () use ( $about, $site ) {
				$own = $about ? meeha_as_page_excerpt( $about ) : '';
				if ( $own ) {
					return $own;
				}
				return $site . ' একটি বাংলা সংবাদমাধ্যম — সারা দেশের সংবাদদাতাদের পাঠানো খবর, অনুসন্ধানী প্রতিবেদন, লাইভ আপডেট, ভিডিও আর ফ্যাক্টচেক নিয়ে কাজ করে। আমি "মিহা সহকারী" — এই সাইটের প্রকাশিত খবর থেকেই আপনার প্রশ্নের উত্তর দিই।';
			},
			'links'    => array( array( 'আমাদের সম্পর্কে', $u( $about ) ), array( 'সম্পাদকীয় নীতি', $u( $edit_pg ) ) ),
		),
		array(
			'id'       => 'write',
			'patterns' => array( 'লেখা পাঠাব', 'লেখা পাঠাবো', 'লেখা পাঠাতে', 'নিউজ পাঠাব', 'খবর পাঠাব', 'সংবাদ পাঠাব', 'প্রতিবেদন পাঠাব', 'লিখতে চাই', 'লেখক হতে', 'সংবাদদাতা হব', 'সংবাদদাতা হতে', 'প্রতিনিধি হতে', 'সাংবাদিক হতে', 'রিপোর্টার হতে', 'যুক্ত হতে চাই', 'কাজ করতে চাই' ),
			'text'     => 'মিহা নিউজে লেখা পাঠানোর দুটি পথ আছে। সংবাদদাতা হিসেবে যুক্ত হতে চাইলে মিহা সংবাদদাতা অ্যাপ থেকে আবেদন করুন — অনুমোদন হলে অ্যাপেই খবর, ছবি ও ভিডিও পাঠাতে পারবেন। আর কোনো ঘটনার গোপন তথ্য দিতে চাইলে "গোপন তথ্য দিন" পাতাটি ব্যবহার করুন; পরিচয় গোপন রাখা হয়।',
			'links'    => array( array( 'সংবাদদাতা অ্যাপ', $u( $app_pg ) ), array( 'গোপন তথ্য দিন', home_url( '/tips/' ) ), array( 'যোগাযোগ', $u( $contact ) ) ),
		),
		array(
			'id'       => 'report',
			'patterns' => array( 'রিপোর্ট করব', 'রিপোর্ট করবো', 'রিপোর্ট আনব', 'ভুল ধরব', 'ভুল পেলে', 'ভুল তথ্য', 'সংশোধনী', 'সংশোধন চাই', 'আপত্তি জানাব', 'অভিযোগ করব', 'অভিযোগ জানাব', 'ভুল সংশোধন' ),
			'text'     => 'কোনো খবরে ভুল পেলে সেই খবরের নিচের "রিপোর্ট করুন" বোতামটি চাপুন — নাম আর ফোন নম্বর দিলেই হয়, লগইন লাগে না। ডেস্ক যাচাই করে প্রয়োজনে সংশোধনী জুড়ে দেয়; কী নিয়মে সংশোধন হয় তা সংশোধন নীতিতে লেখা আছে।',
			'links'    => array( array( 'সংশোধন নীতি', $u( $corr ) ), array( 'যোগাযোগ', $u( $contact ) ) ),
		),
		array(
			'id'       => 'contact',
			'patterns' => array( 'যোগাযোগ', 'ইমেইল কি', 'ইমেইল কী', 'ফোন নম্বর', 'মোবাইল নম্বর', 'ঠিকানা কি', 'ঠিকানা কী', 'অফিস কোথায়', 'কথা বলতে চাই' ),
			'text'     => $contact ? 'যোগাযোগের ঠিকানা, ইমেইল ও ফোন নম্বর "যোগাযোগ" পাতায় আছে।' : 'যোগাযোগের তথ্য সাইটের ফুটারে ও "আমাদের সম্পর্কে" পাতায় পাবেন।',
			'links'    => array( array( 'যোগাযোগ', $u( $contact ) ), array( 'আমাদের সম্পর্কে', $u( $about ) ) ),
		),
		array(
			'id'       => 'ads',
			'patterns' => array( 'বিজ্ঞাপন দিতে', 'বিজ্ঞাপন দেব', 'বিজ্ঞাপন দিব', 'বিজ্ঞাপনের দাম', 'বিজ্ঞাপনের রেট', 'এড দিতে', 'অ্যাড দিতে', 'স্পনসর করতে', 'প্রচার করতে চাই', 'advertise' ),
			'text'     => 'মিহা নিউজে বিজ্ঞাপন দিতে চাইলে বিজ্ঞাপন পাতায় নিয়ম ও রেট দেখুন, অথবা যোগাযোগ পাতার ঠিকানায় লিখুন — দল থেকে দ্রুত উত্তর দেওয়া হয়।',
			'links'    => array( array( 'বিজ্ঞাপন', $u( $ads_pg ) ), array( 'যোগাযোগ', $u( $contact ) ) ),
		),
		array(
			'id'       => 'app',
			'patterns' => array( 'অ্যাপ ডাউনলোড', 'এপ ডাউনলোড', 'অ্যাপ কোথায়', 'অ্যাপ আছে', 'এপ আছে', 'অ্যাপ নামাব', 'মোবাইল অ্যাপ', 'app download' ),
			'text'     => 'মিহা সংবাদদাতা অ্যাপটি সংবাদদাতাদের জন্য — খবর, ছবি ও ভিডিও পাঠানো যায়। অ্যাপ পাতা থেকে ডাউনলোড করা যায়; পাঠকদের জন্য সাইটটিই ফোনে অ্যাপের মতো কাজ করে ("হোম স্ক্রিনে যোগ করুন")।',
			'links'    => array( array( 'অ্যাপ ডাউনলোড', $u( $app_pg ) ) ),
		),
		array(
			'id'       => 'tips',
			'patterns' => array( 'গোপন তথ্য', 'তথ্য দিতে চাই', 'তথ্য ফাঁস', 'টিপস দিতে', 'সোর্স হতে', 'নাম গোপন', 'পরিচয় গোপন' ),
			'text'     => 'কোনো অনিয়ম বা ঘটনার গোপন তথ্য দিতে "গোপন তথ্য দিন" পাতাটি ব্যবহার করুন। পরিচয় গোপন রাখা হয়, আর তথ্যটি সরাসরি সংশ্লিষ্ট জেলার ডেস্কে পৌঁছায়।',
			'links'    => array( array( 'গোপন তথ্য দিন', home_url( '/tips/' ) ) ),
		),
		array(
			'id'       => 'factcheck',
			'patterns' => array( 'ফ্যাক্টচেক', 'ফ্যাক্ট চেক', 'গুজব কিনা', 'সত্য কিনা', 'ভুয়া কিনা', 'যাচাই করে দাও', 'যাচাই করুন' ),
			'text'     => 'ভাইরাল দাবি সত্য না গুজব — মিহার ফ্যাক্টচেক দল তা যাচাই করে রায়সহ প্রকাশ করে। সব ফ্যাক্টচেক এক পাতায় আছে; নতুন কোনো দাবি যাচাই করাতে চাইলে গোপন তথ্য পাতায় লিংকসহ পাঠান।',
			'links'    => array( array( 'ফ্যাক্টচেক', home_url( '/factcheck/' ) ), array( 'যাচাইয়ের অনুরোধ', home_url( '/tips/' ) ) ),
		),
		array(
			'id'       => 'saved',
			'patterns' => array( 'সেভ করব', 'সেভ করে রাখব', 'বুকমার্ক', 'পরে পড়ব', 'পরে পড়ার' ),
			'text'     => 'যেকোনো খবরের "সেভ" বোতাম চাপলে খবরটি আপনার "সেভ করা খবর" তালিকায় জমা হয় — পরে এক জায়গা থেকেই পড়তে পারবেন, এই ফোন/ব্রাউজারেই থাকে।',
			'links'    => array( array( 'সেভ করা খবর', home_url( '/saved/' ) ) ),
		),
		array(
			'id'       => 'campus',
			'patterns' => array( 'শিক্ষা ক্যাম্পাস', 'ক্যাম্পাস কি', 'ক্যাম্পাস কী', 'চাকরির প্রস্তুতি', 'নির্বাচিত পাঠ', 'সিভি বানাব', 'সিভি টেমপ্লেট', 'কুইজ দিতে', 'মডেল টেস্ট' ),
			'text'     => 'মিহা ক্যাম্পাস হলো শিক্ষা ও ক্যারিয়ারের আলাদা অংশ — প্রতিদিনের নির্বাচিত পাঠ, চাকরির খবর, মডেল টেস্ট, কুইজ আর বাংলা সিভি বানানোর টুল সেখানে আছে।',
			'links'    => array( array( 'মিহা ক্যাম্পাস', $campus ) ),
		),
		array(
			'id'       => 'social',
			'patterns' => array( 'ফেসবুক পেজ', 'ইউটিউব চ্যানেল', 'হোয়াটসঅ্যাপ চ্যানেল', 'ফলো করব', 'সাবস্ক্রাইব করব' ),
			'text'     => 'মিহা নিউজকে সোশ্যাল মিডিয়াতেও পাবেন — নিচের লিংকগুলো থেকে ফলো করুন।',
			'links'    => array( array( 'ফেসবুক', $fb ? $fb : null ), array( 'ইউটিউব', $yt ? $yt : null ), array( 'হোয়াটসঅ্যাপ', $wa ? $wa : null ) ),
		),
		array(
			'id'       => 'privacy',
			'patterns' => array( 'গোপনীয়তা নীতি', 'প্রাইভেসি', 'আমার তথ্য কি', 'ডেটা নিরাপদ' ),
			'text'     => 'পাঠকের তথ্য কীভাবে রাখা হয় তা গোপনীয়তা নীতিতে লেখা আছে। এই চ্যাটের প্রশ্নগুলোও কারো নামে জমা হয় না।',
			'links'    => array( array( 'গোপনীয়তা নীতি', $u( $privacy ) ), array( 'সম্পাদকীয় নীতি', $u( $edit_pg ) ) ),
		),
	);
}

/**
 * The desk's own entries from the settings textarea. One per line:
 *   প্রশ্নের শব্দ, আরও শব্দ => উত্তরের লেখা => https://লিংক (লিংক বাদ দেওয়া যায়)
 *
 * @return array[] Same shape as meeha_as_kb_builtin().
 */
function meeha_as_kb_custom() {
	$raw = (string) meeha_opt( 'assistant', 'kb', '' );
	$out = array();
	foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $i => $line ) {
		$parts = array_map( 'trim', explode( '=>', $line ) );
		if ( count( $parts ) < 2 || '' === $parts[0] || '' === $parts[1] ) {
			continue;
		}
		$patterns = array();
		foreach ( explode( ',', $parts[0] ) as $p ) {
			$p = trim( $p );
			if ( '' !== $p ) {
				$patterns[] = $p;
			}
		}
		if ( ! $patterns ) {
			continue;
		}
		$url = isset( $parts[2] ) ? esc_url_raw( $parts[2] ) : '';
		$out[] = array(
			'id'       => 'custom' . $i,
			'patterns' => $patterns,
			'text'     => wp_strip_all_tags( $parts[1] ),
			'links'    => $url ? array( array( 'বিস্তারিত', $url ) ) : array(),
		);
	}
	return array_slice( $out, 0, 60 );
}

/**
 * Match the (normalised) question against the KB. Desk entries win.
 *
 * @param string $q Normalised question.
 * @return array|null Matched entry with resolved text + links.
 */
function meeha_as_kb_match( $q ) {
	if ( '' === $q ) {
		return null;
	}
	foreach ( array_merge( meeha_as_kb_custom(), meeha_as_kb_builtin() ) as $e ) {
		foreach ( $e['patterns'] as $needle ) {
			if ( meeha_as_has( $q, meeha_as_norm( $needle ) ) ) {
				$text  = is_callable( $e['text'] ) ? call_user_func( $e['text'] ) : (string) $e['text'];
				$links = array();
				foreach ( (array) $e['links'] as $l ) {
					if ( ! empty( $l[1] ) ) {
						$links[] = array( 't' => (string) $l[0], 'u' => (string) $l[1] );
					}
				}
				return array( 'id' => $e['id'], 'text' => $text, 'links' => $links );
			}
		}
	}
	return null;
}
