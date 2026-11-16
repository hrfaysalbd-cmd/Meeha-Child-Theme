<?php
/**
 * Career consultants: a panel the desk adds (mh_consultant), and readers' session requests ({prefix}mhp_bookings).
 *
 * Flow: the reader picks a consultant, a topic and a preferred day, and writes the question. A paid session goes
 * to checkout (item type "consult", item id = booking id; the fee is frozen on the booking); a free one is
 * requested straight away. The desk confirms a time and a call/video link from প্রস্তুতি → পরামর্শের বুকিং,
 * the reader gets an email and sees it under আমার → পরামর্শ.
 *
 * Booking status: unpaid → requested (paid or free) → confirmed → done ; any open state → cancelled.
 *
 * @package meeha-prep
 */

defined( 'ABSPATH' ) || exit;

/**
 * Consultants and bookings.
 */
class MHP_Consult {

	const MAX_OPEN = 3;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ), 6 );
		add_filter( 'meeha_campus_views', array( __CLASS__, 'views' ) );
		add_filter( 'meeha_campus_tabs', array( __CLASS__, 'tabs' ), 11, 2 );
		add_filter( 'mhp_hub_actions', array( __CLASS__, 'actions' ) );
		add_action( 'mhp_hub_action_consult_book', array( __CLASS__, 'handle_book' ) );
		add_action( 'mhp_hub_action_consult_cancel', array( __CLASS__, 'handle_cancel' ) );
		add_action( 'mhp_order_paid', array( __CLASS__, 'paid' ) );
		add_action( 'mhp_order_refunded', array( __CLASS__, 'refunded' ) );
	}

	/**
	 * Post type.
	 */
	public static function register() {
		register_post_type(
			'mh_consultant',
			array(
				'labels'              => array(
					'name'          => 'ক্যারিয়ার কনসাল্টেন্ট',
					'singular_name' => 'কনসাল্টেন্ট',
					'menu_name'     => 'কনসাল্টেন্ট',
					'add_new'       => 'নতুন যোগ',
					'add_new_item'  => 'নতুন কনসাল্টেন্ট',
					'edit_item'     => 'কনসাল্টেন্ট সম্পাদনা',
					'all_items'     => 'কনসাল্টেন্ট',
					'not_found'     => 'কিছু পাওয়া যায়নি',
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => true,
				'show_in_menu'        => 'mhp',
				'show_in_rest'        => false,
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'capability_type'     => array( 'mhp_item', 'mhp_items' ),
				'map_meta_cap'        => true,
				'supports'            => array( 'title', 'editor', 'excerpt', 'thumbnail', 'page-attributes' ),
			)
		);
	}

	/**
	 * Hub actions.
	 *
	 * @param string[] $a Actions.
	 * @return string[]
	 */
	public static function actions( $a ) {
		return array_merge( $a, array( 'consult_book', 'consult_cancel' ) );
	}

	/**
	 * Views.
	 *
	 * @param array $views Views.
	 * @return array
	 */
	public static function views( $views ) {
		$views['consult']    = array(
			'title'  => 'ক্যারিয়ার পরামর্শ',
			'tab'    => 'consult',
			'root'   => true,
			'render' => array( __CLASS__, 'render_list' ),
		);
		$views['consultant'] = array(
			'title'    => array( __CLASS__, 'title_one' ),
			'tab'      => 'consult',
			'back'     => 'consult',
			'needs_id' => true,
			'nocache'  => true,
			'bar'      => false,
			'render'   => array( __CLASS__, 'render_one' ),
		);
		return $views;
	}

	/**
	 * Masthead tab (only once the panel has someone).
	 *
	 * @param array  $tabs Tabs.
	 * @param string $cur  Current.
	 * @return array
	 */
	public static function tabs( $tabs, $cur ) {
		if ( self::any() ) {
			$tabs[] = array( 'পরামর্শ', mhp_url( 'consult' ), in_array( $cur, array( 'consult', 'consultant' ), true ) );
		}
		return $tabs;
	}

	/**
	 * Title of a profile.
	 *
	 * @param string $id Id.
	 * @return string
	 */
	public static function title_one( $id ) {
		$c = self::data( (int) $id );
		return $c ? $c['name'] : 'কনসাল্টেন্ট';
	}

	/**
	 * Session modes.
	 *
	 * @return array
	 */
	public static function modes() {
		return array(
			'phone'  => 'ফোনে',
			'video'  => 'ভিডিও কলে',
			'both'   => 'ফোন বা ভিডিও কলে',
			'office' => 'সরাসরি (মিহা কার্যালয়ে)',
		);
	}

	/**
	 * Preferred time windows.
	 *
	 * @return array
	 */
	public static function windows() {
		return array(
			'morning'   => 'সকাল (৯টা–১২টা)',
			'noon'      => 'দুপুর (১২টা–৩টা)',
			'afternoon' => 'বিকেল (৩টা–৬টা)',
			'evening'   => 'সন্ধ্যা (৬টা–৯টা)',
		);
	}

	/**
	 * Booking statuses.
	 *
	 * @return array status => [label, css]
	 */
	public static function statuses() {
		return array(
			'unpaid'    => array( 'ফি পরিশোধ বাকি', 'warn' ),
			'requested' => array( 'অনুরোধ জমা হয়েছে', 'line' ),
			'confirmed' => array( 'সময় নিশ্চিত', 'ok' ),
			'done'      => array( 'সম্পন্ন', 'n' ),
			'cancelled' => array( 'বাতিল', 'n' ),
		);
	}

	/**
	 * One consultant.
	 *
	 * @param int  $id        Post.
	 * @param bool $published Published only.
	 * @return array|null
	 */
	public static function data( $id, $published = true ) {
		$p = get_post( (int) $id );
		if ( ! $p || 'mh_consultant' !== $p->post_type || ( $published && 'publish' !== $p->post_status ) ) {
			return null;
		}
		$topics = array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) mhp_meta( $p->ID, 'topics', '' ) ) ) ) );
		$mode   = (string) mhp_meta( $p->ID, 'mode', 'both' );
		return array(
			'id'        => (int) $p->ID,
			'name'      => get_the_title( $p ),
			'role'      => (string) mhp_meta( $p->ID, 'role', '' ),
			'intro'     => (string) $p->post_excerpt,
			'bio'       => (string) $p->post_content,
			'photo'     => get_the_post_thumbnail_url( $p, 'medium' ),
			'expertise' => array_values( array_filter( array_map( 'trim', explode( ',', str_replace( '،', ',', (string) mhp_meta( $p->ID, 'expertise', '' ) ) ) ) ) ),
			'topics'    => $topics ? $topics : array( 'সাধারণ ক্যারিয়ার পরামর্শ' ),
			'fee'       => max( 0, round( (float) mhp_meta( $p->ID, 'price', 0 ), 2 ) ),
			'minutes'   => max( 0, (int) mhp_meta( $p->ID, 'minutes', 30 ) ),
			'mode'      => isset( self::modes()[ $mode ] ) ? $mode : 'both',
			'avail'     => (string) mhp_meta( $p->ID, 'availability', '' ),
			'open'      => (bool) mhp_meta( $p->ID, 'accepting', 1 ),
			'status'    => $p->post_status,
		);
	}

	/**
	 * Published panel.
	 *
	 * @return array[]
	 */
	public static function all() {
		$out = array();
		foreach ( get_posts(
			array(
				'post_type'      => 'mh_consultant',
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'orderby'        => array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				),
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		) as $id ) {
			$c = self::data( $id );
			if ( $c ) {
				$out[] = $c;
			}
		}
		return $out;
	}

	/**
	 * Is anyone on the panel?
	 *
	 * @return bool
	 */
	public static function any() {
		static $any = null;
		if ( null === $any ) {
			$any = (bool) get_posts(
				array(
					'post_type'      => 'mh_consultant',
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);
		}
		return $any;
	}

	/* ------------------------------------------------------------------
	 * Bookings
	 * ---------------------------------------------------------------- */

	/**
	 * Booking by id.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public static function booking( $id ) {
		global $wpdb;
		$t = MHP_Install::table( 'bookings' );
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $r ? $r : null;
	}

	/**
	 * Is the booking the user's?
	 *
	 * @param int $booking_id Booking.
	 * @param int $user_id    User.
	 * @return bool
	 */
	public static function is_owner( $booking_id, $user_id ) {
		$b = self::booking( $booking_id );
		return $b && $user_id && (int) $b['user_id'] === (int) $user_id;
	}

	/**
	 * A user's bookings.
	 *
	 * @param int $user_id User.
	 * @return array[]
	 */
	public static function of_user( $user_id ) {
		global $wpdb;
		$t = MHP_Install::table( 'bookings' );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE user_id = %d ORDER BY id DESC LIMIT 50", (int) $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Open bookings of a user (optionally with one consultant).
	 *
	 * @param int $user_id       User.
	 * @param int $consultant_id Consultant (0 = any).
	 * @return int
	 */
	public static function open_count( $user_id, $consultant_id = 0 ) {
		global $wpdb;
		$t = MHP_Install::table( 'bookings' );
		if ( $consultant_id ) {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE user_id = %d AND consultant_id = %d AND status IN ('unpaid','requested','confirmed')", (int) $user_id, (int) $consultant_id ) ); // phpcs:ignore WordPress.DB
		}
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE user_id = %d AND status IN ('unpaid','requested','confirmed')", (int) $user_id ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Conditional status change.
	 *
	 * @param int      $id     Booking.
	 * @param string   $to     Status.
	 * @param string[] $from   Allowed current statuses.
	 * @param array    $fields Extra columns.
	 * @return bool
	 */
	public static function set_status( $id, $to, $from, $fields = array() ) {
		global $wpdb;
		if ( ! isset( self::statuses()[ $to ] ) ) {
			return false;
		}
		$t    = MHP_Install::table( 'bookings' );
		$in   = implode( ',', array_fill( 0, count( $from ), '%s' ) );
		$sets = 'status = %s, updated_at = %s';
		$args = array( $to, mhp_gmt() );
		foreach ( $fields as $k => $v ) {
			if ( in_array( $k, array( 'order_id', 'slot', 'meet_link', 'admin_note' ), true ) ) {
				$sets  .= ', ' . $k . ( 'order_id' === $k ? ' = %d' : ' = %s' );
				$args[] = $v;
			}
		}
		$args = array_merge( $args, array( (int) $id ), array_values( $from ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		return 1 === (int) $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET {$sets} WHERE id = %d AND status IN ({$in})", $args ) );
	}

	/**
	 * Checkout description of a booking fee (Orders::item for type "consult"). Only the booking's owner
	 * (or an order manager) can see or pay it.
	 *
	 * @param int $booking_id Booking.
	 * @return array
	 */
	public static function order_item( $booking_id ) {
		$fail = array(
			'ok'     => false,
			'name'   => '',
			'price'  => 0,
			'reason' => 'এই আইটেমটি পাওয়া যায়নি।',
			'post'   => null,
		);
		$b = self::booking( $booking_id );
		if ( ! $b || ( (int) $b['user_id'] !== get_current_user_id() && ! mhp_can_shop() ) ) {
			return $fail;
		}
		$c = self::data( (int) $b['consultant_id'], false );
		if ( ! $c ) {
			return $fail;
		}
		$out = array(
			'ok'     => true,
			'name'   => 'ক্যারিয়ার পরামর্শ: ' . $c['name'] . ( $c['minutes'] ? ' (' . mhp_bn( $c['minutes'] ) . ' মিনিট)' : '' ),
			'price'  => round( (float) $b['fee'], 2 ),
			'reason' => '',
			'post'   => get_post( $c['id'] ),
			'note'   => 'বিষয়: ' . $b['topic'] . ( $b['pref_date'] ? ' · পছন্দের দিন: ' . mhp_fdate( $b['pref_date'] ) : '' ),
		);
		if ( 'unpaid' !== $b['status'] ) {
			$out['ok']     = false;
			$out['reason'] = 'cancelled' === $b['status'] ? 'বুকিংটি বাতিল হয়েছে।' : 'এই বুকিংয়ের ফি আগেই পরিশোধ হয়েছে।';
		} elseif ( $out['price'] <= 0 ) {
			$out['ok']     = false;
			$out['reason'] = 'এই বুকিংয়ের জন্য কোনো ফি নেই।';
		}
		return $out;
	}

	/**
	 * Fee paid: the request goes to the desk.
	 *
	 * @param array $order Order row.
	 */
	public static function paid( $order ) {
		if ( ! $order || 'consult' !== $order['item_type'] ) {
			return;
		}
		if ( self::set_status( (int) $order['item_id'], 'requested', array( 'unpaid' ), array( 'order_id' => (int) $order['id'] ) ) ) {
			self::notify_desk( self::booking( (int) $order['item_id'] ) );
			return;
		}
		// Paid for a booking that was cancelled meanwhile, or paid twice: the desk refunds; nothing reopens.
		MHP_Orders::log( (int) $order['id'], 'বুকিং আর খোলা নেই বা আগেই পরিশোধিত: রিফান্ড দিন' );
		wp_mail( self::desk_email(), '[মিহা] পরামর্শের ফি রিফান্ড দিন: অর্ডার ' . $order['tran_id'], 'বুকিং #' . (int) $order['item_id'] . ' বাতিল ছিল বা এর ফি আগেই পরিশোধ হয়েছিল, তবু অর্ডার ' . $order['tran_id'] . ' (' . mhp_tk( $order['amount'] ) . ') পরিশোধ হয়েছে। SSLCommerz থেকে টাকা ফেরত দিয়ে অর্ডার পাতায় রিফান্ড চিহ্নিত করুন।' );
	}

	/**
	 * A refunded fee closes its booking (if that order is the one the booking was paid with).
	 *
	 * @param array|null $order Order row.
	 */
	public static function refunded( $order ) {
		if ( ! $order || 'consult' !== $order['item_type'] ) {
			return;
		}
		$b = self::booking( (int) $order['item_id'] );
		if ( $b && (int) $b['order_id'] === (int) $order['id'] ) {
			self::set_status( (int) $b['id'], 'cancelled', array( 'unpaid', 'requested', 'confirmed' ), array( 'admin_note' => 'ফি ফেরত দেওয়া হয়েছে।' ) );
		}
	}

	/**
	 * Email the desk (and the consultant's own address when set) about a new request.
	 *
	 * @param array|null $b Booking.
	 */
	public static function notify_desk( $b ) {
		if ( ! $b ) {
			return;
		}
		$c    = self::data( (int) $b['consultant_id'], false );
		$u    = get_userdata( (int) $b['user_id'] );
		$body = "নতুন পরামর্শের অনুরোধ\n\nকনসাল্টেন্ট: " . ( $c ? $c['name'] : '#' . $b['consultant_id'] ) . "\nপাঠক: " . ( $u ? $u->display_name . ' <' . $u->user_email . '>' : '' ) . "\nফোন: {$b['phone']}\nবিষয়: {$b['topic']}\nপছন্দের দিন: {$b['pref_date']} " . ( isset( self::windows()[ $b['pref_time'] ] ) ? self::windows()[ $b['pref_time'] ] : '' ) . "\nফি: " . mhp_tk( $b['fee'] ) . "\n\nপ্রশ্ন:\n{$b['question']}\n\nনিশ্চিত করুন: " . admin_url( 'admin.php?page=mhp-bookings&booking=' . (int) $b['id'] );
		$to   = array( self::desk_email() );
		$own  = $c ? sanitize_email( (string) mhp_meta( $c['id'], 'email', '' ) ) : '';
		if ( $own ) {
			$to[] = $own;
		}
		wp_mail( array_unique( array_filter( $to ) ), '[মিহা] নতুন পরামর্শের অনুরোধ #' . (int) $b['id'], $body );
	}

	/**
	 * Desk address.
	 *
	 * @return string
	 */
	public static function desk_email() {
		$e = sanitize_email( (string) mhp_opt( 'notify_email', '' ) );
		return $e ? $e : (string) get_option( 'admin_email' );
	}

	/**
	 * Email the reader about their booking.
	 *
	 * @param array  $b       Booking (fresh).
	 * @param string $subject Subject.
	 * @param string $lead    First line.
	 */
	public static function notify_reader( $b, $subject, $lead ) {
		$u = get_userdata( (int) $b['user_id'] );
		if ( ! $u ) {
			return;
		}
		$c    = self::data( (int) $b['consultant_id'], false );
		$body = $lead . "\n\nকনসাল্টেন্ট: " . ( $c ? $c['name'] : '' ) . "\nবিষয়: {$b['topic']}" . ( $b['slot'] ? "\nসময়: {$b['slot']}" : '' ) . ( $b['meet_link'] ? "\nলিংক: {$b['meet_link']}" : '' ) . ( $b['admin_note'] ? "\n\n{$b['admin_note']}" : '' ) . "\n\nবিস্তারিত: " . mhp_url( 'me', '', array( 'tab' => 'consult' ) );
		wp_mail( $u->user_email, $subject, $body );
	}

	/* ------------------------------------------------------------------
	 * Hub
	 * ---------------------------------------------------------------- */

	/**
	 * Panel.
	 *
	 * @param string $id Unused.
	 */
	public static function render_list( $id ) {
		mhp_template( 'consult', array( 'list' => self::all() ) );
	}

	/**
	 * Profile + booking form.
	 *
	 * @param string $id Consultant id.
	 */
	public static function render_one( $id ) {
		$c = self::data( (int) $id, ! mhp_can_manage() );
		if ( ! $c ) {
			MHP_Front::not_found( 'কনসাল্টেন্ট পাওয়া যায়নি।', 'consult' );
			return;
		}
		$uid  = get_current_user_id();
		$keep = $uid ? get_transient( 'mhp_bk_' . $uid ) : false;
		if ( $keep ) {
			delete_transient( 'mhp_bk_' . $uid );
		}
		mhp_template(
			'consultant',
			array(
				'c'      => $c,
				'uid'    => $uid,
				'open'   => $uid ? self::open_count( $uid, $c['id'] ) : 0,
				'pay_ok' => MHP_Gateway_SSLCommerz::available(),
				'phone'  => $uid ? (string) get_user_meta( $uid, 'meeha_phone', true ) : '',
				'keep'   => is_array( $keep ) ? $keep : array(),
			)
		);
	}

	/**
	 * Book a session.
	 */
	public static function handle_book() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by the hub dispatcher.
		$uid  = get_current_user_id();
		$cid  = isset( $_POST['consultant_id'] ) ? absint( $_POST['consultant_id'] ) : 0;
		$c    = self::data( $cid );
		$back = $c ? mhp_url( 'consultant', $c['id'] ) : mhp_url( 'consult' );
		$keep = array();
		foreach ( array( 'topic', 'question', 'pref_date', 'pref_time', 'phone' ) as $k ) {
			$keep[ $k ] = isset( $_POST[ $k ] ) ? mb_substr( sanitize_textarea_field( wp_unslash( $_POST[ $k ] ) ), 0, 2000 ) : '';
		}
		$fail = function ( $msg ) use ( $back, $uid, $keep ) {
			// Keep what the reader typed for the form (10 minutes, own account only).
			set_transient( 'mhp_bk_' . $uid, $keep, 10 * MINUTE_IN_SECONDS );
			mhp_flash( $msg, 'error' );
			wp_safe_redirect( $back . '#book' );
			exit;
		};
		if ( ! $c || ! $c['open'] ) {
			$fail( 'এই কনসাল্টেন্ট এখন বুকিং নিচ্ছেন না।' );
		}
		$topic = isset( $_POST['topic'] ) ? sanitize_text_field( wp_unslash( $_POST['topic'] ) ) : '';
		if ( ! in_array( $topic, $c['topics'], true ) ) {
			$topic = $c['topics'][0];
		}
		$q     = isset( $_POST['question'] ) ? trim( sanitize_textarea_field( wp_unslash( $_POST['question'] ) ) ) : '';
		$date  = isset( $_POST['pref_date'] ) ? sanitize_text_field( wp_unslash( $_POST['pref_date'] ) ) : '';
		$win   = isset( $_POST['pref_time'] ) ? sanitize_key( wp_unslash( $_POST['pref_time'] ) ) : '';
		$phone = isset( $_POST['phone'] ) ? meeha_prep_en_digits( preg_replace( '/[\s\-]/', '', sanitize_text_field( wp_unslash( $_POST['phone'] ) ) ) ) : '';
		// phpcs:enable
		if ( mb_strlen( $q ) < 20 ) {
			$fail( 'প্রশ্ন বা পরিস্থিতি অন্তত ২০ অক্ষরে লিখুন, যাতে কনসাল্টেন্ট প্রস্তুত হয়ে আসতে পারেন।' );
		}
		$days = mhp_is_date( $date ) ? mhp_days_left( $date ) : -1;
		if ( $days < 1 || $days > 60 ) {
			$fail( 'আগামীকাল থেকে ৬০ দিনের মধ্যে একটি দিন বেছে নিন।' );
		}
		if ( ! isset( self::windows()[ $win ] ) ) {
			$win = 'evening';
		}
		if ( ! preg_match( '/^(\+?880|0)1[3-9]\d{8}$/', $phone ) ) {
			$fail( '০১ দিয়ে শুরু হওয়া ১১ সংখ্যার সঠিক মোবাইল নম্বর দিন।' );
		}
		if ( self::open_count( $uid, $c['id'] ) > 0 ) {
			$fail( 'এই কনসাল্টেন্টের সঙ্গে আপনার একটি বুকিং আগে থেকেই চলছে। "আমার → পরামর্শ" থেকে দেখুন।' );
		}
		if ( self::open_count( $uid ) >= self::MAX_OPEN ) {
			$fail( 'একসঙ্গে সর্বোচ্চ ' . mhp_bn( self::MAX_OPEN ) . 'টি বুকিং খোলা রাখা যায়।' );
		}
		if ( ! mhp_rate_ok( 'consult_book', 6, HOUR_IN_SECONDS ) ) {
			$fail( 'অল্প সময়ে অনেকবার চেষ্টা হয়েছে। কিছুক্ষণ পরে আবার চেষ্টা করুন।' );
		}
		if ( $c['fee'] > 0 && ! MHP_Gateway_SSLCommerz::available() ) {
			$fail( 'অনলাইন পেমেন্ট এখনো চালু হয়নি, তাই পেইড সেশন বুক করা যাচ্ছে না।' );
		}
		if ( ! get_user_meta( $uid, 'meeha_phone', true ) ) {
			update_user_meta( $uid, 'meeha_phone', $phone );
		}
		global $wpdb;
		$now = mhp_gmt();
		$ok  = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			MHP_Install::table( 'bookings' ),
			array(
				'user_id'       => $uid,
				'consultant_id' => $c['id'],
				'status'        => $c['fee'] > 0 ? 'unpaid' : 'requested',
				'topic'         => mb_substr( $topic, 0, 150 ),
				'question'      => mb_substr( $q, 0, 2000 ),
				'pref_date'     => $date,
				'pref_time'     => $win,
				'phone'         => substr( $phone, 0, 20 ),
				'fee'           => $c['fee'],
				'created_at'    => $now,
				'updated_at'    => $now,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s' )
		);
		if ( ! $ok ) {
			$fail( 'বুকিং নেওয়া যায়নি। আবার চেষ্টা করুন।' );
		}
		$bid = (int) $wpdb->insert_id;
		if ( $c['fee'] > 0 ) {
			wp_safe_redirect( mhp_url( 'checkout', 'consult-' . $bid ) );
			exit;
		}
		self::notify_desk( self::booking( $bid ) );
		mhp_flash( 'অনুরোধ জমা হয়েছে। সময় ঠিক হলে ইমেইলে ও "আমার → পরামর্শ" পাতায় জানানো হবে।' );
		wp_safe_redirect( mhp_url( 'me', '', array( 'tab' => 'consult' ) ) );
		exit;
	}

	/**
	 * Reader cancels a booking that is not paid or not yet confirmed (paid fees are refunded by the desk).
	 */
	public static function handle_cancel() {
		$id = isset( $_POST['booking_id'] ) ? absint( $_POST['booking_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$b  = self::booking( $id );
		$go = mhp_url( 'me', '', array( 'tab' => 'consult' ) );
		if ( ! $b || (int) $b['user_id'] !== get_current_user_id() ) {
			mhp_flash( 'বুকিংটি পাওয়া যায়নি।', 'error' );
			wp_safe_redirect( $go );
			exit;
		}
		if ( self::set_status( $id, 'cancelled', array( 'unpaid', 'requested' ) ) ) {
			if ( 'requested' === $b['status'] ) {
				wp_mail( self::desk_email(), '[মিহা] পাঠক পরামর্শের অনুরোধ বাতিল করেছেন #' . (int) $id, 'বুকিং #' . (int) $id . ( (float) $b['fee'] > 0 ? "\nফি পরিশোধিত ছিল: রিফান্ড দিন (অর্ডার #" . (int) $b['order_id'] . ').' : '' ) );
			}
			mhp_flash( (float) $b['fee'] > 0 && 'requested' === $b['status'] ? 'বুকিং বাতিল হয়েছে। পরিশোধিত ফি কয়েক কর্মদিবসের মধ্যে ফেরত দেওয়া হবে।' : 'বুকিং বাতিল হয়েছে।' );
		} else {
			mhp_flash( 'সময় নিশ্চিত হয়ে যাওয়া বুকিং বাতিল করতে আমাদের ইমেইল করুন।', 'error' );
		}
		wp_safe_redirect( $go );
		exit;
	}
}
