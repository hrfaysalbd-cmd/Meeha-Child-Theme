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
			'/admin/ads-manager/globals',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'save_globals' ),
				'permission_callback' => array( 'NROS_Core', 'require_publisher' ),
			)
		);
		register_rest_route(
			NROS_API_NS,
			'/admin/ads-manager/reader',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'save_reader' ),
				'permission_callback' => array( 'NROS_Core', 'require_publisher' ),
			)
		);
		register_rest_route(
			NROS_API_NS,
			'/admin/ads-manager/library',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'library' ),
					'permission_callback' => array( 'NROS_Core', 'require_staff' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'library_mark' ),
					'permission_callback' => array( 'NROS_Core', 'require_publisher' ),
				),
			)
		);
		register_rest_route(
			NROS_API_NS,
			'/admin/ads-manager/create-page',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'create_page' ),
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
			'float'  => 'মোবাইল ৩২০×৫০ (বা ৩২০×১০০) · ডেস্কটপ ৭২৮×৯০ — পর্দার নিচে ভাসে, ক্রস চাপলে বন্ধ',
			'corner' => '৩০০×২৫০ — ডেস্কটপে ডান দিকের নিচের কোণায়, ক্রস সহ',
			'popup'  => 'ডেস্কটপ ৬০০×৫০০ · মোবাইল ৩০০×২৫০ বা ৩২০×৪৮০ — কয়েক সেকেন্ড পরে খোলে, ক্রস সহ',
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
		if ( 0 === strpos( $slot, 'float' ) || 'popup' === $slot ) {
			return 'floating';
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
				'client'    => isset( $o[ $id . '_client' ] ) ? absint( $o[ $id . '_client' ] ) : 0,
				'mobile_ok' => in_array( $def[1], array( 'leader', 'float', 'popup' ), true ),
				'live'      => self::is_live( $o, $id ),
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
		$specs = function_exists( 'meeha_ad_specs' ) ? array_values( (array) meeha_ad_specs() ) : array();
		return array(
			'theme_ok'   => true,
			'globals'    => self::globals( $o ),
			'reader'     => self::reader(),
			'clients'    => self::clients(),
			'specs'      => $specs,
			'stats'      => class_exists( 'NROS_Ad_Stats' ) ? NROS_Ad_Stats::summary( 7 ) : null,
			'pages'      => self::landing_pages(),
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
			'client'    => 'absint',
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
		foreach ( array( $slot . '_image', $slot . '_image_m' ) as $k ) {
			if ( ! empty( $values[ $k ] ) ) {
				self::mark_creative( $values[ $k ] );
			}
		}
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
		foreach ( $clean as $row ) {
			if ( $row['image'] ) {
				self::mark_creative( $row['image'] );
			}
		}
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
			// Floating bars and the pop-up interrupt reading — they are switched on only on purpose.
			if ( in_array( $def[1], array( 'float', 'corner', 'popup' ), true ) ) {
				continue;
			}
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

	/**
	 * Is a slot showing right now (on, inside its dates, has something to show)?
	 *
	 * @param array  $o    Ads options.
	 * @param string $slot Slot.
	 * @return bool
	 */
	private static function is_live( $o, $slot ) {
		if ( empty( $o[ $slot . '_on' ] ) ) {
			return false;
		}
		$today = current_time( 'Y-m-d' );
		if ( ! empty( $o[ $slot . '_start' ] ) && $today < $o[ $slot . '_start' ] ) {
			return false;
		}
		if ( ! empty( $o[ $slot . '_end' ] ) && $today > $o[ $slot . '_end' ] ) {
			return false;
		}
		$type = isset( $o[ $slot . '_type' ] ) ? $o[ $slot . '_type' ] : 'image';
		$map  = array( 'image' => '_image', 'house' => '_house', 'html' => '_code', 'shortcode' => '_shortcode', 'gam' => '_gam' );
		return isset( $map[ $type ] ) && ! empty( $o[ $slot . $map[ $type ] ] );
	}

	/**
	 * Site-wide ad settings.
	 *
	 * @param array $o Ads options.
	 * @return array
	 */
	private static function globals( $o ) {
		$g = function ( $k, $d ) use ( $o ) {
			return isset( $o[ $k ] ) && '' !== $o[ $k ] ? $o[ $k ] : $d;
		};
		return array(
			'label_style' => (string) $g( 'label_style', 'corner' ),
			'popup_delay' => (int) $g( 'popup_delay', 6 ),
			'popup_every' => (int) $g( 'popup_every', 12 ),
			'popup_pages' => (string) $g( 'popup_pages', 'all' ),
			'float_every' => (int) $g( 'float_every', 6 ),
			'ad_phone'    => (string) $g( 'ad_phone', '' ),
			'ad_whatsapp' => (string) $g( 'ad_whatsapp', '' ),
			'ad_email'    => (string) $g( 'ad_email', '' ),
			'stats_on'    => ! isset( $o['stats_on'] ) || ! empty( $o['stats_on'] ),
		);
	}

	/**
	 * POST /admin/ads-manager/globals.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function save_globals( $request ) {
		if ( ! self::theme_ok() ) {
			return self::no_theme();
		}
		$num = function ( $min, $max ) {
			return function ( $v ) use ( $min, $max ) {
				return max( $min, min( $max, (int) $v ) );
			};
		};
		$map = array(
			'label_style' => function ( $v ) { return in_array( $v, array( 'corner', 'side', 'top', 'none' ), true ) ? $v : 'corner'; },
			'popup_delay' => $num( 0, 120 ),
			'popup_every' => $num( 1, 720 ),
			'popup_pages' => function ( $v ) { return in_array( $v, array( 'all', 'single', 'home' ), true ) ? $v : 'all'; },
			'float_every' => $num( 1, 720 ),
			'ad_phone'    => 'sanitize_text_field',
			'ad_whatsapp' => function ( $v ) { return preg_replace( '/[^\d]/', '', (string) $v ); },
			'ad_email'    => 'sanitize_email',
			'stats_on'    => function ( $v ) { return empty( $v ) ? 0 : 1; },
		);
		$values = array();
		foreach ( $map as $k => $fn ) {
			if ( null !== $request->get_param( $k ) ) {
				$values[ $k ] = call_user_func( $fn, $request->get_param( $k ) );
			}
		}
		if ( ! $values ) {
			return new WP_Error( 'nros_empty', 'কিছু পাঠানো হয়নি।', array( 'status' => 400 ) );
		}
		meeha_opt_update( 'ads', $values );
		delete_transient( 'meeha_adv_numbers' );
		return array( 'ok' => true, 'globals' => self::globals( meeha_opt( 'ads' ) ) );
	}

	/**
	 * Reader-side photocard ads (the strip under "ফটোকার্ড ডাউনলোড" on news and quote pages).
	 *
	 * @return array
	 */
	private static function reader() {
		$get = function ( $k, $d ) {
			if ( function_exists( 'meeha_studio_opt' ) ) {
				return meeha_studio_opt( $k, $d );
			}
			return function_exists( 'meeha_opt' ) ? meeha_opt( 'photocard', $k, $d ) : $d;
		};
		return array(
			'available'   => function_exists( 'meeha_studio_opt' ),
			'news_on'     => ! empty( $get( 'news_reader_ad', 0 ) ),
			'news_image'  => self::image( $get( 'news_reader_ad_image', 0 ) ),
			'quote_on'    => ! empty( $get( 'quote_reader_ad', 0 ) ),
			'quote_image' => self::image( $get( 'quote_reader_ad_image', 0 ) ),
		);
	}

	/**
	 * POST /admin/ads-manager/reader {news_on, news_image, quote_on, quote_image}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function save_reader( $request ) {
		if ( ! self::theme_ok() ) {
			return self::no_theme();
		}
		$map    = array(
			'news_on'     => 'news_reader_ad',
			'news_image'  => 'news_reader_ad_image',
			'quote_on'    => 'quote_reader_ad',
			'quote_image' => 'quote_reader_ad_image',
		);
		$values = array();
		foreach ( $map as $in => $key ) {
			$v = $request->get_param( $in );
			if ( null === $v ) {
				continue;
			}
			if ( '_on' === substr( $in, -3 ) ) {
				$values[ $key ] = empty( $v ) ? 0 : 1;
			} else {
				$values[ $key ] = absint( $v );
				$ok             = self::check_creative( $values[ $key ] );
				if ( is_wp_error( $ok ) ) {
					return $ok;
				}
				if ( $values[ $key ] ) {
					self::mark_creative( $values[ $key ] );
				}
			}
		}
		if ( ! $values ) {
			return new WP_Error( 'nros_empty', 'কিছু পাঠানো হয়নি।', array( 'status' => 400 ) );
		}
		meeha_opt_update( 'photocard', $values );
		return array( 'ok' => true, 'reader' => self::reader() );
	}

	/**
	 * Active ad clients for the "কোন ক্লায়েন্টের বিজ্ঞাপন" select.
	 *
	 * @return array
	 */
	private static function clients() {
		global $wpdb;
		$rows = (array) $wpdb->get_results( "SELECT id, business_name, phone, status FROM {$wpdb->prefix}nros_ad_clients ORDER BY business_name ASC LIMIT 500", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out  = array();
		foreach ( $rows as $r ) {
			$out[] = array(
				'id'     => (int) $r['id'],
				'name'   => (string) $r['business_name'],
				'phone'  => (string) $r['phone'],
				'status' => (string) $r['status'],
			);
		}
		return $out;
	}

	/**
	 * Remember an image as an ad creative, so the ad gallery shows it again.
	 *
	 * @param int $id Attachment.
	 */
	private static function mark_creative( $id ) {
		$id = absint( $id );
		if ( $id && 'attachment' === get_post_type( $id ) ) {
			update_post_meta( $id, '_nros_ad_creative', time() );
		}
	}

	/**
	 * Every image an ad uses now (slots, strip, reader cards, the video studio's ad picks).
	 *
	 * @return int[]
	 */
	private static function used_ids() {
		$ids = array();
		$o   = function_exists( 'meeha_opt' ) ? meeha_opt( 'ads' ) : array();
		foreach ( (array) $o as $k => $v ) {
			if ( preg_match( '/_image(_m)?$/', (string) $k ) && absint( $v ) ) {
				$ids[] = absint( $v );
			}
		}
		foreach ( (array) ( isset( $o['card_strip'] ) ? $o['card_strip'] : array() ) as $row ) {
			if ( ! empty( $row['image'] ) ) {
				$ids[] = absint( $row['image'] );
			}
		}
		$r = self::reader();
		foreach ( array( 'news_image', 'quote_image' ) as $k ) {
			if ( $r[ $k ] ) {
				$ids[] = (int) $r[ $k ]['id'];
			}
		}
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * GET /admin/ads-manager/library?offset=&search= — the ad image gallery (in use first, then
	 * every image ever used or uploaded as an ad), shaped like /admin/media.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	public static function library( $request ) {
		$offset = max( 0, (int) $request->get_param( 'offset' ) );
		$search = sanitize_text_field( (string) $request->get_param( 'search' ) );
		$used   = self::used_ids();
		foreach ( $used as $id ) {
			if ( ! get_post_meta( $id, '_nros_ad_creative', true ) ) {
				self::mark_creative( $id );
			}
		}
		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => 'image',
			'posts_per_page' => 30,
			'offset'         => $offset,
			'meta_key'       => '_nros_ad_creative', // phpcs:ignore WordPress.DB.SlowDBQuery
			'orderby'        => 'meta_value_num',
			'order'          => 'DESC',
			'no_found_rows'  => false,
		);
		if ( '' !== $search ) {
			$args['s'] = $search;
		}
		$q     = new WP_Query( $args );
		$items = array();
		foreach ( $q->posts as $p ) {
			$img = self::image( $p->ID );
			if ( ! $img ) {
				continue;
			}
			$thumb   = wp_get_attachment_image_src( $p->ID, 'medium' );
			$items[] = array_merge(
				$img,
				array(
					'thumb'   => $thumb ? $thumb[0] : $img['url'],
					'alt'     => (string) get_post_meta( $p->ID, '_wp_attachment_image_alt', true ),
					'caption' => (string) $p->post_excerpt,
					'title'   => (string) $p->post_title,
					'date'    => (string) $p->post_date,
					'in_use'  => in_array( (int) $p->ID, $used, true ),
				)
			);
		}
		return array(
			'items'    => $items,
			'total'    => (int) $q->found_posts,
			'has_more' => $offset + count( $q->posts ) < (int) $q->found_posts,
		);
	}

	/**
	 * POST /admin/ads-manager/library {id, remove?} — add an uploaded image to the ad gallery,
	 * or take it out of the gallery (the file itself stays in the media library).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function library_mark( $request ) {
		$id = absint( $request->get_param( 'id' ) );
		if ( ! $id || 'attachment' !== get_post_type( $id ) ) {
			return new WP_Error( 'nros_bad_id', 'ছবি পাওয়া যায়নি।', array( 'status' => 404 ) );
		}
		if ( $request->get_param( 'remove' ) ) {
			if ( in_array( $id, self::used_ids(), true ) ) {
				return new WP_Error( 'nros_in_use', 'এই ছবিটি এখন একটি বিজ্ঞাপনে চলছে — আগে সেখান থেকে সরান।', array( 'status' => 409 ) );
			}
			delete_post_meta( $id, '_nros_ad_creative' );
			return array( 'ok' => true, 'removed' => $id );
		}
		$ok = self::check_creative( $id );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		self::mark_creative( $id );
		return array( 'ok' => true, 'image' => self::image( $id ) );
	}

	/**
	 * Pages that already show the advertising landing page / spec sheet / app landing.
	 *
	 * @return array
	 */
	private static function landing_pages() {
		global $wpdb;
		$out = array();
		foreach ( array( 'advertise' => 'meeha_advertise', 'specs' => 'meeha_ad_specs', 'app' => 'meeha_app_landing' ) as $kind => $tag ) {
			$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status IN ('publish','draft','private') AND post_content LIKE %s ORDER BY post_status = 'publish' DESC, ID ASC LIMIT 1", '%[' . $wpdb->esc_like( $tag ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$out[ $kind ] = $id ? array(
				'id'     => $id,
				'url'    => (string) get_permalink( $id ),
				'status' => (string) get_post_status( $id ),
				'title'  => (string) get_the_title( $id ),
				'canvas' => 'page-canvas.php' === get_post_meta( $id, '_wp_page_template', true ),
			) : null;
		}
		return $out;
	}

	/**
	 * POST /admin/ads-manager/create-page {kind: advertise|specs|app} — one click makes the page
	 * (full-width canvas template, title hidden) or, if it exists, switches it to the canvas.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function create_page( $request ) {
		$kind = sanitize_key( (string) $request->get_param( 'kind' ) );
		$defs = array(
			'advertise' => array( 'বিজ্ঞাপন দিন', 'advertise-with-us', '[meeha_advertise]' ),
			'specs'     => array( 'বিজ্ঞাপনের মাপ ও নিয়ম', 'ad-specs', '[meeha_ad_specs]' ),
			'app'       => array( 'মিহা সংবাদদাতা অ্যাপ', 'correspondent-app', '[meeha_app_landing]' ),
		);
		if ( ! isset( $defs[ $kind ] ) ) {
			return new WP_Error( 'nros_bad_kind', 'অজানা পাতা।', array( 'status' => 400 ) );
		}
		$have = self::landing_pages();
		$canv = '' !== locate_template( 'page-canvas.php' );
		if ( $have[ $kind ] ) {
			$id = (int) $have[ $kind ]['id'];
		} else {
			$id = wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => $defs[ $kind ][0],
					'post_name'    => $defs[ $kind ][1],
					'post_content' => $defs[ $kind ][2],
				),
				true
			);
			if ( is_wp_error( $id ) ) {
				return $id;
			}
		}
		if ( $canv ) {
			update_post_meta( $id, '_wp_page_template', 'page-canvas.php' );
			// The shortcodes carry their own big heading.
			update_post_meta( $id, '_meeha_hide_title', 1 );
		}
		$pages = self::landing_pages();
		return array( 'ok' => true, 'page' => $pages[ $kind ], 'canvas' => $canv );
	}
}
