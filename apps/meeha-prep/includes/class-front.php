<?php
/**
 * Front end glue with the theme's section router (`meeha_campus_views` filter):
 * registers the plugin's views, dispatches hub form POSTs (nonce + login + capability), streams protected
 * downloads, enqueues the test/quiz script.
 *
 * @package meeha-prep
 */

defined( 'ABSPATH' ) || exit;

/**
 * Front.
 */
class MHP_Front {

	/**
	 * Actions guests may post (everything else needs login). quiz_answer is side-effect free for guests.
	 *
	 * @var string[]
	 */
	const GUEST_ACTIONS = array( 'employer_submit', 'quiz_answer' );

	/**
	 * All hub actions.
	 *
	 * @var string[]
	 */
	const ACTIONS = array( 'checkout', 'start_test', 'submit_test', 'quiz_answer', 'employer_submit', 'story_submit', 'alerts_save', 'save_job' );

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'meeha_campus_views', array( __CLASS__, 'views' ) );
		add_action( 'template_redirect', array( __CLASS__, 'dispatch' ), 4 );
		add_action( 'template_redirect', array( __CLASS__, 'download' ), 5 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 20 );
		add_filter( 'meeha_campus_has_engine', '__return_true' );
	}

	/**
	 * Nonce field for a hub action.
	 *
	 * @param string $action Action.
	 * @return string
	 */
	public static function form_fields( $action ) {
		return '<input type="hidden" name="mhp_action" value="' . esc_attr( $action ) . '">' . wp_nonce_field( 'mhp_' . $action, '_mhpnonce', false, false );
	}

	/**
	 * Hub POST dispatcher.
	 */
	public static function dispatch() {
		if ( empty( $_POST['mhp_action'] ) || ! function_exists( 'meeha_campus_is_hub' ) || ! meeha_campus_is_hub() ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}
		mhp_nocache();
		$action = sanitize_key( wp_unslash( $_POST['mhp_action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! in_array( $action, (array) apply_filters( 'mhp_hub_actions', self::ACTIONS ), true ) ) {
			wp_die( esc_html( 'অজানা অনুরোধ।' ), 400 );
		}
		$guest_ok = in_array( $action, self::GUEST_ACTIONS, true );
		if ( ! is_user_logged_in() && ! $guest_ok ) {
			wp_safe_redirect( mhp_login_url( wp_get_referer() ? wp_get_referer() : mhp_url( 'home' ) ) );
			exit;
		}
		// Guests' quiz answers change nothing on the server, so a stale cached nonce must not block them.
		$skip_nonce = ( 'quiz_answer' === $action && ! is_user_logged_in() );
		if ( ! $skip_nonce ) {
			$nonce = isset( $_POST['_mhpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_mhpnonce'] ) ) : '';
			if ( ! wp_verify_nonce( $nonce, 'mhp_' . $action ) ) {
				mhp_flash( 'পাতার মেয়াদ শেষ হয়ে গিয়েছিল। আবার চেষ্টা করুন।', 'error' );
				wp_safe_redirect( wp_get_referer() ? wp_get_referer() : mhp_url( 'home' ) );
				exit;
			}
		}
		if ( function_exists( 'meeha_campus_beta_blocked' ) && meeha_campus_beta_blocked() ) {
			wp_die( esc_html( 'এই সেকশন এখনো পরীক্ষামূলক।' ), 403 );
		}
		do_action( 'mhp_hub_action_' . $action );
		wp_safe_redirect( mhp_url( 'home' ) );
		exit;
	}

	/**
	 * Register views with the theme router.
	 *
	 * @param array $views Views.
	 * @return array
	 */
	public static function views( $views ) {
		$v = array(
			'jobs'        => array( 'title' => 'চাকরির বিজ্ঞপ্তি', 'tab' => 'jobs', 'root' => true ),
			'job'         => array( 'title' => array( __CLASS__, 'title_job' ), 'tab' => 'jobs', 'back' => 'jobs', 'needs_id' => true, 'bar' => false ),
			'prep'        => array( 'title' => 'পরীক্ষার প্রস্তুতি', 'tab' => 'prep', 'root' => true ),
			'track'       => array( 'title' => array( __CLASS__, 'title_track' ), 'tab' => 'prep', 'back' => 'prep', 'needs_id' => true, 'nocache' => true ),
			'test'        => array( 'title' => array( __CLASS__, 'title_test' ), 'tab' => 'prep', 'back' => 'prep', 'needs_id' => true, 'bar' => false ),
			'run'         => array( 'title' => 'পরীক্ষা চলছে', 'tab' => 'prep', 'needs_id' => true, 'nocache' => true, 'noindex' => true, 'bar' => false, 'chrome' => 'exam' ),
			'result'      => array( 'title' => 'ফলাফল', 'tab' => 'prep', 'back' => 'prep', 'needs_id' => true, 'nocache' => true, 'noindex' => true, 'bar' => false ),
			'quiz'        => array( 'title' => 'আজকের কুইজ', 'tab' => 'quiz', 'back' => 'home', 'nocache' => true ),
			'leaderboard' => array( 'title' => 'লিডারবোর্ড', 'tab' => 'quiz', 'back' => 'quiz', 'nocache' => true ),
			'events'      => array( 'title' => 'ইভেন্ট', 'tab' => 'home', 'back' => 'home' ),
			'event'       => array( 'title' => array( __CLASS__, 'title_event' ), 'tab' => 'home', 'back' => 'events', 'needs_id' => true, 'nocache' => true, 'bar' => false ),
			'me'          => array( 'title' => 'আমার পাতা', 'tab' => 'me', 'root' => true, 'nocache' => true, 'noindex' => true ),
			'employer'    => array( 'title' => 'নিয়োগদাতা ও বিজ্ঞাপনদাতা', 'tab' => 'home', 'back' => 'home', 'nocache' => true ),
			'checkout'    => array( 'title' => 'পেমেন্ট', 'tab' => 'prep', 'back' => 'prep', 'needs_id' => true, 'nocache' => true, 'noindex' => true, 'bar' => false ),
			'order'       => array( 'title' => 'অর্ডারের অবস্থা', 'tab' => 'me', 'back' => 'me', 'needs_id' => true, 'nocache' => true, 'noindex' => true ),
		);
		foreach ( $v as $k => $def ) {
			$def['render'] = array( __CLASS__, 'render_' . $k );
			$views[ $k ]   = $def;
		}
		return $views;
	}

	/**
	 * Title callbacks.
	 *
	 * @param string $id Id.
	 * @return string
	 */
	public static function title_job( $id ) {
		$j = get_post( (int) $id );
		return ( $j && 'mh_job' === $j->post_type ) ? get_the_title( $j ) . ( mhp_meta( $j->ID, 'org', '' ) ? ' — ' . mhp_meta( $j->ID, 'org', '' ) : '' ) : 'বিজ্ঞপ্তি';
	}

	/**
	 * Track title.
	 *
	 * @param string $id Slug.
	 * @return string
	 */
	public static function title_track( $id ) {
		$t = get_term_by( 'slug', sanitize_title( $id ), 'mh_track' );
		return $t ? $t->name . ' প্রস্তুতি' : 'প্রস্তুতি';
	}

	/**
	 * Test title.
	 *
	 * @param string $id Id.
	 * @return string
	 */
	public static function title_test( $id ) {
		$t = get_post( (int) $id );
		return ( $t && 'mh_test' === $t->post_type ) ? get_the_title( $t ) : 'টেস্ট';
	}

	/**
	 * Event title.
	 *
	 * @param string $id Id.
	 * @return string
	 */
	public static function title_event( $id ) {
		$e = get_post( (int) $id );
		return ( $e && 'mh_event' === $e->post_type ) ? get_the_title( $e ) : 'ইভেন্ট';
	}

	/**
	 * Scripts for the exam/quiz/job views.
	 */
	public static function assets() {
		if ( ! function_exists( 'meeha_campus_is_hub' ) || ! meeha_campus_is_hub() ) {
			return;
		}
		$view = function_exists( 'meeha_campus_view' ) ? meeha_campus_view() : array( 'view' => '' );
		wp_enqueue_script( 'mhp-prep', MHP_URI . '/assets/js/prep.js', array(), MHP_VERSION, array( 'strategy' => 'defer', 'in_footer' => true ) );
		wp_localize_script(
			'mhp-prep',
			'MHP',
			array(
				'rest'   => esc_url_raw( rest_url( 'meeha-prep/v1/' ) ),
				'ajax'   => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
				'nonce'  => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
				'logged' => is_user_logged_in(),
				'login'  => esc_url_raw( mhp_login_url( mhp_current_url() ) ),
				'view'   => $view['view'],
			)
		);
	}

	/**
	 * Stream a purchased file: hub view "download/<product id>".
	 */
	public static function download() {
		if ( ! function_exists( 'meeha_campus_is_hub' ) || ! meeha_campus_is_hub() ) {
			return;
		}
		$v = meeha_campus_view();
		if ( 'download' !== $v['view'] ) {
			return;
		}
		mhp_nocache();
		$pid = (int) $v['id'];
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( mhp_login_url( mhp_current_url() ) );
			exit;
		}
		if ( ! MHP_Access::has( get_current_user_id(), 'product', $pid ) && ! mhp_can_shop() ) {
			wp_die( esc_html( 'এই ফাইলটি আপনার কেনা নেই।' ), 403 );
		}
		$path = self::private_path( (string) mhp_meta( $pid, 'file', '' ) );
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

	/**
	 * Private upload directory (uploads/meeha-prep-private), created with deny rules.
	 *
	 * @return string Absolute path.
	 */
	public static function private_dir() {
		$up  = wp_upload_dir( null, false );
		$dir = trailingslashit( $up['basedir'] ) . 'meeha-prep-private';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php\n// Silence.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		return $dir;
	}

	/**
	 * Resolve a stored private file name safely (no traversal).
	 *
	 * @param string $stored Stored basename.
	 * @return string Absolute path or ''.
	 */
	public static function private_path( $stored ) {
		$stored = basename( (string) $stored );
		if ( '' === $stored || '.' === $stored[0] ) {
			return '';
		}
		$path = self::private_dir() . '/' . $stored;
		return is_file( $path ) ? $path : '';
	}

	/* ------------------------------------------------------------------
	 * View renderers (markup in templates/, styled by the theme's campus.css)
	 * ---------------------------------------------------------------- */

	/**
	 * Jobs list.
	 *
	 * @param string $id Unused.
	 */
	public static function render_jobs( $id ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$f = array(
			'q'    => isset( $_GET['q'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_GET['q'] ) ), 0, 80 ) : '',
			'type' => isset( $_GET['type'] ) ? sanitize_title( wp_unslash( $_GET['type'] ) ) : '',
			'edu'  => isset( $_GET['edu'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_GET['edu'] ) ), 0, 30 ) : '',
			'loc'  => isset( $_GET['loc'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_GET['loc'] ) ), 0, 40 ) : '',
			'soon' => ! empty( $_GET['soon'] ),
			'page' => isset( $_GET['pg'] ) ? max( 1, absint( $_GET['pg'] ) ) : 1,
		);
		// phpcs:enable
		$res = MHP_Jobs::query( array_merge( $f, array( 'per_page' => 12 ) ) );
		mhp_template(
			'jobs',
			array(
				'f'      => $f,
				'res'    => $res,
				'types'  => get_terms(
					array(
						'taxonomy'   => 'mh_job_type',
						'hide_empty' => false,
					)
				),
				'facets' => MHP_Jobs::facets(),
				'saved'  => is_user_logged_in() ? MHP_Jobs::saved( get_current_user_id() ) : array(),
			)
		);
	}

	/**
	 * One job.
	 *
	 * @param string $id Job id.
	 */
	public static function render_job( $id ) {
		$j = MHP_Jobs::data( (int) $id );
		if ( ! $j || ( 'publish' !== $j['status'] && ! mhp_can_manage() ) ) {
			self::not_found( 'বিজ্ঞপ্তিটি পাওয়া যায়নি বা সরিয়ে নেওয়া হয়েছে।', 'jobs' );
			return;
		}
		$similar = MHP_Jobs::query(
			array(
				'type'     => $j['type_slug'],
				'per_page' => 3,
				'exclude'  => array( $j['id'] ),
			)
		);
		mhp_template(
			'job',
			array(
				'j'       => $j,
				'saved'   => is_user_logged_in() && in_array( $j['id'], MHP_Jobs::saved( get_current_user_id() ), true ),
				'track'   => $j['track'] ? mhp_track( $j['track'] ) : null,
				'similar' => $similar['items'],
			)
		);
	}

	/**
	 * Prep home.
	 *
	 * @param string $id Unused.
	 */
	public static function render_prep( $id ) {
		$uid = get_current_user_id();
		mhp_template(
			'prep',
			array(
				'tracks'   => mhp_tracks(),
				'live'     => MHP_Tests::next_live(),
				'products' => self::products(),
				'last'     => $uid ? MHP_Tests::results_of( $uid, 1 ) : array(),
			)
		);
	}

	/**
	 * Published products.
	 *
	 * @param int $track Track filter.
	 * @return WP_Post[]
	 */
	public static function products( $track = 0 ) {
		$args = array(
			'post_type'      => 'mh_product',
			'post_status'    => 'publish',
			'posts_per_page' => 30,
			'orderby'        => array(
				'menu_order' => 'ASC',
				'date'       => 'DESC',
			),
			'no_found_rows'  => true,
		);
		if ( $track ) {
			$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'taxonomy' => 'mh_track',
					'terms'    => (int) $track,
				),
			);
		}
		return get_posts( $args );
	}

	/**
	 * Track page.
	 *
	 * @param string $id Track slug.
	 */
	public static function render_track( $id ) {
		$term = get_term_by( 'slug', sanitize_title( $id ), 'mh_track' );
		if ( ! $term ) {
			self::not_found( 'ট্র্যাকটি পাওয়া যায়নি।', 'prep' );
			return;
		}
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'tests'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$pg  = isset( $_GET['pg'] ) ? max( 1, absint( $_GET['pg'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		mhp_template(
			'track',
			array(
				'track'    => mhp_track( $term ),
				'tests'    => MHP_Tests::list_tests( $term->term_id ),
				'products' => self::products( $term->term_id ),
				'tab'      => in_array( $tab, array( 'tests', 'practice', 'syllabus' ), true ) ? $tab : 'tests',
				'pg'       => $pg,
				'takers'   => MHP_Tests::track_takers( $term->term_id ),
			)
		);
	}

	/**
	 * Practice questions of a track, with answers. Left out: questions of paid tests and of live (scheduled) tests
	 * in any status, and today's quiz — so no answer of a sold or ranked test leaks here.
	 *
	 * @param int $track_id Track.
	 * @param int $page     Page.
	 * @return array items, pages
	 */
	public static function practice_questions( $track_id, $page ) {
		$paid_q = self::protected_questions();
		$q      = new WP_Query(
			array(
				'post_type'      => 'mh_question',
				'post_status'    => 'publish',
				'posts_per_page' => 15,
				'paged'          => $page,
				'fields'         => 'ids',
				'post__not_in'   => $paid_q ? array_values( array_unique( $paid_q ) ) : array(),
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'taxonomy' => 'mh_track',
						'terms'    => (int) $track_id,
					),
				),
			)
		);
		return array(
			'items' => MHP_Tests::load_questions( $q->posts, true ),
			'pages' => (int) $q->max_num_pages,
			'total' => (int) $q->found_posts,
		);
	}

	/**
	 * Questions whose answers must not be shown outside their test: those of paid tests and of live (scheduled)
	 * tests in any status, today's quiz, and the practice of lessons sold in a pack.
	 *
	 * @param int $except_lesson A pack lesson whose own questions may show (its reader can open it).
	 * @return int[]
	 */
	public static function protected_questions( $except_lesson = 0 ) {
		static $ids = null;
		if ( null !== $ids ) {
			return array_values( array_unique( array_merge( $ids, self::paid_lesson_questions( $except_lesson ) ) ) );
		}
		$ids = array_map( 'intval', MHP_Quiz::set_for( mhp_today() ) );
		foreach ( get_posts(
			array(
				'post_type'      => 'mh_test',
				'post_status'    => array_keys( get_post_stati() ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		) as $tid ) {
			if ( (float) mhp_meta( $tid, 'price', 0 ) > 0 || '' !== (string) mhp_meta( $tid, 'live_start', '' ) ) {
				$ids = array_merge( $ids, array_map( 'intval', (array) mhp_meta( $tid, 'questions', array() ) ) );
			}
		}
		$ids = array_values( array_unique( $ids ) );
		return self::protected_questions( $except_lesson );
	}

	/**
	 * Practice questions of lessons sold in a pack (lesson id => question ids). A lesson stays tied to its pack
	 * even while the pack is paused, so these never show free elsewhere.
	 *
	 * @param int $except Lesson whose own questions to keep (the one being read by a reader who may open it).
	 * @return int[]
	 */
	public static function paid_lesson_questions( $except = 0 ) {
		static $by = null;
		if ( null === $by ) {
			$by = array();
			foreach ( get_posts(
				array(
					'post_type'      => 'mh_lesson',
					'post_status'    => array_keys( get_post_stati() ),
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_key'       => '_mhp_product', // phpcs:ignore WordPress.DB.SlowDBQuery
				)
			) as $lid ) {
				if ( (int) mhp_meta( $lid, 'product', 0 ) ) {
					$by[ (int) $lid ] = array_map( 'intval', (array) mhp_meta( $lid, 'questions', array() ) );
				}
			}
		}
		$out = array();
		foreach ( $by as $lid => $qs ) {
			if ( (int) $except !== $lid ) {
				$out = array_merge( $out, $qs );
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Test intro.
	 *
	 * @param string $id Test id.
	 */
	public static function render_test( $id ) {
		$info = MHP_Tests::info( (int) $id );
		if ( ! $info || ( 'publish' !== $info['status'] && ! mhp_can_manage() ) ) {
			self::not_found( 'টেস্টটি পাওয়া যায়নি।', 'prep' );
			return;
		}
		$uid = get_current_user_id();
		mhp_template(
			'test',
			array(
				't'      => $info,
				'own'    => MHP_Access::can_take_test( $uid, $info['id'] ),
				'last'   => MHP_Tests::last_result( $uid, $info['id'] ),
				'packs'  => self::packs_with_test( $info['id'] ),
				'pay_ok' => MHP_Gateway_SSLCommerz::available(),
			)
		);
	}

	/**
	 * Bundles that contain a test.
	 *
	 * @param int $test_id Test.
	 * @return WP_Post[]
	 */
	public static function packs_with_test( $test_id ) {
		$out = array();
		foreach ( self::products() as $p ) {
			if ( 'bundle' === mhp_meta( $p->ID, 'ptype', 'bundle' ) && in_array( (int) $test_id, array_map( 'intval', (array) mhp_meta( $p->ID, 'tests', array() ) ), true ) ) {
				$out[] = $p;
			}
		}
		return $out;
	}

	/**
	 * Exam runner.
	 *
	 * @param string $id Attempt id.
	 */
	public static function render_run( $id ) {
		if ( ! is_user_logged_in() ) {
			self::login_box( 'পরীক্ষা চালিয়ে যেতে লগইন করুন।' );
			return;
		}
		$a = MHP_Tests::get_attempt( (int) $id );
		if ( ! $a || (int) $a['user_id'] !== get_current_user_id() ) {
			self::not_found( 'পরীক্ষাটি পাওয়া যায়নি।', 'prep' );
			return;
		}
		if ( ! MHP_Tests::accepting( $a ) ) {
			if ( 'active' === $a['status'] ) {
				MHP_Tests::submit( $a, null );
			}
			echo '<div class="mc-sec mc-g"><div class="mc-card mc-pad mc-center"><p>সময় শেষ, উত্তরপত্র জমা হয়েছে।</p><a class="mc-btn" href="' . esc_url( mhp_url( 'result', $a['id'] ) ) . '">ফল দেখুন</a></div></div>';
			return;
		}
		mhp_template(
			'run',
			array(
				'a'    => $a,
				'info' => MHP_Tests::info( $a['test_id'] ),
				'qs'   => MHP_Tests::load_questions( $a['qids'], false ),
				'left' => max( 0, strtotime( $a['deadline_at'] . ' UTC' ) - time() ),
			)
		);
	}

	/**
	 * Result.
	 *
	 * @param string $id Attempt id.
	 */
	public static function render_result( $id ) {
		if ( ! is_user_logged_in() ) {
			self::login_box( 'ফল দেখতে লগইন করুন।' );
			return;
		}
		$a = MHP_Tests::own_attempt( (int) $id );
		if ( ! $a ) {
			self::not_found( 'ফলটি পাওয়া যায়নি।', 'prep' );
			return;
		}
		if ( 'active' === $a['status'] ) {
			if ( MHP_Tests::accepting( $a ) ) {
				echo '<section class="mc-sec mc-g"><div class="mc-card mc-pad mc-center"><p>পরীক্ষাটি এখনো চলছে।</p><a class="mc-btn" href="' . esc_url( mhp_url( 'run', $a['id'] ) ) . '">পরীক্ষায় ফিরুন</a></div></section>';
				return;
			}
			$a = MHP_Tests::submit( $a, null );
		}
		$info = MHP_Tests::info( $a['test_id'] );
		// Live test: keep the answer sheet back until the window closes, so early finishers can't pass answers on.
		$hold = '';
		if ( $info && $info['live'] ) {
			$until = $info['end'] ? MHP_Tests::norm_dt( $info['end'] ) : gmdate( 'Y-m-d H:i:s', strtotime( MHP_Tests::norm_dt( $info['start'] ) ) + ( $info['minutes'] + 10 ) * MINUTE_IN_SECONDS );
			$hold  = mhp_now_local() < $until ? $until : '';
		}
		mhp_template(
			'result',
			array(
				'hold'  => $hold,
				'a'     => $a,
				'info'  => $info,
				'qs'    => MHP_Tests::load_questions( $a['qids'], true ),
				'rank'  => MHP_Tests::rank( $a ),
				'packs' => $info && $info['track'] ? self::products( $info['track']['id'] ) : array(),
			)
		);
	}

	/**
	 * Daily quiz.
	 *
	 * @param string $id Unused.
	 */
	public static function render_quiz( $id ) {
		$uid  = get_current_user_id();
		$date = mhp_today();
		MHP_Quiz::begin( $uid );
		mhp_template(
			'quiz',
			array(
				'qs'     => MHP_Quiz::today_questions(),
				'play'   => MHP_Quiz::play( $uid, $date ),
				'streak' => MHP_Quiz::streak( $uid ),
				'board'  => MHP_Quiz::leaderboard( 'today', 7 ),
				'count'  => MHP_Quiz::played_count( $date ),
				'guest'  => ! $uid,
				'review' => $uid ? MHP_Tests::load_questions( MHP_Quiz::set_for( $date ), true ) : array(),
			)
		);
	}

	/**
	 * Leaderboard.
	 *
	 * @param string $id Unused.
	 */
	public static function render_leaderboard( $id ) {
		$range = isset( $_GET['range'] ) ? sanitize_key( wp_unslash( $_GET['range'] ) ) : 'today'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$range = in_array( $range, array( 'today', 'week', 'month', 'points' ), true ) ? $range : 'today';
		mhp_template(
			'leaderboard',
			array(
				'range' => $range,
				'rows'  => 'points' === $range ? MHP_Points::leaderboard_week( 50 ) : MHP_Quiz::leaderboard( $range, 50 ),
			)
		);
	}

	/**
	 * Events.
	 *
	 * @param string $id Unused.
	 */
	public static function render_events( $id ) {
		mhp_template( 'events', array( 'events' => MHP_Events::upcoming( 30 ) ) );
	}

	/**
	 * Event.
	 *
	 * @param string $id Event id.
	 */
	public static function render_event( $id ) {
		$e = MHP_Events::data( (int) $id );
		if ( ! $e || ( 'publish' !== get_post_status( $e['id'] ) && ! mhp_can_manage() ) ) {
			self::not_found( 'ইভেন্টটি পাওয়া যায়নি।', 'events' );
			return;
		}
		mhp_template(
			'event',
			array(
				'e'      => $e,
				'ticket' => MHP_Events::ticket( get_current_user_id(), $e['id'] ),
				'pay_ok' => MHP_Gateway_SSLCommerz::available(),
			)
		);
	}

	/**
	 * My page.
	 *
	 * @param string $id Unused.
	 */
	public static function render_me( $id ) {
		$uid = get_current_user_id();
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		mhp_template(
			'me',
			array(
				'uid'     => $uid,
				'tab'     => in_array( $tab, array( 'overview', 'saved', 'results', 'purchases', 'tickets', 'alerts', 'story', 'consult', 'cv' ), true ) ? $tab : 'overview',
				'results' => $uid ? MHP_Tests::results_of( $uid, 100 ) : array(),
				'saved'   => $uid ? MHP_Jobs::saved( $uid ) : array(),
				'orders'  => $uid ? MHP_Orders::of_user( $uid ) : array(),
				'items'   => $uid ? MHP_Access::of_user( $uid, 'product' ) : array(),
				'tickets' => $uid ? MHP_Access::of_user( $uid, 'event' ) : array(),
				'streak'  => MHP_Quiz::streak( $uid ),
			)
		);
	}

	/**
	 * Employer / advertiser page.
	 *
	 * @param string $id Unused.
	 */
	public static function render_employer( $id ) {
		mhp_template( 'employer', array( 'sent' => ! empty( $_GET['sent'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Checkout confirmation (id = "<type>-<id>").
	 *
	 * @param string $id Item key.
	 */
	public static function render_checkout( $id ) {
		if ( ! is_user_logged_in() ) {
			self::login_box( 'কিনতে লগইন করুন। পাসওয়ার্ড ভুলে গেলে লগইন পাতা থেকে নতুন করে নিতে পারবেন।' );
			return;
		}
		$parts = explode( '-', (string) $id, 2 );
		$type  = sanitize_key( $parts[0] );
		$item  = isset( $parts[1] ) ? absint( $parts[1] ) : 0;
		$it    = MHP_Orders::item( $type, $item );
		mhp_template(
			'checkout',
			array(
				'type'   => $type,
				'item'   => $item,
				'it'     => $it,
				'owned'  => $it['ok'] && MHP_Access::has( get_current_user_id(), $type, $item ),
				'pay_ok' => MHP_Gateway_SSLCommerz::available(),
				'phone'  => (string) get_user_meta( get_current_user_id(), 'meeha_phone', true ),
			)
		);
	}

	/**
	 * Receipt.
	 *
	 * @param string $id Tran id.
	 */
	public static function render_order( $id ) {
		if ( ! is_user_logged_in() ) {
			self::login_box( 'অর্ডার দেখতে লগইন করুন।' );
			return;
		}
		$o = MHP_Orders::by_tran( (string) $id );
		if ( ! $o || ( (int) $o['user_id'] !== get_current_user_id() && ! mhp_can_shop() ) ) {
			self::not_found( 'অর্ডারটি পাওয়া যায়নি।', 'me' );
			return;
		}
		// A pending order the buyer is looking at: ask the gateway (at most once every two minutes per order).
		if ( 'pending' === $o['status'] && ! get_transient( 'mhp_rc_' . $o['id'] ) ) {
			set_transient( 'mhp_rc_' . $o['id'], 1, 2 * MINUTE_IN_SECONDS );
			MHP_Checkout::reconcile( $o, '' );
			$o = MHP_Orders::by_tran( $o['tran_id'] );
		}
		mhp_template( 'order', array( 'o' => $o ) );
	}

	/**
	 * Login prompt.
	 *
	 * @param string $msg Message.
	 */
	public static function login_box( $msg ) {
		echo '<section class="mc-sec mc-g"><div class="mc-card mc-pad mc-center"><p>' . esc_html( $msg ) . '</p><a class="mc-btn" href="' . esc_url( mhp_login_url( mhp_current_url() ) ) . '">লগইন</a>';
		if ( function_exists( 'meeha_get_account_page_url' ) && get_option( 'users_can_register' ) ) {
			echo ' <a class="mc-btn mc-btn--ghost" href="' . esc_url( meeha_get_account_page_url( 'register' ) ) . '">নতুন অ্যাকাউন্ট</a>';
		}
		echo '</div></section>';
	}

	/**
	 * Not found box (and a 404 status).
	 *
	 * @param string $msg  Message.
	 * @param string $back View to go back to.
	 */
	public static function not_found( $msg, $back ) {
		if ( ! headers_sent() ) {
			status_header( 404 );
		}
		echo '<section class="mc-sec mc-g"><div class="mc-card mc-pad mc-center"><p>' . esc_html( $msg ) . '</p><a class="mc-btn mc-btn--soft" href="' . esc_url( mhp_url( $back ) ) . '">ফিরে যান</a></div></section>';
	}
}
