<?php
/**
 * metrics.js on singular views (posts, উক্তি) — and, for the realtime heartbeat, on every public page. The config contains no nonce and nothing user-specific
 * for anonymous visitors, so it is safe in page-cached HTML.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

/**
 * Should metrics.js load on this request? (filter `meeha_metrics_enqueue`)
 *
 * @return bool
 */
function meeha_metrics_wanted() {
	$on = is_singular( meeha_metrics_post_types() );
	// 2.4.2: the realtime-visitor heartbeat lives in the same small script, so it loads on every public page.
	if ( ! $on && function_exists( 'meeha_live_enabled' ) && meeha_live_enabled() && ! is_admin() && ! is_feed() && ! is_robots() && ! is_404() ) {
		$on = true;
	}
	return (bool) apply_filters( 'meeha_metrics_enqueue', $on );
}

add_action(
	'wp_enqueue_scripts',
	function () {
		if ( meeha_metrics_wanted() ) {
			meeha_enqueue_bundle( 'metrics' );
		}
	},
	4
);

add_action(
	'wp_enqueue_scripts',
	function () {
		if ( ! meeha_metrics_wanted() || ! wp_script_is( 'meeha-metrics', 'enqueued' ) ) {
			return;
		}
		$id  = 0;
		$obj = get_queried_object();
		if ( $obj instanceof WP_Post && meeha_metrics_countable_post( $obj->ID ) && ! is_preview() ) {
			$id = (int) $obj->ID;
		}
		$cfg = array(
			'rest'   => esc_url_raw( rest_url( 'meeha/v1/' ) ),
			'id'     => $id,
			'beacon' => 'beacon' === meeha_metrics_mode() && ! meeha_metrics_skip_user(),
			'delay'  => 1000 * max( 1, (int) meeha_opt( 'home', 'view_delay', 5 ) ),
			'live'   => function_exists( 'meeha_live_enabled' ) && meeha_live_enabled() && ! meeha_metrics_skip_user(),
		);
		wp_add_inline_script( 'meeha-metrics', 'window.MeehaMetricsConfig=' . wp_json_encode( $cfg, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG ) . ';', 'before' );
	},
	20
);
