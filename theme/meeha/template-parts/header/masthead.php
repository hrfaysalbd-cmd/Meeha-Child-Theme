<?php
/**
 * Masthead: ☰ (mobile) · date stack (desktop) · logo + tagline · search / account / CTA.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

$meeha_d       = meeha_dates();
$meeha_h       = meeha_opt( 'header' );
$meeha_name    = get_bloginfo( 'name' );
$meeha_tagline = (string) $meeha_h['tagline'];
$meeha_sitemap = $meeha_h['sitemap_url'] ? $meeha_h['sitemap_url'] : '#m-footer-links';
$meeha_logged  = is_user_logged_in();
$meeha_account = $meeha_logged ? meeha_get_account_page_url( 'my-account' ) : meeha_get_account_page_url( 'login' );
if ( ! $meeha_logged && home_url( '/' ) === $meeha_account ) {
	$meeha_account = wp_login_url();
}
?>
<header class="m-masthead">
	<div class="m-container m-masthead__inner">
		<a class="m-iconbtn m-masthead__menu" href="<?php echo esc_url( $meeha_sitemap ); ?>" data-open="m-drawer" aria-label="মেনু খুলুন" aria-haspopup="dialog"><?php echo meeha_icon( 'menu', array( 'size' => 'lg' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
		<div class="m-masthead__date" aria-label="আজকের তারিখ">
			<b><?php echo esc_html( $meeha_d['weekday'] ); ?></b>
			<span><?php echo esc_html( $meeha_d['gregorian'] ); ?> খ্রিষ্টাব্দ</span>
			<?php if ( $meeha_h['date_bangla'] ) : ?><span><?php echo esc_html( $meeha_d['bangla'] ); ?> বঙ্গাব্দ</span><?php endif; ?>
			<?php if ( $meeha_h['date_hijri'] ) : ?><span><?php echo esc_html( $meeha_d['hijri'] ); ?> হিজরি</span><?php endif; ?>
		</div>
		<?php if ( is_front_page() && apply_filters( 'meeha_header_h1', true ) ) : ?>
			<h1 class="m-vh"><?php echo esc_html( $meeha_name . ( $meeha_tagline ? ' — ' . $meeha_tagline : '' ) ); ?></h1>
		<?php endif; ?>
		<a class="m-masthead__brand" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="<?php echo esc_attr( $meeha_name . ' — প্রথম পাতা' ); ?>"<?php echo is_front_page() ? ' aria-current="page"' : ''; ?>><?php echo meeha_logo( 'main' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php if ( $meeha_tagline ) : ?><span class="m-masthead__tagline"><?php echo esc_html( $meeha_tagline ); ?></span><?php endif; ?></a>
		<div class="m-masthead__actions">
			<a class="m-searchbtn" href="<?php echo esc_url( home_url( '/?s=' ) ); ?>" data-open="m-search" aria-label="খুঁজুন" aria-haspopup="dialog"><?php echo meeha_icon( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span>খবর খুঁজুন</span><kbd>/</kbd></a>
			<?php if ( $meeha_h['show_account'] ) : ?>
				<a class="m-iconbtn m-hide-sm" href="<?php echo esc_url( $meeha_account ); ?>" aria-label="<?php echo esc_attr( $meeha_logged ? 'আমার অ্যাকাউন্ট' : 'লগ ইন' ); ?>"><?php echo meeha_icon( 'user', array( 'size' => 'lg' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
			<?php endif; ?>
			<?php if ( $meeha_h['cta_label'] && $meeha_h['cta_url'] ) : ?>
				<a class="m-btn" href="<?php echo esc_url( $meeha_h['cta_url'] ); ?>"><?php echo esc_html( $meeha_h['cta_label'] ); ?></a>
			<?php endif; ?>
		</div>
	</div>
</header>
