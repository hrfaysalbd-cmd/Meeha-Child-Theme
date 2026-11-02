<?php
/**
 * দেশচিত্র — configuration: the 64 districts (keys match assets/js/deshchitro-map.js), divisions, the incident
 * indicators (per district) and economy indicators (national). Editors can rename / switch off / add indicators
 * on the "দেশচিত্র → সূচক ও সেটিংস" screen; keys never change once data exists, so old days stay readable.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

/**
 * Districts: key => array( Bangla name, division key ), in the order of meeha_get_bd_divisions().
 *
 * @return array
 */
function meeha_dc_districts() {
	return array(
		'dhaka' => array( 'ঢাকা', 'dhaka' ),
		'gazipur' => array( 'গাজীপুর', 'dhaka' ),
		'narayanganj' => array( 'নারায়ণগঞ্জ', 'dhaka' ),
		'tangail' => array( 'টাঙ্গাইল', 'dhaka' ),
		'kishoreganj' => array( 'কিশোরগঞ্জ', 'dhaka' ),
		'manikganj' => array( 'মানিকগঞ্জ', 'dhaka' ),
		'munshiganj' => array( 'মুন্সিগঞ্জ', 'dhaka' ),
		'narsingdi' => array( 'নরসিংদী', 'dhaka' ),
		'rajbari' => array( 'রাজবাড়ী', 'dhaka' ),
		'faridpur' => array( 'ফরিদপুর', 'dhaka' ),
		'gopalganj' => array( 'গোপালগঞ্জ', 'dhaka' ),
		'madaripur' => array( 'মাদারীপুর', 'dhaka' ),
		'shariatpur' => array( 'শরীয়তপুর', 'dhaka' ),
		'chattogram' => array( 'চট্টগ্রাম', 'chattogram' ),
		'coxsbazar' => array( 'কক্সবাজার', 'chattogram' ),
		'rangamati' => array( 'রাঙ্গামাটি', 'chattogram' ),
		'bandarban' => array( 'বান্দরবান', 'chattogram' ),
		'khagrachhari' => array( 'খাগড়াছড়ি', 'chattogram' ),
		'feni' => array( 'ফেনী', 'chattogram' ),
		'noakhali' => array( 'নোয়াখালী', 'chattogram' ),
		'lakshmipur' => array( 'লক্ষ্মীপুর', 'chattogram' ),
		'cumilla' => array( 'কুমিল্লা', 'chattogram' ),
		'chandpur' => array( 'চাঁদপুর', 'chattogram' ),
		'brahmanbaria' => array( 'ব্রাহ্মণবাড়িয়া', 'chattogram' ),
		'rajshahi' => array( 'রাজশাহী', 'rajshahi' ),
		'natore' => array( 'নাটোর', 'rajshahi' ),
		'naogaon' => array( 'নওগাঁ', 'rajshahi' ),
		'chapainawabganj' => array( 'চাঁপাইনবাবগঞ্জ', 'rajshahi' ),
		'pabna' => array( 'পাবনা', 'rajshahi' ),
		'sirajganj' => array( 'সিরাজগঞ্জ', 'rajshahi' ),
		'bogura' => array( 'বগুড়া', 'rajshahi' ),
		'joypurhat' => array( 'জয়পুরহাট', 'rajshahi' ),
		'khulna' => array( 'খুলনা', 'khulna' ),
		'bagerhat' => array( 'বাগেরহাট', 'khulna' ),
		'satkhira' => array( 'সাতক্ষীরা', 'khulna' ),
		'jashore' => array( 'যশোর', 'khulna' ),
		'jhenaidah' => array( 'ঝিনাইদহ', 'khulna' ),
		'magura' => array( 'মাগুরা', 'khulna' ),
		'narail' => array( 'নড়াইল', 'khulna' ),
		'kushtia' => array( 'কুষ্টিয়া', 'khulna' ),
		'chuadanga' => array( 'চুয়াডাঙ্গা', 'khulna' ),
		'meherpur' => array( 'মেহেরপুর', 'khulna' ),
		'barishal' => array( 'বরিশাল', 'barishal' ),
		'patuakhali' => array( 'পটুয়াখালী', 'barishal' ),
		'bhola' => array( 'ভোলা', 'barishal' ),
		'pirojpur' => array( 'পিরোজপুর', 'barishal' ),
		'barguna' => array( 'বরগুনা', 'barishal' ),
		'jhalakathi' => array( 'ঝালকাঠি', 'barishal' ),
		'sylhet' => array( 'সিলেট', 'sylhet' ),
		'moulvibazar' => array( 'মৌলভীবাজার', 'sylhet' ),
		'habiganj' => array( 'হবিগঞ্জ', 'sylhet' ),
		'sunamganj' => array( 'সুনামগঞ্জ', 'sylhet' ),
		'rangpur' => array( 'রংপুর', 'rangpur' ),
		'dinajpur' => array( 'দিনাজপুর', 'rangpur' ),
		'thakurgaon' => array( 'ঠাকুরগাঁও', 'rangpur' ),
		'panchagarh' => array( 'পঞ্চগড়', 'rangpur' ),
		'nilphamari' => array( 'নীলফামারী', 'rangpur' ),
		'lalmonirhat' => array( 'লালমনিরহাট', 'rangpur' ),
		'kurigram' => array( 'কুড়িগ্রাম', 'rangpur' ),
		'gaibandha' => array( 'গাইবান্ধা', 'rangpur' ),
		'mymensingh' => array( 'ময়মনসিংহ', 'mymensingh' ),
		'jamalpur' => array( 'জামালপুর', 'mymensingh' ),
		'sherpur' => array( 'শেরপুর', 'mymensingh' ),
		'netrokona' => array( 'নেত্রকোণা', 'mymensingh' ),
	);
}

