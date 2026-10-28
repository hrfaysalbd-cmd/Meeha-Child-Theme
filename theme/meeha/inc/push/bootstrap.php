<?php
/**
 * নিউজ এলার্ট (web push, 2.4.1). A small "মিহা নিউজের নিউজ এলার্ট পেতে চান? হ্যাঁ / না" card asks the reader once;
 * "হ্যাঁ" subscribes the browser with the standard Push API. The theme sends the alerts itself (VAPID + RFC 8291
 * aes128gcm encryption with PHP's openssl), so no OneSignal/Firebase account is needed.
 *
 * Files: store (table {prefix}meeha_push_subs), webpush (keys, encryption, delivery), settings (tab "নিউজ এলার্ট"),
 * rest (meeha/v1/push/subscribe|unsubscribe), send (on publish, batches via WP-Cron, manual send), render (prompt,
 * service worker at /?meeha-sw=1).
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

foreach ( array( 'store', 'webpush', 'settings', 'rest', 'send', 'render' ) as $meeha_push_file ) {
	require_once __DIR__ . '/' . $meeha_push_file . '.php';
}
unset( $meeha_push_file );
