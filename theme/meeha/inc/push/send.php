<?php
/**
 * Sending: automatic on publish (a minute later, so meta written after the status change — breaking flag, set by
 * the publisher app — is seen), manual from the posts list; delivery runs in WP-Cron batches of 300.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

define( 'MEEHA_PUSH_BATCH', 300 );

/**
 * Icon URL for the prompt and the notification.
 *
 * @param int $size Size.
 * @return string
 */
function meeha_push_icon( $size = 192 ) {
	$id = (int) meeha_opt( 'alerts', 'icon', 0 );
	$u  = $id ? wp_get_attachment_image_url( $id, array( $size, $size ) ) : '';
	if ( ! $u ) {
		$u = get_site_icon_url( $size );
	}
	return $u ? $u : MEEHA_URI . '/assets/img/favicon.svg';
}

/**
 * Is a post flagged ব্রেকিং?
 *
 * @param int $post_id Post.
 * @return bool
 */
function meeha_push_is_breaking( $post_id ) {
	return '1' === (string) get_post_meta( $post_id, '_meeha_breaking', true );
}

/**
 * Notification JSON for a post.
 *
 * @param int $post_id Post.
 * @return string
 */
function meeha_push_payload( $post_id ) {
	$prefix = trim( (string) meeha_opt( 'alerts', 'title_prefix', 'মিহা নিউজ' ) );
	$title  = html_entity_decode( wp_strip_all_tags( get_the_title( $post_id ) ), ENT_QUOTES, 'UTF-8' );
	$img    = get_the_post_thumbnail_url( $post_id, 'medium_large' );
	return (string) wp_json_encode(
		array(
			'title' => ( meeha_push_is_breaking( $post_id ) ? 'ব্রেকিং: ' : '' ) . ( '' !== $prefix ? $prefix : get_bloginfo( 'name' ) ),
			'body'  => mb_substr( $title, 0, 180 ),
			'url'   => add_query_arg(
				array(
					'utm_source' => 'push',
					'utm_medium' => 'web_push',
				),
				get_permalink( $post_id )
			),
			'icon'  => meeha_push_icon( 192 ),
			'image' => $img ? $img : '',
			'tag'   => 'meeha-' . $post_id,
		),
		JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
	);
}

/**
 * Start sending a post to every subscriber.
 *
 * @param int $post_id Post.
 * @return bool queued
 */
function meeha_push_queue( $post_id ) {
	if ( ! meeha_push_supported() || 'publish' !== get_post_status( $post_id ) ) {
		return false;
	}
	update_post_meta( $post_id, '_meeha_push_sent', time() );
	update_option(
		'meeha_push_last',
		array(
			'post'    => (int) $post_id,
			'sent'    => 0,
			'gone'    => 0,
			'started' => time(),
		),
		false
	);
	wp_schedule_single_event( time(), 'meeha_push_run', array( (int) $post_id, 0 ) );
	if ( function_exists( 'spawn_cron' ) ) {
		spawn_cron();
	}
	return true;
}

/**
 * One delivery batch; schedules the next.
 *
 * @param int $post_id Post.
 * @param int $after   Last subscription id done.
 */
