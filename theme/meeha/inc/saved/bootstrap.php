<?php
/**
 * পরে পড়ুন (r46) — save stories for later without an account.
 * The list lives in the reader's own browser (localStorage `meeha_saved`); nothing is sent to the server except
 * the ids, when /saved/ asks GET meeha/v1/saved?ids=… for their cards.
 *  - a bookmark button on every story card (action `meeha_card_end`) and next to the share bar on articles (JS);
 *  - a header icon with the count (action `meeha_masthead_actions`);
 *  - the /saved/ page ([meeha_saved], created once, never overwritten).
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'meeha_reader_ensure_page' ) ) {
	/**
	 * Create a page once when its slug is free (any status, trash included). Never overwrites.
	 *
	 * @param string $slug    Slug.
	 * @param string $title   Title.
	 * @param string $content Content.
	 * @return int Page id created (0 when it existed or failed).
	 */
	function meeha_reader_ensure_page( $slug, $title, $content ) {
		$exists = get_posts(
			array(
				'post_type'      => 'page',
				'name'           => $slug,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		if ( $exists || get_page_by_path( $slug ) ) {
			return 0;
		}
		$id = wp_insert_post(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'post_title'     => $title,
				'post_name'      => $slug,
				'post_content'   => $content,
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			),
			true
		);
		return is_wp_error( $id ) ? 0 : (int) $id;
	}
}

/**
 * The reader pages (/tips/, /saved/). Runs from the foundation migration (`meeha_migrated`) and once on its own
 * (option `meeha_reader_pages_v`) so an existing install gets them without a theme-version bump.
 */
function meeha_reader_pages_ensure() {
	$made = array();
	if ( meeha_reader_ensure_page( 'saved', 'পরে পড়ুন', '[meeha_saved]' ) ) {
		$made[] = 'পরে পড়ুন';
	}
	if ( function_exists( 'meeha_tips_page_content' ) && meeha_reader_ensure_page( 'tips', 'গোপন তথ্য পাঠান', meeha_tips_page_content() ) ) {
		$made[] = 'গোপন তথ্য পাঠান';
	}
	update_option( 'meeha_reader_pages_v', '1', false );
	return $made;
}
add_action( 'meeha_migrated', 'meeha_reader_pages_ensure' );
add_action(
	'init',
	function () {
		if ( '1' !== get_option( 'meeha_reader_pages_v' ) && ! wp_installing() ) {
			meeha_reader_pages_ensure();
		}
	},
	100
);

/** Our shortcodes style themselves (normal container width, no .m-prose). */
add_filter(
	'meeha_app_page_shortcodes',
	function ( $codes ) {
		$codes[] = 'meeha_saved';
		$codes[] = 'meeha_tips';
		return $codes;
	}
);

/* ------------------------------------------------------------- markup */

/**
 * Bookmark SVG (fills when pressed via CSS).
 *
 * @return string
 */
function meeha_saved_svg() {
	return '<svg class="m-save__ic" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path d="M6 3.5h12a1 1 0 0 1 1 1V21l-7-4.5L5 21V4.5a1 1 0 0 1 1-1z"/></svg>';
}

/**
 * The save button for one post.
 *
 * @param WP_Post $post  Post.
 * @param string  $class Extra class.
 * @return string
 */
function meeha_saved_button( $post, $class = '' ) {
	return sprintf(
		'<button type="button" class="m-save%1$s" data-save="%2$d" aria-pressed="false" aria-label="%3$s" title="পরে পড়ুন" hidden>%4$s<span class="m-save__t">পরে পড়ুন</span></button>',
		$class ? ' ' . esc_attr( $class ) : '',
		(int) $post->ID,
		esc_attr( 'পরে পড়ুন: ' . wp_strip_all_tags( get_the_title( $post ) ) ),
		meeha_saved_svg()
	);
}

add_action(
	'meeha_card_end',
	function ( $post ) {
		if ( $post instanceof WP_Post && 'publish' === $post->post_status ) {
			echo meeha_saved_button( $post, 'm-save--card' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}
);

add_action(
	'meeha_masthead_actions',
	function () {
		printf(
			'<a class="m-iconbtn m-savedlink" href="%1$s" aria-label="পরে পড়ুন — সংরক্ষিত খবর" title="সংরক্ষিত খবর">%2$s<span class="m-savedlink__n" data-saved-count hidden></span></a>',
			esc_url( home_url( '/saved/' ) ),
			meeha_saved_svg() // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
	}
);

add_action(
	'wp_enqueue_scripts',
	function () {
		meeha_enqueue_bundle( 'reader' );
	},
	6
);

add_action(
	'wp_head',
	function () {
		printf(
			'<script>window.meehaSaved=%s;</script>' . "\n",
			wp_json_encode(
				array(
					'api'  => esc_url_raw( rest_url( 'meeha/v1/saved' ) ),
					'page' => esc_url_raw( home_url( '/saved/' ) ),
				),
				JSON_UNESCAPED_SLASHES
			)
		);
	},
	2
);

/* ------------------------------------------------------------- /saved/ */

add_shortcode(
	'meeha_saved',
	function () {
		return '<section class="m-savedpage" data-saved-page aria-live="polite">'
			. '<p class="m-savedpage__intro">যে খবরগুলো পরে পড়বেন বলে রেখেছেন, সেগুলো এখানে থাকে — শুধু এই ব্রাউজারে, কোনো অ্যাকাউন্ট লাগে না।</p>'
			. '<div class="m-savedpage__tools" hidden><button type="button" class="m-btn m-btn--ghost" data-saved-clear>সব মুছুন</button></div>'
			. '<div class="m-savedpage__list" data-saved-list><p class="m-savedpage__empty">লোড হচ্ছে…</p></div>'
			. '<noscript><p>এই পাতাটি দেখতে ব্রাউজারে জাভাস্ক্রিপ্ট চালু করুন।</p></noscript>'
			. '</section>';
	}
);

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'meeha/v1',
			'/saved',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'args'                => array(
					'ids' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
				'callback'            => function ( WP_REST_Request $r ) {
					$ids = array_slice( array_values( array_unique( array_filter( array_map( 'absint', explode( ',', (string) $r['ids'] ) ) ) ) ), 0, 100 );
					$out = array();
					if ( $ids ) {
						$q = new WP_Query(
							array(
								'post__in'            => $ids,
								'post_type'           => get_post_types( array( 'public' => true ) ),
								'post_status'         => 'publish',
								'has_password'        => false,
								'posts_per_page'      => 100,
								'orderby'             => 'post__in',
								'ignore_sticky_posts' => true,
								'no_found_rows'       => true,
							)
						);
						foreach ( $q->posts as $p ) {
							$out[] = array(
								'id'    => (int) $p->ID,
								'title' => wp_strip_all_tags( get_the_title( $p ) ),
								'url'   => get_permalink( $p ),
								'html'  => meeha_get_card(
									$p,
									array(
										'variant'       => 'sm rowm',
										'heading_level' => 2,
										'show_dek'      => true,
										'dek_words'     => 20,
									)
								),
							);
						}
					}
					$res = rest_ensure_response(
						array(
							'items'   => $out,
							'missing' => array_values( array_diff( $ids, wp_list_pluck( $out, 'id' ) ) ),
						)
					);
					$res->header( 'Cache-Control', 'public, max-age=300' );
					return $res;
				},
			)
		);
	}
);
