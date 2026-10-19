<?php
/**
 * Settings tab `quotes` (উক্তি). Legacy `meeha_photocard_settings.poll_duration_days` is imported once
 * (meeha_d_import_legacy() in inc/single/settings.php).
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

meeha_register_settings_tab(
	'quotes',
	array(
		'title'    => 'উক্তি',
		'order'    => 42,
		'cap'      => 'design',
		'desc'     => 'উক্তির আর্কাইভ, একক পাতা, হোমপেজের উক্তি-রেল ও ভোট।',
		'fields'   => array(
			array( 'key' => 'archive_intro', 'type' => 'textarea', 'label' => 'আর্কাইভের ভূমিকা', 'default' => 'রাজনীতি থেকে সাহিত্য, যাঁদের কথা সময়কে নাড়া দেয়। প্রতিটি উক্তির উৎস যাচাই করে প্রকাশ করা হয়।' ),
			array( 'key' => 'per_page', 'type' => 'number', 'label' => 'আর্কাইভে প্রতি পাতায়', 'default' => 12, 'min' => 4, 'max' => 48 ),
			array( 'key' => 'home_count', 'type' => 'number', 'label' => 'হোমপেজ রেলে মোট', 'default' => 8, 'min' => 3, 'max' => 16 ),
			array( 'key' => 'home_max_per_type', 'type' => 'number', 'label' => 'হোমপেজে প্রতি ধরনের সর্বোচ্চ', 'default' => 2, 'min' => 1, 'max' => 8 ),
			array( 'key' => 'poll_days', 'type' => 'number', 'label' => 'ভোটের ডিফল্ট মেয়াদ (দিন)', 'default' => 7, 'min' => 1, 'max' => 365, 'desc' => 'উক্তিতে আলাদা মেয়াদ না দিলে এটি প্রযোজ্য। ফলাফল মেয়াদ শেষে প্রকাশ হয়।' ),
			array( 'key' => 'poll_question', 'type' => 'text', 'label' => 'ভোটের প্রশ্ন', 'default' => 'আপনি কি এই কথার সঙ্গে একমত?' ),
			array( 'key' => 'comments_open', 'type' => 'checkbox', 'label' => 'উক্তিতে মন্তব্য চালু', 'default' => 1, 'desc' => 'পুরোনো উক্তিগুলো মন্তব্য-সমর্থন ছাড়া তৈরি হয়েছিল; এটি চালু থাকলে সেগুলোতেও মন্তব্য করা যাবে।' ),
			array( 'type' => 'heading', 'label' => 'ধরন ↔ ফ্রেম' ),
		),
		'render'   => 'meeha_quotes_settings_render',
		'sanitize' => 'meeha_quotes_settings_sanitize',
	)
);

/**
 * Frame mapping table (writes term meta `frame`).
 *
 * @param string $tab    Tab.
 * @param array  $values Values.
 * @param array  $ctx    Context.
 */
function meeha_quotes_settings_render( $tab, $values, $ctx ) {
	$terms = get_terms(
		array(
			'taxonomy'   => 'meeha_quote_type',
			'hide_empty' => false,
		)
	);
	if ( is_wp_error( $terms ) || ! $terms ) {
		echo '<p>এখনো কোনো উক্তির ধরন নেই।</p>';
		return;
	}
	$dis = $ctx['can_edit'] ? '' : ' disabled';
	echo '<table class="widefat striped" style="max-width:640px"><thead><tr><th>ধরন</th><th>উক্তি</th><th>ফ্রেম</th></tr></thead><tbody>';
	foreach ( $terms as $t ) {
		$cur = meeha_quote_frame_of_term( $t );
		echo '<tr><td><a href="' . esc_url( get_edit_term_link( $t->term_id, 'meeha_quote_type' ) ) . '">' . esc_html( $t->name ) . '</a></td><td>' . esc_html( meeha_bn_digits( $t->count ) ) . '</td><td><select name="' . esc_attr( $ctx['name'] ) . '[frames][' . (int) $t->term_id . ']"' . $dis . '>'; // phpcs:ignore WordPress.Security.EscapeOutput
		foreach ( meeha_quote_frames() as $id => $f ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $id ), selected( $cur, $id, false ), esc_html( $f[0] ) );
		}
		echo '</select></td></tr>';
	}
	echo '</tbody></table>';
}

/**
 * Persist the frame mapping into term meta (not into the option).
 *
 * @param array $clean Clean.
 * @param array $raw   Raw.
 * @param array $old   Old.
 * @return array
 */
function meeha_quotes_settings_sanitize( $clean, $raw, $old ) {
	if ( ! empty( $raw['frames'] ) && is_array( $raw['frames'] ) && current_user_can( 'manage_categories' ) ) {
		foreach ( $raw['frames'] as $tid => $f ) {
			$f = meeha_quote_sanitize_frame( $f );
			if ( '' !== $f && term_exists( (int) $tid, 'meeha_quote_type' ) ) {
				update_term_meta( (int) $tid, 'frame', $f );
			}
		}
	}
	unset( $clean['frames'] );
	return $clean;
}
