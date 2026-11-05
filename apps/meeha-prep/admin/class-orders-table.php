<?php
/**
 * Orders screen (প্রস্তুতি › অর্ডার): filter, search, and per-order actions
 * (check with gateway, approve/reject held payments, mark refunded). Needs mhp_manage_orders.
 *
 * @package meeha-prep
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Orders table.
 */
class MHP_Orders_Table extends WP_List_Table {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'order',
				'plural'   => 'orders',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'tran_id' => 'অর্ডার',
			'user'    => 'ক্রেতা',
			'item'    => 'আইটেম',
			'amount'  => 'পরিমাণ',
			'status'  => 'অবস্থা',
			'created' => 'তারিখ',
		);
	}

	/**
	 * Load rows.
	 */
	public function prepare_items() {
		global $wpdb;
		$t      = MHP_Install::table( 'orders' );
		$per    = 30;
		$page   = $this->get_pagenum();
		$where  = array( '1=1' );
		$params = array();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$s      = isset( $_GET['s'] ) ? preg_replace( '/[^A-Za-z0-9@._+\-]/', '', (string) wp_unslash( is_string( $_GET['s'] ) ? $_GET['s'] : '' ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		// phpcs:enable
		if ( $status && isset( MHP_Orders::statuses()[ $status ] ) ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}
		if ( '' !== $s ) {
			$uids = get_users(
				array(
					'search' => '*' . $s . '*',
					'fields' => 'ID',
					'number' => 50,
				)
			);
			$or       = array( 'tran_id LIKE %s', 'phone LIKE %s' );
			$params[] = '%' . $wpdb->esc_like( $s ) . '%';
			$params[] = '%' . $wpdb->esc_like( $s ) . '%';
			if ( $uids ) {
				$or[] = 'user_id IN (' . implode( ',', array_map( 'intval', $uids ) ) . ')';
			}
			$where[] = '(' . implode( ' OR ', $or ) . ')';
		}
		$w     = implode( ' AND ', $where );
		$total = (int) $wpdb->get_var( $params ? $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE {$w}", $params ) : "SELECT COUNT(*) FROM {$t} WHERE {$w}" ); // phpcs:ignore WordPress.DB
		$q     = "SELECT * FROM {$t} WHERE {$w} ORDER BY id DESC LIMIT %d OFFSET %d";
		// phpcs:ignore WordPress.DB
		$this->items = (array) $wpdb->get_results( $wpdb->prepare( $q, array_merge( $params, array( $per, ( $page - 1 ) * $per ) ) ), ARRAY_A );
		$this->_column_headers = array( $this->get_columns(), array(), array() );
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per,
			)
		);
	}

	/**
	 * Status filter links.
	 *
	 * @return array
	 */
	protected function get_views() {
		global $wpdb;
		$t      = MHP_Install::table( 'orders' );
		$counts = array();
		foreach ( (array) $wpdb->get_results( "SELECT status, COUNT(*) AS c FROM {$t} GROUP BY status", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			$counts[ $r['status'] ] = (int) $r['c'];
		}
		$cur   = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$base  = admin_url( 'admin.php?page=mhp-orders' );
		$views = array( 'all' => '<a href="' . esc_url( $base ) . '"' . ( '' === $cur ? ' class="current"' : '' ) . '>সব <span class="count">(' . esc_html( mhp_bn( array_sum( $counts ) ) ) . ')</span></a>' );
		foreach ( MHP_Orders::statuses() as $k => $v ) {
			if ( empty( $counts[ $k ] ) ) {
				continue;
			}
			$views[ $k ] = '<a href="' . esc_url( add_query_arg( 'status', $k, $base ) ) . '"' . ( $cur === $k ? ' class="current"' : '' ) . '>' . esc_html( $v[0] ) . ' <span class="count">(' . esc_html( mhp_bn( $counts[ $k ] ) ) . ')</span></a>';
		}
		return $views;
	}

	/**
	 * Action link.
	 *
	 * @param array  $o     Order.
	 * @param string $do    Action.
	 * @param string $label Label.
	 * @param string $confirm Confirm text.
	 * @return string
	 */
	private function act( $o, $do, $label, $confirm = '' ) {
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=mhp_order_action&do=' . $do . '&id=' . (int) $o['id'] ), 'mhp_order_' . $do . '_' . (int) $o['id'] );
		return '<a href="' . esc_url( $url ) . '"' . ( $confirm ? ' onclick="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ')"' : '' ) . '>' . esc_html( $label ) . '</a>';
	}

	/**
	 * Column: order.
	 *
	 * @param array $o Order.
	 * @return string
	 */
	public function column_tran_id( $o ) {
		$out  = '<b>' . esc_html( $o['tran_id'] ) . '</b>' . ( 'sandbox' === $o['mode'] ? ' <span class="description">(sandbox)</span>' : '' );
		$acts = array();
		if ( in_array( $o['status'], array( 'pending', 'failed', 'cancelled', 'expired' ), true ) ) {
			$acts[] = $this->act( $o, 'reconcile', 'গেটওয়েতে যাচাই' );
		}
		if ( 'hold' === $o['status'] ) {
			$acts[] = $this->act( $o, 'approve', 'অনুমোদন দিন', 'SSLCommerz প্যানেলে লেনদেনটি যাচাই করেছেন? অনুমোদন দিলে ক্রেতা অ্যাক্সেস পাবেন।' );
			$acts[] = $this->act( $o, 'reject', 'বাতিল', 'অর্ডারটি ব্যর্থ হিসেবে চিহ্নিত হবে।' );
		}
		if ( in_array( $o['status'], array( 'paid', 'hold' ), true ) ) {
			$acts[] = $this->act( $o, 'refund', 'রিফান্ড চিহ্নিত করুন', 'এটি শুধু রেকর্ড বদলায় ও অ্যাক্সেস তুলে নেয়; টাকা ফেরত দিতে হবে SSLCommerz প্যানেল থেকে। চালিয়ে যাবেন?' );
		}
		if ( $o['note'] ) {
			$out .= '<details><summary class="description">লগ</summary><pre style="white-space:pre-wrap;font-size:11px">' . esc_html( $o['note'] ) . '</pre></details>';
		}
		return $out . ( $acts ? '<div class="row-actions visible">' . implode( ' | ', $acts ) . '</div>' : '' );
	}

	/**
	 * Default columns.
	 *
	 * @param array  $o   Order.
	 * @param string $col Column.
	 * @return string
	 */
	public function column_default( $o, $col ) {
		switch ( $col ) {
			case 'user':
				$u = get_userdata( (int) $o['user_id'] );
				return $u ? '<a href="' . esc_url( get_edit_user_link( $u->ID ) ) . '">' . esc_html( $u->display_name ) . '</a><br><span class="description">' . esc_html( $u->user_email . ( $o['phone'] ? ' · ' . $o['phone'] : '' ) ) . '</span>' : '#' . (int) $o['user_id'];
			case 'item':
				return esc_html( $o['item_name'] ) . '<br><span class="description">' . esc_html( $o['item_type'] . ' #' . $o['item_id'] ) . '</span>';
			case 'amount':
				return esc_html( mhp_tk( $o['amount'] ) ) . ( $o['card_type'] ? '<br><span class="description">' . esc_html( $o['card_type'] ) . '</span>' : '' );
			case 'status':
				$s = MHP_Orders::statuses();
				$l = isset( $s[ $o['status'] ] ) ? $s[ $o['status'] ][0] : $o['status'];
				return esc_html( $l ) . ( (int) $o['risk_level'] > 0 ? '<br><span style="color:#b32d2e">ঝুঁকি: ' . (int) $o['risk_level'] . '</span>' : '' );
			case 'created':
				return esc_html( get_date_from_gmt( $o['created_at'], 'Y-m-d H:i' ) ) . ( $o['paid_at'] ? '<br><span class="description">পরিশোধ ' . esc_html( get_date_from_gmt( $o['paid_at'], 'Y-m-d H:i' ) ) . '</span>' : '' );
		}
		return '';
	}

	/**
	 * Empty text.
	 */
	public function no_items() {
		echo 'কোনো অর্ডার নেই।';
	}

	/**
	 * Page.
	 */
	public static function page() {
		if ( ! mhp_can_shop() ) {
			wp_die( esc_html( 'অনুমতি নেই।' ) );
		}
		$table = new self();
		$table->prepare_items();
		$msg = isset( $_GET['mhp_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['mhp_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="wrap"><h1>অর্ডার</h1>';
		if ( $msg ) {
			echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}
		echo '<p class="description">টাকা ফেরত দিতে হলে SSLCommerz মার্চেন্ট প্যানেল ব্যবহার করুন, তারপর এখানে "রিফান্ড চিহ্নিত করুন"।</p>';
		$table->views();
		echo '<form method="get"><input type="hidden" name="page" value="mhp-orders">';
		$table->search_box( 'খুঁজুন (অর্ডার, ফোন, ইমেইল)', 'mhp-order' );
		$table->display();
		echo '</form></div>';
	}

	/**
	 * Row actions.
	 */
	public static function handle_action() {
		if ( ! mhp_can_shop() ) {
			wp_die( esc_html( 'অনুমতি নেই।' ), 403 );
		}
		$do = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : '';
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( 'mhp_order_' . $do . '_' . $id );
		$o = MHP_Orders::get( $id );
		if ( ! $o ) {
			wp_die( esc_html( 'অর্ডার পাওয়া যায়নি।' ) );
		}
		$who = ' (ব্যবহারকারী #' . get_current_user_id() . ')';
		$msg = '';
		switch ( $do ) {
			case 'reconcile':
				$r   = MHP_Checkout::reconcile( $o, '' );
				$map = array(
					'paid'    => 'পরিশোধ পাওয়া গেছে; অ্যাক্সেস দেওয়া হয়েছে।',
					'hold'    => 'লেনদেন পাওয়া গেছে কিন্তু ঝুঁকিপূর্ণ; পর্যালোচনায় রাখা হলো।',
					'already' => 'আগেই পরিশোধিত।',
					'error'   => 'গেটওয়ের সঙ্গে যোগাযোগ করা যায়নি; লগ দেখুন।',
				);
				$msg = isset( $map[ $r ] ) ? $map[ $r ] : 'এই অর্ডারের কোনো সফল লেনদেন পাওয়া যায়নি।';
				break;
			case 'approve':
				if ( 'hold' === $o['status'] ) {
					$r   = MHP_Orders::complete( $o, array( 'source' => 'সম্পাদকের অনুমোদন' . $who, 'val_id' => $o['val_id'], 'bank_tran_id' => $o['bank_tran_id'], 'card_type' => $o['card_type'], 'risk_level' => $o['risk_level'] ) );
					$msg = 'paid' === $r ? 'অনুমোদিত; অ্যাক্সেস দেওয়া হয়েছে।' : 'অবস্থা বদলানো যায়নি।';
				}
				break;
			case 'reject':
				if ( MHP_Orders::set_status( $id, 'failed', array( 'hold' ) ) ) {
					MHP_Orders::log( $id, 'পর্যালোচনায় বাতিল' . $who );
					$msg = 'বাতিল করা হয়েছে। টাকা কেটে থাকলে SSLCommerz প্যানেল থেকে ফেরত দিন।';
				}
				break;
			case 'refund':
				$msg = MHP_Orders::refund( $id ) ? 'রিফান্ড হিসেবে চিহ্নিত; অ্যাক্সেস তুলে নেওয়া হয়েছে।' : 'অবস্থা বদলানো যায়নি।';
				break;
		}
		wp_safe_redirect( add_query_arg( 'mhp_msg', rawurlencode( $msg ? $msg : 'কিছু বদলায়নি।' ), admin_url( 'admin.php?page=mhp-orders' ) ) );
		exit;
	}
}
