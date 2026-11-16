<?php
/**
 * Literature rendering: `[meeha_sahitya]` / `[meeha_literature]` landing, homepage teaser (S11), shared bits
 * for the literature single (verse excerpt, CSS book cover, stars, recitation player).
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lines of a poem (plain text), from its stanza HTML.
 *
 * @param WP_Post $post Post.
 * @param int     $max  Max lines (0 = all).
 * @return string[]
 */
function meeha_lit_poem_lines( $post, $max = 4 ) {
	$post = get_post( $post );
	if ( ! $post || '' !== (string) $post->post_password ) {
		return array(); // never quote a password-protected poem.
	}
	$content = (string) $post->post_content;
	$content = preg_replace( '#<!--.*?-->#s', '', $content );
	$content = wpautop( strip_shortcodes( $content ) );
	$html    = meeha_lit_poem_html( $content );
	$lines   = array();
	if ( preg_match_all( '#<p class="m-stanza">(.*?)</p>#s', $html, $m ) ) {
		foreach ( $m[1] as $stanza ) {
			foreach ( preg_split( '#<br\s*/?>#i', $stanza ) as $l ) {
				$l = trim( html_entity_decode( wp_strip_all_tags( $l ), ENT_QUOTES, 'UTF-8' ) );
				if ( '' !== $l ) {
					$lines[] = $l;
				}
				if ( $max && count( $lines ) >= $max ) {
					return $lines;
				}
			}
		}
	}
	return $lines;
}

/**
 * Writer shown for a literature post (poet / interviewee field, else the WP author).
 *
 * @param WP_Post $post Post.
 * @return string
 */
function meeha_lit_writer( $post ) {
	$poet = meeha_lit_field( $post, 'poet' );
	if ( '' !== $poet ) {
		return $poet;
	}
	return function_exists( 'meeha_post_byline' ) ? meeha_post_byline( $post )['name'] : get_the_author_meta( 'display_name', (int) $post->post_author ); // 2.0.3: guest author.
}

/**
 * Inline style with the genre colour.
 *
 * @param array $g Genre.
 * @return string ' style="--g:#xxxxxx"' or ''.
 */
function meeha_lit_genre_style( $g ) {
	$c = isset( $g['color'] ) ? sanitize_hex_color( $g['color'] ) : '';
	return $c ? ' style="--g:' . esc_attr( $c ) . '"' : '';
}

/**
 * Stars markup (rating 1–5).
 *
 * @param int $n Rating.
 * @return string
 */
function meeha_lit_stars( $n ) {
	$n = max( 0, min( 5, (int) $n ) );
	if ( ! $n ) {
		return '';
	}
	$out = '<span class="m-stars" role="img" aria-label="' . esc_attr( '৫-এ ' . meeha_bn_digits( $n ) ) . '">';
	for ( $i = 1; $i <= 5; $i++ ) {
		$out .= meeha_icon( 'star', $i > $n ? array( 'class' => 'is-off' ) : array() );
	}
	return $out . '</span>';
}

/**
 * Book cover: the cover image when set, else a CSS cover from title/author in the genre colour.
 *
 * @param WP_Post $post Post.
 * @param string  $cls  Extra class.
 * @return string
 */
function meeha_lit_book_cover( $post, $cls = '' ) {
	$cover = (int) meeha_lit_field( $post, 'cover' );
	if ( $cover && wp_attachment_is_image( $cover ) ) {
		return '<div class="m-book m-book--img ' . esc_attr( $cls ) . '">' . meeha_img( $cover, 'portrait', array( 'alt' => '' ) ) . '</div>';
	}
	$title  = meeha_lit_field( $post, 'book_title' );
	$author = meeha_lit_field( $post, 'book_author' );
	if ( '' === $title ) {
		$title = preg_replace( '/^বই আলোচনা\s*[:：]\s*/u', '', get_the_title( $post ) );
		$title = preg_replace( '/\s+[—–-].*$/u', '', $title );
	}
	return '<div class="m-book ' . esc_attr( $cls ) . '" aria-hidden="true">' . esc_html( trim( (string) $title, " ‘’'\"" ) ) . ( '' !== $author ? '<small>' . esc_html( $author ) . '</small>' : '' ) . '</div>';
}

