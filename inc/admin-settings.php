<?php
/**
 * Meeha হোমপেজ সেটিংস প্যানেল
 *
 * এডমিন ড্যাশবোর্ডে "Meeha হোমপেজ" নামে একটা মেনু তৈরি হবে, যেখান থেকে
 * এডিটর কোড না ছুঁয়েই হোমপেজের প্রতিটা সেকশনে কোন ক্যাটাগরি, কতগুলো পোস্ট
 * দেখাবে তা ঠিক করতে পারবেন।
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * অ্যাডমিন মেনুতে যোগ করা
 */
function meeha_add_homepage_settings_page() {
	add_menu_page(
		'Meeha হোমপেজ সেটিংস',
		'Meeha হোমপেজ',
		'manage_options',
		'meeha-homepage-settings',
		'meeha_render_homepage_settings_page',
		'dashicons-layout',
		59 // Appearance-এর ঠিক নিচে বসবে
	);

	add_submenu_page(
		'meeha-homepage-settings',
		'Meeha হোমপেজ সেটিংস',
		'হোমপেজ সেটিংস',
		'manage_options',
		'meeha-homepage-settings',
		'meeha_render_homepage_settings_page'
	);

	global $meeha_header_settings_hook;
	$meeha_header_settings_hook = add_submenu_page(
		'meeha-homepage-settings',
		'Meeha হেডার সেটিংস',
		'হেডার',
		'manage_options',
		'meeha-header-settings',
		'meeha_render_header_settings_page'
	);

	add_submenu_page(
		'meeha-homepage-settings',
		'Meeha সিঙ্গেল পোস্ট সেটিংস',
		'সিঙ্গেল পোস্ট',
		'manage_options',
		'meeha-single-settings',
		'meeha_render_single_settings_page'
	);

	global $meeha_photocard_settings_hook;
	$meeha_photocard_settings_hook = add_submenu_page(
		'meeha-homepage-settings',
		'Meeha ফটোকার্ড সেটিংস',
		'ফটোকার্ড',
		'manage_options',
		'meeha-photocard-settings',
		'meeha_render_photocard_settings_page'
	);
}
add_action( 'admin_menu', 'meeha_add_homepage_settings_page' );

/**
 * ফটোকার্ড সেটিংস পেজেই শুধু মিডিয়া আপলোডার (wp.media) লোড হবে,
 * যাতে বাকি অ্যাডমিন পেজে বাড়তি স্ক্রিপ্ট না যোগ হয়। হুক-নেম হার্ডকোড না করে
 * add_submenu_page()-এর প্রকৃত রিটার্ন ভ্যালুর সাথে মিলিয়ে দেখা হচ্ছে, যাতে
 * ভবিষ্যতে WordPress-এর নেমিং ভিন্ন হলেও এটা ভেঙে না যায়।
 */
function meeha_enqueue_photocard_admin_assets( $hook ) {
	global $meeha_photocard_settings_hook;
	if ( $hook !== $meeha_photocard_settings_hook ) {
		return;
	}
	wp_enqueue_media();
}
add_action( 'admin_enqueue_scripts', 'meeha_enqueue_photocard_admin_assets' );

/**
 * হেডার সেটিংস পেজেই মিডিয়া আপলোডার লোড হবে (বিজ্ঞাপন-ব্যানারের ছবি বাছাইয়ের জন্য)
 */
function meeha_enqueue_header_admin_assets( $hook ) {
	global $meeha_header_settings_hook;
	if ( $hook !== $meeha_header_settings_hook ) {
		return;
	}
	wp_enqueue_media();
}
add_action( 'admin_enqueue_scripts', 'meeha_enqueue_header_admin_assets' );

/**
 * হেডার সেটিংস ফর্ম সাবমিট হ্যান্ডল করা
 */
function meeha_handle_header_settings_save() {
	if ( ! isset( $_POST['meeha_header_nonce_field'] ) ) {
		return;
	}
	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['meeha_header_nonce_field'] ) ), 'meeha_save_header_settings' ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$settings = array(
		'topbar_enabled'   => isset( $_POST['topbar_enabled'] ) ? 1 : 0,
		'search_enabled'   => isset( $_POST['search_enabled'] ) ? 1 : 0,
		'account_enabled'  => isset( $_POST['account_enabled'] ) ? 1 : 0,
		'sticky_enabled'   => isset( $_POST['sticky_enabled'] ) ? 1 : 0,
		'desktop_menu_limit' => isset( $_POST['desktop_menu_limit'] ) ? max( 3, min( 20, absint( $_POST['desktop_menu_limit'] ) ) ) : 8,
		'mobile_compact_items' => isset( $_POST['mobile_compact_items'] ) && is_array( $_POST['mobile_compact_items'] )
			? array_map( 'absint', wp_unslash( $_POST['mobile_compact_items'] ) )
			: array(),
		'social'           => array(
			'facebook'  => isset( $_POST['social_facebook'] ) ? esc_url_raw( wp_unslash( $_POST['social_facebook'] ) ) : '',
			'youtube'   => isset( $_POST['social_youtube'] ) ? esc_url_raw( wp_unslash( $_POST['social_youtube'] ) ) : '',
			'twitter'   => isset( $_POST['social_twitter'] ) ? esc_url_raw( wp_unslash( $_POST['social_twitter'] ) ) : '',
			'instagram' => isset( $_POST['social_instagram'] ) ? esc_url_raw( wp_unslash( $_POST['social_instagram'] ) ) : '',
			'whatsapp'  => isset( $_POST['social_whatsapp'] ) ? esc_url_raw( wp_unslash( $_POST['social_whatsapp'] ) ) : '',
		),
		'ad_enabled'       => isset( $_POST['ad_enabled'] ) ? 1 : 0,
		'ad_size'          => ( isset( $_POST['ad_size'] ) && 'compact' === $_POST['ad_size'] ) ? 'compact' : 'default',
		'ad_image_desktop' => isset( $_POST['ad_image_desktop'] ) ? esc_url_raw( wp_unslash( $_POST['ad_image_desktop'] ) ) : '',
		'ad_image_mobile'  => isset( $_POST['ad_image_mobile'] ) ? esc_url_raw( wp_unslash( $_POST['ad_image_mobile'] ) ) : '',
		'ad_link'          => isset( $_POST['ad_link'] ) ? esc_url_raw( wp_unslash( $_POST['ad_link'] ) ) : '',
		'ad_alt'           => isset( $_POST['ad_alt'] ) ? sanitize_text_field( wp_unslash( $_POST['ad_alt'] ) ) : 'বিজ্ঞাপন',
		'ad_label_enabled' => isset( $_POST['ad_label_enabled'] ) ? 1 : 0,
	);

	update_option( 'meeha_header_settings', $settings );

	add_action( 'admin_notices', function () {
		echo '<div class="notice notice-success is-dismissible"><p>হেডার সেটিংস সংরক্ষণ করা হয়েছে। মেনু বসাতে Appearance → Menus-এ গিয়ে "Meeha হেডার মেনু (প্রাইমারি)" লোকেশনে আপনার মেনু অ্যাসাইন করুন।</p></div>';
	} );
}
add_action( 'admin_init', 'meeha_handle_header_settings_save' );

