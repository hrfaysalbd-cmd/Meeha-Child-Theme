<?php
/**
 * Admin glue for the legacy pages.
 *
 * All legacy admin pages keep their slugs (admin.php?page=meeha-security-settings,
 * meeha-registration-settings, meeha-upazila-settings, meeha-reports, meeha-correspondents) and are
 * registered (priority 20) as submenus of the foundation's top-level "মিহা থিম" menu
 * (slug meeha-theme). If that menu is missing (foundation not loaded), a minimal fallback parent
 * is registered so the pages stay reachable.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

function meeha_legacy_ensure_parent_menu() {
	global $menu;
	$parent = meeha_legacy_menu_parent();
	foreach ( (array) $menu as $item ) {
		if ( isset( $item[2] ) && $parent === $item[2] ) {
			return;
		}
	}
	add_menu_page( 'মিহা থিম', 'মিহা থিম', 'edit_posts', $parent, 'meeha_legacy_fallback_menu_page', 'dashicons-admin-appearance', 59 );
}
add_action( 'admin_menu', 'meeha_legacy_ensure_parent_menu', 15 );

function meeha_legacy_fallback_menu_page() {
	$links = array(
		'meeha-security-settings'     => array( 'নিরাপত্তা ও লগইন', 'manage_options' ),
		'meeha-registration-settings' => array( 'রেজিস্ট্রেশন', 'manage_options' ),
		'meeha-upazila-settings'      => array( 'উপজেলা', 'manage_options' ),
		'meeha-reports'               => array( 'রিপোর্ট', 'manage_options' ),
		'meeha-correspondents'        => array( 'সংবাদদাতা', 'list_users' ),
	);
	echo '<div class="wrap"><h1>মিহা থিম</h1><ul>';
	foreach ( $links as $slug => $l ) {
		if ( current_user_can( $l[1] ) ) {
			echo '<li><a href="' . esc_url( admin_url( 'admin.php?page=' . $slug ) ) . '">' . esc_html( $l[0] ) . '</a></li>';
		}
	}
	echo '</ul></div>';
}
