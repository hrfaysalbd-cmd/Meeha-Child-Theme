<?php
/**
 * Header/footer data helpers: menus (flat + grouped, with "current" flags), ticker items, search chips,
 * social profiles.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

/**
 * Menu items of a location as a tree: [ ['title','url','current'(page|true|false),'children'=>[…]], … ].
 *
 * @param string $location Location.
 * @return array|null null when no menu is assigned.
 */
function meeha_menu_tree( $location ) {
	$locs = get_nav_menu_locations();
	if ( empty( $locs[ $location ] ) ) {
		return null;
	}
	$menu = wp_get_nav_menu_object( $locs[ $location ] );
	if ( ! $menu ) {
		return null;
	}
	$items = wp_get_nav_menu_items( $menu->term_id, array( 'update_post_term_cache' => false ) );
	if ( ! $items ) {
		return array();
	}
	_wp_menu_item_classes_by_context( $items );
	$by   = array();
	$tree = array();
	foreach ( $items as $it ) {
		$cls     = (array) $it->classes;
		$current = in_array( 'current-menu-item', $cls, true ) ? 'page' : ( array_intersect( array( 'current-menu-ancestor', 'current-menu-parent', 'current-post-ancestor', 'current-category-ancestor' ), $cls ) ? 'true' : '' );
		$by[ $it->ID ] = array(
			'id'       => (int) $it->ID,
			'title'    => $it->title,
			'url'      => $it->url,
			'current'  => $current,
			'target'   => $it->target,
			'parent'   => (int) $it->menu_item_parent,
			'children' => array(),
		);
	}
	foreach ( $by as $id => $node ) {
		if ( $node['parent'] && isset( $by[ $node['parent'] ] ) ) {
			$by[ $node['parent'] ]['children'][] = &$by[ $id ];
		}
	}
	foreach ( $by as $id => $node ) {
		if ( ! $node['parent'] || ! isset( $by[ $node['parent'] ] ) ) {
			$tree[] = &$by[ $id ];
		}
	}
	return $tree;
}

/**
 * Categories with posts, parents first (each followed by its children), largest desks first.
 *
 * @param int $min_count Minimum post count.
 * @return WP_Term[]
 */
function meeha_category_list( $min_count = 1 ) {
	$all = get_categories(
		array(
			'hide_empty' => false,
			'orderby'    => 'count',
			'order'      => 'DESC',
		)
	);
	$out = array();
	$kids = array();
	foreach ( $all as $c ) {
		if ( 'uncategorized' === $c->slug || (int) $c->count < $min_count ) {
			continue;
		}
		if ( $c->parent ) {
			$kids[ $c->parent ][] = $c;
		}
	}
	foreach ( $all as $c ) {
		if ( 'uncategorized' === $c->slug || $c->parent || ( (int) $c->count < $min_count && empty( $kids[ $c->term_id ] ) ) ) {
			continue;
		}
		$out[] = $c;
		foreach ( isset( $kids[ $c->term_id ] ) ? $kids[ $c->term_id ] : array() as $k ) {
			$out[] = $k;
		}
	}
	return $out;
}

/**
 * Is a category "current" for this request?
 *
 * @param WP_Term $c Category.
 * @return string page|true|''
 */
function meeha_category_current( $c ) {
	if ( is_category( $c->term_id ) ) {
		return 'page';
	}
	if ( is_category() ) {
		$q = get_queried_object();
		if ( $q && in_array( (int) $c->term_id, array_map( 'intval', get_ancestors( $q->term_id, 'category' ) ), true ) ) {
			return 'true';
		}
	}
	if ( is_singular( 'post' ) ) {
		$p = meeha_primary_category( get_queried_object() );
		if ( $p && ( (int) $p->term_id === (int) $c->term_id ) ) {
			return 'true';
		}
	}
	return '';
}

/**
 * Primary nav items (flat): menu `primary` top level, else the 12 biggest categories.
 *
 * @return array
 */
