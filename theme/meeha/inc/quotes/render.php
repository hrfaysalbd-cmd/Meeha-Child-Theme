<?php
/**
 * Quote cards, homepage rail (C's S12), [meeha_photocards] / [meeha_photocard] shortcodes, legacy browse AJAX.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

/**
 * Quote card (`m-quote` with the type's frame).
 *
 * @param WP_Post|int $q    Quote.
 * @param array       $args heading ('p'|'h1'|'h2'|'h3' — the quote text element inside the blockquote),
 *                          link (stretched link, default true), photo (show speaker photo, default true),
 *                          brand (logo mark, default false), class.
 * @return string
 */
function meeha_render_quote_card( $q, $args = array() ) {
	$q = get_post( $q );
	if ( ! $q ) {
		return '';
	}
	$args = wp_parse_args(
		(array) $args,
		array(
			'heading' => 'p',
			'link'    => true,
			'photo'   => true,
			'brand'   => false,
			'class'   => '',
		)
	);
	$d    = meeha_quote_data( $q );
	$cls  = 'm-quote m-quote--' . $d['frame'] . ( $d['len_class'] ? ' m-quote--' . $d['len_class'] : '' ) . ( $args['class'] ? ' ' . $args['class'] : '' );
	$tag  = in_array( $args['heading'], array( 'p', 'h1', 'h2', 'h3' ), true ) ? $args['heading'] : 'p';
	$role = $d['role'];
	if ( '' === $role && '' !== $d['source'] ) {
		$role = $d['source'] . ( '' !== $d['source_year'] ? ', ' . $d['source_year'] : '' );
	}
	$year = 'history' === $d['frame'] ? ( '' !== $d['year'] ? $d['year'] : $d['source_year'] ) : '';
	$out  = '<article class="' . esc_attr( $cls ) . '">';
	$out .= '<div class="m-quote__frame" aria-hidden="true"' . ( '' !== $year ? ' data-year="' . esc_attr( meeha_bn_digits( $year ) ) . '"' : '' ) . '></div>';
	$out .= '<span class="m-quote__type">' . meeha_icon( $d['icon'] ) . esc_html( $d['type_label'] ) . '</span>';
	$out .= '<blockquote class="m-quote__text"><' . $tag . ( 'p' !== $tag ? ' class="m-quote__h1"' : '' ) . '>' . esc_html( $d['text'] ) . '</' . $tag . '></blockquote>';
	$out .= '<footer class="m-quote__by">';
	if ( $args['photo'] && $d['photo'] ) {
		$out .= meeha_img( $d['photo'], 'square', array( 'alt' => '', 'sizes' => '44px' ) );
	}
	$out .= '<span class="m-quote__name">' . esc_html( '' !== $d['speaker'] ? $d['speaker'] : 'অজ্ঞাত' ) . '</span>';
	if ( '' !== $role ) {
		$out .= '<span class="m-quote__role">' . esc_html( $role ) . '</span>';
	}
	$out .= '</footer>';
	if ( $args['brand'] ) {
		$out .= meeha_icon( 'm-logo', array( 'class' => 'm-quote__brand' ) );
	}
	if ( $args['link'] ) {
		$out .= '<a class="m-quote__link" href="' . esc_url( $d['url'] ) . '"><span class="m-vh">উক্তিটি দেখুন: ' . esc_html( mb_substr( $d['text'], 0, 60 ) ) . '</span></a>';
	}
	return $out . '</article>';
}

/**
 * Quote-type chips (term IDs in links — Bengali slugs were the old tab bug).
 *
 * @param int    $current Current term id (0 = all).
 * @param string $base    Base URL for "সব"; per-type links go to the term archive unless $query_arg is set.
 * @param string $query_arg When set (shortcode), chips link to $base?{arg}={term_id}.
 * @return string
 */
