<?php
/**
 * Core helpers — legacy public API (kept byte-compatible with the old child theme) plus the
 * new helpers every module uses (images, kickers, categories, labels).
 *
 * Legacy names (CONTRACT §2.1) defined here or in areas.php:
 *   meeha_bn_digits(), meeha_bengali_month(), meeha_bengali_date(), meeha_bengali_time_ago(),
 *   meeha_reading_time(), meeha_get_summary(), meeha_is_correspondent(), meeha_get_account_page_url(),
 *   meeha_get_bd_divisions(), meeha_get_upazilas_for_district(), (seo.php) meeha_output_social_meta_tags().
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

/* --------------------------------------------------------------------------
 * Bengali numbers, months, dates (legacy)
 * ------------------------------------------------------------------------ */

/**
 * Convert ASCII digits to Bengali digits. (legacy, FP:1004)
 *
 * @param mixed $value Value.
 * @return string
 */
function meeha_bn_digits( $value ) {
	static $en = array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' );
	static $bn = array( '০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯' );
	return str_replace( $en, $bn, (string) $value );
}

if ( ! function_exists( 'meeha_bn_number' ) ) {
	/**
	 * Legacy duplicate of meeha_bn_digits() (old inc/header.php:41).
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	function meeha_bn_number( $value ) {
		return meeha_bn_digits( $value );
	}
}

/**
 * Convert Bengali digits to ASCII digits.
 *
 * @param mixed $value Value.
 * @return string
 */
function meeha_en_digits( $value ) {
	static $bn = array( '০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯' );
	static $en = array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' );
	return str_replace( $bn, $en, (string) $value );
}

/**
 * English month name → Bengali month name; unknown input is returned unchanged. (legacy, FP:1010)
 *
 * @param string $english_month e.g. "September".
 * @return string
 */
function meeha_bengali_month( $english_month ) {
	$months = array(
		'January'   => 'জানুয়ারি',
		'February'  => 'ফেব্রুয়ারি',
		'March'     => 'মার্চ',
		'April'     => 'এপ্রিল',
		'May'       => 'মে',
		'June'      => 'জুন',
		'July'      => 'জুলাই',
		'August'    => 'আগস্ট',
		'September' => 'সেপ্টেম্বর',
		'October'   => 'অক্টোবর',
		'November'  => 'নভেম্বর',
		'December'  => 'ডিসেম্বর',
	);
	if ( is_numeric( $english_month ) ) {
		$n    = (int) $english_month;
		$keys = array_keys( $months );
		return ( $n >= 1 && $n <= 12 ) ? $months[ $keys[ $n - 1 ] ] : (string) $english_month;
	}
	return isset( $months[ $english_month ] ) ? $months[ $english_month ] : $english_month;
}

/**
 * "৮ সেপ্টেম্বর ২০২৬, ৫:৩৪ পূর্বাহ্ণ". (legacy, FP:1029)
 *
 * Accepts what the old theme accepted (null = current loop post, a post ID, a WP_Post) and,
 * new in 2.0, a Unix timestamp (any integer ≥ 100000000 that is not an existing post ID).
 *
 * @param int|WP_Post|null $ts_or_post Post, post ID, timestamp or null.
 * @param bool             $with_time  Append the time.
 * @return string
 */
function meeha_bengali_date( $ts_or_post = null, $with_time = true ) {
	$ts = null;
	if ( is_numeric( $ts_or_post ) && (int) $ts_or_post >= 100000000 && ! get_post( (int) $ts_or_post ) ) {
		$ts = (int) $ts_or_post;
	}
	if ( null !== $ts ) {
		$day   = wp_date( 'j', $ts );
		$month = meeha_bengali_month( (int) wp_date( 'n', $ts ) ); // numeric month: locale independent.
		$year  = wp_date( 'Y', $ts );
		$hm    = wp_date( 'g:i', $ts );
		$ampm  = gmdate( 'a', $ts + wp_timezone()->getOffset( new DateTime( '@' . $ts ) ) );
	} else {
		$day   = get_the_date( 'j', $ts_or_post );
		$month = meeha_bengali_month( (int) get_the_date( 'n', $ts_or_post ) );
		$year  = get_the_date( 'Y', $ts_or_post );
		$hm    = get_the_date( 'g:i', $ts_or_post );
		$ampm  = get_the_date( 'a', $ts_or_post );
	}
	$out = meeha_bn_digits( $day ) . ' ' . $month . ' ' . meeha_bn_digits( $year );
	if ( $with_time ) {
		$ampm_bn = ( 'am' === strtolower( (string) $ampm ) || 'পূর্বাহ্ণ' === $ampm ) ? 'পূর্বাহ্ণ' : 'অপরাহ্ণ';
		$out    .= ', ' . meeha_bn_digits( $hm ) . ' ' . $ampm_bn;
	}
	return $out;
}

/**
 * "২ দিন আগে" — Bengali human_time_diff(). (legacy, FP:1045)
 *
 * Note: like the old theme, timestamps are compared against current_time( 'timestamp' ), i.e. both
 * values are expected in "local" (WP timezone shifted) seconds. Use get_post_time( 'U' ) for posts.
 *
 * @param int      $from_timestamp From.
 * @param int|null $to_timestamp   To (default now, local).
 * @return string
 */
function meeha_bengali_time_ago( $from_timestamp, $to_timestamp = null ) {
	$from_timestamp = (int) $from_timestamp;
	$to_timestamp   = null === $to_timestamp ? current_time( 'timestamp' ) : (int) $to_timestamp; // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
	$diff           = max( 0, $to_timestamp - $from_timestamp );

	if ( $diff < 60 ) {
		return 'এইমাত্র';
	}
	$units = array(
		YEAR_IN_SECONDS     => 'বছর',
		30 * DAY_IN_SECONDS => 'মাস',
		WEEK_IN_SECONDS     => 'সপ্তাহ',
		DAY_IN_SECONDS      => 'দিন',
		HOUR_IN_SECONDS     => 'ঘণ্টা',
		MINUTE_IN_SECONDS   => 'মিনিট',
	);
	foreach ( $units as $seconds => $label ) {
		$count = (int) floor( $diff / $seconds );
		if ( $count >= 1 ) {
			return meeha_bn_digits( $count ) . ' ' . $label . ' আগে';
		}
	}
	return 'এইমাত্র';
}

/**
 * Time-ago label for a post (uses the same local-time basis as the legacy helper).
 *
 * @param WP_Post|int|null $post Post.
 * @return string
 */
function meeha_post_time_ago( $post = null ) {
	$post = get_post( $post );
	if ( ! $post ) {
		return '';
	}
	return meeha_bengali_time_ago( (int) get_post_time( 'U', false, $post ) );
}

/**
 * Estimated reading time in minutes (min 1). Unicode-aware word split, 200 wpm by default
 * (filter `meeha_reading_wpm`), rounded. (legacy, FP:978 — identical behaviour)
 *
 * @param string|null $content Content; null = current post.
 * @return int
 */
function meeha_reading_time( $content = null ) {
	if ( null === $content ) {
		$content = get_post_field( 'post_content', get_the_ID() );
	}
	$text  = html_entity_decode( wp_strip_all_tags( strip_shortcodes( (string) $content ) ), ENT_QUOTES, 'UTF-8' );
	$words = preg_split( '/[\s\p{Z}]+/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );
	if ( ! is_array( $words ) ) { // invalid UTF-8 → preg_split() returns false.
		$words = preg_split( '/\s+/', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );
	}
	$word_count = 0;
	foreach ( (array) $words as $w ) {
		if ( preg_match( '/[\p{L}\p{N}]/u', $w ) ) {
			$word_count++;
		}
	}
	$wpm = max( 1, (int) apply_filters( 'meeha_reading_wpm', 200 ) );
	return max( 1, (int) round( $word_count / $wpm ) );
}

/**
 * Editor summary (`_meeha_summary`). (legacy, FP:1446)
 *
 * @param int $post_id Post ID.
 * @return string
 */
function meeha_get_summary( $post_id ) {
	return get_post_meta( $post_id, '_meeha_summary', true );
}

/**
 * Whether a user has the `correspondent` role. (legacy, inc/correspondent.php:56)
 *
 * @param int $user_id User ID (0 = current).
 * @return bool
 */
function meeha_is_correspondent( $user_id = 0 ) {
	$user_id = $user_id ? $user_id : get_current_user_id();
	if ( ! $user_id ) {
		return false;
	}
	$user = get_userdata( $user_id );
	return $user && in_array( 'correspondent', (array) $user->roles, true );
}

/**
 * URL of the page that holds an account shortcode. (legacy, FP:3614 — same keys, same transient, same result;
 * the scan now only loads pages whose content mentions the shortcode instead of every page.)
 *
 * @param string $which login | register | my-account | lost-password.
 * @return string
 */
function meeha_get_account_page_url( $which ) {
	// The legacy module (B) also scans `_elementor_data`; prefer it when loaded.
	if ( function_exists( 'meeha_account_page_lookup' ) ) {
		$found = meeha_account_page_lookup( $which );
		return $found ? $found : home_url( '/' );
	}
	$map = array(
		'login'         => 'meeha_login',
		'register'      => 'meeha_register',
		'my-account'    => 'meeha_my_account',
		'lost-password' => 'meeha_lost_password',
	);
	if ( ! isset( $map[ $which ] ) ) {
		return home_url( '/' );
	}
	$cached = get_transient( 'meeha_account_page_' . $which );
	if ( false !== $cached ) {
		return $cached;
	}
	global $wpdb;
	$url = home_url( '/' );
	$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_content LIKE %s ORDER BY menu_order ASC, post_date DESC",
			'%' . $wpdb->esc_like( '[' . $map[ $which ] ) . '%'
		)
	);
	foreach ( (array) $ids as $id ) {
		$p = get_post( (int) $id );
		if ( $p && has_shortcode( $p->post_content, $map[ $which ] ) ) {
			$url = get_permalink( $p );
			break;
		}
	}
	set_transient( 'meeha_account_page_' . $which, $url, HOUR_IN_SECONDS );
	return $url;
}

