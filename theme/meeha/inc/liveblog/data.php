<?php
/**
 * Live posts (2.5.0) — data model.
 *
 * A live post is an ordinary `post` with a live configuration in the `_meeha_live` meta. Its timestamped updates are
 * a hidden child post type, `meeha_live_entry` (post_parent = the live post), so several people can add updates at
 * the same time without overwriting each other, every update keeps its own author, time and revisions, and deleting
 * one is just trashing it.
 *
 * Two kinds:
 *   event          লাইভ ইভেন্ট — খেলা, আন্দোলন, নির্বাচন বা যেকোনো চলমান ঘটনা (optional scoreboard + key figures).
 *   investigation  অপরাধ তদন্ত লাইভ — case file: status/stage, victims, named people with an involvement estimate,
 *                  strong/weak points, key facts, documents and evidence added over time, and a fixed disclaimer.
 *
 * Meta on the live post:
 *   _meeha_live         array (see meeha_lb_defaults())
 *   _meeha_live_status  live|paused|ended — separate so lists can query it cheaply
 *   _meeha_live_mod     unix time the configuration last changed (the reader's poll uses it)
 *   _meeha_live_last    unix time of the newest published update (cards show "সর্বশেষ আপডেট")
 * Meta on an update (meeha_live_entry):
 *   _lb_kind    update|key|breaking|score|statement|document|evidence|milestone
 *   _lb_pinned  1 = shown on top of the feed
 *   _lb_source  where a document / piece of evidence comes from ("আদালতের নথি", "পুলিশের এজাহার" …)
 *
 * Writers: the newsroom-os plugin (publisher + correspondent apps) through meeha_lb_save_config(),
 * meeha_lb_insert_entry() and meeha_lb_update_entry(); wp-admin shows the updates read-only.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

const MEEHA_LB_TYPE = 'meeha_live_entry';

add_action(
	'init',
	function () {
		register_post_type(
			MEEHA_LB_TYPE,
			array(
				'labels'              => array(
					'name'          => 'লাইভ আপডেট',
					'singular_name' => 'লাইভ আপডেট',
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => false,
				'show_in_rest'        => false,
				'hierarchical'        => false,
				'rewrite'             => false,
				'query_var'           => false,
				'supports'            => array( 'title', 'editor', 'author', 'revisions' ),
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
			)
		);
	}
);

/**
 * Update kinds: id => [label, applies to (both|event|investigation)].
 *
 * @return array
 */
function meeha_lb_kinds() {
	return array(
		'update'    => array( 'আপডেট', 'both' ),
		'key'       => array( 'গুরুত্বপূর্ণ', 'both' ),
		'breaking'  => array( 'ব্রেকিং', 'both' ),
		'statement' => array( 'বক্তব্য', 'both' ),
		'score'     => array( 'মুহূর্ত', 'event' ),
		'document'  => array( 'নথি', 'investigation' ),
		'evidence'  => array( 'তথ্য-প্রমাণ', 'investigation' ),
		'milestone' => array( 'তদন্তের অগ্রগতি', 'investigation' ),
	);
}

/**
 * Investigation stages, in order: id => label.
 *
 * @return array
 */
function meeha_lb_stages() {
	return array(
		'filed'         => 'মামলা দায়ের',
		'investigating' => 'তদন্ত চলছে',
		'arrests'       => 'গ্রেপ্তার / আটক',
		'chargesheet'   => 'অভিযোগপত্র',
		'trial'         => 'বিচার চলছে',
		'verdict'       => 'রায়',
	);
}

/**
 * Case states: id => [label, tone].
 *
 * @return array
 */
function meeha_lb_case_states() {
	return array(
		'open'    => array( 'ওপেন', 'open' ),
		'stalled' => array( 'স্থবির', 'stalled' ),
		'closed'  => array( 'ক্লোজড', 'closed' ),
	);
}

/**
 * Status of a named person: id => [label, tone].
 *
 * @return array
 */