/**
 * হেডার সেটিংস পেজের HTML
 */
function meeha_render_header_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$settings = meeha_get_header_settings();
	?>
	<div class="wrap">
		<h1>Meeha হেডার সেটিংস</h1>
		<p>
			ওয়েবসাইটের হেডার (লোগো, মেনু, সার্চ, বিজ্ঞাপন ব্যানার) এখান থেকে নিয়ন্ত্রণ করুন।
			প্রাইমারি মেনু নির্বাচনের জন্য
			<a href="<?php echo esc_url( admin_url( 'nav-menus.php' ) ); ?>">Appearance → Menus</a>-এ গিয়ে
			আপনার তৈরি করা মেনুটা <strong>"Meeha হেডার মেনু (প্রাইমারি)"</strong> লোকেশনে অ্যাসাইন করে দিন।
			মেনু অ্যাসাইন করা না থাকলে সাময়িকভাবে টপ ক্যাটাগরিগুলো স্বয়ংক্রিয়ভাবে দেখানো হবে।
			লোগো বসাতে <a href="<?php echo esc_url( admin_url( 'customize.php' ) ); ?>">Customize → Site Identity</a> ব্যবহার করুন।
		</p>

		<form method="post">
			<?php wp_nonce_field( 'meeha_save_header_settings', 'meeha_header_nonce_field' ); ?>

			<h2 class="title">সাধারণ</h2>
			<table class="form-table">
				<tr>
					<th scope="row">টপ বার</th>
					<td><label><input type="checkbox" name="topbar_enabled" value="1" <?php checked( $settings['topbar_enabled'] ); ?> /> লোগোর ওপরে তারিখ, সোশ্যাল আইকন ও লগইন লিংক-সহ সরু বার দেখান</label></td>
				</tr>
				<tr>
					<th scope="row">সার্চ</th>
					<td><label><input type="checkbox" name="search_enabled" value="1" <?php checked( $settings['search_enabled'] ); ?> /> হেডারে সার্চ আইকন/বক্স দেখান</label></td>
				</tr>
				<tr>
					<th scope="row">লগইন / একাউন্ট</th>
					<td><label><input type="checkbox" name="account_enabled" value="1" <?php checked( $settings['account_enabled'] ); ?> /> লগইন/একাউন্ট আইকন দেখান</label></td>
				</tr>
				<tr>
					<th scope="row">স্টিকি হেডার</th>
					<td><label><input type="checkbox" name="sticky_enabled" value="1" <?php checked( $settings['sticky_enabled'] ); ?> /> স্ক্রল করলেও হেডার ওপরে আটকে থাকবে</label></td>
				</tr>
				<tr>
					<th scope="row">ডেস্কটপে সরাসরি মেনু আইটেম</th>
					<td>
						<input type="number" name="desktop_menu_limit" min="3" max="20" value="<?php echo esc_attr( $settings['desktop_menu_limit'] ); ?>" class="small-text" />
						<p class="description">
							এই সংখ্যার পরের মেনু আইটেমগুলো "আরো দেখুন" বাটনের ভেতরে
							(সাজানো-গোছানো গ্রিড প্যানেলে) দেখাবে, যাতে অনেক আইটেম থাকলেও
							লোগো/সার্চ আইকন চাপা না পড়ে। প্রস্তাবিত মান: ৬–৯।
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">মোবাইলে ভাসমান কম্প্যাক্ট মেনু</th>
					<td>
						<?php $meeha_top_items = meeha_get_primary_menu_top_items(); ?>
						<?php if ( empty( $meeha_top_items ) ) : ?>
							<p class="description">
								আগে <a href="<?php echo esc_url( admin_url( 'nav-menus.php' ) ); ?>">Appearance → Menus</a>-এ
								গিয়ে "Meeha হেডার মেনু (প্রাইমারি)" লোকেশনে একটা মেনু অ্যাসাইন করুন,
								তারপর এখানে ফিরে এসে কোন আইটেমগুলো মোবাইলে ভাসমান দেখাবেন সেটা বাছাই করতে পারবেন।
							</p>
						<?php else : ?>
							<?php foreach ( $meeha_top_items as $meeha_item ) : ?>
								<label style="display:inline-block;margin:0 16px 8px 0;">
									<input type="checkbox" name="mobile_compact_items[]" value="<?php echo esc_attr( $meeha_item->ID ); ?>"
										<?php checked( in_array( (int) $meeha_item->ID, $settings['mobile_compact_items'], true ) ); ?> />
									<?php echo esc_html( $meeha_item->title ); ?>
								</label>
							<?php endforeach; ?>
							<p class="description">
								মোবাইলে হেডারের দ্বিতীয় সারিতে এখান থেকে বাছাই করা আইটেমগুলো ভাসমান
								(scroll করা যায় এমন) বাটন আকারে দেখাবে, শেষে থাকবে হ্যামবার্গার আইকন
								(পুরো মেনু খুলতে)। মোবাইল স্ক্রিনে জায়গা কম, তাই ৩–৫টার বেশি না রাখাই
								ভালো। মেনুতে নতুন আইটেম/সাব-মেনু যোগ করলে তালিকা এখানে নিজে থেকেই
								আপডেট হয়ে যাবে (Appearance → Menus বদলানোর সাথে সাথে)।
							</p>
						<?php endif; ?>
					</td>
				</tr>
			</table>

			<h2 class="title">সোশ্যাল লিংক (টপ বার ও মোবাইল মেনুতে দেখাবে)</h2>
			<p class="description">যেগুলো খালি রাখবেন, সেগুলোর আইকন দেখাবে না।</p>
			<table class="form-table">
				<tr>
					<th scope="row">ফেসবুক</th>
					<td><input type="url" name="social_facebook" value="<?php echo esc_attr( $settings['social']['facebook'] ); ?>" class="regular-text" placeholder="https://facebook.com/yourpage" /></td>
				</tr>
				<tr>
					<th scope="row">ইউটিউব</th>
					<td><input type="url" name="social_youtube" value="<?php echo esc_attr( $settings['social']['youtube'] ); ?>" class="regular-text" placeholder="https://youtube.com/@yourchannel" /></td>
				</tr>
				<tr>
					<th scope="row">এক্স (টুইটার)</th>
					<td><input type="url" name="social_twitter" value="<?php echo esc_attr( $settings['social']['twitter'] ); ?>" class="regular-text" placeholder="https://x.com/yourhandle" /></td>
				</tr>
				<tr>
					<th scope="row">ইনস্টাগ্রাম</th>
					<td><input type="url" name="social_instagram" value="<?php echo esc_attr( $settings['social']['instagram'] ); ?>" class="regular-text" placeholder="https://instagram.com/yourhandle" /></td>
				</tr>
				<tr>
					<th scope="row">হোয়াটসঅ্যাপ</th>
					<td><input type="url" name="social_whatsapp" value="<?php echo esc_attr( $settings['social']['whatsapp'] ); ?>" class="regular-text" placeholder="https://wa.me/8801XXXXXXXXX" /></td>
				</tr>
			</table>

			<h2 class="title">হেডারের নিচের বিজ্ঞাপন ব্যানার</h2>
			<table class="form-table">
				<tr>
					<th scope="row">চালু রাখুন</th>
					<td><label><input type="checkbox" name="ad_enabled" value="1" <?php checked( $settings['ad_enabled'] ); ?> /> হেডারের ঠিক নিচে একটা বিজ্ঞাপনের ছবি দেখান</label></td>
				</tr>
				<tr>
					<th scope="row">ব্যানার সাইজ</th>
					<td>
						<label style="margin-right:18px;"><input type="radio" name="ad_size" value="default" <?php checked( $settings['ad_size'], 'default' ); ?> /> সাধারণ (leaderboard, প্রায় ৯৭০×৯০, পূর্ণ প্রস্থ)</label><br />
						<label><input type="radio" name="ad_size" value="compact" <?php checked( $settings['ad_size'], 'compact' ); ?> /> ছোট্ট/কমপ্যাক্ট (যেমন ৩০০×১০০, মাঝে ছোট একটা বক্সে)</label>
					</td>
				</tr>
				<tr>
					<th scope="row">ডেস্কটপ ছবি (leaderboard, যেমন 970×90 বা 728×90 — JPG/PNG/GIF, অ্যানিমেটেড GIF-ও চলবে)</th>
					<td>
						<img id="meeha-header-ad-desktop-preview" src="<?php echo esc_url( $settings['ad_image_desktop'] ); ?>"
							style="max-width:340px;max-height:90px;display:<?php echo $settings['ad_image_desktop'] ? 'block' : 'none'; ?>;margin-bottom:10px;border:1px solid #ddd;" />
						<input type="hidden" id="ad_image_desktop" name="ad_image_desktop" value="<?php echo esc_attr( $settings['ad_image_desktop'] ); ?>" />
						<p>
							<button type="button" class="button meeha-ad-select" data-target="ad_image_desktop" data-preview="meeha-header-ad-desktop-preview">ছবি বাছাই করুন</button>
							<button type="button" class="button meeha-ad-remove" data-target="ad_image_desktop" data-preview="meeha-header-ad-desktop-preview" style="<?php echo $settings['ad_image_desktop'] ? '' : 'display:none;'; ?>">সরিয়ে দিন</button>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">মোবাইল ছবি (ঐচ্ছিক, যেমন 320×100 — GIF-ও চলবে)</th>
					<td>
						<img id="meeha-header-ad-mobile-preview" src="<?php echo esc_url( $settings['ad_image_mobile'] ); ?>"
							style="max-width:220px;max-height:100px;display:<?php echo $settings['ad_image_mobile'] ? 'block' : 'none'; ?>;margin-bottom:10px;border:1px solid #ddd;" />
						<input type="hidden" id="ad_image_mobile" name="ad_image_mobile" value="<?php echo esc_attr( $settings['ad_image_mobile'] ); ?>" />
						<p>
							<button type="button" class="button meeha-ad-select" data-target="ad_image_mobile" data-preview="meeha-header-ad-mobile-preview">ছবি বাছাই করুন</button>
							<button type="button" class="button meeha-ad-remove" data-target="ad_image_mobile" data-preview="meeha-header-ad-mobile-preview" style="<?php echo $settings['ad_image_mobile'] ? '' : 'display:none;'; ?>">সরিয়ে দিন</button>
						</p>
						<p class="description">খালি রাখলে ডেস্কটপ ছবিটাই মোবাইলে ছোট আকারে দেখাবে।</p>
					</td>
				</tr>
				<tr>
					<th scope="row">লিংক (ঐচ্ছিক)</th>
					<td><input type="url" name="ad_link" value="<?php echo esc_attr( $settings['ad_link'] ); ?>" class="regular-text" placeholder="https://advertiser-site.com" /></td>
				</tr>
				<tr>
					<th scope="row">Alt টেক্সট</th>
					<td><input type="text" name="ad_alt" value="<?php echo esc_attr( $settings['ad_alt'] ); ?>" class="regular-text" /></td>
				</tr>
				<tr>
					<th scope="row">"বিজ্ঞাপন" লেবেল</th>
					<td><label><input type="checkbox" name="ad_label_enabled" value="1" <?php checked( $settings['ad_label_enabled'] ); ?> /> ছবির ওপরে ছোট করে "বিজ্ঞাপন" লেখা দেখান</label></td>
				</tr>
			</table>

			<?php submit_button( 'সেটিংস সংরক্ষণ করুন' ); ?>
		</form>
	</div>

	<script>
	( function () {
		var frames = {};
		document.querySelectorAll( '.meeha-ad-select' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				var targetId  = btn.getAttribute( 'data-target' );
				var previewId = btn.getAttribute( 'data-preview' );
				var input     = document.getElementById( targetId );
				var preview   = document.getElementById( previewId );

				if ( ! frames[ targetId ] ) {
					frames[ targetId ] = wp.media( {
						title: 'বিজ্ঞাপনের ছবি বা GIF বাছাই করুন',
						button: { text: 'এই ছবিটি ব্যবহার করুন' },
						library: { type: 'image' }, // images only - jpg/png/gif/webp, animated GIF fully supported
						multiple: false,
					} );
					frames[ targetId ].on( 'select', function () {
						var attachment = frames[ targetId ].state().get( 'selection' ).first().toJSON();
						input.value = attachment.url;
						preview.src = attachment.url;
						preview.style.display = 'block';
						var removeBtn = document.querySelector( '.meeha-ad-remove[data-target="' + targetId + '"]' );
						if ( removeBtn ) { removeBtn.style.display = 'inline-block'; }
					} );
				}
				frames[ targetId ].open();
			} );
		} );

		document.querySelectorAll( '.meeha-ad-remove' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				var targetId  = btn.getAttribute( 'data-target' );
				var previewId = btn.getAttribute( 'data-preview' );
				document.getElementById( targetId ).value = '';
				var preview = document.getElementById( previewId );
				preview.style.display = 'none';
				btn.style.display = 'none';
			} );
		} );
	} )();
	</script>
	<?php
}

