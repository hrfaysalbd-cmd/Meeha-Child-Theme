<?php
/**
 * 1.22.0 — the correspondent app's download landing page.
 *
 * Shortcode: [meeha_app_landing]  (alias [meeha_correspondent_app])
 *
 * Only the page body: no header or footer of its own, so it sits inside
 * whatever page the theme renders. Mobile first, big text, one goal: get
 * the Android app installed. The APK version is read from the same
 * android.json the in-app updater uses (GET nros/v1/app/android).
 *
 * Attributes (all optional):
 *   apk   — APK download URL
 *   apply — "become a correspondent" URL
 *   web   — the web app (for iPhone / desktop)
 *   login — the login URL
 *
 * @package NewsroomOS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NROS_App_Landing {

	const APK   = 'https://correspondent.meeha.news/download/meeha-correspondent.apk';
	const APPLY = 'https://correspondent.meeha.news/apply';
	const WEB   = 'https://correspondent.meeha.news/';
	const LOGIN = 'https://correspondent.meeha.news/login';
	const TAGS  = array( 'meeha_app_landing', 'meeha_correspondent_app' );

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_shortcodes' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ), 5 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue' ), 20 );
	}

	public static function register_shortcodes() {
		foreach ( self::TAGS as $tag ) {
			add_shortcode( $tag, array( __CLASS__, 'render' ) );
		}
	}

	protected static function ver( $file ) {
		$p = NROS_DIR . 'assets/landing/' . $file;
		return file_exists( $p ) ? NROS_VERSION . '.' . filemtime( $p ) : NROS_VERSION;
	}

	protected static function url( $file ) {
		return NROS_URL . 'assets/landing/' . $file;
	}

	public static function register_assets() {
		if ( wp_style_is( 'nros-app-landing', 'registered' ) ) {
			return;
		}
		$font = 'meeha-google-fonts';
		if ( ! wp_style_is( $font, 'registered' ) ) {
			wp_register_style( $font, 'https://fonts.googleapis.com/css2?family=Baloo+Da+2:wght@500;600;700;800&family=Hind+Siliguri:wght@400;500;600;700&display=swap', array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		}
		wp_register_style( 'nros-app-landing', self::url( 'landing.css' ), array( $font ), self::ver( 'landing.css' ) );
		wp_register_script(
			'nros-app-landing',
			self::url( 'landing.js' ),
			array(),
			self::ver( 'landing.js' ),
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}

	/** Load the CSS in <head> on pages that hold the shortcode (no flash). */
	public static function maybe_enqueue() {
		if ( ! is_singular() ) {
			return;
		}
		$post = get_queried_object();
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		foreach ( self::TAGS as $tag ) {
			if ( has_shortcode( (string) $post->post_content, $tag ) ) {
				wp_enqueue_style( 'nros-app-landing' );
				return;
			}
		}
	}

	// ------------------------------------------------------------------
	// Icons (24×24, stroke 2, currentColor)
	// ------------------------------------------------------------------

	protected static function icon( $name ) {
		$p = array(
			'download' => '<path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/>',
			'android'  => '<path d="M7 9h10v8a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2z"/><path d="M7 9a5 5 0 0 1 10 0"/><path d="m8 4 1.3 2M16 4l-1.3 2"/><path d="M4 10v5M20 10v5"/>',
			'pen'      => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
			'video'    => '<rect x="2" y="6" width="14" height="12" rx="2"/><path d="m16 10 6-3v10l-6-3"/>',
			'live'     => '<circle cx="12" cy="12" r="2"/><path d="M16.2 7.8a6 6 0 0 1 0 8.4M7.8 16.2a6 6 0 0 1 0-8.4"/><path d="M19 5a10 10 0 0 1 0 14M5 19A10 10 0 0 1 5 5"/>',
			'chat'     => '<path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/><path d="M8 11h8M8 14.5h5"/>',
			'bell'     => '<path d="M6 8a6 6 0 1 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.9 1.9 0 0 0 3.4 0"/>',
			'wallet'   => '<path d="M20 7H5a2 2 0 0 1 0-4h13v4"/><path d="M3 5v14a2 2 0 0 0 2 2h15V7"/><path d="M16 14h.01"/>',
			'chart'    => '<path d="M3 3v18h18"/><path d="m7 15 4-4 3 3 5-6"/>',
			'trophy'   => '<path d="M8 21h8M12 17v4"/><path d="M7 4h10v5a5 5 0 0 1-10 0z"/><path d="M17 5h3v2a3 3 0 0 1-3 3M7 5H4v2a3 3 0 0 0 3 3"/>',
			'handshake'=> '<path d="m11 17 2 2a1.4 1.4 0 0 0 2-2"/><path d="m14 14 2.5 2.5a1.4 1.4 0 0 0 2-2l-3.9-3.9a2 2 0 0 0-2.8 0l-.9.9a1.4 1.4 0 0 1-2-2l2.8-2.8a3 3 0 0 1 3.6-.5l.6.3a3 3 0 0 0 2 .3L21 4"/><path d="m21 3 1 9h-2"/><path d="M3 3 2 12l6.5 6.5a1.4 1.4 0 0 0 2-2"/><path d="M3 4h8"/>',
			'shield'   => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
			'sos'      => '<path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/>',
			'pin'      => '<path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 0 1 16 0z"/><circle cx="12" cy="10" r="3"/>',
			'users'    => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8"/>',
			'check'    => '<path d="M20 6 9 17l-5-5"/>',
			'lock'     => '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
			'phone'    => '<rect x="6" y="2" width="12" height="20" rx="2.5"/><path d="M11 18h2"/>',
			'zap'      => '<path d="M13 2 3 14h9l-1 8 10-12h-9z"/>',
			'arrow'    => '<path d="M5 12h14M13 6l6 6-6 6"/>',
			'apple'    => '<path d="M16 3a4 4 0 0 1-3 4 4 4 0 0 1 3-4z"/><path d="M12 8c-1.5 0-2.5-1-4-1-2.5 0-4.5 2.2-4.5 5.7C3.5 16.5 6 21 8 21c1.3 0 1.8-.8 4-.8s2.6.8 4 .8c1.6 0 3.3-2.7 4-5-2-1-3-2.5-3-4.5 0-1.8 1-3.2 2.3-3.8A4.8 4.8 0 0 0 16 7c-1.5 0-2.5 1-4 1z"/>',
			'mic'      => '<rect x="9" y="2" width="6" height="12" rx="3"/><path d="M5 11a7 7 0 0 0 14 0M12 18v4"/>',
		);
		$d = isset( $p[ $name ] ) ? $p[ $name ] : '';
		return '<svg class="mhl-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
	}

	// ------------------------------------------------------------------
	// Render
	// ------------------------------------------------------------------

	public static function render( $atts ) {
		$a = shortcode_atts(
			array(
				'apk'   => self::APK,
				'apply' => self::APPLY,
				'web'   => self::WEB,
				'login' => self::LOGIN,
			),
			$atts,
			'meeha_app_landing'
		);
		$a = apply_filters( 'nros_app_landing_args', $a );

		self::register_assets();
		wp_enqueue_style( 'nros-app-landing' );
		wp_enqueue_script( 'nros-app-landing' );

		// Version from the cache only — never make a visitor wait on a remote
		// fetch; landing.js fills it in from /app/android after load.
		$info    = get_transient( 'nros_android_info' );
		$version = is_array( $info ) && ! empty( $info['version_name'] ) ? (string) $info['version_name'] : '';

		$apk   = esc_url( $a['apk'] );
		$apply = esc_url( $a['apply'] );
		$web   = esc_url( $a['web'] );
		$login = esc_url( $a['login'] );
		$i     = array( __CLASS__, 'icon' );
		$shot  = function ( $n ) {
			return esc_url( self::url( 'shots/' . $n . '.webp' ) . '?v=' . rawurlencode( self::ver( 'shots/' . $n . '.webp' ) ) );
		};
		$cfg = array(
			'info' => esc_url_raw( rest_url( NROS_API_NS . '/app/android' ) ),
			'apk'  => esc_url_raw( $a['apk'] ),
		);
		$qr  = ( self::APK === $a['apk'] && file_exists( NROS_DIR . 'assets/landing/qr-apk.svg' ) ) ? file_get_contents( NROS_DIR . 'assets/landing/qr-apk.svg' ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$features = array(
			array( 'pen', 'red', 'সংবাদ লিখুন, এক চাপে পাঠান', 'ছবি, ক্যাটেগরি আর এলাকা বেছে সরাসরি ডেস্কে পাঠান। খসড়া জমা থাকে, পরে শেষ করুন।' ),
			array( 'video', 'violet', 'ভিডিও সংবাদ', 'ফোনের ভিডিও বা ইউটিউব লিংক দিয়ে ভিডিও খবর পাঠান। বড় ফাইলও টুকরো করে ওঠে, নেট গেলে আবার চলে।' ),
			array( 'live', 'rose', 'ঘটনাস্থল থেকে লাইভ', 'ডেস্ক আপনাকে লাইভে যুক্ত করলে ঘটনাস্থল থেকেই মুহূর্তের আপডেট দিন।' ),
			array( 'chat', 'blue', 'ডেস্কের সাথে চ্যাট', 'মিহা পরিবার, বিভাগ আর নিউজ ডেস্কের রুম। ছবি, ফাইল, ভয়েস মেসেজ আর জরুরি/মিটিং লেবেল।' ),
			array( 'bell', 'amber', 'সঙ্গে সঙ্গে নোটিফিকেশন', 'সংবাদ প্রকাশ, ফেরত, জরুরি মেসেজ, অ্যাপ বন্ধ থাকলেও ফোনে খবর আসে।' ),
			array( 'wallet', 'green', 'আয়ের পুরো হিসাব', 'প্রতিটি সংবাদ ও ভিডিওর পেমেন্ট, বোনাস আর ব্যালান্স। বিকাশ, নগদ বা রকেটে উত্তোলনের অনুরোধ।' ),
			array( 'chart', 'cyan', 'আপনার ড্যাশবোর্ড', 'কত মানুষ পড়ল, লাইক, কমেন্ট, শেয়ার, দিন-সপ্তাহ-মাসের চার্টে এক নজরে।' ),
			array( 'trophy', 'orange', 'ব্যাজ ও লেখার ধারা', 'টানা লেখার ধারা আর নতুন নতুন ব্যাজ। প্রতিদিন একটু এগিয়ে থাকার অনুপ্রেরণা।' ),
			array( 'handshake', 'teal', 'বিজ্ঞাপনে বাড়তি আয়', 'এলাকার বিজ্ঞাপনদাতার তথ্য অ্যাপেই জমা দিন, চুক্তি হলে কমিশন পান।' ),
		);

		$shots = array(
			array( 'home', 'ড্যাশবোর্ড', 'সংবাদের অবস্থা, লেখার ধারা, এই মাসের আয়' ),
			array( 'home2', 'চার্ট ও ভিউ', 'আয়ের প্রবণতা আর এনগেজমেন্ট' ),
			array( 'news', 'আমার সংবাদ', 'কোনটা প্রকাশিত, কোনটা রিভিউতে' ),
			array( 'write', 'লিখুন', 'শিরোনাম, বিবরণ, ছবি, ক্যাটেগরি' ),
			array( 'chat', 'চ্যাট', 'মিহা পরিবার ও নিউজ ডেস্ক' ),
			array( 'earn', 'আয়', 'ব্যালান্স ও প্রতিটি পেমেন্ট' ),
		);

		ob_start();
		?>
<div class="mhl" id="mhl" data-cfg="<?php echo esc_attr( wp_json_encode( $cfg ) ); ?>">

	<!-- ============ HERO ============ -->
	<section class="mhl-hero">
		<div class="mhl-hero-in">
			<div class="mhl-hero-copy">
				<div class="mhl-brand">
					<img src="<?php echo esc_url( self::url( 'app-icon.png' ) ); ?>" width="56" height="56" alt="" class="mhl-appicon">
					<div>
						<b>মিহা সংবাদদাতা</b>
						<span>মিহা নিউজের অফিসিয়াল অ্যাপ</span>
					</div>
				</div>
				<h1 class="mhl-h1">আপনার পুরো নিউজরুম <span>এখন পকেটে</span></h1>
				<p class="mhl-lead">সংবাদ লেখা, ভিডিও পাঠানো, ডেস্কের সাথে কথা আর আয়ের হিসাব, সব এক অ্যাপে। দ্রুত, সহজ, পুরোটা বাংলায়।</p>

				<div class="mhl-cta-wrap">
					<a class="mhl-btn mhl-btn-main" href="<?php echo $apk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above ?>" data-mhl-dl>
						<?php echo $i( 'download' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<span><b>অ্যাপ ডাউনলোড করুন</b><small>Android · ফ্রি<span class="mhl-ver" data-mhl-ver><?php echo $version ? ' · ভার্সন ' . esc_html( $version ) : ''; ?></span></small></span>
					</a>
					<div class="mhl-ios" data-mhl-ios hidden>
						<?php echo $i( 'apple' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<p>iPhone-এর অ্যাপ এখনো আসেনি। ততদিন <a href="<?php echo $web; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>">ওয়েব অ্যাপ</a> ব্যবহার করুন, একই অ্যাকাউন্টে সব কাজ চলে।</p>
					</div>
					<ul class="mhl-trust">
						<li><?php echo $i( 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> সম্পূর্ণ ফ্রি</li>
						<li><?php echo $i( 'lock' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> নিরাপদ লগইন</li>
						<li><?php echo $i( 'zap' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> হালকা ও দ্রুত</li>
					</ul>
				</div>
			</div>

			<div class="mhl-hero-phone">
				<div class="mhl-phone">
					<img src="<?php echo $shot( 'home' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" width="540" height="1170" alt="মিহা সংবাদদাতা অ্যাপের ড্যাশবোর্ড" fetchpriority="high">
				</div>
				<div class="mhl-float mhl-float-a"><?php echo $i( 'bell' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span><b>সংবাদ প্রকাশিত হয়েছে</b>এইমাত্র</span></div>
				<div class="mhl-float mhl-float-b"><?php echo $i( 'wallet' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span><b>+৳১৫০</b>নতুন পেমেন্ট</span></div>
			</div>
		</div>
	</section>

	<!-- ============ QUICK POINTS ============ -->
	<section class="mhl-strip" aria-label="এক নজরে">
		<div><?php echo $i( 'phone' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><b>এক অ্যাপে</b><span>লেখা থেকে আয়</span></div>
		<div><?php echo $i( 'bell' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><b>তাৎক্ষণিক</b><span>নোটিফিকেশন</span></div>
		<div><?php echo $i( 'mic' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><b>ভয়েস ও ছবি</b><span>চ্যাটে পাঠান</span></div>
		<div><?php echo $i( 'wallet' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><b>বিকাশ/নগদ</b><span>উত্তোলন</span></div>
	</section>

	<!-- ============ FEATURES ============ -->
	<section class="mhl-sec" id="mhl-features">
		<p class="mhl-kicker">যা যা পাচ্ছেন</p>
		<h2 class="mhl-h2">মাঠের সাংবাদিকের জন্য বানানো</h2>
		<p class="mhl-sub">ঘটনাস্থলে দাঁড়িয়েই খবর পাঠান। অফিসে ফেরার অপেক্ষা নেই।</p>
		<div class="mhl-grid">
			<?php foreach ( $features as $f ) : ?>
				<article class="mhl-card">
					<span class="mhl-ic mhl-ic-<?php echo esc_attr( $f[1] ); ?>"><?php echo $i( $f[0] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<h3><?php echo esc_html( $f[2] ); ?></h3>
					<p><?php echo esc_html( $f[3] ); ?></p>
				</article>
			<?php endforeach; ?>
		</div>
	</section>

	<!-- ============ SCREENSHOTS ============ -->
	<section class="mhl-sec mhl-shots-sec" id="mhl-screens">
		<p class="mhl-kicker">অ্যাপের ভেতরে</p>
		<h2 class="mhl-h2">এক নজরে আপনার ড্যাশবোর্ড</h2>
		<p class="mhl-sub">আঙুল দিয়ে পাশে টানুন</p>
		<div class="mhl-shots" data-mhl-shots tabindex="0" aria-label="অ্যাপের স্ক্রিনশট">
			<?php foreach ( $shots as $k => $s ) : ?>
				<figure class="mhl-shot">
					<div class="mhl-phone mhl-phone-sm">
						<img src="<?php echo $shot( $s[0] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" width="540" height="1170" alt="<?php echo esc_attr( $s[1] ); ?>" loading="lazy" decoding="async">
					</div>
					<figcaption><b><?php echo esc_html( $s[1] ); ?></b><?php echo esc_html( $s[2] ); ?></figcaption>
				</figure>
			<?php endforeach; ?>
		</div>
		<div class="mhl-dots" data-mhl-dots aria-hidden="true">
			<?php foreach ( $shots as $k => $s ) : ?>
				<span class="<?php echo 0 === $k ? 'on' : ''; ?>"></span>
			<?php endforeach; ?>
		</div>
	</section>

	<!-- ============ MID CTA ============ -->
	<section class="mhl-band">
		<h2>আজই শুরু করুন</h2>
		<p>ডাউনলোড করে আপনার সংবাদদাতা অ্যাকাউন্ট দিয়ে লগইন করুন।</p>
		<a class="mhl-btn mhl-btn-white" href="<?php echo $apk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" data-mhl-dl><?php echo $i( 'download' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span><b>অ্যাপ ডাউনলোড করুন</b></span></a>
	</section>

	<!-- ============ HOW TO INSTALL ============ -->
	<section class="mhl-sec" id="mhl-install">
		<p class="mhl-kicker">২ মিনিটের কাজ</p>
		<h2 class="mhl-h2">কীভাবে ইনস্টল করবেন</h2>
		<ol class="mhl-steps">
			<li><span>১</span><div><b>ডাউনলোড চাপুন</b><p>ওপরের লাল বোতাম চাপলে ফোনে অ্যাপের ফাইল (APK) নামবে।</p></div></li>
			<li><span>২</span><div><b>ফাইলটি খুলুন</b><p>নোটিফিকেশন বা Downloads থেকে <i>meeha-correspondent.apk</i> ছুঁয়ে দিন।</p></div></li>
			<li><span>৩</span><div><b>একবার অনুমতি দিন</b><p>ফোন "অচেনা উৎস" বললে <i>Settings → এই উৎস থেকে অনুমতি দিন</i> চালু করে ফিরে আসুন।</p></div></li>
			<li><span>৪</span><div><b>ইনস্টল করে লগইন</b><p>Install চাপুন, অ্যাপ খুলে আপনার সংবাদদাতা অ্যাকাউন্টে ঢুকুন। নোটিফিকেশন, মাইক আর ক্যামেরার অনুমতি দিন।</p></div></li>
		</ol>
		<p class="mhl-note"><?php echo $i( 'zap' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> নতুন ভার্সন এলে অ্যাপ নিজেই জানাবে, এক চাপে আপডেট।</p>
	</section>

	<!-- ============ সাংবাদিক সেইফ (COMING SOON) ============ -->
	<section class="mhl-safe" id="mhl-safe">
		<div class="mhl-safe-in">
			<span class="mhl-soon">শিগগির আসছে</span>
			<div class="mhl-safe-head">
				<span class="mhl-shield"><?php echo $i( 'shield' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<div>
					<h2>সাংবাদিক সেইফ</h2>
					<p>বিপদে কেউ একা নন, পাশে আছে পুরো মিহা পরিবার।</p>
				</div>
			</div>
			<p class="mhl-safe-lead">মাঠে কাজ করতে গিয়ে হুমকি, হামলা বা বিপদে পড়লে এক চাপে সাহায্য চাওয়ার ব্যবস্থা আমরা বানাচ্ছি। পরিকল্পনায় যা আছে:</p>
			<ul class="mhl-safe-list">
				<li><?php echo $i( 'sos' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><div><b>এক চাপে SOS</b><span>বিপদের সংকেত সঙ্গে সঙ্গে ডেস্ক আর নিরাপত্তা দায়িত্বপ্রাপ্তদের কাছে।</span></div></li>
				<li><?php echo $i( 'users' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><div><b>কাছের সহকর্মীরা জানবেন</b><span>আশপাশের মিহা সংবাদদাতারা সতর্কবার্তা পাবেন, এগিয়ে আসতে পারবেন।</span></div></li>
				<li><?php echo $i( 'chat' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><div><b>উদ্ধার রুম</b><span>সাহায্যে যুক্তদের নিয়ে আলাদা রুম, নিরাপদে ফেরা পর্যন্ত।</span></div></li>
				<li><?php echo $i( 'pin' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><div><b>অবস্থান জানানো</b><span>কোথায় আছেন, দরকারে সাহায্যকারীরা জানতে পারবেন।</span></div></li>
			</ul>
			<p class="mhl-safe-foot">অ্যাপটি এখনই নামিয়ে রাখুন, সেইফ মডিউল এলে আপডেটেই পেয়ে যাবেন।</p>
		</div>
	</section>

	<!-- ============ DESKTOP QR ============ -->
	<?php if ( $qr ) : ?>
	<section class="mhl-qr" data-mhl-qr>
		<div class="mhl-qr-code"><?php echo $qr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- our own static SVG ?></div>
		<div>
			<h3>কম্পিউটারে দেখছেন?</h3>
			<p>ফোনের ক্যামেরা দিয়ে এই কোডটি স্ক্যান করুন, অ্যাপ সরাসরি ফোনে নামবে।</p>
		</div>
	</section>
	<?php endif; ?>

	<!-- ============ FAQ ============ -->
	<section class="mhl-sec" id="mhl-faq">
		<p class="mhl-kicker">প্রশ্ন ও উত্তর</p>
		<h2 class="mhl-h2">যা জানতে চান</h2>
		<div class="mhl-faq">
			<details open>
				<summary>অ্যাপটি কি ফ্রি?</summary>
				<p>হ্যাঁ, মিহা নিউজের সংবাদদাতাদের জন্য অ্যাপটি সম্পূর্ণ ফ্রি।</p>
			</details>
			<details>
				<summary>Play Store-এ নেই কেন? এটা কি নিরাপদ?</summary>
				<p>অ্যাপটি মিহা নিউজের নিজস্ব সাইট থেকে সরাসরি দেওয়া হচ্ছে এবং মিহার নিজস্ব সই-চাবিতে সই করা। তাই প্রথমবার ফোন "অচেনা উৎস" বলে অনুমতি চায়। শুধু এই পাতার বোতাম থেকেই নামান, অন্য কোথাও থেকে নয়।</p>
			</details>
			<details>
				<summary>কোন ফোনে চলবে?</summary>
				<p>Android ফোনে চলবে। iPhone-এর জন্য আপাতত <a href="<?php echo $web; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>">ওয়েব অ্যাপ</a> ব্যবহার করুন।</p>
			</details>
			<details>
				<summary>লগইন কোন অ্যাকাউন্ট দিয়ে?</summary>
				<p>আপনার যে সংবাদদাতা অ্যাকাউন্ট দিয়ে ওয়েবে ঢোকেন, সেটাই। আগের সব সংবাদ, আয় আর চ্যাট অ্যাপেও পাবেন।</p>
			</details>
			<details>
				<summary>এখনো সংবাদদাতা নই, কী করব?</summary>
				<p>নিচের "সংবাদদাতা হতে আবেদন করুন" বোতাম থেকে আবেদন করুন। অনুমোদন পেলে এই অ্যাপেই কাজ শুরু।</p>
			</details>
		</div>
	</section>

	<!-- ============ FINAL CTA ============ -->
	<section class="mhl-final">
		<img src="<?php echo esc_url( self::url( 'app-icon.png' ) ); ?>" width="72" height="72" alt="" class="mhl-appicon" loading="lazy">
		<h2>খবর যেখানে, মিহা সেখানে</h2>
		<p>আপনার এলাকার খবর পৌঁছে দিন সারা দেশে।</p>
		<a class="mhl-btn mhl-btn-main" href="<?php echo $apk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" data-mhl-dl>
			<?php echo $i( 'download' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<span><b>অ্যাপ ডাউনলোড করুন</b><small>Android · ফ্রি</small></span>
		</a>
		<div class="mhl-links">
			<a href="<?php echo $apply; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" class="mhl-btn mhl-btn-ghost"><?php echo $i( 'pen' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span><b>সংবাদদাতা হতে আবেদন করুন</b></span></a>
			<a href="<?php echo $login; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" class="mhl-textlink">ওয়েবে লগইন করুন <?php echo $i( 'arrow' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
		</div>
	</section>

	<!-- ============ STICKY BAR (phones) ============ -->
	<div class="mhl-sticky" data-mhl-sticky aria-hidden="true">
		<img src="<?php echo esc_url( self::url( 'app-icon.png' ) ); ?>" width="40" height="40" alt="" loading="lazy">
		<span><b>মিহা সংবাদদাতা</b><small>Android · ফ্রি</small></span>
		<a href="<?php echo $apk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" data-mhl-dl tabindex="-1"><?php echo $i( 'download' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> ডাউনলোড</a>
	</div>

	<div class="mhl-toast" data-mhl-toast role="status" aria-live="polite" hidden>
		<?php echo $i( 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<span>ডাউনলোড শুরু হয়েছে। শেষ হলে নোটিফিকেশন থেকে ফাইলটি খুলে <b>Install</b> চাপুন।</span>
	</div>
</div>
		<?php
		return trim( (string) ob_get_clean() );
	}
}
