<?php
/**
 * Live posts — the reader's poll: GET meeha/v1/liveblog/{id}?since=<unix>  (or ?before=<unix> for older updates).
 *
 * The open page asks every ~30 s (only while the tab is visible) for updates changed since its last answer. The reply
 * carries the changed updates as ready HTML, the ids of every published update (so deleted ones disappear), the
 * re-rendered top block (status, scoreboard / case file) and the live state. Public, read-only, no personal data.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'meeha/v1',
			'/liveblog/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => 'meeha_lb_rest_poll',
				'permission_callback' => '__return_true',
				'args'                => array(
					'since'  => array(
						'type'    => 'integer',
						'default' => 0,
					),
					'before' => array(
						'type'    => 'integer',
						'default' => 0,
					),
				),
			)
		);
	}
);

/**
 * Poll handler.
 *
 * @param WP_REST_Request $req Request.
 * @return WP_REST_Response|WP_Error
 */
function meeha_lb_rest_poll( $req ) {
	$post = get_post( (int) $req['id'] );
	if ( ! $post || 'publish' !== $post->post_status || post_password_required( $post ) ) {
		return new WP_Error( 'meeha_lb_missing', 'পাওয়া যায়নি', array( 'status' => 404 ) );
	}
	$cfg = meeha_lb_config( $post );
	if ( ! $cfg ) {
		return new WP_Error( 'meeha_lb_off', 'এটি লাইভ পোস্ট নয়', array( 'status' => 404 ) );
	}
	$now = time();

	if ( (int) $req['before'] > 0 ) {
		$older = meeha_lb_entries(
			$post->ID,
			array(
				'before' => (int) $req['before'],
				'limit'  => MEEHA_LB_PAGE + 1,
			)
		);
		$more  = count( $older ) > MEEHA_LB_PAGE;
		$items = array();
		foreach ( array_slice( $older, 0, MEEHA_LB_PAGE ) as $e ) {
			$items[] = meeha_lb_entry_payload( $e, $cfg );
		}
		$res = new WP_REST_Response(
			array(
				'entries' => $items,
				'more'    => $more,
			)
		);
		$res->header( 'Cache-Control', 'public, max-age=60' );
		return $res;
	}

	$since   = max( 0, (int) $req['since'] - 5 ); // a few seconds of overlap; the page replaces by id.
	$changed = array();
	if ( $since > 0 ) {
		foreach ( meeha_lb_entries( $post->ID, array( 'since' => $since, 'limit' => 60 ) ) as $e ) {
			$changed[] = meeha_lb_entry_payload( $e, $cfg );
		}
	}
	$res = new WP_REST_Response(
		array(
			'status'  => $cfg['status'],
			'now'     => $now,
			'entries' => $changed,
			'ids'     => meeha_lb_entry_ids( $post->ID ),
			'top'     => meeha_lb_top_html( $post, $cfg ),
			'next'    => 'live' === $cfg['status'] ? 30 : ( 'paused' === $cfg['status'] ? 90 : 0 ),
		)
	);
	$res->header( 'Cache-Control', 'public, max-age=5' );
	return $res;
}

/**
 * One update for the reader.
 *
 * @param WP_Post $e   Update.
 * @param array   $cfg Configuration.
 * @return array
 */
function meeha_lb_entry_payload( $e, $cfg ) {
	return array(
		'id'     => (int) $e->ID,
		't'      => (int) get_post_time( 'U', true, $e ),
		'pinned' => (bool) get_post_meta( $e->ID, '_lb_pinned', true ),
		'html'   => meeha_lb_entry_html( $e, $cfg ),
	);
}
