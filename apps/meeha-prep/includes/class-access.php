<?php
/**
 * Entitlements: what a user may open. Item types: test, product, event, cvtpl (CV design), consult (a paid
 * consultant booking; item id = booking id), pubd (digital edition of a publication), pubp (a paid print
 * shipment; item id = shipment id).
 * A bundle product grants its tests too (granted at purchase time, so later edits to the bundle do not take
 * tests away from earlier buyers).
 *
 * @package meeha-prep
 */

defined( 'ABSPATH' ) || exit;

/**
 * Access rows.
 */
class MHP_Access {

	const TYPES = array( 'test', 'product', 'event', 'cvtpl', 'consult', 'pubd', 'pubp' );

	/**
	 * Does the user hold an item?
	 *
	 * @param int    $user_id User.
	 * @param string $type    Type.
	 * @param int    $item_id Item.
	 * @return bool
	 */
	public static function has( $user_id, $type, $item_id ) {
		global $wpdb;
		$user_id = (int) $user_id;
		if ( ! $user_id || ! in_array( $type, self::TYPES, true ) ) {
			return false;
		}
		$t = MHP_Install::table( 'access' );
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE user_id = %d AND item_type = %s AND item_id = %d", $user_id, $type, (int) $item_id ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Grant (idempotent).
	 *
	 * @param int    $user_id  User.
	 * @param string $type     Type.
	 * @param int    $item_id  Item.
	 * @param int    $order_id Order.
	 * @return bool True when a new row was written.
	 */
	public static function grant( $user_id, $type, $item_id, $order_id = 0 ) {
		global $wpdb;
		if ( ! (int) $user_id || ! in_array( $type, self::TYPES, true ) || ! (int) $item_id ) {
			return false;
		}
		if ( self::has( $user_id, $type, $item_id ) ) {
			return false;
		}
		$ok = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			MHP_Install::table( 'access' ),
			array(
				'user_id'    => (int) $user_id,
				'item_type'  => $type,
				'item_id'    => (int) $item_id,
				'order_id'   => (int) $order_id,
				'created_at' => mhp_gmt(),
			),
			array( '%d', '%s', '%d', '%d', '%s' )
		);
		return (bool) $ok;
	}

	/**
	 * Remove every row an order created (refund).
	 *
	 * @param int $order_id Order.
	 * @return int Rows removed.
	 */
	public static function revoke_order( $order_id ) {
		global $wpdb;
		return (int) $wpdb->delete( MHP_Install::table( 'access' ), array( 'order_id' => (int) $order_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * All items of a type for a user.
	 *
	 * @param int    $user_id User.
	 * @param string $type    Type.
	 * @return array rows (item_id, order_id, created_at, id)
	 */
	public static function of_user( $user_id, $type ) {
		global $wpdb;
		$t = MHP_Install::table( 'access' );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, item_id, order_id, created_at FROM {$t} WHERE user_id = %d AND item_type = %s ORDER BY id DESC", (int) $user_id, $type ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Count holders of an item (event seats).
	 *
	 * @param string $type    Type.
	 * @param int    $item_id Item.
	 * @return int
	 */
	public static function count_item( $type, $item_id ) {
		global $wpdb;
		$t = MHP_Install::table( 'access' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE item_type = %s AND item_id = %d", $type, (int) $item_id ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Can the user take a test (free, bought, part of a bought bundle)?
	 *
	 * @param int $user_id User (0 = guest).
	 * @param int $test_id Test.
	 * @return bool
	 */
	public static function can_take_test( $user_id, $test_id ) {
		$price = (float) mhp_meta( $test_id, 'price', 0 );
		if ( $price <= 0 ) {
			return true;
		}
		return self::has( $user_id, 'test', $test_id );
	}

	/**
	 * Grant everything an item contains (test → test; bundle → product + its tests; download/service → product;
	 * event → ticket).
	 *
	 * @param int    $user_id  User.
	 * @param string $type     Item type.
	 * @param int    $item_id  Item.
	 * @param int    $order_id Order.
	 */
	public static function grant_item( $user_id, $type, $item_id, $order_id = 0 ) {
		if ( 'test' === $type ) {
			self::grant( $user_id, 'test', $item_id, $order_id );
			return;
		}
		if ( in_array( $type, array( 'event', 'cvtpl', 'consult', 'pubd', 'pubp' ), true ) ) {
			self::grant( $user_id, $type, $item_id, $order_id );
			return;
		}
		if ( 'product' === $type ) {
			self::grant( $user_id, 'product', $item_id, $order_id );
			if ( 'bundle' === mhp_meta( $item_id, 'ptype', 'bundle' ) ) {
				foreach ( array_map( 'intval', (array) mhp_meta( $item_id, 'tests', array() ) ) as $tid ) {
					if ( $tid && 'mh_test' === get_post_type( $tid ) ) {
						self::grant( $user_id, 'test', $tid, $order_id );
					}
				}
			}
		}
	}

	/**
	 * Ticket code for an event registration (stable, not guessable).
	 *
	 * @param int $access_id Access row id.
	 * @param int $user_id   User.
	 * @return string
	 */
	public static function ticket_code( $access_id, $user_id ) {
		$h = strtoupper( substr( hash_hmac( 'sha256', $access_id . '|' . $user_id, wp_salt( 'auth' ) ), 0, 6 ) );
		return 'MH-' . strtoupper( base_convert( (string) (int) $access_id, 10, 36 ) ) . '-' . $h;
	}
}
