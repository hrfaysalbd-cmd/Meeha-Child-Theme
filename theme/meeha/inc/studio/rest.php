<?php
/**
 * Studio REST routes (all require `meeha_make_cards`; cookie auth + wp_rest nonce):
 *   GET    meeha/v1/card-source?post=ID|URL     → title, author, designation, photo, category, image, …
 *   GET    meeha/v1/card-recent?offset=&search= → latest published posts for the "সাম্প্রতিক পোস্ট" picker (2.0.4)
 *   GET    meeha/v1/card-quotes?search=&page=   → উক্তি picker
 *   GET    meeha/v1/card-frames?type=&size=     → custom frames
 *   POST   meeha/v1/card-frames  (multipart: file, type, size, label)
 *   DELETE meeha/v1/card-frames/{id}
 * Plus attachment meta `_meeha_card_type` exposed to wp/v2/media (studio "মিডিয়া লাইব্রেরিতে রাখুন").
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

/**
 * Permission callback: card makers only.
 *
 * @return bool|WP_Error
 */
function meeha_studio_rest_can() {
	if ( current_user_can( 'meeha_make_cards' ) ) {
		return true;
	}
	return new WP_Error( 'rest_forbidden', 'ফটোকার্ড তৈরির অনুমতি নেই।', array( 'status' => is_user_logged_in() ? 403 : 401 ) );
}

add_action(
	'init',
	function () {
		register_post_meta(
			'attachment',
			'_meeha_card_type',
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_key',
				'auth_callback'     => function () {
					return current_user_can( 'meeha_make_cards' );
				},
			)
		);
	}
);

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'meeha/v1',
			'/card-source',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'meeha_studio_rest_card_source',
				'permission_callback' => 'meeha_studio_rest_can',
				'args'                => array(
					'post' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => function ( $v ) {
							return trim( (string) $v );
						},
					),
				),
			)
		);
		register_rest_route(
			'meeha/v1',
			'/card-recent',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'meeha_studio_rest_recent',
				'permission_callback' => 'meeha_studio_rest_can',
				'args'                => array(
					'offset' => array( 'type' => 'integer', 'default' => 0, 'minimum' => 0, 'maximum' => 500 ),
					'search' => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
				),
			)
		);
		register_rest_route(
			'meeha/v1',
			'/card-quotes',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'meeha_studio_rest_quotes',
				'permission_callback' => 'meeha_studio_rest_can',
				'args'                => array(
					'search' => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
					'page'   => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1, 'maximum' => 100 ),
				),
			)
		);
		register_rest_route(
			'meeha/v1',
			'/card-frames',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => 'meeha_studio_rest_frames_list',
					'permission_callback' => 'meeha_studio_rest_can',
					'args'                => array(
						'type' => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_key' ),
						'size' => array( 'type' => 'string', 'default' => '' ),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => 'meeha_studio_rest_frames_upload',
					'permission_callback' => function () {
						if ( ! current_user_can( 'meeha_make_cards' ) ) {
							return meeha_studio_rest_can();
						}
						return meeha_studio_can_upload_frames() ? true : new WP_Error( 'rest_forbidden', 'ফ্রেম আপলোডের অনুমতি নেই।', array( 'status' => 403 ) );
					},
				),
			)
		);
		register_rest_route(
			'meeha/v1',
			'/card-frames/(?P<id>f[a-z0-9]{6,20})',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => 'meeha_studio_rest_frames_delete',
				'permission_callback' => 'meeha_studio_rest_can',
			)
		);
	}
);

/**
 * Resolve "ID" or a site permalink to a post id.
 *
 * @param string $in Input.
 * @return int
 */
