<?php
/**
 * দেশচিত্র REST (namespace meeha/v1):
 *
 *   GET    /deshchitro?range=day|7|30|month&date=Y-m-d   public payload (published days that are over)
 *   GET    /deshchitro/sheet?date=Y-m-d                   input sheet for one day      (edit_others_posts)
 *   POST   /deshchitro/sheet {date, status, data}         save                          (edit_others_posts)
 *   DELETE /deshchitro/sheet?date=Y-m-d                   delete                        (edit_others_posts)
 *
 * The newsroom-os plugin exposes the same sheet functions to the publisher app (JWT) as nros/v1/admin/deshchitro.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

/**
 * Keywords used to tag a day's news posts for the input screen's helper list.
 *
 * @return array indicator key => regex alternatives (Bangla)
 */
function meeha_dc_keywords() {
	return apply_filters(
		'meeha_dc_keywords',
		array(
			'khun'         => 'খুন|হত্যা|কুপিয়ে|গলা কেটে|লাশ উদ্ধার|গুলিতে নিহত|পিটিয়ে',
			'sorok_nihoto' => 'সড়ক দুর্ঘটনা|বাসচাপা|ট্রাকচাপা|মোটরসাইকেল দুর্ঘটনা|সংঘর্ষে নিহত|ট্রেনে কাটা',
			'sorok_ahoto'  => 'দুর্ঘটনায় আহত|সংঘর্ষে আহত',
			'dhorshon'     => 'ধর্ষণ|নির্যাতন|যৌন হয়রানি|শ্লীলতাহানি',
			'chhintai'     => 'চুরি|ছিনতাই|ডাকাতি|চোর',
			'attohotya'    => 'আত্মহত্যা|ঝুলন্ত মরদেহ|ঝুলন্ত লাশ',
			'dube'         => 'পানিতে ডুবে|ডুবে মৃত্যু|নৌকাডুবি|ট্রলারডুবি',
			'agun'         => 'আগুন|অগ্নিকাণ্ড|অগ্নিদগ্ধ',
			'greftar'      => 'গ্রেপ্তার|গ্রেফতার|আটক',
			'safolyo'      => 'উদ্ধার|জব্দ|সাফল্য|অর্জন|পুরস্কার',
		)
	);
}

/**
 * The day's published news (helper list on the input screen), tagged with indicator guesses.
 *
 * @param string $day Y-m-d.
 * @return array[]
 */
