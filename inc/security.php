<?php
/**
 * Meeha News — লগইন নিরাপত্তা:
 *  ১) ভুল পাসওয়ার্ডে লগইন লক (ডিফল্ট: ৫ বার ভুল = ১৫ মিনিট)
 *  ২) এডমিন/সংবাদদাতার জন্য ইমেইল বা SMS ওটিপি (2FA)
 *  ৩) SMS গেটওয়ে (যেকোনো HTTP API-ভিত্তিক প্রোভাইডারের জন্য কনফিগারযোগ্য)
 *  ৪) Google / Facebook দিয়ে লগইন-রেজিস্ট্রেশন (OAuth 2.0, authorization code flow)
 *
 * জরুরি অবস্থায় (ওটিপি/SMS কাজ না করলে এডমিন লক হয়ে গেলে): wp-config.php-তে
 *   define( 'MEEHA_DISABLE_2FA', true );
 * লাইনটা যোগ করলে ওটিপি সাময়িকভাবে বন্ধ হয়ে যাবে।
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* =====================================================================
   সেটিংস
===================================================================== */
function meeha_get_security_settings() {
	$s = get_option( 'meeha_security_settings', array() );
	return array(
		'lock_enabled'      => ! isset( $s['lock_enabled'] ) || $s['lock_enabled'],
		'lock_attempts'     => ! empty( $s['lock_attempts'] ) ? max( 3, min( 20, (int) $s['lock_attempts'] ) ) : 5,
		'lock_minutes'      => ! empty( $s['lock_minutes'] ) ? max( 1, min( 1440, (int) $s['lock_minutes'] ) ) : 15,
		'trust_proxy_ip'    => ! empty( $s['trust_proxy_ip'] ),

		'otp_enabled'       => ! empty( $s['otp_enabled'] ), // ডিফল্ট বন্ধ - এডমিন নিজে চালু করবেন
		'otp_admin'         => ! isset( $s['otp_admin'] ) || $s['otp_admin'],
		'otp_correspondent' => ! isset( $s['otp_correspondent'] ) || $s['otp_correspondent'],
		'otp_ttl_minutes'   => ! empty( $s['otp_ttl_minutes'] ) ? max( 1, min( 30, (int) $s['otp_ttl_minutes'] ) ) : 5,
		'otp_email_enabled' => ! isset( $s['otp_email_enabled'] ) || $s['otp_email_enabled'],

		'sms_enabled'       => ! empty( $s['sms_enabled'] ),
		'sms_method'        => ( isset( $s['sms_method'] ) && 'POST' === $s['sms_method'] ) ? 'POST' : 'GET',
		'sms_url'           => isset( $s['sms_url'] ) ? esc_url_raw( $s['sms_url'] ) : '',
		'sms_body'          => isset( $s['sms_body'] ) ? (string) $s['sms_body'] : '',
		'sms_body_type'     => ( isset( $s['sms_body_type'] ) && 'json' === $s['sms_body_type'] ) ? 'json' : 'form',
		'sms_auth_header'   => isset( $s['sms_auth_header'] ) ? sanitize_text_field( $s['sms_auth_header'] ) : '',
		'sms_api_key'       => isset( $s['sms_api_key'] ) ? (string) $s['sms_api_key'] : '',
		'sms_api_secret'    => isset( $s['sms_api_secret'] ) ? (string) $s['sms_api_secret'] : '',
		'sms_sender'        => isset( $s['sms_sender'] ) ? sanitize_text_field( $s['sms_sender'] ) : '',
		'sms_number_format' => ( isset( $s['sms_number_format'] ) && in_array( $s['sms_number_format'], array( 'local', 'intl', 'plus' ), true ) ) ? $s['sms_number_format'] : 'intl',
		'sms_success_text'  => isset( $s['sms_success_text'] ) ? (string) $s['sms_success_text'] : '',
		'sms_template'      => ! empty( $s['sms_template'] ) ? (string) $s['sms_template'] : 'আপনার Meeha News লগইন ওটিপি: {otp}। {minutes} মিনিট পর্যন্ত বৈধ। কাউকে জানাবেন না।',
	);
}

function meeha_2fa_globally_disabled() {
	return defined( 'MEEHA_DISABLE_2FA' ) && MEEHA_DISABLE_2FA;
}

/* =====================================================================
   ১) লগইন অ্যাটেম্পট লক
===================================================================== */
function meeha_client_ip() {
	$s  = meeha_get_security_settings();
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	if ( $s['trust_proxy_ip'] ) {
		if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
		} elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$parts = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
			$ip    = trim( $parts[0] );
		}
	}
	return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
}

// একই আইপি + একই ইউজারনেমের জুটির জন্য কাউন্টার (একজনের ভুলে পুরো আইপি লক হয় না,
// যেন অফিস/মোবাইল নেটওয়ার্কের শেয়ার্ড আইপিতে সবাই আটকে না যায়)
function meeha_lock_key_pair( $login ) {
	return 'meeha_lf_' . md5( meeha_client_ip() . '|' . strtolower( (string) $login ) );
}

// একই আইপি থেকে যেকোনো ইউজারনেমে অনেক বেশি ব্যর্থ চেষ্টা (ক্রেডেনশিয়াল-স্টাফিং ঠেকাতে) - ৪ গুণ সীমা
function meeha_lock_key_ip() {
	return 'meeha_lfi_' . md5( meeha_client_ip() );
}

function meeha_login_lock_remaining( $login ) {
	$s = meeha_get_security_settings();
	if ( ! $s['lock_enabled'] ) {
		return 0;
	}
	$pair = get_transient( meeha_lock_key_pair( $login ) );
	if ( is_array( $pair ) && (int) $pair['count'] >= $s['lock_attempts'] ) {
		return max( 0, (int) $pair['until'] - time() );
	}
	$ip = get_transient( meeha_lock_key_ip() );
	if ( is_array( $ip ) && (int) $ip['count'] >= $s['lock_attempts'] * 4 ) {
		return max( 0, (int) $ip['until'] - time() );
	}
	return 0;
}

function meeha_lock_message( $seconds ) {
	$minutes = max( 1, (int) ceil( $seconds / 60 ) );
	return 'অনেকবার ভুল চেষ্টা হয়েছে। নিরাপত্তার জন্য লগইন সাময়িকভাবে বন্ধ আছে — ' . $minutes . ' মিনিট পর আবার চেষ্টা করুন।';
}

function meeha_bump_lock_counter( $key ) {
	$s   = meeha_get_security_settings();
	$ttl = $s['lock_minutes'] * MINUTE_IN_SECONDS;
	$cur = get_transient( $key );
	$cnt = is_array( $cur ) ? (int) $cur['count'] + 1 : 1;
	set_transient( $key, array( 'count' => $cnt, 'until' => time() + $ttl ), $ttl );
}

