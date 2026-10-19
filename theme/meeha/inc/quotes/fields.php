<?php
/**
 * Quote fields meta box + legacy `_meeha_attribution` ("name, role") two-way sync.
 *
 * New keys: `_meeha_quote_speaker`, `_meeha_quote_role`, `_meeha_quote_source`, `_meeha_quote_source_year`,
 * `_meeha_quote_context`, `_meeha_quote_year` (history watermark). Speaker photo = featured image.
 * Poll keys (legacy, unchanged): `_meeha_poll_enabled`, `_meeha_poll_option_a`, `_meeha_poll_option_b`,
 * `_meeha_poll_duration_days`.
 *
 * Sync: saving the box writes the legacy string; any other write to `_meeha_attribution` (old theme after a
 * rollback, studio, REST) is parsed back into speaker/role; any other write to speaker/role recomposes it.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

/**
 * Split a legacy attribution "name, role".
 *
 * @param string $attr Attribution.
 * @return array [speaker, role]
 */
function meeha_quote_parse_attribution( $attr ) {
	$parts = preg_split( '/\s*[,،]\s*/u', trim( (string) $attr ), 2 );
	return array( isset( $parts[0] ) ? trim( $parts[0] ) : '', isset( $parts[1] ) ? trim( $parts[1] ) : '' );
}

/**
 * Compose the legacy string.
 *
 * @param string $speaker Speaker.
 * @param string $role    Role.
 * @return string
 */
function meeha_quote_compose_attribution( $speaker, $role ) {
	$speaker = trim( (string) $speaker );
	$role    = trim( (string) $role );
	return '' === $role ? $speaker : ( '' === $speaker ? $role : $speaker . ', ' . $role );
}

/**
 * Speaker + role of a quote (new fields; legacy string when they are empty or the legacy string was changed
 * outside the sync, e.g. by the old theme after a rollback).
 *
 * @param WP_Post|int $post Post.
 * @return array [speaker, role]
 */
function meeha_quote_speaker( $post ) {
	$post    = get_post( $post );
	$speaker = trim( (string) get_post_meta( $post->ID, '_meeha_quote_speaker', true ) );
	$role    = trim( (string) get_post_meta( $post->ID, '_meeha_quote_role', true ) );
	$legacy  = trim( (string) get_post_meta( $post->ID, '_meeha_attribution', true ) );
	if ( '' !== $legacy && ( '' === $speaker || $legacy !== meeha_quote_compose_attribution( $speaker, $role ) ) ) {
		return meeha_quote_parse_attribution( $legacy );
	}
	return array( $speaker, $role );
}

/* ---- sync hooks ---- */
$GLOBALS['meeha_quote_syncing'] = false;

/**
 * Keep the pair in sync when either side is written outside the meta box.
 *
 * @param int    $meta_id  Meta id.
 * @param int    $post_id  Post.
 * @param string $key      Key.
 * @param mixed  $value    Value.
 */
function meeha_quote_meta_sync( $meta_id, $post_id, $key, $value ) {
	if ( $GLOBALS['meeha_quote_syncing'] || ! in_array( $key, array( '_meeha_attribution', '_meeha_quote_speaker', '_meeha_quote_role' ), true ) ) {
		return;
	}
	if ( 'meeha_photocard' !== get_post_type( $post_id ) ) {
		return;
	}
	$GLOBALS['meeha_quote_syncing'] = true;
	if ( '_meeha_attribution' === $key ) {
		list( $s, $r ) = meeha_quote_parse_attribution( (string) $value );
		update_post_meta( $post_id, '_meeha_quote_speaker', $s );
		update_post_meta( $post_id, '_meeha_quote_role', $r );
	} else {
		$s = (string) get_post_meta( $post_id, '_meeha_quote_speaker', true );
		$r = (string) get_post_meta( $post_id, '_meeha_quote_role', true );
		update_post_meta( $post_id, '_meeha_attribution', meeha_quote_compose_attribution( $s, $r ) );
	}
	$GLOBALS['meeha_quote_syncing'] = false;
}
add_action( 'added_post_meta', 'meeha_quote_meta_sync', 10, 4 );
add_action( 'updated_post_meta', 'meeha_quote_meta_sync', 10, 4 );

/**
 * Everything the templates need about a quote.
 *
 * @param WP_Post|int $post Post.
 * @return array
 */
