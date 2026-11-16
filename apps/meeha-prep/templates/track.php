<?php
/**
 * Track page. Vars: $track, $tests, $products, $tab (tests|practice|syllabus), $pg, $takers.
 *
 * @package meeha-prep
 */

defined( 'ABSPATH' ) || exit;

$mhp_uid  = get_current_user_id();
$mhp_base = mhp_url( 'track', $track['slug'] );
$mhp_min  = max( 1, (int) mhp_opt( 'count_min', 20 ) );
?>
<section class="mc-g mc-detail">
	<div class="mc-row mc-top" style="--tc:<?php echo esc_attr( $track['color'] ); ?>">
		<span class="mc-track__ic mc-track__ic--lg" aria-hidden="true"><?php echo esc_html( $track['glyph'] ? $track['glyph'] : mhp_initial( $track['name'] ) ); ?></span>
		<div class="mc-grow">
			<h1 class="mc-h1"><?php echo esc_html( $track['name'] ); ?></h1>
			<div class="mc-sm mc-muted">
				<?php echo esc_html( $track['desc'] ); ?>
				<?php echo $takers >= $mhp_min ? esc_html( ' · ' . mhp_num( $takers ) . ' জন টেস্ট দিয়েছেন' ) : ''; ?>
			</div>
		</div>
	</div>
	<?php if ( $track['about'] ) : ?>
		<p class="mc-sm"><?php echo esc_html( $track['about'] ); ?></p>
	<?php endif; ?>
</section>

<nav class="mc-sec mc-g" aria-label="ট্র্যাকের অংশ">
	<div class="mc-seg">
		<a class="<?php echo 'tests' === $tab ? 'is-on' : ''; ?>" href="<?php echo esc_url( $mhp_base ); ?>" <?php echo 'tests' === $tab ? 'aria-current="page"' : ''; ?>>টেস্ট</a>
		<a class="<?php echo 'practice' === $tab ? 'is-on' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'tab', 'practice', $mhp_base ) ); ?>" <?php echo 'practice' === $tab ? 'aria-current="page"' : ''; ?>>অনুশীলন</a>
		<a class="<?php echo 'syllabus' === $tab ? 'is-on' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'tab', 'syllabus', $mhp_base ) ); ?>" <?php echo 'syllabus' === $tab ? 'aria-current="page"' : ''; ?>>সিলেবাস</a>
	</div>
</nav>

