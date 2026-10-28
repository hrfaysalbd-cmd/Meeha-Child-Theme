<?php
/**
 * Hub rendering: app shell (masthead/back bar, flash, bottom nav, footer note) and the theme's own views
 * (home, news, career, search). Shared helpers used by the plugin templates: icons, ads, news list,
 * packages, partner box.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

/**
 * Inline stroke icon.
 *
 * @param string $name Icon.
 * @return string
 */
function meeha_campus_icon( $name ) {
	static $p = array(
		'home'   => '<path d="M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/>',
		'brief'  => '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M9 7V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2M3 13h18"/>',
		'book'   => '<path d="M4 19V5a2 2 0 0 1 2-2h13v16H6a2 2 0 0 0-2 2 2 2 0 0 0 2 2h13"/><path d="M8 7h7"/>',
		'bolt'   => '<path d="M13 2 4 14h7l-1 8 9-12h-7z"/>',
		'user'   => '<circle cx="12" cy="8" r="4"/><path d="M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6"/>',
		'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
		'bell'   => '<path d="M6 8a6 6 0 1 1 12 0c0 7 3 8 3 8H3s3-1 3-8"/><path d="M10 20a2 2 0 0 0 4 0"/>',
		'back'   => '<path d="M15 18l-6-6 6-6"/>',
		'chev'   => '<path d="m9 18 6-6-6-6"/>',
		'mark'   => '<path d="M6 3h12v18l-6-4-6 4z"/>',
		'share'  => '<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="m8.6 13.5 6.8 4M15.4 6.5l-6.8 4"/>',
		'clock'  => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
		'cal'    => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
		'pin'    => '<path d="M12 21s-7-6.5-7-12a7 7 0 0 1 14 0c0 5.5-7 12-7 12z"/><circle cx="12" cy="9" r="2.5"/>',
		'trophy' => '<path d="M8 21h8M12 17v4M7 4h10v5a5 5 0 0 1-10 0z"/><path d="M17 5h3v2a3 3 0 0 1-3 3M7 5H4v2a3 3 0 0 0 3 3"/>',
		'check'  => '<path d="m5 12 5 5 9-10"/>',
		'chat'   => '<path d="M4 20l1.3-3.9A8 8 0 1 1 8 19.3z"/>',
		'mail'   => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
		'flame'  => '<path d="M12 3c1 4 5 5 5 10a5 5 0 0 1-10 0c0-2 1-3.5 2-4.5 0 2 1 3 2 3 0-3-1-5.5 1-8.5z"/>',
		'filter' => '<path d="M4 5h16l-6 8v6l-4-2v-4z"/>',
		'mega'   => '<path d="M3 10v4a1 1 0 0 0 1 1h3l7 4V5L7 9H4a1 1 0 0 0-1 1zM18 9a4 4 0 0 1 0 6"/>',
		'ticket' => '<path d="M3 7h18v3a2 2 0 0 0 0 4v3H3v-3a2 2 0 0 0 0-4z"/><path d="M14 7v10"/>',
		'ext'    => '<path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
		'doc'    => '<path d="M6 3h9l4 4v14H6z"/><path d="M14 3v5h5M9 13h7M9 17h5"/>',
		'star'   => '<path d="m12 3 2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z"/>',
		'grad'   => '<path d="m2 9 10-5 10 5-10 5z"/><path d="M6 11v5c3 2 9 2 12 0v-5"/>',
		'plus'   => '<path d="M12 5v14M5 12h14"/>',
		'news'   => '<path d="M4 5h13v14a2 2 0 0 0 2 2H6a2 2 0 0 1-2-2z"/><path d="M17 9h3v10a2 2 0 0 1-2 2M8 9h5M8 13h5M8 17h3"/>',
		'arrowl' => '<path d="M19 12H5M11 6l-6 6 6 6"/>',
	);
	return isset( $p[ $name ] ) ? '<svg class="mc-i" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $p[ $name ] . '</svg>' : '';
}

/**
 * Ad slot markup ('' when the slot is off).
 *
 * @param string $slot Slot.
 * @param bool   $wrap Wrap in a section.
 * @return string
 */
function meeha_campus_ad( $slot, $wrap = false ) {
	$html = function_exists( 'meeha_ad' ) ? (string) meeha_ad( $slot ) : '';
	if ( '' === trim( $html ) ) {
		return '';
	}
	$html = '<div class="mc-ad">' . $html . '</div>';
	return $wrap ? '<section class="mc-sec mc-g">' . $html . '</section>' : $html;
}

/**
 * Packages for the employer page.
 *
 * @return array jobs, ads, contact
 */
function meeha_campus_packages() {
	$clean = function ( $rows ) {
		$out = array();
		foreach ( (array) $rows as $r ) {
			if ( ! empty( $r['name'] ) ) {
				$out[] = array(
					'name'  => (string) $r['name'],
					'price' => isset( $r['price'] ) ? (string) $r['price'] : '',
					'desc'  => isset( $r['desc'] ) ? (string) $r['desc'] : '',
				);
			}
		}
		return $out;
	};
	return array(
		'jobs'    => $clean( meeha_opt( 'campus', 'job_packages', array() ) ),
		'ads'     => $clean( meeha_opt( 'campus', 'ad_packages', array() ) ),
		'contact' => (string) meeha_opt( 'campus', 'contact', '' ),
	);
}

/**
 * Partner box (settings; nothing when empty).
 */
function meeha_campus_partner_box() {
	$text = trim( (string) meeha_opt( 'campus', 'partner_text', '' ) );
	if ( '' === $text ) {
		return;
	}
	$title = (string) meeha_opt( 'campus', 'partner_title', '' );
	$url   = (string) meeha_opt( 'campus', 'partner_url', '' );
	echo '<section class="mc-sec mc-g"><div class="mc-card mc-pad">';
	if ( $title ) {
		echo '<b class="mc-block">' . esc_html( $title ) . '</b>';
	}
	echo '<p class="mc-sm mc-muted">' . esc_html( $text ) . '</p>';
	if ( $url ) {
		echo '<a class="mc-btn mc-btn--soft mc-btn--sm" href="' . esc_url( $url ) . '" target="_blank" rel="noopener sponsored">' . esc_html( (string) meeha_opt( 'campus', 'partner_label', 'বিস্তারিত' ) ) . '</a>';
	}
	echo '</div></section>';
}

/**
 * News sub-sections (chips) that exist on this site.
 *
 * @return array key => [label, term id]
 */
function meeha_campus_subs() {
	$out = array();
	$map = array(
		'all'        => 'সব খবর',
		'edu'        => 'শিক্ষা',
		'employment' => 'নিয়োগ',
		'career'     => 'ক্যারিয়ার',
		'success'    => 'সাফল্যের গল্প',
		'campus'     => 'ক্যাম্পাস',
	);
	foreach ( $map as $k => $l ) {
		$id = meeha_campus_cat( 'all' === $k ? 'main' : $k );
		if ( $id ) {
			$out[ $k ] = array( $l, $id );
		}
	}
	return $out;
}

