<?php
/**
 * Editorial meta: one tidy side box "মিহা সম্পাদকীয়" (topic, breaking, trending pin, একনজরে +
 * sentence, investigation), the summary box, register_post_meta (REST-visible for editors),
 * Posts list columns and Quick Edit.
 *
 * Meta keys (CONTRACT §5 B):
 *   _meeha_topic (string) · _meeha_trending ('1' = editor pin) + _meeha_trending_at (unix, set
 *   when pinned; C expires pins after 24 h) · _meeha_summary (text) · _meeha_breaking ('1') +
 *   _meeha_breaking_at (unix) · _meeha_glance ('1') + _meeha_glance_text (≤ 90 chars) ·
 *   _meeha_investigation ('1').
 *
 * Works in the block editor: classic meta boxes are posted by Gutenberg's meta-box loader after
 * the REST save, which runs save_post with our nonce. Flags are also writable through
 * /wp/v2/posts `meta` for users who can edit the post.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

/** Max length of the একনজরে sentence. */
function meeha_glance_text_max() {
	return (int) apply_filters( 'meeha_glance_text_max', 90 );
}

/** Trim a একনজরে sentence to the limit (grapheme-safe enough: mb_substr on code points). */
function meeha_sanitize_glance_text( $value ) {
	$value = trim( preg_replace( '/\s+/u', ' ', sanitize_text_field( (string) $value ) ) );
	return mb_substr( $value, 0, meeha_glance_text_max() );
}

/** Flag sanitizer: '1' or ''. */
function meeha_sanitize_flag( $value ) {
	return ( '1' === (string) $value || true === $value || 1 === $value ) ? '1' : '';
}

/* =====================================================================
   register_post_meta
===================================================================== */
/**
 * May the current user set the editorial flags (breaking / trending pin / investigation / glance)?
 * These are desk decisions: correspondents and authors who can edit their own post must not set them.
 *
 * @param int $post_id Post.
 * @return bool
 */
function meeha_can_set_editorial_flags( $post_id = 0 ) {
	$ok = current_user_can( 'edit_others_posts' ) || current_user_can( 'manage_meeha_editorial' );
	return $ok && ( ! $post_id || current_user_can( 'edit_post', $post_id ) );
}

function meeha_register_editorial_meta() {
	$auth  = function ( $allowed, $meta_key, $post_id ) {
		return current_user_can( 'edit_post', $post_id );
	};
	$flags = function ( $allowed, $meta_key, $post_id ) {
		return meeha_can_set_editorial_flags( $post_id );
	};
	$flag_keys = array( '_meeha_trending', '_meeha_trending_at', '_meeha_breaking', '_meeha_breaking_at', '_meeha_glance', '_meeha_glance_text', '_meeha_investigation' );
	$defs = array(
		'_meeha_topic'         => array( 'string', 'sanitize_text_field' ),
		'_meeha_summary'       => array( 'string', 'sanitize_textarea_field' ),
		'_meeha_trending'      => array( 'string', 'meeha_sanitize_flag' ),
		'_meeha_trending_at'   => array( 'integer', 'absint' ),
		'_meeha_breaking'      => array( 'string', 'meeha_sanitize_flag' ),
		'_meeha_breaking_at'   => array( 'integer', 'absint' ),
		'_meeha_glance'        => array( 'string', 'meeha_sanitize_flag' ),
		'_meeha_glance_text'   => array( 'string', 'meeha_sanitize_glance_text' ),
		'_meeha_investigation' => array( 'string', 'meeha_sanitize_flag' ),
		'_meeha_division'      => array( 'string', 'sanitize_text_field' ),
		'_meeha_district'      => array( 'string', 'sanitize_text_field' ),
		'_meeha_upazila'       => array( 'string', 'sanitize_text_field' ),
	);
	foreach ( $defs as $key => $def ) {
		register_post_meta(
			'post',
			$key,
			array(
				'type'              => $def[0],
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => $def[1],
				'auth_callback'     => in_array( $key, $flag_keys, true ) ? $flags : $auth,
			)
		);
	}
}
add_action( 'init', 'meeha_register_editorial_meta' );

