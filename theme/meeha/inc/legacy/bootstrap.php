<?php
/**
 * Meeha News — legacy feature port (owner B).
 *
 * Everything a live reader/editor relied on in the old `meeha-child` theme that is not pure
 * presentation: accounts & login (OTP, OAuth), correspondent role + edit requests, newsroom
 * app redirects, the Bangladesh area system, editorial meta boxes + Quick Edit, reader reports,
 * the একনজরে and সর্বশেষ shortcode pages and the newsletter fallback.
 *
 * Names, option keys, meta keys, AJAX actions, shortcodes, admin page slugs and GET entry
 * points are kept byte-identical to the old theme (see r11/INVENTORY.md §2) so that a rollback
 * to the old theme and the newsroom-os plugin keep working.
 *
 * Nonce policy (CONTRACT §2.6):
 *  - Anonymous endpoints (login, OTP, register, lost password, report, newsletter, glance,
 *    latest feed, area news) are REST routes under meeha/v1 WITHOUT a nonce, protected by
 *    per-IP rate limits (IP hashed with wp_hash), honeypots and strict validation. Their legacy
 *    admin-ajax actions stay registered as thin wrappers that do NOT require the (possibly
 *    stale, page-cached) nonce.
 *  - Logged-in actions (profile, password, avatar) use admin-ajax with the
 *    `meeha_account_nonce` nonce. It is printed only inside the logged-in-only My Account
 *    markup (never into anonymous, cacheable HTML) and account.js refreshes it at runtime via
 *    the cookie-authenticated `meeha_account_nonce` AJAX action if it went stale.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'MEEHA_LEGACY_DIR' ) ) {
	define( 'MEEHA_LEGACY_DIR', __DIR__ );
}

foreach ( array(
	'util',
	'security',
	'accounts',
	'correspondent',
	'newsroom-sync',
	'area',
	'meta-boxes',
	'reports',
	'glance',
	'latest',
	'newsletter',
	'admin',
) as $meeha_legacy_file ) {
	require_once MEEHA_LEGACY_DIR . '/' . $meeha_legacy_file . '.php';
}
unset( $meeha_legacy_file );
