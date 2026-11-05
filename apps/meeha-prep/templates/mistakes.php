<?php
/**
 * Mistake notebook. Vars: $res (items, total), $qs (questions without answers, same order), $subj, $pg, $skills,
 * $last (the reader's last wrong retry: q, c, answer, explain | null), $fixed.
 *
 * @package meeha-prep
 */

defined( 'ABSPATH' ) || exit;

$mhp_rows  = array();
foreach ( $res['items'] as $mhp_r ) {
	$mhp_rows[ (int) $mhp_r['question_id'] ] = $mhp_r;
}
$mhp_pages = (int) ceil( $res['total'] / MHP_Learn::PER_PAGE );
?>
<section class="mc-mast mc-g">
	<div class="mc-mast__eyebrow">আমার প্রস্তুতি</div>
	<h1 class="mc-mast__title">ভুলের খাতা</h1>
	<p class="mc-mast__lede">টেস্ট আর কুইজে যেগুলো ভুল হয়েছিল, সেগুলো আবার করুন। সঠিক হলে খাতা থেকে সরে যাবে, প্রতিটিতে ১ পয়েন্ট।</p>
</section>

<?php if ( $skills ) : ?>
	<nav class="mc-g mc-chips-wrap" aria-label="বিষয়">
		<div class="mc-chips">
			<a class="mc-chip<?php echo $subj ? '' : ' is-on'; ?>" href="<?php echo esc_url( mhp_url( 'mistakes' ) ); ?>">সব বিষয়</a>
			<?php foreach ( $skills as $mhp_s ) : ?>
				<a class="mc-chip<?php echo $subj === $mhp_s['id'] ? ' is-on' : ''; ?>" href="<?php echo esc_url( mhp_url( 'mistakes', '', array( 'subj' => $mhp_s['id'] ) ) ); ?>"><?php echo esc_html( $mhp_s['name'] ); ?></a>
			<?php endforeach; ?>
		</div>
	</nav>
<?php endif; ?>

