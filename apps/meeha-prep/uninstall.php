<?php
/**
 * Uninstall: removes data only when "আনইনস্টলে সব ডেটা মুছুন" is on in the settings. Orders are financial
 * records, so by default everything stays.
 *
 * @package meeha-prep
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$mhp_opts = get_option( 'meeha_opt_prep', array() );
if ( empty( $mhp_opts['delete_on_uninstall'] ) ) {
	return;
}

global $wpdb;
foreach ( array( 'orders', 'access', 'attempts', 'quiz', 'cvs', 'bookings', 'points', 'mistakes', 'skills', 'shipments' ) as $mhp_t ) {
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'mhp_' . $mhp_t ); // phpcs:ignore WordPress.DB
}
foreach ( array( 'mh_job', 'mh_question', 'mh_test', 'mh_product', 'mh_event', 'mh_cv_tpl', 'mh_consultant', 'mh_lesson', 'mh_pub' ) as $mhp_pt ) {
	$mhp_ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", $mhp_pt ) ); // phpcs:ignore WordPress.DB
	foreach ( $mhp_ids as $mhp_id ) {
		wp_delete_post( (int) $mhp_id, true );
	}
}
foreach ( array( 'mh_track', 'mh_subject', 'mh_job_type' ) as $mhp_tax ) {
	$mhp_rows = $wpdb->get_results( $wpdb->prepare( "SELECT term_id, term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", $mhp_tax ), ARRAY_A ); // phpcs:ignore WordPress.DB
	foreach ( (array) $mhp_rows as $mhp_row ) {
		$wpdb->delete( $wpdb->term_relationships, array( 'term_taxonomy_id' => (int) $mhp_row['term_taxonomy_id'] ) ); // phpcs:ignore WordPress.DB
		$wpdb->delete( $wpdb->term_taxonomy, array( 'term_taxonomy_id' => (int) $mhp_row['term_taxonomy_id'] ) ); // phpcs:ignore WordPress.DB
		$mhp_left = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE term_id = %d", (int) $mhp_row['term_id'] ) ); // phpcs:ignore WordPress.DB
		if ( ! $mhp_left ) {
			$wpdb->delete( $wpdb->termmeta, array( 'term_id' => (int) $mhp_row['term_id'] ) ); // phpcs:ignore WordPress.DB
			$wpdb->delete( $wpdb->terms, array( 'term_id' => (int) $mhp_row['term_id'] ) ); // phpcs:ignore WordPress.DB
		}
	}
}
foreach ( array( 'meeha_opt_prep', 'mhp_db_version', 'mhp_seeded', 'mhp_seed_pending', 'mhp_quiz_sets', 'mhp_quiz_override', 'mhp_flush_rewrite', 'mhp_cv_seeded', 'mhp_lessons_seeded', 'mhp_prize_results', 'mhp_prize_excluded', 'mhp_daily_ran', 'mhp_bank_done' ) as $mhp_o ) {
	delete_option( $mhp_o );
}
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_mhp\_%' OR option_name LIKE '\_transient\_timeout\_mhp\_%'" ); // phpcs:ignore WordPress.DB
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key IN ('mhp_saved_jobs','mhp_streak','mhp_streak_best','mhp_last_quiz','mhp_district','mhp_alert_types','mhp_remind','mhp_email_list','mhp_reminded','mhp_points','mhp_goal','mhp_target','mhp_perfect','mhp_ship_addr')" ); // phpcs:ignore WordPress.DB
foreach ( array( 'administrator', 'meeha_publisher', 'editor' ) as $mhp_r ) {
	$mhp_role = get_role( $mhp_r );
	if ( $mhp_role ) {
		foreach ( array( 'edit_mhp_items', 'edit_others_mhp_items', 'publish_mhp_items', 'read_private_mhp_items', 'delete_mhp_items', 'delete_private_mhp_items', 'delete_published_mhp_items', 'delete_others_mhp_items', 'edit_private_mhp_items', 'edit_published_mhp_items', 'mhp_manage', 'mhp_manage_orders' ) as $mhp_c ) {
			$mhp_role->remove_cap( $mhp_c );
		}
	}
}
$mhp_up  = wp_upload_dir( null, false );
$mhp_dir = trailingslashit( $mhp_up['basedir'] ) . 'meeha-prep-private';
if ( is_dir( $mhp_dir ) ) {
	foreach ( (array) scandir( $mhp_dir ) as $mhp_f ) {
		if ( is_file( $mhp_dir . '/' . $mhp_f ) ) {
			wp_delete_file( $mhp_dir . '/' . $mhp_f );
		}
	}
	@rmdir( $mhp_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
}
