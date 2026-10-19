<?php
/**
 * Results-page helpers: URLs with/without filters, applied chips, result item markup, did-you-mean.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

/**
 * Results URL for the current query with changed params (null removes a param; paging always reset).
 *
 * @param array $changes Param => value|null.
 * @return string
 */
function meeha_search_url( $changes = array() ) {
	$f    = meeha_search_filters();
	$args = array(
		's'      => get_search_query( false ),
		'type'   => 'all' !== $f['type'] ? $f['type'] : null,
		'cat'    => $f['cat'] ? $f['cat'] : null,
		'author' => $f['author'] ? $f['author'] : null,
		'when'   => 'any' !== $f['when'] ? $f['when'] : null,
		'from'   => 'range' === $f['when'] && $f['from'] ? $f['from'] : null,
		'to'     => 'range' === $f['when'] && $f['to'] ? $f['to'] : null,
		'sort'   => 'relevance' !== $f['sort'] ? $f['sort'] : null,
	);
	foreach ( (array) $changes as $k => $v ) {
		$args[ $k ] = $v;
	}
	$args = array_filter(
		$args,
		function ( $v ) {
			return null !== $v && '' !== $v;
		}
	);
	return add_query_arg( array_map( 'rawurlencode', $args ), home_url( '/' ) );
}

/**
 * Bengali label of the time filter.
 *
 * @param array $f Filters.
 * @return string
 */
function meeha_search_when_label( $f ) {
	$l = array(
		'1d'  => 'গত ২৪ ঘণ্টা',
		'7d'  => 'গত ৭ দিন',
		'30d' => 'গত ৩০ দিন',
	);
	if ( isset( $l[ $f['when'] ] ) ) {
		return $l[ $f['when'] ];
	}
	if ( 'range' === $f['when'] ) {
		$fmt = function ( $d ) {
			return $d ? meeha_bn_digits( (int) substr( $d, 8, 2 ) ) . ' ' . meeha_bengali_month( (int) substr( $d, 5, 2 ) ) . ' ' . meeha_bn_digits( substr( $d, 0, 4 ) ) : '…';
		};
		return $fmt( $f['from'] ) . ' – ' . $fmt( $f['to'] );
	}
	return '';
}

/**
 * Applied filter chips [[label, remove-url]].
 *
 * @return array
 */
function meeha_search_applied() {
	$f   = meeha_search_filters();
	$out = array();
	if ( 'any' !== $f['when'] ) {
		$out[] = array(
			meeha_search_when_label( $f ),
			meeha_search_url(
				array(
					'when' => null,
					'from' => null,
					'to'   => null,
				)
			),
		);
	}
	if ( $f['cat'] ) {
		$t = get_term( $f['cat'], 'category' );
		if ( $t && ! is_wp_error( $t ) ) {
			$out[] = array( $t->name, meeha_search_url( array( 'cat' => null ) ) );
		}
	}
	if ( $f['author'] ) {
		$u = get_userdata( $f['author'] );
		if ( $u ) {
			$out[] = array( $u->display_name, meeha_search_url( array( 'author' => null ) ) );
		}
	}
	return $out;
}

/**
 * Authors with published posts (for the filter select; cached 1 h).
 *
 * @return array id => name
 */
function meeha_search_authors() {
	$a = get_transient( 'meeha_search_authors' );
	if ( ! is_array( $a ) ) {
		$a = array();
		foreach ( get_users( array( 'has_published_posts' => meeha_search_post_types(), 'fields' => array( 'ID', 'display_name' ), 'orderby' => 'display_name', 'number' => 200 ) ) as $u ) {
			$a[ (int) $u->ID ] = $u->display_name;
		}
		set_transient( 'meeha_search_authors', $a, HOUR_IN_SECONDS );
	}
	return $a;
}

/**
 * Plain text used for the result snippet.
 *
 * @param WP_Post $p Post.
 * @return string
 */
function meeha_search_plain( $p ) {
	if ( '' !== (string) $p->post_password ) {
		return ''; // password-protected: never quote its text in results.
	}
	if ( 'meeha_photocard' === $p->post_type ) {
		return trim( wp_strip_all_tags( $p->post_content . ' ' . get_post_meta( $p->ID, '_meeha_attribution', true ) ) );
	}
	$text = wp_strip_all_tags( strip_shortcodes( (string) $p->post_content ), true );
	if ( '' === trim( $text ) ) {
		$text = (string) $p->post_excerpt;
	}
	return html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
}

/**
 * Icon for a type badge.
 *
 * @param string $label Type label.
 * @return string
 */
function meeha_search_type_icon( $label ) {
	$map = array(
		'ভিডিও'  => 'play',
		'মতামত' => 'quote',
		'উক্তি'  => 'quote',
		'সাহিত্য' => 'feather',
	);
	return isset( $map[ $label ] ) ? meeha_icon( $map[ $label ] ) : '';
}

/**
 * One result item.
 *
 * @param WP_Post $p     Post.
 * @param string  $regex Highlight regex.
 * @param int     $i     Index (first one eager).
 */
