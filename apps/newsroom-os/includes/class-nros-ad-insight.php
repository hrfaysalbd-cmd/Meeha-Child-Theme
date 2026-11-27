<?php
/**
 * r50 — বিজ্ঞাপনের ইনসাইট: an automatic, private results page for each ad client.
 *
 * - Every beacon (see NROS_Ad_Stats::beacon) also adds to nros_ad_dims: per day and client, the
 *   device / system / browser (read from the browser's own User-Agent), the hour, the part of the
 *   site and where the reader came from. Still only totals — no reader, address or cookie is kept.
 * - Each client gets a secret link (?c=ID&k=KEY) to one page, [meeha_ad_insight], made once on a
 *   canvas template and kept out of search. The page reads GET /ads/insight and redraws every minute.
 * - Numbers are what was measured. Forecasts are marked "আনুমানিক"; gender and age are never guessed:
 *   the page shows the site's reader profile only if the publisher typed it in from Google Analytics,
 *   and says so.
 * - Off-site promotion (the video corner ad on YouTube/Facebook, a print ad …) is entered by the
 *   publisher and shown apart, as "প্রকাশকের দেওয়া হিসাব".
 *
 * @package NewsroomOS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Client insight page + breakdowns.
 */
class NROS_Ad_Insight {

	const OPT_KEYS     = 'nros_ad_insight_keys';
	const OPT_OFFSITE  = 'nros_ad_offsite';
	const OPT_AUDIENCE = 'nros_ad_audience';
	const SHORTCODE    = 'meeha_ad_insight';

