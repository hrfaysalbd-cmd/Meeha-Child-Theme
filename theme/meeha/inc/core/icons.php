<?php
/**
 * Icons & logo. Header-critical symbols (logo, menu, close, search, user, home) are inlined once after <body>;
 * everything else references the cached sprite file assets/img/sprite.svg (built from tools/sprite-src.svg).
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

/**
 * Symbol ids that are inlined in every page.
 *
 * @return string[]
 */
function meeha_inline_icon_ids() {
	return apply_filters( 'meeha_inline_icon_ids', array( 'm-logo', 'i-menu', 'i-close', 'i-search', 'i-user', 'i-home' ) );
}

/**
 * URL of the external sprite (versioned).
 *
 * @return string
 */
function meeha_sprite_url() {
	static $url = null;
	if ( null === $url ) {
		$file = MEEHA_DIR . '/assets/img/sprite.svg';
		$ver  = file_exists( $file ) ? substr( md5( (string) filemtime( $file ) . MEEHA_VERSION ), 0, 8 ) : MEEHA_VERSION;
		$url  = MEEHA_URI . '/assets/img/sprite.svg?v=' . $ver;
	}
	return $url;
}

/**
 * <svg><use></svg> icon markup.
 *
 * @param string $name Icon name without the "i-" prefix (e.g. "search"), or a full symbol id ("m-logo").
 * @param array  $attr class (string, appended to "m-icon"), label (accessible name), size (sm|lg), plus any attribute.
 * @return string
 */
function meeha_icon( $name, $attr = array() ) {
	$id    = ( 0 === strpos( $name, 'i-' ) || 0 === strpos( $name, 'm-' ) ) ? $name : 'i-' . $name;
	$attr  = (array) $attr;
	$class = 'm-icon';
	if ( ! empty( $attr['size'] ) ) {
		$class .= ' m-icon--' . sanitize_html_class( $attr['size'] );
	}
	if ( ! empty( $attr['class'] ) ) {
		$class .= ' ' . $attr['class'];
	}
	$label = isset( $attr['label'] ) ? (string) $attr['label'] : '';
	unset( $attr['size'], $attr['class'], $attr['label'] );
	$href = in_array( $id, meeha_inline_icon_ids(), true ) ? '#' . $id : meeha_sprite_url() . '#' . $id;
	$a    = ' class="' . esc_attr( $class ) . '"';
	$a   .= $label ? ' role="img" aria-label="' . esc_attr( $label ) . '"' : ' aria-hidden="true" focusable="false"';
	foreach ( $attr as $k => $v ) {
		$a .= ' ' . esc_attr( $k ) . '="' . esc_attr( $v ) . '"';
	}
	return '<svg' . $a . '><use href="' . esc_url( $href ) . '"></use></svg>';
}

/**
 * Print the inline header sprite (once).
 */
function meeha_print_inline_sprite() {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;
	$file = MEEHA_DIR . '/assets/img/sprite-inline.svg';
	if ( ! is_readable( $file ) ) {
		$file = MEEHA_DIR . '/tools/sprite-src.svg';
	}
	$svg = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	echo $svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static theme file.
}

/**
 * Logo markup.
 *
 * @param string $variant main|compact|footer|drawer.
 * @param array  $args    class, label.
 * @return string
 */
function meeha_logo( $variant = 'main', $args = array() ) {
	$class = isset( $args['class'] ) ? $args['class'] : 'm-logo';
	$label = isset( $args['label'] ) ? $args['label'] : '';
	$id    = 0;
	if ( 'footer' === $variant ) {
		$id = (int) meeha_opt( 'footer', 'logo', 0 );
	} elseif ( 'compact' === $variant ) {
		$id = (int) meeha_opt( 'header', 'logo_compact', 0 );
		if ( ! $id ) {
			$id = (int) meeha_opt( 'header', 'logo', 0 );
		}
	} else {
		$id = (int) meeha_opt( 'header', 'logo', 0 );
	}
	if ( $id && wp_attachment_is_image( $id ) ) {
		$img  = wp_get_attachment_image(
			$id,
			'full',
			false,
			array(
				'class'    => $class . ' m-logo--img',
				'alt'      => $label,
				'loading'  => false,
				'decoding' => 'async',
			)
		);
		$dark = (int) meeha_opt( 'header', 'logo_dark', 0 );
		if ( $dark && 'footer' !== $variant && wp_attachment_is_image( $dark ) ) {
			$img .= wp_get_attachment_image(
				$dark,
				'full',
				false,
				array(
					'class'    => $class . ' m-logo--img m-logo--dark',
					'alt'      => '',
					'loading'  => false,
					'decoding' => 'async',
				)
			);
			$img = str_replace( 'class="' . $class . ' m-logo--img"', 'class="' . $class . ' m-logo--img m-logo--light"', $img );
		}
		return $img;
	}
	$a = $label ? ' role="img" aria-label="' . esc_attr( $label ) . '"' : ' aria-hidden="true" focusable="false"';
	return '<svg class="' . esc_attr( $class ) . '"' . $a . '><use href="#m-logo"></use></svg>';
}
