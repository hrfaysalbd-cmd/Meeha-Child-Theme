<?php
/**
 * CV studio: readers fill in their details once, pick a design and get an A4 CV to print or save as PDF.
 *
 *   mh_cv_tpl            a design (HTML + CSS in a small Mustache subset, see data/cv-templates.php)
 *   {prefix}mhp_cvs      one row per reader CV (data is JSON; token is the share/print link)
 *
 * Rules: free designs (price 0) are one page and only their owner can open the link; a bought design (item
 * type "cvtpl") gives its page count and a public share link. A paid design that is not bought can be tried
 * in the editor with a watermark, but not printed or shared. A design's HTML and CSS only ever run inside its
 * own document (a sandboxed preview frame or the standalone print page with a strict CSP), never in the hub.
 *
 * @package meeha-prep
 */

defined( 'ABSPATH' ) || exit;

/**
 * CV studio.
 */
class MHP_CV {

	const MAX_CVS   = 10;
	const MAX_PAGES = 3;

	/**
	 * Single fields: name => max length.
	 *
	 * @var array
	 */
	const SCALARS = array(
		'name'        => 100,
		'headline'    => 150,
		'phone'       => 40,
		'email'       => 100,
		'address'     => 250,
		'website'     => 200,
		'summary'     => 1500,
		'declaration' => 600,
	);

	/**
	 * Multi-line single fields.
	 *
	 * @var string[]
	 */
	const MULTILINE = array( 'summary', 'declaration', 'address' );

	/**
	 * Personal details: field => max length.
	 *
	 * @var array
	 */
	const PERSONAL = array(
		'father'      => 100,
		'mother'      => 100,
		'dob'         => 40,
		'nationality' => 40,
		'religion'    => 40,
		'marital'     => 40,
		'permanent'   => 250,
	);

