<?php
/**
 * r49 — pages for advertisers.
 *
 *   [meeha_advertise]  a full landing page: why advertise on Meeha News, where ads appear,
 *                      formats, how a campaign runs, reporting, policy summary and contact.
 *                      Optional attributes: visitors="…" followers="…" (shown only when given,
 *                      so the page never prints an invented audience number), phone, whatsapp, email.
 *   [meeha_ad_specs]   the size / format / file rules for designers, plus the policy summary.
 *
 * Put either shortcode on a page; with the "পাতা — ফাঁকা ক্যানভাস" template the page has no site
 * header or footer. Every number the page shows is counted from the site itself.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

/**
 * Every placement with its sizes and formats (also read by newsroom-os for the publisher's size guide).
 *
 * @return array[] each: id, name, where, desktop, mobile, formats, note, group (web|video|card)
 */
function meeha_ad_specs() {
	$img  = 'JPG, PNG, WebP · অ্যানিমেটেড GIF সর্বোচ্চ ১৫০ KB · HTML5 / বিজ্ঞাপন নেটওয়ার্কের কোড';
	$rows = array(
		array( 'id' => 'header_leader', 'group' => 'web', 'name' => 'হেডার ব্যানার', 'where' => 'প্রতিটি পাতার ওপরে, মেনুর ঠিক নিচে', 'desktop' => '৯৭০ × ৯০ বা ৭২৮ × ৯০', 'mobile' => '৩২০ × ৫০ বা ৩২০ × ১০০', 'formats' => $img, 'note' => 'সবচেয়ে বেশি দেখা জায়গা। মোবাইলের জন্য আলাদা ছবি দিন।' ),
		array( 'id' => 'home_leader', 'group' => 'web', 'name' => 'প্রথম পাতার ব্যানার', 'where' => 'প্রথম পাতায় ট্রেন্ডিংয়ের পরে', 'desktop' => '৯৭০ × ৯০ বা ৭২৮ × ৯০', 'mobile' => '৩২০ × ৫০ বা ৩২০ × ১০০', 'formats' => $img, 'note' => '' ),
		array( 'id' => 'article_1', 'group' => 'web', 'name' => 'খবরের ভেতরে', 'where' => 'খবরের ৩য় ও ৮ম অনুচ্ছেদের পরে, খবরের শেষে', 'desktop' => '৩০০ × ২৫০', 'mobile' => '৩০০ × ২৫০', 'formats' => $img, 'note' => 'পাঠক যখন পড়ছেন, ঠিক তখন চোখে পড়ে।' ),
		array( 'id' => 'article_side', 'group' => 'web', 'name' => 'খবরের পাশে (হাফ পেজ)', 'where' => 'ডেস্কটপে খবরের ডান পাশে', 'desktop' => '৩০০ × ৬০০ (৩০০ × ২৫০-ও চলে)', 'mobile' => '—', 'formats' => $img, 'note' => 'বড় ও নজরকাড়া, শুধু ডেস্কটপ।' ),
		array( 'id' => 'home_mpu_1', 'group' => 'web', 'name' => 'প্রথম পাতা ও বিভাগ পাতার পাশে', 'where' => 'প্রথম পাতার মাঝে, বিভাগ পাতার পাশে', 'desktop' => '৩০০ × ২৫০', 'mobile' => '৩০০ × ২৫০', 'formats' => $img, 'note' => '' ),
		array( 'id' => 'archive_infeed', 'group' => 'web', 'name' => 'বিভাগ পাতার খবরের সারিতে', 'where' => 'বিভাগ পাতায় ৬ নম্বর খবরের পরে', 'desktop' => '৯৭০ × ৯০ বা ৭২৮ × ৯০', 'mobile' => '৩২০ × ১০০', 'formats' => $img, 'note' => '' ),
		array( 'id' => 'footer_leader', 'group' => 'web', 'name' => 'ফুটারের ওপরে', 'where' => 'প্রতিটি পাতার শেষে', 'desktop' => '৯৭০ × ৯০ বা ৭২৮ × ৯০', 'mobile' => '৩২০ × ৫০ বা ৩২০ × ১০০', 'formats' => $img, 'note' => '' ),
		array( 'id' => 'float_bottom', 'group' => 'web', 'name' => 'ভাসমান বার (ক্রস সহ)', 'where' => 'পর্দার নিচে লেগে থাকে, পাঠক ক্রস চাপলে সরে যায়', 'desktop' => '৭২৮ × ৯০', 'mobile' => '৩২০ × ৫০', 'formats' => $img, 'note' => 'স্ক্রল করলেও চোখের সামনে থাকে।' ),
		array( 'id' => 'float_side', 'group' => 'web', 'name' => 'ভাসমান কোণা (ক্রস সহ)', 'where' => 'ডেস্কটপে নিচের ডান কোণায়', 'desktop' => '৩০০ × ২৫০', 'mobile' => '—', 'formats' => $img, 'note' => '' ),
		array( 'id' => 'popup', 'group' => 'web', 'name' => 'পপ-আপ (ক্রস সহ)', 'where' => 'পাতা খোলার কয়েক সেকেন্ড পর মাঝখানে; একজন পাঠক দিনে সীমিতবার দেখেন', 'desktop' => '৬০০ × ৫০০', 'mobile' => '৩২০ × ৪৮০', 'formats' => $img, 'note' => 'সবচেয়ে জোরালো উপস্থিতি — উৎসব, অফার, উদ্বোধনের জন্য।' ),
		array( 'id' => 'video_corner', 'group' => 'video', 'name' => 'ভিডিওর কোণায় লোগো-বিজ্ঞাপন', 'where' => 'মিহার প্রতিটি নিউজ ভিডিওতে, মিহার লোগোর উল্টো কোণায়', 'desktop' => '৬০০ × ২৪০ (৫:২) বা ৪০০ × ৪০০', 'mobile' => 'একই ছবি', 'formats' => 'স্বচ্ছ পটভূমির PNG (সর্বোচ্চ ৩০০ KB)', 'note' => 'লোগোর মতো ছোট আকারে পুরো ভিডিওজুড়ে বা নির্দিষ্ট সময় পরপর দেখায়।' ),
		array( 'id' => 'video_footer', 'group' => 'video', 'name' => 'ভিডিওর নিচের ব্যানার', 'where' => 'ভিডিও ফ্রেমের নিচের পট্টিতে', 'desktop' => '১৯২০ × ১২০ (ইউটিউব ১৬:৯)', 'mobile' => '১০৮০ × ১৭০ (রিল ৯:১৬)', 'formats' => 'JPG বা PNG', 'note' => 'অথবা শুধু লেখা — চলমান বিজ্ঞাপন বার্তা।' ),
		array( 'id' => 'video_spot', 'group' => 'video', 'name' => 'ভিডিওর মাঝে বিজ্ঞাপন (স্পট)', 'where' => 'নিউজ ভিডিওর শুরুতে, মাঝে বা শেষে', 'desktop' => '১৯২০ × ১০৮০ (১৬:৯)', 'mobile' => '১০৮০ × ১৯২০ (৯:১৬)', 'formats' => 'MP4 (H.264), ৫–৩০ সেকেন্ড', 'note' => 'ছবি বা ভিডিওর ছোট অংশ হিসেবেও বসানো যায় (পিকচার-ইন-পিকচার)।' ),
		array( 'id' => 'card_strip', 'group' => 'card', 'name' => 'ফটোকার্ডের বিজ্ঞাপন স্ট্রিপ', 'where' => 'মিহার ফটোকার্ড ও পাঠকের ডাউনলোড করা নিউজ/উক্তি কার্ডের নিচে', 'desktop' => '১০৮০ × ১২০', 'mobile' => 'একই ছবি', 'formats' => 'JPG বা PNG', 'note' => 'কার্ড যতবার শেয়ার হয়, বিজ্ঞাপনও ততবার যায়।' ),
		array( 'id' => 'sponsored', 'group' => 'card', 'name' => 'স্পনসর্ড কনটেন্ট', 'where' => 'প্রতিবেদন, ভিডিও বা ফটোকার্ড — "পৃষ্ঠপোষকতায়" চিহ্নসহ', 'desktop' => 'আলোচনা সাপেক্ষে', 'mobile' => '—', 'formats' => 'লেখা, ছবি, ভিডিও', 'note' => 'সম্পাদকীয় নীতি মেনে, স্পষ্ট চিহ্ন দিয়ে।' ),
	);
	return apply_filters( 'meeha_ad_specs', $rows );
}