// ব্যর্থ লগইন গণনা
function meeha_on_login_failed( $username, $error = null ) {
	$s = meeha_get_security_settings();
	if ( ! $s['lock_enabled'] || '' === (string) $username ) {
		return;
	}
	$code = ( $error instanceof WP_Error ) ? $error->get_error_code() : 'incorrect_password';
	if ( ! in_array( $code, array( 'incorrect_password', 'invalid_username', 'invalid_email', 'meeha_otp_failed' ), true ) ) {
		return; // লক/ওটিপি-প্রয়োজন ইত্যাদি এরর গুনবো না
	}
	meeha_bump_lock_counter( meeha_lock_key_pair( $username ) );
	meeha_bump_lock_counter( meeha_lock_key_ip() );
}
add_action( 'wp_login_failed', 'meeha_on_login_failed', 10, 2 );

// সফল লগইনে জুটির কাউন্টার মুছে ফেলা
function meeha_on_login_success( $user_login ) {
	delete_transient( meeha_lock_key_pair( $user_login ) );
}
add_action( 'wp_login', 'meeha_on_login_success', 10, 1 );

// লক থাকলে পাসওয়ার্ড ঠিক থাকলেও ঢুকতে দেওয়া হবে না (core-এর পাসওয়ার্ড-চেকের পরে, তাই priority 30)
function meeha_authenticate_lock_gate( $user, $username ) {
	$remaining = meeha_login_lock_remaining( $username );
	if ( $remaining > 0 ) {
		return new WP_Error( 'meeha_locked', meeha_lock_message( $remaining ) );
	}
	return $user;
}
add_filter( 'authenticate', 'meeha_authenticate_lock_gate', 30, 2 );

/* =====================================================================
   ২) ওটিপি (2FA)
===================================================================== */
function meeha_2fa_required_for_user( $user ) {
	if ( ! ( $user instanceof WP_User ) || meeha_2fa_globally_disabled() ) {
		return false;
	}
	$s = meeha_get_security_settings();
	if ( ! $s['otp_enabled'] ) {
		return false;
	}
	$roles = (array) $user->roles;
	if ( $s['otp_admin'] && in_array( 'administrator', $roles, true ) ) {
		return true;
	}
	if ( $s['otp_correspondent'] && in_array( 'correspondent', $roles, true ) ) {
		return true;
	}
	return false;
}

function meeha_sms_is_ready() {
	$s = meeha_get_security_settings();
	return $s['sms_enabled'] && '' !== $s['sms_url'];
}

// ব্যবহারকারী কোন মাধ্যম বেছেছেন (email/sms) - না থাকলে বা সম্ভব না হলে ইমেইলে ফলব্যাক
function meeha_2fa_method_for_user( $user ) {
	$s      = meeha_get_security_settings();
	$method = get_user_meta( $user->ID, 'meeha_2fa_method', true );
	$phone  = get_user_meta( $user->ID, 'meeha_phone', true );
	if ( 'sms' === $method && meeha_sms_is_ready() && $phone ) {
		return 'sms';
	}
	if ( 'email' === $method && $s['otp_email_enabled'] ) {
		return 'email';
	}
	if ( $s['otp_email_enabled'] ) {
		return 'email';
	}
	return ( meeha_sms_is_ready() && $phone ) ? 'sms' : 'email';
}

function meeha_mask_destination( $user, $method ) {
	if ( 'sms' === $method ) {
		$phone = (string) get_user_meta( $user->ID, 'meeha_phone', true );
		$len   = strlen( $phone );
		return $len > 4 ? str_repeat( '*', $len - 3 ) . substr( $phone, -3 ) : '***';
	}
	$parts = explode( '@', $user->user_email );
	$name  = $parts[0];
	return substr( $name, 0, 2 ) . str_repeat( '*', max( 1, strlen( $name ) - 2 ) ) . '@' . ( isset( $parts[1] ) ? $parts[1] : '' );
}

function meeha_otp_hash( $otp ) {
	return hash_hmac( 'sha256', (string) $otp, wp_salt( 'auth' ) );
}

// নতুন ওটিপি তৈরি করে পাঠায়; সফল হলে [token, method, masked] ফেরত দেয়, নাহলে WP_Error
function meeha_otp_start( $user, $existing_token = '' ) {
	$s = meeha_get_security_settings();

	// SMS/ইমেইল বোমা ঠেকাতে: প্রতি ইউজার ১৫ মিনিটে সর্বোচ্চ ৬টা ওটিপি
	$rate_key = 'meeha_otp_rate_' . $user->ID;
	$sent     = (int) get_transient( $rate_key );
	if ( $sent >= 6 ) {
		return new WP_Error( 'meeha_otp_rate', 'অনেকবার ওটিপি চাওয়া হয়েছে। ১৫ মিনিট পর আবার চেষ্টা করুন।' );
	}

	$method = meeha_2fa_method_for_user( $user );
	$otp    = (string) random_int( 100000, 999999 );
	$ttl    = $s['otp_ttl_minutes'] * MINUTE_IN_SECONDS;
	$token  = $existing_token ? $existing_token : wp_generate_password( 32, false );

	$sent_ok = false;
	if ( 'sms' === $method ) {
		$msg     = str_replace( array( '{otp}', '{minutes}' ), array( $otp, $s['otp_ttl_minutes'] ), $s['sms_template'] );
		$result  = meeha_send_sms( get_user_meta( $user->ID, 'meeha_phone', true ), $msg );
		$sent_ok = ! is_wp_error( $result );
		if ( ! $sent_ok && $s['otp_email_enabled'] ) {
			$method  = 'email'; // SMS ব্যর্থ হলে ইমেইলে ফলব্যাক
			$sent_ok = null;
		}
	}
	if ( 'email' === $method && ! $sent_ok ) {
		$sent_ok = wp_mail(
			$user->user_email,
			'আপনার লগইন ওটিপি — ' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			"আপনার লগইন ওটিপি: {$otp}\n\nএটি {$s['otp_ttl_minutes']} মিনিট পর্যন্ত বৈধ। আপনি লগইনের চেষ্টা না করে থাকলে এই ইমেইল উপেক্ষা করুন এবং পাসওয়ার্ড বদলে নিন।"
		);
	}
	if ( ! $sent_ok ) {
		return new WP_Error( 'meeha_otp_send_failed', 'ওটিপি পাঠানো যায়নি। একটু পরে আবার চেষ্টা করুন বা সাইট এডমিনকে জানান।' );
	}

	$prev = $existing_token ? get_transient( 'meeha_otp_' . $token ) : false;
	set_transient(
		'meeha_otp_' . $token,
		array(
			'user_id'  => $user->ID,
			'hash'     => meeha_otp_hash( $otp ),
			'attempts' => 0,
			'resends'  => is_array( $prev ) ? (int) $prev['resends'] + 1 : 0,
			'method'   => $method,
		),
		$ttl
	);
	set_transient( $rate_key, $sent + 1, 15 * MINUTE_IN_SECONDS );

	return array(
		'token'  => $token,
		'method' => $method,
		'masked' => meeha_mask_destination( $user, $method ),
	);
}

