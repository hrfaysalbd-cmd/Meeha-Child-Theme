<?php
/**
 * Test engine: attempts are created on the server with a frozen question list and a deadline; the browser only
 * ever receives questions without answers; grading happens on the server. Late answers (after the deadline plus
 * a small grace) are ignored and the last autosaved answers are graded instead.
 *
 * @package meeha-prep
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tests.
 */
class MHP_Tests {

	const OPT_LABELS = array( 'ক', 'খ', 'গ', 'ঘ' );

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'mhp_hub_action_start_test', array( __CLASS__, 'handle_start' ) );
		add_action( 'mhp_hub_action_submit_test', array( __CLASS__, 'handle_submit' ) );
	}

	/**
	 * Load questions (published only) keeping the given order.
	 *
	 * @param int[] $ids         Question ids.
	 * @param bool  $with_answer Include answer + explanation.
	 * @return array[] id, text, opts, answer, explain, subject
	 */
	public static function load_questions( $ids, $with_answer = true ) {
		$ids = array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
		if ( ! $ids ) {
			return array();
		}
		$posts = get_posts(
			array(
				'post_type'      => 'mh_question',
				'post_status'    => 'publish',
				'post__in'       => $ids,
				'posts_per_page' => count( $ids ),
				'no_found_rows'  => true,
			)
		);
		$by = array();
		foreach ( $posts as $p ) {
			$opts = array_values( array_map( 'strval', (array) get_post_meta( $p->ID, '_mhp_opts', true ) ) );
			if ( count( $opts ) < 2 ) {
				continue;
			}
			$subj = wp_get_post_terms( $p->ID, 'mh_subject', array( 'fields' => 'names' ) );
			$q    = array(
				'id'      => (int) $p->ID,
				'text'    => (string) $p->post_title,
				'opts'    => array_slice( $opts, 0, 4 ),
				'subject' => ( $subj && ! is_wp_error( $subj ) ) ? $subj[0] : 'সাধারণ',
			);
			if ( $with_answer ) {
				$q['answer']  = (int) get_post_meta( $p->ID, '_mhp_answer', true );
				$q['explain'] = (string) get_post_meta( $p->ID, '_mhp_explain', true );
				$q['source']  = (string) get_post_meta( $p->ID, '_mhp_source', true );
			}
			$by[ $p->ID ] = $q;
		}
		$out = array();
		foreach ( $ids as $id ) {
			if ( isset( $by[ $id ] ) ) {
				$out[] = $by[ $id ];
			}
		}
		return $out;
	}

	/**
	 * Question ids of a test (published questions only).
	 *
	 * @param int $test_id Test.
	 * @return int[]
	 */
	public static function question_ids( $test_id ) {
		$ids = array_values( array_filter( array_map( 'intval', (array) mhp_meta( $test_id, 'questions', array() ) ) ) );
		if ( ! $ids ) {
			return array();
		}
		$live = get_posts(
			array(
				'post_type'      => 'mh_question',
				'post_status'    => 'publish',
				'post__in'       => $ids,
				'posts_per_page' => count( $ids ),
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		// Keep the test's order in PHP: ORDER BY FIELD() with 100+ ids is slow and some databases cap its arguments.
		return array_values( array_intersect( $ids, array_map( 'intval', $live ) ) );
	}

	/**
	 * Test summary.
	 *
	 * @param WP_Post|int $test Test.
	 * @return array|null
	 */
	public static function info( $test ) {
		$test = get_post( $test );
		if ( ! $test || 'mh_test' !== $test->post_type ) {
			return null;
		}
		$tracks = wp_get_post_terms( $test->ID, 'mh_track' );
		$track  = ( $tracks && ! is_wp_error( $tracks ) ) ? mhp_track( $tracks[0] ) : null;
		$start  = (string) mhp_meta( $test->ID, 'live_start', '' );
		$end    = (string) mhp_meta( $test->ID, 'live_end', '' );
		$now    = mhp_now_local();
		$state  = 'open';
		if ( $start && $now < self::norm_dt( $start ) ) {
			$state = 'upcoming';
		} elseif ( $start && $end && $now > self::norm_dt( $end ) ) {
			$state = 'ended';
		}
		$qids = self::question_ids( $test->ID );
		return array(
			'id'        => (int) $test->ID,
			'title'     => get_the_title( $test ),
			'desc'      => (string) $test->post_excerpt,
			'status'    => $test->post_status,
			'track'     => $track,
			'price'     => round( (float) mhp_meta( $test->ID, 'price', 0 ), 2 ),
			'minutes'   => max( 1, (int) mhp_meta( $test->ID, 'duration', 10 ) ),
			'negative'  => (float) mhp_meta( $test->ID, 'negative', 0 ),
			'count'     => count( $qids ),
			'live'      => '' !== $start,
			'start'     => $start,
			'end'       => $end,
			'state'     => $state,
			'full_note' => (string) mhp_meta( $test->ID, 'note', '' ),
		);
	}

	/**
	 * 'Y-m-dTH:i' → 'Y-m-d H:i:s'.
	 *
	 * @param string $dt Datetime.
	 * @return string
	 */
	public static function norm_dt( $dt ) {
		$dt = str_replace( 'T', ' ', (string) $dt );
		return 16 === strlen( $dt ) ? $dt . ':00' : $dt;
	}

	/**
	 * Published tests, optionally of one track.
	 *
	 * @param int  $track_id Track term (0 = all).
	 * @param bool $live     Only live tests.
	 * @return array[]
	 */
	public static function list_tests( $track_id = 0, $live = false ) {
		$args = array(
			'post_type'      => 'mh_test',
			'post_status'    => 'publish',
			'posts_per_page' => 100,
			'orderby'        => array(
				'menu_order' => 'ASC',
				'date'       => 'DESC',
			),
			'no_found_rows'  => true,
		);
		if ( $track_id ) {
			$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'taxonomy' => 'mh_track',
					'terms'    => (int) $track_id,
				),
			);
		}
		if ( $live ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'key'     => '_mhp_live_start',
					'value'   => '',
					'compare' => '!=',
				),
			);
		}
		$out = array();
		foreach ( get_posts( $args ) as $p ) {
			$i = self::info( $p );
			if ( $i && $i['count'] > 0 ) {
				$out[] = $i;
			}
		}
		return $out;
	}

	/**
	 * The next or current live test.
	 *
	 * @return array|null
	 */
	public static function next_live() {
		$best = null;
		foreach ( self::list_tests( 0, true ) as $t ) {
			if ( 'ended' === $t['state'] ) {
				continue;
			}
			if ( ! $best || self::norm_dt( $t['start'] ) < self::norm_dt( $best['start'] ) ) {
				$best = $t;
			}
		}
		return $best;
	}

	/**
	 * Attempt row.
	 *
	 * @param int $id Attempt.
	 * @return array|null
	 */
	public static function get_attempt( $id ) {
		global $wpdb;
		$t = MHP_Install::table( 'attempts' );
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! $r ) {
			return null;
		}
		$r['qids']    = array_map( 'intval', (array) json_decode( (string) $r['qids'], true ) );
		$r['answers'] = self::clean_answers( json_decode( (string) $r['answers'], true ), count( $r['qids'] ) );
		return $r;
	}

	/**
	 * Attempt owned by the current user (staff may open anyone's).
	 *
	 * @param int $id Attempt.
	 * @return array|null
	 */
	public static function own_attempt( $id ) {
		$a = self::get_attempt( $id );
		if ( ! $a ) {
			return null;
		}
		if ( (int) $a['user_id'] !== get_current_user_id() && ! mhp_can_manage() ) {
			return null;
		}
		return $a;
	}

	/**
	 * Normalise answers: [index => 0..3].
	 *
	 * @param mixed $raw Raw.
	 * @param int   $n   Question count.
	 * @return array
	 */
	public static function clean_answers( $raw, $n ) {
		$out = array();
		foreach ( (array) $raw as $k => $v ) {
			$k = (int) $k;
			if ( $k < 0 || $k >= $n || '' === $v || null === $v ) {
				continue;
			}
			$v = (int) $v;
			if ( $v >= 0 && $v <= 3 ) {
				$out[ $k ] = $v;
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Start (or resume) an attempt.
	 *
	 * @param int $user_id User.
	 * @param int $test_id Test.
	 * @return int|WP_Error Attempt id.
	 */
	public static function start( $user_id, $test_id ) {
		global $wpdb;
		$info = self::info( $test_id );
		if ( ! $info || 'publish' !== $info['status'] ) {
			return new WP_Error( 'mhp_test', 'টেস্টটি পাওয়া যায়নি।' );
		}
		if ( ! $user_id ) {
			return new WP_Error( 'mhp_login', 'টেস্ট দিতে লগইন করুন।' );
		}
		if ( ! MHP_Access::can_take_test( $user_id, $test_id ) ) {
			return new WP_Error( 'mhp_access', 'এই টেস্টটি আগে কিনতে হবে।' );
		}
		if ( 'upcoming' === $info['state'] && ! mhp_can_manage() ) {
			return new WP_Error( 'mhp_live', 'লাইভ টেস্ট এখনো শুরু হয়নি।' );
		}
		$qids = self::question_ids( $test_id );
		if ( ! $qids ) {
			return new WP_Error( 'mhp_empty', 'এই টেস্টে এখনো প্রশ্ন যোগ হয়নি।' );
		}
		$t = MHP_Install::table( 'attempts' );
		// Resume an active attempt that still has time.
		$active = $wpdb->get_row( $wpdb->prepare( "SELECT id, deadline_at FROM {$t} WHERE user_id = %d AND test_id = %d AND status = 'active' ORDER BY id DESC LIMIT 1", $user_id, $test_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( $active ) {
			if ( strtotime( $active['deadline_at'] . ' UTC' ) > time() ) {
				return (int) $active['id'];
			}
			self::submit( self::get_attempt( $active['id'] ), null );
		}
		$done  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE user_id = %d AND test_id = %d AND status = 'submitted'", $user_id, $test_id ) ); // phpcs:ignore WordPress.DB
		$first = 0 === $done ? 1 : 0;
		if ( $info['live'] && 'open' !== $info['state'] ) {
			$first = 0; // Practice runs before/after the live window are not ranked.
		}
		$now = time();
		$ok  = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$t,
			array(
				'user_id'     => (int) $user_id,
				'test_id'     => (int) $test_id,
				'status'      => 'active',
				'is_first'    => $first,
				'started_at'  => mhp_gmt( $now ),
				'deadline_at' => mhp_gmt( $now + $info['minutes'] * 60 ),
				'qids'        => wp_json_encode( $qids ),
				'answers'     => '{}',
				'total'       => count( $qids ),
			),
			array( '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%d' )
		);
		if ( ! $ok ) {
			return new WP_Error( 'mhp_db', 'টেস্ট শুরু করা যায়নি।' );
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Is an attempt still accepting answers?
	 *
	 * @param array $a Attempt.
	 * @return bool
	 */
	public static function accepting( $a ) {
		$grace = (int) mhp_opt( 'grace_seconds', 30 );
		return 'active' === $a['status'] && time() <= strtotime( $a['deadline_at'] . ' UTC' ) + $grace;
	}

	/**
	 * Autosave answers.
	 *
	 * @param array $a       Attempt.
	 * @param array $answers Answers.
	 * @return bool
	 */
	public static function save( $a, $answers ) {
		global $wpdb;
		if ( ! self::accepting( $a ) ) {
			return false;
		}
		$clean = self::clean_answers( $answers, count( $a['qids'] ) );
		return false !== $wpdb->update( MHP_Install::table( 'attempts' ), array( 'answers' => wp_json_encode( (object) $clean ) ), array( 'id' => (int) $a['id'], 'status' => 'active' ), array( '%s' ), array( '%d', '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Grade and close an attempt.
	 *
	 * @param array      $a       Attempt.
	 * @param array|null $answers Final answers (ignored when late).
	 * @return array|WP_Error Graded attempt.
	 */
	public static function submit( $a, $answers ) {
		global $wpdb;
		if ( ! $a ) {
			return new WP_Error( 'mhp_attempt', 'পরীক্ষাটি পাওয়া যায়নি।' );
		}
		if ( 'submitted' === $a['status'] ) {
			return $a;
		}
		$final = $a['answers'];
		if ( null !== $answers && self::accepting( $a ) ) {
			$final = self::clean_answers( $answers, count( $a['qids'] ) );
		}
		$qs  = self::load_questions( $a['qids'], true );
		$neg = (float) mhp_meta( $a['test_id'], 'negative', 0 );
		$map = array();
		foreach ( $qs as $q ) {
			$map[ $q['id'] ] = $q;
		}
		$c = 0;
		$w = 0;
		$s = 0;
		foreach ( $a['qids'] as $i => $qid ) {
			if ( ! isset( $map[ $qid ] ) || ! array_key_exists( $i, $final ) ) {
				++$s;
				continue;
			}
			if ( (int) $final[ $i ] === (int) $map[ $qid ]['answer'] ) {
				++$c;
				self::bump_stat( $qid, true );
			} else {
				++$w;
				self::bump_stat( $qid, false );
			}
		}
		$start    = strtotime( $a['started_at'] . ' UTC' );
		$deadline = strtotime( $a['deadline_at'] . ' UTC' );
		$secs     = max( 0, min( time(), $deadline ) - $start );
		$score    = round( $c - $w * $neg, 2 );
		$n        = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			MHP_Install::table( 'attempts' ),
			array(
				'status'       => 'submitted',
				'submitted_at' => mhp_gmt(),
				'answers'      => wp_json_encode( (object) $final ),
				'score'        => $score,
				'correct'      => $c,
				'wrong'        => $w,
				'skipped'      => $s,
				'total'        => count( $a['qids'] ),
				'seconds'      => $secs,
			),
			array(
				'id'     => (int) $a['id'],
				'status' => 'active',
			),
			array( '%s', '%s', '%s', '%f', '%d', '%d', '%d', '%d', '%d' ),
			array( '%d', '%s' )
		);
		$fresh = self::get_attempt( $a['id'] );
		if ( $n ) {
			do_action( 'mhp_attempt_submitted', $fresh );
		}
		return $fresh;
	}

	/**
	 * Question statistics (used / correct) for the question bank's "সঠিক হার".
	 *
	 * @param int  $qid     Question.
	 * @param bool $correct Correct.
	 */
	private static function bump_stat( $qid, $correct ) {
		update_post_meta( $qid, '_mhp_answered', (int) get_post_meta( $qid, '_mhp_answered', true ) + 1 );
		if ( $correct ) {
			update_post_meta( $qid, '_mhp_correct', (int) get_post_meta( $qid, '_mhp_correct', true ) + 1 );
		}
	}

	/**
	 * Rank among first attempts (higher score, then less time).
	 *
	 * @param array $a Attempt.
	 * @return array rank, of (of = 0 when the attempt is not ranked)
	 */
	public static function rank( $a ) {
		global $wpdb;
		$t  = MHP_Install::table( 'attempts' );
		$of = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE test_id = %d AND is_first = 1 AND status = 'submitted'", $a['test_id'] ) ); // phpcs:ignore WordPress.DB
		if ( ! (int) $a['is_first'] ) {
			return array(
				'rank' => 0,
				'of'   => $of,
			);
		}
		$better = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE test_id = %d AND is_first = 1 AND status = 'submitted' AND ( score > %f OR ( score = %f AND seconds < %d ) )", $a['test_id'], $a['score'], $a['score'], $a['seconds'] ) ); // phpcs:ignore WordPress.DB
		return array(
			'rank' => $better + 1,
			'of'   => $of,
		);
	}

	/**
	 * A user's submitted attempts.
	 *
	 * @param int $user_id User.
	 * @param int $limit   Limit.
	 * @return array[]
	 */
	public static function results_of( $user_id, $limit = 50 ) {
		global $wpdb;
		$t = MHP_Install::table( 'attempts' );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, test_id, score, correct, wrong, skipped, total, seconds, submitted_at FROM {$t} WHERE user_id = %d AND status = 'submitted' ORDER BY id DESC LIMIT %d", (int) $user_id, (int) $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Last submitted attempt of a user for a test.
	 *
	 * @param int $user_id User.
	 * @param int $test_id Test.
	 * @return array|null
	 */
	public static function last_result( $user_id, $test_id ) {
		global $wpdb;
		if ( ! $user_id ) {
			return null;
		}
		$t = MHP_Install::table( 'attempts' );
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT id, score, total FROM {$t} WHERE user_id = %d AND test_id = %d AND status = 'submitted' ORDER BY id DESC LIMIT 1", (int) $user_id, (int) $test_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $r ? $r : null;
	}

	/**
	 * Distinct users who took tests of a track (real "preparing" count).
	 *
	 * @param int $track_id Track.
	 * @return int
	 */
	public static function track_takers( $track_id ) {
		global $wpdb;
		$key = 'mhp_takers_' . (int) $track_id;
		$n   = get_transient( $key );
		if ( false !== $n ) {
			return (int) $n;
		}
		$ids = get_posts(
			array(
				'post_type'      => 'mh_test',
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'taxonomy' => 'mh_track',
						'terms'    => (int) $track_id,
					),
				),
			)
		);
		$n = 0;
		if ( $ids ) {
			$t  = MHP_Install::table( 'attempts' );
			$in = implode( ',', array_map( 'intval', $ids ) );
			$n  = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT user_id) FROM {$t} WHERE status = 'submitted' AND test_id IN ({$in})" ); // phpcs:ignore WordPress.DB
		}
		set_transient( $key, $n, HOUR_IN_SECONDS );
		return $n;
	}

	/**
	 * Close attempts whose time ran out (cron).
	 */
	public static function expire_stale() {
		global $wpdb;
		$t     = MHP_Install::table( 'attempts' );
		$grace = (int) mhp_opt( 'grace_seconds', 30 );
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t} WHERE status = 'active' AND deadline_at < %s LIMIT 200", mhp_gmt( time() - $grace - 60 ) ) ); // phpcs:ignore WordPress.DB
		foreach ( (array) $ids as $id ) {
			self::submit( self::get_attempt( $id ), null );
		}
	}

	/**
	 * Hub POST: start a test.
	 */
	public static function handle_start() {
		$test_id = isset( $_POST['test_id'] ) ? absint( $_POST['test_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by the dispatcher.
		$res     = self::start( get_current_user_id(), $test_id );
		if ( is_wp_error( $res ) ) {
			mhp_flash( $res->get_error_message(), 'error' );
			wp_safe_redirect( mhp_url( 'test', $test_id ) );
			exit;
		}
		wp_safe_redirect( mhp_url( 'run', $res ) );
		exit;
	}

	/**
	 * Hub POST: submit (also the no-JavaScript path).
	 */
	public static function handle_submit() {
		$id = isset( $_POST['attempt'] ) ? absint( $_POST['attempt'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by the dispatcher.
		$a  = self::get_attempt( $id );
		if ( ! $a || (int) $a['user_id'] !== get_current_user_id() ) {
			mhp_flash( 'পরীক্ষাটি পাওয়া যায়নি।', 'error' );
			wp_safe_redirect( mhp_url( 'prep' ) );
			exit;
		}
		$raw = array();
		if ( isset( $_POST['ans'] ) && is_array( $_POST['ans'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			foreach ( wp_unslash( $_POST['ans'] ) as $k => $v ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cast below.
				$raw[ (int) $k ] = is_scalar( $v ) && '' !== $v ? (int) $v : null;
			}
		}
		self::submit( $a, $raw );
		wp_safe_redirect( mhp_url( 'result', $id ) );
		exit;
	}
}
