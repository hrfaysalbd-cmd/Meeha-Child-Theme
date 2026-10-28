<?php
/**
 * Metrics storage: custom tables (dbDelta, versioned) and portable atomic write helpers.
 *
 * Tables ({prefix} = $wpdb->prefix):
 *   meeha_views_hourly (post_id, hour DATETIME UTC 'Y-m-d H:00:00', views)      PK (post_id, hour)
 *   meeha_views_daily  (post_id, day DATE site-local, views)                      PK (post_id, day)  ← hourly rows > 35 days
 *   meeha_engagement   (post_id, day DATE site-local, reactions, shares, comments) PK (post_id, day)
 *   meeha_visitor_log  (vkey = hashed IP, post_id, kind, val, n, ts)               PK (vkey, post_id, kind)
 *      kind 'view' (24 h dedupe), 'share:{net}' (24 h dedupe), 'react' (val = this visitor's reaction),
 *      'rl:{bucket}' (post_id 0; fixed-window rate limit: n hits until ts).
 *   meeha_live         (sid = hashed IP + browser id, post_id, dev, src, first, ts)  PK sid  ← realtime visitors (2.4.2)
 *
 * Every write is a single-row statement whose outcome is decided by the database (UPDATE … SET x = x + 1,
 * INSERT IGNORE, compare-and-swap UPDATE … WHERE meta_value = old), so concurrent requests never lose or
 * double-count. Only MySQL/MariaDB/SQLite-portable SQL is used (no ON DUPLICATE KEY, no GREATEST).
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

const MEEHA_METRICS_DB_VERSION = '1.1.0';

/**
 * Table name helper.
 *
 * @param string $t hourly|daily|engagement|log.
 * @return string
 */
function meeha_metrics_table( $t ) {
	global $wpdb;
	$map = array(
		'hourly'     => 'meeha_views_hourly',
		'daily'      => 'meeha_views_daily',
		'engagement' => 'meeha_engagement',
		'log'        => 'meeha_visitor_log',
		'live'       => 'meeha_live',
	);
	return $wpdb->prefix . ( isset( $map[ $t ] ) ? $map[ $t ] : $map['hourly'] );
}

/**
 * Create / upgrade the tables (dbDelta).
 */
function meeha_metrics_install() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$cs = $wpdb->get_charset_collate();
	$h  = meeha_metrics_table( 'hourly' );
	$d  = meeha_metrics_table( 'daily' );
	$e  = meeha_metrics_table( 'engagement' );
	$l  = meeha_metrics_table( 'log' );
	$v  = meeha_metrics_table( 'live' );
	dbDelta(
		array(
			"CREATE TABLE {$h} (
  post_id bigint(20) unsigned NOT NULL,
  hour datetime NOT NULL,
  views int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY  (post_id,hour),
  KEY hour (hour)
) {$cs};",
			"CREATE TABLE {$d} (
  post_id bigint(20) unsigned NOT NULL,
  day date NOT NULL,
  views int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY  (post_id,day),
  KEY day (day)
) {$cs};",
			"CREATE TABLE {$e} (
  post_id bigint(20) unsigned NOT NULL,
  day date NOT NULL,
  reactions int(11) NOT NULL DEFAULT 0,
  shares int(11) NOT NULL DEFAULT 0,
  comments int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY  (post_id,day),
  KEY day (day)
) {$cs};",
			"CREATE TABLE {$l} (
  vkey char(24) NOT NULL,
  post_id bigint(20) unsigned NOT NULL DEFAULT 0,
  kind varchar(24) NOT NULL,
  val varchar(32) NOT NULL DEFAULT '',
  n int(11) NOT NULL DEFAULT 0,
  ts int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (vkey,post_id,kind),
  KEY ts (ts)
) {$cs};",
			"CREATE TABLE {$v} (
  sid char(24) NOT NULL,
  post_id bigint(20) unsigned NOT NULL DEFAULT 0,
  dev char(1) NOT NULL DEFAULT 'd',
  src varchar(12) NOT NULL DEFAULT '',
  first int(10) unsigned NOT NULL DEFAULT 0,
  ts int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (sid),
  KEY ts (ts)
) {$cs};",
		)
	);
	update_option( 'meeha_metrics_db_version', MEEHA_METRICS_DB_VERSION, true );
}

/**
 * Install on activation / version change (cheap autoloaded option check).
 */
function meeha_metrics_maybe_install() {
	if ( get_option( 'meeha_metrics_db_version' ) !== MEEHA_METRICS_DB_VERSION ) {
		meeha_metrics_install();
	}
}
add_action( 'after_switch_theme', 'meeha_metrics_install' );
add_action( 'init', 'meeha_metrics_maybe_install', 5 );

/**
 * Whether the tables exist (the option is written only after dbDelta ran).
 *
 * @return bool
 */
function meeha_metrics_ready() {
	return get_option( 'meeha_metrics_db_version' ) === MEEHA_METRICS_DB_VERSION;
}

/* --------------------------------------------------------------------------
 * Time helpers
 * ------------------------------------------------------------------------ */

