<?php
/**
 * Homepage data plan: one cached structure of post ids per section (transient, 5 min, keyed by a cache
 * generation that every publish/update/delete bumps), then one priming pass (posts, terms, meta,
 * thumbnails, authors) so rendering runs without N+1 queries.
 *
 * De-duplication (setting `dedupe`, default on): category sections (S1, S4, S5, S7 বিশ্ব, S8, S9) never repeat
 * a post shown in an earlier category section — in the configured order. Ranked lists (S2, S7 সর্বাধিক পঠিত,
 * S10), the একনজরে digest and the area widget are exempt.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

/**
 * Cache generation.
 *
 * @return int
 */
function meeha_home_gen() {
	return (int) get_option( 'meeha_home_gen', 1 );
}

/**
 * Invalidate every cached homepage / archive-aside query.
 */
function meeha_home_bust() {
	update_option( 'meeha_home_gen', meeha_home_gen() + 1, true );
}
add_action(
	'transition_post_status',
	function ( $new, $old ) {
		if ( 'publish' === $new || 'publish' === $old ) {
			meeha_home_bust();
		}
	},
	10,
	2
);
add_action(
	'save_post',
	function ( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || 'publish' !== $post->post_status ) {
			return;
		}
		meeha_home_bust();
	},
	20,
	2
);
add_action( 'deleted_post', 'meeha_home_bust' );
add_action( 'edited_category', 'meeha_home_bust' );
foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $meeha_hook ) {
	add_action(
		$meeha_hook,
		function ( $mid, $post_id, $key ) {
			if ( in_array( $key, array( '_meeha_glance', '_meeha_glance_text', '_meeha_investigation', '_thumbnail_id', '_meeha_guest_author' ), true ) && 'publish' === get_post_status( $post_id ) ) {
				meeha_home_bust();
			}
		},
		10,
		3
	);
}
unset( $meeha_hook );

/**
 * Enabled sections in display order: [ id => title ].
 *
 * @return array<string,string>
 */
function meeha_home_enabled_sections() {
	$out = array();
	foreach ( (array) meeha_opt( 'home', 'sections' ) as $row ) {
		if ( ! empty( $row['enabled'] ) && ! empty( $row['id'] ) ) {
			$out[ $row['id'] ] = (string) $row['label'];
		}
	}
	return $out;
}

/**
 * Newest post ids matching a category tree (and extra args), excluding ids.
 *
 * @param int[] $cats    Category ids (descendants included). Empty = any category.
 * @param int   $count   Count.
 * @param int[] $exclude Exclude.
 * @param array $extra   Extra WP_Query args.
 * @return int[]
 */
function meeha_home_newest( $cats, $count, $exclude = array(), $extra = array() ) {
	if ( $count < 1 ) {
		return array();
	}
	$args = array_merge(
		array(
			'post_type'           => 'post',
			'post_status'         => 'publish',
			'posts_per_page'      => (int) $count,
			'fields'              => 'ids',
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
			'suppress_filters'    => true,
			'post__not_in'        => array_values( array_filter( array_map( 'intval', (array) $exclude ) ) ),
			'orderby'             => 'date',
			'order'               => 'DESC',
		),
		(array) $extra
	);
	$tax = isset( $args['tax_query'] ) ? (array) $args['tax_query'] : array();
	if ( $cats ) {
		$tax[] = array(
			'taxonomy'         => 'category',
			'field'            => 'term_id',
			'terms'            => array_map( 'intval', $cats ),
			'include_children' => true,
		);
	}
	if ( $tax ) {
		$args['tax_query'] = $tax; // phpcs:ignore WordPress.DB.SlowDBQuery
	}
	return array_map( 'intval', get_posts( $args ) );
}

/**
 * `tax_query` clause excluding a category tree.
 *
 * @param int[] $cats Category ids.
 * @return array
 */
function meeha_home_not_in( $cats ) {
	return $cats ? array(
		array(
			'taxonomy'         => 'category',
			'field'            => 'term_id',
			'terms'            => array_map( 'intval', $cats ),
			'include_children' => true,
			'operator'         => 'NOT IN',
		),
	) : array();
}

/**
 * Active lead pin id (0 when none / expired / not published).
 *
 * @return int
 */
