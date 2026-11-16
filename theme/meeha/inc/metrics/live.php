<?php
/**
 * Realtime visitors (2.4.2): every open tab sends a small heartbeat (metrics.js → POST meeha/v1/ping) when it
 * opens and then once a minute while it is visible. One row per browser in `meeha_live`; "on the site now" =
 * rows seen in the last MEEHA_LIVE_WINDOW seconds. Nothing personal is stored: the row key is a hash of the
 * visitor's IP bucket and a random per-browser id, plus the page's post id, device class and traffic source.
 *
 * Read API (used by newsroom-os' publisher dashboard): meeha_live_summary().
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

const MEEHA_LIVE_WINDOW = 150; // A tab pings every 60 s while visible; 2½ minutes tolerates one lost ping.

/**
 * Realtime tracking on? (settings: হোমপেজ › রিয়েলটাইম ভিজিটর, default on).
 *
 * @return bool
 */
function meeha_live_enabled() {
	$on = function_exists( 'meeha_opt' ) ? (int) meeha_opt( 'home', 'live_on', 1 ) : 1;
	return (bool) apply_filters( 'meeha_live_enabled', 1 === $on );
}

/**
 * Traffic-source bucket from the landing page's referrer host (sent by the browser on the first ping).
 *
 * @param string $ref Referrer URL or host.
 * @return string direct|search|facebook|social|internal|other
 */
function meeha_live_source( $ref ) {
	$ref = strtolower( trim( (string) $ref ) );
	if ( '' === $ref ) {
		return 'direct';
	}
	$host = (string) wp_parse_url( false === strpos( $ref, '//' ) ? 'https://' . $ref : $ref, PHP_URL_HOST );
	$host = preg_replace( '/^(www|m|l|lm|mobile)\./', '', $host );
	if ( '' === $host ) {
		return 'direct';
	}
	if ( $host === preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) ) {
		return 'internal';
	}
	if ( preg_match( '/(^|\.)(facebook\.com|fb\.com|fb\.me|messenger\.com)$/', $host ) ) {
		return 'facebook';
	}
	if ( preg_match( '/(^|\.)(google\.[a-z.]+|bing\.com|yahoo\.com|duckduckgo\.com|yandex\.[a-z]+|baidu\.com|news\.google\.com)$/', $host ) || 0 === strpos( $host, 'google.' ) ) {
		return 'search';
	}
	if ( preg_match( '/(^|\.)(t\.co|x\.com|twitter\.com|whatsapp\.com|wa\.me|telegram\.org|t\.me|youtube\.com|instagram\.com|linkedin\.com|tiktok\.com|reddit\.com|pinterest\.com|threads\.net)$/', $host ) ) {
		return 'social';
	}
	return 'other';
}

/**
 * Record one heartbeat — a single-row upsert (UPDATE, else INSERT IGNORE), so it stays cheap.
 *
 * @param string $client Random per-browser id from the page (≤ 40 chars).
 * @param int    $post_id Post being read (0 = home/archive).
 * @param string $ref     Landing referrer (first ping only).
 * @param string $ua      User agent.
 * @return bool
 */
function meeha_live_ping( $client, $post_id, $ref = '', $ua = '' ) {
	global $wpdb;
	if ( ! meeha_metrics_ready() ) {
		meeha_metrics_install();
	}
	$t      = meeha_metrics_table( 'live' );
	$now    = time();
	$client = substr( preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $client ), 0, 40 );
	$sid    = substr( wp_hash( meeha_metrics_vkey() . '|' . $client . '|meeha-live' ), 0, 24 );
	$dev    = preg_match( '/Mobi|Android|iPhone|iPod|Opera Mini|IEMobile/i', (string) $ua ) ? 'm' : ( preg_match( '/iPad|Tablet/i', (string) $ua ) ? 't' : 'd' );
	$upd    = $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET post_id = %d, ts = %d WHERE sid = %s", (int) $post_id, $now, $sid ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	if ( ! $upd ) {
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$t} (sid, post_id, dev, src, first, ts) VALUES (%s, %d, %s, %s, %d, %d)", $sid, (int) $post_id, $dev, meeha_live_source( $ref ), $now, $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}
	// Rows are kept for two days (today's visitor count needs since-midnight rows); pruned on ~1 % of pings.
	if ( 1 === wp_rand( 1, 100 ) ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE ts < %d", $now - 2 * DAY_IN_SECONDS ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}
	return true;
}

