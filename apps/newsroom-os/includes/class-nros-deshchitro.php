<?php
/**
 * দেশচিত্র input for the publisher app (1.10.0).
 *
 * The data, rules and storage live in the Meeha theme (inc/deshchitro, theme 2.4.7+); this class only exposes the
 * theme's input sheet to the app's JWT login:
 *
 *   GET    /admin/deshchitro?date=Y-m-d     sheet for one day (config, geography, saved values, the day's news)
 *   POST   /admin/deshchitro {date, status, data}
 *   DELETE /admin/deshchitro?date=Y-m-d
 *
 * Staff only (edit_others_posts). Without the theme module every call answers 501 with a clear message.
 *
 * @package NewsroomOS
 */

defined( 'ABSPATH' ) || exit;

class NROS_Deshchitro {

	/** Registers routes. */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/** Routes. */
	public static function register_routes() {
		register_rest_route(
			NROS_API_NS,
			'/admin/deshchitro',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_sheet' ),
					'permission_callback' => array( 'NROS_Core', 'require_staff' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'save' ),
					'permission_callback' => array( 'NROS_Core', 'require_staff' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( __CLASS__, 'delete' ),
					'permission_callback' => array( 'NROS_Core', 'require_staff' ),
				),
			)
		);
	}

	/**
	 * Theme module present?
	 *
	 * @return true|WP_Error
	 */
	private static function ready() {
		if ( function_exists( 'meeha_dc_sheet' ) && function_exists( 'meeha_dc_save_day' ) ) {
			return true;
		}
		return new WP_Error( 'nros_deshchitro_theme', 'দেশচিত্রের জন্য মিহা থিম ২.৪.৭ বা নতুন সংস্করণ চালু থাকতে হবে।', array( 'status' => 501 ) );
	}

	/**
	 * GET.
	 *
	 * @param WP_REST_Request $r Request.
	 * @return array|WP_Error
	 */
	public static function get_sheet( $r ) {
		$ok = self::ready();
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		return meeha_dc_sheet( (string) $r->get_param( 'date' ) );
	}

	/**
	 * POST.
	 *
	 * @param WP_REST_Request $r Request.
	 * @return array|WP_Error
	 */
	public static function save( $r ) {
		$ok = self::ready();
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$user = NROS_Auth::get_user_from_request( $r );
		$day  = (string) $r->get_param( 'date' );
		$res  = meeha_dc_save_day( $day, (array) $r->get_param( 'data' ), (string) $r->get_param( 'status' ), $user && ! is_wp_error( $user ) ? (int) $user->ID : 0 );
		return is_wp_error( $res ) ? $res : meeha_dc_sheet( $day );
	}

	/**
	 * DELETE.
	 *
	 * @param WP_REST_Request $r Request.
	 * @return array|WP_Error
	 */
	public static function delete( $r ) {
		$ok = self::ready();
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$day = (string) $r->get_param( 'date' );
		meeha_dc_delete_day( $day );
		return meeha_dc_sheet( $day );
	}
}
