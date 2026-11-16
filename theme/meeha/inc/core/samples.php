<?php
/**
 * নমুনা কনটেন্ট (CONTRACT §9.2): create 2–3 honest, evergreen sample posts in every category that has no published
 * posts; delete them again. Every sample post and sample image carries meta `_meeha_sample = 1`.
 *
 * API: meeha_samples_empty_categories(), meeha_samples_create( $status = 'draft', $per = 3 ), meeha_samples_delete(),
 *      meeha_samples_count().
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/samples-data.php';

/**
 * Categories with no published posts (and no sample posts yet).
 *
 * @param bool $include_sampled Include categories that already hold (draft) samples.
 * @return WP_Term[]
 */
function meeha_samples_empty_categories( $include_sampled = false ) {
	$out = array();
	foreach ( get_categories( array( 'hide_empty' => false ) ) as $c ) {
		if ( 'uncategorized' === $c->slug ) {
			continue;
		}
		$published = get_posts(
			array(
				'post_type'        => 'post',
				'post_status'      => 'publish',
				'category__in'     => array( $c->term_id ),
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
			)
		);
		if ( $published ) {
			continue;
		}
		if ( ! $include_sampled && meeha_samples_count( $c->term_id ) ) {
			continue;
		}
		$out[] = $c;
	}
	return $out;
}

/**
 * Number of sample posts (any status), optionally within one category.
 *
 * @param int $cat_id Category or 0.
 * @return int
 */
function meeha_samples_count( $cat_id = 0 ) {
	$args = array(
		'post_type'        => 'post',
		'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future' ),
		'posts_per_page'   => -1,
		'fields'           => 'ids',
		'no_found_rows'    => true,
		'suppress_filters' => true,
		'meta_key'         => '_meeha_sample', // phpcs:ignore WordPress.DB.SlowDBQuery
		'meta_value'       => '1', // phpcs:ignore WordPress.DB.SlowDBQuery
	);
	if ( $cat_id ) {
		$args['category__in'] = array( (int) $cat_id );
	}
	return count( get_posts( $args ) );
}

/**
 * Attachment ID of a bundled sample image (imported once into the media library).
 *
 * @param string $key Image key (hills, river, heritage, wall, book, feather, lantern, desk).
 * @return int
 */
function meeha_samples_image_id( $key ) {
	$key  = sanitize_key( $key );
	$file = MEEHA_DIR . '/assets/img/samples/sample-' . $key . '.jpg';
	if ( ! is_readable( $file ) ) {
		return 0;
	}
	$found = get_posts(
		array(
			'post_type'        => 'attachment',
			'post_status'      => 'inherit',
			'posts_per_page'   => 1,
			'fields'           => 'ids',
			'meta_key'         => '_meeha_sample_image', // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value'       => $key, // phpcs:ignore WordPress.DB.SlowDBQuery
			'suppress_filters' => true,
		)
	);
	if ( $found ) {
		return (int) $found[0];
	}
	require_once ABSPATH . 'wp-admin/includes/image.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	$tmp = wp_tempnam( 'meeha-sample-' . $key . '.jpg' );
	if ( ! $tmp || ! copy( $file, $tmp ) ) {
		return 0;
	}
	$id = media_handle_sideload(
		array(
			'name'     => 'meeha-sample-' . $key . '.jpg',
			'tmp_name' => $tmp,
		),
		0,
		'নমুনা ছবি'
	);
	if ( is_wp_error( $id ) ) {
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return 0;
	}
	update_post_meta( $id, '_meeha_sample', 1 );
	update_post_meta( $id, '_meeha_sample_image', $key );
	update_post_meta( $id, '_wp_attachment_image_alt', '' ); // decorative illustration.
	wp_update_post(
		array(
			'ID'           => $id,
			'post_excerpt' => 'অলংকরণ: মিহা নিউজ',
		)
	);
	return (int) $id;
}

/**
 * Serialize a sample body to block markup.
 *
 * @param array $body Body spec.
 * @return string
 */
function meeha_samples_body( $body ) {
	$out = '';
	foreach ( $body as $b ) {
		list( $type, $val ) = $b;
		switch ( $type ) {
			case 'h2':
				$out .= meeha_block( 'heading', array(), '<h2 class="wp-block-heading">' . esc_html( $val ) . '</h2>' );
				break;
			case 'ul':
				$items = '';
				foreach ( (array) $val as $li ) {
					$items .= meeha_block( 'list-item', array(), '<li>' . esc_html( $li ) . '</li>' );
				}
				$out .= meeha_block( 'list', array(), '<ul class="wp-block-list">' . "\n" . $items . '</ul>' );
				break;
			case 'poem':
				$out .= meeha_block( 'paragraph', array(), '<p>' . implode( '<br>', array_map( 'esc_html', explode( "\n", $val ) ) ) . '</p>' );
				break;
			default:
				$out .= meeha_block( 'paragraph', array(), '<p>' . esc_html( $val ) . '</p>' );
		}
	}
	return trim( $out );
}