/**
 * ফটোকার্ড সেটিংস ফর্ম সাবমিট হ্যান্ডল করা
 */
function meeha_handle_photocard_settings_save() {
	if ( ! isset( $_POST['meeha_photocard_nonce_field'] ) ) {
		return;
	}
	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['meeha_photocard_nonce_field'] ) ), 'meeha_save_photocard_settings' ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$settings = array(
		'footer_ad_enabled' => isset( $_POST['footer_ad_enabled'] ) ? 1 : 0,
		'footer_ad_image'   => isset( $_POST['footer_ad_image'] ) ? esc_url_raw( wp_unslash( $_POST['footer_ad_image'] ) ) : '',
		'poll_duration_days' => isset( $_POST['poll_duration_days'] ) ? absint( $_POST['poll_duration_days'] ) : 7,
	);

	update_option( 'meeha_photocard_settings', $settings );

	add_action( 'admin_notices', function () {
		echo '<div class="notice notice-success is-dismissible"><p>ফটোকার্ড সেটিংস সংরক্ষণ করা হয়েছে। এখন থেকে সব ফটোকার্ডের (অটো + ম্যানুয়াল) ফুটারে এটা প্রতিফলিত হবে।</p></div>';
	} );
}
add_action( 'admin_init', 'meeha_handle_photocard_settings_save' );

