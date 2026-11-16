<?php
/**
 * The three ranked lists (DESIGN-SPEC §9). Computed by WP-Cron every 10 minutes into transients
 * (`meeha_mostread_{window}`, `meeha_trending`, `meeha_popular`); a missing transient (first request
 * after launch / cache flush) is computed once on demand under a short lock.
 *
 *  সর্বাধিক পঠিত  Σ hourly views in the window (today = since local midnight, 7d, 30d; 'all' = `_meeha_views`),
 *                 ties → newer first. Fallback (fills up to $count): `_meeha_views` order among posts published in
 *                 the window (WP_Query meta_value_num + date_query) — so the homepage is never empty after launch.
 *  ট্রেন্ডিং      score = (v6h + 0.5·v6–24h + 0.2·v24–48h) / (age_h + 2)^1.2, posts ≤ 48 h old, v6h ≥ threshold
 *                 (setting, default 10); velocity = v6h / max(1, avg 6-h views of the previous 42 h); spark = 8 × 6-h
 *                 buckets. Editor pins (`_meeha_trending`, ≤ 2, expire after 24 h via `_meeha_trending_at`) take the
 *                 LAST slots. Fallback: most-read of the last 24 h, then 7 days.
 *  জনপ্রিয়        score = w_r·reactions + w_s·shares + w_c·comments over N days (engagement table). Until the table
 *                 has enough data: lifetime meta (`_meeha_reactions`, `_meeha_share_counts`, comment_count) of posts
 *                 published in the last N days.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

/**
 * Valid public windows.
 *
 * @return string[]
 */
function meeha_metrics_windows() {
	return array( 'today', '7d', '30d', 'all' );
}

/**
 * Bengali label of a window.
 *
 * @param string $w Window.
 * @return string
 */
function meeha_metrics_window_label( $w ) {
	$l = array(
		'today' => 'আজ',
		'24h'   => '২৪ ঘণ্টা',
		'7d'    => '৭ দিন',
		'30d'   => '৩০ দিন',
		'all'   => 'সব সময়',
	);
	return isset( $l[ $w ] ) ? $l[ $w ] : $w;
}

/**
 * Start timestamp of a window.
 *
 * @param string   $w   today|24h|7d|30d|all.
 * @param int|null $now Now.
 * @return int 0 for 'all'.
 */
function meeha_metrics_window_start( $w, $now = null ) {
	$now = null === $now ? time() : (int) $now;
	switch ( $w ) {
		case 'today':
			return meeha_metrics_local_midnight( $now );
		case '24h':
			return $now - DAY_IN_SECONDS;
		case '7d':
			return $now - 7 * DAY_IN_SECONDS;
		case '30d':
			return $now - 30 * DAY_IN_SECONDS;
	}
	return 0;
}

/**
 * Lifetime view count (`_meeha_views`).
 *
 * @param int $post_id Post.
 * @return int
 */
function meeha_get_views( $post_id ) {
	return max( 0, (int) get_post_meta( (int) $post_id, '_meeha_views', true ) );
}

/**
 * Category term ids of a set of categories + all their descendants.
 *
 * @param int[] $cats Category ids.
 * @return int[]
 */
function meeha_metrics_cat_tree( $cats ) {
	$out = array();
	foreach ( (array) $cats as $c ) {
		$c = (int) $c;
		if ( $c > 0 && get_term( $c, 'category' ) instanceof WP_Term ) {
			$out[] = $c;
			$kids  = get_term_children( $c, 'category' );
			if ( ! is_wp_error( $kids ) ) {
				$out = array_merge( $out, array_map( 'intval', $kids ) );
			}
		}
	}
	return array_values( array_unique( $out ) );
}

/**
 * Raw ranking from the hourly table: post_id => views since $start (published posts of $types,
 * optionally restricted to categories), best first, ties → newer first.
 *
 * @param int      $start Timestamp.
 * @param int      $limit Max rows.
 * @param string[] $types Post types.
 * @param int[]    $cats  Category ids (already expanded to the tree).
 * @return array<int,int>
 */
