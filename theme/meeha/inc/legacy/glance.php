<?php
/**
 * [meeha_at_a_glance] — একনজরে archive page.
 *
 * Round 11: the first page of items is SERVER-RENDERED (crawlers and no-JS readers get the
 * list; filters are a plain GET form), then assets/js/glance.js enhances it: quick ranges,
 * date, category, search and "আরও দেখুন" via the public REST route GET meeha/v1/glance
 * (cached 2 min). The legacy AJAX action meeha_glance_fetch stays as a wrapper.
 *
 * Look: DESIGN-SPEC §8.2 একনজরে — time-stamped sentences grouped by day, using the editor's
 * `_meeha_glance_text` sentence (≤ 90 chars) and falling back to the title.
 *
 * GET params are prefixed (gr, gd, gc, gq, gp) so they never collide with WordPress query vars
 * such as `cat`, `s` or `paged` on a page URL.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

/** Allowed quick ranges → labels. */
function meeha_glance_ranges() {
	return array(
		'all'       => 'সব',
		'today'     => 'আজ',
		'yesterday' => 'গতকাল',
		'3days'     => 'গত ৩ দিন',
		'7days'     => 'গত ৭ দিন',
	);
}

/** Normalise filter input (from GET, REST or legacy POST). */
function meeha_glance_args( $raw ) {
	$ranges = meeha_glance_ranges();
	$range  = isset( $raw['range'] ) ? sanitize_key( $raw['range'] ) : 'all';
	$date   = isset( $raw['date'] ) ? sanitize_text_field( (string) $raw['date'] ) : '';
	$q      = isset( $raw['q'] ) ? sanitize_text_field( (string) $raw['q'] ) : '';
	return array(
		'range'    => isset( $ranges[ $range ] ) ? $range : 'all',
		'date'     => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : '',
		'cat'      => isset( $raw['cat'] ) ? absint( $raw['cat'] ) : 0,
		'q'        => mb_substr( $q, 0, 60 ),
		'page'     => isset( $raw['page'] ) ? max( 1, min( 50, absint( $raw['page'] ) ) ) : 1,
		'per_page' => isset( $raw['per_page'] ) ? max( 6, min( 24, absint( $raw['per_page'] ) ) ) : 12,
	);
}

/** Filter args from the current page's GET params. */
function meeha_glance_args_from_get() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput
	return meeha_glance_args(
		array(
			'range' => isset( $_GET['gr'] ) ? wp_unslash( $_GET['gr'] ) : 'all',
			'date'  => isset( $_GET['gd'] ) ? wp_unslash( $_GET['gd'] ) : '',
			'cat'   => isset( $_GET['gc'] ) ? wp_unslash( $_GET['gc'] ) : 0,
			'q'     => isset( $_GET['gq'] ) ? wp_unslash( $_GET['gq'] ) : '',
			'page'  => isset( $_GET['gp'] ) ? wp_unslash( $_GET['gp'] ) : 1,
		)
	);
	// phpcs:enable
}

/**
 * Run the glance query (cached 2 minutes per filter set).
 *
 * @return array ['ids' => int[], 'found' => int, 'pages' => int, 'page' => int]
 */
function meeha_glance_query( $a ) {
	$key    = 'meeha_gl_' . md5( wp_json_encode( array( $a, (int) get_option( 'meeha_glance_cache_gen', 0 ) ) ) );
	$cached = get_transient( $key );
	if ( is_array( $cached ) ) {
		return $cached;
	}
	$args = array(
		'post_type'              => 'post',
		'post_status'            => 'publish',
		'has_password'           => false,
		'posts_per_page'         => $a['per_page'],
		'paged'                  => $a['page'],
		'orderby'                => 'date',
		'order'                  => 'DESC',
		'ignore_sticky_posts'    => true,
		'fields'                 => 'ids',
		'update_post_term_cache' => false,
	);
	if ( $a['q'] ) {
		$args['s'] = $a['q'];
	}
	if ( $a['cat'] ) {
		$args['cat'] = $a['cat'];
	}
	$tz  = wp_timezone();
	$now = new DateTime( 'now', $tz );
	if ( $a['date'] ) {
		list( $y, $m, $d ) = array_map( 'intval', explode( '-', $a['date'] ) );
		$args['date_query'] = array( array( 'year' => $y, 'month' => $m, 'day' => $d ) );
	} elseif ( 'all' !== $a['range'] ) {
		$before = null;
		switch ( $a['range'] ) {
			case 'yesterday':
				$after  = ( clone $now )->modify( '-1 day' )->setTime( 0, 0, 0 );
				$before = ( clone $now )->setTime( 0, 0, 0 );
				break;
			case '3days':
				$after = ( clone $now )->modify( '-2 days' )->setTime( 0, 0, 0 );
				break;
			case '7days':
				$after = ( clone $now )->modify( '-6 days' )->setTime( 0, 0, 0 );
				break;
			default: // today
				$after = ( clone $now )->setTime( 0, 0, 0 );
		}
		$dq = array(
			'after'     => $after->format( 'Y-m-d H:i:s' ),
			'inclusive' => true,
		);
		if ( $before ) {
			$dq['before'] = $before->modify( '-1 second' )->format( 'Y-m-d H:i:s' );
		}
		$args['date_query'] = array( $dq );
	}
	$q   = new WP_Query( $args );
	$out = array(
		'ids'   => array_map( 'intval', $q->posts ),
		'found' => (int) $q->found_posts,
		'pages' => (int) $q->max_num_pages,
		'page'  => (int) $a['page'],
	);
	// Free text / unknown categories are not cached (each unique value would add wp_options rows).
	if ( '' === (string) $a['q'] && ( ! $a['cat'] || term_exists( (int) $a['cat'], 'category' ) ) ) {
		set_transient( $key, $out, 2 * MINUTE_IN_SECONDS );
	}
	return $out;
}

