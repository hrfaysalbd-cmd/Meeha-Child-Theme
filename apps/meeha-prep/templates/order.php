<?php
/**
 * Order receipt / status. Vars: $o (order row).
 *
 * @package meeha-prep
 */

defined( 'ABSPATH' ) || exit;

$mhp_st = MHP_Orders::statuses();
$mhp_s  = isset( $mhp_st[ $o['status'] ] ) ? $mhp_st[ $o['status'] ] : array( $o['status'], 'n' );
$mhp_go = '';
if ( 'paid' === $o['status'] ) {
	if ( 'test' === $o['item_type'] ) {
		$mhp_go = mhp_url( 'test', $o['item_id'] );
	} elseif ( 'event' === $o['item_type'] ) {
		$mhp_go = mhp_url( 'event', $o['item_id'] );
	} elseif ( 'pubp' === $o['item_type'] ) {
	$mhp_msg['paid'] = 'পরিশোধ হয়েছে। বইটি প্যাক করে কুরিয়ারে পাঠানো হবে; অবস্থা দেখা যাবে "আমার → কেনাকাটা" পাতায়, পাঠানো হলে ইমেইলও পাবেন।';
} elseif ( 'pubd' === $o['item_type'] ) {
	$mhp_msg['paid'] = 'পরিশোধ হয়েছে। ডিজিটাল সংস্করণটি এখন ডাউনলোড করতে পারবেন।';
} elseif ( 'cvtpl' === $o['item_type'] ) {
		$mhp_go = mhp_url( 'cv' );
	} elseif ( 'consult' === $o['item_type'] ) {
		$mhp_go = mhp_url( 'me', '', array( 'tab' => 'consult' ) );
	} elseif ( 'pubd' === $o['item_type'] ) {
		$mhp_go = mhp_url( 'pub', $o['item_id'] );
	} else {
		$mhp_go = mhp_url( 'me', '', array( 'tab' => 'purchases' ) );
	}
}
$mhp_msg = array(
	'paid'      => 'পরিশোধ যাচাই হয়েছে। আইটেমটি আপনার অ্যাকাউন্টে যোগ হয়েছে।',
	'pending'   => 'পরিশোধের নিশ্চিতকরণ এখনো আসেনি। টাকা কেটে থাকলে কিছুক্ষণের মধ্যে নিজে থেকেই যোগ হবে; পাতাটি পরে আবার দেখুন।',
	'hold'      => 'গেটওয়ে লেনদেনটি পর্যালোচনার জন্য রেখেছে। আমাদের দল যাচাই করে শিগগির জানাবে।',
	'failed'    => 'পরিশোধ সম্পন্ন হয়নি। টাকা কেটে থাকলে সাধারণত ব্যাংক/ওয়ালেট নিজে থেকেই ফেরত দেয়; না দিলে নিচের অর্ডার নম্বরসহ আমাদের জানান।',
	'cancelled' => 'আপনি পরিশোধ বাতিল করেছেন। চাইলে আবার চেষ্টা করতে পারেন।',
	'expired'   => 'অর্ডারের মেয়াদ শেষ। আবার চেষ্টা করুন।',
	'refunded'  => 'এই অর্ডারের টাকা ফেরত দেওয়া হয়েছে।',
);
if ( 'consult' === $o['item_type'] ) {
	$mhp_msg['paid'] = 'ফি পরিশোধ হয়েছে। আপনার অনুরোধ আমাদের ডেস্কে গেছে; সময় ঠিক হলে ইমেইলে ও "আমার → পরামর্শ" পাতায় জানানো হবে।';
} elseif ( 'cvtpl' === $o['item_type'] ) {
	$mhp_msg['paid'] = 'পরিশোধ হয়েছে। টেমপ্লেটটি এখন জলছাপ ছাড়া ব্যবহার, প্রিন্ট ও শেয়ার করতে পারবেন।';
}
$mhp_retry = in_array( $o['status'], array( 'pending', 'failed', 'cancelled', 'expired' ), true );
?>
<section class="mc-g mc-detail">
	<h1 class="mc-h1">অর্ডারের অবস্থা</h1>
	<div class="mc-card mc-pad mc-mt">
		<div class="mc-row mc-between">
			<b><?php echo esc_html( $o['item_name'] ); ?></b>
			<span class="mc-pill mc-pill--<?php echo esc_attr( 'ok' === $mhp_s[1] ? 'ok' : ( 'bad' === $mhp_s[1] ? 'bad' : 'line' ) ); ?>"><?php echo esc_html( $mhp_s[0] ); ?></span>
		</div>
		<p class="mc-sm"><?php echo esc_html( isset( $mhp_msg[ $o['status'] ] ) ? $mhp_msg[ $o['status'] ] : '' ); ?></p>
		<dl class="mc-kv">
			<dt>অর্ডার নম্বর</dt><dd class="mc-mono-text"><?php echo esc_html( $o['tran_id'] ); ?></dd>
			<dt>পরিমাণ</dt><dd><?php echo esc_html( mhp_tk( $o['amount'] ) ); ?></dd>
			<dt>তারিখ</dt><dd><?php echo esc_html( mhp_fdatetime( get_date_from_gmt( $o['created_at'], 'Y-m-d H:i' ) ) ); ?></dd>
			<?php if ( $o['card_type'] ) : ?>
				<dt>মাধ্যম</dt><dd><?php echo esc_html( $o['card_type'] ); ?></dd>
			<?php endif; ?>
			<?php if ( $o['bank_tran_id'] ) : ?>
				<dt>ব্যাংক লেনদেন</dt><dd><?php echo esc_html( $o['bank_tran_id'] ); ?></dd>
			<?php endif; ?>
			<?php if ( 'sandbox' === $o['mode'] ) : ?>
				<dt>মোড</dt><dd>পরীক্ষামূলক (sandbox)</dd>
			<?php endif; ?>
		</dl>
	</div>
	<div class="mc-row mc-mt">
		<?php if ( $mhp_go ) : ?>
			<a class="mc-btn mc-grow" href="<?php echo esc_url( $mhp_go ); ?>">খুলুন</a>
		<?php elseif ( $mhp_retry ) : ?>
			<a class="mc-btn mc-grow" href="<?php echo esc_url( mhp_url( 'checkout', $o['item_type'] . '-' . $o['item_id'] ) ); ?>">আবার চেষ্টা করুন</a>
		<?php elseif ( 'pending' === $o['status'] ) : ?>
			<a class="mc-btn mc-btn--soft mc-grow" href="<?php echo esc_url( mhp_url( 'order', $o['tran_id'] ) ); ?>">আবার দেখুন</a>
		<?php endif; ?>
		<a class="mc-btn mc-btn--ghost mc-grow" href="<?php echo esc_url( mhp_url( 'me', '', array( 'tab' => 'purchases' ) ) ); ?>">সব অর্ডার</a>
	</div>
</section>
