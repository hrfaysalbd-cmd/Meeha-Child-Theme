<?php
/**
 * NROS_Applications — public correspondent-application intake (with KYC
 * document uploads: CV, NID, Certificate) and staff approve/reject actions.
 * KYC files are stored outside the public uploads tree and only ever
 * served back through an authenticated endpoint.
 *
 * @package NewsroomOS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NROS_Applications {

	const ALLOWED_MIMES = array(
		'application/pdf',
		'image/jpeg',
		'image/png',
	);

	const MAX_BYTES = 5242880; // 5MB.

	const FIELDS = array( 'cv', 'nid', 'certificate' );

	const REQUIRED = array( 'nid' );

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
			'/applications',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'submit' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'list_applications' ),
					'permission_callback' => array( 'NROS_Core', 'require_staff' ),
				),
			)
		);

		register_rest_route(
			NROS_API_NS,
			'/applications/(?P<id>\d+)/approve',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'approve' ),
				'permission_callback' => array( 'NROS_Core', 'require_staff' ),
			)
		);

		register_rest_route(
			NROS_API_NS,
			'/applications/(?P<id>\d+)/reject',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'reject' ),
				'permission_callback' => array( 'NROS_Core', 'require_staff' ),
			)
		);

		register_rest_route(
			NROS_API_NS,
			'/applications/(?P<id>\d+)/files/(?P<field>[a-z]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'serve_file' ),
				'permission_callback' => '__return_true', // Fine-grained check happens inside can_view_file().
			)
		);
	}

	/**
	 * Rate limit on the public application endpoint, to slow down spam of
	 * the upload+email flow.
	 *
	 * Round 18: the old limit was 5 per IP per DAY. Mobile operators in
	 * Bangladesh put many phones behind one shared (CGNAT) address, and a
	 * proxy/CDN in front of the site can make every visitor share the
	 * proxy's address — then the first five applicants of the day used up
	 * the quota for everyone and every later applicant got a 429. Now: 30
	 * per IP per hour (filterable) plus 3 per email per day, which still
	 * stops a flood but not real applicants. Only counted for requests that
	 * pass validation (see submit()).
	 *
	 * @param string $email Applicant email.
	 * @return bool True when over the limit.
	 */
	protected static function rate_limited( $email ) {
		$ip       = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$ip_key   = 'nros_apply_rl_ip_' . md5( $ip );
		$mail_key = 'nros_apply_rl_em_' . md5( strtolower( $email ) );
		$ip_max   = (int) apply_filters( 'nros_apply_rate_limit_per_ip_hour', 30 );

		$ip_count   = (int) get_transient( $ip_key );
		$mail_count = (int) get_transient( $mail_key );
		if ( $ip_count >= $ip_max || $mail_count >= 3 ) {
			return true;
		}

		set_transient( $ip_key, $ip_count + 1, HOUR_IN_SECONDS );
		set_transient( $mail_key, $mail_count + 1, DAY_IN_SECONDS );
		return false;
	}

	/**
	 * Checks one uploaded file's size and REAL content type before anything
	 * is saved, so a bad file can never leave a half-saved application.
	 *
	 * @param array $file One entry from $_FILES-style array.
	 * @return string|WP_Error The detected MIME type.
	 */
	protected static function check_kyc_file( $file ) {
		if ( (int) $file['size'] > self::MAX_BYTES ) {
			return new WP_Error( 'nros_file_too_large', 'ফাইলের আকার সর্বোচ্চ ৫ এমবি হতে হবে।', array( 'status' => 400 ) );
		}
		$finfo     = finfo_open( FILEINFO_MIME_TYPE );
		$real_mime = finfo_file( $finfo, $file['tmp_name'] );
		finfo_close( $finfo );
		if ( ! in_array( $real_mime, self::ALLOWED_MIMES, true ) ) {
			return new WP_Error( 'nros_bad_file_type', 'শুধুমাত্র PDF, JPG অথবা PNG ফাইল গ্রহণযোগ্য।', array( 'status' => 400 ) );
		}
		return $real_mime;
	}

	/**
	 * POST /applications — public KYC application submission.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function submit( $request ) {
		$full_name = sanitize_text_field( (string) $request->get_param( 'full_name' ) );
		$email     = sanitize_email( (string) $request->get_param( 'email' ) );
		$phone     = sanitize_text_field( (string) $request->get_param( 'phone' ) );
		$division  = sanitize_text_field( (string) $request->get_param( 'division' ) );
		$district  = sanitize_text_field( (string) $request->get_param( 'district' ) );
		$upazila   = sanitize_text_field( (string) $request->get_param( 'upazila' ) );

		if ( '' === $full_name || ! is_email( $email ) || '' === $phone ) {
			return new WP_Error( 'nros_bad_request', 'নাম, ইমেইল ও ফোন নম্বর আবশ্যক।', array( 'status' => 400 ) );
		}

		$files = $request->get_file_params();
		foreach ( self::REQUIRED as $field ) {
			if ( empty( $files[ $field ] ) || UPLOAD_ERR_OK !== $files[ $field ]['error'] ) {
				$too_big = ! empty( $files[ $field ] ) && in_array( (int) $files[ $field ]['error'], array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true );
				return new WP_Error(
					$too_big ? 'nros_file_too_large' : 'nros_missing_file',
					$too_big ? 'এনআইডি ফাইলটি সার্ভারের সীমার চেয়ে বড়। ছোট ছবি/ফাইল দিন।' : 'এনআইডি আপলোড আবশ্যক।',
					array( 'status' => 400 )
				);
			}
		}

		// Round 18: validate every file BEFORE writing the row (previously a
		// bad optional file returned an error after the row was saved).
		$checked = array();
		foreach ( self::FIELDS as $field ) {
			if ( empty( $files[ $field ] ) || UPLOAD_ERR_OK !== $files[ $field ]['error'] ) {
				continue;
			}
			$mime = self::check_kyc_file( $files[ $field ] );
			if ( is_wp_error( $mime ) ) {
				return $mime;
			}
			$checked[ $field ] = $files[ $field ];
		}

		global $wpdb;
		$table = $wpdb->prefix . 'nros_applications';
		$now   = current_time( 'mysql' );

		$pending = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE email = %s AND status = 'pending' ORDER BY id DESC LIMIT 1", $email ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $pending ) {
			return new WP_Error( 'nros_already_pending', 'এই ইমেইলে একটি আবেদন আগেই জমা আছে, সেটি পর্যালোচনার অপেক্ষায়।', array( 'status' => 409 ) );
		}

		if ( self::rate_limited( $email ) ) {
			return new WP_Error( 'nros_rate_limited', 'অনেকগুলো আবেদন হয়েছে, কিছু সময় পর চেষ্টা করুন।', array( 'status' => 429 ) );
		}

		$row = array(
				'full_name' => $full_name,
				'email'     => $email,
				'phone'     => $phone,
				'division'  => $division,
				'district'  => $district,
				'upazila'   => $upazila,
				'status'    => 'pending',
				'created_at' => $now,
				'updated_at' => $now,
		);

		// Round 18: the insert result was never checked, so a failed write
		// (missing/old table, a 4-byte character in a non-utf8mb4 table…)
		// still told the applicant "submitted" while nothing reached the
		// publisher app. Repair the table once and retry; otherwise say so.
		$inserted = $wpdb->insert( $table, $row );
		if ( false === $inserted ) {
			NROS_Core::create_tables();
			$inserted = $wpdb->insert( $table, $row );
		}
		if ( false === $inserted ) {
			$row['full_name'] = wp_encode_emoji( $row['full_name'] );
			$inserted         = $wpdb->insert( $table, $row );
		}
		if ( false === $inserted || ! $wpdb->insert_id ) {
			error_log( 'newsroom-os: application insert failed: ' . $wpdb->last_error ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return new WP_Error( 'nros_db_error', 'আবেদন সংরক্ষণ করা যায়নি (ডাটাবেস সমস্যা)। কিছুক্ষণ পর আবার চেষ্টা করুন।', array( 'status' => 500 ) );
		}

		$application_id = (int) $wpdb->insert_id;

		foreach ( $checked as $field => $file ) {
			$result = self::store_kyc_file( $application_id, $field, $file );
			if ( is_wp_error( $result ) ) {
				// Don't leave an application without its NID behind.
				if ( in_array( $field, self::REQUIRED, true ) ) {
					$wpdb->delete( $table, array( 'id' => $application_id ) );
					$wpdb->delete( $wpdb->prefix . 'nros_application_files', array( 'application_id' => $application_id ) );
				}
				return $result;
			}
		}

		/**
		 * Round 18 — a new application reached the database (drives the
		 * staff notification).
		 *
		 * @param int   $application_id Application id.
		 * @param array $row            Saved fields.
		 */
		do_action( 'nros_application_submitted', $application_id, $row );

		$admin_email = get_option( 'admin_email' );
		if ( $admin_email ) {
			wp_mail(
				$admin_email,
				'নতুন সংবাদদাতা আবেদন — ' . $full_name,
				"নতুন একটি সংবাদদাতা আবেদন জমা হয়েছে।\n\nনাম: {$full_name}\nইমেইল: {$email}\nফোন: {$phone}\n\nওয়ার্ডপ্রেস অ্যাডমিন থেকে যাচাই করুন।"
			);
		}

		return new WP_REST_Response(
			array(
				'ok'             => true,
				'application_id' => $application_id,
			),
			201
		);
	}

	/**
	 * Validates and stores one uploaded KYC file into the private upload
	 * directory under a randomized filename, after checking size, MIME
	 * type, and the file's REAL content type (not just its extension, to
	 * guard against a renamed executable).
	 *
	 * @param int    $application_id Application row id.
	 * @param string $field          cv|nid|certificate.
	 * @param array  $file           One entry from $_FILES-style array.
	 * @return true|WP_Error
	 */
	protected static function store_kyc_file( $application_id, $field, $file ) {
		if ( $file['size'] > self::MAX_BYTES ) {
			return new WP_Error( 'nros_file_too_large', 'ফাইলের আকার সর্বোচ্চ ৫ এমবি হতে হবে।', array( 'status' => 400 ) );
		}

		$finfo     = finfo_open( FILEINFO_MIME_TYPE );
		$real_mime = finfo_file( $finfo, $file['tmp_name'] );
		finfo_close( $finfo );

		if ( ! in_array( $real_mime, self::ALLOWED_MIMES, true ) ) {
			return new WP_Error( 'nros_bad_file_type', 'শুধুমাত্র PDF, JPG অথবা PNG ফাইল গ্রহণযোগ্য।', array( 'status' => 400 ) );
		}

		$ext_map = array(
			'application/pdf' => 'pdf',
			'image/jpeg'       => 'jpg',
			'image/png'        => 'png',
		);
		$ext = $ext_map[ $real_mime ];

		$stored_name = $field . '-' . $application_id . '-' . wp_generate_password( 20, false ) . '.' . $ext;
		$destination = NROS_PRIVATE_DIR . '/' . $stored_name;

		$moved = false;
		if ( is_uploaded_file( $file['tmp_name'] ) ) {
			$moved = move_uploaded_file( $file['tmp_name'], $destination ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_move_uploaded_file
		} else {
			// Not a genuine PHP upload (e.g. in a test harness) — fall back to rename.
			$moved = rename( $file['tmp_name'], $destination ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rename
		}

		if ( ! $moved ) {
			return new WP_Error( 'nros_upload_failed', 'ফাইল সংরক্ষণ করা যায়নি।', array( 'status' => 500 ) );
		}

		chmod( $destination, 0600 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod

		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'nros_application_files',
			array(
				'application_id' => $application_id,
				'field'          => $field,
				'stored_name'    => $stored_name,
				'original_name'  => isset( $file['name'] ) ? sanitize_file_name( $file['name'] ) : $stored_name,
				'mime_type'      => $real_mime,
				'size_bytes'     => (int) $file['size'],
				'created_at'     => current_time( 'mysql' ),
			)
		);

		return true;
	}

	/**
	 * GET /applications — staff listing, newest first.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function list_applications( $request ) {
		global $wpdb;
		$table = $wpdb->prefix . 'nros_applications';

		$status = sanitize_text_field( (string) $request->get_param( 'status' ) );
		$where  = '';
		if ( $status ) {
			$where = $wpdb->prepare( 'WHERE status = %s', $status );
		}

		$rows = $wpdb->get_results( "SELECT * FROM {$table} {$where} ORDER BY created_at DESC LIMIT 200", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// Round 18: say which KYC files each application has, so the
		// publisher app can offer to open them.
		$ids = array_map( 'intval', wp_list_pluck( (array) $rows, 'id' ) );
		$has = array();
		if ( $ids ) {
			$files = $wpdb->get_results( "SELECT application_id, field FROM {$wpdb->prefix}nros_application_files WHERE application_id IN (" . implode( ',', $ids ) . ')', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			foreach ( (array) $files as $f ) {
				$has[ (int) $f['application_id'] ][ $f['field'] ] = true;
			}
		}
		foreach ( (array) $rows as $i => $row ) {
			$rows[ $i ]['files'] = array_keys( $has[ (int) $row['id'] ] ?? array() );
		}

		return new WP_REST_Response( $rows, 200 );
	}

	/**
	 * Capabilities that mark an account as newsroom staff (or anyone with
	 * user-management power). Checked by CAPABILITY, not role name, so a
	 * custom or renamed role carrying these powers is still refused — such
	 * an account must never be touched by a public application.
	 *
	 * @var string[]
	 */
	const STAFF_CAPS = array( 'edit_others_posts', 'manage_options', 'nros_publisher', 'list_users', 'promote_users' );

	/**
	 * Copies the application's phone/area onto a NEW user's profile, using
	 * the theme's existing user-meta keys.
	 *
	 * @param int   $user_id User id.
	 * @param array $app     Application row.
	 */
	protected static function apply_application_meta( $user_id, $app ) {
		update_user_meta( $user_id, 'meeha_phone', $app['phone'] );
		update_user_meta( $user_id, 'meeha_division', $app['division'] );
		update_user_meta( $user_id, 'meeha_district', $app['district'] );
		update_user_meta( $user_id, 'meeha_upazila', $app['upazila'] );
	}

	/**
	 * Fills ONLY the blank profile fields of an EXISTING account from the
	 * application. The applicant's ownership of the email is never verified
	 * (anyone can type any address into the public form), so an approval
	 * must not overwrite what the real account holder already saved.
	 *
	 * @param WP_User $user Existing user.
	 * @param array   $app  Application row.
	 */
	protected static function fill_blank_profile( $user, $app ) {
		$map = array(
			'meeha_phone'    => 'phone',
			'meeha_division' => 'division',
			'meeha_district' => 'district',
			'meeha_upazila'  => 'upazila',
		);
		foreach ( $map as $meta_key => $field ) {
			if ( '' === trim( (string) get_user_meta( $user->ID, $meta_key, true ) ) && '' !== (string) $app[ $field ] ) {
				update_user_meta( $user->ID, $meta_key, $app[ $field ] );
			}
		}

		// WordPress's default display name is the login; anything else was
		// chosen by the account holder and is kept.
		if ( '' === trim( (string) $user->display_name ) || $user->display_name === $user->user_login ) {
			wp_update_user(
				array(
					'ID'           => $user->ID,
					'display_name' => $app['full_name'],
				)
			);
		}
	}

	/**
	 * Marks an application approved and links it to the correspondent
	 * account it produced (new or upgraded).
	 *
	 * @param int $id      Application id.
	 * @param int $user_id User id.
	 */
	protected static function mark_approved( $id, $user_id ) {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'nros_applications',
			array(
				'status'          => 'approved',
				'created_user_id' => $user_id,
				'updated_at'      => current_time( 'mysql' ),
			),
			array( 'id' => $id )
		);
	}

	/**
	 * POST /applications/{id}/approve — creates a correspondent WP user,
	 * copies the application's area data onto the new user's profile
	 * (reusing the theme's existing meta keys), and emails login details.
	 *
	 * Round 10: people who already registered on meeha.news (as a
	 * subscriber, for comments/newsletter) used to hit a dead end here —
	 * "এই ইমেইলে আগেই একাউন্ট আছে।" — and could never be approved. Now an
	 * existing NON-staff account is upgraded in place instead (see
	 * upgrade_existing_user()); only staff accounts are still refused.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function approve( $request ) {
		global $wpdb;
		$table = $wpdb->prefix . 'nros_applications';
		$id    = (int) $request->get_param( 'id' );

		$app = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $app ) {
			return new WP_Error( 'nros_not_found', 'আবেদন পাওয়া যায়নি।', array( 'status' => 404 ) );
		}

		if ( 'pending' !== $app['status'] ) {
			return new WP_Error( 'nros_already_processed', 'এই আবেদনটি আগেই প্রসেস হয়েছে।', array( 'status' => 409 ) );
		}

		$existing_id = email_exists( $app['email'] );
		if ( $existing_id ) {
			return self::upgrade_existing_user( $app, (int) $existing_id );
		}

		$username = sanitize_user( current( explode( '@', $app['email'] ) ) . '-' . wp_generate_password( 4, false ), true );
		$password = wp_generate_password( 14, true );

		$user_id = wp_insert_user(
			array(
				'user_login'   => $username,
				'user_email'   => $app['email'],
				'user_pass'    => $password,
				'display_name' => $app['full_name'],
				'role'         => 'correspondent',
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		self::apply_application_meta( $user_id, $app );
		self::mark_approved( $id, $user_id );

		wp_mail(
			$app['email'],
			'মিহা নিউজ — সংবাদদাতা একাউন্ট অনুমোদিত হয়েছে',
			"প্রিয় {$app['full_name']},\n\nআপনার সংবাদদাতা আবেদন অনুমোদিত হয়েছে।\n\nলগইন: {$username}\nপাসওয়ার্ড: {$password}\n\nhttps://correspondent.meeha.news থেকে লগইন করুন।"
		);

		return new WP_REST_Response(
			array(
				'ok'            => true,
				'user_id'       => $user_id,
				'existing_user' => false,
			),
			200
		);
	}

	/**
	 * Approves an application whose email already has a WordPress account.
	 *
	 * The public application form cannot prove the applicant owns the
	 * email, so this path is deliberately conservative:
	 * - any account with staff/user-management capabilities → 409, nothing
	 *   changes (see STAFF_CAPS);
	 * - an account that is already a correspondent → 409, nothing changes;
	 * - otherwise `subscriber` is replaced by `correspondent` (other
	 *   non-staff roles kept) and only BLANK profile fields are filled
	 *   (fill_blank_profile()).
	 * The account holder keeps their existing password, and the email tells
	 * them plainly WHY their account changed and whom to contact if they
	 * never applied — so a reader whose address was misused finds out.
	 *
	 * @param array $app     Application row.
	 * @param int   $user_id Existing user id.
	 * @return WP_REST_Response|WP_Error
	 */
	protected static function upgrade_existing_user( $app, $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return new WP_Error( 'nros_not_found', 'ব্যবহারকারী পাওয়া যায়নি।', array( 'status' => 404 ) );
		}

		foreach ( self::STAFF_CAPS as $cap ) {
			if ( user_can( $user, $cap ) ) {
				return new WP_Error(
					'nros_staff_email',
					'এই ইমেইলটি একজন স্টাফ সদস্যের (এডিটর/পাবলিশার/অ্যাডমিন) একাউন্টে ব্যবহৃত — সংবাদদাতা হিসেবে অনুমোদন করা যাবে না।',
					array( 'status' => 409 )
				);
			}
		}

		if ( in_array( 'correspondent', (array) $user->roles, true ) ) {
			return new WP_Error( 'nros_already_correspondent', 'এই ইমেইলের একাউন্ট আগে থেকেই সংবাদদাতা।', array( 'status' => 409 ) );
		}

		if ( in_array( 'subscriber', (array) $user->roles, true ) ) {
			$user->remove_role( 'subscriber' );
		}
		$user->add_role( 'correspondent' );

		self::fill_blank_profile( get_userdata( $user_id ), $app );
		self::mark_approved( (int) $app['id'], $user_id );

		$reset_url     = wp_lostpassword_url();
		$contact_email = (string) get_option( 'admin_email' );
		$site_url      = home_url( '/' );
		wp_mail(
			$app['email'],
			'মিহা নিউজ — আপনার একাউন্ট সংবাদদাতা একাউন্টে উন্নীত হয়েছে',
			"প্রিয় {$app['full_name']},\n\n"
			. "এই ইমেইল ঠিকানা ({$user->user_email}) দিয়ে মিহা নিউজে একটি সংবাদদাতা আবেদন জমা পড়েছিল, এবং সেই আবেদনটি অনুমোদিত হয়েছে। তাই এই ইমেইলের সাথে যুক্ত আপনার আগের মিহা নিউজ একাউন্টটি এখন সংবাদদাতা একাউন্টে উন্নীত করা হয়েছে — নতুন কোনো একাউন্ট বা পাসওয়ার্ড তৈরি করা হয়নি।\n\n"
			. "https://correspondent.meeha.news থেকে আপনার আগের ইমেইল/ইউজারনেম ও আগের পাসওয়ার্ড দিয়েই লগইন করুন। পাসওয়ার্ড মনে না থাকলে এখানে রিসেট করুন: {$reset_url}\n\n"
			. "আপনি যদি এই আবেদন না করে থাকেন, অনুগ্রহ করে এই ইমেইলের উত্তর দিন অথবা {$contact_email} ঠিকানায় যোগাযোগ করুন ({$site_url}) — আমরা সঙ্গে সঙ্গে পরিবর্তনটি বাতিল করব।",
			$contact_email ? array( 'Reply-To: ' . $contact_email ) : array()
		);

		return new WP_REST_Response(
			array(
				'ok'            => true,
				'user_id'       => $user_id,
				'existing_user' => true,
			),
			200
		);
	}

	/**
	 * POST /applications/{id}/reject — records a reason and emails the
	 * applicant a decline notice.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function reject( $request ) {
		global $wpdb;
		$table  = $wpdb->prefix . 'nros_applications';
		$id     = (int) $request->get_param( 'id' );
		$reason = sanitize_textarea_field( (string) $request->get_param( 'reason' ) );

		$app = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $app ) {
			return new WP_Error( 'nros_not_found', 'আবেদন পাওয়া যায়নি।', array( 'status' => 404 ) );
		}

		$wpdb->update(
			$table,
			array(
				'status'        => 'rejected',
				'reject_reason' => $reason,
				'updated_at'    => current_time( 'mysql' ),
			),
			array( 'id' => $id )
		);

		wp_mail(
			$app['email'],
			'মিহা নিউজ — সংবাদদাতা আবেদন বিষয়ে',
			"প্রিয় {$app['full_name']},\n\nদুঃখিত, আপনার আবেদনটি এই সময়ে অনুমোদন করা যায়নি।" . ( $reason ? "\n\nকারণ: {$reason}" : '' )
		);

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * Determines whether the requesting user may view a given KYC file:
	 * staff always can; the applicant can view their own file only if
	 * logged in with an account matching the application's email (covers
	 * the rare case a correspondent needs to re-confirm their own KYC).
	 *
	 * @param WP_REST_Request $request Request.
	 * @param array           $app     Application row.
	 * @return bool
	 */
	protected static function can_view_file( $request, $app ) {
		$user = NROS_Auth::get_user_from_request( $request );
		if ( $user && ! is_wp_error( $user ) ) {
			if ( user_can( $user, 'edit_others_posts' ) ) {
				return true;
			}
			if ( $user->user_email === $app['email'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * GET /applications/{id}/files/{field} — streams a private KYC file to
	 * an authorized viewer only, with anti-sniffing headers.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function serve_file( $request ) {
		global $wpdb;
		$id    = (int) $request->get_param( 'id' );
		$field = sanitize_key( (string) $request->get_param( 'field' ) );

		$app = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}nros_applications WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $app ) {
			return new WP_Error( 'nros_not_found', 'পাওয়া যায়নি।', array( 'status' => 404 ) );
		}

		if ( ! self::can_view_file( $request, $app ) ) {
			return new WP_Error( 'nros_forbidden', 'অ্যাক্সেস নেই।', array( 'status' => 403 ) );
		}

		$file_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}nros_application_files WHERE application_id = %d AND field = %s ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$id,
				$field
			),
			ARRAY_A
		);

		if ( ! $file_row ) {
			return new WP_Error( 'nros_not_found', 'ফাইল পাওয়া যায়নি।', array( 'status' => 404 ) );
		}

		$path = NROS_PRIVATE_DIR . '/' . $file_row['stored_name'];
		if ( ! file_exists( $path ) ) {
			return new WP_Error( 'nros_not_found', 'ফাইল পাওয়া যায়নি।', array( 'status' => 404 ) );
		}

		header( 'Content-Type: ' . $file_row['mime_type'] );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'Content-Disposition: inline; filename="' . $file_row['original_name'] . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_readfile
		exit;
	}
}
