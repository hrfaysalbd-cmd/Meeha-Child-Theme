<?php
/**
 * REST: meeha/v1/push/subscribe and meeha/v1/push/unsubscribe (public, like the view beacon: the page HTML is
 * cached, so there is no nonce; abuse is limited by the endpoint host check and a per-IP rate limit).
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

/**
 * Subscription fields from a request body ({endpoint, keys: {p256dh, auth}}).
 *
 * @param WP_REST_Request $req Request.
 * @return array|WP_Error
 */
function meeha_push_read_sub( $req ) {
	$endpoint = trim( (string) $req->get_param( 'endpoint' ) );
	$keys     = (array) $req->get_param( 'keys' );
	$p256dh   = isset( $keys['p256dh'] ) ? (string) $keys['p256dh'] : '';
	$auth     = isset( $keys['auth'] ) ? (string) $keys['auth'] : '';
	if ( ! meeha_push_endpoint_ok( $endpoint ) ) {
		return new WP_Error( 'meeha_push_endpoint', 'অচেনা পুশ সার্ভিস।', array( 'status' => 400 ) );
	}
	if ( ! preg_match( '/^[A-Za-z0-9_-]{80,100}$/', $p256dh ) || ! preg_match( '/^[A-Za-z0-9_-]{16,32}$/', $auth ) ) {
		return new WP_Error( 'meeha_push_keys', 'ব্রাউজারের কী সঠিক নয়।', array( 'status' => 400 ) );
	}
	return array( $endpoint, $p256dh, $auth );
}

/**
 * Per-IP rate limit: 20 calls an hour.
 *
 * @return bool true when allowed
 */
function meeha_push_rate_ok() {
	$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	$key = 'meeha_push_rl_' . md5( $ip );
	$n   = (int) get_transient( $key );
	if ( $n >= 20 ) {
		return false;
	}
	set_transient( $key, $n + 1, HOUR_IN_SECONDS );
	return true;
}

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'meeha/v1',
			'/push/subscribe',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => function ( WP_REST_Request $req ) {
					if ( ! meeha_opt( 'alerts', 'enabled', 1 ) || ! meeha_push_supported() ) {
						return new WP_Error( 'meeha_push_off', 'নিউজ এলার্ট এখন বন্ধ।', array( 'status' => 403 ) );
					}
					if ( ! meeha_push_rate_ok() ) {
						return new WP_Error( 'meeha_push_rate', 'একটু পরে আবার চেষ্টা করুন।', array( 'status' => 429 ) );
					}
					$sub = meeha_push_read_sub( $req );
					if ( is_wp_error( $sub ) ) {
						return $sub;
					}
					if ( ! meeha_push_save_sub( $sub[0], $sub[1], $sub[2] ) ) {
						return new WP_Error( 'meeha_push_db', 'সংরক্ষণ করা যায়নি।', array( 'status' => 500 ) );
					}
					return array( 'ok' => true );
				},
			)
		);
		register_rest_route(
			'meeha/v1',
			'/push/unsubscribe',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => function ( WP_REST_Request $req ) {
					$endpoint = trim( (string) $req->get_param( 'endpoint' ) );
					if ( meeha_push_endpoint_ok( $endpoint ) && meeha_push_rate_ok() ) {
						meeha_push_delete_sub( $endpoint );
					}
					return array( 'ok' => true );
				},
			)
		);
	}
);