/**
 * Create sample posts in every empty category.
 *
 * @param string $status draft|publish.
 * @param int    $per    Posts per category (2–3; generic categories get at most 2).
 * @return array [ category name => created count ]
 */
function meeha_samples_create( $status = 'draft', $per = 3 ) {
	$status  = 'publish' === $status ? 'publish' : 'draft';
	$per     = max( 1, min( 3, (int) $per ) );
	$lib     = meeha_samples_library();
	$author  = get_current_user_id();
	if ( ! $author ) {
		$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
		$author = $admins ? (int) $admins[0] : 1;
	}
	$report = array();
	$k      = 0;
	foreach ( meeha_samples_empty_categories() as $c ) {
		$topic = meeha_samples_topic_for( $c );
		$items = ( $topic && isset( $lib[ $topic ] ) ) ? $lib[ $topic ] : meeha_samples_generic( $c );
		$items = array_slice( $items, 0, $per );
		$made  = 0;
		foreach ( $items as $i => $it ) {
			$id = wp_insert_post(
				wp_slash(
					array(
						'post_type'      => 'post',
						'post_status'    => $status,
						'post_title'     => $it['title'],
						'post_excerpt'   => $it['summary'],
						'post_content'   => meeha_samples_body( $it['body'] ),
						'post_category'  => array( (int) $c->term_id ),
						'post_author'    => $author,
						'post_date'      => wp_date( 'Y-m-d H:i:s', time() - ( 6 + 9 * $k + 26 * $i ) * HOUR_IN_SECONDS ),
						'comment_status' => 'open',
					)
				),
				true
			);
			$k++;
			if ( is_wp_error( $id ) ) {
				continue;
			}
			update_post_meta( $id, '_meeha_sample', 1 );
			update_post_meta( $id, '_meeha_summary', $it['summary'] );
			$img = meeha_samples_image_id( $it['image'] );
			if ( $img ) {
				set_post_thumbnail( $id, $img );
			}
			$made++;
		}
		$report[ $c->name ] = $made;
	}
	/**
	 * After sample posts were created.
	 *
	 * @param array  $report Category name => count.
	 * @param string $status Status.
	 */
	do_action( 'meeha_samples_created', $report, $status );
	return $report;
}

/**
 * Delete every `_meeha_sample = 1` post and sample image.
 *
 * @return array posts, images
 */
function meeha_samples_delete() {
	$posts = get_posts(
		array(
			'post_type'        => 'any',
			'post_status'      => 'any',
			'posts_per_page'   => -1,
			'fields'           => 'ids',
			'suppress_filters' => true,
			'meta_key'         => '_meeha_sample', // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value'       => '1', // phpcs:ignore WordPress.DB.SlowDBQuery
		)
	);
	$np = 0;
	foreach ( $posts as $id ) {
		if ( 'attachment' !== get_post_type( $id ) && wp_delete_post( $id, true ) ) {
			$np++;
		}
	}
	$atts = get_posts(
		array(
			'post_type'        => 'attachment',
			'post_status'      => 'any',
			'posts_per_page'   => -1,
			'fields'           => 'ids',
			'suppress_filters' => true,
			'meta_key'         => '_meeha_sample', // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value'       => '1', // phpcs:ignore WordPress.DB.SlowDBQuery
		)
	);
	$ni = 0;
	foreach ( $atts as $id ) {
		if ( wp_delete_attachment( $id, true ) ) {
			$ni++;
		}
	}
	return array(
		'posts'  => $np,
		'images' => $ni,
	);
}

