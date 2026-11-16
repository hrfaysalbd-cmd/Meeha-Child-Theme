<?php
/**
 * REST:
 *   POST meeha/v1/assistant        {q, district?} → {intent, mode, text, cards[], chips[], ask?, district?}
 *   GET  meeha/v1/assistant/state  → {live: [{title, url}]}  (60 s public cache; drives the bubble's লাইভ badge)
 *
 * Public like the push / view endpoints (page HTML is cached, so no nonce): same-host Origin/Referer required,
 * per-IP hourly + daily limits (AI tier stricter), 300-character cap, HTML stripped, never page-cached.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

const MEEHA_AS_MAX_LEN = 300;

/**
 * Same host? Origin first, then Referer; a POST with neither is refused (browsers always send Origin on fetch POST).
 *
 * @param WP_REST_Request $req Request.
 * @return bool
 */
function meeha_as_origin_ok( $req ) {
	$home = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	foreach ( array( 'origin', 'referer' ) as $h ) {
		$v = (string) $req->get_header( $h );
		if ( '' !== $v ) {
			return strtolower( (string) wp_parse_url( $v, PHP_URL_HOST ) ) === $home;
		}
	}
	return (bool) apply_filters( 'meeha_assistant_allow_no_origin', false );
}

/**
 * Fixed-window per-IP limiter (theme limiter when present).
 *
 * @param string $bucket Bucket.
 * @param int    $max    Max hits.
 * @param int    $window Seconds.
 * @return bool
 */
function meeha_as_rate( $bucket, $max, $window ) {
	if ( $max <= 0 ) {
		return false;
	}
	if ( function_exists( 'meeha_legacy_rate_ok' ) ) {
		return meeha_legacy_rate_ok( $bucket, $max, $window );
	}
	$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	$key = 'meeha_as_rl_' . $bucket . '_' . substr( wp_hash( $ip ), 0, 16 );
	$n   = (int) get_transient( $key );
	if ( $n >= $max ) {
		return false;
	}
	set_transient( $key, $n + 1, $window );
	return true;
}

/**
 * Mark the response as never cacheable (browser, proxies, LiteSpeed).
 */
function meeha_as_nocache() {
	do_action( 'litespeed_control_set_nocache', 'meeha assistant' );
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
}

/**
 * Clean a question: no HTML, no control characters, single spaces, ≤ 300 characters.
 *
 * @param mixed $q Raw.
 * @return string
 */
function meeha_as_clean_question( $q ) {
	$q = wp_strip_all_tags( (string) $q, true );
	$q = (string) preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $q );
	$q = trim( (string) preg_replace( '/\s+/u', ' ', $q ) );
	return mb_substr( $q, 0, MEEHA_AS_MAX_LEN );
}

/**
 * Public response (drops internal keys).
 *
 * @param array $a Answer.
 * @return array
 */
function meeha_as_public_answer( $a ) {
	unset( $a['posts'] );
	return $a;
}

/**
 * Answer a question (no rate limit / origin logic — used by the route and by tests).
 *
 * @param string $question Clean question.
 * @param array  $ctx      district.
 * @param bool   $allow_ai Whether the AI tier may be tried (rate limit already checked by the caller).
 * @return array
 */
function meeha_as_answer( $question, $ctx = array(), $allow_ai = true ) {
	$in  = meeha_as_detect( $question, $ctx );
	$ai  = $allow_ai && meeha_as_ai_on() && ! in_array( $in['intent'], array( 'help', 'ask_district' ), true );
	$out = meeha_as_basic_answer( $in, $ai ? 8 : 6 );
	$out['mode'] = 'basic';
	if ( $ai ) {
		$posts = $out['posts'];
		$text  = meeha_as_ai_answer( $question, $posts );
		if ( ! is_wp_error( $text ) ) {
			$out['text']  = $text;
			$out['mode']  = 'ai';
			$out['cards'] = array_map( 'meeha_as_card', $posts );
		} else {
			$out['ai_error'] = $text->get_error_code(); // code only — no message, no request data.
			$out['cards']    = array_slice( $out['cards'], 0, 6 );
		}
	}
	return $out;
}

/**
 * Route callback.
 *
 * @param WP_REST_Request $req Request.
 * @return WP_REST_Response|WP_Error
 */
