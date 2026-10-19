<?php
/**
 * Main search query: GET filters → WP_Query vars, and the normalised relevance search.
 * Only the front-end MAIN search query is touched (never admin, REST, feeds or secondary queries).
 *
 * GET params: s, type (all|news|opinion|video|quote|literature), cat (WP native, children included),
 * author (WP native, numeric), when (any|1d|7d|30d|range), from, to (YYYY-MM-DD), sort (relevance|new|old), paged.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

/**
 * Result type tabs.
 *
 * @return array slug => label
 */
function meeha_search_types() {
	return array(
		'all'        => 'সব',
		'news'       => 'খবর',
		'opinion'    => 'মতামত',
		'video'      => 'ভিডিও',
		'quote'      => 'উক্তি',
		'literature' => 'সাহিত্য',
	);
}

/**
 * Sanitised filter state from $_GET (memoised per request).
 *
 * @param bool $reset Re-read $_GET (tests).
 * @return array type, when, from, to, sort, cat, author
 */
function meeha_search_filters( $reset = false ) {
	static $f = null;
	if ( null !== $f && ! $reset ) {
		return $f;
	}
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- public GET filters.
	$g    = wp_unslash( $_GET );
	$type = isset( $g['type'] ) ? sanitize_key( $g['type'] ) : 'all';
	$when = isset( $g['when'] ) ? sanitize_key( $g['when'] ) : 'any';
	$sort = isset( $g['sort'] ) ? sanitize_key( $g['sort'] ) : 'relevance';
	$date = function ( $k ) use ( $g ) {
		$v = isset( $g[ $k ] ) ? meeha_en_digits( trim( (string) $g[ $k ] ) ) : '';
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) && checkdate( (int) substr( $v, 5, 2 ), (int) substr( $v, 8, 2 ), (int) substr( $v, 0, 4 ) ) ? $v : '';
	};
	$f = array(
		'type'   => array_key_exists( $type, meeha_search_types() ) ? $type : 'all',
		'when'   => in_array( $when, array( 'any', '1d', '7d', '30d', 'range' ), true ) ? $when : 'any',
		'from'   => $date( 'from' ),
		'to'     => $date( 'to' ),
		'sort'   => in_array( $sort, array( 'relevance', 'new', 'old' ), true ) ? $sort : 'relevance',
		'cat'    => isset( $g['cat'] ) ? absint( $g['cat'] ) : 0,
		'author' => isset( $g['author'] ) ? absint( $g['author'] ) : 0,
	);
	// phpcs:enable
	if ( $f['cat'] && ! term_exists( $f['cat'], 'category' ) ) {
		$f['cat'] = 0;
	}
	if ( 'range' === $f['when'] && ! $f['from'] && ! $f['to'] ) {
		$f['when'] = 'any';
	}
	if ( $f['from'] && $f['to'] && $f['from'] > $f['to'] ) {
		list( $f['from'], $f['to'] ) = array( $f['to'], $f['from'] );
	}
	return $f;
}

/**
 * Category trees used by the type tabs (same rules as meeha_post_type_label()).
 *
 * @return array opinion => int[], literature => int[]
 */
function meeha_search_type_trees() {
	$tree = function ( $slugs ) {
		$ids = array();
		foreach ( $slugs as $s ) {
			$t = get_category_by_slug( $s );
			if ( $t ) {
				$ids[] = (int) $t->term_id;
				$ids   = array_merge( $ids, array_map( 'intval', (array) get_term_children( $t->term_id, 'category' ) ) );
			}
		}
		return array_values( array_unique( $ids ) );
	};
	return array(
		'opinion'    => $tree( apply_filters( 'meeha_opinion_category_slugs', array( 'editorial', 'sub-editorial', 'readers-words' ) ) ),
		'literature' => $tree( apply_filters( 'meeha_literature_category_slugs', array( 'bengali-culture' ) ) ),
	);
}

/**
 * Apply type / date / author constraints to a query.
 *
 * @param WP_Query $q    Query.
 * @param array    $f    Filters.
 * @param string   $type Type override.
 */
