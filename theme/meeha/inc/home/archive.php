<?php
/**
 * Archives (category / tag / author / date / paged home): 12 posts per page (page 1 = lead 1+2 + grid 9,
 * later pages = grid 12), header with breadcrumb + chips + count (found_posts — children included),
 * aside with "{বিভাগ}-এ বেশি পঠিত" + archive_side ad, progressive "আরও খবর দেখুন" via cacheable HTML
 * fragments (`?meeha_fragment=1` on the next page's own URL) and a Bengali numeric pager for crawlers.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

/**
 * Archive views handled by archive.php (quote archives/taxonomy are D's).
 *
 * @param WP_Query|null $q Query.
 * @return bool
 */
function meeha_archive_is_ours( $q = null ) {
	$q = $q ? $q : $GLOBALS['wp_query'];
	if ( $q->is_post_type_archive( array( 'meeha_photocard', 'meeha_quote' ) ) || $q->is_tax( 'meeha_quote_type' ) ) {
		return false;
	}
	return $q->is_category() || $q->is_tag() || $q->is_author() || $q->is_date() || ( $q->is_home() && $q->is_paged() );
}

/**
 * Posts per archive page.
 *
 * @return int
 */
function meeha_archive_per_page() {
	return (int) apply_filters( 'meeha_archive_per_page', 12 );
}

add_action(
	'pre_get_posts',
	function ( $q ) {
		if ( is_admin() || ! $q->is_main_query() || $q->is_feed() || $q->is_search() ) {
			return;
		}
		if ( meeha_archive_is_ours( $q ) ) {
			$q->set( 'posts_per_page', meeha_archive_per_page() );
			$q->set( 'ignore_sticky_posts', true );
		}
	}
);

/**
 * Is this a load-more fragment request?
 *
 * @return bool
 */
function meeha_archive_is_fragment() {
	return isset( $_GET['meeha_fragment'] ) && '1' === (string) $_GET['meeha_fragment']; // phpcs:ignore WordPress.Security.NonceVerification
}

/**
 * Remove the fragment flag from pagination links (pager / next link must stay clean).
 */
add_filter(
	'get_pagenum_link',
	function ( $url ) {
		return remove_query_arg( 'meeha_fragment', $url );
	}
);

/**
 * Bengali locative of a section name: মাতৃভূমি → মাতৃভূমিতে, খেলা → খেলায়, বিনোদন → বিনোদনে.
 *
 * @param string $name Name.
 * @return string
 */
function meeha_bn_locative( $name ) {
	$name = trim( (string) $name );
	if ( '' === $name ) {
		return $name;
	}
	$last = mb_substr( $name, -1 );
	if ( in_array( $last, array( 'া' ), true ) ) {
		return $name . 'য়';
	}
	if ( in_array( $last, array( 'ি', 'ী', 'ু', 'ূ', 'ৃ', 'ে', 'ৈ', 'ো', 'ৌ', 'অ', 'আ', 'ই', 'ঈ', 'উ', 'এ', 'ও' ), true ) ) {
		return $name . 'তে';
	}
	if ( preg_match( '/[\x{0995}-\x{09B9}\x{09CE}\x{09DC}-\x{09DF}]$/u', $name ) ) {
		return $name . 'ে';
	}
	return $name . '-এ';
}

/**
 * Bengali title for date archives.
 *
 * @return string
 */
function meeha_archive_date_title() {
	$y = (int) get_query_var( 'year' );
	$m = (int) get_query_var( 'monthnum' );
	$d = (int) get_query_var( 'day' );
	if ( ! $y && get_query_var( 'm' ) ) {
		$raw = (string) get_query_var( 'm' );
		$y   = (int) substr( $raw, 0, 4 );
		$m   = (int) substr( $raw, 4, 2 );
		$d   = (int) substr( $raw, 6, 2 );
	}
	if ( $d && $m ) {
		return meeha_bn_digits( $d ) . ' ' . meeha_bengali_month( $m ) . ' ' . meeha_bn_digits( $y );
	}
	if ( $m ) {
		return meeha_bengali_month( $m ) . ' ' . meeha_bn_digits( $y );
	}
	return meeha_bn_digits( $y ) . ' সালের খবর';
}

