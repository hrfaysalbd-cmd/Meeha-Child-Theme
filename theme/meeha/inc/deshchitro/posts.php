<?php
/**
 * দেশচিত্র ← Meeha News posts (2.4.9).
 *
 * Every news post can carry its own দেশচিত্র counts (post meta `_meeha_dc` = array( indicator key => count ),
 * e.g. খুন 2, গ্রেপ্তার 3). The desk types them right under the editor — in wp-admin (meta box below) or in the
 * publisher app (newsroom-os `dc` field). The district comes from the post's existing area (`_meeha_district`),
 * so nothing is entered twice.
 *
 * Uses:
 *  - LIVE: today's published posts are summed in real time for the live section on top of the page
 *    (GET meeha/v1/deshchitro/live). The desk's own sheet for today stays private until midnight, as before.
 *  - Midnight: the input sheet carries the day's post aggregate (`meeha`) so the desk / AI prompt starts from
 *    Meeha's own figures and only looks elsewhere for what is missing.
 *  - Notable events: the public page lists the period's Meeha posts that carry counts.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

/** Meta key. */
const MEEHA_DC_POST_META = '_meeha_dc';

/**
 * Clean a raw counts value (array or JSON string) against the configured indicators.
 *
 * @param mixed $raw Raw.
 * @return array indicator key => int (>0 only)
 */
function meeha_dc_sanitize_post_counts( $raw ) {
	if ( is_string( $raw ) && '' !== $raw ) {
		$dec = json_decode( $raw, true );
		$raw = is_array( $dec ) ? $dec : array();
	}
	$raw  = is_array( $raw ) ? $raw : array();
	$keys = wp_list_pluck( meeha_dc_config()['indicators'], 'key' );
	$out  = array();
	foreach ( $keys as $k ) {
		if ( ! isset( $raw[ $k ] ) ) {
			continue;
		}
		$v = $raw[ $k ];
		$v = is_string( $v ) ? meeha_en_digits( trim( $v ) ) : $v;
		$n = max( 0, min( 9999, (int) $v ) );
		if ( $n > 0 ) {
			$out[ $k ] = $n;
		}
	}
	return $out;
}

/**
 * Counts stored on a post.
 *
 * @param int $post_id Post.
 * @return array
 */
function meeha_dc_post_counts( $post_id ) {
	$v = get_post_meta( (int) $post_id, MEEHA_DC_POST_META, true );
	return is_array( $v ) ? meeha_dc_sanitize_post_counts( $v ) : array();
}

/**
 * Save counts on a post (empty → meta removed).
 *
 * @param int   $post_id Post.
 * @param mixed $raw     Raw counts.
 * @return array Saved counts.
 */
function meeha_dc_set_post_counts( $post_id, $raw ) {
	$c = meeha_dc_sanitize_post_counts( $raw );
	if ( $c ) {
		update_post_meta( (int) $post_id, MEEHA_DC_POST_META, $c );
	} else {
		delete_post_meta( (int) $post_id, MEEHA_DC_POST_META );
	}
	return $c;
}

/**
 * District key of a post from its area meta ('' when unknown).
 *
 * @param int $post_id Post.
 * @return string
 */
function meeha_dc_post_district( $post_id ) {
	static $by_name = null;
	if ( null === $by_name ) {
		$by_name = array();
		foreach ( meeha_dc_districts() as $k => $v ) {
			$by_name[ meeha_dc_name_key( $v[0] ) ] = $k;
		}
		// Spellings used by older posts / other lists.
		foreach ( array( 'চট্টগ্রাম' => 'chattogram', 'কুমিল্লা' => 'cumilla', 'বগুড়া' => 'bogura', 'যশোহর' => 'jashore', 'বরিশাল' => 'barishal', 'ঝালকাঠী' => 'jhalakathi', 'নেত্রকোনা' => 'netrokona', 'নেত্রকোণা' => 'netrokona', 'মৌলভী বাজার' => 'moulvibazar', 'কক্স বাজার' => 'coxsbazar', 'চাঁপাই নবাবগঞ্জ' => 'chapainawabganj', 'ব্রাক্ষণবাড়িয়া' => 'brahmanbaria', 'রাঙামাটি' => 'rangamati' ) as $n => $k ) {
			$by_name[ meeha_dc_name_key( $n ) ] = $k;
		}
	}
	$n = meeha_dc_name_key( (string) get_post_meta( (int) $post_id, '_meeha_district', true ) );
	return isset( $by_name[ $n ] ) ? $by_name[ $n ] : '';
}

/**
 * Comparable form of a Bangla district name (spaces, hyphens and nukta variants removed).
 *
 * @param string $s Name.
 * @return string
 */
