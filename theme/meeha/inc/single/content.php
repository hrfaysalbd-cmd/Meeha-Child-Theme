<?php
/**
 * Block-aware content splitter + article processing.
 *
 * The rendered post HTML (after `the_content`) is cut into TOP-LEVEL segments by a small tag tokenizer that
 * never re-serialises markup: every byte of the original HTML is kept, and extra markup (ads, "আরও পড়ুন",
 * TOC ids) is inserted only BETWEEN top-level segments (or, for ids, into a top-level h2's own start tag).
 * So nothing can ever land inside a <blockquote>, list, <figure>, table, embed wrapper or nested block —
 * this replaces the old `explode('</p>')` approach that broke HTML (INVENTORY §8.1.5).
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

/**
 * Split HTML into top-level segments.
 *
 * @param string $html HTML.
 * @return array[] Each: html, tag (lowercase or '#text'/'#comment'), type (p|h|media|quote|list|readalso|ws|other),
 *                 level (h level), id (existing id attr of h), text (plain text, for p/h).
 */
function meeha_html_segments( $html ) {
	$html  = (string) $html;
	$len   = strlen( $html );
	$void  = array_flip( array( 'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr', 'command', 'keygen' ) );
	$raw   = array_flip( array( 'script', 'style', 'textarea', 'title', 'xmp', 'iframe', 'noscript', 'template' ) );
	$block = array_flip( array( 'address', 'article', 'aside', 'blockquote', 'details', 'div', 'dl', 'fieldset', 'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hr', 'main', 'menu', 'nav', 'ol', 'p', 'pre', 'section', 'table', 'ul' ) );
	$segs  = array();
	$stack = array();
	$start = 0;      // start offset of the current top-level segment.
	$pos   = 0;
	$cut   = function ( $from, $to ) use ( &$segs, $html ) {
		if ( $to > $from ) {
			$segs[] = substr( $html, $from, $to - $from );
		}
	};

	while ( $pos < $len ) {
		$lt = strpos( $html, '<', $pos );
		if ( false === $lt ) {
			break;
		}
		// Comments (incl. block delimiters).
		if ( 0 === substr_compare( $html, '<!--', $lt, 4 ) ) {
			$end = strpos( $html, '-->', $lt + 4 );
			$end = false === $end ? $len : $end + 3;
			if ( ! $stack ) {
				$cut( $start, $lt );
				$cut( $lt, $end );
				$start = $end;
			}
			$pos = $end;
			continue;
		}
		if ( 0 === substr_compare( $html, '<![CDATA[', $lt, 9 ) ) {
			$end = strpos( $html, ']]>', $lt );
			$pos = false === $end ? $len : $end + 3;
			continue;
		}
		if ( ! preg_match( '#\G<(/?)([a-zA-Z][a-zA-Z0-9:-]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>#', $html, $m, 0, $lt ) ) {
			$pos = $lt + 1; // a bare "<" in text.
			continue;
		}
		$tag_end = $lt + strlen( $m[0] );
		$closing = '/' === $m[1];
		$name    = strtolower( $m[2] );
		$self    = '/' === substr( rtrim( $m[3] ), -1 );

		if ( $closing ) {
			$idx = array_search( $name, array_reverse( $stack, true ), true );
			if ( false !== $idx ) {
				$stack = array_slice( $stack, 0, $idx );
				if ( ! $stack ) {
					$cut( $start, $tag_end );
					$start = $tag_end;
				}
			}
			$pos = $tag_end;
			continue;
		}

		// Opening tag. A block start implicitly closes an open <p>.
		if ( isset( $block[ $name ] ) && $stack && 'p' === end( $stack ) ) {
			array_pop( $stack );
			if ( ! $stack ) {
				$cut( $start, $lt );
				$start = $lt;
			}
		}
		if ( ! $stack ) {
			$cut( $start, $lt ); // text/whitespace before this element.
			$start = $lt;
		}
		if ( isset( $void[ $name ] ) || $self ) {
			if ( ! $stack ) {
				$cut( $start, $tag_end );
				$start = $tag_end;
			}
			$pos = $tag_end;
			continue;
		}
		if ( isset( $raw[ $name ] ) ) {
			$close = stripos( $html, '</' . $name, $tag_end );
			if ( false === $close ) {
				$close_end = $len;
			} else {
				$gt        = strpos( $html, '>', $close );
				$close_end = false === $gt ? $len : $gt + 1;
			}
			if ( ! $stack ) {
				$cut( $start, $close_end );
				$start = $close_end;
			}
			$pos = $close_end;
			continue;
		}
		$stack[] = $name;
		$pos     = $tag_end;
	}
	$cut( $start, $len );

	// Merge runs of bare text + inline elements (classic content without <p>) into one segment, so nothing
	// is ever inserted in the middle of a sentence.
	$inline = array_flip( array( 'a', 'abbr', 'b', 'bdi', 'bdo', 'br', 'cite', 'code', 'data', 'dfn', 'em', 'i', 'kbd', 'mark', 'q', 's', 'samp', 'small', 'span', 'strong', 'sub', 'sup', 'time', 'u', 'var', 'del', 'ins' ) );
	$merged = array();
	$run    = null;
	foreach ( $segs as $s ) {
		$is_inline = false;
		if ( '<' !== substr( ltrim( $s ), 0, 1 ) ) {
			$is_inline = '' !== trim( $s ) || null !== $run;
		} elseif ( preg_match( '#^\s*<([a-zA-Z][a-zA-Z0-9]*)#', $s, $mm ) && isset( $inline[ strtolower( $mm[1] ) ] ) ) {
			$is_inline = true;
		}
		if ( $is_inline ) {
			$run = null === $run ? $s : $run . $s;
			continue;
		}
		if ( null !== $run ) {
			$merged[] = $run;
			$run      = null;
		}
		$merged[] = $s;
	}
	if ( null !== $run ) {
		$merged[] = $run;
	}

	$out = array();
	foreach ( $merged as $s ) {
		$out[] = meeha_html_segment_info( $s );
	}
	return $out;
}

