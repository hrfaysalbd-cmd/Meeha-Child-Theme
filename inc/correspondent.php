<?php
/**
 * Meeha News — Correspondent system
 *
 * - 'correspondent' role
 * - application form + admin approval
 * - approved title ("উপাধি") stored in existing meeha_author_title meta
 *   (single.php byline already displays it — no template change needed)
 * - My Account correspondent dashboard: new/all/published/pending/edit-requests
 * - Edit Request CPT (correspondents can't edit published posts directly)
 * - Forminator submission safety net (author + pending status)
 *
 * Meta/option reference:
 * - user meta  meeha_correspondent_status     : '' | 'pending' | 'approved' | 'rejected'
 * - user meta  meeha_author_title             : title shown in byline (pre-existing)
 * - user meta  meeha_correspondent_motivation : application reason
 * - user meta  meeha_division/district/upazila: area (pre-existing, reused)
 * - option     meeha_correspondent_settings   : array( 'forminator_shortcode', 'submit_page_url' )
 * - CPT        meeha_edit_req                 : edit requests
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ---------- role ---------- */

/**
 * Same capabilities as Contributor (can write, can't self-publish).
 * Checked on every `init` (cheap, WP caches roles) so no activation hook needed.
 */
/**
 * সব ধরনের ইমেইল নোটিফিকেশন এক জায়গা থেকে চালু/বন্ধ ও কাস্টমাইজ করার সেটিংস
 * (Admin > সংবাদদাতা ব্যবস্থাপনা > সেটিংস পেজে)
 */
function meeha_get_email_settings() {
	$settings = get_option( 'meeha_email_settings', array() );
	return array(
		'verify_enabled'          => ! isset( $settings['verify_enabled'] ) || $settings['verify_enabled'],
		'success_message'         => ! empty( $settings['success_message'] ) ? sanitize_textarea_field( $settings['success_message'] ) : 'আবেদন জমা হয়েছে! এডমিন অনুমোদনের পর ইমেইলে জানানো হবে।',
		'admin_notify_enabled'    => ! isset( $settings['admin_notify_enabled'] ) || $settings['admin_notify_enabled'],
		'approved_email_enabled'  => ! isset( $settings['approved_email_enabled'] ) || $settings['approved_email_enabled'],
		'rejected_email_enabled'  => ! isset( $settings['rejected_email_enabled'] ) || $settings['rejected_email_enabled'],
		'published_email_enabled' => ! isset( $settings['published_email_enabled'] ) || $settings['published_email_enabled'],
	);
}

/* ---------- ইমেইল কনফার্মেশন লিংক ক্যাচ করা ---------- */
function meeha_handle_email_verify_link() {
	if ( ! isset( $_GET['meeha_verify_email'], $_GET['token'] ) ) {
		return;
	}
	$user_id = absint( $_GET['meeha_verify_email'] );
	$token   = sanitize_text_field( wp_unslash( $_GET['token'] ) );
	$saved   = get_user_meta( $user_id, 'meeha_email_verify_token', true );

	if ( $user_id && $saved && hash_equals( $saved, $token ) ) {
		update_user_meta( $user_id, 'meeha_email_verified', '1' );
		delete_user_meta( $user_id, 'meeha_email_verify_token' );
		add_action( 'wp_footer', function () {
			?>
			<script>
			document.addEventListener('DOMContentLoaded', function () {
				alert('আপনার ইমেইল কনফার্ম করা হয়েছে। ধন্যবাদ!');
			});
			</script>
			<?php
		} );
	}
}
add_action( 'template_redirect', 'meeha_handle_email_verify_link' );

/* ---------- নিউজ প্রকাশ হলে লেখককে ইমেইল ---------- */
function meeha_notify_news_published( $new_status, $old_status, $post ) {
	if ( 'publish' !== $new_status || 'publish' === $old_status || 'post' !== $post->post_type ) {
		return;
	}
	if ( ! meeha_is_correspondent( $post->post_author ) ) {
		return;
	}
	$email_settings = meeha_get_email_settings();
	if ( empty( $email_settings['published_email_enabled'] ) ) {
		return;
	}
	$author = get_userdata( $post->post_author );
	if ( ! $author ) {
		return;
	}
	wp_mail(
		$author->user_email,
		'আপনার খবরটি প্রকাশিত হয়েছে',
		"সুসংবাদ, {$author->display_name}!\n\nআপনার লেখা \"{$post->post_title}\" খবরটি এখন Meeha News-এ প্রকাশিত হয়েছে।\n\nদেখুন: " . get_permalink( $post->ID )
	);
}
add_action( 'transition_post_status', 'meeha_notify_news_published', 10, 3 );

function meeha_register_correspondent_role() {
	if ( get_role( 'correspondent' ) ) {
		return;
	}
	$contributor = get_role( 'contributor' );
	$caps        = $contributor ? $contributor->capabilities : array(
		'read'         => true,
		'delete_posts' => true,
		'edit_posts'   => true,
	);
	add_role( 'correspondent', 'সংবাদদাতা (Correspondent)', $caps );
}
add_action( 'init', 'meeha_register_correspondent_role' );

/* ---------- settings ---------- */

function meeha_get_correspondent_settings() {
	$settings = get_option( 'meeha_correspondent_settings', array() );
	return array(
		'forminator_shortcode' => ! empty( $settings['forminator_shortcode'] ) ? $settings['forminator_shortcode'] : '',
		'submit_page_url'      => ! empty( $settings['submit_page_url'] ) ? esc_url_raw( $settings['submit_page_url'] ) : '',
	);
}

/* ---------- helpers ---------- */

function meeha_is_correspondent( $user_id = 0 ) {
	$user_id = $user_id ? $user_id : get_current_user_id();
	if ( ! $user_id ) {
		return false;
	}
	$user = get_userdata( $user_id );
	return $user && in_array( 'correspondent', (array) $user->roles, true );
}

function meeha_get_correspondent_status( $user_id ) {
	$status = get_user_meta( $user_id, 'meeha_correspondent_status', true );
	return $status ? $status : '';
}

/* ---------- application form [meeha_correspondent_apply] ---------- */
// logged out: full registration + application; logged in: area + reason only

