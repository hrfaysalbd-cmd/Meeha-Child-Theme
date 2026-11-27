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
 * r50 — the tracking id of a placement: MH-<client, 4 digits>-<slot> (MH-0000-… for Meeha's own).
 * The same id is printed on the box (data-ad-tid), shown on the client's results page and put in
 * utm_campaign of the ad's link, so the advertiser can find Meeha's visitors in their own analytics.
 *
 * @param string $slot   Slot.
 * @param int    $client Client id.
 * @return string
 */
function meeha_ad_tracking_id( $slot, $client ) {
	return (string) apply_filters( 'meeha_ad_tracking_id', 'MH-' . str_pad( (string) absint( $client ), 4, '0', STR_PAD_LEFT ) . '-' . sanitize_key( $slot ), $slot, $client );
}

/**
 * r50 — add utm_source/medium/campaign/content to an ad link that goes to another site and has no utm yet.
 *
 * @param string $link Link.
 * @param string $tid  Tracking id.
 * @param string $slot Slot.
 * @return string
 */
function meeha_ad_utm_link( $link, $tid, $slot ) {
	$o = meeha_opt( 'ads' );
	if ( '' === $link || ( isset( $o['utm_on'] ) && empty( $o['utm_on'] ) ) || false !== stripos( $link, 'utm_' ) ) {
		return $link;
	}
	$host = strtolower( (string) wp_parse_url( $link, PHP_URL_HOST ) );
	$self = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	if ( '' === $host || $host === $self || ! preg_match( '#^https?://#i', $link ) ) {
		return $link;
	}
	return add_query_arg(
		array(
			'utm_source'   => rawurlencode( preg_replace( '/^www\./', '', $self ) ),
			'utm_medium'   => 'display',
			'utm_campaign' => rawurlencode( $tid ),
			'utm_content'  => rawurlencode( $slot ),
		),
		$link
	);
}

/**
 * r50 — which part of the site this page is (for the advertiser's breakdown).
 *
 * @return string
 */
function meeha_ad_section() {
	if ( is_front_page() || is_home() ) {
		return 'home';
	}
	if ( is_search() ) {
		return 'search';
	}
	$key = function_exists( 'meeha_current_template_key' ) ? meeha_current_template_key() : '';
	if ( 'literature' === $key ) {
		return 'literature';
	}
	if ( 'quotes' === $key ) {
		return 'quotes';
	}
	if ( is_singular( 'post' ) ) {
		$v = isset( $GLOBALS['meeha_single_variant'] ) ? (string) $GLOBALS['meeha_single_variant'] : '';
		if ( 'opinion' === $v ) {
			return 'opinion';
		}
		if ( has_post_format( 'video' ) || get_post_meta( get_the_ID(), '_nros_video_id', true ) ) {
			return 'video';
		}
		return 'article';
	}
	if ( is_category() || is_tag() || is_tax() || is_archive() ) {
		return 'category';
	}
	if ( is_page() ) {
		return 'page';
	}
	return 'other';
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
			$link  = isset( $o[ $slot . '_link' ] ) ? (string) $o[ $slot . '_link' ] : '';
			$link  = meeha_ad_utm_link( $link, meeha_ad_tracking_id( $slot, isset( $o[ $slot . '_client' ] ) ? (int) $o[ $slot . '_client' ] : 0 ), $slot );
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
	$html   = '<aside class="' . esc_attr( $cls ) . '" aria-label="বিজ্ঞাপন" data-ad-slot="' . esc_attr( $slot ) . '" data-ad-tid="' . esc_attr( meeha_ad_tracking_id( $slot, $client ) ) . '"' . ( $client ? ' data-ad-client="' . $client . '"' : '' ) . '>' .
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
 * r49 — the floating bar, the side box and the pop-up. They start hidden; the small script below
 * shows them (and remembers a close for the set number of hours), so a cached page never shows a
 * pop-up twice to someone who closed it.
 *
 * r50 — the side box slides in from the right a few seconds after the page opens (phones too: about
 * half the screen wide; desktop 300 px). A tab with an arrow and "বন্ধ করুন / ভিতরে ঢুকান" tucks it
 * into the right edge; the tab stays, so the reader can pull it out again. A tucked box stays tucked
 * on the next pages for the "আবার দেখাবে" hours.
 *
 * The same script counts, per placement: ইমপ্রেশন (the box was shown on the page), নিশ্চিত দেখা (half
 * of it on screen for one second), clicks, and — for the advertiser's results page — the first view of
 * the day in this browser, the part of the site and where the reader came from. Logged-in readers are
 * counted like everyone else.
 */
function meeha_ads_floating() {
	if ( ! function_exists( 'meeha_opt' ) ) {
		return;
	}
	$o     = meeha_opt( 'ads' );
	$out   = '';
	$float = max( 1, (int) ( isset( $o['float_every'] ) ? $o['float_every'] : 6 ) );
	$lit   = function_exists( 'meeha_current_template_key' ) && 'literature' === meeha_current_template_key();
	if ( meeha_ads_floating_allowed() && ! $lit ) {
		$bar = meeha_ad( 'float_bottom' );
		if ( '' !== $bar ) {
			$out .= '<div class="m-float m-float--bottom" data-float="float_bottom" data-every="' . $float . '" hidden><button type="button" class="m-float__x" aria-label="বিজ্ঞাপন বন্ধ করুন">×</button>' . $bar . '</div>';
		}
		$side = meeha_ad( 'float_side' );
		if ( '' !== $side ) {
			$sdelay = max( 0, min( 120, (int) ( isset( $o['side_delay'] ) ? $o['side_delay'] : 8 ) ) );
			$arrow  = '<svg class="m-sidead__ar" viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path d="M9 5l7 7-7 7" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';
			$out   .= '<div class="m-sidead" data-float="float_side" data-every="' . $float . '" data-delay="' . $sdelay . '" hidden>' .
				'<button type="button" class="m-sidead__tab" aria-expanded="true" aria-controls="m-sidead-box" aria-label="বিজ্ঞাপন বন্ধ করুন — ডান পাশে ভিতরে ঢুকবে">' . $arrow .
				'<span class="m-sidead__t m-sidead__t--in">বন্ধ করুন<br>ভিতরে ঢুকান</span><span class="m-sidead__t m-sidead__t--out">বিজ্ঞাপন<br>খুলুন</span></button>' .
				'<div class="m-sidead__box" id="m-sidead-box">' . $side . '</div></div>';
		}
		$pages = isset( $o['popup_pages'] ) ? (string) $o['popup_pages'] : 'all';
		$where = 'all' === $pages || ( 'single' === $pages && is_singular( 'post' ) ) || ( 'home' === $pages && ( is_front_page() || is_home() ) );
		$pop   = $where ? meeha_ad( 'popup' ) : '';
		if ( '' !== $pop ) {
			$delay = max( 0, min( 120, (int) ( isset( $o['popup_delay'] ) ? $o['popup_delay'] : 6 ) ) );
			$every = max( 1, (int) ( isset( $o['popup_every'] ) ? $o['popup_every'] : 12 ) );
			$out  .= '<div class="m-adpop" data-float="popup" data-delay="' . $delay . '" data-every="' . $every . '" role="dialog" aria-modal="true" aria-label="বিজ্ঞাপন" hidden><div class="m-adpop__card"><button type="button" class="m-float__x m-adpop__x" aria-label="বিজ্ঞাপন বন্ধ করুন">×</button>' . $pop . '</div></div>';
		}
	}
	$beacon = is_admin() || is_customize_preview() ? '' : (string) apply_filters( 'meeha_ads_beacon_url', '' );
	if ( '' === $out && '' === $beacon ) {
		return;
	}
	echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by meeha_ad().
	$sec = meeha_ad_section();
	?>
<script id="meeha-ads-js">(function(){var d=document,de=d.documentElement,ls;try{ls=window.localStorage}catch(e){}
function get(k){try{return ls?ls.getItem(k):null}catch(e){return null}}function put(k,v){try{ls&&ls.setItem(k,v)}catch(e){}}
function until(k){return +(get('m-ad-x-'+k))||0}
var B=<?php echo wp_json_encode( $beacon ); ?>,SEC=<?php echo wp_json_encode( $sec ); ?>,served={},seen={},qi=[],qv=[],qu=[],t;
var SRC=(function(){var r=d.referrer,h='';try{h=r?new URL(r).hostname:''}catch(e){}if(!h)return'direct';if(h===location.hostname)return'internal';if(/(^|\.)google\./.test(h))return'google';if(/facebook\.com$|(^|\.)fb\.|messenger\.com$/.test(h))return'facebook';if(/youtube\.com$|youtu\.be$/.test(h))return'youtube';if(/(^|\.)(t\.co|twitter\.com|x\.com|instagram\.com|linkedin\.com|whatsapp\.com|telegram\.org|t\.me|tiktok\.com|threads\.net)$/.test(h))return'social';return'other'})();
function send(b){if(!B)return;b.s=SEC;b.r=SRC;try{var s=JSON.stringify(b);if(navigator.sendBeacon&&navigator.sendBeacon(B,new Blob([s],{type:'application/json'})))return;fetch(B,{method:'POST',body:s,headers:{'Content-Type':'application/json'},keepalive:true,credentials:'same-origin'})}catch(e){}}
function flush(){clearTimeout(t);t=0;if(qi.length||qv.length){send({i:qi.splice(0),v:qv.splice(0),u:qu.splice(0)})}}
function later(){if(!t)t=setTimeout(flush,1500)}
function covered(a){return de.classList.contains('mvr-lock')&&!a.closest('.mvr')}
function serve(a){var s=a.getAttribute('data-ad-slot');if(!s||served[s]||covered(a))return;served[s]=1;qi.push(s);later()}
function today(){var n=new Date();return n.getFullYear()+'-'+(n.getMonth()+1)+'-'+n.getDate()}
function view(a){var s=a.getAttribute('data-ad-slot');if(seen[s]||covered(a))return;seen[s]=1;serve(a);qv.push(s);var c=a.getAttribute('data-ad-client');if(c&&get('m-ad-u-'+c)!==today()){put('m-ad-u-'+c,today());if(qu.indexOf(c)<0)qu.push(+c)}later()}
function servedIn(el){el.querySelectorAll('[data-ad-slot]').forEach(serve)}
function hide(el,k,h){el.hidden=true;el.classList.remove('is-on');put('m-ad-x-'+k,String(Date.now()+h*36e5));de.classList.remove('m-adpop-open');if(k==='float_bottom')de.classList.remove('m-float-open')}
d.querySelectorAll('[data-float]').forEach(function(el){var k=el.getAttribute('data-float'),h=+el.getAttribute('data-every')||6,dl=+el.getAttribute('data-delay');
if(k==='float_side'){var tab=el.querySelector('.m-sidead__tab'),tucked=until(k)>Date.now();
var set=function(tk){el.classList.toggle('is-tucked',tk);tab.setAttribute('aria-expanded',String(!tk));tab.setAttribute('aria-label',tk?'বিজ্ঞাপন খুলুন':'বিজ্ঞাপন বন্ধ করুন — ডান পাশে ভিতরে ঢুকবে');if(!tk)servedIn(el)};
tab.addEventListener('click',function(){var tk=!el.classList.contains('is-tucked');set(tk);put('m-ad-x-'+k,tk?String(Date.now()+h*36e5):'0')});
setTimeout(function(){el.hidden=false;set(tucked);requestAnimationFrame(function(){requestAnimationFrame(function(){el.classList.add('is-on')})})},tucked?400:(isNaN(dl)?8:dl)*1000);return}
if(until(k)>Date.now())return;
var show=function(){el.hidden=false;servedIn(el);requestAnimationFrame(function(){el.classList.add('is-on')});if(k==='float_bottom'){requestAnimationFrame(function(){de.style.setProperty('--m-float-h',el.offsetHeight+'px');de.classList.add('m-float-open')})}if(k==='popup'){de.classList.add('m-adpop-open');var x=el.querySelector('.m-adpop__x');x&&x.focus({preventScroll:true});}};
el.addEventListener('click',function(e){if(e.target.closest('.m-float__x')||e.target===el){e.preventDefault();hide(el,k,h)}});
if(k==='popup'){d.addEventListener('keydown',function(e){if(e.key==='Escape'&&!el.hidden)hide(el,k,h)});setTimeout(show,(dl||0)*1000)}else{setTimeout(show,1200)}});
if(!B)return;
d.addEventListener('meeha:card-download',function(e){var x=e.detail||{};if(x.ad&&(x.type==='news'||x.type==='quote'))send({p:x.type})});
var ads=d.querySelectorAll('[data-ad-slot]');
setTimeout(function(){ads.forEach(function(a){if(!a.closest('[data-float]')&&a.getClientRects().length)serve(a)})},1000);
ads.forEach(function(a){a.addEventListener('click',function(e){if(e.target.closest('a'))send({c:a.getAttribute('data-ad-slot')})})});
if('IntersectionObserver' in window){var io=new IntersectionObserver(function(es){es.forEach(function(en){var a=en.target;if(!en.isIntersecting||en.intersectionRatio<.5){clearTimeout(a._mt);a._mt=0;return}if(seen[a.getAttribute('data-ad-slot')]||a._mt)return;a._mt=setTimeout(function(){a._mt=0;if(!d.hidden)view(a)},1000)})},{threshold:[0,.5,1]});ads.forEach(function(a){io.observe(a)})}
addEventListener('pagehide',flush);d.addEventListener('visibilitychange',function(){if(d.hidden)flush()});})();</script>
	<?php
}
add_action( 'wp_footer', 'meeha_ads_floating', 30 );