function meeha_metrics_rank_views( $start, $limit, $types = array( 'post' ), $cats = array() ) {
	global $wpdb;
	if ( ! meeha_metrics_ready() ) {
		return array();
	}
	$h     = meeha_metrics_table( 'hourly' );
	$types = array_values( array_filter( array_map( 'sanitize_key', (array) $types ) ) );
	$types = $types ? $types : array( 'post' );
	$tph   = implode( ',', array_fill( 0, count( $types ), '%s' ) );
	$in    = '';
	$args  = array_merge( array( meeha_metrics_hour( $start ) ), $types );
	if ( $cats ) {
		$tt = array();
		foreach ( $cats as $c ) {
			$term = get_term( (int) $c, 'category' );
			if ( $term && ! is_wp_error( $term ) ) {
				$tt[] = (int) $term->term_taxonomy_id;
			}
		}
		if ( ! $tt ) {
			return array();
		}
		// Sub-select (not a join) so a post filed in two matching categories is not summed twice.
		$in = " AND h.post_id IN (SELECT tr.object_id FROM {$wpdb->term_relationships} tr WHERE tr.term_taxonomy_id IN (" . implode( ',', $tt ) . '))';
	}
	$args[] = (int) $limit;
	$sql    = "SELECT h.post_id, SUM(h.views) AS v, MAX(p.post_date_gmt) AS d
		FROM {$h} h INNER JOIN {$wpdb->posts} p ON p.ID = h.post_id
		WHERE h.`hour` >= %s AND p.post_status = 'publish' AND p.post_type IN ({$tph}){$in}
		GROUP BY h.post_id HAVING SUM(h.views) > 0 ORDER BY v DESC, d DESC LIMIT %d";
	$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	$out  = array();
	foreach ( (array) $rows as $r ) {
		$out[ (int) $r->post_id ] = (int) $r->v;
	}
	return $out;
}

/**
 * Fallback ranking: lifetime `_meeha_views` among posts published since $start.
 *
 * @param int      $start   Timestamp (0 = any time).
 * @param int      $count   Count.
 * @param string[] $types   Post types.
 * @param int[]    $cats    Category ids (expanded).
 * @param int[]    $exclude Exclude ids.
 * @return int[] Post ids.
 */
function meeha_metrics_rank_meta( $start, $count, $types = array( 'post' ), $cats = array(), $exclude = array() ) {
	$args = array(
		'post_type'           => $types,
		'post_status'         => 'publish',
		'posts_per_page'      => (int) $count,
		'fields'              => 'ids',
		'no_found_rows'       => true,
		'ignore_sticky_posts' => true,
		'suppress_filters'    => true,
		'meta_key'            => '_meeha_views', // phpcs:ignore WordPress.DB.SlowDBQuery
		'orderby'             => array(
			'meta_value_num' => 'DESC',
			'date'           => 'DESC',
		),
		'post__not_in'        => array_map( 'intval', (array) $exclude ),
	);
	if ( $start > 0 ) {
		$args['date_query'] = array(
			array(
				'after'     => gmdate( 'Y-m-d H:i:s', $start ),
				'column'    => 'post_date_gmt',
				'inclusive' => true,
			),
		);
	}
	if ( $cats ) {
		$args['category__in'] = array_map( 'intval', $cats );
	}
	$q = new WP_Query( $args );
	return array_map( 'intval', $q->posts );
}

/**
 * Compute a most-read pool (ids → window views) for a window; used by cron and on demand.
 *
 * @param string   $window today|24h|7d|30d|all.
 * @param int      $limit  Pool size.
 * @param string[] $types  Post types.
 * @param int[]    $cats   Category tree ids.
 * @return array<int,int> id => views in window (fallback rows carry 0).
 */