/**
 * Classify one top-level segment.
 *
 * @param string $s Segment HTML.
 * @return array
 */
function meeha_html_segment_info( $s ) {
	$info = array(
		'html'  => $s,
		'tag'   => '#text',
		'type'  => 'other',
		'level' => 0,
		'id'    => '',
		'text'  => '',
		'class' => '',
	);
	$trim = ltrim( $s );
	if ( '' === trim( $s ) ) {
		$info['type'] = 'ws';
		return $info;
	}
	if ( 0 === strpos( $trim, '<!--' ) ) {
		$info['tag']  = '#comment';
		$info['type'] = 'ws';
		return $info;
	}
	if ( ! preg_match( '#^<([a-zA-Z][a-zA-Z0-9:-]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>#', $trim, $m ) ) {
		$info['text'] = trim( wp_strip_all_tags( $s ) );
		$info['type'] = '' === $info['text'] ? 'ws' : 'p'; // bare text at top level reads as a paragraph.
		return $info;
	}
	$tag           = strtolower( $m[1] );
	$attrs         = $m[2];
	$info['tag']   = $tag;
	$info['class'] = preg_match( '#\bclass\s*=\s*(["\'])(.*?)\1#is', $attrs, $c ) ? ' ' . $c[2] . ' ' : '';
	if ( preg_match( '#\bid\s*=\s*(["\'])(.*?)\1#is', $attrs, $c ) ) {
		$info['id'] = $c[2];
	}
	$cls  = $info['class'];
	$text         = html_entity_decode( wp_strip_all_tags( $s ), ENT_QUOTES, 'UTF-8' );
	$text         = preg_replace( '/[\s\x{00A0}\x{200B}]+/u', ' ', $text );
	$info['text'] = trim( (string) $text );
	$text         = $info['text'];

	if ( false !== strpos( $cls, ' m-readalso' ) || false !== strpos( $cls, 'meeha-readalso' ) || false !== strpos( $cls, 'meeha-inline-related' ) ) {
		$info['type'] = 'readalso';
	} elseif ( in_array( $tag, array( 'a', 'abbr', 'b', 'bdi', 'bdo', 'br', 'cite', 'code', 'data', 'dfn', 'em', 'i', 'kbd', 'mark', 'q', 's', 'samp', 'small', 'span', 'strong', 'sub', 'sup', 'time', 'u', 'var', 'del', 'ins' ), true ) ) {
		$info['type'] = '' === $text ? 'ws' : 'p';
	} elseif ( 'p' === $tag ) {
		$has_media    = (bool) preg_match( '#<(img|iframe|video|audio|embed|object|picture)\b#i', $s );
		$info['type'] = ( '' === $text ) ? ( $has_media ? 'media' : 'ws' ) : 'p';
	} elseif ( preg_match( '/^h([1-6])$/', $tag, $h ) ) {
		$info['type']  = 'h';
		$info['level'] = (int) $h[1];
	} elseif ( in_array( $tag, array( 'figure', 'img', 'iframe', 'video', 'audio', 'picture', 'embed', 'object', 'table' ), true ) ) {
		$info['type'] = 'media';
	} elseif ( 'blockquote' === $tag || false !== strpos( $cls, 'wp-block-pullquote' ) || false !== strpos( $cls, 'wp-block-quote' ) ) {
		$info['type'] = 'quote';
	} elseif ( in_array( $tag, array( 'ul', 'ol', 'dl' ), true ) ) {
		$info['type'] = 'list';
	} elseif ( preg_match( '/\s(wp-block-(image|embed|video|gallery|audio|cover|media-text|table)|wp-caption|gallery|m-figure|m-pull|m-method|m-doc|m-timeline|m-ad)(\s|$)/', $cls ) ) {
		$info['type'] = 'media';
	}
	return $info;
}

