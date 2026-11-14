<?php
/**
 * Google News readiness (2.4.11):
 * - Bengali language signals for Rank Math (og:locale bn_BD, schema inLanguage bn-BD) without
 *   changing the WordPress Site Language;
 * - NewsArticle polish: publisher logo, author Person url/sameAs;
 * - robots meta max-image-preview:large, noindex on per-post comment feeds;
 * - Google News sitemap at /news-sitemap.xml (last 48 hours, max 1000);
 * - robots.txt cleanup;
 * - image alt fallback;
 * - footer links + auto-created editorial/corrections policy pages (see policy-pages.php).
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

/* ---------------------------------------------------------------------------
 * 1. Language signals
 * ------------------------------------------------------------------------- */

add_filter(
	'rank_math/opengraph/facebook/og_locale',
	function () {
		return 'bn_BD';
	},
	99
);

/**
 * Rank Math JSON-LD: Bengali inLanguage everywhere, publisher logo, author url/sameAs.
 * Runs after the guest-author walker in byline.php (same priority, registered later).
 *
 * @param array $data   Entities.
 * @param mixed $jsonld Rank Math object (unused).
 * @return array
 */
function meeha_news_seo_json_ld( $data, $jsonld = null ) {
	if ( ! is_array( $data ) ) {
		return $data;
	}
	$data = meeha_news_seo_walk( $data );

	$logo = meeha_news_seo_logo();
	$post = is_singular( 'post' ) ? get_queried_object() : null;
	$uid  = ( $post instanceof WP_Post && ! ( function_exists( 'meeha_guest_author' ) && meeha_guest_author( $post ) ) ) ? (int) $post->post_author : 0;
	$aurl = $uid ? (string) get_author_posts_url( $uid ) : '';
	$same = $uid ? meeha_news_seo_author_same_as( $uid ) : array();

	foreach ( $data as $k => $node ) {
		if ( ! is_array( $node ) ) {
			continue;
		}
		$types = isset( $node['@type'] ) ? (array) $node['@type'] : array();
		// Publisher logo.
		if ( $logo && array_intersect( $types, array( 'Organization', 'NewsMediaOrganization' ) ) && empty( $node['logo'] ) ) {
			$data[ $k ]['logo'] = $logo;
		}
		// Article nodes: language + inline publisher logo.
		if ( array_intersect( $types, array( 'NewsArticle', 'Article', 'BlogPosting', 'WebPage', 'ItemPage', 'CollectionPage', 'WebSite', 'ProfilePage' ) ) ) {
			$data[ $k ]['inLanguage'] = 'bn-BD';
			if ( $logo && isset( $node['publisher'] ) && is_array( $node['publisher'] ) && ! isset( $node['publisher']['@id'] ) && empty( $node['publisher']['logo'] ) ) {
				$data[ $k ]['publisher']['logo'] = $logo;
			}
		}
		// The WP author's Person node (non-guest bylines only).
		if ( $uid && in_array( 'Person', $types, true ) ) {
			$id = isset( $node['@id'] ) ? untrailingslashit( preg_replace( '/#.*$/', '', (string) $node['@id'] ) ) : '';
			$nm = isset( $node['name'] ) ? (string) $node['name'] : '';
			if ( untrailingslashit( $aurl ) === $id || (string) get_the_author_meta( 'display_name', $uid ) === $nm ) {
				if ( empty( $node['url'] ) ) {
					$data[ $k ]['url'] = $aurl;
				}
				if ( $same ) {
					$have                 = isset( $node['sameAs'] ) ? (array) $node['sameAs'] : array();
					$data[ $k ]['sameAs'] = array_values( array_unique( array_merge( $have, $same ) ) );
				}
			}
		}
		// Inline author object inside the article.
		if ( $uid && isset( $node['author'] ) && is_array( $node['author'] ) && isset( $node['author']['name'] ) && ! isset( $node['author']['@id'] ) ) {
			if ( empty( $node['author']['url'] ) ) {
				$data[ $k ]['author']['url'] = $aurl;
			}
			if ( $same && empty( $node['author']['sameAs'] ) ) {
				$data[ $k ]['author']['sameAs'] = $same;
			}
		}
	}
	return $data;
}
add_filter( 'rank_math/json_ld', 'meeha_news_seo_json_ld', 99, 2 );

/**
 * Recursively set every inLanguage to bn-BD.
 *
 * @param array $node Node.
 * @return array
 */
function meeha_news_seo_walk( $node ) {
	foreach ( $node as $k => $v ) {
		if ( 'inLanguage' === $k ) {
			$node[ $k ] = 'bn-BD';
		} elseif ( is_array( $v ) ) {
			$node[ $k ] = meeha_news_seo_walk( $v );
		}
	}
	return $node;
}