function meeha_studio_resolve_post( $in ) {
	$in = trim( (string) $in );
	if ( '' === $in ) {
		return 0;
	}
	if ( ctype_digit( $in ) ) {
		return (int) $in;
	}
	$url = esc_url_raw( $in );
	if ( ! $url ) {
		return 0;
	}
	$host = wp_parse_url( $url, PHP_URL_HOST );
	if ( $host && strtolower( $host ) !== strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) {
		return 0; // only this site's links.
	}
	$id = url_to_postid( $url );
	if ( ! $id ) {
		$q = array();
		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
		foreach ( array( 'p', 'page_id', 'post' ) as $k ) {
			if ( ! empty( $q[ $k ] ) && ctype_digit( (string) $q[ $k ] ) ) {
				return (int) $q[ $k ];
			}
		}
		// /photocard/{slug}/ or /quote/{slug}/ (CPT slug changed between themes).
		if ( preg_match( '#/(?:photocard|quote)/([^/?#]+)/?#u', rawurldecode( (string) wp_parse_url( $url, PHP_URL_PATH ) ), $m ) ) {
			$p = get_page_by_path( sanitize_title( $m[1] ), OBJECT, 'meeha_photocard' );
			$id = $p ? $p->ID : 0;
		}
	}
	return (int) $id;
}

/**
 * Author photo URL: custom `meeha_avatar` user meta, else the avatar URL.
 *
 * @param int $user_id User.
 * @return string
 */
function meeha_studio_author_photo( $user_id ) {
	$u = (string) get_user_meta( $user_id, 'meeha_avatar', true );
	if ( $u && wp_http_validate_url( $u ) ) {
		return esc_url_raw( $u );
	}
	$a = get_avatar_url( $user_id, array( 'size' => 336, 'default' => '404' ) );
	return $a ? esc_url_raw( $a ) : '';
}

/**
 * Map a quote type term to one of the 7 frames (term meta `frame`, else a keyword guess, else 'opinion').
 *
 * @param WP_Term|null $term Term.
 * @return string
 */
function meeha_studio_quote_frame_of( $term ) {
	$frames = meeha_studio_quote_frames();
	if ( ! ( $term instanceof WP_Term ) ) {
		return 'opinion';
	}
	$f = (string) get_term_meta( $term->term_id, 'frame', true );
	if ( isset( $frames[ $f ] ) ) {
		return $f;
	}
	$hay = $term->slug . ' ' . $term->name;
	$map = array(
		'politics'   => array( 'politic', 'রাজনী', 'রাজনৈ' ),
		'faith'      => array( 'faith', 'relig', 'ধর্ম', 'আধ্যাত্ম' ),
		'literature' => array( 'literat', 'সাহিত্য', 'কবিতা' ),
		'inspire'    => array( 'inspir', 'motiv', 'অনুপ্রেরণা', 'মোটিভেশ', 'প্রেরণা' ),
		'history'    => array( 'histor', 'ইতিহাস' ),
		'sports'     => array( 'sport', 'খেলা', 'ক্রীড়া' ),
		'opinion'    => array( 'opinion', 'মতামত' ),
	);
	foreach ( $map as $frame => $needles ) {
		foreach ( $needles as $n ) {
			if ( false !== mb_stripos( $hay, $n ) ) {
				return $frame;
			}
		}
	}
	return 'opinion';
}

/**
 * Quote (উক্তি) fields for the card. D's new meta first, then legacy `_meeha_attribution` "name, role".
 *
 * @param WP_Post $p Quote post.
 * @return array
 */