function meeha_correspondent_apply_shortcode() {
	ob_start();

	if ( is_user_logged_in() ) {
		$user_id = get_current_user_id();

		if ( meeha_is_correspondent( $user_id ) ) {
			echo '<p class="meeha-account-msg">আপনি ইতিমধ্যে একজন অনুমোদিত সংবাদদাতা। <a href="' . esc_url( meeha_get_account_page_url( 'my-account' ) ) . '">আমার একাউন্টে যান</a>।</p>';
			return ob_get_clean();
		}

		$status = meeha_get_correspondent_status( $user_id );
		if ( 'pending' === $status ) {
			echo '<p class="meeha-account-msg">আপনার আবেদন জমা হয়েছে, এডমিনের অনুমোদনের অপেক্ষায় আছে। অনুমোদন হলে ইমেইলে জানানো হবে।</p>';
			return ob_get_clean();
		}
		?>
		<form class="meeha-account-form meeha-correspondent-form" id="meeha-correspondent-apply-form">
			<h2>সংবাদদাতা হিসেবে আবেদন করুন</h2>
			<?php if ( 'rejected' === $status ) : ?>
				<p class="meeha-account-msg">আপনার আগের আবেদনটি গৃহীত হয়নি। চাইলে আবার আবেদন করতে পারেন।</p>
			<?php endif; ?>
			<label>আপনার এলাকা
				<?php
				meeha_render_area_fields(
					get_user_meta( $user_id, 'meeha_division', true ),
					get_user_meta( $user_id, 'meeha_district', true ),
					get_user_meta( $user_id, 'meeha_upazila', true ),
					'meeha_user'
				);
				?>
			</label>
			<label>কেন সংবাদদাতা হতে চান? (সংক্ষেপে লিখুন)
				<textarea name="motivation" rows="4" required placeholder="যেমন: আমি বগুড়া জেলায় থাকি এবং স্থানীয় সংবাদ নিয়মিত কভার করতে চাই"></textarea>
			</label>
			<button type="submit" class="meeha-account-form__submit">আবেদন জমা দিন</button>
			<p class="meeha-account-msg" id="meeha-correspondent-apply-msg" role="status"></p>
		</form>
		<?php
		meeha_area_cascade_script();
		return ob_get_clean();
	}

	// logged out: full registration + application combined
	?>
	<form class="meeha-account-form meeha-correspondent-form" id="meeha-correspondent-apply-form">
		<h2>সংবাদদাতা হিসেবে যোগ দিন</h2>
		<input type="text" name="name" placeholder="আপনার নাম" required />
		<input type="email" name="email" placeholder="ইমেইল" required />
		<input type="tel" name="phone" placeholder="ফোন নাম্বার" required />
		<input type="password" name="password" placeholder="পাসওয়ার্ড" required minlength="6" />
		<label>আপনার এলাকা
			<?php meeha_render_area_fields( '', '', '', 'meeha_user' ); ?>
		</label>
		<label>কেন সংবাদদাতা হতে চান? (সংক্ষেপে লিখুন)
			<textarea name="motivation" rows="4" required placeholder="যেমন: আমি বগুড়া জেলায় থাকি এবং স্থানীয় সংবাদ নিয়মিত কভার করতে চাই"></textarea>
		</label>
		<button type="submit" class="meeha-account-form__submit">আবেদন জমা দিন</button>
		<p class="meeha-account-msg" id="meeha-correspondent-apply-msg" role="status"></p>
		<p class="meeha-account-links">
			<a href="<?php echo esc_url( meeha_get_account_page_url( 'login' ) ); ?>">আগে থেকেই একাউন্ট আছে? লগইন করে আবেদন করুন</a>
		</p>
	</form>
	<?php
	meeha_area_cascade_script();
	return ob_get_clean();
}
add_shortcode( 'meeha_correspondent_apply', 'meeha_correspondent_apply_shortcode' );

/* ---------- AJAX: submit application (logged in or not) ---------- */

