<?php
/**
 * Template tags shared by every template: story cards, section heads, breadcrumb, share bar, pager.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

/**
 * Normalised card arguments.
 *
 * @param array $args Args.
 * @return array
 */
function meeha_card_args( $args ) {
	$args = wp_parse_args(
		(array) $args,
		array(
			'variant'       => 'sm',
			'img'           => '',
			'show_image'    => true,
			'show_kicker'   => true,
			'show_dek'      => false,
			'show_meta'     => true,
			'heading_level' => 3,
			'eager'         => false,
			'class'         => '',
			'flag'          => '',
			'ratio'         => '',
			'viewfinder'    => null,
			'dek_words'     => 30,
		)
	);
	if ( ! $args['img'] ) {
		$map         = array(
			'lead'    => 'lead',
			'big'     => 'card-lg',
			'feature' => 'card-lg',
			'side-lg' => 'card-lg',
			'sm'      => 'card',
			'row'     => 'thumb',
			'rowm'    => 'card',
			'side'    => 'thumb',
		);
		$first       = strtok( (string) $args['variant'], ' ' );
		$args['img'] = isset( $map[ $first ] ) ? $map[ $first ] : 'card';
	}
	if ( false !== strpos( ' ' . $args['variant'] . ' ', ' text ' ) ) {
		$args['show_image'] = false;
	}
	$args['heading_level'] = max( 2, min( 6, (int) $args['heading_level'] ) );
	return $args;
}

/**
 * Print a story card (markup = prototype `m-story` family).
 *
 * @param WP_Post|int $post Post.
 * @param array       $args variant lead|big|sm|row|rowm|side|side-lg|text|feature (space-separated modifiers allowed),
 *                          img role, show_kicker, show_dek, show_meta (true|'author'|false), heading_level, eager, class,
 *                          flag (label over the image), ratio (4x3|1x1|3x2|9x16), viewfinder (bool|null = auto).
 */
function meeha_card( $post, $args = array() ) {
	$post = get_post( $post );
	if ( ! $post ) {
		return;
	}
	$args         = meeha_card_args( $args );
	$args['post'] = $post;
	get_template_part( 'template-parts/card', null, $args );
}

/**
 * Same as meeha_card() but returns the HTML.
 *
 * @param WP_Post|int $post Post.
 * @param array       $args Args.
 * @return string
 */
function meeha_get_card( $post, $args = array() ) {
	ob_start();
	meeha_card( $post, $args );
	return (string) ob_get_clean();
}

/**
 * Section head with the viewfinder corner.
 *
 * @param string $title Title.
 * @param array  $args  url, more_label ('সব খবর'), more (bool, default true when url), sub (note text),
 *                      chips [[label,url]…], tag (h2), modifier (e.g. 'sm' or 'm-sechead--sm'), id, after (raw HTML
 *                      appended inside, e.g. rail nav), class.
 */
