<?php
/**
 * r47 — চ্যাট অটোমেশন: মিহা সহকারী runs the newsroom's daily rhythm.
 *
 *  - 8 AM (Asia/Dhaka): দিনের-শুরু briefing in the "মিহা পরিবার" room — the date, yesterday's
 *    output, what's waiting in the queue, and the day's collection brief.
 *  - 8 PM: every correspondent who sent nothing today gets a gentle line in their own desk
 *    room (private to them and the desk — nobody is called out in front of the group).
 *  - Escalation: nothing for 1 day → a reminder; 3 days → a firmer one; 7 days → a check-in
 *    that offers help; then once a week for as long as it lasts.
 *
 * All timing is Asia/Dhaka. A date-stamp option/user-meta guards every send, so a cron that
 * fires twice (or a missed tick caught later in the window) never double-posts.
 *
 * @package NewsroomOS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Daily chat briefings and no-submission reminders.
 */
class NROS_Chat_Briefing {

	const CRON = 'nros_chat_briefing';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( self::CRON, array( __CLASS__, 'tick' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 600, 'nros_15min', self::CRON );
		}
	}

	/**
	 * The 15-minute interval (also registered by chat-tasks; harmless twice).
	 *
	 * @param array $s Schedules.
	 * @return array
	 */
	public static function schedules( $s ) {
		if ( ! isset( $s['nros_15min'] ) ) {
			$s['nros_15min'] = array( 'interval' => 15 * MINUTE_IN_SECONDS, 'display' => 'Every 15 minutes (Newsroom OS)' );
		}
		return $s;
	}

	/**
	 * Now in Asia/Dhaka.
	 *
	 * @return DateTimeImmutable
	 */
	protected static function now() {
		return new DateTimeImmutable( 'now', new DateTimeZone( 'Asia/Dhaka' ) );
	}

	/**
	 * Bengali digits.
	 *
	 * @param string|int $n Number.
	 * @return string
	 */
	protected static function bn( $n ) {
		return strtr( (string) $n, array( '0' => '০', '1' => '১', '2' => '২', '3' => '৩', '4' => '৪', '5' => '৫', '6' => '৬', '7' => '৭', '8' => '৮', '9' => '৯' ) );
	}

	/**
	 * Every 15 minutes: fire whichever daily job's window is open and not yet done today.
	 */
	public static function tick() {
		if ( ! class_exists( 'NROS_Chat' ) ) {
			return;
		}
		$now  = self::now();
		$hour = (int) $now->format( 'G' );
		$day  = $now->format( 'Y-m-d' );
		// Morning window 8–11 (a missed 8:00 tick still goes out, late rather than never).
		if ( $hour >= 8 && $hour < 11 && get_option( 'nros_brief_am' ) !== $day ) {
			update_option( 'nros_brief_am', $day, false );
			self::morning( $now );
		}
		// Evening window 20–23.
		if ( $hour >= 20 && $hour < 23 && get_option( 'nros_brief_pm' ) !== $day ) {
			update_option( 'nros_brief_pm', $day, false );
			self::evening( $now );
		}
	}

	/**
	 * A room row by slug.
	 *
	 * @param string $slug Slug.
	 * @return array|null
	 */
	protected static function room_by_slug( $slug ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NROS_Chat::t( 'rooms' ) . ' WHERE slug = %s', $slug ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $row ? $row : null;
	}

	/**
	 * Published stories + videos in a local-date window.
	 *
	 * @param string $from Local 'Y-m-d H:i:s'.
	 * @param string $to   Local 'Y-m-d H:i:s'.
	 * @return int
	 */
	protected static function published_between( $from, $to ) {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('post','meeha_video') AND post_status = 'publish' AND post_date >= %s AND post_date < %s",
				$from,
				$to
			)
		);
	}

	/**
	 * 8 AM — দিনের শুরু in "মিহা পরিবার".
	 *
	 * @param DateTimeImmutable $now Dhaka time.
	 */
	protected static function morning( $now ) {
		$room = self::room_by_slug( 'family' );
		if ( ! $room ) {
			return;
		}
		$wd = array( 'Sunday' => 'রবিবার', 'Monday' => 'সোমবার', 'Tuesday' => 'মঙ্গলবার', 'Wednesday' => 'বুধবার', 'Thursday' => 'বৃহস্পতিবার', 'Friday' => 'শুক্রবার', 'Saturday' => 'শনিবার' );
		$mo = array( 1 => 'জানুয়ারি', 'ফেব্রুয়ারি', 'মার্চ', 'এপ্রিল', 'মে', 'জুন', 'জুলাই', 'আগস্ট', 'সেপ্টেম্বর', 'অক্টোবর', 'নভেম্বর', 'ডিসেম্বর' );

		$yday      = $now->modify( '-1 day' )->format( 'Y-m-d' );
		$published = self::published_between( $yday . ' 00:00:00', $now->format( 'Y-m-d' ) . ' 00:00:00' );
		$pending   = (int) wp_count_posts( 'post' )->pending + (int) wp_count_posts( 'meeha_video' )->pending;

		// The day's collection brief: a steady weekly rotation, so every beat gets its day.
		$briefs = array(
			'Sunday'    => array( 'জেলা প্রশাসন ও সেবা: অফিসগুলোতে মানুষের ভোগান্তি আছে কি না দেখুন', 'স্থানীয় বাজারদর — কোনটার দাম বাড়ল-কমল, ছবি/ভিডিওসহ' ),
			'Monday'    => array( 'শিক্ষা: স্কুল-কলেজ-মাদ্রাসার খোঁজ, পরীক্ষা-ভর্তি-ফলাফল', 'সড়ক ও যোগাযোগ: ভাঙা রাস্তা, সেতু, যানজট' ),
			'Tuesday'   => array( 'স্বাস্থ্য: হাসপাতাল-ক্লিনিকের সেবা, ওষুধের দাম, মৌসুমি রোগ', 'কৃষি: ফসল, সার-বীজ, কৃষকের সমস্যা-সাফল্য' ),
			'Wednesday' => array( 'আইনশৃঙ্খলা ও আদালত: থানার তথ্য যাচাই করে, নাম-পরিচয়ে সতর্ক থেকে', 'তরুণ ও কর্মসংস্থান: চাকরি, উদ্যোক্তা, প্রবাসী পরিবার' ),
			'Thursday'  => array( 'সাপ্তাহিক ফলোআপ: এ সপ্তাহের যে খবরের পরের অধ্যায় বাকি', 'মানুষের গল্প: এলাকার ব্যতিক্রমী একজন মানুষ' ),
			'Friday'    => array( 'ধর্ম ও সমাজ: জুমার দিনের আয়োজন, সামাজিক উদ্যোগ', 'খেলা ও বিনোদন: স্থানীয় টুর্নামেন্ট, সাংস্কৃতিক অনুষ্ঠান' ),
			'Saturday'  => array( 'সাপ্তাহিক ছুটির বাজার-পর্যটন: দর্শনীয় স্থান, স্থানীয় অর্থনীতি', 'আগামী সপ্তাহের পরিকল্পনা: কী কী ঘটতে যাচ্ছে, আগে থেকে ধরুন' ),
		);
		$en = $now->format( 'l' );
		$b  = isset( $briefs[ $en ] ) ? $briefs[ $en ] : array();

		$lines   = array();
		$lines[] = '☀️ সুপ্রভাত, মিহা পরিবার! আজ ' . $wd[ $en ] . ', ' . self::bn( (int) $now->format( 'j' ) ) . ' ' . $mo[ (int) $now->format( 'n' ) ] . ' ' . self::bn( $now->format( 'Y' ) ) . '।';
		$lines[] = '';
		$lines[] = '📊 গতকাল প্রকাশ হয়েছে ' . self::bn( $published ) . 'টি খবর/ভিডিও' . ( $pending ? ', আর এডিটোরিয়াল কিউতে অপেক্ষায় ' . self::bn( $pending ) . 'টি' : '' ) . '।';
		if ( $b ) {
			$lines[] = '';
			$lines[] = '📋 আজকের সংগ্রহ-ব্রিফিং:';
			foreach ( $b as $item ) {
				$lines[] = '• ' . $item;
			}
		}
		$lines[] = '';
		$lines[] = 'ছবি-ভিডিওসহ পাঠান, আর জরুরি কিছু পেলে আগে ডেস্ককে জানান। শুভ দিন! 💙';

		NROS_Chat::bot_say( $room, implode( "\n", $lines ), array(), 'দিনের ব্রিফিং' );
	}

	/**
	 * A correspondent's last submission time (post or video, any live status), as a Dhaka date;
	 * falls back to their registration date so a brand-new account is not nagged on day one.
	 *
	 * @param int $uid User.
	 * @return string 'Y-m-d H:i:s' local.
	 */
	protected static function last_submission( $uid ) {
		global $wpdb;
		$last = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX(post_date) FROM {$wpdb->posts} WHERE post_author = %d AND post_type IN ('post','meeha_video') AND post_status IN ('publish','pending','draft','future')",
				$uid
			)
		);
		if ( ! $last ) {
			$u    = get_userdata( $uid );
			$last = $u ? get_date_from_gmt( $u->user_registered ) : current_time( 'mysql' );
		}
		return (string) $last;
	}

	/**
	 * 8 PM — a private line in the desk room of everyone who sent nothing today, escalating at
	 * 1 / 3 / 7 days and weekly after that.
	 *
	 * @param DateTimeImmutable $now Dhaka time.
	 */
	protected static function evening( $now ) {
		$today = $now->format( 'Y-m-d' );
		$ids   = get_users( array( 'role' => 'correspondent', 'fields' => 'ID', 'number' => 5000 ) );
		foreach ( array_map( 'intval', (array) $ids ) as $uid ) {
			if ( class_exists( 'NROS_Core' ) && NROS_Core::active_suspension_types( $uid ) ) {
				continue; // a suspended account is not reminded to submit
			}
			$last = self::last_submission( $uid );
			$days = (int) floor( ( strtotime( $today ) - strtotime( substr( $last, 0, 10 ) ) ) / DAY_IN_SECONDS );
			if ( $days < 1 ) {
				continue; // sent something today
			}
			// Remind at day 1, 3, 7 — and every 7 days from there (14, 21 …).
			$due = 1 === $days || 3 === $days || ( $days >= 7 && 0 === $days % 7 );
			if ( ! $due || get_user_meta( $uid, 'nros_nosub_noted', true ) === $today ) {
				continue;
			}
			update_user_meta( $uid, 'nros_nosub_noted', $today );
			$u    = get_userdata( $uid );
			$name = $u ? $u->display_name : 'সংবাদদাতা';
			if ( 1 === $days ) {
				$text = $name . ', আজ আপনার এলাকা থেকে কোনো খবর আসেনি। 📝 ছোট একটা খবর বা ছবিও দিনের উপস্থিতি — কাল সকালের ব্রিফিং থেকে একটা বিষয় ধরে পাঠান।';
			} elseif ( 3 === $days ) {
				$text = $name . ', টানা ' . self::bn( $days ) . ' দিন আপনার কোনো খবর নেই। আপনার এলাকার পাঠক আপনার চোখেই মিহা দেখে — আজ-কালের মধ্যে অন্তত একটা খবর পাঠান, দরকারে ডেস্ককে বলুন কী নিয়ে লিখবেন।';
			} else {
				$text = $name . ', ' . self::bn( $days ) . ' দিন হয়ে গেল কোনো খবর আসেনি। সব ঠিক আছে তো? কোনো সমস্যা, অসুস্থতা বা নিরাপত্তার বিষয় থাকলে এখানেই জানান — ডেস্ক পাশে আছে। আর সময় করতে পারলে ছোট করে হলেও এলাকার একটা খবর দিন। 🤝';
			}
			NROS_Chat::bot_to_desk( $uid, $text );
		}
	}
}
