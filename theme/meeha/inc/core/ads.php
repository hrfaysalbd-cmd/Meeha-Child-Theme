<?php
/**
 * Ad slots (DESIGN-SPEC §18). Every slot is a fixed-ratio box; a disabled / empty / out-of-date slot returns ''
 * so the server never prints an empty box. Third-party code (HTML with scripts, GAM) is injected by meeha.js
 * after `load` + idle.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

/**
 * Is a slot within its start/end dates (Asia/Dhaka)?
 *
 * @param array  $o    Ads options.
 * @param string $slot Slot.
 * @return bool
 */
function meeha_ad_in_window( $o, $slot ) {
	$today = wp_date( 'Y-m-d', null, new DateTimeZone( 'Asia/Dhaka' ) );
	$start = isset( $o[ $slot . '_start' ] ) ? (string) $o[ $slot . '_start' ] : '';
	$end   = isset( $o[ $slot . '_end' ] ) ? (string) $o[ $slot . '_end' ] : '';
	if ( $start && $today < $start ) {
		return false;
	}
	if ( $end && $today > $end ) {
		return false;
	}
	return true;
}

/**
 * Image data for an attachment.
 *
 * @param int $id Attachment.
 * @return array|null url,w,h,id
 */
function meeha_ad_image_data( $id ) {
	$id = (int) $id;
	if ( ! $id ) {
		return null;
	}
	$src = wp_get_attachment_image_src( $id, 'full' );
	if ( ! $src ) {
		return null;
	}
	return array(
		'id'  => $id,
		'url' => $src[0],
		'w'   => (int) $src[1],
		'h'   => (int) $src[2],
	);
}

/**
 * Creatives of a slot (card_strip: every enabled strip row; other slots: the image creative).
 *
 * @param string $slot Slot id.
 * @return array[] each: url, link, w, h, id, label
 */
function meeha_ad_creatives( $slot ) {
	$o   = meeha_opt( 'ads' );
	$out = array();
	if ( 'card_strip' === $slot ) {
		foreach ( (array) ( isset( $o['card_strip'] ) ? $o['card_strip'] : array() ) as $row ) {
			if ( empty( $row['on'] ) ) {
				continue;
			}
			$img = meeha_ad_image_data( isset( $row['image'] ) ? $row['image'] : 0 );
			if ( $img ) {
				$out[] = array_merge(
					$img,
					array(
						'link'  => isset( $row['link'] ) ? (string) $row['link'] : '',
						'label' => isset( $row['label'] ) && '' !== $row['label'] ? (string) $row['label'] : 'স্ট্রিপ ' . meeha_bn_digits( count( $out ) + 1 ),
					)
				);
			}
		}
		return $out;
	}
	if ( empty( $o[ $slot . '_on' ] ) || ! meeha_ad_in_window( $o, $slot ) || 'image' !== ( isset( $o[ $slot . '_type' ] ) ? $o[ $slot . '_type' ] : 'image' ) ) {
		return $out;
	}
	$img = meeha_ad_image_data( isset( $o[ $slot . '_image' ] ) ? $o[ $slot . '_image' ] : 0 );
	if ( $img ) {
		$out[] = array_merge(
			$img,
			array(
				'link'  => isset( $o[ $slot . '_link' ] ) ? (string) $o[ $slot . '_link' ] : '',
				'label' => $slot,
			)
		);
	}
	return $out;
}

/**
 * First creative of a slot (the studio uses `card_strip`).
 *
 * @param string $slot Slot id.
 * @return array url, link, w, h (+ id, label) or [] when none.
 */
function meeha_ad_creative( $slot ) {
	$all = meeha_ad_creatives( $slot );
	return $all ? $all[0] : array();
}

/**
 * Ad box HTML for a slot, or '' when the slot is off/empty/out of its dates.
 *
 * @param string $slot Slot id (header_leader, home_leader, home_mpu_1, home_mpu_2, article_1, article_2,
 *                     article_side, archive_infeed, archive_side).
 * @return string
 */