function meeha_search_apply_filters( $q, $f, $type ) {
	$trees = meeha_search_type_trees();
	$tax   = array();
	switch ( $type ) {
		case 'video':
			$q->set( 'post_type', post_type_exists( 'meeha_video' ) ? 'meeha_video' : 'meeha_none' );
			break;
		case 'quote':
			$q->set( 'post_type', post_type_exists( 'meeha_photocard' ) ? 'meeha_photocard' : 'meeha_none' );
			break;
		case 'opinion':
		case 'literature':
			$q->set( 'post_type', 'post' );
			$tax[] = array(
				'taxonomy' => 'category',
				'terms'    => $trees[ $type ] ? $trees[ $type ] : array( 0 ),
			);
			break;
		case 'news':
			$q->set( 'post_type', 'post' );
			$not = array_merge( $trees['opinion'], $trees['literature'] );
			if ( $not ) {
				$tax[] = array(
					'taxonomy' => 'category',
					'terms'    => $not,
					'operator' => 'NOT IN',
				);
			}
			break;
		default:
			$q->set( 'post_type', meeha_search_post_types() );
	}
	if ( $tax ) {
		$q->set( 'tax_query', $tax ); // phpcs:ignore WordPress.DB.SlowDBQuery
	}
	$now  = time();
	$date = array();
	if ( '1d' === $f['when'] ) {
		$date[] = array( 'after' => gmdate( 'Y-m-d H:i:s', $now - DAY_IN_SECONDS ), 'column' => 'post_date_gmt' );
	} elseif ( '7d' === $f['when'] ) {
		$date[] = array( 'after' => gmdate( 'Y-m-d H:i:s', $now - 7 * DAY_IN_SECONDS ), 'column' => 'post_date_gmt' );
	} elseif ( '30d' === $f['when'] ) {
		$date[] = array( 'after' => gmdate( 'Y-m-d H:i:s', $now - 30 * DAY_IN_SECONDS ), 'column' => 'post_date_gmt' );
	} elseif ( 'range' === $f['when'] ) {
		$r = array( 'inclusive' => true );
		if ( $f['from'] ) {
			$r['after'] = $f['from'] . ' 00:00:00';
		}
		if ( $f['to'] ) {
			$r['before'] = $f['to'] . ' 23:59:59';
		}
		$date[] = $r;
	}
	if ( $date ) {
		$q->set( 'date_query', $date );
	}
	if ( ! $f['author'] ) {
		$q->set( 'author', '' );
	}
}

add_action(
	'pre_get_posts',
	function ( $q ) {
		if ( is_admin() || ! $q->is_main_query() || ! $q->is_search() || $q->is_feed() ) {
			return;
		}
		$f = meeha_search_filters();
		meeha_search_apply_filters( $q, $f, $f['type'] );
		$q->set( 'posts_per_page', 10 );
		$q->set( 'ignore_sticky_posts', true );
		$q->set( 'meeha_search', 1 );
		if ( 'new' === $f['sort'] ) {
			$q->set( 'orderby', 'date' );
			$q->set( 'order', 'DESC' );
		} elseif ( 'old' === $f['sort'] ) {
			$q->set( 'orderby', 'date' );
			$q->set( 'order', 'ASC' );
		}
	}
);

/**
 * Is this query one of ours?
 *
 * @param WP_Query $q Query.
 * @return bool
 */
function meeha_search_is_ours( $q ) {
	return $q instanceof WP_Query && $q->get( 'meeha_search' ) && $q->is_search() && ! is_admin();
}

/**
 * LIKE pattern for a term.
 *
 * @param string $t Term.
 * @return string
 */
function meeha_search_like( $t ) {
	global $wpdb;
	return '%' . $wpdb->esc_like( $t ) . '%';
}

add_filter(
	'posts_search',
	function ( $search, $q ) {
		if ( ! meeha_search_is_ours( $q ) ) {
			return $search;
		}
		global $wpdb;
		$terms = meeha_search_terms( (string) $q->get( 's' ) );
		if ( ! $terms ) {
			return ' AND 1=0 ';
		}
		$and = array();
		foreach ( $terms as $t ) {
			$l     = meeha_search_like( $t );
			$and[] = $wpdb->prepare(
				"( mst.meta_value LIKE %s OR ( mst.meta_value IS NULL AND ( {$wpdb->posts}.post_title LIKE %s OR {$wpdb->posts}.post_excerpt LIKE %s OR {$wpdb->posts}.post_content LIKE %s ) ) )", // phpcs:ignore WordPress.DB.PreparedSQL
				$l,
				$l,
				$l,
				$l
			);
		}
		$sql = ' AND ( ' . implode( ' AND ', $and ) . ' ) ';
		if ( ! current_user_can( 'edit_others_posts' ) ) { // readers (incl. subscribers) never search inside protected posts.
			$sql .= " AND ( {$wpdb->posts}.post_password = '' ) ";
		}
		return $sql;
	},
	10,
	2
);

add_filter(
	'posts_join',
	function ( $join, $q ) {
		if ( ! meeha_search_is_ours( $q ) || ! meeha_search_terms( (string) $q->get( 's' ) ) ) {
			return $join;
		}
		global $wpdb;
		$join .= " LEFT JOIN {$wpdb->postmeta} mst ON ( mst.post_id = {$wpdb->posts}.ID AND mst.meta_key = '_meeha_search_text' )";
		$join .= " LEFT JOIN {$wpdb->postmeta} mstt ON ( mstt.post_id = {$wpdb->posts}.ID AND mstt.meta_key = '_meeha_search_title' )";
		$join .= " LEFT JOIN {$wpdb->postmeta} msts ON ( msts.post_id = {$wpdb->posts}.ID AND msts.meta_key = '_meeha_search_sum' )";
		return $join;
	},
	10,
	2
);

/**
 * Relevance expression: Σ terms (title ×3 + summary ×2 + body ×1) + phrase-in-title ×3 + recency (7 d +1.5, 30 d +0.5 —
 * enough to lift a fresh story above an equally relevant old one, never above a stronger field match).
 *
 * @param string[] $terms Terms.
 * @param int|null $now   Now.
 * @return string SQL.
 */
