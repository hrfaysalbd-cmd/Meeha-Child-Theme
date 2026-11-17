<?php
/**
 * r47 — হাউস বিজ্ঞাপন: built-in animated cards that promote Meeha's own features (YouTube, app,
 * tips, factcheck, campus, saved, newsletter, assistant). Pure CSS keyframes — no GIF weight, crisp
 * at every size, and they honour prefers-reduced-motion. A slot renders one with type `house` +
 * `<slot>_house` = card id; the publisher app previews the same HTML over nros/v1.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

/**
 * Card catalog. Each: label (desk), kicker, title, line, cta, url (callable → string), theme
 * (gradient from,to + accent), icon (inline SVG path content on a 24×24 grid).
 *
 * @return array[]
 */
function meeha_house_ads() {
	$cards = array(
		'youtube'    => array(
			'label'  => 'ইউটিউব চ্যানেল',
			'kicker' => 'মিহা নিউজ',
			'title'  => 'আমাদের ইউটিউব চ্যানেল',
			'line'   => 'ভিডিও সংবাদ, লাইভ ও অনুসন্ধান — সবার আগে।',
			'cta'    => 'সাবস্ক্রাইব করুন',
			'theme'  => array( '#7f1d1d', '#dc2626', '#fecaca' ),
			'icon'   => '<path d="M10 8.5v7l6-3.5-6-3.5z" fill="currentColor"/><rect x="2.5" y="5" width="19" height="14" rx="4" fill="none" stroke="currentColor" stroke-width="1.8"/>',
			'url'    => function () {
				$u = (string) meeha_opt( 'social', 'youtube', '' );
				return '' !== $u ? $u : home_url( '/' );
			},
		),
		'app'        => array(
			'label'  => 'সংবাদদাতা অ্যাপ',
			'kicker' => 'মিহা নিউজ অ্যাপ',
			'title'  => 'সংবাদদাতা অ্যাপ নামান',
			'line'   => 'মাঠ থেকেই সংবাদ, ছবি ও ভিডিও পাঠান।',
			'cta'    => 'ডাউনলোড করুন',
			'theme'  => array( '#1e3a8a', '#2563eb', '#bfdbfe' ),
			'icon'   => '<rect x="7" y="2.5" width="10" height="19" rx="2.5" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M12 15.5v-6m0 6-2.2-2.2M12 15.5l2.2-2.2" stroke="currentColor" stroke-width="1.6" fill="none" stroke-linecap="round"/>',
			'url'    => function () {
				$p = function_exists( 'meeha_house_find_page' ) ? meeha_house_find_page( array( 'app', 'অ্যাপ' ), 'meeha_app_landing' ) : null;
				return $p ? get_permalink( $p ) : home_url( '/' );
			},
		),
		'tips'       => array(
			'label'  => 'গোপন তথ্য (টিপস)',
			'kicker' => 'অনুসন্ধান',
			'title'  => 'গোপন তথ্য দিন',
			'line'   => 'পরিচয় গোপন রেখে অনিয়মের তথ্য পাঠান।',
			'cta'    => 'তথ্য পাঠান',
			'theme'  => array( '#134e4a', '#0f766e', '#99f6e4' ),
			'icon'   => '<path d="M12 3 4.5 6v5c0 4.6 3.1 8.2 7.5 9.5 4.4-1.3 7.5-4.9 7.5-9.5V6L12 3z" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="m9.3 12 1.9 1.9 3.7-3.8" stroke="currentColor" stroke-width="1.8" fill="none" stroke-linecap="round"/>',
			'url'    => function () {
				return home_url( '/tips/' );
			},
		),
		'factcheck'  => array(
			'label'  => 'ফ্যাক্টচেক',
			'kicker' => 'যাচাই',
			'title'  => 'মিহা ফ্যাক্টচেক',
			'line'   => 'গুজব না সত্য? যাচাই করা প্রতিবেদন পড়ুন।',
			'cta'    => 'ফ্যাক্টচেক দেখুন',
			'theme'  => array( '#14532d', '#16a34a', '#bbf7d0' ),
			'icon'   => '<circle cx="10.5" cy="10.5" r="6" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="m15 15 5 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="m8 10.5 1.8 1.8 3-3" stroke="currentColor" stroke-width="1.6" fill="none" stroke-linecap="round"/>',
			'url'    => function () {
				return home_url( '/factcheck/' );
			},
		),
		'campus'     => array(
			'label'  => 'শিক্ষা ও ক্যাম্পাস',
			'kicker' => 'শিক্ষা',
			'title'  => 'শিক্ষা ও ক্যাম্পাস',
			'line'   => 'আজকের নির্বাচিত পাঠ, চাকরি ও সিভি টেমপ্লেট।',
			'cta'    => 'ক্যাম্পাসে যান',
			'theme'  => array( '#4c1d95', '#7c3aed', '#ddd6fe' ),
			'icon'   => '<path d="m12 4 9.5 4.5L12 13 2.5 8.5 12 4z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M6 11v4.5c0 1.2 2.7 2.5 6 2.5s6-1.3 6-2.5V11" fill="none" stroke="currentColor" stroke-width="1.8"/>',
			'url'    => function () {
				return function_exists( 'meeha_campus_url' ) ? meeha_campus_url() : home_url( '/' );
			},
		),
		'saved'      => array(
			'label'  => 'সংরক্ষিত খবর',
			'kicker' => 'আপনার তালিকা',
			'title'  => 'পরে পড়তে সংরক্ষণ করুন',
			'line'   => 'পছন্দের খবর এক জায়গায় — যেকোনো সময় পড়ুন।',
			'cta'    => 'সংরক্ষিত দেখুন',
			'theme'  => array( '#78350f', '#d97706', '#fde68a' ),
			'icon'   => '<path d="M6.5 3.5h11a1 1 0 0 1 1 1V21l-6.5-4-6.5 4V4.5a1 1 0 0 1 1-1z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>',
			'url'    => function () {
				return home_url( '/saved/' );
			},
		),
		'newsletter' => array(
			'label'  => 'নিউজলেটার',
			'kicker' => 'প্রতিদিন সকালে',
			'title'  => 'মিহা নিউজলেটার',
			'line'   => 'দিনের বাছাই খবর সোজা আপনার ইনবক্সে।',
			'cta'    => 'ফ্রি সাবস্ক্রাইব',
			'theme'  => array( '#312e81', '#4f46e5', '#c7d2fe' ),
			'icon'   => '<rect x="3" y="5.5" width="18" height="13" rx="2" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="m4 7 8 6 8-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>',
			'url'    => function () {
				return home_url( '/#m-footer' );
			},
		),
		'assistant'  => array(
			'label'  => 'মিহা সহকারী',
			'kicker' => 'জিজ্ঞাসা করুন',
			'title'  => 'মিহা সহকারীকে জিজ্ঞাসা করুন',
			'line'   => '“আজ দেশে কী হচ্ছে?” — এক প্রশ্নেই উত্তর।',
			'cta'    => 'জিজ্ঞাসা করুন',
			'theme'  => array( '#0c4a6e', '#0284c7', '#bae6fd' ),
			'icon'   => '<path d="M12 3c5 0 9 3.4 9 7.6 0 4.2-4 7.6-9 7.6-.9 0-1.8-.1-2.6-.3L5 20l.9-3.2C4.1 15.4 3 13.1 3 10.6 3 6.4 7 3 12 3z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M8.5 9.5h7M8.5 12.5h4.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>',
			'url'    => function () {
				return home_url( '/?m_assistant=1' );
			},
		),
	);
	return apply_filters( 'meeha_house_ads', $cards );
}

