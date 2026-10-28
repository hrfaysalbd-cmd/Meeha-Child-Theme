<?php
/**
 * NROS_Videos — "ভিডিও নিউজ" (video news): the `meeha_video` post type and
 * its REST API.
 *
 * - Correspondents submit their own videos (an uploaded file or a YouTube
 *   link) for review, exactly like articles: draft → pending → (staff)
 *   publish, with the same reject/resubmit rules and the same "published
 *   means locked" rule (409).
 * - Staff (Editor/Publisher/Administrator) manage every video, publish
 *   directly, reject with a reason, schedule (date) / re-credit (author)
 *   and trash — the last three gated by the Publisher's Editor
 *   permissions, same as articles (NROS_Core::staff_can()).
 * - Public, unauthenticated endpoints feed the reels player on meeha.news
 *   (list, view/like/share counters, comments). They are rate-limited per
 *   IP (hashed, transients) and — like every nros/v1 route — never cached.
 *
 * Engagement uses the SAME meta keys the theme uses for articles
 * (_meeha_views, _meeha_reactions, _meeha_share_counts, native comments),
 * so NROS_Analytics counts videos with zero special-casing. SEO fields are
 * written to Rank Math's own meta keys, as NROS_Admin_Posts does.
 *
 * The post type is registered here (not by the theme) so videos survive a
 * theme switch; `show_in_rest` is false because this API is the only one
 * the apps use and core's /wp/v2 would bypass the review rules.
 *
 * @package NewsroomOS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NROS_Videos {

	const POST_TYPE = 'meeha_video';

	/**
	 * Bump to make every install flush rewrite rules once more (e.g. if the
	 * rewrite slug ever changes). Compared against the stored option in
	 * maybe_flush_rewrite_rules().
	 */
	const REWRITE_VERSION = '1';

	const LIST_MAX        = 200;
	const PUBLIC_PAGE_MAX = 20;
	const COMMENTS_PAGE   = 20;
	const EXCERPT_MAX     = 300;
	const COMMENT_MAX     = 2000;

	/**
	 * Public API: requests per IP per minute, across all public video
	 * routes. Generous on purpose: Bangladeshi mobile operators put many
	 * subscribers behind one carrier-NAT address. Filterable
	 * (`nros_video_public_rate_per_minute`).
	 */
	const PUBLIC_RATE_PER_MINUTE = 300;

	/** Public API: comments per IP per 10 minutes (filter `nros_video_comment_rate_per_10_min`). */
	const COMMENT_RATE_PER_10_MIN = 10;

	const SHARE_NETWORKS = array( 'facebook', 'whatsapp', 'x', 'copy' );

	const SOURCES      = array( 'upload', 'youtube' );
	const ORIENTATIONS = array( 'portrait', 'landscape' );

	/**
	 * Hooks: post type on init, one-time rewrite flush after it, routes.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_action( 'init', array( __CLASS__, 'maybe_flush_rewrite_rules' ), 99 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		// After core's cookie check (priority 100).
		add_filter( 'rest_authentication_errors', array( __CLASS__, 'tolerate_stale_nonce' ), 101 );
	}

	/**
	 * A logged-in reader whose page carries an expired X-WP-Nonce (e.g. a
	 * tab left open overnight) would otherwise get core's English 403
	 * "Cookie check failed" on EVERY public video call — views, likes and
	 * the feed itself would stop working. For the public video routes
	 * only, treat that visitor as anonymous instead; nothing there needs a
	 * login, and comments then simply ask for a name like any guest.
	 *
	 * @param WP_Error|null|true $result Authentication result so far.
	 * @return WP_Error|null|true
	 */
	public static function tolerate_stale_nonce( $result ) {
		if ( ! is_wp_error( $result ) || 'rest_cookie_invalid_nonce' !== $result->get_error_code() ) {
			return $result;
		}
		$route = isset( $GLOBALS['wp']->query_vars['rest_route'] ) ? (string) $GLOBALS['wp']->query_vars['rest_route'] : '';
		if ( 0 !== strpos( $route, '/' . NROS_API_NS . '/public/videos' ) ) {
			return $result;
		}
		wp_set_current_user( 0 );
		return null;
	}

	// -------------------------------------------------------------------------
	// Post type + rewrite
	// -------------------------------------------------------------------------

	/**
	 * Registers `meeha_video` — public at /video/{slug}/, no archive (the
	 * reels page is a normal WP page carrying the [meeha_video_reels]
	 * shortcode), core categories optional.
	 */
	public static function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'               => 'ভিডিও নিউজ',
					'singular_name'      => 'ভিডিও',
					'menu_name'          => 'ভিডিও নিউজ',
					'add_new'            => 'নতুন ভিডিও',
					'add_new_item'       => 'নতুন ভিডিও যোগ করুন',
					'edit_item'          => 'ভিডিও সম্পাদনা',
					'new_item'           => 'নতুন ভিডিও',
					'view_item'          => 'ভিডিও দেখুন',
					'search_items'       => 'ভিডিও খুঁজুন',
					'not_found'          => 'কোনো ভিডিও পাওয়া যায়নি',
					'not_found_in_trash' => 'ট্র্যাশে কোনো ভিডিও নেই',
					'all_items'          => 'সব ভিডিও',
				),
				'public'          => true,
				'has_archive'     => false,
				'rewrite'         => array(
					'slug'       => 'video',
					'with_front' => false,
				),
				'supports'        => array( 'title', 'excerpt', 'thumbnail', 'comments', 'author' ),
				'taxonomies'      => array( 'category' ),
				'show_in_rest'    => false,
				'menu_icon'       => 'dashicons-video-alt3',
				'menu_position'   => 6,
				'capability_type' => 'post',
				'map_meta_cap'    => true,
			)
		);
	}

	/**
	 * Called on plugin activation: forget the stored rewrite version so the
	 * next `init` (where the post type exists) flushes once.
	 */
	public static function schedule_rewrite_flush() {
		delete_option( 'nros_video_rewrite_version' );
	}

	/**
	 * Flushes rewrite rules exactly once per REWRITE_VERSION — on the first
	 * request after activation or after this code is deployed onto an
	 * already-active install (where the activation hook never re-runs).
	 * Flushing is expensive, so never on every request.
	 */
	public static function maybe_flush_rewrite_rules() {
		if ( get_option( 'nros_video_rewrite_version' ) === self::REWRITE_VERSION ) {
			return;
		}
		flush_rewrite_rules( false );
		update_option( 'nros_video_rewrite_version', self::REWRITE_VERSION, true );
	}

	// -------------------------------------------------------------------------
	// Routes
	// -------------------------------------------------------------------------

	/**
	 * Registers REST routes under nros/v1.
	 */
	public static function register_routes() {
		// Correspondent — own videos only.
		register_rest_route(
			NROS_API_NS,
			'/videos',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'list_own' ),
					'permission_callback' => array( 'NROS_Core', 'require_correspondent' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'create_own' ),
					'permission_callback' => array( 'NROS_Core', 'require_correspondent' ),
				),
			)
		);

		register_rest_route(
			NROS_API_NS,
			'/videos/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_own' ),
					'permission_callback' => array( 'NROS_Core', 'require_correspondent' ),
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( __CLASS__, 'update_own' ),
					'permission_callback' => array( 'NROS_Core', 'require_correspondent' ),
				),
			)
		);

		// Staff — every video.
		register_rest_route(
			NROS_API_NS,
			'/admin/videos',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'admin_list' ),
					'permission_callback' => array( 'NROS_Core', 'require_staff' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'admin_create' ),
					'permission_callback' => array( 'NROS_Core', 'require_staff' ),
				),
			)
		);

		register_rest_route(
			NROS_API_NS,
			'/admin/videos/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'admin_get' ),
					'permission_callback' => array( 'NROS_Core', 'require_staff' ),
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( __CLASS__, 'admin_update' ),
					'permission_callback' => array( 'NROS_Core', 'require_staff' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( __CLASS__, 'admin_delete' ),
					'permission_callback' => array( 'NROS_Core', 'require_staff' ),
				),
			)
		);

		register_rest_route(
			NROS_API_NS,
			'/admin/videos/(?P<id>\d+)/reject',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'admin_reject' ),
				'permission_callback' => array( 'NROS_Core', 'require_staff' ),
			)
		);

		// Public — reels player on meeha.news. No auth; the permission
		// callback is the per-IP rate limiter.
		register_rest_route(
			NROS_API_NS,
			'/public/videos',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'public_list' ),
				'permission_callback' => array( __CLASS__, 'public_gate' ),
			)
		);

		$counters = array(
			'view'  => 'public_view',
			'like'  => 'public_like',
			'share' => 'public_share',
		);
		foreach ( $counters as $path => $callback ) {
			register_rest_route(
				NROS_API_NS,
				'/public/videos/(?P<id>\d+)/' . $path,
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, $callback ),
					'permission_callback' => array( __CLASS__, 'public_gate' ),
				)
			);
		}

		register_rest_route(
			NROS_API_NS,
			'/public/videos/(?P<id>\d+)/comments',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'public_comments' ),
					'permission_callback' => array( __CLASS__, 'public_gate' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'public_comment_create' ),
					'permission_callback' => array( __CLASS__, 'public_gate' ),
				),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Shared helpers
	// -------------------------------------------------------------------------

	/**
	 * Extracts the 11-character YouTube video id from the URL forms people
	 * actually paste: youtube.com/watch?v=, youtu.be/, /shorts/, /embed/,
	 * /live/ (m./www./music. hosts and youtube-nocookie.com too). Anything
	 * else — including non-http(s) schemes — is null, so a stored
	 * `video_url` is always a real YouTube link.
	 *
	 * @param string $url URL.
	 * @return string|null
	 */
	public static function parse_youtube_id( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return null;
		}
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			$url = 'https://' . $url;
		}

		$parts = wp_parse_url( $url );
		if ( ! $parts || empty( $parts['host'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return null;
		}

		$host = preg_replace( '/^(www\.|m\.|music\.)/', '', strtolower( $parts['host'] ) );
		$path = isset( $parts['path'] ) ? $parts['path'] : '';
		$id   = null;

		if ( 'youtu.be' === $host ) {
			$segments = explode( '/', trim( $path, '/' ) );
			$id       = $segments[0];
		} elseif ( in_array( $host, array( 'youtube.com', 'youtube-nocookie.com' ), true ) ) {
			if ( '/watch' === rtrim( $path, '/' ) ) {
				parse_str( isset( $parts['query'] ) ? $parts['query'] : '', $query );
				$id = isset( $query['v'] ) && is_string( $query['v'] ) ? $query['v'] : null;
			} elseif ( preg_match( '~^/(?:shorts|embed|live|v)/([^/?#]+)~', $path, $m ) ) {
				$id = $m[1];
			}
		}

		return ( is_string( $id ) && preg_match( '/^[A-Za-z0-9_-]{11}$/', $id ) ) ? $id : null;
	}

	/**
	 * Whether an attachment is a video file (any video/* MIME).
	 *
	 * @param int $attachment_id Attachment id.
	 * @return bool
	 */
	protected static function is_video_attachment( $attachment_id ) {
		return $attachment_id > 0
			&& 'attachment' === get_post_type( $attachment_id )
			&& 0 === strpos( (string) get_post_mime_type( $attachment_id ), 'video/' );
	}

	/**
	 * Content fingerprint used by the reject → "change something before
	 * resubmitting" rule (same idea as NROS_Submissions::content_fingerprint()
	 * for articles): title, description and the video itself.
	 *
	 * @param string $title       Title.
	 * @param string $excerpt     Excerpt.
	 * @param string $source      upload|youtube.
	 * @param string $video_ref   Attachment id or YouTube URL.
	 * @return string
	 */
	protected static function fingerprint( $title, $excerpt, $source, $video_ref ) {
		return md5( $title . "\x00" . $excerpt . "\x00" . $source . "\x00" . $video_ref );
	}

	/**
	 * Fingerprint of a stored video post.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	protected static function post_fingerprint( $post ) {
		$source = (string) get_post_meta( $post->ID, '_meeha_video_source', true );
		$ref    = 'upload' === $source ? (string) (int) get_post_meta( $post->ID, '_meeha_video_attachment_id', true ) : (string) get_post_meta( $post->ID, '_meeha_video_url', true );
		return self::fingerprint( $post->post_title, $post->post_excerpt, $source, $ref );
	}

	/**
	 * Engagement numbers for one video, in the public `stats` shape. Likes
	 * are the sum of every reaction type (videos only ever get `like`, but
	 * this matches how analytics count reactions on articles).
	 *
	 * @param int $post_id Post id.
	 * @return array{views:int, likes:int, comments:int, shares:int}
	 */
	protected static function stats( $post_id ) {
		$e = NROS_Analytics::live_engagement( $post_id );
		return array(
			'views'    => $e['views'],
			'likes'    => $e['reactions'],
			'comments' => $e['comments'],
			'shares'   => $e['shares'],
		);
	}

	/**
	 * Playable URL + parsed YouTube id + thumbnail for a video post.
	 *
	 * @param WP_Post $post Post.
	 * @return array{source:string, video_url:string, attachment_id:int|null, youtube_id:string|null, thumbnail:string|null, thumbnail_id:int|null}
	 */
	protected static function media_fields( $post ) {
		$source = (string) get_post_meta( $post->ID, '_meeha_video_source', true );
		$source = in_array( $source, self::SOURCES, true ) ? $source : 'upload';

		$attachment_id = (int) get_post_meta( $post->ID, '_meeha_video_attachment_id', true );
		$youtube_url   = (string) get_post_meta( $post->ID, '_meeha_video_url', true );
		$youtube_id    = 'youtube' === $source ? self::parse_youtube_id( $youtube_url ) : null;

		if ( 'upload' === $source ) {
			$video_url = $attachment_id ? (string) wp_get_attachment_url( $attachment_id ) : '';
		} else {
			$video_url = $youtube_url;
		}

		$thumbnail_id = (int) get_post_thumbnail_id( $post->ID );
		$thumbnail    = $thumbnail_id ? wp_get_attachment_image_url( $thumbnail_id, 'large' ) : false;
		if ( ! $thumbnail && $youtube_id ) {
			// YouTube serves a thumbnail for every public video at this URL.
			$thumbnail = 'https://i.ytimg.com/vi/' . $youtube_id . '/hqdefault.jpg';
		}

		return array(
			'source'        => $source,
			'video_url'     => $video_url,
			'attachment_id' => ( 'upload' === $source && $attachment_id ) ? $attachment_id : null,
			'youtube_id'    => $youtube_id,
			'thumbnail'     => $thumbnail ? $thumbnail : null,
			'thumbnail_id'  => $thumbnail_id ? $thumbnail_id : null,
		);
	}

	/**
	 * Full `Video` shape (contract §2) for the correspondent and staff apps.
	 *
	 * @param WP_Post $post Post.
	 * @return array
	 */
	public static function shape( $post ) {
		$author       = get_userdata( $post->post_author );
		$media        = self::media_fields( $post );
		$category_ids = wp_get_post_terms( $post->ID, 'category', array( 'fields' => 'ids' ) );
		$category_ids = is_wp_error( $category_ids ) ? array() : $category_ids;
		$orientation  = (string) get_post_meta( $post->ID, '_meeha_video_orientation', true );
		$duration     = get_post_meta( $post->ID, '_meeha_video_duration', true );
		$rejected     = (bool) get_post_meta( $post->ID, '_nros_rejected', true );
		$payment      = NROS_Ledger::video_payment_info( $post->ID );

		return array(
			'id'                  => $post->ID,
			'title'               => $post->post_title,
			'excerpt'             => $post->post_excerpt,
			'slug'                => $post->post_name,
			'status'              => $post->post_status,
			'link'                => (string) get_permalink( $post ),
			'date'                => $post->post_date,
			// Round 13 — draft/pending video whose date is not set yet (publishing stamps "now").
			'date_floating'       => NROS_Admin_Posts::is_date_floating( $post ),
			'modified'            => $post->post_modified,
			'author'              => array(
				'id'     => (int) $post->post_author,
				'name'   => $author ? $author->display_name : '',
				'avatar' => $author ? NROS_Media::avatar_url( $author->ID ) : '',
			),
			'source'              => $media['source'],
			'video_url'           => $media['video_url'],
			'video_attachment_id' => $media['attachment_id'],
			'youtube_id'          => $media['youtube_id'],
			'orientation'         => in_array( $orientation, self::ORIENTATIONS, true ) ? $orientation : 'portrait',
			'duration'            => '' === $duration ? null : (int) $duration,
			'thumbnail'           => $media['thumbnail'],
			'thumbnail_id'        => $media['thumbnail_id'],
			'category_id'         => $category_ids ? (int) $category_ids[0] : null,
			'seo'                 => array(
				'title'         => (string) get_post_meta( $post->ID, 'rank_math_title', true ),
				'description'   => (string) get_post_meta( $post->ID, 'rank_math_description', true ),
				'focus_keyword' => (string) get_post_meta( $post->ID, 'rank_math_focus_keyword', true ),
			),
			'stats'               => self::stats( $post->ID ),
			// Round 12 — per-video payment (null/null when unset; 'pending' = auto-applied default
			// rate awaiting the Publisher's confirmation, 'approved' = set/confirmed).
			'payment'             => $payment['amount'],
			'payment_status'      => $payment['status'],
			'rejected'            => $rejected,
			'reject_reason'       => $rejected ? (string) get_post_meta( $post->ID, '_nros_reject_reason', true ) : null,
		);
	}

	/**
	 * Public `PublicVideo` shape — no status/SEO/author id/rejection data.
	 *
	 * @param WP_Post $post Post.
	 * @return array
	 */
	public static function shape_public( $post ) {
		$author      = get_userdata( $post->post_author );
		$media       = self::media_fields( $post );
		$orientation = (string) get_post_meta( $post->ID, '_meeha_video_orientation', true );

		return array(
			'id'          => $post->ID,
			'title'       => $post->post_title,
			'excerpt'     => $post->post_excerpt,
			'link'        => (string) get_permalink( $post ),
			'date_human'  => NROS_Core::bn_time_ago( $post->post_date ),
			'author'      => array(
				'name'   => $author ? $author->display_name : '',
				'avatar' => $author ? NROS_Media::avatar_url( $author->ID ) : '',
			),
			'source'      => $media['source'],
			'video_url'   => $media['video_url'],
			'youtube_id'  => $media['youtube_id'],
			'orientation' => in_array( $orientation, self::ORIENTATIONS, true ) ? $orientation : 'portrait',
			'thumbnail'   => $media['thumbnail'],
			'stats'       => self::stats( $post->ID ),
		);
	}

	/**
	 * Any non-trashed video by id (staff).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_Post|WP_Error
	 */
	protected static function video_or_error( $request ) {
		$post = get_post( (int) $request->get_param( 'id' ) );
		if ( ! $post || self::POST_TYPE !== $post->post_type || in_array( $post->post_status, array( 'trash', 'auto-draft', 'inherit' ), true ) ) {
			return new WP_Error( 'nros_not_found', 'ভিডিও পাওয়া যায়নি।', array( 'status' => 404 ) );
		}
		return $post;
	}

	/**
	 * The requesting correspondent's own video, else 404 (not 403, so ids
	 * of other people's videos are not confirmed to exist).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_Post|WP_Error
	 */
	protected static function own_video_or_error( $request ) {
		$post = self::video_or_error( $request );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$user = NROS_Auth::get_user_from_request( $request );
		if ( (int) $post->post_author !== (int) $user->ID ) {
			return new WP_Error( 'nros_not_found', 'ভিডিও পাওয়া যায়নি।', array( 'status' => 404 ) );
		}
		return $post;
	}

	/**
	 * A published, non-password-protected video for the public endpoints.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_Post|WP_Error
	 */
	protected static function public_video_or_error( $request ) {
		$post = get_post( (int) $request->get_param( 'id' ) );
		if ( ! $post || self::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status || '' !== $post->post_password ) {
			return new WP_Error( 'nros_not_found', 'ভিডিও পাওয়া যায়নি।', array( 'status' => 404 ) );
		}
		return $post;
	}

	// -------------------------------------------------------------------------
	// Create / update (shared by correspondent and staff)
	// -------------------------------------------------------------------------

	/**
	 * Validates a VideoInput body against the current state (if any) and
	 * writes it. ALL validation happens before the first write, so a 4xx
	 * never leaves a half-saved video.
	 *
	 * Role rules:
	 * - correspondent: status only draft|pending (publish → 403); `date`
	 *   and `author_id` are ignored (author is always themselves); only
	 *   their own uploaded video/thumbnail attachments; a published or
	 *   scheduled video is locked (409); suspended submitters are blocked;
	 *   a rejected video must change before going back to review.
	 * - staff: draft|pending|publish; date/author via
	 *   NROS_Admin_Posts::apply_date_author() (Editor permissions).
	 *
	 * @param WP_REST_Request $request  Request.
	 * @param WP_User          $user     Acting user.
	 * @param WP_Post|null     $post     Existing video, or null to create.
	 * @param bool             $is_staff Staff route.
	 * @return WP_REST_Response|WP_Error
	 */
	protected static function save( $request, $user, $post, $is_staff ) {
		$is_new = null === $post;

		if ( ! $is_staff && ! $is_new && in_array( $post->post_status, array( 'publish', 'future' ), true ) ) {
			return new WP_Error( 'nros_locked', 'প্রকাশিত ভিডিও সরাসরি সম্পাদনা করা যায় না।', array( 'status' => 409 ) );
		}

		$args        = array( 'post_type' => self::POST_TYPE );
		$meta_set    = array();
		$meta_delete = array();

		// --- Title / description / slug ---------------------------------
		$title = $request->get_param( 'title' );
		if ( null !== $title ) {
			$title = sanitize_text_field( (string) $title );
			if ( '' === $title ) {
				return new WP_Error( 'nros_bad_request', 'শিরোনাম আবশ্যক।', array( 'status' => 400 ) );
			}
			$args['post_title'] = $title;
		} elseif ( $is_new ) {
			return new WP_Error( 'nros_bad_request', 'শিরোনাম আবশ্যক।', array( 'status' => 400 ) );
		}
		$final_title = isset( $args['post_title'] ) ? $args['post_title'] : $post->post_title;

		$excerpt = $request->get_param( 'excerpt' );
		if ( null !== $excerpt ) {
			$excerpt = sanitize_textarea_field( (string) $excerpt );
			if ( mb_strlen( $excerpt ) > self::EXCERPT_MAX ) {
				return new WP_Error( 'nros_bad_request', 'সংক্ষিপ্ত বিবরণ সর্বোচ্চ ' . NROS_Core::bn_digits( self::EXCERPT_MAX ) . ' অক্ষরের হতে পারে।', array( 'status' => 400 ) );
			}
			$args['post_excerpt'] = $excerpt;
		}
		$final_excerpt = isset( $args['post_excerpt'] ) ? $args['post_excerpt'] : ( $post ? $post->post_excerpt : '' );

		$slug = $request->get_param( 'slug' );
		if ( null !== $slug && '' !== trim( (string) $slug ) ) {
			$args['post_name'] = sanitize_title( (string) $slug );
		}

		// --- The video itself ------------------------------------------
		$current_source = $post ? (string) get_post_meta( $post->ID, '_meeha_video_source', true ) : '';
		$source         = $request->get_param( 'source' );
		$att_param      = $request->get_param( 'video_attachment_id' );
		$url_param      = $request->get_param( 'video_url' );

		if ( null !== $source ) {
			$source = sanitize_key( (string) $source );
			if ( ! in_array( $source, self::SOURCES, true ) ) {
				return new WP_Error( 'nros_bad_request', 'ভিডিওর উৎস সঠিক নয় (upload অথবা youtube)।', array( 'status' => 400 ) );
			}
		} elseif ( '' !== $current_source ) {
			$source = $current_source;
		} elseif ( null !== $url_param && '' !== trim( (string) $url_param ) ) {
			$source = 'youtube';
		} else {
			$source = 'upload';
		}

		// Round 12 — YouTube videos are staff-only. A correspondent may still edit an existing
		// YouTube video of theirs (source and link stay untouched), but can't switch a video to
		// YouTube, create one, or change its link.
		if ( ! $is_staff ) {
			$stored_url = $post ? (string) get_post_meta( $post->ID, '_meeha_video_url', true ) : '';
			$new_url    = null !== $url_param ? trim( (string) $url_param ) : '';
			$url_change = '' !== $new_url && $new_url !== $stored_url && esc_url_raw( $new_url ) !== $stored_url;
			$to_youtube = 'youtube' === $source && 'youtube' !== $current_source;
			$yt_link    = $url_change && ( 'youtube' === $source || self::parse_youtube_id( $new_url ) );
			if ( $to_youtube || $yt_link ) {
				return new WP_Error( 'nros_youtube_staff_only', 'ইউটিউব ভিডিও শুধু পাবলিশার/এডিটর দিতে পারেন। ভিডিও আপলোড করুন।', array( 'status' => 403 ) );
			}
		}

		$video_ref = '';
		if ( 'upload' === $source ) {
			$att_id = null !== $att_param ? (int) $att_param : ( $post ? (int) get_post_meta( $post->ID, '_meeha_video_attachment_id', true ) : 0 );
			if ( null !== $att_param && $att_id > 0 ) {
				if ( ! self::is_video_attachment( $att_id ) || ( ! $is_staff && ! NROS_Media::uploaded_by( $att_id, $user->ID ) ) ) {
					return new WP_Error( 'nros_bad_request', 'সঠিক ভিডিও ফাইল দিন।', array( 'status' => 400 ) );
				}
			}
			$has_video = self::is_video_attachment( $att_id );
			if ( $att_id > 0 ) {
				$meta_set['_meeha_video_attachment_id'] = $att_id;
				$video_ref                               = (string) $att_id;
			} else {
				$meta_delete[] = '_meeha_video_attachment_id';
			}
			$meta_delete[] = '_meeha_video_url';
		} else {
			$yt_url = null !== $url_param ? trim( (string) $url_param ) : ( $post ? (string) get_post_meta( $post->ID, '_meeha_video_url', true ) : '' );
			if ( null !== $url_param && '' !== $yt_url && ! self::parse_youtube_id( $yt_url ) ) {
				return new WP_Error( 'nros_bad_request', 'সঠিক ইউটিউব লিংক দিন।', array( 'status' => 400 ) );
			}
			$has_video = (bool) self::parse_youtube_id( $yt_url );
			if ( '' !== $yt_url ) {
				$meta_set['_meeha_video_url'] = esc_url_raw( $yt_url );
				$video_ref                     = $meta_set['_meeha_video_url'];
			} else {
				$meta_delete[] = '_meeha_video_url';
			}
			$meta_delete[] = '_meeha_video_attachment_id';
		}
		$meta_set['_meeha_video_source'] = $source;

		// --- Presentation ------------------------------------------------
		$orientation = $request->get_param( 'orientation' );
		if ( null !== $orientation ) {
			if ( ! in_array( $orientation, self::ORIENTATIONS, true ) ) {
				return new WP_Error( 'nros_bad_request', 'ভিডিওর দিক সঠিক নয় (portrait অথবা landscape)।', array( 'status' => 400 ) );
			}
			$meta_set['_meeha_video_orientation'] = $orientation;
		} elseif ( $is_new ) {
			$meta_set['_meeha_video_orientation'] = 'portrait';
		}

		if ( $request->has_param( 'duration' ) ) {
			$duration = $request->get_param( 'duration' );
			if ( null === $duration || '' === $duration ) {
				$meta_delete[] = '_meeha_video_duration';
			} else {
				$meta_set['_meeha_video_duration'] = min( DAY_IN_SECONDS, max( 0, (int) round( (float) $duration ) ) );
			}
		}

		$thumb_op = null; // null = untouched, 0 = remove, >0 = set.
		if ( $request->has_param( 'thumbnail_id' ) ) {
			$thumb_id = (int) $request->get_param( 'thumbnail_id' );
			if ( $thumb_id > 0 && ( ! wp_attachment_is_image( $thumb_id ) || ( ! $is_staff && ! NROS_Media::uploaded_by( $thumb_id, $user->ID ) ) ) ) {
				return new WP_Error( 'nros_bad_request', 'সঠিক থাম্বনেইল ছবি দিন।', array( 'status' => 400 ) );
			}
			$thumb_op = max( 0, $thumb_id );
		}

		$category_op = null; // null = untouched, 0 = clear, >0 = set.
		if ( $request->has_param( 'category_id' ) ) {
			$category_id = (int) $request->get_param( 'category_id' );
			if ( $category_id > 0 && ! term_exists( $category_id, 'category' ) ) {
				return new WP_Error( 'nros_bad_request', 'বিভাগ পাওয়া যায়নি।', array( 'status' => 400 ) );
			}
			$category_op = max( 0, $category_id );
		}

		$seo = $request->get_param( 'seo' );
		if ( null !== $seo ) {
			if ( ! is_array( $seo ) ) {
				return new WP_Error( 'nros_bad_request', 'SEO তথ্য সঠিক নয়।', array( 'status' => 400 ) );
			}
			$seo_map = array(
				'title'         => 'rank_math_title',
				'description'   => 'rank_math_description',
				'focus_keyword' => 'rank_math_focus_keyword',
			);
			foreach ( $seo_map as $field => $meta_key ) {
				if ( array_key_exists( $field, $seo ) ) {
					$meta_set[ $meta_key ] = sanitize_text_field( (string) $seo[ $field ] );
				}
			}
		}

		// --- Status -------------------------------------------------------
		$status = $request->get_param( 'status' );
		if ( null !== $status ) {
			$status  = sanitize_key( (string) $status );
			$allowed = $is_staff ? array( 'draft', 'pending', 'publish' ) : array( 'draft', 'pending' );
			if ( ! in_array( $status, $allowed, true ) ) {
				if ( ! $is_staff && 'publish' === $status ) {
					return new WP_Error( 'nros_forbidden', 'সংবাদদাতা সরাসরি প্রকাশ করতে পারেন না — রিভিউতে পাঠান।', array( 'status' => 403 ) );
				}
				return new WP_Error( 'nros_bad_request', 'স্ট্যাটাস সঠিক নয়।', array( 'status' => 400 ) );
			}
			$args['post_status'] = $status;
		} elseif ( $is_new ) {
			// Staff included: an accidental publish is worse than an
			// accidental draft, so publishing is always an explicit choice.
			$args['post_status'] = 'draft';
		}
		$final_status = isset( $args['post_status'] ) ? $args['post_status'] : $post->post_status;
		$old_status   = $post ? $post->post_status : 'new';

		if ( in_array( $final_status, array( 'pending', 'publish', 'future' ), true ) && ( '' === $final_title || ! $has_video ) ) {
			return new WP_Error(
				'nros_bad_request',
				'রিভিউতে পাঠাতে বা প্রকাশ করতে শিরোনাম এবং ভিডিও (আপলোড করা ফাইল অথবা ইউটিউব লিংক) আবশ্যক।',
				array( 'status' => 400 )
			);
		}

		$was_rejected = $post && (bool) get_post_meta( $post->ID, '_nros_rejected', true );

		if ( ! $is_staff ) {
			$args['post_author'] = $user->ID; // Never client-controlled for correspondents.

			$blocked = array_intersect( array( 'account', 'news_submission' ), NROS_Core::active_suspension_types( $user->ID ) );
			if ( $blocked && ( $is_new || ( 'pending' === $final_status && 'pending' !== $old_status ) ) ) {
				return new WP_Error( 'nros_suspended', 'সংবাদ জমাদান সাময়িকভাবে স্থগিত করা হয়েছে।', array( 'status' => 403 ) );
			}

			if ( $was_rejected && 'pending' === $final_status && 'pending' !== $old_status ) {
				$prev_hash = (string) get_post_meta( $post->ID, '_nros_reject_hash', true );
				$next_hash = self::fingerprint( $final_title, $final_excerpt, $source, $video_ref );
				if ( $prev_hash && hash_equals( $prev_hash, $next_hash ) ) {
					return new WP_Error(
						'nros_no_changes',
						'প্রত্যাখ্যানের পর কোনো পরিবর্তন করা হয়নি — শিরোনাম, বিবরণ বা ভিডিও সম্পাদনা করে তারপর আবার জমা দিন।',
						array( 'status' => 409 )
					);
				}
			}
		} else {
			$applied = NROS_Admin_Posts::apply_date_author( $args, $request, $user, $post );
			if ( is_wp_error( $applied ) ) {
				return $applied;
			}
			if ( $is_new && ! isset( $args['post_author'] ) ) {
				$args['post_author'] = $user->ID;
			}
		}

		// --- Write ----------------------------------------------------------
		if ( $is_new ) {
			$post_id = wp_insert_post( $args, true );
		} else {
			$args['ID'] = $post->ID;
			$post_id    = wp_update_post( $args, true );
		}
		if ( is_wp_error( $post_id ) ) {
			return new WP_Error( 'nros_failed', 'ভিডিও সংরক্ষণ করা যায়নি।', array( 'status' => 500 ) );
		}
		$post_id = (int) $post_id;

		foreach ( $meta_set as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}
		foreach ( array_unique( $meta_delete ) as $key ) {
			if ( ! array_key_exists( $key, $meta_set ) ) {
				delete_post_meta( $post_id, $key );
			}
		}

		if ( null !== $thumb_op ) {
			if ( $thumb_op > 0 ) {
				set_post_thumbnail( $post_id, $thumb_op );
			} else {
				delete_post_thumbnail( $post_id );
			}
		}
		if ( null !== $category_op ) {
			wp_set_object_terms( $post_id, $category_op > 0 ? array( $category_op ) : array(), 'category' );
		}

		// WordPress discards post_name on a 'pending' save unless the
		// CURRENT user can publish_posts (REST requests here run as user 0
		// — our bearer auth doesn't log anyone in), so a slug chosen
		// before review would silently vanish. Store the requested slug
		// directly, de-duplicated as if published, so /video/{slug}/ is
		// the one that was asked for.
		if ( isset( $args['post_name'] ) && '' !== $args['post_name'] && get_post_field( 'post_name', $post_id ) !== $args['post_name'] ) {
			global $wpdb;
			$unique = wp_unique_post_slug( $args['post_name'], $post_id, 'publish', self::POST_TYPE, 0 );
			$wpdb->update( $wpdb->posts, array( 'post_name' => $unique ), array( 'ID' => $post_id ) );
			clean_post_cache( $post_id );
		}

		$new_status = get_post_status( $post_id );

		// Leaving draft (resubmitted or published by staff) closes the
		// rejection, same as articles.
		if ( $was_rejected && 'draft' !== $new_status ) {
			delete_post_meta( $post_id, '_nros_rejected' );
			delete_post_meta( $post_id, '_nros_reject_reason' );
			delete_post_meta( $post_id, '_nros_reject_hash' );
		}

		if ( ! $is_staff && 'pending' === $new_status && 'pending' !== $old_status ) {
			/**
			 * Fires when a correspondent sends a video for review.
			 *
			 * @param int $post_id Video id.
			 */
			do_action( 'nros_video_submitted', $post_id );
		}
		if ( 'publish' === $new_status && 'publish' !== $old_status ) {
			// Round 12 — default per-video rate (pending) on a correspondent's uploaded video's
			// first publish; here because the video's meta is only now written.
			NROS_Ledger::maybe_apply_default_video_payment( $post_id );
		}
		if ( $is_staff && 'pending' === $old_status && in_array( $new_status, array( 'publish', 'future' ), true ) ) {
			/**
			 * Fires `nros_video_approved` ( int $post_id ) when a video staff
			 * approved goes live — now, or when WordPress publishes it if it
			 * was approved into the future. Same rule as articles.
			 */
			NROS_Admin_Posts::fire_or_defer_approval( $post_id );
		}

		return new WP_REST_Response( self::shape( get_post( $post_id ) ), $is_new ? 201 : 200 );
	}

	// -------------------------------------------------------------------------
	// Correspondent endpoints
	// -------------------------------------------------------------------------

	/**
	 * GET /videos — own videos, newest first, max LIST_MAX.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function list_own( $request ) {
		$user  = NROS_Auth::get_user_from_request( $request );
		$query = new WP_Query(
			array(
				'post_type'      => self::POST_TYPE,
				'author'         => $user->ID,
				'post_status'    => array( 'draft', 'pending', 'publish', 'future' ),
				'posts_per_page' => self::LIST_MAX,
				'orderby'        => array(
					'date' => 'DESC',
					'ID'   => 'DESC',
				),
				'no_found_rows'  => true,
			)
		);
		return new WP_REST_Response( array_map( array( __CLASS__, 'shape' ), $query->posts ), 200 );
	}

	/**
	 * GET /videos/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_own( $request ) {
		$post = self::own_video_or_error( $request );
		return is_wp_error( $post ) ? $post : new WP_REST_Response( self::shape( $post ), 200 );
	}

	/**
	 * POST /videos — new own video (draft or straight to review).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_own( $request ) {
		return self::save( $request, NROS_Auth::get_user_from_request( $request ), null, false );
	}

	/**
	 * PATCH /videos/{id} — edit own draft/pending video (published → 409).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_own( $request ) {
		$post = self::own_video_or_error( $request );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		return self::save( $request, NROS_Auth::get_user_from_request( $request ), $post, false );
	}

	// -------------------------------------------------------------------------
	// Staff endpoints
	// -------------------------------------------------------------------------

	/**
	 * GET /admin/videos?status=all|publish|pending|draft|future — every
	 * author, newest first, max LIST_MAX.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function admin_list( $request ) {
		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		$single = array( 'publish', 'pending', 'draft', 'future' );

		$query = new WP_Query(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => in_array( $status, $single, true ) ? $status : $single,
				'posts_per_page' => self::LIST_MAX,
				'orderby'        => array(
					'date' => 'DESC',
					'ID'   => 'DESC',
				),
				'no_found_rows'  => true,
			)
		);
		return new WP_REST_Response( array_map( array( __CLASS__, 'shape' ), $query->posts ), 200 );
	}

	/**
	 * GET /admin/videos/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function admin_get( $request ) {
		$post = self::video_or_error( $request );
		return is_wp_error( $post ) ? $post : new WP_REST_Response( self::shape( $post ), 200 );
	}

	/**
	 * POST /admin/videos.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function admin_create( $request ) {
		return self::save( $request, NROS_Auth::get_user_from_request( $request ), null, true );
	}

	/**
	 * PATCH /admin/videos/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function admin_update( $request ) {
		$post = self::video_or_error( $request );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		return self::save( $request, NROS_Auth::get_user_from_request( $request ), $post, true );
	}

	/**
	 * POST /admin/videos/{id}/reject { reason } — back to draft with a
	 * reason the correspondent sees; snapshots a fingerprint so an
	 * unchanged resubmission is refused (see save()). Works on a pending
	 * video and also on a live one (taking it down for correction).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function admin_reject( $request ) {
		$post = self::video_or_error( $request );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( 'draft' === $post->post_status ) {
			return new WP_Error( 'nros_invalid_state', 'ড্রাফট ভিডিও প্রত্যাখ্যান করা যায় না।', array( 'status' => 409 ) );
		}

		$reason = sanitize_textarea_field( (string) $request->get_param( 'reason' ) );

		$result = wp_update_post(
			array(
				'ID'          => $post->ID,
				'post_status' => 'draft',
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'nros_failed', 'ভিডিও প্রত্যাখ্যান করা যায়নি।', array( 'status' => 500 ) );
		}

		update_post_meta( $post->ID, '_nros_rejected', 1 );
		update_post_meta( $post->ID, '_nros_reject_reason', $reason );
		update_post_meta( $post->ID, '_nros_reject_hash', self::post_fingerprint( $post ) );

		/** Round 18 — see nros_submission_rejected. */
		do_action( 'nros_video_rejected', $post->ID, $reason );

		return new WP_REST_Response( self::shape( get_post( $post->ID ) ), 200 );
	}

	/**
	 * DELETE /admin/videos/{id} — to trash (restorable in wp-admin);
	 * Editor needs the Publisher's delete_posts permission.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function admin_delete( $request ) {
		$user = NROS_Auth::get_user_from_request( $request );
		if ( ! NROS_Core::staff_can( $user, 'delete_posts' ) ) {
			return new WP_Error( 'nros_forbidden', 'মুছে ফেলার অনুমতি আপনার নেই — পাবলিশারের অনুমতি প্রয়োজন।', array( 'status' => 403 ) );
		}
		$post = self::video_or_error( $request );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( ! wp_trash_post( $post->ID ) ) {
			return new WP_Error( 'nros_failed', 'মুছে ফেলা যায়নি।', array( 'status' => 500 ) );
		}
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	// -------------------------------------------------------------------------
	// Public endpoints
	// -------------------------------------------------------------------------

	/**
	 * The client "address" used for rate limits and per-day dedupe: the
	 * IPv4 address as-is, or for IPv6 its /64 network — one subscriber
	 * line normally gets a whole /64, so per-address keys would let a
	 * single client rotate through billions of addresses.
	 *
	 * Only REMOTE_ADDR is trusted; X-Forwarded-For and similar headers are
	 * deliberately ignored because any client can forge them. If a CDN or
	 * reverse proxy is ever put in front of meeha.news, it must restore the
	 * real client IP into REMOTE_ADDR (e.g. Cloudflare + mod_remoteip /
	 * LiteSpeed's "use client IP in header" setting), otherwise every
	 * visitor shares the proxy's address and its limits.
	 *
	 * @return string
	 */
	public static function client_key() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) ) : '';
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$packed = inet_pton( $ip );
			if ( false !== $packed ) {
				return inet_ntop( substr( $packed, 0, 8 ) . str_repeat( "\0", 8 ) ) . '/64';
			}
		}
		return '' !== $ip ? $ip : 'unknown';
	}

	/**
	 * Short, salted hash of client_key() — the raw address is never stored
	 * (transient names end up in wp_options).
	 *
	 * @return string
	 */
	protected static function ip_hash() {
		return substr( hash_hmac( 'sha256', self::client_key(), wp_salt( 'nonce' ) ), 0, 20 );
	}

	/**
	 * Fixed-window counter: true while `$key` has been hit fewer than
	 * `$limit` times in the current `$window`-second window.
	 *
	 * @param string $key    Bucket name (without window suffix).
	 * @param int    $limit  Max hits per window.
	 * @param int    $window Window length in seconds.
	 * @return bool
	 */
	protected static function within_rate( $key, $limit, $window ) {
		$bucket = 'nros_rl_' . $key . '_' . (int) floor( time() / $window );
		$count  = (int) get_transient( $bucket );
		if ( $count >= $limit ) {
			return false;
		}
		set_transient( $bucket, $count + 1, $window );
		return true;
	}

	/**
	 * permission_callback for every public video route: anyone, but at most
	 * PUBLIC_RATE_PER_MINUTE requests per IP per minute (429 beyond).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function public_gate( $request ) {
		// Counted once per HTTP request: core calls permission callbacks
		// again after dispatch (rest_send_allow_header() builds the Allow
		// header from every handler on the route), which would otherwise
		// double-count each hit.
		static $verdict = null;
		if ( null !== $verdict ) {
			return $verdict;
		}

		$limit   = (int) apply_filters( 'nros_video_public_rate_per_minute', self::PUBLIC_RATE_PER_MINUTE );
		$verdict = self::within_rate( 'pv_' . self::ip_hash(), $limit, MINUTE_IN_SECONDS )
			? true
			: new WP_Error( 'nros_rate_limited', 'অনেক বেশি অনুরোধ এসেছে, একটু পরে আবার চেষ্টা করুন।', array( 'status' => 429 ) );
		return $verdict;
	}

	/**
	 * GET /public/videos?offset=0&limit=10&start={id} — published videos,
	 * newest first. `start` puts that video first (deep link / /video/slug/)
	 * and the rest follow in normal order without it; offsets count it.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function public_list( $request ) {
		$offset = max( 0, (int) $request->get_param( 'offset' ) );
		$limit  = (int) $request->get_param( 'limit' );
		$limit  = $limit > 0 ? min( self::PUBLIC_PAGE_MAX, $limit ) : 10;

		$start_post = null;
		$start_id   = (int) $request->get_param( 'start' );
		if ( $start_id > 0 ) {
			$candidate = get_post( $start_id );
			if ( $candidate && self::POST_TYPE === $candidate->post_type && 'publish' === $candidate->post_status && '' === $candidate->post_password ) {
				$start_post = $candidate;
			}
		}

		$base = array(
			'post_type'           => self::POST_TYPE,
			'post_status'         => 'publish',
			'has_password'        => false,
			// ID breaks ties between videos published in the same second,
			// so offset pagination never repeats or skips one.
			'orderby'             => array(
				'date' => 'DESC',
				'ID'   => 'DESC',
			),
			'ignore_sticky_posts' => true,
		);

		$items = array();
		if ( $start_post ) {
			$base['post__not_in'] = array( $start_post->ID ); // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in
			if ( 0 === $offset ) {
				$items[]   = $start_post;
				$rest_args = array(
					'offset'         => 0,
					'posts_per_page' => max( 1, $limit - 1 ),
				);
			} else {
				$rest_args = array(
					'offset'         => $offset - 1,
					'posts_per_page' => $limit,
				);
			}
			$query = new WP_Query( array_merge( $base, $rest_args ) );
			$total = (int) $query->found_posts + 1;
		} else {
			$query = new WP_Query(
				array_merge(
					$base,
					array(
						'offset'         => $offset,
						'posts_per_page' => $limit,
					)
				)
			);
			$total = (int) $query->found_posts;
		}

		$items = array_slice( array_merge( $items, $query->posts ), 0, $limit );

		return new WP_REST_Response(
			array(
				'items'    => array_map( array( __CLASS__, 'shape_public' ), $items ),
				'has_more' => ( $offset + count( $items ) ) < $total,
			),
			200
		);
	}

	/**
	 * Reads one array-valued engagement meta with every key present.
	 *
	 * @param int      $post_id  Post id.
	 * @param string   $meta_key Meta key.
	 * @param string[] $keys     Keys to default to 0.
	 * @return array<string, int>
	 */
	protected static function read_counts( $post_id, $meta_key, $keys ) {
		$stored = get_post_meta( $post_id, $meta_key, true );
		$out    = array_fill_keys( $keys, 0 );
		if ( is_array( $stored ) ) {
			foreach ( $stored as $k => $v ) {
				$out[ $k ] = max( 0, (int) $v );
			}
		}
		return $out;
	}

	/**
	 * POST /public/videos/{id}/view → { views } — counted once per IP per
	 * video per day (same dedupe idea as the theme's article view counter).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function public_view( $request ) {
		$post = self::public_video_or_error( $request );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$views = (int) get_post_meta( $post->ID, '_meeha_views', true );
		$seen  = 'nros_vv_' . $post->ID . '_' . self::ip_hash();
		if ( ! get_transient( $seen ) ) {
			set_transient( $seen, 1, DAY_IN_SECONDS );
			++$views;
			update_post_meta( $post->ID, '_meeha_views', $views );
		}

		return new WP_REST_Response( array( 'views' => $views ), 200 );
	}

	/**
	 * POST /public/videos/{id}/like { action: like|unlike } → { likes }.
	 * Stored in `_meeha_reactions['like']` (the theme's reaction array).
	 * A like counts once per IP per video per day; an unlike only undoes
	 * this IP's own like, at most once per day, so neither direction can be
	 * spammed to move the counter.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function public_like( $request ) {
		$post = self::public_video_or_error( $request );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$action = sanitize_key( (string) $request->get_param( 'action' ) );
		$action = '' === $action ? 'like' : $action;
		if ( ! in_array( $action, array( 'like', 'unlike' ), true ) ) {
			return new WP_Error( 'nros_bad_request', 'অনুরোধটি সঠিক নয়।', array( 'status' => 400 ) );
		}

		$hash     = self::ip_hash();
		$liked    = 'nros_vl_' . $post->ID . '_' . $hash;
		$unliked  = 'nros_vu_' . $post->ID . '_' . $hash;
		$counts   = self::read_counts( $post->ID, '_meeha_reactions', array( 'like' ) );
		$modified = false;

		if ( 'like' === $action && ! get_transient( $liked ) ) {
			set_transient( $liked, 1, DAY_IN_SECONDS );
			++$counts['like'];
			$modified = true;
		} elseif ( 'unlike' === $action && get_transient( $liked ) && ! get_transient( $unliked ) ) {
			delete_transient( $liked );
			set_transient( $unliked, 1, DAY_IN_SECONDS );
			$counts['like'] = max( 0, $counts['like'] - 1 );
			$modified       = true;
		}

		if ( $modified ) {
			update_post_meta( $post->ID, '_meeha_reactions', $counts );
		}

		return new WP_REST_Response( array( 'likes' => NROS_Analytics::sum_meta_value( $counts ) ), 200 );
	}

	/**
	 * POST /public/videos/{id}/share { network } → { shares } (total
	 * across networks). Counted once per IP per video per network per day.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function public_share( $request ) {
		$post = self::public_video_or_error( $request );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$network = sanitize_key( (string) $request->get_param( 'network' ) );
		if ( ! in_array( $network, self::SHARE_NETWORKS, true ) ) {
			return new WP_Error( 'nros_bad_request', 'শেয়ারের মাধ্যম সঠিক নয়।', array( 'status' => 400 ) );
		}

		$counts = self::read_counts( $post->ID, '_meeha_share_counts', self::SHARE_NETWORKS );
		$seen   = 'nros_vs_' . $post->ID . '_' . $network . '_' . self::ip_hash();
		if ( ! get_transient( $seen ) ) {
			set_transient( $seen, 1, DAY_IN_SECONDS );
			++$counts[ $network ];
			update_post_meta( $post->ID, '_meeha_share_counts', $counts );
		}

		return new WP_REST_Response( array( 'shares' => NROS_Analytics::sum_meta_value( $counts ) ), 200 );
	}

	/**
	 * PublicComment shape. Content is returned as plain text (tags
	 * stripped, entities decoded) — the player renders it as text.
	 *
	 * @param WP_Comment $comment Comment.
	 * @return array
	 */
	protected static function shape_comment( $comment ) {
		$user_id = (int) $comment->user_id;
		return array(
			'id'         => (int) $comment->comment_ID,
			'author'     => (string) $comment->comment_author,
			'avatar'     => $user_id ? NROS_Media::avatar_url( $user_id ) : (string) get_avatar_url( $comment, array( 'size' => 64 ) ),
			'date_human' => NROS_Core::bn_time_ago( $comment->comment_date ),
			'content'    => wp_specialchars_decode( wp_strip_all_tags( $comment->comment_content ), ENT_QUOTES ),
			'parent'     => (int) $comment->comment_parent,
		);
	}

	/**
	 * GET /public/videos/{id}/comments?offset=0 → { items, total } —
	 * approved comments only, newest first, COMMENTS_PAGE per page.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function public_comments( $request ) {
		$post = self::public_video_or_error( $request );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$offset   = max( 0, (int) $request->get_param( 'offset' ) );
		$comments = get_comments(
			array(
				'post_id' => $post->ID,
				'status'  => 'approve',
				'type'    => 'comment',
				'orderby' => 'comment_date_gmt',
				'order'   => 'DESC',
				'number'  => self::COMMENTS_PAGE,
				'offset'  => $offset,
			)
		);
		$total    = (int) get_comments(
			array(
				'post_id' => $post->ID,
				'status'  => 'approve',
				'type'    => 'comment',
				'count'   => true,
			)
		);

		return new WP_REST_Response(
			array(
				'items' => array_map( array( __CLASS__, 'shape_comment' ), $comments ),
				'total' => $total,
			),
			200
		);
	}

	/**
	 * POST /public/videos/{id}/comments { author_name, author_email?,
	 * content, parent?, website? } → { comment, pending }.
	 *
	 * Goes through wp_new_comment(), so the site's own Discussion settings
	 * (manual approval, moderation keys, disallowed words, flood and
	 * duplicate checks, Akismet if active) all apply exactly as for a
	 * normal comment form. A logged-in visitor is recognised the standard
	 * REST way — WordPress auth cookie + a valid `X-WP-Nonce` (wp_rest);
	 * core resolves that before this runs, so get_current_user_id() is
	 * trustworthy here (a cookie without a nonce is treated as logged out).
	 *
	 * `website` is a honeypot: a hidden field humans never fill.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function public_comment_create( $request ) {
		$post = self::public_video_or_error( $request );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		if ( '' !== trim( (string) $request->get_param( 'website' ) ) ) {
			return new WP_Error( 'nros_bad_request', 'মন্তব্যটি গ্রহণ করা যায়নি।', array( 'status' => 400 ) );
		}

		if ( ! comments_open( $post ) ) {
			return new WP_Error( 'nros_comments_closed', 'এই ভিডিওতে মন্তব্য বন্ধ আছে।', array( 'status' => 403 ) );
		}

		$user_id = get_current_user_id();
		if ( ! $user_id && get_option( 'comment_registration' ) ) {
			return new WP_Error( 'nros_login_required', 'মন্তব্য করতে লগইন করুন।', array( 'status' => 401 ) );
		}

		$content = trim( sanitize_textarea_field( (string) $request->get_param( 'content' ) ) );
		if ( '' === $content ) {
			return new WP_Error( 'nros_bad_request', 'মন্তব্য লিখুন।', array( 'status' => 400 ) );
		}
		if ( mb_strlen( $content ) > self::COMMENT_MAX ) {
			return new WP_Error( 'nros_bad_request', 'মন্তব্য সর্বোচ্চ ' . NROS_Core::bn_digits( self::COMMENT_MAX ) . ' অক্ষরের হতে পারে।', array( 'status' => 400 ) );
		}

		if ( $user_id ) {
			$user  = wp_get_current_user();
			$name  = $user->display_name;
			$email = $user->user_email;
		} else {
			$name  = mb_substr( trim( sanitize_text_field( (string) $request->get_param( 'author_name' ) ) ), 0, 60 );
			$email = trim( (string) $request->get_param( 'author_email' ) );
			if ( '' === $name ) {
				return new WP_Error( 'nros_bad_request', 'আপনার নাম লিখুন।', array( 'status' => 400 ) );
			}
			if ( '' !== $email && ! is_email( $email ) ) {
				return new WP_Error( 'nros_bad_request', 'ইমেইল ঠিকানা সঠিক নয়।', array( 'status' => 400 ) );
			}
			if ( '' === $email && get_option( 'require_name_email' ) ) {
				return new WP_Error( 'nros_bad_request', 'নাম ও ইমেইল দুটোই দিন।', array( 'status' => 400 ) );
			}
			$email = sanitize_email( $email );
		}

		$parent = (int) $request->get_param( 'parent' );
		if ( $parent > 0 ) {
			$parent_comment = get_comment( $parent );
			if ( ! $parent_comment || (int) $parent_comment->comment_post_ID !== $post->ID || '1' !== (string) $parent_comment->comment_approved ) {
				return new WP_Error( 'nros_bad_request', 'যে মন্তব্যের উত্তর দিচ্ছেন সেটি পাওয়া যায়নি।', array( 'status' => 400 ) );
			}
		}

		// Only well-formed attempts use up the quota, so a typo (empty
		// name, bad email) doesn't lock a real reader out.
		$comment_limit = (int) apply_filters( 'nros_video_comment_rate_per_10_min', self::COMMENT_RATE_PER_10_MIN );
		if ( ! self::within_rate( 'pc_' . self::ip_hash(), $comment_limit, 10 * MINUTE_IN_SECONDS ) ) {
			return new WP_Error( 'nros_rate_limited', 'অল্প সময়ে অনেক মন্তব্য হয়েছে, কিছুক্ষণ পরে চেষ্টা করুন।', array( 'status' => 429 ) );
		}

		// The theme hooks `comment_post` to (a) subscribe the commenter's
		// email to MailPoet and (b) auto-create a site account when
		// $_POST['meeha_register'] is set. Both are designed for its own
		// on-page comment form with consent UI; on this anonymous API they
		// would let anyone subscribe/register arbitrary addresses. Detach
		// them for this one insert only, then restore them exactly.
		$detached = array();
		foreach ( array( 'meeha_add_commenter_to_mailpoet', 'meeha_handle_comment_registration' ) as $callback ) {
			$priority = has_action( 'comment_post', $callback );
			if ( false !== $priority ) {
				remove_action( 'comment_post', $callback, $priority );
				$detached[ $callback ] = $priority;
			}
		}
		unset( $_POST['meeha_register'], $_REQUEST['meeha_register'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$comment_id = wp_new_comment(
			array(
				'comment_post_ID'      => $post->ID,
				'comment_author'       => $name,
				'comment_author_email' => $email,
				'comment_author_url'   => '',
				'comment_content'      => $content,
				'comment_parent'       => max( 0, $parent ),
				'comment_type'         => 'comment',
				'user_id'              => $user_id,
			),
			true
		);

		foreach ( $detached as $callback => $priority ) {
			add_action( 'comment_post', $callback, $priority, 3 );
		}

		if ( is_wp_error( $comment_id ) ) {
			$code = $comment_id->get_error_code();
			if ( 'comment_duplicate' === $code ) {
				return new WP_Error( 'nros_duplicate', 'এই মন্তব্যটি আগেই করা হয়েছে।', array( 'status' => 409 ) );
			}
			if ( 'comment_flood' === $code ) {
				return new WP_Error( 'nros_rate_limited', 'খুব দ্রুত মন্তব্য করছেন, একটু পরে চেষ্টা করুন।', array( 'status' => 429 ) );
			}
			return new WP_Error( 'nros_bad_request', 'মন্তব্যটি গ্রহণ করা যায়নি।', array( 'status' => 400 ) );
		}

		$comment  = get_comment( $comment_id );
		$approved = $comment && '1' === (string) $comment->comment_approved;

		return new WP_REST_Response(
			array(
				'comment' => $approved ? self::shape_comment( $comment ) : null,
				'pending' => ! $approved,
			),
			201
		);
	}
}
