<?php
/**
 * Live posts module (2.4.8): লাইভ ইভেন্ট and অপরাধ তদন্ত লাইভ — one post, timestamped updates that reach open pages
 * by themselves. See data.php for the model; the publisher / correspondent apps write through newsroom-os.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

foreach ( array( 'data', 'render', 'rest' ) as $meeha_lb_file ) {
	require_once __DIR__ . '/' . $meeha_lb_file . '.php';
}
unset( $meeha_lb_file );

/**
 * Is the current request a live post's own page?
 *
 * @return WP_Post|null
 */
function meeha_lb_current() {
	if ( ! is_singular( 'post' ) ) {
		return null;
	}
	$p = get_queried_object();
	return ( $p instanceof WP_Post && meeha_lb_is_live_post( $p ) ) ? $p : null;
}

add_action(
	'wp',
	function () {
		if ( ! is_admin() && meeha_lb_current() ) {
			meeha_enqueue_bundle( 'liveblog' );
		}
	},
	20
);

add_filter(
	'body_class',
	function ( $classes ) {
		$p = meeha_lb_current();
		if ( $p ) {
			$classes[] = 'm-has-live';
			$classes[] = 'm-live-' . sanitize_html_class( meeha_lb_status( $p ) );
		}
		return $classes;
	}
);

/**
 * Template hook: the status / scoreboard / case file, shown under the article header.
 *
 * @param WP_Post $post Post.
 */
function meeha_lb_render_top( $post ) {
	$cfg = meeha_lb_config( $post );
	if ( $cfg ) {
		echo '<div data-lb-top>' . meeha_lb_top_html( $post, $cfg ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the renderer.
	}
}

/**
 * Template hook: the feed, shown after the article body (the body is the intro / background).
 *
 * @param WP_Post $post Post.
 */
function meeha_lb_render_feed( $post ) {
	$cfg = meeha_lb_config( $post );
	if ( $cfg ) {
		echo meeha_lb_feed_html( $post, $cfg ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the renderer.
	}
}

/**
 * Badge for story cards / labels: '' unless the post is a live post.
 *
 * @param WP_Post|int $post Post.
 * @return string
 */
function meeha_lb_card_badge( $post ) {
	$s = meeha_lb_status( $post );
	if ( 'live' !== $s && 'paused' !== $s ) {
		return '';
	}
	return meeha_lb_badge_html( $s, 'm-lb-badge--card' );
}

/* Google: LiveBlogPosting with the newest updates (shows the "LIVE" label in search results while it runs). */
add_action(
	'wp_head',
	function () {
		$p = meeha_lb_current();
		if ( ! $p || post_password_required( $p ) ) {
			return;
		}
		$cfg = meeha_lb_config( $p );
		if ( ! $cfg ) {
			return;
		}
		$updates = array();
		foreach ( meeha_lb_entries( $p->ID, array( 'limit' => 20 ) ) as $e ) {
			$text      = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $e->post_content ) ) );
			$updates[] = array(
				'@type'         => 'BlogPosting',
				'headline'      => '' !== trim( $e->post_title ) ? $e->post_title : wp_trim_words( $text, 14, '…' ),
				'datePublished' => get_post_time( 'c', true, $e ),
				'dateModified'  => get_post_modified_time( 'c', true, $e ),
				'articleBody'   => wp_trim_words( $text, 80, '…' ),
				'url'           => get_permalink( $p ) . '#lb-' . $e->ID,
				'author'        => array(
					'@type' => 'Person',
					'name'  => get_the_author_meta( 'display_name', (int) $e->post_author ),
				),
			);
		}
		$ld = array(
			'@context'          => 'https://schema.org',
			'@type'             => 'LiveBlogPosting',
			'@id'               => get_permalink( $p ) . '#live',
			'url'               => get_permalink( $p ),
			'headline'          => get_the_title( $p ),
			'datePublished'     => get_post_time( 'c', true, $p ),
			'dateModified'      => get_post_modified_time( 'c', true, $p ),
			'coverageStartTime' => gmdate( 'c', $cfg['started'] ? $cfg['started'] : (int) get_post_time( 'U', true, $p ) ),
			'publisher'         => array(
				'@type' => 'Organization',
				'name'  => get_bloginfo( 'name' ),
			),
			'liveBlogUpdate'    => $updates,
		);
		if ( 'ended' === $cfg['status'] && $cfg['ended'] ) {
			$ld['coverageEndTime'] = gmdate( 'c', $cfg['ended'] );
		}
		if ( has_post_thumbnail( $p ) ) {
			$ld['image'] = get_the_post_thumbnail_url( $p, 'full' );
		}
		echo '<script type="application/ld+json">' . wp_json_encode( $ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "</script>\n";
	},
	30
);