function meeha_studio_quote_data( $p ) {
	$speaker = (string) get_post_meta( $p->ID, '_meeha_quote_speaker', true );
	$role    = (string) get_post_meta( $p->ID, '_meeha_quote_role', true );
	if ( '' === $speaker ) {
		$attr  = (string) get_post_meta( $p->ID, '_meeha_attribution', true );
		$parts = preg_split( '/\s*[,،]\s*/u', $attr, 2 );
		$speaker = isset( $parts[0] ) ? $parts[0] : '';
		if ( '' === $role && isset( $parts[1] ) ) {
			$role = $parts[1];
		}
	}
	$terms = get_the_terms( $p, 'meeha_quote_type' );
	$term  = ( $terms && ! is_wp_error( $terms ) ) ? $terms[0] : null;
	$photo = '';
	foreach ( array( '_meeha_quote_photo', '_meeha_speaker_photo' ) as $k ) {
		$v = get_post_meta( $p->ID, $k, true );
		if ( $v ) {
			$photo = is_numeric( $v ) ? (string) wp_get_attachment_image_url( (int) $v, 'medium' ) : esc_url_raw( (string) $v );
			break;
		}
	}
	if ( ! $photo && has_post_thumbnail( $p ) ) {
		$photo = (string) get_the_post_thumbnail_url( $p, 'medium' );
	}
	$year = '';
	foreach ( array( '_meeha_quote_year', '_meeha_year' ) as $k ) {
		$v = get_post_meta( $p->ID, $k, true );
		if ( $v ) {
			$year = (string) $v;
			break;
		}
	}
	return array(
		'id'         => $p->ID,
		'text'       => html_entity_decode( wp_strip_all_tags( get_the_title( $p ) ), ENT_QUOTES, 'UTF-8' ),
		'speaker'    => sanitize_text_field( $speaker ),
		'role'       => sanitize_text_field( $role ),
		'type_label' => $term ? html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ) : '',
		'frame'      => meeha_studio_quote_frame_of( $term ),
		'photo'      => $photo,
		'year'       => sanitize_text_field( $year ),
		'permalink'  => get_permalink( $p ),
	);
}

/**
 * GET card-source.
 *
 * @param WP_REST_Request $req Request.
 * @return WP_REST_Response|WP_Error
 */
