<?php
/**
 * Studio: custom photo frames — validated transparent PNGs stored in uploads/meeha-frames/{type}/ (not the media
 * library), indexed in option `meeha_card_frames`.
 *
 * Index row: id, type, size ('1080x1080'|'1080x1350'|'1200x630'), label, file (relative to uploads basedir),
 * url (computed on read), uploaded_by, date, alpha.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

define( 'MEEHA_FRAME_MAX_BYTES', 1048576 ); // 1 MB.

/**
 * All stored frames (url resolved against the current uploads base).
 *
 * @return array
 */
function meeha_studio_frames_all() {
	$rows = get_option( 'meeha_card_frames', array() );
	if ( ! is_array( $rows ) ) {
		return array();
	}
	$up  = wp_upload_dir( null, false );
	$out = array();
	foreach ( $rows as $r ) {
		if ( ! is_array( $r ) || empty( $r['id'] ) || empty( $r['file'] ) ) {
			continue;
		}
		$r['url'] = trailingslashit( $up['baseurl'] ) . str_replace( '\\', '/', $r['file'] );
		$out[]    = $r;
	}
	return array_merge( $out, meeha_studio_readymade_frames() );
}

/**
 * Ready-made transparent PNG frames shipped with the theme (2.0.4): assets/frames/png/{id}-{WxH}.png, in all four
 * sizes. Rows look like uploaded frames (so the studio lists them by type and size) but carry `builtin` and can
 * never be deleted. Switch off: মিহা থিম › ফটোকার্ড › রেডিমেড ফ্রেম.
 *
 * @return array
 */
function meeha_studio_readymade_frames() {
	if ( ! meeha_studio_opt( 'readymade_frames', 1 ) ) {
		return array();
	}
	$news = array( 'breaking' ); // every other type gets the ready-made designs as built-in presets (meeha_studio_deco_presets()).
	$defs = array(
		'news-nil-bracket'     => array( 'নীল কর্নার ব্র্যাকেট', $news ),
		'news-breaking'        => array( 'ব্রেকিং ব্যান্ড', $news ),
		'news-exclusive'       => array( 'এক্সক্লুসিভ ট্যাগ', $news ),
		'news-sorboshesh'      => array( 'সর্বশেষ ট্যাগ', $news ),
		'news-meeha-special'   => array( 'মিহা স্পেশাল', $news ),
		'news-surjo-nil'       => array( 'সূর্য নীল', $news ),
		'news-nokshi-lalsobuj' => array( 'নকশি লাল-সবুজ', $news ),
		'news-bishesh-sonali'  => array( 'বিশেষ সোনালি', $news ),
	);
	$out = array();
	foreach ( $defs as $file => $d ) {
		foreach ( array_keys( meeha_studio_sizes( false ) ) as $size ) {
			if ( ! file_exists( MEEHA_DIR . '/assets/frames/png/' . $file . '-' . $size . '.png' ) ) {
				continue;
			}
			foreach ( $d[1] as $type ) {
				$out[] = array(
					'id'          => 'r' . substr( md5( $file . $type . $size ), 0, 10 ),
					'type'        => $type,
					'size'        => $size,
					'label'       => $d[0],
					'file'        => '',
					'url'         => MEEHA_URI . '/assets/frames/png/' . $file . '-' . $size . '.png',
					'uploaded_by' => 0,
					'date'        => '',
					'alpha'       => 1,
					'builtin'     => 1,
				);
			}
		}
	}
	return $out;
}

/**
 * Frames for one type (and optionally one size).
 *
 * @param string      $type Type.
 * @param string|null $size Size key.
 * @return array
 */
function meeha_studio_frames_for( $type, $size = null ) {
	return array_values(
		array_filter(
			meeha_studio_frames_all(),
			function ( $r ) use ( $type, $size ) {
				return $r['type'] === $type && ( null === $size || $r['size'] === $size );
			}
		)
	);
}

/**
 * Public shape of a frame row for JS.
 *
 * @param array $r Row.
 * @return array
 */
