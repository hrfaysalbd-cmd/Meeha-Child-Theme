<?php
/**
 * SSLCommerz (v4 hosted payment page). Nothing the browser or the IPN POST says is trusted on its own: every
 * payment is confirmed server-to-server through the Validation API (val_id) or the Transaction Query API
 * (tran_id), and the amount, currency and tran_id must match our order.
 *
 * Endpoints follow SSLCommerz's public developer documentation (v4). Verify them against the merchant panel's
 * current docs before going live.
 *
 * @package meeha-prep
 */

defined( 'ABSPATH' ) || exit;

/**
 * Gateway client.
 */
class MHP_Gateway_SSLCommerz {

	/**
	 * API base for the configured mode.
	 *
	 * @return string
	 */
	public static function base() {
		return 'live' === self::creds()['mode'] ? 'https://securepay.sslcommerz.com' : 'https://sandbox.sslcommerz.com';
	}

	/**
	 * Credentials and mode. Default source: the existing "Meeha Donation" plugin's SSLCommerz settings
	 * (option meeha_donation_settings), so the store is set up in one place only.
	 *
	 * @return array id, pass, mode (sandbox|live), source (donation|own)
	 */
	public static function creds() {
		if ( 'own' !== mhp_opt( 'cred_source', 'donation' ) ) {
			$d = get_option( 'meeha_donation_settings', array() );
			if ( is_array( $d ) && ! empty( $d['ssl_store_id'] ) && ! empty( $d['ssl_store_password'] ) ) {
				return array(
					'id'     => (string) $d['ssl_store_id'],
					'pass'   => (string) $d['ssl_store_password'],
					'mode'   => empty( $d['ssl_sandbox'] ) ? 'live' : 'sandbox',
					'source' => 'donation',
				);
			}
		}
		return array(
			'id'     => trim( (string) mhp_opt( 'ssl_store_id', '' ) ),
			'pass'   => (string) mhp_opt( 'ssl_store_pass', '' ),
			'mode'   => 'live' === mhp_opt( 'pay_mode', 'sandbox' ) ? 'live' : 'sandbox',
			'source' => 'own',
		);
	}

	/**
	 * Donation plugin settings present?
	 *
	 * @return bool
	 */
	public static function donation_creds_exist() {
		$d = get_option( 'meeha_donation_settings', array() );
		return is_array( $d ) && ! empty( $d['ssl_store_id'] ) && ! empty( $d['ssl_store_password'] );
	}

	/**
	 * Store credentials present?
	 *
	 * @return bool
	 */
	public static function configured() {
		$c = self::creds();
		return '' !== $c['id'] && '' !== $c['pass'];
	}

	/**
	 * Is online payment usable for the current user right now?
	 *
	 * @return bool
	 */
	public static function available() {
		if ( ! mhp_opt( 'pay_on', 0 ) || ! self::configured() ) {
			return false;
		}
		if ( mhp_opt( 'pay_staff_only', 1 ) && ! mhp_can_manage() ) {
			return false;
		}
		return true;
	}