/* --------------------------------------------------------------------------
 * New helpers (2.0)
 * ------------------------------------------------------------------------ */

/**
 * Short Bengali count: ৯৮৭, ২.৪ হাজার, ১২ হাজার, ১.২ লাখ, ৩.৫ কোটি.
 *
 * @param int $n Number.
 * @return string
 */
function meeha_bn_number_short( $n ) {
	$n   = (int) $n;
	$neg = $n < 0 ? '-' : '';
	$n   = abs( $n );
	$fmt = function ( $v ) {
		$s = number_format( $v, 1, '.', '' );
		$s = preg_replace( '/\.0$/', '', $s );
		return meeha_bn_digits( $s );
	};
	if ( $n < 1000 ) {
		return $neg . meeha_bn_digits( $n );
	}
	if ( $n < 100000 ) {
		$v = $n / 1000;
		return $neg . ( $v >= 10 ? meeha_bn_digits( (int) floor( $v ) ) : $fmt( floor( $v * 10 ) / 10 ) ) . ' হাজার';
	}
	if ( $n < 10000000 ) {
		$v = $n / 100000;
		return $neg . ( $v >= 10 ? meeha_bn_digits( (int) floor( $v ) ) : $fmt( floor( $v * 10 ) / 10 ) ) . ' লাখ';
	}
	$v = $n / 10000000;
	return $neg . ( $v >= 10 ? meeha_bn_digits( (int) floor( $v ) ) : $fmt( floor( $v * 10 ) / 10 ) ) . ' কোটি';
}