add_action(
	'admin_post_meeha_samples',
	function () {
		check_admin_referer( 'meeha_samples' );
		if ( ! current_user_can( 'manage_meeha_design' ) || ! current_user_can( 'publish_posts' ) || ! current_user_can( 'upload_files' ) ) {
			wp_die( esc_html( 'অনুমতি নেই।' ), 403 );
		}
		$do = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		if ( 'create' === $do ) {
			$status = isset( $_POST['status'] ) && 'publish' === $_POST['status'] ? 'publish' : 'draft';
			$per    = isset( $_POST['per'] ) ? absint( $_POST['per'] ) : 3;
			$r      = meeha_samples_create( $status, $per );
			$msg    = $r ? meeha_bn_digits( array_sum( $r ) ) . 'টি নমুনা পোস্ট তৈরি হয়েছে (' . ( 'publish' === $status ? 'প্রকাশিত' : 'খসড়া' ) . '): ' . implode( ', ', array_keys( $r ) ) : 'কোনো খালি বিভাগ পাওয়া যায়নি।';
			$notice = array( array( $msg, $r ? 'success' : 'info' ) );
		} elseif ( 'delete' === $do ) {
			$r      = meeha_samples_delete();
			$notice = array( array( meeha_bn_digits( $r['posts'] ) . 'টি নমুনা পোস্ট ও ' . meeha_bn_digits( $r['images'] ) . 'টি নমুনা ছবি মুছে ফেলা হয়েছে।', 'success' ) );
		} else {
			$notice = array();
		}
		set_transient( 'meeha_settings_notices_' . get_current_user_id(), $notice, 120 );
		wp_safe_redirect( meeha_settings_url( 'tools' ) . '#meeha-samples' );
		exit;
	}
);

/**
 * Tools-tab section.
 */
function meeha_samples_tools_section() {
	$empty   = meeha_samples_empty_categories( true );
	$count   = meeha_samples_count();
	$can     = current_user_can( 'manage_meeha_design' ) && current_user_can( 'publish_posts' ) && current_user_can( 'upload_files' );
	$action  = esc_url( admin_url( 'admin-post.php' ) );
	echo '<hr><h2 id="meeha-samples">নমুনা কনটেন্ট</h2>';
	echo '<p>যেসব বিভাগে এখনো কোনো প্রকাশিত লেখা নেই, সেগুলোতে ২–৩টি করে স্থায়ী-মূল্যের (evergreen) নমুনা লেখা তৈরি করে — পরিচিতি, ব্যাখ্যা, কীভাবে-করবেন ধরনের লেখা, মৌলিক কবিতা ও স্পষ্টভাবে কাল্পনিক গল্প। কোনো বানানো খবর, পরিসংখ্যান বা বাস্তব মানুষের উক্তি নেই। ছবিগুলো থিমের সঙ্গে আসা অলংকরণ। প্রতিটি নমুনায় <code>_meeha_sample</code> চিহ্ন থাকে, তাই এক ক্লিকে সব মুছে ফেলা যায়।</p>';
	if ( $empty ) {
		$names = array();
		foreach ( $empty as $c ) {
			$n       = meeha_samples_count( $c->term_id );
			$names[] = esc_html( $c->name ) . ( $n ? ' <em>(নমুনা আছে: ' . esc_html( meeha_bn_digits( $n ) ) . ')</em>' : '' );
		}
		echo '<p><strong>খালি বিভাগ:</strong> ' . implode( ', ', $names ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
	} else {
		echo '<p><em>সব বিভাগেই প্রকাশিত লেখা আছে।</em></p>';
	}
	if ( ! $can ) {
		return;
	}
	echo '<form method="post" action="' . $action . '" class="meeha-samples-form">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	wp_nonce_field( 'meeha_samples' );
	echo '<input type="hidden" name="action" value="meeha_samples"><input type="hidden" name="do" value="create">';
	echo '<fieldset><legend class="screen-reader-text">অবস্থা</legend><label><input type="radio" name="status" value="draft" checked> খসড়া (প্রথমে দেখে নিন)</label> &nbsp; <label><input type="radio" name="status" value="publish"> সরাসরি প্রকাশিত</label></fieldset>';
	echo '<p><label>প্রতি বিভাগে <select name="per"><option value="2">২টি</option><option value="3" selected>৩টি</option></select> লেখা</label></p>';
	echo '<p><button type="submit" class="button button-primary">নমুনা পোস্ট তৈরি করুন</button></p></form>';
	if ( $count ) {
		echo '<form method="post" action="' . $action . '" onsubmit="return confirm(\'সব নমুনা পোস্ট ও নমুনা ছবি স্থায়ীভাবে মুছে যাবে। নিশ্চিত?\')">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		wp_nonce_field( 'meeha_samples' );
		echo '<input type="hidden" name="action" value="meeha_samples"><input type="hidden" name="do" value="delete">';
		echo '<p>এখন ' . esc_html( meeha_bn_digits( $count ) ) . 'টি নমুনা পোস্ট আছে। <button type="submit" class="button button-link-delete">সব নমুনা পোস্ট মুছুন</button></p></form>';
	}
}
