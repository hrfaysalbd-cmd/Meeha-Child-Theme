<?php
/**
 * Consultant profile and booking form. Vars: $c, $uid, $open, $pay_ok, $phone, $keep (what the reader typed before an error).
 *
 * @package meeha-prep
 */

defined( 'ABSPATH' ) || exit;

$mhp_modes = MHP_Consult::modes();
$mhp_min   = wp_date( 'Y-m-d', time() + DAY_IN_SECONDS, mhp_tz() );
$mhp_k     = function ( $k, $def = '' ) use ( $keep ) {
	return isset( $keep[ $k ] ) && '' !== $keep[ $k ] ? (string) $keep[ $k ] : $def;
};
$mhp_max   = wp_date( 'Y-m-d', time() + 60 * DAY_IN_SECONDS, mhp_tz() );
?>
<div class="mc-g mc-detail mc-cons-page">
	<div class="mc-cons-page__main">
		<div class="mc-cons">
			<?php if ( $c['photo'] ) : ?>
				<img class="mc-cons__ph mc-cons__ph--lg" src="<?php echo esc_url( $c['photo'] ); ?>" alt="" width="96" height="96">
			<?php else : ?>
				<span class="mc-cons__ph mc-cons__ph--lg" aria-hidden="true"><?php echo esc_html( mhp_initial( $c['name'] ) ); ?></span>
			<?php endif; ?>
			<div class="mc-grow">
				<h1 class="mc-h1"><?php echo esc_html( $c['name'] ); ?></h1>
				<p class="mc-sm mc-muted"><?php echo esc_html( $c['role'] ); ?></p>
				<?php if ( $c['expertise'] ) : ?>
					<ul class="mc-cons__tags">
						<?php foreach ( $c['expertise'] as $mhp_e ) : ?>
							<li><?php echo esc_html( $mhp_e ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		</div>
		<?php if ( 'publish' !== $c['status'] ) : ?>
			<p class="mc-flash mc-flash--bad mc-mt">এই প্রোফাইল এখনো প্রকাশিত নয়; শুধু স্টাফ দেখছেন।</p>
		<?php endif; ?>
		<dl class="mc-kv mc-mt">
			<dt>সেশন</dt><dd><?php echo esc_html( ( $c['minutes'] ? mhp_bn( $c['minutes'] ) . ' মিনিট, ' : '' ) . $mhp_modes[ $c['mode'] ] ); ?></dd>
			<dt>ফি</dt><dd><?php echo esc_html( $c['fee'] > 0 ? mhp_tk( $c['fee'] ) : 'ফ্রি' ); ?></dd>
			<?php if ( $c['avail'] ) : ?>
				<dt>সাধারণত সময় দেন</dt><dd><?php echo esc_html( $c['avail'] ); ?></dd>
			<?php endif; ?>
		</dl>
		<?php if ( $c['intro'] ) : ?>
			<p class="mc-mt"><b><?php echo esc_html( $c['intro'] ); ?></b></p>
		<?php endif; ?>
		<?php if ( $c['bio'] ) : ?>
			<div class="mc-prose mc-mt"><?php echo wp_kses_post( wpautop( $c['bio'] ) ); ?></div>
		<?php endif; ?>
		<h2 class="mc-h3">যেসব বিষয়ে সাহায্য করেন</h2>
		<ul class="mc-cons-steps">
			<?php foreach ( $c['topics'] as $mhp_t ) : ?>
				<li><?php echo esc_html( $mhp_t ); ?></li>
			<?php endforeach; ?>
		</ul>
	</div>

	<aside class="mc-cons-page__side" id="book">
		<div class="mc-card mc-pad mc-mt">
			<h2 class="mc-h3">সেশন বুক করুন</h2>
			<?php if ( ! $c['open'] ) : ?>
				<p class="mc-sm mc-muted">এই কনসাল্টেন্ট এখন নতুন বুকিং নিচ্ছেন না। পরে আবার দেখুন।</p>
			<?php elseif ( ! $uid ) : ?>
				<p class="mc-sm">বুক করতে লগইন করুন।</p>
				<a class="mc-btn mc-btn--block" href="<?php echo esc_url( mhp_login_url( mhp_url( 'consultant', $c['id'] ) ) ); ?>">লগইন</a>
			<?php elseif ( $open ) : ?>
				<p class="mc-sm">এই কনসাল্টেন্টের সঙ্গে আপনার একটি বুকিং চলছে।</p>
				<a class="mc-btn mc-btn--block" href="<?php echo esc_url( mhp_url( 'me', '', array( 'tab' => 'consult' ) ) ); ?>">আমার বুকিং দেখুন</a>
			<?php elseif ( $c['fee'] > 0 && ! $pay_ok ) : ?>
				<p class="mc-sm mc-muted">অনলাইন পেমেন্ট চালু হলে বুকিং খুলবে।</p>
			<?php else : ?>
				<form method="post" class="mc-col" action="<?php echo esc_url( mhp_url( 'consultant', $c['id'] ) ); ?>">
					<?php echo MHP_Front::form_fields( 'consult_book' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<input type="hidden" name="consultant_id" value="<?php echo (int) $c['id']; ?>">
					<label class="mc-lbl" for="mc-b-topic">বিষয়</label>
					<select class="mc-inp" id="mc-b-topic" name="topic">
						<?php foreach ( $c['topics'] as $mhp_t ) : ?>
							<option value="<?php echo esc_attr( $mhp_t ); ?>"<?php selected( $mhp_k( 'topic' ), $mhp_t ); ?>><?php echo esc_html( $mhp_t ); ?></option>
						<?php endforeach; ?>
					</select>
					<label class="mc-lbl" for="mc-b-q">আপনার প্রশ্ন বা পরিস্থিতি</label>
					<textarea class="mc-inp" id="mc-b-q" name="question" required minlength="20" maxlength="2000" placeholder="যেমন: অ্যাকাউন্টিংয়ে স্নাতক, দুই বছর ব্যাংকে চাকরির চেষ্টা করছি; সিভি ও ভাইভা নিয়ে পরামর্শ চাই।"><?php echo esc_textarea( $mhp_k( 'question' ) ); ?></textarea>
					<div class="mc-cved__two">
						<div>
							<label class="mc-lbl" for="mc-b-date">পছন্দের দিন</label>
							<input class="mc-inp" type="date" id="mc-b-date" name="pref_date" value="<?php echo esc_attr( $mhp_k( 'pref_date' ) ); ?>" required min="<?php echo esc_attr( $mhp_min ); ?>" max="<?php echo esc_attr( $mhp_max ); ?>">
						</div>
						<div>
							<label class="mc-lbl" for="mc-b-time">পছন্দের সময়</label>
							<select class="mc-inp" id="mc-b-time" name="pref_time">
								<?php foreach ( MHP_Consult::windows() as $mhp_w => $mhp_l ) : ?>
									<option value="<?php echo esc_attr( $mhp_w ); ?>"<?php selected( $mhp_k( 'pref_time' ), $mhp_w ); ?>><?php echo esc_html( $mhp_l ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
					</div>
					<label class="mc-lbl" for="mc-b-ph">মোবাইল নম্বর</label>
					<input class="mc-inp" type="tel" id="mc-b-ph" name="phone" required maxlength="14" inputmode="tel" value="<?php echo esc_attr( $mhp_k( 'phone', $phone ) ); ?>" pattern="[0-9০-৯+\s\-]{11,16}" placeholder="01XXXXXXXXX">
					<button type="submit" class="mc-btn mc-btn--block mc-mt"><?php echo esc_html( $c['fee'] > 0 ? mhp_tk( $c['fee'] ) . ' পরিশোধ করে অনুরোধ পাঠান' : 'অনুরোধ পাঠান' ); ?></button>
					<p class="mc-xs mc-muted">পছন্দের দিন-সময় অনুযায়ী আমরা সময় ঠিক করে জানাব। সময় নিশ্চিত হওয়ার আগে বাতিল করলে পরিশোধিত ফি ফেরত দেওয়া হয়।</p>
				</form>
			<?php endif; ?>
		</div>
	</aside>
</div>
