<?php
/**
 * Anonymous question log: per day, counts per intent and per normalised question (no IP, no user, no time of day;
 * e-mail addresses, links and long numbers such as phone numbers are removed). Kept 14 days in one non-autoloaded
 * option; at most 300 distinct questions a day.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

/**
 * Anonymise a question for the log.
 *
 * @param string $q Question.
 * @return string
 */
function meeha_as_log_text( $q ) {
	$q = preg_replace( '/\S+@\S+/u', '…', (string) $q );
	$q = preg_replace( '#https?://\S+|www\.\S+#iu', '…', $q );
	$q = preg_replace( '/[0-9০-৯][0-9০-৯\s\-+]{5,}[0-9০-৯]/u', '…', $q );
	return mb_substr( trim( preg_replace( '/\s+/u', ' ', $q ) ), 0, 80 );
}

/**
 * Count one question.
 *
 * @param string $question Raw (clean) question.
 * @param string $intent   Intent.
 * @param string $mode     basic|ai.
 */
function meeha_as_log( $question, $intent, $mode ) {
	$day  = wp_date( 'Y-m-d' );
	$data = get_option( 'meeha_as_stats', array() );
	$data = is_array( $data ) ? $data : array();
	if ( ! isset( $data[ $day ] ) ) {
		$data[ $day ] = array( 'n' => 0, 'ai' => 0, 'i' => array(), 'q' => array() );
		krsort( $data );
		$data = array_slice( $data, 0, 14, true );
	}
	$d          = &$data[ $day ];
	$d['n']     = (int) $d['n'] + 1;
	$d['ai']    = (int) $d['ai'] + ( 'ai' === $mode ? 1 : 0 );
	$intent     = sanitize_key( $intent );
	$d['i'][ $intent ] = ( isset( $d['i'][ $intent ] ) ? (int) $d['i'][ $intent ] : 0 ) + 1;
	$t = meeha_as_log_text( $question );
	if ( '' !== $t && ( isset( $d['q'][ $t ] ) || count( $d['q'] ) < 300 ) ) {
		$d['q'][ $t ] = ( isset( $d['q'][ $t ] ) ? (int) $d['q'][ $t ] : 0 ) + 1;
	}
	unset( $d );
	if ( false === get_option( 'meeha_as_stats', false ) ) {
		add_option( 'meeha_as_stats', $data, '', 'no' );
	} else {
		update_option( 'meeha_as_stats', $data, false );
	}
}

/**
 * Intent labels.
 *
 * @return array
 */
function meeha_as_intent_labels() {
	return array(
		'help'         => 'শুভেচ্ছা / সাহায্য',
		'trending'     => 'ট্রেন্ডিং',
		'latest'       => 'আজকের খবর',
		'country'      => 'দেশে কী হচ্ছে',
		'area'         => 'জেলা / এলাকা',
		'ask_district' => 'আমার জেলা (জেলা জিজ্ঞাসা)',
		'category'     => 'বিভাগ',
		'live'         => 'লাইভ',
		'search'       => 'বিষয় খোঁজা',
		'limited'      => 'সীমা পেরিয়েছে',
	);
}

/**
 * Settings page block: last 7 days.
 *
 * @return string HTML.
 */
function meeha_as_stats_html() {
	$data = get_option( 'meeha_as_stats', array() );
	$data = is_array( $data ) ? $data : array();
	krsort( $data );
	$data = array_slice( $data, 0, 7, true );
	$tot  = 0;
	$ai   = 0;
	$int  = array();
	$qs   = array();
	foreach ( $data as $d ) {
		$tot += (int) $d['n'];
		$ai  += (int) $d['ai'];
		foreach ( (array) $d['i'] as $k => $v ) {
			$int[ $k ] = ( isset( $int[ $k ] ) ? $int[ $k ] : 0 ) + (int) $v;
		}
		foreach ( (array) $d['q'] as $k => $v ) {
			$qs[ $k ] = ( isset( $qs[ $k ] ) ? $qs[ $k ] : 0 ) + (int) $v;
		}
	}
	arsort( $int );
	arsort( $qs );
	$labels = meeha_as_intent_labels();
	$bn     = function ( $v ) {
		return esc_html( function_exists( 'meeha_bn_digits' ) ? meeha_bn_digits( $v ) : $v );
	};
	$h  = '<h2>পাঠকেরা কী জিজ্ঞেস করছেন (গত ৭ দিন)</h2>';
	if ( ! $tot ) {
		return $h . '<p class="description">এখনো কোনো প্রশ্ন আসেনি।</p>';
	}
	$h .= '<p>মোট প্রশ্ন: <strong>' . $bn( $tot ) . '</strong>' . ( $ai ? ' — এর মধ্যে AI উত্তর: <strong>' . $bn( $ai ) . '</strong>' : '' ) . '। কোনো IP বা পাঠকের পরিচয় রাখা হয় না; ফোন নম্বর, ই-মেইল ও লিংক মুছে রাখা হয়।</p>';
	$h .= '<div style="display:flex;gap:24px;flex-wrap:wrap;align-items:flex-start">';
	$h .= '<table class="widefat striped" style="max-width:340px"><thead><tr><th>ধরন</th><th>সংখ্যা</th></tr></thead><tbody>';
	foreach ( $int as $k => $v ) {
		$h .= '<tr><td>' . esc_html( isset( $labels[ $k ] ) ? $labels[ $k ] : $k ) . '</td><td>' . $bn( $v ) . '</td></tr>';
	}
	$h .= '</tbody></table>';
	$h .= '<table class="widefat striped" style="max-width:560px"><thead><tr><th>শীর্ষ প্রশ্ন</th><th>বার</th></tr></thead><tbody>';
	foreach ( array_slice( $qs, 0, 25, true ) as $k => $v ) {
		$h .= '<tr><td>' . esc_html( $k ) . '</td><td>' . $bn( $v ) . '</td></tr>';
	}
	$h .= '</tbody></table></div>';
	return $h;
}
