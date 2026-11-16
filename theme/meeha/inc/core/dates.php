<?php
/**
 * Dates: weekday, Gregorian, Bangla (Bangladesh revised calendar) and Hijri, all in Bengali.
 *
 * Bangla calendar (Bangladesh, revised 2019): 1 বৈশাখ = 14 April; বৈশাখ–আশ্বিন 31 days; কার্তিক–মাঘ 30;
 * ফাল্গুন 29, or 30 when the Gregorian year it falls in is a leap year; চৈত্র 30.
 *
 * Hijri: ICU `islamic-umalqura` (IntlDateFormatter) shifted by MEEHA_HIJRI_BASE_SHIFT (default −1 day,
 * because Bangladesh's moon-sighting calendar normally runs one day behind Umm al-Qura — verified at the
 * contract fixed point: 2026-10-05 is 24 Rabi' II 1448 in Umm al-Qura and ২৩ রবিউস সানি ১৪৪৮ in Bangladesh),
 * plus the editor's day offset (header setting `hijri_offset`, −1/0/+1, default 0).
 * Without the intl extension an embedded Umm al-Qura month table (1440–1480 AH) gives identical results;
 * outside that range a tabular (civil, Kuwaiti) algorithm is used (can be 1–2 days off).
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'MEEHA_HIJRI_BASE_SHIFT' ) ) {
	define( 'MEEHA_HIJRI_BASE_SHIFT', -1 );
}

/**
 * Bengali weekday names (0 = Sunday).
 *
 * @return string[]
 */
function meeha_bn_weekdays() {
	return array( 'রবিবার', 'সোমবার', 'মঙ্গলবার', 'বুধবার', 'বৃহস্পতিবার', 'শুক্রবার', 'শনিবার' );
}

/**
 * Bangla calendar month names (index 0 = বৈশাখ).
 *
 * @return string[]
 */
function meeha_bangla_months() {
	return array( 'বৈশাখ', 'জ্যৈষ্ঠ', 'আষাঢ়', 'শ্রাবণ', 'ভাদ্র', 'আশ্বিন', 'কার্তিক', 'অগ্রহায়ণ', 'পৌষ', 'মাঘ', 'ফাল্গুন', 'চৈত্র' );
}

/**
 * Hijri month names in Bengali (index 0 = Muharram).
 *
 * @return string[]
 */
function meeha_hijri_months() {
	return array( 'মুহাররম', 'সফর', 'রবিউল আউয়াল', 'রবিউস সানি', 'জমাদিউল আউয়াল', 'জমাদিউস সানি', 'রজব', 'শাবান', 'রমজান', 'শাওয়াল', 'জিলকদ', 'জিলহজ' );
}

/**
 * Julian Day Number for a proleptic Gregorian date (integer, noon based).
 *
 * @param int $y Year.
 * @param int $m Month.
 * @param int $d Day.
 * @return int
 */
function meeha_jdn( $y, $m, $d ) {
	$a  = intdiv( 14 - $m, 12 );
	$y2 = $y + 4800 - $a;
	$m2 = $m + 12 * $a - 3;
	return $d + intdiv( 153 * $m2 + 2, 5 ) + 365 * $y2 + intdiv( $y2, 4 ) - intdiv( $y2, 100 ) + intdiv( $y2, 400 ) - 32045;
}

/**
 * Gregorian [y, m, d] from a Julian Day Number.
 *
 * @param int $jdn JDN.
 * @return int[]
 */
function meeha_jdn_to_gregorian( $jdn ) {
	$a = $jdn + 32044;
	$b = intdiv( 4 * $a + 3, 146097 );
	$c = $a - intdiv( 146097 * $b, 4 );
	$d = intdiv( 4 * $c + 3, 1461 );
	$e = $c - intdiv( 1461 * $d, 4 );
	$m = intdiv( 5 * $e + 2, 153 );
	return array( 100 * $b + $d - 4800 + intdiv( $m, 10 ), $m + 3 - 12 * intdiv( $m, 10 ), $e - intdiv( 153 * $m + 2, 5 ) + 1 );
}

/**
 * Bangladesh revised Bangla calendar.
 *
 * @param int $y Gregorian year.
 * @param int $m Gregorian month.
 * @param int $d Gregorian day.
 * @return array [day(int), month_index(0-11), year(int)]
 */
