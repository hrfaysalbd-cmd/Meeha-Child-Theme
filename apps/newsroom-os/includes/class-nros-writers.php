<?php
/**
 * r47 — মিহা কলম: the second way in. An applicant chooses either
 *   ১. "সাংবাদিক হতে চাই"  — the existing correspondent track (area rooms, daily reminders), or
 *   ২. "মিহা কলম — লেখা পাঠাতে চাই" — feature, story, poem, essay, opinion, travel, children's
 *      writing; for students, teachers/professors, professionals and other writers.
 *
 * A writer is still a `correspondent` WordPress user (so login, the writing screen, the editorial
 * queue and payments all work unchanged) carrying `nros_track = writer` plus their group. That one
 * flag keeps them out of the reporters' rooms and reminders and puts them in their own:
 *   মিহা কলম (all writers) · কলম: শিক্ষার্থী · কলম: শিক্ষক ও অধ্যাপক · কলম: পেশাজীবী.
 * The উপাধি shown under their name is the publisher's call: a sensible default is set at approval
 * and the publisher changes it any time with the existing পদবি editor.
 *
 * @package NewsroomOS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Writer track helpers, rooms and the weekly writing call.
 */
class NROS_Writers {

	const CRON = 'nros_writers_weekly';

	/**
	 * Writer groups: id => [label, room name, default উপাধি].
	 *
	 * @return array
	 */
	public static function groups() {
		return array(
			'student'      => array( 'শিক্ষার্থী', 'কলম: শিক্ষার্থী লেখক', 'শিক্ষার্থী লেখক' ),
			'teacher'      => array( 'শিক্ষক / অধ্যাপক', 'কলম: শিক্ষক ও অধ্যাপক', 'অতিথি লেখক' ),
			'professional' => array( 'পেশাজীবী', 'কলম: পেশাজীবী লেখক', 'অতিথি লেখক' ),
			'general'      => array( 'সাধারণ লেখক', '', 'লেখক' ),
		);
	}

