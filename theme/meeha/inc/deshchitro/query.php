<?php
/**
 * দেশচিত্র public payload: aggregates the published days of a period into what the page draws.
 *
 * Ranges: day (one day; default = the latest public day), 7 (7 days ending on `date`), 30, month (calendar month
 * of `date`). Every period is compared with the period of the same length right before it.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

/**
 * Allowed ranges.
 *
 * @return array key => label
 */
function meeha_dc_ranges() {
	return array(
		'day'   => 'গতকাল / একদিন',
		'7'     => 'গত ৭ দিন',
		'30'    => 'গত ৩০ দিন',
		'month' => 'পুরো মাস',
	);
}

/**
 * Normalise request args.
 *
 * @param array $raw Raw (range, date).
 * @return array{range: string, date: string}
 */
function meeha_dc_args( $raw ) {
	$range = isset( $raw['range'] ) ? (string) $raw['range'] : 'day';
	$date  = isset( $raw['date'] ) ? (string) $raw['date'] : '';
	return array(
		'range' => isset( meeha_dc_ranges()[ $range ] ) ? $range : 'day',
		'date'  => meeha_dc_is_date( $date ) ? $date : '',
	);
}

/**
 * Period bounds for args.
 *
 * @param array  $a      Args.
 * @param string $latest Latest public day.
 * @return array{0: string, 1: string} from, to
 */
function meeha_dc_period( $a, $latest ) {
	$yesterday = meeha_dc_shift( meeha_dc_today(), -1 );
	$end       = $a['date'] ? $a['date'] : ( $latest ? $latest : $yesterday );
	if ( $end > $yesterday ) {
		$end = $yesterday; // never today or later.
	}
	switch ( $a['range'] ) {
		case '7':
			return array( meeha_dc_shift( $end, -6 ), $end );
		case '30':
			return array( meeha_dc_shift( $end, -29 ), $end );
		case 'month':
			$from = substr( $end, 0, 8 ) . '01';
			$last = gmdate( 'Y-m-t', strtotime( $from . ' 00:00:00 UTC' ) );
			return array( $from, min( $last, $yesterday ) );
	}
	return array( $end, $end );
}

/**
 * Days between two dates, inclusive.
 *
 * @param string $from From.
 * @param string $to   To.
 * @return int
 */
function meeha_dc_span( $from, $to ) {
	return (int) round( ( strtotime( $to . ' 00:00:00 UTC' ) - strtotime( $from . ' 00:00:00 UTC' ) ) / DAY_IN_SECONDS ) + 1;
}

/**
 * Sum incident data over rows.
 *
 * @param array[] $rows Day rows.
 * @param array   $ik   Indicator keys.
 * @return array{tot: array, dist: array, unk: array}
 */
function meeha_dc_sum( $rows, $ik ) {
	$tot  = array_fill_keys( $ik, 0 );
	$unk  = array_fill_keys( $ik, 0 );
	$dist = array();
	foreach ( $rows as $r ) {
		foreach ( $r['data']['inc'] as $dk => $vals ) {
			foreach ( $vals as $k => $n ) {
				if ( isset( $tot[ $k ] ) ) {
					$dist[ $dk ][ $k ] = ( isset( $dist[ $dk ][ $k ] ) ? $dist[ $dk ][ $k ] : 0 ) + (int) $n;
					$tot[ $k ]        += (int) $n;
				}
			}
		}
		foreach ( $r['data']['unk'] as $k => $n ) {
			if ( isset( $tot[ $k ] ) ) {
				$unk[ $k ] += (int) $n;
				$tot[ $k ] += (int) $n;
			}
		}
	}
	return array(
		'tot'  => $tot,
		'dist' => $dist,
		'unk'  => array_filter( $unk ),
	);
}

/**
 * The payload the page renders.
 *
 * @param array $args Raw args (range, date).
 * @return array
 */
function meeha_dc_payload( $args ) {
	$a   = meeha_dc_args( $args );
	$key = 'meeha_dc_' . md5( wp_json_encode( array( $a, (int) get_option( 'meeha_dc_gen', 0 ), meeha_dc_today(), MEEHA_DC_DB_VERSION ) ) );
	$hit = get_transient( $key );
	if ( is_array( $hit ) ) {
		return $hit;
	}
	$p = meeha_dc_build_payload( $a );
	set_transient( $key, $p, 10 * MINUTE_IN_SECONDS );
	return $p;
}

/**
 * Build the payload (uncached).
 *
 * @param array $a Normalised args.
 * @return array
 */
