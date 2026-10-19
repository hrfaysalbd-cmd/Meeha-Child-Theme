<?php
/**
 * Generic fallback list (blog index, any archive without its own template). Uses the archive markup
 * (`m-archhead`, `m-archgrid`, numeric pager) styled by the `archive` bundle.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

meeha_enqueue_bundle( 'archive' );
get_header();

if ( is_home() && ! is_front_page() ) {
	$meeha_title = single_post_title( '', false );
} elseif ( is_archive() ) {
	$meeha_title = wp_strip_all_tags( get_the_archive_title() );
} elseif ( is_search() ) {
	/* translators: search query */
	$meeha_title = '‘' . get_search_query() . '’ খোঁজের ফলাফল';
} else {
	$meeha_title = 'সর্বশেষ খবর';
}
$meeha_desc = is_archive() ? get_the_archive_description() : '';
?>
<main id="main">
	<header class="m-archhead">
		<div class="m-container m-archhead__inner">
			<?php meeha_breadcrumb(); ?>
			<?php $meeha_htag = is_front_page() ? 'h2' : 'h1'; // the front page's h1 is printed by the masthead. ?>
			<<?php echo $meeha_htag; // phpcs:ignore ?> class="m-archhead__title"><?php echo esc_html( $meeha_title ? $meeha_title : get_bloginfo( 'name' ) ); ?></<?php echo $meeha_htag; // phpcs:ignore ?>>
			<?php if ( $meeha_desc ) : ?>
				<div class="m-archhead__desc"><?php echo wp_kses_post( $meeha_desc ); ?></div>
			<?php endif; ?>
			<?php if ( $GLOBALS['wp_query']->found_posts ) : ?>
				<div class="m-archhead__row"><span class="m-archhead__count">মোট <?php echo esc_html( meeha_bn_digits( (int) $GLOBALS['wp_query']->found_posts ) ); ?>টি লেখা</span></div>
			<?php endif; ?>
		</div>
	</header>
	<div class="m-container m-archive">
		<?php if ( have_posts() ) : ?>
			<div class="m-archgrid">
				<?php
				$meeha_i = 0;
				while ( have_posts() ) :
					the_post();
					meeha_card(
						get_post(),
						array(
							'variant'       => 'sm rowm',
							'heading_level' => 2,
							'eager'         => 0 === $meeha_i,
						)
					);
					$meeha_i++;
					if ( 6 === $meeha_i ) {
						$meeha_ad = meeha_ad( 'archive_infeed' );
						if ( $meeha_ad ) {
							echo '<div class="m-adrow">' . $meeha_ad . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						}
					}
				endwhile;
				?>
			</div>
			<?php meeha_pager(); ?>
		<?php else : ?>
			<div class="m-page__body m-prose">
				<p>এখানে এখনো কিছু প্রকাশিত হয়নি।</p>
				<?php get_search_form(); ?>
			</div>
		<?php endif; ?>
	</div>
</main>
<?php
get_footer();
