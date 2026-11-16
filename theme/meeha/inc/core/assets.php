<?php
/**
 * Asset bundles.
 *
 * Bundles are declared in tools/bundles.d/{A..E}.json:
 *   { "<bundle>": { "css": ["assets/css/x.css"], "js": ["assets/js/y.js"], "critical": ["assets/css/x.css"], "deps": ["script-handle"] } }
 * `php tools/build.php` merges them into assets/dist/<bundle>.min.css / .min.js, assets/dist/critical-<bundle>.min.css
 * (= the `core` bundle's CSS + that bundle's "critical" files) and assets/dist/manifest.json.
 *
 * Runtime:
 *  - critical CSS for meeha_current_template_key() is inlined in <head> (falls back to critical-default = core);
 *    with the setting off, `core` is a normal render-blocking stylesheet instead;
 *  - `base` (dialogs, footer, meeha.js) is always enqueued;
 *  - meeha_enqueue_bundle( 'x' ) enqueues bundle x: CSS non-blocking (preload → onload swap + <noscript>), JS `defer`;
 *    a bundle whose CSS is entirely inside the inlined critical CSS is not downloaded again;
 *  - when assets/dist is missing (no build yet) the source files are enqueued one by one.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

/**
 * Build manifest (assets/dist/manifest.json).
 *
 * @return array
 */
function meeha_manifest() {
	static $m = null;
	if ( null === $m ) {
		$file = MEEHA_DIR . '/assets/dist/manifest.json';
		$m    = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! is_array( $m ) ) {
			$m = array(
				'bundles'  => meeha_bundles_from_source(),
				'critical' => array(),
				'dev'      => true,
			);
		}
	}
	return $m;
}

/**
 * Merge tools/bundles.d/*.json (used when there is no build).
 *
 * @return array
 */
