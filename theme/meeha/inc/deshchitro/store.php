<?php
/**
 * দেশচিত্র storage: one row per day in {prefix}meeha_deshchitro.
 *
 *   day        DATE      the day the figures describe (00:00–24:00 site time)
 *   status     draft|publish
 *   data       JSON      { inc: {district: {indicator: n}}, unk: {indicator: n}, notes: {district: text},
 *                         eco: {indicator: number}, hl: [{t, u, d}], summary, sources }
 *   updated_by, updated_at (site time)
 *
 * A published day becomes PUBLIC only after it is over (day < today): editors can fill the sheet during the day,
 * mark it "প্রকাশ", and it appears at midnight by itself.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

const MEEHA_DC_DB_VERSION = '1.0.0';

/**
 * Table name.
 *
 * @return string
 */
function meeha_dc_table() {
	global $wpdb;
	return $wpdb->prefix . 'meeha_deshchitro';
}

/** Create / upgrade the table. */
function meeha_dc_install() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$t  = meeha_dc_table();
	$cs = $wpdb->get_charset_collate();
	dbDelta(
		"CREATE TABLE {$t} (
  day date NOT NULL,
  status varchar(10) NOT NULL DEFAULT 'draft',
  data longtext NOT NULL,
  updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (day),
  KEY status (status)
) {$cs};"
	);
	update_option( 'meeha_dc_db_version', MEEHA_DC_DB_VERSION, false );
}

/** Install lazily (admin, REST or the first page view after an upgrade). */
function meeha_dc_maybe_install() {
	if ( get_option( 'meeha_dc_db_version' ) !== MEEHA_DC_DB_VERSION ) {
		meeha_dc_install();
	}
}
add_action( 'admin_init', 'meeha_dc_maybe_install' );
add_action( 'rest_api_init', 'meeha_dc_maybe_install', 1 );
add_action( 'after_switch_theme', 'meeha_dc_install' );

/**
 * Who may enter figures.
 *
 * @param int $user_id User (0 = current).
 * @return bool
 */
function meeha_dc_can_edit( $user_id = 0 ) {
	$ok = $user_id ? user_can( $user_id, 'edit_others_posts' ) : current_user_can( 'edit_others_posts' );
	return (bool) apply_filters( 'meeha_dc_can_edit', $ok, $user_id );
}

/**
 * Empty day structure.
 *
 * @return array
 */
function meeha_dc_empty_data() {
	return array(
		'inc'     => array(),
		'unk'     => array(),
		'notes'   => array(),
		'eco'     => array(),
		'hl'      => array(),
		'summary' => '',
		'sources' => '',
	);
}

/**
 * Read one day (any status).
 *
 * @param string $day Y-m-d.
 * @return array|null { day, status, data, updated_by, updated_at }
 */
function meeha_dc_get_day( $day ) {
	global $wpdb;
	if ( ! meeha_dc_is_date( $day ) ) {
		return null;
	}
	$t   = meeha_dc_table();
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE day = %s", $day ), ARRAY_A ); // phpcs:ignore WordPress.DB
	return $row ? meeha_dc_decode_row( $row ) : null;
}

/**
 * Decode a DB row.
 *
 * @param array $row Row.
 * @return array
 */
function meeha_dc_decode_row( $row ) {
	$data = json_decode( (string) $row['data'], true );
	return array(
		'day'        => substr( (string) $row['day'], 0, 10 ),
		'status'     => 'publish' === $row['status'] ? 'publish' : 'draft',
		'data'       => array_merge( meeha_dc_empty_data(), is_array( $data ) ? $data : array() ),
		'updated_by' => (int) $row['updated_by'],
		'updated_at' => (string) $row['updated_at'],
	);
}

/**
 * Rows in a date range.
 *
 * @param string $from        Y-m-d (inclusive).
 * @param string $to          Y-m-d (inclusive).
 * @param bool   $public_only Only published days that are over.
 * @return array[] keyed by day
 */
