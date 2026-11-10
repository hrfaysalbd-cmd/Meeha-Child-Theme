<?php
/**
 * 1.20.0 — ওয়েব-পুশ: notices reach the phone even when the app is closed.
 *
 * Standard Web Push (RFC 8030) with VAPID (RFC 8292) and aes128gcm payload
 * encryption (RFC 8291), done with PHP's own OpenSSL — no outside service,
 * no Firebase account, no Composer packages. Chrome, Edge, Firefox and
 * Samsung Internet on Android, desktop browsers, and iPhone (16.4+, only
 * when the app was added to the Home Screen) all accept it.
 *
 * Sends are queued during the request and go out after the answer has been
 * sent to the app (fastcgi_finish_request), in parallel with curl_multi.
 *
 * 1.21.0 — the Android app (Capacitor, মিহা সংবাদদাতা) gets its notices
 * through Firebase Cloud Messaging instead: its devices are stored here with
 * the endpoint "fcm:<token>", and the site signs in to FCM with a service
 * account pasted under wp-admin → "অ্যাপ নোটিফিকেশন (Firebase)".
 *
 * @package NewsroomOS
 */

defined( 'ABSPATH' ) || exit;

class NROS_Push {

	const OPTION = 'nros_vapid';
	const MAX_SUBS_PER_USER = 8;

	/** @var array[] queued [sub row, payload array, options] */
	protected static $queue = array();

