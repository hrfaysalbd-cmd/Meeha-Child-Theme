<?php
/**
 * One publication. Vars: $d (data), $body (sanitised HTML), $owned (digital bought), $pay_ok, $cod, $form (saved or
 * re-posted address fields), $phone.
 *
 * @package meeha-prep
 */

defined( 'ABSPATH' ) || exit;

$mhp_uid  = get_current_user_id();
$mhp_f    = wp_parse_args(
	$form,
	array(
		'qty'      => 1,
		'name'     => $mhp_uid ? wp_get_current_user()->display_name : '',
		'phone'    => $phone,
		'district' => '',
		'zone'     => 'dhaka',
		'address'  => '',
		'pay'      => $pay_ok ? 'online' : 'cod',
	)
);
$mhp_fd   = (float) mhp_opt( 'ship_dhaka', 60 );
$mhp_fo   = (float) mhp_opt( 'ship_outside', 120 );
$mhp_free = (float) mhp_opt( 'ship_free_over', 0 );
$mhp_out  = $d['stock_on'] && $d['stock'] < 1;
$mhp_can  = $d['print'] && ! $mhp_out && ( $pay_ok || $cod );
?>
<article class="mc-g mc-detail">
	<div class="mc-pubv">
		<div class="mc-pubv__cover">
			<span class="mc-cover mc-cover--lg">
				<?php if ( $d['thumb'] ) : ?>
					<?php echo wp_get_attachment_image( $d['thumb'], 'medium_large', false, array( 'alt' => $d['title'] . ' প্রচ্ছদ' ) ); ?>
				<?php else : ?>
					<span class="mc-cover__ph"><span class="mc-xs"><?php echo esc_html( $d['kind_label'] ); ?></span><b><?php echo esc_html( $d['title'] ); ?></b></span>
				<?php endif; ?>
			</span>
			<?php if ( $d['sample'] ) : ?>
				<a class="mc-btn mc-btn--ghost mc-btn--sm mc-btn--block mc-mt" href="<?php echo esc_url( $d['sample'] ); ?>" target="_blank" rel="noopener"><?php echo mhp_icon( 'doc' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> নমুনা পাতা দেখুন</a>
			<?php endif; ?>
		</div>
		<div class="mc-pubv__main">
			<span class="mc-pill mc-pill--brand"><?php echo esc_html( $d['kind_label'] ); ?></span>
			<h1 class="mc-h1"><?php echo esc_html( $d['title'] ); ?></h1>
			<?php if ( $d['excerpt'] ) : ?>
				<p class="mc-sm mc-muted"><?php echo esc_html( $d['excerpt'] ); ?></p>
			<?php endif; ?>
			<dl class="mc-kv">
				<?php if ( $d['issue'] ) : ?>
					<dt>সংখ্যা/সংস্করণ</dt><dd><?php echo esc_html( $d['issue'] ); ?></dd>
				<?php endif; ?>
				<?php if ( $d['author'] ) : ?>
					<dt>লেখক/সম্পাদক</dt><dd><?php echo esc_html( $d['author'] ); ?></dd>
				<?php endif; ?>
				<?php if ( $d['pages'] ) : ?>
					<dt>পৃষ্ঠা</dt><dd><?php echo esc_html( mhp_bn( $d['pages'] ) ); ?></dd>
				<?php endif; ?>
				<?php if ( $d['digital'] ) : ?>
					<dt>ডিজিটাল ফাইল</dt><dd><?php echo esc_html( $d['format'] ); ?></dd>
				<?php endif; ?>
			</dl>

			<div class="mc-editions mc-mt">
				<?php if ( $d['digital'] ) : ?>
					<div class="mc-card mc-pad mc-edition">
						<div class="mc-row mc-between"><b>ডিজিটাল সংস্করণ</b><span class="mc-price"><?php echo esc_html( $d['d_price'] > 0 ? mhp_tk( $d['d_price'] ) : 'ফ্রি' ); ?></span></div>
						<p class="mc-xs mc-muted">কেনার সঙ্গে সঙ্গে ডাউনলোড; "আমার → কেনাকাটা" থেকে পরেও নামাতে পারবেন। ফাইলটি ব্যক্তিগত ব্যবহারের জন্য।</p>
						<?php if ( $owned ) : ?>
							<a class="mc-btn mc-btn--block" href="<?php echo esc_url( mhp_url( 'pub-file', $d['id'] ) ); ?>"><?php echo mhp_icon( 'doc' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> ডাউনলোড করুন</a>
						<?php elseif ( $d['d_price'] <= 0 || $pay_ok ) : ?>
							<a class="mc-btn mc-btn--block" href="<?php echo esc_url( $mhp_uid ? mhp_url( 'checkout', 'pubd-' . $d['id'] ) : mhp_login_url( mhp_url( 'pub', $d['id'] ) ) ); ?>"><?php echo esc_html( $d['d_price'] > 0 ? 'কিনুন' : 'ফ্রি নিন' ); ?></a>
						<?php else : ?>
							<p class="mc-sm mc-muted">অনলাইন পেমেন্ট শিগগির চালু হবে।</p>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<?php if ( $d['print'] ) : ?>
					<div class="mc-card mc-pad mc-edition" id="mc-print">
						<div class="mc-row mc-between"><b>প্রিন্ট সংস্করণ</b><span class="mc-price"><?php echo esc_html( mhp_tk( $d['p_price'] ) ); ?></span></div>
						<p class="mc-xs mc-muted">
							<?php echo esc_html( 'কুরিয়ারে ডেলিভারি: ঢাকা মহানগরে ' . mhp_tk( $mhp_fd ) . ', ঢাকার বাইরে ' . mhp_tk( $mhp_fo ) . ( $mhp_free > 0 ? '; বইয়ের দাম ' . mhp_tk( $mhp_free ) . ' বা বেশি হলে ডেলিভারি ফ্রি' : '' ) . '।' ); ?>
							<?php if ( $d['stock_on'] && ! $mhp_out && $d['stock'] <= 10 ) : ?>
								<b><?php echo esc_html( 'স্টকে আছে ' . mhp_bn( $d['stock'] ) . ' কপি।' ); ?></b>
							<?php endif; ?>
							<?php echo esc_html( (string) mhp_opt( 'ship_note', '' ) ); ?>
						</p>
						<?php if ( $mhp_out ) : ?>
							<p class="mc-pill mc-pill--bad">স্টক শেষ</p>
						<?php elseif ( ! $mhp_can ) : ?>
							<p class="mc-sm mc-muted">অর্ডার নেওয়া শিগগির শুরু হবে।</p>
						<?php elseif ( ! $mhp_uid ) : ?>
							<a class="mc-btn mc-btn--block" href="<?php echo esc_url( mhp_login_url( mhp_url( 'pub', $d['id'] ) . '#mc-print' ) ); ?>">লগইন করে অর্ডার করুন</a>
						<?php else : ?>
							<details class="mc-dash__more"<?php echo $form ? ' open' : ''; ?>>
								<summary class="mc-btn mc-btn--block">অর্ডার করুন</summary>
								<form method="post" class="mc-col mc-mt" action="<?php echo esc_url( mhp_url( 'pub', $d['id'] ) ); ?>" data-mc-pubform data-price="<?php echo esc_attr( (string) $d['p_price'] ); ?>" data-fd="<?php echo esc_attr( (string) $mhp_fd ); ?>" data-fo="<?php echo esc_attr( (string) $mhp_fo ); ?>" data-free="<?php echo esc_attr( (string) $mhp_free ); ?>">
									<?php echo MHP_Front::form_fields( 'pub_order' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									<input type="hidden" name="pub_id" value="<?php echo (int) $d['id']; ?>">
									<label class="mc-lbl" for="mc-p-qty">কপি</label>
									<select class="mc-inp" id="mc-p-qty" name="qty">
										<?php for ( $mhp_i = 1; $mhp_i <= MHP_Pubs::MAX_QTY; $mhp_i++ ) : ?>
											<option value="<?php echo (int) $mhp_i; ?>" <?php selected( (int) $mhp_f['qty'], $mhp_i ); ?>><?php echo esc_html( mhp_bn( $mhp_i ) ); ?></option>
										<?php endfor; ?>
									</select>
									<label class="mc-lbl" for="mc-p-name">প্রাপকের নাম</label>
									<input class="mc-inp" id="mc-p-name" name="ship_name" required maxlength="100" value="<?php echo esc_attr( $mhp_f['name'] ); ?>" autocomplete="name">
									<label class="mc-lbl" for="mc-p-phone">মোবাইল নম্বর</label>
									<input class="mc-inp" id="mc-p-phone" name="ship_phone" type="tel" inputmode="tel" required maxlength="14" value="<?php echo esc_attr( $mhp_f['phone'] ); ?>" placeholder="01XXXXXXXXX" autocomplete="tel">
									<label class="mc-lbl" for="mc-p-dist">জেলা</label>
									<select class="mc-inp" id="mc-p-dist" name="district" required>
										<option value="">বেছে নিন</option>
										<?php foreach ( mhp_districts() as $mhp_dist ) : ?>
											<option value="<?php echo esc_attr( $mhp_dist ); ?>" <?php selected( $mhp_f['district'], $mhp_dist ); ?>><?php echo esc_html( $mhp_dist ); ?></option>
										<?php endforeach; ?>
									</select>
									<fieldset class="mc-col mc-gap6">
										<legend class="mc-lbl">ডেলিভারি এলাকা</legend>
										<label class="mc-check"><input type="radio" name="zone" value="dhaka" <?php checked( $mhp_f['zone'], 'dhaka' ); ?>> ঢাকা মহানগরের ভেতরে (<?php echo esc_html( mhp_tk( $mhp_fd ) ); ?>)</label>
										<label class="mc-check"><input type="radio" name="zone" value="outside" <?php checked( $mhp_f['zone'], 'outside' ); ?>> ঢাকার বাইরে (<?php echo esc_html( mhp_tk( $mhp_fo ) ); ?>)</label>
										<span class="mc-xs mc-muted">জেলা ঢাকা না হলে "ঢাকার বাইরে" ধরা হবে।</span>
									</fieldset>
									<label class="mc-lbl" for="mc-p-addr">পুরো ঠিকানা</label>
									<textarea class="mc-inp" id="mc-p-addr" name="address" rows="3" required minlength="10" maxlength="300" placeholder="বাড়ি/রাস্তা, এলাকা, থানা/উপজেলা" autocomplete="street-address"><?php echo esc_textarea( $mhp_f['address'] ); ?></textarea>
									<?php if ( $pay_ok && $cod ) : ?>
										<fieldset class="mc-col mc-gap6">
											<legend class="mc-lbl">পরিশোধ</legend>
											<label class="mc-check"><input type="radio" name="pay" value="online" <?php checked( $mhp_f['pay'], 'online' ); ?>> এখনই অনলাইনে (বিকাশ, নগদ, কার্ড)</label>
											<label class="mc-check"><input type="radio" name="pay" value="cod" <?php checked( $mhp_f['pay'], 'cod' ); ?>> ক্যাশ অন ডেলিভারি (বই হাতে পেয়ে)</label>
										</fieldset>
									<?php else : ?>
										<input type="hidden" name="pay" value="<?php echo $pay_ok ? 'online' : 'cod'; ?>">
										<p class="mc-xs mc-muted"><?php echo $pay_ok ? 'পরের ধাপে SSLCommerz-এ অনলাইনে পরিশোধ করবেন।' : 'ক্যাশ অন ডেলিভারি: বই হাতে পেয়ে টাকা দেবেন।'; ?></p>
									<?php endif; ?>
									<p class="mc-total" data-mc-total hidden></p>
									<button type="submit" class="mc-btn mc-btn--block">অর্ডার করুন</button>
									<p class="mc-xs mc-muted">মোট = বইয়ের দাম × কপি + ডেলিভারি। অনলাইনে দিলে পরের ধাপে মোট দেখে পরিশোধ করবেন; ক্যাশ অন ডেলিভারিতে অর্ডার সঙ্গে সঙ্গে জমা হবে এবং ডেস্ক ফোনে নিশ্চিত করবে।</p>
								</form>
							</details>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</div>

			<?php if ( trim( wp_strip_all_tags( $body ) ) ) : ?>
				<div class="mc-sech mc-mt"><h2>ভেতরে যা আছে</h2></div>
				<div class="mc-prose"><?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses_post in the view. ?></div>
			<?php endif; ?>
		</div>
	</div>
</article>
