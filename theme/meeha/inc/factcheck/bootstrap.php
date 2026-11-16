<?php
/**
 * ফ্যাক্টচেক (r46). A normal `post` becomes a fact-check when its `_meeha_fc` flag is on; then it carries
 * দাবি / দাবিকারী / যেখানে ছড়িয়েছে / রায় / যাচাইয়ের বিবরণ / সূত্র. What the module does with that:
 *  - verdict card at the top of the article (the_content), ClaimReview JSON-LD in <head>;
 *  - a coloured verdict chip in front of the title on every story card (filter `meeha_card_title_badges`);
 *  - tags the post “ফ্যাক্টচেক” on save (automation), and /factcheck/ lists them with verdict filter chips;
 *  - fields are editable in a wp-admin meta box and exposed through REST (register_post_meta, show_in_rest).
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

/**
 * Verdicts: key => [label, colour, ClaimReview rating 1–5 (0 = no number), short meaning].
 *
 * @return array
 */
function meeha_fc_verdicts() {
	return (array) apply_filters(
		'meeha_fc_verdicts',
		array(
			'true'       => array( 'সত্য', '#1E7B45', 5, 'দাবিটি তথ্যপ্রমাণে সঠিক।' ),
			'partly'     => array( 'আংশিক সত্য', '#8A6100', 3, 'দাবির কিছু অংশ সঠিক, কিছু অংশ ভুল বা অসম্পূর্ণ।' ),
			'misleading' => array( 'বিভ্রান্তিকর', '#C2410C', 2, 'তথ্য সত্য হলেও এমনভাবে উপস্থাপিত যে ভুল ধারণা তৈরি করে।' ),
			'false'      => array( 'মিথ্যা', '#CC2A1F', 1, 'দাবিটি তথ্যপ্রমাণে ভুল প্রমাণিত।' ),
			'context'    => array( 'প্রেক্ষাপটহীন', '#6B4FA0', 2, 'পুরোনো বা অন্য ঘটনার ছবি/ভিডিও/উক্তি ভিন্ন প্রেক্ষাপটে ছড়ানো হয়েছে।' ),
			'satire'     => array( 'ব্যঙ্গ', '#2C6E9B', 0, 'ব্যঙ্গাত্মক কনটেন্ট, যা অনেকে সত্য খবর ভেবে ছড়িয়েছেন।' ),
		)
	);
}

/** Slug of the archive (/factcheck/) and of the automatic tag. */
const MEEHA_FC_SLUG = 'factcheck';

/**
 * Meta keys → REST schema.
 *
 * @return array
 */
function meeha_fc_meta_schema() {
	return array(
		'_meeha_fc'               => array( 'type' => 'boolean', 'default' => false ),
		'_meeha_fc_claim'         => array( 'type' => 'string', 'default' => '' ),
		'_meeha_fc_claimant'      => array( 'type' => 'string', 'default' => '' ),
		'_meeha_fc_claimant_type' => array( 'type' => 'string', 'default' => 'person', 'enum' => array( 'person', 'org' ) ),
		'_meeha_fc_claim_date'    => array( 'type' => 'string', 'default' => '' ),
		'_meeha_fc_spread'        => array(
			'type'    => 'array',
			'default' => array(),
			'items'   => array( 'type' => 'string', 'format' => 'uri' ),
		),
		'_meeha_fc_verdict'       => array( 'type' => 'string', 'default' => '', 'enum' => array_merge( array( '' ), array_keys( meeha_fc_verdicts() ) ) ),
		'_meeha_fc_evidence'      => array( 'type' => 'string', 'default' => '' ),
		'_meeha_fc_sources'       => array(
			'type'    => 'array',
			'default' => array(),
			'items'   => array(
				'type'       => 'object',
				'properties' => array(
					'title' => array( 'type' => 'string' ),
					'url'   => array( 'type' => 'string' ),
				),
			),
		),
	);
}

add_action(
	'init',
	function () {
		$auth = function ( $allowed, $meta_key, $post_id ) {
			return current_user_can( 'edit_post', (int) $post_id );
		};
		foreach ( meeha_fc_meta_schema() as $key => $def ) {
			$schema = $def;
			unset( $schema['default'] );
			$show = array( 'schema' => $schema );
			if ( 'array' === $def['type'] ) {
				$show['schema']['items'] = $def['items'];
			}
			register_post_meta(
				'post',
				$key,
				array(
					'type'              => $def['type'],
					'single'            => true,
					'default'           => $def['default'],
					'show_in_rest'      => $show,
					'auth_callback'     => $auth,
					'sanitize_callback' => function ( $v ) use ( $key ) {
						return meeha_fc_sanitize( $key, $v );
					},
				)
			);
		}
		add_rewrite_rule( '^' . MEEHA_FC_SLUG . '/?$', 'index.php?meeha_fc_archive=1', 'top' );
		add_rewrite_rule( '^' . MEEHA_FC_SLUG . '/page/([0-9]+)/?$', 'index.php?meeha_fc_archive=1&paged=$matches[1]', 'top' );
		if ( get_option( 'meeha_fc_rewrite_v' ) !== '1' ) {
			update_option( 'meeha_fc_rewrite_v', '1', false );
			update_option( 'meeha_flush_rewrites', 1, false ); // foundation flushes on the next init (inc/core/migrate.php).
		}
	}
);

/**
 * Sanitize one fact-check field.
 *
 * @param string $key Meta key.
 * @param mixed  $v   Value.
 * @return mixed
 */
