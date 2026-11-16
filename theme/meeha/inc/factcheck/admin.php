<?php
/**
 * ফ্যাক্টচেক — wp-admin meta box (classic and block editor) + a column in the posts list.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'add_meta_boxes_post',
	function () {
		add_meta_box( 'meeha-factcheck', 'ফ্যাক্টচেক', 'meeha_fc_metabox', 'post', 'normal', 'high' );
	}
);

/**
 * Meta box body.
 *
 * @param WP_Post $post Post.
 */
function meeha_fc_metabox( $post ) {
	wp_nonce_field( 'meeha_fc_save', 'meeha_fc_nonce' );
	$on      = (bool) get_post_meta( $post->ID, '_meeha_fc', true );
	$f       = function ( $k ) use ( $post ) {
		return get_post_meta( $post->ID, '_meeha_fc_' . $k, true );
	};
	$spread  = (array) $f( 'spread' );
	$sources = (array) $f( 'sources' );
	$src_txt = implode(
		"\n",
		array_map(
			function ( $s ) {
				return trim( ( isset( $s['title'] ) ? $s['title'] : '' ) . ' | ' . ( isset( $s['url'] ) ? $s['url'] : '' ), ' |' );
			},
			$sources
		)
	);
	?>
	<style>
		.meeha-fc-box label{display:block;font-weight:600;margin:12px 0 4px}
		.meeha-fc-box input[type=text],.meeha-fc-box input[type=date],.meeha-fc-box select,.meeha-fc-box textarea{width:100%;max-width:100%}
		.meeha-fc-box .hint{color:#646970;font-weight:400;font-size:12px}
		.meeha-fc-box .row{display:grid;grid-template-columns:2fr 1fr 1fr;gap:12px}
		.meeha-fc-verdicts{display:flex;flex-wrap:wrap;gap:8px}
		.meeha-fc-verdicts label{display:inline-flex;align-items:center;gap:6px;margin:0;padding:6px 10px;border:1px solid #c3c4c7;border-radius:999px;font-weight:600;cursor:pointer}
		.meeha-fc-verdicts input:checked+span{color:#fff;background:var(--c);padding:2px 8px;border-radius:999px}
	</style>
	<div class="meeha-fc-box">
		<p><label style="display:inline-flex;gap:8px;align-items:center;margin:0"><input type="checkbox" name="meeha_fc[on]" value="1" <?php checked( $on ); ?>> এটি একটি ফ্যাক্টচেক প্রতিবেদন</label>
		<span class="hint">— চালু করলে লেখার শুরুতে রায়-কার্ড, Google-এর ClaimReview ডেটা ও “ফ্যাক্টচেক” ট্যাগ স্বয়ংক্রিয়ভাবে যোগ হবে।</span></p>

		<label for="meeha-fc-claim">দাবি <span class="hint">(যে কথাটি যাচাই করা হলো, এক-দুই বাক্যে)</span></label>
		<textarea id="meeha-fc-claim" name="meeha_fc[claim]" rows="2"><?php echo esc_textarea( (string) $f( 'claim' ) ); ?></textarea>

		<div class="row">
			<div>
				<label for="meeha-fc-claimant">দাবিকারী <span class="hint">(ব্যক্তি, পেজ বা প্রতিষ্ঠান)</span></label>
				<input type="text" id="meeha-fc-claimant" name="meeha_fc[claimant]" value="<?php echo esc_attr( (string) $f( 'claimant' ) ); ?>">
			</div>
			<div>
				<label for="meeha-fc-ctype">দাবিকারীর ধরন</label>
				<select id="meeha-fc-ctype" name="meeha_fc[claimant_type]">
					<option value="person" <?php selected( 'org' !== $f( 'claimant_type' ) ); ?>>ব্যক্তি</option>
					<option value="org" <?php selected( 'org' === $f( 'claimant_type' ) ); ?>>পেজ / প্রতিষ্ঠান</option>
				</select>
			</div>
			<div>
				<label for="meeha-fc-date">দাবির তারিখ</label>
				<input type="date" id="meeha-fc-date" name="meeha_fc[claim_date]" value="<?php echo esc_attr( (string) $f( 'claim_date' ) ); ?>">
			</div>
		</div>

		<label for="meeha-fc-spread">যেখানে ছড়িয়েছে <span class="hint">(ফেসবুক / ইউটিউব / টিকটক লিংক — প্রতি লাইনে একটি)</span></label>
		<textarea id="meeha-fc-spread" name="meeha_fc[spread]" rows="3" placeholder="https://www.facebook.com/…"><?php echo esc_textarea( implode( "\n", $spread ) ); ?></textarea>

		<label>রায়</label>
		<div class="meeha-fc-verdicts" role="radiogroup" aria-label="রায়">
			<?php foreach ( meeha_fc_verdicts() as $k => $v ) : ?>
				<label style="--c:<?php echo esc_attr( $v[1] ); ?>"><input type="radio" name="meeha_fc[verdict]" value="<?php echo esc_attr( $k ); ?>" <?php checked( $f( 'verdict' ), $k ); ?>><span><?php echo esc_html( $v[0] ); ?></span></label>
			<?php endforeach; ?>
		</div>

		<label for="meeha-fc-evidence">যেভাবে যাচাই করেছি <span class="hint">(প্রমাণের সারাংশ — কোন টুল, কার সঙ্গে কথা, কী পাওয়া গেল)</span></label>
		<textarea id="meeha-fc-evidence" name="meeha_fc[evidence]" rows="6"><?php echo esc_textarea( (string) $f( 'evidence' ) ); ?></textarea>

		<label for="meeha-fc-sources">সূত্র <span class="hint">(প্রতি লাইনে: শিরোনাম | https://লিংক)</span></label>
		<textarea id="meeha-fc-sources" name="meeha_fc[sources]" rows="4" placeholder="নির্বাচন কমিশনের বিজ্ঞপ্তি | https://…"><?php echo esc_textarea( $src_txt ); ?></textarea>
	</div>
	<?php
}

add_action(
	'save_post_post',
	function ( $post_id ) {
		if ( ! isset( $_POST['meeha_fc_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['meeha_fc_nonce'] ) ), 'meeha_fc_save' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$in = isset( $_POST['meeha_fc'] ) && is_array( $_POST['meeha_fc'] ) ? wp_unslash( $_POST['meeha_fc'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per field by register_post_meta.
		$sources = array();
		foreach ( preg_split( '/[\r\n]+/', isset( $in['sources'] ) ? (string) $in['sources'] : '' ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			if ( preg_match( '#^(.*?)\s*\|\s*(https?://\S+)$#u', $line, $m ) ) {
				$sources[] = array( 'title' => $m[1], 'url' => $m[2] );
			} elseif ( preg_match( '#^https?://\S+$#', $line ) ) {
				$sources[] = array( 'title' => '', 'url' => $line );
			} else {
				$sources[] = array( 'title' => $line, 'url' => '' );
			}
		}
		$vals = array(
			'_meeha_fc_claim'         => isset( $in['claim'] ) ? $in['claim'] : '',
			'_meeha_fc_claimant'      => isset( $in['claimant'] ) ? $in['claimant'] : '',
			'_meeha_fc_claimant_type' => isset( $in['claimant_type'] ) ? $in['claimant_type'] : 'person',
			'_meeha_fc_claim_date'    => isset( $in['claim_date'] ) ? $in['claim_date'] : '',
			'_meeha_fc_spread'        => isset( $in['spread'] ) ? (string) $in['spread'] : '',
			'_meeha_fc_verdict'       => isset( $in['verdict'] ) ? $in['verdict'] : '',
			'_meeha_fc_evidence'      => isset( $in['evidence'] ) ? $in['evidence'] : '',
			'_meeha_fc_sources'       => $sources,
		);
		foreach ( $vals as $k => $v ) {
			update_post_meta( $post_id, $k, meeha_fc_sanitize( $k, $v ) );
		}
		// The flag last: its meta hook tags the post (bootstrap.php).
		if ( ! empty( $in['on'] ) ) {
			update_post_meta( $post_id, '_meeha_fc', true );
		} else {
			delete_post_meta( $post_id, '_meeha_fc' );
		}
	}
);

/* Posts list: a রায় column. */
add_filter(
	'manage_post_posts_columns',
	function ( $cols ) {
		$cols['meeha_fc'] = 'ফ্যাক্টচেক';
		return $cols;
	}
);
add_action(
	'manage_post_posts_custom_column',
	function ( $col, $post_id ) {
		if ( 'meeha_fc' !== $col ) {
			return;
		}
		$fc = meeha_fc_get( $post_id );
		if ( $fc ) {
			$v = meeha_fc_verdicts();
			echo isset( $v[ $fc['verdict'] ] ) ? '<span style="display:inline-block;padding:2px 8px;border-radius:999px;color:#fff;background:' . esc_attr( $v[ $fc['verdict'] ][1] ) . '">' . esc_html( $v[ $fc['verdict'] ][0] ) . '</span>' : 'রায় বাকি';
		} else {
			echo '—';
		}
	},
	10,
	2
);
