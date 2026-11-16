<?php
/**
 * Consultant bookings desk: list, confirm a time + link, mark done, cancel. The reader is emailed on each step.
 *
 * @package meeha-prep
 */

defined( 'ABSPATH' ) || exit;

/**
 * Bookings admin.
 */
class MHP_Consult_Admin {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_post_mhp_booking', array( __CLASS__, 'do_update' ) );
	}

	/**
	 * Menu.
	 */
	public static function menu() {
		global $wpdb;
		$t = MHP_Install::table( 'bookings' );
		$n = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE status = 'requested'" ); // phpcs:ignore WordPress.DB
		add_submenu_page( 'mhp', 'পরামর্শের বুকিং', 'পরামর্শের বুকিং' . ( $n ? ' <span class="awaiting-mod">' . (int) $n . '</span>' : '' ), 'mhp_manage', 'mhp-bookings', array( __CLASS__, 'page' ) );
	}

	/**
	 * Page: one booking (?booking=) or the list.
	 */
	public static function page() {
		global $wpdb;
		if ( ! mhp_can_manage() ) {
			wp_die( esc_html( 'অনুমতি নেই।' ) );
		}
		$st = MHP_Consult::statuses();
		$wi = MHP_Consult::windows();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only screen.
		$one  = isset( $_GET['booking'] ) ? absint( $_GET['booking'] ) : 0;
		$fs   = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'requested';
		$done = isset( $_GET['done'] ) ? sanitize_key( wp_unslash( $_GET['done'] ) ) : '';
		// phpcs:enable
		echo '<div class="wrap mhp-wrap"><h1>পরামর্শের বুকিং</h1>';
		if ( $done ) {
			echo '<div class="notice notice-' . ( 'fail' === $done ? 'error' : 'success' ) . '"><p>' . esc_html( 'fail' === $done ? 'বদলানো যায়নি (অবস্থা আগেই বদলে গিয়েছিল?)।' : 'সংরক্ষিত হয়েছে; পাঠককে ইমেইল পাঠানো হয়েছে।' ) . '</p></div>';
		}
		if ( $one ) {
			$b = MHP_Consult::booking( $one );
			if ( ! $b ) {
				echo '<p>বুকিং পাওয়া যায়নি।</p></div>';
				return;
			}
			$c = MHP_Consult::data( (int) $b['consultant_id'], false );
			$u = get_userdata( (int) $b['user_id'] );
			echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=mhp-bookings&status=' . $b['status'] ) ) . '">← তালিকা</a></p>';
			echo '<table class="widefat striped" style="max-width:760px"><tbody>';
			$rows = array(
				'বুকিং'        => '#' . $b['id'] . ' · ' . ( isset( $st[ $b['status'] ] ) ? $st[ $b['status'] ][0] : $b['status'] ),
				'কনসাল্টেন্ট'  => $c ? $c['name'] : '#' . $b['consultant_id'],
				'পাঠক'         => $u ? $u->display_name . ' <' . $u->user_email . '>' : '—',
				'ফোন'          => $b['phone'],
				'বিষয়'         => $b['topic'],
				'পছন্দের সময়' => ( $b['pref_date'] ? mhp_fdate( $b['pref_date'] ) : '' ) . ' · ' . ( isset( $wi[ $b['pref_time'] ] ) ? $wi[ $b['pref_time'] ] : '' ),
				'ফি'           => mhp_tk( $b['fee'] ) . ( $b['order_id'] ? ' · অর্ডার #' . $b['order_id'] : '' ),
				'জমা'          => mhp_fdatetime( get_date_from_gmt( $b['created_at'], 'Y-m-d H:i' ) ),
			);
			foreach ( $rows as $k => $v ) {
				echo '<tr><th style="width:160px">' . esc_html( $k ) . '</th><td>' . esc_html( $v ) . '</td></tr>';
			}
			echo '<tr><th>প্রশ্ন</th><td>' . nl2br( esc_html( (string) $b['question'] ) ) . '</td></tr></tbody></table>';

			if ( in_array( $b['status'], array( 'requested', 'confirmed' ), true ) ) {
				echo '<h2>' . ( 'requested' === $b['status'] ? 'সময় নিশ্চিত করুন' : 'সময়/লিংক হালনাগাদ' ) . '</h2>';
				self::form_open( $b, 'confirm' );
				echo '<table class="form-table"><tbody>';
				echo '<tr><th><label for="mhp_slot">সময়</label></th><td><input class="regular-text" id="mhp_slot" name="slot" maxlength="60" required value="' . esc_attr( $b['slot'] ) . '" placeholder="যেমন: শনিবার ২ নভেম্বর, সন্ধ্যা ৭:৩০"></td></tr>';
				echo '<tr><th><label for="mhp_link">ফোন/ভিডিও কলের লিংক</label></th><td><input class="regular-text" type="url" id="mhp_link" name="meet_link" value="' . esc_attr( $b['meet_link'] ) . '" placeholder="https://meet.google.com/…"><p class="description">ফোনে হলে খালি রাখুন; নিচের বার্তায় লিখুন কে কাকে ফোন দেবেন।</p></td></tr>';
				echo '<tr><th><label for="mhp_note">পাঠকের জন্য বার্তা</label></th><td><textarea class="large-text" rows="3" id="mhp_note" name="admin_note">' . esc_textarea( (string) $b['admin_note'] ) . '</textarea></td></tr>';
				echo '</tbody></table>';
				submit_button( 'requested' === $b['status'] ? 'নিশ্চিত করুন ও ইমেইল পাঠান' : 'হালনাগাদ করুন ও ইমেইল পাঠান', 'primary', 'submit', false );
				echo '</form>';
			}
			echo '<p style="margin-top:20px">';
			if ( 'confirmed' === $b['status'] ) {
				self::form_open( $b, 'done', true );
				submit_button( 'সেশন সম্পন্ন', 'secondary', 'submit', false );
				echo '</form> ';
			}
			if ( in_array( $b['status'], array( 'unpaid', 'requested', 'confirmed' ), true ) ) {
				self::form_open( $b, 'cancel', true );
				echo '<input type="text" name="admin_note" class="regular-text" placeholder="বাতিলের কারণ (পাঠক দেখবেন)"> ';
				submit_button( 'বাতিল করুন', 'delete', 'submit', false );
				echo '</form>';
				if ( (float) $b['fee'] > 0 && $b['order_id'] ) {
					echo '<p class="description">ফি পরিশোধিত। বাতিল করলে টাকা SSLCommerz প্যানেল থেকে ফেরত দিন, তারপর <a href="' . esc_url( admin_url( 'admin.php?page=mhp-orders' ) ) . '">অর্ডার</a> পাতায় রিফান্ড চিহ্নিত করুন।</p>';
				}
			}
			echo '</p></div>';
			return;
		}

		$t     = MHP_Install::table( 'bookings' );
		$where = isset( $st[ $fs ] ) ? $wpdb->prepare( 'WHERE status = %s', $fs ) : '';
		$list  = (array) $wpdb->get_results( "SELECT * FROM {$t} {$where} ORDER BY id DESC LIMIT 200", ARRAY_A ); // phpcs:ignore WordPress.DB
		echo '<ul class="subsubsub">';
		$links = array();
		foreach ( array_merge( array( 'all' => array( 'সব' ) ), $st ) as $k => $l ) {
			$links[] = '<li><a href="' . esc_url( admin_url( 'admin.php?page=mhp-bookings&status=' . $k ) ) . '"' . ( $fs === $k ? ' class="current"' : '' ) . '>' . esc_html( $l[0] ) . '</a>';
		}
		echo implode( ' | </li>', $links ) . '</li></ul>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<table class="widefat striped" style="clear:both"><thead><tr><th>#</th><th>কনসাল্টেন্ট</th><th>পাঠক</th><th>বিষয়</th><th>পছন্দের দিন</th><th>ফি</th><th>অবস্থা</th></tr></thead><tbody>';
		if ( ! $list ) {
			echo '<tr><td colspan="7">কিছু নেই।</td></tr>';
		}
		foreach ( $list as $b ) {
			$c = MHP_Consult::data( (int) $b['consultant_id'], false );
			$u = get_userdata( (int) $b['user_id'] );
			echo '<tr><td><a href="' . esc_url( admin_url( 'admin.php?page=mhp-bookings&booking=' . (int) $b['id'] ) ) . '"><b>#' . (int) $b['id'] . '</b></a></td><td>' . esc_html( $c ? $c['name'] : '—' ) . '</td><td>' . esc_html( $u ? $u->display_name : '—' ) . '</td><td>' . esc_html( $b['topic'] ) . '</td><td>' . esc_html( $b['pref_date'] ? mhp_fdate( $b['pref_date'] ) : '' ) . '</td><td>' . esc_html( mhp_tk( $b['fee'] ) ) . '</td><td>' . esc_html( isset( $st[ $b['status'] ] ) ? $st[ $b['status'] ][0] : $b['status'] ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Open an action form.
	 *
	 * @param array  $b      Booking.
	 * @param string $do     Action.
	 * @param bool   $inline Inline form.
	 */
	private static function form_open( $b, $do, $inline = false ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . ( $inline ? ' style="display:inline-block;margin-right:8px"' : '' ) . '>';
		echo '<input type="hidden" name="action" value="mhp_booking"><input type="hidden" name="do" value="' . esc_attr( $do ) . '"><input type="hidden" name="booking" value="' . (int) $b['id'] . '">';
		wp_nonce_field( 'mhp_booking_' . (int) $b['id'] );
	}

	/**
	 * Update a booking.
	 */
	public static function do_update() {
		if ( ! mhp_can_manage() ) {
			wp_die( esc_html( 'অনুমতি নেই।' ), 403 );
		}
		$id = isset( $_POST['booking'] ) ? absint( $_POST['booking'] ) : 0;
		check_admin_referer( 'mhp_booking_' . $id );
		$do   = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$note = isset( $_POST['admin_note'] ) ? mb_substr( sanitize_textarea_field( wp_unslash( $_POST['admin_note'] ) ), 0, 1000 ) : '';
		$ok   = false;
		if ( 'confirm' === $do ) {
			$slot = isset( $_POST['slot'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_POST['slot'] ) ), 0, 60 ) : '';
			$link = isset( $_POST['meet_link'] ) ? esc_url_raw( wp_unslash( $_POST['meet_link'] ), array( 'https', 'http' ) ) : '';
			$ok   = '' !== $slot && MHP_Consult::set_status(
				$id,
				'confirmed',
				array( 'requested', 'confirmed' ),
				array(
					'slot'       => $slot,
					'meet_link'  => mb_substr( $link, 0, 250 ),
					'admin_note' => $note,
				)
			);
			if ( $ok ) {
				MHP_Consult::notify_reader( MHP_Consult::booking( $id ), '[মিহা] আপনার পরামর্শ সেশনের সময় নিশ্চিত', 'আপনার ক্যারিয়ার পরামর্শ সেশনের সময় ঠিক হয়েছে।' );
			}
		} elseif ( 'done' === $do ) {
			$ok = MHP_Consult::set_status( $id, 'done', array( 'confirmed' ) );
		} elseif ( 'cancel' === $do ) {
			$ok = MHP_Consult::set_status( $id, 'cancelled', array( 'unpaid', 'requested', 'confirmed' ), array( 'admin_note' => $note ) );
			if ( $ok ) {
				MHP_Consult::notify_reader( MHP_Consult::booking( $id ), '[মিহা] পরামর্শ সেশন বাতিল', 'দুঃখিত, আপনার পরামর্শ সেশনটি বাতিল হয়েছে। ফি পরিশোধ করে থাকলে তা ফেরত দেওয়া হবে।' );
			}
		}
		wp_safe_redirect( admin_url( 'admin.php?page=mhp-bookings&booking=' . $id . '&done=' . ( $ok ? 'ok' : 'fail' ) ) );
		exit;
	}
}