function meeha_home_lead_pin() {
	$id = (int) meeha_opt( 'home', 'lead_post_id', 0 );
	if ( ! $id ) {
		return 0;
	}
	$p = get_post( $id );
	if ( ! $p || 'publish' !== $p->post_status || 'post' !== $p->post_type ) {
		return 0;
	}
	$at = (int) get_option( 'meeha_home_lead_at', 0 );
	if ( ! $at ) {
		$at = time();
		update_option( 'meeha_home_lead_at', $at, false );
	}
	$hours = max( 1, (int) meeha_opt( 'home', 'lead_hours', 12 ) );
	return ( time() - $at ) <= $hours * HOUR_IN_SECONDS ? $id : 0;
}

/**
 * Compute the plan (uncached).
 *
 * @return array
 */
function meeha_home_compute_plan() {
	$o      = meeha_opt( 'home' );
	$dedupe = ! empty( $o['dedupe'] );
	$used   = array();
	$plan   = array();
	$excl   = function () use ( &$used, $dedupe ) {
		return $dedupe ? $used : array();
	};
	$mark   = function ( $ids ) use ( &$used ) {
		foreach ( (array) $ids as $id ) {
			if ( $id ) {
				$used[] = (int) $id;
			}
		}
	};

	foreach ( array_keys( meeha_home_enabled_sections() ) as $sid ) {
		switch ( $sid ) {
			case 's1':
				$hero    = meeha_home_cats( 'hero_cats' );
				$hexcl   = meeha_home_not_in( meeha_home_cats( 'hero_exclude' ) );
				$hargs   = $hexcl ? array( 'tax_query' => $hexcl ) : array(); // phpcs:ignore WordPress.DB.SlowDBQuery
				$lead    = meeha_home_lead_pin();
				if ( ! $lead && ! empty( $o['use_sticky'] ) ) {
					$sticky = array_map( 'intval', (array) get_option( 'sticky_posts', array() ) );
					if ( $sticky ) {
						$s    = meeha_home_newest( $hero, 1, array(), array_merge( $hargs, array( 'post__in' => $sticky, 'date_query' => array( array( 'after' => '72 hours ago' ) ) ) ) );
						$lead = $s ? $s[0] : 0;
					}
				}
				$pool = meeha_home_newest( $hero, 10, $lead ? array( $lead ) : array(), $hargs );
				if ( ! $lead ) {
					// Prefer the newest story that has an image (the lead image is the LCP element).
					foreach ( array_slice( $pool, 0, 4 ) as $cand ) {
						if ( has_post_thumbnail( $cand ) ) {
							$lead = $cand;
							break;
						}
					}
					if ( ! $lead && $pool ) {
						$lead = $pool[0];
					}
				}
				if ( ! $lead ) {
					$any  = meeha_home_newest( array(), 1 );
					$lead = $any ? $any[0] : 0;
				}
				if ( ! $lead ) {
					break;
				}
				$sec = array_slice( array_values( array_diff( $pool, array( $lead ) ) ), 0, 4 );
				if ( count( $sec ) < 4 ) {
					$sec = array_merge( $sec, meeha_home_newest( array(), 4 - count( $sec ), array_merge( $sec, array( $lead ) ) ) );
				}
				$latest      = meeha_home_newest( array(), max( 5, min( 12, (int) $o['latest_count'] ) ), array_merge( $sec, array( $lead ) ) );
				$plan['s1']  = array(
					'lead'   => $lead,
					'side'   => array_slice( $sec, 0, 2 ),
					'more'   => array_slice( $sec, 2, 2 ),
					'latest' => $latest,
				);
				$mark( array_merge( array( $lead ), $sec ) );
				break;

			case 's3':
				$count = max( 4, min( 12, (int) $o['glance_count'] ) );
				$mode  = (string) $o['glance_mode'];
				$mid   = function_exists( 'meeha_metrics_local_midnight' ) ? meeha_metrics_local_midnight() : strtotime( 'today' );
				$ids   = array();
				if ( 'auto' !== $mode ) {
					$flag = array(
						'meta_key'   => '_meeha_glance', // phpcs:ignore WordPress.DB.SlowDBQuery
						'meta_value' => '1', // phpcs:ignore WordPress.DB.SlowDBQuery
					);
					$ids  = meeha_home_newest( array(), $count, array(), $flag + array( 'date_query' => array( array( 'after' => gmdate( 'Y-m-d H:i:s', $mid ), 'column' => 'post_date_gmt' ) ) ) );
					if ( count( $ids ) < 4 ) {
						$ids = meeha_home_newest( array(), $count, array(), $flag + array( 'date_query' => array( array( 'after' => gmdate( 'Y-m-d H:i:s', $mid - DAY_IN_SECONDS ), 'column' => 'post_date_gmt' ) ) ) );
					}
				}
				if ( ! $ids && 'flagged' !== $mode ) {
					$ids = meeha_home_newest( array(), $count );
				}
				$plan['s3'] = array( 'ids' => $ids );
				break;

			case 's4':
				$cats  = meeha_home_cats( 'inv_cats' );
				$a     = $cats ? meeha_home_newest( $cats, 8, $excl() ) : array();
				$b     = meeha_home_newest(
					array(),
					8,
					$excl(),
					array(
						'meta_key'   => '_meeha_investigation', // phpcs:ignore WordPress.DB.SlowDBQuery
						'meta_value' => '1', // phpcs:ignore WordPress.DB.SlowDBQuery
					)
				);
				$ids   = array_values( array_unique( array_merge( $a, $b ) ) );
				if ( $ids ) {
					_prime_post_caches( $ids, false, false );
					usort(
						$ids,
						function ( $x, $y ) {
							return strcmp( get_post( $y )->post_date_gmt, get_post( $x )->post_date_gmt );
						}
					);
				}
				$ids = array_slice( $ids, 0, 4 );
				if ( ! $ids && ! empty( $o['inv_fallback'] ) ) {
					$ids = meeha_home_newest( array( (int) $o['inv_fallback'] ), 4, $excl() );
				}
				$plan['s4'] = array( 'ids' => $ids );
				$mark( $ids );
				break;

			case 's5':
				$n   = max( 2, min( 9, (int) $o['mb_count'] ) );
				$ids = meeha_home_newest( meeha_home_cats( 'mb_cats' ), $n, $excl() );
				if ( count( $ids ) < 2 && ! empty( $o['mb_fallback'] ) ) {
					$ids = array_merge( $ids, meeha_home_newest( array( (int) $o['mb_fallback'] ), $n - count( $ids ), array_merge( $excl(), $ids ) ) );
				}
				$plan['s5'] = array( 'ids' => $ids );
				$mark( $ids );
				break;

			case 's6':
				$ids = array();
				if ( post_type_exists( 'meeha_video' ) ) {
					$ids = meeha_home_newest( array(), max( 3, min( 16, (int) $o['video_count'] ) ), array(), array( 'post_type' => 'meeha_video', 'has_password' => false ) );
				}
				$plan['s6'] = array( 'ids' => $ids );
				break;

			case 's7':
				$n   = max( 2, min( 7, (int) $o['world_count'] ) );
				$ids = meeha_home_newest( meeha_home_cats( 'world_cats' ), $n, $excl() );
				if ( count( $ids ) < 2 && ! empty( $o['world_fallback'] ) ) {
					$ids = array_merge( $ids, meeha_home_newest( array( (int) $o['world_fallback'] ), $n - count( $ids ), array_merge( $excl(), $ids ) ) );
				}
				$plan['s7'] = array( 'ids' => $ids );
				$mark( $ids );
				break;

			case 's8':
				$n    = max( 2, min( 6, (int) $o['op_count'] ) );
				$ed   = meeha_home_newest( meeha_home_cats( 'op_editorial_cats' ), 1, $excl() );
				$ed   = $ed ? $ed[0] : 0;
				$cols = meeha_home_newest( meeha_home_cats( 'op_columnist_cats' ), $ed ? $n : $n + 1, array_merge( $excl(), array( $ed ) ) );
				$plan['s8'] = array(
					'editorial'  => $ed,
					'columnists' => $cols,
				);
				$mark( array_merge( array( $ed ), $cols ) );
				break;

			case 's9':
				$n     = max( 2, min( 5, (int) $o['trio_count'] ) );
				$pool  = meeha_home_cats( 'trio_pool' );
				$taken = array();
				$cols  = array();
				foreach ( array( 'trio_1', 'trio_2', 'trio_3' ) as $k ) {
					$c = meeha_home_cats( $k );
					$candidates = array_merge( $c ? array( $c[0] ) : array(), $pool );
					foreach ( $candidates as $cat ) {
						if ( in_array( $cat, $taken, true ) ) {
							continue;
						}
						$ids = meeha_home_newest( array( $cat ), $n, $excl() );
						if ( count( $ids ) >= 2 ) {
							$cols[]  = array(
								'cat' => (int) $cat,
								'ids' => $ids,
							);
							$taken[] = (int) $cat;
							$mark( $ids );
							break;
						}
					}
				}
				$plan['s9'] = array( 'cols' => $cols );
				break;
		}
	}
	return $plan;
}

