<?php
/**
 * Checkout confirmation. Vars: $type, $item, $it (item description), $owned, $pay_ok, $phone.
 *
 * @package meeha-prep
 */

defined( 'ABSPATH' ) || exit;

$mhp_backs = array(
	'event'   => mhp_url( 'event', $item ),
	'test'    => mhp_url( 'test', $item ),
	'cvtpl'   => mhp_url( 'cv' ),
	'consult' => mhp_url( 'me', '', array( 'tab' => 'consult' ) ),
	'pubd'    => mhp_url( 'pub', $item ),
	'pubp'    => mhp_url( 'me', '', array( 'tab' => 'purchases' ) ),
);
$mhp_kinds = array(
	'event'   => 'ইভেন্ট টিকিট',
	'test'    => 'টেস্ট',
	'cvtpl'   => 'সিভি টেমপ্লেট',
	'consult' => 'ক্যারিয়ার পরামর্শের ফি',
	'pubd'    => 'ডিজিটাল সংস্করণ',
	'pubp'    => 'প্রিন্ট সংস্করণ (ডেলিভারিসহ)',
);
$mhp_back  = isset( $mhp_backs[ $type ] ) ? $mhp_backs[ $type ] : mhp_url( 'prep' );
?>
<section class="mc-g mc-detail">
	<h1 class="mc-h1">পেমেন্ট</h1>
	<?php if ( ! $it['ok'] ) : ?>
		<div class="mc-card mc-pad mc-center"><p><?php echo esc_html( $it['reason'] ); ?></p><a class="mc-btn mc-btn--soft" href="<?php echo esc_url( $mhp_back ); ?>">ফিরে যান</a></div>
	<?php elseif ( $owned ) : ?>
		<div class="mc-card mc-pad mc-center"><p>এটি আপনার অ্যাকাউন্টে আগেই আছে।</p><a class="mc-btn" href="<?php echo esc_url( $mhp_back ); ?>">খুলুন</a></div>
	<?php else : ?>
		<div class="mc-card mc-pad mc-mt">
			<div class="mc-row mc-between">
				<span class="mc-grow"><span class="mc-xs mc-muted mc-block"><?php echo esc_html( isset( $mhp_kinds[ $type ] ) ? $mhp_kinds[ $type ] : 'প্যাক/বই' ); ?></span><b><?php echo esc_html( $it['name'] ); ?></b></span>
				<span class="mc-price"><?php echo esc_html( mhp_tk( $it['price'] ) ); ?></span>
			</div>
			<?php if ( ! empty( $it['note'] ) ) : ?>
				<p class="mc-xs mc-muted"><?php echo esc_html( $it['note'] ); ?></p>
			<?php elseif ( $it['post'] && $it['post']->post_excerpt ) : ?>
				<p class="mc-xs mc-muted"><?php echo esc_html( $it['post']->post_excerpt ); ?></p>
			<?php endif; ?>
		</div>

		<?php if ( $it['price'] <= 0 ) : ?>
			<form method="post" class="mc-mt" action="<?php echo esc_url( mhp_url( 'checkout', $type . '-' . $item ) ); ?>">
				<?php echo MHP_Front::form_fields( 'checkout' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<input type="hidden" name="item_type" value="<?php echo esc_attr( $type ); ?>">
				<input type="hidden" name="item_id" value="<?php echo (int) $item; ?>">
				<button type="submit" class="mc-btn mc-btn--block">ফ্রি নিন</button>
			</form>
		<?php elseif ( ! $pay_ok ) : ?>
			<div class="mc-card mc-pad mc-mt mc-center mc-muted">অনলাইন পেমেন্ট এখনো চালু হয়নি। শিগগির চালু হবে।</div>
		<?php else : ?>
			<form method="post" class="mc-card mc-pad mc-col mc-mt" action="<?php echo esc_url( mhp_url( 'checkout', $type . '-' . $item ) ); ?>">
				<?php echo MHP_Front::form_fields( 'checkout' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<input type="hidden" name="item_type" value="<?php echo esc_attr( $type ); ?>">
				<input type="hidden" name="item_id" value="<?php echo (int) $item; ?>">
				<label class="mc-lbl" for="mc-c-ph">মোবাইল নম্বর</label>
				<input class="mc-inp" id="mc-c-ph" name="phone" type="tel" inputmode="tel" required maxlength="14" value="<?php echo esc_attr( $phone ); ?>" placeholder="01XXXXXXXXX">
				<span class="mc-xs mc-muted">পেমেন্ট গেটওয়ে রসিদ পাঠাতে নম্বরটি চায়।</span>
				<button type="submit" class="mc-btn mc-btn--block"><?php echo esc_html( mhp_tk( $it['price'] ) ); ?> পরিশোধ করুন</button>
				<p class="mc-xs mc-muted">পরের ধাপে SSLCommerz-এর নিরাপদ পাতায় বিকাশ, নগদ, রকেট বা কার্ডে পরিশোধ করবেন। পরিশোধ যাচাই হলে সঙ্গে সঙ্গে অ্যাকাউন্টে যোগ হবে।<?php echo 'sandbox' === MHP_Gateway_SSLCommerz::creds()['mode'] ? ' <b>(পরীক্ষামূলক মোড: আসল টাকা কাটবে না)</b>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
			</form>
		<?php endif; ?>
	<?php endif; ?>
</section>
