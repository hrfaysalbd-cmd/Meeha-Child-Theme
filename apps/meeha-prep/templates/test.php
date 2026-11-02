<?php
/**
 * Test intro. Vars: $t (info), $own (bool), $last (array|null), $packs (WP_Post[]), $pay_ok (bool).
 *
 * @package meeha-prep
 */

defined( 'ABSPATH' ) || exit;

$mhp_uid     = get_current_user_id();
$mhp_free    = $t['price'] <= 0;
$mhp_need_in = ! $mhp_uid; // Attempts are stored per user, so every test needs an account.
$mhp_rules   = array(
	mhp_bn( $t['count'] ) . 'টি প্রশ্ন, সময় ' . mhp_bn( $t['minutes'] ) . ' মিনিট।',
	$t['negative'] > 0 ? 'প্রতিটি ভুল উত্তরে ' . mhp_score( $t['negative'] ) . ' নম্বর কাটা যাবে। উত্তর না দিলে নম্বর কাটে না।' : 'ভুল উত্তরে নম্বর কাটা নেই।',
	'সময় শেষ হলে উত্তরপত্র নিজে থেকেই জমা হবে। মাঝপথে পাতা বন্ধ হলে আবার খুললে বাকি সময় থেকে চালিয়ে যেতে পারবেন।',
	'প্রথমবারের ফল র‍্যাংকিংয়ে গোনা হয়; পরে যতবার খুশি অনুশীলন করতে পারবেন।',
);
if ( $t['live'] ) {
	$mhp_rules[] = 'লাইভ টেস্ট: নির্ধারিত সময়ের মধ্যে দিলে তবেই র‍্যাংকিংয়ে আসবে।';
}
?>
<div class="mc-g mc-detail">
	<?php if ( 'publish' !== $t['status'] ) : ?>
		<div class="mc-note">টেস্টটি এখনো প্রকাশিত নয়। শুধু সম্পাদকেরা দেখছেন।</div>
	<?php endif; ?>
	<?php if ( $t['track'] ) : ?>
		<a class="mc-sm mc-link" href="<?php echo esc_url( mhp_url( 'track', $t['track']['slug'] ) ); ?>"><?php echo esc_html( $t['track']['name'] ); ?></a>
	<?php endif; ?>
	<h1 class="mc-h1"><?php echo esc_html( $t['title'] ); ?>
		<?php if ( $t['live'] ) : ?>
			<span class="mc-pill mc-pill--bad">লাইভ</span>
		<?php endif; ?>
	</h1>
	<?php if ( $t['desc'] ) : ?>
		<p class="mc-sm mc-muted"><?php echo esc_html( $t['desc'] ); ?></p>
	<?php endif; ?>

	<div class="mc-stat3 mc-mt">
		<div class="mc-card mc-pad mc-center"><b class="mc-num"><?php echo esc_html( mhp_bn( $t['count'] ) ); ?></b><span class="mc-xs mc-muted mc-block">প্রশ্ন</span></div>
		<div class="mc-card mc-pad mc-center"><b class="mc-num"><?php echo esc_html( mhp_bn( $t['minutes'] ) ); ?></b><span class="mc-xs mc-muted mc-block">মিনিট</span></div>
		<div class="mc-card mc-pad mc-center"><b class="mc-num"><?php echo esc_html( $t['negative'] > 0 ? '−' . mhp_score( $t['negative'] ) : 'নেই' ); ?></b><span class="mc-xs mc-muted mc-block">নেগেটিভ</span></div>
	</div>

	<?php if ( $t['live'] && $t['start'] ) : ?>
		<div class="mc-card mc-pad mc-mt mc-tint mc-sm">
			<?php if ( 'upcoming' === $t['state'] ) : ?>
				শুরু হবে <b><?php echo esc_html( mhp_fdatetime( MHP_Tests::norm_dt( $t['start'] ) ) ); ?></b>
			<?php elseif ( 'open' === $t['state'] ) : ?>
				এখন চলছে<?php echo $t['end'] ? esc_html( '; শেষ ' . mhp_fdatetime( MHP_Tests::norm_dt( $t['end'] ) ) ) : ''; ?>
			<?php else : ?>
				লাইভ সময় শেষ। এখন দিলে অনুশীলন হিসেবে গোনা হবে, র‍্যাংকিংয়ে আসবে না।
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<h2 class="mc-h3">নিয়ম</h2>
	<ul class="mc-steps">
		<?php foreach ( $mhp_rules as $mhp_r ) : ?>
			<li><?php echo esc_html( $mhp_r ); ?></li>
		<?php endforeach; ?>
		<?php if ( $t['full_note'] ) : ?>
			<li><?php echo esc_html( $t['full_note'] ); ?></li>
		<?php endif; ?>
	</ul>

	<?php if ( $last ) : ?>
		<a class="mc-card mc-pad mc-block mc-mt" href="<?php echo esc_url( mhp_url( 'result', $last['id'] ) ); ?>">
			<span class="mc-row mc-between"><b>আপনার শেষ ফল: <?php echo esc_html( mhp_score( $last['score'] ) . '/' . mhp_bn( $last['total'] ) ); ?></b><span class="mc-link">উত্তরপত্র</span></span>
		</a>
	<?php endif; ?>

	<?php if ( ! $own && $packs ) : ?>
		<h2 class="mc-h3">এই টেস্ট যে প্যাকে আছে</h2>
		<div class="mc-col">
			<?php
			foreach ( $packs as $mhp_p ) {
				echo mhp_product_card( $mhp_p, $mhp_uid ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			?>
		</div>
	<?php endif; ?>
</div>

<div class="mc-sticky mc-g">
	<?php if ( 0 === $t['count'] ) : ?>
		<span class="mc-btn mc-grow is-disabled" aria-disabled="true">প্রশ্ন যোগ হচ্ছে, শিগগির আসছে</span>
	<?php elseif ( $mhp_need_in ) : ?>
		<a class="mc-btn mc-grow" href="<?php echo esc_url( mhp_login_url( mhp_url( 'test', $t['id'] ) ) ); ?>">টেস্ট দিতে লগইন করুন</a>
	<?php elseif ( ! $own ) : ?>
		<div class="mc-grow"><div class="mc-price"><?php echo esc_html( mhp_tk( $t['price'] ) ); ?></div><div class="mc-xs mc-muted">একবার কিনলে অ্যাকাউন্টে থাকবে</div></div>
		<?php if ( $pay_ok ) : ?>
			<a class="mc-btn" href="<?php echo esc_url( mhp_url( 'checkout', 'test-' . $t['id'] ) ); ?>">কিনুন</a>
		<?php else : ?>
			<span class="mc-btn is-disabled" aria-disabled="true">শিগগির বিক্রি শুরু</span>
		<?php endif; ?>
	<?php elseif ( 'upcoming' === $t['state'] && ! mhp_can_manage() ) : ?>
		<span class="mc-btn mc-grow is-disabled" aria-disabled="true">এখনো শুরু হয়নি</span>
	<?php else : ?>
		<form method="post" class="mc-grow" action="<?php echo esc_url( mhp_url( 'test', $t['id'] ) ); ?>">
			<?php echo MHP_Front::form_fields( 'start_test' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<input type="hidden" name="test_id" value="<?php echo (int) $t['id']; ?>">
			<button type="submit" class="mc-btn mc-btn--block"><?php echo esc_html( $last ? 'আবার দিন' : 'টেস্ট শুরু করুন' ); ?></button>
		</form>
	<?php endif; ?>
</div>
