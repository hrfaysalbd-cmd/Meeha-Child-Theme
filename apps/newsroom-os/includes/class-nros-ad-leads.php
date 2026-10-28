<?php
/**
 * NROS_Ad_Leads — advertiser/ad-client lead records a CORRESPONDENT enters
 * about themselves, from their own app's Profile page (round 7). This is a
 * deliberately separate write surface from NROS_Admin_API's staff-only
 * `/admin/ad-clients` routes, even though both read/write the same
 * `nros_ad_clients` table — per the user's explicit confirmed choice that
 * correspondents self-enter this data rather than staff doing it on their
 * behalf. A correspondent may only ever see/edit rows where `referred_by`
 * is their own user id.
 *
 * `dealing_status` (আগ্রহী/ফাইনাল — "interested"/"final") is a brand-new,
 * separate field from the table's existing `status` (active/inactive, used
 * elsewhere for commission-eligibility/suspension logic) — per the user's
 * explicit confirmed choice, these never share a column.
 *
 * @package NewsroomOS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NROS_Ad_Leads {

	const DEALING_STATUSES = array( 'interested', 'final' );

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
			'/me/ad-clients',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'list_mine' ),
					'permission_callback' => array( 'NROS_Core', 'require_correspondent' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'create_mine' ),
					'permission_callback' => array( 'NROS_Core', 'require_correspondent' ),
				),
			)
		);

		register_rest_route(
			NROS_API_NS,
			'/me/ad-clients/(?P<id>\d+)',
			array(
				'methods'             => 'PATCH',
				'callback'            => array( __CLASS__, 'update_mine' ),
				'permission_callback' => array( 'NROS_Core', 'require_correspondent' ),
			)
		);

		register_rest_route(
			NROS_API_NS,
			'/me/ad-clients/(?P<id>\d+)/visiting-card',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'upload_card' ),
					'permission_callback' => array( 'NROS_Core', 'require_correspondent' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( __CLASS__, 'remove_card' ),
					'permission_callback' => array( 'NROS_Core', 'require_correspondent' ),
				),
			)
		);
	}

	/**
	 * Shapes a raw DB row into the API representation — resolving the
	 * stored attachment id into a URL, same convention as every other
	 * media field this plugin returns, and normalizing an empty
	 * dealing_status to null (never an empty string) for the frontend.
	 *
	 * @param array $row Raw `nros_ad_clients` row.
	 * @return array
	 */
	protected static function shape( $row ) {
		return array(
			'id'             => (int) $row['id'],
			'business_name'  => $row['business_name'],
			'contact_name'   => $row['contact_name'],
			'phone'          => $row['phone'],
			'whatsapp'       => $row['whatsapp'],
			'address'        => $row['address'],
			'visiting_card'  => $row['visiting_card_id'] ? wp_get_attachment_url( $row['visiting_card_id'] ) : null,
			'dealing_status' => $row['dealing_status'] ?: null,
			'status'         => $row['status'],
			'created_at'     => $row['created_at'],
			'updated_at'     => $row['updated_at'],
		);
	}

	/**
	 * GET /me/ad-clients — the correspondent's own entered leads, newest
	 * first.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function list_mine( $request ) {
		global $wpdb;
		$user = NROS_Auth::get_user_from_request( $request );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}nros_ad_clients WHERE referred_by = %d ORDER BY created_at DESC LIMIT 100", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$user->ID
			),
			ARRAY_A
		);

		return new WP_REST_Response( array_map( array( __CLASS__, 'shape' ), $rows ), 200 );
	}

	/**
	 * POST /me/ad-clients — a correspondent records a new advertiser lead
	 * against their own account (`referred_by` is always forced to the
	 * requesting correspondent, never taken from the request).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_mine( $request ) {
		global $wpdb;
		$user = NROS_Auth::get_user_from_request( $request );

		$business_name = sanitize_text_field( (string) $request->get_param( 'business_name' ) );
		if ( '' === $business_name ) {
			return new WP_Error( 'nros_bad_request', 'প্রতিষ্ঠানের নাম আবশ্যক।', array( 'status' => 400 ) );
		}

		$dealing_status = sanitize_key( (string) $request->get_param( 'dealing_status' ) );
		if ( '' !== $dealing_status && ! in_array( $dealing_status, self::DEALING_STATUSES, true ) ) {
			$dealing_status = '';
		}

		$now = current_time( 'mysql' );
		$wpdb->insert(
			$wpdb->prefix . 'nros_ad_clients',
			array(
				'business_name'  => $business_name,
				'contact_name'   => sanitize_text_field( (string) $request->get_param( 'contact_name' ) ),
				'phone'          => sanitize_text_field( (string) $request->get_param( 'phone' ) ),
				'whatsapp'       => sanitize_text_field( (string) $request->get_param( 'whatsapp' ) ),
				'address'        => sanitize_textarea_field( (string) $request->get_param( 'address' ) ),
				'dealing_status' => $dealing_status ?: null,
				'referred_by'    => $user->ID,
				'status'         => 'active',
				'notes'          => '',
				'created_by'     => $user->ID,
				'created_at'     => $now,
				'updated_at'     => $now,
			)
		);

		$id  = (int) $wpdb->insert_id;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}nros_ad_clients WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return new WP_REST_Response( self::shape( $row ), 201 );
	}

	/**
	 * Fetches a lead row only if it belongs to the requesting correspondent
	 * (`referred_by` matches) — 404 either way if not, so this never leaks
	 * which ids exist.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	protected static function owned_row_or_error( $request ) {
		global $wpdb;
		$user = NROS_Auth::get_user_from_request( $request );
		$id   = (int) $request->get_param( 'id' );

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}nros_ad_clients WHERE id = %d", $id ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		if ( ! $row || (int) $row['referred_by'] !== (int) $user->ID ) {
			return new WP_Error( 'nros_not_found', 'পাওয়া যায়নি।', array( 'status' => 404 ) );
		}

		return $row;
	}

	/**
	 * PATCH /me/ad-clients/{id} — a correspondent edits their own lead's
	 * identity fields and/or dealing status. Unlike staff's
	 * `/admin/ad-clients/{id}` (status/notes only), the correspondent owns
	 * the full record here — only `referred_by`/`status`/`created_by` stay
	 * off-limits (status is staff's call; see NROS_Admin_API::clients_update()).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_mine( $request ) {
		global $wpdb;
		$row = self::owned_row_or_error( $request );
		if ( is_wp_error( $row ) ) {
			return $row;
		}

		$update = array( 'updated_at' => current_time( 'mysql' ) );

		$text_fields = array( 'business_name', 'contact_name', 'phone', 'whatsapp' );
		foreach ( $text_fields as $param ) {
			$value = $request->get_param( $param );
			if ( null !== $value ) {
				$update[ $param ] = sanitize_text_field( (string) $value );
			}
		}

		$address = $request->get_param( 'address' );
		if ( null !== $address ) {
			$update['address'] = sanitize_textarea_field( (string) $address );
		}

		$dealing_status = $request->get_param( 'dealing_status' );
		if ( null !== $dealing_status ) {
			$dealing_status            = sanitize_key( (string) $dealing_status );
			$update['dealing_status'] = in_array( $dealing_status, self::DEALING_STATUSES, true ) ? $dealing_status : null;
		}

		if ( isset( $update['business_name'] ) && '' === $update['business_name'] ) {
			return new WP_Error( 'nros_bad_request', 'প্রতিষ্ঠানের নাম আবশ্যক।', array( 'status' => 400 ) );
		}

		$wpdb->update( $wpdb->prefix . 'nros_ad_clients', $update, array( 'id' => (int) $row['id'] ) );

		$fresh = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}nros_ad_clients WHERE id = %d", $row['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return new WP_REST_Response( self::shape( $fresh ), 200 );
	}

	/**
	 * POST /me/ad-clients/{id}/visiting-card — uploads (or replaces) the
	 * visiting-card photo for one of the correspondent's own leads. Expects
	 * a multipart field named "nros_visiting_card". Reuses
	 * NROS_Media::validate_and_upload() for identical size/MIME rules to
	 * every other image upload in this plugin, and deletes any previous
	 * visiting-card attachment once the new one is safely in place.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function upload_card( $request ) {
		global $wpdb;
		$row = self::owned_row_or_error( $request );
		if ( is_wp_error( $row ) ) {
			return $row;
		}

		$user  = NROS_Auth::get_user_from_request( $request );
		$files = $request->get_file_params();

		$attachment_id = NROS_Media::validate_and_upload(
			isset( $files['nros_visiting_card'] ) ? $files['nros_visiting_card'] : array(),
			$user->ID
		);
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		$previous_id = (int) $row['visiting_card_id'];
		$wpdb->update(
			$wpdb->prefix . 'nros_ad_clients',
			array( 'visiting_card_id' => $attachment_id, 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => (int) $row['id'] )
		);

		if ( $previous_id && $previous_id !== $attachment_id ) {
			wp_delete_attachment( $previous_id, true );
		}

		return new WP_REST_Response(
			array( 'ok' => true, 'visiting_card' => wp_get_attachment_url( $attachment_id ) ),
			201
		);
	}

	/**
	 * DELETE /me/ad-clients/{id}/visiting-card — removes the visiting-card
	 * photo from one of the correspondent's own leads, same
	 * upload/remove symmetry as the profile-avatar feature.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function remove_card( $request ) {
		global $wpdb;
		$row = self::owned_row_or_error( $request );
		if ( is_wp_error( $row ) ) {
			return $row;
		}

		$previous_id = (int) $row['visiting_card_id'];
		$wpdb->update(
			$wpdb->prefix . 'nros_ad_clients',
			array( 'visiting_card_id' => null, 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => (int) $row['id'] )
		);

		if ( $previous_id ) {
			wp_delete_attachment( $previous_id, true );
		}

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}
}