function meeha_handle_ajax_correspondent_apply() {
	check_ajax_referer( 'meeha_account_nonce', 'nonce' );

	$motivation = isset( $_POST['motivation'] ) ? sanitize_textarea_field( wp_unslash( $_POST['motivation'] ) ) : '';
	if ( strlen( $motivation ) < 5 ) {
		wp_send_json_error( array( 'message' => 'কেন সংবাদদাতা হতে চান, একটু লিখুন।' ) );
	}

	if ( is_user_logged_in() ) {
		$user_id = get_current_user_id();
	} else {
		$name     = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$email    = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$phone    = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		$password = isset( $_POST['password'] ) ? $_POST['password'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		if ( ! is_email( $email ) || strlen( $password ) < 6 || strlen( $name ) < 2 || strlen( $phone ) < 6 ) {
			wp_send_json_error( array( 'message' => 'সব তথ্য সঠিকভাবে পূরণ করুন (পাসওয়ার্ড কমপক্ষে ৬ অক্ষর)।' ) );
		}
		if ( email_exists( $email ) ) {
			wp_send_json_error( array( 'message' => 'এই ইমেইল দিয়ে আগেই একাউন্ট আছে। আগে লগইন করুন।' ) );
		}

		$base_username = sanitize_user( current( explode( '@', $email ) ), true );
		if ( ! $base_username ) {
			$base_username = 'correspondent';
		}
		$username = $base_username;
		$suffix   = 1;
		while ( username_exists( $username ) ) {
			$username = $base_username . $suffix;
			$suffix++;
		}

		$user_id = wp_insert_user( array(
			'user_login'   => $username,
			'user_pass'    => $password,
			'user_email'   => $email,
			'display_name' => $name,
			'role'         => 'subscriber', // role changes to correspondent only after approval
		) );
		if ( is_wp_error( $user_id ) ) {
			wp_send_json_error( array( 'message' => 'একাউন্ট তৈরি করা যায়নি।' ) );
		}
		update_user_meta( $user_id, 'meeha_phone', $phone );
		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true );

		// ইমেইল কনফার্মেশন লিংক - একাউন্ট তৈরি/লগইন এখনই হয়ে যায় (আবেদন
		// প্রক্রিয়ায় বাধা না দিতে), কিন্তু ইমেইলটা আসলেই সঠিক ও অ্যাক্সেসযোগ্য
		// কিনা যাচাই করতে একটা আলাদা কনফার্মেশন লিংক পাঠানো হয়।
		$email_settings = meeha_get_email_settings();
		if ( $email_settings['verify_enabled'] ) {
			$token = wp_generate_password( 32, false );
			update_user_meta( $user_id, 'meeha_email_verify_token', $token );
			update_user_meta( $user_id, 'meeha_email_verified', '0' );
			$verify_url = add_query_arg(
				array(
					'meeha_verify_email' => $user_id,
					'token'              => $token,
				),
				home_url( '/' )
			);
			wp_mail(
				$email,
				'আপনার ইমেইল কনফার্ম করুন — Meeha News',
				"স্বাগতম, {$name}!\n\nআপনার একাউন্ট তৈরি হয়েছে। নিচের লিংকে ক্লিক করে আপনার ইমেইল ঠিকানাটা কনফার্ম করে নিন:\n\n{$verify_url}\n\nএই লিংকে ক্লিক না করলেও আপনার আবেদন প্রক্রিয়াধীন থাকবে, তবে ইমেইল কনফার্ম করা থাকলে ভবিষ্যতে পাসওয়ার্ড রিসেট ইত্যাদির জন্য সুবিধা হবে।"
			);
		} else {
			update_user_meta( $user_id, 'meeha_email_verified', '1' ); // ভেরিফিকেশন বন্ধ থাকলে সরাসরি ভেরিফায়েড ধরা হয়
		}
	}

	foreach ( array( 'division', 'district', 'upazila' ) as $field ) {
		if ( isset( $_POST[ 'meeha_user_' . $field ] ) ) {
			update_user_meta( $user_id, 'meeha_' . $field, sanitize_text_field( wp_unslash( $_POST[ 'meeha_user_' . $field ] ) ) );
		}
	}
	update_user_meta( $user_id, 'meeha_correspondent_motivation', $motivation );
	update_user_meta( $user_id, 'meeha_correspondent_status', 'pending' );

	$user = get_userdata( $user_id );
	$email_settings = meeha_get_email_settings();
	if ( $email_settings['admin_notify_enabled'] ) {
		wp_mail(
			get_option( 'admin_email' ),
			'নতুন সংবাদদাতা আবেদন — ' . $user->display_name,
			"নতুন একটা সংবাদদাতা আবেদন জমা হয়েছে।\n\nনাম: {$user->display_name}\nইমেইল: {$user->user_email}\n\nঅনুমোদন করতে এডমিন প্যানেলে যান: " . admin_url( 'admin.php?page=meeha-correspondents' )
		);
	}

	wp_send_json_success( array(
		'message'  => $email_settings['success_message'],
		'redirect' => meeha_get_account_page_url( 'my-account' ),
	) );
}
add_action( 'wp_ajax_meeha_ajax_correspondent_apply', 'meeha_handle_ajax_correspondent_apply' );
add_action( 'wp_ajax_nopriv_meeha_ajax_correspondent_apply', 'meeha_handle_ajax_correspondent_apply' );

/* ---------- admin approval page: Admin → Meeha হোমপেজ → সংবাদদাতা ---------- */

function meeha_add_correspondent_admin_page() {
	add_submenu_page(
		'meeha-homepage-settings',
		'সংবাদদাতা আবেদন',
		'সংবাদদাতা',
		'manage_options',
		'meeha-correspondents',
		'meeha_render_correspondent_admin_page'
	);
}
add_action( 'admin_menu', 'meeha_add_correspondent_admin_page' );

function meeha_handle_correspondent_admin_actions() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// save settings
	if ( isset( $_POST['meeha_correspondent_settings_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['meeha_correspondent_settings_nonce'] ) ), 'meeha_save_correspondent_settings' ) ) {
		update_option( 'meeha_correspondent_settings', array(
			'forminator_shortcode' => isset( $_POST['forminator_shortcode'] ) ? sanitize_text_field( wp_unslash( $_POST['forminator_shortcode'] ) ) : '',
			'submit_page_url'      => isset( $_POST['submit_page_url'] ) ? esc_url_raw( wp_unslash( $_POST['submit_page_url'] ) ) : '',
		) );
		update_option( 'meeha_email_settings', array(
			'verify_enabled'          => isset( $_POST['verify_enabled'] ) ? 1 : 0,
			'success_message'         => isset( $_POST['success_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['success_message'] ) ) : '',
			'admin_notify_enabled'    => isset( $_POST['admin_notify_enabled'] ) ? 1 : 0,
			'approved_email_enabled'  => isset( $_POST['approved_email_enabled'] ) ? 1 : 0,
			'rejected_email_enabled'  => isset( $_POST['rejected_email_enabled'] ) ? 1 : 0,
			'published_email_enabled' => isset( $_POST['published_email_enabled'] ) ? 1 : 0,
		) );
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-success is-dismissible"><p>সেটিংস সংরক্ষণ করা হয়েছে।</p></div>';
		} );
	}

	// approve application
	if ( isset( $_POST['meeha_approve_nonce'], $_POST['user_id'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['meeha_approve_nonce'] ) ), 'meeha_approve_correspondent' ) ) {
		$user_id = absint( $_POST['user_id'] );
		$title   = isset( $_POST['author_title'] ) ? sanitize_text_field( wp_unslash( $_POST['author_title'] ) ) : '';
		$user    = get_userdata( $user_id );
		if ( $user ) {
			$user_obj = new WP_User( $user_id );
			$user_obj->set_role( 'correspondent' );
			update_user_meta( $user_id, 'meeha_correspondent_status', 'approved' );
			if ( $title ) {
				update_user_meta( $user_id, 'meeha_author_title', $title );
			}
			$email_settings = meeha_get_email_settings();
			if ( $email_settings['approved_email_enabled'] ) {
				wp_mail(
					$user->user_email,
					'আপনার সংবাদদাতা আবেদন অনুমোদিত হয়েছে',
					"আপনার আবেদন অনুমোদিত হয়েছে! আপনি এখন থেকে '" . ( $title ? $title : 'সংবাদদাতা' ) . "' হিসেবে সংবাদ পাঠাতে পারবেন।\n\nআমার একাউন্টে যান: " . meeha_get_account_page_url( 'my-account' )
				);
			}
			add_action( 'admin_notices', function () use ( $user ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $user->display_name ) . '-কে সংবাদদাতা হিসেবে অনুমোদন করা হয়েছে।</p></div>';
			} );
		}
	}

	// reject application
	if ( isset( $_POST['meeha_reject_nonce'], $_POST['user_id'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['meeha_reject_nonce'] ) ), 'meeha_reject_correspondent' ) ) {
		$user_id = absint( $_POST['user_id'] );
		$user    = get_userdata( $user_id );
		if ( $user ) {
			update_user_meta( $user_id, 'meeha_correspondent_status', 'rejected' );
			$email_settings = meeha_get_email_settings();
			if ( $email_settings['rejected_email_enabled'] ) {
				wp_mail(
					$user->user_email,
					'আপনার সংবাদদাতা আবেদন সম্পর্কে',
					'দুঃখিত, আপনার সংবাদদাতা আবেদনটি এই মুহূর্তে গ্রহণ করা যায়নি।'
				);
			}
			add_action( 'admin_notices', function () use ( $user ) {
				echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html( $user->display_name ) . '-এর আবেদন বাতিল করা হয়েছে।</p></div>';
			} );
		}
	}

	// update title for an already-approved correspondent
	if ( isset( $_POST['meeha_update_title_nonce'], $_POST['user_id'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['meeha_update_title_nonce'] ) ), 'meeha_update_correspondent_title' ) ) {
		$user_id = absint( $_POST['user_id'] );
		$title   = isset( $_POST['author_title'] ) ? sanitize_text_field( wp_unslash( $_POST['author_title'] ) ) : '';
		update_user_meta( $user_id, 'meeha_author_title', $title );
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-success is-dismissible"><p>উপাধি আপডেট করা হয়েছে।</p></div>';
		} );
	}

	// revoke correspondent role back to subscriber
	if ( isset( $_POST['meeha_revoke_nonce'], $_POST['user_id'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['meeha_revoke_nonce'] ) ), 'meeha_revoke_correspondent' ) ) {
		$user_id = absint( $_POST['user_id'] );
		$user    = get_userdata( $user_id );
		if ( $user ) {
			$user_obj = new WP_User( $user_id );
			$user_obj->set_role( 'subscriber' );
			update_user_meta( $user_id, 'meeha_correspondent_status', 'rejected' );
			add_action( 'admin_notices', function () use ( $user ) {
				echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html( $user->display_name ) . '-এর সংবাদদাতা মর্যাদা প্রত্যাহার করা হয়েছে।</p></div>';
			} );
		}
	}
}
add_action( 'admin_init', 'meeha_handle_correspondent_admin_actions' );