/**
 * ফটোকার্ড সেটিংস পেজের HTML
 */
function meeha_render_photocard_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$settings  = meeha_get_photocard_settings();
	$ad_image  = $settings['footer_ad_image'];
	?>
	<div class="wrap">
		<h1>Meeha ফটোকার্ড সেটিংস</h1>
		<p>
			প্রতিটা আর্টিকেলে অটোমেটিক তৈরি হওয়া সোশ্যাল মিডিয়া ফটোকার্ড (ফিচার ইমেজ + টাইটেল + লোগো)
			এবং ম্যানুয়ালি বানানো কোটেশন-কার্ড — দুটোতেই এখান থেকে ফুটার বিজ্ঞাপন চালু/বন্ধ করা যাবে।
			চালু করলে <strong>সব ফটোকার্ডের ফুটারেই</strong> একসাথে দেখাবে।
		</p>

		<form method="post">
			<?php wp_nonce_field( 'meeha_save_photocard_settings', 'meeha_photocard_nonce_field' ); ?>

			<table class="form-table">
				<tr>
					<th scope="row">ফুটার বিজ্ঞাপন চালু রাখুন</th>
					<td>
						<label>
							<input type="checkbox" name="footer_ad_enabled" value="1" <?php checked( $settings['footer_ad_enabled'] ); ?> />
							প্রতিটা ফটোকার্ডের নিচে বিজ্ঞাপনের ছবি জুড়ে দিন
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row">বিজ্ঞাপনের ছবি</th>
					<td>
						<img id="meeha-ad-preview" src="<?php echo esc_url( $ad_image ); ?>"
							style="max-width:280px;max-height:100px;display:<?php echo $ad_image ? 'block' : 'none'; ?>;margin-bottom:10px;border:1px solid #ddd;" />
						<input type="hidden" id="footer_ad_image" name="footer_ad_image" value="<?php echo esc_attr( $ad_image ); ?>" />
						<p>
							<button type="button" class="button" id="meeha-ad-select">ছবি বাছাই করুন</button>
							<button type="button" class="button" id="meeha-ad-remove" style="<?php echo $ad_image ? '' : 'display:none;'; ?>">সরিয়ে দিন</button>
						</p>
						<p class="description">প্রশস্ত (landscape) ছবি ভালো দেখায় — ফটোকার্ডের ফুটার ব্যান্ডে এটা পুরো প্রস্থ জুড়ে বসবে। মিডিয়া লাইব্রেরিতে আপলোড করা ছবিই ব্যবহার করুন, বাইরের CDN লিংক দিলে ডাউনলোড ব্যর্থ হতে পারে।</p>
					</td>
				</tr>
				<tr>
					<th scope="row">পোলের মেয়াদ (দিন)</th>
					<td>
						<input type="number" min="1" max="90" name="poll_duration_days" value="<?php echo esc_attr( $settings['poll_duration_days'] ); ?>" style="width:80px;" />
						<p class="description">উক্তির সাথে যোগ করা প্রতিটা ভোট/পোল কতদিন খোলা থাকবে (ডিফল্ট ৭ দিন)। মেয়াদ শেষ হওয়ার আগে কাউকে ফলাফল দেখানো হয় না।</p>
					</td>
				</tr>
			</table>

			<?php submit_button( 'সেটিংস সংরক্ষণ করুন' ); ?>
		</form>
	</div>

	<script>
	( function () {
		var frame;
		var selectBtn  = document.getElementById( 'meeha-ad-select' );
		var removeBtn  = document.getElementById( 'meeha-ad-remove' );
		var input      = document.getElementById( 'footer_ad_image' );
		var preview    = document.getElementById( 'meeha-ad-preview' );

		selectBtn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			if ( frame ) {
				frame.open();
				return;
			}
			frame = wp.media( {
				title: 'বিজ্ঞাপনের ছবি বাছাই করুন',
				button: { text: 'এই ছবিটি ব্যবহার করুন' },
				multiple: false,
			} );
			frame.on( 'select', function () {
				var attachment = frame.state().get( 'selection' ).first().toJSON();
				input.value = attachment.url;
				preview.src = attachment.url;
				preview.style.display = 'block';
				removeBtn.style.display = 'inline-block';
			} );
			frame.open();
		} );

		removeBtn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			input.value = '';
			preview.style.display = 'none';
		} );
	} )();
	</script>
	<?php
}

/**
 * সিঙ্গেল পোস্ট সেটিংস ফর্ম সাবমিট হ্যান্ডল করা
 */
function meeha_handle_single_settings_save() {
	if ( ! isset( $_POST['meeha_single_nonce'] ) ) {
		return;
	}
	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['meeha_single_nonce'] ) ), 'meeha_save_single_settings' ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$settings = array(
		'sidebar_enabled'       => isset( $_POST['sidebar_enabled'] ) ? 1 : 0,
		'share_buttons_enabled' => isset( $_POST['share_buttons_enabled'] ) ? 1 : 0,
		'author_box_enabled'    => isset( $_POST['author_box_enabled'] ) ? 1 : 0,
		'related_posts_enabled' => isset( $_POST['related_posts_enabled'] ) ? 1 : 0,
		'progress_bar_enabled'  => isset( $_POST['progress_bar_enabled'] ) ? 1 : 0,
		'readmore_enabled'      => isset( $_POST['readmore_enabled'] ) ? 1 : 0,
		'readmore_height'       => isset( $_POST['readmore_height'] ) ? (int) $_POST['readmore_height'] : 900,
		'comments_enabled'      => isset( $_POST['comments_enabled'] ) ? 1 : 0,
		'comments_heading'      => isset( $_POST['comments_heading'] ) ? sanitize_text_field( wp_unslash( $_POST['comments_heading'] ) ) : 'মন্তব্য করুন',
		'font_step_percent'     => isset( $_POST['font_step_percent'] ) ? (int) $_POST['font_step_percent'] : 10,
	);

	update_option( 'meeha_single_settings', $settings );

	add_action( 'admin_notices', function () {
		echo '<div class="notice notice-success is-dismissible"><p>সিঙ্গেল পোস্ট সেটিংস সংরক্ষণ করা হয়েছে।</p></div>';
	} );
}
add_action( 'admin_init', 'meeha_handle_single_settings_save' );

