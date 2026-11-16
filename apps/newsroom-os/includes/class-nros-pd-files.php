<?php
/**
 * NROS_PD_Files — 1.15.0: প্রোফাইল ডেস্কের ফাইল আলমারি ও ভল্ট.
 *
 * Every file kept under a profile (documents, pictures, audio, video) and
 * every file in a vault is stored outside the media library, in
 * wp-content/nros-private/profile-desk/, ENCRYPTED on disk (AES-256-CTR, a
 * random key per file, that key wrapped with the site key from wp-config:
 * NROS_ENC_KEY, else AUTH_KEY). A copied uploads folder or a leaked backup
 * of that folder alone is unreadable; someone holding the whole server
 * (files + wp-config) can still read it — that limit is stated in the app.
 *
 * Files are read only through short-lived signed links (GET /pd/stream/{id}?t=…)
 * that support HTTP Range, so a long video plays and seeks without the
 * browser loading the whole file.
 *
 * Vaults: a private vault per person (only its owner sees it) and one
 * shared vault for everyone with desk access. Opening a vault needs the
 * person's PIN *and* a one-time code (the theme's SMS/e-mail OTP), which
 * gives a vault token valid for 15 idle minutes (8 hours at most), sent by
 * the app as the X-NROS-Vault header.
 *
 * Uploads ride the resumable upload sessions (NROS_Upload_Sessions, kind
 * "pd"): see upload_target() and store_upload().
 *
 * @package NewsroomOS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NROS_PD_Files {

	const SCOPES = array( 'entity', 'vault_private', 'vault_public' );

	/** Default biggest file (filter `nros_pd_max_file_bytes`). */
	const MAX_FILE = 2147483648; // 2 GB.

	const CHUNK = 1048576; // 1 MB, a multiple of the AES block.

	const VAULT_IDLE = 900;
	const VAULT_MAX  = 28800;

	const PIN_MAX_FAILS = 5;
	const PIN_LOCK      = 1800;
	const OTP_TTL       = 300;
	const OTP_ATTEMPTS  = 5;

	/** Types never stored (could run in a browser or on a server). */
	const BLOCKED_MIMES = array(
		'text/html',
		'application/xhtml+xml',
		'image/svg+xml',
		'application/x-httpd-php',
		'text/x-php',
		'application/x-php',
		'application/javascript',
		'text/javascript',
		'application/x-msdownload',
		'application/x-dosexec',
		'application/x-executable',
		'application/x-sharedlib',
		'application/x-sh',
		'text/x-shellscript',
		'application/x-mach-binary',
	);

	const BLOCKED_EXT = array( 'php', 'phtml', 'phar', 'php5', 'php7', 'exe', 'js', 'mjs', 'html', 'htm', 'xhtml', 'svg', 'svgz', 'sh', 'bat', 'cmd', 'msi', 'dll', 'com', 'scr', 'jar', 'htaccess' );

	/**
	 * Registers hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Biggest file a desk upload may be.
	 *
	 * @return int
	 */
	public static function max_bytes() {
		return (int) apply_filters( 'nros_pd_max_file_bytes', self::MAX_FILE );
	}

	// ---------------------------------------------------------------------
	// Storage
	// ---------------------------------------------------------------------

	/**
	 * The desk's private folder (created with deny-all guards).
	 *
	 * @return string
	 */
	public static function base_dir() {
		$dir = NROS_PRIVATE_DIR . '/profile-desk';
		if ( ! is_dir( $dir ) ) {
			NROS_Core::create_private_dir();
			wp_mkdir_p( $dir );
		}
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			@file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" );
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			@file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
		}
		// phpcs:enable
		return $dir;
	}

	/**
	 * Absolute path of a stored file name.
	 *
	 * @param string $stored Relative name (yyyy/mm/hex.bin).
	 * @return string
	 */
	protected static function path_of( $stored ) {
		if ( ! preg_match( '#^\d{4}/\d{2}/[a-f0-9]{32}\.bin$#', (string) $stored ) ) {
			return '';
		}
		return self::base_dir() . '/' . $stored;
	}

	/**
	 * Disk use and the "can the folder be opened from outside?" check.
	 *
	 * @return array
	 */
	public static function storage_summary() {
		global $wpdb;
		$tf   = NROS_Profile_Desk::t( 'files' );
		$used = (int) $wpdb->get_var( "SELECT COALESCE(SUM(size), 0) FROM {$tf}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$n    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tf}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$dir  = self::base_dir();
		$free = function_exists( 'disk_free_space' ) ? @disk_free_space( $dir ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$test = get_option( 'nros_pd_exposure', array() );
		return array(
			'used_bytes' => $used,
			'files'      => $n,
			'free_bytes' => false === $free ? null : (int) $free,
			'writable'   => is_writable( $dir ),
			'exposure'   => is_array( $test ) && isset( $test['result'] ) ? $test['result'] : 'unknown',
			'checked_at' => is_array( $test ) && isset( $test['at'] ) ? $test['at'] : null,
		);
	}

	/**
	 * Puts a probe file in the private folder and asks the site for it over
	 * HTTP: "exposed" means the web server (often Nginx, which ignores
	 * .htaccess) hands the folder out and a server rule is needed.
	 *
	 * @return string ok|exposed|unknown
	 */
	public static function exposure_test() {
		$dir   = self::base_dir();
		$token = bin2hex( random_bytes( 12 ) );
		$probe = $dir . '/probe.txt';
		@file_put_contents( $probe, $token ); // phpcs:ignore
		$url    = content_url( 'nros-private/profile-desk/probe.txt' ) . '?' . rawurlencode( $token );
		$res    = wp_remote_get( $url, array( 'timeout' => 8, 'redirection' => 0 ) );
		$result = 'unknown';
		if ( ! is_wp_error( $res ) ) {
			$code   = (int) wp_remote_retrieve_response_code( $res );
			$body   = trim( (string) wp_remote_retrieve_body( $res ) );
			$result = ( 200 === $code && $body === $token ) ? 'exposed' : 'ok';
		}
		wp_delete_file( $probe );
		update_option( 'nros_pd_exposure', array( 'result' => $result, 'at' => current_time( 'mysql' ) ), false );
		return $result;
	}

	// ---------------------------------------------------------------------
	// Encryption (AES-256-CTR, random key per file, seekable)
	// ---------------------------------------------------------------------

	/**
	 * The counter block for byte offset block*16.
	 *
	 * @param string $iv    16-byte IV.
	 * @param int    $block Block index.
	 * @return string
	 */
	protected static function ctr_iv( $iv, $block ) {
		$out   = $iv;
		$carry = (int) $block;
		for ( $i = 15; $i >= 0 && $carry > 0; $i-- ) {
			$sum       = ord( $out[ $i ] ) + ( $carry & 0xff );
			$out[ $i ] = chr( $sum & 0xff );
			$carry     = ( $carry >> 8 ) + ( $sum >> 8 );
		}
		return $out;
	}

	/**
	 * Encrypts $src into $dst. Returns [wrapped key, iv hex, sha256 of plain] or WP_Error.
	 *
	 * @param string $src Plain file.
	 * @param string $dst Encrypted file.
	 * @return array|WP_Error
	 */
	protected static function encrypt_file( $src, $dst ) {
		$key = random_bytes( 32 );
		$iv  = random_bytes( 16 );
		// phpcs:disable WordPress.WP.AlternativeFunctions
		$in  = fopen( $src, 'rb' );
		$out = fopen( $dst, 'wb' );
		if ( ! $in || ! $out ) {
			return new WP_Error( 'nros_upload_failed', 'সার্ভারে ফাইল লেখা যায়নি।', array( 'status' => 500 ) );
		}
		$hash = hash_init( 'sha256' );
		$pos  = 0;
		while ( ! feof( $in ) ) {
			$buf = fread( $in, self::CHUNK );
			if ( false === $buf || '' === $buf ) {
				break;
			}
			hash_update( $hash, $buf );
			$enc = openssl_encrypt( $buf, 'aes-256-ctr', $key, OPENSSL_RAW_DATA, self::ctr_iv( $iv, intdiv( $pos, 16 ) ) );
			if ( false === $enc || fwrite( $out, $enc ) !== strlen( $enc ) ) {
				fclose( $in );
				fclose( $out );
				@unlink( $dst );
				return new WP_Error( 'nros_upload_failed', 'সার্ভারে জায়গা নেই বা লেখা যায়নি।', array( 'status' => 507 ) );
			}
			$pos += strlen( $buf );
		}
		fclose( $in );
		fclose( $out );
		// phpcs:enable
		return array( NROS_Core::encrypt( base64_encode( $key ) ), bin2hex( $iv ), hash_final( $hash ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Key and IV of a file row.
	 *
	 * @param array $f Row.
	 * @return array|null [key, iv]
	 */
	protected static function key_of( $f ) {
		$key = base64_decode( NROS_Core::decrypt( (string) $f['enc_key'] ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$iv  = hex2bin( (string) $f['enc_iv'] );
		if ( false === $key || 32 !== strlen( $key ) || false === $iv || 16 !== strlen( $iv ) ) {
			return null;
		}
		return array( $key, $iv );
	}

	/**
	 * Writes bytes [$from, $to] of a file's plaintext to the output.
	 *
	 * @param array $f    Row.
	 * @param int   $from First byte.
	 * @param int   $to   Last byte.
	 * @return bool
	 */
	protected static function output_range( $f, $from, $to ) {
		$path = self::path_of( $f['disk_name'] );
		$k    = self::key_of( $f );
		if ( ! $path || ! $k ) {
			return false;
		}
		list( $key, $iv ) = $k;
		$fh = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fh ) {
			return false;
		}
		$block = intdiv( $from, 16 );
		$skip  = $from - $block * 16;
		fseek( $fh, $block * 16 );
		$left = $to - $from + 1;
		while ( $left > 0 && ! feof( $fh ) ) {
			$want = min( self::CHUNK, $left + $skip );
			$want = (int) ( ceil( $want / 16 ) * 16 );
			$buf  = fread( $fh, $want ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			if ( false === $buf || '' === $buf ) {
				break;
			}
			$plain = openssl_decrypt( $buf, 'aes-256-ctr', $key, OPENSSL_RAW_DATA, self::ctr_iv( $iv, $block ) );
			$block += intdiv( strlen( $buf ), 16 );
			if ( $skip ) {
				$plain = substr( $plain, $skip );
				$skip  = 0;
			}
			if ( strlen( $plain ) > $left ) {
				$plain = substr( $plain, 0, $left );
			}
			echo $plain; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			$left -= strlen( $plain );
			if ( connection_aborted() ) {
				break;
			}
			flush();
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return true;
	}

	// ---------------------------------------------------------------------
	// Signed links
	// ---------------------------------------------------------------------

	/**
	 * Short-lived link to read one file (inline, or as a download).
	 *
	 * @param int  $file_id File.
	 * @param int  $uid     Viewer.
	 * @param int  $ttl     Seconds.
	 * @param bool $dl      Download instead of showing.
	 * @return string
	 */
	public static function signed_url( $file_id, $uid, $ttl = 7200, $dl = false ) {
		$exp  = time() + (int) $ttl;
		// round the expiry so a list asked twice gives the same URLs (browser cache works)
		$exp  = (int) ( ceil( $exp / 600 ) * 600 );
		$body = (int) $file_id . '.' . (int) $uid . '.' . $exp . '.' . ( $dl ? 1 : 0 );
		$sig  = substr( hash_hmac( 'sha256', $body, wp_salt( 'secure_auth' ) . '|nros-pd' ), 0, 40 );
		return rest_url( NROS_API_NS . '/pd/stream/' . (int) $file_id ) . '?t=' . rawurlencode( $body . '.' . $sig );
	}

	/**
	 * Reads a signed token; [uid, dl] or null.
	 *
	 * @param int    $file_id File.
	 * @param string $t       Token.
	 * @return array|null
	 */
	protected static function check_token( $file_id, $t ) {
		$parts = explode( '.', (string) $t );
		if ( 5 !== count( $parts ) ) {
			return null;
		}
		list( $fid, $uid, $exp, $dl, $sig ) = $parts;
		$body = $fid . '.' . $uid . '.' . $exp . '.' . $dl;
		$want = substr( hash_hmac( 'sha256', $body, wp_salt( 'secure_auth' ) . '|nros-pd' ), 0, 40 );
		if ( ! hash_equals( $want, (string) $sig ) || (int) $fid !== (int) $file_id || (int) $exp < time() ) {
			return null;
		}
		return array( (int) $uid, '1' === $dl );
	}

	// ---------------------------------------------------------------------
	// Vault: PIN + OTP → vault token
	// ---------------------------------------------------------------------

	/**
	 * Vault token from the request (header X-NROS-Vault, or vault_token param).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string
	 */
	protected static function token_from( $request ) {
		$t = (string) $request->get_header( 'x_nros_vault' );
		if ( '' === $t ) {
			$t = (string) $request->get_param( 'vault_token' );
		}
		return preg_match( '/^[a-f0-9]{64}$/', $t ) ? $t : '';
	}

	/**
	 * Whether the request carries a live vault token of this user (and keeps it alive).
	 *
	 * @param WP_REST_Request $request Request.
	 * @param WP_User         $user    User.
	 * @return bool
	 */
	public static function vault_open( $request, $user ) {
		$t = self::token_from( $request );
		if ( '' === $t || ! ( $user instanceof WP_User ) ) {
			return false;
		}
		$key = 'nros_pd_vt_' . hash( 'sha256', $t );
		$v   = get_transient( $key );
		if ( ! is_array( $v ) || (int) $v['user'] !== (int) $user->ID || time() - (int) $v['created'] > self::VAULT_MAX ) {
			return false;
		}
		if ( time() - (int) $v['last'] > 60 ) {
			$v['last'] = time();
			set_transient( $key, $v, self::VAULT_IDLE );
		}
		return true;
	}

	/**
	 * Vault state for /pd/meta and /pd/vault.
	 *
	 * @param WP_User         $user    User.
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	public static function vault_state( $user, $request ) {
		$lock = (int) get_user_meta( $user->ID, 'nros_pd_pin_lock', true );
		return array(
			'has_pin'     => '' !== (string) get_user_meta( $user->ID, 'nros_pd_pin', true ),
			'open'        => self::vault_open( $request, $user ),
			'locked_for'  => $lock > time() ? $lock - time() : 0,
			'idle_minutes' => (int) ( self::VAULT_IDLE / 60 ),
		);
	}

	/**
	 * Ends every vault token of a user (access removed, or "সব ডিভাইসে লক").
	 *
	 * @param int $uid User.
	 */
	public static function lock_all_for( $uid ) {
		foreach ( (array) get_user_meta( $uid, 'nros_pd_vts', true ) as $hash ) {
			if ( is_string( $hash ) ) {
				delete_transient( 'nros_pd_vt_' . $hash );
			}
		}
		delete_user_meta( $uid, 'nros_pd_vts' );
	}

	/**
	 * Digits from a PIN/code typed in Bengali or English.
	 *
	 * @param string $s Input.
	 * @return string
	 */
	protected static function digits( $s ) {
		return preg_replace( '/\D/', '', strtr( (string) $s, array( '০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9' ) ) );
	}

	/**
	 * Sends a one-time code for a vault step: the theme's OTP sender (SMS or
	 * e-mail, by the person's own 2FA setting) when present, otherwise an
	 * e-mail from here. The code is kept under the desk's own key so it can
	 * never finish a site login.
	 *
	 * @param WP_User $user    User.
	 * @param string  $purpose unlock|pin.
	 * @return array|WP_Error {token, destination, method}
	 */
	protected static function send_otp( $user, $purpose ) {
		$rate = 'nros_pd_otp_rate_' . $user->ID;
		$sent = (int) get_transient( $rate );
		if ( $sent >= 6 ) {
			return new WP_Error( 'nros_otp_rate', 'অনেকবার কোড চাওয়া হয়েছে। ১৫ মিনিট পর আবার চেষ্টা করুন।', array( 'status' => 429 ) );
		}
		$data = null;
		$out  = null;
		if ( function_exists( 'meeha_otp_start' ) && function_exists( 'meeha_get_security_settings' ) ) {
			$started = meeha_otp_start( $user );
			if ( ! is_wp_error( $started ) ) {
				$theme = get_transient( 'meeha_otp_' . $started['token'] );
				delete_transient( 'meeha_otp_' . $started['token'] );
				if ( is_array( $theme ) && ! empty( $theme['hash'] ) ) {
					$data = array( 'hash' => (string) $theme['hash'], 'theme' => 1 );
					$out  = array(
						'method'      => 'sms' === $started['method'] ? 'sms' : 'email',
						'destination' => (string) $started['masked'],
					);
				}
			}
		}
		if ( ! $data ) {
			$code = (string) random_int( 100000, 999999 );
			$ok   = wp_mail(
				$user->user_email,
				'প্রোফাইল ডেস্ক ভল্টের কোড — ' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				"আপনার ভল্ট খোলার কোড: {$code}\n\nএটি ৫ মিনিট পর্যন্ত বৈধ। আপনি চেষ্টা না করে থাকলে এখনই পাসওয়ার্ড বদলান এবং পাবলিশারকে জানান।"
			);
			if ( ! $ok ) {
				return new WP_Error( 'nros_otp_send_failed', 'কোড পাঠানো যায়নি। সাইটের ইমেইল/এসএমএস সেটিংস দেখুন।', array( 'status' => 503 ) );
			}
			$data  = array( 'hash' => hash_hmac( 'sha256', $code, wp_salt( 'auth' ) . '|nros-pd-otp' ), 'theme' => 0 );
			$parts = explode( '@', $user->user_email );
			$out   = array(
				'method'      => 'email',
				'destination' => substr( $parts[0], 0, 2 ) . '***@' . ( isset( $parts[1] ) ? $parts[1] : '' ),
			);
		}
		$token = bin2hex( random_bytes( 16 ) );
		set_transient(
			'nros_pd_otp_' . $token,
			array_merge(
				$data,
				array(
					'user'     => (int) $user->ID,
					'purpose'  => $purpose,
					'attempts' => 0,
					'exp'      => time() + self::OTP_TTL,
				)
			),
			self::OTP_TTL
		);
		set_transient( $rate, $sent + 1, 15 * MINUTE_IN_SECONDS );
		return array_merge( $out, array( 'otp_token' => $token, 'expires_in' => self::OTP_TTL ) );
	}

	/**
	 * Checks a code against a desk OTP token.
	 *
	 * @param WP_User $user    User.
	 * @param string  $token   Token.
	 * @param string  $code    Code.
	 * @param string  $purpose Expected purpose.
	 * @return true|WP_Error
	 */
	protected static function check_otp( $user, $token, $code, $purpose ) {
		$expired = new WP_Error( 'nros_otp_expired', 'কোডের মেয়াদ শেষ। আবার শুরু করুন।', array( 'status' => 401, 'restart' => true ) );
		if ( ! preg_match( '/^[a-f0-9]{32}$/', (string) $token ) ) {
			return $expired;
		}
		$key = 'nros_pd_otp_' . $token;
		$d   = get_transient( $key );
		if ( ! is_array( $d ) || (int) $d['user'] !== (int) $user->ID || $d['purpose'] !== $purpose || time() >= (int) $d['exp'] ) {
			return $expired;
		}
		$code = self::digits( $code );
		$hash = ! empty( $d['theme'] ) && function_exists( 'meeha_otp_hash' ) ? meeha_otp_hash( $code ) : hash_hmac( 'sha256', $code, wp_salt( 'auth' ) . '|nros-pd-otp' );
		if ( '' === $code || ! hash_equals( (string) $d['hash'], $hash ) ) {
			$d['attempts'] = (int) $d['attempts'] + 1;
			if ( $d['attempts'] >= self::OTP_ATTEMPTS ) {
				delete_transient( $key );
				NROS_Profile_Desk::audit( $user->ID, 'vault_otp_fail', 'user', $user->ID );
				return new WP_Error( 'nros_otp_locked', 'অনেকবার ভুল কোড। আবার শুরু করুন।', array( 'status' => 429, 'restart' => true ) );
			}
			set_transient( $key, $d, max( 1, (int) $d['exp'] - time() ) );
			return new WP_Error( 'nros_otp_invalid', 'কোড সঠিক নয়। (আর ' . NROS_Core::bn_digits( self::OTP_ATTEMPTS - $d['attempts'] ) . ' বার)', array( 'status' => 401 ) );
		}
		delete_transient( $key );
		return true;
	}

	/**
	 * Issues a vault token.
	 *
	 * @param WP_User $user User.
	 * @return string
	 */
	protected static function issue_vault_token( $user ) {
		$t    = bin2hex( random_bytes( 32 ) );
		$hash = hash( 'sha256', $t );
		set_transient(
			'nros_pd_vt_' . $hash,
			array(
				'user'    => (int) $user->ID,
				'created' => time(),
				'last'    => time(),
			),
			self::VAULT_IDLE
		);
		$list   = array_filter( (array) get_user_meta( $user->ID, 'nros_pd_vts', true ), 'is_string' );
		$list[] = $hash;
		update_user_meta( $user->ID, 'nros_pd_vts', array_slice( array_values( $list ), -20 ) );
		return $t;
	}

	/**
	 * POST /pd/vault/unlock — { pin } → checks the PIN, then sends the code.
	 * POST /pd/vault/pin/start — sends a code for setting/resetting the PIN.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function vault_unlock( $request ) {
		$user = NROS_Profile_Desk::me( $request );
		$lock = (int) get_user_meta( $user->ID, 'nros_pd_pin_lock', true );
		if ( $lock > time() ) {
			return new WP_Error( 'nros_pd_pin_locked', 'অনেকবার ভুল পিন। ' . NROS_Core::bn_digits( (int) ceil( ( $lock - time() ) / 60 ) ) . ' মিনিট পর আবার চেষ্টা করুন।', array( 'status' => 429 ) );
		}
		$stored = (string) get_user_meta( $user->ID, 'nros_pd_pin', true );
		if ( '' === $stored ) {
			return new WP_Error( 'nros_pd_no_pin', 'আগে একটা পিন সেট করুন।', array( 'status' => 400 ) );
		}
		$pin = self::digits( (string) $request->get_param( 'pin' ) );
		if ( '' === $pin || ! wp_check_password( $pin, $stored, $user->ID ) ) {
			$fails = (int) get_user_meta( $user->ID, 'nros_pd_pin_fails', true ) + 1;
			if ( $fails >= self::PIN_MAX_FAILS ) {
				update_user_meta( $user->ID, 'nros_pd_pin_lock', time() + self::PIN_LOCK );
				delete_user_meta( $user->ID, 'nros_pd_pin_fails' );
				NROS_Profile_Desk::audit( $user->ID, 'vault_pin_locked', 'user', $user->ID );
				return new WP_Error( 'nros_pd_pin_locked', 'অনেকবার ভুল পিন। ৩০ মিনিট পর আবার চেষ্টা করুন।', array( 'status' => 429 ) );
			}
			update_user_meta( $user->ID, 'nros_pd_pin_fails', $fails );
			NROS_Profile_Desk::audit( $user->ID, 'vault_pin_fail', 'user', $user->ID );
			return new WP_Error( 'nros_pd_pin_wrong', 'পিন সঠিক নয়। (আর ' . NROS_Core::bn_digits( self::PIN_MAX_FAILS - $fails ) . ' বার)', array( 'status' => 401 ) );
		}
		delete_user_meta( $user->ID, 'nros_pd_pin_fails' );
		$sent = self::send_otp( $user, 'unlock' );
		return is_wp_error( $sent ) ? $sent : new WP_REST_Response( $sent, 200 );
	}

	/**
	 * POST /pd/vault/pin/start.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function vault_pin_start( $request ) {
		$user = NROS_Profile_Desk::me( $request );
		$sent = self::send_otp( $user, 'pin' );
		return is_wp_error( $sent ) ? $sent : new WP_REST_Response( $sent, 200 );
	}

	/**
	 * POST /pd/vault/verify — { otp_token, code, purpose: unlock|pin, new_pin? } → { vault_token }.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function vault_verify( $request ) {
		$user    = NROS_Profile_Desk::me( $request );
		$purpose = 'pin' === $request->get_param( 'purpose' ) ? 'pin' : 'unlock';
		$new_pin = self::digits( (string) $request->get_param( 'new_pin' ) );
		if ( 'pin' === $purpose && ( strlen( $new_pin ) < 4 || strlen( $new_pin ) > 8 ) ) {
			return new WP_Error( 'nros_bad_request', 'পিন ৪ থেকে ৮ সংখ্যার হতে হবে।', array( 'status' => 400 ) );
		}
		if ( 'pin' === $purpose && preg_match( '/^(\d)\1+$/', $new_pin ) ) {
			return new WP_Error( 'nros_bad_request', 'একই সংখ্যা বারবার (যেমন ১১১১) পিন হিসেবে চলবে না।', array( 'status' => 400 ) );
		}
		$ok = self::check_otp( $user, (string) $request->get_param( 'otp_token' ), (string) $request->get_param( 'code' ), $purpose );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		if ( 'pin' === $purpose ) {
			update_user_meta( $user->ID, 'nros_pd_pin', wp_hash_password( $new_pin ) );
			delete_user_meta( $user->ID, 'nros_pd_pin_fails' );
			delete_user_meta( $user->ID, 'nros_pd_pin_lock' );
			self::lock_all_for( $user->ID );
			NROS_Profile_Desk::audit( $user->ID, 'vault_pin_set', 'user', $user->ID );
		} else {
			NROS_Profile_Desk::audit( $user->ID, 'vault_unlock', 'user', $user->ID );
		}
		return new WP_REST_Response(
			array(
				'vault_token'  => self::issue_vault_token( $user ),
				'idle_seconds' => self::VAULT_IDLE,
			),
			200
		);
	}

	/**
	 * POST /pd/vault/lock — { all?: bool }.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function vault_lock( $request ) {
		$user = NROS_Profile_Desk::me( $request );
		if ( $request->get_param( 'all' ) ) {
			self::lock_all_for( $user->ID );
		} else {
			$t = self::token_from( $request );
			if ( $t ) {
				delete_transient( 'nros_pd_vt_' . hash( 'sha256', $t ) );
			}
		}
		return new WP_REST_Response( array( 'open' => false ), 200 );
	}

	// ---------------------------------------------------------------------
	// Folders and files
	// ---------------------------------------------------------------------

	/**
	 * Whether a folder may be used by this request (scope rules + vault).
	 *
	 * @param array           $folder  Row.
	 * @param WP_User         $user    User.
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	protected static function folder_ok( $folder, $user, $request ) {
		if ( ! $folder ) {
			return false;
		}
		if ( 'entity' === $folder['scope'] ) {
			return (bool) NROS_Profile_Desk::entity_row( (int) $folder['entity_id'], $user );
		}
		if ( ! self::vault_open( $request, $user ) ) {
			return false;
		}
		return 'vault_public' === $folder['scope'] || (int) $folder['owner_id'] === (int) $user->ID;
	}

	/**
	 * Whether a file may be seen by a user (no vault check: signed links are
	 * only handed out while the vault is open).
	 *
	 * @param array   $f    Row.
	 * @param WP_User $user User.
	 * @return bool
	 */
	protected static function file_visible( $f, $user ) {
		if ( ! $f || ! NROS_Profile_Desk::can_access( $user ) ) {
			return false;
		}
		if ( 'entity' === $f['scope'] ) {
			return (bool) NROS_Profile_Desk::entity_row( (int) $f['entity_id'], $user );
		}
		return 'vault_public' === $f['scope'] || (int) $f['owner_id'] === (int) $user->ID;
	}

	/**
	 * A file row this request may use (vault files need an open vault).
	 *
	 * @param int             $id      File.
	 * @param WP_User         $user    User.
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	protected static function file_row( $id, $user, $request ) {
		global $wpdb;
		$f = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NROS_Profile_Desk::t( 'files' ) . ' WHERE id = %d', (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $f || ! self::file_visible( $f, $user ) ) {
			return new WP_Error( 'nros_not_found', 'ফাইল পাওয়া যায়নি।', array( 'status' => 404 ) );
		}
		if ( 'entity' !== $f['scope'] && ! self::vault_open( $request, $user ) ) {
			return new WP_Error( 'nros_pd_vault_closed', 'ভল্ট বন্ধ — পিন ও কোড দিয়ে খুলুন।', array( 'status' => 423 ) );
		}
		return $f;
	}

	/**
	 * Whether a file is one of this entity's own (used for the profile photo).
	 *
	 * @param int $file_id File.
	 * @param int $entity  Entity.
	 * @return bool
	 */
	public static function file_belongs( $file_id, $entity ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . NROS_Profile_Desk::t( 'files' ) . " WHERE id = %d AND entity_id = %d AND scope = 'entity' AND kind = 'image'", (int) $file_id, (int) $entity ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Shapes a file row.
	 *
	 * @param array $f     Row.
	 * @param int   $uid   Viewer.
	 * @param array $names Entity names by id.
	 * @return array
	 */
	protected static function shape( $f, $uid, $names = array() ) {
		$ttl = 'entity' === $f['scope'] ? 2 * HOUR_IN_SECONDS : 10 * MINUTE_IN_SECONDS;
		return array(
			'id'           => (int) $f['id'],
			'scope'        => $f['scope'],
			'folder_id'    => (int) $f['folder_id'],
			'entity_id'    => (int) $f['entity_id'],
			'entity'       => isset( $names[ (int) $f['entity_id'] ] ) ? $names[ (int) $f['entity_id'] ] : null,
			'name'         => $f['name'],
			'ext'          => $f['ext'],
			'mime'         => $f['mime'],
			'kind'         => $f['kind'],
			'size'         => (int) $f['size'],
			'note'         => (string) $f['note'],
			'source'       => $f['source'],
			'collected_on' => $f['collected_on'],
			'created_at'   => $f['created_at'],
			'owner'        => $f['owner_id'] ? get_the_author_meta( 'display_name', (int) $f['owner_id'] ) : '',
			'owner_id'     => (int) $f['owner_id'],
			'url'          => self::signed_url( (int) $f['id'], $uid, $ttl ),
			'download'     => self::signed_url( (int) $f['id'], $uid, $ttl, true ),
		);
	}

	/**
	 * Folders + files of a profile (and its vault files when the vault is open).
	 *
	 * @param int             $entity_id Entity.
	 * @param WP_User         $user      Viewer.
	 * @param WP_REST_Request $request   Request.
	 * @return array
	 */
	public static function entity_files( $entity_id, $user, $request ) {
		global $wpdb;
		$tf      = NROS_Profile_Desk::t( 'files' );
		$tfo     = NROS_Profile_Desk::t( 'folders' );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$folders = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$tfo} WHERE scope = 'entity' AND entity_id = %d ORDER BY name ASC", (int) $entity_id ), ARRAY_A );
		$files   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$tf} WHERE scope = 'entity' AND entity_id = %d ORDER BY created_at DESC LIMIT 1000", (int) $entity_id ), ARRAY_A );
		$vault   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$tf} WHERE scope <> 'entity' AND entity_id = %d AND (scope = 'vault_public' OR owner_id = %d) ORDER BY created_at DESC LIMIT 500", (int) $entity_id, (int) $user->ID ), ARRAY_A );
		// phpcs:enable
		$open = self::vault_open( $request, $user );
		return array(
			'folders' => array_map(
				function ( $f ) {
					return array(
						'id'        => (int) $f['id'],
						'name'      => $f['name'],
						'parent_id' => (int) $f['parent_id'],
					);
				},
				$folders
			),
			'items'   => array_map(
				function ( $f ) use ( $user ) {
					return self::shape( $f, (int) $user->ID );
				},
				$files
			),
			'vault'   => array(
				'count' => count( $vault ),
				'open'  => $open,
				'items' => $open ? array_map(
					function ( $f ) use ( $user ) {
						return self::shape( $f, (int) $user->ID );
					},
					$vault
				) : array(),
			),
		);
	}

	/**
	 * Deletes every entity-scope file of a profile (disk + rows) and untags
	 * vault files.
	 *
	 * @param int $entity_id Entity.
	 */
	public static function delete_entity_files( $entity_id ) {
		global $wpdb;
		$tf = NROS_Profile_Desk::t( 'files' );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT id, disk_name FROM {$tf} WHERE scope = 'entity' AND entity_id = %d", (int) $entity_id ), ARRAY_A ) as $f ) {
			$p = self::path_of( $f['disk_name'] );
			if ( $p && file_exists( $p ) ) {
				wp_delete_file( $p );
			}
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$tf} WHERE scope = 'entity' AND entity_id = %d", (int) $entity_id ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$tf} SET entity_id = 0 WHERE entity_id = %d", (int) $entity_id ) );
		$wpdb->delete( NROS_Profile_Desk::t( 'folders' ), array( 'scope' => 'entity', 'entity_id' => (int) $entity_id ) );
		// phpcs:enable
	}

	/**
	 * Registers REST routes.
	 */
	public static function register_routes() {
		$acc  = array( 'NROS_Profile_Desk', 'require_access' );
		$boss = array( 'NROS_Profile_Desk', 'require_boss' );
		$ns   = NROS_API_NS;

		register_rest_route( $ns, '/pd/vault', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'vault_list' ), 'permission_callback' => $acc ) );
		register_rest_route( $ns, '/pd/vault/unlock', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'vault_unlock' ), 'permission_callback' => $acc ) );
		register_rest_route( $ns, '/pd/vault/pin/start', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'vault_pin_start' ), 'permission_callback' => $acc ) );
		register_rest_route( $ns, '/pd/vault/verify', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'vault_verify' ), 'permission_callback' => $acc ) );
		register_rest_route( $ns, '/pd/vault/lock', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'vault_lock' ), 'permission_callback' => $acc ) );
		register_rest_route( $ns, '/pd/folders', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'create_folder' ), 'permission_callback' => $acc ) );
		register_rest_route(
			$ns,
			'/pd/folders/(?P<id>\d+)',
			array(
				array( 'methods' => 'PATCH', 'callback' => array( __CLASS__, 'update_folder' ), 'permission_callback' => $acc ),
				array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'delete_folder' ), 'permission_callback' => $acc ),
			)
		);
		register_rest_route(
			$ns,
			'/pd/files/(?P<id>\d+)',
			array(
				array( 'methods' => 'PATCH', 'callback' => array( __CLASS__, 'update_file' ), 'permission_callback' => $acc ),
				array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'delete_file' ), 'permission_callback' => $acc ),
			)
		);
		register_rest_route( $ns, '/pd/files/(?P<id>\d+)/download', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'download_link' ), 'permission_callback' => $acc ) );
		// the signed token is the permission (a <video>/<img> can't send the bearer token)
		register_rest_route( $ns, '/pd/stream/(?P<id>\d+)', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'stream' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( $ns, '/pd/storage/test', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'storage_test' ), 'permission_callback' => $boss ) );
	}

	/**
	 * GET /pd/vault?scope=vault_private|vault_public&folder_id= — one vault level.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function vault_list( $request ) {
		global $wpdb;
		$user  = NROS_Profile_Desk::me( $request );
		$state = self::vault_state( $user, $request );
		if ( ! $state['open'] ) {
			return new WP_REST_Response( array( 'state' => $state ), 200 );
		}
		$scope  = 'vault_public' === $request->get_param( 'scope' ) ? 'vault_public' : 'vault_private';
		$parent = max( 0, (int) $request->get_param( 'folder_id' ) );
		$tfo    = NROS_Profile_Desk::t( 'folders' );
		$tf     = NROS_Profile_Desk::t( 'files' );
		$own    = 'vault_private' === $scope ? $wpdb->prepare( ' AND owner_id = %d', (int) $user->ID ) : '';
		$trail  = array();
		if ( $parent ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$f = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tfo} WHERE id = %d AND scope = %s {$own}", $parent, $scope ), ARRAY_A );
			if ( ! $f ) {
				return new WP_Error( 'nros_not_found', 'ফোল্ডার পাওয়া যায়নি।', array( 'status' => 404 ) );
			}
			$cur = $f;
			for ( $i = 0; $cur && $i < 12; $i++ ) {
				array_unshift( $trail, array( 'id' => (int) $cur['id'], 'name' => $cur['name'] ) );
				$cur = (int) $cur['parent_id'] ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tfo} WHERE id = %d", (int) $cur['parent_id'] ), ARRAY_A ) : null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$folders = $wpdb->get_results( $wpdb->prepare( "SELECT f.*, (SELECT COUNT(*) FROM {$tf} x WHERE x.folder_id = f.id) AS n FROM {$tfo} f WHERE f.scope = %s AND f.parent_id = %d {$own} ORDER BY f.name ASC", $scope, $parent ), ARRAY_A );
		$files   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$tf} WHERE scope = %s AND folder_id = %d {$own} ORDER BY created_at DESC LIMIT 1000", $scope, $parent ), ARRAY_A );
		$sizes   = $wpdb->get_results( $wpdb->prepare( "SELECT scope, COUNT(*) n, COALESCE(SUM(size),0) b FROM {$tf} WHERE scope = 'vault_public' OR (scope = 'vault_private' AND owner_id = %d) GROUP BY scope", (int) $user->ID ), ARRAY_A );
		// phpcs:enable
		$names = NROS_Profile_Desk::names_of( wp_list_pluck( $files, 'entity_id' ) );
		$sum   = array();
		foreach ( $sizes as $s ) {
			$sum[ $s['scope'] ] = array( 'files' => (int) $s['n'], 'bytes' => (int) $s['b'] );
		}
		return new WP_REST_Response(
			array(
				'state'   => $state,
				'scope'   => $scope,
				'trail'   => $trail,
				'summary' => $sum,
				'folders' => array_map(
					function ( $f ) {
						return array(
							'id'        => (int) $f['id'],
							'name'      => $f['name'],
							'parent_id' => (int) $f['parent_id'],
							'count'     => (int) $f['n'],
							'owner'     => $f['owner_id'] ? get_the_author_meta( 'display_name', (int) $f['owner_id'] ) : '',
						);
					},
					$folders
				),
				'files'   => array_map(
					function ( $f ) use ( $user, $names ) {
						return self::shape( $f, (int) $user->ID, $names );
					},
					$files
				),
			),
			200
		);
	}

	/**
	 * POST /pd/folders — { scope, entity_id?, parent_id?, name }.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_folder( $request ) {
		global $wpdb;
		$user   = NROS_Profile_Desk::me( $request );
		$scope  = in_array( $request->get_param( 'scope' ), self::SCOPES, true ) ? $request->get_param( 'scope' ) : 'entity';
		$name   = mb_substr( trim( sanitize_text_field( (string) $request->get_param( 'name' ) ) ), 0, 190 );
		$parent = max( 0, (int) $request->get_param( 'parent_id' ) );
		$entity = 'entity' === $scope ? (int) $request->get_param( 'entity_id' ) : 0;
		if ( '' === $name ) {
			return new WP_Error( 'nros_bad_request', 'ফোল্ডারের নাম দিন।', array( 'status' => 400 ) );
		}
		$probe = array( 'scope' => $scope, 'entity_id' => $entity, 'owner_id' => $user->ID );
		if ( ! self::folder_ok( $probe, $user, $request ) ) {
			return new WP_Error( 'entity' === $scope ? 'nros_not_found' : 'nros_pd_vault_closed', 'entity' === $scope ? 'প্রোফাইল পাওয়া যায়নি।' : 'ভল্ট বন্ধ — পিন ও কোড দিয়ে খুলুন।', array( 'status' => 'entity' === $scope ? 404 : 423 ) );
		}
		if ( $parent ) {
			$p = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NROS_Profile_Desk::t( 'folders' ) . ' WHERE id = %d', $parent ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( ! $p || $p['scope'] !== $scope || (int) $p['entity_id'] !== $entity || ! self::folder_ok( $p, $user, $request ) ) {
				return new WP_Error( 'nros_bad_request', 'ভুল ফোল্ডার।', array( 'status' => 400 ) );
			}
		}
		$wpdb->insert(
			NROS_Profile_Desk::t( 'folders' ),
			array(
				'scope'      => $scope,
				'entity_id'  => $entity,
				'owner_id'   => (int) $user->ID,
				'parent_id'  => $parent,
				'name'       => $name,
				'created_at' => current_time( 'mysql' ),
			)
		);
		return new WP_REST_Response( array( 'id' => (int) $wpdb->insert_id, 'name' => $name, 'parent_id' => $parent ), 201 );
	}

	/**
	 * A folder row this request may change, or WP_Error.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	protected static function folder_for_change( $request ) {
		global $wpdb;
		$user = NROS_Profile_Desk::me( $request );
		$f    = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NROS_Profile_Desk::t( 'folders' ) . ' WHERE id = %d', (int) $request->get_param( 'id' ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $f || ! self::folder_ok( $f, $user, $request ) ) {
			return new WP_Error( 'nros_not_found', 'ফোল্ডার পাওয়া যায়নি (অথবা ভল্ট বন্ধ)।', array( 'status' => 404 ) );
		}
		if ( 'vault_public' === $f['scope'] && (int) $f['owner_id'] !== (int) $user->ID && ! NROS_Profile_Desk::is_boss( $user ) ) {
			return new WP_Error( 'nros_forbidden', 'শেয়ার্ড ভল্টের ফোল্ডার শুধু যিনি বানিয়েছেন বা পাবলিশার বদলাতে পারবেন।', array( 'status' => 403 ) );
		}
		return $f;
	}

	/**
	 * PATCH /pd/folders/{id} — { name }.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_folder( $request ) {
		global $wpdb;
		$f = self::folder_for_change( $request );
		if ( is_wp_error( $f ) ) {
			return $f;
		}
		$name = mb_substr( trim( sanitize_text_field( (string) $request->get_param( 'name' ) ) ), 0, 190 );
		if ( '' !== $name ) {
			$wpdb->update( NROS_Profile_Desk::t( 'folders' ), array( 'name' => $name ), array( 'id' => (int) $f['id'] ) );
		}
		return new WP_REST_Response( array( 'id' => (int) $f['id'], 'name' => '' !== $name ? $name : $f['name'] ), 200 );
	}

	/**
	 * DELETE /pd/folders/{id} — the folder, its sub-folders and their files.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_folder( $request ) {
		global $wpdb;
		$f = self::folder_for_change( $request );
		if ( is_wp_error( $f ) ) {
			return $f;
		}
		$tfo   = NROS_Profile_Desk::t( 'folders' );
		$tf    = NROS_Profile_Desk::t( 'files' );
		$ids   = array( (int) $f['id'] );
		$queue = array( (int) $f['id'] );
		while ( $queue && count( $ids ) < 2000 ) {
			$in    = implode( ',', array_map( 'intval', $queue ) );
			$queue = array_map( 'intval', $wpdb->get_col( "SELECT id FROM {$tfo} WHERE parent_id IN ({$in})" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$ids   = array_merge( $ids, $queue );
		}
		$in    = implode( ',', $ids );
		$files = $wpdb->get_results( "SELECT id, disk_name FROM {$tf} WHERE folder_id IN ({$in})", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $files as $row ) {
			$p = self::path_of( $row['disk_name'] );
			if ( $p && file_exists( $p ) ) {
				wp_delete_file( $p );
			}
		}
		$wpdb->query( "DELETE FROM {$tf} WHERE folder_id IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$tfo} WHERE id IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		NROS_Profile_Desk::audit( NROS_Profile_Desk::me( $request )->ID, 'folder_delete', 'folder', (int) $f['id'], $f['name'] . ' (' . count( $files ) . ')' );
		return new WP_REST_Response( array( 'deleted' => true, 'files' => count( $files ) ), 200 );
	}

	/**
	 * PATCH /pd/files/{id} — { name?, note?, source?, collected_on?, folder_id?, entity_id? }.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_file( $request ) {
		global $wpdb;
		$user = NROS_Profile_Desk::me( $request );
		$f    = self::file_row( (int) $request->get_param( 'id' ), $user, $request );
		if ( is_wp_error( $f ) ) {
			return $f;
		}
		$p   = (array) $request->get_json_params();
		$upd = array();
		if ( isset( $p['name'] ) && '' !== trim( (string) $p['name'] ) ) {
			$upd['name'] = mb_substr( sanitize_text_field( (string) $p['name'] ), 0, 255 );
		}
		if ( isset( $p['note'] ) ) {
			$upd['note'] = mb_substr( sanitize_textarea_field( (string) $p['note'] ), 0, 5000 );
		}
		if ( isset( $p['source'] ) ) {
			$upd['source'] = mb_substr( sanitize_text_field( (string) $p['source'] ), 0, 500 );
		}
		if ( array_key_exists( 'collected_on', $p ) ) {
			$d                   = (string) $p['collected_on'];
			$upd['collected_on'] = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ? $d : null;
		}
		if ( isset( $p['folder_id'] ) ) {
			$fid = max( 0, (int) $p['folder_id'] );
			if ( $fid ) {
				$folder = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NROS_Profile_Desk::t( 'folders' ) . ' WHERE id = %d', $fid ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				if ( ! $folder || $folder['scope'] !== $f['scope'] || ( 'entity' === $f['scope'] && (int) $folder['entity_id'] !== (int) $f['entity_id'] ) || ! self::folder_ok( $folder, $user, $request ) ) {
					return new WP_Error( 'nros_bad_request', 'এই ফোল্ডারে সরানো যাবে না।', array( 'status' => 400 ) );
				}
			}
			$upd['folder_id'] = $fid;
		}
		// vault files can be tagged to (or untagged from) a profile
		if ( isset( $p['entity_id'] ) && 'entity' !== $f['scope'] ) {
			$eid              = max( 0, (int) $p['entity_id'] );
			$upd['entity_id'] = $eid && NROS_Profile_Desk::entity_row( $eid, $user ) ? $eid : 0;
		}
		if ( $upd ) {
			$wpdb->update( NROS_Profile_Desk::t( 'files' ), $upd, array( 'id' => (int) $f['id'] ) );
		}
		$f = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NROS_Profile_Desk::t( 'files' ) . ' WHERE id = %d', (int) $f['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return new WP_REST_Response( self::shape( $f, (int) $user->ID, NROS_Profile_Desk::names_of( array( $f['entity_id'] ) ) ), 200 );
	}

	/**
	 * DELETE /pd/files/{id} — the uploader, the Publisher, or (profile files) anyone who can edit the profile.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_file( $request ) {
		global $wpdb;
		$user = NROS_Profile_Desk::me( $request );
		$f    = self::file_row( (int) $request->get_param( 'id' ), $user, $request );
		if ( is_wp_error( $f ) ) {
			return $f;
		}
		if ( 'vault_public' === $f['scope'] && (int) $f['owner_id'] !== (int) $user->ID && ! NROS_Profile_Desk::is_boss( $user ) ) {
			return new WP_Error( 'nros_forbidden', 'শেয়ার্ড ভল্টের ফাইল শুধু যিনি রেখেছেন বা পাবলিশার মুছতে পারবেন।', array( 'status' => 403 ) );
		}
		$p = self::path_of( $f['disk_name'] );
		if ( $p && file_exists( $p ) ) {
			wp_delete_file( $p );
		}
		$wpdb->delete( NROS_Profile_Desk::t( 'files' ), array( 'id' => (int) $f['id'] ) );
		$wpdb->update( NROS_Profile_Desk::t( 'entities' ), array( 'photo_file_id' => 0 ), array( 'photo_file_id' => (int) $f['id'] ) );
		NROS_Profile_Desk::audit( $user->ID, 'file_delete', 'file', (int) $f['id'], $f['name'] );
		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/**
	 * POST /pd/files/{id}/download — a fresh download link (and an audit line).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function download_link( $request ) {
		$user = NROS_Profile_Desk::me( $request );
		$f    = self::file_row( (int) $request->get_param( 'id' ), $user, $request );
		if ( is_wp_error( $f ) ) {
			return $f;
		}
		NROS_Profile_Desk::audit( $user->ID, 'file_download', 'file', (int) $f['id'], $f['name'] );
		return new WP_REST_Response( array( 'url' => self::signed_url( (int) $f['id'], (int) $user->ID, 5 * MINUTE_IN_SECONDS, true ) ), 200 );
	}

	/**
	 * GET /pd/stream/{id}?t=… — decrypts and sends the file (Range supported).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_Error|void
	 */
	public static function stream( $request ) {
		global $wpdb;
		$id  = (int) $request->get_param( 'id' );
		$tok = self::check_token( $id, (string) $request->get_param( 't' ) );
		$bad = new WP_Error( 'nros_forbidden', 'লিংকের মেয়াদ শেষ। পাতাটি রিফ্রেশ করুন।', array( 'status' => 403 ) );
		if ( ! $tok ) {
			return $bad;
		}
		list( $uid, $dl ) = $tok;
		$user             = get_userdata( $uid );
		$f                = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NROS_Profile_Desk::t( 'files' ) . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $user || ! $f || ! self::file_visible( $f, $user ) ) {
			return $bad;
		}
		$path = self::path_of( $f['disk_name'] );
		if ( ! $path || ! file_exists( $path ) ) {
			return new WP_Error( 'nros_not_found', 'ফাইল সার্ভারে নেই।', array( 'status' => 404 ) );
		}
		$size = (int) $f['size'];
		$from = 0;
		$to   = $size - 1;
		$part = false;
		$rng  = isset( $_SERVER['HTTP_RANGE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) ) : '';
		if ( $size > 0 && preg_match( '/^bytes=(\d*)-(\d*)$/', $rng, $m ) ) {
			if ( '' === $m[1] && '' !== $m[2] ) {
				$from = max( 0, $size - (int) $m[2] );
			} elseif ( '' !== $m[1] ) {
				$from = (int) $m[1];
				$to   = '' !== $m[2] ? min( $to, (int) $m[2] ) : $to;
			}
			if ( $from > $to || $from >= $size ) {
				status_header( 416 );
				header( 'Content-Range: bytes */' . $size );
				exit;
			}
			$part = true;
		}
		$inline = ! $dl && ( in_array( $f['kind'], array( 'image', 'video', 'audio' ), true ) || in_array( $f['mime'], array( 'application/pdf', 'text/plain' ), true ) );
		$fname  = $f['name'];
		if ( $f['ext'] && ! preg_match( '/\.' . preg_quote( $f['ext'], '/' ) . '$/i', $fname ) ) {
			$fname .= '.' . $f['ext'];
		}
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		status_header( $part ? 206 : 200 );
		header( 'Content-Type: ' . ( $f['mime'] ? $f['mime'] : 'application/octet-stream' ) );
		header( 'Content-Length: ' . ( $to - $from + 1 ) );
		header( 'Accept-Ranges: bytes' );
		if ( $part ) {
			header( "Content-Range: bytes {$from}-{$to}/{$size}" );
		}
		header( 'Content-Disposition: ' . ( $inline ? 'inline' : 'attachment' ) . "; filename*=UTF-8''" . rawurlencode( $fname ) );
		header( 'X-Content-Type-Options: nosniff' );
		// a sandboxed page can't show a PDF in Chrome, so PDFs get the policy without it
		header( "Content-Security-Policy: default-src 'none'; img-src 'self' data:; media-src 'self'; style-src 'unsafe-inline'" . ( 'application/pdf' === $f['mime'] ? '' : '; sandbox' ) );
		header( 'Cache-Control: private, max-age=3600' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-LiteSpeed-Cache-Control: no-cache' );
		if ( $size > 0 && 'HEAD' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : 'GET' ) ) { // phpcs:ignore
			self::output_range( $f, $from, $to );
		}
		exit;
	}

	/**
	 * POST /pd/storage/test — runs the exposure test now.
	 *
	 * @return WP_REST_Response
	 */
	public static function storage_test() {
		self::exposure_test();
		return new WP_REST_Response( self::storage_summary(), 200 );
	}

	// ---------------------------------------------------------------------
	// Upload-session integration
	// ---------------------------------------------------------------------

	/**
	 * Where a desk upload goes, checked when the session opens.
	 * Params: pd_scope, pd_folder, pd_entity, pd_note, pd_source, (vault) X-NROS-Vault / vault_token.
	 *
	 * @param WP_User         $user    User.
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function upload_target( $user, $request ) {
		global $wpdb;
		if ( ! NROS_Profile_Desk::can_access( $user ) ) {
			return new WP_Error( 'nros_forbidden', 'প্রোফাইল ডেস্কের অ্যাক্সেস নেই।', array( 'status' => 403 ) );
		}
		$scope  = in_array( $request->get_param( 'pd_scope' ), self::SCOPES, true ) ? $request->get_param( 'pd_scope' ) : 'entity';
		$folder = max( 0, (int) $request->get_param( 'pd_folder' ) );
		$entity = max( 0, (int) $request->get_param( 'pd_entity' ) );
		if ( 'entity' === $scope ) {
			if ( ! $entity || ! NROS_Profile_Desk::entity_row( $entity, $user ) ) {
				return new WP_Error( 'nros_not_found', 'প্রোফাইল পাওয়া যায়নি।', array( 'status' => 404 ) );
			}
		} else {
			if ( ! self::vault_open( $request, $user ) ) {
				return new WP_Error( 'nros_pd_vault_closed', 'ভল্ট বন্ধ — পিন ও কোড দিয়ে খুলুন।', array( 'status' => 423 ) );
			}
			if ( $entity && ! NROS_Profile_Desk::entity_row( $entity, $user ) ) {
				$entity = 0;
			}
		}
		if ( $folder ) {
			$f = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NROS_Profile_Desk::t( 'folders' ) . ' WHERE id = %d', $folder ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( ! $f || $f['scope'] !== $scope || ( 'entity' === $scope && (int) $f['entity_id'] !== $entity ) || ! self::folder_ok( $f, $user, $request ) ) {
				return new WP_Error( 'nros_bad_request', 'ভুল ফোল্ডার।', array( 'status' => 400 ) );
			}
		}
		$name = sanitize_file_name( (string) $request->get_param( 'name' ) );
		$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( in_array( $ext, self::BLOCKED_EXT, true ) ) {
			return new WP_Error( 'nros_bad_file_type', 'এই ধরনের ফাইল (.' . $ext . ') রাখা যাবে না।', array( 'status' => 400 ) );
		}
		return array(
			'scope'  => $scope,
			'folder' => $folder,
			'entity' => $entity,
			'note'   => mb_substr( sanitize_textarea_field( (string) $request->get_param( 'pd_note' ) ), 0, 5000 ),
			'source' => mb_substr( sanitize_text_field( (string) $request->get_param( 'pd_source' ) ), 0, 500 ),
		);
	}

	/**
	 * Kind of a file from its type.
	 *
	 * @param string $mime Mime.
	 * @return string
	 */
	protected static function kind_of( $mime ) {
		foreach ( array( 'image', 'video', 'audio' ) as $k ) {
			if ( 0 === strpos( $mime, $k . '/' ) ) {
				return $k;
			}
		}
		if ( preg_match( '#pdf|msword|officedocument|opendocument|rtf|text/|csv|excel|powerpoint#', $mime ) ) {
			return 'doc';
		}
		return 'other';
	}

	/**
	 * Called by NROS_Upload_Sessions when every byte of a desk upload is in:
	 * checks the type, encrypts into the desk folder, removes the plain copy.
	 *
	 * @param string  $path Plain temporary file.
	 * @param array   $s    Session state (with 'pd' target, name, size).
	 * @param WP_User $user Uploader.
	 * @return array|WP_Error Result for the app.
	 */
	public static function store_upload( $path, $s, $user ) {
		global $wpdb;
		if ( ! NROS_Profile_Desk::can_access( $user ) ) {
			wp_delete_file( $path );
			return new WP_Error( 'nros_forbidden', 'প্রোফাইল ডেস্কের অ্যাক্সেস নেই।', array( 'status' => 403 ) );
		}
		$t     = (array) $s['pd'];
		$finfo = finfo_open( FILEINFO_MIME_TYPE );
		$mime  = (string) finfo_file( $finfo, $path );
		finfo_close( $finfo );
		$name = (string) $s['name'];
		$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( in_array( $mime, self::BLOCKED_MIMES, true ) || in_array( $ext, self::BLOCKED_EXT, true ) ) {
			wp_delete_file( $path );
			return new WP_Error( 'nros_bad_file_type', 'এই ধরনের ফাইল রাখা যাবে না (ওয়েবপেজ, স্ক্রিপ্ট বা প্রোগ্রাম)।', array( 'status' => 400 ) );
		}
		// browsers send video/quicktime etc. fine; text files named .csv come in as text/plain
		$kind   = self::kind_of( $mime );
		$sub    = gmdate( 'Y/m' );
		$dir    = self::base_dir() . '/' . $sub;
		wp_mkdir_p( $dir );
		$stored = $sub . '/' . bin2hex( random_bytes( 16 ) ) . '.bin';
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$enc = self::encrypt_file( $path, self::base_dir() . '/' . $stored );
		$size = (int) filesize( $path );
		wp_delete_file( $path );
		if ( is_wp_error( $enc ) ) {
			return $enc;
		}
		list( $wrapped, $iv, $sha ) = $enc;
		$tf   = NROS_Profile_Desk::t( 'files' );
		$dupe = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$tf} WHERE sha256 = %s AND scope = %s AND entity_id = %d LIMIT 1", $sha, $t['scope'], (int) $t['entity'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$base = pathinfo( $name, PATHINFO_FILENAME );
		$wpdb->insert(
			$tf,
			array(
				'scope'      => $t['scope'],
				'folder_id'  => (int) $t['folder'],
				'entity_id'  => (int) $t['entity'],
				'owner_id'   => (int) $user->ID,
				'name'       => mb_substr( '' !== $base && 'blob' !== $base ? $base : 'file-' . gmdate( 'Ymd-His' ), 0, 250 ),
				'ext'        => substr( preg_replace( '/[^a-z0-9]/', '', $ext ), 0, 16 ),
				'mime'       => substr( $mime, 0, 120 ),
				'kind'       => $kind,
				'size'       => $size,
				'disk_name'  => $stored,
				'enc_key'    => $wrapped,
				'enc_iv'     => $iv,
				'sha256'     => $sha,
				'note'       => $t['note'],
				'source'     => $t['source'],
				'created_at' => current_time( 'mysql' ),
			)
		);
		$id = (int) $wpdb->insert_id;
		NROS_Profile_Desk::audit( $user->ID, 'file_upload', 'file', $id, $base . ' → ' . $t['scope'] );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tf} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$shape = self::shape( $row, (int) $user->ID );
		return array(
			'id'           => $id,
			'url'          => $shape['url'],
			'mime'         => $mime,
			'size'         => $size,
			'file'         => $shape,
			'duplicate_of' => $dupe,
		);
	}
}
