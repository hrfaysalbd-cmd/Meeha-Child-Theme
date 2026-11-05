<?php
/**
 * NROS_Upload_Sessions — 1.13.0: resumable uploads for big files (videos,
 * recorded voice) over weak connections.
 *
 * One upload is many small requests: the app opens a session, sends the
 * file in pieces (a few MB each) appended to a temporary file, and asks the
 * server how much it has whenever a piece fails, so a dropped connection
 * costs one piece, never the whole upload. When every byte is there the
 * file is checked (real type from its bytes, size) and moved into the
 * media library exactly like the single-request uploads.
 *
 *   POST /media/upload-session            { kind: video|audio, name, size } → { id, received, chunk_size, max_bytes }
 *   GET  /media/upload-session/{id}       → { received, size, done }
 *   POST /media/upload-session/{id}/chunk?offset=N   raw bytes → { received }
 *   POST /media/upload-session/{id}/finish             → { id, url, mime, size }
 *
 * Because no single request carries the whole file, the size limit is the
 * plugin's own cap (200 MB for video, filter `nros_upload_session_max_bytes`),
 * not PHP's upload_max_filesize.
 *
 * @package NewsroomOS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NROS_Upload_Sessions {

	/** Video ceiling (same as NROS_Media::VIDEO_CAP_BYTES). */
	const VIDEO_MAX = 209715200; // 200MB.

	/** Recorded / uploaded voice. */
	const AUDIO_MAX = 52428800; // 50MB.

	/** Biggest piece the app may send in one request. */
	const CHUNK_MAX = 8388608; // 8MB.

	/** Sessions (and their temp files) older than this are removed. */
	const TTL = DAY_IN_SECONDS;

	/** Seconds a running finish keeps others waiting (then it is taken as dead). */
	const FINISH_LOCK = 600;

	const AUDIO_MIMES = array(
		'audio/mpeg'  => 'mp3',
		'audio/mp4'   => 'm4a',
		'audio/x-m4a' => 'm4a',
		'audio/aac'   => 'aac',
		'audio/x-hx-aac-adts' => 'aac',
		'audio/wav'   => 'wav',
		'audio/wave'  => 'wav',
		'audio/vnd.wave' => 'wav',
		'audio/x-wav' => 'wav',
		'audio/ogg'   => 'ogg',
		'audio/webm'  => 'webm',
		// a voice recorded in the browser (MediaRecorder) is a WebM/MP4 file that finfo often names as video
		'video/webm'  => 'webm',
		'video/mp4'   => 'mp4',
	);

	/**
	 * Registers hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'nros_upload_sessions_cleanup', array( __CLASS__, 'cleanup' ) );
		if ( ! wp_next_scheduled( 'nros_upload_sessions_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'twicedaily', 'nros_upload_sessions_cleanup' );
		}
	}

	/**
	 * Registers REST routes under nros/v1.
	 */
	public static function register_routes() {
		register_rest_route(
			NROS_API_NS,
			'/media/upload-session',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'start' ),
				'permission_callback' => array( 'NROS_Core', 'require_app_user' ),
			)
		);
		register_rest_route(
			NROS_API_NS,
			'/media/upload-session/(?P<id>[a-f0-9]{32})',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'status' ),
				'permission_callback' => array( 'NROS_Core', 'require_app_user' ),
			)
		);
		register_rest_route(
			NROS_API_NS,
			'/media/upload-session/(?P<id>[a-f0-9]{32})/chunk',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'chunk' ),
				'permission_callback' => array( 'NROS_Core', 'require_app_user' ),
			)
		);
		register_rest_route(
			NROS_API_NS,
			'/media/upload-session/(?P<id>[a-f0-9]{32})/finish',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'finish' ),
				'permission_callback' => array( 'NROS_Core', 'require_app_user' ),
			)
		);
	}

	/**
	 * Largest file a session of `kind` may carry.
	 *
	 * @param string $kind video|audio.
	 * @return int
	 */
	public static function max_bytes( $kind ) {
		$max = 'audio' === $kind ? self::AUDIO_MAX : self::VIDEO_MAX;
		return (int) apply_filters( 'nros_upload_session_max_bytes', $max, $kind );
	}

	/**
	 * Piece size the app should use: below PHP's post_max_size, at most 8 MB.
	 *
	 * @return int
	 */
	public static function chunk_size() {
		$post = function_exists( 'wp_convert_hr_to_bytes' ) ? wp_convert_hr_to_bytes( (string) ini_get( 'post_max_size' ) ) : 0;
		$size = self::CHUNK_MAX;
		if ( $post > 0 ) {
			$size = min( $size, max( 262144, $post - 65536 ) );
		}
		return (int) $size;
	}

	/**
	 * Folder that holds the pieces (not web-listable).
	 *
	 * @return string
	 */
	protected static function dir() {
		$up  = wp_upload_dir( null, false );
		$dir = trailingslashit( $up['basedir'] ) . 'nros-upload-sessions';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			@file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
			@file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" );
			// phpcs:enable
		}
		return $dir;
	}

	/**
	 * Temp file of a session.
	 *
	 * @param string $id Session id.
	 * @return string
	 */
	protected static function part_path( $id ) {
		return self::dir() . '/' . $id . '.part';
	}

	/**
	 * Session state, or a REST error when it is missing or someone else's.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	protected static function session( $request ) {
		$id    = (string) $request->get_param( 'id' );
		$state = get_transient( 'nros_upsess_' . $id );
		$user  = NROS_Auth::get_user_from_request( $request );
		if ( ! is_array( $state ) || ! $user || is_wp_error( $user ) || (int) $state['user'] !== (int) $user->ID ) {
			return new WP_Error( 'nros_upload_session_missing', 'আপলোডটি খুঁজে পাওয়া যায়নি — নতুন করে শুরু করুন।', array( 'status' => 404 ) );
		}
		$state['id'] = $id;
		return $state;
	}

	/**
	 * Whether a "finish" for this session is running right now (saving a
	 * big file into the library can outlast the client's wait).
	 *
	 * @param string $id Session id.
	 * @return bool
	 */
	protected static function finishing( $id ) {
		$started = (int) get_option( 'nros_upfin_' . $id, 0 );
		return $started > 0 && $started > time() - self::FINISH_LOCK;
	}

	/**
	 * Bytes on disk for a session.
	 *
	 * @param string $id Session id.
	 * @return int
	 */
	protected static function received( $id ) {
		$path = self::part_path( $id );
		clearstatcache( true, $path );
		return file_exists( $path ) ? (int) filesize( $path ) : 0;
	}

	/**
	 * POST /media/upload-session — opens an upload.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function start( $request ) {
		$user = NROS_Auth::get_user_from_request( $request );
		$kind = 'audio' === $request->get_param( 'kind' ) ? 'audio' : 'video';
		$size = (int) $request->get_param( 'size' );
		$name = sanitize_file_name( (string) $request->get_param( 'name' ) );

		if ( 'audio' === $kind && ! user_can( $user, 'edit_others_posts' ) ) {
			return new WP_Error( 'nros_forbidden', 'এই অ্যাক্সেস নেই।', array( 'status' => 403 ) );
		}
		if ( 'video' === $kind ) {
			$allowed = NROS_Media::check_video_upload_allowed( $user );
			if ( is_wp_error( $allowed ) ) {
				return $allowed;
			}
		}
		$max = self::max_bytes( $kind );
		if ( $size <= 0 ) {
			return new WP_Error( 'nros_bad_request', 'ফাইলের আকার জানা যায়নি।', array( 'status' => 400 ) );
		}
		if ( $size > $max ) {
			return new WP_Error(
				'nros_file_too_large',
				sprintf( 'ফাইলের আকার সর্বোচ্চ %s এমবি হতে পারবে।', NROS_Core::bn_digits( (string) round( $max / MB_IN_BYTES ) ) ),
				array(
					'status'    => 413,
					'max_bytes' => $max,
				)
			);
		}

		self::cleanup();
		$id = bin2hex( random_bytes( 16 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === @file_put_contents( self::part_path( $id ), '' ) ) {
			return new WP_Error( 'nros_upload_failed', 'সার্ভারে অস্থায়ী ফাইল তৈরি করা যায়নি।', array( 'status' => 500 ) );
		}
		set_transient(
			'nros_upsess_' . $id,
			array(
				'user'    => (int) $user->ID,
				'kind'    => $kind,
				'size'    => $size,
				'name'    => '' !== $name ? $name : 'meeha-' . $kind,
				'created' => time(),
				'result'  => null,
			),
			self::TTL
		);

		return new WP_REST_Response(
			array(
				'id'         => $id,
				'received'   => 0,
				'chunk_size' => self::chunk_size(),
				'max_bytes'  => $max,
			),
			201
		);
	}

	/**
	 * GET /media/upload-session/{id} — how much the server already has.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function status( $request ) {
		$s = self::session( $request );
		if ( is_wp_error( $s ) ) {
			return $s;
		}
		$done       = is_array( $s['result'] );
		$processing = ! $done && self::finishing( $s['id'] );
		return new WP_REST_Response(
			array(
				'received'   => $done || $processing ? (int) $s['size'] : self::received( $s['id'] ),
				'size'       => (int) $s['size'],
				'done'       => $done,
				'processing' => $processing,
			),
			200
		);
	}

	/**
	 * POST /media/upload-session/{id}/chunk?offset=N — appends one piece
	 * (the raw request body). A piece for the wrong offset (a retry of one
	 * that did arrive) is refused with 409 and the real count.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function chunk( $request ) {
		$s = self::session( $request );
		if ( is_wp_error( $s ) ) {
			return $s;
		}
		$have   = self::received( $s['id'] );
		$offset = (int) $request->get_param( 'offset' );
		if ( $offset !== $have ) {
			return new WP_Error(
				'nros_upload_offset',
				'আপলোডের অবস্থান মেলেনি।',
				array(
					'status'   => 409,
					'received' => $have,
				)
			);
		}
		$body = $request->get_body();
		$len  = strlen( $body );
		if ( 0 === $len ) {
			return new WP_Error( 'nros_bad_request', 'খালি অংশ।', array( 'status' => 400 ) );
		}
		if ( $len > self::CHUNK_MAX + 1024 || $have + $len > (int) $s['size'] ) {
			return new WP_Error( 'nros_bad_request', 'অংশটি বড় বা ফাইলের আকারের বাইরে।', array( 'status' => 400 ) );
		}
		$fh = fopen( self::part_path( $s['id'] ), 'ab' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fh ) {
			return new WP_Error( 'nros_upload_failed', 'সার্ভারে লেখা যায়নি।', array( 'status' => 500 ) );
		}
		flock( $fh, LOCK_EX );
		clearstatcache( true, self::part_path( $s['id'] ) );
		// a parallel retry may have written meanwhile
		if ( (int) filesize( self::part_path( $s['id'] ) ) !== $offset ) {
			flock( $fh, LOCK_UN );
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return new WP_Error(
				'nros_upload_offset',
				'আপলোডের অবস্থান মেলেনি।',
				array(
					'status'   => 409,
					'received' => self::received( $s['id'] ),
				)
			);
		}
		$written = fwrite( $fh, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fflush( $fh );
		flock( $fh, LOCK_UN );
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		if ( $written !== $len ) {
			// leave the file as it was so the app can resend this piece
			$fh2 = fopen( self::part_path( $s['id'] ), 'r+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			if ( $fh2 ) {
				ftruncate( $fh2, $offset );
				fclose( $fh2 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			}
			return new WP_Error( 'nros_upload_failed', 'সার্ভারে জায়গা নেই বা লেখা যায়নি।', array( 'status' => 507 ) );
		}
		return new WP_REST_Response( array( 'received' => $offset + $len ), 200 );
	}

	/**
	 * POST /media/upload-session/{id}/finish — checks the whole file and
	 * puts it in the media library. Repeating it (the answer was lost on a
	 * weak connection) returns the same attachment.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function finish( $request ) {
		$s = self::session( $request );
		if ( is_wp_error( $s ) ) {
			return $s;
		}
		if ( is_array( $s['result'] ) ) {
			return new WP_REST_Response( $s['result'], 200 );
		}
		// one finish at a time: a second call (the client's retry after a
		// timeout) waits for the first instead of saving the file twice
		$lock = 'nros_upfin_' . $s['id'];
		if ( self::finishing( $s['id'] ) ) {
			return new WP_REST_Response( array( 'processing' => true ), 202 );
		}
		delete_option( $lock );
		if ( ! add_option( $lock, time(), '', 'no' ) ) {
			return new WP_REST_Response( array( 'processing' => true ), 202 );
		}
		$out = self::finish_locked( $request, $s );
		delete_option( $lock );
		return $out;
	}

	/**
	 * The work of finish(), run while holding its lock.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param array           $s       Session state (with id).
	 * @return WP_REST_Response|WP_Error
	 */
	protected static function finish_locked( $request, $s ) {
		$fresh = get_transient( 'nros_upsess_' . $s['id'] );
		if ( is_array( $fresh ) && is_array( $fresh['result'] ) ) {
			return new WP_REST_Response( $fresh['result'], 200 );
		}
		$path = self::part_path( $s['id'] );
		$have = self::received( $s['id'] );
		if ( $have !== (int) $s['size'] ) {
			return new WP_Error(
				'nros_upload_incomplete',
				'ফাইলের সব অংশ এখনও আসেনি।',
				array(
					'status'   => 409,
					'received' => $have,
				)
			);
		}

		$finfo     = finfo_open( FILEINFO_MIME_TYPE );
		$real_mime = finfo_file( $finfo, $path );
		finfo_close( $finfo );
		$map = 'audio' === $s['kind'] ? self::AUDIO_MIMES : NROS_Media::VIDEO_MIMES;
		if ( ! isset( $map[ $real_mime ] ) ) {
			wp_delete_file( $path );
			delete_transient( 'nros_upsess_' . $s['id'] );
			return new WP_Error(
				'nros_bad_file_type',
				'audio' === $s['kind'] ? 'শুধুমাত্র MP3, M4A, WAV, OGG বা WEBM অডিও গ্রহণযোগ্য।' : 'শুধুমাত্র MP4, WEBM অথবা MOV ভিডিও গ্রহণযোগ্য।',
				array( 'status' => 400 )
			);
		}

		$base = sanitize_file_name( pathinfo( (string) $s['name'], PATHINFO_FILENAME ) );
		$base = '' !== $base && 'blob' !== $base ? $base : 'meeha-' . $s['kind'] . '-' . gmdate( 'Ymd-His' );
		$ext  = $map[ $real_mime ];

		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		// WordPress decides the stored type from the extension; m4a/webm audio must be allowed.
		// WordPress only needs the major type (audio/video) to match what the file really is.
		$real_major   = 0 === strpos( (string) $real_mime, 'audio/' ) ? 'audio' : 'video';
		$mimes_filter = function ( $mimes ) use ( $real_major ) {
			$mimes['m4a']  = 'audio/mp4';
			$mimes['webm'] = $real_major . '/webm';
			$mimes['aac']  = 'audio/aac';
			return $mimes;
		};
		add_filter( 'upload_mimes', $mimes_filter );
		$attachment_id = media_handle_sideload(
			array(
				'name'     => $base . '.' . $ext,
				'tmp_name' => $path,
				'size'     => $have,
				'error'    => 0,
			),
			0
		);
		remove_filter( 'upload_mimes', $mimes_filter );

		if ( is_wp_error( $attachment_id ) ) {
			return new WP_Error( 'nros_upload_failed', 'ফাইল সংরক্ষণ করা যায়নি: ' . $attachment_id->get_error_message(), array( 'status' => 500 ) );
		}
		if ( file_exists( $path ) ) {
			wp_delete_file( $path );
		}
		$user = NROS_Auth::get_user_from_request( $request );
		update_post_meta( $attachment_id, '_nros_uploaded_by', (int) $user->ID );
		if ( 'audio' === $s['kind'] ) {
			// a recorded voice saved as WebM/MP4 is still a voice: keep it out of the video library
			$stored = (string) get_post_mime_type( $attachment_id );
			if ( 0 === strpos( $stored, 'video/' ) ) {
				wp_update_post(
					array(
						'ID'             => (int) $attachment_id,
						'post_mime_type' => 'audio/' . substr( $stored, 6 ),
					)
				);
			}
			update_post_meta( $attachment_id, '_nros_voice', 1 );
		}

		$result = array(
			'id'   => (int) $attachment_id,
			'url'  => wp_get_attachment_url( $attachment_id ),
			'mime' => (string) get_post_mime_type( $attachment_id ),
			'size' => $have,
		);
		$s['result'] = $result;
		$id          = $s['id'];
		unset( $s['id'] );
		set_transient( 'nros_upsess_' . $id, $s, HOUR_IN_SECONDS );
		return new WP_REST_Response( $result, 201 );
	}

	/**
	 * Removes pieces older than a day (abandoned uploads).
	 */
	public static function cleanup() {
		$dir = self::dir();
		foreach ( (array) glob( $dir . '/*.part' ) as $file ) {
			if ( is_string( $file ) && filemtime( $file ) < time() - self::TTL ) {
				wp_delete_file( $file );
			}
		}
	}
}