function meeha_lb_person_states() {
	return array(
		'suspect'    => array( 'সন্দেহভাজন', 'amber' ),
		'accused'    => array( 'অভিযুক্ত / আসামি', 'red' ),
		'arrested'   => array( 'গ্রেপ্তার', 'red' ),
		'remand'     => array( 'রিমান্ডে', 'red' ),
		'bail'       => array( 'জামিনে', 'amber' ),
		'absconding' => array( 'পলাতক', 'red' ),
		'witness'    => array( 'সাক্ষী', 'blue' ),
		'cleared'    => array( 'অব্যাহতি', 'green' ),
		'convicted'  => array( 'দোষী সাব্যস্ত', 'red' ),
		'other'      => array( 'অন্যান্য', 'grey' ),
	);
}

/**
 * Live states: id => label.
 *
 * @return array
 */
function meeha_lb_states() {
	return array(
		'live'   => 'লাইভ',
		'paused' => 'বিরতি',
		'ended'  => 'লাইভ শেষ',
	);
}

/**
 * The fixed disclaimer of an investigation live (the editor may reword it per post).
 *
 * @return string
 */
function meeha_lb_default_disclaimer() {
	return 'এই প্রতিবেদন মিহা নিউজের সংগ্রহ করা নথি ও তথ্য-প্রমাণের ভিত্তিতে তৈরি। এটি গোয়েন্দা সংস্থা কর্তৃক পরিচালিত তদন্ত কিংবা আদালতে প্রমাণিত কোনো চূড়ান্ত রায় নয়। আদালতে দোষ প্রমাণিত না হওয়া পর্যন্ত কোনো অভিযুক্তকে অপরাধী বলা যায় না।';
}

/**
 * Default configuration.
 *
 * @return array
 */
function meeha_lb_defaults() {
	return array(
		'type'      => 'event',
		'status'    => 'live',
		'started'   => 0,
		'ended'     => 0,
		'reporters' => array(),
		'moderate'  => false,
		'facts'     => array(), // [ {label, value} ] — key figures, both types.
		'board'     => array(
			'on'      => false,
			'a_name'  => '',
			'a_score' => '',
			'b_name'  => '',
			'b_score' => '',
			'note'    => '',
		),
		'case'      => array(
			'state'       => 'open',
			'stage'       => 'filed',
			'case_no'     => '',
			'filed'       => '', // Y-m-d.
			'incident'    => '', // Y-m-d.
			'place'       => '',
			'summary'     => '',
			'victims'     => array(), // [ {name, age, detail, photo} ].
			'people'      => array(), // [ {name, role, status, share, note, photo} ].
			'show_share'  => true,
			'strengths'   => array(), // [ {text, weight 1–5} ].
			'weaknesses'  => array(),
			'disclaimer'  => '',
		),
	);
}

/**
 * Raw configuration of a post, or null when the post is not a live post.
 *
 * @param int|WP_Post $post Post.
 * @return array|null
 */
function meeha_lb_config( $post ) {
	$post = get_post( $post );
	if ( ! $post ) {
		return null;
	}
	$raw = get_post_meta( $post->ID, '_meeha_live', true );
	if ( is_string( $raw ) && '' !== $raw ) {
		$raw = json_decode( $raw, true );
	}
	if ( ! is_array( $raw ) || empty( $raw['type'] ) ) {
		return null;
	}
	return meeha_lb_clean_config( $raw );
}

/**
 * Is this a live post (any state)?
 *
 * @param int|WP_Post $post Post.
 * @return bool
 */
function meeha_lb_is_live_post( $post ) {
	$post = get_post( $post );
	return $post && '' !== (string) get_post_meta( $post->ID, '_meeha_live_status', true );
}

/**
 * Live state of a post: live|paused|ended, '' when not a live post.
 *
 * @param int|WP_Post $post Post.
 * @return string
 */
function meeha_lb_status( $post ) {
	$post = get_post( $post );
	return $post ? (string) get_post_meta( $post->ID, '_meeha_live_status', true ) : '';
}