/**
 * সিঙ্গেল পোস্ট সেটিংস পেজের HTML
 */
function meeha_render_single_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$settings = get_option( 'meeha_single_settings', array() );

	// চেকবক্স ডিফল্ট মান বের করার হেল্পার — কখনো সেভ না হলেও ডিফল্ট "চালু" দেখাবে
	$is_checked = function ( $key ) use ( $settings ) {
		return ( ! isset( $settings[ $key ] ) || $settings[ $key ] ) ? 'checked' : '';
	};
	?>
	<div class="wrap">
		<h1>Meeha সিঙ্গেল পোস্ট সেটিংস</h1>
		<p>আর্টিকেল পেজের কোন কোন অংশ দেখাবেন, তা এখান থেকে ঠিক করুন — পরিবর্তন সাথে সাথে লাইভ হবে।</p>

		<form method="post">
			<?php wp_nonce_field( 'meeha_save_single_settings', 'meeha_single_nonce' ); ?>

			<h2 class="title">অংশ চালু/বন্ধ</h2>
			<table class="form-table">
				<tr>
					<th scope="row">সাইডবার</th>
					<td><label><input type="checkbox" name="sidebar_enabled" value="1" <?php echo esc_attr( $is_checked( 'sidebar_enabled' ) ); ?> /> ডেস্কটপে সাম্প্রতিক খবর + সর্বাধিক পঠিত সাইডবার দেখান</label></td>
				</tr>
				<tr>
					<th scope="row">শেয়ার বাটন</th>
					<td><label><input type="checkbox" name="share_buttons_enabled" value="1" <?php echo esc_attr( $is_checked( 'share_buttons_enabled' ) ); ?> /> ফেসবুক/হোয়াটসঅ্যাপ/এক্স/লিংক-কপি বাটন দেখান</label></td>
				</tr>
				<tr>
					<th scope="row">লেখক বক্স</th>
					<td><label><input type="checkbox" name="author_box_enabled" value="1" <?php echo esc_attr( $is_checked( 'author_box_enabled' ) ); ?> /> লেখকের বায়ো লেখা থাকলে আর্টিকেলের শেষে দেখান</label></td>
				</tr>
				<tr>
					<th scope="row">প্রাসঙ্গিক খবর</th>
					<td><label><input type="checkbox" name="related_posts_enabled" value="1" <?php echo esc_attr( $is_checked( 'related_posts_enabled' ) ); ?> /> একই ক্যাটাগরির আরও খবর দেখান</label></td>
				</tr>
				<tr>
					<th scope="row">রিডিং প্রোগ্রেস বার</th>
					<td><label><input type="checkbox" name="progress_bar_enabled" value="1" <?php echo esc_attr( $is_checked( 'progress_bar_enabled' ) ); ?> /> পেজের ওপরে স্ক্রল-প্রোগ্রেস বার দেখান</label></td>
				</tr>
			</table>

			<h2 class="title">"আরও পড়ুন" (Read More)</h2>
			<table class="form-table">
				<tr>
					<th scope="row">চালু রাখুন</th>
					<td><label><input type="checkbox" name="readmore_enabled" value="1" <?php echo esc_attr( $is_checked( 'readmore_enabled' ) ); ?> /> লম্বা আর্টিকেলে কিছুটা পড়ার পর বাকিটা বাটনের পেছনে লুকান</label></td>
				</tr>
				<tr>
					<th scope="row">কতটুকুর পর ফেইড হবে (পিক্সেল)</th>
					<td>
						<input type="number" min="300" step="50" name="readmore_height" value="<?php echo esc_attr( isset( $settings['readmore_height'] ) ? $settings['readmore_height'] : 900 ); ?>" />
						<p class="description">এর চেয়ে ছোট আর্টিকেল এমনিতেই পুরোটা দেখাবে, বাটন আসবে না।</p>
					</td>
				</tr>
				<tr>
					<th scope="row">ফন্ট +/- বাটনে প্রতি ক্লিকে কত % বাড়বে/কমবে</th>
					<td>
						<input type="number" min="1" max="30" name="font_step_percent" value="<?php echo esc_attr( isset( $settings['font_step_percent'] ) ? $settings['font_step_percent'] : 10 ); ?>" /> %
						<p class="description">সিঙ্গেল পোস্টের ওপরের ফ্লোটিং + / - বাটনে চাপলে লেখার সাইজ প্রতিবার এই শতাংশ হারে বাড়বে/কমবে (ডিফল্ট ১০%)।</p>
					</td>
				</tr>
			</table>

			<h2 class="title">মন্তব্য / ফিডব্যাক</h2>
			<table class="form-table">
				<tr>
					<th scope="row">চালু রাখুন</th>
					<td><label><input type="checkbox" name="comments_enabled" value="1" <?php echo esc_attr( $is_checked( 'comments_enabled' ) ); ?> /> আর্টিকেলের নিচে মন্তব্য সেকশন দেখান</label></td>
				</tr>
				<tr>
					<th scope="row">হেডিং টেক্সট</th>
					<td><input type="text" name="comments_heading" value="<?php echo esc_attr( ! empty( $settings['comments_heading'] ) ? $settings['comments_heading'] : 'মন্তব্য করুন' ); ?>" class="regular-text" /></td>
				</tr>
			</table>

			<?php submit_button( 'সেটিংস সংরক্ষণ করুন' ); ?>
		</form>
	</div>
	<?php
}

/**
 * ফর্ম সাবমিট হ্যান্ডল করা — সেভ বাটনে ক্লিক করলে এটা চলে
 */
