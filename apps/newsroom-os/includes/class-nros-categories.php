<?php
/**
 * NROS_Categories — read-only listing of the site's real WordPress
 * categories, for the correspondent app's "বিষয় (ক্যাটেগরি)" selector on the
 * Write screen. Correspondents pick from the site's actual taxonomy (so the
 * published post is properly categorized on the front end) rather than
 * typing a free-text topic that never connected to anything.
 *
 * @package NewsroomOS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NROS_Categories {

	/**
	 * Registers routes.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Registers REST routes under nros/v1. Requires a logged-in app user
	 * (correspondent OR staff, not public) — the category list is needed
	 * inside both the correspondent app's Write screen and the Publisher
	 * app's post editor.
	 */
	public static function register_routes() {
		register_rest_route(
			NROS_API_NS,
			'/categories',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_categories' ),
				'permission_callback' => array( 'NROS_Core', 'require_app_user' ),
			)
		);
	}

	/**
	 * GET /categories — every category on the site, alphabetically, as a
	 * flat array. Round 18 adds `parent` (0 for a top-level category) and
	 * `count` (published posts) to each item, so both apps can show the
	 * main categories first, drill into sub-categories and rank by
	 * popularity. Older app builds ignore the extra keys.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function get_categories( $request ) {
		$terms = get_categories(
			array(
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		$default = (int) get_option( 'default_category' );
		$items   = array();
		foreach ( $terms as $term ) {
			$items[] = array(
				'id'         => (int) $term->term_id,
				'name'       => html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ),
				'slug'       => $term->slug,
				'parent'     => (int) $term->parent,
				'count'      => (int) $term->count,
				'is_default' => (int) $term->term_id === $default,
			);
		}

		return new WP_REST_Response( $items, 200 );
	}
}
