<?php
/**
 * Front end: the "পেতে চান?" card (built by push.js, so cached HTML stays the same for everyone) and the service
 * worker, served by WordPress at /?meeha-sw=1 with its own scope /meeha-push/ — it never takes over the pages, so it
 * cannot clash with a caching/PWA plugin's worker.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

/**
 * Service worker URL and scope (both under the site's home path).
 *
 * @return array [url, scope]
 */
function meeha_push_sw() {
	$path = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
	$path = $path ? trailingslashit( $path ) : '/';
	return array( home_url( '/?meeha-sw=1' ), $path . 'meeha-push/' );
}

/**
 * Show the prompt on this request?
 *
 * @return bool
 */
function meeha_push_wanted() {
	if ( is_admin() || is_feed() || is_embed() || is_preview() || ! meeha_opt( 'alerts', 'enabled', 1 ) ) {
		return false;
	}
	return (bool) apply_filters( 'meeha_push_prompt', meeha_push_supported() );
}

add_action(
	'wp_enqueue_scripts',
	function () {
		if ( meeha_push_wanted() ) {
			meeha_enqueue_bundle( 'push' );
		}
	},
	4
);

add_action(
	'wp_enqueue_scripts',
	function () {
		if ( ! wp_script_is( 'meeha-push', 'enqueued' ) ) {
			return;
		}
		$keys = meeha_push_keys();
		list( $sw, $scope ) = meeha_push_sw();
		$cfg = array(
			'key'    => $keys ? $keys['public'] : '',
			'sw'     => $sw,
			'scope'  => $scope,
			'rest'   => esc_url_raw( rest_url( 'meeha/v1/push/' ) ),
			'q'      => (string) meeha_opt( 'alerts', 'question', 'মিহা নিউজের নিউজ এলার্ট পেতে চান?' ),
			'yes'    => (string) meeha_opt( 'alerts', 'yes', 'হ্যাঁ' ),
			'no'     => (string) meeha_opt( 'alerts', 'no', 'না' ),
			'thanks' => (string) meeha_opt( 'alerts', 'thanks', 'ধন্যবাদ! গুরুত্বপূর্ণ খবর এলেই জানিয়ে দেব।' ),
			'icon'   => meeha_push_icon( 96 ),
			'delay'  => 1000 * max( 0, (int) meeha_opt( 'alerts', 'delay', 6 ) ),
			'snooze' => max( 1, (int) meeha_opt( 'alerts', 'snooze', 7 ) ),
		);
		wp_add_inline_script( 'meeha-push', 'window.MeehaPushConfig=' . wp_json_encode( $cfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG ) . ';', 'before' );
	},
	20
);

/**
 * The service worker source.
 *
 * @return string
 */
function meeha_push_sw_js() {
	$keys = meeha_push_keys();
	$cfg  = wp_json_encode(
		array(
			'key'  => $keys ? $keys['public'] : '',
			'rest' => esc_url_raw( rest_url( 'meeha/v1/push/' ) ),
			'home' => home_url( '/' ),
			'name' => (string) meeha_opt( 'alerts', 'title_prefix', 'মিহা নিউজ' ),
		),
		JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
	);
	return <<<JS
/* Meeha News — নিউজ এলার্ট service worker (only push + notification clicks; no caching) */
var C = {$cfg};
self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (e) { e.waitUntil(self.clients.claim()); });
self.addEventListener('push', function (e) {
  var d = {};
  try { d = e.data ? e.data.json() : {}; } catch (err) { d = { body: e.data ? e.data.text() : '' }; }
  var opt = { body: d.body || '', icon: d.icon || undefined, badge: d.badge || undefined, tag: d.tag || undefined, lang: 'bn', data: { url: d.url || C.home } };
  if (d.image) { opt.image = d.image; }
  e.waitUntil(self.registration.showNotification(d.title || C.name, opt));
});
self.addEventListener('notificationclick', function (e) {
  e.notification.close();
  var url = (e.notification.data && e.notification.data.url) || C.home;
  e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
    for (var i = 0; i < list.length; i++) { if (list[i].url === url && 'focus' in list[i]) { return list[i].focus(); } }
    return self.clients.openWindow ? self.clients.openWindow(url) : null;
  }));
});
function keyBytes(b) { b = b.replace(/-/g, '+').replace(/_/g, '/'); b += '===='.slice(0, (4 - b.length % 4) % 4); var s = atob(b), a = new Uint8Array(s.length); for (var i = 0; i < s.length; i++) { a[i] = s.charCodeAt(i); } return a; }
self.addEventListener('pushsubscriptionchange', function (e) {
  e.waitUntil(self.registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(C.key) }).then(function (sub) {
    return fetch(C.rest + 'subscribe', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(sub.toJSON()), credentials: 'omit' });
  }));
});
JS;
}

add_action(
	'init',
	function () {
		if ( ! isset( $_GET['meeha-sw'] ) || is_admin() ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		do_action( 'litespeed_control_set_nocache', 'meeha service worker' );
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
		header( 'Content-Type: application/javascript; charset=utf-8' );
		header( 'Service-Worker-Allowed: ' . meeha_push_sw()[1] );
		header( 'X-Robots-Tag: noindex' );
		echo meeha_push_sw_js(); // phpcs:ignore WordPress.Security.EscapeOutput -- our own script.
		exit;
	},
	1
);
