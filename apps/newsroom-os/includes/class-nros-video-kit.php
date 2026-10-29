<?php
/**
 * NROS_Video_Kit — round 21: backend for the Publisher app's video editor
 * ("ভিডিও এডিট": animated frames, intro/outro, corner logo, ad breaks).
 *
 * The video itself is rendered in the browser (WebCodecs), never on the
 * server — shared hosting has neither ffmpeg nor the CPU for it. This
 * class only gives the editor what a browser can't get on its own:
 *
 * - GET  /admin/media/{id}/file     — the bytes of a video/image
 *   attachment, through the API so they carry the app's CORS headers.
 *   Files under wp-content/uploads are served by the web server without
 *   CORS, and a canvas that draws a cross-origin frame can't be encoded.
 * - GET  /admin/media/videos        — the video library ("ভিডিও গ্যালারি")
 *   for picking ad clips / a custom intro or outro; `ads=1` = only the
 *   clips marked as ads.
 * - PATCH /admin/media/videos/{id}  — mark a clip as an ad / rename it.
 * - GET  /admin/video-kit           — brand overrides (logo, intro, outro,
 *   website, default ticker) + shared presets.
 * - POST /admin/video-kit           — Publisher: brand overrides.
 * - POST /admin/video-kit/presets   — staff: the shared preset list.
 * - POST /admin/video-kit/people    — staff (round 23): the saved name list
 *   for one-click name plates (guests, officials). The kit also lists every
 *   correspondent with their area, so reporters need no typing.
 *
 * The edit itself (which original file, which settings) is stored on the
 * video post by NROS_Videos::save() (`edit` field), so a video can be
 * re-edited from its original or reverted.
 *
 * @package NewsroomOS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NROS_Video_Kit {

	const OPTION        = 'nros_video_kit';
	const LIBRARY_PAGE  = 24;
	const PRESETS_MAX   = 20;
	const CONFIG_MAX    = 262144; // bytes of JSON per stored edit / preset (a smart-reframe track is large).
	const PEOPLE_MAX    = 150;
	const TEXT_MAX      = 300;

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
			'/admin/media/(?P<id>\d+)/file',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'stream_file' ),
				'permission_callback' => array( 'NROS_Core', 'require_staff' ),
			)
		);

		register_rest_route(
			NROS_API_NS,
			'/admin/media/videos',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_videos' ),
				'permission_callback' => array( 'NROS_Core', 'require_staff' ),
			)
		);

		register_rest_route(
			NROS_API_NS,
			'/admin/media/videos/(?P<id>\d+)',
			array(
				'methods'             => 'PATCH',
				'callback'            => array( __CLASS__, 'update_video' ),
				'permission_callback' => array( 'NROS_Core', 'require_staff' ),
			)
		);

		register_rest_route(
			NROS_API_NS,
			'/admin/video-kit',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_kit' ),
					'permission_callback' => array( 'NROS_Core', 'require_staff' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'save_kit' ),
					'permission_callback' => array( 'NROS_Core', 'require_publisher' ),
				),
			)
		);

		register_rest_route(
			NROS_API_NS,
			'/admin/video-kit/people',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'save_people' ),
				'permission_callback' => array( 'NROS_Core', 'require_staff' ),
			)
		);

		register_rest_route(
			NROS_API_NS,
			'/admin/video-kit/presets',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'save_presets' ),
				'permission_callback' => array( 'NROS_Core', 'require_staff' ),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Whether an attachment is a video (any video/* MIME).
	 *
	 * @param int $id Attachment id.
	 * @return bool
	 */
	public static function is_video( $id ) {
		return $id > 0
			&& 'attachment' === get_post_type( $id )
			&& 0 === strpos( (string) get_post_mime_type( $id ), 'video/' );
	}

	/**
	 * Library row for one video attachment.
	 *
	 * @param WP_Post $attachment Attachment.
	 * @return array
	 */
	public static function video_row( $attachment ) {
		$meta  = wp_get_attachment_metadata( $attachment->ID );
		$meta  = is_array( $meta ) ? $meta : array();
		$file  = get_attached_file( $attachment->ID );
		$size  = isset( $meta['filesize'] ) ? (int) $meta['filesize'] : ( $file && file_exists( $file ) ? (int) filesize( $file ) : 0 );
		$thumb = get_post_thumbnail_id( $attachment->ID );

		return array(
			'id'          => (int) $attachment->ID,
			'url'         => (string) wp_get_attachment_url( $attachment->ID ),
			'title'       => (string) $attachment->post_title,
			'mime'        => (string) $attachment->post_mime_type,
			'size'        => $size,
			'duration'    => isset( $meta['length'] ) ? (float) $meta['length'] : null,
			'width'       => isset( $meta['width'] ) ? (int) $meta['width'] : null,
			'height'      => isset( $meta['height'] ) ? (int) $meta['height'] : null,
			'thumb'       => $thumb ? (string) wp_get_attachment_image_url( $thumb, 'medium' ) : null,
			'date'        => $attachment->post_date,
			'is_ad'       => (bool) get_post_meta( $attachment->ID, '_nros_video_ad', true ),
			'edited_from' => (int) get_post_meta( $attachment->ID, '_nros_edited_from', true ) ?: null,
		);
	}

	/**
	 * Decodes a JSON-ish config (array from the REST body) into a clean
	 * array, or WP_Error when it's too large / not an object.
	 *
	 * @param mixed  $value Value from the request.
	 * @param string $label Bengali name for the error.
	 * @return array|WP_Error
	 */
	public static function clean_config( $value, $label = 'এডিট সেটিং' ) {
		if ( ! is_array( $value ) ) {
			return new WP_Error( 'nros_bad_request', $label . ' সঠিক নয়।', array( 'status' => 400 ) );
		}
		$json = wp_json_encode( $value );
		if ( false === $json || strlen( $json ) > self::CONFIG_MAX ) {
			return new WP_Error( 'nros_bad_request', $label . ' অনেক বড়।', array( 'status' => 400 ) );
		}
		return json_decode( $json, true );
	}

	/**
	 * Stored kit option with defaults filled in.
	 *
	 * @return array
	 */
	protected static function kit_option() {
		$kit = get_option( self::OPTION, array() );
		$kit = is_array( $kit ) ? $kit : array();
		return array_merge(
			array(
				'logo_id'  => 0,
				'intro_id' => 0,
				'outro_id' => 0,
				'website'  => 'meeha.news',
				'ticker'   => '',
				'ad_text'  => '',
				'presets'  => array(),
				'people'   => array(),
			),
			$kit
		);
	}

	/**
	 * Public shape of an attachment reference in the kit.
	 *
	 * @param int  $id    Attachment id.
	 * @param bool $video Video (else image).
	 * @return array|null
	 */
	protected static function ref( $id, $video ) {
		$id = (int) $id;
		if ( $id <= 0 || 'attachment' !== get_post_type( $id ) ) {
			return null;
		}
		if ( $video && ! self::is_video( $id ) ) {
			return null;
		}
		if ( ! $video && ! wp_attachment_is_image( $id ) ) {
			return null;
		}
		$row = array(
			'id'    => $id,
			'url'   => (string) wp_get_attachment_url( $id ),
			'title' => (string) get_the_title( $id ),
		);
		if ( $video ) {
			$meta            = wp_get_attachment_metadata( $id );
			$row['duration'] = is_array( $meta ) && isset( $meta['length'] ) ? (float) $meta['length'] : null;
		}
		return $row;
	}

	/**
	 * The kit as the app sees it.
	 *
	 * @return array
	 */
	protected static function kit_shape() {
		$kit = self::kit_option();
		return array(
			'logo'    => self::ref( $kit['logo_id'], false ),
			'intro'   => self::ref( $kit['intro_id'], true ),
			'outro'   => self::ref( $kit['outro_id'], true ),
			'website' => (string) $kit['website'],
			'ticker'  => (string) $kit['ticker'],
			'ad_text' => (string) $kit['ad_text'],
			'presets' => array_values( is_array( $kit['presets'] ) ? $kit['presets'] : array() ),
			'people'  => array_values( is_array( $kit['people'] ) ? $kit['people'] : array() ),
			'reporters' => self::reporters(),
		);
	}

	/**
	 * Every correspondent as a name plate: name + "<area> সংবাদদাতা".
	 *
	 * @return array
	 */
	protected static function reporters() {
		$out = array();
		foreach ( get_users( array( 'role' => 'correspondent', 'orderby' => 'display_name', 'number' => 500 ) ) as $u ) {
			$area  = trim( (string) get_user_meta( $u->ID, 'meeha_division', true ) );
			$out[] = array(
				'id'   => (int) $u->ID,
				'name' => (string) $u->display_name,
				'role' => '' !== $area ? $area . ' সংবাদদাতা' : 'সংবাদদাতা',
			);
		}
		return $out;
	}

	// -------------------------------------------------------------------------
	// Endpoints
	// -------------------------------------------------------------------------

	/**
	 * GET /admin/media/{id}/file — streams a video or image attachment.
	 *
	 * Sent straight to the client (not through a WP_REST_Response, which
	 * would hold a 200 MB file in memory). The CORS headers were already
	 * sent by NROS_Core::add_cors_headers() at rest_api_init.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_Error|void
	 */
	public static function stream_file( $request ) {
		$id   = (int) $request->get_param( 'id' );
		$mime = (string) get_post_mime_type( $id );
		if ( 'attachment' !== get_post_type( $id ) || ( 0 !== strpos( $mime, 'video/' ) && 0 !== strpos( $mime, 'image/' ) ) ) {
			return new WP_Error( 'nros_not_found', 'ফাইল পাওয়া যায়নি।', array( 'status' => 404 ) );
		}
		$path = get_attached_file( $id );
		if ( ! $path || ! is_readable( $path ) ) {
			return new WP_Error( 'nros_not_found', 'সার্ভারে ফাইলটি পাওয়া যায়নি।', array( 'status' => 404 ) );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		status_header( 200 );
		header( 'Content-Type: ' . $mime );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'Content-Disposition: inline; filename="' . rawurlencode( basename( $path ) ) . '"' );
		// Same file every time for this id: let the editor's browser keep it.
		header( 'Cache-Control: private, max-age=86400' );
		header( 'X-Content-Type-Options: nosniff' );

		$fh = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( $fh ) {
			while ( ! feof( $fh ) ) {
				echo fread( $fh, 1048576 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.AlternativeFunctions.file_system_operations_fread
				flush();
			}
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		exit;
	}

	/**
	 * GET /admin/media/videos?offset=0[&search=…][&ads=1] — video library,
	 * newest first.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function list_videos( $request ) {
		$offset = max( 0, (int) $request->get_param( 'offset' ) );
		$search = trim( sanitize_text_field( (string) $request->get_param( 'search' ) ) );
		$args   = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => 'video',
			'orderby'        => 'date',
			'order'          => 'DESC',
			'posts_per_page' => self::LIBRARY_PAGE,
			'offset'         => $offset,
		);
		if ( '' !== $search ) {
			$args['s'] = $search;
		}
		if ( $request->get_param( 'ads' ) ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => '_nros_video_ad',
					'value' => '1',
				),
			);
		}

		$query = new WP_Query( $args );
		$items = array_map( array( __CLASS__, 'video_row' ), $query->posts );

		return new WP_REST_Response(
			array(
				'items'    => $items,
				'has_more' => ( $offset + count( $items ) ) < (int) $query->found_posts,
				'total'    => (int) $query->found_posts,
			),
			200
		);
	}

	/**
	 * PATCH /admin/media/videos/{id} — { is_ad?, title? }.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_video( $request ) {
		$id = (int) $request->get_param( 'id' );
		if ( ! self::is_video( $id ) ) {
			return new WP_Error( 'nros_not_found', 'ভিডিও পাওয়া যায়নি।', array( 'status' => 404 ) );
		}
		if ( $request->has_param( 'is_ad' ) ) {
			if ( rest_sanitize_boolean( $request->get_param( 'is_ad' ) ) ) {
				update_post_meta( $id, '_nros_video_ad', '1' );
			} else {
				delete_post_meta( $id, '_nros_video_ad' );
			}
		}
		if ( $request->has_param( 'title' ) ) {
			$title = sanitize_text_field( (string) $request->get_param( 'title' ) );
			if ( '' !== $title ) {
				wp_update_post(
					array(
						'ID'         => $id,
						'post_title' => mb_substr( $title, 0, 200 ),
					)
				);
			}
		}
		return new WP_REST_Response( self::video_row( get_post( $id ) ), 200 );
	}

	/**
	 * GET /admin/video-kit.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_kit() {
		return new WP_REST_Response( self::kit_shape(), 200 );
	}

	/**
	 * POST /admin/video-kit — Publisher: { logo_id?, intro_id?, outro_id?,
	 * website?, ticker?, ad_text? }. 0 = use the built-in asset.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function save_kit( $request ) {
		$kit = self::kit_option();

		$refs = array(
			'logo_id'  => false,
			'intro_id' => true,
			'outro_id' => true,
		);
		foreach ( $refs as $key => $video ) {
			if ( ! $request->has_param( $key ) ) {
				continue;
			}
			$id = max( 0, (int) $request->get_param( $key ) );
			if ( $id > 0 && ! self::ref( $id, $video ) ) {
				return new WP_Error( 'nros_bad_request', $video ? 'সঠিক ভিডিও ফাইল দিন।' : 'সঠিক ছবি (PNG) দিন।', array( 'status' => 400 ) );
			}
			$kit[ $key ] = $id;
		}
		foreach ( array( 'website', 'ticker', 'ad_text' ) as $key ) {
			if ( $request->has_param( $key ) ) {
				$kit[ $key ] = mb_substr( sanitize_text_field( (string) $request->get_param( $key ) ), 0, self::TEXT_MAX );
			}
		}

		update_option( self::OPTION, $kit, false );
		return new WP_REST_Response( self::kit_shape(), 200 );
	}

	/**
	 * POST /admin/video-kit/people — staff: { people: [{ name, role }] }
	 * replaces the saved name list (max PEOPLE_MAX).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function save_people( $request ) {
		$in = $request->get_param( 'people' );
		if ( ! is_array( $in ) ) {
			return new WP_Error( 'nros_bad_request', 'নামের তালিকা সঠিক নয়।', array( 'status' => 400 ) );
		}
		if ( count( $in ) > self::PEOPLE_MAX ) {
			return new WP_Error( 'nros_bad_request', 'সর্বোচ্চ ' . NROS_Core::bn_digits( self::PEOPLE_MAX ) . 'টি নাম রাখা যায়।', array( 'status' => 400 ) );
		}
		$out = array();
		foreach ( $in as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$name = mb_substr( sanitize_text_field( (string) ( $row['name'] ?? '' ) ), 0, 90 );
			if ( '' === $name ) {
				continue;
			}
			$out[] = array(
				'name' => $name,
				'role' => mb_substr( sanitize_text_field( (string) ( $row['role'] ?? '' ) ), 0, 70 ),
			);
		}

		$kit           = self::kit_option();
		$kit['people'] = $out;
		update_option( self::OPTION, $kit, false );
		return new WP_REST_Response( self::kit_shape(), 200 );
	}

	/**
	 * POST /admin/video-kit/presets — staff: { presets: [{ id, name, config }] }
	 * replaces the shared list (max PRESETS_MAX).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function save_presets( $request ) {
		$in = $request->get_param( 'presets' );
		if ( ! is_array( $in ) ) {
			return new WP_Error( 'nros_bad_request', 'প্রিসেট তালিকা সঠিক নয়।', array( 'status' => 400 ) );
		}
		if ( count( $in ) > self::PRESETS_MAX ) {
			return new WP_Error( 'nros_bad_request', 'সর্বোচ্চ ' . NROS_Core::bn_digits( self::PRESETS_MAX ) . 'টি প্রিসেট রাখা যায়।', array( 'status' => 400 ) );
		}
		$out = array();
		foreach ( $in as $preset ) {
			if ( ! is_array( $preset ) ) {
				continue;
			}
			$name = mb_substr( sanitize_text_field( (string) ( $preset['name'] ?? '' ) ), 0, 60 );
			if ( '' === $name ) {
				return new WP_Error( 'nros_bad_request', 'প্রিসেটের নাম দিন।', array( 'status' => 400 ) );
			}
			$config = self::clean_config( $preset['config'] ?? null, 'প্রিসেট' );
			if ( is_wp_error( $config ) ) {
				return $config;
			}
			$out[] = array(
				'id'     => sanitize_key( (string) ( $preset['id'] ?? '' ) ) ?: wp_generate_password( 8, false ),
				'name'   => $name,
				'config' => $config,
			);
		}

		$kit            = self::kit_option();
		$kit['presets'] = $out;
		update_option( self::OPTION, $kit, false );
		return new WP_REST_Response( self::kit_shape(), 200 );
	}
}
