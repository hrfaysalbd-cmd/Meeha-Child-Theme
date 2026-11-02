<?php
/**
 * দেশচিত্র wp-admin: "দেশচিত্র" menu → দৈনিক হিসাব (input sheet, assets/js/deshchitro-admin.js over REST)
 * and সূচক ও সেটিংস (indicators, economy items, page texts, the page itself).
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

/** Menu. */
function meeha_dc_admin_menu() {
	$hook = add_menu_page( 'দেশচিত্র — দৈনিক হিসাব', 'দেশচিত্র', 'edit_others_posts', 'meeha-deshchitro', 'meeha_dc_admin_sheet_page', 'dashicons-chart-area', 27 );
	add_submenu_page( 'meeha-deshchitro', 'দেশচিত্র — দৈনিক হিসাব', 'দৈনিক হিসাব', 'edit_others_posts', 'meeha-deshchitro', 'meeha_dc_admin_sheet_page' );
	$set = add_submenu_page( 'meeha-deshchitro', 'দেশচিত্র — সূচক ও সেটিংস', 'সূচক ও সেটিংস', 'edit_others_posts', 'meeha-deshchitro-settings', 'meeha_dc_admin_settings_page' );
	add_action( 'admin_print_styles-' . $hook, 'meeha_dc_admin_assets' );
	add_action( 'admin_print_styles-' . $set, 'meeha_dc_admin_settings_assets' );
}
add_action( 'admin_menu', 'meeha_dc_admin_menu' );

/** Input sheet assets. */
function meeha_dc_admin_assets() {
	wp_enqueue_style( 'meeha-dc-admin', MEEHA_URI . '/assets/css/deshchitro-admin.css', array(), meeha_dc_asset_ver( 'assets/css/deshchitro-admin.css' ) );
	wp_enqueue_script( 'meeha-dc-icons', MEEHA_URI . '/assets/js/deshchitro-icons.js', array(), meeha_dc_asset_ver( 'assets/js/deshchitro-icons.js' ), true );
	wp_enqueue_script( 'meeha-dc-admin', MEEHA_URI . '/assets/js/deshchitro-admin.js', array( 'meeha-dc-icons' ), meeha_dc_asset_ver( 'assets/js/deshchitro-admin.js' ), true );
	wp_localize_script(
		'meeha-dc-admin',
		'MeehaDC',
		array(
			'rest'  => esc_url_raw( rest_url( 'meeha/v1/deshchitro/sheet' ) ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
		)
	);
}

/** Settings screen assets (only the shared admin CSS). */
function meeha_dc_admin_settings_assets() {
	wp_enqueue_style( 'meeha-dc-admin', MEEHA_URI . '/assets/css/deshchitro-admin.css', array(), meeha_dc_asset_ver( 'assets/css/deshchitro-admin.css' ) );
}

/**
 * Asset version.
 *
 * @param string $rel Relative path.
 * @return string
 */
function meeha_dc_asset_ver( $rel ) {
	$p = MEEHA_DIR . '/' . $rel;
	return MEEHA_VERSION . ( is_readable( $p ) ? '.' . filemtime( $p ) : '' );
}

/** Input sheet page (the JS app mounts into #meeha-dc-admin). */
function meeha_dc_admin_sheet_page() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$day = isset( $_GET['date'] ) && meeha_dc_is_date( sanitize_text_field( wp_unslash( $_GET['date'] ) ) ) ? sanitize_text_field( wp_unslash( $_GET['date'] ) ) : meeha_dc_shift( meeha_dc_today(), -1 );
	?>
	<div class="wrap dca-wrap">
		<h1 class="dca-h1">দেশচিত্র — দৈনিক হিসাব</h1>
		<p class="dca-lede">প্রতিদিন রাত ১২টার পর আগের দিনের হিসাব দিন। দিনের মধ্যেই খসড়া রাখতে পারেন; "প্রকাশ" দিলে দিন শেষ হওয়ামাত্র (রাত ১২টায়) পাঠক দেখবেন। চলতি দিনের হিসাব পাঠক কখনো দেখেন না।</p>
		<div id="meeha-dc-admin" data-date="<?php echo esc_attr( $day ); ?>"><p class="dca-loading">লোড হচ্ছে…</p></div>
		<noscript><p>এই পাতার জন্য জাভাস্ক্রিপ্ট চালু রাখুন।</p></noscript>
	</div>
	<?php
}