/**
 * Divisions: key => Bangla name.
 *
 * @return array
 */
function meeha_dc_divisions() {
	return array(
		'dhaka'      => 'ঢাকা',
		'chattogram' => 'চট্টগ্রাম',
		'rajshahi'   => 'রাজশাহী',
		'khulna'     => 'খুলনা',
		'barishal'   => 'বরিশাল',
		'sylhet'     => 'সিলেট',
		'rangpur'    => 'রংপুর',
		'mymensingh' => 'ময়মনসিংহ',
	);
}

/**
 * Icon ids the page and the input screens can draw (assets/js/deshchitro*.js hold the glyphs).
 *
 * @return array id => label
 */
function meeha_dc_icons() {
	return array(
		'knife'    => 'ছুরি (খুন)',
		'car'      => 'গাড়ি (সড়ক)',
		'injury'   => 'ব্যান্ডেজ (আহত)',
		'shield'   => 'ঢাল (নারী ও শিশু)',
		'bag'      => 'ব্যাগ (চুরি-ছিনতাই)',
		'ribbon'   => 'ফিতা (আত্মহত্যা)',
		'water'    => 'ঢেউ (পানিতে ডুবে)',
		'fire'     => 'আগুন',
		'cuffs'    => 'হাতকড়া (গ্রেপ্তার)',
		'star'     => 'তারা (সাফল্য)',
		'missing'  => 'প্রশ্ন (নিখোঁজ)',
		'pill'     => 'মাদক',
		'bolt'     => 'বজ্রপাত/দুর্যোগ',
		'alert'    => 'সতর্কতা',
		'people'   => 'মানুষ',
	);
}

/**
 * Tones: bad (red family), warn (amber family), good (green), info (blue).
 *
 * @return array
 */
function meeha_dc_tones() {
	return array(
		'bad'  => 'নেতিবাচক (লাল)',
		'warn' => 'সতর্কতা (হলুদ/কমলা)',
		'good' => 'ইতিবাচক (সবুজ)',
		'info' => 'সাধারণ (নীল)',
	);
}

/**
 * Default incident indicators (per district, counted for one day).
 *
 * @return array[]
 */
