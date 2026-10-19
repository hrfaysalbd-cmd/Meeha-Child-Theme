<?php
/**
 * Meeha News — standalone theme bootstrap.
 *
 * Loads the foundation (inc/core/*) first, then every feature module's bootstrap
 * file when it exists. Modules must not produce output on include.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'MEEHA_VERSION' ) ) {
	define( 'MEEHA_VERSION', '2.0.6' );
}
if ( ! defined( 'MEEHA_DIR' ) ) {
	define( 'MEEHA_DIR', untrailingslashit( get_template_directory() ) );
}
if ( ! defined( 'MEEHA_URI' ) ) {
	define( 'MEEHA_URI', untrailingslashit( get_template_directory_uri() ) );
}

/*
 * Foundation (owner A). Order matters: helpers → dates → settings framework → the rest.
 */
foreach ( array(
	'helpers',
	'byline',
	'areas',
	'dates',
	'settings',
	'options',
	'icons',
	'template-tags',
	'chrome',
	'ads',
	'assets',
	'perf',
	'seo',
	'elementor',
	'setup',
	'migrate',
	'tools',
	'samples',
) as $meeha_core_file ) {
	require_once MEEHA_DIR . '/inc/core/' . $meeha_core_file . '.php';
}
unset( $meeha_core_file );

/*
 * Feature modules (owners B–E). Each is optional; a missing module never breaks the site.
 */
foreach ( array(
	'legacy',
	'home',
	'metrics',
	'search',
	'single',
	'literature',
	'quotes',
	'studio',
) as $meeha_module ) {
	$meeha_module_file = MEEHA_DIR . '/inc/' . $meeha_module . '/bootstrap.php';
	if ( ! is_readable( $meeha_module_file ) ) {
		$meeha_module_file = MEEHA_DIR . '/inc/' . $meeha_module . '/' . $meeha_module . '.php';
	}
	if ( is_readable( $meeha_module_file ) ) {
		require_once $meeha_module_file;
	}
}
unset( $meeha_module, $meeha_module_file );

/**
 * Fires once every theme module has been loaded.
 */
do_action( 'meeha_loaded' );