/**
 * Contact for the ad desk: the ads tab's own fields, else the footer's phone/email.
 *
 * @param array $atts Shortcode attributes (phone, whatsapp, email override).
 * @return array phone, whatsapp, email
 */
function meeha_advertise_contact( $atts = array() ) {
	$o     = function_exists( 'meeha_opt' ) ? meeha_opt( 'ads' ) : array();
	$phone = ! empty( $atts['phone'] ) ? $atts['phone'] : ( ! empty( $o['ad_phone'] ) ? $o['ad_phone'] : (string) meeha_opt( 'footer', 'phone', '' ) );
	$wa    = ! empty( $atts['whatsapp'] ) ? $atts['whatsapp'] : ( ! empty( $o['ad_whatsapp'] ) ? $o['ad_whatsapp'] : '' );
	$email = ! empty( $atts['email'] ) ? $atts['email'] : ( ! empty( $o['ad_email'] ) ? $o['ad_email'] : (string) meeha_opt( 'footer', 'email', '' ) );
	return array(
		'phone'    => trim( (string) $phone ),
		'whatsapp' => preg_replace( '/\D+/', '', meeha_bn_to_latin_digits( (string) $wa ) ),
		'email'    => sanitize_email( (string) $email ),
	);
}

/**
 * Bengali digits → Latin (for tel:/wa.me links).
 *
 * @param string $s Text.
 * @return string
 */
