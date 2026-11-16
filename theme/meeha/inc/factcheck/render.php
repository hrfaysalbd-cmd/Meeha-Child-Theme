<?php
/**
 * ফ্যাক্টচেক — front end: verdict card, ClaimReview JSON-LD, card chips, /factcheck/ archive.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

/* ------------------------------------------------------------- single: verdict card */

/**
 * The verdict card HTML.
 *
 * @param WP_Post $post Post.
 * @return string
 */
function meeha_fc_card_html( $post ) {
	$fc = meeha_fc_get( $post );
	if ( ! $fc ) {
		return '';
	}
	$vs  = meeha_fc_verdicts();
	$vk  = isset( $vs[ $fc['verdict'] ] ) ? $fc['verdict'] : '';
	$v   = $vk ? $vs[ $vk ] : array( 'যাচাই চলছে', '#5B6676', 0, 'রায় এখনো চূড়ান្ত হয়নি।' );
	$id  = 'm-fc-' . (int) $post->ID;
	$out = '<aside class="m-fc" id="' . esc_attr( $id ) . '" style="--fc:' . esc_attr( $v[1] ) . '" aria-labelledby="' . esc_attr( $id ) . '-h" data-verdict="' . esc_attr( $vk ) . '">';
	$out .= '<header class="m-fc__head"><p class="m-fc__label" id="' . esc_attr( $id ) . '-h">' . meeha_icon( 'check', array( 'size' => 'sm' ) ) . 'ফ্যাক্টচেক</p>';
	$out .= '<p class="m-fc__verdict"><span class="m-fc__vk">রায়</span><strong>' . esc_html( $v[0] ) . '</strong></p>';
	$out .= '<p class="m-fc__meaning">' . esc_html( $v[3] ) . '</p></header>';

	if ( '' !== trim( (string) $fc['claim'] ) ) {
		$out .= '<div class="m-fc__claim"><p class="m-fc__k">দাবি</p><blockquote class="m-fc__quote"><p>' . nl2br( esc_html( $fc['claim'] ) ) . '</p></blockquote>';
		$by   = array();
		if ( '' !== trim( (string) $fc['claimant'] ) ) {
			$by[] = '<span>দাবি করেছেন: <b>' . esc_html( $fc['claimant'] ) . '</b></span>';
		}
		if ( $fc['claim_date'] ) {
			$t    = strtotime( $fc['claim_date'] );
			$by[] = '<span>তারিখ: <time datetime="' . esc_attr( $fc['claim_date'] ) . '">' . esc_html( meeha_bn_digits( gmdate( 'j', $t ) ) . ' ' . meeha_bengali_month( (int) gmdate( 'n', $t ) ) . ' ' . meeha_bn_digits( gmdate( 'Y', $t ) ) ) . '</time></span>';
		}
		if ( $by ) {
			$out .= '<p class="m-fc__by">' . implode( '', $by ) . '</p>';
		}
		if ( $fc['spread'] ) {
			$out .= '<p class="m-fc__spread"><span>যেখানে ছড়িয়েছে:</span>';
			foreach ( $fc['spread'] as $i => $u ) {
				$out .= ' <a href="' . esc_url( $u ) . '" rel="nofollow noopener ugc" target="_blank">' . esc_html( meeha_fc_platform( $u ) ) . ( count( $fc['spread'] ) > 1 ? ' ' . meeha_bn_digits( $i + 1 ) : '' ) . '<span class="m-vh"> (নতুন ট্যাবে খুলবে)</span></a>';
			}
			$out .= '</p>';
		}
		$out .= '</div>';
	}

	if ( '' !== trim( wp_strip_all_tags( (string) $fc['evidence'] ) ) || $fc['sources'] ) {
		$out .= '<section class="m-fc__how" aria-labelledby="' . esc_attr( $id ) . '-how"><h2 class="m-fc__h" id="' . esc_attr( $id ) . '-how">যেভাবে যাচাই করেছি</h2>';
		if ( '' !== trim( wp_strip_all_tags( (string) $fc['evidence'] ) ) ) {
			$out .= '<div class="m-fc__evidence">' . wpautop( wp_kses_post( $fc['evidence'] ) ) . '</div>';
		}
		if ( $fc['sources'] ) {
			$out .= '<h3 class="m-fc__h3">সূত্র</h3><ol class="m-fc__sources">';
			foreach ( $fc['sources'] as $s ) {
				$t = '' !== $s['title'] ? $s['title'] : preg_replace( '#^https?://(www\.)?#', '', (string) $s['url'] );
				$out .= '<li>' . ( $s['url'] ? '<a href="' . esc_url( $s['url'] ) . '" rel="noopener" target="_blank">' . esc_html( $t ) . '</a>' : esc_html( $t ) ) . '</li>';
			}
			$out .= '</ol>';
		}
		$out .= '</section>';
	}
	$out .= '<p class="m-fc__foot">ভুল পেয়েছেন বা আরও তথ্য আছে? <a href="' . esc_url( home_url( '/tips/' ) ) . '">গোপনে জানান</a> · <a href="' . esc_url( home_url( '/' . MEEHA_FC_SLUG . '/' ) ) . '">সব ফ্যাক্টচেক</a></p>';
	$out .= '</aside>';
	return $out;
}