/**
 * Current UTC hour key.
 *
 * @param int|null $ts Timestamp.
 * @return string 'Y-m-d H:00:00'
 */
function meeha_metrics_hour( $ts = null ) {
	return gmdate( 'Y-m-d H:00:00', null === $ts ? time() : (int) $ts );
}

/**
 * Site-local date (Y-m-d) for a timestamp.
 *
 * @param int|null $ts Timestamp.
 * @return string
 */
function meeha_metrics_day( $ts = null ) {
	return wp_date( 'Y-m-d', null === $ts ? time() : (int) $ts );
}

/**
 * Unix timestamp of today's local midnight (site timezone, Asia/Dhaka on live).
 *
 * @param int|null $now Now.
 * @return int
 */
function meeha_metrics_local_midnight( $now = null ) {
	$now = null === $now ? time() : (int) $now;
	$dt  = new DateTime( '@' . $now );
	$dt->setTimezone( wp_timezone() );
	$dt->setTime( 0, 0, 0 );
	return $dt->getTimestamp();
}

/* --------------------------------------------------------------------------
 * Portable atomic helpers
 * ------------------------------------------------------------------------ */

/**
 * Add deltas to counter columns of the row identified by $key (insert the row when missing).
 *
 * @param string $table  Full table name.
 * @param array  $key    col => value (primary key columns).
 * @param array  $deltas col => int delta (may be negative; results are clamped at 0).
 * @return bool
 */
function meeha_metrics_bump( $table, $key, $deltas ) {
	global $wpdb;
	$set   = array();
	$sargs = array();
	foreach ( $deltas as $col => $d ) {
		$col   = preg_replace( '/[^a-z_]/', '', $col );
		$set[] = "`{$col}` = CASE WHEN `{$col}` + %d < 0 THEN 0 ELSE `{$col}` + %d END";
		$sargs[] = (int) $d;
		$sargs[] = (int) $d;
	}
	$where = array();
	$wargs = array();
	foreach ( $key as $col => $v ) {
		$col     = preg_replace( '/[^a-z_]/', '', $col );
		$where[] = "`{$col}` = " . ( is_int( $v ) ? '%d' : '%s' );
		$wargs[] = $v;
	}
	$update = $wpdb->prepare( "UPDATE {$table} SET " . implode( ', ', $set ) . ' WHERE ' . implode( ' AND ', $where ), array_merge( $sargs, $wargs ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	if ( $wpdb->query( $update ) ) { // phpcs:ignore WordPress.DB.PreparedSQL
		return true;
	}
	$cols = array();
	$ph   = array();
	$vals = array();
	foreach ( $key as $col => $v ) {
		$cols[] = '`' . preg_replace( '/[^a-z_]/', '', $col ) . '`';
		$ph[]   = is_int( $v ) ? '%d' : '%s';
		$vals[] = $v;
	}
	foreach ( $deltas as $col => $d ) {
		$cols[] = '`' . preg_replace( '/[^a-z_]/', '', $col ) . '`';
		$ph[]   = '%d';
		$vals[] = max( 0, (int) $d );
	}
	$insert = $wpdb->prepare( "INSERT IGNORE INTO {$table} (" . implode( ',', $cols ) . ') VALUES (' . implode( ',', $ph ) . ')', $vals ); // phpcs:ignore WordPress.DB.PreparedSQL
	if ( $wpdb->query( $insert ) ) { // phpcs:ignore WordPress.DB.PreparedSQL
		return true;
	}
	// Lost an insert race: the row exists now.
	return (bool) $wpdb->query( $update ); // phpcs:ignore WordPress.DB.PreparedSQL
}

/**
 * Hashed visitor key (never stores raw IPs). Filter `meeha_metrics_client_ip` may supply the IP
 * (e.g. behind a trusted proxy); B's meeha_client_ip() honours the "trust proxy" security setting.
 *
 * @return string 24 chars.
 */
function meeha_metrics_vkey() {
	$ip = function_exists( 'meeha_client_ip' ) ? meeha_client_ip() : ( isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '' );
	$ip = (string) apply_filters( 'meeha_metrics_client_ip', $ip );
	// IPv6 is bucketed to its /64 (one subscriber allocation), so address rotation inside a VPS's /64
	// cannot fake distinct visitors; IPv4 stays per address.
	$ip = function_exists( 'meeha_ip_bucket' ) ? meeha_ip_bucket( $ip ) : $ip;
	return substr( wp_hash( $ip . '|meeha-metrics' ), 0, 24 );
}

/**
 * True the first time (vkey, post, kind) is seen within $ttl seconds — atomic.
 *
 * @param string $vkey    Visitor key.
 * @param int    $post_id Post.
 * @param string $kind    Kind.
 * @param int    $ttl     Seconds.
 * @return bool
 */
function meeha_metrics_once( $vkey, $post_id, $kind, $ttl ) {
	global $wpdb;
	$t   = meeha_metrics_table( 'log' );
	$now = time();
	$ins = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$t} (vkey, post_id, kind, val, n, ts) VALUES (%s, %d, %s, '', 1, %d)", $vkey, $post_id, $kind, $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	if ( $ins ) {
		return true;
	}
	return (bool) $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET ts = %d, n = n + 1 WHERE vkey = %s AND post_id = %d AND kind = %s AND ts <= %d", $now, $vkey, $post_id, $kind, $now - (int) $ttl ) ); // phpcs:ignore WordPress.DB.PreparedSQL
}

