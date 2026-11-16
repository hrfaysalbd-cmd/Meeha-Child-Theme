<?php
/**
 * Footer main grid: brand (logo, about, editorial dl) · three link columns · correspondent card + social.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

$meeha_f   = meeha_opt( 'footer' );
$meeha_org = array(
	'editor'    => 'সম্পাদক',
	'publisher' => 'প্রকাশক',
	'address'   => 'ঠিকানা',
	'phone'     => 'বার্তাকক্ষ',
	'email'     => 'ইমেইল',
);
$meeha_has_org = false;
foreach ( array_keys( $meeha_org ) as $meeha_k ) {
	if ( '' !== trim( (string) $meeha_f[ $meeha_k ] ) ) {
		$meeha_has_org = true;
	}
}
$meeha_social = meeha_social_profiles();
$meeha_gnews  = (string) meeha_opt( 'seo', 'google_news_url', '' );
?>
<div class="m-container m-footer__main">
	<div class="m-footer__brand">
		<?php echo meeha_logo( 'footer', array( 'label' => get_bloginfo( 'name' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<?php if ( $meeha_f['about'] ) : ?><p class="m-footer__about"><?php echo esc_html( $meeha_f['about'] ); ?></p><?php endif; ?>
		<?php if ( $meeha_has_org ) : ?>
		<dl class="m-footer__org">
			<?php
			foreach ( $meeha_org as $meeha_k => $meeha_label ) {
				$meeha_v = trim( (string) $meeha_f[ $meeha_k ] );
				if ( '' === $meeha_v ) {
					continue;
				}
				if ( 'phone' === $meeha_k ) {
					$meeha_v = '<a href="tel:' . esc_attr( preg_replace( '/[^0-9+]/', '', meeha_en_digits( $meeha_v ) ) ) . '">' . esc_html( meeha_bn_digits( $meeha_v ) ) . '</a>';
				} elseif ( 'email' === $meeha_k ) {
					$meeha_v = '<a href="mailto:' . esc_attr( antispambot( $meeha_v ) ) . '">' . esc_html( antispambot( $meeha_v ) ) . '</a>';
				} else {
					$meeha_v = esc_html( $meeha_v );
				}
				echo '<div><dt>' . esc_html( $meeha_label ) . '</dt><dd>' . $meeha_v . '</dd></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
			}
			?>
		</dl>
		<?php endif; ?>
	</div>
	<nav class="m-footer__cols" id="m-footer-links" aria-label="ফুটারের লিংক">
		<?php
		for ( $meeha_n = 1; $meeha_n <= 3; $meeha_n++ ) {
			$meeha_links = meeha_footer_column_links( $meeha_n );
			if ( ! $meeha_links ) {
				continue;
			}
			echo '<div><h2 class="m-footer__h">' . esc_html( meeha_footer_column_title( $meeha_n ) ) . '</h2><ul class="m-footer__links" role="list">';
			foreach ( $meeha_links as $meeha_l ) {
				echo '<li><a href="' . esc_url( $meeha_l[1] ) . '">' . esc_html( $meeha_l[0] ) . '</a></li>';
			}
			echo '</ul></div>';
		}
		?>
	</nav>
	<div class="m-footer__aside">
		<?php if ( $meeha_f['corr_title'] || $meeha_f['corr_url'] ) : ?>
		<div class="m-cta-corr">
			<?php if ( $meeha_f['corr_title'] ) : ?><p class="m-cta-corr__title"><?php echo esc_html( $meeha_f['corr_title'] ); ?></p><?php endif; ?>
			<?php if ( $meeha_f['corr_text'] ) : ?><p><?php echo esc_html( $meeha_f['corr_text'] ); ?></p><?php endif; ?>
			<?php if ( $meeha_f['corr_url'] && $meeha_f['corr_label'] ) : ?><a class="m-btn" href="<?php echo esc_url( $meeha_f['corr_url'] ); ?>"><?php echo meeha_icon( 'android', array( 'size' => 'sm' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php echo esc_html( $meeha_f['corr_label'] ); ?></a><?php endif; ?>
		</div>
		<?php endif; ?>
		<?php if ( $meeha_social || $meeha_gnews ) : ?>
		<div>
			<h2 class="m-footer__h"><?php echo esc_html( $meeha_f['social_title'] ); ?></h2>
			<?php if ( $meeha_social ) : ?>
			<ul class="m-social" role="list">
				<?php foreach ( $meeha_social as $meeha_s ) : ?>
					<li><a href="<?php echo esc_url( $meeha_s[2] ); ?>" aria-label="<?php echo esc_attr( $meeha_s[1] ); ?>" rel="noopener me" target="_blank"><?php echo meeha_icon( $meeha_s[0] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a></li>
				<?php endforeach; ?>
			</ul>
			<?php endif; ?>
			<?php if ( $meeha_gnews ) : ?>
				<p class="m-footer__gnews"><a href="<?php echo esc_url( $meeha_gnews ); ?>" rel="noopener" target="_blank"><?php echo meeha_icon( 'globe', array( 'size' => 'sm' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>Google News-এ অনুসরণ করুন</a></p>
			<?php endif; ?>
		</div>
		<?php endif; ?>
	</div>
</div>