function meeha_metrics_compute_most_read( $window, $limit = 30, $types = array( 'post' ), $cats = array() ) {
	$start = meeha_metrics_window_start( $window );
	if ( 'all' === $window ) {
		$ids = meeha_metrics_rank_meta( 0, $limit, $types, $cats );
		$out = array();
		foreach ( $ids as $id ) {
			$out[ $id ] = meeha_get_views( $id );
		}
		return $out;
	}
	$out = meeha_metrics_rank_views( $start, $limit, $types, $cats );
	if ( count( $out ) < $limit ) {
		foreach ( meeha_metrics_rank_meta( $start, $limit - count( $out ), $types, $cats, array_keys( $out ) ) as $id ) {
			$out[ $id ] = 0;
		}
	}
	return $out;
}

/**
 * Cached pool for a window / category.
 *
 * @param string $window Window.
 * @param int    $cat    Category id (0 = all).
 * @return array<int,int>
 */
function meeha_metrics_most_read_pool( $window, $cat = 0 ) {
	$key  = 'meeha_mostread_' . $window . ( $cat ? '_c' . (int) $cat : '' );
	$pool = get_transient( $key );
	if ( ! is_array( $pool ) ) {
		$pool = meeha_metrics_compute_most_read( $window, 30, array( 'post' ), $cat ? meeha_metrics_cat_tree( array( $cat ) ) : array() );
		set_transient( $key, $pool, $cat ? 10 * MINUTE_IN_SECONDS : 20 * MINUTE_IN_SECONDS );
	}
	return $pool;
}

/**
 * সর্বাধিক পঠিত (CONTRACT §5).
 *
 * @param string $window today|7d|30d|all (also 24h).
 * @param int    $count  Count.
 * @param array  $args   category (int|int[] — descendants included), exclude (int[]), post_types (string[]).
 * @return WP_Post[]
 */
function meeha_get_most_read( $window, $count, $args = array() ) {
	$window  = in_array( $window, array( 'today', '24h', '7d', '30d', 'all' ), true ) ? $window : '7d';
	$count   = max( 1, min( 30, (int) $count ) );
	$args    = wp_parse_args(
		(array) $args,
		array(
			'category'   => array(),
			'exclude'    => array(),
			'post_types' => array( 'post' ),
		)
	);
	$cats    = array_filter( array_map( 'intval', (array) $args['category'] ) );
	$exclude = array_map( 'intval', (array) $args['exclude'] );
	$types   = array_values( array_filter( array_map( 'sanitize_key', (array) $args['post_types'] ) ) );
	$types   = $types ? $types : array( 'post' );

	if ( array( 'post' ) === $types && count( $cats ) <= 1 ) {
		$pool = meeha_metrics_most_read_pool( $window, $cats ? $cats[0] : 0 );
	} else {
		$key  = 'meeha_mostread_x_' . md5( wp_json_encode( array( $window, $types, $cats ) ) );
		$pool = get_transient( $key );
		if ( ! is_array( $pool ) ) {
			$pool = meeha_metrics_compute_most_read( $window, 30, $types, meeha_metrics_cat_tree( $cats ) );
			set_transient( $key, $pool, 10 * MINUTE_IN_SECONDS );
		}
	}
	$ids = array_values( array_diff( array_keys( $pool ), $exclude ) );
	if ( count( $ids ) < $count && count( $pool ) >= 30 ) {
		// Exclusions emptied a full pool — compute a deeper one.
		$deep = meeha_metrics_compute_most_read( $window, 30 + count( $exclude ), $types, meeha_metrics_cat_tree( $cats ) );
		$ids  = array_values( array_diff( array_keys( $deep ), $exclude ) );
	}
	return meeha_metrics_posts( array_slice( $ids, 0, $count ) );
}

/**
 * Load posts by id in the given order (one query, caches primed), published only.
 *
 * @param int[] $ids Ids.
 * @return WP_Post[]
 */