// wp-login.php-তে সরাসরি পাসওয়ার্ড দিয়ে ঢুকে ওটিপি এড়ানো ঠেকায়
function meeha_authenticate_otp_gate( $user ) {
	if ( ! ( $user instanceof WP_User ) || ! meeha_2fa_required_for_user( $user ) ) {
		return $user;
	}
	if ( ! empty( $GLOBALS['meeha_skip_2fa_gate'] ) || defined( 'XMLRPC_REQUEST' ) || defined( 'REST_REQUEST' ) ) {
		return $user;
	}
	return new WP_Error(
		'meeha_otp_required',
		'এই একাউন্টে ওটিপি (2FA) চালু আছে। অনুগ্রহ করে সাইটের নিজস্ব লগইন পেজ থেকে লগইন করুন: ' . meeha_get_account_page_url( 'login' )
	);
}
add_filter( 'authenticate', 'meeha_authenticate_otp_gate', 50, 1 );

function meeha_finish_login( $user ) {
	wp_set_current_user( $user->ID );
	wp_set_auth_cookie( $user->ID, true, is_ssl() );
	do_action( 'wp_login', $user->user_login, $user );
}

// AJAX ধাপ ১: ইউজারনেম+পাসওয়ার্ড (আগের meeha_handle_ajax_login-এর বদলে)
function meeha_handle_ajax_login() {
	check_ajax_referer( 'meeha_account_nonce', 'nonce' );
	$login = isset( $_POST['username'] ) ? sanitize_text_field( wp_unslash( $_POST['username'] ) ) : '';
	$pass  = isset( $_POST['password'] ) ? $_POST['password'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

	$remaining = meeha_login_lock_remaining( $login );
	if ( $remaining > 0 ) {
		wp_send_json_error( array( 'message' => meeha_lock_message( $remaining ) ) );
	}

	$GLOBALS['meeha_skip_2fa_gate'] = true; // ওটিপি ধাপ আমরা নিজেরাই সামলাচ্ছি
	$user = wp_authenticate( $login, $pass );

	if ( is_wp_error( $user ) ) {
		if ( 'meeha_locked' === $user->get_error_code() ) {
			wp_send_json_error( array( 'message' => $user->get_error_message() ) );
		}
		wp_send_json_error( array( 'message' => 'ইউজারনেম বা পাসওয়ার্ড ভুল।' ) );
	}

	if ( meeha_2fa_required_for_user( $user ) ) {
		$started = meeha_otp_start( $user );
		if ( is_wp_error( $started ) ) {
			wp_send_json_error( array( 'message' => $started->get_error_message() ) );
		}
		wp_send_json_success( array(
			'otp_required' => true,
			'token'        => $started['token'],
			'message'      => ( 'sms' === $started['method'] ? 'আপনার মোবাইলে ' : 'আপনার ইমেইলে ' ) . $started['masked'] . ' ঠিকানায় ৬ সংখ্যার ওটিপি পাঠানো হয়েছে।',
		) );
	}

	meeha_finish_login( $user );
	wp_send_json_success( array( 'message' => 'লগইন সফল হয়েছে।', 'redirect' => home_url( '/' ) ) );
}
add_action( 'wp_ajax_nopriv_meeha_ajax_login', 'meeha_handle_ajax_login' );

// AJAX ধাপ ২: ওটিপি যাচাই
function meeha_handle_ajax_login_otp() {
	check_ajax_referer( 'meeha_account_nonce', 'nonce' );
	$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
	$code  = isset( $_POST['code'] ) ? preg_replace( '/\D/', '', wp_unslash( $_POST['code'] ) ) : '';
	$data  = $token ? get_transient( 'meeha_otp_' . $token ) : false;

	if ( ! is_array( $data ) ) {
		wp_send_json_error( array( 'message' => 'ওটিপির মেয়াদ শেষ হয়েছে। আবার লগইন করুন।', 'restart' => true ) );
	}
	$user = get_userdata( (int) $data['user_id'] );
	if ( ! $user ) {
		wp_send_json_error( array( 'message' => 'সমস্যা হয়েছে। আবার লগইন করুন।', 'restart' => true ) );
	}
	$remaining = meeha_login_lock_remaining( $user->user_login );
	if ( $remaining > 0 ) {
		delete_transient( 'meeha_otp_' . $token );
		wp_send_json_error( array( 'message' => meeha_lock_message( $remaining ), 'restart' => true ) );
	}

	if ( ! hash_equals( $data['hash'], meeha_otp_hash( $code ) ) ) {
		$data['attempts'] = (int) $data['attempts'] + 1;
		do_action( 'wp_login_failed', $user->user_login, new WP_Error( 'meeha_otp_failed', 'ওটিপি ভুল' ) );
		if ( $data['attempts'] >= 5 ) {
			delete_transient( 'meeha_otp_' . $token );
			wp_send_json_error( array( 'message' => 'অনেকবার ভুল ওটিপি দেওয়া হয়েছে। আবার লগইন করুন।', 'restart' => true ) );
		}
		$s = meeha_get_security_settings();
		set_transient( 'meeha_otp_' . $token, $data, $s['otp_ttl_minutes'] * MINUTE_IN_SECONDS );
		wp_send_json_error( array( 'message' => 'ওটিপি সঠিক নয়। আবার দিন।' ) );
	}

	delete_transient( 'meeha_otp_' . $token );
	meeha_finish_login( $user );
	wp_send_json_success( array( 'message' => 'লগইন সফল হয়েছে।', 'redirect' => home_url( '/' ) ) );
}
add_action( 'wp_ajax_nopriv_meeha_ajax_login_otp', 'meeha_handle_ajax_login_otp' );

// AJAX: ওটিপি আবার পাঠানো (টোকেন প্রতি সর্বোচ্চ ৩ বার)
function meeha_handle_ajax_login_otp_resend() {
	check_ajax_referer( 'meeha_account_nonce', 'nonce' );
	$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
	$data  = $token ? get_transient( 'meeha_otp_' . $token ) : false;
	if ( ! is_array( $data ) ) {
		wp_send_json_error( array( 'message' => 'মেয়াদ শেষ হয়েছে। আবার লগইন করুন।', 'restart' => true ) );
	}
	if ( (int) $data['resends'] >= 3 ) {
		wp_send_json_error( array( 'message' => 'আর ওটিপি পাঠানো যাবে না। আবার লগইন করুন।', 'restart' => true ) );
	}
	$user = get_userdata( (int) $data['user_id'] );
	if ( ! $user ) {
		wp_send_json_error( array( 'message' => 'সমস্যা হয়েছে। আবার লগইন করুন।', 'restart' => true ) );
	}
	$started = meeha_otp_start( $user, $token );
	if ( is_wp_error( $started ) ) {
		wp_send_json_error( array( 'message' => $started->get_error_message() ) );
	}
	wp_send_json_success( array( 'message' => 'নতুন ওটিপি পাঠানো হয়েছে।', 'token' => $started['token'] ) );
}
add_action( 'wp_ajax_nopriv_meeha_ajax_login_otp_resend', 'meeha_handle_ajax_login_otp_resend' );

/* ---------- ব্যবহারকারীর ওটিপি-মাধ্যম বাছাই (wp-admin প্রোফাইল) ---------- */
function meeha_2fa_profile_fields( $user ) {
	$s = meeha_get_security_settings();
	if ( ! $s['otp_enabled'] || ! meeha_2fa_required_for_user( $user ) ) {
		return;
	}
	$method = get_user_meta( $user->ID, 'meeha_2fa_method', true );
	?>
	<h2>লগইন ওটিপি (2FA)</h2>
	<table class="form-table">
		<tr>
			<th><label for="meeha_2fa_method">ওটিপি কোন মাধ্যমে পেতে চান</label></th>
			<td>
				<select name="meeha_2fa_method" id="meeha_2fa_method">
					<option value="email" <?php selected( $method, 'email' ); ?>>ইমেইল</option>
					<option value="sms" <?php selected( $method, 'sms' ); ?> <?php disabled( ! meeha_sms_is_ready() ); ?>>মোবাইল SMS<?php echo meeha_sms_is_ready() ? '' : ' (এখনো চালু হয়নি)'; ?></option>
				</select>
				<p class="description">SMS বাছলে আপনার প্রোফাইলে মোবাইল নাম্বার দেওয়া থাকতে হবে। SMS না গেলে স্বয়ংক্রিয়ভাবে ইমেইলে যাবে।</p>
			</td>
		</tr>
	</table>
	<?php
}
add_action( 'show_user_profile', 'meeha_2fa_profile_fields' );
add_action( 'edit_user_profile', 'meeha_2fa_profile_fields' );

function meeha_2fa_profile_save( $user_id ) {
	if ( ! current_user_can( 'edit_user', $user_id ) || ! isset( $_POST['meeha_2fa_method'] ) ) {
		return;
	}
	$method = sanitize_key( wp_unslash( $_POST['meeha_2fa_method'] ) );
	if ( in_array( $method, array( 'email', 'sms' ), true ) ) {
		update_user_meta( $user_id, 'meeha_2fa_method', $method );
	}
}
add_action( 'personal_options_update', 'meeha_2fa_profile_save' );
add_action( 'edit_user_profile_update', 'meeha_2fa_profile_save' );

/* =====================================================================
   ৩) SMS গেটওয়ে (জেনেরিক HTTP API)
===================================================================== */
function meeha_format_sms_number( $number, $format ) {
	$digits = preg_replace( '/\D/', '', (string) $number );
	if ( 0 === strpos( $digits, '880' ) ) {
		$local = '0' . substr( $digits, 3 );
	} elseif ( 0 === strpos( $digits, '0' ) ) {
		$local = $digits;
	} else {
		$local = '0' . $digits;
	}
	if ( 'local' === $format ) {
		return $local;
	}
	$intl = '88' . $local; // 01XXXXXXXXX -> 8801XXXXXXXXX
	return 'plus' === $format ? '+' . $intl : $intl;
}

function meeha_send_sms( $number, $message ) {
	$s = meeha_get_security_settings();
	if ( ! meeha_sms_is_ready() ) {
		return new WP_Error( 'meeha_sms_off', 'SMS গেটওয়ে চালু নেই।' );
	}
	$number = meeha_format_sms_number( $number, $s['sms_number_format'] );
	if ( strlen( preg_replace( '/\D/', '', $number ) ) < 10 ) {
		return new WP_Error( 'meeha_sms_number', 'মোবাইল নাম্বার সঠিক নয়।' );
	}

	$vars = array(
		'{number}'     => $number,
		'{message}'    => $message,
		'{sender}'     => $s['sms_sender'],
		'{api_key}'    => $s['sms_api_key'],
		'{api_secret}' => $s['sms_api_secret'],
	);

	$encode_url  = function ( $tpl ) use ( $vars ) {
		$out = $tpl;
		foreach ( $vars as $k => $v ) {
			$out = str_replace( $k, rawurlencode( $v ), $out );
		}
		return $out;
	};
	$encode_json = function ( $tpl ) use ( $vars ) {
		$out = $tpl;
		foreach ( $vars as $k => $v ) {
			$out = str_replace( $k, trim( wp_json_encode( (string) $v ), '"' ), $out );
		}
		return $out;
	};

	$headers = array();
	if ( '' !== $s['sms_auth_header'] ) {
		$hv = $s['sms_auth_header'];
		foreach ( $vars as $k => $v ) {
			$hv = str_replace( $k, $v, $hv );
		}
		$headers['Authorization'] = $hv;
	}

	$url  = $encode_url( $s['sms_url'] );
	$args = array( 'timeout' => 15, 'headers' => $headers );

	if ( 'POST' === $s['sms_method'] ) {
		if ( 'json' === $s['sms_body_type'] ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = $encode_json( $s['sms_body'] );
		} else {
			$args['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
			$args['body']                    = $encode_url( $s['sms_body'] );
		}
		$res = wp_remote_post( $url, $args );
	} else {
		$res = wp_remote_get( $url, $args );
	}

	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$code = (int) wp_remote_retrieve_response_code( $res );
	$body = (string) wp_remote_retrieve_body( $res );
	if ( $code < 200 || $code >= 300 ) {
		return new WP_Error( 'meeha_sms_http', 'SMS গেটওয়ে HTTP ' . $code . ' ফেরত দিয়েছে।' );
	}
	if ( '' !== $s['sms_success_text'] && false === stripos( $body, $s['sms_success_text'] ) ) {
		return new WP_Error( 'meeha_sms_response', 'SMS গেটওয়ের উত্তরে সফলতার চিহ্ন পাওয়া যায়নি: ' . wp_strip_all_tags( mb_substr( $body, 0, 200 ) ) );
	}
	return true;
}

/* =====================================================================
   ৪) Google / Facebook লগইন (OAuth 2.0)
===================================================================== */
function meeha_social_creds( $provider ) {
	$o = get_option( 'meeha_registration_settings', array() );
	if ( 'google' === $provider ) {
		return array(
			'id'     => isset( $o['google_client_id'] ) ? trim( $o['google_client_id'] ) : '',
			'secret' => isset( $o['google_client_secret'] ) ? trim( $o['google_client_secret'] ) : '',
		);
	}
	return array(
		'id'     => isset( $o['facebook_app_id'] ) ? trim( $o['facebook_app_id'] ) : '',
		'secret' => isset( $o['facebook_app_secret'] ) ? trim( $o['facebook_app_secret'] ) : '',
	);
}

function meeha_social_enabled( $provider ) {
	$c = meeha_social_creds( $provider );
	return '' !== $c['id'] && '' !== $c['secret'];
}

// Google Cloud Console / Facebook App-এ "Authorized redirect URI" হিসেবে এই URL বসাতে হবে
function meeha_social_callback_url( $provider ) {
	return add_query_arg( 'meeha_social_cb', $provider, home_url( '/' ) );
}

function meeha_social_start_url( $provider, $redirect = '' ) {
	$args = array( 'meeha_social' => $provider );
	if ( $redirect ) {
		$args['redirect_to'] = $redirect;
	}
	return add_query_arg( $args, home_url( '/' ) );
}

function meeha_social_error_redirect( $code ) {
	wp_safe_redirect( add_query_arg( 'meeha_social_error', $code, meeha_get_account_page_url( 'login' ) ) );
	exit;
}

function meeha_social_http_json( $method, $url, $args = array() ) {
	$args['timeout'] = 15;
	$res             = ( 'POST' === $method ) ? wp_remote_post( $url, $args ) : wp_remote_get( $url, $args );
	if ( is_wp_error( $res ) || (int) wp_remote_retrieve_response_code( $res ) >= 400 ) {
		return false;
	}
	$data = json_decode( wp_remote_retrieve_body( $res ), true );
	return is_array( $data ) ? $data : false;
}

function meeha_social_router() {
	// ধাপ ১: প্রোভাইডারের লগইন পেজে পাঠানো
	if ( isset( $_GET['meeha_social'] ) ) {
		$provider = sanitize_key( wp_unslash( $_GET['meeha_social'] ) );
		if ( ! in_array( $provider, array( 'google', 'facebook' ), true ) || ! meeha_social_enabled( $provider ) ) {
			meeha_social_error_redirect( 'not_configured' );
		}
		nocache_headers();
		$state = wp_generate_password( 32, false );
		$redir = isset( $_GET['redirect_to'] ) ? wp_validate_redirect( esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ), home_url( '/' ) ) : home_url( '/' );
		set_transient( 'meeha_oauth_' . $state, array( 'provider' => $provider, 'redirect' => $redir ), 10 * MINUTE_IN_SECONDS );
		// ব্রাউজারের সাথে state বেঁধে রাখা (login-CSRF ঠেকাতে)
		setcookie( 'meeha_oauth_state', $state, array(
			'expires'  => time() + 600,
			'path'     => COOKIEPATH ? COOKIEPATH : '/',
			'domain'   => ( defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ) ? COOKIE_DOMAIN : '',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		) );
		$c = meeha_social_creds( $provider );
		if ( 'google' === $provider ) {
			$url = add_query_arg(
				array(
					'client_id'     => $c['id'],
					'redirect_uri'  => meeha_social_callback_url( 'google' ),
					'response_type' => 'code',
					'scope'         => 'openid email profile',
					'state'         => $state,
					'prompt'        => 'select_account',
				),
				'https://accounts.google.com/o/oauth2/v2/auth'
			);
		} else {
			$url = add_query_arg(
				array(
					'client_id'    => $c['id'],
					'redirect_uri' => meeha_social_callback_url( 'facebook' ),
					'state'        => $state,
					'scope'        => 'email,public_profile',
				),
				'https://www.facebook.com/dialog/oauth'
			);
		}
		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect -- বাহ্যিক OAuth এন্ডপয়েন্ট
		exit;
	}

	// ধাপ ২: প্রোভাইডার থেকে ফেরা
	if ( isset( $_GET['meeha_social_cb'] ) ) {
		nocache_headers();
		$provider = sanitize_key( wp_unslash( $_GET['meeha_social_cb'] ) );
		if ( ! in_array( $provider, array( 'google', 'facebook' ), true ) || ! meeha_social_enabled( $provider ) ) {
			meeha_social_error_redirect( 'not_configured' );
		}
		if ( isset( $_GET['error'] ) || empty( $_GET['code'] ) || empty( $_GET['state'] ) ) {
			meeha_social_error_redirect( 'denied' );
		}
		$state  = sanitize_text_field( wp_unslash( $_GET['state'] ) );
		$cookie = isset( $_COOKIE['meeha_oauth_state'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['meeha_oauth_state'] ) ) : '';
		$saved  = get_transient( 'meeha_oauth_' . $state );
		delete_transient( 'meeha_oauth_' . $state );
		if ( ! is_array( $saved ) || $saved['provider'] !== $provider || '' === $cookie || ! hash_equals( $state, $cookie ) ) {
			meeha_social_error_redirect( 'state' );
		}

		$code = sanitize_text_field( wp_unslash( $_GET['code'] ) );
		$c    = meeha_social_creds( $provider );
		$cb   = meeha_social_callback_url( $provider );
		$prof = array( 'id' => '', 'email' => '', 'name' => '', 'verified' => false );

		if ( 'google' === $provider ) {
			$tok = meeha_social_http_json( 'POST', 'https://oauth2.googleapis.com/token', array(
				'body' => array(
					'code'          => $code,
					'client_id'     => $c['id'],
					'client_secret' => $c['secret'],
					'redirect_uri'  => $cb,
					'grant_type'    => 'authorization_code',
				),
			) );
			if ( ! $tok || empty( $tok['access_token'] ) ) {
				meeha_social_error_redirect( 'token' );
			}
			$info = meeha_social_http_json( 'GET', 'https://openidconnect.googleapis.com/v1/userinfo', array(
				'headers' => array( 'Authorization' => 'Bearer ' . $tok['access_token'] ),
			) );
			if ( ! $info || empty( $info['sub'] ) ) {
				meeha_social_error_redirect( 'profile' );
			}
			$prof = array(
				'id'       => (string) $info['sub'],
				'email'    => isset( $info['email'] ) ? sanitize_email( $info['email'] ) : '',
				'name'     => isset( $info['name'] ) ? sanitize_text_field( $info['name'] ) : '',
				'verified' => ! empty( $info['email_verified'] ),
			);
		} else {
			$tok = meeha_social_http_json( 'GET', add_query_arg(
				array(
					'client_id'     => $c['id'],
					'client_secret' => $c['secret'],
					'redirect_uri'  => $cb,
					'code'          => $code,
				),
				'https://graph.facebook.com/oauth/access_token'
			) );
			if ( ! $tok || empty( $tok['access_token'] ) ) {
				meeha_social_error_redirect( 'token' );
			}
			$info = meeha_social_http_json( 'GET', add_query_arg(
				array(
					'fields'       => 'id,name,email',
					'access_token' => $tok['access_token'],
				),
				'https://graph.facebook.com/me'
			) );
			if ( ! $info || empty( $info['id'] ) ) {
				meeha_social_error_redirect( 'profile' );
			}
			$prof = array(
				'id'       => (string) $info['id'],
				'email'    => isset( $info['email'] ) ? sanitize_email( $info['email'] ) : '',
				'name'     => isset( $info['name'] ) ? sanitize_text_field( $info['name'] ) : '',
				'verified' => ! empty( $info['email'] ), // ফেসবুক শুধু যাচাইকৃত ইমেইলই দেয়
			);
		}

		if ( ! is_email( $prof['email'] ) || ! $prof['verified'] ) {
			meeha_social_error_redirect( 'no_email' );
		}

		$user = get_user_by( 'email', $prof['email'] );
		if ( $user ) {
			// নিরাপত্তা: এডমিন/এডিটর-স্তরের একাউন্টে সোশ্যাল লগইন নয় (পাসওয়ার্ড+ওটিপি দিয়েই ঢুকতে হবে)
			if ( user_can( $user, 'manage_options' ) || user_can( $user, 'edit_others_posts' ) ) {
				meeha_social_error_redirect( 'privileged' );
			}
		} else {
			if ( function_exists( 'meeha_is_auto_registration_enabled' ) && ! meeha_is_auto_registration_enabled() ) {
				meeha_social_error_redirect( 'reg_closed' );
			}
			$base = sanitize_user( current( explode( '@', $prof['email'] ) ), true );
			$base = $base ? $base : 'user';
			$uname = $base;
			$n     = 1;
			while ( username_exists( $uname ) ) {
				$uname = $base . $n;
				$n++;
			}
			$uid = wp_insert_user( array(
				'user_login'   => $uname,
				'user_pass'    => wp_generate_password( 32, true, true ),
				'user_email'   => $prof['email'],
				'display_name' => $prof['name'] ? $prof['name'] : $uname,
				'role'         => 'subscriber',
			) );
			if ( is_wp_error( $uid ) ) {
				meeha_social_error_redirect( 'create' );
			}
			$user = get_userdata( $uid );
			if ( function_exists( 'meeha_add_to_mailpoet_list' ) ) {
				meeha_add_to_mailpoet_list( $prof['email'], $prof['name'] );
			}
		}

		update_user_meta( $user->ID, 'meeha_social_' . $provider . '_id', $prof['id'] );
		update_user_meta( $user->ID, 'meeha_email_verified', '1' );
		meeha_finish_login( $user );
		delete_transient( 'meeha_oauth_' . $state );
		wp_safe_redirect( $saved['redirect'] ? $saved['redirect'] : home_url( '/' ) );
		exit;
	}
}
add_action( 'init', 'meeha_social_router', 1 );

function meeha_social_error_text( $code ) {
	$map = array(
		'not_configured' => 'এই লগইন পদ্ধতি এখনো চালু করা হয়নি।',
		'denied'         => 'লগইন বাতিল হয়েছে বা অনুমতি দেওয়া হয়নি।',
		'state'          => 'নিরাপত্তা যাচাই ব্যর্থ হয়েছে। আবার চেষ্টা করুন।',
		'token'          => 'প্রোভাইডারের সাথে সংযোগ ব্যর্থ হয়েছে। একটু পরে আবার চেষ্টা করুন।',
		'profile'        => 'প্রোফাইল তথ্য পাওয়া যায়নি।',
		'no_email'       => 'আপনার একাউন্ট থেকে যাচাইকৃত ইমেইল পাওয়া যায়নি, তাই লগইন করা যায়নি।',
		'privileged'     => 'এই একাউন্টে শুধু পাসওয়ার্ড (ও ওটিপি) দিয়ে লগইন করা যাবে।',
		'reg_closed'     => 'এই মুহূর্তে নতুন রেজিস্ট্রেশন বন্ধ আছে।',
		'create'         => 'একাউন্ট তৈরি করা যায়নি।',
	);
	return isset( $map[ $code ] ) ? $map[ $code ] : 'লগইন করা যায়নি।';
}

// লগইন/রেজিস্ট্রেশন ফর্মে দেখানোর বাটন (শুধু কনফিগার করা প্রোভাইডারগুলো)
function meeha_social_icon_svg( $provider ) {
	if ( 'google' === $provider ) {
		return '<svg viewBox="0 0 48 48" width="18" height="18"><path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3c-1.6 4.7-6.1 8-11.3 8-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.1 8 3l5.7-5.7C34.6 6.1 29.6 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.7-.4-3.5z"/><path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.6 15.9 18.9 13 24 13c3.1 0 5.8 1.1 8 3l5.7-5.7C34.6 6.1 29.6 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/><path fill="#4CAF50" d="M24 44c5.5 0 10.4-1.9 14.3-5.1l-6.6-5.6C29.7 35.1 27 36 24 36c-5.2 0-9.6-3.3-11.3-8l-6.6 5.1C9.6 39.5 16.3 44 24 44z"/><path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.3-2.2 4.2-4.1 5.6l6.6 5.6C41.2 36.6 44 30.9 44 24c0-1.3-.1-2.7-.4-3.5z"/></svg>';
	}
	return '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M22 12c0-5.52-4.48-10-10-10S2 6.48 2 12c0 4.84 3.44 8.87 8 9.8v-6.93H7.9V12H10V9.8c0-2.07 1.23-3.21 3.11-3.21.9 0 1.84.16 1.84.16v2.02h-1.04c-1.02 0-1.34.64-1.34 1.29V12h2.28l-.36 2.87h-1.92V21.8c4.56-.93 8-4.96 8-9.8z"/></svg>';
}

function meeha_render_social_buttons() {
	$providers = array();
	if ( meeha_social_enabled( 'google' ) ) {
		$providers['google'] = 'Google দিয়ে চালিয়ে যান';
	}
	if ( meeha_social_enabled( 'facebook' ) ) {
		$providers['facebook'] = 'Facebook দিয়ে চালিয়ে যান';
	}
	$html = '';
	if ( ! empty( $_GET['meeha_social_error'] ) ) {
		$html .= '<p class="meeha-account-msg meeha-account-msg--error">' . esc_html( meeha_social_error_text( sanitize_key( wp_unslash( $_GET['meeha_social_error'] ) ) ) ) . '</p>';
	}
	if ( ! $providers ) {
		return $html;
	}
	$redirect = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : '';
	$html    .= '<div class="meeha-social-login">';
	foreach ( $providers as $key => $label ) {
		$html .= '<a class="meeha-social-btn meeha-social-btn--' . esc_attr( $key ) . '" href="' . esc_url( meeha_social_start_url( $key, $redirect ) ) . '" rel="nofollow">'
			. '<span class="meeha-social-btn__icon">' . meeha_social_icon_svg( $key ) . '</span>'
			. '<span>' . esc_html( $label ) . '</span></a>';
	}
	$html .= '</div><p class="meeha-social-or"><span>অথবা</span></p>';
	return $html;
}

/* =====================================================================
   অ্যাডমিন সেটিংস পেজ: Meeha হোমপেজ > নিরাপত্তা ও লগইন
===================================================================== */
function meeha_add_security_settings_page() {
	add_submenu_page(
		'meeha-homepage-settings',
		'নিরাপত্তা ও লগইন',
		'নিরাপত্তা ও লগইন',
		'manage_options',
		'meeha-security-settings',
		'meeha_render_security_settings_page'
	);
}
add_action( 'admin_menu', 'meeha_add_security_settings_page' );

function meeha_handle_security_settings_save() {
	if ( ! isset( $_POST['meeha_security_nonce'] ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['meeha_security_nonce'] ) ), 'meeha_save_security' ) ) {
		return;
	}
	$p   = wp_unslash( $_POST );
	$chk = function ( $k ) use ( $p ) {
		return isset( $p[ $k ] ) ? 1 : 0;
	};
	update_option( 'meeha_security_settings', array(
		'lock_enabled'      => $chk( 'lock_enabled' ),
		'lock_attempts'     => isset( $p['lock_attempts'] ) ? (int) $p['lock_attempts'] : 5,
		'lock_minutes'      => isset( $p['lock_minutes'] ) ? (int) $p['lock_minutes'] : 15,
		'trust_proxy_ip'    => $chk( 'trust_proxy_ip' ),
		'otp_enabled'       => $chk( 'otp_enabled' ),
		'otp_admin'         => $chk( 'otp_admin' ),
		'otp_correspondent' => $chk( 'otp_correspondent' ),
		'otp_ttl_minutes'   => isset( $p['otp_ttl_minutes'] ) ? (int) $p['otp_ttl_minutes'] : 5,
		'otp_email_enabled' => $chk( 'otp_email_enabled' ),
		'sms_enabled'       => $chk( 'sms_enabled' ),
		'sms_method'        => ( isset( $p['sms_method'] ) && 'POST' === $p['sms_method'] ) ? 'POST' : 'GET',
		'sms_url'           => isset( $p['sms_url'] ) ? esc_url_raw( trim( $p['sms_url'] ) ) : '',
		'sms_body'          => isset( $p['sms_body'] ) ? sanitize_textarea_field( $p['sms_body'] ) : '',
		'sms_body_type'     => ( isset( $p['sms_body_type'] ) && 'json' === $p['sms_body_type'] ) ? 'json' : 'form',
		'sms_auth_header'   => isset( $p['sms_auth_header'] ) ? sanitize_text_field( $p['sms_auth_header'] ) : '',
		'sms_api_key'       => isset( $p['sms_api_key'] ) ? sanitize_text_field( $p['sms_api_key'] ) : '',
		'sms_api_secret'    => isset( $p['sms_api_secret'] ) ? sanitize_text_field( $p['sms_api_secret'] ) : '',
		'sms_sender'        => isset( $p['sms_sender'] ) ? sanitize_text_field( $p['sms_sender'] ) : '',
		'sms_number_format' => isset( $p['sms_number_format'] ) ? sanitize_key( $p['sms_number_format'] ) : 'intl',
		'sms_success_text'  => isset( $p['sms_success_text'] ) ? sanitize_text_field( $p['sms_success_text'] ) : '',
		'sms_template'      => isset( $p['sms_template'] ) ? sanitize_textarea_field( $p['sms_template'] ) : '',
	) );

	$notice = 'নিরাপত্তা সেটিংস সংরক্ষণ করা হয়েছে।';
	$type   = 'success';
	if ( ! empty( $p['test_sms_number'] ) ) {
		$res = meeha_send_sms( sanitize_text_field( $p['test_sms_number'] ), 'Meeha News টেস্ট SMS — গেটওয়ে ঠিকভাবে কাজ করছে।' );
		if ( is_wp_error( $res ) ) {
			$notice = 'টেস্ট SMS ব্যর্থ: ' . $res->get_error_message();
			$type   = 'error';
		} else {
			$notice .= ' টেস্ট SMS পাঠানো হয়েছে (গেটওয়ে সফল বলেছে)।';
		}
	}
	add_action( 'admin_notices', function () use ( $notice, $type ) {
		echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $notice ) . '</p></div>';
	} );
}
add_action( 'admin_init', 'meeha_handle_security_settings_save' );