/**
 * Plain text, one line, capped.
 *
 * @param mixed $v   Value.
 * @param int   $max Max characters.
 * @return string
 */
function meeha_lb_text( $v, $max = 200 ) {
	$v = sanitize_text_field( is_scalar( $v ) ? (string) $v : '' );
	return function_exists( 'mb_substr' ) ? mb_substr( $v, 0, $max ) : substr( $v, 0, $max );
}

/**
 * Multi-line plain text, capped.
 *
 * @param mixed $v   Value.
 * @param int   $max Max characters.
 * @return string
 */
function meeha_lb_textarea( $v, $max = 2000 ) {
	$v = sanitize_textarea_field( is_scalar( $v ) ? (string) $v : '' );
	return function_exists( 'mb_substr' ) ? mb_substr( $v, 0, $max ) : substr( $v, 0, $max );
}

/**
 * Y-m-d or ''.
 *
 * @param mixed $v Value.
 * @return string
 */
function meeha_lb_date( $v ) {
	$v = is_scalar( $v ) ? trim( (string) $v ) : '';
	return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : '';
}

/**
 * Normalises a configuration array (anything missing gets its default, everything is sanitised and capped).
 *
 * @param array $raw Raw.
 * @return array
 */
function meeha_lb_clean_config( $raw ) {
	$d   = meeha_lb_defaults();
	$raw = is_array( $raw ) ? $raw : array();
	$out = $d;

	$out['type']     = ( isset( $raw['type'] ) && 'investigation' === $raw['type'] ) ? 'investigation' : 'event';
	$out['status']   = ( isset( $raw['status'] ) && isset( meeha_lb_states()[ $raw['status'] ] ) ) ? $raw['status'] : 'live';
	$out['started']  = isset( $raw['started'] ) ? max( 0, (int) $raw['started'] ) : 0;
	$out['ended']    = isset( $raw['ended'] ) ? max( 0, (int) $raw['ended'] ) : 0;
	$out['moderate'] = ! empty( $raw['moderate'] );

	$out['reporters'] = array();
	if ( isset( $raw['reporters'] ) && is_array( $raw['reporters'] ) ) {
		$out['reporters'] = array_values( array_unique( array_filter( array_map( 'absint', $raw['reporters'] ) ) ) );
		$out['reporters'] = array_slice( $out['reporters'], 0, 30 );
	}

	$out['facts'] = array();
	if ( isset( $raw['facts'] ) && is_array( $raw['facts'] ) ) {
		foreach ( $raw['facts'] as $f ) {
			if ( ! is_array( $f ) ) {
				continue;
			}
			$label = meeha_lb_text( isset( $f['label'] ) ? $f['label'] : '', 60 );
			$value = meeha_lb_text( isset( $f['value'] ) ? $f['value'] : '', 80 );
			if ( '' !== $label || '' !== $value ) {
				$out['facts'][] = array(
					'label' => $label,
					'value' => $value,
				);
			}
		}
		$out['facts'] = array_slice( $out['facts'], 0, 12 );
	}

	$b            = isset( $raw['board'] ) && is_array( $raw['board'] ) ? $raw['board'] : array();
	$out['board'] = array(
		'on'      => ! empty( $b['on'] ),
		'a_name'  => meeha_lb_text( isset( $b['a_name'] ) ? $b['a_name'] : '', 60 ),
		'a_score' => meeha_lb_text( isset( $b['a_score'] ) ? $b['a_score'] : '', 30 ),
		'b_name'  => meeha_lb_text( isset( $b['b_name'] ) ? $b['b_name'] : '', 60 ),
		'b_score' => meeha_lb_text( isset( $b['b_score'] ) ? $b['b_score'] : '', 30 ),
		'note'    => meeha_lb_text( isset( $b['note'] ) ? $b['note'] : '', 120 ),
	);

	$c    = isset( $raw['case'] ) && is_array( $raw['case'] ) ? $raw['case'] : array();
	$case = $d['case'];

	$case['state']      = ( isset( $c['state'] ) && isset( meeha_lb_case_states()[ $c['state'] ] ) ) ? $c['state'] : 'open';
	$case['stage']      = ( isset( $c['stage'] ) && isset( meeha_lb_stages()[ $c['stage'] ] ) ) ? $c['stage'] : 'filed';
	$case['case_no']    = meeha_lb_text( isset( $c['case_no'] ) ? $c['case_no'] : '', 80 );
	$case['filed']      = meeha_lb_date( isset( $c['filed'] ) ? $c['filed'] : '' );
	$case['incident']   = meeha_lb_date( isset( $c['incident'] ) ? $c['incident'] : '' );
	$case['place']      = meeha_lb_text( isset( $c['place'] ) ? $c['place'] : '', 120 );
	$case['summary']    = meeha_lb_textarea( isset( $c['summary'] ) ? $c['summary'] : '', 1200 );
	$case['show_share'] = ! isset( $c['show_share'] ) || ! empty( $c['show_share'] );
	$case['disclaimer'] = meeha_lb_textarea( isset( $c['disclaimer'] ) ? $c['disclaimer'] : '', 800 );

	$case['victims'] = array();
	if ( isset( $c['victims'] ) && is_array( $c['victims'] ) ) {
		foreach ( $c['victims'] as $v ) {
			if ( ! is_array( $v ) ) {
				continue;
			}
			$name = meeha_lb_text( isset( $v['name'] ) ? $v['name'] : '', 80 );
			if ( '' === $name ) {
				continue;
			}
			$case['victims'][] = array(
				'name'   => $name,
				'age'    => meeha_lb_text( isset( $v['age'] ) ? $v['age'] : '', 20 ),
				'detail' => meeha_lb_textarea( isset( $v['detail'] ) ? $v['detail'] : '', 400 ),
				'photo'  => isset( $v['photo'] ) ? absint( $v['photo'] ) : 0,
			);
		}
		$case['victims'] = array_slice( $case['victims'], 0, 8 );
	}

	$case['people'] = array();
	if ( isset( $c['people'] ) && is_array( $c['people'] ) ) {
		foreach ( $c['people'] as $p ) {
			if ( ! is_array( $p ) ) {
				continue;
			}
			$name = meeha_lb_text( isset( $p['name'] ) ? $p['name'] : '', 80 );
			if ( '' === $name ) {
				continue;
			}
			$status           = ( isset( $p['status'] ) && isset( meeha_lb_person_states()[ $p['status'] ] ) ) ? $p['status'] : 'suspect';
			$case['people'][] = array(
				'name'   => $name,
				'role'   => meeha_lb_text( isset( $p['role'] ) ? $p['role'] : '', 120 ),
				'status' => $status,
				'share'  => isset( $p['share'] ) ? max( 0, min( 100, (int) $p['share'] ) ) : 0,
				'note'   => meeha_lb_textarea( isset( $p['note'] ) ? $p['note'] : '', 400 ),
				'photo'  => isset( $p['photo'] ) ? absint( $p['photo'] ) : 0,
			);
		}
		$case['people'] = array_slice( $case['people'], 0, 20 );
	}

	foreach ( array( 'strengths', 'weaknesses' ) as $k ) {
		$case[ $k ] = array();
		if ( isset( $c[ $k ] ) && is_array( $c[ $k ] ) ) {
			foreach ( $c[ $k ] as $it ) {
				if ( ! is_array( $it ) ) {
					continue;
				}
				$text = meeha_lb_textarea( isset( $it['text'] ) ? $it['text'] : '', 300 );
				if ( '' === $text ) {
					continue;
				}
				$case[ $k ][] = array(
					'text'   => $text,
					'weight' => isset( $it['weight'] ) ? max( 1, min( 5, (int) $it['weight'] ) ) : 3,
				);
			}
			$case[ $k ] = array_slice( $case[ $k ], 0, 12 );
		}
	}
	$out['case'] = $case;
	return $out;
}