/** Keep the *_at timestamps in step with the flags, whoever writes them (meta box, REST, plugin). */
function meeha_flag_timestamps( $meta_id, $post_id, $meta_key, $value ) {
	$map = array(
		'_meeha_breaking' => '_meeha_breaking_at',
		'_meeha_trending' => '_meeha_trending_at',
	);
	if ( ! isset( $map[ $meta_key ] ) ) {
		return;
	}
	if ( '1' === (string) $value ) {
		if ( ! get_post_meta( $post_id, $map[ $meta_key ], true ) ) {
			update_post_meta( $post_id, $map[ $meta_key ], time() );
		}
	}
}
add_action( 'added_post_meta', 'meeha_flag_timestamps', 10, 4 );
add_action( 'updated_post_meta', 'meeha_flag_timestamps', 10, 4 );

function meeha_flag_timestamps_clear( $meta_ids, $post_id, $meta_key ) {
	if ( '_meeha_breaking' === $meta_key ) {
		delete_post_meta( $post_id, '_meeha_breaking_at' );
	} elseif ( '_meeha_trending' === $meta_key ) {
		delete_post_meta( $post_id, '_meeha_trending_at' );
	}
}
add_action( 'deleted_post_meta', 'meeha_flag_timestamps_clear', 10, 3 );

/* =====================================================================
   Legacy helpers
===================================================================== */
if ( ! function_exists( 'meeha_get_summary' ) ) {
	function meeha_get_summary( $post_id ) {
		if ( post_password_required( $post_id ) ) {
			return ''; // the summary is part of the protected text.
		}
		return get_post_meta( $post_id, '_meeha_summary', true );
	}
}

if ( ! function_exists( 'meeha_get_topic_label' ) ) {
	/** Topic label with fallback to the post's first category (legacy FP:884). */
	function meeha_get_topic_label( $post_id = 0 ) {
		$post_id = $post_id ? $post_id : get_the_ID();
		$topic   = get_post_meta( $post_id, '_meeha_topic', true );
		if ( $topic ) {
			return $topic;
		}
		$cats = get_the_category( $post_id );
		return ! empty( $cats ) ? $cats[0]->name : '';
	}
}

/** The একনজরে sentence for a post: custom text else title. */
function meeha_glance_text( $post ) {
	$post = get_post( $post );
	if ( ! $post ) {
		return '';
	}
	$text = trim( (string) get_post_meta( $post->ID, '_meeha_glance_text', true ) );
	return '' !== $text ? $text : html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' );
}

/* =====================================================================
   Meta boxes
===================================================================== */
function meeha_add_editorial_meta_boxes() {
	add_meta_box( 'meeha_editorial_box', 'মিহা সম্পাদকীয়', 'meeha_render_editorial_meta_box', 'post', 'side', 'high' );
	add_meta_box( 'meeha_summary_box', 'সারাংশ (ডেক)', 'meeha_render_summary_meta_box', 'post', 'normal', 'high' );
}
add_action( 'add_meta_boxes', 'meeha_add_editorial_meta_boxes' );

