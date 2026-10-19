<?php
/**
 * Settings tab `literature` (সাহিত্য-সংস্কৃতি): page intro, genre list (order, on/off, label, intro, category,
 * count, layout, colour, icon, "সব দেখুন" target), homepage teaser, submit CTA, and the
 * "ধরন অনুযায়ী ক্যাটেগরি তৈরি করুন" button.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

meeha_register_settings_tab(
	'literature',
	array(
		'title'    => 'সাহিত্য-সংস্কৃতি',
		'order'    => 40,
		'cap'      => 'design',
		'desc'     => '[meeha_sahitya] পাতা (পাতা — পূর্ণ প্রস্থ টেমপ্লেটে, "শিরোনাম লুকান" চালু রেখে), হোমপেজের সাহিত্য অংশ ও সাহিত্যের একক পাতা।',
		'fields'   => array(
			array( 'type' => 'heading', 'label' => 'সাহিত্য পাতা' ),
			array( 'key' => 'page_title', 'type' => 'text', 'label' => 'শিরোনাম', 'default' => 'বাঙালি সাহিত্য-সংস্কৃতি' ),
			array( 'key' => 'intro', 'type' => 'textarea', 'label' => 'ভূমিকা', 'default' => 'কবিতা, গল্প, প্রবন্ধ আর লোকজ ঐতিহ্যের পাতা। খবরের ভিড়ের বাইরে একটু ধীরে পড়ার জায়গা।' ),
			array( 'key' => 'submit_title', 'type' => 'text', 'label' => 'লেখা পাঠানোর অংশ — শিরোনাম', 'default' => 'আপনার লেখা পাঠান' ),
			array( 'key' => 'submit_text', 'type' => 'textarea', 'label' => 'লেখা পাঠানোর অংশ — লেখা', 'default' => 'অপ্রকাশিত কবিতা, গল্প বা প্রবন্ধ পাঠাতে পারেন। নির্বাচিত লেখা সম্পাদনার পর প্রকাশ হবে এবং লেখককে জানানো হবে।' ),
			array( 'key' => 'submit_label', 'type' => 'text', 'label' => 'বোতামের লেখা', 'default' => 'লেখা পাঠানোর নিয়ম' ),
			array( 'key' => 'submit_url', 'type' => 'url', 'label' => 'বোতামের লিংক', 'default' => '', 'desc' => 'খালি থাকলে সংবাদদাতা অ্যাপের "খবর পাঠান" পাতা, না থাকলে "যোগাযোগ" পাতা।' ),
			array( 'type' => 'heading', 'label' => 'হোমপেজের সাহিত্য অংশ (S11)' ),
			array( 'key' => 'home_title', 'type' => 'text', 'label' => 'শিরোনাম', 'default' => 'বাঙালি সাহিত্য-সংস্কৃতি' ),
			array( 'key' => 'home_cat', 'type' => 'category', 'label' => 'উৎস বিভাগ', 'default' => 0, 'desc' => 'খালি = বাঙালি সাহিত্য-সংস্কৃতি (সব উপবিভাগসহ)।' ),
			array( 'key' => 'home_count', 'type' => 'number', 'label' => 'কবিতার পাশে তালিকায় কয়টি', 'default' => 3, 'min' => 1, 'max' => 6 ),
			array( 'key' => 'home_url', 'type' => 'url', 'label' => '"সাহিত্য পাতা" লিংক', 'default' => '', 'desc' => 'খালি থাকলে [meeha_sahitya] থাকা পাতা।' ),
			array( 'type' => 'heading', 'label' => 'ধরন (জনরা)' ),
		),
		'render'   => 'meeha_lit_settings_render',
		'sanitize' => 'meeha_lit_settings_sanitize',
	)
);

/**
 * Custom UI: sortable genre rows + category creator button.
 *
 * @param string $tab    Tab id.
 * @param array  $values Current values.
 * @param array  $ctx    tab, name, can_edit.
 */