/**
 * Rebuild HTML from segments.
 *
 * @param array $segs Segments.
 * @return string
 */
function meeha_html_join( $segs ) {
	$out = '';
	foreach ( $segs as $s ) {
		$out .= $s['html'];
	}
	return $out;
}

/**
 * Plan where to insert ads and the read-also card.
 *
 * Rules (DESIGN-SPEC §10.1 item 10–11):
 *  - only between top-level segments, directly after a real paragraph;
 *  - never adjacent to a figure/media, pull quote/blockquote, heading, list or another insertion: the segment
 *    right after the boundary must be a paragraph (shifts forward to the next valid boundary);
 *  - ad 1 after ¶N1 (opinion: never before ¶4), needs ≥ 1 paragraph after it; ad 2 after ¶N2, needs ≥ 3 paragraphs
 *    after it and ≥ 3 paragraphs after ad 1; max 2 ads; none on literature;
 *  - read-also after ¶N3 (needs ≥ 1 paragraph after), never at the same boundary as an ad.
 *
 * @param array $segs Segments.
 * @param array $args ad1, ad2, readalso (paragraph numbers, 0 = off), variant.
 * @return array boundary index (segment index after which to insert) => list of keys ('ad1','ad2','readalso').
 */
function meeha_single_plan_inserts( $segs, $args ) {
	$args = wp_parse_args(
		$args,
		array(
			'ad1'      => 3,
			'ad2'      => 8,
			'readalso' => 5,
			'variant'  => 'news',
		)
	);
	// Index of each paragraph segment + "next non-ws segment" lookup.
	$paras = array();
	$n     = count( $segs );
	foreach ( $segs as $i => $s ) {
		if ( 'p' === $s['type'] ) {
			$paras[] = $i;
		}
	}
	$total = count( $paras );
	$next  = function ( $i ) use ( $segs, $n ) {
		for ( $j = $i + 1; $j < $n; $j++ ) {
			if ( 'ws' !== $segs[ $j ]['type'] ) {
				return $j;
			}
		}
		return -1;
	};
	$taken = array();
	$plan  = array();
	/*
	 * Find the first paragraph k (1-based) ≥ $from whose following segment is a paragraph and after which at
	 * least $after paragraphs remain. Returns [k, segment index] or null.
	 */
	$find = function ( $from, $after ) use ( $paras, $total, $segs, $next, &$taken ) {
		for ( $k = max( 1, (int) $from ); $k <= $total; $k++ ) {
			$i = $paras[ $k - 1 ];
			if ( isset( $taken[ $i ] ) || ( $total - $k ) < $after ) {
				continue;
			}
			$j = $next( $i );
			if ( $j < 0 || 'p' !== $segs[ $j ]['type'] ) {
				continue;
			}
			// Previous insertion must not be directly before this paragraph's boundary either.
			if ( isset( $taken[ $i - 1 ] ) ) {
				continue;
			}
			return array( $k, $i );
		}
		return null;
	};

	$ad1_k = 0;
	if ( 'literature' !== $args['variant'] && (int) $args['ad1'] > 0 ) {
		$from = (int) $args['ad1'];
		if ( 'opinion' === $args['variant'] ) {
			$from = max( 4, $from );
		}
		$hit = $find( $from, 1 );
		if ( $hit ) {
			$ad1_k              = $hit[0];
			$taken[ $hit[1] ]   = true;
			$plan[ $hit[1] ][]  = 'ad1';
		}
	}
	if ( 'literature' !== $args['variant'] && (int) $args['ad2'] > 0 ) {
		$from = max( (int) $args['ad2'], $ad1_k ? $ad1_k + 3 : 0 );
		if ( 'opinion' === $args['variant'] ) {
			$from = max( 4, $from );
		}
		$hit = $find( $from, 3 );
		if ( $hit ) {
			$taken[ $hit[1] ]  = true;
			$plan[ $hit[1] ][] = 'ad2';
		}
	}
	if ( (int) $args['readalso'] > 0 ) {
		$manual = false;
		foreach ( $segs as $s ) {
			if ( 'readalso' === $s['type'] ) {
				$manual = true;
				break;
			}
		}
		if ( ! $manual ) {
			$hit = $find( (int) $args['readalso'], 1 );
			if ( $hit ) {
				$taken[ $hit[1] ]  = true;
				$plan[ $hit[1] ][] = 'readalso';
			}
		}
	}
	ksort( $plan );
	return $plan;
}

