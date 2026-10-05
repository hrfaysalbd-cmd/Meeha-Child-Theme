<?php
/**
 * Quote/poll archive - sectioned by most-viewed, most-downloaded, and by
 * quote-type taxonomy term, plus a filter bar (search + type) that loads
 * the full filtered grid below via AJAX.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>

<main id="meeha-single">
	<div class="meeha-container">

		<h1 class="meeha-quote-archive-section__title" style="border:none;font-size:28px;">উক্তি ও পোল</h1>

		<?php
		// Most viewed
		$most_viewed = new WP_Query( array(
			'post_type'      => 'meeha_photocard',
			'post_status'    => 'publish',
			'posts_per_page' => 8,
			'meta_key'       => '_meeha_views',
			'orderby'        => 'meta_value_num',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		) );
		meeha_render_quote_archive_section( 'সার্বাধিকবার দেখা উক্তি', $most_viewed );

		// Most downloaded
		$most_downloaded = new WP_Query( array(
			'post_type'      => 'meeha_photocard',
			'post_status'    => 'publish',
			'posts_per_page' => 8,
			'meta_key'       => '_meeha_download_count',
			'orderby'        => 'meta_value_num',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		) );
		meeha_render_quote_archive_section( 'সার্বাধিক ডাউনলোড উক্তি', $most_downloaded );

		// One section per quote-type term (dynamic - new admin terms show up automatically)
		$terms = get_terms( array( 'taxonomy' => 'meeha_quote_type', 'hide_empty' => true ) );
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$term_query = new WP_Query( array(
					'post_type'      => 'meeha_photocard',
					'post_status'    => 'publish',
					'posts_per_page' => 8,
					'no_found_rows'  => true,
					'tax_query'      => array( array(
						'taxonomy' => 'meeha_quote_type',
						'field'    => 'term_id',
						'terms'    => $term->term_id,
					) ),
				) );
				meeha_render_quote_archive_section( $term->name, $term_query );
			}
		}
		?>

		<hr style="margin:32px 0;border-color:#E6E6E2;" />

		<h2 class="meeha-quote-archive-section__title">সব উক্তি খুঁজুন</h2>
		<div class="meeha-quote-archive-filters">
			<select id="meeha-qa-type">
				<option value="0">সব ধরন</option>
				<?php if ( ! is_wp_error( $terms ) ) : foreach ( $terms as $term ) : ?>
					<option value="<?php echo esc_attr( $term->term_id ); ?>"><?php echo esc_html( $term->name ); ?></option>
				<?php endforeach; endif; ?>
			</select>
			<input type="search" id="meeha-qa-search" placeholder="উক্তি খুঁজুন..." />
		</div>

		<div class="meeha-photocard-grid" id="meeha-qa-list"></div>
		<p class="meeha-glance__empty" id="meeha-qa-empty" hidden>কোনো উক্তি পাওয়া যায়নি।</p>
		<p class="meeha-glance__loading" id="meeha-qa-loading">লোড হচ্ছে...</p>
		<button type="button" class="meeha-glance__more" id="meeha-qa-more" hidden>আরও দেখুন</button>

	</div>
</main>

<?php get_footer(); ?>
