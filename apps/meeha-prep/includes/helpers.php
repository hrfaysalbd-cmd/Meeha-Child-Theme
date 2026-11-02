<?php
/**
 * Small shared helpers (formatting, dates in Asia/Dhaka, URLs, options, templates).
 *
 * @package meeha-prep
 */

defined( 'ABSPATH' ) || exit;

/**
 * Bengali digits.
 *
 * @param mixed $v Value.
 * @return string
 */
function mhp_bn( $v ) {
	return strtr( (string) $v, array( '0' => '০', '1' => '১', '2' => '২', '3' => '৩', '4' => '৪', '5' => '৫', '6' => '৬', '7' => '৭', '8' => '৮', '9' => '৯' ) );
}

/**
 * Number with Indian grouping (12,34,567) in Bengali digits.
 *
 * @param float $n        Number.
 * @param int   $decimals Decimals.
 * @return string
 */
function mhp_num( $n, $decimals = 0 ) {
	$neg = $n < 0;
	$n   = abs( (float) $n );
	$int = (string) floor( $n );
	$dec = $decimals ? substr( number_format( $n - floor( $n ), $decimals, '.', '' ), 1 ) : '';
	if ( strlen( $int ) > 3 ) {
		$last = substr( $int, -3 );
		$rest = substr( $int, 0, -3 );
		$rest = preg_replace( '/\B(?=(\d{2})+(?!\d))/', ',', $rest );
		$int  = $rest . ',' . $last;
	}
	if ( $dec && '.' === rtrim( $dec, '0' ) ) {
		$dec = '';
	}
	return mhp_bn( ( $neg ? '-' : '' ) . $int . $dec );
}

/**
 * Taka amount: ৳১,২৯৯.
 *
 * @param float $n Amount.
 * @return string
 */
function mhp_tk( $n ) {
	return '৳' . mhp_num( $n, floor( (float) $n ) === (float) $n ? 0 : 2 );
}

/**
 * Score (allows .5 / .25).
 *
 * @param float $n Score.
 * @return string
 */
function mhp_score( $n ) {
	$s = rtrim( rtrim( number_format( (float) $n, 2, '.', '' ), '0' ), '.' );
	return mhp_bn( $s );
}

/**
 * Site time zone object (Bangladesh time unless the site says otherwise).
 *
 * @return DateTimeZone
 */
function mhp_tz() {
	$tz = wp_timezone();
	if ( 'UTC' === $tz->getName() || '+00:00' === $tz->getName() ) {
		$tz = new DateTimeZone( 'Asia/Dhaka' );
	}
	return $tz;
}

/**
 * Today Y-m-d (local).
 *
 * @return string
 */
function mhp_today() {
	return wp_date( 'Y-m-d', null, mhp_tz() );
}

/**
 * Current local datetime 'Y-m-d H:i:s'.
 *
 * @return string
 */
function mhp_now_local() {
	return wp_date( 'Y-m-d H:i:s', null, mhp_tz() );
}

/**
 * UTC 'Y-m-d H:i:s' (database timestamps).
 *
 * @param int|null $ts Timestamp.
 * @return string
 */
function mhp_gmt( $ts = null ) {
	return gmdate( 'Y-m-d H:i:s', null === $ts ? time() : (int) $ts );
}

/**
 * Is a string a valid Y-m-d date?
 *
 * @param string $d Date.
 * @return bool
 */
function mhp_is_date( $d ) {
	if ( ! is_string( $d ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m ) ) {
		return false;
	}
	return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
}

/**
 * Days from today to a Y-m-d date (negative = past). Null when no date.
 *
 * @param string $d Date.
 * @return int|null
 */
function mhp_days_left( $d ) {
	if ( ! mhp_is_date( $d ) ) {
		return null;
	}
	$a = new DateTime( mhp_today(), mhp_tz() );
	$b = new DateTime( $d, mhp_tz() );
	return (int) $a->diff( $b )->format( '%r%a' );
}

/**
 * Add days to a Y-m-d date.
 *
 * @param string $d    Date.
 * @param int    $days Days (may be negative).
 * @return string
 */
function mhp_date_add( $d, $days ) {
	$dt = new DateTime( $d, mhp_tz() );
	$dt->modify( ( $days >= 0 ? '+' : '' ) . (int) $days . ' days' );
	return $dt->format( 'Y-m-d' );
}

