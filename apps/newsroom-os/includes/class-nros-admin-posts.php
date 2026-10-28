<?php
/**
 * NROS_Admin_Posts — the full post editor behind publisher.meeha.news, for
 * staff (Editor/Publisher/Administrator) to:
 *
 * - write and publish their OWN articles directly, with no review gate
 *   (unlike a correspondent's submission, which always starts as
 *   draft/pending — see NROS_Submissions). Editor/Publisher are trusted
 *   staff, so their own writing publishes the moment they choose to.
 * - open and fully edit ANY article regardless of author — including a
 *   correspondent's still-pending submission — body, category, featured
 *   image (with alt text/caption), and SEO fields. This is deliberately a
 *   separate resource from `/submissions` (correspondent-only, own-post-
 *   only) rather than loosening that endpoint's ownership check.
 *
 * SEO fields write directly to the meta keys the site's actual SEO plugin
 * (Rank Math) reads: `rank_math_title`, `rank_math_description`,
 * `rank_math_focus_keyword` — confirmed against Rank Math's own documented
 * field names, not guessed. The URL slug is WordPress's own standard
 * `post_name` field, nothing plugin-specific.
 *
 * @package NewsroomOS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NROS_Admin_Posts {

	/**
	 * Registers routes.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'transition_post_status', array( __CLASS__, 'on_transition_post_status' ), 10, 3 );
	}

	/**
	 * Meta flag: "approved while scheduled — fire the approval action when
	 * WordPress actually publishes it".
	 */
	const APPROVAL_PENDING_META = '_nros_approval_pending_hook';

	/**
	 * Approval action per post type. `nros_submission_approved` drives the
	 * ledger's default per-post payment (NROS_Ledger::
	 * maybe_apply_default_payment()), so it must fire exactly when a
	 * correspondent's article goes live.
	 *
	 * @var array<string, string>
	 */
	const APPROVAL_ACTIONS = array(
		'post'        => 'nros_submission_approved',
		'meeha_video' => 'nros_video_approved',
	);

	/**
	 * Called right after staff approve a PENDING article/video. If it is
	 * live now, the approval action fires immediately. If staff approved it
	 * into the future (scheduled), WordPress will publish it later by cron
	 * with nobody in the request — so the post is flagged and
	 * on_transition_post_status() fires the action at that moment instead.
	 * Before round 10's scheduling this was impossible, and approving into
	 * 'future' silently skipped the default payment forever.
	 *
	 * @param int $post_id Post id.
	 */
	public static function fire_or_defer_approval( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || ! isset( self::APPROVAL_ACTIONS[ $post->post_type ] ) ) {
			return;
		}
		if ( 'publish' === $post->post_status ) {
			delete_post_meta( $post_id, self::APPROVAL_PENDING_META );
			/** This action is documented in class-nros-admin-api.php / class-nros-videos.php. */
			do_action( self::APPROVAL_ACTIONS[ $post->post_type ], $post_id );
		} elseif ( 'future' === $post->post_status ) {
			update_post_meta( $post_id, self::APPROVAL_PENDING_META, 1 );
		}
	}

	/**
	 * Fires a deferred approval action when a flagged scheduled post is
	 * published (by cron, or early by staff moving the date back). The flag
	 * is deleted FIRST and the action only fires if that delete actually
	 * removed it, so two overlapping publish events can't both fire it;
	 * the ledger additionally refuses a second payment row per post. If the
	 * post leaves the schedule another way (back to draft/pending/trash),
	 * the flag is dropped — a later fresh approval re-evaluates.
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param WP_Post $post       Post.
	 */
	public static function on_transition_post_status( $new_status, $old_status, $post ) {
		if ( ! isset( self::APPROVAL_ACTIONS[ $post->post_type ] ) || ! get_post_meta( $post->ID, self::APPROVAL_PENDING_META, true ) ) {
			return;
		}
		if ( 'publish' === $new_status && 'future' === $old_status ) {
			if ( delete_post_meta( $post->ID, self::APPROVAL_PENDING_META ) ) {
				do_action( self::APPROVAL_ACTIONS[ $post->post_type ], $post->ID );
			}
		} elseif ( in_array( $new_status, array( 'draft', 'pending', 'trash' ), true ) ) {
			delete_post_meta( $post->ID, self::APPROVAL_PENDING_META );
		}
	}

	/**
	 * Registers REST routes under nros/v1.
	 */
	public static function register_routes() {
		register_rest_route(
			NROS_API_NS,
			'/admin/posts',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'create' ),
				'permission_callback' => array( 'NROS_Core', 'require_staff' ),
			)
		);

		register_rest_route(
			NROS_API_NS,
			'/admin/posts/mine',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_mine' ),
				'permission_callback' => array( 'NROS_Core', 'require_staff' ),
			)
		);

		// Round 7 — every article, any author, filterable by status; backs
		// the Publisher app's news-list screen (সব/প্রকাশিত/প্রত্যাখ্যাত).
		// Registered as a literal path ("all") rather than colliding with
		// the (?P<id>\d+) route below — "all" never matches \d+.
		register_rest_route(
			NROS_API_NS,
			'/admin/posts/all',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_all' ),
				'permission_callback' => array( 'NROS_Core', 'require_staff' ),
			)
		);

		register_rest_route(
			NROS_API_NS,
			'/admin/posts/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_one' ),
					'permission_callback' => array( 'NROS_Core', 'require_staff' ),
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( __CLASS__, 'update' ),
					'permission_callback' => array( 'NROS_Core', 'require_staff' ),
				),
				// Round 10 — moves the article to the WordPress trash
				// (recoverable from wp-admin). Editor needs the Publisher's
				// delete_posts permission; checked inside delete().
				array(
					'methods'             => 'DELETE',
					'callback'            => array( __CLASS__, 'delete' ),
					'permission_callback' => array( 'NROS_Core', 'require_staff' ),
				),
			)
		);

		// Round 10 — author picker for the "change author" control (articles
		// and videos): everyone who can be credited with a story.
		register_rest_route(
			NROS_API_NS,
			'/admin/authors',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_authors' ),
				'permission_callback' => array( 'NROS_Core', 'require_staff' ),
			)
		);

		register_rest_route(
			NROS_API_NS,
			'/admin/posts/(?P<id>\d+)/featured-image',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'set_featured_image' ),
				'permission_callback' => array( 'NROS_Core', 'require_staff' ),
			)
		);

		register_rest_route(
			NROS_API_NS,
			'/admin/posts/(?P<id>\d+)/revisions',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_revisions' ),
				'permission_callback' => array( 'NROS_Core', 'require_staff' ),
			)
		);
	}

	/**
	 * Fetches any post by id (no ownership restriction, unlike
	 * NROS_Submissions::own_post_or_error — staff can open any article).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_Post|WP_Error
	 */
	protected static function post_or_error( $request ) {
		$id   = (int) $request->get_param( 'id' );
		$post = get_post( $id );

		// Round 10: a trashed article behaves as gone (it can only be
		// restored from wp-admin), so a second DELETE is a clean 404.
		if ( ! $post || 'post' !== $post->post_type || 'trash' === $post->post_status ) {
			return new WP_Error( 'nros_not_found', 'পাওয়া যায়নি।', array( 'status' => 404 ) );
		}

		return $post;
	}

	/**
	 * Roles whose members may be credited as an article's/video's author.
	 *
	 * @var string[]
	 */
	const AUTHOR_ROLES = array( 'correspondent', 'editor', 'meeha_publisher', 'administrator' );

	/**
	 * Whether a user id is a valid author choice (exists and holds one of
	 * AUTHOR_ROLES) — never trust a client-sent author_id blindly, or a
	 * story could be attributed to a subscriber account.
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	public static function is_valid_author( $user_id ) {
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		return $user && array_intersect( self::AUTHOR_ROLES, (array) $user->roles );
	}

	/**
	 * Parses a site-local 'YYYY-MM-DD HH:MM:SS' date from a request,
	 * strictly (round-trips through DateTime so "2026-02-31" is refused).
	 *
	 * @param mixed $value Raw value.
	 * @return string|WP_Error Normalised date, or WP_Error (400).
	 */
	public static function parse_local_date( $value ) {
		$value = trim( (string) $value );
		// Round 13: also accept the datetime-local shapes 'YYYY-MM-DD HH:MM' / 'YYYY-MM-DDTHH:MM(:SS)'.
		if ( preg_match( '/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})(:\d{2})?$/', $value, $m ) ) {
			$value = $m[1] . ' ' . $m[2] . ( isset( $m[3] ) && '' !== $m[3] ? $m[3] : ':00' );
		}
		$dt    = DateTime::createFromFormat( 'Y-m-d H:i:s', $value );
		if ( ! $dt || $dt->format( 'Y-m-d H:i:s' ) !== $value ) {
			return new WP_Error( 'nros_bad_date', 'তারিখ সঠিক নয় (YYYY-MM-DD HH:MM:SS)।', array( 'status' => 400 ) );
		}
		return $value;
	}

	/**
	 * Applies the staff-only `date` and `author_id` request params onto a
	 * wp_update_post()/wp_insert_post() args array, enforcing the
	 * Publisher's Editor permissions (NROS_Core::staff_can()).
	 *
	 * A param equal to the post's current value is NOT treated as a change
	 * — the editor screen may send the whole form back, and an Editor
	 * without the permission must still be able to save other fields.
	 *
	 * Publishing with a future date makes WordPress itself store the post
	 * as 'future' (scheduled) and publish it via cron at that time;
	 * wp_insert_post() does that transition, nothing custom here.
	 *
	 * Shared with NROS_Videos so the same rules apply to video news.
	 *
	 * @param array            $args    wp_insert_post args (modified in place).
	 * @param WP_REST_Request $request Request.
	 * @param WP_User          $user    Acting staff user.
	 * @param WP_Post|null     $post    Existing post, or null on create.
	 * @return true|WP_Error
	 */
	public static function apply_date_author( &$args, $request, $user, $post = null ) {
		$date = $request->get_param( 'date' );
		if ( null !== $date && '' !== $date ) {
			$date = self::parse_local_date( $date );
			if ( is_wp_error( $date ) ) {
				return $date;
			}
			// Round 13 (backdating bug): compare to the MINUTE. The apps' date
			// input has no seconds, so the unchanged '10:47' came back as
			// '10:47:00' vs the stored '10:47:26' and was taken as an explicit
			// date — which froze a pending submission's floating date (its
			// submission time) as the publish time. Same minute = unchanged:
			// a floating draft/pending post (see is_date_floating()) then gets
			// no date at all and WordPress stamps "now" when it is published.
			if ( ! $post || substr( $date, 0, 16 ) !== substr( $post->post_date, 0, 16 ) ) {
				if ( ! NROS_Core::staff_can( $user, 'change_date' ) ) {
					return new WP_Error( 'nros_forbidden', 'তারিখ পরিবর্তনের অনুমতি আপনার নেই — পাবলিশারের অনুমতি প্রয়োজন।', array( 'status' => 403 ) );
				}
				$args['post_date']     = $date;
				$args['post_date_gmt'] = get_gmt_from_date( $date );
				$args['edit_date']     = true;
			}
		}

		$author_id = $request->get_param( 'author_id' );
		if ( null !== $author_id && '' !== $author_id ) {
			$author_id = (int) $author_id;
			$current   = $post ? (int) $post->post_author : (int) $user->ID;
			if ( $author_id !== $current ) {
				if ( ! NROS_Core::staff_can( $user, 'change_author' ) ) {
					return new WP_Error( 'nros_forbidden', 'লেখক পরিবর্তনের অনুমতি আপনার নেই — পাবলিশারের অনুমতি প্রয়োজন।', array( 'status' => 403 ) );
				}
				if ( ! self::is_valid_author( $author_id ) ) {
					return new WP_Error( 'nros_bad_author', 'নির্বাচিত লেখক সঠিক নয়।', array( 'status' => 400 ) );
				}
				$args['post_author'] = $author_id;
			}
		}

		return true;
	}

	/**
	 * Round 13 — whether a post's date is still "floating": a draft/pending
	 * post whose GMT date was never set. Its post_date is only the last save
	 * time, and WordPress stamps the real date when it is first published
	 * (unless a date is explicitly set). Exposed to the apps as
	 * `date_floating` so they leave the date field empty for such posts.
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	public static function is_date_floating( $post ) {
		return $post && in_array( $post->post_status, array( 'draft', 'pending', 'auto-draft' ), true )
			&& ( '' === (string) $post->post_date_gmt || '0000-00-00 00:00:00' === $post->post_date_gmt );
	}

	/**
	 * Shapes a WP_Post into the API representation the Publisher app's post
	 * editor consumes: body/category/featured-image fields (same shape
	 * NROS_Submissions uses, for a shared frontend editor component) plus
	 * the slug and SEO fields correspondents don't get.
	 *
	 * @param WP_Post $post Post.
	 * @return array
	 */
	protected static function shape( $post ) {
		$category_ids = wp_get_post_categories( $post->ID, array( 'fields' => 'ids' ) );
		$thumbnail_id = get_post_thumbnail_id( $post->ID );
		$author       = get_userdata( $post->post_author );
		$payment_info = NROS_Ledger::post_payment_info( $post->ID );

		return array(
			'id'                     => $post->ID,
			'title'                  => $post->post_title,
			'content'                => $post->post_content,
			'excerpt'                => $post->post_excerpt,
			// Theme's "Meeha সারাংশ (AI সামারি)" meta box — see the matching
			// field in NROS_Submissions::shape() for the full explanation.
			// Staff can edit it from here too, independent of the
			// correspondent's own copy of this same field.
			'ai_summary'             => (string) get_post_meta( $post->ID, '_meeha_summary', true ),
			'status'                 => $post->post_status,
			'slug'                   => $post->post_name,
			'category_id'            => $category_ids ? (int) $category_ids[0] : 0,
			'featured_image'         => $thumbnail_id ? wp_get_attachment_image_url( $thumbnail_id, 'medium' ) : null,
			'featured_image_id'      => $thumbnail_id ?: null,
			'featured_image_alt'     => $thumbnail_id ? get_post_meta( $thumbnail_id, '_wp_attachment_image_alt', true ) : '',
			'featured_image_caption' => $thumbnail_id ? get_post_field( 'post_excerpt', $thumbnail_id ) : '',
			'seo'                    => array(
				'title'          => get_post_meta( $post->ID, 'rank_math_title', true ),
				'description'    => get_post_meta( $post->ID, 'rank_math_description', true ),
				'focus_keyword'  => get_post_meta( $post->ID, 'rank_math_focus_keyword', true ),
			),
			// Publisher-set (or auto-applied-from-default-rate) flat payment
			// for this specific article — both null when unset (see
			// NROS_Ledger::post_payment_info()'s docblock). payment_status
			// is 'pending' for an auto-applied amount awaiting confirmation,
			// 'approved' once set/confirmed, or null alongside a null amount.
			'payment'                => $payment_info['amount'],
			'payment_status'         => $payment_info['status'],
			'author'                 => $author ? array( 'id' => $author->ID, 'name' => $author->display_name ) : null,
			// Round 10 — publish/scheduled date (site local time); a
			// 'future' status means it is scheduled for this date.
			'date'                   => $post->post_date,
			// Round 13 — true for a draft/pending post whose date is not set
			// yet: `date` is then only its last save time, and publishing
			// stamps the real time (apps show an empty date field).
			'date_floating'          => self::is_date_floating( $post ),
			// Round 13 — "অতিথি লেখক" shown instead of the WP author on the site.
			'guest_author'           => NROS_Guest_Authors::shape_for_post( $post->ID ),
			'created_at'             => $post->post_date,
			'updated_at'             => $post->post_modified,
			'permalink'              => 'publish' === $post->post_status ? get_permalink( $post ) : null,
		) + NROS_Editorial::staff_fields( $post ); // Round 12: tags, area, topic, flags, glance_text, lit, is_literature.
	}

	/**
	 * Applies the real WordPress category when a category_id param is
	 * present and valid — same helper as NROS_Submissions::apply_category().
	 *
	 * @param int              $post_id Post id.
	 * @param WP_REST_Request $request Request.
	 */
	protected static function apply_category( $post_id, $request ) {
		$category_id = $request->get_param( 'category_id' );
		if ( null === $category_id || '' === $category_id ) {
			return;
		}

		$category_id = (int) $category_id;
		if ( $category_id > 0 && term_exists( $category_id, 'category' ) ) {
			wp_set_post_categories( $post_id, array( $category_id ) );
		}
	}

	/**
	 * Saves the 'ai_summary' param into '_meeha_summary' postmeta — same
	 * field, same helper behavior as NROS_Submissions::apply_ai_summary(),
	 * duplicated rather than shared across classes since neither extends
	 * the other.
	 *
	 * @param int              $post_id Post id.
	 * @param WP_REST_Request $request Request.
	 */
	protected static function apply_ai_summary( $post_id, $request ) {
		$ai_summary = $request->get_param( 'ai_summary' );
		if ( null === $ai_summary ) {
			return;
		}
		$clean = sanitize_textarea_field( (string) $ai_summary );
		if ( '' === $clean ) {
			delete_post_meta( $post_id, '_meeha_summary' );
		} else {
			update_post_meta( $post_id, '_meeha_summary', $clean );
		}
	}

	/**
	 * Writes the SEO fields a request provides onto the post: the URL slug
	 * (WordPress's own `post_name`, via a second wp_update_post call since
	 * slug changes want their own sanitize_title() pass) and the three
	 * Rank Math meta keys. Only touches a field when its param is present,
	 * so a partial PATCH never blanks fields the request didn't mention.
	 *
	 * @param int              $post_id Post id.
	 * @param WP_REST_Request $request Request.
	 */
	protected static function apply_seo_fields( $post_id, $request ) {
		$slug = $request->get_param( 'slug' );
		if ( null !== $slug && '' !== $slug ) {
			wp_update_post(
				array(
					'ID'        => $post_id,
					'post_name' => sanitize_title( (string) $slug ),
				)
			);
		}

		$map = array(
			'seo_title'       => 'rank_math_title',
			'seo_description' => 'rank_math_description',
			'focus_keyword'   => 'rank_math_focus_keyword',
		);

		foreach ( $map as $param => $meta_key ) {
			$value = $request->get_param( $param );
			if ( null !== $value ) {
				update_post_meta( $post_id, $meta_key, sanitize_text_field( (string) $value ) );
			}
		}
	}

	/**
	 * Applies the theme's existing area/topic meta fields, same as
	 * NROS_Submissions::apply_meta_fields() — staff can set these too when
	 * writing their own piece (e.g. a Dhaka-desk editor filing a story for
	 * a specific district).
	 *
	 * @param int              $post_id Post id.
	 * @param WP_REST_Request $request Request.
	 */
	protected static function apply_meta_fields( $post_id, $plan ) {
		// Round 12: topic + area (legacy keys and the new `area` object), tags, flags,
		// glance_text and lit — validated up front by NROS_Editorial::prepare().
		NROS_Editorial::apply( $post_id, $plan );
	}

	/**
	 * POST /admin/posts — staff writes and (by default) publishes their own
	 * article directly. No pending/review step: Editor and Publisher are
	 * trusted staff, not correspondents, so their own writing doesn't go
	 * through the editorial queue they themselves run. Pass status=draft
	 * to save without publishing yet.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create( $request ) {
		$user = NROS_Auth::get_user_from_request( $request );

		$title  = sanitize_text_field( (string) $request->get_param( 'title' ) );
		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		$status = in_array( $status, array( 'draft', 'publish' ), true ) ? $status : 'publish';

		if ( '' === $title ) {
			return new WP_Error( 'nros_bad_request', 'শিরোনাম আবশ্যক।', array( 'status' => 400 ) );
		}

		$plan = NROS_Editorial::prepare( $request, null, true );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		// Round 13 — guest author (needs the change-author right).
		$guest_plan = NROS_Guest_Authors::prepare( $request, $user, null );
		if ( is_wp_error( $guest_plan ) ) {
			return $guest_plan;
		}

		$post_id = wp_insert_post(
			array(
				'post_type'    => 'post',
				'post_title'   => $title,
				'post_content' => wp_kses_post( (string) $request->get_param( 'content' ) ),
				'post_excerpt' => sanitize_textarea_field( (string) $request->get_param( 'excerpt' ) ),
				'post_status'  => $status,
				'post_author'  => $user->ID,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		self::apply_meta_fields( $post_id, $plan );
		NROS_Guest_Authors::apply( $post_id, $guest_plan );
		self::apply_category( $post_id, $request );
		self::apply_seo_fields( $post_id, $request );
		self::apply_ai_summary( $post_id, $request );

		return new WP_REST_Response( self::shape( get_post( $post_id ) ), 201 );
	}

	/**
	 * GET /admin/posts/mine — the logged-in staff member's own authored
	 * articles (any status), for a personal "আমার পোস্ট" list distinct from
	 * the editorial queue (which lists everyone ELSE's pending work).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function list_mine( $request ) {
		$user = NROS_Auth::get_user_from_request( $request );

		$query = new WP_Query(
			array(
				'post_type'      => 'post',
				'author'         => $user->ID,
				'post_status'    => array( 'draft', 'pending', 'publish', 'future' ),
				'posts_per_page' => 50,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);

		return new WP_REST_Response( array_map( array( __CLASS__, 'shape' ), $query->posts ), 200 );
	}

	/**
	 * Lighter row shape for the all-posts list screen — avoids shipping full
	 * content/SEO payloads for what may be hundreds of rows at once.
	 *
	 * @param WP_Post $post Post.
	 * @return array
	 */
	protected static function shape_list_row( $post ) {
		$author       = get_userdata( $post->post_author );
		$payment_info = NROS_Ledger::post_payment_info( $post->ID );

		return array(
			'id'             => $post->ID,
			'title'          => $post->post_title,
			'status'         => $post->post_status,
			// A rejected article is a WordPress "draft" under the hood (see
			// NROS_Submissions) — surfaced separately so the list can give it
			// its own "প্রত্যাখ্যাত" tab/badge rather than lumping it in with
			// an ordinary unsent draft.
			'rejected'       => (bool) get_post_meta( $post->ID, '_nros_rejected', true ),
			'author'         => $author ? array( 'id' => $author->ID, 'name' => $author->display_name ) : null,
			'views'          => (int) get_post_meta( $post->ID, '_meeha_views', true ),
			'payment'        => $payment_info['amount'],
			'payment_status' => $payment_info['status'],
			// Round 12 — editorial flags for list badges.
			'flags'          => NROS_Editorial::flags_of( $post->ID ),
			'date'           => $post->post_date,
			'date_floating'  => self::is_date_floating( $post ),
			'guest_author'   => NROS_Guest_Authors::shape_for_post( $post->ID ),
			'created_at'     => $post->post_date,
			'updated_at'     => $post->post_modified,
			'permalink'      => 'publish' === $post->post_status ? get_permalink( $post ) : null,
		);
	}

	/**
	 * GET /admin/posts/all — every article across every author, for the
	 * Publisher app's news-list screen. ?filter= one of:
	 * - 'all' (default): draft + pending + publish, any author
	 * - 'publish': published only
 * - 'future': scheduled (round 10)
	 * - 'rejected': drafts carrying the `_nros_rejected` flag (see
	 *   NROS_Admin_API::queue_reject())
	 * - 'pending': awaiting editorial review
	 * - 'draft': plain unsent drafts (excludes rejected ones — use
	 *   'rejected' for those)
	 * ?page= for simple offset pagination (50/page).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function list_all( $request ) {
		$filter = sanitize_key( (string) $request->get_param( 'filter' ) );
		$page   = max( 1, (int) $request->get_param( 'page' ) );

		$args = array(
			'post_type'      => 'post',
			'posts_per_page' => 50,
			'paged'          => $page,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		);

		if ( 'publish' === $filter ) {
			$args['post_status'] = 'publish';
		} elseif ( 'future' === $filter ) {
			// Round 10 — scheduled articles (a future publish date).
			$args['post_status'] = 'future';
		} elseif ( 'pending' === $filter ) {
			$args['post_status'] = 'pending';
		} elseif ( 'rejected' === $filter ) {
			$args['post_status'] = 'draft';
			$args['meta_key']    = '_nros_rejected'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			$args['meta_value']  = 1; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		} elseif ( 'draft' === $filter ) {
			$args['post_status']   = 'draft';
			$args['meta_query']    = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => '_nros_rejected',
					'compare' => 'NOT EXISTS',
				),
			);
		} else {
			$args['post_status'] = array( 'draft', 'pending', 'publish', 'future' );
		}

		$query = new WP_Query( $args );

		return new WP_REST_Response(
			array(
				'items' => array_map( array( __CLASS__, 'shape_list_row' ), $query->posts ),
				'total' => (int) $query->found_posts,
				'pages' => (int) $query->max_num_pages,
			),
			200
		);
	}

	/**
	 * GET /admin/posts/{id} — any single article, any author/status.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_one( $request ) {
		$post = self::post_or_error( $request );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		return new WP_REST_Response( self::shape( $post ), 200 );
	}

	/**
	 * GET /admin/posts/{id}/revisions — the full edit-history timeline for
	 * ANY article (not just staff's own) — e.g. an Editor reviewing exactly
	 * what they themselves changed on a correspondent's piece before
	 * approving it. Same shared builder the correspondent app's own-post
	 * route uses (NROS_Core::build_revision_timeline()).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_revisions( $request ) {
		$post = self::post_or_error( $request );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		return new WP_REST_Response( NROS_Core::build_revision_timeline( $post->ID ), 200 );
	}

	/**
	 * PATCH /admin/posts/{id} — full edit of any article, including
	 * changing its status (e.g. publishing a correspondent's pending
	 * submission directly from the full editor, as an alternative to the
	 * quick-action `/admin/queue/{id}/approve`).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update( $request ) {
		$post = self::post_or_error( $request );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$was_pending = 'pending' === $post->post_status;
		$update      = array( 'ID' => $post->ID );

		$title = $request->get_param( 'title' );
		if ( null !== $title ) {
			$update['post_title'] = sanitize_text_field( (string) $title );
		}

		$content = $request->get_param( 'content' );
		if ( null !== $content ) {
			$update['post_content'] = wp_kses_post( (string) $content );
		}

		$excerpt = $request->get_param( 'excerpt' );
		if ( null !== $excerpt ) {
			$update['post_excerpt'] = sanitize_textarea_field( (string) $excerpt );
		}

		$status = $request->get_param( 'status' );
		if ( null !== $status && in_array( $status, array( 'draft', 'pending', 'publish' ), true ) ) {
			// A scheduled article re-saved as "publish" stays scheduled:
			// wp_insert_post() turns publish + future date into 'future'.
			$update['post_status'] = $status;
		}

		// Round 10 — staff may move the publish date (future date on a
		// published article = scheduled) and re-credit the author, subject
		// to the Publisher's Editor permissions.
		$user    = NROS_Auth::get_user_from_request( $request );
		$applied = self::apply_date_author( $update, $request, $user, $post );
		if ( is_wp_error( $applied ) ) {
			return $applied;
		}

		// Round 12 — validate tags/area/flags/glance/lit before anything is written.
		$plan = NROS_Editorial::prepare( $request, $post, true );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		// Round 13 — guest author (same right as changing the author).
		$guest_plan = NROS_Guest_Authors::prepare( $request, $user, $post );
		if ( is_wp_error( $guest_plan ) ) {
			return $guest_plan;
		}

		$result = wp_update_post( $update, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		self::apply_meta_fields( $post->ID, $plan );
		NROS_Guest_Authors::apply( $post->ID, $guest_plan );
		self::apply_category( $post->ID, $request );
		self::apply_seo_fields( $post->ID, $request );
		self::apply_ai_summary( $post->ID, $request );

		// Same hook /admin/queue/{id}/approve fires — so a correspondent's
		// pending article being approved from inside the full editor (instead
		// of the queue's one-click approve) still triggers any current or
		// future side effect (crediting, notifications, etc.) tied to that
		// event. Only on the pending→publish (or, round 10, pending→
		// scheduled — deferred until it actually publishes) transition, not
		// an already-published article being re-saved.
		if ( $was_pending && in_array( get_post_status( $post->ID ), array( 'publish', 'future' ), true ) ) {
			self::fire_or_defer_approval( $post->ID );
		}

		return new WP_REST_Response( self::shape( get_post( $post->ID ) ), 200 );
	}

	/**
	 * DELETE /admin/posts/{id} — moves the article to the WordPress trash
	 * (never a permanent delete: it stays restorable from wp-admin for
	 * EMPTY_TRASH_DAYS). Publisher/Administrator always; Editor only when
	 * the Publisher granted delete_posts.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete( $request ) {
		$user = NROS_Auth::get_user_from_request( $request );
		if ( ! NROS_Core::staff_can( $user, 'delete_posts' ) ) {
			return new WP_Error( 'nros_forbidden', 'মুছে ফেলার অনুমতি আপনার নেই — পাবলিশারের অনুমতি প্রয়োজন।', array( 'status' => 403 ) );
		}

		$post = self::post_or_error( $request );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		if ( ! wp_trash_post( $post->ID ) ) {
			return new WP_Error( 'nros_failed', 'মুছে ফেলা যায়নি।', array( 'status' => 500 ) );
		}

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * GET /admin/authors?search= — up to 50 users who can be credited as
	 * author (correspondents and staff), matched on name, email or login.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function list_authors( $request ) {
		$search = trim( sanitize_text_field( (string) $request->get_param( 'search' ) ) );

		$args = array(
			'role__in' => self::AUTHOR_ROLES,
			'number'   => 50,
			'orderby'  => 'display_name',
			'order'    => 'ASC',
		);
		if ( '' !== $search ) {
			$args['search']         = '*' . $search . '*';
			$args['search_columns'] = array( 'user_login', 'user_email', 'display_name' );
		}

		$items = array();
		foreach ( get_users( $args ) as $u ) {
			$items[] = array(
				'id'     => (int) $u->ID,
				'name'   => $u->display_name,
				'role'   => NROS_Auth::primary_role( $u ),
				'avatar' => NROS_Media::avatar_url( $u->ID ),
			);
		}

		return new WP_REST_Response( $items, 200 );
	}

	/**
	 * POST /admin/posts/{id}/featured-image — sets the thumbnail (from an
	 * already-uploaded attachment) plus optional alt/caption in one call.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function set_featured_image( $request ) {
		$post = self::post_or_error( $request );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$attachment_id = (int) $request->get_param( 'attachment_id' );
		if ( $attachment_id <= 0 || ! wp_attachment_is_image( $attachment_id ) ) {
			return new WP_Error( 'nros_bad_request', 'সঠিক ছবি দিন।', array( 'status' => 400 ) );
		}

		if ( ! set_post_thumbnail( $post->ID, $attachment_id ) ) {
			return new WP_Error( 'nros_failed', 'ফিচার ইমেজ সেট করা যায়নি।', array( 'status' => 500 ) );
		}

		NROS_Media::apply_attachment_meta( $attachment_id, $request->get_param( 'alt' ), $request->get_param( 'caption' ) );

		return new WP_REST_Response( self::shape( get_post( $post->ID ) ), 200 );
	}
}
