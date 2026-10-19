<?php
/**
 * Small shared utilities for the legacy port (rate limits, origin checks, uploads, assets).
 * All functions are prefixed `meeha_legacy_` so they never collide with A/C/D/E helpers.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

/* ---------------------------------------------------------------------------
 * Thin wrappers around foundation helpers (A) with safe fallbacks, so this module
 * never fatals if it is loaded without the foundation (CLI tests, partial deploys).
 * ------------------------------------------------------------------------- */

/** Bengali digits. */
function meeha_legacy_bn( $value ) {
	if ( function_exists( 'meeha_bn_digits' ) ) {
		return meeha_bn_digits( $value );
	}
	return strtr( (string) $value, array( '0' => '০', '1' => '১', '2' => '২', '3' => '৩', '4' => '৪', '5' => '৫', '6' => '৬', '7' => '৭', '8' => '৮', '9' => '৯' ) );
}

/** "৫ মিনিট আগে". */
function meeha_legacy_time_ago( $ts ) {
	if ( function_exists( 'meeha_bengali_time_ago' ) ) {
		return meeha_bengali_time_ago( (int) $ts );
	}
	return meeha_legacy_bn( human_time_diff( (int) $ts, time() ) ) . ' আগে';
}

/** Inline sprite icon or ''. */
function meeha_legacy_icon( $name, $class = '' ) {
	if ( function_exists( 'meeha_icon' ) ) {
		$class = trim( preg_replace( '/(^|\s)m-icon(\s|$)/', ' ', (string) $class ) );
		return meeha_icon( $name, $class ? array( 'class' => $class ) : array() );
	}
	return '';
}

/** Kicker label: A's meeha_post_kicker() else topic else first category. */
function meeha_legacy_kicker( $post ) {
	$post = get_post( $post );
	if ( ! $post ) {
		return '';
	}
	if ( function_exists( 'meeha_post_kicker' ) ) {
		return (string) meeha_post_kicker( $post );
	}
	return function_exists( 'meeha_get_topic_label' ) ? meeha_get_topic_label( $post->ID ) : '';
}

/** Story card: A's meeha_get_card() or a minimal m-story fallback. */
function meeha_legacy_card( $post, $args = array() ) {
	$post = get_post( $post );
	if ( ! $post ) {
		return '';
	}
	if ( function_exists( 'meeha_get_card' ) ) {
		return (string) meeha_get_card( $post, $args );
	}
	$img = get_the_post_thumbnail( $post, 'medium_large', array( 'loading' => 'lazy', 'decoding' => 'async', 'alt' => '' ) );
	return '<article class="m-story m-story--' . esc_attr( isset( $args['variant'] ) ? $args['variant'] : 'sm' ) . '">'
		. ( $img ? '<a class="m-story__media m-media" href="' . esc_url( get_permalink( $post ) ) . '" tabindex="-1" aria-hidden="true">' . $img . '</a>' : '' )
		. '<div class="m-story__body"><h3 class="m-story__title"><a href="' . esc_url( get_permalink( $post ) ) . '">' . esc_html( get_the_title( $post ) ) . '</a></h3>'
		. '<p class="m-meta"><span>' . esc_html( meeha_legacy_time_ago( get_post_time( 'U', true, $post ) ) ) . '</span></p></div></article>';
}

/** Enqueue a build bundle (A's pipeline) with a raw-file fallback. */
function meeha_legacy_enqueue( $bundle, $raw_css = array(), $raw_js = array() ) {
	if ( function_exists( 'meeha_enqueue_bundle' ) ) {
		meeha_enqueue_bundle( $bundle ); // A's pipeline: dist files after `php tools/build.php`, sources in dev
		return;
	}
	foreach ( (array) $raw_css as $css ) {
		wp_enqueue_style( 'meeha-' . $bundle . '-' . sanitize_key( basename( $css, '.css' ) ), MEEHA_URI . '/assets/css/' . $css, array(), meeha_legacy_ver( 'assets/css/' . $css ) );
	}
	foreach ( (array) $raw_js as $js ) {
		wp_enqueue_script( 'meeha-' . $bundle . '-' . sanitize_key( basename( $js, '.js' ) ), MEEHA_URI . '/assets/js/' . $js, array(), meeha_legacy_ver( 'assets/js/' . $js ), array( 'in_footer' => true, 'strategy' => 'defer' ) );
	}
}

