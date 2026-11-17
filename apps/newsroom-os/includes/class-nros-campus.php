<?php
/**
 * r47 — শিক্ষা/ক্যাম্পাস managed from the publisher app: a thin REST layer over the meeha-prep
 * plugin. The publisher sees the campus at a glance (lesson pool, আজকের পাঠ, CV designs, jobs,
 * tests), writes and publishes lessons, pins a lesson to a date, and installs the r47 content
 * pack with one call. Reads need staff; destructive writes need the publisher.
 *
 * @package NewsroomOS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Campus admin REST API.
 */
class NROS_Campus {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Routes.
	 */
	public static function register_routes() {
		register_rest_route(
			NROS_API_NS,
			'/admin/campus',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'overview' ),
				'permission_callback' => array( 'NROS_Core', 'require_staff' ),
			)
		);
		register_rest_route(
			NROS_API_NS,
			'/admin/campus/lessons',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'create_lesson' ),
				'permission_callback' => array( 'NROS_Core', 'require_staff' ),
			)
		);
		register_rest_route(
			NROS_API_NS,
			'/admin/campus/lessons/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'PATCH',
					'callback'            => array( __CLASS__, 'update_lesson' ),
					'permission_callback' => array( 'NROS_Core', 'require_staff' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( __CLASS__, 'delete_lesson' ),
					'permission_callback' => array( 'NROS_Core', 'require_publisher' ),
				),
			)
		);
		register_rest_route(
			NROS_API_NS,
			'/admin/campus/seed',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'seed' ),
				'permission_callback' => array( 'NROS_Core', 'require_staff' ),
			)
		);
	}

	/**
	 * Is meeha-prep active?
	 *
	 * @return bool
	 */
	private static function prep_ok() {
		return class_exists( 'MHP_Lessons' ) && post_type_exists( 'mh_lesson' );
	}

	/**
	 * The "plugin missing" error.
	 *
	 * @return WP_Error
	 */
	private static function no_prep() {
		return new WP_Error( 'nros_no_prep', 'মিহা প্রিপ (শিক্ষা) প্লাগইন সক্রিয় নেই।', array( 'status' => 501 ) );
	}

	/**
	 * One lesson row for the app.
	 *
	 * @param WP_Post $p Lesson.
	 * @return array
	 */
	private static function lesson_row( $p ) {
		$subj = wp_get_post_terms( $p->ID, 'mh_subject' );
		return array(
			'id'           => (int) $p->ID,
			'title'        => get_the_title( $p ),
			'excerpt'      => (string) $p->post_excerpt,
			'status'       => (string) $p->post_status,
			'subject'      => $subj && ! is_wp_error( $subj ) ? $subj[0]->slug : '',
			'subject_name' => $subj && ! is_wp_error( $subj ) ? $subj[0]->name : '',
			'minutes'      => (int) get_post_meta( $p->ID, '_mhp_minutes', true ),
			'order'        => (int) $p->menu_order,
			'feature_date' => (string) get_post_meta( $p->ID, '_mhp_feature_date', true ),
			'url'          => function_exists( 'meeha_campus_url' ) ? meeha_campus_url( 'lesson', $p->ID ) : '',
		);
	}

	/**
	 * GET /admin/campus.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function overview( $request ) {
		if ( ! self::prep_ok() ) {
			return self::no_prep();
		}
		$lessons = array();
		foreach ( get_posts(
			array(
				'post_type'      => 'mh_lesson',
				'post_status'    => array( 'publish', 'draft', 'pending' ),
				'posts_per_page' => 200,
				'orderby'        => 'menu_order',
				'order'          => 'ASC',
			)
		) as $p ) {
			$lessons[] = self::lesson_row( $p );
		}
		$subjects = array();
		foreach ( get_terms( array( 'taxonomy' => 'mh_subject', 'hide_empty' => false ) ) as $t ) {
			if ( ! is_wp_error( $t ) ) {
				$subjects[] = array( 'slug' => $t->slug, 'name' => $t->name );
			}
		}
		$cv = array();
		if ( post_type_exists( 'mh_cv_tpl' ) ) {
			foreach ( get_posts(
				array(
					'post_type'      => 'mh_cv_tpl',
					'post_status'    => array( 'publish', 'draft' ),
					'posts_per_page' => 50,
					'orderby'        => 'menu_order',
					'order'          => 'ASC',
				)
			) as $p ) {
				$cv[] = array(
					'id'     => (int) $p->ID,
					'name'   => get_the_title( $p ),
					'status' => (string) $p->post_status,
					'price'  => function_exists( 'mhp_meta' ) ? (float) mhp_meta( $p->ID, 'price', 0 ) : 0,
					'pages'  => function_exists( 'mhp_meta' ) ? max( 1, (int) mhp_meta( $p->ID, 'pages', 1 ) ) : 1,
				);
			}
		}
		$today = class_exists( 'MHP_Lessons' ) && method_exists( 'MHP_Lessons', 'today' ) ? MHP_Lessons::today( 0 ) : null;
		$count = function ( $type ) {
			if ( ! post_type_exists( $type ) ) {
				return 0;
			}
			$c = wp_count_posts( $type );
			return (int) ( isset( $c->publish ) ? $c->publish : 0 );
		};
		return array(
			'campus_url' => function_exists( 'meeha_campus_url' ) ? meeha_campus_url() : '',
			'today'      => $today && is_array( $today ) ? array(
				'id'    => (int) ( isset( $today['id'] ) ? $today['id'] : 0 ),
				'title' => (string) ( isset( $today['title'] ) ? $today['title'] : '' ),
			) : null,
			'stats'      => array(
				'lessons_publish' => count( array_filter( $lessons, function ( $l ) { return 'publish' === $l['status']; } ) ),
				'lessons_draft'   => count( array_filter( $lessons, function ( $l ) { return 'publish' !== $l['status']; } ) ),
				'cv_templates'    => count( $cv ),
				'jobs'            => $count( 'mh_job' ),
				'tests'           => $count( 'mh_test' ),
				'questions'       => $count( 'mh_question' ),
			),
			'lessons'    => $lessons,
			'subjects'   => $subjects,
			'cv'         => $cv,
		);
	}

	/**
	 * Shared lesson input → post array + meta apply.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param int             $id      Existing id or 0.
	 * @return int|WP_Error New/updated post id.
	 */
	private static function save_lesson( $request, $id = 0 ) {
		$post = array(
			'post_type' => 'mh_lesson',
		);
		if ( $id ) {
			$post['ID'] = $id;
		}
		if ( null !== $request->get_param( 'title' ) ) {
			$post['post_title'] = sanitize_text_field( $request->get_param( 'title' ) );
		}
		if ( null !== $request->get_param( 'excerpt' ) ) {
			$post['post_excerpt'] = sanitize_textarea_field( $request->get_param( 'excerpt' ) );
		}
		if ( null !== $request->get_param( 'content' ) ) {
			$post['post_content'] = wp_kses_post( (string) $request->get_param( 'content' ) );
		}
		if ( null !== $request->get_param( 'order' ) ) {
			$post['menu_order'] = (int) $request->get_param( 'order' );
		}
		if ( null !== $request->get_param( 'status' ) ) {
			$post['post_status'] = in_array( $request->get_param( 'status' ), array( 'publish', 'draft' ), true ) ? $request->get_param( 'status' ) : 'draft';
		} elseif ( ! $id ) {
			$post['post_status'] = 'publish'; // r47 standing instruction: campus content publishes directly.
		}
		$id = $id ? wp_update_post( $post, true ) : wp_insert_post( $post, true );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		if ( null !== $request->get_param( 'minutes' ) ) {
			update_post_meta( $id, '_mhp_minutes', max( 1, (int) $request->get_param( 'minutes' ) ) );
		}
		if ( null !== $request->get_param( 'subject' ) ) {
			$t = term_exists( sanitize_key( $request->get_param( 'subject' ) ), 'mh_subject' );
			wp_set_object_terms( $id, $t ? array( (int) $t['term_id'] ) : array(), 'mh_subject' );
		}
		if ( null !== $request->get_param( 'feature_date' ) ) {
			$d = trim( (string) $request->get_param( 'feature_date' ) );
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ) {
				update_post_meta( $id, '_mhp_feature_date', $d );
			} else {
				delete_post_meta( $id, '_mhp_feature_date' );
			}
		}
		delete_transient( 'mhp_lesson_day' );
		return (int) $id;
	}

	/**
	 * POST /admin/campus/lessons.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function create_lesson( $request ) {
		if ( ! self::prep_ok() ) {
			return self::no_prep();
		}
		if ( '' === trim( (string) $request->get_param( 'title' ) ) ) {
			return new WP_Error( 'nros_no_title', 'শিরোনাম লাগবে।', array( 'status' => 400 ) );
		}
		$id = self::save_lesson( $request, 0 );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return array( 'ok' => true, 'lesson' => self::lesson_row( get_post( $id ) ) );
	}

	/**
	 * PATCH /admin/campus/lessons/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function update_lesson( $request ) {
		if ( ! self::prep_ok() ) {
			return self::no_prep();
		}
		$id = absint( $request['id'] );
		$p  = get_post( $id );
		if ( ! $p || 'mh_lesson' !== $p->post_type ) {
			return new WP_Error( 'nros_not_found', 'পাঠটি নেই।', array( 'status' => 404 ) );
		}
		$id = self::save_lesson( $request, $id );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return array( 'ok' => true, 'lesson' => self::lesson_row( get_post( $id ) ) );
	}

	/**
	 * DELETE /admin/campus/lessons/{id} — to trash, not gone for good.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function delete_lesson( $request ) {
		if ( ! self::prep_ok() ) {
			return self::no_prep();
		}
		$id = absint( $request['id'] );
		$p  = get_post( $id );
		if ( ! $p || 'mh_lesson' !== $p->post_type ) {
			return new WP_Error( 'nros_not_found', 'পাঠটি নেই।', array( 'status' => 404 ) );
		}
		wp_trash_post( $id );
		delete_transient( 'mhp_lesson_day' );
		return array( 'ok' => true );
	}

	/**
	 * POST /admin/campus/seed — install the r47 content pack now (idempotent).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function seed( $request ) {
		if ( ! self::prep_ok() ) {
			return self::no_prep();
		}
		$lessons = class_exists( 'MHP_Lessons' ) && method_exists( 'MHP_Lessons', 'seed_r47' ) ? (int) MHP_Lessons::seed_r47() : 0;
		$cv      = class_exists( 'MHP_CV' ) && method_exists( 'MHP_CV', 'seed' ) ? (int) MHP_CV::seed( true ) : 0;
		return array( 'ok' => true, 'lessons' => $lessons, 'cv' => $cv );
	}
}
