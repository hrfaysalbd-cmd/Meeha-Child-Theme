<?php
/**
 * Live posts — automation (2.4.14). A WP-Cron job every 15 minutes:
 *  - "চুপচাপ লাইভ": a running live post without a new update for N minutes (মিহা থিম › হোমপেজ › লাইভ, default 45)
 *    notifies its assigned reporters and the post's author once per silence (newsroom-os notifications + web push,
 *    only when that plugin is active);
 *  - auto-end: a live / paused post without any update for N hours (default 24) is switched to "লাইভ শেষ", so the
 *    homepage band, the header pill and Google's LIVE label never advertise a story nobody is updating. Can be turned
 *    off.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'cron_schedules',
	function ( $s ) {
		if ( ! isset( $s['meeha_15min'] ) ) {
			$s['meeha_15min'] = array(
				'interval' => 15 * MINUTE_IN_SECONDS,
				'display'  => 'প্রতি ১৫ মিনিট (মিহা)',
			);
		}
		return $s;
	}
);

add_action(
	'init',
	function () {
		if ( wp_installing() ) {
			return;
		}
		if ( ! wp_next_scheduled( 'meeha_lb_watch' ) ) {
			wp_schedule_event( time() + 120, 'meeha_15min', 'meeha_lb_watch' );
		}
	},
	20
);

add_action(
	'switch_theme',
	function () {
		wp_clear_scheduled_hook( 'meeha_lb_watch' );
	}
);

add_action( 'meeha_lb_watch', 'meeha_lb_watch_run' );

/**
 * One pass of the watcher.
 *
 * @param int|null $now Unix time (tests).
 * @return array Summary: checked, notified (ids), ended (ids).
 */
function meeha_lb_watch_run( $now = null ) {
	$now      = null === $now ? time() : (int) $now;
	$stale    = max( 0, (int) meeha_opt( 'home', 'live_stale_minutes', 45 ) ) * MINUTE_IN_SECONDS;
	$auto_end = (bool) meeha_opt( 'home', 'live_auto_end', 1 );
	$end_secs = max( 1, (int) meeha_opt( 'home', 'live_auto_end_hours', 24 ) ) * HOUR_IN_SECONDS;
	$sum      = array(
		'checked'  => 0,
		'notified' => array(),
		'ended'    => array(),
	);

	$ids = get_posts(
		array(
			'post_type'        => 'post',
			'post_status'      => 'publish',
			'posts_per_page'   => 50,
			'fields'           => 'ids',
			'meta_key'         => '_meeha_live_status', // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value'       => array( 'live', 'paused' ), // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_compare'     => 'IN',
			'no_found_rows'    => true,
			'suppress_filters' => true,
		)
	);
	foreach ( $ids as $id ) {
		$cfg = meeha_lb_config( $id );
		if ( ! $cfg ) {
			continue;
		}
		++$sum['checked'];
		$last = (int) get_post_meta( $id, '_meeha_live_last', true );
		// No update yet: count the silence from the moment the live started (or the post was published).
		$from = max( $last, (int) $cfg['started'], $last ? 0 : (int) get_post_time( 'U', true, $id ) );
		$idle = $now - $from;

		if ( $auto_end && $idle >= $end_secs ) {
			meeha_lb_save_config( $id, array( 'status' => 'ended' ) );
			update_post_meta( $id, '_meeha_live_auto_ended', $now );
			$sum['ended'][] = (int) $id;
			/**
			 * A live post was ended automatically after a long silence.
			 *
			 * @param int $id   Post ID.
			 * @param int $idle Seconds since the last update.
			 */
			do_action( 'meeha_live_auto_ended', (int) $id, $idle );
			continue;
		}

		if ( $stale && 'live' === $cfg['status'] && $idle >= $stale ) {
			// Once per silence: remember which "last update" we already warned about.
			if ( (int) get_post_meta( $id, '_meeha_live_stale_for', true ) === $from ) {
				continue;
			}
			update_post_meta( $id, '_meeha_live_stale_for', $from );
			meeha_lb_notify_stale( get_post( $id ), $cfg, $idle );
			$sum['notified'][] = (int) $id;
		}
	}
	update_option( 'meeha_lb_watch_last', array( 'time' => $now ) + $sum, false );
	return $sum;
}

/**
 * Tell the people on a live post that it has gone quiet.
 *
 * @param WP_Post $post Live post.
 * @param array   $cfg  Configuration.
 * @param int     $idle Seconds without an update.
 */
function meeha_lb_notify_stale( $post, $cfg, $idle ) {
	if ( ! $post ) {
		return;
	}
	$to = array_merge( array( (int) $post->post_author ), (array) $cfg['reporters'] );
	$to = array_values( array_unique( array_filter( array_map( 'intval', $to ) ) ) );
	$to = (array) apply_filters( 'meeha_live_stale_recipients', $to, $post, $cfg );
	if ( ! $to ) {
		return;
	}
	$mins  = (int) floor( $idle / MINUTE_IN_SECONDS );
	$span  = $mins >= 120 ? meeha_bn_digits( (int) floor( $mins / 60 ) ) . ' ঘণ্টা' : meeha_bn_digits( $mins ) . ' মিনিট';
	$title = 'লাইভে ' . $span . ' কোনো আপডেট নেই';
	$body  = get_the_title( $post ) . ' — পাঠকেরা অপেক্ষা করছেন। নতুন আপডেট দিন, বা ঘটনা শেষ হলে লাইভটি বন্ধ করুন।';
	if ( class_exists( 'NROS_Notifications' ) && is_callable( array( 'NROS_Notifications', 'add' ) ) ) {
		NROS_Notifications::add( $to, 'live_stale', $title, $body, '/app/live/' . (int) $post->ID, (int) $post->ID );
	}
	/**
	 * A running live post went quiet (for other channels: e-mail, Slack …).
	 *
	 * @param WP_Post $post Post.
	 * @param int[]   $to   Users notified.
	 * @param int     $idle Seconds without an update.
	 */
	do_action( 'meeha_live_stale', $post, $to, $idle );
}