function meeha_lit_settings_render( $tab, $values, $ctx ) {
	$name    = $ctx['name'] . '[genres]';
	$dis     = $ctx['can_edit'] ? '' : ' disabled';
	$layouts = meeha_lit_layouts();
	echo '<p class="description">ক্রম বদলাতে টেনে সরান বা ↑ ↓ চাপুন। কোনো ধরনের লেখা না থাকলে সাহিত্য পাতায় সেটি ও তার চিপ দেখাবে না।</p>';
	echo '<ol class="meeha-list meeha-sortable meeha-genres" data-meeha-sortable>';
	$i = 0;
	foreach ( meeha_lit_genres() as $gid => $g ) {
		$b = $name . '[' . $i . ']';
		echo '<li><div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:6px 12px;flex:1">';
		printf( '<input type="hidden" name="%s[id]" value="%s"%s>', esc_attr( $b ), esc_attr( $gid ), $dis ); // phpcs:ignore WordPress.Security.EscapeOutput
		printf( '<label><input type="checkbox" name="%s[enabled]" value="1"%s%s> <strong>চালু</strong> <code>%s</code></label>', esc_attr( $b ), checked( ! empty( $g['enabled'] ), true, false ), $dis, esc_html( $gid ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		printf( '<label>নাম<br><input type="text" class="regular-text" style="width:100%%" name="%s[label]" value="%s"%s></label>', esc_attr( $b ), esc_attr( $g['label'] ), $dis ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<label>বিভাগ<br>';
		wp_dropdown_categories(
			array(
				'name'             => $b . '[cat]',
				'selected'         => (int) $g['cat'],
				'show_option_none' => '— বেছে নিন —',
				'option_none_value' => 0,
				'hide_empty'       => 0,
				'hierarchical'     => 1,
				'class'            => '',
			)
		);
		echo '</label>';
		echo '<label>বিন্যাস<br><select name="' . esc_attr( $b ) . '[layout]"' . $dis . '>'; // phpcs:ignore WordPress.Security.EscapeOutput
		foreach ( $layouts as $k => $l ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $g['layout'], $k, false ), esc_html( $l ) );
		}
		echo '</select></label>';
		printf( '<label>কয়টি<br><input type="number" min="1" max="12" class="small-text" name="%s[count]" value="%d"%s></label>', esc_attr( $b ), (int) $g['count'], $dis ); // phpcs:ignore WordPress.Security.EscapeOutput
		printf( '<label>রং<br><input type="color" name="%s[color]" value="%s"%s></label>', esc_attr( $b ), esc_attr( $g['color'] ), $dis ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<label>আইকন<br><select name="' . esc_attr( $b ) . '[icon]"' . $dis . '>'; // phpcs:ignore WordPress.Security.EscapeOutput
		foreach ( meeha_lit_icons() as $ic ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $ic ), selected( $g['icon'], $ic, false ), esc_html( $ic ) );
		}
		echo '</select></label>';
		$stored_more = '';
		foreach ( (array) meeha_opt( 'literature', 'genres', array() ) as $row ) {
			if ( isset( $row['id'], $row['more_url'] ) && $row['id'] === $gid ) {
				$stored_more = $row['more_url'];
			}
		}
		printf( '<label>"সব দেখুন" লিংক<br><input type="url" style="width:100%%" placeholder="বিভাগের পাতা" name="%s[more_url]" value="%s"%s></label>', esc_attr( $b ), esc_attr( $stored_more ), $dis ); // phpcs:ignore WordPress.Security.EscapeOutput
		printf( '<label style="grid-column:1/-1">ভূমিকা<br><input type="text" style="width:100%%" name="%s[intro]" value="%s"%s></label>', esc_attr( $b ), esc_attr( $g['intro'] ), $dis ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</div>';
		if ( $ctx['can_edit'] ) {
			echo meeha_settings_row_buttons( false ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</li>';
		$i++;
	}
	echo '</ol>';
	if ( $ctx['can_edit'] ) {
		wp_nonce_field( 'meeha_lit_create_cats', 'meeha_lit_cats_nonce' );
		echo '<p><button type="submit" class="button" name="meeha_lit_create_cats" value="1">ধরন অনুযায়ী ক্যাটেগরি তৈরি করুন</button> ';
		echo '<span class="description">বাঙালি সাহিত্য-সংস্কৃতির নিচে যে ধরনের বিভাগ নেই তা তৈরি করে (কবিতা, গল্প, প্রবন্ধ, বই আলোচনা, অনুবাদ, সাক্ষাৎকার → সাহিত্যলোকের নিচে; লোকসংস্কৃতি → সরাসরি)। আগের কোনো বিভাগ বদলায় বা মোছে না।</span></p>';
	}
}

/**
 * Sanitize the genre rows (tab-level sanitize callback).
 *
 * @param array $clean Clean values so far.
 * @param array $raw   Raw posted.
 * @param array $old   Old stored.
 * @return array
 */
function meeha_lit_settings_sanitize( $clean, $raw, $old ) {
	$defs = meeha_lit_genre_defaults();
	$rows = array();
	$seen = array();
	foreach ( (array) ( isset( $raw['genres'] ) ? $raw['genres'] : array() ) as $r ) {
		$id = isset( $r['id'] ) ? sanitize_key( $r['id'] ) : '';
		if ( '' === $id || ! isset( $defs[ $id ] ) || isset( $seen[ $id ] ) ) {
			continue;
		}
		$seen[ $id ] = true;
		$cat         = isset( $r['cat'] ) ? absint( $r['cat'] ) : 0;
		$color       = isset( $r['color'] ) ? sanitize_hex_color( $r['color'] ) : '';
		$layout      = isset( $r['layout'] ) ? sanitize_key( $r['layout'] ) : '';
		$icon        = isset( $r['icon'] ) ? sanitize_key( $r['icon'] ) : '';
		$rows[]      = array(
			'id'       => $id,
			'enabled'  => empty( $r['enabled'] ) ? 0 : 1,
			'label'    => isset( $r['label'] ) && '' !== trim( $r['label'] ) ? sanitize_text_field( $r['label'] ) : $defs[ $id ]['label'],
			'intro'    => isset( $r['intro'] ) ? sanitize_text_field( $r['intro'] ) : '',
			'cat'      => ( $cat && term_exists( $cat, 'category' ) ) ? $cat : 0,
			'count'    => isset( $r['count'] ) ? max( 1, min( 12, absint( $r['count'] ) ) ) : $defs[ $id ]['count'],
			'layout'   => array_key_exists( $layout, meeha_lit_layouts() ) ? $layout : $defs[ $id ]['layout'],
			'color'    => $color ? $color : $defs[ $id ]['color'],
			'icon'     => in_array( $icon, meeha_lit_icons(), true ) ? $icon : $defs[ $id ]['icon'],
			'more_url' => isset( $r['more_url'] ) ? esc_url_raw( trim( $r['more_url'] ) ) : '',
		);
	}
	if ( $rows ) {
		$clean['genres'] = $rows;
	} elseif ( isset( $old['genres'] ) ) {
		$clean['genres'] = $old['genres'];
	}
	unset( $GLOBALS['meeha_lit_genres_cache'] );
	return $clean;
}

/*
 * "ধরন অনুযায়ী ক্যাটেগরি তৈরি করুন": a submit button inside the settings form (A's save handler runs first and
 * stores the genre rows; then the creator maps the categories and reports through A's notices).
 */
add_action(
	'meeha_settings_saved',
	function ( $tab ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked below (own nonce; A already verified the tab nonce).
		if ( 'literature' !== $tab || empty( $_POST['meeha_lit_create_cats'] ) ) {
			return;
		}
		if ( ! isset( $_POST['meeha_lit_cats_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['meeha_lit_cats_nonce'] ) ), 'meeha_lit_create_cats' ) || ! current_user_can( 'manage_categories' ) ) {
			meeha_settings_notice( 'ক্যাটেগরি তৈরির অনুমতি নেই।', 'error' );
			return;
		}
		$res = meeha_lit_create_genre_categories();
		$msg = $res['error'] ? 'ত্রুটি: ' . $res['error'] : ( $res['created'] ? 'তৈরি হয়েছে: ' . implode( ', ', $res['created'] ) . '। সব ধরন বিভাগের সঙ্গে যুক্ত।' : 'নতুন কিছু তৈরি করতে হয়নি — সব ধরন আগে থেকেই বিভাগের সঙ্গে যুক্ত।' );
		meeha_settings_notice( $msg, $res['error'] ? 'error' : 'success' );
	}
);