/**
 * First day (Saturday, as in Bangladesh) of the week a date falls in.
 *
 * @param string $d Date (default today).
 * @return string
 */
function mhp_week_start( $d = '' ) {
	$d   = mhp_is_date( $d ) ? $d : mhp_today();
	$dow = (int) ( new DateTime( $d, mhp_tz() ) )->format( 'w' ); // 0 = Sunday … 6 = Saturday.
	return mhp_date_add( $d, -( ( $dow + 1 ) % 7 ) );
}

/**
 * Bengali month names.
 *
 * @return string[]
 */
function mhp_months() {
	return array( 1 => 'জানুয়ারি', 'ফেব্রুয়ারি', 'মার্চ', 'এপ্রিল', 'মে', 'জুন', 'জুলাই', 'আগস্ট', 'সেপ্টেম্বর', 'অক্টোবর', 'নভেম্বর', 'ডিসেম্বর' );
}

/**
 * "২৫ অক্টোবর ২০২৬" from Y-m-d.
 *
 * @param string $d    Date.
 * @param bool   $year Include year.
 * @return string
 */
function mhp_fdate( $d, $year = true ) {
	if ( ! mhp_is_date( $d ) ) {
		return '';
	}
	list( $y, $m, $dd ) = array_map( 'intval', explode( '-', $d ) );
	$mo                  = mhp_months();
	return mhp_bn( $dd ) . ' ' . $mo[ $m ] . ( $year ? ' ' . mhp_bn( $y ) : '' );
}

/**
 * "২৫ অক্টোবর ২০২৬, রাত ৯:০০" from a local 'Y-m-d H:i' string.
 *
 * @param string $dt Datetime.
 * @return string
 */
function mhp_fdatetime( $dt ) {
	$ts = strtotime( (string) $dt );
	if ( ! $ts ) {
		return '';
	}
	$h    = (int) gmdate( 'G', $ts );
	$part = $h < 5 ? 'রাত' : ( $h < 12 ? 'সকাল' : ( $h < 15 ? 'দুপুর' : ( $h < 18 ? 'বিকেল' : ( $h < 20 ? 'সন্ধ্যা' : 'রাত' ) ) ) );
	$h12  = $h % 12 ? $h % 12 : 12;
	return mhp_fdate( gmdate( 'Y-m-d', $ts ) ) . ', ' . $part . ' ' . mhp_bn( $h12 . ':' . gmdate( 'i', $ts ) );
}

/**
 * Plugin option (stored as `meeha_opt_prep`, the theme's settings tab; works without the theme too).
 *
 * @param string $key     Key.
 * @param mixed  $default Default.
 * @return mixed
 */
function mhp_opt( $key, $default = null ) {
	if ( function_exists( 'meeha_opt' ) && function_exists( 'meeha_settings_tab' ) && meeha_settings_tab( 'prep' ) ) {
		return meeha_opt( 'prep', $key, $default );
	}
	$all  = get_option( 'meeha_opt_prep', array() );
	$all  = is_array( $all ) ? $all : array();
	$defs = MHP_Settings::defaults();
	if ( array_key_exists( $key, $all ) ) {
		return $all[ $key ];
	}
	return array_key_exists( $key, $defs ) ? $defs[ $key ] : $default;
}

/**
 * URL of a section view (delegates to the theme's router when it is active).
 *
 * @param string $view View (home, jobs, job, prep, track, test, run, result, quiz, leaderboard, events, event, me,
 *                     employer, checkout, order, career, news, search).
 * @param string $id   Optional id segment.
 * @param array  $args Query args.
 * @return string
 */
function mhp_url( $view = 'home', $id = '', $args = array() ) {
	if ( function_exists( 'meeha_campus_url' ) ) {
		return meeha_campus_url( $view, $id, $args );
	}
	return add_query_arg( $args, home_url( '/' ) );
}

/**
 * Is the section hub available (theme router present and a hub page chosen)?
 *
 * @return bool
 */
function mhp_hub_ready() {
	return function_exists( 'meeha_campus_hub_id' ) && meeha_campus_hub_id() > 0;
}

/**
 * Login URL that comes back to $back.
 *
 * @param string $back Return URL.
 * @return string
 */
function mhp_login_url( $back = '' ) {
	return wp_login_url( $back ? $back : mhp_current_url() );
}

/**
 * Current front-end URL (path + query) on this site.
 *
 * @return string
 */