function meeha_render_correspondent_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$settings = meeha_get_correspondent_settings();

	$pending_users = get_users( array(
		'meta_key'   => 'meeha_correspondent_status',
		'meta_value' => 'pending',
	) );
	$approved_users = get_users( array( 'role' => 'correspondent' ) );
	?>
	<div class="wrap">
		<h1>Meeha সংবাদদাতা ব্যবস্থাপনা</h1>

		<h2 class="title">নতুন আবেদন (<?php echo count( $pending_users ); ?>টি)</h2>
		<?php if ( empty( $pending_users ) ) : ?>
			<p>এই মুহূর্তে কোনো নতুন আবেদন নেই।</p>
		<?php else : ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th>নাম</th>
						<th>ইমেইল / ফোন</th>
						<th>এলাকা</th>
						<th>কারণ</th>
						<th style="width:340px;">উপাধি দিয়ে অনুমোদন করুন</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $pending_users as $u ) : ?>
						<tr>
							<td><?php echo esc_html( $u->display_name ); ?></td>
							<td>
								<?php echo esc_html( $u->user_email ); ?><br />
								<?php echo esc_html( get_user_meta( $u->ID, 'meeha_phone', true ) ); ?>
							</td>
							<td>
								<?php
								$area = array_filter( array(
									get_user_meta( $u->ID, 'meeha_upazila', true ),
									get_user_meta( $u->ID, 'meeha_district', true ),
									get_user_meta( $u->ID, 'meeha_division', true ),
								) );
								echo esc_html( implode( ', ', $area ) );
								?>
							</td>
							<td><?php echo esc_html( get_user_meta( $u->ID, 'meeha_correspondent_motivation', true ) ); ?></td>
							<td>
								<form method="post" style="display:flex; gap:6px; margin-bottom:6px;">
									<?php wp_nonce_field( 'meeha_approve_correspondent', 'meeha_approve_nonce' ); ?>
									<input type="hidden" name="user_id" value="<?php echo esc_attr( $u->ID ); ?>" />
									<input type="text" name="author_title" placeholder="যেমন: জেলা প্রতিনিধি, বগুড়া" class="regular-text" required />
									<button type="submit" class="button button-primary">অনুমোদন</button>
								</form>
								<form method="post" onsubmit="return confirm('আবেদনটি বাতিল করবেন?');">
									<?php wp_nonce_field( 'meeha_reject_correspondent', 'meeha_reject_nonce' ); ?>
									<input type="hidden" name="user_id" value="<?php echo esc_attr( $u->ID ); ?>" />
									<button type="submit" class="button">বাতিল করুন</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<h2 class="title" style="margin-top:32px;">অনুমোদিত সংবাদদাতা (<?php echo count( $approved_users ); ?>জন)</h2>
		<?php if ( empty( $approved_users ) ) : ?>
			<p>এখনো কোনো অনুমোদিত সংবাদদাতা নেই।</p>
		<?php else : ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th>নাম</th>
						<th>ইমেইল</th>
						<th style="width:260px;">উপাধি</th>
						<th style="width:140px;"></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $approved_users as $u ) : ?>
						<tr>
							<td><?php echo esc_html( $u->display_name ); ?> (<a href="<?php echo esc_url( get_edit_user_link( $u->ID ) ); ?>">প্রোফাইল</a>)</td>
							<td><?php echo esc_html( $u->user_email ); ?></td>
							<td>
								<form method="post" style="display:flex; gap:6px;">
									<?php wp_nonce_field( 'meeha_update_correspondent_title', 'meeha_update_title_nonce' ); ?>
									<input type="hidden" name="user_id" value="<?php echo esc_attr( $u->ID ); ?>" />
									<input type="text" name="author_title" value="<?php echo esc_attr( get_user_meta( $u->ID, 'meeha_author_title', true ) ); ?>" class="regular-text" />
									<button type="submit" class="button">আপডেট</button>
								</form>
							</td>
							<td>
								<form method="post" onsubmit="return confirm('সংবাদদাতা মর্যাদা প্রত্যাহার করবেন?');">
									<?php wp_nonce_field( 'meeha_revoke_correspondent', 'meeha_revoke_nonce' ); ?>
									<input type="hidden" name="user_id" value="<?php echo esc_attr( $u->ID ); ?>" />
									<button type="submit" class="button button-link-delete">প্রত্যাহার</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<h2 class="title" style="margin-top:32px;">সেটিংস</h2>
		<form method="post">
			<?php wp_nonce_field( 'meeha_save_correspondent_settings', 'meeha_correspondent_settings_nonce' ); ?>
			<table class="form-table">
				<tr>
					<th scope="row">নিউজ সাবমিশন পেজের URL</th>
					<td>
						<input type="url" name="submit_page_url" value="<?php echo esc_attr( $settings['submit_page_url'] ); ?>" class="regular-text" placeholder="https://meeha.news/my-writting/" />
						<p class="description">যে পেজে Forminator ফর্মটা বসানো আছে — "আমার একাউন্ট"-এর "নতুন নিউজ" ট্যাবের বাটন এখানে নিয়ে যাবে।</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Forminator ফর্ম শর্টকোড (ঐচ্ছিক)</th>
					<td>
						<input type="text" name="forminator_shortcode" value="<?php echo esc_attr( $settings['forminator_shortcode'] ); ?>" class="regular-text" placeholder='[forminator_form id="5"]' />
						<p class="description">
							দিলে ফর্মটা সরাসরি "আমার একাউন্ট"-এর "নতুন নিউজ" ট্যাবের ভেতরেই দেখাবে (আলাদা পেজে যেতে হবে না)।
							খালি রাখলে শুধু ওপরের URL-এ একটা বাটন দেখাবে।
						</p>
					</td>
				</tr>
			</table>
			<?php submit_button( 'সেটিংস সংরক্ষণ করুন' ); ?>

			<h2 class="title" style="margin-top:32px;">মেইল সেটিংস</h2>
			<?php $email_settings = meeha_get_email_settings(); ?>
			<table class="form-table">
				<tr>
					<th scope="row">ইমেইল কনফার্মেশন লিংক</th>
					<td>
						<label><input type="checkbox" name="verify_enabled" value="1" <?php checked( $email_settings['verify_enabled'] ); ?> /> রেজিস্ট্রেশনের পর ব্যবহারকারীর ইমেইলে একটা কনফার্মেশন লিংক পাঠান</label>
					</td>
				</tr>
				<tr>
					<th scope="row">কাস্টম সাকসেস মেসেজ</th>
					<td>
						<textarea name="success_message" rows="3" class="large-text"><?php echo esc_textarea( $email_settings['success_message'] ); ?></textarea>
						<p class="description">আবেদন সফলভাবে জমা হওয়ার পর ব্যবহারকারী এই লেখাটা দেখবেন।</p>
					</td>
				</tr>
				<tr>
					<th scope="row">নতুন আবেদনের মেইল (এডমিনকে)</th>
					<td><label><input type="checkbox" name="admin_notify_enabled" value="1" <?php checked( $email_settings['admin_notify_enabled'] ); ?> /> নতুন সংবাদদাতা আবেদন জমা হলে এডমিন ইমেইলে জানান</label></td>
				</tr>
				<tr>
					<th scope="row">আবেদন অনুমোদনের মেইল</th>
					<td><label><input type="checkbox" name="approved_email_enabled" value="1" <?php checked( $email_settings['approved_email_enabled'] ); ?> /> আবেদন অনুমোদন হলে ব্যবহারকারীকে ইমেইলে জানান</label></td>
				</tr>
				<tr>
					<th scope="row">আবেদন প্রত্যাখ্যানের মেইল</th>
					<td><label><input type="checkbox" name="rejected_email_enabled" value="1" <?php checked( $email_settings['rejected_email_enabled'] ); ?> /> আবেদন বাতিল হলে ব্যবহারকারীকে ইমেইলে জানান</label></td>
				</tr>
				<tr>
					<th scope="row">নিউজ প্রকাশের মেইল</th>
					<td><label><input type="checkbox" name="published_email_enabled" value="1" <?php checked( $email_settings['published_email_enabled'] ); ?> /> সংবাদদাতার লেখা প্রকাশিত হলে তাকে ইমেইলে জানান</label></td>
				</tr>
			</table>
			<?php submit_button( 'সেটিংস সংরক্ষণ করুন' ); ?>
		</form>
	</div>
	<?php
}