function meeha_bn_to_latin_digits( $s ) {
	return strtr( (string) $s, array( '০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9' ) );
}

/**
 * Real numbers from the site (cached for 12 hours).
 *
 * @return array posts, month, correspondents, categories, quotes
 */
function meeha_advertise_numbers() {
	$cached = get_transient( 'meeha_adv_numbers' );
	if ( is_array( $cached ) ) {
		return $cached;
	}
	$posts = wp_count_posts( 'post' );
	$month = new WP_Query(
		array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'date_query'     => array( array( 'after' => '30 days ago' ) ),
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => false,
		)
	);
	$users  = count_users();
	$corr   = isset( $users['avail_roles']['correspondent'] ) ? (int) $users['avail_roles']['correspondent'] : 0;
	$cats   = (int) wp_count_terms( array( 'taxonomy' => 'category', 'hide_empty' => true ) );
	$quotes = post_type_exists( 'meeha_photocard' ) ? (int) wp_count_posts( 'meeha_photocard' )->publish : 0;
	$out    = array(
		'posts'          => isset( $posts->publish ) ? (int) $posts->publish : 0,
		'month'          => (int) $month->found_posts,
		'correspondents' => $corr,
		'categories'     => $cats,
		'quotes'         => $quotes,
	);
	set_transient( 'meeha_adv_numbers', $out, 12 * HOUR_IN_SECONDS );
	return $out;
}

/**
 * Contact buttons.
 *
 * @param array  $c     Contact.
 * @param string $extra Extra class.
 * @return string
 */
function meeha_advertise_buttons( $c, $extra = '' ) {
	$b = '';
	if ( $c['whatsapp'] ) {
		$b .= '<a class="m-adv__btn m-adv__btn--wa" href="' . esc_url( 'https://wa.me/' . $c['whatsapp'] . '?text=' . rawurlencode( 'মিহা নিউজে বিজ্ঞাপন দিতে চাই।' ) ) . '" target="_blank" rel="noopener">হোয়াটসঅ্যাপে কথা বলুন</a>';
	}
	if ( $c['phone'] ) {
		$b .= '<a class="m-adv__btn m-adv__btn--call" href="' . esc_url( 'tel:' . preg_replace( '/[^\d+]/', '', meeha_bn_to_latin_digits( $c['phone'] ) ) ) . '">কল করুন · ' . esc_html( $c['phone'] ) . '</a>';
	}
	if ( $c['email'] ) {
		$b .= '<a class="m-adv__btn m-adv__btn--mail" href="' . esc_url( 'mailto:' . $c['email'] . '?subject=' . rawurlencode( 'বিজ্ঞাপন — মিহা নিউজ' ) ) . '">ইমেইল করুন</a>';
	}
	if ( '' === $b ) {
		$b = '<a class="m-adv__btn m-adv__btn--call" href="' . esc_url( home_url( '/contact/' ) ) . '">যোগাযোগ করুন</a>';
	}
	return '<div class="m-adv__ctas ' . esc_attr( $extra ) . '">' . $b . '</div>';
}

/**
 * The spec tables (shared by both shortcodes).
 *
 * @param string $only Group to show (web|video|card) or '' for all.
 * @return string
 */
