<?php
/**
 * r47 — নিউজ এলার্ট (visitor web push) managed from the publisher app: what the ask-card says,
 * what a visitor sees after allowing alerts (icon, title line), which news goes out automatically,
 * the hourly cap, plus subscriber count and a manual "এই খবরের এলার্ট পাঠান". Thin REST layer over
 * the theme's push module (meeha_opt 'alerts' + meeha_push_*).
 *
 * @package NewsroomOS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Alerts settings REST API.
 */
class NROS_Alerts {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Routes.
	 */
	public static function register_routes() {
		register_rest_route(
			NROS_API_NS,
			'/admin/alerts',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_settings' ),
					'permission_callback' => array( 'NROS_Core', 'require_staff' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'save_settings' ),
					'permission_callback' => array( 'NROS_Core', 'require_publisher' ),
				),
			)
		);
		register_rest_route(
			NROS_API_NS,
			'/admin/alerts/send',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'send' ),
				'permission_callback' => array( 'NROS_Core', 'require_staff' ),
			)
		);
	}

	/**
	 * Is the theme's push module there?
	 *
	 * @return bool
	 */
	private static function theme_ok() {
		return function_exists( 'meeha_opt' ) && function_exists( 'meeha_opt_update' ) && function_exists( 'meeha_push_supported' );
	}

	/**
	 * The "theme missing" error.
	 *
	 * @return WP_Error
	 */
	private static function no_theme() {
		return new WP_Error( 'nros_no_theme', 'মিহা থিম সক্রিয় নেই — নিউজ এলার্ট থিমের মডিউল।', array( 'status' => 501 ) );
	}

	/**
	 * GET /admin/alerts.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function get_settings( $request ) {
		if ( ! self::theme_ok() ) {
			return self::no_theme();
		}
		$o    = meeha_opt( 'alerts' );
		$icon = 0;
		$url  = '';
		if ( ! empty( $o['icon'] ) ) {
			$icon = (int) $o['icon'];
			$src  = wp_get_attachment_image_src( $icon, array( 192, 192 ) );
			$url  = $src ? $src[0] : '';
		}
		if ( '' === $url && function_exists( 'meeha_push_icon' ) ) {
			$url = (string) meeha_push_icon( 192 );
		}
		$cats = array();
		foreach ( get_categories( array( 'hide_empty' => false ) ) as $c ) {
			$cats[] = array( 'id' => (int) $c->term_id, 'name' => (string) $c->name );
		}
		$last      = get_option( 'meeha_push_last', array() );
		$last_post = is_array( $last ) && ! empty( $last['post'] ) ? get_post( (int) $last['post'] ) : null;
		return array(
			'supported'      => (bool) meeha_push_supported(),
			'subscribers'    => function_exists( 'meeha_push_count' ) ? (int) meeha_push_count() : 0,
			'settings'       => array(
				'enabled'      => ! empty( $o['enabled'] ) || ! isset( $o['enabled'] ),
				'question'     => isset( $o['question'] ) ? (string) $o['question'] : 'মিহা নিউজের নিউজ এলার্ট পেতে চান?',
				'yes'          => isset( $o['yes'] ) ? (string) $o['yes'] : 'হ্যাঁ',
				'no'           => isset( $o['no'] ) ? (string) $o['no'] : 'না',
				'thanks'       => isset( $o['thanks'] ) ? (string) $o['thanks'] : 'ধন্যবাদ! গুরুত্বপূর্ণ খবর এলেই জানিয়ে দেব।',
				'icon'         => $icon,
				'icon_url'     => $url,
				'delay'        => isset( $o['delay'] ) ? (int) $o['delay'] : 6,
				'snooze'       => isset( $o['snooze'] ) ? (int) $o['snooze'] : 7,
				'auto'         => isset( $o['auto'] ) ? (string) $o['auto'] : 'breaking',
				'hourly_cap'   => isset( $o['hourly_cap'] ) ? (int) $o['hourly_cap'] : 3,
				'categories'   => array_map( 'intval', (array) ( isset( $o['categories'] ) ? $o['categories'] : array() ) ),
				'title_prefix' => isset( $o['title_prefix'] ) ? (string) $o['title_prefix'] : 'মিহা নিউজ',
			),
			'categories_all' => $cats,
			'last_send'      => $last_post ? array(
				'post'    => (int) $last_post->ID,
				'title'   => get_the_title( $last_post ),
				'sent'    => (int) ( isset( $last['sent'] ) ? $last['sent'] : 0 ),
				'started' => (int) ( isset( $last['started'] ) ? $last['started'] : 0 ),
			) : null,
		);
	}

	/**
	 * POST /admin/alerts — save the visitor-alert settings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function save_settings( $request ) {
		if ( ! self::theme_ok() ) {
			return self::no_theme();
		}
		$values = array();
		$map    = array(
			'enabled'      => function ( $v ) { return empty( $v ) ? 0 : 1; },
			'question'     => 'sanitize_text_field',
			'yes'          => 'sanitize_text_field',
			'no'           => 'sanitize_text_field',
			'thanks'       => 'sanitize_text_field',
			'icon'         => 'absint',
			'delay'        => function ( $v ) { return max( 0, min( 120, (int) $v ) ); },
			'snooze'       => function ( $v ) { return max( 1, min( 365, (int) $v ) ); },
			'auto'         => function ( $v ) { return in_array( $v, array( 'all', 'breaking', 'off' ), true ) ? $v : 'breaking'; },
			'hourly_cap'   => function ( $v ) { return max( 1, min( 30, (int) $v ) ); },
			'title_prefix' => 'sanitize_text_field',
		);
		foreach ( $map as $key => $fn ) {
			if ( null !== $request->get_param( $key ) ) {
				$values[ $key ] = call_user_func( $fn, $request->get_param( $key ) );
			}
		}
		if ( null !== $request->get_param( 'categories' ) ) {
			$values['categories'] = array_values( array_filter( array_map( 'intval', (array) $request->get_param( 'categories' ) ) ) );
		}
		if ( ! $values ) {
			return new WP_Error( 'nros_empty', 'কিছু পাঠানো হয়নি।', array( 'status' => 400 ) );
		}
		meeha_opt_update( 'alerts', $values );
		return array( 'ok' => true );
	}

	/**
	 * POST /admin/alerts/send {post_id} — queue a manual alert for a published post.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function send( $request ) {
		if ( ! self::theme_ok() || ! function_exists( 'meeha_push_queue' ) ) {
			return self::no_theme();
		}
		$id = absint( $request->get_param( 'post_id' ) );
		if ( ! $id || 'publish' !== get_post_status( $id ) ) {
			return new WP_Error( 'nros_bad_post', 'প্রকাশিত খবর লাগবে।', array( 'status' => 400 ) );
		}
		if ( ! meeha_push_queue( $id ) ) {
			return new WP_Error( 'nros_push_off', 'পুশ এখন কাজ করছে না (VAPID/সাপোর্ট দেখুন)।', array( 'status' => 409 ) );
		}
		return array( 'ok' => true, 'post' => $id, 'subscribers' => function_exists( 'meeha_push_count' ) ? (int) meeha_push_count() : 0 );
	}
}