	/** Dimensions kept per day and client. */
	const DIMS = array( 'dev', 'os', 'br', 'hr', 'sec', 'src', 'reach' );

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'shortcode' ) );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ) );
	}

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'nros_ad_dims';
	}

	/**
	 * Schema (DB 20).
	 *
	 * @param string $c Charset/collate.
	 * @return string
	 */
	public static function schema( $c ) {
		$t = self::table();
		return "CREATE TABLE {$t} (
			day DATE NOT NULL,
			client BIGINT UNSIGNED NOT NULL DEFAULT 0,
			dim VARCHAR(8) NOT NULL,
			val VARCHAR(24) NOT NULL,
			imp BIGINT UNSIGNED NOT NULL DEFAULT 0,
			clk BIGINT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (day,client,dim,val),
			KEY client_day (client,day)
		) {$c};";
	}

	/* ------------------------------------------------------------------ counting */

	/**
	 * Device, system and browser from the User-Agent of the beacon request.
	 *
	 * @return array dev, os, br
	 */
	public static function agent() {
		$ua  = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$dev = 'desktop';
		if ( preg_match( '/iPad|Tablet|SM-T\d|Kindle|Silk|PlayBook/i', $ua ) || ( preg_match( '/Android/i', $ua ) && ! preg_match( '/Mobile/i', $ua ) ) ) {
			$dev = 'tablet';
		} elseif ( preg_match( '/Mobi|iPhone|iPod|Android|Opera Mini|IEMobile/i', $ua ) ) {
			$dev = 'mobile';
		}
		$os = 'other';
		foreach ( array( 'android' => '/Android/i', 'ios' => '/iPhone|iPad|iPod/i', 'windows' => '/Windows/i', 'mac' => '/Macintosh|Mac OS X/i', 'linux' => '/Linux|X11|CrOS/i' ) as $k => $re ) {
			if ( preg_match( $re, $ua ) ) {
				$os = $k;
				break;
			}
		}
		$br = 'other';
		foreach ( array( 'facebook' => '/FBAN|FBAV|FB_IAB|Instagram/i', 'edge' => '/Edg\//', 'samsung' => '/SamsungBrowser/i', 'opera' => '/OPR\/|Opera/i', 'uc' => '/UCBrowser/i', 'firefox' => '/Firefox|FxiOS/i', 'chrome' => '/Chrome|CriOS/i', 'safari' => '/Safari/i' ) as $k => $re ) {
			if ( preg_match( $re, $ua ) ) {
				$br = $k;
				break;
			}
		}
		return array(
			'dev' => $dev,
			'os'  => $os,
			'br'  => $br,
		);
	}

	/**
	 * Add one beacon's numbers to the breakdowns.
	 *
	 * @param array  $per   client => [viewable, clicks, served].
	 * @param array  $reach client => 1 (first view of the day in that browser).
	 * @param string $sec   Page section (home, article …).
	 * @param string $src   Where the reader came from.
	 */
	public static function record( $per, $reach, $sec, $src ) {
		$t    = self::table();
		$day  = current_time( 'Y-m-d' );
		$a    = self::agent();
		$sec  = in_array( $sec, array( 'home', 'article', 'opinion', 'literature', 'category', 'video', 'quotes', 'page', 'search', 'live' ), true ) ? $sec : 'other';
		$src  = in_array( $src, array( 'direct', 'internal', 'google', 'facebook', 'youtube', 'social', 'other' ), true ) ? $src : 'other';
		$vals = array(
			'dev' => $a['dev'],
			'os'  => $a['os'],
			'br'  => $a['br'],
			'hr'  => current_time( 'H' ),
			'sec' => $sec,
			'src' => $src,
		);
		foreach ( $per as $client => $n ) {
			if ( ! $n[0] && ! $n[1] ) {
				continue;
			}
			foreach ( $vals as $dim => $val ) {
				NROS_Ad_Stats::bump(
					$t,
					array(
						'day'    => $day,
						'client' => (int) $client,
						'dim'    => $dim,
						'val'    => (string) $val,
					),
					array(
						'imp' => (int) $n[0],
						'clk' => (int) $n[1],
					)
				);
			}
		}
		foreach ( array_keys( $reach ) as $client ) {
			NROS_Ad_Stats::bump(
				$t,
				array(
					'day'    => $day,
					'client' => (int) $client,
					'dim'    => 'reach',
					'val'    => 'all',
				),
				array( 'imp' => 1 )
			);
		}
	}

	/**
	 * Does the breakdown table exist yet (DB upgrade run)?
	 *
	 * @return bool
	 */
	protected static function ready() {
		global $wpdb;
		static $ok = null;
		if ( null === $ok ) {
			$ok = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', self::table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		return $ok;
	}

	/**
	 * Daily reach summed per client since a day.
	 *
	 * @param string $from Y-m-d.
	 * @return array client => reach
	 */
	public static function reach_by_client( $from ) {
		global $wpdb;
		if ( ! self::ready() ) {
			return array();
		}
		$t    = self::table();
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT client, SUM(imp) AS n FROM {$t} WHERE dim = 'reach' AND day >= %s GROUP BY client", $from ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out  = array();
		foreach ( $rows as $r ) {
			$out[ (int) $r['client'] ] = (int) $r['n'];
		}
		return $out;
	}

	/**
	 * Totals of one breakdown.
	 *
	 * @param string   $dim    Dimension.
	 * @param string   $from   Y-m-d.
	 * @param int|null $client Client (null = every ad).
	 * @param string   $to     Y-m-d (optional).
	 * @return array[] val, imp, clk
	 */
	public static function dim_totals( $dim, $from, $client, $to = '' ) {
		global $wpdb;
		if ( ! self::ready() ) {
			return array();
		}
		$t     = self::table();
		$where = $wpdb->prepare( 'dim = %s AND day >= %s', $dim, $from );
		if ( '' !== $to ) {
			$where .= $wpdb->prepare( ' AND day <= %s', $to );
		}
		if ( null !== $client ) {
			$where .= $wpdb->prepare( ' AND client = %d', $client );
		}
		$rows = (array) $wpdb->get_results( "SELECT val, SUM(imp) AS imp, SUM(clk) AS clk FROM {$t} WHERE {$where} GROUP BY val ORDER BY imp DESC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		$out  = array();
		foreach ( $rows as $r ) {
			$out[] = array(
				'val' => (string) $r['val'],
				'imp' => (int) $r['imp'],
				'clk' => (int) $r['clk'],
			);
		}
		return $out;
	}

	/* ------------------------------------------------------------------ links */

	/**
	 * The secret key of a client's link (made on first use).
	 *
	 * @param int  $client Client.
	 * @param bool $create Make one when missing.
	 * @return string
	 */
	public static function key_for( $client, $create = true ) {
		$client = absint( $client );
		$keys   = (array) get_option( self::OPT_KEYS, array() );
		if ( isset( $keys[ $client ] ) && is_string( $keys[ $client ] ) && '' !== $keys[ $client ] ) {
			return $keys[ $client ];
		}
		if ( ! $create || ! $client ) {
			return '';
		}
		$keys[ $client ] = strtolower( wp_generate_password( 20, false, false ) );
		update_option( self::OPT_KEYS, $keys, false );
		return $keys[ $client ];
	}

	/**
	 * A new key — the old link stops working.
	 *
	 * @param int $client Client.
	 * @return string
	 */
	public static function rotate( $client ) {
		$keys            = (array) get_option( self::OPT_KEYS, array() );
		$keys[ $client ] = strtolower( wp_generate_password( 20, false, false ) );
		update_option( self::OPT_KEYS, $keys, false );
		return $keys[ $client ];
	}

	/**
	 * The page that shows [meeha_ad_insight].
	 *
	 * @return int
	 */
	public static function page_id() {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_content LIKE %s ORDER BY ID ASC LIMIT 1", '%[' . $wpdb->esc_like( self::SHORTCODE ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Make the page once (canvas template, title hidden, kept out of search).
	 *
	 * @return int
	 */
	public static function ensure_page() {
		$id = self::page_id();
		if ( $id ) {
			return $id;
		}
		$id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'বিজ্ঞাপনের ফলাফল',
				'post_name'    => 'ad-insight',
				'post_content' => '[' . self::SHORTCODE . ']',
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return 0;
		}
		if ( '' !== locate_template( 'page-canvas.php' ) ) {
			update_post_meta( $id, '_wp_page_template', 'page-canvas.php' );
		}
		update_post_meta( $id, '_meeha_hide_title', 1 );
		update_post_meta( $id, 'rank_math_robots', array( 'noindex', 'nofollow' ) );
		return (int) $id;
	}

	/**
	 * A client's private link (makes the key and the page when missing).
	 *
	 * @param int $client Client.
	 * @return string
	 */
	public static function link( $client ) {
		$page = self::ensure_page();
		if ( ! $page || ! $client ) {
			return '';
		}
		return add_query_arg(
			array(
				'c' => absint( $client ),
				'k' => self::key_for( $client ),
			),
			get_permalink( $page )
		);
	}

	/**
	 * Keep the insight page out of search engines.
	 *
	 * @param array $robots Directives.
	 * @return array
	 */
	public static function robots( $robots ) {
		if ( is_page() ) {
			$p = get_queried_object();
			if ( $p instanceof WP_Post && has_shortcode( $p->post_content, self::SHORTCODE ) ) {
				$robots['noindex']  = true;
				$robots['nofollow'] = true;
				unset( $robots['index'], $robots['follow'], $robots['max-image-preview'] );
			}
		}
		return $robots;
	}

	/* ------------------------------------------------------------------ routes */

	/**
	 * Routes.
	 */
	public static function register_routes() {
		register_rest_route(
			NROS_API_NS,
			'/ads/insight',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'public_insight' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			NROS_API_NS,
			'/admin/ads-manager/insight',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'admin_links' ),
					'permission_callback' => array( 'NROS_Core', 'require_staff' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'admin_rotate' ),
					'permission_callback' => array( 'NROS_Core', 'require_staff' ),
				),
			)
		);
		register_rest_route(
			NROS_API_NS,
			'/admin/ads-manager/offsite',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'admin_offsite' ),
				'permission_callback' => array( 'NROS_Core', 'require_staff' ),
			)
		);
		register_rest_route(
			NROS_API_NS,
			'/admin/ads-manager/audience',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'admin_audience' ),
				'permission_callback' => array( 'NROS_Core', 'require_staff' ),
			)
		);
	}

	/**
	 * GET /ads/insight?c=ID&k=KEY&days=30 — the client's own numbers (no login; the key is the pass).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function public_insight( $request ) {
		$client = absint( $request->get_param( 'c' ) );
		$key    = (string) $request->get_param( 'k' );
		$have   = $client ? self::key_for( $client, false ) : '';
		if ( '' === $have || '' === $key || ! hash_equals( $have, $key ) ) {
			return new WP_Error( 'nros_insight_key', 'লিংকটি ঠিক নেই বা বদলে গেছে। মিহার বিজ্ঞাপন বিভাগ থেকে নতুন লিংক নিন।', array( 'status' => 403 ) );
		}
		$days = (int) $request->get_param( 'days' );
		$res  = new WP_REST_Response( self::data( $client, in_array( $days, array( 7, 30, 90 ), true ) ? $days : 30 ) );
		$res->header( 'Cache-Control', 'no-store, max-age=0' );
		$res->header( 'X-Robots-Tag', 'noindex' );
		return $res;
	}

	/**
	 * Clients with a placement now or numbers in the last 90 days.
	 *
	 * @return int[]
	 */
	protected static function active_clients() {
		global $wpdb;
		$ids = array();
		$o   = function_exists( 'meeha_opt' ) ? meeha_opt( 'ads' ) : array();
		foreach ( (array) $o as $k => $v ) {
			if ( '_client' === substr( (string) $k, -7 ) && absint( $v ) ) {
				$ids[ absint( $v ) ] = 1;
			}
		}
		foreach ( array( 'news_reader_ad_client', 'quote_reader_ad_client' ) as $k ) {
			$v = function_exists( 'meeha_studio_opt' ) ? absint( meeha_studio_opt( $k, 0 ) ) : 0;
			if ( $v ) {
				$ids[ $v ] = 1;
			}
		}
		$t    = NROS_Ad_Stats::table();
		$from = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -90 days' ) );
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT client FROM {$t} WHERE client > 0 AND day >= %s", $from ) ) as $c ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$ids[ (int) $c ] = 1;
			}
		}
		return array_keys( $ids );
	}

	/**
	 * GET /admin/ads-manager/insight — every client's link, placements, off-site entries; the audience.
	 *
	 * @return array
	 */
	public static function admin_links() {
		global $wpdb;
		$active  = self::active_clients();
		$rows    = (array) $wpdb->get_results( "SELECT id, business_name, phone, whatsapp, status FROM {$wpdb->prefix}nros_ad_clients ORDER BY business_name ASC LIMIT 500", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$offsite = (array) get_option( self::OPT_OFFSITE, array() );
		$out     = array();
		foreach ( $rows as $r ) {
			$id    = (int) $r['id'];
			$on    = in_array( $id, $active, true );
			$out[] = array(
				'id'       => $id,
				'name'     => (string) $r['business_name'],
				'phone'    => (string) $r['phone'],
				'whatsapp' => (string) $r['whatsapp'],
				'status'   => (string) $r['status'],
				'active'   => $on,
				// The link is made automatically once a client has a placement (or numbers).
				'link'     => $on || self::key_for( $id, false ) ? self::link( $id ) : '',
				'slots'    => self::client_slots( $id ),
				'offsite'  => isset( $offsite[ $id ] ) ? array_values( (array) $offsite[ $id ] ) : array(),
			);
		}
		return array(
			'clients'  => $out,
			'audience' => self::audience(),
			'page'     => self::page_id() ? get_permalink( self::page_id() ) : '',
		);
	}

	/**
	 * POST /admin/ads-manager/insight {client, rotate?} — the link (a new one with rotate=1).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function admin_rotate( $request ) {
		$client = absint( $request->get_param( 'client' ) );
		if ( ! $client ) {
			return new WP_Error( 'nros_bad_client', 'ক্লায়েন্ট বেছে নিন।', array( 'status' => 400 ) );
		}
		if ( $request->get_param( 'rotate' ) ) {
			self::rotate( $client );
		}
		return array(
			'ok'   => true,
			'link' => self::link( $client ),
		);
	}

	/**
	 * POST /admin/ads-manager/offsite {client, entries:[{date, kind, title, link, views, clicks, note}]}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function admin_offsite( $request ) {
		$client = absint( $request->get_param( 'client' ) );
		$list   = $request->get_param( 'entries' );
		if ( ! $client || ! is_array( $list ) ) {
			return new WP_Error( 'nros_bad', 'ক্লায়েন্ট ও তালিকা দিন।', array( 'status' => 400 ) );
		}
		$clean = array();
		foreach ( array_slice( $list, 0, 200 ) as $e ) {
			if ( ! is_array( $e ) ) {
				continue;
			}
			$date = isset( $e['date'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $e['date'] ) ? (string) $e['date'] : current_time( 'Y-m-d' );
			$kind = isset( $e['kind'] ) && in_array( $e['kind'], array( 'video', 'youtube', 'facebook', 'photocard', 'print', 'other' ), true ) ? (string) $e['kind'] : 'other';
			$clean[] = array(
				'id'     => isset( $e['id'] ) && '' !== (string) $e['id'] ? sanitize_key( (string) $e['id'] ) : strtolower( wp_generate_password( 8, false, false ) ),
				'date'   => $date,
				'kind'   => $kind,
				'title'  => isset( $e['title'] ) ? mb_substr( sanitize_text_field( (string) $e['title'] ), 0, 140 ) : '',
				'link'   => isset( $e['link'] ) ? esc_url_raw( (string) $e['link'] ) : '',
				'views'  => isset( $e['views'] ) ? max( 0, (int) $e['views'] ) : 0,
				'clicks' => isset( $e['clicks'] ) ? max( 0, (int) $e['clicks'] ) : 0,
				'note'   => isset( $e['note'] ) ? mb_substr( sanitize_text_field( (string) $e['note'] ), 0, 200 ) : '',
			);
		}
		usort(
			$clean,
			function ( $a, $b ) {
				return strcmp( $b['date'], $a['date'] );
			}
		);
		$all            = (array) get_option( self::OPT_OFFSITE, array() );
		$all[ $client ] = $clean;
		update_option( self::OPT_OFFSITE, $all, false );
		return array(
			'ok'      => true,
			'entries' => $clean,
		);
	}

	/**
	 * The site's reader profile, as the publisher entered it (from Google Analytics), or null.
	 *
	 * @return array|null
	 */
	public static function audience() {
		$a = get_option( self::OPT_AUDIENCE, array() );
		return is_array( $a ) && ! empty( $a['on'] ) ? $a : ( is_array( $a ) && $a ? array_merge( $a, array( 'on' => false ) ) : null );
	}

	/**
	 * POST /admin/ads-manager/audience {on, male, female, ages:{…}, source, updated}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	public static function admin_audience( $request ) {
		$pct  = function ( $v ) {
			return max( 0, min( 100, round( (float) $v, 1 ) ) );
		};
		$ages = array();
		$in   = (array) $request->get_param( 'ages' );
		foreach ( array( '13-17', '18-24', '25-34', '35-44', '45-54', '55+' ) as $k ) {
			$ages[ $k ] = isset( $in[ $k ] ) ? $pct( $in[ $k ] ) : 0;
		}
		$a = array(
			'on'      => (bool) $request->get_param( 'on' ),
			'male'    => $pct( $request->get_param( 'male' ) ),
			'female'  => $pct( $request->get_param( 'female' ) ),
			'ages'    => $ages,
			'source'  => mb_substr( sanitize_text_field( (string) $request->get_param( 'source' ) ), 0, 120 ),
			'updated' => preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $request->get_param( 'updated' ) ) ? (string) $request->get_param( 'updated' ) : current_time( 'Y-m-d' ),
		);
		update_option( self::OPT_AUDIENCE, $a, false );
		return array(
			'ok'       => true,
			'audience' => $a,
		);
	}

	/* ------------------------------------------------------------------ numbers */

	/**
	 * A client's placements now (from the theme's ad settings).
	 *
	 * @param int $client Client.
	 * @return array[]
	 */
	public static function client_slots( $client ) {
		if ( ! function_exists( 'meeha_ad_slots' ) || ! function_exists( 'meeha_opt' ) ) {
			return array();
		}
		$o     = meeha_opt( 'ads' );
		$today = current_time( 'Y-m-d' );
		$out   = array();
		foreach ( meeha_ad_slots() as $slot => $def ) {
			if ( absint( isset( $o[ $slot . '_client' ] ) ? $o[ $slot . '_client' ] : 0 ) !== (int) $client ) {
				continue;
			}
			$start = isset( $o[ $slot . '_start' ] ) ? (string) $o[ $slot . '_start' ] : '';
			$end   = isset( $o[ $slot . '_end' ] ) ? (string) $o[ $slot . '_end' ] : '';
			$live  = ! empty( $o[ $slot . '_on' ] ) && ( '' === $start || $today >= $start ) && ( '' === $end || $today <= $end );
			$img   = isset( $o[ $slot . '_image' ] ) ? (int) $o[ $slot . '_image' ] : 0;
			$thumb = $img ? wp_get_attachment_image_url( $img, 'medium' ) : '';
			$out[] = array(
				'slot'      => $slot,
				'label'     => (string) $def[0],
				'tid'       => NROS_Ad_Stats::tracking_id( $slot, $client ),
				'live'      => $live,
				'start'     => $start,
				'end'       => $end,
				'days_left' => '' !== $end ? (int) floor( ( strtotime( $end ) - strtotime( $today ) ) / DAY_IN_SECONDS ) : null,
				'device'    => isset( $o[ $slot . '_device' ] ) ? (string) $o[ $slot . '_device' ] : 'all',
				'thumb'     => $thumb ? (string) $thumb : '',
				'has_m'     => ! empty( $o[ $slot . '_image_m' ] ),
				'link'      => isset( $o[ $slot . '_link' ] ) ? (string) $o[ $slot . '_link' ] : '',
			);
		}
		foreach ( array( 'card_news' => 'news_reader_ad', 'card_quote' => 'quote_reader_ad' ) as $slot => $k ) {
			if ( function_exists( 'meeha_studio_opt' ) && absint( meeha_studio_opt( $k . '_client', 0 ) ) === (int) $client ) {
				$labels = NROS_Ad_Stats::known_slots();
				$out[]  = array(
					'slot'      => $slot,
					'label'     => $labels[ $slot ],
					'tid'       => NROS_Ad_Stats::tracking_id( $slot, $client ),
					'live'      => (bool) meeha_studio_opt( $k, 0 ),
					'start'     => '',
					'end'       => '',
					'days_left' => null,
					'device'    => 'all',
					'thumb'     => '',
					'has_m'     => true,
					'link'      => '',
				);
			}
		}
		return $out;
	}

	/**
	 * Least-squares line through daily numbers → the next 7 days (sum, low, high), or null when
	 * there are fewer than 5 days with numbers.
	 *
	 * @param int[] $ys Daily values, oldest first.
	 * @return array|null
	 */
	public static function forecast( $ys ) {
		$ys = array_values( array_slice( $ys, -28 ) );
		// Leading empty days (before the campaign) do not count.
		while ( $ys && 0 === (int) $ys[0] ) {
			array_shift( $ys );
		}
		$n = count( $ys );
		if ( $n < 5 ) {
			return null;
		}
		$mx = ( $n - 1 ) / 2;
		$my = array_sum( $ys ) / $n;
		$sxy = 0;
		$sxx = 0;
		foreach ( $ys as $x => $y ) {
			$sxy += ( $x - $mx ) * ( $y - $my );
			$sxx += ( $x - $mx ) * ( $x - $mx );
		}
		$b   = $sxx > 0 ? $sxy / $sxx : 0;
		$a   = $my - $b * $mx;
		$res = 0;
		foreach ( $ys as $x => $y ) {
			$res += pow( $y - ( $a + $b * $x ), 2 );
		}
		$sd    = $n > 2 ? sqrt( $res / ( $n - 2 ) ) : 0;
		$days  = array();
		$total = 0;
		for ( $k = 0; $k < 7; $k++ ) {
			$v      = max( 0, $a + $b * ( $n + 1 + $k ) ); // the basis ends yesterday; day n is today
			$days[] = (int) round( $v );
			$total += $v;
		}
		$spread = $sd * sqrt( 7 );
		return array(
			'days'  => $days,
			'total' => (int) round( $total ),
			'low'   => (int) max( 0, round( $total - 1.3 * $spread ) ),
			'high'  => (int) round( $total + 1.3 * $spread ),
			'trend' => $my > 0 ? round( 100 * $b * 7 / max( 1, $my * 7 ), 1 ) : 0, // weekly change, % of an average week
			'basis' => $n,
		);
	}

	/**
	 * Everything the insight page shows.
	 *
	 * @param int $client Client.
	 * @param int $days   7|30|90.
	 * @return array
	 */
	public static function data( $client, $days ) {
		global $wpdb;
		$t     = NROS_Ad_Stats::table();
		$today = current_time( 'Y-m-d' );
		$from  = gmdate( 'Y-m-d', strtotime( $today . ' -' . ( $days - 1 ) . ' days' ) );
		$pto   = gmdate( 'Y-m-d', strtotime( $from . ' -1 day' ) );
		$pfrom = gmdate( 'Y-m-d', strtotime( $from . ' -' . $days . ' days' ) );
		$names = NROS_Ad_Stats::client_names( array( $client ) );
		$ok    = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows  = $ok ? (array) $wpdb->get_results( $wpdb->prepare( "SELECT day, slot, srv, imp, clk FROM {$t} WHERE client = %d AND day >= %s ORDER BY day ASC", $client, $from ), ARRAY_A ) : array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$prev  = $ok ? $wpdb->get_row( $wpdb->prepare( "SELECT SUM(srv) AS srv, SUM(imp) AS imp, SUM(clk) AS clk FROM {$t} WHERE client = %d AND day BETWEEN %s AND %s", $client, $pfrom, $pto ), ARRAY_A ) : null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$site  = $ok ? $wpdb->get_row( $wpdb->prepare( "SELECT SUM(srv) AS srv, SUM(imp) AS imp, SUM(clk) AS clk FROM {$t} WHERE day >= %s AND slot NOT LIKE %s", $from, 'card\_%' ), ARRAY_A ) : null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$labels = NROS_Ad_Stats::known_slots();
		$daily  = array();
		for ( $d = strtotime( $from ); $d <= strtotime( $today ); $d += DAY_IN_SECONDS ) {
			$daily[ gmdate( 'Y-m-d', $d ) ] = array( 'srv' => 0, 'imp' => 0, 'clk' => 0 );
		}
		$slots = array();
		$tot   = array( 'srv' => 0, 'imp' => 0, 'clk' => 0 );
		foreach ( $rows as $r ) {
			$srv = max( (int) $r['srv'], (int) $r['imp'] );
			$day = (string) $r['day'];
			if ( isset( $daily[ $day ] ) ) {
				$daily[ $day ]['srv'] += $srv;
				$daily[ $day ]['imp'] += (int) $r['imp'];
				$daily[ $day ]['clk'] += (int) $r['clk'];
			}
			$s = (string) $r['slot'];
			if ( ! isset( $slots[ $s ] ) ) {
				$slots[ $s ] = array( 'slot' => $s, 'label' => isset( $labels[ $s ] ) ? $labels[ $s ] : $s, 'tid' => NROS_Ad_Stats::tracking_id( $s, $client ), 'srv' => 0, 'imp' => 0, 'clk' => 0 );
			}
			$slots[ $s ]['srv'] += $srv;
			$slots[ $s ]['imp'] += (int) $r['imp'];
			$slots[ $s ]['clk'] += (int) $r['clk'];
			$tot['srv']         += $srv;
			$tot['imp']         += (int) $r['imp'];
			$tot['clk']         += (int) $r['clk'];
		}
		$ctr = function ( $imp, $clk ) {
			return $imp > 0 ? round( 100 * $clk / $imp, 2 ) : 0;
		};
		foreach ( $slots as &$s ) {
			$s['ctr']  = $ctr( $s['imp'], $s['clk'] );
			$s['view'] = $s['srv'] > 0 ? round( 100 * $s['imp'] / $s['srv'], 1 ) : 0;
		}
		unset( $s );
		usort(
			$slots,
			function ( $a, $b ) {
				return $b['imp'] - $a['imp'];
			}
		);
		$series = array();
		foreach ( $daily as $day => $v ) {
			$series[] = array_merge( array( 'day' => $day ), $v );
		}
		$dims = array();
		foreach ( array( 'dev', 'os', 'br', 'hr', 'sec', 'src' ) as $dim ) {
			$dims[ $dim ] = self::dim_totals( $dim, $from, $client );
		}
		$reach_rows = self::dim_totals( 'reach', $from, $client );
		$reach      = $reach_rows ? (int) $reach_rows[0]['imp'] : 0;
		$prev_reach = self::dim_totals( 'reach', $pfrom, $client, $pto );

		$view_rate = $tot['srv'] > 0 ? round( 100 * $tot['imp'] / $tot['srv'], 1 ) : 0;
		$site_srv  = $site ? max( (int) $site['srv'], (int) $site['imp'] ) : 0;
		$site_imp  = $site ? (int) $site['imp'] : 0;
		$site_clk  = $site ? (int) $site['clk'] : 0;
		$bench     = array(
			'ctr'       => $ctr( $site_imp, $site_clk ),
			'view_rate' => $site_srv > 0 ? round( 100 * $site_imp / $site_srv, 1 ) : 0,
		);

		// Performance score (Meeha's own yardstick, explained on the page).
		$active_days = 0;
		$first       = null;
		foreach ( $series as $i => $d ) {
			if ( $d['imp'] > 0 ) {
				$active_days++;
				if ( null === $first ) {
					$first = $i;
				}
			}
		}
		$span    = null === $first ? 0 : count( $series ) - $first;
		$p_view  = min( 1, $view_rate / 70 );
		$p_ctr   = $bench['ctr'] > 0 ? min( 1, $ctr( $tot['imp'], $tot['clk'] ) / $bench['ctr'] ) : ( $tot['clk'] > 0 ? 1 : 0 );
		$p_steady = $span > 0 ? $active_days / $span : 0;
		$score   = $tot['imp'] > 0 ? (int) round( 40 * $p_view + 40 * $p_ctr + 20 * $p_steady ) : null;

		$offsite = (array) get_option( self::OPT_OFFSITE, array() );
		$offsite = isset( $offsite[ $client ] ) ? array_values( (array) $offsite[ $client ] ) : array();
		$off_in  = array_values(
			array_filter(
				$offsite,
				function ( $e ) use ( $from ) {
					return (string) $e['date'] >= $from;
				}
			)
		);
		$campaign = self::client_slots( $client );
		$data     = array(
			'client'    => array(
				'id'   => (int) $client,
				'name' => isset( $names[ $client ] ) ? $names[ $client ] : 'বিজ্ঞাপনদাতা',
			),
			'site'      => array(
				'name' => get_bloginfo( 'name' ),
				'url'  => home_url( '/' ),
				'host' => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
			),
			'days'      => $days,
			'from'      => $from,
			'to'        => $today,
			'generated' => current_time( 'mysql' ),
			'totals'    => array(
				'srv'       => $tot['srv'],
				'imp'       => $tot['imp'],
				'clk'       => $tot['clk'],
				'ctr'       => $ctr( $tot['imp'], $tot['clk'] ),
				'view_rate' => $view_rate,
				'reach'     => $reach,
				'freq'      => $reach > 0 ? round( $tot['imp'] / $reach, 2 ) : 0,
			),
			'prev'      => array(
				'srv'   => $prev ? max( (int) $prev['srv'], (int) $prev['imp'] ) : 0,
				'imp'   => $prev ? (int) $prev['imp'] : 0,
				'clk'   => $prev ? (int) $prev['clk'] : 0,
				'reach' => $prev_reach ? (int) $prev_reach[0]['imp'] : 0,
			),
			'bench'     => $bench,
			'score'     => null === $score ? null : array(
				'value' => $score,
				'view'  => (int) round( 40 * $p_view ),
				'ctr'   => (int) round( 40 * $p_ctr ),
				'steady' => (int) round( 20 * $p_steady ),
			),
			'daily'     => $series,
			// Today is still running, so the trend uses the days up to yesterday.
			'forecast'  => array(
				'imp' => self::forecast( array_slice( wp_list_pluck( $series, 'imp' ), 0, -1 ) ),
				'clk' => self::forecast( array_slice( wp_list_pluck( $series, 'clk' ), 0, -1 ) ),
			),
			'slots'     => $slots,
			'dims'      => $dims,
			'campaign'  => $campaign,
			'offsite'   => array(
				'entries' => $off_in,
				'views'   => array_sum( wp_list_pluck( $off_in, 'views' ) ),
				'clicks'  => array_sum( wp_list_pluck( $off_in, 'clicks' ) ),
			),
			'audience'  => self::audience(),
		);
		$data['tips'] = self::tips( $data );
		return $data;
	}

	/**
	 * Plain advice from the numbers (each tip says why).
	 *
	 * @param array $d data().
	 * @return array[] tone (good|info|warn), text
	 */
	public static function tips( $d ) {
		$t   = array();
		$tot = $d['totals'];
		if ( $tot['srv'] < 1 ) {
			$t[] = array( 'tone' => 'info', 'text' => 'এই সময়ে এখনো কোনো হিসাব আসেনি। বিজ্ঞাপন চালু হওয়ার পর থেকে প্রতি মিনিটে এই পাতা নিজে থেকে হালনাগাদ হয়।' );
			return $t;
		}
		if ( $d['bench']['ctr'] > 0 && $tot['imp'] >= 300 ) {
			$r = $tot['ctr'] / $d['bench']['ctr'];
			if ( $r >= 1.2 ) {
				$t[] = array( 'tone' => 'good', 'text' => sprintf( 'আপনার বিজ্ঞাপনে ক্লিকের হার মিহার সব বিজ্ঞাপনের গড়ের চেয়ে %s গুণ — ছবি ও বার্তা ভালো কাজ করছে।', self::bn( round( $r, 1 ) ) ) );
			} elseif ( $r < 0.7 ) {
				$t[] = array( 'tone' => 'warn', 'text' => 'ক্লিকের হার গড়ের চেয়ে কম। ছবিতে বড় করে একটি সুবিধা (ছাড়, দাম, নতুন কিছু) আর "এখনই দেখুন" ধরনের বোতাম দিলে সাধারণত ক্লিক বাড়ে।' );
			}
		}
		if ( $tot['view_rate'] > 0 && $tot['view_rate'] < 50 && $tot['srv'] >= 200 ) {
			$t[] = array( 'tone' => 'warn', 'text' => sprintf( 'পাতায় দেখানো বিজ্ঞাপনের মাত্র %s%% পাঠকের চোখের সামনে এক সেকেন্ড ছিল। পাতার ওপরের দিকের জায়গা (হেডার, খবরের ভেতরে ৩য় অনুচ্ছেদ) বা ভাসমান জায়গা নিলে দেখা বাড়বে।', self::bn( $tot['view_rate'] ) ) );
		} elseif ( $tot['view_rate'] >= 70 ) {
			$t[] = array( 'tone' => 'good', 'text' => sprintf( 'দেখানো বিজ্ঞাপনের %s%% সত্যিই পর্দায় দেখা গেছে — এটা খুব ভালো দৃশ্যমানতা।', self::bn( $tot['view_rate'] ) ) );
		}
		$slots = array_values(
			array_filter(
				$d['slots'],
				function ( $s ) {
					return $s['imp'] >= 100;
				}
			)
		);
		if ( count( $slots ) > 1 ) {
			usort(
				$slots,
				function ( $a, $b ) {
					return $b['ctr'] <=> $a['ctr'];
				}
			);
			$t[] = array( 'tone' => 'info', 'text' => sprintf( 'সবচেয়ে বেশি ক্লিক হার "%s" জায়গায় (%s%%)। বাজেট বাড়ালে এই জায়গাটি আগে নিন।', $slots[0]['label'], self::bn( $slots[0]['ctr'] ) ) );
		}
		$dev = $d['dims']['dev'];
		$all = array_sum( wp_list_pluck( $dev, 'imp' ) );
		foreach ( $dev as $row ) {
			if ( 'mobile' === $row['val'] && $all > 0 && $row['imp'] / $all >= 0.6 ) {
				$missing = array_filter(
					$d['campaign'],
					function ( $c ) {
						return ! $c['has_m'] && 0 !== strpos( $c['slot'], 'card_' ) && 'desktop' !== $c['device'];
					}
				);
				$t[] = array( 'tone' => $missing ? 'warn' : 'info', 'text' => sprintf( 'আপনার বিজ্ঞাপন যাঁরা দেখেছেন তাঁদের %s%% মোবাইলে।', self::bn( round( 100 * $row['imp'] / $all ) ) ) . ( $missing ? ' মোবাইলের জন্য আলাদা ছোট ছবি (যেমন ৩২০×১০০) দিলে লেখা আরও পরিষ্কার পড়া যাবে।' : ' মোবাইলের ছবিতে লেখা বড় ও কম রাখুন।' ) );
				break;
			}
		}
		$hr = $d['dims']['hr'];
		if ( $hr && array_sum( wp_list_pluck( $hr, 'imp' ) ) >= 200 ) {
			usort(
				$hr,
				function ( $a, $b ) {
					return $b['imp'] - $a['imp'];
				}
			);
			$h   = (int) $hr[0]['val'];
			$t[] = array( 'tone' => 'info', 'text' => sprintf( 'সবচেয়ে বেশি দেখা হয় %s — অফার বা লাইভ ঘোষণা এই সময়ের আগে দিলে বেশি মানুষ পাবেন।', self::hour_range( $h ) ) );
		}
		foreach ( $d['campaign'] as $c ) {
			if ( null !== $c['days_left'] && $c['days_left'] >= 0 && $c['days_left'] <= 5 && $c['live'] ) {
				$t[] = array( 'tone' => 'warn', 'text' => sprintf( '"%s" জায়গার মেয়াদ আর %s দিন। চালু রাখতে মিহার বিজ্ঞাপন বিভাগে যোগাযোগ করুন।', $c['label'], self::bn( $c['days_left'] ) ) );
			}
		}
		$f = $d['forecast']['imp'];
		if ( $f && $f['trend'] >= 10 ) {
			$t[] = array( 'tone' => 'good', 'text' => 'দেখার সংখ্যা সপ্তাহে সপ্তাহে বাড়ছে।' );
		} elseif ( $f && $f['trend'] <= -15 ) {
			$t[] = array( 'tone' => 'info', 'text' => 'শেষ কয়েক দিনে দেখা একটু কমেছে — নতুন ছবি (ক্রিয়েটিভ) দিলে পাঠকের নজর আবার পড়ে।' );
		}
		if ( $tot['freq'] >= 6 ) {
			$t[] = array( 'tone' => 'info', 'text' => sprintf( 'একজন মানুষ গড়ে %s বার দেখছেন। দু-তিনটি আলাদা ছবি পালা করে দিলে বিজ্ঞাপন একঘেয়ে লাগবে না।', self::bn( $tot['freq'] ) ) );
		}
		return $t;
	}

	/**
	 * Bengali digits.
	 *
	 * @param mixed $n Number.
	 * @return string
	 */
	protected static function bn( $n ) {
		return strtr( (string) $n, array( '0' => '০', '1' => '১', '2' => '২', '3' => '৩', '4' => '৪', '5' => '৫', '6' => '৬', '7' => '৭', '8' => '৮', '9' => '৯' ) );
	}

	/**
	 * "রাত ৯টা–১০টা" style hour range.
	 *
	 * @param int $h Hour 0–23.
	 * @return string
	 */
	protected static function hour_range( $h ) {
		$part = $h < 5 ? 'রাত' : ( $h < 12 ? 'সকাল' : ( $h < 16 ? 'দুপুর' : ( $h < 18 ? 'বিকেল' : ( $h < 20 ? 'সন্ধ্যা' : 'রাত' ) ) ) );
		$a    = 0 === $h % 12 ? 12 : $h % 12;
		$b    = 0 === ( $h + 1 ) % 12 ? 12 : ( $h + 1 ) % 12;
		return $part . ' ' . self::bn( $a ) . 'টা–' . self::bn( $b ) . 'টা';
	}

	/* ------------------------------------------------------------------ page */

	/**
	 * [meeha_ad_insight] — the page shell; the numbers come from /ads/insight in the browser.
	 *
	 * @return string
	 */
	public static function shortcode() {
		$ver = NROS_VERSION . '.' . (int) @filemtime( NROS_DIR . 'assets/ad-insight/ad-insight.js' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		wp_enqueue_style( 'nros-ad-insight', NROS_URL . 'assets/ad-insight/ad-insight.css', array(), $ver );
		wp_enqueue_script( 'nros-ad-insight', NROS_URL . 'assets/ad-insight/ad-insight.js', array(), $ver, true );
		$cfg = array(
			'api'   => rest_url( NROS_API_NS . '/ads/insight' ),
			'site'  => get_bloginfo( 'name' ),
			'home'  => home_url( '/' ),
			'phone' => function_exists( 'meeha_opt' ) ? (string) meeha_opt( 'ads', 'ad_phone', '' ) : '',
			'wa'    => function_exists( 'meeha_opt' ) ? (string) meeha_opt( 'ads', 'ad_whatsapp', '' ) : '',
		);
		$logo = function_exists( 'meeha_logo' ) ? meeha_logo( 'main', array( 'class' => 'nai-logo', 'label' => get_bloginfo( 'name' ) ) ) : esc_html( get_bloginfo( 'name' ) );
		return '<div class="nai" id="nai" data-cfg="' . esc_attr( wp_json_encode( $cfg ) ) . '"><header class="nai-top"><a class="nai-brand" href="' . esc_url( home_url( '/' ) ) . '">' . $logo . '</a><span class="nai-top__tag">বিজ্ঞাপনের ফলাফল</span></header><div class="nai-body"><div class="nai-load" role="status">ফলাফল আসছে…</div></div><noscript>এই পাতা দেখতে ব্রাউজারে JavaScript চালু রাখুন।</noscript></div>';
	}
}
