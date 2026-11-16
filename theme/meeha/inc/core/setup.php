<?php
/**
 * Theme supports, menu locations, image sizes, content width.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

if ( ! isset( $content_width ) ) {
	$content_width = 800; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
}

add_action(
	'after_setup_theme',
	function () {
		load_theme_textdomain( 'meeha', MEEHA_DIR . '/languages' );

		add_theme_support( 'title-tag' );
		add_theme_support( 'post-thumbnails' );
		add_theme_support( 'automatic-feed-links' );
		add_theme_support( 'responsive-embeds' );
		add_theme_support( 'align-wide' );
		add_theme_support( 'editor-styles' );
		add_theme_support( 'post-formats', array( 'aside' ) );
		add_theme_support(
			'custom-logo',
			array(
				'height'      => 60,
				'width'       => 220,
				'flex-height' => true,
				'flex-width'  => true,
			)
		);
		add_theme_support( 'html5', array( 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script', 'navigation-widgets' ) );
		remove_theme_support( 'core-block-patterns' );

		if ( file_exists( MEEHA_DIR . '/assets/dist/editor.min.css' ) ) {
			add_editor_style( 'assets/dist/editor.min.css' );
		}

		register_nav_menus(
			array(
				'primary'     => 'প্রধান মেনু (হেডার)',
				'drawer'      => 'ড্রয়ার — সব বিভাগ',
				'footer-1'    => 'ফুটার কলাম ১',
				'footer-2'    => 'ফুটার কলাম ২',
				'footer-3'    => 'ফুটার কলাম ৩',
				'bottom-menu' => 'ফুটারের নিচের লিংক (আইনি)',
			)
		);

		set_post_thumbnail_size( 768, 432, true );
		add_image_size( 'meeha-16x9', 1200, 675, true );
		add_image_size( 'meeha-16x9-m', 768, 432, true );
		add_image_size( 'meeha-16x9-s', 480, 270, true );
		add_image_size( 'meeha-sq', 400, 400, true );
		add_image_size( 'meeha-9x16', 540, 960, true );
	}
);

add_filter(
	'image_size_names_choose',
	function ( $sizes ) {
		return array_merge(
			$sizes,
			array(
				'meeha-16x9'   => '১৬:৯ বড় (১২০০)',
				'meeha-16x9-m' => '১৬:৯ মাঝারি (৭৬৮)',
				'meeha-sq'     => 'বর্গ (৪০০)',
			)
		);
	}
);

add_filter(
	'excerpt_more',
	function () {
		return '…';
	}
);

/**
 * `<html>` language: always Bengali for the front end.
 */
add_filter(
	'language_attributes',
	function ( $out ) {
		if ( is_admin() ) {
			return $out;
		}
		return preg_match( '/lang="/', $out ) ? preg_replace( '/lang="[^"]*"/', 'lang="bn"', $out ) : $out . ' lang="bn"';
	}
);

/**
 * Archive titles without the "Category:" prefix.
 */
add_filter(
	'get_the_archive_title_prefix',
	'__return_empty_string'
);

/**
 * Document title separator.
 */
add_filter(
	'document_title_separator',
	function () {
		return '|';
	}
);

/**
 * Page option "শিরোনাম লুকান" (`_meeha_hide_title`).
 */
add_action(
	'init',
	function () {
		register_post_meta(
			'page',
			'_meeha_hide_title',
			array(
				'type'          => 'boolean',
				'single'        => true,
				'show_in_rest'  => true,
				'default'       => false,
				'auth_callback' => function ( $allowed, $key, $post_id ) {
					return current_user_can( 'edit_post', $post_id );
				},
			)
		);
	}
);

add_action(
	'add_meta_boxes_page',
	function () {
		add_meta_box(
			'meeha-page-options',
			'পাতার বিকল্প',
			function ( $post ) {
				wp_nonce_field( 'meeha_page_options', 'meeha_page_options_nonce' );
				printf(
					'<label><input type="checkbox" name="meeha_hide_title" value="1"%s> শিরোনাম লুকান</label><p class="description">শর্টকোড নিজেই শিরোনাম দেখালে (যেমন সাহিত্য পাতা) টিক দিন।</p>',
					checked( (bool) get_post_meta( $post->ID, '_meeha_hide_title', true ), true, false )
				);
			},
			'page',
			'side'
		);
	}
);

add_action(
	'save_post_page',
	function ( $post_id ) {
		if ( ! isset( $_POST['meeha_page_options_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['meeha_page_options_nonce'] ) ), 'meeha_page_options' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( ! empty( $_POST['meeha_hide_title'] ) ) {
			update_post_meta( $post_id, '_meeha_hide_title', 1 );
		} else {
			delete_post_meta( $post_id, '_meeha_hide_title' );
		}
	}
);

/**
 * Round 12 — "হাইলাইট বক্স" block style on core/quote (the apps write
 * <blockquote class="wp-block-quote is-style-meeha-highlight">), so wp-admin shows and offers it too.
 */
add_action(
	'init',
	function () {
		if ( function_exists( 'register_block_style' ) ) {
			register_block_style( 'core/quote', array( 'name' => 'meeha-highlight', 'label' => 'হাইলাইট বক্স' ) );
		}
	}
);