/* ---------- My Account correspondent dashboard ---------- */
// called from meeha_my_account_shortcode() (functions.php)

function meeha_render_correspondent_dashboard( $user ) {
	$status = meeha_get_correspondent_status( $user->ID );

	if ( ! meeha_is_correspondent( $user->ID ) ) {
		if ( 'pending' === $status ) {
			echo '<div class="meeha-correspondent-dashboard"><p class="meeha-account-msg">আপনার সংবাদদাতা আবেদন পর্যালোচনাধীন আছে। অনুমোদন হলে এখানেই "নতুন নিউজ" অপশন দেখতে পাবেন।</p></div>';
		} elseif ( 'rejected' === $status ) {
			echo '<div class="meeha-correspondent-dashboard"><p class="meeha-account-msg">আপনার সংবাদদাতা আবেদনটি গৃহীত হয়নি। <a href="' . esc_url( meeha_get_correspondent_apply_url() ) . '">আবার আবেদন করুন</a>।</p></div>';
		} else {
			echo '<div class="meeha-correspondent-dashboard"><p class="meeha-account-msg">সংবাদদাতা হিসেবে লিখতে চান? <a href="' . esc_url( meeha_get_correspondent_apply_url() ) . '">এখানে আবেদন করুন</a>।</p></div>';
		}
		return;
	}

	$title    = get_user_meta( $user->ID, 'meeha_author_title', true );
	$settings = meeha_get_correspondent_settings();
	$active   = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'new'; // phpcs:ignore WordPress.Security.NonceVerification
	$tabs     = array(
		'new'       => 'নতুন নিউজ',
		'all'       => 'সব নিউজ',
		'published' => 'প্রকাশিত',
		'pending'   => 'অপ্রকাশিত',
		'requests'  => 'সম্পাদনার অনুরোধ',
	);
	if ( ! isset( $tabs[ $active ] ) ) {
		$active = 'new';
	}
	?>
	<div class="meeha-correspondent-dashboard">
		<h2>সংবাদদাতা ড্যাশবোর্ড<?php if ( $title ) : ?> <span class="meeha-correspondent-dashboard__title">· <?php echo esc_html( $title ); ?></span><?php endif; ?></h2>

		<div class="meeha-correspondent-tabs" role="tablist">
			<?php foreach ( $tabs as $key => $label ) : ?>
				<a href="<?php echo esc_url( add_query_arg( 'tab', $key ) ); ?>" class="meeha-correspondent-tab<?php echo $key === $active ? ' is-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</div>

		<div class="meeha-correspondent-tab-panel">
			<?php
			if ( 'new' === $active ) {
				meeha_render_correspondent_new_news_tab( $settings );
			} elseif ( 'requests' === $active ) {
				meeha_render_correspondent_requests_tab( $user->ID );
			} else {
				$status_map = array(
					'all'       => array( 'publish', 'pending', 'draft', 'future' ),
					'published' => array( 'publish' ),
					'pending'   => array( 'pending', 'draft', 'future' ),
				);
				meeha_render_correspondent_news_list( $user->ID, $status_map[ $active ] );
			}
			?>
		</div>
	</div>
	<?php
}