/**
 * ImageObject for the publisher logo: SEO › স্কিমার লোগো, else the custom logo.
 *
 * @return array|null
 */
function meeha_news_seo_logo() {
	foreach ( array( (int) meeha_opt( 'seo', 'schema_logo', 0 ), (int) get_theme_mod( 'custom_logo' ) ) as $id ) {
		if ( ! $id ) {
			continue;
		}
		$src = wp_get_attachment_image_src( $id, 'full' );
		if ( $src ) {
			return array(
				'@type'  => 'ImageObject',
				'url'    => $src[0],
				'width'  => (int) $src[1],
				'height' => (int) $src[2],
			);
		}
	}
	return null;
}

/**
 * Author profile links for schema sameAs: user website + the usual social meta keys
 * (Rank Math / Yoast store facebook, twitter, additional_profile_urls…).
 *
 * @param int $uid User.
 * @return string[]
 */
function meeha_news_seo_author_same_as( $uid ) {
	$out = array();
	$raw = array( (string) get_the_author_meta( 'user_url', $uid ) );
	foreach ( array( 'facebook', 'twitter', 'linkedin', 'instagram', 'youtube', 'tiktok', 'meeha_facebook', 'meeha_social_url' ) as $key ) {
		$raw[] = (string) get_user_meta( $uid, $key, true );
	}
	$extra = (string) get_user_meta( $uid, 'additional_profile_urls', true );
	if ( '' !== $extra ) {
		$raw = array_merge( $raw, preg_split( '/[\s,]+/', $extra ) );
	}
	foreach ( $raw as $u ) {
		$u = trim( $u );
		if ( '' === $u ) {
			continue;
		}
		if ( ! preg_match( '#^https?://#i', $u ) ) {
			// Bare X/Twitter handle.
			if ( preg_match( '/^@?([A-Za-z0-9_]{1,15})$/', $u, $m ) ) {
				$u = 'https://x.com/' . $m[1];
			} else {
				continue;
			}
		}
		$u = esc_url_raw( $u );
		if ( '' !== $u && false === strpos( $u, wp_parse_url( home_url(), PHP_URL_HOST ) ) ) {
			$out[] = $u;
		}
	}
	return array_values( array_unique( $out ) );
}

/* ---------------------------------------------------------------------------
 * 2. Robots meta
 * ------------------------------------------------------------------------- */

add_filter(
	'wp_robots',
	function ( $robots ) {
		if ( empty( $robots['noindex'] ) ) {
			$robots['max-image-preview'] = 'large';
			if ( ! isset( $robots['max-snippet'] ) ) {
				$robots['max-snippet'] = '-1';
			}
			if ( ! isset( $robots['max-video-preview'] ) ) {
				$robots['max-video-preview'] = '-1';
			}
		}
		return $robots;
	},
	20
);

add_filter(
	'rank_math/frontend/robots',
	function ( $robots ) {
		if ( ! is_array( $robots ) || ( isset( $robots['index'] ) && 'noindex' === $robots['index'] ) ) {
			return $robots;
		}
		if ( empty( $robots['max-image-preview'] ) ) {
			$robots['max-image-preview'] = 'max-image-preview:large';
		}
		return $robots;
	},
	30
);

/*
 * Per-post comment feeds (/post-name/feed/): thin duplicates. Drop the <link> on singular pages and
 * send X-Robots-Tag noindex,follow if a crawler still requests one.
 */
add_action(
	'wp',
	function () {
		if ( is_singular() ) {
			remove_action( 'wp_head', 'feed_links_extra', 3 );
		}
	}
);
add_action(
	'template_redirect',
	function () {
		if ( is_feed() && ( is_singular() || is_comment_feed() ) && ! headers_sent() ) {
			header( 'X-Robots-Tag: noindex, follow', true );
		}
	},
	1
);

/* ---------------------------------------------------------------------------
 * 3. Google News sitemap — /news-sitemap.xml
 * ------------------------------------------------------------------------- */

add_action(
	'init',
	function () {
		add_rewrite_rule( '^news-sitemap\.xml$', 'index.php?meeha_news_sitemap=1', 'top' );
	}
);

add_filter(
	'query_vars',
	function ( $vars ) {
		$vars[] = 'meeha_news_sitemap';
		return $vars;
	}
);

// Never redirect /news-sitemap.xml to /news-sitemap.xml/.
add_filter(
	'redirect_canonical',
	function ( $redirect ) {
		return get_query_var( 'meeha_news_sitemap' ) ? false : $redirect;
	}
);

/*
 * 2.4.13: Rank Math's own rule `([^/]+?)-sitemap([0-9]+)?\.xml$` also matches news-sitemap.xml and answers 404
 * (its News Sitemap is not in the free version). So (1) put our rule first in the rewrite table and (2) answer the
 * request ourselves on wp_loaded — before any plugin parses the request — by looking at the request path.
 */
