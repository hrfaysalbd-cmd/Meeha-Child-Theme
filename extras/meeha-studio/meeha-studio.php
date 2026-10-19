<?php
/**
 * Plugin Name: Meeha Graphics Studio
 * Description: নিউজ সাইটের জন্য ফটোকার্ড ও গ্রাফিক্স স্টুডিও: সংবাদ, ব্রেকিং, ফিচার, মতামত, উক্তি, সাহিত্য, কবিতা, শুভ সকাল ও দিবসের কার্ড; বর্গ, পোর্ট্রেট, স্টোরি ও লিংক মাপ; পোস্ট আইডি থেকে তথ্য; ক্যাটেগরির রং; ফ্রেম ওভারলে; PNG ডাউনলোড ও মিডিয়া লাইব্রেরিতে সংরক্ষণ।
 * Version: 1.0.0
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Author: Meeha News
 * License: GPL-2.0-or-later
 * Text Domain: meeha-studio
 *
 * @package meeha-studio
 */

defined( 'ABSPATH' ) || exit;

define( 'MEEHA_STUDIO_VERSION', '1.0.0' );
define( 'MEEHA_STUDIO_URL', plugin_dir_url( __FILE__ ) );
define( 'MEEHA_STUDIO_DIR', plugin_dir_path( __FILE__ ) );
const MEEHA_STUDIO_OPTION = 'meeha_studio_settings';

/**
 * Who may use the studio, and who may change its settings. Filterable.
 */
function meeha_studio_cap() {
	return apply_filters( 'meeha_studio_capability', 'edit_others_posts' );
}
function meeha_studio_settings_cap() {
	return apply_filters( 'meeha_studio_settings_capability', 'manage_options' );
}

/**
 * Saved settings merged with defaults.
 *
 * @return array
 */
function meeha_studio_settings() {
	$defaults = array(
		'logo_id'         => 0,
		'logo_light_id'   => 0,
		'site_text'       => wp_parse_url( home_url(), PHP_URL_HOST ),
		'brand'           => get_bloginfo( 'name' ),
		'hijri_offset'    => -1,
		'default_palette' => 'category',
		'ads'             => array(
			'square'   => 0,
			'portrait' => 0,
			'story'    => 0,
			'link'     => 0,
		),
		'cat_colors'      => array(),
		'frames'          => array(),
	);
	$saved = get_option( MEEHA_STUDIO_OPTION, array() );
	$s     = wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
	$s['ads'] = wp_parse_args( (array) $s['ads'], $defaults['ads'] );
	return $s;
}

/**
 * A soft, distinct default colour for a category without one.
 *
 * @param int $term_id Term.
 * @return string Hex colour.
 */
function meeha_studio_default_cat_color( $term_id ) {
	$palette = array( '#025AB2', '#0E8A6A', '#D0283A', '#E06A1B', '#6D4BB0', '#C9306E', '#0B88B5', '#B07D12' );
	return $palette[ absint( $term_id ) % count( $palette ) ];
}

/**
 * Colour for a category: the saved one, else a default.
 *
 * @param int $term_id Term.
 * @return string
 */
function meeha_studio_cat_color( $term_id ) {
	$s = meeha_studio_settings();
	$c = isset( $s['cat_colors'][ $term_id ] ) ? sanitize_hex_color( $s['cat_colors'][ $term_id ] ) : '';
	return $c ? $c : meeha_studio_default_cat_color( $term_id );
}

// -----------------------------------------------------------------------------
// Admin menu
// -----------------------------------------------------------------------------
add_action(
	'admin_menu',
	function () {
		add_menu_page( 'গ্রাফিক্স স্টুডিও', 'গ্রাফিক্স স্টুডিও', meeha_studio_cap(), 'meeha-studio', 'meeha_studio_render_app', 'dashicons-format-image', 26 );
		add_submenu_page( 'meeha-studio', 'গ্রাফিক্স স্টুডিও', 'স্টুডিও', meeha_studio_cap(), 'meeha-studio', 'meeha_studio_render_app' );
		add_submenu_page( 'meeha-studio', 'স্টুডিও সেটিংস', 'সেটিংস', meeha_studio_settings_cap(), 'meeha-studio-settings', 'meeha_studio_render_settings' );
	}
);

/**
 * URL that serves an attachment through our same-origin proxy (keeps the
 * canvas exportable even when images are on a CDN).
 *
 * @param int $id Attachment ID.
 * @return string
 */
