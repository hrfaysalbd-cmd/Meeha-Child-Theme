<?php
/**
 * Quiz prize box (only when prizes are on, or winners were announced). Vars: $compact (bool).
 *
 * @package meeha-prep
 */

defined( 'ABSPATH' ) || exit;

$mhp_on     = MHP_Prizes::on();
$mhp_latest = MHP_Prizes::latest();
if ( ! $mhp_on && ! $mhp_latest ) {
	return;
}
$mhp_p     = MHP_Prizes::period( mhp_today() );
$mhp_names = array(
	'day'   => 'আজকের',
	'week'  => 'এই সপ্তাহের',
	'month' => 'এই মাসের',
);
$mhp_text  = trim( (string) mhp_opt( 'prize_text', '' ) );
$mhp_rules = trim( (string) mhp_opt( 'prize_rules', '' ) );
$mhp_min   = MHP_Prizes::min_days( $mhp_p );
?>
<section class="mc-sec mc-g">
	<div class="mc-card mc-pad mc-prize">
		<?php if ( $mhp_on ) : ?>
			<div class="mc-row">
				<span class="mc-mono mc-mono--gold" aria-hidden="true"><?php echo mhp_icon( 'trophy' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<div class="mc-grow">
					<b class="mc-block"><?php echo esc_html( $mhp_names[ $mhp_p['kind'] ] ); ?> কুইজ পুরস্কার</b>
					<span class="mc-xs mc-muted"><?php echo esc_html( $mhp_p['label'] . ' · সেরা ' . mhp_bn( max( 1, (int) mhp_opt( 'prize_winners', 3 ) ) ) . ' জন' . ( 'day' !== $mhp_p['kind'] ? ' · অন্তত ' . mhp_bn( $mhp_min ) . ' দিন খেলতে হবে' : '' ) ); ?></span>
				</div>
			</div>
			<?php if ( $mhp_text ) : ?>
				<p class="mc-sm mc-prize__text"><?php echo nl2br( esc_html( $mhp_text ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
			<?php endif; ?>
			<p class="mc-xs mc-muted">অংশ নেওয়া ফ্রি। সবচেয়ে বেশি সঠিক উত্তর আগে; সমান হলে কম সময়, তারপর বেশি দিন খেলা।</p>
			<?php if ( $mhp_rules ) : ?>
				<details class="mc-reveal">
					<summary>নিয়ম ও শর্ত</summary>
					<div class="mc-xs"><?php echo nl2br( esc_html( $mhp_rules ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
				</details>
			<?php endif; ?>
		<?php endif; ?>
		<?php if ( $mhp_latest && empty( $compact ) ) : ?>
			<div class="mc-prize__win<?php echo $mhp_on ? ' mc-mt' : ''; ?>">
				<b class="mc-sm">বিজয়ী: <?php echo esc_html( $mhp_latest['label'] ); ?></b>
				<ol class="mc-plain mc-col mc-gap6 mc-mt">
					<?php foreach ( $mhp_latest['winners'] as $mhp_w ) : ?>
						<li class="mc-row mc-sm"><span class="mc-rank"><?php echo esc_html( mhp_bn( $mhp_w['rank'] ) ); ?></span><span class="mc-grow"><?php echo esc_html( $mhp_w['name'] ); ?><?php echo $mhp_w['district'] ? '<span class="mc-xs mc-muted"> · ' . esc_html( $mhp_w['district'] ) . '</span>' : ''; ?></span><span class="mc-xs mc-muted"><?php echo esc_html( mhp_bn( $mhp_w['correct'] ) . ' সঠিক' ); ?></span></li>
					<?php endforeach; ?>
				</ol>
			</div>
		<?php endif; ?>
	</div>
</section>