/**
 * Image roles → candidate sizes (best first) and the `sizes` attribute.
 *
 * @return array
 */
function meeha_img_roles() {
	return apply_filters(
		'meeha_img_roles',
		array(
			'lead'     => array(
				'sizes'  => array( 'meeha-16x9', 'large', 'medium_large', 'full' ),
				'attr'   => '(min-width: 1280px) 620px, (min-width: 1024px) 50vw, 100vw',
				'eager'  => true,
			),
			'card-lg'  => array(
				'sizes' => array( 'meeha-16x9-m', 'meeha-16x9', 'medium_large', 'large', 'full' ),
				'attr'  => '(min-width: 1024px) 560px, (min-width: 600px) 50vw, 100vw',
			),
			'card'     => array(
				'sizes' => array( 'meeha-16x9-s', 'meeha-16x9-m', 'medium_large', 'medium', 'large', 'full' ),
				'attr'  => '(min-width: 1024px) 300px, (min-width: 600px) 50vw, 100vw',
			),
			'thumb'    => array(
				'sizes' => array( 'meeha-16x9-s', 'medium', 'medium_large', 'thumbnail', 'full' ),
				'attr'  => '(min-width: 768px) 120px, 112px',
			),
			'square'   => array(
				'sizes' => array( 'meeha-sq', 'thumbnail', 'medium', 'full' ),
				'attr'  => '96px',
			),
			'portrait' => array(
				'sizes' => array( 'meeha-9x16', 'medium_large', 'large', 'medium', 'full' ),
				'attr'  => '(min-width: 1024px) 200px, 46vw',
			),
			'full'     => array(
				'sizes' => array( 'large', 'meeha-16x9', 'medium_large', 'full' ),
				'attr'  => '(min-width: 840px) 800px, 100vw',
			),
		)
	);
}