/**
 * Fixed-window per-visitor rate limit — atomic. True when this hit is allowed (and counted).
 *
 * @param string $bucket Bucket (≤ 20 chars).
 * @param int    $max    Hits per window.
 * @param int    $window Seconds.
 * @param string $vkey   Visitor key (default: current).
 * @return bool
 */
function meeha_metrics_rate_ok( $bucket, $max, $window, $vkey = '' ) {
	global $wpdb;
	$max = (int) apply_filters( 'meeha_metrics_rate_max', (int) $max, $bucket );
	if ( $max <= 0 ) {
		return true;
	}
	$t    = meeha_metrics_table( 'log' );
	$vkey = $vkey ? $vkey : meeha_metrics_vkey();
	$kind = 'rl:' . substr( sanitize_key( $bucket ), 0, 20 );
	$now  = time();
	$end  = $now + (int) $window;
	if ( $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$t} (vkey, post_id, kind, val, n, ts) VALUES (%s, 0, %s, '', 1, %d)", $vkey, $kind, $end ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL
		return true;
	}
	if ( $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET n = 1, ts = %d WHERE vkey = %s AND post_id = 0 AND kind = %s AND ts <= %d", $end, $vkey, $kind, $now ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL
		return true;
	}
	return (bool) $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET n = n + 1 WHERE vkey = %s AND post_id = 0 AND kind = %s AND n < %d", $vkey, $kind, $max ) ); // phpcs:ignore WordPress.DB.PreparedSQL
}

/**
 * Atomically increment the integer post meta `_meeha_views` (creating it when missing).
 *
 * @param int $post_id Post.
 * @param int $by      Increment.
 * @return int New value.
 */
function meeha_metrics_incr_views( $post_id, $by = 1 ) {
	global $wpdb;
	$post_id = (int) $post_id;
	for ( $i = 0; $i < 5; $i++ ) {
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_meeha_views' ORDER BY meta_id ASC LIMIT 1", $post_id ) );
		if ( ! $row ) {
			if ( add_post_meta( $post_id, '_meeha_views', (int) $by, true ) ) {
				return (int) $by;
			}
			continue; // created concurrently — retry as an update.
		}
		$raw = (string) $row->meta_value;
		if ( ! preg_match( '/^\d+$/', $raw ) ) {
			// Normalise a non-numeric legacy value first (strict SQL modes reject '' + 1).
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_value = %s WHERE meta_id = %d AND meta_value = %s", (string) max( 0, (int) $raw ), $row->meta_id, $raw ) );
			continue;
		}
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_value = meta_value + %d WHERE meta_id = %d", (int) $by, $row->meta_id ) );
		wp_cache_delete( $post_id, 'post_meta' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_id = %d", $row->meta_id ) );
	}
	wp_cache_delete( $post_id, 'post_meta' );
	return (int) get_post_meta( $post_id, '_meeha_views', true );
}

/**
 * Compare-and-swap update of an array post meta (`_meeha_share_counts`, `_meeha_reactions`).
 *
 * @param int      $post_id Post.
 * @param string   $key     Meta key.
 * @param callable $fn      fn( array $current ): array $new.
 * @return array Final value.
 */
function meeha_metrics_update_array_meta( $post_id, $key, $fn ) {
	global $wpdb;
	$post_id = (int) $post_id;
	$new     = array();
	for ( $i = 0; $i < 8; $i++ ) {
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC LIMIT 1", $post_id, $key ) );
		if ( ! $row ) {
			$new = (array) call_user_func( $fn, array() );
			if ( add_post_meta( $post_id, $key, $new, true ) ) {
				return $new;
			}
			continue;
		}
		$cur = maybe_unserialize( $row->meta_value );
		$cur = is_array( $cur ) ? $cur : array();
		$new = (array) call_user_func( $fn, $cur );
		if ( $new === $cur ) {
			return $cur;
		}
		$ok = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_value = %s WHERE meta_id = %d AND meta_value = %s", maybe_serialize( $new ), $row->meta_id, $row->meta_value ) );
		if ( $ok ) {
			wp_cache_delete( $post_id, 'post_meta' );
			return $new;
		}
	}
	wp_cache_delete( $post_id, 'post_meta' );
	return $new;
}

/**
 * Integer-clean copy of a counts array (legacy values may be strings).
 *
 * @param mixed $arr Stored meta.
 * @return array<string,int>
 */
function meeha_metrics_int_counts( $arr ) {
	$out = array();
	if ( is_array( $arr ) ) {
		foreach ( $arr as $k => $v ) {
			$out[ (string) $k ] = max( 0, (int) $v );
		}
	}
	return $out;
}