/**
 * Saves a live configuration (merging into what is stored), keeps the status meta in sync and stamps the change.
 *
 * @param int   $post_id Post ID.
 * @param array $changes Partial configuration.
 * @return array The saved configuration.
 */
function meeha_lb_save_config( $post_id, $changes ) {
	$current = meeha_lb_config( $post_id );
	$base    = $current ? $current : meeha_lb_defaults();
	$changes = is_array( $changes ) ? $changes : array();
	foreach ( $changes as $k => $v ) {
		if ( in_array( $k, array( 'board', 'case' ), true ) && is_array( $v ) ) {
			$base[ $k ] = array_merge( $base[ $k ], $v );
		} else {
			$base[ $k ] = $v;
		}
	}
	$cfg = meeha_lb_clean_config( $base );
	if ( ! $cfg['started'] ) {
		$cfg['started'] = time();
	}
	$was = $current ? $current['status'] : '';
	if ( 'ended' === $cfg['status'] && 'ended' !== $was ) {
		$cfg['ended'] = time();
	} elseif ( 'ended' !== $cfg['status'] ) {
		$cfg['ended'] = 0;
	}
	update_post_meta( $post_id, '_meeha_live', wp_slash( wp_json_encode( $cfg, JSON_UNESCAPED_UNICODE ) ) );
	update_post_meta( $post_id, '_meeha_live_status', $cfg['status'] );
	update_post_meta( $post_id, '_meeha_live_mod', time() );
	clean_post_cache( $post_id );
	return $cfg;
}

