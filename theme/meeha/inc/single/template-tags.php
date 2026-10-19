<?php
/**
 * Single-post template tags (owner D).
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

/**
 * Variant of a singular item: news | investigative | opinion | literature.
 *
 * @param WP_Post|int $post Post.
 * @return string
 */
function meeha_single_variant( $post ) {
	$post = get_post( $post );
	if ( ! $post ) {
		return 'news';
	}
	$v = 'news';
	if ( 'post' === $post->post_type ) {
		$label = meeha_post_type_label( $post );
		if ( 'সাহিত্য' === $label ) {
			$v = 'literature';
		} elseif ( 'মতামত' === $label ) {
			$v = 'opinion';
		} elseif ( meeha_is_investigative( $post ) ) {
			$v = 'investigative';
		}
	}
	return (string) apply_filters( 'meeha_single_variant', $v, $post );
}

/**
 * সম্পাদকীয়: filed directly under the `editorial` category (not only its child উপ-সম্পাদকীয়).
 *
 * @param WP_Post|int $post Post.
 * @return bool
 */
function meeha_single_is_editorial( $post ) {
	$post = get_post( $post );
	return $post && has_term( 'editorial', 'category', $post );
}

/**
 * Opinion sub-label (সম্পাদকীয় / উপ-সম্পাদকীয় / পাঠক কথন / মতামত).
 *
 * @param WP_Post $post Post.
 * @return string
 */
function meeha_single_opinion_label( $post ) {
	foreach ( array( 'editorial', 'sub-editorial', 'readers-words' ) as $slug ) {
		if ( has_term( $slug, 'category', $post ) ) {
			$t = get_category_by_slug( $slug );
			return $t ? $t->name : 'মতামত';
		}
	}
	return 'মতামত';
}

/**
 * Ordered pool of related posts: shared tags (newest) → same (child) category → its parent → latest.
 * The first item feeds the inline "আরও পড়ুন" card, the rest "এ বিষয়ে আরও" — so nothing repeats.
 *
 * @param WP_Post $post  Post.
 * @param int     $count How many.
 * @return WP_Post[]
 */
function meeha_single_related_pool( $post, $count = 5 ) {
	$post  = get_post( $post );
	$count = max( 0, (int) $count );
	if ( ! $post || ! $count ) {
		return array();
	}
	$key = 'meeha_rel_' . $post->ID . '_' . $count;
	$ids = get_transient( $key );
	if ( ! is_array( $ids ) ) {
		$ids     = array();
		$exclude = array( $post->ID );
		$base    = array(
			'post_type'           => 'post',
			'post_status'         => 'publish',
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
			'fields'              => 'ids',
			'orderby'             => 'date',
			'order'               => 'DESC',
		);
		$steps   = array();
		$tags    = wp_get_post_tags( $post->ID, array( 'fields' => 'ids' ) );
		if ( $tags ) {
			$steps[] = array( 'tag__in' => $tags );
		}
		$cat = meeha_primary_category( $post );
		if ( $cat ) {
			$steps[] = array(
				'tax_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'taxonomy'         => 'category',
						'terms'            => array( $cat->term_id ),
						'include_children' => false,
					),
				),
			);
			if ( $cat->parent ) {
				$steps[] = array( 'cat' => (int) $cat->parent );
			}
		}
		$steps[] = array();
		foreach ( $steps as $s ) {
			$need = $count - count( $ids );
			if ( $need <= 0 ) {
				break;
			}
			$q   = new WP_Query( array_merge( $base, $s, array( 'posts_per_page' => $need, 'post__not_in' => array_merge( $exclude, $ids ) ) ) );
			$ids = array_merge( $ids, array_map( 'intval', $q->posts ) );
		}
		set_transient( $key, $ids, 30 * MINUTE_IN_SECONDS );
	}
	return array_values( array_filter( array_map( 'get_post', $ids ) ) );
}

/* Drop the cached pool whenever posts change. */
add_action(
	'save_post_post',
	function ( $id ) {
		foreach ( array( 4, 5, 6, 7, 8, 9 ) as $n ) {
			delete_transient( 'meeha_rel_' . $id . '_' . $n );
		}
	}
);

/**
 * Reaction definitions for a variant: type => label.
 *
 * @param string $variant Variant.
 * @return array [question, [type => label]]
 */
function meeha_single_reaction_set( $variant ) {
	if ( 'opinion' === $variant ) {
		return array(
			meeha_single_opt( 'react_q_opinion', 'লেখাটির সঙ্গে আপনি কতটা একমত?' ),
			array(
				'agree'    => meeha_single_opt( 'react_agree', 'একমত' ),
				'partly'   => meeha_single_opt( 'react_partly', 'আংশিক একমত' ),
				'disagree' => meeha_single_opt( 'react_disagree', 'একমত নই' ),
			),
		);
	}
	if ( 'literature' === $variant ) {
		return array(
			meeha_single_opt( 'react_q_lit', 'লেখাটি ছুঁয়ে গেলে জানান' ),
			array( 'love' => meeha_single_opt( 'react_love', 'ভালো লেগেছে' ) ),
		);
	}
	return array(
		meeha_single_opt( 'react_q_news', 'প্রতিবেদনটি আপনার কাছে কেমন লাগল?' ),
		array(
			'important'   => meeha_single_opt( 'react_important', 'গুরুত্বপূর্ণ' ),
			'informative' => meeha_single_opt( 'react_informative', 'তথ্যবহুল' ),
			'worrying'    => meeha_single_opt( 'react_worrying', 'উদ্বেগজনক' ),
			'disagree'    => meeha_single_opt( 'react_disagree', 'একমত নই' ),
		),
	);
}