function meeha_dc_default_indicators() {
	$rows = array(
		array( 'khun', 'খুন', 'খুন', 'knife', 'bad' ),
		array( 'sorok_nihoto', 'সড়ক দুর্ঘটনায় নিহত', 'সড়কে নিহত', 'car', 'bad' ),
		array( 'sorok_ahoto', 'সড়ক দুর্ঘটনায় আহত', 'সড়কে আহত', 'injury', 'warn' ),
		array( 'dhorshon', 'ধর্ষণ ও নারী-শিশু নির্যাতন', 'ধর্ষণ-নির্যাতন', 'shield', 'bad' ),
		array( 'chhintai', 'চুরি, ছিনতাই ও ডাকাতি', 'চুরি-ছিনতাই', 'bag', 'warn' ),
		array( 'attohotya', 'আত্মহত্যা', 'আত্মহত্যা', 'ribbon', 'bad' ),
		array( 'dube', 'পানিতে ডুবে মৃত্যু', 'ডুবে মৃত্যু', 'water', 'warn' ),
		array( 'agun', 'অগ্নিকাণ্ড', 'অগ্নিকাণ্ড', 'fire', 'warn' ),
		array( 'greftar', 'গ্রেপ্তার (অভিযান)', 'গ্রেপ্তার', 'cuffs', 'info' ),
		array( 'safolyo', 'সাফল্য: উদ্ধার, জব্দ ও ইতিবাচক অর্জন', 'সাফল্য', 'star', 'good' ),
	);
	$out = array();
	foreach ( $rows as $r ) {
		$out[] = array(
			'key'   => $r[0],
			'label' => $r[1],
			'short' => $r[2],
			'icon'  => $r[3],
			'tone'  => $r[4],
			'on'    => 1,
		);
	}
	return $out;
}

/**
 * Default economy indicators (national, one value per day).
 * good: 'up' = a rise is good news, 'down' = a fall is good news, '' = neutral.
 *
 * @return array[]
 */
function meeha_dc_default_economy() {
	$rows = array(
		// key, label, group, unit, decimals, good, code.
		array( 'usd', 'মার্কিন ডলার', 'currency', '৳', 2, '', 'USD' ),
		array( 'eur', 'ইউরো', 'currency', '৳', 2, '', 'EUR' ),
		array( 'gbp', 'ব্রিটিশ পাউন্ড', 'currency', '৳', 2, '', 'GBP' ),
		array( 'inr', 'ভারতীয় রুপি', 'currency', '৳', 2, '', 'INR' ),
		array( 'sar', 'সৌদি রিয়াল', 'currency', '৳', 2, '', 'SAR' ),
		array( 'aed', 'আমিরাতি দিরহাম', 'currency', '৳', 2, '', 'AED' ),
		array( 'myr', 'মালয়েশিয়ান রিঙ্গিত', 'currency', '৳', 2, '', 'MYR' ),
		array( 'gold22', 'সোনা (২২ ক্যারেট, প্রতি ভরি)', 'market', '৳', 0, '', '' ),
		array( 'rice', 'মোটা চাল (প্রতি কেজি)', 'market', '৳', 0, 'down', '' ),
		array( 'soyabean', 'সয়াবিন তেল (বোতল, প্রতি লিটার)', 'market', '৳', 0, 'down', '' ),
		array( 'onion', 'দেশি পেঁয়াজ (প্রতি কেজি)', 'market', '৳', 0, 'down', '' ),
		array( 'egg', 'ফার্মের ডিম (প্রতি ডজন)', 'market', '৳', 0, 'down', '' ),
		array( 'dsex', 'ডিএসইএক্স সূচক', 'economy', '', 2, 'up', '' ),
		array( 'reserve', 'বৈদেশিক মুদ্রার রিজার্ভ', 'economy', 'বিলিয়ন $', 2, 'up', '' ),
		array( 'inflation', 'মূল্যস্ফীতি (সর্বশেষ মাস)', 'economy', '%', 2, 'down', '' ),
	);
	$out = array();
	foreach ( $rows as $r ) {
		$out[] = array(
			'key'   => $r[0],
			'label' => $r[1],
			'group' => $r[2],
			'unit'  => $r[3],
			'dec'   => $r[4],
			'good'  => $r[5],
			'code'  => $r[6],
			'on'    => 1,
		);
	}
	return $out;
}

/**
 * Economy groups.
 *
 * @return array
 */
function meeha_dc_economy_groups() {
	return array(
		'currency' => 'মুদ্রার বিনিময় হার',
		'market'   => 'বাজারদর',
		'economy'  => 'অর্থনীতির সূচক',
	);
}

