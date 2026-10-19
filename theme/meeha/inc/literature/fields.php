<?php
/**
 * "সাহিত্য তথ্য" meta box for posts in the বাঙালি সাহিত্য-সংস্কৃতি tree. Meta keys `_meeha_lit_*`.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

/**
 * Field definitions: key => [label, type, genre (or '' = all), extra].
 *
 * @return array
 */
function meeha_lit_fields() {
	return array(
		'poet'        => array( 'কবির নাম (লেখক ভিন্ন হলে)', 'text', 'poem' ),
		'collection'  => array( 'কাব্যগ্রন্থ / বই', 'text', 'poem' ),
		'place_date'  => array( 'রচনার স্থান ও তারিখ', 'text', 'poem' ),
		'center'      => array( 'পঙ্‌ক্তি মাঝখানে সাজান', 'checkbox', 'poem' ),
		'recite_url'  => array( 'আবৃত্তি — অডিও ফাইল বা ইউটিউব লিংক', 'url', 'poem' ),
		'reciter'     => array( 'আবৃত্তিকার', 'text', 'poem' ),
		'series'      => array( 'ধারাবাহিক / সিরিজের নাম', 'text', 'story' ),
		'part'        => array( 'পর্ব নম্বর', 'number', 'story' ),
		'story_type'  => array( 'ধরন (ছোটগল্প / উপন্যাস / চিঠি)', 'text', 'story' ),
		'book_title'  => array( 'বইয়ের নাম', 'text', 'books' ),
		'book_author' => array( 'বইয়ের লেখক', 'text', 'books' ),
		'publisher'   => array( 'প্রকাশক', 'text', 'books' ),
		'year'        => array( 'প্রকাশকাল (সাল)', 'text', 'books' ),
		'pages'       => array( 'পৃষ্ঠা', 'number', 'books' ),
		'isbn'        => array( 'ISBN', 'text', 'books' ),
		'price'       => array( 'মূল্য (টাকা)', 'text', 'books' ),
		'rating'      => array( 'রেটিং (১–৫)', 'rating', 'books' ),
		'cover'       => array( 'প্রচ্ছদের ছবি (ঐচ্ছিক)', 'image', 'books' ),
		'orig_author' => array( 'মূল লেখক', 'text', 'translation' ),
		'orig_lang'   => array( 'মূল ভাষা', 'text', 'translation' ),
		'orig_title'  => array( 'মূল শিরোনাম', 'text', 'translation' ),
		'translator'  => array( 'অনুবাদক', 'text', 'translation' ),
		'interviewee' => array( 'সাক্ষাৎকারদাতা', 'text', 'interview' ),
		'designation' => array( 'পরিচয় / পদবি', 'text', 'interview' ),
		'pullquote'   => array( 'উদ্ধৃতি (তালিকায় দেখাবে)', 'textarea', 'interview' ),
		'region'      => array( 'অঞ্চল / জেলা', 'text', 'folk' ),
		'era'         => array( 'কাল / সাল', 'text', 'history' ),
	);
}

/**
 * Read one literature field.
 *
 * @param WP_Post|int $post Post.
 * @param string      $key  Key without prefix.
 * @return string
 */
function meeha_lit_field( $post, $key ) {
	$post = get_post( $post );
	return $post ? trim( (string) get_post_meta( $post->ID, '_meeha_lit_' . $key, true ) ) : '';
}

/**
 * Sanitize one field value.
 *
 * @param string $type  Type.
 * @param mixed  $value Raw.
 * @return string|int
 */
function meeha_lit_sanitize_field( $type, $value ) {
	switch ( $type ) {
		case 'checkbox':
			return empty( $value ) ? '' : '1';
		case 'url':
			return esc_url_raw( trim( (string) $value ) );
		case 'number':
			$v = absint( meeha_en_digits( (string) $value ) );
			return $v ? (string) $v : '';
		case 'rating':
			$v = absint( meeha_en_digits( (string) $value ) );
			return $v ? (string) max( 1, min( 5, $v ) ) : '';
		case 'image':
			$v = absint( $value );
			return ( $v && wp_attachment_is_image( $v ) ) ? (string) $v : '';
		case 'textarea':
			return sanitize_textarea_field( (string) $value );
		default:
			return sanitize_text_field( (string) $value );
	}
}

add_action(
	'init',
	function () {
		$auth = function ( $allowed, $meta_key, $post_id ) {
			return current_user_can( 'edit_post', $post_id );
		};
		register_post_meta( 'post', '_meeha_lit_genre', array( 'type' => 'string', 'single' => true, 'show_in_rest' => true, 'auth_callback' => $auth, 'sanitize_callback' => 'sanitize_key' ) );
		foreach ( meeha_lit_fields() as $k => $f ) {
			$type = $f[1];
			register_post_meta(
				'post',
				'_meeha_lit_' . $k,
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => true,
					'auth_callback'     => $auth,
					'sanitize_callback' => function ( $v ) use ( $type ) {
						return meeha_lit_sanitize_field( $type, $v );
					},
				)
			);
		}
	}
);

add_action(
	'add_meta_boxes_post',
	function ( $post ) {
		$new = in_array( $post->post_status, array( 'auto-draft', 'draft' ), true ) && ! wp_get_post_categories( $post->ID );
		if ( ! $new && ! meeha_lit_is_literature( $post ) ) {
			return;
		}
		add_meta_box( 'meeha-lit', 'সাহিত্য তথ্য', 'meeha_lit_meta_box', 'post', 'normal', 'default' );
	}
);