function meeha_quote_data( $post ) {
	$post   = get_post( $post );
	$frames = meeha_quote_frames();
	$terms  = get_the_terms( $post, 'meeha_quote_type' );
	$term   = ( $terms && ! is_wp_error( $terms ) ) ? $terms[0] : null;
	$frame  = meeha_quote_frame_of_term( $term );
	$text   = trim( html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES, 'UTF-8' ) );
	$len    = mb_strlen( $text );
	list( $speaker, $role ) = meeha_quote_speaker( $post );
	$m      = function ( $k ) use ( $post ) {
		return trim( (string) get_post_meta( $post->ID, '_meeha_quote_' . $k, true ) );
	};
	return array(
		'id'          => $post->ID,
		'text'        => $text,
		'len_class'   => $len > 160 ? 'xlong' : ( $len > 90 ? 'long' : '' ),
		'speaker'     => $speaker,
		'role'        => $role,
		'source'      => $m( 'source' ),
		'source_year' => $m( 'source_year' ),
		'context'     => $m( 'context' ),
		'year'        => $m( 'year' ),
		'photo'       => (int) get_post_thumbnail_id( $post ),
		'term'        => $term,
		'frame'       => $frame,
		'type_label'  => $term ? $term->name : $frames[ $frame ][0],
		'icon'        => $frames[ $frame ][1],
		'url'         => get_permalink( $post ),
	);
}

/* ---- editor UI ---- */
add_filter(
	'enter_title_here',
	function ( $t, $post ) {
		return 'meeha_photocard' === $post->post_type ? 'উক্তিটি এখানে লিখুন…' : $t;
	},
	10,
	2
);
add_action(
	'edit_form_after_title',
	function ( $post ) {
		if ( 'meeha_photocard' === $post->post_type ) {
			echo '<p class="description" style="margin:6px 0 0">শিরোনামের ঘরেই উক্তিটি লিখুন (সর্বোচ্চ ২২০ অক্ষর; ৯০ অক্ষরের মধ্যে হলে কার্ডে সবচেয়ে ভালো দেখায়)। বক্তার ছবি = ফিচার্ড ছবি (ঐচ্ছিক)।</p>';
		}
	}
);

add_action(
	'add_meta_boxes_meeha_photocard',
	function () {
		add_meta_box( 'meeha-quote', 'উক্তির তথ্য', 'meeha_quote_meta_box', 'meeha_photocard', 'normal', 'high' );
	}
);

/**
 * Meta box.
 *
 * @param WP_Post $post Post.
 */
function meeha_quote_meta_box( $post ) {
	wp_nonce_field( 'meeha_quote_save', 'meeha_quote_nonce' );
	list( $speaker, $role ) = meeha_quote_speaker( $post );
	$g     = function ( $k ) use ( $post ) {
		return (string) get_post_meta( $post->ID, $k, true );
	};
	$row   = function ( $id, $label, $html ) {
		echo '<p style="margin:0 0 10px"><label for="' . esc_attr( $id ) . '"><strong>' . esc_html( $label ) . '</strong></label><br>' . $html . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built escaped.
	};
	$input = function ( $id, $name, $val, $extra = '' ) {
		return '<input type="text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $val ) . '" style="width:100%"' . $extra . '>';
	};
	echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:0 18px">';
	$row( 'mq-speaker', 'বক্তা', $input( 'mq-speaker', 'meeha_quote[speaker]', $speaker ) );
	$row( 'mq-role', 'পরিচয় / উপাধি', $input( 'mq-role', 'meeha_quote[role]', $role ) );
	$row( 'mq-source', 'উৎস (বই, ভাষণ, সাক্ষাৎকার…)', $input( 'mq-source', 'meeha_quote[source]', $g( '_meeha_quote_source' ) ) );
	$row( 'mq-source-year', 'উৎসের সাল', $input( 'mq-source-year', 'meeha_quote[source_year]', $g( '_meeha_quote_source_year' ), ' inputmode="numeric"' ) );
	$row( 'mq-year', 'বছর (ইতিহাস ফ্রেমের জলছাপ)', $input( 'mq-year', 'meeha_quote[year]', $g( '_meeha_quote_year' ), ' inputmode="numeric" placeholder="যেমন ১৯৫২"' ) );
	echo '</div>';
	$row( 'mq-context', 'প্রসঙ্গ (সংক্ষেপে)', '<textarea id="mq-context" name="meeha_quote[context]" rows="3" style="width:100%">' . esc_textarea( $g( '_meeha_quote_context' ) ) . '</textarea>' );
	echo '<p class="description">পুরোনো "বক্তার নাম, পরিচয়" ঘর (_meeha_attribution) স্বয়ংক্রিয়ভাবে মিলিয়ে রাখা হয়।</p>';
	echo '<hr><h4 style="margin:10px 0">ভোট</h4>';
	echo '<p><label><input type="checkbox" name="meeha_quote[poll]" value="1"' . checked( '1', $g( '_meeha_poll_enabled' ), false ) . '> এই উক্তিতে ভোট চালু করুন</label></p>';
	echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:0 18px">';
	$row( 'mq-a', 'অপশন ক', $input( 'mq-a', 'meeha_quote[option_a]', $g( '_meeha_poll_option_a' ), ' placeholder="একমত"' ) );
	$row( 'mq-b', 'অপশন খ', $input( 'mq-b', 'meeha_quote[option_b]', $g( '_meeha_poll_option_b' ), ' placeholder="দ্বিমত"' ) );
	$row( 'mq-days', 'মেয়াদ (দিন; খালি = ডিফল্ট ' . meeha_bn_digits( (int) meeha_opt( 'quotes', 'poll_days', 7 ) ) . ')', '<input type="number" min="1" max="365" id="mq-days" name="meeha_quote[days]" value="' . esc_attr( $g( '_meeha_poll_duration_days' ) ) . '" class="small-text">' );
	echo '</div>';
	if ( '1' === $g( '_meeha_poll_enabled' ) && function_exists( 'meeha_poll_data' ) ) {
		$d = meeha_poll_data( $post->ID );
		echo '<p class="description">' . esc_html( 'এখন পর্যন্ত মোট ভোট: ' . meeha_bn_digits( $d['total'] ) . ' (' . $d['option_a'] . ' ' . meeha_bn_digits( $d['count_a'] ) . ' · ' . $d['option_b'] . ' ' . meeha_bn_digits( $d['count_b'] ) . ') — ' . ( $d['is_closed'] ? 'ভোট শেষ' : 'চলছে' ) ) . '</p>';
	}
}

