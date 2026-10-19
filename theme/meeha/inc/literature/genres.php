<?php
/**
 * Literature genres: defaults, settings merge, category mapping/creator, genre detection.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

/**
 * Root category slug of the literature tree.
 *
 * @return string
 */
function meeha_lit_root_slug() {
	return (string) apply_filters( 'meeha_lit_root_slug', 'bengali-culture' );
}

/**
 * Layout styles.
 *
 * @return array id => label
 */
function meeha_lit_layouts() {
	return array(
		'verse'        => 'কবিতার কার্ড (প্রথম ৪ পঙ্‌ক্তি)',
		'feature-list' => 'ছবিসহ প্রধান লেখা + তালিকা',
		'essays'       => 'দুই কলামে লেখা ও সারাংশ',
		'cards'        => 'ছবির কার্ড',
		'books'        => 'বইয়ের প্রচ্ছদ + তথ্য + রেটিং',
		'list'         => 'তালিকা (মূল/অনুবাদ)',
		'portraits'    => 'প্রতিকৃতি + উদ্ধৃতি',
	);
}

/**
 * Icons available for genres (sprite ids).
 *
 * @return string[]
 */
function meeha_lit_icons() {
	return array( 'feather', 'book', 'scroll', 'lantern', 'column', 'translate', 'mic', 'star', 'quote', 'globe', 'sparkle', 'calendar' );
}

/**
 * Default genre definitions (DESIGN-SPEC §13.1).
 *
 * @return array id => def
 */
function meeha_lit_genre_defaults() {
	return array(
		'poem'        => array( 'label' => 'কবিতা', 'intro' => 'নতুন ও প্রতিষ্ঠিত কবিদের কবিতা, পঙ্‌ক্তি যেমন লেখা তেমনই সাজানো।', 'layout' => 'verse', 'count' => 3, 'color' => '#1D4F91', 'icon' => 'feather', 'anchor' => 'kobita', 'more' => 'সব কবিতা', 'cat_name' => 'কবিতা', 'cat_slug' => 'poetry', 'parent' => 'sahityaloka' ),
		'story'       => array( 'label' => 'গল্প', 'intro' => '', 'layout' => 'feature-list', 'count' => 3, 'color' => '#7A2E3A', 'icon' => 'book', 'anchor' => 'golpo', 'more' => 'সব গল্প', 'cat_name' => 'গল্প', 'cat_slug' => 'story', 'parent' => 'sahityaloka' ),
		'essay'       => array( 'label' => 'প্রবন্ধ', 'intro' => '', 'layout' => 'essays', 'count' => 4, 'color' => '#2F5D50', 'icon' => 'scroll', 'anchor' => 'probondho', 'more' => 'সব প্রবন্ধ', 'cat_name' => 'প্রবন্ধ', 'cat_slug' => 'essay', 'parent' => 'sahityaloka' ),
		'folk'        => array( 'label' => 'সংস্কৃতি ও লোকসংস্কৃতি', 'intro' => '', 'layout' => 'cards', 'count' => 3, 'color' => '#8A5D0E', 'icon' => 'lantern', 'anchor' => 'lok', 'more' => 'সব লেখা', 'cat_name' => 'লোকসংস্কৃতি', 'cat_slug' => 'folk-culture', 'parent' => '' ),
		'history'     => array( 'label' => 'ইতিহাস-ঐতিহ্য', 'intro' => '', 'layout' => 'cards', 'count' => 3, 'color' => '#6B4E2E', 'icon' => 'column', 'anchor' => 'itihas', 'more' => 'সব লেখা', 'cat_name' => 'ইতিহাস-ঐতিহ্য', 'cat_slug' => 'history-heritage', 'parent' => '' ),
		'books'       => array( 'label' => 'বই আলোচনা', 'intro' => '', 'layout' => 'books', 'count' => 3, 'color' => '#3E4A8A', 'icon' => 'book', 'anchor' => 'boi', 'more' => 'সব আলোচনা', 'cat_name' => 'বই আলোচনা', 'cat_slug' => 'book-review', 'parent' => 'sahityaloka' ),
		'translation' => array( 'label' => 'অনুবাদ', 'intro' => '', 'layout' => 'list', 'count' => 4, 'color' => '#276A7A', 'icon' => 'translate', 'anchor' => 'onubad', 'more' => 'সব অনুবাদ', 'cat_name' => 'অনুবাদ', 'cat_slug' => 'translation', 'parent' => 'sahityaloka' ),
		'interview'   => array( 'label' => 'সাক্ষাৎকার', 'intro' => '', 'layout' => 'portraits', 'count' => 2, 'color' => '#5A3D7A', 'icon' => 'mic', 'anchor' => 'sakkhatkar', 'more' => 'সব সাক্ষাৎকার', 'cat_name' => 'সাক্ষাৎকার', 'cat_slug' => 'interview', 'parent' => 'sahityaloka' ),
	);
}