/** Save settings (admin-post). */
function meeha_dc_admin_save_settings() {
	if ( ! meeha_dc_can_edit() ) {
		wp_die( 'অনুমতি নেই।', 403 );
	}
	check_admin_referer( 'meeha_dc_settings' );
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised field by field below.
	$raw = isset( $_POST['dc'] ) ? wp_unslash( $_POST['dc'] ) : array();
	$raw = is_array( $raw ) ? $raw : array();
	foreach ( array( 'indicators', 'economy' ) as $k ) {
		$raw[ $k ] = isset( $raw[ $k ] ) && is_array( $raw[ $k ] ) ? array_values( $raw[ $k ] ) : array();
		usort(
			$raw[ $k ],
			function ( $x, $y ) {
				return (int) ( isset( $x['order'] ) ? $x['order'] : 0 ) - (int) ( isset( $y['order'] ) ? $y['order'] : 0 );
			}
		);
	}
	$msg = 'saved';
	if ( ! empty( $_POST['dc_create_page'] ) ) {
		$id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'দৈনিক দেশচিত্র',
				'post_name'    => 'deshchitro',
				'post_content' => '<!-- wp:shortcode -->[meeha_deshchitro]<!-- /wp:shortcode -->',
			),
			true
		);
		if ( ! is_wp_error( $id ) ) {
			update_post_meta( $id, '_meeha_hide_title', 1 );
			update_post_meta( $id, '_wp_page_template', 'page-full.php' );
			$raw['page_id'] = $id;
			$msg            = 'page';
		}
	}
	if ( ! empty( $_POST['dc_reset'] ) ) {
		$keep            = meeha_dc_config();
		$raw             = array(
			'indicators' => meeha_dc_default_indicators(),
			'economy'    => meeha_dc_default_economy(),
			'text'       => array(),
			'page_id'    => $keep['page_id'],
		);
		$msg             = 'reset';
	}
	update_option( 'meeha_dc_config', meeha_dc_sanitize_config( $raw ), false );
	meeha_dc_cache_bump();
	wp_safe_redirect( admin_url( 'admin.php?page=meeha-deshchitro-settings&dc_msg=' . $msg ) );
	exit;
}
add_action( 'admin_post_meeha_dc_settings', 'meeha_dc_admin_save_settings' );

