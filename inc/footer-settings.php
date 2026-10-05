<?php
/**
 * Meeha News — ফুটার সেটিংস (Admin > Meeha হোমপেজ > ফুটার)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function meeha_get_footer_settings() {
	$s = get_option( 'meeha_footer_settings', array() );

	$columns = array();
	for ( $i = 0; $i < 3; $i++ ) {
		$saved_col = isset( $s['columns'][ $i ] ) ? $s['columns'][ $i ] : array();
		$links     = array();
		for ( $j = 0; $j < 6; $j++ ) {
			$saved_link = isset( $saved_col['links'][ $j ] ) ? $saved_col['links'][ $j ] : array();
			$links[]    = array(
				'label' => isset( $saved_link['label'] ) ? sanitize_text_field( $saved_link['label'] ) : '',
				'url'   => isset( $saved_link['url'] ) ? esc_url_raw( $saved_link['url'] ) : '',
			);
		}
		$columns[] = array(
			'title' => isset( $saved_col['title'] ) ? sanitize_text_field( $saved_col['title'] ) : '',
			'links' => $links,
		);
	}

	return array(
		'enabled'            => ! isset( $s['enabled'] ) || $s['enabled'],
		'about_text'         => isset( $s['about_text'] ) ? sanitize_textarea_field( $s['about_text'] ) : 'বাংলাদেশ ও বিশ্বের সর্বশেষ সংবাদ, নির্ভরযোগ্য ও নিরপেক্ষ সাংবাদিকতা।',
		'columns'            => $columns,
		'newsletter_enabled' => ! empty( $s['newsletter_enabled'] ),
		'copyright_text'     => isset( $s['copyright_text'] ) ? wp_kses_post( $s['copyright_text'] ) : '© {year} Meeha News. সর্বস্বত্ব সংরক্ষিত।',
		'scrolltop_enabled'  => ! isset( $s['scrolltop_enabled'] ) || $s['scrolltop_enabled'],
	);
}

function meeha_add_footer_settings_page() {
	add_submenu_page(
		'meeha-homepage-settings',
		'ফুটার সেটিংস',
		'ফুটার',
		'manage_options',
		'meeha-footer-settings',
		'meeha_render_footer_settings_page'
	);
}
add_action( 'admin_menu', 'meeha_add_footer_settings_page' );

function meeha_handle_footer_settings_save() {
	if ( ! isset( $_POST['meeha_footer_nonce'] ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['meeha_footer_nonce'] ) ), 'meeha_save_footer' ) ) {
		return;
	}
	$p = wp_unslash( $_POST );

	$columns = array();
	for ( $i = 0; $i < 3; $i++ ) {
		$links = array();
		for ( $j = 0; $j < 6; $j++ ) {
			$links[] = array(
				'label' => isset( $p[ "col_{$i}_label_{$j}" ] ) ? sanitize_text_field( $p[ "col_{$i}_label_{$j}" ] ) : '',
				'url'   => isset( $p[ "col_{$i}_url_{$j}" ] ) ? esc_url_raw( trim( $p[ "col_{$i}_url_{$j}" ] ) ) : '',
			);
		}
		$columns[] = array(
			'title' => isset( $p[ "col_{$i}_title" ] ) ? sanitize_text_field( $p[ "col_{$i}_title" ] ) : '',
			'links' => $links,
		);
	}

	update_option( 'meeha_footer_settings', array(
		'enabled'            => isset( $p['enabled'] ) ? 1 : 0,
		'about_text'         => isset( $p['about_text'] ) ? sanitize_textarea_field( $p['about_text'] ) : '',
		'columns'            => $columns,
		'newsletter_enabled' => isset( $p['newsletter_enabled'] ) ? 1 : 0,
		'copyright_text'     => isset( $p['copyright_text'] ) ? wp_kses_post( $p['copyright_text'] ) : '',
		'scrolltop_enabled'  => isset( $p['scrolltop_enabled'] ) ? 1 : 0,
	) );

	add_action( 'admin_notices', function () {
		echo '<div class="notice notice-success is-dismissible"><p>ফুটার সেটিংস সংরক্ষণ করা হয়েছে।</p></div>';
	} );
}
add_action( 'admin_init', 'meeha_handle_footer_settings_save' );

function meeha_render_footer_settings_page() {
	$s = meeha_get_footer_settings();
	?>
	<div class="wrap">
		<h1>ফুটার সেটিংস</h1>
		<p class="description">সোশ্যাল মিডিয়া লিংক <a href="<?php echo esc_url( admin_url( 'admin.php?page=meeha-header-settings' ) ); ?>">হেডার সেটিংস</a> থেকে নিয়ন্ত্রিত হয় (হেডার ও ফুটার দুই জায়গাতেই একই লিংক দেখাবে)।</p>
		<form method="post">
			<?php wp_nonce_field( 'meeha_save_footer', 'meeha_footer_nonce' ); ?>

			<table class="form-table">
				<tr>
					<th scope="row">কাস্টম ফুটার</th>
					<td>
						<label><input type="checkbox" name="enabled" value="1" <?php checked( $s['enabled'] ); ?> /> এই কাস্টম ডিজাইন করা ফুটার ব্যবহার করুন</label>
						<p class="description">বন্ধ করলে প্যারেন্ট থিমের ডিফল্ট ফুটার ফিরে আসবে (জরুরি ফলব্যাক)।</p>
					</td>
				</tr>
				<tr>
					<th scope="row">সংক্ষিপ্ত পরিচিতি</th>
					<td><textarea name="about_text" rows="3" class="large-text"><?php echo esc_textarea( $s['about_text'] ); ?></textarea></td>
				</tr>
				<tr>
					<th scope="row">নিউজলেটার</th>
					<td><label><input type="checkbox" name="newsletter_enabled" value="1" <?php checked( $s['newsletter_enabled'] ); ?> /> ফুটারের ওপরে নিউজলেটার সাবস্ক্রিপশন ব্যান্ড দেখান</label></td>
				</tr>
				<tr>
					<th scope="row">স্ক্রল-টু-টপ বাটন</th>
					<td><label><input type="checkbox" name="scrolltop_enabled" value="1" <?php checked( $s['scrolltop_enabled'] ); ?> /> ওপরে ফিরে যাওয়ার বাটন দেখান</label></td>
				</tr>
				<tr>
					<th scope="row">কপিরাইট লেখা</th>
					<td>
						<input type="text" name="copyright_text" class="large-text" value="<?php echo esc_attr( $s['copyright_text'] ); ?>" />
						<p class="description"><code>{year}</code> লিখলে সেটা স্বয়ংক্রিয়ভাবে চলতি বছর দিয়ে বদলে যাবে।</p>
					</td>
				</tr>
			</table>

			<h2 class="title">কুইক-লিংক কলাম (৩টা পর্যন্ত)</h2>
			<p class="description">প্রতিটা কলামে একটা শিরোনাম ও সর্বোচ্চ ৬টা লিংক দিন। খালি রাখলে সেই লিংক/কলাম দেখাবে না।</p>
			<?php for ( $i = 0; $i < 3; $i++ ) : $col = $s['columns'][ $i ]; ?>
				<table class="form-table" style="margin-bottom:8px;">
					<tr>
						<th scope="row">কলাম <?php echo (int) $i + 1; ?> শিরোনাম</th>
						<td><input type="text" name="col_<?php echo (int) $i; ?>_title" value="<?php echo esc_attr( $col['title'] ); ?>" placeholder="যেমন: ক্যাটাগরি / প্রতিষ্ঠান / সাহায্য" style="max-width:300px;" /></td>
					</tr>
					<?php for ( $j = 0; $j < 6; $j++ ) : $link = $col['links'][ $j ]; ?>
						<tr>
							<th scope="row"><?php echo (int) $j + 1; ?>. লিংক</th>
							<td>
								<input type="text" name="col_<?php echo (int) $i; ?>_label_<?php echo (int) $j; ?>" value="<?php echo esc_attr( $link['label'] ); ?>" placeholder="লেবেল, যেমন: রাজনীতি" style="width:38%;margin-right:2%;" />
								<input type="text" name="col_<?php echo (int) $i; ?>_url_<?php echo (int) $j; ?>" value="<?php echo esc_attr( $link['url'] ); ?>" placeholder="https://..." style="width:58%;" />
							</td>
						</tr>
					<?php endfor; ?>
				</table>
			<?php endfor; ?>

			<?php submit_button( 'সংরক্ষণ করুন' ); ?>
		</form>
	</div>
	<?php
}