function meeha_handle_homepage_settings_save() {
	if ( ! isset( $_POST['meeha_homepage_nonce'] ) ) {
		return;
	}
	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['meeha_homepage_nonce'] ) ), 'meeha_save_homepage_settings' ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$settings = array();

	// সর্বশেষ (site-wide latest, কোনো ক্যাটাগরি ফিল্টার ছাড়া)
	$settings['latest_enabled']      = isset( $_POST['latest_enabled'] ) ? 1 : 0;
	$settings['latest_count']        = isset( $_POST['latest_count'] ) ? (int) $_POST['latest_count'] : 6;
	$settings['latest_mobile_count'] = isset( $_POST['latest_mobile_count'] ) ? (int) $_POST['latest_mobile_count'] : 4;

	// সার্বাধিক পঠিত (হোমপেজে আলাদা সেকশন হিসেবে, আবার চালু)
	$settings['most_read_section_enabled']      = isset( $_POST['most_read_section_enabled'] ) ? 1 : 0;
	$settings['most_read_section_count']        = isset( $_POST['most_read_section_count'] ) ? (int) $_POST['most_read_section_count'] : 6;
	$settings['most_read_section_mobile_count'] = isset( $_POST['most_read_section_mobile_count'] ) ? (int) $_POST['most_read_section_mobile_count'] : 4;
	$settings['most_read_section_heading']      = isset( $_POST['most_read_section_heading'] ) ? sanitize_text_field( wp_unslash( $_POST['most_read_section_heading'] ) ) : 'সর্বাধিক পঠিত';

	// হিরো সেকশন
	$settings['hero_category'] = isset( $_POST['hero_category'] ) ? (int) $_POST['hero_category'] : 0;
	for ( $i = 1; $i <= 3; $i++ ) {
		$settings[ 'hero_highlight_cat_' . $i ] = isset( $_POST[ 'hero_highlight_cat_' . $i ] ) ? (int) $_POST[ 'hero_highlight_cat_' . $i ] : 0;
	}
	$settings['hero_count']        = isset( $_POST['hero_count'] ) ? (int) $_POST['hero_count'] : 2;
	$settings['hero_count_mobile'] = isset( $_POST['hero_count_mobile'] ) ? (int) $_POST['hero_count_mobile'] : 1;
	$settings['hero_top_image']    = isset( $_POST['hero_top_image'] ) ? 1 : 0;

	// ৫টি সেকশন ব্লক
	for ( $i = 1; $i <= 5; $i++ ) {
		$settings[ 'section_' . $i ] = array(
			'category'     => isset( $_POST[ 'section_' . $i . '_category' ] ) ? (int) $_POST[ 'section_' . $i . '_category' ] : 0,
			'title'        => isset( $_POST[ 'section_' . $i . '_title' ] ) ? sanitize_text_field( wp_unslash( $_POST[ 'section_' . $i . '_title' ] ) ) : '',
			'count'        => isset( $_POST[ 'section_' . $i . '_count' ] ) ? (int) $_POST[ 'section_' . $i . '_count' ] : 4,
			'mobile_count' => isset( $_POST[ 'section_' . $i . '_mobile_count' ] ) ? (int) $_POST[ 'section_' . $i . '_mobile_count' ] : 2,
			'layout'       => isset( $_POST[ 'section_' . $i . '_layout' ] ) && 'list' === $_POST[ 'section_' . $i . '_layout' ] ? 'list' : 'grid',
		);
	}

	// ব্রেকিং নিউজ টিকার
	$settings['topicbox_enabled'] = isset( $_POST['topicbox_enabled'] ) ? 1 : 0;
	for ( $i = 1; $i <= 4; $i++ ) {
		$settings[ 'topicbox_cat_' . $i ] = isset( $_POST[ 'topicbox_cat_' . $i ] ) ? (int) $_POST[ 'topicbox_cat_' . $i ] : 0;
	}
	$settings['tabbed_enabled']  = isset( $_POST['tabbed_enabled'] ) ? 1 : 0;
	$settings['tabbed_category'] = isset( $_POST['tabbed_category'] ) ? (int) $_POST['tabbed_category'] : 0;
	$settings['tabbed_title']    = isset( $_POST['tabbed_title'] ) ? sanitize_text_field( wp_unslash( $_POST['tabbed_title'] ) ) : '';

	$settings['breaking_enabled']  = isset( $_POST['breaking_enabled'] ) ? 1 : 0;
	$settings['breaking_label']    = isset( $_POST['breaking_label'] ) ? sanitize_text_field( wp_unslash( $_POST['breaking_label'] ) ) : 'হেডলাইন';
	$settings['breaking_category'] = isset( $_POST['breaking_category'] ) ? (int) $_POST['breaking_category'] : 0;

	// নিউজলেটার হেডলাইন
	$settings['newsletter_heading'] = isset( $_POST['newsletter_heading'] ) ? sanitize_text_field( wp_unslash( $_POST['newsletter_heading'] ) ) : '';
	$settings['mailpoet_form_id']   = isset( $_POST['mailpoet_form_id'] ) ? sanitize_text_field( wp_unslash( $_POST['mailpoet_form_id'] ) ) : '';
	$settings['google_news_url']    = isset( $_POST['google_news_url'] ) ? esc_url_raw( wp_unslash( $_POST['google_news_url'] ) ) : '';

	update_option( 'meeha_homepage_settings', $settings );

	add_action( 'admin_notices', function () {
		echo '<div class="notice notice-success is-dismissible"><p>হোমপেজ সেটিংস সংরক্ষণ করা হয়েছে।</p></div>';
	} );
}
add_action( 'admin_init', 'meeha_handle_homepage_settings_save' );

/**
 * ড্রপডাউনে দেখানোর জন্য সব ক্যাটাগরির তালিকা
 */
function meeha_category_dropdown( $field_name, $selected = 0 ) {
	$categories = get_categories( array( 'hide_empty' => false ) );
	echo '<select name="' . esc_attr( $field_name ) . '">';
	echo '<option value="0">— নির্বাচন করুন —</option>';
	foreach ( $categories as $cat ) {
		printf(
			'<option value="%d" %s>%s</option>',
			(int) $cat->term_id,
			selected( $selected, $cat->term_id, false ),
			esc_html( $cat->name )
		);
	}
	echo '</select>';
}

/**
 * সেটিংস পেজের HTML
 */