/** Built asset URL (dist min file when present, else the source file). */
function meeha_legacy_asset_url( $bundle, $type, $source ) {
	$dist = 'assets/dist/' . $bundle . '.min.' . $type;
	if ( is_readable( MEEHA_DIR . '/' . $dist ) ) {
		return MEEHA_URI . '/' . $dist . '?ver=' . meeha_legacy_ver( $dist );
	}
	return MEEHA_URI . '/assets/' . $type . '/' . $source . '?ver=' . meeha_legacy_ver( 'assets/' . $type . '/' . $source );
}

/** Cache-busting version for a theme file. */
function meeha_legacy_ver( $rel ) {
	$path = MEEHA_DIR . '/' . ltrim( $rel, '/' );
	$base = defined( 'MEEHA_VERSION' ) ? MEEHA_VERSION : '2';
	return is_readable( $path ) ? $base . '.' . filemtime( $path ) : $base;
}

/** Print a JSON payload in a non-executable script tag (safe against </script> injection). */
function meeha_legacy_json_script( $id, $data ) {
	echo '<script type="application/json" id="' . esc_attr( $id ) . '">'
		. wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT )
		. '</script>' . "\n";
}

/* ---------------------------------------------------------------------------
 * Request helpers
 * ------------------------------------------------------------------------- */

/**
 * Is an IP inside one of the CIDR ranges? (IPv4 + IPv6, no extensions needed.)
 *
 * @param string   $ip    IP.
 * @param string[] $cidrs CIDR list ("1.2.3.0/24", "2400:cb00::/32", plain IPs allowed).
 * @return bool
 */
function meeha_ip_in_cidrs( $ip, $cidrs ) {
	$bin = @inet_pton( (string) $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	if ( false === $bin ) {
		return false;
	}
	foreach ( (array) $cidrs as $cidr ) {
		$parts = explode( '/', trim( (string) $cidr ), 2 );
		$net   = @inet_pton( $parts[0] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $net || strlen( $net ) !== strlen( $bin ) ) {
			continue;
		}
		$bits  = isset( $parts[1] ) ? max( 0, min( 8 * strlen( $bin ), (int) $parts[1] ) ) : 8 * strlen( $bin );
		$bytes = intdiv( $bits, 8 );
		if ( substr( $bin, 0, $bytes ) !== substr( $net, 0, $bytes ) ) {
			continue;
		}
		$rem = $bits % 8;
		if ( $rem && ( ( ord( $bin[ $bytes ] ) ^ ord( $net[ $bytes ] ) ) & ( 0xFF << ( 8 - $rem ) ) & 0xFF ) ) {
			continue;
		}
		return true;
	}
	return false;
}

/**
 * Proxies whose forwarding headers may be believed: Cloudflare's published ranges, loopback/private
 * networks (a local LiteSpeed/nginx front), plus constant MEEHA_TRUSTED_PROXIES ("cidr,cidr") and
 * filter `meeha_trusted_proxies`.
 *
 * @return string[]
 */
function meeha_trusted_proxies() {
	$list = array(
		// Cloudflare (https://www.cloudflare.com/ips/).
		'173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18',
		'190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
		'104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22', '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32',
		'2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
		// Local reverse proxies.
		'127.0.0.0/8', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '::1/128', 'fc00::/7',
	);
	if ( defined( 'MEEHA_TRUSTED_PROXIES' ) ) {
		$list = array_merge( $list, array_filter( array_map( 'trim', explode( ',', (string) MEEHA_TRUSTED_PROXIES ) ) ) );
	}
	return (array) apply_filters( 'meeha_trusted_proxies', $list );
}

if ( ! function_exists( 'meeha_client_ip' ) ) {
	/**
	 * Client IP (legacy name from inc/security.php). Proxy headers are used only when the admin enabled
	 * "trust_proxy_ip" in নিরাপত্তা ও লগইন AND the connecting address (REMOTE_ADDR) is a trusted proxy;
	 * X-Forwarded-For is read right-to-left and the first hop that is not a trusted proxy wins.
	 */
	function meeha_client_ip() {
		$s  = function_exists( 'meeha_get_security_settings' ) ? meeha_get_security_settings() : array( 'trust_proxy_ip' => false );
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( ! empty( $s['trust_proxy_ip'] ) && meeha_ip_in_cidrs( $ip, meeha_trusted_proxies() ) ) {
			$proxies = meeha_trusted_proxies();
			if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) && filter_var( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ), FILTER_VALIDATE_IP ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				$ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
			} elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
				$hops = array_reverse( array_map( 'trim', explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) ) ) );
				foreach ( $hops as $hop ) {
					if ( ! filter_var( $hop, FILTER_VALIDATE_IP ) ) {
						break;
					}
					$ip = $hop;
					if ( ! meeha_ip_in_cidrs( $hop, $proxies ) ) {
						break;
					}
				}
			}
		}
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
	}
}