function meeha_advertise_spec_tables( $only = '' ) {
	$groups = array(
		'web'   => 'ওয়েবসাইট',
		'video' => 'ভিডিও (ইউটিউব, ফেসবুক, রিল)',
		'card'  => 'ফটোকার্ড ও স্পনসর্ড কনটেন্ট',
	);
	$out = '';
	foreach ( $groups as $g => $label ) {
		if ( $only && $only !== $g ) {
			continue;
		}
		$out .= '<h3 class="m-adv__h3">' . esc_html( $label ) . '</h3><div class="m-adv__tablewrap"><table class="m-adv__table"><thead><tr><th>জায়গা</th><th>কোথায় দেখায়</th><th>ডেস্কটপ (পিক্সেল)</th><th>মোবাইল (পিক্সেল)</th><th>ফাইল</th></tr></thead><tbody>';
		foreach ( meeha_ad_specs() as $r ) {
			if ( $r['group'] !== $g ) {
				continue;
			}
			$out .= '<tr><th scope="row">' . esc_html( $r['name'] ) . ( $r['note'] ? '<small>' . esc_html( $r['note'] ) . '</small>' : '' ) . '</th><td>' . esc_html( $r['where'] ) . '</td><td class="m-adv__px">' . esc_html( $r['desktop'] ) . '</td><td class="m-adv__px">' . esc_html( $r['mobile'] ) . '</td><td>' . esc_html( $r['formats'] ) . '</td></tr>';
		}
		$out .= '</tbody></table></div>';
	}
	return $out;
}

/**
 * Design rules + policy summary.
 *
 * @return string
 */
function meeha_advertise_rules() {
	$policy = esc_url( home_url( '/advertising-policy/' ) );
	return '<div class="m-adv__rules">
<div class="m-adv__rule"><h3>নকশার নিয়ম</h3><ul>
<li>ঠিক যে মাপ চাওয়া হয়েছে সেই পিক্সেলে ছবি দিন — আমাদের সাইট ছবি কাটে না, ছবির মাপেই বাক্স বসে।</li>
<li>রেটিনা স্ক্রিনে ঝকঝকে দেখাতে দ্বিগুণ মাপ দিতে পারেন (যেমন ৩২০ × ৫০-এর জায়গায় ৬৪০ × ১০০), অনুপাত একই রাখুন।</li>
<li>ফাইল ছোট রাখুন: ছবি সাধারণত ১৫০ KB-এর মধ্যে; অ্যানিমেটেড GIF ১৫০ KB-এর বেশি হলে গ্রহণ হয় না — WebP বা MP4 দিন।</li>
<li>লেখা যেন মোবাইলেও পড়া যায়: ৩২০ × ৫০ ব্যানারে ৬–৮ শব্দের বেশি নয়, ফোন নম্বর বড় করে।</li>
<li>সাদা পটভূমির ব্যানারে চারপাশে হালকা সীমারেখা দিন, যাতে খবর থেকে আলাদা বোঝা যায়।</li>
<li>ক্লিক করলে কোন লিংকে যাবে (ওয়েবসাইট, ফেসবুক পেজ, হোয়াটসঅ্যাপ) তা সঙ্গে পাঠান।</li>
</ul></div>
<div class="m-adv__rule"><h3>নীতির সারকথা</h3><ul>
<li>প্রতিটি বিজ্ঞাপনে "বিজ্ঞাপন" চিহ্ন থাকে; স্পনসর্ড কনটেন্টে "পৃষ্ঠপোষকতায়" ও বিজ্ঞাপনদাতার নাম থাকে।</li>
<li>বিজ্ঞাপনদাতা কোনো সংবাদের বিষয়, ভাষা বা প্রকাশে প্রভাব ফেলতে পারেন না।</li>
<li>জুয়া/বেটিং, তামাক ও মাদক, প্রাপ্তবয়স্ক কনটেন্ট, যাচাইহীন চিকিৎসা দাবি, প্রতারণামূলক বা অনুমোদনহীন আর্থিক স্কিম এবং বিদ্বেষ ছড়ায় এমন বিজ্ঞাপন গ্রহণ করা হয় না।</li>
<li>বিজ্ঞাপনের তথ্য ও ছবির সত্যতা ও আইনি দায় বিজ্ঞাপনদাতার।</li>
<li>মিহা নিউজ যেকোনো বিজ্ঞাপন প্রত্যাখ্যান বা সরিয়ে নেওয়ার অধিকার রাখে।</li>
</ul><p><a href="' . $policy . '">পুরো বিজ্ঞাপন নীতিমালা পড়ুন →</a></p></div>
</div>';
}