/**
 * Give every top-level h2 an id (keeps existing ids) and collect the TOC.
 *
 * @param array $segs Segments (modified).
 * @return array TOC items [id, text].
 */
function meeha_single_toc_ids( &$segs ) {
	$toc  = array();
	$used = array();
	$n    = 0;
	foreach ( $segs as $i => $s ) {
		if ( 'h' !== $s['type'] || 2 !== $s['level'] || '' === $s['text'] ) {
			continue;
		}
		$n++;
		$id = $s['id'];
		if ( '' === $id ) {
			$id = 'm-sec-' . $n;
			while ( isset( $used[ $id ] ) ) {
				$id .= '-x';
			}
			// Inject into this segment's own start tag only.
			$segs[ $i ]['html'] = preg_replace( '#^(\s*<h2)\b#i', '$1 id="' . esc_attr( $id ) . '"', $s['html'], 1 );
			$segs[ $i ]['id']   = $id;
		}
		$used[ $id ] = true;
		$toc[]       = array( $id, $s['text'] );
	}
	return $toc;
}

/**
 * Process an article body: TOC ids, in-article ads, read-also card.
 *
 * @param string  $html    Rendered content (after the_content).
 * @param WP_Post $post    Post.
 * @param array   $args    variant, readalso_post (WP_Post|null), ads (bool, default true).
 * @return array html, toc, inserted (keys).
 */
