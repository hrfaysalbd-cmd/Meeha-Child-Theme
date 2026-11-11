<?php
/**
 * NROS_ID_Cards — মিহা সাংবাদিক আইডি কার্ড (r43, newsroom-os 1.26.0).
 *
 * Hafiz (2026-11-11):
 *  - সাংবাদিকরা অ্যাপ থেকে আইডি কার্ডের জন্য আবেদন করবেন, আবেদনের সময় পেমেন্ট;
 *  - পাবলিশার প্যাকেজ বানাবেন (কার্ড + ফিতা + নিয়োগপত্র = ৯৯৯ …);
 *  - মেয়াদ এক বছর; তারপর অটো-রিনিউ / হাতে রিনিউ, নইলে বাতিল;
 *  - দুটি কিউআর: সামনে যাচাই (নাম, পদবি, জেলা, মেয়াদ / মেয়াদ শেষ),
 *    পেছনে ওয়েবসাইট;
 *  - অনুমোদন হলে সঙ্গে সঙ্গে প্রোফাইলে ভার্চুয়াল কার্ড + ডাউনলোড।
 *
 * Model
 *  orders — every application or renewal (with its payment)
 *    status: awaiting (payment not given yet) → review (paid / proof sent,
 *            waiting for the desk) → approved | rejected | cancelled
 *    fulfil: '' → printing → shipped → delivered (the physical kit)
 *  cards  — one per person: the card that the verify page shows
 *    status: active | expired (past the date, inside the grace days) |
 *            cancelled | suspended
 *
 * Payment: no gateway yet (none in this repo). A person pays by bKash /
 * Nagad / Rocket / bank to the numbers the Publisher saves, and types the
 * transaction id; or pays from their Meeha earnings (wallet), which is
 * certain and is taken at once as a ledger row of type `idcard_fee`.
 * A real gateway can later call NROS_ID_Cards::mark_paid().
 *
 * @package NewsroomOS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NROS_ID_Cards {

	const SETTINGS       = 'nros_idc_settings';
	const SEQ            = 'nros_idc_seq';
	const REWRITE_OPTION = 'nros_idc_rewrite';
	const REWRITE_REV    = '1';
	const CRON           = 'nros_idc_daily';

	/** Payment methods the app knows. */
	const METHODS = array(
		'bkash'  => 'বিকাশ',
		'nagad'  => 'নগদ',
		'rocket' => 'রকেট',
		'bank'   => 'ব্যাংক',
		'cash'   => 'অফিসে নগদ',
		'wallet' => 'মিহা আয় থেকে',
		'free'   => 'ফ্রি / অফিস থেকে',
	);

	const AREA_TYPES = array(
		'national' => 'জাতীয়',
		'division' => 'বিভাগীয়',
		'district' => 'জেলা',
		'upazila'  => 'উপজেলা',
		'campus'   => 'ক্যাম্পাস',
		'staff'    => 'ডেস্ক / অফিস',
	);

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'init', array( __CLASS__, 'rewrite' ), 20 );
		add_action( 'init', array( __CLASS__, 'maybe_flush' ), 99 );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'verify_page' ), 1 );
		add_action( self::CRON, array( __CLASS__, 'daily' ) );
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( strtotime( 'tomorrow 03:10' ) - (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ), 'daily', self::CRON );
		}
	}

	public static function t( $t ) {
		global $wpdb;
		return $wpdb->prefix . 'nros_idc_' . $t;
	}

	/**
	 * @param string $c Charset.
	 * @return string[]
	 */
	public static function schema( $c ) {
		$o = self::t( 'orders' );
		$k = self::t( 'cards' );
		return array(
			"CREATE TABLE {$o} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id BIGINT UNSIGNED NOT NULL,
				type VARCHAR(8) NOT NULL DEFAULT 'new',
				pkg_key VARCHAR(40) NOT NULL DEFAULT '',
				pkg LONGTEXT,
				amount DECIMAL(10,2) NOT NULL DEFAULT 0,
				status VARCHAR(12) NOT NULL DEFAULT 'awaiting',
				fulfil VARCHAR(12) NOT NULL DEFAULT '',
				pay_method VARCHAR(12) NOT NULL DEFAULT '',
				pay_txn VARCHAR(80) NOT NULL DEFAULT '',
				pay_from VARCHAR(40) NOT NULL DEFAULT '',
				pay_note VARCHAR(255) NOT NULL DEFAULT '',
				paid_at DATETIME DEFAULT NULL,
				fields LONGTEXT,
				ship_to VARCHAR(255) NOT NULL DEFAULT '',
				staff_note VARCHAR(255) NOT NULL DEFAULT '',
				reason VARCHAR(255) NOT NULL DEFAULT '',
				ledger_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				decided_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
				decided_at DATETIME DEFAULT NULL,
				auto TINYINT(1) NOT NULL DEFAULT 0,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY user_id (user_id),
				KEY status (status),
				KEY fulfil (fulfil)
			) {$c};",
			"CREATE TABLE {$k} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id BIGINT UNSIGNED NOT NULL,
				card_no VARCHAR(30) NOT NULL,
				token VARCHAR(24) NOT NULL,
				status VARCHAR(10) NOT NULL DEFAULT 'active',
				template VARCHAR(20) NOT NULL DEFAULT 'royal',
				fields LONGTEXT,
				photo_url VARCHAR(255) NOT NULL DEFAULT '',
				pkg_key VARCHAR(40) NOT NULL DEFAULT '',
				issued_at DATETIME NOT NULL,
				expires_at DATETIME NOT NULL,
				auto_renew TINYINT(1) NOT NULL DEFAULT 1,
				renewals INT UNSIGNED NOT NULL DEFAULT 0,
				reminded INT NOT NULL DEFAULT 9999,
				last_order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				cancel_reason VARCHAR(255) NOT NULL DEFAULT '',
				scans INT UNSIGNED NOT NULL DEFAULT 0,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY user_id (user_id),
				UNIQUE KEY card_no (card_no),
				UNIQUE KEY token (token),
				KEY status_exp (status,expires_at)
			) {$c};",
		);
	}

	// ---------------------------------------------------------------------
	// Settings (packages, payment numbers, validity …)
	// ---------------------------------------------------------------------

	public static function default_settings() {
		return array(
			'enabled'          => true,
			'packages'         => array(
				array(
					'key'         => 'digital',
					'name'        => 'ডিজিটাল কার্ড',
					'price'       => 299,
					'renew_price' => 199,
					'items'       => array( 'ভার্চুয়াল আইডি কার্ড (অ্যাপে)', 'অটো ভিজিটিং কার্ড', 'যাচাই কিউআর' ),
					'physical'    => false,
					'note'        => 'প্রিন্ট ছাড়া — অ্যাপে ও ফোনে দেখানোর জন্য।',
					'popular'     => false,
					'active'      => true,
				),
				array(
					'key'         => 'basic',
					'name'        => 'বেসিক কিট',
					'price'       => 999,
					'renew_price' => 499,
					'items'       => array( 'প্রিমিয়াম পিভিসি আইডি কার্ড', 'ফিতা (ল্যানইয়ার্ড)', 'নিয়োগপত্র', 'ভার্চুয়াল কার্ড + ভিজিটিং কার্ড' ),
					'physical'    => true,
					'note'        => '',
					'popular'     => true,
					'active'      => true,
				),
				array(
					'key'         => 'pro',
					'name'        => 'প্রো কিট',
					'price'       => 1500,
					'renew_price' => 699,
					'items'       => array( 'প্রিমিয়াম পিভিসি আইডি কার্ড', 'ফিতা (ল্যানইয়ার্ড)', 'নিয়োগপত্র', 'টি-শার্ট', 'প্রেস স্টিকার', 'কলম', 'ভার্চুয়াল কার্ড + ভিজিটিং কার্ড' ),
					'physical'    => true,
					'note'        => '',
					'popular'     => false,
					'active'      => true,
				),
			),
			'methods'          => array(
				'bkash'  => array( 'on' => true, 'number' => '', 'kind' => 'পার্সোনাল', 'help' => 'সেন্ড মানি করে ট্রানজেকশন আইডি দিন।' ),
				'nagad'  => array( 'on' => true, 'number' => '', 'kind' => 'পার্সোনাল', 'help' => 'সেন্ড মানি করে ট্রানজেকশন আইডি দিন।' ),
				'rocket' => array( 'on' => false, 'number' => '', 'kind' => 'পার্সোনাল', 'help' => '' ),
				'bank'   => array( 'on' => false, 'number' => '', 'kind' => '', 'help' => 'ব্যাংকের নাম, হিসাবের নাম ও নম্বর এখানে লিখুন।' ),
				'cash'   => array( 'on' => true, 'number' => '', 'kind' => '', 'help' => 'অফিসে এসে নগদ দিন; ডেস্ক রসিদ দেবে।' ),
				'wallet' => array( 'on' => true, 'number' => '', 'kind' => '', 'help' => 'আপনার মিহা আয়ের ব্যালেন্স থেকে সঙ্গে সঙ্গে কাটা হবে।' ),
			),
			'validity_months'  => 12,
			'grace_days'       => 15,
			'remind_days'      => array( 30, 7, 1 ),
			'auto_renew_days'  => 3,
			'auto_renew_default' => true,
			'auto_approve_wallet' => false,
			'card_prefix'      => 'MN',
			'template'         => 'royal',
			'designations'     => array( 'স্টাফ রিপোর্টার', 'নিজস্ব প্রতিবেদক', 'জেলা প্রতিনিধি', 'উপজেলা প্রতিনিধি', 'ক্যাম্পাস প্রতিনিধি', 'বিশেষ প্রতিনিধি', 'ফটো সাংবাদিক', 'ভিডিও সাংবাদিক', 'সহ-সম্পাদক', 'বার্তা সম্পাদক', 'সম্পাদক' ),
			'back_terms'       => array(
				'এই কার্ডটি মিহা নিউজের সম্পত্তি; বহনকারী মিহা নিউজের প্রতিনিধি।',
				'কার্ড হারালে সঙ্গে সঙ্গে অফিসে জানান।',
				'কিউআর স্ক্যান করে কার্ডের সত্যতা যাচাই করুন।',
			),
			'apply_note'       => 'আবেদনের ৩–৭ কর্মদিবসের মধ্যে প্রিন্ট কার্ড পৌঁছে যাবে। অনুমোদনের সঙ্গে সঙ্গে ভার্চুয়াল কার্ড অ্যাপে পাবেন।',
		);
	}

	public static function settings() {
		$s = get_option( self::SETTINGS, array() );
		$d = self::default_settings();
		$s = is_array( $s ) ? array_merge( $d, $s ) : $d;
		$s['methods'] = array_merge( $d['methods'], is_array( $s['methods'] ) ? $s['methods'] : array() );
		return $s;
	}

	public static function package( $key ) {
		foreach ( (array) self::settings()['packages'] as $p ) {
			if ( isset( $p['key'] ) && $p['key'] === $key ) {
				return $p;
			}
		}
		return null;
	}

	// ---------------------------------------------------------------------
	// Verify page (/verify/{token})
	// ---------------------------------------------------------------------

	public static function verify_url( $token ) {
		$base = get_option( 'permalink_structure' ) ? home_url( '/verify/' ) : add_query_arg( 'meeha_verify', '', home_url( '/' ) );
		if ( '' === $token ) {
			return $base;
		}
		return get_option( 'permalink_structure' ) ? $base . rawurlencode( $token ) : add_query_arg( 'meeha_verify', rawurlencode( $token ), home_url( '/' ) );
	}

	public static function rewrite() {
		add_rewrite_rule( '^verify/?$', 'index.php?meeha_verify=_', 'top' );
		add_rewrite_rule( '^verify/([A-Za-z0-9-]{4,30})/?$', 'index.php?meeha_verify=$matches[1]', 'top' );
	}

	public static function maybe_flush() {
		if ( get_option( self::REWRITE_OPTION ) !== self::REWRITE_REV ) {
			flush_rewrite_rules( false );
			update_option( self::REWRITE_OPTION, self::REWRITE_REV, true );
		}
	}

	public static function query_vars( $v ) {
		$v[] = 'meeha_verify';
		return $v;
	}

	/** Card by token or by card number. */
	public static function find_card( $code ) {
		global $wpdb;
		$code = trim( (string) $code );
		if ( '' === $code ) {
			return null;
		}
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'cards' ) . ' WHERE token = %s OR card_no = %s LIMIT 1', $code, strtoupper( $code ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $r ? $r : null;
	}

	/** What the public may see about a card. */
	public static function public_view( $card ) {
		$st = self::live_status( $card );
		$f  = self::fields_of( $card );
		return array(
			'status'      => $st,
			'status_text' => self::status_text( $st ),
			'name'        => $f['name_bn'],
			'name_en'     => $f['name_en'],
			'designation' => $f['designation'],
			'area'        => self::area_text( $f ),
			'area_type'   => $f['area_type'],
			'card_no'     => $card['card_no'],
			'photo'       => $card['photo_url'],
			'issued_at'   => substr( $card['issued_at'], 0, 10 ),
			'expires_at'  => substr( $card['expires_at'], 0, 10 ),
			'expires_bn'  => self::bn_date( $card['expires_at'] ),
		);
	}

	public static function verify_page() {
		$code = get_query_var( 'meeha_verify' );
		if ( '' === (string) $code && isset( $_GET['meeha_verify'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$code = sanitize_text_field( wp_unslash( $_GET['meeha_verify'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( '' === (string) $code && ! isset( $_GET['meeha_verify'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$code = '_' === $code ? '' : $code;
		if ( '' === $code && isset( $_GET['no'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$code = sanitize_text_field( wp_unslash( $_GET['no'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // LiteSpeed / WP Super Cache
		}
		do_action( 'litespeed_control_set_nocache', 'meeha verify' ); // phpcs:ignore WordPress.NamingConventions.ValidHookName
		$card = $code ? self::find_card( $code ) : null;
		if ( $card ) {
			global $wpdb;
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'cards' ) . ' SET scans = scans + 1 WHERE id = %d', $card['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		self::render_verify( $card ? self::public_view( $card ) : null, $code );
		exit;
	}

	protected static function render_verify( $v, $code ) {
		$org   = NROS_Brand::get();
		$blue  = $org['color_primary'];
		$st    = $v ? $v['status'] : 'unknown';
		$tone  = array(
			'active'    => array( '#047857', '#ecfdf5', '✓', 'ভেরিফাইড সাংবাদিক' ),
			'expired'   => array( '#b45309', '#fffbeb', '!', 'মেয়াদ শেষ' ),
			'cancelled' => array( '#b91c1c', '#fef2f2', '✕', 'কার্ড বাতিল' ),
			'suspended' => array( '#b91c1c', '#fef2f2', '✕', 'কার্ড স্থগিত' ),
			'unknown'   => array( '#475569', '#f1f5f9', '?', 'কার্ড পাওয়া যায়নি' ),
		);
		$t     = isset( $tone[ $st ] ) ? $tone[ $st ] : $tone['unknown'];
		$font  = esc_url( NROS_URL . 'assets/brand/' );
		$home  = esc_url( home_url( '/' ) );
		?><!doctype html>
<html lang="bn"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title><?php echo esc_html( ( $v ? $v['name'] . ' — ' : '' ) . 'কার্ড যাচাই · ' . $org['name_bn'] ); ?></title>
<style>
@font-face{font-family:"MS";src:url("<?php echo $font; // phpcs:ignore ?>meeha-sans-bn-400.woff2") format("woff2");font-weight:400}
@font-face{font-family:"MS";src:url("<?php echo $font; // phpcs:ignore ?>meeha-sans-bn-600.woff2") format("woff2");font-weight:600}
*{box-sizing:border-box}body{margin:0;font-family:"MS","Noto Sans Bengali",system-ui,sans-serif;background:linear-gradient(160deg,#0b1f3a,#062a57 55%,#0b1f3a);min-height:100vh;color:#0f172a;display:flex;align-items:flex-start;justify-content:center;padding:28px 14px}
.w{width:100%;max-width:420px}.top{display:flex;align-items:center;justify-content:space-between;color:#fff;margin-bottom:16px}.top b{font-size:20px;letter-spacing:.2px}.top span{font-size:12px;opacity:.75}
.c{background:#fff;border-radius:22px;overflow:hidden;box-shadow:0 30px 60px rgba(0,0,0,.35)}
.st{display:flex;align-items:center;gap:12px;padding:16px 18px;background:<?php echo esc_attr( $t[1] ); ?>;color:<?php echo esc_attr( $t[0] ); ?>;font-weight:600;font-size:17px}
.st i{font-style:normal;width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:<?php echo esc_attr( $t[0] ); ?>;color:#fff;font-size:18px}
.p{padding:22px 20px;text-align:center}.ph{width:116px;height:136px;border-radius:16px;object-fit:cover;border:3px solid #C9A54C;margin:0 auto 12px;display:block;background:#e2e8f0}
h1{font-size:22px;margin:4px 0 2px}.en{color:#64748b;font-size:13px;letter-spacing:.3px}.d{display:inline-block;margin-top:10px;padding:5px 14px;border-radius:99px;background:<?php echo esc_attr( $blue ); ?>;color:#fff;font-size:14px}
dl{margin:18px 0 0;text-align:left;border-top:1px solid #e2e8f0}dl div{display:flex;justify-content:space-between;gap:10px;padding:10px 2px;border-bottom:1px solid #f1f5f9;font-size:14px}dt{color:#64748b}dd{margin:0;font-weight:600;text-align:right}
.f{padding:14px 20px 18px;font-size:12.5px;color:#64748b;text-align:center;background:#f8fafc}.f a{color:<?php echo esc_attr( $blue ); ?>;font-weight:600;text-decoration:none}
form{display:flex;gap:8px;margin-top:14px}input{flex:1;padding:11px 12px;border-radius:12px;border:1px solid #cbd5e1;font:inherit}button{padding:11px 16px;border:0;border-radius:12px;background:<?php echo esc_attr( $blue ); ?>;color:#fff;font:inherit;font-weight:600}
.warn{margin-top:14px;padding:10px 12px;border-radius:12px;background:#fef2f2;color:#b91c1c;font-size:13px}
</style></head><body><div class="w">
<div class="top"><b><?php echo esc_html( $org['name_bn'] ); ?></b><span>সাংবাদিক কার্ড যাচাই</span></div>
<div class="c">
<div class="st"><i><?php echo esc_html( $t[2] ); ?></i><?php echo esc_html( $t[3] ); ?></div>
<div class="p">
<?php if ( $v ) : ?>
	<?php if ( $v['photo'] ) : ?><img class="ph" src="<?php echo esc_url( $v['photo'] ); ?>" alt=""><?php endif; ?>
	<h1><?php echo esc_html( $v['name'] ); ?></h1>
	<?php if ( $v['name_en'] ) : ?><div class="en"><?php echo esc_html( $v['name_en'] ); ?></div><?php endif; ?>
	<div class="d"><?php echo esc_html( $v['designation'] ); ?></div>
	<dl>
		<?php if ( $v['area'] ) : ?><div><dt>এলাকা</dt><dd><?php echo esc_html( $v['area'] ); ?></dd></div><?php endif; ?>
		<div><dt>কার্ড নম্বর</dt><dd><?php echo esc_html( $v['card_no'] ); ?></dd></div>
		<div><dt><?php echo 'active' === $st ? 'মেয়াদ আছে' : 'মেয়াদ ছিল'; ?></dt><dd><?php echo esc_html( $v['expires_bn'] ); ?> পর্যন্ত</dd></div>
		<div><dt>প্রতিষ্ঠান</dt><dd><?php echo esc_html( $org['name_bn'] ); ?></dd></div>
	</dl>
	<?php if ( 'active' !== $st ) : ?><div class="warn">এই কার্ডটি এখন বৈধ নয়। <?php echo esc_html( $org['name_bn'] ); ?>-এর প্রতিনিধি হিসেবে এটি গ্রহণ করবেন না; সন্দেহ হলে অফিসে যোগাযোগ করুন।</div><?php endif; ?>
<?php else : ?>
	<p style="margin:0;color:#475569"><?php echo $code ? 'এই নম্বর বা কোডের কোনো কার্ড নেই। নম্বরটি ঠিক করে আবার লিখুন।' : 'কার্ডের পেছনের কার্ড নম্বর লিখে যাচাই করুন।'; ?></p>
	<form method="get" action="<?php echo esc_url( self::verify_url( '' ) ); ?>"><?php if ( ! get_option( 'permalink_structure' ) ) : ?><input type="hidden" name="meeha_verify" value=""><?php endif; ?><input name="no" placeholder="যেমন MN-26-0001" value="<?php echo esc_attr( $code ); ?>"><button>যাচাই</button></form>
<?php endif; ?>
</div>
<div class="f">যাচাই করেছে <a href="<?php echo $home; // phpcs:ignore ?>"><?php echo esc_html( $org['website'] ); ?></a><?php echo $org['phone'] ? ' · ফোন ' . esc_html( $org['phone'] ) : ''; ?><br><?php echo esc_html( current_time( 'j/n/Y g:i a' ) ); ?></div>
</div></div></body></html>
		<?php
	}

	// ---------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------

	public static function bn_date( $mysql ) {
		$ts = strtotime( (string) $mysql );
		if ( ! $ts ) {
			return '';
		}
		$m = array( 'জানুয়ারি', 'ফেব্রুয়ারি', 'মার্চ', 'এপ্রিল', 'মে', 'জুন', 'জুলাই', 'আগস্ট', 'সেপ্টেম্বর', 'অক্টোবর', 'নভেম্বর', 'ডিসেম্বর' );
		return NROS_Core::bn_digits( gmdate( 'j', $ts ) ) . ' ' . $m[ (int) gmdate( 'n', $ts ) - 1 ] . ' ' . NROS_Core::bn_digits( gmdate( 'Y', $ts ) );
	}

	/** Status as of now (a card past its date reads "expired" even before the cron runs). */
	public static function live_status( $card ) {
		$st = $card['status'];
		if ( 'active' === $st && strtotime( $card['expires_at'] ) < current_time( 'timestamp' ) ) { // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
			return 'expired';
		}
		return $st;
	}

	public static function status_text( $st ) {
		$m = array(
			'active'    => 'সক্রিয়',
			'expired'   => 'মেয়াদ শেষ',
			'cancelled' => 'বাতিল',
			'suspended' => 'স্থগিত',
		);
		return isset( $m[ $st ] ) ? $m[ $st ] : $st;
	}

	public static function fields_of( $row ) {
		$f = json_decode( (string) $row['fields'], true );
		return self::clean_fields( is_array( $f ) ? $f : array() );
	}

	public static function clean_fields( $in ) {
		$keys = array( 'name_bn', 'name_en', 'designation', 'designation_en', 'area_type', 'division', 'district', 'upazila', 'campus', 'blood', 'phone', 'email', 'emergency', 'nid', 'joined', 'address' );
		$out  = array();
		foreach ( $keys as $k ) {
			$out[ $k ] = isset( $in[ $k ] ) ? mb_substr( sanitize_text_field( (string) $in[ $k ] ), 0, 'address' === $k ? 200 : 80 ) : '';
		}
		if ( ! isset( self::AREA_TYPES[ $out['area_type'] ] ) ) {
			$out['area_type'] = $out['upazila'] ? 'upazila' : ( $out['district'] ? 'district' : 'national' );
		}
		return $out;
	}

	public static function area_text( $f ) {
		switch ( $f['area_type'] ) {
			case 'upazila':
				return trim( implode( ', ', array_filter( array( $f['upazila'], $f['district'] ) ) ) );
			case 'district':
				return $f['district'] ? $f['district'] . ' জেলা' : '';
			case 'division':
				return $f['division'] ? $f['division'] . ' বিভাগ' : '';
			case 'campus':
				return $f['campus'];
			case 'staff':
				return 'প্রধান কার্যালয়';
		}
		return 'জাতীয়';
	}

	/** Fields pre-filled from the person's profile. */
	public static function prefill( $uid ) {
		$u = get_userdata( $uid );
		if ( ! $u ) {
			return self::clean_fields( array() );
		}
		$title = (string) get_user_meta( $uid, 'meeha_author_title', true );
		$staff = user_can( $u, 'edit_others_posts' );
		return self::clean_fields(
			array(
				'name_bn'     => $u->display_name,
				'name_en'     => (string) get_user_meta( $uid, 'nros_name_en', true ),
				'designation' => $title ? $title : ( $staff ? 'সহ-সম্পাদক' : ( get_user_meta( $uid, 'meeha_upazila', true ) ? 'উপজেলা প্রতিনিধি' : 'জেলা প্রতিনিধি' ) ),
				'area_type'   => $staff ? 'staff' : '',
				'division'    => (string) get_user_meta( $uid, 'meeha_division', true ),
				'district'    => (string) get_user_meta( $uid, 'meeha_district', true ),
				'upazila'     => (string) get_user_meta( $uid, 'meeha_upazila', true ),
				'phone'       => (string) get_user_meta( $uid, 'meeha_phone', true ) ? (string) get_user_meta( $uid, 'meeha_phone', true ) : (string) get_user_meta( $uid, 'phone', true ),
				'email'       => $u->user_email,
				'blood'       => (string) get_user_meta( $uid, 'nros_blood', true ),
				'joined'      => substr( $u->user_registered, 0, 10 ),
			)
		);
	}

	protected static function photo_for( $uid ) {
		return class_exists( 'NROS_Media' ) ? NROS_Media::avatar_url( $uid ) : get_avatar_url( $uid, array( 'size' => 400 ) );
	}

	/**
	 * Bigger photo for print: the original app upload when there is one.
	 */
	protected static function print_photo( $uid, $fallback ) {
		$aid = (int) get_user_meta( $uid, '_nros_avatar_id', true );
		if ( $aid && wp_attachment_is_image( $aid ) ) {
			$u = wp_get_attachment_image_url( $aid, 'medium_large' );
			if ( $u ) {
				return $u;
			}
		}
		return $fallback;
	}

	protected static function now() {
		return current_time( 'mysql' );
	}

	public static function shape_card( $c, $with_data = false ) {
		$f   = self::fields_of( $c );
		$out = array(
			'id'          => (int) $c['id'],
			'user_id'     => (int) $c['user_id'],
			'card_no'     => $c['card_no'],
			'token'       => $c['token'],
			'verify_url'  => self::verify_url( $c['token'] ),
			'status'      => self::live_status( $c ),
			'status_text' => self::status_text( self::live_status( $c ) ),
			'template'    => $c['template'],
			'fields'      => $f,
			'area'        => self::area_text( $f ),
			'photo'       => $c['photo_url'],
			'pkg_key'     => $c['pkg_key'],
			'issued_at'   => substr( $c['issued_at'], 0, 10 ),
			'expires_at'  => substr( $c['expires_at'], 0, 10 ),
			'days_left'   => (int) floor( ( strtotime( $c['expires_at'] ) - current_time( 'timestamp' ) ) / DAY_IN_SECONDS ), // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
			'auto_renew'  => (bool) (int) $c['auto_renew'],
			'renewals'    => (int) $c['renewals'],
			'scans'       => (int) $c['scans'],
			'cancel_reason' => $c['cancel_reason'],
			'person'      => class_exists( 'NROS_Chat' ) ? NROS_Chat::person( $c['user_id'] ) : null,
		);
		if ( $with_data ) {
			$out['photo_data'] = NROS_Brand::data_uri( $c['photo_url'], 700 );
		}
		return $out;
	}

	public static function shape_order( $o, $with_data = false ) {
		$pkg = json_decode( (string) $o['pkg'], true );
		$f   = json_decode( (string) $o['fields'], true );
		$f   = self::clean_fields( is_array( $f ) ? $f : array() );
		$ph  = is_array( json_decode( (string) $o['fields'], true ) ) && ! empty( json_decode( (string) $o['fields'], true )['photo'] ) ? esc_url_raw( json_decode( (string) $o['fields'], true )['photo'] ) : '';
		$out = array(
			'id'         => (int) $o['id'],
			'user_id'    => (int) $o['user_id'],
			'type'       => $o['type'],
			'pkg_key'    => $o['pkg_key'],
			'pkg'        => is_array( $pkg ) ? $pkg : null,
			'amount'     => (float) $o['amount'],
			'status'     => $o['status'],
			'fulfil'     => $o['fulfil'],
			'pay_method' => $o['pay_method'],
			'pay_method_text' => isset( self::METHODS[ $o['pay_method'] ] ) ? self::METHODS[ $o['pay_method'] ] : $o['pay_method'],
			'pay_txn'    => $o['pay_txn'],
			'pay_from'   => $o['pay_from'],
			'pay_note'   => $o['pay_note'],
			'paid_at'    => $o['paid_at'] ? mysql_to_rfc3339( get_gmt_from_date( $o['paid_at'] ) ) . 'Z' : null,
			'fields'     => $f,
			'photo'      => $ph,
			'area'       => self::area_text( $f ),
			'ship_to'    => $o['ship_to'],
			'staff_note' => $o['staff_note'],
			'reason'     => $o['reason'],
			'auto'       => (bool) (int) $o['auto'],
			'decided_by' => $o['decided_by'] && class_exists( 'NROS_Chat' ) ? NROS_Chat::person( $o['decided_by'] )['name'] : '',
			'decided_at' => $o['decided_at'] ? mysql_to_rfc3339( get_gmt_from_date( $o['decided_at'] ) ) . 'Z' : null,
			'created_at' => mysql_to_rfc3339( get_gmt_from_date( $o['created_at'] ) ) . 'Z',
			'person'     => class_exists( 'NROS_Chat' ) ? NROS_Chat::person( $o['user_id'] ) : null,
		);
		if ( $with_data ) {
			$out['photo_data'] = NROS_Brand::data_uri( $ph ? $ph : self::print_photo( (int) $o['user_id'], self::photo_for( (int) $o['user_id'] ) ), 700 );
		}
		return $out;
	}

	public static function card_of( $uid ) {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'cards' ) . ' WHERE user_id = %d', $uid ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $r ? $r : null;
	}

	public static function order( $id ) {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'orders' ) . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $r ? $r : null;
	}

	protected static function open_order( $uid ) {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'orders' ) . " WHERE user_id = %d AND status IN ('awaiting','review') ORDER BY id DESC LIMIT 1", $uid ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $r ? $r : null;
	}

	protected static function notify( $uids, $type, $title, $body, $link, $oid = 0 ) {
		if ( class_exists( 'NROS_Notifications' ) ) {
			NROS_Notifications::add( $uids, $type, $title, $body, $link, $oid );
		}
	}

	protected static function notify_staff( $title, $body, $oid ) {
		if ( class_exists( 'NROS_Notifications' ) ) {
			NROS_Notifications::add( NROS_Notifications::staff_ids(), 'idcard_order', $title, $body, '/app/id-cards?order=' . (int) $oid, $oid );
		}
	}

	protected static function bn_money( $n ) {
		return NROS_Core::bn_digits( number_format( (float) $n, 0 ) ) . ' টাকা';
	}

	// ---------------------------------------------------------------------
	// Routes
	// ---------------------------------------------------------------------

	public static function register_routes() {
		$ns  = NROS_API_NS;
		$app = array( 'NROS_Core', 'require_app_user' );
		$stf = array( 'NROS_Core', 'require_staff' );
		$pub = array( 'NROS_Core', 'require_publisher' );
		$r   = function ( $path, $method, $cb, $perm ) use ( $ns ) {
			register_rest_route( $ns, $path, array( 'methods' => $method, 'callback' => array( __CLASS__, $cb ), 'permission_callback' => $perm ) );
		};
		$r( '/idcard/me', 'GET', 'me', $app );
		$r( '/idcard/apply', 'POST', 'apply', $app );
		$r( '/idcard/renew', 'POST', 'renew', $app );
		$r( '/idcard/orders/(?P<id>\d+)/pay', 'POST', 'pay', $app );
		$r( '/idcard/orders/(?P<id>\d+)/cancel', 'POST', 'cancel_own', $app );
		$r( '/idcard/auto-renew', 'POST', 'set_auto_renew', $app );
		$r( '/idcard/verify/(?P<code>[A-Za-z0-9-]{4,30})', 'GET', 'verify_json', '__return_true' );
		$r( '/admin/idcards/summary', 'GET', 'admin_summary', $stf );
		$r( '/admin/idcards/orders', 'GET', 'admin_orders', $stf );
		$r( '/admin/idcards/orders/(?P<id>\d+)', 'GET', 'admin_order', $stf );
		$r( '/admin/idcards/orders/(?P<id>\d+)', 'POST', 'admin_order_action', $stf );
		$r( '/admin/idcards/cards', 'GET', 'admin_cards', $stf );
		$r( '/admin/idcards/cards/(?P<id>\d+)', 'GET', 'admin_card', $stf );
		$r( '/admin/idcards/cards/(?P<id>\d+)', 'POST', 'admin_card_action', $stf );
		$r( '/admin/idcards/issue', 'POST', 'admin_issue', $pub );
		$r( '/admin/idcards/settings', 'GET', 'admin_settings', $stf );
		$r( '/admin/idcards/settings', 'POST', 'admin_save_settings', $pub );
		$r( '/admin/idcards/people', 'GET', 'admin_people', $stf );
	}

	/** Public settings the app needs to show packages and pay numbers. */
	protected static function public_settings() {
		$s       = self::settings();
		$methods = array();
		foreach ( $s['methods'] as $k => $m ) {
			if ( ! empty( $m['on'] ) && isset( self::METHODS[ $k ] ) ) {
				$methods[] = array( 'key' => $k, 'label' => self::METHODS[ $k ], 'number' => (string) $m['number'], 'kind' => (string) $m['kind'], 'help' => (string) $m['help'] );
			}
		}
		return array(
			'enabled'         => (bool) $s['enabled'],
			'packages'        => array_values( array_filter( (array) $s['packages'], function ( $p ) {
				return ! empty( $p['active'] );
			} ) ),
			'methods'         => $methods,
			'validity_months' => (int) $s['validity_months'],
			'grace_days'      => (int) $s['grace_days'],
			'designations'    => (array) $s['designations'],
			'area_types'      => self::AREA_TYPES,
			'back_terms'      => (array) $s['back_terms'],
			'apply_note'      => (string) $s['apply_note'],
			'template'        => (string) $s['template'],
		);
	}

	/** GET /idcard/me */
	public static function me( $request ) {
		global $wpdb;
		$u      = NROS_Auth::get_user_from_request( $request );
		$card   = self::card_of( $u->ID );
		$orders = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'orders' ) . ' WHERE user_id = %d ORDER BY id DESC LIMIT 20', $u->ID ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$photo  = self::print_photo( $u->ID, self::photo_for( $u->ID ) );
		return rest_ensure_response(
			array(
				'card'     => $card ? self::shape_card( $card, true ) : null,
				'orders'   => array_map( array( __CLASS__, 'shape_order' ), (array) $orders ),
				'settings' => self::public_settings(),
				'prefill'  => self::prefill( $u->ID ),
				'photo'    => $photo,
				'balance'  => class_exists( 'NROS_Ledger' ) ? NROS_Ledger::available_balance( $u->ID ) : 0,
				'org'      => NROS_Brand::get(),
				'assets'   => NROS_Brand::assets(),
			)
		);
	}

	/**
	 * Validates the payment part of a request.
	 *
	 * @return array|WP_Error [method, txn, from]
	 */
	protected static function read_pay( $request, $amount, $uid ) {
		$s      = self::settings();
		$method = sanitize_key( (string) $request->get_param( 'pay_method' ) );
		$txn    = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $request->get_param( 'pay_txn' ) ) );
		$from   = preg_replace( '/[^0-9+]/', '', (string) $request->get_param( 'pay_from' ) );
		if ( '' === $method ) {
			return array( '', '', '' ); // pay later
		}
		if ( empty( $s['methods'][ $method ]['on'] ) ) {
			return new WP_Error( 'nros_bad_request', 'এই পেমেন্ট পদ্ধতি এখন চালু নেই।', array( 'status' => 400 ) );
		}
		if ( 'wallet' === $method ) {
			$bal = class_exists( 'NROS_Ledger' ) ? NROS_Ledger::available_balance( $uid ) : 0;
			if ( $bal < $amount ) {
				return new WP_Error( 'nros_low_balance', 'আপনার আয়ের ব্যালেন্সে যথেষ্ট টাকা নেই (আছে ' . self::bn_money( $bal ) . ')।', array( 'status' => 400 ) );
			}
			return array( 'wallet', '', '' );
		}
		if ( in_array( $method, array( 'bkash', 'nagad', 'rocket', 'bank' ), true ) ) {
			if ( strlen( $txn ) < 6 ) {
				return new WP_Error( 'nros_bad_request', 'ট্রানজেকশন আইডি ঠিকমতো লিখুন।', array( 'status' => 400 ) );
			}
			global $wpdb;
			$dup = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t( 'orders' ) . " WHERE pay_txn = %s AND status <> 'rejected'", $txn ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( $dup ) {
				return new WP_Error( 'nros_dup_txn', 'এই ট্রানজেকশন আইডি দিয়ে আগেই একটি আবেদন হয়েছে।', array( 'status' => 400 ) );
			}
		}
		return array( $method, $txn, $from );
	}

	/** Takes the fee from the Meeha earnings (one ledger row). */
	protected static function charge_wallet( $uid, $amount, $order_id, $label ) {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'nros_ledger',
			array(
				'user_id'    => $uid,
				'type'       => 'idcard_fee',
				'amount'     => -1 * abs( (float) $amount ),
				'status'     => 'approved',
				'note'       => $label . ' (অর্ডার #' . (int) $order_id . ')',
				'created_by' => $uid,
				'created_at' => self::now(),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/** POST /idcard/apply */
	public static function apply( $request ) {
		global $wpdb;
		$u = NROS_Auth::get_user_from_request( $request );
		$s = self::settings();
		if ( empty( $s['enabled'] ) ) {
			return new WP_Error( 'nros_closed', 'এখন আইডি কার্ডের আবেদন বন্ধ আছে।', array( 'status' => 400 ) );
		}
		if ( self::open_order( $u->ID ) ) {
			return new WP_Error( 'nros_open_order', 'আপনার একটি আবেদন এখনো চলছে।', array( 'status' => 400 ) );
		}
		$card = self::card_of( $u->ID );
		if ( $card && 'active' === self::live_status( $card ) && (int) floor( ( strtotime( $card['expires_at'] ) - current_time( 'timestamp' ) ) / DAY_IN_SECONDS ) > 30 ) { // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
			return new WP_Error( 'nros_has_card', 'আপনার কার্ড সক্রিয় আছে। মেয়াদ শেষের ৩০ দিন আগে থেকে রিনিউ করা যাবে।', array( 'status' => 400 ) );
		}
		$pkg = self::package( sanitize_key( (string) $request->get_param( 'package' ) ) );
		if ( ! $pkg || empty( $pkg['active'] ) ) {
			return new WP_Error( 'nros_bad_request', 'একটি প্যাকেজ বেছে নিন।', array( 'status' => 400 ) );
		}
		$fields = (array) $request->get_param( 'fields' );
		$clean  = self::clean_fields( $fields );
		if ( '' === $clean['name_bn'] || '' === $clean['designation'] ) {
			return new WP_Error( 'nros_bad_request', 'নাম ও পদবি দিন।', array( 'status' => 400 ) );
		}
		$photo = isset( $fields['photo'] ) ? esc_url_raw( (string) $fields['photo'] ) : '';
		if ( '' === $photo ) {
			$photo = self::print_photo( $u->ID, self::photo_for( $u->ID ) );
		}
		$amount = (float) $pkg['price'];
		$pay    = self::read_pay( $request, $amount, $u->ID );
		if ( is_wp_error( $pay ) ) {
			return $pay;
		}
		$ship = mb_substr( sanitize_text_field( (string) $request->get_param( 'ship_to' ) ), 0, 250 );
		if ( ! empty( $pkg['physical'] ) && '' === $ship ) {
			return new WP_Error( 'nros_bad_request', 'কিট কোথায় পাঠাবো — ঠিকানা দিন।', array( 'status' => 400 ) );
		}
		// remember the English name / blood group for next time
		if ( $clean['name_en'] ) {
			update_user_meta( $u->ID, 'nros_name_en', $clean['name_en'] );
		}
		if ( $clean['blood'] ) {
			update_user_meta( $u->ID, 'nros_blood', $clean['blood'] );
		}
		$now = self::now();
		$wpdb->insert(
			self::t( 'orders' ),
			array(
				'user_id'    => $u->ID,
				'type'       => $card ? 'renew' : 'new',
				'pkg_key'    => $pkg['key'],
				'pkg'        => wp_json_encode( $pkg ),
				'amount'     => $amount,
				'status'     => $pay[0] ? 'review' : 'awaiting',
				'pay_method' => $pay[0],
				'pay_txn'    => $pay[1],
				'pay_from'   => $pay[2],
				'paid_at'    => $pay[0] ? $now : null,
				'fields'     => wp_json_encode( array_merge( $clean, array( 'photo' => $photo ) ) ),
				'ship_to'    => $ship,
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
		$oid = (int) $wpdb->insert_id;
		if ( 'wallet' === $pay[0] ) {
			$lid = self::charge_wallet( $u->ID, $amount, $oid, 'আইডি কার্ড — ' . $pkg['name'] );
			$wpdb->update( self::t( 'orders' ), array( 'ledger_id' => $lid ), array( 'id' => $oid ) );
			if ( ! empty( $s['auto_approve_wallet'] ) ) {
				self::approve( self::order( $oid ), 0, '', true );
			}
		}
		self::notify_staff(
			'🪪 আইডি কার্ডের নতুন আবেদন: ' . $clean['name_bn'],
			$pkg['name'] . ' · ' . self::bn_money( $amount ) . ( $pay[0] ? ' · ' . self::METHODS[ $pay[0] ] . ( $pay[1] ? ' ' . $pay[1] : '' ) : ' · পেমেন্ট বাকি' ),
			$oid
		);
		return rest_ensure_response( array( 'ok' => true, 'order' => self::shape_order( self::order( $oid ) ) ) );
	}

	/** POST /idcard/renew — one tap: the same card, a new year. */
	public static function renew( $request ) {
		global $wpdb;
		$u    = NROS_Auth::get_user_from_request( $request );
		$card = self::card_of( $u->ID );
		if ( ! $card || 'suspended' === $card['status'] ) {
			return new WP_Error( 'nros_bad_request', 'রিনিউ করার মতো কার্ড নেই।', array( 'status' => 400 ) );
		}
		if ( self::open_order( $u->ID ) ) {
			return new WP_Error( 'nros_open_order', 'আপনার একটি আবেদন এখনো চলছে।', array( 'status' => 400 ) );
		}
		$pkg = self::package( $request->get_param( 'package' ) ? sanitize_key( (string) $request->get_param( 'package' ) ) : $card['pkg_key'] );
		if ( ! $pkg ) {
			$pkg = array( 'key' => 'renew', 'name' => 'রিনিউ', 'price' => 500, 'renew_price' => 500, 'items' => array( 'নতুন মেয়াদের কার্ড' ), 'physical' => false );
		}
		$amount = isset( $pkg['renew_price'] ) ? (float) $pkg['renew_price'] : (float) $pkg['price'];
		$pay    = self::read_pay( $request, $amount, $u->ID );
		if ( is_wp_error( $pay ) ) {
			return $pay;
		}
		$oid = self::create_renew_order( $card, $pkg, $amount, $pay, false );
		return rest_ensure_response( array( 'ok' => true, 'order' => self::shape_order( self::order( $oid ) ) ) );
	}

	protected static function create_renew_order( $card, $pkg, $amount, $pay, $auto ) {
		global $wpdb;
		$now = self::now();
		$f   = self::fields_of( $card );
		$wpdb->insert(
			self::t( 'orders' ),
			array(
				'user_id'    => (int) $card['user_id'],
				'type'       => 'renew',
				'pkg_key'    => $pkg['key'],
				'pkg'        => wp_json_encode( $pkg ),
				'amount'     => $amount,
				'status'     => $pay[0] ? 'review' : 'awaiting',
				'pay_method' => $pay[0],
				'pay_txn'    => $pay[1],
				'pay_from'   => $pay[2],
				'paid_at'    => $pay[0] ? $now : null,
				'fields'     => wp_json_encode( array_merge( $f, array( 'photo' => $card['photo_url'] ) ) ),
				'auto'       => $auto ? 1 : 0,
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
		$oid = (int) $wpdb->insert_id;
		if ( 'wallet' === $pay[0] ) {
			$lid = self::charge_wallet( (int) $card['user_id'], $amount, $oid, 'আইডি কার্ড রিনিউ' );
			$wpdb->update( self::t( 'orders' ), array( 'ledger_id' => $lid ), array( 'id' => $oid ) );
			// a renewal paid from the wallet needs no desk check: the person is already approved
			self::approve( self::order( $oid ), 0, '', true );
		} else {
			self::notify_staff( '🔁 আইডি কার্ড রিনিউ: ' . $f['name_bn'], self::bn_money( $amount ) . ( $pay[0] ? ' · ' . self::METHODS[ $pay[0] ] . ' ' . $pay[1] : ' · পেমেন্ট বাকি' ), $oid );
		}
		return $oid;
	}

	/** POST /idcard/orders/{id}/pay — the payment proof, given later. */
	public static function pay( $request ) {
		global $wpdb;
		$u = NROS_Auth::get_user_from_request( $request );
		$o = self::order( (int) $request->get_param( 'id' ) );
		if ( ! $o || (int) $o['user_id'] !== (int) $u->ID || 'awaiting' !== $o['status'] ) {
			return new WP_Error( 'nros_not_found', 'আবেদনটি পাওয়া যায়নি।', array( 'status' => 404 ) );
		}
		$pay = self::read_pay( $request, (float) $o['amount'], $u->ID );
		if ( is_wp_error( $pay ) ) {
			return $pay;
		}
		if ( '' === $pay[0] ) {
			return new WP_Error( 'nros_bad_request', 'পেমেন্ট পদ্ধতি বেছে নিন।', array( 'status' => 400 ) );
		}
		$wpdb->update(
			self::t( 'orders' ),
			array( 'status' => 'review', 'pay_method' => $pay[0], 'pay_txn' => $pay[1], 'pay_from' => $pay[2], 'paid_at' => self::now(), 'updated_at' => self::now() ),
			array( 'id' => (int) $o['id'] )
		);
		if ( 'wallet' === $pay[0] ) {
			$lid = self::charge_wallet( $u->ID, (float) $o['amount'], (int) $o['id'], 'আইডি কার্ড' );
			$wpdb->update( self::t( 'orders' ), array( 'ledger_id' => $lid ), array( 'id' => (int) $o['id'] ) );
			if ( 'renew' === $o['type'] && self::card_of( $u->ID ) ) {
				self::approve( self::order( (int) $o['id'] ), 0, '', true );
			}
		}
		$f = self::fields_of( $o );
		self::notify_staff( '💳 আইডি কার্ডের পেমেন্ট এসেছে: ' . $f['name_bn'], self::METHODS[ $pay[0] ] . ' ' . $pay[1] . ' · ' . self::bn_money( $o['amount'] ), (int) $o['id'] );
		return rest_ensure_response( array( 'ok' => true, 'order' => self::shape_order( self::order( (int) $o['id'] ) ) ) );
	}

	public static function cancel_own( $request ) {
		global $wpdb;
		$u = NROS_Auth::get_user_from_request( $request );
		$o = self::order( (int) $request->get_param( 'id' ) );
		if ( ! $o || (int) $o['user_id'] !== (int) $u->ID || 'awaiting' !== $o['status'] ) {
			return new WP_Error( 'nros_bad_request', 'শুধু পেমেন্ট না দেওয়া আবেদন বাতিল করা যায়।', array( 'status' => 400 ) );
		}
		$wpdb->update( self::t( 'orders' ), array( 'status' => 'cancelled', 'updated_at' => self::now() ), array( 'id' => (int) $o['id'] ) );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public static function set_auto_renew( $request ) {
		global $wpdb;
		$u    = NROS_Auth::get_user_from_request( $request );
		$card = self::card_of( $u->ID );
		if ( ! $card ) {
			return new WP_Error( 'nros_not_found', 'কার্ড নেই।', array( 'status' => 404 ) );
		}
		$wpdb->update( self::t( 'cards' ), array( 'auto_renew' => $request->get_param( 'on' ) ? 1 : 0, 'updated_at' => self::now() ), array( 'id' => (int) $card['id'] ) );
		return rest_ensure_response( array( 'ok' => true, 'card' => self::shape_card( self::card_of( $u->ID ) ) ) );
	}

	public static function verify_json( $request ) {
		$c = self::find_card( (string) $request->get_param( 'code' ) );
		if ( ! $c ) {
			return new WP_Error( 'nros_not_found', 'কার্ড পাওয়া যায়নি।', array( 'status' => 404 ) );
		}
		return rest_ensure_response( self::public_view( $c ) );
	}

	// ---------------------------------------------------------------------
	// Issue / approve
	// ---------------------------------------------------------------------

	protected static function next_card_no() {
		global $wpdb;
		$s      = self::settings();
		$prefix = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $s['card_prefix'] ) );
		$prefix = $prefix ? $prefix : 'MN';
		do {
			$n  = (int) get_option( self::SEQ, 0 ) + 1;
			update_option( self::SEQ, $n, false );
			$no = $prefix . '-' . current_time( 'y' ) . '-' . str_pad( (string) $n, 4, '0', STR_PAD_LEFT );
		} while ( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'cards' ) . ' WHERE card_no = %s', $no ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $no;
	}

	protected static function new_token() {
		$abc = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		$t   = '';
		for ( $i = 0; $i < 10; $i++ ) {
			$t .= $abc[ random_int( 0, strlen( $abc ) - 1 ) ];
		}
		return $t;
	}

	/**
	 * Approves an order: makes the card (new) or adds a year (renew).
	 *
	 * @param array  $o      Order row.
	 * @param int    $by     Staff id (0 = automatic).
	 * @param string $note   Note to the person.
	 * @param bool   $auto   Approved by the system.
	 * @param array  $over   Field overrides from the desk.
	 * @return array Card row.
	 */
	public static function approve( $o, $by = 0, $note = '', $auto = false, $over = array(), $template = '' ) {
		global $wpdb;
		$s      = self::settings();
		$uid    = (int) $o['user_id'];
		$now    = self::now();
		$months = max( 1, (int) $s['validity_months'] );
		$raw    = json_decode( (string) $o['fields'], true );
		$raw    = is_array( $raw ) ? $raw : array();
		$fields = self::clean_fields( array_merge( $raw, (array) $over ) );
		$photo  = ! empty( $over['photo'] ) ? esc_url_raw( $over['photo'] ) : ( ! empty( $raw['photo'] ) ? esc_url_raw( $raw['photo'] ) : self::photo_for( $uid ) );
		$card   = self::card_of( $uid );
		if ( $card ) {
			// a new year starts from the old end date if it hasn't passed yet
			$from = max( strtotime( $card['expires_at'] ), current_time( 'timestamp' ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
			$exp  = gmdate( 'Y-m-d 23:59:59', strtotime( '+' . $months . ' months', $from ) );
			$wpdb->update(
				self::t( 'cards' ),
				array(
					'status'        => 'active',
					'fields'        => wp_json_encode( $fields ),
					'photo_url'     => $photo ? $photo : $card['photo_url'],
					'pkg_key'       => $o['pkg_key'],
					'template'      => $template ? $template : $card['template'],
					'expires_at'    => $exp,
					'renewals'      => (int) $card['renewals'] + ( 'renew' === $o['type'] ? 1 : 0 ),
					'reminded'      => 9999,
					'last_order_id' => (int) $o['id'],
					'cancel_reason' => '',
					'updated_at'    => $now,
				),
				array( 'id' => (int) $card['id'] )
			);
		} else {
			$exp = gmdate( 'Y-m-d 23:59:59', strtotime( '+' . $months . ' months', current_time( 'timestamp' ) ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
			$wpdb->insert(
				self::t( 'cards' ),
				array(
					'user_id'       => $uid,
					'card_no'       => self::next_card_no(),
					'token'         => self::new_token(),
					'status'        => 'active',
					'template'      => $template ? $template : (string) $s['template'],
					'fields'        => wp_json_encode( $fields ),
					'photo_url'     => (string) $photo,
					'pkg_key'       => $o['pkg_key'],
					'issued_at'     => $now,
					'expires_at'    => $exp,
					'auto_renew'    => ! empty( $s['auto_renew_default'] ) ? 1 : 0,
					'last_order_id' => (int) $o['id'],
					'created_at'    => $now,
					'updated_at'    => $now,
				)
			);
		}
		$pkg    = json_decode( (string) $o['pkg'], true );
		$fulfil = is_array( $pkg ) && ! empty( $pkg['physical'] ) && 'renew' !== $o['type'] ? 'printing' : '';
		$wpdb->update(
			self::t( 'orders' ),
			array(
				'status'     => 'approved',
				'fulfil'     => $fulfil,
				'fields'     => wp_json_encode( array_merge( $fields, array( 'photo' => $photo ) ) ),
				'staff_note' => mb_substr( (string) $note, 0, 250 ),
				'decided_by' => (int) $by,
				'decided_at' => $now,
				'auto'       => $auto ? 1 : (int) $o['auto'],
				'updated_at' => $now,
			),
			array( 'id' => (int) $o['id'] )
		);
		$card = self::card_of( $uid );
		self::notify(
			array( $uid ),
			'idcard_approved',
			'renew' === $o['type'] ? '🪪 আপনার আইডি কার্ড রিনিউ হয়েছে' : '🪪 আপনার মিহা আইডি কার্ড তৈরি হয়েছে',
			'কার্ড নম্বর ' . $card['card_no'] . ' · মেয়াদ ' . self::bn_date( $card['expires_at'] ) . ' পর্যন্ত। প্রোফাইলে ভার্চুয়াল কার্ড দেখুন ও ডাউনলোড করুন।' . ( $note ? ' — ' . $note : '' ),
			'/app/idcard',
			(int) $o['id']
		);
		if ( class_exists( 'NROS_Chat' ) && method_exists( 'NROS_Chat', 'bot_to_desk' ) ) {
			NROS_Chat::bot_to_desk( $uid, '🪪 অভিনন্দন! আপনার মিহা আইডি কার্ড ' . ( 'renew' === $o['type'] ? 'রিনিউ হয়েছে' : 'তৈরি হয়েছে' ) . ' — কার্ড নম্বর ' . $card['card_no'] . ', মেয়াদ ' . self::bn_date( $card['expires_at'] ) . ' পর্যন্ত। প্রোফাইল → আইডি কার্ড থেকে ডাউনলোড করুন।' );
		}
		do_action( 'nros_idcard_approved', $card, $o );
		return $card;
	}

	/** For a future gateway: marks an order paid. */
	public static function mark_paid( $order_id, $method, $txn ) {
		global $wpdb;
		$wpdb->update( self::t( 'orders' ), array( 'status' => 'review', 'pay_method' => sanitize_key( $method ), 'pay_txn' => sanitize_text_field( $txn ), 'paid_at' => self::now(), 'updated_at' => self::now() ), array( 'id' => (int) $order_id, 'status' => 'awaiting' ) );
	}

	// ---------------------------------------------------------------------
	// Desk (publisher app)
	// ---------------------------------------------------------------------

	public static function admin_summary() {
		global $wpdb;
		$o   = self::t( 'orders' );
		$c   = self::t( 'cards' );
		$now = self::now();
		$in30 = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) + 30 * DAY_IN_SECONDS ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		$month = current_time( 'Y-m-01 00:00:00' );
		return rest_ensure_response(
			array(
				'review'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$o} WHERE status = 'review'" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'awaiting' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$o} WHERE status = 'awaiting'" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'printing' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$o} WHERE status = 'approved' AND fulfil IN ('printing','shipped')" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'active'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$c} WHERE status = 'active' AND expires_at >= %s", $now ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'expiring' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$c} WHERE status = 'active' AND expires_at BETWEEN %s AND %s", $now, $in30 ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'expired'  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$c} WHERE status = 'expired' OR (status = 'active' AND expires_at < %s)", $now ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'income_month' => (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM {$o} WHERE status = 'approved' AND pay_method NOT IN ('free','') AND decided_at >= %s", $month ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'income_total' => (float) $wpdb->get_var( "SELECT COALESCE(SUM(amount),0) FROM {$o} WHERE status = 'approved' AND pay_method NOT IN ('free','')" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			)
		);
	}

	public static function admin_orders( $request ) {
		global $wpdb;
		$st   = sanitize_key( (string) $request->get_param( 'status' ) );
		$q    = trim( (string) $request->get_param( 'q' ) );
		$sql  = 'SELECT * FROM ' . self::t( 'orders' ) . ' WHERE 1=1';
		$args = array();
		if ( 'printing' === $st ) {
			$sql .= " AND status = 'approved' AND fulfil IN ('printing','shipped')";
		} elseif ( $st && 'all' !== $st ) {
			$sql   .= ' AND status = %s';
			$args[] = $st;
		}
		if ( '' !== $q ) {
			$sql   .= ' AND (fields LIKE %s OR pay_txn LIKE %s)';
			$like   = '%' . $wpdb->esc_like( $q ) . '%';
			$args[] = $like;
			$args[] = $like;
		}
		$sql .= ' ORDER BY FIELD(status,\'review\',\'awaiting\',\'approved\',\'rejected\',\'cancelled\'), id DESC LIMIT 300';
		$rows = $args ? $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return rest_ensure_response( array( 'items' => array_map( array( __CLASS__, 'shape_order' ), (array) $rows ) ) );
	}

	public static function admin_order( $request ) {
		$o = self::order( (int) $request->get_param( 'id' ) );
		if ( ! $o ) {
			return new WP_Error( 'nros_not_found', 'অর্ডার পাওয়া যায়নি।', array( 'status' => 404 ) );
		}
		$card = self::card_of( (int) $o['user_id'] );
		return rest_ensure_response(
			array(
				'order'   => self::shape_order( $o, true ),
				'card'    => $card ? self::shape_card( $card ) : null,
				'balance' => class_exists( 'NROS_Ledger' ) ? NROS_Ledger::available_balance( (int) $o['user_id'] ) : 0,
			)
		);
	}

	/**
	 * POST /admin/idcards/orders/{id} {action: approve|reject|printing|shipped|delivered|note, note, reason, fields, template}
	 */
	public static function admin_order_action( $request ) {
		global $wpdb;
		$u      = NROS_Auth::get_user_from_request( $request );
		$o      = self::order( (int) $request->get_param( 'id' ) );
		$action = sanitize_key( (string) $request->get_param( 'action' ) );
		$note   = sanitize_text_field( (string) $request->get_param( 'note' ) );
		if ( ! $o ) {
			return new WP_Error( 'nros_not_found', 'অর্ডার পাওয়া যায়নি।', array( 'status' => 404 ) );
		}
		$name = self::fields_of( $o )['name_bn'];
		switch ( $action ) {
			case 'approve':
				if ( ! in_array( $o['status'], array( 'review', 'awaiting' ), true ) ) {
					return new WP_Error( 'nros_bad_request', 'এই অর্ডারটি আগেই নিষ্পত্তি হয়েছে।', array( 'status' => 400 ) );
				}
				if ( 'awaiting' === $o['status'] ) {
					// the desk got the money another way (cash at the office / free)
					$m = sanitize_key( (string) $request->get_param( 'pay_method' ) );
					$m = isset( self::METHODS[ $m ] ) && 'wallet' !== $m ? $m : 'cash';
					$wpdb->update( self::t( 'orders' ), array( 'pay_method' => $m, 'paid_at' => self::now(), 'amount' => 'free' === $m ? 0 : $o['amount'] ), array( 'id' => (int) $o['id'] ) );
					$o = self::order( (int) $o['id'] );
				}
				$over = (array) $request->get_param( 'fields' );
				self::approve( $o, $u->ID, $note, false, $over, sanitize_key( (string) $request->get_param( 'template' ) ) );
				break;
			case 'reject':
				if ( ! in_array( $o['status'], array( 'review', 'awaiting' ), true ) ) {
					return new WP_Error( 'nros_bad_request', 'এই অর্ডারটি আগেই নিষ্পত্তি হয়েছে।', array( 'status' => 400 ) );
				}
				$reason = sanitize_text_field( (string) $request->get_param( 'reason' ) );
				$wpdb->update( self::t( 'orders' ), array( 'status' => 'rejected', 'reason' => mb_substr( $reason, 0, 250 ), 'decided_by' => $u->ID, 'decided_at' => self::now(), 'updated_at' => self::now() ), array( 'id' => (int) $o['id'] ) );
				// money taken from the wallet goes back
				if ( (int) $o['ledger_id'] ) {
					$wpdb->update( $wpdb->prefix . 'nros_ledger', array( 'status' => 'rejected' ), array( 'id' => (int) $o['ledger_id'] ) );
				}
				self::notify( array( (int) $o['user_id'] ), 'idcard_rejected', 'আইডি কার্ডের আবেদন গ্রহণ হয়নি', $reason ? $reason : 'বিস্তারিত জানতে ডেস্কে যোগাযোগ করুন।', '/app/idcard', (int) $o['id'] );
				break;
			case 'printing':
			case 'shipped':
			case 'delivered':
				if ( 'approved' !== $o['status'] ) {
					return new WP_Error( 'nros_bad_request', 'আগে অনুমোদন দিন।', array( 'status' => 400 ) );
				}
				$wpdb->update( self::t( 'orders' ), array( 'fulfil' => $action, 'staff_note' => $note ? mb_substr( $note, 0, 250 ) : $o['staff_note'], 'updated_at' => self::now() ), array( 'id' => (int) $o['id'] ) );
				$msg = array(
					'printing' => '🖨️ আপনার কার্ড ও কিট প্রিন্টে আছে',
					'shipped'  => '📦 আপনার কার্ড ও কিট পাঠানো হয়েছে',
					'delivered' => '✅ আপনার কিট পৌঁছেছে বলে চিহ্নিত হয়েছে',
				);
				self::notify( array( (int) $o['user_id'] ), 'idcard_' . $action, $msg[ $action ], $note, '/app/idcard', (int) $o['id'] );
				break;
			case 'note':
				$wpdb->update( self::t( 'orders' ), array( 'staff_note' => mb_substr( $note, 0, 250 ), 'updated_at' => self::now() ), array( 'id' => (int) $o['id'] ) );
				break;
			case 'remind':
				self::notify( array( (int) $o['user_id'] ), 'idcard_pay', '💳 আইডি কার্ডের পেমেন্ট বাকি', 'আবেদন #' . NROS_Core::bn_digits( $o['id'] ) . ' — ' . self::bn_money( $o['amount'] ) . '। অ্যাপে ট্রানজেকশন আইডি দিন।', '/app/idcard', (int) $o['id'] );
				break;
			default:
				return new WP_Error( 'nros_bad_request', 'অজানা কাজ।', array( 'status' => 400 ) );
		}
		unset( $name );
		return self::admin_order( $request );
	}

	public static function admin_cards( $request ) {
		global $wpdb;
		$st   = sanitize_key( (string) $request->get_param( 'status' ) );
		$q    = trim( (string) $request->get_param( 'q' ) );
		$now  = self::now();
		$sql  = 'SELECT * FROM ' . self::t( 'cards' ) . ' WHERE 1=1';
		$args = array();
		if ( 'active' === $st ) {
			$sql   .= " AND status = 'active' AND expires_at >= %s";
			$args[] = $now;
		} elseif ( 'expiring' === $st ) {
			$sql   .= " AND status = 'active' AND expires_at BETWEEN %s AND %s";
			$args[] = $now;
			$args[] = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) + 30 * DAY_IN_SECONDS ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		} elseif ( 'expired' === $st ) {
			$sql   .= " AND (status = 'expired' OR (status = 'active' AND expires_at < %s))";
			$args[] = $now;
		} elseif ( in_array( $st, array( 'cancelled', 'suspended' ), true ) ) {
			$sql   .= ' AND status = %s';
			$args[] = $st;
		}
		if ( '' !== $q ) {
			$sql   .= ' AND (fields LIKE %s OR card_no LIKE %s)';
			$like   = '%' . $wpdb->esc_like( $q ) . '%';
			$args[] = $like;
			$args[] = $like;
		}
		$sql .= ' ORDER BY expires_at ASC LIMIT 500';
		$rows = $args ? $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return rest_ensure_response( array( 'items' => array_map( array( __CLASS__, 'shape_card' ), (array) $rows ) ) );
	}

	public static function admin_card( $request ) {
		global $wpdb;
		$c = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'cards' ) . ' WHERE id = %d', (int) $request->get_param( 'id' ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $c ) {
			return new WP_Error( 'nros_not_found', 'কার্ড পাওয়া যায়নি।', array( 'status' => 404 ) );
		}
		$orders = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'orders' ) . ' WHERE user_id = %d ORDER BY id DESC LIMIT 20', $c['user_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return rest_ensure_response( array( 'card' => self::shape_card( $c, true ), 'orders' => array_map( array( __CLASS__, 'shape_order' ), (array) $orders ) ) );
	}

	/**
	 * POST /admin/idcards/cards/{id} {action: edit|extend|cancel|suspend|reactivate, fields, template, months, reason, expires_at}
	 */
	public static function admin_card_action( $request ) {
		global $wpdb;
		$u = NROS_Auth::get_user_from_request( $request );
		$c = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'cards' ) . ' WHERE id = %d', (int) $request->get_param( 'id' ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $c ) {
			return new WP_Error( 'nros_not_found', 'কার্ড পাওয়া যায়নি।', array( 'status' => 404 ) );
		}
		$action = sanitize_key( (string) $request->get_param( 'action' ) );
		$set    = array( 'updated_at' => self::now() );
		$pub    = NROS_Chat::is_publisher( $u );
		switch ( $action ) {
			case 'edit':
				$set['fields'] = wp_json_encode( self::clean_fields( array_merge( self::fields_of( $c ), (array) $request->get_param( 'fields' ) ) ) );
				$tpl           = sanitize_key( (string) $request->get_param( 'template' ) );
				if ( $tpl ) {
					$set['template'] = $tpl;
				}
				$photo = esc_url_raw( (string) $request->get_param( 'photo' ) );
				if ( $photo ) {
					$set['photo_url'] = $photo;
				}
				if ( null !== $request->get_param( 'auto_renew' ) ) {
					$set['auto_renew'] = $request->get_param( 'auto_renew' ) ? 1 : 0;
				}
				break;
			case 'extend':
				if ( ! $pub ) {
					return new WP_Error( 'nros_forbidden', 'মেয়াদ বাড়াতে পারেন শুধু পাবলিশার।', array( 'status' => 403 ) );
				}
				$date = sanitize_text_field( (string) $request->get_param( 'expires_at' ) );
				if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
					$set['expires_at'] = $date . ' 23:59:59';
				} else {
					$months            = max( 1, min( 36, (int) $request->get_param( 'months' ) ) );
					$from              = max( strtotime( $c['expires_at'] ), current_time( 'timestamp' ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
					$set['expires_at'] = gmdate( 'Y-m-d 23:59:59', strtotime( '+' . $months . ' months', $from ) );
				}
				$set['status']   = 'active';
				$set['reminded'] = 9999;
				self::notify( array( (int) $c['user_id'] ), 'idcard_extended', '🪪 আপনার কার্ডের মেয়াদ বাড়ানো হয়েছে', 'নতুন মেয়াদ ' . self::bn_date( $set['expires_at'] ) . ' পর্যন্ত।', '/app/idcard', (int) $c['id'] );
				break;
			case 'cancel':
			case 'suspend':
				if ( ! $pub ) {
					return new WP_Error( 'nros_forbidden', 'কার্ড বাতিল/স্থগিত করতে পারেন শুধু পাবলিশার।', array( 'status' => 403 ) );
				}
				$set['status']        = 'cancel' === $action ? 'cancelled' : 'suspended';
				$set['cancel_reason'] = mb_substr( sanitize_text_field( (string) $request->get_param( 'reason' ) ), 0, 250 );
				self::notify( array( (int) $c['user_id'] ), 'idcard_' . $set['status'], 'cancel' === $action ? 'আপনার আইডি কার্ড বাতিল করা হয়েছে' : 'আপনার আইডি কার্ড স্থগিত করা হয়েছে', $set['cancel_reason'], '/app/idcard', (int) $c['id'] );
				break;
			case 'reactivate':
				if ( ! $pub ) {
					return new WP_Error( 'nros_forbidden', 'শুধু পাবলিশার।', array( 'status' => 403 ) );
				}
				$set['status']        = strtotime( $c['expires_at'] ) < current_time( 'timestamp' ) ? 'expired' : 'active'; // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
				$set['cancel_reason'] = '';
				break;
			case 'remind':
				self::notify( array( (int) $c['user_id'] ), 'idcard_renew', '🔁 আইডি কার্ড রিনিউ করুন', 'মেয়াদ ' . self::bn_date( $c['expires_at'] ) . ' পর্যন্ত। অ্যাপ থেকে এক ট্যাপে রিনিউ করুন।', '/app/idcard', (int) $c['id'] );
				break;
			default:
				return new WP_Error( 'nros_bad_request', 'অজানা কাজ।', array( 'status' => 400 ) );
		}
		$wpdb->update( self::t( 'cards' ), $set, array( 'id' => (int) $c['id'] ) );
		return self::admin_card( $request );
	}

	/** POST /admin/idcards/issue — the Publisher issues a card directly (editors, office staff, free cards). */
	public static function admin_issue( $request ) {
		global $wpdb;
		$u   = NROS_Auth::get_user_from_request( $request );
		$uid = (int) $request->get_param( 'user_id' );
		$who = get_userdata( $uid );
		if ( ! $who ) {
			return new WP_Error( 'nros_bad_request', 'ব্যবহারকারী পাওয়া যায়নি।', array( 'status' => 400 ) );
		}
		$pkg    = self::package( sanitize_key( (string) $request->get_param( 'package' ) ) );
		$pkg    = $pkg ? $pkg : array( 'key' => 'office', 'name' => 'অফিস থেকে', 'price' => 0, 'renew_price' => 0, 'items' => array( 'আইডি কার্ড' ), 'physical' => true );
		$method = sanitize_key( (string) $request->get_param( 'pay_method' ) );
		$method = isset( self::METHODS[ $method ] ) && 'wallet' !== $method ? $method : 'free';
		$amount = 'free' === $method ? 0 : (float) ( null !== $request->get_param( 'amount' ) ? $request->get_param( 'amount' ) : $pkg['price'] );
		$fields = array_merge( self::prefill( $uid ), (array) $request->get_param( 'fields' ) );
		$photo  = esc_url_raw( (string) $request->get_param( 'photo' ) );
		$now    = self::now();
		$wpdb->insert(
			self::t( 'orders' ),
			array(
				'user_id'    => $uid,
				'type'       => self::card_of( $uid ) ? 'renew' : 'new',
				'pkg_key'    => $pkg['key'],
				'pkg'        => wp_json_encode( $pkg ),
				'amount'     => $amount,
				'status'     => 'review',
				'pay_method' => $method,
				'pay_txn'    => mb_substr( sanitize_text_field( (string) $request->get_param( 'pay_txn' ) ), 0, 80 ),
				'paid_at'    => $now,
				'fields'     => wp_json_encode( array_merge( self::clean_fields( $fields ), array( 'photo' => $photo ? $photo : self::print_photo( $uid, self::photo_for( $uid ) ) ) ) ),
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
		$oid  = (int) $wpdb->insert_id;
		$card = self::approve( self::order( $oid ), $u->ID, '', false, array(), sanitize_key( (string) $request->get_param( 'template' ) ) );
		return rest_ensure_response( array( 'ok' => true, 'card' => self::shape_card( $card, true ) ) );
	}

	public static function admin_settings() {
		$s = self::settings();
		return rest_ensure_response( array( 'settings' => $s, 'defaults' => self::default_settings(), 'methods' => self::METHODS, 'area_types' => self::AREA_TYPES ) );
	}

	public static function admin_save_settings( $request ) {
		$in  = (array) $request->get_json_params();
		$in  = isset( $in['settings'] ) && is_array( $in['settings'] ) ? $in['settings'] : $in;
		$cur = self::settings();
		$out = $cur;
		if ( isset( $in['packages'] ) && is_array( $in['packages'] ) ) {
			$pk   = array();
			$seen = array();
			foreach ( $in['packages'] as $p ) {
				$name = mb_substr( sanitize_text_field( (string) ( $p['name'] ?? '' ) ), 0, 60 );
				if ( '' === $name ) {
					continue;
				}
				$key = sanitize_key( (string) ( $p['key'] ?? '' ) );
				$key = $key ? $key : 'p' . substr( md5( $name . microtime() ), 0, 6 );
				if ( isset( $seen[ $key ] ) ) {
					$key .= '-' . count( $pk );
				}
				$seen[ $key ] = true;
				$pk[]         = array(
					'key'         => $key,
					'name'        => $name,
					'price'       => max( 0, (float) ( $p['price'] ?? 0 ) ),
					'renew_price' => max( 0, (float) ( $p['renew_price'] ?? 0 ) ),
					'items'       => array_values( array_filter( array_map( function ( $i ) {
						return mb_substr( sanitize_text_field( (string) $i ), 0, 80 );
					}, (array) ( $p['items'] ?? array() ) ) ) ),
					'physical'    => ! empty( $p['physical'] ),
					'note'        => mb_substr( sanitize_text_field( (string) ( $p['note'] ?? '' ) ), 0, 200 ),
					'popular'     => ! empty( $p['popular'] ),
					'active'      => ! empty( $p['active'] ),
				);
			}
			$out['packages'] = $pk;
		}
		if ( isset( $in['methods'] ) && is_array( $in['methods'] ) ) {
			foreach ( $in['methods'] as $k => $m ) {
				if ( ! isset( $cur['methods'][ $k ] ) ) {
					continue;
				}
				$out['methods'][ $k ] = array(
					'on'     => ! empty( $m['on'] ),
					'number' => mb_substr( sanitize_text_field( (string) ( $m['number'] ?? '' ) ), 0, 160 ),
					'kind'   => mb_substr( sanitize_text_field( (string) ( $m['kind'] ?? '' ) ), 0, 40 ),
					'help'   => mb_substr( sanitize_text_field( (string) ( $m['help'] ?? '' ) ), 0, 240 ),
				);
			}
		}
		foreach ( array( 'validity_months' => array( 1, 60 ), 'grace_days' => array( 0, 120 ), 'auto_renew_days' => array( 0, 60 ) ) as $k => $range ) {
			if ( isset( $in[ $k ] ) ) {
				$out[ $k ] = max( $range[0], min( $range[1], (int) $in[ $k ] ) );
			}
		}
		foreach ( array( 'enabled', 'auto_renew_default', 'auto_approve_wallet' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$out[ $k ] = (bool) $in[ $k ];
			}
		}
		if ( isset( $in['remind_days'] ) ) {
			$d = array_values( array_unique( array_filter( array_map( 'intval', (array) $in['remind_days'] ), function ( $n ) {
				return $n > 0 && $n <= 120;
			} ) ) );
			rsort( $d );
			$out['remind_days'] = $d;
		}
		foreach ( array( 'card_prefix', 'template', 'apply_note' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$out[ $k ] = mb_substr( sanitize_text_field( (string) $in[ $k ] ), 0, 'apply_note' === $k ? 400 : 20 );
			}
		}
		foreach ( array( 'designations', 'back_terms' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$out[ $k ] = array_values( array_filter( array_map( function ( $i ) {
					return mb_substr( sanitize_text_field( (string) $i ), 0, 160 );
				}, (array) $in[ $k ] ) ) );
			}
		}
		update_option( self::SETTINGS, $out, false );
		return self::admin_settings();
	}

	/** GET /admin/idcards/people?q= — anyone who can get a card (for "নতুন কার্ড"). */
	public static function admin_people( $request ) {
		$q     = trim( (string) $request->get_param( 'q' ) );
		$args  = array( 'role__in' => array( 'administrator', 'meeha_publisher', 'editor', 'correspondent' ), 'number' => 30, 'orderby' => 'display_name' );
		if ( '' !== $q ) {
			$args['search']         = '*' . $q . '*';
			$args['search_columns'] = array( 'display_name', 'user_email', 'user_login' );
		}
		$out = array();
		foreach ( get_users( $args ) as $u ) {
			$c     = self::card_of( $u->ID );
			$p     = NROS_Chat::person( $u->ID );
			$out[] = array_merge(
				$p,
				array(
					'card'    => $c ? array( 'card_no' => $c['card_no'], 'status' => self::live_status( $c ), 'expires_at' => substr( $c['expires_at'], 0, 10 ) ) : null,
					'prefill' => self::prefill( $u->ID ),
					'photo'   => self::print_photo( $u->ID, self::photo_for( $u->ID ) ),
				)
			);
		}
		return rest_ensure_response( array( 'items' => $out ) );
	}

	// ---------------------------------------------------------------------
	// Daily: reminders, auto-renew, expiry, cancel after grace
	// ---------------------------------------------------------------------

	public static function daily() {
		global $wpdb;
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', self::t( 'cards' ) ) ) !== self::t( 'cards' ) ) {
			return;
		}
		$s     = self::settings();
		$now   = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		$cards = $wpdb->get_results( "SELECT * FROM " . self::t( 'cards' ) . " WHERE status IN ('active','expired')", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$days  = (array) $s['remind_days'];
		foreach ( (array) $cards as $c ) {
			$left = (int) floor( ( strtotime( $c['expires_at'] ) - $now ) / DAY_IN_SECONDS );
			$uid  = (int) $c['user_id'];
			// 1. auto-renew from the wallet a few days before the end
			if ( (int) $c['auto_renew'] && $left <= (int) $s['auto_renew_days'] && $left > -1 * (int) $s['grace_days'] && ! self::open_order( $uid ) && ! empty( $s['methods']['wallet']['on'] ) ) {
				$pkg    = self::package( $c['pkg_key'] );
				$pkg    = $pkg ? $pkg : array( 'key' => $c['pkg_key'], 'name' => 'রিনিউ', 'price' => 500, 'renew_price' => 500, 'items' => array(), 'physical' => false );
				$amount = isset( $pkg['renew_price'] ) ? (float) $pkg['renew_price'] : (float) $pkg['price'];
				$bal    = class_exists( 'NROS_Ledger' ) ? NROS_Ledger::available_balance( $uid ) : 0;
				if ( $bal >= $amount ) {
					self::create_renew_order( $c, $pkg, $amount, array( 'wallet', '', '' ), true );
					continue;
				}
				if ( ! get_user_meta( $uid, 'nros_idc_lowbal_' . substr( $c['expires_at'], 0, 10 ), true ) ) {
					update_user_meta( $uid, 'nros_idc_lowbal_' . substr( $c['expires_at'], 0, 10 ), 1 );
					self::notify( array( $uid ), 'idcard_renew', '🔁 অটো-রিনিউ হয়নি — ব্যালেন্স কম', 'রিনিউ ফি ' . self::bn_money( $amount ) . ', আপনার আয়ে আছে ' . self::bn_money( $bal ) . '। অ্যাপ থেকে বিকাশ/নগদে রিনিউ করুন।', '/app/idcard', (int) $c['id'] );
				}
			}
			// 2. reminders (30 / 7 / 1 days before)
			foreach ( $days as $d ) {
				if ( $left <= (int) $d && $left >= 0 && (int) $c['reminded'] > (int) $d ) {
					$wpdb->update( self::t( 'cards' ), array( 'reminded' => (int) $d ), array( 'id' => (int) $c['id'] ) );
					self::notify( array( $uid ), 'idcard_renew', '⏳ আইডি কার্ডের মেয়াদ আর ' . NROS_Core::bn_digits( max( 0, $left ) ) . ' দিন', 'মেয়াদ ' . self::bn_date( $c['expires_at'] ) . ' পর্যন্ত। ' . ( (int) $c['auto_renew'] ? 'অটো-রিনিউ চালু আছে — আয়ের ব্যালেন্স থেকে রিনিউ হবে।' : 'অ্যাপ থেকে এক ট্যাপে রিনিউ করুন।' ), '/app/idcard', (int) $c['id'] );
					break;
				}
			}
			// 3. past the date → expired; past the grace days → cancelled
			if ( $left < 0 && 'active' === $c['status'] ) {
				$wpdb->update( self::t( 'cards' ), array( 'status' => 'expired', 'updated_at' => self::now() ), array( 'id' => (int) $c['id'] ) );
				self::notify( array( $uid ), 'idcard_expired', '⚠️ আপনার আইডি কার্ডের মেয়াদ শেষ', NROS_Core::bn_digits( (int) $s['grace_days'] ) . ' দিনের মধ্যে রিনিউ না করলে কার্ডটি বাতিল হয়ে যাবে।', '/app/idcard', (int) $c['id'] );
			}
			if ( $left < -1 * (int) $s['grace_days'] && ! self::open_order( $uid ) ) {
				$wpdb->update( self::t( 'cards' ), array( 'status' => 'cancelled', 'cancel_reason' => 'মেয়াদ শেষে রিনিউ হয়নি', 'updated_at' => self::now() ), array( 'id' => (int) $c['id'] ) );
				$f = self::fields_of( $c );
				self::notify( array( $uid ), 'idcard_cancelled', 'আপনার আইডি কার্ড বাতিল হয়েছে', 'মেয়াদ শেষে রিনিউ না হওয়ায়। আবার আবেদন করে নতুন করে চালু করতে পারবেন।', '/app/idcard', (int) $c['id'] );
				if ( class_exists( 'NROS_Notifications' ) ) {
					NROS_Notifications::add( NROS_Notifications::staff_ids(), 'idcard_order', '🪪 কার্ড বাতিল (রিনিউ হয়নি): ' . $f['name_bn'], $c['card_no'], '/app/id-cards?tab=cards&status=cancelled', (int) $c['id'] );
				}
			}
		}
		// 4. the desk: a short morning digest when something waits
		$review = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::t( 'orders' ) . " WHERE status = 'review'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$print  = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::t( 'orders' ) . " WHERE status = 'approved' AND fulfil = 'printing'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ( $review || $print ) && class_exists( 'NROS_Notifications' ) ) {
			$pubs = array_map( 'intval', get_users( array( 'role__in' => array( 'administrator', 'meeha_publisher' ), 'fields' => 'ID' ) ) );
			NROS_Notifications::add( $pubs, 'idcard_digest', '🪪 আইডি কার্ড: ' . NROS_Core::bn_digits( $review ) . 'টি অনুমোদনের অপেক্ষায়, ' . NROS_Core::bn_digits( $print ) . 'টি প্রিন্টে', 'আইডি কার্ড ডেস্ক খুলে দেখুন।', '/app/id-cards', 0 );
		}
	}

	/** Counter for the publisher app's sidebar badge. */
	public static function badge() {
		global $wpdb;
		static $n = null;
		if ( null === $n ) {
			$n = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::t( 'orders' ) . " WHERE status = 'review'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		return $n;
	}
}