function meeha_metrics_posts( $ids ) {
	$ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $ids ) ) ) );
	if ( ! $ids ) {
		return array();
	}
	_prime_post_caches( $ids, true, true );
	$out = array();
	foreach ( $ids as $id ) {
		$p = get_post( $id );
		if ( $p && 'publish' === $p->post_status ) {
			$out[] = $p;
		}
	}
	return $out;
}

/* --------------------------------------------------------------------------
 * Trending
 * ------------------------------------------------------------------------ */

/**
 * 8 × 6-hour buckets per post for the last 48 h (index 0 = oldest … 7 = the current 6 h).
 *
 * @param int[]|null $only Restrict to these ids (null = every post with views).
 * @param int|null   $now  Now.
 * @return array<int,int[]>
 */
function meeha_metrics_buckets( $only = null, $now = null ) {
	global $wpdb;
	$now = null === $now ? time() : (int) $now;
	if ( ! meeha_metrics_ready() ) {
		return array();
	}
	$h      = meeha_metrics_table( 'hourly' );
	$cur    = strtotime( meeha_metrics_hour( $now ) . ' UTC' );
	$start  = $cur - 47 * HOUR_IN_SECONDS;
	$filter = '';
	if ( is_array( $only ) ) {
		$only = array_filter( array_map( 'intval', $only ) );
		if ( ! $only ) {
			return array();
		}
		$filter = ' AND post_id IN (' . implode( ',', $only ) . ')';
	}
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, `hour`, views FROM {$h} WHERE `hour` >= %s" . $filter, gmdate( 'Y-m-d H:i:s', $start ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	$out  = array();
	foreach ( (array) $rows as $r ) {
		$ts = strtotime( $r->hour . ' UTC' );
		$b  = (int) floor( ( $cur - $ts ) / ( 6 * HOUR_IN_SECONDS ) ); // 0 = newest.
		if ( $b < 0 || $b > 7 ) {
			continue;
		}
		$pid = (int) $r->post_id;
		if ( ! isset( $out[ $pid ] ) ) {
			$out[ $pid ] = array_fill( 0, 8, 0 );
		}
		$out[ $pid ][ 7 - $b ] += (int) $r->views;
	}
	return $out;
}

/**
 * Trending score + velocity for one bucket series.
 *
 * @param int[] $b     8 buckets (oldest → newest).
 * @param float $age_h Post age in hours.
 * @return array [score, velocity, v6h]
 */
function meeha_metrics_trend_score( $b, $age_h ) {
	$v6     = (int) $b[7];
	$v6_24  = (int) $b[6] + (int) $b[5] + (int) $b[4];
	$v24_48 = (int) $b[3] + (int) $b[2] + (int) $b[1] + (int) $b[0];
	$score  = ( $v6 + 0.5 * $v6_24 + 0.2 * $v24_48 ) / pow( max( 0, (float) $age_h ) + 2, 1.2 );
	$prev   = ( $v6_24 + $v24_48 ) / 7;
	$vel    = $v6 / max( 1, $prev );
	return array( $score, $vel, $v6 );
}

/**
 * Trending settings.
 *
 * @return array threshold, pins, pin_hours
 */
function meeha_metrics_trend_settings() {
	$o = function_exists( 'meeha_opt' ) ? meeha_opt( 'home' ) : array();
	return array(
		'threshold' => max( 1, (int) ( isset( $o['trend_threshold'] ) ? $o['trend_threshold'] : 10 ) ),
		'pins'      => max( 0, min( 2, (int) ( isset( $o['trend_pins'] ) ? $o['trend_pins'] : 2 ) ) ),
		'pin_hours' => max( 1, (int) ( isset( $o['trend_pin_hours'] ) ? $o['trend_pin_hours'] : 24 ) ),
	);
}

/**
 * Active editor pins (newest first), at most $max.
 *
 * @param int      $max       Max pins.
 * @param int      $pin_hours Expiry.
 * @param int|null $now       Now.
 * @return int[]
 */