function meeha_primary_nav_items() {
	$tree = meeha_menu_tree( 'primary' );
	if ( null !== $tree ) {
		return $tree;
	}
	$out = array();
	foreach ( array_slice( array_filter( meeha_category_list( 1 ), function ( $c ) {
		return ! $c->parent;
	} ), 0, 12 ) as $c ) {
		$out[] = array(
			'title'    => $c->name,
			'url'      => get_category_link( $c ),
			'current'  => meeha_category_current( $c ),
			'target'   => '',
			'children' => array(),
		);
	}
	return $out;
}

/**
 * Drawer groups: [ ['title'=>…, 'links'=>[[title,url,current],…]], … ].
 *
 * @return array
 */
function meeha_drawer_groups() {
	$tree = meeha_menu_tree( 'drawer' );
	if ( null !== $tree && $tree ) {
		$groups = array();
		$loose  = array();
		foreach ( $tree as $node ) {
			if ( $node['children'] ) {
				$groups[] = array(
					'title' => $node['title'],
					'links' => $node['children'],
				);
			} else {
				$loose[] = $node;
			}
		}
		if ( $loose ) {
			array_unshift(
				$groups,
				array(
					'title' => 'বিভাগ',
					'links' => $loose,
				)
			);
		}
		return $groups;
	}
	$special = apply_filters( 'meeha_drawer_special_slugs', array( 'editorial', 'sub-editorial', 'readers-words', 'human-wall', 'meeha-special', 'bengali-culture' ) );
	$news    = array();
	$more    = array();
	foreach ( meeha_category_list( 1 ) as $c ) {
		$row = array(
			'title'   => $c->name,
			'url'     => get_category_link( $c ),
			'current' => meeha_category_current( $c ),
		);
		$root = $c;
		while ( $root->parent ) {
			$root = get_category( $root->parent );
		}
		if ( in_array( $c->slug, $special, true ) || in_array( $root->slug, $special, true ) ) {
			$more[] = $row;
		} else {
			$news[] = $row;
		}
	}
	foreach ( array( 'meeha_quote', 'meeha_photocard', 'meeha_video' ) as $pt ) {
		$o   = get_post_type_object( $pt );
		$url = $o ? get_post_type_archive_link( $pt ) : '';
		if ( $o && $url ) {
			$more[] = array(
				'title'   => 'meeha_video' === $pt ? 'ভিডিও' : 'উক্তি',
				'url'     => $url,
				'current' => is_post_type_archive( $pt ) ? 'page' : '',
			);
		}
	}
	$groups = array();
	if ( $news ) {
		$groups[] = array(
			'title' => 'সংবাদ',
			'links' => $news,
		);
	}
	if ( $more ) {
		$groups[] = array(
			'title' => 'মতামত ও বিশেষ',
			'links' => $more,
		);
	}
	return $groups;
}

/**
 * Footer column links: menu footer-N, else legacy meeha_footer_settings.columns[N-1], else (column 1 only) categories.
 *
 * @param int $n 1..3.
 * @return array [[title,url],…]
 */
function meeha_footer_column_links( $n ) {
	$tree = meeha_menu_tree( 'footer-' . $n );
	if ( null !== $tree ) {
		$out = array();
		foreach ( $tree as $node ) {
			$out[] = array( $node['title'], $node['url'] );
		}
		return $out;
	}
	$legacy = get_option( 'meeha_footer_settings', array() );
	if ( is_array( $legacy ) && ! empty( $legacy['columns'][ $n - 1 ]['links'] ) ) {
		$out = array();
		foreach ( (array) $legacy['columns'][ $n - 1 ]['links'] as $l ) {
			if ( ! empty( $l['label'] ) && ! empty( $l['url'] ) ) {
				$out[] = array( $l['label'], $l['url'] );
			}
		}
		if ( $out ) {
			return $out;
		}
	}
	if ( 1 === (int) $n ) {
		$out = array();
		foreach ( array_slice( array_filter( meeha_category_list( 1 ), function ( $c ) {
			return ! $c->parent;
		} ), 0, 7 ) as $c ) {
			$out[] = array( $c->name, get_category_link( $c ) );
		}
		return $out;
	}
	return array();
}

