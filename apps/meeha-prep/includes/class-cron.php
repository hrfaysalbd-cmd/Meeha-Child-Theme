<?php
/**
 * Scheduled work (WP-Cron; on Hostinger it runs on page visits unless a real cron job calls wp-cron.php):
 *   hourly — close timed-out attempts, settle pending orders with the gateway, expire abandoned orders;
 *   daily  — deadline reminders for saved jobs (opt-in, capped).
 *
 * @package meeha-prep
 */

defined( 'ABSPATH' ) || exit;

/**
 * Cron.
 */
class MHP_Cron {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'mhp_hourly', array( __CLASS__, 'hourly' ) );
		add_action( 'mhp_daily', array( __CLASS__, 'daily' ) );
		add_action( 'init', array( __CLASS__, 'ensure' ) );
	}

	/**
	 * Schedule events.
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( 'mhp_hourly' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'mhp_hourly' );
		}
		if ( ! wp_next_scheduled( 'mhp_daily' ) ) {
			// 08:00 Asia/Dhaka (02:00 UTC).
			$next = strtotime( gmdate( 'Y-m-d' ) . ' 02:00:00 UTC' );
			if ( $next <= time() ) {
				$next += DAY_IN_SECONDS;
			}
			wp_schedule_event( $next, 'daily', 'mhp_daily' );
		}
	}

	/**
	 * Re-schedule if lost (e.g. after a migration).
	 */
	public static function ensure() {
		if ( is_admin() && ! wp_doing_ajax() && ( ! wp_next_scheduled( 'mhp_hourly' ) || ! wp_next_scheduled( 'mhp_daily' ) ) ) {
			self::schedule();
		}
	}

	/**
	 * Remove events.
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( 'mhp_hourly' );
		wp_clear_scheduled_hook( 'mhp_daily' );
	}

	/**
	 * Hourly.
	 */
	public static function hourly() {
		global $wpdb;
		MHP_Tests::expire_stale();
		if ( ! MHP_Gateway_SSLCommerz::configured() ) {
			return;
		}
		$t     = MHP_Install::table( 'orders' );
		$hours = max( 1, (int) mhp_opt( 'pending_hours', 24 ) );
		// Pending for more than 20 minutes: ask the gateway (the reader may have closed the tab after paying).
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE status = 'pending' AND created_at < %s ORDER BY id ASC LIMIT 25", mhp_gmt( time() - 20 * MINUTE_IN_SECONDS ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
		foreach ( (array) $rows as $o ) {
			$old = strtotime( $o['created_at'] . ' UTC' ) < time() - $hours * HOUR_IN_SECONDS;
			MHP_Checkout::reconcile( $o, $old ? 'expired' : '' );
		}
	}

	/**
	 * Daily.
	 */
	public static function daily() {
		$today = mhp_today();
		if ( get_option( 'mhp_daily_ran' ) === $today ) {
			return;
		}
		update_option( 'mhp_daily_ran', $today, false );
		// Trim quiz overrides older than 60 days.
		$over = get_option( 'mhp_quiz_override', array() );
		if ( is_array( $over ) && $over ) {
			$limit = gmdate( 'Y-m-d', strtotime( $today . ' -60 days' ) );
			foreach ( array_keys( $over ) as $d ) {
				if ( $d < $limit ) {
					unset( $over[ $d ] );
				}
			}
			update_option( 'mhp_quiz_override', $over, false );
		}
		if ( mhp_opt( 'reminder_on', 0 ) && mhp_hub_ready() ) {
			self::reminders( $today );
		}
	}

	/**
	 * Deadline reminders for saved jobs.
	 *
	 * @param string $today Today.
	 */
	private static function reminders( $today ) {
		$days   = max( 1, (int) mhp_opt( 'reminder_days', 2 ) );
		$target = gmdate( 'Y-m-d', strtotime( $today . ' +' . $days . ' days' ) );
		$cap    = max( 10, (int) mhp_opt( 'reminder_cap', 100 ) );
		$users  = get_users(
			array(
				'meta_key'   => 'mhp_remind', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value' => '1', // phpcs:ignore WordPress.DB.SlowDBQuery
				'number'     => 2000,
				'fields'     => array( 'ID', 'user_email', 'display_name' ),
			)
		);
		$sent = 0;
		foreach ( $users as $u ) {
			if ( $sent >= $cap ) {
				break;
			}
			$due = array();
			foreach ( MHP_Jobs::saved( $u->ID ) as $jid ) {
				if ( 'publish' === get_post_status( $jid ) && mhp_meta( $jid, 'deadline', '' ) === $target ) {
					$due[] = $jid;
				}
			}
			if ( ! $due || ! is_email( $u->user_email ) ) {
				continue;
			}
			$lines = array();
			foreach ( $due as $jid ) {
				$lines[] = '• ' . get_the_title( $jid ) . ' — ' . mhp_meta( $jid, 'org', '' ) . "\n  " . mhp_url( 'job', $jid );
			}
			$body = "প্রিয় {$u->display_name},\n\nআপনার সংরক্ষিত এই বিজ্ঞপ্তিগুলোর আবেদন " . mhp_bn( $days ) . " দিন পর (" . mhp_fdate( $target ) . ") শেষ হবে:\n\n" . implode( "\n", $lines ) . "\n\nরিমাইন্ডার বন্ধ করতে: " . mhp_url( 'me', '', array( 'tab' => 'alerts' ) ) . "\n\n— মিহা নিউজ, শিক্ষা ও ক্যাম্পাস";
			if ( wp_mail( $u->user_email, 'আবেদনের শেষ তারিখ ঘনিয়ে আসছে', $body ) ) {
				++$sent;
			}
		}
	}
}
