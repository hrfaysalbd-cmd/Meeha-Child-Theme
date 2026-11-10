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
 * @package NewsroomOS
 */

defined( 'ABSPATH' ) || exit;

class NROS_Push {

	const OPTION = 'nros_vapid';
	const MAX_SUBS_PER_USER = 8;

	/** @var array[] queued [sub row, payload array, options] */
	protected static $queue = array();

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'shutdown', array( __CLASS__, 'flush' ), 1 );
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
		if ( ! $uids || ! self::supported() || ! self::vapid() ) {
			return;
		}
		foreach ( array_chunk( $uids, 500 ) as $chunk ) {
			$rows = $wpdb->get_results( 'SELECT * FROM ' . self::table() . ' WHERE user_id IN (' . implode( ',', $chunk ) . ') AND fails < 5', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			foreach ( (array) $rows as $s ) {
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
			$reqs[] = array( $s, $body, $headers );
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
					$ch = curl_init( $r[0]['endpoint'] );
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
					$out[ (int) $batch[ $i ][0]['id'] ] = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
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
				$res = wp_remote_post( $r[0]['endpoint'], array( 'body' => $r[1], 'headers' => $h, 'timeout' => 10 ) );
				$out[ (int) $r[0]['id'] ] = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
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
		$endpoint = esc_url_raw( (string) $request->get_param( 'endpoint' ), array( 'https' ) );
		$keys     = (array) $request->get_param( 'keys' );
		$p256     = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) ( $keys['p256dh'] ?? '' ) );
		$auth     = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) ( $keys['auth'] ?? '' ) );
		if ( ! $endpoint || 0 !== strpos( $endpoint, 'https://' ) || 65 !== strlen( self::unb64u( $p256 ) ) || strlen( self::unb64u( $auth ) ) < 16 ) {
			return new WP_Error( 'nros_bad', 'সাবস্ক্রিপশন ঠিক নেই।', array( 'status' => 400 ) );
		}
		$app  = in_array( $request->get_param( 'app' ), array( 'publisher', 'correspondent' ), true ) ? $request->get_param( 'app' ) : '';
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
		$wpdb->delete( self::table(), array( 'ep_hash' => sha1( esc_url_raw( (string) $request->get_param( 'endpoint' ), array( 'https' ) ) ), 'user_id' => (int) $u->ID ) );
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
}
