<?php
/**
 * Round 24 — ফটোকার্ড স্টুডিও for the publisher app.
 *
 * The theme (Meeha News 2.x) owns the photocard studio: settings, built-in
 * designs, own frames, ad strips, the reader "ফটোকার্ড ডাউনলোড" button and
 * the canvas renderer (assets/js/cards.js, which the publisher app ships an
 * identical copy of). The theme's own REST routes (meeha/v1/card-*) use
 * cookie + nonce auth, which the apps don't have, so this class is a thin
 * bridge: every route checks staff access through the app's JWT, makes that
 * user the current WordPress user, and then calls the theme's own functions
 * — nothing about the cards is duplicated here. Without the theme studio
 * (another theme active) the kit says so and the app falls back to its
 * built-in defaults.
 *
 *   GET    /admin/photocard/kit               settings, designs, frames, ads, categories, days, dates
 *   GET    /admin/photocard/source?post=      a post's card fields + its photos (featured + inside the article)
 *   GET    /admin/photocard/recent            latest published posts (offset, search)
 *   GET    /admin/photocard/quotes            উক্তি picker (search, page)
 *   GET    /admin/photocard/dates?d=&offset=  weekday / খ্রিষ্টাব্দ / বঙ্গাব্দ / হিজরি for a day
 *   GET    /admin/photocard/file?id=&size=|url=  streams one of this site's images (canvas needs same-origin bytes)
 *   POST   /admin/photocard/focus             saves an image's card position (theme reader downloads use it)
 *   POST   /admin/photocard/save              keeps a finished card in the media library
 *   GET    /admin/photocard/made              cards kept so far (type, page)
 *   POST   /admin/photocard/frames            uploads an own frame (theme rules: transparent PNG, exact size)
 *   DELETE /admin/photocard/frames/{id}       deletes an own frame
 *
 * @package NewsroomOS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NROS_Photocard {

	const MAX_BYTES    = 8388608; // 8 MB — a 1080 × 1920 PNG is usually 2–4 MB.
	const POST_IMAGES  = 12;
	const MADE_PAGE    = 24;
	const FOCUS_META   = '_meeha_card_focus';
	const IMAGE_EXTS   = array( 'jpg', 'jpeg', 'png', 'webp', 'gif', 'svg' );

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
		$staff = array( 'NROS_Core', 'require_staff' );
		$get   = array(
			'/admin/photocard/kit'    => 'get_kit',
			'/admin/photocard/source' => 'get_source',
			'/admin/photocard/recent' => 'get_recent',
			'/admin/photocard/quotes' => 'get_quotes',
			'/admin/photocard/dates'  => 'get_dates',
			'/admin/photocard/file'   => 'stream_file',
			'/admin/photocard/made'   => 'get_made',
		);
		foreach ( $get as $route => $cb ) {
			register_rest_route(
				NROS_API_NS,
				$route,
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, $cb ),
					'permission_callback' => $staff,
				)
			);
		}
		$post = array(
			'/admin/photocard/focus'  => 'save_focus',
			'/admin/photocard/save'   => 'save_card',
			'/admin/photocard/frames' => 'upload_frame',
		);
		foreach ( $post as $route => $cb ) {
			register_rest_route(
				NROS_API_NS,
				$route,
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, $cb ),
					'permission_callback' => $staff,
				)
			);
		}
		register_rest_route(
			NROS_API_NS,
			'/admin/photocard/frames/(?P<id>f[a-z0-9]{6,20})',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'delete_frame' ),
				'permission_callback' => $staff,
			)
		);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Makes the app's user the current WordPress user, so the theme's own
	 * capability checks (meeha_make_cards, frame upload roles, read_post)
	 * answer for them.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_User|null
	 */
	protected static function as_user( $request ) {
		$user = NROS_Auth::get_user_from_request( $request );
		if ( $user && ! is_wp_error( $user ) ) {
			wp_set_current_user( $user->ID );
			return $user;
		}
		return null;
	}

	/**
	 * Whether the active theme has the photocard studio.
	 *
	 * @return bool
	 */
	public static function has_studio() {
		return function_exists( 'meeha_cards_config' ) && function_exists( 'meeha_studio_types' );
	}

	/**
	 * WP_Error for routes that need the theme studio.
	 *
	 * @return WP_Error
	 */
	protected static function no_studio() {
		return new WP_Error( 'nros_no_studio', 'সাইটের থিমে ফটোকার্ড স্টুডিও পাওয়া যায়নি — মিহা নিউজ থিম চালু আছে কিনা দেখুন।', array( 'status' => 501 ) );
	}

	/**
	 * Calls a theme REST callback with the given params and returns its data.
	 *
	 * @param string $fn     Function name.
	 * @param array  $params Params.
	 * @return mixed|WP_Error
	 */
	protected static function theme_call( $fn, $params ) {
		if ( ! function_exists( $fn ) ) {
			return self::no_studio();
		}
		$req = new WP_REST_Request( 'GET' );
		foreach ( $params as $k => $v ) {
			$req->set_param( $k, $v );
		}
		$res = call_user_func( $fn, $req );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return $res instanceof WP_REST_Response ? $res->get_data() : $res;
	}

	/**
	 * Saved card position of an image, or null.
	 *
	 * @param int $id Attachment id.
	 * @return array|null
	 */
	public static function focus_of( $id ) {
		if ( function_exists( 'meeha_cards_image_focus' ) ) {
			return meeha_cards_image_focus( $id );
		}
		$f = get_post_meta( (int) $id, self::FOCUS_META, true );
		return is_array( $f ) && isset( $f['x'], $f['y'] ) ? array(
			'x'    => (float) $f['x'],
			'y'    => (float) $f['y'],
			'zoom' => isset( $f['zoom'] ) ? (float) $f['zoom'] : 1,
		) : null;
	}

	/**
	 * Photo row for the app's photo strip.
	 *
	 * @param int  $id       Attachment id.
	 * @param bool $featured Featured image?
	 * @return array|null
	 */
	protected static function image_row( $id, $featured ) {
		$id = (int) $id;
		if ( ! $id || 'attachment' !== get_post_type( $id ) || 0 !== strpos( (string) get_post_mime_type( $id ), 'image/' ) ) {
			return null;
		}
		$meta = wp_get_attachment_metadata( $id );
		$meta = is_array( $meta ) ? $meta : array();
		$med  = wp_get_attachment_image_src( $id, 'medium' );
		return array(
			'id'       => $id,
			'url'      => (string) wp_get_attachment_url( $id ),
			'thumb'    => $med ? (string) $med[0] : (string) wp_get_attachment_url( $id ),
			'width'    => isset( $meta['width'] ) ? (int) $meta['width'] : null,
			'height'   => isset( $meta['height'] ) ? (int) $meta['height'] : null,
			'alt'      => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'caption'  => (string) get_post_field( 'post_excerpt', $id ),
			'featured' => $featured,
			'focus'    => self::focus_of( $id ),
		);
	}

	/**
	 * The post's photos: the featured image first, then every image inside
	 * the article (wp-image-{id} classes, gallery shortcodes / blocks, and
	 * plain <img> tags of this site's uploads), without repeats.
	 *
	 * @param WP_Post $p Post.
	 * @return array
	 */
	public static function post_images( $p ) {
		$ids  = array();
		$feat = (int) get_post_thumbnail_id( $p );
		if ( $feat ) {
			$ids[] = $feat;
		}
		$html = (string) $p->post_content;
		if ( preg_match_all( '/wp-image-(\d+)/', $html, $m ) ) {
			$ids = array_merge( $ids, array_map( 'intval', $m[1] ) );
		}
		if ( preg_match_all( '/\[gallery[^\]]*\bids="([\d,\s]+)"/', $html, $m ) ) {
			foreach ( $m[1] as $list ) {
				$ids = array_merge( $ids, array_map( 'intval', explode( ',', $list ) ) );
			}
		}
		if ( preg_match_all( '/"ids":\[([\d,\s]+)\]/', $html, $m ) ) {
			foreach ( $m[1] as $list ) {
				$ids = array_merge( $ids, array_map( 'intval', explode( ',', $list ) ) );
			}
		}
		if ( preg_match_all( '/<img[^>]+src="([^"]+)"/i', $html, $m ) ) {
			foreach ( array_slice( $m[1], 0, 20 ) as $src ) {
				$aid = attachment_url_to_postid( preg_replace( '/-\d+x\d+(\.[a-z]+)$/i', '$1', $src ) );
				if ( $aid ) {
					$ids[] = (int) $aid;
				}
			}
		}
		$out  = array();
		$seen = array();
		foreach ( $ids as $id ) {
			if ( $id <= 0 || isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$row         = self::image_row( $id, $id === $feat );
			if ( $row ) {
				$out[] = $row;
			}
			if ( count( $out ) >= self::POST_IMAGES ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Local file of a URL that points into this site's uploads folder or the
	 * active theme (CDN host or a LiteSpeed ".webp" suffix tolerated), or ''.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function local_path( $url ) {
		$path = (string) wp_parse_url( (string) $url, PHP_URL_PATH );
		if ( '' === $path ) {
			return '';
		}
		$path  = rawurldecode( $path );
		$up    = wp_upload_dir( null, false );
		$roots = array(
			array( (string) wp_parse_url( $up['baseurl'], PHP_URL_PATH ), $up['basedir'] ),
			array( (string) wp_parse_url( get_stylesheet_directory_uri(), PHP_URL_PATH ), get_stylesheet_directory() ),
			array( (string) wp_parse_url( get_template_directory_uri(), PHP_URL_PATH ), get_template_directory() ),
		);
		foreach ( $roots as $r ) {
			$prefix = untrailingslashit( $r[0] ) . '/';
			if ( '/' === $prefix || 0 !== strpos( $path, $prefix ) ) {
				continue;
			}
			$base = realpath( $r[1] );
			$rel  = substr( $path, strlen( $prefix ) );
			foreach ( array( $rel, preg_replace( '/(\.(?:jpe?g|png|gif))\.webp$/i', '$1', $rel ) ) as $cand ) {
				$file = realpath( trailingslashit( $r[1] ) . $cand );
				if ( $base && $file && 0 === strpos( $file, trailingslashit( $base ) ) && is_file( $file ) && is_readable( $file ) ) {
					return $file;
				}
			}
		}
		return '';
	}

	// -------------------------------------------------------------------------
	// Routes
	// -------------------------------------------------------------------------

	/**
	 * GET /admin/photocard/kit
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function get_kit( $request ) {
		self::as_user( $request );
		$theme = wp_get_theme( get_template() );
		$out   = array(
			'studio'        => self::has_studio(),
			'theme_version' => (string) $theme->get( 'Version' ),
			'focus_sync'    => function_exists( 'meeha_cards_image_focus' ),
			'site'          => home_url( '/' ),
		);
		if ( ! $out['studio'] ) {
			return rest_ensure_response( $out );
		}
		if ( ! function_exists( 'meeha_studio_js_config' ) ) {
			$admin = get_template_directory() . '/inc/studio/admin.php';
			if ( is_readable( $admin ) ) {
				require_once $admin;
			}
		}
		$conf = function_exists( 'meeha_studio_js_config' ) ? meeha_studio_js_config() : array();
		unset( $conf['rest'], $conf['wpRest'], $conf['nonce'], $conf['init'], $conf['madeUrl'], $conf['history'], $conf['frameBase'] );
		$cards = meeha_cards_config();
		unset( $cards['base'], $cards['fonts'] );
		$out['cards']  = $cards;
		$out['studio_config'] = $conf;
		return rest_ensure_response( $out );
	}

	/**
	 * GET /admin/photocard/source?post=ID|URL
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_source( $request ) {
		self::as_user( $request );
		$in   = trim( (string) $request->get_param( 'post' ) );
		$in   = strtr( $in, array( '০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9' ) );
		$data = self::theme_call( 'meeha_studio_rest_card_source', array( 'post' => $in ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$p = get_post( (int) $data['id'] );
		$data['images'] = $p ? self::post_images( $p ) : array();
		$data['type']   = function_exists( 'meeha_studio_type_for_post' ) && $p ? meeha_studio_type_for_post( $p ) : 'news';
		$data['status'] = $p ? $p->post_status : '';
		$data['author_photo_local'] = ! empty( $data['author_photo'] ) && '' !== self::local_path( $data['author_photo'] );
		return rest_ensure_response( $data );
	}

	/**
	 * GET /admin/photocard/recent?offset=&search=
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_recent( $request ) {
		self::as_user( $request );
		return rest_ensure_response(
			self::theme_call(
				'meeha_studio_rest_recent',
				array(
					'offset' => max( 0, min( 500, (int) $request->get_param( 'offset' ) ) ),
					'search' => sanitize_text_field( (string) $request->get_param( 'search' ) ),
				)
			)
		);
	}

	/**
	 * GET /admin/photocard/quotes?search=&page=
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_quotes( $request ) {
		self::as_user( $request );
		return rest_ensure_response(
			self::theme_call(
				'meeha_studio_rest_quotes',
				array(
					'search' => sanitize_text_field( (string) $request->get_param( 'search' ) ),
					'page'   => max( 1, min( 100, (int) $request->get_param( 'page' ) ) ),
				)
			)
		);
	}

	/**
	 * GET /admin/photocard/dates?d=YYYY-MM-DD&offset=-1|0|1
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_dates( $request ) {
		if ( ! function_exists( 'meeha_studio_dates' ) ) {
			return self::no_studio();
		}
		$d = (string) $request->get_param( 'd' );
		if ( '' !== $d && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ) {
			return new WP_Error( 'nros_bad_request', 'তারিখ সঠিক নয়।', array( 'status' => 400 ) );
		}
		$o = $request->get_param( 'offset' );
		return rest_ensure_response( meeha_studio_dates( '' === $d ? null : $d, null === $o || '' === $o ? null : max( -2, min( 2, (int) $o ) ) ) );
	}

	/**
	 * GET /admin/photocard/file?id=123[&size=large] | ?url=…
	 *
	 * Streams one of this site's images to the app: the canvas may only read
	 * pixels it got with CORS, and the uploads folder sends no CORS headers.
	 * Only image files under uploads/ or the active theme are served.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_Error|void
	 */
	public static function stream_file( $request ) {
		$id   = (int) $request->get_param( 'id' );
		$file = '';
		if ( $id ) {
			if ( 'attachment' !== get_post_type( $id ) || 0 !== strpos( (string) get_post_mime_type( $id ), 'image/' ) ) {
				return new WP_Error( 'nros_not_found', 'ছবিটি পাওয়া যায়নি।', array( 'status' => 404 ) );
			}
			$size = sanitize_key( (string) $request->get_param( 'size' ) );
			if ( $size && 'full' !== $size ) {
				$src = wp_get_attachment_image_src( $id, $size );
				// The sized copy only when it is still big enough for a 1080 card.
				if ( $src && max( (int) $src[1], (int) $src[2] ) >= 1080 ) {
					$file = self::local_path( $src[0] );
				}
			}
			if ( '' === $file ) {
				$file = (string) get_attached_file( $id );
			}
		} else {
			$file = self::local_path( (string) $request->get_param( 'url' ) );
		}
		$ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
		if ( '' === $file || ! is_readable( $file ) || ! in_array( $ext, self::IMAGE_EXTS, true ) ) {
			return new WP_Error( 'nros_not_found', 'ছবিটি পাওয়া যায়নি।', array( 'status' => 404 ) );
		}
		$mime = 'svg' === $ext ? 'image/svg+xml' : ( wp_check_filetype( $file )['type'] ?: 'application/octet-stream' );

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		status_header( 200 );
		header( 'Content-Type: ' . $mime );
		header( 'Content-Length: ' . filesize( $file ) );
		header( 'Cache-Control: private, max-age=86400' );
		header( 'X-Content-Type-Options: nosniff' );
		if ( 'svg' === $ext ) {
			header( "Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'" );
		}
		readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/**
	 * POST /admin/photocard/focus {attachment_id, x, y, zoom}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function save_focus( $request ) {
		self::as_user( $request );
		$id = (int) $request->get_param( 'attachment_id' );
		if ( ! $id || 'attachment' !== get_post_type( $id ) || 0 !== strpos( (string) get_post_mime_type( $id ), 'image/' ) ) {
			return new WP_Error( 'nros_not_found', 'ছবিটি পাওয়া যায়নি।', array( 'status' => 404 ) );
		}
		$x    = max( 0, min( 100, round( (float) $request->get_param( 'x' ), 1 ) ) );
		$y    = max( 0, min( 100, round( (float) $request->get_param( 'y' ), 1 ) ) );
		$zoom = max( 1, min( 3, round( (float) ( $request->get_param( 'zoom' ) ?: 1 ), 2 ) ) );
		if ( 50.0 === (float) $x && 50.0 === (float) $y && 1.0 === (float) $zoom ) {
			delete_post_meta( $id, self::FOCUS_META ); // centre = the default: nothing to keep.
		} else {
			update_post_meta( $id, self::FOCUS_META, array( 'x' => $x, 'y' => $y, 'zoom' => $zoom ) );
		}
		return rest_ensure_response(
			array(
				'ok'    => true,
				'focus' => self::focus_of( $id ),
			)
		);
	}

	/**
	 * POST /admin/photocard/save (multipart: file, type, title, post_id)
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function save_card( $request ) {
		$user  = self::as_user( $request );
		$files = $request->get_file_params();
		$file  = isset( $files['file'] ) ? $files['file'] : array();
		if ( empty( $file ) || UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return new WP_Error( 'nros_missing_file', 'কার্ডের ছবি পৌঁছায়নি — আবার চেষ্টা করুন।', array( 'status' => 400 ) );
		}
		if ( (int) $file['size'] > self::MAX_BYTES ) {
			return new WP_Error( 'nros_file_too_large', 'কার্ডের ফাইল ৮ এমবির বেশি।', array( 'status' => 400 ) );
		}
		$finfo = finfo_open( FILEINFO_MIME_TYPE );
		$mime  = finfo_file( $finfo, $file['tmp_name'] );
		finfo_close( $finfo );
		if ( ! in_array( $mime, array( 'image/png', 'image/jpeg' ), true ) ) {
			return new WP_Error( 'nros_bad_file_type', 'শুধু PNG বা JPG কার্ড রাখা যায়।', array( 'status' => 400 ) );
		}
		$type    = sanitize_key( (string) $request->get_param( 'type' ) );
		$title   = sanitize_text_field( (string) $request->get_param( 'title' ) );
		$post_id = (int) $request->get_param( 'post_id' );
		if ( $post_id && ( ! get_post( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) ) {
			$post_id = 0;
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 180 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		wp_raise_memory_limit( 'image' );

		$_FILES['nros_card_upload'] = $file;
		$id = media_handle_upload(
			'nros_card_upload',
			$post_id,
			array( 'post_title' => '' !== $title ? mb_substr( $title, 0, 180 ) : 'ফটোকার্ড' )
		);
		unset( $_FILES['nros_card_upload'] );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		update_post_meta( $id, '_meeha_card_type', '' !== $type ? $type : 'news' );
		update_post_meta( $id, '_nros_uploaded_by', $user ? $user->ID : 0 );
		if ( $post_id ) {
			update_post_meta( $id, '_meeha_card_post', $post_id );
		}
		$alt = sanitize_text_field( (string) $request->get_param( 'alt' ) );
		if ( '' !== $alt ) {
			update_post_meta( $id, '_wp_attachment_image_alt', mb_substr( $alt, 0, 180 ) );
		}
		return new WP_REST_Response( self::made_row( get_post( $id ) ), 201 );
	}

	/**
	 * One kept card.
	 *
	 * @param WP_Post $a Attachment.
	 * @return array
	 */
	protected static function made_row( $a ) {
		$th = wp_get_attachment_image_src( $a->ID, 'medium' );
		$by = get_userdata( (int) $a->post_author );
		return array(
			'id'      => (int) $a->ID,
			'url'     => (string) wp_get_attachment_url( $a->ID ),
			'thumb'   => $th ? (string) $th[0] : (string) wp_get_attachment_url( $a->ID ),
			'type'    => (string) get_post_meta( $a->ID, '_meeha_card_type', true ),
			'title'   => html_entity_decode( get_the_title( $a ), ENT_QUOTES, 'UTF-8' ),
			'post_id' => (int) get_post_meta( $a->ID, '_meeha_card_post', true ) ?: null,
			'by'      => $by ? $by->display_name : '',
			'date'    => $a->post_date,
		);
	}

	/**
	 * GET /admin/photocard/made?type=&page=
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function get_made( $request ) {
		$type = sanitize_key( (string) $request->get_param( 'type' ) );
		$meta = '' !== $type ? array( 'key' => '_meeha_card_type', 'value' => $type ) : array( 'key' => '_meeha_card_type', 'compare' => 'EXISTS' );
		$q    = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => self::MADE_PAGE,
				'paged'          => max( 1, (int) $request->get_param( 'page' ) ),
				'meta_query'     => array( $meta ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		return rest_ensure_response(
			array(
				'items' => array_map( array( __CLASS__, 'made_row' ), $q->posts ),
				'total' => (int) $q->found_posts,
				'pages' => (int) $q->max_num_pages,
			)
		);
	}

	/**
	 * POST /admin/photocard/frames (multipart: file, type, size, label)
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function upload_frame( $request ) {
		self::as_user( $request );
		if ( ! function_exists( 'meeha_studio_store_frame' ) ) {
			return self::no_studio();
		}
		if ( ! meeha_studio_can_upload_frames() ) {
			return new WP_Error( 'nros_forbidden', 'ফ্রেম আপলোডের অনুমতি নেই (মিহা থিম › ফটোকার্ড সেটিংসে রোল চালু করা যায়)।', array( 'status' => 403 ) );
		}
		$files = $request->get_file_params();
		if ( empty( $files['file'] ) ) {
			return new WP_Error( 'nros_missing_file', 'কোনো ফাইল পাওয়া যায়নি।', array( 'status' => 400 ) );
		}
		$row = meeha_studio_store_frame( $files['file'], (string) $request->get_param( 'type' ), (string) $request->get_param( 'size' ), (string) $request->get_param( 'label' ) );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$pub            = meeha_studio_frame_public( $row );
		$pub['warning'] = isset( $row['warning'] ) ? $row['warning'] : '';
		return new WP_REST_Response( $pub, 201 );
	}

	/**
	 * DELETE /admin/photocard/frames/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_frame( $request ) {
		self::as_user( $request );
		if ( ! function_exists( 'meeha_studio_delete_frame' ) ) {
			return self::no_studio();
		}
		$r = meeha_studio_delete_frame( (string) $request->get_param( 'id' ) );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		return rest_ensure_response( array( 'deleted' => true ) );
	}
}
