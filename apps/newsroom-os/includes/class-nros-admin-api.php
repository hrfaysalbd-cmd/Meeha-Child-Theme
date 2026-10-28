<?php
/**
 * NROS_Admin_API — the REST surface behind publisher.meeha.news, the single
 * business dashboard shared by both the Editor and Publisher roles. Routes
 * are split by permission:
 *
 * - `require_staff`     (built-in Editor, Publisher, or Administrator): the
 *   editorial queue, correspondent list, applications/withdrawals (already
 *   exposed by NROS_Applications/NROS_Ledger), ad-client records,
 *   investigations, issuing everyday suspensions, and engagement analytics.
 * - `require_publisher` (Publisher or Administrator only): user/role
 *   management, the Editor financial-visibility toggle, SEO settings,
 *   suspension-appeal review, and the two most serious suspension actions
 *   (a full account suspension, or any lifetime duration) — matching the
 *   final spec's punishment-ladder design, where the last rung and any
 *   appeal are reserved for Publisher even though Editor sits on the
 *   investigating panel for everything before that.
 *
 * Deliberately reuses existing tables/classes rather than duplicating them:
 * applications and withdrawals already have staff-only REST routes
 * (NROS_Applications, NROS_Ledger) that this app calls as-is.
 *
 * @package NewsroomOS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NROS_Admin_API {

	const SUSPENSION_TYPES = array( 'client_commission', 'all_commission', 'news_submission', 'account' );

	const DURATIONS = array( '1m', '3m', '6m', 'lifetime' );

	/**
	 * Suspension actions serious enough to require Publisher specifically,
	 * even though Editor can issue every other suspension as part of the
	 * investigating panel: a full account suspension, or any lifetime one.
	 *
	 * @param string $type     Suspension type.
	 * @param string $duration Suspension duration.
	 * @return bool
	 */
	protected static function requires_publisher_sign_off( $type, $duration ) {
		return 'account' === $type || 'lifetime' === $duration;
	}

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

		// --- Overview -------------------------------------------------------
		register_rest_route( NROS_API_NS, '/admin/overview', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'overview' ),
			'permission_callback' => array( 'NROS_Core', 'require_staff' ),
		) );

		register_rest_route( NROS_API_NS, '/admin/analytics', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'site_analytics' ),
			'permission_callback' => array( 'NROS_Core', 'require_staff' ),
		) );

		register_rest_route( NROS_API_NS, '/admin/finance-summary', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'finance_summary' ),
			'permission_callback' => array( 'NROS_Core', 'require_staff' ),
		) );

		// --- Editorial queue --------------------------------------------------
		register_rest_route( NROS_API_NS, '/admin/queue', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'queue_list' ),
			'permission_callback' => array( 'NROS_Core', 'require_staff' ),
		) );

		register_rest_route( NROS_API_NS, '/admin/queue/(?P<id>\d+)/approve', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'queue_approve' ),
			'permission_callback' => array( 'NROS_Core', 'require_staff' ),
		) );

		register_rest_route( NROS_API_NS, '/admin/queue/(?P<id>\d+)/reject', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'queue_reject' ),
			'permission_callback' => array( 'NROS_Core', 'require_staff' ),
		) );

		// --- Correspondents -----------------------------------------------
		register_rest_route( NROS_API_NS, '/admin/correspondents', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'correspondents_list' ),
			'permission_callback' => array( 'NROS_Core', 'require_staff' ),
		) );

		register_rest_route( NROS_API_NS, '/admin/correspondents/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'correspondent_detail' ),
			'permission_callback' => array( 'NROS_Core', 'require_staff' ),
		) );

		register_rest_route( NROS_API_NS, '/admin/correspondents/(?P<id>\d+)/promote-editor', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'correspondent_promote_editor' ),
			'permission_callback' => array( 'NROS_Core', 'require_publisher' ),
		) );

		// --- Ad clients (minimal, foundational) ----------------------------
		register_rest_route( NROS_API_NS, '/admin/ad-clients', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'clients_list' ),
				'permission_callback' => array( 'NROS_Core', 'require_staff' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'clients_create' ),
				'permission_callback' => array( 'NROS_Core', 'require_staff' ),
			),
		) );

		register_rest_route( NROS_API_NS, '/admin/ad-clients/(?P<id>\d+)', array(
			'methods'             => 'PATCH',
			'callback'            => array( __CLASS__, 'clients_update' ),
			'permission_callback' => array( 'NROS_Core', 'require_staff' ),
		) );

		// --- Investigations ---------------------------------------------------
		register_rest_route( NROS_API_NS, '/admin/investigations', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'investigations_list' ),
				'permission_callback' => array( 'NROS_Core', 'require_staff' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'investigations_open' ),
				'permission_callback' => array( 'NROS_Core', 'require_staff' ),
			),
		) );

		register_rest_route( NROS_API_NS, '/admin/investigations/(?P<id>\d+)/resolve', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'investigations_resolve' ),
			'permission_callback' => array( 'NROS_Core', 'require_staff' ),
		) );

		// --- Suspensions --------------------------------------------------
		register_rest_route( NROS_API_NS, '/admin/suspensions', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'suspensions_list' ),
				'permission_callback' => array( 'NROS_Core', 'require_staff' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'suspensions_issue' ),
				'permission_callback' => array( 'NROS_Core', 'require_staff' ),
			),
		) );

		register_rest_route( NROS_API_NS, '/admin/suspensions/(?P<id>\d+)/lift', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'suspensions_lift' ),
			'permission_callback' => array( 'NROS_Core', 'require_staff' ),
		) );

		register_rest_route( NROS_API_NS, '/admin/suspensions/(?P<id>\d+)/review-appeal', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'suspensions_review_appeal' ),
			'permission_callback' => array( 'NROS_Core', 'require_publisher' ),
		) );

		// Correspondent-side: filing an appeal on one's own suspension.
		register_rest_route( NROS_API_NS, '/me/suspensions', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'my_suspensions' ),
			'permission_callback' => array( 'NROS_Core', 'require_correspondent' ),
		) );

		register_rest_route( NROS_API_NS, '/me/suspensions/(?P<id>\d+)/appeal', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'file_appeal' ),
			'permission_callback' => array( 'NROS_Core', 'require_correspondent' ),
		) );

		// --- Users & roles (Publisher only) --------------------------------
		register_rest_route( NROS_API_NS, '/admin/users', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'users_list' ),
				'permission_callback' => array( 'NROS_Core', 'require_publisher' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'users_create' ),
				'permission_callback' => array( 'NROS_Core', 'require_publisher' ),
			),
		) );

		register_rest_route( NROS_API_NS, '/admin/users/(?P<id>\d+)/role', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'users_set_role' ),
			'permission_callback' => array( 'NROS_Core', 'require_publisher' ),
		) );

		// --- Settings (Publisher only) --------------------------------------
		register_rest_route( NROS_API_NS, '/admin/settings', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'settings_get' ),
				'permission_callback' => array( 'NROS_Core', 'require_staff' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'settings_update' ),
				'permission_callback' => array( 'NROS_Core', 'require_publisher' ),
			),
		) );

		// Round 8's /me/prompt-template and the summary/staff template
		// fields on this route were replaced in round 9 by the unified,
		// role-visible prompt system — see NROS_Prompts (GET /prompts).
	}

	// -------------------------------------------------------------------------
	// Overview / analytics / finance
	// -------------------------------------------------------------------------

	/**
	 * GET /admin/overview — the numbers the dashboard's landing screen shows
	 * as KPI cards. Kept to cheap COUNT()s so the screen loads fast.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function overview( $request ) {
		global $wpdb;
		$user = NROS_Auth::get_user_from_request( $request );

		$pending_applications = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}nros_applications WHERE status = 'pending'" );
		$pending_queue        = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'pending'", 'post' ) );
		$pending_videos       = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'pending'", 'meeha_video' ) );
		$pending_withdrawals  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}nros_withdraw_requests WHERE status = 'pending'" );
		$open_cases           = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}nros_investigation_cases WHERE status = 'open'" );
		$active_suspensions   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}nros_suspensions WHERE status = 'active'" );
		$correspondents       = count( get_users( array( 'role' => 'correspondent', 'fields' => 'ID' ) ) );
		$active_clients       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}nros_ad_clients WHERE status = 'active'" );

		$data = array(
			'correspondents'       => $correspondents,
			'pending_applications' => $pending_applications,
			'pending_queue'        => $pending_queue,
			// Round 10 — video news awaiting review (additive field).
			'pending_videos'       => $pending_videos,
			'pending_withdrawals'  => $pending_withdrawals,
			'open_cases'           => $open_cases,
			'active_suspensions'   => $active_suspensions,
			'active_clients'       => $active_clients,
			'finance_visible'      => NROS_Core::financial_visible_to( $user ),
		);

		if ( $data['finance_visible'] ) {
			$data['pending_withdrawal_amount'] = (float) $wpdb->get_var( "SELECT COALESCE(SUM(amount),0) FROM {$wpdb->prefix}nros_withdraw_requests WHERE status = 'pending'" );
		}

		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * GET /admin/analytics — site-wide engagement trend (every published
	 * post, not just one correspondent's), reusing the same daily-snapshot
	 * table and ?range= contract as /me/analytics so the Home screen's chart
	 * component can be shared almost as-is between the two frontend apps.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function site_analytics( $request ) {
		global $wpdb;

		$range = sanitize_key( (string) $request->get_param( 'range' ) );
		if ( ! array_key_exists( $range, NROS_Analytics::RANGES ) ) {
			$range = '7';
		}

		// Round 10 fix: reactions/shares are serialized per-type arrays, so
		// the old SUM(CAST(meta_value AS UNSIGNED)) always produced 0 for
		// them. Views stay a cheap SQL SUM (a plain integer meta); the two
		// array metas are fetched in ONE query each — only the rows that
		// exist, only meta_value — and summed in PHP. Video news counts.
		$types_in = "'" . implode( "','", array_map( 'esc_sql', NROS_Analytics::ENGAGEMENT_POST_TYPES ) ) . "'";

		$views = (int) $wpdb->get_var(
			"SELECT COALESCE(SUM(CAST(pm.meta_value AS UNSIGNED)),0) FROM {$wpdb->postmeta} pm " // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			. "INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id "
			. "WHERE pm.meta_key = '_meeha_views' AND p.post_type IN ({$types_in}) AND p.post_status = 'publish'"
		);

		$array_totals = array();
		foreach ( array( 'reactions' => '_meeha_reactions', 'shares' => '_meeha_share_counts' ) as $field => $meta_key ) {
			$values = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id " // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					. "WHERE pm.meta_key = %s AND p.post_type IN ({$types_in}) AND p.post_status = 'publish'",
					$meta_key
				)
			);
			$sum = 0;
			foreach ( (array) $values as $raw ) {
				$sum += NROS_Analytics::sum_meta_value( $raw );
			}
			$array_totals[ $field ] = $sum;
		}

		$comments = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->comments} c INNER JOIN {$wpdb->posts} p ON p.ID = c.comment_post_ID " // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			. "WHERE p.post_type IN ({$types_in}) AND p.post_status = 'publish' AND c.comment_approved = '1'"
		);

		$days_back   = NROS_Analytics::RANGES[ $range ];
		$range_start = null === $days_back ? null : gmdate( 'Y-m-d', strtotime( "-{$days_back} days" ) );

		if ( null === $days_back ) {
			$date_sql   = '';
			$query_args = array();
		} else {
			// One extra buffer day before the visible range — see
			// NROS_Analytics::cumulative_rows_to_daily_deltas()'s docblock.
			$date_sql   = 'WHERE stat_date >= %s';
			$query_args = array( gmdate( 'Y-m-d', strtotime( '-' . ( $days_back + 1 ) . ' days' ) ) );
		}

		$sql = "SELECT stat_date, SUM(views) AS views, SUM(reactions) AS reactions, SUM(shares) AS shares, SUM(comments) AS comments " // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			. "FROM {$wpdb->prefix}nros_daily_stats {$date_sql} GROUP BY stat_date ORDER BY stat_date ASC";

		$raw_rows = $query_args ? $wpdb->get_results( $wpdb->prepare( $sql, $query_args ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$live_totals = array(
			'views'     => $views,
			'reactions' => $array_totals['reactions'],
			'shares'    => $array_totals['shares'],
			'comments'  => $comments,
		);

		$trend = NROS_Analytics::cumulative_rows_to_daily_deltas( $raw_rows, $range_start, $live_totals );

		return new WP_REST_Response(
			array(
				'totals' => $live_totals,
				'trend'  => $trend,
				'range'  => $range,
			),
			200
		);
	}

	/**
	 * GET /admin/finance-summary — ledger-derived totals. Gated beyond the
	 * route's own require_staff check: an Editor only sees this when
	 * Publisher has switched the finance-visibility setting on.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function finance_summary( $request ) {
		global $wpdb;
		$user = NROS_Auth::get_user_from_request( $request );

		if ( ! NROS_Core::financial_visible_to( $user ) ) {
			return new WP_Error( 'nros_forbidden', 'এই তথ্য দেখার অনুমতি নেই।', array( 'status' => 403 ) );
		}

		$by_type = $wpdb->get_results(
			"SELECT type, COALESCE(SUM(amount),0) AS total FROM {$wpdb->prefix}nros_ledger WHERE status = 'approved' GROUP BY type", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		// Round 12 — additive: Bengali label per type (video_payment is new) and the
		// not-yet-confirmed (pending) auto-applied article/video payments.
		$by_type = is_array( $by_type ) ? $by_type : array();
		foreach ( $by_type as &$row ) {
			$row['type_label'] = NROS_Ledger::type_label( $row['type'] );
		}
		unset( $row );
		$pending_by_type = $wpdb->get_results(
			"SELECT type, COALESCE(SUM(amount),0) AS total, COUNT(*) AS count FROM {$wpdb->prefix}nros_ledger WHERE status = 'pending' GROUP BY type", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);
		$pending_by_type = is_array( $pending_by_type ) ? $pending_by_type : array();
		foreach ( $pending_by_type as &$row ) {
			$row['type_label'] = NROS_Ledger::type_label( $row['type'] );
			$row['count']      = (int) $row['count'];
		}
		unset( $row );

		$paid_out    = (float) $wpdb->get_var( "SELECT COALESCE(SUM(amount),0) FROM {$wpdb->prefix}nros_withdraw_requests WHERE status = 'paid'" );
		$pending_out = (float) $wpdb->get_var( "SELECT COALESCE(SUM(amount),0) FROM {$wpdb->prefix}nros_withdraw_requests WHERE status = 'pending'" );

		return new WP_REST_Response(
			array(
				'by_type'          => $by_type,
				'pending_by_type'  => $pending_by_type,
				'paid_out'         => $paid_out,
				'pending_withdraw' => $pending_out,
			),
			200
		);
	}

	// -------------------------------------------------------------------------
	// Editorial queue
	// -------------------------------------------------------------------------

	/**
	 * GET /admin/queue — every correspondent's pending-review articles,
	 * across all authors (unlike NROS_Submissions::list_own, which is
	 * scoped to the calling correspondent).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function queue_list( $request ) {
		$query = new WP_Query(
			array(
				'post_type'      => 'post',
				'post_status'    => 'pending',
				'posts_per_page' => 100,
				'orderby'        => 'date',
				'order'          => 'ASC',
			)
		);

		$items = array_map(
			function ( $post ) {
				$author = get_userdata( $post->post_author );
				return array(
					'id'              => $post->ID,
					'title'           => $post->post_title,
					'excerpt'         => wp_trim_words( wp_strip_all_tags( $post->post_content ), 30 ),
					'featured_image'  => get_the_post_thumbnail_url( $post, 'medium' ) ?: null,
					'category_id'     => (int) ( wp_get_post_categories( $post->ID, array( 'fields' => 'ids' ) )[0] ?? 0 ),
					'author'          => $author ? array( 'id' => $author->ID, 'name' => $author->display_name ) : null,
					'created_at'      => $post->post_date,
				);
			},
			$query->posts
		);

		return new WP_REST_Response( $items, 200 );
	}

	/**
	 * POST /admin/queue/{id}/approve — publishes a pending article.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function queue_approve( $request ) {
		$id   = (int) $request->get_param( 'id' );
		$post = get_post( $id );

		if ( ! $post || 'post' !== $post->post_type || 'pending' !== $post->post_status ) {
			return new WP_Error( 'nros_not_found', 'এই সংবাদটি রিভিউ তালিকায় নেই।', array( 'status' => 404 ) );
		}

		$result = wp_update_post( array( 'ID' => $id, 'post_status' => 'publish' ), true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		/**
		 * Fires when a correspondent's article approved by staff goes live
		 * — immediately here, or (round 10) when WordPress publishes it if
		 * it was approved with a future date (see
		 * NROS_Admin_Posts::fire_or_defer_approval()).
		 *
		 * @param int $post_id Post id.
		 */
		NROS_Admin_Posts::fire_or_defer_approval( $id );

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * POST /admin/queue/{id}/reject — sends a pending article back to draft
	 * with a reason the correspondent can see and act on. Also snapshots a
	 * fingerprint of the article's current title/excerpt/content, so
	 * NROS_Submissions::submit_for_review() can later tell whether the
	 * correspondent actually changed anything before trying to resubmit —
	 * see that method for the enforcement side.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function queue_reject( $request ) {
		$id     = (int) $request->get_param( 'id' );
		$reason = sanitize_textarea_field( (string) $request->get_param( 'reason' ) );
		$post   = get_post( $id );

		if ( ! $post || 'post' !== $post->post_type || 'pending' !== $post->post_status ) {
			return new WP_Error( 'nros_not_found', 'এই সংবাদটি রিভিউ তালিকায় নেই।', array( 'status' => 404 ) );
		}

		$result = wp_update_post( array( 'ID' => $id, 'post_status' => 'draft' ), true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		update_post_meta( $id, '_nros_rejected', 1 );
		update_post_meta( $id, '_nros_reject_reason', $reason );
		update_post_meta( $id, '_nros_reject_hash', NROS_Submissions::content_fingerprint( $post ) );

		/**
		 * Fires after staff send a pending article back with a reason
		 * (round 18 — drives the correspondent's notification).
		 *
		 * @param int    $post_id Post id.
		 * @param string $reason  Reason.
		 */
		do_action( 'nros_submission_rejected', $id, $reason );

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	// -------------------------------------------------------------------------
	// Correspondents
	// -------------------------------------------------------------------------

	/**
	 * GET /admin/correspondents — roster with a quick status snapshot.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function correspondents_list( $request ) {
		$current        = NROS_Auth::get_user_from_request( $request );
		$finance_visible = NROS_Core::financial_visible_to( $current );
		$users          = get_users( array( 'role' => 'correspondent', 'orderby' => 'display_name' ) );

		$items = array_map(
			function ( $u ) use ( $finance_visible ) {
				$row = array(
					'id'           => $u->ID,
					'name'         => $u->display_name,
					'email'        => $u->user_email,
					'phone'        => get_user_meta( $u->ID, 'meeha_phone', true ),
					'division'     => get_user_meta( $u->ID, 'meeha_division', true ),
					'suspensions'  => NROS_Core::active_suspension_types( $u->ID ),
				);

				// Round 7 — the correspondent's standing per-post rate, shown
				// as a list column; gated behind finance-visibility the same
				// as every other money figure (not exposed to an Editor
				// whose Publisher hasn't switched financial figures on).
				if ( $finance_visible ) {
					$row['default_post_rate'] = NROS_Ledger::get_default_post_rate( $u->ID );
					// Round 12 — default per-video rate (same gate, same null-when-unset rule).
					$row['default_video_payment'] = NROS_Ledger::get_default_video_rate( $u->ID );
				}

				return $row;
			},
			$users
		);

		return new WP_REST_Response( $items, 200 );
	}

	/**
	 * GET /admin/correspondents/{id} — one correspondent's profile, article
	 * counts, balance (only alongside finance visibility), and standing.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function correspondent_detail( $request ) {
		global $wpdb;
		$current = NROS_Auth::get_user_from_request( $request );
		$id      = (int) $request->get_param( 'id' );
		$user    = get_userdata( $id );

		if ( ! $user || ! in_array( 'correspondent', (array) $user->roles, true ) ) {
			return new WP_Error( 'nros_not_found', 'পাওয়া যায়নি।', array( 'status' => 404 ) );
		}

		$counts = array();
		foreach ( array( 'draft', 'pending', 'publish' ) as $status ) {
			$counts[ $status ] = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_author = %d AND post_type = 'post' AND post_status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					$id,
					$status
				)
			);
		}

		$data = array(
			'id'            => $user->ID,
			'name'          => $user->display_name,
			'email'         => $user->user_email,
			'phone'         => get_user_meta( $id, 'meeha_phone', true ),
			'division'      => get_user_meta( $id, 'meeha_division', true ),
			'district'      => get_user_meta( $id, 'meeha_district', true ),
			'upazila'       => get_user_meta( $id, 'meeha_upazila', true ),
			'post_counts'   => $counts,
			'suspensions'   => NROS_Core::active_suspension_types( $id ),
		);

		if ( NROS_Core::financial_visible_to( $current ) ) {
			$data['available_balance']  = NROS_Ledger::available_balance( $id );
			$salary                     = (float) get_user_meta( $id, '_meeha_monthly_salary', true );
			$data['monthly_salary']     = $salary > 0 ? $salary : null;
			$data['default_post_rate']  = NROS_Ledger::get_default_post_rate( $id );
			$data['default_video_payment'] = NROS_Ledger::get_default_video_rate( $id );
		}

		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * POST /admin/correspondents/{id}/promote-editor — upgrades an existing
	 * correspondent account to the Editor role (Publisher-only, same as
	 * creating a brand-new Editor account via /admin/users). Uses
	 * WP_User::set_role(), which REPLACES the user's role list — they stop
	 * being a correspondent and become an Editor with the same WordPress
	 * user id, so their past authored articles and user meta are untouched.
	 * There is no separate "Editor" subdomain/app: this just switches which
	 * capabilities their existing login carries, and from then on they use
	 * publisher.meeha.news instead of correspondent.meeha.news.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function correspondent_promote_editor( $request ) {
		$id   = (int) $request->get_param( 'id' );
		$user = get_userdata( $id );

		if ( ! $user || ! in_array( 'correspondent', (array) $user->roles, true ) ) {
			return new WP_Error( 'nros_not_found', 'পাওয়া যায়নি।', array( 'status' => 404 ) );
		}

		$user->set_role( 'editor' );
		update_user_meta( $id, 'nros_promoted_from_correspondent_at', current_time( 'mysql' ) );

		// Round 7 belt-and-suspenders: a correspondent-only default per-post
		// rate has no meaning once this account is staff — cleared so it
		// can't linger as a stale setting (NROS_Ledger::maybe_apply_default_
		// payment() already independently re-checks the correspondent role
		// at the moment it would apply, so this alone isn't load-bearing,
		// but there's no reason to leave it configured for an account that
		// can no longer be auto-paid by it).
		delete_user_meta( $id, '_meeha_default_post_payment' );
		delete_user_meta( $id, NROS_Ledger::DEFAULT_VIDEO_RATE_META );

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	// -------------------------------------------------------------------------
	// Ad clients (minimal — full campaign/invoice management is the future
	// ads.meeha.news app's job; this exists only so commission/suspension
	// records have a real client to point at).
	// -------------------------------------------------------------------------

	/**
	 * GET /admin/ad-clients.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function clients_list( $request ) {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}nros_ad_clients ORDER BY created_at DESC LIMIT 300", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// Round 7 — these rows may now also carry correspondent-self-entered
		// fields (whatsapp/address/dealing_status) via NROS_Ad_Leads; the
		// stored attachment id is resolved to a URL here, same convention
		// as every other media field this API returns.
		$items = array_map(
			function ( $row ) {
				$row['visiting_card'] = $row['visiting_card_id'] ? wp_get_attachment_url( $row['visiting_card_id'] ) : null;
				unset( $row['visiting_card_id'] );
				return $row;
			},
			$rows
		);

		return new WP_REST_Response( $items, 200 );
	}

	/**
	 * POST /admin/ad-clients — records a referred business.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function clients_create( $request ) {
		global $wpdb;
		$current = NROS_Auth::get_user_from_request( $request );

		$business_name = sanitize_text_field( (string) $request->get_param( 'business_name' ) );
		if ( '' === $business_name ) {
			return new WP_Error( 'nros_bad_request', 'প্রতিষ্ঠানের নাম আবশ্যক।', array( 'status' => 400 ) );
		}

		$referred_by = (int) $request->get_param( 'referred_by' );
		if ( $referred_by && ! get_userdata( $referred_by ) ) {
			$referred_by = null;
		}

		$now = current_time( 'mysql' );
		$wpdb->insert(
			$wpdb->prefix . 'nros_ad_clients',
			array(
				'business_name' => $business_name,
				'contact_name'  => sanitize_text_field( (string) $request->get_param( 'contact_name' ) ),
				'phone'         => sanitize_text_field( (string) $request->get_param( 'phone' ) ),
				'whatsapp'      => sanitize_text_field( (string) $request->get_param( 'whatsapp' ) ),
				'address'       => sanitize_textarea_field( (string) $request->get_param( 'address' ) ),
				'referred_by'   => $referred_by ?: null,
				'status'        => 'active',
				'notes'         => sanitize_textarea_field( (string) $request->get_param( 'notes' ) ),
				'created_by'    => $current && ! is_wp_error( $current ) ? $current->ID : null,
				'created_at'    => $now,
				'updated_at'    => $now,
			)
		);

		return new WP_REST_Response( array( 'ok' => true, 'id' => (int) $wpdb->insert_id ), 201 );
	}

	/**
	 * PATCH /admin/ad-clients/{id} — updates status/notes (e.g. marking a
	 * client inactive once a confirmed-collusion case concludes).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function clients_update( $request ) {
		global $wpdb;
		$id = (int) $request->get_param( 'id' );

		$update = array( 'updated_at' => current_time( 'mysql' ) );

		$status = $request->get_param( 'status' );
		if ( null !== $status && in_array( $status, array( 'active', 'inactive' ), true ) ) {
			$update['status'] = $status;
		}

		$notes = $request->get_param( 'notes' );
		if ( null !== $notes ) {
			$update['notes'] = sanitize_textarea_field( (string) $notes );
		}

		$updated = $wpdb->update( $wpdb->prefix . 'nros_ad_clients', $update, array( 'id' => $id ) );
		if ( false === $updated ) {
			return new WP_Error( 'nros_not_found', 'পাওয়া যায়নি।', array( 'status' => 404 ) );
		}

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	// -------------------------------------------------------------------------
	// Investigations
	// -------------------------------------------------------------------------

	/**
	 * GET /admin/investigations.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function investigations_list( $request ) {
		global $wpdb;

		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		$where  = $status ? $wpdb->prepare( 'WHERE c.status = %s', $status ) : '';

		$rows = $wpdb->get_results(
			"SELECT c.*, u.display_name AS correspondent_name, cl.business_name AS client_name " // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			. "FROM {$wpdb->prefix}nros_investigation_cases c "
			. "LEFT JOIN {$wpdb->users} u ON u.ID = c.correspondent_id "
			. "LEFT JOIN {$wpdb->prefix}nros_ad_clients cl ON cl.id = c.client_id "
			. "{$where} ORDER BY c.created_at DESC LIMIT 200",
			ARRAY_A
		);

		return new WP_REST_Response( $rows, 200 );
	}

	/**
	 * POST /admin/investigations — opens a case against a correspondent.
	 * Per the finalized workflow, correspondents are investigated only for
	 * proven collusion/suppression with an advertiser — never for accurate,
	 * proven negative reporting on one — so this never auto-flags a story;
	 * it's always deliberately opened by the Editor+Ad-staff panel.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function investigations_open( $request ) {
		global $wpdb;
		$current         = NROS_Auth::get_user_from_request( $request );
		$correspondent_id = (int) $request->get_param( 'correspondent_id' );

		if ( ! get_userdata( $correspondent_id ) ) {
			return new WP_Error( 'nros_bad_request', 'সংবাদদাতা পাওয়া যায়নি।', array( 'status' => 400 ) );
		}

		$client_id = (int) $request->get_param( 'client_id' );

		$now = current_time( 'mysql' );
		$wpdb->insert(
			$wpdb->prefix . 'nros_investigation_cases',
			array(
				'correspondent_id' => $correspondent_id,
				'client_id'        => $client_id ?: null,
				'opened_by'        => $current && ! is_wp_error( $current ) ? $current->ID : null,
				'status'           => 'open',
				'summary'          => sanitize_textarea_field( (string) $request->get_param( 'summary' ) ),
				'created_at'       => $now,
				'updated_at'       => $now,
			)
		);

		return new WP_REST_Response( array( 'ok' => true, 'id' => (int) $wpdb->insert_id ), 201 );
	}

	/**
	 * POST /admin/investigations/{id}/resolve — records the panel's finding.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function investigations_resolve( $request ) {
		global $wpdb;
		$current = NROS_Auth::get_user_from_request( $request );
		$id      = (int) $request->get_param( 'id' );
		$status  = sanitize_key( (string) $request->get_param( 'status' ) );

		if ( ! in_array( $status, array( 'no_violation', 'violation_confirmed' ), true ) ) {
			return new WP_Error( 'nros_bad_request', 'সঠিক ফলাফল দিন।', array( 'status' => 400 ) );
		}

		$updated = $wpdb->update(
			$wpdb->prefix . 'nros_investigation_cases',
			array(
				'status'           => $status,
				'evidence_notes'   => sanitize_textarea_field( (string) $request->get_param( 'evidence_notes' ) ),
				'resolution_notes' => sanitize_textarea_field( (string) $request->get_param( 'resolution_notes' ) ),
				'resolved_by'      => $current && ! is_wp_error( $current ) ? $current->ID : null,
				'resolved_at'      => current_time( 'mysql' ),
				'updated_at'       => current_time( 'mysql' ),
			),
			array( 'id' => $id, 'status' => 'open' )
		);

		if ( ! $updated ) {
			return new WP_Error( 'nros_conflict', 'এই কেসটি আগেই সমাধান হয়েছে অথবা পাওয়া যায়নি।', array( 'status' => 409 ) );
		}

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	// -------------------------------------------------------------------------
	// Suspensions — the four-step punishment ladder
	// -------------------------------------------------------------------------

	/**
	 * Converts a duration code into an `ends_at` DATETIME, or null for
	 * 'lifetime'.
	 *
	 * @param string $duration One of NROS_Admin_API::DURATIONS.
	 * @return string|null
	 */
	protected static function duration_to_end_date( $duration ) {
		$map = array(
			'1m' => '+1 month',
			'3m' => '+3 months',
			'6m' => '+6 months',
		);

		if ( ! isset( $map[ $duration ] ) ) {
			return null; // lifetime.
		}

		return gmdate( 'Y-m-d H:i:s', strtotime( $map[ $duration ], current_time( 'timestamp' ) ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
	}

	/**
	 * GET /admin/suspensions.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function suspensions_list( $request ) {
		global $wpdb;

		$correspondent_id = (int) $request->get_param( 'correspondent_id' );
		$where             = $correspondent_id ? $wpdb->prepare( 'WHERE s.correspondent_id = %d', $correspondent_id ) : '';

		$rows = $wpdb->get_results(
			"SELECT s.*, u.display_name AS correspondent_name FROM {$wpdb->prefix}nros_suspensions s " // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			. "LEFT JOIN {$wpdb->users} u ON u.ID = s.correspondent_id {$where} ORDER BY s.created_at DESC LIMIT 300",
			ARRAY_A
		);

		return new WP_REST_Response( $rows, 200 );
	}

	/**
	 * POST /admin/suspensions — issues a punishment-ladder step. A full
	 * account suspension, or any lifetime-duration suspension, requires
	 * Publisher (or Administrator) even though the route itself only
	 * requires staff, since Editor sits on the panel for every earlier
	 * rung but the spec reserves the harshest step to Publisher.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function suspensions_issue( $request ) {
		global $wpdb;
		$current = NROS_Auth::get_user_from_request( $request );

		$correspondent_id = (int) $request->get_param( 'correspondent_id' );
		$type              = sanitize_key( (string) $request->get_param( 'type' ) );
		$duration          = sanitize_key( (string) $request->get_param( 'duration' ) );
		$client_id         = (int) $request->get_param( 'client_id' );
		$case_id           = (int) $request->get_param( 'case_id' );
		$reason            = sanitize_textarea_field( (string) $request->get_param( 'reason' ) );

		if ( ! get_userdata( $correspondent_id ) ) {
			return new WP_Error( 'nros_bad_request', 'সংবাদদাতা পাওয়া যায়নি।', array( 'status' => 400 ) );
		}
		if ( ! in_array( $type, self::SUSPENSION_TYPES, true ) ) {
			return new WP_Error( 'nros_bad_request', 'সঠিক সাসপেনশন ধরন দিন।', array( 'status' => 400 ) );
		}
		if ( ! in_array( $duration, self::DURATIONS, true ) ) {
			return new WP_Error( 'nros_bad_request', 'সঠিক মেয়াদ দিন।', array( 'status' => 400 ) );
		}
		if ( 'client_commission' === $type && ! $client_id ) {
			return new WP_Error( 'nros_bad_request', 'ক্লায়েন্ট নির্বাচন করুন।', array( 'status' => 400 ) );
		}

		if ( self::requires_publisher_sign_off( $type, $duration )
			&& ! user_can( $current, 'nros_publisher' ) && ! user_can( $current, 'manage_options' ) ) {
			return new WP_Error( 'nros_forbidden', 'সম্পূর্ণ একাউন্ট সাসপেনশন অথবা স্থায়ী মেয়াদের সিদ্ধান্ত শুধু পাবলিশার নিতে পারবেন।', array( 'status' => 403 ) );
		}

		$now = current_time( 'mysql' );
		$wpdb->insert(
			$wpdb->prefix . 'nros_suspensions',
			array(
				'correspondent_id' => $correspondent_id,
				'case_id'          => $case_id ?: null,
				'type'             => $type,
				'client_id'        => $client_id ?: null,
				'duration'         => $duration,
				'starts_at'        => $now,
				'ends_at'          => self::duration_to_end_date( $duration ),
				'reason'           => $reason,
				'issued_by'        => $current && ! is_wp_error( $current ) ? $current->ID : null,
				'status'           => 'active',
				'created_at'       => $now,
				'updated_at'       => $now,
			)
		);

		return new WP_REST_Response( array( 'ok' => true, 'id' => (int) $wpdb->insert_id ), 201 );
	}

	/**
	 * POST /admin/suspensions/{id}/lift — ends a suspension early.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function suspensions_lift( $request ) {
		global $wpdb;
		$id = (int) $request->get_param( 'id' );

		$updated = $wpdb->update(
			$wpdb->prefix . 'nros_suspensions',
			array( 'status' => 'lifted', 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => $id, 'status' => 'active' )
		);

		if ( ! $updated ) {
			return new WP_Error( 'nros_conflict', 'পাওয়া যায়নি অথবা আগেই শেষ হয়েছে।', array( 'status' => 409 ) );
		}

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * GET /me/suspensions — a correspondent's own suspension history, so the
	 * correspondent app can show why an action is blocked and offer appeal.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function my_suspensions( $request ) {
		global $wpdb;
		$user = NROS_Auth::get_user_from_request( $request );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, type, duration, starts_at, ends_at, reason, status, appeal_status FROM {$wpdb->prefix}nros_suspensions WHERE correspondent_id = %d ORDER BY created_at DESC", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$user->ID
			),
			ARRAY_A
		);

		return new WP_REST_Response( $rows, 200 );
	}

	/**
	 * POST /me/suspensions/{id}/appeal — a correspondent disputes their own
	 * suspension. Review is deliberately NOT automatic and is reserved to
	 * Publisher (suspensions_review_appeal), ideally someone outside the
	 * original investigating panel, per the final spec.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function file_appeal( $request ) {
		global $wpdb;
		$user = NROS_Auth::get_user_from_request( $request );
		$id   = (int) $request->get_param( 'id' );
		$note = sanitize_textarea_field( (string) $request->get_param( 'notes' ) );

		if ( '' === $note ) {
			return new WP_Error( 'nros_bad_request', 'আপিলের কারণ লিখুন।', array( 'status' => 400 ) );
		}

		$updated = $wpdb->update(
			$wpdb->prefix . 'nros_suspensions',
			array(
				'appeal_status' => 'pending',
				'appeal_notes'  => $note,
				'updated_at'    => current_time( 'mysql' ),
			),
			array( 'id' => $id, 'correspondent_id' => $user->ID )
		);

		if ( ! $updated ) {
			return new WP_Error( 'nros_not_found', 'পাওয়া যায়নি।', array( 'status' => 404 ) );
		}

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * POST /admin/suspensions/{id}/review-appeal — Publisher-only. Deciding
	 * "overturned" also lifts the suspension itself.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function suspensions_review_appeal( $request ) {
		global $wpdb;
		$current = NROS_Auth::get_user_from_request( $request );
		$id      = (int) $request->get_param( 'id' );
		$verdict = sanitize_key( (string) $request->get_param( 'verdict' ) );

		if ( ! in_array( $verdict, array( 'upheld', 'overturned' ), true ) ) {
			return new WP_Error( 'nros_bad_request', 'সঠিক সিদ্ধান্ত দিন।', array( 'status' => 400 ) );
		}

		$update = array(
			'appeal_status' => $verdict,
			'updated_at'    => current_time( 'mysql' ),
		);
		if ( 'overturned' === $verdict ) {
			$update['status'] = 'lifted';
		}

		$updated = $wpdb->update(
			$wpdb->prefix . 'nros_suspensions',
			$update,
			array( 'id' => $id, 'appeal_status' => 'pending' )
		);

		if ( ! $updated ) {
			return new WP_Error( 'nros_conflict', 'পাওয়া যায়নি অথবা কোনো পেন্ডিং আপিল নেই।', array( 'status' => 409 ) );
		}

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	// -------------------------------------------------------------------------
	// Users & roles (Publisher only)
	// -------------------------------------------------------------------------

	/**
	 * GET /admin/users — Editor and Publisher accounts (not correspondents —
	 * those are managed via /admin/correspondents instead).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function users_list( $request ) {
		$users = get_users( array( 'role__in' => array( 'editor', 'meeha_publisher', 'administrator' ) ) );

		$items = array_map(
			function ( $u ) {
				return array(
					'id'    => $u->ID,
					'name'  => $u->display_name,
					'email' => $u->user_email,
					'roles' => array_values( (array) $u->roles ),
				);
			},
			$users
		);

		return new WP_REST_Response( $items, 200 );
	}

	/**
	 * POST /admin/users — creates a new Editor or Publisher account and
	 * emails them a generated password. Deliberately cannot create another
	 * Administrator from here — that stays IT/Dev's own wp-admin territory.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function users_create( $request ) {
		$name  = sanitize_text_field( (string) $request->get_param( 'name' ) );
		$email = sanitize_email( (string) $request->get_param( 'email' ) );
		$role  = sanitize_key( (string) $request->get_param( 'role' ) );

		if ( '' === $name || ! is_email( $email ) ) {
			return new WP_Error( 'nros_bad_request', 'নাম ও সঠিক ইমেইল দিন।', array( 'status' => 400 ) );
		}
		if ( ! in_array( $role, array( 'editor', 'meeha_publisher' ), true ) ) {
			return new WP_Error( 'nros_bad_request', 'শুধু এডিটর বা পাবলিশার রোল দেওয়া যাবে।', array( 'status' => 400 ) );
		}
		if ( email_exists( $email ) ) {
			return new WP_Error( 'nros_email_exists', 'এই ইমেইলে আগেই একাউন্ট আছে।', array( 'status' => 409 ) );
		}

		$username = sanitize_user( current( explode( '@', $email ) ) . '-' . wp_generate_password( 4, false ), true );
		$password = wp_generate_password( 14, true );

		$user_id = wp_insert_user(
			array(
				'user_login'   => $username,
				'user_email'   => $email,
				'user_pass'    => $password,
				'display_name' => $name,
				'role'         => $role,
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		wp_mail(
			$email,
			'মিহা নিউজ — আপনার একাউন্ট তৈরি হয়েছে',
			"প্রিয় {$name},\n\npublisher.meeha.news-এ আপনার একাউন্ট তৈরি হয়েছে।\n\nলগইন: {$username}\nপাসওয়ার্ড: {$password}\n\nঅনুগ্রহ করে লগইন করে পাসওয়ার্ড পরিবর্তন করুন।"
		);

		return new WP_REST_Response( array( 'ok' => true, 'user_id' => $user_id ), 201 );
	}

	/**
	 * POST /admin/users/{id}/role — promotes/demotes between Editor and
	 * Publisher. Refuses to touch an Administrator account, same boundary
	 * as users_create().
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function users_set_role( $request ) {
		$id   = (int) $request->get_param( 'id' );
		$role = sanitize_key( (string) $request->get_param( 'role' ) );
		$user = get_userdata( $id );

		if ( ! $user ) {
			return new WP_Error( 'nros_not_found', 'পাওয়া যায়নি।', array( 'status' => 404 ) );
		}
		if ( in_array( 'administrator', (array) $user->roles, true ) ) {
			return new WP_Error( 'nros_forbidden', 'অ্যাডমিনিস্ট্রেটরের রোল এখান থেকে পরিবর্তন করা যাবে না।', array( 'status' => 403 ) );
		}
		if ( ! in_array( $role, array( 'editor', 'meeha_publisher' ), true ) ) {
			return new WP_Error( 'nros_bad_request', 'শুধু এডিটর বা পাবলিশার রোল দেওয়া যাবে।', array( 'status' => 400 ) );
		}

		$user->set_role( $role );

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	// -------------------------------------------------------------------------
	// Settings (finance-visibility toggle + minimal SEO)
	// -------------------------------------------------------------------------

	/**
	 * GET /admin/settings — readable by any staff (so the Editor UI knows
	 * whether to render financial figures) but only Publisher can change
	 * them (see register_routes()).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function settings_get( $request ) {
		return new WP_REST_Response(
			array(
				'editor_finance_visible' => (bool) get_option( 'nros_editor_finance_visible', false ),
				'seo_title'              => (string) get_option( 'nros_seo_title', '' ),
				'seo_description'        => (string) get_option( 'nros_seo_description', '' ),
				'view_tiers'             => NROS_Ledger::get_view_tiers(),
				// Round 10 — what Editors may do to articles/videos beyond
				// editing them (see NROS_Core::staff_can()).
				'editor_permissions'     => NROS_Core::editor_permissions(),
			),
			200
		);
	}

	/**
	 * POST /admin/settings — Publisher-only updates.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function settings_update( $request ) {
		$finance_visible = $request->get_param( 'editor_finance_visible' );
		if ( null !== $finance_visible ) {
			update_option( 'nros_editor_finance_visible', (bool) $finance_visible );
		}

		$seo_title = $request->get_param( 'seo_title' );
		if ( null !== $seo_title ) {
			update_option( 'nros_seo_title', sanitize_text_field( (string) $seo_title ) );
		}

		$seo_description = $request->get_param( 'seo_description' );
		if ( null !== $seo_description ) {
			update_option( 'nros_seo_description', sanitize_textarea_field( (string) $seo_description ) );
		}

		$view_tiers = $request->get_param( 'view_tiers' );
		if ( null !== $view_tiers ) {
			$result = NROS_Ledger::save_view_tiers( $view_tiers );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		// Round 10 — partial updates allowed ({ delete_posts: true } alone
		// keeps the other two as they were); unknown keys are ignored.
		$editor_permissions = $request->get_param( 'editor_permissions' );
		if ( null !== $editor_permissions ) {
			if ( ! is_array( $editor_permissions ) ) {
				return new WP_Error( 'nros_bad_request', 'এডিটরের অনুমতির তথ্য সঠিক নয়।', array( 'status' => 400 ) );
			}
			$merged = NROS_Core::editor_permissions();
			foreach ( array_keys( NROS_Core::EDITOR_PERMISSION_DEFAULTS ) as $key ) {
				if ( array_key_exists( $key, $editor_permissions ) ) {
					$merged[ $key ] = rest_sanitize_boolean( $editor_permissions[ $key ] );
				}
			}
			update_option( 'nros_editor_permissions', $merged, false );
		}

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}
}