function meeha_search_relevance_sql( $terms, $now = null ) {
	global $wpdb;
	$now   = null === $now ? time() : (int) $now;
	$parts = array();
	foreach ( $terms as $t ) {
		$l       = meeha_search_like( $t );
		$parts[] = $wpdb->prepare(
			"(CASE WHEN mstt.meta_value LIKE %s THEN 3 WHEN mstt.meta_value IS NULL AND {$wpdb->posts}.post_title LIKE %s THEN 3 ELSE 0 END)
			+ (CASE WHEN msts.meta_value LIKE %s THEN 2 ELSE 0 END)
			+ (CASE WHEN mst.meta_value LIKE %s THEN 1 ELSE 0 END)", // phpcs:ignore WordPress.DB.PreparedSQL
			$l,
			$l,
			$l,
			$l
		);
	}
	if ( count( $terms ) > 1 ) {
		$parts[] = $wpdb->prepare( '(CASE WHEN mstt.meta_value LIKE %s THEN 3 ELSE 0 END)', meeha_search_like( implode( ' ', $terms ) ) );
	}
	$parts[] = $wpdb->prepare(
		"(CASE WHEN {$wpdb->posts}.post_date_gmt >= %s THEN 1.5 WHEN {$wpdb->posts}.post_date_gmt >= %s THEN 0.5 ELSE 0 END)", // phpcs:ignore WordPress.DB.PreparedSQL
		gmdate( 'Y-m-d H:i:s', $now - 7 * DAY_IN_SECONDS ),
		gmdate( 'Y-m-d H:i:s', $now - 30 * DAY_IN_SECONDS )
	);
	return '( ' . implode( ' + ', $parts ) . ' )';
}

add_filter(
	'posts_orderby',
	function ( $orderby, $q ) {
		if ( ! meeha_search_is_ours( $q ) ) {
			return $orderby;
		}
		$f = meeha_search_filters();
		if ( 'relevance' !== $f['sort'] ) {
			return $orderby;
		}
		$terms = meeha_search_terms( (string) $q->get( 's' ) );
		if ( ! $terms ) {
			return $orderby;
		}
		global $wpdb;
		return meeha_search_relevance_sql( $terms ) . " DESC, {$wpdb->posts}.post_date DESC";
	},
	10,
	2
);

/**
 * No ticker on the results page (prototype).
 */
add_filter(
	'meeha_show_ticker',
	function ( $show ) {
		return is_search() ? false : $show;
	}
);

/**
 * Counts per type tab for the current search (one id query, classified in PHP; object-cached 5 min, per user).
 *
 * @return array slug => int
 */
function meeha_search_type_counts() {
	$s = (string) get_search_query( false );
	$f = meeha_search_filters();
	$k = 'meeha_sc_' . md5( wp_json_encode( array( meeha_search_terms( $s ), $f['when'], $f['from'], $f['to'], $f['cat'], $f['author'], get_current_user_id(), function_exists( 'meeha_home_gen' ) ? meeha_home_gen() : 0 ) ) );
	// Object cache only: free-text queries must not create wp_options rows (no persistent cache → per request).
	$c = wp_cache_get( $k, 'meeha_search' );
	if ( is_array( $c ) ) {
		return $c;
	}
	$c = array_fill_keys( array_keys( meeha_search_types() ), 0 );
	if ( meeha_search_terms( $s ) ) {
		$q = new WP_Query();
		add_action(
			'pre_get_posts',
			$fn = function ( $qq ) use ( $q, $f ) {
				if ( $qq === $q ) {
					meeha_search_apply_filters( $qq, $f, 'all' );
				}
			}
		);
		$ids = $q->query(
			array(
				's'                   => $s,
				'cat'                 => $f['cat'],
				'author'              => $f['author'] ? $f['author'] : '',
				'meeha_search'        => 1,
				'fields'              => 'ids',
				'posts_per_page'      => 1000,
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
				'orderby'             => 'date',
			)
		);
		remove_action( 'pre_get_posts', $fn );
		$ids = array_map( 'intval', (array) $ids );
		if ( $ids ) {
			_prime_post_caches( $ids, true, false );
			$trees = meeha_search_type_trees();
			foreach ( $ids as $id ) {
				$p = get_post( $id );
				if ( ! $p ) {
					continue;
				}
				$c['all']++;
				if ( 'meeha_video' === $p->post_type ) {
					$c['video']++;
				} elseif ( 'meeha_photocard' === $p->post_type ) {
					$c['quote']++;
				} else {
					$cats = wp_get_post_categories( $id );
					if ( array_intersect( $cats, $trees['literature'] ) ) {
						$c['literature']++;
					} elseif ( array_intersect( $cats, $trees['opinion'] ) ) {
						$c['opinion']++;
					} else {
						$c['news']++;
					}
				}
			}
		}
	}
	wp_cache_set( $k, $c, 'meeha_search', 5 * MINUTE_IN_SECONDS );
	return $c;
}