/**
 * Reactions block. Counts come from `_meeha_reactions`; JS (single.js) calls MeehaMetrics.react / REST meeha/v1/react.
 *
 * @param WP_Post $post    Post.
 * @param string  $variant Variant.
 */
function meeha_single_reactions( $post, $variant ) {
	list( $q, $set ) = meeha_single_reaction_set( $variant );
	$counts          = get_post_meta( $post->ID, '_meeha_reactions', true );
	$counts          = is_array( $counts ) ? $counts : array();
	echo '<div class="m-reactions" data-reactions data-post-id="' . (int) $post->ID . '" data-endpoint="' . esc_url( rest_url( 'meeha/v1/react' ) ) . '">';
	echo '<p class="m-reactions__q" id="m-react-q">' . esc_html( $q ) . '</p>';
	echo '<div class="m-reactions__row" role="group" aria-labelledby="m-react-q">';
	foreach ( $set as $type => $label ) {
		$n = isset( $counts[ $type ] ) ? max( 0, (int) $counts[ $type ] ) : 0;
		printf(
			'<button class="m-react" type="button" aria-pressed="false" data-react="%1$s">%2$s%3$s <b data-count="%4$d">%5$s</b></button>',
			esc_attr( $type ),
			in_array( $type, array( 'important', 'love' ), true ) ? meeha_icon( 'heart', array( 'size' => 'sm' ) ) : '', // phpcs:ignore WordPress.Security.EscapeOutput
			esc_html( $label ),
			$n, // phpcs:ignore WordPress.Security.EscapeOutput
			esc_html( meeha_bn_number_short( $n ) )
		);
	}
	echo '</div></div>';
}

/**
 * Author avatar <img> (B's avatar override applies through get_avatar_url()).
 *
 * @param int    $user_id User.
 * @param int    $size    Px.
 * @param string $alt     Alt.
 * @param bool   $eager   Eager load.
 * @return string
 */
function meeha_single_avatar( $user_id, $size = 48, $alt = '', $eager = false ) {
	$url = get_avatar_url( (int) $user_id, array( 'size' => $size * 2 ) );
	if ( ! $url ) {
		return '';
	}
	return sprintf( '<img src="%s" width="%d" height="%d" alt="%s"%s decoding="async">', esc_url( $url ), $size, $size, esc_attr( $alt ), $eager ? '' : ' loading="lazy"' );
}

/**
 * "৬ অক্টোবর ২০২৬, ০৯:১৫" for a timestamp.
 *
 * @param int  $ts       Unix time.
 * @param bool $with_day Include date.
 * @return string
 */
function meeha_single_when( $ts, $with_day = true ) {
	$time = meeha_bn_digits( wp_date( 'H:i', $ts ) );
	if ( ! $with_day ) {
		return $time;
	}
	return meeha_bn_digits( wp_date( 'j', $ts ) ) . ' ' . meeha_bengali_month( (int) wp_date( 'n', $ts ) ) . ' ' . meeha_bn_digits( wp_date( 'Y', $ts ) ) . ', ' . $time;
}

/**
 * Meta row: প্রকাশ | হালনাগাদ | reading time.
 *
 * @param WP_Post $post Post.
 * @param array   $args prefix (extra span text).
 */
function meeha_single_meta_row( $post, $args = array() ) {
	$pub = (int) get_post_time( 'U', true, $post );
	$mod = (int) get_post_modified_time( 'U', true, $post );
	echo '<div class="m-meta">';
	if ( ! empty( $args['prefix'] ) ) {
		echo '<span>' . esc_html( $args['prefix'] ) . '</span>';
	}
	printf( '<span>প্রকাশ: <time datetime="%s">%s</time></span>', esc_attr( get_post_time( 'c', true, $post ) ), esc_html( meeha_single_when( $pub ) ) );
	if ( meeha_single_opt( 'show_updated', 1 ) && $mod - $pub > 10 * MINUTE_IN_SECONDS ) {
		$same = wp_date( 'Ymd', $mod ) === wp_date( 'Ymd', $pub );
		printf( '<span>হালনাগাদ: <time datetime="%s">%s</time></span>', esc_attr( get_post_modified_time( 'c', true, $post ) ), esc_html( meeha_single_when( $mod, ! $same ) ) );
	}
	echo '<span>' . esc_html( meeha_reading_time_label( $post ) ) . '</span>';
	echo '</div>';
}

/**
 * News byline: photo, name (link), designation, meta row.
 *
 * @param WP_Post $post Post.
 */