function meeha_dc_name_key( $s ) {
	$s = str_replace( array( ' ', '-', "\u{200C}", "\u{200D}" ), '', trim( (string) $s ) );
	// Precomposed ড় ঢ় য় vs base + nukta.
	return str_replace( array( "ড\u{09BC}", "ঢ\u{09BC}", "য\u{09BC}" ), array( 'ড়', 'ঢ়', 'য়' ), $s );
}

/** Cache generation for post aggregates (bumped whenever a post or its counts change). */
function meeha_dc_posts_gen() {
	return (int) get_option( 'meeha_dc_pgen', 0 );
}

/** Bump the post-aggregate generation. */
function meeha_dc_posts_bump() {
	static $done = false;
	if ( $done ) {
		return; // once per request is enough.
	}
	$done = true;
	update_option( 'meeha_dc_pgen', meeha_dc_posts_gen() + 1, false );
}

/**
 * Meta writes that change the aggregate.
 *
 * @param mixed  $meta_id  Unused.
 * @param int    $post_id  Post.
 * @param string $meta_key Key.
 */
function meeha_dc_posts_meta_changed( $meta_id, $post_id, $meta_key ) {
	if ( MEEHA_DC_POST_META === $meta_key || '_meeha_district' === $meta_key ) {
		meeha_dc_posts_bump();
	}
}
add_action( 'added_post_meta', 'meeha_dc_posts_meta_changed', 10, 3 );
add_action( 'updated_post_meta', 'meeha_dc_posts_meta_changed', 10, 3 );
add_action( 'deleted_post_meta', 'meeha_dc_posts_meta_changed', 10, 3 );

/**
 * Status / date / title changes of a post that has counts.
 *
 * @param string  $new  New status.
 * @param string  $old  Old status.
 * @param WP_Post $post Post.
 */
function meeha_dc_posts_status_changed( $new, $old, $post ) {
	if ( 'post' === $post->post_type && ( 'publish' === $new || 'publish' === $old ) && get_post_meta( $post->ID, MEEHA_DC_POST_META, true ) ) {
		meeha_dc_posts_bump();
	}
}
add_action( 'transition_post_status', 'meeha_dc_posts_status_changed', 10, 3 );
add_action(
	'post_updated',
	function ( $post_id ) {
		if ( 'post' === get_post_type( $post_id ) && get_post_meta( $post_id, MEEHA_DC_POST_META, true ) ) {
			meeha_dc_posts_bump(); // title / date edits.
		}
	}
);

/**
 * Published posts with counts in a date range (site dates, inclusive), newest first.
 *
 * @param string $from  Y-m-d.
 * @param string $to    Y-m-d.
 * @param int    $limit Max posts.
 * @return array[]
 */
function meeha_dc_posts_in( $from, $to, $limit = 300 ) {
	$q   = new WP_Query(
		array(
			'post_type'              => 'post',
			'post_status'            => 'publish',
			'posts_per_page'         => max( 1, min( 500, (int) $limit ) ),
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_term_cache' => false,
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'key'     => MEEHA_DC_POST_META,
					'compare' => 'EXISTS',
				),
			),
			'date_query'             => array(
				array(
					'after'     => $from . ' 00:00:00',
					'before'    => $to . ' 23:59:59',
					'inclusive' => true,
				),
			),
		)
	);
	$names = meeha_dc_districts();
	$out   = array();
	foreach ( $q->posts as $post ) {
		$c = meeha_dc_post_counts( $post->ID );
		if ( ! $c ) {
			continue;
		}
		$d     = meeha_dc_post_district( $post->ID );
		$thumb = get_the_post_thumbnail_url( $post, 'thumbnail' );
		$out[] = array(
			'id'    => (int) $post->ID,
			'title' => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
			'url'   => get_permalink( $post ),
			'day'   => get_the_date( 'Y-m-d', $post ),
			'time'  => get_the_date( 'H:i', $post ),
			'd'     => $d,
			'dn'    => $d ? $names[ $d ][0] : (string) get_post_meta( $post->ID, '_meeha_district', true ),
			'up'    => (string) get_post_meta( $post->ID, '_meeha_upazila', true ),
			'c'     => $c,
			'img'   => $thumb ? $thumb : '',
		);
	}
	return $out;
}

/**
 * Sum post counts into the sheet's shape.
 *
 * @param array[] $posts From meeha_dc_posts_in().
 * @return array { inc, unk, tot, n }
 */
