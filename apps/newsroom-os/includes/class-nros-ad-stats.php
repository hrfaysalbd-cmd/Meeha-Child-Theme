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

	/**
	 * Beacons one address + browser may send in ten minutes. r50: per address AND browser, and higher —
	 * mobile networks put many readers behind one address, and every honest view should count.
	 */
	const RATE_PER_10MIN = 240;

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
	 * Schema (DB 19; DB 20 adds srv = ইমপ্রেশন, the box was shown on the page; imp stays "নিশ্চিত দেখা").
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
			srv BIGINT UNSIGNED NOT NULL DEFAULT 0,
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
		$ua     = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$bucket = 'nros_adb_' . substr( hash_hmac( 'sha256', (string) $key . '|' . $ua, wp_salt( 'nonce' ) ), 0, 16 ) . '_' . (int) floor( time() / 600 );
		$count  = (int) get_transient( $bucket );
		if ( $count >= self::RATE_PER_10MIN ) {
			return false;
		}
		set_transient( $bucket, $count + 1, 600 );
		return true;
	}

	/**
	 * Which client a placement is sold to right now (0 = none / house). `card_news` / `card_quote`
	 * are the reader photocard downloads (their strip's client).
	 *
	 * @param string $slot Slot.
	 * @return int
	 */
	public static function client_of( $slot ) {
		if ( 'card_news' === $slot || 'card_quote' === $slot ) {
			$k = 'card_news' === $slot ? 'news_reader_ad_client' : 'quote_reader_ad_client';
			$v = function_exists( 'meeha_studio_opt' ) ? meeha_studio_opt( $k, 0 ) : 0;
			return absint( $v );
		}
		$o = function_exists( 'meeha_opt' ) ? meeha_opt( 'ads' ) : array();
		return isset( $o[ $slot . '_client' ] ) ? absint( $o[ $slot . '_client' ] ) : 0;
	}

	/**
	 * Placements that are counted: the theme's slots plus the two reader photocard downloads.
	 *
	 * @return array slot => label
	 */
	public static function known_slots() {
		$out = array();
		foreach ( ( function_exists( 'meeha_ad_slots' ) ? meeha_ad_slots() : array() ) as $id => $def ) {
			$out[ $id ] = (string) $def[0];
		}
		$out['card_news']  = 'পাঠকের ফটোকার্ড ডাউনলোড (খবর)';
		$out['card_quote'] = 'পাঠকের ফটোকার্ড ডাউনলোড (উক্তি)';
		return $out;
	}

	/**
	 * Tracking id of a placement for a client, e.g. MH-0007-article_1 (the same one the theme prints
	 * on the ad box and puts in utm_campaign).
	 *
	 * @param string $slot   Slot.
	 * @param int    $client Client.
	 * @return string
	 */
	public static function tracking_id( $slot, $client ) {
		if ( function_exists( 'meeha_ad_tracking_id' ) ) {
			return meeha_ad_tracking_id( $slot, $client );
		}
		return 'MH-' . str_pad( (string) absint( $client ), 4, '0', STR_PAD_LEFT ) . '-' . sanitize_key( $slot );
	}

	/**
	 * Add to a counter row: make sure it exists, then add (portable — MySQL and the SQLite test site).
	 *
	 * @param string $table Table.
	 * @param array  $key   Column => value of the primary key.
	 * @param array  $inc   Column => amount.
	 */
	public static function bump( $table, $key, $inc ) {
		global $wpdb;
		$inc = array_filter( array_map( 'intval', $inc ) );
		if ( ! $inc ) {
			return;
		}
		$cols = array_keys( $key );
		$ph   = array();
		foreach ( $key as $v ) {
			$ph[] = is_int( $v ) ? '%d' : '%s';
		}
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (" . implode( ', ', $cols ) . ') VALUES (' . implode( ', ', $ph ) . ')', array_values( $key ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		$set = array();
		$arg = array();
		foreach ( $inc as $c => $n ) {
			$set[] = "{$c} = {$c} + %d";
			$arg[] = $n;
		}
		$where = array();
		foreach ( $key as $c => $v ) {
			$where[] = $c . ' = ' . ( is_int( $v ) ? '%d' : '%s' );
			$arg[]   = $v;
		}
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET " . implode( ', ', $set ) . ' WHERE ' . implode( ' AND ', $where ), $arg ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Add to today's row for a placement.
	 *
	 * @param string $slot Slot id.
	 * @param int    $imp  Viewable views ("নিশ্চিত দেখা").
	 * @param int    $clk  Clicks.
	 * @param int    $srv  Served (ইমপ্রেশন: the box was on the page, shown).
	 */
	public static function add( $slot, $imp, $clk, $srv = 0 ) {
		self::bump(
			self::table(),
			array(
				'day'    => current_time( 'Y-m-d' ),
				'slot'   => (string) $slot,
				'client' => self::client_of( $slot ),
			),
			array(
				'srv' => $srv,
				'imp' => $imp,
				'clk' => $clk,
			)
		);
	}

	/**
	 * Crawlers and preview bots also run scripts — they never count.
	 *
	 * @return bool
	 */
	public static function is_bot() {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( '' === $ua ) {
			return true;
		}
		return (bool) preg_match( '/bot\b|bot\/|crawl|spider|slurp|headless|lighthouse|pagespeed|preview|facebookexternalhit|whatsapp\/|telegrambot|python-|curl\/|wget|phantom|puppeteer|playwright/i', $ua );
	}

	/**
	 * POST /ads/beacon — always answers 204-like. Body (all optional):
	 *   i: [slot…] served (shown on the page), v: [slot…] viewable (½ on screen for 1 s),
	 *   u: [client…] first view today of that client's ad in this browser (reach),
	 *   c: slot clicked, p: news|quote reader photocard downloaded with the ad strip,
	 *   s: page section, r: where the reader came from.
	 * Logged-in readers count like everyone else (the REST cookie without a nonce is user 0 here).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function beacon( $request ) {
		$none = new WP_REST_Response( null, 204 );
		if ( ! self::enabled() || ! function_exists( 'meeha_ad_slots' ) || ! self::same_site( $request ) || self::is_bot() ) {
			return $none;
		}
		$body = json_decode( (string) $request->get_body(), true );
		if ( ! is_array( $body ) ) {
			return $none;
		}
		if ( ! self::within_rate() ) {
			return new WP_REST_Response( null, 429 );
		}
		$known = self::known_slots();
		$list  = function ( $k ) use ( $body, $known ) {
			$out = array();
			if ( isset( $body[ $k ] ) && is_array( $body[ $k ] ) ) {
				foreach ( array_slice( $body[ $k ], 0, 24 ) as $s ) {
					$s = sanitize_key( (string) $s );
					if ( isset( $known[ $s ] ) && 0 !== strpos( $s, 'card_' ) ) {
						$out[ $s ] = 1;
					}
				}
			}
			return array_keys( $out );
		};
		$served   = $list( 'i' );
		$viewable = $list( 'v' );
		$slots    = array_unique( array_merge( $served, $viewable ) );
		$per      = array(); // client => [imp, clk, srv]
		foreach ( $slots as $s ) {
			$is = in_array( $s, $served, true ) ? 1 : 0;
			$iv = in_array( $s, $viewable, true ) ? 1 : 0;
			self::add( $s, $iv, 0, $is );
			$c = self::client_of( $s );
			if ( ! isset( $per[ $c ] ) ) {
				$per[ $c ] = array( 0, 0, 0 );
			}
			$per[ $c ][0] += $iv;
			$per[ $c ][2] += $is;
		}
		if ( isset( $body['c'] ) ) {
			$s = sanitize_key( (string) $body['c'] );
			if ( isset( $known[ $s ] ) ) {
				self::add( $s, 0, 1 );
				$c = self::client_of( $s );
				if ( ! isset( $per[ $c ] ) ) {
					$per[ $c ] = array( 0, 0, 0 );
				}
				$per[ $c ][1] += 1;
			}
		}
		if ( isset( $body['p'] ) && in_array( $body['p'], array( 'news', 'quote' ), true ) ) {
			$s = 'card_' . $body['p'];
			self::add( $s, 1, 0, 1 ); // a download is both shown and seen — it is the reader's own picture
			$c = self::client_of( $s );
			if ( ! isset( $per[ $c ] ) ) {
				$per[ $c ] = array( 0, 0, 0 );
			}
			$per[ $c ][0] += 1;
			$per[ $c ][2] += 1;
		}
		// Reach: only a client whose ad this very beacon reported as seen.
		$reach = array();
		if ( isset( $body['u'] ) && is_array( $body['u'] ) ) {
			foreach ( array_slice( $body['u'], 0, 12 ) as $c ) {
				$c = absint( $c );
				if ( isset( $per[ $c ] ) && $per[ $c ][0] > 0 ) {
					$reach[ $c ] = 1;
				}
			}
		}
		if ( $per && class_exists( 'NROS_Ad_Insight' ) ) {
			$sec = isset( $body['s'] ) ? sanitize_key( (string) $body['s'] ) : '';
			$src = isset( $body['r'] ) ? sanitize_key( (string) $body['r'] ) : '';
			NROS_Ad_Insight::record( $per, $reach, $sec, $src );
		}
		return $none;
	}

	/**
	 * Totals for the last N days — the Ads Manager report and its overview card.
	 *
	 * @param int $days Days (1–366).
	 * @return array
	 */
	public static function summary( $days ) {
		global $wpdb;
		$t    = self::table();
		$days = max( 1, min( 366, (int) $days ) );
		$from = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -' . ( $days - 1 ) . ' days' ) );
		$empty = array( 'from' => $from, 'days' => $days, 'srv' => 0, 'imp' => 0, 'clk' => 0, 'ctr' => 0, 'view_rate' => 0, 'reach' => 0, 'slots' => array(), 'clients' => array(), 'daily' => array(), 'devices' => array() );
		// The table may not exist yet if the DB upgrade has not run.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! $exists ) {
			return $empty;
		}
		$slots = (array) $wpdb->get_results( $wpdb->prepare( "SELECT slot, client, SUM(srv) AS srv, SUM(imp) AS imp, SUM(clk) AS clk FROM {$t} WHERE day >= %s GROUP BY slot, client ORDER BY imp DESC", $from ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$cl    = (array) $wpdb->get_results( $wpdb->prepare( "SELECT client, SUM(srv) AS srv, SUM(imp) AS imp, SUM(clk) AS clk FROM {$t} WHERE day >= %s AND client > 0 GROUP BY client ORDER BY imp DESC", $from ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$daily = (array) $wpdb->get_results( $wpdb->prepare( "SELECT day, SUM(srv) AS srv, SUM(imp) AS imp, SUM(clk) AS clk FROM {$t} WHERE day >= %s GROUP BY day ORDER BY day ASC", $from ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$labels = self::known_slots();
		$names  = self::client_names( wp_list_pluck( $cl, 'client' ) );
		$ctr    = function ( $imp, $clk ) {
			return $imp > 0 ? round( 100 * $clk / $imp, 2 ) : 0;
		};
		$reach  = class_exists( 'NROS_Ad_Insight' ) ? NROS_Ad_Insight::reach_by_client( $from ) : array();
		$tot    = array( 0, 0, 0 );
		$out_s  = array();
		foreach ( $slots as $r ) {
			$i       = (int) $r['imp'];
			$c       = (int) $r['clk'];
			$v       = (int) $r['srv'];
			$tot[0] += $v;
			$tot[1] += $i;
			$tot[2] += $c;
			$out_s[] = array(
				'slot'   => (string) $r['slot'],
				'client' => (int) $r['client'],
				'tid'    => (int) $r['client'] ? self::tracking_id( (string) $r['slot'], (int) $r['client'] ) : '',
				'label'  => isset( $labels[ $r['slot'] ] ) ? $labels[ $r['slot'] ] : (string) $r['slot'],
				'srv'    => max( $v, $i ),
				'imp'    => $i,
				'clk'    => $c,
				'ctr'    => $ctr( $i, $c ),
			);
		}
		$out_c = array();
		foreach ( $cl as $r ) {
			$id      = (int) $r['client'];
			$out_c[] = array(
				'client' => $id,
				'name'   => isset( $names[ $id ] ) ? $names[ $id ] : '#' . $id,
				'srv'    => max( (int) $r['srv'], (int) $r['imp'] ),
				'imp'    => (int) $r['imp'],
				'clk'    => (int) $r['clk'],
				'ctr'    => $ctr( (int) $r['imp'], (int) $r['clk'] ),
				'reach'  => isset( $reach[ $id ] ) ? (int) $reach[ $id ] : 0,
			);
		}
		$out_d = array();
		foreach ( $daily as $r ) {
			$out_d[] = array( 'day' => (string) $r['day'], 'srv' => max( (int) $r['srv'], (int) $r['imp'] ), 'imp' => (int) $r['imp'], 'clk' => (int) $r['clk'] );
		}
		$srv = max( $tot[0], $tot[1] ); // rows from before r50 have only "imp"
		return array(
			'from'      => $from,
			'days'      => $days,
			'srv'       => $srv,
			'imp'       => $tot[1],
			'clk'       => $tot[2],
			'ctr'       => $ctr( $tot[1], $tot[2] ),
			'view_rate' => $srv > 0 ? round( 100 * $tot[1] / $srv, 1 ) : 0,
			'reach'     => array_sum( $reach ),
			'slots'     => $out_s,
			'clients'   => $out_c,
			'daily'     => $out_d,
			'devices'   => class_exists( 'NROS_Ad_Insight' ) ? NROS_Ad_Insight::dim_totals( 'dev', $from, null ) : array(),
		);
	}

	/**
	 * Business names of ad clients.
	 *
	 * @param int[] $ids Ids.
	 * @return array id => name
	 */
	public static function client_names( $ids ) {
		global $wpdb;
		$ids = array_filter( array_map( 'absint', (array) $ids ) );
		if ( ! $ids ) {
			return array();
		}
		$rows  = (array) $wpdb->get_results( "SELECT id, business_name FROM {$wpdb->prefix}nros_ad_clients WHERE id IN (" . implode( ',', $ids ) . ')', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		$names = array();
		foreach ( $rows as $r ) {
			$names[ (int) $r['id'] ] = (string) $r['business_name'];
		}
		return $names;
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
		global $wpdb;
		$t = self::table();
		if ( $client ) {
			$data['client_rows'] = (array) $wpdb->get_results( $wpdb->prepare( "SELECT day, slot, srv, imp, clk FROM {$t} WHERE day >= %s AND client = %d ORDER BY day ASC, slot ASC", $data['from'], $client ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		if ( 'csv' === $request->get_param( 'format' ) ) {
			$where  = $client ? $wpdb->prepare( ' AND client = %d', $client ) : '';
			$rows   = (array) $wpdb->get_results( $wpdb->prepare( "SELECT day, slot, client, srv, imp, clk FROM {$t} WHERE day >= %s", $data['from'] ) . $where . ' ORDER BY day ASC, slot ASC', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
			$labels = self::known_slots();
			$lines  = array( 'তারিখ,জায়গা,স্লট,ট্র্যাকিং আইডি,ক্লায়েন্ট আইডি,ইমপ্রেশন,নিশ্চিত দেখা,ক্লিক,ক্লিক হার %' );
			foreach ( $rows as $r ) {
				$label   = isset( $labels[ $r['slot'] ] ) ? $labels[ $r['slot'] ] : $r['slot'];
				$i       = (int) $r['imp'];
				$c       = (int) $r['clk'];
				$lines[] = implode(
					',',
					array(
						$r['day'],
						'"' . str_replace( '"', '""', $label ) . '"',
						$r['slot'],
						(int) $r['client'] ? self::tracking_id( $r['slot'], (int) $r['client'] ) : '',
						(int) $r['client'],
						max( (int) $r['srv'], $i ),
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