/**
 * Text defaults.
 *
 * @return array
 */
function meeha_dc_text_defaults() {
	return array(
		'title'      => 'দৈনিক দেশচিত্র',
		'kicker'     => 'মিহা নিউজ ডেটা ডেস্ক',
		'subtitle'   => 'গতকাল সারা দেশে কী ঘটেছে — অপরাধ, দুর্ঘটনা, সাফল্য আর অর্থনীতির হিসাব, জেলা ধরে এক নজরে।',
		'disclaimer' => 'এই প্রতিবেদন সংবাদমাধ্যম, প্রশাসন ও গোয়েন্দা সংস্থার প্রতিবেদনের ভিত্তিতে তৈরি।',
		'method'     => 'প্রতিদিন রাত ১২টার পর আগের দিনের (রাত ১২টা থেকে রাত ১২টা) হিসাব হালনাগাদ হয়। চলতি দিনের হিসাব দেখানো হয় না। সংখ্যাগুলো প্রকাশিত ও নিশ্চিত হওয়া ঘটনার; পরে নতুন তথ্য এলে সংশোধন হতে পারে।',
	);
}

/**
 * Saved configuration merged with defaults.
 *
 * @return array{indicators: array, economy: array, text: array, page_id: int}
 */
function meeha_dc_config() {
	static $cache = null;
	if ( null !== $cache ) {
		return $cache;
	}
	$saved = get_option( 'meeha_dc_config', array() );
	$saved = is_array( $saved ) ? $saved : array();
	$cache = array(
		'indicators' => ! empty( $saved['indicators'] ) && is_array( $saved['indicators'] ) ? $saved['indicators'] : meeha_dc_default_indicators(),
		'economy'    => ! empty( $saved['economy'] ) && is_array( $saved['economy'] ) ? $saved['economy'] : meeha_dc_default_economy(),
		'text'       => array_merge( meeha_dc_text_defaults(), isset( $saved['text'] ) && is_array( $saved['text'] ) ? array_filter( $saved['text'], 'strlen' ) : array() ),
		'page_id'    => isset( $saved['page_id'] ) ? (int) $saved['page_id'] : 0,
	);
	return $cache;
}

/**
 * Active indicators only.
 *
 * @param string $which indicators|economy.
 * @return array[]
 */
function meeha_dc_active( $which ) {
	$c = meeha_dc_config();
	return array_values(
		array_filter(
			$c[ $which ],
			function ( $r ) {
				return ! empty( $r['on'] );
			}
		)
	);
}

/**
 * Sanitise a posted configuration.
 *
 * @param array $in Raw.
 * @return array
 */