/**
 * Title of footer column N (setting, else the legacy column title for that column).
 *
 * @param int $n 1..3.
 * @return string
 */
function meeha_footer_column_title( $n ) {
	$locs = get_nav_menu_locations();
	if ( empty( $locs[ 'footer-' . $n ] ) ) {
		$legacy = get_option( 'meeha_footer_settings', array() );
		$stored = get_option( 'meeha_opt_footer', array() );
		if ( empty( $stored[ 'col' . $n . '_title' ] ) && is_array( $legacy ) && ! empty( $legacy['columns'][ $n - 1 ]['title'] ) && ! empty( $legacy['columns'][ $n - 1 ]['links'] ) ) {
			return (string) $legacy['columns'][ $n - 1 ]['title'];
		}
	}
	return (string) meeha_opt( 'footer', 'col' . $n . '_title', '' );
}

/**
 * Social profiles that have a URL.
 *
 * @return array [ [key, label, url], … ]
 */
function meeha_social_profiles() {
	$labels = array(
		'facebook'  => 'ফেসবুক',
		'youtube'   => 'ইউটিউব',
		'x'         => 'এক্স (টুইটার)',
		'instagram' => 'ইনস্টাগ্রাম',
		'whatsapp'  => 'হোয়াটসঅ্যাপ চ্যানেল',
		'telegram'  => 'টেলিগ্রাম',
	);
	$out = array();
	foreach ( $labels as $k => $label ) {
		$url = (string) meeha_opt( 'social', $k, '' );
		if ( '' !== $url ) {
			$out[] = array( $k, $label, $url );
		}
	}
	return $out;
}

/**
 * Ticker data (cached 2 minutes; flushed whenever a post changes).
 *
 * @return array ['mode' => 'breaking'|'latest'|'', 'items' => WP_Post[]]
 */
function meeha_ticker_data() {
	$o    = meeha_opt( 'header' );
	$mode = isset( $o['ticker_mode'] ) ? $o['ticker_mode'] : 'auto';
	if ( 'off' === $mode ) {
		return array(
			'mode'  => '',
			'items' => array(),
		);
	}
	$count = max( 3, min( 15, (int) $o['ticker_count'] ) );
	$hours = max( 1, (int) $o['breaking_hours'] );
	$sig    = md5( wp_json_encode( array( $mode, $count, $hours, (int) $o['ticker_category'] ) ) );
	$cached = get_transient( 'meeha_ticker' );
	$ids    = ( is_array( $cached ) && isset( $cached['sig'] ) && $cached['sig'] === $sig ) ? $cached : null;
	if ( ! is_array( $ids ) ) {
		$ids    = array( 'mode' => '', 'ids' => array() );
		$now    = time();
		$recent = get_posts(
			array(
				'post_type'        => 'post',
				'post_status'      => 'publish',
				'has_password'     => false,
				'posts_per_page'   => 20,
				'meta_key'         => '_meeha_breaking', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'       => '1', // phpcs:ignore WordPress.DB.SlowDBQuery
				'date_query'       => array( array( 'after' => '7 days ago' ) ),
				'no_found_rows'    => true,
				'suppress_filters' => false,
			)
		);
		$breaking = array();
		foreach ( $recent as $p ) {
			$at = (int) get_post_meta( $p->ID, '_meeha_breaking_at', true );
			if ( ! $at ) {
				$at = (int) get_post_time( 'U', true, $p );
			}
			if ( $at >= $now - $hours * HOUR_IN_SECONDS ) {
				$breaking[] = (int) $p->ID;
			}
		}
		if ( $breaking ) {
			$ids = array( 'mode' => 'breaking', 'ids' => array_slice( $breaking, 0, $count ) );
		} elseif ( 'auto' === $mode ) {
			$args = array(
				'post_type'        => 'post',
				'post_status'      => 'publish',
				'has_password'     => false,
				'posts_per_page'   => $count,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => false,
				'ignore_sticky_posts' => true,
			);
			if ( ! empty( $o['ticker_category'] ) ) {
				$args['cat'] = (int) $o['ticker_category'];
			}
			$ids = array( 'mode' => 'latest', 'ids' => array_map( 'intval', get_posts( $args ) ) );
		}
		$ids['sig'] = $sig;
		set_transient( 'meeha_ticker', $ids, 2 * MINUTE_IN_SECONDS );
	}
	$items = array();
	foreach ( $ids['ids'] as $id ) {
		$p = get_post( $id );
		if ( $p && 'publish' === $p->post_status ) {
			$items[] = $p;
		}
	}
	return array(
		'mode'  => $items ? $ids['mode'] : '',
		'items' => $items,
	);
}

