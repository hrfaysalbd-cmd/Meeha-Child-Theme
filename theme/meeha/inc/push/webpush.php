<?php
/**
 * Web Push without a third-party service: VAPID (RFC 8292, ES256 JWT) + payload encryption (RFC 8291, aes128gcm).
 * Needs PHP openssl with prime256v1 (openssl_pkey_derive, PHP ≥ 7.3) — standard on shared hosting.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

/**
 * Push services we deliver to. Restricting hosts stops anyone registering an internal URL as an "endpoint"
 * and making the server POST to it.
 *
 * @return string[] host suffixes
 */
function meeha_push_hosts() {
	return (array) apply_filters(
		'meeha_push_hosts',
		array( 'fcm.googleapis.com', 'android.googleapis.com', 'updates.push.services.mozilla.com', 'push.services.mozilla.com', 'notify.windows.com', 'push.apple.com' )
	);
}

/**
 * Is this a push-service endpoint we accept?
 *
 * @param string $url URL.
 * @return bool
 */
function meeha_push_endpoint_ok( $url ) {
	$p = wp_parse_url( (string) $url );
	if ( empty( $p['scheme'] ) || 'https' !== $p['scheme'] || empty( $p['host'] ) || strlen( $url ) > 1024 ) {
		return false;
	}
	$host = strtolower( $p['host'] );
	foreach ( meeha_push_hosts() as $suffix ) {
		if ( $host === $suffix || substr( $host, -strlen( '.' . $suffix ) ) === '.' . $suffix ) {
			return true;
		}
	}
	return false;
}

/**
 * Base64url encode/decode.
 *
 * @param string $s Data.
 * @return string
 */
