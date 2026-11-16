<?php
/**
 * Starter content (data/seed.php): job types, subjects, tracks with syllabus, a reviewed question bank, one
 * free model test per track and career-guide drafts. Idempotent: every item carries a seed key and is only
 * created when missing, so running it twice (or after an editor changed things) never duplicates or overwrites.
 *
 * @package meeha-prep
 */

defined( 'ABSPATH' ) || exit;

/**
 * Seeder.
 */
class MHP_Seeder {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_run' ), 20 );
	}

	/**
	 * Run once after activation (CPTs/taxonomies exist by admin_init).
	 */
	public static function maybe_run() {
		if ( ! get_option( 'mhp_seed_pending' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		delete_option( 'mhp_seed_pending' );
		$r = self::run();
		update_option( 'mhp_seeded', time(), false );
		set_transient( 'mhp_seed_notice', $r, 10 * MINUTE_IN_SECONDS );
	}

	/**
	 * Install whatever is missing.
	 *
	 * @return array Counts of created items.
	 */
	public static function run() {
		$data = include MHP_DIR . '/data/seed.php';
		$out  = array(
			'terms'     => 0,
			'questions' => 0,
			'tests'     => 0,
			'guides'    => 0,
		);
		if ( ! is_array( $data ) ) {
			return $out;
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		wp_defer_term_counting( true );

		$types = array();
		foreach ( $data['job_types'] as $slug => $name ) {
			$types[ $slug ] = self::term( $slug, $name, 'mh_job_type', $out );
		}
		$subjects = array();
		foreach ( $data['subjects'] as $slug => $name ) {
			$subjects[ $slug ] = self::term( $slug, $name, 'mh_subject', $out );
		}
		$tracks = array();
		foreach ( $data['tracks'] as $slug => $t ) {
			$exists          = term_exists( $slug, 'mh_track' );
			$id              = self::term( $slug, $t['name'], 'mh_track', $out, $t['about'] );
			$tracks[ $slug ] = $id;
			if ( $id && ! $exists ) {
				update_term_meta( $id, 'mhp_short', $t['desc'] );
				update_term_meta( $id, 'mhp_color', $t['color'] );
				update_term_meta( $id, 'mhp_glyph', $t['glyph'] );
				update_term_meta( $id, 'mhp_order', (int) $t['order'] );
				update_term_meta( $id, 'mhp_syllabus', $t['syllabus'] );
				update_term_meta( $id, 'mhp_syllabus_note', $t['syl_note'] );
				if ( $t['job_type'] && ! empty( $types[ $t['job_type'] ] ) ) {
					update_term_meta( $id, 'mhp_job_type', (int) $types[ $t['job_type'] ] );
				}
			}
		}

		// Questions.
		$by_track = array();
		foreach ( $data['questions'] as $q ) {
			list( $subj, $tr, $text, $opts, $ans, $explain, $quiz ) = $q;
			$key = 'q-' . md5( $text );
			$qid = self::find( 'mh_question', $key );
			if ( ! $qid ) {
				$qid = wp_insert_post(
					array(
						'post_type'   => 'mh_question',
						'post_status' => 'publish',
						'post_title'  => $text,
					),
					true
				);
				if ( is_wp_error( $qid ) ) {
					continue;
				}
				update_post_meta( $qid, '_mhp_seed_key', $key );
				update_post_meta( $qid, '_mhp_opts', array_values( $opts ) );
				update_post_meta( $qid, '_mhp_answer', (int) $ans );
				update_post_meta( $qid, '_mhp_explain', $explain );
				update_post_meta( $qid, '_mhp_quiz_ok', $quiz ? 1 : 0 );
				if ( ! empty( $subjects[ $subj ] ) ) {
					wp_set_object_terms( $qid, array( (int) $subjects[ $subj ] ), 'mh_subject' );
				}
				$tids = array();
				foreach ( explode( ',', $tr ) as $s ) {
					if ( ! empty( $tracks[ trim( $s ) ] ) ) {
						$tids[] = (int) $tracks[ trim( $s ) ];
					}
				}
				wp_set_object_terms( $qid, $tids, 'mh_track' );
				++$out['questions'];
			}
			foreach ( explode( ',', $tr ) as $s ) {
				$by_track[ trim( $s ) ][] = (int) $qid;
			}
		}

		// One free model test per track, built from that track's questions (max 25).
		foreach ( $data['tracks'] as $slug => $t ) {
			$key = 'test-' . $slug . '-1';
			if ( empty( $tracks[ $slug ] ) || empty( $by_track[ $slug ] ) || self::find( 'mh_test', $key ) ) {
				continue;
			}
			$ids = array_slice( array_values( array_unique( $by_track[ $slug ] ) ), 0, 25 );
			$tid = wp_insert_post(
				array(
					'post_type'    => 'mh_test',
					'post_status'  => 'publish',
					'post_title'   => $t['short'] . ' মডেল টেস্ট ১',
					'post_excerpt' => 'ফ্রি মডেল টেস্ট: সময় ধরে দিন, শেষে ব্যাখ্যাসহ উত্তরপত্র দেখুন।',
					'menu_order'   => 1,
				),
				true
			);
			if ( is_wp_error( $tid ) ) {
				continue;
			}
			update_post_meta( $tid, '_mhp_seed_key', $key );
			update_post_meta( $tid, '_mhp_questions', $ids );
			update_post_meta( $tid, '_mhp_price', 0 );
			update_post_meta( $tid, '_mhp_duration', max( 5, count( $ids ) ) );
			update_post_meta( $tid, '_mhp_negative', 'bcs' === $slug ? 0.5 : 0 );
			wp_set_object_terms( $tid, array( (int) $tracks[ $slug ] ), 'mh_track' );
			++$out['tests'];
		}

		// Career guides as drafts in the theme's career category (editors review and publish).
		$cat = function_exists( 'meeha_campus_cat' ) ? (int) meeha_campus_cat( 'career' ) : 0;
		foreach ( $data['guides'] as $g ) {
			$key = 'guide-' . md5( $g['title'] );
			if ( self::find( 'post', $key ) ) {
				continue;
			}
			$pid = wp_insert_post(
				array(
					'post_type'     => 'post',
					'post_status'   => 'draft',
					'post_title'    => $g['title'],
					'post_content'  => $g['body'],
					'post_category' => $cat ? array( $cat ) : array(),
					'post_author'   => get_current_user_id(),
				),
				true
			);
			if ( ! is_wp_error( $pid ) ) {
				update_post_meta( $pid, '_mhp_seed_key', $key );
				++$out['guides'];
			}
		}

		wp_defer_term_counting( false );
		return $out;
	}

	/**
	 * Create a term if its slug is missing.
	 *
	 * @param string $slug Slug.
	 * @param string $name Name.
	 * @param string $tax  Taxonomy.
	 * @param array  $out  Counter (by reference).
	 * @param string $desc Description.
	 * @return int Term id.
	 */
	private static function term( $slug, $name, $tax, &$out, $desc = '' ) {
		$t = get_term_by( 'slug', $slug, $tax );
		if ( $t ) {
			return (int) $t->term_id;
		}
		$r = wp_insert_term(
			$name,
			$tax,
			array(
				'slug'        => $slug,
				'description' => $desc,
			)
		);
		if ( is_wp_error( $r ) ) {
			return 0;
		}
		++$out['terms'];
		return (int) $r['term_id'];
	}

	/**
	 * Find a seeded post by key (any status, trash included, so a deleted item is not recreated).
	 *
	 * @param string $type   Post type.
	 * @param string $key    Seed key.
	 * @return int
	 */
	private static function find( $type, $key ) {
		$ids = get_posts(
			array(
				'post_type'      => $type,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => '_mhp_seed_key', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => $key, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}
}
