<?php
/**
 * Checkout: start (form POST on the hub), gateway callbacks (REST, no login needed — the gateway POSTs
 * cross-site so the reader's cookies are usually absent), receipt.
 *
 * @package meeha-prep
 */

defined( 'ABSPATH' ) || exit;

/**
 * Checkout flow.
 */
class MHP_Checkout {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_filter( 'allowed_redirect_hosts', array( __CLASS__, 'allowed_hosts' ) );
		add_action( 'mhp_hub_action_checkout', array( __CLASS__, 'handle_start' ) );
	}

	/**
	 * Gateway hosts for wp_safe_redirect().
	 *
	 * @param array $hosts Hosts.
	 * @return array
	 */
	public static function allowed_hosts( $hosts ) {
		$hosts[] = 'sandbox.sslcommerz.com';
		$hosts[] = 'securepay.sslcommerz.com';
		return $hosts;
	}

	/**
	 * Callback URL.
	 *
	 * @param string $kind ipn|success|fail|cancel.
	 * @param string $tran Tran id (not for IPN).
	 * @return string
	 */
	public static function callback_url( $kind, $tran = '' ) {
		$url = rest_url( 'meeha-prep/v1/pay/' . $kind );
		return $tran ? add_query_arg( 't', rawurlencode( $tran ), $url ) : $url;
	}

	/**
	 * REST routes.
	 */
	public static function routes() {
		register_rest_route(
			'meeha-prep/v1',
			'/pay/ipn',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'ipn' ),
				'permission_callback' => '__return_true', // The gateway calls this; the payload is verified with the gateway itself.
			)
		);
		register_rest_route(
			'meeha-prep/v1',
			'/pay/(?P<kind>success|fail|cancel)',
			array(
				'methods'             => array( 'GET', 'POST' ),
				'callback'            => array( __CLASS__, 'browser_return' ),
				'permission_callback' => '__return_true', // Browser comes back from the gateway; verified server-side.
			)
		);
	}

	/**
	 * Start checkout (hub POST, nonce + login already checked by the hub dispatcher).
	 */
	public static function handle_start() {
		$type  = isset( $_POST['item_type'] ) ? sanitize_key( wp_unslash( $_POST['item_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by the dispatcher.
		$id    = isset( $_POST['item_id'] ) ? absint( $_POST['item_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$phone = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$back  = mhp_url( 'checkout', $type . '-' . $id );
		$user  = wp_get_current_user();

		$it = MHP_Orders::item( $type, $id );
		if ( ! $it['ok'] ) {
			mhp_flash( $it['reason'], 'error' );
			wp_safe_redirect( mhp_url( 'prep' ) );
			exit;
		}
		if ( $it['price'] <= 0 ) {
			// Free item (free event): grant directly.
			MHP_Access::grant_item( $user->ID, $type, $id, 0 );
			mhp_flash( 'event' === $type ? 'নিবন্ধন সম্পন্ন হয়েছে।' : 'আপনার অ্যাকাউন্টে যোগ হয়েছে।' );
			$to = array(
				'event' => mhp_url( 'event', $id ),
				'pubd'  => mhp_url( 'pub', $id ),
			);
			wp_safe_redirect( isset( $to[ $type ] ) ? $to[ $type ] : mhp_url( 'me' ) );
			exit;
		}
		if ( ! MHP_Gateway_SSLCommerz::available() ) {
			mhp_flash( 'অনলাইন পেমেন্ট এখনো চালু হয়নি।', 'error' );
			wp_safe_redirect( $back );
			exit;
		}
		$digits = meeha_prep_en_digits( preg_replace( '/[\s\-]/', '', $phone ) );
		if ( ! preg_match( '/^(\+?880|0)1[3-9]\d{8}$/', $digits ) ) {
			mhp_flash( '০১ দিয়ে শুরু হওয়া ১১ সংখ্যার সঠিক মোবাইল নম্বর দিন।', 'error' );
			wp_safe_redirect( $back );
			exit;
		}
		if ( ! mhp_rate_ok( 'checkout', 10, 10 * MINUTE_IN_SECONDS ) ) {
			mhp_flash( 'অল্প সময়ে অনেকবার চেষ্টা হয়েছে। কয়েক মিনিট পরে আবার চেষ্টা করুন।', 'error' );
			wp_safe_redirect( $back );
			exit;
		}
		if ( ! get_user_meta( $user->ID, 'meeha_phone', true ) ) {
			update_user_meta( $user->ID, 'meeha_phone', $digits );
		}
		$order = MHP_Orders::create( $user->ID, $type, $id, $digits );
		if ( is_wp_error( $order ) ) {
			mhp_flash( $order->get_error_message(), 'error' );
			wp_safe_redirect( $back );
			exit;
		}
		$url = MHP_Gateway_SSLCommerz::init_session( $order, $user );
		if ( is_wp_error( $url ) ) {
			MHP_Orders::set_status( $order['id'], 'failed', array( 'pending' ) );
			mhp_flash( $url->get_error_message(), 'error' );
			wp_safe_redirect( $back );
			exit;
		}
		MHP_Orders::log( $order['id'], 'গেটওয়েতে পাঠানো হলো (' . ( 'live' === $order['mode'] ? 'লাইভ' : 'স্যান্ডবক্স' ) . ')' );
		wp_safe_redirect( $url, 303 );
		exit;
	}

	/**
	 * IPN from SSLCommerz.
	 *
	 * @param WP_REST_Request $r Request.
	 * @return WP_REST_Response
	 */
	public static function ipn( WP_REST_Request $r ) {
		if ( ! mhp_rate_ok( 'ipn', 120, MINUTE_IN_SECONDS ) ) {
			return new WP_REST_Response( array( 'ok' => false ), 429 );
		}
		$tran  = sanitize_text_field( (string) $r->get_param( 'tran_id' ) );
		$val   = sanitize_text_field( (string) $r->get_param( 'val_id' ) );
		$order = MHP_Orders::by_tran( $tran );
		if ( ! $order ) {
			return new WP_REST_Response( array( 'ok' => false ), 404 );
		}
		$res = self::verify_and_complete( $order, $val, 'IPN' );
		return new WP_REST_Response( array( 'ok' => in_array( $res, array( 'paid', 'already', 'hold' ), true ) ), 200 );
	}

	/**
	 * Browser comes back from the gateway.
	 *
	 * @param WP_REST_Request $r Request.
	 * @return WP_REST_Response
	 */
	public static function browser_return( WP_REST_Request $r ) {
		$kind  = (string) $r->get_param( 'kind' );
		$tran  = sanitize_text_field( (string) ( $r->get_param( 'tran_id' ) ? $r->get_param( 'tran_id' ) : $r->get_param( 't' ) ) );
		$order = MHP_Orders::by_tran( $tran );
		$dest  = mhp_url( 'me' );
		if ( $order && mhp_rate_ok( 'return', 60, MINUTE_IN_SECONDS ) ) {
			if ( 'success' === $kind ) {
				self::verify_and_complete( $order, sanitize_text_field( (string) $r->get_param( 'val_id' ) ), 'ফেরত' );
			} else {
				// Anyone who knows the order number can open this URL, so it never cancels anything by itself: ask the
				// gateway, and leave an unpaid order pending (the hourly job expires it after the set hours).
				self::reconcile( $order, '' );
			}
			$dest = mhp_url( 'order', $order['tran_id'] );
		}
		$resp = new WP_REST_Response( null, 303 );
		$resp->header( 'Location', esc_url_raw( $dest ) );
		$resp->header( 'Cache-Control', 'no-store' );
		return $resp;
	}

	/**
	 * Validate val_id with the gateway and complete the order.
	 *
	 * @param array  $order  Order.
	 * @param string $val_id Val id.
	 * @param string $source Label.
	 * @return string paid|hold|already|noop|invalid|error
	 */
	public static function verify_and_complete( $order, $val_id, $source ) {
		if ( 'paid' === $order['status'] ) {
			return 'already';
		}
		if ( 'refunded' === $order['status'] ) {
			return 'noop';
		}
		if ( ! MHP_Gateway_SSLCommerz::configured() ) {
			return 'error';
		}
		if ( '' === $val_id ) {
			// No val_id (e.g. a GET return): fall back to looking the transaction up by tran_id.
			return self::reconcile( $order, '' );
		}
		$ans = MHP_Gateway_SSLCommerz::validate( $val_id );
		if ( is_wp_error( $ans ) ) {
			MHP_Orders::log( $order['id'], $source . ': যাচাই করা যায়নি (' . $ans->get_error_message() . ')' );
			return 'error';
		}
		$ok = MHP_Gateway_SSLCommerz::proves_payment( $ans, $order );
		if ( true !== $ok ) {
			MHP_Orders::log( $order['id'], $source . ': পেমেন্ট প্রমাণিত হয়নি (' . $ok . ')' );
			return 'invalid';
		}
		$risk = isset( $ans['risk_level'] ) ? (int) $ans['risk_level'] : 0;
		return MHP_Orders::complete(
			$order,
			array(
				'val_id'       => $val_id,
				'bank_tran_id' => isset( $ans['bank_tran_id'] ) ? sanitize_text_field( (string) $ans['bank_tran_id'] ) : '',
				'card_type'    => isset( $ans['card_type'] ) ? sanitize_text_field( (string) $ans['card_type'] ) : '',
				'risk_level'   => $risk,
				'source'       => $source,
			),
			1 === $risk && mhp_opt( 'risk_hold', 1 )
		);
	}

	/**
	 * Ask the gateway what happened to an order (by tran_id) and settle it.
	 *
	 * @param array  $order     Order.
	 * @param string $otherwise Status to set when nothing valid is found ('' = leave pending).
	 * @return string
	 */
	public static function reconcile( $order, $otherwise ) {
		if ( ! in_array( $order['status'], array( 'pending', 'failed', 'cancelled', 'expired' ), true ) || ! MHP_Gateway_SSLCommerz::configured() ) {
			return 'noop';
		}
		$els = MHP_Gateway_SSLCommerz::query_tran( $order['tran_id'] );
		if ( is_wp_error( $els ) ) {
			MHP_Orders::log( $order['id'], 'লেনদেন খোঁজা যায়নি: ' . $els->get_error_message() );
			return 'error';
		}
		foreach ( $els as $el ) {
			if ( is_array( $el ) && true === MHP_Gateway_SSLCommerz::proves_payment( $el, $order ) ) {
				$val = isset( $el['val_id'] ) ? sanitize_text_field( (string) $el['val_id'] ) : '';
				// Confirm the element once more through the validation API when it carries a val_id.
				if ( '' !== $val ) {
					$ans = MHP_Gateway_SSLCommerz::validate( $val );
					if ( is_wp_error( $ans ) || true !== MHP_Gateway_SSLCommerz::proves_payment( $ans, $order ) ) {
						continue;
					}
				}
				$risk = isset( $el['risk_level'] ) ? (int) $el['risk_level'] : 0;
				return MHP_Orders::complete(
					$order,
					array(
						'val_id'       => $val,
						'bank_tran_id' => isset( $el['bank_tran_id'] ) ? sanitize_text_field( (string) $el['bank_tran_id'] ) : '',
						'card_type'    => isset( $el['card_type'] ) ? sanitize_text_field( (string) $el['card_type'] ) : '',
						'risk_level'   => $risk,
						'source'       => 'লেনদেন অনুসন্ধান',
					),
					1 === $risk && mhp_opt( 'risk_hold', 1 )
				);
			}
		}
		if ( $otherwise && 'pending' === $order['status'] ) {
			if ( MHP_Orders::set_status( $order['id'], $otherwise, array( 'pending' ) ) ) {
				MHP_Orders::log( $order['id'], 'failed' === $otherwise ? 'পেমেন্ট ব্যর্থ' : ( 'expired' === $otherwise ? 'সময় পেরিয়ে গেছে' : 'ক্রেতা বাতিল করেছেন' ) );
			}
			return $otherwise;
		}
		return 'noop';
	}
}