add_filter(
	'rewrite_rules_array',
	function ( $rules ) {
		return array( '^news-sitemap\.xml$' => 'index.php?meeha_news_sitemap=1' ) + (array) $rules;
	},
	9999
);

/**
 * Whether this request is for /news-sitemap.xml (also below a sub-folder install, or ?meeha_news_sitemap=1).
 *
 * @return bool
 */
function meeha_news_sitemap_is_request() {
	if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
		return false;
	}
	if ( isset( $_GET['meeha_news_sitemap'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return true;
	}
	$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
	$base = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
	return strtolower( untrailingslashit( $path ) ) === strtolower( untrailingslashit( $base ) . '/news-sitemap.xml' );
}

/**
 * Send the news sitemap and stop.
 */
function meeha_news_sitemap_send() {
	$xml = meeha_news_sitemap_xml();
	if ( ! headers_sent() ) {
		status_header( 200 );
		header( 'Content-Type: application/xml; charset=UTF-8' );
		header_remove( 'X-Robots-Tag' );
		// LiteSpeed Cache: short public TTL so fresh stories show up within ~5 minutes.
		header( 'X-LiteSpeed-Cache-Control: public,max-age=300' );
		header( 'Cache-Control: public, max-age=300' );
	}
	do_action( 'litespeed_control_set_ttl', 300 );
	echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit;
}

add_action(
	'wp_loaded',
	function () {
		if ( meeha_news_sitemap_is_request() ) {
			meeha_news_sitemap_send();
		}
	},
	0
);

// Fallback: the rewrite rule / query var path.
add_action(
	'template_redirect',
	function () {
		if ( get_query_var( 'meeha_news_sitemap' ) ) {
			meeha_news_sitemap_send();
		}
	},
	0
);

/**
 * Posts for the news sitemap: published, last 48 hours, newest first, max 1000, no password, no noindex.
 *
 * @return WP_Post[]
 */
function meeha_news_sitemap_posts() {
	$q = new WP_Query(
		array(
			'post_type'              => 'post',
			'post_status'            => 'publish',
			'has_password'           => false,
			'posts_per_page'         => 1000,
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'date_query'             => array(
				array(
					'column' => 'post_date_gmt',
					'after'  => gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ),
				),
			),
			'ignore_sticky_posts'    => true,
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'suppress_filters'       => false,
		)
	);
	$out = array();
	foreach ( $q->posts as $p ) {
		$robots = get_post_meta( $p->ID, 'rank_math_robots', true );
		if ( is_array( $robots ) && in_array( 'noindex', $robots, true ) ) {
			continue;
		}
		$out[] = $p;
	}
	return $out;
}

/**
 * The news sitemap XML.
 *
 * @return string
 */