/**
 * Pick the best size that actually exists for an attachment.
 *
 * @param int    $attachment_id Attachment.
 * @param string $role          Role.
 * @return string Size name.
 */
function meeha_img_best_size( $attachment_id, $role ) {
	$roles = meeha_img_roles();
	$def   = isset( $roles[ $role ] ) ? $roles[ $role ] : $roles['card'];
	$meta  = wp_get_attachment_metadata( $attachment_id );
	$have  = ( is_array( $meta ) && ! empty( $meta['sizes'] ) ) ? $meta['sizes'] : array();
	foreach ( $def['sizes'] as $size ) {
		if ( 'full' === $size || isset( $have[ $size ] ) ) {
			return $size;
		}
	}
	return 'full';
}

/**
 * Responsive <img> for a role. Always width/height + srcset/sizes; lazy + async except the lead.
 *
 * @param int    $attachment_id Attachment ID.
 * @param string $role          lead|card-lg|card|thumb|square|portrait|full.
 * @param array  $attr          Extra attributes (alt, class, sizes, eager => true, loading, fetchpriority…).
 * @return string '' when there is no image.
 */
function meeha_img( $attachment_id, $role = 'card', $attr = array() ) {
	$attachment_id = (int) $attachment_id;
	if ( ! $attachment_id || ! wp_attachment_is_image( $attachment_id ) ) {
		return '';
	}
	$roles = meeha_img_roles();
	$def   = isset( $roles[ $role ] ) ? $roles[ $role ] : $roles['card'];
	$size  = meeha_img_best_size( $attachment_id, $role );
	$attr  = (array) $attr;
	$eager = ! empty( $def['eager'] ) || ! empty( $attr['eager'] );
	unset( $attr['eager'] );

	$out = array(
		'sizes' => $def['attr'],
		'alt'   => '',
	);
	if ( 'lead' === $role ) {
		$alt        = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		$out['alt'] = $alt ? $alt : '';
	}
	if ( $eager ) {
		$out['loading']  = false;
		$out['decoding'] = 'async';
		if ( 'lead' === $role ) {
			$out['fetchpriority'] = 'high';
			// The LCP image must be painted in the first frame: async decoding lets Chrome present the frame
			// without it (checker-imaged) and swap it in later, which delays LCP.
			$out['decoding'] = 'sync';
		}
	} else {
		$out['loading']  = 'lazy';
		$out['decoding'] = 'async';
	}
	$out = array_merge( $out, $attr );
	$html = wp_get_attachment_image( $attachment_id, $size, false, $out );
	return (string) apply_filters( 'meeha_img_html', $html, $attachment_id, $role, $attr );
}