function meeha_dc_sanitize_config( $in ) {
	$icons = meeha_dc_icons();
	$tones = meeha_dc_tones();
	$out   = array(
		'indicators' => array(),
		'economy'    => array(),
		'text'       => array(),
		'page_id'    => isset( $in['page_id'] ) ? absint( $in['page_id'] ) : 0,
	);
	$seen  = array();
	foreach ( (array) ( isset( $in['indicators'] ) ? $in['indicators'] : array() ) as $r ) {
		$key = isset( $r['key'] ) ? substr( sanitize_key( $r['key'] ), 0, 24 ) : '';
		$lab = isset( $r['label'] ) ? trim( sanitize_text_field( $r['label'] ) ) : '';
		if ( '' === $key || '' === $lab || isset( $seen[ $key ] ) || count( $out['indicators'] ) >= 16 ) {
			continue;
		}
		$seen[ $key ]          = 1;
		$out['indicators'][] = array(
			'key'   => $key,
			'label' => mb_substr( $lab, 0, 60 ),
			'short' => mb_substr( trim( sanitize_text_field( isset( $r['short'] ) && '' !== trim( $r['short'] ) ? $r['short'] : $lab ) ), 0, 18 ),
			'icon'  => isset( $r['icon'], $icons[ $r['icon'] ] ) ? $r['icon'] : 'alert',
			'tone'  => isset( $r['tone'], $tones[ $r['tone'] ] ) ? $r['tone'] : 'bad',
			'on'    => empty( $r['on'] ) ? 0 : 1,
		);
	}
	$seen   = array();
	$groups = meeha_dc_economy_groups();
	foreach ( (array) ( isset( $in['economy'] ) ? $in['economy'] : array() ) as $r ) {
		$key = isset( $r['key'] ) ? substr( sanitize_key( $r['key'] ), 0, 24 ) : '';
		$lab = isset( $r['label'] ) ? trim( sanitize_text_field( $r['label'] ) ) : '';
		if ( '' === $key || '' === $lab || isset( $seen[ $key ] ) || count( $out['economy'] ) >= 30 ) {
			continue;
		}
		$seen[ $key ]       = 1;
		$out['economy'][] = array(
			'key'   => $key,
			'label' => mb_substr( $lab, 0, 60 ),
			'group' => isset( $r['group'], $groups[ $r['group'] ] ) ? $r['group'] : 'economy',
			'unit'  => mb_substr( sanitize_text_field( isset( $r['unit'] ) ? $r['unit'] : '' ), 0, 14 ),
			'dec'   => isset( $r['dec'] ) ? max( 0, min( 4, (int) $r['dec'] ) ) : 2,
			'good'  => isset( $r['good'] ) && in_array( $r['good'], array( 'up', 'down' ), true ) ? $r['good'] : '',
			'code'  => strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', isset( $r['code'] ) ? $r['code'] : '' ), 0, 4 ) ),
			'on'    => empty( $r['on'] ) ? 0 : 1,
		);
	}
	foreach ( meeha_dc_text_defaults() as $k => $v ) {
		$out['text'][ $k ] = isset( $in['text'][ $k ] ) ? mb_substr( sanitize_textarea_field( $in['text'][ $k ] ), 0, 600 ) : '';
	}
	if ( ! $out['indicators'] ) {
		$out['indicators'] = meeha_dc_default_indicators();
	}
	if ( ! $out['economy'] ) {
		$out['economy'] = meeha_dc_default_economy();
	}
	return $out;
}

/**
 * Today's date in the site's timezone (Y-m-d). Public pages show only days strictly before this.
 *
 * @return string
 */
function meeha_dc_today() {
	return wp_date( 'Y-m-d' );
}

/**
 * Shift a Y-m-d date by N days.
 *
 * @param string $ymd  Date.
 * @param int    $days Days.
 * @return string
 */
function meeha_dc_shift( $ymd, $days ) {
	$d = DateTime::createFromFormat( '!Y-m-d', $ymd, new DateTimeZone( 'UTC' ) );
	if ( ! $d ) {
		return $ymd;
	}
	$d->modify( ( $days >= 0 ? '+' : '' ) . (int) $days . ' days' );
	return $d->format( 'Y-m-d' );
}

/**
 * Is a string a valid Y-m-d date?
 *
 * @param string $v Value.
 * @return bool
 */
function meeha_dc_is_date( $v ) {
	if ( ! is_string( $v ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m ) ) {
		return false;
	}
	return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
}

/**
 * Bangla long date, e.g. "১ নভেম্বর ২০২৬, রবিবার".
 *
 * @param string $ymd Date.
 * @return string
 */
function meeha_dc_bn_date( $ymd ) {
	$months = array( 'জানুয়ারি', 'ফেব্রুয়ারি', 'মার্চ', 'এপ্রিল', 'মে', 'জুন', 'জুলাই', 'আগস্ট', 'সেপ্টেম্বর', 'অক্টোবর', 'নভেম্বর', 'ডিসেম্বর' );
	$days   = array( 'রবিবার', 'সোমবার', 'মঙ্গলবার', 'বুধবার', 'বৃহস্পতিবার', 'শুক্রবার', 'শনিবার' );
	$d      = DateTime::createFromFormat( '!Y-m-d', $ymd, new DateTimeZone( 'UTC' ) );
	if ( ! $d ) {
		return '';
	}
	return meeha_bn_digits( $d->format( 'j' ) ) . ' ' . $months[ (int) $d->format( 'n' ) - 1 ] . ' ' . meeha_bn_digits( $d->format( 'Y' ) ) . ', ' . $days[ (int) $d->format( 'w' ) ];
}
