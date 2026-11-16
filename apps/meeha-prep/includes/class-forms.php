<?php
/**
 * Reader / employer submissions: employer circular (→ pending job), success story (→ pending post), job-alert
 * preferences (district, job types, MailPoet e-mail list). Everything lands as "pending" for an editor.
 *
 * @package meeha-prep
 */

defined( 'ABSPATH' ) || exit;

/**
 * Forms.
 */
class MHP_Forms {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'mhp_hub_action_employer_submit', array( __CLASS__, 'employer' ) );
		add_action( 'mhp_hub_action_story_submit', array( __CLASS__, 'story' ) );
		add_action( 'mhp_hub_action_alerts_save', array( __CLASS__, 'alerts' ) );
		add_action( 'admin_post_mhp_circular', array( __CLASS__, 'view_circular' ) );
		add_action( 'post_submitbox_misc_actions', array( __CLASS__, 'circular_link' ) );
	}

	/**
	 * Read a POST text field.
	 *
	 * @param string $k   Key.
	 * @param int    $max Max length.
	 * @param bool   $multi Multi-line.
	 * @return string
	 */
	private static function field( $k, $max = 200, $multi = false ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by the dispatcher.
		$v = isset( $_POST[ $k ] ) ? wp_unslash( $_POST[ $k ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$v = is_scalar( $v ) ? (string) $v : '';
		$v = $multi ? sanitize_textarea_field( $v ) : sanitize_text_field( $v );
		return mb_substr( trim( $v ), 0, $max );
	}

	/**
	 * Where submission notices go.
	 *
	 * @return string
	 */
	private static function notify_to() {
		$e = sanitize_email( (string) mhp_opt( 'notify_email', '' ) );
		return $e ? $e : (string) get_option( 'admin_email' );
	}

	/**
	 * Employer circular.
	 */
	public static function employer() {
		$back = mhp_url( 'employer' );
		if ( '' !== self::field( 'website' ) ) { // Honeypot.
			wp_safe_redirect( add_query_arg( 'sent', 1, $back ) );
			exit;
		}
		if ( ! mhp_rate_ok( 'employer', 5, HOUR_IN_SECONDS ) ) {
			mhp_flash( 'অল্প সময়ে অনেক জমা হয়েছে। এক ঘণ্টা পরে আবার চেষ্টা করুন।', 'error' );
			wp_safe_redirect( $back );
			exit;
		}
		$org     = self::field( 'company', 150 );
		$post    = self::field( 'post_title', 150 );
		$dl      = self::field( 'deadline', 10 );
		$phone   = self::field( 'phone', 20 );
		$email   = sanitize_email( self::field( 'email', 120 ) );
		$name    = self::field( 'contact_name', 100 );
		$pkg     = self::field( 'package', 60 );
		$details = self::field( 'details', 4000, true );
		$digits  = meeha_prep_en_digits( preg_replace( '/[\s\-]/', '', $phone ) );
		$errors  = array();
		if ( '' === $org || '' === $post ) {
			$errors[] = 'প্রতিষ্ঠান ও পদের নাম দিন।';
		}
		if ( ! mhp_is_date( $dl ) || mhp_days_left( $dl ) < 0 ) {
			$errors[] = 'আবেদনের শেষ তারিখ আজ বা পরের কোনো তারিখ দিন।';
		}
		if ( ! preg_match( '/^(\+?880|0)1[3-9]\d{8}$/', $digits ) ) {
			$errors[] = 'সঠিক মোবাইল নম্বর দিন।';
		}
		if ( $errors ) {
			mhp_flash( implode( ' ', $errors ), 'error' );
			wp_safe_redirect( $back );
			exit;
		}
		$job_id = wp_insert_post(
			array(
				'post_type'    => 'mh_job',
				'post_status'  => 'pending',
				'post_title'   => $post,
				'post_content' => $details,
				'post_author'  => get_current_user_id(),
			),
			true
		);
		if ( is_wp_error( $job_id ) ) {
			mhp_flash( 'জমা দেওয়া যায়নি। আবার চেষ্টা করুন।', 'error' );
			wp_safe_redirect( $back );
			exit;
		}
		update_post_meta( $job_id, '_mhp_org', $org );
		update_post_meta( $job_id, '_mhp_deadline', $dl );
		update_post_meta( $job_id, '_mhp_source', 'নিয়োগদাতার জমা' );
		update_post_meta( $job_id, '_mhp_contact_name', $name );
		update_post_meta( $job_id, '_mhp_contact_phone', $digits );
		update_post_meta( $job_id, '_mhp_contact_email', $email );
		update_post_meta( $job_id, '_mhp_package', $pkg );
		update_post_meta( $job_id, '_mhp_submitted_ip', wp_hash( mhp_ip() ) );
		$types = get_term_by( 'slug', 'private', 'mh_job_type' );
		if ( $types ) {
			wp_set_object_terms( $job_id, array( (int) $types->term_id ), 'mh_job_type' );
		}
		$file_note = '';
		if ( mhp_opt( 'employer_upload', 1 ) && ! empty( $_FILES['circular']['name'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$att = self::upload_circular( $job_id );
			if ( is_wp_error( $att ) ) {
				$file_note = ' (সার্কুলার ফাইল নেওয়া যায়নি: ' . $att->get_error_message() . ')';
			} else {
				update_post_meta( $job_id, '_mhp_circular_file', $att );
			}
		}
		wp_mail(
			self::notify_to(),
			'[মিহা] নতুন নিয়োগ বিজ্ঞপ্তি জমা: ' . $org,
			"প্রতিষ্ঠান: {$org}\nপদ: {$post}\nশেষ তারিখ: {$dl}\nযোগাযোগ: {$name} {$digits} {$email}\nপ্যাকেজ: {$pkg}\n\nযাচাই করুন: " . admin_url( 'post.php?post=' . (int) $job_id . '&action=edit' )
		);
		mhp_flash( 'বিজ্ঞপ্তি জমা হয়েছে। যাচাইয়ের পর প্রকাশ হবে; আমাদের বিজ্ঞাপন বিভাগ যোগাযোগ করবে।' . $file_note );
		wp_safe_redirect( add_query_arg( 'sent', 1, $back ) );
		exit;
	}

	/**
	 * Keep an uploaded circular (jpg/png/webp/pdf ≤ 5 MB) in the private folder, not in the public media library:
	 * anyone can submit this form, so nothing they send is reachable on the site until an editor has checked it
	 * (the editor opens it from the job's edit screen and, if fine, adds it to the job as a normal media file).
	 *
	 * @param int $job_id Job.
	 * @return string|WP_Error Stored file name.
	 */
	private static function upload_circular( $job_id ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- checked below.
		$file = $_FILES['circular'];
		if ( ! empty( $file['error'] ) || empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'up', 'আপলোডে সমস্যা' );
		}
		if ( (int) $file['size'] > 5 * MB_IN_BYTES ) {
			return new WP_Error( 'up', 'ফাইল ৫ এমবির বেশি' );
		}
		$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], self::CIRCULAR_MIMES );
		if ( empty( $check['ext'] ) || empty( $check['type'] ) ) {
			return new WP_Error( 'up', 'শুধু JPG, PNG, WEBP বা PDF' );
		}
		$name = 'circular-' . (int) $job_id . '-' . wp_generate_password( 20, false, false ) . '.' . $check['ext'];
		if ( ! @move_uploaded_file( $file['tmp_name'], MHP_Front::private_dir() . '/' . $name ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new WP_Error( 'up', 'ফাইল রাখা যায়নি' );
		}
		return $name;
	}

	/**
	 * Allowed circular types.
	 */
	const CIRCULAR_MIMES = array(
		'jpg|jpeg' => 'image/jpeg',
		'png'      => 'image/png',
		'webp'     => 'image/webp',
		'pdf'      => 'application/pdf',
	);

	/**
	 * Editors: open a submitted circular from the private folder (admin-post.php?action=mhp_circular&job=ID).
	 */
	public static function view_circular() {
		$job = isset( $_GET['job'] ) ? absint( $_GET['job'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked next.
		check_admin_referer( 'mhp_circular_' . $job );
		if ( ! $job || 'mh_job' !== get_post_type( $job ) || ! current_user_can( 'edit_post', $job ) ) {
			wp_die( 'অনুমতি নেই।', 403 );
		}
		$path = MHP_Front::private_path( (string) get_post_meta( $job, '_mhp_circular_file', true ) );
		$type = $path ? wp_check_filetype( $path, self::CIRCULAR_MIMES ) : array();
		if ( ! $path || empty( $type['type'] ) ) {
			wp_die( 'ফাইলটি পাওয়া যায়নি।', 404 );
		}
		nocache_headers();
		status_header( 200 );
		header( 'Content-Type: ' . $type['type'] );
		header( 'Content-Disposition: attachment; filename="' . basename( $path ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/**
	 * Link to the submitted circular in the job's Publish box.
	 *
	 * @param WP_Post $post Post.
	 */
	public static function circular_link( $post ) {
		if ( 'mh_job' !== $post->post_type || ! get_post_meta( $post->ID, '_mhp_circular_file', true ) ) {
			return;
		}
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=mhp_circular&job=' . (int) $post->ID ), 'mhp_circular_' . (int) $post->ID );
		echo '<div class="misc-pub-section">নিয়োগদাতার পাঠানো সার্কুলার: <a href="' . esc_url( $url ) . '">ডাউনলোড করে দেখুন</a><br><span class="description">ঠিক থাকলে "মূল সার্কুলার" ঘরে মিডিয়া হিসেবে যোগ করুন।</span></div>';
	}

	/**
	 * Success story (logged in).
	 */
	public static function story() {
		$back = mhp_url( 'me', '', array( 'tab' => 'story' ) );
		if ( '' !== self::field( 'website' ) ) {
			wp_safe_redirect( $back );
			exit;
		}
		if ( ! mhp_rate_ok( 'story', 3, DAY_IN_SECONDS ) ) {
			mhp_flash( 'আজ আর গল্প পাঠানো যাবে না। কাল আবার চেষ্টা করুন।', 'error' );
			wp_safe_redirect( $back );
			exit;
		}
		$name = self::field( 'story_name', 100 );
		$exam = self::field( 'exam', 150 );
		$body = self::field( 'body', 5000, true );
		if ( '' === $name || mb_strlen( $body ) < 80 ) {
			mhp_flash( 'নাম দিন এবং গল্পটি অন্তত কয়েক লাইনে লিখুন।', 'error' );
			wp_safe_redirect( $back );
			exit;
		}
		$cat = function_exists( 'meeha_campus_cat' ) ? meeha_campus_cat( 'success' ) : 0;
		$pid = wp_insert_post(
			array(
				'post_type'     => 'post',
				'post_status'   => 'pending',
				'post_title'    => $exam ? $name . ': ' . $exam : $name . '-এর সাফল্যের গল্প',
				'post_content'  => wpautop( esc_html( $body ) ),
				'post_author'   => get_current_user_id(),
				'post_category' => $cat ? array( $cat ) : array(),
			),
			true
		);
		if ( is_wp_error( $pid ) ) {
			mhp_flash( 'পাঠানো যায়নি। আবার চেষ্টা করুন।', 'error' );
			wp_safe_redirect( $back );
			exit;
		}
		update_post_meta( $pid, '_mhp_reader_story', 1 );
		wp_mail( self::notify_to(), '[মিহা] পাঠকের সাফল্যের গল্প জমা পড়েছে', "নাম: {$name}\nপরীক্ষা: {$exam}\n\nসম্পাদনা: " . admin_url( 'post.php?post=' . (int) $pid . '&action=edit' ) );
		mhp_flash( 'ধন্যবাদ! সম্পাদক যাচাই করে বাছাই করা গল্প প্রকাশ করবেন।' );
		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * Job-alert preferences (logged in).
	 */
	public static function alerts() {
		$uid  = get_current_user_id();
		$dist = self::field( 'district', 40 );
		if ( '' !== $dist && ! in_array( $dist, mhp_districts(), true ) ) {
			$dist = '';
		}
		update_user_meta( $uid, 'mhp_district', $dist );
		$valid = get_terms(
			array(
				'taxonomy'   => 'mh_job_type',
				'hide_empty' => false,
				'fields'     => 'slugs',
			)
		);
		$cats  = array();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by the dispatcher.
		foreach ( isset( $_POST['types'] ) ? (array) wp_unslash( $_POST['types'] ) : array() as $c ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$c = sanitize_title( (string) $c );
			if ( in_array( $c, (array) $valid, true ) ) {
				$cats[] = $c;
			}
		}
		update_user_meta( $uid, 'mhp_alert_types', array_values( array_unique( $cats ) ) );
		update_user_meta( $uid, 'mhp_remind', empty( $_POST['remind'] ) ? 0 : 1 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$msg = 'অ্যালার্টের পছন্দ সংরক্ষিত হয়েছে।';
		if ( ! empty( $_POST['email_list'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$ok  = self::mailpoet_subscribe( wp_get_current_user() );
			$msg = $ok ? 'সংরক্ষিত হয়েছে। নতুন বিজ্ঞপ্তির ইমেইল তালিকায় আপনি যুক্ত হয়েছেন।' : 'সংরক্ষিত হয়েছে, তবে ইমেইল তালিকায় যুক্ত করা যায়নি (তালিকা চালু নেই বা আগে থেকেই যুক্ত)।';
			if ( $ok ) {
				update_user_meta( $uid, 'mhp_email_list', 1 );
			}
		}
		mhp_flash( $msg );
		wp_safe_redirect( mhp_url( 'me', '', array( 'tab' => 'alerts' ) ) );
		exit;
	}

	/**
	 * Add the user to the configured MailPoet list.
	 *
	 * @param WP_User $user User.
	 * @return bool
	 */
	public static function mailpoet_subscribe( $user ) {
		$list = (int) mhp_opt( 'mailpoet_list', 0 );
		if ( ! $list || ! class_exists( '\MailPoet\API\API' ) || ! is_email( $user->user_email ) ) {
			return false;
		}
		try {
			$mp = \MailPoet\API\API::MP( 'v1' );
			try {
				$mp->addSubscriber(
					array(
						'email'      => $user->user_email,
						'first_name' => (string) $user->first_name ? (string) $user->first_name : (string) $user->display_name,
					),
					array( $list ),
					array( 'send_confirmation_email' => false )
				);
			} catch ( \Exception $e ) {
				$mp->subscribeToList( $user->user_email, $list, array( 'send_confirmation_email' => false ) );
			}
			return true;
		} catch ( \Exception $e ) {
			return false;
		}
	}
}
