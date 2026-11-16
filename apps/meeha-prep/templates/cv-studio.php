<?php
/**
 * CV studio home: the reader's CVs and the design gallery. Vars: $tpls, $owned, $cvs, $uid, $pay_ok.
 *
 * @package meeha-prep
 */

defined( 'ABSPATH' ) || exit;

$mhp_names = array();
foreach ( $tpls as $mhp_t ) {
	$mhp_names[ $mhp_t['id'] ] = $mhp_t['name'];
}
$mhp_groups = array(
	'free' => array( 'ফ্রি টেমপ্লেট', 'এক পাতা। সিভির লিংক শুধু আপনি দেখতে পারবেন; প্রিন্ট ও পিডিএফ করা যাবে।' ),
	'paid' => array( 'পেইড টেমপ্লেট', 'দুই-তিন পাতা, জলছাপ ছাড়া পিডিএফ আর পাবলিক শেয়ার লিংক। একবার কিনলে যতবার খুশি ব্যবহার করুন।' ),
);
?>
<section class="mc-g mc-detail mc-cv-intro">
	<h1 class="mc-h1">সিভি স্টুডিও</h1>
	<p class="mc-sm mc-muted">তথ্য একবার লিখুন, পছন্দের ডিজাইন বেছে নিন, তারপর প্রিন্ট করুন বা পিডিএফ নিন। পরে যেকোনো সময় ডিজাইন বদলাতে পারবেন, তথ্য আবার লিখতে হবে না।</p>
</section>

