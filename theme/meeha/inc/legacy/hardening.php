<?php
/**
 * Site hardening (2.4.14) — মিহা থিম › নিরাপত্তা। Every item is a switch (all on by default):
 *  - security headers on front-end, login and admin responses: X-Content-Type-Options, Referrer-Policy,
 *    X-Frame-Options SAMEORIGIN (not on /embed/ pages, which other sites frame), Permissions-Policy. No CSP: ad and
 *    analytics scripts load from many changing hosts, and a wrong CSP silently blanks ads;
 *  - XML-RPC off (unless Jetpack is active, which needs it): no methods, no X-Pingback header, no pingback <link>.
 *    The newsroom apps use the REST API (nros/v1) with application passwords, never XML-RPC;
 *  - user enumeration closed for visitors who are not logged in: /?author=N redirects home, /wp/v2/users answers
 *    401 (the block editor and the apps are logged in, so they keep working), core's users sitemap is off;
 *  - WordPress version hidden: no generator tag (also in feeds), core asset ?ver= replaced by a site-specific hash.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

if ( function_exists( 'meeha_register_settings_tab' ) ) {
	meeha_register_settings_tab(
		'hardening',
		array(
			'title'    => 'নিরাপত্তা',
			'order'    => 75,
			'cap'      => 'admin',
			'autoload' => true,
			'desc'     => 'ব্রাউজার-নিরাপত্তা হেডার, XML-RPC, ইউজার-তালিকা লুকানো। লগইন লক ও ওটিপি আলাদা "নিরাপত্তা সেটিংস" পাতায়।',
			'fields'   => array(
				array( 'key' => 'headers', 'type' => 'checkbox', 'label' => 'নিরাপত্তা হেডার', 'default' => 1, 'text' => 'X-Content-Type-Options, Referrer-Policy, X-Frame-Options (SAMEORIGIN), Permissions-Policy পাঠান', 'desc' => 'অন্য সাইট মিহা নিউজের পাতা ফ্রেমে ভরে ক্লিক-জ্যাকিং করতে পারবে না। বিজ্ঞাপনে প্রভাব নেই (CSP দেওয়া হয়নি)।' ),
				array( 'key' => 'xmlrpc_off', 'type' => 'checkbox', 'label' => 'XML-RPC বন্ধ', 'default' => 1, 'text' => 'xmlrpc.php ও পিংব্যাক বন্ধ (Jetpack চালু থাকলে এটি নিজে থেকেই খোলা থাকে)', 'desc' => 'পাসওয়ার্ড-অনুমান আক্রমণের পুরোনো পথ। মিহার অ্যাপগুলো REST API ব্যবহার করে।' ),
				array( 'key' => 'block_user_enum', 'type' => 'checkbox', 'label' => 'ইউজার-তালিকা লুকান', 'default' => 1, 'text' => 'লগইন ছাড়া ?author=১ ও /wp-json/wp/v2/users দিয়ে ইউজারনেম বের করা যাবে না' ),
				array( 'key' => 'hide_version', 'type' => 'checkbox', 'label' => 'WordPress সংস্করণ লুকান', 'default' => 1 ),
			),
		)
	);
}

/**
 * A hardening switch.
 *
 * @param string $key Key.
 * @return bool
 */
function meeha_harden( $key ) {
	return function_exists( 'meeha_opt' ) ? (bool) meeha_opt( 'hardening', $key, 1 ) : true;
}

/* ---------------------------------------------------------------- headers */

/**
 * Send the security headers (once per response).
 */
function meeha_harden_send_headers() {
	static $sent = false;
	if ( $sent || headers_sent() || ! meeha_harden( 'headers' ) ) {
		return;
	}
	$sent = true;
	header( 'X-Content-Type-Options: nosniff' );
	header( 'Referrer-Policy: strict-origin-when-cross-origin' );
	if ( ! ( function_exists( 'is_embed' ) && did_action( 'wp' ) && is_embed() ) && ! is_customize_preview() ) {
		header( 'X-Frame-Options: SAMEORIGIN' );
	}
	// Camera / microphone / location stay available to the site itself (publisher & correspondent web apps, area
	// finder) but not to third-party frames; the rest is off.
	header( 'Permissions-Policy: ' . (string) apply_filters( 'meeha_permissions_policy', 'camera=(self), microphone=(self), geolocation=(self), payment=(), usb=(), serial=(), bluetooth=(), magnetometer=(), gyroscope=(), accelerometer=()' ) );
}
add_action( 'template_redirect', 'meeha_harden_send_headers', 0 );
add_action( 'login_init', 'meeha_harden_send_headers' );
add_action( 'admin_init', 'meeha_harden_send_headers' );
add_filter(
	'rest_post_dispatch',
	function ( $res ) {
		if ( $res instanceof WP_HTTP_Response && meeha_harden( 'headers' ) ) {
			$res->header( 'X-Content-Type-Options', 'nosniff' );
			$res->header( 'Referrer-Policy', 'strict-origin-when-cross-origin' );
		}
		return $res;
	}
);

