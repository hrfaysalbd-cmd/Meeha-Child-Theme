<?php
/**
 * One-time (idempotent) migration from the old child theme `meeha-child` (+ parent newsmatic).
 * Runs on `after_switch_theme` and whenever MEEHA_VERSION differs from the stored `meeha_theme_version`.
 * Never deletes or rewrites legacy options / theme_mods (rollback must keep working).
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

/**
 * The old theme slug to copy from.
 *
 * @return string
 */
function meeha_migrate_source_stylesheet() {
	foreach ( array( 'meeha-child', 'newsmatic' ) as $s ) {
		if ( is_array( get_option( 'theme_mods_' . $s ) ) ) {
			return $s;
		}
	}
	return 'meeha-child';
}

/**
 * Resolve a legacy image URL to an attachment ID (0 when it is not in the media library).
 *
 * @param string $url URL.
 * @return int
 */
function meeha_migrate_url_to_id( $url ) {
	$url = trim( (string) $url );
	if ( '' === $url ) {
		return 0;
	}
	$id = attachment_url_to_postid( $url );
	if ( ! $id ) {
		$id = attachment_url_to_postid( preg_replace( '/-\d+x\d+(\.[a-z0-9]+)$/i', '$1', $url ) );
	}
	return (int) $id;
}

/**
 * Run the migration.
 *
 * @param string $trigger What started it (switch|version|manual).
 * @return array The report.
 */