	/**
	 * What a writer can send (ticks on the form).
	 *
	 * @return string[] id => label
	 */
	public static function genres() {
		return array(
			'feature' => 'ফিচার',
			'story'   => 'গল্প',
			'poem'    => 'কবিতা',
			'essay'   => 'প্রবন্ধ',
			'opinion' => 'মতামত / কলাম',
			'travel'  => 'ভ্রমণ',
			'kids'    => 'শিশু-কিশোর',
			'campus'  => 'ক্যাম্পাস',
		);
	}

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( self::CRON, array( __CLASS__, 'weekly_call' ) );
		if ( ! wp_next_scheduled( self::CRON ) ) {
			// Fridays 10:00 Asia/Dhaka (04:00 UTC); the job itself checks the weekday.
			wp_schedule_event( strtotime( 'next friday 04:00 UTC' ), 'daily', self::CRON );
		}
	}

	/**
	 * Routes.
	 */
	public static function register_routes() {
		register_rest_route(
			NROS_API_NS,
			'/writers/options',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'options' ),
				'permission_callback' => '__return_true', // public: the apply form needs it before login
			)
		);
		register_rest_route(
			NROS_API_NS,
			'/admin/correspondents/(?P<id>\d+)/track',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'set_track_route' ),
				'permission_callback' => array( 'NROS_Core', 'require_publisher' ),
			)
		);
	}

	/**
	 * GET /writers/options — groups and genres for the apply form.
	 *
	 * @return array
	 */
	public static function options() {
		$g = array();
		foreach ( self::groups() as $id => $row ) {
			$g[] = array( 'id' => $id, 'label' => $row[0] );
		}
		$k = array();
		foreach ( self::genres() as $id => $label ) {
			$k[] = array( 'id' => $id, 'label' => $label );
		}
		return array( 'name' => 'মিহা কলম', 'groups' => $g, 'genres' => $k );
	}

	/**
	 * Is this user on the writer track?
	 *
	 * @param int|WP_User $u User.
	 * @return bool
	 */
	public static function is_writer( $u ) {
		$id = $u instanceof WP_User ? $u->ID : (int) $u;
		return $id && 'writer' === get_user_meta( $id, 'nros_track', true );
	}

	/**
	 * A writer's group id ('' for reporters).
	 *
	 * @param int $uid User.
	 * @return string
	 */
	public static function group_of( $uid ) {
		$g = (string) get_user_meta( (int) $uid, 'nros_writer_group', true );
		return isset( self::groups()[ $g ] ) ? $g : ( self::is_writer( $uid ) ? 'general' : '' );
	}

	/**
	 * Ids of every writer (optionally one group).
	 *
	 * @param string $group Group or '' for all.
	 * @return int[]
	 */
	public static function ids( $group = '' ) {
		$mq = array( array( 'key' => 'nros_track', 'value' => 'writer' ) );
		if ( '' !== $group ) {
			$mq[] = array( 'key' => 'nros_writer_group', 'value' => $group );
		}
		return array_map(
			'intval',
			get_users(
				array(
					'role'       => 'correspondent',
					'fields'     => 'ID',
					'number'     => 5000,
					'meta_query' => $mq, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_query
				)
			)
		);
	}

	/**
	 * Copy an approved application's track fields onto the user and set the উপাধি.
	 *
	 * @param int    $uid   User.
	 * @param array  $app   Application row.
	 * @param string $title Publisher's উপাধি ('' = default for the group; never overwrites one already set).
	 */
	public static function apply_from_application( $uid, $app, $title = '' ) {
		$track = isset( $app['track'] ) && 'writer' === $app['track'] ? 'writer' : 'journalist';
		update_user_meta( $uid, 'nros_track', $track );
		if ( 'writer' === $track ) {
			$group = isset( $app['writer_group'] ) && isset( self::groups()[ $app['writer_group'] ] ) ? $app['writer_group'] : 'general';
			update_user_meta( $uid, 'nros_writer_group', $group );
			foreach ( array( 'institution', 'designation', 'pen_name', 'genres', 'sample' ) as $k ) {
				if ( ! empty( $app[ $k ] ) ) {
					update_user_meta( $uid, 'nros_writer_' . $k, (string) $app[ $k ] );
				}
			}
			if ( '' === $title ) {
				$title = self::groups()[ $group ][2];
			}
		}
		$title = trim( (string) $title );
		if ( '' !== $title && '' === (string) get_user_meta( $uid, 'meeha_author_title', true ) ) {
			update_user_meta( $uid, 'meeha_author_title', mb_substr( $title, 0, 80 ) );
		}
	}

	/**
	 * POST /admin/correspondents/{id}/track {track, group} — move someone between the tracks.
	 * Leaving a track marks them removed from that track's auto rooms; the hourly chat sync
	 * (and an immediate one here) puts them in the other track's rooms.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function set_track_route( $request ) {
		$uid = absint( $request['id'] );
		$u   = get_userdata( $uid );
		if ( ! $u || ! in_array( 'correspondent', (array) $u->roles, true ) ) {
			return new WP_Error( 'nros_not_found', 'পাওয়া যায়নি।', array( 'status' => 404 ) );
		}
		$track = 'writer' === $request->get_param( 'track' ) ? 'writer' : 'journalist';
		$group = sanitize_key( (string) $request->get_param( 'group' ) );
		update_user_meta( $uid, 'nros_track', $track );
		if ( 'writer' === $track ) {
			update_user_meta( $uid, 'nros_writer_group', isset( self::groups()[ $group ] ) ? $group : 'general' );
		}
		self::leave_other_rooms( $uid );
		if ( class_exists( 'NROS_Chat' ) ) {
			NROS_Chat::sync_user( $uid, true );
		}
		return array( 'ok' => true, 'track' => $track, 'group' => self::group_of( $uid ) );
	}

	/**
	 * Marks a user removed from auto rooms of the track (or writer group) they no longer belong to.
	 *
	 * @param int $uid User.
	 */
	public static function leave_other_rooms( $uid ) {
		global $wpdb;
		if ( ! class_exists( 'NROS_Chat' ) ) {
			return;
		}
		$writer = self::is_writer( $uid );
		$group  = self::group_of( $uid );
		$rooms  = $wpdb->get_results( 'SELECT id, kind, slug FROM ' . NROS_Chat::t( 'rooms' ) . " WHERE kind IN ('family','area','writers')", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( (array) $rooms as $r ) {
			$keep = 'writers' === $r['kind']
				? ( $writer && ( 'writers-all' === $r['slug'] || 'writers-' . $group === $r['slug'] ) )
				: ! $writer;
			if ( ! $keep ) {
				$wpdb->update( NROS_Chat::t( 'members' ), array( 'removed' => 1 ), array( 'room_id' => (int) $r['id'], 'user_id' => (int) $uid ) );
			} else {
				$wpdb->update( NROS_Chat::t( 'members' ), array( 'removed' => 0 ), array( 'room_id' => (int) $r['id'], 'user_id' => (int) $uid ) );
			}
		}
	}

	/**
	 * Writer rooms (slug => [name, about, icon]).
	 *
	 * @return array
	 */
	public static function rooms() {
		$out = array( 'writers-all' => array( 'মিহা কলম', 'ফিচার, গল্প, কবিতা, প্রবন্ধ ও মতামতের লেখকদের আড্ডা — ডেস্কও এখানে আছে।', '✒️' ) );
		foreach ( self::groups() as $id => $g ) {
			if ( '' !== $g[1] ) {
				$out[ 'writers-' . $id ] = array( $g[1], $g[0] . ' লেখকদের নিজেদের রুম।', 'student' === $id ? '🎓' : ( 'teacher' === $id ? '📚' : '💼' ) );
			}
		}
		return $out;
	}

	/**
	 * Members a writer room should have: staff + the matching writers.
	 *
	 * @param array $room  Room row.
	 * @param int[] $staff Staff ids.
	 * @return int[]
	 */
	public static function room_members( $room, $staff ) {
		$slug = (string) $room['slug'];
		$ids  = 'writers-all' === $slug ? self::ids() : self::ids( substr( $slug, strlen( 'writers-' ) ) );
		return array_merge( $staff, $ids );
	}

	/**
	 * Does a user belong in a writer room?
	 *
	 * @param array   $room  Room row.
	 * @param WP_User $u     User.
	 * @param bool    $staff Is staff.
	 * @return bool
	 */
	public static function belongs( $room, $u, $staff ) {
		if ( $staff ) {
			return true;
		}
		if ( ! self::is_writer( $u ) ) {
			return false;
		}
		return 'writers-all' === $room['slug'] || 'writers-' . self::group_of( $u->ID ) === $room['slug'];
	}

	/**
	 * Friday's writing call in মিহা কলম (once a week, Asia/Dhaka).
	 */
	public static function weekly_call() {
		if ( ! class_exists( 'NROS_Chat' ) ) {
			return;
		}
		$now = new DateTimeImmutable( 'now', new DateTimeZone( 'Asia/Dhaka' ) );
		if ( 'Friday' !== $now->format( 'l' ) || get_option( 'nros_writers_call' ) === $now->format( 'Y-m-d' ) ) {
			return;
		}
		update_option( 'nros_writers_call', $now->format( 'Y-m-d' ), false );
		global $wpdb;
		$room = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NROS_Chat::t( 'rooms' ) . ' WHERE slug = %s', 'writers-all' ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $room ) {
			return;
		}
		$themes = array(
			'শীতের সকাল — শহর আর গ্রামের দুই রকম ছবি',
			'আমার শহরের একজন অচেনা নায়ক',
			'ক্যাম্পাসের যে গল্প কেউ লেখেনি',
			'নদী, মানুষ আর জীবিকা',
			'প্রবাসীর চিঠি — যে কথা বাড়িতে বলা হয় না',
			'পুরোনো বইয়ের দোকান',
			'প্রযুক্তি আর পরিবার: বদলে যাওয়া অভ্যাস',
			'এক বেলার ভ্রমণ — কাছের অচেনা জায়গা',
		);
		$pick = $themes[ (int) $now->format( 'W' ) % count( $themes ) ];
		$body = "✒️ মিহা কলম — এ সপ্তাহের লেখার আহ্বান\n\nবিষয়: “{$pick}”\nফিচার, গল্প, কবিতা বা প্রবন্ধ — যেকোনো রূপে লিখতে পারেন।\n\n• লেখা পাঠান অ্যাপের “লিখুন” থেকে, বিভাগ হিসেবে “মিহা কলম”/সাহিত্য বেছে\n• সঙ্গে নিজের একটা ছবি দিলে লেখার সঙ্গে ছাপা হবে\n• বাছাই করা লেখা মিহা নিউজে প্রকাশ হবে, লেখকের নাম ও উপাধিসহ";
		NROS_Chat::bot_say( $room, $body, array(), 'notice' );
	}
}
