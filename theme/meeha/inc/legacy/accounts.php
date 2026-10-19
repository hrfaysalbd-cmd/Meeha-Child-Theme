<?php
/**
 * Accounts: [meeha_login] (+ OTP step, social buttons), [meeha_register], [meeha_lost_password],
 * [meeha_my_account]; account page discovery; login_url filter; wp-login branding; avatar
 * override; admin bar / wp-admin restrictions; registration settings page; auto-registration;
 * MailPoet list integration (consent-only).
 *
 * Fixes vs. the old theme (INVENTORY §8): login honours redirect_to; registration respects
 * users_can_register AND meeha_registration_settings.enabled; registration and lost-password are
 * rate-limited per hashed IP; avatar upload accepts real JPG/PNG/WebP ≤ 2 MB only and deletes
 * the previous file; MailPoet subscription only with an explicit (default unchecked) consent.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

/* =====================================================================
   Account page discovery
===================================================================== */

/** Shortcode for each account page key. */
function meeha_account_page_map() {
	return array(
		'login'         => 'meeha_login',
		'register'      => 'meeha_register',
		'my-account'    => 'meeha_my_account',
		'lost-password' => 'meeha_lost_password',
	);
}

/**
 * Find the published page carrying an account shortcode (post_content or Elementor data).
 * Cached for an hour in `meeha_acct_url_{which}` (an array, so it never clashes with the legacy
 * string transient `meeha_account_page_{which}` used by meeha_get_account_page_url()); both are
 * flushed whenever a page is saved.
 *
 * @return string URL or '' when no such page exists.
 */
function meeha_account_page_lookup( $which ) {
	$map = meeha_account_page_map();
	if ( ! isset( $map[ $which ] ) ) {
		return '';
	}
	$cached = get_transient( 'meeha_acct_url_' . $which );
	if ( is_array( $cached ) && isset( $cached['url'] ) ) {
		return (string) $cached['url'];
	}
	global $wpdb;
	$tag  = $map[ $which ];
	$like = '%' . $wpdb->esc_like( '[' . $tag ) . '%';
	// phpcs:disable WordPress.DB.DirectDatabaseQuery
	$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_content LIKE %s ORDER BY menu_order ASC, ID ASC LIMIT 20", $like ) );
	$el  = $wpdb->get_col( $wpdb->prepare( "SELECT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_elementor_data' AND p.post_type = 'page' AND p.post_status = 'publish' AND pm.meta_value LIKE %s ORDER BY p.ID ASC LIMIT 20", $like ) );
	// phpcs:enable
	$url = '';
	foreach ( array_unique( array_merge( array_map( 'intval', (array) $ids ), array_map( 'intval', (array) $el ) ) ) as $id ) {
		if ( meeha_legacy_post_has( $id, array( $tag ) ) ) {
			$url = (string) get_permalink( $id );
			break;
		}
	}
	set_transient( 'meeha_acct_url_' . $which, array( 'url' => $url ), HOUR_IN_SECONDS );
	return $url;
}

if ( ! function_exists( 'meeha_get_account_page_url' ) ) {
	/**
	 * Legacy public API: URL of the login / register / my-account / lost-password page.
	 * Falls back to the home URL (old behaviour) when no page carries the shortcode.
	 * (The foundation may define it first — then this fallback is skipped.)
	 */
	function meeha_get_account_page_url( $which ) {
		$url = meeha_account_page_lookup( $which );
		return $url ? $url : home_url( '/' );
	}
}

/** Forget cached account URLs when a page changes. */
function meeha_account_pages_flush( $post_id = 0, $post = null ) {
	if ( $post && 'page' !== $post->post_type ) {
		return;
	}
	foreach ( array_keys( meeha_account_page_map() ) as $k ) {
		delete_transient( 'meeha_account_page_' . $k );
		delete_transient( 'meeha_acct_url_' . $k );
	}
}
add_action( 'save_post_page', 'meeha_account_pages_flush', 10, 2 );
add_action( 'deleted_post', 'meeha_account_pages_flush' );
add_action( 'after_switch_theme', 'meeha_account_pages_flush' );

/* =====================================================================
   login_url → custom login page (wp-admin auth_redirect, comment "log in", admin bar …)
===================================================================== */
function meeha_custom_login_url( $login_url, $redirect, $force_reauth ) {
	$custom = meeha_account_page_lookup( 'login' );
	if ( ! $custom ) {
		return $login_url; // no [meeha_login] page → keep wp-login.php
	}
	if ( $redirect ) {
		$custom = add_query_arg( 'redirect_to', urlencode( $redirect ), $custom );
	}
	if ( $force_reauth ) {
		$custom = add_query_arg( 'reauth', '1', $custom );
	}
	return $custom;
}
add_filter( 'login_url', 'meeha_custom_login_url', 10, 3 );

/* =====================================================================
   wp-login.php branding
===================================================================== */
function meeha_login_logo_url_src() {
	$id = (int) get_theme_mod( 'custom_logo' );
	if ( $id ) {
		$src = wp_get_attachment_image_url( $id, 'full' );
		if ( $src ) {
			return $src;
		}
	}
	return is_readable( MEEHA_DIR . '/assets/img/logo.svg' ) ? MEEHA_URI . '/assets/img/logo.svg' : '';
}

