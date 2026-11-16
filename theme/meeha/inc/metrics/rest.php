<?php
/**
 * REST endpoints (namespace meeha/v1) + legacy admin-ajax wrappers.
 *
 * Writes (no nonce — the pages are publicly cached): same-origin Origin/Referer required, bot UAs refused,
 * per-visitor (hashed IP) rate limits, strict validation (published post of a counted type, allowed type).
 *   POST view  {id}             → {counted}
 *   POST share {id, network}    → {counts, total, counted}
 *   POST react {id, type, prev} → {counts, mine, changed}
 * Read:
 *   GET most-read?window=today|7d|30d|all&count=5&cat=ID → {window, html, items[]} (Cache-Control public 300 s)
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

/**
 * `m-rank` list markup (ranked numerals via CSS counters).
 *
 * @param WP_Post[] $posts Posts.
 * @param array     $args  kicker (bool, default true), views (bool).
 * @return string
 */
function meeha_rank_list_html( $posts, $args = array() ) {
	$args = wp_parse_args(
		(array) $args,
		array(
			'kicker' => true,
			'views'  => false,
		)
	);
	if ( ! $posts ) {
		return '<p class="m-rank__empty">এই সময়ে এখনো কোনো খবর নেই।</p>';
	}
	$out = '<ol class="m-rank" role="list">';
	foreach ( $posts as $p ) {
		$meta = array();
		if ( $args['kicker'] ) {
			$k = meeha_post_kicker( $p );
			if ( '' !== $k ) {
				$meta[] = '<span>' . esc_html( $k ) . '</span>';
			}
		}
		if ( $args['views'] && meeha_get_views( $p->ID ) ) {
			$meta[] = '<span>' . esc_html( meeha_bn_number_short( meeha_get_views( $p->ID ) ) ) . ' বার পঠিত</span>';
		}
		$out .= '<li><div><a href="' . esc_url( get_permalink( $p ) ) . '">' . esc_html( get_the_title( $p ) ) . '</a>' .
			( $meta ? '<div class="m-meta">' . implode( '', $meta ) . '</div>' : '' ) . '</div></li>';
	}
	return $out . '</ol>';
}

/**
 * Common guard for the write endpoints.
 *
 * @param string $bucket Rate-limit bucket.
 * @param int    $max    Max per window.
 * @param int    $window Window.
 * @return true|WP_Error
 */
function meeha_metrics_guard( $bucket, $max, $window ) {
	if ( ! meeha_metrics_same_origin() ) {
		return new WP_Error( 'meeha_origin', 'অনুরোধটি এই সাইট থেকে আসেনি।', array( 'status' => 403 ) );
	}
	if ( meeha_metrics_is_bot() ) {
		return new WP_Error( 'meeha_bot', 'স্বয়ংক্রিয় অনুরোধ গোনা হয় না।', array( 'status' => 403 ) );
	}
	if ( ! meeha_metrics_ready() ) {
		meeha_metrics_install();
	}
	if ( ! meeha_metrics_rate_ok( $bucket, $max, $window ) ) {
		return new WP_Error( 'meeha_rate', 'অনেক বেশি অনুরোধ — একটু পরে আবার চেষ্টা করুন।', array( 'status' => 429 ) );
	}
	return true;
}

/**
 * Validate the post param.
 *
 * @param mixed $id Id.
 * @return WP_Post|WP_Error
 */
function meeha_metrics_rest_post( $id ) {
	$p = meeha_metrics_countable_post( $id );
	return $p ? $p : new WP_Error( 'meeha_post', 'খবরটি পাওয়া যায়নি।', array( 'status' => 404 ) );
}

