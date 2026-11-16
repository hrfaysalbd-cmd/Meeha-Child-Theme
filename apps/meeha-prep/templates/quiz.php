<?php
/**
 * Daily quiz. Vars: $qs (no answers), $play (row|null), $streak, $board, $count, $guest, $review (with answers).
 * Each option is a tiny form (works without JavaScript); prep.js answers through the REST route instead.
 *
 * @package meeha-prep
 */

defined( 'ABSPATH' ) || exit;

$mhp_ans    = $play ? $play['answers'] : array();
$mhp_rev    = array();
foreach ( $review as $mhp_r ) {
	$mhp_rev[ $mhp_r['id'] ] = $mhp_r;
}
// No-JS guest fallback: ?q=<index>&c=<choice> reveals that one question.
// phpcs:disable WordPress.Security.NonceVerification.Recommended
$mhp_gq = isset( $_GET['q'] ) ? absint( $_GET['q'] ) : -1;
$mhp_gc = isset( $_GET['c'] ) ? (int) $_GET['c'] : -1;
// phpcs:enable
$mhp_closed = $guest && ! mhp_opt( 'quiz_guest', 0 ); // guests see the questions but answer only after login.
if ( $guest && ! $mhp_closed && $mhp_gq >= 0 && isset( $qs[ $mhp_gq ] ) && $mhp_gc >= 0 && $mhp_gc <= 3 ) {
	$mhp_one = MHP_Tests::load_questions( array( $qs[ $mhp_gq ]['id'] ), true );
	if ( $mhp_one ) {
		$mhp_rev[ $mhp_one[0]['id'] ] = $mhp_one[0];
		$mhp_ans[ $mhp_gq ]           = $mhp_gc;
	}
}
$mhp_done    = $play && ! empty( $play['finished_at'] );
$mhp_sponsor = trim( (string) mhp_opt( 'quiz_sponsor', '' ) );
$mhp_s_url   = (string) mhp_opt( 'quiz_sponsor_url', '' );
$mhp_s_logo  = (int) mhp_opt( 'quiz_sponsor_logo', 0 );
$mhp_lb_min  = max( 1, (int) mhp_opt( 'rank_min', 5 ) );
$mhp_today   = mhp_today();
?>
<section class="mc-mast mc-g">
	<div class="mc-mast__eyebrow"><?php echo esc_html( mhp_fdate( $mhp_today ) ); ?></div>
	<h1 class="mc-mast__title">আজকের কুইজ</h1>
	<p class="mc-mast__lede"><?php echo esc_html( mhp_bn( count( $qs ) ) ); ?>টি প্রশ্ন, প্রতিটির ব্যাখ্যাসহ। প্রথম উত্তরটাই গোনা হবে।</p>
	<?php if ( $mhp_sponsor ) : ?>
		<div class="mc-xs mc-mast__eyebrow">
			সৌজন্যে:
			<?php if ( $mhp_s_url ) : ?>
				<a href="<?php echo esc_url( $mhp_s_url ); ?>" target="_blank" rel="noopener sponsored"><?php echo $mhp_s_logo ? wp_get_attachment_image( $mhp_s_logo, 'thumbnail', false, array( 'alt' => $mhp_sponsor, 'class' => 'mc-sponsor-logo' ) ) : esc_html( $mhp_sponsor ); ?></a>
			<?php else : ?>
				<?php echo $mhp_s_logo ? wp_get_attachment_image( $mhp_s_logo, 'thumbnail', false, array( 'alt' => $mhp_sponsor, 'class' => 'mc-sponsor-logo' ) ) : esc_html( $mhp_sponsor ); ?>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</section>

<?php if ( ! $qs ) : ?>
	<section class="mc-sec mc-g"><div class="mc-card mc-pad mc-center mc-muted">আজকের কুইজ তৈরি হচ্ছে। একটু পরে আবার আসুন।</div></section>
	<?php
	return;
