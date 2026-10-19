<?php
/**
 * Bylines + guest authors ("অতিথি লেখক") — 2.0.3.
 *
 * The publisher app (newsroom-os ≥ 1.2.1) can give a post a guest byline in post meta
 * `_meeha_guest_author` = { name, title, photo_id }. The post still belongs to its WordPress
 * user (post_author: payments, ownership, author archive), but every byline on the front end
 * shows the guest instead. Templates call meeha_post_byline(); the `the_author` /
 * `get_the_author_display_name` filters below cover WordPress/plugin output for the post being
 * rendered (RSS dc:creator, plugins using get_the_author()), and Rank Math's JSON-LD author is
 * rewritten when Rank Math is active.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

/**
 * The post's guest author, or null.
 *
 * @param WP_Post|int|null $post Post.
 * @return array|null { name, title, photo_id }
 */
function meeha_guest_author( $post = null ) {
	$post = get_post( $post );
	if ( ! $post ) {
		return null;
	}
	$g = get_post_meta( $post->ID, '_meeha_guest_author', true );
	if ( ! is_array( $g ) ) {
		return null;
	}
	$name = isset( $g['name'] ) ? trim( wp_strip_all_tags( (string) $g['name'] ) ) : '';
	if ( '' === $name ) {
		return null;
	}
	return array(
		'name'     => $name,
		'title'    => isset( $g['title'] ) ? trim( wp_strip_all_tags( (string) $g['title'] ) ) : '',
		'photo_id' => isset( $g['photo_id'] ) ? absint( $g['photo_id'] ) : 0,
	);
}

/**
 * Everything a byline needs, for the guest author when the post has one, else the WP author.
 *
 * @param WP_Post|int|null $post Post.
 * @param int              $size Photo display size in px (the URL is for 2× that).
 * @return array { name, title, photo (URL or ''), url (author archive or null), is_guest, user_id }
 */
function meeha_post_byline( $post = null, $size = 48 ) {
	$post = get_post( $post );
	$out  = array(
		'name'     => '',
		'title'    => '',
		'photo'    => '',
		'url'      => null,
		'is_guest' => false,
		'user_id'  => 0,
	);
	if ( ! $post ) {
		return $out;
	}
	$g = meeha_guest_author( $post );
	if ( $g ) {
		$photo = '';
		if ( $g['photo_id'] && wp_attachment_is_image( $g['photo_id'] ) ) {
			$photo = (string) wp_get_attachment_image_url( $g['photo_id'], $size * 2 > 150 ? 'medium' : 'thumbnail' );
		}
		return array(
			'name'     => $g['name'],
			'title'    => $g['title'],
			'photo'    => $photo,
			'url'      => null,
			'is_guest' => true,
			'user_id'  => 0,
		);
	}
	$uid = (int) $post->post_author;
	if ( ! $uid ) {
		return $out;
	}
	return array(
		'name'     => (string) get_the_author_meta( 'display_name', $uid ),
		'title'    => function_exists( 'meeha_author_designation' ) ? meeha_author_designation( $uid ) : '',
		'photo'    => (string) get_avatar_url( $uid, array( 'size' => $size * 2 ) ),
		'url'      => get_author_posts_url( $uid ),
		'is_guest' => false,
		'user_id'  => $uid,
	);
}

/**
 * Neutral person silhouette (data URI) for a guest without a photo.
 *
 * @return string
 */
function meeha_byline_placeholder_src() {
	return 'data:image/svg+xml,' . rawurlencode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" fill="#e8edf3"/><circle cx="32" cy="25" r="11" fill="#a9b6c6"/><path d="M12 58c2-12 10-18 20-18s18 6 20 18z" fill="#a9b6c6"/></svg>' );
}

/**
 * <img> for a byline photo (same markup as meeha_single_avatar()).
 *
 * @param array  $byline From meeha_post_byline().
 * @param int    $size   Display size.
 * @param string $alt    Alt text.
 * @param bool   $eager  No lazy loading.
 * @return string
 */
function meeha_byline_photo_html( $byline, $size = 48, $alt = '', $eager = false ) {
	$src = ! empty( $byline['photo'] ) ? $byline['photo'] : ( ! empty( $byline['is_guest'] ) ? meeha_byline_placeholder_src() : '' );
	if ( '' === $src ) {
		return '';
	}
	return sprintf(
		'<img src="%s" width="%d" height="%d" alt="%s"%s decoding="async"%s>',
		0 === strpos( $src, 'data:' ) ? esc_attr( $src ) : esc_url( $src ),
		(int) $size,
		(int) $size,
		esc_attr( $alt ),
		$eager ? '' : ' loading="lazy"',
		! empty( $byline['photo'] ) && ! empty( $byline['is_guest'] ) ? ' style="object-fit:cover"' : ''
	);
}

/**
 * Whether the guest filters should act (front end, REST, feeds — not wp-admin screens).
 *
 * @return bool
 */
function meeha_byline_filters_active() {
	return ! is_admin() || wp_doing_ajax();
}

/**
 * `the_author` (get_the_author(), the_author(): RSS dc:creator, plugins) → guest name for the
 * post being rendered.
 *
 * @param string $name Name.
 * @return string
 */
function meeha_byline_filter_the_author( $name ) {
	global $authordata;
	if ( ! meeha_byline_filters_active() ) {
		return $name;
	}
	$post = get_post();
	if ( ! $post || ( is_object( $authordata ) && (int) $authordata->ID !== (int) $post->post_author ) ) {
		return $name;
	}
	$g = meeha_guest_author( $post );
	return $g ? $g['name'] : $name;
}
add_filter( 'the_author', 'meeha_byline_filter_the_author' );