function meeha_dc_build_payload( $a ) {
	$latest = meeha_dc_latest_public_day();
	$first  = meeha_dc_first_public_day();
	list( $from, $to ) = meeha_dc_period( $a, $latest );
	$span       = meeha_dc_span( $from, $to );
	$prev_to    = meeha_dc_shift( $from, -1 );
	$prev_from  = meeha_dc_shift( $from, -$span );
	$series_len = max( 14, min( 60, $span * 2 ) );
	$series_from = meeha_dc_shift( $to, - ( $series_len - 1 ) );
	$fetch_from = min( $prev_from, $series_from, meeha_dc_shift( $to, -45 ) );

	$all   = meeha_dc_days( $fetch_from, $to, true );
	$inds  = meeha_dc_active( 'indicators' );
	$ecos  = meeha_dc_active( 'economy' );
	$ik    = wp_list_pluck( $inds, 'key' );
	$cur   = array();
	$prev  = array();
	foreach ( $all as $day => $r ) {
		if ( $day >= $from && $day <= $to ) {
			$cur[ $day ] = $r;
		} elseif ( $day >= $prev_from && $day <= $prev_to ) {
			$prev[ $day ] = $r;
		}
	}
	$now  = meeha_dc_sum( $cur, $ik );
	$then = meeha_dc_sum( $prev, $ik );

	// Daily series (per indicator), null where no published sheet exists.
	$series = array(
		'days' => array(),
		'v'    => array_fill_keys( $ik, array() ),
	);
	for ( $i = 0; $i < $series_len; $i++ ) {
		$d                  = meeha_dc_shift( $series_from, $i );
		$series['days'][] = $d;
		$one                = isset( $all[ $d ] ) ? meeha_dc_sum( array( $all[ $d ] ), $ik ) : null;
		foreach ( $ik as $k ) {
			$series['v'][ $k ][] = $one ? $one['tot'][ $k ] : null;
		}
	}

	// Economy: the last value on or before `to`, compared with the value before it (previous sheet that has it).
	$eco = array();
	foreach ( $ecos as $e ) {
		$k     = $e['key'];
		$value = null;
		$vday  = '';
		$before = null;
		$hist  = array();
		foreach ( $all as $day => $r ) {
			if ( $day > $to || ! isset( $r['data']['eco'][ $k ] ) ) {
				continue;
			}
			$hist[ $day ] = (float) $r['data']['eco'][ $k ];
		}
		ksort( $hist );
		$keys = array_keys( $hist );
		$n    = count( $keys );
		if ( $n ) {
			$vday   = $keys[ $n - 1 ];
			$value  = $hist[ $vday ];
			$before = $n > 1 ? $hist[ $keys[ $n - 2 ] ] : null;
		}
		$spark = array();
		foreach ( $series['days'] as $d ) {
			$spark[] = isset( $hist[ $d ] ) ? $hist[ $d ] : null;
		}
		$eco[] = array_merge(
			$e,
			array(
				'value' => $value,
				'prev'  => $before,
				'day'   => $vday,
				'stale' => $vday && $vday < $from,
				'spark' => $spark,
			)
		);
	}

	// One-day extras.
	$one     = 1 === $span && isset( $cur[ $to ] ) ? $cur[ $to ] : null;
	$notes   = $one ? $one['data']['notes'] : array();
	$hl      = array();
	$summary = $one ? $one['data']['summary'] : '';
	foreach ( array_reverse( $cur, true ) as $day => $r ) {
		foreach ( $r['data']['hl'] as $h ) {
			if ( count( $hl ) >= 12 ) {
				break 2;
			}
			$h['day'] = $day;
			$hl[]     = $h;
		}
	}
	$updated = '';
	foreach ( $cur as $r ) {
		$updated = max( $updated, $r['updated_at'] );
	}

	$c = meeha_dc_config();
	return array(
		'range'     => $a['range'],
		'from'      => $from,
		'to'        => $to,
		'span'      => $span,
		'prevFrom'  => $prev_from,
		'prevTo'    => $prev_to,
		'covered'   => count( $cur ),
		'prevCovered' => count( $prev ),
		'latest'    => $latest,
		'first'     => $first,
		'today'     => meeha_dc_today(),
		'requested' => $a['date'],
		'updated'   => $updated,
		'label'     => 1 === $span ? meeha_dc_bn_date( $to ) : meeha_dc_bn_date( $from ) . ' — ' . meeha_dc_bn_date( $to ),
		'indicators' => $inds,
		'economy'   => $eco,
		'ecoGroups' => meeha_dc_economy_groups(),
		'totals'    => $now['tot'],
		'prevTotals' => $then['tot'],
		'districts' => $now['dist'],
		'unknown'   => $now['unk'],
		'notes'     => $notes,
		'highlights' => $hl,
		'summary'   => $summary,
		'series'    => $series,
		'text'      => $c['text'],
	);
}

/**
 * Static geography the page needs (names + divisions); shapes come from deshchitro-map.js.
 *
 * @return array
 */
function meeha_dc_geo() {
	$d = array();
	foreach ( meeha_dc_districts() as $k => $v ) {
		$d[ $k ] = array( $v[0], $v[1] );
	}
	return array(
		'districts' => $d,
		'divisions' => meeha_dc_divisions(),
	);
}
