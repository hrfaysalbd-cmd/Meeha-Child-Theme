<?php
/**
 * Meeha asset build (PHP CLI, no dependencies).
 *
 *   php tools/build.php            build everything into assets/dist
 *   php tools/build.php --check    exit 1 when dist is stale (CI)
 *
 * Reads tools/bundles.d/*.json (A..E, merged in file order; arrays concatenated, duplicates dropped; keys starting with
 * "_" are comments) and writes:
 *   assets/dist/<bundle>.min.css      concatenated + safely minified CSS (comments/whitespace only; strings & url() intact)
 *   assets/dist/<bundle>.min.js       concatenated JS; comments and indentation stripped by a string/regex/template-aware
 *                                     scanner (newlines kept, so ASI is never affected)
 *   assets/dist/critical-<bundle>.min.css   core CSS + that bundle's "critical" files (only for bundles with "critical")
 *   assets/dist/critical-default.min.css    core CSS only
 *   assets/dist/manifest.json         what the theme reads at runtime
 *   assets/img/sprite.svg, assets/img/sprite-inline.svg   from tools/sprite-src.svg
 *
 * @package meeha
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

$root  = dirname( __DIR__ );
$dist  = $root . '/assets/dist';
$check = in_array( '--check', $argv, true );

if ( ! is_dir( $dist ) && ! mkdir( $dist, 0755, true ) ) {
	fwrite( STDERR, "cannot create $dist\n" );
	exit( 1 );
}

/* ---------------------------------------------------------------- bundles */
$bundles = array();
$files   = glob( $root . '/tools/bundles.d/*.json' );
sort( $files );
foreach ( $files as $f ) {
	$j = json_decode( (string) file_get_contents( $f ), true );
	if ( ! is_array( $j ) ) {
		fwrite( STDERR, 'invalid JSON: ' . basename( $f ) . ' (' . json_last_error_msg() . ")\n" );
		exit( 1 );
	}
	foreach ( $j as $name => $def ) {
		if ( '_' === substr( (string) $name, 0, 1 ) || ! is_array( $def ) ) {
			continue;
		}
		$name = strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $name ) );
		foreach ( array( 'css', 'js', 'critical', 'deps' ) as $k ) {
			$prev                    = isset( $bundles[ $name ][ $k ] ) ? $bundles[ $name ][ $k ] : array();
			$bundles[ $name ][ $k ] = array_values( array_unique( array_merge( $prev, isset( $def[ $k ] ) ? (array) $def[ $k ] : array() ) ) );
		}
		$bundles[ $name ]['from'][] = basename( $f );
	}
}
if ( empty( $bundles['core'] ) ) {
	fwrite( STDERR, "bundle `core` missing (tools/bundles.d/A.json)\n" );
	exit( 1 );
}

/* ---------------------------------------------------------------- CSS */

/**
 * Read a source CSS file and rebase relative url()s from its directory to assets/dist.
 */
function meeha_build_read_css( $root, $rel ) {
	$path = $root . '/' . ltrim( $rel, '/' );
	if ( ! is_readable( $path ) ) {
		fwrite( STDERR, "  ! missing CSS: $rel\n" );
		return '';
	}
	$css    = (string) file_get_contents( $path );
	$srcdir = dirname( ltrim( $rel, '/' ) );
	return preg_replace_callback(
		'#url\(\s*(["\']?)([^"\')]+)\1\s*\)#',
		function ( $m ) use ( $srcdir ) {
			$u = $m[2];
			if ( preg_match( '#^(data:|https?:|//|/|\#)#i', $u ) ) {
				return $m[0];
			}
			$abs   = meeha_build_normalize( $srcdir . '/' . $u );   // relative to theme root.
			$fromd = 'assets/dist';
			$rel   = meeha_build_relative( $fromd, $abs );
			return 'url(' . $m[1] . $rel . $m[1] . ')';
		},
		$css
	);
}

function meeha_build_normalize( $p ) {
	$out = array();
	foreach ( explode( '/', $p ) as $seg ) {
		if ( '' === $seg || '.' === $seg ) {
			continue;
		}
		if ( '..' === $seg ) {
			array_pop( $out );
		} else {
			$out[] = $seg;
		}
	}
	return implode( '/', $out );
}

