<?php
/**
 * Bengali search normalisation — applied identically to the index and to queries.
 *
 *  1. HTML entities decoded; Unicode NFC when ext-intl is available.
 *  2. Nukta letters unified: precomposed ড় ঢ় য় (U+09DC/09DD/09DF) → base + ় (what NFC produces — they are
 *     composition exclusions), so both encodings match.
 *  3. ZWJ / ZWNJ / ZWSP / word joiner / BOM / soft hyphen removed.
 *  4. ৎ → ত্ (khanda-ta written as ত + ্ + ZWJ in older text).
 *  5. ঙ্গ/ঙ্ক/ঙ্খ/ঙ্ঘ → ংগ/ংক/ংখ/ংঘ (ডেঙ্গু = ডেংগু).
 *  6. Word-final ী → ি (2012 spelling reform: সরকারী = সরকারি, জানুয়ারী = জানুয়ারি).
 *  7. Bengali digits → ASCII digits; Latin lower-cased; punctuation → space.
 *  8. Spelling-variant map (whole word / word prefix): ডেঙ্গি → ডেঙ্গু, ইসরায়েল → ইসরাইল, … (filter `meeha_search_variants`).
 * Queries are additionally split into ≤ 6 terms, stop-words dropped and common suffixes stripped
 * (সরকারের → সরকার, ঢাকায় → ঢাকা) — LIKE matching is substring-based, so stems only add recall.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

/**
 * Spelling variants (surface form → canonical), written in ordinary spelling.
 *
 * @return array<string,string>
 */
function meeha_search_variants_raw() {
	return (array) apply_filters(
		'meeha_search_variants',
		array(
			'ডেঙ্গি'      => 'ডেঙ্গু',
			'ডেঙ্গী'      => 'ডেঙ্গু',
			'ডেংগি'      => 'ডেঙ্গু',
			'ইসরায়েল'    => 'ইসরাইল',
			'ইসরাঈল'     => 'ইসরাইল',
			'ইজরায়েল'    => 'ইসরাইল',
			'বাঙলাদেশ'   => 'বাংলাদেশ',
			'ইউক্রেইন'   => 'ইউক্রেন',
			'মোহাম্মদ'    => 'মুহাম্মদ',
			'মুহাম্মাদ'    => 'মুহাম্মদ',
			'মোহাম্মাদ'   => 'মুহাম্মদ',
			'কোরআন'     => 'কুরআন',
			'কোরান'      => 'কুরআন',
			'ইদ'         => 'ঈদ',
			'ইদুল'       => 'ঈদুল',
			'রমজান'      => 'রমযান',
			'হজ্জ'        => 'হজ',
			'নির্বাচণ'     => 'নির্বাচন',
			'প্রধানমন্ত্রি' => 'প্রধানমন্ত্রী',
			'দূর্নীতি'     => 'দুর্নীতি',
			'করোণা'      => 'করোনা',
		)
	);
}

/**
 * Character-level normalisation (steps 1–7, no variant map).
 *
 * @param string $s Text.
 * @return string
 */
function meeha_search_normalize_chars( $s ) {
	$s = html_entity_decode( (string) $s, ENT_QUOTES, 'UTF-8' );
	if ( class_exists( 'Normalizer' ) ) {
		$n = Normalizer::normalize( $s, Normalizer::FORM_C );
		if ( is_string( $n ) ) {
			$s = $n;
		}
	}
	$s = strtr(
		$s,
		array(
			"\u{09DC}" => "\u{09A1}\u{09BC}",
			"\u{09DD}" => "\u{09A2}\u{09BC}",
			"\u{09DF}" => "\u{09AF}\u{09BC}",
		)
	);
	$s = (string) preg_replace( '/[\x{200B}-\x{200D}\x{2060}\x{FEFF}\x{00AD}]/u', '', $s );
	$s = str_replace( "\u{09CE}", "\u{09A4}\u{09CD}", $s );
	$s = str_replace(
		array( 'ঙ্গ', 'ঙ্ক', 'ঙ্খ', 'ঙ্ঘ' ),
		array( 'ংগ', 'ংক', 'ংখ', 'ংঘ' ),
		$s
	);
	$s = strtr(
		$s,
		array(
			'০' => '0',
			'১' => '1',
			'২' => '2',
			'৩' => '3',
			'৪' => '4',
			'৫' => '5',
			'৬' => '6',
			'৭' => '7',
			'৮' => '8',
			'৯' => '9',
		)
	);
	$s = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
	$s = (string) preg_replace( '/[^\p{L}\p{M}\p{N}]+/u', ' ', $s );
	$s = (string) preg_replace( '/ী(?=\s|$)/u', 'ি', $s );
	return trim( (string) preg_replace( '/\s+/u', ' ', $s ) );
}