function mhp_current_url() {
	$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	$uri = '/' . ltrim( (string) $uri, '/' );
	return esc_url_raw( home_url( $uri ) );
}

/**
 * Render a plugin template (theme override: meeha-prep/<name>.php in the active theme).
 *
 * @param string $name Template name without .php.
 * @param array  $vars Variables.
 */
function mhp_template( $name, $vars = array() ) {
	$name = sanitize_file_name( $name );
	$file = locate_template( 'meeha-prep/' . $name . '.php' );
	if ( ! $file ) {
		$file = MHP_DIR . '/templates/' . $name . '.php';
	}
	if ( ! is_readable( $file ) ) {
		return;
	}
	// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- template variables.
	extract( $vars, EXTR_SKIP );
	include $file;
}

/**
 * Template output as a string.
 *
 * @param string $name Template.
 * @param array  $vars Vars.
 * @return string
 */
function mhp_get_template( $name, $vars = array() ) {
	ob_start();
	mhp_template( $name, $vars );
	return (string) ob_get_clean();
}

/**
 * Inline icon (theme campus icon set when present).
 *
 * @param string $name Icon.
 * @return string SVG markup.
 */
function mhp_icon( $name ) {
	if ( function_exists( 'meeha_campus_icon' ) ) {
		return meeha_campus_icon( $name );
	}
	return '';
}

/**
 * First letter (grapheme-ish) of a Bengali/Latin string, for monogram tiles.
 *
 * @param string $s String.
 * @return string
 */
function mhp_initial( $s ) {
	$s = trim( wp_strip_all_tags( (string) $s ) );
	if ( '' === $s ) {
		return '';
	}
	if ( function_exists( 'grapheme_substr' ) ) {
		$g = grapheme_substr( $s, 0, 1 );
		if ( false !== $g && null !== $g ) {
			return $g;
		}
	}
	// Bengali vowel signs / virama combine with the previous consonant.
	if ( preg_match( '/^.[\x{0981}-\x{0983}\x{09BC}\x{09BE}-\x{09CD}\x{09D7}]*/u', $s, $m ) ) {
		return $m[0];
	}
	return mb_substr( $s, 0, 1 );
}

/**
 * Flash message for the next page view of this user/visitor (cookie, short-lived).
 *
 * @param string $msg  Message.
 * @param string $type ok|error|info.
 */
