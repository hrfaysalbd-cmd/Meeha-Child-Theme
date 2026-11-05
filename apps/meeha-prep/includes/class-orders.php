<?php
/**
 * Orders. Prices always come from the database at checkout time, never from the browser.
 * Status flow: pending → paid | failed | cancelled | expired | hold (risky, needs a human) ; paid → refunded.
 * Completion is a single conditional UPDATE, so two callbacks (IPN + browser return) cannot grant twice.
 *
 * @package meeha-prep
 */

defined( 'ABSPATH' ) || exit;

/**
 * Order model.
 */
class MHP_Orders {

	/**
	 * Labels.
	 *
	 * @return array status => [label, css]
	 */
	public static function statuses() {
		return array(
			'pending'   => array( 'অপেক্ষমাণ', 'warn' ),
			'paid'      => array( 'সফল', 'ok' ),
			'hold'      => array( 'পর্যালোচনা', 'warn' ),
			'failed'    => array( 'ব্যর্থ', 'bad' ),
			'cancelled' => array( 'বাতিল', 'n' ),
			'expired'   => array( 'মেয়াদোত্তীর্ণ', 'n' ),
			'refunded'  => array( 'রিফান্ড হয়েছে', 'n' ),
		);
	}

	/**
	 * Describe a sellable item.
	 *
	 * @param string $type    test|product|event|cvtpl|consult|pubd|pubp.
	 * @param int    $item_id Item.
	 * @return array ok, name, price, reason, post
	 */
	public static function item( $type, $item_id ) {
		if ( 'consult' === $type ) {
			return MHP_Consult::order_item( (int) $item_id );
		}
		if ( 'pubd' === $type ) {
			return MHP_Pubs::order_item_digital( (int) $item_id );
		}
		if ( 'pubp' === $type ) {
			return MHP_Pubs::order_item_print( (int) $item_id );
		}
		$map  = array(
			'test'    => 'mh_test',
			'product' => 'mh_product',
			'event'   => 'mh_event',
			'cvtpl'   => 'mh_cv_tpl',
		);
		$fail = array(
			'ok'     => false,
			'name'   => '',
			'price'  => 0,
			'reason' => 'এই আইটেমটি পাওয়া যায়নি।',
			'post'   => null,
		);
		if ( ! isset( $map[ $type ] ) ) {
			return $fail;
		}
		$post = get_post( (int) $item_id );
		if ( ! $post || $map[ $type ] !== $post->post_type || 'publish' !== $post->post_status ) {
			return $fail;
		}
		$price = round( (float) mhp_meta( $post->ID, 'price', 0 ), 2 );
		$out   = array(
			'ok'     => true,
			'name'   => get_the_title( $post ),
			'price'  => $price,
			'reason' => '',
			'post'   => $post,
		);
		if ( 'event' !== $type && $price <= 0 ) {
			// Only events are handed out free through checkout. A test or product without a price (e.g. published
			// before an order manager set one) is not for sale yet — never grant it for nothing.
			$out['ok']     = false;
			$out['reason'] = 'এটি এখনো বিক্রির জন্য প্রস্তুত নয়।';
		}
		if ( 'cvtpl' === $type ) {
			$out['name'] = 'সিভি টেমপ্লেট: ' . $out['name'];
		}
		if ( 'test' === $type ) {
			$track = wp_get_post_terms( $post->ID, 'mh_track', array( 'fields' => 'names' ) );
			if ( $track && ! is_wp_error( $track ) ) {
				$out['name'] = $track[0] . ': ' . $out['name'];
			}
		}
		if ( 'event' === $type ) {
			if ( ! mhp_meta( $post->ID, 'reg_open', 1 ) ) {
				$out['ok']     = false;
				$out['reason'] = 'নিবন্ধন বন্ধ।';
			}
			$date = (string) mhp_meta( $post->ID, 'date', '' );
			if ( mhp_is_date( $date ) && mhp_days_left( $date ) < 0 ) {
				$out['ok']     = false;
				$out['reason'] = 'ইভেন্টটি শেষ হয়ে গেছে।';
			}
			$cap = (int) mhp_meta( $post->ID, 'capacity', 0 );
			if ( $cap && MHP_Access::count_item( 'event', $post->ID ) >= $cap ) {
				$out['ok']     = false;
				$out['reason'] = 'সব আসন পূর্ণ হয়ে গেছে।';
			}
		}
		return $out;
	}