function meeha_metrics_active_pins( $max, $pin_hours, $now = null ) {
	$now = null === $now ? time() : (int) $now;
	if ( $max < 1 ) {
		return array();
	}
	$ids  = get_posts(
		array(
			'post_type'        => 'post',
			'post_status'      => 'publish',
			'posts_per_page'   => 20,
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'suppress_filters' => true,
			'meta_key'         => '_meeha_trending', // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value'       => '1', // phpcs:ignore WordPress.DB.SlowDBQuery
		)
	);
	$pins = array();
	foreach ( $ids as $id ) {
		$at = (int) get_post_meta( $id, '_meeha_trending_at', true );
		if ( ! $at ) {
			// Legacy flag without a timestamp: treat the publish time as the pin time.
			$at = (int) get_post_time( 'U', true, $id );
		}
		if ( $at >= $now - $pin_hours * HOUR_IN_SECONDS ) {
			$pins[ $id ] = $at;
		}
	}
	arsort( $pins );
	return array_slice( array_keys( $pins ), 0, $max );
}

/**
 * Compute the trending list (ids + numbers, no post objects) — cron/on demand.
 *
 * @param int      $count Count.
 * @param int|null $now   Now (tests).
 * @return array[] items: id, velocity (float|null), spark int[8], pinned bool, views (v6h), source organic|pin|fallback.
 */
function meeha_metrics_compute_trending( $count = 8, $now = null ) {
	$now  = null === $now ? time() : (int) $now;
	$set  = meeha_metrics_trend_settings();
	$pins = meeha_metrics_active_pins( min( $set['pins'], $count ), $set['pin_hours'], $now );
	$need = max( 0, $count - count( $pins ) );

	$buckets = meeha_metrics_buckets( null, $now );
	$cands   = array();
	if ( $buckets ) {
		_prime_post_caches( array_keys( $buckets ), false, false );
		foreach ( $buckets as $pid => $b ) {
			if ( in_array( $pid, $pins, true ) ) {
				continue;
			}
			$p = get_post( $pid );
			if ( ! $p || 'publish' !== $p->post_status || 'post' !== $p->post_type ) {
				continue;
			}
			$age = ( $now - (int) get_post_time( 'U', true, $p ) ) / HOUR_IN_SECONDS;
			if ( $age > 48 ) {
				continue;
			}
			list( $score, $vel, $v6 ) = meeha_metrics_trend_score( $b, $age );
			if ( $v6 < $set['threshold'] ) {
				continue;
			}
			$cands[ $pid ] = array(
				'id'       => $pid,
				'score'    => $score,
				'velocity' => round( $vel, 2 ),
				'spark'    => array_map( 'intval', $b ),
				'pinned'   => false,
				'views'    => $v6,
				'source'   => 'organic',
			);
		}
		uasort(
			$cands,
			function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);
	}
	$items = array_slice( array_values( $cands ), 0, $need );

	if ( count( $items ) < $need ) {
		// Fallback: most-read of the last 24 h, then of 7 days (both fall back to `_meeha_views`).
		$have = array_merge( $pins, wp_list_pluck( $items, 'id' ) );
		foreach ( array( '24h', '7d' ) as $w ) {
			foreach ( array_keys( meeha_metrics_compute_most_read( $w, $need + count( $have ) ) ) as $pid ) {
				if ( count( $items ) >= $need ) {
					break 2;
				}
				if ( in_array( $pid, $have, true ) ) {
					continue;
				}
				$b       = isset( $buckets[ $pid ] ) ? $buckets[ $pid ] : array_fill( 0, 8, 0 );
				$age     = ( $now - (int) get_post_time( 'U', true, $pid ) ) / HOUR_IN_SECONDS;
				$r       = meeha_metrics_trend_score( $b, $age );
				$items[] = array(
					'id'       => $pid,
					'score'    => $r[0],
					'velocity' => array_sum( $b ) ? round( $r[1], 2 ) : null,
					'spark'    => array_map( 'intval', $b ),
					'pinned'   => false,
					'views'    => $r[2],
					'source'   => 'fallback',
				);
				$have[]  = $pid;
			}
		}
	}
	foreach ( $pins as $pid ) {
		$b       = isset( $buckets[ $pid ] ) ? $buckets[ $pid ] : array_fill( 0, 8, 0 );
		$items[] = array(
			'id'       => $pid,
			'score'    => 0,
			'velocity' => null,
			'spark'    => array_map( 'intval', $b ),
			'pinned'   => true,
			'views'    => (int) $b[7],
			'source'   => 'pin',
		);
	}
	return $items;
}

