<?php
/**
 * গোপন তথ্য পাঠান (r46) — the public tipline page. The data side (table, files, desk API) is the newsroom-os
 * plugin's NROS_Tips; this module renders [meeha_tips]: the form (posts multipart to nros/v1/tips, no nonce — the
 * page is cached — origin-checked server-side) and the anonymous status checker (/tips/?code=…, PIN typed in).
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

/**
 * Content of the auto-created /tips/ page.
 *
 * @return string
 */
function meeha_tips_page_content() {
	return '[meeha_tips]';
}

if ( ! function_exists( 'meeha_reader_ensure_page' ) ) {
	require_once MEEHA_DIR . '/inc/saved/bootstrap.php';
}

add_action(
	'wp',
	function () {
		if ( is_singular() ) {
			$p = get_queried_object();
			if ( $p instanceof WP_Post && has_shortcode( (string) $p->post_content, 'meeha_tips' ) ) {
				meeha_enqueue_bundle( 'tips' );
				// Never cache a page a tipster may have typed into; no referrer to the outside.
				nocache_headers();
				header( 'Referrer-Policy: no-referrer' );
			}
		}
	}
);

/* The status page is per-person: keep it out of search results. */
add_filter(
	'wp_robots',
	function ( $robots ) {
		if ( isset( $_GET['code'] ) && is_singular() && has_shortcode( (string) get_post_field( 'post_content', get_queried_object_id() ), 'meeha_tips' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
		}
		return $robots;
	}
);

add_shortcode( 'meeha_tips', 'meeha_tips_render' );

/**
 * [meeha_tips].
 *
 * @return string
 */
function meeha_tips_render() {
	meeha_enqueue_bundle( 'tips' );
	if ( ! class_exists( 'NROS_Tips' ) ) {
		return '<div class="m-tips m-tips--off"><p>গোপন তথ্য পাঠানোর ব্যবস্থাটি এই মুহূর্তে বন্ধ আছে। পরে আবার চেষ্টা করুন।</p></div>';
	}
	$lim   = NROS_Tips::limits();
	$code  = isset( $_GET['code'] ) ? strtoupper( preg_replace( '/[^A-Za-z0-9\-]/', '', sanitize_text_field( wp_unslash( $_GET['code'] ) ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$mb    = (int) floor( $lim['max_file'] / MB_IN_BYTES );
	$dists = array();
	if ( function_exists( 'meeha_get_bd_divisions' ) ) {
		foreach ( meeha_get_bd_divisions() as $div => $ds ) {
			$dists[ $div ] = $ds;
		}
	}
	$cfg = array(
		'endpoint' => $lim['endpoint'],
		'status'   => $lim['status_url'],
		'followup' => $lim['follow_url'],
		'maxFile'  => (int) $lim['max_file'],
		'maxFiles' => (int) $lim['max_files'],
		'page'     => get_permalink(),
	);
	ob_start();
	?>
	<div class="m-tips" data-tips="<?php echo esc_attr( wp_json_encode( $cfg, JSON_UNESCAPED_SLASHES ) ); ?>">
		<div class="m-tips__privacy" role="note">
			<p class="m-tips__shield"><?php echo meeha_icon( 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><strong>আপনার পরিচয় গোপন থাকবে।</strong></p>
			<ul>
				<li>নাম, ফোন বা ইমেইল দেওয়া লাগবে না। দিলে তা এনক্রিপ্ট করে রাখা হয় এবং শুধু সম্পাদকেরা দেখতে পান — কখনো প্রকাশ করা হয় না।</li>
				<li>আমরা আপনার আইপি ঠিকানা সংরক্ষণ করি না। ছবির ভেতরের লুকানো তথ্য (ক্যামেরা, তারিখ, জিপিএস লোকেশন) সার্ভারে পৌঁছানোমাত্র মুছে ফেলা হয়।</li>
				<li>ভিডিও, অডিও ও PDF-এর ভেতরের তথ্য আমরা মুছতে পারি না — সম্ভব হলে স্ক্রিনশট বা স্ক্রিন রেকর্ড পাঠান।</li>
				<li>বেশি ঝুঁকিতে থাকলে অফিসের বা নিজের ফোন/ওয়াই-ফাই নয়, অন্য কোনো ডিভাইস ও নেটওয়ার্ক থেকে পাঠান।</li>
			</ul>
		</div>

		<div class="m-tips__tabs" role="tablist" aria-label="গোপন তথ্য">
			<button type="button" role="tab" id="m-tips-tab-send" aria-controls="m-tips-send" aria-selected="<?php echo $code ? 'false' : 'true'; ?>" data-tab="send">তথ্য পাঠান</button>
			<button type="button" role="tab" id="m-tips-tab-check" aria-controls="m-tips-check" aria-selected="<?php echo $code ? 'true' : 'false'; ?>" data-tab="check">অবস্থা দেখুন</button>
		</div>

		<form class="m-tips__form" id="m-tips-send" role="tabpanel" aria-labelledby="m-tips-tab-send" method="post" enctype="multipart/form-data" action="<?php echo esc_url( $lim['endpoint'] ); ?>" novalidate<?php echo $code ? ' hidden' : ''; ?>>
			<fieldset class="m-tips__cats">
				<legend>বিষয় <span aria-hidden="true">*</span></legend>
				<?php $first = true; foreach ( $lim['categories'] as $k => $label ) : ?>
					<label class="m-tips__cat"><input type="radio" name="category" value="<?php echo esc_attr( $k ); ?>"<?php echo $first ? ' checked' : ''; ?>><span><?php echo esc_html( $label ); ?></span></label>
				<?php $first = false; endforeach; ?>
			</fieldset>

			<div class="m-tips__field">
				<label for="m-tips-body">কী ঘটেছে? <span aria-hidden="true">*</span></label>
				<p class="m-tips__hint" id="m-tips-body-hint">কী, কোথায়, কখন, কারা জড়িত, আপনি কীভাবে জানলেন — যতটা পারেন লিখুন। নিজের পরিচয় প্রকাশ পায় এমন কিছু না লিখলেও চলবে।</p>
				<textarea class="m-input" id="m-tips-body" name="body" rows="8" required minlength="20" maxlength="20000" aria-describedby="m-tips-body-hint"></textarea>
			</div>

			<div class="m-tips__row">
				<div class="m-tips__field">
					<label for="m-tips-district">জেলা</label>
					<select class="m-input" id="m-tips-district" name="district">
						<option value="">— জেলা বাছুন (ঐচ্ছিক) —</option>
						<?php foreach ( $dists as $div => $ds ) : ?>
							<optgroup label="<?php echo esc_attr( $div . ' বিভাগ' ); ?>">
								<?php foreach ( $ds as $d ) : ?>
									<option value="<?php echo esc_attr( $d ); ?>"><?php echo esc_html( $d ); ?></option>
								<?php endforeach; ?>
							</optgroup>
						<?php endforeach; ?>
					</select>
				</div>
				<label class="m-tips__urgent"><input type="checkbox" name="urgent" value="1"> জরুরি — কারও জীবন বা নিরাপত্তা ঝুঁকিতে</label>
			</div>

			<div class="m-tips__field">
				<label for="m-tips-files">প্রমাণ (ঐচ্ছিক)</label>
				<p class="m-tips__hint" id="m-tips-files-hint">ছবি, PDF, অডিও বা ভিডিও — সর্বোচ্চ <?php echo esc_html( meeha_bn_digits( $lim['max_files'] ) ); ?>টি ফাইল, প্রতিটি <?php echo esc_html( meeha_bn_digits( $mb ) ); ?> এমবি পর্যন্ত। ফাইল পাবলিক মিডিয়া লাইব্রেরিতে যায় না, আলাদা সুরক্ষিত জায়গায় থাকে।</p>
				<input class="m-tips__file" type="file" id="m-tips-files" name="files[]" multiple accept="<?php echo esc_attr( $lim['accept'] ); ?>" aria-describedby="m-tips-files-hint">
				<ul class="m-tips__filelist" data-filelist aria-live="polite"></ul>
			</div>

			<details class="m-tips__contact">
				<summary>যোগাযোগের তথ্য দিতে চান? (ঐচ্ছিক)</summary>
				<p class="m-tips__hint">দিলে প্রতিবেদক দরকার হলে আপনার সঙ্গে যোগাযোগ করতে পারবেন। না দিলেও ট্র্যাকিং কোড দিয়ে এই পাতায় উত্তর দেখতে পাবেন।</p>
				<div class="m-tips__row m-tips__row--3">
					<div class="m-tips__field"><label for="m-tips-name">নাম বা ছদ্মনাম</label><input class="m-input" id="m-tips-name" name="name" autocomplete="off" maxlength="120"></div>
					<div class="m-tips__field"><label for="m-tips-phone">ফোন</label><input class="m-input" id="m-tips-phone" name="phone" inputmode="tel" autocomplete="off" maxlength="30"></div>
					<div class="m-tips__field"><label for="m-tips-email">ইমেইল</label><input class="m-input" id="m-tips-email" name="email" type="email" autocomplete="off" maxlength="120"></div>
				</div>
			</details>

			<div class="m-tips__hp" aria-hidden="true"><label for="m-tips-website">ওয়েবসাইট</label><input id="m-tips-website" name="website" tabindex="-1" autocomplete="off"></div>

			<p class="m-tips__err" role="alert" data-err hidden></p>
			<div class="m-tips__progress" data-progress hidden><span></span></div>
			<button type="submit" class="m-btn m-tips__submit">গোপনে পাঠান</button>
		</form>

		<section class="m-tips__done" data-done hidden tabindex="-1" aria-labelledby="m-tips-done-h">
			<h2 id="m-tips-done-h">ধন্যবাদ, আপনার তথ্য পৌঁছেছে</h2>
			<p>নিচের <strong>ট্র্যাকিং কোড ও পিন</strong> লিখে রাখুন বা স্ক্রিনশট নিন — নিরাপত্তার জন্য এগুলো আর কখনো দেখানো হবে না। এ দিয়ে এই পাতায় এসে অবস্থা ও আমাদের উত্তর দেখতে পারবেন।</p>
			<dl class="m-tips__creds">
				<div><dt>ট্র্যাকিং কোড</dt><dd data-code></dd></div>
				<div><dt>পিন</dt><dd data-pin></dd></div>
			</dl>
			<p><button type="button" class="m-btn m-btn--ghost" data-copy-creds>কোড ও পিন কপি করুন</button> <a class="m-btn m-btn--ghost" data-status-link href="#">অবস্থার পাতা</a></p>
		</section>

		<section class="m-tips__check" id="m-tips-check" role="tabpanel" aria-labelledby="m-tips-tab-check"<?php echo $code ? '' : ' hidden'; ?>>
			<form class="m-tips__checkform" data-checkform novalidate>
				<div class="m-tips__row">
					<div class="m-tips__field"><label for="m-tips-code">ট্র্যাকিং কোড</label><input class="m-input" id="m-tips-code" name="code" value="<?php echo esc_attr( $code ); ?>" placeholder="MH-XXXX-XXXX" autocomplete="off" autocapitalize="characters" required></div>
					<div class="m-tips__field"><label for="m-tips-pin">পিন (৬ সংখ্যা)</label><input class="m-input" id="m-tips-pin" name="pin" inputmode="numeric" autocomplete="off" maxlength="6" required></div>
				</div>
				<p class="m-tips__err" role="alert" data-check-err hidden></p>
				<button type="submit" class="m-btn">অবস্থা দেখুন</button>
			</form>
			<div class="m-tips__status" data-status hidden aria-live="polite"></div>
		</section>
	</div>
	<?php
	return (string) ob_get_clean();
}
