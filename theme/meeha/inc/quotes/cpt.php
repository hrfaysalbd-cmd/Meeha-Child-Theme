<?php
/**
 * CPT + taxonomy registration, frames, legacy URL redirects, type seeding.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

define( 'MEEHA_QUOTES_REWRITE_VER', '2' );

/**
 * The seven frames: id => [label, icon, swatch].
 *
 * @return array
 */
function meeha_quote_frames() {
	return array(
		'politics'   => array( 'রাজনীতি', 'column', '#0D2747' ),
		'faith'      => array( 'ধর্ম ও আধ্যাত্মিক', 'lotus', '#0E3A35' ),
		'literature' => array( 'সাহিত্য', 'feather', '#E9DFC8' ),
		'inspire'    => array( 'অনুপ্রেরণা', 'sparkle', '#F4A73B' ),
		'history'    => array( 'ইতিহাস', 'hourglass', '#C9B48A' ),
		'sports'     => array( 'খেলা', 'ball', '#11553A' ),
		'opinion'    => array( 'মতামত', 'quote', '#025AB2' ),
	);
}

add_action(
	'init',
	function () {
		register_post_type(
			'meeha_photocard',
			array(
				'labels'        => array(
					'name'               => 'উক্তি',
					'singular_name'      => 'উক্তি',
					'menu_name'          => 'উক্তি',
					'add_new'            => 'নতুন উক্তি',
					'add_new_item'       => 'নতুন উক্তি যোগ করুন',
					'edit_item'          => 'উক্তি সম্পাদনা',
					'new_item'           => 'নতুন উক্তি',
					'view_item'          => 'উক্তি দেখুন',
					'all_items'          => 'সব উক্তি',
					'search_items'       => 'উক্তি খুঁজুন',
					'not_found'          => 'কোনো উক্তি পাওয়া যায়নি',
					'not_found_in_trash' => 'ট্র্যাশে কোনো উক্তি নেই',
					'archives'           => 'উক্তি',
					'featured_image'     => 'বক্তার ছবি',
					'set_featured_image' => 'বক্তার ছবি দিন',
					'remove_featured_image' => 'বক্তার ছবি সরান',
					'use_featured_image' => 'বক্তার ছবি হিসেবে ব্যবহার করুন',
				),
				'public'        => true,
				'show_ui'       => true,
				'show_in_menu'  => true,
				'menu_icon'     => 'dashicons-format-quote',
				'menu_position' => 21,
				'supports'      => array( 'title', 'thumbnail', 'comments', 'excerpt' ),
				'has_archive'   => 'quote',
				'rewrite'       => array(
					'slug'       => 'quote',
					'with_front' => false,
				),
				'show_in_rest'  => false,
			)
		);
		register_taxonomy(
			'meeha_quote_type',
			'meeha_photocard',
			array(
				'labels'            => array(
					'name'          => 'উক্তির ধরন',
					'singular_name' => 'উক্তির ধরন',
					'add_new_item'  => 'নতুন ধরন যোগ করুন',
					'edit_item'     => 'ধরন সম্পাদনা',
					'all_items'     => 'সব ধরন',
					'menu_name'     => 'উক্তির ধরন',
				),
				'hierarchical'      => true,
				'public'            => true,
				'show_ui'           => true,
				'show_admin_column' => true,
				'rewrite'           => array(
					'slug'       => 'quote-type',
					'with_front' => false,
				),
			)
		);
		register_term_meta(
			'meeha_quote_type',
			'frame',
			array(
				'type'              => 'string',
				'single'            => true,
				'sanitize_callback' => 'meeha_quote_sanitize_frame',
			)
		);
		// Legacy URLs: /photocard/{slug}/ (singles) and /photocard/ (archive).
		add_rewrite_rule( '^photocard/page/([0-9]+)/?$', 'index.php?meeha_pc_legacy_archive=1&paged=$matches[1]', 'top' );
		add_rewrite_rule( '^photocard/?$', 'index.php?meeha_pc_legacy_archive=1', 'top' );
		add_rewrite_rule( '^photocard/([^/]+)/?$', 'index.php?meeha_pc_legacy=$matches[1]', 'top' );
	},
	5
);

/* One-time flush when our rewrite rules change. */
add_action(
	'init',
	function () {
		if ( get_option( 'meeha_quotes_rewrite_ver' ) !== MEEHA_QUOTES_REWRITE_VER ) {
			update_option( 'meeha_quotes_rewrite_ver', MEEHA_QUOTES_REWRITE_VER, true );
			flush_rewrite_rules( false );
		}
	},
	99
);

add_filter(
	'query_vars',
	function ( $v ) {
		$v[] = 'meeha_pc_legacy';
		$v[] = 'meeha_pc_legacy_archive';
		return $v;
	}
);

/**
 * Sanitize a frame id.
 *
 * @param string $v Value.
 * @return string
 */
function meeha_quote_sanitize_frame( $v ) {
	$v = sanitize_key( (string) $v );
	return array_key_exists( $v, meeha_quote_frames() ) ? $v : '';
}

