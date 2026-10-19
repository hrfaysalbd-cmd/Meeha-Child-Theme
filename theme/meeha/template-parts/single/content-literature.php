<?php
/**
 * Single — literature (proto/single-literature.html). Paper, Tiro Bangla, centred head; poems are typeset as
 * stanzas (centred block, left-aligned lines, or fully centred with "মাঝখানে সাজান"); prose genres use the
 * literary prose column. No ads, no ticker.
 *
 * @package meeha
 *
 * @var array $args post, variant.
 */

defined( 'ABSPATH' ) || exit;

$meeha_p     = $args['post'];
$meeha_gid   = meeha_lit_genre_of( $meeha_p );
$meeha_g     = $meeha_gid ? meeha_lit_genre( $meeha_gid ) : null;
$meeha_poem  = 'poem' === $meeha_gid;
$meeha_body  = meeha_single_body( $meeha_p, 'literature', array() );
$meeha_by    = meeha_post_byline( $meeha_p, 72 ); // 2.0.3: guest author (no archive link, no WP bio).
$meeha_uid   = $meeha_by['user_id'];
$meeha_wr    = meeha_lit_writer( $meeha_p );
$meeha_from  = '';
$meeha_coll  = meeha_lit_field( $meeha_p, 'collection' );
if ( $meeha_poem && '' !== $meeha_coll ) {
	$meeha_from = 'কাব্যগ্রন্থ ‘' . trim( $meeha_coll, '‘’' ) . '’ থেকে';
} elseif ( 'translation' === $meeha_gid && '' !== meeha_lit_field( $meeha_p, 'orig_author' ) ) {
	$meeha_from = 'মূল: ' . meeha_lit_field( $meeha_p, 'orig_author' ) . ( '' !== meeha_lit_field( $meeha_p, 'orig_lang' ) ? ' (' . meeha_lit_field( $meeha_p, 'orig_lang' ) . ')' : '' ) . ( '' !== meeha_lit_field( $meeha_p, 'translator' ) ? ' · অনুবাদ: ' . meeha_lit_field( $meeha_p, 'translator' ) : '' );
} elseif ( 'interview' === $meeha_gid && '' !== meeha_lit_field( $meeha_p, 'interviewee' ) ) {
	$meeha_from = 'সাক্ষাৎকার: ' . meeha_lit_field( $meeha_p, 'interviewee' ) . ( '' !== meeha_lit_field( $meeha_p, 'designation' ) ? ', ' . meeha_lit_field( $meeha_p, 'designation' ) : '' );
} elseif ( 'story' === $meeha_gid && '' !== meeha_lit_field( $meeha_p, 'series' ) ) {
	$meeha_from = meeha_lit_field( $meeha_p, 'series' ) . ( '' !== meeha_lit_field( $meeha_p, 'part' ) ? ' · পর্ব ' . meeha_bn_digits( meeha_lit_field( $meeha_p, 'part' ) ) : '' );
}
$meeha_cls = 'm-poem m-litsingle' . ( $meeha_gid ? ' m-g-' . $meeha_gid : '' ) . ( $meeha_poem ? '' : ' m-poem--prose' ) . ( '1' === meeha_lit_field( $meeha_p, 'center' ) ? ' m-poem--center' : '' );
?>
<main id="main">
	<article id="post-<?php echo (int) $meeha_p->ID; ?>" <?php post_class( $meeha_cls, $meeha_p ); ?><?php echo $meeha_g ? meeha_lit_genre_style( $meeha_g ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>>
		<div class="m-container">
			<?php meeha_breadcrumb(); ?>
			<header class="m-poem__head">
				<?php if ( $meeha_g ) : ?>
					<a class="m-poem__genre" href="<?php echo esc_url( $meeha_g['more_url'] ? $meeha_g['more_url'] : meeha_lit_landing_url() ); ?>"><?php echo esc_html( $meeha_g['label'] ); ?></a>
				<?php endif; ?>
				<h1 class="m-poem__title"><?php echo esc_html( get_the_title( $meeha_p ) ); ?></h1>
				<p class="m-poem__poet"><?php echo meeha_byline_photo_html( $meeha_by, 40 ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php if ( $meeha_by['url'] ) : ?><a href="<?php echo esc_url( $meeha_by['url'] ); ?>" rel="author"><?php echo esc_html( $meeha_wr ); ?></a><?php else : ?><span><?php echo esc_html( $meeha_wr ); ?></span><?php endif; ?></p>
				<?php if ( '' !== $meeha_from ) : ?>
					<p class="m-poem__from"><?php echo esc_html( $meeha_from ); ?></p>
				<?php endif; ?>
				<span class="m-ornament" aria-hidden="true"><i></i></span>
				<div class="m-litools">
					<?php
					if ( meeha_single_opt( 'summary_button', 1 ) && '' !== trim( (string) meeha_get_summary( $meeha_p->ID ) ) ) {
						echo '<button class="m-btn m-btn--ghost m-sumbtn" type="button" data-summary-open="m-summary" aria-haspopup="dialog">' . meeha_icon( 'sparkle', array( 'size' => 'sm' ) ) . '<span>সারাংশ পড়ুন</span></button>'; // phpcs:ignore WordPress.Security.EscapeOutput
					}
					meeha_single_fontsize();
					?>
				</div>
			</header>

			<?php if ( 'books' === $meeha_gid ) : ?>
				<div class="m-bookinfo">
					<?php echo meeha_lit_book_cover( $meeha_p, 'm-bookinfo__cover' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<dl>
						<?php
						foreach ( array( 'book_title' => 'বই', 'book_author' => 'লেখক', 'publisher' => 'প্রকাশক', 'year' => 'প্রকাশকাল', 'pages' => 'পৃষ্ঠা', 'isbn' => 'ISBN', 'price' => 'মূল্য' ) as $meeha_k => $meeha_l ) {
							$meeha_v = meeha_lit_field( $meeha_p, $meeha_k );
							if ( '' !== $meeha_v ) {
								$meeha_v = in_array( $meeha_k, array( 'pages', 'year' ), true ) ? meeha_bn_digits( $meeha_v ) : ( 'price' === $meeha_k ? meeha_bn_digits( $meeha_v ) . ' টাকা' : $meeha_v );
								echo '<dt>' . esc_html( $meeha_l ) . '</dt><dd>' . esc_html( $meeha_v ) . '</dd>';
							}
						}
						?>
					</dl>
					<?php echo meeha_lit_stars( (int) meeha_lit_field( $meeha_p, 'rating' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				</div>
			<?php endif; ?>

			<?php if ( $meeha_poem ) : ?>
				<div class="m-poem__body" data-prose>
					<?php echo $meeha_body['html']; // phpcs:ignore WordPress.Security.EscapeOutput ?>
				</div>
				<?php if ( '' !== meeha_lit_field( $meeha_p, 'place_date' ) ) : ?>
					<p class="m-poem__date"><?php echo esc_html( meeha_lit_field( $meeha_p, 'place_date' ) ); ?></p>
				<?php endif; ?>
			<?php else : ?>
				<?php meeha_single_lead_figure( $meeha_p ); ?>
				<div class="m-prose m-litprose" data-prose>
					<?php echo $meeha_body['html']; // phpcs:ignore WordPress.Security.EscapeOutput ?>
				</div>
			<?php endif; ?>

			<?php echo meeha_lit_recite_html( $meeha_p ); // phpcs:ignore WordPress.Security.EscapeOutput ?>

			<footer class="m-litfoot">
				<?php
				meeha_share_buttons(
					$meeha_p,
					array(
						'networks' => meeha_single_share_networks(),
						'label'    => 'শেয়ার করুন',
					)
				);
				meeha_single_reactions( $meeha_p, 'literature' );
				$meeha_bio   = $meeha_uid ? trim( (string) get_the_author_meta( 'description', $meeha_uid ) ) : '';
				$meeha_count = $meeha_uid ? (int) count_user_posts( $meeha_uid, 'post', true ) : 0;
				?>
				<div class="m-poetbio">
					<?php echo meeha_byline_photo_html( $meeha_by, 72 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<p class="m-poetbio__name"><?php echo esc_html( $meeha_wr ); ?></p>
					<?php if ( $meeha_by['is_guest'] && '' !== $meeha_by['title'] && '' === meeha_lit_field( $meeha_p, 'poet' ) ) : ?>
						<p><?php echo esc_html( $meeha_by['title'] ); ?></p>
					<?php elseif ( '' !== $meeha_bio && '' === meeha_lit_field( $meeha_p, 'poet' ) ) : ?>
						<p><?php echo esc_html( $meeha_bio ); ?></p>
					<?php endif; ?>
					<?php if ( $meeha_by['url'] ) : ?>
					<p class="m-authorbox__links"><a href="<?php echo esc_url( $meeha_by['url'] ); ?>"><?php echo esc_html( ( $meeha_poem ? 'কবির আরও কবিতা' : 'লেখকের আরও লেখা' ) . ( $meeha_count > 1 ? ' (' . meeha_bn_digits( $meeha_count ) . ')' : '' ) ); ?></a></p>
					<?php endif; ?>
				</div>
				<?php
				meeha_single_correction( $meeha_p );
				meeha_single_comments_card( $meeha_p, 'literature' );
				?>
			</footer>
		</div>
	</article>

	<?php
	if ( $meeha_g ) {
		$meeha_more = meeha_lit_genre_posts( $meeha_g, 3, array( $meeha_p->ID ) );
		if ( $meeha_more ) {
			echo '<section class="m-section m-g-' . esc_attr( $meeha_gid ) . '" aria-labelledby="m-more-h"' . meeha_lit_genre_style( $meeha_g ) . '><div class="m-container">'; // phpcs:ignore WordPress.Security.EscapeOutput
			meeha_sechead(
				'আরও ' . $meeha_g['label'],
				array(
					'url'        => $meeha_g['more_url'],
					'more_label' => $meeha_g['more'],
					'id'         => 'm-more-h',
				)
			);
			if ( 'poem' === $meeha_gid ) {
				echo '<div class="m-versegrid">';
				foreach ( $meeha_more as $meeha_m ) {
					echo meeha_lit_verse_card( $meeha_m, $meeha_g ); // phpcs:ignore WordPress.Security.EscapeOutput
				}
				echo '</div>';
			} else {
				echo meeha_lit_layout_html( array_merge( $meeha_g, array( 'layout' => in_array( $meeha_g['layout'], array( 'books', 'cards', 'essays' ), true ) ? $meeha_g['layout'] : 'essays' ) ), $meeha_more ); // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '</div></section>';
		}
	}
	?>
</main>
<?php
meeha_single_summary_dialog( $meeha_p );
