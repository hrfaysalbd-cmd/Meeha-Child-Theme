<?php
/**
 * Area-based news (এরিয়া ভিত্তিক খবর).
 *
 * Data functions meeha_get_bd_divisions() / meeha_get_default_upazilas() /
 * meeha_get_upazilas_for_district() (+ option meeha_upazila_overrides) are provided by the
 * foundation (inc/core/areas.php) because the newsroom-os plugin depends on them; everything
 * built on top of the data lives here:
 *  - meeha_render_area_fields() (legacy 3-select cascade) + meeha_area_cascade_script() which now
 *    prints the JSON data ONCE per page (<script type="application/json" id="meeha-area-data">)
 *  - post meta box (_meeha_division/_district/_upazila) and user profile fields
 *  - meeha_render_area_widget() — design-system `m-area` widget; results via public REST
 *    GET meeha/v1/area-news (cached 5 min) + legacy AJAX meeha_area_filter
 *  - meeha_comment_author_area( $comment ) — plain text for the comments UI (no HTML filter on
 *    get_comment_author any more)
 *  - admin page meeha-upazila-settings
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

/** Divisions map (empty array if the foundation is missing). */
function meeha_area_divisions() {
	return function_exists( 'meeha_get_bd_divisions' ) ? (array) meeha_get_bd_divisions() : array();
}

/** Upazilas for a district (empty array if the foundation is missing). */
function meeha_area_upazilas( $district ) {
	return ( $district && function_exists( 'meeha_get_upazilas_for_district' ) ) ? array_values( (array) meeha_get_upazilas_for_district( $district ) ) : array();
}

/**
 * Validate a division/district/upazila triplet against the data.
 *
 * @return array ['division' => …, 'district' => …, 'upazila' => …] ('' for invalid parts)
 */
function meeha_area_sanitize_triplet( $division, $district, $upazila ) {
	$divisions = meeha_area_divisions();
	$division  = sanitize_text_field( (string) $division );
	$district  = sanitize_text_field( (string) $district );
	$upazila   = sanitize_text_field( (string) $upazila );
	if ( ! isset( $divisions[ $division ] ) ) {
		return array( 'division' => '', 'district' => '', 'upazila' => '' );
	}
	if ( ! in_array( $district, (array) $divisions[ $division ], true ) ) {
		return array( 'division' => $division, 'district' => '', 'upazila' => '' );
	}
	if ( '' !== $upazila && ! in_array( $upazila, meeha_area_upazilas( $district ), true ) ) {
		$upazila = mb_substr( $upazila, 0, 60 ); // overrides may change later — keep a sane free value
	}
	return array( 'division' => $division, 'district' => $district, 'upazila' => $upazila );
}

/* =====================================================================
   Cascade data + script (printed once per page)
===================================================================== */

/** Request that the JSON data be printed (level 'div' = divisions only, 'full' = + upazilas). */
function meeha_area_need_data( $level = 'full' ) {
	$cur = isset( $GLOBALS['meeha_area_data_level'] ) ? $GLOBALS['meeha_area_data_level'] : '';
	if ( 'full' !== $cur ) {
		$GLOBALS['meeha_area_data_level'] = $level;
	}
	meeha_area_enqueue_script();
}

