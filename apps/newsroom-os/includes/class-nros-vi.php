<?php
/**
 * NROS_VI — ভিউ/ইনসাইট (newsroom-os 1.17.0, publisher r34).
 *
 * One place for the website's views and the connected Facebook Pages,
 * YouTube channels and the TikTok account: views, followers, top content,
 * filters (আজ … লাইফটাইম, কাস্টম) and a live "দেখছেন এখন" panel.
 *
 * Storage — every day of every account is kept in our own table, forever:
 *   {prefix}nros_vi_accounts  connected accounts (tokens AES-encrypted)
 *   {prefix}nros_vi_daily     one row per account per day
 *   {prefix}nros_vi_items     recent posts/videos with their views
 * So when Facebook stops giving data older than 2 years, ours stays, and
 * TikTok (which only gives today's totals) builds history from the day it
 * is connected.
 *
 * Routes (nros/v1):
 *   GET    /vi/overview?period=today|7d|30d|6m|this_year|last_year|lifetime|custom&from&to   (viewers)
 *   GET    /vi/live                                                                     (viewers)
 *   GET    /vi/accounts                         accounts + setup status                 (publisher)
 *   POST   /vi/setup                            app keys, editor access                  (publisher)
 *   POST   /vi/connect   {provider, return}     → sign-in URL                           (publisher)
 *   GET    /vi/oauth/{facebook|google|tiktok}   the platforms come back here            (public, state-checked)
 *   GET    /vi/facebook/pending?key             Pages found after Facebook sign-in      (publisher)
 *   POST   /vi/facebook/pending {key, ids[]}    add the chosen Pages                    (publisher)
 *   PATCH  /vi/accounts/{id}  {enabled}         count it / not                          (publisher)
 *   POST   /vi/accounts/{id}/sync               refresh now                             (publisher)
 *   DELETE /vi/accounts/{id}?purge=1            disconnect (keep data) / delete data    (publisher)
 *
 * @package NewsroomOS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NROS_VI {

	const PLATFORMS = array( 'facebook', 'youtube', 'tiktok' );
	const PROVIDERS = array(
		'facebook' => 'facebook',
		'google'   => 'youtube',
		'tiktok'   => 'tiktok',
	);
	const PERIODS   = array( 'today', '7d', '30d', '6m', 'this_year', 'last_year', 'lifetime', 'custom' );
	const LIVE_TTL  = 90;
	const CRON_HOOK = 'nros_vi_sync';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'cron' ) );
		add_action(
			'init',
			function () {
				if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
					wp_schedule_event( time() + 300, 'hourly', self::CRON_HOOK );
				}
			}
		);
	}

	// ---------------------------------------------------------------------
	// Schema
	// ---------------------------------------------------------------------

	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'nros_vi_' . $name;
	}

	public static function schema( $cc ) {
		$a = self::table( 'accounts' );
		$d = self::table( 'daily' );
		$i = self::table( 'items' );
		return "CREATE TABLE {$a} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			platform VARCHAR(20) NOT NULL,
			ext_id VARCHAR(100) NOT NULL,
			name VARCHAR(200) NOT NULL DEFAULT '',
			handle VARCHAR(120) NOT NULL DEFAULT '',
			url VARCHAR(400) NOT NULL DEFAULT '',
			avatar TEXT NULL,
			token_enc TEXT NULL,
			refresh_enc TEXT NULL,
			token_expires DATETIME NULL,
			refresh_expires DATETIME NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'ok',
			last_error VARCHAR(500) NOT NULL DEFAULT '',
			warning VARCHAR(500) NOT NULL DEFAULT '',
			last_sync DATETIME NULL,
			backfill_until DATE NULL,
			backfill_done TINYINT(1) NOT NULL DEFAULT 0,
			followers BIGINT NULL,
			lifetime BIGINT NULL,
			enabled TINYINT(1) NOT NULL DEFAULT 1,
			meta TEXT NULL,
			created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY platform_ext (platform, ext_id)
		) {$cc};
		CREATE TABLE {$d} (
			account_id BIGINT UNSIGNED NOT NULL,
			day DATE NOT NULL,
			views BIGINT NULL,
			total_views BIGINT NULL,
			followers BIGINT NULL,
			net_followers BIGINT NULL,
			engagement BIGINT NULL,
			watch_minutes BIGINT NULL,
			source VARCHAR(12) NOT NULL DEFAULT 'api',
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (account_id, day),
			KEY day_idx (day)
		) {$cc};
		CREATE TABLE {$i} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			account_id BIGINT UNSIGNED NOT NULL,
			ext_id VARCHAR(120) NOT NULL,
			title VARCHAR(400) NOT NULL DEFAULT '',
			url VARCHAR(500) NOT NULL DEFAULT '',
			thumb TEXT NULL,
			published_at DATETIME NULL,
			views BIGINT NULL,
			likes BIGINT NULL,
			comments BIGINT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY acc_ext (account_id, ext_id),
			KEY pub_idx (published_at)
		) {$cc};";
	}

	// ---------------------------------------------------------------------
	// Access
	// ---------------------------------------------------------------------

	public static function settings() {
		$s = get_option( 'nros_vi_settings', array() );
		return array( 'editors' => ! empty( $s['editors'] ) );
	}

	public static function is_boss( $user ) {
		return $user instanceof WP_User && ( user_can( $user, 'nros_publisher' ) || user_can( $user, 'manage_options' ) );
	}

	/** Publisher always; an Editor when the Publisher has switched it on. */
	public static function can_view( $user ) {
		if ( ! ( $user instanceof WP_User ) ) {
			return false;
		}
		return self::is_boss( $user ) || ( user_can( $user, 'edit_others_posts' ) && self::settings()['editors'] );
	}

	public static function perm_view( $request ) {
		$user = NROS_Auth::get_user_from_request( $request );
		if ( ! $user || is_wp_error( $user ) ) {
			return new WP_Error( 'nros_unauthorized', 'লগইন প্রয়োজন।', array( 'status' => 401 ) );
		}
		return self::can_view( $user ) ? true : new WP_Error( 'nros_forbidden', 'ভিউ/ইনসাইট দেখার অনুমতি নেই।', array( 'status' => 403 ) );
	}

	// ---------------------------------------------------------------------
	// Routes
	// ---------------------------------------------------------------------

	public static function register_routes() {
		$ns   = NROS_API_NS;
		$view = array( __CLASS__, 'perm_view' );
		$boss = array( 'NROS_Core', 'require_publisher' );
		register_rest_route( $ns, '/vi/overview', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'overview' ), 'permission_callback' => $view ) );
		register_rest_route( $ns, '/vi/live', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'live' ), 'permission_callback' => $view ) );
		register_rest_route( $ns, '/vi/accounts', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'accounts' ), 'permission_callback' => $boss ) );
		register_rest_route( $ns, '/vi/setup', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'save_setup' ), 'permission_callback' => $boss ) );
		register_rest_route( $ns, '/vi/connect', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'connect' ), 'permission_callback' => $boss ) );
		register_rest_route( $ns, '/vi/oauth/(?P<provider>facebook|google|tiktok)', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'oauth_callback' ), 'permission_callback' => '__return_true' ) );
		register_rest_route(
			$ns,
			'/vi/facebook/pending',
			array(
				array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'fb_pending' ), 'permission_callback' => $boss ),
				array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'fb_pending_add' ), 'permission_callback' => $boss ),
			)
		);
		register_rest_route(
			$ns,
			'/vi/accounts/(?P<id>\d+)',
			array(
				array( 'methods' => 'PATCH', 'callback' => array( __CLASS__, 'patch_account' ), 'permission_callback' => $boss ),
				array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'delete_account' ), 'permission_callback' => $boss ),
			)
		);
		register_rest_route( $ns, '/vi/accounts/(?P<id>\d+)/sync', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'sync_now' ), 'permission_callback' => $boss ) );
	}

	protected static function no_store( $data, $status = 200 ) {
		$r = new WP_REST_Response( $data, $status );
		$r->header( 'Cache-Control', 'no-store' );
		return $r;
	}

	// ---------------------------------------------------------------------
	// Account storage
	// ---------------------------------------------------------------------

	public static function get_account( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'accounts' ) . ' WHERE id = %d', (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	public static function all_accounts( $only_enabled = false ) {
		global $wpdb;
		$w = $only_enabled ? ' WHERE enabled = 1' : '';
		return (array) $wpdb->get_results( 'SELECT * FROM ' . self::table( 'accounts' ) . $w . " ORDER BY FIELD(platform,'facebook','youtube','tiktok'), id" ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	public static function meta( $acc ) {
		$m = $acc && $acc->meta ? json_decode( (string) $acc->meta, true ) : array();
		return is_array( $m ) ? $m : array();
	}

	/**
	 * Update some columns; null values are skipped (= "not known, keep").
	 */
	public static function update_account( $id, $fields ) {
		global $wpdb;
		$allowed = array( 'name', 'handle', 'url', 'avatar', 'token_enc', 'refresh_enc', 'token_expires', 'refresh_expires', 'status', 'last_error', 'warning', 'last_sync', 'backfill_until', 'backfill_done', 'followers', 'lifetime', 'enabled', 'meta' );
		$set     = array();
		foreach ( $fields as $k => $v ) {
			if ( in_array( $k, $allowed, true ) && null !== $v ) {
				$set[ $k ] = is_string( $v ) && in_array( $k, array( 'last_error', 'warning' ), true ) ? mb_substr( $v, 0, 480 ) : $v;
			}
		}
		if ( ! $set ) {
			return;
		}
		$set['updated_at'] = current_time( 'mysql', true );
		$wpdb->update( self::table( 'accounts' ), $set, array( 'id' => (int) $id ) );
	}

	/**
	 * Insert or refresh an account after sign-in (same platform + id = same row, new tokens).
	 *
	 * @return int id
	 */
	public static function upsert_account( $a, $user_id ) {
		global $wpdb;
		$t   = self::table( 'accounts' );
		$now = current_time( 'mysql', true );
		$row = array(
			'name'            => isset( $a['name'] ) ? mb_substr( (string) $a['name'], 0, 190 ) : '',
			'handle'          => isset( $a['handle'] ) ? mb_substr( (string) $a['handle'], 0, 110 ) : '',
			'url'             => isset( $a['url'] ) ? esc_url_raw( (string) $a['url'] ) : '',
			'avatar'          => isset( $a['avatar'] ) ? esc_url_raw( (string) $a['avatar'] ) : '',
			'token_enc'       => NROS_Core::encrypt( (string) $a['token'] ),
			'refresh_enc'     => ! empty( $a['refresh'] ) ? NROS_Core::encrypt( (string) $a['refresh'] ) : null,
			'token_expires'   => ! empty( $a['token_expires'] ) ? gmdate( 'Y-m-d H:i:s', (int) $a['token_expires'] ) : null,
			'refresh_expires' => ! empty( $a['refresh_expires'] ) ? gmdate( 'Y-m-d H:i:s', (int) $a['refresh_expires'] ) : null,
			'status'          => 'ok',
			'last_error'      => '',
			'followers'       => isset( $a['followers'] ) ? $a['followers'] : null,
			'lifetime'        => isset( $a['lifetime'] ) ? $a['lifetime'] : null,
			'meta'            => isset( $a['meta'] ) ? wp_json_encode( $a['meta'] ) : null,
			'updated_at'      => $now,
		);
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE platform = %s AND ext_id = %s", $a['platform'], $a['ext_id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( $id ) {
			// A Google refresh token only comes on the first consent — keep the old one if none came.
			if ( null === $row['refresh_enc'] ) {
				unset( $row['refresh_enc'] );
			}
			$wpdb->update( $t, array_filter( $row, function ( $v ) { return null !== $v; } ), array( 'id' => $id ) );
			return $id;
		}
		$row['platform']   = $a['platform'];
		$row['ext_id']     = (string) $a['ext_id'];
		$row['enabled']    = 1;
		$row['created_by'] = (int) $user_id;
		$row['created_at'] = $now;
		$wpdb->insert( $t, $row );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Merge numbers into one account-day.
	 *
	 * @param int    $id     Account.
	 * @param string $day    Y-m-d.
	 * @param array  $fields views|total_views|followers|net_followers|engagement|watch_minutes.
	 * @param string $source api|snapshot.
	 */
	public static function put_day( $id, $day, $fields, $source = 'api' ) {
		global $wpdb;
		$cols = array_intersect_key( $fields, array_flip( array( 'views', 'total_views', 'followers', 'net_followers', 'engagement', 'watch_minutes' ) ) );
		if ( ! $cols || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) ) {
			return;
		}
		$names  = array_keys( $cols );
		$ph     = implode( ',', array_fill( 0, count( $cols ), '%d' ) );
		$update = implode( ',', array_map( function ( $c ) { return "`{$c}` = VALUES(`{$c}`)"; }, $names ) );
		$sql    = 'INSERT INTO ' . self::table( 'daily' ) . ' (account_id, `day`, `' . implode( '`,`', $names ) . "`, source, updated_at) VALUES (%d, %s, {$ph}, %s, %s) ON DUPLICATE KEY UPDATE {$update}, source = IF(VALUES(source) = 'api', 'api', source), updated_at = VALUES(updated_at)";
		$args   = array_merge( array( (int) $id, $day ), array_map( 'intval', array_values( $cols ) ), array( $source, current_time( 'mysql', true ) ) );
		$wpdb->query( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * TikTok-style day: save today's running total and turn it into the day's
	 * views (today's total minus the newest earlier total).
	 */
	public static function put_snapshot_total( $id, $day, $total, $followers, $likes ) {
		global $wpdb;
		$t    = self::table( 'daily' );
		$prev = $wpdb->get_var( $wpdb->prepare( "SELECT total_views FROM {$t} WHERE account_id = %d AND `day` < %s AND total_views IS NOT NULL ORDER BY `day` DESC LIMIT 1", (int) $id, $day ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$f    = array( 'total_views' => (int) $total, 'engagement' => (int) $likes );
		if ( null !== $followers ) {
			$f['followers'] = (int) $followers;
		}
		if ( null !== $prev ) {
			$f['views'] = max( 0, (int) $total - (int) $prev );
		}
		self::put_day( $id, $day, $f, 'snapshot' );
	}

	/**
	 * YouTube: Analytics gives subscribers gained/lost per day; the channel
	 * gives today's count. Walk back from today to fill each day's count.
	 */
	public static function derive_followers( $id, $current ) {
		global $wpdb;
		if ( null === $current ) {
			return;
		}
		$t       = self::table( 'daily' );
		$today   = wp_date( 'Y-m-d' );
		$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT `day`, net_followers, followers FROM {$t} WHERE account_id = %d AND `day` <= %s ORDER BY `day` DESC", (int) $id, $today ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$running = (int) $current;
		$recent  = wp_date( 'Y-m-d', time() - 15 * DAY_IN_SECONDS );
		foreach ( (array) $rows as $r ) {
			if ( $r->day !== $today && ( null === $r->followers || $r->day >= $recent ) && (int) $r->followers !== $running ) {
				$wpdb->update( $t, array( 'followers' => max( 0, $running ) ), array( 'account_id' => (int) $id, 'day' => $r->day ) );
			}
			$running -= (int) $r->net_followers;
		}
	}

	public static function put_item( $id, $it ) {
		global $wpdb;
		if ( empty( $it['ext_id'] ) ) {
			return;
		}
		$t   = self::table( 'items' );
		$row = array(
			'title'        => mb_substr( wp_strip_all_tags( (string) $it['title'] ), 0, 390 ),
			'url'          => esc_url_raw( (string) $it['url'] ),
			'thumb'        => esc_url_raw( (string) $it['thumb'] ),
			'published_at' => $it['published_at'],
			'views'        => isset( $it['views'] ) ? $it['views'] : null,
			'likes'        => isset( $it['likes'] ) ? $it['likes'] : null,
			'comments'     => isset( $it['comments'] ) ? $it['comments'] : null,
			'updated_at'   => current_time( 'mysql', true ),
		);
		$ex = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE account_id = %d AND ext_id = %s", (int) $id, (string) $it['ext_id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( $ex ) {
			$wpdb->update( $t, array_filter( $row, function ( $v ) { return null !== $v; } ), array( 'id' => $ex ) );
		} else {
			$wpdb->insert( $t, array_merge( $row, array( 'account_id' => (int) $id, 'ext_id' => (string) $it['ext_id'] ) ) );
		}
	}

	/** First day with views for an account. */
	protected static function data_since( $id ) {
		global $wpdb;
		$d = $wpdb->get_var( $wpdb->prepare( 'SELECT MIN(`day`) FROM ' . self::table( 'daily' ) . ' WHERE account_id = %d AND views IS NOT NULL', (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		return $d ? (string) $d : null;
	}

	/** Account as the app sees it (never tokens). */
	public static function public_account( $a ) {
		return array(
			'id'              => (int) $a->id,
			'platform'        => (string) $a->platform,
			'ext_id'          => (string) $a->ext_id,
			'name'            => (string) $a->name,
			'handle'          => (string) $a->handle,
			'url'             => (string) $a->url,
			'avatar'          => (string) $a->avatar,
			'status'          => (string) $a->status,
			'last_error'      => (string) $a->last_error,
			'warning'         => (string) $a->warning,
			'last_sync'       => $a->last_sync ? mysql2date( 'c', get_date_from_gmt( $a->last_sync ) ) : null,
			'backfill_done'   => (bool) $a->backfill_done,
			'backfill_until'  => $a->backfill_until,
			'data_since'      => self::data_since( (int) $a->id ),
			'followers'       => null === $a->followers ? null : (int) $a->followers,
			'lifetime'        => null === $a->lifetime ? null : (int) $a->lifetime,
			'enabled'         => (bool) $a->enabled,
			'connected'       => '' !== (string) $a->token_enc && 'disconnected' !== $a->status,
			'refresh_expires' => $a->refresh_expires ? mysql2date( 'c', get_date_from_gmt( $a->refresh_expires ) ) : null,
		);
	}

	// ---------------------------------------------------------------------
	// Sync
	// ---------------------------------------------------------------------

	/**
	 * Sync one account and record the outcome on its row.
	 *
	 * @return true|WP_Error
	 */
	public static function run_sync( $acc, $chunks = 3 ) {
		if ( ! $acc || ! $acc->token_enc || 'disconnected' === $acc->status ) {
			return new WP_Error( 'nros_vi_off', 'অ্যাকাউন্টটি সংযুক্ত নেই।' );
		}
		$r = NROS_VI_Providers::sync( $acc, $chunks );
		if ( is_wp_error( $r ) ) {
			$d = $r->get_error_data();
			self::update_account(
				(int) $acc->id,
				array(
					'status'     => ! empty( $d['auth'] ) ? 'expired' : 'error',
					'last_error' => $r->get_error_message(),
					'last_sync'  => current_time( 'mysql', true ),
				)
			);
			return $r;
		}
		self::update_account( (int) $acc->id, array( 'status' => 'ok', 'last_error' => '', 'last_sync' => current_time( 'mysql', true ) ) );
		return true;
	}

	/** Hourly: refresh every connected account; keep filling history. */
	public static function cron() {
		if ( get_transient( 'nros_vi_cron_lock' ) ) {
			return;
		}
		set_transient( 'nros_vi_cron_lock', 1, 10 * MINUTE_IN_SECONDS );
		$began = time();
		foreach ( self::all_accounts() as $acc ) {
			if ( time() - $began > 90 ) {
				break;
			}
			if ( ! $acc->token_enc || 'disconnected' === $acc->status ) {
				continue;
			}
			$fresh = $acc->last_sync && strtotime( $acc->last_sync . ' UTC' ) > time() - 50 * MINUTE_IN_SECONDS;
			if ( $fresh && $acc->backfill_done ) {
				continue;
			}
			self::run_sync( $acc, 3 );
		}
		update_option( 'nros_vi_last_cron', time(), false );
		delete_transient( 'nros_vi_cron_lock' );
	}

	// ---------------------------------------------------------------------
	// Setup / connect
	// ---------------------------------------------------------------------

	public static function accounts( $request ) {
		$apps = array();
		foreach ( array( 'facebook', 'google', 'tiktok' ) as $p ) {
			$a          = NROS_VI_Providers::app( $p );
			$apps[ $p ] = array(
				'id'         => $a['id'],
				'has_secret' => '' !== $a['secret'],
				'ready'      => NROS_VI_Providers::app_ready( $p ),
				'redirect'   => NROS_VI_Providers::redirect_uri( $p ),
			);
			if ( 'facebook' === $p ) {
				$apps[ $p ]['version'] = $a['version'];
			}
		}
		$next = wp_next_scheduled( self::CRON_HOOK );
		return self::no_store(
			array(
				'accounts' => array_map( array( __CLASS__, 'public_account' ), self::all_accounts() ),
				'apps'     => $apps,
				'settings' => self::settings(),
				'cron'     => array(
					'next' => $next ? gmdate( 'c', $next ) : null,
					'last' => get_option( 'nros_vi_last_cron' ) ? gmdate( 'c', (int) get_option( 'nros_vi_last_cron' ) ) : null,
				),
				'site'     => home_url( '/' ),
				'privacy'  => get_privacy_policy_url(),
			)
		);
	}

	public static function save_setup( $request ) {
		$p    = (array) $request->get_json_params();
		$apps = get_option( 'nros_vi_apps', array() );
		$apps = is_array( $apps ) ? $apps : array();
		foreach ( array( 'facebook', 'google', 'tiktok' ) as $k ) {
			if ( ! isset( $p[ $k ] ) || ! is_array( $p[ $k ] ) ) {
				continue;
			}
			$cur = isset( $apps[ $k ] ) && is_array( $apps[ $k ] ) ? $apps[ $k ] : array();
			if ( isset( $p[ $k ]['id'] ) ) {
				$cur['id'] = trim( sanitize_text_field( (string) $p[ $k ]['id'] ) );
			}
			if ( ! empty( $p[ $k ]['secret'] ) ) {
				$cur['secret'] = NROS_Core::encrypt( trim( (string) $p[ $k ]['secret'] ) );
			}
			if ( ! empty( $p[ $k ]['clear_secret'] ) ) {
				$cur['secret'] = '';
			}
			if ( 'facebook' === $k && isset( $p[ $k ]['version'] ) && preg_match( '/^v\d{1,3}\.\d$/', (string) $p[ $k ]['version'] ) ) {
				$cur['version'] = (string) $p[ $k ]['version'];
			}
			$apps[ $k ] = $cur;
		}
		update_option( 'nros_vi_apps', $apps, false );
		if ( isset( $p['editors'] ) ) {
			update_option( 'nros_vi_settings', array( 'editors' => (bool) $p['editors'] ), false );
		}
		return self::accounts( $request );
	}

	/** Only send people back to our own apps. */
	protected static function safe_return( $url ) {
		$url  = esc_url_raw( (string) $url );
		$host = wp_parse_url( $url, PHP_URL_HOST );
		$ok   = array_map(
			function ( $o ) {
				return wp_parse_url( $o, PHP_URL_HOST );
			},
			array_merge( NROS_Core::allowed_origins(), array( home_url() ) )
		);
		if ( $url && $host && in_array( $host, $ok, true ) ) {
			return $url;
		}
		return 'https://publisher.meeha.news/app/insights';
	}

	public static function connect( $request ) {
		$p        = (array) $request->get_json_params();
		$provider = isset( $p['provider'] ) ? sanitize_key( (string) $p['provider'] ) : '';
		if ( ! isset( self::PROVIDERS[ $provider ] ) ) {
			return new WP_Error( 'nros_vi_provider', 'অজানা প্ল্যাটফর্ম।', array( 'status' => 400 ) );
		}
		if ( ! NROS_VI_Providers::app_ready( $provider ) ) {
			return new WP_Error( 'nros_vi_setup', 'আগে সেটআপে এই প্ল্যাটফর্মের অ্যাপ আইডি ও সিক্রেট বসান।', array( 'status' => 400 ) );
		}
		$user  = NROS_Auth::get_user_from_request( $request );
		$state = wp_generate_password( 32, false, false );
		set_transient(
			'nros_vi_st_' . $state,
			array(
				'provider' => $provider,
				'user'     => $user instanceof WP_User ? $user->ID : 0,
				'return'   => self::safe_return( isset( $p['return'] ) ? $p['return'] : '' ),
			),
			15 * MINUTE_IN_SECONDS
		);
		return self::no_store( array( 'url' => NROS_VI_Providers::auth_url( $provider, $state ) ) );
	}

	protected static function bounce( $return, $args ) {
		$url = add_query_arg( array_map( 'rawurlencode', $args ), $return );
		nocache_headers();
		wp_redirect( $url, 302 ); // phpcs:ignore WordPress.Security.SafeRedirect -- host checked in safe_return().
		exit;
	}

	public static function oauth_callback( $request ) {
		$provider = (string) $request['provider'];
		$state    = sanitize_text_field( (string) $request->get_param( 'state' ) );
		$st       = $state ? get_transient( 'nros_vi_st_' . $state ) : false;
		if ( ! $st || $st['provider'] !== $provider ) {
			return new WP_Error( 'nros_vi_state', 'সংযোগের লিংকের মেয়াদ শেষ বা ভুল। পাবলিশার অ্যাপ থেকে আবার চেষ্টা করুন।', array( 'status' => 400 ) );
		}
		delete_transient( 'nros_vi_st_' . $state );
		$return = $st['return'];
		$err    = $request->get_param( 'error' );
		if ( $err ) {
			$desc = (string) ( $request->get_param( 'error_description' ) ? $request->get_param( 'error_description' ) : $err );
			self::bounce( $return, array( 'vi_error' => mb_substr( sanitize_text_field( $desc ), 0, 200 ) ) );
		}
		$code = (string) $request->get_param( 'code' );
		if ( 'facebook' === $provider ) {
			$pages = NROS_VI_Providers::facebook_exchange( $code );
			if ( is_wp_error( $pages ) ) {
				self::bounce( $return, array( 'vi_error' => $pages->get_error_message() ) );
			}
			if ( ! $pages ) {
				self::bounce( $return, array( 'vi_error' => 'এই ফেসবুক অ্যাকাউন্টে কোনো পেজ পাওয়া যায়নি (পেজের অ্যাডমিন হতে হবে, সাইন-ইনের সময় পেজগুলো টিক দিন)।' ) );
			}
			foreach ( $pages as &$pg ) {
				$pg['token'] = NROS_Core::encrypt( $pg['token'] );
			}
			unset( $pg );
			$key = wp_generate_password( 24, false, false );
			set_transient( 'nros_vi_fbp_' . $key, array( 'user' => (int) $st['user'], 'pages' => $pages ), 30 * MINUTE_IN_SECONDS );
			self::bounce( $return, array( 'vi_fb' => $key ) );
		}
		$list = 'google' === $provider ? NROS_VI_Providers::google_exchange( $code ) : NROS_VI_Providers::tiktok_exchange( $code );
		if ( is_wp_error( $list ) ) {
			self::bounce( $return, array( 'vi_error' => $list->get_error_message() ) );
		}
		$names = array();
		foreach ( $list as $a ) {
			$id = self::upsert_account( $a, (int) $st['user'] );
			self::run_sync( self::get_account( $id ), 1 );
			$names[] = $a['name'];
		}
		self::bounce( $return, array( 'vi_ok' => self::PROVIDERS[ $provider ], 'vi_names' => implode( ', ', $names ) ) );
	}

	public static function fb_pending( $request ) {
		$key = sanitize_text_field( (string) $request->get_param( 'key' ) );
		$p   = $key ? get_transient( 'nros_vi_fbp_' . $key ) : false;
		if ( ! $p ) {
			return new WP_Error( 'nros_vi_pending', 'তালিকার মেয়াদ শেষ — আবার "ফেসবুক যোগ করুন" চাপুন।', array( 'status' => 404 ) );
		}
		global $wpdb;
		$have = (array) $wpdb->get_col( "SELECT ext_id FROM " . self::table( 'accounts' ) . " WHERE platform = 'facebook' AND status <> 'disconnected'" ); // phpcs:ignore WordPress.DB.PreparedSQL
		$out  = array();
		foreach ( $p['pages'] as $pg ) {
			$out[] = array(
				'id'        => $pg['id'],
				'name'      => $pg['name'],
				'link'      => $pg['link'],
				'picture'   => $pg['picture'],
				'followers' => $pg['followers'],
				'connected' => in_array( $pg['id'], $have, true ),
			);
		}
		return self::no_store( array( 'pages' => $out ) );
	}

	public static function fb_pending_add( $request ) {
		$p   = (array) $request->get_json_params();
		$key = isset( $p['key'] ) ? sanitize_text_field( (string) $p['key'] ) : '';
		$ids = isset( $p['ids'] ) ? array_map( 'strval', (array) $p['ids'] ) : array();
		$pd  = $key ? get_transient( 'nros_vi_fbp_' . $key ) : false;
		if ( ! $pd ) {
			return new WP_Error( 'nros_vi_pending', 'তালিকার মেয়াদ শেষ — আবার "ফেসবুক যোগ করুন" চাপুন।', array( 'status' => 404 ) );
		}
		$user  = NROS_Auth::get_user_from_request( $request );
		$added = array();
		foreach ( $pd['pages'] as $pg ) {
			if ( ! in_array( (string) $pg['id'], $ids, true ) ) {
				continue;
			}
			$id = self::upsert_account(
				array(
					'platform'  => 'facebook',
					'ext_id'    => $pg['id'],
					'name'      => $pg['name'],
					'url'       => $pg['link'],
					'avatar'    => $pg['picture'],
					'followers' => $pg['followers'],
					'token'     => NROS_Core::decrypt( $pg['token'] ),
				),
				$user instanceof WP_User ? $user->ID : 0
			);
			self::run_sync( self::get_account( $id ), 1 );
			$added[] = self::public_account( self::get_account( $id ) );
		}
		delete_transient( 'nros_vi_fbp_' . $key );
		return self::no_store( array( 'added' => $added ) );
	}

	public static function patch_account( $request ) {
		$acc = self::get_account( (int) $request['id'] );
		if ( ! $acc ) {
			return new WP_Error( 'nros_vi_404', 'অ্যাকাউন্ট নেই।', array( 'status' => 404 ) );
		}
		$p = (array) $request->get_json_params();
		if ( isset( $p['enabled'] ) ) {
			self::update_account( (int) $acc->id, array( 'enabled' => $p['enabled'] ? 1 : 0 ) );
		}
		return self::no_store( self::public_account( self::get_account( (int) $acc->id ) ) );
	}

	public static function sync_now( $request ) {
		$acc = self::get_account( (int) $request['id'] );
		if ( ! $acc ) {
			return new WP_Error( 'nros_vi_404', 'অ্যাকাউন্ট নেই।', array( 'status' => 404 ) );
		}
		delete_transient( 'nros_vi_live_' . $acc->id );
		$r = self::run_sync( $acc, 4 );
		$a = self::public_account( self::get_account( (int) $acc->id ) );
		if ( is_wp_error( $r ) ) {
			$a['sync_error'] = $r->get_error_message();
		}
		return self::no_store( $a );
	}

	public static function delete_account( $request ) {
		global $wpdb;
		$acc = self::get_account( (int) $request['id'] );
		if ( ! $acc ) {
			return new WP_Error( 'nros_vi_404', 'অ্যাকাউন্ট নেই।', array( 'status' => 404 ) );
		}
		if ( $request->get_param( 'purge' ) ) {
			$wpdb->delete( self::table( 'daily' ), array( 'account_id' => (int) $acc->id ) );
			$wpdb->delete( self::table( 'items' ), array( 'account_id' => (int) $acc->id ) );
			$wpdb->delete( self::table( 'accounts' ), array( 'id' => (int) $acc->id ) );
			return self::no_store( array( 'deleted' => true ) );
		}
		// Disconnect: forget the keys, keep every saved day (it still counts in totals).
		$wpdb->update(
			self::table( 'accounts' ),
			array( 'token_enc' => '', 'refresh_enc' => '', 'status' => 'disconnected', 'updated_at' => current_time( 'mysql', true ) ),
			array( 'id' => (int) $acc->id )
		);
		return self::no_store( self::public_account( self::get_account( (int) $acc->id ) ) );
	}

	// ---------------------------------------------------------------------
	// Overview
	// ---------------------------------------------------------------------

	/**
	 * [from, to] (site-local Y-m-d, inclusive) for a period, plus the window before it.
	 */
	public static function range( $period, $from = '', $to = '' ) {
		$tz    = wp_timezone();
		$today = new DateTimeImmutable( 'today', $tz );
		$label = '';
		switch ( $period ) {
			case 'today':
				$s     = $today;
				$e     = $today;
				$label = 'আজ';
				break;
			case '7d':
				$s     = $today->modify( '-6 days' );
				$e     = $today;
				$label = 'গত ৭ দিন';
				break;
			case '6m':
				$s     = $today->modify( '-6 months' )->modify( '+1 day' );
				$e     = $today;
				$label = 'গত ৬ মাস';
				break;
			case 'this_year':
				$s     = $today->setDate( (int) $today->format( 'Y' ), 1, 1 );
				$e     = $today;
				$label = 'এ বছর';
				break;
			case 'last_year':
				$y     = (int) $today->format( 'Y' ) - 1;
				$s     = $today->setDate( $y, 1, 1 );
				$e     = $today->setDate( $y, 12, 31 );
				$label = 'গত বছর';
				break;
			case 'lifetime':
				$s     = null;
				$e     = $today;
				$label = 'লাইফটাইম';
				break;
			case 'custom':
				$s = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $from, $tz );
				$e = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $to, $tz );
				if ( ! $s || ! $e ) {
					return self::range( '30d' );
				}
				if ( $e > $today ) {
					$e = $today;
				}
				if ( $s > $e ) {
					list( $s, $e ) = array( $e, $s );
				}
				$label = 'কাস্টম';
				break;
			default:
				$period = '30d';
				$s      = $today->modify( '-29 days' );
				$e      = $today;
				$label  = 'গত ৩০ দিন';
		}
		$out = array( 'period' => $period, 'label' => $label, 'from' => $s ? $s->format( 'Y-m-d' ) : null, 'to' => $e->format( 'Y-m-d' ), 'prev_from' => null, 'prev_to' => null );
		if ( $s ) {
			$days             = (int) $s->diff( $e )->days + 1;
			$pe               = $s->modify( '-1 day' );
			$out['prev_to']   = $pe->format( 'Y-m-d' );
			$out['prev_from'] = $pe->modify( '-' . ( $days - 1 ) . ' days' )->format( 'Y-m-d' );
			$out['days']      = $days;
		}
		return $out;
	}

	/** Website views per site-local day between two days (inclusive). */
	protected static function website_days( $from, $to ) {
		if ( ! class_exists( 'NROS_Insights' ) || ! NROS_Insights::theme_tables() ) {
			return array();
		}
		$out = array();
		foreach ( NROS_Insights::daily_series( $from ) as $r ) {
			if ( $r['stat_date'] >= $from && $r['stat_date'] <= $to ) {
				$out[ $r['stat_date'] ] = (int) $r['views'];
			}
		}
		return $out;
	}

	/** Earliest day anything is known (lifetime start). */
	protected static function first_day() {
		global $wpdb;
		$c = array();
		if ( class_exists( 'NROS_Insights' ) && NROS_Insights::theme_tables() ) {
			$c[] = NROS_Insights::first_event_day();
		}
		$d = $wpdb->get_var( 'SELECT MIN(d.`day`) FROM ' . self::table( 'daily' ) . ' d INNER JOIN ' . self::table( 'accounts' ) . ' a ON a.id = d.account_id WHERE a.enabled = 1 AND d.views IS NOT NULL' ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( $d ) {
			$c[] = (string) $d;
		}
		return $c ? min( $c ) : wp_date( 'Y-m-d' );
	}

	/** Followers of an account at the end of a day (newest known on/before it). */
	protected static function followers_on( $id, $day ) {
		global $wpdb;
		$v = $wpdb->get_var( $wpdb->prepare( 'SELECT followers FROM ' . self::table( 'daily' ) . ' WHERE account_id = %d AND `day` <= %s AND followers IS NOT NULL ORDER BY `day` DESC LIMIT 1', (int) $id, $day ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		return null === $v ? null : (int) $v;
	}

	/** Bucket key for a day. */
	protected static function bucket( $day, $gran ) {
		if ( 'day' === $gran ) {
			return $day;
		}
		if ( 'month' === $gran ) {
			return substr( $day, 0, 7 );
		}
		$t = strtotime( $day . ' 12:00 UTC' );
		return gmdate( 'Y-m-d', $t - ( (int) gmdate( 'N', $t ) - 1 ) * DAY_IN_SECONDS );
	}

	public static function overview( $request ) {
		global $wpdb;
		$period = sanitize_key( (string) $request->get_param( 'period' ) );
		$r      = self::range( in_array( $period, self::PERIODS, true ) ? $period : '30d', (string) $request->get_param( 'from' ), (string) $request->get_param( 'to' ) );
		$life   = 'lifetime' === $r['period'];
		$from   = $life ? self::first_day() : $r['from'];
		$to     = $r['to'];
		$span   = (int) ( ( strtotime( $to . ' 12:00 UTC' ) - strtotime( $from . ' 12:00 UTC' ) ) / DAY_IN_SECONDS ) + 1;
		$gran   = $span <= 93 ? 'day' : ( $span <= 400 ? 'week' : 'month' );

		// Website.
		$wdays = self::website_days( $life ? $from : ( $r['prev_from'] ? $r['prev_from'] : $from ), $to );
		$wsum  = function ( $a, $b ) use ( $wdays ) {
			$s = 0;
			foreach ( $wdays as $d => $v ) {
				if ( $d >= $a && $d <= $b ) {
					$s += $v;
				}
			}
			return $s;
		};
		$wsince  = class_exists( 'NROS_Insights' ) && NROS_Insights::theme_tables() ? NROS_Insights::first_event_day() : null;
		$website = array(
			'key'        => 'website',
			'views'      => $life && class_exists( 'NROS_Insights' ) ? max( NROS_Insights::lifetime_views(), $wsum( $from, $to ) ) : $wsum( $from, $to ),
			'prev_views' => $life ? null : $wsum( $r['prev_from'], $r['prev_to'] ),
			'followers'  => null,
			'since'      => $wsince,
			'partial'    => $wsince && ! $life && $wsince > $from,
			'accounts'   => array(),
		);

		// Social accounts.
		$accs  = self::all_accounts( true );
		$dt    = self::table( 'daily' );
		$plat  = array();
		$byday = array();
		foreach ( self::PLATFORMS as $p ) {
			$plat[ $p ] = array( 'key' => $p, 'views' => 0, 'prev_views' => $life ? null : 0, 'followers' => null, 'followers_change' => null, 'since' => null, 'partial' => false, 'accounts' => array() );
		}
		foreach ( $accs as $a ) {
			if ( ! isset( $plat[ $a->platform ] ) ) {
				continue;
			}
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT `day`, views FROM {$dt} WHERE account_id = %d AND `day` >= %s AND `day` <= %s AND views IS NOT NULL", (int) $a->id, $life ? '1970-01-01' : ( $r['prev_from'] ? $r['prev_from'] : $from ), $to ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			$v    = 0;
			$pv   = 0;
			foreach ( (array) $rows as $row ) {
				if ( $row->day >= $from ) {
					$v += (int) $row->views;
					$byday[ $row->day ][ $a->platform ] = ( isset( $byday[ $row->day ][ $a->platform ] ) ? $byday[ $row->day ][ $a->platform ] : 0 ) + (int) $row->views;
				} else {
					$pv += (int) $row->views;
				}
			}
			// Lifetime: the channel's own all-time counter beats our sum (it has the years before connection).
			if ( $life && null !== $a->lifetime && 'facebook' !== $a->platform ) {
				$v = max( $v, (int) $a->lifetime );
			}
			$since  = self::data_since( (int) $a->id );
			$fol    = null === $a->followers ? null : (int) $a->followers;
			$fstart = $life ? null : self::followers_on( (int) $a->id, wp_date( 'Y-m-d', strtotime( $from . ' 12:00 UTC' ) - DAY_IN_SECONDS ) );
			$fend   = self::followers_on( (int) $a->id, $to );
			$change = ( null !== $fstart && null !== $fend ) ? $fend - $fstart : null;
			$item   = array(
				'id'               => (int) $a->id,
				'name'             => (string) $a->name,
				'url'              => (string) $a->url,
				'avatar'           => (string) $a->avatar,
				'status'           => (string) $a->status,
				'views'            => $v,
				'prev_views'       => $life ? null : $pv,
				'followers'        => $fol,
				'followers_change' => $change,
				'since'            => $since,
				'partial'          => ! $life && ( ! $since || $since > $from ),
				'last_sync'        => $a->last_sync ? mysql2date( 'c', get_date_from_gmt( $a->last_sync ) ) : null,
			);
			$P = &$plat[ $a->platform ];
			$P['views'] += $v;
			if ( ! $life ) {
				$P['prev_views'] += $pv;
			}
			if ( null !== $fol ) {
				$P['followers'] = (int) $P['followers'] + $fol;
			}
			if ( null !== $change ) {
				$P['followers_change'] = (int) $P['followers_change'] + $change;
			}
			$P['since']    = $since && ( ! $P['since'] || $since < $P['since'] ) ? $since : $P['since'];
			$P['partial']  = $P['partial'] || $item['partial'];
			$P['accounts'][] = $item;
			unset( $P );
		}

		// Series.
		$series = array();
		$cur    = strtotime( $from . ' 12:00 UTC' );
		$end    = strtotime( $to . ' 12:00 UTC' );
		for ( $n = 0; $cur <= $end && $n < 5000; $cur += DAY_IN_SECONDS, $n++ ) {
			$day = gmdate( 'Y-m-d', $cur );
			$k   = self::bucket( $day, $gran );
			if ( ! isset( $series[ $k ] ) ) {
				$series[ $k ] = array( 'key' => $k, 'website' => 0, 'facebook' => 0, 'youtube' => 0, 'tiktok' => 0 );
			}
			$series[ $k ]['website'] += isset( $wdays[ $day ] ) ? $wdays[ $day ] : 0;
			foreach ( self::PLATFORMS as $p ) {
				$series[ $k ][ $p ] += isset( $byday[ $day ][ $p ] ) ? $byday[ $day ][ $p ] : 0;
			}
		}
		foreach ( $series as &$s ) {
			$s['total'] = $s['website'] + $s['facebook'] + $s['youtube'] + $s['tiktok'];
		}
		unset( $s );

		// Totals.
		$platforms = array_merge( array( $website ), array_values( $plat ) );
		$tot       = array( 'views' => 0, 'prev_views' => $life ? null : 0, 'followers' => 0, 'followers_change' => null );
		foreach ( $platforms as $p ) {
			$tot['views'] += $p['views'];
			if ( ! $life ) {
				$tot['prev_views'] += (int) $p['prev_views'];
			}
			$tot['followers'] += (int) $p['followers'];
			if ( null !== ( isset( $p['followers_change'] ) ? $p['followers_change'] : null ) ) {
				$tot['followers_change'] = (int) $tot['followers_change'] + $p['followers_change'];
			}
		}

		// Top content.
		$top = array( 'website' => array() );
		if ( class_exists( 'NROS_Insights' ) ) {
			$tz        = wp_timezone();
			$st        = ( new DateTimeImmutable( $from . ' 00:00:00', $tz ) )->getTimestamp();
			$en        = ( new DateTimeImmutable( $to . ' 00:00:00', $tz ) )->modify( '+1 day' )->getTimestamp();
			$top['website'] = array_map(
				function ( $p ) {
					return array( 'title' => $p['title'], 'url' => $p['url'], 'thumb' => $p['thumb'], 'views' => $p['views'], 'date' => $p['date'], 'account' => '' );
				},
				NROS_Insights::top_posts( $st, min( $en, time() + 1 ), 5, $life )
			);
		}
		$it = self::table( 'items' );
		$at = self::table( 'accounts' );
		foreach ( self::PLATFORMS as $p ) {
			$w   = $life ? '' : $wpdb->prepare( ' AND i.published_at >= %s AND i.published_at < %s', get_gmt_from_date( $from . ' 00:00:00' ), get_gmt_from_date( wp_date( 'Y-m-d', strtotime( $to . ' 12:00 UTC' ) + DAY_IN_SECONDS ) . ' 00:00:00' ) );
			$res = $wpdb->get_results( $wpdb->prepare( "SELECT i.title, i.url, i.thumb, i.views, i.published_at, a.name AS account FROM {$it} i INNER JOIN {$at} a ON a.id = i.account_id WHERE a.enabled = 1 AND a.platform = %s AND i.views IS NOT NULL", $p ) . $w . ' ORDER BY i.views DESC LIMIT 5' ); // phpcs:ignore WordPress.DB.PreparedSQL
			$top[ $p ] = array_map(
				function ( $x ) {
					return array( 'title' => $x->title, 'url' => $x->url, 'thumb' => (string) $x->thumb, 'views' => (int) $x->views, 'date' => $x->published_at ? get_date_from_gmt( $x->published_at ) : '', 'account' => $x->account );
				},
				(array) $res
			);
		}

		return self::no_store(
			array(
				'range'        => array_merge( $r, array( 'from' => $from, 'granularity' => $gran ) ),
				'totals'       => $tot,
				'platforms'    => $platforms,
				'series'       => array_values( $series ),
				'top'          => $top,
				'has_accounts' => (bool) $accs,
				'generated_at' => time(),
			)
		);
	}

	// ---------------------------------------------------------------------
	// Live
	// ---------------------------------------------------------------------

	public static function live( $request ) {
		$web = function_exists( 'meeha_live_summary' ) ? meeha_live_summary( 5 ) : array( 'enabled' => false, 'now' => 0, 'last30' => 0, 'today' => 0, 'devices' => array(), 'pages' => array(), 'window' => 150 );
		$web['supported'] = function_exists( 'meeha_live_summary' );
		$out   = array();
		$total = (int) $web['now'];
		$force = (bool) $request->get_param( 'fresh' );
		foreach ( self::all_accounts( true ) as $a ) {
			if ( 'disconnected' === $a->status || ! $a->token_enc ) {
				continue;
			}
			$key = 'nros_vi_live_' . $a->id;
			$l   = $force ? false : get_transient( $key );
			if ( false === $l ) {
				$l            = NROS_VI_Providers::live( $a );
				$l['checked'] = time();
				set_transient( $key, $l, self::LIVE_TTL );
			}
			$viewers = 0;
			foreach ( $l['items'] as $x ) {
				$viewers += (int) $x['viewers'];
			}
			$total += $viewers;
			$out[]  = array(
				'id'        => (int) $a->id,
				'platform'  => (string) $a->platform,
				'name'      => (string) $a->name,
				'avatar'    => (string) $a->avatar,
				'supported' => $l['supported'],
				'live'      => (bool) $l['items'],
				'viewers'   => $viewers,
				'items'     => $l['items'],
				'error'     => $l['error'],
				'checked'   => gmdate( 'c', (int) $l['checked'] ),
			);
		}
		return self::no_store(
			array(
				'total'    => $total,
				'website'  => $web,
				'accounts' => $out,
				'ttl'      => self::LIVE_TTL,
				'time'     => gmdate( 'c' ),
			)
		);
	}
}