/**
 * Resolve legacy /photocard/ requests: a page that owns the path wins; otherwise 301 to the quote URL.
 * Hooked on `request` so that a real page /photocard/ (live page 401 with [meeha_photocards]) keeps working.
 */
add_filter(
	'request',
	function ( $qv ) {
		if ( ! empty( $qv['meeha_pc_legacy_archive'] ) ) {
			$page = get_page_by_path( 'photocard' );
			if ( $page && 'publish' === $page->post_status ) {
				$out = array( 'pagename' => 'photocard' );
				if ( ! empty( $qv['paged'] ) ) {
					$out['paged'] = (int) $qv['paged'];
				}
				return $out;
			}
			$GLOBALS['meeha_pc_redirect'] = get_post_type_archive_link( 'meeha_photocard' );
			if ( ! empty( $qv['paged'] ) && (int) $qv['paged'] > 1 ) {
				$GLOBALS['meeha_pc_redirect'] = user_trailingslashit( trailingslashit( $GLOBALS['meeha_pc_redirect'] ) . 'page/' . (int) $qv['paged'] );
			}
			return $qv;
		}
		if ( ! empty( $qv['meeha_pc_legacy'] ) ) {
			$slug = sanitize_title( urldecode( (string) $qv['meeha_pc_legacy'] ) );
			$page = get_page_by_path( 'photocard/' . $slug );
			if ( $page && 'publish' === $page->post_status ) {
				return array( 'pagename' => 'photocard/' . $slug );
			}
			$q = get_posts(
				array(
					'post_type'      => 'meeha_photocard',
					'name'           => $slug,
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'no_found_rows'  => true,
				)
			);
			if ( ! $q && is_numeric( $slug ) ) {
				$p = get_post( (int) $slug );
				$q = ( $p && 'meeha_photocard' === $p->post_type && 'publish' === $p->post_status ) ? array( $p ) : array();
			}
			if ( $q ) {
				$GLOBALS['meeha_pc_redirect'] = get_permalink( $q[0] );
				return $qv;
			}
			return array( 'error' => '404' );
		}
		return $qv;
	}
);

add_action(
	'template_redirect',
	function () {
		if ( ! empty( $GLOBALS['meeha_pc_redirect'] ) ) {
			wp_safe_redirect( $GLOBALS['meeha_pc_redirect'], 301, 'Meeha' );
			exit;
		}
	},
	1
);

/* Old quote posts were created without comment support (closed): follow the quotes setting instead. */
add_filter(
	'comments_open',
	function ( $open, $post_id ) {
		$p = get_post( $post_id );
		if ( $p && 'meeha_photocard' === $p->post_type && 'publish' === $p->post_status && ! $open ) {
			return (bool) meeha_opt( 'quotes', 'comments_open', 1 );
		}
		return $open;
	},
	10,
	2
);

/**
 * Frame of a quote-type term (term meta `frame`; else guessed from the name/slug).
 *
 * @param WP_Term|null $term Term.
 * @return string
 */
function meeha_quote_frame_of_term( $term ) {
	if ( ! $term instanceof WP_Term ) {
		return 'opinion';
	}
	$f = (string) get_term_meta( $term->term_id, 'frame', true );
	if ( array_key_exists( $f, meeha_quote_frames() ) ) {
		return $f;
	}
	$hay = $term->slug . ' ' . $term->name;
	$map = array(
		'politics'   => array( 'politic', 'রাজনী', 'রাজনৈ' ),
		'faith'      => array( 'faith', 'relig', 'ধর্ম', 'আধ্যাত্ম' ),
		'literature' => array( 'literat', 'সাহিত্য', 'কবিতা' ),
		'inspire'    => array( 'inspir', 'motiv', 'অনুপ্রেরণা', 'মোটিভেশ', 'প্রেরণা' ),
		'history'    => array( 'histor', 'ইতিহাস' ),
		'sports'     => array( 'sport', 'খেলা', 'ক্রীড়া' ),
		'opinion'    => array( 'opinion', 'মতামত' ),
	);
	foreach ( $map as $frame => $needles ) {
		foreach ( $needles as $n ) {
			if ( false !== mb_stripos( $hay, $n ) ) {
				return $frame;
			}
		}
	}
	return 'opinion';
}

/**
 * Seed the seven quote types once (non-destructive): existing রাজনৈতিক উক্তি → politics, মোটিভেশনাল উক্তি → inspire
 * (frames set, names untouched); missing types are created with ASCII slugs.
 *
 * @param bool $force Run even if the flag is set.
 * @return array created names
 */