function meeha_render_homepage_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$settings = get_option( 'meeha_homepage_settings', array() );
	?>
	<div class="wrap">
		<h1>Meeha হোমপেজ সেটিংস</h1>
		<p>এখান থেকে হোমপেজের প্রতিটা সেকশনে কোন ক্যাটাগরি ও কতগুলো পোস্ট দেখাবে তা ঠিক করুন। পরিবর্তন সরাসরি লাইভ হোমপেজে প্রতিফলিত হবে।</p>

		<form method="post">
			<?php wp_nonce_field( 'meeha_save_homepage_settings', 'meeha_homepage_nonce' ); ?>

			<h2 class="title">সর্বশেষ (সাইট-জুড়ে সবচেয়ে নতুন খবর)</h2>
			<table class="form-table">
				<tr>
					<th scope="row">চালু রাখুন</th>
					<td><label><input type="checkbox" name="latest_enabled" value="1" <?php checked( ! empty( $settings['latest_enabled'] ) ); ?> /> কোনো নির্দিষ্ট ক্যাটাগরি ছাড়াই সাইটের সব ক্যাটাগরি থেকে সবচেয়ে নতুন পোস্টগুলো দেখান</label></td>
				</tr>
				<tr>
					<th scope="row">কতগুলো দেখাবে (ডেক্সটপ)</th>
					<td><input type="number" min="3" max="12" name="latest_count" value="<?php echo esc_attr( isset( $settings['latest_count'] ) ? $settings['latest_count'] : 6 ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row">কতগুলো দেখাবে (মোবাইলে)</th>
					<td>
						<input type="number" min="1" max="12" name="latest_mobile_count" value="<?php echo esc_attr( isset( $settings['latest_mobile_count'] ) ? $settings['latest_mobile_count'] : 4 ); ?>" />
						<p class="description">ছোট স্ক্রিনে (মোবাইল) এতগুলো দেখাবে; ডেক্সটপ/ট্যাবলেটে ওপরের সংখ্যা অনুযায়ী দেখাবে।</p>
					</td>
				</tr>
			</table>

			<h2 class="title">সার্বাধিক পঠিত সেকশন (হোমপেজে আলাদা সেকশন হিসেবে)</h2>
			<table class="form-table">
				<tr>
					<th scope="row">চালু রাখুন</th>
					<td><label><input type="checkbox" name="most_read_section_enabled" value="1" <?php checked( ! empty( $settings['most_read_section_enabled'] ) ); ?> /> হোমপেজে দেখান</label>
					<p class="description">যেসব পোস্টে "সার্বাধিক পঠিত" ফ্ল্যাগ দেওয়া আছে (Posts লিস্টের Quick Edit থেকে), সেগুলো এখানে দেখাবে — সাইডবারের "জনপ্রিয়" ট্যাবেও একই ডেটা ব্যবহার হয়, তাই দুই জায়গায় দুই রকম তালিকার ঝুঁকি নেই।</p></td>
				</tr>
				<tr>
					<th scope="row">শিরোনাম</th>
					<td><input type="text" name="most_read_section_heading" value="<?php echo esc_attr( isset( $settings['most_read_section_heading'] ) ? $settings['most_read_section_heading'] : 'সর্বাধিক পঠিত' ); ?>" style="width:250px;" /></td>
				</tr>
				<tr>
					<th scope="row">কতগুলো দেখাবে (ডেক্সটপ)</th>
					<td><input type="number" min="3" max="10" name="most_read_section_count" value="<?php echo esc_attr( isset( $settings['most_read_section_count'] ) ? $settings['most_read_section_count'] : 6 ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row">কতগুলো দেখাবে (মোবাইলে)</th>
					<td><input type="number" min="1" max="10" name="most_read_section_mobile_count" value="<?php echo esc_attr( isset( $settings['most_read_section_mobile_count'] ) ? $settings['most_read_section_mobile_count'] : 4 ); ?>" /></td>
				</tr>
			</table>

			<h2 class="title">হিরো / টপ স্টোরি সেকশন (নতুন ডিজাইন — Al Jazeera/AFP-স্টাইল, মডারেট ছবি, টেক্সট-প্রাধান্য)</h2>
			<table class="form-table">
				<tr>
					<th scope="row"><label for="hero_category">লিড ক্যাটাগরি</label></th>
					<td><?php meeha_category_dropdown( 'hero_category', isset( $settings['hero_category'] ) ? $settings['hero_category'] : 0 ); ?>
					<p class="description">এই ক্যাটাগরির সবচেয়ে নতুন পোস্টটি সবচেয়ে ওপরে "টপ স্টোরি" হিসেবে দেখাবে।</p></td>
				</tr>
				<tr>
					<th scope="row">টপ স্টোরিতে ছবি</th>
					<td>
						<label><input type="checkbox" name="hero_top_image" value="1" <?php checked( meeha_get_hero_top_image_enabled() ); ?> /> মডারেট সাইজের একটা ছবি দেখান (টেক্সটের পাশে, বড় ডমিনেটিং ছবি না)</label>
						<p class="description">বন্ধ করলে টপ স্টোরি সম্পূর্ণ টেক্সট-অনলি হবে (একদম AFP-স্টাইল)।</p>
					</td>
				</tr>
				<?php for ( $i = 1; $i <= 3; $i++ ) : ?>
				<tr>
					<th scope="row">হাইলাইট ক্যাটাগরি <?php echo esc_html( $i ); ?></th>
					<td><?php meeha_category_dropdown( 'hero_highlight_cat_' . $i, isset( $settings[ 'hero_highlight_cat_' . $i ] ) ? $settings[ 'hero_highlight_cat_' . $i ] : 0 ); ?></td>
				</tr>
				<?php endfor; ?>
				<tr>
					<th scope="row"><label for="hero_count">প্রতি হাইলাইট ক্যাটাগরি থেকে কতটা পোস্ট (ডেক্সটপ)</label></th>
					<td>
						<input type="number" min="1" max="3" name="hero_count" value="<?php echo esc_attr( isset( $settings['hero_count'] ) ? $settings['hero_count'] : 2 ); ?>" />
						<p class="description">টপ স্টোরির নিচে ছোট ছোট কার্ডের গ্রিডে প্রতিটা হাইলাইট ক্যাটাগরি থেকে এতগুলো করে পোস্ট দেখাবে (নিজের ক্যাটাগরির নামসহ)।</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="hero_count_mobile">প্রতি হাইলাইট ক্যাটাগরি থেকে কতটা পোস্ট (মোবাইলে)</label></th>
					<td>
						<input type="number" min="1" max="3" name="hero_count_mobile" value="<?php echo esc_attr( isset( $settings['hero_count_mobile'] ) ? $settings['hero_count_mobile'] : 1 ); ?>" />
					</td>
				</tr>
			</table>

			<h2 class="title">ক্যাটাগরি সেকশন (৫টি পর্যন্ত)</h2>
			<p class="description">খালি রাখলে সেই সেকশনটি হোমপেজে দেখাবে না।</p>
			<?php for ( $i = 1; $i <= 5; $i++ ) : ?>
				<?php $block = isset( $settings[ 'section_' . $i ] ) ? $settings[ 'section_' . $i ] : array(); ?>
				<table class="form-table">
					<tr>
						<th colspan="2"><h3>সেকশন <?php echo (int) $i; ?></h3></th>
					</tr>
					<tr>
						<th scope="row">ক্যাটাগরি</th>
						<td><?php meeha_category_dropdown( 'section_' . $i . '_category', isset( $block['category'] ) ? $block['category'] : 0 ); ?></td>
					</tr>
					<tr>
						<th scope="row">সেকশনের শিরোনাম (না দিলে ক্যাটাগরির নামই দেখাবে)</th>
						<td><input type="text" name="section_<?php echo (int) $i; ?>_title" value="<?php echo esc_attr( isset( $block['title'] ) ? $block['title'] : '' ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row">কতগুলো পোস্ট দেখাবে (ডেক্সটপ)</th>
						<td><input type="number" min="2" max="8" name="section_<?php echo (int) $i; ?>_count" value="<?php echo esc_attr( isset( $block['count'] ) ? $block['count'] : 4 ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row">কতগুলো পোস্ট দেখাবে (মোবাইলে)</th>
						<td><input type="number" min="1" max="8" name="section_<?php echo (int) $i; ?>_mobile_count" value="<?php echo esc_attr( isset( $block['mobile_count'] ) ? $block['mobile_count'] : 2 ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row">লেআউট</th>
						<td>
							<select name="section_<?php echo (int) $i; ?>_layout">
								<option value="grid" <?php selected( ! isset( $block['layout'] ) || 'grid' === $block['layout'] ); ?>>গ্রিড (৪টা সমান কার্ড)</option>
								<option value="list" <?php selected( isset( $block['layout'] ) && 'list' === $block['layout'] ); ?>>লিস্ট (১টা বড় + বাকিগুলো টেক্সট-লিস্ট)</option>
							</select>
						</td>
					</tr>
				</table>
			<?php endfor; ?>

			<h2 class="title">টপিক বক্স (৪ কলাম)</h2>
			<table class="form-table">
				<tr>
					<th scope="row">চালু রাখুন</th>
					<td><label><input type="checkbox" name="topicbox_enabled" value="1" <?php checked( ! empty( $settings['topicbox_enabled'] ) ); ?> /> হিরো সেকশনের ঠিক নিচে ৪-কলামের রঙিন টপিক বক্স দেখান</label></td>
				</tr>
				<?php for ( $i = 1; $i <= 4; $i++ ) : ?>
				<tr>
					<th scope="row">বক্স <?php echo esc_html( $i ); ?>-এর ক্যাটাগরি</th>
					<td><?php meeha_category_dropdown( 'topicbox_cat_' . $i, isset( $settings[ 'topicbox_cat_' . $i ] ) ? $settings[ 'topicbox_cat_' . $i ] : 0 ); ?></td>
				</tr>
				<?php endfor; ?>
				<tr>
					<td colspan="2"><p class="description">প্রতিটা বক্সে সেই ক্যাটাগরির সর্বশেষ পোস্টটা দেখাবে।</p></td>
				</tr>
			</table>

			<h2 class="title">ট্যাব সাইডবার (জনপ্রিয়/ট্রেন্ড/নতুন)</h2>
			<p class="description">এই একই "জনপ্রিয়/ট্রেন্ড/নতুন" উইজেট এখন তিন জায়গায় দেখাবে: হোমপেজে (নিচের ক্যাটাগরি সেকশনসহ), এবং সিঙ্গেল পোস্ট ও ক্যাটাগরি আর্কাইভ পেজের সাইডবারে (কম্প্যাক্ট আকারে, শুধু ট্যাব+তালিকা)। আগের আলাদা "সার্বাধিক পঠিত" সেকশনটা তাই সরিয়ে দেওয়া হয়েছে — এই উইজেটের "জনপ্রিয়" ট্যাবই একই কাজ করে (একই ম্যানুয়ালি-চিহ্নিত পোস্ট)।</p>
			<table class="form-table">
				<tr>
					<th scope="row">চালু রাখুন</th>
					<td><label><input type="checkbox" name="tabbed_enabled" value="1" <?php checked( ! empty( $settings['tabbed_enabled'] ) ); ?> /> টপিক বক্সের নিচে (হোমপেজে) + সিঙ্গেল/ক্যাটাগরি সাইডবারে দেখান</label></td>
				</tr>
				<tr>
					<th scope="row">পাশের ক্যাটাগরি সেকশন</th>
					<td>
						<?php meeha_category_dropdown( 'tabbed_category', isset( $settings['tabbed_category'] ) ? $settings['tabbed_category'] : 0 ); ?>
						<p class="description">ট্যাব সাইডবারের পাশে এই ক্যাটাগরির একটা সেকশন (বড় লিড পোস্ট + তালিকা) দেখাবে।</p>
					</td>
				</tr>
				<tr>
					<th scope="row">সেকশনের শিরোনাম (ঐচ্ছিক)</th>
					<td><input type="text" name="tabbed_title" value="<?php echo esc_attr( isset( $settings['tabbed_title'] ) ? $settings['tabbed_title'] : '' ); ?>" style="width:250px;" placeholder="খালি রাখলে ক্যাটাগরির নাম দেখাবে" /></td>
				</tr>
			</table>

			<h2 class="title">হেডলাইন টিকার</h2>
			<table class="form-table">
				<tr>
					<th scope="row">চালু রাখুন</th>
					<td><label><input type="checkbox" name="breaking_enabled" value="1" <?php checked( ! empty( $settings['breaking_enabled'] ) ); ?> /> হেডারের ঠিক নিচে স্ক্রলিং টিকার দেখান (সাইটের সব পেজে)</label></td>
				</tr>
				<tr>
					<th scope="row">লেবেল</th>
					<td><input type="text" name="breaking_label" value="<?php echo esc_attr( isset( $settings['breaking_label'] ) ? $settings['breaking_label'] : 'হেডলাইন' ); ?>" style="width:200px;" /></td>
				</tr>
				<tr>
					<th scope="row">কোন ক্যাটাগরি থেকে দেখাবে</th>
					<td>
						<?php meeha_category_dropdown( 'breaking_category', isset( $settings['breaking_category'] ) ? $settings['breaking_category'] : 0 ); ?>
						<p class="description">কোনো ক্যাটাগরি বাছাই না করলে সব ক্যাটাগরির সর্বশেষ পোস্ট (নতুন থেকে পুরাতন) দেখাবে।</p>
					</td>
				</tr>
			</table>

			<h2 class="title">নিউজলেটার ব্যান্ড</h2>
			<table class="form-table">
				<tr>
					<th scope="row">হেডলাইন টেক্সট</th>
					<td><input type="text" name="newsletter_heading" value="<?php echo esc_attr( isset( $settings['newsletter_heading'] ) ? $settings['newsletter_heading'] : 'প্রতিদিনের গুরুত্বপূর্ণ খবর সরাসরি আপনার ইনবক্সে' ); ?>" class="regular-text" /></td>
				</tr>
				<tr>
					<th scope="row">MailPoet ফর্ম আইডি (ঐচ্ছিক)</th>
					<td>
						<input type="text" name="mailpoet_form_id" value="<?php echo esc_attr( isset( $settings['mailpoet_form_id'] ) ? $settings['mailpoet_form_id'] : '' ); ?>" class="small-text" placeholder="যেমন: 1" />
						<p class="description">MailPoet → Forms-এ গিয়ে আপনার ফর্মের সঠিক আইডি দেখে এখানে বসান। খালি রাখলে সাইটের নিজস্ব সাবস্ক্রাইব ফর্ম (সরাসরি কাজ করে) ব্যবহার হবে।</p>
					</td>
				</tr>
			</table>

			<h2 class="title">গুগল নিউজ</h2>
			<table class="form-table">
				<tr>
					<th scope="row">Google News ফলো লিংক</th>
					<td>
						<input type="url" name="google_news_url" value="<?php echo esc_attr( isset( $settings['google_news_url'] ) ? $settings['google_news_url'] : '' ); ?>" class="regular-text" placeholder="https://news.google.com/publications/..." />
						<p class="description">
							Google News Publisher Center-এ Meeha News যুক্ত করার পর সেখান থেকে "Follow"/প্রকাশনার লিংকটা এখানে বসান।
							এটা দেওয়া থাকলে প্রতিটা আর্টিকেলের শেষে একটা "গুগল নিউজে ফলো করুন" বাটন দেখাবে; খালি থাকলে বাটনটা দেখাবে না।
						</p>
					</td>
				</tr>
			</table>

			<?php submit_button( 'সেটিংস সংরক্ষণ করুন' ); ?>
		</form>
	</div>
	<?php
}