/** Bump the cache generation on publish/update so new posts appear quickly. */
function meeha_glance_cache_bump( $new_status, $old_status, $post ) {
	if ( 'post' === $post->post_type && ( 'publish' === $new_status || 'publish' === $old_status ) ) {
		update_option( 'meeha_glance_cache_gen', (int) get_option( 'meeha_glance_cache_gen', 0 ) + 1, false );
	}
}
add_action( 'transition_post_status', 'meeha_glance_cache_bump', 10, 3 );

/** "আজ · মঙ্গলবার, ৬ অক্টোবর ২০২৬" */
function meeha_glance_day_label( $ymd ) {
	$tz        = wp_timezone();
	$today     = ( new DateTime( 'now', $tz ) )->format( 'Y-m-d' );
	$yesterday = ( new DateTime( 'yesterday', $tz ) )->format( 'Y-m-d' );
	if ( function_exists( 'meeha_dates' ) ) {
		$d     = meeha_dates( $ymd );
		$label = $d['weekday'] . ', ' . $d['gregorian'];
	} else {
		$label = meeha_legacy_bn( wp_date( 'l, j F Y', strtotime( $ymd . ' 12:00:00' ) ) );
	}
	if ( $ymd === $today ) {
		return array( 'আজ', $label );
	}
	if ( $ymd === $yesterday ) {
		return array( 'গতকাল', $label );
	}
	return array( '', $label );
}

/** One item. */
function meeha_glance_item_html( $post_id ) {
	$post = get_post( $post_id );
	if ( ! $post ) {
		return '';
	}
	$ts      = (int) get_post_time( 'U', true, $post );
	$kicker  = meeha_legacy_kicker( $post );
	$excerpt = function_exists( 'meeha_post_dek' ) ? meeha_post_dek( $post, 40 ) : wp_trim_words( get_the_excerpt( $post ), 40 );
	$url     = get_permalink( $post );
	$title   = meeha_glance_text( $post );
	$out     = '<li class="m-gl__item">'
		. '<time class="m-gl__time" datetime="' . esc_attr( get_post_time( 'c', true, $post ) ) . '">' . esc_html( meeha_legacy_bn( wp_date( 'H:i', $ts ) ) ) . '</time>'
		. '<div class="m-gl__body">'
		. '<p class="m-gl__text"><a href="' . esc_url( $url ) . '">' . esc_html( $title ) . '</a></p>'
		. '<p class="m-gl__meta">' . ( $kicker ? '<span class="m-gl__cat">' . esc_html( $kicker ) . '</span>' : '' ) . '<span>' . esc_html( meeha_legacy_time_ago( $ts ) ) . '</span></p>';
	if ( $excerpt ) {
		$out .= '<details class="m-gl__more"><summary>সংক্ষেপে পড়ুন</summary><div class="m-gl__excerpt"><p>' . esc_html( $excerpt ) . '</p><a class="m-gl__read" href="' . esc_url( $url ) . '">সম্পূর্ণ পড়ুন<span class="m-vh">: ' . esc_html( get_the_title( $post ) ) . '</span></a></div></details>';
	}
	return $out . '</div></li>';
}

/** Items grouped by day (Asia/Dhaka). */
function meeha_glance_groups_html( $ids ) {
	if ( ! $ids ) {
		return '';
	}
	_prime_post_caches( $ids, true, true );
	$groups = array();
	foreach ( $ids as $id ) {
		$day              = wp_date( 'Y-m-d', (int) get_post_time( 'U', true, $id ) );
		$groups[ $day ][] = $id;
	}
	$out = '';
	foreach ( $groups as $day => $day_ids ) {
		list( $rel, $label ) = meeha_glance_day_label( $day );
		$out .= '<section class="m-gl__day" data-day="' . esc_attr( $day ) . '">'
			. '<h2 class="m-gl__dayhead">' . ( $rel ? '<span class="m-gl__rel">' . esc_html( $rel ) . '</span>' : '' ) . esc_html( $label ) . '</h2>'
			. '<ol class="m-gl__items" role="list">';
		foreach ( $day_ids as $id ) {
			$out .= meeha_glance_item_html( $id );
		}
		$out .= '</ol></section>';
	}
	return $out;
}