/**
 * Normalised variant map (longest keys first).
 *
 * @return array<string,string>
 */
function meeha_search_variants() {
	static $map = null;
	if ( null === $map ) {
		$map = array();
		foreach ( meeha_search_variants_raw() as $from => $to ) {
			$f = meeha_search_normalize_chars( $from );
			$t = meeha_search_normalize_chars( $to );
			if ( '' !== $f && '' !== $t && $f !== $t ) {
				$map[ $f ] = $t;
			}
		}
		uksort(
			$map,
			function ( $a, $b ) {
				return mb_strlen( $b ) - mb_strlen( $a );
			}
		);
	}
	return $map;
}

/**
 * Apply the variant map to one normalised word (whole word or word prefix).
 *
 * @param string $w Word.
 * @return string
 */
function meeha_search_map_word( $w ) {
	foreach ( meeha_search_variants() as $from => $to ) {
		if ( $w === $from ) {
			return $to;
		}
		if ( mb_strlen( $from ) >= 3 && 0 === strpos( $w, $from ) ) {
			return $to . substr( $w, strlen( $from ) );
		}
	}
	return $w;
}

/**
 * Full normalisation (index + queries).
 *
 * @param string $s Text.
 * @return string
 */
function meeha_search_normalize( $s ) {
	$s = meeha_search_normalize_chars( $s );
	if ( '' === $s ) {
		return '';
	}
	return implode( ' ', array_map( 'meeha_search_map_word', explode( ' ', $s ) ) );
}

/**
 * Stem a normalised query word (suffix strip; result keeps ≥ 3 code points).
 *
 * @param string $w Word.
 * @return string
 */
function meeha_search_stem( $w ) {
	static $suffixes = null;
	if ( null === $suffixes ) {
		$suffixes = array();
		foreach ( array( 'গুলোর', 'গুলোতে', 'গুলো', 'গুলি', 'দের', 'য়ের', 'য়ে', 'ের', 'কে', 'তে', 'টির', 'টি', 'টা', 'য়' ) as $sx ) {
			$suffixes[] = meeha_search_normalize_chars( $sx );
		}
		usort(
			$suffixes,
			function ( $a, $b ) {
				return mb_strlen( $b ) - mb_strlen( $a );
			}
		);
	}
	if ( preg_match( '/^[a-z0-9]+$/', $w ) ) {
		return $w;
	}
	foreach ( $suffixes as $sx ) {
		$len = mb_strlen( $sx );
		if ( mb_strlen( $w ) - $len >= 3 && mb_substr( $w, -$len ) === $sx ) {
			return mb_substr( $w, 0, mb_strlen( $w ) - $len );
		}
	}
	return $w;
}

/**
 * Query → search terms (normalised, stemmed, unique, ≤ 6).
 *
 * @param string $q Raw query.
 * @return string[]
 */
function meeha_search_terms( $q ) {
	$norm = meeha_search_normalize( wp_unslash( (string) $q ) );
	if ( '' === $norm ) {
		return array();
	}
	$stop  = array( 'ও', 'এবং', 'কি', 'কী', 'না', 'এ', 'এই', 'সে', 'যে', 'তার', 'থেকে', 'জন্য', 'নিয়ে', 'the', 'a', 'an', 'of', 'in', 'and', 'to', 'for' );
	$stop  = array_map( 'meeha_search_normalize_chars', $stop );
	$words = array_values( array_filter( explode( ' ', $norm ), 'strlen' ) );
	$keep  = array_values( array_diff( $words, $stop ) );
	$words = $keep ? $keep : $words;
	$out   = array();
	foreach ( $words as $w ) {
		if ( mb_strlen( $w ) < 2 && ! ctype_digit( $w ) ) {
			continue;
		}
		$t = meeha_search_stem( $w );
		if ( ! in_array( $t, $out, true ) ) {
			$out[] = $t;
		}
		if ( count( $out ) >= 6 ) {
			break;
		}
	}
	return $out;
}