add_action(
	'rest_api_init',
	function () {
		$id_arg = array(
			'required'          => true,
			'validate_callback' => function ( $v ) {
				return is_numeric( $v ) && (int) $v > 0;
			},
			'sanitize_callback' => 'absint',
		);
		register_rest_route(
			'meeha/v1',
			'/view',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'args'                => array( 'id' => $id_arg ),
				'callback'            => function ( WP_REST_Request $r ) {
					$g = meeha_metrics_guard( 'view', 120, 10 * MINUTE_IN_SECONDS );
					if ( is_wp_error( $g ) ) {
						return $g;
					}
					$p = meeha_metrics_rest_post( $r['id'] );
					if ( is_wp_error( $p ) ) {
						return $p;
					}
					if ( 'beacon' !== meeha_metrics_mode() ) {
						return rest_ensure_response( array( 'counted' => false, 'mode' => 'legacy' ) );
					}
					return rest_ensure_response( array( 'counted' => meeha_metrics_record_view( $p->ID ) ) );
				},
			)
		);
		register_rest_route(
			'meeha/v1',
			'/share',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'args'                => array(
					'id'      => $id_arg,
					'network' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => function ( $v ) {
							return in_array( sanitize_key( (string) $v ), meeha_metrics_share_networks(), true );
						},
					),
				),
				'callback'            => function ( WP_REST_Request $r ) {
					$g = meeha_metrics_guard( 'share', 30, 10 * MINUTE_IN_SECONDS );
					if ( is_wp_error( $g ) ) {
						return $g;
					}
					$p = meeha_metrics_rest_post( $r['id'] );
					if ( is_wp_error( $p ) ) {
						return $p;
					}
					return rest_ensure_response( meeha_metrics_record_share( $p->ID, (string) $r['network'] ) );
				},
			)
		);
		register_rest_route(
			'meeha/v1',
			'/react',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'args'                => array(
					'id'   => $id_arg,
					'type' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => function ( $v ) {
							return in_array( sanitize_key( (string) $v ), meeha_metrics_reaction_types(), true );
						},
					),
					'prev' => array(
						'required'          => false,
						'sanitize_callback' => 'sanitize_key',
					),
				),
				'callback'            => function ( WP_REST_Request $r ) {
					$g = meeha_metrics_guard( 'react', 30, 10 * MINUTE_IN_SECONDS );
					if ( is_wp_error( $g ) ) {
						return $g;
					}
					$p = meeha_metrics_rest_post( $r['id'] );
					if ( is_wp_error( $p ) ) {
						return $p;
					}
					return rest_ensure_response( meeha_metrics_record_reaction( $p->ID, (string) $r['type'], (string) $r['prev'] ) );
				},
			)
		);
		register_rest_route(
			'meeha/v1',
			'/most-read',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'args'                => array(
					'window' => array(
						'default'           => '7d',
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => function ( $v ) {
							return in_array( sanitize_key( (string) $v ), meeha_metrics_windows(), true );
						},
					),
					'count'  => array(
						'default'           => 5,
						'sanitize_callback' => 'absint',
					),
					'cat'    => array(
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
				),
				'callback'            => function ( WP_REST_Request $r ) {
					$count = max( 1, min( 10, (int) $r['count'] ) );
					$cat   = (int) $r['cat'];
					if ( $cat && ! term_exists( $cat, 'category' ) ) {
						$cat = 0;
					}
					$posts = meeha_get_most_read( (string) $r['window'], $count, $cat ? array( 'category' => $cat ) : array() );
					$items = array();
					foreach ( $posts as $p ) {
						$items[] = array(
							'id'     => $p->ID,
							'title'  => html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ),
							'url'    => get_permalink( $p ),
							'kicker' => meeha_post_kicker( $p ),
						);
					}
					$res = rest_ensure_response(
						array(
							'window' => (string) $r['window'],
							'html'   => meeha_rank_list_html( $posts ),
							'items'  => $items,
						)
					);
					$res->header( 'Cache-Control', 'public, max-age=300, s-maxage=300' );
					return $res;
				},
			)
		);
	}
);

/* --------------------------------------------------------------------------
 * Legacy admin-ajax actions (old cached HTML may still call them). Thin wrappers over the same code;
 * their nonces are NOT enforced (cached pages carry stale ones) — origin + rate limits apply instead.
 * ------------------------------------------------------------------------ */

/**
 * Guard + post lookup for the legacy write wrappers (sends the JSON error itself).
 *
 * @param string $bucket Bucket.
 * @return WP_Post
 */