/** "মোট ৩২টি খবর" status line. */
function meeha_glance_status( $found, $a ) {
	if ( ! $found ) {
		return 'এই বাছাইয়ে কোনো খবর পাওয়া যায়নি।';
	}
	$parts = array();
	if ( $a['q'] ) {
		$parts[] = '‘' . $a['q'] . '’';
	}
	return ( $parts ? implode( ' ', $parts ) . ' — ' : '' ) . 'মোট ' . meeha_legacy_bn( $found ) . 'টি খবর';
}

/** URL of the shortcode page with filter params. */
function meeha_glance_url( $base, $a, $page = 1 ) {
	$args = array_filter(
		array(
			'gr' => 'all' !== $a['range'] ? $a['range'] : '',
			'gd' => $a['date'],
			'gc' => $a['cat'] ? $a['cat'] : '',
			'gq' => $a['q'],
			'gp' => $page > 1 ? $page : '',
		)
	);
	return add_query_arg( array_map( 'rawurlencode', $args ), $base );
}

/** REST/AJAX payload. */
function meeha_glance_payload( $a, $base = '' ) {
	$r = meeha_glance_query( $a );
	return array(
		'html'     => meeha_glance_groups_html( $r['ids'] ),
		'has_more' => $r['page'] < $r['pages'],
		'found'    => $r['found'],
		'page'     => $r['page'],
		'status'   => meeha_glance_status( $r['found'], $a ),
		'next_url' => ( $base && $r['page'] < $r['pages'] ) ? meeha_glance_url( $base, $a, $r['page'] + 1 ) : '',
	);
}