/* ---------------------------------------------------------------- XML-RPC */

/**
 * Should XML-RPC be off?
 *
 * @return bool
 */
function meeha_harden_xmlrpc_off() {
	return meeha_harden( 'xmlrpc_off' ) && ! class_exists( 'Jetpack' ) && ! apply_filters( 'meeha_keep_xmlrpc', false );
}

add_action(
	'init',
	function () {
		if ( ! meeha_harden_xmlrpc_off() ) {
			return;
		}
		add_filter( 'xmlrpc_enabled', '__return_false' );
		add_filter( 'xmlrpc_methods', '__return_empty_array', 999 );
		add_filter( 'pings_open', '__return_false', 999 );
		add_filter(
			'wp_headers',
			function ( $h ) {
				unset( $h['X-Pingback'] );
				return $h;
			}
		);
		remove_action( 'wp_head', 'rsd_link' );
		add_filter(
			'bloginfo_url',
			function ( $out, $show ) {
				return 'pingback_url' === $show ? '' : $out;
			},
			10,
			2
		);
	},
	1
);
// Answer xmlrpc.php itself with 403 before WordPress parses the XML (a cheap stop for brute-force bursts).
if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
	add_action(
		'after_setup_theme', // the theme loads after plugins_loaded; class_exists( 'Jetpack' ) is already known here.
		function () {
			if ( meeha_harden_xmlrpc_off() ) {
				status_header( 403 );
				header( 'Content-Type: text/plain; charset=UTF-8' );
				echo 'XML-RPC is disabled on this site.';
				exit;
			}
		},
		0
	);
}

/* ---------------------------------------------------------------- user enumeration */

add_action(
	'parse_request',
	function ( $wp ) {
		if ( is_admin() || is_user_logged_in() || ! meeha_harden( 'block_user_enum' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['author'] ) || ( isset( $wp->query_vars['author'] ) && '' !== (string) $wp->query_vars['author'] && empty( $wp->query_vars['author_name'] ) ) ) {
			wp_safe_redirect( home_url( '/' ), 301 );
			exit;
		}
	},
	1
);

add_filter(
	'rest_pre_dispatch',
	function ( $result, $server, $request ) {
		if ( null !== $result || ! meeha_harden( 'block_user_enum' ) || is_user_logged_in() ) {
			return $result;
		}
		if ( preg_match( '#^/wp/v2/users(?:/|$)#', (string) $request->get_route() ) ) {
			return new WP_Error( 'rest_user_cannot_view', 'অনুমতি নেই।', array( 'status' => 401 ) );
		}
		return $result;
	},
	10,
	3
);

add_filter(
	'wp_sitemaps_add_provider',
	function ( $provider, $name ) {
		return ( 'users' === $name && meeha_harden( 'block_user_enum' ) ) ? false : $provider;
	},
	10,
	2
);

/* ---------------------------------------------------------------- version */

add_action(
	'init',
	function () {
		if ( ! meeha_harden( 'hide_version' ) ) {
			return;
		}
		remove_action( 'wp_head', 'wp_generator' );
		add_filter( 'the_generator', '__return_empty_string' );
		$swap = function ( $src ) {
			if ( ! is_string( $src ) || false === strpos( $src, 'ver=' ) || is_admin() ) {
				return $src;
			}
			$wpv = get_bloginfo( 'version' );
			if ( false === strpos( $src, 'ver=' . $wpv ) ) {
				return $src;
			}
			return add_query_arg( 'ver', substr( wp_hash( 'meeha-ver-' . $wpv ), 0, 8 ), $src );
		};
		add_filter( 'style_loader_src', $swap, 9999 );
		add_filter( 'script_loader_src', $swap, 9999 );
	}
);