/**
 * Cached page lookup by slug candidates, then by shortcode in content (for the app landing page).
 *
 * @param string[] $slugs     Slug candidates.
 * @param string   $shortcode Shortcode tag to search for.
 * @return WP_Post|null
 */
function meeha_house_find_page( $slugs, $shortcode = '' ) {
	$key = 'meeha_house_pg_' . md5( implode( ',', $slugs ) . '|' . $shortcode );
	$id  = get_transient( $key );
	if ( false !== $id ) {
		$p = $id ? get_post( (int) $id ) : null;
		return $p && 'publish' === $p->post_status ? $p : null;
	}
	$found = null;
	foreach ( $slugs as $slug ) {
		$p = get_page_by_path( $slug );
		if ( $p && 'publish' === $p->post_status ) {
			$found = $p;
			break;
		}
	}
	if ( ! $found && '' !== $shortcode ) {
		$q = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				's'              => '[' . $shortcode,
				'fields'         => 'ids',
			)
		);
		$found = $q ? get_post( (int) $q[0] ) : null;
	}
	set_transient( $key, $found ? $found->ID : 0, 6 * HOUR_IN_SECONDS );
	return $found;
}

/**
 * The shared stylesheet, printed once per page with the first card.
 *
 * @return string
 */
function meeha_house_ad_css() {
	static $done = false;
	if ( $done ) {
		return '';
	}
	$done = true;
	return '<style id="m-had-css">'
		. '.m-had{position:relative;display:flex;width:100%;height:100%;overflow:hidden;border-radius:10px;color:#fff;text-decoration:none;font-family:inherit;background:linear-gradient(120deg,var(--had-a),var(--had-b),var(--had-a));background-size:220% 220%;animation:m-had-bg 9s ease-in-out infinite}'
		. '.m-had:hover{color:#fff}'
		. '.m-had::after{content:"";position:absolute;inset:0;background:linear-gradient(105deg,transparent 38%,rgba(255,255,255,.22) 50%,transparent 62%);transform:translateX(-120%);animation:m-had-shine 5.5s ease-in-out infinite}'
		. '.m-had__ic{flex:0 0 auto;display:grid;place-items:center;color:var(--had-c);animation:m-had-float 3.2s ease-in-out infinite}'
		. '.m-had__ic svg{display:block}'
		. '.m-had__k{font-size:11px;font-weight:600;letter-spacing:.04em;color:var(--had-c);text-transform:uppercase}'
		. '.m-had__t{font-weight:700;line-height:1.25;margin:2px 0}'
		. '.m-had__l{opacity:.88;line-height:1.45}'
		. '.m-had__cta{display:inline-block;background:#fff;color:var(--had-a);font-weight:700;border-radius:999px;padding:.42em 1.1em;margin-top:auto;white-space:nowrap}'
		. '.m-had--leader{align-items:center;gap:16px;padding:10px 18px}'
		. '.m-had--leader .m-had__ic svg{width:44px;height:44px}'
		. '.m-had--leader .m-had__bd{min-width:0;flex:1 1 auto}'
		. '.m-had--leader .m-had__t{font-size:17px}'
		. '.m-had--leader .m-had__l{font-size:12.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'
		. '.m-had--leader .m-had__cta{font-size:13px;flex:0 0 auto;margin-top:0}'
		. '.m-had--mpu,.m-had--half{flex-direction:column;justify-content:center;text-align:center;align-items:center;gap:6px;padding:20px 16px}'
		. '.m-had--mpu .m-had__ic svg{width:52px;height:52px}'
		. '.m-had--half .m-had__ic svg{width:72px;height:72px}'
		. '.m-had--mpu .m-had__t{font-size:19px}'
		. '.m-had--half .m-had__t{font-size:22px}'
		. '.m-had--mpu .m-had__l,.m-had--half .m-had__l{font-size:13px}'
		. '.m-had--mpu .m-had__cta,.m-had--half .m-had__cta{font-size:14px;margin-top:10px}'
		. '@media (max-width:767.98px){.m-had--leader{gap:10px;padding:8px 12px}.m-had--leader .m-had__ic svg{width:32px;height:32px}.m-had--leader .m-had__t{font-size:14px}.m-had--leader .m-had__l{display:none}.m-had--leader .m-had__cta{font-size:12px;padding:.35em .9em}}'
		. '@keyframes m-had-bg{0%,100%{background-position:0% 50%}50%{background-position:100% 50%}}'
		. '@keyframes m-had-shine{0%,55%{transform:translateX(-120%)}75%,100%{transform:translateX(120%)}}'
		. '@keyframes m-had-float{0%,100%{transform:translateY(0)}50%{transform:translateY(-5px)}}'
		. '@media (prefers-reduced-motion:reduce){.m-had,.m-had::after,.m-had__ic{animation:none}}'
		. '</style>';
}

