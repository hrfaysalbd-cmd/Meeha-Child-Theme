<?php
/**
 * Meeha News — Photocard browse UI: search box + tabs (All / Most Read /
 * one tab per meeha_quote_type term). Renders server-side for the first
 * batch, then AJAX swaps content on tab click, search, and "load more".
 *
 * "Most Read" is sorted by the existing _meeha_views counter. It's sorted
 * in PHP rather than via WP_Query's meta_key+orderby=meta_value_num (which
 * uses an INNER JOIN and silently excludes any post with no recorded views
 * yet) - the same join-based approach that caused real problems for the
 * blog-post "Most Read" feature elsewhere in this theme.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function meeha_render_photocard_browse_shell( $count ) {
	$count = max( 1, (int) $count );
	$terms = get_terms( array( 'taxonomy' => 'meeha_quote_type', 'hide_empty' => true ) );
	if ( is_wp_error( $terms ) ) {
		$terms = array();
	}
	?>
	<div class="meeha-pc-browse" id="meeha-pc-browse" data-count="<?php echo esc_attr( $count ); ?>" data-tab="all">
		<div class="meeha-pc-browse__search">
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
			<input type="search" id="meeha-pc-search-input" placeholder="সব উক্তি খুঁজুন..." />
		</div>

		<div class="meeha-pc-browse__tabs" role="tablist" id="meeha-pc-tabs">
			<button type="button" class="meeha-pc-tab is-active" data-tab="all">সব উক্তি</button>
			<button type="button" class="meeha-pc-tab" data-tab="most-read">সার্বাধিক পঠিত</button>
			<?php foreach ( $terms as $term ) : ?>
				<button type="button" class="meeha-pc-tab" data-tab="<?php echo esc_attr( $term->slug ); ?>"><?php echo esc_html( $term->name ); ?></button>
			<?php endforeach; ?>
		</div>

		<div class="meeha-photocard-grid" id="meeha-pc-grid">
			<?php
			$initial = meeha_get_photocard_batch( 'all', '', 0, $count );
			if ( empty( $initial['posts'] ) ) {
				echo '<p class="meeha-photocard-empty">এখনো কোনো ফটোকার্ড যোগ করা হয়নি।</p>';
			} else {
				foreach ( $initial['posts'] as $p ) {
					meeha_render_photocard_item( $p );
				}
			}
			?>
		</div>

		<p class="meeha-pc-browse__empty" id="meeha-pc-empty" <?php echo empty( $initial['posts'] ) ? '' : 'hidden'; ?>>কোনো উক্তি পাওয়া যায়নি।</p>
		<button type="button" class="meeha-glance__more" id="meeha-pc-more" data-offset="<?php echo esc_attr( count( $initial['posts'] ) ); ?>" <?php echo $initial['has_more'] ? '' : 'hidden'; ?>>আরও দেখুন</button>
	</div>
	<?php
}

/**
 * Fetches one batch of photocards for a given tab/search/offset, returning
 * both the post objects and whether more results exist beyond this batch.
 */
function meeha_get_photocard_batch( $tab, $search, $offset, $count ) {
	$tab    = sanitize_key( $tab );
	$search = sanitize_text_field( $search );
	$offset = max( 0, (int) $offset );
	$count  = max( 1, (int) $count );

	if ( 'most-read' === $tab ) {
		return meeha_get_photocard_batch_most_read( $search, $offset, $count );
	}

	$args = array(
		'post_type'      => 'meeha_photocard',
		'post_status'    => 'publish',
		'posts_per_page' => $count + 1, // fetch one extra to know if more remain
		'offset'         => $offset,
		'orderby'        => 'date',
		'order'          => 'DESC',
		'no_found_rows'  => true,
	);
	if ( $search ) {
		$args['s'] = $search;
	}
	if ( 'all' !== $tab ) {
		$args['tax_query'] = array( array( // phpcs:ignore WordPress.DB.SlowDBQuery
			'taxonomy' => 'meeha_quote_type',
			'field'    => 'slug',
			'terms'    => $tab,
		) );
	}

	$query    = new WP_Query( $args );
	$posts    = $query->posts;
	$has_more = count( $posts ) > $count;
	if ( $has_more ) {
		array_pop( $posts );
	}
	return array( 'posts' => $posts, 'has_more' => $has_more );
}

function meeha_get_photocard_batch_most_read( $search, $offset, $count ) {
	$args = array(
		'post_type'      => 'meeha_photocard',
		'post_status'    => 'publish',
		'posts_per_page' => 300, // sane cap - sorted/paginated in PHP below
		'no_found_rows'  => true,
	);
	if ( $search ) {
		$args['s'] = $search;
	}
	$all = ( new WP_Query( $args ) )->posts;

	usort( $all, function ( $a, $b ) {
		$va = (int) get_post_meta( $a->ID, '_meeha_views', true );
		$vb = (int) get_post_meta( $b->ID, '_meeha_views', true );
		if ( $va === $vb ) {
			return strtotime( $b->post_date ) <=> strtotime( $a->post_date );
		}
		return $vb <=> $va;
	} );

	$slice    = array_slice( $all, $offset, $count + 1 );
	$has_more = count( $slice ) > $count;
	if ( $has_more ) {
		array_pop( $slice );
	}
	return array( 'posts' => $slice, 'has_more' => $has_more );
}

function meeha_handle_ajax_photocard_browse() {
	check_ajax_referer( 'meeha_pc_browse_nonce', 'nonce' );

	$tab    = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : 'all';
	$search = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
	$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
	$count  = isset( $_POST['count'] ) ? absint( $_POST['count'] ) : 12;

	$batch = meeha_get_photocard_batch( $tab, $search, $offset, $count );

	ob_start();
	foreach ( $batch['posts'] as $p ) {
		meeha_render_photocard_item( $p );
	}
	$html = ob_get_clean();

	wp_send_json_success( array(
		'html'     => $html,
		'has_more' => $batch['has_more'],
		'count'    => count( $batch['posts'] ),
	) );
}
add_action( 'wp_ajax_meeha_pc_browse', 'meeha_handle_ajax_photocard_browse' );
add_action( 'wp_ajax_nopriv_meeha_pc_browse', 'meeha_handle_ajax_photocard_browse' );