/**
 * Genres merged with settings (stored order first, then any new default genre), each with resolved `cat`.
 *
 * @param bool $enabled_only Only enabled ones.
 * @return array id => def (+ id, enabled, cat, more_url)
 */
function meeha_lit_genres( $enabled_only = false ) {
	if ( isset( $GLOBALS['meeha_lit_genres_cache'] ) ) {
		$out = $GLOBALS['meeha_lit_genres_cache'];
		return $enabled_only ? array_filter( $out, function ( $g ) { return ! empty( $g['enabled'] ); } ) : $out;
	}
	$defs   = meeha_lit_genre_defaults();
	$stored = meeha_opt( 'literature', 'genres', array() );
	$out    = array();
	foreach ( (array) $stored as $row ) {
		if ( empty( $row['id'] ) || ! isset( $defs[ $row['id'] ] ) || isset( $out[ $row['id'] ] ) ) {
			continue;
		}
		$out[ $row['id'] ] = array_merge( $defs[ $row['id'] ], array_filter( $row, function ( $v ) { return '' !== $v && null !== $v; } ) );
		$out[ $row['id'] ]['enabled'] = ! empty( $row['enabled'] );
	}
	foreach ( $defs as $id => $d ) {
		if ( ! isset( $out[ $id ] ) ) {
			$out[ $id ] = array_merge( $d, array( 'enabled' => true ) );
		}
	}
	foreach ( $out as $id => &$g ) {
		$g['id']    = $id;
		$g['cat']   = isset( $g['cat'] ) ? (int) $g['cat'] : 0;
		$g['count'] = max( 1, min( 12, (int) $g['count'] ) );
		if ( ! $g['cat'] || ! term_exists( $g['cat'], 'category' ) ) {
			$g['cat'] = meeha_lit_find_genre_cat( $g );
		}
		if ( ! isset( $g['more_url'] ) || '' === $g['more_url'] ) {
			$g['more_url'] = $g['cat'] ? get_category_link( $g['cat'] ) : '';
		}
	}
	unset( $g );
	$GLOBALS['meeha_lit_genres_cache'] = $out;
	if ( $enabled_only ) {
		$out = array_filter(
			$out,
			function ( $g ) {
				return ! empty( $g['enabled'] );
			}
		);
	}
	return $out;
}

/**
 * Find an existing category for a genre inside the literature tree (by slug, then by name).
 *
 * @param array $g Genre.
 * @return int Term id or 0.
 */
function meeha_lit_find_genre_cat( $g ) {
	$key = $g['cat_slug'] . '|' . $g['cat_name'];
	if ( isset( $GLOBALS['meeha_lit_catmemo'][ $key ] ) ) {
		return $GLOBALS['meeha_lit_catmemo'][ $key ];
	}
	$tree = meeha_lit_tree_ids();
	$id   = 0;
	$t    = get_category_by_slug( $g['cat_slug'] );
	if ( $t && in_array( (int) $t->term_id, $tree, true ) ) {
		$id = (int) $t->term_id;
	}
	if ( ! $id ) {
		foreach ( $tree as $tid ) {
			$term = get_term( $tid, 'category' );
			if ( $term && ! is_wp_error( $term ) && ( $term->name === $g['cat_name'] || $term->slug === 'lit-' . $g['cat_slug'] ) ) {
				$id = (int) $tid;
				break;
			}
		}
	}
	$GLOBALS['meeha_lit_catmemo'][ $key ] = $id;
	return $id;
}

/**
 * Term ids of the literature tree (root + descendants).
 *
 * @return int[]
 */
function meeha_lit_tree_ids() {
	$root = get_category_by_slug( meeha_lit_root_slug() );
	if ( ! $root ) {
		return array();
	}
	return array_merge( array( (int) $root->term_id ), array_map( 'intval', (array) get_term_children( $root->term_id, 'category' ) ) );
}

/**
 * Is the post in the literature tree?
 *
 * @param WP_Post|int $post Post.
 * @return bool
 */
function meeha_lit_is_literature( $post ) {
	$post = get_post( $post );
	return $post && 'post' === $post->post_type && meeha_in_category_tree( $post, array( meeha_lit_root_slug() ) );
}

/**
 * Create missing genre categories and map them (idempotent; never renames, moves or deletes anything).
 * Literary genres go under সাহিত্যলোক (if it exists, itself under বাঙালি সাহিত্য-সংস্কৃতি), লোকসংস্কৃতি and
 * ইতিহাস-ঐতিহ্য directly under বাঙালি সাহিত্য-সংস্কৃতি.
 *
 * @return array created => [names], mapped => [genre => term id], error => string
 */