/**
 * Short plain excerpt.
 *
 * @param WP_Post $post  Post.
 * @param int     $words Words.
 * @return string
 */
function meeha_lit_excerpt( $post, $words = 24 ) {
	return meeha_post_dek( $post, $words );
}

/**
 * Posts of a genre.
 *
 * @param array $g     Genre.
 * @param int   $count Count.
 * @param array $excl  Exclude ids.
 * @return WP_Post[]
 */
function meeha_lit_genre_posts( $g, $count, $excl = array() ) {
	$q = array(
		'post_type'           => 'post',
		'post_status'         => 'publish',
		'posts_per_page'      => (int) $count,
		'no_found_rows'       => true,
		'ignore_sticky_posts' => true,
		'post__not_in'        => array_map( 'intval', (array) $excl ),
	);
	if ( $g['cat'] ) {
		$q['cat'] = (int) $g['cat'];
	} else {
		$root = get_category_by_slug( meeha_lit_root_slug() );
		if ( ! $root ) {
			return array();
		}
		$q['cat']        = (int) $root->term_id;
		$q['meta_key']   = '_meeha_lit_genre'; // phpcs:ignore WordPress.DB.SlowDBQuery
		$q['meta_value'] = $g['id']; // phpcs:ignore WordPress.DB.SlowDBQuery
	}
	$posts = ( new WP_Query( $q ) )->posts;
	if ( count( $posts ) < (int) $count ) {
		// Older literature posts filed only under the root/সাহিত্যলোক: classify them (meeha_lit_genre_of) and fill up.
		$have = wp_list_pluck( $posts, 'ID' );
		foreach ( meeha_lit_unassigned_posts() as $p ) {
			if ( count( $posts ) >= (int) $count ) {
				break;
			}
			if ( ! in_array( $p->ID, $have, true ) && ! in_array( (int) $p->ID, array_map( 'intval', (array) $excl ), true ) && meeha_lit_genre_of( $p ) === $g['id'] ) {
				$posts[] = $p;
			}
		}
		usort(
			$posts,
			function ( $a, $b ) {
				return strcmp( $b->post_date_gmt, $a->post_date_gmt );
			}
		);
	}
	return $posts;
}

/**
 * Latest literature posts that sit in no genre category (cached per request).
 *
 * @return WP_Post[]
 */
function meeha_lit_unassigned_posts() {
	if ( isset( $GLOBALS['meeha_lit_unassigned'] ) ) {
		return $GLOBALS['meeha_lit_unassigned'];
	}
	$root = get_category_by_slug( meeha_lit_root_slug() );
	$out  = array();
	if ( $root ) {
		$genre_cats = array_filter( wp_list_pluck( meeha_lit_genres(), 'cat' ) );
		$q          = new WP_Query(
			array(
				'post_type'           => 'post',
				'posts_per_page'      => 40,
				'cat'                 => (int) $root->term_id,
				'category__not_in'    => array_values( array_map( 'intval', $genre_cats ) ),
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
			)
		);
		$out        = $q->posts;
	}
	$GLOBALS['meeha_lit_unassigned'] = $out;
	return $out;
}

/**
 * One genre section's inner layout.
 *
 * @param array     $g     Genre.
 * @param WP_Post[] $posts Posts.
 * @return string
 */
