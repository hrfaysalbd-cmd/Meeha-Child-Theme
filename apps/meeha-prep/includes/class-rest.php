<?php
/**
 * REST endpoints used by the section's JavaScript (namespace meeha-prep/v1). Logged-in calls use the standard
 * cookie + X-WP-Nonce authentication; a fresh nonce is available from admin-ajax (action mhp_nonce) when a page
 * was served from cache.
 *
 * @package meeha-prep
 */

defined( 'ABSPATH' ) || exit;

/**
 * REST.
 */
class MHP_Rest {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'wp_ajax_mhp_nonce', array( __CLASS__, 'nonce' ) );
		add_action( 'wp_ajax_nopriv_mhp_nonce', array( __CLASS__, 'nonce' ) );
	}

	/**
	 * Fresh REST nonce.
	 */
	public static function nonce() {
		nocache_headers();
		wp_send_json_success(
			array(
				'nonce'  => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
				'logged' => is_user_logged_in(),
			)
		);
	}

	/**
	 * Logged-in check.
	 *
	 * @return bool|WP_Error
	 */
	public static function need_login() {
		return is_user_logged_in() ? true : new WP_Error( 'mhp_login', 'লগইন করুন।', array( 'status' => 401 ) );
	}

	/**
	 * Routes.
	 */
	public static function routes() {
		register_rest_route(
			'meeha-prep/v1',
			'/attempts/(?P<id>\d+)/save',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'save_attempt' ),
				'permission_callback' => array( __CLASS__, 'need_login' ),
				'args'                => array(
					'id' => array(
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
		register_rest_route(
			'meeha-prep/v1',
			'/quiz/answer',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'quiz_answer' ),
				'permission_callback' => '__return_true', // Guests may play (setting); nothing is stored for them.
				'args'                => array(
					'i'      => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'choice' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
		register_rest_route(
			'meeha-prep/v1',
			'/jobs/(?P<id>\d+)/save',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'save_job' ),
				'permission_callback' => array( __CLASS__, 'need_login' ),
				'args'                => array(
					'id' => array(
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Autosave an attempt.
	 *
	 * @param WP_REST_Request $r Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function save_attempt( WP_REST_Request $r ) {
		$a = MHP_Tests::get_attempt( (int) $r['id'] );
		if ( ! $a || (int) $a['user_id'] !== get_current_user_id() ) {
			return new WP_Error( 'mhp_attempt', 'পরীক্ষাটি পাওয়া যায়নি।', array( 'status' => 404 ) );
		}
		$answers = $r->get_param( 'answers' );
		$answers = is_array( $answers ) ? $answers : array();
		if ( ! MHP_Tests::save( $a, $answers ) ) {
			return new WP_REST_Response(
				array(
					'ok'     => false,
					'closed' => true,
				),
				409
			);
		}
		$left = strtotime( $a['deadline_at'] . ' UTC' ) - time();
		return new WP_REST_Response(
			array(
				'ok'   => true,
				'left' => max( 0, $left ),
			),
			200
		);
	}

	/**
	 * Daily quiz answer.
	 *
	 * @param WP_REST_Request $r Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function quiz_answer( WP_REST_Request $r ) {
		if ( function_exists( 'meeha_campus_beta_blocked' ) && meeha_campus_beta_blocked() ) {
			return new WP_Error( 'mhp_beta', 'পাওয়া যায়নি।', array( 'status' => 404 ) );
		}
		if ( ! mhp_rate_ok( 'quiz', 120, 10 * MINUTE_IN_SECONDS ) ) {
			return new WP_Error( 'mhp_rate', 'একটু থামুন, আবার চেষ্টা করুন।', array( 'status' => 429 ) );
		}
		$res = MHP_Quiz::answer( get_current_user_id(), (int) $r['i'], (int) $r['choice'] );
		if ( is_wp_error( $res ) ) {
			$res->add_data( array( 'status' => 400 ) );
			return $res;
		}
		$resp = new WP_REST_Response( $res, 200 );
		$resp->header( 'Cache-Control', 'no-store' );
		return $resp;
	}

	/**
	 * Toggle a saved job.
	 *
	 * @param WP_REST_Request $r Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function save_job( WP_REST_Request $r ) {
		$on = MHP_Jobs::toggle( get_current_user_id(), (int) $r['id'] );
		if ( null === $on ) {
			return new WP_Error( 'mhp_job', 'বিজ্ঞপ্তিটি পাওয়া যায়নি।', array( 'status' => 404 ) );
		}
		return new WP_REST_Response(
			array(
				'saved' => $on,
				'count' => count( MHP_Jobs::saved( get_current_user_id() ) ),
			),
			200
		);
	}
}
