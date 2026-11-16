<?php
/**
 * Plugin Name:       মিহা প্রস্তুতি (Meeha Prep)
 * Plugin URI:        https://meeha.news/
 * Description:       মিহা নিউজের শিক্ষা ও ক্যাম্পাস সেকশনের ইঞ্জিন: চাকরির বিজ্ঞপ্তি, প্রশ্ন ব্যাংক, মডেল/মক টেস্ট, দৈনিক কুইজ, লিডারবোর্ড, ইভেন্ট, পাঠ, শিক্ষার্থীর ড্যাশবোর্ড (পয়েন্ট, চ্যালেঞ্জ, ভুলের খাতা), কুইজ পুরস্কার, প্রকাশনা (ডিজিটাল ও প্রিন্ট), সিভি স্টুডিও, ক্যারিয়ার পরামর্শ, অর্ডার ও SSLCommerz পেমেন্ট। নকশা আসে "Meeha News" থিম থেকে।
 * Version:           1.3.1
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Meeha News
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       meeha-prep
 *
 * @package meeha-prep
 */

defined( 'ABSPATH' ) || exit;

define( 'MHP_VERSION', '1.3.1' );
define( 'MHP_DB_VERSION', 5 );
define( 'MHP_FILE', __FILE__ );
define( 'MHP_DIR', untrailingslashit( plugin_dir_path( __FILE__ ) ) );
define( 'MHP_URI', untrailingslashit( plugin_dir_url( __FILE__ ) ) );

foreach ( array(
	'helpers',
	'class-install',
	'class-post-types',
	'class-settings',
	'class-access',
	'class-orders',
	'class-gateway-sslcommerz',
	'class-checkout',
	'class-tests',
	'class-quiz',
	'class-jobs',
	'class-events',
	'class-forms',
	'class-rest',
	'class-cron',
	'class-seeder',
	'class-cv',
	'class-consult',
	'class-points',
	'class-learn',
	'class-lessons',
	'class-prizes',
	'class-pubs',
	'class-front',
	'template-tags',
) as $mhp_file ) {
	require_once MHP_DIR . '/includes/' . $mhp_file . '.php';
}
unset( $mhp_file );

if ( is_admin() ) {
	foreach ( array( 'class-meta-boxes', 'class-orders-table', 'class-importer', 'class-bank', 'class-admin', 'class-cv-admin', 'class-consult-admin', 'class-prize-admin', 'class-pub-admin' ) as $mhp_file ) {
		require_once MHP_DIR . '/admin/' . $mhp_file . '.php';
	}
	unset( $mhp_file );
}

register_activation_hook( __FILE__, array( 'MHP_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'MHP_Install', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'MHP_Install', 'maybe_upgrade' ), 5 );
MHP_Post_Types::init();
MHP_Settings::init();
MHP_Checkout::init();
MHP_Tests::init();
MHP_Quiz::init();
MHP_Jobs::init();
MHP_Events::init();
MHP_Forms::init();
MHP_Rest::init();
MHP_Cron::init();
MHP_Seeder::init();
MHP_CV::init();
MHP_Consult::init();
MHP_Points::init();
MHP_Learn::init();
MHP_Lessons::init();
MHP_Prizes::init();
MHP_Pubs::init();
MHP_Front::init();
if ( is_admin() ) {
	MHP_Meta_Boxes::init();
	MHP_Admin::init();
	MHP_Bank::init();
	MHP_CV_Admin::init();
	MHP_Consult_Admin::init();
	MHP_Prize_Admin::init();
	MHP_Pub_Admin::init();
}
