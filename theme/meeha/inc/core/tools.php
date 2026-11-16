<?php
/**
 * মিহা থিম › টুলস: migration report (+ re-run), thumbnail regeneration for the new sizes (batched AJAX),
 * Elementor → ব্লক converter (preview / apply / undo, per page), flush permalinks.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

meeha_register_settings_tab(
	'tools',
	array(
		'title'  => 'টুলস',
		'order'  => 90,
		'cap'    => 'manage_meeha_design', // raw cap: hidden (not read-only) for editors.
		'form'   => false,
		'render' => 'meeha_tools_render',
	)
);

/**
 * Theme image sizes the regenerator cares about.
 *
 * @return string[]
 */
function meeha_tools_sizes() {
	return array( 'meeha-16x9', 'meeha-16x9-m', 'meeha-16x9-s', 'meeha-sq', 'meeha-9x16' );
}

/**
 * Image attachments missing at least one theme size that their original is large enough for.
 *
 * @param int $limit Max IDs.
 * @return int[]
 */
function meeha_tools_missing_ids( $limit = 5000 ) {
	global $wpdb;
	$ids  = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE %s ORDER BY ID DESC LIMIT %d", 'image/%', (int) $limit ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$need = array();
	$reg  = wp_get_registered_image_subsizes();
	foreach ( $ids as $id ) {
		$meta = wp_get_attachment_metadata( (int) $id );
		if ( ! is_array( $meta ) || empty( $meta['width'] ) ) {
			continue;
		}
		foreach ( meeha_tools_sizes() as $size ) {
			if ( empty( $reg[ $size ] ) || isset( $meta['sizes'][ $size ] ) ) {
				continue;
			}
			// WordPress only creates a crop when the original is larger in at least one dimension.
			if ( (int) $meta['width'] > (int) $reg[ $size ]['width'] || (int) $meta['height'] > (int) $reg[ $size ]['height'] ) {
				$need[] = (int) $id;
				break;
			}
		}
	}
	return $need;
}

add_action(
	'wp_ajax_meeha_regen_scan',
	function () {
		check_ajax_referer( 'meeha_regen', 'nonce' );
		if ( ! current_user_can( 'manage_meeha_design' ) || ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( 'অনুমতি নেই', 403 );
		}
		wp_send_json_success( array( 'ids' => meeha_tools_missing_ids() ) );
	}
);

add_action(
	'wp_ajax_meeha_regen_batch',
	function () {
		check_ajax_referer( 'meeha_regen', 'nonce' );
		if ( ! current_user_can( 'manage_meeha_design' ) || ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( 'অনুমতি নেই', 403 );
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$ids  = isset( $_POST['ids'] ) ? array_slice( array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ), 0, 10 ) : array();
		$done = 0;
		$errs = array();
		foreach ( $ids as $id ) {
			if ( ! $id || ! wp_attachment_is_image( $id ) ) {
				continue;
			}
			$r = wp_update_image_subsizes( $id );
			if ( is_wp_error( $r ) ) {
				$errs[] = '#' . $id . ': ' . $r->get_error_message();
			} else {
				$done++;
			}
		}
		wp_send_json_success(
			array(
				'done'   => $done,
				'errors' => $errs,
			)
		);
	}
);

/**
 * Admin-post actions (convert / undo / flush / rerun migration).
 */
add_action(
	'admin_post_meeha_tools',
	function () {
		check_admin_referer( 'meeha_tools' );
		if ( ! current_user_can( 'manage_meeha_design' ) ) {
			wp_die( esc_html( 'অনুমতি নেই।' ), 403 );
		}
		$do  = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$pid = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$msg = '';
		$err = '';
		switch ( $do ) {
			case 'flush':
				flush_rewrite_rules( false );
				$msg = 'পার্মালিংক নতুন করে তৈরি হয়েছে।';
				break;
			case 'migrate':
				meeha_migrate_run( 'manual' );
				$msg = 'মাইগ্রেশন আবার চালানো হয়েছে (যা আগে থেকেই আছে তা বদলায়নি)।';
				break;
			case 'convert':
			case 'undo':
				if ( ! $pid || ! current_user_can( 'edit_post', $pid ) ) {
					wp_die( esc_html( 'এই পাতা বদলানোর অনুমতি নেই।' ), 403 );
				}
				$r = 'convert' === $do ? meeha_elementor_apply( $pid ) : meeha_elementor_undo( $pid );
				if ( is_wp_error( $r ) ) {
					$err = $r->get_error_message();
				} else {
					$msg = 'convert' === $do ? '"' . get_the_title( $pid ) . '" ব্লকে রূপান্তরিত হয়েছে। Elementor-এর ডেটা রেখে দেওয়া হয়েছে — "ফিরিয়ে আনুন" দিয়ে আগের অবস্থায় যাওয়া যাবে।' : '"' . get_the_title( $pid ) . '" আগের Elementor অবস্থায় ফিরেছে।';
				}
				break;
		}
		set_transient(
			'meeha_settings_notices_' . get_current_user_id(),
			array_filter(
				array(
					$msg ? array( $msg, 'success' ) : null,
					$err ? array( $err, 'error' ) : null,
				)
			),
			120
		);
		wp_safe_redirect( meeha_settings_url( 'tools' ) );
		exit;
	}
);

