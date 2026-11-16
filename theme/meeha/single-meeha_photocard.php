<?php
/**
 * Single উক্তি (proto/quote-single.html): the card (<h1> = quote text), speaker, context/source,
 * "ছবি ডাউনলোড" (E's cards.js, lazy), copy link, share, poll, "আরও {type}-এর উক্তি" rail, comments.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

get_header();
while ( have_posts() ) :
	the_post();
	$meeha_q = get_post();
	$meeha_d = meeha_quote_data( $meeha_q );
	$meeha_pl = function_exists( 'meeha_cards_quote_payload' ) ? meeha_cards_quote_payload( $meeha_q ) : array();
	?>
<main id="main" class="m-quote-single">
	<div class="m-container">
		<?php meeha_breadcrumb( array( 'class' => 'm-qsingle__crumbs' ) ); ?>
		<div class="m-qsingle">
			<?php
			echo meeha_render_quote_card( // phpcs:ignore WordPress.Security.EscapeOutput
				$meeha_q,
				array(
					'heading' => 'h1',
					'link'    => false,
					'photo'   => false,
					'brand'   => true,
				)
			);
			?>
			<div class="m-qinfo">
				<div class="m-qinfo__speaker<?php echo $meeha_d['photo'] ? '' : ' m-qinfo__speaker--nophoto'; ?>">
					<?php
					if ( $meeha_d['photo'] ) {
						echo meeha_img( $meeha_d['photo'], 'square', array( 'alt' => $meeha_d['speaker'], 'sizes' => '64px' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
					}
					?>
					<span class="m-qinfo__name"><?php echo esc_html( '' !== $meeha_d['speaker'] ? $meeha_d['speaker'] : 'অজ্ঞাত' ); ?></span>
					<?php if ( '' !== $meeha_d['role'] ) : ?>
						<span class="m-qinfo__role"><?php echo esc_html( $meeha_d['role'] ); ?></span>
					<?php endif; ?>
				</div>
				<?php
				$meeha_src = trim( ( '' !== $meeha_d['source'] ? '‘' . trim( $meeha_d['source'], '‘’' ) . '’' : '' ) . ( '' !== $meeha_d['source_year'] ? ( '' !== $meeha_d['source'] ? ', ' : '' ) . $meeha_d['source_year'] : '' ) );
				if ( '' !== $meeha_src ) {
					echo '<p class="m-qinfo__context"><b>উৎস:</b> ' . esc_html( $meeha_src ) . '</p>';
				}
				if ( '' !== $meeha_d['context'] ) {
					echo '<p class="m-qinfo__context"><b>প্রসঙ্গ:</b> ' . esc_html( $meeha_d['context'] ) . '</p>';
				}
				if ( has_excerpt( $meeha_q ) ) {
					echo '<p class="m-qinfo__context">' . esc_html( get_the_excerpt( $meeha_q ) ) . '</p>';
				}
				?>
				<div class="m-qactions">
					<?php if ( $meeha_pl ) : ?>
						<button class="m-btn m-qdl" type="button" data-card="<?php echo esc_attr( wp_json_encode( $meeha_pl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); ?>" data-track="<?php echo esc_url( rest_url( 'meeha/v1/quote-download/' . $meeha_q->ID ) ); ?>"><?php echo meeha_icon( 'download', array( 'size' => 'sm' ) ); // phpcs:ignore ?><span data-label>ছবি ডাউনলোড (১০৮০ × ১০৮০)</span></button>
					<?php endif; ?>
					<button class="m-btn m-btn--ghost" type="button" data-copy="<?php echo esc_url( get_permalink( $meeha_q ) ); ?>"><?php echo meeha_icon( 'link', array( 'size' => 'sm' ) ); // phpcs:ignore ?><span>লিংক কপি</span></button>
					<span class="m-vh" role="status" aria-live="polite" data-card-status></span>
				</div>
				<?php
				meeha_share_buttons(
					$meeha_q,
					array(
						'networks' => array( 'fb', 'wa', 'x', 'tg', 'native' ),
						'label'    => 'শেয়ার করুন',
					)
				);
				meeha_quote_poll_form( $meeha_q );
				?>
			</div>
		</div>
	</div>
	<?php
	// "আরও {type}-এর উক্তি".
	$meeha_rel_args = array(
		'post_type'           => 'meeha_photocard',
		'posts_per_page'      => 8,
		'post__not_in'        => array( $meeha_q->ID ),
		'no_found_rows'       => true,
		'ignore_sticky_posts' => true,
	);
	if ( $meeha_d['term'] ) {
		$meeha_rel_args['tax_query'] = array( array( 'taxonomy' => 'meeha_quote_type', 'terms' => array( (int) $meeha_d['term']->term_id ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
	}
	$meeha_rel = ( new WP_Query( $meeha_rel_args ) )->posts;
	if ( count( $meeha_rel ) < 2 ) {
		unset( $meeha_rel_args['tax_query'] );
		$meeha_rel = ( new WP_Query( $meeha_rel_args ) )->posts;
		$meeha_rt  = 'আরও উক্তি';
	} else {
		$meeha_rt = 'আরও ' . preg_replace( '/\s*উক্তি$/u', '', $meeha_d['type_label'] ) . '-এর উক্তি';
	}
	if ( $meeha_rel ) :
		?>
	<section class="m-section m-section--band" aria-labelledby="m-rq-h" data-rail>
		<div class="m-container">
			<?php
			meeha_sechead(
				$meeha_rt,
				array(
					'url'        => $meeha_d['term'] ? get_term_link( $meeha_d['term'] ) : get_post_type_archive_link( 'meeha_photocard' ),
					'more_label' => 'সব উক্তি',
					'id'         => 'm-rq-h',
					'after'      => '<div class="m-railnav"><button type="button" aria-label="আগের উক্তি">' . meeha_icon( 'chev-l' ) . '</button><button type="button" aria-label="পরের উক্তি">' . meeha_icon( 'chev-r' ) . '</button></div>',
				)
			);
			?>
			<ul class="m-rail m-quote-rail" role="list">
				<?php
				foreach ( $meeha_rel as $meeha_r ) {
					echo '<li>' . meeha_render_quote_card( $meeha_r ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput
				}
				?>
			</ul>
		</div>
	</section>
	<?php endif; ?>
	<?php if ( comments_open() || get_comments_number() ) : ?>
	<section class="m-section m-qcomments" aria-labelledby="m-qc-h">
		<div class="m-container m-container--narrow">
			<h2 class="m-comments__title" id="m-qc-h"><?php echo esc_html( (string) meeha_single_opt( 'comments_heading', 'মন্তব্য' ) ); ?></h2>
			<?php comments_template(); ?>
		</div>
	</section>
	<?php endif; ?>
</main>
	<?php
endwhile;
get_footer();
