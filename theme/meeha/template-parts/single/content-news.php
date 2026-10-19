<?php
/**
 * Single — news + investigative long-read (proto/single.html).
 *
 * @package meeha
 *
 * @var array $args post, variant.
 */

defined( 'ABSPATH' ) || exit;

$meeha_p   = $args['post'];
$meeha_v   = $args['variant'];
$meeha_inv = 'investigative' === $meeha_v;
$meeha_rc  = max( 0, (int) meeha_single_opt( 'related_count', 4 ) );
$meeha_pool = 'post' === $meeha_p->post_type ? meeha_single_related_pool( $meeha_p, $meeha_rc + 1 ) : array();
$meeha_body = meeha_single_body( $meeha_p, $meeha_v, $meeha_pool );
$meeha_toc  = meeha_single_show_toc( $meeha_body['toc'], $meeha_v ) ? $meeha_body['toc'] : array();
$meeha_rel  = in_array( 'readalso', $meeha_body['inserted'], true ) ? array_slice( $meeha_pool, 1, $meeha_rc ) : array_slice( $meeha_pool, 0, $meeha_rc );
$meeha_dek  = trim( (string) meeha_get_summary( $meeha_p->ID ) );
$meeha_dek  = '' !== $meeha_dek ? $meeha_dek : ( has_excerpt( $meeha_p ) && ! post_password_required( $meeha_p ) ? get_the_excerpt( $meeha_p ) : '' );
?>
<main id="main" class="m-article-page">
	<div class="m-container m-article-layout">
		<article id="post-<?php echo (int) $meeha_p->ID; ?>" <?php post_class( 'm-article' . ( $meeha_inv ? ' m-article--inv' : '' ), $meeha_p ); ?>>
			<header class="m-article__head">
				<?php
				meeha_breadcrumb();
				meeha_single_labels( $meeha_p, $meeha_v );
				?>
				<h1 class="m-article__title"><?php echo esc_html( get_the_title( $meeha_p ) ); ?></h1>
				<?php if ( '' !== $meeha_dek ) : ?>
					<?php echo meeha_single_dek_html( $meeha_dek, $meeha_p->ID ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?>
				<?php endif; ?>
				<?php
				meeha_single_byline( $meeha_p );
				meeha_single_tools( $meeha_p );
				?>
			</header>

			<?php
			meeha_single_lead_figure( $meeha_p, array( 'viewfinder' => $meeha_inv ) );
			meeha_single_toc( $meeha_toc, 'details' );
			?>

			<div class="m-prose" data-prose>
				<?php echo $meeha_body['html']; // phpcs:ignore WordPress.Security.EscapeOutput -- the_content output + escaped inserts. ?>
			</div>
			<?php
			wp_link_pages(
				array(
					'before' => '<nav class="m-pager" aria-label="অংশ">',
					'after'  => '</nav>',
				)
			);
			?>

			<footer class="m-article__foot">
				<?php
				meeha_single_tags( $meeha_p );
				meeha_single_reactions( $meeha_p, $meeha_v );
				meeha_share_buttons(
					$meeha_p,
					array(
						'networks' => meeha_single_share_networks(),
						'label'    => 'শেয়ার করুন',
					)
				);
				meeha_single_author_box( $meeha_p, $meeha_v );
				meeha_single_correction( $meeha_p );
				meeha_single_comments_card( $meeha_p, $meeha_v );
				?>
			</footer>
		</article>

		<aside class="m-article__aside" aria-label="আরও পড়ুন">
			<?php meeha_single_sidebar( $meeha_p, $meeha_toc ); // configurable blocks; the last one (+ TOC) is sticky. ?>
		</aside>
	</div>
	<?php meeha_single_related_section( $meeha_rel ); ?>
	<div class="m-container m-mostread-m"><?php meeha_single_most_read_block( $meeha_p ); ?></div>
</main>
<?php
meeha_single_summary_dialog( $meeha_p );
