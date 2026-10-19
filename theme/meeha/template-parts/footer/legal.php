<?php
/**
 * Legal bar: `bottom-menu` (fallback: ফুটার › আইনি লিংক), copyright with {year} in Bengali digits, back to top.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

$meeha_tree  = meeha_menu_tree( 'bottom-menu' );
$meeha_links = array();
if ( null !== $meeha_tree ) {
	foreach ( $meeha_tree as $meeha_n ) {
		$meeha_links[] = array( $meeha_n['title'], $meeha_n['url'] );
	}
} else {
	foreach ( (array) meeha_opt( 'footer', 'legal_links', array() ) as $meeha_r ) {
		if ( ! empty( $meeha_r['label'] ) && ! empty( $meeha_r['url'] ) ) {
			$meeha_links[] = array( $meeha_r['label'], $meeha_r['url'] );
		}
	}
}
$meeha_year = wp_date( 'Y', null, new DateTimeZone( 'Asia/Dhaka' ) );
$meeha_copy = str_replace( '{year}', meeha_bn_digits( $meeha_year ), (string) meeha_opt( 'footer', 'copyright', '' ) );
?>
<div class="m-footer__legal">
	<div class="m-container m-footer__legal-inner">
		<?php if ( $meeha_links ) : ?>
			<ul role="list" aria-label="আইনি তথ্য">
				<?php foreach ( $meeha_links as $meeha_l ) : ?>
					<li><a href="<?php echo esc_url( $meeha_l[1] ); ?>"><?php echo esc_html( $meeha_l[0] ); ?></a></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<p><?php echo wp_kses( $meeha_copy, array( 'a' => array( 'href' => true ) ) ); ?> <a class="m-totop" href="#top"><?php echo meeha_icon( 'arrow-up', array( 'size' => 'sm' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>উপরে যান</a></p>
	</div>
</div>