function meeha_dc_days( $from, $to, $public_only = true ) {
	global $wpdb;
	$t   = meeha_dc_table();
	$sql = $wpdb->prepare( "SELECT * FROM {$t} WHERE day >= %s AND day <= %s", $from, $to ); // phpcs:ignore WordPress.DB
	if ( $public_only ) {
		$sql .= $wpdb->prepare( " AND status = 'publish' AND day < %s", meeha_dc_today() );
	}
	$out = array();
	foreach ( (array) $wpdb->get_results( $sql . ' ORDER BY day ASC', ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB
		$r              = meeha_dc_decode_row( $row );
		$out[ $r['day'] ] = $r;
	}
	return $out;
}

/**
 * Latest public day ('' when none).
 *
 * @return string
 */
function meeha_dc_latest_public_day() {
	global $wpdb;
	$t = meeha_dc_table();
	return (string) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(day) FROM {$t} WHERE status = 'publish' AND day < %s", meeha_dc_today() ) ); // phpcs:ignore WordPress.DB
}

/**
 * Earliest public day ('' when none).
 *
 * @return string
 */
function meeha_dc_first_public_day() {
	global $wpdb;
	$t = meeha_dc_table();
	return (string) $wpdb->get_var( $wpdb->prepare( "SELECT MIN(day) FROM {$t} WHERE status = 'publish' AND day < %s", meeha_dc_today() ) ); // phpcs:ignore WordPress.DB
}

/**
 * Sanitise posted day data against the current configuration.
 *
 * @param array $in Raw.
 * @return array
 */
function meeha_dc_sanitize_data( $in ) {
	$in    = is_array( $in ) ? $in : array();
	$dists = meeha_dc_districts();
	$keys  = array();
	foreach ( meeha_dc_config()['indicators'] as $r ) {
		$keys[ $r['key'] ] = 1; // keep values of switched-off indicators too (they may be switched on again).
	}
	$num = function ( $v ) {
		$v = is_string( $v ) ? meeha_en_digits( trim( $v ) ) : $v;
		return max( 0, min( 99999, (int) $v ) );
	};
	$out = meeha_dc_empty_data();
	foreach ( (array) ( isset( $in['inc'] ) ? $in['inc'] : array() ) as $dk => $vals ) {
		if ( ! isset( $dists[ $dk ] ) || ! is_array( $vals ) ) {
			continue;
		}
		foreach ( $vals as $ik => $v ) {
			$n = $num( $v );
			if ( isset( $keys[ $ik ] ) && $n > 0 ) {
				$out['inc'][ $dk ][ $ik ] = $n;
			}
		}
	}
	foreach ( (array) ( isset( $in['unk'] ) ? $in['unk'] : array() ) as $ik => $v ) {
		$n = $num( $v );
		if ( isset( $keys[ $ik ] ) && $n > 0 ) {
			$out['unk'][ $ik ] = $n;
		}
	}
	foreach ( (array) ( isset( $in['notes'] ) ? $in['notes'] : array() ) as $dk => $v ) {
		$v = trim( sanitize_text_field( (string) $v ) );
		if ( isset( $dists[ $dk ] ) && '' !== $v ) {
			$out['notes'][ $dk ] = mb_substr( $v, 0, 220 );
		}
	}
	$eco_keys = array();
	foreach ( meeha_dc_config()['economy'] as $r ) {
		$eco_keys[ $r['key'] ] = 1;
	}
	foreach ( (array) ( isset( $in['eco'] ) ? $in['eco'] : array() ) as $ek => $v ) {
		if ( ! isset( $eco_keys[ $ek ] ) || null === $v || '' === $v ) {
			continue;
		}
		$v = str_replace( array( ',', ' ' ), '', meeha_en_digits( (string) $v ) );
		if ( is_numeric( $v ) ) {
			$out['eco'][ $ek ] = round( (float) $v, 4 );
		}
	}
	foreach ( (array) ( isset( $in['hl'] ) ? $in['hl'] : array() ) as $h ) {
		$text = isset( $h['t'] ) ? trim( sanitize_text_field( (string) $h['t'] ) ) : '';
		if ( '' === $text || count( $out['hl'] ) >= 15 ) {
			continue;
		}
		$d             = isset( $h['d'] ) ? sanitize_key( $h['d'] ) : '';
		$out['hl'][] = array(
			't' => mb_substr( $text, 0, 240 ),
			'u' => isset( $h['u'] ) ? esc_url_raw( (string) $h['u'] ) : '',
			'd' => isset( $dists[ $d ] ) ? $d : '',
		);
	}
	$out['summary'] = isset( $in['summary'] ) ? mb_substr( trim( sanitize_textarea_field( (string) $in['summary'] ) ), 0, 600 ) : '';
	$out['sources'] = isset( $in['sources'] ) ? mb_substr( trim( sanitize_textarea_field( (string) $in['sources'] ) ), 0, 600 ) : '';
	return $out;
}

/**
 * Save one day.
 *
 * @param string $day     Y-m-d.
 * @param array  $data    Raw data.
 * @param string $status  draft|publish.
 * @param int    $user_id Editor.
 * @return array|WP_Error Saved day.
 */
function meeha_dc_save_day( $day, $data, $status, $user_id ) {
	global $wpdb;
	if ( ! meeha_dc_is_date( $day ) ) {
		return new WP_Error( 'meeha_dc_date', 'তারিখ ঠিক নেই।', array( 'status' => 400 ) );
	}
	if ( $day > meeha_dc_today() ) {
		return new WP_Error( 'meeha_dc_future', 'ভবিষ্যতের তারিখের হিসাব রাখা যায় না।', array( 'status' => 400 ) );
	}
	meeha_dc_maybe_install();
	$clean = meeha_dc_sanitize_data( $data );
	$t     = meeha_dc_table();
	$row   = array(
		'day'        => $day,
		'status'     => 'publish' === $status ? 'publish' : 'draft',
		'data'       => wp_json_encode( $clean, JSON_UNESCAPED_UNICODE ),
		'updated_by' => (int) $user_id,
		'updated_at' => current_time( 'mysql' ),
	);
	$exists = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE day = %s", $day ) ); // phpcs:ignore WordPress.DB
	$ok     = $exists ? $wpdb->update( $t, $row, array( 'day' => $day ) ) : $wpdb->insert( $t, $row );
	if ( false === $ok ) {
		return new WP_Error( 'meeha_dc_db', 'সংরক্ষণ করা যায়নি (ডেটাবেস)।', array( 'status' => 500 ) );
	}
	meeha_dc_cache_bump();
	do_action( 'meeha_dc_saved', $day, $row['status'], $clean, $user_id );
	return meeha_dc_get_day( $day );
}

/**
 * Delete one day.
 *
 * @param string $day Y-m-d.
 * @return bool
 */
function meeha_dc_delete_day( $day ) {
	global $wpdb;
	if ( ! meeha_dc_is_date( $day ) ) {
		return false;
	}
	$ok = (bool) $wpdb->delete( meeha_dc_table(), array( 'day' => $day ) );
	meeha_dc_cache_bump();
	return $ok;
}

/** Invalidate cached payloads. */
function meeha_dc_cache_bump() {
	update_option( 'meeha_dc_gen', (int) get_option( 'meeha_dc_gen', 0 ) + 1, false );
}

/**
 * Month overview for the input calendar: day => status ('draft'|'publish').
 *
 * @param string $ym Y-m.
 * @return array
 */
function meeha_dc_month_status( $ym ) {
	global $wpdb;
	if ( ! preg_match( '/^\d{4}-\d{2}$/', $ym ) ) {
		$ym = wp_date( 'Y-m' );
	}
	$t   = meeha_dc_table();
	$out = array();
	foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT day, status FROM {$t} WHERE day LIKE %s", $ym . '-%' ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
		$out[ substr( $r['day'], 0, 10 ) ] = $r['status'];
	}
	return $out;
}
