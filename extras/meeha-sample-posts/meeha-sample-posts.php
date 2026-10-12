<?php
/**
 * Plugin Name: Meeha Sample Posts
 * Description: যে ক্যাটেগরিতে কোনো পোস্ট নেই, সেখানে এক ক্লিকে নমুনা পোস্ট বসায়; পরে এক ক্লিকে সব মুছে ফেলা যায়। নমুনা পোস্ট সার্চ ইঞ্জিনে noindex থাকে।
 * Version: 1.0.0
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Author: Meeha News
 * License: GPL-2.0-or-later
 * Text Domain: meeha-sample-posts
 */

defined( 'ABSPATH' ) || exit;

const MEEHA_SP_META = '_meeha_sample_post';

/**
 * Admin page under Tools.
 */
add_action(
	'admin_menu',
	function () {
		add_management_page( 'নমুনা পোস্ট', 'নমুনা পোস্ট', 'manage_options', 'meeha-sample-posts', 'meeha_sp_render_page' );
	}
);

/**
 * Categories with no published posts (the default category is skipped).
 *
 * @return WP_Term[]
 */
function meeha_sp_empty_categories() {
	$default = (int) get_option( 'default_category' );
	$terms   = get_terms(
		array(
			'taxonomy'   => 'category',
			'hide_empty' => false,
		)
	);
	if ( is_wp_error( $terms ) ) {
		return array();
	}
	return array_values(
		array_filter(
			$terms,
			function ( $t ) use ( $default ) {
				return 0 === (int) $t->count && (int) $t->term_id !== $default;
			}
		)
	);
}

/**
 * IDs of every sample post this plugin created.
 *
 * @return int[]
 */
function meeha_sp_sample_ids() {
	return get_posts(
		array(
			'post_type'      => 'post',
			'post_status'    => 'any',
			'meta_key'       => MEEHA_SP_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		)
	);
}

/**
 * Generate a soft gradient placeholder image and attach it. Returns 0 if GD is missing.
 *
 * @param int $post_id Post to attach to.
 * @param int $index   Used to vary the colours.
 * @return int Attachment ID or 0.
 */
function meeha_sp_placeholder_image( $post_id, $index ) {
	if ( ! function_exists( 'imagecreatetruecolor' ) ) {
		return 0;
	}
	$palettes = array(
		array( array( 2, 90, 178 ), array( 120, 180, 230 ) ),
		array( array( 0, 106, 78 ), array( 160, 210, 170 ) ),
		array( array( 180, 60, 40 ), array( 245, 190, 140 ) ),
		array( array( 70, 60, 140 ), array( 190, 170, 230 ) ),
		array( array( 40, 70, 90 ), array( 170, 200, 210 ) ),
		array( array( 150, 110, 20 ), array( 240, 215, 150 ) ),
	);
	list( $a, $b ) = $palettes[ $index % count( $palettes ) ];

	$w  = 1200;
	$h  = 675;
	$im = imagecreatetruecolor( $w, $h );
	for ( $y = 0; $y < $h; $y++ ) {
		$t = $y / $h;
		$c = imagecolorallocate(
			$im,
			(int) ( $a[0] + ( $b[0] - $a[0] ) * $t ),
			(int) ( $a[1] + ( $b[1] - $a[1] ) * $t ),
			(int) ( $a[2] + ( $b[2] - $a[2] ) * $t )
		);
		imageline( $im, 0, $y, $w, $y, $c );
	}
	// A few soft circles so it doesn't look flat.
	$white = imagecolorallocatealpha( $im, 255, 255, 255, 105 );
	imagefilledellipse( $im, (int) ( $w * 0.72 ), (int) ( $h * 0.35 ), 260, 260, $white );
	imagefilledellipse( $im, (int) ( $w * 0.2 ), (int) ( $h * 0.85 ), 420, 420, $white );

	$upload = wp_upload_dir();
	if ( ! empty( $upload['error'] ) ) {
		imagedestroy( $im );
		return 0;
	}
	$file = trailingslashit( $upload['path'] ) . wp_unique_filename( $upload['path'], 'meeha-sample-' . $post_id . '.jpg' );
	imagejpeg( $im, $file, 82 );
	imagedestroy( $im );

	$attach_id = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/jpeg',
			'post_title'     => 'নমুনা ছবি',
			'post_status'    => 'inherit',
		),
		$file,
		$post_id
	);
	if ( is_wp_error( $attach_id ) || ! $attach_id ) {
		return 0;
	}
	require_once ABSPATH . 'wp-admin/includes/image.php';
	wp_update_attachment_metadata( $attach_id, wp_generate_attachment_metadata( $attach_id, $file ) );
	update_post_meta( $attach_id, MEEHA_SP_META, 1 );
	return (int) $attach_id;
}