function meeha_fc_sanitize( $key, $v ) {
	switch ( $key ) {
		case '_meeha_fc':
			return (bool) $v;
		case '_meeha_fc_claimant_type':
			return 'org' === $v ? 'org' : 'person';
		case '_meeha_fc_claim_date':
			return preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $v ) ? (string) $v : '';
		case '_meeha_fc_verdict':
			return array_key_exists( (string) $v, meeha_fc_verdicts() ) ? (string) $v : '';
		case '_meeha_fc_evidence':
			return wp_kses_post( (string) $v );
		case '_meeha_fc_spread':
			$v = is_array( $v ) ? $v : preg_split( '/[\r\n]+/', (string) $v );
			return array_values( array_filter( array_map( 'esc_url_raw', array_map( 'trim', $v ) ) ) );
		case '_meeha_fc_sources':
			$out = array();
			foreach ( (array) $v as $s ) {
				$t = is_array( $s ) && isset( $s['title'] ) ? sanitize_text_field( $s['title'] ) : '';
				$u = is_array( $s ) && isset( $s['url'] ) ? esc_url_raw( trim( (string) $s['url'] ) ) : '';
				if ( '' !== $t || '' !== $u ) {
					$out[] = array( 'title' => $t, 'url' => $u );
				}
			}
			return $out;
		default:
			return sanitize_textarea_field( (string) $v );
	}
}

/**
 * All fact-check fields of a post (null when it is not a fact-check).
 *
 * @param WP_Post|int|null $post Post.
 * @return array|null
 */
function meeha_fc_get( $post = null ) {
	$post = get_post( $post );
	if ( ! $post || 'post' !== $post->post_type || ! get_post_meta( $post->ID, '_meeha_fc', true ) ) {
		return null;
	}
	$out = array();
	foreach ( meeha_fc_meta_schema() as $key => $def ) {
		$v                                    = get_post_meta( $post->ID, $key, true );
		$out[ substr( $key, strlen( '_meeha_fc_' ) ) ?: 'on' ] = ( '' === $v || null === $v ) ? $def['default'] : $v;
	}
	$out['spread']  = is_array( $out['spread'] ) ? $out['spread'] : array();
	$out['sources'] = is_array( $out['sources'] ) ? $out['sources'] : array();
	return $out;
}

/**
 * The platform a link points to (for the label next to it).
 *
 * @param string $url URL.
 * @return string
 */
function meeha_fc_platform( $url ) {
	$h = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	$map = array(
		'facebook.com' => 'ফেসবুক',
		'fb.watch'     => 'ফেসবুক',
		'fb.com'       => 'ফেসবুক',
		'youtube.com'  => 'ইউটিউব',
		'youtu.be'     => 'ইউটিউব',
		'tiktok.com'   => 'টিকটক',
		'x.com'        => 'এক্স',
		'twitter.com'  => 'এক্স',
		'instagram.com' => 'ইনস্টাগ্রাম',
		'whatsapp.com' => 'হোয়াটসঅ্যাপ',
		't.me'         => 'টেলিগ্রাম',
	);
	foreach ( $map as $dom => $label ) {
		if ( $h === $dom || substr( $h, -strlen( '.' . $dom ) ) === '.' . $dom ) {
			return $label;
		}
	}
	return $h ? preg_replace( '/^www\./', '', $h ) : 'লিংক';
}

/**
 * Coloured verdict chip.
 *
 * @param string $verdict Key.
 * @param string $size    'sm' | 'lg'.
 * @return string
 */
function meeha_fc_chip( $verdict, $size = 'sm' ) {
	$v = meeha_fc_verdicts();
	if ( ! isset( $v[ $verdict ] ) ) {
		return '';
	}
	return sprintf(
		'<span class="m-fc-chip m-fc-chip--%1$s m-fc-chip--%2$s" style="--fc:%3$s"><span class="m-vh">ফ্যাক্টচেক রায়: </span>%4$s</span>',
		esc_attr( $size ),
		esc_attr( $verdict ),
		esc_attr( $v[ $verdict ][1] ),
		esc_html( $v[ $verdict ][0] )
	);
}

/* ------------------------------------------------------------- automation: tag on save */

/**
 * Keep the “ফ্যাক্টচেক” tag in step with the flag.
 *
 * @param int $post_id Post.
 */
function meeha_fc_sync_tag( $post_id ) {
	$post_id = (int) $post_id;
	if ( 'post' !== get_post_type( $post_id ) || wp_is_post_revision( $post_id ) ) {
		return;
	}
	$on   = (bool) get_post_meta( $post_id, '_meeha_fc', true );
	$term = get_term_by( 'slug', MEEHA_FC_SLUG, 'post_tag' );
	if ( ! $term && $on ) {
		$made = wp_insert_term( 'ফ্যাক্টচেক', 'post_tag', array( 'slug' => MEEHA_FC_SLUG ) );
		$term = is_wp_error( $made ) ? null : get_term( $made['term_id'], 'post_tag' );
	}
	if ( ! $term ) {
		return;
	}
	$has = has_term( (int) $term->term_id, 'post_tag', $post_id );
	if ( $on && ! $has ) {
		wp_set_post_terms( $post_id, array( (int) $term->term_id ), 'post_tag', true );
	} elseif ( ! $on && $has ) {
		wp_remove_object_terms( $post_id, (int) $term->term_id, 'post_tag' );
	}
}
foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $meeha_fc_hook ) {
	add_action(
		$meeha_fc_hook,
		function ( $meta_id, $object_id, $meta_key ) {
			if ( '_meeha_fc' === $meta_key ) {
				meeha_fc_sync_tag( $object_id );
			}
		},
		10,
		3
	);
}
unset( $meeha_fc_hook );

/* ------------------------------------------------------------- the rest */

require_once __DIR__ . '/admin.php';
require_once __DIR__ . '/render.php';
