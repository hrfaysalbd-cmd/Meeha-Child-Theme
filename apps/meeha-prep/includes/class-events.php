<?php
/**
 * Events (job fairs, workshops, live sessions). Free registration and paid tickets both go through checkout
 * (a free item is granted at once); a registration is an access row of type "event".
 *
 * @package meeha-prep
 */

defined( 'ABSPATH' ) || exit;

/**
 * Events.
 */
class MHP_Events {

	/**
	 * Hooks.
	 */
	public static function init() {}

	/**
	 * Event data.
	 *
	 * @param WP_Post|int $post Event.
	 * @return array|null
	 */
	public static function data( $post ) {
		$post = get_post( $post );
		if ( ! $post || 'mh_event' !== $post->post_type ) {
			return null;
		}
		$id   = $post->ID;
		$date = (string) mhp_meta( $id, 'date', '' );
		$cap  = (int) mhp_meta( $id, 'capacity', 0 );
		return array(
			'id'       => $id,
			'title'    => get_the_title( $post ),
			'date'     => $date,
			'days'     => mhp_days_left( $date ),
			'time'     => (string) mhp_meta( $id, 'time', '' ),
			'venue'    => (string) mhp_meta( $id, 'venue', '' ),
			'online'   => (string) mhp_meta( $id, 'online_url', '' ),
			'price'    => round( (float) mhp_meta( $id, 'price', 0 ), 2 ),
			'capacity' => $cap,
			'taken'    => MHP_Access::count_item( 'event', $id ),
			'reg_open' => (bool) mhp_meta( $id, 'reg_open', 1 ),
			'sponsor'  => (string) mhp_meta( $id, 'sponsor_note', '' ),
			'content'  => $post->post_content,
			'thumb'    => (int) get_post_thumbnail_id( $post ),
		);
	}

	/**
	 * Upcoming events (today or later).
	 *
	 * @param int $limit Limit.
	 * @return array[]
	 */
	public static function upcoming( $limit = 10 ) {
		$posts = get_posts(
			array(
				'post_type'      => 'mh_event',
				'post_status'    => 'publish',
				'posts_per_page' => (int) $limit,
				'no_found_rows'  => true,
				'meta_key'       => '_mhp_date', // phpcs:ignore WordPress.DB.SlowDBQuery
				'orderby'        => 'meta_value',
				'order'          => 'ASC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'     => '_mhp_date',
						'value'   => mhp_today(),
						'compare' => '>=',
						'type'    => 'CHAR',
					),
				),
			)
		);
		return array_values( array_filter( array_map( array( __CLASS__, 'data' ), $posts ) ) );
	}

	/**
	 * A user's registration row for an event.
	 *
	 * @param int $user_id  User.
	 * @param int $event_id Event.
	 * @return array|null id, created_at, code
	 */
	public static function ticket( $user_id, $event_id ) {
		global $wpdb;
		if ( ! $user_id ) {
			return null;
		}
		$t = MHP_Install::table( 'access' );
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT id, created_at FROM {$t} WHERE user_id = %d AND item_type = 'event' AND item_id = %d", (int) $user_id, (int) $event_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! $r ) {
			return null;
		}
		$r['code'] = MHP_Access::ticket_code( (int) $r['id'], (int) $user_id );
		return $r;
	}
}