function meeha_login_logo_css() {
	$logo = meeha_login_logo_url_src();
	?>
	<style id="meeha-login-brand">
		body.login { background: #F3F5F8; }
		<?php if ( $logo ) : ?>
		#login h1 a, .login h1 a { background-image: url('<?php echo esc_url( $logo ); ?>'); background-size: contain; background-position: center; background-repeat: no-repeat; width: 260px; height: 72px; }
		<?php endif; ?>
		body.login.wp-core-ui .button-primary { background: #025AB2; border-color: #025AB2; }
		body.login.wp-core-ui .button-primary:hover, body.login.wp-core-ui .button-primary:focus { background: #01468C; border-color: #01468C; }
		body.login.wp-core-ui .button.wp-hide-pw, body.login.wp-core-ui .button.wp-hide-pw:focus { color: #025AB2; }
		.login #nav a:hover, .login #backtoblog a:hover, .login h1 a:focus { color: #025AB2; }
		.login input[type=text]:focus, .login input[type=password]:focus { border-color: #025AB2; box-shadow: 0 0 0 1px #025AB2; }
	</style>
	<?php
}
add_action( 'login_enqueue_scripts', 'meeha_login_logo_css' );

function meeha_login_logo_url() {
	return home_url( '/' );
}
add_filter( 'login_headerurl', 'meeha_login_logo_url' );

function meeha_login_logo_title() {
	return get_bloginfo( 'name' );
}
add_filter( 'login_headertext', 'meeha_login_logo_title' );

/* =====================================================================
   Admin bar / wp-admin restrictions (unchanged behaviour)
===================================================================== */
// hide the admin bar on the front end for everyone except administrators
function meeha_restrict_subscriber_backend() {
	if ( current_user_can( 'manage_options' ) || ! apply_filters( 'meeha_hide_admin_bar', true ) ) {
		return;
	}
	add_filter( 'show_admin_bar', '__return_false' );
}
add_action( 'init', 'meeha_restrict_subscriber_backend' );

function meeha_block_subscriber_wp_admin() {
	global $pagenow;
	if ( ! is_admin() || wp_doing_ajax() || ! is_user_logged_in() || 'admin-post.php' === $pagenow ) {
		return;
	}
	$user = wp_get_current_user();
	if ( in_array( 'subscriber', (array) $user->roles, true ) && 1 === count( (array) $user->roles ) ) {
		wp_safe_redirect( meeha_account_page_lookup( 'my-account' ) ? meeha_account_page_lookup( 'my-account' ) : home_url( '/' ) );
		exit;
	}
}
add_action( 'admin_init', 'meeha_block_subscriber_wp_admin' );

/* =====================================================================
   Registration switches, auto-registration, MailPoet
===================================================================== */
function meeha_is_auto_registration_enabled() {
	$settings = get_option( 'meeha_registration_settings', array() );
	return ! isset( $settings['enabled'] ) || $settings['enabled'];
}

/**
 * Can new accounts be created? Requires BOTH core's "Anyone can register"
 * (users_can_register) and the theme's registration switch.
 */
function meeha_registration_open() {
	$open = (bool) get_option( 'users_can_register' ) && meeha_is_auto_registration_enabled();
	return (bool) apply_filters( 'meeha_registration_open', $open );
}

function meeha_get_registration_email_template() {
	$settings        = get_option( 'meeha_registration_settings', array() );
	$default_subject = '{site_name}-এ আপনার একাউন্ট তৈরি হয়েছে';
	$default_body    = "প্রিয় {name},\n\nমন্তব্য/ভোট দেওয়ার সময় আপনি {site_name}-এ রেজিস্ট্রেশনের অনুরোধ করেছিলেন। আপনার একাউন্ট তৈরি হয়ে গেছে।\n\nইউজারনেম: {username}\nপাসওয়ার্ড: {password}\n\nলগইন করুন: {login_url}\n\nধন্যবাদ,\n{site_name}";
	return array(
		'subject' => ! empty( $settings['email_subject'] ) ? $settings['email_subject'] : $default_subject,
		'body'    => ! empty( $settings['email_body'] ) ? $settings['email_body'] : $default_body,
	);
}

/** Unique username from an e-mail's local part. */
function meeha_username_from_email( $email ) {
	$base = sanitize_user( current( explode( '@', $email ) ), true );
	$base = $base ? substr( $base, 0, 50 ) : 'user';
	$name = $base;
	$n    = 1;
	while ( username_exists( $name ) ) {
		$name = $base . $n;
		$n++;
	}
	return $name;
}

/**
 * Legacy API (used by the comment/poll/report forms): create a subscriber account for an
 * e-mail that has none, and mail the credentials using the editable template.
 *
 * Round 11: no plain-text password any more — `{password}` (and the new `{set_password_url}`)
 * become a one-time "set your password" link (core reset key). Respects meeha_registration_open().
 *
 * @return int User ID or 0.
 */
function meeha_maybe_create_account( $name, $email, $phone = '' ) {
	if ( ! meeha_registration_open() ) {
		return 0;
	}
	$email = sanitize_email( $email );
	if ( ! is_email( $email ) || email_exists( $email ) ) {
		return 0;
	}
	$name     = sanitize_text_field( $name );
	$username = meeha_username_from_email( $email );
	$user_id  = wp_insert_user(
		array(
			'user_login'   => $username,
			'user_pass'    => wp_generate_password( 24, true, true ),
			'user_email'   => $email,
			'display_name' => $name ? $name : $username,
			'role'         => 'subscriber',
		)
	);
	if ( is_wp_error( $user_id ) ) {
		return 0;
	}
	if ( $phone ) {
		update_user_meta( $user_id, 'meeha_phone', sanitize_text_field( $phone ) );
	}
	$user    = get_userdata( $user_id );
	$key     = get_password_reset_key( $user );
	$set_url = is_wp_error( $key ) ? meeha_get_account_page_url( 'lost-password' ) : network_site_url( 'wp-login.php?action=rp&key=' . $key . '&login=' . rawurlencode( $username ), 'login' );

	$template = meeha_get_registration_email_template();
	$tokens   = array(
		'{name}'             => $name ? $name : $username,
		'{username}'         => $username,
		'{password}'         => 'এই লিংক থেকে নিজের পাসওয়ার্ড সেট করুন: ' . $set_url,
		'{set_password_url}' => $set_url,
		'{site_name}'        => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
		'{login_url}'        => meeha_get_account_page_url( 'login' ),
	);
	wp_mail( $email, strtr( $template['subject'], $tokens ), strtr( $template['body'], $tokens ) );
	return (int) $user_id;
}

function meeha_get_mailpoet_list_id() {
	$settings = get_option( 'meeha_registration_settings', array() );
	return isset( $settings['mailpoet_list_id'] ) ? absint( $settings['mailpoet_list_id'] ) : 0;
}

/**
 * Legacy low-level call: add an e-mail to the configured MailPoet list (no confirmation mail).
 * New code must go through meeha_maybe_subscribe_mailpoet() which enforces consent.
 */
function meeha_add_to_mailpoet_list( $email, $name = '' ) {
	if ( ! is_email( $email ) || ! class_exists( '\MailPoet\API\API' ) ) {
		return false;
	}
	$list_id = meeha_get_mailpoet_list_id();
	if ( ! $list_id ) {
		return false;
	}
	try {
		$mailpoet   = \MailPoet\API\API::MP( 'v1' );
		$name_parts = explode( ' ', trim( (string) $name ), 2 );
		$mailpoet->addSubscriber(
			array(
				'email'      => $email,
				'first_name' => isset( $name_parts[0] ) ? $name_parts[0] : '',
				'last_name'  => isset( $name_parts[1] ) ? $name_parts[1] : '',
			),
			array( $list_id ),
			array( 'send_confirmation_email' => false )
		);
		return true;
	} catch ( \Exception $e ) {
		return false; // already subscribed, or MailPoet list/config issue
	}
}

/**
 * Public API for every form (comments — D, polls — D, reports, registration):
 * subscribe ONLY when the reader ticked the (default unchecked) consent box.
 *
 * @return bool True when a subscription call was made and succeeded.
 */
function meeha_maybe_subscribe_mailpoet( $email, $name, $consent ) {
	if ( ! $consent || ! is_email( $email ) ) {
		return false;
	}
	return meeha_add_to_mailpoet_list( sanitize_email( $email ), sanitize_text_field( (string) $name ) );
}

/** Markup of the consent checkbox (shown only when a MailPoet list is configured). */
function meeha_mailpoet_consent_field( $id_prefix = 'm' ) {
	if ( ! meeha_get_mailpoet_list_id() ) {
		return '';
	}
	$id = esc_attr( $id_prefix . '-nlc' );
	return '<label class="m-check" for="' . $id . '"><input type="checkbox" id="' . $id . '" name="meeha_newsletter_consent" value="1"> <span>মিহা নিউজের নিউজলেটার ইমেইলে পেতে চাই (যেকোনো সময় বাতিল করা যাবে)</span></label>';
}

/* =====================================================================
   Registration settings page (slug meeha-registration-settings, option unchanged)
===================================================================== */
function meeha_add_registration_settings_page() {
	add_submenu_page( meeha_legacy_menu_parent(), 'Meeha রেজিস্ট্রেশন সেটিংস', 'রেজিস্ট্রেশন', 'manage_options', 'meeha-registration-settings', 'meeha_render_registration_settings_page' );
}
add_action( 'admin_menu', 'meeha_add_registration_settings_page', 20 );

function meeha_handle_registration_settings_save() {
	if ( ! isset( $_POST['meeha_registration_nonce'] ) ) {
		return;
	}
	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['meeha_registration_nonce'] ) ), 'meeha_save_registration_settings' ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$old      = get_option( 'meeha_registration_settings', array() );
	$old      = is_array( $old ) ? $old : array();
	$text     = function ( $k ) {
		return isset( $_POST[ $k ] ) ? sanitize_text_field( wp_unslash( $_POST[ $k ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	};
	$settings = array_merge(
		$old,
		array(
			'enabled'              => isset( $_POST['reg_enabled'] ) ? 1 : 0,
			'email_subject'        => $text( 'email_subject' ),
			'email_body'           => isset( $_POST['email_body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['email_body'] ) ) : '',
			'google_client_id'     => $text( 'google_client_id' ),
			'google_client_secret' => $text( 'google_client_secret' ),
			'facebook_app_id'      => $text( 'facebook_app_id' ),
			'facebook_app_secret'  => $text( 'facebook_app_secret' ),
			'mailpoet_list_id'     => isset( $_POST['mailpoet_list_id'] ) ? absint( $_POST['mailpoet_list_id'] ) : 0,
		)
	);
	update_option( 'meeha_registration_settings', $settings );
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-success is-dismissible"><p>রেজিস্ট্রেশন সেটিংস সংরক্ষণ করা হয়েছে।</p></div>';
		}
	);
}
add_action( 'admin_init', 'meeha_handle_registration_settings_save' );

function meeha_render_registration_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$settings = get_option( 'meeha_registration_settings', array() );
	$template = meeha_get_registration_email_template();
	$v        = function ( $k ) use ( $settings ) {
		return isset( $settings[ $k ] ) ? $settings[ $k ] : '';
	};
	?>
	<div class="wrap">
		<h1>Meeha রেজিস্ট্রেশন সেটিংস</h1>
		<?php if ( ! get_option( 'users_can_register' ) ) : ?>
			<div class="notice notice-warning inline"><p><strong>রেজিস্ট্রেশন এখন বন্ধ:</strong> <a href="<?php echo esc_url( admin_url( 'options-general.php' ) ); ?>">সেটিংস › সাধারণ</a> পাতায় “Anyone can register” (সদস্যপদ) চালু নেই। নতুন একাউন্ট (ফর্ম, সোশ্যাল লগইন, কমেন্ট/রিপোর্ট থেকে অটো-একাউন্ট) তৈরি হবে না যতক্ষণ না দুটোই চালু থাকে।</p></div>
		<?php endif; ?>
		<p>রেজিস্ট্রেশন ফর্ম, সোশ্যাল লগইন এবং মন্তব্য/ভোট/রিপোর্ট ফর্মের “একাউন্ট খুলুন” চেকবক্স — সবই এই সুইচ এবং ওয়ার্ডপ্রেসের “Anyone can register” দুটো চালু থাকলেই কাজ করে। এই একাউন্টগুলো সাবস্ক্রাইবার লেভেলের, wp-admin-এ ঢুকতে পারে না।</p>

		<form method="post">
			<?php wp_nonce_field( 'meeha_save_registration_settings', 'meeha_registration_nonce' ); ?>
			<table class="form-table">
				<tr>
					<th scope="row">চালু রাখুন</th>
					<td><label><input type="checkbox" name="reg_enabled" value="1" <?php checked( meeha_is_auto_registration_enabled() ); ?> /> নতুন একাউন্ট খোলা যাবে (রেজিস্ট্রেশন পেজ, সোশ্যাল লগইন, কমেন্ট/ভোট/রিপোর্ট ফর্মের চেকবক্স)</label></td>
				</tr>
				<tr>
					<th scope="row">ইমেইল সাবজেক্ট</th>
					<td><input type="text" name="email_subject" value="<?php echo esc_attr( $template['subject'] ); ?>" class="large-text" /></td>
				</tr>
				<tr>
					<th scope="row">ইমেইল বডি</th>
					<td>
						<textarea name="email_body" rows="8" class="large-text"><?php echo esc_textarea( $template['body'] ); ?></textarea>
						<p class="description">টোকেন: <code>{name}</code> <code>{username}</code> <code>{password}</code> <code>{set_password_url}</code> <code>{site_name}</code> <code>{login_url}</code>. নিরাপত্তার জন্য এখন আর পাসওয়ার্ড ইমেইলে যায় না — <code>{password}</code>-এর জায়গায় “পাসওয়ার্ড সেট করার লিংক” বসে।</p>
					</td>
				</tr>
			</table>

			<h2 class="title">MailPoet ইন্টিগ্রেশন</h2>
			<p class="description">রেজিস্ট্রেশন, মন্তব্য, ভোট বা রিপোর্ট ফর্মে পাঠক “নিউজলেটার পেতে চাই” টিক দিলে তবেই তার ইমেইল এই লিস্টে যোগ হবে (ডিফল্ট টিক ছাড়া)। List ID: MailPoet → Lists-এ লিস্টের ওপর মাউস রাখলে URL-এ id দেখা যায়।</p>
			<table class="form-table">
				<tr>
					<th scope="row">MailPoet List ID</th>
					<td><input type="number" min="0" name="mailpoet_list_id" value="<?php echo esc_attr( $v( 'mailpoet_list_id' ) ); ?>" class="small-text" placeholder="১" /></td>
				</tr>
			</table>

			<h2 class="title">Google / Facebook লগইন</h2>
			<p class="description">ID ও Secret দুটোই দিলে লগইন ও রেজিস্ট্রেশন পেজে বাটন দেখাবে। কনসোলে যে “Authorized redirect URI” বসাতে হবে: <a href="<?php echo esc_url( admin_url( 'admin.php?page=meeha-security-settings' ) ); ?>">নিরাপত্তা ও লগইন</a> পেজে দেখুন।</p>
			<table class="form-table">
				<?php
				foreach ( array(
					'google_client_id'     => 'Google Client ID',
					'google_client_secret' => 'Google Client Secret',
					'facebook_app_id'      => 'Facebook App ID',
					'facebook_app_secret'  => 'Facebook App Secret',
				) as $key => $label ) :
					?>
					<tr>
						<th scope="row"><?php echo esc_html( $label ); ?></th>
						<td><input type="text" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $v( $key ) ); ?>" class="large-text" autocomplete="off" /></td>
					</tr>
				<?php endforeach; ?>
			</table>
			<?php submit_button( 'সেটিংস সংরক্ষণ করুন' ); ?>
		</form>
	</div>
	<?php
}

/* =====================================================================
   Avatar: custom upload replaces Gravatar everywhere
===================================================================== */
function meeha_custom_avatar_url( $url, $id_or_email ) {
	$user_id = 0;
	if ( is_numeric( $id_or_email ) ) {
		$user_id = (int) $id_or_email;
	} elseif ( $id_or_email instanceof WP_User ) {
		$user_id = (int) $id_or_email->ID;
	} elseif ( $id_or_email instanceof WP_Post ) {
		$user_id = (int) $id_or_email->post_author;
	} elseif ( is_object( $id_or_email ) && ! empty( $id_or_email->user_id ) ) {
		$user_id = (int) $id_or_email->user_id; // comment object
	} elseif ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
		$user = get_user_by( 'email', $id_or_email );
		if ( $user ) {
			$user_id = (int) $user->ID;
		}
	}
	if ( $user_id ) {
		$custom = get_user_meta( $user_id, 'meeha_avatar', true );
		if ( $custom ) {
			return $custom;
		}
	}
	return $url;
}
add_filter( 'get_avatar_url', 'meeha_custom_avatar_url', 10, 2 );

/**
 * Save a validated avatar for a user: re-encoded 256×256 crop in uploads/meeha-avatars with a
 * random name; the previous custom avatar file is deleted.
 *
 * @return string|WP_Error New URL.
 */
function meeha_save_user_avatar( $user_id, $file, $check_uploaded = true ) {
	$valid = meeha_legacy_validate_image( $file, (int) apply_filters( 'meeha_avatar_max_bytes', 2 * MB_IN_BYTES ), $check_uploaded );
	if ( is_wp_error( $valid ) ) {
		return $valid;
	}
	$stored = meeha_legacy_store_image( $valid, 'meeha-avatars', array( 256, 256, true ) );
	if ( is_wp_error( $stored ) ) {
		return $stored;
	}
	// delete the previous custom avatar (never an attachment, never outside uploads)
	$prev_path = (string) get_user_meta( $user_id, 'meeha_avatar_file', true );
	if ( ! $prev_path ) {
		$prev_url  = (string) get_user_meta( $user_id, 'meeha_avatar', true );
		$prev_path = ( $prev_url && ! attachment_url_to_postid( $prev_url ) ) ? meeha_legacy_upload_url_to_path( $prev_url ) : '';
	}
	if ( $prev_path && $prev_path !== $stored['path'] ) {
		$up   = wp_upload_dir( null, false );
		$base = wp_normalize_path( trailingslashit( $up['basedir'] ) );
		$prev = wp_normalize_path( $prev_path );
		if ( 0 === strpos( $prev, $base ) && is_file( $prev ) && preg_match( '/\.(jpe?g|png|webp|gif)$/i', $prev ) ) {
			wp_delete_file( $prev );
		}
	}
	update_user_meta( $user_id, 'meeha_avatar', esc_url_raw( $stored['url'] ) );
	update_user_meta( $user_id, 'meeha_avatar_file', $stored['path'] );
	return $stored['url'];
}

/* =====================================================================
   Author designation (meeha_author_title) on wp-admin profile screens
===================================================================== */
if ( ! function_exists( 'meeha_add_author_title_field' ) ) {
	function meeha_add_author_title_field( $user ) {
		?>
		<h2>Meeha প্রতিবেদক তথ্য</h2>
		<table class="form-table">
			<tr>
				<th><label for="meeha_author_title">পদবি</label></th>
				<td>
					<input type="text" name="meeha_author_title" id="meeha_author_title" value="<?php echo esc_attr( get_user_meta( $user->ID, 'meeha_author_title', true ) ); ?>" class="regular-text" placeholder="যেমন: নিজস্ব প্রতিবেদক, জেলা প্রতিনিধি" />
					<p class="description">আর্টিকেলের বাইলাইনে নামের পাশে এটা দেখাবে।</p>
				</td>
			</tr>
		</table>
		<?php
	}
	add_action( 'show_user_profile', 'meeha_add_author_title_field' );
	add_action( 'edit_user_profile', 'meeha_add_author_title_field' );

	function meeha_save_author_title_field( $user_id ) {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- core verifies the profile nonce before these hooks.
		if ( isset( $_POST['meeha_author_title'] ) ) {
			update_user_meta( $user_id, 'meeha_author_title', sanitize_text_field( wp_unslash( $_POST['meeha_author_title'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
	}
	add_action( 'personal_options_update', 'meeha_save_author_title_field' );
	add_action( 'edit_user_profile_update', 'meeha_save_author_title_field' );
}

/* =====================================================================
   Transport-independent account actions → [ bool ok, array data ]
===================================================================== */

function meeha_account_do_register( $p ) {
	if ( ! meeha_registration_open() ) {
		return array( false, array( 'message' => 'এই মুহূর্তে নতুন রেজিস্ট্রেশন বন্ধ আছে।' ) );
	}
	if ( meeha_legacy_honeypot_tripped( isset( $p['website'] ) ? $p['website'] : '' ) ) {
		return array( true, array( 'message' => 'একাউন্ট তৈরি হয়েছে!' ) ); // silently drop bots
	}
	if ( ! meeha_legacy_rate_ok( 'register', 5, HOUR_IN_SECONDS ) ) {
		return array( false, array( 'message' => 'অনেকবার চেষ্টা হয়েছে। কিছুক্ষণ পর আবার চেষ্টা করুন।', 'code' => 'rate' ) );
	}
	$name     = isset( $p['name'] ) ? sanitize_text_field( $p['name'] ) : '';
	$email    = isset( $p['email'] ) ? sanitize_email( $p['email'] ) : '';
	$phone    = isset( $p['phone'] ) ? sanitize_text_field( $p['phone'] ) : '';
	$password = isset( $p['password'] ) ? (string) $p['password'] : '';

	if ( mb_strlen( $name ) < 2 || mb_strlen( $name ) > 60 || ! is_email( $email ) || strlen( $password ) < 8 ) {
		return array( false, array( 'message' => 'সব তথ্য সঠিকভাবে পূরণ করুন (পাসওয়ার্ড কমপক্ষে ৮ অক্ষর)।' ) );
	}
	if ( '' !== $phone && ! preg_match( '/^\+?[0-9০-৯\s-]{6,20}$/u', $phone ) ) {
		return array( false, array( 'message' => 'ফোন নাম্বার সঠিক নয়।' ) );
	}
	if ( email_exists( $email ) ) {
		// Non-committal wording (does not assert that the address is registered); rate limits apply.
		return array( false, array( 'code' => 'exists', 'message' => 'এই তথ্য দিয়ে নতুন একাউন্ট খোলা গেল না। আগে একাউন্ট খুলে থাকলে লগইন বা পাসওয়ার্ড রিসেট করুন।' ) );
	}
	$user_id = wp_insert_user(
		array(
			'user_login'   => meeha_username_from_email( $email ),
			'user_pass'    => $password,
			'user_email'   => $email,
			'display_name' => $name,
			'role'         => 'subscriber',
		)
	);
	if ( is_wp_error( $user_id ) ) {
		return array( false, array( 'message' => 'একাউন্ট তৈরি করা যায়নি।' ) );
	}
	if ( $phone ) {
		update_user_meta( $user_id, 'meeha_phone', $phone );
	}
	meeha_maybe_subscribe_mailpoet( $email, $name, ! empty( $p['meeha_newsletter_consent'] ) );

	wp_set_current_user( $user_id );
	wp_set_auth_cookie( $user_id, true, is_ssl() );
	return array(
		true,
		array(
			'message'  => 'একাউন্ট তৈরি হয়েছে!',
			'redirect' => meeha_login_redirect_target( isset( $p['redirect_to'] ) ? $p['redirect_to'] : '', get_userdata( $user_id ) ),
		),
	);
}

function meeha_account_do_lost_password( $p ) {
	$msg   = 'এই ইমেইলে একাউন্ট থাকলে পাসওয়ার্ড রিসেটের লিংক পাঠানো হয়েছে। ইনবক্স (ও স্প্যাম) দেখুন।';
	$login = isset( $p['email'] ) ? $p['email'] : ( isset( $p['user_login'] ) ? $p['user_login'] : '' );
	$login = sanitize_text_field( (string) $login );
	if ( meeha_legacy_honeypot_tripped( isset( $p['website'] ) ? $p['website'] : '' ) ) {
		return array( true, array( 'message' => $msg ) );
	}
	if ( ! meeha_legacy_rate_ok( 'lostpw', 5, HOUR_IN_SECONDS ) ) {
		return array( false, array( 'message' => 'অনেকবার চেষ্টা হয়েছে। কিছুক্ষণ পর আবার চেষ্টা করুন।', 'code' => 'rate' ) );
	}
	if ( '' === $login ) {
		return array( false, array( 'message' => 'ইমেইল দিন।' ) );
	}
	$user = is_email( $login ) ? get_user_by( 'email', $login ) : get_user_by( 'login', $login );
	// per-account cap so nobody can flood one inbox; same answer either way (no enumeration)
	if ( $user && meeha_legacy_rate_ok( 'lostpwu', 3, HOUR_IN_SECONDS, 'u' . $user->ID ) ) {
		retrieve_password( $user->user_login );
	}
	return array( true, array( 'message' => $msg ) );
}

function meeha_account_do_profile( $user_id, $p ) {
	$name  = isset( $p['name'] ) ? sanitize_text_field( $p['name'] ) : '';
	$phone = isset( $p['phone'] ) ? sanitize_text_field( $p['phone'] ) : '';
	if ( '' !== $name && ( mb_strlen( $name ) < 2 || mb_strlen( $name ) > 60 ) ) {
		return array( false, array( 'message' => 'নাম ২–৬০ অক্ষরের মধ্যে দিন।' ) );
	}
	if ( '' !== $phone && ! preg_match( '/^\+?[0-9০-৯\s-]{6,20}$/u', $phone ) ) {
		return array( false, array( 'message' => 'ফোন নাম্বার সঠিক নয়।' ) );
	}
	if ( $name ) {
		wp_update_user(
			array(
				'ID'           => $user_id,
				'display_name' => $name,
			)
		);
	}
	update_user_meta( $user_id, 'meeha_phone', $phone );
	if ( isset( $p['meeha_2fa_method'] ) ) {
		$m2 = sanitize_key( $p['meeha_2fa_method'] );
		if ( in_array( $m2, array( 'email', 'sms' ), true ) ) {
			update_user_meta( $user_id, 'meeha_2fa_method', $m2 );
		}
	}
	$area = meeha_area_sanitize_triplet(
		isset( $p['meeha_user_division'] ) ? $p['meeha_user_division'] : '',
		isset( $p['meeha_user_district'] ) ? $p['meeha_user_district'] : '',
		isset( $p['meeha_user_upazila'] ) ? $p['meeha_user_upazila'] : ''
	);
	if ( isset( $p['meeha_user_division'] ) ) {
		foreach ( $area as $field => $value ) {
			update_user_meta( $user_id, 'meeha_' . $field, $value );
		}
	}
	update_user_meta( $user_id, 'meeha_show_area', empty( $p['show_area'] ) ? 0 : 1 );
	return array( true, array( 'message' => 'প্রোফাইল আপডেট হয়েছে।' ) );
}

function meeha_account_do_password( $user_id, $p ) {
	$user     = get_userdata( $user_id );
	$current  = isset( $p['current_password'] ) ? (string) $p['current_password'] : '';
	$new_pass = isset( $p['new_password'] ) ? (string) $p['new_password'] : '';
	if ( ! $user || ! wp_check_password( $current, $user->user_pass, $user->ID ) ) {
		return array( false, array( 'message' => 'বর্তমান পাসওয়ার্ড ভুল।' ) );
	}
	if ( strlen( $new_pass ) < 8 ) {
		return array( false, array( 'message' => 'নতুন পাসওয়ার্ড কমপক্ষে ৮ অক্ষরের হতে হবে।' ) );
	}
	wp_set_password( $new_pass, $user->ID ); // ends every other session
	wp_set_current_user( $user->ID );
	wp_set_auth_cookie( $user->ID, true, is_ssl() ); // …but keep this browser logged in
	return array( true, array( 'message' => 'পাসওয়ার্ড বদলানো হয়েছে। অন্য সব ডিভাইস থেকে লগআউট করা হয়েছে।' ) );
}

function meeha_account_do_avatar( $user_id, $file, $check_uploaded = true ) {
	if ( ! meeha_legacy_rate_ok( 'avatar', 10, HOUR_IN_SECONDS, 'u' . $user_id ) ) {
		return array( false, array( 'message' => 'অনেকবার চেষ্টা হয়েছে। কিছুক্ষণ পর আবার চেষ্টা করুন।' ) );
	}
	if ( empty( $file ) || empty( $file['name'] ) ) {
		return array( false, array( 'message' => 'ছবি বাছাই করুন।' ) );
	}
	$url = meeha_save_user_avatar( $user_id, $file, $check_uploaded );
	if ( is_wp_error( $url ) ) {
		return array( false, array( 'message' => $url->get_error_message() ) );
	}
	return array(
		true,
		array(
			'message' => 'ছবি আপডেট হয়েছে।',
			'url'     => $url,
		),
	);
}

/* =====================================================================
   REST (anonymous, no nonce, rate-limited) — meeha/v1/account/*
===================================================================== */
function meeha_account_rest_routes() {
	$routes = array(
		'login'            => function ( WP_REST_Request $r ) {
			return meeha_login_step_password( (string) $r->get_param( 'username' ), (string) $r->get_param( 'password' ), (string) $r->get_param( 'redirect_to' ) );
		},
		'login-otp'        => function ( WP_REST_Request $r ) {
			return meeha_login_step_otp( (string) $r->get_param( 'token' ), (string) $r->get_param( 'code' ) );
		},
		'login-otp-resend' => function ( WP_REST_Request $r ) {
			return meeha_login_step_otp_resend( (string) $r->get_param( 'token' ) );
		},
		'register'         => function ( WP_REST_Request $r ) {
			return meeha_account_do_register( $r->get_params() );
		},
		'lost-password'    => function ( WP_REST_Request $r ) {
			return meeha_account_do_lost_password( $r->get_params() );
		},
	);
	foreach ( $routes as $path => $cb ) {
		register_rest_route(
			'meeha/v1',
			'/account/' . $path,
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => function ( WP_REST_Request $r ) use ( $cb ) {
					if ( ! meeha_legacy_same_origin() ) {
						return new WP_REST_Response( array( 'success' => false, 'data' => array( 'message' => 'অনুরোধ গ্রহণযোগ্য নয়।' ) ), 403 );
					}
					$out = call_user_func( $cb, $r );
					$res = new WP_REST_Response(
						array(
							'success' => (bool) $out[0],
							'data'    => $out[1],
						),
						$out[0] ? 200 : ( ( isset( $out[1]['code'] ) && 'rate' === $out[1]['code'] ) ? 429 : 400 )
					);
					$res->header( 'Cache-Control', 'no-store' );
					return $res;
				},
			)
		);
	}
}
add_action( 'rest_api_init', 'meeha_account_rest_routes' );

/* =====================================================================
   Legacy admin-ajax actions (same names, same JSON shape)
===================================================================== */
// phpcs:disable WordPress.Security.NonceVerification.Missing -- anonymous wrappers: see bootstrap.php nonce policy.
function meeha_handle_ajax_register() {
	if ( ! meeha_legacy_same_origin() ) {
		wp_send_json_error( array( 'message' => 'অনুরোধ গ্রহণযোগ্য নয়।' ), 403 );
	}
	meeha_legacy_ajax_send( meeha_account_do_register( wp_unslash( $_POST ) ) );
}
add_action( 'wp_ajax_nopriv_meeha_ajax_register', 'meeha_handle_ajax_register' );

function meeha_handle_ajax_lost_password() {
	meeha_legacy_ajax_send( meeha_account_do_lost_password( wp_unslash( $_POST ) ) );
}
add_action( 'wp_ajax_nopriv_meeha_ajax_lost_password', 'meeha_handle_ajax_lost_password' );
// phpcs:enable

/** Logged-in actions: nonce required (meeha_account_nonce). */
function meeha_account_ajax_guard() {
	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => 'লগইন করুন।' ), 401 );
	}
	if ( ! check_ajax_referer( 'meeha_account_nonce', 'nonce', false ) ) {
		wp_send_json_error( array( 'message' => 'সেশনের মেয়াদ শেষ, আবার চেষ্টা করুন।', 'stale_nonce' => true ), 403 );
	}
}

function meeha_handle_ajax_profile_update() {
	meeha_account_ajax_guard();
	meeha_legacy_ajax_send( meeha_account_do_profile( get_current_user_id(), wp_unslash( $_POST ) ) );
}
add_action( 'wp_ajax_meeha_ajax_profile_update', 'meeha_handle_ajax_profile_update' );

function meeha_handle_ajax_password_change() {
	meeha_account_ajax_guard();
	meeha_legacy_ajax_send( meeha_account_do_password( get_current_user_id(), wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
}
add_action( 'wp_ajax_meeha_ajax_password_change', 'meeha_handle_ajax_password_change' );

function meeha_handle_ajax_avatar_upload() {
	meeha_account_ajax_guard();
	meeha_legacy_ajax_send( meeha_account_do_avatar( get_current_user_id(), isset( $_FILES['avatar'] ) ? $_FILES['avatar'] : array() ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
}
add_action( 'wp_ajax_meeha_ajax_avatar_upload', 'meeha_handle_ajax_avatar_upload' );

/** Fresh nonce for the logged-in user (cookie-authenticated; response unreadable cross-origin). */
function meeha_handle_ajax_account_nonce() {
	nocache_headers();
	wp_send_json_success( array( 'nonce' => wp_create_nonce( 'meeha_account_nonce' ) ) );
}
add_action( 'wp_ajax_meeha_account_nonce', 'meeha_handle_ajax_account_nonce' );

/* =====================================================================
   No-JS fallbacks via admin-post.php (redirect back with a message code)
===================================================================== */
function meeha_account_msg_text( $code ) {
	$map = array(
		'reg_ok'      => array( 'ok', 'একাউন্ট তৈরি হয়েছে!' ),
		'profile_ok'  => array( 'ok', 'প্রোফাইল আপডেট হয়েছে।' ),
		'pass_ok'     => array( 'ok', 'পাসওয়ার্ড বদলানো হয়েছে।' ),
		'avatar_ok'   => array( 'ok', 'ছবি আপডেট হয়েছে।' ),
		'err_closed'  => array( 'error', 'এই মুহূর্তে নতুন রেজিস্ট্রেশন বন্ধ আছে।' ),
		'err_rate'    => array( 'error', 'অনেকবার চেষ্টা হয়েছে। কিছুক্ষণ পর আবার চেষ্টা করুন।' ),
		'err_input'   => array( 'error', 'তথ্য সঠিক নয়, আবার চেষ্টা করুন।' ),
		'err_exists'  => array( 'error', 'এই তথ্য দিয়ে নতুন একাউন্ট খোলা গেল না। আগে একাউন্ট খুলে থাকলে লগইন বা পাসওয়ার্ড রিসেট করুন।' ),
		'err_pass'    => array( 'error', 'বর্তমান পাসওয়ার্ড ভুল বা নতুন পাসওয়ার্ড ছোট।' ),
		'err_image'   => array( 'error', 'শুধু JPG, PNG বা WebP ছবি (সর্বোচ্চ ২ MB) দেওয়া যাবে।' ),
		'err_session' => array( 'error', 'সেশনের মেয়াদ শেষ, আবার চেষ্টা করুন।' ),
	);
	return isset( $map[ $code ] ) ? $map[ $code ] : null;
}

/** Message box for ?meeha_msg= codes (no-JS round trips). */
function meeha_account_flash() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$code = isset( $_GET['meeha_msg'] ) ? sanitize_key( wp_unslash( $_GET['meeha_msg'] ) ) : '';
	$m    = $code ? meeha_account_msg_text( $code ) : null;
	return $m ? '<p class="m-acct__msg ' . ( 'ok' === $m[0] ? 'is-ok' : 'is-error' ) . '" role="status">' . esc_html( $m[1] ) . '</p>' : '';
}

function meeha_account_post_register() {
	if ( is_user_logged_in() ) {
		wp_safe_redirect( home_url( '/' ) );
		exit;
	}
	// Login-CSRF guard (the form sits on a cacheable page, so no nonce): a cross-site or `Origin: null`
	// POST never creates an account or sets an auth cookie.
	if ( ! meeha_legacy_same_origin() ) {
		meeha_legacy_redirect_back( array( 'meeha_msg' => 'err_session' ), meeha_get_account_page_url( 'register' ) );
	}
	$out = meeha_account_do_register( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	if ( $out[0] ) {
		wp_safe_redirect( ! empty( $out[1]['redirect'] ) ? $out[1]['redirect'] : home_url( '/' ) );
		exit;
	}
	$code = ! meeha_registration_open() ? 'err_closed' : ( isset( $out[1]['code'] ) && 'rate' === $out[1]['code'] ? 'err_rate' : ( isset( $out[1]['code'] ) && 'exists' === $out[1]['code'] ? 'err_exists' : 'err_input' ) );
	meeha_legacy_redirect_back( array( 'meeha_msg' => $code ), meeha_get_account_page_url( 'register' ) );
}
add_action( 'admin_post_nopriv_meeha_register', 'meeha_account_post_register' );
add_action( 'admin_post_meeha_register', 'meeha_account_post_register' );

function meeha_account_post_profile() {
	$back = meeha_get_account_page_url( 'my-account' );
	if ( ! is_user_logged_in() || ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'meeha_account_nonce' ) ) {
		meeha_legacy_redirect_back( array( 'meeha_msg' => 'err_session' ), $back );
	}
	$what = isset( $_POST['meeha_form'] ) ? sanitize_key( wp_unslash( $_POST['meeha_form'] ) ) : 'profile';
	$uid  = get_current_user_id();
	if ( 'password' === $what ) {
		$out = meeha_account_do_password( $uid, wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		meeha_legacy_redirect_back( array( 'meeha_msg' => $out[0] ? 'pass_ok' : 'err_pass', 'tab' => 'security' ), $back );
	}
	if ( 'avatar' === $what ) {
		$out = meeha_account_do_avatar( $uid, isset( $_FILES['avatar'] ) ? $_FILES['avatar'] : array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		meeha_legacy_redirect_back( array( 'meeha_msg' => $out[0] ? 'avatar_ok' : 'err_image' ), $back );
	}
	$out = meeha_account_do_profile( $uid, wp_unslash( $_POST ) );
	meeha_legacy_redirect_back( array( 'meeha_msg' => $out[0] ? 'profile_ok' : 'err_input' ), $back );
}
add_action( 'admin_post_meeha_account', 'meeha_account_post_profile' );
add_action(
	'admin_post_nopriv_meeha_account',
	function () {
		wp_safe_redirect( meeha_get_account_page_url( 'login' ) );
		exit;
	}
);

/* =====================================================================
   Assets: account bundle only on pages carrying an account/newsroom shortcode
===================================================================== */
function meeha_account_tags() {
	return array( 'meeha_login', 'meeha_register', 'meeha_my_account', 'meeha_lost_password', 'meeha_correspondent_apply', 'meeha_news_submit_form' );
}

function meeha_account_asset_enqueue() {
	if ( meeha_legacy_current_has( meeha_account_tags() ) ) {
		meeha_account_enqueue_now();
	}
}
add_action( 'wp_enqueue_scripts', 'meeha_account_asset_enqueue' );

function meeha_account_enqueue_now() {
	meeha_legacy_enqueue( 'account', array( 'account.css' ), array( 'account.js' ) );
}

/** Pages with account forms must never be served from a page cache. */
function meeha_account_nocache() {
	if ( meeha_legacy_current_has( array( 'meeha_login', 'meeha_register', 'meeha_my_account', 'meeha_lost_password' ) ) ) {
		nocache_headers();
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // LiteSpeed / WP Super Cache / W3TC honour this
		}
		do_action( 'litespeed_control_set_nocache', 'meeha account page' );
	}
}
add_action( 'template_redirect', 'meeha_account_nocache', 5 );

/* =====================================================================
   Shortcodes
===================================================================== */

/** Validated redirect_to from the current request ('' when absent/invalid). */
function meeha_account_requested_redirect() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$raw = isset( $_GET['redirect_to'] ) ? wp_unslash( $_GET['redirect_to'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	return $raw ? wp_validate_redirect( wp_sanitize_redirect( (string) $raw ), '' ) : '';
}

/** Password input with a show/hide toggle. */
function meeha_account_password_field( $id, $name, $label, $autocomplete, $minlength = 0 ) {
	return '<div class="m-field"><label class="m-field__label" for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>'
		. '<div class="m-field__pw"><input class="m-input" type="password" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" autocomplete="' . esc_attr( $autocomplete ) . '" required' . ( $minlength ? ' minlength="' . (int) $minlength . '"' : '' ) . '>'
		. '<button type="button" class="m-field__eye" data-pw-toggle aria-controls="' . esc_attr( $id ) . '" aria-pressed="false" hidden>দেখান</button></div></div>';
}

function meeha_account_logged_in_notice() {
	$redir = meeha_account_requested_redirect();
	$user  = wp_get_current_user();
	ob_start();
	?>
	<div class="m-acct"><div class="m-acct__card">
		<h2 class="m-acct__title">আপনি লগইন করা আছেন</h2>
		<p class="m-acct__sub"><?php echo esc_html( $user->display_name ); ?> হিসেবে লগইন করা আছে।</p>
		<div class="m-acct__actions">
			<?php if ( $redir ) : ?>
				<a class="m-btn" href="<?php echo esc_url( $redir ); ?>">চালিয়ে যান</a>
			<?php endif; ?>
			<a class="m-btn m-btn--ghost" href="<?php echo esc_url( meeha_get_account_page_url( 'my-account' ) ); ?>">আমার একাউন্ট</a>
			<a class="m-btn m-btn--ghost" href="<?php echo esc_url( home_url( '/' ) ); ?>">প্রথম পাতা</a>
		</div>
	</div></div>
	<?php
	return ob_get_clean();
}

function meeha_login_shortcode() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$reauth = ! empty( $_GET['reauth'] );
	if ( is_user_logged_in() && ! $reauth ) {
		return meeha_account_logged_in_notice();
	}
	meeha_account_enqueue_now();
	$redir = meeha_account_requested_redirect();
	ob_start();
	?>
	<div class="m-acct" data-meeha-account="login" data-rest="<?php echo esc_url( rest_url( 'meeha/v1/account/' ) ); ?>">
		<div class="m-acct__card">
			<h2 class="m-acct__title">লগইন করুন</h2>
			<p class="m-acct__sub">মিহা নিউজের একাউন্টে প্রবেশ করুন।</p>
			<?php echo meeha_render_social_buttons(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside ?>
			<form class="m-acct__form" id="meeha-login-form" method="post" action="<?php echo esc_url( site_url( 'wp-login.php', 'login_post' ) ); ?>" novalidate>
				<div class="m-field">
					<label class="m-field__label" for="m-login-user">ইউজারনেম অথবা ইমেইল</label>
					<input class="m-input" type="text" id="m-login-user" name="log" autocomplete="username" autocapitalize="off" spellcheck="false" required>
				</div>
				<?php echo meeha_account_password_field( 'm-login-pass', 'pwd', 'পাসওয়ার্ড', 'current-password' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<input type="hidden" name="rememberme" value="forever">
				<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redir ? $redir : home_url( '/' ) ); ?>">
				<button type="submit" class="m-btn m-btn--block">লগইন করুন</button>
				<p class="m-acct__msg" id="meeha-login-msg" role="status" aria-live="polite"></p>
			</form>

			<form class="m-acct__form" id="meeha-otp-form" hidden novalidate>
				<p class="m-acct__hint" id="meeha-otp-hint"></p>
				<input type="hidden" name="token" value="">
				<div class="m-field">
					<label class="m-field__label" for="m-otp-code">৬ সংখ্যার ওটিপি</label>
					<input class="m-input m-input--code" type="text" id="m-otp-code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9০-৯]{6}" required>
				</div>
				<button type="submit" class="m-btn m-btn--block">যাচাই করে লগইন করুন</button>
				<p class="m-acct__msg" id="meeha-otp-msg" role="status" aria-live="polite"></p>
				<p class="m-acct__links"><button type="button" class="m-linkbtn" id="meeha-otp-resend">ওটিপি আবার পাঠান</button><button type="button" class="m-linkbtn" id="meeha-otp-back">আবার লগইন করুন</button></p>
			</form>

			<p class="m-acct__links">
				<a href="<?php echo esc_url( meeha_get_account_page_url( 'lost-password' ) ); ?>">পাসওয়ার্ড ভুলে গেছেন?</a>
				<?php if ( meeha_registration_open() ) : ?>
					<a href="<?php echo esc_url( $redir ? add_query_arg( 'redirect_to', rawurlencode( $redir ), meeha_get_account_page_url( 'register' ) ) : meeha_get_account_page_url( 'register' ) ); ?>">নতুন একাউন্ট খুলুন</a>
				<?php endif; ?>
			</p>
		</div>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'meeha_login', 'meeha_login_shortcode' );

function meeha_register_shortcode() {
	if ( is_user_logged_in() ) {
		return meeha_account_logged_in_notice();
	}
	meeha_account_enqueue_now();
	$redir = meeha_account_requested_redirect();
	ob_start();
	?>
	<div class="m-acct" data-meeha-account="register" data-rest="<?php echo esc_url( rest_url( 'meeha/v1/account/' ) ); ?>">
		<div class="m-acct__card">
			<h2 class="m-acct__title">নতুন একাউন্ট</h2>
			<?php if ( ! meeha_registration_open() ) : ?>
				<p class="m-acct__msg is-error" role="status">এই মুহূর্তে নতুন রেজিস্ট্রেশন বন্ধ আছে।</p>
				<p class="m-acct__links"><a href="<?php echo esc_url( meeha_get_account_page_url( 'login' ) ); ?>">আগে থেকেই একাউন্ট আছে? লগইন করুন</a></p>
			<?php else : ?>
				<p class="m-acct__sub">মন্তব্য, ভোট ও নিজের এলাকার খবর — এক একাউন্টে।</p>
				<?php echo meeha_render_social_buttons(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<?php echo meeha_account_flash(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<form class="m-acct__form" id="meeha-register-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" novalidate>
					<input type="hidden" name="action" value="meeha_register">
					<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redir ); ?>">
					<div class="m-field">
						<label class="m-field__label" for="m-reg-name">আপনার নাম</label>
						<input class="m-input" type="text" id="m-reg-name" name="name" autocomplete="name" minlength="2" maxlength="60" required>
					</div>
					<div class="m-field">
						<label class="m-field__label" for="m-reg-email">ইমেইল</label>
						<input class="m-input" type="email" id="m-reg-email" name="email" autocomplete="email" inputmode="email" required>
					</div>
					<div class="m-field">
						<label class="m-field__label" for="m-reg-phone">ফোন নাম্বার <span class="m-field__opt">(ঐচ্ছিক)</span></label>
						<input class="m-input" type="tel" id="m-reg-phone" name="phone" autocomplete="tel" inputmode="tel" maxlength="20">
					</div>
					<?php echo meeha_account_password_field( 'm-reg-pass', 'password', 'পাসওয়ার্ড (কমপক্ষে ৮ অক্ষর)', 'new-password', 8 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<?php echo meeha_mailpoet_consent_field( 'm-reg' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<?php echo meeha_legacy_honeypot(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<button type="submit" class="m-btn m-btn--block">একাউন্ট খুলুন</button>
					<p class="m-acct__msg" id="meeha-register-msg" role="status" aria-live="polite"></p>
				</form>
				<p class="m-acct__links"><a href="<?php echo esc_url( meeha_get_account_page_url( 'login' ) ); ?>">আগে থেকেই একাউন্ট আছে? লগইন করুন</a></p>
			<?php endif; ?>
		</div>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'meeha_register', 'meeha_register_shortcode' );

function meeha_lost_password_shortcode() {
	meeha_account_enqueue_now();
	ob_start();
	?>
	<div class="m-acct" data-meeha-account="lost" data-rest="<?php echo esc_url( rest_url( 'meeha/v1/account/' ) ); ?>">
		<div class="m-acct__card">
			<h2 class="m-acct__title">পাসওয়ার্ড রিসেট</h2>
			<p class="m-acct__sub">একাউন্টের ইমেইল দিন — পাসওয়ার্ড বদলানোর লিংক পাঠানো হবে।</p>
			<form class="m-acct__form" id="meeha-lost-password-form" method="post" action="<?php echo esc_url( wp_lostpassword_url() ); ?>" novalidate>
				<div class="m-field">
					<label class="m-field__label" for="m-lost-email">ইমেইল</label>
					<input class="m-input" type="email" id="m-lost-email" name="user_login" autocomplete="email" inputmode="email" required>
				</div>
				<?php echo meeha_legacy_honeypot(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<button type="submit" class="m-btn m-btn--block">রিসেট লিংক পাঠান</button>
				<p class="m-acct__msg" id="meeha-lost-password-msg" role="status" aria-live="polite"></p>
			</form>
			<p class="m-acct__links"><a href="<?php echo esc_url( meeha_get_account_page_url( 'login' ) ); ?>">লগইন পেজে ফিরুন</a></p>
		</div>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'meeha_lost_password', 'meeha_lost_password_shortcode' );

function meeha_my_account_shortcode() {
	if ( ! is_user_logged_in() ) {
		return '<div class="m-acct"><div class="m-acct__card"><h2 class="m-acct__title">আমার একাউন্ট</h2><p class="m-acct__sub">দেখতে হলে আগে <a href="' . esc_url( meeha_get_account_page_url( 'login' ) ) . '">লগইন করুন</a>'
			. ( meeha_registration_open() ? ' অথবা <a href="' . esc_url( meeha_get_account_page_url( 'register' ) ) . '">নতুন একাউন্ট খুলুন</a>' : '' ) . '।</p></div></div>';
	}
	meeha_account_enqueue_now();
	$user  = wp_get_current_user();
	$phone = get_user_meta( $user->ID, 'meeha_phone', true );
	// Old ?tab=new/all/published/pending/requests links (removed correspondent tabs) open "profile".
	$requested = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$active    = 'security' === $requested ? 'security' : 'profile';
	$page_url  = remove_query_arg( array( 'tab', 'meeha_msg' ) );
	$nonce     = wp_create_nonce( 'meeha_account_nonce' );
	$post_url  = admin_url( 'admin-post.php' );
	$roles     = array_map( 'translate_user_role', array_intersect_key( wp_roles()->get_names(), array_flip( (array) $user->roles ) ) );
	ob_start();
	?>
	<div class="m-acct m-acct--wide" data-meeha-account="my" data-nonce="<?php echo esc_attr( $nonce ); ?>" data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">
		<div class="m-acct__head">
			<img src="<?php echo esc_url( get_avatar_url( $user->ID, array( 'size' => 96 ) ) ); ?>" alt="" width="64" height="64" class="m-acct__avatar" data-avatar-img>
			<div>
				<h2 class="m-acct__name"><?php echo esc_html( $user->display_name ); ?></h2>
				<p class="m-acct__email"><?php echo esc_html( $user->user_email ); ?><?php echo $roles ? ' · ' . esc_html( implode( ', ', $roles ) ) : ''; ?></p>
			</div>
			<a class="m-btn m-btn--ghost m-acct__logout" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>">লগআউট</a>
		</div>

		<nav class="m-acct__tabs" aria-label="একাউন্ট">
			<a href="<?php echo esc_url( add_query_arg( 'tab', 'profile', $page_url ) ); ?>" <?php echo 'profile' === $active ? 'aria-current="page"' : ''; ?>>প্রোফাইল</a>
			<a href="<?php echo esc_url( add_query_arg( 'tab', 'security', $page_url ) ); ?>" <?php echo 'security' === $active ? 'aria-current="page"' : ''; ?>>নিরাপত্তা</a>
		</nav>
		<?php echo meeha_account_flash(); // phpcs:ignore WordPress.Security.EscapeOutput ?>

		<?php if ( 'profile' === $active ) : ?>
			<section class="m-acct__panel" aria-label="প্রোফাইল">
				<?php
				if ( function_exists( 'meeha_newsroom_render_account_card' ) ) {
					meeha_newsroom_render_account_card( $user );
				}
				?>
				<form class="m-acct__card m-avatarbox" id="meeha-avatar-form" method="post" action="<?php echo esc_url( $post_url ); ?>" enctype="multipart/form-data">
					<img src="<?php echo esc_url( get_avatar_url( $user->ID, array( 'size' => 192 ) ) ); ?>" alt="আপনার ছবি" width="88" height="88" class="m-avatarbox__img" id="meeha-account-avatar-preview" data-avatar-img>
					<div class="m-avatarbox__body">
						<h3 class="m-acct__h">প্রোফাইল ছবি</h3>
						<p class="m-field__hint">JPG, PNG বা WebP · সর্বোচ্চ ২ MB · বর্গাকার ছবি ভালো দেখায়</p>
						<input type="hidden" name="action" value="meeha_account">
						<input type="hidden" name="meeha_form" value="avatar">
						<input type="hidden" name="nonce" value="<?php echo esc_attr( $nonce ); ?>">
						<label class="m-btn m-btn--ghost m-avatarbox__pick" for="meeha-avatar-input">ছবি বদলান</label>
						<input class="m-vh" type="file" name="avatar" id="meeha-avatar-input" accept="image/jpeg,image/png,image/webp">
						<noscript><button type="submit" class="m-btn">আপলোড</button></noscript>
						<p class="m-acct__msg" id="meeha-avatar-msg" role="status" aria-live="polite"></p>
					</div>
				</form>

				<form class="m-acct__card m-acct__form" id="meeha-profile-form" method="post" action="<?php echo esc_url( $post_url ); ?>">
					<h3 class="m-acct__h">ব্যক্তিগত তথ্য</h3>
					<input type="hidden" name="action" value="meeha_account">
					<input type="hidden" name="meeha_form" value="profile">
					<input type="hidden" name="nonce" value="<?php echo esc_attr( $nonce ); ?>">
					<div class="m-acct__grid">
						<div class="m-field">
							<label class="m-field__label" for="m-pf-name">নাম</label>
							<input class="m-input" type="text" id="m-pf-name" name="name" value="<?php echo esc_attr( $user->display_name ); ?>" autocomplete="name" maxlength="60">
						</div>
						<div class="m-field">
							<label class="m-field__label" for="m-pf-email">ইমেইল</label>
							<input class="m-input" type="email" id="m-pf-email" value="<?php echo esc_attr( $user->user_email ); ?>" disabled>
						</div>
						<div class="m-field">
							<label class="m-field__label" for="m-pf-phone">ফোন নাম্বার</label>
							<input class="m-input" type="tel" id="m-pf-phone" name="phone" value="<?php echo esc_attr( $phone ); ?>" autocomplete="tel" inputmode="tel" maxlength="20">
						</div>
						<?php if ( function_exists( 'meeha_2fa_required_for_user' ) && meeha_2fa_required_for_user( $user ) ) : ?>
							<div class="m-field">
								<label class="m-field__label" for="m-pf-2fa">লগইন ওটিপি কোন মাধ্যমে পাবেন</label>
								<select class="m-input" id="m-pf-2fa" name="meeha_2fa_method">
									<option value="email" <?php selected( get_user_meta( $user->ID, 'meeha_2fa_method', true ), 'email' ); ?>>ইমেইল</option>
									<?php if ( meeha_sms_is_ready() ) : ?>
										<option value="sms" <?php selected( get_user_meta( $user->ID, 'meeha_2fa_method', true ), 'sms' ); ?>>মোবাইল SMS (ফোন নাম্বার লাগবে)</option>
									<?php endif; ?>
								</select>
							</div>
						<?php endif; ?>
					</div>
					<fieldset class="m-field m-acct__area">
						<legend class="m-field__label">আমার এলাকা</legend>
						<div class="m-acct__selects">
							<?php
							meeha_render_area_fields(
								get_user_meta( $user->ID, 'meeha_division', true ),
								get_user_meta( $user->ID, 'meeha_district', true ),
								get_user_meta( $user->ID, 'meeha_upazila', true ),
								'meeha_user'
							);
							?>
						</div>
					</fieldset>
					<label class="m-check" for="m-pf-showarea"><input type="checkbox" id="m-pf-showarea" name="show_area" value="1" <?php checked( get_user_meta( $user->ID, 'meeha_show_area', true ) ); ?>> <span>আমার এলাকা দেখাতে চাই (মন্তব্যে নামের পাশে জেলা ও বিভাগ দেখাবে)</span></label>
					<button type="submit" class="m-btn">সংরক্ষণ করুন</button>
					<p class="m-acct__msg" id="meeha-profile-msg" role="status" aria-live="polite"></p>
				</form>
				<?php meeha_area_cascade_script(); ?>
			</section>
		<?php else : ?>
			<section class="m-acct__panel" aria-label="নিরাপত্তা">
				<form class="m-acct__card m-acct__form" id="meeha-password-form" method="post" action="<?php echo esc_url( $post_url ); ?>">
					<h3 class="m-acct__h">পাসওয়ার্ড বদলান</h3>
					<input type="hidden" name="action" value="meeha_account">
					<input type="hidden" name="meeha_form" value="password">
					<input type="hidden" name="nonce" value="<?php echo esc_attr( $nonce ); ?>">
					<input type="text" name="username" value="<?php echo esc_attr( $user->user_login ); ?>" autocomplete="username" hidden>
					<?php echo meeha_account_password_field( 'm-pw-cur', 'current_password', 'বর্তমান পাসওয়ার্ড', 'current-password' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<?php echo meeha_account_password_field( 'm-pw-new', 'new_password', 'নতুন পাসওয়ার্ড (কমপক্ষে ৮ অক্ষর)', 'new-password', 8 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<button type="submit" class="m-btn">পাসওয়ার্ড বদলান</button>
					<p class="m-acct__msg" id="meeha-password-msg" role="status" aria-live="polite"></p>
				</form>
				<?php if ( function_exists( 'meeha_2fa_required_for_user' ) && meeha_2fa_required_for_user( $user ) ) : ?>
					<div class="m-acct__card m-acct__note"><h3 class="m-acct__h">দুই ধাপের লগইন (ওটিপি)</h3><p>আপনার একাউন্টে ওটিপি চালু আছে। লগইনের সময় <?php echo 'sms' === meeha_2fa_method_for_user( $user ) ? 'মোবাইলে' : 'ইমেইলে'; ?> ৬ সংখ্যার কোড যাবে। মাধ্যম বদলাতে প্রোফাইল ট্যাব দেখুন।</p></div>
				<?php endif; ?>
			</section>
		<?php endif; ?>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'meeha_my_account', 'meeha_my_account_shortcode' );