/**
 * Alternative spellings of a raw query word that were searched too (for the summary line).
 *
 * @param string $word Raw word.
 * @return string[]
 */
function meeha_search_variant_forms( $word ) {
	$word = trim( (string) $word );
	$alts = array();
	if ( false !== strpos( $word, 'ঙ্গ' ) ) {
		$alts[] = str_replace( 'ঙ্গ', 'ংগ', $word );
	} elseif ( false !== strpos( $word, 'ংগ' ) ) {
		$alts[] = str_replace( 'ংগ', 'ঙ্গ', $word );
	}
	if ( preg_match( '/ী$/u', $word ) ) {
		$alts[] = preg_replace( '/ী$/u', 'ি', $word );
	} elseif ( preg_match( '/ি$/u', $word ) && mb_strlen( $word ) > 2 ) {
		$alts[] = preg_replace( '/ি$/u', 'ী', $word );
	}
	$canon = meeha_search_normalize( $word );
	foreach ( meeha_search_variants_raw() as $from => $to ) {
		if ( meeha_search_normalize( $to ) === $canon || meeha_search_normalize( $from ) === $canon ) {
			$alts[] = $from;
			$alts[] = $to;
		}
	}
	$alts = array_values( array_unique( array_filter( $alts ) ) );
	return array_values(
		array_filter(
			$alts,
			function ( $a ) use ( $word ) {
				return $a !== $word;
			}
		)
	);
}

/**
 * Regex (PCRE, /u) that finds a normalised term in ORIGINAL text, tolerant to the same variations the
 * normaliser removes; extended to the end of the word so the whole word gets marked.
 *
 * @param string $term Normalised term.
 * @return string Pattern body (without delimiters).
 */
function meeha_search_term_regex( $term ) {
	$zw    = '[\x{200B}-\x{200D}\x{00AD}]*';
	$chars = preg_split( '//u', (string) $term, -1, PREG_SPLIT_NO_EMPTY );
	$bn    = array( '০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯' );
	$parts = array();
	$n     = count( $chars );
	for ( $i = 0; $i < $n; $i++ ) {
		$c    = $chars[ $i ];
		$next = isset( $chars[ $i + 1 ] ) ? $chars[ $i + 1 ] : '';
		if ( "\u{09BC}" === $next && in_array( $c, array( "\u{09A1}", "\u{09A2}", "\u{09AF}" ), true ) ) {
			$pre     = array( "\u{09A1}" => "\u{09DC}", "\u{09A2}" => "\u{09DD}", "\u{09AF}" => "\u{09DF}" );
			$parts[] = '(?:' . preg_quote( $c . "\u{09BC}", '~' ) . '|' . preg_quote( $pre[ $c ], '~' ) . ')';
			$i++;
			continue;
		}
		if ( 'ত' === $c && '্' === $next ) {
			$parts[] = '(?:ৎ|ত্\x{200D}?)';
			$i++;
			continue;
		}
		if ( 'ং' === $c ) {
			$parts[] = '(?:ং|ঙ্)';
			continue;
		}
		if ( 'ি' === $c && $i === $n - 1 ) {
			$parts[] = '[িী]';
			continue;
		}
		if ( ctype_digit( $c ) ) {
			$parts[] = '[' . $c . $bn[ (int) $c ] . ']';
			continue;
		}
		$parts[] = preg_quote( $c, '~' );
	}
	return implode( $zw, $parts ) . '[\p{L}\p{M}]*';
}

/**
 * Combined highlight regex for terms (+ surface variants of canonical terms), longest first.
 *
 * @param string[] $terms Normalised terms.
 * @return string Full pattern with delimiters, or '' when no terms.
 */