function mhp_flash( $msg, $type = 'ok' ) {
	$payload = wp_json_encode( array( 'm' => (string) $msg, 't' => (string) $type ) );
	$sig     = hash_hmac( 'sha256', $payload, wp_salt( 'nonce' ) );
	if ( ! headers_sent() ) {
		setcookie( 'mhp_flash', base64_encode( $payload ) . '.' . $sig, time() + 120, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}
}

/**
 * Read and clear the flash message.
 *
 * @return array|null m,t
 */
function mhp_take_flash() {
	static $read = null;
	if ( null !== $read ) {
		return $read ? $read : null;
	}
	$read = false;
	if ( empty( $_COOKIE['mhp_flash'] ) ) {
		return null;
	}
	$raw   = sanitize_text_field( wp_unslash( $_COOKIE['mhp_flash'] ) );
	$parts = explode( '.', $raw );
	if ( 2 === count( $parts ) ) {
		$payload = base64_decode( $parts[0], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( $payload && hash_equals( hash_hmac( 'sha256', $payload, wp_salt( 'nonce' ) ), $parts[1] ) ) {
			$j = json_decode( $payload, true );
			if ( is_array( $j ) && isset( $j['m'] ) ) {
				$read = array(
					'm' => (string) $j['m'],
					't' => isset( $j['t'] ) ? (string) $j['t'] : 'ok',
				);
			}
		}
	}
	if ( ! headers_sent() ) {
		setcookie( 'mhp_flash', '', time() - 3600, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true );
	}
	return $read ? $read : null;
}

/**
 * Tell page caches (LiteSpeed, WP Rocket, generic) not to cache this response.
 */
function mhp_nocache() {
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
	do_action( 'litespeed_control_set_nocache', 'meeha-prep personal page' );
	if ( ! headers_sent() ) {
		nocache_headers();
	}
}

/**
 * Client IP for rate limits. REMOTE_ADDR by default (proxy headers are spoofable); behind a CDN that hides the
 * visitor's address, a site can map it with the `mhp_client_ip` filter (e.g. Cloudflare's CF-Connecting-IP).
 *
 * @return string
 */
function mhp_ip() {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	return (string) apply_filters( 'mhp_client_ip', $ip );
}

/**
 * Simple rate limit: true when the action is still allowed (and counts it).
 *
 * @param string $bucket  Bucket name.
 * @param int    $max     Max hits.
 * @param int    $window  Window seconds.
 * @return bool
 */
function mhp_rate_ok( $bucket, $max, $window ) {
	$key = 'mhp_rl_' . md5( $bucket . '|' . mhp_ip() . '|' . get_current_user_id() );
	$n   = (int) get_transient( $key );
	if ( $n >= $max ) {
		return false;
	}
	set_transient( $key, $n + 1, $window );
	return true;
}

/**
 * Short public display name ("তাসনিম র." style when the setting asks for privacy).
 *
 * @param int $user_id User.
 * @return string
 */
function mhp_public_name( $user_id ) {
	$u = get_userdata( (int) $user_id );
	if ( ! $u ) {
		return 'পাঠক';
	}
	$name = trim( (string) $u->display_name );
	if ( '' === $name || false !== strpos( $name, '@' ) ) {
		$name = trim( (string) $u->first_name );
	}
	if ( '' === $name || false !== strpos( $name, '@' ) ) {
		return 'পাঠক';
	}
	if ( 'first' === mhp_opt( 'lb_name', 'first' ) ) {
		$parts = preg_split( '/\s+/u', $name );
		$name  = $parts[0];
	}
	return $name;
}

/**
 * Bangladesh districts (64) for alert preferences and leaderboards.
 *
 * @return string[]
 */
function mhp_districts() {
	return array( 'বাগেরহাট', 'বান্দরবান', 'বরগুনা', 'বরিশাল', 'ভোলা', 'বগুড়া', 'ব্রাহ্মণবাড়িয়া', 'চাঁদপুর', 'চাঁপাইনবাবগঞ্জ', 'চট্টগ্রাম', 'চুয়াডাঙ্গা', 'কুমিল্লা', 'কক্সবাজার', 'ঢাকা', 'দিনাজপুর', 'ফরিদপুর', 'ফেনী', 'গাইবান্ধা', 'গাজীপুর', 'গোপালগঞ্জ', 'হবিগঞ্জ', 'জামালপুর', 'যশোর', 'ঝালকাঠি', 'ঝিনাইদহ', 'জয়পুরহাট', 'খাগড়াছড়ি', 'খুলনা', 'কিশোরগঞ্জ', 'কুড়িগ্রাম', 'কুষ্টিয়া', 'লক্ষ্মীপুর', 'লালমনিরহাট', 'মাদারীপুর', 'মাগুরা', 'মানিকগঞ্জ', 'মেহেরপুর', 'মৌলভীবাজার', 'মুন্সীগঞ্জ', 'ময়মনসিংহ', 'নওগাঁ', 'নড়াইল', 'নারায়ণগঞ্জ', 'নরসিংদী', 'নাটোর', 'নেত্রকোনা', 'নীলফামারী', 'নোয়াখালী', 'পাবনা', 'পঞ্চগড়', 'পটুয়াখালী', 'পিরোজপুর', 'রাজবাড়ী', 'রাজশাহী', 'রাঙ্গামাটি', 'রংপুর', 'সাতক্ষীরা', 'শরীয়তপুর', 'শেরপুর', 'সিরাজগঞ্জ', 'সুনামগঞ্জ', 'সিলেট', 'টাঙ্গাইল', 'ঠাকুরগাঁও' );
}

/**
 * Can the current user manage the section's content (jobs, questions, tests, events)?
 *
 * @return bool
 */
function mhp_can_manage() {
	return current_user_can( 'mhp_manage' ) || current_user_can( 'manage_options' );
}

/**
 * Can the current user see orders, change prices and refund?
 *
 * @return bool
 */
function mhp_can_shop() {
	return current_user_can( 'mhp_manage_orders' ) || current_user_can( 'manage_options' );
}

/*
 * Read (and clear) the flash cookie before the page starts printing, so the cookie can still be deleted.
 */
add_action(
	'template_redirect',
	function () {
		if ( ! empty( $_COOKIE['mhp_flash'] ) && function_exists( 'meeha_campus_is_hub' ) && meeha_campus_is_hub() ) {
			mhp_take_flash();
		}
	},
	1
);
