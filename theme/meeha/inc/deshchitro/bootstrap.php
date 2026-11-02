<?php
/**
 * দেশচিত্র (2.4.7) — the national daily-figures page.
 *
 * Every night after 12 a.m. the desk enters the previous day's figures (murders, road deaths and injuries, rape and
 * abuse, theft/robbery, suicide, drowning, fire, arrests, successes — configurable) for each of the 64 districts,
 * plus national economy figures (exchange rates, market prices, DSEX, reserve, inflation) and the day's notable
 * events. The `[meeha_deshchitro]` page shows them as KPI tiles, an interactive district map of Bangladesh,
 * division bars, trends, an economy board and a full table, with a date / period filter.
 *
 * Input: wp-admin "দেশচিত্র" menu (editors and above) or the publisher app (newsroom-os 1.10.0+).
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

foreach ( array( 'config', 'store', 'query', 'rest', 'render', 'admin' ) as $meeha_dc_file ) {
	require_once __DIR__ . '/' . $meeha_dc_file . '.php';
}
unset( $meeha_dc_file );