function meeha_build_relative( $from_dir, $to ) {
	$a = explode( '/', meeha_build_normalize( $from_dir ) );
	$b = explode( '/', $to );
	while ( $a && $b && $a[0] === $b[0] ) {
		array_shift( $a );
		array_shift( $b );
	}
	return str_repeat( '../', count( $a ) ) . implode( '/', $b );
}

/**
 * Safe CSS minifier: drops comments, collapses whitespace, trims around { } ; , and after ':' — never inside strings.
 */
function meeha_build_min_css( $css ) {
	$out = '';
	$len = strlen( $css );
	$i   = 0;
	$buf = '';
	$flush = function ( $chunk ) {
		$chunk = preg_replace( '/\s+/', ' ', $chunk );
		$chunk = preg_replace( '/\s*([{};,])\s*/', '$1', $chunk );
		$chunk = preg_replace( '/:\s+/', ':', $chunk );
		$chunk = preg_replace( '/\s+!important/', '!important', $chunk );
		return $chunk;
	};
	while ( $i < $len ) {
		$c = $css[ $i ];
		if ( '/' === $c && $i + 1 < $len && '*' === $css[ $i + 1 ] ) {
			$end = strpos( $css, '*/', $i + 2 );
			$i   = false === $end ? $len : $end + 2;
			$buf .= ' ';
			continue;
		}
		if ( '"' === $c || "'" === $c ) {
			$out .= $flush( $buf );
			$buf  = '';
			$j    = $i + 1;
			while ( $j < $len && $css[ $j ] !== $c ) {
				if ( '\\' === $css[ $j ] ) {
					$j++;
				}
				$j++;
			}
			$out .= substr( $css, $i, $j - $i + 1 );
			$i    = $j + 1;
			continue;
		}
		if ( 'u' === $c && 0 === substr_compare( $css, 'url(', $i, 4, true ) ) { // unquoted url(...) verbatim.
			$out .= $flush( $buf );
			$buf  = '';
			$end  = strpos( $css, ')', $i );
			$end  = false === $end ? $len - 1 : $end;
			$out .= substr( $css, $i, $end - $i + 1 );
			$i    = $end + 1;
			continue;
		}
		$buf .= $c;
		$i++;
	}
	$out .= $flush( $buf );
	$out  = str_replace( ';}', '}', $out );
	return trim( $out );
}

/* ---------------------------------------------------------------- JS */

/**
 * Conservative JS "minifier": removes comments and leading indentation / blank lines only.
 * Tracks '…', "…", `…${…}…` (nested) and regex literals so their contents are never touched.
 */