	/**
	 * Repeating lists: max rows and field => max length. "lines" lists are typed one per line.
	 *
	 * @var array
	 */
	const LISTS = array(
		'experience'   => array(
			'max'    => 12,
			'fields' => array(
				'role'    => 120,
				'org'     => 150,
				'place'   => 100,
				'from'    => 30,
				'to'      => 30,
				'details' => 800,
			),
		),
		'education'    => array(
			'max'    => 10,
			'fields' => array(
				'degree' => 150,
				'inst'   => 200,
				'year'   => 20,
				'result' => 40,
			),
		),
		'skills'       => array(
			'max'    => 30,
			'lines'  => 'name',
			'fields' => array( 'name' => 60 ),
		),
		'languages'    => array(
			'max'    => 8,
			'fields' => array(
				'name'  => 40,
				'level' => 60,
			),
		),
		'training'     => array(
			'max'    => 12,
			'fields' => array(
				'name' => 150,
				'org'  => 150,
				'year' => 20,
			),
		),
		'achievements' => array(
			'max'    => 15,
			'lines'  => 'text',
			'fields' => array( 'text' => 300 ),
		),
		'references'   => array(
			'max'    => 4,
			'fields' => array(
				'name'    => 100,
				'role'    => 150,
				'contact' => 150,
			),
		),
	);

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ), 6 );
		add_filter( 'meeha_campus_views', array( __CLASS__, 'views' ) );
		add_filter( 'meeha_campus_tabs', array( __CLASS__, 'tabs' ), 10, 2 );
		add_filter( 'mhp_hub_actions', array( __CLASS__, 'actions' ) );
		add_action( 'template_redirect', array( __CLASS__, 'standalone' ), 5 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 21 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_seed' ), 21 );
		add_action( 'mhp_hub_action_cv_create', array( __CLASS__, 'handle_create' ) );
		add_action( 'mhp_hub_action_cv_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'mhp_hub_action_cv_share', array( __CLASS__, 'handle_share' ) );
		add_action( 'mhp_hub_action_cv_delete', array( __CLASS__, 'handle_delete' ) );
	}

	/**
	 * Post type for designs.
	 */
	public static function register() {
		register_post_type(
			'mh_cv_tpl',
			array(
				'labels'              => array(
					'name'          => 'সিভি টেমপ্লেট',
					'singular_name' => 'সিভি টেমপ্লেট',
					'menu_name'     => 'সিভি টেমপ্লেট',
					'add_new'       => 'নতুন যোগ',
					'add_new_item'  => 'নতুন সিভি টেমপ্লেট',
					'edit_item'     => 'সিভি টেমপ্লেট সম্পাদনা',
					'all_items'     => 'সিভি টেমপ্লেট',
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
				'supports'            => array( 'title', 'excerpt', 'page-attributes' ),
			)
		);
	}

	/**
	 * Hub actions this class handles.
	 *
	 * @param string[] $a Actions.
	 * @return string[]
	 */
	public static function actions( $a ) {
		return array_merge( $a, array( 'cv_create', 'cv_save', 'cv_share', 'cv_delete' ) );
	}

	/**
	 * Views.
	 *
	 * @param array $views Views.
	 * @return array
	 */
	public static function views( $views ) {
		$views['cv']      = array(
			'title'  => 'সিভি স্টুডিও',
			'tab'    => 'cv',
			'root'   => true,
			'render' => array( __CLASS__, 'render_studio' ),
		);
		$views['cv-edit'] = array(
			'title'    => 'সিভি সম্পাদনা',
			'tab'      => 'cv',
			'back'     => 'cv',
			'needs_id' => true,
			'nocache'  => true,
			'noindex'  => true,
			'bar'      => false,
			'render'   => array( __CLASS__, 'render_edit' ),
		);
		// Printed by standalone() before the theme starts; registered so the router knows the path.
		$views['cv-view'] = array(
			'title'    => 'সিভি',
			'tab'      => 'cv',
			'back'     => 'cv',
			'needs_id' => true,
			'nocache'  => true,
			'noindex'  => true,
			'render'   => '__return_null',
		);
		return $views;
	}

	/**
	 * Masthead tab.
	 *
	 * @param array  $tabs Tabs.
	 * @param string $cur  Current view.
	 * @return array
	 */
	public static function tabs( $tabs, $cur ) {
		$tabs[] = array( 'সিভি', mhp_url( 'cv' ), in_array( $cur, array( 'cv', 'cv-edit' ), true ) );
		return $tabs;
	}

	/* ------------------------------------------------------------------
	 * Designs
	 * ---------------------------------------------------------------- */

	/**
	 * One design.
	 *
	 * @param int  $id        Post id.
	 * @param bool $published Only published.
	 * @return array|null
	 */
	public static function tpl( $id, $published = true ) {
		$p = get_post( (int) $id );
		if ( ! $p || 'mh_cv_tpl' !== $p->post_type || ( $published && 'publish' !== $p->post_status ) ) {
			return null;
		}
		return array(
			'id'     => (int) $p->ID,
			'key'    => (string) mhp_meta( $p->ID, 'tpl_key', '' ),
			'name'   => get_the_title( $p ),
			'desc'   => (string) $p->post_excerpt,
			'price'  => max( 0, round( (float) mhp_meta( $p->ID, 'price', 0 ), 2 ) ),
			'pages'  => self::pages_of( $p->ID ),
			'html'   => (string) mhp_meta( $p->ID, 'html', '' ),
			'css'    => (string) mhp_meta( $p->ID, 'css', '' ),
			'status' => $p->post_status,
		);
	}

	/**
	 * Page count of a design (free designs are always one page).
	 *
	 * @param int $id Post id.
	 * @return int
	 */
	public static function pages_of( $id ) {
		if ( (float) mhp_meta( $id, 'price', 0 ) <= 0 ) {
			return 1;
		}
		return max( 1, min( self::MAX_PAGES, (int) mhp_meta( $id, 'pages', 1 ) ) );
	}

	/**
	 * Published designs in display order.
	 *
	 * @return array[]
	 */
	public static function templates() {
		$out = array();
		foreach ( get_posts(
			array(
				'post_type'      => 'mh_cv_tpl',
				'post_status'    => 'publish',
				'posts_per_page' => 60,
				'orderby'        => array(
					'menu_order' => 'ASC',
					'ID'         => 'ASC',
				),
				'no_found_rows'  => true,
			)
		) as $p ) {
			$t = self::tpl( $p->ID );
			if ( $t ) {
				$out[] = $t;
			}
		}
		return $out;
	}

	/**
	 * Has the user the right to use a design without a watermark?
	 *
	 * @param int   $user_id User.
	 * @param array $tpl     Design.
	 * @return bool
	 */
	public static function owns( $user_id, $tpl ) {
		if ( $tpl['price'] <= 0 ) {
			return true;
		}
		return MHP_Access::has( $user_id, 'cvtpl', $tpl['id'] ) || ( $user_id && user_can( $user_id, 'mhp_manage' ) );
	}

	/**
	 * May the user's CV in this design have a public link?
	 *
	 * @param int   $user_id User.
	 * @param array $tpl     Design.
	 * @return bool
	 */
	public static function can_share( $user_id, $tpl ) {
		return $tpl['price'] > 0 && self::owns( $user_id, $tpl );
	}

	/* ------------------------------------------------------------------
	 * Reader CVs
	 * ---------------------------------------------------------------- */

	/**
	 * CV row by id.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$t = MHP_Install::table( 'cvs' );
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $r ? self::hydrate( $r ) : null;
	}

	/**
	 * CV row by share token.
	 *
	 * @param string $token Token.
	 * @return array|null
	 */
	public static function by_token( $token ) {
		global $wpdb;
		$token = preg_replace( '/[^A-Za-z0-9]/', '', (string) $token );
		if ( strlen( $token ) < 20 ) {
			return null;
		}
		$t = MHP_Install::table( 'cvs' );
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE token = %s", $token ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $r ? self::hydrate( $r ) : null;
	}

	/**
	 * A user's CVs, newest first.
	 *
	 * @param int $user_id User.
	 * @return array[]
	 */
	public static function of_user( $user_id ) {
		global $wpdb;
		$t    = MHP_Install::table( 'cvs' );
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE user_id = %d ORDER BY updated_at DESC, id DESC LIMIT %d", (int) $user_id, self::MAX_CVS * 2 ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array_map( array( __CLASS__, 'hydrate' ), $rows );
	}

	/**
	 * Decode a row.
	 *
	 * @param array $r Row.
	 * @return array
	 */
	private static function hydrate( $r ) {
		$d             = json_decode( (string) $r['data'], true );
		$r['data']      = is_array( $d ) ? $d : array();
		$r['id']        = (int) $r['id'];
		$r['user_id']   = (int) $r['user_id'];
		$r['tpl_id']    = (int) $r['tpl_id'];
		$r['is_public'] = (int) $r['is_public'];
		return $r;
	}

	/**
	 * The current user's CV or null.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public static function own( $id ) {
		$cv = self::get( $id );
		return ( $cv && get_current_user_id() && $cv['user_id'] === get_current_user_id() ) ? $cv : null;
	}

	/**
	 * Clean posted CV data (the browser sends cv[...] fields).
	 *
	 * @param mixed $raw Raw array.
	 * @return array
	 */
	public static function clean( $raw ) {
		$raw = is_array( $raw ) ? $raw : array();
		$out = array();
		foreach ( self::SCALARS as $k => $max ) {
			$v = isset( $raw[ $k ] ) && is_scalar( $raw[ $k ] ) ? (string) $raw[ $k ] : '';
			$v = in_array( $k, self::MULTILINE, true ) ? sanitize_textarea_field( $v ) : sanitize_text_field( $v );
			if ( 'website' === $k && '' !== $v ) {
				if ( ! preg_match( '#^https?://#i', $v ) ) {
					$v = 'https://' . $v;
				}
				$v = esc_url_raw( $v, array( 'http', 'https' ) );
			}
			$out[ $k ] = mb_substr( trim( $v ), 0, $max );
		}
		$out['personal'] = array();
		$p               = isset( $raw['personal'] ) && is_array( $raw['personal'] ) ? $raw['personal'] : array();
		foreach ( self::PERSONAL as $k => $max ) {
			$v                     = isset( $p[ $k ] ) && is_scalar( $p[ $k ] ) ? (string) $p[ $k ] : '';
			$out['personal'][ $k ] = mb_substr( trim( 'permanent' === $k ? sanitize_textarea_field( $v ) : sanitize_text_field( $v ) ), 0, $max );
		}
		foreach ( self::LISTS as $list => $def ) {
			$rows = isset( $raw[ $list ] ) ? $raw[ $list ] : array();
			if ( ! empty( $def['lines'] ) ) {
				// Typed one per line in a textarea.
				$rows = is_scalar( $rows ) ? array_map(
					function ( $l ) use ( $def ) {
						return array( $def['lines'] => $l );
					},
					preg_split( '/\r\n|\r|\n/', (string) $rows )
				) : ( is_array( $rows ) ? $rows : array() );
			}
			$out[ $list ] = array();
			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$clean = array();
				$any   = false;
				foreach ( $def['fields'] as $f => $max ) {
					$v           = isset( $row[ $f ] ) && is_scalar( $row[ $f ] ) ? (string) $row[ $f ] : '';
					$v           = 'details' === $f ? sanitize_textarea_field( $v ) : sanitize_text_field( $v );
					$clean[ $f ] = mb_substr( trim( $v ), 0, $max );
					$any         = $any || '' !== $clean[ $f ];
				}
				if ( $any ) {
					$out[ $list ][] = $clean;
				}
				if ( count( $out[ $list ] ) >= $def['max'] ) {
					break;
				}
			}
		}
		$out['lang'] = ( isset( $raw['lang'] ) && 'en' === $raw['lang'] ) ? 'en' : 'bn';
		return $out;
	}

	/**
	 * Labels printed by the designs ({{L.x}}).
	 *
	 * @param string $lang bn|en.
	 * @return array
	 */
	public static function labels( $lang ) {
		$bn = array(
			'phone'        => 'ফোন',
			'email'        => 'ইমেইল',
			'address'      => 'ঠিকানা',
			'website'      => 'ওয়েবসাইট',
			'your_name'    => 'আপনার নাম',
			'summary'      => 'সংক্ষিপ্ত পরিচিতি',
			'experience'   => 'কর্ম-অভিজ্ঞতা',
			'result'       => 'ফল',
			'education'    => 'শিক্ষাগত যোগ্যতা',
			'degree'       => 'পরীক্ষা/ডিগ্রি',
			'institution'  => 'প্রতিষ্ঠান',
			'year'         => 'সাল',
			'skills'       => 'দক্ষতা',
			'languages'    => 'ভাষা',
			'training'     => 'প্রশিক্ষণ ও সনদ',
			'achievements' => 'অর্জন',
			'personal'     => 'ব্যক্তিগত তথ্য',
			'father'       => 'পিতার নাম',
			'mother'       => 'মাতার নাম',
			'dob'          => 'জন্ম তারিখ',
			'nationality'  => 'জাতীয়তা',
			'religion'     => 'ধর্ম',
			'marital'      => 'বৈবাহিক অবস্থা',
			'permanent'    => 'স্থায়ী ঠিকানা',
			'references'   => 'রেফারেন্স',
			'declaration'  => 'ঘোষণা',
			'signature'    => 'স্বাক্ষর',
			'cv_title'     => 'জীবনবৃত্তান্ত',
		);
		$en = array(
			'phone'        => 'Phone',
			'email'        => 'Email',
			'address'      => 'Address',
			'website'      => 'Website',
			'your_name'    => 'Your Name',
			'summary'      => 'Profile',
			'experience'   => 'Work Experience',
			'result'       => 'Result',
			'education'    => 'Education',
			'degree'       => 'Degree',
			'institution'  => 'Institution',
			'year'         => 'Year',
			'skills'       => 'Skills',
			'languages'    => 'Languages',
			'training'     => 'Training & Certification',
			'achievements' => 'Achievements',
			'personal'     => 'Personal Details',
			'father'       => "Father's Name",
			'mother'       => "Mother's Name",
			'dob'          => 'Date of Birth',
			'nationality'  => 'Nationality',
			'religion'     => 'Religion',
			'marital'      => 'Marital Status',
			'permanent'    => 'Permanent Address',
			'references'   => 'References',
			'declaration'  => 'Declaration',
			'signature'    => 'Signature',
			'cv_title'     => 'CURRICULUM VITAE',
		);
		return 'en' === $lang ? $en : $bn;
	}

	/**
	 * Default declaration text.
	 *
	 * @param string $lang bn|en.
	 * @return string
	 */
	public static function default_declaration( $lang ) {
		return 'en' === $lang ? 'I hereby declare that the information given above is true and correct to the best of my knowledge.' : 'আমি এই মর্মে ঘোষণা করছি যে, ওপরে দেওয়া সব তথ্য আমার জানা মতে সত্য ও সঠিক।';
	}

	/**
	 * Placeholder content for design previews (field descriptions, not a made-up person).
	 *
	 * @return array
	 */
	public static function sample() {
		return array(
			'lang'         => 'bn',
			'name'         => 'আপনার নাম',
			'headline'     => 'যে পদে আবেদন করছেন',
			'phone'        => '০১XXXXXXXXX',
			'email'        => 'apnar@email.com',
			'address'      => 'বর্তমান ঠিকানা',
			'website'      => '',
			'summary'      => 'নিজের সম্পর্কে দুই-তিন লাইন: কোন কাজে দক্ষ, কী ধরনের দায়িত্ব নিতে চান।',
			'declaration'  => self::default_declaration( 'bn' ),
			'personal'     => array(
				'father'      => 'পিতার নাম',
				'mother'      => 'মাতার নাম',
				'dob'         => 'দিন-মাস-বছর',
				'nationality' => 'বাংলাদেশি',
				'religion'    => '',
				'marital'     => '',
				'permanent'   => 'গ্রাম, ডাকঘর, উপজেলা, জেলা',
			),
			'experience'   => array(
				array(
					'role'    => 'পদবি',
					'org'     => 'প্রতিষ্ঠানের নাম',
					'place'   => 'জেলা',
					'from'    => 'শুরু',
					'to'      => 'বর্তমান',
					'details' => 'দায়িত্ব ও অর্জনের সংক্ষিপ্ত বিবরণ।',
				),
				array(
					'role'    => 'আগের পদবি',
					'org'     => 'আগের প্রতিষ্ঠান',
					'place'   => '',
					'from'    => 'শুরু',
					'to'      => 'শেষ',
					'details' => '',
				),
			),
			'education'    => array(
				array(
					'degree' => 'স্নাতক',
					'inst'   => 'বিশ্ববিদ্যালয়ের নাম',
					'year'   => 'সাল',
					'result' => 'সিজিপিএ',
				),
				array(
					'degree' => 'এইচএসসি',
					'inst'   => 'কলেজের নাম',
					'year'   => 'সাল',
					'result' => 'জিপিএ',
				),
				array(
					'degree' => 'এসএসসি',
					'inst'   => 'বিদ্যালয়ের নাম',
					'year'   => 'সাল',
					'result' => 'জিপিএ',
				),
			),
			'skills'       => array( array( 'name' => 'দক্ষতা ১' ), array( 'name' => 'দক্ষতা ২' ), array( 'name' => 'দক্ষতা ৩' ), array( 'name' => 'কম্পিউটার' ) ),
			'languages'    => array(
				array(
					'name'  => 'বাংলা',
					'level' => 'মাতৃভাষা',
				),
				array(
					'name'  => 'ইংরেজি',
					'level' => 'দক্ষতার মাত্রা',
				),
			),
			'training'     => array(
				array(
					'name' => 'প্রশিক্ষণের নাম',
					'org'  => 'আয়োজক',
					'year' => 'সাল',
				),
			),
			'achievements' => array( array( 'text' => 'উল্লেখযোগ্য অর্জন' ) ),
			'references'   => array(
				array(
					'name'    => 'রেফারেন্সের নাম',
					'role'    => 'পদবি ও প্রতিষ্ঠান',
					'contact' => 'ফোন/ইমেইল',
				),
			),
		);
	}

	/**
	 * Neutral silhouette for previews.
	 *
	 * @return string data: URI.
	 */
	public static function sample_photo() {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 140"><rect width="120" height="140" fill="#dfe5ec"/><circle cx="60" cy="52" r="26" fill="#b7c2ce"/><path d="M14 140c4-30 24-46 46-46s42 16 46 46z" fill="#b7c2ce"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Values a design sees: the data, its labels, computed flags and the photo.
	 *
	 * @param array  $data  Clean data.
	 * @param string $photo Photo URI or ''.
	 * @return array
	 */
	public static function context( $data, $photo = '' ) {
		$lang = isset( $data['lang'] ) && 'en' === $data['lang'] ? 'en' : 'bn';
		$ctx  = $data;
		foreach ( array_keys( self::LISTS ) as $l ) {
			$ctx[ $l ]          = isset( $data[ $l ] ) && is_array( $data[ $l ] ) ? array_values( $data[ $l ] ) : array();
			$ctx[ 'has_' . $l ] = count( $ctx[ $l ] ) > 0;
		}
		$personal            = isset( $data['personal'] ) && is_array( $data['personal'] ) ? $data['personal'] : array();
		$ctx['personal']     = $personal;
		$ctx['has_personal'] = '' !== implode( '', array_map( 'strval', $personal ) );
		$ctx['photo']        = (string) $photo;
		$ctx['L']            = self::labels( $lang );
		$ctx['lang']         = $lang;
		return $ctx;
	}

	/* ------------------------------------------------------------------
	 * Template language (same rules as assets/js/cv.js)
	 * ---------------------------------------------------------------- */

	/**
	 * Render a design.
	 *
	 * @param string $tpl HTML with {{tags}}.
	 * @param array  $ctx Context.
	 * @return string
	 */
	public static function render( $tpl, $ctx ) {
		$parts = preg_split( '/(\{\{\s*[#^\/]?\s*[A-Za-z0-9_.]+\s*\}\})/', (string) $tpl, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
		$i     = 0;
		$tree  = self::build( $parts, $i, '', 0 );
		return self::run( $tree, array( $ctx ) );
	}

	/**
	 * Parse tokens into a tree.
	 *
	 * @param array  $parts Tokens.
	 * @param int    $i     Cursor.
	 * @param string $close Section being closed.
	 * @param int    $depth Depth.
	 * @return array
	 */
	private static function build( $parts, &$i, $close, $depth ) {
		$out = array();
		$n   = count( $parts );
		while ( $i < $n ) {
			$p = $parts[ $i++ ];
			if ( preg_match( '/^\{\{\s*([#^\/]?)\s*([A-Za-z0-9_.]+)\s*\}\}$/', $p, $m ) ) {
				if ( '/' === $m[1] ) {
					if ( $m[2] === $close ) {
						return $out;
					}
					continue; // Stray close tag.
				}
				if ( '' === $m[1] ) {
					$out[] = array( 'v', $m[2] );
					continue;
				}
				$out[] = array( $m[1], $m[2], $depth < 12 ? self::build( $parts, $i, $m[2], $depth + 1 ) : array() );
				continue;
			}
			$out[] = array( 't', $p );
		}
		return $out;
	}

	/**
	 * Render a tree.
	 *
	 * @param array $nodes Nodes.
	 * @param array $stack Context stack.
	 * @return string
	 */
	private static function run( $nodes, $stack ) {
		$html = '';
		foreach ( $nodes as $nd ) {
			if ( 't' === $nd[0] ) {
				$html .= $nd[1];
				continue;
			}
			$v = self::lookup( $nd[1], $stack );
			if ( 'v' === $nd[0] ) {
				$html .= ( is_string( $v ) || is_int( $v ) || is_float( $v ) ) ? htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ) : '';
			} elseif ( '#' === $nd[0] ) {
				if ( is_array( $v ) && self::is_list( $v ) ) {
					foreach ( $v as $row ) {
						$s     = $stack;
						$s[]   = $row;
						$html .= self::run( $nd[2], $s );
					}
				} elseif ( self::truthy( $v ) ) {
					$s = $stack;
					if ( is_array( $v ) ) {
						$s[] = $v;
					}
					$html .= self::run( $nd[2], $s );
				}
			} elseif ( '^' === $nd[0] && ! self::truthy( $v ) ) {
				$html .= self::run( $nd[2], $stack );
			}
		}
		return $html;
	}

	/**
	 * Find a value ("." = current row; dot paths walk into arrays).
	 *
	 * @param string $name  Name.
	 * @param array  $stack Stack.
	 * @return mixed
	 */
	private static function lookup( $name, $stack ) {
		if ( '.' === $name ) {
			return end( $stack );
		}
		$path = explode( '.', $name );
		$val  = null;
		for ( $k = count( $stack ) - 1; $k >= 0; $k-- ) {
			if ( is_array( $stack[ $k ] ) && array_key_exists( $path[0], $stack[ $k ] ) ) {
				$val = $stack[ $k ][ $path[0] ];
				break;
			}
		}
		$count = count( $path );
		for ( $j = 1; $j < $count; $j++ ) {
			$val = ( is_array( $val ) && array_key_exists( $path[ $j ], $val ) ) ? $val[ $path[ $j ] ] : null;
		}
		return $val;
	}

	/**
	 * Is it a 0..n list?
	 *
	 * @param array $a Array.
	 * @return bool
	 */
	private static function is_list( $a ) {
		return array() === $a || array_keys( $a ) === range( 0, count( $a ) - 1 );
	}

	/**
	 * Truthiness.
	 *
	 * @param mixed $v Value.
	 * @return bool
	 */
	private static function truthy( $v ) {
		if ( is_array( $v ) ) {
			return count( $v ) > 0;
		}
		if ( is_string( $v ) ) {
			return '' !== trim( $v );
		}
		return (bool) $v;
	}

	/* ------------------------------------------------------------------
	 * Documents
	 * ---------------------------------------------------------------- */

	/**
	 * @font-face rules for the theme's Bangla fonts (same origin).
	 *
	 * @return string
	 */
	public static function font_css() {
		$dir = get_template_directory() . '/assets/fonts/';
		$uri = get_template_directory_uri() . '/assets/fonts/';
		$css = '';
		foreach ( array(
			array( 'Meeha Sans', 'meeha-sans-bn-400.woff2', '400' ),
			array( 'Meeha Sans', 'meeha-sans-bn-600.woff2', '600 700' ),
			array( 'Meeha Serif', 'meeha-serif-bn-700.woff2', '400 700' ),
		) as $f ) {
			if ( is_readable( $dir . $f[1] ) ) {
				$css .= '@font-face{font-family:"' . $f[0] . '";src:url(' . esc_url( $uri . $f[1] ) . ') format("woff2");font-weight:' . $f[2] . ';font-display:swap}';
			}
		}
		return (string) apply_filters( 'mhp_cv_font_css', $css );
	}

	/**
	 * CSS around every design (page, preview frame, page guides, watermark).
	 *
	 * @param int $pages Page limit.
	 * @return string
	 */
	public static function frame_css( $pages ) {
		$h = max( 1, (int) $pages ) * 297;
		return '@page{size:A4;margin:0}html,body{margin:0;padding:0}body{background:#fff}'
			. '.cv-clip{position:relative;width:210mm;max-height:' . $h . 'mm;overflow:hidden;margin:0 auto}'
			. '.cv-wm{position:absolute;inset:0;z-index:50;pointer-events:none;background-image:url("data:image/svg+xml,' . rawurlencode( '<svg xmlns="http://www.w3.org/2000/svg" width="560" height="320"><text x="90" y="190" transform="rotate(-24 280 160)" font-family="sans-serif" font-size="30" font-weight="700" fill="rgba(2,90,178,0.16)">MEEHA NEWS · SAMPLE</text></svg>' ) . '")}';
	}

	/**
	 * A complete HTML document for one CV (preview frames and the standalone page share it).
	 *
	 * @param array $tpl   Design.
	 * @param array $ctx   Context.
	 * @param array $opt   watermark (bool), head (extra head HTML), before/after (body HTML), lang.
	 * @return string
	 */
	public static function document( $tpl, $ctx, $opt = array() ) {
		$opt  = wp_parse_args(
			$opt,
			array(
				'watermark' => false,
				'head'      => '',
				'before'    => '',
				'after'     => '',
				'pages'     => $tpl['pages'],
				'csp_meta'  => true,
			)
		);
		$lang = isset( $ctx['lang'] ) && 'en' === $ctx['lang'] ? 'en' : 'bn';
		return '<!doctype html><html lang="' . $lang . '"><head><meta charset="utf-8">' . ( $opt['csp_meta'] ? '<meta http-equiv="Content-Security-Policy" content="' . esc_attr( self::frame_csp() ) . '">' : '' ) . '<meta name="viewport" content="width=device-width,initial-scale=1">'
			. $opt['head']
			. '<style>' . self::font_css() . self::frame_css( $opt['pages'] ) . '</style>'
			. '<style>' . $tpl['css'] . '</style></head><body>'
			. $opt['before']
			. '<div class="cv-clip">' . self::render( $tpl['html'], $ctx ) . ( $opt['watermark'] ? '<div class="cv-wm"></div>' : '' ) . '</div>'
			. $opt['after']
			. '</body></html>';
	}

	/**
	 * Content policy inside every CV document (also set as the first <meta> of preview frames, which a design's
	 * markup cannot loosen): no outside images, styles or fonts, no scripts.
	 *
	 * @return string
	 */
	public static function frame_csp() {
		$host = wp_parse_url( get_template_directory_uri() );
		$font = "'self'" . ( ! empty( $host['host'] ) ? ' ' . ( isset( $host['scheme'] ) ? $host['scheme'] : 'https' ) . '://' . $host['host'] : '' );
		return "default-src 'none'; img-src data:; style-src 'unsafe-inline'; font-src {$font} data:; script-src 'none'; base-uri 'none'; form-action 'none'";
	}

	/**
	 * Photo of a CV as a data: URI (kept in the private folder, so no public URL exists).
	 *
	 * @param array $cv Row.
	 * @return string
	 */
	public static function photo_uri( $cv ) {
		if ( empty( $cv['photo'] ) ) {
			return '';
		}
		$path = MHP_Front::private_path( $cv['photo'] );
		if ( ! $path || filesize( $path ) > 1024 * 1024 ) {
			return '';
		}
		return 'data:image/jpeg;base64,' . base64_encode( (string) file_get_contents( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Print/share page: hub view "cv-view/<token>", printed on its own (no theme), with a strict CSP.
	 */
	public static function standalone() {
		if ( ! function_exists( 'meeha_campus_is_hub' ) || ! meeha_campus_is_hub() ) {
			return;
		}
		$v = meeha_campus_view();
		if ( 'cv-view' !== $v['view'] ) {
			return;
		}
		mhp_nocache();
		$cv  = ( function_exists( 'meeha_campus_beta_blocked' ) && meeha_campus_beta_blocked() ) ? null : self::by_token( $v['id'] );
		$tpl = $cv ? self::tpl( $cv['tpl_id'] ) : null;
		$uid = get_current_user_id();
		$own = $cv && $uid && $cv['user_id'] === $uid;
		$ok  = $cv && $tpl && ( $own || mhp_can_manage() || ( $cv['is_public'] && self::can_share( $cv['user_id'], $tpl ) ) );
		if ( ! $ok ) {
			if ( $cv && $tpl && ! $uid ) {
				wp_safe_redirect( mhp_login_url( mhp_current_url() ) );
				exit;
			}
			status_header( 404 );
			header( 'Content-Type: text/html; charset=utf-8' );
			header( 'X-Robots-Tag: noindex, nofollow' );
			echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>সিভি পাওয়া যায়নি</title><p style="font-family:sans-serif;padding:2rem;text-align:center">এই সিভিটি পাওয়া যায়নি, অথবা এর মালিক লিংকটি বন্ধ রেখেছেন।</p>';
			exit;
		}
		$locked = ! self::owns( $cv['user_id'], $tpl );
		$nonce  = wp_create_nonce( 'mhp_cvjs' ) . bin2hex( random_bytes( 6 ) );
		$title  = '' !== (string) ( isset( $cv['data']['name'] ) ? $cv['data']['name'] : '' ) ? $cv['data']['name'] . ' — সিভি' : 'সিভি';
		$bar    = '';
		if ( $own || mhp_can_manage() ) {
			$bar = '<div class="mhp-bar" role="toolbar">'
				. '<a href="' . esc_url( mhp_url( 'cv-edit', $cv['id'] ) ) . '">← সম্পাদনা</a>'
				. ( $locked
					? '<span>এটি পেইড টেমপ্লেট। কিনলে জলছাপ ছাড়া প্রিন্ট/পিডিএফ ও শেয়ার করা যাবে।</span><a class="mhp-go" href="' . esc_url( mhp_url( 'checkout', 'cvtpl-' . $tpl['id'] ) ) . '">' . esc_html( mhp_tk( $tpl['price'] ) ) . '-এ কিনুন</a>'
					: '<span>প্রিন্ট ডায়ালগে "Save as PDF" বেছে নিন; Margins: None, Background graphics: চালু।</span><button type="button" class="mhp-go" id="mhp-print">প্রিন্ট / পিডিএফ</button>' )
				. '<span class="mhp-over" id="mhp-over" hidden>লেখা এই টেমপ্লেটের ' . esc_html( mhp_bn( $tpl['pages'] ) ) . ' পাতার বেশি হয়ে গেছে; বাড়তি অংশ কাটা পড়বে। কিছু লেখা ছোট করুন' . ( $tpl['pages'] < self::MAX_PAGES ? ' বা বেশি পাতার টেমপ্লেট নিন' : '' ) . '।</span>'
				. '</div>';
		}
		if ( ! $bar && ! $locked ) {
			$bar = '<div class="mhp-bar" role="toolbar"><span>মিহা নিউজের সিভি স্টুডিওতে তৈরি</span><button type="button" class="mhp-go" id="mhp-print">প্রিন্ট / পিডিএফ</button></div>';
		}
		$head = '<title>' . esc_html( $title ) . '</title><meta name="robots" content="noindex,nofollow">'
			. '<style>@media screen{body{background:#e6eaef;padding:16px 0 40px}.cv-clip{box-shadow:0 2px 14px rgba(0,0,0,.18);zoom:var(--z,1)}}'
			. '.mhp-bar{position:sticky;top:0;z-index:99;display:flex;flex-wrap:wrap;gap:8px 14px;align-items:center;justify-content:center;max-width:210mm;margin:-16px auto 16px;padding:10px 14px;background:#fff;border-bottom:1px solid #d5dbe2;font:14px/1.5 "Meeha Sans",system-ui,sans-serif;color:#1d232b}'
			. '.mhp-bar a{color:#025ab2}.mhp-go{display:inline-block;padding:7px 14px;border:0;border-radius:8px;background:#025ab2;color:#fff!important;font:inherit;font-weight:600;text-decoration:none;cursor:pointer}.mhp-over{flex-basis:100%;text-align:center;color:#a1260d;font-weight:600}'
			. '@media print{.mhp-bar{display:none!important}' . ( $locked ? '.cv-clip{display:none!important}body::after{content:"এই পেইড টেমপ্লেট প্রিন্টের আগে কিনে নিন।";display:block;padding:40mm 20mm;text-align:center;font:16pt sans-serif}' : '' ) . '}</style>';
		$js   = '<script nonce="' . esc_attr( $nonce ) . '">(function(){var c=document.querySelector(".cv-clip"),cv=c&&c.firstElementChild,mm=96/25.4,lim=' . (int) $tpl['pages'] . ',w=210*mm;'
			. 'function fit(){var z=Math.min(1,(document.documentElement.clientWidth-16)/w);document.documentElement.style.setProperty("--z",z>0.2?z:0.2);}'
			. 'function pages(){if(!cv)return;cv.style.minHeight="";var h=cv.scrollHeight/mm,n=Math.max(1,Math.ceil((h-1)/297));cv.style.minHeight=Math.min(n,lim)*297+"mm";var o=document.getElementById("mhp-over");if(o)o.hidden=!(n>lim);}'
			. 'fit();window.addEventListener("resize",fit);if(document.fonts&&document.fonts.ready){document.fonts.ready.then(pages);}else{pages();}window.addEventListener("load",pages);'
			. 'var b=document.getElementById("mhp-print");if(b)b.addEventListener("click",function(){window.print();});})();</script>';

		$font_host = wp_parse_url( get_template_directory_uri() );
		$font_src  = "'self'" . ( ! empty( $font_host['host'] ) ? ' ' . ( isset( $font_host['scheme'] ) ? $font_host['scheme'] : 'https' ) . '://' . $font_host['host'] : '' );
		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Content-Type-Options: nosniff' );
		header( "Content-Security-Policy: default-src 'none'; img-src data:; font-src {$font_src} data:; style-src 'unsafe-inline'; script-src 'nonce-{$nonce}'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'" );
		echo self::document( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- design HTML is sanitized on save, every value escaped by render().
			$tpl,
			self::context( $cv['data'], self::photo_uri( $cv ) ),
			array(
				'watermark' => $locked,
				'csp_meta'  => false,
				'head'      => $head,
				'before'    => $bar,
				'after'     => $js,
			)
		);
		exit;
	}

	/* ------------------------------------------------------------------
	 * Hub pages
	 * ---------------------------------------------------------------- */

	/**
	 * Gallery + my CVs.
	 *
	 * @param string $id Unused.
	 */
	public static function render_studio( $id ) {
		$uid = get_current_user_id();
		$tps = self::templates();
		$own = array();
		foreach ( $tps as $t ) {
			$own[ $t['id'] ] = self::owns( $uid, $t );
		}
		mhp_template(
			'cv-studio',
			array(
				'tpls'   => $tps,
				'owned'  => $own,
				'cvs'    => $uid ? self::of_user( $uid ) : array(),
				'uid'    => $uid,
				'pay_ok' => MHP_Gateway_SSLCommerz::available(),
			)
		);
	}

	/**
	 * Small preview document for a gallery card.
	 *
	 * @param array $tpl Design.
	 * @return string
	 */
	public static function thumb_doc( $tpl ) {
		$t = $tpl;
		return self::document( $t, self::context( self::sample(), self::sample_photo() ), array( 'pages' => 1 ) );
	}

	/**
	 * Editor.
	 *
	 * @param string $id CV id.
	 */
	public static function render_edit( $id ) {
		if ( ! is_user_logged_in() ) {
			MHP_Front::login_box( 'সিভি বানাতে লগইন করুন।' );
			return;
		}
		$cv = self::own( (int) $id );
		if ( ! $cv ) {
			MHP_Front::not_found( 'সিভিটি পাওয়া যায়নি।', 'cv' );
			return;
		}
		$uid  = get_current_user_id();
		$tps  = self::templates();
		$cur  = self::tpl( $cv['tpl_id'] );
		if ( ! $cur && $tps ) {
			$cur = $tps[0];
		}
		$conf = array(
			'tpls'   => array(),
			'labels' => array(
				'bn' => self::labels( 'bn' ),
				'en' => self::labels( 'en' ),
			),
			'decl'   => array(
				'bn' => self::default_declaration( 'bn' ),
				'en' => self::default_declaration( 'en' ),
			),
			'frame'  => self::font_css(),
			'csp'    => self::frame_csp(),
			'photo'  => self::photo_uri( $cv ),
			'lists'  => self::LISTS,
		);
		foreach ( $tps as $t ) {
			$conf['tpls'][ $t['id'] ] = array(
				'name'   => $t['name'],
				'pages'  => $t['pages'],
				'price'  => $t['price'],
				'owned'  => self::owns( $uid, $t ),
				'html'   => $t['html'],
				'css'    => $t['css'],
				'frame'  => self::frame_css( $t['pages'] ),
				'buy'    => mhp_url( 'checkout', 'cvtpl-' . $t['id'] ),
				'pricet' => mhp_tk( $t['price'] ),
			);
		}
		mhp_template(
			'cv-edit',
			array(
				'cv'     => $cv,
				'tpl'    => $cur,
				'tpls'   => $tps,
				'conf'   => $conf,
				'owned'  => $cur ? self::owns( $uid, $cur ) : false,
				'share'  => $cur ? self::can_share( $uid, $cur ) : false,
				'pay_ok' => MHP_Gateway_SSLCommerz::available(),
			)
		);
	}

	/**
	 * Editor script.
	 */
	public static function assets() {
		if ( ! function_exists( 'meeha_campus_is_hub' ) || ! meeha_campus_is_hub() ) {
			return;
		}
		$v = meeha_campus_view();
		if ( in_array( $v['view'], array( 'cv', 'cv-edit' ), true ) ) {
			wp_enqueue_script( 'mhp-cv', MHP_URI . '/assets/js/cv.js', array(), MHP_VERSION, array( 'strategy' => 'defer', 'in_footer' => true ) );
		}
	}

	/* ------------------------------------------------------------------
	 * Hub actions (nonce + login checked by the dispatcher)
	 * ---------------------------------------------------------------- */

	/**
	 * Start a CV with a design.
	 */
	public static function handle_create() {
		$uid = get_current_user_id();
		$tid = isset( $_POST['tpl_id'] ) ? absint( $_POST['tpl_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$tpl = self::tpl( $tid );
		if ( ! $tpl ) {
			mhp_flash( 'টেমপ্লেটটি পাওয়া যায়নি।', 'error' );
			wp_safe_redirect( mhp_url( 'cv' ) );
			exit;
		}
		if ( count( self::of_user( $uid ) ) >= self::MAX_CVS ) {
			mhp_flash( 'সর্বোচ্চ ' . mhp_bn( self::MAX_CVS ) . 'টি সিভি রাখা যায়। পুরোনো একটি মুছে নতুন বানান, অথবা আগেরটিতেই টেমপ্লেট বদলান।', 'error' );
			wp_safe_redirect( mhp_url( 'cv' ) );
			exit;
		}
		if ( ! mhp_rate_ok( 'cv_create', 20, HOUR_IN_SECONDS ) ) {
			mhp_flash( 'অল্প সময়ে অনেকবার চেষ্টা হয়েছে। কিছুক্ষণ পরে আবার চেষ্টা করুন।', 'error' );
			wp_safe_redirect( mhp_url( 'cv' ) );
			exit;
		}
		$u    = wp_get_current_user();
		$prev = self::of_user( $uid );
		// A new CV starts from the reader's last one (the details are the same; only the design changes).
		$data = $prev ? $prev[0]['data'] : self::clean(
			array(
				'name'        => false !== strpos( (string) $u->display_name, '@' ) ? '' : $u->display_name,
				'email'       => $u->user_email,
				'phone'       => (string) get_user_meta( $uid, 'meeha_phone', true ),
				'declaration' => self::default_declaration( 'bn' ),
				'personal'    => array( 'nationality' => 'বাংলাদেশি' ),
			)
		);
		global $wpdb;
		$now = mhp_gmt();
		$ok  = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			MHP_Install::table( 'cvs' ),
			array(
				'user_id'    => $uid,
				'tpl_id'     => $tpl['id'],
				'title'      => mb_substr( $tpl['name'] . ' সিভি', 0, 120 ),
				'data'       => wp_json_encode( $data ),
				'token'      => wp_generate_password( 24, false, false ),
				'is_public'  => 0,
				'photo'      => '',
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
		if ( ! $ok ) {
			mhp_flash( 'সিভি তৈরি করা যায়নি। আবার চেষ্টা করুন।', 'error' );
			wp_safe_redirect( mhp_url( 'cv' ) );
			exit;
		}
		$new = (int) $wpdb->insert_id;
		if ( $prev && $prev[0]['photo'] ) {
			self::copy_photo( $prev[0]['photo'], $new );
		}
		wp_safe_redirect( mhp_url( 'cv-edit', $new ) );
		exit;
	}

	/**
	 * Copy a photo file for a new CV.
	 *
	 * @param string $stored Stored name.
	 * @param int    $cv_id  New CV.
	 */
	private static function copy_photo( $stored, $cv_id ) {
		$src = MHP_Front::private_path( $stored );
		if ( ! $src ) {
			return;
		}
		$name = 'cvphoto-' . (int) $cv_id . '-' . wp_generate_password( 16, false, false ) . '.jpg';
		if ( copy( $src, MHP_Front::private_dir() . '/' . $name ) ) {
			self::set_col( $cv_id, array( 'photo' => $name ) );
		}
	}

	/**
	 * Update columns.
	 *
	 * @param int   $id     CV.
	 * @param array $fields Fields.
	 */
	private static function set_col( $id, $fields ) {
		global $wpdb;
		$fields['updated_at'] = mhp_gmt();
		$wpdb->update( MHP_Install::table( 'cvs' ), $fields, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * The CV a posted form is about, or bail out.
	 *
	 * @return array
	 */
	private static function posted_cv() {
		$id = isset( $_POST['cv_id'] ) ? absint( $_POST['cv_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$cv = self::own( $id );
		if ( ! $cv ) {
			mhp_flash( 'সিভিটি পাওয়া যায়নি।', 'error' );
			wp_safe_redirect( mhp_url( 'cv' ) );
			exit;
		}
		return $cv;
	}

	/**
	 * Save the editor.
	 */
	public static function handle_save() {
		$cv   = self::posted_cv();
		$back = mhp_url( 'cv-edit', $cv['id'] );
		if ( ! mhp_rate_ok( 'cv_save', 120, HOUR_IN_SECONDS ) ) {
			mhp_flash( 'অল্প সময়ে অনেকবার সংরক্ষণ হয়েছে। কিছুক্ষণ পরে আবার চেষ্টা করুন।', 'error' );
			wp_safe_redirect( $back );
			exit;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- verified by the dispatcher; clean() sanitizes each field.
		$data  = self::clean( isset( $_POST['cv'] ) ? wp_unslash( $_POST['cv'] ) : array() );
		$tid   = isset( $_POST['tpl_id'] ) ? absint( $_POST['tpl_id'] ) : $cv['tpl_id'];
		$title = isset( $_POST['cv_title'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_POST['cv_title'] ) ), 0, 120 ) : $cv['title'];
		// phpcs:enable
		$fields = array(
			'data'  => wp_json_encode( $data ),
			'title' => '' !== $title ? $title : $cv['title'],
		);
		$new_tpl = $tid !== $cv['tpl_id'] ? self::tpl( $tid ) : null;
		if ( $new_tpl ) {
			$fields['tpl_id'] = $tid;
			$old_tpl          = self::tpl( $cv['tpl_id'], false );
			if ( $old_tpl && $fields['title'] === $old_tpl['name'] . ' সিভি' ) {
				$fields['title'] = mb_substr( $new_tpl['name'] . ' সিভি', 0, 120 );
			}
		}
		$err = '';
		if ( ! empty( $_POST['photo_remove'] ) && $cv['photo'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			self::delete_photo( $cv['photo'] );
			$fields['photo'] = '';
		}
		if ( ! empty( $_FILES['cv_photo']['name'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$name = self::save_photo( $_FILES['cv_photo'], $cv['id'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification.Missing -- checked in save_photo().
			if ( is_wp_error( $name ) ) {
				$err = $name->get_error_message();
			} else {
				if ( $cv['photo'] && empty( $fields['photo'] ) ) {
					self::delete_photo( $cv['photo'] );
				}
				$fields['photo'] = $name;
			}
		}
		self::set_col( $cv['id'], $fields );
		mhp_flash( $err ? 'সিভি সংরক্ষিত হয়েছে, কিন্তু ছবি নেওয়া যায়নি: ' . $err : 'সিভি সংরক্ষিত হয়েছে।', $err ? 'error' : 'ok' );
		$go = isset( $_POST['then'] ) && 'view' === $_POST['then'] ? mhp_url( 'cv-view', $cv['token'] ) : $back; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		wp_safe_redirect( $go );
		exit;
	}

	/**
	 * Take an uploaded photo: real image, at most 3 MB, re-encoded as a small JPEG in the private folder.
	 *
	 * @param array $f     $_FILES entry.
	 * @param int   $cv_id CV.
	 * @return string|WP_Error Stored name.
	 */
	private static function save_photo( $f, $cv_id ) {
		if ( ! is_array( $f ) || ! isset( $f['error'], $f['tmp_name'], $f['size'] ) || UPLOAD_ERR_OK !== (int) $f['error'] || ! is_uploaded_file( $f['tmp_name'] ) ) {
			return new WP_Error( 'mhp_photo', 'ফাইলটি আপলোড হয়নি।' );
		}
		if ( (int) $f['size'] > 3 * 1024 * 1024 ) {
			return new WP_Error( 'mhp_photo', 'ছবি ৩ এমবির কম হতে হবে।' );
		}
		$info = @getimagesize( $f['tmp_name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $info || ! in_array( (int) $info[2], array( IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP ), true ) || $info[0] * $info[1] > 25000000 ) {
			return new WP_Error( 'mhp_photo', 'জেপিজি, পিএনজি বা ওয়েবপি ছবি দিন।' );
		}
		$ed = wp_get_image_editor( $f['tmp_name'], array( 'mime_type' => $info['mime'] ) );
		if ( is_wp_error( $ed ) ) {
			return new WP_Error( 'mhp_photo', 'ছবিটি পড়া যায়নি।' );
		}
		$ed->resize( 600, 720, false );
		$ed->set_quality( 84 );
		$name = 'cvphoto-' . (int) $cv_id . '-' . wp_generate_password( 16, false, false ) . '.jpg';
		$res  = $ed->save( MHP_Front::private_dir() . '/' . $name, 'image/jpeg' );
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'mhp_photo', 'ছবিটি সংরক্ষণ করা যায়নি।' );
		}
		return $name;
	}

	/**
	 * Delete a stored photo.
	 *
	 * @param string $stored Stored name.
	 */
	private static function delete_photo( $stored ) {
		$p = MHP_Front::private_path( $stored );
		if ( $p && 0 === strpos( basename( $p ), 'cvphoto-' ) ) {
			wp_delete_file( $p );
		}
	}

	/**
	 * Public link on/off (bought designs only).
	 */
	public static function handle_share() {
		$cv  = self::posted_cv();
		$on  = ! empty( $_POST['public'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$tpl = self::tpl( $cv['tpl_id'] );
		if ( $on && ( ! $tpl || ! self::can_share( $cv['user_id'], $tpl ) ) ) {
			mhp_flash( 'পাবলিক লিংক শুধু কেনা পেইড টেমপ্লেটে চালু করা যায়। ফ্রি টেমপ্লেটের লিংক শুধু আপনি দেখতে পারবেন।', 'error' );
			wp_safe_redirect( mhp_url( 'cv-edit', $cv['id'] ) );
			exit;
		}
		$fields = array( 'is_public' => $on ? 1 : 0 );
		if ( ! empty( $_POST['new_link'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$fields['token'] = wp_generate_password( 24, false, false );
		}
		self::set_col( $cv['id'], $fields );
		mhp_flash( $on ? 'পাবলিক লিংক চালু হয়েছে। যাঁকে লিংক দেবেন, তিনি সিভি দেখতে ও পিডিএফ নিতে পারবেন।' : ( isset( $fields['token'] ) ? 'নতুন লিংক তৈরি হয়েছে; আগের লিংক আর কাজ করবে না।' : 'পাবলিক লিংক বন্ধ হয়েছে।' ) );
		wp_safe_redirect( mhp_url( 'cv-edit', $cv['id'] ) );
		exit;
	}

	/**
	 * Delete a CV.
	 */
	public static function handle_delete() {
		global $wpdb;
		$cv = self::posted_cv();
		if ( $cv['photo'] ) {
			self::delete_photo( $cv['photo'] );
		}
		$wpdb->delete( MHP_Install::table( 'cvs' ), array( 'id' => $cv['id'] ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		mhp_flash( 'সিভি মুছে ফেলা হয়েছে।' );
		wp_safe_redirect( mhp_url( 'cv' ) );
		exit;
	}

	/* ------------------------------------------------------------------
	 * Design packages: sanitize, seed, import/export
	 * ---------------------------------------------------------------- */

	/**
	 * Allowed design markup.
	 *
	 * @return array
	 */
	private static function allowed_html() {
		$common = array(
			'class' => true,
			'id'    => true,
			'style' => true,
			'title' => true,
			'lang'  => true,
			'dir'   => true,
		);
		$tags   = array( 'div', 'section', 'header', 'footer', 'main', 'aside', 'article', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'span', 'b', 'strong', 'i', 'em', 'u', 'small', 'sup', 'sub', 'br', 'hr', 'ul', 'ol', 'li', 'dl', 'dt', 'dd', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'figure', 'figcaption', 'blockquote', 'address', 'time' );
		$out    = array();
		foreach ( $tags as $t ) {
			$out[ $t ] = $common;
		}
		$out['th']  = array_merge( $common, array( 'colspan' => true, 'rowspan' => true, 'scope' => true ) );
		$out['td']  = array_merge( $common, array( 'colspan' => true, 'rowspan' => true ) );
		$out['img'] = array_merge( $common, array( 'src' => true, 'alt' => true, 'width' => true, 'height' => true ) );
		$out['a']   = array_merge( $common, array( 'href' => true ) );
		return $out;
	}

	/**
	 * Sanitize design HTML: allow-listed tags, http(s)/mailto/tel links, and {{tags}} only where they are safe
	 * (text, class/alt/title, src="{{photo}}", href="{{website}}", mailto:{{email}}, tel:{{phone}}).
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function clean_html( $html ) {
		$html = (string) $html;
		$html = preg_replace( '#<(script|style|template|iframe|object|embed|svg|math|noscript|textarea|select|form)\b.*?</\1\s*>#is', '', $html );
		$html = wp_kses( $html, self::allowed_html(), array( 'http', 'https', 'mailto', 'tel' ) );
		$html = preg_replace_callback(
			'/<([a-z][a-z0-9]*)(\s[^>]*?)?(\/?)>/i',
			function ( $m ) {
				if ( empty( $m[2] ) || false === strpos( $m[2], '{{' ) ) {
					return $m[0];
				}
				$attrs = preg_replace_callback(
					'/\s([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*=\s*("[^"]*"|\'[^\']*\')/',
					function ( $a ) {
						$name = strtolower( $a[1] );
						$val  = substr( $a[2], 1, -1 );
						if ( false === strpos( $val, '{{' ) ) {
							return $a[0];
						}
						$safe = in_array( $name, array( 'class', 'alt', 'title', 'lang', 'dir' ), true ) || 0 === strpos( $name, 'data-' )
							|| ( 'src' === $name && '{{photo}}' === trim( $val ) )
							|| ( 'href' === $name && in_array( trim( $val ), array( '{{website}}', 'mailto:{{email}}', 'tel:{{phone}}' ), true ) );
						return $safe ? $a[0] : '';
					},
					$m[2]
				);
				return '<' . $m[1] . $attrs . $m[3] . '>';
			},
			$html
		);
		// No outside images: <img> only takes {{photo}} or a data: image; inline styles may not use url().
		$html = preg_replace_callback(
			'/<img\b[^>]*>/i',
			function ( $m ) {
				return preg_replace_callback(
					'/\ssrc\s*=\s*("[^"]*"|\'[^\']*\')/i',
					function ( $a ) {
						$v = trim( substr( $a[1], 1, -1 ) );
						return ( '{{photo}}' === $v || preg_match( '#^data:image/(png|jpeg|gif|webp);#i', $v ) ) ? $a[0] : '';
					},
					$m[0]
				);
			},
			$html
		);
		$html = preg_replace( '/\sstyle\s*=\s*("[^"]*(url|image-set|@import)[^"]*"|\'[^\']*(url|image-set|@import)[^\']*\')/i', '', $html );
		$html = trim( (string) $html );
		if ( ! preg_match( '/^<div\s+class="cv[\s"]/', $html ) ) {
			$html = '<div class="cv">' . $html . '</div>';
		}
		return $html;
	}

	/**
	 * Sanitize design CSS. No outside resources: url() only with data:image/ or data:font/, no @import,
	 * no "<" (so it can never close the style element).
	 *
	 * @param string $css CSS.
	 * @return string|WP_Error
	 */
	public static function clean_css( $css ) {
		$css = str_replace( array( '<', "\0" ), '', (string) $css );
		$css = preg_replace( '/@import[^;]*;?/i', '', $css );
		$css = preg_replace( '/@namespace[^;]*;?/i', '', $css );
		$css = preg_replace_callback(
			'/url\s*\(\s*([\'"]?)(.*?)\1\s*\)/is',
			function ( $m ) {
				return preg_match( '#^data:(image/(png|jpeg|gif|webp|svg\+xml)|font/(woff2?|ttf|otf)|application/font-woff2?)[;,]#i', trim( $m[2] ) ) ? $m[0] : 'none';
			},
			$css
		);
		$css = preg_replace( '/expression\s*\(|behavior\s*:|-moz-binding|javascript\s*:/i', '', $css );
		// Escapes can spell the same words in disguise ("\75rl(" is url(): decode and look again.
		$probe = preg_replace_callback(
			'/\\\\([0-9a-fA-F]{1,6})\s?|\\\\(.)/s',
			function ( $m ) {
				if ( '' !== $m[1] ) {
					$cp = hexdec( $m[1] );
					if ( $cp <= 0 || $cp >= 0x110000 ) {
						return '';
					}
					return function_exists( 'mb_chr' ) ? mb_chr( (int) $cp, 'UTF-8' ) : ( $cp < 128 ? chr( (int) $cp ) : '?' );
				}
				return $m[2];
			},
			preg_replace( '#/\*.*?\*/#s', '', $css )
		);
		$probe = strtolower( (string) $probe );
		$bare  = preg_replace( '/url\s*\(\s*([\'"]?)\s*data:.*?\1\s*\)/s', 'url()', $probe );
		if ( preg_match( '#https?:|(?<![a-z-])(-webkit-)?(image-set|image|cross-fade|src|element)\s*\(#', (string) $bare ) ) {
			return new WP_Error( 'mhp_css', 'CSS-এ বাইরের লিংক আছে। ছবি/ফন্ট শুধু data: দিয়ে দিন।' );
		}
		if ( preg_match( '/@import|expression\s*\(|behavior\s*:|-moz-binding|javascript\s*:/', $probe ) || preg_match_all( '/url\s*\(\s*[\'"]?\s*([^\'")\s]*)/', $probe, $mm ) && array_filter(
			$mm[1],
			function ( $u ) {
				return 0 !== strpos( $u, 'data:' );
			}
		) ) {
			return new WP_Error( 'mhp_css', 'CSS-এ বাইরের লিংক বা লুকানো কোড আছে। url() শুধু data: দিয়ে ব্যবহার করুন।' );
		}
		return trim( $css );
	}

	/**
	 * Validate and normalise a package (from the bundled data or an import).
	 *
	 * @param array $p        Package.
	 * @param bool  $can_shop May set a price.
	 * @return array|WP_Error
	 */
	public static function package( $p, $can_shop ) {
		if ( ! is_array( $p ) ) {
			return new WP_Error( 'mhp_pkg', 'প্যাকেজের গঠন ঠিক নেই।' );
		}
		$key  = isset( $p['key'] ) ? sanitize_key( (string) $p['key'] ) : '';
		$name = isset( $p['name'] ) ? mb_substr( sanitize_text_field( (string) $p['name'] ), 0, 80 ) : '';
		$html = isset( $p['html'] ) ? (string) $p['html'] : '';
		$css  = isset( $p['css'] ) ? (string) $p['css'] : '';
		if ( '' === $key || '' === $name || '' === trim( $html ) ) {
			return new WP_Error( 'mhp_pkg', 'key, name ও html থাকতে হবে।' );
		}
		if ( strlen( $html ) > 80000 || strlen( $css ) > 80000 ) {
			return new WP_Error( 'mhp_pkg', 'html বা css ৮০ কিলোবাইটের বেশি।' );
		}
		$css = self::clean_css( $css );
		if ( is_wp_error( $css ) ) {
			return $css;
		}
		return array(
			'key'   => mb_substr( $key, 0, 40 ),
			'name'  => $name,
			'desc'  => isset( $p['desc'] ) ? mb_substr( sanitize_text_field( (string) $p['desc'] ), 0, 300 ) : '',
			'price' => $can_shop && isset( $p['price'] ) ? max( 0, round( (float) $p['price'], 2 ) ) : null,
			'pages' => $can_shop ? ( isset( $p['pages'] ) ? max( 1, min( self::MAX_PAGES, (int) $p['pages'] ) ) : 1 ) : null,
			'order' => isset( $p['order'] ) ? (int) $p['order'] : 50,
			'html'  => self::clean_html( $html ),
			'css'   => $css,
		);
	}

	/**
	 * Design post by key.
	 *
	 * @param string $key Key.
	 * @return int
	 */
	public static function id_by_key( $key ) {
		$ids = get_posts(
			array(
				'post_type'      => 'mh_cv_tpl',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_mhp_tpl_key', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => $key, // phpcs:ignore WordPress.DB.SlowDBQuery
				'no_found_rows'  => true,
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Create or update a design from a clean package.
	 *
	 * @param array  $pkg       Clean package.
	 * @param string $status    publish|draft for new designs.
	 * @param bool   $overwrite Update an existing key.
	 * @return array result: id, action (created|updated|skipped)
	 */
	public static function upsert( $pkg, $status = 'publish', $overwrite = false ) {
		$id = self::id_by_key( $pkg['key'] );
		if ( $id && ! $overwrite ) {
			return array(
				'id'     => $id,
				'action' => 'skipped',
			);
		}
		$post = array(
			'post_type'    => 'mh_cv_tpl',
			'post_title'   => $pkg['name'],
			'post_excerpt' => $pkg['desc'],
			'menu_order'   => $pkg['order'],
		);
		if ( $id ) {
			$post['ID'] = $id;
			wp_update_post( wp_slash( $post ) );
		} else {
			$post['post_status'] = $status;
			$id                  = (int) wp_insert_post( wp_slash( $post ) );
			if ( ! $id ) {
				return array(
					'id'     => 0,
					'action' => 'failed',
				);
			}
		}
		update_post_meta( $id, '_mhp_tpl_key', $pkg['key'] );
		if ( null !== $pkg['pages'] ) {
			update_post_meta( $id, '_mhp_pages', $pkg['pages'] );
		} elseif ( ! metadata_exists( 'post', $id, '_mhp_pages' ) ) {
			update_post_meta( $id, '_mhp_pages', 1 );
		}
		update_post_meta( $id, '_mhp_html', wp_slash( $pkg['html'] ) );
		update_post_meta( $id, '_mhp_css', wp_slash( $pkg['css'] ) );
		if ( null !== $pkg['price'] ) {
			update_post_meta( $id, '_mhp_price', $pkg['price'] );
		} elseif ( ! metadata_exists( 'post', $id, '_mhp_price' ) ) {
			update_post_meta( $id, '_mhp_price', 0 );
		}
		return array(
			'id'     => $id,
			'action' => isset( $post['ID'] ) ? 'updated' : 'created',
		);
	}

	/**
	 * Package of a stored design (export).
	 *
	 * @param int $id Post.
	 * @return array|null
	 */
	public static function export_package( $id ) {
		$t = self::tpl( $id, false );
		if ( ! $t ) {
			return null;
		}
		return array(
			'format'  => 'meeha-cv-template',
			'version' => 1,
			'key'     => $t['key'] ? $t['key'] : 'tpl-' . $t['id'],
			'name'    => $t['name'],
			'desc'    => $t['desc'],
			'price'   => $t['price'],
			'pages'   => (int) mhp_meta( $t['id'], 'pages', 1 ),
			'order'   => (int) get_post_field( 'menu_order', $t['id'] ),
			'html'    => $t['html'],
			'css'     => $t['css'],
		);
	}

	/**
	 * Install the bundled designs once (missing keys only; editors' changes stay).
	 *
	 * @param bool $force Run even when done before.
	 * @return int Created.
	 */
	public static function seed( $force = false ) {
		if ( ! $force && get_option( 'mhp_cv_seeded' ) ) {
			return 0;
		}
		$n = 0;
		foreach ( (array) include MHP_DIR . '/data/cv-templates.php' as $p ) {
			$pkg = self::package( $p, true );
			if ( is_wp_error( $pkg ) ) {
				continue;
			}
			$r  = self::upsert( $pkg, 'publish', false );
			$n += 'created' === $r['action'] ? 1 : 0;
		}
		update_option( 'mhp_cv_seeded', time(), false );
		return $n;
	}

	/**
	 * Seed on the first admin visit after the update.
	 */
	public static function maybe_seed() {
		if ( ! get_option( 'mhp_cv_seeded' ) && current_user_can( 'manage_options' ) ) {
			self::seed();
		}
	}
}