function meeha_quote_seed_types( $force = false ) {
	if ( ! $force && get_option( 'meeha_quote_types_seeded_v2' ) ) {
		return array();
	}
	if ( ! taxonomy_exists( 'meeha_quote_type' ) ) {
		return array();
	}
	$created = array();
	$terms   = get_terms(
		array(
			'taxonomy'   => 'meeha_quote_type',
			'hide_empty' => false,
		)
	);
	$covered = array();
	foreach ( is_wp_error( $terms ) ? array() : $terms as $t ) {
		$legacy = array(
			'রাজনৈতিক উক্তি'   => 'politics',
			'মোটিভেশনাল উক্তি' => 'inspire',
		);
		$f      = (string) get_term_meta( $t->term_id, 'frame', true );
		if ( '' === $f ) {
			$f = isset( $legacy[ $t->name ] ) ? $legacy[ $t->name ] : meeha_quote_frame_of_term( $t );
			update_term_meta( $t->term_id, 'frame', $f );
		}
		$covered[ $f ] = true;
	}
	$slugs = array(
		'politics'   => 'politics',
		'faith'      => 'faith',
		'literature' => 'literature',
		'inspire'    => 'inspire',
		'history'    => 'history',
		'sports'     => 'sports',
		'opinion'    => 'opinion',
	);
	foreach ( meeha_quote_frames() as $fid => $def ) {
		if ( isset( $covered[ $fid ] ) ) {
			continue;
		}
		$exists = term_exists( $def[0], 'meeha_quote_type' );
		if ( ! $exists ) {
			$exists = wp_insert_term( $def[0], 'meeha_quote_type', array( 'slug' => $slugs[ $fid ] ) );
			if ( is_wp_error( $exists ) ) {
				continue;
			}
			$created[] = $def[0];
		}
		update_term_meta( (int) $exists['term_id'], 'frame', $fid );
	}
	update_option( 'meeha_quote_types_seeded_v2', 1, true );
	return $created;
}
add_action(
	'init',
	function () {
		if ( ! get_option( 'meeha_quote_types_seeded_v2' ) && ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) ) {
			meeha_quote_seed_types();
		}
	},
	20
);
add_action( 'after_switch_theme', 'meeha_quote_seed_types', 30 );

/* ---------- term edit UI: frame picker with swatches ---------- */

/**
 * Frame radio picker markup.
 *
 * @param string $current Current frame.
 * @return string
 */
function meeha_quote_frame_picker( $current ) {
	$out = '<fieldset class="meeha-frames" style="display:flex;flex-wrap:wrap;gap:8px">';
	foreach ( meeha_quote_frames() as $id => $f ) {
		$out .= sprintf(
			'<label style="display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border:1px solid #c3c4c7;border-radius:6px;background:#fff;cursor:pointer"><input type="radio" name="meeha_frame" value="%1$s"%2$s><span aria-hidden="true" style="display:inline-block;width:22px;height:22px;border-radius:4px;background:%3$s;border:1px solid rgba(0,0,0,.15)"></span>%4$s</label>',
			esc_attr( $id ),
			checked( $current, $id, false ),
			esc_attr( $f[2] ),
			esc_html( $f[0] )
		);
	}
	return $out . '</fieldset>';
}

add_action(
	'meeha_quote_type_add_form_fields',
	function () {
		wp_nonce_field( 'meeha_quote_frame', 'meeha_frame_nonce' );
		echo '<div class="form-field"><label>ফ্রেম</label>' . meeha_quote_frame_picker( 'opinion' ) . '<p>উক্তির কার্ড ও ফটোকার্ড স্টুডিওতে এই ফ্রেম ব্যবহৃত হবে।</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
);
add_action(
	'meeha_quote_type_edit_form_fields',
	function ( $term ) {
		wp_nonce_field( 'meeha_quote_frame', 'meeha_frame_nonce' );
		echo '<tr class="form-field"><th scope="row">ফ্রেম</th><td>' . meeha_quote_frame_picker( meeha_quote_frame_of_term( $term ) ) . '<p class="description">উক্তির কার্ড ও ফটোকার্ড স্টুডিওতে এই ফ্রেম ব্যবহৃত হবে।</p></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
);
/**
 * Save the frame term meta.
 *
 * @param int $term_id Term.
 */
function meeha_quote_save_frame( $term_id ) {
	if ( ! isset( $_POST['meeha_frame_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['meeha_frame_nonce'] ) ), 'meeha_quote_frame' ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_categories' ) ) {
		return;
	}
	$f = isset( $_POST['meeha_frame'] ) ? meeha_quote_sanitize_frame( wp_unslash( $_POST['meeha_frame'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	if ( '' !== $f ) {
		update_term_meta( $term_id, 'frame', $f );
	}
}
add_action( 'created_meeha_quote_type', 'meeha_quote_save_frame' );
add_action( 'edited_meeha_quote_type', 'meeha_quote_save_frame' );

/* Admin list column with the swatch. */
add_filter(
	'manage_edit-meeha_quote_type_columns',
	function ( $c ) {
		$c['meeha_frame'] = 'ফ্রেম';
		return $c;
	}
);
add_filter(
	'manage_meeha_quote_type_custom_column',
	function ( $out, $col, $term_id ) {
		if ( 'meeha_frame' !== $col ) {
			return $out;
		}
		$f      = meeha_quote_frame_of_term( get_term( $term_id, 'meeha_quote_type' ) );
		$frames = meeha_quote_frames();
		return sprintf( '<span style="display:inline-block;width:14px;height:14px;border-radius:3px;vertical-align:middle;background:%s"></span> %s', esc_attr( $frames[ $f ][2] ), esc_html( $frames[ $f ][0] ) );
	},
	10,
	3
);