/**
 * Cached plan (5 minutes; invalidated by the cache generation).
 *
 * @return array
 */
function meeha_home_plan() {
	static $plan = null;
	if ( null !== $plan ) {
		return $plan;
	}
	$key  = 'meeha_home_plan_' . md5( meeha_home_gen() . '|' . wp_json_encode( meeha_opt( 'home' ) ) . '|' . (int) get_option( 'meeha_home_lead_at', 0 ) );
	$plan = get_transient( $key );
	if ( ! is_array( $plan ) ) {
		$plan = meeha_home_compute_plan();
		set_transient( $key, $plan, 5 * MINUTE_IN_SECONDS );
	}
	return $plan;
}

/**
 * Prime post, term, meta, thumbnail and author caches for a set of post ids (3–5 queries in total).
 *
 * @param int[] $ids Post ids.
 */
function meeha_home_prime( $ids ) {
	$ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $ids ) ) ) );
	if ( ! $ids ) {
		return;
	}
	_prime_post_caches( $ids, true, true );
	$thumbs  = array();
	$authors = array();
	foreach ( $ids as $id ) {
		$p = get_post( $id );
		if ( ! $p ) {
			continue;
		}
		$authors[] = (int) $p->post_author;
		$t         = (int) get_post_meta( $id, '_thumbnail_id', true );
		if ( $t ) {
			$thumbs[] = $t;
		}
	}
	if ( $thumbs ) {
		_prime_post_caches( array_unique( $thumbs ), false, true );
	}
	if ( $authors ) {
		cache_users( array_unique( $authors ) );
	}
}