function meeha_search_result( $p, $regex, $i = 0 ) {
	$label   = meeha_post_type_label( $p );
	$title   = html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' );
	$img     = meeha_post_img( $p, 'card', $i < 2 ? array( 'eager' => true ) : array() );
	$cat     = 'post' === $p->post_type ? meeha_primary_category( $p ) : null;
	$snippet = meeha_search_snippet( meeha_search_plain( $p ), $regex, 160 );
	$dur     = 'meeha_video' === $p->post_type && function_exists( 'meeha_home_duration' ) ? meeha_home_duration( get_post_meta( $p->ID, '_meeha_video_duration', true ) ) : '';
	?>
	<li class="m-result<?php echo $img ? '' : ' m-result--noimg'; ?>">
		<?php if ( $img ) : ?>
			<div class="m-result__media"><div class="m-media"><?php echo $img; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core image markup. ?></div></div>
		<?php endif; ?>
		<div class="m-result__top"><span class="m-type"><?php echo meeha_search_type_icon( $label ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php echo esc_html( $label ); ?></span><?php if ( $cat ) : ?><a class="m-kicker" href="<?php echo esc_url( get_category_link( $cat ) ); ?>"><?php echo esc_html( $cat->name ); ?></a><?php endif; ?></div>
		<h2 class="m-result__title"><a href="<?php echo esc_url( get_permalink( $p ) ); ?>"><?php echo meeha_search_mark( $title, $regex ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?></a></h2>
		<?php if ( '' !== $snippet ) : ?>
			<p class="m-result__snippet"><?php echo $snippet; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?></p>
		<?php endif; ?>
		<div class="m-meta"><span><?php echo esc_html( $dur ? $dur : meeha_post_byline( $p )['name'] ); ?></span><time datetime="<?php echo esc_attr( get_post_time( 'c', true, $p ) ); ?>"><?php echo esc_html( meeha_bengali_date( (int) get_post_time( 'U', true, $p ), false ) ); ?></time></div>
	</li>
	<?php
}

/**
 * Did-you-mean suggestion for a query with no results ('' when none).
 *
 * @param string $q Raw query.
 * @return string Suggested query (display spelling).
 */
function meeha_search_suggest( $q ) {
	$vocab = get_transient( 'meeha_search_vocab' );
	if ( ! is_array( $vocab ) ) {
		$vocab = array();
		$add   = function ( $text ) use ( &$vocab ) {
			foreach ( preg_split( '/[^\p{L}\p{M}\p{N}]+/u', html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' ), -1, PREG_SPLIT_NO_EMPTY ) as $w ) {
				if ( mb_strlen( $w ) >= 3 ) {
					$n = meeha_search_normalize( $w );
					if ( ! isset( $vocab[ $n ] ) ) {
						$vocab[ $n ] = $w;
					}
				}
			}
		};
		foreach ( get_terms( array( 'taxonomy' => array( 'post_tag', 'category' ), 'hide_empty' => true, 'number' => 500 ) ) as $t ) {
			$add( $t->name );
		}
		$ids = get_posts( array( 'post_type' => meeha_search_post_types(), 'post_status' => 'publish', 'has_password' => false, 'posts_per_page' => 300, 'fields' => 'ids', 'no_found_rows' => true, 'suppress_filters' => true ) );
		_prime_post_caches( $ids, false, false );
		foreach ( $ids as $id ) {
			$add( get_the_title( $id ) );
		}
		set_transient( 'meeha_search_vocab', $vocab, 6 * HOUR_IN_SECONDS );
	}
	// Bounded work: only the first 6 words are checked, within a ~150 ms budget; results (also "no
	// suggestion") are cached per normalised query in the object cache only (no wp_options rows per query).
	$words = array_slice( preg_split( '/\s+/u', trim( (string) $q ), -1, PREG_SPLIT_NO_EMPTY ), 0, 6 );
	$ckey  = 'sug_' . md5( implode( ' ', array_map( 'meeha_search_normalize', $words ) ) );
	$hit   = wp_cache_get( $ckey, 'meeha_search' );
	if ( false !== $hit ) {
		return (string) $hit;
	}
	$stop    = microtime( true ) + 0.15;
	$changed = false;
	foreach ( $words as $i => $w ) {
		if ( microtime( true ) > $stop ) {
			break;
		}
		$n = meeha_search_normalize( $w );
		if ( '' === $n || isset( $vocab[ $n ] ) || mb_strlen( $n ) < 3 ) {
			continue;
		}
		$best = '';
		$bd   = PHP_INT_MAX;
		$max  = max( 1, (int) floor( mb_strlen( $n ) / 4 ) );
		foreach ( $vocab as $vn => $orig ) {
			if ( abs( mb_strlen( $vn ) - mb_strlen( $n ) ) > $max ) {
				continue;
			}
			$d = meeha_search_levenshtein( $n, $vn );
			if ( $d < $bd ) {
				$bd   = $d;
				$best = $orig;
			}
		}
		if ( $best && $bd <= $max ) {
			$words[ $i ] = $best;
			$changed     = true;
		}
	}
	$out = $changed ? implode( ' ', $words ) : '';
	wp_cache_set( $ckey, $out, 'meeha_search', HOUR_IN_SECONDS );
	return $out;
}