function meeha_push_b64u( $s ) {
	return rtrim( strtr( base64_encode( $s ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
}
/**
 * Decode base64url.
 *
 * @param string $s Text.
 * @return string|false
 */
function meeha_push_b64u_dec( $s ) {
	$s = strtr( (string) $s, '-_', '+/' );
	return base64_decode( $s . str_repeat( '=', ( 4 - strlen( $s ) % 4 ) % 4 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
}

/**
 * Can this server encrypt and sign push messages?
 *
 * @return bool
 */
function meeha_push_supported() {
	static $ok = null;
	if ( null === $ok ) {
		$ok = function_exists( 'openssl_pkey_derive' ) && function_exists( 'hash_hkdf' ) && in_array( 'aes-128-gcm', openssl_get_cipher_methods(), true )
			&& in_array( 'prime256v1', openssl_get_curve_names() ?: array(), true ) && (bool) meeha_push_keys();
	}
	return $ok;
}

/**
 * New P-256 key pair.
 *
 * @return array|false [OpenSSLAsymmetricKey, raw 65-byte public point]
 */
function meeha_push_new_key() {
	$k = @openssl_pkey_new( array( 'curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	if ( ! $k ) {
		return false;
	}
	$d = openssl_pkey_get_details( $k );
	if ( empty( $d['ec']['x'] ) || empty( $d['ec']['y'] ) ) {
		return false;
	}
	return array( $k, "\x04" . str_pad( $d['ec']['x'], 32, "\0", STR_PAD_LEFT ) . str_pad( $d['ec']['y'], 32, "\0", STR_PAD_LEFT ) );
}

/**
 * The site's VAPID keys (made once, kept in a non-autoloaded option).
 *
 * @return array|false ['private' => PEM, 'public' => base64url point]
 */
function meeha_push_keys() {
	$keys = get_option( 'meeha_push_vapid' );
	if ( is_array( $keys ) && ! empty( $keys['private'] ) && ! empty( $keys['public'] ) ) {
		return $keys;
	}
	$pair = meeha_push_new_key();
	if ( ! $pair || ! openssl_pkey_export( $pair[0], $pem ) ) {
		return false;
	}
	$keys = array(
		'private' => $pem,
		'public'  => meeha_push_b64u( $pair[1] ),
	);
	add_option( 'meeha_push_vapid', $keys, '', false );
	return get_option( 'meeha_push_vapid' );
}

/**
 * PEM public key from a raw uncompressed P-256 point.
 *
 * @param string $raw 65 bytes.
 * @return string
 */
function meeha_push_point_pem( $raw ) {
	$der = hex2bin( '3059301306072a8648ce3d020106082a8648ce3d030107034200' ) . $raw;
	return "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $der ), 64, "\n" ) . "-----END PUBLIC KEY-----\n"; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
}

/**
 * DER ECDSA signature → raw R||S (64 bytes) as JWS ES256 wants.
 *
 * @param string $der DER.
 * @return string|false
 */
function meeha_push_der_to_raw( $der ) {
	$pos = 2; /* 0x30 len */
	if ( "\x81" === $der[1] ) {
		$pos = 3;
	}
	$out = '';
	for ( $i = 0; $i < 2; $i++ ) {
		if ( "\x02" !== $der[ $pos ] ) {
			return false;
		}
		$len  = ord( $der[ $pos + 1 ] );
		$int  = substr( $der, $pos + 2, $len );
		$out .= str_pad( ltrim( $int, "\0" ), 32, "\0", STR_PAD_LEFT );
		$pos += 2 + $len;
	}
	return 64 === strlen( $out ) ? $out : false;
}

/**
 * "Authorization: vapid t=…, k=…" for one push service origin (cached per origin for 10 h).
 *
 * @param string $endpoint Endpoint URL.
 * @return string|false
 */
function meeha_push_vapid_header( $endpoint ) {
	static $cache = array();
	$p      = wp_parse_url( $endpoint );
	$origin = 'https://' . $p['host'] . ( empty( $p['port'] ) ? '' : ':' . $p['port'] );
	if ( isset( $cache[ $origin ] ) ) {
		return $cache[ $origin ];
	}
	$keys = meeha_push_keys();
	if ( ! $keys ) {
		return false;
	}
	$sub   = (string) apply_filters( 'meeha_push_subject', 'mailto:' . get_option( 'admin_email' ) );
	$data  = meeha_push_b64u( wp_json_encode( array( 'typ' => 'JWT', 'alg' => 'ES256' ) ) ) . '.' . meeha_push_b64u(
		wp_json_encode(
			array(
				'aud' => $origin,
				'exp' => time() + 12 * HOUR_IN_SECONDS,
				'sub' => $sub,
			),
			JSON_UNESCAPED_SLASHES
		)
	);
	$sig = '';
	if ( ! openssl_sign( $data, $sig, $keys['private'], OPENSSL_ALGO_SHA256 ) ) {
		return false;
	}
	$raw = meeha_push_der_to_raw( $sig );
	if ( ! $raw ) {
		return false;
	}
	$cache[ $origin ] = 'vapid t=' . $data . '.' . meeha_push_b64u( $raw ) . ', k=' . $keys['public'];
	return $cache[ $origin ];
}

/**
 * Encrypt a payload for one subscription (RFC 8291 + RFC 8188 aes128gcm, single record).
 *
 * @param string $payload Plain text (JSON).
 * @param string $p256dh  Browser key (base64url).
 * @param string $auth    Browser auth secret (base64url).
 * @return string|false body
 */
function meeha_push_encrypt( $payload, $p256dh, $auth ) {
	$ua_pub = meeha_push_b64u_dec( $p256dh );
	$secret = meeha_push_b64u_dec( $auth );
	if ( 65 !== strlen( (string) $ua_pub ) || "\x04" !== $ua_pub[0] || 16 !== strlen( (string) $secret ) ) {
		return false;
	}
	$pair = meeha_push_new_key();
	if ( ! $pair ) {
		return false;
	}
	list( $as_key, $as_pub ) = $pair;
	$ua_key = openssl_pkey_get_public( meeha_push_point_pem( $ua_pub ) );
	if ( ! $ua_key ) {
		return false;
	}
	$shared = openssl_pkey_derive( $ua_key, $as_key, 32 );
	if ( false === $shared ) {
		return false;
	}
	$shared = str_pad( $shared, 32, "\0", STR_PAD_LEFT );
	$ikm    = hash_hkdf( 'sha256', $shared, 32, "WebPush: info\0" . $ua_pub . $as_pub, $secret );
	$salt   = random_bytes( 16 );
	$cek    = hash_hkdf( 'sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt );
	$nonce  = hash_hkdf( 'sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt );
	$tag    = '';
	$ct     = openssl_encrypt( $payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16 );
	if ( false === $ct ) {
		return false;
	}
	return $salt . pack( 'N', 4096 ) . chr( 65 ) . $as_pub . $ct . $tag;
}

/**
 * Deliver one payload to many subscriptions in parallel.
 *
 * @param array  $subs    Rows (id, endpoint, p256dh, auth).
 * @param string $payload JSON.
 * @param int    $ttl     Seconds the push service keeps it for an offline device.
 * @return array ['sent' => int, 'gone' => int[] ids, 'failed' => int[] ids]
 */
function meeha_push_deliver( $subs, $payload, $ttl = 21600 ) {
	$out  = array(
		'sent'   => 0,
		'gone'   => array(),
		'failed' => array(),
	);
	$reqs = array();
	foreach ( $subs as $s ) {
		$body = meeha_push_encrypt( $payload, $s['p256dh'], $s['auth'] );
		$auth = meeha_push_endpoint_ok( $s['endpoint'] ) ? meeha_push_vapid_header( $s['endpoint'] ) : false;
		if ( false === $body || false === $auth ) {
			$out['gone'][] = (int) $s['id'];
			continue;
		}
		$reqs[ (int) $s['id'] ] = array(
			'url'     => $s['endpoint'],
			'type'    => 'POST',
			'data'    => $body,
			'headers' => array(
				'Authorization'    => $auth,
				'Content-Encoding' => 'aes128gcm',
				'Content-Type'     => 'application/octet-stream',
				'TTL'              => (string) (int) $ttl,
				'Urgency'          => 'high',
			),
		);
	}
	if ( ! $reqs ) {
		return $out;
	}
	$opts  = apply_filters(
		'meeha_push_request_options',
		array(
			'timeout'         => 15,
			'connect_timeout' => 8,
			'verify'          => ABSPATH . WPINC . '/certificates/ca-bundle.crt',
		)
	);
	$class = class_exists( '\WpOrg\Requests\Requests' ) ? '\WpOrg\Requests\Requests' : ( class_exists( 'Requests' ) ? 'Requests' : '' );
	$hooks = class_exists( '\WpOrg\Requests\Hooks' ) ? '\WpOrg\Requests\Hooks' : ( class_exists( 'Requests_Hooks' ) ? 'Requests_Hooks' : '' );
	if ( $hooks && function_exists( 'curl_setopt' ) ) {
		/* request_multiple() skips the `verify` option on cURL handles, so apply it (WordPress's CA bundle) here */
		$verify        = $opts['verify'];
		$opts['hooks'] = new $hooks();
		$opts['hooks']->register(
			'curl.before_request',
			function ( &$handle ) use ( $verify ) {
				if ( false === $verify ) {
					curl_setopt( $handle, CURLOPT_SSL_VERIFYPEER, 0 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
					curl_setopt( $handle, CURLOPT_SSL_VERIFYHOST, 0 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				} elseif ( is_string( $verify ) && is_readable( $verify ) ) {
					curl_setopt( $handle, CURLOPT_CAINFO, $verify ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				}
			}
		);
	}
	if ( $class ) {
		$res = call_user_func( array( $class, 'request_multiple' ), $reqs, $opts );
	} else {
		$res = array();
		foreach ( $reqs as $id => $r ) {
			$w          = wp_remote_post( $r['url'], array( 'headers' => $r['headers'], 'body' => $r['data'], 'timeout' => 15 ) );
			$res[ $id ] = is_wp_error( $w ) ? null : (object) array( 'status_code' => (int) wp_remote_retrieve_response_code( $w ) );
		}
	}
	foreach ( $reqs as $id => $r ) {
		$code = isset( $res[ $id ] ) && is_object( $res[ $id ] ) && isset( $res[ $id ]->status_code ) ? (int) $res[ $id ]->status_code : 0;
		if ( $code >= 200 && $code < 300 ) {
			++$out['sent'];
		} elseif ( in_array( $code, array( 404, 410 ), true ) ) {
			$out['gone'][] = $id; /* the reader unsubscribed or cleared the browser */
		} else {
			$out['failed'][] = $id;
		}
	}
	return $out;
}