function meeha_migrate_run( $trigger = 'manual' ) {
	static $ran = false;
	if ( $ran && 'manual' !== $trigger ) {
		return (array) get_option( 'meeha_migration_report', array() );
	}
	$ran = true;

	$items = array();
	$add   = function ( $label, $status, $detail = '' ) use ( &$items ) {
		$items[] = array( $label, $status, $detail );
	};
	$src      = meeha_migrate_source_stylesheet();
	$old_mods = get_option( 'theme_mods_' . $src, array() );
	$old_mods = is_array( $old_mods ) ? $old_mods : array();

	/* ---- 1. theme mods --------------------------------------------------- */
	if ( ! empty( $old_mods['custom_logo'] ) ) {
		if ( get_theme_mod( 'custom_logo' ) ) {
			$add( 'লোগো (custom_logo)', 'skip', 'নতুন থিমে আগে থেকেই আছে' );
		} else {
			set_theme_mod( 'custom_logo', (int) $old_mods['custom_logo'] );
			$add( 'লোগো (custom_logo)', 'ok', 'কপি হয়েছে — হেডারে থিমের SVG লোগো থাকবে; ছবি-লোগো চাইলে হেডার ট্যাবে বাছুন' );
		}
	}
	$locations = get_theme_mod( 'nav_menu_locations', array() );
	$locations = is_array( $locations ) ? $locations : array();
	$old_locs  = isset( $old_mods['nav_menu_locations'] ) && is_array( $old_mods['nav_menu_locations'] ) ? $old_mods['nav_menu_locations'] : array();
	$copied    = array();
	foreach ( array( 'primary', 'bottom-menu', 'drawer', 'footer-1', 'footer-2', 'footer-3' ) as $loc ) {
		if ( ! empty( $old_locs[ $loc ] ) && empty( $locations[ $loc ] ) && is_nav_menu( (int) $old_locs[ $loc ] ) ) {
			$locations[ $loc ] = (int) $old_locs[ $loc ];
			$copied[]          = $loc;
		}
	}
	if ( $copied ) {
		set_theme_mod( 'nav_menu_locations', $locations );
		$add( 'মেনু লোকেশন', 'ok', implode( ', ', $copied ) );
	} elseif ( $old_locs ) {
		$add( 'মেনু লোকেশন', 'skip', 'আগে থেকেই বসানো' );
	}
	$mods_copied = array();
	foreach ( array( 'header_image', 'header_image_data', 'background_image', 'background_image_thumb', 'background_color', 'background_repeat', 'background_position_x', 'background_position_y', 'background_size', 'background_attachment' ) as $mod ) {
		if ( isset( $old_mods[ $mod ] ) && false === get_theme_mod( $mod, false ) ) {
			set_theme_mod( $mod, $old_mods[ $mod ] );
			$mods_copied[] = $mod;
		}
	}
	if ( $mods_copied ) {
		$add( 'হেডার/ব্যাকগ্রাউন্ড ছবি', 'ok', implode( ', ', $mods_copied ) );
	}

	// Additional CSS (custom_css post is stored per stylesheet).
	$old_css_post = null;
	if ( ! empty( $old_mods['custom_css_post_id'] ) ) {
		$p = get_post( (int) $old_mods['custom_css_post_id'] );
		if ( $p && 'custom_css' === $p->post_type ) {
			$old_css_post = $p;
		}
	}
	if ( ! $old_css_post ) {
		$old_css_post = wp_get_custom_css_post( $src );
	}
	if ( $old_css_post && '' !== trim( $old_css_post->post_content ) ) {
		$mine = wp_get_custom_css_post( get_stylesheet() );
		if ( $mine && '' !== trim( $mine->post_content ) ) {
			$add( 'অতিরিক্ত CSS', 'skip', 'নতুন থিমে আগে থেকেই আছে' );
		} else {
			$r = wp_update_custom_css_post(
				$old_css_post->post_content,
				array(
					'stylesheet'   => get_stylesheet(),
					'preprocessed' => $old_css_post->post_content_filtered,
				)
			);
			if ( is_wp_error( $r ) ) {
				$add( 'অতিরিক্ত CSS', 'warn', $r->get_error_message() );
			} else {
				if ( get_stylesheet() === get_option( 'stylesheet' ) ) {
					set_theme_mod( 'custom_css_post_id', $r->ID );
				}
				$add( 'অতিরিক্ত CSS', 'ok', 'কপি হয়েছে (' . meeha_bn_digits( strlen( $old_css_post->post_content ) ) . ' বাইট) — পুরোনো থিমের ক্লাস লক্ষ্য করে লেখা নিয়ম নতুন নকশায় কাজ নাও করতে পারে' );
			}
		}
	}

	/* ---- 2. legacy options → new tabs (only tabs that don't exist yet) ---- */
	$home   = get_option( 'meeha_homepage_settings', array() );
	$home   = is_array( $home ) ? $home : array();
	$header = get_option( 'meeha_header_settings', array() );
	$header = is_array( $header ) ? $header : array();
	$footer = get_option( 'meeha_footer_settings', array() );
	$footer = is_array( $footer ) ? $footer : array();

	$import = function ( $tab, $values, $label ) use ( $add ) {
		if ( false !== get_option( 'meeha_opt_' . $tab, false ) ) {
			$add( $label, 'skip', 'নতুন সেটিং আগে থেকেই আছে — বদলানো হয়নি' );
			return;
		}
		if ( ! $values ) {
			return;
		}
		meeha_opt_update( $tab, $values );
		$add( $label, 'ok', implode( ', ', array_keys( $values ) ) );
	};

	// হেডার.
	$h = array();
	if ( isset( $home['breaking_label'] ) && '' !== trim( (string) $home['breaking_label'] ) && 'হেডলাইন' !== $home['breaking_label'] ) {
		$h['latest_label'] = sanitize_text_field( $home['breaking_label'] );
	}
	if ( ! empty( $home['breaking_category'] ) && term_exists( (int) $home['breaking_category'], 'category' ) ) {
		$h['ticker_category'] = (int) $home['breaking_category'];
	}
	if ( array_key_exists( 'breaking_enabled', $home ) && empty( $home['breaking_enabled'] ) ) {
		$h['ticker_mode'] = 'off';
	}
	$import( 'header', $h, 'হেডার (টিকার)' );

	// সোশ্যাল.
	$s   = array();
	$soc = isset( $header['social'] ) && is_array( $header['social'] ) ? $header['social'] : array();
	foreach ( array( 'facebook' => 'facebook', 'youtube' => 'youtube', 'twitter' => 'x', 'instagram' => 'instagram', 'whatsapp' => 'whatsapp' ) as $from => $to ) {
		if ( ! empty( $soc[ $from ] ) ) {
			$s[ $to ] = esc_url_raw( $soc[ $from ] );
		}
	}
	$import( 'social', $s, 'সোশ্যাল লিংক' );

	// বিজ্ঞাপন.
	$a = array();
	if ( ! empty( $header['ad_image_desktop'] ) || ! empty( $header['ad_image_mobile'] ) ) {
		$a['header_leader_on']    = empty( $header['ad_enabled'] ) ? 0 : 1; // paid placement: keep it running if it was.
		$a['header_leader_type']  = 'image';
		$a['header_leader_image'] = meeha_migrate_url_to_id( isset( $header['ad_image_desktop'] ) ? $header['ad_image_desktop'] : '' );
		$mob                      = meeha_migrate_url_to_id( isset( $header['ad_image_mobile'] ) ? $header['ad_image_mobile'] : '' );
		if ( $mob ) {
			$a['header_leader_image_m'] = $mob;
		}
		if ( ! $a['header_leader_image'] && $mob ) {
			$a['header_leader_image'] = $mob;
		}
		$a['header_leader_link']  = esc_url_raw( isset( $header['ad_link'] ) ? $header['ad_link'] : '' );
		$a['header_leader_alt']   = sanitize_text_field( isset( $header['ad_alt'] ) ? $header['ad_alt'] : 'বিজ্ঞাপন' );
		$a['header_leader_label'] = array_key_exists( 'ad_label_enabled', $header ) ? ( empty( $header['ad_label_enabled'] ) ? 0 : 1 ) : 1;
		if ( ! $a['header_leader_image'] ) {
			$add( 'হেডারের বিজ্ঞাপন', 'warn', 'ছবিটি মিডিয়া লাইব্রেরিতে পাওয়া যায়নি (' . esc_url_raw( (string) $header['ad_image_desktop'] ) . ') — বিজ্ঞাপন ট্যাবে আবার বাছুন' );
		}
	}
	if ( shortcode_exists( 'adinserter' ) ) {
		$a['article_side_on']        = 1;
		$a['article_side_type']      = 'shortcode';
		$a['article_side_shortcode'] = '[adinserter block="1"]';
	}
	$import( 'ads', $a, 'বিজ্ঞাপন (হেডার ব্যানার / Ad Inserter)' );

	// ফুটার.
	$f = array();
	if ( ! empty( $footer['about_text'] ) ) {
		$f['about'] = sanitize_textarea_field( $footer['about_text'] );
	}
	if ( ! empty( $footer['copyright_text'] ) ) {
		$f['copyright'] = sanitize_text_field( $footer['copyright_text'] );
	}
	if ( isset( $home['mailpoet_form_id'] ) && '' !== (string) $home['mailpoet_form_id'] ) {
		$f['newsletter_form_id'] = absint( $home['mailpoet_form_id'] );
	}
	if ( ! empty( $home['newsletter_heading'] ) && 'প্রতিদিনের গুরুত্বপূর্ণ খবর সরাসরি আপনার ইনবক্সে' !== $home['newsletter_heading'] ) {
		$f['newsletter_title'] = sanitize_text_field( $home['newsletter_heading'] );
	}
	$import( 'footer', $f, 'ফুটার (পরিচিতি, কপিরাইট, নিউজলেটার ফর্ম)' );

	// SEO.
	$seo = array();
	if ( ! empty( $home['google_news_url'] ) ) {
		$seo['google_news_url'] = esc_url_raw( $home['google_news_url'] );
	}
	$import( 'seo', $seo, 'SEO (Google News)' );

	/* ---- 3. capabilities ---------------------------------------------- */
	$roles = meeha_grant_caps();
	$add( 'অনুমতি (manage_meeha_design / manage_meeha_editorial)', 'ok', implode( ', ', $roles ) );
	if ( ! get_role( 'meeha_publisher' ) ) {
		$add( 'meeha_publisher রোল', 'warn', 'রোলটি এখনো নেই (newsroom-os প্লাগইন চালু হলে স্বয়ংক্রিয়ভাবে অনুমতি পাবে)' );
	}

	/* ---- 4. rewrites (deferred to the next init, after every CPT is registered) ---- */
	update_option( 'meeha_flush_rewrites', 1, false );
	$add( 'পার্মালিংক', 'ok', 'পরের পাতা-লোডে নতুন করে তৈরি হবে' );

	$report = array(
		'time'    => time(),
		'version' => MEEHA_VERSION,
		'from'    => $src,
		'trigger' => $trigger,
		'items'   => $items,
	);
	update_option( 'meeha_migration_report', $report, false );
	if ( 'manual' !== $trigger ) {
		update_option( 'meeha_migration_notice', 1, false );
	}
	update_option( 'meeha_theme_version', MEEHA_VERSION );

	/**
	 * After the foundation migration ran (modules may hook their own imports here).
	 *
	 * @param array  $report  Report.
	 * @param string $trigger Trigger.
	 */
	do_action( 'meeha_migrated', $report, $trigger );
	return $report;
}

