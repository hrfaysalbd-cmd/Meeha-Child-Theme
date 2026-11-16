<?php
/**
 * [meeha_latest_feed] — the "সর্বশেষ" live page.
 *
 * Server-rendered: 1 hero + 12 newest, "আরও দেখুন" (8 more, no-JS link ?lp=N), and a category
 * explorer (8 per category, no-JS links ?lcat=ID). assets/js/latest.js polls the public REST
 * route GET meeha/v1/latest?part=top only while the tab is visible (Page Visibility API) and
 * at most every 60 s; new stories are announced with a "নতুন খবর" button instead of replacing
 * the list under the reader (no layout shift). Legacy AJAX actions meeha_latest_top_refresh,
 * meeha_latest_load_more and meeha_latest_category_tab remain as thin wrappers.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

define( 'MEEHA_LATEST_TOP', 13 );   // 1 hero + 12 grid
define( 'MEEHA_LATEST_STEP', 8 );   // load-more batch

/** Newest post IDs with offset (cached 60 s; busted on publish). */
function meeha_latest_ids( $count, $offset = 0, $cat = 0 ) {
	$count  = max( 1, min( 40, (int) $count ) );
	$offset = max( 0, min( 500, (int) $offset ) );
	$key    = 'meeha_lf_' . md5( wp_json_encode( array( $count, $offset, (int) $cat, (int) get_option( 'meeha_glance_cache_gen', 0 ) ) ) );
	$ids    = get_transient( $key );
	if ( is_array( $ids ) ) {
		return $ids;
	}
	$args = array(
		'post_type'              => 'post',
		'post_status'            => 'publish',
		'has_password'           => false,
		'posts_per_page'         => $count,
		'offset'                 => $offset,
		'ignore_sticky_posts'    => true,
		'no_found_rows'          => true,
		'fields'                 => 'ids',
		'update_post_term_cache' => false,
	);
	if ( $cat ) {
		$args['cat'] = (int) $cat;
	}
	$q   = new WP_Query( $args );
	$ids = array_map( 'intval', $q->posts );
	if ( ! $cat || term_exists( (int) $cat, 'category' ) ) { // never cache arbitrary ids
		set_transient( $key, $ids, MINUTE_IN_SECONDS );
	}
	return $ids;
}

/** Card list markup. */
function meeha_latest_cards( $ids, $variant = 'rowm' ) {
	$out = '';
	foreach ( $ids as $id ) {
		$out .= '<li class="m-lf__item">' . meeha_legacy_card(
			$id,
			array(
				'variant'   => $variant,
				'img'       => 'card',
				'show_meta' => true,
			)
		) . '</li>';
	}
	return $out;
}

/** Hero + grid block. */
function meeha_latest_top_html( $ids = null ) {
	$ids = null === $ids ? meeha_latest_ids( MEEHA_LATEST_TOP ) : $ids;
	if ( ! $ids ) {
		return '<p class="m-dek">এখনো কোনো খবর প্রকাশিত হয়নি।</p>';
	}
	$hero = array_shift( $ids );
	_prime_post_caches( array_merge( array( $hero ), $ids ), true, true );
	$out  = '<div class="m-lf__hero">' . meeha_legacy_card(
		$hero,
		array(
			'variant'       => 'side-lg',
			'img'           => 'card-lg',
			'show_dek'      => true,
			'heading_level' => 2,
			'eager'         => true,
		)
	) . '</div>';
	if ( $ids ) {
		$out .= '<ul class="m-lf__grid" role="list">' . meeha_latest_cards( $ids ) . '</ul>';
	}
	return $out;
}

/** Default category for the explorer: legacy hero category, else the biggest category. */
function meeha_latest_default_cat( $categories ) {
	$legacy = get_option( 'meeha_homepage_settings', array() );
	$cat    = is_array( $legacy ) && ! empty( $legacy['hero_category'] ) ? (int) $legacy['hero_category'] : 0;
	foreach ( $categories as $c ) {
		if ( (int) $c->term_id === $cat ) {
			return $cat;
		}
	}
	return $categories ? (int) $categories[0]->term_id : 0;
}

