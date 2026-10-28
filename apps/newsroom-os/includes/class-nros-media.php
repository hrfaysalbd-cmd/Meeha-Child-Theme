<?php
/**
 * NROS_Media — article image uploads (correspondent + staff) and the
 * alt-text/caption metadata on an uploaded attachment. Unlike KYC documents
 * (private, non-public), article images belong in the normal public media
 * library since they'll be displayed on published articles.
 *
 * @package NewsroomOS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NROS_Media {

	const MAX_BYTES = 8388608; // 8MB.

	const ALLOWED_MIMES = array(
		'image/jpeg',
		'image/png',
		'image/webp',
	);

	/**
	 * Round 10 — video news uploads. The plugin's own ceiling is 200 MB, but
	 * the effective limit is never above what PHP/WordPress will actually
	 * accept (wp_max_upload_size(), i.e. upload_max_filesize/post_max_size)
	 * — see video_max_bytes().
	 */
	const VIDEO_CAP_BYTES = 209715200; // 200MB.

	/**
	 * Accepted video MIME types (detected from file content, never trusted
	 * from the client) mapped to the extension the stored file gets.
	 *
	 * @var array<string, string>
	 */
	const VIDEO_DAILY_CAP = 20;

	const VIDEO_MIMES = array(
		'video/mp4'       => 'mp4',
		'video/webm'      => 'webm',
		'video/quicktime' => 'mov',
	);

	/**
	 * Registers routes.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Registers REST routes under nros/v1.
	 */
	public static function register_routes() {
		register_rest_route(
			NROS_API_NS,
			'/media',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'upload' ),
				// Both correspondents (their own article images) and staff
				// (their own posts, or inserting images while fully editing
				// a correspondent's article in the Publisher app) upload here.
				'permission_callback' => array( 'NROS_Core', 'require_app_user' ),
			)
		);

		register_rest_route(
			NROS_API_NS,
			'/media/(?P<id>\d+)',
			array(
				'methods'             => 'PATCH',
				'callback'            => array( __CLASS__, 'update_meta' ),
				'permission_callback' => array( 'NROS_Core', 'require_app_user' ),
			)
		);

		// Round 10 — video news file upload + the size limit the apps
		// should check before starting a (possibly long) upload.
		register_rest_route(
			NROS_API_NS,
			'/media/video',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'upload_video' ),
				'permission_callback' => array( 'NROS_Core', 'require_app_user' ),
			)
		);

		register_rest_route(
			NROS_API_NS,
			'/media/video-limits',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'video_limits' ),
				'permission_callback' => array( 'NROS_Core', 'require_app_user' ),
			)
		);

		// Round 9 follow-up 5 — the Editor/Publisher image gallery: recently
		// uploaded article images, so staff re-use an existing image instead
		// of uploading a duplicate. Staff only.
		register_rest_route(
			NROS_API_NS,
			'/admin/media',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_library' ),
				'permission_callback' => array( 'NROS_Core', 'require_staff' ),
			)
		);

		// Profile-picture upload/change (round 5) — any logged-in app user
		// (correspondent, Editor, Publisher, Administrator) manages their
		// own avatar. Deliberately a separate small resource from /media:
		// a profile picture isn't an article image, has no alt/caption, and
		// only ever has one current value per user (replacing, not adding).
		register_rest_route(
			NROS_API_NS,
			'/me/avatar',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'upload_avatar' ),
					'permission_callback' => array( 'NROS_Core', 'require_app_user' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( __CLASS__, 'delete_avatar' ),
					'permission_callback' => array( 'NROS_Core', 'require_app_user' ),
				),
			)
		);
	}

	/**
	 * Resolves a user's avatar URL: a custom-uploaded photo if one was set
	 * via POST /me/avatar, falling back to WordPress's own Gravatar lookup
	 * otherwise — so a user who never uploads a photo sees the exact same
	 * behavior as before this feature existed.
	 *
	 * @param int $user_id User id.
	 * @return string
	 */
	public static function avatar_url( $user_id ) {
		$attachment_id = (int) get_user_meta( $user_id, '_nros_avatar_id', true );

		if ( $attachment_id && wp_attachment_is_image( $attachment_id ) ) {
			$url = wp_get_attachment_image_url( $attachment_id, 'thumbnail' );
			if ( $url ) {
				return $url;
			}
		}

		return get_avatar_url( $user_id, array( 'size' => 128 ) );
	}

	/**
	 * Validates a single $_FILES-shaped entry (size + real MIME, same rules
	 * as article images) and hands it to WordPress's media_handle_upload(),
	 * tagging the resulting attachment with who uploaded it. Shared by
	 * upload() and upload_avatar() so both enforce identical rules through
	 * one code path.
	 *
	 * Public (round 7) so NROS_Ad_Leads can reuse the exact same validation
	 * for a correspondent's visiting-card upload, instead of duplicating
	 * the size/MIME checks for a third time.
	 *
	 * @param array $file    A single $_FILES-shaped entry.
	 * @param int   $user_id Uploading user's id.
	 * @return int|WP_Error Attachment id, or a WP_Error.
	 */
	public static function validate_and_upload( $file, $user_id ) {
		if ( ! empty( $file ) && in_array( (int) $file['error'], array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) ) {
			return new WP_Error( 'nros_file_too_large', 'ছবিটি সার্ভারের আপলোড-সীমার চেয়ে বড়।', array( 'status' => 413 ) );
		}
		if ( ! empty( $file ) && UPLOAD_ERR_PARTIAL === (int) $file['error'] ) {
			return new WP_Error( 'nros_upload_partial', 'ছবির পুরোটা পৌঁছায়নি — সংযোগ কেটে গিয়েছিল। আবার চেষ্টা করুন।', array( 'status' => 400 ) );
		}
		if ( empty( $file ) || UPLOAD_ERR_OK !== $file['error'] ) {
			return new WP_Error( 'nros_missing_file', 'ছবি পাওয়া যায়নি।', array( 'status' => 400 ) );
		}

		if ( $file['size'] > self::MAX_BYTES ) {
			return new WP_Error( 'nros_file_too_large', 'ছবির আকার সর্বোচ্চ ৮ এমবি হতে হবে।', array( 'status' => 400 ) );
		}

		$finfo     = finfo_open( FILEINFO_MIME_TYPE );
		$real_mime = finfo_file( $finfo, $file['tmp_name'] );
		finfo_close( $finfo );

		if ( ! in_array( $real_mime, self::ALLOWED_MIMES, true ) ) {
			return new WP_Error( 'nros_bad_file_type', 'শুধুমাত্র JPG, PNG অথবা WEBP ছবি গ্রহণযোগ্য।', array( 'status' => 400 ) );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		// Round 18: generating the thumbnail sizes of a large phone photo can
		// outlast a low max_execution_time on shared hosting; the PHP fatal
		// then reaches the app as a dropped connection ("সার্ভারের সাথে
		// সংযোগ হয়নি"). Give the image work room (where the host allows).
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 180 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		wp_raise_memory_limit( 'image' );

		// media_handle_upload() reads from the superglobal $_FILES, so the
		// file is faked into it under a fixed key.
		$_FILES['nros_media_upload'] = $file;

		$attachment_id = media_handle_upload( 'nros_media_upload', 0 );

		unset( $_FILES['nros_media_upload'] );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		update_post_meta( $attachment_id, '_nros_uploaded_by', $user_id );

		return $attachment_id;
	}

	/**
	 * Effective maximum video upload size in bytes: the plugin cap, but
	 * never more than the server will accept — otherwise the app would
	 * promise 200 MB and PHP would silently drop anything above, say, 64 MB.
	 *
	 * @return int
	 */
	public static function video_max_bytes() {
		$server = (int) wp_max_upload_size();
		return $server > 0 ? min( self::VIDEO_CAP_BYTES, $server ) : self::VIDEO_CAP_BYTES;
	}

	/**
	 * Bengali "too large" message naming the real limit in MB.
	 *
	 * @return WP_Error
	 */
	protected static function video_too_large_error() {
		$max   = self::video_max_bytes();
		$label = NROS_Core::bn_digits( 0 === $max % MB_IN_BYTES ? (string) intdiv( $max, MB_IN_BYTES ) : number_format( $max / MB_IN_BYTES, 1 ) );
		return new WP_Error(
			'nros_file_too_large',
			sprintf( 'ভিডিওর আকার সর্বোচ্চ %s এমবি হতে পারবে।', $label ),
			array(
				'status'    => 413,
				'max_bytes' => self::video_max_bytes(),
			)
		);
	}

	/**
	 * How many videos a user has uploaded through this API since local
	 * midnight — counted from the attachments themselves (not a separate
	 * counter that could drift).
	 *
	 * @param int $user_id User id.
	 * @return int
	 */
	protected static function videos_uploaded_today( $user_id ) {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_nros_uploaded_by' AND m.meta_value = %s " // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				. "WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE %s AND p.post_date >= %s",
				(string) (int) $user_id,
				'video/%',
				current_time( 'Y-m-d' ) . ' 00:00:00'
			)
		);
	}

	/**
	 * Correspondent-only gates for video uploads: an account or
	 * news-submission suspension blocks uploading too (a suspended
	 * correspondent couldn't submit the video anyway, and video files are
	 * the expensive part), and a per-day cap (filter
	 * `nros_video_daily_upload_cap`, default VIDEO_DAILY_CAP) keeps one
	 * account from filling the disk. Staff are trusted and not capped.
	 *
	 * @param WP_User $user Uploading user.
	 * @return true|WP_Error
	 */
	protected static function video_upload_allowed( $user ) {
		if ( ! in_array( 'correspondent', (array) $user->roles, true ) ) {
			return true;
		}

		$blocked = array_intersect( array( 'account', 'news_submission' ), NROS_Core::active_suspension_types( $user->ID ) );
		if ( $blocked ) {
			return new WP_Error( 'nros_suspended', 'সংবাদ জমাদান সাময়িকভাবে স্থগিত — এখন ভিডিও আপলোড করা যাবে না।', array( 'status' => 403 ) );
		}

		$cap = (int) apply_filters( 'nros_video_daily_upload_cap', self::VIDEO_DAILY_CAP, $user );
		if ( $cap > 0 && self::videos_uploaded_today( $user->ID ) >= $cap ) {
			return new WP_Error(
				'nros_rate_limited',
				sprintf( 'আজকের ভিডিও আপলোডের সীমা (%sটি) পূর্ণ হয়েছে — আগামীকাল আবার চেষ্টা করুন।', NROS_Core::bn_digits( $cap ) ),
				array( 'status' => 429 )
			);
		}

		return true;
	}

	/**
	 * GET /media/video-limits — { max_bytes } so the apps can refuse an
	 * oversized file before uploading it at all (mobile data is costly).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function video_limits( $request ) {
		return new WP_REST_Response(
			array(
				'max_bytes'     => self::video_max_bytes(),
				'allowed_mimes' => array_keys( self::VIDEO_MIMES ),
			),
			200
		);
	}

	/**
	 * POST /media/video — uploads one video file (multipart field
	 * "nros_video") into the public media library.
	 *
	 * Validation mirrors validate_and_upload() for images: size first, then
	 * the REAL type from the file's bytes (finfo), never the client's
	 * claimed type or name. The stored file name is rebuilt with the
	 * extension matching the detected type, because phones often upload a
	 * Blob named "blob" (no extension), which WordPress's own extension-
	 * based type check would otherwise refuse.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function upload_video( $request ) {
		$user  = NROS_Auth::get_user_from_request( $request );
		$files = $request->get_file_params();
		$file  = isset( $files['nros_video'] ) ? $files['nros_video'] : array();

		$allowed = self::video_upload_allowed( $user );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		// A body larger than post_max_size makes PHP discard ALL fields and
		// files, so an oversized upload looks like "no file" — report it as
		// the size problem it really is.
		$content_length = isset( $_SERVER['CONTENT_LENGTH'] ) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
		if ( empty( $file ) && $content_length > self::video_max_bytes() ) {
			return self::video_too_large_error();
		}

		if ( empty( $file ) || ! isset( $file['error'] ) ) {
			return new WP_Error( 'nros_missing_file', 'ভিডিও ফাইল পাওয়া যায়নি।', array( 'status' => 400 ) );
		}
		if ( in_array( (int) $file['error'], array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) ) {
			return self::video_too_large_error();
		}
		if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return new WP_Error( 'nros_upload_failed', 'ভিডিও আপলোড সম্পূর্ণ হয়নি, আবার চেষ্টা করুন।', array( 'status' => 400 ) );
		}
		if ( (int) $file['size'] > self::video_max_bytes() ) {
			return self::video_too_large_error();
		}

		$finfo     = finfo_open( FILEINFO_MIME_TYPE );
		$real_mime = finfo_file( $finfo, $file['tmp_name'] );
		finfo_close( $finfo );

		if ( ! isset( self::VIDEO_MIMES[ $real_mime ] ) ) {
			return new WP_Error( 'nros_bad_file_type', 'শুধুমাত্র MP4, WEBM অথবা MOV ভিডিও গ্রহণযোগ্য।', array( 'status' => 400 ) );
		}

		$base         = sanitize_file_name( pathinfo( isset( $file['name'] ) ? (string) $file['name'] : '', PATHINFO_FILENAME ) );
		$base         = '' !== $base && 'blob' !== $base ? $base : 'meeha-video-' . gmdate( 'Ymd-His' );
		$file['name'] = $base . '.' . self::VIDEO_MIMES[ $real_mime ];
		$file['type'] = $real_mime;

		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		// media_handle_upload() reads from the superglobal $_FILES, so the
		// (renamed) file is faked into it under a fixed key.
		$_FILES['nros_video_upload'] = $file;
		$attachment_id               = media_handle_upload( 'nros_video_upload', 0 );
		unset( $_FILES['nros_video_upload'] );

		if ( is_wp_error( $attachment_id ) ) {
			return new WP_Error( 'nros_upload_failed', 'ভিডিও সংরক্ষণ করা যায়নি: ' . $attachment_id->get_error_message(), array( 'status' => 500 ) );
		}

		update_post_meta( $attachment_id, '_nros_uploaded_by', $user->ID );

		return new WP_REST_Response(
			array(
				'id'   => (int) $attachment_id,
				'url'  => wp_get_attachment_url( $attachment_id ),
				'mime' => $real_mime,
				'size' => (int) $file['size'],
			),
			201
		);
	}

	/**
	 * Applies alt text and/or caption to an already-uploaded attachment.
	 * Alt text is WordPress's standard `_wp_attachment_image_alt` meta key;
	 * caption is the attachment post's own `post_excerpt` column — both are
	 * the normal WP conventions, not newsroom-os-specific, so they show up
	 * correctly in the Media Library and anywhere else WordPress expects them.
	 *
	 * @param int                $attachment_id Attachment id.
	 * @param string|null        $alt           Alt text, or null to leave unchanged.
	 * @param string|null        $caption       Caption, or null to leave unchanged.
	 */
	public static function apply_attachment_meta( $attachment_id, $alt, $caption ) {
		if ( null !== $alt ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( (string) $alt ) );
		}
		if ( null !== $caption ) {
			wp_update_post(
				array(
					'ID'           => $attachment_id,
					'post_excerpt' => sanitize_text_field( (string) $caption ),
				)
			);
		}
	}

	/**
	 * Whether an attachment was uploaded by this user through this API
	 * (the `_nros_uploaded_by` tag validate_and_upload()/upload_video()
	 * write). Used to stop a correspondent attaching someone else's media
	 * to their own article/video by guessing ids. Media added any other way
	 * (wp-admin) carries no tag and is therefore never "theirs" here.
	 *
	 * @param int $attachment_id Attachment id.
	 * @param int $user_id       User id.
	 * @return bool
	 */
	public static function uploaded_by( $attachment_id, $user_id ) {
		$uploaded_by = (int) get_post_meta( $attachment_id, '_nros_uploaded_by', true );
		return $uploaded_by > 0 && $uploaded_by === (int) $user_id;
	}

	/**
	 * Whether the requesting user may change a given attachment's alt/
	 * caption: whoever uploaded it (via this plugin), or any staff member.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param int              $attachment_id Attachment id.
	 * @return bool
	 */
	protected static function can_edit_attachment( $request, $attachment_id ) {
		$user = NROS_Auth::get_user_from_request( $request );
		if ( ! $user || is_wp_error( $user ) ) {
			return false;
		}
		if ( user_can( $user, 'edit_others_posts' ) ) {
			return true;
		}
		$uploaded_by = (int) get_post_meta( $attachment_id, '_nros_uploaded_by', true );
		return $uploaded_by && $uploaded_by === (int) $user->ID;
	}

	/**
	 * POST /media — uploads one image into the public media library via
	 * WordPress's own media_handle_upload(), tagging it with who uploaded
	 * it. Expects a multipart field named "nros_media", with optional
	 * "alt"/"caption" fields alongside it so a correspondent (or staff
	 * member) can supply both in the same request instead of a second call.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function upload( $request ) {
		$user  = NROS_Auth::get_user_from_request( $request );
		$files = $request->get_file_params();

		$attachment_id = self::validate_and_upload( isset( $files['nros_media'] ) ? $files['nros_media'] : array(), $user->ID );
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		self::apply_attachment_meta(
			$attachment_id,
			$request->get_param( 'alt' ),
			$request->get_param( 'caption' )
		);

		return new WP_REST_Response(
			array(
				'id'      => $attachment_id,
				'url'     => wp_get_attachment_url( $attachment_id ),
				'alt'     => get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
				'caption' => get_post_field( 'post_excerpt', $attachment_id ),
			),
			201
		);
	}

	/**
	 * POST /me/avatar — uploads (or replaces) the logged-in user's own
	 * profile picture. Expects a multipart field named "nros_avatar". Any
	 * previously-uploaded avatar attachment is deleted once the new one is
	 * safely in place, so a user who changes their photo repeatedly doesn't
	 * quietly pile up orphaned media-library attachments.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function upload_avatar( $request ) {
		$user  = NROS_Auth::get_user_from_request( $request );
		$files = $request->get_file_params();

		$attachment_id = self::validate_and_upload( isset( $files['nros_avatar'] ) ? $files['nros_avatar'] : array(), $user->ID );
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		$previous_id = (int) get_user_meta( $user->ID, '_nros_avatar_id', true );
		update_user_meta( $user->ID, '_nros_avatar_id', $attachment_id );

		if ( $previous_id && $previous_id !== $attachment_id ) {
			wp_delete_attachment( $previous_id, true );
		}

		return new WP_REST_Response(
			array(
				'ok'     => true,
				'avatar' => self::avatar_url( $user->ID ),
			),
			201
		);
	}

	/**
	 * DELETE /me/avatar — removes the logged-in user's custom profile
	 * picture, reverting them back to their Gravatar (the original default
	 * behavior, unchanged for anyone who never uploads a photo at all).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function delete_avatar( $request ) {
		$user        = NROS_Auth::get_user_from_request( $request );
		$previous_id = (int) get_user_meta( $user->ID, '_nros_avatar_id', true );

		delete_user_meta( $user->ID, '_nros_avatar_id' );

		if ( $previous_id ) {
			wp_delete_attachment( $previous_id, true );
		}

		return new WP_REST_Response(
			array(
				'ok'     => true,
				'avatar' => self::avatar_url( $user->ID ),
			),
			200
		);
	}

	/**
	 * Gallery size limits: a page is at most 20 images, and the gallery
	 * never reaches further back than the 50 most recent.
	 */
	const LIBRARY_PAGE = 24;

	/**
	 * Attachment ids that live in the media library but are NOT article
	 * images and must never be offered for insertion into a post: users'
	 * profile photos and advertisers' visiting cards (business contact
	 * details a correspondent uploaded for staff, not for publication).
	 *
	 * @return int[]
	 */
	protected static function non_article_attachment_ids() {
		global $wpdb;

		$ids = $wpdb->get_col( "SELECT meta_value FROM {$wpdb->usermeta} WHERE meta_key = '_nros_avatar_id'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$table = $wpdb->prefix . 'nros_ad_clients';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$cards = $wpdb->get_col( "SELECT visiting_card_id FROM {$table} WHERE visiting_card_id IS NOT NULL AND visiting_card_id > 0" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$ids   = array_merge( (array) $ids, (array) $cards );
		}

		return array_values( array_unique( array_filter( array_map( 'intval', (array) $ids ) ) ) );
	}

	/**
	 * Attachment ids matching a gallery search: the image's own title,
	 * caption, alt text or file name, OR the headline of an article that
	 * uses it (as featured image, or as the post it was uploaded to) — staff
	 * remember "the photo from the flood story", not the file name.
	 *
	 * @param string $search Search text.
	 * @return int[] Newest first, at most 500.
	 */
	protected static function search_attachment_ids( $search ) {
		global $wpdb;
		$like = '%' . $wpdb->esc_like( $search ) . '%';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$own = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} alt ON alt.post_id = p.ID AND alt.meta_key = '_wp_attachment_image_alt'
				LEFT JOIN {$wpdb->postmeta} f ON f.post_id = p.ID AND f.meta_key = '_wp_attached_file'
				WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%%'
				AND ( p.post_title LIKE %s OR p.post_excerpt LIKE %s OR alt.meta_value LIKE %s OR f.meta_value LIKE %s )
				ORDER BY p.post_date DESC LIMIT 500",
				$like,
				$like,
				$like,
				$like
			)
		);
		$featured = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT CAST(m.meta_value AS UNSIGNED) FROM {$wpdb->postmeta} m
				INNER JOIN {$wpdb->posts} a ON a.ID = m.post_id
				WHERE m.meta_key = '_thumbnail_id' AND a.post_type IN ('post','meeha_video','meeha_photocard')
				AND a.post_status IN ('publish','future','pending','draft') AND a.post_title LIKE %s
				ORDER BY a.post_date DESC LIMIT 500",
				$like
			)
		);
		$attached = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->posts} a ON a.ID = p.post_parent
				WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%%' AND a.post_title LIKE %s
				ORDER BY p.post_date DESC LIMIT 500",
				$like
			)
		);
		// phpcs:enable

		$ids = array_values( array_unique( array_filter( array_map( 'intval', array_merge( (array) $own, (array) $featured, (array) $attached ) ) ) ) );
		return array_slice( $ids, 0, 500 );
	}

	/**
	 * GET /admin/media?offset=0[&search=…] — article images, newest first,
	 * LIBRARY_PAGE at a time. Without a search the newest 1000
	 * (filter `nros_media_library_max`) are browsable; with a search (round 18) the WHOLE library is
	 * searched, so old images can be found and reused. Includes images
	 * uploaded through wp-admin too.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function list_library( $request ) {
		$offset = max( 0, (int) $request->get_param( 'offset' ) );
		$search = trim( sanitize_text_field( (string) $request->get_param( 'search' ) ) );

		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => 'image',
			'orderby'        => 'date',
			'order'          => 'DESC',
			'post__not_in'   => self::non_article_attachment_ids(), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in
		);

		if ( '' !== $search ) {
			// WP_Query ignores post__not_in when post__in is set, so the
			// profile photos / visiting cards are removed here.
			$ids = array_values( array_diff( self::search_attachment_ids( $search ), $args['post__not_in'] ) );
			unset( $args['post__not_in'] );
			if ( ! $ids ) {
				return new WP_REST_Response( array( 'items' => array(), 'has_more' => false, 'total' => 0 ), 200 );
			}
			$args['post__in']       = $ids;
			$args['posts_per_page'] = self::LIBRARY_PAGE;
			$args['offset']         = $offset;
			$cap                    = PHP_INT_MAX;
		} else {
			$max = (int) apply_filters( 'nros_media_library_max', 1000 );
			if ( $offset >= $max ) {
				return new WP_REST_Response( array( 'items' => array(), 'has_more' => false ), 200 );
			}
			$args['posts_per_page'] = min( self::LIBRARY_PAGE, $max - $offset );
			$args['offset']         = $offset;
			$cap                    = $max;
		}

		$query = new WP_Query( $args );

		$items = array();
		foreach ( $query->posts as $attachment ) {
			$thumb   = wp_get_attachment_image_url( $attachment->ID, 'medium' );
			$items[] = array(
				'id'      => $attachment->ID,
				'url'     => wp_get_attachment_url( $attachment->ID ),
				'thumb'   => $thumb ? $thumb : wp_get_attachment_url( $attachment->ID ),
				'alt'     => (string) get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ),
				'caption' => (string) $attachment->post_excerpt,
				'title'   => (string) $attachment->post_title,
				'date'    => $attachment->post_date,
			);
		}

		$reachable = min( (int) $query->found_posts, $cap );

		return new WP_REST_Response(
			array(
				'items'    => $items,
				'has_more' => ( $offset + count( $items ) ) < $reachable,
				'total'    => $reachable,
			),
			200
		);
	}

	/**
	 * PATCH /media/{id} — updates alt text/caption on an already-uploaded
	 * attachment (e.g. after picking it as a featured image, or editing an
	 * inline image already inserted into the article body).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_meta( $request ) {
		$id = (int) $request->get_param( 'id' );

		if ( ! wp_attachment_is_image( $id ) ) {
			return new WP_Error( 'nros_not_found', 'পাওয়া যায়নি।', array( 'status' => 404 ) );
		}

		if ( ! self::can_edit_attachment( $request, $id ) ) {
			return new WP_Error( 'nros_forbidden', 'এই অ্যাক্সেস নেই।', array( 'status' => 403 ) );
		}

		self::apply_attachment_meta( $id, $request->get_param( 'alt' ), $request->get_param( 'caption' ) );

		return new WP_REST_Response(
			array(
				'id'      => $id,
				'alt'     => get_post_meta( $id, '_wp_attachment_image_alt', true ),
				'caption' => get_post_field( 'post_excerpt', $id ),
			),
			200
		);
	}
}