function meeha_bundles_from_source() {
	$out   = array();
	$files = glob( MEEHA_DIR . '/tools/bundles.d/*.json' );
	sort( $files );
	foreach ( (array) $files as $f ) {
		$j = json_decode( (string) file_get_contents( $f ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! is_array( $j ) ) {
			continue;
		}
		foreach ( $j as $name => $def ) {
			if ( ! is_array( $def ) || '_' === substr( $name, 0, 1 ) ) {
				continue;
			}
			foreach ( array( 'css', 'js', 'critical', 'deps' ) as $k ) {
				$out[ $name ][ $k ] = array_values( array_unique( array_merge( isset( $out[ $name ][ $k ] ) ? $out[ $name ][ $k ] : array(), isset( $def[ $k ] ) ? (array) $def[ $k ] : array() ) ) );
			}
		}
	}
	return $out;
}

/**
 * Current template key: home|single|archive|search|page|literature|quotes|account|404 (filter `meeha_template_key`).
 *
 * @return string
 */
function meeha_current_template_key() {
	if ( isset( $GLOBALS['meeha_template_key'] ) ) {
		return $GLOBALS['meeha_template_key'];
	}
	$key = 'archive';
	if ( is_404() ) {
		$key = '404';
	} elseif ( is_search() ) {
		$key = 'search';
	} elseif ( is_front_page() && ! is_paged() && ( is_home() || 'page' === get_option( 'show_on_front' ) ) ) {
		$key = 'home';
	} elseif ( is_singular( array( 'meeha_photocard', 'meeha_quote' ) ) || is_post_type_archive( array( 'meeha_photocard', 'meeha_quote' ) ) || is_tax( 'meeha_quote_type' ) ) {
		$key = 'quotes';
	} elseif ( is_page() ) {
		$post = get_queried_object();
		$key  = 'page';
		if ( $post instanceof WP_Post ) {
			foreach ( array( 'meeha_login', 'meeha_register', 'meeha_my_account', 'meeha_lost_password' ) as $sc ) {
				if ( has_shortcode( $post->post_content, $sc ) ) {
					$key = 'account';
					break;
				}
			}
			if ( 'page' === $key && ( has_shortcode( $post->post_content, 'meeha_sahitya' ) || has_shortcode( $post->post_content, 'meeha_literature' ) ) ) {
				$key = 'literature';
			}
		}
	} elseif ( is_singular() ) {
		$key = ( is_singular( 'post' ) && 'সাহিত্য' === meeha_post_type_label( get_queried_object() ) ) ? 'literature' : 'single';
	}
	$key = (string) apply_filters( 'meeha_template_key', $key );
	if ( did_action( 'wp' ) ) {
		$GLOBALS['meeha_template_key'] = $key;
	}
	return $key;
}

/**
 * Whether inline critical CSS is active for this request.
 *
 * @return bool
 */
function meeha_critical_active() {
	static $on = null;
	if ( null === $on ) {
		$on = (bool) meeha_opt( 'performance', 'critical_css', 1 ) && '' !== meeha_critical_file();
		$on = (bool) apply_filters( 'meeha_critical_active', $on );
	}
	return $on;
}

/**
 * Path of the critical CSS file for the current template ('' when none was built).
 *
 * @return string
 */
function meeha_critical_file() {
	$key = sanitize_file_name( meeha_current_template_key() );
	foreach ( array( 'critical-' . $key . '.min.css', 'critical-default.min.css' ) as $f ) {
		if ( is_readable( MEEHA_DIR . '/assets/dist/' . $f ) ) {
			return MEEHA_DIR . '/assets/dist/' . $f;
		}
	}
	return '';
}

/**
 * Source files contained in the current critical CSS.
 *
 * @return string[]
 */
function meeha_critical_sources() {
	$m    = meeha_manifest();
	$file = basename( meeha_critical_file(), '.min.css' );
	$name = preg_replace( '/^critical-/', '', $file );
	return isset( $m['critical'][ $name ]['files'] ) ? (array) $m['critical'][ $name ]['files'] : array();
}

/**
 * Request a bundle on this page.
 *
 * @param string $bundle Bundle name.
 */
function meeha_enqueue_bundle( $bundle ) {
	$bundle = sanitize_key( $bundle );
	if ( isset( $GLOBALS['meeha_bundles_queued'][ $bundle ] ) ) {
		return;
	}
	$GLOBALS['meeha_bundles_queued'][ $bundle ] = true;
	if ( did_action( 'wp_enqueue_scripts' ) ) {
		meeha_do_enqueue_bundle( $bundle );
	}
}

/**
 * Enqueue a bundle now.
 *
 * @param string $bundle Bundle.
 */
function meeha_do_enqueue_bundle( $bundle ) {
	$m = meeha_manifest();
	if ( empty( $m['bundles'][ $bundle ] ) ) {
		return;
	}
	$def    = $m['bundles'][ $bundle ];
	$handle = 'meeha-' . $bundle;
	$dev    = ! empty( $m['dev'] );

	$css = isset( $def['css'] ) ? (array) $def['css'] : array();
	if ( $css ) {
		$skip = false;
		if ( 'core' !== $bundle && meeha_critical_active() ) {
			$skip = ! array_diff( $css, meeha_critical_sources() );
		}
		if ( ! $skip ) {
			$async = 'core' !== $bundle || meeha_critical_active();
			if ( $dev ) {
				foreach ( $css as $i => $src ) {
					$h = $handle . ( $i ? '-' . $i : '' );
					wp_enqueue_style( $h, MEEHA_URI . '/' . ltrim( $src, '/' ), array(), (string) @filemtime( MEEHA_DIR . '/' . $src ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
					if ( $async ) {
						$GLOBALS['meeha_async_styles'][ $h ] = true;
					}
				}
			} elseif ( ! empty( $def['css_file'] ) ) {
				wp_enqueue_style( $handle, MEEHA_URI . '/assets/dist/' . $def['css_file'], array(), isset( $def['css_ver'] ) ? $def['css_ver'] : MEEHA_VERSION );
				if ( $async ) {
					$GLOBALS['meeha_async_styles'][ $handle ] = true;
				}
			}
		}
	}

	$js   = isset( $def['js'] ) ? (array) $def['js'] : array();
	$deps = isset( $def['deps'] ) ? (array) $def['deps'] : array();
	if ( $js ) {
		$args = array(
			'strategy'  => 'defer',
			'in_footer' => false,
		);
		if ( $dev ) {
			foreach ( $js as $i => $src ) {
				$h = $handle . ( $i ? '-' . $i : '' );
				wp_enqueue_script( $h, MEEHA_URI . '/' . ltrim( $src, '/' ), $deps, (string) @filemtime( MEEHA_DIR . '/' . $src ), $args ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		} elseif ( ! empty( $def['js_file'] ) ) {
			wp_enqueue_script( $handle, MEEHA_URI . '/assets/dist/' . $def['js_file'], $deps, isset( $def['js_ver'] ) ? $def['js_ver'] : MEEHA_VERSION, $args );
		}
	}
}

add_action(
	'wp_enqueue_scripts',
	function () {
		if ( ! meeha_critical_active() ) {
			meeha_do_enqueue_bundle( 'core' );
		}
		meeha_enqueue_bundle( 'base' );
		foreach ( array_keys( isset( $GLOBALS['meeha_bundles_queued'] ) ? $GLOBALS['meeha_bundles_queued'] : array() ) as $b ) {
			meeha_do_enqueue_bundle( $b );
		}
	},
	5
);

/**
 * Non-blocking stylesheet markup for bundle handles.
 */
add_filter(
	'style_loader_tag',
	function ( $tag, $handle, $href, $media ) {
		if ( empty( $GLOBALS['meeha_async_styles'][ $handle ] ) || is_admin() ) {
			return $tag;
		}
		$id = esc_attr( $handle . '-css' );
		return sprintf(
			'<link rel="preload" as="style" id="%1$s" href="%2$s" media="%3$s" onload="this.onload=null;this.rel=\'stylesheet\'">' . "\n" .
			'<noscript><link rel="stylesheet" href="%2$s" media="%3$s"></noscript>' . "\n",
			$id,
			esc_url( $href ),
			esc_attr( $media ? $media : 'all' )
		);
	},
	10,
	4
);

/**
 * Inline critical CSS (font/img urls rewritten to absolute theme URLs).
 *
 * @return string
 */
function meeha_critical_css() {
	$file = meeha_critical_file();
	if ( ! $file ) {
		return '';
	}
	$css  = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	$base = MEEHA_URI . '/assets/';
	return (string) preg_replace( '#url\(\s*(["\']?)\.\./#', 'url($1' . $base, $css );
}

add_action(
	'wp_head',
	function () {
		if ( meeha_critical_active() ) {
			echo '<style id="meeha-critical">' . meeha_critical_css() . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- theme CSS file.
		}
	},
	3
);

/**
 * Preload the two critical fonts.
 */
add_action(
	'wp_head',
	function () {
		if ( ! meeha_opt( 'performance', 'preload_fonts', 1 ) ) {
			return;
		}
		foreach ( apply_filters( 'meeha_preload_fonts', array( 'meeha-serif-bn-700.woff2', 'meeha-sans-bn-400.woff2' ) ) as $f ) {
			printf( '<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin>' . "\n", esc_url( MEEHA_URI . '/assets/fonts/' . $f ) );
		}
	},
	2
);

/**
 * URL of the literary font stylesheet (Tiro Bangla @font-face) for the lazy loader / literature pages.
 *
 * @return string
 */
function meeha_literary_font_url() {
	$m = meeha_manifest();
	if ( empty( $m['dev'] ) && ! empty( $m['bundles']['literary-font']['css_file'] ) ) {
		return MEEHA_URI . '/assets/dist/' . $m['bundles']['literary-font']['css_file'] . '?ver=' . rawurlencode( (string) $m['bundles']['literary-font']['css_ver'] );
	}
	return MEEHA_URI . '/assets/css/literary-font.css?ver=' . rawurlencode( MEEHA_VERSION );
}

/**
 * Body classes: template key + dark-mode hook.
 */
add_filter(
	'body_class',
	function ( $classes ) {
		$classes[] = 'm-tpl-' . sanitize_html_class( meeha_current_template_key() );
		return $classes;
	}
);

/**
 * Favicon fallback when no Site Icon is set.
 */
add_action(
	'wp_head',
	function () {
		if ( ! has_site_icon() ) {
			echo '<link rel="icon" href="' . esc_url( MEEHA_URI . '/assets/img/favicon.svg' ) . '" type="image/svg+xml">' . "\n";
		}
		echo '<meta name="theme-color" content="#025AB2" media="(prefers-color-scheme: light)">' . "\n";
		echo '<meta name="theme-color" content="#0D141D" media="(prefers-color-scheme: dark)">' . "\n";
	},
	4
);