<?php if ( $cvs ) : ?>
	<section class="mc-sec mc-g">
		<?php meeha_campus_sech( 'আমার সিভি' ); ?>
		<div class="mc-col">
			<?php foreach ( $cvs as $mhp_cv ) : ?>
				<div class="mc-card mc-pad mc-row mc-cv-mine">
					<span class="mc-mono" aria-hidden="true"><?php echo mhp_icon( 'doc' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<span class="mc-grow">
						<b class="mc-block"><?php echo esc_html( $mhp_cv['title'] ); ?></b>
						<span class="mc-xs mc-muted"><?php echo esc_html( ( isset( $mhp_names[ $mhp_cv['tpl_id'] ] ) ? $mhp_names[ $mhp_cv['tpl_id'] ] : 'টেমপ্লেট নেই' ) . ' · হালনাগাদ ' . mhp_fdatetime( get_date_from_gmt( $mhp_cv['updated_at'], 'Y-m-d H:i' ) ) . ( $mhp_cv['is_public'] ? ' · পাবলিক লিংক চালু' : '' ) ); ?></span>
					</span>
					<a class="mc-btn mc-btn--sm" href="<?php echo esc_url( mhp_url( 'cv-edit', $mhp_cv['id'] ) ); ?>">সম্পাদনা</a>
					<a class="mc-btn mc-btn--sm mc-btn--ghost" href="<?php echo esc_url( mhp_url( 'cv-view', $mhp_cv['token'] ) ); ?>" target="_blank" rel="noopener">পিডিএফ</a>
				</div>
			<?php endforeach; ?>
		</div>
	</section>
<?php endif; ?>

<?php if ( ! $tpls ) : ?>
	<section class="mc-sec mc-g"><div class="mc-card mc-pad mc-center mc-muted">টেমপ্লেট শিগগির আসছে।</div></section>
<?php endif; ?>

<?php
foreach ( $mhp_groups as $mhp_g => $mhp_head ) :
	$mhp_list = array_filter(
		$tpls,
		function ( $t ) use ( $mhp_g ) {
			return 'free' === $mhp_g ? $t['price'] <= 0 : $t['price'] > 0;
		}
	);
	if ( ! $mhp_list ) {
		continue;
	}
	?>
	<section class="mc-sec mc-g">
		<?php meeha_campus_sech( $mhp_head[0] ); ?>
		<p class="mc-xs mc-muted mc-cv-gnote"><?php echo esc_html( $mhp_head[1] ); ?></p>
		<div class="mc-cvt-grid">
			<?php foreach ( $mhp_list as $mhp_t ) : ?>
				<article class="mc-card mc-cvt">
					<div class="mc-cvt__thumb" data-cvthumb>
						<iframe srcdoc="<?php echo htmlspecialchars( MHP_CV::thumb_doc( $mhp_t ), ENT_QUOTES, 'UTF-8', true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- double-encoded on purpose (esc_attr keeps entities). ?>" sandbox="allow-same-origin" loading="lazy" tabindex="-1" aria-hidden="true" scrolling="no" title=""></iframe>
					</div>
					<div class="mc-pad mc-cvt__body">
						<div class="mc-row mc-between">
							<b><?php echo esc_html( $mhp_t['name'] ); ?></b>
							<?php if ( $mhp_t['price'] <= 0 ) : ?>
								<span class="mc-pill mc-pill--ok">ফ্রি</span>
							<?php elseif ( ! empty( $owned[ $mhp_t['id'] ] ) ) : ?>
								<span class="mc-pill mc-pill--ok">আপনার কেনা</span>
							<?php else : ?>
								<span class="mc-price"><?php echo esc_html( mhp_tk( $mhp_t['price'] ) ); ?></span>
							<?php endif; ?>
						</div>
						<p class="mc-xs mc-muted"><?php echo esc_html( $mhp_t['desc'] ); ?></p>
						<p class="mc-xs"><?php echo esc_html( mhp_bn( $mhp_t['pages'] ) . ' পাতা পর্যন্ত · ' . ( $mhp_t['price'] > 0 ? 'পাবলিক শেয়ার' : 'শুধু নিজে দেখবেন' ) ); ?></p>
						<div class="mc-row mc-wrap mc-cvt__act">
							<?php if ( $uid ) : ?>
								<form method="post" action="<?php echo esc_url( mhp_url( 'cv' ) ); ?>">
									<?php echo MHP_Front::form_fields( 'cv_create' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									<input type="hidden" name="tpl_id" value="<?php echo (int) $mhp_t['id']; ?>">
									<button type="submit" class="mc-btn mc-btn--sm"><?php echo esc_html( $mhp_t['price'] > 0 && empty( $owned[ $mhp_t['id'] ] ) ? 'চেষ্টা করে দেখুন' : 'এটি দিয়ে বানান' ); ?></button>
								</form>
							<?php else : ?>
								<a class="mc-btn mc-btn--sm" href="<?php echo esc_url( mhp_login_url( mhp_url( 'cv' ) ) ); ?>">লগইন করে বানান</a>
							<?php endif; ?>
							<?php if ( $mhp_t['price'] > 0 && empty( $owned[ $mhp_t['id'] ] ) && $pay_ok ) : ?>
								<a class="mc-btn mc-btn--sm mc-btn--ghost" href="<?php echo esc_url( mhp_url( 'checkout', 'cvtpl-' . $mhp_t['id'] ) ); ?>">কিনুন</a>
							<?php endif; ?>
						</div>
					</div>
				</article>
			<?php endforeach; ?>
		</div>
	</section>
<?php endforeach; ?>

<?php if ( class_exists( 'MHP_Consult' ) && MHP_Consult::any() ) : ?>
	<section class="mc-sec mc-g">
		<a class="mc-card mc-cta" href="<?php echo esc_url( mhp_url( 'consult' ) ); ?>"><span class="mc-mono"><?php echo mhp_icon( 'chat' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span><span class="mc-grow"><b class="mc-block">সিভি নিয়ে পরামর্শ চান?</b><span class="mc-xs mc-muted">ক্যারিয়ার কনসাল্টেন্টের সঙ্গে সিভি রিভিউ ও ইন্টারভিউ প্রস্তুতির সেশন</span></span><?php echo mhp_icon( 'chev' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
	</section>
<?php endif; ?>
