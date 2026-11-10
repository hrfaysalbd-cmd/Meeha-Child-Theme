<?php
/**
 * NROS_Chat — চ্যাট বক্স (r36, newsroom-os 1.19.0).
 *
 * Rooms where correspondents and the desk talk. Hafiz's rules (2026-11-10):
 *  - correspondents talk only through the desk and rooms (no correspondent
 *    to correspondent private chat);
 *  - a general room "মিহা পরিবার" with everyone in it; the Publisher is its
 *    admin and can remove anyone;
 *  - messages carry a label (জরুরি, মিটিং, ঘোষণা …);
 *  - chat is kept forever; a Publisher can delete a message for good.
 *
 * Kinds of room:
 *  family — মিহা পরিবার: every correspondent + every staff member (auto-joined)
 *  staff  — এডিটোরিয়াল ডেস্ক: Editors and Publishers only (auto-joined)
 *  area   — one per division: that division's correspondents + staff
 *  desk   — one per correspondent: that correspondent ⇄ the whole desk
 *  room   — rooms a staff member made, with the members they chose
 *
 * The apps poll (GET /chat/summary every few seconds, GET messages?after=
 * while a room is open), like the rest of the apps.
 *
 * @package NewsroomOS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NROS_Chat {

	/** Message labels: key => [Bengali name, colour]. */
	const LABELS = array(
		'urgent'   => array( 'জরুরি', '#dc2626' ),
		'meeting'  => array( 'মিটিং', '#7c3aed' ),
		'notice'   => array( 'ঘোষণা', '#2563eb' ),
		'task'     => array( 'কাজ', '#d97706' ),
		'info'     => array( 'তথ্য চাই', '#0d9488' ),
		'general'  => array( 'সাধারণ', '#64748b' ),
	);

	/** Reactions people can add. */
	const REACTIONS = array( '👍', '❤️', '✅', '😂', '😮', '🙏' );

	/** Minutes in which the writer can still edit a message. */
	const EDIT_MINUTES = 15;

	/** Largest attachment (bytes). */
	const MAX_FILE = 25 * MB_IN_BYTES;

	const SCHEMA_OPTION = 'nros_chat_defaults_v';
	const SCHEMA_REV    = '1';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'init', array( __CLASS__, 'maybe_defaults' ), 30 );
		// new people join the default rooms by themselves
		add_action( 'set_user_role', array( __CLASS__, 'on_role_change' ), 20, 1 );
		add_action( 'add_user_role', array( __CLASS__, 'on_role_change' ), 20, 1 );
		add_action( 'user_register', array( __CLASS__, 'on_role_change' ), 20, 1 );
		add_action( 'nros_chat_sync', array( __CLASS__, 'sync_all' ) );
		if ( ! wp_next_scheduled( 'nros_chat_sync' ) ) {
			wp_schedule_event( time() + 600, 'daily', 'nros_chat_sync' );
		}
	}

	/**
	 * Table name.
	 *
	 * @param string $t rooms|members|messages|files.
	 * @return string
	 */
	public static function t( $t ) {
		global $wpdb;
		return $wpdb->prefix . 'nros_chat_' . $t;
	}

	/**
	 * CREATE TABLE statements (dbDelta).
	 *
	 * @param string $c Charset/collation.
	 * @return string[]
	 */
	public static function schema( $c ) {
		$r = self::t( 'rooms' );
		$m = self::t( 'members' );
		$g = self::t( 'messages' );
		$f = self::t( 'files' );
		return array(
			"CREATE TABLE {$r} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				kind VARCHAR(12) NOT NULL DEFAULT 'room',
				slug VARCHAR(80) DEFAULT NULL,
				name VARCHAR(120) NOT NULL,
				about VARCHAR(255) NOT NULL DEFAULT '',
				icon VARCHAR(16) NOT NULL DEFAULT '',
				owner_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
				posting VARCHAR(8) NOT NULL DEFAULT 'all',
				archived TINYINT(1) NOT NULL DEFAULT 0,
				last_msg_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				last_at DATETIME DEFAULT NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY slug (slug),
				KEY kind (kind),
				KEY owner_id (owner_id)
			) {$c};",
			"CREATE TABLE {$m} (
				room_id BIGINT UNSIGNED NOT NULL,
				user_id BIGINT UNSIGNED NOT NULL,
				role VARCHAR(8) NOT NULL DEFAULT 'member',
				removed TINYINT(1) NOT NULL DEFAULT 0,
				last_read_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				muted_until DATETIME DEFAULT NULL,
				joined_at DATETIME NOT NULL,
				PRIMARY KEY  (room_id,user_id),
				KEY user_id (user_id)
			) {$c};",
			"CREATE TABLE {$g} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				room_id BIGINT UNSIGNED NOT NULL,
				user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				kind VARCHAR(8) NOT NULL DEFAULT 'text',
				body TEXT,
				label VARCHAR(12) NOT NULL DEFAULT '',
				meeting_at DATETIME DEFAULT NULL,
				reply_to BIGINT UNSIGNED NOT NULL DEFAULT 0,
				files VARCHAR(255) NOT NULL DEFAULT '',
				mentions VARCHAR(255) NOT NULL DEFAULT '',
				reactions TEXT,
				pinned TINYINT(1) NOT NULL DEFAULT 0,
				edited_at DATETIME DEFAULT NULL,
				deleted_at DATETIME DEFAULT NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY room_id_id (room_id,id),
				KEY room_label (room_id,label),
				KEY room_pinned (room_id,pinned)
			) {$c};",
			"CREATE TABLE {$f} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				room_id BIGINT UNSIGNED NOT NULL,
				user_id BIGINT UNSIGNED NOT NULL,
				disk_name VARCHAR(80) NOT NULL,
				name VARCHAR(190) NOT NULL,
				mime VARCHAR(100) NOT NULL DEFAULT '',
				kind VARCHAR(10) NOT NULL DEFAULT 'file',
				size BIGINT UNSIGNED NOT NULL DEFAULT 0,
				duration INT UNSIGNED NOT NULL DEFAULT 0,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY room_id (room_id)
			) {$c};",
		);
	}

	// ---------------------------------------------------------------------
	// People
	// ---------------------------------------------------------------------

	/**
	 * @param WP_User $u User.
	 * @return bool
	 */
	public static function is_staff( $u ) {
		return $u && user_can( $u, 'edit_others_posts' );
	}

	/**
	 * @param WP_User $u User.
	 * @return bool
	 */
	public static function is_publisher( $u ) {
		return $u && ( user_can( $u, 'nros_publisher' ) || user_can( $u, 'manage_options' ) );
	}

	/**
	 * @param WP_User $u User.
	 * @return bool
	 */
	public static function is_correspondent( $u ) {
		return $u && in_array( 'correspondent', (array) $u->roles, true );
	}

	/**
	 * Ids of every app user (correspondents + staff).
	 *
	 * @param bool $staff_only Only Editors/Publishers/Admins.
	 * @return int[]
	 */
	protected static function people( $staff_only = false ) {
		$roles = $staff_only ? array( 'administrator', 'meeha_publisher', 'editor' ) : array( 'administrator', 'meeha_publisher', 'editor', 'correspondent' );
		return array_map( 'intval', get_users( array( 'role__in' => $roles, 'fields' => 'ID', 'number' => 5000 ) ) );
	}

	/**
	 * Short public card of a user.
	 *
	 * @param int $uid User id.
	 * @return array
	 */
	public static function person( $uid ) {
		static $cache = array();
		$uid = (int) $uid;
		if ( isset( $cache[ $uid ] ) ) {
			return $cache[ $uid ];
		}
		$u = get_userdata( $uid );
		if ( ! $u ) {
			return $cache[ $uid ] = array( 'id' => $uid, 'name' => 'সাবেক সদস্য', 'avatar' => '', 'role' => '', 'area' => '', 'online' => false );
		}
		$role = self::is_publisher( $u ) ? 'পাবলিশার' : ( self::is_staff( $u ) ? 'এডিটর' : ( self::is_correspondent( $u ) ? 'সংবাদদাতা' : '' ) );
		$area = trim( implode( ', ', array_filter( array( (string) get_user_meta( $uid, 'meeha_upazila', true ), (string) get_user_meta( $uid, 'meeha_district', true ) ) ) ) );
		$seen = (int) get_user_meta( $uid, 'nros_chat_seen', true );
		return $cache[ $uid ] = array(
			'id'     => $uid,
			'name'   => $u->display_name,
			'avatar' => get_avatar_url( $uid, array( 'size' => 96 ) ),
			'role'   => $role,
			'area'   => $area,
			'online' => $seen && ( time() - $seen ) < 150,
		);
	}

	// ---------------------------------------------------------------------
	// Default rooms
	// ---------------------------------------------------------------------

	/**
	 * Creates the default rooms once (and again after a schema bump).
	 */
	public static function maybe_defaults() {
		if ( get_option( self::SCHEMA_OPTION ) === self::SCHEMA_REV ) {
			return;
		}
		global $wpdb;
		// tables may not exist yet on a half-finished upgrade
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', self::t( 'rooms' ) ) ) !== self::t( 'rooms' ) ) {
			return;
		}
		self::ensure_room( 'family', 'family', 'মিহা পরিবার', 'পাবলিশার থেকে শুরু করে মিহার সবাই এক জায়গায়।', '🏠' );
		self::ensure_room( 'staff', 'staff', 'এডিটোরিয়াল ডেস্ক', 'এডিটর ও পাবলিশারদের নিজেদের আলাপ।', '🗞️' );
		foreach ( self::divisions() as $div ) {
			self::ensure_room( 'area', 'area-' . md5( $div ), 'বিভাগ: ' . $div, $div . ' বিভাগের সংবাদদাতা ও ডেস্ক।', '📍' );
		}
		update_option( self::SCHEMA_OPTION, self::SCHEMA_REV, false );
		self::sync_all();
	}

	/**
	 * Division names (theme helper when present; the 8 divisions otherwise).
	 *
	 * @return string[]
	 */
	protected static function divisions() {
		if ( function_exists( 'meeha_get_bd_divisions' ) ) {
			$d = meeha_get_bd_divisions();
			if ( is_array( $d ) && $d ) {
				return array_map( 'strval', array_keys( $d ) );
			}
		}
		return array( 'ঢাকা', 'চট্টগ্রাম', 'রাজশাহী', 'খুলনা', 'বরিশাল', 'সিলেট', 'রংপুর', 'ময়মনসিংহ' );
	}

	/**
	 * Room by slug, made when missing.
	 *
	 * @return int Room id.
	 */
	protected static function ensure_room( $kind, $slug, $name, $about, $icon, $owner = 0 ) {
		global $wpdb;
		$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'rooms' ) . ' WHERE slug = %s', $slug ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( $id ) {
			return $id;
		}
		$wpdb->insert(
			self::t( 'rooms' ),
			array(
				'kind'       => $kind,
				'slug'       => $slug,
				'name'       => $name,
				'about'      => $about,
				'icon'       => $icon,
				'owner_id'   => (int) $owner,
				'created_at' => current_time( 'mysql' ),
				'last_at'    => current_time( 'mysql' ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Who belongs in an auto room.
	 *
	 * @param array $room Room row.
	 * @return int[]|null null = not an auto room.
	 */
	protected static function auto_members( $room ) {
		if ( 'family' === $room['kind'] ) {
			return self::people();
		}
		if ( 'staff' === $room['kind'] ) {
			return self::people( true );
		}
		if ( 'area' === $room['kind'] ) {
			$div  = preg_replace( '/^বিভাগ:\s*/u', '', $room['name'] );
			$ids  = self::people( true );
			$corr = get_users(
				array(
					'role'       => 'correspondent',
					'fields'     => 'ID',
					'number'     => 5000,
					'meta_key'   => 'meeha_division', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key
					'meta_value' => $div, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_value
				)
			);
			return array_merge( $ids, array_map( 'intval', $corr ) );
		}
		return null;
	}

	/**
	 * Adds everyone who belongs to the auto rooms (never re-adds someone an
	 * admin removed). Publishers are admins of the auto rooms.
	 */
	public static function sync_all() {
		global $wpdb;
		$rooms = $wpdb->get_results( 'SELECT * FROM ' . self::t( 'rooms' ) . " WHERE kind IN ('family','staff','area')", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( (array) $rooms as $room ) {
			self::sync_room( $room );
		}
	}

	/**
	 * @param array $room Room row.
	 */
	protected static function sync_room( $room ) {
		global $wpdb;
		$want = self::auto_members( $room );
		if ( null === $want ) {
			return;
		}
		$have = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT user_id FROM ' . self::t( 'members' ) . ' WHERE room_id = %d', $room['id'] ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$now  = current_time( 'mysql' );
		foreach ( array_diff( array_unique( $want ), $have ) as $uid ) {
			$u = get_userdata( $uid );
			$wpdb->insert(
				self::t( 'members' ),
				array(
					'room_id'   => (int) $room['id'],
					'user_id'   => (int) $uid,
					'role'      => self::is_publisher( $u ) ? 'admin' : 'member',
					'joined_at' => $now,
					// a newcomer starts "caught up" instead of with the whole history unread
					'last_read_id' => (int) $room['last_msg_id'],
				)
			);
		}
	}

	/**
	 * A user got a new role: join the right auto rooms.
	 *
	 * @param int $uid User id.
	 */
	public static function on_role_change( $uid ) {
		global $wpdb;
		$u = get_userdata( (int) $uid );
		if ( ! $u || ( ! self::is_correspondent( $u ) && ! self::is_staff( $u ) ) ) {
			return;
		}
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', self::t( 'rooms' ) ) ) !== self::t( 'rooms' ) ) {
			return;
		}
		self::sync_all();
	}

	// ---------------------------------------------------------------------
	// Access
	// ---------------------------------------------------------------------

	/**
	 * @param int $id Room id.
	 * @return array|null
	 */
	protected static function room( $id ) {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'rooms' ) . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $r ? $r : null;
	}

	/**
	 * @return array|null Member row.
	 */
	protected static function member( $room_id, $uid ) {
		global $wpdb;
		$m = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'members' ) . ' WHERE room_id = %d AND user_id = %d', $room_id, $uid ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $m ? $m : null;
	}

	/**
	 * Can this user read the room? Desk rooms: the correspondent + all staff.
	 *
	 * @param array   $room Room.
	 * @param WP_User $u    User.
	 * @return bool
	 */
	protected static function can_read( $room, $u ) {
		if ( 'desk' === $room['kind'] ) {
			return (int) $room['owner_id'] === (int) $u->ID || self::is_staff( $u );
		}
		if ( self::is_publisher( $u ) ) {
			return true;
		}
		$m = self::member( $room['id'], $u->ID );
		return $m && ! (int) $m['removed'];
	}

	/**
	 * Is this user an admin of the room? Publishers always are; in a desk
	 * room every staff member is.
	 */
	protected static function is_admin( $room, $u ) {
		if ( self::is_publisher( $u ) ) {
			return true;
		}
		if ( 'desk' === $room['kind'] ) {
			return self::is_staff( $u );
		}
		$m = self::member( $room['id'], $u->ID );
		return $m && ! (int) $m['removed'] && 'admin' === $m['role'];
	}

	/**
	 * Makes sure a reader has a member row (for read marks), for desk rooms
	 * and Publishers looking into a room they are not in.
	 */
	protected static function touch_member( $room, $u ) {
		global $wpdb;
		if ( self::member( $room['id'], $u->ID ) ) {
			return;
		}
		$wpdb->insert(
			self::t( 'members' ),
			array(
				'room_id'   => (int) $room['id'],
				'user_id'   => (int) $u->ID,
				'role'      => self::is_admin( $room, $u ) ? 'admin' : 'member',
				'joined_at' => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Logged-in user, and marks them online (at most once a minute).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_User
	 */
	protected static function me( $request ) {
		$u    = NROS_Auth::get_user_from_request( $request );
		$seen = (int) get_user_meta( $u->ID, 'nros_chat_seen', true );
		if ( time() - $seen > 60 ) {
			update_user_meta( $u->ID, 'nros_chat_seen', time() );
		}
		return $u;
	}

	/**
	 * Desk room of a correspondent (made when missing).
	 *
	 * @param int $cid Correspondent id.
	 * @return int
	 */
	protected static function desk_room( $cid ) {
		$u = get_userdata( $cid );
		return self::ensure_room( 'desk', 'desk-' . (int) $cid, $u ? $u->display_name : 'সংবাদদাতা', 'ডেস্কের সঙ্গে সরাসরি আলাপ', '💬', $cid );
	}

	// ---------------------------------------------------------------------
	// Routes
	// ---------------------------------------------------------------------

	/**
	 * Registers REST routes.
	 */
	public static function register_routes() {
		$ns   = NROS_API_NS;
		$app  = array( 'NROS_Core', 'require_app_user' );
		$stf  = array( 'NROS_Core', 'require_staff' );
		$r    = function ( $path, $method, $cb, $perm ) use ( $ns ) {
			register_rest_route( $ns, $path, array( 'methods' => $method, 'callback' => array( __CLASS__, $cb ), 'permission_callback' => $perm ) );
		};
		$r( '/chat/summary', 'GET', 'summary', $app );
		$r( '/chat/rooms', 'GET', 'list_rooms', $app );
		$r( '/chat/rooms', 'POST', 'create_room', $stf );
		$r( '/chat/rooms/(?P<id>\d+)', 'PATCH', 'update_room', $app );
		$r( '/chat/rooms/(?P<id>\d+)/messages', 'GET', 'list_messages', $app );
		$r( '/chat/rooms/(?P<id>\d+)/messages', 'POST', 'post_message', $app );
		$r( '/chat/rooms/(?P<id>\d+)/read', 'POST', 'mark_read', $app );
		$r( '/chat/rooms/(?P<id>\d+)/typing', 'POST', 'typing', $app );
		$r( '/chat/rooms/(?P<id>\d+)/mute', 'POST', 'mute', $app );
		$r( '/chat/rooms/(?P<id>\d+)/members', 'GET', 'list_members', $app );
		$r( '/chat/rooms/(?P<id>\d+)/members', 'POST', 'add_members', $app );
		$r( '/chat/rooms/(?P<id>\d+)/members/(?P<uid>\d+)', 'PATCH', 'update_member', $app );
		$r( '/chat/rooms/(?P<id>\d+)/members/(?P<uid>\d+)', 'DELETE', 'remove_member', $app );
		$r( '/chat/messages/(?P<id>\d+)', 'PATCH', 'edit_message', $app );
		$r( '/chat/messages/(?P<id>\d+)', 'DELETE', 'delete_message', $app );
		$r( '/chat/messages/(?P<id>\d+)/react', 'POST', 'react', $app );
		$r( '/chat/messages/(?P<id>\d+)/pin', 'POST', 'pin', $app );
		$r( '/chat/desk', 'POST', 'open_desk', $app );
		$r( '/chat/people', 'GET', 'people_search', $stf );
		$r( '/chat/files', 'POST', 'upload', $app );
		register_rest_route(
			$ns,
			'/chat/files/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'stream' ),
				'permission_callback' => '__return_true', // checked by the signed token
			)
		);
	}

	/**
	 * Room ids this user can see, with their member row.
	 *
	 * @param WP_User $u User.
	 * @return array[] Room rows (+ my_* fields).
	 */
	protected static function rooms_for( $u ) {
		global $wpdb;
		$rt = self::t( 'rooms' );
		$mt = self::t( 'members' );
		$uid = (int) $u->ID;
		if ( self::is_staff( $u ) ) {
			// everything I'm in + every desk room that has messages (+ for Publishers, every room)
			$extra = self::is_publisher( $u ) ? "r.kind <> 'desk' OR " : '';
			$sql   = "SELECT r.*, m.role AS my_role, m.removed AS my_removed, m.last_read_id AS my_read, m.muted_until AS my_muted
				FROM {$rt} r LEFT JOIN {$mt} m ON m.room_id = r.id AND m.user_id = %d
				WHERE ( {$extra}(m.user_id IS NOT NULL AND m.removed = 0) OR (r.kind = 'desk' AND r.last_msg_id > 0) )";
		} else {
			self::desk_room( $uid );
			$sql = "SELECT r.*, m.role AS my_role, m.removed AS my_removed, m.last_read_id AS my_read, m.muted_until AS my_muted
				FROM {$rt} r LEFT JOIN {$mt} m ON m.room_id = r.id AND m.user_id = %d
				WHERE ( (m.user_id IS NOT NULL AND m.removed = 0 AND r.kind <> 'desk') OR (r.kind = 'desk' AND r.owner_id = %d) )";
		}
		$sql .= ' ORDER BY r.archived ASC, r.last_at DESC LIMIT 400';
		$args = self::is_staff( $u ) ? array( $uid ) : array( $uid, $uid );
		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Unread counters of one room for one reader.
	 *
	 * @return array{unread:int,mentions:int,urgent:int}
	 */
	protected static function unread_in( $room_id, $read_id, $uid ) {
		global $wpdb;
		$g   = self::t( 'messages' );
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS n, SUM(FIND_IN_SET(%d, mentions) > 0) AS men, SUM(label = 'urgent') AS urg FROM {$g} WHERE room_id = %d AND id > %d AND user_id <> %d AND deleted_at IS NULL AND kind = 'text'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$uid,
				$room_id,
				(int) $read_id,
				$uid
			),
			ARRAY_A
		);
		return array(
			'unread'   => (int) $row['n'],
			'mentions' => (int) $row['men'],
			'urgent'   => (int) $row['urg'],
		);
	}

	/**
	 * @return bool
	 */
	protected static function muted( $row ) {
		return ! empty( $row['my_muted'] ) && strtotime( $row['my_muted'] ) > current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
	}

	/**
	 * GET /chat/summary — badge numbers for the apps (cheap; polled).
	 */
	public static function summary( $request ) {
		$u     = self::me( $request );
		$total = 0;
		$men   = 0;
		$urg   = 0;
		$last  = 0;
		$pick  = null;
		foreach ( self::rooms_for( $u ) as $row ) {
			if ( (int) $row['archived'] ) {
				continue;
			}
			$last = max( $last, (int) $row['last_msg_id'] );
			if ( (int) $row['last_msg_id'] <= (int) $row['my_read'] ) {
				continue;
			}
			$c = self::unread_in( $row['id'], (int) $row['my_read'], $u->ID );
			$men += $c['mentions'];
			$urg += $c['urgent'];
			$loud = ! self::muted( $row ) || $c['mentions'] || $c['urgent'];
			if ( ! self::muted( $row ) ) {
				$total += $c['unread'];
			}
			if ( $c['unread'] && $loud && ( ! $pick || (int) $row['last_msg_id'] > (int) $pick['last_msg_id'] ) ) {
				$pick = $row;
			}
		}
		// the newest unread message I should hear about (for the pop-up / system notice)
		$latest = null;
		if ( $pick ) {
			global $wpdb;
			$m = $wpdb->get_row( $wpdb->prepare( 'SELECT id, user_id, body, label, files, mentions FROM ' . self::t( 'messages' ) . " WHERE room_id = %d AND id > %d AND user_id <> %d AND deleted_at IS NULL AND kind = 'text' ORDER BY id DESC LIMIT 1", $pick['id'], (int) $pick['my_read'], $u->ID ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( $m ) {
				$room   = self::shape_room( $pick, $u );
				$latest = array(
					'id'      => (int) $m['id'],
					'room_id' => (int) $pick['id'],
					'room'    => $room['name'],
					'icon'    => $room['icon'],
					'author'  => self::person( $m['user_id'] )['name'],
					'text'    => '' !== trim( (string) $m['body'] ) ? wp_html_excerpt( (string) $m['body'], 140, '…' ) : '📎 ফাইল',
					'label'   => (string) $m['label'],
					'mention' => in_array( (int) $u->ID, array_map( 'intval', explode( ',', (string) $m['mentions'] ) ), true ),
				);
			}
		}
		return rest_ensure_response( array( 'unread' => $total, 'mentions' => $men, 'urgent' => $urg, 'latest_id' => $last, 'latest' => $latest ) );
	}

	/**
	 * Shapes a room row for the list.
	 */
	protected static function shape_room( $row, $u ) {
		global $wpdb;
		$last = null;
		if ( (int) $row['last_msg_id'] ) {
			$m = $wpdb->get_row( $wpdb->prepare( 'SELECT id, user_id, body, label, files, deleted_at, kind, created_at FROM ' . self::t( 'messages' ) . ' WHERE id = %d', $row['last_msg_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( $m ) {
				$text = $m['deleted_at'] ? 'মেসেজটি মুছে ফেলা হয়েছে' : wp_html_excerpt( (string) $m['body'], 90, '…' );
				if ( '' === trim( $text ) && $m['files'] ) {
					$text = '📎 ফাইল';
				}
				$last = array(
					'id'     => (int) $m['id'],
					'text'   => $text,
					'label'  => (string) $m['label'],
					'author' => (int) $m['user_id'] === (int) $u->ID ? 'আপনি' : ( 'system' === $m['kind'] ? '' : self::person( $m['user_id'] )['name'] ),
					'at'     => self::iso( $m['created_at'] ),
				);
			}
		}
		$c       = (int) $row['last_msg_id'] > (int) $row['my_read'] ? self::unread_in( $row['id'], (int) $row['my_read'], $u->ID ) : array( 'unread' => 0, 'mentions' => 0, 'urgent' => 0 );
		$members = 'desk' === $row['kind'] ? 0 : (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t( 'members' ) . ' WHERE room_id = %d AND removed = 0', $row['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$name    = $row['name'];
		$avatar  = '';
		$sub     = $row['about'];
		$online  = false;
		if ( 'desk' === $row['kind'] ) {
			if ( (int) $row['owner_id'] === (int) $u->ID ) {
				$name = 'ডেস্ক (এডিটর ও পাবলিশার)';
				$sub  = 'আপনার ও ডেস্কের সরাসরি আলাপ';
			} else {
				$p      = self::person( $row['owner_id'] );
				$name   = $p['name'];
				$avatar = $p['avatar'];
				$sub    = trim( 'সংবাদদাতা' . ( $p['area'] ? ' · ' . $p['area'] : '' ) );
				$online = $p['online'];
			}
		}
		return array(
			'id'       => (int) $row['id'],
			'kind'     => $row['kind'],
			'name'     => $name,
			'about'    => $sub,
			'icon'     => $row['icon'],
			'avatar'   => $avatar,
			'online'   => $online,
			'posting'  => $row['posting'],
			'archived' => (bool) (int) $row['archived'],
			'members'  => $members,
			'is_admin' => self::is_admin( $row, $u ),
			'can_post' => self::can_post( $row, $u ),
			'muted'    => self::muted( $row ),
			'muted_until' => self::muted( $row ) ? self::iso( $row['my_muted'] ) : null,
			'unread'   => $c['unread'],
			'mentions' => $c['mentions'],
			'urgent'   => $c['urgent'],
			'last'     => $last,
			'last_at'  => $row['last_at'] ? self::iso( $row['last_at'] ) : null,
		);
	}

	/**
	 * @return bool
	 */
	protected static function can_post( $room, $u ) {
		if ( (int) $room['archived'] ) {
			return false;
		}
		if ( 'desk' === $room['kind'] ) {
			return true;
		}
		if ( 'admins' === $room['posting'] ) {
			return self::is_admin( $room, $u );
		}
		return true;
	}

	/**
	 * GET /chat/rooms
	 */
	public static function list_rooms( $request ) {
		self::maybe_defaults();
		$u   = self::me( $request );
		$out = array();
		foreach ( self::rooms_for( $u ) as $row ) {
			$out[] = self::shape_room( $row, $u );
		}
		// pinned order: family first, then unread, then latest
		usort(
			$out,
			function ( $a, $b ) {
				if ( $a['archived'] !== $b['archived'] ) {
					return $a['archived'] ? 1 : -1;
				}
				$ka = 'family' === $a['kind'] ? 0 : 1;
				$kb = 'family' === $b['kind'] ? 0 : 1;
				if ( $ka !== $kb ) {
					return $ka - $kb;
				}
				return strcmp( (string) $b['last_at'], (string) $a['last_at'] );
			}
		);
		$labels = array();
		foreach ( self::LABELS as $k => $l ) {
			$labels[] = array( 'key' => $k, 'name' => $l[0], 'color' => $l[1] );
		}
		return rest_ensure_response(
			array(
				'rooms'     => $out,
				'labels'    => $labels,
				'reactions' => self::REACTIONS,
				'me'        => array(
					'id'           => (int) $u->ID,
					'staff'        => self::is_staff( $u ),
					'publisher'    => self::is_publisher( $u ),
				),
			)
		);
	}

	/**
	 * Local MySQL datetime → UTC ISO.
	 */
	protected static function iso( $local ) {
		if ( ! $local ) {
			return null;
		}
		return mysql_to_rfc3339( get_gmt_from_date( $local ) ) . 'Z';
	}

	/**
	 * Shapes a message.
	 *
	 * @param array   $m    Row.
	 * @param WP_User $u    Viewer.
	 * @param array   $room Room.
	 * @return array
	 */
	protected static function shape_msg( $m, $u, $room ) {
		global $wpdb;
		$del   = (bool) $m['deleted_at'];
		$files = array();
		if ( ! $del && $m['files'] ) {
			$ids = array_filter( array_map( 'intval', explode( ',', $m['files'] ) ) );
			if ( $ids ) {
				$rows = $wpdb->get_results( 'SELECT * FROM ' . self::t( 'files' ) . ' WHERE id IN (' . implode( ',', $ids ) . ')', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				foreach ( (array) $rows as $f ) {
					$files[] = self::shape_file( $f, $u );
				}
			}
		}
		$reply = null;
		if ( (int) $m['reply_to'] ) {
			$r = $wpdb->get_row( $wpdb->prepare( 'SELECT id, user_id, body, files, deleted_at FROM ' . self::t( 'messages' ) . ' WHERE id = %d AND room_id = %d', $m['reply_to'], $m['room_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( $r ) {
				$reply = array(
					'id'     => (int) $r['id'],
					'author' => self::person( $r['user_id'] )['name'],
					'text'   => $r['deleted_at'] ? 'মেসেজটি মুছে ফেলা হয়েছে' : ( '' !== trim( (string) $r['body'] ) ? wp_html_excerpt( (string) $r['body'], 120, '…' ) : '📎 ফাইল' ),
				);
			}
		}
		$reactions = array();
		$rx        = $m['reactions'] ? json_decode( $m['reactions'], true ) : array();
		foreach ( (array) $rx as $emoji => $uids ) {
			$uids = array_map( 'intval', (array) $uids );
			if ( ! $uids ) {
				continue;
			}
			$reactions[] = array(
				'emoji' => (string) $emoji,
				'count' => count( $uids ),
				'mine'  => in_array( (int) $u->ID, $uids, true ),
				'names' => array_slice( array_map( function ( $id ) { return NROS_Chat::person( $id )['name']; }, $uids ), 0, 12 ),
			);
		}
		$mine    = (int) $m['user_id'] === (int) $u->ID;
		$age_min = ( current_time( 'timestamp' ) - strtotime( $m['created_at'] ) ) / 60; // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		$p       = self::person( $m['user_id'] );
		$mentions = array_values( array_filter( array_map( 'intval', explode( ',', (string) $m['mentions'] ) ) ) );
		return array(
			'id'         => (int) $m['id'],
			'kind'       => $m['kind'],
			'author'     => 'system' === $m['kind'] ? null : $p,
			'mine'       => $mine,
			'body'       => $del ? '' : (string) $m['body'],
			'label'      => $del ? '' : (string) $m['label'],
			'meeting_at' => $m['meeting_at'] && ! $del ? self::iso( $m['meeting_at'] ) : null,
			'reply'      => $del ? null : $reply,
			'files'      => $files,
			'reactions'  => $del ? array() : $reactions,
			'pinned'     => (bool) (int) $m['pinned'],
			'mentions_me'=> in_array( (int) $u->ID, $mentions, true ),
			'edited'     => (bool) $m['edited_at'],
			'deleted'    => $del,
			'can_edit'   => ! $del && $mine && 'text' === $m['kind'] && $age_min <= self::EDIT_MINUTES,
			'can_delete' => ! $del && 'text' === $m['kind'] && ( $mine || self::is_admin( $room, $u ) ),
			'can_purge'  => self::is_publisher( $u ),
			'at'         => self::iso( $m['created_at'] ),
		);
	}

	/**
	 * GET /chat/rooms/{id}/messages?after=&before=&label=&q=&pinned=1
	 */
	public static function list_messages( $request ) {
		global $wpdb;
		$u    = self::me( $request );
		$room = self::room( (int) $request['id'] );
		if ( ! $room || ! self::can_read( $room, $u ) ) {
			return new WP_Error( 'nros_forbidden', 'এই রুমে আপনি নেই।', array( 'status' => 403 ) );
		}
		$g      = self::t( 'messages' );
		$after  = (int) $request->get_param( 'after' );
		$before = (int) $request->get_param( 'before' );
		$label  = sanitize_key( (string) $request->get_param( 'label' ) );
		$q      = trim( (string) $request->get_param( 'q' ) );
		$where  = $wpdb->prepare( 'room_id = %d', $room['id'] );
		if ( $label && isset( self::LABELS[ $label ] ) ) {
			$where .= $wpdb->prepare( ' AND label = %s AND deleted_at IS NULL', $label );
		}
		if ( '' !== $q ) {
			$where .= $wpdb->prepare( ' AND body LIKE %s AND deleted_at IS NULL', '%' . $wpdb->esc_like( $q ) . '%' );
		}
		if ( $request->get_param( 'pinned' ) ) {
			$where .= ' AND pinned = 1 AND deleted_at IS NULL';
		}
		$limit = 60;
		if ( $after ) {
			$rows = $wpdb->get_results( "SELECT * FROM {$g} WHERE {$where} AND id > {$after} ORDER BY id ASC LIMIT 200", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			$b    = $before ? " AND id < {$before}" : '';
			$rows = array_reverse( (array) $wpdb->get_results( "SELECT * FROM {$g} WHERE {$where}{$b} ORDER BY id DESC LIMIT {$limit}", ARRAY_A ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		// messages edited/reacted/deleted since the client's last look (so open rooms stay fresh)
		$changed = array();
		$gone    = array();
		$since   = (int) $request->get_param( 'changed_since' );
		if ( $after && $since ) {
			// edits, deletes, reactions and pins all call bump(), which keeps
			// "message id => time" for five minutes
			$ids = array();
			foreach ( (array) get_transient( 'nros_chat_chg_' . $room['id'] ) as $mid => $ts ) {
				if ( (int) $ts >= $since - 2 && (int) $mid <= $after ) {
					$ids[] = (int) $mid;
				}
			}
			if ( $ids ) {
				$ch = $wpdb->get_results( "SELECT * FROM {$g} WHERE room_id = " . (int) $room['id'] . ' AND id IN (' . implode( ',', $ids ) . ')', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$found = array();
				foreach ( (array) $ch as $m ) {
					$changed[] = self::shape_msg( $m, $u, $room );
					$found[]   = (int) $m['id'];
				}
				// removed from the record by a Publisher
				$gone = array_values( array_diff( $ids, $found ) );
			}
		}
		$out = array();
		foreach ( (array) $rows as $m ) {
			$out[] = self::shape_msg( $m, $u, $room );
		}
		// read marks of the others (for "দেখেছেন")
		$reads = $wpdb->get_results( $wpdb->prepare( 'SELECT user_id, last_read_id FROM ' . self::t( 'members' ) . ' WHERE room_id = %d AND removed = 0 AND last_read_id > 0 AND user_id <> %d', $room['id'], $u->ID ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$seen  = array();
		foreach ( (array) $reads as $r ) {
			$seen[] = array( 'id' => (int) $r['user_id'], 'read' => (int) $r['last_read_id'] );
		}
		// who is typing now
		$typing = array();
		$tp     = get_transient( 'nros_chat_typ_' . $room['id'] );
		foreach ( (array) $tp as $tid => $ts ) {
			if ( (int) $tid !== (int) $u->ID && time() - (int) $ts < 7 ) {
				$typing[] = self::person( $tid )['name'];
			}
		}
		$has_more = false;
		if ( ! $after && $out ) {
			$has_more = (bool) $wpdb->get_var( "SELECT id FROM {$g} WHERE {$where} AND id < " . (int) $out[0]['id'] . ' LIMIT 1' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$pinned = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$g} WHERE room_id = %d AND pinned = 1 AND deleted_at IS NULL", $room['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row    = $wpdb->get_row( $wpdb->prepare( 'SELECT r.*, m.role AS my_role, m.removed AS my_removed, m.last_read_id AS my_read, m.muted_until AS my_muted FROM ' . self::t( 'rooms' ) . ' r LEFT JOIN ' . self::t( 'members' ) . ' m ON m.room_id = r.id AND m.user_id = %d WHERE r.id = %d', $u->ID, $room['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return rest_ensure_response(
			array(
				'room'     => self::shape_room( $row, $u ),
				'messages' => $out,
				'changed'  => $changed,
				'gone'     => $gone,
				'has_more' => $has_more,
				'seen'     => $seen,
				'typing'   => $typing,
				'pinned'   => $pinned,
				'now'      => time(),
			)
		);
	}

	/**
	 * Picks @mentions out of the text: ids sent by the app, kept only when the
	 * person can read the room.
	 */
	protected static function clean_mentions( $ids, $room ) {
		$out = array();
		foreach ( array_slice( array_map( 'intval', (array) $ids ), 0, 30 ) as $id ) {
			$p = get_userdata( $id );
			if ( $p && self::can_read( $room, $p ) ) {
				$out[] = $id;
			}
		}
		return implode( ',', array_unique( $out ) );
	}

	/**
	 * POST /chat/rooms/{id}/messages — { body, label?, reply_to?, file_ids?, mentions?, meeting_at? }
	 */
	public static function post_message( $request ) {
		global $wpdb;
		$u    = self::me( $request );
		$room = self::room( (int) $request['id'] );
		if ( ! $room || ! self::can_read( $room, $u ) ) {
			return new WP_Error( 'nros_forbidden', 'এই রুমে আপনি নেই।', array( 'status' => 403 ) );
		}
		if ( ! self::can_post( $room, $u ) ) {
			return new WP_Error( 'nros_forbidden', 'এই রুমে শুধু অ্যাডমিন লিখতে পারেন।', array( 'status' => 403 ) );
		}
		$body  = trim( sanitize_textarea_field( (string) $request->get_param( 'body' ) ) );
		$body  = mb_substr( $body, 0, 4000 );
		$label = sanitize_key( (string) $request->get_param( 'label' ) );
		$label = isset( self::LABELS[ $label ] ) && 'general' !== $label ? $label : '';
		$files = array();
		foreach ( array_slice( array_map( 'intval', (array) $request->get_param( 'file_ids' ) ), 0, 10 ) as $fid ) {
			$ok = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'files' ) . ' WHERE id = %d AND user_id = %d AND room_id = %d', $fid, $u->ID, $room['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( $ok ) {
				$files[] = $fid;
			}
		}
		if ( '' === $body && ! $files ) {
			return new WP_Error( 'nros_empty', 'কিছু লিখুন বা ফাইল দিন।', array( 'status' => 400 ) );
		}
		$reply = (int) $request->get_param( 'reply_to' );
		if ( $reply && ! $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'messages' ) . ' WHERE id = %d AND room_id = %d', $reply, $room['id'] ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$reply = 0;
		}
		$meeting = null;
		$mat     = (string) $request->get_param( 'meeting_at' );
		if ( 'meeting' === $label && $mat && strtotime( $mat ) ) {
			$meeting = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', strtotime( $mat ) ) );
		}
		$now = current_time( 'mysql' );
		$wpdb->insert(
			self::t( 'messages' ),
			array(
				'room_id'    => (int) $room['id'],
				'user_id'    => (int) $u->ID,
				'kind'       => 'text',
				'body'       => $body,
				'label'      => $label,
				'meeting_at' => $meeting,
				'reply_to'   => $reply,
				'files'      => implode( ',', $files ),
				'mentions'   => self::clean_mentions( $request->get_param( 'mentions' ), $room ),
				'created_at' => $now,
			)
		);
		$id = (int) $wpdb->insert_id;
		$wpdb->update( self::t( 'rooms' ), array( 'last_msg_id' => $id, 'last_at' => $now ), array( 'id' => $room['id'] ) );
		self::touch_member( $room, $u );
		$wpdb->update( self::t( 'members' ), array( 'last_read_id' => $id ), array( 'room_id' => $room['id'], 'user_id' => $u->ID ) );
		// stop "typing…"
		$tp = (array) get_transient( 'nros_chat_typ_' . $room['id'] );
		unset( $tp[ $u->ID ] );
		set_transient( 'nros_chat_typ_' . $room['id'], $tp, 30 );
		$m = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'messages' ) . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return rest_ensure_response( self::shape_msg( $m, $u, $room ) );
	}

	/**
	 * A grey line in the room ("রহিম যোগ হয়েছেন").
	 */
	protected static function system( $room_id, $text ) {
		global $wpdb;
		$now = current_time( 'mysql' );
		$wpdb->insert( self::t( 'messages' ), array( 'room_id' => (int) $room_id, 'user_id' => 0, 'kind' => 'system', 'body' => $text, 'created_at' => $now ) );
		$wpdb->update( self::t( 'rooms' ), array( 'last_msg_id' => (int) $wpdb->insert_id, 'last_at' => $now ), array( 'id' => (int) $room_id ) );
	}

	/**
	 * Message + its room + checks.
	 *
	 * @return array|WP_Error [message, room, user]
	 */
	protected static function load_msg( $request ) {
		global $wpdb;
		$u = self::me( $request );
		$m = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'messages' ) . ' WHERE id = %d', (int) $request['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$room = $m ? self::room( $m['room_id'] ) : null;
		if ( ! $m || ! $room || ! self::can_read( $room, $u ) ) {
			return new WP_Error( 'nros_not_found', 'মেসেজ পাওয়া যায়নি।', array( 'status' => 404 ) );
		}
		return array( $m, $room, $u );
	}

	/**
	 * PATCH /chat/messages/{id} — { body, label? } (writer, within 15 minutes).
	 */
	public static function edit_message( $request ) {
		global $wpdb;
		$l = self::load_msg( $request );
		if ( is_wp_error( $l ) ) {
			return $l;
		}
		list( $m, $room, $u ) = $l;
		$age = ( current_time( 'timestamp' ) - strtotime( $m['created_at'] ) ) / 60; // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		if ( (int) $m['user_id'] !== (int) $u->ID || $m['deleted_at'] || $age > self::EDIT_MINUTES ) {
			return new WP_Error( 'nros_forbidden', 'নিজের মেসেজ ' . NROS_Core::bn_digits( self::EDIT_MINUTES ) . ' মিনিটের মধ্যে বদলানো যায়।', array( 'status' => 403 ) );
		}
		$body = mb_substr( trim( sanitize_textarea_field( (string) $request->get_param( 'body' ) ) ), 0, 4000 );
		if ( '' === $body && ! $m['files'] ) {
			return new WP_Error( 'nros_empty', 'মেসেজ খালি রাখা যায় না।', array( 'status' => 400 ) );
		}
		$data = array( 'body' => $body, 'edited_at' => current_time( 'mysql' ) );
		if ( null !== $request->get_param( 'label' ) ) {
			$label         = sanitize_key( (string) $request->get_param( 'label' ) );
			$data['label'] = isset( self::LABELS[ $label ] ) && 'general' !== $label ? $label : '';
		}
		$wpdb->update( self::t( 'messages' ), $data, array( 'id' => $m['id'] ) );
		self::bump( $room['id'], $m['id'] );
		$m = array_merge( $m, $data );
		return rest_ensure_response( self::shape_msg( $m, $u, $room ) );
	}

	/**
	 * DELETE /chat/messages/{id}[?purge=1] — writer or room admin hides it
	 * ("মুছে ফেলা হয়েছে"); a Publisher can remove it from the record for good.
	 */
	public static function delete_message( $request ) {
		global $wpdb;
		$l = self::load_msg( $request );
		if ( is_wp_error( $l ) ) {
			return $l;
		}
		list( $m, $room, $u ) = $l;
		if ( $request->get_param( 'purge' ) ) {
			if ( ! self::is_publisher( $u ) ) {
				return new WP_Error( 'nros_forbidden', 'স্থায়ীভাবে মুছতে পারেন শুধু পাবলিশার।', array( 'status' => 403 ) );
			}
			foreach ( array_filter( array_map( 'intval', explode( ',', (string) $m['files'] ) ) ) as $fid ) {
				self::delete_file( $fid );
			}
			$wpdb->delete( self::t( 'messages' ), array( 'id' => $m['id'] ) );
			self::bump( $room['id'], $m['id'] );
			return rest_ensure_response( array( 'deleted' => true, 'purged' => true, 'id' => (int) $m['id'] ) );
		}
		if ( 'text' !== $m['kind'] || ( (int) $m['user_id'] !== (int) $u->ID && ! self::is_admin( $room, $u ) ) ) {
			return new WP_Error( 'nros_forbidden', 'এই মেসেজ মুছতে পারবেন না।', array( 'status' => 403 ) );
		}
		$wpdb->update( self::t( 'messages' ), array( 'deleted_at' => current_time( 'mysql' ), 'pinned' => 0 ), array( 'id' => $m['id'] ) );
		self::bump( $room['id'], $m['id'] );
		$m['deleted_at'] = current_time( 'mysql' );
		return rest_ensure_response( self::shape_msg( $m, $u, $room ) );
	}

	/**
	 * POST /chat/messages/{id}/react — { emoji } toggles mine.
	 */
	public static function react( $request ) {
		global $wpdb;
		$l = self::load_msg( $request );
		if ( is_wp_error( $l ) ) {
			return $l;
		}
		list( $m, $room, $u ) = $l;
		$emoji = (string) $request->get_param( 'emoji' );
		if ( ! in_array( $emoji, self::REACTIONS, true ) || $m['deleted_at'] ) {
			return new WP_Error( 'nros_bad', 'এই প্রতিক্রিয়া দেওয়া যায় না।', array( 'status' => 400 ) );
		}
		$rx   = $m['reactions'] ? (array) json_decode( $m['reactions'], true ) : array();
		$list = array_map( 'intval', isset( $rx[ $emoji ] ) ? (array) $rx[ $emoji ] : array() );
		if ( in_array( (int) $u->ID, $list, true ) ) {
			$list = array_values( array_diff( $list, array( (int) $u->ID ) ) );
		} else {
			$list[] = (int) $u->ID;
		}
		if ( $list ) {
			$rx[ $emoji ] = $list;
		} else {
			unset( $rx[ $emoji ] );
		}
		$m['reactions'] = $rx ? wp_json_encode( $rx, JSON_UNESCAPED_UNICODE ) : '';
		// counts as a change so open rooms pick it up
		$m['edited_at'] = $m['edited_at'];
		$wpdb->update( self::t( 'messages' ), array( 'reactions' => $m['reactions'] ), array( 'id' => $m['id'] ) );
		self::bump( $room['id'], $m['id'] );
		return rest_ensure_response( self::shape_msg( $m, $u, $room ) );
	}

	/**
	 * Remembers "message changed" so others' open rooms refresh it.
	 */
	protected static function bump( $room_id, $msg_id ) {
		$k = 'nros_chat_chg_' . (int) $room_id;
		$c = (array) get_transient( $k );
		$c[ (int) $msg_id ] = time();
		$c = array_filter( $c, function ( $t ) { return time() - $t < 300; } );
		set_transient( $k, $c, 600 );
	}

	/**
	 * POST /chat/messages/{id}/pin — room admins.
	 */
	public static function pin( $request ) {
		global $wpdb;
		$l = self::load_msg( $request );
		if ( is_wp_error( $l ) ) {
			return $l;
		}
		list( $m, $room, $u ) = $l;
		if ( ! self::is_admin( $room, $u ) || $m['deleted_at'] ) {
			return new WP_Error( 'nros_forbidden', 'পিন করতে পারেন রুমের অ্যাডমিন।', array( 'status' => 403 ) );
		}
		$m['pinned'] = (int) $m['pinned'] ? 0 : 1;
		$wpdb->update( self::t( 'messages' ), array( 'pinned' => $m['pinned'] ), array( 'id' => $m['id'] ) );
		self::bump( $room['id'], $m['id'] );
		return rest_ensure_response( self::shape_msg( $m, $u, $room ) );
	}

	/**
	 * POST /chat/rooms/{id}/read — { last_id }
	 */
	public static function mark_read( $request ) {
		global $wpdb;
		$u    = self::me( $request );
		$room = self::room( (int) $request['id'] );
		if ( ! $room || ! self::can_read( $room, $u ) ) {
			return new WP_Error( 'nros_forbidden', 'এই রুমে আপনি নেই।', array( 'status' => 403 ) );
		}
		self::touch_member( $room, $u );
		$last = min( (int) $request->get_param( 'last_id' ), (int) $room['last_msg_id'] );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'members' ) . ' SET last_read_id = GREATEST(last_read_id, %d) WHERE room_id = %d AND user_id = %d', $last, $room['id'], $u->ID ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * POST /chat/rooms/{id}/typing
	 */
	public static function typing( $request ) {
		$u    = self::me( $request );
		$room = self::room( (int) $request['id'] );
		if ( ! $room || ! self::can_read( $room, $u ) ) {
			return new WP_Error( 'nros_forbidden', 'এই রুমে আপনি নেই।', array( 'status' => 403 ) );
		}
		$k            = 'nros_chat_typ_' . $room['id'];
		$tp           = (array) get_transient( $k );
		$tp[ $u->ID ] = time();
		$tp           = array_filter( $tp, function ( $t ) { return time() - (int) $t < 10; } );
		set_transient( $k, $tp, 30 );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * POST /chat/rooms/{id}/mute — { hours } (0 = unmute, -1 = always).
	 */
	public static function mute( $request ) {
		global $wpdb;
		$u    = self::me( $request );
		$room = self::room( (int) $request['id'] );
		if ( ! $room || ! self::can_read( $room, $u ) ) {
			return new WP_Error( 'nros_forbidden', 'এই রুমে আপনি নেই।', array( 'status' => 403 ) );
		}
		self::touch_member( $room, $u );
		$h     = (int) $request->get_param( 'hours' );
		$until = 0 === $h ? null : gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) + ( $h < 0 ? 20 * YEAR_IN_SECONDS : $h * HOUR_IN_SECONDS ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		$wpdb->update( self::t( 'members' ), array( 'muted_until' => $until ), array( 'room_id' => $room['id'], 'user_id' => $u->ID ) );
		return rest_ensure_response( array( 'ok' => true, 'muted' => null !== $until ) );
	}

	/**
	 * GET /chat/rooms/{id}/members
	 */
	public static function list_members( $request ) {
		global $wpdb;
		$u    = self::me( $request );
		$room = self::room( (int) $request['id'] );
		if ( ! $room || ! self::can_read( $room, $u ) ) {
			return new WP_Error( 'nros_forbidden', 'এই রুমে আপনি নেই।', array( 'status' => 403 ) );
		}
		$admin = self::is_admin( $room, $u );
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT user_id, role, removed FROM ' . self::t( 'members' ) . ' WHERE room_id = %d', $room['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$list  = array();
		$gone  = array();
		foreach ( (array) $rows as $r ) {
			if ( ! get_userdata( $r['user_id'] ) ) {
				continue;
			}
			$p          = self::person( $r['user_id'] );
			$p['admin'] = 'admin' === $r['role'];
			if ( (int) $r['removed'] ) {
				if ( $admin ) {
					$gone[] = $p;
				}
				continue;
			}
			// in a desk room only the correspondent and staff who took part are listed
			$list[] = $p;
		}
		usort(
			$list,
			function ( $a, $b ) {
				if ( $a['admin'] !== $b['admin'] ) {
					return $a['admin'] ? -1 : 1;
				}
				if ( $a['online'] !== $b['online'] ) {
					return $a['online'] ? -1 : 1;
				}
				return strcmp( $a['name'], $b['name'] );
			}
		);
		return rest_ensure_response( array( 'members' => $list, 'removed' => $gone, 'is_admin' => $admin ) );
	}

	/**
	 * POST /chat/rooms/{id}/members — { user_ids } (admins; brings back removed people too).
	 */
	public static function add_members( $request ) {
		global $wpdb;
		$u    = self::me( $request );
		$room = self::room( (int) $request['id'] );
		if ( ! $room || 'desk' === $room['kind'] || ! self::is_admin( $room, $u ) ) {
			return new WP_Error( 'nros_forbidden', 'সদস্য যোগ করতে পারেন রুমের অ্যাডমিন।', array( 'status' => 403 ) );
		}
		$added = array();
		foreach ( array_slice( array_map( 'intval', (array) $request->get_param( 'user_ids' ) ), 0, 500 ) as $id ) {
			$p = get_userdata( $id );
			if ( ! $p || ( ! self::is_correspondent( $p ) && ! self::is_staff( $p ) ) ) {
				continue;
			}
			$m = self::member( $room['id'], $id );
			if ( $m && ! (int) $m['removed'] ) {
				continue;
			}
			if ( $m ) {
				$wpdb->update( self::t( 'members' ), array( 'removed' => 0, 'last_read_id' => (int) $room['last_msg_id'] ), array( 'room_id' => $room['id'], 'user_id' => $id ) );
			} else {
				$wpdb->insert( self::t( 'members' ), array( 'room_id' => (int) $room['id'], 'user_id' => $id, 'role' => self::is_publisher( $p ) ? 'admin' : 'member', 'joined_at' => current_time( 'mysql' ), 'last_read_id' => (int) $room['last_msg_id'] ) );
			}
			$added[] = $p->display_name;
		}
		if ( $added ) {
			self::system( $room['id'], $u->display_name . ' যোগ করেছেন: ' . implode( ', ', array_slice( $added, 0, 8 ) ) . ( count( $added ) > 8 ? ' ও আরও ' . NROS_Core::bn_digits( count( $added ) - 8 ) . ' জন' : '' ) );
		}
		return self::list_members( $request );
	}

	/**
	 * PATCH /chat/rooms/{id}/members/{uid} — { admin: bool } (admins).
	 */
	public static function update_member( $request ) {
		global $wpdb;
		$u    = self::me( $request );
		$room = self::room( (int) $request['id'] );
		$uid  = (int) $request['uid'];
		if ( ! $room || 'desk' === $room['kind'] || ! self::is_admin( $room, $u ) || ! self::member( $room['id'], $uid ) ) {
			return new WP_Error( 'nros_forbidden', 'এটা করতে পারেন রুমের অ্যাডমিন।', array( 'status' => 403 ) );
		}
		$wpdb->update( self::t( 'members' ), array( 'role' => $request->get_param( 'admin' ) ? 'admin' : 'member' ), array( 'room_id' => $room['id'], 'user_id' => $uid ) );
		return self::list_members( $request );
	}

	/**
	 * DELETE /chat/rooms/{id}/members/{uid} — an admin removes someone, or
	 * someone leaves a room (not the auto rooms: there only an admin can).
	 */
	public static function remove_member( $request ) {
		global $wpdb;
		$u    = self::me( $request );
		$room = self::room( (int) $request['id'] );
		$uid  = (int) $request['uid'];
		if ( ! $room || 'desk' === $room['kind'] ) {
			return new WP_Error( 'nros_forbidden', 'এখান থেকে কাউকে বাদ দেওয়া যায় না।', array( 'status' => 403 ) );
		}
		$self  = $uid === (int) $u->ID;
		$admin = self::is_admin( $room, $u );
		if ( ! $admin && ! ( $self && 'room' === $room['kind'] ) ) {
			return new WP_Error( 'nros_forbidden', 'কাউকে বাদ দিতে পারেন রুমের অ্যাডমিন।', array( 'status' => 403 ) );
		}
		$target = get_userdata( $uid );
		if ( $target && self::is_publisher( $target ) && ! self::is_publisher( $u ) ) {
			return new WP_Error( 'nros_forbidden', 'পাবলিশারকে বাদ দেওয়া যায় না।', array( 'status' => 403 ) );
		}
		$wpdb->update( self::t( 'members' ), array( 'removed' => 1, 'role' => 'member' ), array( 'room_id' => $room['id'], 'user_id' => $uid ) );
		$name = $target ? $target->display_name : '';
		self::system( $room['id'], $self ? $name . ' রুম ছেড়েছেন' : $u->display_name . ' বাদ দিয়েছেন: ' . $name );
		return $self ? rest_ensure_response( array( 'left' => true ) ) : self::list_members( $request );
	}

	/**
	 * POST /chat/rooms — { name, about?, icon?, member_ids?, posting? } (staff).
	 */
	public static function create_room( $request ) {
		global $wpdb;
		$u    = self::me( $request );
		$name = mb_substr( trim( sanitize_text_field( (string) $request->get_param( 'name' ) ) ), 0, 100 );
		if ( '' === $name ) {
			return new WP_Error( 'nros_bad', 'রুমের একটা নাম দিন।', array( 'status' => 400 ) );
		}
		$icon = mb_substr( trim( sanitize_text_field( (string) $request->get_param( 'icon' ) ) ), 0, 4 );
		$wpdb->insert(
			self::t( 'rooms' ),
			array(
				'kind'       => 'room',
				'name'       => $name,
				'about'      => mb_substr( sanitize_text_field( (string) $request->get_param( 'about' ) ), 0, 250 ),
				'icon'       => '' !== $icon ? $icon : '👥',
				'created_by' => (int) $u->ID,
				'posting'    => 'admins' === $request->get_param( 'posting' ) ? 'admins' : 'all',
				'created_at' => current_time( 'mysql' ),
				'last_at'    => current_time( 'mysql' ),
			)
		);
		$id = (int) $wpdb->insert_id;
		$wpdb->insert( self::t( 'members' ), array( 'room_id' => $id, 'user_id' => (int) $u->ID, 'role' => 'admin', 'joined_at' => current_time( 'mysql' ) ) );
		self::system( $id, $u->display_name . ' রুমটি খুলেছেন' );
		$request['id'] = $id;
		$request->set_param( 'user_ids', (array) $request->get_param( 'member_ids' ) );
		self::add_members( $request );
		return rest_ensure_response( array( 'id' => $id ) );
	}

	/**
	 * PATCH /chat/rooms/{id} — { name?, about?, icon?, posting?, archived? } (admins).
	 */
	public static function update_room( $request ) {
		global $wpdb;
		$u    = self::me( $request );
		$room = self::room( (int) $request['id'] );
		if ( ! $room || 'desk' === $room['kind'] || ! self::is_admin( $room, $u ) ) {
			return new WP_Error( 'nros_forbidden', 'রুম বদলাতে পারেন অ্যাডমিন।', array( 'status' => 403 ) );
		}
		$data = array();
		foreach ( array( 'name' => 100, 'about' => 250, 'icon' => 4 ) as $k => $len ) {
			if ( null !== $request->get_param( $k ) ) {
				$v = mb_substr( trim( sanitize_text_field( (string) $request->get_param( $k ) ) ), 0, $len );
				if ( 'name' !== $k || '' !== $v ) {
					$data[ $k ] = $v;
				}
			}
		}
		if ( null !== $request->get_param( 'posting' ) ) {
			$data['posting'] = 'admins' === $request->get_param( 'posting' ) ? 'admins' : 'all';
		}
		if ( null !== $request->get_param( 'archived' ) && 'room' === $room['kind'] ) {
			$data['archived'] = $request->get_param( 'archived' ) ? 1 : 0;
		}
		if ( $data ) {
			$wpdb->update( self::t( 'rooms' ), $data, array( 'id' => $room['id'] ) );
			if ( isset( $data['posting'] ) && $data['posting'] !== $room['posting'] ) {
				self::system( $room['id'], 'admins' === $data['posting'] ? 'এখন থেকে এই রুমে শুধু অ্যাডমিন লিখতে পারবেন' : 'এখন থেকে সবাই লিখতে পারবেন' );
			}
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * POST /chat/desk — { user_id? }: a correspondent opens their desk room;
	 * staff open (or start) the one of a correspondent.
	 */
	public static function open_desk( $request ) {
		$u = self::me( $request );
		if ( self::is_staff( $u ) ) {
			$cid = (int) $request->get_param( 'user_id' );
			$c   = get_userdata( $cid );
			if ( ! $c || ! self::is_correspondent( $c ) ) {
				return new WP_Error( 'nros_bad', 'সংবাদদাতা পাওয়া যায়নি।', array( 'status' => 400 ) );
			}
		} else {
			$cid = (int) $u->ID;
		}
		$id = self::desk_room( $cid );
		if ( self::is_staff( $u ) ) {
			// keeps the conversation in my list even before the first message
			self::touch_member( self::room( $id ), $u );
		}
		return rest_ensure_response( array( 'id' => $id ) );
	}

	/**
	 * GET /chat/people?q= — correspondents and staff (to add to rooms / start a desk chat).
	 */
	public static function people_search( $request ) {
		$q    = trim( sanitize_text_field( (string) $request->get_param( 'q' ) ) );
		$args = array(
			'role__in' => array( 'administrator', 'meeha_publisher', 'editor', 'correspondent' ),
			'number'   => 40,
			'orderby'  => 'display_name',
			'fields'   => 'ID',
		);
		if ( '' !== $q ) {
			$args['search']         = '*' . $q . '*';
			$args['search_columns'] = array( 'display_name', 'user_login', 'user_email' );
		}
		$ids = get_users( $args );
		if ( '' !== $q && count( $ids ) < 40 ) {
			// district / upazila too
			$more = get_users(
				array(
					'role'       => 'correspondent',
					'number'     => 40,
					'fields'     => 'ID',
					'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_query
						'relation' => 'OR',
						array( 'key' => 'meeha_district', 'value' => $q, 'compare' => 'LIKE' ),
						array( 'key' => 'meeha_upazila', 'value' => $q, 'compare' => 'LIKE' ),
					),
				)
			);
			$ids = array_unique( array_merge( $ids, $more ) );
		}
		return rest_ensure_response( array( 'people' => array_values( array_map( array( __CLASS__, 'person' ), array_slice( $ids, 0, 60 ) ) ) ) );
	}

	// ---------------------------------------------------------------------
	// Files (kept outside the public uploads folder, read through signed links)
	// ---------------------------------------------------------------------

	/**
	 * @return string
	 */
	protected static function dir() {
		$dir = NROS_PRIVATE_DIR . '/chat';
		if ( ! is_dir( $dir ) ) {
			NROS_Core::create_private_dir();
			wp_mkdir_p( $dir );
		}
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			@file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" );
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			@file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
		}
		// phpcs:enable
		return $dir;
	}

	/**
	 * @return string Absolute path or ''.
	 */
	protected static function path_of( $stored ) {
		return preg_match( '#^\d{4}/\d{2}/[a-f0-9]{32}\.bin$#', (string) $stored ) ? self::dir() . '/' . $stored : '';
	}

	/**
	 * Signed link to one chat file.
	 */
	protected static function url( $id, $uid, $dl = false ) {
		$exp  = (int) ( ceil( ( time() + 6 * HOUR_IN_SECONDS ) / 900 ) * 900 );
		$body = (int) $id . '.' . (int) $uid . '.' . $exp . '.' . ( $dl ? 1 : 0 );
		$sig  = substr( hash_hmac( 'sha256', $body, wp_salt( 'secure_auth' ) . '|nros-chat' ), 0, 40 );
		return rest_url( NROS_API_NS . '/chat/files/' . (int) $id ) . '?t=' . rawurlencode( $body . '.' . $sig );
	}

	/**
	 * @return array
	 */
	protected static function shape_file( $f, $u ) {
		return array(
			'id'       => (int) $f['id'],
			'name'     => $f['name'],
			'kind'     => $f['kind'],
			'mime'     => $f['mime'],
			'size'     => (int) $f['size'],
			'duration' => (int) $f['duration'],
			'url'      => self::url( $f['id'], $u->ID ),
			'download' => self::url( $f['id'], $u->ID, true ),
		);
	}

	/**
	 * POST /chat/files — multipart: file, room_id, duration? → the stored file.
	 */
	public static function upload( $request ) {
		global $wpdb;
		$u    = self::me( $request );
		$room = self::room( (int) $request->get_param( 'room_id' ) );
		if ( ! $room || ! self::can_read( $room, $u ) || ! self::can_post( $room, $u ) ) {
			return new WP_Error( 'nros_forbidden', 'এই রুমে ফাইল দিতে পারবেন না।', array( 'status' => 403 ) );
		}
		$files = $request->get_file_params();
		$f     = isset( $files['file'] ) ? $files['file'] : null;
		if ( ! $f || ! empty( $f['error'] ) || ! is_uploaded_file( $f['tmp_name'] ) ) {
			return new WP_Error( 'nros_upload', 'ফাইল আসেনি, আবার চেষ্টা করুন।', array( 'status' => 400 ) );
		}
		if ( (int) $f['size'] > self::MAX_FILE ) {
			return new WP_Error( 'nros_upload', 'ফাইল সর্বোচ্চ ২৫ এমবি হতে পারে।', array( 'status' => 413 ) );
		}
		$name  = sanitize_file_name( (string) $f['name'] );
		$check = wp_check_filetype_and_ext( $f['tmp_name'], $name );
		$mime  = $check['type'] ? $check['type'] : ( function_exists( 'mime_content_type' ) ? (string) mime_content_type( $f['tmp_name'] ) : '' );
		$ok    = '/^(image\/(jpeg|png|gif|webp)|audio\/(webm|ogg|mpeg|mp4|x-m4a|aac|wav)|video\/(mp4|webm|quicktime)|application\/pdf|application\/(msword|vnd\.openxmlformats-officedocument\.[a-z.]+|vnd\.ms-excel)|text\/plain)$/';
		if ( ! preg_match( $ok, $mime ) ) {
			// voice notes from the browser come as audio/webm or video/webm without a known extension
			$sniff = function_exists( 'mime_content_type' ) ? (string) mime_content_type( $f['tmp_name'] ) : '';
			if ( preg_match( '/^(audio|video)\/(webm|ogg|mp4)$/', $sniff ) && preg_match( '/^voice/i', $name ) ) {
				$mime = 'audio/' . explode( '/', $sniff )[1];
			} else {
				return new WP_Error( 'nros_upload', 'এই ধরনের ফাইল দেওয়া যায় না (ছবি, অডিও, ভিডিও, PDF, Word/Excel চলে)।', array( 'status' => 415 ) );
			}
		}
		if ( preg_match( '/^voice/i', $name ) && preg_match( '/^(audio|video)\/(webm|ogg|mp4)$/', $mime, $vm ) ) {
			$mime = 'audio/' . $vm[2]; // a voice note recorded in the app
		}
		$kind   = 0 === strpos( $mime, 'image/' ) ? 'image' : ( 0 === strpos( $mime, 'audio/' ) ? 'audio' : ( 0 === strpos( $mime, 'video/' ) ? 'video' : 'file' ) );
		$sub    = gmdate( 'Y/m' );
		$stored = $sub . '/' . bin2hex( random_bytes( 16 ) ) . '.bin';
		wp_mkdir_p( self::dir() . '/' . $sub );
		if ( ! @move_uploaded_file( $f['tmp_name'], self::dir() . '/' . $stored ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new WP_Error( 'nros_upload', 'সার্ভারে ফাইল রাখা গেল না।', array( 'status' => 500 ) );
		}
		$wpdb->insert(
			self::t( 'files' ),
			array(
				'room_id'    => (int) $room['id'],
				'user_id'    => (int) $u->ID,
				'disk_name'  => $stored,
				'name'       => mb_substr( '' !== $name ? $name : 'file', 0, 180 ),
				'mime'       => $mime,
				'kind'       => $kind,
				'size'       => (int) $f['size'],
				'duration'   => min( 3600, max( 0, (int) $request->get_param( 'duration' ) ) ),
				'created_at' => current_time( 'mysql' ),
			)
		);
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'files' ) . ' WHERE id = %d', $wpdb->insert_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return rest_ensure_response( self::shape_file( $row, $u ) );
	}

	/**
	 * @param int $id File id.
	 */
	protected static function delete_file( $id ) {
		global $wpdb;
		$f = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'files' ) . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $f ) {
			return;
		}
		$p = self::path_of( $f['disk_name'] );
		if ( $p && file_exists( $p ) ) {
			wp_delete_file( $p );
		}
		$wpdb->delete( self::t( 'files' ), array( 'id' => $id ) );
	}

	/**
	 * GET /chat/files/{id}?t= — streams a file (Range supported, for voice/video).
	 */
	public static function stream( $request ) {
		global $wpdb;
		$id    = (int) $request['id'];
		$parts = explode( '.', (string) $request->get_param( 't' ) );
		$bad   = new WP_Error( 'nros_forbidden', 'লিংকের মেয়াদ শেষ। চ্যাটটি আবার খুলুন।', array( 'status' => 403 ) );
		if ( 5 !== count( $parts ) ) {
			return $bad;
		}
		list( $fid, $uid, $exp, $dl, $sig ) = $parts;
		$want = substr( hash_hmac( 'sha256', $fid . '.' . $uid . '.' . $exp . '.' . $dl, wp_salt( 'secure_auth' ) . '|nros-chat' ), 0, 40 );
		if ( ! hash_equals( $want, (string) $sig ) || (int) $fid !== $id || (int) $exp < time() ) {
			return $bad;
		}
		$f    = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'files' ) . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$user = get_userdata( (int) $uid );
		$room = $f ? self::room( $f['room_id'] ) : null;
		if ( ! $f || ! $user || ! $room || ! self::can_read( $room, $user ) ) {
			return $bad;
		}
		$path = self::path_of( $f['disk_name'] );
		if ( ! $path || ! file_exists( $path ) ) {
			return new WP_Error( 'nros_not_found', 'ফাইল সার্ভারে নেই।', array( 'status' => 404 ) );
		}
		$size = (int) filesize( $path );
		$from = 0;
		$to   = $size - 1;
		$part = false;
		$rng  = isset( $_SERVER['HTTP_RANGE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) ) : '';
		if ( $size > 0 && preg_match( '/^bytes=(\d*)-(\d*)$/', $rng, $m ) ) {
			if ( '' === $m[1] && '' !== $m[2] ) {
				$from = max( 0, $size - (int) $m[2] );
			} elseif ( '' !== $m[1] ) {
				$from = (int) $m[1];
				$to   = '' !== $m[2] ? min( $to, (int) $m[2] ) : $to;
			}
			if ( $from > $to || $from >= $size ) {
				status_header( 416 );
				header( 'Content-Range: bytes */' . $size );
				exit;
			}
			$part = true;
		}
		$inline = '1' !== $dl && ( in_array( $f['kind'], array( 'image', 'video', 'audio' ), true ) || 'application/pdf' === $f['mime'] );
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		status_header( $part ? 206 : 200 );
		header( 'Content-Type: ' . ( $f['mime'] ? $f['mime'] : 'application/octet-stream' ) );
		header( 'Content-Length: ' . ( $to - $from + 1 ) );
		header( 'Accept-Ranges: bytes' );
		if ( $part ) {
			header( "Content-Range: bytes {$from}-{$to}/{$size}" );
		}
		header( 'Content-Disposition: ' . ( $inline ? 'inline' : 'attachment' ) . "; filename*=UTF-8''" . rawurlencode( $f['name'] ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( "Content-Security-Policy: default-src 'none'; img-src 'self' data:; media-src 'self'; style-src 'unsafe-inline'" . ( 'application/pdf' === $f['mime'] ? '' : '; sandbox' ) );
		header( 'Cache-Control: private, max-age=3600' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		// phpcs:disable WordPress.WP.AlternativeFunctions
		$fh = fopen( $path, 'rb' );
		fseek( $fh, $from );
		$left = $to - $from + 1;
		while ( $left > 0 && ! feof( $fh ) ) {
			$chunk = fread( $fh, (int) min( 262144, $left ) );
			echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			$left -= strlen( $chunk );
			flush();
		}
		fclose( $fh );
		// phpcs:enable
		exit;
	}
}