/**
 * Flush cached ticker data when posts change.
 */
function meeha_flush_ticker_cache() {
	delete_transient( 'meeha_ticker' );
	delete_transient( 'meeha_search_chips' );
}
add_action( 'transition_post_status', 'meeha_flush_ticker_cache' );
add_action(
	'updated_post_meta',
	function ( $mid, $post_id, $key ) {
		if ( in_array( $key, array( '_meeha_breaking', '_meeha_breaking_at' ), true ) ) {
			meeha_flush_ticker_cache();
		}
	},
	10,
	3
);
add_action(
	'added_post_meta',
	function ( $mid, $post_id, $key ) {
		if ( in_array( $key, array( '_meeha_breaking', '_meeha_breaking_at' ), true ) ) {
			meeha_flush_ticker_cache();
		}
	},
	10,
	3
);
add_action(
	'meeha_settings_saved',
	function ( $tab ) {
		if ( 'header' === $tab ) {
			meeha_flush_ticker_cache();
		}
	}
);

/**
 * Should the ticker render on this view? (filter `meeha_show_ticker`)
 *
 * @return bool
 */
function meeha_show_ticker() {
	$key  = meeha_current_template_key();
	$show = ! in_array( $key, array( 'page', 'account', 'literature' ), true );
	return (bool) apply_filters( 'meeha_show_ticker', $show, $key );
}

/**
 * "এখন যা বেশি খোঁজা হচ্ছে" chips for the search dialog: tags of trending posts (C) else the biggest tags.
 *
 * @return array [[label,url],…]
 */
function meeha_search_chips() {
	$chips = get_transient( 'meeha_search_chips' );
	if ( ! is_array( $chips ) ) {
		$chips = array();
		$names = array();
		if ( function_exists( 'meeha_get_trending' ) ) {
			foreach ( (array) meeha_get_trending( 5 ) as $t ) {
				$p = isset( $t['post'] ) ? $t['post'] : null;
				if ( $p ) {
					foreach ( (array) get_the_tags( $p->ID ) as $tag ) {
						if ( $tag && ! isset( $names[ $tag->name ] ) ) {
							$names[ $tag->name ] = true;
						}
					}
				}
			}
		}
		if ( count( $names ) < 3 ) {
			foreach ( get_tags( array( 'orderby' => 'count', 'order' => 'DESC', 'number' => 6 ) ) as $tag ) {
				$names[ $tag->name ] = true;
			}
		}
		foreach ( array_slice( array_keys( $names ), 0, 6 ) as $name ) {
			$chips[] = array( $name, add_query_arg( 's', rawurlencode( $name ), home_url( '/' ) ) );
		}
		set_transient( 'meeha_search_chips', $chips, HOUR_IN_SECONDS );
	}
	return (array) apply_filters( 'meeha_search_chips', $chips );
}
