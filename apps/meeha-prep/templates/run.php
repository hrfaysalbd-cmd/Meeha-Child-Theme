<?php
/**
 * Exam runner. Vars: $a (attempt), $info (test info|null), $qs (questions without answers), $left (seconds).
 * Works without JavaScript: the whole sheet is one form; prep.js adds the timer, palette, autosave and auto-submit.
 *
 * @package meeha-prep
 */

defined( 'ABSPATH' ) || exit;

$mhp_by = array();
foreach ( $qs as $mhp_q ) {
	$mhp_by[ $mhp_q['id'] ] = $mhp_q;
}
$mhp_n     = count( $a['qids'] );
$mhp_title = $info ? $info['title'] : 'পরীক্ষা';
$mhp_neg   = $info ? (float) $info['negative'] : 0;
?>
<form method="post" class="mc-run" id="mc-run" action="<?php echo esc_url( mhp_url( 'run', $a['id'] ) ); ?>"
	data-attempt="<?php echo (int) $a['id']; ?>" data-left="<?php echo (int) $left; ?>" data-total="<?php echo (int) $mhp_n; ?>">
	<?php echo MHP_Front::form_fields( 'submit_test' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	<input type="hidden" name="attempt" value="<?php echo (int) $a['id']; ?>">

	<div class="mc-bar-top mc-g">
		<div class="mc-row mc-between">
			<div class="mc-grow">
				<div class="mc-xs mc-muted"><?php echo esc_html( $mhp_title ); ?></div>
				<div class="mc-sm"><b data-mc-answered><?php echo esc_html( mhp_bn( count( $a['answers'] ) ) ); ?></b>/<?php echo esc_html( mhp_bn( $mhp_n ) ); ?> উত্তর দেওয়া</div>
			</div>
			<div class="mc-timer" role="timer" aria-live="off" data-mc-timer><?php echo esc_html( mhp_mmss( $left ) ); ?></div>
		</div>
		<div class="mc-progress" aria-hidden="true"><i data-mc-progress style="width:<?php echo esc_attr( $mhp_n ? round( count( $a['answers'] ) / $mhp_n * 100 ) : 0 ); ?>%"></i></div>
	</div>

	<noscript><div class="mc-note mc-g">জাভাস্ক্রিপ্ট বন্ধ থাকায় টাইমার দেখা যাচ্ছে না। সময় <?php echo esc_html( mhp_mmss( $left ) ); ?> মিনিটের মধ্যে নিচের "জমা দিন" চাপুন।</div></noscript>

	<ol class="mc-col mc-plain mc-g mc-sec">
		<?php foreach ( $a['qids'] as $mhp_i => $mhp_qid ) : ?>
			<?php
			if ( ! isset( $mhp_by[ $mhp_qid ] ) ) {
				continue;
			}
			$mhp_q   = $mhp_by[ $mhp_qid ];
			$mhp_sel = array_key_exists( $mhp_i, $a['answers'] ) ? (int) $a['answers'][ $mhp_i ] : -1;
			?>
			<li class="mc-card mc-q" id="mc-q-<?php echo (int) $mhp_i; ?>">
				<fieldset>
					<legend class="mc-row mc-between mc-block">
						<span class="mc-qnum">প্রশ্ন <?php echo esc_html( mhp_bn( $mhp_i + 1 ) ); ?></span>
						<span class="mc-pill mc-pill--line"><?php echo esc_html( $mhp_q['subject'] ); ?></span>
					</legend>
					<p class="mc-qtext"><?php echo esc_html( $mhp_q['text'] ); ?></p>
					<?php foreach ( $mhp_q['opts'] as $mhp_k => $mhp_o ) : ?>
						<label class="mc-opt<?php echo $mhp_sel === $mhp_k ? ' is-sel' : ''; ?>">
							<input type="radio" name="ans[<?php echo (int) $mhp_i; ?>]" value="<?php echo (int) $mhp_k; ?>" <?php checked( $mhp_sel, $mhp_k ); ?>>
							<span class="mc-omr"><?php echo esc_html( MHP_Tests::OPT_LABELS[ $mhp_k ] ); ?></span>
							<span><?php echo esc_html( $mhp_o ); ?></span>
						</label>
					<?php endforeach; ?>
					<button type="button" class="mc-btn mc-btn--ghost mc-btn--sm" data-mc-clear="<?php echo (int) $mhp_i; ?>"<?php echo $mhp_sel < 0 ? ' hidden' : ''; ?>>উত্তর মুছুন</button>
				</fieldset>
			</li>
		<?php endforeach; ?>
	</ol>

	<div class="mc-g mc-sec">
		<details class="mc-card mc-pad">
			<summary class="mc-sm"><b>প্রশ্নের তালিকা</b> <span class="mc-muted">(কোনটি বাকি দেখুন)</span></summary>
			<div class="mc-palette mc-mt" data-mc-palette>
				<?php foreach ( $a['qids'] as $mhp_i => $mhp_qid ) : ?>
					<a href="#mc-q-<?php echo (int) $mhp_i; ?>" class="<?php echo array_key_exists( $mhp_i, $a['answers'] ) ? 'is-on' : ''; ?>" data-i="<?php echo (int) $mhp_i; ?>"><?php echo esc_html( mhp_bn( $mhp_i + 1 ) ); ?></a>
				<?php endforeach; ?>
			</div>
		</details>
		<?php if ( $mhp_neg > 0 ) : ?>
			<p class="mc-xs mc-muted">প্রতিটি ভুলে <?php echo esc_html( mhp_score( $mhp_neg ) ); ?> নম্বর কাটা যাবে। নিশ্চিত না হলে ফাঁকা রাখতে পারেন।</p>
		<?php endif; ?>
		<p class="mc-xs mc-muted" data-mc-save-state aria-live="polite"></p>
	</div>

	<div class="mc-sticky mc-g">
		<button type="submit" class="mc-btn mc-btn--block" data-mc-submit>জমা দিন</button>
	</div>
</form>
