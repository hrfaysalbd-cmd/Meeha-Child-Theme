<?php
/**
 * Events list. Vars: $events.
 *
 * @package meeha-prep
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="mc-mast mc-g">
	<div class="mc-mast__eyebrow">শিক্ষা ও ক্যাম্পাস</div>
	<h1 class="mc-mast__title">ইভেন্ট ও কর্মশালা</h1>
	<p class="mc-mast__lede">ক্যারিয়ার সেমিনার, প্রস্তুতি কর্মশালা ও লাইভ সেশন</p>
</section>
<section class="mc-sec mc-g">
	<?php if ( $events ) : ?>
		<div class="mc-col mc-grid2">
			<?php foreach ( $events as $mhp_e ) : ?>
				<?php $mhp_full = $mhp_e['capacity'] && $mhp_e['taken'] >= $mhp_e['capacity']; ?>
				<a class="mc-card mc-event" href="<?php echo esc_url( mhp_url( 'event', $mhp_e['id'] ) ); ?>">
					<span class="mc-event__date" aria-hidden="true">
						<b><?php echo esc_html( mhp_bn( (int) substr( $mhp_e['date'], 8, 2 ) ) ); ?></b>
						<span><?php echo esc_html( mhp_months()[ (int) substr( $mhp_e['date'], 5, 2 ) ] ); ?></span>
					</span>
					<span class="mc-grow">
						<b class="mc-block"><?php echo esc_html( $mhp_e['title'] ); ?></b>
						<span class="mc-xs mc-muted mc-block"><?php echo esc_html( trim( $mhp_e['time'] . ' · ' . ( $mhp_e['venue'] ? $mhp_e['venue'] : ( $mhp_e['online'] ? 'অনলাইন' : '' ) ), ' ·' ) ); ?></span>
						<span class="mc-row mc-gap6 mc-mt">
							<?php if ( $mhp_e['price'] > 0 ) : ?>
								<span class="mc-pill mc-pill--brand"><?php echo esc_html( mhp_tk( $mhp_e['price'] ) ); ?></span>
							<?php else : ?>
								<span class="mc-pill mc-pill--ok">ফ্রি</span>
							<?php endif; ?>
							<?php if ( $mhp_full ) : ?>
								<span class="mc-pill mc-pill--line">আসন পূর্ণ</span>
							<?php elseif ( ! $mhp_e['reg_open'] ) : ?>
								<span class="mc-pill mc-pill--line">নিবন্ধন বন্ধ</span>
							<?php endif; ?>
						</span>
					</span>
				</a>
			<?php endforeach; ?>
		</div>
	<?php else : ?>
		<div class="mc-card mc-pad mc-center mc-muted">এই মুহূর্তে কোনো ইভেন্ট নির্ধারিত নেই। নতুন ইভেন্টের খবর পেতে "আমার পাতা › অ্যালার্ট" চালু রাখুন।</div>
	<?php endif; ?>
</section>