function meeha_push_run( $post_id, $after ) {
	$rows = meeha_push_batch( (int) $after, MEEHA_PUSH_BATCH );
	if ( ! $rows ) {
		return;
	}
	$res = meeha_push_deliver( $rows, meeha_push_payload( (int) $post_id ) );
	global $wpdb;
	$t = meeha_push_table();
	if ( $res['gone'] ) {
		$wpdb->query( "DELETE FROM {$t} WHERE id IN (" . implode( ',', array_map( 'intval', $res['gone'] ) ) . ')' ); // phpcs:ignore WordPress.DB
	}
	if ( $res['failed'] ) {
		$ids = implode( ',', array_map( 'intval', $res['failed'] ) );
		$wpdb->query( "UPDATE {$t} SET fails = fails + 1 WHERE id IN ({$ids})" ); // phpcs:ignore WordPress.DB
		$wpdb->query( "DELETE FROM {$t} WHERE fails > 8" ); // phpcs:ignore WordPress.DB
	}
	$ok = array_diff( wp_list_pluck( $rows, 'id' ), $res['gone'], $res['failed'] );
	if ( $ok ) {
		$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET fails = 0, last_ok = %s WHERE id IN (" . implode( ',', array_map( 'intval', $ok ) ) . ')', current_time( 'mysql', true ) ) ); // phpcs:ignore WordPress.DB
	}
	delete_transient( 'meeha_push_count' );
	$last = get_option( 'meeha_push_last', array() );
	if ( is_array( $last ) && (int) $post_id === (int) ( isset( $last['post'] ) ? $last['post'] : 0 ) ) {
		$last['sent'] = (int) $last['sent'] + (int) $res['sent'];
		$last['gone'] = (int) $last['gone'] + count( $res['gone'] );
		update_option( 'meeha_push_last', $last, false );
	}
	if ( count( $rows ) === MEEHA_PUSH_BATCH ) {
		$end = end( $rows );
		wp_schedule_single_event( time(), 'meeha_push_run', array( (int) $post_id, (int) $end['id'] ) );
	}
}
add_action( 'meeha_push_run', 'meeha_push_run', 10, 2 );

/**
 * Should a just-published post go out automatically?
 *
 * @param int $post_id Post.
 * @return bool
 */
function meeha_push_auto_wanted( $post_id ) {
	$mode = (string) meeha_opt( 'alerts', 'auto', 'breaking' );
	if ( ! meeha_opt( 'alerts', 'enabled', 1 ) || 'off' === $mode || 'publish' !== get_post_status( $post_id ) || get_post_meta( $post_id, '_meeha_push_sent', true ) ) {
		return false;
	}
	if ( 'breaking' === $mode && ! meeha_push_is_breaking( $post_id ) ) {
		return false;
	}
	if ( time() - (int) get_post_time( 'U', true, $post_id ) > DAY_IN_SECONDS ) {
		return false; /* an old post re-published */
	}
	$cats = array_filter( array_map( 'intval', (array) meeha_opt( 'alerts', 'categories', array() ) ) );
	if ( $cats && ! has_category( $cats, $post_id ) ) {
		return false;
	}
	$log = array_filter(
		(array) get_option( 'meeha_push_auto_log', array() ),
		function ( $t ) {
			return (int) $t > time() - HOUR_IN_SECONDS;
		}
	);
	if ( count( $log ) >= max( 1, (int) meeha_opt( 'alerts', 'hourly_cap', 3 ) ) ) {
		return false;
	}
	$log[] = time();
	update_option( 'meeha_push_auto_log', array_values( $log ), false );
	return true;
}

add_action(
	'transition_post_status',
	function ( $new, $old, $post ) {
		if ( 'publish' === $new && 'publish' !== $old && 'post' === $post->post_type && 'off' !== meeha_opt( 'alerts', 'auto', 'breaking' ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'meeha_push_auto', array( (int) $post->ID ) );
		}
	},
	10,
	3
);
add_action(
	'meeha_push_auto',
	function ( $post_id ) {
		if ( meeha_push_auto_wanted( (int) $post_id ) ) {
			meeha_push_queue( (int) $post_id );
		}
	}
);

/* ---- manual send: posts list row action ---- */
add_filter(
	'post_row_actions',
	function ( $actions, $post ) {
		if ( 'post' !== $post->post_type || 'publish' !== $post->post_status || ! current_user_can( 'edit_others_posts' ) || ! meeha_opt( 'alerts', 'enabled', 1 ) ) {
			return $actions;
		}
		$sent = (int) get_post_meta( $post->ID, '_meeha_push_sent', true );
		$url  = wp_nonce_url( admin_url( 'admin-post.php?action=meeha_push_send&post=' . $post->ID ), 'meeha_push_send_' . $post->ID );
		$ask  = $sent ? 'এই খবরের এলার্ট আগেই পাঠানো হয়েছে। আবার সব গ্রাহককে পাঠাবেন?' : 'এই খবরটি সব গ্রাহকের কাছে নিউজ এলার্ট হিসেবে যাবে। পাঠাবেন?';
		$actions['meeha_push'] = sprintf(
			'<a href="%s" onclick="return confirm(%s)">%s</a>',
			esc_url( $url ),
			esc_attr( wp_json_encode( $ask ) ),
			$sent ? 'এলার্ট আবার পাঠান' : 'নিউজ এলার্ট পাঠান'
		);
		return $actions;
	},
	10,
	2
);
add_action(
	'admin_post_meeha_push_send',
	function () {
		$id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		check_admin_referer( 'meeha_push_send_' . $id );
		if ( ! $id || ! current_user_can( 'edit_others_posts' ) ) {
			wp_die( 'অনুমতি নেই।' );
		}
		$ok = meeha_push_queue( $id );
		wp_safe_redirect( add_query_arg( 'meeha_push', $ok ? 'queued' : 'failed', wp_get_referer() ? wp_get_referer() : admin_url( 'edit.php' ) ) );
		exit;
	}
);
add_action(
	'admin_notices',
	function () {
		if ( empty( $_GET['meeha_push'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$ok = 'queued' === $_GET['meeha_push']; // phpcs:ignore WordPress.Security.NonceVerification
		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			$ok ? 'success' : 'error',
			esc_html( $ok ? 'নিউজ এলার্ট পাঠানো শুরু হয়েছে (' . meeha_bn_digits( meeha_push_count() ) . ' টি ব্রাউজার)। ফল দেখুন মিহা থিম › নিউজ এলার্ট-এ।' : 'এলার্ট পাঠানো যায়নি — খবরটি প্রকাশিত কি না এবং সার্ভারে openssl আছে কি না দেখুন।' )
		);
	}
);