/**
 * Turns a post back into an ordinary post (the updates stay, hidden, and come back when live is switched on again).
 *
 * @param int $post_id Post ID.
 */
function meeha_lb_disable( $post_id ) {
	delete_post_meta( $post_id, '_meeha_live' );
	delete_post_meta( $post_id, '_meeha_live_status' );
	update_post_meta( $post_id, '_meeha_live_mod', time() );
	clean_post_cache( $post_id );
}

/**
 * Updates of a live post.
 *
 * @param int   $post_id Live post ID.
 * @param array $args    status (publish|pending|any), limit, before (unix), since (unix, by modified time), order.
 * @return WP_Post[]
 */
function meeha_lb_entries( $post_id, $args = array() ) {
	$args = wp_parse_args(
		$args,
		array(
			'status' => 'publish',
			'limit'  => 0,
			'before' => 0,
			'since'  => 0,
			'order'  => 'DESC',
		)
	);
	$q    = array(
		'post_type'              => MEEHA_LB_TYPE,
		'post_parent'            => (int) $post_id,
		'post_status'            => 'any' === $args['status'] ? array( 'publish', 'pending' ) : $args['status'],
		'posts_per_page'         => $args['limit'] > 0 ? (int) $args['limit'] : 500,
		'orderby'                => array(
			'date' => 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC',
			'ID'   => 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC',
		),
		'no_found_rows'          => true,
		'ignore_sticky_posts'    => true,
		'suppress_filters'       => true,
		'update_post_term_cache' => false,
	);
	$dq   = array();
	if ( $args['before'] > 0 ) {
		$dq[] = array(
			'column'    => 'post_date_gmt',
			'before'    => gmdate( 'Y-m-d H:i:s', (int) $args['before'] ),
			'inclusive' => false,
		);
	}
	if ( $args['since'] > 0 ) {
		$dq[] = array(
			'column'    => 'post_modified_gmt',
			'after'     => gmdate( 'Y-m-d H:i:s', (int) $args['since'] ),
			'inclusive' => true,
		);
	}
	if ( $dq ) {
		$q['date_query'] = $dq;
	}
	return get_posts( $q );
}

/**
 * IDs of all published updates (cheap; the reader uses it to drop deleted ones).
 *
 * @param int $post_id Live post ID.
 * @return int[]
 */
function meeha_lb_entry_ids( $post_id ) {
	global $wpdb;
	return array_map(
		'intval',
		$wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = %s AND post_status = 'publish'", (int) $post_id, MEEHA_LB_TYPE )
		)
	);
}

