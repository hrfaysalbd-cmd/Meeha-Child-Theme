<?php
/**
 * বাঙালি সাহিত্য-সংস্কৃতি module (owner D): genres (settings tab `literature`), genre-category creator,
 * "সাহিত্য তথ্য" meta box (`_meeha_lit_*`), `[meeha_sahitya]` / `[meeha_literature]` landing page,
 * homepage teaser renderer for C (S11) and helpers (`meeha_lit_genre_of()`).
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

foreach ( array( 'genres', 'settings', 'fields', 'render' ) as $meeha_lit_file ) {
	require_once __DIR__ . '/' . $meeha_lit_file . '.php';
}
unset( $meeha_lit_file );
