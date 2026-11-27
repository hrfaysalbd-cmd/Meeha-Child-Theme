<?php
/**
 * r49 — উক্তির ব্যক্তি: when writing a quote card the desk picks the speaker instead of typing
 * name, পদবি and photo again — from Meeha's own people (journalists, correspondents, writers,
 * staff), from প্রোফাইল ডেস্ক (CRM), or from earlier quotes. A new speaker can be added to the
 * CRM from the same form, with the photo.
 *
 * @package NewsroomOS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Quote speaker picker + "CRM-এ যোগ করুন".
 */
class NROS_Quote_People {

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
			'/admin/quotes/people',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'search' ),
				'permission_callback' => array( 'NROS_Core', 'require_staff' ),
			)
		);
		register_rest_route(
			NROS_API_NS,
			'/admin/quotes/people/photo',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'photo' ),
				'permission_callback' => array( 'NROS_Core', 'require_staff' ),
			)
		);
		register_rest_route(
			NROS_API_NS,
			'/admin/quotes/people/to-crm',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'to_crm' ),
				'permission_callback' => array( 'NROS_Core', 'require_staff' ),
			)
		);
	}

	/**
	 * Current user.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_User|null
	 */
	protected static function me( $request ) {
		$u = NROS_Auth::get_user_from_request( $request );
		return $u instanceof WP_User ? $u : null;
	}

	/**
	 * Whether this user may use প্রোফাইল ডেস্ক.
	 *
	 * @param WP_User|null $u User.
	 * @return bool
	 */
	protected static function crm_ok( $u ) {
		return $u && class_exists( 'NROS_Profile_Desk' ) && NROS_Profile_Desk::can_access( $u );
	}

	/**
	 * Role label for a user.
	 *
	 * @param WP_User $u User.
	 * @return string
	 */
	protected static function kind_of( $u ) {
		if ( 'writer' === get_user_meta( $u->ID, 'nros_track', true ) ) {
			return 'মিহা কলম';
		}
		if ( in_array( 'correspondent', (array) $u->roles, true ) ) {
			return 'সংবাদদাতা';
		}
		return 'মিহা নিউজ';
	}

	/**
	 * GET /admin/quotes/people?q=&src=all|team|crm|past — up to 12 of each.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	public static function search( $request ) {
		global $wpdb;
		$me  = self::me( $request );
		$q   = trim( sanitize_text_field( (string) $request->get_param( 'q' ) ) );
		$src = sanitize_key( (string) $request->get_param( 'src' ) );
		$src = in_array( $src, array( 'team', 'crm', 'past' ), true ) ? $src : 'all';
		$out = array(
			'team'   => array(),
			'crm'    => array(),
			'past'   => array(),
			'crm_ok' => self::crm_ok( $me ),
		);

		if ( 'all' === $src || 'team' === $src ) {
			$args = array(
				'number'   => 12,
				'orderby'  => 'display_name',
				'fields'   => 'all',
				'role__in' => array_values( array_intersect( array_keys( wp_roles()->roles ), array( 'administrator', 'editor', 'author', 'contributor', 'meeha_publisher', 'correspondent' ) ) ),
			);
			if ( '' !== $q ) {
				$args['search']         = '*' . $q . '*';
				$args['search_columns'] = array( 'display_name', 'user_login', 'user_nicename' );
			}
			foreach ( get_users( $args ) as $u ) {
				$aid           = (int) get_user_meta( $u->ID, '_nros_avatar_id', true );
				$out['team'][] = array(
					'src'      => 'team',
					'id'       => (int) $u->ID,
					'name'     => (string) $u->display_name,
					'role'     => trim( (string) get_user_meta( $u->ID, 'meeha_author_title', true ) ),
					'kind'     => self::kind_of( $u ),
					'photo'    => $aid ? (string) wp_get_attachment_image_url( $aid, 'thumbnail' ) : '',
					'photo_id' => $aid && 'attachment' === get_post_type( $aid ) ? $aid : 0,
				);
			}
		}

		if ( ( 'all' === $src || 'crm' === $src ) && $out['crm_ok'] ) {
			$te   = NROS_Profile_Desk::t( 'entities' );
			$vis  = NROS_Profile_Desk::visible_sql( $me );
			$like = '%' . $wpdb->esc_like( $q ) . '%';
			$rows = '' !== $q
				? $wpdb->get_results( $wpdb->prepare( "SELECT e.id, e.name_bn, e.name_en, e.title, e.category, e.photo_file_id, e.sensitivity FROM {$te} e WHERE e.kind = 'person' AND {$vis} AND (e.name_bn LIKE %s OR e.name_en LIKE %s OR e.aliases LIKE %s OR e.title LIKE %s) ORDER BY e.importance DESC, e.name_bn ASC LIMIT 12", $like, $like, $like, $like ), ARRAY_A ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				: $wpdb->get_results( "SELECT e.id, e.name_bn, e.name_en, e.title, e.category, e.photo_file_id, e.sensitivity FROM {$te} e WHERE e.kind = 'person' AND {$vis} ORDER BY e.importance DESC, e.updated_at DESC LIMIT 12", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$cats = NROS_Profile_Desk::CATEGORIES;
			foreach ( (array) $rows as $r ) {
				$out['crm'][] = array(
					'src'        => 'crm',
					'id'         => (int) $r['id'],
					'name'       => (string) ( $r['name_bn'] ? $r['name_bn'] : $r['name_en'] ),
					'role'       => (string) $r['title'],
					'kind'       => isset( $cats[ $r['category'] ] ) ? (string) $cats[ $r['category'] ][1] : 'ব্যক্তি',
					'photo'      => (int) $r['photo_file_id'] ? NROS_PD_Files::signed_url( (int) $r['photo_file_id'], (int) $me->ID, HOUR_IN_SECONDS ) : '',
					'photo_id'   => 0,
					'has_photo'  => (int) $r['photo_file_id'] > 0,
					'restricted' => 'restricted' === $r['sensitivity'],
				);
			}
		}

		if ( 'all' === $src || 'past' === $src ) {
			$where = '';
			if ( '' !== $q ) {
				$where = $wpdb->prepare( ' AND m.meta_value LIKE %s', '%' . $wpdb->esc_like( $q ) . '%' );
			}
			// Latest quote per speaker name.
			$rows = $wpdb->get_results( "SELECT m.meta_value AS name, MAX(p.ID) AS last_id, COUNT(*) AS n FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = '_meeha_quote_speaker' AND m.meta_value <> '' AND p.post_type = 'meeha_photocard' AND p.post_status IN ('publish','draft','pending','future','private'){$where} GROUP BY m.meta_value ORDER BY last_id DESC LIMIT 12", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
			foreach ( (array) $rows as $r ) {
				$pid           = (int) $r['last_id'];
				$photo         = (int) get_post_thumbnail_id( $pid );
				$out['past'][] = array(
					'src'      => 'past',
					'id'       => $pid,
					'name'     => (string) $r['name'],
					'role'     => trim( (string) get_post_meta( $pid, '_meeha_quote_role', true ) ),
					'kind'     => sprintf( 'আগের উক্তি · %s টি', NROS_Core::bn_digits( (string) (int) $r['n'] ) ),
					'photo'    => $photo ? (string) wp_get_attachment_image_url( $photo, 'thumbnail' ) : '',
					'photo_id' => $photo,
				);
			}
		}
		return $out;
	}

	/**
	 * POST /admin/quotes/people/photo {src: crm, id} — a CRM profile photo copied into the media
	 * library so the quote card can use it (team / past photos are already there).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function photo( $request ) {
		$me = self::me( $request );
		if ( 'crm' !== $request->get_param( 'src' ) ) {
			return new WP_Error( 'nros_bad_request', 'শুধু প্রোফাইল ডেস্কের ছবি আনতে হয়।', array( 'status' => 400 ) );
		}
		if ( ! self::crm_ok( $me ) ) {
			return new WP_Error( 'nros_forbidden', 'প্রোফাইল ডেস্কের অ্যাক্সেস নেই।', array( 'status' => 403 ) );
		}
		return NROS_PD_Files::profile_photo_to_media( absint( $request->get_param( 'id' ) ), $me );
	}

	/**
	 * POST /admin/quotes/people/to-crm {name, role, photo_id, category, force} — add the speaker
	 * to প্রোফাইল ডেস্ক (a near-duplicate name answers 409 with the matches, unless force).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function to_crm( $request ) {
		$me = self::me( $request );
		if ( ! self::crm_ok( $me ) ) {
			return new WP_Error( 'nros_forbidden', 'প্রোফাইল ডেস্কের অ্যাক্সেস নেই — পাবলিশারের কাছে অনুমতি চান।', array( 'status' => 403 ) );
		}
		$name = trim( sanitize_text_field( (string) $request->get_param( 'name' ) ) );
		if ( '' === $name ) {
			return new WP_Error( 'nros_bad_request', 'নাম দিন।', array( 'status' => 400 ) );
		}
		$cat = sanitize_key( (string) $request->get_param( 'category' ) );
		$cat = isset( NROS_Profile_Desk::CATEGORIES[ $cat ] ) && 'person' === NROS_Profile_Desk::CATEGORIES[ $cat ][0] ? $cat : 'person';
		$bn  = (bool) preg_match( '/[\x{0980}-\x{09FF}]/u', $name );

		$sub = new WP_REST_Request( 'POST', '/' . NROS_API_NS . '/pd/entities' );
		foreach ( $request->get_headers() as $k => $v ) {
			$sub->set_header( $k, $v );
		}
		$sub->set_header( 'content-type', 'application/json' );
		$sub->set_body(
			wp_json_encode(
				array(
					'name_bn'  => $bn ? $name : '',
					'name_en'  => $bn ? '' : $name,
					'title'    => sanitize_text_field( (string) $request->get_param( 'role' ) ),
					'category' => $cat,
					'tags'     => array( 'উক্তি' ),
				)
			)
		);
		if ( $request->get_param( 'force' ) ) {
			$sub->set_param( 'force', 1 );
		}
		$res = NROS_Profile_Desk::create_entity( $sub );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$data = $res instanceof WP_REST_Response ? $res->get_data() : (array) $res;
		$id   = isset( $data['id'] ) ? (int) $data['id'] : ( isset( $data['entity']['id'] ) ? (int) $data['entity']['id'] : 0 );
		$warn = '';
		$pid  = absint( $request->get_param( 'photo_id' ) );
		if ( $id && $pid ) {
			$ok = NROS_PD_Files::media_to_profile_photo( $pid, $id, $me );
			if ( is_wp_error( $ok ) ) {
				$warn = 'প্রোফাইল হয়েছে, কিন্তু ছবি যোগ হয়নি: ' . $ok->get_error_message();
			}
		}
		return array( 'ok' => true, 'id' => $id, 'warning' => $warn );
	}
}
