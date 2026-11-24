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
 * r49 — the "বিজ্ঞাপন" mark of an ad box, in the publisher's chosen style.
 *
 * @param string $style corner|side|top|none.
 * @return string
 */
function meeha_ad_label_html( $style ) {
	switch ( $style ) {
		case 'none':
			return '';
		case 'corner':
			// A small ⓘ on the creative's corner; hovering or tapping it shows "বিজ্ঞাপন" (no JS: :hover / :focus).
			return '<button type="button" class="m-ad__i" aria-label="বিজ্ঞাপন"><span aria-hidden="true">i</span></button><span class="m-ad__tip" role="tooltip">বিজ্ঞাপন</span>';
		default:
			return '<span class="m-ad__label">বিজ্ঞাপন</span>';
	}
}

/**
 * Ad box HTML for a slot, or '' when the slot is off/empty/out of its dates.
 *
 * r49: an image creative keeps its own shape — the box takes the picture's ratio (the phone
 * picture's ratio on phones), so a 320 × 50 banner is never cropped into a 320 × 100 box.
 *
 * @param string $slot Slot id (header_leader, home_leader, … float_bottom, float_side, popup).
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
	$boxc  = '';
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
			$wide   = in_array( $size, array( 'leader', 'float' ), true );
			$sizes  = $wide ? '(max-width: 767.98px) 320px, (max-width: 1023.98px) 728px, 970px' : ( 'popup' === $size ? '(max-width: 767.98px) 92vw, 600px' : '300px' );
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
			}
			// The box follows the creative's own ratio on each screen.
			$vars = array();
			if ( $img['w'] && $img['h'] ) {
				$vars[] = '--ar:' . (int) $img['w'] . ' / ' . (int) $img['h'];
				// never stretched past its own width (a 728×90 stays sharp in a 970 slot)
				$vars[] = '--aw:' . (int) $img['w'] . 'px';
			}
			if ( $mob && $mob['w'] && $mob['h'] ) {
				$vars[] = '--ar-m:' . (int) $mob['w'] . ' / ' . (int) $mob['h'];
				$vars[] = '--aw-m:' . (int) $mob['w'] . 'px';
			}
			if ( $vars ) {
				$style = ' style="' . esc_attr( implode( ';', $vars ) ) . '"';
				$boxc  = ' m-ad__box--fit';
			}
			$link  = isset( $o[ $slot . '_link' ] ) ? $o[ $slot . '_link' ] : '';
			$inner = $link ? '<a href="' . esc_url( $link ) . '" rel="sponsored noopener" target="_blank">' . $tag . '</a>' : $tag;
			break;
		case 'house': // r47 — Meeha's own animated feature card.
			$card  = isset( $o[ $slot . '_house' ] ) ? (string) $o[ $slot . '_house' ] : '';
			$hsize = in_array( $size, array( 'float' ), true ) ? 'leader' : ( in_array( $size, array( 'corner', 'popup' ), true ) ? 'mpu' : $size );
			$inner = '' !== $card && function_exists( 'meeha_house_ad_html' ) ? meeha_house_ad_html( $card, $hsize ) : '';
			if ( '' === $inner ) {
				return '';
			}
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
				'float'  => '[[728,90],[320,50],[320,100]]',
				'mpu'    => '[[300,250]]',
				'corner' => '[[300,250]]',
				'half'   => '[[300,600],[300,250]]',
				'popup'  => '[[600,500],[320,480],[300,250]]',
			);
			$inner = sprintf( '<div class="m-ad__gam" id="m-gam-%1$s" data-gam-unit="%2$s" data-gam-sizes="%3$s"></div>', esc_attr( $slot ), esc_attr( $unit ), esc_attr( isset( $sizes[ $size ] ) ? $sizes[ $size ] : '[[300,250]]' ) );
			break;
		default:
			return '';
	}
	$device = isset( $o[ $slot . '_device' ] ) ? $o[ $slot . '_device' ] : 'all';
	$lstyle = isset( $o['label_style'] ) ? (string) $o['label_style'] : 'corner';
	$label  = 'house' !== $type && ( ! isset( $o[ $slot . '_label' ] ) || ! empty( $o[ $slot . '_label' ] ) ); // house = self-promo, never labelled "বিজ্ঞাপন".
	$lhtml  = $label ? meeha_ad_label_html( $lstyle ) : '';
	$cls    = 'm-ad m-ad--' . $size . ( $lhtml ? ' m-ad--l' . $lstyle : '' ) . ( 'mobile' === $device ? ' m-show-m' : ( 'desktop' === $device ? ' m-hide-m' : '' ) );
	$client = isset( $o[ $slot . '_client' ] ) ? (int) $o[ $slot . '_client' ] : 0;
	// The corner ⓘ sits inside the box (over the creative); the text marks sit above it.
	$inbox  = 'corner' === $lstyle ? $lhtml : '';
	$above  = 'corner' === $lstyle ? '' : $lhtml;
	$html   = '<aside class="' . esc_attr( $cls ) . '" aria-label="বিজ্ঞাপন" data-ad-slot="' . esc_attr( $slot ) . '"' . ( $client ? ' data-ad-client="' . $client . '"' : '' ) . '>' .
		$above .
		'<div class="m-ad__box' . $boxc . '"' . $style . '>' . $inner . $inbox . '</div></aside>';
	return (string) apply_filters( 'meeha_ad_html', $html, $slot, $type );
}

/**
 * r49 — is this a page where floating ads and pop-ups may appear?
 *
 * @return bool
 */