/**
 * One house card's HTML for a slot size, or '' for an unknown card.
 *
 * @param string $card Card id.
 * @param string $size leader|mpu|half.
 * @return string
 */
function meeha_house_ad_html( $card, $size ) {
	$all = meeha_house_ads();
	if ( ! isset( $all[ $card ] ) ) {
		return '';
	}
	$c    = $all[ $card ];
	$size = in_array( $size, array( 'leader', 'mpu', 'half' ), true ) ? $size : 'mpu';
	$url  = is_callable( $c['url'] ) ? (string) call_user_func( $c['url'] ) : (string) $c['url'];
	$t    = $c['theme'];
	return meeha_house_ad_css() . sprintf(
		'<a class="m-had m-had--%1$s m-had--%2$s" style="--had-a:%3$s;--had-b:%4$s;--had-c:%5$s" href="%6$s">'
		. '<span class="m-had__ic" aria-hidden="true"><svg viewBox="0 0 24 24">%7$s</svg></span>'
		. '<span class="m-had__bd"><span class="m-had__k">%8$s</span><span class="m-had__t">%9$s</span><span class="m-had__l">%10$s</span></span>'
		. '<span class="m-had__cta">%11$s</span></a>',
		esc_attr( $size ),
		esc_attr( $card ),
		esc_attr( $t[0] ),
		esc_attr( $t[1] ),
		esc_attr( $t[2] ),
		esc_url( $url ),
		$c['icon'], // Fixed catalog SVG, not user input.
		esc_html( $c['kicker'] ),
		esc_html( $c['title'] ),
		esc_html( $c['line'] ),
		esc_html( $c['cta'] )
	);
}