/** Settings page. */
function meeha_dc_admin_settings_page() {
	$c     = meeha_dc_config();
	$icons = meeha_dc_icons();
	$tones = meeha_dc_tones();
	$grps  = meeha_dc_economy_groups();
	$url   = meeha_dc_page_url();
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$msg = isset( $_GET['dc_msg'] ) ? sanitize_key( $_GET['dc_msg'] ) : '';
	$msgs = array(
		'saved' => 'সংরক্ষণ হয়েছে।',
		'page'  => 'পাতা তৈরি হয়েছে এবং যুক্ত করা হয়েছে।',
		'reset' => 'সূচক ও লেখা আগের (ডিফল্ট) অবস্থায় ফিরেছে।',
	);
	?>
	<div class="wrap dca-wrap">
		<h1 class="dca-h1">দেশচিত্র — সূচক ও সেটিংস</h1>
		<?php if ( isset( $msgs[ $msg ] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $msgs[ $msg ] ); ?></p></div>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dca-settings">
			<input type="hidden" name="action" value="meeha_dc_settings">
			<?php wp_nonce_field( 'meeha_dc_settings' ); ?>

			<div class="dca-card">
				<h2>পাতা</h2>
				<p>যেকোনো পাতায় শর্টকোড <code>[meeha_deshchitro]</code> বসালেই পুরো দেশচিত্র দেখাবে। টেমপ্লেট "পাতা — পূর্ণ প্রস্থ" আর "শিরোনাম লুকান" দিলে সবচেয়ে ভালো দেখায়।</p>
				<p>
					<label>পাতার আইডি <input type="number" min="0" name="dc[page_id]" value="<?php echo esc_attr( $c['page_id'] ); ?>" class="small-text"></label>
					<?php if ( $url ) : ?>
						&nbsp; <a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener">পাতা দেখুন ↗</a>
					<?php else : ?>
						&nbsp; <button type="submit" name="dc_create_page" value="1" class="button">"দৈনিক দেশচিত্র" পাতা নিজে তৈরি করুন</button>
					<?php endif; ?>
				</p>
			</div>

			<div class="dca-card">
				<h2>লেখা</h2>
				<?php
				$labels = array(
					'title'      => 'শিরোনাম',
					'kicker'     => 'ওপরের ছোট লেখা',
					'subtitle'   => 'উপশিরোনাম',
					'disclaimer' => 'নিচের ঘোষণা (দায় স্বীকার)',
					'method'     => 'পদ্ধতির নোট',
				);
				foreach ( $labels as $k => $label ) :
					?>
					<p class="dca-field"><label><strong><?php echo esc_html( $label ); ?></strong><br>
						<textarea name="dc[text][<?php echo esc_attr( $k ); ?>]" rows="<?php echo in_array( $k, array( 'title', 'kicker' ), true ) ? 1 : 2; ?>" class="large-text" placeholder="<?php echo esc_attr( meeha_dc_text_defaults()[ $k ] ); ?>"><?php echo esc_textarea( $c['text'][ $k ] === meeha_dc_text_defaults()[ $k ] ? '' : $c['text'][ $k ] ); ?></textarea></label></p>
				<?php endforeach; ?>
				<p class="description">খালি রাখলে ধূসর লেখাটিই (ডিফল্ট) দেখাবে।</p>
			</div>

			<div class="dca-card">
				<h2>ঘটনার সূচক (জেলা ধরে)</h2>
				<p class="description">সর্বোচ্চ ১৬টি। "কী" একবার ঠিক করার পর বদলাবেন না — পুরোনো দিনের হিসাব এই কী দিয়েই রাখা। কোনো সূচক বাদ দিতে চাইলে মুছবেন না, "চালু" টিক তুলে দিন।</p>
				<table class="widefat dca-cfg">
					<thead><tr><th>ক্রম</th><th>চালু</th><th>কী (ইংরেজি)</th><th>পুরো নাম</th><th>ছোট নাম</th><th>চিহ্ন</th><th>রং/ধরন</th></tr></thead>
					<tbody>
					<?php
					$rows = $c['indicators'];
					for ( $i = 0; $i < 3; $i++ ) {
						$rows[] = array( 'key' => '', 'label' => '', 'short' => '', 'icon' => 'alert', 'tone' => 'bad', 'on' => 1 );
					}
					foreach ( $rows as $i => $r ) :
						$n = 'dc[indicators][' . $i . ']';
						?>
						<tr>
							<td><input type="number" name="<?php echo esc_attr( $n ); ?>[order]" value="<?php echo esc_attr( $i + 1 ); ?>" class="small-text"></td>
							<td><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[on]" value="1" <?php checked( ! empty( $r['on'] ) ); ?>></td>
							<td><input type="text" name="<?php echo esc_attr( $n ); ?>[key]" value="<?php echo esc_attr( $r['key'] ); ?>" <?php echo $r['key'] ? 'readonly' : 'placeholder="নতুন: যেমন nikhoj"'; ?> class="regular-text code" style="width:9em"></td>
							<td><input type="text" name="<?php echo esc_attr( $n ); ?>[label]" value="<?php echo esc_attr( $r['label'] ); ?>" class="regular-text"></td>
							<td><input type="text" name="<?php echo esc_attr( $n ); ?>[short]" value="<?php echo esc_attr( $r['short'] ); ?>" style="width:9em"></td>
							<td><select name="<?php echo esc_attr( $n ); ?>[icon]"><?php foreach ( $icons as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $r['icon'], $k ); ?>><?php echo esc_html( $l ); ?></option><?php endforeach; ?></select></td>
							<td><select name="<?php echo esc_attr( $n ); ?>[tone]"><?php foreach ( $tones as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $r['tone'], $k ); ?>><?php echo esc_html( $l ); ?></option><?php endforeach; ?></select></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description">ম্যাপে "সব নেতিবাচক ঘটনা" স্তরে লাল ও হলুদ ধরনের সূচকগুলো যোগ হয়। নীল (যেমন গ্রেপ্তার) ও সবুজ (সাফল্য) আলাদা দেখায়।</p>
			</div>

			<div class="dca-card">
				<h2>অর্থনীতির সূচক (জাতীয়)</h2>
				<table class="widefat dca-cfg">
					<thead><tr><th>ক্রম</th><th>চালু</th><th>কী</th><th>নাম</th><th>দল</th><th>একক</th><th>দশমিক</th><th>বাড়লে</th><th>কোড</th></tr></thead>
					<tbody>
					<?php
					$rows = $c['economy'];
					for ( $i = 0; $i < 3; $i++ ) {
						$rows[] = array( 'key' => '', 'label' => '', 'group' => 'market', 'unit' => '৳', 'dec' => 0, 'good' => '', 'code' => '', 'on' => 1 );
					}
					foreach ( $rows as $i => $r ) :
						$n = 'dc[economy][' . $i . ']';
						?>
						<tr>
							<td><input type="number" name="<?php echo esc_attr( $n ); ?>[order]" value="<?php echo esc_attr( $i + 1 ); ?>" class="small-text"></td>
							<td><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[on]" value="1" <?php checked( ! empty( $r['on'] ) ); ?>></td>
							<td><input type="text" name="<?php echo esc_attr( $n ); ?>[key]" value="<?php echo esc_attr( $r['key'] ); ?>" <?php echo $r['key'] ? 'readonly' : 'placeholder="যেমন sugar"'; ?> class="code" style="width:7em"></td>
							<td><input type="text" name="<?php echo esc_attr( $n ); ?>[label]" value="<?php echo esc_attr( $r['label'] ); ?>" class="regular-text"></td>
							<td><select name="<?php echo esc_attr( $n ); ?>[group]"><?php foreach ( $grps as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $r['group'], $k ); ?>><?php echo esc_html( $l ); ?></option><?php endforeach; ?></select></td>
							<td><input type="text" name="<?php echo esc_attr( $n ); ?>[unit]" value="<?php echo esc_attr( $r['unit'] ); ?>" style="width:6em"></td>
							<td><input type="number" min="0" max="4" name="<?php echo esc_attr( $n ); ?>[dec]" value="<?php echo esc_attr( $r['dec'] ); ?>" class="small-text"></td>
							<td><select name="<?php echo esc_attr( $n ); ?>[good]">
								<option value="" <?php selected( $r['good'], '' ); ?>>নিরপেক্ষ</option>
								<option value="up" <?php selected( $r['good'], 'up' ); ?>>ভালো খবর (সবুজ)</option>
								<option value="down" <?php selected( $r['good'], 'down' ); ?>>খারাপ খবর (লাল)</option>
							</select></td>
							<td><input type="text" name="<?php echo esc_attr( $n ); ?>[code]" value="<?php echo esc_attr( $r['code'] ); ?>" style="width:4.5em" placeholder="USD"></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<p class="submit">
				<button type="submit" class="button button-primary">সংরক্ষণ করুন</button>
				&nbsp; <button type="submit" name="dc_reset" value="1" class="button" onclick="return confirm('সূচক ও লেখা ডিফল্টে ফিরবে। আগের দিনের হিসাব মুছবে না। চালিয়ে যাবেন?');">ডিফল্টে ফিরুন</button>
			</p>
		</form>
	</div>
	<?php
}
