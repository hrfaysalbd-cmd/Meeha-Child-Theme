<?php
/**
 * প্রস্তুতি → বইয়ের অর্ডার: print shipments (addresses are personal data and money is involved, so this needs
 * the order-manager capability). Mark sent (courier + tracking), delivered, returned, cancel COD/unpaid ones,
 * and download a CSV for the courier's bulk upload.
 *
 * @package meeha-prep
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shipments desk.
 */
class MHP_Pub_Admin {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 22 );
		add_action( 'admin_post_mhp_shipment', array( __CLASS__, 'handle' ) );
		add_action( 'admin_post_mhp_shipments_csv', array( __CLASS__, 'csv' ) );
	}

	/**
	 * Menu (with a count of orders to pack).
	 */
	public static function menu() {
		global $wpdb;
		$t = MHP_Install::table( 'shipments' );
		$n = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE status IN ('paid','cod')" ); // phpcs:ignore WordPress.DB
		add_submenu_page( 'mhp', 'বইয়ের অর্ডার', 'বইয়ের অর্ডার' . ( $n ? ' <span class="awaiting-mod">' . (int) $n . '</span>' : '' ), 'mhp_manage_orders', 'mhp-shipments', array( __CLASS__, 'page' ) );
	}

	/**
	 * Page: one shipment (?shipment=) or the list.
	 */
	public static function page() {
		global $wpdb;
		if ( ! mhp_can_shop() ) {
			wp_die( esc_html( 'অনুমতি নেই।' ) );
		}
		$st = MHP_Pubs::statuses();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only screen.
		$one  = isset( $_GET['shipment'] ) ? absint( $_GET['shipment'] ) : 0;
		$fs   = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'todo';
		$done = isset( $_GET['done'] ) ? sanitize_key( wp_unslash( $_GET['done'] ) ) : '';
		// phpcs:enable
		echo '<div class="wrap mhp-wrap"><h1>বইয়ের অর্ডার (প্রিন্ট)</h1>';
		if ( $done ) {
			echo '<div class="notice notice-' . ( 'fail' === $done ? 'error' : 'success' ) . ' is-dismissible"><p>' . esc_html( 'fail' === $done ? 'বদলানো যায়নি (অবস্থা আগেই বদলে গিয়েছিল?)।' : 'সংরক্ষিত হয়েছে।' ) . '</p></div>';
		}
		if ( $one ) {
			self::one( $one, $st );
			echo '</div>';
			return;
		}
		$tabs = array(
			'todo'      => 'পাঠাতে হবে',
			'shipped'   => 'পাঠানো',
			'delivered' => 'পৌঁছেছে',
			'unpaid'    => 'পেমেন্ট বাকি',
			'cancelled' => 'বাতিল/ফেরত',
			'all'       => 'সব',
		);
		echo '<ul class="subsubsub">';
		$i = 0;
		foreach ( $tabs as $k => $l ) {
			echo '<li>' . ( $i++ ? ' | ' : '' ) . '<a href="' . esc_url( admin_url( 'admin.php?page=mhp-shipments&status=' . $k ) ) . '"' . ( $fs === $k ? ' class="current"' : '' ) . '>' . esc_html( $l ) . '</a></li>';
		}
		echo '</ul><p style="clear:both;padding-top:8px"><a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mhp_shipments_csv' ), 'mhp_shipments_csv' ) ) . '">"পাঠাতে হবে" তালিকার CSV (কুরিয়ারের জন্য)</a></p>';
		$map = array(
			'todo'      => array( 'paid', 'cod' ),
			'shipped'   => array( 'shipped' ),
			'delivered' => array( 'delivered' ),
			'unpaid'    => array( 'unpaid' ),
			'cancelled' => array( 'cancelled', 'returned' ),
		);
		$t    = MHP_Install::table( 'shipments' );
		$rows = isset( $map[ $fs ] )
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			? $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE status IN (" . implode( ',', array_fill( 0, count( $map[ $fs ] ), '%s' ) ) . ') ORDER BY id ASC LIMIT 300', $map[ $fs ] ), ARRAY_A )
			: $wpdb->get_results( "SELECT * FROM {$t} ORDER BY id DESC LIMIT 300", ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! $rows ) {
			echo '<p>কিছু নেই।</p></div>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>#</th><th>বই</th><th>প্রাপক</th><th>মোট</th><th>অবস্থা</th><th>তারিখ</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$s = isset( $st[ $r['status'] ] ) ? $st[ $r['status'] ][0] : $r['status'];
			echo '<tr><td><a href="' . esc_url( admin_url( 'admin.php?page=mhp-shipments&shipment=' . (int) $r['id'] ) ) . '"><b>#' . (int) $r['id'] . '</b></a></td>';
			echo '<td>' . esc_html( get_the_title( (int) $r['pub_id'] ) . ' × ' . $r['qty'] ) . '</td>';
			echo '<td>' . esc_html( $r['name'] . ' · ' . $r['phone'] ) . '<br><span class="description">' . esc_html( $r['district'] ) . '</span></td>';
			echo '<td>' . esc_html( mhp_tk( $r['amount'] ) . ( 'cod' === $r['pay'] ? ' (COD)' : '' ) ) . '</td>';
			echo '<td>' . esc_html( $s ) . '</td><td>' . esc_html( mhp_fdatetime( get_date_from_gmt( $r['created_at'], 'Y-m-d H:i' ) ) ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * One shipment with its actions.
	 *
	 * @param int   $id Shipment.
	 * @param array $st Statuses.
	 */
	private static function one( $id, $st ) {
		$s = MHP_Pubs::shipment( $id );
		if ( ! $s ) {
			echo '<p>অর্ডার পাওয়া যায়নি।</p>';
			return;
		}
		$u = get_userdata( (int) $s['user_id'] );
		$o = $s['order_id'] ? MHP_Orders::get( (int) $s['order_id'] ) : null;
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=mhp-shipments' ) ) . '">← তালিকা</a></p>';
		echo '<table class="widefat striped" style="max-width:760px"><tbody>';
		$rows = array(
			'অর্ডার'    => '#' . $s['id'] . ' · ' . ( isset( $st[ $s['status'] ] ) ? $st[ $s['status'] ][0] : $s['status'] ),
			'বই'        => get_the_title( (int) $s['pub_id'] ) . ' × ' . $s['qty'] . ' (প্রতি কপি ' . mhp_tk( $s['unit_price'] ) . ')',
			'ডেলিভারি'  => mhp_tk( $s['ship_fee'] ) . ' · ' . ( 'dhaka' === $s['zone'] ? 'ঢাকা মহানগর' : 'ঢাকার বাইরে' ),
			'মোট'       => mhp_tk( $s['amount'] ) . ( 'cod' === $s['pay'] ? ' · ক্যাশ অন ডেলিভারি (কুরিয়ার টাকা তুলবে)' : '' ) . ( $o ? ' · অনলাইন অর্ডার ' . $o['tran_id'] . ' (' . $o['status'] . ')' : '' ),
			'প্রাপক'    => $s['name'] . ' · ' . $s['phone'],
			'ঠিকানা'    => $s['address'] . ', ' . $s['district'],
			'অ্যাকাউন্ট' => $u ? $u->display_name . ' <' . $u->user_email . '>' : '—',
			'কুরিয়ার'   => trim( $s['courier'] . ' ' . $s['tracking'] ),
			'তারিখ'     => mhp_fdatetime( get_date_from_gmt( $s['created_at'], 'Y-m-d H:i' ) ),
		);
		foreach ( $rows as $k => $v ) {
			echo '<tr><th style="width:150px">' . esc_html( $k ) . '</th><td>' . esc_html( $v ) . '</td></tr>';
		}
		if ( $s['note'] ) {
			echo '<tr><th>নোট</th><td>' . nl2br( esc_html( (string) $s['note'] ) ) . '</td></tr>';
		}
		echo '</tbody></table>';

		$form = function ( $do, $label, $extra = '', $primary = false, $confirm = '' ) use ( $s ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:12px 0">';
			wp_nonce_field( 'mhp_shipment_' . (int) $s['id'] );
			echo '<input type="hidden" name="action" value="mhp_shipment"><input type="hidden" name="id" value="' . (int) $s['id'] . '"><input type="hidden" name="do" value="' . esc_attr( $do ) . '">';
			echo $extra; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built below from escaped parts.
			echo ' <button class="button' . ( $primary ? ' button-primary' : '' ) . '"' . ( $confirm ? ' onclick="return confirm(\'' . esc_js( $confirm ) . '\')"' : '' ) . '>' . esc_html( $label ) . '</button></form>';
		};
		if ( in_array( $s['status'], array( 'paid', 'cod', 'shipped' ), true ) ) {
			$extra = '<label>কুরিয়ার <input name="courier" class="regular-text" maxlength="60" value="' . esc_attr( $s['courier'] ) . '" placeholder="যেমন: Pathao, Steadfast, RedX, সুন্দরবন"></label><br><label>ট্র্যাকিং/কনসাইনমেন্ট নম্বর <input name="tracking" class="regular-text" maxlength="80" value="' . esc_attr( $s['tracking'] ) . '"></label><br>';
			$form( 'ship', 'shipped' === $s['status'] ? 'কুরিয়ারের তথ্য হালনাগাদ' : 'পাঠানো হয়েছে (পাঠককে ইমেইল যাবে)', $extra, true );
		}
		if ( 'shipped' === $s['status'] ) {
			$form( 'deliver', 'পৌঁছেছে' );
			$form( 'return', 'ফেরত এসেছে (স্টকে ফেরত যাবে)', '', false, 'ফেরত হিসেবে চিহ্নিত করবেন?' );
		}
		if ( in_array( $s['status'], array( 'unpaid', 'cod' ), true ) ) {
			$form( 'cancel', 'অর্ডার বাতিল করুন', '', false, 'অর্ডারটি বাতিল করবেন? পাঠককে ইমেইল যাবে।' );
		}
		if ( 'paid' === $s['status'] ) {
			echo '<p class="description">অনলাইনে পরিশোধিত অর্ডার বাতিল করতে <a href="' . esc_url( admin_url( 'admin.php?page=mhp-orders' ) ) . '">অর্ডার</a> পাতা থেকে রিফান্ড করুন; চালানটি নিজে থেকেই বাতিল হবে ও স্টক ফেরত যাবে।</p>';
		}
	}

	/**
	 * Status actions.
	 */
	public static function handle() {
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		check_admin_referer( 'mhp_shipment_' . $id );
		if ( ! mhp_can_shop() ) {
			wp_die( esc_html( 'অনুমতি নেই।' ), 403 );
		}
		$do = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$ok = false;
		$by = ' (ব্যবহারকারী #' . get_current_user_id() . ')';
		if ( 'ship' === $do ) {
			$fields = array(
				'courier'  => isset( $_POST['courier'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_POST['courier'] ) ), 0, 60 ) : '',
				'tracking' => isset( $_POST['tracking'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_POST['tracking'] ) ), 0, 80 ) : '',
			);
			$ok = MHP_Pubs::set_status( $id, 'shipped', array( 'paid', 'cod', 'shipped' ), $fields );
			if ( $ok ) {
				MHP_Pubs::log( $id, 'পাঠানো হয়েছে: ' . trim( $fields['courier'] . ' ' . $fields['tracking'] ) . $by );
				MHP_Pubs::notify_reader( $id );
			}
		} elseif ( 'deliver' === $do ) {
			$ok = MHP_Pubs::set_status( $id, 'delivered', array( 'shipped' ) );
			if ( $ok ) {
				MHP_Pubs::log( $id, 'পৌঁছেছে' . $by );
			}
		} elseif ( 'return' === $do ) {
			$ok = MHP_Pubs::set_status( $id, 'returned', array( 'shipped' ) );
			if ( $ok ) {
				MHP_Pubs::restock( $id );
				MHP_Pubs::log( $id, 'ফেরত এসেছে' . $by );
			}
		} elseif ( 'cancel' === $do ) {
			$ok = MHP_Pubs::set_status( $id, 'cancelled', array( 'unpaid', 'cod' ) );
			if ( $ok ) {
				MHP_Pubs::restock( $id );
				MHP_Pubs::log( $id, 'ডেস্ক বাতিল করেছে' . $by );
				MHP_Pubs::notify_reader( $id );
			}
		}
		wp_safe_redirect( admin_url( 'admin.php?page=mhp-shipments&shipment=' . $id . '&done=' . ( $ok ? 'ok' : 'fail' ) ) );
		exit;
	}

	/**
	 * CSV of shipments to send (paid + COD). Cells that a spreadsheet would run as a formula are prefixed with '.
	 */
	public static function csv() {
		global $wpdb;
		check_admin_referer( 'mhp_shipments_csv' );
		if ( ! mhp_can_shop() ) {
			wp_die( esc_html( 'অনুমতি নেই।' ), 403 );
		}
		$t    = MHP_Install::table( 'shipments' );
		$rows = (array) $wpdb->get_results( "SELECT * FROM {$t} WHERE status IN ('paid','cod') ORDER BY id ASC LIMIT 2000", ARRAY_A ); // phpcs:ignore WordPress.DB
		$cell = function ( $v ) {
			$v = (string) $v;
			return preg_match( '/^[=+\-@\t\r]/', $v ) ? "'" . $v : $v;
		};
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="meeha-shipments-' . gmdate( 'Ymd' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- BOM so Excel reads Bangla.
		fputcsv( $out, array( 'order', 'name', 'phone', 'address', 'district', 'zone', 'item', 'qty', 'cod_amount', 'paid_online' ) );
		foreach ( $rows as $r ) {
			fputcsv(
				$out,
				array_map(
					$cell,
					array(
						'MHB' . $r['id'],
						$r['name'],
						$r['phone'],
						preg_replace( '/\s+/u', ' ', (string) $r['address'] ),
						$r['district'],
						'dhaka' === $r['zone'] ? 'inside_dhaka' : 'outside_dhaka',
						get_the_title( (int) $r['pub_id'] ),
						$r['qty'],
						'cod' === $r['pay'] ? $r['amount'] : '0',
						'cod' === $r['pay'] ? '0' : $r['amount'],
					)
				)
			);
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}
}