function meeha_studio_rest_card_source( $req ) {
	$id = meeha_studio_resolve_post( $req['post'] );
	$p  = $id ? get_post( $id ) : null;
	if ( ! $p || 'attachment' === $p->post_type || 'revision' === $p->post_type || ! current_user_can( 'read_post', $p->ID ) ) {
		return new WP_Error( 'meeha_card_source_404', 'এই আইডি বা লিংকে কোনো লেখা পাওয়া যায়নি।', array( 'status' => 404 ) );
	}
	$pto = get_post_type_object( $p->post_type );
	if ( ! $pto || ( ! $pto->public && ! current_user_can( 'edit_post', $p->ID ) ) ) {
		return new WP_Error( 'meeha_card_source_404', 'এই আইডি বা লিংকে কোনো লেখা পাওয়া যায়নি।', array( 'status' => 404 ) );
	}
	$author = (int) $p->post_author;
	$cat    = null;
	if ( function_exists( 'meeha_primary_category' ) ) {
		$cat = meeha_primary_category( $p );
	}
	if ( ! $cat ) {
		foreach ( (array) get_the_category( $p->ID ) as $c ) {
			if ( 'uncategorized' !== $c->slug ) {
				$cat = $c;
				break;
			}
		}
	}
	$title_meta = function_exists( 'meeha_author_designation' ) ? meeha_author_designation( $author ) : (string) get_user_meta( $author, 'meeha_author_title', true );
	$guest      = function_exists( 'meeha_post_byline' ) ? meeha_post_byline( $p, 168 ) : null; // 2.0.3: guest author (অতিথি লেখক).
	$locked     = '' !== (string) $p->post_password && ! current_user_can( 'edit_post', $p->ID ); // protected text only for its editors.
	$excerpt    = $locked ? '' : ( has_excerpt( $p ) ? $p->post_excerpt : wp_trim_words( wp_strip_all_tags( strip_shortcodes( $p->post_content ) ), 30, '…' ) );
	$img_id     = (int) get_post_thumbnail_id( $p );
	$date_bn    = function_exists( 'meeha_bengali_date' ) ? meeha_bengali_date( $p ) : get_the_date( '', $p );
	$out        = array(
		'id'             => $p->ID,
		'post_type'      => $p->post_type,
		'title'          => html_entity_decode( wp_strip_all_tags( get_the_title( $p ) ), ENT_QUOTES, 'UTF-8' ),
		'author_name'    => html_entity_decode( $guest && $guest['is_guest'] ? $guest['name'] : get_the_author_meta( 'display_name', $author ), ENT_QUOTES, 'UTF-8' ),
		'author_title'   => sanitize_text_field( (string) ( $guest && $guest['is_guest'] ? $guest['title'] : $title_meta ) ),
		'author_photo'   => $guest && $guest['is_guest'] ? ( '' !== $guest['photo'] ? esc_url_raw( $guest['photo'] ) : '' ) : meeha_studio_author_photo( $author ),
		'author_is_guest' => $guest && $guest['is_guest'],
		'category'       => $cat ? html_entity_decode( $cat->name, ENT_QUOTES, 'UTF-8' ) : '',
		'category_id'    => $cat ? (int) $cat->term_id : 0,
		'category_color' => $cat ? meeha_studio_category_color( $cat ) : '',
		'kicker'         => function_exists( 'meeha_post_kicker' ) ? html_entity_decode( meeha_post_kicker( $p ), ENT_QUOTES, 'UTF-8' ) : ( $cat ? $cat->name : '' ),
		'permalink'      => get_permalink( $p ),
		'image'          => $img_id ? (string) wp_get_attachment_image_url( $img_id, 'large' ) : '',
		'image_id'       => $img_id,
		'excerpt'        => html_entity_decode( wp_strip_all_tags( $excerpt ), ENT_QUOTES, 'UTF-8' ),
		'summary'        => $locked ? '' : sanitize_textarea_field( (string) get_post_meta( $p->ID, '_meeha_summary', true ) ),
		'date'           => wp_strip_all_tags( (string) $date_bn ),
		'date_short'     => meeha_cards_bn_date( $p ),
		'date_ymd'       => get_the_date( 'Y-m-d', $p ),
		'time'           => function_exists( 'meeha_bn_digits' ) ? meeha_bn_digits( get_the_date( 'H:i', $p ) ) : get_the_date( 'H:i', $p ),
		'is_breaking'    => '1' === (string) get_post_meta( $p->ID, '_meeha_breaking', true ),
	);
	if ( 'meeha_photocard' === $p->post_type ) {
		$out['quote'] = meeha_studio_quote_data( $p );
	}
	// 2.0.4: সাহিত্য / কবিতা cards — genre label, writer (poet field first), poem lines, a longer excerpt.
	if ( 'post' === $p->post_type && function_exists( 'meeha_lit_genre_of' ) ) {
		$gid = meeha_lit_genre_of( $p );
		if ( '' !== $gid ) {
			$g                  = function_exists( 'meeha_lit_genre' ) ? meeha_lit_genre( $gid ) : null;
			$out['lit_genre']   = $gid;
			$out['lit_label']   = $g && ! empty( $g['label'] ) ? (string) $g['label'] : '';
			$out['lit_writer']  = function_exists( 'meeha_lit_writer' ) ? html_entity_decode( (string) meeha_lit_writer( $p ), ENT_QUOTES, 'UTF-8' ) : $out['author_name'];
			$out['poem_lines']  = ! $locked && function_exists( 'meeha_lit_poem_lines' ) ? meeha_lit_poem_lines( $p, 16 ) : array();
			$out['lit_excerpt'] = $locked ? '' : html_entity_decode( wp_trim_words( wp_strip_all_tags( strip_shortcodes( $p->post_content ) ), 60, '…' ), ENT_QUOTES, 'UTF-8' );
		}
	}
	return rest_ensure_response( apply_filters( 'meeha_card_source', $out, $p ) );
}

/**
 * GET card-recent: five latest published posts (newest first), paged by offset, optional search.
 *
 * @param WP_REST_Request $req Request.
 * @return WP_REST_Response
 */
function meeha_studio_rest_recent( $req ) {
	$n = max( 3, min( 20, (int) meeha_studio_opt( 'recent_count', 5 ) ) );
	$q = new WP_Query(
		array(
			'post_type'           => 'post',
			'post_status'         => 'publish',
			'posts_per_page'      => $n + 1, // one extra tells the client whether "পরের" exists.
			'offset'              => (int) $req['offset'],
			's'                   => (string) $req['search'],
			'orderby'             => 'date',
			'order'               => 'DESC',
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
		)
	);
	$items = array();
	foreach ( array_slice( $q->posts, 0, $n ) as $p ) {
		$cat = function_exists( 'meeha_primary_category' ) ? meeha_primary_category( $p ) : null;
		$items[] = array(
			'id'       => $p->ID,
			'title'    => html_entity_decode( wp_strip_all_tags( get_the_title( $p ) ), ENT_QUOTES, 'UTF-8' ),
			'date'     => function_exists( 'meeha_bn_digits' ) ? meeha_bn_digits( get_the_date( 'j M, H:i', $p ) ) : get_the_date( 'j M, H:i', $p ),
			'thumb'    => (string) get_the_post_thumbnail_url( $p, 'thumbnail' ),
			'category' => $cat ? html_entity_decode( $cat->name, ENT_QUOTES, 'UTF-8' ) : '',
			'type'     => meeha_studio_type_for_post( $p ),
		);
	}
	return rest_ensure_response(
		array(
			'items'  => $items,
			'offset' => (int) $req['offset'],
			'per'    => $n,
			'more'   => count( $q->posts ) > $n,
		)
	);
}

