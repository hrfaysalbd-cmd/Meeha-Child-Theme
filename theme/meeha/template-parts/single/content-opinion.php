<?php
/**
 * Single — opinion (সম্পাদকীয় / উপ-সম্পাদকীয় / পাঠক কথন / format aside) — proto/single-opinion.html.
 * Writer-first header; সম্পাদকীয় has no person and no disclaimer.
 *
 * @package meeha
 *
 * @var array $args post, variant.
 */

defined( 'ABSPATH' ) || exit;

$meeha_p    = $args['post'];
$meeha_ed   = meeha_single_is_editorial( $meeha_p );
$meeha_by   = meeha_post_byline( $meeha_p, 88 ); // 2.0.3: guest author aware.
$meeha_name = $meeha_by['name'];
$meeha_role = $meeha_by['title'];
$meeha_rc   = max( 0, (int) meeha_single_opt( 'related_count', 4 ) );
$meeha_pool = meeha_single_related_pool( $meeha_p, $meeha_rc + 1 );
$meeha_body = meeha_single_body( $meeha_p, 'opinion', $meeha_pool );
$meeha_rel  = in_array( 'readalso', $meeha_body['inserted'], true ) ? array_slice( $meeha_pool, 1, $meeha_rc ) : array_slice( $meeha_pool, 0, $meeha_rc );
$meeha_dek  = trim( (string) meeha_get_summary( $meeha_p->ID ) );
$meeha_dek  = '' !== $meeha_dek ? $meeha_dek : ( has_excerpt( $meeha_p ) && ! post_password_required( $meeha_p ) ? get_the_excerpt( $meeha_p ) : '' );
$meeha_card = '';
if ( function_exists( 'meeha_studio_url' ) && current_user_can( 'meeha_make_cards' ) ) {
	$meeha_card = '<a class="m-btn m-btn--ghost m-opcard" href="' . esc_url( meeha_studio_url( 'opinion', $meeha_p->ID ) ) . '" title="ফটোকার্ড স্টুডিওর মতামত কার্ড এই লেখা থেকে তৈরি হবে">' . meeha_icon( 'image', array( 'size' => 'sm' ) ) . '<span>কার্ড হিসেবে শেয়ার</span></a>';
}
?>
<main id="main" class="m-article-page">
	<div class="m-container m-article-layout">
		<article id="post-<?php echo (int) $meeha_p->ID; ?>" <?php post_class( 'm-article m-article--opinion' . ( $meeha_ed ? ' m-article--editorial' : '' ), $meeha_p ); ?>>
			<header class="m-article__head">
				<?php meeha_breadcrumb(); ?>
				<?php if ( $meeha_ed ) : ?>
					<div class="m-article__labels"><span class="m-label">সম্পাদকীয়</span></div>
				<?php else : ?>
					<div class="m-ophead">
						<span class="m-ophead__photo"><?php echo meeha_byline_photo_html( $meeha_by, 88, $meeha_name, true ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
						<?php if ( $meeha_by['url'] ) : ?>
							<a class="m-ophead__name" href="<?php echo esc_url( $meeha_by['url'] ); ?>" rel="author"><?php echo esc_html( $meeha_name ); ?></a>
						<?php else : ?>
							<span class="m-ophead__name"><?php echo esc_html( $meeha_name ); ?></span>
						<?php endif; ?>
						<span class="m-ophead__role"><?php echo esc_html( '' !== $meeha_role ? $meeha_role : 'মতামত' ); ?></span>
					</div>
				<?php endif; ?>
				<h1 class="m-article__title"><?php echo esc_html( get_the_title( $meeha_p ) ); ?></h1>
				<?php if ( '' !== $meeha_dek ) : ?>
					<?php echo meeha_single_dek_html( $meeha_dek, $meeha_p->ID ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?>
				<?php endif; ?>
				<?php
				meeha_single_meta_row( $meeha_p, array( 'prefix' => meeha_single_opinion_label( $meeha_p ) ) );
				meeha_single_tools( $meeha_p, array( 'extra' => $meeha_card ) );
				?>
			</header>

			<?php
			// Opinion: no lead image by default; an illustration (featured image) shows below the title when set.
			meeha_single_lead_figure( $meeha_p, array( 'class' => 'm-figure--illustration' ) );
			?>

			<div class="m-prose" data-prose>
				<?php echo $meeha_body['html']; // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</div>

			<footer class="m-article__foot">
				<?php
				if ( ! $meeha_ed ) {
					$meeha_disc = trim( (string) meeha_single_opt( 'opinion_disclaimer', '' ) );
					$meeha_sub  = (string) meeha_single_opt( 'opinion_submit_url', '' );
					if ( '' !== $meeha_disc ) {
						echo '<p class="m-disclaimer">' . esc_html( $meeha_disc );
						if ( $meeha_sub ) {
							echo ' আপনার মতামত লিখে পাঠাতে <a href="' . esc_url( $meeha_sub ) . '">লেখা পাঠানোর নিয়ম</a> দেখুন।';
						}
						echo '</p>';
					}
				}
				meeha_single_tags( $meeha_p );
				meeha_single_reactions( $meeha_p, 'opinion' );
				meeha_share_buttons(
					$meeha_p,
					array(
						'networks' => meeha_single_share_networks(),
						'label'    => 'শেয়ার করুন',
					)
				);
				meeha_single_author_box( $meeha_p, 'opinion' );
				meeha_single_correction( $meeha_p );
				meeha_single_comments_card( $meeha_p, 'opinion' );
				?>
			</footer>
		</article>

		<aside class="m-article__aside" aria-label="আরও মতামত">
			<?php
			meeha_single_more_opinion( $meeha_p );
			meeha_single_sidebar( $meeha_p ); // configurable desktop blocks; the last one is sticky.
			?>
		</aside>
	</div>
	<?php meeha_single_related_section( $meeha_rel ); ?>
</main>
<?php
meeha_single_summary_dialog( $meeha_p );