/**
 * Sample body. Clearly labelled as a placeholder so it is never mistaken for news.
 *
 * @param string $cat Category name.
 * @return string
 */
function meeha_sp_body( $cat ) {
	$p = array(
		'এটি একটি <strong>নমুনা পোস্ট</strong>। "' . esc_html( $cat ) . '" বিভাগে আসল সংবাদ প্রকাশ না হওয়া পর্যন্ত পাতার নকশা ও বিন্যাস কেমন দেখায়, তা বোঝাতে এটি বসানো হয়েছে।',
		'এখানে সংবাদের মূল অংশ থাকবে। প্রথম অনুচ্ছেদে সাধারণত ঘটনার মূল তথ্য থাকে: কী ঘটেছে, কোথায়, কখন এবং কারা জড়িত। পাঠক যেন প্রথম কয়েক লাইনেই পুরো বিষয়টা বুঝে যান, সেভাবে লেখা হয়।',
		'পরের অনুচ্ছেদগুলোতে প্রেক্ষাপট, সংশ্লিষ্টদের বক্তব্য ও বিস্তারিত তথ্য যুক্ত হয়। দীর্ঘ লেখায় উপশিরোনাম, উদ্ধৃতি ও ছবি ব্যবহার করলে পড়া সহজ হয়।',
		'<blockquote>"এটি একটি নমুনা উদ্ধৃতি, যা দেখাবে উদ্ধৃতির নকশা কেমন দেখায়।"</blockquote>',
		'শেষ অংশে পরবর্তী পদক্ষেপ বা ঘটনার সম্ভাব্য প্রভাব তুলে ধরা হয়। আসল সংবাদ প্রকাশের পর এই নমুনা পোস্টটি টুলস → নমুনা পোস্ট থেকে মুছে ফেলুন।',
	);
	return '<p>' . implode( "</p>\n<p>", array_slice( $p, 0, 3 ) ) . "</p>\n" . $p[3] . "\n<p>" . $p[4] . '</p>';
}

/**
 * Create $per sample posts in each empty category.
 *
 * @param int  $per        Posts per category.
 * @param bool $with_image Generate a placeholder featured image.
 * @return int Number of posts created.
 */
function meeha_sp_create( $per, $with_image ) {
	$created = 0;
	$author  = get_current_user_id();
	foreach ( meeha_sp_empty_categories() as $i => $cat ) {
		for ( $n = 1; $n <= $per; $n++ ) {
			$title = sprintf( '%s: নমুনা সংবাদ শিরোনাম %s', $cat->name, meeha_sp_bn_digits( $n ) );
			$id    = wp_insert_post(
				array(
					'post_type'     => 'post',
					'post_status'   => 'publish',
					'post_title'    => $title,
					'post_excerpt'  => 'এটি একটি নমুনা পোস্ট। আসল সংবাদ প্রকাশ হলে এটি মুছে ফেলা হবে।',
					'post_content'  => meeha_sp_body( $cat->name ),
					'post_category' => array( $cat->term_id ),
					'post_author'   => $author,
					// Stagger times so ordering looks natural.
					'post_date'     => wp_date( 'Y-m-d H:i:s', time() - ( $i * $per + $n ) * 37 * MINUTE_IN_SECONDS ),
				),
				true
			);
			if ( is_wp_error( $id ) ) {
				continue;
			}
			update_post_meta( $id, MEEHA_SP_META, 1 );
			update_post_meta( $id, 'rank_math_robots', array( 'noindex', 'nofollow' ) );
			if ( $with_image ) {
				$img = meeha_sp_placeholder_image( $id, $i + $n );
				if ( $img ) {
					set_post_thumbnail( $id, $img );
				}
			}
			++$created;
		}
	}
	return $created;
}

/**
 * Delete every sample post and its generated images.
 *
 * @return int Number of posts deleted.
 */
function meeha_sp_delete_all() {
	$n = 0;
	foreach ( meeha_sp_sample_ids() as $id ) {
		$thumb = (int) get_post_thumbnail_id( $id );
		if ( $thumb && get_post_meta( $thumb, MEEHA_SP_META, true ) ) {
			wp_delete_attachment( $thumb, true );
		}
		if ( wp_delete_post( $id, true ) ) {
			++$n;
		}
	}
	return $n;
}

/**
 * Latin → Bengali digits.
 *
 * @param int|string $n Number.
 * @return string
 */