add_action(
	'save_post_meeha_photocard',
	function ( $post_id ) {
		if ( ! isset( $_POST['meeha_quote_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['meeha_quote_nonce'] ) ), 'meeha_quote_save' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$in = isset( $_POST['meeha_quote'] ) && is_array( $_POST['meeha_quote'] ) ? wp_unslash( $_POST['meeha_quote'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized below.
		meeha_quote_save_fields( $post_id, $in );
	}
);

/**
 * Save quote fields (also used by tests/importers).
 *
 * @param int   $post_id Post.
 * @param array $in      speaker, role, source, source_year, context, year, poll, option_a, option_b, days.
 */
function meeha_quote_save_fields( $post_id, $in ) {
	$t = function ( $k ) use ( $in ) {
		return isset( $in[ $k ] ) ? sanitize_text_field( (string) $in[ $k ] ) : '';
	};
	$GLOBALS['meeha_quote_syncing'] = true;
	foreach ( array( 'speaker', 'role', 'source', 'source_year', 'year' ) as $k ) {
		$v = $t( $k );
		if ( in_array( $k, array( 'source_year', 'year' ), true ) ) {
			$v = meeha_bn_digits( $v );
		}
		update_post_meta( $post_id, '_meeha_quote_' . $k, $v );
	}
	update_post_meta( $post_id, '_meeha_quote_context', isset( $in['context'] ) ? sanitize_textarea_field( (string) $in['context'] ) : '' );
	update_post_meta( $post_id, '_meeha_attribution', meeha_quote_compose_attribution( $t( 'speaker' ), $t( 'role' ) ) );
	$GLOBALS['meeha_quote_syncing'] = false;

	update_post_meta( $post_id, '_meeha_poll_enabled', empty( $in['poll'] ) ? 0 : 1 );
	foreach ( array( 'option_a', 'option_b' ) as $k ) {
		$v = $t( $k );
		if ( '' === $v ) {
			delete_post_meta( $post_id, '_meeha_poll_' . $k );
		} else {
			update_post_meta( $post_id, '_meeha_poll_' . $k, $v );
		}
	}
	$days = isset( $in['days'] ) ? absint( meeha_en_digits( (string) $in['days'] ) ) : 0;
	if ( $days ) {
		update_post_meta( $post_id, '_meeha_poll_duration_days', min( 365, $days ) );
	} else {
		delete_post_meta( $post_id, '_meeha_poll_duration_days' );
	}
	delete_transient( 'meeha_poll_' . $post_id );
}

/* Admin list: speaker + votes columns. */
add_filter(
	'manage_meeha_photocard_posts_columns',
	function ( $c ) {
		$new = array();
		foreach ( $c as $k => $v ) {
			$new[ $k ] = 'title' === $k ? 'উক্তি' : $v;
			if ( 'title' === $k ) {
				$new['meeha_speaker'] = 'বক্তা';
				$new['meeha_votes']   = 'ভোট';
			}
		}
		return $new;
	}
);
add_action(
	'manage_meeha_photocard_posts_custom_column',
	function ( $col, $id ) {
		if ( 'meeha_speaker' === $col ) {
			list( $s, $r ) = meeha_quote_speaker( $id );
			echo esc_html( meeha_quote_compose_attribution( $s, $r ) );
		} elseif ( 'meeha_votes' === $col && function_exists( 'meeha_poll_data' ) ) {
			$d = meeha_poll_data( $id );
			if ( empty( $d['enabled'] ) ) {
				echo '—';
			} else {
				echo esc_html( meeha_bn_digits( $d['total'] ) . ' · ' . ( $d['is_closed'] ? 'শেষ' : 'চলছে' ) );
			}
		}
	},
	10,
	2
);
