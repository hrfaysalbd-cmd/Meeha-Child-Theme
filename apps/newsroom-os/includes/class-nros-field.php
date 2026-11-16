<?php
/**
 * NROS_Field — মাঠের ডেস্ক (r44, newsroom-os 1.27.0).
 *
 * Built from what local journalists in Bangladesh say they lack (see
 * DEPLOY-NOTES-r44): most upazila correspondents are not paid, a field
 * trip costs them money out of pocket, attacks and threats are common,
 * and the right-to-information law is little used because its steps and
 * dates are hard to follow. Four parts:
 *
 *  1. কাজের বোর্ড (assignments) — the desk posts a paid job for an area
 *     (fee + travel money, deadline, how many people). Correspondents of
 *     that district get a phone notice, take it, send their story; when
 *     the desk accepts, the money goes straight into their Meeha earnings
 *     (ledger rows `assignment` and `travel`, one each per claim).
 *  2. নিরাপত্তা — SOS (one tap: the whole desk gets an urgent notice with
 *     the place) and চেক-ইন (“going somewhere risky, back by …”; if the
 *     person does not mark themself safe in time, the desk is alerted).
 *  3. তথ্য অধিকার সহকারী — keeps a correspondent's RTI requests with
 *     the dates that matter and reminds them when to appeal.
 *  4. কভারেজ — for the desk: correspondents per district and upazila,
 *     so empty areas show and can be filled.
 *
 * @package NewsroomOS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NROS_Field {

	const CRON  = 'nros_field_tick';
	const DAILY = 'nros_field_daily';

	/** Check-in lengths a person may choose (hours). */
	const CHECKIN_HOURS = array( 1, 2, 3, 4, 6, 8, 12 );

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval
		add_action( self::CRON, array( __CLASS__, 'tick' ) );
		add_action( self::DAILY, array( __CLASS__, 'daily' ) );
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 120, 'nros_5min', self::CRON );
		}
		if ( ! wp_next_scheduled( self::DAILY ) ) {
			wp_schedule_event( strtotime( 'tomorrow 09:00' ) - (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ), 'daily', self::DAILY );
		}
	}

	public static function schedules( $s ) {
		$s['nros_5min'] = array( 'interval' => 5 * MINUTE_IN_SECONDS, 'display' => 'Every 5 minutes (Newsroom OS)' );
		return $s;
	}

	// ------------------------------------------------------------------
	// tables
	// ------------------------------------------------------------------

	public static function t( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'nros_' . $name;
	}

	public static function schema( $c ) {
		$a  = self::t( 'assign' );
		$cl = self::t( 'assign_claims' );
		$s  = self::t( 'safety' );
		$r  = self::t( 'rti' );
		return array(
			"CREATE TABLE {$a} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			title VARCHAR(200) NOT NULL,
			brief TEXT,
			district VARCHAR(80) NOT NULL DEFAULT '',
			upazila VARCHAR(80) NOT NULL DEFAULT '',
			fee DECIMAL(10,2) NOT NULL DEFAULT 0,
			travel DECIMAL(10,2) NOT NULL DEFAULT 0,
			slots SMALLINT UNSIGNED NOT NULL DEFAULT 1,
			kind VARCHAR(20) NOT NULL DEFAULT 'news',
			urgent TINYINT(1) NOT NULL DEFAULT 0,
			deadline DATETIME DEFAULT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'open',
			created_by BIGINT UNSIGNED NOT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY district (district)
		) {$c};",
			"CREATE TABLE {$cl} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			assign_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'taken',
			note TEXT,
			post_id BIGINT UNSIGNED DEFAULT NULL,
			link VARCHAR(500) NOT NULL DEFAULT '',
			desk_note TEXT,
			paid DECIMAL(10,2) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			submitted_at DATETIME DEFAULT NULL,
			decided_at DATETIME DEFAULT NULL,
			decided_by BIGINT UNSIGNED DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY one_each (assign_id, user_id),
			KEY user_id (user_id),
			KEY status (status)
		) {$c};",
			"CREATE TABLE {$s} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			kind VARCHAR(10) NOT NULL DEFAULT 'checkin',
			status VARCHAR(12) NOT NULL DEFAULT 'active',
			place VARCHAR(200) NOT NULL DEFAULT '',
			note TEXT,
			lat DECIMAL(9,6) DEFAULT NULL,
			lng DECIMAL(9,6) DEFAULT NULL,
			due_at DATETIME DEFAULT NULL,
			created_at DATETIME NOT NULL,
			closed_at DATETIME DEFAULT NULL,
			closed_by BIGINT UNSIGNED DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY user_status (user_id, status),
			KEY status_due (status, due_at)
		) {$c};",
			"CREATE TABLE {$r} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			office VARCHAR(200) NOT NULL DEFAULT '',
			officer VARCHAR(200) NOT NULL DEFAULT '',
			address VARCHAR(300) NOT NULL DEFAULT '',
			subject VARCHAR(250) NOT NULL DEFAULT '',
			info TEXT,
			method VARCHAR(30) NOT NULL DEFAULT 'copy',
			status VARCHAR(16) NOT NULL DEFAULT 'draft',
			sent_on DATE DEFAULT NULL,
			answered_on DATE DEFAULT NULL,
			appeal_on DATE DEFAULT NULL,
			complaint_on DATE DEFAULT NULL,
			result TEXT,
			reminded VARCHAR(40) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY status (status)
		) {$c};",
		);
	}

	// ------------------------------------------------------------------
	// routes
	// ------------------------------------------------------------------

	public static function routes() {
		$cor   = array( 'NROS_Core', 'require_correspondent' );
		$staff = array( 'NROS_Core', 'require_staff' );
		$r     = function ( $path, $method, $cb, $perm ) {
			register_rest_route(
				NROS_API_NS,
				$path,
				array(
					'methods'             => $method,
					'callback'            => array( __CLASS__, $cb ),
					'permission_callback' => $perm,
				)
			);
		};
		// correspondent
		$r( '/field', 'GET', 'my_board', $cor );
		$r( '/field/assignments/(?P<id>\d+)/take', 'POST', 'take', $cor );
		$r( '/field/claims/(?P<id>\d+)/submit', 'POST', 'submit', $cor );
		$r( '/field/claims/(?P<id>\d+)/drop', 'POST', 'drop', $cor );
		$r( '/field/sos', 'POST', 'sos', $cor );
		$r( '/field/checkin', 'POST', 'checkin', $cor );
		$r( '/field/safety/(?P<id>\d+)/safe', 'POST', 'mark_safe', $cor );
		$r( '/field/safety/(?P<id>\d+)/extend', 'POST', 'extend', $cor );
		$r( '/field/rti', 'GET', 'rti_list', $cor );
		$r( '/field/rti', 'POST', 'rti_save', $cor );
		$r( '/field/rti/(?P<id>\d+)', 'POST', 'rti_save', $cor );
		$r( '/field/rti/(?P<id>\d+)', 'DELETE', 'rti_delete', $cor );
		// desk
		$r( '/admin/field', 'GET', 'desk', $staff );
		$r( '/admin/field/assignments', 'POST', 'assign_save', $staff );
		$r( '/admin/field/assignments/(?P<id>\d+)', 'POST', 'assign_save', $staff );
		$r( '/admin/field/claims/(?P<id>\d+)/decide', 'POST', 'decide', $staff );
		$r( '/admin/field/safety/(?P<id>\d+)/resolve', 'POST', 'resolve', $staff );
		$r( '/admin/field/coverage', 'GET', 'coverage', $staff );
	}

	protected static function me( $request ) {
		$u = NROS_Auth::get_user_from_request( $request );
		return ( $u && ! is_wp_error( $u ) ) ? $u : null;
	}

	protected static function err( $msg, $status = 400, $code = 'nros_bad_request' ) {
		return new WP_Error( $code, $msg, array( 'status' => $status ) );
	}

	protected static function now() {
		return current_time( 'mysql' );
	}

	/** A short text param, cleaned and cut to a length. */
	protected static function txt( $request, $key, $len = 200, $multi = false ) {
		$v = (string) $request->get_param( $key );
		$v = $multi ? sanitize_textarea_field( $v ) : sanitize_text_field( $v );
		return mb_substr( $v, 0, $len );
	}

	/** Person's area as saved on the profile. */
	public static function area_of( $uid ) {
		return array(
			'district' => (string) get_user_meta( $uid, 'meeha_district', true ),
			'upazila'  => (string) get_user_meta( $uid, 'meeha_upazila', true ),
		);
	}

	protected static function phone_of( $uid ) {
		foreach ( array( 'meeha_phone', 'phone', 'billing_phone' ) as $k ) {
			$p = (string) get_user_meta( $uid, $k, true );
			if ( '' !== $p ) {
				return $p;
			}
		}
		return '';
	}

	/**
	 * In-app notice + phone notice. `$urgent` notices ignore the person's
	 * “mute inbox” choice (an SOS must never be silenced by a setting).
	 */
	protected static function notify( $uids, $type, $title, $body, $link, $urgent = false ) {
		global $wpdb;
		$uids = array_values( array_unique( array_filter( array_map( 'intval', (array) $uids ) ) ) );
		if ( ! $uids ) {
			return;
		}
		if ( ! $urgent ) {
			NROS_Notifications::add( $uids, $type, $title, $body, $link );
			return;
		}
		$now = self::now();
		foreach ( $uids as $uid ) {
			$wpdb->insert(
				NROS_Notifications::table(),
				array(
					'user_id'    => $uid,
					'type'       => $type,
					'title'      => mb_substr( wp_strip_all_tags( $title ), 0, 250 ),
					'body'       => wp_strip_all_tags( (string) $body ),
					'link'       => substr( (string) $link, 0, 250 ),
					'is_read'    => 0,
					'created_at' => $now,
				)
			);
		}
		if ( class_exists( 'NROS_Push' ) ) {
			NROS_Push::to_users(
				$uids,
				array(
					'title'  => '🚨 ' . wp_strip_all_tags( $title ),
					'body'   => wp_html_excerpt( wp_strip_all_tags( (string) $body ), 160, '…' ),
					'url'    => $link ? $link : '/',
					'tag'    => 'field-' . sanitize_key( $type ),
					'urgent' => true,
				),
				array( 'topic' => 'field-' . sanitize_key( $type ), 'ttl' => 6 * HOUR_IN_SECONDS, 'urgency' => 'high' )
			);
		}
	}

	/** Correspondents whose profile district matches (everyone if none given). */
	protected static function correspondents_in( $district ) {
		$args = array(
			'role'   => 'correspondent',
			'fields' => 'ID',
			'number' => 3000,
		);
		if ( '' !== $district ) {
			$args['meta_query'] = array( array( 'key' => 'meeha_district', 'value' => $district ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}
		return array_map( 'intval', get_users( $args ) );
	}

	// ------------------------------------------------------------------
	// 1. কাজের বোর্ড
	// ------------------------------------------------------------------

	const KINDS = array(
		'news'   => 'সংবাদ',
		'photo'  => 'ছবি',
		'video'  => 'ভিডিও',
		'live'   => 'লাইভ',
		'invest' => 'অনুসন্ধান',
		'survey' => 'জরিপ / তথ্য সংগ্রহ',
	);

	protected static function assign_row( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'assign' ) . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	protected static function claim_row( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'assign_claims' ) . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/** Claims that hold a place (taken, sent or accepted). */
	protected static function held( $assign_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t( 'assign_claims' ) . " WHERE assign_id = %d AND status IN ('taken','submitted','paid')", $assign_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	protected static function shape_assign( $a, $uid = 0 ) {
		$held = self::held( (int) $a['id'] );
		$out  = array(
			'id'         => (int) $a['id'],
			'title'      => $a['title'],
			'brief'      => (string) $a['brief'],
			'district'   => $a['district'],
			'upazila'    => $a['upazila'],
			'fee'        => (float) $a['fee'],
			'travel'     => (float) $a['travel'],
			'slots'      => (int) $a['slots'],
			'left'       => max( 0, (int) $a['slots'] - $held ),
			'kind'       => $a['kind'],
			'kind_label' => isset( self::KINDS[ $a['kind'] ] ) ? self::KINDS[ $a['kind'] ] : $a['kind'],
			'urgent'     => (bool) $a['urgent'],
			'deadline'   => $a['deadline'] ? mysql2date( 'c', $a['deadline'], false ) : null,
			'status'     => $a['status'],
			'created_at' => mysql2date( 'c', $a['created_at'], false ),
		);
		if ( $uid ) {
			$area          = self::area_of( $uid );
			$out['mine']   = '' === $a['district'] || $a['district'] === $area['district'];
			$out['nearby'] = '' !== $a['upazila'] && $a['upazila'] === $area['upazila'];
		}
		return $out;
	}

	protected static function shape_claim( $c, $with_person = false ) {
		$out = array(
			'id'           => (int) $c['id'],
			'assign_id'    => (int) $c['assign_id'],
			'status'       => $c['status'],
			'note'         => (string) $c['note'],
			'post_id'      => $c['post_id'] ? (int) $c['post_id'] : null,
			'post_title'   => $c['post_id'] ? get_the_title( (int) $c['post_id'] ) : '',
			'link'         => (string) $c['link'],
			'desk_note'    => (string) $c['desk_note'],
			'paid'         => (float) $c['paid'],
			'created_at'   => mysql2date( 'c', $c['created_at'], false ),
			'submitted_at' => $c['submitted_at'] ? mysql2date( 'c', $c['submitted_at'], false ) : null,
		);
		if ( $with_person ) {
			$u              = get_userdata( (int) $c['user_id'] );
			$out['user_id'] = (int) $c['user_id'];
			$out['name']    = $u ? $u->display_name : '—';
			$out['phone']   = self::phone_of( (int) $c['user_id'] );
			$out['area']    = implode( ', ', array_filter( self::area_of( (int) $c['user_id'] ) ) );
		}
		return $out;
	}

	/** GET /field — the correspondent's board, safety state and RTI dates. */
	public static function my_board( $request ) {
		global $wpdb;
		$u = self::me( $request );
		self::expire_assignments();
		$open = $wpdb->get_results( 'SELECT * FROM ' . self::t( 'assign' ) . " WHERE status = 'open' ORDER BY urgent DESC, id DESC LIMIT 100", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$claims = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'assign_claims' ) . ' WHERE user_id = %d ORDER BY id DESC LIMIT 60', $u->ID ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$taken  = array();
		$mine   = array();
		foreach ( (array) $claims as $c ) {
			$taken[ (int) $c['assign_id'] ] = true;
			$a = self::assign_row( (int) $c['assign_id'] );
			if ( $a ) {
				$mine[] = array_merge( self::shape_claim( $c ), array( 'assignment' => self::shape_assign( $a, $u->ID ) ) );
			}
		}
		$board = array();
		foreach ( (array) $open as $a ) {
			if ( isset( $taken[ (int) $a['id'] ] ) ) {
				continue;
			}
			$s = self::shape_assign( $a, $u->ID );
			if ( $s['left'] > 0 ) {
				$board[] = $s;
			}
		}
		// own area first
		usort(
			$board,
			function ( $x, $y ) {
				return array( ! $x['urgent'], ! $x['nearby'], ! $x['mine'], -$x['id'] ) <=> array( ! $y['urgent'], ! $y['nearby'], ! $y['mine'], -$y['id'] );
			}
		);
		$earned = (float) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(paid),0) FROM ' . self::t( 'assign_claims' ) . " WHERE user_id = %d AND status = 'paid'", $u->ID ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return rest_ensure_response(
			array(
				'area'    => self::area_of( $u->ID ),
				'board'   => $board,
				'mine'    => $mine,
				'earned'  => $earned,
				'safety'  => self::my_safety( $u->ID ),
				'hours'   => self::CHECKIN_HOURS,
				'kinds'   => self::KINDS,
			)
		);
	}

	public static function take( $request ) {
		global $wpdb;
		$u = self::me( $request );
		$a = self::assign_row( (int) $request['id'] );
		if ( ! $a || 'open' !== $a['status'] ) {
			return self::err( 'এই কাজটি আর খোলা নেই।', 409, 'nros_field_closed' );
		}
		if ( $a['deadline'] && strtotime( $a['deadline'] ) < current_time( 'timestamp' ) ) { // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
			return self::err( 'কাজটির সময় শেষ হয়ে গেছে।', 409, 'nros_field_closed' );
		}
		$active = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t( 'assign_claims' ) . " WHERE user_id = %d AND status = 'taken'", $u->ID ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( $active >= 5 ) {
			return self::err( 'একসঙ্গে ৫টির বেশি কাজ নেওয়া যায় না। আগের কাজ পাঠান বা ছেড়ে দিন।', 409, 'nros_field_too_many' );
		}
		// one place at a time: a named lock so two people can't take the last slot together
		$lock = 'nros_assign_' . (int) $a['id'];
		$wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock ) );
		$left = (int) $a['slots'] - self::held( (int) $a['id'] );
		$old  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'assign_claims' ) . ' WHERE assign_id = %d AND user_id = %d', $a['id'], $u->ID ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( $old && in_array( $old['status'], array( 'taken', 'submitted', 'paid' ), true ) ) {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
			return self::err( 'কাজটি আগেই নিয়েছেন।', 409, 'nros_field_taken' );
		}
		if ( $left <= 0 ) {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
			return self::err( 'দুঃখিত, এই কাজের সব জায়গা পূর্ণ।', 409, 'nros_field_full' );
		}
		if ( $old ) {
			$wpdb->update( self::t( 'assign_claims' ), array( 'status' => 'taken', 'created_at' => self::now(), 'submitted_at' => null, 'desk_note' => '' ), array( 'id' => $old['id'] ) );
			$cid = (int) $old['id'];
		} else {
			$wpdb->insert(
				self::t( 'assign_claims' ),
				array(
					'assign_id'  => (int) $a['id'],
					'user_id'    => $u->ID,
					'status'     => 'taken',
					'created_at' => self::now(),
				)
			);
			$cid = (int) $wpdb->insert_id;
		}
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		self::notify( array( (int) $a['created_by'] ), 'field_taken', $u->display_name . ' কাজটি নিয়েছেন: ' . $a['title'], '', '/app/field' );
		return rest_ensure_response( array( 'ok' => true, 'claim_id' => $cid ) );
	}

	protected static function own_claim( $request ) {
		$u = self::me( $request );
		$c = self::claim_row( (int) $request['id'] );
		if ( ! $c || (int) $c['user_id'] !== (int) $u->ID ) {
			return self::err( 'পাওয়া যায়নি।', 404, 'nros_not_found' );
		}
		return $c;
	}

	public static function submit( $request ) {
		global $wpdb;
		$c = self::own_claim( $request );
		if ( is_wp_error( $c ) ) {
			return $c;
		}
		if ( ! in_array( $c['status'], array( 'taken', 'submitted', 'rejected' ), true ) ) {
			return self::err( 'এই কাজ আর পাঠানো যায় না।', 409 );
		}
		$post_id = (int) $request->get_param( 'post_id' );
		if ( $post_id ) {
			$p = get_post( $post_id );
			if ( ! $p || (int) $p->post_author !== (int) $c['user_id'] ) {
				return self::err( 'নিজের লেখা সংবাদ বেছে নিন।' );
			}
		}
		$link = esc_url_raw( (string) $request->get_param( 'link' ) );
		$note = self::txt( $request, 'note', 2000, true );
		if ( ! $post_id && '' === $link && '' === $note ) {
			return self::err( 'সংবাদ বাছুন, লিংক দিন বা কী করেছেন লিখুন।' );
		}
		$wpdb->update(
			self::t( 'assign_claims' ),
			array(
				'status'       => 'submitted',
				'post_id'      => $post_id ? $post_id : null,
				'link'         => mb_substr( $link, 0, 500 ),
				'note'         => $note,
				'submitted_at' => self::now(),
			),
			array( 'id' => $c['id'] )
		);
		$a = self::assign_row( (int) $c['assign_id'] );
		$u = self::me( $request );
		if ( $a ) {
			self::notify( array( (int) $a['created_by'] ), 'field_submitted', $u->display_name . ' কাজ জমা দিয়েছেন: ' . $a['title'], $note, '/app/field' );
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public static function drop( $request ) {
		global $wpdb;
		$c = self::own_claim( $request );
		if ( is_wp_error( $c ) ) {
			return $c;
		}
		if ( 'taken' !== $c['status'] && 'submitted' !== $c['status'] ) {
			return self::err( 'এই কাজ আর ছাড়া যায় না।', 409 );
		}
		$wpdb->update( self::t( 'assign_claims' ), array( 'status' => 'dropped' ), array( 'id' => $c['id'] ) );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/** Closes open jobs whose deadline passed (cheap; runs on reads and on the tick). */
	protected static function expire_assignments() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'assign' ) . " SET status = 'closed', updated_at = %s WHERE status = 'open' AND deadline IS NOT NULL AND deadline < %s", self::now(), self::now() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/** POST /admin/field/assignments[/id] — create, edit, close or cancel. */
	public static function assign_save( $request ) {
		global $wpdb;
		$u  = self::me( $request );
		$id = (int) $request['id'];
		$a  = $id ? self::assign_row( $id ) : null;
		if ( $id && ! $a ) {
			return self::err( 'পাওয়া যায়নি।', 404, 'nros_not_found' );
		}
		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		if ( $a && $status && in_array( $status, array( 'open', 'closed', 'cancelled' ), true ) && null === $request->get_param( 'title' ) ) {
			$wpdb->update( self::t( 'assign' ), array( 'status' => $status, 'updated_at' => self::now() ), array( 'id' => $id ) );
			return rest_ensure_response( array( 'ok' => true ) );
		}
		$title = self::txt( $request, 'title', 200 );
		if ( '' === $title ) {
			return self::err( 'কাজের শিরোনাম দিন।' );
		}
		$fee    = max( 0, min( 1000000, (float) $request->get_param( 'fee' ) ) );
		$travel = max( 0, min( 100000, (float) $request->get_param( 'travel' ) ) );
		$slots  = max( 1, min( 50, (int) $request->get_param( 'slots' ) ) );
		$kind   = sanitize_key( (string) $request->get_param( 'kind' ) );
		$kind   = isset( self::KINDS[ $kind ] ) ? $kind : 'news';
		$dl     = (string) $request->get_param( 'deadline' );
		$dlv    = null;
		if ( '' !== $dl ) {
			$ts = strtotime( $dl );
			if ( ! $ts ) {
				return self::err( 'শেষ সময় ঠিক নেই।' );
			}
			// “2026-11-20T18:00” from the app is already Bangladesh time; a
			// value with a zone is turned into the site's local time
			$zoned = (bool) preg_match( '/(Z|[+-]\d\d:?\d\d)$/', $dl );
			$dlv   = gmdate( 'Y-m-d H:i:s', $zoned ? $ts + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) : $ts );
		}
		$row = array(
			'title'      => $title,
			'brief'      => self::txt( $request, 'brief', 4000, true ),
			'district'   => self::txt( $request, 'district', 80 ),
			'upazila'    => self::txt( $request, 'upazila', 80 ),
			'fee'        => $fee,
			'travel'     => $travel,
			'slots'      => $slots,
			'kind'       => $kind,
			'urgent'     => $request->get_param( 'urgent' ) ? 1 : 0,
			'deadline'   => $dlv,
			'updated_at' => self::now(),
		);
		if ( $a ) {
			$wpdb->update( self::t( 'assign' ), $row, array( 'id' => $id ) );
			return rest_ensure_response( array( 'ok' => true, 'id' => $id ) );
		}
		$row['status']     = 'open';
		$row['created_by'] = $u->ID;
		$row['created_at'] = self::now();
		$wpdb->insert( self::t( 'assign' ), $row );
		$id = (int) $wpdb->insert_id;
		// tell the people of that area (everyone, for a nationwide job)
		$to    = self::correspondents_in( $row['district'] );
		$money = $fee + $travel > 0 ? ' · ৳' . NROS_Core::bn_digits( number_format( $fee + $travel ) ) : '';
		$where = $row['upazila'] ? $row['upazila'] . ', ' . $row['district'] : ( $row['district'] ? $row['district'] : 'সারা দেশ' );
		self::notify( $to, 'field_new', ( $row['urgent'] ? 'জরুরি কাজ: ' : 'নতুন কাজ: ' ) . $title . $money, $where, '/app/field' );
		return rest_ensure_response( array( 'ok' => true, 'id' => $id, 'told' => count( $to ) ) );
	}

	/**
	 * POST /admin/field/claims/{id}/decide — accept (pays into the
	 * person's earnings) or send back with a note.
	 */
	public static function decide( $request ) {
		global $wpdb;
		$u = self::me( $request );
		$c = self::claim_row( (int) $request['id'] );
		if ( ! $c ) {
			return self::err( 'পাওয়া যায়নি।', 404, 'nros_not_found' );
		}
		$a = self::assign_row( (int) $c['assign_id'] );
		if ( ! $a ) {
			return self::err( 'কাজটি পাওয়া যায়নি।', 404, 'nros_not_found' );
		}
		$action = sanitize_key( (string) $request->get_param( 'action' ) );
		$note   = self::txt( $request, 'note', 1000, true );
		if ( 'reject' === $action ) {
			$wpdb->update( self::t( 'assign_claims' ), array( 'status' => 'rejected', 'desk_note' => $note, 'decided_at' => self::now(), 'decided_by' => $u->ID ), array( 'id' => $c['id'] ) );
			self::notify( array( (int) $c['user_id'] ), 'field_back', 'কাজ ফেরত এসেছে: ' . $a['title'], $note ? $note : 'ঠিক করে আবার পাঠান।', '/app/field' );
			return rest_ensure_response( array( 'ok' => true ) );
		}
		if ( 'approve' !== $action ) {
			return self::err( 'কী করবেন বলুন।' );
		}
		if ( 'paid' === $c['status'] ) {
			return self::err( 'আগেই গৃহীত হয়েছে।', 409 );
		}
		if ( ! NROS_Core::financial_visible_to( $u ) && ( (float) $a['fee'] + (float) $a['travel'] ) > 0 ) {
			return self::err( 'টাকা দেওয়ার অনুমতি আপনার নেই — পাবলিশারকে বলুন।', 403, 'nros_forbidden' );
		}
		$fee    = null !== $request->get_param( 'fee' ) ? max( 0, (float) $request->get_param( 'fee' ) ) : (float) $a['fee'];
		$travel = null !== $request->get_param( 'travel' ) ? max( 0, (float) $request->get_param( 'travel' ) ) : (float) $a['travel'];
		$ledger = $wpdb->prefix . 'nros_ledger';
		$label  = 'কাজ: ' . $a['title'];
		foreach ( array( 'assignment' => $fee, 'travel' => $travel ) as $type => $amt ) {
			if ( $amt <= 0 ) {
				continue;
			}
			// type + dedupe_key is unique: a double tap can never pay twice
			$wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$ledger} (user_id, type, amount, status, note, post_id, created_by, created_at, dedupe_key) VALUES (%d, %s, %f, 'approved', %s, %s, %d, %s, %d)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					(int) $c['user_id'],
					$type,
					$amt,
					$label,
					$c['post_id'] ? (string) (int) $c['post_id'] : null,
					$u->ID,
					self::now(),
					(int) $c['id']
				)
			);
		}
		$wpdb->update( self::t( 'assign_claims' ), array( 'status' => 'paid', 'paid' => $fee + $travel, 'desk_note' => $note, 'decided_at' => self::now(), 'decided_by' => $u->ID ), array( 'id' => $c['id'] ) );
		$msg = $fee + $travel > 0 ? '৳' . NROS_Core::bn_digits( number_format( $fee + $travel ) ) . ' আপনার আয়ে যোগ হয়েছে।' : 'ধন্যবাদ!';
		self::notify( array( (int) $c['user_id'] ), 'field_paid', 'কাজ গৃহীত: ' . $a['title'], $msg, '/app/earn' );
		// all places done → close the job
		$done = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t( 'assign_claims' ) . " WHERE assign_id = %d AND status = 'paid'", $a['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( $done >= (int) $a['slots'] && 'open' === $a['status'] ) {
			$wpdb->update( self::t( 'assign' ), array( 'status' => 'closed', 'updated_at' => self::now() ), array( 'id' => $a['id'] ) );
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}

	// ------------------------------------------------------------------
	// 2. নিরাপত্তা
	// ------------------------------------------------------------------

	protected static function shape_safety( $s, $with_person = false ) {
		$out = array(
			'id'         => (int) $s['id'],
			'kind'       => $s['kind'],
			'status'     => $s['status'],
			'place'      => $s['place'],
			'note'       => (string) $s['note'],
			'lat'        => null !== $s['lat'] ? (float) $s['lat'] : null,
			'lng'        => null !== $s['lng'] ? (float) $s['lng'] : null,
			'due_at'     => $s['due_at'] ? mysql2date( 'c', $s['due_at'], false ) : null,
			'created_at' => mysql2date( 'c', $s['created_at'], false ),
			'closed_at'  => $s['closed_at'] ? mysql2date( 'c', $s['closed_at'], false ) : null,
		);
		if ( $with_person ) {
			$u              = get_userdata( (int) $s['user_id'] );
			$out['user_id'] = (int) $s['user_id'];
			$out['name']    = $u ? $u->display_name : '—';
			$out['phone']   = self::phone_of( (int) $s['user_id'] );
			$out['area']    = implode( ', ', array_filter( self::area_of( (int) $s['user_id'] ) ) );
			$out['avatar']  = class_exists( 'NROS_Media' ) ? NROS_Media::avatar_url( (int) $s['user_id'] ) : '';
			$out['emergency'] = (string) get_user_meta( (int) $s['user_id'], 'nros_emergency_contact', true );
		}
		return $out;
	}

	protected static function my_safety( $uid ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'safety' ) . " WHERE user_id = %d AND status IN ('active','overdue') ORDER BY id DESC LIMIT 5", $uid ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array_map( array( __CLASS__, 'shape_safety' ), (array) $rows );
	}

	protected static function coords( $request ) {
		$lat = $request->get_param( 'lat' );
		$lng = $request->get_param( 'lng' );
		if ( null === $lat || null === $lng || '' === $lat || '' === $lng ) {
			return array( null, null );
		}
		$lat = (float) $lat;
		$lng = (float) $lng;
		if ( $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 ) {
			return array( null, null );
		}
		return array( round( $lat, 6 ), round( $lng, 6 ) );
	}

	protected static function map_link( $lat, $lng ) {
		return null === $lat ? '' : 'https://maps.google.com/?q=' . rawurlencode( $lat . ',' . $lng );
	}

	/** POST /field/sos — one tap; the whole desk is told at once. */
	public static function sos( $request ) {
		global $wpdb;
		$u = self::me( $request );
		// at most one alert a minute from one person (a pocket tap must not flood)
		$last = $wpdb->get_var( $wpdb->prepare( 'SELECT created_at FROM ' . self::t( 'safety' ) . " WHERE user_id = %d AND kind = 'sos' ORDER BY id DESC LIMIT 1", $u->ID ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		list( $lat, $lng ) = self::coords( $request );
		$place = self::txt( $request, 'place', 200 );
		$note  = self::txt( $request, 'note', 1000, true );
		if ( $last && current_time( 'timestamp' ) - strtotime( $last ) < 60 ) { // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
			$open = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'safety' ) . " WHERE user_id = %d AND kind = 'sos' AND status = 'active' ORDER BY id DESC LIMIT 1", $u->ID ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( $open && null !== $lat ) {
				$wpdb->update( self::t( 'safety' ), array( 'lat' => $lat, 'lng' => $lng ), array( 'id' => $open ) );
			}
			return rest_ensure_response( array( 'ok' => true, 'id' => (int) $open, 'repeat' => true ) );
		}
		$wpdb->insert(
			self::t( 'safety' ),
			array(
				'user_id'    => $u->ID,
				'kind'       => 'sos',
				'status'     => 'active',
				'place'      => $place,
				'note'       => $note,
				'lat'        => $lat,
				'lng'        => $lng,
				'created_at' => self::now(),
			)
		);
		$id   = (int) $wpdb->insert_id;
		$area = implode( ', ', array_filter( self::area_of( $u->ID ) ) );
		$body = trim( implode( ' · ', array_filter( array( $place ? $place : $area, $note, self::phone_of( $u->ID ) ) ) ) );
		self::notify( NROS_Notifications::staff_ids(), 'field_sos', 'SOS — ' . $u->display_name . ' বিপদে', $body, '/app/field?tab=safety', true );
		return rest_ensure_response( array( 'ok' => true, 'id' => $id ) );
	}

	/** POST /field/checkin — “going to …, back by …”. */
	public static function checkin( $request ) {
		global $wpdb;
		$u     = self::me( $request );
		$hours = (int) $request->get_param( 'hours' );
		if ( ! in_array( $hours, self::CHECKIN_HOURS, true ) ) {
			return self::err( 'কত ঘণ্টা লাগবে বাছুন।' );
		}
		$place = self::txt( $request, 'place', 200 );
		if ( '' === $place ) {
			return self::err( 'কোথায় যাচ্ছেন লিখুন।' );
		}
		$open = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t( 'safety' ) . " WHERE user_id = %d AND kind = 'checkin' AND status IN ('active','overdue')", $u->ID ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( $open >= 3 ) {
			return self::err( 'আগের চেক-ইন শেষ করুন (নিরাপদ চাপুন)।', 409 );
		}
		list( $lat, $lng ) = self::coords( $request );
		$wpdb->insert(
			self::t( 'safety' ),
			array(
				'user_id'    => $u->ID,
				'kind'       => 'checkin',
				'status'     => 'active',
				'place'      => $place,
				'note'       => self::txt( $request, 'note', 1000, true ),
				'lat'        => $lat,
				'lng'        => $lng,
				'due_at'     => gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) + $hours * HOUR_IN_SECONDS ), // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
				'created_at' => self::now(),
			)
		);
		$em = self::txt( $request, 'emergency', 120 );
		if ( '' !== $em ) {
			update_user_meta( $u->ID, 'nros_emergency_contact', $em );
		}
		return rest_ensure_response( array( 'ok' => true, 'id' => (int) $wpdb->insert_id, 'safety' => self::my_safety( $u->ID ) ) );
	}

	protected static function own_safety( $request ) {
		$u = self::me( $request );
		global $wpdb;
		$s = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'safety' ) . ' WHERE id = %d', (int) $request['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $s || (int) $s['user_id'] !== (int) $u->ID ) {
			return self::err( 'পাওয়া যায়নি।', 404, 'nros_not_found' );
		}
		return $s;
	}

	public static function mark_safe( $request ) {
		global $wpdb;
		$s = self::own_safety( $request );
		if ( is_wp_error( $s ) ) {
			return $s;
		}
		$was = $s['status'];
		$wpdb->update( self::t( 'safety' ), array( 'status' => 'safe', 'closed_at' => self::now(), 'closed_by' => (int) $s['user_id'] ), array( 'id' => $s['id'] ) );
		if ( 'overdue' === $was || 'sos' === $s['kind'] ) {
			$u = self::me( $request );
			self::notify( NROS_Notifications::staff_ids(), 'field_safe', $u->display_name . ' এখন নিরাপদ', $s['place'], '/app/field?tab=safety' );
		}
		return rest_ensure_response( array( 'ok' => true, 'safety' => self::my_safety( (int) $s['user_id'] ) ) );
	}

	public static function extend( $request ) {
		global $wpdb;
		$s = self::own_safety( $request );
		if ( is_wp_error( $s ) ) {
			return $s;
		}
		if ( 'checkin' !== $s['kind'] || ! in_array( $s['status'], array( 'active', 'overdue' ), true ) ) {
			return self::err( 'এটা আর বাড়ানো যায় না।', 409 );
		}
		$hours = max( 1, min( 12, (int) $request->get_param( 'hours' ) ) );
		$wpdb->update( self::t( 'safety' ), array( 'status' => 'active', 'due_at' => gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) + $hours * HOUR_IN_SECONDS ) ), array( 'id' => $s['id'] ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		return rest_ensure_response( array( 'ok' => true, 'safety' => self::my_safety( (int) $s['user_id'] ) ) );
	}

	public static function resolve( $request ) {
		global $wpdb;
		$u = self::me( $request );
		$id = (int) $request['id'];
		$s  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'safety' ) . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $s ) {
			return self::err( 'পাওয়া যায়নি।', 404, 'nros_not_found' );
		}
		$wpdb->update( self::t( 'safety' ), array( 'status' => 'resolved', 'closed_at' => self::now(), 'closed_by' => $u->ID ), array( 'id' => $id ) );
		self::notify( array( (int) $s['user_id'] ), 'field_resolved', 'ডেস্ক আপনার খোঁজ নিয়েছে', $u->display_name . ' বিষয়টি দেখেছেন।', '/app/field?tab=safety' );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/** Every 5 minutes: check-ins past their time → the desk is alerted (once). */
	public static function tick() {
		global $wpdb;
		self::expire_assignments();
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'safety' ) . " WHERE kind = 'checkin' AND status = 'active' AND due_at < %s LIMIT 50", self::now() ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( (array) $rows as $s ) {
			$wpdb->update( self::t( 'safety' ), array( 'status' => 'overdue' ), array( 'id' => $s['id'] ) );
			$u    = get_userdata( (int) $s['user_id'] );
			$name = $u ? $u->display_name : '—';
			$body = trim( implode( ' · ', array_filter( array( $s['place'], self::phone_of( (int) $s['user_id'] ), (string) get_user_meta( (int) $s['user_id'], 'nros_emergency_contact', true ) ) ) ) );
			self::notify( NROS_Notifications::staff_ids(), 'field_overdue', $name . ' সময়মতো ফেরেননি — খোঁজ নিন', $body, '/app/field?tab=safety', true );
			self::notify( array( (int) $s['user_id'] ), 'field_overdue_me', 'আপনি কি নিরাপদ? “নিরাপদ” চাপুন', 'ডেস্ককে জানানো হয়েছে।', '/app/field?tab=safety', true );
		}
	}

	// ------------------------------------------------------------------
	// 3. তথ্য অধিকার সহকারী
	// ------------------------------------------------------------------

	/**
	 * Adds working days (Friday and Saturday are Bangladesh's weekly
	 * holidays; public holidays are NOT known here, so the real date can
	 * be a little later — the app says so).
	 */
	public static function add_working_days( $date, $n ) {
		$t = strtotime( $date . ' 12:00:00' );
		while ( $n > 0 ) {
			$t += DAY_IN_SECONDS;
			$w  = (int) gmdate( 'w', $t ); // 5 = Fri, 6 = Sat
			if ( 5 !== $w && 6 !== $w ) {
				--$n;
			}
		}
		return gmdate( 'Y-m-d', $t );
	}

	protected static function plus_days( $date, $n ) {
		return gmdate( 'Y-m-d', strtotime( $date . ' 12:00:00' ) + $n * DAY_IN_SECONDS );
	}

	/** The dates that matter for one request (from RTI Act 2009, ss. 9, 24, 25). */
	public static function rti_dates( $r ) {
		$d = array();
		if ( $r['sent_on'] ) {
			$d['answer_due']      = self::add_working_days( $r['sent_on'], 20 );
			$d['answer_due_long'] = self::add_working_days( $r['sent_on'], 30 );
			$base                 = $r['answered_on'] ? $r['answered_on'] : $d['answer_due'];
			$d['appeal_by']       = self::plus_days( $base, 30 );
		}
		if ( $r['appeal_on'] ) {
			$d['appeal_answer_due'] = self::plus_days( $r['appeal_on'], 15 );
			$d['complaint_by']      = self::plus_days( $d['appeal_answer_due'], 30 );
		}
		return $d;
	}

	protected static function shape_rti( $r ) {
		return array(
			'id'           => (int) $r['id'],
			'office'       => $r['office'],
			'officer'      => $r['officer'],
			'address'      => $r['address'],
			'subject'      => $r['subject'],
			'info'         => (string) $r['info'],
			'method'       => $r['method'],
			'status'       => $r['status'],
			'sent_on'      => $r['sent_on'],
			'answered_on'  => $r['answered_on'],
			'appeal_on'    => $r['appeal_on'],
			'complaint_on' => $r['complaint_on'],
			'result'       => (string) $r['result'],
			'dates'        => self::rti_dates( $r ),
			'updated_at'   => mysql2date( 'c', $r['updated_at'], false ),
		);
	}

	public static function rti_list( $request ) {
		global $wpdb;
		$u    = self::me( $request );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'rti' ) . ' WHERE user_id = %d ORDER BY id DESC LIMIT 100', $u->ID ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$prof = array(
			'name'    => $u->display_name,
			'phone'   => self::phone_of( $u->ID ),
			'email'   => $u->user_email,
			'area'    => implode( ', ', array_filter( array_reverse( self::area_of( $u->ID ) ) ) ),
			'address' => (string) get_user_meta( $u->ID, 'meeha_address', true ),
		);
		return rest_ensure_response( array( 'items' => array_map( array( __CLASS__, 'shape_rti' ), (array) $rows ), 'me' => $prof ) );
	}

	protected static function date_param( $request, $key ) {
		$v = (string) $request->get_param( $key );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : null;
	}

	public static function rti_save( $request ) {
		global $wpdb;
		$u  = self::me( $request );
		$id = (int) $request['id'];
		if ( $id ) {
			$old = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'rti' ) . ' WHERE id = %d AND user_id = %d', $id, $u->ID ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( ! $old ) {
				return self::err( 'পাওয়া যায়নি।', 404, 'nros_not_found' );
			}
		} else {
			$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t( 'rti' ) . ' WHERE user_id = %d', $u->ID ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( $count >= 200 ) {
				return self::err( 'অনেক আবেদন জমেছে — পুরোনোগুলো মুছুন।', 409 );
			}
		}
		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		$row    = array(
			'office'       => self::txt( $request, 'office', 200 ),
			'officer'      => self::txt( $request, 'officer', 200 ),
			'address'      => self::txt( $request, 'address', 300 ),
			'subject'      => self::txt( $request, 'subject', 250 ),
			'info'         => self::txt( $request, 'info', 6000, true ),
			'method'       => in_array( (string) $request->get_param( 'method' ), array( 'copy', 'print', 'email', 'inspect', 'cd' ), true ) ? (string) $request->get_param( 'method' ) : 'copy',
			'status'       => in_array( $status, array( 'draft', 'sent', 'answered', 'refused', 'appeal', 'complaint', 'closed' ), true ) ? $status : 'draft',
			'sent_on'      => self::date_param( $request, 'sent_on' ),
			'answered_on'  => self::date_param( $request, 'answered_on' ),
			'appeal_on'    => self::date_param( $request, 'appeal_on' ),
			'complaint_on' => self::date_param( $request, 'complaint_on' ),
			'result'       => self::txt( $request, 'result', 4000, true ),
			'updated_at'   => self::now(),
		);
		if ( '' === $row['office'] || '' === $row['info'] ) {
			return self::err( 'অফিসের নাম ও কী তথ্য চান — দুটোই লিখুন।' );
		}
		if ( $id ) {
			if ( $row['sent_on'] !== $old['sent_on'] || $row['appeal_on'] !== $old['appeal_on'] ) {
				$row['reminded'] = '';
			}
			$wpdb->update( self::t( 'rti' ), $row, array( 'id' => $id ) );
		} else {
			$row['user_id']    = $u->ID;
			$row['created_at'] = self::now();
			$wpdb->insert( self::t( 'rti' ), $row );
			$id = (int) $wpdb->insert_id;
		}
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'rti' ) . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return rest_ensure_response( array( 'ok' => true, 'item' => self::shape_rti( $r ) ) );
	}

	public static function rti_delete( $request ) {
		global $wpdb;
		$u = self::me( $request );
		$wpdb->delete( self::t( 'rti' ), array( 'id' => (int) $request['id'], 'user_id' => $u->ID ) );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/** Daily: RTI reminders (each one once). */
	public static function daily() {
		global $wpdb;
		$rows  = $wpdb->get_results( 'SELECT * FROM ' . self::t( 'rti' ) . " WHERE status IN ('sent','refused','appeal') LIMIT 2000", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$today = current_time( 'Y-m-d' );
		foreach ( (array) $rows as $r ) {
			$d    = self::rti_dates( $r );
			$done = array_filter( explode( ',', (string) $r['reminded'] ) );
			$send = function ( $key, $title, $body ) use ( $r, &$done ) {
				if ( in_array( $key, $done, true ) ) {
					return;
				}
				$done[] = $key;
				self::notify( array( (int) $r['user_id'] ), 'field_rti', $title, $body, '/app/field?tab=rti' );
			};
			if ( 'sent' === $r['status'] && isset( $d['answer_due'] ) && $today > $d['answer_due'] ) {
				$send( 'late', 'তথ্য অধিকার: উত্তরের সময় পার — ' . $r['office'], '২০ কার্যদিবস পেরিয়েছে। আপিল কর্তৃপক্ষের কাছে আপিল করতে পারেন (শেষ দিন ' . NROS_Core::bn_digits( $d['appeal_by'] ) . ')।' );
			}
			if ( in_array( $r['status'], array( 'sent', 'refused' ), true ) && isset( $d['appeal_by'] ) && $today >= self::plus_days( $d['appeal_by'], -5 ) && $today <= $d['appeal_by'] ) {
				$send( 'appeal5', 'আপিলের সময় শেষ হচ্ছে — ' . $r['office'], 'শেষ দিন ' . NROS_Core::bn_digits( $d['appeal_by'] ) . '।' );
			}
			if ( 'appeal' === $r['status'] && isset( $d['appeal_answer_due'] ) && $today > $d['appeal_answer_due'] ) {
				$send( 'complaint', 'আপিলের উত্তর আসেনি — তথ্য কমিশনে অভিযোগ করুন', 'শেষ দিন ' . NROS_Core::bn_digits( $d['complaint_by'] ) . '। অফিস: ' . $r['office'] );
			}
			$wpdb->update( self::t( 'rti' ), array( 'reminded' => implode( ',', $done ) ), array( 'id' => $r['id'] ) );
		}
		// old closed safety rows are kept 180 days
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::t( 'safety' ) . " WHERE status IN ('safe','resolved') AND closed_at < %s", gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 180 * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DateTime.CurrentTimeTimestamp.Requested
	}

	// ------------------------------------------------------------------
	// desk
	// ------------------------------------------------------------------

	/** GET /admin/field — jobs with their claims, safety board, counts. */
	public static function desk( $request ) {
		global $wpdb;
		self::expire_assignments();
		$jobs   = $wpdb->get_results( 'SELECT * FROM ' . self::t( 'assign' ) . " WHERE status = 'open' OR updated_at > DATE_SUB(NOW(), INTERVAL 30 DAY) ORDER BY (status = 'open') DESC, id DESC LIMIT 120", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$ids    = array_map( 'intval', wp_list_pluck( (array) $jobs, 'id' ) );
		$claims = array();
		if ( $ids ) {
			$rows = $wpdb->get_results( 'SELECT * FROM ' . self::t( 'assign_claims' ) . ' WHERE assign_id IN (' . implode( ',', $ids ) . ") AND status <> 'dropped' ORDER BY id", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			foreach ( (array) $rows as $c ) {
				$claims[ (int) $c['assign_id'] ][] = self::shape_claim( $c, true );
			}
		}
		$out = array();
		foreach ( (array) $jobs as $a ) {
			$s           = self::shape_assign( $a );
			$s['claims'] = isset( $claims[ $s['id'] ] ) ? $claims[ $s['id'] ] : array();
			$out[]       = $s;
		}
		$safety = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}nros_safety WHERE status IN ('active','overdue') OR created_at > DATE_SUB(NOW(), INTERVAL 7 DAY) ORDER BY FIELD(status,'active','overdue') DESC, (kind = 'sos') DESC, id DESC LIMIT 100", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$waiting = 0;
		foreach ( $out as $j ) {
			foreach ( $j['claims'] as $c ) {
				$waiting += 'submitted' === $c['status'] ? 1 : 0;
			}
		}
		return rest_ensure_response(
			array(
				'jobs'    => $out,
				'safety'  => array_map(
					function ( $s ) {
						return self::shape_safety( $s, true );
					},
					(array) $safety
				),
				'waiting' => $waiting,
				'alerts'  => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::t( 'safety' ) . " WHERE (kind = 'sos' AND status = 'active') OR status = 'overdue'" ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				'kinds'   => self::KINDS,
				'can_pay' => NROS_Core::financial_visible_to( self::me( $request ) ),
			)
		);
	}

	/** Desk badge: SOS / late check-ins + work waiting for a decision. */
	public static function badge() {
		global $wpdb;
		$a = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::t( 'safety' ) . " WHERE (kind = 'sos' AND status = 'active') OR status = 'overdue'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$w = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::t( 'assign_claims' ) . " WHERE status = 'submitted'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $a + $w;
	}

	/**
	 * GET /admin/field/coverage — correspondents per district and upazila
	 * (all 64 districts from the theme's area list), so the desk sees
	 * where nobody reports from.
	 */
	public static function coverage( $request ) {
		$areas = class_exists( 'NROS_Areas' ) ? NROS_Areas::get_areas( $request ) : null;
		$data  = $areas instanceof WP_REST_Response ? $areas->get_data() : array( 'divisions' => array(), 'upazilas' => array() );
		$users = get_users(
			array(
				'role'   => 'correspondent',
				'number' => 5000,
				'fields' => 'all_with_meta', // one query primes every profile's area (not one per person)
			)
		);
		$by   = array();
		$seen = array();
		$cut  = gmdate( 'Y-m-d H:i:s', time() - 60 * DAY_IN_SECONDS );
		global $wpdb;
		$active_ids = array_flip(
			array_map(
				'intval',
				(array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_author FROM {$wpdb->posts} WHERE post_type IN ('post','meeha_video') AND post_date_gmt > %s", $cut ) )
			)
		);
		foreach ( $users as $u ) {
			$id   = (int) $u->ID;
			$area = self::area_of( $id );
			$d    = trim( $area['district'] );
			if ( '' === $d ) {
				continue;
			}
			if ( ! isset( $by[ $d ] ) ) {
				$by[ $d ] = array( 'people' => 0, 'active' => 0, 'upazilas' => array() );
			}
			++$by[ $d ]['people'];
			if ( isset( $active_ids[ $id ] ) ) {
				++$by[ $d ]['active'];
			}
			$z = trim( $area['upazila'] );
			if ( '' !== $z ) {
				$by[ $d ]['upazilas'][ $z ] = ( isset( $by[ $d ]['upazilas'][ $z ] ) ? $by[ $d ]['upazilas'][ $z ] : 0 ) + 1;
			}
			$seen[ $d ] = true;
		}
		$rows = array();
		foreach ( (array) $data['divisions'] as $div ) {
			foreach ( (array) $div['districts'] as $dist ) {
				$name = is_array( $dist ) ? (string) $dist['name'] : (string) $dist;
				$all  = isset( $data['upazilas'][ $name ] ) ? (array) $data['upazilas'][ $name ] : array();
				$have = isset( $by[ $name ] ) ? $by[ $name ] : array( 'people' => 0, 'active' => 0, 'upazilas' => array() );
				$rows[] = array(
					'division'       => (string) $div['name'],
					'district'       => $name,
					'people'         => $have['people'],
					'active'         => $have['active'],
					'upazila_total'  => count( $all ),
					'upazila_have'   => count( array_intersect( $all, array_keys( $have['upazilas'] ) ) ),
					'upazila_empty'  => array_values( array_diff( $all, array_keys( $have['upazilas'] ) ) ),
				);
				unset( $seen[ $name ] );
			}
		}
		return rest_ensure_response(
			array(
				'rows'    => $rows,
				'unknown' => array_keys( $seen ), // districts typed on profiles that the area list doesn't know
				'total'   => count( $users ),
			)
		);
	}
}