/**
 * Featured image of a post for a role, or ''.
 *
 * @param WP_Post|int $post Post.
 * @param string      $role Role.
 * @param array       $attr Attributes.
 * @return string
 */
function meeha_post_img( $post, $role = 'card', $attr = array() ) {
	$post = get_post( $post );
	if ( ! $post ) {
		return '';
	}
	$id = (int) apply_filters( 'meeha_post_img_id', (int) get_post_thumbnail_id( $post ), $post, $role );
	return $id ? meeha_img( $id, $role, $attr ) : '';
}

/**
 * Queue a `<link rel="preload" as="image">` for an image (call before get_header()).
 *
 * @param int    $attachment_id Attachment.
 * @param string $role          Role.
 */
function meeha_preload_img( $attachment_id, $role = 'lead' ) {
	$attachment_id = (int) $attachment_id;
	if ( ! $attachment_id || did_action( 'wp_head' ) ) {
		return;
	}
	$size = meeha_img_best_size( $attachment_id, $role );
	$src  = wp_get_attachment_image_src( $attachment_id, $size );
	if ( ! $src ) {
		return;
	}
	$roles  = meeha_img_roles();
	$def    = isset( $roles[ $role ] ) ? $roles[ $role ] : $roles['card'];
	$srcset = wp_get_attachment_image_srcset( $attachment_id, $size );
	$GLOBALS['meeha_preload_images'][ $attachment_id ] = array( $src[0], (string) $srcset, $def['attr'] );
}

add_action(
	'wp_head',
	function () {
		if ( empty( $GLOBALS['meeha_preload_images'] ) ) {
			return;
		}
		foreach ( $GLOBALS['meeha_preload_images'] as $p ) {
			printf(
				'<link rel="preload" as="image" href="%s"%s%s fetchpriority="high">' . "\n",
				esc_url( $p[0] ),
				$p[1] ? ' imagesrcset="' . esc_attr( $p[1] ) . '"' : '',
				$p[1] ? ' imagesizes="' . esc_attr( $p[2] ) . '"' : ''
			);
		}
	},
	2
);

/**
 * Primary category: Rank Math primary (`rank_math_primary_category`) if valid, else the first
 * category that is not the default "uncategorized" one.
 *
 * @param WP_Post|int $post Post.
 * @return WP_Term|null
 */
function meeha_primary_category( $post ) {
	$post = get_post( $post );
	if ( ! $post || ! is_object_in_taxonomy( $post->post_type, 'category' ) ) {
		return null;
	}
	$primary = (int) get_post_meta( $post->ID, 'rank_math_primary_category', true );
	if ( ! $primary ) {
		$primary = (int) get_post_meta( $post->ID, '_yoast_wpseo_primary_category', true );
	}
	$cats = get_the_category( $post->ID );
	if ( $primary ) {
		foreach ( (array) $cats as $c ) {
			if ( (int) $c->term_id === $primary ) {
				return $c;
			}
		}
	}
	foreach ( (array) $cats as $c ) {
		if ( 'uncategorized' !== $c->slug ) {
			return $c;
		}
	}
	return null;
}

/**
 * Kicker text: `_meeha_topic` else primary category name.
 *
 * @param WP_Post|int $post Post.
 * @return string
 */
function meeha_post_kicker( $post ) {
	$post = get_post( $post );
	if ( ! $post ) {
		return '';
	}
	$topic = trim( (string) get_post_meta( $post->ID, '_meeha_topic', true ) );
	if ( '' !== $topic ) {
		return $topic;
	}
	$cat = meeha_primary_category( $post );
	return $cat ? $cat->name : '';
}

/**
 * Investigative: `_meeha_investigation` = 1 or filed under category slug `search` (অনুসন্ধান).
 *
 * @param WP_Post|int $post Post.
 * @return bool
 */
function meeha_is_investigative( $post ) {
	$post = get_post( $post );
	if ( ! $post ) {
		return false;
	}
	if ( '1' === (string) get_post_meta( $post->ID, '_meeha_investigation', true ) ) {
		return true;
	}
	return 'post' === $post->post_type && has_term( 'search', 'category', $post );
}