<?php if ( 'tests' === $tab ) : ?>
	<section class="mc-sec mc-g mc-tight">
		<?php
		// Past papers (প্রশ্নব্যাংক) get their own block, newest exam first; model tests and practice sets follow.
		$mhp_papers = array();
		$mhp_rest   = array();
		foreach ( (array) $tests as $mhp_t ) {
			$mhp_pp = mhp_test_paper( $mhp_t['id'] );
			if ( $mhp_pp ) {
				$mhp_papers[] = array( $mhp_pp['rank'], $mhp_t );
			} else {
				$mhp_rest[] = $mhp_t;
			}
		}
		usort(
			$mhp_papers,
			function ( $a, $b ) {
				return $a[0] - $b[0];
			}
		);
		?>
		<?php if ( $mhp_papers ) : ?>
			<div class="mc-sech"><h2>বিগত সালের প্রশ্ন</h2><span class="mc-xs mc-muted"><?php echo esc_html( mhp_num( count( $mhp_papers ) ) ); ?>টি পরীক্ষা · আসল সময় ও নেগেটিভ মার্কিং</span></div>
			<div class="mc-col">
				<?php
				foreach ( $mhp_papers as $mhp_pp ) {
					echo mhp_test_row( $mhp_pp[1], $mhp_uid ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				?>
			</div>
		<?php endif; ?>
		<?php if ( $mhp_rest ) : ?>
			<?php if ( $mhp_papers ) : ?>
				<div class="mc-sech mc-mt"><h2>মডেল টেস্ট ও অনুশীলন</h2></div>
			<?php endif; ?>
			<div class="mc-col">
				<?php
				foreach ( $mhp_rest as $mhp_t ) {
					echo mhp_test_row( $mhp_t, $mhp_uid ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				?>
			</div>
		<?php endif; ?>
		<?php if ( ! $tests ) : ?>
			<div class="mc-card mc-pad mc-center mc-muted">এই ট্র্যাকের টেস্ট শিগগির আসছে। ততক্ষণ <a class="mc-link" href="<?php echo esc_url( add_query_arg( 'tab', 'practice', $mhp_base ) ); ?>">অনুশীলনের প্রশ্ন</a> দেখুন।</div>
		<?php endif; ?>
	</section>
	<?php if ( $products ) : ?>
		<section class="mc-sec mc-g">
			<div class="mc-sech"><h2>প্যাক ও বই</h2></div>
			<div class="mc-col">
				<?php
				foreach ( $products as $mhp_p ) {
					echo mhp_product_card( $mhp_p, $mhp_uid ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				?>
			</div>
		</section>
	<?php endif; ?>
<?php elseif ( 'practice' === $tab ) : ?>
	<?php $mhp_pr = MHP_Front::practice_questions( $track['id'], $pg ); ?>
	<section class="mc-sec mc-g mc-tight">
		<p class="mc-sm mc-muted">উত্তর ভেবে নিয়ে "উত্তর দেখুন" চাপুন। মোট <?php echo esc_html( mhp_num( $mhp_pr['total'] ) ); ?>টি প্রশ্ন।</p>
		<?php if ( $mhp_pr['items'] ) : ?>
			<ol class="mc-col mc-plain" start="<?php echo (int) ( ( $pg - 1 ) * 15 + 1 ); ?>">
				<?php foreach ( $mhp_pr['items'] as $mhp_n => $mhp_q ) : ?>
					<li class="mc-card mc-q">
						<div class="mc-row mc-between"><span class="mc-qnum">প্রশ্ন <?php echo esc_html( mhp_bn( ( $pg - 1 ) * 15 + $mhp_n + 1 ) ); ?></span><span class="mc-pill mc-pill--line"><?php echo esc_html( $mhp_q['subject'] ); ?></span></div>
						<p class="mc-qtext"><?php echo esc_html( $mhp_q['text'] ); ?></p>
						<ul class="mc-plain">
							<?php foreach ( $mhp_q['opts'] as $mhp_k => $mhp_o ) : ?>
								<li class="mc-opt mc-opt--static"><span class="mc-omr"><?php echo esc_html( MHP_Tests::OPT_LABELS[ $mhp_k ] ); ?></span><span><?php echo esc_html( $mhp_o ); ?></span></li>
							<?php endforeach; ?>
						</ul>
						<details class="mc-reveal">
							<summary>উত্তর দেখুন</summary>
							<div class="mc-explain"><b>সঠিক উত্তর: <?php echo esc_html( MHP_Tests::OPT_LABELS[ $mhp_q['answer'] ] . ') ' . ( isset( $mhp_q['opts'][ $mhp_q['answer'] ] ) ? $mhp_q['opts'][ $mhp_q['answer'] ] : '' ) ); ?></b>
								<?php echo $mhp_q['explain'] ? '<br>' . esc_html( $mhp_q['explain'] ) : ''; ?>
								<?php echo $mhp_q['source'] ? '<br><span class="mc-xs mc-muted">উৎস: ' . esc_html( $mhp_q['source'] ) . '</span>' : ''; ?>
							</div>
						</details>
					</li>
				<?php endforeach; ?>
			</ol>
			<?php if ( $mhp_pr['pages'] > 1 ) : ?>
				<nav class="mc-pager" aria-label="পাতা">
					<?php for ( $mhp_p = 1; $mhp_p <= min( 30, $mhp_pr['pages'] ); $mhp_p++ ) : ?>
						<a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'practice', 'pg' => $mhp_p ), $mhp_base ) ); ?>" <?php echo $mhp_p === $pg ? 'class="is-on" aria-current="page"' : ''; ?>><?php echo esc_html( mhp_bn( $mhp_p ) ); ?></a>
					<?php endfor; ?>
				</nav>
			<?php endif; ?>
		<?php else : ?>
			<div class="mc-card mc-pad mc-center mc-muted">এই ট্র্যাকে অনুশীলনের প্রশ্ন এখনো যোগ হয়নি।</div>
		<?php endif; ?>
	</section>
<?php else : ?>
	<section class="mc-sec mc-g mc-tight">
		<?php if ( $track['syllabus'] ) : ?>
			<?php
			$mhp_max = 0;
			$mhp_sum = 0;
			foreach ( $track['syllabus'] as $mhp_r ) {
				$mhp_max  = max( $mhp_max, $mhp_r[1] );
				$mhp_sum += $mhp_r[1];
			}
			?>
			<div class="mc-sech"><h2>নম্বর বণ্টন</h2><span class="mc-xs mc-muted">মোট <?php echo esc_html( mhp_score( $mhp_sum ) ); ?></span></div>
			<div class="mc-card mc-pad mc-col">
				<?php foreach ( $track['syllabus'] as $mhp_r ) : ?>
					<div class="mc-subj mc-subj--wide">
						<span class="mc-sm"><?php echo esc_html( $mhp_r[0] ); ?></span>
						<b class="mc-num"><?php echo esc_html( mhp_score( $mhp_r[1] ) ); ?></b>
						<div class="mc-bar"><i style="width:<?php echo esc_attr( $mhp_max ? round( $mhp_r[1] / $mhp_max * 100 ) : 0 ); ?>%"></i></div>
					</div>
				<?php endforeach; ?>
			</div>
			<p class="mc-xs mc-muted"><?php echo esc_html( $track['syl_note'] ? $track['syl_note'] : 'প্রচলিত কাঠামো অনুযায়ী। প্রতিটি সার্কুলারের সিলেবাস মিলিয়ে নিন।' ); ?></p>
		<?php else : ?>
			<div class="mc-card mc-pad mc-center mc-muted">এই ট্র্যাকের সিলেবাস সম্পাদক শিগগির যোগ করবেন।</div>
		<?php endif; ?>
	</section>
<?php endif; ?>
<?php
if ( function_exists( 'meeha_campus_ad' ) ) {
	echo meeha_campus_ad( 'campus_banner', true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