/**
 * [meeha_advertise] — the advertiser landing page.
 *
 * @param array $atts visitors, followers, phone, whatsapp, email.
 * @return string
 */
function meeha_advertise_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'visitors'  => '',
			'followers' => '',
			'phone'     => '',
			'whatsapp'  => '',
			'email'     => '',
		),
		$atts,
		'meeha_advertise'
	);
	$c    = meeha_advertise_contact( $atts );
	$n    = meeha_advertise_numbers();
	$bn   = function ( $x ) {
		return function_exists( 'meeha_bn_digits' ) ? meeha_bn_digits( number_format( (int) $x ) ) : number_format( (int) $x );
	};
	$site  = wp_parse_url( home_url(), PHP_URL_HOST );
	$stats = array();
	if ( '' !== trim( $atts['visitors'] ) ) {
		$stats[] = array( esc_html( $atts['visitors'] ), 'মাসিক পাঠক' );
	}
	if ( '' !== trim( $atts['followers'] ) ) {
		$stats[] = array( esc_html( $atts['followers'] ), 'সোশ্যাল মিডিয়ায় অনুসারী' );
	}
	if ( $n['posts'] ) {
		$stats[] = array( $bn( $n['posts'] ) . '+', 'প্রকাশিত খবর' );
	}
	if ( $n['month'] ) {
		$stats[] = array( $bn( $n['month'] ), 'খবর গত ৩০ দিনে' );
	}
	if ( $n['correspondents'] ) {
		$stats[] = array( $bn( $n['correspondents'] ), 'মাঠের সংবাদদাতা' );
	}
	if ( $n['categories'] ) {
		$stats[] = array( $bn( $n['categories'] ), 'বিষয়ের বিভাগ' );
	}
	$stats_html = '';
	foreach ( array_slice( $stats, 0, 4 ) as $s ) {
		$stats_html .= '<div class="m-adv__stat"><strong>' . $s[0] . '</strong><span>' . esc_html( $s[1] ) . '</span></div>';
	}
	$why = array(
		array( '🎯', 'ঠিক জায়গায়, ঠিক মানুষের কাছে', 'খবরের ভেতরে, ভিডিওতে, ফটোকার্ডে — পাঠক যেখানে মনোযোগ দেন, বিজ্ঞাপন সেখানে।' ),
		array( '📱', 'মোবাইলে নিখুঁত', 'প্রতিটি জায়গা মোবাইলের জন্য আলাদা মাপে তৈরি; ব্যানার কাটা পড়ে না, পাতা ধীর হয় না।' ),
		array( '🎬', 'ওয়েবসাইটের বাইরেও', 'ইউটিউব ও ফেসবুক ভিডিওর কোণায় লোগো-বিজ্ঞাপন, নিচের ব্যানার, আর শেয়ার হওয়া ফটোকার্ডে স্ট্রিপ।' ),
		array( '📊', 'ফলাফলের হিসাব', 'কতবার দেখা হলো আর কতজন ক্লিক করলেন — প্রচারণা শেষে জায়গাভিত্তিক রিপোর্ট।' ),
		array( '🗺️', 'স্থানীয় থেকে জাতীয়', 'জেলা-উপজেলার সংবাদদাতাদের খবরের পাশে এলাকার ব্যবসার বিজ্ঞাপন — আবার সারা দেশেও।' ),
		array( '🛡️', 'আস্থার পরিবেশ', 'যাচাই করা সংবাদের পাশে, স্পষ্ট "বিজ্ঞাপন" চিহ্নসহ — পাঠক বিভ্রান্ত হন না, ব্র্যান্ডের সুনাম বাড়ে।' ),
	);
	$why_html = '';
	foreach ( $why as $w ) {
		$why_html .= '<div class="m-adv__card"><span class="m-adv__emoji" aria-hidden="true">' . $w[0] . '</span><h3>' . esc_html( $w[1] ) . '</h3><p>' . esc_html( $w[2] ) . '</p></div>';
	}
	$steps = array(
		array( 'কথা বলুন', 'লক্ষ্য, এলাকা, বাজেট ও সময় জানান — আমরা উপযুক্ত জায়গা প্রস্তাব করি।' ),
		array( 'ডিজাইন', 'নিজের ডিজাইন দিন, অথবা আমাদের ডিজাইন দল মাপমতো বানিয়ে দেবে।' ),
		array( 'অনুমোদন', 'নীতিমালা অনুযায়ী যাচাই ও আপনার চূড়ান্ত সম্মতি।' ),
		array( 'প্রচার', 'নির্দিষ্ট তারিখে বিজ্ঞাপন চালু, তারিখ শেষে নিজে থেকেই বন্ধ।' ),
		array( 'রিপোর্ট', 'দেখা ও ক্লিকের হিসাবসহ প্রচারণার ফলাফল।' ),
	);
	$steps_html = '';
	foreach ( $steps as $i => $s ) {
		$steps_html .= '<li><b>' . esc_html( function_exists( 'meeha_bn_digits' ) ? meeha_bn_digits( $i + 1 ) : $i + 1 ) . '</b><h3>' . esc_html( $s[0] ) . '</h3><p>' . esc_html( $s[1] ) . '</p></li>';
	}
	$faq = array(
		array( 'সবচেয়ে কম কত দিনের জন্য বিজ্ঞাপন দেওয়া যায়?', 'এক দিনের জন্যও দেওয়া যায়। শুরু ও শেষের তারিখ ঠিক করে দিলে সেই সময়েই চলে।' ),
		array( 'ডিজাইন না থাকলে?', 'আমাদের ডিজাইন দল মাপমতো ব্যানার, ভিডিও-লোগো ও ফটোকার্ড স্ট্রিপ বানিয়ে দিতে পারে।' ),
		array( 'শুধু আমার জেলার পাঠকদের কাছে দেখানো যায়?', 'জেলা ও বিষয়ভিত্তিক পাতায় বিজ্ঞাপন বসানোর বিষয়ে আলোচনা করুন — কোন জায়গা আপনার লক্ষ্যের সঙ্গে মেলে, আমরা জানাব।' ),
		array( 'ফলাফল কীভাবে জানব?', 'আমাদের সাইট প্রতিটি জায়গার বিজ্ঞাপন কতবার পর্দায় এসেছে আর কতবার ক্লিক হয়েছে তা গোনে; প্রচারণা শেষে সেই রিপোর্ট পাবেন।' ),
		array( 'দাম কত?', 'জায়গা, সময়কাল ও ধরন অনুযায়ী দাম আলাদা। যোগাযোগ করলে আপনার প্রয়োজনমতো প্রস্তাব পাঠানো হবে।' ),
	);
	$faq_html = '';
	foreach ( $faq as $f ) {
		$faq_html .= '<details class="m-adv__faq"><summary>' . esc_html( $f[0] ) . '</summary><p>' . esc_html( $f[1] ) . '</p></details>';
	}
	$specs_url = '';
	foreach ( array( 'ad-specs', 'advertising-specs', 'bigyapon-maap' ) as $slug ) {
		$p = get_page_by_path( $slug );
		if ( $p && 'publish' === $p->post_status ) {
			$specs_url = get_permalink( $p );
			break;
		}
	}
	ob_start();
	?>