function meeha_quote_type_chips( $current = 0, $base = '', $query_arg = '' ) {
	$terms = get_terms(
		array(
			'taxonomy'   => 'meeha_quote_type',
			'hide_empty' => true,
			'orderby'    => 'count',
			'order'      => 'DESC',
		)
	);
	$base  = $base ? $base : get_post_type_archive_link( 'meeha_photocard' );
	$out   = '<ul class="m-chiprow m-qchips" role="list" aria-label="উক্তির ধরন">';
	$out  .= '<li><a class="m-chip" href="' . esc_url( $query_arg ? remove_query_arg( array( $query_arg, 'qpage' ), $base ) : $base ) . '"' . ( ! $current ? ' aria-current="page"' : '' ) . '>সব</a></li>';
	foreach ( is_wp_error( $terms ) ? array() : $terms as $t ) {
		$url  = $query_arg ? add_query_arg( $query_arg, (int) $t->term_id, remove_query_arg( 'qpage', $base ) ) : get_term_link( $t );
		$out .= '<li><a class="m-chip m-qchip m-qchip--' . esc_attr( meeha_quote_frame_of_term( $t ) ) . '" href="' . esc_url( $url ) . '"' . ( (int) $current === (int) $t->term_id ? ' aria-current="page"' : '' ) . '>' . esc_html( $t->name ) . '</a></li>';
	}
	return $out . '</ul>';
}

/**
 * Homepage উক্তি rail (S12) for C: newest N, max K per type. Returns '' with fewer than 3.
 *
 * @param array $args count, max_per_type, title, echo (true), heading_id.
 * @return string
 */
function meeha_render_home_quotes( $args = array() ) {
	$args = wp_parse_args(
		(array) $args,
		array(
			'count'        => (int) meeha_opt( 'quotes', 'home_count', 8 ),
			'max_per_type' => (int) meeha_opt( 'quotes', 'home_max_per_type', 2 ),
			'title'        => 'উক্তি',
			'echo'         => true,
			'heading_id'   => 'm-q-h',
		)
	);
	$q    = new WP_Query(
		array(
			'post_type'           => 'meeha_photocard',
			'posts_per_page'      => max( 12, (int) $args['count'] * 4 ),
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
		)
	);
	$pick = array();
	$per  = array();
	foreach ( $q->posts as $p ) {
		$terms = get_the_terms( $p, 'meeha_quote_type' );
		$k     = ( $terms && ! is_wp_error( $terms ) ) ? (int) $terms[0]->term_id : 0;
		if ( isset( $per[ $k ] ) && $per[ $k ] >= (int) $args['max_per_type'] ) {
			continue;
		}
		$per[ $k ] = isset( $per[ $k ] ) ? $per[ $k ] + 1 : 1;
		$pick[]    = $p;
		if ( count( $pick ) >= (int) $args['count'] ) {
			break;
		}
	}
	if ( count( $pick ) < 3 ) {
		return '';
	}
	meeha_enqueue_bundle( 'quote-cards' );
	$url  = get_post_type_archive_link( 'meeha_photocard' );
	$nav  = '<div class="m-railnav"><button type="button" aria-label="আগের উক্তি">' . meeha_icon( 'chev-l' ) . '</button><button type="button" aria-label="পরের উক্তি">' . meeha_icon( 'chev-r' ) . '</button></div>';
	$html = '<section class="m-section m-qhome" aria-labelledby="' . esc_attr( $args['heading_id'] ) . '" data-rail><div class="m-container">';
	ob_start();
	meeha_sechead(
		$args['title'],
		array(
			'url'        => $url,
			'more_label' => 'সব উক্তি',
			'id'         => $args['heading_id'],
			'after'      => $nav,
		)
	);
	$html .= ob_get_clean();
	$html .= '<ul class="m-rail m-quote-rail" role="list">';
	foreach ( $pick as $p ) {
		$html .= '<li>' . meeha_render_quote_card( $p ) . '</li>';
	}
	$html .= '</ul></div></section>';
	if ( $args['echo'] ) {
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	return $html;
}

/**
 * [meeha_photocards count=12] (browse: search + type chips + grid + pager) and [meeha_photocard id=N] (one card).
 * Server-rendered with GET params (?qt=term_id, ?qs=search, ?qpage=n) — works without JS and is cacheable.
 *
 * @param array $atts id, count.
 * @return string
 */
function meeha_photocards_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'id'    => 0,
			'count' => 12,
		),
		$atts
	);
	meeha_enqueue_bundle( 'quote-cards' );
	if ( (int) $atts['id'] ) {
		$p = get_post( (int) $atts['id'] );
		return ( $p && 'meeha_photocard' === $p->post_type && 'publish' === $p->post_status ) ? '<div class="m-qone">' . meeha_render_quote_card( $p ) . '</div>' : '';
	}
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- public read-only filters.
	$type = isset( $_GET['qt'] ) ? absint( $_GET['qt'] ) : 0;
	$s    = isset( $_GET['qs'] ) ? sanitize_text_field( wp_unslash( $_GET['qs'] ) ) : '';
	$page = isset( $_GET['qpage'] ) ? max( 1, absint( $_GET['qpage'] ) ) : 1;
	// phpcs:enable
	return meeha_quote_browse_html( $type, $s, $page, max( 1, min( 48, (int) $atts['count'] ) ), get_permalink() );
}
add_shortcode( 'meeha_photocards', 'meeha_photocards_shortcode' );
add_shortcode( 'meeha_photocard', 'meeha_photocards_shortcode' );

