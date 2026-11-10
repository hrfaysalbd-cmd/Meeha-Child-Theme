<?php
/**
 * NROS_Chat_Tasks — টু-ডু inside the chat box, and the chat's helpers that
 * work on their own (newsroom-os 1.23.0).
 *
 *  - every room has a task list (title, who does it, by when, জরুরি);
 *  - a message with the “কাজ” label becomes a task by itself: one per
 *    @mentioned person, or one for the room when nobody is mentioned;
 *  - any message can be turned into a task from its menu;
 *  - মিহা সহকারী (the chat bot) announces new tasks, finished tasks, tasks
 *    that are about to run out of time and tasks that are late;
 *  - meetings (“মিটিং” label with a time) are announced 30 minutes before;
 *  - every morning at 9 each person with open tasks gets one short notice.
 *
 * The timed parts run on WP-Cron every 15 minutes (a real server cron that
 * calls wp-cron.php makes them punctual).
 *
 * @package NewsroomOS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NROS_Chat_Tasks extends NROS_Chat {

	const CRON = 'nros_chat_tick';

	/** Minutes before a meeting when the room is reminded. */
	const MEETING_LEAD = 30;

	/** Minutes before a task's time when the person is reminded. */
	const TASK_LEAD = 60;

	/** Local hour of the morning notice. */
	const DIGEST_HOUR = 9;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval
		add_action( self::CRON, array( __CLASS__, 'tick' ) );
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 300, 'nros_15min', self::CRON );
		}
	}

	/**
	 * @param array $s Schedules.
	 * @return array
	 */
	public static function schedules( $s ) {
		$s['nros_15min'] = array( 'interval' => 15 * MINUTE_IN_SECONDS, 'display' => 'Every 15 minutes (Newsroom OS)' );
		return $s;
	}

	/**
	 * Routes.
	 */
	public static function routes() {
		$ns  = NROS_API_NS;
		$app = array( 'NROS_Core', 'require_app_user' );
		$r   = function ( $path, $method, $cb ) use ( $ns, $app ) {
			register_rest_route( $ns, $path, array( 'methods' => $method, 'callback' => array( __CLASS__, $cb ), 'permission_callback' => $app ) );
		};
		$r( '/chat/rooms/(?P<id>\d+)/tasks', 'GET', 'list_tasks' );
		$r( '/chat/rooms/(?P<id>\d+)/tasks', 'POST', 'create_task' );
		$r( '/chat/tasks', 'GET', 'my_tasks' );
		$r( '/chat/tasks/(?P<tid>\d+)', 'PATCH', 'update_task' );
		$r( '/chat/tasks/(?P<tid>\d+)', 'DELETE', 'delete_task' );
	}

	// ---------------------------------------------------------------------
	// Shapes and counts
	// ---------------------------------------------------------------------

	/**
	 * @param int $id Task id.
	 * @return array|null
	 */
	protected static function task( $id ) {
		global $wpdb;
		$t = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'tasks' ) . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $t ? $t : null;
	}

	/**
	 * Can this person tick / change / delete the task?
	 *
	 * @return array{done:bool,edit:bool}
	 */
	protected static function rights( $t, $room, $u ) {
		$mine  = (int) $t['assignee_id'] === (int) $u->ID;
		$maker = (int) $t['created_by'] === (int) $u->ID;
		$admin = self::is_admin( $room, $u ) || ( 'desk' === $room['kind'] && self::is_staff( $u ) );
		return array(
			'done' => $mine || $maker || $admin || ! (int) $t['assignee_id'],
			'edit' => $maker || $admin,
		);
	}

	/**
	 * @param array   $t    Task row.
	 * @param WP_User $u    Viewer.
	 * @param array   $room Room row.
	 * @return array
	 */
	protected static function shape( $t, $u, $room = null ) {
		$room  = $room ? $room : self::room( $t['room_id'] );
		$right = $room ? self::rights( $t, $room, $u ) : array( 'done' => false, 'edit' => false );
		$now   = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		$due   = $t['due_at'] ? strtotime( $t['due_at'] ) : 0;
		$out   = array(
			'id'         => (int) $t['id'],
			'room_id'    => (int) $t['room_id'],
			'msg_id'     => (int) $t['msg_id'],
			'title'      => (string) $t['title'],
			'assignee'   => (int) $t['assignee_id'] ? self::person( $t['assignee_id'] ) : null,
			'by'         => self::person( $t['created_by'] ),
			'urgent'     => (bool) (int) $t['urgent'],
			'due_at'     => $t['due_at'] ? self::iso( $t['due_at'] ) : null,
			'late'       => ! $t['done_at'] && $due && $due < $now,
			'done'       => (bool) $t['done_at'],
			'done_at'    => $t['done_at'] ? self::iso( $t['done_at'] ) : null,
			'done_by'    => (int) $t['done_by'] ? self::person( $t['done_by'] )['name'] : '',
			'mine'       => (int) $t['assignee_id'] === (int) $u->ID,
			'can_done'   => $right['done'],
			'can_edit'   => $right['edit'],
			'created_at' => self::iso( $t['created_at'] ),
		);
		if ( $room ) {
			$out['room'] = array( 'id' => (int) $room['id'], 'name' => self::room_title( $room, $u ), 'icon' => $room['icon'] ? $room['icon'] : '💬' );
		}
		return $out;
	}

	/** A room's name as this viewer sees it. */
	protected static function room_title( $room, $u ) {
		if ( 'desk' !== $room['kind'] ) {
			return $room['name'];
		}
		return (int) $room['owner_id'] === (int) $u->ID ? 'ডেস্ক (এডিটর ও পাবলিশার)' : self::person( $room['owner_id'] )['name'];
	}

	/**
	 * Open tasks per room (for the room list).
	 *
	 * @return array<int,int>
	 */
	public static function open_counts() {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT room_id, COUNT(*) AS n FROM ' . self::t( 'tasks' ) . ' WHERE done_at IS NULL GROUP BY room_id', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r['room_id'] ] = (int) $r['n'];
		}
		return $out;
	}

	/**
	 * My open tasks: how many, how many late.
	 *
	 * @return array{open:int,late:int}
	 */
	public static function mine_count( $uid ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) AS n, SUM(due_at IS NOT NULL AND due_at < %s) AS late FROM ' . self::t( 'tasks' ) . ' WHERE assignee_id = %d AND done_at IS NULL', current_time( 'mysql' ), $uid ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array( 'open' => (int) ( $row ? $row['n'] : 0 ), 'late' => (int) ( $row ? $row['late'] : 0 ) );
	}

	/**
	 * Tasks made from one message (shown under the bubble).
	 *
	 * @return array[]
	 */
	public static function for_message( $m, $u, $room ) {
		global $wpdb;
		static $by_room = array();
		$rid = (int) $room['id'];
		if ( ! isset( $by_room[ $rid ] ) ) {
			$by_room[ $rid ] = array();
			$rows            = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'tasks' ) . ' WHERE room_id = %d AND msg_id > 0', $rid ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			foreach ( (array) $rows as $t ) {
				$by_room[ $rid ][ (int) $t['msg_id'] ][] = $t;
			}
		}
		$out = array();
		foreach ( isset( $by_room[ $rid ][ (int) $m['id'] ] ) ? $by_room[ $rid ][ (int) $m['id'] ] : array() as $t ) {
			$s     = self::shape( $t, $u, $room );
			unset( $s['room'] );
			$out[] = $s;
		}
		return $out;
	}

	// ---------------------------------------------------------------------
	// Routes
	// ---------------------------------------------------------------------

	/**
	 * GET /chat/rooms/{id}/tasks — { open: [], done: [] (latest 30) }
	 */
	public static function list_tasks( $request ) {
		global $wpdb;
		$u    = self::me( $request );
		$room = self::room( (int) $request['id'] );
		if ( ! $room || ! self::can_read( $room, $u ) ) {
			return new WP_Error( 'nros_forbidden', 'এই রুমে আপনি নেই।', array( 'status' => 403 ) );
		}
		$k    = self::t( 'tasks' );
		$open = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$k} WHERE room_id = %d AND done_at IS NULL ORDER BY urgent DESC, due_at IS NULL, due_at ASC, id ASC LIMIT 300", $room['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$done = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$k} WHERE room_id = %d AND done_at IS NOT NULL ORDER BY done_at DESC LIMIT 30", $room['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$f    = function ( $t ) use ( $u, $room ) {
			$s = NROS_Chat_Tasks::shape_public( $t, $u, $room );
			unset( $s['room'] );
			return $s;
		};
		return rest_ensure_response(
			array(
				'open'    => array_map( $f, (array) $open ),
				'done'    => array_map( $f, (array) $done ),
				'can_add' => self::can_post( $room, $u ) || self::is_admin( $room, $u ),
			)
		);
	}

	/** shape() for closures. */
	public static function shape_public( $t, $u, $room = null ) {
		return self::shape( $t, $u, $room );
	}

	/**
	 * GET /chat/tasks?scope=mine|made — my tasks in every room.
	 */
	public static function my_tasks( $request ) {
		global $wpdb;
		$u     = self::me( $request );
		$k     = self::t( 'tasks' );
		$made  = 'made' === $request->get_param( 'scope' );
		$col   = $made ? 'created_by' : 'assignee_id';
		$open  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$k} WHERE {$col} = %d AND done_at IS NULL ORDER BY urgent DESC, due_at IS NULL, due_at ASC, id ASC LIMIT 300", $u->ID ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$done  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$k} WHERE {$col} = %d AND done_at IS NOT NULL ORDER BY done_at DESC LIMIT 20", $u->ID ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rooms = array();
		$f     = function ( $t ) use ( $u, &$rooms ) {
			$rid = (int) $t['room_id'];
			if ( ! array_key_exists( $rid, $rooms ) ) {
				$r              = NROS_Chat_Tasks::room_public( $rid );
				$rooms[ $rid ] = $r && NROS_Chat_Tasks::can_read_public( $r, $u ) ? $r : null;
			}
			return $rooms[ $rid ] ? NROS_Chat_Tasks::shape_public( $t, $u, $rooms[ $rid ] ) : null;
		};
		return rest_ensure_response(
			array(
				'open'  => array_values( array_filter( array_map( $f, (array) $open ) ) ),
				'done'  => array_values( array_filter( array_map( $f, (array) $done ) ) ),
				'count' => self::mine_count( $u->ID ),
			)
		);
	}

	/** room() for closures. */
	public static function room_public( $id ) {
		return self::room( $id );
	}

	/** can_read() for closures. */
	public static function can_read_public( $room, $u ) {
		return self::can_read( $room, $u );
	}

	/**
	 * Local "Y-m-d H:i:s" from an ISO time the app sent, or null.
	 */
	protected static function local_time( $iso ) {
		$iso = trim( (string) $iso );
		if ( '' === $iso || ! strtotime( $iso ) ) {
			return null;
		}
		return get_date_from_gmt( gmdate( 'Y-m-d H:i:s', strtotime( $iso ) ) );
	}

	/**
	 * Can this person be given a task in this room?
	 */
	protected static function assignable( $room, $uid ) {
		if ( ! $uid ) {
			return true;
		}
		$p = get_userdata( $uid );
		return $p && self::can_read( $room, $p );
	}

	/**
	 * POST /chat/rooms/{id}/tasks — { title, assignee_id?, due_at?, urgent?, msg_id? }
	 */
	public static function create_task( $request ) {
		$u    = self::me( $request );
		$room = self::room( (int) $request['id'] );
		if ( ! $room || ! self::can_read( $room, $u ) ) {
			return new WP_Error( 'nros_forbidden', 'এই রুমে আপনি নেই।', array( 'status' => 403 ) );
		}
		if ( ! self::can_post( $room, $u ) && ! self::is_admin( $room, $u ) ) {
			return new WP_Error( 'nros_forbidden', 'এই রুমে কাজ দিতে পারেন শুধু অ্যাডমিন।', array( 'status' => 403 ) );
		}
		$title = mb_substr( trim( sanitize_text_field( (string) $request->get_param( 'title' ) ) ), 0, 250 );
		if ( '' === $title ) {
			return new WP_Error( 'nros_bad', 'কাজটা লিখুন।', array( 'status' => 400 ) );
		}
		$who = (int) $request->get_param( 'assignee_id' );
		if ( ! self::assignable( $room, $who ) ) {
			return new WP_Error( 'nros_bad', 'যাঁকে কাজ দিচ্ছেন, তিনি এই রুমে নেই।', array( 'status' => 400 ) );
		}
		$msg = (int) $request->get_param( 'msg_id' );
		$id  = self::add( $room, $u, $title, $who, self::local_time( $request->get_param( 'due_at' ) ), (bool) $request->get_param( 'urgent' ), $msg );
		return rest_ensure_response( self::shape( self::task( $id ), $u, $room ) );
	}

	/**
	 * Saves a task and tells the room (and the person).
	 *
	 * @return int Task id.
	 */
	protected static function add( $room, $u, $title, $who, $due, $urgent, $msg_id = 0, $announce = true ) {
		global $wpdb;
		$wpdb->insert(
			self::t( 'tasks' ),
			array(
				'room_id'     => (int) $room['id'],
				'msg_id'      => (int) $msg_id,
				'title'       => $title,
				'assignee_id' => (int) $who,
				'created_by'  => (int) $u->ID,
				'urgent'      => $urgent ? 1 : 0,
				'due_at'      => $due,
				'created_at'  => current_time( 'mysql' ),
			)
		);
		$id = (int) $wpdb->insert_id;
		if ( $announce ) {
			$whom = $who ? self::tag( $who ) : 'রুমের যে কেউ';
			$when = $due ? ' · শেষ সময়: ' . self::bn_when( $due ) : '';
			self::bot_say( $room, ( $urgent ? '🚨 জরুরি কাজ' : '✅ নতুন কাজ' ) . " — “{$title}”\nদায়িত্ব: {$whom}{$when}\nদিয়েছেন: " . $u->display_name, $who && $who !== (int) $u->ID ? array( $who ) : array(), $urgent ? 'urgent' : '' );
		}
		return $id;
	}

	/**
	 * “কাজ” label → tasks (one per @mention, or one for the room).
	 */
	public static function from_message( $room, $u, $msg_id, $body, $mentions, $due_iso = '' ) {
		$title = wp_html_excerpt( trim( preg_replace( '/@[\p{L}\p{M}\p{N}_.]+/u', '', $body ) ), 200, '…' );
		$title = '' !== trim( $title ) ? trim( $title ) : wp_html_excerpt( $body, 200, '…' );
		$due   = self::local_time( $due_iso );
		$ids   = array();
		foreach ( array_unique( array_map( 'intval', (array) $mentions ) ) as $id ) {
			if ( $id && self::assignable( $room, $id ) ) {
				$ids[] = $id;
			}
		}
		if ( ! $ids ) {
			$ids = array( 0 );
		}
		foreach ( array_slice( $ids, 0, 20 ) as $who ) {
			// the message itself already reached the room: no extra bot line
			self::add( $room, $u, $title, $who, $due, false, $msg_id, false );
		}
	}

	/**
	 * PATCH /chat/tasks/{tid} — { done?, title?, assignee_id?, due_at?, urgent? }
	 */
	public static function update_task( $request ) {
		global $wpdb;
		$u = self::me( $request );
		$t = self::task( (int) $request['tid'] );
		$room = $t ? self::room( $t['room_id'] ) : null;
		if ( ! $t || ! $room || ! self::can_read( $room, $u ) ) {
			return new WP_Error( 'nros_not_found', 'কাজটি পাওয়া যায়নি।', array( 'status' => 404 ) );
		}
		$right = self::rights( $t, $room, $u );
		$data  = array();
		if ( null !== $request->get_param( 'done' ) ) {
			if ( ! $right['done'] ) {
				return new WP_Error( 'nros_forbidden', 'কাজটি শেষ চিহ্ন দিতে পারেন যাঁর কাজ, যিনি দিয়েছেন বা রুমের অ্যাডমিন।', array( 'status' => 403 ) );
			}
			$done = (bool) $request->get_param( 'done' );
			if ( $done && ! $t['done_at'] ) {
				$data['done_at'] = current_time( 'mysql' );
				$data['done_by'] = (int) $u->ID;
				$maker           = (int) $t['created_by'];
				self::bot_say( $room, "☑️ কাজ শেষ — “{$t['title']}”\nশেষ করেছেন: " . $u->display_name, $maker && $maker !== (int) $u->ID ? array( $maker ) : array() );
			} elseif ( ! $done && $t['done_at'] ) {
				$data['done_at']  = null;
				$data['done_by']  = 0;
				$data['reminded'] = 0;
			}
		}
		$edit = array();
		foreach ( array( 'title', 'assignee_id', 'due_at', 'urgent' ) as $k ) {
			if ( null !== $request->get_param( $k ) ) {
				$edit[ $k ] = $request->get_param( $k );
			}
		}
		if ( $edit ) {
			if ( ! $right['edit'] ) {
				return new WP_Error( 'nros_forbidden', 'কাজটি বদলাতে পারেন যিনি দিয়েছেন বা রুমের অ্যাডমিন।', array( 'status' => 403 ) );
			}
			if ( isset( $edit['title'] ) ) {
				$v = mb_substr( trim( sanitize_text_field( (string) $edit['title'] ) ), 0, 250 );
				if ( '' !== $v ) {
					$data['title'] = $v;
				}
			}
			if ( isset( $edit['assignee_id'] ) ) {
				$who = (int) $edit['assignee_id'];
				if ( ! self::assignable( $room, $who ) ) {
					return new WP_Error( 'nros_bad', 'যাঁকে কাজ দিচ্ছেন, তিনি এই রুমে নেই।', array( 'status' => 400 ) );
				}
				if ( $who !== (int) $t['assignee_id'] ) {
					$data['assignee_id'] = $who;
					$data['reminded']    = 0;
					if ( $who ) {
						$title = isset( $data['title'] ) ? $data['title'] : $t['title'];
						self::bot_say( $room, "🔁 কাজের দায়িত্ব বদল — “{$title}”\nএখন দায়িত্বে: " . self::tag( $who ), $who !== (int) $u->ID ? array( $who ) : array() );
					}
				}
			}
			if ( array_key_exists( 'due_at', $edit ) ) {
				$data['due_at']   = self::local_time( $edit['due_at'] );
				$data['reminded'] = 0;
			}
			if ( isset( $edit['urgent'] ) ) {
				$data['urgent'] = $edit['urgent'] ? 1 : 0;
			}
		}
		if ( $data ) {
			$wpdb->update( self::t( 'tasks' ), $data, array( 'id' => (int) $t['id'] ) );
			if ( (int) $t['msg_id'] ) {
				self::bump( $room['id'], (int) $t['msg_id'] );
			}
		}
		return rest_ensure_response( self::shape( self::task( (int) $t['id'] ), $u, $room ) );
	}

	/**
	 * DELETE /chat/tasks/{tid}
	 */
	public static function delete_task( $request ) {
		global $wpdb;
		$u    = self::me( $request );
		$t    = self::task( (int) $request['tid'] );
		$room = $t ? self::room( $t['room_id'] ) : null;
		if ( ! $t || ! $room || ! self::can_read( $room, $u ) ) {
			return new WP_Error( 'nros_not_found', 'কাজটি পাওয়া যায়নি।', array( 'status' => 404 ) );
		}
		if ( ! self::rights( $t, $room, $u )['edit'] ) {
			return new WP_Error( 'nros_forbidden', 'কাজটি মুছতে পারেন যিনি দিয়েছেন বা রুমের অ্যাডমিন।', array( 'status' => 403 ) );
		}
		$wpdb->delete( self::t( 'tasks' ), array( 'id' => (int) $t['id'] ) );
		if ( (int) $t['msg_id'] ) {
			self::bump( $room['id'], (int) $t['msg_id'] );
		}
		return rest_ensure_response( array( 'deleted' => true, 'id' => (int) $t['id'] ) );
	}

	// ---------------------------------------------------------------------
	// Timed helpers (WP-Cron, every 15 minutes)
	// ---------------------------------------------------------------------

	/**
	 * Runs the timed work. Each part is safe to run twice (flags on the rows).
	 */
	public static function tick() {
		if ( ! self::tables_ready() ) {
			return;
		}
		self::meeting_reminders();
		self::task_reminders();
		self::morning_digest();
	}

	/**
	 * “মিটিং” messages: a bot line + notice 30 minutes before.
	 */
	public static function meeting_reminders() {
		global $wpdb;
		$now  = current_time( 'mysql' );
		$soon = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) + self::MEETING_LEAD * MINUTE_IN_SECONDS ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'messages' ) . " WHERE label = 'meeting' AND reminded = 0 AND deleted_at IS NULL AND meeting_at IS NOT NULL AND meeting_at > %s AND meeting_at <= %s LIMIT 50", $now, $soon ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( (array) $rows as $m ) {
			$wpdb->update( self::t( 'messages' ), array( 'reminded' => 1 ), array( 'id' => (int) $m['id'] ) );
			$room = self::room( $m['room_id'] );
			if ( ! $room || (int) $room['archived'] ) {
				continue;
			}
			$mins  = max( 1, (int) round( ( strtotime( $m['meeting_at'] ) - current_time( 'timestamp' ) ) / 60 ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
			$title = wp_html_excerpt( trim( (string) $m['body'] ), 120, '…' );
			$ids   = $wpdb->get_col( $wpdb->prepare( 'SELECT user_id FROM ' . self::t( 'members' ) . ' WHERE room_id = %d AND removed = 0', $room['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( 'desk' === $room['kind'] ) {
				$ids[] = (int) $room['owner_id'];
			}
			self::bot_say( $room, '⏰ ' . NROS_Core::bn_digits( $mins ) . " মিনিট পরে মিটিং শুরু — “{$title}”\nসময়: " . self::bn_when( $m['meeting_at'] ), array_map( 'intval', $ids ) );
		}
	}

	/**
	 * Tasks: a notice an hour before the time, then a bot line when late.
	 */
	public static function task_reminders() {
		global $wpdb;
		$k    = self::t( 'tasks' );
		$now  = current_time( 'mysql' );
		$soon = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) + self::TASK_LEAD * MINUTE_IN_SECONDS ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		// 1 = reminded before the time
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$k} WHERE done_at IS NULL AND reminded = 0 AND assignee_id > 0 AND due_at > %s AND due_at <= %s LIMIT 100", $now, $soon ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $rows as $t ) {
			$wpdb->update( $k, array( 'reminded' => 1 ), array( 'id' => (int) $t['id'] ) );
			$room = self::room( $t['room_id'] );
			if ( $room ) {
				self::notify( array( (int) $t['assignee_id'] ), $room, '⏰ কাজের সময় ঘনিয়ে এসেছে', '“' . $t['title'] . '” · শেষ সময় ' . self::bn_when( $t['due_at'] ), (bool) (int) $t['urgent'] );
			}
		}
		// 2 = told the room it is late
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$k} WHERE done_at IS NULL AND reminded < 2 AND due_at IS NOT NULL AND due_at <= %s LIMIT 100", $now ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $rows as $t ) {
			$wpdb->update( $k, array( 'reminded' => 2 ), array( 'id' => (int) $t['id'] ) );
			$room = self::room( $t['room_id'] );
			if ( ! $room || (int) $room['archived'] ) {
				continue;
			}
			$who  = (int) $t['assignee_id'];
			$tell = array_values( array_unique( array_filter( array( $who, (int) $t['created_by'] ) ) ) );
			self::bot_say( $room, "⚠️ সময় পেরিয়ে গেছে — “{$t['title']}”\n" . ( $who ? 'দায়িত্বে: ' . self::tag( $who ) . ' · ' : '' ) . 'শেষ সময় ছিল ' . self::bn_when( $t['due_at'] ) . "\nশেষ হলে টু-ডু তালিকায় ✓ দিন।", $tell );
		}
	}

	/**
	 * At 9 in the morning: one notice per person with open tasks.
	 */
	public static function morning_digest() {
		global $wpdb;
		if ( (int) current_time( 'G' ) < self::DIGEST_HOUR ) {
			return;
		}
		$today = current_time( 'Y-m-d' );
		if ( get_option( 'nros_chat_digest_day' ) === $today ) {
			return;
		}
		update_option( 'nros_chat_digest_day', $today, false );
		$now  = current_time( 'mysql' );
		$end  = $today . ' 23:59:59';
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT assignee_id AS uid, COUNT(*) AS n, SUM(due_at IS NOT NULL AND due_at < %s) AS late, SUM(due_at IS NOT NULL AND due_at >= %s AND due_at <= %s) AS today FROM ' . self::t( 'tasks' ) . ' WHERE done_at IS NULL AND assignee_id > 0 GROUP BY assignee_id', $now, $now, $end ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $rows || ! class_exists( 'NROS_Push' ) ) {
			return;
		}
		foreach ( $rows as $r ) {
			$parts = array( NROS_Core::bn_digits( (int) $r['n'] ) . 'টি কাজ বাকি' );
			if ( (int) $r['today'] ) {
				$parts[] = 'আজ শেষ করতে হবে ' . NROS_Core::bn_digits( (int) $r['today'] ) . 'টি';
			}
			if ( (int) $r['late'] ) {
				$parts[] = NROS_Core::bn_digits( (int) $r['late'] ) . 'টির সময় পেরিয়েছে';
			}
			$text = implode( ' · ', $parts );
			NROS_Push::to_users(
				array( (int) $r['uid'] ),
				function ( $sub ) use ( $text ) {
					return array(
						'title' => '☀️ সুপ্রভাত! আজকের কাজ',
						'body'  => $text,
						'url'   => 'correspondent' === $sub['app'] ? '/app/chat?tasks=1' : '/app/inbox?tab=chat&tasks=1',
						'tag'   => 'tasks-digest',
						'kind'  => 'chat',
					);
				},
				array( 'topic' => 'tasks-digest', 'ttl' => 12 * HOUR_IN_SECONDS )
			);
		}
	}

	// ---------------------------------------------------------------------
	// Little helpers
	// ---------------------------------------------------------------------

	/** "@নাম_পদবি" for a user. */
	protected static function tag( $uid ) {
		$u = get_userdata( $uid );
		return $u ? '@' . preg_replace( '/\s+/u', '_', $u->display_name ) : '';
	}

	/** Local time as "১২ নভেম্বর, সন্ধ্যা ৭:৩০". */
	protected static function bn_when( $local ) {
		$ts = strtotime( $local );
		if ( ! $ts ) {
			return '';
		}
		$months = array( 'জানুয়ারি', 'ফেব্রুয়ারি', 'মার্চ', 'এপ্রিল', 'মে', 'জুন', 'জুলাই', 'আগস্ট', 'সেপ্টেম্বর', 'অক্টোবর', 'নভেম্বর', 'ডিসেম্বর' );
		$h      = (int) gmdate( 'G', $ts );
		$part   = $h < 5 ? 'রাত' : ( $h < 12 ? 'সকাল' : ( $h < 15 ? 'দুপুর' : ( $h < 18 ? 'বিকেল' : ( $h < 20 ? 'সন্ধ্যা' : 'রাত' ) ) ) );
		$h12    = $h % 12 ? $h % 12 : 12;
		$today  = current_time( 'Y-m-d' );
		$day    = gmdate( 'Y-m-d', $ts );
		$tomorrow = gmdate( 'Y-m-d', strtotime( $today . ' +1 day' ) );
		$date   = $day === $today ? 'আজ' : ( $day === $tomorrow ? 'আগামীকাল' : NROS_Core::bn_digits( (int) gmdate( 'j', $ts ) ) . ' ' . $months[ (int) gmdate( 'n', $ts ) - 1 ] );
		return $date . ', ' . $part . ' ' . NROS_Core::bn_digits( $h12 . ':' . gmdate( 'i', $ts ) );
	}

	/**
	 * A phone notice that opens the room.
	 */
	protected static function notify( $uids, $room, $title, $body, $urgent = false ) {
		if ( ! class_exists( 'NROS_Push' ) ) {
			return;
		}
		$rid = (int) $room['id'];
		NROS_Push::to_users(
			$uids,
			function ( $sub ) use ( $rid, $title, $body, $urgent ) {
				return array(
					'title'  => $title,
					'body'   => $body,
					'url'    => 'correspondent' === $sub['app'] ? '/app/chat?room=' . $rid . '&tasks=1' : '/app/inbox?tab=chat&room=' . $rid . '&tasks=1',
					'tag'    => 'task-' . $rid,
					'urgent' => $urgent,
					'kind'   => 'chat',
				);
			},
			array( 'topic' => 'task-' . $rid, 'urgency' => $urgent ? 'high' : 'normal', 'ttl' => 6 * HOUR_IN_SECONDS )
		);
	}
}