/**
 * Context for the archive header: title, desc, label, chips, count label, term, author.
 *
 * @return array
 */
function meeha_archive_context() {
	global $wp_query;
	$ctx = array(
		'title'  => '',
		'desc'   => '',
		'label'  => '',
		'chips'  => array(),
		'count'  => (int) $wp_query->found_posts,
		'noun'   => 'খবর',
		'term'   => null,
		'author' => null,
		'area'   => false,
	);
	$obj = get_queried_object();
	if ( is_category() && $obj instanceof WP_Term ) {
		$ctx['term']  = $obj;
		$ctx['title'] = $obj->name;
		$ctx['desc']  = term_description( $obj );
		$kids         = meeha_home_children( $obj->term_id );
		$base         = $kids ? $obj : ( $obj->parent ? get_term( $obj->parent, 'category' ) : $obj );
		$sub          = $kids ? $kids : ( $obj->parent ? meeha_home_children( $obj->parent ) : array() );
		if ( $sub && $base && ! is_wp_error( $base ) ) {
			$ctx['chips'][] = array( 'সব খবর', get_category_link( $base ), (int) $base->term_id === (int) $obj->term_id );
			foreach ( array_slice( $sub, 0, 12 ) as $cid ) {
				$t = get_term( $cid, 'category' );
				if ( $t && ! is_wp_error( $t ) ) {
					$ctx['chips'][] = array( $t->name, get_category_link( $t ), (int) $t->term_id === (int) $obj->term_id );
				}
			}
		}
		$mb          = meeha_home_cat_id( 'motherland' );
		$ctx['area'] = $mb && function_exists( 'meeha_render_area_widget' ) && ( (int) $obj->term_id === $mb || term_is_ancestor_of( $mb, $obj->term_id, 'category' ) );
	} elseif ( is_tag() && $obj instanceof WP_Term ) {
		$ctx['term']  = $obj;
		$ctx['title'] = $obj->name;
		$ctx['label'] = 'ট্যাগ';
		$ctx['desc']  = term_description( $obj );
	} elseif ( is_author() && $obj instanceof WP_User ) {
		$ctx['author'] = $obj;
		$ctx['title']  = $obj->display_name;
		$ctx['desc']   = get_the_author_meta( 'description', $obj->ID );
		$ctx['noun']   = 'লেখা';
	} elseif ( is_date() ) {
		$ctx['title'] = meeha_archive_date_title();
		$ctx['label'] = 'আর্কাইভ';
	} elseif ( is_home() ) {
		$ctx['title'] = 'সর্বশেষ খবর';
	} else {
		$ctx['title'] = wp_strip_all_tags( get_the_archive_title() );
		$ctx['desc']  = get_the_archive_description();
	}
	return apply_filters( 'meeha_archive_context', $ctx );
}

/**
 * Archive header markup.
 *
 * @param array $ctx Context.
 */
