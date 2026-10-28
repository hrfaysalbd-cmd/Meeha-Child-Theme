<?php
/**
 * NROS_Areas — public division/upazila lookups for the correspondent
 * application form (used BEFORE login, on the KYC apply screen) and for
 * the profile's area fields. Reuses the theme's existing
 * meeha_get_bd_divisions()/meeha_get_upazilas_for_district() helpers
 * instead of duplicating Bangladesh's administrative area data in this
 * plugin.
 *
 * @package NewsroomOS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NROS_Areas {

	/**
	 * Registers routes.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Registers REST routes under nros/v1. Public (no auth) since the
	 * application form that needs this loads before login.
	 */
	public static function register_routes() {
		register_rest_route(
			NROS_API_NS,
			'/areas',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_areas' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * GET /areas — divisions and, for each district within them, upazilas —
	 * sourced from the theme's own helper functions when available.
	 *
	 * meeha_get_bd_divisions() returns an ASSOCIATIVE array keyed by division
	 * name, each value a plain array of district-name strings — e.g.
	 * array( 'ঢাকা' => array( 'ঢাকা', 'গাজীপুর', ... ), ... ) — not a list of
	 * objects with 'name'/'districts' keys. A PHP associative array like
	 * that serializes to a JSON OBJECT, not an array, which would break any
	 * frontend expecting divisions.map()/divisions.find() to work on an
	 * array. This method reshapes it into a plain numeric array of
	 * { name, districts } objects so the REST response is a stable, frontend
	 * friendly array regardless of how the theme stores the data internally.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function get_areas( $request ) {
		if ( ! function_exists( 'meeha_get_bd_divisions' ) ) {
			return new WP_REST_Response(
				array(
					'divisions' => array(),
					'upazilas'  => array(),
					'source'    => 'unavailable',
				),
				200
			);
		}

		$raw_divisions = meeha_get_bd_divisions();
		$divisions     = array();
		$upazilas      = array();

		$has_upazila_fn = function_exists( 'meeha_get_upazilas_for_district' );

		if ( is_array( $raw_divisions ) ) {
			foreach ( $raw_divisions as $division_name => $districts ) {
				// Defensive: tolerate either the real shape (plain string
				// list) or, if the theme's data shape ever changes, a list
				// of { name: ... } objects — never let an unexpected shape
				// reach the frontend as something other than a string.
				$district_names = array();
				foreach ( (array) $districts as $district ) {
					$district_name = is_array( $district ) ? ( $district['name'] ?? '' ) : (string) $district;
					if ( '' === $district_name ) {
						continue;
					}
					$district_names[] = $district_name;

					if ( $has_upazila_fn && ! isset( $upazilas[ $district_name ] ) ) {
						$upazilas[ $district_name ] = array_values( (array) meeha_get_upazilas_for_district( $district_name ) );
					}
				}

				$divisions[] = array(
					'name'      => (string) $division_name,
					'districts' => $district_names,
				);
			}
		}

		return new WP_REST_Response(
			array(
				'divisions' => $divisions,
				'upazilas'  => $upazilas,
				'source'    => 'theme',
			),
			200
		);
	}
}
