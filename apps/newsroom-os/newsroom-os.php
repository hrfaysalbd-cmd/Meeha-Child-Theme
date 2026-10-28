<?php
/**
 * Plugin Name:       Newsroom OS — Correspondent API
 * Description:       Headless REST API backend for the Meeha News correspondent
 *                     portal (correspondent.meeha.news) and future publisher/ads
 *                     apps. Exposes authenticated endpoints under the nros/v1
 *                     namespace: JWT auth, KYC applications, article submissions,
 *                     media uploads, earnings/withdraw ledger, engagement analytics,
 *                     and area (division/upazila) lookups. Reuses the active theme's
 *                     existing data (engagement meta, area helpers, edit-request CPT,
 *                     submission safety-net) wherever possible instead of duplicating it.
 * Version:           1.4.0
 * Author:             Meeha News
 * Text Domain:        newsroom-os
 *
 * @package NewsroomOS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ---------------------------------------------------------------------------
// Core constants
// ---------------------------------------------------------------------------

define( 'NROS_VERSION', '1.4.0' );
// Bumped whenever create_tables()/register_roles() need to re-run on an
// already-active install (see NROS_Core::maybe_upgrade()) — bump this any
// time a future change adds/changes a table or role.
define( 'NROS_DB_VERSION', '6' );
define( 'NROS_DIR', plugin_dir_path( __FILE__ ) );
define( 'NROS_URL', plugin_dir_url( __FILE__ ) );
define( 'NROS_PRIVATE_DIR', WP_CONTENT_DIR . '/nros-private' );
define( 'NROS_API_NS', 'nros/v1' );

// ---------------------------------------------------------------------------
// Load plugin classes
// ---------------------------------------------------------------------------

require_once NROS_DIR . 'includes/class-nros-core.php';
require_once NROS_DIR . 'includes/class-nros-jwt.php';
require_once NROS_DIR . 'includes/class-nros-auth.php';
require_once NROS_DIR . 'includes/class-nros-applications.php';
require_once NROS_DIR . 'includes/class-nros-submissions.php';
require_once NROS_DIR . 'includes/class-nros-media.php';
require_once NROS_DIR . 'includes/class-nros-ledger.php';
require_once NROS_DIR . 'includes/class-nros-analytics.php';
require_once NROS_DIR . 'includes/class-nros-areas.php';
require_once NROS_DIR . 'includes/class-nros-categories.php';
require_once NROS_DIR . 'includes/class-nros-admin.php';
require_once NROS_DIR . 'includes/class-nros-admin-api.php';
require_once NROS_DIR . 'includes/class-nros-admin-posts.php';
require_once NROS_DIR . 'includes/class-nros-ad-leads.php';
require_once NROS_DIR . 'includes/class-nros-prompts.php';
require_once NROS_DIR . 'includes/class-nros-videos.php';
require_once NROS_DIR . 'includes/class-nros-editorial.php';
require_once NROS_DIR . 'includes/class-nros-guest-authors.php';
require_once NROS_DIR . 'includes/class-nros-notifications.php';
require_once NROS_DIR . 'includes/class-nros-quotes.php';

// Round 10 — public reels/carousel shortcodes and the /video/{slug}/ page
// live in their own class, shipped separately; guarded so the plugin keeps
// working whether or not that file is present.
if ( file_exists( NROS_DIR . 'includes/class-nros-video-frontend.php' ) ) {
	require_once NROS_DIR . 'includes/class-nros-video-frontend.php';
}

// ---------------------------------------------------------------------------
// Activation / deactivation
// ---------------------------------------------------------------------------

register_activation_hook( __FILE__, array( 'NROS_Core', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'NROS_Core', 'deactivate' ) );

// ---------------------------------------------------------------------------
// Bootstrap — every class with route registrations/hooks gets its init()
// called once all plugins have loaded.
// ---------------------------------------------------------------------------

add_action(
	'plugins_loaded',
	function () {
		NROS_Core::init();
		NROS_Auth::init();
		NROS_Applications::init();
		NROS_Submissions::init();
		NROS_Media::init();
		NROS_Ledger::init();
		NROS_Analytics::init();
		NROS_Areas::init();
		NROS_Categories::init();
		NROS_Admin::init();
		NROS_Admin_API::init();
		NROS_Admin_Posts::init();
		NROS_Ad_Leads::init();
		NROS_Prompts::init();
		NROS_Videos::init();
		NROS_Editorial::init();
		NROS_Guest_Authors::init();
		NROS_Notifications::init();
		NROS_Quotes::init();
		if ( class_exists( 'NROS_Video_Frontend' ) ) {
			NROS_Video_Frontend::init();
		}
	}
);