function meeha_archive_header( $ctx ) {
	$count = meeha_bn_digits( $ctx['count'] );
	$area  = ! empty( $ctx['area'] );
	?>
	<header class="m-archhead<?php echo $ctx['author'] ? ' m-archhead--author' : ''; ?>">
		<div class="m-container m-archhead__inner">
			<?php meeha_breadcrumb(); ?>
			<?php if ( $ctx['author'] ) : ?>
				<?php
				$u    = $ctx['author'];
				$role = meeha_author_designation( $u->ID );
				$dist = '';
				if ( function_exists( 'meeha_is_correspondent' ) && meeha_is_correspondent( $u->ID ) ) {
					$dist = trim( (string) get_user_meta( $u->ID, 'meeha_district', true ) );
				}
				$av   = get_avatar_url( $u->ID, array( 'size' => 192 ) );
				?>
				<div class="m-authorhead">
					<?php if ( $av ) : ?>
						<img class="m-authorhead__photo" src="<?php echo esc_url( $av ); ?>" width="96" height="96" alt="<?php echo esc_attr( $u->display_name ); ?>">
					<?php endif; ?>
					<div class="m-authorhead__body">
						<h1 class="m-archhead__title"><?php echo esc_html( $u->display_name ); ?></h1>
						<?php if ( '' !== $role ) : ?>
							<p class="m-authorhead__role"><?php echo esc_html( $role ); ?></p>
						<?php endif; ?>
						<?php if ( '' !== $dist ) : ?>
							<p class="m-authorhead__area"><?php echo meeha_icon( 'location', array( 'size' => 'sm' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>এলাকা: <?php echo esc_html( $dist ); ?></p>
						<?php endif; ?>
						<?php if ( $ctx['desc'] ) : ?>
							<p class="m-archhead__desc"><?php echo esc_html( wp_strip_all_tags( $ctx['desc'] ) ); ?></p>
						<?php endif; ?>
						<?php if ( $u->user_url ) : ?>
							<p class="m-authorhead__links"><a href="<?php echo esc_url( $u->user_url ); ?>" rel="nofollow noopener"><?php echo meeha_icon( 'link', array( 'size' => 'sm' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php echo esc_html( preg_replace( '#^https?://(www\.)?#', '', untrailingslashit( $u->user_url ) ) ); ?></a></p>
						<?php endif; ?>
					</div>
				</div>
			<?php else : ?>
				<?php if ( $ctx['label'] ) : ?>
					<p class="m-kicker m-archhead__label"><?php echo esc_html( $ctx['label'] ); ?></p>
				<?php endif; ?>
				<h1 class="m-archhead__title"><?php echo esc_html( $ctx['title'] ); ?></h1>
				<?php if ( $ctx['desc'] ) : ?>
					<div class="m-archhead__desc"><?php echo wp_kses_post( wpautop( wp_strip_all_tags( $ctx['desc'] ) ) ); ?></div>
				<?php endif; ?>
			<?php endif; ?>
			<?php if ( $ctx['chips'] || $ctx['count'] || $area ) : ?>
			<div class="m-archhead__row">
				<?php if ( $ctx['chips'] || $area ) : ?>
				<ul class="m-chiprow" role="list" aria-label="উপবিভাগ">
					<?php foreach ( $ctx['chips'] as $c ) : ?>
						<li><a class="m-chip" href="<?php echo esc_url( $c[1] ); ?>"<?php echo ! empty( $c[2] ) ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $c[0] ); ?></a></li>
					<?php endforeach; ?>
					<?php if ( $area ) : ?>
						<li><a class="m-chip" href="#m-area-arch"><?php echo meeha_icon( 'location', array( 'size' => 'sm' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>জেলা বেছে নিন</a></li>
					<?php endif; ?>
				</ul>
				<?php endif; ?>
				<?php if ( $ctx['count'] ) : ?>
					<span class="m-archhead__count">মোট <?php echo esc_html( $count . 'টি ' . $ctx['noun'] ); ?></span>
				<?php endif; ?>
			</div>
			<?php endif; ?>
		</div>
	</header>
	<?php
}

/**
 * Archive aside: most-read (7 days by default, same category tree on category pages) + area widget
 * (মাতৃভূমি tree) + archive_side ad.
 *
 * @param array $ctx Context.
 * @return string
 */
function meeha_archive_aside( $ctx ) {
	$cat    = ( $ctx['term'] && 'category' === $ctx['term']->taxonomy ) ? (int) $ctx['term']->term_id : 0;
	$window = (string) meeha_opt( 'home', 'mostread_archive', '7d' );
	$key    = 'meeha_arch_aside_' . md5( meeha_home_gen() . '|' . $cat . '|' . $window );
	$list   = get_transient( $key );
	if ( ! is_string( $list ) ) {
		$posts = function_exists( 'meeha_get_most_read' ) ? meeha_get_most_read( $window, 5, $cat ? array( 'category' => $cat ) : array() ) : array();
		$list  = $posts ? meeha_rank_list_html( $posts, array( 'kicker' => ! $cat ) ) : '';
		set_transient( $key, $list, 10 * MINUTE_IN_SECONDS );
	}
	$out = '';
	if ( '' !== $list ) {
		$title = $cat ? meeha_bn_locative( $ctx['term']->name ) . ' বেশি পঠিত' : 'সর্বাধিক পঠিত';
		$out  .= '<div class="m-mostread"><div class="m-sechead"><h2 class="m-sechead__title">' . esc_html( $title ) . '</h2></div>' . $list . '</div>';
	}
	if ( ! empty( $ctx['area'] ) ) {
		$out .= meeha_render_area_widget(
			array(
				'count'   => 3,
				'heading' => 'h2',
				'id'      => 'm-area-arch',
				'echo'    => false,
			)
		);
	}
	$out .= function_exists( 'meeha_ad' ) ? meeha_ad( 'archive_side' ) : '';
	return $out;
}

/**
 * Print the cards of one archive page.
 *
 * @param WP_Post[] $posts Posts.
 * @param bool      $first Page 1 (lead block).
 * @param bool      $ad    Insert the in-feed ad after grid item 6.
 */
function meeha_archive_cards( $posts, $first, $ad = true ) {
	$grid = $posts;
	$here = get_queried_object();
	$here = ( $here instanceof WP_Term && 'category' === $here->taxonomy ) ? $here->name : '';
	// On a category page the kicker is only shown when it adds something (a sub-category or a topic).
	$kick = function ( $p ) use ( $here ) {
		return '' === $here || meeha_post_kicker( $p ) !== $here;
	};
	if ( $first && count( $posts ) >= 3 ) {
		echo '<div class="m-archlead">';
		meeha_card(
			$posts[0],
			array(
				'variant'       => 'big',
				'show_dek'      => true,
				'show_meta'     => 'author',
				'heading_level' => 2,
				'img'           => 'lead',
				'eager'         => true,
				'show_kicker'   => $kick( $posts[0] ),
			)
		);
		echo '<div class="m-archlead__side">';
		meeha_card( $posts[1], array( 'variant' => 'sm rowm', 'heading_level' => 2, 'show_kicker' => $kick( $posts[1] ) ) );
		meeha_card( $posts[2], array( 'variant' => 'sm rowm', 'heading_level' => 2, 'show_kicker' => $kick( $posts[2] ) ) );
		echo '</div></div>';
		$grid = array_slice( $posts, 3 );
	}
	echo '<div class="m-archgrid" data-archgrid>';
	foreach ( $grid as $i => $p ) {
		meeha_card(
			$p,
			array(
				'variant'       => 'sm rowm',
				'heading_level' => 2,
				'show_meta'     => is_author() ? true : 'author',
				'eager'         => ! $first && 0 === $i,
				'show_kicker'   => $kick( $p ),
			)
		);
		if ( $ad && 5 === $i ) {
			$html = function_exists( 'meeha_ad' ) ? meeha_ad( 'archive_infeed' ) : '';
			if ( $html ) {
				echo '<div class="m-adrow">' . $html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by meeha_ad().
			}
		}
	}
	echo '</div>';
}

/**
 * Prime caches for the main query's posts (thumbnails, authors, meta, terms).
 */
function meeha_archive_prime() {
	global $wp_query;
	if ( ! empty( $wp_query->posts ) ) {
		update_post_thumbnail_cache( $wp_query );
		meeha_home_prime( wp_list_pluck( $wp_query->posts, 'ID' ) );
	}
}

/**
 * Next-page URL ('' on the last page).
 *
 * @return string
 */
function meeha_archive_next_url() {
	global $wp_query;
	$cur = max( 1, (int) get_query_var( 'paged' ) );
	return $cur < (int) $wp_query->max_num_pages ? (string) get_pagenum_link( $cur + 1, false ) : '';
}

/**
 * Output a load-more fragment (cacheable; no header/footer) and exit.
 */
function meeha_archive_fragment() {
	global $wp_query;
	if ( ! headers_sent() ) {
		header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
		header( 'Cache-Control: public, max-age=300, s-maxage=300' );
		header( 'X-Robots-Tag: noindex' );
	}
	meeha_archive_prime();
	$next = meeha_archive_next_url();
	echo '<div data-meeha-fragment data-next="' . esc_url( $next ) . '">';
	meeha_archive_cards( $wp_query->posts, false, false );
	echo '</div>';
	exit;
}
