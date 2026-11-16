<?php
/**
 * শিক্ষা ও ক্যাম্পাস section (career, jobs, exam prep): settings tab `campus`, the `[meeha_campus]` hub page with
 * its own router ({hub}/{view}/{id}), the section home/news/career/search views, the mobile app shell (back bar,
 * bottom nav) and the `campus` CSS/JS bundle. Jobs, tests, quiz, payments come from the "Meeha Prep" plugin,
 * which registers its views through the `meeha_campus_views` filter; without it the hub shows news only.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

foreach ( array( 'settings', 'router', 'render' ) as $meeha_campus_file ) {
	require_once __DIR__ . '/' . $meeha_campus_file . '.php';
}
unset( $meeha_campus_file );