function meeha_news_sitemap_xml() {
	$name = trim( (string) meeha_opt( 'seo', 'news_name', 'মিহা নিউজ' ) );
	if ( '' === $name ) {
		$name = 'মিহা নিউজ';
	}
	$esc = function ( $s ) {
		return function_exists( 'esc_xml' ) ? esc_xml( $s ) : htmlspecialchars( $s, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	};
	$tz  = new DateTimeZone( 'Asia/Dhaka' );
	$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
	$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:news="http://www.google.com/schemas/sitemap-news/0.9">' . "\n";
	foreach ( meeha_news_sitemap_posts() as $p ) {
		$title = html_entity_decode( wp_strip_all_tags( get_the_title( $p ) ), ENT_QUOTES, 'UTF-8' );
		if ( '' === $title ) {
			continue;
		}
		$ts   = (int) get_post_time( 'U', true, $p );
		$xml .= "\t<url>\n";
		$xml .= "\t\t<loc>" . $esc( get_permalink( $p ) ) . "</loc>\n";
		$xml .= "\t\t<news:news>\n";
		$xml .= "\t\t\t<news:publication>\n";
		$xml .= "\t\t\t\t<news:name>" . $esc( $name ) . "</news:name>\n";
		$xml .= "\t\t\t\t<news:language>bn</news:language>\n";
		$xml .= "\t\t\t</news:publication>\n";
		$xml .= "\t\t\t<news:publication_date>" . wp_date( 'c', $ts, $tz ) . "</news:publication_date>\n";
		$xml .= "\t\t\t<news:title>" . $esc( $title ) . "</news:title>\n";
		$xml .= "\t\t</news:news>\n";
		$xml .= "\t</url>\n";
	}
	$xml .= "</urlset>\n";
	return $xml;
}

/* ---------------------------------------------------------------------------
 * 4. robots.txt (only applies when there is no physical robots.txt file)
 * ------------------------------------------------------------------------- */

add_filter(
	'robots_txt',
	function ( $output, $public ) {
		if ( ! $public ) {
			return $output;
		}
		$lines = preg_split( '/\r\n|\r|\n/', (string) $output );
		$keep  = array();
		foreach ( $lines as $line ) {
			// Sitemap lines are rebuilt below; stale ones (e-floating-buttons, post-sitemap) disappear.
			if ( preg_match( '/^\s*sitemap\s*:/i', $line ) ) {
				continue;
			}
			$keep[] = $line;
		}
		$text = trim( implode( "\n", $keep ) );
		if ( '' === $text ) {
			$text = "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php";
		}
		foreach ( array( 'Disallow: /?s=', 'Disallow: /*?s=' ) as $rule ) {
			if ( false === stripos( $text, $rule ) ) {
				// Add under the first "User-agent: *" group.
				if ( preg_match( '/^User-agent:\s*\*\s*$/mi', $text ) ) {
					$text = preg_replace( '/^(User-agent:\s*\*\s*)$/mi', "$1\n" . $rule, $text, 1 );
				} else {
					$text .= "\n\nUser-agent: *\n" . $rule;
				}
			}
		}
		$text .= "\n\nSitemap: " . home_url( '/sitemap_index.xml' );
		$text .= "\nSitemap: " . home_url( '/news-sitemap.xml' ) . "\n";
		return $text;
	},
	999,
	2
);

/* ---------------------------------------------------------------------------
 * 5. Image alt fallback (attachment alt → attachment caption → parent post title)
 * ------------------------------------------------------------------------- */

/**
 * Fallback alt text for an attachment.
 *
 * @param int $id Attachment.
 * @return string
 */
function meeha_news_seo_alt_for( $id ) {
	$id = (int) $id;
	if ( ! $id ) {
		return '';
	}
	$alt = trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) );
	if ( '' !== $alt ) {
		return $alt;
	}
	$cap = trim( wp_strip_all_tags( (string) wp_get_attachment_caption( $id ) ) );
	if ( '' !== $cap ) {
		return wp_html_excerpt( $cap, 125 );
	}
	$att    = get_post( $id );
	$parent = $att && $att->post_parent ? get_post( $att->post_parent ) : null;
	if ( $parent && 'publish' === $parent->post_status ) {
		return wp_strip_all_tags( get_the_title( $parent ) );
	}
	$t = $att ? trim( (string) $att->post_title ) : '';
	// Skip camera / file names such as IMG_2034 or 1699999-scaled.
	if ( '' !== $t && ! preg_match( '/^(img|dsc|pxl|screenshot|whatsapp|photo|image)?[\s_\-]*[\d\s_\-]+(scaled)?$/i', $t ) ) {
		return $t;
	}
	return '';
}

add_filter(
	'wp_get_attachment_image_attributes',
	function ( $attr, $attachment ) {
		if ( ( ! isset( $attr['alt'] ) || '' === trim( (string) $attr['alt'] ) ) && $attachment instanceof WP_Post ) {
			$alt = meeha_news_seo_alt_for( $attachment->ID );
			if ( '' !== $alt ) {
				$attr['alt'] = $alt;
			}
		}
		return $attr;
	},
	20,
	2
);

add_filter(
	'wp_content_img_tag',
	function ( $img, $context, $attachment_id ) {
		if ( ! $attachment_id ) {
			return $img;
		}
		$has_alt = preg_match( '/\salt\s*=\s*(["\'])(.*?)\1/i', $img, $m );
		if ( $has_alt && '' !== trim( $m[2] ) ) {
			return $img;
		}
		$alt = meeha_news_seo_alt_for( $attachment_id );
		if ( '' === $alt ) {
			return $img;
		}
		if ( $has_alt ) {
			return preg_replace( '/\salt\s*=\s*(["\'])\s*\1/i', ' alt="' . esc_attr( $alt ) . '"', $img, 1 );
		}
		return preg_replace( '/^<img\b/i', '<img alt="' . esc_attr( $alt ) . '"', $img, 1 );
	},
	20,
	3
);

/* ---------------------------------------------------------------------------
 * 6. Policy pages: footer links (pages are created by policy-pages.php during migration)
 * ------------------------------------------------------------------------- */

/**
 * Published editorial/corrections policy pages: [ [title, url], … ].
 *
 * @return array
 */
function meeha_news_seo_policy_links() {
	$out = array();
	foreach ( array(
		'editorial-policy'   => 'সম্পাদকীয় নীতি',
		'corrections-policy' => 'সংশোধন নীতি',
	) as $slug => $label ) {
		$p = get_page_by_path( $slug );
		if ( $p && 'publish' === $p->post_status ) {
			$out[] = array( $label, get_permalink( $p ) );
		}
	}
	return $out;
}
