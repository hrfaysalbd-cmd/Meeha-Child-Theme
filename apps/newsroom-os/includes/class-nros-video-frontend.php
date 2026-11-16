<?php
/**
 * Public video experience (contract §6): the `[meeha_video_reels]` reels
 * player, the `[meeha_video_carousel]` homepage row, the single
 * /video/{slug}/ page and its VideoObject schema / og:video tags.
 *
 * The first screen is rendered on the server (SEO + no-JS) from exactly the
 * same data the public API returns — NROS_Videos::public_list() and
 * NROS_Videos::shape_public() — and assets/video/reels.js hydrates it and
 * talks to the public nros/v1 endpoints (contract §3).
 *
 * @package NewsroomOS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shortcodes, templates and head output for the public video pages.
 */
class NROS_Video_Frontend {

	const PT          = 'meeha_video';
	const FIRST_PAGE  = 6;
	const PAGE_CACHE  = 'nros_reels_page_url';
	const FONT_HANDLE = 'meeha-google-fonts';
	const FONT_URL    = 'https://fonts.googleapis.com/css2?family=Baloo+Da+2:wght@500;600;700;800&family=Hind+Siliguri:wght@400;500;600;700&family=Inter:wght@400;500;600&display=swap';

	/** @var bool */
	protected static $config_added = false;