add_filter(
	'the_content',
	function ( $content ) {
		static $done = array();
		if ( is_admin() || is_feed() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ! is_singular( 'post' ) ) {
			return $content;
		}
		$post = get_post();
		if ( ! $post || (int) $post->ID !== (int) get_queried_object_id() || isset( $done[ $post->ID ] ) ) {
			return $content;
		}
		$card = meeha_fc_card_html( $post );
		if ( '' === $card ) {
			return $content;
		}
		$done[ $post->ID ] = true;
		return $card . $content;
	},
	20
);

/* ------------------------------------------------------------- ClaimReview JSON-LD */

/**
 * schema.org ClaimReview for a fact-check (null when the verdict or claim is missing).
 *
 * @param WP_Post $post Post.
 * @return array|null
 */
function meeha_fc_claimreview( $post ) {
	$fc = meeha_fc_get( $post );
	$vs = meeha_fc_verdicts();
	if ( ! $fc || ! isset( $vs[ $fc['verdict'] ] ) || '' === trim( (string) $fc['claim'] ) ) {
		return null;
	}
	$v      = $vs[ $fc['verdict'] ];
	$rating = array(
		'@type'         => 'Rating',
		'alternateName' => $v[0],
	);
	if ( $v[2] > 0 ) {
		$rating += array(
			'ratingValue' => (int) $v[2],
			'bestRating'  => 5,
			'worstRating' => 1,
		);
	}
	$claim = array(
		'@type'      => 'Claim',
		'inLanguage' => 'bn-BD',
	);
	if ( '' !== trim( (string) $fc['claimant'] ) ) {
		$claim['author'] = array(
			'@type' => 'org' === $fc['claimant_type'] ? 'Organization' : 'Person',
			'name'  => $fc['claimant'],
		);
	}
	if ( $fc['claim_date'] ) {
		$claim['datePublished'] = $fc['claim_date'];
	}
	if ( $fc['spread'] ) {
		$claim['appearance']      = array_map(
			function ( $u ) {
				return array(
					'@type' => 'CreativeWork',
					'url'   => $u,
				);
			},
			array_values( $fc['spread'] )
		);
		$claim['firstAppearance'] = $claim['appearance'][0];
	}
	$logo = function_exists( 'get_site_icon_url' ) ? get_site_icon_url( 512 ) : '';
	$org  = array(
		'@type' => 'Organization',
		'name'  => get_bloginfo( 'name' ),
		'url'   => home_url( '/' ),
	);
	if ( $logo ) {
		$org['logo'] = $logo;
	}
	return array(
		'@context'      => 'https://schema.org',
		'@type'         => 'ClaimReview',
		'@id'           => get_permalink( $post ) . '#claimreview',
		'url'           => get_permalink( $post ),
		'headline'      => wp_strip_all_tags( get_the_title( $post ) ),
		'claimReviewed' => wp_strip_all_tags( $fc['claim'] ),
		'datePublished' => get_post_time( 'c', true, $post ),
		'dateModified'  => get_post_modified_time( 'c', true, $post ),
		'inLanguage'    => 'bn-BD',
		'author'        => $org,
		'reviewRating'  => $rating,
		'itemReviewed'  => $claim,
	);
}

