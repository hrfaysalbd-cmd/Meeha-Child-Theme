<?php
/**
 * Template tags shared by the plugin views and the theme's section home (job card, deadline pill, test row,
 * track tile). All output is escaped here; callers echo the returned strings.
 *
 * @package meeha-prep
 */

defined( 'ABSPATH' ) || exit;

/**
 * Deadline pill.
 *
 * @param int|null $days Days left.
 * @return string
 */
function mhp_dl_pill( $days ) {
	if ( null === $days ) {
		return '';
	}
	if ( $days < 0 ) {
		return '<span class="mc-pill mc-pill--line">শেষ হয়েছে</span>';
	}
	if ( $days <= 7 ) {
		return '<span class="mc-pill mc-pill--bad">' . mhp_icon( 'clock' ) . ' ' . esc_html( 0 === $days ? 'আজ শেষ' : mhp_bn( $days ) . ' দিন বাকি' ) . '</span>';
	}
	return '<span class="mc-pill mc-pill--brand">' . esc_html( mhp_bn( $days ) . ' দিন বাকি' ) . '</span>';
}

/**
 * Save-job button (works without JS through the hub form).
 *
 * @param int  $job_id Job.
 * @param bool $saved  Saved.
 * @param bool $icon_only Icon button.
 * @return string
 */
function mhp_save_button( $job_id, $saved, $icon_only = true ) {
	if ( ! is_user_logged_in() ) {
		return '<a class="mc-ib" href="' . esc_url( mhp_login_url( mhp_url( 'job', $job_id ) ) ) . '" aria-label="সংরক্ষণ করতে লগইন করুন">' . mhp_icon( 'mark' ) . '</a>';
	}
	$out  = '<form method="post" class="mc-inline" data-mhp-save="' . (int) $job_id . '">';
	$out .= MHP_Front::form_fields( 'save_job' );
	$out .= '<input type="hidden" name="job" value="' . (int) $job_id . '">';
	$out .= '<input type="hidden" name="back" value="' . esc_attr( mhp_current_url() ) . '">';
	$out .= '<button type="submit" class="' . ( $icon_only ? 'mc-ib' : 'mc-btn mc-btn--ghost mc-btn--sm mc-grow' ) . ( $saved ? ' is-on' : '' ) . '" aria-pressed="' . ( $saved ? 'true' : 'false' ) . '" aria-label="' . esc_attr( $saved ? 'সংরক্ষিত (সরাতে চাপুন)' : 'সংরক্ষণ করুন' ) . '">' . mhp_icon( 'mark' ) . ( $icon_only ? '' : ' <span>' . esc_html( $saved ? 'সংরক্ষিত' : 'সংরক্ষণ' ) . '</span>' ) . '</button>';
	$out .= '</form>';
	return $out;
}

/**
 * Job card.
 *
 * @param array $j     Job data.
 * @param bool  $saved Saved by the viewer.
 * @return string
 */