<section class="mc-sec mc-g">
	<p class="mc-sm mc-muted"><?php echo esc_html( 'খাতায় ' . mhp_bn( $res['total'] ) . 'টি প্রশ্ন' . ( $fixed ? ' · এ পর্যন্ত শুধরেছেন ' . mhp_bn( $fixed ) . 'টি' : '' ) ); ?></p>
	<?php if ( ! $qs ) : ?>
		<div class="mc-card mc-pad mc-center">
			<p><?php echo $res['total'] || $subj ? 'এই বিষয়ে খাতায় কোনো প্রশ্ন নেই।' : 'খাতা খালি। টেস্ট বা কুইজে কোনো উত্তর ভুল হলে তা এখানে জমা হবে।'; ?></p>
			<div class="mc-row mc-wrap" style="justify-content:center">
				<a class="mc-btn mc-btn--soft" href="<?php echo esc_url( mhp_url( 'prep' ) ); ?>">মডেল টেস্ট দিন</a>
				<a class="mc-btn mc-btn--ghost" href="<?php echo esc_url( mhp_url( 'quiz' ) ); ?>">আজকের কুইজ</a>
			</div>
		</div>
	<?php else : ?>
		<ol class="mc-col mc-plain">
			<?php foreach ( $qs as $mhp_q ) : ?>
				<?php
				$mhp_row  = isset( $mhp_rows[ $mhp_q['id'] ] ) ? $mhp_rows[ $mhp_q['id'] ] : array( 'wrong_count' => 1 );
				$mhp_shot = ( $last && (int) $last['q'] === $mhp_q['id'] ) ? $last : null;
				?>
				<li class="mc-card mc-q" id="q-<?php echo (int) $mhp_q['id']; ?>">
					<div class="mc-row mc-between">
						<span class="mc-pill mc-pill--line"><?php echo esc_html( $mhp_q['subject'] ); ?></span>
						<span class="mc-xs mc-muted"><?php echo esc_html( mhp_bn( $mhp_row['wrong_count'] ) ); ?> বার ভুল</span>
					</div>
					<p class="mc-qtext"><?php echo esc_html( $mhp_q['text'] ); ?></p>
					<?php if ( $mhp_shot ) : ?>
						<p class="mc-flash mc-flash--bad" role="status">এবারও হয়নি। সঠিক উত্তর সবুজে দেখানো হলো; ব্যাখ্যা পড়ে পরে আবার চেষ্টা করুন।</p>
					<?php endif; ?>
					<div class="mc-col mc-gap6">
						<?php foreach ( $mhp_q['opts'] as $mhp_k => $mhp_o ) : ?>
							<?php
							$mhp_cls = 'mc-opt';
							if ( $mhp_shot ) {
								$mhp_cls .= $mhp_k === (int) $mhp_shot['answer'] ? ' is-right' : ( $mhp_k === (int) $mhp_shot['c'] ? ' is-wrong' : '' );
							}
							?>
							<form method="post" class="mc-inline" action="<?php echo esc_url( mhp_url( 'mistakes' ) ); ?>">
								<?php echo MHP_Front::form_fields( 'mistake_try' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<input type="hidden" name="q" value="<?php echo (int) $mhp_q['id']; ?>">
								<input type="hidden" name="subj" value="<?php echo (int) $subj; ?>">
								<input type="hidden" name="pg" value="<?php echo (int) $pg; ?>">
								<button type="submit" name="choice" value="<?php echo (int) $mhp_k; ?>" class="<?php echo esc_attr( $mhp_cls ); ?>"<?php echo $mhp_shot ? ' disabled' : ''; ?>>
									<span class="mc-omr"><?php echo esc_html( MHP_Tests::OPT_LABELS[ $mhp_k ] ); ?></span><span><?php echo esc_html( $mhp_o ); ?></span>
								</button>
							</form>
						<?php endforeach; ?>
					</div>
					<?php if ( $mhp_shot && $mhp_shot['explain'] ) : ?>
						<div class="mc-explain"><?php echo esc_html( $mhp_shot['explain'] ); ?></div>
					<?php endif; ?>
					<form method="post" class="mc-mt" action="<?php echo esc_url( mhp_url( 'mistakes' ) ); ?>">
						<?php echo MHP_Front::form_fields( 'mistake_drop' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<input type="hidden" name="q" value="<?php echo (int) $mhp_q['id']; ?>">
						<input type="hidden" name="subj" value="<?php echo (int) $subj; ?>">
						<input type="hidden" name="pg" value="<?php echo (int) $pg; ?>">
						<button type="submit" class="mc-link mc-btn-link">খাতা থেকে বাদ দিন</button>
					</form>
				</li>
			<?php endforeach; ?>
		</ol>
		<?php if ( $mhp_pages > 1 ) : ?>
			<nav class="mc-pager mc-row mc-between mc-mt" aria-label="পাতা">
				<?php if ( $pg > 1 ) : ?>
					<a class="mc-btn mc-btn--ghost mc-btn--sm" href="<?php echo esc_url( mhp_url( 'mistakes', '', array_filter( array( 'subj' => $subj, 'pg' => $pg - 1 ) ) ) ); ?>">আগের পাতা</a>
				<?php else : ?>
					<span></span>
				<?php endif; ?>
				<span class="mc-xs mc-muted"><?php echo esc_html( mhp_bn( $pg ) . '/' . mhp_bn( $mhp_pages ) ); ?></span>
				<?php if ( $pg < $mhp_pages ) : ?>
					<a class="mc-btn mc-btn--ghost mc-btn--sm" href="<?php echo esc_url( mhp_url( 'mistakes', '', array_filter( array( 'subj' => $subj, 'pg' => $pg + 1 ) ) ) ); ?>">পরের পাতা</a>
				<?php else : ?>
					<span></span>
				<?php endif; ?>
			</nav>
		<?php endif; ?>
	<?php endif; ?>
</section>