/**
 * Posts of a section category.
 *
 * @param string $key  Sub key (all = main).
 * @param int    $n    Count.
 * @param int    $page Page.
 * @param string $s    Search.
 * @return WP_Query|null
 */
function meeha_campus_news_query( $key, $n, $page = 1, $s = '' ) {
	$cat = meeha_campus_cat( 'all' === $key ? 'main' : $key );
	if ( ! $cat ) {
		return null;
	}
	$args = array(
		'post_type'           => 'post',
		'post_status'         => 'publish',
		'cat'                 => $cat,
		'posts_per_page'      => $n,
		'paged'               => max( 1, (int) $page ),
		'ignore_sticky_posts' => true,
	);
	if ( '' !== $s ) {
		$args['s'] = $s;
	}
	return new WP_Query( $args );
}

/**
 * One news row.
 *
 * @param WP_Post $p     Post.
 * @param bool    $thumb Show image.
 * @return string
 */
function meeha_campus_news_item( $p, $thumb = true ) {
	$img = $thumb && function_exists( 'meeha_post_img' ) ? meeha_post_img( $p, 'thumb', array( 'class' => 'mc-nitem__img' ) ) : '';
	$cat = function_exists( 'meeha_post_kicker' ) ? (string) meeha_post_kicker( $p ) : '';
	$out = '<a class="mc-nitem" href="' . esc_url( get_permalink( $p ) ) . '">';
	if ( $img ) {
		$out .= '<span class="mc-nitem__thumb">' . $img . '</span>';
	}
	$out .= '<span class="mc-grow">';
	if ( $cat ) {
		$out .= '<span class="mc-cat">' . esc_html( wp_strip_all_tags( $cat ) ) . '</span>';
	}
	$out .= '<span class="mc-nitem__t">' . esc_html( get_the_title( $p ) ) . '</span>';
	$out .= '<span class="mc-xs mc-muted">' . esc_html( function_exists( 'meeha_post_time_ago' ) ? meeha_post_time_ago( $p ) : get_the_date( '', $p ) ) . '</span>';
	$out .= '</span></a>';
	return $out;
}

/**
 * A titled list of section news (used when a list is empty, e.g. no open jobs).
 *
 * @param string $key   Sub key.
 * @param int    $n     Count.
 * @param string $title Heading.
 */