/**
 * Whether a post sits in a category (by slug) or any of its descendants.
 *
 * @param WP_Post|int $post Post.
 * @param string[]    $slugs Category slugs.
 * @return bool
 */
function meeha_in_category_tree( $post, $slugs ) {
	$post = get_post( $post );
	if ( ! $post ) {
		return false;
	}
	$ids = array();
	foreach ( (array) $slugs as $slug ) {
		$t = get_category_by_slug( $slug );
		if ( $t ) {
			$ids[] = (int) $t->term_id;
			$ids   = array_merge( $ids, array_map( 'intval', get_term_children( $t->term_id, 'category' ) ) );
		}
	}
	if ( ! $ids ) {
		return false;
	}
	$own = wp_get_post_categories( $post->ID );
	return (bool) array_intersect( $ids, array_map( 'intval', $own ) );
}

/**
 * Content-type label: খবর / মতামত / ভিডিও / উক্তি / সাহিত্য.
 *
 * @param WP_Post|int $post Post.
 * @return string
 */
function meeha_post_type_label( $post ) {
	$post = get_post( $post );
	if ( ! $post ) {
		return '';
	}
	$label = 'খবর';
	if ( in_array( $post->post_type, array( 'meeha_video' ), true ) ) {
		$label = 'ভিডিও';
	} elseif ( in_array( $post->post_type, array( 'meeha_photocard', 'meeha_quote' ), true ) ) {
		$label = 'উক্তি';
	} elseif ( 'page' === $post->post_type ) {
		$label = 'পাতা';
	} elseif ( 'post' === $post->post_type ) {
		if ( meeha_in_category_tree( $post, apply_filters( 'meeha_literature_category_slugs', array( 'bengali-culture' ) ) ) ) {
			$label = 'সাহিত্য';
		} elseif ( 'aside' === get_post_format( $post ) || meeha_in_category_tree( $post, apply_filters( 'meeha_opinion_category_slugs', array( 'editorial', 'sub-editorial', 'readers-words' ) ) ) ) {
			$label = 'মতামত';
		}
	}
	return (string) apply_filters( 'meeha_post_type_label', $label, $post );
}

/**
 * Author designation (user meta `meeha_author_title`).
 *
 * @param int $user_id User.
 * @return string
 */
function meeha_author_designation( $user_id ) {
	return trim( (string) get_user_meta( (int) $user_id, 'meeha_author_title', true ) );
}

/**
 * "১২ মিনিটে পড়ুন".
 *
 * @param WP_Post|int $post Post.
 * @return string
 */
function meeha_reading_time_label( $post ) {
	$post = get_post( $post );
	if ( ! $post ) {
		return '';
	}
	return meeha_bn_digits( meeha_reading_time( $post->post_content ) ) . ' মিনিটে পড়ুন';
}

/**
 * Bengali clock time "১০:৪২" (24 h) for a post or timestamp.
 *
 * @param WP_Post|int|null $post Post.
 * @return string
 */
function meeha_post_clock( $post = null ) {
	$post = get_post( $post );
	return $post ? meeha_bn_digits( get_post_time( 'H:i', false, $post ) ) : '';
}

/**
 * Trimmed plain-text dek for a post: summary → excerpt → content.
 *
 * @param WP_Post|int $post  Post.
 * @param int         $words Word limit.
 * @return string
 */
function meeha_post_dek( $post, $words = 30 ) {
	$post = get_post( $post );
	if ( ! $post || '' !== (string) $post->post_password ) {
		return ''; // password-protected posts never expose summary/excerpt/content in listings.
	}
	$text = (string) meeha_get_summary( $post->ID );
	if ( '' === trim( $text ) ) {
		$text = has_excerpt( $post ) ? $post->post_excerpt : strip_shortcodes( $post->post_content );
	}
	return wp_trim_words( wp_strip_all_tags( $text ), $words, '…' );
}

/**
 * Cheap "is this request a REST/AJAX/CLI request" check.
 *
 * @return bool
 */
function meeha_is_backend_request() {
	return is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI );
}
