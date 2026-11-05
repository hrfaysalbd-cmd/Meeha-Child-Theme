<?php
/**
 * Daily quiz. Each day's set is chosen once (editor override, else a date-seeded pick from questions marked
 * "কুইজে ব্যবহারযোগ্য", avoiding the last 14 days) and frozen in an option so it never changes mid-day.
 * Logged-in answers are locked on first pick (no retry for the leaderboard); guests play without a record.
 *
 * @package meeha-prep
 */

defined( 'ABSPATH' ) || exit;

/**
 * Quiz.
 */
class MHP_Quiz {

	/**
	 * Fewest seconds per question a finished quiz needs to be ranked. Faster than this means the answers were
	 * known in advance (copied, scripted), so the play still counts for the reader but not on leaderboards or prizes.
	 */
	const MIN_SECONDS = 3;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'mhp_hub_action_quiz_answer', array( __CLASS__, 'handle_answer' ) );
	}

	/**
	 * Question ids for a date.
	 *
	 * @param string $date Y-m-d.
	 * @return int[]
	 */
	public static function set_for( $date ) {
		$over = get_option( 'mhp_quiz_override', array() );
		if ( is_array( $over ) && ! empty( $over[ $date ] ) ) {
			return array_values( array_filter( array_map( 'intval', (array) $over[ $date ] ) ) );
		}
		$sets = get_option( 'mhp_quiz_sets', array() );
		$sets = is_array( $sets ) ? $sets : array();
		if ( ! empty( $sets[ $date ] ) ) {
			return array_map( 'intval', (array) $sets[ $date ] );
		}
		$ids = self::pick( $date, $sets );
		if ( $ids && $date <= mhp_today() ) {
			$sets[ $date ] = $ids;
			krsort( $sets );
			$sets = array_slice( $sets, 0, 40, true );
			update_option( 'mhp_quiz_sets', $sets, false );
		}
		return $ids;
	}

	/**
	 * Date-seeded pick.
	 *
	 * @param string $date Date.
	 * @param array  $sets Previous sets.
	 * @return int[]
	 */
	public static function pick( $date, $sets ) {
		$n   = max( 3, min( 15, (int) mhp_opt( 'quiz_count', 5 ) ) );
		$all = get_posts(
			array(
				'post_type'      => 'mh_question',
				'post_status'    => 'publish',
				'posts_per_page' => 3000,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => '_mhp_quiz_ok', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		$all = array_map( 'intval', $all );
		if ( count( $all ) < $n ) {
			return array();
		}
		$recent = array();
		$limit  = gmdate( 'Y-m-d', strtotime( $date . ' -14 days' ) );
		foreach ( $sets as $d => $ids ) {
			if ( $d < $date && $d >= $limit ) {
				$recent = array_merge( $recent, array_map( 'intval', (array) $ids ) );
			}
		}
		$pool = array_values( array_diff( $all, $recent ) );
		if ( count( $pool ) < $n ) {
			$pool = $all;
		}
		usort(
			$pool,
			function ( $a, $b ) use ( $date ) {
				return strcmp( md5( $date . '|' . $a ), md5( $date . '|' . $b ) );
			}
		);
		return array_slice( $pool, 0, $n );
	}

	/**
	 * Today's questions without answers.
	 *
	 * @return array[]
	 */
	public static function today_questions() {
		return MHP_Tests::load_questions( self::set_for( mhp_today() ), false );
	}

	/**
	 * A user's row for a date.
	 *
	 * @param int    $user_id User.
	 * @param string $date    Date.
	 * @return array|null
	 */
	public static function play( $user_id, $date ) {
		global $wpdb;
		if ( ! $user_id ) {
			return null;
		}
		$t = MHP_Install::table( 'quiz' );
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE quiz_date = %s AND user_id = %d", $date, (int) $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( $r ) {
			$r['answers_raw'] = (string) $r['answers'];
			$r['answers']     = array_map( 'intval', (array) json_decode( (string) $r['answers'], true ) );
		}
		return $r ? $r : null;
	}

	/**
	 * Start today's clock for a signed-in player when the quiz page is first opened (not at the first answer),
	 * so the time on the leaderboard includes reading the questions.
	 *
	 * @param int $user_id User.
	 */
	public static function begin( $user_id ) {
		global $wpdb;
		$ids = $user_id ? self::set_for( mhp_today() ) : array();
		if ( ! $ids || self::play( $user_id, mhp_today() ) ) {
			return;
		}
		$t = MHP_Install::table( 'quiz' );
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$t} (quiz_date, user_id, answers, total, started_at) VALUES (%s, %d, %s, %d, %s)", mhp_today(), (int) $user_id, '{}', count( $ids ), mhp_gmt() ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Answer question $i of today's quiz.
	 *
	 * @param int $user_id User (0 = guest).
	 * @param int $i       Index.
	 * @param int $choice  0..3.
	 * @return array|WP_Error correct, answer, explain, locked, done, score, total, chosen
	 */
	public static function answer( $user_id, $i, $choice ) {
		global $wpdb;
		$date = mhp_today();
		$ids  = self::set_for( $date );
		$i    = (int) $i;
		if ( ! isset( $ids[ $i ] ) || $choice < 0 || $choice > 3 ) {
			return new WP_Error( 'mhp_quiz', 'প্রশ্নটি পাওয়া যায়নি।' );
		}
		if ( ! $user_id && ! mhp_opt( 'quiz_guest', 0 ) ) {
			return new WP_Error( 'mhp_login', 'কুইজ খেলতে লগইন করুন।' );
		}
		$q = MHP_Tests::load_questions( array( $ids[ $i ] ), true );
		if ( ! $q ) {
			return new WP_Error( 'mhp_quiz', 'প্রশ্নটি পাওয়া যায়নি।' );
		}
		$q      = $q[0];
		$total  = count( $ids );
		$locked = false;
		$score  = null;
		$done   = false;
		if ( $user_id ) {
			$t   = MHP_Install::table( 'quiz' );
			$row = self::play( $user_id, $date );
			if ( ! $row ) {
				$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$t} (quiz_date, user_id, answers, total, started_at) VALUES (%s, %d, %s, %d, %s)", $date, (int) $user_id, '{}', $total, mhp_gmt() ) ); // phpcs:ignore WordPress.DB
				$row = self::play( $user_id, $date );
			}
			if ( ! $row ) {
				return new WP_Error( 'mhp_db', 'উত্তর সংরক্ষণ করা যায়নি।' );
			}
			$ans = $row['answers'];
			if ( array_key_exists( $i, $ans ) ) {
				$locked = true;
				$choice = (int) $ans[ $i ];
			} else {
				$ans[ $i ] = (int) $choice;
				ksort( $ans );
				$correct = 0;
				foreach ( $ans as $k => $v ) {
					if ( isset( $ids[ $k ] ) ) {
						$qq = ( (int) $k === $i ) ? array( $q ) : MHP_Tests::load_questions( array( $ids[ $k ] ), true );
						if ( $qq && (int) $qq[0]['answer'] === (int) $v ) {
							++$correct;
						}
					}
				}
				$fields = array(
					'answers'  => wp_json_encode( (object) $ans ),
					'correct'  => $correct,
					'answered' => count( $ans ),
				);
				$formats = array( '%s', '%d', '%d' );
				if ( count( $ans ) >= $total && empty( $row['finished_at'] ) ) {
					$fields['finished_at'] = mhp_gmt();
					$fields['seconds']     = max( 1, time() - strtotime( $row['started_at'] . ' UTC' ) );
					$formats[]             = '%s';
					$formats[]             = '%d';
				}
				// Only if nobody wrote in between (two answers at once): otherwise the first answer could be lost and re-asked.
				$ok = $wpdb->update( $t, $fields, array( 'id' => (int) $row['id'], 'answers' => $row['answers_raw'] ), $formats, array( '%d', '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				if ( ! $ok ) {
					return new WP_Error( 'mhp_busy', 'উত্তর সংরক্ষণ হয়নি, আবার চাপুন।' );
				}
				do_action( 'mhp_quiz_answered', (int) $user_id, (int) $ids[ $i ], (int) $choice === (int) $q['answer'], $date );
				if ( isset( $fields['finished_at'] ) ) {
					self::bump_streak( $user_id, $date );
				}
				$row = self::play( $user_id, $date );
				if ( isset( $fields['finished_at'] ) ) {
					do_action( 'mhp_quiz_finished', (int) $user_id, $date, $row );
				}
			}
			$score = (int) $row['correct'];
			$done  = ! empty( $row['finished_at'] );
		}
		return array(
			'i'       => $i,
			'chosen'  => (int) $choice,
			'correct' => (int) $choice === (int) $q['answer'],
			'answer'  => (int) $q['answer'],
			'explain' => $q['explain'],
			'locked'  => $locked,
			'done'    => $done,
			'score'   => $score,
			'total'   => $total,
		);
	}

	/**
	 * Streak: +1 when yesterday was played, else 1.
	 *
	 * @param int    $user_id User.
	 * @param string $date    Today.
	 */
	private static function bump_streak( $user_id, $date ) {
		$last = (string) get_user_meta( $user_id, 'mhp_last_quiz', true );
		if ( $last === $date ) {
			return;
		}
		$yday   = gmdate( 'Y-m-d', strtotime( $date . ' -1 day' ) );
		$streak = $last === $yday ? (int) get_user_meta( $user_id, 'mhp_streak', true ) + 1 : 1;
		update_user_meta( $user_id, 'mhp_streak', $streak );
		update_user_meta( $user_id, 'mhp_last_quiz', $date );
		$best = (int) get_user_meta( $user_id, 'mhp_streak_best', true );
		if ( $streak > $best ) {
			update_user_meta( $user_id, 'mhp_streak_best', $streak );
		}
	}

	/**
	 * Current streak (0 when the chain is broken).
	 *
	 * @param int $user_id User.
	 * @return int
	 */
	public static function streak( $user_id ) {
		if ( ! $user_id ) {
			return 0;
		}
		$last = (string) get_user_meta( $user_id, 'mhp_last_quiz', true );
		$yday = gmdate( 'Y-m-d', strtotime( mhp_today() . ' -1 day' ) );
		if ( $last !== mhp_today() && $last !== $yday ) {
			return 0;
		}
		return (int) get_user_meta( $user_id, 'mhp_streak', true );
	}

	/**
	 * Finished players for a date.
	 *
	 * @param string $date Date.
	 * @return int
	 */
	public static function played_count( $date ) {
		global $wpdb;
		$t = MHP_Install::table( 'quiz' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE quiz_date = %s AND finished_at IS NOT NULL", $date ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Leaderboard.
	 *
	 * @param string $range today|week (from Saturday)|month.
	 * @param int    $limit Rows.
	 * @return array[] user_id, name, district, correct, seconds, total
	 */
	public static function leaderboard( $range = 'today', $limit = 20 ) {
		global $wpdb;
		$t     = MHP_Install::table( 'quiz' );
		$today = mhp_today();
		if ( 'week' === $range || 'month' === $range ) {
			$from = 'week' === $range ? mhp_week_start( $today ) : substr( $today, 0, 8 ) . '01';
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, SUM(correct) AS correct, SUM(seconds) AS seconds, SUM(total) AS total, COUNT(*) AS days FROM {$t} WHERE quiz_date BETWEEN %s AND %s AND finished_at IS NOT NULL AND seconds >= total * %d GROUP BY user_id ORDER BY correct DESC, seconds ASC LIMIT %d", $from, $today, self::MIN_SECONDS, (int) $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, correct, seconds, total, 1 AS days FROM {$t} WHERE quiz_date = %s AND finished_at IS NOT NULL AND seconds >= total * %d ORDER BY correct DESC, seconds ASC, id ASC LIMIT %d", $today, self::MIN_SECONDS, (int) $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB
		}
		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[] = array(
				'user_id'  => (int) $r['user_id'],
				'name'     => mhp_public_name( (int) $r['user_id'] ),
				'district' => (string) get_user_meta( (int) $r['user_id'], 'mhp_district', true ),
				'correct'  => (int) $r['correct'],
				'seconds'  => (int) $r['seconds'],
				'total'    => (int) $r['total'],
				'days'     => (int) $r['days'],
			);
		}
		return $out;
	}

	/**
	 * Hub POST fallback (no JavaScript).
	 */
	public static function handle_answer() {
		$i      = isset( $_POST['i'] ) ? absint( $_POST['i'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified (or guest) by the dispatcher.
		$choice = isset( $_POST['choice'] ) ? (int) $_POST['choice'] : -1; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$res    = self::answer( get_current_user_id(), $i, $choice );
		$args   = array();
		if ( is_wp_error( $res ) ) {
			mhp_flash( $res->get_error_message(), 'error' );
		} else {
			$args = array(
				'q' => $i,
				'c' => (int) $res['chosen'],
			);
		}
		wp_safe_redirect( mhp_url( 'quiz', '', $args ) );
		exit;
	}
}
