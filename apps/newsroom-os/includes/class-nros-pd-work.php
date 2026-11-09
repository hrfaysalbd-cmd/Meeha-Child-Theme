<?php
/**
 * প্রোফাইল ডেস্ক ২.০ (1.16.0) — the desk's working layer on top of the profiles:
 * to-dos with reminders, a dated timeline per profile, typed relations between
 * profiles, pins and "recently opened", saved lists, bulk import / CSV export,
 * a completeness score, and Meeha's own news and quotes that name a profile.
 *
 * Visibility follows the profile: anything tied to a "restricted" profile is
 * seen only by those who can see that profile.
 *
 * @package NewsroomOS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tasks, timeline, relations, pins, saved lists, import/export, coverage.
 */
class NROS_PD_Work {

	/** Timeline kinds. */
	const EVENT_KINDS = array(
		'statement' => 'বক্তব্য',
		'arrest'    => 'গ্রেপ্তার / আটক',
		'case'      => 'মামলা / অভিযোগ',
		'verdict'   => 'রায় / জামিন',
		'position'  => 'পদ / দায়িত্ব',
		'rally'     => 'সমাবেশ / কর্মসূচি',
		'meeting'   => 'বৈঠক / সাক্ষাৎ',
		'media'     => 'সাক্ষাৎকার / মিডিয়া',
		'travel'    => 'সফর / দেশত্যাগ',
		'contact'   => 'আমাদের যোগাযোগ',
		'note'      => 'নোট',
		'other'     => 'অন্যান্য',
	);

	/** Relation kinds (read the same from both sides). */
	const RELATION_KINDS = array(
		'family'  => 'পরিবার / আত্মীয়',
		'ally'    => 'ঘনিষ্ঠ / সহযোগী',
		'rival'   => 'প্রতিদ্বন্দ্বী / বিরোধী',
		'member'  => 'সদস্য / কর্মী',
		'former'  => 'সাবেক সম্পর্ক',
		'lawyer'  => 'আইনজীবী',
		'source'  => 'সূত্র / যোগাযোগকারী',
		'funding' => 'অর্থায়ন',
		'other'   => 'অন্যান্য',
	);

