<?php
/**
 * Meeha News — Correspondent system (থিমের অংশ)
 *
 * Round 10: সংবাদদাতা আবেদন, অনুমোদন, "আমার একাউন্ট"-এর সংবাদদাতা ড্যাশবোর্ড,
 * নিউজ জমার ফর্ম ও সম্পাদনার অনুরোধের ফর্ম এখন সংবাদদাতা অ্যাপে
 * (https://correspondent.meeha.news) আর অনুমোদন/রোল ব্যবস্থাপনা পাবলিশার অ্যাপে
 * (newsroom-os প্লাগইন)। তাই সেই ফ্রন্টএন্ড/AJAX/অ্যাডমিন-অনুমোদন কোড এখান থেকে
 * সরানো হয়েছে। পুরনো শর্টকোড [meeha_correspondent_apply]/[meeha_news_submit_form]
 * থাকা পেজগুলো inc/meeha-newsroom-sync.php অ্যাপে রিডাইরেক্ট করে।
 *
 * এখানে যা থাকছে (অন্য অংশ এগুলোর ওপর নির্ভর করে):
 * - 'correspondent' রোল — newsroom-os প্লাগইন নিজে রোলটা তৈরি করে না, থিমের ওপর নির্ভর করে
 * - meeha_is_correspondent() হেল্পার
 * - wp_insert_post_data সেফটি-নেট — প্লাগইনের POST /submissions এর ওপর নির্ভর করে
 *   (লেখক = বর্তমান ইউজার, নন-পাবলিশার কখনো সরাসরি publish করতে পারে না)
 * - meeha_edit_req CPT (+ অ্যাডমিন কলাম/মেটাবক্স) — প্লাগইনের
 *   POST /submissions/{id}/edit-request এটাতেই অনুরোধ জমা রাখে
 * - "খবর প্রকাশিত হলে সংবাদদাতাকে ইমেইল" ও তার চালু/বন্ধ সেটিং
 * - আগে পাঠানো ইমেইল-কনফার্মেশন লিংক যেন কাজ করে
 *
 * Meta/option reference:
 * - user meta  meeha_author_title             : বাইলাইনের উপাধি (প্রোফাইল পেজ থেকে বদলানো যায়; প্লাগইনও পড়ে)
 * - user meta  meeha_correspondent_status     : পুরনো ফর্মের অবস্থা — এখন শুধু পড়া হয় (অ্যাডমিন তালিকায়)
 * - user meta  meeha_division/district/upazila: এলাকা (প্লাগইন ও প্রোফাইলও ব্যবহার করে)
 * - option     meeha_email_settings           : 'published_email_enabled' (বাকি কী-গুলো এখন অব্যবহৃত)
 * - CPT        meeha_edit_req                 : সম্পাদনার অনুরোধ (_meeha_edit_req_target, _meeha_edit_req_status)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Round 11 (theme `meeha`): ported unchanged except — admin page + CPT menus live under
 * "মিহা থিম" (slugs unchanged); the সংবাদদাতা page is also available to the publisher
 * (capability list_users = administrator + meeha_publisher); the verify-email notice is an
 * accessible banner instead of alert().
 */

/* ---------- role ---------- */

/**
 * Same capabilities as Contributor (can write, can't self-publish).
 * Checked on every `init` (cheap, WP caches roles) so no activation hook needed.
 */
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

/* ---------- helpers ---------- */

if ( ! function_exists( 'meeha_is_correspondent' ) ) {
	function meeha_is_correspondent( $user_id = 0 ) {
		$user_id = $user_id ? $user_id : get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}
		$user = get_userdata( $user_id );
		return $user && in_array( 'correspondent', (array) $user->roles, true );
	}
}

/**
 * ইমেইল নোটিফিকেশন সেটিং — এখন শুধু "খবর প্রকাশের মেইল" ব্যবহৃত হয়
 * (আবেদন-সংক্রান্ত মেইল প্লাগইন পাঠায়)। পুরনো অপশন অক্ষত রেখে পড়া হয়।
 */
function meeha_get_email_settings() {
	$settings = get_option( 'meeha_email_settings', array() );
	$settings = is_array( $settings ) ? $settings : array();
	return array(
		'published_email_enabled' => ! isset( $settings['published_email_enabled'] ) || $settings['published_email_enabled'],
	);
}

