<?php
/**
 * CV editor: form on one side, live A4 preview on the other (tabs on phones).
 * Vars: $cv, $tpl (current design or null), $tpls, $conf (editor script config), $owned, $share, $pay_ok.
 *
 * @package meeha-prep
 */

defined( 'ABSPATH' ) || exit;

$mhp_d    = $cv['data'];
$mhp_val  = function ( $k ) use ( $mhp_d ) {
	return isset( $mhp_d[ $k ] ) && is_scalar( $mhp_d[ $k ] ) ? (string) $mhp_d[ $k ] : '';
};
$mhp_pers = isset( $mhp_d['personal'] ) && is_array( $mhp_d['personal'] ) ? $mhp_d['personal'] : array();
$mhp_rows = function ( $list ) use ( $mhp_d ) {
	return isset( $mhp_d[ $list ] ) && is_array( $mhp_d[ $list ] ) ? array_values( $mhp_d[ $list ] ) : array();
};
$mhp_lines = function ( $list, $f ) use ( $mhp_rows ) {
	$out = array();
	foreach ( $mhp_rows( $list ) as $r ) {
		$out[] = isset( $r[ $f ] ) ? (string) $r[ $f ] : '';
	}
	return implode( "\n", $out );
};
$mhp_lang = isset( $mhp_d['lang'] ) && 'en' === $mhp_d['lang'] ? 'en' : 'bn';

// Repeater field labels.
$mhp_rep = array(
	'experience' => array(
		'role'    => array( 'পদবি', 'text' ),
		'org'     => array( 'প্রতিষ্ঠান', 'text' ),
		'place'   => array( 'স্থান', 'text' ),
		'from'    => array( 'শুরু (মাস/বছর)', 'text' ),
		'to'      => array( 'শেষ, বা "বর্তমান"', 'text' ),
		'details' => array( 'দায়িত্ব ও অর্জন', 'textarea' ),
	),
	'education'  => array(
		'degree' => array( 'পরীক্ষা/ডিগ্রি', 'text' ),
		'inst'   => array( 'প্রতিষ্ঠান/বোর্ড', 'text' ),
		'year'   => array( 'পাসের সাল', 'text' ),
		'result' => array( 'ফল (জিপিএ/সিজিপিএ/বিভাগ)', 'text' ),
	),
	'languages'  => array(
		'name'  => array( 'ভাষা', 'text' ),
		'level' => array( 'দক্ষতা (যেমন: সাবলীল)', 'text' ),
	),
	'training'   => array(
		'name' => array( 'প্রশিক্ষণ/সনদ', 'text' ),
		'org'  => array( 'প্রতিষ্ঠান', 'text' ),
		'year' => array( 'সাল', 'text' ),
	),
	'references' => array(
		'name'    => array( 'নাম', 'text' ),
		'role'    => array( 'পদবি ও প্রতিষ্ঠান', 'text' ),
		'contact' => array( 'ফোন/ইমেইল', 'text' ),
	),
);
$mhp_add_label = array(
	'experience' => 'আরেকটি অভিজ্ঞতা',
	'education'  => 'আরেকটি পরীক্ষা/ডিগ্রি',
	'languages'  => 'আরেকটি ভাষা',
	'training'   => 'আরেকটি প্রশিক্ষণ',
	'references' => 'আরেকজন রেফারেন্স',
);

/**
 * One repeater row.
 *
 * @param string     $list   List.
 * @param string|int $i      Index.
 * @param array      $row    Values.
 * @param array      $fields Labels.
 * @param int        $max    Max length per field.
 */
$mhp_row = function ( $list, $i, $row, $fields ) {
	$lim = MHP_CV::LISTS[ $list ]['fields'];
	echo '<div class="mc-rep__row" data-row>';
	foreach ( $fields as $f => $def ) {
		$name = 'cv[' . $list . '][' . $i . '][' . $f . ']';
		$id   = 'mc-cv-' . $list . '-' . $i . '-' . $f;
		$val  = isset( $row[ $f ] ) ? (string) $row[ $f ] : '';
		echo '<div class="mc-rep__f mc-rep__f--' . esc_attr( $f ) . '"><label class="mc-lbl" for="' . esc_attr( $id ) . '">' . esc_html( $def[0] ) . '</label>';
		if ( 'textarea' === $def[1] ) {
			echo '<textarea class="mc-inp" rows="3" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" maxlength="' . (int) $lim[ $f ] . '">' . esc_textarea( $val ) . '</textarea>';
		} else {
			echo '<input class="mc-inp" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" maxlength="' . (int) $lim[ $f ] . '" value="' . esc_attr( $val ) . '">';
		}
		echo '</div>';
	}
	echo '<button type="button" class="mc-btn mc-btn--sm mc-btn--ghost mc-rep__rm" data-rm>মুছুন</button></div>';
};