	const MAX_IMPORT = 500;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		// rides the desk's 15-minute cron
		add_action( 'nros_pd_feeds', array( __CLASS__, 'remind' ) );
	}

	/**
	 * CREATE TABLE statements (dbDelta).
	 *
	 * @param string $c Charset.
	 * @return string
	 */
	public static function schema( $c ) {
		$tasks  = NROS_Profile_Desk::t( 'tasks' );
		$events = NROS_Profile_Desk::t( 'events' );
		$rels   = NROS_Profile_Desk::t( 'relations' );
		$marks  = NROS_Profile_Desk::t( 'marks' );
		$views  = NROS_Profile_Desk::t( 'views' );
		return "CREATE TABLE {$tasks} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			title VARCHAR(255) NOT NULL DEFAULT '',
			details TEXT NULL,
			entity_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			due_at DATETIME NULL,
			priority TINYINT NOT NULL DEFAULT 2,
			status VARCHAR(10) NOT NULL DEFAULT 'open',
			assignee_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			source_url VARCHAR(500) NOT NULL DEFAULT '',
			reminded TINYINT NOT NULL DEFAULT 0,
			created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			done_at DATETIME NULL,
			done_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY assignee_status (assignee_id, status),
			KEY entity_id (entity_id),
			KEY status_due (status, due_at)
		) {$c};

		CREATE TABLE {$events} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			entity_id BIGINT UNSIGNED NOT NULL,
			kind VARCHAR(20) NOT NULL DEFAULT 'note',
			happened_on DATE NOT NULL,
			title VARCHAR(255) NOT NULL DEFAULT '',
			details TEXT NULL,
			source_url VARCHAR(500) NOT NULL DEFAULT '',
			importance TINYINT NOT NULL DEFAULT 2,
			created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY entity_date (entity_id, happened_on),
			KEY happened_on (happened_on)
		) {$c};

		CREATE TABLE {$rels} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			a_id BIGINT UNSIGNED NOT NULL,
			b_id BIGINT UNSIGNED NOT NULL,
			kind VARCHAR(20) NOT NULL DEFAULT 'other',
			note VARCHAR(255) NOT NULL DEFAULT '',
			created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY pair_kind (a_id, b_id, kind),
			KEY b_id (b_id)
		) {$c};

		CREATE TABLE {$marks} (
			user_id BIGINT UNSIGNED NOT NULL,
			entity_id BIGINT UNSIGNED NOT NULL,
			kind VARCHAR(10) NOT NULL,
			at DATETIME NOT NULL,
			PRIMARY KEY  (user_id, entity_id, kind),
			KEY user_kind_at (user_id, kind, at)
		) {$c};

		CREATE TABLE {$views} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(120) NOT NULL DEFAULT '',
			params TEXT NULL,
			owner_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			shared TINYINT NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY owner_id (owner_id)
		) {$c};";
	}

	/**
	 * Routes.
	 */
	public static function register_routes() {
		$acc  = array( 'NROS_Profile_Desk', 'require_access' );
		$boss = array( 'NROS_Profile_Desk', 'require_boss' );
		$ns   = NROS_API_NS;
		$pair = function ( $get, $post ) use ( $acc ) {
			return array(
				array( 'methods' => 'GET', 'callback' => array( __CLASS__, $get ), 'permission_callback' => $acc ),
				array( 'methods' => 'POST', 'callback' => array( __CLASS__, $post ), 'permission_callback' => $acc ),
			);
		};
		$one  = function ( $patch, $delete ) use ( $acc ) {
			return array(
				array( 'methods' => 'PATCH', 'callback' => array( __CLASS__, $patch ), 'permission_callback' => $acc ),
				array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, $delete ), 'permission_callback' => $acc ),
			);
		};
		register_rest_route( $ns, '/pd/home', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'home' ), 'permission_callback' => $acc ) );
		register_rest_route( $ns, '/pd/entities/(?P<id>\d+)/pin', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'pin' ), 'permission_callback' => $acc ) );
		register_rest_route( $ns, '/pd/entities/(?P<id>\d+)/coverage', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'coverage' ), 'permission_callback' => $acc ) );
		register_rest_route( $ns, '/pd/tasks', $pair( 'list_tasks', 'create_task' ) );
		register_rest_route( $ns, '/pd/tasks/(?P<id>\d+)', $one( 'update_task', 'delete_task' ) );
		register_rest_route( $ns, '/pd/events', $pair( 'list_events', 'create_event' ) );
		register_rest_route( $ns, '/pd/events/(?P<id>\d+)', $one( 'update_event', 'delete_event' ) );
		register_rest_route( $ns, '/pd/relations', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'create_relation' ), 'permission_callback' => $acc ) );
		register_rest_route( $ns, '/pd/relations/(?P<id>\d+)', array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'delete_relation' ), 'permission_callback' => $acc ) );
		register_rest_route( $ns, '/pd/views', $pair( 'list_views', 'create_view' ) );
		register_rest_route( $ns, '/pd/views/(?P<id>\d+)', $one( 'update_view', 'delete_view' ) );
		register_rest_route( $ns, '/pd/import', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'import' ), 'permission_callback' => $acc ) );
		register_rest_route( $ns, '/pd/export', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'export' ), 'permission_callback' => $boss ) );
	}

	// ---------------------------------------------------------------------
	// Small helpers
	// ---------------------------------------------------------------------

	/**
	 * Desk people (for "assign to").
	 *
	 * @return array
	 */
	public static function people() {
		$out = array();
		foreach ( get_users( array( 'role__in' => array( 'administrator', 'meeha_publisher', 'editor' ), 'number' => 300 ) ) as $u ) {
			if ( NROS_Profile_Desk::can_access( $u ) ) {
				$out[] = array(
					'id'     => (int) $u->ID,
					'name'   => $u->display_name,
					'avatar' => get_avatar_url( $u->ID, array( 'size' => 64 ) ),
				);
			}
		}
		return $out;
	}

	/**
	 * Name of a user (cached per request).
	 *
	 * @param int $id User.
	 * @return string
	 */
	protected static function uname( $id ) {
		static $c = array();
		$id = (int) $id;
		if ( ! $id ) {
			return '';
		}
		if ( ! isset( $c[ $id ] ) ) {
			$u        = get_userdata( $id );
			$c[ $id ] = $u ? $u->display_name : '';
		}
		return $c[ $id ];
	}

	/**
	 * Entity ids from a list that the viewer may NOT see.
	 *
	 * @param int[]   $ids  Ids.
	 * @param WP_User $user Viewer.
	 * @return int[] visible ids.
	 */
	protected static function visible_ids( $ids, $user ) {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $ids ) ) ) );
		if ( ! $ids ) {
			return array();
		}
		$te  = NROS_Profile_Desk::t( 'entities' );
		$vis = NROS_Profile_Desk::visible_sql( $user );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( 'intval', $wpdb->get_col( "SELECT id FROM {$te} e WHERE {$vis} AND e.id IN (" . implode( ',', $ids ) . ')' ) );
	}

	/**
	 * SQL piece: "{col} = 0 OR the entity is visible".
	 *
	 * @param WP_User $user User.
	 * @param string  $col  Column holding the entity id.
	 * @return string
	 */
	protected static function vis_entity_sql( $user, $col ) {
		if ( NROS_Profile_Desk::is_boss( $user ) ) {
			return '1=1';
		}
		$te = NROS_Profile_Desk::t( 'entities' );
		return "({$col} = 0 OR {$col} IN (SELECT e.id FROM {$te} e WHERE " . NROS_Profile_Desk::visible_sql( $user ) . '))';
	}

	/**
	 * Local "Y-m-d H:i:s" from an app value ("2026-11-09T18:00" / "2026-11-09").
	 *
	 * @param mixed $v Value.
	 * @return string|null
	 */
	protected static function to_dt( $v ) {
		$v = trim( (string) $v );
		if ( '' === $v ) {
			return null;
		}
		if ( preg_match( '/^(\d{4}-\d{2}-\d{2})(?:[T ](\d{2}:\d{2}))?/', $v, $m ) ) {
			return $m[1] . ' ' . ( isset( $m[2] ) ? $m[2] : '23:59' ) . ':00';
		}
		return null;
	}

	/**
	 * Body param as array.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	protected static function body( $request ) {
		$p = $request->get_json_params();
		return is_array( $p ) ? $p : array();
	}

	// ---------------------------------------------------------------------
	// Completeness, pins, recent
	// ---------------------------------------------------------------------

	/**
	 * How complete a profile is: score 0–100 and what is missing.
	 *
	 * @param array $e      Entity row.
	 * @param int   $nlinks Links.
	 * @return array {score, missing[]}
	 */
	public static function completeness( $e, $nlinks ) {
		$checks = array(
			'photo'    => array( (int) $e['photo_file_id'] > 0, 'ছবি' ),
			'title'    => array( '' !== trim( (string) $e['title'] ), 'পদ / পরিচয়' ),
			'links'    => array( $nlinks > 0, 'সোশ্যাল লিংক' ),
			'place'    => array( '' !== trim( (string) $e['district'] ) || ( '' !== trim( (string) $e['country'] ) && 'বাংলাদেশ' !== $e['country'] ), 'জেলা / দেশ' ),
			'bio'      => array( mb_strlen( trim( wp_strip_all_tags( (string) $e['bio'] ) ) ) >= 40, 'পরিচিতি (অন্তত দুই লাইন)' ),
			'tags'     => array( '' !== trim( (string) $e['tags'] ), 'ট্যাগ' ),
			'both'     => array( '' !== trim( (string) $e['name_bn'] ) && '' !== trim( (string) $e['name_en'] ), 'বাংলা ও ইংরেজি দুই নাম' ),
		);
		if ( 'person' === $e['kind'] ) {
			$checks['org'] = array( (int) $e['org_id'] > 0, 'কোন দল / সংস্থার' );
		}
		$done    = 0;
		$missing = array();
		foreach ( $checks as $key => $c ) {
			if ( $c[0] ) {
				++$done;
			} else {
				$missing[] = array( 'key' => $key, 'label' => $c[1] );
			}
		}
		return array(
			'score'   => (int) round( 100 * $done / count( $checks ) ),
			'missing' => $missing,
		);
	}

	/**
	 * Ids the user pinned.
	 *
	 * @param int $uid User.
	 * @return int[]
	 */
	public static function pinned_ids( $uid ) {
		global $wpdb;
		$tm = NROS_Profile_Desk::t( 'marks' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT entity_id FROM {$tm} WHERE user_id = %d AND kind = 'pin' ORDER BY at ASC", (int) $uid ) ) );
	}

	/**
	 * Remembers that a user opened a profile.
	 *
	 * @param int $uid User.
	 * @param int $eid Entity.
	 */
	public static function touch_recent( $uid, $eid ) {
		global $wpdb;
		$tm = NROS_Profile_Desk::t( 'marks' );
		$now = current_time( 'mysql' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$n = $wpdb->query( $wpdb->prepare( "UPDATE {$tm} SET at = %s WHERE user_id = %d AND entity_id = %d AND kind = 'recent'", $now, (int) $uid, (int) $eid ) );
		if ( ! $n ) {
			$wpdb->insert( $tm, array( 'user_id' => (int) $uid, 'entity_id' => (int) $eid, 'kind' => 'recent', 'at' => $now ) );
		}
		// keep the 30 newest
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$old = $wpdb->get_col( $wpdb->prepare( "SELECT entity_id FROM {$tm} WHERE user_id = %d AND kind = 'recent' ORDER BY at DESC LIMIT 30, 100", (int) $uid ) );
		foreach ( $old as $o ) {
			$wpdb->delete( $tm, array( 'user_id' => (int) $uid, 'entity_id' => (int) $o, 'kind' => 'recent' ) );
		}
	}

	/**
	 * POST /pd/entities/{id}/pin — { on: bool }.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function pin( $request ) {
		global $wpdb;
		$user = NROS_Profile_Desk::me( $request );
		$id   = (int) $request->get_param( 'id' );
		if ( ! NROS_Profile_Desk::entity_row( $id, $user ) ) {
			return new WP_Error( 'nros_not_found', 'প্রোফাইল পাওয়া যায়নি।', array( 'status' => 404 ) );
		}
		$tm = NROS_Profile_Desk::t( 'marks' );
		$wpdb->delete( $tm, array( 'user_id' => (int) $user->ID, 'entity_id' => $id, 'kind' => 'pin' ) );
		$on = (bool) $request->get_param( 'on' );
		if ( $on ) {
			$wpdb->insert( $tm, array( 'user_id' => (int) $user->ID, 'entity_id' => $id, 'kind' => 'pin', 'at' => current_time( 'mysql' ) ) );
		}
		return new WP_REST_Response( array( 'pinned' => $on ), 200 );
	}

	/**
	 * Cards (entity + links) for a list of ids, in that order.
	 *
	 * @param int[]   $ids  Ids.
	 * @param WP_User $user Viewer.
	 * @return array
	 */
	protected static function cards( $ids, $user ) {
		global $wpdb;
		$ids = self::visible_ids( $ids, $user );
		if ( ! $ids ) {
			return array();
		}
		$te    = NROS_Profile_Desk::t( 'entities' );
		$rows  = $wpdb->get_results( "SELECT * FROM {$te} WHERE id IN (" . implode( ',', $ids ) . ')', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$by    = array();
		foreach ( $rows as $r ) {
			$by[ (int) $r['id'] ] = $r;
		}
		$links = NROS_Profile_Desk::links_for( $ids, $user );
		$orgs  = NROS_Profile_Desk::names_of( wp_list_pluck( $rows, 'org_id' ) );
		$out   = array();
		foreach ( $ids as $id ) {
			if ( ! isset( $by[ $id ] ) ) {
				continue;
			}
			$s          = NROS_Profile_Desk::shape( $by[ $id ], $user );
			$s['links'] = isset( $links[ $id ] ) ? $links[ $id ] : array();
			$s['org']   = isset( $orgs[ (int) $by[ $id ]['org_id'] ] ) ? $orgs[ (int) $by[ $id ]['org_id'] ] : null;
			$s['parent'] = null;
			$out[]      = $s;
		}
		return $out;
	}

	/**
	 * GET /pd/home — the desk's start page for the viewer.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function home( $request ) {
		global $wpdb;
		$user = NROS_Profile_Desk::me( $request );
		$uid  = (int) $user->ID;
		$tm   = NROS_Profile_Desk::t( 'marks' );
		$tt   = NROS_Profile_Desk::t( 'tasks' );
		$tv   = NROS_Profile_Desk::t( 'events' );
		$te   = NROS_Profile_Desk::t( 'entities' );
		$tl   = NROS_Profile_Desk::t( 'links' );
		$now  = current_time( 'mysql' );
		$eod  = substr( $now, 0, 10 ) . ' 23:59:59';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$recent = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT entity_id FROM {$tm} WHERE user_id = %d AND kind = 'recent' ORDER BY at DESC LIMIT 10", $uid ) ) );
		$tvis   = self::vis_entity_sql( $user, 't.entity_id' );
		$mine   = $wpdb->get_results( $wpdb->prepare( "SELECT t.* FROM {$tt} t WHERE t.status = 'open' AND (t.assignee_id = %d OR (t.assignee_id = 0 AND t.created_by = %d)) AND {$tvis} ORDER BY t.due_at IS NULL, t.due_at ASC, t.priority DESC LIMIT 30", $uid, $uid ), ARRAY_A );
		$given  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tt} t WHERE t.status = 'open' AND t.created_by = %d AND t.assignee_id NOT IN (0, %d)", $uid, $uid ) );
		$evis   = self::vis_entity_sql( $user, 'v.entity_id' );
		$events = $wpdb->get_results( "SELECT v.* FROM {$tv} v WHERE {$evis} ORDER BY v.happened_on DESC, v.id DESC LIMIT 8", ARRAY_A );
		$vis    = NROS_Profile_Desk::visible_sql( $user );
		// important profiles that most need filling in
		$weak   = $wpdb->get_results( "SELECT e.*, (SELECT COUNT(*) FROM {$tl} l WHERE l.entity_id = e.id) AS nl FROM {$te} e WHERE {$vis} AND e.importance >= 3 AND (e.photo_file_id = 0 OR e.bio = '' OR e.title = '') ORDER BY e.importance DESC, e.updated_at ASC LIMIT 6", ARRAY_A );
		// phpcs:enable
		$overdue = 0;
		$today   = 0;
		foreach ( $mine as $t ) {
			if ( $t['due_at'] && $t['due_at'] < $now ) {
				++$overdue;
			} elseif ( $t['due_at'] && $t['due_at'] <= $eod ) {
				++$today;
			}
		}
		return new WP_REST_Response(
			array(
				'pinned'   => self::cards( self::pinned_ids( $uid ), $user ),
				'recent'   => self::cards( $recent, $user ),
				'tasks'    => self::shape_tasks( $mine, $user ),
				'counts'   => array(
					'my_open' => count( $mine ),
					'overdue' => $overdue,
					'today'   => $today,
					'given'   => $given,
					'unseen'  => NROS_PD_Feeds::unseen_total( $user ),
				),
				'events'   => self::shape_events( $events ),
				'weak'     => array_map(
					function ( $e ) {
						$c = self::completeness( $e, (int) $e['nl'] );
						return array(
							'id'      => (int) $e['id'],
							'name'    => '' !== $e['name_bn'] ? $e['name_bn'] : $e['name_en'],
							'score'   => $c['score'],
							'missing' => wp_list_pluck( $c['missing'], 'label' ),
						);
					},
					$weak
				),
			),
			200
		);
	}

	/**
	 * Badge number for the sidebar: unseen posts + my tasks due today or late.
	 *
	 * @param WP_User $user User.
	 * @return int
	 */
	public static function badge( $user ) {
		global $wpdb;
		$tt  = NROS_Profile_Desk::t( 'tasks' );
		$eod = substr( current_time( 'mysql' ), 0, 10 ) . ' 23:59:59';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$due = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tt} WHERE status = 'open' AND (assignee_id = %d OR (assignee_id = 0 AND created_by = %d)) AND due_at IS NOT NULL AND due_at <= %s", (int) $user->ID, (int) $user->ID, $eod ) );
		return NROS_PD_Feeds::unseen_total( $user ) + $due;
	}

	// ---------------------------------------------------------------------
	// Tasks
	// ---------------------------------------------------------------------

	/**
	 * Shapes task rows.
	 *
	 * @param array   $rows Rows.
	 * @param WP_User $user Viewer.
	 * @return array
	 */
	public static function shape_tasks( $rows, $user ) {
		$names = NROS_Profile_Desk::names_of( wp_list_pluck( $rows, 'entity_id' ) );
		$boss  = NROS_Profile_Desk::is_boss( $user );
		$now   = current_time( 'mysql' );
		return array_map(
			function ( $t ) use ( $names, $boss, $user, $now ) {
				$mine = (int) $t['created_by'] === (int) $user->ID;
				return array(
					'id'          => (int) $t['id'],
					'title'       => $t['title'],
					'details'     => (string) $t['details'],
					'entity'      => isset( $names[ (int) $t['entity_id'] ] ) ? $names[ (int) $t['entity_id'] ] : null,
					'due_at'      => $t['due_at'],
					'overdue'     => 'open' === $t['status'] && $t['due_at'] && $t['due_at'] < $now,
					'priority'    => (int) $t['priority'],
					'status'      => $t['status'],
					'assignee'    => (int) $t['assignee_id'] ? array( 'id' => (int) $t['assignee_id'], 'name' => self::uname( $t['assignee_id'] ) ) : null,
					'creator'     => array( 'id' => (int) $t['created_by'], 'name' => self::uname( $t['created_by'] ) ),
					'source_url'  => $t['source_url'],
					'created_at'  => $t['created_at'],
					'done_at'     => $t['done_at'],
					'done_by'     => (int) $t['done_by'] ? self::uname( $t['done_by'] ) : '',
					'can_edit'    => $boss || $mine || (int) $t['assignee_id'] === (int) $user->ID,
					'can_delete'  => $boss || $mine,
				);
			},
			$rows
		);
	}

	/**
	 * GET /pd/tasks — scope=mine|given|all, status=open|done|all, entity, q, due=overdue|today|week.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function list_tasks( $request ) {
		global $wpdb;
		$user  = NROS_Profile_Desk::me( $request );
		$uid   = (int) $user->ID;
		$tt    = NROS_Profile_Desk::t( 'tasks' );
		$where = array( self::vis_entity_sql( $user, 't.entity_id' ) );
		$scope = (string) $request->get_param( 'scope' );
		if ( 'given' === $scope ) {
			$where[] = $wpdb->prepare( 't.created_by = %d AND t.assignee_id NOT IN (0, %d)', $uid, $uid );
		} elseif ( 'all' !== $scope ) {
			$where[] = $wpdb->prepare( '(t.assignee_id = %d OR (t.assignee_id = 0 AND t.created_by = %d))', $uid, $uid );
		}
		$status = (string) $request->get_param( 'status' );
		if ( 'done' === $status ) {
			$where[] = "t.status = 'done'";
		} elseif ( 'all' !== $status ) {
			$where[] = "t.status = 'open'";
		}
		$eid = (int) $request->get_param( 'entity' );
		if ( $eid ) {
			$where[] = $wpdb->prepare( 't.entity_id = %d', $eid );
		}
		$q = trim( (string) $request->get_param( 'q' ) );
		if ( '' !== $q ) {
			$like    = '%' . $wpdb->esc_like( $q ) . '%';
			$where[] = $wpdb->prepare( '(t.title LIKE %s OR t.details LIKE %s)', $like, $like );
		}
		$now = current_time( 'mysql' );
		$due = (string) $request->get_param( 'due' );
		if ( 'overdue' === $due ) {
			$where[] = $wpdb->prepare( "t.status = 'open' AND t.due_at IS NOT NULL AND t.due_at < %s", $now );
		} elseif ( 'today' === $due ) {
			$where[] = $wpdb->prepare( 't.due_at BETWEEN %s AND %s', substr( $now, 0, 10 ) . ' 00:00:00', substr( $now, 0, 10 ) . ' 23:59:59' );
		} elseif ( 'week' === $due ) {
			$where[] = $wpdb->prepare( 't.due_at IS NOT NULL AND t.due_at <= %s', gmdate( 'Y-m-d 23:59:59', strtotime( $now ) + 7 * DAY_IN_SECONDS ) );
		}
		$order = 'done' === $status ? 't.done_at DESC' : 't.due_at IS NULL, t.due_at ASC, t.priority DESC, t.id DESC';
		$w     = implode( ' AND ', $where );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT t.* FROM {$tt} t WHERE {$w} ORDER BY {$order} LIMIT 300", ARRAY_A );
		return new WP_REST_Response( self::shape_tasks( $rows, $user ), 200 );
	}

	/**
	 * Task fields from a request.
	 *
	 * @param array   $p      Body.
	 * @param bool    $create Creating.
	 * @param WP_User $user   User.
	 * @return array|WP_Error
	 */
	protected static function task_fields( $p, $create, $user ) {
		$out = array();
		if ( $create || array_key_exists( 'title', $p ) ) {
			$out['title'] = mb_substr( trim( sanitize_text_field( (string) ( isset( $p['title'] ) ? $p['title'] : '' ) ) ), 0, 255 );
			if ( '' === $out['title'] ) {
				return new WP_Error( 'nros_bad_request', 'কাজের নাম লিখুন।', array( 'status' => 400 ) );
			}
		}
		if ( array_key_exists( 'details', $p ) ) {
			$out['details'] = mb_substr( sanitize_textarea_field( (string) $p['details'] ), 0, 5000 );
		}
		if ( array_key_exists( 'due_at', $p ) ) {
			$out['due_at']   = self::to_dt( $p['due_at'] );
			$out['reminded'] = 0;
		}
		if ( array_key_exists( 'priority', $p ) ) {
			$out['priority'] = max( 1, min( 3, (int) $p['priority'] ) );
		}
		if ( array_key_exists( 'entity_id', $p ) ) {
			$eid = (int) $p['entity_id'];
			if ( $eid && ! NROS_Profile_Desk::entity_row( $eid, $user ) ) {
				return new WP_Error( 'nros_not_found', 'প্রোফাইল পাওয়া যায়নি।', array( 'status' => 404 ) );
			}
			$out['entity_id'] = max( 0, $eid );
		}
		if ( array_key_exists( 'assignee_id', $p ) ) {
			$aid = (int) $p['assignee_id'];
			if ( $aid ) {
				$a = get_userdata( $aid );
				if ( ! $a || ! NROS_Profile_Desk::can_access( $a ) ) {
					return new WP_Error( 'nros_bad_request', 'যাঁকে দিচ্ছেন তাঁর প্রোফাইল ডেস্কে প্রবেশাধিকার নেই।', array( 'status' => 400 ) );
				}
			}
			$out['assignee_id'] = max( 0, $aid );
		}
		if ( array_key_exists( 'source_url', $p ) ) {
			$out['source_url'] = substr( esc_url_raw( (string) $p['source_url'], array( 'http', 'https' ) ), 0, 500 );
		}
		return $out;
	}

	/**
	 * POST /pd/tasks.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_task( $request ) {
		global $wpdb;
		$user = NROS_Profile_Desk::me( $request );
		$f    = self::task_fields( self::body( $request ), true, $user );
		if ( is_wp_error( $f ) ) {
			return $f;
		}
		$now = current_time( 'mysql' );
		$f  += array( 'priority' => 2, 'entity_id' => 0, 'assignee_id' => 0 );
		$f['status']     = 'open';
		$f['created_by'] = (int) $user->ID;
		$f['created_at'] = $now;
		$f['updated_at'] = $now;
		$wpdb->insert( NROS_Profile_Desk::t( 'tasks' ), $f );
		$id = (int) $wpdb->insert_id;
		if ( $f['assignee_id'] && (int) $f['assignee_id'] !== (int) $user->ID ) {
			NROS_Notifications::add( array( $f['assignee_id'] ), 'pd_task', 'নতুন কাজ: ' . $f['title'], $user->display_name . ' আপনাকে দিয়েছেন।', '/app/profile-desk?tab=tasks', $id );
		}
		return self::task_response( $id, $user, 201 );
	}

	/**
	 * One task as a response.
	 *
	 * @param int     $id     Id.
	 * @param WP_User $user   Viewer.
	 * @param int     $status HTTP status.
	 * @return WP_REST_Response
	 */
	protected static function task_response( $id, $user, $status = 200 ) {
		global $wpdb;
		$tt  = NROS_Profile_Desk::t( 'tasks' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tt} WHERE id = %d", (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$s   = self::shape_tasks( array( $row ), $user );
		return new WP_REST_Response( $s[0], $status );
	}

	/**
	 * A task the viewer may see, or WP_Error.
	 *
	 * @param int     $id   Id.
	 * @param WP_User $user Viewer.
	 * @return array|WP_Error
	 */
	protected static function task_row( $id, $user ) {
		global $wpdb;
		$tt  = NROS_Profile_Desk::t( 'tasks' );
		$vis = self::vis_entity_sql( $user, 't.entity_id' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$t = $wpdb->get_row( $wpdb->prepare( "SELECT t.* FROM {$tt} t WHERE t.id = %d AND {$vis}", (int) $id ), ARRAY_A );
		return $t ? $t : new WP_Error( 'nros_not_found', 'কাজটি পাওয়া যায়নি।', array( 'status' => 404 ) );
	}

	/**
	 * PATCH /pd/tasks/{id} — fields, or { done: bool }.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_task( $request ) {
		global $wpdb;
		$user = NROS_Profile_Desk::me( $request );
		$t    = self::task_row( (int) $request->get_param( 'id' ), $user );
		if ( is_wp_error( $t ) ) {
			return $t;
		}
		$uid = (int) $user->ID;
		if ( ! NROS_Profile_Desk::is_boss( $user ) && (int) $t['created_by'] !== $uid && (int) $t['assignee_id'] !== $uid ) {
			return new WP_Error( 'nros_forbidden', 'শুধু যিনি কাজ দিয়েছেন বা যাঁকে দেওয়া হয়েছে তিনি বদলাতে পারবেন।', array( 'status' => 403 ) );
		}
		$p = self::body( $request );
		$f = self::task_fields( $p, false, $user );
		if ( is_wp_error( $f ) ) {
			return $f;
		}
		if ( array_key_exists( 'done', $p ) ) {
			$done = (bool) $p['done'];
			$f['status']  = $done ? 'done' : 'open';
			$f['done_at'] = $done ? current_time( 'mysql' ) : null;
			$f['done_by'] = $done ? $uid : 0;
			if ( $done && 'open' === $t['status'] && (int) $t['created_by'] !== $uid ) {
				NROS_Notifications::add( array( (int) $t['created_by'] ), 'pd_task', 'কাজ শেষ: ' . $t['title'], $user->display_name . ' শেষ করেছেন।', '/app/profile-desk?tab=tasks', (int) $t['id'] );
			}
		}
		if ( isset( $f['assignee_id'] ) && (int) $f['assignee_id'] && (int) $f['assignee_id'] !== (int) $t['assignee_id'] && (int) $f['assignee_id'] !== $uid ) {
			NROS_Notifications::add( array( (int) $f['assignee_id'] ), 'pd_task', 'নতুন কাজ: ' . ( isset( $f['title'] ) ? $f['title'] : $t['title'] ), $user->display_name . ' আপনাকে দিয়েছেন।', '/app/profile-desk?tab=tasks', (int) $t['id'] );
			$f['reminded'] = 0;
		}
		if ( $f ) {
			$f['updated_at'] = current_time( 'mysql' );
			$wpdb->update( NROS_Profile_Desk::t( 'tasks' ), $f, array( 'id' => (int) $t['id'] ) );
		}
		return self::task_response( (int) $t['id'], $user );
	}

	/**
	 * DELETE /pd/tasks/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_task( $request ) {
		global $wpdb;
		$user = NROS_Profile_Desk::me( $request );
		$t    = self::task_row( (int) $request->get_param( 'id' ), $user );
		if ( is_wp_error( $t ) ) {
			return $t;
		}
		if ( ! NROS_Profile_Desk::is_boss( $user ) && (int) $t['created_by'] !== (int) $user->ID ) {
			return new WP_Error( 'nros_forbidden', 'শুধু যিনি কাজ দিয়েছেন তিনি মুছতে পারবেন।', array( 'status' => 403 ) );
		}
		$wpdb->delete( NROS_Profile_Desk::t( 'tasks' ), array( 'id' => (int) $t['id'] ) );
		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/**
	 * Cron: tells people about tasks that are due within the hour, and once
	 * more when they are late.
	 */
	public static function remind() {
		global $wpdb;
		$tt   = NROS_Profile_Desk::t( 'tasks' );
		$now  = current_time( 'mysql' );
		$soon = gmdate( 'Y-m-d H:i:s', strtotime( $now ) + HOUR_IN_SECONDS );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$tt} WHERE status = 'open' AND due_at IS NOT NULL AND ((reminded = 0 AND due_at <= %s) OR (reminded < 2 AND due_at < %s)) LIMIT 200", $soon, $now ), ARRAY_A );
		// phpcs:enable
		foreach ( $rows as $t ) {
			$to   = (int) $t['assignee_id'] ? (int) $t['assignee_id'] : (int) $t['created_by'];
			$late = $t['due_at'] < $now;
			NROS_Notifications::add( array( $to ), 'pd_task', ( $late ? 'সময় পেরিয়ে গেছে: ' : 'এক ঘণ্টার মধ্যে শেষ সময়: ' ) . $t['title'], '', '/app/profile-desk?tab=tasks', (int) $t['id'] );
			$wpdb->update( $tt, array( 'reminded' => $late ? 2 : 1 ), array( 'id' => (int) $t['id'] ) );
		}
	}

	// ---------------------------------------------------------------------
	// Timeline
	// ---------------------------------------------------------------------

	/**
	 * Shapes events.
	 *
	 * @param array $rows Rows.
	 * @return array
	 */
	public static function shape_events( $rows ) {
		$names = NROS_Profile_Desk::names_of( wp_list_pluck( $rows, 'entity_id' ) );
		return array_map(
			function ( $v ) use ( $names ) {
				return array(
					'id'          => (int) $v['id'],
					'entity'      => isset( $names[ (int) $v['entity_id'] ] ) ? $names[ (int) $v['entity_id'] ] : null,
					'kind'        => $v['kind'],
					'happened_on' => $v['happened_on'],
					'title'       => $v['title'],
					'details'     => (string) $v['details'],
					'source_url'  => $v['source_url'],
					'importance'  => (int) $v['importance'],
					'author'      => self::uname( $v['created_by'] ),
					'created_by'  => (int) $v['created_by'],
					'created_at'  => $v['created_at'],
				);
			},
			$rows
		);
	}

	/**
	 * GET /pd/events — entity, kind, from, to, q, page.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function list_events( $request ) {
		global $wpdb;
		$user  = NROS_Profile_Desk::me( $request );
		$tv    = NROS_Profile_Desk::t( 'events' );
		$where = array( self::vis_entity_sql( $user, 'v.entity_id' ) );
		$eid   = (int) $request->get_param( 'entity' );
		if ( $eid ) {
			$where[] = $wpdb->prepare( 'v.entity_id = %d', $eid );
		}
		$kinds = array_values( array_intersect( array_map( 'sanitize_key', explode( ',', (string) $request->get_param( 'kind' ) ) ), array_keys( self::EVENT_KINDS ) ) );
		if ( $kinds ) {
			$where[] = "v.kind IN ('" . implode( "','", $kinds ) . "')";
		}
		foreach ( array( 'from' => '>=', 'to' => '<=' ) as $k => $op ) {
			$d = (string) $request->get_param( $k );
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ) {
				$where[] = $wpdb->prepare( "v.happened_on {$op} %s", $d ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}
		$q = trim( (string) $request->get_param( 'q' ) );
		if ( '' !== $q ) {
			$like    = '%' . $wpdb->esc_like( $q ) . '%';
			$where[] = $wpdb->prepare( '(v.title LIKE %s OR v.details LIKE %s)', $like, $like );
		}
		$per  = 50;
		$page = max( 1, (int) $request->get_param( 'page' ) );
		$off  = ( $page - 1 ) * $per;
		$w    = implode( ' AND ', $where );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tv} v WHERE {$w}" );
		$rows  = $wpdb->get_results( "SELECT v.* FROM {$tv} v WHERE {$w} ORDER BY v.happened_on DESC, v.id DESC LIMIT {$off}, {$per}", ARRAY_A );
		// phpcs:enable
		return new WP_REST_Response(
			array(
				'items' => self::shape_events( $rows ),
				'total' => $total,
				'page'  => $page,
				'pages' => (int) ceil( $total / $per ),
			),
			200
		);
	}

	/**
	 * Event fields.
	 *
	 * @param array $p      Body.
	 * @param bool  $create Creating.
	 * @return array|WP_Error
	 */
	protected static function event_fields( $p, $create ) {
		$out = array();
		if ( $create || array_key_exists( 'kind', $p ) ) {
			$k          = sanitize_key( (string) ( isset( $p['kind'] ) ? $p['kind'] : 'note' ) );
			$out['kind'] = isset( self::EVENT_KINDS[ $k ] ) ? $k : 'note';
		}
		if ( $create || array_key_exists( 'happened_on', $p ) ) {
			$d = trim( (string) ( isset( $p['happened_on'] ) ? $p['happened_on'] : '' ) );
			if ( '' === $d ) {
				$d = current_time( 'Y-m-d' );
			} elseif ( preg_match( '/^(\d{4})(?:-(\d{1,2}))?(?:-(\d{1,2}))?/', $d, $m ) && checkdate( isset( $m[2] ) && '' !== $m[2] ? (int) $m[2] : 1, isset( $m[3] ) ? (int) $m[3] : 1, (int) $m[1] ) ) {
				// "2026", "2026-02" and "2026-02-17" are all fine (missing parts become 01).
				$d = sprintf( '%04d-%02d-%02d', (int) $m[1], isset( $m[2] ) && '' !== $m[2] ? (int) $m[2] : 1, isset( $m[3] ) ? (int) $m[3] : 1 );
			} else {
				return new WP_Error( 'nros_bad_request', 'তারিখ ঠিক নেই (যেমন ২০২৬-০২-১৭)।', array( 'status' => 400 ) );
			}
			$out['happened_on'] = $d;
		}
		if ( $create || array_key_exists( 'title', $p ) ) {
			$out['title'] = mb_substr( trim( sanitize_text_field( (string) ( isset( $p['title'] ) ? $p['title'] : '' ) ) ), 0, 255 );
			if ( '' === $out['title'] ) {
				return new WP_Error( 'nros_bad_request', 'ঘটনার শিরোনাম লিখুন।', array( 'status' => 400 ) );
			}
		}
		if ( array_key_exists( 'details', $p ) ) {
			$out['details'] = mb_substr( sanitize_textarea_field( (string) $p['details'] ), 0, 20000 );
		}
		if ( array_key_exists( 'source_url', $p ) ) {
			$out['source_url'] = substr( esc_url_raw( (string) $p['source_url'], array( 'http', 'https' ) ), 0, 500 );
		}
		if ( array_key_exists( 'importance', $p ) ) {
			$out['importance'] = max( 1, min( 3, (int) $p['importance'] ) );
		}
		return $out;
	}

	/**
	 * POST /pd/events — { entity_id, kind, happened_on, title, details?, source_url?, importance? }.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_event( $request ) {
		global $wpdb;
		$user = NROS_Profile_Desk::me( $request );
		$p    = self::body( $request );
		$eid  = isset( $p['entity_id'] ) ? (int) $p['entity_id'] : 0;
		if ( ! $eid || ! NROS_Profile_Desk::entity_row( $eid, $user ) ) {
			return new WP_Error( 'nros_not_found', 'প্রোফাইল পাওয়া যায়নি।', array( 'status' => 404 ) );
		}
		$f = self::event_fields( $p, true );
		if ( is_wp_error( $f ) ) {
			return $f;
		}
		$now = current_time( 'mysql' );
		$f  += array( 'importance' => 2 );
		$f['entity_id']  = $eid;
		$f['created_by'] = (int) $user->ID;
		$f['created_at'] = $now;
		$f['updated_at'] = $now;
		$wpdb->insert( NROS_Profile_Desk::t( 'events' ), $f );
		$id = (int) $wpdb->insert_id;
		$wpdb->update( NROS_Profile_Desk::t( 'entities' ), array( 'updated_at' => $now ), array( 'id' => $eid ) );
		return new WP_REST_Response( self::event_out( $id ), 201 );
	}

	/**
	 * One event shaped.
	 *
	 * @param int $id Id.
	 * @return array
	 */
	protected static function event_out( $id ) {
		global $wpdb;
		$tv = NROS_Profile_Desk::t( 'events' );
		$s  = self::shape_events( array( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tv} WHERE id = %d", (int) $id ), ARRAY_A ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $s[0];
	}

	/**
	 * An event the viewer may change, or WP_Error.
	 *
	 * @param int     $id   Id.
	 * @param WP_User $user Viewer.
	 * @return array|WP_Error
	 */
	protected static function own_event( $id, $user ) {
		global $wpdb;
		$tv  = NROS_Profile_Desk::t( 'events' );
		$vis = self::vis_entity_sql( $user, 'v.entity_id' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$v = $wpdb->get_row( $wpdb->prepare( "SELECT v.* FROM {$tv} v WHERE v.id = %d AND {$vis}", (int) $id ), ARRAY_A );
		if ( ! $v ) {
			return new WP_Error( 'nros_not_found', 'ঘটনাটি পাওয়া যায়নি।', array( 'status' => 404 ) );
		}
		if ( ! NROS_Profile_Desk::is_boss( $user ) && (int) $v['created_by'] !== (int) $user->ID ) {
			return new WP_Error( 'nros_forbidden', 'শুধু যিনি লিখেছেন বা পাবলিশার বদলাতে পারবেন।', array( 'status' => 403 ) );
		}
		return $v;
	}

	/**
	 * PATCH /pd/events/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_event( $request ) {
		global $wpdb;
		$user = NROS_Profile_Desk::me( $request );
		$v    = self::own_event( (int) $request->get_param( 'id' ), $user );
		if ( is_wp_error( $v ) ) {
			return $v;
		}
		$f = self::event_fields( self::body( $request ), false );
		if ( is_wp_error( $f ) ) {
			return $f;
		}
		if ( $f ) {
			$f['updated_at'] = current_time( 'mysql' );
			$wpdb->update( NROS_Profile_Desk::t( 'events' ), $f, array( 'id' => (int) $v['id'] ) );
		}
		return new WP_REST_Response( self::event_out( (int) $v['id'] ), 200 );
	}

	/**
	 * DELETE /pd/events/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_event( $request ) {
		global $wpdb;
		$user = NROS_Profile_Desk::me( $request );
		$v    = self::own_event( (int) $request->get_param( 'id' ), $user );
		if ( is_wp_error( $v ) ) {
			return $v;
		}
		$wpdb->delete( NROS_Profile_Desk::t( 'events' ), array( 'id' => (int) $v['id'] ) );
		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	// ---------------------------------------------------------------------
	// Relations
	// ---------------------------------------------------------------------

	/**
	 * Relations of one entity, as seen from it.
	 *
	 * @param int     $eid  Entity.
	 * @param WP_User $user Viewer.
	 * @return array
	 */
	public static function relations_of( $eid, $user ) {
		global $wpdb;
		$tr   = NROS_Profile_Desk::t( 'relations' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$tr} WHERE a_id = %d OR b_id = %d ORDER BY kind, id", (int) $eid, (int) $eid ), ARRAY_A );
		$other = array();
		foreach ( $rows as $r ) {
			$other[] = (int) $r['a_id'] === (int) $eid ? (int) $r['b_id'] : (int) $r['a_id'];
		}
		$ok    = self::visible_ids( $other, $user );
		$names = NROS_Profile_Desk::names_of( $ok );
		$out   = array();
		foreach ( $rows as $i => $r ) {
			$o = $other[ $i ];
			if ( ! in_array( $o, $ok, true ) || ! isset( $names[ $o ] ) ) {
				continue;
			}
			$out[] = array(
				'id'    => (int) $r['id'],
				'kind'  => $r['kind'],
				'note'  => $r['note'],
				'other' => $names[ $o ],
			);
		}
		return $out;
	}

	/**
	 * POST /pd/relations — { a_id, b_id, kind, note? }.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_relation( $request ) {
		global $wpdb;
		$user = NROS_Profile_Desk::me( $request );
		$p    = self::body( $request );
		$a    = isset( $p['a_id'] ) ? (int) $p['a_id'] : 0;
		$b    = isset( $p['b_id'] ) ? (int) $p['b_id'] : 0;
		if ( ! $a || ! $b || $a === $b || count( self::visible_ids( array( $a, $b ), $user ) ) !== 2 ) {
			return new WP_Error( 'nros_bad_request', 'দুটো আলাদা প্রোফাইল বেছে নিন।', array( 'status' => 400 ) );
		}
		$kind = sanitize_key( (string) ( isset( $p['kind'] ) ? $p['kind'] : 'other' ) );
		$kind = isset( self::RELATION_KINDS[ $kind ] ) ? $kind : 'other';
		// one row per pair+kind whichever side it was added from
		list( $lo, $hi ) = $a < $b ? array( $a, $b ) : array( $b, $a );
		$tr = NROS_Profile_Desk::t( 'relations' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$tr} WHERE a_id = %d AND b_id = %d AND kind = %s", $lo, $hi, $kind ) ) ) {
			return new WP_Error( 'nros_bad_request', 'এই সম্পর্ক আগেই যোগ করা আছে।', array( 'status' => 409 ) );
		}
		$wpdb->insert(
			$tr,
			array(
				'a_id'       => $lo,
				'b_id'       => $hi,
				'kind'       => $kind,
				'note'       => mb_substr( sanitize_text_field( (string) ( isset( $p['note'] ) ? $p['note'] : '' ) ), 0, 255 ),
				'created_by' => (int) $user->ID,
				'created_at' => current_time( 'mysql' ),
			)
		);
		return new WP_REST_Response( array( 'id' => (int) $wpdb->insert_id, 'relations' => self::relations_of( $a, $user ) ), 201 );
	}

	/**
	 * DELETE /pd/relations/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_relation( $request ) {
		global $wpdb;
		$user = NROS_Profile_Desk::me( $request );
		$tr   = NROS_Profile_Desk::t( 'relations' );
		$r    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tr} WHERE id = %d", (int) $request->get_param( 'id' ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $r || count( self::visible_ids( array( $r['a_id'], $r['b_id'] ), $user ) ) !== 2 ) {
			return new WP_Error( 'nros_not_found', 'সম্পর্কটি পাওয়া যায়নি।', array( 'status' => 404 ) );
		}
		$wpdb->delete( $tr, array( 'id' => (int) $r['id'] ) );
		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/**
	 * Clears an entity's tasks links, events, relations and marks (on delete).
	 *
	 * @param int $id Entity.
	 */
	public static function forget_entity( $id ) {
		global $wpdb;
		$id = (int) $id;
		$wpdb->update( NROS_Profile_Desk::t( 'tasks' ), array( 'entity_id' => 0 ), array( 'entity_id' => $id ) );
		$wpdb->delete( NROS_Profile_Desk::t( 'events' ), array( 'entity_id' => $id ) );
		$wpdb->delete( NROS_Profile_Desk::t( 'relations' ), array( 'a_id' => $id ) );
		$wpdb->delete( NROS_Profile_Desk::t( 'relations' ), array( 'b_id' => $id ) );
		$wpdb->delete( NROS_Profile_Desk::t( 'marks' ), array( 'entity_id' => $id ) );
	}

	/**
	 * Extra parts of GET /pd/entities/{id}.
	 *
	 * @param array   $e     Entity row.
	 * @param int     $nlinks Links.
	 * @param WP_User $user  Viewer.
	 * @return array
	 */
	public static function entity_extras( $e, $nlinks, $user ) {
		global $wpdb;
		$id = (int) $e['id'];
		self::touch_recent( (int) $user->ID, $id );
		$tv = NROS_Profile_Desk::t( 'events' );
		$tt = NROS_Profile_Desk::t( 'tasks' );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$events = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$tv} WHERE entity_id = %d ORDER BY happened_on DESC, id DESC LIMIT 100", $id ), ARRAY_A );
		$tasks  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$tt} WHERE entity_id = %d AND (status = 'open' OR done_at >= %s) ORDER BY status = 'done', due_at IS NULL, due_at ASC LIMIT 50", $id, gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - 14 * DAY_IN_SECONDS ) ), ARRAY_A );
		// phpcs:enable
		return array(
			'pinned'       => in_array( $id, self::pinned_ids( (int) $user->ID ), true ),
			'completeness' => self::completeness( $e, $nlinks ),
			'events'       => self::shape_events( $events ),
			'relations'    => self::relations_of( $id, $user ),
			'tasks'        => self::shape_tasks( $tasks, $user ),
		);
	}

	// ---------------------------------------------------------------------
	// Saved lists
	// ---------------------------------------------------------------------

	/**
	 * Allowed filter keys in a saved list.
	 */
	const VIEW_KEYS = array( 'q', 'kind', 'category', 'country', 'district', 'status', 'tag', 'importance', 'platform', 'alert', 'files', 'group', 'sort' );

	/**
	 * Cleans saved-list params.
	 *
	 * @param mixed $p Params.
	 * @return array
	 */
	protected static function clean_params( $p ) {
		$out = array();
		foreach ( (array) $p as $k => $v ) {
			if ( in_array( $k, self::VIEW_KEYS, true ) && ( is_scalar( $v ) ) && '' !== (string) $v && false !== $v ) {
				$out[ $k ] = is_bool( $v ) ? $v : ( is_int( $v ) ? $v : mb_substr( sanitize_text_field( (string) $v ), 0, 190 ) );
			}
		}
		return $out;
	}

	/**
	 * Shapes a view row.
	 *
	 * @param array   $v    Row.
	 * @param WP_User $user Viewer.
	 * @return array
	 */
	protected static function view_out( $v, $user ) {
		$p = json_decode( (string) $v['params'], true );
		return array(
			'id'       => (int) $v['id'],
			'name'     => $v['name'],
			'params'   => is_array( $p ) ? $p : array(),
			'shared'   => (bool) $v['shared'],
			'owner'    => self::uname( $v['owner_id'] ),
			'can_edit' => NROS_Profile_Desk::is_boss( $user ) || (int) $v['owner_id'] === (int) $user->ID,
		);
	}

	/**
	 * GET /pd/views — mine + shared.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function list_views( $request ) {
		global $wpdb;
		$user = NROS_Profile_Desk::me( $request );
		$tv   = NROS_Profile_Desk::t( 'views' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$tv} WHERE owner_id = %d OR shared = 1 ORDER BY name ASC LIMIT 200", (int) $user->ID ), ARRAY_A );
		return new WP_REST_Response(
			array_map(
				function ( $v ) use ( $user ) {
					return self::view_out( $v, $user );
				},
				$rows
			),
			200
		);
	}

	/**
	 * POST /pd/views — { name, params, shared? }.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_view( $request ) {
		global $wpdb;
		$user = NROS_Profile_Desk::me( $request );
		$p    = self::body( $request );
		$name = mb_substr( trim( sanitize_text_field( (string) ( isset( $p['name'] ) ? $p['name'] : '' ) ) ), 0, 120 );
		if ( '' === $name ) {
			return new WP_Error( 'nros_bad_request', 'তালিকার একটা নাম দিন।', array( 'status' => 400 ) );
		}
		$wpdb->insert(
			NROS_Profile_Desk::t( 'views' ),
			array(
				'name'       => $name,
				'params'     => wp_json_encode( self::clean_params( isset( $p['params'] ) ? $p['params'] : array() ) ),
				'owner_id'   => (int) $user->ID,
				'shared'     => ! empty( $p['shared'] ) ? 1 : 0,
				'created_at' => current_time( 'mysql' ),
			)
		);
		$tv = NROS_Profile_Desk::t( 'views' );
		$v  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tv} WHERE id = %d", (int) $wpdb->insert_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return new WP_REST_Response( self::view_out( $v, $user ), 201 );
	}

	/**
	 * A view the viewer may change.
	 *
	 * @param int     $id   Id.
	 * @param WP_User $user User.
	 * @return array|WP_Error
	 */
	protected static function own_view( $id, $user ) {
		global $wpdb;
		$tv = NROS_Profile_Desk::t( 'views' );
		$v  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tv} WHERE id = %d", (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $v || ( ! $v['shared'] && (int) $v['owner_id'] !== (int) $user->ID ) ) {
			return new WP_Error( 'nros_not_found', 'তালিকাটি পাওয়া যায়নি।', array( 'status' => 404 ) );
		}
		if ( ! NROS_Profile_Desk::is_boss( $user ) && (int) $v['owner_id'] !== (int) $user->ID ) {
			return new WP_Error( 'nros_forbidden', 'শুধু যিনি বানিয়েছেন তিনি বদলাতে পারবেন।', array( 'status' => 403 ) );
		}
		return $v;
	}

	/**
	 * PATCH /pd/views/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_view( $request ) {
		global $wpdb;
		$user = NROS_Profile_Desk::me( $request );
		$v    = self::own_view( (int) $request->get_param( 'id' ), $user );
		if ( is_wp_error( $v ) ) {
			return $v;
		}
		$p   = self::body( $request );
		$upd = array();
		if ( isset( $p['name'] ) && '' !== trim( (string) $p['name'] ) ) {
			$upd['name'] = mb_substr( sanitize_text_field( (string) $p['name'] ), 0, 120 );
		}
		if ( isset( $p['params'] ) ) {
			$upd['params'] = wp_json_encode( self::clean_params( $p['params'] ) );
		}
		if ( array_key_exists( 'shared', $p ) ) {
			$upd['shared'] = $p['shared'] ? 1 : 0;
		}
		if ( $upd ) {
			$wpdb->update( NROS_Profile_Desk::t( 'views' ), $upd, array( 'id' => (int) $v['id'] ) );
		}
		$v = self::own_view( (int) $v['id'], $user );
		return new WP_REST_Response( self::view_out( $v, $user ), 200 );
	}

	/**
	 * DELETE /pd/views/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_view( $request ) {
		global $wpdb;
		$user = NROS_Profile_Desk::me( $request );
		$v    = self::own_view( (int) $request->get_param( 'id' ), $user );
		if ( is_wp_error( $v ) ) {
			return $v;
		}
		$wpdb->delete( NROS_Profile_Desk::t( 'views' ), array( 'id' => (int) $v['id'] ) );
		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	// ---------------------------------------------------------------------
	// Import / export
	// ---------------------------------------------------------------------

	/**
	 * POST /pd/import — { rows: [{name_bn, name_en, category, title, org, district,
	 * country, tags, importance, phone, email, bio, links}], dry_run, skip_duplicates }.
	 * "org" is a name: it is matched to an existing organisation, or created.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function import( $request ) {
		global $wpdb;
		$user = NROS_Profile_Desk::me( $request );
		$p    = self::body( $request );
		$rows = isset( $p['rows'] ) && is_array( $p['rows'] ) ? array_values( $p['rows'] ) : array();
		if ( ! $rows ) {
			return new WP_Error( 'nros_bad_request', 'কোনো সারি পাওয়া যায়নি।', array( 'status' => 400 ) );
		}
		if ( count( $rows ) > self::MAX_IMPORT ) {
			return new WP_Error( 'nros_bad_request', 'একবারে সর্বোচ্চ ' . NROS_Core::bn_digits( self::MAX_IMPORT ) . 'টি সারি।', array( 'status' => 400 ) );
		}
		$dry   = ! empty( $p['dry_run'] );
		$skip  = ! isset( $p['skip_duplicates'] ) || ! empty( $p['skip_duplicates'] );
		$cats  = NROS_Profile_Desk::CATEGORIES;
		$label = array();
		foreach ( $cats as $k => $c ) {
			$label[ NROS_Profile_Desk::norm( $c[1] ) ] = $k;
		}
		$out     = array();
		$created = 0;
		$seen    = array();
		$orgs    = array();
		foreach ( $rows as $i => $r ) {
			$r = is_array( $r ) ? $r : array();
			foreach ( array( 'tags' => ',', 'aliases' => ', ' ) as $ak => $glue ) {
				if ( isset( $r[ $ak ] ) && is_array( $r[ $ak ] ) ) {
					$r[ $ak ] = implode( $glue, array_map( 'strval', array_filter( $r[ $ak ], 'is_scalar' ) ) );
				}
			}
			$g  = function ( $k ) use ( $r ) {
				return isset( $r[ $k ] ) && is_scalar( $r[ $k ] ) ? trim( (string) $r[ $k ] ) : '';
			};
			$bn = mb_substr( sanitize_text_field( $g( 'name_bn' ) ), 0, 190 );
			$en = mb_substr( sanitize_text_field( $g( 'name_en' ) ), 0, 190 );
			$row = array( 'row' => $i + 1, 'name' => '' !== $bn ? $bn : $en );
			if ( '' === $bn && '' === $en ) {
				$out[] = $row + array( 'status' => 'error', 'message' => 'নাম নেই' );
				continue;
			}
			// category: key or Bangla label
			$cat = self::guess_category( $g( 'category' ), $g( 'kind' ), $label );
			$key = NROS_Profile_Desk::norm( $bn . '|' . $en );
			if ( isset( $seen[ $key ] ) ) {
				$out[] = $row + array( 'status' => 'duplicate', 'message' => 'এই ফাইলেই আগে আছে (সারি ' . NROS_Core::bn_digits( $seen[ $key ] ) . ')' );
				continue;
			}
			$seen[ $key ] = $i + 1;
			$near    = NROS_Profile_Desk::similar( $bn, $en, $user );
			$similar = self::same_name( $near, $bn, $en );
			$warn    = array_values( array_filter( $near, function ( $h ) use ( $similar ) {
				return ! in_array( (int) $h['id'], wp_list_pluck( $similar, 'id' ), true );
			} ) );
			if ( $similar && $skip && empty( $r['force'] ) ) {
				if ( 'org' === $cats[ $cat ][0] && ! $dry ) {
					// people further down that name this organisation link to the one already on the desk
					foreach ( array( $bn, $en ) as $nm ) {
						if ( '' !== $nm ) {
							$orgs[ NROS_Profile_Desk::norm( $nm ) ] = (int) $similar[0]['id'];
						}
					}
				}
				$out[] = $row + array( 'status' => 'duplicate', 'message' => 'ডেস্কে আগে থেকেই আছে', 'similar' => $similar );
				continue;
			}
			$links = isset( $r['links'] ) && is_array( $r['links'] )
				? array_values( array_filter( array_map( function ( $l ) {
					return is_array( $l ) ? ( isset( $l['url'] ) ? trim( (string) $l['url'] ) : '' ) : trim( (string) $l );
				}, $r['links'] ) ) )
				: array_values( array_filter( preg_split( '/[\s,;]+/', $g( 'links' ) ) ) );
			$events = isset( $r['events'] ) && is_array( $r['events'] ) ? array_slice( array_values( $r['events'] ), 0, 50 ) : array();
			if ( $dry ) {
				$out[] = $row + array( 'status' => 'new', 'category' => $cat, 'links' => count( $links ), 'events' => count( $events ), 'similar' => $warn );
				continue;
			}
			$org_id  = 0;
			$orgname = mb_substr( sanitize_text_field( $g( 'org' ) ), 0, 190 );
			if ( '' !== $orgname && 'person' === $cats[ $cat ][0] ) {
				$ok = NROS_Profile_Desk::norm( $orgname );
				if ( ! isset( $orgs[ $ok ] ) ) {
					$hit = NROS_Profile_Desk::similar( $orgname, $orgname, $user );
					$te  = NROS_Profile_Desk::t( 'entities' );
					$oid = 0;
					foreach ( $hit as $h ) {
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						if ( 'org' === $wpdb->get_var( $wpdb->prepare( "SELECT kind FROM {$te} WHERE id = %d", $h['id'] ) ) ) {
							$oid = (int) $h['id'];
							break;
						}
					}
					if ( ! $oid ) {
						$now = current_time( 'mysql' );
						$wpdb->insert( $te, array( 'kind' => 'org', 'category' => isset( $cats[ sanitize_key( $g( 'org_category' ) ) ] ) && 'org' === $cats[ sanitize_key( $g( 'org_category' ) ) ][0] ? sanitize_key( $g( 'org_category' ) ) : 'org', 'name_bn' => $orgname, 'name_en' => '', 'country' => 'বাংলাদেশ', 'importance' => 3, 'status' => 'active', 'sensitivity' => 'normal', 'created_by' => (int) $user->ID, 'updated_by' => (int) $user->ID, 'created_at' => $now, 'updated_at' => $now ) );
						$oid = (int) $wpdb->insert_id;
						NROS_Profile_Desk::reindex( $oid );
					}
					$orgs[ $ok ] = $oid;
				}
				$org_id = $orgs[ $ok ];
			}
			$now    = current_time( 'mysql' );
			$email  = sanitize_email( $g( 'email' ) );
			$fields = array(
				'kind'        => $cats[ $cat ][0],
				'category'    => $cat,
				'name_bn'     => $bn,
				'name_en'     => $en,
				'aliases'     => mb_substr( sanitize_text_field( $g( 'aliases' ) ), 0, 500 ),
				'title'       => mb_substr( sanitize_text_field( $g( 'title' ) ), 0, 255 ),
				'org_id'      => $org_id,
				'country'     => '' !== $g( 'country' ) ? mb_substr( sanitize_text_field( $g( 'country' ) ), 0, 80 ) : 'বাংলাদেশ',
				'district'    => mb_substr( sanitize_text_field( $g( 'district' ) ), 0, 100 ),
				'upazila'     => mb_substr( sanitize_text_field( $g( 'upazila' ) ), 0, 100 ),
				'tags'        => implode( ',', NROS_Profile_Desk::clean_tags( $g( 'tags' ) ) ),
				'importance'  => max( 1, min( 5, (int) ( $g( 'importance' ) ?: 3 ) ) ),
				'status'      => self::guess_status( $g( 'status' ) ),
				'sensitivity' => 'restricted' === $g( 'sensitivity' ) ? 'restricted' : 'normal',
				'phone'       => mb_substr( sanitize_text_field( $g( 'phone' ) ), 0, 120 ),
				'email'       => is_email( $email ) ? $email : '',
				'bio'         => mb_substr( sanitize_textarea_field( $g( 'bio' ) ), 0, 20000 ),
				'created_by'  => (int) $user->ID,
				'updated_by'  => (int) $user->ID,
				'created_at'  => $now,
				'updated_at'  => $now,
			);
			if ( ! $wpdb->insert( NROS_Profile_Desk::t( 'entities' ), $fields ) ) {
				$out[] = $row + array( 'status' => 'error', 'message' => 'সংরক্ষণ হয়নি' );
				continue;
			}
			$id = (int) $wpdb->insert_id;
			foreach ( $links as $j => $u ) {
				NROS_Profile_Desk::insert_link( $id, array( 'url' => $u ), $j );
			}
			foreach ( $events as $ev ) {
				if ( ! is_array( $ev ) || empty( $ev['title'] ) ) {
					continue;
				}
				$f = self::event_fields(
					array(
						'kind'        => isset( $ev['kind'] ) ? $ev['kind'] : 'other',
						'happened_on' => isset( $ev['date'] ) ? $ev['date'] : ( isset( $ev['happened_on'] ) ? $ev['happened_on'] : '' ),
						'title'       => $ev['title'],
						'details'     => isset( $ev['details'] ) ? $ev['details'] : '',
						'source_url'  => isset( $ev['source_url'] ) ? $ev['source_url'] : '',
					),
					true
				);
				if ( ! is_wp_error( $f ) ) {
					$wpdb->insert( NROS_Profile_Desk::t( 'events' ), $f + array( 'importance' => 2, 'entity_id' => $id, 'created_by' => (int) $user->ID, 'created_at' => $now, 'updated_at' => $now ) );
				}
			}
			NROS_Profile_Desk::reindex( $id );
			if ( 'org' === $cats[ $cat ][0] ) {
				foreach ( array( $bn, $en ) as $nm ) {
					if ( '' !== $nm ) {
						$orgs[ NROS_Profile_Desk::norm( $nm ) ] = $id;
					}
				}
			}
			++$created;
			$out[] = $row + array( 'status' => 'created', 'id' => $id, 'similar' => $warn );
		}
		if ( ! $dry && $created ) {
			NROS_Profile_Desk::audit( $user->ID, 'import', 'entity', 0, NROS_Core::bn_digits( $created ) . 'টি প্রোফাইল' );
		}
		return new WP_REST_Response( array( 'dry_run' => $dry, 'created' => $created, 'rows' => $out ), 200 );
	}

	/**
	 * Of the near matches, those whose name is really the same: equal after
	 * normalising and dropping a bracketed part ("জাতীয় নাগরিক পার্টি (এনসিপি)").
	 * Near-but-different names ("অধিকার" vs "গণ অধিকার পরিষদ") are only warnings.
	 *
	 * @param array  $near Hits from similar().
	 * @param string $bn   Bangla name.
	 * @param string $en   English name.
	 * @return array
	 */
	protected static function same_name( $near, $bn, $en ) {
		global $wpdb;
		if ( ! $near ) {
			return array();
		}
		$core = function ( $n ) {
			return NROS_Profile_Desk::norm( preg_replace( '/[(\[（][^)\]）]*[)\]）]/u', ' ', (string) $n ) );
		};
		$mine = array_filter( array( $core( $bn ), $core( $en ) ) );
		$te   = NROS_Profile_Desk::t( 'entities' );
		$out  = array();
		foreach ( $near as $h ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$e = $wpdb->get_row( $wpdb->prepare( "SELECT name_bn, name_en, aliases FROM {$te} WHERE id = %d", (int) $h['id'] ), ARRAY_A );
			if ( ! $e ) {
				continue;
			}
			$theirs = array_merge( array( $core( $e['name_bn'] ), $core( $e['name_en'] ) ), array_map( $core, NROS_Profile_Desk::clean_tags( (string) $e['aliases'] ) ) );
			if ( array_intersect( $mine, array_filter( $theirs ) ) ) {
				$out[] = $h;
			}
		}
		return $out;
	}

	/**
	 * Category from a key, a Bangla label or a common word ("দল", "police", "মন্ত্রী"…).
	 *
	 * @param string $v     Raw category.
	 * @param string $kind  Raw kind hint (person/org).
	 * @param array  $label Normalised label => key.
	 * @return string
	 */
	protected static function guess_category( $v, $kind, $label ) {
		$cats = NROS_Profile_Desk::CATEGORIES;
		$k    = sanitize_key( str_replace( array( ' ', '-' ), '_', strtolower( trim( $v ) ) ) );
		if ( isset( $cats[ $k ] ) ) {
			return $k;
		}
		$n = NROS_Profile_Desk::norm( $v );
		if ( '' !== $n && isset( $label[ $n ] ) ) {
			return $label[ $n ];
		}
		$words = array(
			'party'       => array( 'দল', 'party', 'political party' ),
			'media'       => array( 'সংবাদ', 'পত্রিকা', 'টিভি', 'চ্যানেল', 'মিডিয়া', 'media', 'newspaper', 'tv', 'news' ),
			'law_foreign' => array( 'বিদেশি', 'foreign', 'fbi', 'interpol' ),
			'law_bd'      => array( 'পুলিশ', 'র‍্যাব', 'র্যাব', 'বাহিনী', 'police', 'rab', 'army', 'force', 'law enforcement', 'আইনপ্রয়োগ' ),
			'govt'        => array( 'মন্ত্রণালয়', 'সরকারি', 'দপ্তর', 'কমিশন', 'ministry', 'government', 'govt', 'commission', 'department' ),
			'ngo'         => array( 'এনজিও', 'সংগঠন', 'ngo', 'foundation', 'trust' ),
			'activist'    => array( 'অ্যাক্টিভিস্ট', 'এক্টিভিস্ট', 'activist', 'youtuber', 'ইউটিউবার', 'influencer' ),
			'celebrity'   => array( 'সেলিব্রেটি', 'সেলেব্রিটি', 'অভিনেতা', 'অভিনেত্রী', 'শিল্পী', 'খেলোয়াড়', 'ক্রিকেটার', 'celebrity', 'actor', 'actress', 'singer', 'cricketer', 'player' ),
			'journalist'  => array( 'সাংবাদিক', 'সম্পাদক', 'journalist', 'editor', 'reporter' ),
			'official'    => array( 'কর্মকর্তা', 'সচিব', 'official', 'secretary', 'officer', 'bureaucrat' ),
			'politician'  => array( 'রাজনীতি', 'মন্ত্রী', 'এমপি', 'সংসদ', 'নেতা', 'politician', 'minister', 'mp', 'leader', 'adviser', 'উপদেষ্টা' ),
		);
		$low = mb_strtolower( trim( $v ) );
		if ( '' !== $low ) {
			foreach ( $words as $key => $list ) {
				foreach ( $list as $w ) {
					if ( false !== mb_strpos( $low, mb_strtolower( $w ) ) ) {
						return $key;
					}
				}
			}
		}
		$kd = strtolower( trim( $kind ) );
		return in_array( $kd, array( 'org', 'organisation', 'organization', 'সংস্থা' ), true ) ? 'org' : 'person';
	}

	/**
	 * Status from a key or a Bangla word.
	 *
	 * @param string $v Raw status.
	 * @return string
	 */
	protected static function guess_status( $v ) {
		$st = NROS_Profile_Desk::STATUSES;
		$k  = sanitize_key( $v );
		if ( isset( $st[ $k ] ) ) {
			return $k;
		}
		$v = trim( $v );
		foreach ( $st as $key => $lbl ) {
			if ( '' !== $v && NROS_Profile_Desk::norm( $lbl ) === NROS_Profile_Desk::norm( $v ) ) {
				return $key;
			}
		}
		$map = array( 'গ্রেপ্তার' => 'arrested', 'কারাগারে' => 'arrested', 'প্রবাসে' => 'abroad', 'নির্বাসনে' => 'abroad', 'exile' => 'abroad', 'jail' => 'arrested', 'dead' => 'deceased', 'প্রয়াত' => 'deceased' );
		foreach ( $map as $w => $key ) {
			if ( '' !== $v && false !== mb_strpos( mb_strtolower( $v ), $w ) ) {
				return $key;
			}
		}
		return 'active';
	}

	/**
	 * GET /pd/export — CSV of every profile (no private notes). Publisher only.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function export( $request ) {
		global $wpdb;
		$user = NROS_Profile_Desk::me( $request );
		$te   = NROS_Profile_Desk::t( 'entities' );
		$tl   = NROS_Profile_Desk::t( 'links' );
		$rows = $wpdb->get_results( "SELECT * FROM {$te} ORDER BY kind, name_bn LIMIT 20000", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$orgs = NROS_Profile_Desk::names_of( wp_list_pluck( $rows, 'org_id' ) );
		$lk   = array();
		foreach ( $wpdb->get_results( "SELECT entity_id, url FROM {$tl} ORDER BY sort_order, id", ARRAY_A ) as $l ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$lk[ (int) $l['entity_id'] ][] = $l['url'];
		}
		if ( 'json' === $request->get_param( 'format' ) ) {
			$tv  = NROS_Profile_Desk::t( 'events' );
			$evs = array();
			foreach ( $wpdb->get_results( "SELECT * FROM {$tv} ORDER BY happened_on", ARRAY_A ) as $v ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$evs[ (int) $v['entity_id'] ][] = array( 'date' => $v['happened_on'], 'kind' => $v['kind'], 'title' => $v['title'], 'details' => (string) $v['details'], 'source_url' => $v['source_url'] );
			}
			$list = array();
			foreach ( $rows as $r ) {
				$list[] = array(
					'name_bn'    => $r['name_bn'],
					'name_en'    => $r['name_en'],
					'aliases'    => (string) $r['aliases'],
					'category'   => $r['category'],
					'title'      => $r['title'],
					'org'        => isset( $orgs[ (int) $r['org_id'] ] ) ? $orgs[ (int) $r['org_id'] ]['name'] : '',
					'country'    => $r['country'],
					'district'   => $r['district'],
					'upazila'    => $r['upazila'],
					'tags'       => (string) $r['tags'],
					'importance' => (int) $r['importance'],
					'status'     => $r['status'],
					'sensitivity' => $r['sensitivity'],
					'phone'      => $r['phone'],
					'email'      => $r['email'],
					'bio'        => (string) $r['bio'],
					'links'      => isset( $lk[ (int) $r['id'] ] ) ? $lk[ (int) $r['id'] ] : array(),
					'events'     => isset( $evs[ (int) $r['id'] ] ) ? $evs[ (int) $r['id'] ] : array(),
				);
			}
			NROS_Profile_Desk::audit( $user->ID, 'export', 'entity', 0, NROS_Core::bn_digits( count( $rows ) ) . 'টি প্রোফাইল (JSON)' );
			return new WP_REST_Response(
				array(
					'json'     => wp_json_encode( array( 'format' => 'meeha-profile-desk', 'version' => 1, 'exported_at' => current_time( 'c' ), 'rows' => $list ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ),
					'count'    => count( $rows ),
					'filename' => 'profile-desk-' . current_time( 'Y-m-d' ) . '.json',
				),
				200
			);
		}
		$cols = array( 'id', 'name_bn', 'name_en', 'aliases', 'category', 'title', 'org', 'country', 'district', 'upazila', 'tags', 'importance', 'status', 'sensitivity', 'phone', 'email', 'links', 'bio', 'updated_at' );
		$fh   = fopen( 'php://temp', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fputcsv( $fh, $cols );
		foreach ( $rows as $r ) {
			$r['org']   = isset( $orgs[ (int) $r['org_id'] ] ) ? $orgs[ (int) $r['org_id'] ]['name'] : '';
			$r['links'] = isset( $lk[ (int) $r['id'] ] ) ? implode( ' ', $lk[ (int) $r['id'] ] ) : '';
			$line       = array();
			foreach ( $cols as $c ) {
				$v = isset( $r[ $c ] ) ? (string) $r[ $c ] : '';
				// a cell starting with = + - @ would run as a formula in Excel
				$line[] = preg_match( '/^[=+\-@]/', $v ) ? "'" . $v : $v;
			}
			fputcsv( $fh, $line );
		}
		rewind( $fh );
		$csv = stream_get_contents( $fh );
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		NROS_Profile_Desk::audit( $user->ID, 'export', 'entity', 0, NROS_Core::bn_digits( count( $rows ) ) . 'টি প্রোফাইল' );
		return new WP_REST_Response( array( 'csv' => "\xEF\xBB\xBF" . $csv, 'count' => count( $rows ), 'filename' => 'profile-desk-' . current_time( 'Y-m-d' ) . '.csv' ), 200 );
	}

	// ---------------------------------------------------------------------
	// Coverage: Meeha's own news and quotes naming a profile
	// ---------------------------------------------------------------------

	/**
	 * GET /pd/entities/{id}/coverage.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function coverage( $request ) {
		$user = NROS_Profile_Desk::me( $request );
		$e    = NROS_Profile_Desk::entity_row( (int) $request->get_param( 'id' ), $user );
		if ( ! $e ) {
			return new WP_Error( 'nros_not_found', 'প্রোফাইল পাওয়া যায়নি।', array( 'status' => 404 ) );
		}
		$names = array_filter( array_unique( array_merge( array( trim( (string) $e['name_bn'] ), trim( (string) $e['name_en'] ) ), NROS_Profile_Desk::clean_tags( (string) $e['aliases'] ) ) ), function ( $n ) {
			return mb_strlen( $n ) >= 4;
		} );
		$names = array_slice( array_values( $names ), 0, 4 );
		$posts = array();
		foreach ( $names as $n ) {
			$q = new WP_Query(
				array(
					'post_type'           => 'post',
					'post_status'         => 'publish',
					's'                   => '"' . $n . '"',
					'posts_per_page'      => 10,
					'no_found_rows'       => true,
					'ignore_sticky_posts' => true,
					'orderby'             => 'date',
					'order'               => 'DESC',
				)
			);
			foreach ( $q->posts as $p ) {
				$posts[ $p->ID ] = array(
					'id'    => (int) $p->ID,
					'title' => html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ),
					'url'   => get_permalink( $p ),
					'date'  => get_the_date( 'Y-m-d', $p ),
				);
			}
		}
		uasort(
			$posts,
			function ( $a, $b ) {
				return strcmp( $b['date'], $a['date'] );
			}
		);
		$quotes = array();
		if ( class_exists( 'NROS_Quotes' ) && $names ) {
			$meta = array( 'relation' => 'OR' );
			foreach ( $names as $n ) {
				$meta[] = array( 'key' => '_meeha_quote_speaker', 'value' => $n, 'compare' => 'LIKE' );
			}
			$q = new WP_Query(
				array(
					'post_type'      => NROS_Quotes::POST_TYPE,
					'post_status'    => 'publish',
					'posts_per_page' => 10,
					'no_found_rows'  => true,
					'meta_query'     => $meta, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				)
			);
			foreach ( $q->posts as $p ) {
				$quotes[] = array(
					'id'   => (int) $p->ID,
					'text' => html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ),
					'url'  => get_permalink( $p ),
					'date' => get_the_date( 'Y-m-d', $p ),
				);
			}
		}
		return new WP_REST_Response(
			array(
				'names'  => $names,
				'posts'  => array_slice( array_values( $posts ), 0, 15 ),
				'quotes' => $quotes,
			),
			200
		);
	}
}
