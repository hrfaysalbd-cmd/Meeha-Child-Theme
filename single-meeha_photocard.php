<?php
/**
 * Meeha News — একটা উক্তি (ফটোকার্ড) পোস্টের নিজস্ব পেজ।
 * এখানেই "ভোট দিন" বাটনে ক্লিক করলে আসা হয় — ভোট ফর্ম, লাইভ ভোট-সংখ্যা,
 * কাউন্টডাউন, সাম্প্রতিক ভোটারদের মার্কি, আর (মেয়াদ শেষ হলে) ফলাফল
 * বার-চার্ট + ডাউনলোডযোগ্য ফলাফল-কার্ড — সবকিছু এই পেজেই।
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>

<main id="meeha-single">
	<?php while ( have_posts() ) : the_post(); ?>

	<div class="meeha-container meeha-quote-single-layout">

	<div class="meeha-quote-single">

		<?php
		meeha_render_photocard_item( get_post() );

		$poll = meeha_get_poll_data( get_the_ID() );
		if ( ! empty( $poll['enabled'] ) ) :
			$image_url   = get_the_post_thumbnail_url( get_the_ID(), 'large' );
			$attribution = get_post_meta( get_the_ID(), '_meeha_attribution', true );
			$branding    = meeha_get_photocard_branding();
			?>
			<section id="meeha-poll" class="meeha-poll" data-post-id="<?php echo esc_attr( get_the_ID() ); ?>">

				<!-- লাইভ ভোট-সংখ্যা ব্যাজ — মেয়াদ চলাকালীন সবসময় দেখাবে -->
				<div class="meeha-poll__live">
					<span class="meeha-poll__live-dot" aria-hidden="true"></span>
					<span class="meeha-poll__live-label">লাইভ</span>
					<span class="meeha-poll__live-text">
						<strong id="meeha-poll-live-count"><?php echo esc_html( number_format_i18n( $poll['total'] ) ); ?></strong> জন ভোট দিয়েছেন
					</span>
				</div>

				<?php if ( empty( $poll['is_closed'] ) ) : ?>

					<!-- কাউন্টডাউন -->
					<div class="meeha-poll__countdown" id="meeha-poll-countdown" data-end="<?php echo esc_attr( $poll['end_time'] ); ?>">
						⏳ <span id="meeha-poll-countdown-text">হিসাব হচ্ছে...</span> সময় বাকি আছে
					</div>

					<h2 class="meeha-poll__title">আপনার মতামত দিন</h2>
					<form class="meeha-poll__form" id="meeha-poll-form">
						<div class="meeha-poll__options">
							<label class="meeha-poll__option">
								<input type="radio" name="choice" value="a" required />
								<span><?php echo esc_html( $poll['option_a'] ); ?></span>
							</label>
							<label class="meeha-poll__option">
								<input type="radio" name="choice" value="b" required />
								<span><?php echo esc_html( $poll['option_b'] ); ?></span>
							</label>
						</div>

						<input type="text" name="name" placeholder="আপনার নাম" required />
						<input type="tel" name="phone" placeholder="মোবাইল নাম্বার (একটা নাম্বারে একবার ভোট)" required />
						<input type="email" name="email" id="meeha-poll-email" placeholder="ইমেইল (ঐচ্ছিক)" />

						<?php if ( ! is_user_logged_in() && meeha_is_auto_registration_enabled() ) : ?>
							<label class="meeha-poll__register-label">
								<input type="checkbox" name="meeha_register" value="1" onchange="document.getElementById('meeha-poll-email').required = this.checked;" />
								আপনি কি মিহা নিউজে রেজিস্ট্রেশন করতে চান? (একাউন্টের তথ্য ইমেইলে পাঠানো হবে)
							</label>
						<?php endif; ?>

						<p class="meeha-poll__privacy-note">আপনার ফোন নাম্বার ও ইমেইল কখনো প্রকাশ্যে দেখানো হবে না — শুধু একবার-ভোট নিশ্চিত করতে ব্যবহৃত হয়, ঠিক যেমনটা কমেন্ট সেকশনে হয়।</p>

						<button type="submit" class="meeha-poll__submit">ভোট দিন</button>
						<p class="meeha-poll__msg" id="meeha-poll-msg" role="status"></p>
					</form>

					<!-- সাম্প্রতিক ভোটারদের স্ক্রলিং মার্কি -->
					<div class="meeha-poll__marquee-wrap">
						<p class="meeha-poll__marquee-title">ইতোমধ্যে ভোট দিয়েছেন (<span id="meeha-poll-marquee-count"><?php echo esc_html( number_format_i18n( $poll['total'] ) ); ?></span>)</p>
						<div class="meeha-poll__marquee">
							<div class="meeha-poll__marquee-track" id="meeha-poll-marquee-track">
								<?php
								$voters = meeha_get_recent_voters( get_the_ID(), 20 );
								if ( $voters ) {
									for ( $r = 0; $r < 2; $r++ ) { // দুইবার বসানো হচ্ছে যাতে স্ক্রল লুপ মসৃণ দেখায়
										foreach ( $voters as $voter ) {
											echo '<span class="meeha-poll__marquee-item">' . esc_html( $voter['name'] ) . ' — ' . esc_html( $voter['phone'] ) . '</span>';
										}
									}
								} else {
									echo '<span class="meeha-poll__marquee-item">এখনো কেউ ভোট দেননি — প্রথম ভোটটি আপনিই দিন!</span>';
								}
								?>
							</div>
						</div>
					</div>

				<?php else : ?>

					<h2 class="meeha-poll__title">ভোটের ফলাফল</h2>
					<?php
					$total = max( 1, $poll['total'] ); // শূন্য দিয়ে ভাগ এড়াতে
					$pct_a = round( ( $poll['count_a'] / $total ) * 100 );
					$pct_b = round( ( $poll['count_b'] / $total ) * 100 );
					?>
					<div class="meeha-poll__results">
						<div class="meeha-poll__result-row<?php echo 'a' === $poll['winner'] ? ' meeha-poll__result-row--winner' : ''; ?>">
							<div class="meeha-poll__result-label">
								<span><?php echo esc_html( $poll['option_a'] ); ?><?php echo 'a' === $poll['winner'] ? ' 🏆' : ''; ?></span>
								<span><?php echo esc_html( $pct_a ); ?>%</span>
							</div>
							<div class="meeha-poll__bar"><div class="meeha-poll__bar-fill" style="width:<?php echo esc_attr( $pct_a ); ?>%"></div></div>
						</div>
						<div class="meeha-poll__result-row<?php echo 'b' === $poll['winner'] ? ' meeha-poll__result-row--winner' : ''; ?>">
							<div class="meeha-poll__result-label">
								<span><?php echo esc_html( $poll['option_b'] ); ?><?php echo 'b' === $poll['winner'] ? ' 🏆' : ''; ?></span>
								<span><?php echo esc_html( $pct_b ); ?>%</span>
							</div>
							<div class="meeha-poll__bar"><div class="meeha-poll__bar-fill meeha-poll__bar-fill--b" style="width:<?php echo esc_attr( $pct_b ); ?>%"></div></div>
						</div>
					</div>
					<p class="meeha-poll__total"><?php echo esc_html( number_format_i18n( $poll['total'] ) ); ?> জন ভোট দিয়েছেন — ভোটের মেয়াদ শেষ হয়েছে।</p>

					<!-- ফলাফল একটা ফটোকার্ড হিসেবে ডাউনলোড করার বাটন — সোশ্যাল মিডিয়ায় শেয়ার করার জন্য -->
					<?php $photocard_settings = meeha_get_photocard_settings(); ?>
					<div class="meeha-photocard"
						data-layout="poll-result"
						data-file-id="<?php echo esc_attr( get_the_ID() . '9' ); ?>"
						data-post-id="<?php echo esc_attr( get_the_ID() ); ?>"
						data-quote="<?php echo esc_attr( get_the_title() ); ?>"
						data-attribution="<?php echo esc_attr( $attribution ); ?>"
						data-image="<?php echo esc_url( $image_url ); ?>"
						data-logo="<?php echo esc_url( $branding['logo_url'] ); ?>"
						data-sitename="<?php echo esc_attr( $branding['site_name'] ); ?>"
						data-option-a="<?php echo esc_attr( $poll['option_a'] ); ?>"
						data-option-b="<?php echo esc_attr( $poll['option_b'] ); ?>"
						data-count-a="<?php echo esc_attr( $poll['count_a'] ); ?>"
						data-count-b="<?php echo esc_attr( $poll['count_b'] ); ?>"
						data-winner="<?php echo esc_attr( $poll['winner'] ); ?>"
						data-ad-enabled="<?php echo esc_attr( $photocard_settings['footer_ad_enabled'] ? '1' : '0' ); ?>"
						data-ad-image="<?php echo esc_url( $photocard_settings['footer_ad_image'] ); ?>">
						<button type="button" class="meeha-photocard__download meeha-photocard__download--auto">
							<span aria-hidden="true">⤓</span> ফলাফল-কার্ড ডাউনলোড করুন
						</button>
					</div>

				<?php endif; ?>

			</section>
			<?php
		endif;

		meeha_render_related_quotes( get_the_ID() );
		?>

	</div>

	<?php meeha_render_quote_sidebar( get_the_ID() ); ?>

	</div>

	<div class="meeha-container">
		<div class="meeha-comments">
			<?php comments_template(); ?>
		</div>
	</div>

	<?php endwhile; ?>
</main>

<?php get_footer(); ?>