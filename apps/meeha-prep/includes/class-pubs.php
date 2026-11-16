<?php
/**
 * Publications (প্রকাশনা): periodicals, syllabus books and guides, sold as a digital edition (protected PDF/EPUB
 * download), a printed edition delivered by courier, or both.
 *
 * Digital: order item "pubd" (item id = publication). Free when the digital price is 0.
 * Print:   the reader fills in the address first; a shipment row ({prefix}mhp_shipments) freezes the price, copies
 *          and delivery fee, and checkout sells item "pubp" (item id = shipment). With cash on delivery (optional)
 *          the shipment is placed without online payment.
 *
 * Shipment status: unpaid → paid (online) | cod (cash on delivery) → shipped → delivered | returned ; unpaid/cod →
 * cancelled ; a paid shipment is cancelled by refunding its order. Stock (optional per publication) is taken when
 * a shipment is paid or placed as COD, and put back when it is cancelled or returned.
 *
 * @package meeha-prep
 */

defined( 'ABSPATH' ) || exit;

/**
 * Publications.
 */
class MHP_Pubs {

	const MAX_QTY  = 5;
	const MAX_OPEN = 3;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ), 6 );
		add_filter( 'meeha_campus_views', array( __CLASS__, 'views' ) );
		add_filter( 'meeha_campus_tabs', array( __CLASS__, 'tabs' ), 12, 2 );
		add_filter( 'meeha_campus_wide_views', array( __CLASS__, 'wide' ) );
		add_filter( 'mhp_hub_actions', array( __CLASS__, 'actions' ) );
		add_action( 'mhp_hub_action_pub_order', array( __CLASS__, 'handle_order' ) );
		add_action( 'mhp_hub_action_pub_cancel', array( __CLASS__, 'handle_cancel' ) );
		add_action( 'mhp_order_paid', array( __CLASS__, 'paid' ) );
		add_action( 'mhp_order_refunded', array( __CLASS__, 'refunded' ) );
		add_action( 'template_redirect', array( __CLASS__, 'download' ), 5 );
		add_action( 'mhp_daily', array( __CLASS__, 'cleanup' ) );
	}

	/**
	 * Post type.
	 */
	public static function register() {
		register_post_type(
			'mh_pub',
			array(
				'labels'              => array(
					'name'          => 'প্রকাশনা',
					'singular_name' => 'প্রকাশনা',
					'menu_name'     => 'প্রকাশনা',
					'add_new'       => 'নতুন যোগ',
					'add_new_item'  => 'নতুন প্রকাশনা',
					'edit_item'     => 'প্রকাশনা সম্পাদনা',
					'all_items'     => 'প্রকাশনা',
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
		register_taxonomy_for_object_type( 'mh_track', 'mh_pub' );
	}

	/**
	 * Kinds.
	 *
	 * @return array
	 */
	public static function kinds() {
		return array(
			'magazine' => 'সাময়িকী',
			'syllabus' => 'সিলেবাস ও প্রশ্ন',
			'guide'    => 'গাইড বই',
			'other'    => 'অন্যান্য',
		);
	}

	/**
	 * Shipment statuses: label, pill style.
	 *
	 * @return array
	 */
	public static function statuses() {
		return array(
			'unpaid'    => array( 'পেমেন্ট বাকি', 'warn' ),
			'cod'       => array( 'ক্যাশ অন ডেলিভারি: অর্ডার গৃহীত', 'warn' ),
			'paid'      => array( 'পরিশোধিত: প্যাকিং চলছে', 'ok' ),
			'shipped'   => array( 'কুরিয়ারে পাঠানো হয়েছে', 'ok' ),
			'delivered' => array( 'পৌঁছেছে', 'ok' ),
			'returned'  => array( 'ফেরত এসেছে', 'bad' ),
			'cancelled' => array( 'বাতিল', 'n' ),
		);
	}

	/**
	 * Hub actions.
	 *
	 * @param string[] $a Actions.
	 * @return string[]
	 */
	public static function actions( $a ) {
		return array_merge( $a, array( 'pub_order', 'pub_cancel' ) );
	}

	/**
	 * Views.
	 *
	 * @param array $views Views.
	 * @return array
	 */
	public static function views( $views ) {
		$views['pubs'] = array(
			'title'  => 'প্রকাশনা',
			'tab'    => 'prep',
			'root'   => true,
			'render' => array( __CLASS__, 'render_list' ),
		);
		$views['pub']  = array(
			'title'    => array( __CLASS__, 'title_one' ),
			'tab'      => 'prep',
			'back'     => 'pubs',
			'needs_id' => true,
			'nocache'  => true,
			'bar'      => false,
			'render'   => array( __CLASS__, 'render_one' ),
		);
		return $views;
	}

	/**
	 * Masthead tab.
	 *
	 * @param array  $tabs Tabs.
	 * @param string $cur  Current.
	 * @return array
	 */
	public static function tabs( $tabs, $cur ) {
		if ( self::any() ) {
			$tabs[] = array( 'প্রকাশনা', mhp_url( 'pubs' ), in_array( $cur, array( 'pubs', 'pub' ), true ) );
		}
		return $tabs;
	}

	/**
	 * Wide layout.
	 *
	 * @param string[] $v Views.
	 * @return string[]
	 */
	public static function wide( $v ) {
		return array_merge( $v, array( 'pubs', 'pub' ) );
	}

	/**
	 * Title.
	 *
	 * @param string $id Id.
	 * @return string
	 */
	public static function title_one( $id ) {
		$p = get_post( (int) $id );
		return ( $p && 'mh_pub' === $p->post_type ) ? get_the_title( $p ) : 'প্রকাশনা';
	}

	/**
	 * Anything published?
	 *
	 * @return bool
	 */
	public static function any() {
		return (int) wp_count_posts( 'mh_pub' )->publish > 0;
	}

	/**
	 * Publication summary.
	 *
	 * @param WP_Post|int $p Post.
	 * @return array|null
	 */
	public static function data( $p ) {
		$p = get_post( $p );
		if ( ! $p || 'mh_pub' !== $p->post_type ) {
			return null;
		}
		$kinds  = self::kinds();
		$kind   = (string) mhp_meta( $p->ID, 'kind', 'other' );
		$file   = MHP_Front::private_path( (string) mhp_meta( $p->ID, 'file', '' ) );
		$d_on   = (bool) mhp_meta( $p->ID, 'digital_on', 0 ) && '' !== $file;
		$p_on   = (bool) mhp_meta( $p->ID, 'print_on', 0 ) && (float) mhp_meta( $p->ID, 'print_price', 0 ) > 0;
		$s_on   = (bool) mhp_meta( $p->ID, 'stock_on', 0 );
		$sample = (int) mhp_meta( $p->ID, 'sample_id', 0 );
		return array(
			'id'          => (int) $p->ID,
			'title'       => get_the_title( $p ),
			'excerpt'     => (string) $p->post_excerpt,
			'status'      => $p->post_status,
			'kind'        => isset( $kinds[ $kind ] ) ? $kind : 'other',
			'kind_label'  => isset( $kinds[ $kind ] ) ? $kinds[ $kind ] : $kinds['other'],
			'issue'       => (string) mhp_meta( $p->ID, 'issue', '' ),
			'author'      => (string) mhp_meta( $p->ID, 'author', '' ),
			'pages'       => (int) mhp_meta( $p->ID, 'pages', 0 ),
			'thumb'       => (int) get_post_thumbnail_id( $p ),
			'sample'      => $sample ? (string) wp_get_attachment_url( $sample ) : '',
			'digital'     => $d_on,
			'd_price'     => round( (float) mhp_meta( $p->ID, 'digital_price', 0 ), 2 ),
			'format'      => $file ? strtoupper( pathinfo( $file, PATHINFO_EXTENSION ) ) : '',
			'print'       => $p_on,
			'p_price'     => round( (float) mhp_meta( $p->ID, 'print_price', 0 ), 2 ),
			'stock_on'    => $s_on,
			'stock'       => $s_on ? max( 0, (int) mhp_meta( $p->ID, 'stock', 0 ) ) : null,
		);
	}

	/**
	 * Delivery fee for a zone and subtotal.
	 *
	 * @param string $zone     dhaka|outside.
	 * @param float  $subtotal Books total.
	 * @return float
	 */
	public static function ship_fee( $zone, $subtotal ) {
		$free = (float) mhp_opt( 'ship_free_over', 0 );
		if ( $free > 0 && $subtotal >= $free ) {
			return 0.0;
		}
		return round( max( 0, (float) mhp_opt( 'dhaka' === $zone ? 'ship_dhaka' : 'ship_outside', 'dhaka' === $zone ? 60 : 120 ) ), 2 );
	}

	/**
	 * Cash on delivery offered?
	 *
	 * @return bool
	 */
	public static function cod_on() {
		return (bool) mhp_opt( 'cod_on', 0 );
	}

	/* ---------------- shipments ---------------- */

	/**
	 * Shipment row.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public static function shipment( $id ) {
		global $wpdb;
		$t = MHP_Install::table( 'shipments' );
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $r ? $r : null;
	}

	/**
	 * A user's shipments.
	 *
	 * @param int $user_id User.
	 * @return array[]
	 */
	public static function of_user( $user_id ) {
		global $wpdb;
		$t = MHP_Install::table( 'shipments' );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE user_id = %d ORDER BY id DESC LIMIT 50", (int) $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Conditional status change (+ extra fields). True only for the call that changed the row.
	 *
	 * @param int      $id     Shipment.
	 * @param string   $to     New status.
	 * @param string[] $from   Allowed current statuses.
	 * @param array    $fields Extra columns (order_id, courier, tracking).
	 * @return bool
	 */
	public static function set_status( $id, $to, $from, $fields = array() ) {
		global $wpdb;
		if ( ! isset( self::statuses()[ $to ] ) || ! $from ) {
			return false;
		}
		$t    = MHP_Install::table( 'shipments' );
		$set  = 'status = %s, updated_at = %s';
		$args = array( $to, mhp_gmt() );
		foreach ( array( 'order_id' => '%d', 'courier' => '%s', 'tracking' => '%s' ) as $k => $f ) {
			if ( array_key_exists( $k, $fields ) ) {
				$set   .= ", {$k} = {$f}";
				$args[] = $fields[ $k ];
			}
		}
		$args[] = (int) $id;
		$in     = implode( ',', array_fill( 0, count( $from ), '%s' ) );
		$args   = array_merge( $args, array_values( $from ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		return 1 === (int) $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET {$set} WHERE id = %d AND status IN ({$in})", $args ) );
	}

	/**
	 * Append to a shipment's note.
	 *
	 * @param int    $id   Shipment.
	 * @param string $line Line.
	 */
	public static function log( $id, $line ) {
		global $wpdb;
		$s = self::shipment( $id );
		if ( ! $s ) {
			return;
		}
		$note = trim( (string) $s['note'] . "\n" . wp_date( 'Y-m-d H:i', null, mhp_tz() ) . ' ' . $line );
		$wpdb->update( MHP_Install::table( 'shipments' ), array( 'note' => mb_substr( $note, -1900 ) ), array( 'id' => (int) $id ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Take copies out of stock (one atomic UPDATE, so two buyers can't take the last copy twice).
	 *
	 * @param array $s Shipment.
	 * @return bool False when stock ran out.
	 */
	public static function take_stock( $s ) {
		global $wpdb;
		if ( ! mhp_meta( (int) $s['pub_id'], 'stock_on', 0 ) ) {
			return true;
		}
		$q = (int) $s['qty'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$n = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_value = meta_value - %d WHERE post_id = %d AND meta_key = '_mhp_stock' AND meta_value + 0 >= %d", $q, (int) $s['pub_id'], $q ) );
		wp_cache_delete( (int) $s['pub_id'], 'post_meta' );
		if ( 1 !== (int) $n ) {
			return false;
		}
		$wpdb->update( MHP_Install::table( 'shipments' ), array( 'stock_taken' => 1 ), array( 'id' => (int) $s['id'] ), array( '%d' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return true;
	}

	/**
	 * Put copies back (only once, only if they were taken).
	 *
	 * @param int $id Shipment.
	 */
	public static function restock( $id ) {
		global $wpdb;
		$s = self::shipment( $id );
		if ( ! $s ) {
			return;
		}
		$t = MHP_Install::table( 'shipments' );
		$n = $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET stock_taken = 0 WHERE id = %d AND stock_taken = 1", (int) $id ) ); // phpcs:ignore WordPress.DB
		if ( 1 === (int) $n ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_value = meta_value + %d WHERE post_id = %d AND meta_key = '_mhp_stock'", (int) $s['qty'], (int) $s['pub_id'] ) ); // phpcs:ignore WordPress.DB
			wp_cache_delete( (int) $s['pub_id'], 'post_meta' );
		}
	}

	/**
	 * Desk address for new print orders.
	 *
	 * @return string
	 */
	public static function desk_email() {
		$e = (string) mhp_opt( 'pub_email', '' );
		if ( ! is_email( $e ) ) {
			$e = (string) mhp_opt( 'notify_email', '' );
		}
		return is_email( $e ) ? $e : (string) get_option( 'admin_email' );
	}

	/**
	 * Tell the desk about a shipment.
	 *
	 * @param int    $id    Shipment.
	 * @param string $title Subject line.
	 */
	public static function notify_desk( $id, $title ) {
		$s = self::shipment( $id );
		if ( ! $s ) {
			return;
		}
		$body = $title . "\n\n" . get_the_title( (int) $s['pub_id'] ) . ' × ' . (int) $s['qty'] . "\nমোট: " . mhp_tk( $s['amount'] ) . ( 'cod' === $s['pay'] ? ' (ক্যাশ অন ডেলিভারি)' : '' ) . "\n" . $s['name'] . ', ' . $s['phone'] . "\n" . $s['address'] . ', ' . $s['district'] . "\n\n" . admin_url( 'admin.php?page=mhp-shipments&shipment=' . (int) $id );
		wp_mail( self::desk_email(), '[প্রকাশনা] ' . $title . ' #' . (int) $id, $body );
	}

	/**
	 * Email the reader on a status change.
	 *
	 * @param int $id Shipment.
	 */
	public static function notify_reader( $id ) {
		$s = self::shipment( $id );
		$u = $s ? get_userdata( (int) $s['user_id'] ) : null;
		if ( ! $u || ! is_email( $u->user_email ) ) {
			return;
		}
		$st   = self::statuses();
		$line = isset( $st[ $s['status'] ] ) ? $st[ $s['status'] ][0] : $s['status'];
		$body = "প্রিয় {$u->display_name},\n\nআপনার অর্ডার: " . get_the_title( (int) $s['pub_id'] ) . ' × ' . (int) $s['qty'] . "\nঅবস্থা: {$line}\n";
		if ( 'shipped' === $s['status'] && ( $s['courier'] || $s['tracking'] ) ) {
			$body .= 'কুরিয়ার: ' . $s['courier'] . ( $s['tracking'] ? ' · ট্র্যাকিং: ' . $s['tracking'] : '' ) . "\n";
		}
		$body .= "\nবিস্তারিত: " . mhp_url( 'me', '', array( 'tab' => 'purchases' ) ) . "\n\n— মিহা নিউজ";
		wp_mail( $u->user_email, 'আপনার বইয়ের অর্ডার: ' . $line, $body );
	}

	/* ---------------- checkout integration ---------------- */

	/**
	 * Digital edition as an order item.
	 *
	 * @param int $pub_id Publication.
	 * @return array
	 */
	public static function order_item_digital( $pub_id ) {
		$d    = self::data( $pub_id );
		$fail = array(
			'ok'     => false,
			'name'   => '',
			'price'  => 0,
			'reason' => 'এই সংস্করণটি পাওয়া যায়নি।',
			'post'   => null,
		);
		if ( ! $d || 'publish' !== $d['status'] || ! $d['digital'] ) {
			return $fail;
		}
		return array(
			'ok'     => true,
			'name'   => 'ডিজিটাল সংস্করণ: ' . $d['title'] . ( $d['issue'] ? ' (' . $d['issue'] . ')' : '' ),
			'price'  => $d['d_price'],
			'reason' => '',
			'post'   => get_post( $pub_id ),
			'note'   => $d['format'] . ' ফাইল · কেনার পর "আমার → কেনাকাটা" থেকে যতবার দরকার ডাউনলোড করতে পারবেন।',
		);
	}

	/**
	 * Printed copy (shipment) as an order item: price frozen on the shipment; owner or order manager only.
	 *
	 * @param int $sid Shipment.
	 * @return array
	 */
	public static function order_item_print( $sid ) {
		$s    = self::shipment( $sid );
		$fail = array(
			'ok'     => false,
			'name'   => '',
			'price'  => 0,
			'reason' => 'এই অর্ডারটি পাওয়া যায়নি।',
			'post'   => null,
		);
		if ( ! $s || ( (int) $s['user_id'] !== get_current_user_id() && ! mhp_can_shop() ) ) {
			return $fail;
		}
		if ( 'unpaid' !== $s['status'] ) {
			$why            = array(
				'cancelled' => 'অর্ডারটি বাতিল হয়েছে।',
				'cod'       => 'এটি ক্যাশ অন ডেলিভারি অর্ডার; টাকা বই হাতে পেয়ে দেবেন।',
				'returned'  => 'অর্ডারটি ফেরত এসেছে। নতুন করে অর্ডার করুন।',
			);
			$fail['reason'] = isset( $why[ $s['status'] ] ) ? $why[ $s['status'] ] : 'এই অর্ডারের পেমেন্ট আগেই হয়ে গেছে।';
			return $fail;
		}
		$p = get_post( (int) $s['pub_id'] );
		if ( ! $p || 'publish' !== $p->post_status ) {
			$fail['reason'] = 'বইটি এখন বিক্রি হচ্ছে না।';
			return $fail;
		}
		return array(
			'ok'     => true,
			'name'   => 'প্রিন্ট: ' . get_the_title( $p ) . ' × ' . mhp_bn( (int) $s['qty'] ),
			'price'  => round( (float) $s['amount'], 2 ),
			'reason' => '',
			'post'   => $p,
			'note'   => 'বই ' . mhp_tk( $s['unit_price'] * $s['qty'] ) . ' + ডেলিভারি ' . mhp_tk( $s['ship_fee'] ) . ' · ' . $s['name'] . ', ' . $s['district'],
		);
	}

	/**
	 * Is the shipment the user's?
	 *
	 * @param int $sid     Shipment.
	 * @param int $user_id User.
	 * @return bool
	 */
	public static function is_owner( $sid, $user_id ) {
		$s = self::shipment( $sid );
		return $s && (int) $s['user_id'] === (int) $user_id;
	}

	/**
	 * Paid order → shipment paid, stock taken, desk told. A payment for a shipment cancelled in the meantime is
	 * logged and the desk is asked to refund it.
	 *
	 * @param array $order Order row.
	 */
	public static function paid( $order ) {
		if ( ! $order || 'pubp' !== $order['item_type'] ) {
			return;
		}
		$sid = (int) $order['item_id'];
		if ( self::set_status( $sid, 'paid', array( 'unpaid' ), array( 'order_id' => (int) $order['id'] ) ) ) {
			$s = self::shipment( $sid );
			if ( ! self::take_stock( $s ) ) {
				self::log( $sid, 'পরিশোধের সময় স্টক শেষ: পাঠকের সঙ্গে কথা বলে অপেক্ষা বা রিফান্ড ঠিক করুন' );
				self::notify_desk( $sid, 'স্টক শেষ, কিন্তু পরিশোধ হয়েছে' );
				return;
			}
			self::notify_desk( $sid, 'নতুন প্রিন্ট অর্ডার (পরিশোধিত)' );
			return;
		}
		MHP_Orders::log( (int) $order['id'], 'চালানটি আগেই বাতিল/বদলে গিয়েছিল: রিফান্ড দিন' );
		wp_mail( self::desk_email(), '[প্রকাশনা] বাতিল অর্ডারে পরিশোধ এসেছে, রিফান্ড দিন', 'অর্ডার ' . $order['tran_id'] . ' (' . mhp_tk( $order['amount'] ) . ")\n" . admin_url( 'admin.php?page=mhp-orders' ) );
	}

	/**
	 * Refunded order → cancel its shipment (if not sent yet) and put the copies back.
	 *
	 * @param array $order Order row.
	 */
	public static function refunded( $order ) {
		if ( ! $order || 'pubp' !== $order['item_type'] ) {
			return;
		}
		$s = self::shipment( (int) $order['item_id'] );
		if ( ! $s || (int) $s['order_id'] !== (int) $order['id'] ) {
			return;
		}
		if ( self::set_status( (int) $s['id'], 'cancelled', array( 'unpaid', 'paid' ) ) ) {
			self::restock( (int) $s['id'] );
			self::notify_reader( (int) $s['id'] );
		} else {
			self::log( (int) $s['id'], 'অর্ডারটি রিফান্ড হয়েছে (চালান আগেই পাঠানো)' );
		}
	}

	/**
	 * Daily: cancel print orders left unpaid for 3 days.
	 */
	public static function cleanup() {
		global $wpdb;
		$t   = MHP_Install::table( 'shipments' );
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t} WHERE status = 'unpaid' AND created_at < %s LIMIT 200", mhp_gmt( time() - 3 * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB
		foreach ( (array) $ids as $id ) {
			if ( self::set_status( (int) $id, 'cancelled', array( 'unpaid' ) ) ) {
				self::log( (int) $id, '৩ দিন পরিশোধ না হওয়ায় নিজে থেকে বাতিল' );
			}
		}
	}

	/* ---------------- views ---------------- */

	/**
	 * Published publications.
	 *
	 * @param string $kind Kind ('' = all).
	 * @return array[]
	 */
	public static function list_pubs( $kind = '' ) {
		$args = array(
			'post_type'      => 'mh_pub',
			'post_status'    => 'publish',
			'posts_per_page' => 60,
			'orderby'        => array(
				'menu_order' => 'ASC',
				'date'       => 'DESC',
			),
			'no_found_rows'  => true,
		);
		if ( $kind ) {
			$args['meta_key']   = '_mhp_kind'; // phpcs:ignore WordPress.DB.SlowDBQuery
			$args['meta_value'] = $kind; // phpcs:ignore WordPress.DB.SlowDBQuery
		}
		return array_values( array_filter( array_map( array( __CLASS__, 'data' ), get_posts( $args ) ) ) );
	}

	/**
	 * List.
	 *
	 * @param string $id Unused.
	 */
	public static function render_list( $id ) {
		$kind = isset( $_GET['kind'] ) ? sanitize_key( wp_unslash( $_GET['kind'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$kind = isset( self::kinds()[ $kind ] ) ? $kind : '';
		mhp_template(
			'pubs',
			array(
				'items' => self::list_pubs( $kind ),
				'kind'  => $kind,
				'used'  => array_unique( wp_list_pluck( self::list_pubs(), 'kind' ) ),
			)
		);
	}

	/**
	 * One publication.
	 *
	 * @param string $id Id.
	 */
	public static function render_one( $id ) {
		$d = self::data( (int) $id );
		if ( ! $d || ( 'publish' !== $d['status'] && ! mhp_can_manage() ) ) {
			MHP_Front::not_found( 'প্রকাশনাটি পাওয়া যায়নি।', 'pubs' );
			return;
		}
		$uid  = get_current_user_id();
		$keep = $uid ? get_transient( 'mhp_po_' . $uid ) : false;
		if ( $keep ) {
			delete_transient( 'mhp_po_' . $uid );
		}
		$addr = $uid ? get_user_meta( $uid, 'mhp_ship_addr', true ) : array();
		mhp_template(
			'pub',
			array(
				'd'      => $d,
				'body'   => wp_kses_post( wpautop( (string) get_post_field( 'post_content', $d['id'] ) ) ),
				'owned'  => $uid && MHP_Access::has( $uid, 'pubd', $d['id'] ),
				'pay_ok' => MHP_Gateway_SSLCommerz::available(),
				'cod'    => self::cod_on(),
				'form'   => is_array( $keep ) ? $keep : ( is_array( $addr ) ? $addr : array() ),
				'phone'  => $uid ? (string) get_user_meta( $uid, 'meeha_phone', true ) : '',
			)
		);
	}

	/**
	 * Stream a digital edition: hub view "pub-file/<id>".
	 */
	public static function download() {
		if ( ! function_exists( 'meeha_campus_is_hub' ) || ! meeha_campus_is_hub() ) {
			return;
		}
		$v = meeha_campus_view();
		if ( 'pub-file' !== $v['view'] ) {
			return;
		}
		mhp_nocache();
		$pid = (int) $v['id'];
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( mhp_login_url( mhp_url( 'pub', $pid ) ) );
			exit;
		}
		if ( 'mh_pub' !== get_post_type( $pid ) || ( ! MHP_Access::has( get_current_user_id(), 'pubd', $pid ) && ! mhp_can_shop() ) ) {
			wp_die( esc_html( 'এই সংস্করণটি আপনার কেনা নেই।' ), 403 );
		}
		$path = MHP_Front::private_path( (string) mhp_meta( $pid, 'file', '' ) );
		if ( ! $path ) {
			wp_die( esc_html( 'ফাইলটি পাওয়া যায়নি। আমাদের জানান।' ), 404 );
		}
		$name = sanitize_file_name( (string) mhp_meta( $pid, 'file_name', basename( $path ) ) );
		$type = wp_check_filetype( $path );
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		status_header( 200 );
		header( 'Content-Type: ' . ( $type['type'] ? $type['type'] : 'application/octet-stream' ) );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/* ---------------- handlers ---------------- */

	/**
	 * Print order: validate the address, freeze the price, then go to payment (or place it as COD).
	 */
	public static function handle_order() {
		global $wpdb;
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by the dispatcher.
		$pid  = isset( $_POST['pub_id'] ) ? absint( $_POST['pub_id'] ) : 0;
		$in   = array(
			'qty'      => isset( $_POST['qty'] ) ? absint( meeha_prep_en_digits( sanitize_text_field( wp_unslash( $_POST['qty'] ) ) ) ) : 1,
			'name'     => isset( $_POST['ship_name'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_POST['ship_name'] ) ), 0, 100 ) : '',
			'phone'    => isset( $_POST['ship_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['ship_phone'] ) ) : '',
			'district' => isset( $_POST['district'] ) ? sanitize_text_field( wp_unslash( $_POST['district'] ) ) : '',
			'zone'     => isset( $_POST['zone'] ) && 'dhaka' === $_POST['zone'] ? 'dhaka' : 'outside',
			'address'  => isset( $_POST['address'] ) ? mb_substr( sanitize_textarea_field( wp_unslash( $_POST['address'] ) ), 0, 300 ) : '',
			'pay'      => isset( $_POST['pay'] ) && 'cod' === $_POST['pay'] ? 'cod' : 'online',
		);
		// phpcs:enable
		$uid  = get_current_user_id();
		$back = mhp_url( 'pub', $pid ) . '#mc-print';
		$d    = self::data( $pid );
		$err  = '';
		$digits = meeha_prep_en_digits( preg_replace( '/[\s\-]/', '', $in['phone'] ) );
		if ( ! $d || 'publish' !== $d['status'] || ! $d['print'] ) {
			$err = 'এই বইয়ের প্রিন্ট সংস্করণ এখন বিক্রি হচ্ছে না।';
		} elseif ( $in['qty'] < 1 || $in['qty'] > self::MAX_QTY ) {
			$err = 'একবারে ১ থেকে ' . mhp_bn( self::MAX_QTY ) . ' কপি অর্ডার করা যায়।';
		} elseif ( mb_strlen( $in['name'] ) < 2 ) {
			$err = 'প্রাপকের নাম লিখুন।';
		} elseif ( ! preg_match( '/^(\+?880|0)1[3-9]\d{8}$/', $digits ) ) {
			$err = '০১ দিয়ে শুরু হওয়া ১১ সংখ্যার সঠিক মোবাইল নম্বর দিন।';
		} elseif ( ! in_array( $in['district'], mhp_districts(), true ) ) {
			$err = 'জেলা বেছে নিন।';
		} elseif ( mb_strlen( $in['address'] ) < 10 ) {
			$err = 'পুরো ঠিকানা লিখুন (বাড়ি/রাস্তা, এলাকা, থানা)।';
		} elseif ( 'online' === $in['pay'] && ! MHP_Gateway_SSLCommerz::available() ) {
			$err = self::cod_on() ? 'অনলাইন পেমেন্ট এখন চালু নেই; ক্যাশ অন ডেলিভারি বেছে নিন।' : 'অনলাইন পেমেন্ট এখনো চালু হয়নি।';
		} elseif ( 'cod' === $in['pay'] && ! self::cod_on() ) {
			$err = 'ক্যাশ অন ডেলিভারি এখন চালু নেই।';
		} elseif ( $d['stock_on'] && $d['stock'] < $in['qty'] ) {
			$err = $d['stock'] > 0 ? 'স্টকে আছে মাত্র ' . mhp_bn( $d['stock'] ) . ' কপি।' : 'স্টক শেষ।';
		}
		if ( ! $err ) {
			$t    = MHP_Install::table( 'shipments' );
			$open = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE user_id = %d AND status IN ('unpaid','cod')", $uid ) ); // phpcs:ignore WordPress.DB
			if ( $open >= self::MAX_OPEN ) {
				$err = 'আপনার ' . mhp_bn( $open ) . 'টি অর্ডার এখনো খোলা আছে (পেমেন্ট বাকি বা ক্যাশ অন ডেলিভারি)। আগে সেগুলো শেষ বা বাতিল করুন।';
			} elseif ( 'cod' === $in['pay'] && (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE phone = %s AND status IN ('unpaid','cod')", substr( $digits, 0, 20 ) ) ) >= self::MAX_OPEN ) { // phpcs:ignore WordPress.DB
				$err = 'এই ফোন নম্বরে আগের কয়েকটি অর্ডার এখনো খোলা আছে। আগে সেগুলো শেষ হোক, অথবা অনলাইনে পেমেন্ট করুন।';
			} elseif ( ! mhp_rate_ok( 'pub_order', 6, HOUR_IN_SECONDS ) ) {
				$err = 'অল্প সময়ে অনেক অর্ডার হয়েছে। একটু পরে আবার চেষ্টা করুন।';
			}
		}
		if ( $err ) {
			set_transient( 'mhp_po_' . $uid, $in, 10 * MINUTE_IN_SECONDS );
			mhp_flash( $err, 'error' );
			wp_safe_redirect( $back );
			exit;
		}
		if ( 'ঢাকা' !== $in['district'] ) {
			$in['zone'] = 'outside';
		}
		$sub = round( $d['p_price'] * $in['qty'], 2 );
		$fee = self::ship_fee( $in['zone'], $sub );
		$now = mhp_gmt();
		$ok  = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			MHP_Install::table( 'shipments' ),
			array(
				'user_id'    => $uid,
				'pub_id'     => $pid,
				'qty'        => $in['qty'],
				'unit_price' => $d['p_price'],
				'ship_fee'   => $fee,
				'amount'     => $sub + $fee,
				'pay'        => $in['pay'],
				'status'     => 'unpaid',
				'zone'       => $in['zone'],
				'name'       => $in['name'],
				'phone'      => substr( $digits, 0, 20 ),
				'district'   => $in['district'],
				'address'    => $in['address'],
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%d', '%d', '%d', '%f', '%f', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( ! $ok ) {
			mhp_flash( 'অর্ডার তৈরি করা যায়নি। আবার চেষ্টা করুন।', 'error' );
			wp_safe_redirect( $back );
			exit;
		}
		$sid = (int) $wpdb->insert_id;
		// Two orders sent at the same moment can both pass the open-order check above: keep the oldest ones only.
		$open_ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t} WHERE user_id = %d AND status IN ('unpaid','cod') ORDER BY id ASC", $uid ) ) ); // phpcs:ignore WordPress.DB
		if ( array_search( $sid, $open_ids, true ) >= self::MAX_OPEN ) {
			self::set_status( $sid, 'cancelled', array( 'unpaid' ) );
			mhp_flash( 'আপনার কয়েকটি অর্ডার এখনো খোলা আছে। আগে সেগুলো শেষ বা বাতিল করুন।', 'error' );
			wp_safe_redirect( $back );
			exit;
		}
		update_user_meta(
			$uid,
			'mhp_ship_addr',
			array(
				'name'     => $in['name'],
				'phone'    => $digits,
				'district' => $in['district'],
				'zone'     => $in['zone'],
				'address'  => $in['address'],
			)
		);
		if ( ! get_user_meta( $uid, 'meeha_phone', true ) ) {
			update_user_meta( $uid, 'meeha_phone', $digits );
		}
		if ( 'cod' === $in['pay'] ) {
			$s = self::shipment( $sid );
			if ( ! self::take_stock( $s ) ) {
				self::set_status( $sid, 'cancelled', array( 'unpaid' ) );
				self::log( $sid, 'স্টক শেষ হওয়ায় বাতিল' );
				mhp_flash( 'দুঃখিত, এইমাত্র স্টক শেষ হয়ে গেছে।', 'error' );
				wp_safe_redirect( $back );
				exit;
			}
			self::set_status( $sid, 'cod', array( 'unpaid' ) );
			self::notify_desk( $sid, 'নতুন প্রিন্ট অর্ডার (ক্যাশ অন ডেলিভারি)' );
			mhp_flash( 'অর্ডার গৃহীত হয়েছে। নিশ্চিত করতে আমাদের ডেস্ক ফোন করবে; বই হাতে পেয়ে ' . mhp_tk( $sub + $fee ) . ' পরিশোধ করবেন।' );
			wp_safe_redirect( mhp_url( 'me', '', array( 'tab' => 'purchases' ) ) );
			exit;
		}
		wp_safe_redirect( mhp_url( 'checkout', 'pubp-' . $sid ) );
		exit;
	}

	/**
	 * Reader cancels an unpaid or COD order (not once it is paid or sent).
	 */
	public static function handle_cancel() {
		$sid = isset( $_POST['shipment_id'] ) ? absint( $_POST['shipment_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by the dispatcher.
		$s   = self::shipment( $sid );
		$go  = mhp_url( 'me', '', array( 'tab' => 'purchases' ) );
		if ( ! $s || (int) $s['user_id'] !== get_current_user_id() ) {
			mhp_flash( 'অর্ডারটি পাওয়া যায়নি।', 'error' );
			wp_safe_redirect( $go );
			exit;
		}
		if ( 'unpaid' === $s['status'] ) {
			// A payment may be under way at the gateway: wait for it to finish or time out, so nobody pays for a cancelled order.
			global $wpdb;
			$t    = MHP_Install::table( 'orders' );
			$busy = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE item_type = 'pubp' AND item_id = %d AND status = 'pending' AND created_at > %s", $sid, mhp_gmt( time() - HOUR_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB
			if ( $busy ) {
				mhp_flash( 'এই অর্ডারের একটি পেমেন্ট চলমান। পেমেন্ট শেষ বা বাতিল হওয়ার কিছুক্ষণ পর (সর্বোচ্চ এক ঘণ্টা) আবার চেষ্টা করুন।', 'error' );
				wp_safe_redirect( $go );
				exit;
			}
		}
		if ( self::set_status( $sid, 'cancelled', array( 'unpaid', 'cod' ) ) ) {
			self::restock( $sid );
			self::log( $sid, 'পাঠক বাতিল করেছেন' );
			if ( 'cod' === $s['status'] ) {
				self::notify_desk( $sid, 'ক্যাশ অন ডেলিভারি অর্ডার বাতিল করেছেন পাঠক' );
			}
			mhp_flash( 'অর্ডার বাতিল হয়েছে।' );
		} else {
			mhp_flash( 'এই অবস্থায় অর্ডারটি আর বাতিল করা যায় না। দরকার হলে আমাদের জানান।', 'error' );
		}
		wp_safe_redirect( $go );
		exit;
	}
}
