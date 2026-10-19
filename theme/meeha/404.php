<?php
/**
 * 404 (DESIGN-SPEC §11.4): narrow column, H1, explanation, search (autofocus), সর্বশেষ (6 rows), section chips.
 * HTTP 404 + noindex (seo.php).
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

meeha_enqueue_bundle( '404' );
get_header();
?>
<main id="main" class="m-page m-404">
	<div class="m-container m-container--narrow">
		<header class="m-page__head">
			<p class="m-404__code" aria-hidden="true">৪০৪</p>
			<h1 class="m-page__title">এই পাতাটি পাওয়া যায়নি</h1>
			<p class="m-page__lede">লিংকটি ভুল হতে পারে বা খবরটি সরিয়ে নেওয়া হয়েছে।</p>
		</header>
		<div class="m-page__body">
			<form class="m-404__search" role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
				<label class="m-vh" for="m-404-q">খুঁজুন</label>
				<input class="m-input" id="m-404-q" type="search" name="s" placeholder="খবর, বিষয় বা লেখকের নাম" autofocus enterkeyhint="search">
				<button class="m-btn" type="submit"><?php echo meeha_icon( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>খুঁজুন</button>
			</form>

			<section class="m-404__latest" aria-labelledby="m-404-latest">
				<?php
				meeha_sechead(
					'সর্বশেষ',
					array(
						'id'       => 'm-404-latest',
						'modifier' => 'sm',
					)
				);
				$meeha_q = new WP_Query(
					array(
						'post_type'           => 'post',
						'posts_per_page'      => 6,
						'ignore_sticky_posts' => true,
						'no_found_rows'       => true,
					)
				);
				if ( $meeha_q->have_posts() ) {
					echo '<div class="m-404__list">';
					foreach ( $meeha_q->posts as $meeha_p ) {
						meeha_card( $meeha_p, array( 'variant' => 'row', 'heading_level' => 3 ) );
					}
					echo '</div>';
				}
				?>
			</section>

			<?php $meeha_cats = array_slice( array_filter( meeha_category_list( 1 ), function ( $c ) { return ! $c->parent; } ), 0, 12 ); ?>
			<?php if ( $meeha_cats ) : ?>
			<section class="m-404__sections" aria-labelledby="m-404-sections">
				<?php meeha_sechead( 'বিভাগসমূহ', array( 'id' => 'm-404-sections', 'modifier' => 'sm' ) ); ?>
				<ul class="m-404__chips" role="list">
					<?php foreach ( $meeha_cats as $meeha_c ) : ?>
						<li><a class="m-chip" href="<?php echo esc_url( get_category_link( $meeha_c ) ); ?>"><?php echo esc_html( $meeha_c->name ); ?></a></li>
					<?php endforeach; ?>
				</ul>
			</section>
			<?php endif; ?>
		</div>
	</div>
</main>
<?php
get_footer();
