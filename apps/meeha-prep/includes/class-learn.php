<?php
/**
 * The reader's learning record and dashboard: mistake notebook (ভুলের খাতা), per-subject accuracy (দুর্বল বিষয়),
 * target exam, daily goal, and the "আমার" overview that brings it all together.
 *
 * Wrong answers are recorded from graded tests and from the daily quiz. A wrong answer from a live test only shows
 * in the notebook after the live window closes, so nobody can read the answers while others are still sitting it.
 *
 * @package meeha-prep
 */

defined( 'ABSPATH' ) || exit;

/**
 * Learning record.
 */
class MHP_Learn {

	const PER_PAGE = 10;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'meeha_campus_views', array( __CLASS__, 'views' ) );
		add_filter( 'meeha_campus_wide_views', array( __CLASS__, 'wide' ) );
		add_filter( 'mhp_hub_actions', array( __CLASS__, 'actions' ) );
		add_action( 'mhp_attempt_submitted', array( __CLASS__, 'on_attempt' ), 5 );
		add_action( 'mhp_quiz_answered', array( __CLASS__, 'on_quiz_answer' ), 10, 3 );
		add_action( 'mhp_hub_action_mistake_try', array( __CLASS__, 'handle_try' ) );
		add_action( 'mhp_hub_action_mistake_drop', array( __CLASS__, 'handle_drop' ) );
		add_action( 'mhp_hub_action_goal_set', array( __CLASS__, 'handle_goal' ) );
		add_action( 'mhp_hub_action_target_set', array( __CLASS__, 'handle_target' ) );
	}

	/**
	 * Hub actions.
	 *
	 * @param string[] $a Actions.
	 * @return string[]
	 */
	public static function actions( $a ) {
		return array_merge( $a, array( 'mistake_try', 'mistake_drop', 'goal_set', 'target_set' ) );
	}

	/**
	 * Views.
	 *
	 * @param array $views Views.
	 * @return array
	 */
	public static function views( $views ) {
		$views['mistakes'] = array(
			'title'   => 'ভুলের খাতা',
			'tab'     => 'me',
			'back'    => 'me',
			'nocache' => true,
			'noindex' => true,
			'render'  => array( __CLASS__, 'render_mistakes' ),
		);
		return $views;
	}

	/**
	 * The dashboard (আমার › সারসংক্ষেপ) uses the wide desktop layout.
	 *
	 * @param string[] $v Wide views.
	 * @return string[]
	 */
	public static function wide( $v ) {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $tab || 'overview' === $tab ) {
			$v[] = 'me';
		}
		return $v;
	}

	/* ---------------- recording ---------------- */

	/**
	 * Subject term id per question.
	 *
	 * @param int[] $qids Questions.
	 * @return array qid => subject term id
	 */
	private static function subjects_of( $qids ) {
		$out   = array();
		$terms = $qids ? wp_get_object_terms( array_map( 'intval', $qids ), 'mh_subject', array( 'fields' => 'all_with_object_id' ) ) : array();
		if ( is_wp_error( $terms ) ) {
			return $out;
		}
		foreach ( $terms as $t ) {
			if ( ! isset( $out[ (int) $t->object_id ] ) ) {
				$out[ (int) $t->object_id ] = (int) $t->term_id;
			}
		}
		return $out;
	}

	/**
	 * Add answered/correct counts per subject.
	 *
	 * @param int   $user_id User.
	 * @param array $tally   subject id => [seen, correct].
	 */
	private static function bump_skills( $user_id, $tally ) {
		global $wpdb;
		$t = MHP_Install::table( 'skills' );
		foreach ( $tally as $sid => $n ) {
			$ok = $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET seen = seen + %d, correct = correct + %d, updated_at = %s WHERE user_id = %d AND subject_id = %d", $n[0], $n[1], mhp_gmt(), (int) $user_id, (int) $sid ) ); // phpcs:ignore WordPress.DB
			if ( ! $ok ) {
				$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$t} (user_id, subject_id, seen, correct, updated_at) VALUES (%d, %d, %d, %d, %s)", (int) $user_id, (int) $sid, $n[0], $n[1], mhp_gmt() ) ); // phpcs:ignore WordPress.DB
			}
		}
	}

	/**
	 * Put a wrong answer in the notebook (or count it again).
	 *
	 * @param int    $user_id  User.
	 * @param int    $qid      Question.
	 * @param string $source   test|quiz.
	 * @param string $avail_at GMT datetime from which the reader may see it.
	 */
	public static function record_mistake( $user_id, $qid, $source, $avail_at ) {
		global $wpdb;
		$t   = MHP_Install::table( 'mistakes' );
		$now = mhp_gmt();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, avail_at FROM {$t} WHERE user_id = %d AND question_id = %d", (int) $user_id, (int) $qid ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( $row ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET wrong_count = wrong_count + 1, last_wrong = %s, fixed_at = NULL, source = %s, avail_at = %s WHERE id = %d", $now, $source, max( (string) $row['avail_at'], $avail_at ), (int) $row['id'] ) ); // phpcs:ignore WordPress.DB
			return;
		}
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$t} (user_id, question_id, source, wrong_count, last_wrong, avail_at) VALUES (%d, %d, %s, 1, %s, %s)", (int) $user_id, (int) $qid, $source, $now, $avail_at ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Graded test → notebook + subject counts.
	 *
	 * @param array $a Attempt.
	 */
	public static function on_attempt( $a ) {
		if ( ! $a || 'submitted' !== $a['status'] ) {
			return;
		}
		$qs    = MHP_Tests::load_questions( $a['qids'], true );
		$map   = array();
		$avail = mhp_gmt();
		foreach ( $qs as $q ) {
			$map[ $q['id'] ] = (int) $q['answer'];
		}
		$info = MHP_Tests::info( (int) $a['test_id'] );
		if ( $info && $info['live'] ) {
			$until = $info['end'] ? MHP_Tests::norm_dt( $info['end'] ) : gmdate( 'Y-m-d H:i:s', strtotime( MHP_Tests::norm_dt( $info['start'] ) ) + ( $info['minutes'] + 10 ) * MINUTE_IN_SECONDS );
			$avail = max( $avail, mhp_gmt( ( new DateTime( $until, mhp_tz() ) )->getTimestamp() ) ); // $until is local time.
		}
		$subj  = self::subjects_of( array_keys( $map ) );
		$tally = array();
		foreach ( $a['qids'] as $i => $qid ) {
			if ( ! isset( $map[ $qid ] ) || ! array_key_exists( $i, $a['answers'] ) ) {
				continue;
			}
			$right = (int) $a['answers'][ $i ] === $map[ $qid ];
			if ( ! $right ) {
				self::record_mistake( (int) $a['user_id'], (int) $qid, 'test', $avail );
			}
			if ( isset( $subj[ $qid ] ) ) {
				$s = $subj[ $qid ];
				if ( ! isset( $tally[ $s ] ) ) {
					$tally[ $s ] = array( 0, 0 );
				}
				++$tally[ $s ][0];
				$tally[ $s ][1] += $right ? 1 : 0;
			}
		}
		self::bump_skills( (int) $a['user_id'], $tally );
	}

	/**
	 * Quiz answer → notebook + subject counts.
	 *
	 * @param int  $user_id User.
	 * @param int  $qid     Question.
	 * @param bool $right   Correct.
	 */
	public static function on_quiz_answer( $user_id, $qid, $right ) {
		if ( ! $right ) {
			self::record_mistake( $user_id, $qid, 'quiz', mhp_gmt() );
		}
		$subj = self::subjects_of( array( $qid ) );
		if ( isset( $subj[ $qid ] ) ) {
			self::bump_skills( $user_id, array( $subj[ $qid ] => array( 1, $right ? 1 : 0 ) ) );
		}
	}

	/* ---------------- reading ---------------- */

	/**
	 * Open notebook rows the reader may see now.
	 *
	 * @param int $user_id User.
	 * @param int $subject Subject term (0 = all).
	 * @param int $page    Page.
	 * @return array items (rows), total
	 */
	public static function mistakes( $user_id, $subject = 0, $page = 1 ) {
		global $wpdb;
		$t    = MHP_Install::table( 'mistakes' );
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT question_id, wrong_count, source, last_wrong FROM {$t} WHERE user_id = %d AND fixed_at IS NULL AND avail_at <= %s ORDER BY wrong_count DESC, last_wrong DESC LIMIT 500", (int) $user_id, mhp_gmt() ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( $rows && $subject ) {
			$subj = self::subjects_of( wp_list_pluck( $rows, 'question_id' ) );
			$rows = array_values(
				array_filter(
					$rows,
					function ( $r ) use ( $subj, $subject ) {
						return isset( $subj[ (int) $r['question_id'] ] ) && $subj[ (int) $r['question_id'] ] === (int) $subject;
					}
				)
			);
		}
		// Questions that were unpublished since are left out.
		$live = $rows ? array_map( 'intval', get_posts( array( 'post_type' => 'mh_question', 'post_status' => 'publish', 'post__in' => wp_list_pluck( $rows, 'question_id' ), 'posts_per_page' => 500, 'fields' => 'ids', 'no_found_rows' => true ) ) ) : array(); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing
		$rows = array_values(
			array_filter(
				$rows,
				function ( $r ) use ( $live ) {
					return in_array( (int) $r['question_id'], $live, true );
				}
			)
		);
		return array(
			'items' => array_slice( $rows, ( max( 1, $page ) - 1 ) * self::PER_PAGE, self::PER_PAGE ),
			'total' => count( $rows ),
		);
	}

	/**
	 * One open, visible notebook row.
	 *
	 * @param int $user_id User.
	 * @param int $qid     Question.
	 * @return array|null
	 */
	public static function mistake( $user_id, $qid ) {
		global $wpdb;
		$t = MHP_Install::table( 'mistakes' );
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE user_id = %d AND question_id = %d AND fixed_at IS NULL AND avail_at <= %s", (int) $user_id, (int) $qid, mhp_gmt() ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $r ? $r : null;
	}

	/**
	 * Count of open, visible mistakes.
	 *
	 * @param int $user_id User.
	 * @return int
	 */
	public static function open_count( $user_id ) {
		global $wpdb;
		$t = MHP_Install::table( 'mistakes' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE user_id = %d AND fixed_at IS NULL AND avail_at <= %s", (int) $user_id, mhp_gmt() ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Subject accuracy.
	 *
	 * @param int $user_id User.
	 * @return array[] id, name, seen, correct, pct (most practised first)
	 */
	public static function skills( $user_id ) {
		global $wpdb;
		$t    = MHP_Install::table( 'skills' );
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT subject_id, seen, correct FROM {$t} WHERE user_id = %d ORDER BY seen DESC LIMIT 30", (int) $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$out  = array();
		foreach ( $rows as $r ) {
			$term = get_term( (int) $r['subject_id'], 'mh_subject' );
			if ( ! $term || is_wp_error( $term ) || ! (int) $r['seen'] ) {
				continue;
			}
			$out[] = array(
				'id'      => (int) $term->term_id,
				'slug'    => $term->slug,
				'name'    => $term->name,
				'seen'    => (int) $r['seen'],
				'correct' => (int) $r['correct'],
				'pct'     => (int) round( (int) $r['correct'] / (int) $r['seen'] * 100 ),
			);
		}
		return $out;
	}

	/**
	 * Weakest subjects with enough answers to judge (at least 5).
	 *
	 * @param array $skills Skills.
	 * @param int   $n      How many.
	 * @return array[]
	 */
	public static function weak( $skills, $n = 3 ) {
		$w = array_values(
			array_filter(
				$skills,
				function ( $s ) {
					return $s['seen'] >= 5 && $s['pct'] < 70;
				}
			)
		);
		usort(
			$w,
			function ( $a, $b ) {
				return $a['pct'] - $b['pct'];
			}
		);
		return array_slice( $w, 0, $n );
	}

	/**
	 * Target exam saved by the reader.
	 *
	 * @param int $user_id User.
	 * @return array name, date, track (id), days
	 */
	public static function target( $user_id ) {
		$t = get_user_meta( $user_id, 'mhp_target', true );
		$t = is_array( $t ) ? $t : array();
		$t = wp_parse_args(
			$t,
			array(
				'name'  => '',
				'date'  => '',
				'track' => 0,
			)
		);
		$t['days'] = mhp_is_date( $t['date'] ) ? mhp_days_left( $t['date'] ) : null;
		return $t;
	}

	/**
	 * A free test of the target track the reader has not taken yet.
	 *
	 * @param int $user_id User.
	 * @param int $track   Track (0 = any).
	 * @return array|null Test info.
	 */
	public static function next_test( $user_id, $track ) {
		$taken = MHP_Points::refs( $user_id, 'test' );
		foreach ( MHP_Tests::list_tests( (int) $track ) as $t ) {
			if ( 'upcoming' === $t['state'] || in_array( (string) $t['id'], $taken, true ) ) {
				continue;
			}
			if ( MHP_Access::can_take_test( $user_id, $t['id'] ) ) {
				return $t;
			}
		}
		return null;
	}

	/**
	 * Everything the dashboard shows.
	 *
	 * @param int $uid User.
	 * @return array
	 */
	public static function dashboard( $uid ) {
		$total  = MHP_Points::total( $uid );
		$target = self::target( $uid );
		$skills = self::skills( $uid );
		$today  = mhp_today();
		$play   = MHP_Quiz::play( $uid, $today );
		$lesson = MHP_Lessons::today( (int) $target['track'] );

		$jobs = array();
		foreach ( MHP_Jobs::saved( $uid ) as $jid ) {
			$j = MHP_Jobs::data( $jid );
			if ( $j && 'publish' === $j['status'] && null !== $j['days_left'] && $j['days_left'] >= 0 ) {
				$jobs[] = $j;
			}
		}
		usort(
			$jobs,
			function ( $a, $b ) {
				return $a['days_left'] - $b['days_left'];
			}
		);
		$sessions = array();
		if ( class_exists( 'MHP_Consult' ) ) {
			foreach ( MHP_Consult::of_user( $uid ) as $b ) {
				if ( 'confirmed' === $b['status'] ) {
					$sessions[] = $b;
				}
			}
		}
		$events = array();
		foreach ( MHP_Access::of_user( $uid, 'event' ) as $row ) {
			$e = MHP_Events::data( (int) $row['item_id'] );
			if ( $e && mhp_is_date( $e['date'] ) && mhp_days_left( $e['date'] ) >= 0 ) {
				$events[] = $e;
			}
		}
		$fix_today = MHP_Points::count( $uid, 'fix', $today, $today );
		$open      = self::open_count( $uid );
		return array(
			'total'      => $total,
			'level'      => MHP_Points::level( $total ),
			'week'       => MHP_Points::between( $uid, mhp_week_start(), $today ),
			'rank'       => MHP_Points::week_rank( $uid ),
			'goal'       => MHP_Points::goal( $uid ),
			'today'      => MHP_Points::today( $uid ),
			'streak'     => MHP_Quiz::streak( $uid ),
			'quiz_done'  => $play && ! empty( $play['finished_at'] ),
			'quiz_ready' => (bool) MHP_Quiz::set_for( $today ),
			'lesson'     => $lesson,
			'lesson_ok'  => $lesson ? MHP_Points::has( $uid, 'lesson', (string) $lesson['id'] ) : false,
			'next_test'  => self::next_test( $uid, (int) $target['track'] ),
			'test_today' => MHP_Points::count( $uid, 'test', $today, $today ) > 0,
			'open'       => $open,
			'fix_today'  => $fix_today,
			'challenges' => MHP_Points::challenges( $uid ),
			'target'     => $target,
			'skills'     => array_slice( $skills, 0, 8 ),
			'weak'       => self::weak( $skills ),
			'jobs'       => array_slice( $jobs, 0, 3 ),
			'sessions'   => array_slice( $sessions, 0, 2 ),
			'events'     => array_slice( $events, 0, 2 ),
			'badges'     => MHP_Points::badges( $uid ),
			'recent'     => MHP_Points::recent( $uid, 6 ),
		);
	}

	/* ---------------- views ---------------- */

	/**
	 * Mistake notebook.
	 *
	 * @param string $id Unused.
	 */
	public static function render_mistakes( $id ) {
		if ( ! is_user_logged_in() ) {
			MHP_Front::login_box( 'আপনার ভুলের খাতা দেখতে লগইন করুন।' );
			return;
		}
		$uid  = get_current_user_id();
		$subj = isset( $_GET['subj'] ) ? absint( $_GET['subj'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$pg   = isset( $_GET['pg'] ) ? max( 1, absint( $_GET['pg'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$res  = self::mistakes( $uid, $subj, $pg );
		if ( ! $res['items'] && $pg > 1 && $res['total'] ) {
			$pg  = (int) ceil( $res['total'] / self::PER_PAGE );
			$res = self::mistakes( $uid, $subj, $pg );
		}
		$last = get_transient( 'mhp_mk_' . $uid );
		if ( $last ) {
			delete_transient( 'mhp_mk_' . $uid );
		}
		mhp_template(
			'mistakes',
			array(
				'res'    => $res,
				'qs'     => MHP_Tests::load_questions( wp_list_pluck( $res['items'], 'question_id' ), false ),
				'subj'   => $subj,
				'pg'     => $pg,
				'skills' => self::skills( $uid ),
				'last'   => is_array( $last ) ? $last : null,
				'fixed'  => MHP_Points::count( $uid, 'fix' ),
			)
		);
	}

	/* ---------------- handlers ---------------- */

	/**
	 * Back to the notebook keeping the filter and page.
	 *
	 * @return string
	 */
	private static function back() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by the dispatcher.
		$args = array();
		if ( ! empty( $_POST['subj'] ) ) {
			$args['subj'] = absint( $_POST['subj'] );
		}
		if ( ! empty( $_POST['pg'] ) && absint( $_POST['pg'] ) > 1 ) {
			$args['pg'] = absint( $_POST['pg'] );
		}
		// phpcs:enable
		return mhp_url( 'mistakes', '', $args );
	}

	/**
	 * Answer a notebook question again. Correct → fixed (+1 point once per question); wrong → the answer and the
	 * explanation are shown, and the question stays for another day.
	 */
	public static function handle_try() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by the dispatcher.
		$qid    = isset( $_POST['q'] ) ? absint( $_POST['q'] ) : 0;
		$choice = isset( $_POST['choice'] ) ? (int) $_POST['choice'] : -1;
		// phpcs:enable
		$uid = get_current_user_id();
		$row = self::mistake( $uid, $qid );
		$q   = $row ? MHP_Tests::load_questions( array( $qid ), true ) : array();
		if ( ! $row || ! $q || $choice < 0 || $choice > 3 ) {
			mhp_flash( 'প্রশ্নটি খাতায় পাওয়া যায়নি।', 'error' );
			wp_safe_redirect( self::back() );
			exit;
		}
		if ( ! mhp_rate_ok( 'mistake_try', 120, HOUR_IN_SECONDS ) ) {
			mhp_flash( 'অল্প সময়ে অনেক উত্তর হয়েছে। একটু পরে আবার চেষ্টা করুন।', 'error' );
			wp_safe_redirect( self::back() );
			exit;
		}
		global $wpdb;
		$right = (int) $q[0]['answer'] === $choice;
		$t     = MHP_Install::table( 'mistakes' );
		if ( $right ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET fixed_at = %s WHERE id = %d AND fixed_at IS NULL", mhp_gmt(), (int) $row['id'] ) ); // phpcs:ignore WordPress.DB
			MHP_Points::award( $uid, 'fix', (string) $qid, MHP_Points::rules()['fix'] );
			mhp_flash( 'সঠিক! প্রশ্নটি খাতা থেকে সরানো হলো।' );
		} else {
			$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET wrong_count = wrong_count + 1, last_wrong = %s WHERE id = %d", mhp_gmt(), (int) $row['id'] ) ); // phpcs:ignore WordPress.DB
			set_transient(
				'mhp_mk_' . $uid,
				array(
					'q'       => $qid,
					'c'       => $choice,
					'answer'  => (int) $q[0]['answer'],
					'explain' => (string) $q[0]['explain'],
				),
				10 * MINUTE_IN_SECONDS
			);
		}
		$subj = self::subjects_of( array( $qid ) );
		if ( isset( $subj[ $qid ] ) ) {
			self::bump_skills( $uid, array( $subj[ $qid ] => array( 1, $right ? 1 : 0 ) ) );
		}
		wp_safe_redirect( self::back() . ( $right ? '' : '#q-' . $qid ) );
		exit;
	}

	/**
	 * Remove a question from the notebook without answering.
	 */
	public static function handle_drop() {
		global $wpdb;
		$qid = isset( $_POST['q'] ) ? absint( $_POST['q'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by the dispatcher.
		$t   = MHP_Install::table( 'mistakes' );
		// Dropped rows are kept as "fixed" without points, so they come back only if answered wrong again.
		$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET fixed_at = %s WHERE user_id = %d AND question_id = %d AND fixed_at IS NULL", mhp_gmt(), get_current_user_id(), $qid ) ); // phpcs:ignore WordPress.DB
		mhp_flash( 'খাতা থেকে সরানো হলো।' );
		wp_safe_redirect( self::back() );
		exit;
	}

	/**
	 * Daily goal.
	 */
	public static function handle_goal() {
		$g = isset( $_POST['goal'] ) ? absint( $_POST['goal'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by the dispatcher.
		if ( in_array( $g, MHP_Points::GOALS, true ) ) {
			update_user_meta( get_current_user_id(), 'mhp_goal', $g );
			MHP_Points::check_goal( get_current_user_id() );
			mhp_flash( 'দৈনিক লক্ষ্য ' . mhp_bn( $g ) . ' পয়েন্ট করা হলো।' );
		}
		wp_safe_redirect( mhp_url( 'me' ) );
		exit;
	}

	/**
	 * Target exam (name, date, track).
	 */
	public static function handle_target() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by the dispatcher.
		$name  = isset( $_POST['t_name'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_POST['t_name'] ) ), 0, 80 ) : '';
		$date  = isset( $_POST['t_date'] ) ? sanitize_text_field( wp_unslash( $_POST['t_date'] ) ) : '';
		$track = isset( $_POST['t_track'] ) ? absint( $_POST['t_track'] ) : 0;
		$clear = ! empty( $_POST['t_clear'] );
		// phpcs:enable
		$uid = get_current_user_id();
		if ( $clear ) {
			delete_user_meta( $uid, 'mhp_target' );
			mhp_flash( 'লক্ষ্য পরীক্ষা মুছে ফেলা হলো।' );
			wp_safe_redirect( mhp_url( 'me' ) );
			exit;
		}
		$date = meeha_prep_en_digits( $date );
		if ( '' !== $date && ( ! mhp_is_date( $date ) || mhp_days_left( $date ) < 0 || mhp_days_left( $date ) > 1100 ) ) {
			mhp_flash( 'পরীক্ষার তারিখ আজ বা সামনের কোনো দিন দিন (তিন বছরের মধ্যে)।', 'error' );
			wp_safe_redirect( mhp_url( 'me' ) . '#mc-target' );
			exit;
		}
		if ( $track && ! term_exists( $track, 'mh_track' ) ) {
			$track = 0;
		}
		update_user_meta(
			$uid,
			'mhp_target',
			array(
				'name'  => $name,
				'date'  => $date,
				'track' => $track,
			)
		);
		mhp_flash( 'লক্ষ্য পরীক্ষা সংরক্ষিত হয়েছে।' );
		wp_safe_redirect( mhp_url( 'me' ) );
		exit;
	}
}