/**
 * ট্রেন্ডিং (CONTRACT §5).
 *
 * @param int $count Count (≤ 8).
 * @return array[] items: post WP_Post, velocity float|null, spark int[8], pinned bool (+ views, source).
 */
function meeha_get_trending( $count ) {
	$count = max( 1, min( 8, (int) $count ) );
	$raw   = get_transient( 'meeha_trending' );
	if ( ! is_array( $raw ) ) {
		$raw = meeha_metrics_compute_trending( 8 );
		set_transient( 'meeha_trending', $raw, 20 * MINUTE_IN_SECONDS );
	}
	// Re-apply the pin rule at read time: pins occupy the last slots of the requested length.
	$pins  = array_values( array_filter( $raw, function ( $i ) { return ! empty( $i['pinned'] ); } ) );
	$other = array_values( array_filter( $raw, function ( $i ) { return empty( $i['pinned'] ); } ) );
	$pins  = array_slice( $pins, 0, $count );
	$list  = array_merge( array_slice( $other, 0, $count - count( $pins ) ), $pins );
	$posts = meeha_metrics_posts( wp_list_pluck( $list, 'id' ) );
	$by    = array();
	foreach ( $posts as $p ) {
		$by[ $p->ID ] = $p;
	}
	$out = array();
	foreach ( $list as $i ) {
		if ( isset( $by[ $i['id'] ] ) ) {
			$out[] = array(
				'post'     => $by[ $i['id'] ],
				'velocity' => isset( $i['velocity'] ) && null !== $i['velocity'] ? (float) $i['velocity'] : null,
				'spark'    => array_map( 'intval', (array) $i['spark'] ),
				'pinned'   => ! empty( $i['pinned'] ),
				'views'    => (int) $i['views'],
				'source'   => $i['source'],
			);
		}
	}
	return $out;
}

/* --------------------------------------------------------------------------
 * Popular
 * ------------------------------------------------------------------------ */

/**
 * Popular weights.
 *
 * @return int[] r, s, c
 */
function meeha_metrics_pop_weights() {
	$o = function_exists( 'meeha_opt' ) ? meeha_opt( 'home' ) : array();
	return array(
		'r' => isset( $o['pop_w_reactions'] ) ? max( 0, (int) $o['pop_w_reactions'] ) : 1,
		's' => isset( $o['pop_w_shares'] ) ? max( 0, (int) $o['pop_w_shares'] ) : 3,
		'c' => isset( $o['pop_w_comments'] ) ? max( 0, (int) $o['pop_w_comments'] ) : 4,
	);
}

/**
 * Compute the popular list (ids + numbers).
 *
 * @param int      $count Count.
 * @param int      $days  Window in days.
 * @param int|null $now   Now.
 * @return array[] id, score, reactions, shares, comments, source table|meta
 */