function meeha_single_process_content( $html, $post, $args = array() ) {
	$args    = wp_parse_args(
		$args,
		array(
			'variant'       => 'news',
			'readalso_post' => null,
			'ads'           => true,
		)
	);
	$segs    = meeha_html_segments( $html );
	$toc     = meeha_single_toc_ids( $segs );
	$plan    = meeha_single_plan_inserts(
		$segs,
		array(
			'ad1'      => $args['ads'] ? (int) meeha_single_opt( 'ad_para_1', 3 ) : 0,
			'ad2'      => $args['ads'] ? (int) meeha_single_opt( 'ad_para_2', 8 ) : 0,
			'readalso' => $args['readalso_post'] ? (int) meeha_single_opt( 'readalso_para', 5 ) : 0,
			'variant'  => $args['variant'],
		)
	);
	$done    = array();
	$snippet = array();
	foreach ( $plan as $keys ) {
		foreach ( $keys as $k ) {
			if ( isset( $snippet[ $k ] ) ) {
				continue;
			}
			if ( 'ad1' === $k ) {
				$snippet[ $k ] = function_exists( 'meeha_ad' ) ? meeha_ad( 'article_1' ) : '';
			} elseif ( 'ad2' === $k ) {
				$snippet[ $k ] = function_exists( 'meeha_ad' ) ? meeha_ad( 'article_2' ) : '';
			} elseif ( 'readalso' === $k ) {
				$snippet[ $k ] = meeha_single_readalso_html( $args['readalso_post'] );
			}
		}
	}
	$out = '';
	foreach ( $segs as $i => $s ) {
		$out .= $s['html'];
		if ( isset( $plan[ $i ] ) ) {
			// A paragraph left open by the editor (`<p>text` without `</p>`) is closed explicitly before the insert,
			// so the inserted block can never end up inside it (same DOM as HTML5's implicit close).
			if ( 'p' === $s['tag'] && ! preg_match( '#</p>\s*$#i', $s['html'] ) ) {
				$out .= '</p>';
			}
			foreach ( $plan[ $i ] as $k ) {
				if ( ! empty( $snippet[ $k ] ) ) {
					$out   .= "\n" . $snippet[ $k ] . "\n";
					$done[] = $k;
				}
			}
		}
	}
	return array(
		'html'     => $out,
		'toc'      => $toc,
		'inserted' => $done,
	);
}

/**
 * Inline "আরও পড়ুন" card markup.
 *
 * @param WP_Post|null $p Post.
 * @return string
 */
function meeha_single_readalso_html( $p ) {
	$p = $p ? get_post( $p ) : null;
	if ( ! $p ) {
		return '';
	}
	$img = meeha_post_img( $p, 'thumb' );
	return '<aside class="m-readalso" aria-label="আরও পড়ুন"><div><span class="m-readalso__label">আরও পড়ুন</span><a href="' . esc_url( get_permalink( $p ) ) . '">' . esc_html( get_the_title( $p ) ) . '</a></div>'
		. ( $img ? '<div class="m-media">' . $img . '</div>' : '' ) . '</aside>';
}

/**
 * Turn poem content into stanza paragraphs: one stanza per top-level paragraph; `<br>` line breaks preserved;
 * a verse block (`<pre class="wp-block-verse">`) or a paragraph with blank-line gaps is split into stanzas.
 *
 * @param string $html Rendered content.
 * @return string
 */
function meeha_lit_poem_html( $html ) {
	$out = '';
	foreach ( meeha_html_segments( $html ) as $s ) {
		if ( 'p' === $s['type'] && 'p' === $s['tag'] ) {
			$inner = preg_replace( '#^\s*<p\b[^>]*>|</p>\s*$#i', '', $s['html'] );
			$out  .= meeha_lit_stanzas_from( $inner );
		} elseif ( 'pre' === $s['tag'] && false !== strpos( $s['class'], 'wp-block-verse' ) ) {
			$inner = preg_replace( '#^\s*<pre\b[^>]*>|</pre>\s*$#i', '', $s['html'] );
			$out  .= meeha_lit_stanzas_from( $inner );
		} else {
			$out .= $s['html'];
		}
	}
	return $out;
}

/**
 * Stanza paragraphs from inline HTML (lines separated by <br> or newlines; stanzas by blank lines).
 *
 * @param string $inner Inline HTML.
 * @return string
 */
function meeha_lit_stanzas_from( $inner ) {
	$inner = str_replace( array( "\r\n", "\r" ), "\n", (string) $inner );
	$inner = preg_replace( '#<br\s*/?>\s*\n?#i', "\n", $inner );
	$out   = '';
	foreach ( preg_split( "/\n[ \t]*\n+/", trim( $inner ) ) as $stanza ) {
		$lines = array_map( 'trim', explode( "\n", trim( $stanza ) ) );
		$lines = array_filter(
			$lines,
			function ( $l ) {
				return '' !== $l;
			}
		);
		if ( $lines ) {
			$out .= '<p class="m-stanza">' . implode( '<br>', $lines ) . "</p>\n";
		}
	}
	return $out;
}
