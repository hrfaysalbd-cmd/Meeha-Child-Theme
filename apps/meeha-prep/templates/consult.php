<?php
/**
 * Career consultant panel. Vars: $list.
 *
 * @package meeha-prep
 */

defined( 'ABSPATH' ) || exit;

$mhp_modes = MHP_Consult::modes();
?>
<section class="mc-g mc-detail">
	<h1 class="mc-h1">ক্যারিয়ার পরামর্শ</h1>
	<p class="mc-sm mc-muted">সিভি রিভিউ, ইন্টারভিউ প্রস্তুতি, পড়াশোনা ও ক্যারিয়ারের পথ বাছাই: অভিজ্ঞ মানুষের সঙ্গে একান্ত সেশন। প্রশ্ন আগে লিখে দিন, কনসাল্টেন্ট প্রস্তুত হয়ে আসবেন।</p>
</section>

<section class="mc-sec mc-g">
	<?php if ( ! $list ) : ?>
		<div class="mc-card mc-pad mc-center">
			<p>আমাদের কনসাল্টেন্ট প্যানেল শিগগির যুক্ত হচ্ছে।</p>
			<p class="mc-xs mc-muted">ততদিন সিভি স্টুডিওতে নিজের সিভি গুছিয়ে রাখুন।</p>
			<a class="mc-btn mc-btn--soft" href="<?php echo esc_url( mhp_url( 'cv' ) ); ?>">সিভি স্টুডিও</a>
		</div>
	<?php else : ?>
		<div class="mc-cons-grid">
			<?php foreach ( $list as $mhp_c ) : ?>
				<a class="mc-card mc-pad mc-cons" href="<?php echo esc_url( mhp_url( 'consultant', $mhp_c['id'] ) ); ?>">
					<?php if ( $mhp_c['photo'] ) : ?>
						<img class="mc-cons__ph" src="<?php echo esc_url( $mhp_c['photo'] ); ?>" alt="" loading="lazy" width="64" height="64">
					<?php else : ?>
						<span class="mc-cons__ph" aria-hidden="true"><?php echo esc_html( mhp_initial( $mhp_c['name'] ) ); ?></span>
					<?php endif; ?>
					<span class="mc-grow">
						<b class="mc-block"><?php echo esc_html( $mhp_c['name'] ); ?></b>
						<span class="mc-xs mc-muted mc-block"><?php echo esc_html( $mhp_c['role'] ); ?></span>
						<span class="mc-cons__meta">
							<span><?php echo esc_html( $mhp_c['fee'] > 0 ? mhp_tk( $mhp_c['fee'] ) : 'ফ্রি' ); ?><?php echo $mhp_c['minutes'] ? esc_html( ' · ' . mhp_bn( $mhp_c['minutes'] ) . ' মিনিট' ) : ''; ?></span>
							<span><?php echo esc_html( $mhp_modes[ $mhp_c['mode'] ] ); ?></span>
							<?php if ( ! $mhp_c['open'] ) : ?>
								<span class="mc-bad">এখন বুকিং বন্ধ</span>
							<?php endif; ?>
						</span>
						<?php if ( $mhp_c['expertise'] ) : ?>
							<ul class="mc-cons__tags">
								<?php foreach ( array_slice( $mhp_c['expertise'], 0, 4 ) as $mhp_e ) : ?>
									<li><?php echo esc_html( $mhp_e ); ?></li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
					</span>
				</a>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</section>

<section class="mc-sec mc-g">
	<div class="mc-card mc-pad">
		<h2 class="mc-h3">কীভাবে কাজ করে</h2>
		<ol class="mc-cons-steps">
			<li>কনসাল্টেন্ট বেছে নিয়ে বিষয়, পছন্দের দিন ও প্রশ্ন লিখুন।</li>
			<li>ফি থাকলে বিকাশ, নগদ, রকেট বা কার্ডে পরিশোধ করুন।</li>
			<li>আমরা সময় ঠিক করে ইমেইলে ও "আমার → পরামর্শ" পাতায় ফোন/ভিডিও কলের তথ্য জানাব।</li>
		</ol>
		<p class="mc-xs mc-muted">কনসাল্টেন্ট হিসেবে যুক্ত হতে চাইলে <a href="<?php echo esc_url( mhp_url( 'employer' ) ); ?>">যোগাযোগ পাতা</a> থেকে জানান।</p>
	</div>
</section>
