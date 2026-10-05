<?php
/**
 * Meeha News — সিঙ্গেল আর্টিকেল টেমপ্লেট
 * Elementor ছাড়া হাতে-লেখা, যাতে সবচেয়ে বেশি-পড়া পেজ টাইপ সবচেয়ে দ্রুত লোড হয়।
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>

<?php meeha_render_single_subheader(); ?>

<!-- রিডিং প্রোগ্রেস বার — স্ক্রল করার সাথে সাথে ভরে ওঠে -->
<div id="meeha-progress"><div id="meeha-progress__bar"></div></div>

<main id="meeha-single">

	<div class="meeha-font-control" id="meeha-font-control">
		<button type="button" class="meeha-font-control__btn" data-font-action="increase" aria-label="লেখা বড় করুন">+</button>
		<button type="button" class="meeha-font-control__btn" data-font-action="decrease" aria-label="লেখা ছোট করুন">−</button>
		<button type="button" class="meeha-font-control__btn meeha-font-control__listen" id="meeha-float-listen-btn" aria-label="শুনুন">
			<span aria-hidden="true">▶</span>
		</button>
	</div>
	<?php while ( have_posts() ) : the_post(); ?>

	<div class="meeha-single-layout meeha-container">

		<article class="meeha-article">

			<header class="meeha-article__header">
				<?php
				// আগে এখানে .meeha-eyebrow দিয়ে ক্যাটাগরি আবার দেখানো হতো -
				// কিন্তু meeha_render_single_subheader() থেকেই ব্রেডক্রাম্বে
				// ক্যাটাগরি দেখানো হয়, তাই এই "মাঝের" ডুপ্লিকেট বাদ দেওয়া হলো।
				$cats = get_the_category();
				?>

				<h1 class="meeha-article__title"><?php the_title(); ?></h1>

				<?php if ( has_excerpt() ) : ?>
					<p class="meeha-article__subtitle"><?php echo esc_html( get_the_excerpt() ); ?></p>
				<?php endif; ?>

				<div class="meeha-byline">
					<?php echo get_avatar( get_the_author_meta( 'ID' ), 40, '', '', array( 'class' => 'meeha-byline__avatar' ) ); ?>
					<div class="meeha-byline__text">
						<span class="meeha-byline__name"><?php the_author(); ?><?php $title = get_the_author_meta( 'meeha_author_title' ); if ( $title ) : ?> <span class="meeha-byline__title">· <?php echo esc_html( $title ); ?></span><?php endif; ?></span>
						<span class="meeha-byline__meta">
							<?php echo esc_html( meeha_bengali_date() ); ?>
							· <?php echo esc_html( meeha_bn_digits( meeha_reading_time( get_the_content() ) ) ); ?> মিনিট পড়ার সময়
							<?php $views = meeha_get_view_count( get_the_ID() ); if ( $views > 0 ) : ?>
								· <?php echo esc_html( meeha_bn_digits( number_format_i18n( $views ) ) ); ?> বার পঠিত
							<?php endif; ?>
						</span>
					</div>
				</div>

				<div class="meeha-toolbar">
					<?php
					$summary = meeha_get_summary( get_the_ID() );
					if ( $summary ) :
						?>
						<button type="button" class="meeha-summary-btn" data-summary-target="meeha-summary-<?php echo esc_attr( get_the_ID() ); ?>" data-permalink="<?php echo esc_url( get_permalink() ); ?>">
							<span aria-hidden="true">⚡</span> <span class="meeha-toolbar__label">সারাংশ পড়ুন</span>
						</button>
						<div class="meeha-summary-content" id="meeha-summary-<?php echo esc_attr( get_the_ID() ); ?>" hidden>
							<?php echo wp_kses_post( wpautop( $summary ) ); ?>
						</div>
						<?php
					endif;
					?>

						<button type="button" class="meeha-listen-btn" id="meeha-listen-btn" data-target="meeha-article-body">
						<span aria-hidden="true">🔊</span> <span class="meeha-toolbar__label">শুনুন</span>
					</button>

					<?php meeha_share_buttons(); ?>
				</div>
			</header>

			<?php if ( has_post_thumbnail() ) : ?>
				<figure class="meeha-article__figure">
					<?php the_post_thumbnail( 'large', array( 'alt' => get_the_title() ) ); ?>
					<?php
					$caption = get_the_post_thumbnail_caption();
					if ( $caption ) :
						?>
						<figcaption><?php echo esc_html( $caption ); ?></figcaption>
						<?php
					endif;
					?>
				</figure>
			<?php endif; ?>

			<?php meeha_render_auto_photocard( get_the_ID() ); ?>

			<div class="meeha-article__body-wrap" id="meeha-body-wrap">
				<div class="meeha-article__body" id="meeha-article-body">
					<?php the_content(); ?>
				</div>
				<div class="meeha-readmore-fade" id="meeha-readmore-fade"></div>
			</div>
			<button type="button" class="meeha-readmore-btn" id="meeha-readmore-btn" hidden>আরও পড়ুন</button>

			<?php
			$tags = get_the_tags();
			if ( $tags ) :
				?>
				<div class="meeha-tags">
					<?php foreach ( $tags as $tag ) : ?>
						<a href="<?php echo esc_url( get_tag_link( $tag->term_id ) ); ?>"><?php echo esc_html( $tag->name ); ?></a>
					<?php endforeach; ?>
				</div>
				<?php
			endif;
			?>

			<?php meeha_share_buttons( 'bottom' ); ?>

			<?php meeha_render_report_button( get_the_ID() ); ?>

			<?php
			$author_bio = get_the_author_meta( 'description' );
			if ( $author_bio ) :
				?>
				<div class="meeha-author-box">
					<?php echo get_avatar( get_the_author_meta( 'ID' ), 64 ); ?>
					<div>
						<span class="meeha-author-box__name"><?php the_author(); ?></span>
						<p class="meeha-author-box__bio"><?php echo esc_html( $author_bio ); ?></p>
					</div>
				</div>
				<?php
			endif;
			?>

			<?php meeha_render_reactions( get_the_ID() ); ?>

		</article>

		<div class="meeha-comments-wrap">
			<div class="meeha-comments">
				<?php comments_template(); ?>
			</div>
		</div>

		<?php meeha_render_single_sidebar( get_the_ID() ); ?>

	</div><!-- .meeha-single-layout -->

	<?php
	/* ============================================
	   কমেন্টের নিচে: সম্পর্কিত খবর → সাম্প্রতিক খবর → সার্বাধিক পঠিত
	============================================ */
	meeha_render_related_posts( get_the_ID(), $cats[0]->term_id ?? 0 );
	meeha_render_recent_section( get_the_ID() );
	meeha_render_most_read_section( true );
	?>

	<?php endwhile; ?>
</main>

<?php get_footer(); ?>