/** Editorial fields markup (shared by the meta box and Quick Edit). */
function meeha_editorial_fields( $values, $context = 'box' ) {
	$max = meeha_glance_text_max();
	$v   = function ( $k ) use ( $values ) {
		return isset( $values[ $k ] ) ? (string) $values[ $k ] : '';
	};
	$pin_note = '';
	if ( '1' === $v( 'trending' ) && (int) $v( 'trending_at' ) ) {
		$age      = time() - (int) $v( 'trending_at' );
		$pin_note = $age > DAY_IN_SECONDS ? 'পিনের ২৪ ঘণ্টা পার হয়েছে — আর দেখাচ্ছে না।' : 'পিন করা হয়েছে ' . meeha_legacy_time_ago( (int) $v( 'trending_at' ) ) . '।';
	}
	ob_start();
	?>
	<div class="meeha-ed meeha-ed--<?php echo esc_attr( $context ); ?>">
		<p class="meeha-ed__row">
			<label for="meeha-topic-<?php echo esc_attr( $context ); ?>"><strong>টপিক</strong> <span class="meeha-ed__hint">(শিরোনামের ওপরে ছোট লেবেল)</span></label>
			<input type="text" id="meeha-topic-<?php echo esc_attr( $context ); ?>" name="meeha_topic" value="<?php echo esc_attr( $v( 'topic' ) ); ?>" class="widefat" maxlength="80" placeholder="যেমন: আন্তর্জাতিক অপরাধ ট্রাইব্যুনাল" />
			<span class="description">খালি রাখলে প্রধান ক্যাটাগরি দেখাবে।</span>
		</p>
		<?php $meeha_desk = meeha_can_set_editorial_flags(); ?>
		<fieldset class="meeha-ed__flags"<?php disabled( ! $meeha_desk ); ?>>
			<legend class="screen-reader-text">সম্পাদকীয় চিহ্ন</legend>
			<?php if ( ! $meeha_desk ) : ?><span class="description meeha-ed__note">এই চিহ্নগুলো ডেস্ক (সম্পাদক/প্রকাশক) ঠিক করবে।</span><?php endif; ?>
			<label><input type="checkbox" name="meeha_breaking" value="1" <?php checked( $v( 'breaking' ), '1' ); ?> /> <span class="meeha-ed__badge meeha-ed__badge--red">ব্রেকিং</span> টিকারে ব্রেকিং হিসেবে দেখান</label>
			<label><input type="checkbox" name="meeha_trending" value="1" <?php checked( $v( 'trending' ), '1' ); ?> /> <span class="meeha-ed__badge">পিন</span> ট্রেন্ডিং-এ পিন (২৪ ঘণ্টা)</label>
			<?php if ( $pin_note ) : ?><span class="description meeha-ed__note"><?php echo esc_html( $pin_note ); ?></span><?php endif; ?>
			<label><input type="checkbox" name="meeha_investigation" value="1" <?php checked( $v( 'investigation' ), '1' ); ?> /> <span class="meeha-ed__badge meeha-ed__badge--ink">অনুসন্ধান</span> অনুসন্ধানী প্রতিবেদন</label>
			<label><input type="checkbox" name="meeha_glance" value="1" <?php checked( $v( 'glance' ), '1' ); ?> data-meeha-glance-toggle /> <span class="meeha-ed__badge">একনজরে</span> আজকের একনজরে-তে রাখুন</label>
		</fieldset>
		<p class="meeha-ed__row meeha-ed__glance">
			<label for="meeha-glance-text-<?php echo esc_attr( $context ); ?>"><strong>একনজরে বাক্য</strong> <span class="meeha-ed__hint">(খালি থাকলে শিরোনাম)</span></label>
			<textarea id="meeha-glance-text-<?php echo esc_attr( $context ); ?>" name="meeha_glance_text" rows="2" class="widefat" maxlength="<?php echo (int) $max; ?>" data-meeha-counter placeholder="এক লাইনে খবরের সারকথা, যেমন: রূপসায় ছেলে হত্যা মামলার বাদী বাবা গুলিতে নিহত।"><?php echo esc_textarea( $v( 'glance_text' ) ); ?></textarea>
			<span class="meeha-ed__count" aria-live="polite" data-meeha-count><?php echo esc_html( meeha_legacy_bn( mb_strlen( $v( 'glance_text' ) ) ) . ' / ' . meeha_legacy_bn( $max ) ); ?></span>
		</p>
	</div>
	<?php
	return ob_get_clean();
}

/** Current values for a post. */
function meeha_editorial_values( $post_id ) {
	return array(
		'topic'         => get_post_meta( $post_id, '_meeha_topic', true ),
		'breaking'      => get_post_meta( $post_id, '_meeha_breaking', true ),
		'trending'      => get_post_meta( $post_id, '_meeha_trending', true ),
		'trending_at'   => get_post_meta( $post_id, '_meeha_trending_at', true ),
		'investigation' => get_post_meta( $post_id, '_meeha_investigation', true ),
		'glance'        => get_post_meta( $post_id, '_meeha_glance', true ),
		'glance_text'   => get_post_meta( $post_id, '_meeha_glance_text', true ),
	);
}