function meeha_studio_frame_public( $r ) {
	$u = get_userdata( (int) $r['uploaded_by'] );
	return array(
		'id'         => (string) $r['id'],
		'type'       => (string) $r['type'],
		'size'       => (string) $r['size'],
		'label'      => (string) $r['label'],
		'url'        => (string) $r['url'],
		'alpha'      => ! empty( $r['alpha'] ),
		'by'         => $u ? $u->display_name : '',
		'date'       => (string) $r['date'],
		'can_delete' => meeha_studio_can_delete_frame( $r ),
		'readymade'  => ! empty( $r['builtin'] ),
	);
}

/**
 * Uploader (still allowed to upload) or a design manager may delete.
 *
 * @param array $r Row.
 * @return bool
 */
function meeha_studio_can_delete_frame( $r ) {
	if ( ! empty( $r['builtin'] ) ) {
		return false; // ready-made frames ship with the theme.
	}
	if ( meeha_studio_can_manage() ) {
		return true;
	}
	return (int) $r['uploaded_by'] === get_current_user_id() && meeha_studio_can_upload_frames();
}

/**
 * Does a PNG carry transparency? (colour type 4/6, or a tRNS chunk for palette/grey/RGB.)
 *
 * @param string $path File.
 * @return bool
 */
function meeha_studio_png_has_alpha( $path ) {
	$fh = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
	if ( ! $fh ) {
		return false;
	}
	$head = fread( $fh, 33 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
	if ( strlen( $head ) < 33 || "\x89PNG\r\n\x1a\n" !== substr( $head, 0, 8 ) ) {
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return false;
	}
	$ctype = ord( $head[25] );
	if ( 4 === $ctype || 6 === $ctype ) {
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return true;
	}
	// Walk chunks until IDAT looking for tRNS.
	fseek( $fh, 8 );
	for ( $i = 0; $i < 64; $i++ ) {
		$hdr = fread( $fh, 8 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		if ( strlen( $hdr ) < 8 ) {
			break;
		}
		$len  = unpack( 'N', substr( $hdr, 0, 4 ) );
		$name = substr( $hdr, 4, 4 );
		if ( 'tRNS' === $name ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return true;
		}
		if ( 'IDAT' === $name || 'IEND' === $name ) {
			break;
		}
		fseek( $fh, $len[1] + 4, SEEK_CUR );
	}
	fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	return false;
}

/**
 * Validate an uploaded frame file (server side). Never trusts the client name/MIME.
 *
 * @param array  $file  $_FILES entry (name, tmp_name, size, error).
 * @param string $size  Size key.
 * @param bool   $is_http_upload Require is_uploaded_file() (false only for CLI tests).
 * @return array|WP_Error [w, h, alpha]
 */
function meeha_studio_validate_frame_file( $file, $size, $is_http_upload = true ) {
	$sizes = meeha_studio_sizes( false );
	if ( ! isset( $sizes[ $size ] ) ) {
		return new WP_Error( 'meeha_frame_size', 'অজানা মাপ।', array( 'status' => 400 ) );
	}
	if ( ! is_array( $file ) || ! isset( $file['tmp_name'], $file['name'] ) ) {
		return new WP_Error( 'meeha_frame_nofile', 'কোনো ফাইল পাওয়া যায়নি।', array( 'status' => 400 ) );
	}
	if ( ! empty( $file['error'] ) ) {
		$msg = in_array( (int) $file['error'], array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) ? 'ফাইলটি ১ এমবি-র বেশি।' : 'আপলোড ব্যর্থ হয়েছে।';
		return new WP_Error( 'meeha_frame_upload', $msg, array( 'status' => 400 ) );
	}
	$tmp = (string) $file['tmp_name'];
	if ( ( $is_http_upload && ! is_uploaded_file( $tmp ) ) || ! is_file( $tmp ) ) {
		return new WP_Error( 'meeha_frame_upload', 'আপলোড ব্যর্থ হয়েছে।', array( 'status' => 400 ) );
	}
	$bytes = filesize( $tmp );
	if ( ! $bytes || $bytes > MEEHA_FRAME_MAX_BYTES ) {
		return new WP_Error( 'meeha_frame_bytes', 'ফাইলটি ১ এমবি-র বেশি। ছোট করে (যেমন TinyPNG দিয়ে) আবার দিন।', array( 'status' => 400 ) );
	}
	$check = wp_check_filetype_and_ext( $tmp, sanitize_file_name( (string) $file['name'] ), array( 'png' => 'image/png' ) );
	if ( empty( $check['ext'] ) || 'png' !== $check['ext'] || 'image/png' !== $check['type'] ) {
		return new WP_Error( 'meeha_frame_type', 'শুধু আসল PNG ফাইল চলবে (নাম বদলানো JPG/অন্য ফাইল নয়)।', array( 'status' => 400 ) );
	}
	$fh  = fopen( $tmp, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
	$sig = $fh ? fread( $fh, 8 ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
	if ( $fh ) {
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}
	$info = @getimagesize( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	if ( "\x89PNG\r\n\x1a\n" !== $sig || ! $info || IMAGETYPE_PNG !== $info[2] ) {
		return new WP_Error( 'meeha_frame_type', 'শুধু আসল PNG ফাইল চলবে (নাম বদলানো JPG/অন্য ফাইল নয়)।', array( 'status' => 400 ) );
	}
	list( $want_w, $want_h ) = array_map( 'intval', explode( 'x', $size ) );
	if ( (int) $info[0] !== $want_w || (int) $info[1] !== $want_h ) {
		return new WP_Error(
			'meeha_frame_dims',
			sprintf( 'ফ্রেমের মাপ ঠিক %s × %s পিক্সেল হতে হবে (এই ফাইলটি %s × %s)।', meeha_studio_bn( $want_w ), meeha_studio_bn( $want_h ), meeha_studio_bn( (int) $info[0] ), meeha_studio_bn( (int) $info[1] ) ),
			array( 'status' => 400 )
		);
	}
	return array(
		'w'     => $want_w,
		'h'     => $want_h,
		'alpha' => meeha_studio_png_has_alpha( $tmp ),
	);
}

/**
 * Bengali digits (A's meeha_bn_digits when present).
 *
 * @param mixed $s Value.
 * @return string
 */
function meeha_studio_bn( $s ) {
	return function_exists( 'meeha_bn_digits' ) ? meeha_bn_digits( (string) $s ) : strtr( (string) $s, array( '0' => '০', '1' => '১', '2' => '২', '3' => '৩', '4' => '৪', '5' => '৫', '6' => '৬', '7' => '৭', '8' => '৮', '9' => '৯' ) );
}

/**
 * Validate + store a frame and index it.
 *
 * @param array  $file           $_FILES entry.
 * @param string $type           Card type.
 * @param string $size           Size key.
 * @param string $label          Label.
 * @param bool   $is_http_upload See validator.
 * @return array|WP_Error Stored row (with url, warning).
 */
function meeha_studio_store_frame( $file, $type, $size, $label, $is_http_upload = true ) {
	if ( ! meeha_studio_can_upload_frames() ) {
		return new WP_Error( 'meeha_frame_forbidden', 'ফ্রেম আপলোডের অনুমতি নেই।', array( 'status' => 403 ) );
	}
	$types = meeha_studio_types();
	$type  = sanitize_key( $type );
	if ( ! isset( $types[ $type ] ) ) {
		return new WP_Error( 'meeha_frame_type', 'অজানা কার্ডের ধরন।', array( 'status' => 400 ) );
	}
	$v = meeha_studio_validate_frame_file( $file, $size, $is_http_upload );
	if ( is_wp_error( $v ) ) {
		return $v;
	}
	$up = wp_upload_dir();
	if ( ! empty( $up['error'] ) ) {
		return new WP_Error( 'meeha_frame_dir', 'আপলোড ফোল্ডারে লেখা যাচ্ছে না।', array( 'status' => 500 ) );
	}
	$rel_dir = 'meeha-frames/' . $type;
	$dir     = trailingslashit( $up['basedir'] ) . $rel_dir;
	if ( ! wp_mkdir_p( $dir ) ) {
		return new WP_Error( 'meeha_frame_dir', 'আপলোড ফোল্ডারে লেখা যাচ্ছে না।', array( 'status' => 500 ) );
	}
	foreach ( array( trailingslashit( $up['basedir'] ) . 'meeha-frames', $dir ) as $d ) {
		if ( ! file_exists( $d . '/index.php' ) ) {
			file_put_contents( $d . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}
	if ( function_exists( 'meeha_legacy_upload_dir' ) ) {
		meeha_legacy_upload_dir( 'meeha-frames' ); // writes the deny-exec .htaccess
	}
	$name = strtolower( wp_generate_password( 24, false, false ) ) . '.png';
	$dest = $dir . '/' . $name;
	$tmp  = (string) $file['tmp_name'];

	// Re-encode through the image editor (drops metadata / trailing payloads, keeps alpha); fall back to a move.
	$saved  = false;
	$editor = wp_get_image_editor( $tmp, array( 'mime_type' => 'image/png' ) );
	if ( ! is_wp_error( $editor ) ) {
		$res   = $editor->save( $dest, 'image/png' );
		$saved = ! is_wp_error( $res ) && file_exists( $dest );
		if ( $saved && ! empty( $res['path'] ) && $res['path'] !== $dest ) {
			$saved = @rename( $res['path'], $dest ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		if ( $saved && $v['alpha'] && ! meeha_studio_png_has_alpha( $dest ) ) {
			wp_delete_file( $dest ); // editor lost the alpha channel → keep the original bytes instead.
			$saved = false;
		}
	}
	if ( ! $saved ) {
		$saved = $is_http_upload ? @move_uploaded_file( $tmp, $dest ) : @copy( $tmp, $dest ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}
	if ( ! $saved ) {
		return new WP_Error( 'meeha_frame_save', 'ফ্রেম সংরক্ষণ করা যায়নি।', array( 'status' => 500 ) );
	}
	// Original bytes kept (alpha fallback): drop anything after the PNG IEND chunk (trailing payloads).
	$bytes = (string) file_get_contents( $dest ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$iend  = strrpos( $bytes, 'IEND' );
	if ( false !== $iend && strlen( $bytes ) > $iend + 8 ) {
		file_put_contents( $dest, substr( $bytes, 0, $iend + 8 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}
	@chmod( $dest, 0644 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

	$label = trim( sanitize_text_field( (string) $label ) );
	$label = '' === $label ? 'নিজের ফ্রেম' : mb_substr( $label, 0, 60 );
	$row   = array(
		'id'          => 'f' . strtolower( wp_generate_password( 10, false, false ) ),
		'type'        => $type,
		'size'        => $size,
		'label'       => $label,
		'file'        => $rel_dir . '/' . $name,
		'uploaded_by' => get_current_user_id(),
		'date'        => current_time( 'mysql' ),
		'alpha'       => $v['alpha'] ? 1 : 0,
	);
	$rows   = get_option( 'meeha_card_frames', array() );
	$rows   = is_array( $rows ) ? $rows : array();
	$rows[] = $row;
	if ( false === get_option( 'meeha_card_frames', false ) ) {
		add_option( 'meeha_card_frames', $rows, '', 'no' );
	} else {
		update_option( 'meeha_card_frames', $rows );
	}
	$row['url']     = trailingslashit( $up['baseurl'] ) . $row['file'];
	$row['warning'] = $v['alpha'] ? '' : 'সতর্কতা: ফ্রেমে কোনো স্বচ্ছ অংশ নেই — এটি ছবিটিকে পুরো ঢেকে দেবে।';
	return $row;
}

/**
 * Delete a frame (index row + file).
 *
 * @param string $id Frame id.
 * @return true|WP_Error
 */
function meeha_studio_delete_frame( $id ) {
	$rows = get_option( 'meeha_card_frames', array() );
	$rows = is_array( $rows ) ? $rows : array();
	foreach ( $rows as $i => $r ) {
		if ( ! is_array( $r ) || ! isset( $r['id'] ) || (string) $r['id'] !== (string) $id ) {
			continue;
		}
		if ( ! meeha_studio_can_delete_frame( $r ) ) {
			return new WP_Error( 'meeha_frame_forbidden', 'এই ফ্রেম মুছে ফেলার অনুমতি নেই।', array( 'status' => 403 ) );
		}
		$up   = wp_upload_dir( null, false );
		$root = realpath( trailingslashit( $up['basedir'] ) . 'meeha-frames' );
		$path = realpath( trailingslashit( $up['basedir'] ) . $r['file'] );
		if ( $root && $path && 0 === strpos( $path, $root . DIRECTORY_SEPARATOR ) && '.png' === substr( $path, -4 ) ) {
			wp_delete_file( $path );
		}
		unset( $rows[ $i ] );
		update_option( 'meeha_card_frames', array_values( $rows ) );
		return true;
	}
	return new WP_Error( 'meeha_frame_missing', 'ফ্রেমটি পাওয়া যায়নি।', array( 'status' => 404 ) );
}
