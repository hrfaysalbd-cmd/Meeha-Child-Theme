<?php
/**
 * Front end: the tiny `assistant` bundle (bubble, floating questions, live badge) on every public page; the
 * `assistant-panel` bundle (chat panel) is NOT enqueued — assistant.js loads it on first open (or on hover/focus
 * of the bubble), so the panel costs nothing on page load and never affects LCP.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

/**
 * Show the assistant on this request?
 *
 * @return bool
 */
function meeha_as_wanted() {
	if ( is_admin() || is_feed() || is_embed() || is_robots() || ! meeha_opt( 'assistant', 'enabled', 1 ) ) {
		return false;
	}
	return (bool) apply_filters( 'meeha_assistant_show', true );
}

/**
 * URLs of a bundle's built files (or its source files in a dev checkout without a build).
 *
 * @param string $bundle Bundle.
 * @return array css string[], js string[]
 */
function meeha_as_bundle_urls( $bundle ) {
	$m   = function_exists( 'meeha_manifest' ) ? meeha_manifest() : array();
	$def = isset( $m['bundles'][ $bundle ] ) ? $m['bundles'][ $bundle ] : array();
	$out = array( 'css' => array(), 'js' => array() );
	if ( ! $def ) {
		return $out;
	}
	if ( ! empty( $m['dev'] ) ) {
		foreach ( array( 'css', 'js' ) as $k ) {
			foreach ( isset( $def[ $k ] ) ? (array) $def[ $k ] : array() as $src ) {
				$out[ $k ][] = add_query_arg( 'ver', (string) @filemtime( MEEHA_DIR . '/' . $src ), MEEHA_URI . '/' . ltrim( $src, '/' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		}
		return $out;
	}
	foreach ( array( 'css', 'js' ) as $k ) {
		if ( ! empty( $def[ $k . '_file' ] ) ) {
			$out[ $k ][] = add_query_arg( 'ver', isset( $def[ $k . '_ver' ] ) ? $def[ $k . '_ver' ] : MEEHA_VERSION, MEEHA_URI . '/assets/dist/' . $def[ $k . '_file' ] );
		}
	}
	return $out;
}

add_action(
	'wp_enqueue_scripts',
	function () {
		if ( meeha_as_wanted() ) {
			meeha_enqueue_bundle( 'assistant' );
		}
	},
	4
);

add_action(
	'wp_enqueue_scripts',
	function () {
		if ( ! wp_script_is( 'meeha-assistant', 'enqueued' ) ) {
			return;
		}
		$live = array();
		if ( meeha_opt( 'assistant', 'live_chip', 1 ) ) {
			foreach ( meeha_as_live_posts( 1 ) as $p ) {
				$live[] = array(
					'title' => html_entity_decode( wp_strip_all_tags( get_the_title( $p ) ), ENT_QUOTES, 'UTF-8' ),
					'url'   => get_permalink( $p ),
				);
			}
		}
		$cfg = array(
			'rest'       => esc_url_raw( rest_url( 'meeha/v1/assistant' ) ),
			'home'       => home_url( '/' ),
			'name'       => (string) meeha_opt( 'assistant', 'name', 'মিহা সহকারী' ),
			'label'      => (string) meeha_opt( 'assistant', 'label', 'জিজ্ঞেস করুন' ),
			'greeting'   => (string) meeha_opt( 'assistant', 'greeting', '' ),
			'disclaimer' => (string) meeha_opt( 'assistant', 'disclaimer', 'উত্তর মিহা নিউজের প্রকাশিত খবর থেকে স্বয়ংক্রিয়ভাবে তৈরি' ),
			'chips'      => array( 'আজকের ট্রেন্ডিং', 'আজ দেশে কী হচ্ছে', 'আমার জেলার খবর', 'লাইভ', 'খেলা', 'বিনোদন' ),
			'float'      => meeha_opt( 'assistant', 'float_on', 1 ) ? meeha_as_lines( 'float_chips', meeha_as_default_chips(), 5 ) : array(),
			'delay'      => 1000 * max( 0, (int) meeha_opt( 'assistant', 'float_delay', 3 ) ),
			'liveOn'     => (bool) meeha_opt( 'assistant', 'live_chip', 1 ),
			'live'       => $live,
			'panel'      => meeha_as_bundle_urls( 'assistant-panel' ),
			'max'        => MEEHA_AS_MAX_LEN,
		);
		wp_add_inline_script( 'meeha-assistant', 'window.MeehaAssistantConfig=' . wp_json_encode( $cfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG ) . ';', 'before' );
	},
	20
);
