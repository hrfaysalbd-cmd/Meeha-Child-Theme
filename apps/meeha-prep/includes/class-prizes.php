<?php
/**
 * Daily quiz prizes (optional, off by default). The desk picks a period (day, week from Saturday, or month), how
 * many winners, the prizes and the rules in settings. Once a period is over, প্রস্তুতি → কুইজ পুরস্কার shows the
 * ranking (most correct answers, then less time, then more days played), lets the desk disqualify someone, and
 * announces the winners. Announced winners show on the quiz and leaderboard pages.
 *
 * Entry is free (the quiz never costs money), so this is a free-entry contest, not a lottery. Staff are left out.
 *
 * @package meeha-prep
 */

defined( 'ABSPATH' ) || exit;

/**
 * Prizes.
 */
class MHP_Prizes {

	const OPT = 'mhp_prize_results';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'guest_notice' ) );
	}

	/**
	 * Prizes switched on?
	 *
	 * @return bool
	 */
	public static function on() {
		return (bool) mhp_opt( 'prize_on', 0 );
	}

	/**
	 * Period kind: day|week|month.
	 *
	 * @return string
	 */
	public static function kind() {
		$k = (string) mhp_opt( 'prize_period', 'week' );
		return in_array( $k, array( 'day', 'week', 'month' ), true ) ? $k : 'week';
	}

	/**
	 * The period a date falls in.
	 *
	 * @param string $date Y-m-d.
	 * @param string $kind day|week|month.
	 * @return array key, from, to, label, kind
	 */
	public static function period( $date, $kind = '' ) {
		$kind = $kind ? $kind : self::kind();
		if ( 'day' === $kind ) {
			return array(
				'key'   => 'd-' . $date,
				'from'  => $date,
				'to'    => $date,
				'label' => mhp_fdate( $date ),
				'kind'  => 'day',
			);
		}
		if ( 'month' === $kind ) {
			$from = substr( $date, 0, 8 ) . '01';
			$to   = ( new DateTime( $from, mhp_tz() ) )->modify( 'last day of this month' )->format( 'Y-m-d' );
			$m    = mhp_months();
			return array(
				'key'   => 'm-' . substr( $date, 0, 7 ),
				'from'  => $from,
				'to'    => $to,
				'label' => $m[ (int) substr( $date, 5, 2 ) ] . ' ' . mhp_bn( substr( $date, 0, 4 ) ),
				'kind'  => 'month',
			);
		}
		$from = mhp_week_start( $date );
		$to   = mhp_date_add( $from, 6 );
		return array(
			'key'   => 'w-' . $from,
			'from'  => $from,
			'to'    => $to,
			'label' => mhp_fdate( $from, false ) . ' – ' . mhp_fdate( $to ),
			'kind'  => 'week',
		);
	}

	/**
	 * Period from a key.
	 *
	 * @param string $key Key.
	 * @return array|null
	 */
	public static function period_by_key( $key ) {
		if ( preg_match( '/^d-(\d{4}-\d{2}-\d{2})$/', $key, $m ) && mhp_is_date( $m[1] ) ) {
			return self::period( $m[1], 'day' );
		}
		if ( preg_match( '/^w-(\d{4}-\d{2}-\d{2})$/', $key, $m ) && mhp_is_date( $m[1] ) ) {
			return self::period( $m[1], 'week' );
		}
		if ( preg_match( '/^m-(\d{4}-\d{2})$/', $key, $m ) && mhp_is_date( $m[1] . '-01' ) ) {
			return self::period( $m[1] . '-01', 'month' );
		}
		return null;
	}

	/**
	 * The current period and the ones before it (newest first).
	 *
	 * @param int $n How many.
	 * @return array[]
	 */
	public static function recent_periods( $n = 8 ) {
		$out = array();
		$p   = self::period( mhp_today() );
		for ( $i = 0; $i < $n; $i++ ) {
			$out[] = $p;
			$p     = self::period( mhp_date_add( $p['from'], -1 ), $p['kind'] );
		}
		return $out;
	}

	/**
	 * Minimum days played in a period to qualify (week/month).
	 *
	 * @param array $p Period.
	 * @return int
	 */
	public static function min_days( $p ) {
		if ( 'day' === $p['kind'] ) {
			return 1;
		}
		$len = 'week' === $p['kind'] ? 7 : 31;
		return max( 1, min( $len, (int) mhp_opt( 'prize_min_days', 'week' === $p['kind'] ? 4 : 15 ) ) );
	}

	/**
	 * Disqualified users of a period.
	 *
	 * @param string $key Period key.
	 * @return int[]
	 */
	public static function excluded( $key ) {
		$all = get_option( 'mhp_prize_excluded', array() );
		return isset( $all[ $key ] ) ? array_map( 'intval', (array) $all[ $key ] ) : array();
	}

	/**
	 * Ranking for a period.
	 *
	 * @param array $p     Period.
	 * @param int   $limit Rows.
	 * @param bool  $all   Include disqualified rows (flagged) for the admin screen.
	 * @return array[] user_id, name, district, correct, seconds, days, out (bool)
	 */
	public static function ranking( $p, $limit = 20, $all = false ) {
		global $wpdb;
		$t    = MHP_Install::table( 'quiz' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM (SELECT user_id, SUM(correct) AS correct, SUM(seconds) AS seconds, COUNT(*) AS days, MIN(finished_at) AS first_done FROM {$t} WHERE quiz_date BETWEEN %s AND %s AND finished_at IS NOT NULL AND seconds >= total * %d GROUP BY user_id) x WHERE x.days >= %d ORDER BY correct DESC, seconds ASC, days DESC, first_done ASC LIMIT %d", $p['from'], $p['to'], MHP_Quiz::MIN_SECONDS, self::min_days( $p ), (int) $limit + 60 ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$ex   = self::excluded( $p['key'] );
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$uid   = (int) $r['user_id'];
			$staff = mhp_opt( 'prize_staff_out', 1 ) && user_can( $uid, 'edit_posts' );
			$out_f = $staff || in_array( $uid, $ex, true );
			if ( $out_f && ! $all ) {
				continue;
			}
			$out[] = array(
				'user_id'  => $uid,
				'name'     => mhp_public_name( $uid ),
				'district' => (string) get_user_meta( $uid, 'mhp_district', true ),
				'correct'  => (int) $r['correct'],
				'seconds'  => (int) $r['seconds'],
				'days'     => (int) $r['days'],
				'out'      => $out_f,
				'staff'    => $staff,
			);
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Announced results, newest first.
	 *
	 * @return array key => result
	 */
	public static function results() {
		$r = get_option( self::OPT, array() );
		$r = is_array( $r ) ? $r : array();
		uasort(
			$r,
			function ( $a, $b ) {
				return strcmp( (string) $b['to'], (string) $a['to'] );
			}
		);
		return $r;
	}

	/**
	 * Latest announced result.
	 *
	 * @return array|null
	 */
	public static function latest() {
		$r = self::results();
		return $r ? reset( $r ) : null;
	}

	/**
	 * Announce winners of a finished period.
	 *
	 * @param string $key   Period key.
	 * @param bool   $email Email the winners.
	 * @return int|WP_Error Winners count.
	 */
	public static function announce( $key, $email ) {
		$p = self::period_by_key( $key );
		if ( ! $p ) {
			return new WP_Error( 'mhp_prize', 'সময়কাল চেনা যায়নি।' );
		}
		if ( $p['to'] >= mhp_today() ) {
			return new WP_Error( 'mhp_prize', 'সময়কাল শেষ হওয়ার পরে বিজয়ী ঘোষণা করা যাবে।' );
		}
		$done = self::results();
		if ( isset( $done[ $key ] ) ) {
			return new WP_Error( 'mhp_prize', 'এই সময়কালের বিজয়ী আগেই ঘোষণা হয়েছে। নতুন করে ঘোষণা করতে আগে সেটি বাতিল করুন।' );
		}
		$n    = max( 1, min( 20, (int) mhp_opt( 'prize_winners', 3 ) ) );
		$rows = self::ranking( $p, $n );
		if ( ! $rows ) {
			return new WP_Error( 'mhp_prize', 'এই সময়কালে যোগ্য কোনো খেলোয়াড় নেই।' );
		}
		$win = array();
		foreach ( $rows as $i => $r ) {
			$win[] = array(
				'rank'     => $i + 1,
				'user_id'  => $r['user_id'],
				'name'     => $r['name'],
				'district' => $r['district'],
				'correct'  => $r['correct'],
				'seconds'  => $r['seconds'],
				'days'     => $r['days'],
				'sent'     => 0,
			);
		}
		$all         = get_option( self::OPT, array() );
		$all         = is_array( $all ) ? $all : array();
		$all[ $key ] = array(
			'key'     => $key,
			'label'   => $p['label'],
			'kind'    => $p['kind'],
			'from'    => $p['from'],
			'to'      => $p['to'],
			'prizes'  => (string) mhp_opt( 'prize_text', '' ),
			'winners' => $win,
			'at'      => mhp_gmt(),
			'by'      => get_current_user_id(),
		);
		uasort(
			$all,
			function ( $a, $b ) {
				return strcmp( (string) $b['to'], (string) $a['to'] );
			}
		);
		update_option( self::OPT, array_slice( $all, 0, 60, true ), false );
		if ( $email ) {
			foreach ( $win as $w ) {
				$u = get_userdata( $w['user_id'] );
				if ( $u && is_email( $u->user_email ) ) {
					wp_mail(
						$u->user_email,
						'অভিনন্দন! দৈনিক কুইজে আপনি বিজয়ী',
						"প্রিয় {$u->display_name},\n\nমিহা নিউজের দৈনিক কুইজে {$p['label']} সময়কালে আপনি " . mhp_bn( $w['rank'] ) . " নম্বরে আছেন। পুরস্কার পৌঁছে দিতে আমাদের ডেস্ক শিগগির আপনার সঙ্গে যোগাযোগ করবে। ফোন নম্বর হালনাগাদ না থাকলে এই ইমেইলের উত্তরে নম্বরটি জানান।\n\n" . trim( (string) mhp_opt( 'prize_text', '' ) ) . "\n\n— মিহা নিউজ, শিক্ষা ও ক্যাম্পাস\n" . mhp_url( 'leaderboard' )
					);
				}
			}
		}
		return count( $win );
	}

	/**
	 * Warn the desk when guests may play while prizes are on (guests could look up answers before logging in).
	 */
	public static function guest_notice() {
		if ( ! self::on() || ! mhp_opt( 'quiz_guest', 0 ) || ! mhp_can_manage() ) {
			return;
		}
		$s = get_current_screen();
		if ( ! $s || false === strpos( (string) $s->id, 'mhp' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p><b>কুইজ পুরস্কার চালু আছে</b>, কিন্তু অতিথিরাও কুইজ খেলতে পারছেন। অতিথি হিসেবে উত্তর দেখে পরে লগইন করে খেললে ফল নিখুঁত থাকে না। সেটিং › প্রস্তুতি ও পেমেন্ট › "অতিথি" বন্ধ রাখুন।</p></div>';
	}
}