	/** @var array<int, array> Cache of shaped items per request. */
	protected static $shaped = array();

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_shortcodes' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ), 5 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue' ), 20 );
		add_filter( 'template_include', array( __CLASS__, 'template_include' ), 99 );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		add_action( 'wp_head', array( __CLASS__, 'viewport_meta' ), 1 );
		add_action( 'wp_head', array( __CLASS__, 'head_meta' ), 30 );
		add_action( 'save_post_page', array( __CLASS__, 'flush_page_cache' ) );
		add_action( 'deleted_post', array( __CLASS__, 'flush_page_cache' ) );
		add_action( 'switch_theme', array( __CLASS__, 'flush_page_cache' ) );
		add_action( 'update_option_permalink_structure', array( __CLASS__, 'flush_page_cache' ) );
	}

	public static function register_shortcodes() {
		add_shortcode( 'meeha_video_reels', array( __CLASS__, 'shortcode_reels' ) );
		add_shortcode( 'meeha_video_carousel', array( __CLASS__, 'shortcode_carousel' ) );
	}

	// -----------------------------------------------------------------------
	// Assets
	// -----------------------------------------------------------------------

	protected static function asset_ver( $file ) {
		$path = NROS_DIR . 'assets/video/' . $file;
		return file_exists( $path ) ? NROS_VERSION . '.' . filemtime( $path ) : NROS_VERSION;
	}

	public static function register_assets() {
		if ( wp_style_is( 'nros-reels', 'registered' ) ) {
			return;
		}
		$base = NROS_URL . 'assets/video/';
		if ( ! wp_style_is( self::FONT_HANDLE, 'registered' ) ) {
			wp_register_style( self::FONT_HANDLE, self::FONT_URL, array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		}
		$script_args = array(
			'in_footer' => true,
			'strategy'  => 'defer',
		);
		wp_register_style( 'nros-reels', $base . 'reels.css', array( self::FONT_HANDLE ), self::asset_ver( 'reels.css' ) );
		// Desktop layout is its own file so phones never block rendering on it.
		wp_register_style( 'nros-reels-desktop', $base . 'reels-desktop.css', array( 'nros-reels' ), self::asset_ver( 'reels-desktop.css' ), '(min-width: 768px) and (hover: hover), (min-width: 768px) and (min-height: 501px)' );
		wp_register_style( 'nros-video-carousel', $base . 'carousel.css', array( self::FONT_HANDLE ), self::asset_ver( 'carousel.css' ) );
		wp_register_script( 'nros-reels', $base . 'reels.js', array(), self::asset_ver( 'reels.js' ), $script_args );
		wp_register_script( 'nros-reels-panel', $base . 'reels-panel.js', array( 'nros-reels' ), self::asset_ver( 'reels-panel.js' ), $script_args );
		wp_register_script( 'nros-video-carousel', $base . 'carousel.js', array(), self::asset_ver( 'carousel.js' ), $script_args );
	}

	protected static function page_has( $tag ) {
		if ( ! is_singular() ) {
			return false;
		}
		$post = get_queried_object();
		return $post instanceof WP_Post && has_shortcode( (string) $post->post_content, $tag );
	}

	protected static function has_videos() {
		if ( ! post_type_exists( self::PT ) ) {
			return false;
		}
		$ids = get_posts(
			array(
				'post_type'        => self::PT,
				'post_status'      => 'publish',
				'has_password'     => false,
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => false,
			)
		);
		return ! empty( $ids );
	}

	/**
	 * Enqueue in <head> wherever the shortcodes are known to render, so the
	 * CSS is never late (no flash of unstyled content / layout shift).
	 */
	public static function maybe_enqueue() {
		$single = is_singular( self::PT );
		if ( $single || self::page_has( 'meeha_video_reels' ) ) {
			self::enqueue_reels();
		}
		if ( $single ) {
			// The theme loads its article CSS/JS for every is_single(); a video
			// page renders none of that markup.
			wp_dequeue_style( 'meeha-single-style' );
			wp_dequeue_script( 'meeha-single-script' );
		}
		// The child theme's front-page.php renders the carousel right after "সর্বশেষ".
		if ( self::page_has( 'meeha_video_carousel' ) || ( is_front_page() && shortcode_exists( 'meeha_video_carousel' ) && self::has_videos() ) ) {
			self::enqueue_carousel();
		}
	}

	/** If <head> is already out (shortcode in a widget etc.), print the CSS right here. */
	protected static function late_styles( $handles ) {
		if ( ! did_action( 'wp_head' ) || doing_action( 'wp_head' ) ) {
			return '';
		}
		$todo = array();
		foreach ( $handles as $h ) {
			if ( ! wp_style_is( $h, 'done' ) ) {
				$todo[] = $h;
			}
		}
		if ( ! $todo ) {
			return '';
		}
		ob_start();
		wp_print_styles( $todo );
		return (string) ob_get_clean();
	}

	public static function enqueue_reels() {
		self::register_assets();
		wp_enqueue_style( 'nros-reels' );
		wp_enqueue_style( 'nros-reels-desktop' );
		wp_enqueue_script( 'nros-reels' );
		wp_enqueue_script( 'nros-reels-panel' );
		if ( ! self::$config_added ) {
			self::$config_added = true;
			$logged_in          = is_user_logged_in();
			$cfg                = array(
				'rest'  => esc_url_raw( rest_url( NROS_API_NS . '/' ) ),
				'nonce' => $logged_in ? wp_create_nonce( 'wp_rest' ) : '',
				'user'  => $logged_in ? wp_get_current_user()->display_name : '',
				'site'  => get_bloginfo( 'name' ),
				// Core's `rest-nonce` action renews an expired nonce (logged-in only).
				'ajax'  => $logged_in ? admin_url( 'admin-ajax.php' ) : '',
			);
			wp_add_inline_script( 'nros-reels', 'window.NROS_VIDEO=' . wp_json_encode( $cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG ) . ';', 'before' );
		}
	}

	public static function enqueue_carousel() {
		self::register_assets();
		wp_enqueue_style( 'nros-video-carousel' );
		wp_enqueue_script( 'nros-video-carousel' );
	}

	public static function body_class( $classes ) {
		if ( is_singular( self::PT ) || self::page_has( 'meeha_video_reels' ) ) {
			$classes[] = 'mvr-page';
		}
		return $classes;
	}

	// -----------------------------------------------------------------------
	// Formatting helpers (Bengali digits, compact counts)
	// -----------------------------------------------------------------------

	public static function bn( $value ) {
		return strtr( (string) $value, array( '0' => '০', '1' => '১', '2' => '২', '3' => '৩', '4' => '৪', '5' => '৫', '6' => '৬', '7' => '৭', '8' => '৮', '9' => '৯' ) );
	}

	/** 999 → ৯৯৯, 1200 → ১.২হা, 154300 → ১.৫লা (same rule as reels.js). */
	public static function compact( $n ) {
		$n = max( 0, (int) $n );
		if ( $n >= 100000 ) {
			$v = floor( $n / 10000 ) / 10;
			$s = 'লা';
		} elseif ( $n >= 1000 ) {
			$v = floor( $n / 100 ) / 10;
			$s = 'হা';
		} else {
			return self::bn( $n );
		}
		$v = rtrim( rtrim( number_format( $v, 1, '.', '' ), '0' ), '.' );
		return self::bn( $v ) . $s;
	}

	public static function duration_label( $sec ) {
		$sec = (int) $sec;
		if ( $sec <= 0 ) {
			return '';
		}
		$h   = (int) floor( $sec / 3600 );
		$m   = (int) floor( ( $sec % 3600 ) / 60 );
		$s   = $sec % 60;
		$out = $h ? $h . ':' . str_pad( (string) $m, 2, '0', STR_PAD_LEFT ) : (string) $m;
		return self::bn( $out . ':' . str_pad( (string) $s, 2, '0', STR_PAD_LEFT ) );
	}

	// -----------------------------------------------------------------------
	// Data (identical to the public API)
	// -----------------------------------------------------------------------

	/** PublicVideo (contract §2) for a post — the API's own shaper. */
	public static function public_item( $post ) {
		$post = get_post( $post );
		if ( isset( self::$shaped[ $post->ID ] ) ) {
			return self::$shaped[ $post->ID ];
		}
		$it = NROS_Videos::shape_public( $post );
		// Titles are stored with entities (&amp;) by wp_insert_post sanitising.
		$it['title']                = html_entity_decode( (string) $it['title'], ENT_QUOTES, 'UTF-8' );
		$it['excerpt']              = html_entity_decode( wp_strip_all_tags( (string) $it['excerpt'] ), ENT_QUOTES, 'UTF-8' );
		self::$shaped[ $post->ID ] = $it;
		return $it;
	}

	/**
	 * Exactly what GET /public/videos?offset=0&limit=N&start=ID returns
	 * (called directly, so no rate-limit is spent on server rendering).
	 *
	 * @return array{0: array, 1: bool}
	 */
	public static function query_items( $start = 0, $limit = self::FIRST_PAGE ) {
		if ( ! post_type_exists( self::PT ) || ! class_exists( 'NROS_Videos' ) ) {
			return array( array(), false );
		}
		$req = new WP_REST_Request( 'GET', '/' . NROS_API_NS . '/public/videos' );
		$req->set_param( 'offset', 0 );
		$req->set_param( 'limit', (int) $limit );
		if ( $start ) {
			$req->set_param( 'start', (int) $start );
		}
		$data  = NROS_Videos::public_list( $req )->get_data();
		$items = array();
		foreach ( $data['items'] as $it ) {
			$it['title']               = html_entity_decode( (string) $it['title'], ENT_QUOTES, 'UTF-8' );
			$it['excerpt']             = html_entity_decode( wp_strip_all_tags( (string) $it['excerpt'] ), ENT_QUOTES, 'UTF-8' );
			self::$shaped[ $it['id'] ] = $it;
			$items[]                   = $it;
		}
		return array( $items, ! empty( $data['has_more'] ) );
	}

	// -----------------------------------------------------------------------
	// Icons (inline SVG, currentColor)
	// -----------------------------------------------------------------------

	public static function icon( $name ) {
		$p = array(
			'heart'   => '<path d="M12 20.6s-7.6-4.5-9.4-9.4C1.4 7.9 3.6 4.8 6.9 4.8c2 0 3.5 1.1 4.3 2.5h1.6c.8-1.4 2.3-2.5 4.3-2.5 3.3 0 5.5 3.1 4.3 6.4-1.8 4.9-9.4 9.4-9.4 9.4z"/>',
			'comment' => '<path d="M4 5.5h16a1.5 1.5 0 0 1 1.5 1.5v9a1.5 1.5 0 0 1-1.5 1.5h-8.6L7 21v-3.5H4A1.5 1.5 0 0 1 2.5 16V7A1.5 1.5 0 0 1 4 5.5z"/>',
			'share'   => '<path d="M13.5 4.5 21 11.8l-7.5 7.2v-4.3c-5 0-8.2 1.4-10.5 4.8.8-5.3 3.7-9.6 10.5-10.6V4.5z"/>',
			'play'    => '<path d="M8 5.2v13.6a.8.8 0 0 0 1.2.7l11-6.8a.8.8 0 0 0 0-1.4l-11-6.8A.8.8 0 0 0 8 5.2z"/>',
			'muted'   => '<path d="M4 9.2h3.3L12 5v14l-4.7-4.2H4z"/><path d="m15.5 9.5 5 5m0-5-5 5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>',
			'sound'   => '<path d="M4 9.2h3.3L12 5v14l-4.7-4.2H4z"/><path d="M15.5 8.8a4.6 4.6 0 0 1 0 6.4M18.2 6.2a8.3 8.3 0 0 1 0 11.6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>',
			'back'    => '<path d="M15 5 8 12l7 7" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>',
			'next'    => '<path d="m9 5 7 7-7 7" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>',
			'up'      => '<path d="m6 15 6-6 6 6" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>',
			'down'    => '<path d="m6 9 6 6 6-6" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>',
			'close'   => '<path d="m6 6 12 12M18 6 6 18" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"/>',
			'eye'     => '<path d="M12 5.5c5 0 8.7 4 9.7 6.5-1 2.5-4.7 6.5-9.7 6.5S3.3 14.5 2.3 12C3.3 9.5 7 5.5 12 5.5zm0 3.2a3.3 3.3 0 1 0 0 6.6 3.3 3.3 0 0 0 0-6.6z"/>',
			'fb'      => '<path d="M13.5 21v-7.6h2.6l.4-3h-3V8.5c0-.9.3-1.5 1.5-1.5h1.6V4.3c-.3 0-1.2-.1-2.3-.1-2.3 0-3.9 1.4-3.9 4v2.2H7.8v3h2.6V21z"/>',
			'wa'      => '<path d="M12 3a9 9 0 0 0-7.8 13.4L3 21l4.7-1.2A9 9 0 1 0 12 3zm4.6 12.6c-.2.6-1.1 1.1-1.6 1.2-.4.1-.9.1-1.5-.1a13 13 0 0 1-1.4-.5 10.6 10.6 0 0 1-4.1-3.6c-.3-.4-1-1.3-1-2.5 0-1.2.6-1.8.9-2.1.2-.2.5-.3.7-.3h.5c.2 0 .4 0 .6.5l.8 2c.1.1.1.3 0 .5l-.3.5-.4.4c-.1.1-.3.3-.1.6.2.3.7 1.2 1.6 1.9 1 .9 1.9 1.2 2.2 1.3.3.1.4.1.6-.1l.8-1c.2-.3.4-.2.6-.1l1.9.9c.3.1.5.2.5.3.1.1.1.6-.1 1.2z"/>',
			'x'       => '<path d="M17.5 3.5h3l-6.6 7.5 7.8 9.5h-6.1l-4.8-5.9-5.4 5.9h-3l7.1-7.9L2.1 3.5h6.2l4.3 5.4 4.9-5.4zm-1 15.3h1.7L7.6 5.1H5.8z"/>',
			'link'    => '<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1.2 1.2M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1.2-1.2" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/>',
			'send'    => '<path d="M3.4 20.4 21 12 3.4 3.6 3.4 10l12.6 2-12.6 2z"/>',
		);
		return '<svg class="mvr-i" viewBox="0 0 24 24" width="24" height="24" fill="currentColor" aria-hidden="true" focusable="false">' . ( isset( $p[ $name ] ) ? $p[ $name ] : '' ) . '</svg>';
	}

	// -----------------------------------------------------------------------
	// Reels
	// -----------------------------------------------------------------------

	public static function shortcode_reels( $atts = array() ) {
		$start = isset( $_GET['v'] ) ? absint( wp_unslash( $_GET['v'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return self::render_reels( $start, is_singular( self::PT ) ? 'single' : 'page' );
	}

	protected static function bug() {
		return '<span class="mvr-bug" aria-hidden="true"><b>মিহা</b><span>ভিডিও</span></span>';
	}

	protected static function avatar( $author, $size ) {
		$name = isset( $author['name'] ) ? (string) $author['name'] : '';
		$img  = ! empty( $author['avatar'] ) ? '<img src="' . esc_url( $author['avatar'] ) . '" alt="" loading="lazy" decoding="async" width="' . (int) $size . '" height="' . (int) $size . '">' : '';
		return '<span class="mvr-av" data-n="' . esc_attr( mb_substr( $name, 0, 1 ) ) . '">' . $img . '</span>';
	}

	/**
	 * One slide. Only the first slide carries a real `src` (and `controls`
	 * until JS takes over, so it also plays without JS); the rest hold
	 * data-src/data-poster and are loaded by reels.js one step ahead.
	 */
	protected static function slide_html( $it, $i ) {
		$land  = 'landscape' === $it['orientation'];
		$near  = $i < 2;
		$thumb = $it['thumbnail'] ? esc_url( $it['thumbnail'] ) : '';
		$likes = (int) $it['stats']['likes'];
		$title = $it['title'];
		ob_start();
		?>
		<article class="mvr-slide" data-i="<?php echo (int) $i; ?>" data-id="<?php echo (int) $it['id']; ?>" aria-label="<?php echo esc_attr( $title ); ?>" aria-posinset="<?php echo (int) $i + 1; ?>" aria-setsize="-1">
			<div class="mvr-stage<?php echo $land ? ' is-land' : ''; ?>">
				<div class="mvr-bg"<?php echo $thumb ? ( $near ? ' style="--bg:url(&quot;' . $thumb . '&quot;)"' : ' data-bg="' . $thumb . '"' ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>></div>
				<?php if ( 'youtube' === $it['source'] && $it['youtube_id'] ) : ?>
					<div class="mvr-media mvr-yt" data-yt="<?php echo esc_attr( $it['youtube_id'] ); ?>">
						<?php if ( $thumb ) : ?>
							<img src="<?php echo $near ? $thumb : 'data:image/gif;base64,R0lGODlhAQABAAAAACw='; // phpcs:ignore ?>"<?php echo $near ? '' : ' data-src="' . $thumb . '"'; // phpcs:ignore ?> alt="" decoding="async">
						<?php endif; ?>
						<a class="mvr-ytlink" href="<?php echo esc_url( 'https://www.youtube.com/watch?v=' . $it['youtube_id'] ); ?>" target="_blank" rel="noopener">ইউটিউবে দেখুন</a>
					</div>
				<?php else : ?>
					<video class="mvr-media" playsinline muted loop disablepictureinpicture preload="<?php echo 0 === $i ? 'metadata' : 'none'; ?>"
						<?php echo 0 === $i ? 'controls src="' . esc_url( $it['video_url'] ) . '"' : 'data-src="' . esc_url( $it['video_url'] ) . '"'; ?>
						<?php echo $thumb ? ( $near ? 'poster="' : 'data-poster="' ) . $thumb . '"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>></video>
				<?php endif; ?>
				<button type="button" class="mvr-tap" aria-label="ভিডিও চালান বা থামান"></button>
				<span class="mvr-state" aria-hidden="true"><?php echo self::icon( 'play' ); // phpcs:ignore ?></span>
				<div class="mvr-cap">
					<div class="mvr-by">
						<?php echo self::avatar( $it['author'], 26 ); // phpcs:ignore ?>
						<span class="mvr-name"><?php echo esc_html( $it['author']['name'] ); ?></span>
						<span class="mvr-when"><?php echo esc_html( $it['date_human'] ); ?></span>
					</div>
					<h2 class="mvr-title"><a href="<?php echo esc_url( $it['link'] ); ?>"><?php echo esc_html( $title ); ?></a></h2>
					<p class="mvr-ex"><?php echo esc_html( $it['excerpt'] ); ?></p>
					<button type="button" class="mvr-more" aria-expanded="false">আরও</button>
				</div>
				<div class="mvr-prog" aria-hidden="true"><i></i></div>
			</div>
			<div class="mvr-rail">
				<button type="button" class="mvr-act mvr-like" aria-pressed="false" aria-label="পছন্দ করুন"><span class="mvr-ic"><?php echo self::icon( 'heart' ); // phpcs:ignore ?></span><span class="mvr-n"><?php echo esc_html( self::compact( $likes ) ); ?></span></button>
				<button type="button" class="mvr-act mvr-cmt" aria-label="মন্তব্য দেখুন"><span class="mvr-ic"><?php echo self::icon( 'comment' ); // phpcs:ignore ?></span><span class="mvr-n"><?php echo esc_html( self::compact( $it['stats']['comments'] ) ); ?></span></button>
				<button type="button" class="mvr-act mvr-shr" aria-label="শেয়ার করুন"><span class="mvr-ic"><?php echo self::icon( 'share' ); // phpcs:ignore ?></span><span class="mvr-n"><?php echo esc_html( self::compact( $it['stats']['shares'] ) ); ?></span></button>
			</div>
		</article>
		<?php
		return ob_get_clean();
	}

	protected static function queue_item_html( $it, $i ) {
		$thumb = $it['thumbnail'] ? '<img src="' . esc_url( $it['thumbnail'] ) . '" alt="" loading="lazy" decoding="async" width="68" height="120">' : '';
		return '<li><a href="' . esc_url( $it['link'] ) . '" class="mvr-qi' . ( 0 === $i ? ' is-on' : '' ) . '" data-i="' . (int) $i . '"' . ( 0 === $i ? ' aria-current="true"' : '' ) . '>'
			. '<span class="mvr-qt' . ( 'landscape' === $it['orientation'] ? ' is-land' : '' ) . '">' . $thumb . '</span>'
			. '<span class="mvr-qx"><span class="mvr-qh">' . esc_html( $it['title'] ) . '</span>'
			. '<span class="mvr-qm">' . esc_html( self::compact( $it['stats']['views'] ) ) . ' বার দেখা হয়েছে</span>'
			. '<span class="mvr-qm">' . esc_html( $it['date_human'] ) . '</span></span></a></li>';
	}

	public static function render_reels( $start = 0, $mode = 'page' ) {
		self::enqueue_reels();
		list( $items, $more ) = self::query_items( $start );
		$css = self::late_styles( array( self::FONT_HANDLE, 'nros-reels', 'nros-reels-desktop' ) );
		if ( ! $items ) {
			return $css . '<div class="mvr-empty"><p><b>এখনো কোনো ভিডিও প্রকাশিত হয়নি।</b> নতুন ভিডিও প্রকাশ হলেই এখানে দেখতে পাবেন।</p><p><a href="' . esc_url( home_url( '/' ) ) . '">প্রচ্ছদে ফিরে যান</a></p></div>';
		}
		$first = $items[0];
		$start = (int) $first['id'] === (int) $start ? (int) $start : 0;
		$h1    = 'single' === $mode ? $first['title'] : ( is_singular() ? get_the_title() : 'ভিডিও নিউজ' );
		$json  = wp_json_encode( $items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP );
		ob_start();
		echo $css; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
		<div class="mvr mvr-nojs" id="mvr" data-mode="<?php echo esc_attr( $mode ); ?>" data-start="<?php echo (int) $start; ?>" data-more="<?php echo $more ? '1' : '0'; ?>">
			<script type="application/json" class="mvr-json"><?php echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></script>
			<h1 class="mvr-sr"><?php echo esc_html( wp_strip_all_tags( $h1 ) ); ?></h1>
			<div class="mvr-box"><div class="mvr-in">
				<div class="mvr-top">
					<a class="mvr-back" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="প্রচ্ছদে ফিরে যান"><?php echo self::icon( 'back' ); // phpcs:ignore ?></a>
					<?php echo self::bug(); // phpcs:ignore ?>
				</div>

				<section class="mvr-info" aria-label="এখন চলছে">
					<?php echo self::bug(); // phpcs:ignore ?>
					<p class="mvr-info-title"><?php echo esc_html( $first['title'] ); ?></p>
					<div class="mvr-info-by">
						<?php echo self::avatar( $first['author'], 42 ); // phpcs:ignore ?>
						<span><b class="mvr-info-name"><?php echo esc_html( $first['author']['name'] ); ?></b><span class="mvr-info-when"><?php echo esc_html( $first['date_human'] ); ?></span></span>
					</div>
					<p class="mvr-info-ex"><?php echo esc_html( $first['excerpt'] ); ?></p>
					<dl class="mvr-info-stats">
						<div><dt>বার দেখা হয়েছে</dt><dd data-k="views"><?php echo esc_html( self::compact( $first['stats']['views'] ) ); ?></dd></div>
						<div><dt>পছন্দ</dt><dd data-k="likes"><?php echo esc_html( self::compact( $first['stats']['likes'] ) ); ?></dd></div>
						<div><dt>শেয়ার</dt><dd data-k="shares"><?php echo esc_html( self::compact( $first['stats']['shares'] ) ); ?></dd></div>
					</dl>
					<div class="mvr-info-share" role="group" aria-label="শেয়ার করুন">
						<button type="button" data-net="facebook" aria-label="ফেসবুকে শেয়ার করুন"><?php echo self::icon( 'fb' ); // phpcs:ignore ?></button>
						<button type="button" data-net="whatsapp" aria-label="হোয়াটসঅ্যাপে শেয়ার করুন"><?php echo self::icon( 'wa' ); // phpcs:ignore ?></button>
						<button type="button" data-net="x" aria-label="এক্স-এ শেয়ার করুন"><?php echo self::icon( 'x' ); // phpcs:ignore ?></button>
						<button type="button" data-net="copy" class="mvr-copy"><?php echo self::icon( 'link' ); // phpcs:ignore ?><span>লিংক কপি</span></button>
					</div>
					<p class="mvr-keys"><kbd>↑</kbd><kbd>↓</kbd> আগের ও পরের ভিডিও <kbd>স্পেস</kbd> চালান/থামান <kbd>M</kbd> শব্দ</p>
				</section>

				<div class="mvr-col">
					<button type="button" class="mvr-sound" aria-pressed="false" aria-label="শব্দ চালু করুন"><?php echo self::icon( 'muted' ) . self::icon( 'sound' ); // phpcs:ignore ?></button>
					<div class="mvr-feed" role="feed" aria-label="ভিডিও নিউজ" aria-busy="false">
						<?php
						foreach ( $items as $i => $it ) {
							echo self::slide_html( $it, $i ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						}
						?>
						<div class="mvr-end" aria-live="polite"></div>
					</div>
					<div class="mvr-nav">
						<button type="button" class="mvr-prev" aria-label="আগের ভিডিও"><?php echo self::icon( 'up' ); // phpcs:ignore ?></button>
						<button type="button" class="mvr-next" aria-label="পরের ভিডিও"><?php echo self::icon( 'down' ); // phpcs:ignore ?></button>
					</div>
				</div>

				<aside class="mvr-side" aria-label="আরও ভিডিও ও মন্তব্য">
					<div class="mvr-tabs" role="tablist">
						<button type="button" role="tab" class="mvr-tab" id="mvr-tab-q" data-tab="q" aria-controls="mvr-queue" aria-selected="true">আরও ভিডিও</button>
						<button type="button" role="tab" class="mvr-tab" id="mvr-tab-c" data-tab="c" aria-controls="mvr-cm" aria-selected="false" tabindex="-1">মন্তব্য <span class="mvr-tab-n"><?php echo esc_html( self::compact( $first['stats']['comments'] ) ); ?></span></button>
					</div>
					<ol class="mvr-queue" id="mvr-queue" role="tabpanel" aria-labelledby="mvr-tab-q">
						<?php
						foreach ( $items as $i => $it ) {
							echo self::queue_item_html( $it, $i ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						}
						?>
					</ol>
					<div class="mvr-cm" id="mvr-cm" role="dialog" aria-labelledby="mvr-cm-h" hidden>
						<div class="mvr-grab" aria-hidden="true"></div>
						<div class="mvr-cm-head">
							<h3 id="mvr-cm-h">মন্তব্য <span class="mvr-cm-n"></span></h3>
							<button type="button" class="mvr-x" aria-label="মন্তব্য বন্ধ করুন"><?php echo self::icon( 'close' ); // phpcs:ignore ?></button>
						</div>
						<div class="mvr-cm-list" aria-live="polite"></div>
						<?php if ( get_option( 'comment_registration' ) && ! is_user_logged_in() ) : ?>
							<p class="mvr-form mvr-login"><a href="<?php echo esc_url( wp_login_url( 'single' === $mode ? $first['link'] : (string) get_permalink() ) ); ?>">মন্তব্য করতে লগইন করুন</a></p>
						<?php else : ?>
						<form class="mvr-form" novalidate>
							<div class="mvr-who">
								<?php $email_req = (bool) get_option( 'require_name_email' ); ?>
								<label><span class="mvr-sr">আপনার নাম</span><input name="author_name" type="text" maxlength="60" autocomplete="name" placeholder="আপনার নাম" required></label>
								<label><span class="mvr-sr"><?php echo $email_req ? 'ইমেইল (প্রকাশ করা হবে না)' : 'ইমেইল (ঐচ্ছিক, প্রকাশ করা হবে না)'; ?></span><input name="author_email" type="email" maxlength="100" autocomplete="email" inputmode="email" placeholder="<?php echo $email_req ? 'ইমেইল (প্রকাশ হবে না)' : 'ইমেইল (ঐচ্ছিক)'; ?>"<?php echo $email_req ? ' required' : ''; ?>></label>
							</div>
							<div class="mvr-hp" aria-hidden="true"><label>ওয়েবসাইট<input name="website" type="text" tabindex="-1" autocomplete="off"></label></div>
							<div class="mvr-say">
								<label><span class="mvr-sr">মন্তব্য</span><textarea name="content" rows="1" maxlength="2000" placeholder="মন্তব্য লিখুন…" required></textarea></label>
								<button type="submit" class="mvr-send" aria-label="মন্তব্য পাঠান"><?php echo self::icon( 'send' ); // phpcs:ignore ?></button>
							</div>
							<p class="mvr-msg" role="status"></p>
						</form>
						<?php endif; ?>
					</div>
				</aside>
			</div></div>

			<div class="mvr-scrim" hidden></div>
				<div class="mvr-share" role="dialog" aria-modal="true" aria-labelledby="mvr-share-h" hidden>
					<div class="mvr-grab" aria-hidden="true"></div>
					<div class="mvr-cm-head"><h3 id="mvr-share-h">শেয়ার করুন</h3><button type="button" class="mvr-x" aria-label="বন্ধ করুন"><?php echo self::icon( 'close' ); // phpcs:ignore ?></button></div>
					<div class="mvr-nets">
						<button type="button" data-net="facebook"><span class="mvr-net mvr-fb"><?php echo self::icon( 'fb' ); // phpcs:ignore ?></span>ফেসবুক</button>
						<button type="button" data-net="whatsapp"><span class="mvr-net mvr-wa"><?php echo self::icon( 'wa' ); // phpcs:ignore ?></span>হোয়াটসঅ্যাপ</button>
						<button type="button" data-net="x"><span class="mvr-net mvr-xx"><?php echo self::icon( 'x' ); // phpcs:ignore ?></span>এক্স</button>
						<button type="button" data-net="copy"><span class="mvr-net mvr-cp"><?php echo self::icon( 'link' ); // phpcs:ignore ?></span>লিংক কপি</button>
					</div>
				</div>
			<div class="mvr-toast" role="status" aria-live="polite"></div>
		</div>
		<?php
		return ob_get_clean();
	}

	// -----------------------------------------------------------------------
	// Carousel
	// -----------------------------------------------------------------------

	/** Permalink of the first published page that holds [meeha_video_reels], or ''. */
	public static function reels_page_url() {
		$cached = get_transient( self::PAGE_CACHE );
		if ( false === $cached ) {
			global $wpdb;
			$like   = '%' . $wpdb->esc_like( '[meeha_video_reels' ) . '%';
			$ids    = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_password = '' AND post_content LIKE %s ORDER BY menu_order ASC, ID ASC LIMIT 5", $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$cached = 'none';
			foreach ( (array) $ids as $id ) {
				$p = get_post( (int) $id );
				if ( $p && has_shortcode( $p->post_content, 'meeha_video_reels' ) ) {
					$cached = (string) get_permalink( $p );
					break;
				}
			}
			set_transient( self::PAGE_CACHE, $cached, 12 * HOUR_IN_SECONDS );
		}
		return 'none' === $cached ? '' : $cached;
	}

	public static function flush_page_cache() {
		delete_transient( self::PAGE_CACHE );
	}

	public static function shortcode_carousel( $atts = array() ) {
		$a = shortcode_atts(
			array(
				'count' => 10,
				'title' => 'ভিডিও নিউজ',
				'link'  => '',
			),
			$atts,
			'meeha_video_carousel'
		);
		if ( ! post_type_exists( self::PT ) || ! class_exists( 'NROS_Videos' ) ) {
			return '';
		}
		$q = new WP_Query(
			array(
				'post_type'           => self::PT,
				'post_status'         => 'publish',
				'has_password'        => false,
				'posts_per_page'      => max( 1, min( 20, (int) $a['count'] ) ),
				'orderby'             => array(
					'date' => 'DESC',
					'ID'   => 'DESC',
				),
				'ignore_sticky_posts' => true,
				'no_found_rows'       => true,
			)
		);
		if ( ! $q->posts ) {
			return '';
		}
		self::enqueue_carousel();
		$reels = '' !== trim( (string) $a['link'] ) ? esc_url_raw( trim( (string) $a['link'] ) ) : self::reels_page_url();
		// No reels page: every card opens its own /video/{slug}/ (which is the
		// reels player), and "see all" opens the newest one.
		$all = $reels ? $reels : (string) get_permalink( $q->posts[0] );
		$uid = 'mvc-' . wp_unique_id();
		ob_start();
		echo self::late_styles( array( self::FONT_HANDLE, 'nros-video-carousel' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
		<section class="mvc" aria-labelledby="<?php echo esc_attr( $uid ); ?>">
			<div class="mvc-head">
				<h2 class="mvc-title" id="<?php echo esc_attr( $uid ); ?>"><span class="mvc-dot" aria-hidden="true"></span><?php echo esc_html( $a['title'] ); ?></h2>
				<div class="mvc-ctrl">
					<button type="button" class="mvc-arrow" data-dir="-1" aria-label="আগের ভিডিওগুলো" aria-controls="<?php echo esc_attr( $uid ); ?>-t" disabled><?php echo self::icon( 'back' ); // phpcs:ignore ?></button>
					<button type="button" class="mvc-arrow" data-dir="1" aria-label="পরের ভিডিওগুলো" aria-controls="<?php echo esc_attr( $uid ); ?>-t"><?php echo self::icon( 'next' ); // phpcs:ignore ?></button>
					<a class="mvc-all" href="<?php echo esc_url( $all ); ?>">সব ভিডিও দেখুন</a>
				</div>
			</div>
			<ul class="mvc-track" id="<?php echo esc_attr( $uid ); ?>-t">
				<?php
				foreach ( $q->posts as $i => $p ) :
					$it   = self::public_item( $p );
					$href = $reels ? add_query_arg( 'v', $it['id'], $reels ) : $it['link'];
					$dur  = self::duration_label( get_post_meta( $p->ID, '_meeha_video_duration', true ) );
					?>
					<li class="mvc-card">
						<a href="<?php echo esc_url( $href ); ?>" class="mvc-link">
							<span class="mvc-thumb<?php echo 'landscape' === $it['orientation'] ? ' is-land' : ''; ?>"<?php echo $it['thumbnail'] && 'landscape' === $it['orientation'] ? ' style="--bg:url(&quot;' . esc_url( $it['thumbnail'] ) . '&quot;)"' : ''; // phpcs:ignore ?>>
								<?php if ( $it['thumbnail'] ) : ?>
									<img src="<?php echo esc_url( $it['thumbnail'] ); ?>" alt="" loading="<?php echo $i < 4 ? 'eager' : 'lazy'; ?>" decoding="async" width="270" height="480">
								<?php endif; ?>
								<?php if ( $dur ) : ?><span class="mvc-dur"><span class="mvr-sr">দৈর্ঘ্য </span><?php echo esc_html( $dur ); ?></span><?php endif; ?>
								<span class="mvc-play" aria-hidden="true"><?php echo self::icon( 'play' ); // phpcs:ignore ?></span>
								<span class="mvc-views"><?php echo self::icon( 'eye' ); // phpcs:ignore ?><?php echo esc_html( self::compact( $it['stats']['views'] ) ); ?><span class="mvr-sr"> বার দেখা হয়েছে</span></span>
							</span>
							<span class="mvc-h"><?php echo esc_html( $it['title'] ); ?></span>
							<span class="mvc-when"><?php echo esc_html( $it['date_human'] ); ?></span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
		<?php
		return ob_get_clean();
	}

	// -----------------------------------------------------------------------
	// Templates: /video/{slug}/ and the reels page
	// -----------------------------------------------------------------------

	public static function template_include( $template ) {
		if ( is_feed() || is_embed() || post_password_required() ) {
			return $template;
		}
		$single = is_singular( self::PT );
		// The reels page gets the same full-width canvas (theme header/footer
		// kept) because theme content columns are far too narrow for it.
		$page = ! $single && is_page() && self::page_has( 'meeha_video_reels' ) && apply_filters( 'nros_video_reels_full_width', true );
		if ( ! $single && ! $page ) {
			return $template;
		}
		if ( $single ) {
			$theme = locate_template( array( 'single-' . self::PT . '.php' ) );
			if ( $theme ) {
				return $theme;
			}
		}
		$ours = NROS_DIR . 'assets/video/single-video.php';
		return file_exists( $ours ) ? $ours : $template;
	}

	/** True when the theme has no classic header.php (block theme). */
	protected static function is_block_shell() {
		// Not locate_template(): it falls back to wp-includes/theme-compat/header.php.
		return function_exists( 'wp_is_block_theme' ) && wp_is_block_theme()
			&& ! file_exists( get_stylesheet_directory() . '/header.php' )
			&& ! file_exists( get_template_directory() . '/header.php' );
	}

	/** Called from assets/video/single-video.php (single video and the reels page). */
	public static function render_single_page() {
		$block = self::is_block_shell();
		if ( $block ) {
			// Minimal document: get_header() on a block theme would load the
			// deprecated core fallback header and log notices.
			$parts = array();
			if ( function_exists( 'do_blocks' ) ) {
				foreach ( array( 'header', 'footer' ) as $slug ) {
					$parts[ $slug ] = do_blocks( '<!-- wp:template-part {"slug":"' . $slug . '","tagName":"' . $slug . '"} /-->' );
				}
			}
			?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
			<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
			<?php
			wp_body_open();
			echo '<div class="wp-site-blocks">';
			echo isset( $parts['header'] ) ? $parts['header'] : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} else {
			get_header();
		}
		echo '<main id="primary" class="mvr-single-main site-main">';
		if ( is_singular( self::PT ) ) {
			echo self::render_reels( get_queried_object_id(), 'single' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} else {
			while ( have_posts() ) {
				the_post();
				the_content();
			}
		}
		echo '</main>';
		if ( $block ) {
			echo isset( $parts['footer'] ) ? $parts['footer'] : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</div>';
			wp_footer();
			echo "\n</body>\n</html>\n";
		} else {
			get_footer();
		}
	}

	// -----------------------------------------------------------------------
	// <head>: og:video + VideoObject
	// -----------------------------------------------------------------------

	/**
	 * The theme's viewport meta has no viewport-fit=cover, so env(safe-area-*)
	 * would be 0 on notched phones. A later viewport meta wins.
	 */
	public static function viewport_meta() {
		if ( ( is_singular( self::PT ) || self::page_has( 'meeha_video_reels' ) ) && ! self::is_block_shell() ) {
			echo '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />' . "\n";
		}
	}

	protected static function iso_duration( $sec ) {
		$sec = (int) $sec;
		$h   = (int) floor( $sec / 3600 );
		$m   = (int) floor( ( $sec % 3600 ) / 60 );
		$s   = $sec % 60;
		return 'PT' . ( $h ? $h . 'H' : '' ) . ( $m ? $m . 'M' : '' ) . $s . 'S';
	}

	public static function head_meta() {
		if ( ! is_singular( self::PT ) ) {
			return;
		}
		$post = get_queried_object();
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status || post_password_required( $post ) ) {
			return;
		}
		$it   = self::public_item( $post );
		$desc = $it['excerpt'] ? $it['excerpt'] : $it['title'];
		$rm   = (string) get_post_meta( $post->ID, 'rank_math_description', true );
		if ( '' !== $rm ) {
			$desc = wp_strip_all_tags( $rm );
		}
		$yt    = $it['youtube_id'];
		$embed = $yt ? 'https://www.youtube.com/embed/' . $yt : '';
		$land  = 'landscape' === $it['orientation'];
		$dur   = (int) get_post_meta( $post->ID, '_meeha_video_duration', true );
		$mime  = '';
		if ( ! $yt ) {
			$att  = (int) get_post_meta( $post->ID, '_meeha_video_attachment_id', true );
			$mime = $att ? (string) get_post_mime_type( $att ) : '';
		}

		// Rank Math / Yoast / AIOSEO (or the theme when none is active) already
		// print og:title/description/url/image — only add what they don't.
		$seo_plugin   = class_exists( 'RankMath' ) || defined( 'WPSEO_VERSION' ) || defined( 'AIOSEO_VERSION' );
		$theme_social = function_exists( 'meeha_output_social_meta_tags' );
		echo "\n<!-- Meeha video -->\n";
		if ( ! $seo_plugin && ! $theme_social ) {
			echo '<meta property="og:type" content="video.other" />' . "\n";
			printf( '<meta property="og:title" content="%s" />' . "\n", esc_attr( $it['title'] ) );
			printf( '<meta property="og:description" content="%s" />' . "\n", esc_attr( wp_trim_words( $desc, 40 ) ) );
			printf( '<meta property="og:url" content="%s" />' . "\n", esc_url( $it['link'] ) );
			if ( $it['thumbnail'] ) {
				printf( '<meta property="og:image" content="%s" />' . "\n", esc_url( $it['thumbnail'] ) );
			}
		}
		$og_video = $yt ? $embed : $it['video_url'];
		if ( $og_video ) {
			printf( '<meta property="og:video" content="%s" />' . "\n", esc_url( $og_video ) );
			if ( 0 === strpos( $og_video, 'https://' ) ) {
				printf( '<meta property="og:video:secure_url" content="%s" />' . "\n", esc_url( $og_video ) );
			}
			printf( '<meta property="og:video:type" content="%s" />' . "\n", esc_attr( $yt ? 'text/html' : ( $mime ? $mime : 'video/mp4' ) ) );
			printf( '<meta property="og:video:width" content="%d" />' . "\n", $land ? 1280 : 720 );
			printf( '<meta property="og:video:height" content="%d" />' . "\n", $land ? 720 : 1280 );
		}

		$logo_id = get_theme_mod( 'custom_logo' );
		$logo    = $logo_id ? wp_get_attachment_image_url( $logo_id, 'full' ) : get_site_icon_url( 512 );
		$thumb   = $it['thumbnail'] ? $it['thumbnail'] : $logo;
		$schema  = array(
			'@context'             => 'https://schema.org',
			'@type'                => 'VideoObject',
			'name'                 => $it['title'],
			'description'          => wp_trim_words( $desc, 60 ),
			'uploadDate'           => get_post_time( 'c', true, $post ),
			'dateModified'         => get_post_modified_time( 'c', true, $post ),
			'url'                  => $it['link'],
			'inLanguage'           => 'bn',
			'author'               => array(
				'@type' => 'Person',
				'name'  => $it['author']['name'],
			),
			'publisher'            => array_filter(
				array(
					'@type' => 'NewsMediaOrganization',
					'name'  => get_bloginfo( 'name' ),
					'url'   => home_url( '/' ),
					'logo'  => $logo ? array(
						'@type' => 'ImageObject',
						'url'   => $logo,
					) : null,
				)
			),
			'interactionStatistic' => array(
				array(
					'@type'                => 'InteractionCounter',
					'interactionType'      => array( '@type' => 'WatchAction' ),
					'userInteractionCount' => (int) $it['stats']['views'],
				),
				array(
					'@type'                => 'InteractionCounter',
					'interactionType'      => array( '@type' => 'LikeAction' ),
					'userInteractionCount' => (int) $it['stats']['likes'],
				),
				array(
					'@type'                => 'InteractionCounter',
					'interactionType'      => array( '@type' => 'CommentAction' ),
					'userInteractionCount' => (int) $it['stats']['comments'],
				),
			),
		);
		if ( $thumb ) {
			$schema['thumbnailUrl'] = array( $thumb );
		}
		if ( $dur > 0 ) {
			$schema['duration'] = self::iso_duration( $dur );
		}
		if ( $yt ) {
			$schema['embedUrl'] = $embed;
		} elseif ( $it['video_url'] ) {
			$schema['contentUrl'] = $it['video_url'];
		}
		echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG ) . "</script>\n";
	}
}