function meeha_studio_image_url( $id ) {
	if ( ! $id ) {
		return '';
	}
	return add_query_arg( '_wpnonce', wp_create_nonce( 'wp_rest' ), rest_url( 'meeha-studio/v1/image/' . absint( $id ) ) );
}

/**
 * Built-in frame overlays shipped in assets/frames ({id}-{size}.png).
 *
 * @return array
 */
function meeha_studio_builtin_frames() {
	$names = array(
		'news-nil-bracket'     => 'নীল ব্র্যাকেট',
		'news-breaking'        => 'ব্রেকিং ব্যান্ড',
		'news-exclusive'       => 'এক্সক্লুসিভ',
		'news-sorboshesh'      => 'সর্বশেষ',
		'news-meeha-special'   => 'মিহা স্পেশাল',
		'shok-kalo-border'     => 'শোক: কালো পাড়',
		'shok-kona-fita'       => 'শোক: কোণে ফিতা',
		'jonmodin-confetti'    => 'জন্মদিন: কনফেটি',
		'jonmodin-sonali'      => 'জন্মদিন: সোনালি',
		'bijoy-dibos'          => 'বিজয় দিবস',
		'swadhinota-dibos'     => 'স্বাধীনতা দিবস',
		'ekushe-february'      => 'অমর একুশে',
		'pohela-boishakh'      => 'পহেলা বৈশাখ',
		'eid-mubarak'          => 'ঈদ মোবারক',
		'sharodiya-shubhechha' => 'শারদীয়',
	);
	$out = array();
	foreach ( $names as $id => $name ) {
		$sizes = array();
		foreach ( array( 'square', 'portrait', 'story', 'link' ) as $size ) {
			if ( file_exists( MEEHA_STUDIO_DIR . "assets/frames/{$id}-{$size}.png" ) ) {
				$sizes[ $size ] = MEEHA_STUDIO_URL . "assets/frames/{$id}-{$size}.png";
			}
		}
		if ( $sizes ) {
			$out[] = array(
				'id'    => $id,
				'name'  => $name,
				'sizes' => $sizes,
			);
		}
	}
	return $out;
}

/**
 * Studio page.
 */
function meeha_studio_render_app() {
	echo '<div class="wrap"><div id="meeha-studio-app"><p>লোড হচ্ছে…</p></div></div>';
}

