<?php
/**
 * Section nav: pills on mobile (horizontal scroll), text rail on desktop (sticky, mini logo when stuck),
 * "সব বিভাগ" opens the drawer.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

$meeha_items   = meeha_primary_nav_items();
$meeha_sitemap = meeha_opt( 'header', 'sitemap_url', '' );
$meeha_sitemap = $meeha_sitemap ? $meeha_sitemap : '#m-footer-links';
$meeha_home    = home_url( '/' );
?>
<nav class="m-nav" aria-label="প্রধান বিভাগসমূহ">
	<div class="m-container m-nav__bar">
		<a class="m-nav__mini" href="<?php echo esc_url( $meeha_home ); ?>" aria-label="প্রথম পাতা" tabindex="-1"><?php echo meeha_logo( 'compact' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
		<a class="m-nav__home" href="<?php echo esc_url( $meeha_home ); ?>" aria-label="প্রথম পাতা"<?php echo is_front_page() ? ' aria-current="page"' : ''; ?>><?php echo meeha_icon( 'home' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
		<ul class="m-nav__list" role="list">
			<?php foreach ( $meeha_items as $meeha_it ) : ?>
				<li><a class="m-nav__link" href="<?php echo esc_url( $meeha_it['url'] ); ?>"<?php echo $meeha_it['current'] ? ' aria-current="' . esc_attr( $meeha_it['current'] ) . '"' : ''; ?><?php echo ! empty( $meeha_it['target'] ) ? ' target="' . esc_attr( $meeha_it['target'] ) . '"' : ''; ?>><?php echo esc_html( $meeha_it['title'] ); ?></a></li>
			<?php endforeach; ?>
		</ul>
		<a class="m-nav__more" href="<?php echo esc_url( $meeha_sitemap ); ?>" data-open="m-drawer" aria-haspopup="dialog" role="button"><?php echo meeha_icon( 'menu', array( 'size' => 'sm' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>সব বিভাগ</a>
	</div>
</nav>