function meeha_search_highlight_regex( $terms ) {
	$alts = array();
	foreach ( (array) $terms as $t ) {
		if ( '' === $t ) {
			continue;
		}
		$alts[] = $t;
		foreach ( meeha_search_variants() as $from => $to ) {
			if ( 0 === strpos( $to, $t ) || 0 === strpos( $t, $to ) ) {
				$alts[] = $from;
			}
		}
	}
	$alts = array_values( array_unique( $alts ) );
	if ( ! $alts ) {
		return '';
	}
	usort(
		$alts,
		function ( $a, $b ) {
			return strlen( $b ) - strlen( $a );
		}
	);
	return '~' . implode( '|', array_map( 'meeha_search_term_regex', $alts ) ) . '~iu';
}

/**
 * Escape a text and wrap matches in <mark> (everything is escaped BEFORE marking is inserted).
 *
 * @param string $text  Plain text (unescaped).
 * @param string $regex Highlight regex ('' = none).
 * @param int    $max   Max marks (0 = all).
 * @return string Safe HTML.
 */
function meeha_search_mark( $text, $regex, $max = 0 ) {
	$text = (string) $text;
	if ( '' === $regex || '' === $text || ! preg_match_all( $regex, $text, $m, PREG_OFFSET_CAPTURE ) ) {
		return esc_html( $text );
	}
	$out  = '';
	$pos  = 0;
	$done = 0;
	foreach ( $m[0] as $hit ) {
		list( $str, $off ) = $hit;
		if ( '' === $str || $off < $pos ) {
			continue;
		}
		$out .= esc_html( substr( $text, $pos, $off - $pos ) ) . '<mark>' . esc_html( $str ) . '</mark>';
		$pos  = $off + strlen( $str );
		if ( $max && ++$done >= $max ) {
			break;
		}
	}
	return $out . esc_html( substr( $text, $pos ) );
}

/**
 * ~$len-character snippet around the first match, marked (first match only).
 *
 * @param string $text  Plain text.
 * @param string $regex Highlight regex.
 * @param int    $len   Approx. length in characters.
 * @return string Safe HTML.
 */
function meeha_search_snippet( $text, $regex, $len = 160 ) {
	$text = trim( (string) preg_replace( '/\s+/u', ' ', (string) $text ) );
	if ( '' === $text ) {
		return '';
	}
	$start = 0;
	if ( '' !== $regex && preg_match( $regex, $text, $m, PREG_OFFSET_CAPTURE ) ) {
		$char_off = mb_strlen( substr( $text, 0, $m[0][1] ) );
		$start    = max( 0, $char_off - (int) floor( $len / 3 ) );
		if ( $start > 0 ) {
			// Begin at a word boundary.
			$sp = mb_strpos( $text, ' ', $start );
			if ( false !== $sp && $sp < $char_off ) {
				$start = $sp + 1;
			}
		}
	}
	$total = mb_strlen( $text );
	$piece = mb_substr( $text, $start, $len );
	if ( $start + $len < $total ) {
		$sp = mb_strrpos( $piece, ' ' );
		if ( false !== $sp && $sp > $len * 0.6 ) {
			$piece = mb_substr( $piece, 0, $sp );
		}
	}
	$html = meeha_search_mark( $piece, $regex, 1 );
	return ( $start > 0 ? '…' : '' ) . $html . ( $start + mb_strlen( $piece ) < $total ? '…' : '' );
}

/**
 * Levenshtein distance on code points (Bengali-safe).
 *
 * @param string $a A.
 * @param string $b B.
 * @return int
 */
function meeha_search_levenshtein( $a, $b ) {
	$x = preg_split( '//u', $a, -1, PREG_SPLIT_NO_EMPTY );
	$y = preg_split( '//u', $b, -1, PREG_SPLIT_NO_EMPTY );
	$n = count( $x );
	$m = count( $y );
	if ( ! $n ) {
		return $m;
	}
	if ( ! $m ) {
		return $n;
	}
	$prev = range( 0, $m );
	for ( $i = 1; $i <= $n; $i++ ) {
		$cur = array( $i );
		for ( $j = 1; $j <= $m; $j++ ) {
			$cur[ $j ] = min( $prev[ $j ] + 1, $cur[ $j - 1 ] + 1, $prev[ $j - 1 ] + ( $x[ $i - 1 ] === $y[ $j - 1 ] ? 0 : 1 ) );
		}
		$prev = $cur;
	}
	return $prev[ $m ];
}