/**
 * Every post id referenced by the plan (+ list ids), for priming.
 *
 * @param array $plan  Plan.
 * @param int[] $extra Extra ids.
 * @return int[]
 */
function meeha_home_plan_ids( $plan, $extra = array() ) {
	$ids = (array) $extra;
	unset( $plan['_lists'] ); // ranked-list items carry scores/counts — their post ids come in via $extra.
	array_walk_recursive(
		$plan,
		function ( $v, $k ) use ( &$ids ) {
			if ( is_int( $v ) && 'cat' !== $k ) {
				$ids[] = $v;
			}
		}
	);
	return array_values( array_unique( array_filter( $ids ) ) );
}

/**
 * Find a published page holding a shortcode ('' when none). One cached map for all tags (a day).
 *
 * @param string $tag Shortcode tag.
 * @return string URL.
 */
function meeha_home_page_with( $tag ) {
	static $map = null;
	if ( null === $map ) {
		$map = get_transient( 'meeha_pages_with' );
		$map = is_array( $map ) ? $map : array();
	}
	if ( ! array_key_exists( $tag, $map ) ) {
		global $wpdb;
		$id          = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_content LIKE %s ORDER BY ID ASC LIMIT 1", '%' . $wpdb->esc_like( '[' . $tag ) . '%' ) );
		$map[ $tag ] = $id ? (string) get_permalink( $id ) : '';
		set_transient( 'meeha_pages_with', $map, DAY_IN_SECONDS );
	}
	return (string) $map[ $tag ];
}
add_action(
	'save_post_page',
	function () {
		delete_transient( 'meeha_pages_with' );
	}
);

/**
 * The homepage never uses the main query's posts (sections run their own cached queries), so skip
 * the default "latest 10 posts" query on the unpaged front page (saves ~5 queries per request).
 */
add_filter(
	'posts_pre_query',
	function ( $posts, $q ) {
		if ( null === $posts && ! is_admin() && $q->is_main_query() && $q->is_home() && $q->is_front_page() && ! $q->is_paged() && ! $q->is_feed() && 'posts' === get_option( 'show_on_front' ) && (bool) apply_filters( 'meeha_home_skip_main_query', true ) ) {
			$q->found_posts   = 0;
			$q->max_num_pages = 0;
			return array();
		}
		return $posts;
	},
	10,
	2
);