endif;
?>

<section class="mc-sec mc-g">
	<div class="mc-row mc-between mc-sm">
		<?php if ( ! $guest ) : ?>
			<span><?php echo mhp_icon( 'bolt' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> ধারাবাহিকতা: <b><?php echo esc_html( mhp_bn( $streak ) ); ?> দিন</b></span>
		<?php else : ?>
			<?php if ( $mhp_closed ) : ?>
				<a class="mc-btn mc-btn--sm" href="<?php echo esc_url( mhp_login_url( mhp_url( 'quiz' ) ) ); ?>">লগইন করে খেলুন</a>
			<?php else : ?>
				<a class="mc-link" href="<?php echo esc_url( mhp_login_url( mhp_url( 'quiz' ) ) ); ?>">লগইন করলে স্কোর ও লিডারবোর্ডে নাম থাকবে</a>
			<?php endif; ?>
		<?php endif; ?>
		<span class="mc-muted" data-mc-quiz-score><?php echo $play ? esc_html( 'স্কোর ' . mhp_bn( $play['correct'] ) . '/' . mhp_bn( count( $qs ) ) ) : ''; ?></span>
	</div>
</section>

<section class="mc-g">
	<ol class="mc-col mc-plain" data-mc-quiz data-total="<?php echo (int) count( $qs ); ?>" data-guest="<?php echo $guest ? 1 : 0; ?>" data-date="<?php echo esc_attr( $mhp_today ); ?>">
		<?php foreach ( $qs as $mhp_i => $mhp_q ) : ?>
			<?php
			$mhp_mine = array_key_exists( $mhp_i, $mhp_ans ) ? (int) $mhp_ans[ $mhp_i ] : -1;
			$mhp_full = ( $mhp_mine >= 0 && isset( $mhp_rev[ $mhp_q['id'] ] ) ) ? $mhp_rev[ $mhp_q['id'] ] : null;
			?>
			<li class="mc-card mc-q<?php echo $mhp_full ? ' is-done' : ''; ?>" data-i="<?php echo (int) $mhp_i; ?>">
				<div class="mc-row mc-between"><span class="mc-qnum">প্রশ্ন <?php echo esc_html( mhp_bn( $mhp_i + 1 ) . '/' . mhp_bn( count( $qs ) ) ); ?></span><span class="mc-pill mc-pill--line"><?php echo esc_html( $mhp_q['subject'] ); ?></span></div>
				<p class="mc-qtext"><?php echo esc_html( $mhp_q['text'] ); ?></p>
				<div class="mc-col mc-gap6">
					<?php foreach ( $mhp_q['opts'] as $mhp_k => $mhp_o ) : ?>
						<?php
						$mhp_cls = 'mc-opt';
						if ( $mhp_full ) {
							if ( $mhp_k === (int) $mhp_full['answer'] ) {
								$mhp_cls .= ' is-right';
							} elseif ( $mhp_k === $mhp_mine ) {
								$mhp_cls .= ' is-wrong';
							}
						}
						?>
						<form method="post" class="mc-inline" action="<?php echo esc_url( mhp_url( 'quiz' ) ); ?>">
							<?php echo MHP_Front::form_fields( 'quiz_answer' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<input type="hidden" name="i" value="<?php echo (int) $mhp_i; ?>">
							<button type="submit" name="choice" value="<?php echo (int) $mhp_k; ?>" class="<?php echo esc_attr( $mhp_cls ); ?>" data-choice="<?php echo (int) $mhp_k; ?>"<?php echo ( $mhp_full || $mhp_closed ) ? ' disabled' : ''; ?>>
								<span class="mc-omr"><?php echo esc_html( MHP_Tests::OPT_LABELS[ $mhp_k ] ); ?></span><span><?php echo esc_html( $mhp_o ); ?></span>
							</button>
						</form>
					<?php endforeach; ?>
				</div>
				<div class="mc-explain" data-mc-explain<?php echo ( $mhp_full && $mhp_full['explain'] ) ? '' : ' hidden'; ?>><?php echo $mhp_full ? esc_html( $mhp_full['explain'] ) : ''; ?></div>
			</li>
		<?php endforeach; ?>
	</ol>
</section>

<section class="mc-sec mc-g" data-mc-quiz-done<?php echo $mhp_done ? '' : ' hidden'; ?>>
	<div class="mc-card mc-pad mc-center mc-tint">
		<b>আজকের কুইজ শেষ!</b>
		<p class="mc-sm mc-muted" data-mc-quiz-final><?php echo $mhp_done ? esc_html( mhp_bn( $play['correct'] ) . '/' . mhp_bn( count( $qs ) ) . ' সঠিক, সময় ' . mhp_mmss( $play['seconds'] ) ) : ''; ?></p>
		<p class="mc-xs mc-muted">কাল নতুন প্রশ্ন আসবে।</p>
		<div class="mc-row">
			<button type="button" class="mc-btn mc-btn--soft mc-grow" data-mc-share data-text="<?php echo esc_attr( 'মিহা নিউজের আজকের কুইজ খেলুন' ); ?>" data-url="<?php echo esc_url( mhp_url( 'quiz' ) ); ?>"><?php echo mhp_icon( 'share' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> বন্ধুকে পাঠান</button>
			<a class="mc-btn mc-grow" href="<?php echo esc_url( mhp_url( 'prep' ) ); ?>">মডেল টেস্ট দিন</a>
		</div>
	</div>
</section>

<?php mhp_template( 'prize-box', array( 'compact' => false ) ); ?>

<section class="mc-sec mc-g">
	<div class="mc-sech"><h2>আজকের সেরা</h2><a class="mc-link mc-sm" href="<?php echo esc_url( mhp_url( 'leaderboard' ) ); ?>">পুরো তালিকা</a></div>
	<?php if ( count( $board ) >= min( 3, $mhp_lb_min ) ) : ?>
		<ol class="mc-lb mc-card mc-plain">
			<?php foreach ( $board as $mhp_n => $mhp_row ) : ?>
				<li class="mc-row<?php echo get_current_user_id() === $mhp_row['user_id'] ? ' is-me' : ''; ?>">
					<span class="mc-rank"><?php echo esc_html( mhp_bn( $mhp_n + 1 ) ); ?></span>
					<span class="mc-avatar" aria-hidden="true"><?php echo esc_html( mhp_initial( $mhp_row['name'] ) ); ?></span>
					<span class="mc-grow"><?php echo esc_html( $mhp_row['name'] ); ?><?php echo $mhp_row['district'] ? '<span class="mc-xs mc-muted mc-block">' . esc_html( $mhp_row['district'] ) . '</span>' : ''; ?></span>
					<b class="mc-num"><?php echo esc_html( mhp_bn( $mhp_row['correct'] ) . '/' . mhp_bn( $mhp_row['total'] ) ); ?></b>
					<span class="mc-xs mc-muted"><?php echo esc_html( mhp_mmss( $mhp_row['seconds'] ) ); ?></span>
				</li>
			<?php endforeach; ?>
		</ol>
	<?php else : ?>
		<div class="mc-card mc-pad mc-center mc-muted mc-sm">আজকের তালিকা এখনো তৈরি হয়নি। প্রথম হয়ে যান!</div>
	<?php endif; ?>
	<?php if ( $count >= (int) mhp_opt( 'count_min', 20 ) ) : ?>
		<p class="mc-xs mc-muted"><?php echo esc_html( 'আজ ' . mhp_num( $count ) . ' জন কুইজ শেষ করেছেন।' ); ?></p>
	<?php endif; ?>
</section>
<?php
if ( function_exists( 'meeha_campus_ad' ) ) {
	echo meeha_campus_ad( 'campus_banner', true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