function meeha_get_correspondent_apply_url() {
	// cached the same way as meeha_get_account_page_url() to avoid a page scan on every load
	$cached = get_transient( 'meeha_correspondent_apply_page' );
	if ( false !== $cached ) {
		return $cached;
	}
	$url       = home_url( '/' );
	$all_pages = get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'posts_per_page' => -1 ) );
	foreach ( $all_pages as $p ) {
		if ( has_shortcode( $p->post_content, 'meeha_correspondent_apply' ) ) {
			$url = get_permalink( $p );
			break;
		}
	}
	set_transient( 'meeha_correspondent_apply_page', $url, HOUR_IN_SECONDS );
	return $url;
}

function meeha_render_correspondent_new_news_tab( $settings ) {
	if ( $settings['forminator_shortcode'] ) {
		echo '<div class="meeha-correspondent-submit-form">';
		echo do_shortcode( $settings['forminator_shortcode'] );
		echo '</div>';
		return;
	}
	if ( $settings['submit_page_url'] ) {
		echo '<p>নতুন নিউজ লিখতে নিচের বাটনে ক্লিক করুন।</p>';
		echo '<a href="' . esc_url( $settings['submit_page_url'] ) . '" class="meeha-account-form__submit meeha-correspondent-submit-btn">নতুন নিউজ লিখুন</a>';
		return;
	}
	echo '<p class="meeha-account-msg">নিউজ সাবমিশন পেজ এখনো সেট করা হয়নি — এডমিনকে জানান।</p>';
}

function meeha_render_correspondent_news_list( $user_id, $statuses ) {
	$query = new WP_Query( array(
		'post_type'      => 'post',
		'post_status'    => $statuses,
		'author'         => $user_id,
		'posts_per_page' => 20,
		'orderby'        => 'date',
		'order'          => 'DESC',
		'no_found_rows'  => true,
	) );

	if ( ! $query->have_posts() ) {
		echo '<p class="meeha-account-msg">এখানে দেখানোর মতো কোনো নিউজ নেই।</p>';
		return;
	}

	$status_labels = array(
		'publish' => 'প্রকাশিত',
		'pending' => 'পর্যালোচনাধীন',
		'draft'   => 'খসড়া',
		'future'  => 'শিডিউল করা',
	);
	?>
	<table class="meeha-correspondent-news-table">
		<?php
		while ( $query->have_posts() ) :
			$query->the_post();
			$status = get_post_status();
			?>
			<tr>
				<td class="meeha-correspondent-news-table__title">
					<?php if ( 'publish' === $status ) : ?>
						<a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
					<?php else : ?>
						<?php the_title(); ?>
					<?php endif; ?>
				</td>
				<td><span class="meeha-correspondent-status meeha-correspondent-status--<?php echo esc_attr( $status ); ?>"><?php echo esc_html( isset( $status_labels[ $status ] ) ? $status_labels[ $status ] : $status ); ?></span></td>
				<td><?php echo esc_html( get_the_date() ); ?></td>
				<?php if ( 'publish' === $status ) : ?>
					<td><a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'requests', 'post_id' => get_the_ID() ) ) ); ?>">সম্পাদনার অনুরোধ পাঠান</a></td>
				<?php else : ?>
					<td></td>
				<?php endif; ?>
			</tr>
		<?php endwhile; ?>
	</table>
	<?php
	wp_reset_postdata();
}

/* ---------- Edit Request CPT ---------- */
// correspondents can't edit published posts directly, so they request changes;
// admin reviews in wp-admin and marks resolved

function meeha_register_edit_request_cpt() {
	register_post_type( 'meeha_edit_req', array(
		'labels' => array(
			'name'          => 'সম্পাদনার অনুরোধ',
			'singular_name' => 'সম্পাদনার অনুরোধ',
			'all_items'     => 'সম্পাদনার অনুরোধ',
		),
		'public'             => false,
		'show_ui'            => true,
		'show_in_menu'       => 'meeha-homepage-settings',
		'capability_type'    => 'post',
		'map_meta_cap'       => true,
		'supports'           => array( 'title', 'editor' ),
		'menu_icon'          => 'dashicons-edit',
	) );
}
add_action( 'init', 'meeha_register_edit_request_cpt' );

function meeha_edit_request_admin_columns( $columns ) {
	$columns['meeha_target'] = 'মূল পোস্ট';
	$columns['meeha_status'] = 'অবস্থা';
	return $columns;
}
add_filter( 'manage_meeha_edit_req_posts_columns', 'meeha_edit_request_admin_columns' );

function meeha_edit_request_admin_column_content( $column, $post_id ) {
	if ( 'meeha_target' === $column ) {
		$target_id = (int) get_post_meta( $post_id, '_meeha_edit_req_target', true );
		if ( $target_id && get_post( $target_id ) ) {
			echo '<a href="' . esc_url( get_edit_post_link( $target_id ) ) . '">' . esc_html( get_the_title( $target_id ) ) . '</a>';
		} else {
			echo '—';
		}
	}
	if ( 'meeha_status' === $column ) {
		$req_status = get_post_meta( $post_id, '_meeha_edit_req_status', true );
		$req_status = $req_status ? $req_status : 'open';
		echo 'resolved' === $req_status ? '<span style="color:#146356;font-weight:600;">সমাধান হয়েছে</span>' : '<span style="color:#B33B2E;font-weight:600;">খোলা</span>';
	}
}
add_action( 'manage_meeha_edit_req_posts_custom_column', 'meeha_edit_request_admin_column_content', 10, 2 );