function meeha_metrics_compute_popular( $count, $days = 30, $now = null ) {
	global $wpdb;
	$now   = null === $now ? time() : (int) $now;
	$w     = meeha_metrics_pop_weights();
	$since = wp_date( 'Y-m-d', $now - $days * DAY_IN_SECONDS );
	$items = array();
	if ( meeha_metrics_ready() ) {
		$e    = meeha_metrics_table( 'engagement' );
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT e.post_id, SUM(e.reactions) r, SUM(e.shares) s, SUM(e.comments) c,
					( %d * SUM(e.reactions) + %d * SUM(e.shares) + %d * SUM(e.comments) ) AS score
				FROM {$e} e INNER JOIN {$wpdb->posts} p ON p.ID = e.post_id
				WHERE e.`day` >= %s AND p.post_status = 'publish' AND p.post_type = 'post'
				GROUP BY e.post_id HAVING score > 0 ORDER BY score DESC, MAX(p.post_date_gmt) DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL
				$w['r'],
				$w['s'],
				$w['c'],
				$since,
				(int) $count
			)
		);
		foreach ( (array) $rows as $r ) {
			$items[ (int) $r->post_id ] = array(
				'id'        => (int) $r->post_id,
				'score'     => (int) $r->score,
				'reactions' => (int) $r->r,
				'shares'    => (int) $r->s,
				'comments'  => (int) $r->c,
				'source'    => 'table',
			);
		}
	}
	if ( count( $items ) < $count ) {
		// Fallback until the engagement table has data: lifetime meta of recent posts.
		$ids = get_posts(
			array(
				'post_type'        => 'post',
				'post_status'      => 'publish',
				'posts_per_page'   => 60,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'post__not_in'     => array_keys( $items ),
				'date_query'       => array( array( 'after' => gmdate( 'Y-m-d H:i:s', $now - $days * DAY_IN_SECONDS ), 'column' => 'post_date_gmt' ) ),
			)
		);
		if ( $ids ) {
			_prime_post_caches( $ids, false, true );
		}
		$extra = array();
		foreach ( $ids as $id ) {
			$r = array_sum( meeha_metrics_int_counts( get_post_meta( $id, '_meeha_reactions', true ) ) );
			$s = array_sum( meeha_metrics_int_counts( get_post_meta( $id, '_meeha_share_counts', true ) ) );
			$c = (int) get_comments_number( $id );
			$sc = $w['r'] * $r + $w['s'] * $s + $w['c'] * $c;
			if ( $sc > 0 ) {
				$extra[ $id ] = array(
					'id'        => (int) $id,
					'score'     => $sc,
					'reactions' => $r,
					'shares'    => $s,
					'comments'  => $c,
					'source'    => 'meta',
				);
			}
		}
		uasort(
			$extra,
			function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);
		foreach ( $extra as $id => $it ) {
			if ( count( $items ) >= $count ) {
				break;
			}
			$items[ $id ] = $it;
		}
	}
	return array_values( $items );
}

/**
 * জনপ্রিয় (CONTRACT §5).
 *
 * @param int $count Count.
 * @param int $days  Days.
 * @return array[] post, score, reactions, shares, comments.
 */
function meeha_get_popular( $count, $days = 30 ) {
	$count = max( 1, min( 12, (int) $count ) );
	$days  = max( 1, min( 365, (int) $days ) );
	$key   = 'meeha_popular' . ( 30 === $days ? '' : '_' . $days );
	$raw   = get_transient( $key );
	if ( ! is_array( $raw ) ) {
		$raw = meeha_metrics_compute_popular( 12, $days );
		set_transient( $key, $raw, 20 * MINUTE_IN_SECONDS );
	}
	$list  = array_slice( $raw, 0, $count );
	$posts = meeha_metrics_posts( wp_list_pluck( $list, 'id' ) );
	$by    = array();
	foreach ( $posts as $p ) {
		$by[ $p->ID ] = $p;
	}
	$out = array();
	foreach ( $list as $i ) {
		if ( isset( $by[ $i['id'] ] ) ) {
			$out[] = array(
				'post'      => $by[ $i['id'] ],
				'score'     => (int) $i['score'],
				'reactions' => (int) $i['reactions'],
				'shares'    => (int) $i['shares'],
				'comments'  => (int) $i['comments'],
			);
		}
	}
	return $out;
}