/**
 * Network bucket of an IP for dedupe / rate limits: IPv6 → its /64 (one customer's allocation, so a
 * single VPS cannot rotate 2^64 "visitors"); IPv4 → the address itself (CGNAT users share addresses).
 *
 * @param string $ip     IP (default: current client).
 * @param int    $v6bits IPv6 prefix length.
 * @return string
 */
function meeha_ip_bucket( $ip = null, $v6bits = 64 ) {
	$ip  = null === $ip ? meeha_client_ip() : (string) $ip;
	$bin = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	if ( false === $bin || 16 !== strlen( $bin ) ) {
		return $ip;
	}
	if ( 0 === strpos( $bin, str_repeat( "\0", 10 ) . "\xff\xff" ) ) {
		return inet_ntop( substr( $bin, 12 ) ); // IPv4-mapped IPv6
	}
	$bytes = intdiv( $v6bits, 8 );
	return bin2hex( substr( $bin, 0, $bytes ) ) . '::/' . $v6bits;
}

/** Hashed client IP bucket (never store raw IPs). */
function meeha_legacy_ip_hash() {
	return substr( wp_hash( meeha_ip_bucket() . '|meeha-rl' ), 0, 24 );
}

/**
 * Fixed-window rate limiter. Atomic when the metrics log table exists (INSERT IGNORE / conditional
 * UPDATE in inc/metrics/db.php — parallel requests cannot all pass); transient fallback otherwise.
 *
 * @param string $bucket Short bucket name (≤ 16 chars).
 * @param int    $max    Allowed hits per window.
 * @param int    $window Window length in seconds.
 * @param string $id     Identity (defaults to the hashed client IP bucket).
 * @return bool True when this hit is allowed (and counted).
 */
function meeha_legacy_rate_ok( $bucket, $max, $window, $id = '' ) {
	$max = (int) apply_filters( 'meeha_rate_limit_max', (int) $max, $bucket );
	if ( $max <= 0 ) {
		return true;
	}
	$id = '' !== (string) $id ? (string) $id : meeha_legacy_ip_hash();
	if ( function_exists( 'meeha_metrics_rate_ok' ) && function_exists( 'meeha_metrics_ready' ) && meeha_metrics_ready() ) {
		$max_cb = function () use ( $max ) {
			return $max;
		};
		add_filter( 'meeha_metrics_rate_max', $max_cb, 999 );
		$ok = meeha_metrics_rate_ok( 'b' . sanitize_key( $bucket ), $max, $window, substr( wp_hash( $id . '|meeha-rl-id' ), 0, 24 ) );
		remove_filter( 'meeha_metrics_rate_max', $max_cb, 999 );
		return $ok;
	}
	$key  = 'meeha_rl_' . sanitize_key( $bucket ) . '_' . substr( wp_hash( $id ), 0, 16 );
	$now  = time();
	$data = get_transient( $key );
	if ( ! is_array( $data ) || (int) $data['r'] <= $now ) {
		$data = array(
			'c' => 0,
			'r' => $now + (int) $window,
		);
	}
	if ( (int) $data['c'] >= $max ) {
		return false;
	}
	$data['c'] = (int) $data['c'] + 1;
	set_transient( $key, $data, max( 1, (int) $data['r'] - $now ) );
	return true;
}