function meeha_area_enqueue_script() {
	if ( wp_script_is( 'meeha-area', 'enqueued' ) ) {
		return;
	}
	wp_enqueue_script( 'meeha-area', meeha_legacy_asset_url( 'area', 'js', 'area.js' ), array(), null, array( 'in_footer' => true, 'strategy' => 'defer' ) ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- version is in the URL
}

/** Print the data block (wp_footer / admin_footer, before footer scripts). */
function meeha_area_print_data() {
	if ( empty( $GLOBALS['meeha_area_data_level'] ) || ! empty( $GLOBALS['meeha_area_data_printed'] ) ) {
		return;
	}
	$GLOBALS['meeha_area_data_printed'] = true;
	$divisions                          = meeha_area_divisions();
	$data                               = array( 'd' => $divisions );
	if ( 'full' === $GLOBALS['meeha_area_data_level'] ) {
		$data['u'] = array();
		foreach ( $divisions as $districts ) {
			foreach ( (array) $districts as $dist ) {
				$data['u'][ $dist ] = meeha_area_upazilas( $dist );
			}
		}
	}
	meeha_legacy_json_script( 'meeha-area-data', $data );
}
add_action( 'wp_footer', 'meeha_area_print_data', 5 );
add_action( 'admin_footer', 'meeha_area_print_data', 5 );

/**
 * Legacy: the cascade handler. Used to print a full inline <script> on every call; now it only
 * makes sure the shared JSON block (once) and assets/js/area.js are on the page.
 */
function meeha_area_cascade_script() {
	meeha_area_need_data( 'full' );
}

/**
 * Legacy markup: three selects named {prefix}_division / _district / _upazila with the classes
 * meeha-area-division / -district / -upazila and data-target ids (kept for old callers & CSS).
 */
function meeha_render_area_fields( $division, $district, $upazila, $prefix = 'meeha' ) {
	$divisions = meeha_area_divisions();
	$upazilas  = $district ? meeha_area_upazilas( $district ) : array();
	if ( $upazila && ! in_array( $upazila, $upazilas, true ) ) {
		$upazilas[] = $upazila; // keep a stored value visible even if the list changed
	}
	$p = esc_attr( $prefix );
	?>
	<select name="<?php echo $p; // phpcs:ignore WordPress.Security.EscapeOutput ?>_division" id="<?php echo $p; // phpcs:ignore ?>_division" class="meeha-area-division m-input" data-target="<?php echo $p; // phpcs:ignore ?>_district" aria-label="বিভাগ">
		<option value="">বিভাগ বাছাই করুন</option>
		<?php foreach ( array_keys( $divisions ) as $div ) : ?>
			<option value="<?php echo esc_attr( $div ); ?>" <?php selected( $division, $div ); ?>><?php echo esc_html( $div ); ?></option>
		<?php endforeach; ?>
	</select>
	<select name="<?php echo $p; // phpcs:ignore ?>_district" id="<?php echo $p; // phpcs:ignore ?>_district" class="meeha-area-district m-input" data-selected="<?php echo esc_attr( $district ); ?>" data-target="<?php echo $p; // phpcs:ignore ?>_upazila" aria-label="জেলা">
		<option value="">জেলা বাছাই করুন</option>
		<?php if ( $division && isset( $divisions[ $division ] ) ) : ?>
			<?php foreach ( $divisions[ $division ] as $dist ) : ?>
				<option value="<?php echo esc_attr( $dist ); ?>" <?php selected( $district, $dist ); ?>><?php echo esc_html( $dist ); ?></option>
			<?php endforeach; ?>
		<?php endif; ?>
	</select>
	<select name="<?php echo $p; // phpcs:ignore ?>_upazila" id="<?php echo $p; // phpcs:ignore ?>_upazila" class="meeha-area-upazila m-input" data-selected="<?php echo esc_attr( $upazila ); ?>" aria-label="থানা/উপজেলা">
		<option value="">থানা/উপজেলা বাছাই করুন</option>
		<?php foreach ( $upazilas as $upz ) : ?>
			<option value="<?php echo esc_attr( $upz ); ?>" <?php selected( $upazila, $upz ); ?>><?php echo esc_html( $upz ); ?></option>
		<?php endforeach; ?>
	</select>
	<?php
}

/* =====================================================================
   Post meta box "এরিয়া (অঞ্চল)"
===================================================================== */
function meeha_add_area_meta_box() {
	add_meta_box( 'meeha_area_box', 'এরিয়া (অঞ্চল)', 'meeha_render_area_meta_box', 'post', 'side' );
}
add_action( 'add_meta_boxes', 'meeha_add_area_meta_box' );

function meeha_render_area_meta_box( $post ) {
	wp_nonce_field( 'meeha_save_area', 'meeha_area_nonce' );
	echo '<div class="meeha-area-box">';
	meeha_render_area_fields(
		get_post_meta( $post->ID, '_meeha_division', true ),
		get_post_meta( $post->ID, '_meeha_district', true ),
		get_post_meta( $post->ID, '_meeha_upazila', true ),
		'meeha_post'
	);
	echo '<p class="description">সংবাদটি কোন এলাকার — “আমার এলাকার খবর”-এ দেখাবে।</p></div>';
	meeha_area_cascade_script();
}

function meeha_save_area_meta( $post_id ) {
	if ( ! isset( $_POST['meeha_area_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['meeha_area_nonce'] ) ), 'meeha_save_area' ) ) {
		return;
	}
	if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	if ( ! isset( $_POST['meeha_post_division'] ) ) {
		return;
	}
	$area = meeha_area_sanitize_triplet(
		wp_unslash( $_POST['meeha_post_division'] ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized in helper
		isset( $_POST['meeha_post_district'] ) ? wp_unslash( $_POST['meeha_post_district'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		isset( $_POST['meeha_post_upazila'] ) ? wp_unslash( $_POST['meeha_post_upazila'] ) : '' // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	);
	foreach ( $area as $field => $value ) {
		if ( '' === $value ) {
			delete_post_meta( $post_id, '_meeha_' . $field );
		} else {
			update_post_meta( $post_id, '_meeha_' . $field, $value );
		}
	}
}
add_action( 'save_post_post', 'meeha_save_area_meta' );

/* =====================================================================
   User profile (wp-admin)
===================================================================== */
function meeha_add_user_area_field( $user ) {
	?>
	<h2>Meeha এরিয়া</h2>
	<table class="form-table">
		<tr>
			<th>এরিয়া</th>
			<td class="meeha-area-box">
				<?php
				meeha_render_area_fields(
					get_user_meta( $user->ID, 'meeha_division', true ),
					get_user_meta( $user->ID, 'meeha_district', true ),
					get_user_meta( $user->ID, 'meeha_upazila', true ),
					'meeha_user'
				);
				?>
			</td>
		</tr>
		<tr>
			<th>দেখানো</th>
			<td><label><input type="checkbox" name="meeha_show_area" value="1" <?php checked( get_user_meta( $user->ID, 'meeha_show_area', true ) ); ?> /> আমি আমার এরিয়া দেখাতে চাই (কমেন্টে নামের পাশে দেখাবে)</label></td>
		</tr>
	</table>
	<?php
	meeha_area_cascade_script();
}
add_action( 'show_user_profile', 'meeha_add_user_area_field' );
add_action( 'edit_user_profile', 'meeha_add_user_area_field' );

function meeha_save_user_area_field( $user_id ) {
	if ( ! current_user_can( 'edit_user', $user_id ) ) {
		return;
	}
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- core verified the profile nonce.
	if ( isset( $_POST['meeha_user_division'] ) ) {
		$area = meeha_area_sanitize_triplet(
			wp_unslash( $_POST['meeha_user_division'] ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			isset( $_POST['meeha_user_district'] ) ? wp_unslash( $_POST['meeha_user_district'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			isset( $_POST['meeha_user_upazila'] ) ? wp_unslash( $_POST['meeha_user_upazila'] ) : '' // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		);
		foreach ( $area as $field => $value ) {
			update_user_meta( $user_id, 'meeha_' . $field, $value );
		}
	}
	update_user_meta( $user_id, 'meeha_show_area', isset( $_POST['meeha_show_area'] ) ? 1 : 0 );
	// phpcs:enable
}
add_action( 'personal_options_update', 'meeha_save_user_area_field' );
add_action( 'edit_user_profile_update', 'meeha_save_user_area_field' );

function meeha_area_admin_assets( $hook ) {
	if ( in_array( $hook, array( 'post.php', 'post-new.php', 'profile.php', 'user-edit.php' ), true ) ) {
		meeha_area_enqueue_script();
		wp_add_inline_style( 'common', '.meeha-area-box select{display:block;width:100%;max-width:25rem;margin:0 0 6px}' );
	}
}
add_action( 'admin_enqueue_scripts', 'meeha_area_admin_assets' );

/* =====================================================================
   Area news query (cached 5 minutes)
===================================================================== */

/**
 * Post IDs for an area. Empty area → newest posts that have any district set.
 *
 * @return int[]
 */
function meeha_area_query_ids( $division, $district, $upazila, $count = 5 ) {
	$count = max( 1, min( 20, (int) $count ) );
	$key   = 'meeha_area_' . md5( wp_json_encode( array( $division, $district, $upazila, $count, (int) get_option( 'meeha_area_cache_gen', 0 ) ) ) );
	$ids   = get_transient( $key );
	if ( is_array( $ids ) ) {
		return array_map( 'intval', $ids );
	}
	$meta = array( 'relation' => 'AND' );
	if ( $division ) {
		$meta[] = array( 'key' => '_meeha_division', 'value' => $division );
	}
	if ( $district ) {
		$meta[] = array( 'key' => '_meeha_district', 'value' => $district );
	}
	if ( $upazila ) {
		$meta[] = array( 'key' => '_meeha_upazila', 'value' => $upazila, 'compare' => 'LIKE' );
	}
	if ( 1 === count( $meta ) ) {
		$meta[] = array( 'key' => '_meeha_district', 'value' => '', 'compare' => '!=' );
	}
	$q   = new WP_Query(
		array(
			'post_type'              => 'post',
			'post_status'            => 'publish',
			'posts_per_page'         => $count,
			'meta_query'             => $meta, // phpcs:ignore WordPress.DB.SlowDBQuery
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'fields'                 => 'ids',
			'update_post_term_cache' => false,
		)
	);
	$ids = array_map( 'intval', $q->posts );
	// Cache only known areas (free-text upazila values must not create wp_options rows).
	$divs = function_exists( 'meeha_get_bd_divisions' ) ? meeha_get_bd_divisions() : array();
	$all  = $divs ? call_user_func_array( 'array_merge', array_map( 'array_values', array_values( $divs ) ) ) : array();
	$ok   = ( ! $division || isset( $divs[ $division ] ) )
		&& ( ! $district || in_array( $district, $all, true ) )
		&& ( ! $upazila || ( $district && in_array( $upazila, (array) meeha_get_upazilas_for_district( $district ), true ) ) );
	if ( $ok ) {
		set_transient( $key, $ids, 5 * MINUTE_IN_SECONDS );
	}
	return $ids;
}

/** Bump the cache generation when an article's area or status changes. */
function meeha_area_cache_bump( $post_id = 0 ) {
	if ( $post_id && 'post' !== get_post_type( $post_id ) ) {
		return;
	}
	update_option( 'meeha_area_cache_gen', (int) get_option( 'meeha_area_cache_gen', 0 ) + 1, false );
}
add_action( 'save_post_post', 'meeha_area_cache_bump', 20 );

/** Result list markup for a set of post IDs. */
function meeha_area_results_html( $ids, $heading_tag = 'h4', $empty_text = 'এই এলাকার কোনো খবর এখনো নেই।' ) {
	if ( ! $ids ) {
		return '<p class="m-dek m-area__empty">' . esc_html( $empty_text ) . '</p>';
	}
	$tag = in_array( $heading_tag, array( 'h3', 'h4', 'h5', 'p' ), true ) ? $heading_tag : 'h4';
	$out = '<ul class="m-list m-area__list" role="list">';
	foreach ( $ids as $id ) {
		$out .= '<li><' . $tag . ' class="m-list__title"><a href="' . esc_url( get_permalink( $id ) ) . '">' . esc_html( get_the_title( $id ) ) . '</a></' . $tag . '>'
			. '<p class="m-meta"><span>' . esc_html( meeha_legacy_time_ago( get_post_time( 'U', true, $id ) ) ) . '</span></p></li>';
	}
	return $out . '</ul>';
}

/* =====================================================================
   Widget — design-system `m-area`
===================================================================== */

/**
 * Render the area news widget (division → district [→ upazila] + results).
 *
 * @param array $args title, count (1–10, def 5), division, district, upazila, show_upazila (false),
 *                    heading ('h3'), id (unique html id), class (extra classes), echo (true).
 * @return string HTML (also echoed unless echo=false).
 */
function meeha_render_area_widget( $args = array() ) {
	static $n = 0;
	$n++;
	$args = wp_parse_args(
		$args,
		array(
			'title'        => 'আমার এলাকার খবর',
			'count'        => 5,
			'division'     => '',
			'district'     => '',
			'upazila'      => '',
			'show_upazila' => false,
			'heading'      => 'h3',
			'id'           => 'm-area-' . $n,
			'class'        => '',
			'echo'         => true,
		)
	);
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- public GET filter (no-JS fallback)
	$from_get = isset( $_GET['m_div'] );
	$division = $from_get ? wp_unslash( $_GET['m_div'] ) : $args['division']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$district = $from_get && isset( $_GET['m_dis'] ) ? wp_unslash( $_GET['m_dis'] ) : $args['district']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$upazila  = $from_get && isset( $_GET['m_upz'] ) ? wp_unslash( $_GET['m_upz'] ) : $args['upazila']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	// phpcs:enable
	if ( ! $from_get && ! $division && is_user_logged_in() ) {
		$uid      = get_current_user_id();
		$division = get_user_meta( $uid, 'meeha_division', true );
		$district = get_user_meta( $uid, 'meeha_district', true );
	}
	$area      = meeha_area_sanitize_triplet( $division, $district, $upazila );
	$count     = max( 1, min( 10, (int) $args['count'] ) );
	$id        = sanitize_html_class( $args['id'] );
	$heading   = in_array( $args['heading'], array( 'h2', 'h3', 'h4' ), true ) ? $args['heading'] : 'h3';
	$item_tag  = 'h2' === $heading ? 'h3' : ( 'h3' === $heading ? 'h4' : 'p' );
	$divisions = meeha_area_divisions();
	$ids       = meeha_area_query_ids( $area['division'], $area['district'], $area['upazila'], $count );
	$empty     = ( $area['division'] || $area['district'] ) ? 'এই এলাকার কোনো খবর এখনো নেই।' : 'বিভাগ ও জেলা বেছে নিন।';

	meeha_area_need_data( $args['show_upazila'] ? 'full' : 'div' );

	ob_start();
	?>
	<form class="m-area<?php echo $args['class'] ? ' ' . esc_attr( $args['class'] ) : ''; ?>" id="<?php echo esc_attr( $id ); ?>" action="<?php echo esc_url( remove_query_arg( array( 'm_div', 'm_dis', 'm_upz' ) ) ); ?>#<?php echo esc_attr( $id ); ?>" method="get" aria-labelledby="<?php echo esc_attr( $id ); ?>-h" data-meeha-area data-count="<?php echo esc_attr( $count ); ?>" data-item-tag="<?php echo esc_attr( $item_tag ); ?>" data-endpoint="<?php echo esc_url( rest_url( 'meeha/v1/area-news' ) ); ?>">
		<<?php echo $heading; // phpcs:ignore WordPress.Security.EscapeOutput ?> class="m-area__title" id="<?php echo esc_attr( $id ); ?>-h"><?php echo meeha_legacy_icon( 'location' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php echo esc_html( $args['title'] ); ?></<?php echo $heading; // phpcs:ignore ?>>
		<div class="m-area__selects">
			<label class="m-vh" for="<?php echo esc_attr( $id ); ?>-div">বিভাগ</label>
			<select id="<?php echo esc_attr( $id ); ?>-div" name="m_div" class="meeha-area-division" data-target="<?php echo esc_attr( $id ); ?>-dis">
				<option value="">বিভাগ</option>
				<?php foreach ( array_keys( $divisions ) as $div ) : ?>
					<option value="<?php echo esc_attr( $div ); ?>" <?php selected( $area['division'], $div ); ?>><?php echo esc_html( $div ); ?></option>
				<?php endforeach; ?>
			</select>
			<label class="m-vh" for="<?php echo esc_attr( $id ); ?>-dis">জেলা</label>
			<select id="<?php echo esc_attr( $id ); ?>-dis" name="m_dis" class="meeha-area-district"<?php echo $args['show_upazila'] ? ' data-target="' . esc_attr( $id ) . '-upz"' : ''; ?>>
				<option value="">জেলা</option>
				<?php if ( $area['division'] ) : ?>
					<?php foreach ( $divisions[ $area['division'] ] as $dist ) : ?>
						<option value="<?php echo esc_attr( $dist ); ?>" <?php selected( $area['district'], $dist ); ?>><?php echo esc_html( $dist ); ?></option>
					<?php endforeach; ?>
				<?php endif; ?>
			</select>
			<?php if ( $args['show_upazila'] ) : ?>
				<label class="m-vh" for="<?php echo esc_attr( $id ); ?>-upz">থানা/উপজেলা</label>
				<select id="<?php echo esc_attr( $id ); ?>-upz" name="m_upz" class="meeha-area-upazila" style="grid-column:1/-1">
					<option value="">থানা/উপজেলা (সব)</option>
					<?php foreach ( meeha_area_upazilas( $area['district'] ) as $upz ) : ?>
						<option value="<?php echo esc_attr( $upz ); ?>" <?php selected( $area['upazila'], $upz ); ?>><?php echo esc_html( $upz ); ?></option>
					<?php endforeach; ?>
				</select>
			<?php endif; ?>
		</div>
		<div class="m-area__results" aria-live="polite" data-area-results>
			<?php echo meeha_area_results_html( $ids, $item_tag, $empty ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside ?>
		</div>
		<button class="m-btn m-btn--ghost m-btn--block" type="submit" data-js-hide>খুঁজুন</button>
	</form>
	<?php
	$html = ob_get_clean();
	if ( $args['echo'] ) {
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	return $html;
}

/** Legacy name: the old sidebar block (now the m-area widget with the upazila select). */
function meeha_render_area_sidebar() {
	meeha_render_area_widget(
		array(
			'title'        => 'এরিয়া ভিত্তিক খবর',
			'show_upazila' => true,
		)
	);
}

/* =====================================================================
   REST GET meeha/v1/area-news (public, cached) + legacy AJAX meeha_area_filter
===================================================================== */
function meeha_area_rest_payload( $division, $district, $upazila, $count, $item_tag = 'h4' ) {
	$area  = meeha_area_sanitize_triplet( $division, $district, $upazila );
	$ids   = meeha_area_query_ids( $area['division'], $area['district'], $area['upazila'], $count );
	$items = array();
	foreach ( $ids as $id ) {
		$items[] = array(
			'id'    => $id,
			'title' => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
			'url'   => get_permalink( $id ),
			'ago'   => meeha_legacy_time_ago( get_post_time( 'U', true, $id ) ),
		);
	}
	$empty = ( $area['division'] || $area['district'] ) ? 'এই এলাকার কোনো খবর এখনো নেই।' : 'বিভাগ ও জেলা বেছে নিন।';
	return array_merge(
		$area,
		array(
			'items' => $items,
			'html'  => meeha_area_results_html( $ids, $item_tag, $empty ),
		)
	);
}

function meeha_area_rest_routes() {
	register_rest_route(
		'meeha/v1',
		'/area-news',
		array(
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'args'                => array(
				'division' => array( 'type' => 'string', 'default' => '' ),
				'district' => array( 'type' => 'string', 'default' => '' ),
				'upazila'  => array( 'type' => 'string', 'default' => '' ),
				'count'    => array( 'type' => 'integer', 'default' => 5, 'minimum' => 1, 'maximum' => 20 ),
				'tag'      => array( 'type' => 'string', 'default' => 'h4', 'enum' => array( 'h3', 'h4', 'p' ) ),
			),
			'callback'            => function ( WP_REST_Request $r ) {
				return meeha_legacy_rest_cacheable(
					meeha_area_rest_payload( $r['division'], $r['district'], $r['upazila'], $r['count'], $r['tag'] ),
					300
				);
			},
		)
	);
}
add_action( 'rest_api_init', 'meeha_area_rest_routes' );

/** Legacy wrapper (old cached sidebars post here with a possibly stale nonce — not enforced). */
function meeha_handle_area_filter() {
	// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput
	$division = isset( $_POST['division'] ) ? wp_unslash( $_POST['division'] ) : '';
	$district = isset( $_POST['district'] ) ? wp_unslash( $_POST['district'] ) : '';
	$upazila  = isset( $_POST['upazila'] ) ? wp_unslash( $_POST['upazila'] ) : '';
	// phpcs:enable
	if ( '' === $division && '' === $district && '' === $upazila ) {
		wp_send_json_success( array( 'html' => '<p class="m-dek m-area__empty">অন্তত একটা এরিয়া বাছাই করুন।</p>' ) );
	}
	$payload = meeha_area_rest_payload( $division, $district, $upazila, 10 );
	wp_send_json_success( array( 'html' => $payload['html'] ) );
}
add_action( 'wp_ajax_meeha_area_filter', 'meeha_handle_area_filter' );
add_action( 'wp_ajax_nopriv_meeha_area_filter', 'meeha_handle_area_filter' );

/* =====================================================================
   Commenter area (plain text; D prints it in the comment template)
===================================================================== */

/**
 * "জেলা, বিভাগ" for a comment by a registered user who opted in (meeha_show_area), else ''.
 *
 * @param WP_Comment|int $comment Comment.
 * @return string Plain text — escape on output.
 */
function meeha_comment_author_area( $comment ) {
	$comment = get_comment( $comment );
	if ( ! $comment || empty( $comment->user_id ) ) {
		return '';
	}
	$uid = (int) $comment->user_id;
	if ( ! get_user_meta( $uid, 'meeha_show_area', true ) ) {
		return '';
	}
	$division = trim( (string) get_user_meta( $uid, 'meeha_division', true ) );
	$district = trim( (string) get_user_meta( $uid, 'meeha_district', true ) );
	return trim( $district . ( $district && $division ? ', ' : '' ) . $division );
}

/* =====================================================================
   Admin: মিহা থিম › উপজেলা (slug meeha-upazila-settings, option meeha_upazila_overrides)
===================================================================== */
function meeha_add_upazila_settings_page() {
	add_submenu_page( meeha_legacy_menu_parent(), 'Meeha উপজেলা সেটিংস', 'উপজেলা', 'manage_options', 'meeha-upazila-settings', 'meeha_render_upazila_settings_page' );
}
add_action( 'admin_menu', 'meeha_add_upazila_settings_page', 20 );

function meeha_handle_upazila_settings_save() {
	if ( ! isset( $_POST['meeha_upazila_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['meeha_upazila_nonce'] ) ), 'meeha_save_upazila' ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$district = isset( $_POST['upazila_district'] ) ? sanitize_text_field( wp_unslash( $_POST['upazila_district'] ) ) : '';
	$list     = isset( $_POST['upazila_list'] ) ? sanitize_textarea_field( wp_unslash( $_POST['upazila_list'] ) ) : '';
	$valid    = array();
	foreach ( meeha_area_divisions() as $districts ) {
		$valid = array_merge( $valid, (array) $districts );
	}
	if ( ! $district || ! in_array( $district, $valid, true ) ) {
		return;
	}
	// normalise: Bengali/ASCII commas, newlines → ", "
	$items = array_filter( array_map( 'trim', preg_split( '/[,،\n]+/u', $list ) ) );
	$overrides              = get_option( 'meeha_upazila_overrides', array() );
	$overrides              = is_array( $overrides ) ? $overrides : array();
	$overrides[ $district ] = implode( ', ', array_unique( $items ) );
	update_option( 'meeha_upazila_overrides', $overrides );

	add_action(
		'admin_notices',
		function () use ( $district ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $district ) . '-এর উপজেলা তালিকা সংরক্ষণ করা হয়েছে।</p></div>';
		}
	);
}
add_action( 'admin_init', 'meeha_handle_upazila_settings_save' );

function meeha_render_upazila_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$divisions         = meeha_area_divisions();
	$selected_district = isset( $_GET['district'] ) ? sanitize_text_field( wp_unslash( $_GET['district'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$current_list      = $selected_district ? implode( ', ', meeha_area_upazilas( $selected_district ) ) : '';
	$overrides         = get_option( 'meeha_upazila_overrides', array() );
	?>
	<div class="wrap">
		<h1>উপজেলা সেটিংস</h1>
		<p>প্রতিটা জেলার উপজেলা/থানার তালিকা এখান থেকে ঠিক করে দিতে পারবেন। ডিফল্ট তালিকা ভুল/অসম্পূর্ণ থাকলে কমা দিয়ে সঠিক তালিকা লিখে সংরক্ষণ করুন — এটাই তখন থেকে সব জায়গায় (পোস্ট, প্রোফাইল, এলাকার খবর, সংবাদদাতা অ্যাপ) ব্যবহৃত হবে।</p>
		<form method="get">
			<input type="hidden" name="page" value="meeha-upazila-settings" />
			<label class="screen-reader-text" for="meeha-upz-district">জেলা</label>
			<select name="district" id="meeha-upz-district" onchange="this.form.submit()">
				<option value="">জেলা বাছাই করুন</option>
				<?php foreach ( $divisions as $div => $districts ) : ?>
					<optgroup label="<?php echo esc_attr( $div ); ?>">
						<?php foreach ( $districts as $dist ) : ?>
							<option value="<?php echo esc_attr( $dist ); ?>" <?php selected( $selected_district, $dist ); ?>><?php echo esc_html( $dist ); ?><?php echo ! empty( $overrides[ $dist ] ) ? ' ✎' : ''; ?></option>
						<?php endforeach; ?>
					</optgroup>
				<?php endforeach; ?>
			</select>
			<noscript><?php submit_button( 'দেখুন', 'secondary', '', false ); ?></noscript>
		</form>
		<?php if ( $selected_district ) : ?>
			<form method="post" style="margin-top:16px;max-width:640px;">
				<?php wp_nonce_field( 'meeha_save_upazila', 'meeha_upazila_nonce' ); ?>
				<input type="hidden" name="upazila_district" value="<?php echo esc_attr( $selected_district ); ?>" />
				<p><label for="meeha-upz-list"><strong><?php echo esc_html( $selected_district ); ?></strong>-এর উপজেলা তালিকা (কমা দিয়ে আলাদা করুন):</label></p>
				<textarea name="upazila_list" id="meeha-upz-list" rows="6" class="large-text"><?php echo esc_textarea( $current_list ); ?></textarea>
				<p class="description">খালি রেখে সংরক্ষণ করলে ডিফল্ট তালিকায় ফিরে যাবে।</p>
				<?php submit_button( 'সংরক্ষণ করুন' ); ?>
			</form>
		<?php endif; ?>
	</div>
	<?php
}