/* ---------- আগে পাঠানো ইমেইল কনফার্মেশন লিংক ---------- */
// নতুন লিংক আর তৈরি হয় না (আবেদন ফর্ম সরানো হয়েছে), কিন্তু পুরনো মেইলের লিংক
// কেউ ক্লিক করলে যেন কাজ করে।
function meeha_handle_email_verify_link() {
	if ( ! isset( $_GET['meeha_verify_email'], $_GET['token'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	$user_id = absint( $_GET['meeha_verify_email'] ); // phpcs:ignore WordPress.Security.NonceVerification
	$token   = sanitize_text_field( wp_unslash( $_GET['token'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
	$saved   = get_user_meta( $user_id, 'meeha_email_verify_token', true );

	if ( $user_id && $saved && hash_equals( $saved, $token ) ) {
		update_user_meta( $user_id, 'meeha_email_verified', '1' );
		delete_user_meta( $user_id, 'meeha_email_verify_token' );
		add_action( 'wp_body_open', function () {
			echo '<div class="m-container" role="status" style="padding-block:12px"><p style="margin:0;padding:10px 14px;border-radius:8px;background:var(--m-blue-50,#EEF4FB);color:var(--m-ink,#142030)">আপনার ইমেইল কনফার্ম করা হয়েছে। ধন্যবাদ!</p></div>';
		} );
	}
}
add_action( 'template_redirect', 'meeha_handle_email_verify_link' );

/* ---------- নিউজ প্রকাশ হলে লেখককে ইমেইল ---------- */
// পাবলিশার অ্যাপ থেকে অনুমোদন করলেও এটা চলে (প্লাগইন নিজে এই মেইল পাঠায় না)
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

/* ---------- admin page: মিহা থিম → সংবাদদাতা (slug meeha-correspondents) ---------- */
// আগে এখানে আবেদন অনুমোদন/বাতিল, উপাধি, প্রত্যাহার ও Forminator সেটিং ছিল —
// এখন সেগুলো পাবলিশার অ্যাপে (প্লাগইনের আবেদন টেবিল ও রোল ব্যবস্থাপনা)।
// উপাধি (meeha_author_title) ইউজারের প্রোফাইল পেজ থেকে বদলানো যায়।
// এখানে শুধু একটা নির্দেশনা, পুরনো ফর্মের অপেক্ষমাণ আবেদনের তালিকা (শুধু দেখার
// জন্য) আর "খবর প্রকাশের মেইল" সেটিং থাকছে।

function meeha_add_correspondent_admin_page() {
	add_submenu_page(
		meeha_legacy_menu_parent(),
		'সংবাদদাতা',
		'সংবাদদাতা',
		'list_users',
		'meeha-correspondents',
		'meeha_render_correspondent_admin_page'
	);
}
add_action( 'admin_menu', 'meeha_add_correspondent_admin_page', 20 );

function meeha_handle_correspondent_admin_actions() {
	if ( ! current_user_can( 'list_users' ) ) {
		return;
	}
	if ( isset( $_POST['meeha_correspondent_settings_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['meeha_correspondent_settings_nonce'] ) ), 'meeha_save_correspondent_settings' ) ) {
		$settings = get_option( 'meeha_email_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();
		$settings['published_email_enabled'] = isset( $_POST['published_email_enabled'] ) ? 1 : 0;
		update_option( 'meeha_email_settings', $settings );
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-success is-dismissible"><p>সেটিংস সংরক্ষণ করা হয়েছে।</p></div>';
		} );
	}
}
add_action( 'admin_init', 'meeha_handle_correspondent_admin_actions' );

function meeha_render_correspondent_admin_page() {
	if ( ! current_user_can( 'list_users' ) ) {
		return;
	}
	$app_url        = function_exists( 'meeha_correspondent_app_url' ) ? meeha_correspondent_app_url() : 'https://correspondent.meeha.news';
	$apply_url      = function_exists( 'meeha_correspondent_apply_url' ) ? meeha_correspondent_apply_url() : 'https://correspondent.meeha.news/apply';
	$legacy_pending = get_users( array(
		'meta_key'   => 'meeha_correspondent_status', // phpcs:ignore WordPress.DB.SlowDBQuery
		'meta_value' => 'pending', // phpcs:ignore WordPress.DB.SlowDBQuery
		'role__not_in' => array( 'correspondent' ),
	) );
	$email_settings = meeha_get_email_settings();
	?>
	<div class="wrap">
		<h1>সংবাদদাতা</h1>

		<div class="notice notice-info inline" style="margin:16px 0;">
			<p>
				সংবাদদাতা আবেদন এখন নেওয়া হয় <a href="<?php echo esc_url( $apply_url ); ?>" target="_blank" rel="noopener">সংবাদদাতা অ্যাপে</a>;
				আবেদন অনুমোদন, রোল ও সংবাদদাতা ব্যবস্থাপনা হয় পাবলিশার অ্যাপে।
				সংবাদদাতারা খবর লেখেন, পাঠান ও সম্পাদনার অনুরোধ করেন <a href="<?php echo esc_url( $app_url ); ?>" target="_blank" rel="noopener">সংবাদদাতা অ্যাপ</a> থেকে।
				বাইলাইনের উপাধি বদলাতে: Users → ইউজার এডিট → "Meeha প্রতিবেদক তথ্য" → "পদবি"।
			</p>
		</div>

		<h2 class="title">পুরনো ফর্মের অপেক্ষমাণ আবেদন (<?php echo esc_html( count( $legacy_pending ) ); ?>টি)</h2>
		<?php if ( empty( $legacy_pending ) ) : ?>
			<p>পুরনো থিম-ফর্ম থেকে আসা কোনো অপেক্ষমাণ আবেদন নেই।</p>
		<?php else : ?>
			<p class="description">এগুলো থিমের পুরনো আবেদন ফর্ম থেকে এসেছিল এবং প্লাগইনের আবেদন তালিকায় নেই। আবেদনকারীদের সংবাদদাতা অ্যাপে আবার আবেদন করতে বলুন (একই ইমেইল দিলে অনুমোদনের সময় তাদের বিদ্যমান একাউন্টই সংবাদদাতা হয়ে যাবে)।</p>
			<table class="widefat striped" style="max-width:900px;">
				<thead><tr><th>নাম</th><th>ইমেইল / ফোন</th><th>এলাকা</th><th>কারণ</th></tr></thead>
				<tbody>
					<?php foreach ( $legacy_pending as $u ) : ?>
						<tr>
							<td><a href="<?php echo esc_url( get_edit_user_link( $u->ID ) ); ?>"><?php echo esc_html( $u->display_name ); ?></a></td>
							<td><?php echo esc_html( $u->user_email ); ?><br /><?php echo esc_html( get_user_meta( $u->ID, 'meeha_phone', true ) ); ?></td>
							<td>
								<?php
								echo esc_html( implode( ', ', array_filter( array(
									get_user_meta( $u->ID, 'meeha_upazila', true ),
									get_user_meta( $u->ID, 'meeha_district', true ),
									get_user_meta( $u->ID, 'meeha_division', true ),
								) ) ) );
								?>
							</td>
							<td><?php echo esc_html( get_user_meta( $u->ID, 'meeha_correspondent_motivation', true ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<h2 class="title" style="margin-top:32px;">মেইল সেটিংস</h2>
		<form method="post">
			<?php wp_nonce_field( 'meeha_save_correspondent_settings', 'meeha_correspondent_settings_nonce' ); ?>
			<table class="form-table">
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

/* ---------- Edit Request CPT ---------- */
// newsroom-os প্লাগইনের POST /submissions/{id}/edit-request এই CPT-তেই অনুরোধ
// জমা রাখে; এডমিন wp-admin-এ দেখে "সমাধান হয়েছে" চিহ্নিত করেন।

function meeha_register_edit_request_cpt() {
	register_post_type( 'meeha_edit_req', array(
		'labels' => array(
			'name'          => 'সম্পাদনার অনুরোধ',
			'singular_name' => 'সম্পাদনার অনুরোধ',
			'all_items'     => 'সম্পাদনার অনুরোধ',
		),
		'public'             => false,
		'show_ui'            => true,
		'show_in_menu'       => meeha_legacy_menu_parent(),
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
		echo 'resolved' === $req_status ? '<span style="color:#1a7f37;font-weight:600;">সমাধান হয়েছে</span>' : '<span style="color:#CC2A1F;font-weight:600;">খোলা</span>';
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
		$st = sanitize_key( wp_unslash( $_POST['meeha_edit_req_status'] ) );
		update_post_meta( $post_id, '_meeha_edit_req_status', in_array( $st, array( 'open', 'resolved' ), true ) ? $st : 'open' );
	}
}
add_action( 'save_post_meeha_edit_req', 'meeha_save_edit_request_meta_box' );

/* ---------- নতুন পোস্টের সেফটি-নেট ---------- */
// newsroom-os প্লাগইনের POST /submissions এর ওপর নির্ভর করে: নন-পাবলিশার ইউজারের
// নতুন পোস্টে লেখক = বর্তমান ইউজার, আর publish/future হলে pending।
// (আগের Forminator-নির্দিষ্ট ফিল্টারটা সরানো হয়েছে — Forminator আর নিউজ জমার পথ
// নয়, আর এই সাধারণ ফিল্টার যেকোনো wp_insert_post()-এই প্রযোজ্য।)

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
