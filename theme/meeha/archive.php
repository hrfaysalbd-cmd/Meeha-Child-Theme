<?php
/**
 * Category / tag / author / date archives (DESIGN-SPEC §11, proto/category.html) and the paged home.
 * `?meeha_fragment=1` returns only the cards of this page (progressive "আরও খবর দেখুন").
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

if ( meeha_archive_is_fragment() ) {
	meeha_archive_fragment();
}

global $wp_query;
$meeha_ctx   = meeha_archive_context();
$meeha_paged = max( 1, (int) get_query_var( 'paged' ) );
$meeha_first = 1 === $meeha_paged;
$meeha_posts = $wp_query->posts;

meeha_enqueue_bundle( 'archive' );
if ( $meeha_first && count( $meeha_posts ) >= 3 ) {
	$meeha_thumb = (int) get_post_thumbnail_id( $meeha_posts[0] );
	if ( $meeha_thumb ) {
		meeha_preload_img( $meeha_thumb, 'lead' );
	}
}
meeha_archive_prime();
get_header();
?>
<main id="main" class="m-archive-page">
	<?php meeha_archive_header( $meeha_ctx ); ?>
	<div class="m-container m-archive">
		<?php $meeha_aside = meeha_archive_aside( $meeha_ctx ); ?>
		<div class="<?php echo $meeha_aside ? 'm-split' : 'm-archive__solo'; ?>">
			<div>
				<?php if ( $meeha_posts ) : ?>
					<?php meeha_archive_cards( $meeha_posts, $meeha_first ); ?>
					<?php $meeha_next = meeha_archive_next_url(); ?>
					<?php if ( $meeha_next ) : ?>
						<div class="m-loadmore"><a class="m-btn m-btn--ghost" href="<?php echo esc_url( $meeha_next ); ?>" data-loadmore aria-describedby="m-loadmore-note">আরও খবর দেখুন</a><span class="m-vh" id="m-loadmore-note" aria-live="polite" data-loadmore-status></span></div>
					<?php endif; ?>
					<?php meeha_pager( $wp_query ); ?>
				<?php else : ?>
					<div class="m-noresult">
						<h2>এখানে এখনো কিছু প্রকাশিত হয়নি</h2>
						<p>অন্য বিভাগ দেখুন বা খুঁজে দেখুন।</p>
						<?php get_search_form(); ?>
					</div>
				<?php endif; ?>
			</div>
			<?php if ( $meeha_aside ) : ?>
				<aside class="m-split__aside" aria-label="আরও পড়ুন">
					<?php echo $meeha_aside; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
				</aside>
			<?php endif; ?>
		</div>
	</div>
</main>
<?php
get_footer();
