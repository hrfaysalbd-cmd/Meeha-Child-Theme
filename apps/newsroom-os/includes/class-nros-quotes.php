<?php
/**
 * NROS_Quotes — round 19: the theme's উক্তি (quotes) in the Publisher app.
 *
 * The quotes live in the Meeha News theme: post type `meeha_photocard`
 * (relabelled উক্তি), taxonomy `meeha_quote_type` (each type has a frame:
 * politics, faith, literature, inspire, history, sports, opinion), the quote
 * text in the post title, the speaker photo as the featured image and the
 * other fields in `_meeha_quote_*` meta (+ the legacy `_meeha_attribution`
 * "name, role" string the theme keeps in sync). Saving goes through the
 * theme's own meeha_quote_save_fields() when it exists, so the site shows
 * an app-made quote exactly like one made in wp-admin.
 *
 * Routes (staff = Editor/Publisher/Administrator):
 *   GET    /admin/quotes?search=&type=&status=&page=   list (30 a page)
 *   POST   /admin/quotes                               create
 *   GET    /admin/quotes/{id}                          one
 *   PATCH  /admin/quotes/{id}                          update
 *   DELETE /admin/quotes/{id}                          to trash (needs the delete_posts right, like articles)
 *   GET    /admin/quote-types                          types + frames
 *   POST   /admin/quote-types                          new type {name, frame}
 *
 * @package NewsroomOS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NROS_Quotes {

	const POST_TYPE = 'meeha_photocard';
	const TAXONOMY  = 'meeha_quote_type';
	const PER_PAGE  = 30;
	const MAX_TEXT  = 600;

	/**
	 * Registers routes.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Registers REST routes under nros/v1.
	 */
	public static function register_routes() {
		register_rest_route(
			NROS_API_NS,
			'/admin/quotes',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'list_quotes' ),
					'permission_callback' => array( 'NROS_Core', 'require_staff' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'create' ),
					'permission_callback' => array( 'NROS_Core', 'require_staff' ),
				),
			)
		);
		register_rest_route(
			NROS_API_NS,
			'/admin/quotes/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_one' ),
					'permission_callback' => array( 'NROS_Core', 'require_staff' ),
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( __CLASS__, 'update' ),
					'permission_callback' => array( 'NROS_Core', 'require_staff' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( __CLASS__, 'delete' ),
					'permission_callback' => array( 'NROS_Core', 'require_staff' ),
				),
			)
		);
		register_rest_route(
			NROS_API_NS,
			'/admin/quote-types',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'list_types' ),
					'permission_callback' => array( 'NROS_Core', 'require_staff' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'create_type' ),
					'permission_callback' => array( 'NROS_Core', 'require_staff' ),
				),
			)
		);
	}

	/**
	 * Error when the theme's quotes module is not active.
	 *
	 * @return WP_Error|null
	 */
	protected static function unavailable() {
		if ( post_type_exists( self::POST_TYPE ) && taxonomy_exists( self::TAXONOMY ) ) {
			return null;
		}
		return new WP_Error( 'nros_quotes_unavailable', 'সাইটে Meeha News থিমের উক্তি অংশ চালু নেই।', array( 'status' => 501 ) );
	}

	/**
	 * The seven frames: id => [label, swatch] (from the theme when present).
	 *
	 * @return array
	 */
	protected static function frames() {
		$out = array();
		if ( function_exists( 'meeha_quote_frames' ) ) {
			foreach ( meeha_quote_frames() as $id => $f ) {
				$out[ $id ] = array( (string) $f[0], (string) $f[2] );
			}
			return $out;
		}
		return array(
			'politics'   => array( 'রাজনীতি', '#0D2747' ),
			'faith'      => array( 'ধর্ম ও আধ্যাত্মিক', '#0E3A35' ),
			'literature' => array( 'সাহিত্য', '#E9DFC8' ),
			'inspire'    => array( 'অনুপ্রেরণা', '#F4A73B' ),
			'history'    => array( 'ইতিহাস', '#C9B48A' ),
			'sports'     => array( 'খেলা', '#11553A' ),
			'opinion'    => array( 'মতামত', '#025AB2' ),
		);
	}

	/**
	 * Frame of a type term.
	 *
	 * @param WP_Term|null $term Term.
	 * @return string
	 */
	protected static function frame_of( $term ) {
		if ( function_exists( 'meeha_quote_frame_of_term' ) ) {
			return (string) meeha_quote_frame_of_term( $term );
		}
		$f = $term ? (string) get_term_meta( $term->term_id, 'frame', true ) : '';
		return array_key_exists( $f, self::frames() ) ? $f : 'opinion';
	}

	/**
	 * Speaker + role (theme helper, else the legacy string).
	 *
	 * @param WP_Post $post Post.
	 * @return array
	 */
	protected static function speaker( $post ) {
		if ( function_exists( 'meeha_quote_speaker' ) ) {
			return meeha_quote_speaker( $post );
		}
		$s = trim( (string) get_post_meta( $post->ID, '_meeha_quote_speaker', true ) );
		$r = trim( (string) get_post_meta( $post->ID, '_meeha_quote_role', true ) );
		return array( $s, $r );
	}

	/**
	 * API shape of a quote.
	 *
	 * @param WP_Post $post Post.
	 * @return array
	 */
	protected static function shape( $post ) {
		$terms = get_the_terms( $post, self::TAXONOMY );
		$term  = ( $terms && ! is_wp_error( $terms ) ) ? $terms[0] : null;
		$frame = self::frame_of( $term );
		$m     = function ( $k ) use ( $post ) {
			return (string) get_post_meta( $post->ID, $k, true );
		};
		list( $speaker, $role ) = self::speaker( $post );
		$photo = (int) get_post_thumbnail_id( $post );
		$poll  = array(
			'enabled'  => '1' === $m( '_meeha_poll_enabled' ),
			'option_a' => $m( '_meeha_poll_option_a' ),
			'option_b' => $m( '_meeha_poll_option_b' ),
			'days'     => (int) $m( '_meeha_poll_duration_days' ),
			'total'    => null,
		);
		if ( $poll['enabled'] && function_exists( 'meeha_poll_data' ) ) {
			$d             = meeha_poll_data( $post->ID );
			$poll['total'] = isset( $d['total'] ) ? (int) $d['total'] : null;
		}
		$author = get_userdata( (int) $post->post_author );
		return array(
			'id'          => (int) $post->ID,
			'text'        => html_entity_decode( (string) $post->post_title, ENT_QUOTES, 'UTF-8' ),
			'speaker'     => (string) $speaker,
			'role'        => (string) $role,
			'source'      => $m( '_meeha_quote_source' ),
			'source_year' => $m( '_meeha_quote_source_year' ),
			'year'        => $m( '_meeha_quote_year' ),
			'context'     => $m( '_meeha_quote_context' ),
			'type_id'     => $term ? (int) $term->term_id : 0,
			'type_name'   => $term ? (string) $term->name : '',
			'frame'       => $frame,
			'photo_id'    => $photo,
			'photo_url'   => $photo ? (string) wp_get_attachment_image_url( $photo, 'thumbnail' ) : '',
			'status'      => (string) $post->post_status,
			'date'        => mysql_to_rfc3339( $post->post_date ),
			'modified'    => mysql_to_rfc3339( $post->post_modified ),
			'author'      => $author ? (string) $author->display_name : '',
			'url'         => 'publish' === $post->post_status ? (string) get_permalink( $post ) : '',
			'poll'        => $poll,
		);
	}

	/**
	 * GET /admin/quotes.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function list_quotes( $request ) {
		$err = self::unavailable();
		if ( $err ) {
			return $err;
		}
		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		$args   = array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => in_array( $status, array( 'publish', 'draft' ), true ) ? $status : array( 'publish', 'draft', 'pending', 'future' ),
			'posts_per_page' => self::PER_PAGE,
			'paged'          => max( 1, (int) $request->get_param( 'page' ) ),
			'orderby'        => 'date',
			'order'          => 'DESC',
		);
		$search = trim( sanitize_text_field( (string) $request->get_param( 'search' ) ) );
		if ( '' !== $search ) {
			/* the text is the title; also match the speaker (meta) */
			$ids = get_posts(
				array(
					'post_type'      => self::POST_TYPE,
					'post_status'    => $args['post_status'],
					'fields'         => 'ids',
					'posts_per_page' => 200,
					'no_found_rows'  => true,
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						'relation' => 'OR',
						array(
							'key'     => '_meeha_quote_speaker',
							'value'   => $search,
							'compare' => 'LIKE',
						),
						array(
							'key'     => '_meeha_attribution',
							'value'   => $search,
							'compare' => 'LIKE',
						),
					),
				)
			);
			$by_title = get_posts(
				array(
					'post_type'      => self::POST_TYPE,
					'post_status'    => $args['post_status'],
					'fields'         => 'ids',
					'posts_per_page' => 200,
					'no_found_rows'  => true,
					's'              => $search,
				)
			);
			$args['post__in'] = array_values( array_unique( array_merge( $ids, $by_title ) ) );
			if ( ! $args['post__in'] ) {
				$args['post__in'] = array( 0 );
			}
		}
		$type = (int) $request->get_param( 'type' );
		if ( $type > 0 ) {
			$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => self::TAXONOMY,
					'field'    => 'term_id',
					'terms'    => $type,
				),
			);
		}
		$q = new WP_Query( $args );
		return new WP_REST_Response(
			array(
				'items' => array_map( array( __CLASS__, 'shape' ), $q->posts ),
				'total' => (int) $q->found_posts,
				'pages' => (int) $q->max_num_pages,
			),
			200
		);
	}

	/**
	 * A quote by id or an error.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_Post|WP_Error
	 */
	protected static function quote_or_error( $request ) {
		$err = self::unavailable();
		if ( $err ) {
			return $err;
		}
		$post = get_post( (int) $request->get_param( 'id' ) );
		if ( ! $post || self::POST_TYPE !== $post->post_type || 'trash' === $post->post_status ) {
			return new WP_Error( 'nros_not_found', 'উক্তিটি পাওয়া যায়নি।', array( 'status' => 404 ) );
		}
		return $post;
	}

	/**
	 * GET /admin/quotes/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_one( $request ) {
		$post = self::quote_or_error( $request );
		return is_wp_error( $post ) ? $post : new WP_REST_Response( self::shape( $post ), 200 );
	}

	/**
	 * Validated fields from a request; for an update, missing params keep the stored values.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param WP_Post|null    $post    Existing quote.
	 * @return array|WP_Error
	 */
	protected static function read_fields( $request, $post ) {
		$cur = $post ? self::shape( $post ) : null;
		$get = function ( $k, $default = '' ) use ( $request, $cur ) {
			$v = $request->get_param( $k );
			if ( null === $v ) {
				return $cur && array_key_exists( $k, $cur ) ? $cur[ $k ] : $default;
			}
			return $v;
		};
		$text = trim( preg_replace( '/\s+/u', ' ', sanitize_textarea_field( (string) $get( 'text' ) ) ) );
		if ( '' === $text ) {
			return new WP_Error( 'nros_bad_request', 'উক্তিটি লিখুন।', array( 'status' => 400 ) );
		}
		if ( mb_strlen( $text ) > self::MAX_TEXT ) {
			return new WP_Error( 'nros_bad_request', 'উক্তি সর্বোচ্চ ' . NROS_Core::bn_digits( self::MAX_TEXT ) . ' অক্ষরের হতে পারে।', array( 'status' => 400 ) );
		}
		$status = sanitize_key( (string) $get( 'status', 'publish' ) );
		$type   = (int) $get( 'type_id', 0 );
		if ( $type > 0 ) {
			$t = get_term( $type, self::TAXONOMY );
			if ( ! $t || is_wp_error( $t ) ) {
				return new WP_Error( 'nros_bad_request', 'উক্তির ধরনটি পাওয়া যায়নি।', array( 'status' => 400 ) );
			}
		}
		$photo = (int) $get( 'photo_id', 0 );
		if ( $photo > 0 && ! wp_attachment_is_image( $photo ) ) {
			return new WP_Error( 'nros_bad_request', 'বক্তার ছবিটি সঠিক নয়।', array( 'status' => 400 ) );
		}
		$poll_in = $request->get_param( 'poll' );
		$poll    = is_array( $poll_in ) ? $poll_in : ( $cur ? $cur['poll'] : array() );
		$s       = function ( $v ) {
			return sanitize_text_field( (string) $v );
		};
		return array(
			'text'   => $text,
			'status' => in_array( $status, array( 'publish', 'draft' ), true ) ? $status : 'publish',
			'type'   => max( 0, $type ),
			'photo'  => max( 0, $photo ),
			'meta'   => array(
				'speaker'     => $s( $get( 'speaker' ) ),
				'role'        => $s( $get( 'role' ) ),
				'source'      => $s( $get( 'source' ) ),
				'source_year' => $s( $get( 'source_year' ) ),
				'year'        => $s( $get( 'year' ) ),
				'context'     => sanitize_textarea_field( (string) $get( 'context' ) ),
				'poll'        => ! empty( $poll['enabled'] ) ? 1 : 0,
				'option_a'    => $s( isset( $poll['option_a'] ) ? $poll['option_a'] : '' ),
				'option_b'    => $s( isset( $poll['option_b'] ) ? $poll['option_b'] : '' ),
				'days'        => isset( $poll['days'] ) ? absint( $poll['days'] ) : 0,
			),
		);
	}

	/**
	 * Write meta, type and photo.
	 *
	 * @param int   $post_id Post.
	 * @param array $f       read_fields() result.
	 */
	protected static function apply( $post_id, $f ) {
		if ( function_exists( 'meeha_quote_save_fields' ) ) {
			meeha_quote_save_fields( $post_id, $f['meta'] );
		} else {
			$m = $f['meta'];
			foreach ( array( 'speaker', 'role', 'source', 'source_year', 'year', 'context' ) as $k ) {
				update_post_meta( $post_id, '_meeha_quote_' . $k, $m[ $k ] );
			}
			update_post_meta( $post_id, '_meeha_attribution', trim( $m['speaker'] . ( '' !== $m['role'] ? ', ' . $m['role'] : '' ), ', ' ) );
			update_post_meta( $post_id, '_meeha_poll_enabled', $m['poll'] );
			update_post_meta( $post_id, '_meeha_poll_option_a', $m['option_a'] );
			update_post_meta( $post_id, '_meeha_poll_option_b', $m['option_b'] );
			if ( $m['days'] ) {
				update_post_meta( $post_id, '_meeha_poll_duration_days', min( 365, $m['days'] ) );
			} else {
				delete_post_meta( $post_id, '_meeha_poll_duration_days' );
			}
		}
		wp_set_object_terms( $post_id, $f['type'] ? array( $f['type'] ) : array(), self::TAXONOMY );
		if ( $f['photo'] ) {
			set_post_thumbnail( $post_id, $f['photo'] );
		} else {
			delete_post_thumbnail( $post_id );
		}
	}

	/**
	 * POST /admin/quotes.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create( $request ) {
		$err = self::unavailable();
		if ( $err ) {
			return $err;
		}
		$user = NROS_Auth::get_user_from_request( $request );
		$f    = self::read_fields( $request, null );
		if ( is_wp_error( $f ) ) {
			return $f;
		}
		$id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_title'  => $f['text'],
				'post_status' => $f['status'],
				'post_author' => $user->ID,
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		self::apply( $id, $f );
		return new WP_REST_Response( self::shape( get_post( $id ) ), 201 );
	}

	/**
	 * PATCH /admin/quotes/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update( $request ) {
		$post = self::quote_or_error( $request );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$f = self::read_fields( $request, $post );
		if ( is_wp_error( $f ) ) {
			return $f;
		}
		$res = wp_update_post(
			array(
				'ID'          => $post->ID,
				'post_title'  => $f['text'],
				'post_status' => $f['status'],
			),
			true
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		self::apply( $post->ID, $f );
		return new WP_REST_Response( self::shape( get_post( $post->ID ) ), 200 );
	}

	/**
	 * DELETE /admin/quotes/{id} — to the trash.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete( $request ) {
		$user = NROS_Auth::get_user_from_request( $request );
		if ( ! NROS_Core::staff_can( $user, 'delete_posts' ) ) {
			return new WP_Error( 'nros_forbidden', 'মুছে ফেলার অনুমতি আপনার নেই — পাবলিশারের অনুমতি প্রয়োজন।', array( 'status' => 403 ) );
		}
		$post = self::quote_or_error( $request );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( ! wp_trash_post( $post->ID ) ) {
			return new WP_Error( 'nros_failed', 'মুছে ফেলা যায়নি।', array( 'status' => 500 ) );
		}
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * GET /admin/quote-types.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function list_types() {
		$err = self::unavailable();
		if ( $err ) {
			return $err;
		}
		if ( function_exists( 'meeha_quote_seed_types' ) ) {
			meeha_quote_seed_types();
		}
		$terms = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);
		$types = array();
		foreach ( is_wp_error( $terms ) ? array() : $terms as $t ) {
			$types[] = array(
				'id'    => (int) $t->term_id,
				'name'  => (string) $t->name,
				'frame' => self::frame_of( $t ),
				'count' => (int) $t->count,
			);
		}
		$frames = array();
		foreach ( self::frames() as $id => $f ) {
			$frames[] = array(
				'id'     => $id,
				'label'  => $f[0],
				'swatch' => $f[1],
			);
		}
		return new WP_REST_Response(
			array(
				'types'  => $types,
				'frames' => $frames,
			),
			200
		);
	}

	/**
	 * POST /admin/quote-types {name, frame}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_type( $request ) {
		$err = self::unavailable();
		if ( $err ) {
			return $err;
		}
		$name  = trim( sanitize_text_field( (string) $request->get_param( 'name' ) ) );
		$frame = sanitize_key( (string) $request->get_param( 'frame' ) );
		if ( '' === $name ) {
			return new WP_Error( 'nros_bad_request', 'ধরনের নাম লিখুন।', array( 'status' => 400 ) );
		}
		if ( ! array_key_exists( $frame, self::frames() ) ) {
			$frame = 'opinion';
		}
		$exists = term_exists( $name, self::TAXONOMY );
		if ( $exists ) {
			return new WP_Error( 'nros_conflict', 'এই নামে ধরন আগেই আছে।', array( 'status' => 409 ) );
		}
		$res = wp_insert_term( $name, self::TAXONOMY );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		update_term_meta( (int) $res['term_id'], 'frame', $frame );
		return new WP_REST_Response(
			array(
				'id'    => (int) $res['term_id'],
				'name'  => $name,
				'frame' => $frame,
				'count' => 0,
			),
			201
		);
	}
}