function meeha_sp_bn_digits( $n ) {
	return strtr( (string) $n, array_combine( range( 0, 9 ), array( '০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯' ) ) );
}

/**
 * Handle the two form actions.
 */
add_action(
	'admin_post_meeha_sp',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'অনুমতি নেই।', 403 );
		}
		check_admin_referer( 'meeha_sp' );
		$do  = isset( $_POST['do'] ) ? sanitize_key( $_POST['do'] ) : '';
		$msg = '';
		if ( 'create' === $do ) {
			$per = isset( $_POST['per'] ) ? max( 1, min( 6, (int) $_POST['per'] ) ) : 3;
			$msg = 'created:' . meeha_sp_create( $per, ! empty( $_POST['image'] ) );
		} elseif ( 'delete' === $do ) {
			$msg = 'deleted:' . meeha_sp_delete_all();
		}
		wp_safe_redirect( add_query_arg( 'meeha_sp_msg', rawurlencode( $msg ), admin_url( 'tools.php?page=meeha-sample-posts' ) ) );
		exit;
	}
);

/**
 * Admin page.
 */
function meeha_sp_render_page() {
	$empty   = meeha_sp_empty_categories();
	$samples = meeha_sp_sample_ids();
	$msg     = isset( $_GET['meeha_sp_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['meeha_sp_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	?>
	<div class="wrap">
		<h1>নমুনা পোস্ট</h1>
		<?php if ( $msg ) : ?>
			<?php list( $kind, $count ) = array_pad( explode( ':', $msg ), 2, '0' ); ?>
			<div class="notice notice-success is-dismissible"><p>
				<?php echo esc_html( meeha_sp_bn_digits( (int) $count ) . ( 'created' === $kind ? 'টি নমুনা পোস্ট তৈরি হয়েছে।' : 'টি নমুনা পোস্ট মুছে ফেলা হয়েছে।' ) ); ?>
			</p></div>
		<?php endif; ?>

		<p>যে ক্যাটেগরিতে এখনো কোনো প্রকাশিত পোস্ট নেই, সেগুলোতে নমুনা পোস্ট বসিয়ে ডিজাইন দেখে নিতে পারবেন। নমুনা পোস্টের শিরোনামে "নমুনা" লেখা থাকে এবং সার্চ ইঞ্জিনে এগুলো noindex থাকে।</p>

		<h2>খালি ক্যাটেগরি (<?php echo esc_html( meeha_sp_bn_digits( count( $empty ) ) ); ?>)</h2>
		<?php if ( $empty ) : ?>
			<p><?php echo esc_html( implode( ', ', wp_list_pluck( $empty, 'name' ) ) ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'meeha_sp' ); ?>
				<input type="hidden" name="action" value="meeha_sp">
				<input type="hidden" name="do" value="create">
				<p>
					<label>প্রতি ক্যাটেগরিতে পোস্ট:
						<select name="per">
							<?php for ( $i = 1; $i <= 6; $i++ ) : ?>
								<option value="<?php echo (int) $i; ?>" <?php selected( $i, 3 ); ?>><?php echo esc_html( meeha_sp_bn_digits( $i ) ); ?></option>
							<?php endfor; ?>
						</select>
					</label>
					&nbsp;
					<label><input type="checkbox" name="image" value="1" checked> নমুনা ফিচার ছবি যোগ করুন</label>
				</p>
				<?php submit_button( 'নমুনা পোস্ট তৈরি করুন', 'primary', 'submit', false ); ?>
			</form>
		<?php else : ?>
			<p>সব ক্যাটেগরিতেই পোস্ট আছে।</p>
		<?php endif; ?>

		<h2>এখন যত নমুনা পোস্ট আছে: <?php echo esc_html( meeha_sp_bn_digits( count( $samples ) ) ); ?></h2>
		<?php if ( $samples ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('সব নমুনা পোস্ট ও তাদের নমুনা ছবি স্থায়ীভাবে মুছে যাবে। নিশ্চিত?');">
				<?php wp_nonce_field( 'meeha_sp' ); ?>
				<input type="hidden" name="action" value="meeha_sp">
				<input type="hidden" name="do" value="delete">
				<?php submit_button( 'সব নমুনা পোস্ট মুছে ফেলুন', 'delete', 'submit', false ); ?>
			</form>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Keep sample posts out of search engines even without Rank Math.
 */
add_filter(
	'wp_robots',
	function ( $robots ) {
		if ( is_singular( 'post' ) && get_post_meta( get_queried_object_id(), MEEHA_SP_META, true ) ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
		}
		return $robots;
	}
);
