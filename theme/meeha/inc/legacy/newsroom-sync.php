<?php
/**
 * Meeha News — থিম ↔ সংবাদদাতা অ্যাপ সংযোগ (Round 10)
 *
 * সংবাদদাতা আবেদন ও সংবাদদাতা ড্যাশবোর্ড এখন আলাদা অ্যাপে চলে
 * (https://correspondent.meeha.news)। থিমের পুরনো আবেদন ফর্ম, নিউজ জমার
 * ফর্ম ও ড্যাশবোর্ড inc/correspondent.php থেকে সরানো হয়েছে; এই ফাইল পুরনো
 * লিংক/পেজগুলোকে অ্যাপের দিকে ঘুরিয়ে দেয়:
 *
 *  1. [meeha_correspondent_apply] ও [meeha_news_submit_form] শর্টকোড এখন ছোট
 *     বাংলা CTA কার্ড — কোনো পেজে/উইজেটে থেকে গেলেও শুধু অ্যাপের লিংক দেখাবে।
 *  2. যে পেজের কনটেন্টে এই শর্টকোড আছে, সেটা খুললে সরাসরি অ্যাপে 302 রিডাইরেক্ট।
 *  3. "আমার একাউন্ট"-এর প্রোফাইল প্যানেলে ছোট একটা কার্ড
 *     (meeha_newsroom_render_account_card(), functions.php থেকে কল হয়)।
 *
 * URL বদলাতে চাইলে wp-config.php-তে কনস্ট্যান্ট দিন অথবা ফিল্টার ব্যবহার করুন:
 *   define( 'MEEHA_CORRESPONDENT_APP_URL', 'https://correspondent.meeha.news' );
 *   define( 'MEEHA_CORRESPONDENT_APPLY_URL', 'https://correspondent.meeha.news/apply' );
 *   add_filter( 'meeha_correspondent_app_url', fn() => '...' );
 *   add_filter( 'meeha_correspondent_apply_url', fn() => '...' );
 *   add_filter( 'meeha_correspondent_redirect_enabled', '__return_false' ); // রিডাইরেক্ট বন্ধ
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Round 11 (theme `meeha`): unchanged logic; the CTA card uses the design system (.m-nrcta).

if ( ! defined( 'MEEHA_CORRESPONDENT_APP_URL' ) ) {
	define( 'MEEHA_CORRESPONDENT_APP_URL', 'https://correspondent.meeha.news' );
}
if ( ! defined( 'MEEHA_CORRESPONDENT_APPLY_URL' ) ) {
	define( 'MEEHA_CORRESPONDENT_APPLY_URL', 'https://correspondent.meeha.news/apply' );
}

if ( ! function_exists( 'meeha_correspondent_app_url' ) ) {
	/** সংবাদদাতা অ্যাপের (ড্যাশবোর্ড) URL */
	function meeha_correspondent_app_url() {
		return esc_url_raw( apply_filters( 'meeha_correspondent_app_url', MEEHA_CORRESPONDENT_APP_URL ) );
	}
}

if ( ! function_exists( 'meeha_correspondent_apply_url' ) ) {
	/** সংবাদদাতা হওয়ার আবেদনের URL */
	function meeha_correspondent_apply_url() {
		return esc_url_raw( apply_filters( 'meeha_correspondent_apply_url', MEEHA_CORRESPONDENT_APPLY_URL ) );
	}
}

/**
 * শর্টকোড → গন্তব্য URL ম্যাপ (রিডাইরেক্ট ও CTA দুই জায়গাতেই ব্যবহৃত)
 */
function meeha_newsroom_shortcode_targets() {
	return array(
		'meeha_correspondent_apply' => meeha_correspondent_apply_url(),
		'meeha_news_submit_form'    => meeha_correspondent_app_url(),
	);
}

/**
 * ছোট CTA কার্ড — শর্টকোড ও "আমার একাউন্ট" দুই জায়গাতেই একই মার্কআপ
 */
function meeha_newsroom_cta_card( $title, $text, $button_label, $url, $modifier = '' ) {
	$class = 'm-nrcta' . ( $modifier ? ' m-nrcta--' . sanitize_html_class( $modifier ) : '' );
	return '<div class="' . esc_attr( $class ) . '">'
		. '<div class="m-nrcta__body">'
		. '<p class="m-nrcta__title">' . esc_html( $title ) . '</p>'
		. ( $text ? '<p class="m-nrcta__text">' . esc_html( $text ) . '</p>' : '' )
		. '</div>'
		. '<a class="m-btn m-nrcta__btn" href="' . esc_url( $url ) . '" rel="noopener">' . esc_html( $button_label ) . '</a>'
		. '</div>';
}

function meeha_newsroom_apply_shortcode() {
	meeha_newsroom_enqueue_cta_style();
	return meeha_newsroom_cta_card(
		'সংবাদদাতা হতে চান?',
		'মিহা নিউজের সংবাদদাতা হওয়ার আবেদন এখন আমাদের সংবাদদাতা অ্যাপে নেওয়া হয়।',
		'আবেদন করুন',
		meeha_correspondent_apply_url(),
		'apply'
	);
}

function meeha_newsroom_submit_shortcode() {
	meeha_newsroom_enqueue_cta_style();
	return meeha_newsroom_cta_card(
		'খবর পাঠাতে চান?',
		'সংবাদদাতারা এখন সংবাদদাতা অ্যাপ থেকে খবর লেখেন, পাঠান ও অবস্থা দেখেন।',
		'সংবাদদাতা অ্যাপে যান',
		meeha_correspondent_app_url(),
		'app'
	);
}