/**
 * Category accent colour for the বিভাগ ফ্রেম (term meta `color`/`meeha_color`, else brand blue).
 *
 * @param WP_Term $cat Term.
 * @return string
 */
function meeha_studio_category_color( $cat ) {
	foreach ( array( 'color', 'meeha_color', '_meeha_color' ) as $k ) {
		$c = sanitize_hex_color( (string) get_term_meta( $cat->term_id, $k, true ) );
		if ( $c ) {
			return $c;
		}
	}
	return '#025AB2';
}

/**
 * GET card-quotes.
 *
 * @param WP_REST_Request $req Request.
 * @return WP_REST_Response
 */
function meeha_studio_rest_quotes( $req ) {
	$q = new WP_Query(
		array(
			'post_type'      => 'meeha_photocard',
			'post_status'    => current_user_can( 'edit_others_posts' ) ? array( 'publish', 'future', 'draft', 'pending', 'private' ) : 'publish',
			's'              => (string) $req['search'],
			'posts_per_page' => 20,
			'paged'          => (int) $req['page'],
			'no_found_rows'  => false,
		)
	);
	$items = array();
	foreach ( $q->posts as $p ) {
		if ( current_user_can( 'read_post', $p->ID ) ) {
			$items[] = meeha_studio_quote_data( $p );
		}
	}
	return rest_ensure_response(
		array(
			'items' => $items,
			'total' => (int) $q->found_posts,
			'pages' => (int) $q->max_num_pages,
		)
	);
}

/**
 * GET card-frames.
 *
 * @param WP_REST_Request $req Request.
 * @return WP_REST_Response
 */
function meeha_studio_rest_frames_list( $req ) {
	$rows = meeha_studio_frames_all();
	$out  = array();
	foreach ( $rows as $r ) {
		if ( ( '' === $req['type'] || $r['type'] === $req['type'] ) && ( '' === $req['size'] || $r['size'] === $req['size'] ) ) {
			$out[] = meeha_studio_frame_public( $r );
		}
	}
	return rest_ensure_response( $out );
}

/**
 * POST card-frames (multipart).
 *
 * @param WP_REST_Request $req Request.
 * @return WP_REST_Response|WP_Error
 */
function meeha_studio_rest_frames_upload( $req ) {
	$files = $req->get_file_params();
	if ( empty( $files['file'] ) ) {
		return new WP_Error( 'meeha_frame_nofile', 'কোনো ফাইল পাওয়া যায়নি।', array( 'status' => 400 ) );
	}
	$row = meeha_studio_store_frame( $files['file'], (string) $req['type'], (string) $req['size'], (string) $req['label'] );
	if ( is_wp_error( $row ) ) {
		return $row;
	}
	$pub            = meeha_studio_frame_public( $row );
	$pub['warning'] = $row['warning'];
	return new WP_REST_Response( $pub, 201 );
}

/**
 * DELETE card-frames/{id}.
 *
 * @param WP_REST_Request $req Request.
 * @return WP_REST_Response|WP_Error
 */
function meeha_studio_rest_frames_delete( $req ) {
	$r = meeha_studio_delete_frame( (string) $req['id'] );
	if ( is_wp_error( $r ) ) {
		return $r;
	}
	return rest_ensure_response( array( 'deleted' => true, 'id' => (string) $req['id'] ) );
}