function meeha_ad( $slot ) {
	$slots = meeha_ad_slots();
	if ( ! isset( $slots[ $slot ] ) ) {
		return '';
	}
	$o = meeha_opt( 'ads' );
	if ( empty( $o[ $slot . '_on' ] ) || ! meeha_ad_in_window( $o, $slot ) ) {
		return '';
	}
	$size  = $slots[ $slot ][1];
	$type  = isset( $o[ $slot . '_type' ] ) ? $o[ $slot . '_type' ] : 'image';
	$inner = '';
	$style = '';
	switch ( $type ) {
		case 'image':
			$img = meeha_ad_image_data( isset( $o[ $slot . '_image' ] ) ? $o[ $slot . '_image' ] : 0 );
			if ( ! $img ) {
				return '';
			}
			$mob   = meeha_ad_image_data( isset( $o[ $slot . '_image_m' ] ) ? $o[ $slot . '_image_m' ] : 0 );
			$alt   = isset( $o[ $slot . '_alt' ] ) && '' !== $o[ $slot . '_alt' ] ? $o[ $slot . '_alt' ] : 'বিজ্ঞাপন';
			$eager = 'header_leader' === $slot;
			// Responsive candidates (never for GIFs: WP's resized copies are single static frames).
			$srcset = 'image/gif' === get_post_mime_type( $img['id'] ) ? '' : (string) wp_get_attachment_image_srcset( $img['id'], 'full' );
			$sizes  = 'leader' === $size ? '(max-width: 767.98px) 320px, (max-width: 1023.98px) 728px, 970px' : '300px';
			$tag    = sprintf(
				'<img src="%s"%s width="%d" height="%d" alt="%s"%s decoding="async">',
				esc_url( $img['url'] ),
				$srcset ? ' srcset="' . esc_attr( $srcset ) . '" sizes="' . esc_attr( $sizes ) . '"' : '',
				$img['w'],
				$img['h'],
				esc_attr( $alt ),
				// The header banner sits above the lead story: load it eagerly but never ahead of the LCP image.
				$eager ? ' fetchpriority="low"' : ' loading="lazy"'
			);
			if ( $mob ) {
				$tag = sprintf( '<picture><source media="(max-width: 767.98px)" srcset="%s" width="%d" height="%d">%s</picture>', esc_url( $mob['url'] ), $mob['w'], $mob['h'], $tag );
			} elseif ( 'leader' === $size && $img['h'] && $img['w'] / $img['h'] >= 3 ) { // a real banner: keep its own ratio on phones too.
				$style = ' style="aspect-ratio:' . (int) $img['w'] . ' / ' . (int) $img['h'] . '"';
			}
			$link  = isset( $o[ $slot . '_link' ] ) ? $o[ $slot . '_link' ] : '';
			$inner = $link ? '<a href="' . esc_url( $link ) . '" rel="sponsored noopener" target="_blank">' . $tag . '</a>' : $tag;
			break;
		case 'html':
			$code = isset( $o[ $slot . '_code' ] ) ? trim( (string) $o[ $slot . '_code' ] ) : '';
			if ( '' === $code ) {
				return '';
			}
			// Scripts run only after load + idle (meeha.js clones the template); plain HTML renders immediately.
			$inner = ( false !== stripos( $code, '<script' ) || false !== stripos( $code, '<iframe' ) ) ? '<template class="m-ad__defer">' . $code . '</template>' : $code;
			break;
		case 'shortcode':
			$sc = isset( $o[ $slot . '_shortcode' ] ) ? trim( (string) $o[ $slot . '_shortcode' ] ) : '';
			if ( '' === $sc || ! preg_match( '/^\[\s*([\w-]+)/', $sc, $m ) || ! shortcode_exists( $m[1] ) ) {
				return '';
			}
			$inner = do_shortcode( $sc );
			if ( '' === trim( wp_strip_all_tags( $inner, true ) ) && false === strpos( $inner, '<img' ) && false === strpos( $inner, '<ins' ) && false === strpos( $inner, '<script' ) && false === strpos( $inner, '<iframe' ) ) {
				return '';
			}
			break;
		case 'gam':
			$unit = isset( $o[ $slot . '_gam' ] ) ? trim( (string) $o[ $slot . '_gam' ] ) : '';
			if ( '' === $unit ) {
				return '';
			}
			$sizes = array(
				'leader' => '[[970,90],[728,90],[320,100],[320,50]]',
				'mpu'    => '[[300,250]]',
				'half'   => '[[300,600],[300,250]]',
			);
			$inner = sprintf( '<div class="m-ad__gam" id="m-gam-%1$s" data-gam-unit="%2$s" data-gam-sizes="%3$s"></div>', esc_attr( $slot ), esc_attr( $unit ), esc_attr( isset( $sizes[ $size ] ) ? $sizes[ $size ] : '[[300,250]]' ) );
			break;
		default:
			return '';
	}
	$device = isset( $o[ $slot . '_device' ] ) ? $o[ $slot . '_device' ] : 'all';
	$cls    = 'm-ad m-ad--' . $size . ( 'mobile' === $device ? ' m-show-m' : ( 'desktop' === $device ? ' m-hide-m' : '' ) );
	$label  = ! isset( $o[ $slot . '_label' ] ) || ! empty( $o[ $slot . '_label' ] );
	$html   = '<aside class="' . esc_attr( $cls ) . '" aria-label="বিজ্ঞাপন" data-ad-slot="' . esc_attr( $slot ) . '">' .
		( $label ? '<span class="m-ad__label">বিজ্ঞাপন</span>' : '' ) .
		'<div class="m-ad__box"' . $style . '>' . $inner . '</div></aside>';
	return (string) apply_filters( 'meeha_ad_html', $html, $slot, $type );
}