/**
 * Light CSRF guard for anonymous POST endpoints without nonces: when the browser sends an
 * Origin (or Referer) header, its host must be this site. Requests without either header
 * (curl, old browsers) are allowed — rate limits still apply.
 */
function meeha_legacy_same_origin() {
	$home = wp_parse_url( home_url(), PHP_URL_HOST );
	foreach ( array( 'HTTP_ORIGIN', 'HTTP_REFERER' ) as $h ) {
		if ( ! empty( $_SERVER[ $h ] ) ) {
			$host = wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER[ $h ] ) ), PHP_URL_HOST );
			return $host && strtolower( $host ) === strtolower( (string) $home );
		}
	}
	return true;
}

/** Honeypot field markup (bots fill it, humans never see it). */
function meeha_legacy_honeypot() {
	return '<p class="m-hp" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden"><label>ওয়েবসাইট<input type="text" name="website" value="" tabindex="-1" autocomplete="off"></label></p>';
}

/** True when the honeypot was filled. */
function meeha_legacy_honeypot_tripped( $value ) {
	return '' !== trim( (string) $value );
}

/** Does a post (content or Elementor data) contain one of the shortcodes? */
function meeha_legacy_post_has( $post, $tags ) {
	$post = get_post( $post );
	if ( ! $post ) {
		return false;
	}
	$haystacks = array( (string) $post->post_content );
	$el        = get_post_meta( $post->ID, '_elementor_data', true );
	if ( is_string( $el ) && '' !== $el ) {
		$haystacks[] = $el;
	}
	foreach ( (array) $tags as $tag ) {
		foreach ( $haystacks as $h ) {
			if ( false !== strpos( $h, '[' . $tag ) && preg_match( '/\[' . preg_quote( $tag, '/' ) . '[\s\]\/]/', $h ) ) {
				return true;
			}
		}
	}
	return false;
}

/** Is the current front-end request a singular page containing one of the shortcodes? */
function meeha_legacy_current_has( $tags ) {
	if ( is_admin() || ! is_singular() ) {
		return false;
	}
	$obj = get_queried_object();
	return $obj instanceof WP_Post && meeha_legacy_post_has( $obj, $tags );
}

/** Admin parent menu slug (A's top-level "মিহা থিম"). */
function meeha_legacy_menu_parent() {
	return (string) apply_filters( 'meeha_legacy_menu_parent', 'meeha-theme' );
}

/** Add Cache-Control to a public REST GET response. */
function meeha_legacy_rest_cacheable( $data, $max_age ) {
	$res = rest_ensure_response( $data );
	$res->header( 'Cache-Control', 'public, max-age=' . (int) $max_age );
	return $res;
}

/** Safe redirect back to the referring page (same host) for no-JS form posts. */
function meeha_legacy_redirect_back( $args, $fallback = '', $anchor = '' ) {
	$back = wp_get_referer();
	$back = $back ? $back : ( $fallback ? $fallback : home_url( '/' ) );
	$back = remove_query_arg( array( 'meeha_msg', 'meeha_ok' ), $back );
	$url  = add_query_arg( $args, $back );
	if ( $anchor ) {
		$url = preg_replace( '/#.*$/', '', $url ) . '#' . $anchor;
	}
	wp_safe_redirect( $url );
	exit;
}

/* ---------------------------------------------------------------------------
 * Image upload validation + storage (avatars, report evidence)
 * ------------------------------------------------------------------------- */

/** Allowed image types: IMAGETYPE => [mime, ext]. */
function meeha_legacy_image_types() {
	$types = array(
		IMAGETYPE_JPEG => array( 'image/jpeg', 'jpg' ),
		IMAGETYPE_PNG  => array( 'image/png', 'png' ),
	);
	if ( defined( 'IMAGETYPE_WEBP' ) ) {
		$types[ IMAGETYPE_WEBP ] = array( 'image/webp', 'webp' );
	}
	return $types;
}

