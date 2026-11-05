<?php
/**
 * Points, levels, badges, weekly challenges and the daily goal.
 *
 * Every point is a row in {prefix}mhp_points with a unique (user, source, ref), so the same lesson, test, quiz day
 * or fixed mistake can never be counted twice, whatever the browser sends. Points come only from the server's own
 * events (graded test, finished quiz, completed lesson, fixed mistake), never from a number the reader posts.
 *
 * Sources: quiz (ref = date), test (ref = test id, first submitted attempt only), lesson (ref = lesson id),
 * fix (ref = question id), streak (ref = s7-/s30-<date>), goal (ref = date), challenge (ref = <key>-<week start>).
 *
 * @package meeha-prep
 */

defined( 'ABSPATH' ) || exit;

/**
 * Points ledger.
 */
class MHP_Points {

	const GOALS = array( 10, 20, 40, 60 );

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'mhp_attempt_submitted', array( __CLASS__, 'on_attempt' ) );
		add_action( 'mhp_quiz_finished', array( __CLASS__, 'on_quiz' ), 10, 2 );
	}

	/**
	 * Point values (settings decide the ones an editor may tune).
	 *
	 * @return array
	 */
	public static function rules() {
		return array(
			'quiz_correct' => 2,
			'quiz_done'    => 5,
			'test_done'    => 10,
			'test_cap'     => 100,
			'lesson'       => max( 0, (int) mhp_opt( 'pt_lesson', 5 ) ),
			'fix'          => 1,
			'streak7'      => 20,
			'streak30'     => 100,
			'goal'         => 5,
		);
	}

	/**
	 * Labels for the activity list.
	 *
	 * @return array
	 */
	public static function sources() {
		return array(
			'quiz'      => 'দৈনিক কুইজ',
			'test'      => 'মডেল টেস্ট',
			'lesson'    => 'পাঠ শেষ',
			'fix'       => 'ভুল শুধরানো',
			'streak'    => 'টানা কুইজের বোনাস',
			'goal'      => 'দৈনিক লক্ষ্য পূরণ',
			'challenge' => 'চ্যালেঞ্জ জয়',
		);
	}

	/**
	 * Add points once. Returns true only when a new row was written.
	 *
	 * @param int    $user_id User.
	 * @param string $source  Source key.
	 * @param string $ref     Reference (unique per user+source).
	 * @param int    $points  Points (> 0).
	 * @return bool
	 */
	public static function award( $user_id, $source, $ref, $points ) {
		global $wpdb;
		$user_id = (int) $user_id;
		$points  = (int) $points;
		if ( ! $user_id || $points <= 0 || ! isset( self::sources()[ $source ] ) ) {
			return false;
		}
		$t = MHP_Install::table( 'points' );
		$n = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$t} (user_id, source, ref, points, day, created_at) VALUES (%d, %s, %s, %d, %s, %s)", $user_id, $source, substr( (string) $ref, 0, 40 ), $points, mhp_today(), mhp_gmt() ) ); // phpcs:ignore WordPress.DB
		if ( 1 !== (int) $n ) {
			return false;
		}
		update_user_meta( $user_id, 'mhp_points', self::sum( $user_id ) );
		do_action( 'mhp_points_awarded', $user_id, $source, $ref, $points );
		if ( ! in_array( $source, array( 'goal', 'challenge' ), true ) ) {
			self::check_goal( $user_id );
			self::check_challenges( $user_id );
		}
		return true;
	}

	/**
	 * Total from the ledger.
	 *
	 * @param int $user_id User.
	 * @return int
	 */
	public static function sum( $user_id ) {
		global $wpdb;
		$t = MHP_Install::table( 'points' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(points),0) FROM {$t} WHERE user_id = %d", (int) $user_id ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Total (cached in user meta, rebuilt when missing).
	 *
	 * @param int $user_id User.
	 * @return int
	 */
	public static function total( $user_id ) {
		if ( ! $user_id ) {
			return 0;
		}
		$v = get_user_meta( (int) $user_id, 'mhp_points', true );
		if ( '' === $v ) {
			$v = self::sum( $user_id );
			update_user_meta( (int) $user_id, 'mhp_points', $v );
		}
		return (int) $v;
	}

	/**
	 * Points between two dates (inclusive), optionally without some sources.
	 *
	 * @param int      $user_id User.
	 * @param string   $from    Y-m-d.
	 * @param string   $to      Y-m-d.
	 * @param string[] $without Sources to leave out.
	 * @return int
	 */
	public static function between( $user_id, $from, $to, $without = array() ) {
		global $wpdb;
		$t   = MHP_Install::table( 'points' );
		$sql = "SELECT COALESCE(SUM(points),0) FROM {$t} WHERE user_id = %d AND day BETWEEN %s AND %s";
		$arg = array( (int) $user_id, $from, $to );
		foreach ( $without as $w ) {
			$sql  .= ' AND source <> %s';
			$arg[] = $w;
		}
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $arg ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Count of ledger rows of a source between two dates (challenge progress).
	 *
	 * @param int    $user_id User.
	 * @param string $source  Source.
	 * @param string $from    Y-m-d ('' = ever).
	 * @param string $to      Y-m-d.
	 * @return int
	 */
	public static function count( $user_id, $source, $from = '', $to = '' ) {
		global $wpdb;
		$t = MHP_Install::table( 'points' );
		if ( '' === $from ) {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE user_id = %d AND source = %s", (int) $user_id, $source ) ); // phpcs:ignore WordPress.DB
		}
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE user_id = %d AND source = %s AND day BETWEEN %s AND %s", (int) $user_id, $source, $from, $to ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Has a ledger row?
	 *
	 * @param int    $user_id User.
	 * @param string $source  Source.
	 * @param string $ref     Ref.
	 * @return bool
	 */
	public static function has( $user_id, $source, $ref ) {
		global $wpdb;
		if ( ! $user_id ) {
			return false;
		}
		$t = MHP_Install::table( 'points' );
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE user_id = %d AND source = %s AND ref = %s", (int) $user_id, $source, (string) $ref ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Refs of a source a user holds (e.g. completed lessons).
	 *
	 * @param int    $user_id User.
	 * @param string $source  Source.
	 * @return string[]
	 */
	public static function refs( $user_id, $source ) {
		global $wpdb;
		if ( ! $user_id ) {
			return array();
		}
		$t = MHP_Install::table( 'points' );
		return array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ref FROM {$t} WHERE user_id = %d AND source = %s", (int) $user_id, $source ) ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Latest rows.
	 *
	 * @param int $user_id User.
	 * @param int $limit   Rows.
	 * @return array[]
	 */
	public static function recent( $user_id, $limit = 8 ) {
		global $wpdb;
		$t = MHP_Install::table( 'points' );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT source, ref, points, day FROM {$t} WHERE user_id = %d ORDER BY id DESC LIMIT %d", (int) $user_id, (int) $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/* ---------------- levels and badges ---------------- */

	/**
	 * Level ladder: [points needed, name].
	 *
	 * @return array[]
	 */
	public static function levels() {
		return array(
			array( 0, 'নবীন' ),
			array( 100, 'অনুশীলনকারী' ),
			array( 300, 'নিয়মিত' ),
			array( 700, 'দক্ষ' ),
			array( 1500, 'অভিজ্ঞ' ),
			array( 3000, 'চ্যাম্পিয়ন' ),
		);
	}

	/**
	 * Level for a total.
	 *
	 * @param int $points Points.
	 * @return array n (1-based), name, next (points needed for the next level, 0 at the top), pct (to next), left
	 */
	public static function level( $points ) {
		$ladder = self::levels();
		$i      = 0;
		foreach ( $ladder as $k => $l ) {
			if ( $points >= $l[0] ) {
				$i = $k;
			}
		}
		$next = isset( $ladder[ $i + 1 ] ) ? $ladder[ $i + 1 ][0] : 0;
		$base = $ladder[ $i ][0];
		return array(
			'n'    => $i + 1,
			'name' => $ladder[ $i ][1],
			'next' => $next,
			'left' => $next ? $next - $points : 0,
			'pct'  => $next ? (int) floor( ( $points - $base ) / max( 1, $next - $base ) * 100 ) : 100,
			'up'   => $next && isset( $ladder[ $i + 1 ] ) ? $ladder[ $i + 1 ][1] : '',
		);
	}

	/**
	 * Badges (worked out from the ledger and a few counters; nothing to forge).
	 *
	 * @param int $user_id User.
	 * @return array[] key, label, desc, on
	 */
	public static function badges( $user_id ) {
		$best = (int) get_user_meta( $user_id, 'mhp_streak_best', true );
		$list = array(
			array( 'first_test', 'প্রথম পদক্ষেপ', 'প্রথম মডেল টেস্ট শেষ', self::count( $user_id, 'test' ) >= 1 ),
			array( 'quiz_7', 'সাত দিনের ধারা', 'টানা ৭ দিন কুইজ', $best >= 7 ),
			array( 'quiz_30', 'মাসজুড়ে অটল', 'টানা ৩০ দিন কুইজ', $best >= 30 ),
			array( 'quiz_50', 'কুইজপ্রেমী', '৫০ দিন কুইজ শেষ', self::count( $user_id, 'quiz' ) >= 50 ),
			array( 'lesson_10', 'পাঠে মনোযোগী', '১০টি পাঠ শেষ', self::count( $user_id, 'lesson' ) >= 10 ),
			array( 'fix_25', 'ভুল থেকে শেখা', '২৫টি ভুল শুধরেছেন', self::count( $user_id, 'fix' ) >= 25 ),
			array( 'perfect', 'নিখুঁত', '১০+ প্রশ্নের টেস্টে সব সঠিক', (bool) get_user_meta( $user_id, 'mhp_perfect', true ) ),
			array( 'challenger', 'চ্যালেঞ্জার', '৫টি সাপ্তাহিক চ্যালেঞ্জ জয়', self::count( $user_id, 'challenge' ) >= 5 ),
		);
		$out = array();
		foreach ( $list as $b ) {
			$out[] = array(
				'key'   => $b[0],
				'label' => $b[1],
				'desc'  => $b[2],
				'on'    => (bool) $b[3],
			);
		}
		return $out;
	}

	/* ---------------- daily goal ---------------- */

	/**
	 * The reader's daily goal (points).
	 *
	 * @param int $user_id User.
	 * @return int
	 */
	public static function goal( $user_id ) {
		$g = (int) get_user_meta( $user_id, 'mhp_goal', true );
		return in_array( $g, self::GOALS, true ) ? $g : 20;
	}

	/**
	 * Points counted toward today's goal.
	 *
	 * @param int $user_id User.
	 * @return int
	 */
	public static function today( $user_id ) {
		return self::between( $user_id, mhp_today(), mhp_today(), array( 'goal', 'challenge' ) );
	}

	/**
	 * Award the goal bonus once a day.
	 *
	 * @param int $user_id User.
	 */
	public static function check_goal( $user_id ) {
		if ( self::today( $user_id ) >= self::goal( $user_id ) ) {
			self::award( $user_id, 'goal', mhp_today(), self::rules()['goal'] );
		}
	}

	/* ---------------- weekly challenges ---------------- */

	/**
	 * This week's challenges with the reader's progress.
	 *
	 * @param int $user_id User (0 = definitions only).
	 * @return array[] key, label, target, done, bonus, complete
	 */
	public static function challenges( $user_id ) {
		$from  = mhp_week_start();
		$to    = mhp_date_add( $from, 6 );
		$bonus = max( 0, (int) mhp_opt( 'ch_bonus', 20 ) );
		$defs  = array(
			'quiz'   => array( (int) mhp_opt( 'ch_quiz', 5 ), 'দিন দৈনিক কুইজ শেষ করুন' ),
			'test'   => array( (int) mhp_opt( 'ch_test', 2 ), 'টি নতুন মডেল টেস্ট দিন' ),
			'lesson' => array( (int) mhp_opt( 'ch_lesson', 3 ), 'টি পাঠ শেষ করুন' ),
			'fix'    => array( (int) mhp_opt( 'ch_fix', 10 ), 'টি ভুল প্রশ্ন শুধরে নিন' ),
		);
		if ( ! MHP_Lessons::any() ) {
			unset( $defs['lesson'] );
		}
		$out = array();
		foreach ( $defs as $k => $d ) {
			if ( $d[0] <= 0 ) {
				continue;
			}
			$done  = $user_id ? min( $d[0], self::count( $user_id, $k, $from, $to ) ) : 0;
			$out[] = array(
				'key'      => $k,
				'label'    => mhp_bn( $d[0] ) . $d[1],
				'target'   => $d[0],
				'done'     => $done,
				'bonus'    => $bonus,
				'complete' => $done >= $d[0],
				'won'      => $user_id && self::has( $user_id, 'challenge', $k . '-' . $from ),
			);
		}
		return $out;
	}

	/**
	 * Pay out finished challenges (once per challenge per week).
	 *
	 * @param int $user_id User.
	 */
	public static function check_challenges( $user_id ) {
		$from = mhp_week_start();
		foreach ( self::challenges( $user_id ) as $c ) {
			if ( $c['complete'] && $c['bonus'] > 0 ) {
				self::award( $user_id, 'challenge', $c['key'] . '-' . $from, $c['bonus'] );
			}
		}
	}

	/* ---------------- leaderboards ---------------- */

	/**
	 * Points leaderboard for this week (staff left out).
	 *
	 * @param int $limit Rows.
	 * @return array[] user_id, name, district, points
	 */
	public static function leaderboard_week( $limit = 20 ) {
		global $wpdb;
		$t    = MHP_Install::table( 'points' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, SUM(points) AS pts FROM {$t} WHERE day BETWEEN %s AND %s GROUP BY user_id ORDER BY pts DESC, MIN(id) ASC LIMIT %d", mhp_week_start(), mhp_today(), (int) $limit + 10 ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$out  = array();
		foreach ( (array) $rows as $r ) {
			if ( user_can( (int) $r['user_id'], 'edit_posts' ) ) {
				continue;
			}
			$out[] = array(
				'user_id'  => (int) $r['user_id'],
				'name'     => mhp_public_name( (int) $r['user_id'] ),
				'district' => (string) get_user_meta( (int) $r['user_id'], 'mhp_district', true ),
				'points'   => (int) $r['pts'],
			);
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * The reader's place in this week's points table (0 = no points yet).
	 *
	 * @param int $user_id User.
	 * @return int
	 */
	public static function week_rank( $user_id ) {
		global $wpdb;
		$from = mhp_week_start();
		$mine = self::between( $user_id, $from, mhp_today() );
		if ( $mine <= 0 ) {
			return 0;
		}
		$t = MHP_Install::table( 'points' );
		$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM (SELECT user_id, SUM(points) AS s FROM {$t} WHERE day BETWEEN %s AND %s GROUP BY user_id) x WHERE x.s > %d", $from, mhp_today(), $mine ) ); // phpcs:ignore WordPress.DB
		return $n + 1;
	}

	/* ---------------- event hooks ---------------- */

	/**
	 * Test submitted: points for the first submitted attempt of each test only.
	 *
	 * @param array $a Graded attempt.
	 */
	public static function on_attempt( $a ) {
		if ( ! $a || 'submitted' !== $a['status'] ) {
			return;
		}
		$uid   = (int) $a['user_id'];
		$total = max( 1, (int) $a['total'] );
		$ans   = (int) $a['correct'] + (int) $a['wrong'];
		$r     = self::rules();
		// Half the paper answered earns the finishing points; empty submissions earn nothing.
		$pts = ( $ans * 2 >= $total ? $r['test_done'] : 0 ) + min( $r['test_cap'], (int) $a['correct'] );
		if ( $total >= 10 && (int) $a['correct'] === $total ) {
			update_user_meta( $uid, 'mhp_perfect', 1 );
		}
		if ( $pts > 0 ) {
			self::award( $uid, 'test', (string) (int) $a['test_id'], $pts );
		}
	}

	/**
	 * Quiz finished: correct answers + finishing points, then streak bonuses.
	 *
	 * @param int    $user_id User.
	 * @param string $date    Quiz date.
	 */
	public static function on_quiz( $user_id, $date ) {
		$row = MHP_Quiz::play( $user_id, $date );
		if ( ! $row || empty( $row['finished_at'] ) ) {
			return;
		}
		$r = self::rules();
		self::award( $user_id, 'quiz', $date, (int) $row['correct'] * $r['quiz_correct'] + $r['quiz_done'] );
		$streak = MHP_Quiz::streak( $user_id );
		if ( $streak > 0 && 0 === $streak % 30 ) {
			self::award( $user_id, 'streak', 's30-' . $date, $r['streak30'] );
		} elseif ( $streak > 0 && 0 === $streak % 7 ) {
			self::award( $user_id, 'streak', 's7-' . $date, $r['streak7'] );
		}
	}
}