function meeha_metrics_ajax_post( $bucket ) {
	$g = meeha_metrics_guard( $bucket, 30, 10 * MINUTE_IN_SECONDS );
	if ( is_wp_error( $g ) ) {
		wp_send_json_error( array( 'message' => $g->get_error_message() ), (int) $g->get_error_data()['status'] );
	}
	$p = meeha_metrics_countable_post( isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification
	if ( ! $p ) {
		wp_send_json_error();
	}
	return $p;
}

/**
 * Legacy `meeha_track_share` (post_id, share_type).
 */
function meeha_handle_track_share() {
	$p   = meeha_metrics_ajax_post( 'share' );
	$net = isset( $_POST['share_type'] ) ? sanitize_key( wp_unslash( $_POST['share_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	if ( ! in_array( $net, meeha_metrics_share_networks(), true ) ) {
		wp_send_json_error();
	}
	$r = meeha_metrics_record_share( $p->ID, $net );
	wp_send_json_success( array( 'counts' => $r['counts'], 'total' => $r['total'] ) );
}
add_action( 'wp_ajax_meeha_track_share', 'meeha_handle_track_share' );
add_action( 'wp_ajax_nopriv_meeha_track_share', 'meeha_handle_track_share' );

/**
 * Legacy `meeha_reaction` (post_id, reaction, previous).
 */
function meeha_handle_reaction() {
	$p    = meeha_metrics_ajax_post( 'react' );
	$type = isset( $_POST['reaction'] ) ? sanitize_key( wp_unslash( $_POST['reaction'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	$prev = isset( $_POST['previous'] ) ? sanitize_key( wp_unslash( $_POST['previous'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	if ( ! in_array( $type, meeha_metrics_reaction_types(), true ) ) {
		wp_send_json_error();
	}
	$r = meeha_metrics_record_reaction( $p->ID, $type, $prev );
	wp_send_json_success( array( 'counts' => $r['counts'] ) );
}
add_action( 'wp_ajax_meeha_reaction', 'meeha_handle_reaction' );
add_action( 'wp_ajax_nopriv_meeha_reaction', 'meeha_handle_reaction' );

/**
 * Legacy `meeha_mostread_filter` (range all|year|6months|3months|lastmonth|thismonth) → {html}.
 */
function meeha_handle_ajax_most_read_filter() {
	$range = isset( $_POST['range'] ) ? sanitize_key( wp_unslash( $_POST['range'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification
	$map   = array(
		'thismonth' => '30d',
		'lastmonth' => '30d',
		'3months'   => 'all',
		'6months'   => 'all',
		'year'      => 'all',
		'all'       => 'all',
	);
	$count = function_exists( 'meeha_opt' ) ? (int) meeha_opt( 'home', 'mostread_count', 5 ) : 5;
	$posts = meeha_get_most_read( isset( $map[ $range ] ) ? $map[ $range ] : 'all', max( 3, $count ) );
	wp_send_json_success( array( 'html' => meeha_rank_list_html( $posts, array( 'views' => true ) ) ) );
}
add_action( 'wp_ajax_meeha_mostread_filter', 'meeha_handle_ajax_most_read_filter' );
add_action( 'wp_ajax_nopriv_meeha_mostread_filter', 'meeha_handle_ajax_most_read_filter' );

/**
 * Legacy `meeha_tabbed_sidebar` (tab popular|trend|new) → {html}.
 */
function meeha_handle_tabbed_sidebar() {
	$tab = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : 'popular'; // phpcs:ignore WordPress.Security.NonceVerification
	if ( 'trend' === $tab ) {
		$posts = wp_list_pluck( meeha_get_trending( 8 ), 'post' );
	} elseif ( 'new' === $tab ) {
		$posts = get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'has_password' => false, 'posts_per_page' => 8, 'ignore_sticky_posts' => true ) );
	} else {
		$posts = meeha_get_most_read( '30d', 8 );
	}
	wp_send_json_success( array( 'html' => meeha_rank_list_html( $posts ) ) );
}
add_action( 'wp_ajax_meeha_tabbed_sidebar', 'meeha_handle_tabbed_sidebar' );
add_action( 'wp_ajax_nopriv_meeha_tabbed_sidebar', 'meeha_handle_tabbed_sidebar' );

/**
 * Legacy `meeha_category_load_more` (category, offset) → {html, has_more}. Children included.
 */
function meeha_handle_category_load_more() {
	$cat    = isset( $_POST['category'] ) ? absint( $_POST['category'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
	$offset = isset( $_POST['offset'] ) ? min( 2000, absint( $_POST['offset'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
	if ( ! $cat || ! term_exists( $cat, 'category' ) ) {
		wp_send_json_error();
	}
	$q    = new WP_Query(
		array(
			// Explicit status: inside admin-ajax.php is_admin() is true and WP_Query would add draft/pending/future.
			'post_type'           => 'post',
			'post_status'         => 'publish',
			'has_password'        => false,
			'cat'                 => $cat,
			'posts_per_page'      => 10,
			'offset'              => $offset,
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
		)
	);
	$html = '';
	foreach ( $q->posts as $p ) {
		$html .= meeha_get_card( $p, array( 'variant' => 'sm rowm', 'heading_level' => 2 ) );
	}
	wp_send_json_success( array( 'html' => $html, 'has_more' => 10 === count( $q->posts ) ) );
}
add_action( 'wp_ajax_meeha_category_load_more', 'meeha_handle_category_load_more' );
add_action( 'wp_ajax_nopriv_meeha_category_load_more', 'meeha_handle_category_load_more' );