function meeha_dc_posts_sum( $posts ) {
	$inc = array();
	$unk = array();
	$tot = array();
	foreach ( $posts as $p ) {
		foreach ( $p['c'] as $k => $n ) {
			if ( $p['d'] ) {
				$inc[ $p['d'] ][ $k ] = ( isset( $inc[ $p['d'] ][ $k ] ) ? $inc[ $p['d'] ][ $k ] : 0 ) + $n;
			} else {
				$unk[ $k ] = ( isset( $unk[ $k ] ) ? $unk[ $k ] : 0 ) + $n;
			}
			$tot[ $k ] = ( isset( $tot[ $k ] ) ? $tot[ $k ] : 0 ) + $n;
		}
	}
	return array(
		'inc' => $inc,
		'unk' => $unk,
		'tot' => $tot,
		'n'   => count( $posts ),
	);
}

/**
 * One day's Meeha aggregate (cached until any counted post changes).
 *
 * @param string $day Y-m-d.
 * @return array { inc, unk, tot, n, posts }
 */
function meeha_dc_posts_day( $day ) {
	$key = 'meeha_dc_pd_' . md5( $day . '|' . meeha_dc_posts_gen() . '|' . (int) get_option( 'meeha_dc_gen', 0 ) );
	$hit = get_transient( $key );
	if ( is_array( $hit ) ) {
		return $hit;
	}
	$posts = meeha_dc_posts_in( $day, $day, 400 );
	$agg   = meeha_dc_posts_sum( $posts ) + array( 'posts' => $posts );
	set_transient( $key, $agg, $day >= meeha_dc_today() ? 5 * MINUTE_IN_SECONDS : DAY_IN_SECONDS );
	return $agg;
}

/**
 * Is the live section switched on?
 *
 * @return bool
 */
function meeha_dc_live_on() {
	return ! empty( meeha_dc_config()['live'] );
}

/**
 * Public live payload: today's Meeha posts, summed.
 *
 * @return array
 */
function meeha_dc_live_payload() {
	$day = meeha_dc_today();
	$agg = meeha_dc_posts_day( $day );
	$ik  = wp_list_pluck( meeha_dc_active( 'indicators' ), 'key' );
	$tot = array();
	foreach ( $ik as $k ) {
		$tot[ $k ] = isset( $agg['tot'][ $k ] ) ? $agg['tot'][ $k ] : 0;
	}
	$posts = array_slice( $agg['posts'], 0, 20 );
	return array(
		'on'        => meeha_dc_live_on(),
		'day'       => $day,
		'label'     => meeha_dc_bn_date( $day ),
		'now'       => current_time( 'H:i:s' ),
		'ts'        => time(),
		'tot'       => $tot,
		'districts' => $agg['inc'],
		'unknown'   => $agg['unk'],
		'n'         => $agg['n'],
		'posts'     => $posts,
		'last'      => $posts ? $posts[0]['time'] : '',
	);
}

/**
 * Notable Meeha posts of a (public) period for the page: most counts first, then newest.
 *
 * @param string $from  Y-m-d.
 * @param string $to    Y-m-d.
 * @param int    $limit Max.
 * @return array[]
 */
function meeha_dc_posts_notable( $from, $to, $limit = 12 ) {
	if ( ! $from || ! $to || $to >= meeha_dc_today() ) {
		$to = meeha_dc_shift( meeha_dc_today(), -1 );
	}
	if ( $from > $to ) {
		return array();
	}
	$posts = meeha_dc_posts_in( $from, $to, 200 );
	$inds  = array();
	foreach ( meeha_dc_active( 'indicators' ) as $r ) {
		$inds[ $r['key'] ] = $r['tone'];
	}
	foreach ( $posts as $i => $p ) {
		$w = 0;
		foreach ( $p['c'] as $k => $n ) {
			if ( isset( $inds[ $k ] ) ) {
				$w += $n * ( 'bad' === $inds[ $k ] ? 3 : 1 );
			}
		}
		$posts[ $i ]['w'] = $w;
		$posts[ $i ]['o'] = $i;
	}
	usort(
		$posts,
		function ( $a, $b ) {
			return $b['w'] - $a['w'] ?: $a['o'] - $b['o'];
		}
	);
	$out = array();
	foreach ( array_slice( $posts, 0, $limit ) as $p ) {
		unset( $p['w'], $p['o'] );
		$out[] = $p;
	}
	return $out;
}

/** Live route. */
function meeha_dc_live_route() {
	register_rest_route(
		'meeha/v1',
		'/deshchitro/live',
		array(
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => function () {
				$res = rest_ensure_response( meeha_dc_live_payload() );
				$res->header( 'Cache-Control', 'public, max-age=30' );
				return $res;
			},
		)
	);
}
add_action( 'rest_api_init', 'meeha_dc_live_route' );

/* =====================================================================
   wp-admin meta box (right under the editor)
===================================================================== */

/** Register the box for desk users. */
function meeha_dc_add_post_box() {
	if ( meeha_dc_can_edit() ) {
		add_meta_box( 'meeha_dc_box', 'দেশচিত্র — এই খবরের হিসাব', 'meeha_dc_render_post_box', 'post', 'normal', 'high' );
	}
}
add_action( 'add_meta_boxes', 'meeha_dc_add_post_box' );