add_action(
	'admin_enqueue_scripts',
	function ( $hook ) {
		$is_app      = 'toplevel_page_meeha-studio' === $hook;
		$is_settings = false !== strpos( $hook, 'meeha-studio-settings' );
		if ( ! $is_app && ! $is_settings ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'meeha-studio-fonts', 'https://fonts.googleapis.com/css2?family=Noto+Serif+Bengali:wght@600;800&family=Hind+Siliguri:wght@500;600;700&family=Tiro+Bangla:ital@0;1&display=swap', array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		wp_enqueue_style( 'meeha-studio', MEEHA_STUDIO_URL . 'assets/studio.css', array(), MEEHA_STUDIO_VERSION );

		if ( $is_settings ) {
			wp_enqueue_script( 'meeha-studio-settings', MEEHA_STUDIO_URL . 'assets/settings.js', array(), MEEHA_STUDIO_VERSION, true );
			return;
		}

		$s      = meeha_studio_settings();
		$frames = array();
		foreach ( (array) $s['frames'] as $f ) {
			if ( empty( $f['attachment_id'] ) || ! wp_attachment_is_image( $f['attachment_id'] ) ) {
				continue;
			}
			$frames[] = array(
				'id'    => 'custom-' . absint( $f['attachment_id'] ),
				'name'  => $f['name'],
				'sizes' => array( $f['size'] => meeha_studio_image_url( $f['attachment_id'] ) ),
			);
		}
		$ads = array();
		foreach ( $s['ads'] as $size => $id ) {
			if ( $id ) {
				$ads[ $size ] = meeha_studio_image_url( $id );
			}
		}

		wp_enqueue_script( 'meeha-studio-render', MEEHA_STUDIO_URL . 'assets/render.js', array(), MEEHA_STUDIO_VERSION, true );
		wp_enqueue_script( 'meeha-studio', MEEHA_STUDIO_URL . 'assets/studio.js', array( 'meeha-studio-render' ), MEEHA_STUDIO_VERSION, true );
		wp_localize_script(
			'meeha-studio',
			'MeehaStudio',
			array(
				'rest'          => esc_url_raw( rest_url( 'meeha-studio/v1/' ) ),
				'mediaRest'     => esc_url_raw( rest_url( 'wp/v2/media' ) ),
				'nonce'         => wp_create_nonce( 'wp_rest' ),
				'brand'         => $s['brand'],
				'site'          => $s['site_text'],
				'hijriOffset'   => (int) $s['hijri_offset'],
				'palette'       => $s['default_palette'],
				'logo'          => meeha_studio_image_url( $s['logo_id'] ),
				'logoLight'     => meeha_studio_image_url( $s['logo_light_id'] ),
				'ads'           => $ads,
				'frames'        => array_merge( meeha_studio_builtin_frames(), $frames ),
				'canUpload'     => current_user_can( 'upload_files' ),
				'settingsUrl'   => current_user_can( meeha_studio_settings_cap() ) ? admin_url( 'admin.php?page=meeha-studio-settings' ) : '',
				'user'          => get_current_user_id(),
			)
		);
	}
);

// -----------------------------------------------------------------------------
// REST API
// -----------------------------------------------------------------------------
add_action(
	'rest_api_init',
	function () {
		$can = function () {
			return current_user_can( meeha_studio_cap() );
		};
		register_rest_route(
			'meeha-studio/v1',
			'/posts',
			array(
				'methods'             => 'GET',
				'permission_callback' => $can,
				'callback'            => 'meeha_studio_rest_posts',
				'args'                => array(
					'search' => array( 'type' => 'string' ),
					'offset' => array(
						'type'    => 'integer',
						'default' => 0,
					),
				),
			)
		);
		register_rest_route(
			'meeha-studio/v1',
			'/post',
			array(
				'methods'             => 'GET',
				'permission_callback' => $can,
				'callback'            => 'meeha_studio_rest_post',
				'args'                => array( 'ref' => array( 'type' => 'string', 'required' => true ) ),
			)
		);
		register_rest_route(
			'meeha-studio/v1',
			'/image/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'permission_callback' => $can,
				'callback'            => 'meeha_studio_rest_image',
			)
		);
		register_rest_route(
			'meeha-studio/v1',
			'/avatar/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'permission_callback' => $can,
				'callback'            => 'meeha_studio_rest_avatar',
			)
		);
	}
);

/**
 * Five most recent published posts (optionally searched), for the picker.
 *
 * @param WP_REST_Request $req Request.
 * @return array
 */
function meeha_studio_rest_posts( WP_REST_Request $req ) {
	$q = new WP_Query(
		array(
			'post_type'           => 'post',
			'post_status'         => 'publish',
			'posts_per_page'      => 5,
			'offset'              => max( 0, (int) $req['offset'] ),
			's'                   => (string) $req['search'],
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
		)
	);
	$out = array();
	foreach ( $q->posts as $p ) {
		$thumb = get_post_thumbnail_id( $p );
		$out[] = array(
			'id'    => $p->ID,
			'title' => html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ),
			'date'  => get_the_date( 'Y-m-d H:i', $p ),
			'thumb' => $thumb ? wp_get_attachment_image_url( $thumb, 'thumbnail' ) : '',
		);
	}
	return $out;
}

/**
 * Everything a card needs from one post. `ref` is an ID or a permalink.
 *
 * @param WP_REST_Request $req Request.
 * @return array|WP_Error
 */