/**
 * get_the_author_meta( 'display_name' ) WITHOUT an explicit user (= "this post's author") →
 * guest name. Calls with an explicit user ID (author archives, account pages) are untouched.
 *
 * @param string    $value            Value.
 * @param int       $user_id          Resolved user.
 * @param int|false $original_user_id User ID passed by the caller.
 * @return string
 */
function meeha_byline_filter_display_name( $value, $user_id, $original_user_id ) {
	if ( $original_user_id || ! meeha_byline_filters_active() ) {
		return $value;
	}
	$post = get_post();
	if ( ! $post || (int) $user_id !== (int) $post->post_author ) {
		return $value;
	}
	$g = meeha_guest_author( $post );
	return $g ? $g['name'] : $value;
}
add_filter( 'get_the_author_display_name', 'meeha_byline_filter_display_name', 10, 3 );

/**
 * the_author_posts_link(): a guest has no archive — plain name.
 *
 * @param string $link Link HTML.
 * @return string
 */
function meeha_byline_filter_posts_link( $link ) {
	if ( ! meeha_byline_filters_active() ) {
		return $link;
	}
	$g = meeha_guest_author( get_post() );
	return $g ? esc_html( $g['name'] ) : $link;
}
add_filter( 'the_author_posts_link', 'meeha_byline_filter_posts_link' );

/**
 * Rank Math JSON-LD: the Person node(s) of the WP author (and Article.author references to it)
 * become the guest on a singular post with a guest byline. No-op without Rank Math.
 *
 * @param array $data   Schema entities.
 * @param mixed $jsonld Rank Math JsonLD object (unused).
 * @return array
 */
function meeha_byline_rank_math_json_ld( $data, $jsonld = null ) {
	if ( ! is_array( $data ) || ! is_singular() ) {
		return $data;
	}
	$post = get_queried_object();
	if ( ! $post instanceof WP_Post ) {
		return $data;
	}
	$g = meeha_guest_author( $post );
	if ( ! $g ) {
		return $data;
	}
	$uid    = (int) $post->post_author;
	$person = array(
		'@type' => 'Person',
		'@id'   => get_permalink( $post ) . '#guest-author',
		'name'  => $g['name'],
	);
	if ( '' !== $g['title'] ) {
		$person['jobTitle'] = $g['title'];
	}
	$b = meeha_post_byline( $post, 96 );
	if ( '' !== $b['photo'] ) {
		$person['image'] = array(
			'@type' => 'ImageObject',
			'url'   => $b['photo'],
		);
	}
	$match = array(
		'name' => $uid ? (string) get_the_author_meta( 'display_name', $uid ) : '',
		'url'  => $uid ? untrailingslashit( (string) get_author_posts_url( $uid ) ) : '',
	);
	return meeha_byline_rank_math_walk( $data, $person, $match );
}
add_filter( 'rank_math/json_ld', 'meeha_byline_rank_math_json_ld', 99, 2 );

/**
 * Recursive helper for meeha_byline_rank_math_json_ld().
 *
 * @param array $node   Node.
 * @param array $person Guest Person.
 * @param array $match  WP author { name, url }.
 * @return array
 */
function meeha_byline_rank_math_walk( $node, $person, $match ) {
	$is_author = function ( $n ) use ( $match ) {
		if ( ! is_array( $n ) ) {
			return false;
		}
		$id   = isset( $n['@id'] ) ? (string) $n['@id'] : '';
		$type = isset( $n['@type'] ) ? (array) $n['@type'] : array();
		if ( '' !== $match['url'] && '' !== $id && untrailingslashit( preg_replace( '/#.*$/', '', $id ) ) === $match['url'] ) {
			return true;
		}
		return in_array( 'Person', $type, true ) && '' !== $match['name'] && isset( $n['name'] ) && $n['name'] === $match['name'];
	};
	foreach ( $node as $k => $v ) {
		if ( ! is_array( $v ) ) {
			continue;
		}
		if ( 'author' === $k ) {
			if ( $is_author( $v ) || ( isset( $v[0] ) && $is_author( $v[0] ) ) ) {
				$node[ $k ] = array(
					'@id'  => $person['@id'],
					'name' => $person['name'],
				);
			}
			continue;
		}
		if ( $is_author( $v ) && in_array( 'Person', isset( $v['@type'] ) ? (array) $v['@type'] : array(), true ) ) {
			$node[ $k ] = $person;
			continue;
		}
		$node[ $k ] = meeha_byline_rank_math_walk( $v, $person, $match );
	}
	return $node;
}

/**
 * Rank Math's "Written by" (Slack/Twitter enhanced data) → guest name.
 *
 * @param array $data Label → value.
 * @return array
 */
function meeha_byline_rank_math_slack( $data ) {
	if ( ! is_array( $data ) || ! is_singular() ) {
		return $data;
	}
	$post = get_queried_object();
	$g    = $post instanceof WP_Post ? meeha_guest_author( $post ) : null;
	if ( ! $g ) {
		return $data;
	}
	$user_name = (string) get_the_author_meta( 'display_name', (int) $post->post_author );
	foreach ( $data as $label => $value ) {
		if ( is_string( $value ) && $value === $user_name ) {
			$data[ $label ] = $g['name'];
		}
	}
	return $data;
}
add_filter( 'rank_math/opengraph/slack_enhanced_data', 'meeha_byline_rank_math_slack', 99 );