/**
 * Realtime summary for dashboards.
 *
 * @param int $top How many "being read now" posts.
 * @return array now, last30, today, devices{d,m,t}, sources{…}, pages[{id,title,url,n}], window
 */
function meeha_live_summary( $top = 8 ) {
	global $wpdb;
	$out = array(
		'enabled' => meeha_live_enabled(),
		'window'  => MEEHA_LIVE_WINDOW,
		'now'     => 0,
		'last30'  => 0,
		'today'   => 0,
		'devices' => array( 'd' => 0, 'm' => 0, 't' => 0 ),
		'sources' => array(),
		'pages'   => array(),
	);
	if ( ! meeha_metrics_ready() ) {
		return $out;
	}
	$t   = meeha_metrics_table( 'live' );
	$now = time();
	$cut = $now - MEEHA_LIVE_WINDOW;
	$out['now']    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE ts >= %d", $cut ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	$out['last30'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE ts >= %d", $now - 30 * MINUTE_IN_SECONDS ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	$out['today']  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE ts >= %d", meeha_metrics_local_midnight( $now ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT dev, COUNT(*) AS n FROM {$t} WHERE ts >= %d GROUP BY dev", $cut ) ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL
		$out['devices'][ (string) $r->dev ] = (int) $r->n;
	}
	// Sources over the last 30 minutes (steadier than the 2½-minute window).
	foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT src, COUNT(*) AS n FROM {$t} WHERE ts >= %d GROUP BY src ORDER BY n DESC", $now - 30 * MINUTE_IN_SECONDS ) ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL
		$out['sources'][ '' === (string) $r->src ? 'direct' : (string) $r->src ] = (int) $r->n;
	}
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, COUNT(*) AS n FROM {$t} WHERE ts >= %d GROUP BY post_id ORDER BY n DESC LIMIT %d", $cut, (int) $top + 1 ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	foreach ( (array) $rows as $r ) {
		$pid = (int) $r->post_id;
		if ( $pid ) {
			$p = get_post( $pid );
			if ( ! $p ) {
				continue;
			}
			$out['pages'][] = array( 'id' => $pid, 'title' => html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ), 'url' => get_permalink( $p ), 'n' => (int) $r->n );
		} else {
			$out['pages'][] = array( 'id' => 0, 'title' => 'প্রথম পাতা / বিভাগ / অন্যান্য পাতা', 'url' => home_url( '/' ), 'n' => (int) $r->n );
		}
		if ( count( $out['pages'] ) >= $top ) {
			break;
		}
	}
	return $out;
}

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'meeha/v1',
			'/ping',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'args'                => array(
					'c'   => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
					'id'  => array( 'default' => 0, 'sanitize_callback' => 'absint' ),
					'ref' => array( 'default' => '', 'sanitize_callback' => 'esc_url_raw' ),
				),
				'callback'            => function ( WP_REST_Request $r ) {
					if ( ! meeha_live_enabled() ) {
						return rest_ensure_response( array( 'ok' => false ) );
					}
					// Same guards as the view beacon, minus the rate-limit table write (a ping is one cheap upsert).
					if ( ! meeha_metrics_same_origin() || meeha_metrics_is_bot() ) {
						return new WP_Error( 'meeha_ping', 'গোনা হয় না।', array( 'status' => 403 ) );
					}
					$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
					meeha_live_ping( (string) $r['c'], (int) $r['id'], (string) $r['ref'], $ua );
					do_action( 'meeha_live_pinged' );
					$res = rest_ensure_response( array( 'ok' => true ) );
					$res->header( 'Cache-Control', 'no-store' );
					return $res;
				},
			)
		);
	}
);
