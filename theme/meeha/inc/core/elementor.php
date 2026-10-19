<?php
/**
 * Elementor: (1) asset blocker on theme-designed pages (port of FP:174-224, extended to every non-Elementor view
 * and the glance page), (2) per-page "Elementor → ব্লক" converter with preview and undo (UI in tools.php).
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shortcodes whose pages are always styled by the theme (legacy list + glance + latest).
 *
 * @return string[]
 */
function meeha_theme_shortcodes() {
	return apply_filters(
		'meeha_theme_shortcodes',
		array( 'meeha_login', 'meeha_register', 'meeha_my_account', 'meeha_lost_password', 'meeha_correspondent_apply', 'meeha_news_submit_form', 'meeha_photocards', 'meeha_photocard', 'meeha_latest_feed', 'meeha_at_a_glance', 'meeha_sahitya', 'meeha_literature' )
	);
}

/**
 * Is a page an "app" page (its content is one of the theme's/plugin's shortcode screens)? Those pages use the
 * normal container width and no .m-prose styles in both page templates.
 *
 * @param int $post_id Page.
 * @return bool
 */
function meeha_page_is_app( $post_id ) {
	$content = (string) get_post_field( 'post_content', $post_id );
	$codes   = array_merge( meeha_theme_shortcodes(), array( 'meeha_video_reels', 'meeha_video_carousel' ) );
	foreach ( (array) apply_filters( 'meeha_app_page_shortcodes', $codes ) as $sc ) {
		if ( has_shortcode( $content, $sc ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Is a post rendered by Elementor's builder?
 *
 * @param int $post_id Post.
 * @return bool
 */
function meeha_is_elementor_built( $post_id ) {
	return 'builder' === get_post_meta( $post_id, '_elementor_edit_mode', true ) && '' !== (string) get_post_meta( $post_id, '_elementor_data', true );
}

if ( ! function_exists( 'meeha_is_theme_designed_page' ) ) {
	/**
	 * Pages the theme designs itself (Elementor CSS/JS is useless there). (legacy name kept)
	 *
	 * @return bool
	 */
	function meeha_is_theme_designed_page() {
		if ( ! is_singular() ) {
			return true; // home, archives, search, 404 are always theme templates.
		}
		$post = get_queried_object();
		if ( ! $post instanceof WP_Post ) {
			return false;
		}
		foreach ( meeha_theme_shortcodes() as $sc ) {
			if ( has_shortcode( $post->post_content, $sc ) ) {
				return true;
			}
		}
		if ( 'post' === $post->post_type ) {
			return true;
		}
		return ! meeha_is_elementor_built( $post->ID );
	}
}

/**
 * Dequeue every elementor* / eicons* asset on theme-designed pages.
 */
function meeha_block_elementor_assets() {
	if ( is_admin() || ! meeha_opt( 'performance', 'elementor_block', 1 ) || ! did_action( 'wp' ) || ! meeha_is_theme_designed_page() ) {
		return;
	}
	if ( isset( $_GET['elementor-preview'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	global $wp_styles, $wp_scripts;
	if ( $wp_styles instanceof WP_Styles ) {
		foreach ( array_keys( $wp_styles->registered ) as $handle ) {
			if ( 0 === strpos( $handle, 'elementor' ) || 0 === strpos( $handle, 'eicons' ) || 0 === strpos( $handle, 'e-animation' ) || 0 === strpos( $handle, 'swiper' ) && false !== strpos( (string) $wp_styles->registered[ $handle ]->src, 'elementor' ) ) {
				wp_dequeue_style( $handle );
			}
		}
	}
	if ( $wp_scripts instanceof WP_Scripts ) {
		foreach ( array_keys( $wp_scripts->registered ) as $handle ) {
			if ( 0 === strpos( $handle, 'elementor' ) ) {
				wp_dequeue_script( $handle );
			}
		}
	}
}
add_action( 'wp_enqueue_scripts', 'meeha_block_elementor_assets', 999 );
add_action( 'wp_print_styles', 'meeha_block_elementor_assets', 999 );
add_action( 'wp_print_scripts', 'meeha_block_elementor_assets', 999 );

/* --------------------------------------------------------------------------
 * Elementor → blocks converter
 * ------------------------------------------------------------------------ */

/**
 * Serialize one block.
 *
 * @param string $name  Block name without "core/".
 * @param array  $attrs Attributes.
 * @param string $html  Inner HTML.
 * @return string
 */
function meeha_block( $name, $attrs, $html ) {
	// Core's serializer escapes --, <, >, & and quotes so an attribute can never close the block comment.
	$a = $attrs ? ' ' . serialize_block_attributes( $attrs ) : '';
	return "<!-- wp:{$name}{$a} -->\n{$html}\n<!-- /wp:{$name} -->\n\n";
}

/**
 * Convert an HTML fragment (Elementor text editor) to core blocks.
 *
 * @param string $html HTML.
 * @return string
 */
function meeha_html_to_blocks( $html ) {
	$html = trim( (string) $html );
	if ( '' === $html ) {
		return '';
	}
	if ( false === strpos( $html, '<' ) ) {
		$html = wpautop( $html );
	}
	if ( ! class_exists( 'DOMDocument' ) ) {
		return meeha_block( 'html', array(), $html );
	}
	$doc  = new DOMDocument();
	$prev = libxml_use_internal_errors( true );
	$doc->loadHTML( '<?xml encoding="utf-8"?><div id="meeha-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
	libxml_clear_errors();
	libxml_use_internal_errors( $prev );
	$root = $doc->getElementById( 'meeha-root' );
	if ( ! $root ) {
		return meeha_block( 'html', array(), $html );
	}
	$out    = '';
	$inline = '';
	$flush  = function () use ( &$inline, &$out ) {
		if ( '' !== trim( wp_strip_all_tags( $inline ) ) ) {
			$out .= meeha_block( 'paragraph', array(), '<p>' . trim( $inline ) . '</p>' );
		}
		$inline = '';
	};
	foreach ( iterator_to_array( $root->childNodes ) as $node ) {
		$tag  = XML_ELEMENT_NODE === $node->nodeType ? strtolower( $node->nodeName ) : '#text';
		$html = $doc->saveHTML( $node );
		switch ( $tag ) {
			case 'p':
				$flush();
				if ( '' !== trim( wp_strip_all_tags( $html ) ) || false !== strpos( $html, '<img' ) ) {
					$out .= meeha_block( 'paragraph', array(), $html );
				}
				break;
			case 'h1':
			case 'h2':
			case 'h3':
			case 'h4':
			case 'h5':
			case 'h6':
				$flush();
				$level = (int) substr( $tag, 1 );
				$level = max( 2, $level );
				$inner = '';
				foreach ( $node->childNodes as $c ) {
					$inner .= $doc->saveHTML( $c );
				}
				$out .= meeha_block( 'heading', 2 === $level ? array() : array( 'level' => $level ), '<h' . $level . ' class="wp-block-heading">' . trim( $inner ) . '</h' . $level . '>' );
				break;
			case 'ul':
			case 'ol':
				$flush();
				$items = '';
				foreach ( $node->childNodes as $li ) {
					if ( XML_ELEMENT_NODE === $li->nodeType && 'li' === strtolower( $li->nodeName ) ) {
						$inner = '';
						foreach ( $li->childNodes as $c ) {
							$inner .= $doc->saveHTML( $c );
						}
						$items .= meeha_block( 'list-item', array(), '<li>' . trim( $inner ) . '</li>' );
					}
				}
				$out .= meeha_block( 'list', 'ol' === $tag ? array( 'ordered' => true ) : array(), '<' . $tag . ' class="wp-block-list">' . "\n" . $items . '</' . $tag . '>' );
				break;
			case 'blockquote':
				$flush();
				$out .= meeha_block( 'quote', array(), '<blockquote class="wp-block-quote">' . meeha_html_to_blocks( preg_replace( '#^<blockquote[^>]*>|</blockquote>$#', '', trim( $html ) ) ) . '</blockquote>' );
				break;
			case 'hr':
				$flush();
				$out .= meeha_block( 'separator', array(), '<hr class="wp-block-separator has-alpha-channel-opacity"/>' );
				break;
			case 'table':
				$flush();
				$out .= meeha_block( 'table', array(), '<figure class="wp-block-table">' . $html . '</figure>' );
				break;
			case 'div':
			case 'section':
				$flush();
				$inner = '';
				foreach ( $node->childNodes as $c ) {
					$inner .= $doc->saveHTML( $c );
				}
				$out .= meeha_html_to_blocks( $inner );
				break;
			case '#text':
				$inline .= esc_html( $node->textContent );
				break;
			default: // inline elements (a, strong, em, span, br, img…).
				$inline .= $html;
		}
	}
	$flush();
	return $out;
}

/**
 * Convert Elementor JSON elements to block markup.
 *
 * @param array $elements Elements.
 * @param array $report   Collected report (unknown widgets).
 * @return string
 */
function meeha_elementor_elements_to_blocks( $elements, &$report ) {
	$out = '';
	foreach ( (array) $elements as $el ) {
		if ( ! is_array( $el ) ) {
			continue;
		}
		$type = isset( $el['elType'] ) ? $el['elType'] : '';
		$s    = isset( $el['settings'] ) && is_array( $el['settings'] ) ? $el['settings'] : array();
		if ( 'widget' !== $type ) {
			$out .= meeha_elementor_elements_to_blocks( isset( $el['elements'] ) ? $el['elements'] : array(), $report );
			continue;
		}
		$w = isset( $el['widgetType'] ) ? $el['widgetType'] : '';
		switch ( $w ) {
			case 'heading':
			case 'theme-post-title':
			case 'theme-page-title':
				$title = isset( $s['title'] ) ? wp_kses_post( $s['title'] ) : '';
				if ( '' === trim( wp_strip_all_tags( $title ) ) ) {
					break;
				}
				$lvl   = isset( $s['header_size'] ) && preg_match( '/^h([1-6])$/', $s['header_size'], $m ) ? max( 2, (int) $m[1] ) : 2;
				$out  .= meeha_block( 'heading', 2 === $lvl ? array() : array( 'level' => $lvl ), '<h' . $lvl . ' class="wp-block-heading">' . $title . '</h' . $lvl . '>' );
				break;
			case 'text-editor':
				$out .= meeha_html_to_blocks( isset( $s['editor'] ) ? wp_kses_post( $s['editor'] ) : '' );
				break;
			case 'image':
				$img = isset( $s['image'] ) ? $s['image'] : array();
				$url = isset( $img['url'] ) ? esc_url( $img['url'] ) : '';
				if ( ! $url ) {
					break;
				}
				$id    = isset( $img['id'] ) ? (int) $img['id'] : 0;
				$cap   = ! empty( $s['caption'] ) ? '<figcaption class="wp-element-caption">' . esc_html( $s['caption'] ) . '</figcaption>' : '';
				$alt   = $id ? esc_attr( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ) : '';
				$out  .= meeha_block( 'image', $id ? array( 'id' => $id, 'sizeSlug' => 'large', 'linkDestination' => 'none' ) : array(), '<figure class="wp-block-image size-large"><img src="' . $url . '" alt="' . $alt . '"' . ( $id ? ' class="wp-image-' . $id . '"' : '' ) . '/>' . $cap . '</figure>' );
				break;
			case 'button':
				$text = isset( $s['text'] ) ? esc_html( $s['text'] ) : '';
				$link = isset( $s['link']['url'] ) ? esc_url( $s['link']['url'] ) : '';
				if ( $text ) {
					$btn  = meeha_block( 'button', array(), '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button"' . ( $link ? ' href="' . $link . '"' : '' ) . '>' . $text . '</a></div>' );
					$out .= meeha_block( 'buttons', array(), '<div class="wp-block-buttons">' . "\n" . $btn . '</div>' );
				}
				break;
			case 'shortcode':
				$sc = isset( $s['shortcode'] ) ? trim( (string) $s['shortcode'] ) : '';
				if ( $sc ) {
					$out .= meeha_block( 'shortcode', array(), $sc );
				}
				break;
			case 'html':
				$code = isset( $s['html'] ) ? (string) $s['html'] : '';
				if ( '' !== trim( $code ) ) {
					$out .= meeha_block( 'html', array(), $code );
				}
				break;
			case 'divider':
				$out .= meeha_block( 'separator', array(), '<hr class="wp-block-separator has-alpha-channel-opacity"/>' );
				break;
			case 'spacer':
				break;
			case 'video':
				$u = '';
				foreach ( array( 'youtube_url', 'vimeo_url', 'external_url' ) as $k ) {
					if ( ! empty( $s[ $k ] ) ) {
						$u = is_array( $s[ $k ] ) ? ( isset( $s[ $k ]['url'] ) ? $s[ $k ]['url'] : '' ) : $s[ $k ];
						break;
					}
				}
				if ( $u ) {
					$out .= meeha_block( 'embed', array( 'url' => esc_url_raw( $u ) ), '<figure class="wp-block-embed"><div class="wp-block-embed__wrapper">' . "\n" . esc_url( $u ) . "\n" . '</div></figure>' );
				}
				break;
			case 'icon-list':
				$items = '';
				foreach ( (array) ( isset( $s['icon_list'] ) ? $s['icon_list'] : array() ) as $it ) {
					$t = isset( $it['text'] ) ? wp_kses_post( $it['text'] ) : '';
					$l = isset( $it['link']['url'] ) ? esc_url( $it['link']['url'] ) : '';
					if ( '' !== $t ) {
						$items .= meeha_block( 'list-item', array(), '<li>' . ( $l ? '<a href="' . $l . '">' . $t . '</a>' : $t ) . '</li>' );
					}
				}
				if ( $items ) {
					$out .= meeha_block( 'list', array(), '<ul class="wp-block-list">' . "\n" . $items . '</ul>' );
				}
				break;
			case 'image-box':
			case 'icon-box':
				if ( ! empty( $s['image']['url'] ) ) {
					$out .= meeha_block( 'image', array(), '<figure class="wp-block-image"><img src="' . esc_url( $s['image']['url'] ) . '" alt=""/></figure>' );
				}
				if ( ! empty( $s['title_text'] ) ) {
					$out .= meeha_block( 'heading', array( 'level' => 3 ), '<h3 class="wp-block-heading">' . esc_html( $s['title_text'] ) . '</h3>' );
				}
				if ( ! empty( $s['description_text'] ) ) {
					$out .= meeha_html_to_blocks( wp_kses_post( $s['description_text'] ) );
				}
				break;
			default:
				$report['unknown'][] = $w;
				$text = '';
				foreach ( array( 'title', 'text', 'editor', 'description', 'content' ) as $k ) {
					if ( ! empty( $s[ $k ] ) && is_string( $s[ $k ] ) ) {
						$text .= wp_kses_post( $s[ $k ] ) . "\n";
					}
				}
				if ( '' !== trim( wp_strip_all_tags( $text ) ) ) {
					$out .= meeha_html_to_blocks( $text );
				}
		}
	}
	return $out;
}

/**
 * Convert a page's Elementor data to blocks (no saving).
 *
 * @param int $post_id Post.
 * @return array content, unknown(string[]), error
 */
function meeha_elementor_convert( $post_id ) {
	$raw = get_post_meta( $post_id, '_elementor_data', true );
	if ( is_string( $raw ) ) {
		$data = json_decode( $raw, true );
	} else {
		$data = $raw;
	}
	if ( ! is_array( $data ) ) {
		return array(
			'content' => '',
			'unknown' => array(),
			'error'   => 'Elementor ডেটা পাওয়া যায়নি বা ভাঙা।',
		);
	}
	$report  = array( 'unknown' => array() );
	$content = trim( meeha_elementor_elements_to_blocks( $data, $report ) );
	return array(
		'content' => $content,
		'unknown' => array_values( array_unique( $report['unknown'] ) ),
		'error'   => '' === $content ? 'রূপান্তরের মতো কোনো লেখা পাওয়া যায়নি।' : '',
	);
}

/**
 * Apply the conversion: back up content/template/edit-mode, write blocks, switch the page to the theme template.
 *
 * @param int $post_id Post.
 * @return true|WP_Error
 */
function meeha_elementor_apply( $post_id ) {
	$post = get_post( $post_id );
	if ( ! $post ) {
		return new WP_Error( 'missing', 'পাতা পাওয়া যায়নি।' );
	}
	$conv = meeha_elementor_convert( $post_id );
	if ( $conv['error'] ) {
		return new WP_Error( 'convert', $conv['error'] );
	}
	if ( ! get_post_meta( $post_id, '_meeha_elementor_backup', true ) ) {
		update_post_meta(
			$post_id,
			'_meeha_elementor_backup',
			array(
				'content'   => $post->post_content,
				'edit_mode' => get_post_meta( $post_id, '_elementor_edit_mode', true ),
				'template'  => get_post_meta( $post_id, '_wp_page_template', true ),
				'time'      => time(),
				'user'      => get_current_user_id(),
			)
		);
	}
	// Switch the template first: wp_update_post() rejects templates that are not registered (Elementor's when inactive).
	$tpl = get_post_meta( $post_id, '_wp_page_template', true );
	if ( $tpl && 0 === strpos( $tpl, 'elementor_' ) ) {
		update_post_meta( $post_id, '_wp_page_template', 'default' );
	}
	$res = wp_update_post(
		wp_slash(
			array(
				'ID'           => $post_id,
				'post_content' => $conv['content'],
			)
		),
		true
	);
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	delete_post_meta( $post_id, '_elementor_edit_mode' );
	return true;
}

/**
 * Undo a conversion from the backup.
 *
 * @param int $post_id Post.
 * @return true|WP_Error
 */
function meeha_elementor_undo( $post_id ) {
	$b = get_post_meta( $post_id, '_meeha_elementor_backup', true );
	if ( ! is_array( $b ) ) {
		return new WP_Error( 'nobackup', 'ফিরিয়ে আনার মতো কোনো ব্যাকআপ নেই।' );
	}
	$res = wp_update_post(
		wp_slash(
			array(
				'ID'           => $post_id,
				'post_content' => $b['content'],
			)
		),
		true
	);
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	if ( $b['edit_mode'] ) {
		update_post_meta( $post_id, '_elementor_edit_mode', $b['edit_mode'] );
	}
	if ( $b['template'] ) {
		update_post_meta( $post_id, '_wp_page_template', $b['template'] );
	} else {
		delete_post_meta( $post_id, '_wp_page_template' );
	}
	delete_post_meta( $post_id, '_meeha_elementor_backup' );
	return true;
}