/* ---------- shortcode ---------- */
function meeha_at_a_glance_shortcode( $atts = array() ) {
	$atts = shortcode_atts(
		array(
			'per_page' => 12,
			'area'     => 'auto', // auto | yes | no — the area widget (aside on full-width pages, below on narrow ones)
		),
		$atts,
		'meeha_at_a_glance'
	);
	meeha_glance_enqueue();
	$a             = meeha_glance_args_from_get();
	$a['per_page'] = max( 6, min( 24, (int) $atts['per_page'] ) );
	$page_no       = $a['page'];
	// server-render page 1..N (a no-JS reader on ?gp=3 sees the first three pages' items)
	$a_render             = $a;
	$a_render['page']     = 1;
	$a_render['per_page'] = min( 72, $a['per_page'] * $page_no );
	$r                    = meeha_glance_query( $a_render );
	$has_more             = ( $a['per_page'] * $page_no ) < $r['found'];
	$base                 = get_permalink();
	$categories           = get_categories(
		array(
			'hide_empty' => true,
			'orderby'    => 'count',
			'order'      => 'DESC',
			'number'     => 60,
		)
	);
	$wide                 = 'page-full.php' === get_page_template_slug( get_queried_object_id() );
	$show_area            = 'no' !== $atts['area'] && function_exists( 'meeha_render_area_widget' );
	$uid                  = 'm-gl';

	ob_start();
	?>
	<div class="m-gl<?php echo $wide ? ' m-gl--wide' : ''; ?>" id="<?php echo esc_attr( $uid ); ?>" data-glance data-endpoint="<?php echo esc_url( rest_url( 'meeha/v1/glance' ) ); ?>" data-base="<?php echo esc_url( $base ); ?>" data-per-page="<?php echo esc_attr( $a['per_page'] ); ?>" data-page="<?php echo esc_attr( $page_no ); ?>">
		<div class="m-gl__main">
			<form class="m-gl__filters" method="get" action="<?php echo esc_url( $base ); ?>" role="search" aria-label="একনজরে ছাঁকুন" data-glance-form>
				<fieldset class="m-gl__ranges">
					<legend class="m-vh">সময়</legend>
					<?php foreach ( meeha_glance_ranges() as $key => $label ) : ?>
						<label class="m-gl__range"><input class="m-vh" type="radio" name="gr" value="<?php echo esc_attr( $key ); ?>" <?php checked( $a['range'], $key ); ?>><span class="m-chip"><?php echo esc_html( $label ); ?></span></label>
					<?php endforeach; ?>
				</fieldset>
				<div class="m-gl__adv">
					<label class="m-gl__field"><span class="m-gl__flabel">তারিখ</span><input class="m-input" type="date" name="gd" value="<?php echo esc_attr( $a['date'] ); ?>" max="<?php echo esc_attr( wp_date( 'Y-m-d' ) ); ?>"></label>
					<label class="m-gl__field"><span class="m-gl__flabel">বিভাগ</span>
						<select class="m-input" name="gc">
							<option value="">সব বিভাগ</option>
							<?php foreach ( $categories as $cat ) : ?>
								<option value="<?php echo esc_attr( $cat->term_id ); ?>" <?php selected( $a['cat'], $cat->term_id ); ?>><?php echo esc_html( $cat->name ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label class="m-gl__field m-gl__field--q"><span class="m-gl__flabel">খুঁজুন</span><input class="m-input" type="search" name="gq" value="<?php echo esc_attr( $a['q'] ); ?>" placeholder="শব্দ লিখুন…" maxlength="60" enterkeyhint="search"></label>
					<button class="m-btn m-gl__go" type="submit" data-js-hide>দেখুন</button>
				</div>
			</form>

			<div class="m-gl__panel">
				<p class="m-gl__status" role="status" aria-live="polite" data-glance-status><?php echo esc_html( meeha_glance_status( $r['found'], $a ) ); ?></p>
				<div class="m-gl__list" data-glance-list>
					<?php echo meeha_glance_groups_html( $r['ids'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside ?>
				</div>
				<?php if ( ! $r['found'] ) : ?>
					<p class="m-gl__empty" data-glance-empty><a href="<?php echo esc_url( $base ); ?>">সব ফিল্টার মুছে আবার দেখুন</a></p>
				<?php endif; ?>
				<div class="m-loadmore"<?php echo $has_more ? '' : ' hidden'; ?> data-glance-morewrap>
					<a class="m-btn m-btn--ghost" href="<?php echo esc_url( meeha_glance_url( $base, $a, $page_no + 1 ) ); ?>" rel="next" data-glance-more>আরও দেখুন</a>
				</div>
			</div>
		</div>
		<?php if ( $show_area ) : ?>
			<aside class="m-gl__aside" aria-label="এলাকার খবর">
				<?php
				meeha_render_area_widget(
					array(
						'title'        => 'আমার এলাকার খবর',
						'show_upazila' => true,
						'heading'      => 'h2',
						'id'           => 'm-gl-area',
					)
				);
				?>
			</aside>
		<?php endif; ?>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'meeha_at_a_glance', 'meeha_at_a_glance_shortcode' );

function meeha_glance_enqueue() {
	meeha_legacy_enqueue( 'glance', array( 'glance.css' ), array( 'glance.js' ) );
}

function meeha_glance_maybe_enqueue() {
	if ( meeha_legacy_current_has( array( 'meeha_at_a_glance' ) ) ) {
		meeha_glance_enqueue();
	}
}
add_action( 'wp_enqueue_scripts', 'meeha_glance_maybe_enqueue' );

/* ---------- REST ---------- */
function meeha_glance_rest_routes() {
	register_rest_route(
		'meeha/v1',
		'/glance',
		array(
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => function ( WP_REST_Request $r ) {
				$a    = meeha_glance_args( $r->get_params() );
				$base = '';
				$b    = (string) $r->get_param( 'base' );
				if ( $b && wp_validate_redirect( $b, '' ) ) {
					$base = esc_url_raw( $b );
				}
				return meeha_legacy_rest_cacheable( meeha_glance_payload( $a, $base ), 120 );
			},
		)
	);
}
add_action( 'rest_api_init', 'meeha_glance_rest_routes' );

/* ---------- legacy AJAX wrapper (nonce not enforced; read-only) ---------- */
function meeha_handle_glance_fetch() {
	// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput
	$a = meeha_glance_args(
		array(
			'page'  => isset( $_POST['page'] ) ? wp_unslash( $_POST['page'] ) : 1,
			'q'     => isset( $_POST['search'] ) ? wp_unslash( $_POST['search'] ) : '',
			'cat'   => isset( $_POST['category'] ) ? wp_unslash( $_POST['category'] ) : 0,
			'range' => isset( $_POST['range'] ) ? wp_unslash( $_POST['range'] ) : 'all',
			'date'  => isset( $_POST['date'] ) ? wp_unslash( $_POST['date'] ) : '',
		)
	);
	// phpcs:enable
	$p = meeha_glance_payload( $a );
	wp_send_json_success(
		array(
			'html'     => $p['html'],
			'has_more' => $p['has_more'],
			'found'    => $p['found'],
		)
	);
}
add_action( 'wp_ajax_meeha_glance_fetch', 'meeha_handle_glance_fetch' );
add_action( 'wp_ajax_nopriv_meeha_glance_fetch', 'meeha_handle_glance_fetch' );