function meeha_single_byline( $post ) {
	$by   = meeha_post_byline( $post, 48 ); // 2.0.3: guest author (অতিথি লেখক) aware.
	$name = $by['name'];
	$role = $by['title'];
	echo '<div class="m-byline' . ( $by['is_guest'] ? ' m-byline--guest' : '' ) . '">';
	echo '<span class="m-byline__photo">' . meeha_byline_photo_html( $by, 48, '', true ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput
	echo '<p class="m-byline__who">' . ( $by['url'] ? '<a href="' . esc_url( $by['url'] ) . '" rel="author">' . esc_html( $name ) . '</a>' : '<span class="m-byline__name" style="font-weight:600">' . esc_html( $name ) . '</span>' );
	if ( '' !== $role ) {
		echo '<span class="m-byline__role">' . esc_html( $role ) . '</span>';
	}
	echo '</p>';
	meeha_single_meta_row( $post );
	echo '</div>';
}

/**
 * Header tools: share + "সারাংশ পড়ুন" + A−/A+.
 *
 * @param WP_Post $post    Post.
 * @param array   $args    extra (raw HTML appended inside the tool group, escaped by caller).
 */
function meeha_single_tools( $post, $args = array() ) {
	echo '<div class="m-article__tools">';
	meeha_share_buttons( $post, array( 'networks' => meeha_single_share_networks() ) );
	echo '<div class="m-article__actions">';
	if ( meeha_single_opt( 'summary_button', 1 ) && '' !== trim( (string) meeha_get_summary( $post->ID ) ) ) {
		echo '<button class="m-btn m-btn--ghost m-sumbtn" type="button" data-summary-open="m-summary" aria-haspopup="dialog">' . meeha_icon( 'sparkle', array( 'size' => 'sm' ) ) . '<span>সারাংশ পড়ুন</span></button>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	if ( ! empty( $args['extra'] ) ) {
		echo $args['extra']; // phpcs:ignore WordPress.Security.EscapeOutput -- built by the caller with escaping.
	}
	meeha_single_fontsize();
	echo '</div></div>';
}

/**
 * A−/A+ font-size controls (scale 0.85–1.4, step from settings; persisted in localStorage meeha_font_scale).
 */
function meeha_single_fontsize() {
	$step = max( 1, min( 30, (int) meeha_single_opt( 'font_step', 10 ) ) );
	echo '<div class="m-fontsize" role="group" aria-label="লেখার আকার" data-font-step="' . esc_attr( $step ) . '">';
	echo '<button type="button" class="m-fontsize__btn" data-font="-" aria-label="লেখা ছোট করুন" title="লেখা ছোট করুন">A<span aria-hidden="true">−</span></button>';
	echo '<button type="button" class="m-fontsize__btn m-fontsize__btn--up" data-font="+" aria-label="লেখা বড় করুন" title="লেখা বড় করুন">A<span aria-hidden="true">+</span></button>';
	echo '</div>';
}

/**
 * Summary <dialog> (only when `_meeha_summary` exists and the setting is on).
 *
 * @param WP_Post $post Post.
 */
function meeha_single_summary_dialog( $post ) {
	$summary = trim( (string) meeha_get_summary( $post->ID ) );
	if ( '' === $summary || ! meeha_single_opt( 'summary_button', 1 ) ) {
		return;
	}
	$title = wp_strip_all_tags( get_the_title( $post ) );
	?>
	<dialog class="m-sumdlg" id="m-summary" aria-labelledby="m-summary-h">
		<div class="m-sumdlg__head">
			<h2 class="m-sumdlg__title" id="m-summary-h"><?php echo meeha_icon( 'sparkle' ); // phpcs:ignore ?>সারাংশ</h2>
			<button class="m-iconbtn" type="button" data-close aria-label="বন্ধ করুন"><?php echo meeha_icon( 'close' ); // phpcs:ignore ?></button>
		</div>
		<div class="m-sumdlg__body">
			<p class="m-sumdlg__for"><?php echo esc_html( $title ); ?></p>
			<div class="m-sumdlg__text" id="m-summary-text"><?php echo wp_kses_post( wpautop( esc_html( $summary ) ) ); ?></div>
		</div>
		<div class="m-sumdlg__actions">
			<button class="m-btn m-btn--ghost" type="button" data-summary-copy><?php echo meeha_icon( 'link', array( 'size' => 'sm' ) ); // phpcs:ignore ?><span>সারাংশ কপি করুন</span></button>
			<button class="m-btn m-btn--ghost" type="button" data-summary-share data-url="<?php echo esc_url( get_permalink( $post ) ); ?>" data-title="<?php echo esc_attr( $title ); ?>"><?php echo meeha_icon( 'share', array( 'size' => 'sm' ) ); // phpcs:ignore ?><span>শেয়ার করুন</span></button>
			<button class="m-btn" type="button" data-close>পুরো খবর পড়ুন</button>
		</div>
	</dialog>
	<?php
}

/**
 * "ফটোকার্ড ডাউনলোড" icon button for the lead image (E's cards.js, lazy).
 *
 * @param WP_Post $post Post.
 * @return string
 */
function meeha_single_card_button( $post ) {
	if ( ! meeha_single_opt( 'card_button', 1 ) || ! function_exists( 'meeha_cards_news_payload' ) || ! has_post_thumbnail( $post ) ) {
		return '';
	}
	$pl = meeha_cards_news_payload( $post );
	if ( empty( $pl['src'] ) ) {
		return '';
	}
	return '<button class="m-cardbtn" type="button" aria-label="ফটোকার্ড ডাউনলোড" aria-describedby="m-cardbtn-tip" data-card="' . esc_attr( wp_json_encode( $pl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) . '">'
		. meeha_icon( 'download' ) . '<span class="m-cardbtn__spin" aria-hidden="true"></span>'
		. '<span class="m-cardbtn__tip" role="tooltip" id="m-cardbtn-tip">ফটোকার্ড ডাউনলোড</span></button>'
		. '<span class="m-vh" role="status" aria-live="polite" data-card-status></span>';
}

/**
 * Lead figure (role 'lead' = LCP image) with caption/credit and the card button.
 *
 * @param WP_Post $post    Post.
 * @param array   $args    viewfinder (bool), class.
 */
function meeha_single_lead_figure( $post, $args = array() ) {
	$id = (int) get_post_thumbnail_id( $post );
	if ( ! $id ) {
		return;
	}
	$alt = trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) );
	$img = meeha_img( $id, 'lead', array( 'alt' => '' !== $alt ? $alt : wp_strip_all_tags( get_the_title( $post ) ) ) );
	if ( '' === $img ) {
		return;
	}
	$caption = trim( (string) wp_get_attachment_caption( $id ) );
	$credit  = '';
	// "…। ছবি: X" → caption + credit; a caption that is only the credit becomes the credit.
	if ( preg_match( '/^(.*?)[\s।.|—-]*\s*(ছবি\s*[:：].+)$/u', $caption, $m ) ) {
		$caption = trim( $m[1] );
		$credit  = trim( $m[2] );
	}
	echo '<figure class="m-figure m-article__lead' . ( ! empty( $args['class'] ) ? ' ' . esc_attr( $args['class'] ) : '' ) . '">';
	echo '<div class="m-media m-figure__media' . ( ! empty( $args['viewfinder'] ) ? ' m-viewfinder' : '' ) . '">' . $img . meeha_single_card_button( $post ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
	if ( '' !== $caption || '' !== $credit ) {
		echo '<figcaption>';
		if ( '' !== $caption ) {
			echo '<span>' . esc_html( $caption ) . '</span>';
		}
		if ( '' !== $credit ) {
			echo '<span class="m-figure__credit">' . esc_html( $credit ) . '</span>';
		}
		echo '</figcaption>';
	}
	echo '</figure>';
}

/**
 * Table of contents.
 *
 * @param array  $toc  Items [id, text].
 * @param string $mode details|aside.
 */
function meeha_single_toc( $toc, $mode = 'details' ) {
	if ( ! $toc ) {
		return;
	}
	$items = '';
	foreach ( $toc as $t ) {
		$items .= '<li><a href="#' . esc_attr( $t[0] ) . '">' . esc_html( $t[1] ) . '</a></li>';
	}
	if ( 'aside' === $mode ) {
		echo '<nav class="m-toc m-aside-toc" aria-label="এই প্রতিবেদনে"><p class="m-toc__title">এই প্রতিবেদনে</p><ol>' . $items . '</ol></nav>'; // phpcs:ignore WordPress.Security.EscapeOutput
	} else {
		echo '<details class="m-toc"><summary>এই প্রতিবেদনে' . meeha_icon( 'chev-d' ) . '</summary><ol>' . $items . '</ol></details>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
}

/**
 * Whether the TOC shows: ≥ 3 h2 or investigative (with at least one h2).
 *
 * @param array  $toc     Items.
 * @param string $variant Variant.
 * @return bool
 */
function meeha_single_show_toc( $toc, $variant ) {
	$n = count( $toc );
	return $n >= 3 || ( 'investigative' === $variant && $n >= 1 );
}

/**
 * Tags as chips.
 *
 * @param WP_Post $post Post.
 */
function meeha_single_tags( $post ) {
	$tags = get_the_tags( $post->ID );
	if ( ! $tags || is_wp_error( $tags ) ) {
		return;
	}
	echo '<div class="m-tags" aria-label="ট্যাগ">';
	foreach ( $tags as $t ) {
		echo '<a class="m-chip" href="' . esc_url( get_tag_link( $t ) ) . '" rel="tag">' . esc_html( $t->name ) . '</a>';
	}
	echo '</div>';
}

/**
 * Author box.
 *
 * @param WP_Post $post    Post.
 * @param string  $variant Variant.
 */
function meeha_single_author_box( $post, $variant ) {
	if ( 'opinion' === $variant && meeha_single_is_editorial( $post ) ) {
		return;
	}
	$by    = meeha_post_byline( $post, 72 ); // 2.0.3: a guest has no bio and no "সব লেখা" archive.
	$uid   = $by['user_id'];
	$bio   = $uid ? trim( (string) get_the_author_meta( 'description', $uid ) ) : '';
	$name  = $by['name'];
	$role  = $by['title'];
	$count = $uid ? (int) count_user_posts( $uid, 'post', true ) : 0;
	echo '<div class="m-authorbox' . ( $by['is_guest'] ? ' m-authorbox--guest' : '' ) . '">';
	echo '<span class="m-authorbox__photo">' . meeha_byline_photo_html( $by, 72 ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput
	echo '<p class="m-authorbox__name">' . esc_html( $name ) . '</p>';
	echo '<p class="m-authorbox__role">' . esc_html( '' !== $role ? $role : ( $by['is_guest'] ? 'অতিথি লেখক' : 'মিহা নিউজ' ) ) . '</p>';
	echo '<div>';
	if ( '' !== $bio ) {
		echo '<p>' . esc_html( $bio ) . '</p>';
	}
	echo '<p class="m-authorbox__links">';
	if ( $by['url'] ) {
		echo '<a href="' . esc_url( $by['url'] ) . '">' . esc_html( 'সব লেখা' . ( $count > 1 ? ' (' . meeha_bn_digits( $count ) . ')' : '' ) ) . '</a>';
	}
	$tips = (string) meeha_single_opt( 'tips_email', '' );
	if ( 'investigative' === $variant && is_email( $tips ) ) {
		echo '<a href="mailto:' . esc_attr( antispambot( $tips ) ) . '">' . esc_html( antispambot( $tips ) ) . '</a>';
	}
	echo '</p></div></div>';
}

/**
 * Correction note box with B's compact report button inside (CONTRACT §9.1).
 *
 * @param WP_Post $post Post.
 */
function meeha_single_correction( $post ) {
	$text = trim( (string) meeha_single_opt( 'correction_text', MEEHA_CORRECTION_TEXT ) );
	if ( '' === $text ) {
		$text = MEEHA_CORRECTION_TEXT;
	}
	$html = esc_html( $text );
	$url  = meeha_single_policy_url();
	if ( $url && false !== strpos( $html, 'সংশোধনী নীতি' ) ) {
		$html = preg_replace( '/সংশোধনী নীতি/u', '<a href="' . esc_url( $url ) . '">সংশোধনী নীতি</a>', $html, 1 );
	}
	echo '<div class="m-correction"><p class="m-correction__text">' . $html . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
	if ( function_exists( 'meeha_render_report_button' ) ) {
		meeha_render_report_button( $post->ID, array( 'variant' => 'compact' ) );
	}
	echo '</div>';
}

/**
 * Bundle asset URL (dist when built, else source) — for lazily injected CSS/JS.
 *
 * @param string $bundle Bundle.
 * @param string $type   css|js.
 * @return string
 */
function meeha_d_bundle_url( $bundle, $type ) {
	$m = function_exists( 'meeha_manifest' ) ? meeha_manifest() : array();
	if ( empty( $m['dev'] ) && ! empty( $m['bundles'][ $bundle ][ $type . '_file' ] ) ) {
		$ver = isset( $m['bundles'][ $bundle ][ $type . '_ver' ] ) ? $m['bundles'][ $bundle ][ $type . '_ver' ] : MEEHA_VERSION;
		return MEEHA_URI . '/assets/dist/' . $m['bundles'][ $bundle ][ $type . '_file' ] . '?ver=' . rawurlencode( (string) $ver );
	}
	$src = MEEHA_DIR . '/assets/' . $type . '/' . $bundle . '.' . $type;
	return MEEHA_URI . '/assets/' . $type . '/' . $bundle . '.' . $type . '?ver=' . rawurlencode( (string) ( file_exists( $src ) ? filemtime( $src ) : MEEHA_VERSION ) );
}

/**
 * Collapsed comments card. The thread is server-rendered (crawlable) but hidden until the reader asks;
 * comments CSS/JS (+ comment-reply) are then injected by single.js.
 *
 * @param WP_Post $post    Post.
 * @param string  $variant Variant.
 */
function meeha_single_comments_card( $post, $variant = 'news' ) {
	if ( post_password_required( $post ) || ( ! comments_open( $post ) && ! get_comments_number( $post ) ) ) {
		return;
	}
	$n         = (int) get_comments_number( $post );
	$collapsed = (bool) meeha_single_opt( 'comments_collapsed', 1 );
	$head      = (string) meeha_single_opt( 'comments_heading', 'মন্তব্য' );
	$note      = 'opinion' === $variant ? 'মতামতের জবাবে মতামত দিন, সম্মান রেখে।' : (string) meeha_single_opt( 'comments_note', '' );
	$reply_js  = ( get_option( 'thread_comments' ) && comments_open( $post ) ) ? includes_url( 'js/comment-reply.min.js' ) : '';
	printf(
		'<section class="m-comments%1$s" id="m-comments" aria-labelledby="m-comments-h" data-comments data-css="%2$s" data-js="%3$s" data-reply="%4$s">',
		$collapsed ? '' : ' is-open',
		esc_url( meeha_d_bundle_url( 'comments', 'css' ) ),
		esc_url( meeha_d_bundle_url( 'comments', 'js' ) ),
		esc_url( $reply_js )
	);
	echo '<h2 class="m-comments__title" id="m-comments-h">' . esc_html( $head . ( $n ? ' (' . meeha_bn_digits( $n ) . ')' : '' ) ) . '</h2>';
	if ( $collapsed ) {
		if ( '' !== $note ) {
			echo '<p class="m-comments__note">' . esc_html( $note ) . '</p>';
		}
		echo '<button class="m-btn m-btn--ghost m-comments__open" type="button" aria-expanded="false" aria-controls="m-comments-thread" data-comments-open>' . meeha_icon( 'comment', array( 'size' => 'sm' ) ) . '<span>' . esc_html( $n ? 'মন্তব্য দেখুন ও লিখুন' : 'মন্তব্য লিখুন' ) . '</span></button>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	echo '<div class="m-comments__thread" id="m-comments-thread"' . ( $collapsed ? ' hidden' : '' ) . '>';
	$GLOBALS['meeha_comments_lazy'] = $collapsed; // the card's JS loads CSS/JS on demand.
	comments_template();
	$GLOBALS['meeha_comments_lazy'] = false;
	echo '</div>';
	if ( $collapsed ) {
		echo '<noscript><style>#m-comments-thread{display:block!important}.m-comments__open{display:none!important}</style><link rel="stylesheet" href="' . esc_url( meeha_d_bundle_url( 'comments', 'css' ) ) . '"></noscript>';
	}
	echo '</section>';
}

/**
 * Most-read list for the sidebar (C's meeha_get_most_read() when available, else a cached views query).
 *
 * @param WP_Post $post  Current post (excluded).
 * @param int     $count Count.
 * @return WP_Post[]
 */
function meeha_single_most_read( $post, $count = 4 ) {
	if ( function_exists( 'meeha_get_most_read' ) ) {
		$list = meeha_get_most_read( '7d', $count + 1, array( 'exclude' => array( $post->ID ) ) );
		$list = array_values(
			array_filter(
				(array) $list,
				function ( $p ) use ( $post ) {
					return $p instanceof WP_Post && (int) $p->ID !== (int) $post->ID;
				}
			)
		);
		return array_slice( $list, 0, $count );
	}
	$ids = get_transient( 'meeha_d_mostread' );
	if ( ! is_array( $ids ) ) {
		$q   = new WP_Query(
			array(
				'post_type'           => 'post',
				'posts_per_page'      => 10,
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
				'fields'              => 'ids',
				'meta_key'            => '_meeha_views', // phpcs:ignore WordPress.DB.SlowDBQuery
				'orderby'             => 'meta_value_num',
				'order'               => 'DESC',
				'date_query'          => array( array( 'after' => '30 days ago' ) ),
			)
		);
		$ids = array_map( 'intval', $q->posts );
		set_transient( 'meeha_d_mostread', $ids, 10 * MINUTE_IN_SECONDS );
	}
	$ids = array_diff( $ids, array( (int) $post->ID ) );
	return array_slice( array_values( array_filter( array_map( 'get_post', $ids ) ) ), 0, $count );
}

/**
 * Ranked most-read block.
 *
 * @param WP_Post $post Current.
 */
function meeha_single_most_read_block( $post, $count = 4, $title = 'সর্বাধিক পঠিত' ) {
	$list = meeha_single_most_read( $post, $count );
	if ( ! $list ) {
		return;
	}
	echo '<div class="m-mostread">';
	meeha_sechead( $title );
	echo '<ol class="m-rank" role="list">';
	foreach ( $list as $p ) {
		echo '<li><div><a href="' . esc_url( get_permalink( $p ) ) . '">' . esc_html( get_the_title( $p ) ) . '</a></div></li>';
	}
	echo '</ol></div>';
}

/* --------------------------------------------------------------------------
 * Desktop sidebar (≥ 1024 px): configurable blocks (settings › একক পোস্ট › সাইডবার). Only the last block is
 * sticky (with the long-read TOC, when there is one); phones keep the below-article placements.
 * ------------------------------------------------------------------------ */

/**
 * Enabled sidebar blocks in order.
 *
 * @return array id => label
 */
function meeha_single_sidebar_blocks() {
	$out = array();
	foreach ( (array) meeha_single_opt( 'sidebar_blocks', array() ) as $b ) {
		if ( ! empty( $b['enabled'] ) && ! empty( $b['id'] ) ) {
			$out[ (string) $b['id'] ] = isset( $b['label'] ) ? (string) $b['label'] : '';
		}
	}
	return $out;
}

/**
 * Newest published post ids (cached 5 minutes, invalidated with the homepage cache generation).
 *
 * @param int $n Count.
 * @return int[]
 */
function meeha_single_latest_ids( $n ) {
	$key = 'meeha_d_latest_' . ( function_exists( 'meeha_home_gen' ) ? meeha_home_gen() : 0 );
	$ids = get_transient( $key );
	if ( ! is_array( $ids ) ) {
		$q   = new WP_Query(
			array(
				'post_type'           => 'post',
				'post_status'         => 'publish',
				'has_password'        => false,
				'posts_per_page'      => 12,
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
				'fields'              => 'ids',
			)
		);
		$ids = array_map( 'intval', $q->posts );
		set_transient( $key, $ids, 5 * MINUTE_IN_SECONDS );
	}
	return array_slice( $ids, 0, $n );
}

/**
 * Is the post in one of the configured area-news categories (children included)?
 *
 * @param WP_Post $post Post.
 * @return bool
 */
function meeha_single_wants_area( $post ) {
	$cats = array_filter( array_map( 'intval', (array) meeha_single_opt( 'sidebar_area_cats', array() ) ) );
	if ( ! $cats || ! function_exists( 'meeha_render_area_widget' ) ) {
		return false;
	}
	$tree = $cats;
	foreach ( $cats as $c ) {
		$kids = get_term_children( $c, 'category' );
		if ( is_array( $kids ) ) {
			$tree = array_merge( $tree, array_map( 'intval', $kids ) );
		}
	}
	return (bool) array_intersect( $tree, array_map( 'intval', wp_get_post_categories( $post->ID ) ) );
}

/**
 * HTML of one sidebar block ('' when it has nothing to show).
 *
 * @param string  $id    Block id.
 * @param string  $label Heading.
 * @param WP_Post $post  Current post.
 * @return string
 */
function meeha_single_sidebar_block( $id, $label, $post ) {
	ob_start();
	switch ( $id ) {
		case 'latest':
			$n   = max( 3, min( 10, (int) meeha_single_opt( 'sidebar_latest_count', 6 ) ) );
			$ids = array_values( array_diff( meeha_single_latest_ids( $n + 1 ), array( (int) $post->ID ) ) );
			$ids = array_slice( $ids, 0, $n );
			if ( ! $ids ) {
				break;
			}
			_prime_post_caches( $ids, false, false );
			echo '<section class="m-aside-block m-aside-d" aria-label="' . esc_attr( $label ) . '">';
			meeha_sechead( '' !== $label ? $label : 'সর্বশেষ', array( 'modifier' => 'sm', 'url' => function_exists( 'meeha_home_page_with' ) ? meeha_home_page_with( 'meeha_latest_feed' ) : '' ) );
			echo '<ol class="m-latest" role="list">';
			foreach ( $ids as $pid ) {
				$p = get_post( $pid );
				if ( $p ) {
					echo '<li><time datetime="' . esc_attr( get_post_time( 'c', true, $p ) ) . '">' . esc_html( meeha_post_clock( $p ) ) . '</time><a href="' . esc_url( get_permalink( $p ) ) . '">' . esc_html( get_the_title( $p ) ) . '</a></li>';
				}
			}
			echo '</ol></section>';
			break;
		case 'trending':
			if ( ! function_exists( 'meeha_get_trending' ) ) {
				break;
			}
			$n     = max( 3, min( 8, (int) meeha_single_opt( 'sidebar_trending_count', 5 ) ) );
			$items = array();
			foreach ( (array) meeha_get_trending( min( 8, $n + 1 ) ) as $it ) {
				if ( isset( $it['post'] ) && (int) $it['post']->ID !== (int) $post->ID ) {
					$items[] = $it;
				}
			}
			$items = array_slice( $items, 0, $n );
			if ( ! $items ) {
				break;
			}
			echo '<section class="m-aside-block m-aside-d m-aside-trend" aria-label="' . esc_attr( $label ) . '">';
			meeha_sechead( '' !== $label ? $label : 'ট্রেন্ডিং', array( 'modifier' => 'sm' ) );
			echo '<ol class="m-aside-trend__list" role="list">';
			foreach ( $items as $it ) {
				echo '<li class="m-aside-trend__item">'
					. ( function_exists( 'meeha_spark_svg' ) ? meeha_spark_svg( $it['spark'] ) : '' ) // phpcs:ignore WordPress.Security.EscapeOutput
					. '<a class="m-aside-trend__title" href="' . esc_url( get_permalink( $it['post'] ) ) . '">' . esc_html( get_the_title( $it['post'] ) ) . '</a>'
					. ( function_exists( 'meeha_home_trend_rate' ) ? meeha_home_trend_rate( $it ) : '' ) // phpcs:ignore WordPress.Security.EscapeOutput
					. '</li>';
			}
			echo '</ol></section>';
			break;
		case 'mostread':
			meeha_single_most_read_block( $post, max( 3, min( 10, (int) meeha_single_opt( 'sidebar_mostread_count', 5 ) ) ), '' !== $label ? $label : 'সর্বাধিক পঠিত' );
			break;
		case 'area':
			if ( meeha_single_wants_area( $post ) ) {
				$html = meeha_render_area_widget(
					array(
						'title'   => '' !== $label ? $label : 'এলাকার খবর খুঁজুন',
						'count'   => 4,
						'heading' => 'h2',
						'id'      => 'm-area-side',
						'class'   => 'm-aside-d',
						'echo'    => false,
					)
				);
				echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- built and escaped by B.
			}
			break;
		case 'ad':
			echo meeha_ad( 'article_side' ); // phpcs:ignore WordPress.Security.EscapeOutput -- built in meeha_ad().
			break;
	}
	return trim( (string) ob_get_clean() );
}

/**
 * Print the configured sidebar. Blocks before the last scroll normally; the last one (plus the long-read TOC)
 * sticks below the header.
 *
 * @param WP_Post $post Current post.
 * @param array   $toc  TOC items ([] = none).
 */
function meeha_single_sidebar( $post, $toc = array() ) {
	$html = array();
	foreach ( meeha_single_sidebar_blocks() as $id => $label ) {
		$h = meeha_single_sidebar_block( $id, $label, $post );
		if ( '' !== $h ) {
			$html[] = $h;
		}
	}
	$last = array_pop( $html );
	foreach ( $html as $h ) {
		echo $h; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped per block.
	}
	ob_start();
	meeha_single_toc( $toc, 'aside' );
	$toc_html = trim( (string) ob_get_clean() );
	if ( '' !== $toc_html || null !== $last ) {
		echo '<div class="m-article__aside-sticky">' . $toc_html . (string) $last . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
}

/**
 * Sidebar "আরও মতামত" (opinion variant).
 *
 * @param WP_Post $post Current.
 */
function meeha_single_more_opinion( $post ) {
	$cats = array();
	foreach ( array( 'editorial', 'sub-editorial', 'readers-words' ) as $slug ) {
		$t = get_category_by_slug( $slug );
		if ( $t ) {
			$cats[] = (int) $t->term_id;
		}
	}
	if ( ! $cats ) {
		return;
	}
	$q = new WP_Query(
		array(
			'post_type'           => 'post',
			'posts_per_page'      => 3,
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
			'category__in'        => $cats,
			'post__not_in'        => array( $post->ID ),
		)
	);
	if ( ! $q->have_posts() ) {
		return;
	}
	echo '<div class="m-mostread">';
	meeha_sechead( 'আরও মতামত' );
	echo '<ul class="m-morefrom" role="list">';
	foreach ( $q->posts as $p ) {
		$ed  = meeha_single_is_editorial( $p );
		$by  = meeha_post_byline( $p, 64 );
		echo '<li class="m-columnist">';
		echo '<span class="m-columnist__photo">' . ( $ed ? meeha_icon( 'quote' ) : meeha_byline_photo_html( $by, 64 ) ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<span class="m-columnist__name">' . esc_html( $ed ? 'সম্পাদকীয়' : $by['name'] ) . '</span>';
		echo '<span class="m-columnist__role">' . esc_html( $ed ? 'মিহা নিউজ' : $by['title'] ) . '</span>';
		echo '<h3 class="m-columnist__title"><a href="' . esc_url( get_permalink( $p ) ) . '">' . esc_html( get_the_title( $p ) ) . '</a></h3>';
		echo '</li>';
	}
	echo '</ul></div>';
}

/**
 * "এ বিষয়ে আরও" band.
 *
 * @param WP_Post[] $posts Posts.
 * @param string    $title Title.
 */
function meeha_single_related_section( $posts, $title = 'এ বিষয়ে আরও' ) {
	if ( ! $posts ) {
		return;
	}
	echo '<section class="m-section m-section--band m-related" aria-labelledby="m-rel-h"><div class="m-container">';
	meeha_sechead( $title, array( 'id' => 'm-rel-h' ) );
	echo '<div class="m-grid m-grid--4">';
	foreach ( $posts as $p ) {
		meeha_card( $p, array( 'variant' => 'sm rowm' ) );
	}
	echo '</div></div></section>';
}

/**
 * Dek under the H1: the whole summary, clamped to 2 lines (mobile) / 3 lines (desktop) by CSS once the <head>
 * script marked <html class="m-dekjs">; single.js shows the "+" / "−" toggle only when the text is really clamped.
 * Without JS the full text shows.
 *
 * @param string $text    Plain text.
 * @param int    $post_id Post.
 * @return string
 */
function meeha_single_dek_html( $text, $post_id ) {
	$text = trim( (string) $text );
	if ( '' === $text ) {
		return '';
	}
	$id = 'm-dek-' . (int) $post_id;
	return '<div class="m-dek" data-dek><p class="m-article__dek m-dek__text" id="' . esc_attr( $id ) . '">' . esc_html( $text ) . '</p>'
		. '<button type="button" class="m-dek__toggle" aria-expanded="false" aria-controls="' . esc_attr( $id ) . '" aria-label="সম্পূর্ণ সারাংশ" title="সম্পূর্ণ সারাংশ">'
		. '<svg class="m-dek__plus" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path d="M12 5v14M5 12h14" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"/></svg>'
		. '<svg class="m-dek__minus" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path d="M5 12h14" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"/></svg>'
		. '</button></div>';
}

/**
 * Article labels (investigative, breaking).
 *
 * @param WP_Post $post    Post.
 * @param string  $variant Variant.
 */
function meeha_single_labels( $post, $variant ) {
	$out = '';
	if ( 'investigative' === $variant ) {
		$out .= '<span class="m-label m-label--inv">মিহা অনুসন্ধান</span>';
	}
	$br_at = (int) get_post_meta( $post->ID, '_meeha_breaking_at', true );
	if ( '1' === (string) get_post_meta( $post->ID, '_meeha_breaking', true ) && $br_at && time() - $br_at < 6 * HOUR_IN_SECONDS ) {
		$out .= '<span class="m-label m-label--live">ব্রেকিং</span>';
	}
	$kicker = trim( (string) get_post_meta( $post->ID, '_meeha_topic', true ) );
	if ( '' !== $kicker ) {
		$out .= '<span class="m-meta m-meta--plain"><span>' . esc_html( $kicker ) . '</span></span>';
	}
	if ( '' !== $out ) {
		echo '<div class="m-article__labels">' . $out . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
	}
}

/**
 * Render the processed article body (TOC ids, ads, read-also) once and memoise it for the template parts.
 *
 * @param WP_Post $post    Post.
 * @param string  $variant Variant.
 * @param array   $pool    Related pool (first = read-also).
 * @return array html, toc, inserted
 */
function meeha_single_body( $post, $variant, $pool = array() ) {
	static $cache = array();
	if ( isset( $cache[ $post->ID ] ) ) {
		return $cache[ $post->ID ];
	}
	$html = apply_filters( 'the_content', get_the_content( null, false, $post ) );
	$html = str_replace( ']]>', ']]&gt;', (string) $html );
	if ( 'literature' === $variant ) {
		$genre = function_exists( 'meeha_lit_genre_of' ) ? meeha_lit_genre_of( $post ) : '';
		if ( 'poem' === $genre ) {
			$cache[ $post->ID ] = array(
				'html'     => meeha_lit_poem_html( $html ),
				'toc'      => array(),
				'inserted' => array(),
			);
			return $cache[ $post->ID ];
		}
	}
	$cache[ $post->ID ] = meeha_single_process_content(
		$html,
		$post,
		array(
			'variant'       => $variant,
			'readalso_post' => ( 'literature' !== $variant && $pool ) ? $pool[0] : null,
			'ads'           => 'literature' !== $variant,
		)
	);
	return $cache[ $post->ID ];
}