function meeha_render_security_settings_page() {
	$s = meeha_get_security_settings();
	?>
	<div class="wrap">
		<h1>নিরাপত্তা ও লগইন</h1>
		<form method="post">
			<?php wp_nonce_field( 'meeha_save_security', 'meeha_security_nonce' ); ?>

			<h2 class="title">১) ভুল পাসওয়ার্ডে লগইন লক</h2>
			<table class="form-table">
				<tr><th>চালু রাখুন</th><td><label><input type="checkbox" name="lock_enabled" value="1" <?php checked( $s['lock_enabled'] ); ?> /> বারবার ভুল করলে সাময়িকভাবে লগইন বন্ধ করুন</label></td></tr>
				<tr><th>কতবার ভুলের পর লক</th><td><input type="number" min="3" max="20" name="lock_attempts" value="<?php echo esc_attr( $s['lock_attempts'] ); ?>" /></td></tr>
				<tr><th>কত মিনিট লক থাকবে</th><td><input type="number" min="1" max="1440" name="lock_minutes" value="<?php echo esc_attr( $s['lock_minutes'] ); ?>" /></td></tr>
				<tr><th>প্রক্সি/Cloudflare</th><td><label><input type="checkbox" name="trust_proxy_ip" value="1" <?php checked( $s['trust_proxy_ip'] ); ?> /> সাইট Cloudflare বা প্রক্সির পেছনে থাকলে আসল আইপি হেডার ব্যবহার করুন</label>
				<p class="description">লক একই আইপি + একই ইউজারনেমের জুটির জন্য কাজ করে; একই আইপি থেকে বিভিন্ন ইউজারনেমে সীমার ৪ গুণ ব্যর্থ চেষ্টা হলে সেই আইপিও লক হয়।</p></td></tr>
			</table>

			<h2 class="title">২) লগইন ওটিপি (2FA)</h2>
			<table class="form-table">
				<tr><th>চালু রাখুন</th><td><label><input type="checkbox" name="otp_enabled" value="1" <?php checked( $s['otp_enabled'] ); ?> /> ওটিপি ছাড়া লগইন করতে দেবেন না (নিচের ভূমিকাগুলোর জন্য)</label>
				<p class="description"><strong>সাবধান:</strong> চালু করার আগে নিজের ইমেইলে মেইল যাচ্ছে কিনা নিশ্চিত হয়ে নিন। আটকে গেলে wp-config.php-তে <code>define( 'MEEHA_DISABLE_2FA', true );</code> যোগ করলে ওটিপি সাময়িক বন্ধ হবে।</p></td></tr>
				<tr><th>কাদের জন্য বাধ্যতামূলক</th><td>
					<label><input type="checkbox" name="otp_admin" value="1" <?php checked( $s['otp_admin'] ); ?> /> এডমিন</label><br />
					<label><input type="checkbox" name="otp_correspondent" value="1" <?php checked( $s['otp_correspondent'] ); ?> /> সংবাদদাতা</label>
				</td></tr>
				<tr><th>ওটিপির মেয়াদ (মিনিট)</th><td><input type="number" min="1" max="30" name="otp_ttl_minutes" value="<?php echo esc_attr( $s['otp_ttl_minutes'] ); ?>" /></td></tr>
				<tr><th>ইমেইল ওটিপি</th><td><label><input type="checkbox" name="otp_email_enabled" value="1" <?php checked( $s['otp_email_enabled'] ); ?> /> ইমেইলে ওটিপি পাঠানো অনুমোদিত (SMS ব্যর্থ হলে ফলব্যাকও এটাই)</label>
				<p class="description">প্রতিটি ব্যবহারকারী নিজের প্রোফাইল পেজ (ইউজার প্রোফাইল) থেকে ইমেইল বা SMS বেছে নিতে পারবেন।</p></td></tr>
			</table>

			<h2 class="title">৩) SMS গেটওয়ে (মোবাইল ওটিপির জন্য)</h2>
			<p class="description">যেকোনো HTTP-API-ভিত্তিক SMS প্রোভাইডারের ডকুমেন্টেশন দেখে নিচের ঘরগুলো পূরণ করুন। URL/বডিতে এই প্লেসহোল্ডারগুলো বসানো যাবে: <code>{number}</code> <code>{message}</code> <code>{sender}</code> <code>{api_key}</code> <code>{api_secret}</code></p>
			<table class="form-table">
				<tr><th>চালু রাখুন</th><td><label><input type="checkbox" name="sms_enabled" value="1" <?php checked( $s['sms_enabled'] ); ?> /> SMS পাঠানো চালু</label></td></tr>
				<tr><th>রিকোয়েস্ট মেথড</th><td>
					<select name="sms_method"><option value="GET" <?php selected( $s['sms_method'], 'GET' ); ?>>GET</option><option value="POST" <?php selected( $s['sms_method'], 'POST' ); ?>>POST</option></select>
				</td></tr>
				<tr><th>API URL</th><td><input type="text" class="large-text" name="sms_url" value="<?php echo esc_attr( $s['sms_url'] ); ?>" placeholder="https://provider.example/api/send?api_key={api_key}&amp;to={number}&amp;msg={message}&amp;senderid={sender}" />
				<p class="description">GET হলে সব প্যারামিটার URL-এই বসান।</p></td></tr>
				<tr><th>POST বডি</th><td>
					<textarea name="sms_body" rows="3" class="large-text code" placeholder="api_key={api_key}&amp;to={number}&amp;message={message}"><?php echo esc_textarea( $s['sms_body'] ); ?></textarea>
					<select name="sms_body_type"><option value="form" <?php selected( $s['sms_body_type'], 'form' ); ?>>ফর্ম (key=value&amp;...)</option><option value="json" <?php selected( $s['sms_body_type'], 'json' ); ?>>JSON</option></select>
				</td></tr>
				<tr><th>Authorization হেডার (ঐচ্ছিক)</th><td><input type="text" class="regular-text" name="sms_auth_header" value="<?php echo esc_attr( $s['sms_auth_header'] ); ?>" placeholder="Bearer {api_key}" /></td></tr>
				<tr><th>API Key</th><td><input type="text" class="regular-text" name="sms_api_key" value="<?php echo esc_attr( $s['sms_api_key'] ); ?>" autocomplete="off" /></td></tr>
				<tr><th>API Secret (ঐচ্ছিক)</th><td><input type="text" class="regular-text" name="sms_api_secret" value="<?php echo esc_attr( $s['sms_api_secret'] ); ?>" autocomplete="off" /></td></tr>
				<tr><th>Sender ID</th><td><input type="text" class="regular-text" name="sms_sender" value="<?php echo esc_attr( $s['sms_sender'] ); ?>" /></td></tr>
				<tr><th>নাম্বারের ফরম্যাট</th><td>
					<select name="sms_number_format">
						<option value="intl" <?php selected( $s['sms_number_format'], 'intl' ); ?>>8801XXXXXXXXX</option>
						<option value="local" <?php selected( $s['sms_number_format'], 'local' ); ?>>01XXXXXXXXX</option>
						<option value="plus" <?php selected( $s['sms_number_format'], 'plus' ); ?>>+8801XXXXXXXXX</option>
					</select>
				</td></tr>
				<tr><th>সফলতার চিহ্ন (ঐচ্ছিক)</th><td><input type="text" class="regular-text" name="sms_success_text" value="<?php echo esc_attr( $s['sms_success_text'] ); ?>" placeholder="যেমন: SUCCESS" />
				<p class="description">ফাঁকা রাখলে শুধু HTTP 200-299 হলেই সফল ধরা হবে। দিলে প্রোভাইডারের উত্তরে এই লেখা থাকতে হবে।</p></td></tr>
				<tr><th>SMS-এর লেখা</th><td><textarea name="sms_template" rows="2" class="large-text"><?php echo esc_textarea( $s['sms_template'] ); ?></textarea>
				<p class="description"><code>{otp}</code> ও <code>{minutes}</code> ব্যবহার করুন।</p></td></tr>
				<tr><th>টেস্ট SMS পাঠান</th><td><input type="text" name="test_sms_number" placeholder="01XXXXXXXXX" />
				<p class="description">সংরক্ষণ করার সময় এই নাম্বারে একটা টেস্ট SMS যাবে।</p></td></tr>
			</table>

			<h2 class="title">৪) Google / Facebook লগইন</h2>
			<p class="description">ক্রেডেনশিয়াল বসানোর ঘর: <a href="<?php echo esc_url( admin_url( 'admin.php?page=meeha-registration-settings' ) ); ?>">Meeha হোমপেজ > রেজিস্ট্রেশন</a>। Google Cloud Console / Facebook App-এ "Authorized redirect URI" হিসেবে নিচের ঠিকানাগুলো <strong>হুবহু</strong> বসাতে হবে:</p>
			<table class="form-table">
				<tr><th>Google redirect URI</th><td><code><?php echo esc_html( meeha_social_callback_url( 'google' ) ); ?></code> — অবস্থা: <?php echo meeha_social_enabled( 'google' ) ? '<span style="color:#1a7f37">ক্রেডেনশিয়াল দেওয়া আছে</span>' : '<span style="color:#b32d2e">ক্রেডেনশিয়াল নেই</span>'; ?></td></tr>
				<tr><th>Facebook redirect URI</th><td><code><?php echo esc_html( meeha_social_callback_url( 'facebook' ) ); ?></code> — অবস্থা: <?php echo meeha_social_enabled( 'facebook' ) ? '<span style="color:#1a7f37">ক্রেডেনশিয়াল দেওয়া আছে</span>' : '<span style="color:#b32d2e">ক্রেডেনশিয়াল নেই</span>'; ?></td></tr>
			</table>
			<p class="description">নিরাপত্তা নিয়ম: এডমিন/এডিটর-স্তরের একাউন্টে সোশ্যাল লগইন কাজ করবে না (শুধু পাসওয়ার্ড+ওটিপি)। ইমেইল যাচাইকৃত না হলে লগইন হবে না।</p>

			<?php submit_button( 'সংরক্ষণ করুন' ); ?>
		</form>
	</div>
	<?php
}
