<?php
/**
 * উক্তি module (owner D). CPT key stays `meeha_photocard` (rollback + plugin safety), relabelled উক্তি, rewrite
 * slug `quote` with 301s from /photocard/{slug}/; taxonomy `meeha_quote_type` (slug quote-type) with term meta
 * `frame`; fields (+ legacy `_meeha_attribution` sync); polls (table {prefix}meeha_poll_votes, REST
 * meeha/v1/poll/{id}); archive/single/taxonomy templates; [meeha_photocards]/[meeha_photocard]; home rail.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

foreach ( array( 'cpt', 'settings', 'fields', 'polls', 'render' ) as $meeha_q_file ) {
	require_once __DIR__ . '/' . $meeha_q_file . '.php';
}
unset( $meeha_q_file );
