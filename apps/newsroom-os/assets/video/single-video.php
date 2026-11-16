<?php
/**
 * Template for a single meeha_video (/video/{slug}/): the reels experience
 * starting at this video, inside the theme's header and footer.
 * Loaded via NROS_Video_Frontend::template_include(); a theme can override it
 * with its own single-meeha_video.php.
 *
 * @package NewsroomOS
 */

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'NROS_Video_Frontend' ) ) {
	exit;
}

NROS_Video_Frontend::render_single_page();
