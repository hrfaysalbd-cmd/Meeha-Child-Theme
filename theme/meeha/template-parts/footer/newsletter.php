<?php
/**
 * Newsletter band — the ONLY newsletter form on the site. MailPoet form (id from ফুটার settings) rendered inside
 * our markup and styled by footer.css (MailPoet CSS + fonts are dequeued); without a MailPoet form the theme's
 * fallback form is printed by the legacy module (action `meeha_newsletter_fallback_form`, REST meeha/v1/newsletter).
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

$meeha_f = meeha_opt( 'footer' );
if ( empty( $meeha_f['newsletter_enabled'] ) ) {
	return;
}
$meeha_form_id = (int) $meeha_f['newsletter_form_id'];
$meeha_form    = '';
if ( $meeha_form_id && shortcode_exists( 'mailpoet_form' ) ) {
	$meeha_form = do_shortcode( '[mailpoet_form id="' . $meeha_form_id . '"]' );
}
if ( '' === trim( $meeha_form ) ) {
	ob_start();
	do_action( 'meeha_newsletter_fallback_form', $meeha_f );
	$meeha_form = (string) ob_get_clean();
}
if ( '' === trim( $meeha_form ) ) {
	return;
}
$meeha_privacy = get_privacy_policy_url();
?>
<section class="m-news" aria-labelledby="m-news-title">
	<div class="m-container m-news__inner">
		<div>
			<h2 class="m-news__title" id="m-news-title"><?php echo esc_html( $meeha_f['newsletter_title'] ); ?></h2>
			<?php if ( $meeha_f['newsletter_text'] ) : ?><p class="m-news__text"><?php echo esc_html( $meeha_f['newsletter_text'] ); ?></p><?php endif; ?>
		</div>
		<div class="m-news__form m-news__slot">
			<?php echo $meeha_form; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- MailPoet / module form markup. ?>
			<p class="m-news__fine">সাবস্ক্রাইব করলে আপনি আমাদের <?php echo $meeha_privacy ? '<a href="' . esc_url( $meeha_privacy ) . '">গোপনীয়তা নীতি</a>' : 'গোপনীয়তা নীতি'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> মেনে নিচ্ছেন।</p>
		</div>
	</div>
</section>