add_action(
	'wp_head',
	function () {
		if ( ! is_singular( 'post' ) ) {
			return;
		}
		$ld = meeha_fc_claimreview( get_queried_object() );
		if ( $ld ) {
			echo '<script type="application/ld+json">' . wp_json_encode( $ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG ) . "</script>\n";
		}
	},
	31
);

/* ------------------------------------------------------------- automation: chip on every card */

add_filter(
	'meeha_card_title_badges',
	function ( $html, $post ) {
		if ( ! $post || 'post' !== $post->post_type || ! get_post_meta( $post->ID, '_meeha_fc', true ) ) {
			return $html;
		}
		return $html . meeha_fc_chip( (string) get_post_meta( $post->ID, '_meeha_fc_verdict', true ) );
	},
	10,
	2
);

/* ------------------------------------------------------------- assets */

add_action(
	'wp',
	function () {
		if ( ( is_singular( 'post' ) && meeha_fc_get( get_queried_object() ) ) || get_query_var( 'meeha_fc_archive' ) ) {
			meeha_enqueue_bundle( 'factcheck' );
		}
	}
);

/* ------------------------------------------------------------- /factcheck/ archive */

add_filter(
	'query_vars',
	function ( $v ) {
		$v[] = 'meeha_fc_archive';
		$v[] = 'verdict';
		return $v;
	}
);

add_action(
	'pre_get_posts',
	function ( $q ) {
		if ( is_admin() || ! $q->is_main_query() || ! $q->get( 'meeha_fc_archive' ) ) {
			return;
		}
		$meta = array(
			array(
				'key'   => '_meeha_fc',
				'value' => '1',
			),
		);
		$vk   = sanitize_key( (string) $q->get( 'verdict' ) );
		if ( $vk && array_key_exists( $vk, meeha_fc_verdicts() ) ) {
			$meta[] = array(
				'key'   => '_meeha_fc_verdict',
				'value' => $vk,
			);
		}
		$q->set( 'post_type', 'post' );
		$q->set( 'post_status', 'publish' );
		$q->set( 'meta_query', $meta ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		$q->set( 'posts_per_page', 18 );
		$q->set( 'ignore_sticky_posts', true );
		$q->is_home       = false;
		$q->is_archive    = true;
		$q->is_post_type_archive = false;
	}
);

add_filter(
	'template_include',
	function ( $t ) {
		return get_query_var( 'meeha_fc_archive' ) ? __DIR__ . '/archive-template.php' : $t;
	}
);

add_filter(
	'document_title_parts',
	function ( $parts ) {
		if ( get_query_var( 'meeha_fc_archive' ) ) {
			$parts['title'] = 'ফ্যাক্টচেক';
		}
		return $parts;
	}
);

/**
 * How many published fact-checks per verdict (cached 10 minutes).
 *
 * @return array verdict => n, plus '' => total.
 */
function meeha_fc_counts() {
	$c = get_transient( 'meeha_fc_counts' );
	if ( is_array( $c ) ) {
		return $c;
	}
	global $wpdb;
	$rows = $wpdb->get_results(
		"SELECT v.meta_value AS verdict, COUNT(*) AS n FROM {$wpdb->posts} p
		 JOIN {$wpdb->postmeta} f ON f.post_id = p.ID AND f.meta_key = '_meeha_fc' AND f.meta_value = '1'
		 LEFT JOIN {$wpdb->postmeta} v ON v.post_id = p.ID AND v.meta_key = '_meeha_fc_verdict'
		 WHERE p.post_type = 'post' AND p.post_status = 'publish' GROUP BY v.meta_value",
		ARRAY_A
	);
	$c   = array( '' => 0 );
	foreach ( (array) $rows as $r ) {
		$c[ (string) $r['verdict'] ] = (int) $r['n'];
		$c['']                      += (int) $r['n'];
	}
	set_transient( 'meeha_fc_counts', $c, 10 * MINUTE_IN_SECONDS );
	return $c;
}
add_action(
	'save_post_post',
	function () {
		delete_transient( 'meeha_fc_counts' );
	}
);
