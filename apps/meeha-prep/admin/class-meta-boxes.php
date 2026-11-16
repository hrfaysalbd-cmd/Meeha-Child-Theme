<?php
/**
 * Edit screens: meta boxes for jobs, questions, tests, products and events, plus track term fields.
 * Every save checks the box nonce and edit_post; prices and featured dates also need mhp_manage_orders.
 *
 * @package meeha-prep
 */

defined( 'ABSPATH' ) || exit;

/**
 * Meta boxes.
 */
class MHP_Meta_Boxes {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add' ) );
		add_action( 'save_post', array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'post_edit_form_tag', array( __CLASS__, 'form_tag' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_mhp_find_questions', array( __CLASS__, 'find_questions' ) );
		add_action( 'mh_track_add_form_fields', array( __CLASS__, 'track_add' ) );
		add_action( 'mh_track_edit_form_fields', array( __CLASS__, 'track_edit' ) );
		add_action( 'created_mh_track', array( __CLASS__, 'track_save' ) );
		add_action( 'edited_mh_track', array( __CLASS__, 'track_save' ) );
		add_filter( 'enter_title_here', array( __CLASS__, 'title_hint' ), 10, 2 );
	}

	/**
	 * Field sets per post type.
	 *
	 * @param string $type Post type.
	 * @return array
	 */
	public static function fields( $type ) {
		$tracks = array( 0 => '—' );
		foreach ( mhp_tracks() as $t ) {
			$tracks[ $t['id'] ] = $t['name'];
		}
		switch ( $type ) {
			case 'mh_job':
				return array(
					'org'            => array( 'প্রতিষ্ঠান *', 'text' ),
					'vacancies'      => array( 'পদসংখ্যা', 'number' ),
					'deadline'       => array( 'আবেদনের শেষ তারিখ *', 'date', 'এই তারিখ পেরোলে বিজ্ঞপ্তি তালিকা থেকে নিজে থেকেই সরে যায়।' ),
					'apply_start'    => array( 'আবেদন শুরু', 'date' ),
					'education'      => array( 'শিক্ষাগত যোগ্যতা', 'text', 'ফিল্টারের জন্য শব্দগুলো লিখুন: এসএসসি, এইচএসসি, ডিপ্লোমা, স্নাতক, স্নাতকোত্তর।' ),
					'age'            => array( 'বয়স/অভিজ্ঞতা', 'text' ),
					'location'       => array( 'কর্মস্থল', 'text', 'জেলার নাম, বা "সারা দেশ"।' ),
					'grade'          => array( 'বেতন গ্রেড', 'text' ),
					'salary'         => array( 'বেতন', 'text' ),
					'fee'            => array( 'আবেদন ফি', 'text' ),
					'apply_url'      => array( 'অফিসিয়াল আবেদনের লিংক', 'url' ),
					'source'         => array( 'সূত্র', 'text', 'যেমন: প্রতিষ্ঠানের ওয়েবসাইট, দৈনিক পত্রিকার নাম ও তারিখ।' ),
					'source_url'     => array( 'সূত্রের লিংক', 'url' ),
					'circular_id'    => array( 'মূল সার্কুলার (ছবি/পিডিএফ)', 'media' ),
					'steps'          => array( 'আবেদনের ধাপ (প্রতি লাইনে একটি)', 'textarea' ),
					'track'          => array( 'সংশ্লিষ্ট প্রস্তুতি ট্র্যাক', 'select', '', $tracks ),
					'news_post'      => array( 'সংশ্লিষ্ট খবরের আইডি', 'number', 'পোস্টের আইডি (সম্পাদনা পাতার ঠিকানায় post=…)।' ),
					'featured_until' => array( 'ফিচার্ড থাকবে (তারিখ পর্যন্ত)', 'date', 'পেইড ফিচার্ড বিজ্ঞপ্তির জন্য। শুধু অর্ডার ব্যবস্থাপকেরা বদলাতে পারেন।', null, true ),
				);
			case 'mh_test':
				return array(
					'price'      => array( 'মূল্য (টাকা)', 'money', '০ রাখলে ফ্রি।', null, true ),
					'duration'   => array( 'সময় (মিনিট) *', 'number' ),
					'negative'   => array( 'প্রতি ভুলে কাটা নম্বর', 'decimal', 'যেমন ০.৫ বা ০.২৫; না থাকলে ০।' ),
					'live_start' => array( 'লাইভ শুরু', 'datetime', 'খালি রাখলে সাধারণ টেস্ট। লাইভ টেস্ট শুধু এই সময়ের মধ্যে র‍্যাংকে গোনা হয়।' ),
					'live_end'   => array( 'লাইভ শেষ', 'datetime' ),
					'note'       => array( 'বিশেষ নির্দেশনা', 'text' ),
				);
			case 'mh_product':
				return array(
					'ptype' => array( 'ধরন', 'select', '', array( 'bundle' => 'টেস্ট প্যাক', 'download' => 'ডাউনলোড (পিডিএফ/বই)', 'service' => 'সেবা (যেমন সিভি রিভিউ)' ) ),
					'price' => array( 'মূল্য (টাকা) *', 'money', '', null, true ),
				);
			case 'mh_event':
				return array(
					'date'         => array( 'তারিখ *', 'date' ),
					'time'         => array( 'সময়', 'text', 'যেমন: বিকেল ৩টা–৫টা' ),
					'venue'        => array( 'স্থান', 'text' ),
					'online_url'   => array( 'অনলাইন লিংক', 'url', 'শুধু নিবন্ধিতরা টিকিটের পাতায় দেখবেন।' ),
					'price'        => array( 'ফি (টাকা)', 'money', '০ রাখলে ফ্রি নিবন্ধন।', null, true ),
					'capacity'     => array( 'আসন', 'number', '০ = সীমা নেই' ),
					'reg_open'     => array( 'নিবন্ধন খোলা', 'checkbox' ),
					'sponsor_note' => array( 'স্পনসর/আয়োজক লাইন', 'text' ),
				);
			case 'mh_consultant':
				return array(
					'role'         => array( 'পরিচয় লাইন *', 'text', 'যেমন: এইচআর বিশেষজ্ঞ, ১২ বছর ব্যাংকে নিয়োগের অভিজ্ঞতা।' ),
					'expertise'    => array( 'বিশেষ ক্ষেত্র', 'text', 'কমা দিয়ে আলাদা করুন: ব্যাংক, বিসিএস ভাইভা, সিভি রিভিউ।' ),
					'topics'       => array( 'সেবা/বিষয় (প্রতি লাইনে একটি)', 'textarea', 'বুকিং ফর্মে পাঠক এখান থেকে বিষয় বাছবেন।' ),
					'price'        => array( 'ফি (টাকা)', 'money', '০ রাখলে ফ্রি সেশন।', null, true ),
					'minutes'      => array( 'সেশনের সময় (মিনিট)', 'number' ),
					'mode'         => array( 'মাধ্যম', 'select', '', MHP_Consult::modes() ),
					'availability' => array( 'সাধারণত সময় দেন', 'text', 'যেমন: শুক্র ও শনিবার, সন্ধ্যা ৭টা–১০টা।' ),
					'accepting'    => array( 'বুকিং নিচ্ছেন', 'checkbox' ),
					'email'        => array( 'নতুন অনুরোধের ইমেইল (ঐচ্ছিক)', 'email', 'পাঠকেরা দেখবেন না। খালি রাখলে শুধু ডেস্কের ঠিকানায় যাবে।' ),
				);
			case 'mh_lesson':
				$packs = array( 0 => 'কোনোটি নয় (ফ্রি পাঠ)' );
				foreach ( get_posts( array( 'post_type' => 'mh_product', 'post_status' => array( 'publish', 'draft' ), 'posts_per_page' => 200, 'orderby' => 'title', 'order' => 'ASC' ) ) as $pk ) { // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing
					$packs[ $pk->ID ] = get_the_title( $pk ) . ( (float) mhp_meta( $pk->ID, 'price', 0 ) > 0 ? ' (' . mhp_tk( mhp_meta( $pk->ID, 'price', 0 ) ) . ')' : '' );
				}
				return array(
					'video'        => array( 'ভিডিও লিংক', 'url', 'YouTube বা Vimeo-র লিংক (যেমন https://youtu.be/…)। অন্য সাইটের লিংক দেখাবে না।' ),
					'minutes'      => array( 'পড়ার সময় (মিনিট)', 'number', 'খালি রাখলে লেখার দৈর্ঘ্য থেকে হিসাব হয়।' ),
					'feature_date' => array( 'আজকের নির্বাচিত পাঠ', 'date', 'এই তারিখে "আজকের নির্বাচিত পাঠ" হিসেবে সবাই এটি দেখবেন। কোনো দিনের জন্য বাছাই না থাকলে ফ্রি পাঠ থেকে নিজে থেকে একটি আসে।' ),
					'product'      => array( 'যে প্যাক কিনলে খুলবে', 'select', 'বেছে নিলে পাঠটি শুধু ওই প্যাকের ক্রেতারা পুরোটা পড়তে পারবেন (দাম থাকা প্রকাশিত প্যাক হতে হবে)। শুধু অর্ডার ব্যবস্থাপকেরা বদলাতে পারেন।', $packs, true ),
				);
			case 'mh_pub':
				return array(
					'kind'          => array( 'ধরন', 'select', '', MHP_Pubs::kinds() ),
					'issue'         => array( 'সংখ্যা/সংস্করণ', 'text', 'যেমন: অক্টোবর ২০২৬ সংখ্যা, বা ২০২৬ সংস্করণ।' ),
					'author'        => array( 'লেখক/সম্পাদক', 'text' ),
					'pages'         => array( 'পৃষ্ঠা', 'number' ),
					'sample_id'     => array( 'নমুনা পাতা (সবার জন্য)', 'media', 'কয়েক পাতার পিডিএফ বা ছবি। এটি মিডিয়া লাইব্রেরিতে থাকে, তাই পুরো বই এখানে দেবেন না।' ),
					'digital_on'    => array( 'ডিজিটাল সংস্করণ বিক্রি', 'checkbox', 'নিচে সুরক্ষিত ফাইল দিলে তবেই দেখাবে।', null, true ),
					'digital_price' => array( 'ডিজিটাল মূল্য (টাকা)', 'money', '০ = লগইন করা পাঠকের জন্য ফ্রি ডাউনলোড।', null, true ),
					'print_on'      => array( 'প্রিন্ট সংস্করণ বিক্রি', 'checkbox', 'কুরিয়ারে ডেলিভারি; ফি সেটিং › প্রস্তুতি ও পেমেন্ট › প্রকাশনা।', null, true ),
					'print_price'   => array( 'প্রিন্ট মূল্য (টাকা, প্রতি কপি)', 'money', '', null, true ),
					'stock_on'      => array( 'স্টক গুনে রাখুন', 'checkbox', 'চালু থাকলে স্টক শেষ হলে অর্ডার বন্ধ হয়। বন্ধ থাকলে (যেমন প্রিন্ট-অন-ডিমান্ড) সীমা নেই।', null, true ),
					'stock'         => array( 'স্টক (কপি)', 'number', 'অর্ডার পরিশোধ বা ক্যাশ অন ডেলিভারিতে কমে, বাতিল/ফেরতে বাড়ে।', null, true ),
				);
			case 'mh_cv_tpl':
				return array(
					'price' => array( 'মূল্য (টাকা)', 'money', '০ = ফ্রি (ফ্রি টেমপ্লেট সব সময় এক পাতা, পাবলিক শেয়ার নেই)।', null, true ),
					'pages' => array( 'সর্বোচ্চ পাতা', 'select', 'পেইড টেমপ্লেটে প্রযোজ্য।', array( '1' => '১', '2' => '২', '3' => '৩' ), true ),
				);
		}
		return array();
	}

	/**
	 * Register boxes.
	 */
	public static function add() {
		add_meta_box( 'mhp_job', 'বিজ্ঞপ্তির তথ্য', array( __CLASS__, 'box_fields' ), 'mh_job', 'normal', 'high' );
		add_meta_box( 'mhp_job_contact', 'নিয়োগদাতার যোগাযোগ', array( __CLASS__, 'box_contact' ), 'mh_job', 'side' );
		add_meta_box( 'mhp_question', 'প্রশ্ন, অপশন ও উত্তর', array( __CLASS__, 'box_question' ), 'mh_question', 'normal', 'high' );
		add_meta_box( 'mhp_test', 'টেস্টের নিয়ম', array( __CLASS__, 'box_fields' ), 'mh_test', 'normal', 'high' );
		add_meta_box( 'mhp_test_qs', 'টেস্টের প্রশ্ন', array( __CLASS__, 'box_test_questions' ), 'mh_test', 'normal', 'high' );
		add_meta_box( 'mhp_product', 'প্যাক/পণ্যের তথ্য', array( __CLASS__, 'box_product' ), 'mh_product', 'normal', 'high' );
		add_meta_box( 'mhp_event', 'ইভেন্টের তথ্য', array( __CLASS__, 'box_fields' ), 'mh_event', 'normal', 'high' );
		add_meta_box( 'mhp_event_people', 'নিবন্ধিত', array( __CLASS__, 'box_event_people' ), 'mh_event', 'side' );
		add_meta_box( 'mhp_consultant', 'কনসাল্টেন্টের তথ্য', array( __CLASS__, 'box_fields' ), 'mh_consultant', 'normal', 'high' );
		add_meta_box( 'mhp_lesson', 'পাঠের তথ্য', array( __CLASS__, 'box_fields' ), 'mh_lesson', 'normal', 'high' );
		add_meta_box( 'mhp_lesson_qs', 'অনুশীলনের প্রশ্ন (পাঠের শেষে, উত্তরসহ)', array( __CLASS__, 'box_test_questions' ), 'mh_lesson', 'normal' );
		add_meta_box( 'mhp_pub', 'প্রকাশনার তথ্য ও সংস্করণ', array( __CLASS__, 'box_pub' ), 'mh_pub', 'normal', 'high' );
		add_meta_box( 'mhp_cv_tpl', 'মূল্য ও পাতা', array( __CLASS__, 'box_fields' ), 'mh_cv_tpl', 'side' );
		add_meta_box( 'mhp_cv_code', 'টেমপ্লেটের HTML ও CSS', array( __CLASS__, 'box_cv_code' ), 'mh_cv_tpl', 'normal', 'high' );
	}

	/**
	 * CV design code (sanitized on save by MHP_CV::clean_html / clean_css).
	 *
	 * @param WP_Post $post Post.
	 */
	public static function box_cv_code( $post ) {
		$key = (string) get_post_meta( $post->ID, '_mhp_tpl_key', true );
		echo '<p class="description">ভাষা ও নিয়ম: প্রস্তুতি → সিভি টেমপ্লেট ইমপোর্ট পাতার নির্দেশিকা দেখুন। স্ক্রিপ্ট, বাইরের লিংক বা ছবি (data: ছাড়া) সংরক্ষণের সময় মুছে যায়।</p>';
		echo '<p><label for="mhp_tpl_key"><b>key</b></label> <input class="regular-text code" id="mhp_tpl_key" name="mhp[tpl_key]" value="' . esc_attr( $key ) . '" pattern="[a-z0-9_\\-]+"> <span class="description">ইমপোর্ট/এক্সপোর্টে টেমপ্লেট চেনার নাম (ইংরেজি ছোট হাতের অক্ষর, সংখ্যা, -)।</span></p>';
		echo '<p><label for="mhp_html"><b>HTML</b></label><textarea class="large-text code" rows="14" id="mhp_html" name="mhp[html]" spellcheck="false">' . esc_textarea( (string) get_post_meta( $post->ID, '_mhp_html', true ) ) . '</textarea></p>';
		echo '<p><label for="mhp_css"><b>CSS</b></label><textarea class="large-text code" rows="14" id="mhp_css" name="mhp[css]" spellcheck="false">' . esc_textarea( (string) get_post_meta( $post->ID, '_mhp_css', true ) ) . '</textarea></p>';
		if ( 'auto-draft' !== $post->post_status ) {
			echo '<p><a class="button" target="_blank" rel="noopener" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mhp_cv_preview&id=' . (int) $post->ID ), 'mhp_cv_preview_' . (int) $post->ID ) ) . '">নমুনা তথ্যে প্রিভিউ (সংরক্ষিত সংস্করণ)</a> ';
			echo '<a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mhp_cv_export&id=' . (int) $post->ID ), 'mhp_cv_export' ) ) . '">JSON এক্সপোর্ট</a></p>';
		}
	}

	/**
	 * Upload support for product files.
	 *
	 * @param WP_Post $post Post.
	 */
	public static function form_tag( $post ) {
		if ( in_array( $post->post_type, array( 'mh_product', 'mh_pub' ), true ) ) {
			echo ' enctype="multipart/form-data"';
		}
	}

	/**
	 * Admin JS/CSS on our edit screens.
	 *
	 * @param string $hook Hook.
	 */
	public static function assets( $hook ) {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->post_type, array( 'mh_job', 'mh_test', 'mh_product', 'mh_event', 'mh_question', 'mh_consultant', 'mh_cv_tpl', 'mh_lesson', 'mh_pub' ), true ) ) {
			return;
		}
		if ( 'post.php' === $hook || 'post-new.php' === $hook ) {
			wp_enqueue_media();
		}
		wp_enqueue_script( 'mhp-admin', MHP_URI . '/assets/js/admin.js', array( 'jquery' ), MHP_VERSION, true );
		wp_localize_script(
			'mhp-admin',
			'MHPA',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'mhp_find_questions' ),
			)
		);
		wp_enqueue_style( 'mhp-admin', MHP_URI . '/assets/css/admin.css', array(), MHP_VERSION );
	}

	/**
	 * Title placeholder.
	 *
	 * @param string  $text Text.
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public static function title_hint( $text, $post ) {
		$map = array(
			'mh_job'      => 'পদের নাম (যেমন: সহকারী শিক্ষক)',
			'mh_question' => 'প্রশ্নটি এখানে লিখুন',
			'mh_test'     => 'টেস্টের নাম (যেমন: বিসিএস মডেল টেস্ট ২)',
			'mh_product'  => 'প্যাক/বইয়ের নাম',
			'mh_event'    => 'ইভেন্টের নাম',
			'mh_consultant' => 'কনসাল্টেন্টের পুরো নাম',
			'mh_cv_tpl'     => 'টেমপ্লেটের নাম',
			'mh_lesson'     => 'পাঠের শিরোনাম',
			'mh_pub'        => 'বই/সাময়িকীর নাম',
		);
		return isset( $map[ $post->post_type ] ) ? $map[ $post->post_type ] : $text;
	}

	/**
	 * Generic field table.
	 *
	 * @param WP_Post $post Post.
	 */
	public static function box_fields( $post ) {
		wp_nonce_field( 'mhp_meta_' . $post->post_type, '_mhp_meta_nonce' );
		self::render_fields( $post, self::fields( $post->post_type ) );
	}

	/**
	 * Render a set of fields.
	 *
	 * @param WP_Post $post   Post.
	 * @param array   $fields Fields.
	 */
	private static function render_fields( $post, $fields ) {
		echo '<table class="form-table mhp-fields"><tbody>';
		foreach ( $fields as $key => $f ) {
			$label = $f[0];
			$type  = $f[1];
			$desc  = isset( $f[2] ) ? $f[2] : '';
			$opts  = isset( $f[3] ) ? $f[3] : array();
			$shop  = ! empty( $f[4] );
			$val   = get_post_meta( $post->ID, '_mhp_' . $key, true );
			if ( in_array( $key, array( 'reg_open', 'accepting' ), true ) && '' === $val ) {
				$val = 1;
			}
			if ( 'minutes' === $key && '' === $val ) {
				$val = 30;
			}
			$id  = 'mhp_' . $key;
			$ro  = $shop && ! mhp_can_shop() ? ' disabled' : '';
			$nm  = 'mhp[' . $key . ']';
			echo '<tr><th><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
			switch ( $type ) {
				case 'textarea':
					echo '<textarea class="large-text" rows="4" id="' . esc_attr( $id ) . '" name="' . esc_attr( $nm ) . '"' . $ro . '>' . esc_textarea( (string) $val ) . '</textarea>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					break;
				case 'select':
					echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $nm ) . '"' . $ro . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					foreach ( $opts as $k => $l ) {
						echo '<option value="' . esc_attr( $k ) . '"' . selected( (string) $val, (string) $k, false ) . '>' . esc_html( $l ) . '</option>';
					}
					echo '</select>';
					break;
				case 'checkbox':
					echo '<label><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $nm ) . '" value="1"' . checked( (int) $val, 1, false ) . $ro . '> হ্যাঁ</label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					break;
				case 'media':
					$url = $val ? wp_get_attachment_url( (int) $val ) : '';
					echo '<div class="mhp-media" data-mhp-media>';
					echo '<input type="hidden" id="' . esc_attr( $id ) . '" name="' . esc_attr( $nm ) . '" value="' . esc_attr( (string) (int) $val ) . '">';
					echo '<span class="mhp-media__name">' . ( $url ? '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( basename( $url ) ) . '</a>' : 'কিছু বাছাই করা হয়নি' ) . '</span> ';
					echo '<button type="button" class="button" data-mhp-media-pick>বাছাই/আপলোড</button> <button type="button" class="button-link-delete" data-mhp-media-clear>সরান</button>';
					echo '</div>';
					break;
				default:
					$html_type = array(
						'number'   => 'number',
						'money'    => 'number',
						'decimal'  => 'number',
						'date'     => 'date',
						'datetime' => 'datetime-local',
						'url'      => 'url',
						'email'    => 'email',
					);
					$t    = isset( $html_type[ $type ] ) ? $html_type[ $type ] : 'text';
					$step = 'money' === $type ? ' step="1" min="0"' : ( 'decimal' === $type ? ' step="0.05" min="0" max="4"' : ( 'number' === $type ? ' step="1" min="0"' : '' ) );
					if ( 'datetime' === $type && $val ) {
						$val = str_replace( ' ', 'T', substr( (string) $val, 0, 16 ) );
					}
					echo '<input class="' . ( in_array( $type, array( 'number', 'money', 'decimal' ), true ) ? 'small-text' : 'regular-text' ) . '" type="' . esc_attr( $t ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $nm ) . '" value="' . esc_attr( (string) $val ) . '"' . $step . $ro . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			if ( $desc ) {
				echo '<p class="description">' . esc_html( $desc ) . '</p>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Save a CV design's key, HTML and CSS. CSS that tries to load outside resources is refused (old CSS kept).
	 *
	 * @param int   $post_id Post.
	 * @param array $in      Posted fields.
	 */
	private static function save_cv_code( $post_id, $in ) {
		if ( ! mhp_can_manage() ) {
			return;
		}
		$key = isset( $in['tpl_key'] ) ? substr( sanitize_key( (string) $in['tpl_key'] ), 0, 40 ) : '';
		if ( '' === $key ) {
			$key = 'tpl-' . (int) $post_id;
		}
		$other = MHP_CV::id_by_key( $key );
		if ( $other && $other !== (int) $post_id ) {
			$key .= '-' . (int) $post_id;
		}
		update_post_meta( $post_id, '_mhp_tpl_key', $key );
		if ( isset( $in['html'] ) && is_string( $in['html'] ) && strlen( $in['html'] ) <= 80000 ) {
			update_post_meta( $post_id, '_mhp_html', wp_slash( MHP_CV::clean_html( $in['html'] ) ) );
		}
		if ( isset( $in['css'] ) && is_string( $in['css'] ) && strlen( $in['css'] ) <= 80000 ) {
			$css = MHP_CV::clean_css( $in['css'] );
			if ( is_wp_error( $css ) ) {
				set_transient( 'mhp_cv_css_err_' . get_current_user_id(), $css->get_error_message(), 120 );
			} else {
				update_post_meta( $post_id, '_mhp_css', wp_slash( $css ) );
			}
		}
	}

	/**
	 * Employer contact (read only).
	 *
	 * @param WP_Post $post Post.
	 */
	public static function box_contact( $post ) {
		$name  = (string) get_post_meta( $post->ID, '_mhp_contact_name', true );
		$phone = (string) get_post_meta( $post->ID, '_mhp_contact_phone', true );
		$email = (string) get_post_meta( $post->ID, '_mhp_contact_email', true );
		$pkg   = (string) get_post_meta( $post->ID, '_mhp_package', true );
		if ( ! $phone && ! $email ) {
			echo '<p class="description">সম্পাদকের যোগ করা বিজ্ঞপ্তি।</p>';
			return;
		}
		echo '<p>' . esc_html( $name ) . '<br>' . esc_html( $phone ) . '<br>' . ( $email ? '<a href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a>' : '' ) . '</p>';
		if ( $pkg ) {
			echo '<p>প্যাকেজ: <b>' . esc_html( $pkg ) . '</b></p>';
		}
		echo '<p class="description">প্রকাশের আগে প্রতিষ্ঠান ও সার্কুলার যাচাই করুন।</p>';
	}

	/**
	 * Question box.
	 *
	 * @param WP_Post $post Post.
	 */
	public static function box_question( $post ) {
		wp_nonce_field( 'mhp_meta_mh_question', '_mhp_meta_nonce' );
		$opts = (array) get_post_meta( $post->ID, '_mhp_opts', true );
		$ans  = get_post_meta( $post->ID, '_mhp_answer', true );
		$ans  = '' === $ans ? 0 : (int) $ans;
		echo '<p class="description">প্রশ্নটি ওপরের শিরোনামের ঘরে লিখুন। ডান পাশে বিষয় ও ট্র্যাক বেছে নিন।</p>';
		echo '<table class="form-table"><tbody>';
		foreach ( MHP_Tests::OPT_LABELS as $i => $l ) {
			echo '<tr><th><label><input type="radio" name="mhp[answer]" value="' . (int) $i . '"' . checked( $ans, $i, false ) . '> ' . esc_html( $l ) . ' (সঠিক হলে চিহ্ন দিন)</label></th>';
			echo '<td><input class="large-text" type="text" name="mhp[opts][' . (int) $i . ']" value="' . esc_attr( isset( $opts[ $i ] ) ? (string) $opts[ $i ] : '' ) . '"' . ( $i < 2 ? ' required' : '' ) . '></td></tr>';
		}
		echo '<tr><th><label for="mhp_explain">ব্যাখ্যা</label></th><td><textarea id="mhp_explain" class="large-text" rows="3" name="mhp[explain]">' . esc_textarea( (string) get_post_meta( $post->ID, '_mhp_explain', true ) ) . '</textarea></td></tr>';
		echo '<tr><th><label for="mhp_source">উৎস</label></th><td><input id="mhp_source" class="regular-text" type="text" name="mhp[source]" value="' . esc_attr( (string) get_post_meta( $post->ID, '_mhp_source', true ) ) . '"><p class="description">যেমন: ৪৪তম বিসিএস প্রিলিমিনারি, বা পাঠ্যবইয়ের নাম ও শ্রেণি।</p></td></tr>';
		echo '<tr><th>দৈনিক কুইজ</th><td><label><input type="checkbox" name="mhp[quiz_ok]" value="1"' . checked( (int) get_post_meta( $post->ID, '_mhp_quiz_ok', true ), 1, false ) . '> দৈনিক কুইজে ব্যবহারযোগ্য</label></td></tr>';
		$n = (int) get_post_meta( $post->ID, '_mhp_answered', true );
		if ( $n ) {
			$c = (int) get_post_meta( $post->ID, '_mhp_correct', true );
			echo '<tr><th>পরিসংখ্যান</th><td>' . esc_html( mhp_num( $n ) . ' বার উত্তর, সঠিক ' . mhp_bn( round( $c / $n * 100 ) ) . '%' ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Test questions: ordered list + search to add + auto-fill.
	 *
	 * @param WP_Post $post Post.
	 */
	public static function box_test_questions( $post ) {
		$ids  = array_map( 'intval', (array) get_post_meta( $post->ID, '_mhp_questions', true ) );
		$ids  = array_values( array_filter( $ids ) );
		$subs = get_terms(
			array(
				'taxonomy'   => 'mh_subject',
				'hide_empty' => false,
			)
		);
		if ( 'mh_lesson' === $post->post_type ) {
			echo '<p class="description">পাঠের শেষে উত্তর ও ব্যাখ্যাসহ দেখাবে (সর্বোচ্চ ৩০টি)। পেইড বা লাইভ টেস্টের প্রশ্ন আর আজকের কুইজের প্রশ্ন এখানে যোগ করলেও দেখানো হবে না, যাতে সেগুলোর উত্তর ফাঁস না হয়।</p>';
		} else {
			echo '<p class="description">প্রশ্নগুলো এই ক্রমে দেখাবে। খুঁজে যোগ করুন, অথবা নিচে "স্বয়ংক্রিয়ভাবে যোগ করুন" ব্যবহার করুন। প্রকাশিত নয় এমন প্রশ্ন টেস্টে দেখাবে না।</p>';
		}
		echo '<ol class="mhp-qlist" data-mhp-qlist>';
		foreach ( $ids as $qid ) {
			$st = get_post_status( $qid );
			echo '<li data-id="' . (int) $qid . '"><input type="hidden" name="mhp_questions[]" value="' . (int) $qid . '"><span class="mhp-qlist__t">' . esc_html( wp_trim_words( get_the_title( $qid ), 16 ) ) . '</span> <span class="description">#' . (int) $qid . ( 'publish' !== $st ? ' · ' . esc_html( (string) $st ) : '' ) . '</span> <button type="button" class="button-link" data-mhp-up aria-label="ওপরে">↑</button> <button type="button" class="button-link" data-mhp-down aria-label="নিচে">↓</button> <label><input type="checkbox" name="mhp_remove[]" value="' . (int) $qid . '" data-mhp-remove> সরান</label></li>';
		}
		echo '</ol>';
		echo '<p><input type="search" class="regular-text" placeholder="প্রশ্ন খুঁজুন (কয়েকটি শব্দ লিখুন)" data-mhp-qsearch> <span class="description" data-mhp-qcount>' . esc_html( mhp_bn( count( $ids ) ) ) . 'টি প্রশ্ন</span></p>';
		echo '<ul class="mhp-qresults" data-mhp-qresults></ul>';
		echo '<p><b>স্বয়ংক্রিয়ভাবে যোগ করুন:</b> <input type="number" min="0" max="200" class="small-text" name="mhp_auto[count]" value="0"> টি প্রশ্ন, বিষয় <select name="mhp_auto[subject]"><option value="0">সব বিষয়</option>';
		if ( $subs && ! is_wp_error( $subs ) ) {
			foreach ( $subs as $s ) {
				echo '<option value="' . (int) $s->term_id . '">' . esc_html( $s->name ) . '</option>';
			}
		}
		echo '</select> <span class="description">(এর ট্র্যাকের প্রকাশিত প্রশ্ন থেকে, যা আগে থেকে তালিকায় নেই; সংরক্ষণের সময় যোগ হবে)</span></p>';
	}

	/**
	 * Product box (type, price, tests in bundle, protected file).
	 *
	 * @param WP_Post $post Post.
	 */
	public static function box_product( $post ) {
		wp_nonce_field( 'mhp_meta_mh_product', '_mhp_meta_nonce' );
		self::render_fields( $post, self::fields( 'mh_product' ) );
		$sel   = array_map( 'intval', (array) get_post_meta( $post->ID, '_mhp_tests', true ) );
		$tests = get_posts(
			array(
				'post_type'      => 'mh_test',
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => 300,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		echo '<h4>প্যাকে যে টেস্টগুলো থাকবে</h4><div class="mhp-checklist">';
		if ( ! $tests ) {
			echo '<p class="description">এখনো কোনো টেস্ট নেই।</p>';
		}
		foreach ( $tests as $t ) {
			$p = (float) mhp_meta( $t->ID, 'price', 0 );
			echo '<label><input type="checkbox" name="mhp_tests[]" value="' . (int) $t->ID . '"' . checked( in_array( (int) $t->ID, $sel, true ), true, false ) . '> ' . esc_html( get_the_title( $t ) ) . ' <span class="description">' . esc_html( $p > 0 ? mhp_tk( $p ) : 'ফ্রি' ) . '</span></label><br>';
		}
		echo '</div>';
		self::file_field( $post );
	}

	/**
	 * Publication box: fields + protected file for the digital edition.
	 *
	 * @param WP_Post $post Post.
	 */
	public static function box_pub( $post ) {
		wp_nonce_field( 'mhp_meta_mh_pub', '_mhp_meta_nonce' );
		self::render_fields( $post, self::fields( 'mh_pub' ) );
		echo '<p class="description">প্রচ্ছদ: ডান পাশের "ফিচার্ড ছবি"। ভেতরের সূচি বা বিবরণ: ওপরের লেখার ঘরে। ট্র্যাক বেছে দিলে সংশ্লিষ্ট প্রস্তুতির পাতায় খুঁজে পেতে সুবিধা।</p>';
		self::file_field( $post, 'ডিজিটাল সংস্করণের ফাইল (সুরক্ষিত)' );
	}

	/**
	 * Protected file field (product download / digital edition).
	 *
	 * @param WP_Post $post  Post.
	 * @param string  $title Heading.
	 */
	private static function file_field( $post, $title = 'ডাউনলোড ফাইল (সুরক্ষিত)' ) {
		$file = (string) get_post_meta( $post->ID, '_mhp_file', true );
		echo '<h4>' . esc_html( $title ) . '</h4>';
		if ( $file && MHP_Front::private_path( $file ) ) {
			echo '<p>বর্তমান: <b>' . esc_html( (string) get_post_meta( $post->ID, '_mhp_file_name', true ) ) . '</b> · <label><input type="checkbox" name="mhp_file_remove" value="1"> সরান</label></p>';
		}
		echo '<input type="file" name="mhp_file" accept=".pdf,.epub,.zip"> <p class="description">পিডিএফ, ইপাব বা জিপ, সর্বোচ্চ ' . esc_html( size_format( wp_max_upload_size() ) ) . '। ফাইলটি সাধারণ মিডিয়া লাইব্রেরিতে যায় না; শুধু ক্রেতারা ডাউনলোড করতে পারেন।</p>';
	}

	/**
	 * Event registrations.
	 *
	 * @param WP_Post $post Post.
	 */
	public static function box_event_people( $post ) {
		global $wpdb;
		$t    = MHP_Install::table( 'access' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, user_id, created_at FROM {$t} WHERE item_type = 'event' AND item_id = %d ORDER BY id ASC LIMIT 500", $post->ID ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! $rows ) {
			echo '<p class="description">এখনো কেউ নিবন্ধন করেননি।</p>';
			return;
		}
		echo '<p>' . esc_html( mhp_bn( count( $rows ) ) . ' জন' ) . '</p><ol style="margin-left:18px">';
		foreach ( $rows as $r ) {
			$u = get_userdata( (int) $r['user_id'] );
			echo '<li>' . esc_html( $u ? $u->display_name : '#' . $r['user_id'] ) . '<br><code>' . esc_html( MHP_Access::ticket_code( (int) $r['id'], (int) $r['user_id'] ) ) . '</code></li>';
		}
		echo '</ol>';
	}

	/**
	 * Save.
	 *
	 * @param int     $post_id Post.
	 * @param WP_Post $post    Post.
	 */
	public static function save( $post_id, $post ) {
		if ( ! in_array( $post->post_type, array( 'mh_job', 'mh_question', 'mh_test', 'mh_product', 'mh_event', 'mh_consultant', 'mh_cv_tpl', 'mh_lesson', 'mh_pub' ), true ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		$nonce = isset( $_POST['_mhp_meta_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_mhp_meta_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'mhp_meta_' . $post->post_type ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field is sanitised below.
		$in = isset( $_POST['mhp'] ) && is_array( $_POST['mhp'] ) ? wp_unslash( $_POST['mhp'] ) : array();

		if ( 'mh_question' === $post->post_type ) {
			$opts = array();
			foreach ( array( 0, 1, 2, 3 ) as $i ) {
				$v = isset( $in['opts'][ $i ] ) ? sanitize_text_field( (string) $in['opts'][ $i ] ) : '';
				if ( '' !== $v ) {
					$opts[] = $v;
				}
			}
			$ans = isset( $in['answer'] ) ? max( 0, min( 3, (int) $in['answer'] ) ) : 0;
			if ( $ans >= count( $opts ) ) {
				$ans = 0;
			}
			update_post_meta( $post_id, '_mhp_opts', $opts );
			update_post_meta( $post_id, '_mhp_answer', $ans );
			update_post_meta( $post_id, '_mhp_explain', isset( $in['explain'] ) ? sanitize_textarea_field( (string) $in['explain'] ) : '' );
			update_post_meta( $post_id, '_mhp_source', isset( $in['source'] ) ? sanitize_text_field( (string) $in['source'] ) : '' );
			update_post_meta( $post_id, '_mhp_quiz_ok', empty( $in['quiz_ok'] ) ? 0 : 1 );
			return;
		}

		foreach ( self::fields( $post->post_type ) as $key => $f ) {
			if ( ! empty( $f[4] ) && ! mhp_can_shop() ) {
				continue; // Price / featured: order managers only.
			}
			$type = $f[1];
			if ( 'checkbox' === $type ) {
				update_post_meta( $post_id, '_mhp_' . $key, empty( $in[ $key ] ) ? 0 : 1 );
				continue;
			}
			if ( ! array_key_exists( $key, $in ) ) {
				continue;
			}
			$v = is_scalar( $in[ $key ] ) ? (string) $in[ $key ] : '';
			switch ( $type ) {
				case 'number':
				case 'media':
					$v = absint( meeha_prep_en_digits( $v ) );
					break;
				case 'money':
					$v = max( 0, round( (float) meeha_prep_en_digits( $v ), 2 ) );
					break;
				case 'decimal':
					$v = max( 0, min( 4, round( (float) meeha_prep_en_digits( $v ), 2 ) ) );
					break;
				case 'date':
					$v = mhp_is_date( $v ) ? $v : '';
					break;
				case 'datetime':
					$v = preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $v ) ? str_replace( 'T', ' ', $v ) : '';
					break;
				case 'url':
					$v = esc_url_raw( $v, array( 'http', 'https' ) );
					break;
				case 'email':
					$v = sanitize_email( $v );
					break;
				case 'textarea':
					$v = sanitize_textarea_field( $v );
					break;
				case 'select':
					$v = array_key_exists( $v, $f[3] ) ? $v : '';
					break;
				default:
					$v = sanitize_text_field( $v );
			}
			update_post_meta( $post_id, '_mhp_' . $key, $v );
		}

		if ( in_array( $post->post_type, array( 'mh_test', 'mh_lesson' ), true ) ) {
			self::save_test_questions( $post_id );
		}
		if ( 'mh_pub' === $post->post_type && mhp_can_manage() ) {
			self::save_product_file( $post_id );
		}
		if ( 'mh_cv_tpl' === $post->post_type ) {
			self::save_cv_code( $post_id, $in );
		}
		if ( 'mh_product' === $post->post_type && mhp_can_shop() ) {
			// The bundle's test list decides what a purchase unlocks, so only order managers change it (like the price).
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
			$tests = isset( $_POST['mhp_tests'] ) ? array_map( 'absint', (array) $_POST['mhp_tests'] ) : array();
			$tests = array_values(
				array_filter(
					$tests,
					function ( $t ) {
						return 'mh_test' === get_post_type( $t );
					}
				)
			);
			update_post_meta( $post_id, '_mhp_tests', $tests );
			self::save_product_file( $post_id );
		}
	}

	/**
	 * Save a test's question list.
	 *
	 * @param int $post_id Test.
	 */
	private static function save_test_questions( $post_id ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in save().
		$ids    = isset( $_POST['mhp_questions'] ) ? array_map( 'absint', (array) $_POST['mhp_questions'] ) : array();
		$remove = isset( $_POST['mhp_remove'] ) ? array_map( 'absint', (array) $_POST['mhp_remove'] ) : array();
		$auto   = isset( $_POST['mhp_auto'] ) && is_array( $_POST['mhp_auto'] ) ? array_map( 'absint', $_POST['mhp_auto'] ) : array();
		// phpcs:enable
		$ids = array_values(
			array_unique(
				array_filter(
					array_diff( $ids, $remove ),
					function ( $q ) {
						return 'mh_question' === get_post_type( $q );
					}
				)
			)
		);
		$n = isset( $auto['count'] ) ? min( 200, (int) $auto['count'] ) : 0;
		if ( $n > 0 ) {
			$tax = array();
			$tr  = wp_get_post_terms( $post_id, 'mh_track', array( 'fields' => 'ids' ) );
			if ( $tr && ! is_wp_error( $tr ) ) {
				$tax[] = array(
					'taxonomy' => 'mh_track',
					'terms'    => array_map( 'intval', $tr ),
				);
			}
			if ( ! empty( $auto['subject'] ) ) {
				$tax[] = array(
					'taxonomy' => 'mh_subject',
					'terms'    => array( (int) $auto['subject'] ),
				);
			}
			$more = get_posts(
				array(
					'post_type'      => 'mh_question',
					'post_status'    => 'publish',
					'posts_per_page' => $n,
					'fields'         => 'ids',
					'orderby'        => 'rand',
					'post__not_in'   => $ids ? $ids : array( 0 ),
					'tax_query'      => $tax ? array_merge( array( 'relation' => 'AND' ), $tax ) : array(), // phpcs:ignore WordPress.DB.SlowDBQuery
					'no_found_rows'  => true,
				)
			);
			$ids  = array_merge( $ids, array_map( 'intval', $more ) );
		}
		update_post_meta( $post_id, '_mhp_questions', $ids );
	}

	/**
	 * Store an uploaded product file in the private folder.
	 *
	 * @param int $post_id Product.
	 */
	private static function save_product_file( $post_id ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in save().
		if ( ! empty( $_POST['mhp_file_remove'] ) ) {
			$old = MHP_Front::private_path( (string) get_post_meta( $post_id, '_mhp_file', true ) );
			if ( $old ) {
				wp_delete_file( $old );
			}
			delete_post_meta( $post_id, '_mhp_file' );
			delete_post_meta( $post_id, '_mhp_file_name' );
		}
		if ( empty( $_FILES['mhp_file']['name'] ) || ! mhp_can_manage() ) {
			return;
		}
		$f = $_FILES['mhp_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		// phpcs:enable
		if ( ! empty( $f['error'] ) || empty( $f['tmp_name'] ) || ! is_uploaded_file( $f['tmp_name'] ) ) {
			return;
		}
		$name  = sanitize_file_name( (string) $f['name'] );
		$check = wp_check_filetype_and_ext(
			$f['tmp_name'],
			$name,
			array(
				'pdf'  => 'application/pdf',
				'epub' => 'application/epub+zip',
				'zip'  => 'application/zip',
			)
		);
		if ( empty( $check['ext'] ) ) {
			set_transient( 'mhp_file_err_' . get_current_user_id(), 'শুধু পিডিএফ, ইপাব বা জিপ ফাইল দেওয়া যাবে।', 60 );
			return;
		}
		$stored = wp_generate_password( 20, false, false ) . '.' . $check['ext'];
		$dest   = MHP_Front::private_dir() . '/' . $stored;
		if ( ! move_uploaded_file( $f['tmp_name'], $dest ) ) {
			return;
		}
		$old = MHP_Front::private_path( (string) get_post_meta( $post_id, '_mhp_file', true ) );
		if ( $old ) {
			wp_delete_file( $old );
		}
		update_post_meta( $post_id, '_mhp_file', $stored );
		update_post_meta( $post_id, '_mhp_file_name', $name );
	}

	/**
	 * Question search for the test picker.
	 */
	public static function find_questions() {
		check_ajax_referer( 'mhp_find_questions', 'nonce' );
		if ( ! current_user_can( 'edit_mhp_items' ) ) {
			wp_send_json_error( null, 403 );
		}
		$q   = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		$out = array();
		if ( mb_strlen( $q ) >= 2 ) {
			foreach ( get_posts(
				array(
					'post_type'      => 'mh_question',
					'post_status'    => 'publish',
					's'              => $q,
					'posts_per_page' => 20,
					'no_found_rows'  => true,
				)
			) as $p ) {
				$out[] = array(
					'id'    => (int) $p->ID,
					'title' => wp_trim_words( $p->post_title, 16 ),
				);
			}
		}
		wp_send_json_success( $out );
	}

	/* ---------------- Track term fields ---------------- */

	/**
	 * Track field defs.
	 *
	 * @return array
	 */
	private static function track_fields() {
		$types = array( 0 => '—' );
		$terms = get_terms(
			array(
				'taxonomy'   => 'mh_job_type',
				'hide_empty' => false,
			)
		);
		if ( $terms && ! is_wp_error( $terms ) ) {
			foreach ( $terms as $t ) {
				$types[ $t->term_id ] = $t->name;
			}
		}
		return array(
			'mhp_short'         => array( 'ছোট বিবরণ', 'text', 'টাইলে দেখায়, যেমন: ২০০ নম্বরের এমসিকিউ' ),
			'mhp_color'         => array( 'রং', 'color' ),
			'mhp_glyph'         => array( 'টাইলের অক্ষর', 'text', '১–৩টি অক্ষর, যেমন: বি' ),
			'mhp_order'         => array( 'ক্রম', 'number' ),
			'mhp_syllabus'      => array( 'সিলেবাস', 'textarea', 'প্রতি লাইনে: বিষয় | নম্বর' ),
			'mhp_syllabus_note' => array( 'সিলেবাসের নোট', 'text' ),
			'mhp_job_type'      => array( 'সংশ্লিষ্ট চাকরির ধরন', 'select', '', $types ),
			'mhp_is_new'        => array( '"নতুন" চিহ্ন', 'checkbox' ),
		);
	}

	/**
	 * Add-term form.
	 */
	public static function track_add() {
		wp_nonce_field( 'mhp_track', '_mhp_track_nonce' );
		foreach ( self::track_fields() as $k => $f ) {
			echo '<div class="form-field"><label for="' . esc_attr( $k ) . '">' . esc_html( $f[0] ) . '</label>';
			self::track_input( $k, $f, '' );
			echo '</div>';
		}
	}

	/**
	 * Edit-term form.
	 *
	 * @param WP_Term $term Term.
	 */
	public static function track_edit( $term ) {
		wp_nonce_field( 'mhp_track', '_mhp_track_nonce' );
		foreach ( self::track_fields() as $k => $f ) {
			echo '<tr class="form-field"><th><label for="' . esc_attr( $k ) . '">' . esc_html( $f[0] ) . '</label></th><td>';
			self::track_input( $k, $f, get_term_meta( $term->term_id, $k, true ) );
			echo '</td></tr>';
		}
	}

	/**
	 * One track input.
	 *
	 * @param string $k   Key.
	 * @param array  $f   Def.
	 * @param mixed  $val Value.
	 */
	private static function track_input( $k, $f, $val ) {
		switch ( $f[1] ) {
			case 'textarea':
				echo '<textarea id="' . esc_attr( $k ) . '" name="' . esc_attr( $k ) . '" rows="8">' . esc_textarea( (string) $val ) . '</textarea>';
				break;
			case 'select':
				echo '<select id="' . esc_attr( $k ) . '" name="' . esc_attr( $k ) . '">';
				foreach ( $f[3] as $id => $l ) {
					echo '<option value="' . esc_attr( $id ) . '"' . selected( (int) $val, (int) $id, false ) . '>' . esc_html( $l ) . '</option>';
				}
				echo '</select>';
				break;
			case 'checkbox':
				echo '<input type="checkbox" id="' . esc_attr( $k ) . '" name="' . esc_attr( $k ) . '" value="1"' . checked( (int) $val, 1, false ) . ' style="width:auto">';
				break;
			case 'color':
				echo '<input type="color" id="' . esc_attr( $k ) . '" name="' . esc_attr( $k ) . '" value="' . esc_attr( $val ? (string) $val : '#025AB2' ) . '" style="width:80px">';
				break;
			default:
				echo '<input type="' . ( 'number' === $f[1] ? 'number' : 'text' ) . '" id="' . esc_attr( $k ) . '" name="' . esc_attr( $k ) . '" value="' . esc_attr( (string) $val ) . '">';
		}
		if ( ! empty( $f[2] ) ) {
			echo '<p class="description">' . esc_html( $f[2] ) . '</p>';
		}
	}

	/**
	 * Save track meta.
	 *
	 * @param int $term_id Term.
	 */
	public static function track_save( $term_id ) {
		$nonce = isset( $_POST['_mhp_track_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_mhp_track_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'mhp_track' ) || ! current_user_can( 'mhp_manage' ) ) {
			return;
		}
		foreach ( self::track_fields() as $k => $f ) {
			$raw = isset( $_POST[ $k ] ) ? wp_unslash( $_POST[ $k ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$raw = is_scalar( $raw ) ? (string) $raw : '';
			switch ( $f[1] ) {
				case 'textarea':
					$v = sanitize_textarea_field( $raw );
					break;
				case 'color':
					$v = (string) sanitize_hex_color( $raw );
					break;
				case 'number':
				case 'select':
					$v = absint( $raw );
					break;
				case 'checkbox':
					$v = '' === $raw ? 0 : 1;
					break;
				default:
					$v = sanitize_text_field( $raw );
			}
			update_term_meta( $term_id, $k, $v );
		}
		delete_transient( 'mhp_takers_' . (int) $term_id );
	}
}
