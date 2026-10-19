<?php
/**
 * WP-Cron: every 10 minutes the lists are recomputed into transients; once a day hourly rows older than
 * 35 days are rolled into the daily table and stale visitor-log rows are removed.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'cron_schedules',
	function ( $s ) {
		$s['meeha_10min'] = array(
			'interval' => 10 * MINUTE_IN_SECONDS,
			'display'  => 'প্রতি ১০ মিনিট (মিহা)',
		);
		return $s;
	}
);

add_action(
	'init',
	function () {
		if ( wp_installing() ) {
			return;
		}
		if ( ! wp_next_scheduled( 'meeha_metrics_cron' ) ) {
			wp_schedule_event( time() + 60, 'meeha_10min', 'meeha_metrics_cron' );
		}
		if ( ! wp_next_scheduled( 'meeha_metrics_daily' ) ) {
			wp_schedule_event( strtotime( 'tomorrow 03:15', time() ) ? strtotime( 'tomorrow 03:15', time() ) : time() + DAY_IN_SECONDS, 'daily', 'meeha_metrics_daily' );
		}
	},
	20
);

add_action(
	'switch_theme',
	function () {
		wp_clear_scheduled_hook( 'meeha_metrics_cron' );
		wp_clear_scheduled_hook( 'meeha_metrics_daily' );
	}
);

/**
 * Recompute every cached list (the 10-minute job).
 *
 * @return array Summary (for the settings tab / tests).
 */
function meeha_metrics_compute_all() {
	$t0  = microtime( true );
	$sum = array();
	foreach ( array( 'today', '7d', '30d', 'all' ) as $w ) {
		$pool = meeha_metrics_compute_most_read( $w, 30 );
		set_transient( 'meeha_mostread_' . $w, $pool, 20 * MINUTE_IN_SECONDS );
		$sum[ 'mostread_' . $w ] = count( $pool );
	}
	$old   = get_transient( 'meeha_trending' );
	$trend = meeha_metrics_compute_trending( 8 );
	set_transient( 'meeha_trending', $trend, 20 * MINUTE_IN_SECONDS );
	$sum['trending'] = count( $trend );
	if ( ! is_array( $old ) || wp_list_pluck( $old, 'id' ) !== wp_list_pluck( $trend, 'id' ) ) {
		delete_transient( 'meeha_search_chips' ); // A's dialog chips are built from trending tags.
	}
	$days = function_exists( 'meeha_opt' ) ? max( 1, (int) meeha_opt( 'home', 'pop_days', 30 ) ) : 30;
	$pop  = meeha_metrics_compute_popular( 12, $days );
	set_transient( 'meeha_popular' . ( 30 === $days ? '' : '_' . $days ), $pop, 20 * MINUTE_IN_SECONDS );
	$sum['popular'] = count( $pop );
	// Per-category pools are recomputed lazily (10-minute transients).
	$sum['ms'] = (int) round( ( microtime( true ) - $t0 ) * 1000 );
	update_option( 'meeha_metrics_last_run', array( 'time' => time() ) + $sum, false );
	/**
	 * After the lists were recomputed.
	 *
	 * @param array $sum Summary.
	 */
	do_action( 'meeha_metrics_computed', $sum );
	return $sum;
}
add_action( 'meeha_metrics_cron', 'meeha_metrics_compute_all' );

/**
 * Roll hourly rows older than $days into the daily table, then delete them; prune the visitor log.
 *
 * @param int      $days Keep hourly rows for this many days.
 * @param int|null $now  Now.
 * @return array rolled, deleted, log_pruned
 */
function meeha_metrics_rollup( $days = 35, $now = null ) {
	global $wpdb;
	if ( ! meeha_metrics_ready() ) {
		return array();
	}
	$now    = null === $now ? time() : (int) $now;
	$h      = meeha_metrics_table( 'hourly' );
	$d      = meeha_metrics_table( 'daily' );
	$l      = meeha_metrics_table( 'log' );
	$cutoff = meeha_metrics_hour( $now - (int) $days * DAY_IN_SECONDS );
	$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, `hour`, views FROM {$h} WHERE `hour` < %s", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	$daily  = array();
	foreach ( (array) $rows as $r ) {
		$day = meeha_metrics_day( strtotime( $r->hour . ' UTC' ) );
		$k   = (int) $r->post_id . '|' . $day;
		$daily[ $k ] = ( isset( $daily[ $k ] ) ? $daily[ $k ] : 0 ) + (int) $r->views;
	}
	foreach ( $daily as $k => $v ) {
		list( $pid, $day ) = explode( '|', $k );
		meeha_metrics_bump( $d, array( 'post_id' => (int) $pid, 'day' => $day ), array( 'views' => $v ) );
	}
	$deleted = $rows ? (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$h} WHERE `hour` < %s", $cutoff ) ) : 0; // phpcs:ignore WordPress.DB.PreparedSQL
	// Visitor log: view/share dedupe rows after 2 days, reactions after 400 days, expired rate-limit windows.
	$pruned  = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$l} WHERE ( kind = 'view' OR kind LIKE %s ) AND ts < %d", $wpdb->esc_like( 'share:' ) . '%', $now - 2 * DAY_IN_SECONDS ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	$pruned += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$l} WHERE kind LIKE %s AND ts < %d", $wpdb->esc_like( 'rl:' ) . '%', $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	$pruned += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$l} WHERE kind = 'react' AND ts < %d", $now - 400 * DAY_IN_SECONDS ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	// Engagement rows are kept for 13 months (the popular window is ≤ 90 days).
	$e = meeha_metrics_table( 'engagement' );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$e} WHERE `day` < %s", wp_date( 'Y-m-d', $now - 400 * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	return array(
		'rolled'     => count( $daily ),
		'deleted'    => $deleted,
		'log_pruned' => $pruned,
	);
}
add_action(
	'meeha_metrics_daily',
	function () {
		meeha_metrics_rollup( 35 );
	}
);

/**
 * Drop the cached lists (e.g. after an editor pins/unpins or a post is unpublished).
 */
function meeha_metrics_flush_lists() {
	delete_transient( 'meeha_trending' );
}
add_action(
	'transition_post_status',
	function ( $new, $old ) {
		if ( 'publish' === $old && 'publish' !== $new ) {
			meeha_metrics_flush_lists();
		}
	},
	10,
	2
);
foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $meeha_hook ) {
	add_action(
		$meeha_hook,
		function ( $mid, $post_id, $key ) {
			if ( in_array( $key, array( '_meeha_trending', '_meeha_trending_at' ), true ) ) {
				meeha_metrics_flush_lists();
			}
		},
		10,
		3
	);
}
unset( $meeha_hook );