function meeha_build_min_js( $js ) {
	$out   = '';
	$len   = strlen( $js );
	$i     = 0;
	$last  = '';      // last significant (non-space) char emitted in code state.
	$lastw = '';      // last identifier word emitted.
	$bol   = true;    // at beginning of line (code state).
	$tpl   = array(); // template literal brace-depth stack.
	$depth = 0;
	while ( $i < $len ) {
		$c = $js[ $i ];
		$n = $i + 1 < $len ? $js[ $i + 1 ] : '';
		// Inside template literal body?
		if ( $tpl && end( $tpl ) === -1 ) {
			if ( '\\' === $c ) {
				$out .= $c . $n;
				$i   += 2;
				continue;
			}
			if ( '`' === $c ) {
				array_pop( $tpl );
				$out  .= $c;
				$last  = '`';
				$i++;
				continue;
			}
			if ( '$' === $c && '{' === $n ) {
				$tpl[ count( $tpl ) - 1 ] = $depth; // remember brace depth of this ${.
				$out                     .= '${';
				$depth++;
				$last = '{';
				$i   += 2;
				continue;
			}
			$out .= $c;
			$i++;
			continue;
		}
		if ( $bol && ( ' ' === $c || "\t" === $c ) ) {
			$i++;
			continue;
		}
		if ( "\n" === $c || "\r" === $c ) {
			if ( ! $bol ) {
				$out .= "\n";
			}
			$bol = true;
			$i++;
			continue;
		}
		if ( '/' === $c && '/' === $n ) {
			$e = strpos( $js, "\n", $i );
			$i = false === $e ? $len : $e;
			$out = rtrim( $out, " \t" );
			continue;
		}
		if ( '/' === $c && '*' === $n ) {
			$e = strpos( $js, '*/', $i + 2 );
			$i = false === $e ? $len : $e + 2;
			continue;
		}
		$bol = false;
		if ( '"' === $c || "'" === $c ) {
			$j = $i + 1;
			while ( $j < $len && $js[ $j ] !== $c && "\n" !== $js[ $j ] ) {
				if ( '\\' === $js[ $j ] ) {
					$j++;
				}
				$j++;
			}
			$out .= substr( $js, $i, $j - $i + 1 );
			$i    = $j + 1;
			$last = $c;
			continue;
		}
		if ( '`' === $c ) {
			$tpl[] = -1;
			$out  .= $c;
			$i++;
			continue;
		}
		if ( '/' === $c ) {
			$regex_ok = '' === $last || false !== strpos( '(,=:[!&|?{};+-*%<>~^', $last ) || in_array( $lastw, array( 'return', 'typeof', 'case', 'do', 'else', 'in', 'of', 'new', 'delete', 'void', 'throw', 'instanceof', 'yield', 'await' ), true );
			if ( $regex_ok ) {
				$j     = $i + 1;
				$class = false;
				while ( $j < $len && "\n" !== $js[ $j ] ) {
					$d = $js[ $j ];
					if ( '\\' === $d ) {
						$j += 2;
						continue;
					}
					if ( '[' === $d ) {
						$class = true;
					} elseif ( ']' === $d ) {
						$class = false;
					} elseif ( '/' === $d && ! $class ) {
						break;
					}
					$j++;
				}
				$j++;
				while ( $j < $len && ctype_alpha( $js[ $j ] ) ) { // flags.
					$j++;
				}
				$out  .= substr( $js, $i, $j - $i );
				$i     = $j;
				$last  = '/';
				$lastw = '';
				continue;
			}
		}
		if ( '{' === $c ) {
			$depth++;
		} elseif ( '}' === $c ) {
			$depth--;
			if ( $tpl && end( $tpl ) === $depth ) { // closing a ${ … } → back into the template body.
				$tpl[ count( $tpl ) - 1 ] = -1;
				$out                     .= '}';
				$i++;
				continue;
			}
		}
		if ( ctype_alnum( $c ) || '_' === $c || '$' === $c ) {
			$j = $i;
			while ( $j < $len && ( ctype_alnum( $js[ $j ] ) || '_' === $js[ $j ] || '$' === $js[ $j ] ) ) {
				$j++;
			}
			$lastw = substr( $js, $i, $j - $i );
			$out  .= $lastw;
			$last  = substr( $lastw, -1 );
			$i     = $j;
			continue;
		}
		$out .= $c;
		if ( ' ' !== $c && "\t" !== $c ) {
			$last  = $c;
			$lastw = '';
		}
		$i++;
	}
	return trim( preg_replace( "/[ \t]+\n/", "\n", $out ) ) . "\n";
}

/* ---------------------------------------------------------------- write */
$manifest = array(
	'generated' => gmdate( 'c' ),
	'bundles'   => array(),
	'critical'  => array(),
);
$written  = array();
$report   = array();

$write = function ( $file, $content ) use ( $dist, $check, &$written ) {
	$path = $dist . '/' . $file;
	$written[ $file ] = true;
	if ( $check ) {
		return is_readable( $path ) && file_get_contents( $path ) === $content;
	}
	file_put_contents( $path, $content );
	return true;
};
$stale = false;

$core_css_src = $bundles['core']['css'];