add_action(
	'after_switch_theme',
	function () {
		meeha_migrate_run( 'switch' );
	}
);

add_action(
	'init',
	function () {
		// Appearance → Themes → Live Preview loads this theme before the owner activates it: never migrate
		// (or flush) from a preview — after_switch_theme runs the real migration once "Activate" is pressed.
		if ( is_customize_preview() && isset( $GLOBALS['wp_customize'] ) && ! $GLOBALS['wp_customize']->is_theme_active() ) {
			return;
		}
		if ( MEEHA_VERSION !== get_option( 'meeha_theme_version' ) && ! get_transient( 'meeha_migrating' ) ) {
			set_transient( 'meeha_migrating', 1, 60 );
			meeha_migrate_run( 'version' );
			delete_transient( 'meeha_migrating' );
		}
		if ( get_option( 'meeha_flush_rewrites' ) ) {
			delete_option( 'meeha_flush_rewrites' );
			flush_rewrite_rules( false );
		}
	},
	99
);

/**
 * HTML of the migration report.
 *
 * @param array $report Report.
 * @return string
 */
function meeha_migration_report_html( $report ) {
	if ( empty( $report['items'] ) ) {
		return '<p>এখনো কোনো মাইগ্রেশন চলেনি।</p>';
	}
	$icons = array(
		'ok'   => '✔',
		'skip' => '–',
		'warn' => '⚠',
	);
	$out = '<ul class="meeha-migration">';
	foreach ( $report['items'] as $it ) {
		$out .= '<li><span aria-hidden="true">' . esc_html( isset( $icons[ $it[1] ] ) ? $icons[ $it[1] ] : '•' ) . '</span> <strong>' . esc_html( $it[0] ) . '</strong>' . ( $it[2] ? ' — ' . esc_html( $it[2] ) : '' ) . '</li>';
	}
	$out .= '</ul>';
	$out .= '<p class="description">উৎস: <code>' . esc_html( isset( $report['from'] ) ? $report['from'] : '' ) . '</code> · ' . esc_html( meeha_bengali_date( (int) $report['time'] ) ) . ' · পুরোনো সেটিং বা থিম-মড মোছা বা বদলানো হয়নি, তাই আগের থিমে ফিরে যাওয়া যাবে।</p>';
	return $out;
}

add_action(
	'admin_notices',
	function () {
		if ( ! get_option( 'meeha_migration_notice' ) || ! current_user_can( 'manage_meeha_design' ) ) {
			return;
		}
		$report  = get_option( 'meeha_migration_report', array() );
		$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=meeha_dismiss_migration' ), 'meeha_dismiss_migration' );
		echo '<div class="notice notice-info meeha-migration-notice"><p><strong>মিহা থিম চালু হয়েছে — পুরোনো থিম থেকে যা আনা হলো:</strong></p>';
		echo meeha_migration_report_html( $report ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		echo '<p><a class="button button-primary" href="' . esc_url( meeha_settings_url( 'header' ) ) . '">থিমের সেটিং</a> <a class="button" href="' . esc_url( $dismiss ) . '">বুঝেছি, লুকান</a></p></div>';
	}
);

add_action(
	'admin_post_meeha_dismiss_migration',
	function () {
		check_admin_referer( 'meeha_dismiss_migration' );
		if ( current_user_can( 'manage_meeha_design' ) ) {
			delete_option( 'meeha_migration_notice' );
		}
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}
);