function meeha_as_rest_ask( WP_REST_Request $req ) {
	meeha_as_nocache();
	if ( ! meeha_opt( 'assistant', 'enabled', 1 ) ) {
		return new WP_Error( 'meeha_as_off', 'মিহা সহকারী এখন বন্ধ।', array( 'status' => 403 ) );
	}
	if ( ! meeha_as_origin_ok( $req ) ) {
		return new WP_Error( 'meeha_as_origin', 'অনুমতি নেই।', array( 'status' => 403 ) );
	}
	$raw = $req->get_param( 'q' );
	if ( ! is_scalar( $raw ) ) {
		return new WP_Error( 'meeha_as_q', 'প্রশ্নটি লিখুন।', array( 'status' => 400 ) );
	}
	if ( mb_strlen( (string) $raw ) > 2000 ) {
		return new WP_Error( 'meeha_as_long', 'প্রশ্ন ৩০০ অক্ষরের মধ্যে লিখুন।', array( 'status' => 400 ) );
	}
	$q        = meeha_as_clean_question( $raw );
	$district = meeha_as_valid_district( is_scalar( $req->get_param( 'district' ) ) ? $req->get_param( 'district' ) : '' );
	if ( '' === $q ) {
		return new WP_Error( 'meeha_as_q', 'প্রশ্নটি লিখুন।', array( 'status' => 400 ) );
	}

	$ok = meeha_as_rate( 'asd', (int) meeha_opt( 'assistant', 'rate_day', 60 ), DAY_IN_SECONDS )
		&& meeha_as_rate( 'ash', (int) meeha_opt( 'assistant', 'rate_hour', 20 ), HOUR_IN_SECONDS );
	if ( ! $ok ) {
		meeha_as_log( '', 'limited', 'basic' );
		$r = new WP_REST_Response(
			array(
				'intent' => 'limited',
				'mode'   => 'basic',
				'text'   => 'অনেক প্রশ্ন হয়ে গেছে — একটু পরে আবার চেষ্টা করুন। ততক্ষণ প্রচ্ছদের খবর পড়ে দেখতে পারেন।',
				'cards'  => array(),
				'chips'  => array(),
			),
			429
		);
		return $r;
	}

	$ai_on = meeha_as_ai_on();
	$ckey  = 'meeha_as_c_' . md5( wp_json_encode( array( meeha_as_norm( $q ), $district, wp_date( 'Y-m-d' ), $ai_on, (int) get_option( 'meeha_as_cache_gen', 0 ) ) ) );
	$hit   = get_transient( $ckey );
	if ( is_array( $hit ) ) {
		meeha_as_log( $q, isset( $hit['intent'] ) ? $hit['intent'] : 'search', isset( $hit['mode'] ) ? $hit['mode'] : 'basic' );
		$hit['cached'] = true;
		return new WP_REST_Response( $hit, 200 );
	}

	$allow_ai = $ai_on
		&& meeha_as_rate( 'asad', (int) meeha_opt( 'assistant', 'ai_day', 20 ), DAY_IN_SECONDS )
		&& meeha_as_rate( 'asah', (int) meeha_opt( 'assistant', 'ai_hour', 6 ), HOUR_IN_SECONDS );
	$out = meeha_as_public_answer( meeha_as_answer( $q, array( 'district' => $district ), $allow_ai ) );
	$err = isset( $out['ai_error'] );
	unset( $out['ai_error'] );
	// Cache answers (not AI failures, so the next identical question can try the AI again).
	if ( ! $err ) {
		set_transient( $ckey, $out, 10 * MINUTE_IN_SECONDS );
	}
	meeha_as_log( $q, $out['intent'], $out['mode'] );
	return new WP_REST_Response( $out, 200 );
}

/**
 * Running live posts for the bubble.
 *
 * @return WP_REST_Response
 */
function meeha_as_rest_state() {
	$live = array();
	if ( meeha_opt( 'assistant', 'enabled', 1 ) && meeha_opt( 'assistant', 'live_chip', 1 ) ) {
		foreach ( array_slice( meeha_as_live_posts( 3 ), 0, 3 ) as $p ) {
			$live[] = array(
				'title' => html_entity_decode( wp_strip_all_tags( get_the_title( $p ) ), ENT_QUOTES, 'UTF-8' ),
				'url'   => get_permalink( $p ),
			);
		}
	}
	$r = new WP_REST_Response( array( 'live' => $live ), 200 );
	$r->header( 'Cache-Control', 'public, max-age=60' );
	return $r;
}

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'meeha/v1',
			'/assistant',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => 'meeha_as_rest_ask',
				'args'                => array(
					'q'        => array( 'required' => true ),
					'district' => array( 'required' => false ),
				),
			)
		);
		register_rest_route(
			'meeha/v1',
			'/assistant/state',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => 'meeha_as_rest_state',
			)
		);
	}
);

/**
 * No-cache headers on the POST route (WP's REST server adds its own for logged-in users only).
 */
add_filter(
	'rest_post_dispatch',
	function ( $res, $server, $req ) {
		if ( $res instanceof WP_REST_Response && '/meeha/v1/assistant' === $req->get_route() ) {
			foreach ( wp_get_nocache_headers() as $k => $v ) {
				$res->header( $k, $v );
			}
			$res->header( 'X-LiteSpeed-Cache-Control', 'no-cache' );
		}
		return $res;
	},
	10,
	3
);