function meeha_bangla_parts( $y, $m, $d ) {
	$start_year = ( $m > 4 || ( 4 === (int) $m && $d >= 14 ) ) ? $y : $y - 1;
	$by         = $start_year - 593;
	$offset     = meeha_jdn( $y, $m, $d ) - meeha_jdn( $start_year, 4, 14 );
	$g_next     = $start_year + 1; // Gregorian year in which ফাল্গুন falls.
	$leap       = ( 0 === $g_next % 4 && 0 !== $g_next % 100 ) || 0 === $g_next % 400;
	$lengths    = array( 31, 31, 31, 31, 31, 31, 30, 30, 30, 30, $leap ? 30 : 29, 30 );
	foreach ( $lengths as $i => $len ) {
		if ( $offset < $len ) {
			return array( $offset + 1, $i, $by );
		}
		$offset -= $len;
	}
	return array( $offset + 1, 11, $by ); // unreachable.
}

/**
 * Tabular Islamic (civil) date from a JDN.
 *
 * @param int $jdn JDN.
 * @return int[] [d, m(1-12), y]
 */
function meeha_hijri_civil_from_jdn( $jdn ) {
	$l = $jdn - 1948440 + 10632;
	$n = intdiv( $l - 1, 10631 );
	$l = $l - 10631 * $n + 354;
	$j = intdiv( 10985 - $l, 5316 ) * intdiv( 50 * $l, 17719 ) + intdiv( $l, 5670 ) * intdiv( 43 * $l, 15238 );
	$l = $l - intdiv( 30 - $j, 15 ) * intdiv( 17719 * $j, 50 ) - intdiv( $j, 16 ) * intdiv( 15238 * $j, 43 ) + 29;
	$m = intdiv( 24 * $l, 709 );
	$d = $l - intdiv( 709 * $m, 24 );
	$y = 30 * $n + $j - 30;
	return array( $d, $m, $y );
}

/**
 * Hijri date (Bangladesh) for a Gregorian date.
 *
 * @param int  $y       Year.
 * @param int  $m       Month.
 * @param int  $d       Day.
 * @param int  $offset  Editor offset in days (−1/0/+1).
 * @param bool $use_intl Use ICU when available (tests pass false to exercise the fallback).
 * @return array [day(int), month_index(0-11), year(int)]
 */
function meeha_hijri_parts( $y, $m, $d, $offset = 0, $use_intl = true ) {
	$shift = (int) apply_filters( 'meeha_hijri_base_shift', MEEHA_HIJRI_BASE_SHIFT ) + (int) $offset;
	$jdn   = meeha_jdn( $y, $m, $d ) + $shift;
	if ( $use_intl && class_exists( 'IntlDateFormatter' ) ) {
		list( $gy, $gm, $gd ) = meeha_jdn_to_gregorian( $jdn );
		static $fmt = null;
		if ( null === $fmt ) {
			$fmt = new IntlDateFormatter( 'en_US@calendar=islamic-umalqura', IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'UTC', IntlDateFormatter::TRADITIONAL, 'd|M|y' );
		}
		$dt  = new DateTime( sprintf( '%04d-%02d-%02d 12:00:00', $gy, $gm, $gd ), new DateTimeZone( 'UTC' ) );
		$out = $fmt ? $fmt->format( $dt ) : false;
		if ( $out && preg_match( '/^(\d+)\|(\d+)\|(\d+)$/', $out, $mm ) ) {
			return array( (int) $mm[1], (int) $mm[2] - 1, (int) $mm[3] );
		}
	}
	// Fallback 1: embedded Umm al-Qura month table (1440–1480 AH ≈ 2018–2058, generated from ICU 74).
	$uq = meeha_hijri_uq_table( $jdn );
	if ( $uq ) {
		return $uq;
	}
	// Fallback 2: tabular civil calendar (± 1–2 days from the observed calendar).
	list( $hd, $hm, $hy ) = meeha_hijri_civil_from_jdn( $jdn + (int) apply_filters( 'meeha_hijri_civil_shift', 1 ) );
	return array( $hd, $hm - 1, $hy );
}

/**
 * Umm al-Qura lookup from an embedded month-length table (no intl needed).
 *
 * @param int $jdn JDN.
 * @return array|null [d, month_index, y] or null when out of range.
 */