function meeha_studio_rest_post( WP_REST_Request $req ) {
	$ref = trim( (string) $req['ref'] );
	$id  = ctype_digit( $ref ) ? (int) $ref : url_to_postid( $ref );
	$p   = $id ? get_post( $id ) : null;
	if ( ! $p || 'publish' !== $p->post_status && ! current_user_can( 'edit_post', $p->ID ) ) {
		return new WP_Error( 'meeha_studio_not_found', 'এই আইডি বা লিংকে কোনো পোস্ট পাওয়া যায়নি।', array( 'status' => 404 ) );
	}

	$cats    = get_the_category( $p->ID );
	$primary = $cats ? $cats[0] : null;
	// Rank Math primary category, when set.
	$rm = (int) get_post_meta( $p->ID, 'rank_math_primary_category', true );
	if ( $rm ) {
		foreach ( $cats as $c ) {
			if ( (int) $c->term_id === $rm ) {
				$primary = $c;
			}
		}
	}

	$author_id = (int) $p->post_author;
	$name      = get_the_author_meta( 'display_name', $author_id );
	$title     = '';
	foreach ( array( 'meeha_author_title', 'designation', 'job_title' ) as $key ) {
		$title = get_user_meta( $author_id, $key, true );
		if ( $title ) {
			break;
		}
	}
	// Guest writers on opinion pieces, if a site stores them per post.
	$guest = get_post_meta( $p->ID, '_meeha_guest_author', true );
	if ( $guest ) {
		$name  = $guest;
		$title = (string) get_post_meta( $p->ID, '_meeha_guest_author_title', true );
	}
	$author = apply_filters(
		'meeha_studio_post_author',
		array(
			'name'   => $name,
			'title'  => $title,
			'avatar' => $guest ? '' : add_query_arg( '_wpnonce', wp_create_nonce( 'wp_rest' ), rest_url( 'meeha-studio/v1/avatar/' . $author_id ) ),
		),
		$p
	);

	$excerpt = has_excerpt( $p ) ? get_the_excerpt( $p ) : '';
	$summary = (string) get_post_meta( $p->ID, '_meeha_summary', true );
	$content = $p->post_content;
	$content = preg_replace( '#<(br|/p|/h[1-6]|/li|/blockquote)[^>]*>#i', "\n", $content );
	$content = trim( preg_replace( "/\n{3,}/", "\n\n", html_entity_decode( wp_strip_all_tags( strip_shortcodes( $content ) ), ENT_QUOTES, 'UTF-8' ) ) );
	if ( ! $excerpt ) {
		$excerpt = wp_trim_words( $content, 40, '…' );
	}
	$thumb = get_post_thumbnail_id( $p );

	return array(
		'id'       => $p->ID,
		'title'    => html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ),
		'excerpt'  => html_entity_decode( wp_strip_all_tags( $excerpt ), ENT_QUOTES, 'UTF-8' ),
		'summary'  => wp_strip_all_tags( $summary ),
		'content'  => mb_substr( $content, 0, 3000 ),
		'link'     => get_permalink( $p ),
		'date'     => get_the_date( 'Y-m-d', $p ),
		'time'     => get_the_date( 'H:i', $p ),
		'category' => $primary ? array(
			'id'    => $primary->term_id,
			'name'  => html_entity_decode( $primary->name, ENT_QUOTES, 'UTF-8' ),
			'color' => meeha_studio_cat_color( $primary->term_id ),
		) : null,
		'image'    => $thumb ? meeha_studio_image_url( $thumb ) : '',
		'credit'   => $thumb ? wp_strip_all_tags( (string) wp_get_attachment_caption( $thumb ) ) : '',
		'author'   => $author,
	);
}

/**
 * Send a local image file to the browser.
 *
 * @param string $file Absolute path.
 * @return WP_Error|void
 */
function meeha_studio_send_file( $file ) {
	$type = wp_check_filetype( $file );
	if ( ! $file || ! is_readable( $file ) || 0 !== strpos( (string) $type['type'], 'image/' ) ) {
		return new WP_Error( 'meeha_studio_no_image', 'ছবি পাওয়া যায়নি।', array( 'status' => 404 ) );
	}
	nocache_headers();
	header( 'Content-Type: ' . $type['type'] );
	header( 'Content-Length: ' . filesize( $file ) );
	header( 'Cache-Control: private, max-age=3600' );
	readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
	exit;
}

/**
 * Same-origin proxy for an attachment (largest reasonable size).
 *
 * @param WP_REST_Request $req Request.
 * @return WP_Error|void
 */
function meeha_studio_rest_image( WP_REST_Request $req ) {
	$id = (int) $req['id'];
	if ( 'attachment' !== get_post_type( $id ) || ! wp_attachment_is_image( $id ) ) {
		return new WP_Error( 'meeha_studio_no_image', 'ছবি পাওয়া যায়নি।', array( 'status' => 404 ) );
	}
	$file = get_attached_file( $id );
	// Prefer a ~2048px version over a huge original.
	$meta = wp_get_attachment_metadata( $id );
	if ( ! empty( $meta['sizes']['2048x2048']['file'] ) ) {
		$file = path_join( dirname( $file ), $meta['sizes']['2048x2048']['file'] );
	}
	return meeha_studio_send_file( $file );
}

