<?php
/**
 * Subscriptions table {prefix}meeha_push_subs (one row per browser).
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

define( 'MEEHA_PUSH_DB_VER', '1' );

/**
 * Table name.
 *
 * @return string
 */
function meeha_push_table() {
	global $wpdb;
	return $wpdb->prefix . 'meeha_push_subs';
}

/**
 * Create/upgrade the table (dbDelta, once per version).
 */
function meeha_push_install() {
	if ( MEEHA_PUSH_DB_VER === get_option( 'meeha_push_db_ver' ) ) {
		return;
	}
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$t = meeha_push_table();
	dbDelta(
		"CREATE TABLE {$t} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		endpoint text NOT NULL,
		endpoint_hash char(64) NOT NULL,
		p256dh varchar(128) NOT NULL,
		auth varchar(48) NOT NULL,
		created_at datetime NOT NULL,
		last_ok datetime NULL,
		fails smallint(5) unsigned NOT NULL DEFAULT 0,
		PRIMARY KEY  (id),
		UNIQUE KEY endpoint_hash (endpoint_hash)
		) " . $wpdb->get_charset_collate() . ';'
	);
	update_option( 'meeha_push_db_ver', MEEHA_PUSH_DB_VER, true );
}
add_action( 'after_switch_theme', 'meeha_push_install' );
add_action(
	'admin_init',
	'meeha_push_install'
);

/**
 * Save (insert or refresh) a subscription.
 *
 * @param string $endpoint Push service URL.
 * @param string $p256dh   Browser public key (base64url).
 * @param string $auth     Auth secret (base64url).
 * @return bool
 */
function meeha_push_save_sub( $endpoint, $p256dh, $auth ) {
	global $wpdb;
	meeha_push_install();
	$hash = hash( 'sha256', $endpoint );
	$now  = current_time( 'mysql', true );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$ok = $wpdb->query(
		$wpdb->prepare(
			'INSERT INTO ' . meeha_push_table() . ' (endpoint, endpoint_hash, p256dh, auth, created_at, fails) VALUES (%s, %s, %s, %s, %s, 0)
			ON DUPLICATE KEY UPDATE p256dh = VALUES(p256dh), auth = VALUES(auth), fails = 0', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$endpoint,
			$hash,
			$p256dh,
			$auth,
			$now
		)
	);
	delete_transient( 'meeha_push_count' );
	return false !== $ok;
}

/**
 * Delete a subscription by endpoint.
 *
 * @param string $endpoint Endpoint.
 */
function meeha_push_delete_sub( $endpoint ) {
	global $wpdb;
	$wpdb->delete( meeha_push_table(), array( 'endpoint_hash' => hash( 'sha256', (string) $endpoint ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	delete_transient( 'meeha_push_count' );
}

/**
 * Number of subscribed browsers (cached 10 min).
 *
 * @return int
 */
function meeha_push_count() {
	$n = get_transient( 'meeha_push_count' );
	if ( false === $n ) {
		global $wpdb;
		if ( get_option( 'meeha_push_db_ver' ) ) {
			$n = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . meeha_push_table() ); // phpcs:ignore WordPress.DB
		} else {
			$n = 0;
		}
		set_transient( 'meeha_push_count', $n, 10 * MINUTE_IN_SECONDS );
	}
	return (int) $n;
}

/**
 * One batch of subscriptions after an id.
 *
 * @param int $after Last id handled.
 * @param int $limit Batch size.
 * @return array rows (id, endpoint, p256dh, auth)
 */
function meeha_push_batch( $after, $limit ) {
	global $wpdb;
	// phpcs:ignore WordPress.DB
	return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, endpoint, p256dh, auth FROM ' . meeha_push_table() . ' WHERE id > %d ORDER BY id ASC LIMIT %d', $after, $limit ), ARRAY_A );
}