function meeha_render_editorial_meta_box( $post ) {
	wp_nonce_field( 'meeha_save_editorial', 'meeha_editorial_nonce' );
	echo meeha_editorial_fields( meeha_editorial_values( $post->ID ), 'box' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside
}

function meeha_render_summary_meta_box( $post ) {
	wp_nonce_field( 'meeha_save_summary', 'meeha_summary_nonce' );
	$summary = get_post_meta( $post->ID, '_meeha_summary', true );
	?>
	<p class="description">শিরোনামের নিচে ডেক হিসেবে আর “সারাংশ” বাটনে দেখাবে। ২–৩ বাক্যে লিখুন (নিজে বা AI দিয়ে বানিয়ে যাচাই করে)। খালি রাখলে সারাংশ বাটন দেখাবে না।</p>
	<label class="screen-reader-text" for="meeha-summary">সারাংশ</label>
	<textarea id="meeha-summary" name="meeha_summary" rows="5" class="widefat"><?php echo esc_textarea( $summary ); ?></textarea>
	<?php
}

/** Apply submitted editorial fields (meta box or Quick Edit). */
function meeha_save_editorial_fields( $post_id, $src ) {
	if ( isset( $src['meeha_topic'] ) ) {
		$topic = sanitize_text_field( wp_unslash( $src['meeha_topic'] ) );
		if ( '' === $topic ) {
			delete_post_meta( $post_id, '_meeha_topic' );
		} else {
			update_post_meta( $post_id, '_meeha_topic', mb_substr( $topic, 0, 80 ) );
		}
	}
	if ( ! meeha_can_set_editorial_flags( $post_id ) ) {
		return; // flags + glance sentence are desk decisions (edit_others_posts / manage_meeha_editorial).
	}
	foreach ( array( 'breaking', 'trending', 'investigation', 'glance' ) as $flag ) {
		if ( ! empty( $src[ 'meeha_' . $flag ] ) ) {
			if ( '1' !== get_post_meta( $post_id, '_meeha_' . $flag, true ) ) {
				update_post_meta( $post_id, '_meeha_' . $flag, '1' );
				if ( 'breaking' === $flag || 'trending' === $flag ) {
					update_post_meta( $post_id, '_meeha_' . $flag . '_at', time() ); // fresh pin/breaking
				}
			}
		} else {
			delete_post_meta( $post_id, '_meeha_' . $flag );
		}
	}
	if ( isset( $src['meeha_glance_text'] ) ) {
		$text = meeha_sanitize_glance_text( wp_unslash( $src['meeha_glance_text'] ) );
		if ( '' === $text ) {
			delete_post_meta( $post_id, '_meeha_glance_text' );
		} else {
			update_post_meta( $post_id, '_meeha_glance_text', $text );
		}
	}
}

function meeha_save_editorial_meta( $post_id ) {
	if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
		return;
	}
	if ( ! isset( $_POST['meeha_editorial_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['meeha_editorial_nonce'] ) ), 'meeha_save_editorial' ) ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	meeha_save_editorial_fields( $post_id, $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized per field
}
add_action( 'save_post_post', 'meeha_save_editorial_meta' );

function meeha_save_summary_meta_box( $post_id ) {
	if ( ! isset( $_POST['meeha_summary_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['meeha_summary_nonce'] ) ), 'meeha_save_summary' ) ) {
		return;
	}
	if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	if ( isset( $_POST['meeha_summary'] ) ) {
		$summary = sanitize_textarea_field( wp_unslash( $_POST['meeha_summary'] ) );
		if ( '' === trim( $summary ) ) {
			delete_post_meta( $post_id, '_meeha_summary' );
		} else {
			update_post_meta( $post_id, '_meeha_summary', $summary );
		}
	}
}
add_action( 'save_post', 'meeha_save_summary_meta_box' );

/* =====================================================================
   Posts list columns + Quick Edit
===================================================================== */
function meeha_editorial_columns( $columns ) {
	$out = array();
	foreach ( $columns as $k => $label ) {
		$out[ $k ] = $label;
		if ( 'title' === $k ) {
			$out['meeha_topic'] = 'টপিক';
			$out['meeha_flags'] = 'সম্পাদকীয়';
		}
	}
	if ( ! isset( $out['meeha_flags'] ) ) {
		$out['meeha_topic'] = 'টপিক';
		$out['meeha_flags'] = 'সম্পাদকীয়';
	}
	return $out;
}
add_filter( 'manage_post_posts_columns', 'meeha_editorial_columns' );

function meeha_editorial_column_content( $column, $post_id ) {
	if ( 'meeha_topic' === $column ) {
		$topic = get_post_meta( $post_id, '_meeha_topic', true );
		echo $topic ? esc_html( $topic ) : '<span aria-hidden="true">—</span>';
		return;
	}
	if ( 'meeha_flags' !== $column ) {
		return;
	}
	$v      = meeha_editorial_values( $post_id );
	$badges = array();
	if ( '1' === $v['breaking'] ) {
		$badges[] = '<span class="meeha-ed__badge meeha-ed__badge--red">ব্রেকিং</span>';
	}
	if ( '1' === $v['trending'] ) {
		$expired  = $v['trending_at'] && ( time() - (int) $v['trending_at'] ) > DAY_IN_SECONDS;
		$badges[] = '<span class="meeha-ed__badge' . ( $expired ? ' is-expired' : '' ) . '" title="' . esc_attr( $expired ? 'পিনের মেয়াদ শেষ' : 'ট্রেন্ডিং পিন' ) . '">পিন</span>';
	}
	if ( '1' === $v['glance'] ) {
		$badges[] = '<span class="meeha-ed__badge">একনজরে</span>';
	}
	if ( '1' === $v['investigation'] ) {
		$badges[] = '<span class="meeha-ed__badge meeha-ed__badge--ink">অনুসন্ধান</span>';
	}
	echo $badges ? implode( ' ', $badges ) : '<span aria-hidden="true">—</span>'; // phpcs:ignore WordPress.Security.EscapeOutput -- static markup
	// Quick Edit pre-fill data (read by assets/js/admin-quickedit.js)
	echo '<span class="hidden meeha-qe-data" data-meeha-qe="' . esc_attr( wp_json_encode( $v ) ) . '"></span>';
	// legacy hidden values (old admin JS / tooling)
	echo '<div class="hidden meeha-trending-inline-value">' . esc_html( '1' === $v['trending'] ? '1' : '0' ) . '</div>';
	echo '<div class="hidden meeha-topic-inline-value">' . esc_html( $v['topic'] ) . '</div>';
}
add_action( 'manage_post_posts_custom_column', 'meeha_editorial_column_content', 10, 2 );

function meeha_editorial_quick_edit( $column_name, $post_type ) {
	if ( 'meeha_flags' !== $column_name || 'post' !== $post_type ) {
		return;
	}
	wp_nonce_field( 'meeha_save_editorial', 'meeha_editorial_nonce' );
	?>
	<fieldset class="inline-edit-col-right meeha-qe">
		<div class="inline-edit-col">
			<span class="title inline-edit-categories-label">মিহা সম্পাদকীয়</span>
			<?php echo meeha_editorial_fields( array(), 'qe' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		</div>
	</fieldset>
	<?php
}
add_action( 'quick_edit_custom_box', 'meeha_editorial_quick_edit', 10, 2 );

/** Admin assets: Quick Edit pre-fill + counter + small styles. */
function meeha_editorial_admin_assets( $hook ) {
	$screen = get_current_screen();
	if ( ! $screen || 'post' !== $screen->post_type || ! in_array( $hook, array( 'edit.php', 'post.php', 'post-new.php' ), true ) ) {
		return;
	}
	$deps = 'edit.php' === $hook ? array( 'inline-edit-post' ) : array();
	wp_enqueue_script( 'meeha-admin-quickedit', MEEHA_URI . '/assets/js/admin-quickedit.js', $deps, meeha_legacy_ver( 'assets/js/admin-quickedit.js' ), true );
	wp_add_inline_style(
		'common',
		'.meeha-ed__row{margin:0 0 12px}.meeha-ed__row label{display:block;margin-bottom:4px}.meeha-ed__hint{color:#646970;font-weight:400}'
		. '.meeha-ed__flags{display:grid;gap:8px;margin:0 0 12px;padding:10px 0;border-block:1px solid #dcdcde}.meeha-ed__flags label{display:flex;align-items:center;gap:6px;line-height:1.5}'
		. '.meeha-ed__badge{display:inline-block;padding:1px 8px;border-radius:999px;background:#EEF4FB;color:#01468C;font-size:12px;line-height:1.7;white-space:nowrap}'
		. '.meeha-ed__badge--red{background:#FCEDEB;color:#CC2A1F}.meeha-ed__badge--ink{background:#0B2746;color:#fff}.meeha-ed__badge.is-expired{opacity:.5;text-decoration:line-through}'
		. '.meeha-ed__count{display:block;text-align:right;color:#646970;font-size:12px}.meeha-ed__count.is-over{color:#CC2A1F}.meeha-ed__note{display:block;margin:-4px 0 0 24px}'
		. '.meeha-qe .meeha-ed{max-width:420px}.column-meeha_flags{width:150px}.column-meeha_topic{width:140px}'
	);
}
add_action( 'admin_enqueue_scripts', 'meeha_editorial_admin_assets' );