/**
 * Author photo through the proxy: site avatar if uploaded, else Gravatar.
 *
 * @param WP_REST_Request $req Request.
 * @return WP_Error|void
 */
function meeha_studio_rest_avatar( WP_REST_Request $req ) {
	$uid = (int) $req['id'];
	if ( ! get_userdata( $uid ) ) {
		return new WP_Error( 'meeha_studio_no_image', 'ছবি পাওয়া যায়নি।', array( 'status' => 404 ) );
	}
	$aid = (int) get_user_meta( $uid, '_nros_avatar_id', true );
	if ( $aid && wp_attachment_is_image( $aid ) ) {
		return meeha_studio_send_file( get_attached_file( $aid ) );
	}
	$url = (string) get_user_meta( $uid, 'meeha_avatar', true );
	if ( $url ) {
		$uploads = wp_get_upload_dir();
		if ( 0 === strpos( $url, $uploads['baseurl'] ) ) {
			$file = realpath( $uploads['basedir'] . substr( $url, strlen( $uploads['baseurl'] ) ) );
			if ( $file && 0 === strpos( $file, realpath( $uploads['basedir'] ) ) ) {
				return meeha_studio_send_file( $file );
			}
		}
	}
	$grav = get_avatar_url( $uid, array( 'size' => 400, 'default' => '404' ) );
	$host = (string) wp_parse_url( $grav, PHP_URL_HOST );
	if ( $grav && preg_match( '/(^|\.)gravatar\.com$/', $host ) ) {
		$res = wp_safe_remote_get( $grav, array( 'timeout' => 6 ) );
		if ( ! is_wp_error( $res ) && 200 === wp_remote_retrieve_response_code( $res ) ) {
			$type = wp_remote_retrieve_header( $res, 'content-type' );
			if ( 0 === strpos( (string) $type, 'image/' ) ) {
				nocache_headers();
				header( 'Content-Type: ' . $type );
				header( 'Cache-Control: private, max-age=3600' );
				echo wp_remote_retrieve_body( $res ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				exit;
			}
		}
	}
	return new WP_Error( 'meeha_studio_no_image', 'ছবি নেই।', array( 'status' => 404 ) );
}

// -----------------------------------------------------------------------------
// Settings page
// -----------------------------------------------------------------------------
add_action(
	'admin_post_meeha_studio_save',
	function () {
		if ( ! current_user_can( meeha_studio_settings_cap() ) ) {
			wp_die( 'অনুমতি নেই।', 403 );
		}
		check_admin_referer( 'meeha_studio_save' );
		$in  = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$old = meeha_studio_settings();
		$s   = array(
			'logo_id'         => absint( $in['logo_id'] ?? 0 ),
			'logo_light_id'   => absint( $in['logo_light_id'] ?? 0 ),
			'site_text'       => sanitize_text_field( $in['site_text'] ?? '' ),
			'brand'           => sanitize_text_field( $in['brand'] ?? '' ),
			'hijri_offset'    => max( -2, min( 2, (int) ( $in['hijri_offset'] ?? 0 ) ) ),
			'default_palette' => sanitize_key( $in['default_palette'] ?? 'category' ),
			'ads'             => array(),
			'cat_colors'      => array(),
			'frames'          => array(),
		);
		foreach ( array( 'square', 'portrait', 'story', 'link' ) as $size ) {
			$s['ads'][ $size ] = absint( $in['ads'][ $size ] ?? 0 );
		}
		foreach ( (array) ( $in['cat_colors'] ?? array() ) as $tid => $hex ) {
			$hex = sanitize_hex_color( $hex );
			if ( $hex && strtolower( $hex ) !== strtolower( meeha_studio_default_cat_color( (int) $tid ) ) ) {
				$s['cat_colors'][ absint( $tid ) ] = $hex;
			}
		}
		$names = (array) ( $in['frame_name'] ?? array() );
		$sizes = (array) ( $in['frame_size'] ?? array() );
		foreach ( (array) ( $in['frame_id'] ?? array() ) as $i => $aid ) {
			$aid = absint( $aid );
			if ( ! $aid || empty( $in['frame_keep'][ $i ] ) ) {
				continue;
			}
			$size          = sanitize_key( $sizes[ $i ] ?? 'square' );
			$s['frames'][] = array(
				'attachment_id' => $aid,
				'name'          => sanitize_text_field( $names[ $i ] ?? '' ) ?: 'নিজের ফ্রেম',
				'size'          => in_array( $size, array( 'square', 'portrait', 'story', 'link' ), true ) ? $size : 'square',
			);
		}
		update_option( MEEHA_STUDIO_OPTION, wp_parse_args( $s, $old ), false );
		wp_safe_redirect( admin_url( 'admin.php?page=meeha-studio-settings&saved=1' ) );
		exit;
	}
);

/**
 * Hidden-input media picker row.
 *
 * @param string $name  Field name.
 * @param int    $id    Current attachment.
 * @param string $label Label.
 * @param string $hint  Hint text.
 */
function meeha_studio_media_field( $name, $id, $label, $hint = '' ) {
	$url = $id ? wp_get_attachment_image_url( $id, 'medium' ) : '';
	?>
	<div class="ms-media-field" data-ms-media>
		<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo (int) $id; ?>">
		<div class="ms-media-field__thumb"><?php if ( $url ) : ?><img src="<?php echo esc_url( $url ); ?>" alt=""><?php endif; ?></div>
		<div>
			<strong><?php echo esc_html( $label ); ?></strong>
			<?php if ( $hint ) : ?><p class="description"><?php echo esc_html( $hint ); ?></p><?php endif; ?>
			<button type="button" class="button" data-ms-pick>বাছুন</button>
			<button type="button" class="button-link-delete" data-ms-clear>সরান</button>
		</div>
	</div>
	<?php
}

/**
 * Settings screen.
 */
function meeha_studio_render_settings() {
	$s    = meeha_studio_settings();
	$cats = get_terms(
		array(
			'taxonomy'   => 'category',
			'hide_empty' => false,
		)
	);
	$sizes = array(
		'square'   => 'বর্গ ১০৮০×১০৮০',
		'portrait' => 'পোর্ট্রেট ১০৮০×১৩৫০',
		'story'    => 'স্টোরি ১০৮০×১৯২০',
		'link'     => 'লিংক ১২০০×৬৩০',
	);
	?>
	<div class="wrap ms-settings">
		<h1>গ্রাফিক্স স্টুডিও: সেটিংস</h1>
		<?php if ( ! empty( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<div class="notice notice-success is-dismissible"><p>সেটিংস সংরক্ষিত হয়েছে।</p></div>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'meeha_studio_save' ); ?>
			<input type="hidden" name="action" value="meeha_studio_save">

			<h2>ব্র্যান্ড</h2>
			<div class="ms-grid2">
				<?php meeha_studio_media_field( 'logo_id', $s['logo_id'], 'লোগো (হালকা ব্যাকগ্রাউন্ডের জন্য)', 'স্বচ্ছ PNG বা SVG নয়, PNG দিন। না দিলে নামের লেখা বসবে।' ); ?>
				<?php meeha_studio_media_field( 'logo_light_id', $s['logo_light_id'], 'সাদা লোগো (গাঢ় ব্যাকগ্রাউন্ডের জন্য)', 'গাঢ় কার্ডে এটি ব্যবহার হবে।' ); ?>
			</div>
			<table class="form-table" role="presentation">
				<tr><th><label for="ms-brand">ব্র্যান্ডের নাম</label></th><td><input id="ms-brand" class="regular-text" name="brand" value="<?php echo esc_attr( $s['brand'] ); ?>"><p class="description">লোগো না থাকলে এই নাম লেখা হিসেবে বসবে।</p></td></tr>
				<tr><th><label for="ms-site">ওয়েবসাইটের লেখা</label></th><td><input id="ms-site" class="regular-text" name="site_text" value="<?php echo esc_attr( $s['site_text'] ); ?>"></td></tr>
				<tr><th><label for="ms-hijri">হিজরি তারিখ সমন্বয়</label></th><td>
					<select id="ms-hijri" name="hijri_offset">
						<?php foreach ( array( -2, -1, 0, 1, 2 ) as $o ) : ?>
							<option value="<?php echo (int) $o; ?>" <?php selected( (int) $s['hijri_offset'], $o ); ?>><?php echo esc_html( ( $o > 0 ? '+' : '' ) . $o . ' দিন' ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description">উম্মুল কুরা ক্যালেন্ডারের সাথে বাংলাদেশের চাঁদ দেখার পার্থক্য মেলাতে। সাধারণত −১, তবে চাঁদ দেখা অনুযায়ী মিলিয়ে নিন।</p>
				</td></tr>
				<tr><th><label for="ms-pal">ডিফল্ট রং</label></th><td>
					<select id="ms-pal" name="default_palette">
						<option value="category" <?php selected( $s['default_palette'], 'category' ); ?>>ক্যাটেগরির রং</option>
						<?php foreach ( array( 'nil' => 'নীল (ব্র্যান্ড)', 'akash' => 'আকাশি', 'sobuj' => 'সবুজ', 'lal' => 'লাল', 'kamla' => 'কমলা', 'begun' => 'বেগুনি', 'golapi' => 'গোলাপি', 'sonali' => 'সোনালি' ) as $k => $v ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $s['default_palette'], $k ); ?>><?php echo esc_html( $v ); ?></option>
						<?php endforeach; ?>
					</select>
				</td></tr>
			</table>

			<h2>ক্যাটেগরির রং</h2>
			<p class="description">পোস্ট থেকে তথ্য আনলে কার্ড ওই পোস্টের ক্যাটেগরির রং নেবে। সব রং হালকা ও উজ্জ্বল রাখতে মাঝারি গাঢ় রং বাছুন।</p>
			<div class="ms-cat-colors">
				<?php foreach ( is_wp_error( $cats ) ? array() : $cats as $c ) : ?>
					<label><input type="color" name="cat_colors[<?php echo (int) $c->term_id; ?>]" value="<?php echo esc_attr( meeha_studio_cat_color( $c->term_id ) ); ?>"> <?php echo esc_html( $c->name ); ?></label>
				<?php endforeach; ?>
			</div>

			<h2>বিজ্ঞাপন স্ট্রিপ</h2>
			<p class="description">কার্ডের নিচে ঐচ্ছিক বিজ্ঞাপন। প্রতিটি মাপের জন্য আলাদা ছবি: চওড়া ১০৮০ (লিংকে ১২০০), উচ্চতা প্রায় ১২০ থেকে ১৩০ পিক্সেল।</p>
			<div class="ms-grid2">
				<?php foreach ( $sizes as $k => $label ) : ?>
					<?php meeha_studio_media_field( "ads[{$k}]", $s['ads'][ $k ], $label ); ?>
				<?php endforeach; ?>
			</div>

			<h2>নিজের ফ্রেম</h2>
			<p class="description">স্বচ্ছ PNG, যে মাপের জন্য বানানো ঠিক সেই মাপে। ফ্রেম ছবির ওপরে আর লেখার নিচে বসে। বিল্ট-ইন ১৫টা ফ্রেম আগে থেকেই আছে।</p>
			<table class="widefat ms-frames" data-ms-frames>
				<thead><tr><th>ফ্রেম</th><th>নাম</th><th>মাপ</th><th>রাখুন</th></tr></thead>
				<tbody>
				<?php foreach ( (array) $s['frames'] as $i => $f ) : ?>
					<tr>
						<td><input type="hidden" name="frame_id[<?php echo (int) $i; ?>]" value="<?php echo (int) $f['attachment_id']; ?>"><?php echo wp_get_attachment_image( $f['attachment_id'], 'thumbnail' ); ?></td>
						<td><input name="frame_name[<?php echo (int) $i; ?>]" value="<?php echo esc_attr( $f['name'] ); ?>"></td>
						<td><select name="frame_size[<?php echo (int) $i; ?>]"><?php foreach ( $sizes as $k => $label ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $f['size'], $k ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></td>
						<td><input type="checkbox" name="frame_keep[<?php echo (int) $i; ?>]" value="1" checked></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p><button type="button" class="button" data-ms-add-frame data-sizes="<?php echo esc_attr( wp_json_encode( $sizes ) ); ?>">+ নতুন ফ্রেম যোগ করুন</button></p>

			<?php submit_button( 'সংরক্ষণ করুন' ); ?>
		</form>
	</div>
	<?php
}

/**
 * Link to the studio from the post list rows.
 */
add_filter(
	'post_row_actions',
	function ( $actions, $post ) {
		if ( 'post' === $post->post_type && 'publish' === $post->post_status && current_user_can( meeha_studio_cap() ) ) {
			$actions['meeha_studio'] = '<a href="' . esc_url( admin_url( 'admin.php?page=meeha-studio&post=' . $post->ID ) ) . '">ফটোকার্ড</a>';
		}
		return $actions;
	},
	10,
	2
);