	/**
	 * Start a hosted payment session.
	 *
	 * @param array   $order Order row.
	 * @param WP_User $user  Buyer.
	 * @return string|WP_Error Gateway page URL.
	 */
	public static function init_session( $order, $user ) {
		$phone = $order['phone'] ? $order['phone'] : (string) get_user_meta( $user->ID, 'meeha_phone', true );
		$phone = preg_replace( '/[^0-9+]/', '', meeha_prep_en_digits( $phone ) );
		$name  = trim( (string) $user->display_name );
		$body  = array(
			'store_id'         => self::creds()['id'],
			'store_passwd'     => self::creds()['pass'],
			'total_amount'     => number_format( (float) $order['amount'], 2, '.', '' ),
			'currency'         => 'BDT',
			'tran_id'          => $order['tran_id'],
			'success_url'      => MHP_Checkout::callback_url( 'success', $order['tran_id'] ),
			'fail_url'         => MHP_Checkout::callback_url( 'fail', $order['tran_id'] ),
			'cancel_url'       => MHP_Checkout::callback_url( 'cancel', $order['tran_id'] ),
			'ipn_url'          => MHP_Checkout::callback_url( 'ipn' ),
			'cus_name'         => '' !== $name ? mb_substr( $name, 0, 50 ) : 'Meeha Reader',
			'cus_email'        => $user->user_email,
			'cus_add1'         => (string) mhp_opt( 'cus_city', 'Dhaka' ),
			'cus_city'         => (string) mhp_opt( 'cus_city', 'Dhaka' ),
			'cus_postcode'     => '1000',
			'cus_country'      => 'Bangladesh',
			'cus_phone'        => '' !== $phone ? $phone : '01700000000',
			'shipping_method'  => 'NO',
			'num_of_item'      => 1,
			'product_name'     => mb_substr( (string) $order['item_name'], 0, 250 ),
			'product_category' => 'Education',
			'product_profile'  => 'non-physical-goods',
			'value_a'          => 'meeha-prep',
			'value_b'          => (string) $order['id'],
		);
		$res = wp_remote_post(
			self::base() . '/gwprocess/v4/api.php',
			array(
				'timeout' => 30,
				'body'    => $body,
			)
		);
		if ( is_wp_error( $res ) ) {
			MHP_Orders::log( $order['id'], 'গেটওয়ে সংযোগ ব্যর্থ: ' . $res->get_error_message() );
			return new WP_Error( 'mhp_gw', 'পেমেন্ট গেটওয়েতে সংযোগ হয়নি। কিছুক্ষণ পরে আবার চেষ্টা করুন।' );
		}
		$j = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $j ) || 'SUCCESS' !== ( isset( $j['status'] ) ? strtoupper( (string) $j['status'] ) : '' ) || empty( $j['GatewayPageURL'] ) ) {
			$reason = is_array( $j ) && ! empty( $j['failedreason'] ) ? sanitize_text_field( (string) $j['failedreason'] ) : 'অজানা উত্তর (HTTP ' . (int) wp_remote_retrieve_response_code( $res ) . ')';
			MHP_Orders::log( $order['id'], 'গেটওয়ে সেশন হয়নি: ' . $reason );
			return new WP_Error( 'mhp_gw', 'পেমেন্ট শুরু করা যায়নি। পরে আবার চেষ্টা করুন।' );
		}
		$url  = esc_url_raw( (string) $j['GatewayPageURL'] );
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $host || ! preg_match( '/(^|\.)sslcommerz\.com$/i', $host ) ) {
			MHP_Orders::log( $order['id'], 'অপ্রত্যাশিত গেটওয়ে ঠিকানা প্রত্যাখ্যান: ' . $host );
			return new WP_Error( 'mhp_gw', 'পেমেন্ট শুরু করা যায়নি।' );
		}
		return $url;
	}

	/**
	 * Validate a payment by val_id (server-to-server).
	 *
	 * @param string $val_id Val id.
	 * @return array|WP_Error Decoded answer.
	 */
	public static function validate( $val_id ) {
		$val_id = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $val_id );
		if ( '' === $val_id ) {
			return new WP_Error( 'mhp_val', 'val_id নেই' );
		}
		$url = add_query_arg(
			array(
				'val_id'       => rawurlencode( $val_id ),
				'store_id'     => rawurlencode( self::creds()['id'] ),
				'store_passwd' => rawurlencode( self::creds()['pass'] ),
				'v'            => 1,
				'format'       => 'json',
			),
			self::base() . '/validator/api/validationserverAPI.php'
		);
		$res = wp_remote_get( $url, array( 'timeout' => 30 ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$j = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		return is_array( $j ) ? $j : new WP_Error( 'mhp_val', 'যাচাইয়ের উত্তর বোঝা যায়নি' );
	}

	/**
	 * Look a transaction up by our tran_id (for orders whose callback never arrived).
	 *
	 * @param string $tran_id Tran id.
	 * @return array|WP_Error List of transaction elements.
	 */
	public static function query_tran( $tran_id ) {
		$url = add_query_arg(
			array(
				'tran_id'      => rawurlencode( (string) $tran_id ),
				'store_id'     => rawurlencode( self::creds()['id'] ),
				'store_passwd' => rawurlencode( self::creds()['pass'] ),
				'format'       => 'json',
			),
			self::base() . '/validator/api/merchantTransIDvalidationAPI.php'
		);
		$res = wp_remote_get( $url, array( 'timeout' => 30 ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$j = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $j ) ) {
			return new WP_Error( 'mhp_q', 'উত্তর বোঝা যায়নি' );
		}
		return isset( $j['element'] ) && is_array( $j['element'] ) ? $j['element'] : array();
	}

	/**
	 * Does a gateway answer prove that this order was paid in full?
	 *
	 * @param array $answer Validation answer or query element.
	 * @param array $order  Order row.
	 * @return true|string True or the reason it does not.
	 */
	public static function proves_payment( $answer, $order ) {
		$status = strtoupper( isset( $answer['status'] ) ? (string) $answer['status'] : '' );
		if ( ! in_array( $status, array( 'VALID', 'VALIDATED' ), true ) ) {
			return 'status ' . $status;
		}
		if ( ! isset( $answer['tran_id'] ) || ! hash_equals( (string) $order['tran_id'], (string) $answer['tran_id'] ) ) {
			return 'tran_id মেলেনি';
		}
		// currency_type/currency_amount describe what the buyer was charged in; we sell in BDT only.
		$cur = ! empty( $answer['currency_type'] ) ? (string) $answer['currency_type'] : ( isset( $answer['currency'] ) ? (string) $answer['currency'] : '' );
		if ( 'BDT' !== strtoupper( $cur ) ) {
			return 'মুদ্রা ' . $cur;
		}
		$amt = isset( $answer['currency_amount'] ) && '' !== (string) $answer['currency_amount'] ? (float) $answer['currency_amount'] : ( isset( $answer['amount'] ) ? (float) $answer['amount'] : -1 );
		if ( abs( $amt - (float) $order['amount'] ) > 0.009 ) {
			return 'টাকার অঙ্ক মেলেনি (' . $amt . ')';
		}
		return true;
	}
}
