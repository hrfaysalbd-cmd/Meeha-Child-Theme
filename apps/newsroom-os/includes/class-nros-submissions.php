<?php
/**
 * NROS_Submissions — correspondent article CRUD over the REST API.
 *
 * Deliberately reuses the theme's existing `wp_insert_post_data` safety net
 * (which forces post_author to the current user and post_status to a safe
 * value for the correspondent role) rather than re-implementing that logic
 * here — this plugin only needs to call wp_insert_post()/wp_update_post()
 * as the correspondent's own user and the theme's filter does the rest.
 *
 * @package NewsroomOS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NROS_Submissions {

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
			'/submissions',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'list_own' ),
					'permission_callback' => array( 'NROS_Core', 'require_correspondent' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'create' ),
					'permission_callback' => array( 'NROS_Core', 'require_correspondent' ),
				),
			)
		);

		register_rest_route(
			NROS_API_NS,
			'/submissions/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_one' ),
					'permission_callback' => array( 'NROS_Core', 'require_correspondent' ),
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( __CLASS__, 'update' ),
					'permission_callback' => array( 'NROS_Core', 'require_correspondent' ),
				),
			)
		);

		register_rest_route(
			NROS_API_NS,
			'/submissions/(?P<id>\d+)/submit',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'submit_for_review' ),
				'permission_callback' => array( 'NROS_Core', 'require_correspondent' ),
			)
		);

		register_rest_route(
			NROS_API_NS,
			'/submissions/(?P<id>\d+)/edit-request',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'request_edit' ),
				'permission_callback' => array( 'NROS_Core', 'require_correspondent' ),
			)
		);

		register_rest_route(
			NROS_API_NS,
			'/submissions/(?P<id>\d+)/featured-image',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'set_featured_image' ),
				'permission_callback' => array( 'NROS_Core', 'require_correspondent' ),
			)
		);

		register_rest_route(
			NROS_API_NS,
			'/submissions/(?P<id>\d+)/revisions',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_revisions' ),
				'permission_callback' => array( 'NROS_Core', 'require_correspondent' ),
			)
		);
	}

	/**
	 * Fetches a post by id, returning it only if it belongs to the
	 * requesting correspondent — otherwise a 404 (not 403, to avoid leaking
	 * which post ids exist).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_Post|WP_Error
	 */
	protected static function own_post_or_error( $request ) {
		$user = NROS_Auth::get_user_from_request( $request );
		$id   = (int) $request->get_param( 'id' );

		$post = get_post( $id );
		if ( ! $post || 'post' !== $post->post_type ) {
			return new WP_Error( 'nros_not_found', 'পাওয়া যায়নি।', array( 'status' => 404 ) );
		}

		if ( (int) $post->post_author !== (int) $user->ID ) {
			return new WP_Error( 'nros_not_found', 'পাওয়া যায়নি।', array( 'status' => 404 ) );
		}

		return $post;
	}

	/**
	 * A cheap content fingerprint (title + excerpt + body) used to detect
	 * whether a correspondent actually changed anything between a
	 * rejection and their next submit attempt — see submit_for_review().
	 * Deliberately scoped to just the editable article text, not area/
	 * category/image fields, since "edit it and resubmit" is naturally
	 * read as rewriting the article itself.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public static function content_fingerprint( $post ) {
		return md5( $post->post_title . "\x00" . $post->post_excerpt . "\x00" . $post->post_content );
	}

	/**
	 * Shapes a WP_Post into the API representation the correspondent app
	 * consumes, including the theme's existing area/topic meta.
	 *
	 * @param WP_Post $post Post.
	 * @return array
	 */
	protected static function shape( $post ) {
		$category_ids = wp_get_post_categories( $post->ID, array( 'fields' => 'ids' ) );
		$thumbnail_id = get_post_thumbnail_id( $post->ID );
		$payment_info = NROS_Ledger::post_payment_info( $post->ID );
		// A rejected-then-untouched draft: surfaced so the app can show the
		// editor's reason and block a resubmit until something actually
		// changes (see submit_for_review()). Cleared automatically once a
		// genuinely edited resubmission succeeds.
		$rejected     = (bool) get_post_meta( $post->ID, '_nros_rejected', true );

		return array(
			'id'                     => $post->ID,
			'title'                  => $post->post_title,
			'content'                => $post->post_content,
			'excerpt'                => $post->post_excerpt,
			// The theme's own "Meeha সারাংশ (AI সামারি)" meta box — shown on
			// the live site as the "সারাংশ পড়ুন" popup. Deliberately a
			// separate field from 'excerpt' (post_excerpt), which is the
			// article's subtitle/teaser shown under the headline and in
			// homepage cards — the two have always served different jobs on
			// the frontend, this just makes the AI-summary one editable from
			// here too (round 8), via the same '_meeha_summary' postmeta key
			// the theme already reads.
			'ai_summary'             => (string) get_post_meta( $post->ID, '_meeha_summary', true ),
			'status'                 => $post->post_status,
			'rejected'               => $rejected,
			'reject_reason'          => $rejected ? get_post_meta( $post->ID, '_nros_reject_reason', true ) : null,
			'category_id'            => $category_ids ? (int) $category_ids[0] : 0,
			'topic'                  => get_post_meta( $post->ID, '_meeha_topic', true ),
			'division'               => get_post_meta( $post->ID, '_meeha_division', true ),
			'district'               => get_post_meta( $post->ID, '_meeha_district', true ),
			'upazila'                => get_post_meta( $post->ID, '_meeha_upazila', true ),
			'views'                  => (int) get_post_meta( $post->ID, '_meeha_views', true ),
			'featured_image'         => $thumbnail_id ? wp_get_attachment_image_url( $thumbnail_id, 'medium' ) : null,
			'featured_image_id'      => $thumbnail_id ?: null,
			// Journalists can caption/alt-tag their own featured image (see
			// set_featured_image()); full SEO fields (slug, Rank Math meta)
			// are deliberately staff-only, via NROS_Admin_Posts instead.
			'featured_image_alt'     => $thumbnail_id ? get_post_meta( $thumbnail_id, '_wp_attachment_image_alt', true ) : '',
			'featured_image_caption' => $thumbnail_id ? get_post_field( 'post_excerpt', $thumbnail_id ) : '',
			// Flat per-post payment a Publisher may have set (round 5), or
			// auto-applied from this correspondent's default per-post rate
			// (round 7) — null when unset, so the app shows no income for
			// this mechanism at all rather than a misleading ৳0. Independent
			// of, and stacks with, any view-tier bonus the post earns.
			// payment_status is 'pending' for an auto-applied amount still
			// awaiting the Publisher's confirmation (shown, but NOT yet part
			// of /me/earnings' available_balance), or 'approved' once
			// confirmed/set directly.
			'payment'                => $payment_info['amount'],
			'payment_status'         => $payment_info['status'],
			'created_at'             => $post->post_date,
			'updated_at'             => $post->post_modified,
			'permalink'              => 'publish' === $post->post_status ? get_permalink( $post ) : null,
		) + NROS_Editorial::correspondent_fields( $post ); // Round 12: tags + area.
	}

	/**
	 * Applies a real WordPress category (not a free-text field) to the post
	 * when a category_id param is present and refers to an existing
	 * category — so a published article is properly categorized on the
	 * front end, not just tagged with an unconnected text label.
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
	 * GET /submissions — the correspondent's own articles, newest first.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function list_own( $request ) {
		$user = NROS_Auth::get_user_from_request( $request );

		$query = new WP_Query(
			array(
				'post_type'      => 'post',
				'author'         => $user->ID,
				'post_status'    => array( 'draft', 'pending', 'publish' ),
				// Round 9 follow-up: was 50. A post's own `modified` date does
				// NOT change when a Publisher sets/edits its payment (that only
				// touches a separate nros_ledger row — see
				// NROS_Ledger::set_post_payment()), so a payment added to an
				// older, already-published article could never bump it back
				// toward the top of this "newest first" list. For an active
				// correspondent with more than 50 draft/pending/published
				// posts total, that article could already sit past the old
				// cutoff — meaning a correctly-saved payment would still never
				// appear in "আমার সংবাদ" no matter how long the correspondent
				// waited. Raised well past any realistic per-correspondent
				// post count as a stopgap; real pagination is the proper fix
				// if this list ever needs to scale further.
				'posts_per_page' => 300,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);

		$items = array_map( array( __CLASS__, 'shape' ), $query->posts );

		return new WP_REST_Response( $items, 200 );
	}

	/**
	 * GET /submissions/{id} — single own article.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_one( $request ) {
		$post = self::own_post_or_error( $request );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		return new WP_REST_Response( self::shape( $post ), 200 );
	}

	/**
	 * GET /submissions/{id}/revisions — the editorial edit-history timeline
	 * for one of the correspondent's OWN articles, for training: exactly
	 * what the editorial desk changed before publishing, and by whom. Same
	 * shared timeline builder the Publisher app's full editor uses on any
	 * post (NROS_Core::build_revision_timeline()); this route only adds the
	 * ownership check.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_revisions( $request ) {
		$post = self::own_post_or_error( $request );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		return new WP_REST_Response( NROS_Core::build_revision_timeline( $post->ID ), 200 );
	}

	/**
	 * Applies the theme's area/topic meta fields from request params onto a
	 * post, when present.
	 *
	 * @param int              $post_id Post id.
	 * @param WP_REST_Request $request Request.
	 */
	protected static function apply_meta_fields( $post_id, $plan ) {
		// Round 12: topic, area (legacy keys + `area` object) and tags — validated up front
		// by NROS_Editorial::prepare() (correspondents can't send flags/glance/lit: 403).
		NROS_Editorial::apply( $post_id, $plan );
	}

	/**
	 * Saves the 'ai_summary' param into the theme's own '_meeha_summary'
	 * postmeta (the "Meeha সারাংশ (AI সামারি)" meta box, shown on the live
	 * site as the "সারাংশ পড়ুন" popup). Deleted rather than stored empty
	 * when cleared, matching how the theme already checks `if ( $summary )`
	 * to decide whether to render the button at all.
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
	 * POST /submissions — creates a new draft article. The theme's existing
	 * wp_insert_post_data safety net forces the correct author/status for
	 * the correspondent role, so this just needs to pass the submitted
	 * content.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create( $request ) {
		$user = NROS_Auth::get_user_from_request( $request );

		$blocked = array_intersect( array( 'account', 'news_submission' ), NROS_Core::active_suspension_types( $user->ID ) );
		if ( $blocked ) {
			return new WP_Error( 'nros_suspended', 'সংবাদ জমাদান সাময়িকভাবে স্থগিত করা হয়েছে।', array( 'status' => 403 ) );
		}

		$title   = sanitize_text_field( (string) $request->get_param( 'title' ) );
		$content = wp_kses_post( (string) $request->get_param( 'content' ) );

		if ( '' === $title ) {
			return new WP_Error( 'nros_bad_request', 'শিরোনাম আবশ্যক।', array( 'status' => 400 ) );
		}

		$plan = NROS_Editorial::prepare( $request, null, false );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		$post_id = wp_insert_post(
			array(
				'post_type'    => 'post',
				'post_title'   => $title,
				'post_content' => $content,
				'post_excerpt' => sanitize_textarea_field( (string) $request->get_param( 'excerpt' ) ),
				'post_status'  => 'draft',
				'post_author'  => $user->ID,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		self::apply_meta_fields( $post_id, $plan );
		self::apply_category( $post_id, $request );
		self::apply_ai_summary( $post_id, $request );

		return new WP_REST_Response( self::shape( get_post( $post_id ) ), 201 );
	}

	/**
	 * Statuses in which an article has been approved by the desk and must
	 * no longer change without review. Round 10: staff can now SCHEDULE an
	 * approved article ('future'), and WordPress publishes it later by cron
	 * without anyone looking again — so 'future' is locked exactly like
	 * 'publish', otherwise a correspondent could swap unreviewed text into
	 * an approved article before it goes live.
	 *
	 * @var string[]
	 */
	const LOCKED_STATUSES = array( 'publish', 'future' );

	/**
	 * The 409 for an approved (published or scheduled) article, or null.
	 *
	 * @param WP_Post $post Post.
	 * @return WP_Error|null
	 */
	protected static function locked_error( $post ) {
		if ( ! in_array( $post->post_status, self::LOCKED_STATUSES, true ) ) {
			return null;
		}
		return new WP_Error( 'nros_locked', 'প্রকাশিত বা প্রকাশের জন্য নির্ধারিত সংবাদ সরাসরি সম্পাদনা করা যায় না — এডিট রিকোয়েস্ট পাঠান।', array( 'status' => 409 ) );
	}

	/**
	 * PATCH /submissions/{id} — edits a draft/pending article. Blocked once
	 * a post is already published; a correspondent must use the
	 * edit-request flow for published corrections instead.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update( $request ) {
		$post = self::own_post_or_error( $request );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$locked = self::locked_error( $post );
		if ( $locked ) {
			return $locked;
		}

		$plan = NROS_Editorial::prepare( $request, $post, false );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		$update = array( 'ID' => $post->ID );

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

		$result = wp_update_post( $update, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		self::apply_meta_fields( $post->ID, $plan );
		self::apply_category( $post->ID, $request );
		self::apply_ai_summary( $post->ID, $request );

		return new WP_REST_Response( self::shape( get_post( $post->ID ) ), 200 );
	}

	/**
	 * POST /submissions/{id}/featured-image — sets the post's featured
	 * image (thumbnail) to an already-uploaded media attachment (via
	 * POST /media). Kept as a separate step rather than part of create/
	 * update because the image must already exist as an attachment before
	 * it can be set as a thumbnail, and a correspondent may want to change
	 * the featured image independently of editing the article text.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function set_featured_image( $request ) {
		$post = self::own_post_or_error( $request );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		// Same approval lock as update(): the featured image is part of
		// what the desk approved.
		$locked = self::locked_error( $post );
		if ( $locked ) {
			return $locked;
		}

		$attachment_id = (int) $request->get_param( 'attachment_id' );

		// Only an image this correspondent uploaded through the API — never
		// someone else's media by guessing attachment ids.
		$user = NROS_Auth::get_user_from_request( $request );
		if ( $attachment_id <= 0 || ! wp_attachment_is_image( $attachment_id ) || ! NROS_Media::uploaded_by( $attachment_id, $user->ID ) ) {
			return new WP_Error( 'nros_bad_request', 'সঠিক ছবি দিন।', array( 'status' => 400 ) );
		}

		$set = set_post_thumbnail( $post->ID, $attachment_id );
		if ( ! $set ) {
			return new WP_Error( 'nros_failed', 'ফিচার ইমেজ সেট করা যায়নি।', array( 'status' => 500 ) );
		}

		// Caption/alt are optional and may be supplied in the same request —
		// the correspondent (journalist) is explicitly allowed to caption
		// their own featured image, not just staff.
		NROS_Media::apply_attachment_meta( $attachment_id, $request->get_param( 'alt' ), $request->get_param( 'caption' ) );

		return new WP_REST_Response( self::shape( get_post( $post->ID ) ), 200 );
	}

	/**
	 * POST /submissions/{id}/submit — moves a draft into pending review.
	 * Only allowed from draft, so a post that's already pending or
	 * published can't be bounced back into the review queue by mistake.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function submit_for_review( $request ) {
		$post = self::own_post_or_error( $request );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$blocked = array_intersect( array( 'account', 'news_submission' ), NROS_Core::active_suspension_types( $post->post_author ) );
		if ( $blocked ) {
			return new WP_Error( 'nros_suspended', 'সংবাদ জমাদান সাময়িকভাবে স্থগিত করা হয়েছে।', array( 'status' => 403 ) );
		}

		if ( 'draft' !== $post->post_status ) {
			return new WP_Error( 'nros_invalid_state', 'শুধুমাত্র ড্রাফট থেকে রিভিউতে পাঠানো যায়।', array( 'status' => 409 ) );
		}

		// A previously-rejected draft may only be resubmitted once its
		// content has actually changed — otherwise this would just be the
		// editor's already-rejected version bouncing straight back into
		// their queue unchanged.
		$was_rejected = (bool) get_post_meta( $post->ID, '_nros_rejected', true );
		if ( $was_rejected ) {
			$prev_hash = (string) get_post_meta( $post->ID, '_nros_reject_hash', true );
			if ( $prev_hash && hash_equals( $prev_hash, self::content_fingerprint( $post ) ) ) {
				return new WP_Error(
					'nros_no_changes',
					'প্রত্যাখ্যানের পর কোনো পরিবর্তন করা হয়নি — শিরোনাম বা বিস্তারিত সম্পাদনা করে তারপর আবার জমা দিন।',
					array( 'status' => 409 )
				);
			}
		}

		$result = wp_update_post(
			array(
				'ID'          => $post->ID,
				'post_status' => 'pending',
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( $was_rejected ) {
			delete_post_meta( $post->ID, '_nros_rejected' );
			delete_post_meta( $post->ID, '_nros_reject_reason' );
			delete_post_meta( $post->ID, '_nros_reject_hash' );
		}

		/**
		 * Fires when a correspondent submits an article for editorial review.
		 *
		 * @param int $post_id Post id.
		 */
		do_action( 'nros_submission_submitted', $post->ID );

		return new WP_REST_Response( self::shape( get_post( $post->ID ) ), 200 );
	}

	/**
	 * POST /submissions/{id}/edit-request — for an already-published
	 * article, files a correction request using the theme's existing
	 * meeha_edit_req custom post type rather than a new table.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function request_edit( $request ) {
		$post = self::own_post_or_error( $request );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		// Scheduled ('future') articles are locked too, so they need this
		// correction path just like published ones.
		if ( ! in_array( $post->post_status, self::LOCKED_STATUSES, true ) ) {
			return new WP_Error( 'nros_invalid_state', 'শুধুমাত্র প্রকাশিত বা নির্ধারিত সংবাদের জন্য এডিট রিকোয়েস্ট পাঠানো যায়।', array( 'status' => 409 ) );
		}

		if ( ! post_type_exists( 'meeha_edit_req' ) ) {
			return new WP_Error( 'nros_not_implemented', 'এডিট রিকোয়েস্ট সিস্টেম থিমে পাওয়া যায়নি।', array( 'status' => 501 ) );
		}

		$reason = sanitize_textarea_field( (string) $request->get_param( 'reason' ) );
		if ( '' === $reason ) {
			return new WP_Error( 'nros_bad_request', 'পরিবর্তনের কারণ লিখুন।', array( 'status' => 400 ) );
		}

		$req_id = wp_insert_post(
			array(
				'post_type'    => 'meeha_edit_req',
				'post_title'   => 'Edit request for #' . $post->ID,
				'post_content' => $reason,
				'post_status'  => 'publish',
				'post_author'  => $post->post_author,
				'meta_input'   => array(
					'_meeha_edit_req_target' => $post->ID,
				),
			),
			true
		);

		if ( is_wp_error( $req_id ) ) {
			return $req_id;
		}

		return new WP_REST_Response( array( 'ok' => true, 'edit_request_id' => $req_id ), 201 );
	}
}