$mhp_repeater = function ( $list ) use ( $mhp_rep, $mhp_rows, $mhp_row, $mhp_add_label ) {
	$rows = $mhp_rows( $list );
	$max  = MHP_CV::LISTS[ $list ]['max'];
	if ( count( $rows ) < $max ) {
		$rows[] = array();
	}
	echo '<div class="mc-rep" data-rep="' . esc_attr( $list ) . '" data-max="' . (int) $max . '" data-next="' . count( $rows ) . '">';
	foreach ( $rows as $i => $r ) {
		$mhp_row( $list, $i, $r, $mhp_rep[ $list ] );
	}
	echo '</div><template data-tpl="' . esc_attr( $list ) . '">';
	$mhp_row( $list, '__i__', array(), $mhp_rep[ $list ] );
	echo '</template><button type="button" class="mc-btn mc-btn--sm mc-btn--soft mc-rep__add" data-add="' . esc_attr( $list ) . '">' . mhp_icon( 'plus' ) . ' ' . esc_html( $mhp_add_label[ $list ] ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
};

$mhp_input = function ( $name, $label, $val, $max, $type = 'text', $extra = '' ) {
	$id = 'mc-cv-' . preg_replace( '/[^a-z0-9]+/', '-', $name );
	echo '<label class="mc-lbl" for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
	if ( 'textarea' === $type ) {
		echo '<textarea class="mc-inp" rows="4" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" maxlength="' . (int) $max . '"' . $extra . '>' . esc_textarea( $val ) . '</textarea>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	} else {
		echo '<input class="mc-inp" type="' . esc_attr( $type ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" maxlength="' . (int) $max . '" value="' . esc_attr( $val ) . '"' . $extra . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
};

$mhp_link    = mhp_url( 'cv-view', $cv['token'] );
$mhp_preview = $tpl ? MHP_CV::document( $tpl, MHP_CV::context( $mhp_d, $conf['photo'] ), array( 'watermark' => ! $owned ) ) : '';
?>
<div class="mc-g mc-cved" data-cved>
	<div class="mc-seg mc-cved__switch" role="tablist" aria-label="সম্পাদনা ও প্রিভিউ">
		<a href="#mc-cvform" class="is-on" data-show="form" role="tab" aria-selected="true">তথ্য লিখুন</a>
		<a href="#mc-cvpre" data-show="preview" role="tab" aria-selected="false">প্রিভিউ</a>
	</div>

	<form method="post" enctype="multipart/form-data" class="mc-cved__form" id="mc-cvform" action="<?php echo esc_url( mhp_url( 'cv-edit', $cv['id'] ) ); ?>">
		<?php echo MHP_Front::form_fields( 'cv_save' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<input type="hidden" name="cv_id" value="<?php echo (int) $cv['id']; ?>">

		<div class="mc-card mc-pad mc-cved__top">
			<?php $mhp_input( 'cv_title', 'সিভির নাম (শুধু আপনার তালিকায় দেখাবে)', $cv['title'], 120 ); ?>
			<div class="mc-cved__two">
				<div>
					<label class="mc-lbl" for="mc-cv-tpl">টেমপ্লেট</label>
					<select class="mc-inp" id="mc-cv-tpl" name="tpl_id">
						<?php foreach ( $tpls as $mhp_t ) : ?>
							<?php $mhp_own = ! empty( $conf['tpls'][ $mhp_t['id'] ]['owned'] ); ?>
							<option value="<?php echo (int) $mhp_t['id']; ?>"<?php selected( $tpl && $tpl['id'] === $mhp_t['id'] ); ?>><?php echo esc_html( $mhp_t['name'] . ' · ' . ( $mhp_t['price'] <= 0 ? 'ফ্রি' : ( $mhp_own ? 'কেনা' : mhp_tk( $mhp_t['price'] ) ) ) . ' · ' . mhp_bn( $mhp_t['pages'] ) . ' পাতা' ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div>
					<label class="mc-lbl" for="mc-cv-lang">শিরোনামের ভাষা</label>
					<select class="mc-inp" id="mc-cv-lang" name="cv[lang]">
						<option value="bn"<?php selected( 'bn', $mhp_lang ); ?>>বাংলা</option>
						<option value="en"<?php selected( 'en', $mhp_lang ); ?>>English</option>
					</select>
				</div>
			</div>
			<p class="mc-xs mc-muted">শিরোনাম (যেমন "শিক্ষাগত যোগ্যতা") বাছাই করা ভাষায় ছাপা হবে; আপনার লেখা যেমন লিখবেন তেমনই থাকবে।</p>
		</div>

		<details class="mc-card mc-cved__sec" open>
			<summary>পরিচিতি ও যোগাযোগ</summary>
			<div class="mc-pad">
				<?php $mhp_input( 'cv[name]', 'পুরো নাম', $mhp_val( 'name' ), 100, 'text', ' autocomplete="name"' ); ?>
				<?php $mhp_input( 'cv[headline]', 'পদ বা পেশাগত পরিচয় (যেমন: হিসাবরক্ষক, সদ্য স্নাতক)', $mhp_val( 'headline' ), 150 ); ?>
				<div class="mc-cved__two">
					<div><?php $mhp_input( 'cv[phone]', 'মোবাইল', $mhp_val( 'phone' ), 40, 'tel', ' autocomplete="tel"' ); ?></div>
					<div><?php $mhp_input( 'cv[email]', 'ইমেইল', $mhp_val( 'email' ), 100, 'email', ' autocomplete="email"' ); ?></div>
				</div>
				<?php $mhp_input( 'cv[address]', 'বর্তমান ঠিকানা', $mhp_val( 'address' ), 250, 'textarea', ' rows="2"' ); ?>
				<?php $mhp_input( 'cv[website]', 'ওয়েবসাইট/লিংকডইন (ঐচ্ছিক)', $mhp_val( 'website' ), 200, 'text', ' inputmode="url" placeholder="linkedin.com/in/…"' ); ?>
				<label class="mc-lbl" for="mc-cv-photo">ছবি (পাসপোর্ট সাইজ; জেপিজি, পিএনজি বা ওয়েবপি, ৩ এমবির কম)</label>
				<input class="mc-inp" type="file" id="mc-cv-photo" name="cv_photo" accept="image/jpeg,image/png,image/webp">
				<?php if ( $cv['photo'] ) : ?>
					<label class="mc-check mc-xs"><input type="checkbox" name="photo_remove" value="1" data-photo-rm> ছবি সরিয়ে দিন</label>
				<?php endif; ?>
				<p class="mc-xs mc-muted">ছবি শুধু আপনার সিভিতে দেখায়; আলাদা কোনো খোলা লিংকে রাখা হয় না।</p>
			</div>
		</details>

		<details class="mc-card mc-cved__sec" open>
			<summary>সংক্ষিপ্ত পরিচিতি</summary>
			<div class="mc-pad">
				<?php $mhp_input( 'cv[summary]', 'দুই-চার লাইনে নিজের কথা: কী পারেন, কোন কাজ করতে চান', $mhp_val( 'summary' ), 1500, 'textarea' ); ?>
			</div>
		</details>

		<details class="mc-card mc-cved__sec">
			<summary>কর্ম-অভিজ্ঞতা</summary>
			<div class="mc-pad"><p class="mc-xs mc-muted">নতুনটি আগে লিখুন। অভিজ্ঞতা না থাকলে ফাঁকা রাখুন, সিভিতে অংশটি আসবে না।</p><?php $mhp_repeater( 'experience' ); ?></div>
		</details>

		<details class="mc-card mc-cved__sec">
			<summary>শিক্ষাগত যোগ্যতা</summary>
			<div class="mc-pad"><?php $mhp_repeater( 'education' ); ?></div>
		</details>

		<details class="mc-card mc-cved__sec">
			<summary>দক্ষতা ও ভাষা</summary>
			<div class="mc-pad">
				<?php $mhp_input( 'cv[skills]', 'দক্ষতা (প্রতি লাইনে একটি)', $mhp_lines( 'skills', 'name' ), 2000, 'textarea' ); ?>
				<h3 class="mc-h3">ভাষা</h3>
				<?php $mhp_repeater( 'languages' ); ?>
			</div>
		</details>

		<details class="mc-card mc-cved__sec">
			<summary>প্রশিক্ষণ ও অর্জন</summary>
			<div class="mc-pad">
				<?php $mhp_repeater( 'training' ); ?>
				<?php $mhp_input( 'cv[achievements]', 'অর্জন, পুরস্কার বা স্বেচ্ছাসেবা (প্রতি লাইনে একটি)', $mhp_lines( 'achievements', 'text' ), 4600, 'textarea' ); ?>
			</div>
		</details>

		<details class="mc-card mc-cved__sec">
			<summary>ব্যক্তিগত তথ্য</summary>
			<div class="mc-pad">
				<p class="mc-xs mc-muted">সরকারি চাকরির সিভিতে সাধারণত লাগে; বেসরকারি চাকরিতে ঐচ্ছিক। ফাঁকা রাখলে ছাপা হবে না।</p>
				<div class="mc-cved__two">
					<?php
					foreach ( array(
						'father'      => 'পিতার নাম',
						'mother'      => 'মাতার নাম',
						'dob'         => 'জন্ম তারিখ',
						'nationality' => 'জাতীয়তা',
						'religion'    => 'ধর্ম',
						'marital'     => 'বৈবাহিক অবস্থা',
					) as $mhp_k => $mhp_l ) {
						echo '<div>';
						$mhp_input( 'cv[personal][' . $mhp_k . ']', $mhp_l, isset( $mhp_pers[ $mhp_k ] ) ? (string) $mhp_pers[ $mhp_k ] : '', MHP_CV::PERSONAL[ $mhp_k ] );
						echo '</div>';
					}
					?>
				</div>
				<?php $mhp_input( 'cv[personal][permanent]', 'স্থায়ী ঠিকানা', isset( $mhp_pers['permanent'] ) ? (string) $mhp_pers['permanent'] : '', 250, 'textarea', ' rows="2"' ); ?>
			</div>
		</details>

		<details class="mc-card mc-cved__sec">
			<summary>রেফারেন্স ও ঘোষণা</summary>
			<div class="mc-pad">
				<?php $mhp_repeater( 'references' ); ?>
				<?php $mhp_input( 'cv[declaration]', 'ঘোষণা (ফাঁকা রাখলে ছাপা হবে না)', $mhp_val( 'declaration' ), 600, 'textarea', ' rows="3"' ); ?>
			</div>
		</details>

		<div class="mc-cved__save">
			<button type="submit" class="mc-btn" name="then" value="stay">সংরক্ষণ</button>
			<button type="submit" class="mc-btn mc-btn--ghost" name="then" value="view">সংরক্ষণ করে প্রিন্ট/পিডিএফ</button>
			<span class="mc-xs mc-muted" data-dirty hidden>সংরক্ষণ করা হয়নি এমন পরিবর্তন আছে</span>
		</div>
	</form>

	<aside class="mc-cved__pre" id="mc-cvpre" aria-label="প্রিভিউ">
		<div class="mc-cved__status mc-xs" data-status>
			<span data-pages></span>
			<span class="mc-cved__lock" data-lock<?php echo $owned ? ' hidden' : ''; ?>>পেইড টেমপ্লেট: প্রিভিউতে জলছাপ থাকবে। <a href="<?php echo esc_url( $tpl ? mhp_url( 'checkout', 'cvtpl-' . $tpl['id'] ) : mhp_url( 'cv' ) ); ?>" data-buy><?php echo esc_html( $tpl ? mhp_tk( $tpl['price'] ) . '-এ কিনুন' : 'কিনুন' ); ?></a></span>
			<span class="mc-cved__over" data-over hidden></span>
		</div>
		<div class="mc-cved__paper" data-paper>
			<iframe id="mc-cvframe" title="সিভির প্রিভিউ" sandbox="allow-same-origin" srcdoc="<?php echo htmlspecialchars( $mhp_preview, ENT_QUOTES, 'UTF-8', true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- double-encoded on purpose (esc_attr keeps entities). ?>"></iframe>
		</div>

		<div class="mc-card mc-pad mc-mt mc-cved__share">
			<h2 class="mc-h3">লিংক ও শেয়ার</h2>
			<?php if ( $share ) : ?>
				<p class="mc-sm"><?php echo $cv['is_public'] ? 'পাবলিক লিংক <b>চালু</b>: লিংক যাঁর কাছে আছে, তিনি সিভি দেখতে ও পিডিএফ নিতে পারবেন।' : 'লিংকটি এখন শুধু আপনি খুলতে পারবেন।'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
				<div class="mc-field"><input type="text" readonly value="<?php echo esc_attr( $mhp_link ); ?>" aria-label="সিভির লিংক" data-copy-src><button type="button" class="mc-btn mc-btn--sm mc-btn--soft" data-copy>কপি</button></div>
				<form method="post" class="mc-row mc-wrap mc-mt" action="<?php echo esc_url( mhp_url( 'cv-edit', $cv['id'] ) ); ?>">
					<?php echo MHP_Front::form_fields( 'cv_share' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<input type="hidden" name="cv_id" value="<?php echo (int) $cv['id']; ?>">
					<input type="hidden" name="public" value="<?php echo $cv['is_public'] ? '0' : '1'; ?>">
					<button type="submit" class="mc-btn mc-btn--sm"><?php echo $cv['is_public'] ? 'পাবলিক লিংক বন্ধ করুন' : 'পাবলিক লিংক চালু করুন'; ?></button>
				</form>
				<?php if ( $cv['is_public'] ) : ?>
					<form method="post" class="mc-mt" action="<?php echo esc_url( mhp_url( 'cv-edit', $cv['id'] ) ); ?>" data-confirm="আগের লিংক আর কাজ করবে না। নতুন লিংক বানাবেন?">
						<?php echo MHP_Front::form_fields( 'cv_share' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<input type="hidden" name="cv_id" value="<?php echo (int) $cv['id']; ?>">
						<input type="hidden" name="public" value="1">
						<input type="hidden" name="new_link" value="1">
						<button type="submit" class="mc-btn mc-btn--sm mc-btn--ghost">নতুন লিংক বানান</button>
					</form>
				<?php endif; ?>
			<?php elseif ( $tpl && $tpl['price'] > 0 ) : ?>
				<p class="mc-sm">এই টেমপ্লেট কিনলে জলছাপ ছাড়া প্রিন্ট/পিডিএফ, <?php echo esc_html( mhp_bn( $tpl['pages'] ) ); ?> পাতা পর্যন্ত আর পাবলিক শেয়ার লিংক পাবেন।</p>
				<?php if ( $pay_ok ) : ?>
					<a class="mc-btn mc-btn--sm" href="<?php echo esc_url( mhp_url( 'checkout', 'cvtpl-' . $tpl['id'] ) ); ?>"><?php echo esc_html( mhp_tk( $tpl['price'] ) ); ?>-এ কিনুন</a>
				<?php else : ?>
					<p class="mc-xs mc-muted">অনলাইন পেমেন্ট এখনো চালু হয়নি।</p>
				<?php endif; ?>
			<?php else : ?>
				<p class="mc-sm">ফ্রি টেমপ্লেটের লিংক শুধু আপনি (লগইন অবস্থায়) খুলতে পারবেন; সেখান থেকে প্রিন্ট বা পিডিএফ নিন। অন্যদের সঙ্গে লিংক শেয়ার করতে পেইড টেমপ্লেট বেছে নিন।</p>
			<?php endif; ?>
			<a class="mc-btn mc-btn--sm mc-btn--ghost mc-mt" href="<?php echo esc_url( $mhp_link ); ?>" target="_blank" rel="noopener">সংরক্ষিত সিভি খুলুন</a>
			<p class="mc-xs mc-muted">টেমপ্লেট বদলালে আগে সংরক্ষণ করুন; লিংক আর শেয়ারের নিয়ম সংরক্ষিত টেমপ্লেট অনুযায়ী চলে।</p>
		</div>

		<form method="post" class="mc-mt mc-center" action="<?php echo esc_url( mhp_url( 'cv-edit', $cv['id'] ) ); ?>" data-confirm="সিভিটি একেবারে মুছে যাবে। নিশ্চিত?">
			<?php echo MHP_Front::form_fields( 'cv_delete' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<input type="hidden" name="cv_id" value="<?php echo (int) $cv['id']; ?>">
			<button type="submit" class="mc-btn mc-btn--sm mc-btn--ghost mc-danger">সিভি মুছুন</button>
		</form>
	</aside>
</div>
<script type="application/json" id="mc-cv-conf"><?php echo wp_json_encode( $conf, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE ); ?></script>