/**
 * Validate an uploaded file as a real JPG/PNG/WebP image.
 *
 * The client-supplied name and MIME type are ignored: the type comes from the file's own
 * bytes (getimagesize + finfo), so a PHP script renamed to .jpg is rejected.
 *
 * @param array $file      $_FILES entry (name, tmp_name, size, error).
 * @param int   $max_bytes Size limit.
 * @param bool  $check_uploaded Require is_uploaded_file() (false only for CLI tests).
 * @return array|WP_Error  ['tmp' => path, 'mime' => …, 'ext' => …, 'w' => …, 'h' => …]
 */
function meeha_legacy_validate_image( $file, $max_bytes, $check_uploaded = true ) {
	if ( ! is_array( $file ) || empty( $file['tmp_name'] ) ) {
		return new WP_Error( 'meeha_upload_none', 'কোনো ফাইল পাওয়া যায়নি।' );
	}
	if ( isset( $file['error'] ) && UPLOAD_ERR_OK !== (int) $file['error'] ) {
		$too_big = in_array( (int) $file['error'], array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true );
		return new WP_Error( 'meeha_upload_error', $too_big ? 'ফাইলটি অনেক বড়।' : 'ফাইল আপলোড ব্যর্থ হয়েছে।' );
	}
	$tmp = (string) $file['tmp_name'];
	if ( $check_uploaded && ! is_uploaded_file( $tmp ) ) {
		return new WP_Error( 'meeha_upload_error', 'ফাইল আপলোড ব্যর্থ হয়েছে।' );
	}
	if ( ! is_readable( $tmp ) ) {
		return new WP_Error( 'meeha_upload_error', 'ফাইল আপলোড ব্যর্থ হয়েছে।' );
	}
	$size = (int) filesize( $tmp );
	if ( $size <= 0 || $size > (int) $max_bytes ) {
		return new WP_Error( 'meeha_upload_size', 'ছবির আকার সর্বোচ্চ ' . meeha_legacy_bn( (string) round( $max_bytes / 1048576, 1 ) ) . ' MB হতে পারে।' );
	}
	$info  = @getimagesize( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- invalid images are expected input.
	$types = meeha_legacy_image_types();
	if ( ! $info || empty( $info[2] ) || ! isset( $types[ $info[2] ] ) ) {
		return new WP_Error( 'meeha_upload_type', 'শুধু JPG, PNG বা WebP ছবি দেওয়া যাবে।' );
	}
	list( $mime, $ext ) = $types[ $info[2] ];
	if ( function_exists( 'finfo_open' ) ) {
		$fi   = finfo_open( FILEINFO_MIME_TYPE );
		$real = $fi ? finfo_file( $fi, $tmp ) : '';
		if ( $fi ) {
			finfo_close( $fi );
		}
		if ( $real && $real !== $mime ) {
			return new WP_Error( 'meeha_upload_type', 'শুধু JPG, PNG বা WebP ছবি দেওয়া যাবে।' );
		}
	}
	$w = (int) $info[0];
	$h = (int) $info[1];
	// ≤ 6000 px per side and ≤ 24 MP: a tiny compressed file cannot make GD allocate hundreds of MB.
	if ( $w < 1 || $h < 1 || $w > 6000 || $h > 6000 || $w * $h > 24000000 ) {
		return new WP_Error( 'meeha_upload_dims', 'ছবির মাপ গ্রহণযোগ্য নয়।' );
	}
	return array(
		'tmp'  => $tmp,
		'mime' => $mime,
		'ext'  => $ext,
		'w'    => $w,
		'h'    => $h,
	);
}

/**
 * Directory under uploads, created with an index file and a deny-execution .htaccess.
 *
 * @return array|WP_Error ['dir' => path, 'url' => url]
 */
function meeha_legacy_upload_dir( $sub ) {
	$up = wp_upload_dir( null, false );
	if ( ! empty( $up['error'] ) ) {
		return new WP_Error( 'meeha_upload_dir', 'আপলোড ফোল্ডার পাওয়া যায়নি।' );
	}
	$sub = sanitize_file_name( $sub );
	$dir = trailingslashit( $up['basedir'] ) . $sub;
	if ( ! wp_mkdir_p( $dir ) ) {
		return new WP_Error( 'meeha_upload_dir', 'আপলোড ফোল্ডার তৈরি করা যায়নি।' );
	}
	if ( ! file_exists( $dir . '/index.php' ) ) {
		@file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore
	}
	if ( ! file_exists( $dir . '/.htaccess' ) ) {
		@file_put_contents( // phpcs:ignore
			$dir . '/.htaccess',
			"# Meeha: images only, never execute anything here.\nOptions -ExecCGI -Indexes\n<IfModule mod_php.c>\nphp_flag engine off\n</IfModule>\n<IfModule mod_php7.c>\nphp_flag engine off\n</IfModule>\n<FilesMatch \"(?i)\\.(php[0-9]?|phtml|phar|pl|py|cgi|sh|shtml)$\">\nRequire all denied\n</FilesMatch>\n"
		);
	}
	return array(
		'dir' => $dir,
		'url' => trailingslashit( $up['baseurl'] ) . $sub,
	);
}

/**
 * Re-encode a validated image into $sub with a random file name (strips metadata / any
 * appended payload). Optional resize: [w, h, crop].
 *
 * @return array|WP_Error ['path' => …, 'url' => …]
 */
function meeha_legacy_store_image( $valid, $sub, $resize = null ) {
	$target = meeha_legacy_upload_dir( $sub );
	if ( is_wp_error( $target ) ) {
		return $target;
	}
	$name = bin2hex( random_bytes( 12 ) ) . '.' . $valid['ext'];
	$path = $target['dir'] . '/' . $name;

	$editor = wp_get_image_editor( $valid['tmp'], array( 'mime_type' => $valid['mime'] ) );
	if ( ! is_wp_error( $editor ) ) {
		if ( $resize ) {
			$size = $editor->get_size();
			if ( ! empty( $resize[2] ) && (int) $resize[0] === (int) $resize[1] ) {
				// square crop (avatars): centre square, then scale down to the target (never up)
				$side = min( (int) $size['width'], (int) $size['height'] );
				$dst  = min( $side, (int) $resize[0] );
				$editor->crop( (int) floor( ( $size['width'] - $side ) / 2 ), (int) floor( ( $size['height'] - $side ) / 2 ), $side, $side, $dst, $dst );
			} else {
				$editor->resize( (int) $resize[0], (int) $resize[1], ! empty( $resize[2] ) );
			}
		}
		if ( method_exists( $editor, 'set_quality' ) ) {
			$editor->set_quality( 85 );
		}
		$saved = $editor->save( $path, $valid['mime'] );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		$path = $saved['path'];
	} elseif ( ! @copy( $valid['tmp'], $path ) ) { // phpcs:ignore -- fallback when no image library is available
		return new WP_Error( 'meeha_upload_save', 'ছবি সংরক্ষণ করা যায়নি।' );
	}
	@chmod( $path, 0644 ); // phpcs:ignore
	return array(
		'path' => $path,
		'url'  => $target['url'] . '/' . basename( $path ),
	);
}

/** Map an uploads URL to a local path (only inside uploads basedir). */
function meeha_legacy_upload_url_to_path( $url ) {
	$up = wp_upload_dir( null, false );
	if ( ! empty( $up['error'] ) || ! $url ) {
		return '';
	}
	$base_url = set_url_scheme( trailingslashit( $up['baseurl'] ), 'http' );
	$url      = set_url_scheme( (string) $url, 'http' );
	if ( 0 !== strpos( $url, $base_url ) ) {
		return '';
	}
	$rel  = substr( $url, strlen( $base_url ) );
	$rel  = preg_replace( '/[?#].*$/', '', $rel );
	$path = wp_normalize_path( trailingslashit( $up['basedir'] ) . rawurldecode( $rel ) );
	$base = wp_normalize_path( trailingslashit( $up['basedir'] ) );
	if ( 0 !== strpos( $path, $base ) || false !== strpos( $rel, '..' ) ) {
		return '';
	}
	return $path;
}