function meeha_campus_news_list( $key, $n, $title ) {
	$q = meeha_campus_news_query( $key, $n );
	if ( ! $q || ! $q->have_posts() ) {
		return;
	}
	echo '<div class="mc-sech mc-mt"><h2>' . esc_html( $title ) . '</h2><a class="mc-link" href="' . esc_url( meeha_campus_url( 'news', $key ) ) . '">আরও</a></div>';
	echo '<div class="mc-card mc-list">';
	foreach ( $q->posts as $p ) {
		echo meeha_campus_news_item( $p ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
	echo '</div>';
}

/**
 * Title callback for news views.
 *
 * @param string $id Sub key.
 * @return string
 */
function meeha_campus_title_news( $id ) {
	$subs = meeha_campus_subs();
	return isset( $subs[ $id ] ) ? $subs[ $id ][0] : 'খবর';
}

/* ---------------- Shell ---------------- */

/**
 * Bottom nav items (filterable).
 *
 * @return array tab => [label, icon, view]
 */
function meeha_campus_nav() {
	$items = array( 'home' => array( 'হোম', 'home', 'home' ) );
	if ( meeha_campus_has_engine() ) {
		$items['jobs']  = array( 'চাকরি', 'brief', 'jobs' );
		$items['quiz']  = array( 'কুইজ', 'bolt', 'quiz' );
		$items['prep']  = array( 'প্রস্তুতি', 'book', 'prep' );
		$items['me']    = array( 'আমার', 'user', 'me' );
	}
	return (array) apply_filters( 'meeha_campus_nav', $items );
}

/**
 * Masthead tabs (root views).
 *
 * @param string $cur Current view.
 * @return array [label, url, on]
 */
function meeha_campus_mast_tabs( $cur ) {
	$v    = meeha_campus_view();
	$subs = meeha_campus_subs();
	$tabs = array( array( 'সব', meeha_campus_url(), 'home' === $cur ) );
	if ( meeha_campus_has_engine() ) {
		$tabs[] = array( 'নিয়োগ', meeha_campus_url( 'jobs' ), 'jobs' === $cur );
		$tabs[] = array( 'প্রস্তুতি', meeha_campus_url( 'prep' ), 'prep' === $cur );
		$tabs[] = array( 'কুইজ', meeha_campus_url( 'quiz' ), 'quiz' === $cur );
	}
	foreach ( array( 'career', 'success', 'campus', 'edu' ) as $k ) {
		if ( isset( $subs[ $k ] ) ) {
			$tabs[] = array( $subs[ $k ][0], meeha_campus_url( 'news', $k ), 'news' === $cur && $v['id'] === $k );
		}
	}
	if ( meeha_campus_has_engine() && class_exists( 'MHP_Events' ) && MHP_Events::upcoming( 1 ) ) {
		$tabs[] = array( 'ইভেন্ট', meeha_campus_url( 'events' ), 'events' === $cur );
	}
	$tabs = (array) apply_filters( 'meeha_campus_tabs', $tabs, $cur );
	if ( meeha_campus_has_engine() ) {
		// Phones reach "আমার" from the bottom bar; desktop has no bottom bar, so it sits at the end of the tabs.
		$tabs[] = array( 'আমার', meeha_campus_url( 'me' ), 'me' === $cur, 'mc-tab--desk' );
	}
	return $tabs;
}

/**
 * Render the whole hub (called by template.php inside <main>).
 */
function meeha_campus_render() {
	$v = meeha_campus_view();
	if ( meeha_campus_beta_blocked() ) {
		echo '<section class="mc-sec mc-g"><div class="mc-card mc-pad mc-center"><h1 class="mc-h1">পাতাটি পাওয়া যায়নি</h1><p class="mc-muted">এই অংশটি এখনো চালু হয়নি।</p><a class="mc-btn mc-btn--soft" href="' . esc_url( home_url( '/' ) ) . '">প্রথম পাতা</a></div></section>';
		return;
	}
	$def  = $v['def'];
	$root = $def && $def['root'];
	$exam = $def && 'exam' === $def['chrome'];
	$bar  = $def ? $def['bar'] : true;

	$app  = meeha_campus_app_mode();
	$wide = in_array( $v['view'], (array) apply_filters( 'meeha_campus_wide_views', array( 'home', 'jobs', 'prep', 'news', 'career', 'search', 'events', 'cv', 'cv-edit', 'consult', 'consultant' ) ), true );
	echo '<div class="mc mc-v-' . esc_attr( $v['view'] ) . ( $wide ? ' mc--wide' : ' mc--narrow' ) . ( $bar && ! $exam ? ' mc--nav' : '' ) . ( $app ? ' mc--app' : '' ) . '">';
	if ( $app && ! $exam ) {
		meeha_campus_render_appbar( $v, $root );
	}
	if ( (bool) meeha_opt( 'campus', 'beta', 1 ) ) {
		echo '<div class="mc-beta mc-g">পরীক্ষামূলক মোড: শুধু স্টাফ দেখছেন। সার্চ ইঞ্জিনে যাবে না।</div>';
	}
	if ( ! $root && ! $exam ) {
		$back = $def && $def['back'] ? $def['back'] : 'home';
		echo '<div class="mc-backbar mc-g"><a class="mc-ib" href="' . esc_url( meeha_campus_url( $back ) ) . '" aria-label="পেছনে">' . meeha_campus_icon( 'back' ) . '</a><span class="mc-backbar__t">' . esc_html( meeha_campus_view_title() ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<a class="mc-ib" href="' . esc_url( meeha_campus_url( 'search' ) ) . '" aria-label="খুঁজুন">' . meeha_campus_icon( 'search' ) . '</a></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
	// Root views that print their own masthead get only the tab strip (filter: meeha_campus_own_mast_views).
	$own_mast = (array) apply_filters( 'meeha_campus_own_mast_views', array( 'jobs', 'prep', 'lessons', 'pubs' ) );
	if ( 'home' === $v['view'] || ( $root && 'home' !== $v['view'] && ! in_array( $v['view'], $own_mast, true ) ) ) {
		meeha_campus_render_mast( $v['view'] );
	} elseif ( $root ) {
		meeha_campus_render_tabs( $v['view'] );
	}
	$flash = function_exists( 'mhp_take_flash' ) ? mhp_take_flash() : null;
	if ( $flash ) {
		echo '<div class="mc-g"><div class="mc-flash mc-flash--' . esc_attr( 'error' === $flash['t'] ? 'bad' : 'ok' ) . '" role="status">' . esc_html( $flash['m'] ) . '</div></div>';
	}

	if ( ! $def || ! is_callable( $def['render'] ) ) {
		echo '<section class="mc-sec mc-g"><div class="mc-card mc-pad mc-center"><p>পাতাটি পাওয়া যায়নি।</p><a class="mc-btn mc-btn--soft" href="' . esc_url( meeha_campus_url() ) . '">সেকশনের প্রথম পাতা</a></div></section>';
	} else {
		call_user_func( $def['render'], $v['id'] );
	}

	if ( ! $exam && in_array( $v['view'], array( 'home', 'jobs', 'job', 'employer', 'news', 'search' ), true ) ) {
		$note = trim( (string) meeha_opt( 'campus', 'footer_note', '' ) );
		if ( $note ) {
			echo '<p class="mc-foot mc-g">' . esc_html( $note ) . '</p>';
		}
	}
	if ( $bar && ! $exam ) {
		$tab = $def ? $def['tab'] : 'home';
		echo '<nav class="mc-nav" aria-label="সেকশনের মেনু">';
		foreach ( meeha_campus_nav() as $k => $it ) {
			$on = $tab === $k;
			echo '<a class="mc-nav__i' . ( 'quiz' === $k ? ' mc-nav__q' : '' ) . ( $on ? ' is-on' : '' ) . '" href="' . esc_url( meeha_campus_url( $it[2] ) ) . '"' . ( $on ? ' aria-current="page"' : '' ) . '>';
			echo 'quiz' === $k ? '<span class="mc-nav__qb">' . meeha_campus_icon( $it[1] ) . '</span>' : meeha_campus_icon( $it[1] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<span>' . esc_html( $it[0] ) . '</span></a>';
		}
		echo '</nav>';
	}
	echo '</div>';
}

/**
 * Mobile app mode: on phones and tablets the news site's header, menu, ticker and footer are hidden on hub pages
 * (campus.css, body.mc-app) and the hub gets its own top bar with a "back to news" button.
 *
 * @return bool
 */
function meeha_campus_app_mode() {
	return (bool) apply_filters( 'meeha_campus_app_mode', (bool) meeha_opt( 'campus', 'app_mode', 1 ) );
}

/**
 * Where "খবরে ফিরুন" goes: the setting, else the site's front page. Filter `meeha_campus_news_url` to change it in code.
 *
 * @return string
 */
function meeha_campus_news_url() {
	$url = trim( (string) meeha_opt( 'campus', 'news_url', '' ) );
	return (string) apply_filters( 'meeha_campus_news_url', $url ? $url : home_url( '/' ) );
}

/**
 * The hub's own top bar (phones/tablets in app mode). Root views: "খবরে ফিরুন" + section name; inner views: back
 * arrow + page title + a small news button. Extra buttons can be added with the `meeha_campus_appbar_end` action.
 *
 * @param array $v    Current view.
 * @param bool  $root Root view.
 */
function meeha_campus_render_appbar( $v, $root ) {
	$label = trim( (string) meeha_opt( 'campus', 'news_label', 'খবরে ফিরুন' ) );
	$label = '' !== $label ? $label : 'খবরে ফিরুন';
	echo '<div class="mc-appbar" data-mc-appbar>';
	if ( $root ) {
		echo '<a class="mc-appbar__news" href="' . esc_url( meeha_campus_news_url() ) . '">' . meeha_campus_icon( 'arrowl' ) . '<span>' . esc_html( $label ) . '</span></a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<a class="mc-appbar__t" href="' . esc_url( meeha_campus_url() ) . '">' . esc_html( (string) meeha_opt( 'campus', 'title', 'শিক্ষা ও ক্যাম্পাস' ) ) . '</a>';
	} else {
		$back = $v['def'] && $v['def']['back'] ? $v['def']['back'] : 'home';
		echo '<a class="mc-ib" href="' . esc_url( meeha_campus_url( $back ) ) . '" aria-label="পেছনে" data-mc-back>' . meeha_campus_icon( 'back' ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<span class="mc-appbar__t">' . esc_html( meeha_campus_view_title() ) . '</span>';
		echo '<a class="mc-appbar__news mc-appbar__news--icon" href="' . esc_url( meeha_campus_news_url() ) . '" aria-label="' . esc_attr( $label ) . '" title="' . esc_attr( $label ) . '">' . meeha_campus_icon( 'news' ) . '<span>খবর</span></a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
	echo '<a class="mc-ib" href="' . esc_url( meeha_campus_url( 'search' ) ) . '" aria-label="খুঁজুন">' . meeha_campus_icon( 'search' ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	do_action( 'meeha_campus_appbar_end', $v );
	echo '</div>';
}

/**
 * Masthead with tabs.
 *
 * @param string $cur View.
 */
function meeha_campus_render_mast( $cur ) {
	echo '<section class="mc-mast mc-g">';
	echo '<div class="mc-mast__eyebrow">' . esc_html( (string) meeha_opt( 'campus', 'eyebrow', 'মিহা নিউজ' ) ) . '</div>';
	echo '<h1 class="mc-mast__title">' . esc_html( (string) meeha_opt( 'campus', 'title', 'শিক্ষা ও ক্যাম্পাস' ) ) . '</h1>';
	$lede = (string) meeha_opt( 'campus', 'lede', '' );
	if ( $lede ) {
		echo '<p class="mc-mast__lede">' . esc_html( $lede ) . '</p>';
	}
	echo '<nav class="mc-tabs" aria-label="সেকশনের অংশ">';
	foreach ( meeha_campus_mast_tabs( $cur ) as $t ) {
		echo '<a class="mc-tab' . ( $t[2] ? ' is-on' : '' ) . ( isset( $t[3] ) ? ' ' . esc_attr( $t[3] ) : '' ) . '" href="' . esc_url( $t[1] ) . '"' . ( $t[2] ? ' aria-current="page"' : '' ) . '>' . esc_html( $t[0] ) . '</a>';
	}
	echo '</nav></section>';
}

/**
 * Tabs only (root views with their own masthead, e.g. jobs/prep): a compact desktop/mobile strip.
 *
 * @param string $cur View.
 */
function meeha_campus_render_tabs( $cur ) {
	echo '<nav class="mc-strip mc-g" aria-label="সেকশনের অংশ"><a class="mc-strip__home" href="' . esc_url( meeha_campus_url() ) . '">' . esc_html( (string) meeha_opt( 'campus', 'title', 'শিক্ষা ও ক্যাম্পাস' ) ) . '</a><div class="mc-chips">';
	foreach ( meeha_campus_mast_tabs( $cur ) as $t ) {
		echo '<a class="mc-chip' . ( $t[2] ? ' is-on' : '' ) . ( isset( $t[3] ) ? ' mc-chip--desk' : '' ) . '" href="' . esc_url( $t[1] ) . '">' . esc_html( $t[0] ) . '</a>';
	}
	echo '</div></nav>';
}

/* ---------------- Views ---------------- */

/**
 * Section home.
 */
function meeha_campus_render_home() {
	$engine = meeha_campus_has_engine();
	$side   = (array) apply_filters( 'meeha_campus_home_side', array( 'countdown', 'mystats', 'prep', 'events', 'alerts', 'employer', 'cv', 'consult' ) );
	$cols   = array(
		'main' => '',
		'side' => '',
	);
	$n      = 0;
	foreach ( (array) meeha_opt( 'campus', 'sections', meeha_campus_section_defaults() ) as $s ) {
		if ( empty( $s['enabled'] ) ) {
			continue;
		}
		$id = sanitize_key( $s['id'] );
		$fn = 'meeha_campus_home_' . $id;
		if ( ! function_exists( $fn ) ) {
			continue;
		}
		ob_start();
		call_user_func( $fn, isset( $s['label'] ) ? (string) $s['label'] : '', $engine );
		$html = trim( (string) ob_get_clean() );
		if ( '' === $html ) {
			continue;
		}
		// Phones: one column in the chosen order (CSS `order`). Desktop: main column + sidebar, like the rest of the site.
		$cols[ in_array( $id, $side, true ) ? 'side' : 'main' ] .= '<div class="mc-home__item mc-home__item--' . esc_attr( $id ) . '" style="order:' . (int) ( ++$n ) . '">' . $html . '</div>';
	}
	echo '<div class="mc-home"><div class="mc-home__main">' . $cols['main'] . '</div>' . ( '' !== $cols['side'] ? '<aside class="mc-home__side" aria-label="আরও">' . $cols['side'] . '</aside>' : '' ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside each section.
}

/**
 * Section head.
 *
 * @param string $title Title.
 * @param string $url   More link.
 * @param string $more  More label.
 */
function meeha_campus_sech( $title, $url = '', $more = 'সব' ) {
	echo '<div class="mc-sech"><h2>' . esc_html( $title ) . '</h2>' . ( $url ? '<a class="mc-link" href="' . esc_url( $url ) . '">' . esc_html( $more ) . '</a>' : '' ) . '</div>';
}

/**
 * Countdown strip: editor-set dates + jobs closing within 7 days.
 */
function meeha_campus_home_countdown() {
	$cards = array();
	$today = wp_date( 'Y-m-d', null, new DateTimeZone( 'Asia/Dhaka' ) );
	foreach ( (array) meeha_opt( 'campus', 'countdowns', array() ) as $c ) {
		if ( empty( $c['label'] ) || empty( $c['date'] ) || $c['date'] < $today ) {
			continue;
		}
		$days    = (int) ( ( strtotime( $c['date'] ) - strtotime( $today ) ) / DAY_IN_SECONDS );
		$cards[] = array( $c['label'], $days, 'দিন বাকি', $c['date'], isset( $c['url'] ) ? $c['url'] : '', false, $c['date'] );
	}
	if ( meeha_campus_has_engine() && class_exists( 'MHP_Jobs' ) ) {
		$res = MHP_Jobs::query(
			array(
				'soon'     => true,
				'per_page' => 4,
			)
		);
		foreach ( $res['items'] as $j ) {
			$cards[] = array( trim( $j['title'] . ( $j['org'] ? ', ' . $j['org'] : '' ) ), (int) $j['days_left'], 'দিনে আবেদন শেষ', $j['deadline'], meeha_campus_url( 'job', $j['id'] ), $j['days_left'] <= 2, $j['deadline'] );
		}
	}
	if ( ! $cards ) {
		return;
	}
	usort(
		$cards,
		function ( $a, $b ) {
			return strcmp( $a[6], $b[6] );
		}
	);
	echo '<section class="mc-sec mc-g"><div class="mc-hscroll">';
	foreach ( array_slice( $cards, 0, 6 ) as $c ) {
		$tag = $c[4] ? 'a href="' . esc_url( $c[4] ) . '"' : 'div';
		echo '<' . $tag . ' class="mc-card mc-countdown' . ( $c[5] ? ' is-urgent' : '' ) . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<span class="mc-xs mc-muted mc-block mc-clamp">' . esc_html( $c[0] ) . '</span>';
		echo '<b class="mc-num">' . esc_html( meeha_bn_digits( $c[1] ) ) . '</b> <span class="mc-sm">' . esc_html( 0 === $c[1] ? 'আজ' : $c[2] ) . '</span>';
		echo '<span class="mc-xs mc-muted mc-block">' . esc_html( function_exists( 'mhp_fdate' ) ? mhp_fdate( $c[3] ) : $c[3] ) . '</span>';
		echo '</' . ( $c[4] ? 'a' : 'div' ) . '>';
	}
	echo '</div></section>';
}

/**
 * Daily quiz hero.
 *
 * @param string $label  Label.
 * @param bool   $engine Plugin active.
 */
function meeha_campus_home_quiz( $label, $engine ) {
	if ( ! $engine || ! class_exists( 'MHP_Quiz' ) ) {
		return;
	}
	$ids = MHP_Quiz::set_for( mhp_today() );
	if ( ! $ids ) {
		return;
	}
	$uid     = get_current_user_id();
	$play    = $uid ? MHP_Quiz::play( $uid, mhp_today() ) : null;
	$streak  = MHP_Quiz::streak( $uid );
	$count   = MHP_Quiz::played_count( mhp_today() );
	$min     = max( 1, (int) mhp_opt( 'count_min', 20 ) );
	$sponsor = trim( (string) mhp_opt( 'quiz_sponsor', '' ) );
	$done    = $play && ! empty( $play['finished_at'] );
	$play    = $play && ! empty( $play['answered'] ) ? $play : null; // a row with no answers yet = page opened only.
	echo '<section class="mc-sec mc-g"><div class="mc-qhero">';
	echo '<div class="mc-qhero__bubbles" aria-hidden="true"><i></i><i class="f"></i><i></i><i></i><i></i><i></i><i class="f"></i><i></i></div>';
	if ( $streak > 1 ) {
		echo '<span class="mc-pill mc-pill--glass">' . meeha_campus_icon( 'flame' ) . ' টানা ' . esc_html( meeha_bn_digits( $streak ) ) . ' দিন</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
	echo '<h2 class="mc-qhero__t">' . esc_html( $label ? $label : 'আজকের কুইজ' ) . '</h2>';
	$meta = meeha_bn_digits( count( $ids ) ) . 'টি প্রশ্ন · ব্যাখ্যাসহ';
	if ( $play ) {
		$meta .= ' · আপনার স্কোর ' . meeha_bn_digits( (int) $play['correct'] ) . '/' . meeha_bn_digits( count( $ids ) );
	} elseif ( $count >= $min ) {
		$meta .= ' · আজ ' . mhp_num( $count ) . ' জন খেলেছেন';
	}
	echo '<div class="mc-qhero__meta">' . esc_html( $meta ) . '</div>';
	echo '<div class="mc-row mc-mt"><a class="mc-btn mc-btn--light" href="' . esc_url( meeha_campus_url( 'quiz' ) ) . '">' . esc_html( $done ? 'উত্তর ও ব্যাখ্যা দেখুন' : ( $play ? 'বাকি প্রশ্ন খেলুন' : 'কুইজ শুরু করুন' ) ) . '</a>';
	echo '<a class="mc-btn mc-btn--outline-light" href="' . esc_url( meeha_campus_url( 'leaderboard' ) ) . '">লিডারবোর্ড</a></div>';
	if ( $sponsor ) {
		echo '<div class="mc-qhero__spons"><span>আজকের কুইজ উপহার দিচ্ছে</span><b>' . esc_html( $sponsor ) . '</b></div>';
	}
	echo '</div></section>';
}

/**
 * Top news.
 *
 * @param string $label Label.
 */
function meeha_campus_home_news( $label ) {
	$n = max( 2, min( 10, (int) meeha_opt( 'campus', 'news_count', 4 ) ) );
	$q = meeha_campus_news_query( 'all', $n );
	if ( ! $q || ! $q->have_posts() ) {
		return;
	}
	echo '<section class="mc-sec mc-g">';
	meeha_campus_sech( $label ? $label : 'শীর্ষ খবর', meeha_campus_url( 'news', 'all' ), 'সব খবর' );
	$lead = $q->posts[0];
	$img  = function_exists( 'meeha_post_img' ) ? meeha_post_img( $lead, 'card-lg', array( 'class' => 'mc-lead__img' ) ) : '';
	echo '<a class="mc-card mc-lead" href="' . esc_url( get_permalink( $lead ) ) . '">' . ( $img ? '<span class="mc-lead__media">' . $img . '</span>' : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo '<span class="mc-lead__body">';
	$kick = function_exists( 'meeha_post_kicker' ) ? wp_strip_all_tags( (string) meeha_post_kicker( $lead ) ) : '';
	if ( $kick ) {
		echo '<span class="mc-cat">' . esc_html( $kick ) . '</span>';
	}
	echo '<span class="mc-lead__t">' . esc_html( get_the_title( $lead ) ) . '</span><span class="mc-xs mc-muted">' . esc_html( function_exists( 'meeha_post_time_ago' ) ? meeha_post_time_ago( $lead ) : '' ) . '</span></span></a>';
	if ( count( $q->posts ) > 1 ) {
		echo '<div class="mc-card mc-list mc-mt">';
		foreach ( array_slice( $q->posts, 1 ) as $p ) {
			echo meeha_campus_news_item( $p ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</div>';
	}
	echo '</section>';
}

/**
 * Section banner ad.
 */
function meeha_campus_home_ad() {
	echo meeha_campus_ad( 'campus_banner', true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

/**
 * Jobs closing soonest.
 *
 * @param string $label  Label.
 * @param bool   $engine Plugin.
 */
function meeha_campus_home_closing( $label, $engine ) {
	if ( ! $engine || ! class_exists( 'MHP_Jobs' ) ) {
		return;
	}
	$res = MHP_Jobs::query( array( 'per_page' => 3 ) );
	if ( ! $res['items'] ) {
		return;
	}
	$saved = is_user_logged_in() ? MHP_Jobs::saved( get_current_user_id() ) : array();
	echo '<section class="mc-sec mc-g">';
	meeha_campus_sech( $label ? $label : 'আবেদন শেষ হচ্ছে', meeha_campus_url( 'jobs' ), 'সব চাকরি' );
	echo '<div class="mc-col mc-grid2">';
	foreach ( $res['items'] as $j ) {
		echo mhp_job_card( $j, in_array( $j['id'], $saved, true ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
	echo '</div></section>';
}

/**
 * Featured employers.
 *
 * @param string $label  Label.
 * @param bool   $engine Plugin.
 */
function meeha_campus_home_featured( $label, $engine ) {
	if ( ! $engine || ! class_exists( 'MHP_Jobs' ) ) {
		return;
	}
	$res = MHP_Jobs::query(
		array(
			'featured' => true,
			'per_page' => 6,
		)
	);
	if ( ! $res['items'] ) {
		return;
	}
	$saved = is_user_logged_in() ? MHP_Jobs::saved( get_current_user_id() ) : array();
	echo '<section class="mc-sec mc-g">';
	meeha_campus_sech( $label ? $label : 'ফিচার্ড নিয়োগদাতা', meeha_campus_url( 'employer' ), 'বিজ্ঞপ্তি দিন' );
	echo '<div class="mc-hscroll mc-hscroll--wide">';
	foreach ( $res['items'] as $j ) {
		echo mhp_job_card( $j, in_array( $j['id'], $saved, true ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
	echo '</div></section>';
}

/**
 * Prep tracks + next live test.
 *
 * @param string $label  Label.
 * @param bool   $engine Plugin.
 */
function meeha_campus_home_prep( $label, $engine ) {
	if ( ! $engine || ! function_exists( 'mhp_tracks' ) ) {
		return;
	}
	$tracks = mhp_tracks();
	if ( ! $tracks ) {
		return;
	}
	echo '<section class="mc-sec mc-g">';
	meeha_campus_sech( $label ? $label : 'পরীক্ষার প্রস্তুতি', meeha_campus_url( 'prep' ), 'সব ট্র্যাক' );
	echo '<div class="mc-tracks">';
	foreach ( array_slice( $tracks, 0, 4 ) as $t ) {
		echo mhp_track_tile( $t ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
	echo '</div>';
	$live = MHP_Tests::next_live();
	if ( $live ) {
		echo '<a class="mc-card mc-cta mc-mt" href="' . esc_url( meeha_campus_url( 'test', $live['id'] ) ) . '"><span class="mc-mono mc-mono--gold">' . meeha_campus_icon( 'trophy' ) . '</span><span class="mc-grow"><b class="mc-block">' . esc_html( ( 'open' === $live['state'] ? 'এখন চলছে: ' : '' ) . $live['title'] ) . '</b><span class="mc-xs mc-muted">' . esc_html( trim( ( $live['start'] ? mhp_fdatetime( MHP_Tests::norm_dt( $live['start'] ) ) : '' ) . ' · ' . ( $live['price'] > 0 ? mhp_tk( $live['price'] ) : 'ফ্রি' ), ' ·' ) ) . '</span></span>' . meeha_campus_icon( 'chev' ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
	echo '</section>';
}

/**
 * Success stories (+ "send yours").
 *
 * @param string $label  Label.
 * @param bool   $engine Plugin.
 */
function meeha_campus_home_stories( $label, $engine ) {
	$q = meeha_campus_news_query( 'success', 6 );
	if ( ( ! $q || ! $q->have_posts() ) && ! $engine ) {
		return;
	}
	echo '<section class="mc-sec mc-g">';
	meeha_campus_sech( $label ? $label : 'সাফল্যের গল্প', $q && $q->have_posts() ? meeha_campus_url( 'news', 'success' ) : '', 'আরও' );
	echo '<div class="mc-hscroll">';
	if ( $q ) {
		foreach ( $q->posts as $p ) {
			$img = function_exists( 'meeha_post_img' ) ? meeha_post_img( $p, 'card', array( 'class' => 'mc-story__img' ) ) : '';
			echo '<a class="mc-card mc-story" href="' . esc_url( get_permalink( $p ) ) . '">' . ( $img ? '<span class="mc-story__media">' . $img . '</span>' : '' ) . '<span class="mc-story__b"><span class="mc-cat">সাফল্যের গল্প</span><span class="mc-story__t">' . esc_html( get_the_title( $p ) ) . '</span></span></a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}
	if ( $engine ) {
		echo '<a class="mc-card mc-story mc-story--add" href="' . esc_url( meeha_campus_url( 'me', '', array( 'tab' => 'story' ) ) ) . '"><span class="mc-story__media mc-story__plus">' . meeha_campus_icon( 'plus' ) . '</span><span class="mc-story__b"><span class="mc-cat">পাঠকের গল্প</span><span class="mc-story__t">নিজের সাফল্যের গল্প পাঠান; বাছাই করা গল্প প্রকাশ হবে</span></span></a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
	echo '</div></section>';
}

/**
 * Next event.
 *
 * @param string $label  Label.
 * @param bool   $engine Plugin.
 */
function meeha_campus_home_events( $label, $engine ) {
	if ( ! $engine || ! class_exists( 'MHP_Events' ) ) {
		return;
	}
	$ev = MHP_Events::upcoming( 1 );
	if ( ! $ev ) {
		return;
	}
	$e = $ev[0];
	echo '<section class="mc-sec mc-g"><a class="mc-eventhero" href="' . esc_url( meeha_campus_url( 'event', $e['id'] ) ) . '">';
	echo '<span class="mc-pill mc-pill--glass">' . meeha_campus_icon( 'cal' ) . ' ' . esc_html( mhp_fdate( $e['date'] ) . ( $e['time'] ? ' · ' . $e['time'] : '' ) ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo '<span class="mc-eventhero__t">' . esc_html( $e['title'] ) . '</span>';
	echo '<span class="mc-sm mc-eventhero__sub">' . esc_html( trim( ( $e['venue'] ? $e['venue'] : ( $e['online'] ? 'অনলাইন' : '' ) ) . ' · ' . ( $e['price'] > 0 ? mhp_tk( $e['price'] ) : 'নিবন্ধন ফ্রি' ), ' ·' ) ) . '</span>';
	echo '<span class="mc-row mc-mt"><span class="mc-btn mc-btn--light mc-btn--sm">বিস্তারিত ও নিবন্ধন</span>' . ( $e['sponsor'] ? '<span class="mc-xs mc-eventhero__sub">' . esc_html( $e['sponsor'] ) . '</span>' : '' ) . '</span>';
	echo '</a></section>';
}

/**
 * Career guides.
 *
 * @param string $label Label.
 */
function meeha_campus_home_guides( $label ) {
	$q = meeha_campus_news_query( 'career', 4 );
	if ( ! $q || ! $q->have_posts() ) {
		return;
	}
	echo '<section class="mc-sec mc-g">';
	meeha_campus_sech( $label ? $label : 'ক্যারিয়ার গাইড', meeha_campus_url( 'career' ), 'সব গাইড' );
	echo '<div class="mc-card mc-list">';
	foreach ( $q->posts as $p ) {
		echo meeha_campus_news_item( $p, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
	echo '</div></section>';
}

/**
 * Job alerts card.
 *
 * @param string $label  Label.
 * @param bool   $engine Plugin.
 */
function meeha_campus_home_alerts( $label, $engine ) {
	$wa = (string) meeha_opt( 'campus', 'whatsapp_url', '' );
	if ( ! $engine && ! $wa ) {
		return;
	}
	echo '<section class="mc-sec mc-g"><div class="mc-card mc-pad">';
	echo '<h2 class="mc-h3">' . esc_html( $label ? $label : 'জব অ্যালার্ট' ) . '</h2>';
	echo '<p class="mc-sm mc-muted">পছন্দের চাকরি সংরক্ষণ করুন, শেষ তারিখের আগে ইমেইলে মনে করিয়ে দেওয়া হবে।</p><div class="mc-row mc-wrap">';
	if ( $wa ) {
		echo '<a class="mc-btn mc-btn--sm" href="' . esc_url( $wa ) . '" target="_blank" rel="noopener">' . meeha_campus_icon( 'chat' ) . ' হোয়াটসঅ্যাপে</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
	if ( $engine ) {
		echo '<a class="mc-btn mc-btn--sm mc-btn--ghost" href="' . esc_url( meeha_campus_url( 'me', '', array( 'tab' => 'alerts' ) ) ) . '">' . meeha_campus_icon( 'mail' ) . ' ইমেইলে</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
	echo '</div></div></section>';
}

/**
 * Employer CTA.
 *
 * @param string $label  Label.
 * @param bool   $engine Plugin.
 */
function meeha_campus_home_employer( $label, $engine ) {
	if ( ! $engine ) {
		return;
	}
	echo '<section class="mc-sec mc-g"><a class="mc-card mc-cta" href="' . esc_url( meeha_campus_url( 'employer' ) ) . '"><span class="mc-mono">' . meeha_campus_icon( 'mega' ) . '</span><span class="mc-grow"><b class="mc-block">নিয়োগদাতা বা বিজ্ঞাপনদাতা?</b><span class="mc-xs mc-muted">বিজ্ঞপ্তি জমা, ফিচার্ড বিজ্ঞপ্তি ও স্পনসরশিপের প্যাকেজ</span></span>' . meeha_campus_icon( 'chev' ) . '</a></section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

/**
 * CV studio teaser.
 *
 * @param string $label  Label.
 * @param bool   $engine Plugin.
 */
function meeha_campus_home_cv( $label, $engine ) {
	if ( ! $engine || ! class_exists( 'MHP_CV' ) ) {
		return;
	}
	echo '<section class="mc-sec mc-g"><a class="mc-card mc-cta" href="' . esc_url( meeha_campus_url( 'cv' ) ) . '"><span class="mc-mono">' . meeha_campus_icon( 'doc' ) . '</span><span class="mc-grow"><b class="mc-block">' . esc_html( $label ? $label : 'সিভি স্টুডিও' ) . '</b><span class="mc-xs mc-muted">তথ্য লিখে পছন্দের ডিজাইনে সিভি বানান, প্রিন্ট বা পিডিএফ নিন। ফ্রি টেমপ্লেটও আছে।</span></span>' . meeha_campus_icon( 'chev' ) . '</a></section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

/**
 * Career consultant teaser (only when the panel has someone).
 *
 * @param string $label  Label.
 * @param bool   $engine Plugin.
 */
function meeha_campus_home_consult( $label, $engine ) {
	if ( ! $engine || ! class_exists( 'MHP_Consult' ) || ! MHP_Consult::any() ) {
		return;
	}
	echo '<section class="mc-sec mc-g"><a class="mc-card mc-cta" href="' . esc_url( meeha_campus_url( 'consult' ) ) . '"><span class="mc-mono">' . meeha_campus_icon( 'chat' ) . '</span><span class="mc-grow"><b class="mc-block">' . esc_html( $label ? $label : 'ক্যারিয়ার পরামর্শ' ) . '</b><span class="mc-xs mc-muted">সিভি রিভিউ, ইন্টারভিউ প্রস্তুতি ও ক্যারিয়ার পরিকল্পনায় অভিজ্ঞদের সঙ্গে একান্ত সেশন</span></span>' . meeha_campus_icon( 'chev' ) . '</a></section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

/**
 * Today's selected lesson.
 *
 * @param string $label  Label.
 * @param bool   $engine Plugin.
 */
function meeha_campus_home_lesson( $label, $engine ) {
	if ( ! $engine || ! class_exists( 'MHP_Lessons' ) ) {
		return;
	}
	$uid = get_current_user_id();
	$tgt = $uid && class_exists( 'MHP_Learn' ) ? MHP_Learn::target( $uid ) : array( 'track' => 0 );
	$l   = MHP_Lessons::today( (int) $tgt['track'] );
	if ( ! $l ) {
		return;
	}
	echo '<section class="mc-sec mc-g">';
	meeha_campus_sech( $label ? $label : 'আজকের নির্বাচিত পাঠ', meeha_campus_url( 'lessons' ), 'সব পাঠ' );
	echo '<a class="mc-card mc-today" href="' . esc_url( meeha_campus_url( 'lesson', $l['id'] ) ) . '">';
	if ( $l['thumb'] ) {
		echo '<span class="mc-today__media">' . wp_get_attachment_image( $l['thumb'], 'medium_large', false, array( 'alt' => '', 'loading' => 'lazy' ) ) . '</span>';
	}
	echo '<span class="mc-today__body"><b class="mc-today__t">' . esc_html( $l['title'] ) . '</b>';
	if ( $l['excerpt'] ) {
		echo '<span class="mc-sm mc-muted mc-clamp">' . esc_html( $l['excerpt'] ) . '</span>';
	}
	echo '<span class="mc-xs mc-muted">' . esc_html( trim( $l['subject'] . ' · ' . meeha_bn_digits( $l['minutes'] ) . ' মিনিট' . ( $l['video'] ? ' · ভিডিও' : '' ) . ( $l['qcount'] ? ' · অনুশীলনের প্রশ্ন' : '' ), ' ·' ) ) . '</span></span></a></section>';
}

/**
 * The reader's progress (points, level, today's goal); a short invitation for guests.
 *
 * @param string $label  Label.
 * @param bool   $engine Plugin.
 */
function meeha_campus_home_mystats( $label, $engine ) {
	if ( ! $engine || ! class_exists( 'MHP_Points' ) ) {
		return;
	}
	$uid = get_current_user_id();
	echo '<section class="mc-sec mc-g">';
	if ( ! $uid ) {
		echo '<a class="mc-card mc-cta" href="' . esc_url( meeha_campus_url( 'me' ) ) . '"><span class="mc-mono mc-mono--gold">' . meeha_campus_icon( 'trophy' ) . '</span><span class="mc-grow"><b class="mc-block">' . esc_html( $label ? $label : 'আমার অগ্রগতি' ) . '</b><span class="mc-xs mc-muted">লগইন করলে কুইজ, টেস্ট আর পাঠে পয়েন্ট, সাপ্তাহিক চ্যালেঞ্জ আর ভুলের খাতা</span></span>' . meeha_campus_icon( 'chev' ) . '</a></section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		return;
	}
	$total = MHP_Points::total( $uid );
	$lv    = MHP_Points::level( $total );
	$goal  = MHP_Points::goal( $uid );
	$today = MHP_Points::today( $uid );
	$pct   = min( 100, (int) round( $today / max( 1, $goal ) * 100 ) );
	echo '<a class="mc-card mc-pad mc-mini" href="' . esc_url( meeha_campus_url( 'me' ) ) . '">';
	echo '<span class="mc-ring mc-ring--sm" style="--p:' . (int) $pct . '" aria-hidden="true"><b>' . esc_html( meeha_bn_digits( $pct ) ) . '%</b></span>';
	echo '<span class="mc-grow"><span class="mc-xs mc-muted mc-block">' . esc_html( $label ? $label : 'আমার অগ্রগতি' ) . '</span><b class="mc-block">' . esc_html( 'লেভেল ' . meeha_bn_digits( $lv['n'] ) . ' · ' . $lv['name'] ) . '</b>';
	echo '<span class="mc-xs mc-muted">' . esc_html( mhp_num( $total ) . ' পয়েন্ট · আজ ' . meeha_bn_digits( $today ) . '/' . meeha_bn_digits( $goal ) ) . '</span></span>' . meeha_campus_icon( 'chev' ) . '</a></section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

/**
 * Publications strip (latest covers).
 *
 * @param string $label  Label.
 * @param bool   $engine Plugin.
 */
function meeha_campus_home_pubs( $label, $engine ) {
	if ( ! $engine || ! class_exists( 'MHP_Pubs' ) ) {
		return;
	}
	$items = array_slice( MHP_Pubs::list_pubs(), 0, 4 );
	if ( ! $items ) {
		return;
	}
	echo '<section class="mc-sec mc-g">';
	meeha_campus_sech( $label ? $label : 'প্রকাশনা', meeha_campus_url( 'pubs' ) );
	echo '<div class="mc-pubs mc-pubs--strip">';
	foreach ( $items as $d ) {
		echo '<a class="mc-pubcard" href="' . esc_url( meeha_campus_url( 'pub', $d['id'] ) ) . '"><span class="mc-cover">';
		echo $d['thumb'] ? wp_get_attachment_image( $d['thumb'], 'medium', false, array( 'alt' => '', 'loading' => 'lazy' ) ) : '<span class="mc-cover__ph"><span class="mc-xs">' . esc_html( $d['kind_label'] ) . '</span><b>' . esc_html( $d['title'] ) . '</b></span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</span><b class="mc-pubcard__t mc-clamp">' . esc_html( $d['title'] ) . '</b>';
		$p = array();
		if ( $d['digital'] ) {
			$p[] = 'ডিজিটাল ' . ( $d['d_price'] > 0 ? mhp_tk( $d['d_price'] ) : 'ফ্রি' );
		}
		if ( $d['print'] ) {
			$p[] = 'প্রিন্ট ' . mhp_tk( $d['p_price'] );
		}
		echo '<span class="mc-xs mc-muted">' . esc_html( $p ? implode( ' · ', $p ) : $d['kind_label'] ) . '</span></a>';
	}
	echo '</div></section>';
}

/**
 * News view (id = sub key).
 *
 * @param string $id Sub key.
 */
function meeha_campus_render_news( $id ) {
	$subs = meeha_campus_subs();
	$key  = isset( $subs[ $id ] ) ? $id : 'all';
	$pg   = isset( $_GET['pg'] ) ? max( 1, absint( $_GET['pg'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( count( $subs ) > 1 ) {
		echo '<nav class="mc-g mc-chips-wrap" aria-label="বিভাগ"><div class="mc-chips">';
		foreach ( $subs as $k => $s ) {
			echo '<a class="mc-chip' . ( $k === $key ? ' is-on' : '' ) . '" href="' . esc_url( meeha_campus_url( 'news', $k ) ) . '">' . esc_html( $s[0] ) . '</a>';
		}
		echo '</div></nav>';
	}
	meeha_campus_post_list( meeha_campus_news_query( $key, 15, $pg ), meeha_campus_url( 'news', $key ), $pg, isset( $subs[ $key ] ) ? get_category_link( $subs[ $key ][1] ) : '' );
}

/**
 * Career guides view.
 */
function meeha_campus_render_career() {
	$pg = isset( $_GET['pg'] ) ? max( 1, absint( $_GET['pg'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	echo '<section class="mc-sec mc-g mc-tight"><p class="mc-sm mc-muted">চাকরির আবেদন, প্রস্তুতি, ভাইভা ও ক্যারিয়ার পরিকল্পনা নিয়ে মিহা নিউজের লেখা।</p></section>';
	$cat = meeha_campus_cat( 'career' );
	meeha_campus_post_list( meeha_campus_news_query( 'career', 15, $pg ), meeha_campus_url( 'career' ), $pg, $cat ? get_category_link( $cat ) : '' );
	if ( meeha_campus_has_engine() ) {
		echo '<section class="mc-sec mc-g"><a class="mc-card mc-cta" href="' . esc_url( meeha_campus_url( 'prep' ) ) . '"><span class="mc-mono">' . meeha_campus_icon( 'book' ) . '</span><span class="mc-grow"><b class="mc-block">ফ্রি মডেল টেস্ট দিন</b><span class="mc-xs mc-muted">বিসিএস, ব্যাংক, প্রাথমিক শিক্ষক ও আরও</span></span>' . meeha_campus_icon( 'chev' ) . '</a></section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}

/**
 * Paginated post list.
 *
 * @param WP_Query|null $q       Query.
 * @param string        $base    Base URL.
 * @param int           $pg      Page.
 * @param string        $archive Category archive URL.
 */
function meeha_campus_post_list( $q, $base, $pg, $archive = '' ) {
	echo '<section class="mc-sec mc-g">';
	if ( ! $q ) {
		echo '<div class="mc-card mc-pad mc-center mc-muted">এই বিভাগটি এখনো ঠিক করা হয়নি।</div></section>';
		return;
	}
	if ( ! $q->have_posts() ) {
		echo '<div class="mc-card mc-pad mc-center mc-muted">এই বিভাগে এখনো খবর নেই।</div></section>';
		return;
	}
	echo '<div class="mc-card mc-list">';
	foreach ( $q->posts as $i => $p ) {
		echo meeha_campus_news_item( $p ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( 5 === $i ) {
			echo meeha_campus_ad( 'campus_infeed' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}
	echo '</div>';
	$pages = min( 20, (int) $q->max_num_pages );
	if ( $pages > 1 ) {
		echo '<nav class="mc-pager" aria-label="পাতা">';
		for ( $i = 1; $i <= $pages; $i++ ) {
			printf( '<a href="%s"%s>%s</a>', esc_url( 1 === $i ? $base : add_query_arg( 'pg', $i, $base ) ), $i === $pg ? ' class="is-on" aria-current="page"' : '', esc_html( meeha_bn_digits( $i ) ) );
		}
		echo '</nav>';
	}
	if ( $archive && $q->max_num_pages > 20 ) {
		echo '<p class="mc-center"><a class="mc-link" href="' . esc_url( $archive ) . '">পুরোনো সব খবর</a></p>';
	}
	echo '</section>';
}

/**
 * Search: section news + open jobs.
 */
function meeha_campus_render_search() {
	$s = isset( $_GET['q'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_GET['q'] ) ), 0, 80 ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	echo '<section class="mc-sec mc-g"><form class="mc-search" method="get" action="' . esc_url( meeha_campus_url( 'search' ) ) . '" role="search">';
	if ( ! get_option( 'permalink_structure' ) ) {
		echo '<input type="hidden" name="page_id" value="' . (int) meeha_campus_hub_id() . '"><input type="hidden" name="mc_view" value="search">';
	}
	echo '<label class="mc-field mc-grow">' . meeha_campus_icon( 'search' ) . '<span class="screen-reader-text">খুঁজুন</span><input type="search" name="q" value="' . esc_attr( $s ) . '" placeholder="খবর, চাকরি বা প্রতিষ্ঠান" maxlength="80" autofocus></label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo '<button class="mc-btn" type="submit">খুঁজুন</button></form></section>';
	if ( mb_strlen( $s ) < 2 ) {
		return;
	}
	if ( meeha_campus_has_engine() && class_exists( 'MHP_Jobs' ) ) {
		$res = MHP_Jobs::query(
			array(
				'q'        => $s,
				'per_page' => 6,
			)
		);
		if ( $res['items'] ) {
			$saved = is_user_logged_in() ? MHP_Jobs::saved( get_current_user_id() ) : array();
			echo '<section class="mc-sec mc-g">';
			meeha_campus_sech( 'চাকরির বিজ্ঞপ্তি', $res['total'] > 6 ? meeha_campus_url( 'jobs', '', array( 'q' => $s ) ) : '', 'সব' );
			echo '<div class="mc-col mc-grid2">';
			foreach ( $res['items'] as $j ) {
				echo mhp_job_card( $j, in_array( $j['id'], $saved, true ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</div></section>';
		}
	}
	$q = meeha_campus_news_query( 'all', 15, 1, $s );
	echo '<section class="mc-sec mc-g">';
	meeha_campus_sech( 'খবর' );
	if ( $q && $q->have_posts() ) {
		echo '<div class="mc-card mc-list">';
		foreach ( $q->posts as $p ) {
			echo meeha_campus_news_item( $p ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</div>';
	} else {
		echo '<div class="mc-card mc-pad mc-center mc-muted">"' . esc_html( $s ) . '" লিখে সেকশনে কিছু পাওয়া যায়নি। <a class="mc-link" href="' . esc_url( home_url( '/?s=' . rawurlencode( $s ) ) ) . '">পুরো সাইটে খুঁজুন</a></div>';
	}
	echo '</section>';
}