function meeha_lit_create_genre_categories() {
	$res  = array(
		'created' => array(),
		'mapped'  => array(),
		'error'   => '',
	);
	$root = get_category_by_slug( meeha_lit_root_slug() );
	if ( ! $root ) {
		$r = wp_insert_term( 'বাঙালি সাহিত্য-সংস্কৃতি', 'category', array( 'slug' => meeha_lit_root_slug() ) );
		if ( is_wp_error( $r ) ) {
			$res['error'] = $r->get_error_message();
			return $res;
		}
		$root               = get_term( $r['term_id'], 'category' );
		$res['created'][]   = $root->name;
	}
	unset( $GLOBALS['meeha_lit_genres_cache'], $GLOBALS['meeha_lit_catmemo'] );
	$sahityaloka = get_category_by_slug( 'sahityaloka' );
	$sahit_ok    = $sahityaloka && in_array( (int) $sahityaloka->term_id, meeha_lit_tree_ids(), true );

	$stored = (array) meeha_opt( 'literature', 'genres', array() );
	$by_id  = array();
	foreach ( $stored as $row ) {
		if ( ! empty( $row['id'] ) ) {
			$by_id[ $row['id'] ] = $row;
		}
	}
	foreach ( meeha_lit_genre_defaults() as $gid => $g ) {
		$cur = isset( $by_id[ $gid ]['cat'] ) ? (int) $by_id[ $gid ]['cat'] : 0;
		if ( $cur && term_exists( $cur, 'category' ) ) {
			$res['mapped'][ $gid ] = $cur;
			continue;
		}
		$tid = meeha_lit_find_genre_cat( $g );
		if ( ! $tid ) {
			$parent = ( 'sahityaloka' === $g['parent'] && $sahit_ok ) ? (int) $sahityaloka->term_id : (int) $root->term_id;
			$slug   = $g['cat_slug'];
			$clash  = get_category_by_slug( $slug );
			if ( $clash ) {
				$slug = 'lit-' . $slug; // the plain slug is used elsewhere in the site.
			}
			$r = wp_insert_term( $g['cat_name'], 'category', array( 'slug' => $slug, 'parent' => $parent ) );
			if ( is_wp_error( $r ) ) {
				if ( 'term_exists' === $r->get_error_code() ) {
					$tid = (int) $r->get_error_data();
				} else {
					continue;
				}
			} else {
				$tid                = (int) $r['term_id'];
				$res['created'][] = $g['cat_name'];
			}
		}
		$res['mapped'][ $gid ] = $tid;
	}
	// Save the mapping (keep any other per-genre settings).
	$new = array();
	foreach ( meeha_lit_genres() as $gid => $g ) {
		$row        = isset( $by_id[ $gid ] ) ? $by_id[ $gid ] : array(
			'id'      => $gid,
			'enabled' => 1,
		);
		$row['id']  = $gid;
		$row['cat'] = isset( $res['mapped'][ $gid ] ) ? $res['mapped'][ $gid ] : ( isset( $row['cat'] ) ? (int) $row['cat'] : 0 );
		if ( ! isset( $row['enabled'] ) ) {
			$row['enabled'] = 1;
		}
		$new[] = $row;
	}
	meeha_opt_update( 'literature', array( 'genres' => $new ) );
	unset( $GLOBALS['meeha_lit_genres_cache'], $GLOBALS['meeha_lit_catmemo'] );
	return $res;
}

/**
 * Genre id of a literature post: explicit choice (`_meeha_lit_genre`) → genre category → fields → content heuristic.
 *
 * @param WP_Post|int $post Post.
 * @return string Genre id ('' when the post is not literature).
 */