/**
 * Box markup.
 *
 * @param WP_Post $post Post.
 */
function meeha_dc_render_post_box( $post ) {
	wp_nonce_field( 'meeha_dc_post', 'meeha_dc_post_nonce' );
	$c    = meeha_dc_post_counts( $post->ID );
	$dn   = (string) get_post_meta( $post->ID, '_meeha_district', true );
	$dk   = meeha_dc_post_district( $post->ID );
	$kw   = meeha_dc_keywords();
	?>
	<div class="meeha-dcbox">
		<p class="meeha-dcbox__area">
			<?php if ( $dk ) : ?>
				জেলা: <strong><?php echo esc_html( $dn ); ?></strong> <span>(পাশের "এরিয়া" বক্স থেকে)</span>
			<?php else : ?>
				<strong class="meeha-dcbox__warn">জেলা বাছাই করা নেই।</strong> <span>পাশের "এরিয়া" বক্সে জেলা দিন, নইলে সংখ্যাগুলো "জেলা অজানা" হিসেবে গোনা হবে।</span>
			<?php endif; ?>
		</p>
		<div class="meeha-dcbox__grid">
			<?php foreach ( meeha_dc_active( 'indicators' ) as $r ) : ?>
				<label class="meeha-dcbox__f meeha-dcbox__f--<?php echo esc_attr( $r['tone'] ); ?>" data-kw="<?php echo esc_attr( isset( $kw[ $r['key'] ] ) ? $kw[ $r['key'] ] : '' ); ?>">
					<span><?php echo esc_html( $r['short'] ); ?></span>
					<input type="number" min="0" max="9999" inputmode="numeric" name="meeha_dc[<?php echo esc_attr( $r['key'] ); ?>]" value="<?php echo isset( $c[ $r['key'] ] ) ? esc_attr( $c[ $r['key'] ] ) : ''; ?>" placeholder="০">
				</label>
			<?php endforeach; ?>
		</div>
		<p class="description">খবরে যে ঘটনাগুলো আছে, তার সংখ্যা দিন (যেমন খুন ২, গ্রেপ্তার ৩)। প্রকাশের সাথে সাথে "দৈনিক দেশচিত্র" পাতার লাইভ অংশে যোগ হবে, আর রাত ১২টার হিসাবে আগে থেকে বসে থাকবে। একই ঘটনার ফলোআপ খবরে আবার সংখ্যা দেবেন না।</p>
	</div>
	<style>
		.meeha-dcbox__area{margin:0 0 10px}.meeha-dcbox__area span{color:#646970}.meeha-dcbox__warn{color:#b32d2e}
		.meeha-dcbox__grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:8px}
		.meeha-dcbox__f{display:flex;align-items:center;justify-content:space-between;gap:6px;border:1px solid #dcdcde;border-left:4px solid #c3c4c7;border-radius:6px;padding:4px 6px 4px 8px;background:#fff;font-size:12.5px}
		.meeha-dcbox__f input{width:62px;text-align:center}
		.meeha-dcbox__f--bad{border-left-color:#d63638}.meeha-dcbox__f--warn{border-left-color:#dba617}.meeha-dcbox__f--good{border-left-color:#00a32a}.meeha-dcbox__f--info{border-left-color:#2271b1}
		.meeha-dcbox__f.is-hint{box-shadow:0 0 0 2px #f0c33c inset}
	</style>
	<script>
	( function () {
		var t = document.getElementById( 'title' );
		if ( ! t ) { return; }
		function hint() {
			document.querySelectorAll( '.meeha-dcbox__f' ).forEach( function ( el ) {
				var kw = el.getAttribute( 'data-kw' );
				var on = false;
				try { on = !! kw && new RegExp( kw ).test( t.value ); } catch ( e ) {}
				el.classList.toggle( 'is-hint', on );
			} );
		}
		t.addEventListener( 'input', hint );
		hint();
	} )();
	</script>
	<?php
}

/**
 * Save from the box.
 *
 * @param int $post_id Post.
 */
function meeha_dc_save_post_box( $post_id ) {
	if ( ! isset( $_POST['meeha_dc_post_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['meeha_dc_post_nonce'] ) ), 'meeha_dc_post' ) ) {
		return;
	}
	if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) || ! meeha_dc_can_edit() ) {
		return;
	}
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised in meeha_dc_sanitize_post_counts().
	$raw = isset( $_POST['meeha_dc'] ) && is_array( $_POST['meeha_dc'] ) ? wp_unslash( $_POST['meeha_dc'] ) : array();
	meeha_dc_set_post_counts( $post_id, $raw );
}
add_action( 'save_post_post', 'meeha_dc_save_post_box' );
