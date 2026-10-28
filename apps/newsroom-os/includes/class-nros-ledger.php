<?php
/**
 * NROS_Ledger — correspondent earnings (honorarium + ad-referral commission)
 * and the withdraw flow: setting a payout method, requesting a withdrawal,
 * and staff marking requests as paid. Plain manual honorarium/commission
 * entries (`admin_add_entry()`, type honorarium/commission/bonus/adjustment)
 * are still added manually by staff via wp-admin/the Finance screen.
 *
 * Round 5 adds three automatic/semi-automatic ledger sources, each with its
 * own dedicated, idempotent code path rather than going through
 * `admin_add_entry()` — mixing manual entries into these would break the
 * "exactly one row per post" / "exactly one row per month" invariants they
 * depend on:
 * - `post_payment` — a single flat amount a Publisher sets on one specific
 *   article (`set_post_payment()`); at most one row per post+type, upserted
 *   in place so changing the amount doesn't create duplicates.
 * - `view_bonus` — cumulative view-count tier bonuses (`apply_view_tier_bonus()`),
 *   checked once a day by the same cron that snapshots engagement stats
 *   (`NROS_Analytics::run_daily_snapshot()`). Per the user's explicit choice,
 *   every tier a post's view count has crossed stacks (10k→50tk AND
 *   50k→200tk both credit once 50k is reached), tracked via a "total
 *   credited so far" post meta so re-running the check never double-pays.
 * - `salary` — an optional fixed monthly amount per correspondent
 *   (`set_monthly_salary()`), auto-credited once a month by its own cron
 *   (`maybe_run_monthly_payroll()`), guarded by a "last paid month" user meta
 *   so a cron that fires more than once on the 1st never double-pays.
 *
 * Per the user's explicit choice, a post's flat payment and its view-tier
 * bonus are independent and both apply (stack) — setting one never disables
 * the other.
 *
 * Round 7 adds a correspondent-level DEFAULT per-post rate
 * (`_meeha_default_post_payment` usermeta, `set_default_rate()`), for a
 * correspondent paid a fixed average per article under a standing
 * arrangement rather than one-off amounts a Publisher sets by hand. When
 * such a post of theirs is approved/published and no `post_payment` row
 * already exists for it, `maybe_apply_default_payment()` auto-inserts one —
 * but with `status = 'pending'`, not 'approved'. `available_balance()`
 * already (and only) sums `status = 'approved'` rows, so a pending
 * auto-applied amount is correctly excluded from the correspondent's
 * spendable balance until a Publisher explicitly confirms it
 * (`confirm_post_payment()`), per the user's explicit choice — zero changes
 * needed to the balance query itself. A Publisher directly setting/editing a
 * post's payment (`set_post_payment()`) always resolves straight to
 * 'approved', since that's a deliberate one-off decision, not a
 * fire-and-forget default.
 *
 * @package NewsroomOS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NROS_Ledger {

	const METHODS = array( 'bkash', 'nagad', 'rocket', 'bank' );

	/**
	 * Registers routes and the monthly payroll cron.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );

		add_action( 'nros_monthly_payroll_event', array( __CLASS__, 'maybe_run_monthly_payroll' ) );

		if ( ! wp_next_scheduled( 'nros_monthly_payroll_event' ) ) {
			// Scheduled daily (WP core has no built-in monthly interval); the
			// handler itself only does anything on the 1st of the month, so
			// an off-by-a-few-minutes cron run is harmless either way.
			wp_schedule_event( strtotime( 'tomorrow 00:30:00' ), 'daily', 'nros_monthly_payroll_event' );
		}

		// Fired by NROS_Admin_API::queue_approve() and (on a pending→publish
		// transition) NROS_Admin_Posts::update() — see this class's own
		// docblock for why the auto-applied row lands as 'pending' rather
		// than 'approved'.
		add_action( 'nros_submission_approved', array( __CLASS__, 'maybe_apply_default_payment' ) );

		// Round 12 — per-video payments for correspondents' uploaded video news. The
		// default video rate is auto-applied (pending) on the video's first publish:
		// NROS_Videos::save() calls maybe_apply_default_video_payment() after it has written
		// the video's meta; this hook covers every other path (scheduled → published by cron,
		// wp-admin). Both are idempotent.
		add_action( 'transition_post_status', array( __CLASS__, 'on_video_transition' ), 20, 3 );
	}

	/** Ledger type of a per-video payment (one row per video, dedupe_key = video id). */
	const VIDEO_PAYMENT_TYPE = 'video_payment';

	/** User meta: a correspondent's default per-video rate. */
	const DEFAULT_VIDEO_RATE_META = '_meeha_default_video_payment';

	/**
	 * Bengali labels for ledger entry types (sent as `type_label` so apps that don't know a
	 * newer type still show a proper name).
	 *
	 * @return array<string,string>
	 */
	public static function type_labels() {
		return array(
			'honorarium'    => 'সম্মানী',
			'commission'    => 'কমিশন',
			'bonus'         => 'বোনাস',
			'adjustment'    => 'সমন্বয়',
			'post_payment'  => 'পোস্ট পেমেন্ট',
			'video_payment' => 'ভিডিও পেমেন্ট',
			'view_bonus'    => 'ভিউ-স্ল্যাব বোনাস',
			'salary'        => 'মাসিক স্যালারি',
		);
	}

	/**
	 * @param string $type Ledger type.
	 * @return string
	 */
	public static function type_label( $type ) {
		$labels = self::type_labels();
		return isset( $labels[ $type ] ) ? $labels[ $type ] : (string) $type;
	}

	/**
	 * Registers REST routes under nros/v1.
	 */
	public static function register_routes() {
		register_rest_route(
			NROS_API_NS,
			'/me/earnings',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'earnings' ),
				'permission_callback' => array( 'NROS_Core', 'require_correspondent' ),
			)
		);

		register_rest_route(
			NROS_API_NS,
			'/me/withdraw-method',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_method' ),
					'permission_callback' => array( 'NROS_Core', 'require_correspondent' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'save_method' ),
					'permission_callback' => array( 'NROS_Core', 'require_correspondent' ),
				),
			)
		);

		register_rest_route(
			NROS_API_NS,
			'/me/withdraw-requests',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'list_own_requests' ),
					'permission_callback' => array( 'NROS_Core', 'require_correspondent' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'create_request' ),
					'permission_callback' => array( 'NROS_Core', 'require_correspondent' ),
				),
			)
		);

		register_rest_route(
			NROS_API_NS,
			'/admin/withdraw-requests',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'admin_list_requests' ),
				'permission_callback' => array( 'NROS_Core', 'require_staff' ),
			)
		);

		register_rest_route(
			NROS_API_NS,
			'/admin/withdraw-requests/(?P<id>\d+)/mark-paid',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'admin_mark_paid' ),
				'permission_callback' => array( 'NROS_Core', 'require_staff' ),
			)
		);

		register_rest_route(
			NROS_API_NS,
			'/admin/ledger',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'admin_add_entry' ),
				'permission_callback' => array( 'NROS_Core', 'require_staff' ),
			)
		);

		register_rest_route(
			NROS_API_NS,
			'/admin/posts/(?P<id>\d+)/payment',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'set_post_payment' ),
				'permission_callback' => array( 'NROS_Core', 'require_publisher' ),
			)
		);

		register_rest_route(
			NROS_API_NS,
			'/admin/correspondents/(?P<id>\d+)/salary',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'set_monthly_salary' ),
				'permission_callback' => array( 'NROS_Core', 'require_publisher' ),
			)
		);

		// Round 7 — the correspondent-level default per-post rate.
		register_rest_route(
			NROS_API_NS,
			'/admin/correspondents/(?P<id>\d+)/default-rate',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'set_default_rate' ),
				'permission_callback' => array( 'NROS_Core', 'require_publisher' ),
			)
		);

		// Round 7 — confirms a pending (auto-applied default-rate) post
		// payment, moving it into the correspondent's available balance.
		register_rest_route(
			NROS_API_NS,
			'/admin/posts/(?P<id>\d+)/payment/confirm',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'confirm_post_payment' ),
				'permission_callback' => array( 'NROS_Core', 'require_publisher' ),
			)
		);

		// Round 12 — per-video payments, mirroring the article routes above (same
		// permission: Publisher/Administrator).
		register_rest_route(
			NROS_API_NS,
			'/admin/videos/(?P<id>\d+)/payment',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'set_video_payment' ),
				'permission_callback' => array( 'NROS_Core', 'require_publisher' ),
			)
		);
		register_rest_route(
			NROS_API_NS,
			'/admin/videos/(?P<id>\d+)/payment/confirm',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'confirm_video_payment' ),
				'permission_callback' => array( 'NROS_Core', 'require_publisher' ),
			)
		);
		register_rest_route(
			NROS_API_NS,
			'/admin/correspondents/(?P<id>\d+)/default-video-rate',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'set_default_video_rate' ),
				'permission_callback' => array( 'NROS_Core', 'require_publisher' ),
			)
		);
	}

	/**
	 * Computes a correspondent's available balance: the sum of approved
	 * ledger credits, minus any withdrawal that isn't rejected (i.e. both
	 * pending and already-paid withdrawals reduce the spendable balance).
	 *
	 * @param int $user_id User id.
	 * @return float
	 */
	public static function available_balance( $user_id ) {
		global $wpdb;

		$credits = (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(amount),0) FROM {$wpdb->prefix}nros_ledger WHERE user_id = %d AND status = 'approved'", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$user_id
			)
		);

		$withdrawn = (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(amount),0) FROM {$wpdb->prefix}nros_withdraw_requests WHERE user_id = %d AND status != 'rejected'", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$user_id
			)
		);

		return round( $credits - $withdrawn, 2 );
	}

	/**
	 * GET /me/earnings — ledger history plus the current available balance.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function earnings( $request ) {
		global $wpdb;
		$user = NROS_Auth::get_user_from_request( $request );

		$entries = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, type, amount, status, note, post_id, created_at FROM {$wpdb->prefix}nros_ledger WHERE user_id = %d ORDER BY created_at DESC LIMIT 200", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$user->ID
			),
			ARRAY_A
		);

		// Round 12 — additive: Bengali type label (video_payment is new) and the article/video id.
		$entries = is_array( $entries ) ? $entries : array();
		foreach ( $entries as &$entry ) {
			$entry['type_label'] = self::type_label( $entry['type'] );
			$entry['post_id']    = null === $entry['post_id'] ? null : (int) $entry['post_id'];
		}
		unset( $entry );

		return new WP_REST_Response(
			array(
				'available_balance' => self::available_balance( $user->ID ),
				'entries'            => $entries,
			),
			200
		);
	}

	/**
	 * GET /me/withdraw-method — returns the saved method with only a
	 * masked last-4-digits of the account number, never the full number.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function get_method( $request ) {
		$user = NROS_Auth::get_user_from_request( $request );

		$method = get_user_meta( $user->ID, 'nros_withdraw_method', true );
		$last4  = get_user_meta( $user->ID, 'nros_withdraw_last4', true );

		return new WP_REST_Response(
			array(
				'method'       => $method ?: null,
				'account_last4' => $last4 ?: null,
				'configured'   => (bool) $method,
			),
			200
		);
	}

	/**
	 * POST /me/withdraw-method — saves/updates the payout method. The
	 * account number is encrypted at rest (NROS_Core::encrypt) and only its
	 * last 4 digits are kept in plaintext for display purposes.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function save_method( $request ) {
		$user   = NROS_Auth::get_user_from_request( $request );
		$method = sanitize_key( (string) $request->get_param( 'method' ) );
		$number = preg_replace( '/\s+/', '', (string) $request->get_param( 'account_number' ) );

		if ( ! in_array( $method, self::METHODS, true ) ) {
			return new WP_Error( 'nros_bad_request', 'অবৈধ পদ্ধতি।', array( 'status' => 400 ) );
		}

		if ( strlen( $number ) < 4 ) {
			return new WP_Error( 'nros_bad_request', 'একাউন্ট নম্বর সঠিক নয়।', array( 'status' => 400 ) );
		}

		update_user_meta( $user->ID, 'nros_withdraw_method', $method );
		update_user_meta( $user->ID, 'nros_withdraw_account_enc', NROS_Core::encrypt( $number ) );
		update_user_meta( $user->ID, 'nros_withdraw_last4', substr( $number, -4 ) );

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * GET /me/withdraw-requests — the correspondent's own withdrawal
	 * history.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function list_own_requests( $request ) {
		global $wpdb;
		$user = NROS_Auth::get_user_from_request( $request );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, amount, method, status, created_at, updated_at FROM {$wpdb->prefix}nros_withdraw_requests WHERE user_id = %d ORDER BY created_at DESC LIMIT 100", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$user->ID
			),
			ARRAY_A
		);

		return new WP_REST_Response( $rows, 200 );
	}

	/**
	 * POST /me/withdraw-requests — requests a withdrawal of the available
	 * balance (or less). Requires a payout method already on file, and
	 * caps the amount at the current available balance.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_request( $request ) {
		global $wpdb;
		$user   = NROS_Auth::get_user_from_request( $request );
		$amount = (float) $request->get_param( 'amount' );

		$method = get_user_meta( $user->ID, 'nros_withdraw_method', true );
		if ( ! $method ) {
			return new WP_Error( 'nros_no_method', 'প্রথমে উইথড্র পদ্ধতি সেট করুন।', array( 'status' => 400 ) );
		}

		if ( $amount <= 0 ) {
			return new WP_Error( 'nros_bad_request', 'সঠিক পরিমাণ লিখুন।', array( 'status' => 400 ) );
		}

		$available = self::available_balance( $user->ID );
		if ( $amount > $available ) {
			return new WP_Error( 'nros_insufficient_balance', 'উপলব্ধ ব্যালেন্সের চেয়ে বেশি উইথড্র করা যাবে না।', array( 'status' => 409 ) );
		}

		$now = current_time( 'mysql' );
		$wpdb->insert(
			$wpdb->prefix . 'nros_withdraw_requests',
			array(
				'user_id'    => $user->ID,
				'amount'     => $amount,
				'method'     => $method,
				'status'     => 'pending',
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
		$request_id = (int) $wpdb->insert_id;

		/** Round 18 — notifies finance-visible staff. */
		do_action( 'nros_withdraw_requested', $request_id, $user->ID, $amount );

		return new WP_REST_Response( array( 'ok' => true, 'id' => $request_id ), 201 );
	}

	/**
	 * GET /admin/withdraw-requests — staff view of all pending/processed
	 * withdrawal requests, newest first.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function admin_list_requests( $request ) {
		global $wpdb;

		$current = NROS_Auth::get_user_from_request( $request );
		if ( ! NROS_Core::financial_visible_to( $current ) ) {
			return new WP_Error( 'nros_forbidden', 'এই তথ্য দেখার অনুমতি নেই।', array( 'status' => 403 ) );
		}

		$status = sanitize_text_field( (string) $request->get_param( 'status' ) );
		$where  = $status ? $wpdb->prepare( 'WHERE status = %s', $status ) : '';

		$rows = $wpdb->get_results(
			"SELECT w.*, u.display_name, u.user_email FROM {$wpdb->prefix}nros_withdraw_requests w " // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			. "INNER JOIN {$wpdb->users} u ON u.ID = w.user_id {$where} ORDER BY w.created_at DESC LIMIT 200",
			ARRAY_A
		);

		return new WP_REST_Response( $rows, 200 );
	}

	/**
	 * POST /admin/withdraw-requests/{id}/mark-paid — the WHERE clause is
	 * guarded to status='pending' so a double-click (or two staff acting at
	 * once) can't process the same request twice.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function admin_mark_paid( $request ) {
		global $wpdb;

		$current = NROS_Auth::get_user_from_request( $request );
		if ( ! NROS_Core::financial_visible_to( $current ) ) {
			return new WP_Error( 'nros_forbidden', 'এই অ্যাক্সেস নেই।', array( 'status' => 403 ) );
		}

		$id   = (int) $request->get_param( 'id' );
		$note = sanitize_textarea_field( (string) $request->get_param( 'note' ) );

		$updated = $wpdb->update(
			$wpdb->prefix . 'nros_withdraw_requests',
			array(
				'status'     => 'paid',
				'admin_note' => $note,
				'updated_at' => current_time( 'mysql' ),
			),
			array(
				'id'     => $id,
				'status' => 'pending',
			)
		);

		if ( ! $updated ) {
			return new WP_Error( 'nros_conflict', 'এই রিকোয়েস্ট আগেই প্রসেস হয়েছে অথবা পাওয়া যায়নি।', array( 'status' => 409 ) );
		}

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * POST /admin/ledger — staff manually adds an honorarium or commission
	 * entry to a correspondent's ledger.
	 *
	 * NOTE: this is the interim mechanism. Automatic entries — honorarium
	 * on article publish, commission on a referred ad client's confirmed
	 * payment (20% month 1, 10% thereafter, only after payment is received,
	 * never on invoicing) — are a deferred future phase and are not yet
	 * implemented.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function admin_add_entry( $request ) {
		global $wpdb;

		$current_user = NROS_Auth::get_user_from_request( $request );
		if ( ! NROS_Core::financial_visible_to( $current_user ) ) {
			return new WP_Error( 'nros_forbidden', 'এই অ্যাক্সেস নেই।', array( 'status' => 403 ) );
		}

		$user_id = (int) $request->get_param( 'user_id' );
		$type    = sanitize_key( (string) $request->get_param( 'type' ) );
		$amount  = (float) $request->get_param( 'amount' );
		$note    = sanitize_textarea_field( (string) $request->get_param( 'note' ) );

		if ( ! get_user_by( 'id', $user_id ) ) {
			return new WP_Error( 'nros_bad_request', 'ব্যবহারকারী পাওয়া যায়নি।', array( 'status' => 400 ) );
		}

		if ( ! in_array( $type, array( 'honorarium', 'commission', 'bonus', 'adjustment' ), true ) || 0 === $amount ) {
			return new WP_Error( 'nros_bad_request', 'সঠিক ধরন ও পরিমাণ দিন।', array( 'status' => 400 ) );
		}

		$wpdb->insert(
			$wpdb->prefix . 'nros_ledger',
			array(
				'user_id'    => $user_id,
				'type'       => $type,
				'amount'     => $amount,
				'status'     => 'approved',
				'note'       => $note,
				'created_by' => $current_user && ! is_wp_error( $current_user ) ? $current_user->ID : null,
				'created_at' => current_time( 'mysql' ),
			)
		);

		return new WP_REST_Response( array( 'ok' => true, 'id' => (int) $wpdb->insert_id ), 201 );
	}

	/**
	 * A post's current flat payment amount and its ledger status, if a
	 * `post_payment` row exists for it — both null if not, per the user's
	 * rule that an unset post shows no income for this mechanism at all (as
	 * opposed to 0, which would still render as a figure). Shared by
	 * NROS_Admin_Posts::shape() (Publisher's editor/list) and
	 * NROS_Submissions::shape() (correspondent's own post view).
	 *
	 * status is 'approved' for anything a Publisher set directly
	 * (set_post_payment()) or already confirmed, and 'pending' for an
	 * amount auto-applied from a correspondent's default rate
	 * (maybe_apply_default_payment()) that hasn't been confirmed yet — a
	 * pending row's amount is intentionally still returned here (so the UI
	 * can show it, labeled pending) even though available_balance() excludes
	 * it from the correspondent's spendable total.
	 *
	 * @param int $post_id Post id.
	 * @return array{amount: float|null, status: string|null}
	 */
	public static function post_payment_info( $post_id, $type = 'post_payment' ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT amount, status FROM {$wpdb->prefix}nros_ledger WHERE post_id = %d AND type = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$post_id,
				$type
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return array( 'amount' => null, 'status' => null );
		}

		return array( 'amount' => (float) $row['amount'], 'status' => $row['status'] );
	}

	/**
	 * POST /admin/posts/{id}/payment — Publisher-only. Sets (or clears, with
	 * amount <= 0 or omitted) a single flat payment for one specific
	 * article. At most one `post_payment` ledger row ever exists per post —
	 * this upserts that row in place rather than inserting a new one each
	 * time the amount is changed, and deletes it outright when cleared so
	 * the post goes back to showing no income for this mechanism (per the
	 * user's explicit rule). Independent of, and stacks with, any view-tier
	 * bonus the same post may also earn.
	 *
	 * A Publisher explicitly setting/editing an amount here is always a
	 * deliberate decision, so the row always resolves to 'approved' — even
	 * when it overwrites a 'pending' row that was auto-applied from the
	 * correspondent's default rate (maybe_apply_default_payment()). That's
	 * the explicit "confirm by editing" path; confirm_post_payment() is the
	 * "confirm without changing the amount" path.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function set_post_payment( $request ) {
		$post_id = (int) $request->get_param( 'id' );
		$post    = get_post( $post_id );

		if ( ! $post || 'post' !== $post->post_type ) {
			return new WP_Error( 'nros_not_found', 'পোস্টটি পাওয়া যায়নি।', array( 'status' => 404 ) );
		}

		return self::upsert_item_payment( $post, $request, 'post_payment', 'পোস্ট পেমেন্ট: %s' );
	}

	/**
	 * Shared body of set_post_payment() / set_video_payment(): upserts (or, for amount <= 0,
	 * deletes) the single payment row of one article/video, always resolving to 'approved'.
	 *
	 * @param WP_Post         $post    Article or video.
	 * @param WP_REST_Request $request Request (amount).
	 * @param string          $type    post_payment|video_payment.
	 * @param string          $note    sprintf() note with %s = title.
	 * @return WP_REST_Response
	 */
	protected static function upsert_item_payment( $post, $request, $type, $note ) {
		global $wpdb;

		$current = NROS_Auth::get_user_from_request( $request );
		$post_id = (int) $post->ID;

		$table       = $wpdb->prefix . 'nros_ledger';
		$existing_id = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE post_id = %d AND type = %s LIMIT 1", $post_id, $type ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		$amount_param = $request->get_param( 'amount' );
		$amount       = null === $amount_param ? 0.0 : round( (float) $amount_param, 2 );

		if ( $amount <= 0 ) {
			if ( $existing_id ) {
				$wpdb->delete( $table, array( 'id' => $existing_id ) );
			}
			return new WP_REST_Response( array( 'ok' => true, 'amount' => null ), 200 );
		}

		if ( $existing_id ) {
			$wpdb->update( $table, array( 'amount' => $amount, 'status' => 'approved' ), array( 'id' => $existing_id ) );
		} else {
			$inserted = $wpdb->insert(
				$table,
				array(
					'user_id'    => $post->post_author,
					'type'       => $type,
					'amount'     => $amount,
					'status'     => 'approved',
					'note'       => sprintf( $note, wp_strip_all_tags( $post->post_title ) ),
					'post_id'    => $post_id,
					'created_by' => $current && ! is_wp_error( $current ) ? $current->ID : null,
					'created_at' => current_time( 'mysql' ),
					'dedupe_key' => $post_id,
				)
			);

			// A near-simultaneous request (or maybe_apply_default_payment()
			// firing right around the same time) may have already inserted a
			// row for this post, which the UNIQUE KEY(type, dedupe_key) on
			// nros_ledger rejects here — fall back to updating that row
			// instead of silently losing this explicit Publisher action.
			if ( false === $inserted ) {
				$existing_id = (int) $wpdb->get_var(
					$wpdb->prepare( "SELECT id FROM {$table} WHERE post_id = %d AND type = %s LIMIT 1", $post_id, $type ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				);
				if ( $existing_id ) {
					$wpdb->update( $table, array( 'amount' => $amount, 'status' => 'approved' ), array( 'id' => $existing_id ) );
				}
			}
		}

		return new WP_REST_Response( array( 'ok' => true, 'amount' => $amount, 'payment_status' => 'approved' ), 200 );
	}

	/**
	 * POST /admin/posts/{id}/payment/confirm — Publisher-only. Confirms an
	 * auto-applied default-rate payment (status 'pending') WITHOUT changing
	 * its amount, moving it into the correspondent's available balance. This
	 * is the "just approve what was auto-applied" path; set_post_payment()
	 * (which always resolves to 'approved') is the "approve AND change the
	 * amount" path. A 409 if the post has no pending post_payment row —
	 * either it was never auto-applied, or it's already approved.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function confirm_post_payment( $request ) {
		return self::confirm_item_payment( (int) $request->get_param( 'id' ), 'post_payment', 'এই পোস্টের কোনো পেন্ডিং পেমেন্ট নেই অথবা পাওয়া যায়নি।' );
	}

	/**
	 * Shared body of confirm_post_payment() / confirm_video_payment().
	 *
	 * @param int    $post_id Article/video id.
	 * @param string $type    Ledger type.
	 * @param string $missing 409 message.
	 * @return WP_REST_Response|WP_Error
	 */
	protected static function confirm_item_payment( $post_id, $type, $missing ) {
		global $wpdb;

		$updated = $wpdb->update(
			$wpdb->prefix . 'nros_ledger',
			array( 'status' => 'approved' ),
			array( 'post_id' => $post_id, 'type' => $type, 'status' => 'pending' )
		);

		if ( ! $updated ) {
			return new WP_Error( 'nros_conflict', $missing, array( 'status' => 409 ) );
		}

		$info = self::post_payment_info( $post_id, $type );

		return new WP_REST_Response( array( 'ok' => true, 'amount' => $info['amount'], 'payment_status' => $info['status'] ), 200 );
	}

	// ---------------------------------------------------------------------
	// Round 12 — per-video payments (correspondents' uploaded video news)
	// ---------------------------------------------------------------------

	/**
	 * A video's payment row: {amount, status}, both null when unset (same rule as articles).
	 *
	 * @param int $video_id Video id.
	 * @return array{amount: float|null, status: string|null}
	 */
	public static function video_payment_info( $video_id ) {
		return self::post_payment_info( $video_id, self::VIDEO_PAYMENT_TYPE );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_Post|WP_Error
	 */
	protected static function video_or_error( $request ) {
		$post = get_post( (int) $request->get_param( 'id' ) );
		if ( ! $post || 'meeha_video' !== $post->post_type || 'trash' === $post->post_status ) {
			return new WP_Error( 'nros_not_found', 'ভিডিও পাওয়া যায়নি।', array( 'status' => 404 ) );
		}
		return $post;
	}

	/**
	 * POST /admin/videos/{id}/payment {amount} — Publisher-only; sets/overrides (approved)
	 * or clears (amount <= 0) one video's payment. Mirrors set_post_payment().
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function set_video_payment( $request ) {
		$post = self::video_or_error( $request );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		return self::upsert_item_payment( $post, $request, self::VIDEO_PAYMENT_TYPE, 'ভিডিও পেমেন্ট: %s' );
	}

	/**
	 * POST /admin/videos/{id}/payment/confirm — Publisher-only; confirms an auto-applied
	 * (pending) default-rate video payment without changing the amount. 409 when none pending.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function confirm_video_payment( $request ) {
		$post = self::video_or_error( $request );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		return self::confirm_item_payment( (int) $post->ID, self::VIDEO_PAYMENT_TYPE, 'এই ভিডিওর কোনো পেন্ডিং পেমেন্ট নেই।' );
	}

	/**
	 * A correspondent's default per-video rate, or null.
	 *
	 * @param int $user_id User id.
	 * @return float|null
	 */
	public static function get_default_video_rate( $user_id ) {
		$amount = (float) get_user_meta( $user_id, self::DEFAULT_VIDEO_RATE_META, true );
		return $amount > 0 ? $amount : null;
	}

	/**
	 * Writes (amount > 0) or clears a correspondent's default video rate.
	 *
	 * @param int   $user_id User id.
	 * @param mixed $amount  Raw amount.
	 * @return float|null Stored rate.
	 */
	protected static function store_default_video_rate( $user_id, $amount ) {
		$amount = null === $amount ? 0.0 : round( (float) $amount, 2 );
		if ( $amount <= 0 ) {
			delete_user_meta( $user_id, self::DEFAULT_VIDEO_RATE_META );
			return null;
		}
		update_user_meta( $user_id, self::DEFAULT_VIDEO_RATE_META, $amount );
		return $amount;
	}

	/**
	 * POST /admin/correspondents/{id}/default-video-rate {amount} — Publisher-only.
	 * (POST …/default-rate also accepts `video_amount`.)
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function set_default_video_rate( $request ) {
		$id   = (int) $request->get_param( 'id' );
		$user = get_userdata( $id );
		if ( ! $user || ! in_array( 'correspondent', (array) $user->roles, true ) ) {
			return new WP_Error( 'nros_not_found', 'পাওয়া যায়নি।', array( 'status' => 404 ) );
		}
		$rate = self::store_default_video_rate( $id, $request->get_param( 'amount' ) );
		return new WP_REST_Response( array( 'ok' => true, 'default_video_payment' => $rate ), 200 );
	}

	/**
	 * Auto-applies the author's default video rate as a 'pending' video_payment row on the
	 * video's FIRST publish, only for a correspondent's UPLOADED video (not YouTube), only
	 * when no payment row exists yet. Idempotent (flag meta + UNIQUE KEY(type, dedupe_key)).
	 *
	 * @param int $video_id Video id.
	 */
	public static function maybe_apply_default_video_payment( $video_id ) {
		global $wpdb;

		$post = get_post( $video_id );
		if ( ! $post || 'meeha_video' !== $post->post_type || 'publish' !== $post->post_status ) {
			return;
		}
		if ( 'upload' !== (string) get_post_meta( $post->ID, '_meeha_video_source', true ) ) {
			return;
		}
		if ( get_post_meta( $post->ID, '_nros_video_default_applied', true ) ) {
			return; // Only the first publish.
		}

		$author_id = (int) $post->post_author;
		$author    = get_userdata( $author_id );
		if ( ! $author || ! in_array( 'correspondent', (array) $author->roles, true ) ) {
			return;
		}

		$table  = $wpdb->prefix . 'nros_ledger';
		$exists = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE post_id = %d AND type = %s", $post->ID, self::VIDEO_PAYMENT_TYPE ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
		update_post_meta( $post->ID, '_nros_video_default_applied', time() );
		if ( $exists ) {
			return;
		}

		$rate = self::get_default_video_rate( $author_id );
		if ( ! $rate ) {
			return;
		}

		$wpdb->insert(
			$table,
			array(
				'user_id'    => $author_id,
				'type'       => self::VIDEO_PAYMENT_TYPE,
				'amount'     => $rate,
				'status'     => 'pending',
				'note'       => sprintf( 'ডিফল্ট ভিডিও পেমেন্ট (নিশ্চিতকরণ বাকি) — %s', wp_strip_all_tags( $post->post_title ) ),
				'post_id'    => $post->ID,
				'created_by' => null,
				'created_at' => current_time( 'mysql' ),
				'dedupe_key' => $post->ID,
			)
		);
	}

	/**
	 * transition_post_status: a video becoming 'publish' (cron for scheduled ones, wp-admin).
	 * Skipped while the video's source meta isn't written yet (NROS_Videos::save() applies it
	 * itself right after writing the meta).
	 *
	 * @param string  $new_status New.
	 * @param string  $old_status Old.
	 * @param WP_Post $post       Post.
	 */
	public static function on_video_transition( $new_status, $old_status, $post ) {
		if ( 'publish' !== $new_status || 'publish' === $old_status || ! $post || 'meeha_video' !== $post->post_type ) {
			return;
		}
		if ( '' === (string) get_post_meta( $post->ID, '_meeha_video_source', true ) ) {
			return;
		}
		self::maybe_apply_default_video_payment( $post->ID );
	}

	/**
	 * A correspondent's configured default per-post rate, or null if none
	 * (per the user's rule: no default configured means this mechanism
	 * shows no income at all, never ৳0).
	 *
	 * @param int $user_id User id.
	 * @return float|null
	 */
	public static function get_default_post_rate( $user_id ) {
		$amount = (float) get_user_meta( $user_id, '_meeha_default_post_payment', true );
		return $amount > 0 ? $amount : null;
	}

	/**
	 * POST /admin/correspondents/{id}/default-rate — Publisher-only. Sets
	 * (or clears, with amount <= 0 or omitted) a correspondent's standing
	 * per-post rate — e.g. a contract-based average of ৫ টাকা/post. Doesn't
	 * retroactively touch any already-published post; only applies going
	 * forward, the next time one of their articles is approved/published
	 * (see maybe_apply_default_payment()).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function set_default_rate( $request ) {
		$id   = (int) $request->get_param( 'id' );
		$user = get_userdata( $id );

		if ( ! $user || ! in_array( 'correspondent', (array) $user->roles, true ) ) {
			return new WP_Error( 'nros_not_found', 'পাওয়া যায়নি।', array( 'status' => 404 ) );
		}

		// Round 12 — optional `video_amount` sets the default per-video rate in the same call.
		// A body with ONLY video_amount leaves the per-post rate untouched.
		$has_video = null !== $request->get_param( 'video_amount' );
		if ( $has_video ) {
			self::store_default_video_rate( $id, $request->get_param( 'video_amount' ) );
			if ( null === $request->get_param( 'amount' ) ) {
				return new WP_REST_Response(
					array(
						'ok'                    => true,
						'default_post_rate'     => self::get_default_post_rate( $id ),
						'default_video_payment' => self::get_default_video_rate( $id ),
					),
					200
				);
			}
		}

		$amount_param = $request->get_param( 'amount' );
		$amount       = null === $amount_param ? 0.0 : round( (float) $amount_param, 2 );

		if ( $amount <= 0 ) {
			delete_user_meta( $id, '_meeha_default_post_payment' );
		} else {
			update_user_meta( $id, '_meeha_default_post_payment', $amount );
		}

		return new WP_REST_Response(
			array(
				'ok'                    => true,
				'default_post_rate'     => $amount > 0 ? $amount : null,
				'default_video_payment' => self::get_default_video_rate( $id ),
			),
			200
		);
	}

	/**
	 * Hooked to `nros_submission_approved` (fired on a correspondent's
	 * pending→publish transition — see NROS_Admin_API::queue_approve() and
	 * NROS_Admin_Posts::update()). Auto-applies the post author's default
	 * per-post rate, as a 'pending' ledger row, but ONLY when:
	 * - the author CURRENTLY holds the correspondent role (checked here,
	 *   not inferred from the hook's call site) — so a correspondent later
	 *   promoted to Editor (NROS_Admin_API::correspondent_promote_editor())
	 *   who still has a default rate configured on their old account never
	 *   has it auto-applied to an article they now write/publish as staff
	 *   through the exact same pending→publish code path in
	 *   NROS_Admin_Posts::update();
	 * - the author actually has a default rate configured, and
	 * - no `post_payment` row already exists for this post at all (manual
	 *   or previously auto-applied) — so this never overwrites a Publisher's
	 *   explicit one-off amount, and re-firing the hook (or any other
	 *   accidental re-trigger) can never double-insert; the UNIQUE
	 *   KEY(type, dedupe_key) on nros_ledger (see NROS_Core::create_tables())
	 *   is the DB-level backstop in case two such checks ever race.
	 *
	 * @param int $post_id Post id.
	 */
	public static function maybe_apply_default_payment( $post_id ) {
		global $wpdb;

		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}

		$author_id = (int) $post->post_author;
		$author    = get_userdata( $author_id );
		if ( ! $author || ! in_array( 'correspondent', (array) $author->roles, true ) ) {
			return;
		}

		$table = $wpdb->prefix . 'nros_ledger';

		$exists = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE post_id = %d AND type = 'post_payment'", $post_id ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
		if ( $exists ) {
			return;
		}

		$default_rate = self::get_default_post_rate( $author_id );
		if ( ! $default_rate ) {
			return;
		}

		$wpdb->insert(
			$table,
			array(
				'user_id'    => $author_id,
				'type'       => 'post_payment',
				'amount'     => $default_rate,
				'status'     => 'pending',
				'note'       => sprintf( 'ডিফল্ট পোস্ট পেমেন্ট (নিশ্চিতকরণ বাকি) — %s', wp_strip_all_tags( $post->post_title ) ),
				'post_id'    => $post_id,
				'created_by' => null,
				'created_at' => current_time( 'mysql' ),
				'dedupe_key' => $post_id,
			)
		);
		// If this insert lost a race against a concurrent one (rejected by
		// the UNIQUE KEY), there's nothing further to do — a row for this
		// post now exists either way, which is exactly the invariant this
		// method exists to guarantee.
	}

	/**
	 * POST /admin/correspondents/{id}/salary — Publisher-only. Sets (or
	 * clears, with amount <= 0 or omitted) a correspondent's optional fixed
	 * monthly salary. Takes effect from the next payroll run
	 * (`maybe_run_monthly_payroll()`) onward — doesn't retroactively credit
	 * the current month.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function set_monthly_salary( $request ) {
		$id   = (int) $request->get_param( 'id' );
		$user = get_userdata( $id );

		if ( ! $user || ! in_array( 'correspondent', (array) $user->roles, true ) ) {
			return new WP_Error( 'nros_not_found', 'পাওয়া যায়নি।', array( 'status' => 404 ) );
		}

		$amount_param = $request->get_param( 'amount' );
		$amount       = null === $amount_param ? 0.0 : round( (float) $amount_param, 2 );

		if ( $amount <= 0 ) {
			delete_user_meta( $id, '_meeha_monthly_salary' );
		} else {
			update_user_meta( $id, '_meeha_monthly_salary', $amount );
		}

		return new WP_REST_Response( array( 'ok' => true, 'monthly_salary' => $amount > 0 ? $amount : null ), 200 );
	}

	/**
	 * Cron-driven (scheduled daily, no-ops on every day but the 1st): pays
	 * every correspondent who has a monthly salary configured, once per
	 * calendar month. The `_meeha_salary_last_paid_month` guard means a cron
	 * that somehow fires twice on the 1st (or a manual re-trigger) never
	 * pays the same month twice.
	 */
	public static function maybe_run_monthly_payroll() {
		global $wpdb;

		if ( 1 !== (int) current_time( 'j' ) ) {
			return;
		}

		$month_key = current_time( 'Y-m' );

		$users = get_users(
			array(
				'role'       => 'correspondent',
				'meta_key'   => '_meeha_monthly_salary', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_compare' => 'EXISTS',
			)
		);

		foreach ( $users as $user ) {
			$amount = (float) get_user_meta( $user->ID, '_meeha_monthly_salary', true );
			if ( $amount <= 0 ) {
				continue;
			}

			$last_paid = get_user_meta( $user->ID, '_meeha_salary_last_paid_month', true );
			if ( $month_key === $last_paid ) {
				continue;
			}

			$wpdb->insert(
				$wpdb->prefix . 'nros_ledger',
				array(
					'user_id'    => $user->ID,
					'type'       => 'salary',
					'amount'     => $amount,
					'status'     => 'approved',
					'note'       => sprintf( 'মাসিক বেতন — %s', $month_key ),
					'post_id'    => null,
					'created_by' => null,
					'created_at' => current_time( 'mysql' ),
				)
			);

			update_user_meta( $user->ID, '_meeha_salary_last_paid_month', $month_key );
		}
	}

	/**
	 * Cron-driven, called once per post from
	 * NROS_Analytics::run_daily_snapshot() with that post's current live
	 * view count. Per the user's explicit choice, every configured tier the
	 * post's views have crossed stacks cumulatively (not just the highest
	 * one) — so this computes the TOTAL the post should have earned from
	 * tiers by now and only credits the difference versus what's already
	 * been credited (tracked in `_meeha_view_bonus_credited`), making
	 * repeated daily runs safe to re-check as views keep climbing.
	 *
	 * @param int $post_id Post id.
	 * @param int $author_id Post author's user id.
	 * @param int $views Current live view count.
	 */
	public static function apply_view_tier_bonus( $post_id, $author_id, $views ) {
		global $wpdb;

		$tiers = self::get_view_tiers();
		if ( ! $tiers ) {
			return;
		}

		$target_total = 0.0;
		foreach ( $tiers as $tier ) {
			if ( $views >= $tier['views'] ) {
				$target_total += $tier['amount'];
			}
		}

		if ( $target_total <= 0 ) {
			return;
		}

		$credited_total = (float) get_post_meta( $post_id, '_meeha_view_bonus_credited', true );
		$delta          = round( $target_total - $credited_total, 2 );

		if ( $delta <= 0 ) {
			return;
		}

		$wpdb->insert(
			$wpdb->prefix . 'nros_ledger',
			array(
				'user_id'    => $author_id,
				'type'       => 'view_bonus',
				'amount'     => $delta,
				'status'     => 'approved',
				'note'       => sprintf( 'ভিউ বোনাস — %s ভিউ অতিক্রম', number_format_i18n( $views ) ),
				'post_id'    => $post_id,
				'created_by' => null,
				'created_at' => current_time( 'mysql' ),
			)
		);

		update_post_meta( $post_id, '_meeha_view_bonus_credited', round( $credited_total + $delta, 2 ) );
	}

	/**
	 * The Publisher-configured view-count tiers (views => amount), sorted
	 * ascending by views. Stored as JSON in the `nros_view_tiers` option —
	 * no new table needed for a handful of rows.
	 *
	 * @return array<int, array{views: int, amount: float}>
	 */
	public static function get_view_tiers() {
		$raw = get_option( 'nros_view_tiers', '[]' );
		$decoded = json_decode( (string) $raw, true );

		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$tiers = array();
		foreach ( $decoded as $row ) {
			if ( ! isset( $row['views'], $row['amount'] ) ) {
				continue;
			}
			$views  = (int) $row['views'];
			$amount = (float) $row['amount'];
			if ( $views > 0 && $amount > 0 ) {
				$tiers[] = array( 'views' => $views, 'amount' => $amount );
			}
		}

		usort( $tiers, fn( $a, $b ) => $a['views'] <=> $b['views'] );

		return $tiers;
	}

	/**
	 * Validates and saves the Publisher-configured view-count tiers.
	 * Rejects malformed rows rather than silently dropping them — a typo'd
	 * tier that silently never saves is a worse failure mode for a money
	 * setting than an explicit 400.
	 *
	 * @param mixed $tiers Raw tiers value from the request (expected array).
	 * @return true|WP_Error
	 */
	public static function save_view_tiers( $tiers ) {
		if ( ! is_array( $tiers ) ) {
			return new WP_Error( 'nros_bad_request', 'স্ল্যাব তালিকা সঠিক নয়।', array( 'status' => 400 ) );
		}

		$clean = array();
		foreach ( $tiers as $row ) {
			$views  = isset( $row['views'] ) ? (int) $row['views'] : 0;
			$amount = isset( $row['amount'] ) ? (float) $row['amount'] : 0;
			if ( $views <= 0 || $amount <= 0 ) {
				return new WP_Error( 'nros_bad_request', 'প্রতিটি স্ল্যাবে সঠিক ভিউ সংখ্যা ও টাকার পরিমাণ দিন।', array( 'status' => 400 ) );
			}
			$clean[] = array( 'views' => $views, 'amount' => round( $amount, 2 ) );
		}

		usort( $clean, fn( $a, $b ) => $a['views'] <=> $b['views'] );

		update_option( 'nros_view_tiers', wp_json_encode( $clean ) );

		return true;
	}
}