/* ---------- shortcode ---------- */
function meeha_latest_feed_shortcode() {
	meeha_latest_enqueue();
	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	$lp  = isset( $_GET['lp'] ) ? max( 1, min( 20, absint( $_GET['lp'] ) ) ) : 1;
	$sel = isset( $_GET['lcat'] ) ? absint( $_GET['lcat'] ) : 0;
	// phpcs:enable
	$base       = get_permalink();
	$top_ids    = meeha_latest_ids( MEEHA_LATEST_TOP );
	$more_count = ( $lp - 1 ) * MEEHA_LATEST_STEP;
	$more_ids   = $more_count ? meeha_latest_ids( $more_count, MEEHA_LATEST_TOP ) : array();
	$next       = meeha_latest_ids( 1, MEEHA_LATEST_TOP + $more_count );
	$categories = get_categories(
		array(
			'hide_empty' => true,
			'orderby'    => 'count',
			'order'      => 'DESC',
			'number'     => 16,
			'exclude'    => array( (int) get_option( 'default_category' ) ),
		)
	);
	$cur_cat    = $sel ? $sel : meeha_latest_default_cat( $categories );
	$cat_ids    = $cur_cat ? meeha_latest_ids( 8, 0, $cur_cat ) : array();
	$updated    = function_exists( 'meeha_post_clock' ) && $top_ids ? meeha_post_clock( $top_ids[0] ) : '';

	ob_start();
	?>
	<div class="m-lf" data-latest data-endpoint="<?php echo esc_url( rest_url( 'meeha/v1/latest' ) ); ?>" data-interval="60" data-offset="<?php echo esc_attr( MEEHA_LATEST_TOP + $more_count ); ?>">
		<div class="m-lf__bar">
			<p class="m-lf__live"><span class="m-kicker m-kicker--live">সর্বশেষ</span><span class="m-lf__upd">সব বিভাগের খবর, নতুন থেকে পুরোনো<?php echo $updated ? ' · সর্বশেষ প্রকাশ ' . esc_html( $updated ) : ''; ?></span></p>
			<button type="button" class="m-btn m-lf__fresh" hidden data-latest-fresh aria-live="polite"><?php echo meeha_legacy_icon( 'arrow-up', 'm-icon--sm' ); // phpcs:ignore ?><span data-latest-fresh-text>নতুন খবর এসেছে — দেখুন</span></button>
		</div>
		<div class="m-lf__top" data-latest-top data-ids="<?php echo esc_attr( implode( ',', $top_ids ) ); ?>">
			<?php echo meeha_latest_top_html( $top_ids ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside ?>
		</div>
		<ul class="m-lf__grid m-lf__grid--more" role="list" data-latest-more><?php echo meeha_latest_cards( $more_ids ); // phpcs:ignore WordPress.Security.EscapeOutput ?></ul>
		<?php if ( $next ) : ?>
			<div class="m-loadmore" data-latest-morewrap>
				<a class="m-btn m-btn--ghost" href="<?php echo esc_url( add_query_arg( 'lp', $lp + 1, $base ) ); ?>" rel="next" data-latest-loadmore>আরও দেখুন</a>
			</div>
		<?php endif; ?>

		<?php if ( $categories ) : ?>
			<section class="m-lf__cats" id="m-lf-cats" aria-labelledby="m-lf-cats-h">
				<div class="m-sechead"><h2 class="m-sechead__title" id="m-lf-cats-h">বিভাগ অনুযায়ী দেখুন</h2></div>
				<nav class="m-lf__chips" aria-label="বিভাগ">
					<?php foreach ( $categories as $cat ) : ?>
						<a class="m-chip" href="<?php echo esc_url( add_query_arg( 'lcat', $cat->term_id, $base ) . '#m-lf-cats' ); ?>" data-cat="<?php echo esc_attr( $cat->term_id ); ?>" <?php echo (int) $cat->term_id === (int) $cur_cat ? 'aria-current="true"' : ''; ?>><?php echo esc_html( $cat->name ); ?></a>
					<?php endforeach; ?>
				</nav>
				<ul class="m-lf__grid" role="list" data-latest-cat aria-live="polite">
					<?php echo $cat_ids ? meeha_latest_cards( $cat_ids ) : '<li class="m-dek">এই বিভাগে কোনো খবর নেই।</li>'; // phpcs:ignore WordPress.Security.EscapeOutput ?>
				</ul>
				<?php if ( $cur_cat ) : ?>
					<p class="m-lf__all"><a class="m-sechead__more" href="<?php echo esc_url( get_category_link( $cur_cat ) ); ?>" data-latest-catlink>এই বিভাগের সব খবর<?php echo meeha_legacy_icon( 'chev-r', 'm-icon--sm' ); // phpcs:ignore ?></a></p>
				<?php endif; ?>
			</section>
		<?php endif; ?>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'meeha_latest_feed', 'meeha_latest_feed_shortcode' );

function meeha_latest_enqueue() {
	meeha_legacy_enqueue( 'latest', array( 'latest.css' ), array( 'latest.js' ) );
}

function meeha_latest_maybe_enqueue() {
	if ( meeha_legacy_current_has( array( 'meeha_latest_feed' ) ) ) {
		meeha_latest_enqueue();
	}
}
add_action( 'wp_enqueue_scripts', 'meeha_latest_maybe_enqueue' );

/* ---------- REST ---------- */
function meeha_latest_payload( $part, $offset = 0, $cat = 0 ) {
	if ( 'more' === $part ) {
		$offset = max( MEEHA_LATEST_TOP, (int) $offset );
		$ids    = meeha_latest_ids( MEEHA_LATEST_STEP, $offset );
		$next   = $ids ? meeha_latest_ids( 1, $offset + count( $ids ) ) : array();
		return array(
			'html'        => meeha_latest_cards( $ids ),
			'has_more'    => ! empty( $next ),
			'next_offset' => $offset + count( $ids ),
		);
	}
	if ( 'cat' === $part ) {
		$cat  = absint( $cat );
		$term = $cat ? get_term( $cat, 'category' ) : null;
		if ( ! $term || is_wp_error( $term ) ) {
			return array( 'html' => '<li class="m-dek">বিভাগটি পাওয়া যায়নি।</li>', 'link' => '' );
		}
		$ids = meeha_latest_ids( 8, 0, $cat );
		return array(
			'html' => $ids ? meeha_latest_cards( $ids ) : '<li class="m-dek">এই বিভাগে কোনো খবর নেই।</li>',
			'link' => get_category_link( $cat ),
		);
	}
	$ids = meeha_latest_ids( MEEHA_LATEST_TOP );
	return array(
		'ids'  => $ids,
		'html' => meeha_latest_top_html( $ids ),
	);
}

function meeha_latest_rest_routes() {
	register_rest_route(
		'meeha/v1',
		'/latest',
		array(
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'args'                => array(
				'part'   => array( 'type' => 'string', 'default' => 'top', 'enum' => array( 'top', 'more', 'cat' ) ),
				'offset' => array( 'type' => 'integer', 'default' => MEEHA_LATEST_TOP, 'minimum' => 0, 'maximum' => 500 ),
				'cat'    => array( 'type' => 'integer', 'default' => 0, 'minimum' => 0 ),
			),
			'callback'            => function ( WP_REST_Request $r ) {
				return meeha_legacy_rest_cacheable( meeha_latest_payload( $r['part'], $r['offset'], $r['cat'] ), 'top' === $r['part'] ? 60 : 300 );
			},
		)
	);
}
add_action( 'rest_api_init', 'meeha_latest_rest_routes' );

/* ---------- legacy AJAX wrappers (read-only; nonce not enforced) ---------- */
function meeha_handle_latest_top_refresh() {
	$p = meeha_latest_payload( 'top' );
	wp_send_json_success( array( 'html' => $p['html'] ) );
}
add_action( 'wp_ajax_meeha_latest_top_refresh', 'meeha_handle_latest_top_refresh' );
add_action( 'wp_ajax_nopriv_meeha_latest_top_refresh', 'meeha_handle_latest_top_refresh' );

function meeha_handle_latest_load_more() {
	$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : MEEHA_LATEST_TOP; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$p      = meeha_latest_payload( 'more', $offset );
	wp_send_json_success( array( 'html' => $p['html'], 'has_more' => $p['has_more'] ) );
}
add_action( 'wp_ajax_meeha_latest_load_more', 'meeha_handle_latest_load_more' );
add_action( 'wp_ajax_nopriv_meeha_latest_load_more', 'meeha_handle_latest_load_more' );

function meeha_handle_latest_category_tab() {
	$cat = isset( $_POST['category'] ) ? absint( $_POST['category'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	if ( ! $cat ) {
		wp_send_json_error();
	}
	$p = meeha_latest_payload( 'cat', 0, $cat );
	wp_send_json_success( array( 'html' => $p['html'] ) );
}
add_action( 'wp_ajax_meeha_latest_category_tab', 'meeha_handle_latest_category_tab' );
add_action( 'wp_ajax_nopriv_meeha_latest_category_tab', 'meeha_handle_latest_category_tab' );
