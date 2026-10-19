<?php
/**
 * Search index: three normalised post-meta fields per searchable post, rebuilt after every save (at
 * shutdown, when meta and terms are final — also for block-editor REST saves) and backfilled in batches
 * by WP-Cron / an admin button.
 *   _meeha_search_title  title                                 (relevance ×3)
 *   _meeha_search_sum    summary (`_meeha_summary`) or excerpt  (relevance ×2)
 *   _meeha_search_text   title + summary + body + tags + author + quote attribution  (match field, ×1)
 *   _meeha_search_v      index version
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

const MEEHA_SEARCH_INDEX_VERSION = 2;

/**
 * Searchable post types (results page "সব").
 *
 * @return string[]
 */
function meeha_search_post_types() {
	$types = array( 'post' );
	foreach ( array( 'meeha_video', 'meeha_photocard' ) as $t ) {
		if ( post_type_exists( $t ) ) {
			$types[] = $t;
		}
	}
	return (array) apply_filters( 'meeha_search_post_types', $types );
}

/**
 * Build the three index strings of a post.
 *
 * @param WP_Post $p Post.
 * @return array title, sum, text
 */
function meeha_search_index_fields( $p ) {
	$title = get_the_title( $p );
	$sum   = trim( (string) get_post_meta( $p->ID, '_meeha_summary', true ) );
	if ( '' === $sum ) {
		$sum = (string) $p->post_excerpt;
	}
	$body  = wp_strip_all_tags( strip_shortcodes( (string) $p->post_content ), true );
	$extra = array();
	$tags  = get_the_terms( $p->ID, 'post_tag' );
	if ( $tags && ! is_wp_error( $tags ) ) {
		$extra[] = implode( ' ', wp_list_pluck( $tags, 'name' ) );
	}
	$author = function_exists( 'meeha_post_byline' ) ? meeha_post_byline( $p )['name'] : get_the_author_meta( 'display_name', (int) $p->post_author ); // 2.0.3: guest author.
	if ( $author ) {
		$extra[] = $author;
	}
	$attr = (string) get_post_meta( $p->ID, '_meeha_attribution', true );
	if ( '' !== $attr ) {
		$extra[] = $attr;
	}
	$text = $title . ' ' . $sum . ' ' . $body . ' ' . implode( ' ', $extra );
	return array(
		'title' => meeha_search_normalize( $title ),
		'sum'   => meeha_search_normalize( $sum ),
		'text'  => mb_substr( meeha_search_normalize( $text ), 0, 60000 ),
	);
}

/**
 * Write one meta value, keeping exactly one row.
 *
 * @param int    $id  Post.
 * @param string $key Key.
 * @param mixed  $val Value.
 */
function meeha_search_set_meta( $id, $key, $val ) {
	$rows = get_post_meta( $id, $key );
	if ( count( $rows ) > 1 ) {
		delete_post_meta( $id, $key );
	}
	update_post_meta( $id, $key, $val );
}

/**
 * (Re)index one post.
 *
 * @param int $post_id Post.
 * @return bool
 */
function meeha_search_index_post( $post_id ) {
	$p = get_post( $post_id );
	if ( ! $p || ! in_array( $p->post_type, meeha_search_post_types(), true ) || wp_is_post_revision( $p ) ) {
		return false;
	}
	$f = meeha_search_index_fields( $p );
	meeha_search_set_meta( $p->ID, '_meeha_search_title', $f['title'] );
	meeha_search_set_meta( $p->ID, '_meeha_search_sum', $f['sum'] );
	meeha_search_set_meta( $p->ID, '_meeha_search_text', $f['text'] );
	meeha_search_set_meta( $p->ID, '_meeha_search_v', MEEHA_SEARCH_INDEX_VERSION );
	return true;
}

/**
 * Queue a post for indexing at shutdown.
 *
 * @param int $post_id Post.
 */
function meeha_search_queue( $post_id ) {
	$post_id = (int) $post_id;
	if ( ! $post_id || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}
	if ( empty( $GLOBALS['meeha_search_queue'] ) ) {
		$GLOBALS['meeha_search_queue'] = array();
		add_action( 'shutdown', 'meeha_search_flush_queue', 5 );
	}
	$GLOBALS['meeha_search_queue'][ $post_id ] = true;
}

/**
 * Index every queued post.
 */
function meeha_search_flush_queue() {
	$ids = array_keys( isset( $GLOBALS['meeha_search_queue'] ) ? $GLOBALS['meeha_search_queue'] : array() );
	$GLOBALS['meeha_search_queue'] = array();
	foreach ( $ids as $id ) {
		wp_cache_delete( $id, 'posts' );
		wp_cache_delete( $id, 'post_meta' );
		meeha_search_index_post( $id );
	}
}

