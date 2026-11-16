<?php
/**
 * ফটোকার্ড স্টুডিও (owner E) — module bootstrap.
 *
 * Admin menu "ফটোকার্ড" (স্টুডিও · ফ্রেম লাইব্রেরি · তৈরি কার্ড), REST `meeha/v1/card-source`,
 * `meeha/v1/card-quotes`, `meeha/v1/card-frames`, custom frame storage, settings tab `photocard`,
 * capability `meeha_make_cards`, and the front-end renderer handle `meeha-cards` (assets/js/cards.js).
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

define( 'MEEHA_STUDIO_CAPS_VERSION', '1' );

require_once __DIR__ . '/options.php';
require_once __DIR__ . '/frames.php';
require_once __DIR__ . '/rest.php';
require_once __DIR__ . '/assets.php';
if ( is_admin() ) {
	require_once __DIR__ . '/admin.php';
}