	const FCM_OPTION = 'nros_fcm';
	const FCM_PREFIX = 'fcm:';
	/** Where the Android app's deploy package puts its version file. */
	const ANDROID_INFO_URL = 'https://correspondent.meeha.news/download/android.json';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'shutdown', array( __CLASS__, 'flush' ), 1 );
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 30 );
		add_action( 'admin_init', array( __CLASS__, 'admin_save' ) );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'nros_push_subs';
	}

	public static function schema( $c ) {
		$t = self::table();
		return "CREATE TABLE {$t} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			app VARCHAR(20) NOT NULL DEFAULT '',
			endpoint TEXT NOT NULL,
			ep_hash CHAR(40) NOT NULL,
			p256dh VARCHAR(120) NOT NULL,
			auth VARCHAR(60) NOT NULL,
			ua VARCHAR(190) NOT NULL DEFAULT '',
			fails SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			last_ok DATETIME NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY ep_hash (ep_hash),
			KEY user_id (user_id)
		) {$c};";
	}

	public static function supported() {
		return function_exists( 'openssl_pkey_derive' ) && function_exists( 'hash_hkdf' ) && in_array( 'prime256v1', (array) openssl_get_curve_names(), true );
	}

	// ------------------------------------------------------------------
	// small helpers
	// ------------------------------------------------------------------

	public static function b64u( $bin ) {
		return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	public static function unb64u( $s ) {
		$s = strtr( (string) $s, '-_', '+/' );
		return (string) base64_decode( $s . str_repeat( '=', ( 4 - strlen( $s ) % 4 ) % 4 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
	}

	/** 65-byte uncompressed P-256 point → OpenSSL public key. */
	protected static function pub_from_point( $point ) {
		$der = hex2bin( '3059301306072a8648ce3d020106082a8648ce3d030107034200' ) . $point;
		$pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $der ), 64, "\n" ) . "-----END PUBLIC KEY-----\n"; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return openssl_pkey_get_public( $pem );
	}

	/** OpenSSL EC key → 65-byte public point. */
	protected static function point_of( $key ) {
		$d = openssl_pkey_get_details( $key );
		return "\x04" . str_pad( $d['ec']['x'], 32, "\0", STR_PAD_LEFT ) . str_pad( $d['ec']['y'], 32, "\0", STR_PAD_LEFT );
	}

	protected static function new_key() {
		return openssl_pkey_new( array( 'curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC ) );
	}

	/**
	 * The site's VAPID key pair (made once).
	 *
	 * @return array{public:string,pem:string}|null
	 */
	public static function vapid() {
		$v = get_option( self::OPTION );
		if ( is_array( $v ) && ! empty( $v['public'] ) && ! empty( $v['pem'] ) ) {
			return $v;
		}
		if ( ! self::supported() ) {
			return null;
		}
		$k = self::new_key();
		if ( ! $k ) {
			return null;
		}
		openssl_pkey_export( $k, $pem );
		$v = array( 'public' => self::b64u( self::point_of( $k ) ), 'pem' => $pem );
		update_option( self::OPTION, $v, false );
		return $v;
	}

	/** DER ECDSA signature → 64-byte r||s (JOSE). */
	protected static function der_to_jose( $der ) {
		$pos = 3;
		if ( "\x81" === $der[1] ) {
			$pos = 4;
		}
		$rlen = ord( $der[ $pos ] );
		$r    = substr( $der, $pos + 1, $rlen );
		$pos  = $pos + 1 + $rlen + 1;
		$slen = ord( $der[ $pos ] );
		$s    = substr( $der, $pos + 1, $slen );
		$r    = str_pad( ltrim( $r, "\0" ), 32, "\0", STR_PAD_LEFT );
		$s    = str_pad( ltrim( $s, "\0" ), 32, "\0", STR_PAD_LEFT );
		return $r . $s;
	}

	protected static function vapid_header( $endpoint ) {
		$v = self::vapid();
		$p = wp_parse_url( $endpoint );
		$aud = $p['scheme'] . '://' . $p['host'] . ( isset( $p['port'] ) ? ':' . $p['port'] : '' );
		$h   = self::b64u( wp_json_encode( array( 'typ' => 'JWT', 'alg' => 'ES256' ) ) );
		$c   = self::b64u( wp_json_encode( array( 'aud' => $aud, 'exp' => time() + 12 * HOUR_IN_SECONDS, 'sub' => home_url( '/' ) ) ) );
		openssl_sign( $h . '.' . $c, $der, openssl_pkey_get_private( $v['pem'] ), OPENSSL_ALGO_SHA256 );
		return 'vapid t=' . $h . '.' . $c . '.' . self::b64u( self::der_to_jose( $der ) ) . ', k=' . $v['public'];
	}

	/**
	 * RFC 8291 aes128gcm body for one subscription.
	 */
	public static function encrypt( $plain, $p256dh_b64u, $auth_b64u ) {
		$ua_pub = self::unb64u( $p256dh_b64u );
		$auth   = self::unb64u( $auth_b64u );
		if ( 65 !== strlen( $ua_pub ) || strlen( $auth ) < 16 ) {
			return null;
		}
		$peer = self::pub_from_point( $ua_pub );
		$eph  = self::new_key();
		if ( ! $peer || ! $eph ) {
			return null;
		}
		$as_pub = self::point_of( $eph );
		$secret = openssl_pkey_derive( $peer, $eph, 32 );
		if ( false === $secret ) {
			return null;
		}
		$salt  = random_bytes( 16 );
		$ikm   = hash_hkdf( 'sha256', $secret, 32, "WebPush: info\0" . $ua_pub . $as_pub, $auth );
		$cek   = hash_hkdf( 'sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt );
		$nonce = hash_hkdf( 'sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt );
		$tag   = '';
		$ct    = openssl_encrypt( $plain . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag );
		if ( false === $ct ) {
			return null;
		}
		return $salt . pack( 'N', 4096 ) . chr( 65 ) . $as_pub . $ct . $tag;
	}

	// ------------------------------------------------------------------
	// sending
	// ------------------------------------------------------------------

	/**
	 * Queue a notice for some users (sent after the response).
	 *
	 * @param int[]           $uids    Users.
	 * @param array|callable  $payload {title, body, url, tag, urgent?} or fn( $sub_row ) → array.
	 * @param array           $opt     { topic?, urgency? (very-low|low|normal|high), ttl? }.
	 */
	public static function to_users( $uids, $payload, $opt = array() ) {
		global $wpdb;
		$uids = array_values( array_unique( array_filter( array_map( 'intval', (array) $uids ) ) ) );
		$web_ok = self::supported() && self::vapid();
		$fcm_ok = (bool) self::fcm_account();
		if ( ! $uids || ( ! $web_ok && ! $fcm_ok ) ) {
			return;
		}
		foreach ( array_chunk( $uids, 500 ) as $chunk ) {
			$rows = $wpdb->get_results( 'SELECT * FROM ' . self::table() . ' WHERE user_id IN (' . implode( ',', $chunk ) . ') AND fails < 5', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			foreach ( (array) $rows as $s ) {
				if ( ! ( self::is_fcm( $s ) ? $fcm_ok : $web_ok ) ) {
					continue;
				}
				$p = is_callable( $payload ) ? call_user_func( $payload, $s ) : $payload;
				if ( $p ) {
					self::$queue[] = array( $s, $p, $opt );
				}
			}
		}
	}

	/**
	 * Sends what was queued. Runs on shutdown, after the app has its answer.
	 */
	public static function flush() {
		if ( ! self::$queue ) {
			return;
		}
		$queue       = self::$queue;
		self::$queue = array();
		// answer the app first (PHP-FPM / LiteSpeed), then talk to the push services
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		} elseif ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
		}
		ignore_user_abort( true );
		self::send_now( $queue );
	}

	/**
	 * @param array[] $queue [sub, payload, opt].
	 * @return array<int,int> sub id → HTTP status
	 */
	public static function send_now( $queue ) {
		global $wpdb;
		$reqs = array();
		foreach ( $queue as $q ) {
			list( $s, $p, $opt ) = $q;
			if ( self::is_fcm( $s ) ) {
				$r = self::fcm_request( $s, $p, $opt );
				if ( $r ) {
					$reqs[] = $r;
				}
				continue;
			}
			$body = self::encrypt( wp_json_encode( $p ), $s['p256dh'], $s['auth'] );
			if ( null === $body ) {
				continue;
			}
			$headers = array(
				'Content-Type: application/octet-stream',
				'Content-Encoding: aes128gcm',
				'TTL: ' . (int) ( isset( $opt['ttl'] ) ? $opt['ttl'] : DAY_IN_SECONDS ),
				'Urgency: ' . ( isset( $opt['urgency'] ) ? $opt['urgency'] : 'normal' ),
				'Authorization: ' . self::vapid_header( $s['endpoint'] ),
			);
			if ( ! empty( $opt['topic'] ) ) {
				$headers[] = 'Topic: ' . substr( preg_replace( '/[^A-Za-z0-9_-]/', '', $opt['topic'] ), 0, 32 );
			}
			$reqs[] = array( $s, $body, $headers, $s['endpoint'] );
		}
		$out = array();
		if ( ! $reqs ) {
			return $out;
		}
		if ( function_exists( 'curl_multi_init' ) ) {
			foreach ( array_chunk( $reqs, 50 ) as $batch ) {
				$mh  = curl_multi_init();
				$hs  = array();
				foreach ( $batch as $i => $r ) {
					$ch = curl_init( $r[3] );
					curl_setopt_array(
						$ch,
						array(
							CURLOPT_POST           => true,
							CURLOPT_POSTFIELDS     => $r[1],
							CURLOPT_HTTPHEADER     => $r[2],
							CURLOPT_RETURNTRANSFER => true,
							CURLOPT_TIMEOUT        => 10,
							CURLOPT_CONNECTTIMEOUT => 5,
						)
					);
					curl_multi_add_handle( $mh, $ch );
					$hs[ $i ] = $ch;
				}
				do {
					$st = curl_multi_exec( $mh, $running );
					if ( $running ) {
						curl_multi_select( $mh, 1.0 );
					}
				} while ( $running && CURLM_OK === $st );
				foreach ( $hs as $i => $ch ) {
					$out[ (int) $batch[ $i ][0]['id'] ] = self::status_of( $batch[ $i ][0], (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE ), (string) curl_multi_getcontent( $ch ) );
					curl_multi_remove_handle( $mh, $ch );
					curl_close( $ch );
				}
				curl_multi_close( $mh );
			}
		} else {
			foreach ( $reqs as $r ) {
				$h = array();
				foreach ( $r[2] as $line ) {
					list( $k, $v ) = explode( ': ', $line, 2 );
					$h[ $k ] = $v;
				}
				$res = wp_remote_post( $r[3], array( 'body' => $r[1], 'headers' => $h, 'timeout' => 10 ) );
				$out[ (int) $r[0]['id'] ] = is_wp_error( $res ) ? 0 : self::status_of( $r[0], (int) wp_remote_retrieve_response_code( $res ), (string) wp_remote_retrieve_body( $res ) );
			}
		}
		// gone subscriptions are dropped; other failures counted
		foreach ( $out as $id => $code ) {
			if ( 404 === $code || 410 === $code ) {
				$wpdb->delete( self::table(), array( 'id' => $id ) );
			} elseif ( $code >= 200 && $code < 300 ) {
				$wpdb->update( self::table(), array( 'fails' => 0, 'last_ok' => current_time( 'mysql' ) ), array( 'id' => $id ) );
			} else {
				$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET fails = fails + 1 WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			}
		}
		return $out;
	}

	// ------------------------------------------------------------------
	// REST
	// ------------------------------------------------------------------

	public static function register_routes() {
		$app = array( 'NROS_Core', 'require_app_user' );
		register_rest_route( NROS_API_NS, '/push/key', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'key' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( NROS_API_NS, '/push/subscribe', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'subscribe' ), 'permission_callback' => $app ) );
		register_rest_route( NROS_API_NS, '/push/unsubscribe', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'unsubscribe' ), 'permission_callback' => $app ) );
		register_rest_route( NROS_API_NS, '/push/test', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'test' ), 'permission_callback' => $app ) );
		register_rest_route( NROS_API_NS, '/app/android', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'android_info' ), 'permission_callback' => '__return_true' ) );
	}

	public static function key() {
		$v = self::vapid();
		if ( ! $v ) {
			return new WP_Error( 'nros_push_off', 'এই সার্ভারে পুশ চালানো যাচ্ছে না (PHP OpenSSL পুরোনো)।', array( 'status' => 501 ) );
		}
		return rest_ensure_response( array( 'key' => $v['public'] ) );
	}

	/**
	 * POST /push/subscribe — { endpoint, keys: { p256dh, auth }, app }
	 */
	public static function subscribe( $request ) {
		global $wpdb;
		$u        = NROS_Auth::get_user_from_request( $request );
		$fcm      = (string) $request->get_param( 'fcm' );
		if ( '' !== $fcm ) {
			return self::subscribe_fcm( $request, $u, $fcm );
		}
		$endpoint = esc_url_raw( (string) $request->get_param( 'endpoint' ), array( 'https' ) );
		$keys     = (array) $request->get_param( 'keys' );
		$p256     = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) ( $keys['p256dh'] ?? '' ) );
		$auth     = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) ( $keys['auth'] ?? '' ) );
		if ( ! $endpoint || 0 !== strpos( $endpoint, 'https://' ) || 65 !== strlen( self::unb64u( $p256 ) ) || strlen( self::unb64u( $auth ) ) < 16 ) {
			return new WP_Error( 'nros_bad', 'সাবস্ক্রিপশন ঠিক নেই।', array( 'status' => 400 ) );
		}
		$app  = in_array( $request->get_param( 'app' ), array( 'publisher', 'correspondent' ), true ) ? $request->get_param( 'app' ) : '';
		return self::save_sub( $request, $u, $app, $endpoint, $p256, $auth );
	}

	/** POST /push/subscribe with { fcm: <token>, app } from the Android app. */
	protected static function subscribe_fcm( $request, $u, $token ) {
		if ( ! preg_match( '/^[A-Za-z0-9:_\-]{20,4096}$/', $token ) ) {
			return new WP_Error( 'nros_bad', 'অ্যাপের টোকেন ঠিক নেই।', array( 'status' => 400 ) );
		}
		if ( ! self::fcm_account() ) {
			return new WP_Error( 'nros_fcm_off', 'সাইটে এখনো Firebase চাবি বসানো হয়নি। ওয়ার্ডপ্রেস অ্যাডমিন → অ্যাপ নোটিফিকেশন (Firebase)-এ বসান।', array( 'status' => 503 ) );
		}
		$app = in_array( $request->get_param( 'app' ), array( 'publisher', 'correspondent' ), true ) ? $request->get_param( 'app' ) : 'correspondent';
		return self::save_sub( $request, $u, $app, self::FCM_PREFIX . $token, '', '' );
	}

	protected static function save_sub( $request, $u, $app, $endpoint, $p256, $auth ) {
		global $wpdb;
		$hash = sha1( $endpoint );
		$row  = array(
			'user_id'    => (int) $u->ID,
			'app'        => $app,
			'endpoint'   => $endpoint,
			'ep_hash'    => $hash,
			'p256dh'     => $p256,
			'auth'       => $auth,
			'ua'         => mb_substr( sanitize_text_field( (string) $request->get_header( 'user_agent' ) ), 0, 190 ),
			'fails'      => 0,
			'created_at' => current_time( 'mysql' ),
		);
		$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE ep_hash = %s', $hash ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( $id ) {
			unset( $row['created_at'] );
			$wpdb->update( self::table(), $row, array( 'id' => $id ) );
		} else {
			$wpdb->insert( self::table(), $row );
			// keep the newest few devices per person
			$old = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE user_id = %d ORDER BY id DESC LIMIT 100 OFFSET %d', $u->ID, self::MAX_SUBS_PER_USER ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( $old ) {
				$wpdb->query( 'DELETE FROM ' . self::table() . ' WHERE id IN (' . implode( ',', array_map( 'intval', $old ) ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			}
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public static function unsubscribe( $request ) {
		global $wpdb;
		$u = NROS_Auth::get_user_from_request( $request );
		$fcm = (string) $request->get_param( 'fcm' );
		$ep  = '' !== $fcm ? self::FCM_PREFIX . $fcm : esc_url_raw( (string) $request->get_param( 'endpoint' ), array( 'https' ) );
		$wpdb->delete( self::table(), array( 'ep_hash' => sha1( $ep ), 'user_id' => (int) $u->ID ) );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/** POST /push/test — sends "it works" to my own devices, right away. */
	public static function test( $request ) {
		global $wpdb;
		$u    = NROS_Auth::get_user_from_request( $request );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE user_id = %d', $u->ID ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $rows ) {
			return new WP_Error( 'nros_none', 'এই অ্যাকাউন্টে কোনো ডিভাইস চালু নেই।', array( 'status' => 404 ) );
		}
		$q = array();
		foreach ( $rows as $s ) {
			$q[] = array( $s, array( 'title' => '✅ মিহা নোটিফিকেশন চালু আছে', 'body' => 'অ্যাপ বন্ধ থাকলেও এখন থেকে চ্যাট ও জরুরি খবর এভাবে আসবে।', 'url' => '/', 'tag' => 'test' ), array( 'ttl' => 300 ) );
		}
		$res = self::send_now( $q );
		$ok  = count( array_filter( $res, function ( $c ) { return $c >= 200 && $c < 300; } ) );
		return rest_ensure_response( array( 'devices' => count( $rows ), 'sent' => $ok ) );
	}

	/**
	 * In-app notices (ইনবক্স + পোস্ট/ভিডিও এলার্ট) → push too.
	 */
	public static function on_notification( $uids, $type, $title, $body, $link ) {
		$alert = 0 === strpos( (string) $type, 'pd_alert' );
		self::to_users(
			$uids,
			array(
				'title' => ( $alert ? ( false !== strpos( $type, 'video' ) ? '▶ ' : '🔔 ' ) : '📥 ' ) . wp_strip_all_tags( $title ),
				'body'  => wp_html_excerpt( wp_strip_all_tags( (string) $body ), 160, '…' ),
				'url'   => $link ? $link : '/',
				'tag'   => 'n-' . sanitize_key( $type ),
				'kind'  => $alert ? 'alert' : 'inbox',
			),
			array( 'topic' => 'n-' . sanitize_key( $type ), 'ttl' => 2 * DAY_IN_SECONDS )
		);
	}

	// ------------------------------------------------------------------
	// 1.21.0 — Firebase Cloud Messaging for the Android app
	// ------------------------------------------------------------------

	public static function is_fcm( $s ) {
		return 0 === strpos( (string) $s['endpoint'], self::FCM_PREFIX );
	}

	/**
	 * The pasted service account (project_id, client_email, private_key), or null.
	 */
	public static function fcm_account() {
		$a = get_option( self::FCM_OPTION );
		if ( ! is_array( $a ) || empty( $a['project_id'] ) || empty( $a['client_email'] ) || empty( $a['private_key'] ) ) {
			return null;
		}
		return $a;
	}

	/** Google OAuth access token for FCM (cached ~50 min). */
	protected static function fcm_token( $force = false ) {
		$a = self::fcm_account();
		if ( ! $a ) {
			return '';
		}
		$t = get_transient( 'nros_fcm_token' );
		if ( $t && ! $force ) {
			return $t;
		}
		$now = time();
		$h   = self::b64u( wp_json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) ) );
		$c   = self::b64u(
			wp_json_encode(
				array(
					'iss'   => $a['client_email'],
					'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
					'aud'   => 'https://oauth2.googleapis.com/token',
					'iat'   => $now,
					'exp'   => $now + 3600,
				)
			)
		);
		$key = openssl_pkey_get_private( $a['private_key'] );
		if ( ! $key || ! openssl_sign( $h . '.' . $c, $sig, $key, OPENSSL_ALGO_SHA256 ) ) {
			update_option( 'nros_fcm_error', 'Firebase চাবির private_key পড়া গেল না।', false );
			return '';
		}
		$res = wp_remote_post(
			apply_filters( 'nros_fcm_token_url', 'https://oauth2.googleapis.com/token' ),
			array(
				'timeout' => 10,
				'body'    => array(
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => $h . '.' . $c . '.' . self::b64u( $sig ),
				),
			)
		);
		$j = is_wp_error( $res ) ? null : json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( empty( $j['access_token'] ) ) {
			update_option( 'nros_fcm_error', 'Google লগইন হলো না: ' . ( is_wp_error( $res ) ? $res->get_error_message() : mb_substr( (string) wp_remote_retrieve_body( $res ), 0, 200 ) ), false );
			return '';
		}
		delete_option( 'nros_fcm_error' );
		set_transient( 'nros_fcm_token', $j['access_token'], max( 300, (int) ( $j['expires_in'] ?? 3600 ) - 600 ) );
		return $j['access_token'];
	}

	/**
	 * One FCM HTTP v1 request: [sub, body, headers, url], or null.
	 */
	protected static function fcm_request( $s, $p, $opt ) {
		$a     = self::fcm_account();
		$token = self::fcm_token();
		if ( ! $a || ! $token ) {
			return null;
		}
		$urgent = ! empty( $p['urgent'] );
		$kind   = isset( $p['kind'] ) ? (string) $p['kind'] : '';
		$data   = array();
		foreach ( array( 'url', 'tag', 'kind' ) as $k ) {
			if ( isset( $p[ $k ] ) ) {
				$data[ $k ] = (string) $p[ $k ];
			}
		}
		$data['urgent'] = $urgent ? '1' : '0';
		$ttl            = (int) ( isset( $opt['ttl'] ) ? $opt['ttl'] : DAY_IN_SECONDS );
		$msg            = array(
			'token'        => substr( $s['endpoint'], strlen( self::FCM_PREFIX ) ),
			'notification' => array(
				'title' => (string) ( $p['title'] ?? 'মিহা' ),
				'body'  => (string) ( $p['body'] ?? '' ),
			),
			'data'         => $data,
			'android'      => array(
				// chat and approvals should not wait for the phone's next wake-up
				'priority'     => ( 'alert' === $kind ) ? 'NORMAL' : 'HIGH',
				'ttl'          => max( 0, $ttl ) . 's',
				'notification' => array(
					'channel_id'            => $urgent ? 'urgent' : ( 'chat' === $kind ? 'chat' : 'general' ),
					'tag'                   => (string) ( $p['tag'] ?? '' ),
					'sound'                 => 'default',
					'notification_priority' => $urgent ? 'PRIORITY_MAX' : 'PRIORITY_HIGH',
					'default_vibrate_timings' => true,
					'color'                 => '#b91c1c',
				),
			),
		);
		if ( '' === $msg['android']['notification']['tag'] ) {
			unset( $msg['android']['notification']['tag'] );
		}
		if ( ! empty( $opt['topic'] ) ) {
			$msg['android']['collapse_key'] = substr( preg_replace( '/[^A-Za-z0-9_-]/', '', $opt['topic'] ), 0, 64 );
		}
		return array(
			$s,
			wp_json_encode( array( 'message' => $msg ) ),
			array( 'Content-Type: application/json; charset=UTF-8', 'Authorization: Bearer ' . $token ),
			apply_filters( 'nros_fcm_base', 'https://fcm.googleapis.com' ) . '/v1/projects/' . rawurlencode( $a['project_id'] ) . '/messages:send',
		);
	}

	/**
	 * HTTP answer → status used for clean-up. FCM says "this app was removed"
	 * with 404 UNREGISTERED (or 400 for a malformed token) → treated as 410.
	 */
	protected static function status_of( $s, $code, $body ) {
		if ( ! self::is_fcm( $s ) ) {
			return $code;
		}
		if ( 404 === $code || ( 400 === $code && false !== strpos( $body, 'registration token' ) ) || false !== strpos( $body, 'UNREGISTERED' ) ) {
			return 410;
		}
		if ( 401 === $code || 403 === $code ) {
			delete_transient( 'nros_fcm_token' );
			update_option( 'nros_fcm_error', 'Firebase অনুমতি দিল না (' . $code . '): ' . mb_substr( $body, 0, 200 ), false );
		}
		return $code;
	}

	/**
	 * GET /app/android — the newest Android app (from the deploy package's
	 * download/android.json on the correspondent site), for the in-app
	 * "নতুন ভার্সন" notice.
	 */
	public static function android_info() {
		$info = get_transient( 'nros_android_info' );
		if ( false === $info ) {
			$url  = apply_filters( 'nros_android_info_url', self::ANDROID_INFO_URL );
			$res  = wp_remote_get( add_query_arg( 't', time(), $url ), array( 'timeout' => 6 ) );
			$j    = is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ? null : json_decode( (string) wp_remote_retrieve_body( $res ), true );
			$info = is_array( $j ) && ! empty( $j['version_code'] ) ? array(
				'version_code' => (int) $j['version_code'],
				'version_name' => sanitize_text_field( (string) ( $j['version_name'] ?? '' ) ),
				'url'          => esc_url_raw( (string) ( $j['url'] ?? '' ) ),
				'notes'        => sanitize_text_field( (string) ( $j['notes'] ?? '' ) ),
				'min_code'     => (int) ( $j['min_code'] ?? 0 ),
			) : array();
			set_transient( 'nros_android_info', $info, 10 * MINUTE_IN_SECONDS );
		}
		$r = rest_ensure_response( $info ? $info : array( 'version_code' => 0 ) );
		$r->header( 'Cache-Control', 'no-store' );
		return $r;
	}

	// ---- wp-admin: paste the Firebase service account ----

	public static function admin_menu() {
		$parent = isset( $GLOBALS['admin_page_hooks']['meeha-homepage-settings'] ) ? 'meeha-homepage-settings' : 'nros-applications';
		add_submenu_page( $parent, 'অ্যাপ নোটিফিকেশন (Firebase)', 'অ্যাপ নোটিফিকেশন (Firebase)', 'manage_options', 'nros-fcm', array( __CLASS__, 'render_admin' ) );
	}

	public static function admin_save() {
		if ( empty( $_POST['nros_fcm_action'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'nros_fcm', 'nros_fcm_nonce' );
		$act = sanitize_key( wp_unslash( $_POST['nros_fcm_action'] ) );
		$msg = '';
		if ( 'remove' === $act ) {
			delete_option( self::FCM_OPTION );
			delete_transient( 'nros_fcm_token' );
			$msg = 'removed';
		} elseif ( 'save' === $act ) {
			$raw = isset( $_POST['nros_fcm_json'] ) ? (string) wp_unslash( $_POST['nros_fcm_json'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON key, checked below
			$j   = json_decode( trim( $raw ), true );
			if ( ! is_array( $j ) || ( $j['type'] ?? '' ) !== 'service_account' || empty( $j['project_id'] ) || empty( $j['client_email'] ) || empty( $j['private_key'] ) || ! openssl_pkey_get_private( $j['private_key'] ) ) {
				$msg = 'bad';
			} else {
				update_option(
					self::FCM_OPTION,
					array(
						'project_id'   => sanitize_text_field( $j['project_id'] ),
						'client_email' => sanitize_email( $j['client_email'] ),
						'private_key'  => $j['private_key'],
					),
					false
				);
				delete_transient( 'nros_fcm_token' );
				$msg = self::fcm_token( true ) ? 'ok' : 'saved-noauth';
			}
		}
		wp_safe_redirect( add_query_arg( array( 'page' => 'nros-fcm', 'nros_fcm' => $msg ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function render_admin() {
		global $wpdb;
		$a    = self::fcm_account();
		$err  = get_option( 'nros_fcm_error' );
		$done = isset( $_GET['nros_fcm'] ) ? sanitize_key( wp_unslash( $_GET['nros_fcm'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$n    = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE endpoint LIKE %s', self::FCM_PREFIX . '%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$msgs = array(
			'ok'           => array( 'success', 'চাবি বসানো হয়েছে, Google-এ লগইনও হয়েছে ✅' ),
			'saved-noauth' => array( 'warning', 'চাবি রাখা হয়েছে, কিন্তু Google-এ লগইন হলো না। নিচের ভুলের লেখাটা দেখুন।' ),
			'bad'          => array( 'error', 'এটা Firebase-এর service account JSON নয়, বা অসম্পূর্ণ। পুরো ফাইলের লেখা কপি করে বসান।' ),
			'removed'      => array( 'success', 'চাবি সরানো হয়েছে।' ),
		);
		?>
		<div class="wrap">
			<h1>অ্যাপ নোটিফিকেশন (Firebase)</h1>
			<?php if ( isset( $msgs[ $done ] ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( $msgs[ $done ][0] ); ?>"><p><?php echo esc_html( $msgs[ $done ][1] ); ?></p></div>
			<?php endif; ?>
			<p>মিহা সংবাদদাতার Android অ্যাপে নোটিফিকেশন যায় Firebase Cloud Messaging দিয়ে। ব্রাউজারের নোটিফিকেশন এটা ছাড়াই চলে।</p>
			<p><b>অবস্থা:</b>
				<?php echo $a ? '✅ চালু (প্রজেক্ট: <code>' . esc_html( $a['project_id'] ) . '</code>)' : '⛔ চাবি বসানো হয়নি'; ?>
				· অ্যাপে নোটিফিকেশন চালু আছে <?php echo esc_html( number_format_i18n( $n ) ); ?>টি ফোনে
			</p>
			<?php if ( $err ) : ?>
				<p style="color:#b32d2e"><b>শেষ ভুল:</b> <?php echo esc_html( $err ); ?></p>
			<?php endif; ?>
			<ol>
				<li>Firebase Console → আপনার প্রজেক্ট → ⚙ Project settings → <b>Service accounts</b> → <b>Generate new private key</b>। একটা .json ফাইল নামবে।</li>
				<li>ফাইলটা Notepad-এ খুলে পুরো লেখা কপি করে নিচে বসান, তারপর <b>রাখুন</b>।</li>
			</ol>
			<form method="post">
				<?php wp_nonce_field( 'nros_fcm', 'nros_fcm_nonce' ); ?>
				<input type="hidden" name="nros_fcm_action" value="save" />
				<textarea name="nros_fcm_json" rows="10" class="large-text code" placeholder='{"type": "service_account", "project_id": "...", ...}'></textarea>
				<p><button class="button button-primary">রাখুন</button></p>
			</form>
			<?php if ( $a ) : ?>
				<form method="post" onsubmit="return confirm('চাবি সরালে অ্যাপে নোটিফিকেশন যাবে না। সরাবেন?')">
					<?php wp_nonce_field( 'nros_fcm', 'nros_fcm_nonce' ); ?>
					<input type="hidden" name="nros_fcm_action" value="remove" />
					<p><button class="button">চাবি সরান</button></p>
				</form>
			<?php endif; ?>
			<p class="description">চাবিটা গোপন রাখুন। কাউকে পাঠাবেন না, শুধু এখানে বসাবেন।</p>
		</div>
		<?php
	}
}