	/**
	 * Create a pending order.
	 *
	 * @param int    $user_id User.
	 * @param string $type    Type.
	 * @param int    $item_id Item.
	 * @param string $phone   Phone (for the gateway).
	 * @return array|WP_Error Order row.
	 */
	public static function create( $user_id, $type, $item_id, $phone = '' ) {
		global $wpdb;
		$it = self::item( $type, $item_id );
		if ( ! $it['ok'] ) {
			return new WP_Error( 'mhp_item', $it['reason'] );
		}
		if ( $it['price'] <= 0 ) {
			return new WP_Error( 'mhp_free', 'এটি ফ্রি, কেনার দরকার নেই।' );
		}
		if ( MHP_Access::has( $user_id, $type, $item_id ) ) {
			return new WP_Error( 'mhp_owned', in_array( $type, array( 'consult', 'pubp' ), true ) ? 'এর পেমেন্ট আগেই হয়ে গেছে।' : 'এটি আপনার কেনা আছে।' );
		}
		if ( 'consult' === $type && ! MHP_Consult::is_owner( (int) $item_id, (int) $user_id ) ) {
			return new WP_Error( 'mhp_item', 'এই আইটেমটি পাওয়া যায়নি।' );
		}
		if ( 'pubp' === $type && ! MHP_Pubs::is_owner( (int) $item_id, (int) $user_id ) ) {
			return new WP_Error( 'mhp_item', 'এই আইটেমটি পাওয়া যায়নি।' );
		}
		$prefix = (string) mhp_opt( 'order_prefix', 'MHP' );
		$now    = mhp_gmt();
		$tmp    = $prefix . strtoupper( wp_generate_password( 12, false, false ) );
		$ok     = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			MHP_Install::table( 'orders' ),
			array(
				'tran_id'    => $tmp,
				'user_id'    => (int) $user_id,
				'item_type'  => $type,
				'item_id'    => (int) $item_id,
				'item_name'  => mb_substr( $it['name'], 0, 250 ),
				'amount'     => $it['price'],
				'currency'   => 'BDT',
				'status'     => 'pending',
				'gateway'    => 'sslcommerz',
				'mode'       => MHP_Gateway_SSLCommerz::creds()['mode'],
				'phone'      => substr( preg_replace( '/[^0-9+]/', '', meeha_prep_en_digits( $phone ) ), 0, 20 ),
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%s', '%d', '%s', '%d', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( ! $ok ) {
			return new WP_Error( 'mhp_db', 'অর্ডার তৈরি করা যায়নি। আবার চেষ্টা করুন।' );
		}
		$id = (int) $wpdb->insert_id;
		// Readable, unique, not guessable: PREFIX + id + random tail (e.g. MHP1024K7Q2).
		$tran = $prefix . $id . strtoupper( wp_generate_password( 4, false, false ) );
		$wpdb->update( MHP_Install::table( 'orders' ), array( 'tran_id' => $tran ), array( 'id' => $id ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return self::get( $id );
	}

	/**
	 * Order by id.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$t = MHP_Install::table( 'orders' );
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $r ? $r : null;
	}

	/**
	 * Order by transaction id.
	 *
	 * @param string $tran Tran id.
	 * @return array|null
	 */
	public static function by_tran( $tran ) {
		global $wpdb;
		$tran = preg_replace( '/[^A-Za-z0-9]/', '', (string) $tran );
		if ( '' === $tran ) {
			return null;
		}
		$t = MHP_Install::table( 'orders' );
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE tran_id = %s", $tran ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $r ? $r : null;
	}

	/**
	 * A user's orders.
	 *
	 * @param int $user_id User.
	 * @param int $limit   Limit.
	 * @return array[]
	 */
	public static function of_user( $user_id, $limit = 50 ) {
		global $wpdb;
		$t = MHP_Install::table( 'orders' );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE user_id = %d AND status <> 'pending' ORDER BY id DESC LIMIT %d", (int) $user_id, (int) $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Update fields (status changes go through set_status/complete).
	 *
	 * @param int   $id     Id.
	 * @param array $fields Fields.
	 */
	public static function update( $id, $fields ) {
		global $wpdb;
		$allowed = array( 'val_id', 'bank_tran_id', 'card_type', 'risk_level', 'note', 'phone' );
		$data    = array();
		foreach ( $fields as $k => $v ) {
			if ( in_array( $k, $allowed, true ) ) {
				$data[ $k ] = 'risk_level' === $k ? (int) $v : mb_substr( (string) $v, 0, 'note' === $k ? 2000 : 80 );
			}
		}
		if ( ! $data ) {
			return;
		}
		$data['updated_at'] = mhp_gmt();
		$wpdb->update( MHP_Install::table( 'orders' ), $data, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Append a line to the order's note (audit trail).
	 *
	 * @param int    $id   Id.
	 * @param string $line Line.
	 */
	public static function log( $id, $line ) {
		$o = self::get( $id );
		if ( ! $o ) {
			return;
		}
		$note = trim( (string) $o['note'] . "\n" . wp_date( 'Y-m-d H:i', null, mhp_tz() ) . ' ' . $line );
		if ( mb_strlen( $note ) > 1900 ) {
			$note = mb_substr( $note, -1900 );
		}
		self::update( $id, array( 'note' => $note ) );
	}

	/**
	 * Conditional status change. Returns true only for the call that actually changed the row.
	 *
	 * @param int      $id   Id.
	 * @param string   $to   New status.
	 * @param string[] $from Allowed current statuses.
	 * @return bool
	 */
	public static function set_status( $id, $to, $from ) {
		global $wpdb;
		if ( ! isset( self::statuses()[ $to ] ) || ! $from ) {
			return false;
		}
		$t      = MHP_Install::table( 'orders' );
		$in     = implode( ',', array_fill( 0, count( $from ), '%s' ) );
		$now    = mhp_gmt();
		$paid   = 'paid' === $to ? ', paid_at = %s' : '';
		$params = 'paid' === $to ? array( $to, $now, $now ) : array( $to, $now );
		$params = array_merge( $params, array( (int) $id ), array_values( $from ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$n = $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET status = %s, updated_at = %s{$paid} WHERE id = %d AND status IN ({$in})", $params ) );
		return 1 === (int) $n;
	}

	/**
	 * Mark paid (from a verified gateway answer) and grant the item. Idempotent.
	 *
	 * @param array $order Order row.
	 * @param array $info  val_id, bank_tran_id, card_type, risk_level.
	 * @param bool  $hold  Put on hold instead of granting.
	 * @return string paid|hold|already|noop
	 */
	public static function complete( $order, $info, $hold = false ) {
		self::update(
			$order['id'],
			array(
				'val_id'       => isset( $info['val_id'] ) ? $info['val_id'] : '',
				'bank_tran_id' => isset( $info['bank_tran_id'] ) ? $info['bank_tran_id'] : '',
				'card_type'    => isset( $info['card_type'] ) ? $info['card_type'] : '',
				'risk_level'   => isset( $info['risk_level'] ) ? (int) $info['risk_level'] : -1,
			)
		);
		if ( ! $hold && 'event' === $order['item_type'] ) {
			// Seats are checked when the order starts; several buyers paying at once could pass the limit, so check
			// again and let an editor decide (approve or refund) instead of overselling.
			$cap = (int) mhp_meta( (int) $order['item_id'], 'capacity', 0 );
			if ( $cap && MHP_Access::count_item( 'event', (int) $order['item_id'] ) >= $cap && ! MHP_Access::has( (int) $order['user_id'], 'event', (int) $order['item_id'] ) ) {
				self::log( $order['id'], 'আসন পূর্ণ হয়ে গেছে' );
				$hold = true;
			}
		}
		if ( $hold ) {
			if ( self::set_status( $order['id'], 'hold', array( 'pending', 'failed', 'cancelled', 'expired' ) ) ) {
				self::log( $order['id'], 'ঝুঁকিপূর্ণ লেনদেন: পর্যালোচনায় রাখা হলো' );
				do_action( 'mhp_order_hold', $order );
				return 'hold';
			}
			return 'noop';
		}
		if ( self::set_status( $order['id'], 'paid', array( 'pending', 'failed', 'cancelled', 'expired', 'hold' ) ) ) {
			MHP_Access::grant_item( (int) $order['user_id'], $order['item_type'], (int) $order['item_id'], (int) $order['id'] );
			self::log( $order['id'], 'পেমেন্ট যাচাই সফল' . ( ! empty( $info['source'] ) ? ' (' . $info['source'] . ')' : '' ) );
			do_action( 'mhp_order_paid', self::get( $order['id'] ) );
			return 'paid';
		}
		return 'paid' === $order['status'] ? 'already' : 'noop';
	}

	/**
	 * Refund bookkeeping: status + revoke access (the money is returned from the SSLCommerz panel).
	 *
	 * @param int $id Order.
	 * @return bool
	 */
	public static function refund( $id ) {
		if ( self::set_status( $id, 'refunded', array( 'paid', 'hold' ) ) ) {
			MHP_Access::revoke_order( $id );
			do_action( 'mhp_order_refunded', self::get( $id ) );
			self::log( $id, 'রিফান্ড হিসেবে চিহ্নিত; অ্যাক্সেস তুলে নেওয়া হলো (ব্যবহারকারী #' . get_current_user_id() . ')' );
			return true;
		}
		return false;
	}

	/**
	 * Totals for the dashboard (paid only).
	 *
	 * @param string $since_gmt Since.
	 * @return array count, sum
	 */
	public static function totals_since( $since_gmt ) {
		global $wpdb;
		$t = MHP_Install::table( 'orders' );
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS c, COALESCE(SUM(amount),0) AS s FROM {$t} WHERE status = 'paid' AND paid_at >= %s", $since_gmt ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array(
			'count' => (int) ( $r ? $r['c'] : 0 ),
			'sum'   => (float) ( $r ? $r['s'] : 0 ),
		);
	}
}
