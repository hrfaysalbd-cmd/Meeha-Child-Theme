<?php
/**
 * CSV question import (প্রস্তুতি › প্রশ্ন ইমপোর্ট).
 * Columns: question, a, b, c, d, answer (ক/খ/গ/ঘ, A–D or 1–4), explanation, subject, tracks, source, quiz.
 *
 * @package meeha-prep
 */

defined( 'ABSPATH' ) || exit;

/**
 * Importer.
 */
class MHP_Importer {

	const MAX_ROWS = 2000;

	/**
	 * Page.
	 */
	public static function page() {
		if ( ! mhp_can_manage() ) {
			wp_die( esc_html( 'অনুমতি নেই।' ) );
		}
		$r = get_transient( 'mhp_import_' . get_current_user_id() );
		if ( $r ) {
			delete_transient( 'mhp_import_' . get_current_user_id() );
		}
		?>
		<div class="wrap">
			<h1>প্রশ্ন ইমপোর্ট (CSV)</h1>
			<?php if ( $r ) : ?>
				<div class="notice notice-<?php echo $r['added'] ? 'success' : 'warning'; ?>"><p>
					<?php echo esc_html( sprintf( 'যোগ হয়েছে %d, আগে থেকে ছিল %d, বাদ পড়েছে %d।', $r['added'], $r['dupes'], count( $r['errors'] ) ) ); ?>
				</p>
				<?php if ( $r['errors'] ) : ?>
					<ul style="list-style:disc;margin-left:20px">
						<?php foreach ( array_slice( $r['errors'], 0, 30 ) as $e ) : ?>
							<li><?php echo esc_html( $e ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
				</div>
			<?php endif; ?>
			<p>এক্সেল বা গুগল শিটে নিচের কলামগুলো রেখে <b>CSV (UTF-8)</b> হিসেবে সেভ করুন। প্রথম সারিতে কলামের নাম থাকবে।</p>
			<table class="widefat striped" style="max-width:900px">
				<thead><tr><th>কলাম</th><th>কী লিখবেন</th></tr></thead>
				<tbody>
					<tr><td><code>question</code></td><td>প্রশ্ন (আবশ্যক)</td></tr>
					<tr><td><code>a</code>, <code>b</code>, <code>c</code>, <code>d</code></td><td>চারটি অপশন (অন্তত a ও b আবশ্যক)</td></tr>
					<tr><td><code>answer</code></td><td>সঠিক অপশন: ক/খ/গ/ঘ, অথবা A/B/C/D, অথবা 1/2/3/4</td></tr>
					<tr><td><code>explanation</code></td><td>ব্যাখ্যা</td></tr>
					<tr><td><code>subject</code></td><td>বিষয়ের নাম বা স্লাগ (না থাকলে তৈরি হবে)</td></tr>
					<tr><td><code>tracks</code></td><td>ট্র্যাকের স্লাগ, কমা দিয়ে (যেমন: bcs,primary)। শুধু বিদ্যমান ট্র্যাক নেওয়া হয়।</td></tr>
					<tr><td><code>source</code></td><td>উৎস (যেমন: ৪৪তম বিসিএস)</td></tr>
					<tr><td><code>quiz</code></td><td>দৈনিক কুইজে ব্যবহারযোগ্য হলে 1</td></tr>
				</tbody>
			</table>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:16px">
				<input type="hidden" name="action" value="mhp_import">
				<?php wp_nonce_field( 'mhp_import' ); ?>
				<p><input type="file" name="csv" accept=".csv,text/csv" required></p>
				<p><label><input type="radio" name="status" value="draft" checked> খসড়া হিসেবে (যাচাইয়ের পর প্রকাশ করবেন)</label><br>
				<label><input type="radio" name="status" value="publish"> সরাসরি প্রকাশ</label></p>
				<p class="description">একই প্রশ্ন (হুবহু একই লেখা) আগে থেকে থাকলে বাদ যাবে। একবারে সর্বোচ্চ <?php echo esc_html( mhp_num( self::MAX_ROWS ) ); ?>টি সারি।</p>
				<?php submit_button( 'ইমপোর্ট করুন' ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Map an answer cell to 0–3.
	 *
	 * @param string $v Cell.
	 * @return int -1 when invalid.
	 */
	public static function answer_index( $v ) {
		$v   = strtoupper( trim( meeha_prep_en_digits( (string) $v ) ) );
		$map = array(
			'ক' => 0,
			'খ' => 1,
			'গ' => 2,
			'ঘ' => 3,
			'A' => 0,
			'B' => 1,
			'C' => 2,
			'D' => 3,
			'1' => 0,
			'2' => 1,
			'3' => 2,
			'4' => 3,
		);
		return isset( $map[ $v ] ) ? $map[ $v ] : -1;
	}

	/**
	 * Handle the upload.
	 */
	public static function handle() {
		if ( ! mhp_can_manage() ) {
			wp_die( esc_html( 'অনুমতি নেই।' ), 403 );
		}
		check_admin_referer( 'mhp_import' );
		$back = admin_url( 'admin.php?page=mhp-import' );
		$res  = array(
			'added'  => 0,
			'dupes'  => 0,
			'errors' => array(),
		);
		$status = ( isset( $_POST['status'] ) && 'publish' === $_POST['status'] && current_user_can( 'publish_mhp_items' ) ) ? 'publish' : 'draft';
		if ( empty( $_FILES['csv']['tmp_name'] ) || ! is_uploaded_file( $_FILES['csv']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$res['errors'][] = 'ফাইল পাওয়া যায়নি।';
			set_transient( 'mhp_import_' . get_current_user_id(), $res, 300 );
			wp_safe_redirect( $back );
			exit;
		}
		$name = sanitize_file_name( wp_unslash( (string) $_FILES['csv']['name'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( 'csv' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
			$res['errors'][] = 'শুধু .csv ফাইল দিন।';
			set_transient( 'mhp_import_' . get_current_user_id(), $res, 300 );
			wp_safe_redirect( $back );
			exit;
		}
		$fh = fopen( $_FILES['csv']['tmp_name'], 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.Security.ValidatedSanitizedInput
		if ( ! $fh ) {
			wp_safe_redirect( $back );
			exit;
		}
		$head = fgetcsv( $fh );
		if ( ! $head ) {
			$res['errors'][] = 'ফাইলটি খালি।';
		} else {
			$head[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $head[0] ); // UTF-8 BOM from Excel.
			$head    = array_map(
				function ( $h ) {
					return strtolower( trim( (string) $h ) );
				},
				$head
			);
			if ( ! in_array( 'question', $head, true ) || ! in_array( 'answer', $head, true ) ) {
				$res['errors'][] = 'প্রথম সারিতে অন্তত "question" ও "answer" কলাম থাকতে হবে।';
			} else {
				if ( function_exists( 'set_time_limit' ) ) {
					@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				}
				wp_defer_term_counting( true );
				$line = 1;
				while ( ( $row = fgetcsv( $fh ) ) !== false ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
					++$line;
					if ( $line > self::MAX_ROWS + 1 ) {
						$res['errors'][] = 'সারির সীমা পেরিয়েছে; বাকিগুলো নেওয়া হয়নি।';
						break;
					}
					if ( count( array_filter( $row, 'strlen' ) ) === 0 ) {
						continue;
					}
					$r = array();
					foreach ( $head as $i => $h ) {
						$r[ $h ] = isset( $row[ $i ] ) ? trim( (string) $row[ $i ] ) : '';
					}
					$err = self::import_row( $r, $status );
					if ( true === $err ) {
						++$res['added'];
					} elseif ( 'dupe' === $err ) {
						++$res['dupes'];
					} else {
						$res['errors'][] = 'সারি ' . $line . ': ' . $err;
					}
				}
				wp_defer_term_counting( false );
			}
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		set_transient( 'mhp_import_' . get_current_user_id(), $res, 300 );
		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * Import one row.
	 *
	 * @param array  $r      Row by column.
	 * @param string $status Post status.
	 * @return true|string True, 'dupe' or an error message.
	 */
	private static function import_row( $r, $status ) {
		global $wpdb;
		$q = sanitize_text_field( isset( $r['question'] ) ? $r['question'] : '' );
		if ( '' === $q ) {
			return 'প্রশ্ন নেই';
		}
		$opts = array();
		foreach ( array( 'a', 'b', 'c', 'd' ) as $k ) {
			$v = sanitize_text_field( isset( $r[ $k ] ) ? $r[ $k ] : '' );
			if ( '' !== $v ) {
				$opts[] = $v;
			}
		}
		if ( count( $opts ) < 2 ) {
			return 'অন্তত দুটি অপশন লাগবে';
		}
		$ans = self::answer_index( isset( $r['answer'] ) ? $r['answer'] : '' );
		if ( $ans < 0 || $ans >= count( $opts ) ) {
			return 'সঠিক উত্তর বোঝা যায়নি ("' . sanitize_text_field( isset( $r['answer'] ) ? $r['answer'] : '' ) . '")';
		}
		$dupe = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'mh_question' AND post_title = %s AND post_status <> 'trash' LIMIT 1", $q ) ); // phpcs:ignore WordPress.DB
		if ( $dupe ) {
			return 'dupe';
		}
		$id = wp_insert_post(
			array(
				'post_type'   => 'mh_question',
				'post_status' => $status,
				'post_title'  => $q,
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id->get_error_message();
		}
		update_post_meta( $id, '_mhp_opts', $opts );
		update_post_meta( $id, '_mhp_answer', $ans );
		update_post_meta( $id, '_mhp_explain', sanitize_textarea_field( isset( $r['explanation'] ) ? $r['explanation'] : '' ) );
		update_post_meta( $id, '_mhp_source', sanitize_text_field( isset( $r['source'] ) ? $r['source'] : '' ) );
		update_post_meta( $id, '_mhp_quiz_ok', ! empty( $r['quiz'] ) && '0' !== $r['quiz'] ? 1 : 0 );
		$subj = sanitize_text_field( isset( $r['subject'] ) ? $r['subject'] : '' );
		if ( '' !== $subj ) {
			$t = get_term_by( 'slug', sanitize_title( $subj ), 'mh_subject' );
			if ( ! $t ) {
				$t = get_term_by( 'name', $subj, 'mh_subject' );
			}
			if ( ! $t ) {
				$new = wp_insert_term( $subj, 'mh_subject' );
				$t   = is_wp_error( $new ) ? null : get_term( $new['term_id'], 'mh_subject' );
			}
			if ( $t ) {
				wp_set_object_terms( $id, array( (int) $t->term_id ), 'mh_subject' );
			}
		}
		$tracks = array();
		foreach ( array_filter( array_map( 'trim', explode( ',', isset( $r['tracks'] ) ? $r['tracks'] : '' ) ) ) as $s ) {
			$t = get_term_by( 'slug', sanitize_title( $s ), 'mh_track' );
			if ( ! $t ) {
				$t = get_term_by( 'name', $s, 'mh_track' );
			}
			if ( $t ) {
				$tracks[] = (int) $t->term_id;
			}
		}
		if ( $tracks ) {
			wp_set_object_terms( $id, $tracks, 'mh_track' );
		}
		return true;
	}
}