foreach ( $bundles as $name => $def ) {
	$entry = array(
		'css'      => $def['css'],
		'js'       => $def['js'],
		'critical' => $def['critical'],
		'deps'     => $def['deps'],
	);
	if ( $def['css'] ) {
		$css = '';
		foreach ( $def['css'] as $rel ) {
			$css .= meeha_build_read_css( $root, $rel ) . "\n";
		}
		$min                = meeha_build_min_css( $css );
		$entry['css_file']  = $name . '.min.css';
		$entry['css_ver']   = substr( md5( $min ), 0, 10 );
		$entry['css_bytes'] = strlen( $min );
		$stale              = ! $write( $entry['css_file'], $min ) || $stale;
		$report[]           = sprintf( '%-24s css %7d B  gz %6d B', $name, strlen( $min ), strlen( gzencode( $min, 9 ) ) );
	}
	if ( $def['js'] ) {
		$js = '';
		foreach ( $def['js'] as $rel ) {
			$path = $root . '/' . ltrim( $rel, '/' );
			if ( ! is_readable( $path ) ) {
				fwrite( STDERR, "  ! missing JS: $rel\n" );
				continue;
			}
			$js .= meeha_build_min_js( (string) file_get_contents( $path ) ) . ";\n";
		}
		$entry['js_file']  = $name . '.min.js';
		$entry['js_ver']   = substr( md5( $js ), 0, 10 );
		$entry['js_bytes'] = strlen( $js );
		$stale             = ! $write( $entry['js_file'], $js ) || $stale;
		$report[]          = sprintf( '%-24s js  %7d B  gz %6d B', $name, strlen( $js ), strlen( gzencode( $js, 9 ) ) );
	}
	$manifest['bundles'][ $name ] = $entry;

	if ( $def['critical'] || 'core' === $name ) {
		$key   = 'core' === $name ? 'default' : $name;
		$srcs  = array_values( array_unique( array_merge( $core_css_src, 'core' === $name ? array() : $def['critical'] ) ) );
		$css   = '';
		foreach ( $srcs as $rel ) {
			$css .= meeha_build_read_css( $root, $rel ) . "\n";
		}
		$min  = meeha_build_min_css( $css );
		$file = 'critical-' . $key . '.min.css';
		$stale = ! $write( $file, $min ) || $stale;
		$manifest['critical'][ $key ] = array(
			'file'  => $file,
			'files' => $srcs,
			'bytes' => strlen( $min ),
			'gzip'  => strlen( gzencode( $min, 9 ) ),
		);
		$report[] = sprintf( '%-24s     %7d B  gz %6d B', 'critical-' . $key, strlen( $min ), strlen( gzencode( $min, 9 ) ) );
	}
}

/* ---------------------------------------------------------------- sprite */
$sprite_src = $root . '/tools/sprite-src.svg';
if ( is_readable( $sprite_src ) ) {
	$svg     = (string) file_get_contents( $sprite_src );
	$ext     = preg_replace( '/<svg\b[^>]*>/', '<svg xmlns="http://www.w3.org/2000/svg">', $svg, 1 );
	$inline  = array( 'm-logo', 'i-menu', 'i-close', 'i-search', 'i-user', 'i-home' );
	preg_match_all( '#<symbol\b[^>]*\bid="([^"]+)"[^>]*>.*?</symbol>#s', $svg, $mm, PREG_SET_ORDER );
	$keep = '';
	foreach ( $mm as $m ) {
		if ( in_array( $m[1], $inline, true ) ) {
			$keep .= $m[0] . "\n";
		}
	}
	$inline_svg = '<svg xmlns="http://www.w3.org/2000/svg" style="display:none" aria-hidden="true">' . "\n" . $keep . "</svg>\n";
	foreach ( array( 'sprite.svg' => $ext, 'sprite-inline.svg' => $inline_svg ) as $f => $c ) {
		$p = $root . '/assets/img/' . $f;
		if ( $check ) {
			$stale = ( ! is_readable( $p ) || file_get_contents( $p ) !== $c ) || $stale;
		} else {
			file_put_contents( $p, $c );
		}
	}
	$report[] = sprintf( '%-24s     %7d B (inline %d B, %d symbols)', 'sprite.svg', strlen( $ext ), strlen( $inline_svg ), count( $mm ) );
}

// Manifest content without the timestamp decides staleness.
$cmp = $manifest;
unset( $cmp['generated'] );
$mpath = $dist . '/manifest.json';
if ( $check ) {
	$old = is_readable( $mpath ) ? json_decode( (string) file_get_contents( $mpath ), true ) : null;
	if ( is_array( $old ) ) {
		unset( $old['generated'] );
	}
	$stale = $old !== $cmp || $stale;
	echo $stale ? "assets/dist is STALE — run: php tools/build.php\n" : "assets/dist is up to date\n";
	exit( $stale ? 1 : 0 );
}
file_put_contents( $mpath, json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );

// Remove dist files no bundle produces any more.
foreach ( glob( $dist . '/*.min.{css,js}', GLOB_BRACE ) as $f ) {
	if ( empty( $written[ basename( $f ) ] ) ) {
		unlink( $f );
	}
}
echo "Meeha build — " . count( $bundles ) . " bundles\n" . implode( "\n", $report ) . "\n";