function mhp_job_card( $j, $saved = false ) {
	$posted = strtotime( $j['posted'] );
	$dl     = mhp_is_date( $j['deadline'] ) ? strtotime( $j['deadline'] ) : 0;
	$pct    = 0;
	if ( $dl && $posted && $dl > $posted ) {
		$pct = (int) round( min( 1, max( 0, ( time() - $posted ) / ( $dl + DAY_IN_SECONDS - $posted ) ) ) * 100 );
	}
	$url   = mhp_url( 'job', $j['id'] );
	$facts = array();
	if ( $j['featured'] ) {
		$facts[] = '<span class="mc-pill mc-pill--gold">' . mhp_icon( 'star' ) . ' ফিচার্ড</span>';
	}
	if ( $j['location'] ) {
		$facts[] = '<span>' . mhp_icon( 'pin' ) . ' ' . esc_html( $j['location'] ) . '</span>';
	}
	if ( $j['education'] ) {
		$facts[] = '<span>' . mhp_icon( 'grad' ) . ' ' . esc_html( $j['education'] ) . '</span>';
	}
	if ( $j['vacancies'] ) {
		$facts[] = '<span>' . esc_html( mhp_num( $j['vacancies'] ) ) . 'টি পদ</span>';
	}
	if ( $j['salary'] ) {
		$facts[] = '<span>' . esc_html( $j['salary'] ) . '</span>';
	}
	$out  = '<article class="mc-card mc-job' . ( $j['featured'] ? ' mc-job--feat' : '' ) . '">';
	$out .= '<div class="mc-job__org"><span class="mc-mono" aria-hidden="true">' . esc_html( mhp_initial( $j['org'] ? $j['org'] : $j['title'] ) ) . '</span>';
	$out .= '<div class="mc-grow"><div class="mc-xs mc-muted">' . esc_html( $j['org'] ) . '</div><h3 class="mc-job__title"><a href="' . esc_url( $url ) . '">' . esc_html( $j['title'] ) . '</a></h3></div>';
	$out .= mhp_save_button( $j['id'], $saved ) . '</div>';
	if ( $facts ) {
		$out .= '<div class="mc-job__facts">' . implode( '', $facts ) . '</div>';
	}
	if ( $dl ) {
		$out .= '<div class="mc-bar" aria-hidden="true"><i style="width:' . (int) $pct . '%"></i></div>';
		$out .= '<div class="mc-job__dl"><span class="mc-xs mc-muted">শেষ তারিখ ' . esc_html( mhp_fdate( $j['deadline'] ) ) . '</span>' . mhp_dl_pill( $j['days_left'] ) . '</div>';
	}
	$out .= '<a class="mc-btn mc-btn--soft mc-btn--sm" href="' . esc_url( $url ) . '">বিস্তারিত দেখুন ' . mhp_icon( 'chev' ) . '</a>';
	$out .= '</article>';
	return $out;
}

/**
 * Track tile.
 *
 * @param array $t Track.
 * @return string
 */
function mhp_track_tile( $t ) {
	$n    = MHP_Tests::track_takers( $t['id'] );
	$min  = max( 1, (int) mhp_opt( 'count_min', 20 ) );
	$out  = '<a class="mc-card mc-track" href="' . esc_url( mhp_url( 'track', $t['slug'] ) ) . '" style="--tc:' . esc_attr( $t['color'] ) . '">';
	$out .= '<span class="mc-track__ic" aria-hidden="true">' . esc_html( $t['glyph'] ? $t['glyph'] : mhp_initial( $t['name'] ) ) . '</span>';
	$out .= '<h3 class="mc-track__name">' . esc_html( $t['name'] ) . ( $t['is_new'] ? ' <span class="mc-pill mc-pill--gold">নতুন</span>' : '' ) . '</h3>';
	if ( $t['desc'] ) {
		$out .= '<span class="mc-xs mc-muted">' . esc_html( $t['desc'] ) . '</span>';
	}
	if ( $n >= $min ) {
		$out .= '<span class="mc-xs mc-muted">' . esc_html( mhp_num( $n ) ) . ' জন টেস্ট দিয়েছেন</span>';
	}
	$out .= '</a>';
	return $out;
}

/**
 * Test row.
 *
 * @param array $t   Test info.
 * @param int   $uid Viewer.
 * @return string
 */
