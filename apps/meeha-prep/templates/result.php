<?php
/**
 * Result + answer sheet. Vars: $a (graded attempt), $info (test info|null), $qs (with answers), $rank, $packs.
 *
 * @package meeha-prep
 */

defined( 'ABSPATH' ) || exit;

$mhp_by = array();
foreach ( $qs as $mhp_q ) {
	$mhp_by[ $mhp_q['id'] ] = $mhp_q;
}
$mhp_total = max( 1, (int) $a['total'] );
$mhp_pct   = max( 0, min( 100, round( (float) $a['score'] / $mhp_total * 100 ) ) );
$mhp_neg   = $info ? (float) $info['negative'] : 0;
$mhp_min   = max( 1, (int) mhp_opt( 'rank_min', 5 ) );

// Per-subject tally.
$mhp_subj = array();
foreach ( $a['qids'] as $mhp_i => $mhp_qid ) {
	if ( ! isset( $mhp_by[ $mhp_qid ] ) ) {
		continue;
	}
	$mhp_s = $mhp_by[ $mhp_qid ]['subject'];
	if ( ! isset( $mhp_subj[ $mhp_s ] ) ) {
		$mhp_subj[ $mhp_s ] = array( 0, 0 );
	}
	++$mhp_subj[ $mhp_s ][1];
	if ( array_key_exists( $mhp_i, $a['answers'] ) && (int) $a['answers'][ $mhp_i ] === (int) $mhp_by[ $mhp_qid ]['answer'] ) {
		++$mhp_subj[ $mhp_s ][0];
	}
}
$mhp_filter = isset( $_GET['show'] ) ? sanitize_key( wp_unslash( $_GET['show'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$mhp_base   = mhp_url( 'result', $a['id'] );
?>
<section class="mc-g mc-detail">
	<div class="mc-xs mc-muted"><?php echo esc_html( $info ? $info['title'] : 'টেস্ট' ); ?></div>
	<h1 class="mc-h1">আপনার ফল</h1>

	<div class="mc-card mc-pad mc-row mc-mt">
		<div class="mc-ring" style="--p:<?php echo (int) $mhp_pct; ?>" role="img" aria-label="<?php echo esc_attr( mhp_bn( $mhp_pct ) . ' শতাংশ' ); ?>">
			<b><?php echo esc_html( mhp_score( $a['score'] ) ); ?></b><span class="mc-xs">/<?php echo esc_html( mhp_bn( $a['total'] ) ); ?></span>
		</div>
		<div class="mc-grow mc-sm">
			<div>সময় লেগেছে <b><?php echo esc_html( mhp_mmss( $a['seconds'] ) ); ?></b></div>
			<?php if ( $rank['rank'] && $rank['of'] >= $mhp_min ) : ?>
				<div>র‍্যাংক <b><?php echo esc_html( mhp_bn( $rank['rank'] ) ); ?></b> <span class="mc-muted">(<?php echo esc_html( mhp_num( $rank['of'] ) ); ?> জনের মধ্যে)</span></div>
			<?php elseif ( $rank['rank'] ) : ?>
				<div class="mc-muted mc-xs">আরও কয়েকজন পরীক্ষা দিলে র‍্যাংক দেখা যাবে।</div>
			<?php else : ?>
				<div class="mc-muted mc-xs">এটি অনুশীলন; র‍্যাংকে গোনা হয় প্রথমবারের ফল।</div>
			<?php endif; ?>
			<?php if ( $mhp_neg > 0 && $a['wrong'] ) : ?>
				<div class="mc-xs mc-muted">ভুলের জন্য কাটা গেছে <?php echo esc_html( mhp_score( $a['wrong'] * $mhp_neg ) ); ?></div>
			<?php endif; ?>
		</div>
	</div>

	<div class="mc-stat3 mc-mt">
		<div class="mc-card mc-pad mc-center"><b class="mc-num mc-ok"><?php echo esc_html( mhp_bn( $a['correct'] ) ); ?></b><span class="mc-xs mc-muted mc-block">সঠিক</span></div>
		<div class="mc-card mc-pad mc-center"><b class="mc-num mc-bad"><?php echo esc_html( mhp_bn( $a['wrong'] ) ); ?></b><span class="mc-xs mc-muted mc-block">ভুল</span></div>
		<div class="mc-card mc-pad mc-center"><b class="mc-num"><?php echo esc_html( mhp_bn( $a['skipped'] ) ); ?></b><span class="mc-xs mc-muted mc-block">ফাঁকা</span></div>
	</div>

	<?php if ( count( $mhp_subj ) > 1 ) : ?>
		<h2 class="mc-h3">বিষয়ভিত্তিক</h2>
		<div class="mc-card mc-pad mc-col">
			<?php foreach ( $mhp_subj as $mhp_name => $mhp_v ) : ?>
				<div class="mc-subj">
					<span class="mc-sm"><?php echo esc_html( $mhp_name ); ?></span>
					<b class="mc-num"><?php echo esc_html( mhp_bn( $mhp_v[0] ) . '/' . mhp_bn( $mhp_v[1] ) ); ?></b>
					<div class="mc-bar"><i style="width:<?php echo esc_attr( $mhp_v[1] ? round( $mhp_v[0] / $mhp_v[1] * 100 ) : 0 ); ?>%"></i></div>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<div class="mc-row mc-mt">
		<?php if ( $info ) : ?>
			<a class="mc-btn mc-btn--ghost mc-grow" href="<?php echo esc_url( mhp_url( 'test', $info['id'] ) ); ?>">আবার দিন</a>
		<?php endif; ?>
		<button type="button" class="mc-btn mc-btn--soft mc-grow" data-mc-share data-text="<?php echo esc_attr( 'মিহা নিউজের মডেল টেস্টে পেলাম ' . mhp_score( $a['score'] ) . '/' . mhp_bn( $a['total'] ) ); ?>" data-url="<?php echo esc_url( $info ? mhp_url( 'test', $info['id'] ) : mhp_url( 'prep' ) ); ?>"><?php echo mhp_icon( 'share' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> শেয়ার</button>
	</div>
</section>

<?php if ( ! empty( $hold ) ) : ?>
	<section class="mc-sec mc-g"><div class="mc-card mc-pad mc-center mc-muted mc-sm">লাইভ পরীক্ষাটি এখনো চলছে। উত্তরপত্র দেখা যাবে <?php echo esc_html( mhp_fdatetime( $hold ) ); ?> এর পরে।</div></section>
<?php else : ?>
<section class="mc-sec mc-g">
	<div class="mc-sech"><h2>উত্তরপত্র</h2></div>
	<div class="mc-seg mc-mb">
		<?php foreach ( array( 'all' => 'সব', 'wrong' => 'ভুল', 'skipped' => 'ফাঁকা' ) as $mhp_k => $mhp_l ) : ?>
			<a class="<?php echo $mhp_filter === $mhp_k ? 'is-on' : ''; ?>" href="<?php echo esc_url( 'all' === $mhp_k ? $mhp_base : add_query_arg( 'show', $mhp_k, $mhp_base ) ); ?>"><?php echo esc_html( $mhp_l ); ?></a>
		<?php endforeach; ?>
	</div>
	<ol class="mc-col mc-plain">
		<?php
		$mhp_shown = 0;
		foreach ( $a['qids'] as $mhp_i => $mhp_qid ) :
			if ( ! isset( $mhp_by[ $mhp_qid ] ) ) {
				continue;
			}
			$mhp_q     = $mhp_by[ $mhp_qid ];
			$mhp_mine  = array_key_exists( $mhp_i, $a['answers'] ) ? (int) $a['answers'][ $mhp_i ] : -1;
			$mhp_right = (int) $mhp_q['answer'];
			if ( ( 'wrong' === $mhp_filter && ( $mhp_mine < 0 || $mhp_mine === $mhp_right ) ) || ( 'skipped' === $mhp_filter && $mhp_mine >= 0 ) ) {
				continue;
			}
			++$mhp_shown;
			?>
			<li class="mc-card mc-q">
				<div class="mc-row mc-between">
					<span class="mc-qnum">প্রশ্ন <?php echo esc_html( mhp_bn( $mhp_i + 1 ) ); ?></span>
					<?php if ( $mhp_mine < 0 ) : ?>
						<span class="mc-pill mc-pill--line">ফাঁকা</span>
					<?php elseif ( $mhp_mine === $mhp_right ) : ?>
						<span class="mc-pill mc-pill--ok">সঠিক</span>
					<?php else : ?>
						<span class="mc-pill mc-pill--bad">ভুল</span>
					<?php endif; ?>
				</div>
				<p class="mc-qtext"><?php echo esc_html( $mhp_q['text'] ); ?></p>
				<ul class="mc-plain">
					<?php foreach ( $mhp_q['opts'] as $mhp_k => $mhp_o ) : ?>
						<?php
						$mhp_cls = 'mc-opt mc-opt--static';
						if ( $mhp_k === $mhp_right ) {
							$mhp_cls .= ' is-right';
						} elseif ( $mhp_k === $mhp_mine ) {
							$mhp_cls .= ' is-wrong';
						}
						?>
						<li class="<?php echo esc_attr( $mhp_cls ); ?>"><span class="mc-omr"><?php echo esc_html( MHP_Tests::OPT_LABELS[ $mhp_k ] ); ?></span><span><?php echo esc_html( $mhp_o ); ?></span><?php echo $mhp_k === $mhp_mine ? '<span class="mc-xs mc-muted"> (আপনার উত্তর)</span>' : ''; ?></li>
					<?php endforeach; ?>
				</ul>
				<?php if ( $mhp_q['explain'] || $mhp_q['source'] ) : ?>
					<div class="mc-explain">
						<?php echo esc_html( $mhp_q['explain'] ); ?>
						<?php echo $mhp_q['source'] ? '<span class="mc-xs mc-muted mc-block">উৎস: ' . esc_html( $mhp_q['source'] ) . '</span>' : ''; ?>
					</div>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ol>
	<?php if ( ! $mhp_shown ) : ?>
		<div class="mc-card mc-pad mc-center mc-muted">এই তালিকায় কোনো প্রশ্ন নেই।</div>
	<?php endif; ?>
</section>
<?php endif; ?>
<?php if ( $packs ) : ?>
	<section class="mc-sec mc-g">
		<div class="mc-sech"><h2>আরও প্রস্তুতি</h2></div>
		<div class="mc-col">
			<?php
			foreach ( $packs as $mhp_p ) {
				echo mhp_product_card( $mhp_p, get_current_user_id() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			?>
		</div>
	</section>
<?php endif; ?>
