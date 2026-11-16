<?php
/**
 * Mobile date strip (hidden ≥1024 px).
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

$meeha_d   = meeha_dates();
$meeha_alt = '';
if ( meeha_opt( 'header', 'date_bangla', 1 ) ) {
	$meeha_alt = $meeha_d['bangla'];
} elseif ( meeha_opt( 'header', 'date_hijri', 1 ) ) {
	$meeha_alt = $meeha_d['hijri'];
}
?>
<div class="m-datebar"><span><?php echo esc_html( $meeha_d['weekday'] . ', ' . $meeha_d['gregorian'] ); ?></span><?php if ( $meeha_alt ) : ?><span class="m-datebar__alt"><?php echo esc_html( $meeha_alt ); ?></span><?php endif; ?></div>
