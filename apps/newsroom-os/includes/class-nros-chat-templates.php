<?php
/**
 * NROS_Chat_Templates — ready-made messages for the chat box (1.24.0).
 *
 *  - built-in templates for the desk (to correspondents) and for
 *    correspondents (to the desk), sorted by kind: প্রকাশ, সংশোধন, ছবি ও
 *    ভিডিও, জরুরি, নিরাপত্তা, পেমেন্ট ও প্রশাসন, শুভেচ্ছা, মিটিং ও কাজ;
 *  - a Publisher edits/turns off built-ins and adds the team's own; anyone
 *    keeps personal templates;
 *  - fill-ins: {নাম} {আমার_নাম} {এলাকা} {খবর} {লিংক} {ফেসবুক} {তারিখ}
 *    {কারণ} — the app fills them, a news fill-in opens the “/” news picker;
 *  - “/” in the composer: GET /chat/posts — the correspondent's own recent
 *    news first, search by words, or a post id;
 *  - automatic desk messages: when a correspondent's news/video goes live
 *    (“প্রকাশিত হয়েছে” + link + share link) and when it is returned
 *    (with the reason) — the auto templates, editable, switch on/off.
 *
 * @package NewsroomOS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NROS_Chat_Templates extends NROS_Chat {

	/** Bump to add newly shipped built-ins on existing sites (never overwrites edits). */
	const SEED_REV    = '1';
	const SEED_OPTION = 'nros_chat_tpl_seed';

	/** Post meta: the news's Facebook post link (pasted once by the desk). */
	const FB_META = '_nros_fb_url';

	/** Kinds, in the order the app shows them. */
	const CATS = array(
		'publish' => array( 'প্রকাশ', '📰' ),
		'fix'     => array( 'সংশোধন', '✏️' ),
		'media'   => array( 'ছবি ও ভিডিও', '📷' ),
		'urgent'  => array( 'জরুরি', '🚨' ),
		'safety'  => array( 'নিরাপত্তা', '🛡️' ),
		'admin'   => array( 'পেমেন্ট ও প্রশাসন', '💼' ),
		'greet'   => array( 'শুভেচ্ছা', '🌸' ),
		'meeting' => array( 'মিটিং ও কাজ', '📅' ),
		'mine'    => array( 'নিজের', '⭐' ),
		'auto'    => array( 'অটো বার্তা', '🤖' ),
	);

	/** Fill-ins the app knows. */
	const VARS = array( 'নাম', 'আমার_নাম', 'এলাকা', 'খবর', 'লিংক', 'ফেসবুক', 'তারিখ', 'কারণ' );

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'init', array( __CLASS__, 'maybe_seed' ), 40 );
		add_action( 'nros_submission_approved', array( __CLASS__, 'on_published' ), 30, 1 );
		add_action( 'nros_video_approved', array( __CLASS__, 'on_published' ), 30, 1 );
		add_action( 'nros_submission_rejected', array( __CLASS__, 'on_rejected' ), 30, 2 );
		add_action( 'nros_video_rejected', array( __CLASS__, 'on_rejected' ), 30, 2 );
	}

	/**
	 * Routes.
	 */
	public static function routes() {
		$ns  = NROS_API_NS;
		$app = array( 'NROS_Core', 'require_app_user' );
		$stf = array( 'NROS_Core', 'require_staff' );
		$r   = function ( $path, $method, $cb, $perm ) use ( $ns ) {
			register_rest_route( $ns, $path, array( 'methods' => $method, 'callback' => array( __CLASS__, $cb ), 'permission_callback' => $perm ) );
		};
		$r( '/chat/templates', 'GET', 'list_templates', $app );
		$r( '/chat/templates', 'POST', 'create_template', $app );
		$r( '/chat/templates/(?P<tid>\d+)', 'PATCH', 'update_template', $app );
		$r( '/chat/templates/(?P<tid>\d+)', 'DELETE', 'delete_template', $app );
		$r( '/chat/templates/(?P<tid>\d+)/used', 'POST', 'used', $app );
		$r( '/chat/templates/(?P<tid>\d+)/reset', 'POST', 'reset_template', $app );
		$r( '/chat/posts', 'GET', 'posts', $app );
		$r( '/chat/posts/(?P<pid>\d+)/fb', 'POST', 'set_fb', $stf );
	}

	// ---------------------------------------------------------------------
	// Built-ins
	// ---------------------------------------------------------------------

	/**
	 * key => [title, cat, audience, body, label].
	 * audience: staff (the desk writes) | correspondent (a correspondent writes) | auto.
	 *
	 * @return array<string,array>
	 */
	public static function builtins() {
		$sign = "\n\nধন্যবাদান্তে,\nঅনলাইন ডেস্ক।";
		return array(
			// ---- the desk → a correspondent ----
			'published'        => array( 'সংবাদ প্রকাশিত হয়েছে', 'publish', 'staff', "আপনার নিউজটি প্রকাশিত হয়েছে।\n{লিংক}\n\nএডিটোরিয়াল সংস্করণে পরিবর্তনগুলো লক্ষ্য করুন। ‘র’ (এডিট না করা) ছবি ব্যবহার করার চেষ্টা করবেন।\n\nআপনার বন্ধুদের সাথে নিউজটি শেয়ার করুন।\n{ফেসবুক}" . $sign, '' ),
			'published_video'  => array( 'ভিডিও প্রকাশিত হয়েছে', 'publish', 'staff', "আপনার ভিডিওটি প্রকাশিত হয়েছে।\n{লিংক}\n\nবন্ধুদের সাথে শেয়ার করুন, যত বেশি মানুষ দেখবেন তত ভালো।\n{ফেসবুক}" . $sign, '' ),
			'got_it'           => array( 'সংবাদ পেয়েছি, দেখছি', 'publish', 'staff', "{নাম}, আপনার “{খবর}” পেয়েছি। এডিট করে শিগগির প্রকাশ করা হবে। প্রয়োজনে এখানে জানাব।" . $sign, '' ),
			'fix_info'         => array( 'তথ্য অসম্পূর্ণ', 'fix', 'staff', "{নাম}, আপনার “{খবর}” সংবাদে কিছু তথ্য ঘাটতি আছে। কে, কী, কোথায়, কখন, কেন ও কীভাবে — এই ছয়টি প্রশ্নের উত্তর স্পষ্ট করে আবার পাঠান। সূত্রের নাম ও পদবি দিন।" . $sign, '' ),
			'fix_source'       => array( 'সূত্র যাচাই করুন', 'fix', 'staff', "{নাম}, খবরটি প্রকাশের আগে সূত্র যাচাই দরকার। অন্তত দুটি আলাদা সূত্র থেকে নিশ্চিত হোন, সম্ভব হলে পুলিশ/প্রশাসন বা দায়িত্বশীল কারও বক্তব্য নিন। গুজব বা ফেসবুক পোস্ট একমাত্র সূত্র হতে পারে না।" . $sign, '' ),
			'fix_both_sides'   => array( 'অপর পক্ষের বক্তব্য নিন', 'fix', 'staff', "{নাম}, অভিযোগের খবরে অভিযুক্ত পক্ষের বক্তব্য নেওয়া জরুরি। যোগাযোগ করেও না পেলে লিখুন “বারবার চেষ্টা করেও তাঁর বক্তব্য পাওয়া যায়নি”। এরপর আবার পাঠান।" . $sign, '' ),
			'fix_language'     => array( 'ভাষা ও বানান', 'fix', 'staff', "{নাম}, সংবাদটির ভাষা সহজ রাখুন, এক বাক্যে এক তথ্য। নিজের মতামত বা বিশেষণ (যেমন “ভয়াবহ”, “চাঞ্চল্যকর”) এড়িয়ে চলুন, ঘটনা যেমন তেমনই লিখুন।" . $sign, '' ),
			'media_raw'        => array( '‘র’ ছবি পাঠান', 'media', 'staff', "{নাম}, ছবিতে ফিল্টার, লেখা, লোগো বা ফ্রেম দেবেন না, আসল (‘র’) ছবি পাঠান। অন্তত ৩–৪টি ছবি দিন, বেশিরভাগ আনুভূমিক (ফোন শুইয়ে) তুলবেন।" . $sign, '' ),
			'media_video'      => array( 'ভিডিও যেভাবে ধরবেন', 'media', 'staff', "{নাম}, ভিডিওর জন্য: ফোন শুইয়ে (আনুভূমিক) ধরুন, হাত স্থির রাখুন, প্রতিটি দৃশ্য ১০–১৫ সেকেন্ড। বক্তব্য নিলে মাইক বা ফোন মুখের কাছে রাখুন, বাতাস ও ভিড়ের শব্দ এড়ান। মোট ১–৩ মিনিট যথেষ্ট।" . $sign, '' ),
			'media_sensitive'  => array( 'স্পর্শকাতর ছবি নয়', 'media', 'staff', "{নাম}, মৃতদেহ, রক্তাক্ত দৃশ্য, ধর্ষণ বা নির্যাতনের শিকার এবং শিশুর মুখ — এমন ছবি প্রকাশযোগ্য নয়। পাঠালেও আলাদা করে “স্পর্শকাতর” লিখে দিন, ডেস্ক সিদ্ধান্ত নেবে।" . $sign, '' ),
			'urgent_go'        => array( 'ঘটনাস্থলে যান', 'urgent', 'staff', "জরুরি: {এলাকা}-এ একটি ঘটনার খবর পাওয়া গেছে। নিরাপদ থাকলে ঘটনাস্থলে গিয়ে ছবি, ভিডিও ও প্রাথমিক তথ্য পাঠান। আগে নিজের নিরাপত্তা।", 'urgent' ),
			'urgent_confirm'   => array( 'খবরটি নিশ্চিত করুন', 'urgent', 'staff', "{নাম}, “{খবর}” — এই খবরটি কি নিশ্চিত? ১৫ মিনিটের মধ্যে দায়িত্বশীল কারও কাছ থেকে নিশ্চিত হয়ে জানান।", 'urgent' ),
			'safety_check'     => array( 'আপনি নিরাপদ তো?', 'safety', 'staff', "{নাম}, আপনি নিরাপদ আছেন তো? এলাকার পরিস্থিতি জানান। ঝুঁকি মনে হলে সরে যান, খবরের চেয়ে আপনার নিরাপত্তা বড়। ডেস্ক আপনার পাশে আছে।", '' ),
			'safety_threat'    => array( 'হুমকি পেলে করণীয়', 'safety', 'staff', "{নাম}, হুমকি বা বাধা পেলে:\n১. প্রমাণ (স্ক্রিনশট, রেকর্ড, নম্বর) সংরক্ষণ করুন\n২. সঙ্গে সঙ্গে ডেস্ককে জানান\n৩. প্রয়োজনে নিকটস্থ থানায় জিডি করুন\n৪. একা কোথাও দেখা করতে যাবেন না\nআমরা আপনার সঙ্গে আছি।", 'urgent' ),
			'admin_payment'    => array( 'সম্মানী পাঠানো হয়েছে', 'admin', 'staff', "{নাম}, আপনার সম্মানী পাঠানো হয়েছে। অ্যাপের “আয়” পাতায় বিস্তারিত হিসাব দেখতে পাবেন। কোনো অমিল থাকলে এখানে জানান।" . $sign, '' ),
			'admin_idcard'     => array( 'পরিচয়পত্রের কাগজ', 'admin', 'staff', "{নাম}, আপনার পরিচয়পত্র (আইডি কার্ড) তৈরির জন্য একটি পাসপোর্ট সাইজ ছবি ও জাতীয় পরিচয়পত্রের দুই পাশের ছবি এখানে পাঠান।" . $sign, '' ),
			'admin_inactive'   => array( 'অনেকদিন খবর নেই', 'admin', 'staff', "{নাম}, অনেকদিন আপনার কাছ থেকে কোনো সংবাদ পাইনি। সব ঠিক আছে তো? এলাকার ছোট-বড় খবর পাঠাতে থাকুন, কোনো সমস্যা হলে জানান।" . $sign, '' ),
			'admin_policy'     => array( 'নীতিমালা মনে করিয়ে দেওয়া', 'admin', 'staff', "মনে করিয়ে দিচ্ছি: অন্য গণমাধ্যম বা ফেসবুক থেকে লেখা/ছবি কপি করা যাবে না, মিহার পরিচয় ব্যবহার করে কোনো সুবিধা নেওয়া যাবে না, কারও কাছ থেকে টাকা বা উপহার নেওয়া যাবে না। নীতিমালা ভাঙলে সদস্যপদ বাতিল হতে পারে।" . $sign, 'notice' ),
			'greet_welcome'    => array( 'স্বাগতম', 'greet', 'staff', "স্বাগতম {নাম}! মিহা পরিবারে আপনাকে পেয়ে আমরা আনন্দিত। {এলাকা}-এর খবর এখন থেকে আপনার হাত দিয়ে পাঠকের কাছে যাবে। যেকোনো প্রশ্নে এই ডেস্ক আলাপে লিখুন।" . $sign, '' ),
			'greet_thanks'     => array( 'চমৎকার কাজ', 'greet', 'staff', "{নাম}, চমৎকার কাজ! আপনার “{খবর}” প্রতিবেদনটি পাঠকেরা পছন্দ করছেন। এভাবেই চালিয়ে যান, ধন্যবাদ।\n{লিংক}", '' ),
			'meeting_call'     => array( 'মিটিংয়ের আমন্ত্রণ', 'meeting', 'staff', "আগামী {তারিখ} একটি অনলাইন মিটিং হবে। বিষয়: [বিষয় লিখুন]। সবাইকে উপস্থিত থাকার অনুরোধ রইল।" . $sign, 'meeting' ),
			'task_deadline'    => array( 'ডেডলাইন মনে করিয়ে দেওয়া', 'meeting', 'staff', "{নাম}, মনে করিয়ে দিচ্ছি: “{খবর}”-এর আপডেট আজকের মধ্যে পাঠান।", 'task' ),

			// ---- a correspondent → the desk ----
			'c_sent'           => array( 'সংবাদ পাঠিয়েছি', 'publish', 'correspondent', "একটি সংবাদ পাঠিয়েছি: “{খবর}”। দয়া করে দেখে নেবেন।\n— {আমার_নাম}, {এলাকা}", '' ),
			'c_published_q'    => array( 'কবে প্রকাশ হবে?', 'publish', 'correspondent', "আমার “{খবর}” সংবাদটি কবে প্রকাশ হতে পারে জানালে উপকৃত হব।", '' ),
			'c_breaking'       => array( 'ব্রেকিং খবর', 'urgent', 'correspondent', "ব্রেকিং: {এলাকা}-এ [কী ঘটেছে লিখুন]। বিস্তারিত ও ছবি পাঠাচ্ছি।", 'urgent' ),
			'c_onsite'         => array( 'ঘটনাস্থলে আছি', 'urgent', 'correspondent', "ঘটনাস্থলে আছি। ১৫–২০ মিনিটের মধ্যে ছবি ও তথ্য পাঠাচ্ছি।", '' ),
			'c_slow_net'       => array( 'নেট ধীর, ভিডিও দেরিতে', 'media', 'correspondent', "এখানে নেট খুব ধীর, ভিডিও আপলোড হতে দেরি হবে। আগে লেখা ও ছবি পাঠাচ্ছি, ভিডিও পরে যাবে।", '' ),
			'c_fixed'          => array( 'সংশোধন করে পাঠিয়েছি', 'fix', 'correspondent', "সংশোধন করে আবার পাঠিয়েছি: “{খবর}”। দেখে নেবেন।", '' ),
			'c_why_rejected'   => array( 'কেন ফেরত এল?', 'fix', 'correspondent', "আমার “{খবর}” সংবাদটি কেন প্রকাশ হয়নি জানালে পরের বার ঠিক করে পাঠাতে পারব।", '' ),
			'c_payment'        => array( 'সম্মানী নিয়ে প্রশ্ন', 'admin', 'correspondent', "আমার সম্মানীর বিষয়ে জানতে চাই: [মাস ও প্রশ্ন লিখুন]।", '' ),
			'c_idcard'         => array( 'আইডি কার্ড দরকার', 'admin', 'correspondent', "আমার পরিচয়পত্র (আইডি কার্ড) প্রয়োজন। কী কী পাঠাতে হবে জানাবেন।", '' ),
			'c_leave'          => array( 'ছুটি / এলাকার বাইরে', 'admin', 'correspondent', "আগামী [তারিখ] থেকে [কত দিন] আমি ছুটিতে / এলাকার বাইরে থাকব। এই সময় {এলাকা}-এর খবর দেখার জন্য কাউকে দায়িত্ব দিলে ভালো হয়।", '' ),
			'c_app_problem'    => array( 'অ্যাপে সমস্যা', 'admin', 'correspondent', "অ্যাপে সমস্যা হচ্ছে: [কী হচ্ছে লিখুন]। ফোন: [মডেল]। স্ক্রিনশট দিচ্ছি।", '' ),
			'c_threat'         => array( '⚠️ হুমকি / বাধা পেয়েছি', 'safety', 'correspondent', "⚠️ সংবাদ সংগ্রহ করতে গিয়ে আমি বাধা/হুমকির মুখে পড়েছি। অবস্থান: {এলাকা}। দ্রুত যোগাযোগ করুন।", 'urgent' ),
			'c_safe'           => array( 'আমি নিরাপদ আছি', 'safety', 'correspondent', "আমি নিরাপদে আছি। পরিস্থিতি এখন: [লিখুন]।", '' ),
			'c_source_secret'  => array( 'সূত্রের নাম গোপন রাখুন', 'fix', 'correspondent', "“{খবর}” সংবাদের সূত্রের নাম প্রকাশ না করার অনুরোধ করছি, তাঁর নিরাপত্তার ঝুঁকি আছে।", '' ),
			'c_interview'      => array( 'সাক্ষাৎকারের সুযোগ', 'meeting', 'correspondent', "[কার] সাক্ষাৎকার নেওয়ার সুযোগ আছে। অনুমতি দিলে নিয়ে পাঠাব। প্রশ্ন ঠিক করে দিলে ভালো হয়।", '' ),
			'c_thanks'         => array( 'ধন্যবাদ', 'greet', 'correspondent', "ধন্যবাদ, পেয়েছি। 🙏", '' ),

			// ---- automatic (the bot sends them; turning one off stops it) ----
			'auto_published'   => array( 'খবর প্রকাশ হলে (অটো)', 'auto', 'auto', "🎉 {নাম}, আপনার নিউজটি প্রকাশিত হয়েছে।\n“{খবর}”\n{লিংক}\n\nএডিটোরিয়াল সংস্করণে পরিবর্তনগুলো লক্ষ্য করুন। ‘র’ (এডিট না করা) ছবি ব্যবহার করার চেষ্টা করবেন।\n\nআপনার বন্ধুদের সাথে নিউজটি শেয়ার করুন।\n{ফেসবুক}" . $sign, '' ),
			'auto_rejected'    => array( 'খবর ফেরত গেলে (অটো)', 'auto', 'auto', "{নাম}, আপনার “{খবর}” এবার প্রকাশ করা গেল না।\nকারণ: {কারণ}\n\nপ্রয়োজনীয় সংশোধন করে আবার পাঠাতে পারেন। কোনো প্রশ্ন থাকলে এখানেই লিখুন।" . $sign, '' ),
		);
	}

	/**
	 * Adds missing built-ins (once per SEED_REV); never touches edited rows.
	 */
	public static function maybe_seed() {
		if ( get_option( self::SEED_OPTION ) === self::SEED_REV || ! self::tables_ready() ) {
			return;
		}
		global $wpdb;
		$t = self::t( 'templates' );
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) !== $t ) {
			return;
		}
		$i = 0;
		foreach ( self::builtins() as $key => $b ) {
			++$i;
			if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE tkey = %s", $key ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				continue;
			}
			$wpdb->insert(
				$t,
				array(
					'tkey'       => $key,
					'title'      => $b[0],
					'cat'        => $b[1],
					'audience'   => $b[2],
					'body'       => $b[3],
					'label'      => $b[4],
					'sort'       => $i * 10,
					'created_at' => current_time( 'mysql' ),
				)
			);
		}
		update_option( self::SEED_OPTION, self::SEED_REV, false );
	}

	// ---------------------------------------------------------------------
	// Templates API
	// ---------------------------------------------------------------------

	/**
	 * @param array   $r Row.
	 * @param WP_User $u Viewer.
	 * @return array
	 */
	protected static function shape_tpl( $r, $u ) {
		$own = (int) $r['owner_id'];
		return array(
			'id'       => (int) $r['id'],
			'key'      => (string) $r['tkey'],
			'title'    => (string) $r['title'],
			'body'     => (string) $r['body'],
			'cat'      => $own ? 'mine' : (string) $r['cat'],
			'kind'     => (string) $r['cat'],
			'audience' => (string) $r['audience'],
			'label'    => (string) $r['label'],
			'personal' => (bool) $own,
			'builtin'  => '' !== (string) $r['tkey'],
			'active'   => (bool) (int) $r['active'],
			'uses'     => (int) $r['uses'],
			'can_edit' => $own ? $own === (int) $u->ID : self::is_publisher( $u ),
			'changed'  => '' !== (string) $r['tkey'] && isset( self::builtins()[ $r['tkey'] ] ) && self::builtins()[ $r['tkey'] ][3] !== $r['body'],
		);
	}

	/** Audiences this person writes as. */
	protected static function audiences( $u ) {
		return self::is_staff( $u ) ? array( 'staff', 'all' ) : array( 'correspondent', 'all' );
	}

	/**
	 * GET /chat/templates?manage=1 — templates for me (managers also get the
	 * turned-off ones and the auto ones).
	 */
	public static function list_templates( $request ) {
		global $wpdb;
		self::maybe_seed();
		$u      = self::me( $request );
		$t      = self::t( 'templates' );
		$manage = (bool) $request->get_param( 'manage' );
		$pub    = self::is_publisher( $u );
		$aud    = self::audiences( $u );
		if ( $manage && $pub ) {
			$aud[] = 'auto';
			$aud[] = 'correspondent';
			$aud   = array_unique( $aud );
		}
		$in   = "'" . implode( "','", array_map( 'esc_sql', $aud ) ) . "'";
		$act  = $manage ? '' : ' AND active = 1';
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE ( ( owner_id = 0 AND audience IN ({$in}) ) OR owner_id = %d ){$act} ORDER BY sort ASC, id ASC LIMIT 500", $u->ID ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[] = self::shape_tpl( $r, $u );
		}
		$cats = array();
		foreach ( self::CATS as $k => $c ) {
			$cats[] = array( 'key' => $k, 'name' => $c[0], 'icon' => $c[1] );
		}
		return rest_ensure_response(
			array(
				'templates'  => $out,
				'cats'       => $cats,
				'vars'       => self::VARS,
				'can_manage' => $pub,
				'me'         => array( 'name' => $u->display_name, 'area' => self::person( $u->ID )['area'] ),
			)
		);
	}

	/**
	 * Clean input for a template.
	 *
	 * @return array|WP_Error
	 */
	protected static function clean( $request, $u, $existing = null ) {
		$data = array();
		if ( null !== $request->get_param( 'title' ) || ! $existing ) {
			$title = mb_substr( trim( sanitize_text_field( (string) $request->get_param( 'title' ) ) ), 0, 100 );
			if ( '' === $title ) {
				return new WP_Error( 'nros_bad', 'টেমপ্লেটের একটা নাম দিন।', array( 'status' => 400 ) );
			}
			$data['title'] = $title;
		}
		if ( null !== $request->get_param( 'body' ) || ! $existing ) {
			$body = trim( sanitize_textarea_field( (string) $request->get_param( 'body' ) ) );
			if ( '' === $body ) {
				return new WP_Error( 'nros_bad', 'টেমপ্লেটের লেখা দিন।', array( 'status' => 400 ) );
			}
			$data['body'] = mb_substr( $body, 0, 3000 );
		}
		if ( null !== $request->get_param( 'cat' ) ) {
			$cat         = sanitize_key( (string) $request->get_param( 'cat' ) );
			$data['cat'] = isset( self::CATS[ $cat ] ) && 'mine' !== $cat && 'auto' !== $cat ? $cat : 'publish';
		}
		if ( null !== $request->get_param( 'label' ) ) {
			$l             = sanitize_key( (string) $request->get_param( 'label' ) );
			$data['label'] = isset( self::LABELS[ $l ] ) && 'general' !== $l ? $l : '';
		}
		if ( null !== $request->get_param( 'audience' ) && self::is_publisher( $u ) && ( ! $existing || 'auto' !== $existing['audience'] ) ) {
			$a                = sanitize_key( (string) $request->get_param( 'audience' ) );
			$data['audience'] = in_array( $a, array( 'staff', 'correspondent', 'all' ), true ) ? $a : 'staff';
		}
		if ( null !== $request->get_param( 'active' ) ) {
			$data['active'] = $request->get_param( 'active' ) ? 1 : 0;
		}
		$data['updated_at'] = current_time( 'mysql' );
		return $data;
	}

	/**
	 * POST /chat/templates — { title, body, cat?, label?, audience?, shared? }
	 * shared (the whole team's) needs a Publisher; otherwise it is personal.
	 */
	public static function create_template( $request ) {
		global $wpdb;
		$u      = self::me( $request );
		$shared = (bool) $request->get_param( 'shared' ) && self::is_publisher( $u );
		$data   = self::clean( $request, $u );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$t = self::t( 'templates' );
		if ( ! $shared && (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE owner_id = %d", $u->ID ) ) >= 100 ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return new WP_Error( 'nros_bad', 'নিজের টেমপ্লেট সর্বোচ্চ ১০০টি রাখা যায়।', array( 'status' => 400 ) );
		}
		$data += array(
			'cat'      => 'publish',
			'audience' => self::is_staff( $u ) ? 'staff' : 'correspondent',
		);
		$data['owner_id']   = $shared ? 0 : (int) $u->ID;
		$data['created_by'] = (int) $u->ID;
		$data['sort']       = 1000;
		$data['created_at'] = current_time( 'mysql' );
		$wpdb->insert( $t, $data );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $wpdb->insert_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return rest_ensure_response( self::shape_tpl( $row, $u ) );
	}

	/**
	 * @return array|WP_Error [row, user]
	 */
	protected static function load_tpl( $request ) {
		global $wpdb;
		$u = self::me( $request );
		$t = self::t( 'templates' );
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", (int) $request['tid'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $r ) {
			return new WP_Error( 'nros_not_found', 'টেমপ্লেট পাওয়া যায়নি।', array( 'status' => 404 ) );
		}
		$own = (int) $r['owner_id'];
		if ( $own ? $own !== (int) $u->ID : ! self::is_publisher( $u ) ) {
			return new WP_Error( 'nros_forbidden', 'এই টেমপ্লেট বদলাতে পারেন শুধু পাবলিশার।', array( 'status' => 403 ) );
		}
		return array( $r, $u );
	}

	/**
	 * PATCH /chat/templates/{tid}
	 */
	public static function update_template( $request ) {
		global $wpdb;
		$l = self::load_tpl( $request );
		if ( is_wp_error( $l ) ) {
			return $l;
		}
		list( $r, $u ) = $l;
		$data          = self::clean( $request, $u, $r );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$t = self::t( 'templates' );
		$wpdb->update( $t, $data, array( 'id' => (int) $r['id'] ) );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $r['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return rest_ensure_response( self::shape_tpl( $row, $u ) );
	}

	/**
	 * DELETE /chat/templates/{tid} — a built-in is only turned off.
	 */
	public static function delete_template( $request ) {
		global $wpdb;
		$l = self::load_tpl( $request );
		if ( is_wp_error( $l ) ) {
			return $l;
		}
		list( $r ) = $l;
		$t         = self::t( 'templates' );
		if ( '' !== (string) $r['tkey'] ) {
			$wpdb->update( $t, array( 'active' => 0 ), array( 'id' => (int) $r['id'] ) );
			return rest_ensure_response( array( 'off' => true, 'id' => (int) $r['id'] ) );
		}
		$wpdb->delete( $t, array( 'id' => (int) $r['id'] ) );
		return rest_ensure_response( array( 'deleted' => true, 'id' => (int) $r['id'] ) );
	}

	/**
	 * POST /chat/templates/{tid}/reset — a built-in back to its shipped text.
	 */
	public static function reset_template( $request ) {
		global $wpdb;
		$l = self::load_tpl( $request );
		if ( is_wp_error( $l ) ) {
			return $l;
		}
		list( $r, $u ) = $l;
		$b             = self::builtins();
		if ( '' === (string) $r['tkey'] || ! isset( $b[ $r['tkey'] ] ) ) {
			return new WP_Error( 'nros_bad', 'এটা তৈরি করা টেমপ্লেট নয়।', array( 'status' => 400 ) );
		}
		$x = $b[ $r['tkey'] ];
		$t = self::t( 'templates' );
		$wpdb->update( $t, array( 'title' => $x[0], 'body' => $x[3], 'label' => $x[4], 'active' => 1, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => (int) $r['id'] ) );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $r['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return rest_ensure_response( self::shape_tpl( $row, $u ) );
	}

	/**
	 * POST /chat/templates/{tid}/used — most used first, per team.
	 */
	public static function used( $request ) {
		global $wpdb;
		self::me( $request );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'templates' ) . ' SET uses = uses + 1 WHERE id = %d', (int) $request['tid'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return rest_ensure_response( array( 'ok' => true ) );
	}

	// ---------------------------------------------------------------------
	// “/” news picker
	// ---------------------------------------------------------------------

	/**
	 * A news (post or video) for the picker.
	 *
	 * @param WP_Post $p Post.
	 * @return array
	 */
	protected static function shape_post( $p ) {
		$live = 'publish' === $p->post_status;
		$fb   = (string) get_post_meta( $p->ID, self::FB_META, true );
		// readable (Bangla slugs stay Bangla, not %e0%a6…)
		$url  = $live ? rawurldecode( (string) get_permalink( $p ) ) : '';
		$short = $live ? add_query_arg( 'p', $p->ID, home_url( '/' ) ) : '';
		$st   = array(
			'publish' => 'প্রকাশিত',
			'future'  => 'সময় নির্ধারিত',
			'pending' => 'রিভিউয়ে',
			'draft'   => 'খসড়া',
		);
		return array(
			'id'       => (int) $p->ID,
			'title'    => html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ),
			'url'      => $url,
			'status'   => $p->post_status,
			'status_l' => isset( $st[ $p->post_status ] ) ? $st[ $p->post_status ] : $p->post_status,
			'type'     => 'meeha_video' === $p->post_type ? 'video' : 'post',
			'date'     => mysql_to_rfc3339( $p->post_date_gmt && '0000-00-00 00:00:00' !== $p->post_date_gmt ? $p->post_date_gmt : get_gmt_from_date( $p->post_date ) ) . 'Z',
			'author'   => self::person( $p->post_author )['name'],
			'author_id' => (int) $p->post_author,
			'thumb'    => (string) get_the_post_thumbnail_url( $p, 'thumbnail' ),
			'fb'       => $fb,
			// no page link yet: Facebook's share box for the short link (one tap to share; ?p=ID keeps it short and readable)
			'share'    => $url ? ( $fb ? $fb : 'https://www.facebook.com/sharer/sharer.php?u=' . $short ) : '',
		);
	}

	/**
	 * Can this person see this news in the picker?
	 */
	protected static function can_see_post( $p, $u ) {
		if ( ! $p || ! in_array( $p->post_type, array( 'post', 'meeha_video' ), true ) ) {
			return false;
		}
		if ( 'publish' === $p->post_status ) {
			return true;
		}
		if ( in_array( $p->post_status, array( 'trash', 'auto-draft', 'inherit' ), true ) ) {
			return false;
		}
		return (int) $p->post_author === (int) $u->ID || self::is_staff( $u );
	}

	/**
	 * GET /chat/posts?room=&q= — news for “/”:
	 *  - a number → that post (by id);
	 *  - words → titles that match;
	 *  - nothing → the room's correspondent's (or my) newest first, then the
	 *    site's newest (for the desk).
	 */
	public static function posts( $request ) {
		$u    = self::me( $request );
		$q    = trim( sanitize_text_field( (string) $request->get_param( 'q' ) ) );
		$q    = ltrim( $q, '/#' );
		$room = (int) $request->get_param( 'room' ) ? self::room( (int) $request->get_param( 'room' ) ) : null;
		if ( $room && ! self::can_read( $room, $u ) ) {
			$room = null;
		}
		// whose news comes first
		$focus = 0;
		if ( ! self::is_staff( $u ) ) {
			$focus = (int) $u->ID;
		} elseif ( $room && 'desk' === $room['kind'] ) {
			$focus = (int) $room['owner_id'];
		} elseif ( (int) $request->get_param( 'author' ) ) {
			$focus = (int) $request->get_param( 'author' );
		}
		$types    = array( 'post', 'meeha_video' );
		$statuses = self::is_staff( $u ) ? array( 'publish', 'future', 'pending', 'draft' ) : array( 'publish', 'future', 'pending' );
		$out      = array();
		$seen     = array();
		$add      = function ( $list ) use ( &$out, &$seen, $u ) {
			foreach ( $list as $p ) {
				if ( isset( $seen[ $p->ID ] ) || ! NROS_Chat_Templates::can_see_public( $p, $u ) ) {
					continue;
				}
				$seen[ $p->ID ] = true;
				$out[]          = NROS_Chat_Templates::shape_post_public( $p );
			}
		};
		// a post id (also from a pasted link like …/8285/)
		if ( preg_match( '#(?:^|/)(\d{1,9})/?$#', $q, $m ) || ctype_digit( $q ) ) {
			$id = ctype_digit( $q ) ? (int) $q : (int) $m[1];
			$p  = get_post( $id );
			if ( $p ) {
				$add( array( $p ) );
			}
		}
		$base = array(
			'post_type'              => $types,
			'posts_per_page'         => 12,
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'suppress_filters'       => false,
		);
		if ( $focus ) {
			$args = $base + array( 'author' => $focus, 'post_status' => $focus === (int) $u->ID || self::is_staff( $u ) ? $statuses : array( 'publish' ) );
			if ( '' !== $q && ! ctype_digit( $q ) ) {
				$args['s'] = $q;
			}
			$add( get_posts( $args ) );
		}
		if ( count( $out ) < 20 && ( self::is_staff( $u ) || '' !== $q ) ) {
			$args = $base + array( 'post_status' => array( 'publish' ) );
			if ( '' !== $q && ! ctype_digit( $q ) ) {
				$args['s'] = $q;
			}
			$add( get_posts( $args ) );
		}
		return rest_ensure_response( array( 'posts' => array_slice( $out, 0, 20 ), 'focus' => $focus ? self::person( $focus ) : null ) );
	}

	/** can_see_post() for closures. */
	public static function can_see_public( $p, $u ) {
		return self::can_see_post( $p, $u );
	}

	/** shape_post() for closures. */
	public static function shape_post_public( $p ) {
		return self::shape_post( $p );
	}

	/**
	 * POST /chat/posts/{pid}/fb — { url } the news's Facebook post (desk).
	 */
	public static function set_fb( $request ) {
		$p   = get_post( (int) $request['pid'] );
		$url = esc_url_raw( trim( (string) $request->get_param( 'url' ) ) );
		if ( ! $p || ! in_array( $p->post_type, array( 'post', 'meeha_video' ), true ) ) {
			return new WP_Error( 'nros_not_found', 'সংবাদ পাওয়া যায়নি।', array( 'status' => 404 ) );
		}
		if ( '' === $url ) {
			delete_post_meta( $p->ID, self::FB_META );
		} else {
			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			if ( ! preg_match( '/(^|\.)(facebook\.com|fb\.com|fb\.watch)$/', $host ) ) {
				return new WP_Error( 'nros_bad', 'ফেসবুকের লিংক দিন (facebook.com বা fb.watch)।', array( 'status' => 400 ) );
			}
			update_post_meta( $p->ID, self::FB_META, $url );
		}
		return rest_ensure_response( self::shape_post( $p ) );
	}

	// ---------------------------------------------------------------------
	// Automatic desk messages
	// ---------------------------------------------------------------------

	/**
	 * The text of an auto template, or '' when it is turned off.
	 */
	protected static function auto_body( $key ) {
		global $wpdb;
		self::maybe_seed();
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT body, active FROM ' . self::t( 'templates' ) . ' WHERE tkey = %s', $key ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $r ) {
			$b = self::builtins();
			return isset( $b[ $key ] ) ? $b[ $key ][3] : '';
		}
		return (int) $r['active'] ? (string) $r['body'] : '';
	}

	/**
	 * Fills {…}; unknown fill-ins stay as they are.
	 *
	 * @param string $body Text.
	 * @param array  $vars name => value.
	 * @return string
	 */
	public static function fill( $body, $vars ) {
		return preg_replace_callback(
			'/\{([^{}\s]{1,20})\}/u',
			function ( $m ) use ( $vars ) {
				return array_key_exists( $m[1], $vars ) ? (string) $vars[ $m[1] ] : $m[0];
			},
			$body
		);
	}

	/**
	 * Fill-ins for a correspondent's news.
	 */
	protected static function vars_for( $post, $extra = array() ) {
		$p = self::shape_post( $post );
		$a = self::person( $post->post_author );
		return array_merge(
			array(
				'নাম'       => $a['name'],
				'আমার_নাম'  => 'অনলাইন ডেস্ক',
				'এলাকা'     => $a['area'],
				'খবর'       => $p['title'],
				'লিংক'      => $p['url'] ? $p['url'] : rawurldecode( (string) get_permalink( $post ) ),
				'ফেসবুক'    => $p['share'],
				'তারিখ'     => wp_date( 'j F Y' ),
			),
			$extra
		);
	}

	/**
	 * Is this news from a correspondent (so it has a desk line)?
	 */
	protected static function correspondent_post( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || ! self::tables_ready() ) {
			return null;
		}
		$a = get_userdata( (int) $post->post_author );
		return $a && self::is_correspondent( $a ) ? $post : null;
	}

	/**
	 * A correspondent's news/video went live: “প্রকাশিত হয়েছে” in their desk line.
	 */
	public static function on_published( $post_id ) {
		$post = self::correspondent_post( $post_id );
		if ( ! $post || get_post_meta( $post->ID, '_nros_chat_pub_note', true ) ) {
			return;
		}
		$body = self::auto_body( 'auto_published' );
		if ( '' === $body ) {
			return;
		}
		update_post_meta( $post->ID, '_nros_chat_pub_note', time() );
		if ( 'meeha_video' === $post->post_type ) {
			$body = str_replace( array( 'আপনার নিউজটি', 'নিউজটি শেয়ার' ), array( 'আপনার ভিডিওটি', 'ভিডিওটি শেয়ার' ), $body );
		}
		$room = self::room( self::desk_room( (int) $post->post_author ) );
		if ( $room ) {
			self::bot_say( $room, self::fill( $body, self::vars_for( $post ) ), array( (int) $post->post_author ) );
		}
	}

	/**
	 * A correspondent's news/video was returned: the reason in their desk line.
	 */
	public static function on_rejected( $post_id, $reason = '' ) {
		$post = self::correspondent_post( $post_id );
		if ( ! $post ) {
			return;
		}
		$body = self::auto_body( 'auto_rejected' );
		if ( '' === $body ) {
			return;
		}
		$reason = trim( wp_strip_all_tags( (string) $reason ) );
		$room   = self::room( self::desk_room( (int) $post->post_author ) );
		if ( $room ) {
			self::bot_say( $room, self::fill( $body, self::vars_for( $post, array( 'কারণ' => '' !== $reason ? $reason : 'ডেস্ক শিগগির জানাবে' ) ) ), array( (int) $post->post_author ) );
		}
	}
}