/**
 * Kind of an update.
 *
 * @param WP_Post $e Update.
 * @return string
 */
function meeha_lb_entry_kind( $e ) {
	$k = (string) get_post_meta( $e->ID, '_lb_kind', true );
	return isset( meeha_lb_kinds()[ $k ] ) ? $k : 'update';
}

/**
 * Sanitised entry fields from raw input.
 *
 * @param array $raw title, content, kind, pinned, source, time (unix, optional — backdating an update).
 * @return array
 */
function meeha_lb_clean_entry( $raw ) {
	$raw  = is_array( $raw ) ? $raw : array();
	$kind = isset( $raw['kind'] ) && isset( meeha_lb_kinds()[ $raw['kind'] ] ) ? $raw['kind'] : 'update';
	return array(
		'title'   => meeha_lb_text( isset( $raw['title'] ) ? $raw['title'] : '', 200 ),
		'content' => wp_kses_post( isset( $raw['content'] ) ? (string) $raw['content'] : '' ),
		'kind'    => $kind,
		'pinned'  => ! empty( $raw['pinned'] ),
		'source'  => meeha_lb_text( isset( $raw['source'] ) ? $raw['source'] : '', 120 ),
	);
}

/**
 * Adds an update.
 *
 * @param int   $post_id   Live post ID.
 * @param array $raw       See meeha_lb_clean_entry(); plus status publish|pending and time (unix).
 * @param int   $author_id Author.
 * @return int|WP_Error Update ID.
 */
function meeha_lb_insert_entry( $post_id, $raw, $author_id ) {
	$e = meeha_lb_clean_entry( $raw );
	if ( '' === trim( wp_strip_all_tags( $e['content'] ) ) && '' === $e['title'] && false === strpos( $e['content'], '<img' ) ) {
		return new WP_Error( 'meeha_lb_empty', 'আপডেটে কিছু লেখা বা ছবি দিন।', array( 'status' => 400 ) );
	}
	$status = ( isset( $raw['status'] ) && 'pending' === $raw['status'] ) ? 'pending' : 'publish';
	$data   = array(
		'post_type'    => MEEHA_LB_TYPE,
		'post_parent'  => (int) $post_id,
		'post_title'   => $e['title'],
		'post_content' => $e['content'],
		'post_status'  => $status,
		'post_author'  => (int) $author_id,
	);
	if ( ! empty( $raw['time'] ) && (int) $raw['time'] > 0 && (int) $raw['time'] <= time() + 60 ) {
		$data['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', (int) $raw['time'] );
		$data['post_date']     = get_date_from_gmt( $data['post_date_gmt'] );
	}
	$id = wp_insert_post( wp_slash( $data ), true );
	if ( is_wp_error( $id ) ) {
		return $id;
	}
	update_post_meta( $id, '_lb_kind', $e['kind'] );
	update_post_meta( $id, '_lb_pinned', $e['pinned'] ? 1 : 0 );
	update_post_meta( $id, '_lb_source', $e['source'] );
	if ( 'publish' === $status ) {
		meeha_lb_touch( $post_id );
	}
	return (int) $id;
}

/**
 * Edits an update (only the given fields).
 *
 * @param int   $entry_id Update ID.
 * @param array $raw      Fields; status publish|pending.
 * @return int|WP_Error
 */
