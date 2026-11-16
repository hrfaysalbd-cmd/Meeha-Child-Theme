<?php
/**
 * NROS_JWT — minimal, dependency-free HS256 JSON Web Token helper.
 *
 * Deliberately hand-rolled (rather than pulling in firebase/php-jwt) since
 * the plugin only needs issue + verify of its own short-lived access tokens
 * and longer-lived refresh tokens; keeping it dependency-free avoids a
 * Composer autoload step inside a plain WordPress plugin.
 *
 * @package NewsroomOS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NROS_JWT {

	/**
	 * Returns the HMAC signing secret, preferring a dedicated wp-config
	 * constant and otherwise deriving one from AUTH_KEY so it still works
	 * (with a warning in the setup-notice) before the constant is defined.
	 *
	 * @return string
	 */
	protected static function secret() {
		if ( defined( 'NROS_JWT_SECRET' ) && NROS_JWT_SECRET ) {
			return NROS_JWT_SECRET;
		}
		return ( defined( 'AUTH_KEY' ) ? AUTH_KEY : 'nros-fallback-secret-change-me' ) . 'nros-jwt';
	}

	/**
	 * Base64url-encodes a string (no padding), per the JWT spec.
	 *
	 * @param string $data Raw bytes.
	 * @return string
	 */
	protected static function b64url_encode( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decodes a base64url string back to raw bytes.
	 *
	 * @param string $data Base64url string.
	 * @return string|false
	 */
	protected static function b64url_decode( $data ) {
		$padded = str_pad( $data, strlen( $data ) % 4 === 0 ? strlen( $data ) : strlen( $data ) + ( 4 - strlen( $data ) % 4 ), '=' );
		return base64_decode( strtr( $padded, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
	}

	/**
	 * Issues a signed JWT for the given claims, adding iat/exp automatically.
	 *
	 * @param array $claims     Payload claims (e.g. ['uid' => 5, 'rid' => '...']).
	 * @param int   $ttl_seconds Seconds until expiry.
	 * @return string
	 */
	public static function issue( array $claims, $ttl_seconds ) {
		$header = array(
			'typ' => 'JWT',
			'alg' => 'HS256',
		);

		$now = time();
		$payload = array_merge(
			$claims,
			array(
				'iat' => $now,
				'exp' => $now + (int) $ttl_seconds,
			)
		);

		$segments = array(
			self::b64url_encode( wp_json_encode( $header ) ),
			self::b64url_encode( wp_json_encode( $payload ) ),
		);

		$signing_input = implode( '.', $segments );
		$signature     = hash_hmac( 'sha256', $signing_input, self::secret(), true );
		$segments[]    = self::b64url_encode( $signature );

		return implode( '.', $segments );
	}

	/**
	 * Verifies a JWT's signature and expiry, returning its decoded payload
	 * on success or a WP_Error on any failure (bad format, bad signature,
	 * expired).
	 *
	 * @param string $token Compact JWT string.
	 * @return array|WP_Error
	 */
	public static function verify( $token ) {
		if ( ! is_string( $token ) || '' === $token ) {
			return new WP_Error( 'nros_jwt_malformed', 'Invalid token.' );
		}

		$parts = explode( '.', $token );
		if ( 3 !== count( $parts ) ) {
			return new WP_Error( 'nros_jwt_malformed', 'Invalid token.' );
		}

		list( $header_b64, $payload_b64, $sig_b64 ) = $parts;

		$signing_input      = $header_b64 . '.' . $payload_b64;
		$expected_signature = hash_hmac( 'sha256', $signing_input, self::secret(), true );
		$provided_signature = self::b64url_decode( $sig_b64 );

		if ( false === $provided_signature || ! hash_equals( $expected_signature, $provided_signature ) ) {
			return new WP_Error( 'nros_jwt_bad_signature', 'Invalid token.' );
		}

		$payload_json = self::b64url_decode( $payload_b64 );
		$payload      = $payload_json ? json_decode( $payload_json, true ) : null;

		if ( ! is_array( $payload ) ) {
			return new WP_Error( 'nros_jwt_malformed', 'Invalid token.' );
		}

		if ( empty( $payload['exp'] ) || time() >= (int) $payload['exp'] ) {
			return new WP_Error( 'nros_jwt_expired', 'Token expired.' );
		}

		return $payload;
	}
}