add_shortcode( 'meeha_correspondent_apply', 'meeha_newsroom_apply_shortcode' );
add_shortcode( 'meeha_news_submit_form', 'meeha_newsroom_submit_shortcode' );

/**
 * পোস্টের কনটেন্টে (বা Elementor ডেটায়) কোন শর্টকোড আছে, সেটার গন্তব্য URL।
 * has_shortcode() না, সরাসরি স্ট্রিং খোঁজা হচ্ছে — যাতে শর্টকোড রেজিস্টার্ড না
 * থাকলেও / Elementor উইজেটের ভেতরে থাকলেও ধরা পড়ে।
 */
function meeha_newsroom_redirect_target_for( $post ) {
	if ( ! $post instanceof WP_Post ) {
		return '';
	}
	$haystacks = array( (string) $post->post_content );
	$elementor = get_post_meta( $post->ID, '_elementor_data', true );
	if ( is_string( $elementor ) && '' !== $elementor ) {
		$haystacks[] = $elementor;
	}
	foreach ( meeha_newsroom_shortcode_targets() as $tag => $url ) {
		foreach ( $haystacks as $h ) {
			if ( false !== strpos( $h, '[' . $tag ) && preg_match( '/\[' . preg_quote( $tag, '/' ) . '[\s\]\/]/', $h ) ) {
				return $url;
			}
		}
	}
	return '';
}

/**
 * পুরনো "সংবাদদাতা আবেদন"/"খবর পাঠান" পেজ খুললে অ্যাপে পাঠিয়ে দেওয়া (302)।
 * বাদ: wp-admin, প্রিভিউ, REST/AJAX/cron, ফিডে; আর এডিটররা (edit_pages)
 * ?meeha_no_redirect=1 দিয়ে পেজটা দেখতে পারবেন।
 */
function meeha_newsroom_maybe_redirect() {
	if ( is_admin() || is_preview() || is_feed() || wp_doing_ajax() || wp_doing_cron() ) {
		return;
	}
	if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) {
		return;
	}
	// Only pages: the apply/submit forms always lived on pages. An article that merely
	// quotes the shortcode text (e.g. a how-to) must never be redirected away.
	if ( ! is_page() || ! apply_filters( 'meeha_correspondent_redirect_enabled', true ) ) {
		return;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( ! empty( $_GET['meeha_no_redirect'] ) && current_user_can( 'edit_pages' ) ) {
		return;
	}
	$target = meeha_newsroom_redirect_target_for( get_queried_object() );
	if ( ! $target ) {
		return;
	}
	// LiteSpeed/অন্যান্য ক্যাশ যেন এই রিডাইরেক্ট স্থায়ীভাবে ধরে না রাখে
	nocache_headers();
	header( 'X-Meeha-Redirect: correspondent-app' );
	wp_redirect( $target, 302, 'Meeha News' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- বাইরের (নিজস্ব) সাবডোমেইন
	exit;
}
add_action( 'template_redirect', 'meeha_newsroom_maybe_redirect', 1 );

/**
 * "আমার একাউন্ট" → প্রোফাইল প্যানেলের কার্ড।
 * correspondent রোল থাকলে অ্যাপের লিংক, অন্যদের জন্য আবেদনের লিংক।
 */
function meeha_newsroom_render_account_card( $user = null ) {
	$user = $user instanceof WP_User ? $user : wp_get_current_user();
	meeha_newsroom_enqueue_cta_style();
	if ( $user && in_array( 'correspondent', (array) $user->roles, true ) ) {
		$html = meeha_newsroom_cta_card(
			'আপনার সংবাদদাতা ড্যাশবোর্ড এখন আলাদা অ্যাপে',
			'খবর লেখা, পাঠানো, সম্পাদনার অনুরোধ ও পরিসংখ্যান — সব সংবাদদাতা অ্যাপে। একই ইউজারনেম ও পাসওয়ার্ড দিয়ে লগইন করুন।',
			'সংবাদদাতা অ্যাপ খুলুন',
			meeha_correspondent_app_url(),
			'app'
		);
	} else {
		$html = meeha_newsroom_cta_card(
			'সংবাদদাতা হতে চান?',
			'আপনার এলাকার খবর মিহা নিউজে পৌঁছে দিন। আবেদন করুন সংবাদদাতা অ্যাপে।',
			'আবেদন করুন',
			meeha_correspondent_apply_url(),
			'apply'
		);
	}
	echo apply_filters( 'meeha_newsroom_account_card_html', $html, $user ); // phpcs:ignore WordPress.Security.EscapeOutput -- উপরে এস্কেপ করা
}

/**
 * CTA কার্ডের স্টাইল এখন account বান্ডলে (assets/css/account.css → .m-nrcta)।
 * শর্টকোড/অ্যাকাউন্ট পেজে accounts.php আগেভাগেই (wp_enqueue_scripts) বান্ডল লোড করে;
 * অন্য কোথাও শর্টকোড রেন্ডার হলে এখানে লেট-এনকিউ হয় (ফুটারে প্রিন্ট)।
 */
function meeha_newsroom_enqueue_cta_style() {
	if ( function_exists( 'meeha_account_enqueue_now' ) && ! did_action( 'wp_footer' ) ) {
		meeha_account_enqueue_now();
	}
}
