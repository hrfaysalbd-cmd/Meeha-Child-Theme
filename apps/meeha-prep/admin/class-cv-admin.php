<?php
/**
 * CV studio admin: template import/export (JSON packages), preview with placeholder content, format guide.
 *
 * @package meeha-prep
 */

defined( 'ABSPATH' ) || exit;

/**
 * CV admin.
 */
class MHP_CV_Admin {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_post_mhp_cv_import', array( __CLASS__, 'do_import' ) );
		add_action( 'admin_post_mhp_cv_export', array( __CLASS__, 'do_export' ) );
		add_action( 'admin_post_mhp_cv_preview', array( __CLASS__, 'do_preview' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
	}

	/**
	 * Menu.
	 */
	public static function menu() {
		add_submenu_page( 'mhp', 'সিভি টেমপ্লেট ইমপোর্ট', 'সিভি টেমপ্লেট ইমপোর্ট', 'mhp_manage', 'mhp-cv-import', array( __CLASS__, 'page' ) );
	}

	/**
	 * Export / preview links in the template list.
	 *
	 * @param array   $a    Actions.
	 * @param WP_Post $post Post.
	 * @return array
	 */
	public static function row_actions( $a, $post ) {
		if ( 'mh_cv_tpl' === $post->post_type && mhp_can_manage() ) {
			$a['mhp_preview'] = '<a target="_blank" rel="noopener" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mhp_cv_preview&id=' . (int) $post->ID ), 'mhp_cv_preview_' . (int) $post->ID ) ) . '">প্রিভিউ</a>';
			$a['mhp_export']  = '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mhp_cv_export&id=' . (int) $post->ID ), 'mhp_cv_export' ) ) . '">JSON এক্সপোর্ট</a>';
		}
		return $a;
	}

	/**
	 * Notices (import result, refused CSS on the edit screen).
	 */
	public static function notices() {
		$err = get_transient( 'mhp_cv_css_err_' . get_current_user_id() );
		if ( $err ) {
			delete_transient( 'mhp_cv_css_err_' . get_current_user_id() );
			echo '<div class="notice notice-error"><p>CSS সংরক্ষণ হয়নি (আগেরটি রাখা হয়েছে): ' . esc_html( $err ) . '</p></div>';
		}
	}

	/**
	 * Import page with the format guide.
	 */
	public static function page() {
		if ( ! mhp_can_manage() ) {
			wp_die( esc_html( 'অনুমতি নেই।' ) );
		}
		$res = get_transient( 'mhp_cv_import_' . get_current_user_id() );
		if ( $res ) {
			delete_transient( 'mhp_cv_import_' . get_current_user_id() );
		}
		$count = wp_count_posts( 'mh_cv_tpl' );
		?>
		<div class="wrap mhp-wrap">
			<h1>সিভি টেমপ্লেট ইমপোর্ট</h1>
			<?php if ( is_array( $res ) ) : ?>
				<div class="notice notice-<?php echo $res['errors'] ? 'warning' : 'success'; ?>"><p>
					<?php echo esc_html( sprintf( 'নতুন: %d, হালনাগাদ: %d, বাদ (একই key আগে থেকে আছে): %d', $res['created'], $res['updated'], $res['skipped'] ) ); ?>
				</p>
				<?php foreach ( $res['errors'] as $e ) : ?>
					<p><?php echo esc_html( $e ); ?></p>
				<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<p>প্রকাশিত টেমপ্লেট: <?php echo (int) ( isset( $count->publish ) ? $count->publish : 0 ); ?>টি · খসড়া: <?php echo (int) ( isset( $count->draft ) ? $count->draft : 0 ); ?>টি। <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mh_cv_tpl' ) ); ?>">সব টেমপ্লেট</a> · <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mhp_cv_export&id=all' ), 'mhp_cv_export' ) ); ?>">সব এক্সপোর্ট (JSON)</a></p>

			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="mhp_cv_import">
				<?php wp_nonce_field( 'mhp_cv_import' ); ?>
				<table class="form-table"><tbody>
					<tr><th><label for="mhp_cv_file">JSON ফাইল</label></th><td><input type="file" id="mhp_cv_file" name="file" accept=".json,application/json"></td></tr>
					<tr><th><label for="mhp_cv_json">অথবা JSON পেস্ট করুন</label></th><td><textarea class="large-text code" rows="10" id="mhp_cv_json" name="json" spellcheck="false"></textarea></td></tr>
					<tr><th>বিকল্প</th><td>
						<label><input type="checkbox" name="overwrite" value="1"> একই key থাকলে হালনাগাদ করুন</label><br>
						<label><input type="checkbox" name="publish" value="1"> নতুনগুলো সরাসরি প্রকাশ করুন (না দিলে খসড়া থাকবে; প্রিভিউ দেখে প্রকাশ করুন)</label>
						<?php if ( ! mhp_can_shop() ) : ?>
							<p class="description">আপনার অ্যাকাউন্টে দাম বদলানোর অনুমতি নেই, তাই ফাইলের price উপেক্ষা হবে (নতুন টেমপ্লেট ফ্রি হিসেবে আসবে)।</p>
						<?php endif; ?>
					</td></tr>
				</tbody></table>
				<?php submit_button( 'ইমপোর্ট করুন' ); ?>
			</form>

			<h2>টেমপ্লেট প্যাকেজের গঠন</h2>
			<p>একটি টেমপ্লেট, অথবা <code>{"templates":[ … ]}</code> আকারে একাধিক:</p>
			<pre class="mhp-pre">{
  "format": "meeha-cv-template",
  "version": 1,
  "key": "nil-sada",            // ইংরেজি ছোট হাতের অক্ষর, সংখ্যা, -  (একই key = একই টেমপ্লেট)
  "name": "নীল-সাদা",
  "desc": "কার্ডে দেখানোর এক লাইনের বিবরণ",
  "price": 99,                  // ০ = ফ্রি (ফ্রি সব সময় এক পাতা)
  "pages": 2,                   // ১–৩, পেইড টেমপ্লেটে
  "order": 20,                  // গ্যালারিতে ক্রম
  "html": "&lt;div class=\"cv\"&gt; … &lt;/div&gt;",
  "css": ".cv{ … }"
}</pre>
			<h3>HTML-এ ব্যবহারযোগ্য ট্যাগ</h3>
			<ul class="ul-disc">
				<li><code>{{name}}</code> ইত্যাদি: মান বসে (সব সময় এসকেপ করা)। একক ঘর: name, headline, phone, email, address, website, summary, declaration, photo; <code>{{personal.father}}</code>, mother, dob, nationality, religion, marital, permanent।</li>
				<li><code>{{#experience}}…{{/experience}}</code>: প্রতিটি সারির জন্য একবার। তালিকা ও ঘর: experience (role, org, place, from, to, details), education (degree, inst, year, result), skills (name), languages (name, level), training (name, org, year), achievements (text), references (name, role, contact)।</li>
				<li><code>{{#has_experience}}…{{/has_experience}}</code>: তালিকায় কিছু থাকলে অংশটি দেখায় (has_education, has_skills, has_languages, has_training, has_achievements, has_personal, has_references)। <code>{{#photo}}…{{/photo}}</code>: ছবি থাকলে।</li>
				<li><code>{{^name}}…{{/name}}</code>: মান ফাঁকা থাকলে দেখায়।</li>
				<li><code>{{L.education}}</code> ইত্যাদি: শিরোনাম (বাংলা/ইংরেজি পাঠকের বাছাই অনুযায়ী)। L-এর ঘর: phone, email, address, website, your_name, summary, experience, result, education, degree, institution, year, skills, languages, training, achievements, personal, father, mother, dob, nationality, religion, marital, permanent, references, declaration, signature, cv_title।</li>
			</ul>
			<h3>নিয়ম</h3>
			<ul class="ul-disc">
				<li>মূল উপাদান <code>&lt;div class="cv"&gt;…&lt;/div&gt;</code>; পাতা A4 (২১০ মিমি চওড়া, প্রতি পাতা ২৯৭ মিমি)। CSS-এ মাপ মিমি/পয়েন্টে দিলে প্রিন্ট ঠিক থাকে।</li>
				<li>চলে: div, section, header, main, aside, footer, h1–h6, p, span, b, i, ul, ol, li, table, img, a ইত্যাদি; class ও style অ্যাট্রিবিউট। <code>&lt;img src="{{photo}}"&gt;</code>, <code>href="mailto:{{email}}"</code>, <code>href="tel:{{phone}}"</code>, <code>href="{{website}}"</code>।</li>
				<li>মুছে যায়: script, iframe, form, style ট্যাগ, on…= অ্যাট্রিবিউট, javascript: লিংক। অন্য অ্যাট্রিবিউটে <code>{{…}}</code> দিলে অ্যাট্রিবিউটটি বাদ যায়।</li>
				<li>CSS: <code>@import</code> ও বাইরের <code>url(…)</code> চলে না; ছবি/ফন্ট দিতে হলে <code>data:</code> ব্যবহার করুন। থিমের ফন্ট "Meeha Sans" (৪০০, ৬০০) ও "Meeha Serif" (৭০০) এমনিতেই পাওয়া যায়।</li>
				<li>প্রিন্ট পাতায় কঠোর নিরাপত্তা নীতি (CSP) থাকে: বাইরের কোনো কিছু লোড হয় না।</li>
			</ul>
			<p>শুরু করার সহজ উপায়: একটি বিদ্যমান টেমপ্লেট "JSON এক্সপোর্ট" করে key ও name বদলান, তারপর html/css সম্পাদনা করে এখানে ইমপোর্ট করুন।</p>
		</div>
		<?php
	}

	/**
	 * Import handler.
	 */
	public static function do_import() {
		if ( ! mhp_can_manage() ) {
			wp_die( esc_html( 'অনুমতি নেই।' ), 403 );
		}
		check_admin_referer( 'mhp_cv_import' );
		$raw = '';
		if ( ! empty( $_FILES['file']['tmp_name'] ) && is_uploaded_file( $_FILES['file']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( (int) $_FILES['file']['size'] <= 2 * 1024 * 1024 ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				$raw = (string) file_get_contents( $_FILES['file']['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.Security.ValidatedSanitizedInput
			}
		} elseif ( isset( $_POST['json'] ) ) {
			$raw = (string) wp_unslash( $_POST['json'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- parsed as JSON, every field sanitized by MHP_CV::package().
		}
		$raw  = preg_replace( '/^\xEF\xBB\xBF/', '', $raw );
		$data = json_decode( $raw, true );
		$res  = array(
			'created' => 0,
			'updated' => 0,
			'skipped' => 0,
			'errors'  => array(),
		);
		if ( ! is_array( $data ) ) {
			$res['errors'][] = 'JSON পড়া যায়নি: ' . json_last_error_msg();
		} else {
			$list = isset( $data['templates'] ) && is_array( $data['templates'] ) ? $data['templates'] : ( isset( $data['html'] ) ? array( $data ) : ( array_keys( $data ) === range( 0, count( $data ) - 1 ) ? $data : array() ) );
			if ( ! $list ) {
				$res['errors'][] = 'কোনো টেমপ্লেট পাওয়া যায়নি।';
			}
			foreach ( array_slice( $list, 0, 50 ) as $i => $p ) {
				$pkg = MHP_CV::package( $p, mhp_can_shop() );
				if ( is_wp_error( $pkg ) ) {
					$res['errors'][] = '#' . ( $i + 1 ) . ': ' . $pkg->get_error_message();
					continue;
				}
				$r = MHP_CV::upsert( $pkg, ! empty( $_POST['publish'] ) ? 'publish' : 'draft', ! empty( $_POST['overwrite'] ) );
				if ( isset( $res[ $r['action'] ] ) ) {
					++$res[ $r['action'] ];
				} else {
					$res['errors'][] = '#' . ( $i + 1 ) . ': সংরক্ষণ করা যায়নি।';
				}
			}
		}
		set_transient( 'mhp_cv_import_' . get_current_user_id(), $res, 300 );
		wp_safe_redirect( admin_url( 'admin.php?page=mhp-cv-import' ) );
		exit;
	}

	/**
	 * Export one design or all as JSON.
	 */
	public static function do_export() {
		if ( ! mhp_can_manage() ) {
			wp_die( esc_html( 'অনুমতি নেই।' ), 403 );
		}
		check_admin_referer( 'mhp_cv_export' );
		$id  = isset( $_GET['id'] ) ? sanitize_key( wp_unslash( $_GET['id'] ) ) : '';
		$out = null;
		$fn  = 'meeha-cv-templates.json';
		if ( 'all' === $id ) {
			$out = array(
				'format'    => 'meeha-cv-template',
				'version'   => 1,
				'templates' => array(),
			);
			foreach ( get_posts(
				array(
					'post_type'      => 'mh_cv_tpl',
					'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
					'posts_per_page' => -1,
					'fields'         => 'ids',
				)
			) as $pid ) {
				$p = MHP_CV::export_package( $pid );
				if ( $p ) {
					unset( $p['format'], $p['version'] );
					$out['templates'][] = $p;
				}
			}
		} else {
			$out = MHP_CV::export_package( absint( $id ) );
			if ( $out ) {
				$fn = 'meeha-cv-' . $out['key'] . '.json';
			}
		}
		if ( ! $out ) {
			wp_die( esc_html( 'টেমপ্লেট পাওয়া যায়নি।' ), 404 );
		}
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $fn ) . '"' );
		echo wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Preview a stored design with placeholder content (any status), on its own page with the same CSP.
	 */
	public static function do_preview() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		if ( ! mhp_can_manage() || ! current_user_can( 'edit_post', $id ) ) {
			wp_die( esc_html( 'অনুমতি নেই।' ), 403 );
		}
		check_admin_referer( 'mhp_cv_preview_' . $id );
		$tpl = MHP_CV::tpl( $id, false );
		if ( ! $tpl ) {
			wp_die( esc_html( 'টেমপ্লেট পাওয়া যায়নি।' ), 404 );
		}
		$host = wp_parse_url( get_template_directory_uri() );
		$font = "'self'" . ( ! empty( $host['host'] ) ? ' ' . ( isset( $host['scheme'] ) ? $host['scheme'] : 'https' ) . '://' . $host['host'] : '' );
		header( 'Content-Type: text/html; charset=utf-8' );
		header( "Content-Security-Policy: default-src 'none'; img-src data:; font-src {$font} data:; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'" );
		echo MHP_CV::document( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sanitized design, escaped values.
			$tpl,
			MHP_CV::context( MHP_CV::sample(), MHP_CV::sample_photo() ),
			array(
				'head' => '<title>' . esc_html( $tpl['name'] ) . ' — প্রিভিউ</title><style>@media screen{body{background:#e6eaef;padding:16px 0}.cv-clip{box-shadow:0 2px 14px rgba(0,0,0,.18)}}</style>',
			)
		);
		exit;
	}
}
