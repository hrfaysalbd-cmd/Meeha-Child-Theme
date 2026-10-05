<?php
/**
 * Meeha News — কাস্টম ফুটার (প্যারেন্ট থিমের বিল্ডার-ফুটার সম্পূর্ণ প্রতিস্থাপন করে)
 *
 * header.php-তে #content ও #page ইচ্ছাকৃতভাবে খোলা রাখা হয়েছে (Underscores
 * কনভেনশন) - এখানেই সেগুলো বন্ধ করা হচ্ছে, তারপর wp_footer() ও </body></html>।
 *
 * অ্যাডমিন থেকে "ডিফল্ট ফুটার ব্যবহার করুন" চালু থাকলে প্যারেন্ট থিমের নিজের
 * footer.php সরাসরি লোড হবে (জরুরি ফলব্যাক/সেফটি ভালভ)।
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$meeha_footer = meeha_get_footer_settings();

if ( ! $meeha_footer['enabled'] ) {
	echo '</div><!-- #content -->';
	echo '</div><!-- #page -->';
	include get_template_directory() . '/footer.php';
	return;
}

$meeha_header_settings = function_exists( 'meeha_get_header_settings' ) ? meeha_get_header_settings() : array( 'social' => array() );
$meeha_has_social       = ! empty( array_filter( $meeha_header_settings['social'] ) );
$meeha_has_columns      = false;
foreach ( $meeha_footer['columns'] as $meeha_col ) {
	if ( $meeha_col['title'] || array_filter( $meeha_col['links'] ) ) {
		$meeha_has_columns = true;
		break;
	}
}
?>
	</div><!-- #content -->

	<footer id="colophon" class="meeha-footer">

		<?php if ( $meeha_footer['newsletter_enabled'] ) : ?>
		<div class="meeha-footer__newsletter">
			<div class="meeha-footer__inner">
				<div class="meeha-footer__newsletter-text">
					<h2><?php echo esc_html( meeha_get_newsletter_heading() ); ?></h2>
					<p>প্রতিদিনের গুরুত্বপূর্ণ খবর সরাসরি আপনার ইমেইলে পেতে সাবস্ক্রাইব করুন।</p>
				</div>
				<?php
				$meeha_mailpoet_id = meeha_get_mailpoet_form_id();
				if ( $meeha_mailpoet_id && shortcode_exists( 'mailpoet_form' ) ) {
					echo do_shortcode( '[mailpoet_form id="' . esc_attr( $meeha_mailpoet_id ) . '"]' );
				} else {
					?>
					<form class="meeha-footer__newsletter-form" id="meeha-footer-newsletter-form">
						<input type="email" name="email" placeholder="আপনার ইমেইল ঠিকানা" required />
						<button type="submit">সাবস্ক্রাইব করুন</button>
					</form>
					<p class="meeha-footer__newsletter-msg" id="meeha-footer-newsletter-msg" role="status"></p>
					<?php
				}
				?>
			</div>
		</div>
		<?php endif; ?>

		<div class="meeha-footer__main">
			<div class="meeha-footer__inner meeha-footer__grid">

				<div class="meeha-footer__brand">
					<?php if ( has_custom_logo() ) : ?>
						<div class="meeha-footer__logo"><?php the_custom_logo(); ?></div>
					<?php else : ?>
						<p class="meeha-footer__sitename"><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php bloginfo( 'name' ); ?></a></p>
					<?php endif; ?>

					<?php if ( $meeha_footer['about_text'] ) : ?>
						<p class="meeha-footer__about"><?php echo esc_html( $meeha_footer['about_text'] ); ?></p>
					<?php endif; ?>

					<?php if ( $meeha_has_social ) : ?>
						<ul class="meeha-footer__social">
							<?php foreach ( $meeha_header_settings['social'] as $meeha_net => $meeha_url ) : ?>
								<?php if ( empty( $meeha_url ) ) { continue; } ?>
								<li>
									<a href="<?php echo esc_url( $meeha_url ); ?>" target="_blank" rel="noopener noreferrer" aria-label="<?php echo esc_attr( ucfirst( $meeha_net ) ); ?>">
										<?php meeha_social_icon( $meeha_net ); ?>
									</a>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>

				<?php if ( $meeha_has_columns ) : ?>
					<?php foreach ( $meeha_footer['columns'] as $meeha_ci => $meeha_col ) : ?>
						<?php
						$meeha_links = array_filter( $meeha_col['links'], function ( $l ) {
							return ! empty( $l['label'] ) && ! empty( $l['url'] );
						} );
						if ( ! $meeha_col['title'] && ! $meeha_links ) {
							continue;
						}
						?>
						<div class="meeha-footer__col">
							<h3 class="meeha-footer__col-title" data-meeha-footer-toggle><?php echo esc_html( $meeha_col['title'] ? $meeha_col['title'] : '—' ); ?></h3>
							<?php if ( $meeha_links ) : ?>
								<ul class="meeha-footer__col-list">
									<?php foreach ( $meeha_links as $meeha_link ) : ?>
										<li><a href="<?php echo esc_url( $meeha_link['url'] ); ?>"><?php echo esc_html( $meeha_link['label'] ); ?></a></li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				<?php endif; ?>

			</div>
		</div>

		<div class="meeha-footer__bottom">
			<div class="meeha-footer__inner meeha-footer__bottom-inner">
				<p class="meeha-footer__copyright">
					<?php echo wp_kses_post( str_replace( '{year}', gmdate( 'Y' ), $meeha_footer['copyright_text'] ) ); ?>
				</p>
				<?php if ( has_nav_menu( 'bottom-menu' ) ) : ?>
					<?php
					wp_nav_menu( array(
						'theme_location' => 'bottom-menu',
						'container'      => false,
						'menu_class'     => 'meeha-footer__bottom-menu',
						'fallback_cb'    => false,
						'depth'          => 1,
					) );
					?>
				<?php endif; ?>
			</div>
		</div>

		<?php if ( $meeha_footer['scrolltop_enabled'] ) : ?>
			<button type="button" id="meeha-scroll-top" class="meeha-scroll-top" aria-label="ওপরে ফিরুন">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 15l7-7 7 7"/></svg>
			</button>
		<?php endif; ?>

	</footer><!-- #colophon -->

	</div><!-- #page -->

	<?php wp_footer(); ?>
</body>
</html>
