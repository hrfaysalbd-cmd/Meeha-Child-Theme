<?php
/**
 * r47 — বিজ্ঞাপন ব্যবস্থাপনা: the publisher app manages the theme's ad slots (meeha_opt 'ads')
 * over REST — every placement (header, front page, in-article, category, footer, photocard strip),
 * the animated house cards, the size guide, and a one-click "সব জায়গায় হাউস বিজ্ঞাপন" switch.
 * Reads need staff; writes need the publisher. Works only while the Meeha theme is active.
 *
 * @package NewsroomOS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Ads manager REST API.
 */
class NROS_Ads_Manager {

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
			'/admin/ads-manager',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'overview' ),
				'permission_callback' => array( 'NROS_Core', 'require_staff' ),
			)
		);
		register_rest_route(
			NROS_API_NS,
			'/admin/ads-manager/slot/(?P<slot>[a-z0-9_]+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'save_slot' ),
				'permission_callback' => array( 'NROS_Core', 'require_publisher' ),
			)
		);
		register_rest_route(
			NROS_API_NS,
			'/admin/ads-manager/strip',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'save_strip' ),
				'permission_callback' => array( 'NROS_Core', 'require_publisher' ),
			)
		);
		register_rest_route(
			NROS_API_NS,
			'/admin/ads-manager/enable-house',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'enable_house_everywhere' ),
				'permission_callback' => array( 'NROS_Core', 'require_publisher' ),
			)
		);
	}

	/**
	 * Is the Meeha theme (its ads module) active?
	 *
	 * @return bool
	 */
	private static function theme_ok() {
		return function_exists( 'meeha_ad_slots' ) && function_exists( 'meeha_opt' ) && function_exists( 'meeha_opt_update' );
	}

	/**
	 * The "theme missing" error.
	 *
	 * @return WP_Error
	 */
	private static function no_theme() {
		return new WP_Error( 'nros_no_theme', 'মিহা থিম সক্রিয় নেই — বিজ্ঞাপন ব্যবস্থাপনা থিমের সঙ্গে কাজ করে।', array( 'status' => 501 ) );
	}

	/**
	 * Image info for an attachment id.
	 *
	 * @param int $id Attachment.
	 * @return array|null
	 */
	private static function image( $id ) {
		$id = (int) $id;
		if ( ! $id ) {
			return null;
		}
		$src = wp_get_attachment_image_src( $id, 'full' );
		if ( ! $src ) {
			return null;
		}
		$file = get_attached_file( $id );
		return array(
			'id'    => $id,
			'url'   => $src[0],
			'w'     => (int) $src[1],
			'h'     => (int) $src[2],
			'bytes' => $file && file_exists( $file ) ? (int) filesize( $file ) : 0,
			'mime'  => (string) get_post_mime_type( $id ),
		);
	}

	/**
	 * Human size guide for a slot size.
	 *
	 * @param string $size leader|mpu|half.
	 * @return string
	 */
	private static function size_text( $size ) {
		$map = array(
			'leader' => 'ডেস্কটপ ৯৭০×৯০ বা ৭২৮×৯০ · মোবাইল ৩২০×১০০ (বা ৩২০×৫০)',
			'mpu'    => '৩০০×২৫০ (মিডিয়াম রেক্ট্যাঙ্গেল)',
			'half'   => '৩০০×৬০০ (হাফ পেজ) — ৩০০×২৫০-ও চলে',
		);
		return isset( $map[ $size ] ) ? $map[ $size ] : '';
	}

	/**
	 * UI group of a slot id.
	 *
	 * @param string $slot Slot.
	 * @return string
	 */
	private static function group_of( $slot ) {
		if ( 0 === strpos( $slot, 'header' ) ) {
			return 'header';
		}
		if ( 0 === strpos( $slot, 'home' ) ) {
			return 'home';
		}
		if ( 0 === strpos( $slot, 'article' ) ) {
			return 'article';
		}
		if ( 0 === strpos( $slot, 'archive' ) ) {
			return 'archive';
		}
		if ( 0 === strpos( $slot, 'footer' ) ) {
			return 'footer';
		}
		return 'other';
	}

	/**
	 * GET /admin/ads-manager — the whole picture for the publisher screen.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function overview( $request ) {
		if ( ! self::theme_ok() ) {
			return self::no_theme();
		}
		$o     = meeha_opt( 'ads' );
		$slots = array();
		foreach ( meeha_ad_slots() as $id => $def ) {
			$slots[] = array(
				'id'        => $id,
				'label'     => $def[0],
				'size'      => $def[1],
				'size_text' => self::size_text( $def[1] ),
				'group'     => self::group_of( $id ),
				'on'        => ! empty( $o[ $id . '_on' ] ),
				'type'      => isset( $o[ $id . '_type' ] ) ? (string) $o[ $id . '_type' ] : 'image',
				'house'     => isset( $o[ $id . '_house' ] ) ? (string) $o[ $id . '_house' ] : '',
				'image'     => self::image( isset( $o[ $id . '_image' ] ) ? $o[ $id . '_image' ] : 0 ),
				'image_m'   => self::image( isset( $o[ $id . '_image_m' ] ) ? $o[ $id . '_image_m' ] : 0 ),
				'link'      => isset( $o[ $id . '_link' ] ) ? (string) $o[ $id . '_link' ] : '',
				'alt'       => isset( $o[ $id . '_alt' ] ) ? (string) $o[ $id . '_alt' ] : 'বিজ্ঞাপন',
				'device'    => isset( $o[ $id . '_device' ] ) ? (string) $o[ $id . '_device' ] : 'all',
				'start'     => isset( $o[ $id . '_start' ] ) ? (string) $o[ $id . '_start' ] : '',
				'end'       => isset( $o[ $id . '_end' ] ) ? (string) $o[ $id . '_end' ] : '',
				'label_on'  => ! isset( $o[ $id . '_label' ] ) || ! empty( $o[ $id . '_label' ] ),
				'code'      => isset( $o[ $id . '_code' ] ) ? (string) $o[ $id . '_code' ] : '',
				'shortcode' => isset( $o[ $id . '_shortcode' ] ) ? (string) $o[ $id . '_shortcode' ] : '',
				'gam'       => isset( $o[ $id . '_gam' ] ) ? (string) $o[ $id . '_gam' ] : '',
			);
		}
		$strip = array();
		foreach ( (array) ( isset( $o['card_strip'] ) ? $o['card_strip'] : array() ) as $row ) {
			$strip[] = array(
				'label' => isset( $row['label'] ) ? (string) $row['label'] : '',
				'image' => self::image( isset( $row['image'] ) ? $row['image'] : 0 ),
				'link'  => isset( $row['link'] ) ? (string) $row['link'] : '',
				'on'    => ! empty( $row['on'] ),
			);
		}
		$house     = array();
		$house_css = '';
		if ( function_exists( 'meeha_house_ads' ) && function_exists( 'meeha_house_ad_html' ) ) {
			$house_css = function_exists( 'meeha_house_ad_css' ) ? meeha_house_ad_css() : '';
			foreach ( meeha_house_ads() as $hid => $hc ) {
				$house[] = array(
					'id'      => $hid,
					'label'   => (string) $hc['label'],
					'title'   => (string) $hc['title'],
					'line'    => (string) $hc['line'],
					'cta'     => (string) $hc['cta'],
					'url'     => is_callable( $hc['url'] ) ? (string) call_user_func( $hc['url'] ) : (string) $hc['url'],
					'preview' => array(
						'leader' => meeha_house_ad_html( $hid, 'leader' ),
						'mpu'    => meeha_house_ad_html( $hid, 'mpu' ),
					),
				);
			}
		}
		return array(
			'theme_ok'   => true,
			'slots'      => $slots,
			'strip'      => $strip,
			'house'      => $house,
			'house_css'  => $house_css,
			'gif_limit'  => 150 * KB_IN_BYTES,
			'size_guide' => array(
				array( 'name' => 'লিডারবোর্ড (ডেস্কটপ)', 'px' => '৯৭০×৯০ / ৭২৮×৯০', 'note' => 'হেডার, হোম, বিভাগ পাতা ও ফুটারের বড় ব্যানার' ),
				array( 'name' => 'মোবাইল ব্যানার', 'px' => '৩২০×১০০ / ৩২০×৫০', 'note' => 'লিডারবোর্ড স্লটের মোবাইল ছবি' ),
				array( 'name' => 'মিডিয়াম রেক্ট্যাঙ্গেল', 'px' => '৩০০×২৫০', 'note' => 'খবরের ভেতরে, খবরের শেষে, হোম ও বিভাগ পাতার পাশে' ),
				array( 'name' => 'হাফ পেজ', 'px' => '৩০০×৬০০', 'note' => 'খবরের পাশে (ডেস্কটপ) — সবচেয়ে নজরকাড়া' ),
				array( 'name' => 'ফটোকার্ড স্ট্রিপ', 'px' => '১০৮০×১২০', 'note' => 'ফটোকার্ড স্টুডিওর নিচের বিজ্ঞাপন স্ট্রিপ' ),
				array( 'name' => 'অ্যানিমেটেড GIF', 'px' => 'সর্বোচ্চ ১৫০ KB', 'note' => 'বড় হলে WebP/MP4 করুন, না হলে গ্রহণ হবে না' ),
			),
		);
	}

	/**
	 * Animated-GIF rule: reject a GIF creative over 150 KB, like the theme desk does.
	 *
	 * @param int $id Attachment id.
	 * @return true|WP_Error
	 */
	private static function check_creative( $id ) {
		$id = (int) $id;
		if ( ! $id ) {
			return true;
		}
		$file = get_attached_file( $id );
		if ( $file && file_exists( $file ) && filesize( $file ) > 150 * KB_IN_BYTES && 'image/gif' === get_post_mime_type( $id ) ) {
			return new WP_Error( 'nros_gif_too_big', 'GIF ছবিটি ১৫০ KB-এর বেশি — পাতা ভারী হয়ে যাবে। WebP বা ছোট GIF দিন।', array( 'status' => 400 ) );
		}
		return true;
	}

	/**
	 * POST /admin/ads-manager/slot/{slot} — save one placement's settings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function save_slot( $request ) {
		if ( ! self::theme_ok() ) {
			return self::no_theme();
		}
		$slot  = sanitize_key( $request['slot'] );
		$slots = meeha_ad_slots();
		if ( ! isset( $slots[ $slot ] ) ) {
			return new WP_Error( 'nros_bad_slot', 'অজানা স্লট।', array( 'status' => 404 ) );
		}
		$user   = NROS_Auth::get_user_from_request( $request );
		$values = array();
		$date   = function ( $v ) {
			$v = trim( (string) $v );
			return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : '';
		};
		$map = array(
			'on'        => function ( $v ) { return empty( $v ) ? 0 : 1; },
			'type'      => function ( $v ) { return in_array( $v, array( 'image', 'house', 'html', 'shortcode', 'gam' ), true ) ? $v : 'image'; },
			'house'     => function ( $v ) { return sanitize_key( $v ); },
			'image'     => 'absint',
			'image_m'   => 'absint',
			'link'      => 'esc_url_raw',
			'alt'       => 'sanitize_text_field',
			'device'    => function ( $v ) { return in_array( $v, array( 'all', 'mobile', 'desktop' ), true ) ? $v : 'all'; },
			'start'     => $date,
			'end'       => $date,
			'label'     => function ( $v ) { return empty( $v ) ? 0 : 1; },
			'shortcode' => 'sanitize_text_field',
			'gam'       => 'sanitize_text_field',
		);
		foreach ( $map as $key => $fn ) {
			if ( null !== $request->get_param( $key ) ) {
				$values[ $slot . '_' . $key ] = call_user_func( $fn, $request->get_param( $key ) );
			}
		}
		if ( null !== $request->get_param( 'code' ) ) {
			$code = (string) $request->get_param( 'code' );
			// Third-party tags stay exact only for users trusted with unfiltered HTML; others get filtered markup.
			$values[ $slot . '_code' ] = $user && ! is_wp_error( $user ) && user_can( $user, 'unfiltered_html' ) ? $code : wp_kses_post( $code );
		}
		foreach ( array( $slot . '_image', $slot . '_image_m' ) as $k ) {
			if ( isset( $values[ $k ] ) ) {
				$ok = self::check_creative( $values[ $k ] );
				if ( is_wp_error( $ok ) ) {
					return $ok;
				}
			}
		}
		if ( ! $values ) {
			return new WP_Error( 'nros_empty', 'কিছু পাঠানো হয়নি।', array( 'status' => 400 ) );
		}
		meeha_opt_update( 'ads', $values );
		return array( 'ok' => true, 'slot' => $slot );
	}

	/**
	 * POST /admin/ads-manager/strip — replace the photocard strip rows.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function save_strip( $request ) {
		if ( ! self::theme_ok() ) {
			return self::no_theme();
		}
		$rows = $request->get_param( 'rows' );
		if ( ! is_array( $rows ) ) {
			return new WP_Error( 'nros_bad_rows', 'rows লাগবে।', array( 'status' => 400 ) );
		}
		$clean = array();
		foreach ( array_slice( $rows, 0, 20 ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$img = isset( $row['image'] ) ? absint( $row['image'] ) : 0;
			$ok  = self::check_creative( $img );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
			$clean[] = array(
				'label' => isset( $row['label'] ) ? sanitize_text_field( $row['label'] ) : '',
				'image' => $img,
				'link'  => isset( $row['link'] ) ? esc_url_raw( $row['link'] ) : '',
				'on'    => empty( $row['on'] ) ? 0 : 1,
			);
		}
		meeha_opt_update( 'ads', array( 'card_strip' => $clean ) );
		return array( 'ok' => true, 'count' => count( $clean ) );
	}

	/**
	 * POST /admin/ads-manager/enable-house — "প্রত্যেক জায়গায় বিজ্ঞাপন চালু": every slot that has
	 * nothing of its own switches on with a fitting house card (slots already configured keep
	 * their settings — only `on` is set). Rotation spreads the cards so one page never repeats.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function enable_house_everywhere( $request ) {
		if ( ! self::theme_ok() ) {
			return self::no_theme();
		}
		if ( ! function_exists( 'meeha_house_ads' ) ) {
			return new WP_Error( 'nros_no_house', 'থিমে হাউস কার্ড নেই — থিম আপডেট করুন।', array( 'status' => 501 ) );
		}
		// A deliberate spread: which card fits which placement best.
		$plan = array(
			'header_leader'  => 'youtube',
			'home_leader'    => 'app',
			'home_mpu_1'     => 'assistant',
			'home_mpu_2'     => 'tips',
			'article_1'      => 'factcheck',
			'article_2'      => 'saved',
			'article_side'   => 'campus',
			'article_end'    => 'youtube',
			'archive_infeed' => 'newsletter',
			'archive_side'   => 'tips',
			'footer_leader'  => 'campus',
		);
		$cards  = meeha_house_ads();
		$ids    = array_keys( $cards );
		$o      = meeha_opt( 'ads' );
		$values = array();
		$i      = 0;
		$done   = array();
		foreach ( meeha_ad_slots() as $slot => $def ) {
			$values[ $slot . '_on' ] = 1;
			$configured = ! empty( $o[ $slot . '_image' ] ) || ! empty( $o[ $slot . '_code' ] ) || ! empty( $o[ $slot . '_shortcode' ] ) || ! empty( $o[ $slot . '_gam' ] ) || ! empty( $o[ $slot . '_house' ] );
			if ( $configured ) {
				$done[] = array( 'slot' => $slot, 'card' => isset( $o[ $slot . '_house' ] ) ? (string) $o[ $slot . '_house' ] : '(নিজস্ব সেটিং)' );
				continue;
			}
			$card = isset( $plan[ $slot ] ) && isset( $cards[ $plan[ $slot ] ] ) ? $plan[ $slot ] : $ids[ $i % count( $ids ) ];
			++$i;
			$values[ $slot . '_type' ]  = 'house';
			$values[ $slot . '_house' ] = $card;
			$done[]                     = array( 'slot' => $slot, 'card' => $card );
		}
		meeha_opt_update( 'ads', $values );
		return array( 'ok' => true, 'slots' => $done );
	}
}
