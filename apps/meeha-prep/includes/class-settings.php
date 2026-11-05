<?php
/**
 * Settings: one tab "প্রস্তুতি ও পেমেন্ট" inside the theme's "মিহা থিম" settings page (option meeha_opt_prep).
 * The store password never goes back into the page: it is shown as "saved" and only replaced when typed again.
 *
 * @package meeha-prep
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings tab.
 */
class MHP_Settings {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'meeha_register_settings', array( __CLASS__, 'register' ) );
		add_action( 'admin_notices', array( __CLASS__, 'theme_notice' ) );
	}

	/**
	 * Defaults (also used when the theme is not active).
	 *
	 * @return array
	 */
	public static function defaults() {
		$out = array();
		foreach ( self::fields() as $f ) {
			if ( ! empty( $f['key'] ) && 'heading' !== $f['type'] ) {
				$out[ $f['key'] ] = $f['default'];
			}
		}
		$out['ssl_store_pass'] = '';
		return $out;
	}

	/**
	 * Field definitions in the theme framework's format.
	 *
	 * @return array
	 */
	public static function fields() {
		return array(
			array(
				'type'  => 'heading',
				'label' => 'অনলাইন পেমেন্ট (SSLCommerz)',
				'desc'  => 'ডিফল্টভাবে "মিহা ডোনেশন" প্লাগিনের SSLCommerz Store ID, পাসওয়ার্ড ও স্যান্ডবক্স/লাইভ মোড ব্যবহার হয়, আলাদা করে বসাতে হয় না।',
			),
			array(
				'key'     => 'cred_source',
				'type'    => 'radio',
				'label'   => 'স্টোরের তথ্য',
				'default' => 'donation',
				'cap'     => 'admin',
				'options' => array(
					'donation' => 'মিহা ডোনেশন প্লাগিনের সেটিং ব্যবহার করুন (প্রস্তাবিত)',
					'own'      => 'আলাদা স্টোর: নিচের Store ID/পাসওয়ার্ড/মোড',
				),
			),
			array(
				'key'     => 'pay_on',
				'type'    => 'checkbox',
				'label'   => 'অনলাইন পেমেন্ট',
				'text'    => 'চালু করুন',
				'default' => 0,
				'cap'     => 'admin',
				'desc'    => 'বন্ধ থাকলে পেইড টেস্ট ও পণ্যে "কিনুন" বাটনের বদলে "শিগগির আসছে" দেখাবে। ফ্রি টেস্ট ও কুইজ চলবে।',
			),
			array(
				'key'     => 'pay_mode',
				'type'    => 'radio',
				'label'   => 'মোড (শুধু আলাদা স্টোরে)',
				'default' => 'sandbox',
				'cap'     => 'admin',
				'options' => array(
					'sandbox' => 'স্যান্ডবক্স (পরীক্ষা, আসল টাকা কাটবে না)',
					'live'    => 'লাইভ (আসল পেমেন্ট)',
				),
			),
			array(
				'key'     => 'ssl_store_id',
				'type'    => 'text',
				'label'   => 'Store ID (শুধু আলাদা স্টোরে)',
				'default' => '',
				'cap'     => 'admin',
			),
			array(
				'key'     => 'pay_staff_only',
				'type'    => 'checkbox',
				'label'   => 'পরীক্ষামূলক কেনাকাটা',
				'text'    => 'শুধু অ্যাডমিন ও এডিটর কিনতে পারবেন',
				'default' => 1,
				'cap'     => 'admin',
				'desc'    => 'লাইভে খোলার আগে নিজেরা কিনে দেখুন। সবার জন্য খুলতে টিক তুলে দিন।',
			),
			array(
				'key'     => 'risk_hold',
				'type'    => 'checkbox',
				'label'   => 'ঝুঁকিপূর্ণ লেনদেন',
				'text'    => 'SSLCommerz ঝুঁকিপূর্ণ (risk_level = 1) বললে অ্যাক্সেস না দিয়ে "পর্যালোচনা" অবস্থায় রাখুন',
				'default' => 1,
				'cap'     => 'admin',
			),
			array(
				'key'     => 'order_prefix',
				'type'    => 'text',
				'label'   => 'অর্ডার নম্বরের শুরু',
				'default' => 'MHP',
				'cap'     => 'admin',
				'desc'    => 'শুধু ইংরেজি অক্ষর, ২–৬টি। ডোনেশনের অর্ডার নম্বরের সঙ্গে যেন না মেলে।',
				'sanitize' => function ( $v ) {
					$v = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $v ) );
					return ( strlen( $v ) >= 2 && strlen( $v ) <= 6 ) ? $v : 'MHP';
				},
			),
			array(
				'key'     => 'cus_city',
				'type'    => 'text',
				'label'   => 'ক্রেতার শহর (ডিফল্ট)',
				'default' => 'Dhaka',
				'cap'     => 'admin',
				'desc'    => 'SSLCommerz-এ ঠিকানা বাধ্যতামূলক; ডিজিটাল পণ্যে এটি পাঠানো হয়।',
			),
			array(
				'key'     => 'pending_hours',
				'type'    => 'number',
				'label'   => 'অসম্পূর্ণ অর্ডার বাতিল',
				'default' => 24,
				'min'     => 1,
				'max'     => 168,
				'unit'    => 'ঘণ্টা পরে (গেটওয়েতে যাচাই করে)',
				'cap'     => 'admin',
			),

			array(
				'type'  => 'heading',
				'label' => 'টেস্ট ও কুইজ',
			),
			array(
				'key'     => 'grace_seconds',
				'type'    => 'number',
				'label'   => 'জমার বাড়তি সময়',
				'default' => 30,
				'min'     => 0,
				'max'     => 300,
				'unit'    => 'সেকেন্ড (নেটওয়ার্ক দেরির জন্য)',
			),
			array(
				'key'     => 'quiz_count',
				'type'    => 'number',
				'label'   => 'দৈনিক কুইজের প্রশ্ন',
				'default' => 5,
				'min'     => 3,
				'max'     => 15,
				'unit'    => 'টি',
			),
			array(
				'key'     => 'quiz_guest',
				'type'    => 'checkbox',
				'label'   => 'অতিথি',
				'text'    => 'লগইন ছাড়াও কুইজ খেলা যাবে',
				'default' => 0,
				'desc'    => 'চালু করলে অতিথিরাও উত্তর ও ব্যাখ্যা দেখতে পারেন, তাই কেউ আগে উত্তর জেনে লগইন করে খেললে লিডারবোর্ড নিখুঁত থাকে না। লিডারবোর্ডে পুরস্কার দিলে বন্ধ রাখুন।',
			),
			array(
				'key'     => 'quiz_sponsor',
				'type'    => 'text',
				'label'   => 'কুইজ স্পনসরের নাম',
				'default' => '',
				'desc'    => 'খালি থাকলে স্পনসর লাইন দেখাবে না।',
			),
			array(
				'key'     => 'quiz_sponsor_url',
				'type'    => 'url',
				'label'   => 'স্পনসরের লিংক',
				'default' => '',
			),
			array(
				'key'     => 'quiz_sponsor_logo',
				'type'    => 'image',
				'label'   => 'স্পনসরের লোগো',
				'default' => 0,
			),
			array(
				'key'     => 'lb_name',
				'type'    => 'select',
				'label'   => 'লিডারবোর্ডে নাম',
				'default' => 'first',
				'options' => array(
					'first' => 'শুধু নামের প্রথম অংশ',
					'full'  => 'পুরো প্রদর্শন-নাম',
				),
			),
			array(
				'key'     => 'rank_min',
				'type'    => 'number',
				'label'   => 'র‍্যাঙ্ক দেখানোর ন্যূনতম পরীক্ষার্থী',
				'default' => 5,
				'min'     => 2,
				'max'     => 500,
				'unit'    => 'জন',
			),
			array(
				'key'     => 'count_min',
				'type'    => 'number',
				'label'   => '"কতজন প্রস্তুতি নিচ্ছেন" দেখানোর ন্যূনতম সংখ্যা',
				'default' => 20,
				'min'     => 1,
				'max'     => 10000,
				'desc'    => 'এর কম হলে সংখ্যাটি লুকানো থাকে। সব সংখ্যা ডেটাবেস থেকে আসে, বানানো নয়।',
			),

			array(
				'type'  => 'heading',
				'label' => 'কুইজ পুরস্কার',
				'desc'  => 'ঐচ্ছিক। চালু করলে কুইজ ও লিডারবোর্ড পাতায় পুরস্কারের ঘোষণা দেখায়। সময়কাল শেষ হলে প্রস্তুতি → কুইজ পুরস্কার পাতা থেকে বিজয়ী ঘোষণা করুন। কুইজে অংশ নেওয়া ফ্রি, তাই এটি ফ্রি-এন্ট্রি প্রতিযোগিতা।',
			),
			array(
				'key'     => 'prize_on',
				'type'    => 'checkbox',
				'label'   => 'পুরস্কার',
				'text'    => 'দৈনিক কুইজে পুরস্কার চালু করুন',
				'default' => 0,
			),
			array(
				'key'     => 'prize_period',
				'type'    => 'select',
				'label'   => 'কত দিন পরপর',
				'default' => 'week',
				'options' => array(
					'day'   => 'প্রতিদিন',
					'week'  => 'প্রতি সপ্তাহ (শনি–শুক্র)',
					'month' => 'প্রতি মাস',
				),
			),
			array(
				'key'     => 'prize_winners',
				'type'    => 'number',
				'label'   => 'বিজয়ীর সংখ্যা',
				'default' => 3,
				'min'     => 1,
				'max'     => 20,
				'unit'    => 'জন',
			),
			array(
				'key'     => 'prize_min_days',
				'type'    => 'number',
				'label'   => 'ন্যূনতম খেলার দিন',
				'default' => 4,
				'min'     => 1,
				'max'     => 31,
				'unit'    => 'দিন (সাপ্তাহিক/মাসিক পুরস্কারে)',
			),
			array(
				'key'     => 'prize_text',
				'type'    => 'textarea',
				'label'   => 'পুরস্কার',
				'default' => '',
				'desc'    => 'পাঠকেরা যা দেখবেন, যেমন: ১ম: ৫০০ টাকার বই; ২য় ও ৩য়: মিহা প্রকাশনার ডিজিটাল সংখ্যা। স্পনসর থাকলে তার নামও লিখুন।',
			),
			array(
				'key'     => 'prize_rules',
				'type'    => 'textarea',
				'label'   => 'নিয়ম ও শর্ত',
				'default' => "১. অংশ নেওয়া সম্পূর্ণ ফ্রি; কিছু কিনতে হয় না।\n২. শুধু লগইন করা পাঠকের প্রথম উত্তর গোনা হয়। এক ব্যক্তির একাধিক অ্যাকাউন্ট ধরা পড়লে সব অ্যাকাউন্ট বাদ যাবে।\n৩. ফল: বেশি সঠিক উত্তর আগে; সমান হলে কম সময়, তারপর বেশি দিন খেলা।\n৪. মিহা নিউজের কর্মী ও তাঁদের সঙ্গে যুক্ত অ্যাকাউন্ট পুরস্কারের জন্য বিবেচিত হবে না।\n৫. বিজয়ীদের সঙ্গে অ্যাকাউন্টের ইমেইল বা ফোনে যোগাযোগ করা হবে; ৭ দিনের মধ্যে সাড়া না পেলে পরের জনকে দেওয়া হতে পারে।\n৬. অসদুপায়ের সন্দেহ হলে কর্তৃপক্ষ যাচাই করে যেকোনো অংশগ্রহণ বাতিল করতে পারবে।",
			),
			array(
				'key'     => 'prize_staff_out',
				'type'    => 'checkbox',
				'label'   => 'কর্মী',
				'text'    => 'লেখক, সম্পাদক ও অ্যাডমিন অ্যাকাউন্ট বিজয়ী তালিকা থেকে বাদ',
				'default' => 1,
			),

			array(
				'type'  => 'heading',
				'label' => 'পয়েন্ট ও সাপ্তাহিক চ্যালেঞ্জ',
				'desc'  => 'শিক্ষার্থীর ড্যাশবোর্ডে দেখায়। লক্ষ্য ০ দিলে সেই চ্যালেঞ্জ বন্ধ থাকে।',
			),
			array(
				'key'     => 'ch_quiz',
				'type'    => 'number',
				'label'   => 'চ্যালেঞ্জ: কুইজ',
				'default' => 5,
				'min'     => 0,
				'max'     => 7,
				'unit'    => 'দিন কুইজ শেষ (সপ্তাহে)',
			),
			array(
				'key'     => 'ch_test',
				'type'    => 'number',
				'label'   => 'চ্যালেঞ্জ: টেস্ট',
				'default' => 2,
				'min'     => 0,
				'max'     => 20,
				'unit'    => 'টি নতুন মডেল টেস্ট',
			),
			array(
				'key'     => 'ch_lesson',
				'type'    => 'number',
				'label'   => 'চ্যালেঞ্জ: পাঠ',
				'default' => 3,
				'min'     => 0,
				'max'     => 30,
				'unit'    => 'টি পাঠ (প্রকাশিত পাঠ থাকলে)',
			),
			array(
				'key'     => 'ch_fix',
				'type'    => 'number',
				'label'   => 'চ্যালেঞ্জ: ভুল শুধরানো',
				'default' => 10,
				'min'     => 0,
				'max'     => 100,
				'unit'    => 'টি ভুল প্রশ্ন',
			),
			array(
				'key'     => 'ch_bonus',
				'type'    => 'number',
				'label'   => 'প্রতি চ্যালেঞ্জের বোনাস',
				'default' => 20,
				'min'     => 0,
				'max'     => 500,
				'unit'    => 'পয়েন্ট',
			),
			array(
				'key'     => 'pt_lesson',
				'type'    => 'number',
				'label'   => 'পাঠ শেষ করলে',
				'default' => 5,
				'min'     => 0,
				'max'     => 50,
				'unit'    => 'পয়েন্ট',
			),

			array(
				'type'  => 'heading',
				'label' => 'প্রকাশনা: ডেলিভারি',
				'desc'  => 'প্রিন্ট সংস্করণের ডেলিভারি ফি আপনার কুরিয়ারের রেট অনুযায়ী বসান। ডিজিটাল সংস্করণে ডেলিভারি ফি নেই।',
			),
			array(
				'key'     => 'ship_dhaka',
				'type'    => 'number',
				'label'   => 'ঢাকা মহানগরে ডেলিভারি',
				'default' => 60,
				'min'     => 0,
				'max'     => 2000,
				'unit'    => 'টাকা',
				'cap'     => 'admin',
			),
			array(
				'key'     => 'ship_outside',
				'type'    => 'number',
				'label'   => 'ঢাকার বাইরে ডেলিভারি',
				'default' => 120,
				'min'     => 0,
				'max'     => 2000,
				'unit'    => 'টাকা',
				'cap'     => 'admin',
			),
			array(
				'key'     => 'ship_free_over',
				'type'    => 'number',
				'label'   => 'ফ্রি ডেলিভারি',
				'default' => 0,
				'min'     => 0,
				'max'     => 100000,
				'unit'    => 'টাকা বা বেশির বইয়ে (০ = বন্ধ)',
				'cap'     => 'admin',
			),
			array(
				'key'     => 'cod_on',
				'type'    => 'checkbox',
				'label'   => 'ক্যাশ অন ডেলিভারি',
				'text'    => 'প্রিন্ট বইয়ে "বই হাতে পেয়ে টাকা" অপশন দিন',
				'default' => 0,
				'cap'     => 'admin',
				'desc'    => 'চালু করলে প্রতি অর্ডার ফোনে নিশ্চিত করে পাঠান। একজন পাঠকের একসঙ্গে ৩টির বেশি খোলা অর্ডার থাকতে পারে না।',
			),
			array(
				'key'     => 'ship_note',
				'type'    => 'text',
				'label'   => 'ডেলিভারির নোট',
				'default' => '',
				'desc'    => 'প্রিন্ট সংস্করণের নিচে দেখায়, যেমন: অর্ডারের ২–৪ কর্মদিবসের মধ্যে কুরিয়ারে পাঠানো হয়।',
			),
			array(
				'key'     => 'pub_email',
				'type'    => 'email',
				'label'   => 'নতুন বইয়ের অর্ডারের ইমেইল',
				'default' => '',
				'desc'    => 'খালি = ওপরের "নতুন জমার খবর" ঠিকানা, সেটিও খালি হলে সাইটের অ্যাডমিন ইমেইল।',
			),

			array(
				'type'  => 'heading',
				'label' => 'অ্যালার্ট, জমা ও নোটিফিকেশন',
			),
			array(
				'key'     => 'mailpoet_list',
				'type'    => 'number',
				'label'   => 'জব অ্যালার্টের MailPoet তালিকা (ID)',
				'default' => 0,
				'min'     => 0,
				'desc'    => '"ইমেইলে পাব" চাপলে পাঠক এই তালিকায় যুক্ত হবেন। MailPoet › Lists-এ তালিকার ID দেখা যায়। ০ = বন্ধ।',
			),
			array(
				'key'     => 'reminder_on',
				'type'    => 'checkbox',
				'label'   => 'শেষ তারিখের রিমাইন্ডার',
				'text'    => 'সংরক্ষিত চাকরির শেষ তারিখের আগে ইমেইল পাঠান',
				'default' => 0,
			),
			array(
				'key'     => 'reminder_days',
				'type'    => 'number',
				'label'   => 'কত দিন আগে',
				'default' => 2,
				'min'     => 1,
				'max'     => 7,
				'unit'    => 'দিন',
			),
			array(
				'key'     => 'reminder_cap',
				'type'    => 'number',
				'label'   => 'প্রতিদিন সর্বোচ্চ ইমেইল',
				'default' => 100,
				'min'     => 10,
				'max'     => 2000,
				'desc'    => 'হোস্টিংয়ের দৈনিক ইমেইল সীমার নিচে রাখুন।',
			),
			array(
				'key'     => 'notify_email',
				'type'    => 'email',
				'label'   => 'নতুন জমার খবর যাবে',
				'default' => '',
				'desc'    => 'নিয়োগদাতার বিজ্ঞপ্তি ও পাঠকের গল্প জমা হলে এই ঠিকানায় ইমেইল যাবে। খালি = সাইটের অ্যাডমিন ইমেইল।',
			),
			array(
				'key'     => 'employer_upload',
				'type'    => 'checkbox',
				'label'   => 'নিয়োগদাতার সার্কুলার আপলোড',
				'text'    => 'ছবি বা পিডিএফ আপলোড করতে দিন (সর্বোচ্চ ৫ এমবি)',
				'default' => 1,
			),

			array(
				'type'  => 'heading',
				'label' => 'ডেটা',
			),
			array(
				'key'     => 'delete_on_uninstall',
				'type'    => 'checkbox',
				'label'   => 'প্লাগিন মুছলে',
				'text'    => 'সব টেবিল ও সেটিং মুছে ফেলুন (অর্ডারের হিসাবও যাবে)',
				'default' => 0,
				'cap'     => 'admin',
			),
		);
	}

	/**
	 * Register the tab (fires from meeha_settings_tabs()).
	 */
	public static function register() {
		if ( ! function_exists( 'meeha_register_settings_tab' ) ) {
			return;
		}
		meeha_register_settings_tab(
			'prep',
			array(
				'title'    => 'প্রস্তুতি ও পেমেন্ট',
				'order'    => 46,
				'cap'      => 'design',
				'desc'     => 'শিক্ষা ও ক্যাম্পাস সেকশনের টেস্ট, কুইজ, অ্যালার্ট ও অনলাইন পেমেন্টের সেটিং। সেকশনের নকশা ও পাতা: "শিক্ষা ও ক্যাম্পাস" ট্যাব।',
				'fields'   => self::fields(),
				'render'   => array( __CLASS__, 'render_extra' ),
				'sanitize' => array( __CLASS__, 'sanitize' ),
				'autoload' => false,
			)
		);
	}

	/**
	 * Password + callback URLs (after the field table, inside the same form).
	 *
	 * @param string $tab    Tab id.
	 * @param array  $values Values.
	 * @param array  $ctx    Context.
	 */
	public static function render_extra( $tab, $values, $ctx ) {
		$saved = ! empty( $values['ssl_store_pass'] );
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="mhp-pass">Store Password</label></th><td>';
		if ( current_user_can( 'manage_options' ) ) {
			echo '<input type="password" class="regular-text" id="mhp-pass" name="' . esc_attr( $ctx['name'] ) . '[ssl_store_pass]" value="" autocomplete="new-password" placeholder="' . esc_attr( $saved ? '•••••••• (সংরক্ষিত আছে)' : '' ) . '">';
			echo '<p class="description">' . ( $saved ? 'সংরক্ষিত পাসওয়ার্ড দেখানো হয় না। বদলাতে চাইলে নতুনটি লিখুন, নইলে খালি রাখুন।' : 'SSLCommerz মার্চেন্ট প্যানেল বা ডোনেশন প্লাগিনের সেটিং থেকে নিন।' ) . '</p>';
			if ( $saved ) {
				echo '<label><input type="checkbox" name="' . esc_attr( $ctx['name'] ) . '[ssl_pass_clear]" value="1"> পাসওয়ার্ড মুছে ফেলুন</label>';
			}
		} else {
			echo '<p class="description"><span class="dashicons dashicons-lock"></span> শুধু প্রশাসক দেখতে ও বদলাতে পারবেন।</p>';
		}
		echo '</td></tr>';
		echo '<tr><th scope="row">গেটওয়ের ঠিকানাগুলো</th><td>';
		echo '<p class="description">প্রতিটি লেনদেনে প্লাগিন নিজেই এই ঠিকানাগুলো SSLCommerz-কে পাঠায়, তাই মার্চেন্ট প্যানেলে ডোনেশনের IPN সেটিং বদলাতে হবে না।</p>';
		foreach ( array(
			'IPN'     => MHP_Checkout::callback_url( 'ipn' ),
			'Success' => MHP_Checkout::callback_url( 'success' ),
			'Fail'    => MHP_Checkout::callback_url( 'fail' ),
			'Cancel'  => MHP_Checkout::callback_url( 'cancel' ),
		) as $label => $u ) {
			echo '<div><code>' . esc_html( $label ) . '</code> <code>' . esc_html( $u ) . '</code></div>';
		}
		echo '</td></tr>';
		$ready = MHP_Gateway_SSLCommerz::configured();
		$cr    = MHP_Gateway_SSLCommerz::creds();
		echo '<tr><th scope="row">অবস্থা</th><td>';
		if ( $ready ) {
			echo '<p><span class="dashicons dashicons-yes-alt" style="color:#1B7A4C"></span> ' . esc_html( 'donation' === $cr['source'] ? 'মিহা ডোনেশন প্লাগিনের স্টোর ব্যবহার হচ্ছে' : 'আলাদা স্টোর ব্যবহার হচ্ছে' ) . ' · মোড: <strong>' . esc_html( 'live' === $cr['mode'] ? 'লাইভ' : 'স্যান্ডবক্স' ) . '</strong></p>';
		} else {
			echo '<p><span class="dashicons dashicons-warning" style="color:#B57A06"></span> Store ID বা পাসওয়ার্ড নেই, তাই পেমেন্ট চালু হবে না।</p>';
		}
		echo '</td></tr>';
		echo '</tbody></table>';
	}

	/**
	 * Tab sanitize: keep the password unless a new one is typed.
	 *
	 * @param array $clean Clean values.
	 * @param array $raw   Raw.
	 * @param array $old   Old.
	 * @return array
	 */
	public static function sanitize( $clean, $raw, $old ) {
		$clean['ssl_store_pass'] = isset( $old['ssl_store_pass'] ) ? (string) $old['ssl_store_pass'] : '';
		if ( current_user_can( 'manage_options' ) ) {
			if ( ! empty( $raw['ssl_pass_clear'] ) ) {
				$clean['ssl_store_pass'] = '';
			} elseif ( isset( $raw['ssl_store_pass'] ) && '' !== trim( (string) $raw['ssl_store_pass'] ) ) {
				// Store passwords are ASCII tokens like "abc123@ssl"; keep them byte-exact (no HTML stripping needed).
				$clean['ssl_store_pass'] = preg_replace( '/[^\x21-\x7E]/', '', (string) $raw['ssl_store_pass'] );
			}
		}
		if ( isset( $clean['ssl_store_id'] ) ) {
			$clean['ssl_store_id'] = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $clean['ssl_store_id'] );
		}
		$own_missing = empty( $clean['ssl_store_id'] ) || '' === $clean['ssl_store_pass'];
		$use_don     = 'own' !== ( isset( $clean['cred_source'] ) ? $clean['cred_source'] : 'donation' ) && MHP_Gateway_SSLCommerz::donation_creds_exist();
		if ( ! empty( $clean['pay_on'] ) && ! $use_don && $own_missing ) {
			$clean['pay_on'] = 0;
			if ( function_exists( 'meeha_settings_notice' ) ) {
				meeha_settings_notice( 'Store ID ও পাসওয়ার্ড না থাকায় পেমেন্ট চালু করা যায়নি।', 'warning' );
			}
		}
		return $clean;
	}

	/**
	 * Warn when the Meeha theme (and its settings framework) is not active.
	 */
	public static function theme_notice() {
		if ( function_exists( 'meeha_register_settings_tab' ) || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p><strong>মিহা প্রস্তুতি:</strong> এই প্লাগিন "Meeha News" থিমের সঙ্গে কাজ করে (সেটিং ও নকশা থিমে)। থিমটি সক্রিয় করুন।</p></div>';
	}
}
