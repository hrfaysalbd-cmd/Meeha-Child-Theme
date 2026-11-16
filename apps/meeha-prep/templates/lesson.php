<?php
/**
 * One lesson. Vars: $l (data), $open (may read), $body (sanitised HTML), $qs (practice questions with answers),
 * $done, $next (array|null), $pack (WP_Post|null), $pay_ok.
 *
 * @package meeha-prep
 */

defined( 'ABSPATH' ) || exit;

$mhp_uid = get_current_user_id();
?>
<article class="mc-g mc-detail mc-lessonv">
	<div class="mc-row mc-wrap mc-gap6">
		<?php if ( $l['track'] ) : ?>
			<span class="mc-pill mc-pill--brand"><?php echo esc_html( $l['track']['name'] ); ?></span>
		<?php endif; ?>
		<?php if ( $l['subject'] ) : ?>
			<span class="mc-pill mc-pill--line"><?php echo esc_html( $l['subject'] ); ?></span>
		<?php endif; ?>
		<span class="mc-xs mc-muted"><?php echo mhp_icon( 'clock' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php echo esc_html( mhp_bn( $l['minutes'] ) ); ?> মিনিট</span>
		<?php if ( $done ) : ?>
			<span class="mc-pill mc-pill--ok"><?php echo mhp_icon( 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> পড়েছেন</span>
		<?php endif; ?>
	</div>
	<h1 class="mc-h1"><?php echo esc_html( $l['title'] ); ?></h1>
	<?php if ( $l['excerpt'] ) : ?>
		<p class="mc-lessonv__lede mc-muted"><?php echo esc_html( $l['excerpt'] ); ?></p>
	<?php endif; ?>

	<?php if ( ! $open ) : ?>
		<div class="mc-card mc-pad mc-center mc-mt">
			<?php if ( ! $pack ) : ?>
				<p><b>এই পাঠটি একটি পেইড প্যাকের অংশ।</b></p>
				<p class="mc-sm mc-muted">প্যাকটি এখন বিক্রি হচ্ছে না। যাঁরা আগে কিনেছেন, তাঁরা লগইন করলে পাঠটি দেখতে পাবেন।</p>
			<?php else : ?>
				<p><b>এই পাঠটি "<?php echo esc_html( get_the_title( $pack ) ); ?>"-এর অংশ।</b></p>
				<p class="mc-sm mc-muted">প্যাকটি একবার কিনলে এর সব পাঠ ও টেস্ট অ্যাকাউন্টে থাকবে।</p>
				<a class="mc-btn" href="<?php echo esc_url( $mhp_uid ? mhp_url( 'checkout', 'product-' . $pack->ID ) : mhp_login_url( mhp_url( 'lesson', $l['id'] ) ) ); ?>"><?php echo esc_html( $pay_ok ? mhp_tk( mhp_meta( $pack->ID, 'price', 0 ) ) . '-এ প্যাক কিনুন' : 'প্যাকটি দেখুন' ); ?></a>
			<?php endif; ?>
		</div>
	<?php else : ?>
		<?php if ( $l['video'] ) : ?>
			<div class="mc-video mc-mt">
				<iframe src="<?php echo esc_url( $l['video'] ); ?>" title="<?php echo esc_attr( $l['title'] ); ?>" loading="lazy" allow="accelerometer; encrypted-media; gyroscope; picture-in-picture; fullscreen" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe>
			</div>
		<?php endif; ?>
		<div class="mc-prose mc-mt"><?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses_post in the view. ?></div>

		<?php if ( $qs ) : ?>
			<section class="mc-sec">
				<div class="mc-sech"><h2>অনুশীলন করুন</h2></div>
				<p class="mc-xs mc-muted">মনে মনে উত্তর ঠিক করে "উত্তর দেখুন" চাপুন।</p>
				<ol class="mc-col mc-plain">
					<?php foreach ( $qs as $mhp_i => $mhp_q ) : ?>
						<li class="mc-card mc-q">
							<span class="mc-qnum">প্রশ্ন <?php echo esc_html( mhp_bn( $mhp_i + 1 ) ); ?></span>
							<p class="mc-qtext"><?php echo esc_html( $mhp_q['text'] ); ?></p>
							<ul class="mc-plain mc-col mc-gap6">
								<?php foreach ( $mhp_q['opts'] as $mhp_k => $mhp_o ) : ?>
									<li class="mc-opt mc-opt--static"><span class="mc-omr"><?php echo esc_html( MHP_Tests::OPT_LABELS[ $mhp_k ] ); ?></span><span><?php echo esc_html( $mhp_o ); ?></span></li>
								<?php endforeach; ?>
							</ul>
							<details class="mc-reveal">
								<summary>উত্তর দেখুন</summary>
								<p class="mc-sm"><b class="mc-ok">সঠিক: <?php echo esc_html( MHP_Tests::OPT_LABELS[ (int) $mhp_q['answer'] ] . ') ' . ( isset( $mhp_q['opts'][ (int) $mhp_q['answer'] ] ) ? $mhp_q['opts'][ (int) $mhp_q['answer'] ] : '' ) ); ?></b></p>
								<?php if ( $mhp_q['explain'] ) : ?>
									<div class="mc-explain"><?php echo esc_html( $mhp_q['explain'] ); ?></div>
								<?php endif; ?>
							</details>
						</li>
					<?php endforeach; ?>
				</ol>
			</section>
		<?php endif; ?>

		<div class="mc-sticky mc-lessonv__bar">
			<?php if ( ! $mhp_uid ) : ?>
				<a class="mc-btn mc-btn--block" href="<?php echo esc_url( mhp_login_url( mhp_url( 'lesson', $l['id'] ) ) ); ?>">লগইন করে অগ্রগতি রাখুন</a>
			<?php elseif ( ! $done && 'publish' === $l['status'] ) : ?>
				<form method="post" action="<?php echo esc_url( mhp_url( 'lesson', $l['id'] ) ); ?>">
					<?php echo MHP_Front::form_fields( 'lesson_done' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<input type="hidden" name="lesson_id" value="<?php echo (int) $l['id']; ?>">
					<button type="submit" class="mc-btn mc-btn--block"><?php echo mhp_icon( 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> পাঠ শেষ করেছি<?php echo MHP_Points::rules()['lesson'] ? ' (+' . esc_html( mhp_bn( MHP_Points::rules()['lesson'] ) ) . ')' : ''; ?></button>
				</form>
			<?php elseif ( $next ) : ?>
				<a class="mc-btn mc-btn--block" href="<?php echo esc_url( mhp_url( 'lesson', $next['id'] ) ); ?>">পরের পাঠ: <?php echo esc_html( $next['title'] ); ?></a>
			<?php else : ?>
				<a class="mc-btn mc-btn--block mc-btn--soft" href="<?php echo esc_url( mhp_url( 'lessons' ) ); ?>">সব পাঠ</a>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</article>
