<?php
/**
 * Employer / advertiser page. Vars: $sent (bool).
 * Packages and contact lines come from the theme's "শিক্ষা ও ক্যাম্পাস" settings (meeha_campus_packages()).
 *
 * @package meeha-prep
 */

defined( 'ABSPATH' ) || exit;

$mhp_pk  = function_exists( 'meeha_campus_packages' ) ? meeha_campus_packages() : array( 'jobs' => array(), 'ads' => array(), 'contact' => '' );
$mhp_up  = (bool) mhp_opt( 'employer_upload', 1 );
$mhp_min = gmdate( 'Y-m-d', strtotime( mhp_today() ) );
?>
<section class="mc-mast mc-g">
	<div class="mc-mast__eyebrow">নিয়োগদাতা ও বিজ্ঞাপনদাতা</div>
	<h1 class="mc-mast__title">সঠিক প্রার্থীর কাছে পৌঁছান</h1>
	<p class="mc-mast__lede">মিহা নিউজের শিক্ষা ও ক্যাম্পাস পাঠকেরা চাকরি ও পরীক্ষার প্রস্তুতিতে সক্রিয়। বিজ্ঞপ্তি যাচাইয়ের পর প্রকাশ হয়।</p>
</section>

<?php if ( $sent ) : ?>
	<section class="mc-sec mc-g"><div class="mc-card mc-pad mc-center mc-tint"><b>ধন্যবাদ!</b><p class="mc-sm">আপনার বিজ্ঞপ্তি জমা হয়েছে। যাচাইয়ের পর প্রকাশ হবে এবং আমাদের বিজ্ঞাপন বিভাগ যোগাযোগ করবে।</p></div></section>
<?php endif; ?>

<?php if ( ! empty( $mhp_pk['jobs'] ) ) : ?>
	<section class="mc-sec mc-g">
		<div class="mc-sech"><h2>বিজ্ঞপ্তির প্যাকেজ</h2></div>
		<div class="mc-col mc-grid2">
			<?php foreach ( $mhp_pk['jobs'] as $mhp_p ) : ?>
				<div class="mc-card mc-pad mc-pkg">
					<b class="mc-block"><?php echo esc_html( $mhp_p['name'] ); ?></b>
					<div class="mc-price"><?php echo esc_html( '' !== (string) $mhp_p['price'] ? $mhp_p['price'] : 'যোগাযোগ করুন' ); ?></div>
					<?php if ( ! empty( $mhp_p['desc'] ) ) : ?>
						<p class="mc-xs mc-muted"><?php echo esc_html( $mhp_p['desc'] ); ?></p>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
	</section>
<?php endif; ?>

<section class="mc-sec mc-g" id="mc-submit">
	<div class="mc-sech"><h2>বিজ্ঞপ্তি জমা দিন</h2></div>
	<form method="post" class="mc-card mc-pad mc-col" action="<?php echo esc_url( mhp_url( 'employer' ) ); ?>"<?php echo $mhp_up ? ' enctype="multipart/form-data"' : ''; ?>>
		<?php echo MHP_Front::form_fields( 'employer_submit' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<label class="mc-lbl" for="mc-e-co">প্রতিষ্ঠানের নাম *</label>
		<input class="mc-inp" id="mc-e-co" name="company" required maxlength="150">
		<label class="mc-lbl" for="mc-e-post">পদের নাম *</label>
		<input class="mc-inp" id="mc-e-post" name="post_title" required maxlength="150">
		<label class="mc-lbl" for="mc-e-dl">আবেদনের শেষ তারিখ *</label>
		<input class="mc-inp" id="mc-e-dl" name="deadline" type="date" required min="<?php echo esc_attr( $mhp_min ); ?>">
		<label class="mc-lbl" for="mc-e-det">বিস্তারিত (যোগ্যতা, পদসংখ্যা, কর্মস্থল, আবেদনের নিয়ম)</label>
		<textarea class="mc-inp" id="mc-e-det" name="details" rows="6" maxlength="4000"></textarea>
		<?php if ( $mhp_up ) : ?>
			<label class="mc-lbl" for="mc-e-file">সার্কুলারের ছবি বা পিডিএফ (৫ এমবি পর্যন্ত)</label>
			<input class="mc-inp" id="mc-e-file" name="circular" type="file" accept=".jpg,.jpeg,.png,.webp,.pdf">
		<?php endif; ?>
		<label class="mc-lbl" for="mc-e-name">যোগাযোগকারীর নাম</label>
		<input class="mc-inp" id="mc-e-name" name="contact_name" maxlength="100">
		<label class="mc-lbl" for="mc-e-ph">মোবাইল *</label>
		<input class="mc-inp" id="mc-e-ph" name="phone" type="tel" required maxlength="20" inputmode="tel" placeholder="01XXXXXXXXX">
		<label class="mc-lbl" for="mc-e-em">ইমেইল</label>
		<input class="mc-inp" id="mc-e-em" name="email" type="email" maxlength="120">
		<?php if ( ! empty( $mhp_pk['jobs'] ) ) : ?>
			<label class="mc-lbl" for="mc-e-pk">প্যাকেজ</label>
			<select class="mc-inp" id="mc-e-pk" name="package">
				<option value="">এখনো ঠিক করিনি</option>
				<?php foreach ( $mhp_pk['jobs'] as $mhp_p ) : ?>
					<option value="<?php echo esc_attr( $mhp_p['name'] ); ?>"><?php echo esc_html( $mhp_p['name'] ); ?></option>
				<?php endforeach; ?>
			</select>
		<?php endif; ?>
		<div class="mc-hp" aria-hidden="true"><label>Website <input name="website" tabindex="-1" autocomplete="off"></label></div>
		<p class="mc-xs mc-muted">জমা দিলে আপনি নিশ্চিত করছেন যে তথ্যগুলো সঠিক। ভুয়া বা প্রতারণামূলক বিজ্ঞপ্তি প্রকাশ করা হয় না।</p>
		<button type="submit" class="mc-btn">জমা দিন</button>
	</form>
</section>

<?php if ( ! empty( $mhp_pk['ads'] ) ) : ?>
	<section class="mc-sec mc-g">
		<div class="mc-sech"><h2>বিজ্ঞাপন ও স্পনসরশিপ</h2></div>
		<div class="mc-col mc-grid2">
			<?php foreach ( $mhp_pk['ads'] as $mhp_p ) : ?>
				<div class="mc-card mc-pad mc-pkg">
					<b class="mc-block"><?php echo esc_html( $mhp_p['name'] ); ?></b>
					<div class="mc-price"><?php echo esc_html( '' !== (string) $mhp_p['price'] ? $mhp_p['price'] : 'যোগাযোগ করুন' ); ?></div>
					<?php if ( ! empty( $mhp_p['desc'] ) ) : ?>
						<p class="mc-xs mc-muted"><?php echo esc_html( $mhp_p['desc'] ); ?></p>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
	</section>
<?php endif; ?>

<?php if ( ! empty( $mhp_pk['contact'] ) ) : ?>
	<section class="mc-sec mc-g"><div class="mc-card mc-pad mc-sm"><?php echo wp_kses_post( wpautop( $mhp_pk['contact'] ) ); ?></div></section>
<?php endif; ?>