/**
 * Browse block markup.
 *
 * @param int    $type  Term id.
 * @param string $s     Search.
 * @param int    $page  Page.
 * @param int    $count Per page.
 * @param string $base  Base URL.
 * @return string
 */
function meeha_quote_browse_html( $type, $s, $page, $count, $base ) {
	$args = array(
		'post_type'      => 'meeha_photocard',
		'post_status'    => 'publish',
		'posts_per_page' => $count,
		'paged'          => $page,
		's'              => $s,
	);
	if ( $type && term_exists( $type, 'meeha_quote_type' ) ) {
		$args['tax_query'] = array( array( 'taxonomy' => 'meeha_quote_type', 'terms' => array( $type ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
	}
	$q    = new WP_Query( $args );
	$out  = '<div class="m-qbrowse">';
	$out .= '<form class="m-qbrowse__search" role="search" method="get" action="' . esc_url( $base ) . '">';
	if ( $type ) {
		$out .= '<input type="hidden" name="qt" value="' . (int) $type . '">';
	}
	$out .= '<label class="m-vh" for="m-qs">উক্তি খুঁজুন</label><input class="m-input" id="m-qs" type="search" name="qs" value="' . esc_attr( $s ) . '" placeholder="উক্তি বা বক্তার নাম লিখুন…"><button class="m-btn" type="submit">' . meeha_icon( 'search', array( 'size' => 'sm' ) ) . '<span>খুঁজুন</span></button></form>';
	$out .= meeha_quote_type_chips( $type, $base, 'qt' );
	if ( $q->have_posts() ) {
		$out .= '<ul class="m-qgrid" role="list">';
		foreach ( $q->posts as $p ) {
			$out .= '<li>' . meeha_render_quote_card( $p ) . '</li>';
		}
		$out .= '</ul>';
		if ( $q->max_num_pages > 1 ) {
			$out .= '<nav class="m-pager" aria-label="উক্তির পাতা">';
			for ( $i = 1; $i <= min( (int) $q->max_num_pages, 20 ); $i++ ) {
				$u    = add_query_arg( array_filter( array( 'qt' => $type, 'qs' => $s, 'qpage' => $i > 1 ? $i : null ) ), $base );
				$out .= $i === $page ? '<span aria-current="page">' . esc_html( meeha_bn_digits( $i ) ) . '</span>' : '<a href="' . esc_url( $u ) . '">' . esc_html( meeha_bn_digits( $i ) ) . '</a>';
			}
			$out .= '</nav>';
		}
	} else {
		$out .= '<p class="m-qbrowse__empty">' . esc_html( '' !== $s ? '‘' . $s . '’ লিখে কোনো উক্তি পাওয়া যায়নি।' : 'এই ধরনে এখনো কোনো উক্তি নেই।' ) . '</p>';
	}
	return $out . '</div>';
}

/* Legacy AJAX (old cached pages): bounded count, term id or legacy slug, returns HTML. */
add_action( 'wp_ajax_meeha_pc_browse', 'meeha_ajax_pc_browse' );
add_action( 'wp_ajax_nopriv_meeha_pc_browse', 'meeha_ajax_pc_browse' );
add_action( 'wp_ajax_meeha_quote_archive_fetch', 'meeha_ajax_pc_browse' );
add_action( 'wp_ajax_nopriv_meeha_quote_archive_fetch', 'meeha_ajax_pc_browse' );
/**
 * Legacy browse wrapper.
 */
function meeha_ajax_pc_browse() {
	// phpcs:disable WordPress.Security.NonceVerification.Missing
	$tab  = isset( $_POST['tab'] ) ? sanitize_text_field( wp_unslash( $_POST['tab'] ) ) : ( isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '' );
	$s    = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : ( isset( $_POST['s'] ) ? sanitize_text_field( wp_unslash( $_POST['s'] ) ) : '' );
	$cnt  = isset( $_POST['count'] ) ? max( 1, min( 24, absint( $_POST['count'] ) ) ) : 12;
	// phpcs:enable
	$type = 0;
	if ( is_numeric( $tab ) ) {
		$type = absint( $tab );
	} elseif ( '' !== $tab && ! in_array( $tab, array( 'all', 'popular' ), true ) ) {
		$t    = get_term_by( 'slug', sanitize_title( rawurldecode( $tab ) ), 'meeha_quote_type' );
		$type = $t ? (int) $t->term_id : 0;
	}
	$q    = new WP_Query(
		array(
			'post_type'      => 'meeha_photocard',
			'post_status'    => 'publish',
			'posts_per_page' => $cnt,
			's'              => $s,
			'tax_query'      => $type ? array( array( 'taxonomy' => 'meeha_quote_type', 'terms' => array( $type ) ) ) : array(), // phpcs:ignore WordPress.DB.SlowDBQuery
		)
	);
	$html = '';
	foreach ( $q->posts as $p ) {
		$html .= '<li>' . meeha_render_quote_card( $p ) . '</li>';
	}
	wp_send_json_success(
		array(
			'html'  => $html,
			'count' => count( $q->posts ),
		)
	);
}

/* Archive + taxonomy archive: 12 (setting) per page; quotes bundle before <head>. */
add_action(
	'pre_get_posts',
	function ( $q ) {
		if ( is_admin() || ! $q->is_main_query() ) {
			return;
		}
		if ( $q->is_post_type_archive( 'meeha_photocard' ) || $q->is_tax( 'meeha_quote_type' ) ) {
			$q->set( 'posts_per_page', max( 4, (int) meeha_opt( 'quotes', 'per_page', 12 ) ) );
		}
	}
);
add_action(
	'wp',
	function () {
		if ( is_admin() ) {
			return;
		}
		if ( is_singular( 'meeha_photocard' ) || is_post_type_archive( 'meeha_photocard' ) || is_tax( 'meeha_quote_type' ) ) {
			meeha_enqueue_bundle( 'quotes' );
		} elseif ( is_page() ) {
			$p = get_queried_object();
			if ( $p instanceof WP_Post && ( has_shortcode( $p->post_content, 'meeha_photocards' ) || has_shortcode( $p->post_content, 'meeha_photocard' ) ) ) {
				meeha_enqueue_bundle( 'quote-cards' );
			}
		}
	}
);

/**
 * Archive grid + load more + pager (shared by the CPT archive and the taxonomy archive).
 *
 * @param WP_Query|null $q Query.
 */
function meeha_quote_archive_grid( $q = null ) {
	$q = $q ? $q : $GLOBALS['wp_query'];
	echo '<div class="m-container m-qarchive">';
	if ( $q->have_posts() ) {
		echo '<ul class="m-qgrid" role="list" data-qgrid>';
		foreach ( $q->posts as $p ) {
			echo '<li>' . meeha_render_quote_card( $p ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</ul>';
		$paged = max( 1, (int) get_query_var( 'paged' ) );
		if ( $paged < (int) $q->max_num_pages ) {
			echo '<div class="m-loadmore"><a class="m-btn m-btn--ghost" href="' . esc_url( get_pagenum_link( $paged + 1 ) ) . '" data-loadmore>আরও উক্তি দেখুন</a></div>';
		}
		meeha_pager( $q, array( 'label' => 'উক্তির পাতা' ) );
	} else {
		echo '<p class="m-qbrowse__empty">এখনো কোনো উক্তি নেই।</p>';
	}
	echo '</div>';
}
