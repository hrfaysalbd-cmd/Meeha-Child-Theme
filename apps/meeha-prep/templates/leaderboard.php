<?php
/**
 * Leaderboard. Vars: $range (today|week|month|points), $rows.
 *
 * @package meeha-prep
 */

defined( 'ABSPATH' ) || exit;

$mhp_min  = max( 1, (int) mhp_opt( 'rank_min', 5 ) );
$mhp_me   = get_current_user_id();
$mhp_pts  = 'points' === $range;
$mhp_segs = array(
	'today'  => 'আজ',
	'week'   => 'এই সপ্তাহ',
	'month'  => 'এই মাস',
	'points' => 'পয়েন্ট',
);
$mhp_ledes = array(
	'today'  => 'আজকের কুইজ: বেশি সঠিক উত্তর আগে; সমান হলে যিনি কম সময় নিয়েছেন।',
	'week'   => 'শনিবার থেকে আজ পর্যন্ত কুইজের মোট সঠিক উত্তর; সমান হলে কম সময়।',
	'month'  => 'এই মাসের কুইজের মোট সঠিক উত্তর; সমান হলে কম সময়।',
	'points' => 'শনিবার থেকে আজ পর্যন্ত কুইজ, টেস্ট, পাঠ আর ভুল শুধরানো মিলিয়ে পয়েন্ট।',
);
?>
<section class="mc-mast mc-g">
	<div class="mc-mast__eyebrow">দৈনিক কুইজ ও প্রস্তুতি</div>
	<h1 class="mc-mast__title">লিডারবোর্ড</h1>
	<p class="mc-mast__lede"><?php echo esc_html( $mhp_ledes[ $range ] ); ?></p>
</section>
<section class="mc-sec mc-g">
	<div class="mc-seg mc-mb">
		<?php foreach ( $mhp_segs as $mhp_k => $mhp_l ) : ?>
			<a class="<?php echo $range === $mhp_k ? 'is-on' : ''; ?>" href="<?php echo esc_url( 'today' === $mhp_k ? mhp_url( 'leaderboard' ) : mhp_url( 'leaderboard', '', array( 'range' => $mhp_k ) ) ); ?>"<?php echo $range === $mhp_k ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $mhp_l ); ?></a>
		<?php endforeach; ?>
	</div>
	<?php if ( count( $rows ) >= $mhp_min ) : ?>
		<ol class="mc-lb mc-card mc-plain">
			<?php foreach ( $rows as $mhp_n => $mhp_r ) : ?>
				<li class="mc-row<?php echo $mhp_me === $mhp_r['user_id'] ? ' is-me' : ''; ?>">
					<span class="mc-rank"><?php echo esc_html( mhp_bn( $mhp_n + 1 ) ); ?></span>
					<span class="mc-avatar" aria-hidden="true"><?php echo esc_html( mhp_initial( $mhp_r['name'] ) ); ?></span>
					<span class="mc-grow"><?php echo esc_html( $mhp_r['name'] ); ?>
						<span class="mc-xs mc-muted mc-block"><?php echo esc_html( trim( $mhp_r['district'] . ( ! $mhp_pts && 'today' !== $range ? ' · ' . mhp_bn( $mhp_r['days'] ) . ' দিন' : '' ), ' ·' ) ); ?></span>
					</span>
					<?php if ( $mhp_pts ) : ?>
						<b class="mc-num"><?php echo esc_html( mhp_num( $mhp_r['points'] ) ); ?></b>
						<span class="mc-xs mc-muted">পয়েন্ট</span>
					<?php else : ?>
						<b class="mc-num"><?php echo esc_html( mhp_bn( $mhp_r['correct'] ) ); ?></b>
						<span class="mc-xs mc-muted"><?php echo esc_html( mhp_mmss( $mhp_r['seconds'] ) ); ?></span>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ol>
		<p class="mc-xs mc-muted">নাম দেখানো হয় প্রোফাইলের নাম থেকে। "আমার পাতা › অ্যালার্ট"-এ জেলা যোগ করলে নামের নিচে দেখা যাবে।<?php echo $mhp_pts ? ' সম্পাদক ও লেখকেরা এই তালিকায় থাকেন না।' : ''; ?></p>
	<?php else : ?>
		<div class="mc-card mc-pad mc-center mc-muted">
			কমপক্ষে <?php echo esc_html( mhp_bn( $mhp_min ) ); ?> জন থাকলে তালিকা দেখা যাবে।
			<a class="mc-link mc-block mc-mt" href="<?php echo esc_url( mhp_url( 'quiz' ) ); ?>">আজকের কুইজ খেলুন</a>
		</div>
	<?php endif; ?>
</section>
<?php mhp_template( 'prize-box', array( 'compact' => false ) ); ?>
