<?php
/**
 * Lessons (পাঠ): short written lessons with an optional YouTube/Vimeo video and practice questions at the end.
 * A lesson is free, or unlocked by a pack (mh_product) the reader bought. "আজকের নির্বাচিত পাঠ" is the lesson an
 * editor scheduled for today, otherwise a free lesson picked by date (the same for everyone all day).
 *
 * Not a live-class platform: live classes run as events with a Zoom/Meet link (see the guide).
 *
 * @package meeha-prep
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lessons.
 */
class MHP_Lessons {

	const PER_PAGE = 12;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ), 6 );
		add_filter( 'meeha_campus_views', array( __CLASS__, 'views' ) );
		add_filter( 'meeha_campus_tabs', array( __CLASS__, 'tabs' ), 10, 2 );
		add_filter( 'meeha_campus_wide_views', array( __CLASS__, 'wide' ) );
		add_filter( 'mhp_hub_actions', array( __CLASS__, 'actions' ) );
		add_action( 'mhp_hub_action_lesson_done', array( __CLASS__, 'handle_done' ) );
		add_action( 'save_post_mh_lesson', array( __CLASS__, 'flush' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_seed' ) );
	}

	/**
	 * Post type (shares the track and subject taxonomies).
	 */
	public static function register() {
		register_post_type(
			'mh_lesson',
			array(
				'labels'              => array(
					'name'          => 'পাঠ',
					'singular_name' => 'পাঠ',
					'menu_name'     => 'পাঠ',
					'add_new'       => 'নতুন যোগ',
					'add_new_item'  => 'নতুন পাঠ',
					'edit_item'     => 'পাঠ সম্পাদনা',
					'all_items'     => 'পাঠ',
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
				'supports'            => array( 'title', 'editor', 'excerpt', 'thumbnail', 'page-attributes', 'revisions' ),
			)
		);
		register_taxonomy_for_object_type( 'mh_track', 'mh_lesson' );
		register_taxonomy_for_object_type( 'mh_subject', 'mh_lesson' );
	}

	/**
	 * Hub actions.
	 *
	 * @param string[] $a Actions.
	 * @return string[]
	 */
	public static function actions( $a ) {
		$a[] = 'lesson_done';
		return $a;
	}

	/**
	 * Views.
	 *
	 * @param array $views Views.
	 * @return array
	 */
	public static function views( $views ) {
		$views['lessons'] = array(
			'title'  => 'পাঠ',
			'tab'    => 'prep',
			'root'   => true,
			'render' => array( __CLASS__, 'render_list' ),
		);
		$views['lesson']  = array(
			'title'    => array( __CLASS__, 'title_one' ),
			'tab'      => 'prep',
			'back'     => 'lessons',
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
	 * @param string $cur  Current view.
	 * @return array
	 */
	public static function tabs( $tabs, $cur ) {
		if ( self::any() ) {
			$tabs[] = array( 'পাঠ', mhp_url( 'lessons' ), in_array( $cur, array( 'lessons', 'lesson' ), true ) );
		}
		return $tabs;
	}

	/**
	 * Wide desktop layout for the list.
	 *
	 * @param string[] $v Views.
	 * @return string[]
	 */
	public static function wide( $v ) {
		$v[] = 'lessons';
		return $v;
	}

	/**
	 * Title.
	 *
	 * @param string $id Id.
	 * @return string
	 */
	public static function title_one( $id ) {
		$p = get_post( (int) $id );
		return ( $p && 'mh_lesson' === $p->post_type ) ? get_the_title( $p ) : 'পাঠ';
	}

	/**
	 * Any published lesson?
	 *
	 * @return bool
	 */
	public static function any() {
		$n = wp_cache_get( 'mhp_lessons_any', 'mhp' );
		if ( false === $n ) {
			$n = (int) wp_count_posts( 'mh_lesson' )->publish;
			wp_cache_set( 'mhp_lessons_any', $n, 'mhp', 300 );
		}
		return $n > 0;
	}

	/**
	 * Clear the day's pick when lessons change.
	 */
	public static function flush() {
		wp_cache_delete( 'mhp_lessons_any', 'mhp' );
		delete_transient( 'mhp_lesson_day' );
	}

	/**
	 * YouTube / Vimeo URL → privacy-friendly embed URL ('' when not supported).
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function embed_url( $url ) {
		$url = trim( (string) $url );
		if ( preg_match( '~^https?://(?:www\.|m\.)?(?:youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/)|youtu\.be/)([A-Za-z0-9_-]{11})~', $url, $m ) ) {
			return 'https://www.youtube-nocookie.com/embed/' . $m[1] . '?rel=0';
		}
		if ( preg_match( '~^https?://(?:www\.)?(?:player\.)?vimeo\.com/(?:video/)?(\d{6,12})~', $url, $m ) ) {
			return 'https://player.vimeo.com/video/' . $m[1] . '?dnt=1';
		}
		return '';
	}

	/**
	 * Lesson summary.
	 *
	 * @param WP_Post|int $p Lesson.
	 * @return array|null
	 */
	public static function data( $p ) {
		$p = get_post( $p );
		if ( ! $p || 'mh_lesson' !== $p->post_type ) {
			return null;
		}
		$tracks = wp_get_post_terms( $p->ID, 'mh_track' );
		$subj   = wp_get_post_terms( $p->ID, 'mh_subject', array( 'fields' => 'names' ) );
		$pack   = (int) mhp_meta( $p->ID, 'product', 0 );
		$pack_p = $pack ? get_post( $pack ) : null;
		// A lesson tied to a pack stays locked even while the pack is paused (draft, trashed, unpriced): only buyers
		// and managers open it then. on_sale says whether the pack can be bought right now.
		$on_sale = $pack_p && 'mh_product' === $pack_p->post_type && 'publish' === $pack_p->post_status && (float) mhp_meta( $pack, 'price', 0 ) > 0;
		$text  = wp_strip_all_tags( (string) $p->post_content );
		$words = count( preg_split( '/\s+/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY ) );
		$mins  = (int) mhp_meta( $p->ID, 'minutes', 0 );
		return array(
			'id'      => (int) $p->ID,
			'title'   => get_the_title( $p ),
			'excerpt' => (string) $p->post_excerpt,
			'status'  => $p->post_status,
			'track'   => ( $tracks && ! is_wp_error( $tracks ) ) ? mhp_track( $tracks[0] ) : null,
			'subject' => ( $subj && ! is_wp_error( $subj ) ) ? $subj[0] : '',
			'video'   => self::embed_url( (string) mhp_meta( $p->ID, 'video', '' ) ),
			'minutes' => $mins ? $mins : max( 1, (int) ceil( $words / 180 ) ),
			'pack'    => $pack,
			'on_sale' => $on_sale,
			'qcount'  => count( array_filter( (array) mhp_meta( $p->ID, 'questions', array() ) ) ),
			'thumb'   => (int) get_post_thumbnail_id( $p ),
		);
	}

	/**
	 * May the reader open the full lesson?
	 *
	 * @param array $l   Lesson data.
	 * @param int   $uid User.
	 * @return bool
	 */
	public static function can_open( $l, $uid ) {
		if ( ! $l['pack'] ) {
			return true;
		}
		return mhp_can_manage() || ( $uid && MHP_Access::has( $uid, 'product', $l['pack'] ) );
	}

	/**
	 * Published lessons.
	 *
	 * @param array $f track (term id), subject (term id), page, per_page, free_only.
	 * @return array items, pages, total
	 */
	public static function query( $f = array() ) {
		$f    = wp_parse_args(
			$f,
			array(
				'track'     => 0,
				'subject'   => 0,
				'page'      => 1,
				'per_page'  => self::PER_PAGE,
				'free_only' => false,
			)
		);
		$args = array(
			'post_type'      => 'mh_lesson',
			'post_status'    => 'publish',
			'posts_per_page' => (int) $f['per_page'],
			'paged'          => max( 1, (int) $f['page'] ),
			'orderby'        => array(
				'menu_order' => 'ASC',
				'date'       => 'DESC',
			),
		);
		$tax  = array();
		if ( $f['track'] ) {
			$tax[] = array(
				'taxonomy' => 'mh_track',
				'terms'    => (int) $f['track'],
			);
		}
		if ( $f['subject'] ) {
			$tax[] = array(
				'taxonomy' => 'mh_subject',
				'terms'    => (int) $f['subject'],
			);
		}
		if ( $tax ) {
			$args['tax_query'] = array_merge( array( 'relation' => 'AND' ), $tax ); // phpcs:ignore WordPress.DB.SlowDBQuery
		}
		if ( $f['free_only'] ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery
				'relation' => 'OR',
				array(
					'key'     => '_mhp_product',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'   => '_mhp_product',
					'value' => array( '', '0' ),
					'compare' => 'IN',
				),
			);
		}
		$q   = new WP_Query( $args );
		$out = array();
		foreach ( $q->posts as $p ) {
			$d = self::data( $p );
			if ( $d && ( ! $f['free_only'] || ! $d['pack'] ) ) {
				$out[] = $d;
			}
		}
		return array(
			'items' => $out,
			'pages' => (int) $q->max_num_pages,
			'total' => (int) $q->found_posts,
		);
	}

	/**
	 * Today's selected lesson: scheduled by an editor (field "আজকের পাঠ হিসেবে দেখান"), else a free lesson picked
	 * by date — from the reader's target track when it has lessons, otherwise from all free lessons.
	 *
	 * @param int $track Preferred track.
	 * @return array|null
	 */
	public static function today( $track = 0 ) {
		$day = mhp_today();
		$c   = get_transient( 'mhp_lesson_day' );
		if ( ! is_array( $c ) || ! isset( $c['day'] ) || $c['day'] !== $day ) {
			$c = array(
				'day'   => $day,
				'pick'  => array(),
				'fixed' => 0,
			);
			$f = get_posts(
				array(
					'post_type'      => 'mh_lesson',
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_key'       => '_mhp_feature_date', // phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_value'     => $day, // phpcs:ignore WordPress.DB.SlowDBQuery
				)
			);
			$c['fixed'] = $f ? (int) $f[0] : 0;
			set_transient( 'mhp_lesson_day', $c, DAY_IN_SECONDS );
		}
		if ( $c['fixed'] ) {
			return self::data( $c['fixed'] );
		}
		$key = (string) (int) $track;
		if ( ! isset( $c['pick'][ $key ] ) ) {
			$pool = self::query(
				array(
					'track'     => (int) $track,
					'per_page'  => 300,
					'free_only' => true,
				)
			)['items'];
			if ( ! $pool && $track ) {
				return self::today( 0 );
			}
			$ids = wp_list_pluck( $pool, 'id' );
			sort( $ids );
			$c['pick'][ $key ] = $ids ? (int) $ids[ (int) floor( strtotime( $day . ' 00:00:00 UTC' ) / DAY_IN_SECONDS ) % count( $ids ) ] : 0;
			set_transient( 'mhp_lesson_day', $c, DAY_IN_SECONDS );
		}
		return $c['pick'][ $key ] ? self::data( $c['pick'][ $key ] ) : null;
	}

	/**
	 * Practice questions of a lesson, leaving out any that belong to paid or live tests or today's quiz.
	 *
	 * @param int $lesson_id Lesson.
	 * @return array[] Questions with answers.
	 */
	public static function practice( $lesson_id ) {
		$ids = array_values( array_filter( array_map( 'intval', (array) mhp_meta( $lesson_id, 'questions', array() ) ) ) );
		if ( ! $ids ) {
			return array();
		}
		$ids = array_values( array_diff( $ids, MHP_Front::protected_questions( $lesson_id ) ) );
		return MHP_Tests::load_questions( array_slice( $ids, 0, 30 ), true );
	}

	/**
	 * Tracks that have lessons.
	 *
	 * @return array[]
	 */
	public static function tracks() {
		$out = array();
		foreach ( mhp_tracks() as $t ) {
			$n = get_posts(
				array(
					'post_type'      => 'mh_lesson',
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery
						array(
							'taxonomy' => 'mh_track',
							'terms'    => $t['id'],
						),
					),
				)
			);
			if ( $n ) {
				$out[] = $t;
			}
		}
		return $out;
	}

	/**
	 * List view.
	 *
	 * @param string $id Unused.
	 */
	public static function render_list( $id ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$track = isset( $_GET['track'] ) ? get_term_by( 'slug', sanitize_title( wp_unslash( $_GET['track'] ) ), 'mh_track' ) : null;
		$subj  = isset( $_GET['subj'] ) ? absint( $_GET['subj'] ) : 0;
		$pg    = isset( $_GET['pg'] ) ? max( 1, absint( $_GET['pg'] ) ) : 1;
		// phpcs:enable
		$uid = get_current_user_id();
		$tgt = $uid ? MHP_Learn::target( $uid ) : array( 'track' => 0 );
		mhp_template(
			'lessons',
			array(
				'res'    => self::query(
					array(
						'track'   => $track ? $track->term_id : 0,
						'subject' => $subj,
						'page'    => $pg,
					)
				),
				'track'  => $track ? $track->slug : '',
				'subj'   => $subj,
				'pg'     => $pg,
				'tracks' => self::tracks(),
				'today'  => ( $track || $subj || $pg > 1 ) ? null : self::today( (int) $tgt['track'] ),
				'done'   => MHP_Points::refs( $uid, 'lesson' ),
			)
		);
	}

	/**
	 * One lesson.
	 *
	 * @param string $id Lesson id.
	 */
	public static function render_one( $id ) {
		$l = self::data( (int) $id );
		if ( ! $l || ( 'publish' !== $l['status'] && ! mhp_can_manage() ) ) {
			MHP_Front::not_found( 'পাঠটি পাওয়া যায়নি।', 'lessons' );
			return;
		}
		$uid  = get_current_user_id();
		$open = self::can_open( $l, $uid );
		$post = get_post( $l['id'] );
		mhp_template(
			'lesson',
			array(
				'l'      => $l,
				'open'   => $open,
				'body'   => $open ? wp_kses_post( wpautop( (string) $post->post_content ) ) : '',
				'qs'     => $open ? self::practice( $l['id'] ) : array(),
				'done'   => MHP_Points::has( $uid, 'lesson', (string) $l['id'] ),
				'next'   => self::neighbour( $l ),
				'pack'   => $l['on_sale'] ? get_post( $l['pack'] ) : null,
				'pay_ok' => MHP_Gateway_SSLCommerz::available(),
			)
		);
	}

	/**
	 * Next lesson in the same track (menu order, then date).
	 *
	 * @param array $l Lesson.
	 * @return array|null
	 */
	public static function neighbour( $l ) {
		$items = self::query(
			array(
				'track'    => $l['track'] ? $l['track']['id'] : 0,
				'per_page' => 200,
			)
		)['items'];
		foreach ( $items as $i => $it ) {
			if ( $it['id'] === $l['id'] ) {
				return isset( $items[ $i + 1 ] ) ? $items[ $i + 1 ] : null;
			}
		}
		return null;
	}

	/**
	 * Hub POST: "পাঠ শেষ করেছি".
	 */
	public static function handle_done() {
		$lid = isset( $_POST['lesson_id'] ) ? absint( $_POST['lesson_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by the dispatcher.
		$uid = get_current_user_id();
		$l   = self::data( $lid );
		if ( ! $l || 'publish' !== $l['status'] || ! self::can_open( $l, $uid ) ) {
			mhp_flash( 'পাঠটি পাওয়া যায়নি।', 'error' );
			wp_safe_redirect( mhp_url( 'lessons' ) );
			exit;
		}
		if ( ! MHP_Points::has( $uid, 'lesson', (string) $lid ) ) {
			if ( ! mhp_rate_ok( 'lesson_done', 20, DAY_IN_SECONDS ) ) {
				mhp_flash( 'আজ অনেক পাঠ শেষ হয়েছে। বাকিগুলো কাল চিহ্নিত করুন।', 'error' );
			} else {
				$pts = MHP_Points::rules()['lesson'];
				MHP_Points::award( $uid, 'lesson', (string) $lid, $pts );
				mhp_flash( 'দারুণ! পাঠটি শেষ হিসেবে চিহ্নিত হলো' . ( $pts > 0 ? ' (+' . mhp_bn( $pts ) . ' পয়েন্ট)।' : '।' ) );
			}
		}
		$next = self::neighbour( $l );
		wp_safe_redirect( $next ? mhp_url( 'lesson', $next['id'] ) : mhp_url( 'lesson', $lid ) );
		exit;
	}

	/**
	 * First-run lessons (drafts for an editor to check and publish).
	 */
	public static function maybe_seed() {
		if ( get_option( 'mhp_lessons_seeded' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		update_option( 'mhp_lessons_seeded', 1, false );
		$file = MHP_DIR . '/data/lessons.php';
		if ( ! is_readable( $file ) ) {
			return;
		}
		$rows = include $file;
		foreach ( (array) $rows as $r ) {
			$id = wp_insert_post(
				array(
					'post_type'    => 'mh_lesson',
					'post_status'  => 'draft',
					'post_title'   => $r['title'],
					'post_excerpt' => $r['excerpt'],
					'post_content' => $r['content'],
					'menu_order'   => (int) $r['order'],
				),
				true
			);
			if ( is_wp_error( $id ) ) {
				continue;
			}
			update_post_meta( $id, '_mhp_minutes', (int) $r['minutes'] );
			if ( ! empty( $r['subject'] ) ) {
				$t = term_exists( $r['subject'], 'mh_subject' );
				if ( $t ) {
					wp_set_object_terms( $id, array( (int) $t['term_id'] ), 'mh_subject' );
				}
			}
		}
	}
}