/**
 * Meta box markup.
 *
 * @param WP_Post $post Post.
 */
function meeha_lit_meta_box( $post ) {
	wp_nonce_field( 'meeha_lit_save', 'meeha_lit_nonce' );
	$genres = meeha_lit_genres();
	$pick   = (string) get_post_meta( $post->ID, '_meeha_lit_genre', true );
	$auto   = meeha_lit_is_literature( $post ) ? meeha_lit_genre_of( $post ) : '';
	echo '<p class="description">শুধু "বাঙালি সাহিত্য-সংস্কৃতি" বিভাগের লেখায় প্রযোজ্য। ধরন অনুযায়ী ঘরগুলো দেখাবে।</p>';
	echo '<p><label for="meeha-lit-genre"><strong>ধরন</strong></label><br><select id="meeha-lit-genre" name="meeha_lit[genre]" data-auto="' . esc_attr( $auto ) . '">';
	echo '<option value="">স্বয়ংক্রিয় (বিভাগ থেকে)' . ( $auto && isset( $genres[ $auto ] ) ? ' — এখন: ' . esc_html( $genres[ $auto ]['label'] ) : '' ) . '</option>';
	foreach ( $genres as $gid => $g ) {
		printf( '<option value="%s"%s>%s</option>', esc_attr( $gid ), selected( $pick, $gid, false ), esc_html( $g['label'] ) );
	}
	echo '</select></p>';
	echo '<div class="meeha-lit-fields" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px 16px">';
	foreach ( meeha_lit_fields() as $k => $f ) {
		$val = (string) get_post_meta( $post->ID, '_meeha_lit_' . $k, true );
		$id  = 'meeha-lit-' . $k;
		$nm  = 'meeha_lit[' . $k . ']';
		echo '<p data-genre="' . esc_attr( $f[2] ) . '" style="margin:0">';
		switch ( $f[1] ) {
			case 'checkbox':
				printf( '<label><input type="checkbox" id="%s" name="%s" value="1"%s> %s</label>', esc_attr( $id ), esc_attr( $nm ), checked( '1', $val, false ), esc_html( $f[0] ) );
				break;
			case 'textarea':
				printf( '<label for="%s">%s</label><br><textarea id="%s" name="%s" rows="2" style="width:100%%">%s</textarea>', esc_attr( $id ), esc_html( $f[0] ), esc_attr( $id ), esc_attr( $nm ), esc_textarea( $val ) );
				break;
			case 'rating':
				printf( '<label for="%s">%s</label><br><select id="%s" name="%s"><option value="">—</option>', esc_attr( $id ), esc_html( $f[0] ), esc_attr( $id ), esc_attr( $nm ) );
				for ( $i = 1; $i <= 5; $i++ ) {
					printf( '<option value="%d"%s>%s</option>', $i, selected( (string) $i, $val, false ), esc_html( str_repeat( '★', $i ) ) );
				}
				echo '</select>';
				break;
			case 'image':
				printf( '<label for="%s">%s</label><br><input type="number" min="0" class="small-text" id="%s" name="%s" value="%s"> <span class="description">মিডিয়া আইডি</span>', esc_attr( $id ), esc_html( $f[0] ), esc_attr( $id ), esc_attr( $nm ), esc_attr( $val ) );
				break;
			default:
				printf( '<label for="%s">%s</label><br><input type="%s" id="%s" name="%s" value="%s" style="width:100%%">', esc_attr( $id ), esc_html( $f[0] ), 'url' === $f[1] ? 'url' : 'text', esc_attr( $id ), esc_attr( $nm ), esc_attr( $val ) );
		}
		echo '</p>';
	}
	echo '</div>';
	?>
	<script>
	(function(){var s=document.getElementById('meeha-lit-genre');if(!s)return;function f(){var g=s.value||s.getAttribute('data-auto')||'';document.querySelectorAll('#meeha-lit [data-genre]').forEach(function(p){p.style.display=(!g||p.getAttribute('data-genre')===g)?'':'none';});}s.addEventListener('change',f);f();})();
	</script>
	<?php
}

add_action(
	'save_post_post',
	function ( $post_id ) {
		if ( ! isset( $_POST['meeha_lit_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['meeha_lit_nonce'] ) ), 'meeha_lit_save' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$in = isset( $_POST['meeha_lit'] ) && is_array( $_POST['meeha_lit'] ) ? wp_unslash( $_POST['meeha_lit'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per field.
		$g  = isset( $in['genre'] ) ? sanitize_key( $in['genre'] ) : '';
		if ( '' !== $g && array_key_exists( $g, meeha_lit_genre_defaults() ) ) {
			update_post_meta( $post_id, '_meeha_lit_genre', $g );
		} else {
			delete_post_meta( $post_id, '_meeha_lit_genre' );
		}
		foreach ( meeha_lit_fields() as $k => $f ) {
			$v = meeha_lit_sanitize_field( $f[1], isset( $in[ $k ] ) ? $in[ $k ] : '' );
			if ( '' === $v ) {
				delete_post_meta( $post_id, '_meeha_lit_' . $k );
			} else {
				update_post_meta( $post_id, '_meeha_lit_' . $k, $v );
			}
		}
	}
);