function meeha_lit_genre_of( $post ) {
	$post = get_post( $post );
	if ( ! $post || ! meeha_lit_is_literature( $post ) ) {
		return '';
	}
	$genres = meeha_lit_genres();
	$pick   = (string) get_post_meta( $post->ID, '_meeha_lit_genre', true );
	if ( '' !== $pick && isset( $genres[ $pick ] ) ) {
		return $pick;
	}
	$cats = array_map( 'intval', wp_get_post_categories( $post->ID ) );
	foreach ( $genres as $gid => $g ) {
		if ( ! $g['cat'] ) {
			continue;
		}
		$ids = array_merge( array( $g['cat'] ), array_map( 'intval', (array) get_term_children( $g['cat'], 'category' ) ) );
		if ( array_intersect( $ids, $cats ) ) {
			return $gid;
		}
	}
	// Category NAME keywords (deepest category first): a post filed under an unmapped "ছোটগল্প" is a story.
	$by_name = meeha_lit_genre_from_category_names( $post, $genres );
	if ( '' !== $by_name ) {
		return $by_name;
	}
	$m = function ( $k ) use ( $post ) {
		return '' !== trim( (string) get_post_meta( $post->ID, '_meeha_lit_' . $k, true ) );
	};
	if ( $m( 'book_title' ) || 0 === mb_strpos( $post->post_title, 'বই আলোচনা' ) ) {
		return 'books';
	}
	if ( $m( 'orig_author' ) || $m( 'translator' ) ) {
		return 'translation';
	}
	if ( $m( 'interviewee' ) ) {
		return 'interview';
	}
	if ( $m( 'poet' ) || $m( 'collection' ) || $m( 'recite_url' ) || meeha_lit_looks_like_poem( $post->post_content ) ) {
		return 'poem';
	}
	if ( $m( 'region' ) ) {
		return 'folk';
	}
	if ( $m( 'era' ) ) {
		return 'history';
	}
	if ( $m( 'series' ) || $m( 'story_type' ) ) {
		return 'story';
	}
	return (string) apply_filters( 'meeha_lit_default_genre', 'essay', $post );
}

/**
 * Genre from the names of the post's literature categories, deepest first (the root is skipped: its name
 * "সাহিত্য-সংস্কৃতি" would read as "সংস্কৃতি").
 *
 * @param WP_Post $post   Post.
 * @param array   $genres Genres (id => def).
 * @return string Genre id or ''.
 */
function meeha_lit_genre_from_category_names( $post, $genres ) {
	$map  = (array) apply_filters(
		'meeha_lit_genre_keywords',
		array(
			'story'       => array( 'গল্প', 'উপন্যাস' ),
			'poem'        => array( 'কবিতা', 'ছড়া', 'পদ্য' ),
			'essay'       => array( 'প্রবন্ধ', 'নিবন্ধ' ),
			'books'       => array( 'বই' ),
			'translation' => array( 'অনুবাদ' ),
			'interview'   => array( 'সাক্ষাৎকার' ),
			'folk'        => array( 'লোকসংস্কৃতি', 'সংস্কৃতি' ),
			'history'     => array( 'ইতিহাস', 'ঐতিহ্য' ),
		)
	);
	$tree = meeha_lit_tree_ids();
	$root = $tree ? (int) $tree[0] : 0;
	$cats = array();
	foreach ( (array) get_the_category( $post->ID ) as $c ) {
		if ( (int) $c->term_id !== $root && in_array( (int) $c->term_id, $tree, true ) ) {
			$cats[] = array( count( get_ancestors( $c->term_id, 'category' ) ), $c );
		}
	}
	usort(
		$cats,
		function ( $a, $b ) {
			return $b[0] - $a[0];
		}
	);
	foreach ( $cats as $row ) {
		$name = str_replace( "\u{09A4}\u{09CD}\u{200D}", "\u{09CE}", html_entity_decode( $row[1]->name, ENT_QUOTES, 'UTF-8' ) ); // ত্‍ → ৎ
		foreach ( $map as $gid => $words ) {
			if ( ! isset( $genres[ $gid ] ) ) {
				continue;
			}
			foreach ( (array) $words as $w ) {
				if ( false !== mb_strpos( $name, $w ) ) {
					return (string) $gid;
				}
			}
		}
	}
	return '';
}

/**
 * Content heuristic: mostly short lines separated by <br> / newlines.
 *
 * @param string $content Raw content.
 * @return bool
 */
function meeha_lit_looks_like_poem( $content ) {
	$c = (string) $content;
	if ( false !== stripos( $c, 'wp-block-verse' ) || false !== stripos( $c, '<!-- wp:verse' ) ) {
		return true;
	}
	$text  = preg_replace( '#<br\s*/?>#i', "\n", strip_shortcodes( $c ) );
	$text  = wp_strip_all_tags( preg_replace( '#<!--.*?-->#s', '', $text ) );
	$lines = array_values( array_filter( array_map( 'trim', explode( "\n", $text ) ), 'strlen' ) );
	if ( count( $lines ) < 4 ) {
		return false;
	}
	$short = 0;
	foreach ( $lines as $l ) {
		if ( mb_strlen( $l ) <= 70 ) {
			$short++;
		}
	}
	return $short / count( $lines ) >= 0.75 && substr_count( strtolower( $c ), '<br' ) >= 3;
}

/**
 * Genre definition by id (merged).
 *
 * @param string $id Genre id.
 * @return array|null
 */
function meeha_lit_genre( $id ) {
	$g = meeha_lit_genres();
	return isset( $g[ $id ] ) ? $g[ $id ] : null;
}