/**
 * A small POST button form.
 *
 * @param string $do      Action.
 * @param string $label   Label.
 * @param int    $post_id Post.
 * @param string $class   Button class.
 * @param string $confirm Confirm text.
 * @return string
 */
function meeha_tools_button( $do, $label, $post_id = 0, $class = 'button', $confirm = '' ) {
	$out  = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline"' . ( $confirm ? ' onsubmit="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ')"' : '' ) . '>';
	$out .= '<input type="hidden" name="action" value="meeha_tools"><input type="hidden" name="do" value="' . esc_attr( $do ) . '">';
	$out .= $post_id ? '<input type="hidden" name="post_id" value="' . (int) $post_id . '">' : '';
	$out .= wp_nonce_field( 'meeha_tools', '_wpnonce', true, false );
	$out .= '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
	return $out;
}

/**
 * Render the tools tab.
 *
 * @param string $tab    Tab id.
 * @param array  $values Values (unused).
 * @param array  $ctx    Context.
 */
function meeha_tools_render( $tab, $values, $ctx ) {
	$can = current_user_can( 'manage_meeha_design' );

	// 1. Migration report.
	echo '<h2>মাইগ্রেশন রিপোর্ট</h2>';
	echo meeha_migration_report_html( get_option( 'meeha_migration_report', array() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	if ( $can ) {
		echo '<p>' . meeha_tools_button( 'migrate', 'আবার চালান' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	// 2. Thumbnails.
	echo '<hr><h2>থাম্বনেইল তৈরি</h2>';
	echo '<p>নতুন থিমের ছবির মাপ (১২০০ × ৬৭৫, ৭৬৮ × ৪৩২, ৪৮০ × ২৭০, ৪০০ × ৪০০, ৫৪০ × ৯৬০) পুরোনো ছবির জন্য তৈরি করে। না করলেও সাইট চলবে — তখন থিম সবচেয়ে কাছের বিদ্যমান মাপ ব্যবহার করে। একবারে ১০টি করে ছবি প্রক্রিয়া হয়; পাতা খোলা রাখুন।</p>';
	if ( $can && current_user_can( 'upload_files' ) ) {
		echo '<p><button type="button" class="button button-primary" id="meeha-regen">শুরু করুন</button> <span id="meeha-regen-status" role="status"></span></p>';
		echo '<progress id="meeha-regen-bar" max="100" value="0" style="width:100%;max-width:480px" hidden></progress>';
		$nonce = wp_create_nonce( 'meeha_regen' );
		?>
		<script>
		(function(){
			var b=document.getElementById('meeha-regen'),st=document.getElementById('meeha-regen-status'),bar=document.getElementById('meeha-regen-bar');
			var bn=function(n){return String(n).replace(/\d/g,function(d){return '০১২৩৪৫৬৭৮৯'[d];});};
			function post(data){var f=new FormData();Object.keys(data).forEach(function(k){[].concat(data[k]).forEach(function(v){f.append(Array.isArray(data[k])?k+'[]':k,v);});});f.append('nonce',<?php echo wp_json_encode( $nonce ); ?>);return fetch(ajaxurl,{method:'POST',body:f,credentials:'same-origin'}).then(function(r){return r.json();});}
			b&&b.addEventListener('click',function(){
				b.disabled=true;st.textContent='খোঁজা হচ্ছে…';
				post({action:'meeha_regen_scan'}).then(function(r){
					var ids=(r&&r.success)?r.data.ids:[],total=ids.length,done=0,errs=[];
					if(!total){st.textContent='সব ছবির নতুন মাপ আগে থেকেই আছে।';b.disabled=false;return;}
					bar.hidden=false;bar.max=total;
					(function next(){
						if(!ids.length){st.textContent='শেষ: '+bn(done)+'টি ছবি'+(errs.length?' · ত্রুটি '+bn(errs.length)+'টি':'');b.disabled=false;return;}
						var chunk=ids.splice(0,10);
						post({action:'meeha_regen_batch',ids:chunk}).then(function(r){if(r&&r.success){done+=r.data.done;errs=errs.concat(r.data.errors);}bar.value=total-ids.length;st.textContent=bn(total-ids.length)+' / '+bn(total);next();}).catch(function(){errs.push('network');next();});
					})();
				});
			});
		})();
		</script>
		<?php
	}

	// 3. Elementor → blocks.
	echo '<hr><h2>Elementor → ব্লক</h2>';
	echo '<p>Elementor দিয়ে বানানো পাতার লেখা (শিরোনাম, অনুচ্ছেদ, তালিকা, ছবি, বোতাম, শর্টকোড) সাধারণ ব্লকে রূপান্তর করে থিমের "পাতা" টেমপ্লেটে দেখায় — Elementor-এর ~১৫০ KB CSS/JS আর লাগে না। Elementor-এর ডেটা মোছা হয় না; "ফিরিয়ে আনুন" দিয়ে আগের অবস্থায় যাওয়া যায় (রিভিশনও থাকে)।</p>';
	$pages = get_posts(
		array(
			'post_type'      => 'page',
			'post_status'    => array( 'publish', 'draft', 'private' ),
			'posts_per_page' => 100,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				'relation' => 'OR',
				array(
					'key'   => '_elementor_edit_mode',
					'value' => 'builder',
				),
				array(
					'key'     => '_meeha_elementor_backup',
					'compare' => 'EXISTS',
				),
			),
		)
	);
	$preview = isset( $_GET['preview'] ) ? absint( $_GET['preview'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
	if ( ! $pages ) {
		echo '<p><em>Elementor দিয়ে বানানো কোনো পাতা পাওয়া যায়নি।</em></p>';
	} else {
		echo '<table class="widefat striped" style="max-width:960px"><thead><tr><th>পাতা</th><th>অবস্থা</th><th>কাজ</th></tr></thead><tbody>';
		foreach ( $pages as $p ) {
			$backup = get_post_meta( $p->ID, '_meeha_elementor_backup', true );
			echo '<tr><td><a href="' . esc_url( get_permalink( $p ) ) . '" target="_blank" rel="noopener">' . esc_html( get_the_title( $p ) ) . '</a></td><td>' . ( $backup ? 'ব্লকে রূপান্তরিত' : 'Elementor' ) . '</td><td>';
			if ( current_user_can( 'edit_post', $p->ID ) ) {
				if ( $backup ) {
					echo meeha_tools_button( 'undo', 'ফিরিয়ে আনুন', $p->ID, 'button', 'পাতাটি আগের Elementor অবস্থায় ফিরবে। নিশ্চিত?' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				} else {
					echo '<a class="button" href="' . esc_url( add_query_arg( 'preview', $p->ID, meeha_settings_url( 'tools' ) ) . '#meeha-preview' ) . '">প্রিভিউ</a> ';
					echo meeha_tools_button( 'convert', 'রূপান্তর করুন', $p->ID, 'button button-primary', 'পাতার লেখা ব্লকে বদলাবে (Elementor ডেটা থাকবে)। নিশ্চিত?' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}
	if ( $preview && current_user_can( 'edit_post', $preview ) ) {
		$conv = meeha_elementor_convert( $preview );
		echo '<h3 id="meeha-preview">প্রিভিউ: ' . esc_html( get_the_title( $preview ) ) . '</h3>';
		if ( $conv['error'] ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $conv['error'] ) . '</p></div>';
		} else {
			if ( $conv['unknown'] ) {
				echo '<div class="notice notice-warning inline"><p>এই উইজেটগুলোর শুধু লেখা আনা গেছে, নকশা নয়: <code>' . esc_html( implode( ', ', $conv['unknown'] ) ) . '</code></p></div>';
			}
			echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;max-width:1200px"><div><h4>দেখতে যেমন হবে</h4><div style="padding:16px;border:1px solid #dcdcde;background:#fff;max-height:520px;overflow:auto">' . wp_kses_post( do_blocks( $conv['content'] ) ) . '</div></div>';
			echo '<div><h4>ব্লক মার্কআপ</h4><textarea readonly class="large-text code" rows="24">' . esc_textarea( $conv['content'] ) . '</textarea></div></div>';
			echo '<p>' . meeha_tools_button( 'convert', 'এই রূপান্তর প্রয়োগ করুন', $preview, 'button button-primary' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	// 4. Sample content (CONTRACT §9.2).
	if ( function_exists( 'meeha_samples_tools_section' ) ) {
		meeha_samples_tools_section();
	}

	// 5. Permalinks.
	echo '<hr><h2>পার্মালিংক</h2><p>নতুন পোস্ট টাইপ বা স্লাগ (যেমন /quote/) ৪০৪ দেখালে একবার চাপুন।</p>';
	if ( $can ) {
		echo '<p>' . meeha_tools_button( 'flush', 'পার্মালিংক ফ্লাশ করুন' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
