<?php
/**
 * Meeha News — কাস্টম হোমপেজ টেমপ্লেট
 * Elementor বা কোনো পেজ-বিল্ডার ছাড়াই সরাসরি PHP দিয়ে বানানো,
 * যাতে সবচেয়ে বেশি-দেখা পেজটি সবচেয়ে দ্রুত লোড হয়।
 *
 * সেকশনগুলো Admin > Meeha হোমপেজ থেকে নিয়ন্ত্রিত হয়।
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header(); // Parent থিমের হেডার/নেভিগেশন ব্যবহার হবে, যাতে পুরো সাইটে ধারাবাহিকতা থাকে
?>

<main id="meeha-home">

	<div class="meeha-container">

		<?php
		/* ============================================
		   হিরো / টপ স্টোরি সেকশন — নতুন ডিজাইন (রিডিজাইন v3)
		   বড় ডমিনেটিং বাম-পাশের ছবি বাদ দিয়ে Al Jazeera/AFP-স্টাইল টেক্সট-
		   প্রাধান্য দেওয়া টপ স্টোরি বার (মডারেট, ঐচ্ছিক ছবি) + নিচে ছোট
		   কার্ডের একটা হেডলাইন গ্রিড (হাইলাইট ক্যাটাগরিগুলো থেকে)
		============================================ */
		$hero_cat = meeha_get_hero_category();
		if ( $hero_cat > 0 ) :
			$meeha_force_distinct_lead = function () {
				return 'DISTINCT';
			};
			add_filter( 'posts_distinct', $meeha_force_distinct_lead );
			$hero_query = new WP_Query( array(
				'cat'                 => $hero_cat,
				'posts_per_page'      => 1,
				'ignore_sticky_posts' => true,
				'no_found_rows'       => true,
			) );
			remove_filter( 'posts_distinct', $meeha_force_distinct_lead );

			if ( $hero_query->have_posts() ) :
				$hero_query->the_post();
				$lead_id        = get_the_ID();
				$show_top_image = meeha_get_hero_top_image_enabled();
				?>
				<section class="meeha-newshero" id="meeha-hero">
					<a href="<?php the_permalink(); ?>" class="meeha-newshero__top">
						<?php if ( $show_top_image ) : ?>
							<div class="meeha-newshero__top-image"><?php meeha_card_thumbnail( 'large' ); ?></div>
						<?php endif; ?>
						<div class="meeha-newshero__top-text">
							<span class="meeha-eyebrow"><?php echo esc_html( get_the_category()[0]->name ?? '' ); ?></span>
							<h1 class="meeha-newshero__title"><?php the_title(); ?></h1>
							<p class="meeha-newshero__excerpt"><?php echo esc_html( wp_trim_words( get_the_excerpt(), 26 ) ); ?></p>
							<span class="meeha-meta"><?php echo esc_html( meeha_bengali_time_ago( get_the_time( 'U' ) ) ); ?></span>
						</div>
					</a>

					<?php
					wp_reset_postdata();

					// প্রতিটা হাইলাইট ক্যাটাগরি থেকে আলাদা আলাদা কুয়েরি — যাতে প্রতিটা
					// ক্যাটাগরি থেকে নির্দিষ্ট সংখ্যক পোস্ট নিশ্চিত হয়, এবং প্রতিটা
					// আইটেমের সাথে তার নিজের ক্যাটাগরির নাম রাখা যায়। একই পোস্ট
					// একাধিক হাইলাইট ক্যাটাগরিতেই থাকলে (বা লিড পোস্টের সাথে
					// শেয়ার করা থাকলে) যেন দুইবার না দেখায়, তাই ব্যবহৃত সব
					// পোস্ট ID-র একটা চলমান তালিকা রাখা হচ্ছে এবং পরের প্রতিটা
					// কুয়েরিতে সেগুলো বাদ দেওয়া হচ্ছে।
					$highlight_cats = meeha_get_hero_highlight_categories();
					$per_cat        = meeha_get_hero_count();
					$side_items     = array();
					$used_ids       = array( $lead_id );

					foreach ( $highlight_cats as $cat_id ) {
						$side_query = new WP_Query( array(
							'cat'                 => $cat_id,
							'posts_per_page'      => $per_cat,
							'post__not_in'        => $used_ids,
							'ignore_sticky_posts' => true,
							'no_found_rows'       => true,
						) );


						$cat_term = get_term( $cat_id );
						foreach ( $side_query->posts as $side_post ) {
							// হার্ড সেফগার্ড: কোনো প্লাগইন/ফিল্টার যদি তারপরও একটা
							// আগে-ব্যবহৃত পোস্ট ফেরত দেয়, সেটা এখানে বাদ দেওয়া হচ্ছে।
							if ( in_array( (int) $side_post->ID, $used_ids, true ) ) {
								continue;
							}
							$side_items[] = array(
								'post'     => $side_post,
								'cat_name' => ( $cat_term && ! is_wp_error( $cat_term ) ) ? $cat_term->name : '',
							);
							$used_ids[] = (int) $side_post->ID;
						}
						wp_reset_postdata();
					}


					if ( $side_items ) {
						$hero_mobile_total = meeha_get_hero_count_mobile() * max( 1, count( $highlight_cats ) );
						if ( $hero_mobile_total < count( $side_items ) ) {
							printf(
								'<style>@media (max-width:767px){#meeha-hero .meeha-newshero__grid>a:nth-child(n+%d){display:none}}</style>',
								(int) ( $hero_mobile_total + 1 )
							);
						}
					}
					?>

					<?php if ( $side_items ) : ?>
						<div class="meeha-newshero__grid">
							<?php
							global $post;

							foreach ( $side_items as $item ) :
								// setup_postdata() does not assign the global $post itself.
								$post = $item['post'];
								setup_postdata( $post );
								?>
								<a href="<?php the_permalink(); ?>" class="meeha-newshero__card">
									<div class="meeha-newshero__card-thumb">
										<?php meeha_card_thumbnail( 'thumbnail' ); ?>
									</div>

									<span class="meeha-newshero__card-text">
										<?php if ( ! empty( $item['cat_name'] ) ) : ?>
											<span class="meeha-newshero__card-cat">
												<?php echo esc_html( $item['cat_name'] ); ?>
											</span>
										<?php endif; ?>

										<span class="meeha-newshero__card-title">
											<?php the_title(); ?>
										</span>
									</span>
								</a>
							<?php endforeach; ?>
						</div>

						<?php wp_reset_postdata(); ?>
					<?php endif; ?>
				</section>
				<?php
			endif;
			wp_reset_postdata();
		endif;
		?>

		<?php
		/* ============================================
		   সর্বশেষ — সাইট-জুড়ে সবচেয়ে নতুন খবর, কোনো ক্যাটাগরি ফিল্টার ছাড়া
		============================================ */
		$latest = meeha_get_latest_settings();
		if ( $latest['enabled'] ) :
			$latest_query = new WP_Query( array(
				'posts_per_page'      => $latest['count'],
				'ignore_sticky_posts' => true,
			) );
			if ( $latest_query->have_posts() ) :
				meeha_mobile_count_style( 'meeha-latest-section', $latest['count'], $latest['mobile_count'], 'grid' );
				?>
				<section class="meeha-section" id="meeha-latest-section">
					<div class="meeha-section__head">
						<span class="meeha-eyebrow-bar"></span>
						<h2>সর্বশেষ</h2>
					</div>
					<div class="meeha-grid">
						<?php while ( $latest_query->have_posts() ) : $latest_query->the_post(); ?>
							<a href="<?php the_permalink(); ?>" class="meeha-card">
								<div class="meeha-card__image"><?php meeha_card_thumbnail( 'medium' ); ?></div>
								<h3 class="meeha-card__title"><?php the_title(); ?></h3>
								<span class="meeha-meta"><?php echo esc_html( meeha_bengali_time_ago( get_the_time( 'U' ) ) ); ?></span>
							</a>
						<?php endwhile; ?>
					</div>
				</section>
				<?php
			endif;
			wp_reset_postdata();
		endif;
		?>

		<?php
		meeha_render_topic_boxes_section();
		?>

		<?php
		/* ============================================
		   এরিয়া ভিত্তিক খবর — টপিক বক্সের নিচে, ট্যাব সেকশনের ওপরে
		============================================ */
		meeha_render_area_filter_section();
		?>

		<?php
		/* ============================================
		   সর্বাধিক পঠিত — ফিরিয়ে আনা হলো (আগে সরানো হয়েছিল, সাইডবারের
		   ট্যাব-উইজেটের "জনপ্রিয়" ট্যাবের সাথে মিশে গিয়েছিল); এখন হোমপেজে
		   নিজের আলাদা সেকশন হিসেবে আবার আছে, একই ম্যানুয়ালি-চিহ্নিত পোস্ট
		   ডেটা ব্যবহার করে (Quick Edit-এর "সার্বাধিক পঠিত" চেকবক্স)
		============================================ */
		meeha_render_most_read_section();
		?>

		<?php
		meeha_render_tabbed_sidebar_section();
		?>

		<?php
		/* ============================================
		   ক্যাটাগরি সেকশন ১ ও ২
		============================================ */
		for ( $i = 1; $i <= 2; $i++ ) {
			meeha_render_category_section( $i );
		}
		?>

		<?php
		/* ============================================
		   ক্যাটাগরি সেকশন ৩, ৪, ৫
		============================================ */
		for ( $i = 3; $i <= 5; $i++ ) {
			meeha_render_category_section( $i );
		}
		?>

	</div><!-- .meeha-container -->

	<?php
	/* ============================================
	   নিউজলেটার ব্যান্ড
	============================================ */
	?>
	<section class="meeha-newsletter">
		<div class="meeha-container meeha-newsletter__inner">
			<h2><?php echo esc_html( meeha_get_newsletter_heading() ); ?></h2>
			<?php
			$mailpoet_id = meeha_get_mailpoet_form_id();
			if ( $mailpoet_id && shortcode_exists( 'mailpoet_form' ) ) {
				echo do_shortcode( '[mailpoet_form id="' . esc_attr( $mailpoet_id ) . '"]' );
			} else {
				?>
				<form class="meeha-newsletter__form" id="meeha-newsletter-form">
					<input type="email" name="email" placeholder="আপনার ইমেইল ঠিকানা" required />
					<button type="submit">সাবস্ক্রাইব করুন</button>
				</form>
				<p class="meeha-newsletter__msg" id="meeha-newsletter-msg" role="status"></p>
				<?php
			}
			?>
		</div>
	</section>

</main>

<?php get_footer(); ?>