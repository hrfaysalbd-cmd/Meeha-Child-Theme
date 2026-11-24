<?php
/**
 * r49 — বিজ্ঞাপনের দেখা ও ক্লিক: the theme's ad script sends one small beacon per page view
 * (the placements that were at least half on screen for a second) and one per click. They are
 * summed per day, placement and client in nros_ad_stats — no reader data, no IP, no cookie.
 * The publisher app reads a report (7/30/90 days, per placement and per client, CSV).
 *
 * @package NewsroomOS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Ad impression / click counter.
 */
class NROS_Ad_Stats {

	/** Beacons one address may send in ten minutes. */
	const RATE_PER_10MIN = 90;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_filter( 'meeha_ads_beacon_url', array( __CLASS__, 'beacon_url' ) );
	}

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'nros_ad_stats';
	}

	/**
	 * Schema (DB 19).
	 *
	 * @param string $c Charset/collate.
	 * @return string
	 */
	public static function schema( $c ) {
		$t = self::table();
		return "CREATE TABLE {$t} (
			day DATE NOT NULL,
			slot VARCHAR(40) NOT NULL,
			client BIGINT UNSIGNED NOT NULL DEFAULT 0,
			imp BIGINT UNSIGNED NOT NULL DEFAULT 0,
			clk BIGINT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (day,slot,client),
			KEY day (day)
		) {$c};";
	}

	/**
	 * Counting is on unless the publisher turned it off (ads option stats_on = 0).
	 *
	 * @return bool
	 */
	public static function enabled() {
		if ( ! function_exists( 'meeha_opt' ) ) {
			return false;
		}
		$o = meeha_opt( 'ads' );
		return ! isset( $o['stats_on'] ) || ! empty( $o['stats_on'] );
	}

	/**
	 * Filter for the theme: where to send beacons ('' = do not count).
	 *
	 * @param string $url Incoming.
	 * @return string
	 */
	public static function beacon_url( $url ) {
		return self::enabled() ? rest_url( NROS_API_NS . '/ads/beacon' ) : $url;
	}

	/**
	 * Routes.
	 */
	public static function register_routes() {
		register_rest_route(
			NROS_API_NS,
			'/ads/beacon',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'beacon' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			NROS_API_NS,
			'/admin/ads-manager/report',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'report' ),
				'permission_callback' => array( 'NROS_Core', 'require_staff' ),
			)
		);
	}

	/**
	 * Same-site check: Origin (else Referer) must be this site.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	protected static function same_site( $request ) {
		$origin = (string) $request->get_header( 'origin' );
		if ( '' === $origin || 'null' === $origin ) {
			$origin = (string) $request->get_header( 'referer' );
		}
		$host = strtolower( (string) wp_parse_url( $origin, PHP_URL_HOST ) );
		if ( '' === $host ) {
			return false;
		}
		$hosts = array_filter( array_map( 'strtolower', array( wp_parse_url( home_url(), PHP_URL_HOST ), wp_parse_url( site_url(), PHP_URL_HOST ) ) ) );
		return in_array( $host, $hosts, true );
	}

	/**
	 * Fixed-window rate limit per (hashed, never stored raw) address.
	 *
	 * @return bool
	 */
	protected static function within_rate() {
		$key    = class_exists( 'NROS_Videos' ) && method_exists( 'NROS_Videos', 'client_key' ) ? NROS_Videos::client_key() : ( isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '' );
		$bucket = 'nros_adb_' . substr( hash_hmac( 'sha256', (string) $key, wp_salt( 'nonce' ) ), 0, 16 ) . '_' . (int) floor( time() / 600 );
		$count  = (int) get_transient( $bucket );
		if ( $count >= self::RATE_PER_10MIN ) {
			return false;
		}
		set_transient( $bucket, $count + 1, 600 );
		return true;
	}

	/**
	 * Which client a placement is sold to right now (0 = none / house).
	 *
	 * @param string $slot Slot.
	 * @return int
	 */
	protected static function client_of( $slot ) {
		$o = function_exists( 'meeha_opt' ) ? meeha_opt( 'ads' ) : array();
		return isset( $o[ $slot . '_client' ] ) ? absint( $o[ $slot . '_client' ] ) : 0;
	}

	/**
	 * Add to today's row for a placement.
	 *
	 * @param string $slot Slot id.
	 * @param int    $imp  Impressions.
	 * @param int    $clk  Clicks.
	 */
	public static function add( $slot, $imp, $clk ) {
		global $wpdb;
		$t      = self::table();
		$day    = current_time( 'Y-m-d' );
		$client = self::client_of( $slot );
		// Portable "upsert" (MySQL and the SQLite test site): make sure the row exists, then add.
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$t} (day, slot, client, imp, clk) VALUES (%s, %s, %d, 0, 0)", $day, $slot, $client ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET imp = imp + %d, clk = clk + %d WHERE day = %s AND slot = %s AND client = %d", (int) $imp, (int) $clk, $day, $slot, $client ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * POST /ads/beacon — {v:[slot…]} views or {c:slot} a click. Always answers 204-like.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function beacon( $request ) {
		$none = new WP_REST_Response( null, 204 );
		if ( ! self::enabled() || ! function_exists( 'meeha_ad_slots' ) || ! self::same_site( $request ) ) {
			return $none;
		}
		$body = json_decode( (string) $request->get_body(), true );
		if ( ! is_array( $body ) ) {
			return $none;
		}
		if ( ! self::within_rate() ) {
			return new WP_REST_Response( null, 429 );
		}
		$known = meeha_ad_slots();
		if ( isset( $body['v'] ) && is_array( $body['v'] ) ) {
			$seen = array();
			foreach ( array_slice( $body['v'], 0, 24 ) as $s ) {
				$s = sanitize_key( (string) $s );
				if ( isset( $known[ $s ] ) && ! isset( $seen[ $s ] ) ) {
					$seen[ $s ] = 1;
					self::add( $s, 1, 0 );
				}
			}
		}
		if ( isset( $body['c'] ) ) {
			$s = sanitize_key( (string) $body['c'] );
			if ( isset( $known[ $s ] ) ) {
				self::add( $s, 0, 1 );
			}
		}
		return $none;
	}

	/**
	 * Totals for the last N days — used by the overview card too.
	 *
	 * @param int $days Days (1–366).
	 * @return array
	 */
	public static function summary( $days ) {
		global $wpdb;
		$t    = self::table();
		$days = max( 1, min( 366, (int) $days ) );
		$from = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -' . ( $days - 1 ) . ' days' ) );
		// The table may not exist yet if the DB upgrade has not run.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! $exists ) {
			return array( 'from' => $from, 'days' => $days, 'imp' => 0, 'clk' => 0, 'slots' => array(), 'clients' => array(), 'daily' => array() );
		}
		$slots = (array) $wpdb->get_results( $wpdb->prepare( "SELECT slot, SUM(imp) AS imp, SUM(clk) AS clk FROM {$t} WHERE day >= %s GROUP BY slot ORDER BY imp DESC", $from ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$cl    = (array) $wpdb->get_results( $wpdb->prepare( "SELECT client, SUM(imp) AS imp, SUM(clk) AS clk FROM {$t} WHERE day >= %s AND client > 0 GROUP BY client ORDER BY imp DESC", $from ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$daily = (array) $wpdb->get_results( $wpdb->prepare( "SELECT day, SUM(imp) AS imp, SUM(clk) AS clk FROM {$t} WHERE day >= %s GROUP BY day ORDER BY day ASC", $from ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$labels = function_exists( 'meeha_ad_slots' ) ? meeha_ad_slots() : array();
		$names  = array();
		if ( $cl ) {
			$ids  = implode( ',', array_map( 'absint', wp_list_pluck( $cl, 'client' ) ) );
			$rows = (array) $wpdb->get_results( "SELECT id, business_name FROM {$wpdb->prefix}nros_ad_clients WHERE id IN ({$ids})", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( $rows as $r ) {
				$names[ (int) $r['id'] ] = (string) $r['business_name'];
			}
		}
		$ctr = function ( $imp, $clk ) {
			return $imp > 0 ? round( 100 * $clk / $imp, 2 ) : 0;
		};
		$imp_all = 0;
		$clk_all = 0;
		$out_s   = array();
		foreach ( $slots as $r ) {
			$i        = (int) $r['imp'];
			$c        = (int) $r['clk'];
			$imp_all += $i;
			$clk_all += $c;
			$out_s[]  = array(
				'slot'  => (string) $r['slot'],
				'label' => isset( $labels[ $r['slot'] ] ) ? (string) $labels[ $r['slot'] ][0] : (string) $r['slot'],
				'imp'   => $i,
				'clk'   => $c,
				'ctr'   => $ctr( $i, $c ),
			);
		}
		$out_c = array();
		foreach ( $cl as $r ) {
			$out_c[] = array(
				'client' => (int) $r['client'],
				'name'   => isset( $names[ (int) $r['client'] ] ) ? $names[ (int) $r['client'] ] : '#' . (int) $r['client'],
				'imp'    => (int) $r['imp'],
				'clk'    => (int) $r['clk'],
				'ctr'    => $ctr( (int) $r['imp'], (int) $r['clk'] ),
			);
		}
		$out_d = array();
		foreach ( $daily as $r ) {
			$out_d[] = array( 'day' => (string) $r['day'], 'imp' => (int) $r['imp'], 'clk' => (int) $r['clk'] );
		}
		return array(
			'from'    => $from,
			'days'    => $days,
			'imp'     => $imp_all,
			'clk'     => $clk_all,
			'ctr'     => $ctr( $imp_all, $clk_all ),
			'slots'   => $out_s,
			'clients' => $out_c,
			'daily'   => $out_d,
		);
	}

	/**
	 * GET /admin/ads-manager/report?days=30[&client=ID][&format=csv]
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	public static function report( $request ) {
		$days   = (int) ( $request->get_param( 'days' ) ? $request->get_param( 'days' ) : 30 );
		$client = absint( $request->get_param( 'client' ) );
		$data   = self::summary( $days );
		$data['enabled'] = self::enabled();
		if ( $client ) {
			global $wpdb;
			$t    = self::table();
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT day, slot, imp, clk FROM {$t} WHERE day >= %s AND client = %d ORDER BY day ASC, slot ASC", $data['from'], $client ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$data['client_rows'] = $rows;
		}
		if ( 'csv' === $request->get_param( 'format' ) ) {
			global $wpdb;
			$t     = self::table();
			$where = $client ? $wpdb->prepare( ' AND client = %d', $client ) : '';
			$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT day, slot, client, imp, clk FROM {$t} WHERE day >= %s", $data['from'] ) . $where . ' ORDER BY day ASC, slot ASC', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
			$labels = function_exists( 'meeha_ad_slots' ) ? meeha_ad_slots() : array();
			$lines  = array( 'তারিখ,জায়গা,স্লট,ক্লায়েন্ট আইডি,দেখা,ক্লিক,ক্লিক হার %' );
			foreach ( $rows as $r ) {
				$label   = isset( $labels[ $r['slot'] ] ) ? $labels[ $r['slot'] ][0] : $r['slot'];
				$i       = (int) $r['imp'];
				$c       = (int) $r['clk'];
				$lines[] = implode(
					',',
					array(
						$r['day'],
						'"' . str_replace( '"', '""', $label ) . '"',
						$r['slot'],
						(int) $r['client'],
						$i,
						$c,
						$i > 0 ? round( 100 * $c / $i, 2 ) : 0,
					)
				);
			}
			$data['csv'] = "\xEF\xBB\xBF" . implode( "\r\n", $lines ) . "\r\n";
		}
		return $data;
	}
}