function meeha_sechead( $title, $args = array() ) {
	$args = wp_parse_args(
		(array) $args,
		array(
			'url'        => '',
			'more_label' => 'সব খবর',
			'more'       => null,
			'sub'        => '',
			'chips'      => array(),
			'tag'        => 'h2',
			'modifier'   => '',
			'id'         => '',
			'after'      => '',
			'class'      => '',
		)
	);
	$tag = in_array( $args['tag'], array( 'h1', 'h2', 'h3', 'h4', 'p', 'div' ), true ) ? $args['tag'] : 'h2';
	$mod = '';
	foreach ( preg_split( '/\s+/', trim( (string) $args['modifier'] ) ) as $m ) {
		if ( '' !== $m ) {
			$mod .= ' ' . ( 0 === strpos( $m, 'm-' ) ? sanitize_html_class( $m ) : 'm-sechead--' . sanitize_html_class( $m ) );
		}
	}
	echo '<div class="m-sechead' . esc_attr( $mod . ( $args['class'] ? ' ' . $args['class'] : '' ) ) . '">';
	printf( '<%1$s class="m-sechead__title"%2$s>', $tag, $args['id'] ? ' id="' . esc_attr( $args['id'] ) . '"' : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	if ( $args['url'] ) {
		echo '<a href="' . esc_url( $args['url'] ) . '">' . esc_html( $title ) . '</a>';
	} else {
		echo esc_html( $title );
	}
	echo '</' . $tag . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	if ( $args['chips'] ) {
		echo '<ul class="m-sechead__sub" role="list">';
		foreach ( (array) $args['chips'] as $chip ) {
			if ( ! empty( $chip[0] ) ) {
				printf( '<li><a href="%s">%s</a></li>', esc_url( isset( $chip[1] ) ? $chip[1] : '#' ), esc_html( $chip[0] ) );
			}
		}
		echo '</ul>';
	}
	if ( $args['sub'] ) {
		echo '<p class="m-sechead__note">' . esc_html( $args['sub'] ) . '</p>';
	}
	$more = null === $args['more'] ? (bool) $args['url'] : (bool) $args['more'];
	if ( $more && $args['url'] ) {
		echo '<a class="m-sechead__more" href="' . esc_url( $args['url'] ) . '">' . esc_html( $args['more_label'] ) . meeha_icon( 'chev-r', array( 'size' => 'sm' ) ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
	if ( $args['after'] ) {
		echo $args['after']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- caller-built markup.
	}
	echo '</div>';
}

/**
 * Breadcrumb trail items for the current request.
 *
 * @return array [[label, url|''], …]
 */
function meeha_breadcrumb_items() {
	$items = array( array( 'প্রথম পাতা', home_url( '/' ) ) );
	$cat_chain = function ( $term ) use ( &$items ) {
		$anc = array_reverse( get_ancestors( $term->term_id, $term->taxonomy, 'taxonomy' ) );
		foreach ( $anc as $a ) {
			$t = get_term( $a, $term->taxonomy );
			if ( $t && ! is_wp_error( $t ) ) {
				$items[] = array( $t->name, get_term_link( $t ) );
			}
		}
	};
	if ( is_singular( 'post' ) ) {
		$cat = meeha_primary_category( get_queried_object() );
		if ( $cat ) {
			$cat_chain( $cat );
			$items[] = array( $cat->name, get_category_link( $cat ) );
		}
	} elseif ( is_page() ) {
		$page = get_queried_object();
		foreach ( array_reverse( get_post_ancestors( $page ) ) as $pid ) {
			$items[] = array( get_the_title( $pid ), get_permalink( $pid ) );
		}
		$items[] = array( get_the_title( $page ), '' );
	} elseif ( is_singular() ) {
		$pt  = get_post_type_object( get_post_type() );
		$url = $pt ? get_post_type_archive_link( $pt->name ) : '';
		if ( $pt && $url ) {
			$items[] = array( $pt->labels->name, $url );
		}
	} elseif ( is_category() || is_tag() || is_tax() ) {
		$term = get_queried_object();
		if ( is_tag() ) {
			$items[] = array( 'ট্যাগ', '' );
		} elseif ( is_tax() && $term ) {
			$tax = get_taxonomy( $term->taxonomy );
			$pt  = $tax && ! empty( $tax->object_type ) ? $tax->object_type[0] : '';
			$url = $pt ? get_post_type_archive_link( $pt ) : '';
			if ( $url ) {
				$pto     = get_post_type_object( $pt );
				$items[] = array( $pto ? $pto->labels->name : $tax->labels->name, $url );
			}
		}
		if ( $term && ! is_wp_error( $term ) ) {
			if ( is_taxonomy_hierarchical( $term->taxonomy ) ) {
				$cat_chain( $term );
			}
			$items[] = array( $term->name, '' );
		}
	} elseif ( is_post_type_archive() ) {
		$items[] = array( post_type_archive_title( '', false ), '' );
	} elseif ( is_author() ) {
		$items[] = array( 'লেখক', '' );
		$items[] = array( get_the_author_meta( 'display_name', (int) get_query_var( 'author' ) ), '' );
	} elseif ( is_search() ) {
		$items[] = array( 'খোঁজ', '' );
	} elseif ( is_404() ) {
		$items[] = array( 'পাতা পাওয়া যায়নি', '' );
	} elseif ( is_date() ) {
		$items[] = array( wp_strip_all_tags( get_the_archive_title() ), '' );
	}
	return apply_filters( 'meeha_breadcrumb_items', $items );
}

/**
 * Whether Rank Math already prints BreadcrumbList schema.
 *
 * @return bool
 */
function meeha_rank_math_breadcrumbs() {
	if ( ! class_exists( 'RankMath' ) ) {
		return false;
	}
	if ( class_exists( '\RankMath\Helper' ) && method_exists( '\RankMath\Helper', 'is_breadcrumbs_enabled' ) ) {
		return (bool) \RankMath\Helper::is_breadcrumbs_enabled();
	}
	$o = get_option( 'rank-math-options-general', array() );
	return is_array( $o ) && ! empty( $o['breadcrumbs'] ) && 'off' !== $o['breadcrumbs'];
}

/**
 * Print the breadcrumb (+ BreadcrumbList JSON-LD unless Rank Math breadcrumbs are active).
 *
 * @param array $args class (extra class on the <ol>).
 */
function meeha_breadcrumb( $args = array() ) {
	if ( ! meeha_opt( 'seo', 'breadcrumb', 1 ) ) {
		return;
	}
	$items = meeha_breadcrumb_items();
	if ( count( $items ) < 2 ) {
		return;
	}
	$class = 'm-crumbs' . ( ! empty( $args['class'] ) ? ' ' . $args['class'] : '' );
	echo '<nav aria-label="অবস্থান"><ol class="' . esc_attr( $class ) . '" role="list">';
	foreach ( $items as $it ) {
		if ( $it[1] ) {
			echo '<li><a href="' . esc_url( $it[1] ) . '">' . esc_html( $it[0] ) . '</a></li>';
		} else {
			echo '<li aria-current="page">' . esc_html( $it[0] ) . '</li>';
		}
	}
	echo '</ol></nav>';

	if ( apply_filters( 'meeha_breadcrumb_jsonld', ! meeha_rank_math_breadcrumbs() ) ) {
		$list = array();
		$pos  = 1;
		foreach ( $items as $it ) {
			$el = array(
				'@type'    => 'ListItem',
				'position' => $pos++,
				'name'     => wp_strip_all_tags( $it[0] ),
			);
			if ( $it[1] ) {
				$el['item'] = $it[1];
			}
			$list[] = $el;
		}
		echo '<script type="application/ld+json">' . wp_json_encode(
			array(
				'@context'        => 'https://schema.org',
				'@type'           => 'BreadcrumbList',
				'itemListElement' => $list,
			),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		) . '</script>';
	}
}

/**
 * Share bar. Each network link carries data-share-net + data-post-id for C's metrics.js.
 *
 * @param WP_Post|int|string $post Post (a legacy string position argument means "current post").
 * @param array              $args networks (fb,wa,x,tg,copy,native), label (bool|string), class, compact (icons only).
 */
function meeha_share_buttons( $post = null, $args = array() ) {
	if ( is_string( $post ) && ! is_numeric( $post ) ) {
		$post = null; // legacy meeha_share_buttons( 'top' ).
	}
	$post = get_post( $post );
	if ( ! $post ) {
		return;
	}
	$args = wp_parse_args(
		(array) $args,
		array(
			'networks' => apply_filters( 'meeha_share_networks', array( 'fb', 'wa', 'x', 'tg', 'copy', 'native' ) ),
			'label'    => false,
			'class'    => '',
		)
	);
	$url   = get_permalink( $post );
	$title = wp_strip_all_tags( get_the_title( $post ) );
	$nets  = array(
		'fb' => array( 'facebook', 'ফেসবুকে শেয়ার', 'https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode( $url ) ),
		'wa' => array( 'whatsapp', 'হোয়াটসঅ্যাপে শেয়ার', 'https://wa.me/?text=' . rawurlencode( $title . ' ' . $url ) ),
		'x'  => array( 'x', 'এক্সে শেয়ার', 'https://twitter.com/intent/tweet?url=' . rawurlencode( $url ) . '&text=' . rawurlencode( $title ) ),
		'tg' => array( 'telegram', 'টেলিগ্রামে শেয়ার', 'https://t.me/share/url?url=' . rawurlencode( $url ) . '&text=' . rawurlencode( $title ) ),
	);
	$pid = (int) $post->ID;
	echo '<div class="m-share' . ( $args['class'] ? ' ' . esc_attr( $args['class'] ) : '' ) . '" aria-label="শেয়ার করুন" data-post-id="' . esc_attr( $pid ) . '">';
	if ( $args['label'] ) {
		echo '<span class="m-share__label">' . esc_html( is_string( $args['label'] ) ? $args['label'] : 'শেয়ার করুন' ) . '</span>';
	}
	foreach ( (array) $args['networks'] as $n ) {
		if ( isset( $nets[ $n ] ) ) {
			printf(
				'<a href="%1$s" target="_blank" rel="noopener nofollow" aria-label="%2$s" data-share-net="%3$s" data-post-id="%4$d">%5$s</a>',
				esc_url( $nets[ $n ][2] ),
				esc_attr( $nets[ $n ][1] ),
				esc_attr( $nets[ $n ][0] ),
				$pid,
				meeha_icon( $nets[ $n ][0] ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			);
		} elseif ( 'native' === $n ) {
			printf( '<button type="button" data-share data-share-net="native" data-post-id="%d" aria-label="অন্য অ্যাপে শেয়ার" hidden>%s</button>', $pid, meeha_icon( 'share' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} elseif ( 'copy' === $n ) {
			printf( '<button class="m-share__copy" type="button" data-copy="%1$s" data-share-net="copy" data-post-id="%2$d">%3$s<span>লিংক কপি</span></button>', esc_url( $url ), $pid, meeha_icon( 'link' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}
	echo '</div>';
}

/**
 * Bengali numeric pager (plain links — works without JS and for crawlers).
 *
 * @param WP_Query|null $q    Query (default: main query).
 * @param array         $args label (aria-label).
 */
function meeha_pager( $q = null, $args = array() ) {
	if ( ! $q instanceof WP_Query ) {
		$q = $GLOBALS['wp_query'];
	}
	$total = (int) $q->max_num_pages;
	if ( $total < 2 ) {
		return;
	}
	$cur = max( 1, (int) ( $q->get( 'paged' ) ? $q->get( 'paged' ) : get_query_var( 'paged' ) ) );
	$cur = min( $cur, $total );
	$set = array_unique( array_filter( array( 1, $cur - 1, $cur, $cur + 1, $total ), function ( $n ) use ( $total ) {
		return $n >= 1 && $n <= $total;
	} ) );
	sort( $set );
	echo '<nav class="m-pager" aria-label="' . esc_attr( isset( $args['label'] ) ? $args['label'] : 'পাতা' ) . '">';
	if ( $cur > 1 ) {
		printf( '<a href="%s" rel="prev" aria-label="আগের পাতা">%s</a>', esc_url( get_pagenum_link( $cur - 1 ) ), meeha_icon( 'chev-l' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
	$prev = 0;
	foreach ( $set as $n ) {
		if ( $prev && $n - $prev > 1 ) {
			echo '<span class="m-pager__gap" aria-hidden="true">…</span>';
		}
		if ( $n === $cur ) {
			echo '<span aria-current="page">' . esc_html( meeha_bn_digits( $n ) ) . '</span>';
		} else {
			printf( '<a href="%s">%s</a>', esc_url( get_pagenum_link( $n ) ), esc_html( meeha_bn_digits( $n ) ) );
		}
		$prev = $n;
	}
	if ( $cur < $total ) {
		printf( '<a href="%s" rel="next" aria-label="পরের পাতা">%s</a>', esc_url( get_pagenum_link( $cur + 1 ) ), meeha_icon( 'chev-r' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
	echo '</nav>';
}

/**
 * Latest posts as a `m-latest` list (used by 404 and as a generic fallback).
 *
 * @param int   $count Count.
 * @param array $args  exclude ids.
 */
function meeha_latest_list( $count = 6, $args = array() ) {
	$q = new WP_Query(
		array(
			'post_type'           => 'post',
			'post_status'         => 'publish',
			'has_password'        => false,
			'posts_per_page'      => (int) $count,
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
			'post__not_in'        => isset( $args['exclude'] ) ? (array) $args['exclude'] : array(),
		)
	);
	if ( ! $q->have_posts() ) {
		return;
	}
	echo '<ol class="m-latest" role="list">';
	foreach ( $q->posts as $p ) {
		printf(
			'<li><time datetime="%s">%s</time><a href="%s">%s</a></li>',
			esc_attr( get_post_time( 'c', true, $p ) ),
			esc_html( meeha_post_clock( $p ) ),
			esc_url( get_permalink( $p ) ),
			esc_html( get_the_title( $p ) )
		);
	}
	echo '</ol>';
}