add_action( 'save_post', 'meeha_search_queue', 30 );
add_action(
	'updated_post_meta',
	function ( $mid, $post_id, $key ) {
		if ( in_array( $key, array( '_meeha_summary', '_meeha_attribution', '_meeha_guest_author' ), true ) ) {
			meeha_search_queue( $post_id );
		}
	},
	10,
	3
);
add_action(
	'set_object_terms',
	function ( $object_id, $terms, $tt_ids, $taxonomy ) {
		if ( 'post_tag' === $taxonomy ) {
			meeha_search_queue( $object_id );
		}
	},
	10,
	4
);

/* --------------------------------------------------------------------------
 * Backfill
 * ------------------------------------------------------------------------ */

/**
 * Ids of published posts that miss the current index version.
 *
 * @param int $limit Limit.
 * @return int[]
 */
function meeha_search_unindexed( $limit = 100 ) {
	global $wpdb;
	$types = meeha_search_post_types();
	$ph    = implode( ',', array_fill( 0, count( $types ), '%s' ) );
	$args  = array_merge( array( (string) MEEHA_SEARCH_INDEX_VERSION ), $types, array( (int) $limit ) );
	return array_map(
		'intval',
		$wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} v ON v.post_id = p.ID AND v.meta_key = '_meeha_search_v' AND v.meta_value = %s
				WHERE v.meta_id IS NULL AND p.post_status = 'publish' AND p.post_type IN ({$ph})
				ORDER BY p.post_date DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL
				$args
			)
		)
	);
}

/**
 * Index status (for the settings tab).
 *
 * @return array total, done
 */
function meeha_search_index_status() {
	global $wpdb;
	$types = meeha_search_post_types();
	$ph    = implode( ',', array_fill( 0, count( $types ), '%s' ) );
	$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ({$ph})", $types ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	$done  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} v ON v.post_id = p.ID AND v.meta_key = '_meeha_search_v' AND v.meta_value = %s WHERE p.post_status = 'publish' AND p.post_type IN ({$ph})", array_merge( array( (string) MEEHA_SEARCH_INDEX_VERSION ), $types ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	return array(
		'total' => $total,
		'done'  => $done,
	);
}

/**
 * Index one batch; schedules the next batch when more remain.
 *
 * @param int $limit Batch size.
 * @return int Indexed.
 */
function meeha_search_backfill( $limit = 100 ) {
	$ids = meeha_search_unindexed( $limit );
	if ( $ids ) {
		_prime_post_caches( $ids, true, true );
	}
	foreach ( $ids as $id ) {
		meeha_search_index_post( $id );
	}
	if ( count( $ids ) >= $limit && ! wp_next_scheduled( 'meeha_search_backfill' ) ) {
		wp_schedule_single_event( time() + 60, 'meeha_search_backfill' );
	}
	if ( count( $ids ) < $limit ) {
		update_option( 'meeha_search_index_ver', MEEHA_SEARCH_INDEX_VERSION, true );
	}
	return count( $ids );
}
add_action(
	'meeha_search_backfill',
	function () {
		meeha_search_backfill( 100 );
	}
);
add_action(
	'init',
	function () {
		if ( (int) get_option( 'meeha_search_index_ver', 0 ) !== MEEHA_SEARCH_INDEX_VERSION && ! wp_next_scheduled( 'meeha_search_backfill' ) && ! wp_installing() ) {
			wp_schedule_single_event( time() + 30, 'meeha_search_backfill' );
		}
	},
	25
);

/**
 * Admin button (posted from the হোমপেজ settings form; reuses that form's nonce).
 */
add_action(
	'admin_post_meeha_search_reindex',
	function () {
		check_admin_referer( 'meeha_settings_home' );
		if ( ! current_user_can( 'manage_meeha_design' ) ) {
			wp_die( esc_html( 'অনুমতি নেই।' ), 403 );
		}
		if ( isset( $_POST['meeha_full'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			update_option( 'meeha_search_index_ver', 0, true );
		}
		// Force a rebuild of everything: drop the version marks, then index the first batches right away.
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_meeha_search_v'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		wp_cache_flush();
		$n = 0;
		for ( $i = 0; $i < 3; $i++ ) {
			$n += meeha_search_backfill( 100 );
		}
		if ( function_exists( 'meeha_settings_notice' ) ) {
			meeha_settings_notice( 'সার্চ ইনডেক্স: ' . meeha_bn_digits( $n ) . 'টি লেখা এখনই তৈরি হলো; বাকিগুলো পেছনে চলছে।', 'success' );
			set_transient( 'meeha_settings_notices_' . get_current_user_id(), $GLOBALS['meeha_settings_notices'], 120 );
		}
		wp_safe_redirect( meeha_settings_url( 'home' ) );
		exit;
	}
);