function meeha_hijri_uq_table( $jdn ) {
	// 1 Muharram 1440 = JDN 2458373 (2018-09-11). One char per month: 1 = 30 days, 0 = 29 days.
	static $first = 2458373;
	static $bits  = '010111010100101011011010010101011010101010101011010110010101011101001001011101100100101110101010010110110101001010110110101001010110111001001101101100100101101101010010101101101010010110101101001010101110100100101111010010010111011001001011011010100101011010101100101011010110010101011101010010011101101001001101110100010110110110010101010110101010010110110101001011011010100101011011010010101101010110010101011011001010011011100100101011101010010011110101001010110110100101010110101010101010';
	if ( $jdn < $first ) {
		return null;
	}
	$start = $first;
	$len   = strlen( $bits );
	for ( $i = 0; $i < $len; $i++ ) {
		$days = '1' === $bits[ $i ] ? 30 : 29;
		if ( $jdn < $start + $days ) {
			return array( $jdn - $start + 1, $i % 12, 1440 + intdiv( $i, 12 ) );
		}
		$start += $days;
	}
	return null;
}

/**
 * All the date strings the header, drawer and photo-card studio need.
 *
 * @param int|string|null $ts           Timestamp, 'YYYY-MM-DD', any strtotime() string, or null (now).
 * @param int|null        $hijri_offset Day offset; null = header setting `hijri_offset`.
 * @return array
 */
function meeha_dates( $ts = null, $hijri_offset = null ) {
	$tz = new DateTimeZone( 'Asia/Dhaka' );
	if ( null === $ts || '' === $ts ) {
		$dt = new DateTime( 'now', $tz );
	} elseif ( is_int( $ts ) || ( is_string( $ts ) && ctype_digit( $ts ) && strlen( $ts ) > 8 ) ) {
		$dt = new DateTime( '@' . (int) $ts );
		$dt->setTimezone( $tz );
	} elseif ( is_string( $ts ) && preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $ts ) ) {
		$dt = new DateTime( $ts . ' 12:00:00', $tz );
	} else {
		try {
			$dt = new DateTime( (string) $ts, $tz );
		} catch ( Exception $e ) {
			$dt = new DateTime( 'now', $tz );
		}
	}
	if ( null === $hijri_offset ) {
		$hijri_offset = function_exists( 'meeha_opt' ) ? (int) meeha_opt( 'header', 'hijri_offset', 0 ) : 0;
	}
	$hijri_offset = max( -2, min( 2, (int) $hijri_offset ) );

	$y   = (int) $dt->format( 'Y' );
	$m   = (int) $dt->format( 'n' );
	$d   = (int) $dt->format( 'j' );
	$key = sprintf( '%04d%02d%02d|%d', $y, $m, $d, $hijri_offset );

	static $cache = array();
	if ( isset( $cache[ $key ] ) ) {
		return $cache[ $key ];
	}

	$weekdays = meeha_bn_weekdays();
	$bmonths  = meeha_bangla_months();
	$hmonths  = meeha_hijri_months();

	list( $bd, $bm, $by ) = meeha_bangla_parts( $y, $m, $d );
	list( $hd, $hm, $hy ) = meeha_hijri_parts( $y, $m, $d, $hijri_offset );

	$out = array(
		'weekday'     => $weekdays[ (int) $dt->format( 'w' ) ],
		'gregorian'   => meeha_bn_digits( $d ) . ' ' . meeha_bengali_month( $m ) . ' ' . meeha_bn_digits( $y ),
		'bangla'      => meeha_bn_digits( $bd ) . ' ' . $bmonths[ $bm ] . ' ' . meeha_bn_digits( $by ),
		'hijri'       => meeha_bn_digits( $hd ) . ' ' . $hmonths[ $hm ] . ' ' . meeha_bn_digits( $hy ),
		'bangla_parts'=> array( $bd, $bmonths[ $bm ], $by ),
		'hijri_parts' => array( $hd, $hmonths[ $hm ], $hy ),
		'iso'         => $dt->format( 'Y-m-d' ),
	);
	$cache[ $key ] = $out;
	return $out;
}

/*
 * REST: GET meeha/v1/dates?d=YYYY-MM-DD&offset=-1|0|1  (cap edit_posts) — used by the photo-card studio.
 */
add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'meeha/v1',
			'/dates',
			array(
				'methods'             => 'GET',
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
				'args'                => array(
					'd'      => array(
						'type'              => 'string',
						'required'          => false,
						'validate_callback' => function ( $v ) {
							return '' === $v || (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $v );
						},
					),
					'offset' => array(
						'type'    => 'integer',
						'minimum' => -2,
						'maximum' => 2,
					),
				),
				'callback'            => function ( WP_REST_Request $r ) {
					$d      = (string) $r->get_param( 'd' );
					$offset = $r->get_param( 'offset' );
					return rest_ensure_response( meeha_dates( '' === $d ? null : $d, null === $offset ? null : (int) $offset ) );
				},
			)
		);
	}
);