function meeha_lit_layout_html( $g, $posts ) {
	ob_start();
	switch ( $g['layout'] ) {
		case 'verse':
			echo '<div class="m-versegrid">';
			foreach ( $posts as $p ) {
				echo meeha_lit_verse_card( $p, $g ); // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '</div>';
			break;
		case 'feature-list':
			$first = array_shift( $posts );
			echo '<div class="m-litfeature"><article class="m-litstory">';
			$img = meeha_post_img( $first, 'card-lg' );
			if ( $img ) {
				echo '<div class="m-media m-media--3x2">' . $img . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			$series = meeha_lit_field( $first, 'series' );
			$part   = meeha_lit_field( $first, 'part' );
			if ( '' !== $series || '' !== $part ) {
				echo '<span class="m-series">' . esc_html( $series ) . ( '' !== $part ? ' <b>পর্ব ' . esc_html( meeha_bn_digits( $part ) ) . '</b>' : '' ) . '</span>';
			}
			echo '<h3 class="m-litstory__title"><a href="' . esc_url( get_permalink( $first ) ) . '">' . esc_html( get_the_title( $first ) ) . '</a></h3>';
			echo '<p>' . esc_html( meeha_lit_excerpt( $first, 28 ) ) . '</p><p class="m-litlist__by">' . esc_html( meeha_lit_writer( $first ) ) . '</p></article>';
			if ( $posts ) {
				echo '<ul class="m-litlist" role="list">';
				foreach ( $posts as $p ) {
					echo meeha_lit_list_item( $p, meeha_lit_field( $p, 'story_type' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
				}
				echo '</ul>';
			}
			echo '</div>';
			break;
		case 'essays':
			echo '<ul class="m-essays" role="list">';
			foreach ( $posts as $p ) {
				echo '<li><h3><a href="' . esc_url( get_permalink( $p ) ) . '">' . esc_html( get_the_title( $p ) ) . '</a></h3><p>' . esc_html( meeha_lit_excerpt( $p, 22 ) ) . '</p><p class="m-litlist__by">' . esc_html( meeha_lit_writer( $p ) ) . '</p></li>';
			}
			echo '</ul>';
			break;
		case 'cards':
			echo '<div class="m-litcards">';
			foreach ( $posts as $p ) {
				$img  = meeha_post_img( $p, 'card' );
				$tag  = meeha_lit_field( $p, 'region' );
				$tag  = '' !== $tag ? $tag : meeha_lit_field( $p, 'era' );
				echo '<article class="m-litcard">' . ( $img ? '<div class="m-media m-media--3x2">' . $img . '</div>' : '' ); // phpcs:ignore WordPress.Security.EscapeOutput
				if ( '' !== $tag ) {
					echo '<span class="m-litlist__genre">' . esc_html( $tag ) . '</span>';
				}
				echo '<h3><a href="' . esc_url( get_permalink( $p ) ) . '">' . esc_html( get_the_title( $p ) ) . '</a></h3><p class="m-litlist__by">' . esc_html( meeha_lit_writer( $p ) ) . '</p></article>';
			}
			echo '</div>';
			break;
		case 'books':
			echo '<div class="m-books">';
			foreach ( $posts as $p ) {
				echo meeha_lit_book_review_card( $p ); // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '</div>';
			break;
		case 'list':
			echo '<ul class="m-transl" role="list">';
			foreach ( $posts as $p ) {
				$oa = meeha_lit_field( $p, 'orig_author' );
				$ol = meeha_lit_field( $p, 'orig_lang' );
				$tr = meeha_lit_field( $p, 'translator' );
				echo '<li><h3><a href="' . esc_url( get_permalink( $p ) ) . '">' . esc_html( get_the_title( $p ) ) . '</a></h3><p class="m-transl__src">';
				if ( '' !== $oa ) {
					echo 'মূল: <span>' . esc_html( $oa . ( '' !== $ol ? ' (' . $ol . ')' : '' ) ) . '</span><br>';
				}
				echo esc_html( ( '' !== $tr || '' !== $oa ? 'অনুবাদ: ' : '' ) . ( '' !== $tr ? $tr : meeha_lit_writer( $p ) ) ) . '</p></li>';
			}
			echo '</ul>';
			break;
		case 'portraits':
			echo '<div class="m-interviews">';
			foreach ( $posts as $p ) {
				$who  = meeha_lit_field( $p, 'interviewee' );
				$des  = meeha_lit_field( $p, 'designation' );
				$pull = meeha_lit_field( $p, 'pullquote' );
				$img  = meeha_post_img( $p, 'square' );
				echo '<article class="m-iv">' . ( $img ? '<div class="m-media m-media--1x1">' . $img . '</div>' : '<div class="m-media m-media--1x1" aria-hidden="true"></div>' ); // phpcs:ignore WordPress.Security.EscapeOutput
				echo '<div><blockquote><a href="' . esc_url( get_permalink( $p ) ) . '">' . esc_html( '' !== $pull ? '“' . trim( $pull, '“”"' ) . '”' : get_the_title( $p ) ) . '</a></blockquote>';
				echo '<p>' . esc_html( trim( ( '' !== $who ? $who : meeha_lit_writer( $p ) ) . ( '' !== $des ? ', ' . $des : '' ) ) ) . '</p></div></article>';
			}
			echo '</div>';
			break;
	}
	return (string) ob_get_clean();
}

/**
 * Verse card (poem excerpt: first 4 lines); non-poems fall back to an excerpt.
 *
 * @param WP_Post    $p Post.
 * @param array|null $g Genre.
 * @param string     $h Heading tag.
 * @return string
 */
function meeha_lit_verse_card( $p, $g = null, $h = 'h3' ) {
	$genre = $g ? $g : meeha_lit_genre( meeha_lit_genre_of( $p ) );
	$lines = meeha_lit_poem_lines( $p, 4 );
	$text  = $lines ? implode( "\n", $lines ) : meeha_lit_excerpt( $p, 24 );
	return '<article class="m-verse-card"><span class="m-verse-card__genre">' . esc_html( $genre ? $genre['label'] : 'সাহিত্য' ) . '</span>'
		. '<' . $h . ' class="m-verse-card__title"><a href="' . esc_url( get_permalink( $p ) ) . '">' . esc_html( get_the_title( $p ) ) . '</a></' . $h . '>'
		. '<p class="m-verse">' . esc_html( $text ) . '</p><p class="m-verse-card__poet">' . esc_html( meeha_lit_writer( $p ) ) . '</p></article>';
}

/**
 * List row (`m-litlist li`).
 *
 * @param WP_Post $p     Post.
 * @param string  $label Small label.
 * @return string
 */
function meeha_lit_list_item( $p, $label = '' ) {
	if ( '' === $label ) {
		$g     = meeha_lit_genre( meeha_lit_genre_of( $p ) );
		$label = $g ? $g['label'] : '';
	}
	$img = meeha_post_img( $p, 'thumb' );
	return '<li><div>' . ( '' !== $label ? '<span class="m-litlist__genre">' . esc_html( $label ) . '</span>' : '' )
		. '<h3 class="m-litlist__title"><a href="' . esc_url( get_permalink( $p ) ) . '">' . esc_html( get_the_title( $p ) ) . '</a></h3>'
		. '<p class="m-litlist__by">' . esc_html( meeha_lit_writer( $p ) ) . '</p></div>'
		. ( $img ? '<div class="m-media">' . $img . '</div>' : '' ) . '</li>';
}

/**
 * Book-review card (`m-bookrev`).
 *
 * @param WP_Post $p Post.
 * @return string
 */
function meeha_lit_book_review_card( $p ) {
	$dl = '';
	foreach ( array( 'publisher' => 'প্রকাশক', 'pages' => 'পৃষ্ঠা' ) as $k => $l ) {
		$v = meeha_lit_field( $p, $k );
		if ( '' !== $v ) {
			$dl .= '<dt>' . esc_html( $l ) . '</dt><dd>' . esc_html( meeha_bn_digits( $v ) ) . '</dd>';
		}
	}
	return '<article class="m-bookrev">' . meeha_lit_book_cover( $p ) . '<div><h3 class="m-bookrev__title"><a href="' . esc_url( get_permalink( $p ) ) . '">' . esc_html( get_the_title( $p ) ) . '</a></h3>'
		. ( $dl ? '<dl>' . $dl . '</dl>' : '' ) . meeha_lit_stars( (int) meeha_lit_field( $p, 'rating' ) ) . '</div></article>';
}

/**
 * URL of the page that holds [meeha_sahitya]/[meeha_literature] (cached).
 *
 * @return string
 */
function meeha_lit_landing_url() {
	$set = (string) meeha_opt( 'literature', 'home_url', '' );
	if ( '' !== $set ) {
		return $set;
	}
	$url = get_transient( 'meeha_lit_landing_url' );
	if ( false === $url ) {
		global $wpdb;
		$id  = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_type='page' AND post_status='publish' AND (post_content LIKE '%[meeha_sahitya%' OR post_content LIKE '%[meeha_literature%') ORDER BY ID ASC LIMIT 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$url = $id ? get_permalink( $id ) : '';
		if ( ! $url ) {
			$root = get_category_by_slug( meeha_lit_root_slug() );
			$url  = $root ? get_category_link( $root ) : home_url( '/' );
		}
		set_transient( 'meeha_lit_landing_url', $url, DAY_IN_SECONDS );
	}
	return (string) $url;
}

/**
 * Submit CTA target.
 *
 * @return string
 */
function meeha_lit_submit_url() {
	$u = (string) meeha_opt( 'literature', 'submit_url', '' );
	if ( '' !== $u ) {
		return $u;
	}
	global $wpdb;
	$id = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_type='page' AND post_status='publish' AND post_content LIKE '%[meeha_news_submit_form%' LIMIT 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	if ( $id ) {
		return get_permalink( $id );
	}
	foreach ( array( 'contact', 'jogajog', 'যোগাযোগ' ) as $slug ) {
		$p = get_page_by_path( $slug );
		if ( $p ) {
			return get_permalink( $p );
		}
	}
	return home_url( '/' );
}

/**
 * [meeha_sahitya] / [meeha_literature] — literature landing page.
 *
 * @param array $atts Unused.
 * @return string
 */
function meeha_sahitya_shortcode( $atts = array() ) {
	meeha_enqueue_bundle( 'literature' );
	$hide  = is_page() && get_post_meta( get_the_ID(), '_meeha_hide_title', true );
	$ckey  = 'meeha_lit_landing_' . md5( wp_json_encode( meeha_opt( 'literature' ) ) . (int) $hide . get_option( 'meeha_lit_gen', 0 ) );
	$cache = get_transient( $ckey );
	if ( is_string( $cache ) && '' !== $cache ) {
		return $cache;
	}
	$sections = array();
	foreach ( meeha_lit_genres( true ) as $gid => $g ) {
		$posts = meeha_lit_genre_posts( $g, $g['count'] );
		if ( $posts ) {
			$sections[ $gid ] = array( $g, $posts );
		}
	}
	ob_start();
	echo '<div class="m-litpage">';
	echo '<header class="m-lithero m-container">';
	if ( $hide || ! is_page() ) {
		echo '<h1 class="m-lithero__title">' . esc_html( (string) meeha_opt( 'literature', 'page_title', 'বাঙালি সাহিত্য-সংস্কৃতি' ) ) . '</h1>';
	}
	$intro = trim( (string) meeha_opt( 'literature', 'intro', '' ) );
	if ( '' !== $intro ) {
		echo '<p class="m-lithero__intro">' . esc_html( $intro ) . '</p>';
	}
	echo '<span class="m-ornament" aria-hidden="true"><i></i></span></header>';
	if ( $sections ) {
		echo '<nav class="m-genrenav" aria-label="ধরন"><ul role="list">';
		foreach ( $sections as $gid => $s ) {
			echo '<li class="m-g-' . esc_attr( $gid ) . '"' . meeha_lit_genre_style( $s[0] ) . '><a href="#' . esc_attr( $s[0]['anchor'] ) . '">' . meeha_icon( $s[0]['icon'] ) . esc_html( $s[0]['label'] ) . '</a></li>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</ul></nav>';
	}
	foreach ( $sections as $gid => $s ) {
		list( $g, $posts ) = $s;
		$hid = 'm-g-h-' . $gid;
		echo '<section class="m-section m-g-' . esc_attr( $gid ) . '" id="' . esc_attr( $g['anchor'] ) . '" aria-labelledby="' . esc_attr( $hid ) . '"' . meeha_lit_genre_style( $g ) . '><div class="m-container">'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<div class="m-sechead"><h2 class="m-sechead__title" id="' . esc_attr( $hid ) . '"><span class="m-genre__icon">' . meeha_icon( $g['icon'] ) . '</span>' . esc_html( $g['label'] ) . '</h2>'; // phpcs:ignore WordPress.Security.EscapeOutput
		if ( $g['more_url'] ) {
			echo '<a class="m-sechead__more" href="' . esc_url( $g['more_url'] ) . '">' . esc_html( $g['more'] ) . meeha_icon( 'chev-r', array( 'size' => 'sm' ) ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</div>';
		if ( '' !== trim( (string) $g['intro'] ) ) {
			echo '<p class="m-genre__intro">' . esc_html( $g['intro'] ) . '</p>';
		}
		echo meeha_lit_layout_html( $g, $posts ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</div></section>';
	}
	if ( ! $sections ) {
		echo '<p class="m-container m-lit__empty">এখনো কোনো লেখা প্রকাশিত হয়নি।</p>';
	}
	echo '<section class="m-section" aria-labelledby="m-lit-submit-h"><div class="m-container"><div class="m-submit">';
	echo '<h2 id="m-lit-submit-h">' . esc_html( (string) meeha_opt( 'literature', 'submit_title', 'আপনার লেখা পাঠান' ) ) . '</h2>';
	echo '<p>' . esc_html( (string) meeha_opt( 'literature', 'submit_text', '' ) ) . '</p>';
	echo '<a class="m-btn" href="' . esc_url( meeha_lit_submit_url() ) . '">' . esc_html( (string) meeha_opt( 'literature', 'submit_label', 'লেখা পাঠানোর নিয়ম' ) ) . '</a>';
	echo '</div></div></section></div>';
	$html = (string) ob_get_clean();
	set_transient( $ckey, $html, 10 * MINUTE_IN_SECONDS );
	return $html;
}
add_shortcode( 'meeha_sahitya', 'meeha_sahitya_shortcode' );
add_shortcode( 'meeha_literature', 'meeha_sahitya_shortcode' );

/* Bump the landing cache generation whenever a post or the settings change. */
add_action( 'save_post_post', 'meeha_lit_bump_cache' );
add_action( 'deleted_post', 'meeha_lit_bump_cache' );
add_action(
	'meeha_settings_saved',
	function ( $tab ) {
		if ( 'literature' === $tab ) {
			meeha_lit_bump_cache();
		}
	}
);
/**
 * Invalidate literature caches.
 */
function meeha_lit_bump_cache() {
	update_option( 'meeha_lit_gen', time(), false );
	delete_transient( 'meeha_lit_landing_url' );
}

/* Landing page: literature bundle before <head> output + paper body class. */
add_action(
	'wp',
	function () {
		if ( is_admin() || ! is_page() ) {
			return;
		}
		$p = get_queried_object();
		if ( $p instanceof WP_Post && ( has_shortcode( $p->post_content, 'meeha_sahitya' ) || has_shortcode( $p->post_content, 'meeha_literature' ) ) ) {
			meeha_enqueue_bundle( 'literature' );
			$GLOBALS['meeha_lit_page'] = true;
		}
	}
);
add_filter(
	'body_class',
	function ( $c ) {
		if ( ! empty( $GLOBALS['meeha_lit_page'] ) ) {
			$c[] = 'm-lit';
			$c[] = 'm-lit-landing';
		}
		return $c;
	}
);

/**
 * Homepage teaser (S11) for C: verse card (newest poem; else the first item) + list.
 *
 * @param array $args title, count, cat, url, echo (true), heading_id.
 * @return string HTML ('' when fewer than 2 items).
 */
function meeha_render_home_literature( $args = array() ) {
	$args = wp_parse_args(
		(array) $args,
		array(
			'title'      => (string) meeha_opt( 'literature', 'home_title', 'বাঙালি সাহিত্য-সংস্কৃতি' ),
			'count'      => (int) meeha_opt( 'literature', 'home_count', 3 ),
			'cat'        => (int) meeha_opt( 'literature', 'home_cat', 0 ),
			'url'        => '',
			'echo'       => true,
			'heading_id' => 'm-lit-h',
		)
	);
	$cat = (int) $args['cat'];
	if ( ! $cat ) {
		$root = get_category_by_slug( meeha_lit_root_slug() );
		$cat  = $root ? (int) $root->term_id : 0;
	}
	if ( ! $cat ) {
		return '';
	}
	$q     = new WP_Query(
		array(
			'post_type'           => 'post',
			'posts_per_page'      => 12,
			'cat'                 => $cat,
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
		)
	);
	$posts = $q->posts;
	if ( count( $posts ) < 2 ) {
		return '';
	}
	$poem = null;
	foreach ( $posts as $p ) {
		if ( 'poem' === meeha_lit_genre_of( $p ) ) {
			$poem = $p;
			break;
		}
	}
	if ( ! $poem ) {
		$poem = $posts[0];
	}
	$rest = array_slice(
		array_values(
			array_filter(
				$posts,
				function ( $p ) use ( $poem ) {
					return (int) $p->ID !== (int) $poem->ID;
				}
			)
		),
		0,
		max( 1, (int) $args['count'] )
	);
	$url   = $args['url'] ? $args['url'] : meeha_lit_landing_url();
	$chips = array();
	foreach ( meeha_lit_genres( true ) as $g ) {
		if ( count( $chips ) >= 4 ) {
			break;
		}
		if ( $g['cat'] && ( $t = get_term( $g['cat'], 'category' ) ) && ! is_wp_error( $t ) && $t->count > 0 ) { // phpcs:ignore
			$chips[] = array( $g['label'], $url . '#' . $g['anchor'] );
		}
	}
	ob_start();
	echo '<section class="m-section m-litteaser" aria-labelledby="' . esc_attr( $args['heading_id'] ) . '" data-lit-font="' . esc_url( meeha_literary_font_url() ) . '"><div class="m-container">';
	meeha_sechead(
		$args['title'],
		array(
			'url'        => $url,
			'more_label' => 'সাহিত্য পাতা',
			'chips'      => $chips,
			'id'         => $args['heading_id'],
		)
	);
	echo '<div class="m-litteaser__grid">' . meeha_lit_verse_card( $poem ) . '<ul class="m-litlist" role="list">'; // phpcs:ignore WordPress.Security.EscapeOutput
	foreach ( $rest as $p ) {
		echo meeha_lit_list_item( $p ); // phpcs:ignore WordPress.Security.EscapeOutput
	}
	echo '</ul></div></div></section>';
	$html = (string) ob_get_clean();
	if ( $args['echo'] ) {
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	return $html;
}

/**
 * Recitation player: YouTube facade (iframe injected on click) or a styled native <audio>.
 *
 * @param WP_Post $post Post.
 * @return string
 */
function meeha_lit_recite_html( $post ) {
	$url = meeha_lit_field( $post, 'recite_url' );
	if ( '' === $url ) {
		return '';
	}
	$who   = meeha_lit_field( $post, 'reciter' );
	$title = 'আবৃত্তি: ' . ( '' !== $who ? $who : 'কবির কণ্ঠে' );
	if ( preg_match( '~(?:youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/)|youtu\.be/)([A-Za-z0-9_-]{11})~', $url, $m ) ) {
		$vid = $m[1];
		return '<div class="m-recite m-recite--yt" role="group" aria-label="আবৃত্তি">'
			. '<div class="m-recite__video m-media" data-yt="' . esc_attr( $vid ) . '">'
			. '<img src="' . esc_url( 'https://i.ytimg.com/vi/' . $vid . '/hqdefault.jpg' ) . '" width="480" height="360" alt="" loading="lazy" decoding="async">'
			. '<button class="m-recite__play" type="button" aria-label="' . esc_attr( $title . ' — ভিডিও চালান' ) . '">' . meeha_icon( 'play', array( 'size' => 'lg' ) ) . '</button>'
			. '<a class="m-recite__fallback" href="' . esc_url( 'https://www.youtube.com/watch?v=' . $vid ) . '" target="_blank" rel="noopener">ইউটিউবে দেখুন</a></div>'
			. '<span class="m-recite__title">' . esc_html( $title ) . '</span></div>';
	}
	return '<div class="m-recite m-recite--audio" role="group" aria-label="আবৃত্তি" data-recite>'
		. '<button class="m-recite__play" type="button" aria-label="আবৃত্তি শুনুন" aria-pressed="false">' . meeha_icon( 'play', array( 'size' => 'lg' ) ) . '</button>'
		. '<span class="m-recite__title">' . esc_html( $title ) . '</span>'
		. '<div class="m-recite__bar"><span class="m-recite__track"><i></i></span><span class="m-recite__time">০:০০</span></div>'
		. '<audio class="m-recite__audio" controls preload="none" src="' . esc_url( $url ) . '"></audio></div>';
}