function meeha_lb_update_entry( $entry_id, $raw ) {
	$post = get_post( $entry_id );
	if ( ! $post || MEEHA_LB_TYPE !== $post->post_type ) {
		return new WP_Error( 'meeha_lb_missing', 'আপডেটটি পাওয়া যায়নি।', array( 'status' => 404 ) );
	}
	$current = array(
		'title'   => $post->post_title,
		'content' => $post->post_content,
		'kind'    => meeha_lb_entry_kind( $post ),
		'pinned'  => (bool) get_post_meta( $post->ID, '_lb_pinned', true ),
		'source'  => (string) get_post_meta( $post->ID, '_lb_source', true ),
	);
	$e       = meeha_lb_clean_entry( array_merge( $current, array_intersect_key( (array) $raw, $current ) ) );
	$data    = array(
		'ID'           => $post->ID,
		'post_title'   => $e['title'],
		'post_content' => $e['content'],
	);
	if ( isset( $raw['status'] ) && in_array( $raw['status'], array( 'publish', 'pending' ), true ) ) {
		$data['post_status'] = $raw['status'];
		if ( 'publish' === $raw['status'] && 'pending' === $post->post_status ) {
			// Approved now: it appears at the moment of approval, at the top of the feed.
			$data['post_date_gmt'] = gmdate( 'Y-m-d H:i:s' );
			$data['post_date']     = get_date_from_gmt( $data['post_date_gmt'] );
			$data['edit_date']     = true;
		}
	}
	if ( ! empty( $raw['time'] ) && (int) $raw['time'] > 0 && (int) $raw['time'] <= time() + 60 ) {
		$data['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', (int) $raw['time'] );
		$data['post_date']     = get_date_from_gmt( $data['post_date_gmt'] );
		$data['edit_date']     = true;
	}
	$r = wp_update_post( wp_slash( $data ), true );
	if ( is_wp_error( $r ) ) {
		return $r;
	}
	update_post_meta( $post->ID, '_lb_kind', $e['kind'] );
	update_post_meta( $post->ID, '_lb_pinned', $e['pinned'] ? 1 : 0 );
	update_post_meta( $post->ID, '_lb_source', $e['source'] );
	meeha_lb_touch( (int) $post->post_parent );
	return (int) $post->ID;
}

/**
 * Removes an update (to the trash, so it can be restored from the database if needed).
 *
 * @param int $entry_id Update ID.
 * @return bool
 */
function meeha_lb_delete_entry( $entry_id ) {
	$post = get_post( $entry_id );
	if ( ! $post || MEEHA_LB_TYPE !== $post->post_type ) {
		return false;
	}
	$parent = (int) $post->post_parent;
	$ok     = (bool) wp_trash_post( $post->ID );
	meeha_lb_touch( $parent );
	return $ok;
}

/**
 * Stamps "something changed" on the live post: newest-update time, and the parent's modified date (so caches and
 * sitemaps see the page as fresh).
 *
 * @param int $post_id Live post ID.
 */
function meeha_lb_touch( $post_id ) {
	global $wpdb;
	$newest = (string) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare( "SELECT MAX(post_date_gmt) FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = %s AND post_status = 'publish'", (int) $post_id, MEEHA_LB_TYPE )
	);
	update_post_meta( $post_id, '_meeha_live_last', $newest ? (int) strtotime( $newest . ' UTC' ) : 0 );
	$now = current_time( 'mysql' );
	$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->posts,
		array(
			'post_modified'     => $now,
			'post_modified_gmt' => get_gmt_from_date( $now ),
		),
		array( 'ID' => (int) $post_id )
	);
	clean_post_cache( $post_id );
	/**
	 * Fires after a live post's updates changed (page caches can purge the URL here).
	 *
	 * @param int $post_id Live post ID.
	 */
	do_action( 'meeha_live_changed', (int) $post_id );
}

/* LiteSpeed Cache: purge a live page as soon as it changes, so a fresh visit never starts from a stale copy. */
add_action(
	'meeha_live_changed',
	function ( $post_id ) {
		if ( has_action( 'litespeed_purge_post' ) ) {
			do_action( 'litespeed_purge_post', $post_id );
		}
	}
);

/* Updates follow their live post into (and out of) the trash. */
add_action(
	'before_delete_post',
	function ( $post_id ) {
		$p = get_post( $post_id );
		if ( ! $p || 'post' !== $p->post_type ) {
			return;
		}
		foreach ( get_children( array( 'post_parent' => $post_id, 'post_type' => MEEHA_LB_TYPE, 'post_status' => 'any', 'fields' => 'ids' ) ) as $cid ) {
			wp_delete_post( $cid, true );
		}
	}
);