function mhp_test_row( $t, $uid ) {
	$own  = MHP_Access::can_take_test( $uid, $t['id'] );
	$last = MHP_Tests::last_result( $uid, $t['id'] );
	$meta = mhp_bn( $t['count'] ) . ' প্রশ্ন · ' . mhp_bn( $t['minutes'] ) . ' মিনিট';
	if ( $t['negative'] > 0 ) {
		$meta .= ' · ভুলে −' . mhp_score( $t['negative'] );
	}
	$out  = '<div class="mc-card mc-test">';
	$out .= '<div class="mc-grow"><h3 class="mc-test__title"><a href="' . esc_url( mhp_url( 'test', $t['id'] ) ) . '">' . esc_html( $t['title'] ) . '</a>';
	if ( $t['live'] ) {
		$out .= ' <span class="mc-pill mc-pill--bad">লাইভ</span>';
	}
	$out .= '</h3><div class="mc-xs mc-muted">' . esc_html( $meta ) . '</div>';
	if ( $t['live'] && $t['start'] ) {
		$out .= '<div class="mc-xs mc-muted">' . esc_html( ( 'upcoming' === $t['state'] ? 'শুরু: ' : 'শুরু হয়েছিল: ' ) . mhp_fdatetime( MHP_Tests::norm_dt( $t['start'] ) ) ) . '</div>';
	}
	if ( $t['full_note'] ) {
		$out .= '<div class="mc-xs mc-muted">' . esc_html( $t['full_note'] ) . '</div>';
	}
	if ( $last ) {
		$out .= '<div class="mc-xs mc-ok">শেষ ফল: ' . esc_html( mhp_score( $last['score'] ) . '/' . mhp_bn( $last['total'] ) ) . '</div>';
	}
	$out .= '</div><div class="mc-test__side">';
	if ( $t['price'] <= 0 ) {
		$out .= '<div class="mc-free">ফ্রি</div>';
	} elseif ( $own ) {
		$out .= '<span class="mc-pill mc-pill--ok">কেনা আছে</span>';
	} else {
		$out .= '<div class="mc-price">' . esc_html( mhp_tk( $t['price'] ) ) . '</div>';
	}
	$out .= '<a class="mc-btn mc-btn--sm' . ( $own ? '' : ' mc-btn--ghost' ) . '" href="' . esc_url( mhp_url( 'test', $t['id'] ) ) . '">' . esc_html( $own ? 'শুরু' : 'দেখুন' ) . '</a>';
	$out .= '</div></div>';
	return $out;
}

/**
 * Product card (bundle / download / service).
 *
 * @param WP_Post $p   Product.
 * @param int     $uid Viewer.
 * @return string
 */
function mhp_product_card( $p, $uid ) {
	$price = (float) mhp_meta( $p->ID, 'price', 0 );
	$type  = (string) mhp_meta( $p->ID, 'ptype', 'bundle' );
	$own   = $uid && MHP_Access::has( $uid, 'product', $p->ID );
	$icon  = 'download' === $type ? 'doc' : ( 'service' === $type ? 'brief' : 'book' );
	$out   = '<div class="mc-card mc-test' . ( 'bundle' === $type ? ' mc-test--pack' : '' ) . '">';
	$out  .= '<span class="mc-mono" aria-hidden="true">' . mhp_icon( $icon ) . '</span>';
	$out  .= '<div class="mc-grow"><h3 class="mc-test__title">' . esc_html( get_the_title( $p ) ) . '</h3>';
	if ( $p->post_excerpt ) {
		$out .= '<div class="mc-xs mc-muted">' . esc_html( $p->post_excerpt ) . '</div>';
	}
	if ( 'bundle' === $type ) {
		$n = count( array_filter( (array) mhp_meta( $p->ID, 'tests', array() ) ) );
		if ( $n ) {
			$out .= '<div class="mc-xs mc-muted">' . esc_html( mhp_bn( $n ) ) . 'টি টেস্ট · একবার কিনলে অ্যাকাউন্টে থাকবে</div>';
		}
	}
	$out .= '</div><div class="mc-test__side">';
	if ( $own ) {
		$out .= '<span class="mc-pill mc-pill--ok">কেনা আছে</span>';
		if ( 'download' === $type ) {
			$out .= '<a class="mc-btn mc-btn--sm" href="' . esc_url( mhp_url( 'download', $p->ID ) ) . '">ডাউনলোড</a>';
		}
	} else {
		$out .= '<div class="mc-price">' . esc_html( mhp_tk( $price ) ) . '</div>';
		$out .= '<a class="mc-btn mc-btn--sm mc-btn--ghost" href="' . esc_url( mhp_url( 'checkout', 'product-' . $p->ID ) ) . '">কিনুন</a>';
	}
	$out .= '</div></div>';
	return $out;
}

/**
 * Seconds → "১:০৮".
 *
 * @param int $s Seconds.
 * @return string
 */
function mhp_mmss( $s ) {
	$s = max( 0, (int) $s );
	return mhp_bn( floor( $s / 60 ) . ':' . str_pad( (string) ( $s % 60 ), 2, '0', STR_PAD_LEFT ) );
}