function meeha_dc_day_news( $day ) {
	$by_name = array();
	foreach ( meeha_dc_districts() as $k => $v ) {
		$by_name[ $v[0] ] = $k;
	}
	$parts = array_map( 'intval', explode( '-', $day ) );
	$q     = new WP_Query(
		array(
			'post_type'              => 'post',
			'post_status'            => 'publish',
			'posts_per_page'         => 200,
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_term_cache' => false,
			'date_query'             => array(
				array(
					'year'  => $parts[0],
					'month' => $parts[1],
					'day'   => $parts[2],
				),
			),
			'fields'                 => 'ids',
		)
	);
	$kw  = meeha_dc_keywords();
	$out = array();
	foreach ( $q->posts as $id ) {
		$title = html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' );
		$tags  = array();
		foreach ( $kw as $k => $re ) {
			if ( @preg_match( '/' . $re . '/u', $title ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				$tags[] = $k;
			}
		}
		$dn    = (string) get_post_meta( $id, '_meeha_district', true );
		$out[] = array(
			'id'    => (int) $id,
			'title' => $title,
			'url'   => get_permalink( $id ),
			'd'     => isset( $by_name[ $dn ] ) ? $by_name[ $dn ] : '',
			'tags'  => $tags,
			'time'  => get_the_time( 'H:i', $id ),
			'c'     => (object) meeha_dc_post_counts( $id ),
		);
	}
	// Tagged first.
	usort(
		$out,
		function ( $x, $y ) {
			return ( $y['tags'] ? 1 : 0 ) - ( $x['tags'] ? 1 : 0 );
		}
	);
	return $out;
}

/**
 * Everything the input screen needs for one day.
 *
 * @param string $day Y-m-d (default yesterday).
 * @return array
 */
function meeha_dc_sheet( $day ) {
	meeha_dc_maybe_install();
	if ( ! meeha_dc_is_date( $day ) ) {
		$day = meeha_dc_shift( meeha_dc_today(), -1 );
	}
	$row = meeha_dc_get_day( $day );
	// Economy values of the most recent earlier sheet (hint + "copy previous").
	$prev_eco = array();
	$prev_day = '';
	foreach ( array_reverse( meeha_dc_days( meeha_dc_shift( $day, -40 ), meeha_dc_shift( $day, -1 ), false ), true ) as $d => $r ) {
		foreach ( $r['data']['eco'] as $k => $v ) {
			if ( ! isset( $prev_eco[ $k ] ) ) {
				$prev_eco[ $k ] = $v;
			}
		}
		if ( ! $prev_day ) {
			$prev_day = $d;
		}
	}
	$user = $row && $row['updated_by'] ? get_userdata( $row['updated_by'] ) : null;
	$c    = meeha_dc_config();
	return array(
		'day'        => $day,
		'today'      => meeha_dc_today(),
		'label'      => meeha_dc_bn_date( $day ),
		'exists'     => (bool) $row,
		'status'     => $row ? $row['status'] : 'draft',
		'data'       => $row ? $row['data'] : meeha_dc_empty_data(),
		'updatedAt'  => $row ? $row['updated_at'] : '',
		'updatedBy'  => $user ? $user->display_name : '',
		'prevEco'    => $prev_eco,
		'prevDay'    => $prev_day,
		'month'      => meeha_dc_month_status( substr( $day, 0, 7 ) ),
		'indicators' => $c['indicators'],
		'economy'    => $c['economy'],
		'ecoGroups'  => meeha_dc_economy_groups(),
		'geo'        => meeha_dc_geo(),
		'news'       => meeha_dc_day_news( $day ),
		// 2.4.9: the day's own Meeha posts with counts (prefill + "already recorded" list for the AI prompt).
		'meeha'      => meeha_dc_posts_day( $day ),
		'pageUrl'    => meeha_dc_page_url(),
	);
}

/**
 * URL of the public page ('' when not set up).
 *
 * @return string
 */
function meeha_dc_page_url() {
	$id = (int) meeha_dc_config()['page_id'];
	if ( $id && 'page' === get_post_type( $id ) && 'publish' === get_post_status( $id ) ) {
		return get_permalink( $id );
	}
	return '';
}

/** Routes. */
function meeha_dc_rest_routes() {
	register_rest_route(
		'meeha/v1',
		'/deshchitro',
		array(
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => function ( WP_REST_Request $r ) {
				$res = rest_ensure_response( meeha_dc_payload( array( 'range' => $r->get_param( 'range' ), 'date' => $r->get_param( 'date' ) ) ) );
				$res->header( 'Cache-Control', 'public, max-age=300' );
				return $res;
			},
		)
	);
	$can = function () {
		return meeha_dc_can_edit() ? true : new WP_Error( 'rest_forbidden', 'এই কাজের অনুমতি নেই।', array( 'status' => rest_authorization_required_code() ) );
	};
	register_rest_route(
		'meeha/v1',
		'/deshchitro/sheet',
		array(
			array(
				'methods'             => 'GET',
				'permission_callback' => $can,
				'callback'            => function ( WP_REST_Request $r ) {
					return meeha_dc_sheet( (string) $r->get_param( 'date' ) );
				},
			),
			array(
				'methods'             => 'POST',
				'permission_callback' => $can,
				'callback'            => function ( WP_REST_Request $r ) {
					$day = (string) $r->get_param( 'date' );
					$res = meeha_dc_save_day( $day, (array) $r->get_param( 'data' ), (string) $r->get_param( 'status' ), get_current_user_id() );
					return is_wp_error( $res ) ? $res : meeha_dc_sheet( $day );
				},
			),
			array(
				'methods'             => 'DELETE',
				'permission_callback' => $can,
				'callback'            => function ( WP_REST_Request $r ) {
					$day = (string) $r->get_param( 'date' );
					meeha_dc_delete_day( $day );
					return meeha_dc_sheet( $day );
				},
			),
		)
	);
}
add_action( 'rest_api_init', 'meeha_dc_rest_routes' );