function meeha_ads_floating_allowed() {
	if ( is_admin() || is_customize_preview() || is_404() ) {
		return false;
	}
	if ( is_page_template( 'page-canvas.php' ) ) { // landing pages stay clean
		return false;
	}
	return (bool) apply_filters( 'meeha_ads_floating_allowed', true );
}

/**
 * r49 — the floating bar, the corner box and the pop-up, each with a close (×) button. They start
 * hidden; the small script below shows them (and remembers a close for the set number of hours),
 * so a cached page never shows a pop-up twice to someone who closed it.
 */
function meeha_ads_floating() {
	if ( ! function_exists( 'meeha_opt' ) || ! meeha_ads_floating_allowed() ) {
		return;
	}
	$o     = meeha_opt( 'ads' );
	$out   = '';
	$float = max( 1, (int) ( isset( $o['float_every'] ) ? $o['float_every'] : 6 ) );
	$bar   = meeha_ad( 'float_bottom' );
	if ( '' !== $bar ) {
		$out .= '<div class="m-float m-float--bottom" data-float="float_bottom" data-every="' . $float . '" hidden><button type="button" class="m-float__x" aria-label="বিজ্ঞাপন বন্ধ করুন">×</button>' . $bar . '</div>';
	}
	$side = meeha_ad( 'float_side' );
	if ( '' !== $side ) {
		$out .= '<div class="m-float m-float--side" data-float="float_side" data-every="' . $float . '" hidden><button type="button" class="m-float__x" aria-label="বিজ্ঞাপন বন্ধ করুন">×</button>' . $side . '</div>';
	}
	$pages = isset( $o['popup_pages'] ) ? (string) $o['popup_pages'] : 'all';
	$where = 'all' === $pages || ( 'single' === $pages && is_singular( 'post' ) ) || ( 'home' === $pages && ( is_front_page() || is_home() ) );
	$pop   = $where ? meeha_ad( 'popup' ) : '';
	if ( '' !== $pop ) {
		$delay = max( 0, min( 120, (int) ( isset( $o['popup_delay'] ) ? $o['popup_delay'] : 6 ) ) );
		$every = max( 1, (int) ( isset( $o['popup_every'] ) ? $o['popup_every'] : 12 ) );
		$out  .= '<div class="m-adpop" data-float="popup" data-delay="' . $delay . '" data-every="' . $every . '" role="dialog" aria-modal="true" aria-label="বিজ্ঞাপন" hidden><div class="m-adpop__card"><button type="button" class="m-float__x m-adpop__x" aria-label="বিজ্ঞাপন বন্ধ করুন">×</button>' . $pop . '</div></div>';
	}
	$beacon = (string) apply_filters( 'meeha_ads_beacon_url', '' );
	if ( '' === $out && '' === $beacon ) {
		return;
	}
	echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by meeha_ad().
	// Close/remember + pop-up timer + (with newsroom-os) a count of views and clicks per placement.
	?>
<script id="meeha-ads-js">(function(){var d=document,ls;try{ls=window.localStorage}catch(e){}
function until(k){try{return +(ls&&ls.getItem('m-ad-x-'+k))||0}catch(e){return 0}}
function hide(el,k,h){el.hidden=true;el.classList.remove('is-on');try{ls&&ls.setItem('m-ad-x-'+k,String(Date.now()+h*36e5))}catch(e){}d.documentElement.classList.remove('m-adpop-open');if(k==='float_bottom')d.documentElement.classList.remove('m-float-open')}
d.querySelectorAll('[data-float]').forEach(function(el){var k=el.getAttribute('data-float'),h=+el.getAttribute('data-every')||6;if(until(k)>Date.now())return;
var show=function(){el.hidden=false;requestAnimationFrame(function(){el.classList.add('is-on')});if(k==='float_bottom'){requestAnimationFrame(function(){d.documentElement.style.setProperty('--m-float-h',el.offsetHeight+'px');d.documentElement.classList.add('m-float-open')})}if(k==='popup'){d.documentElement.classList.add('m-adpop-open');var x=el.querySelector('.m-adpop__x');x&&x.focus({preventScroll:true});}};
el.addEventListener('click',function(e){if(e.target.closest('.m-float__x')||e.target===el){e.preventDefault();hide(el,k,h)}});
if(k==='popup'){d.addEventListener('keydown',function(e){if(e.key==='Escape'&&!el.hidden)hide(el,k,h)});setTimeout(show,(+el.getAttribute('data-delay')||0)*1000)}else{setTimeout(show,1200)}});
var B=<?php echo wp_json_encode( $beacon ); ?>;if(!B||!('IntersectionObserver' in window))return;
var seen={},q=[],t;function send(body){try{var s=JSON.stringify(body);if(navigator.sendBeacon){navigator.sendBeacon(B,new Blob([s],{type:'application/json'}))}else{fetch(B,{method:'POST',body:s,headers:{'Content-Type':'application/json'},keepalive:true})}}catch(e){}}
function flush(){if(q.length){send({v:q.splice(0)})}}
var io=new IntersectionObserver(function(es){es.forEach(function(en){var s=en.target.getAttribute('data-ad-slot');if(!en.isIntersecting){clearTimeout(en.target._mt);return}if(seen[s])return;var tm=setTimeout(function(){if(seen[s])return;seen[s]=1;q.push(s);clearTimeout(t);t=setTimeout(flush,1500)},1000);en.target._mt=tm});},{threshold:.5});
d.querySelectorAll('[data-ad-slot]').forEach(function(a){io.observe(a);a.addEventListener('click',function(e){if(e.target.closest('a'))send({c:a.getAttribute('data-ad-slot')})})});
addEventListener('pagehide',flush);})();</script>
	<?php
}
add_action( 'wp_footer', 'meeha_ads_floating', 30 );
