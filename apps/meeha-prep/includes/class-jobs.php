<?php
/**
 * Job circulars: queries, saved jobs, helpers. A job is "open" while its deadline (Asia/Dhaka) is today or later.
 *
 * @package meeha-prep
 */

defined( 'ABSPATH' ) || exit;

/**
 * Jobs.
 */
class MHP_Jobs {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'mhp_hub_action_save_job', array( __CLASS__, 'handle_save' ) );
	}

	/**
	 * Job data.
	 *
	 * @param WP_Post|int $post Job.
	 * @return array|null
	 */
	public static function data( $post ) {
		$post = get_post( $post );
		if ( ! $post || 'mh_job' !== $post->post_type ) {
			return null;
		}
		$id    = $post->ID;
		$types = wp_get_post_terms( $id, 'mh_job_type' );
		$type  = ( $types && ! is_wp_error( $types ) ) ? $types[0] : null;
		$feat  = (string) mhp_meta( $id, 'featured_until', '' );
		$dl    = (string) mhp_meta( $id, 'deadline', '' );
		$circ  = (int) mhp_meta( $id, 'circular_id', 0 );
		$steps = array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) mhp_meta( $id, 'steps', '' ) ) ) ) );
		return array(
			'id'          => $id,
			'title'       => get_the_title( $post ),
			'org'         => (string) mhp_meta( $id, 'org', '' ),
			'vacancies'   => (int) mhp_meta( $id, 'vacancies', 0 ),
			'deadline'    => $dl,
			'days_left'   => mhp_days_left( $dl ),
			'posted'      => get_the_date( 'Y-m-d', $post ),
			'type'        => $type ? $type->name : '',
			'type_id'     => $type ? (int) $type->term_id : 0,
			'type_slug'   => $type ? $type->slug : '',
			'education'   => (string) mhp_meta( $id, 'education', '' ),
			'age'         => (string) mhp_meta( $id, 'age', '' ),
			'location'    => (string) mhp_meta( $id, 'location', '' ),
			'grade'       => (string) mhp_meta( $id, 'grade', '' ),
			'fee'         => (string) mhp_meta( $id, 'fee', '' ),
			'salary'      => (string) mhp_meta( $id, 'salary', '' ),
			'apply_url'   => (string) mhp_meta( $id, 'apply_url', '' ),
			'apply_start' => (string) mhp_meta( $id, 'apply_start', '' ),
			'source'      => (string) mhp_meta( $id, 'source', '' ),
			'source_url'  => (string) mhp_meta( $id, 'source_url', '' ),
			'circular_id' => $circ,
			'featured'    => mhp_is_date( $feat ) && mhp_days_left( $feat ) >= 0,
			'track'       => (int) mhp_meta( $id, 'track', 0 ),
			'news'        => (int) mhp_meta( $id, 'news_post', 0 ),
			'steps'       => $steps,
			'content'     => $post->post_content,
			'status'      => $post->post_status,
		);
	}

	/**
	 * Query open jobs.
	 *
	 * @param array $args q, type (slug), edu, loc, soon (bool), featured (bool), page, per_page, exclude.
	 * @return array items (array[]), total, pages
	 */
	public static function query( $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'q'        => '',
				'type'     => '',
				'edu'      => '',
				'loc'      => '',
				'soon'     => false,
				'featured' => false,
				'page'     => 1,
				'per_page' => 12,
				'exclude'  => array(),
				'order'    => 'deadline',
			)
		);
		$today = mhp_today();
		$meta  = array(
			'relation' => 'AND',
			'dl'       => array(
				'key'     => '_mhp_deadline',
				'value'   => $today,
				'compare' => '>=',
				'type'    => 'CHAR',
			),
		);
		if ( $args['soon'] ) {
			$meta[] = array(
				'key'     => '_mhp_deadline',
				'value'   => gmdate( 'Y-m-d', strtotime( $today . ' +7 days' ) ),
				'compare' => '<=',
				'type'    => 'CHAR',
			);
		}
		if ( $args['featured'] ) {
			$meta[] = array(
				'key'     => '_mhp_featured_until',
				'value'   => $today,
				'compare' => '>=',
				'type'    => 'CHAR',
			);
		}
		if ( '' !== $args['edu'] ) {
			$meta[] = array(
				'key'     => '_mhp_education',
				'value'   => $args['edu'],
				'compare' => 'LIKE',
			);
		}
		if ( '' !== $args['loc'] ) {
			$meta[] = array(
				'relation' => 'OR',
				array(
					'key'     => '_mhp_location',
					'value'   => $args['loc'],
					'compare' => 'LIKE',
				),
				array(
					'key'     => '_mhp_location',
					'value'   => 'সারা দেশ',
					'compare' => 'LIKE',
				),
			);
		}
		$q = array(
			'post_type'      => 'mh_job',
			'post_status'    => 'publish',
			'posts_per_page' => max( 1, (int) $args['per_page'] ),
			'paged'          => max( 1, (int) $args['page'] ),
			'meta_query'     => $meta, // phpcs:ignore WordPress.DB.SlowDBQuery
			'orderby'        => 'newest' === $args['order'] ? array( 'date' => 'DESC' ) : array(
				'dl'   => 'ASC',
				'date' => 'DESC',
			),
		);
		if ( '' !== $args['q'] ) {
			$q['s'] = $args['q'];
		}
		if ( '' !== $args['type'] ) {
			$q['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'taxonomy' => 'mh_job_type',
					'field'    => 'slug',
					'terms'    => sanitize_title( $args['type'] ),
				),
			);
		}
		if ( $args['exclude'] ) {
			$q['post__not_in'] = array_map( 'intval', (array) $args['exclude'] );
		}
		$wpq   = new WP_Query( $q );
		$items = array();
		foreach ( $wpq->posts as $p ) {
			$items[] = self::data( $p );
		}
		// Featured first within the page, keeping deadline order otherwise.
		if ( ! $args['featured'] ) {
			usort(
				$items,
				function ( $a, $b ) {
					if ( $a['featured'] !== $b['featured'] ) {
						return $a['featured'] ? -1 : 1;
					}
					return strcmp( $a['deadline'], $b['deadline'] );
				}
			);
		}
		return array(
			'items' => $items,
			'total' => (int) $wpq->found_posts,
			'pages' => (int) $wpq->max_num_pages,
		);
	}

	/**
	 * Saved job ids of a user (open or not).
	 *
	 * @param int $user_id User.
	 * @return int[]
	 */
	public static function saved( $user_id ) {
		$v = get_user_meta( (int) $user_id, 'mhp_saved_jobs', true );
		return array_values( array_unique( array_filter( array_map( 'intval', is_array( $v ) ? $v : array() ) ) ) );
	}

	/**
	 * Toggle a saved job.
	 *
	 * @param int $user_id User.
	 * @param int $job_id  Job.
	 * @return bool|null New state (true = saved), null when the job is invalid.
	 */
	public static function toggle( $user_id, $job_id ) {
		$job = get_post( (int) $job_id );
		if ( ! $user_id || ! $job || 'mh_job' !== $job->post_type || 'publish' !== $job->post_status ) {
			return null;
		}
		$list = self::saved( $user_id );
		if ( in_array( (int) $job_id, $list, true ) ) {
			$list = array_values( array_diff( $list, array( (int) $job_id ) ) );
			$on   = false;
		} else {
			array_unshift( $list, (int) $job_id );
			$list = array_slice( $list, 0, 100 );
			$on   = true;
		}
		update_user_meta( $user_id, 'mhp_saved_jobs', $list );
		return $on;
	}

	/**
	 * Hub POST: toggle saved (no-JS path).
	 */
	public static function handle_save() {
		$id = isset( $_POST['job'] ) ? absint( $_POST['job'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by the dispatcher.
		$on = self::toggle( get_current_user_id(), $id );
		mhp_flash( null === $on ? 'বিজ্ঞপ্তিটি পাওয়া যায়নি।' : ( $on ? 'সংরক্ষিত হয়েছে।' : 'সংরক্ষণ থেকে সরানো হয়েছে।' ), null === $on ? 'error' : 'ok' );
		$back = isset( $_POST['back'] ) ? esc_url_raw( wp_unslash( $_POST['back'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		wp_safe_redirect( $back ? $back : mhp_url( 'job', $id ) );
		exit;
	}

	/**
	 * Distinct education labels and locations among open jobs (filter sheet).
	 *
	 * @return array edu[], loc[]
	 */
	public static function facets() {
		$key = 'mhp_job_facets';
		$f   = get_transient( $key );
		if ( is_array( $f ) ) {
			return $f;
		}
		$res = self::query(
			array(
				'per_page' => 300,
			)
		);
		$edu = array();
		$loc = array();
		foreach ( $res['items'] as $j ) {
			foreach ( array( 'এসএসসি', 'এইচএসসি', 'স্নাতক', 'স্নাতকোত্তর', 'ডিপ্লোমা' ) as $e ) {
				if ( false !== mb_strpos( $j['education'], $e ) ) {
					$edu[ $e ] = true;
				}
			}
			foreach ( preg_split( '/[,،]/u', $j['location'] ) as $l ) {
				$l = trim( $l );
				if ( '' !== $l && 'সারা দেশ' !== $l ) {
					$loc[ $l ] = true;
				}
			}
		}
		$f = array(
			'edu' => array_keys( $edu ),
			'loc' => array_keys( $loc ),
		);
		sort( $f['loc'] );
		set_transient( $key, $f, 30 * MINUTE_IN_SECONDS );
		return $f;
	}
}

add_action(
	'save_post_mh_job',
	function () {
		delete_transient( 'mhp_job_facets' );
	}
);
