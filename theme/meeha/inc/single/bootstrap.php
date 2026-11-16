<?php
/**
 * Single post module (owner D): news / investigative / opinion / literature variants, content processing
 * (TOC ids, in-article ads, "আরও পড়ুন"), reactions, summary dialog, font size, card download, comments.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

foreach ( array( 'settings', 'content', 'template-tags', 'assets', 'comments' ) as $meeha_single_file ) {
	require_once __DIR__ . '/' . $meeha_single_file . '.php';
}
unset( $meeha_single_file );
