<?php
/**
 * Studio: renderer script handle for the front end (D lazy-loads it), deep links, admin-bar shortcut.
 *
 * Front end usage (owner D):
 *   - enqueue:   wp_enqueue_script( 'meeha-cards' )  (registered here, footer + defer, config printed before it), or
 *   - lazy-load: <button data-cards-src="<?php echo esc_url( meeha_cards_script_src() ); ?>"> then inject a <script>.
 *     The payloads carry `config` (= meeha_cards_config(): base, fonts, logo, watermark, cta); callers pass it to
 *     MeehaCards.configure() — the theme root is never derived from the script URL (LiteSpeed may combine/move JS).
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

/**
 * URL of the renderer (minified bundle when built, else source).
 *
 * @return string
 */
function meeha_cards_script_src() {
	$dist = '/assets/dist/cards.min.js';
	$src  = MEEHA_DIR . '/assets/js/cards.js';
	// Use the built bundle only when it is not older than the source (a stale build must never win).
	if ( ! ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) && file_exists( MEEHA_DIR . $dist ) && ( ! file_exists( $src ) || filemtime( MEEHA_DIR . $dist ) >= filemtime( $src ) ) ) {
		return MEEHA_URI . $dist . '?ver=' . rawurlencode( (string) filemtime( MEEHA_DIR . $dist ) );
	}
	return MEEHA_URI . '/assets/js/cards.js?ver=' . rawurlencode( (string) @filemtime( MEEHA_DIR . '/assets/js/cards.js' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
}

/**
 * JSON for window.MeehaCardsConfig (safe for inline <script>).
 *
 * @return string
 */
function meeha_cards_config_json() {
	return wp_json_encode( meeha_cards_config(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP );
}

/**
 * Register the `meeha-cards` handle (front end + admin).
 */
function meeha_cards_register_script() {
	if ( wp_script_is( 'meeha-cards', 'registered' ) ) {
		return;
	}
	wp_register_script( 'meeha-cards', meeha_cards_script_src(), array(), null, array( 'in_footer' => true, 'strategy' => 'defer' ) ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- version is in the src.
	wp_add_inline_script( 'meeha-cards', 'window.MeehaCardsConfig=' . meeha_cards_config_json() . ';', 'before' );
}
add_action( 'wp_enqueue_scripts', 'meeha_cards_register_script', 5 );
add_action( 'admin_enqueue_scripts', 'meeha_cards_register_script', 5 );

/**
 * Studio deep link ("কার্ড হিসেবে শেয়ার"): admin.php?page=meeha-photocard-studio&type=opinion&post=ID.
 *
 * @param string $type    Card type.
 * @param int    $post_id Post id (0 = none).
 * @return string
 */
function meeha_studio_url( $type = '', $post_id = 0 ) {
	$args = array( 'page' => 'meeha-photocard-studio' );
	if ( $type ) {
		$args['type'] = sanitize_key( $type );
	}
	if ( $post_id ) {
		$args['post'] = (int) $post_id;
	}
	return add_query_arg( $args, admin_url( 'admin.php' ) );
}

/**
 * Best studio type for a post: quote CPT → quote; literature → literature/poem; opinion/editorial → opinion; else news.
 *
 * @param WP_Post|int $post Post.
 * @return string
 */
function meeha_studio_type_for_post( $post ) {
	$post = get_post( $post );
	if ( ! $post ) {
		return 'feature';
	}
	if ( 'meeha_photocard' === $post->post_type ) {
		return 'quote';
	}
	if ( 'post' !== $post->post_type ) {
		return 'news';
	}
	if ( function_exists( 'meeha_lit_genre_of' ) ) {
		$gid = meeha_lit_genre_of( $post ); // 2.0.4: সাহিত্য / কবিতা.
		if ( '' !== $gid ) {
			return 'poem' === $gid ? 'poem' : 'literature';
		}
	}
	if ( function_exists( 'meeha_post_type_label' ) && 'মতামত' === meeha_post_type_label( $post ) ) {
		return 'opinion';
	}
	foreach ( (array) get_the_category( $post->ID ) as $c ) {
		if ( in_array( $c->slug, array( 'opinion', 'editorial', 'sub-editorial', 'motamot' ), true ) ) {
			return 'opinion';
		}
	}
	return 'news';
}

/**
 * "৬ অক্টোবর ২০২৬" for a post (no time).
 *
 * @param WP_Post $post Post.
 * @return string
 */
function meeha_cards_bn_date( $post ) {
	$months = array( 1 => 'জানুয়ারি', 'ফেব্রুয়ারি', 'মার্চ', 'এপ্রিল', 'মে', 'জুন', 'জুলাই', 'আগস্ট', 'সেপ্টেম্বর', 'অক্টোবর', 'নভেম্বর', 'ডিসেম্বর' );
	return meeha_studio_bn( get_the_date( 'j', $post ) ) . ' ' . $months[ (int) get_the_date( 'n', $post ) ] . ' ' . meeha_studio_bn( get_the_date( 'Y', $post ) );
}

/**
 * Everything D needs for the single-post "ফটোকার্ড ডাউনলোড" button: MeehaCards.render( p.type, p.data, p.opts ).
 *
 * @param WP_Post|int $post Post.
 * @return array type, data{title,category,date,image}, opts{size,preset,ad}, filename, src
 */
function meeha_cards_news_payload( $post ) {
	$post = get_post( $post );
	if ( ! $post ) {
		return array();
	}
	$cat = function_exists( 'meeha_primary_category' ) ? meeha_primary_category( $post ) : null;
	if ( ! $cat ) {
		foreach ( (array) get_the_category( $post->ID ) as $c ) {
			if ( 'uncategorized' !== $c->slug ) {
				$cat = $c;
				break;
			}
		}
	}
	$img_id = (int) get_post_thumbnail_id( $post );
	$img    = $img_id ? wp_get_attachment_image_url( $img_id, 'full' ) : '';
	$orig   = $img_id ? meeha_studio_original_url( $img_id ) : '';
	if ( $img_id ) {
		$meta = wp_get_attachment_metadata( $img_id );
		if ( is_array( $meta ) && ! empty( $meta['width'] ) && $meta['width'] > 1600 ) {
			$large = wp_get_attachment_image_src( $img_id, 'large' );
			$img   = ( $large && $large[1] >= 1080 ) ? $large[0] : $img; // big enough for 1080 without a 5 MB download.
		}
	}
	$date = meeha_cards_bn_date( $post );
	$title = html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES, 'UTF-8' );
	$opts  = array(
		'size'   => (string) meeha_studio_opt( 'news_default_size', '1080x1080' ),
		'preset' => (string) meeha_studio_opt( 'news_default_frame', 'classic' ),
		'ad'     => false,
		'cta'    => (string) meeha_studio_opt( 'news_cta', 'বিস্তারিত কমেন্টে' ),
	);
	if ( meeha_studio_opt( 'news_reader_ad', 0 ) ) {
		$ad = meeha_studio_reader_ad();
		if ( $ad ) {
			$opts['ad'] = $ad;
		}
	}
	// 2.4.3: the photo position the publisher studio saved for this image (attachment meta `_meeha_card_focus`).
	$focus = $img_id ? meeha_cards_image_focus( $img_id ) : null;
	$summary = function_exists( 'meeha_get_summary' ) ? trim( wp_strip_all_tags( (string) meeha_get_summary( $post->ID ) ) ) : '';
	return apply_filters(
		'meeha_cards_news_payload',
		array(
			'type'     => 'news',
			'data'     => array(
				'title'    => $title,
				'category' => $cat ? html_entity_decode( $cat->name, ENT_QUOTES, 'UTF-8' ) : '',
				'date'     => wp_strip_all_tags( (string) $date ),
				'image'    => $img ? esc_url_raw( $img ) : '',
				'image_fallback' => ( $orig && $orig !== $img ) ? esc_url_raw( $orig ) : '',
				'focus'    => $focus ? array( 'x' => $focus['x'], 'y' => $focus['y'] ) : array( 'x' => 50, 'y' => 50 ),
				'zoom'     => $focus ? $focus['zoom'] : 1,
			),
			'opts'     => $opts,
			'filename' => 'meeha-news-' . get_the_date( 'Ymd', $post ) . '-' . $post->ID . '.png',
			'src'      => meeha_cards_script_src(),
			'config'   => meeha_cards_config(), // explicit logo/fonts/base: never derived from the (possibly combined) script URL.
			'caption'  => html_entity_decode( '' !== $summary ? $summary : $title, ENT_QUOTES, 'UTF-8' ) . "\n\n" . get_permalink( $post ),
			'title'    => $title,
		),
		$post
	);
}

/**
 * Saved card position of an image: `_meeha_card_focus` = { x: 0–100, y: 0–100, zoom: 1–3 } (written by the
 * publisher app's ফটোকার্ড স্টুডিও through newsroom-os), or null.
 *
 * @param int $attachment_id Attachment.
 * @return array|null x, y, zoom
 */
function meeha_cards_image_focus( $attachment_id ) {
	$f = get_post_meta( (int) $attachment_id, '_meeha_card_focus', true );
	if ( ! is_array( $f ) || ! isset( $f['x'], $f['y'] ) ) {
		return null;
	}
	return array(
		'x'    => max( 0, min( 100, round( (float) $f['x'], 1 ) ) ),
		'y'    => max( 0, min( 100, round( (float) $f['y'], 1 ) ) ),
		'zoom' => max( 1, min( 3, round( isset( $f['zoom'] ) ? (float) $f['zoom'] : 1, 2 ) ) ),
	);
}

/**
 * Payload for an উক্তি (meeha_photocard) "ছবি ডাউনলোড".
 *
 * @param WP_Post|int $post Quote post.
 * @return array
 */
function meeha_cards_quote_payload( $post ) {
	$post = get_post( $post );
	if ( ! $post || ! function_exists( 'meeha_studio_quote_data' ) ) {
		return array();
	}
	$q = meeha_studio_quote_data( $post );
	return array(
		'type'     => 'quote',
		'data'     => array(
			'text'          => $q['text'],
			'speaker'       => $q['speaker'],
			'role'          => $q['role'],
			'type_label'    => $q['type_label'],
			'frame'         => $q['frame'],
			'year'          => $q['year'],
			'speaker_photo' => $q['photo'],
		),
		'opts'     => array( 'size' => '1080x1080' ),
		'filename' => 'meeha-quote-' . get_the_date( 'Ymd', $post ) . '-' . $post->ID . '.png',
		'src'      => meeha_cards_script_src(),
		'config'   => meeha_cards_config(),
		'caption'  => '“' . $q['text'] . '”' . ( '' !== (string) $q['speaker'] ? ' — ' . $q['speaker'] : '' ) . "\n\n" . get_permalink( $post ),
		'title'    => wp_strip_all_tags( get_the_title( $post ) ),
	);
}

/* Admin bar: "ফটোকার্ড বানান" on single posts/quotes for card makers. */
add_action(
	'admin_bar_menu',
	function ( $bar ) {
		if ( is_admin() || ! is_singular( array( 'post', 'meeha_photocard' ) ) || ! current_user_can( 'meeha_make_cards' ) ) {
			return;
		}
		$id = get_queried_object_id();
		$bar->add_node(
			array(
				'id'    => 'meeha-card',
				'title' => 'ফটোকার্ড বানান',
				'href'  => meeha_studio_url( meeha_studio_type_for_post( $id ), $id ),
			)
		);
	},
	90
);