function meeha_add_edit_request_meta_box() {
	add_meta_box( 'meeha_edit_req_status_box', 'অবস্থা', 'meeha_render_edit_request_meta_box', 'meeha_edit_req', 'side' );
}
add_action( 'add_meta_boxes', 'meeha_add_edit_request_meta_box' );

function meeha_render_edit_request_meta_box( $post ) {
	wp_nonce_field( 'meeha_save_edit_req_status', 'meeha_edit_req_nonce' );
	$req_status = get_post_meta( $post->ID, '_meeha_edit_req_status', true );
	$req_status = $req_status ? $req_status : 'open';
	?>
	<select name="meeha_edit_req_status" style="width:100%;">
		<option value="open" <?php selected( $req_status, 'open' ); ?>>খোলা</option>
		<option value="resolved" <?php selected( $req_status, 'resolved' ); ?>>সমাধান হয়েছে</option>
	</select>
	<?php
}

function meeha_save_edit_request_meta_box( $post_id ) {
	if ( ! isset( $_POST['meeha_edit_req_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['meeha_edit_req_nonce'] ) ), 'meeha_save_edit_req_status' ) ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	if ( isset( $_POST['meeha_edit_req_status'] ) ) {
		update_post_meta( $post_id, '_meeha_edit_req_status', sanitize_key( wp_unslash( $_POST['meeha_edit_req_status'] ) ) );
	}
}
add_action( 'save_post_meeha_edit_req', 'meeha_save_edit_request_meta_box' );

/**
 * Correspondent's own request list + form (target must be one of their own
 * published posts, so requests can't target arbitrary posts)
 */
function meeha_render_correspondent_requests_tab( $user_id ) {
	$preselect = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification

	$published = get_posts( array(
		'post_type'      => 'post',
		'post_status'    => 'publish',
		'author'         => $user_id,
		'posts_per_page' => 100,
		'orderby'        => 'date',
		'order'          => 'DESC',
	) );
	?>
	<?php if ( ! empty( $published ) ) : ?>
	<form class="meeha-account-form" id="meeha-edit-request-form">
		<label>কোন নিউজ নিয়ে অনুরোধ?
			<select name="target_post_id" required>
				<option value="">বাছাই করুন</option>
				<?php foreach ( $published as $p ) : ?>
					<option value="<?php echo esc_attr( $p->ID ); ?>" <?php selected( $preselect, $p->ID ); ?>><?php echo esc_html( $p->post_title ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<label>কী পরিবর্তন দরকার?
			<textarea name="request_note" rows="4" required placeholder="যেমন: তৃতীয় প্যারাগ্রাফে একটা তথ্য ভুল আছে, ঠিক করা দরকার"></textarea>
		</label>
		<button type="submit" class="meeha-account-form__submit">অনুরোধ পাঠান</button>
		<p class="meeha-account-msg" id="meeha-edit-request-msg" role="status"></p>
	</form>
	<?php else : ?>
		<p class="meeha-account-msg">আপনার এখনো কোনো প্রকাশিত নিউজ নেই, তাই সম্পাদনার অনুরোধ পাঠানোর কিছু নেই।</p>
	<?php endif; ?>

	<?php
	$requests = get_posts( array(
		'post_type'      => 'meeha_edit_req',
		'post_status'    => 'publish',
		'author'         => $user_id,
		'posts_per_page' => 20,
		'orderby'        => 'date',
		'order'          => 'DESC',
	) );
	if ( $requests ) :
		?>
		<h3 style="margin-top:24px;">আপনার পাঠানো অনুরোধ</h3>
		<table class="meeha-correspondent-news-table">
			<?php foreach ( $requests as $r ) :
				$target_id  = (int) get_post_meta( $r->ID, '_meeha_edit_req_target', true );
				$req_status = get_post_meta( $r->ID, '_meeha_edit_req_status', true );
				$req_status = $req_status ? $req_status : 'open';
				?>
				<tr>
					<td class="meeha-correspondent-news-table__title"><?php echo esc_html( $target_id ? get_the_title( $target_id ) : $r->post_title ); ?></td>
					<td><span class="meeha-correspondent-status meeha-correspondent-status--<?php echo 'resolved' === $req_status ? 'publish' : 'pending'; ?>"><?php echo 'resolved' === $req_status ? 'সমাধান হয়েছে' : 'খোলা'; ?></span></td>
					<td><?php echo esc_html( get_the_date( '', $r ) ); ?></td>
				</tr>
			<?php endforeach; ?>
		</table>
	<?php endif; ?>
	<?php
}

function meeha_handle_ajax_edit_request() {
	check_ajax_referer( 'meeha_account_nonce', 'nonce' );
	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => 'লগইন করুন।' ) );
	}
	$user_id   = get_current_user_id();
	$target_id = isset( $_POST['target_post_id'] ) ? absint( $_POST['target_post_id'] ) : 0;
	$note      = isset( $_POST['request_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['request_note'] ) ) : '';

	$target = get_post( $target_id );
	// must be the requester's own published post
	if ( ! $target || 'publish' !== $target->post_status || (int) $target->post_author !== $user_id ) {
		wp_send_json_error( array( 'message' => 'সঠিক পোস্ট বাছাই করুন।' ) );
	}
	if ( strlen( $note ) < 5 ) {
		wp_send_json_error( array( 'message' => 'কী পরিবর্তন দরকার, একটু লিখুন।' ) );
	}

	$req_id = wp_insert_post( array(
		'post_type'    => 'meeha_edit_req',
		'post_title'   => 'সম্পাদনার অনুরোধ: ' . $target->post_title,
		'post_content' => $note,
		'post_status'  => 'publish',
		'post_author'  => $user_id,
	) );
	if ( is_wp_error( $req_id ) ) {
		wp_send_json_error( array( 'message' => 'অনুরোধ পাঠানো যায়নি।' ) );
	}
	update_post_meta( $req_id, '_meeha_edit_req_target', $target_id );
	update_post_meta( $req_id, '_meeha_edit_req_status', 'open' );

	wp_mail(
		get_option( 'admin_email' ),
		'সম্পাদনার অনুরোধ — ' . $target->post_title,
		"একজন সংবাদদাতা একটা পোস্ট সম্পাদনার অনুরোধ পাঠিয়েছেন।\n\nপোস্ট: {$target->post_title}\nঅনুরোধ: {$note}\n\nদেখুন: " . admin_url( 'edit.php?post_type=meeha_edit_req' )
	);

	wp_send_json_success( array( 'message' => 'অনুরোধ পাঠানো হয়েছে। এডমিন দেখে সমাধান করবেন।' ) );
}
add_action( 'wp_ajax_meeha_ajax_edit_request', 'meeha_handle_ajax_edit_request' );

/* ---------- Forminator safety net ---------- */
// Forminator's own "Post Author" field mapping can be misconfigured/fixed to
// an admin ID, so wp_insert_post_data always re-checks: author = actual
// logged-in user, and non-publishers always land as pending.

function meeha_force_correspondent_post_author_and_status( $data, $postarr ) {
	// only on new posts, not later edits (don't undo admin status changes)
	if ( ! empty( $postarr['ID'] ) ) {
		return $data;
	}
	if ( 'post' !== $data['post_type'] || ! is_user_logged_in() ) {
		return $data;
	}

	$current_user_id = get_current_user_id();

	// admins/editors: no restriction
	if ( user_can( $current_user_id, 'publish_posts' ) ) {
		return $data;
	}

	$data['post_author'] = $current_user_id;
	if ( 'publish' === $data['post_status'] || 'future' === $data['post_status'] ) {
		$data['post_status'] = 'pending';
	}
	return $data;
}
add_filter( 'wp_insert_post_data', 'meeha_force_correspondent_post_author_and_status', 10, 2 );

/**
 * Forminator's own filter (only fires if Forminator is active) — sets
 * post_author explicitly regardless of the form's "Post Author" setting.
 */
function meeha_forminator_force_post_author( $post_array ) {
	if ( is_user_logged_in() && ! user_can( get_current_user_id(), 'publish_posts' ) ) {
		$post_array['post_author'] = get_current_user_id();
	}
	return $post_array;
}
add_filter( 'forminator_custom_form_submit_field_post_data', 'meeha_forminator_force_post_author' );

/* ---------- gated submission page [meeha_news_submit_form] ---------- */
// use in place of the raw Forminator shortcode on /my-writting/ to require
// login + correspondent approval before showing the form

function meeha_news_submit_form_shortcode() {
	if ( ! is_user_logged_in() ) {
		ob_start();
		?>
		<p class="meeha-account-msg">নিউজ পাঠাতে হলে আগে লগইন করতে হবে। <a href="<?php echo esc_url( meeha_get_account_page_url( 'login' ) ); ?>">লগইন করুন</a> অথবা <a href="<?php echo esc_url( meeha_get_correspondent_apply_url() ); ?>">সংবাদদাতা হিসেবে আবেদন করুন</a>।</p>
		<?php
		return ob_get_clean();
	}
	if ( ! meeha_is_correspondent() ) {
		$status = meeha_get_correspondent_status( get_current_user_id() );
		ob_start();
		if ( 'pending' === $status ) {
			echo '<p class="meeha-account-msg">আপনার সংবাদদাতা আবেদন এখনো পর্যালোচনাধীন। অনুমোদন হলে এখান থেকেই নিউজ পাঠাতে পারবেন।</p>';
		} else {
			echo '<p class="meeha-account-msg">নিউজ পাঠাতে হলে অনুমোদিত সংবাদদাতা হতে হবে। <a href="' . esc_url( meeha_get_correspondent_apply_url() ) . '">এখানে আবেদন করুন</a>।</p>';
		}
		return ob_get_clean();
	}

	$settings = meeha_get_correspondent_settings();
	if ( ! $settings['forminator_shortcode'] ) {
		return '<p class="meeha-account-msg">ফর্ম এখনো কনফিগার করা হয়নি — এডমিনকে জানান।</p>';
	}
	return do_shortcode( $settings['forminator_shortcode'] );
}
add_shortcode( 'meeha_news_submit_form', 'meeha_news_submit_form_shortcode' );

/* ---------- asset enqueue ---------- */

function meeha_correspondent_asset_enqueue() {
	global $post;
	if ( ! is_a( $post, 'WP_Post' ) ) {
		return;
	}
	$shortcodes = array( 'meeha_correspondent_apply', 'meeha_my_account', 'meeha_news_submit_form' );
	$has_any    = false;
	foreach ( $shortcodes as $sc ) {
		if ( has_shortcode( $post->post_content, $sc ) ) {
			$has_any = true;
			break;
		}
	}
	if ( ! $has_any ) {
		return;
	}
	// meeha_correspondent_apply / meeha_news_submit_form pages aren't in
	// functions.php's own account-asset check list, so .meeha-account-msg/
	// .meeha-account-form base styles are enqueued here too (same handle,
	// WP dedups if already loaded elsewhere on the page)
	wp_enqueue_style(
		'meeha-account-style',
		MEEHA_CHILD_URI . '/assets/css/meeha-account.css',
		array(),
		MEEHA_CHILD_VERSION
	);
	wp_enqueue_style(
		'meeha-correspondent-style',
		MEEHA_CHILD_URI . '/assets/css/meeha-correspondent.css',
		array( 'meeha-account-style' ),
		MEEHA_CHILD_VERSION
	);
	wp_enqueue_script(
		'meeha-correspondent-script',
		MEEHA_CHILD_URI . '/assets/js/meeha-correspondent.js',
		array(),
		MEEHA_CHILD_VERSION,
		true
	);
	wp_add_inline_script(
		'meeha-correspondent-script',
		'window.meehaAccountAjax = window.meehaAccountAjax || { url: "' . esc_url( admin_url( 'admin-ajax.php' ) ) . '", nonce: "' . esc_js( wp_create_nonce( 'meeha_account_nonce' ) ) . '" };',
		'before'
	);
}
add_action( 'wp_enqueue_scripts', 'meeha_correspondent_asset_enqueue' );