<div class="m-adv">
	<section class="m-adv__hero">
		<div class="m-adv__wrap">
			<p class="m-adv__kicker">মিহা নিউজে বিজ্ঞাপন</p>
			<h1 class="m-adv__title">আপনার ব্র্যান্ড পৌঁছে দিন <span>আস্থার খবরের</span> পাশে</h1>
			<p class="m-adv__lede">ওয়েবসাইট, ইউটিউব-ফেসবুক ভিডিও আর শেয়ার হওয়া ফটোকার্ড — এক জায়গা থেকে সারা দেশের পাঠকের কাছে। মাপমতো ডিজাইন, নির্দিষ্ট তারিখে চালু-বন্ধ, আর প্রচারণা শেষে দেখা-ক্লিকের রিপোর্ট।</p>
			<?php echo meeha_advertise_buttons( $c, 'm-adv__ctas--hero' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
			<?php if ( $stats_html ) : ?>
				<div class="m-adv__stats"><?php echo $stats_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?></div>
				<p class="m-adv__note">সংখ্যাগুলো <?php echo esc_html( (string) $site ); ?> থেকে নিজে থেকে গোনা।</p>
			<?php endif; ?>
		</div>
		<div class="m-adv__mock" aria-hidden="true">
			<div class="m-adv__mock-bar"></div>
			<div class="m-adv__mock-ad m-adv__mock-ad--top">হেডার ব্যানার</div>
			<div class="m-adv__mock-grid">
				<div class="m-adv__mock-news"><i></i><i></i><i></i><div class="m-adv__mock-ad">খবরের ভেতরে</div><i></i><i></i></div>
				<div class="m-adv__mock-ad m-adv__mock-ad--side">পাশে</div>
			</div>
			<div class="m-adv__mock-ad m-adv__mock-ad--float">ভাসমান বার ✕</div>
		</div>
	</section>

	<section class="m-adv__sec">
		<div class="m-adv__wrap">
			<h2 class="m-adv__h2">কেন মিহা নিউজ</h2>
			<div class="m-adv__cards"><?php echo $why_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?></div>
		</div>
	</section>

	<section class="m-adv__sec m-adv__sec--tint">
		<div class="m-adv__wrap">
			<h2 class="m-adv__h2">কোথায় কোথায় বিজ্ঞাপন</h2>
			<p class="m-adv__sub">প্রতিটি জায়গার মাপ পিক্সেলে — ডিজাইনারকে এই তালিকাটিই পাঠান।</p>
			<?php echo meeha_advertise_spec_tables(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
			<?php if ( $specs_url ) : ?>
				<p><a class="m-adv__link" href="<?php echo esc_url( $specs_url ); ?>">ডিজাইনের পুরো নিয়ম ও মাপ →</a></p>
			<?php endif; ?>
		</div>
	</section>

	<section class="m-adv__sec">
		<div class="m-adv__wrap">
			<h2 class="m-adv__h2">যেভাবে কাজ হয়</h2>
			<ol class="m-adv__steps"><?php echo $steps_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?></ol>
		</div>
	</section>

	<section class="m-adv__sec m-adv__sec--tint">
		<div class="m-adv__wrap">
			<?php echo meeha_advertise_rules(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static. ?>
		</div>
	</section>

	<section class="m-adv__sec">
		<div class="m-adv__wrap m-adv__wrap--narrow">
			<h2 class="m-adv__h2">সাধারণ প্রশ্ন</h2>
			<?php echo $faq_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>
		</div>
	</section>

	<section class="m-adv__final">
		<div class="m-adv__wrap">
			<h2>আজই আপনার প্রচারণা শুরু করুন</h2>
			<p>আপনার লক্ষ্য জানান — বাকিটা আমরা সাজিয়ে দেব।</p>
			<?php echo meeha_advertise_buttons( $c, 'm-adv__ctas--final' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
		</div>
	</section>
</div>
	<?php
	return (string) ob_get_clean();
}
add_shortcode( 'meeha_advertise', 'meeha_advertise_shortcode' );

/**
 * [meeha_ad_specs] — sizes, formats, design rules and the policy summary for designers.
 *
 * @param array $atts group (web|video|card) to show one table only.
 * @return string
 */
function meeha_ad_specs_shortcode( $atts ) {
	$atts = shortcode_atts( array( 'group' => '' ), $atts, 'meeha_ad_specs' );
	$c    = meeha_advertise_contact();
	return '<div class="m-adv m-adv--specs"><section class="m-adv__sec"><div class="m-adv__wrap">'
		. '<h2 class="m-adv__h2">বিজ্ঞাপনের মাপ ও ফরম্যাট</h2><p class="m-adv__sub">প্রতিটি জায়গার জন্য ঠিক এই মাপে ফাইল দিন। ছবির মাপেই বাক্স বসে, তাই কিছু কাটা পড়ে না।</p>'
		. meeha_advertise_spec_tables( sanitize_key( $atts['group'] ) )
		. meeha_advertise_rules()
		. '<div class="m-adv__specs-cta"><p>ফাইল পাঠাতে বা প্রশ্ন থাকলে:</p>' . meeha_advertise_buttons( $c ) . '</div>'
		. '</div></section></div>';
}
add_shortcode( 'meeha_ad_specs', 'meeha_ad_specs_shortcode' );

/* The landing page's stylesheet loads only on pages that use one of the two shortcodes. */
add_action(
	'wp_enqueue_scripts',
	function () {
		if ( ! is_singular() ) {
			return;
		}
		$post = get_post();
		if ( $post && ( has_shortcode( $post->post_content, 'meeha_advertise' ) || has_shortcode( $post->post_content, 'meeha_ad_specs' ) ) ) {
			meeha_enqueue_bundle( 'advertise' );
		}
	},
	5
);
