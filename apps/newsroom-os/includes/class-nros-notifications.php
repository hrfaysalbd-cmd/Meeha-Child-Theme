<?php
/**
 * NROS_Notifications — in-app notifications for both apps (round 18).
 *
 * One row per recipient in `nros_notifications`. Rows are written from
 * WordPress hooks, so every path that changes a post's state (the apps'
 * REST routes, wp-admin, scheduled publishing by cron) produces the same
 * notification:
 *
 * Correspondent: own article/video approved, rejected (with the reason).
 * Staff (Editor/Publisher/Administrator): new article/video in the review
 * queue, new correspondent application, new edit request; Publisher (or
 * an Editor with finance visibility): new withdrawal request.
 *
 * The apps poll GET /notifications every few seconds while visible. The
 * reply is small: new rows after the client's last seen id, the unread
 * count and, for staff, live counters for the sidebar badges.
 *
 * @package NewsroomOS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NROS_Notifications {

	/** Rows older than this are pruned. */
	const KEEP_DAYS = 90;

	/** Staff roles that receive newsroom notifications. */
	const STAFF_ROLES = array( 'administrator', 'meeha_publisher', 'editor' );

	/**
	 * Registers routes and hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );

		add_action( 'transition_post_status', array( __CLASS__, 'on_transition' ), 20, 3 );
		add_action( 'nros_submission_rejected', array( __CLASS__, 'on_rejected' ), 10, 2 );
		add_action( 'nros_video_rejected', array( __CLASS__, 'on_rejected' ), 10, 2 );
		add_action( 'nros_application_submitted', array( __CLASS__, 'on_application' ), 10, 2 );
		add_action( 'nros_withdraw_requested', array( __CLASS__, 'on_withdraw_request' ), 10, 3 );
		add_action( 'save_post_meeha_edit_req', array( __CLASS__, 'on_edit_request' ), 10, 3 );

		add_action( 'nros_notifications_prune', array( __CLASS__, 'prune' ) );
		if ( ! wp_next_scheduled( 'nros_notifications_prune' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'nros_notifications_prune' );
		}
	}

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'nros_notifications';
	}

	/**
	 * CREATE TABLE statement for NROS_Core::create_tables() (dbDelta).
	 *
	 * @param string $charset_collate Charset/collation clause.
	 * @return string
	 */
	public static function schema( $charset_collate ) {
		$table = self::table();
		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			type VARCHAR(40) NOT NULL,
			title VARCHAR(255) NOT NULL,
			body TEXT,
			link VARCHAR(255) DEFAULT '',
			object_id BIGINT UNSIGNED DEFAULT NULL,
			is_read TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY user_id_id (user_id, id),
			KEY user_unread (user_id, is_read)
		) {$charset_collate};";
	}

	/**
	 * Registers REST routes under nros/v1.
	 */
	public static function register_routes() {
		register_rest_route(
			NROS_API_NS,
			'/notifications',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_notifications' ),
				'permission_callback' => array( 'NROS_Core', 'require_app_user' ),
			)
		);
		register_rest_route(
			NROS_API_NS,
			'/notifications/read',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'mark_read' ),
				'permission_callback' => array( 'NROS_Core', 'require_app_user' ),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Writing
	// -------------------------------------------------------------------------

	/**
	 * Adds one notification for each of the given users.
	 *
	 * @param int[]  $user_ids  Recipients.
	 * @param string $type      Machine type (e.g. submission_approved).
	 * @param string $title     One-line Bengali title.
	 * @param string $body      Optional detail.
	 * @param string $link      In-app route the notification opens.
	 * @param int    $object_id Related post/application id.
	 */
	public static function add( $user_ids, $type, $title, $body = '', $link = '', $object_id = 0 ) {
		global $wpdb;
		$user_ids = array_unique( array_filter( array_map( 'intval', (array) $user_ids ) ) );
		if ( ! $user_ids ) {
			return;
		}
		$now = current_time( 'mysql' );
		foreach ( $user_ids as $uid ) {
			$wpdb->insert(
				self::table(),
				array(
					'user_id'    => $uid,
					'type'       => substr( $type, 0, 40 ),
					'title'      => mb_substr( wp_strip_all_tags( $title ), 0, 250 ),
					'body'       => wp_strip_all_tags( (string) $body ),
					'link'       => substr( (string) $link, 0, 250 ),
					'object_id'  => $object_id ? (int) $object_id : null,
					'is_read'    => 0,
					'created_at' => $now,
				)
			);
		}
	}

	/**
	 * Staff user ids (optionally only those who can see finance).
	 *
	 * @param bool $finance_only Only users with finance visibility.
	 * @return int[]
	 */
	public static function staff_ids( $finance_only = false ) {
		$users = get_users(
			array(
				'role__in' => self::STAFF_ROLES,
				'fields'   => array( 'ID' ),
				'number'   => 200,
			)
		);
		$ids = array();
		foreach ( $users as $u ) {
			$id = (int) $u->ID;
			if ( $finance_only ) {
				$user = get_userdata( $id );
				if ( ! $user || ! NROS_Core::financial_visible_to( $user ) ) {
					continue;
				}
			}
			$ids[] = $id;
		}
		return $ids;
	}

	/**
	 * Whether a user is a correspondent.
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	protected static function is_correspondent( $user_id ) {
		$user = get_userdata( (int) $user_id );
		return $user && in_array( 'correspondent', (array) $user->roles, true );
	}

	/**
	 * Short, safe quote of a post title for a notification line.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	protected static function quote_title( $post ) {
		$title = trim( wp_strip_all_tags( get_the_title( $post ) ) );
		if ( '' === $title ) {
			$title = 'শিরোনামহীন';
		}
		if ( mb_strlen( $title ) > 80 ) {
			$title = mb_substr( $title, 0, 78 ) . '…';
		}
		return '“' . $title . '”';
	}

	// -------------------------------------------------------------------------
	// Hooks
	// -------------------------------------------------------------------------

	/**
	 * Article/video status changes → queue + approval notifications.
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param WP_Post $post       Post.
	 */
	public static function on_transition( $new_status, $old_status, $post ) {
		if ( ! in_array( $post->post_type, array( 'post', 'meeha_video' ), true ) || $new_status === $old_status ) {
			return;
		}
		if ( ! self::is_correspondent( $post->post_author ) ) {
			return;
		}
		$is_video = 'meeha_video' === $post->post_type;
		$author   = get_userdata( (int) $post->post_author );
		$name     = $author ? $author->display_name : '';

		if ( 'pending' === $new_status ) {
			$resubmitted = (bool) get_post_meta( $post->ID, '_nros_rejected', true );
			self::add(
				self::staff_ids(),
				$is_video ? 'queue_video' : 'queue_post',
				( $is_video ? 'নতুন ভিডিও রিভিউয়ের অপেক্ষায়' : 'নতুন সংবাদ রিভিউয়ের অপেক্ষায়' ) . ( $resubmitted ? ' (সংশোধিত)' : '' ),
				self::quote_title( $post ) . ( $name ? ' — ' . $name : '' ),
				$is_video ? '/app/videos?tab=pending' : '/app/queue',
				$post->ID
			);
			return;
		}

		if ( 'pending' === $old_status && in_array( $new_status, array( 'publish', 'future' ), true ) ) {
			$scheduled = 'future' === $new_status;
			self::add(
				array( (int) $post->post_author ),
				$is_video ? 'video_approved' : 'submission_approved',
				( $is_video ? 'আপনার ভিডিও অনুমোদিত হয়েছে' : 'আপনার সংবাদ অনুমোদিত হয়েছে' ) . ( $scheduled ? ' (প্রকাশের সময় নির্ধারিত)' : '' ),
				self::quote_title( $post ),
				$is_video ? '/app/news?tab=video&status=approved' : '/app/news?status=approved',
				$post->ID
			);
		}
	}

	/**
	 * Rejection (fired explicitly by the reject routes, after the reason is
	 * saved).
	 *
	 * @param int    $post_id Post id.
	 * @param string $reason  Reason given by staff.
	 */
	public static function on_rejected( $post_id, $reason = '' ) {
		$post = get_post( $post_id );
		if ( ! $post || ! self::is_correspondent( $post->post_author ) ) {
			return;
		}
		$is_video = 'meeha_video' === $post->post_type;
		$body     = self::quote_title( $post );
		if ( '' !== trim( (string) $reason ) ) {
			$body .= "\nকারণ: " . $reason;
		}
		self::add(
			array( (int) $post->post_author ),
			$is_video ? 'video_rejected' : 'submission_rejected',
			$is_video ? 'আপনার ভিডিও ফেরত পাঠানো হয়েছে' : 'আপনার সংবাদ ফেরত পাঠানো হয়েছে',
			$body,
			$is_video ? '/app/video/' . $post->ID : '/app/write/' . $post->ID,
			$post->ID
		);
	}

	/**
	 * New correspondent application.
	 *
	 * @param int   $application_id Application id.
	 * @param array $data           full_name, district, upazila.
	 */
	public static function on_application( $application_id, $data ) {
		$where = trim( implode( ', ', array_filter( array( $data['upazila'] ?? '', $data['district'] ?? '' ) ) ) );
		self::add(
			self::staff_ids(),
			'application',
			'নতুন সংবাদদাতা আবেদন',
			( $data['full_name'] ?? '' ) . ( $where ? ' — ' . $where : '' ),
			'/app/applications',
			$application_id
		);
	}

	/**
	 * New withdrawal request (finance-visible staff only).
	 *
	 * @param int   $request_id Request id.
	 * @param int   $user_id    Correspondent.
	 * @param float $amount     Amount.
	 */
	public static function on_withdraw_request( $request_id, $user_id, $amount ) {
		$user = get_userdata( (int) $user_id );
		self::add(
			self::staff_ids( true ),
			'withdraw_request',
			'নতুন উইথড্র রিকোয়েস্ট',
			( $user ? $user->display_name . ' — ' : '' ) . '৳ ' . number_format_i18n( (float) $amount ),
			'/app/finance',
			$request_id
		);
	}

	/**
	 * New edit request on a published article.
	 *
	 * @param int     $post_id Edit request post id.
	 * @param WP_Post $post    Edit request post.
	 * @param bool    $update  Whether this is an update.
	 */
	public static function on_edit_request( $post_id, $post, $update ) {
		if ( $update || wp_is_post_revision( $post_id ) ) {
			return;
		}
		// wp_insert_post() saves meta_input before the save_post hooks run.
		$target  = (int) get_post_meta( $post_id, '_meeha_edit_req_target', true );
		$article = $target ? get_post( $target ) : null;
		self::add(
			self::staff_ids(),
			'edit_request',
			'নতুন এডিট রিকোয়েস্ট',
			( $article ? self::quote_title( $article ) . "\n" : '' ) . wp_strip_all_tags( $post->post_content ),
			$target ? '/app/write/' . $target : '/app/posts',
			$target ? $target : $post_id
		);
	}

	/**
	 * Deletes old rows (daily cron).
	 */
	public static function prune() {
		global $wpdb;
		$table  = self::table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) - self::KEEP_DAYS * DAY_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	// -------------------------------------------------------------------------
	// REST
	// -------------------------------------------------------------------------

	/**
	 * Shapes a row for the apps.
	 *
	 * @param array $row DB row.
	 * @return array
	 */
	protected static function shape( $row ) {
		return array(
			'id'         => (int) $row['id'],
			'type'       => $row['type'],
			'title'      => $row['title'],
			'body'       => (string) $row['body'],
			'link'       => (string) $row['link'],
			'object_id'  => $row['object_id'] ? (int) $row['object_id'] : null,
			'read'       => (bool) (int) $row['is_read'],
			// Stored in site-local time; sent as UTC ISO-8601 so every device shows it right.
			'created_at' => mysql_to_rfc3339( get_gmt_from_date( $row['created_at'] ) ) . 'Z',
		);
	}

	/**
	 * GET /notifications?after=<id>&limit=<n> — newest first. With `after`,
	 * only rows newer than that id (the polling path). Always returns the
	 * unread count; staff also get live counters for the sidebar badges.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function list_notifications( $request ) {
		global $wpdb;
		$user  = NROS_Auth::get_user_from_request( $request );
		$uid   = (int) $user->ID;
		$table = self::table();
		$after = max( 0, (int) $request->get_param( 'after' ) );
		$limit = min( 100, max( 1, (int) ( $request->get_param( 'limit' ) ? $request->get_param( 'limit' ) : 30 ) ) );

		if ( $after > 0 ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d AND id > %d ORDER BY id DESC LIMIT %d", $uid, $after, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d ORDER BY id DESC LIMIT %d", $uid, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$unread = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND is_read = 0", $uid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$latest = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(id) FROM {$table} WHERE user_id = %d", $uid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$out = array(
			'items'     => array_map( array( __CLASS__, 'shape' ), (array) $rows ),
			'unread'    => $unread,
			'latest_id' => $latest,
		);

		if ( user_can( $user, 'edit_others_posts' ) ) {
			$out['counts'] = self::staff_counts( $user );
		}

		return new WP_REST_Response( $out, 200 );
	}

	/**
	 * Live counters for the publisher app's sidebar badges.
	 *
	 * @param WP_User $user Staff user.
	 * @return array
	 */
	protected static function staff_counts( $user ) {
		global $wpdb;
		$counts = array(
			'queue'        => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'pending'" ),
			'videos'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'meeha_video' AND post_status = 'pending'" ),
			'applications' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}nros_applications WHERE status = 'pending'" ),
		);
		if ( NROS_Core::financial_visible_to( $user ) ) {
			$counts['withdrawals'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}nros_withdraw_requests WHERE status = 'pending'" );
		}
		return $counts;
	}

	/**
	 * POST /notifications/read — body { ids: number[] } or { all: true }.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function mark_read( $request ) {
		global $wpdb;
		$user  = NROS_Auth::get_user_from_request( $request );
		$uid   = (int) $user->ID;
		$table = self::table();

		if ( $request->get_param( 'all' ) ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET is_read = 1 WHERE user_id = %d AND is_read = 0", $uid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			$ids = array_slice( array_filter( array_map( 'intval', (array) $request->get_param( 'ids' ) ) ), 0, 200 );
			if ( $ids ) {
				$in = implode( ',', $ids );
				$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET is_read = 1 WHERE user_id = %d AND id IN ({$in})", $uid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}

		$unread = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND is_read = 0", $uid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return new WP_REST_Response( array( 'ok' => true, 'unread' => $unread ), 200 );
	}
}
