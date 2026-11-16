<?php
/**
 * Drawer (`<dialog>`, slides from the left): search, grouped section links, three-calendar date,
 * theme switch (স্বয়ংক্রিয় / আলো / অন্ধকার), CTA. Printed once, after the footer.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

$meeha_d = meeha_dates();
$meeha_h = meeha_opt( 'header' );
?>
<dialog class="m-drawer" id="m-drawer" aria-label="সব বিভাগ">
	<div class="m-drawer__head"><?php echo meeha_logo( 'compact' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><button class="m-iconbtn" type="button" data-close aria-label="মেনু বন্ধ করুন"><?php echo meeha_icon( 'close', array( 'size' => 'lg' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button></div>
	<div class="m-drawer__body">
		<form action="<?php echo esc_url( home_url( '/' ) ); ?>" method="get" role="search"><label class="m-vh" for="m-drawer-q">খুঁজুন</label><input class="m-input" id="m-drawer-q" type="search" name="s" placeholder="খবর, বিষয় বা লেখকের নাম" autocomplete="off" enterkeyhint="search"></form>
		<?php foreach ( meeha_drawer_groups() as $meeha_g ) : ?>
			<?php
			if ( empty( $meeha_g['links'] ) ) {
				continue;
			}
			?>
			<div class="m-drawer__group"><p class="m-drawer__title"><?php echo esc_html( $meeha_g['title'] ); ?></p><ul class="m-drawer__links" role="list">
			<?php foreach ( $meeha_g['links'] as $meeha_l ) : ?>
				<li><a href="<?php echo esc_url( $meeha_l['url'] ); ?>"<?php echo ! empty( $meeha_l['current'] ) && 'page' === $meeha_l['current'] ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $meeha_l['title'] ); ?></a></li>
			<?php endforeach; ?>
			</ul></div>
		<?php endforeach; ?>
		<div class="m-drawer__group"><p class="m-drawer__title">আজ</p><div class="m-drawer__date"><span><?php echo esc_html( $meeha_d['weekday'] . ', ' . $meeha_d['gregorian'] ); ?></span><?php if ( $meeha_h['date_bangla'] ) : ?><span><?php echo esc_html( $meeha_d['bangla'] ); ?> বঙ্গাব্দ</span><?php endif; ?><?php if ( $meeha_h['date_hijri'] ) : ?><span><?php echo esc_html( $meeha_d['hijri'] ); ?> হিজরি</span><?php endif; ?></div></div>
		<div class="m-drawer__group"><p class="m-drawer__title">পাঠের রং</p><div class="m-theme-switch" role="group" aria-label="রং নির্বাচন"><button type="button" data-theme-set="system" aria-pressed="true">স্বয়ংক্রিয়</button><button type="button" data-theme-set="light" aria-pressed="false">আলো</button><button type="button" data-theme-set="dark" aria-pressed="false">অন্ধকার</button></div></div>
		<?php if ( $meeha_h['cta_label'] && $meeha_h['cta_url'] ) : ?>
			<div class="m-drawer__group"><a class="m-btn m-btn--block" href="<?php echo esc_url( $meeha_h['cta_url'] ); ?>"><?php echo esc_html( $meeha_h['cta_label'] ); ?></a></div>
		<?php endif; ?>
	</div>
</dialog>
