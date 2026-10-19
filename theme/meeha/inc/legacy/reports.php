<?php
/**
 * Reader reports / fact-check requests.
 *
 *  - Table {prefix}meeha_reports (schema unchanged; dbDelta guarded by option meeha_reports_table_v1).
 *  - Public REST POST meeha/v1/report (multipart, NO nonce): per-IP rate limit (5/h, 20/day),
 *    honeypot, same-origin check, published-post validation, evidence = real JPG/PNG/WebP ≤ 3 MB,
 *    re-encoded into uploads/meeha-reports/ with a random name (folder denies script execution).
 *  - Legacy AJAX action meeha_report_submit kept as a thin wrapper (old cached single pages).
 *  - meeha_render_report_button( $post_id ): button + one <template>; the native <dialog> is
 *    created lazily by assets/js/report.js, which also lazy-loads the report CSS.
 *  - Admin page মিহা থিম › রিপোর্ট (slug meeha-reports).
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

function meeha_reports_table() {
	global $wpdb;
	return $wpdb->prefix . 'meeha_reports';
}

function meeha_maybe_create_reports_table() {
	if ( '1' === get_option( 'meeha_reports_table_v1' ) ) {
		return;
	}
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$table_name      = meeha_reports_table();
	$charset_collate = $wpdb->get_charset_collate();
	$sql             = "CREATE TABLE $table_name (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		post_id BIGINT UNSIGNED NOT NULL,
		report_type VARCHAR(40) NOT NULL,
		reporter_name VARCHAR(191) NOT NULL,
		reporter_phone VARCHAR(32) NULL,
		reporter_email VARCHAR(191) NULL,
		message TEXT NULL,
		evidence_url VARCHAR(500) NULL,
		created_at DATETIME NOT NULL,
		PRIMARY KEY (id),
		KEY post_id (post_id)
	) $charset_collate;";
	dbDelta( $sql );
	update_option( 'meeha_reports_table_v1', '1' );
}
add_action( 'init', 'meeha_maybe_create_reports_table' );

function meeha_get_report_types() {
	return array(
		'verify'    => 'সত্যতা যাচাই অনুরোধ',
		'false'     => 'অসত্য নিউজ',
		'partial'   => 'আংশিক সত্য',
		'sensitive' => 'সত্য কিন্তু সামাজিক সেন্সিটিভ',
		'opinion'   => 'মতামত আছে',
	);
}

/** Post types that can be reported. */
function meeha_report_post_types() {
	return (array) apply_filters( 'meeha_report_post_types', array( 'post', 'meeha_photocard', 'meeha_video' ) );
}

/** Normalise a phone number (Bengali digits → ASCII, keep + and digits). */
function meeha_normalise_phone( $phone ) {
	$phone = strtr( (string) $phone, array( '০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9' ) );
	return preg_replace( '/[^0-9+]/', '', $phone );
}

/**
 * Validate + store a report.
 *
 * @param array $p              Fields: post_id, report_type, name, phone, email, message, website,
 *                              meeha_newsletter_consent, meeha_register.
 * @param array $file           Evidence $_FILES entry or empty.
 * @param bool  $check_uploaded Require is_uploaded_file() (false only in CLI tests).
 * @return array [ bool ok, array data ]
 */
function meeha_report_submit( $p, $file = array(), $check_uploaded = true ) {
	$ok_msg = 'আপনার রিপোর্ট জমা হয়েছে, ধন্যবাদ। আমাদের সম্পাদকীয় দল এটি যাচাই করবে।';
	if ( meeha_legacy_honeypot_tripped( isset( $p['website'] ) ? $p['website'] : '' ) ) {
		return array( true, array( 'message' => $ok_msg ) );
	}
	if ( ! meeha_legacy_rate_ok( 'report', 5, HOUR_IN_SECONDS ) || ! meeha_legacy_rate_ok( 'reportd', 20, DAY_IN_SECONDS ) ) {
		return array( false, array( 'message' => 'অনেকগুলো রিপোর্ট পাঠানো হয়েছে। কিছুক্ষণ পর আবার চেষ্টা করুন।', 'code' => 'rate' ) );
	}
	$post_id     = isset( $p['post_id'] ) ? absint( $p['post_id'] ) : 0;
	$report_type = isset( $p['report_type'] ) ? sanitize_key( $p['report_type'] ) : '';
	$name        = isset( $p['name'] ) ? sanitize_text_field( $p['name'] ) : '';
	$phone       = isset( $p['phone'] ) ? meeha_normalise_phone( sanitize_text_field( $p['phone'] ) ) : '';
	$email       = isset( $p['email'] ) ? sanitize_email( $p['email'] ) : '';
	$message     = isset( $p['message'] ) ? sanitize_textarea_field( $p['message'] ) : '';

	$post = $post_id ? get_post( $post_id ) : null;
	if ( ! $post || 'publish' !== $post->post_status || ! in_array( $post->post_type, meeha_report_post_types(), true ) || post_password_required( $post ) ) {
		return array( false, array( 'message' => 'খবরটি পাওয়া যায়নি।' ) );
	}
	if ( ! array_key_exists( $report_type, meeha_get_report_types() ) ) {
		return array( false, array( 'message' => 'রিপোর্টের ধরন বাছাই করুন।' ) );
	}
	if ( mb_strlen( $name ) < 2 || mb_strlen( $name ) > 100 ) {
		return array( false, array( 'message' => 'আপনার নাম দিন।' ) );
	}
	if ( strlen( ltrim( $phone, '+' ) ) < 6 || strlen( $phone ) > 20 ) {
		return array( false, array( 'message' => 'সঠিক ফোন নাম্বার দিন।' ) );
	}
	if ( isset( $p['email'] ) && '' !== trim( (string) $p['email'] ) && ! is_email( $email ) ) {
		return array( false, array( 'message' => 'ইমেইল ঠিকানা সঠিক নয়।' ) );
	}
	if ( mb_strlen( $message ) < 5 || mb_strlen( $message ) > 3000 ) {
		return array( false, array( 'message' => 'আপনার বক্তব্য লিখুন (৫–৩০০০ অক্ষর)।' ) );
	}

	$evidence_url = '';
	if ( ! empty( $file ) && ! empty( $file['name'] ) && ( ! isset( $file['error'] ) || UPLOAD_ERR_NO_FILE !== (int) $file['error'] ) ) {
		$valid = meeha_legacy_validate_image( $file, (int) apply_filters( 'meeha_report_max_bytes', 3 * MB_IN_BYTES ), $check_uploaded );
		if ( is_wp_error( $valid ) ) {
			return array( false, array( 'message' => 'প্রমাণ: ' . $valid->get_error_message() ) );
		}
		$resize = ( $valid['w'] > 2000 || $valid['h'] > 2000 ) ? array( 2000, 2000, false ) : null;
		$stored = meeha_legacy_store_image( $valid, 'meeha-reports', $resize );
		if ( is_wp_error( $stored ) ) {
			return array( false, array( 'message' => $stored->get_error_message() ) );
		}
		$evidence_url = $stored['url'];
	}

	global $wpdb;
	$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		meeha_reports_table(),
		array(
			'post_id'        => $post_id,
			'report_type'    => $report_type,
			'reporter_name'  => $name,
			'reporter_phone' => $phone,
			'reporter_email' => $email ? $email : null,
			'message'        => $message,
			'evidence_url'   => $evidence_url,
			'created_at'     => current_time( 'mysql' ),
		),
		array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
	);
	if ( ! $inserted ) {
		return array( false, array( 'message' => 'রিপোর্ট জমা করা যায়নি, আবার চেষ্টা করুন।' ) );
	}

	$types = meeha_get_report_types();
	wp_mail(
		get_option( 'admin_email' ),
		'নতুন রিপোর্ট: ' . $types[ $report_type ],
		'পোস্ট: ' . get_permalink( $post_id ) . "\nধরন: " . $types[ $report_type ] . "\nনাম: $name\nফোন: $phone\nইমেইল: $email\nবক্তব্য: $message"
			. ( $evidence_url ? "\nপ্রমাণ: $evidence_url" : '' )
			. "\n\nসব রিপোর্ট: " . admin_url( 'admin.php?page=meeha-reports' )
	);

	if ( $email ) {
		meeha_maybe_subscribe_mailpoet( $email, $name, ! empty( $p['meeha_newsletter_consent'] ) );
		if ( ! empty( $p['meeha_register'] ) && ! is_user_logged_in() ) {
			meeha_maybe_create_account( $name, $email, $phone );
		}
	}
	do_action( 'meeha_report_submitted', (int) $wpdb->insert_id, $post_id, $report_type );
	return array( true, array( 'message' => $ok_msg ) );
}

/* ---------- REST ---------- */
function meeha_report_rest_routes() {
	register_rest_route(
		'meeha/v1',
		'/report',
		array(
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => function ( WP_REST_Request $r ) {
				if ( ! meeha_legacy_same_origin() ) {
					return new WP_REST_Response( array( 'success' => false, 'data' => array( 'message' => 'অনুরোধ গ্রহণযোগ্য নয়।' ) ), 403 );
				}
				$files = $r->get_file_params();
				$out   = meeha_report_submit( $r->get_body_params() ? $r->get_body_params() : $r->get_params(), isset( $files['evidence'] ) ? $files['evidence'] : array() );
				$res   = new WP_REST_Response( array( 'success' => $out[0], 'data' => $out[1] ), $out[0] ? 200 : ( isset( $out[1]['code'] ) && 'rate' === $out[1]['code'] ? 429 : 400 ) );
				$res->header( 'Cache-Control', 'no-store' );
				return $res;
			},
		)
	);
}
add_action( 'rest_api_init', 'meeha_report_rest_routes' );

/* ---------- legacy AJAX wrapper (nonce not enforced; stale cached pages) ---------- */
function meeha_handle_report_submit() {
	if ( ! meeha_legacy_same_origin() ) {
		wp_send_json_error( array( 'message' => 'অনুরোধ গ্রহণযোগ্য নয়।' ), 403 );
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput
	meeha_legacy_ajax_send( meeha_report_submit( wp_unslash( $_POST ), isset( $_FILES['evidence'] ) ? $_FILES['evidence'] : array() ) );
}
add_action( 'wp_ajax_meeha_report_submit', 'meeha_handle_report_submit' );
add_action( 'wp_ajax_nopriv_meeha_report_submit', 'meeha_handle_report_submit' );

/* ---------- button + lazy modal ---------- */

/**
 * Report button for a post (D places it on single templates). Prints the dialog <template>
 * once per page and enqueues report.js; the CSS is loaded by the script on first open.
 *
 * @param int        $post_id Post ID.
 * @param array|bool $args    variant: 'default' (ghost button) | 'compact' (small chip for the
 *                            correction-note box, CONTRACT §9.1); echo: true. A bool is accepted
 *                            as the legacy `$echo` flag.
 * @return string
 */
function meeha_render_report_button( $post_id, $args = array() ) {
	static $tpl_done = false;
	if ( is_bool( $args ) ) {
		$args = array( 'echo' => $args );
	}
	$args    = wp_parse_args( (array) $args, array( 'variant' => 'default', 'echo' => true, 'label' => 'রিপোর্ট করুন' ) );
	$post_id = absint( $post_id );
	if ( ! $post_id ) {
		return '';
	}
	wp_enqueue_script( 'meeha-report', meeha_legacy_asset_url( 'report', 'js', 'report.js' ), array(), null, array( 'in_footer' => true, 'strategy' => 'defer' ) ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion

	$compact = 'compact' === $args['variant'];
	$class   = $compact ? 'm-chip m-reportbtn m-reportbtn--compact' : 'm-btn m-btn--ghost m-reportbtn';
	$html    = '<button type="button" class="' . esc_attr( $class ) . '" data-meeha-report="' . esc_attr( $post_id ) . '" data-post-id="' . esc_attr( $post_id ) . '" aria-haspopup="dialog" data-css="' . esc_url( meeha_legacy_asset_url( 'report', 'css', 'report.css' ) ) . '" data-endpoint="' . esc_url( rest_url( 'meeha/v1/report' ) ) . '">'
		. meeha_legacy_icon( 'flag', 'm-icon--sm' ) . '<span>' . esc_html( $args['label'] ) . '</span></button>';

	if ( ! $tpl_done ) {
		$tpl_done = true;
		$html    .= meeha_report_template();
	}
	if ( $args['echo'] ) {
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above
	}
	return $html;
}

/** The dialog markup inside an inert <template>. */
function meeha_report_template() {
	$types = meeha_get_report_types();
	ob_start();
	?>
	<template id="meeha-report-tpl">
		<dialog class="m-report" aria-labelledby="m-report-h">
			<form class="m-report__form" method="post" enctype="multipart/form-data" novalidate>
				<header class="m-report__head">
					<h2 class="m-report__title" id="m-report-h"><?php echo meeha_legacy_icon( 'flag' ); // phpcs:ignore ?>রিপোর্ট করুন</h2>
					<button type="button" class="m-iconbtn m-report__close" data-close aria-label="বন্ধ করুন"><?php echo meeha_legacy_icon( 'close' ); // phpcs:ignore ?><span aria-hidden="true" class="m-report__x">×</span></button>
				</header>
				<div class="m-report__body">
					<p class="m-report__lede">খবরে ভুল, বিভ্রান্তিকর বা সংবেদনশীল কিছু চোখে পড়লে জানান। প্রতিটি রিপোর্ট আমাদের সম্পাদকীয় দল যাচাই করে; আপনার তথ্য প্রকাশ করা হয় না।</p>
					<fieldset class="m-report__types">
						<legend class="m-report__label">রিপোর্টের ধরন</legend>
						<?php foreach ( $types as $key => $label ) : ?>
							<label class="m-report__type"><input type="radio" name="report_type" value="<?php echo esc_attr( $key ); ?>" required><span><?php echo esc_html( $label ); ?></span></label>
						<?php endforeach; ?>
					</fieldset>
					<div class="m-report__grid">
						<div class="m-field"><label class="m-field__label" for="m-rp-name">আপনার নাম</label><input class="m-input" id="m-rp-name" type="text" name="name" autocomplete="name" maxlength="100" required></div>
						<div class="m-field"><label class="m-field__label" for="m-rp-phone">ফোন নাম্বার</label><input class="m-input" id="m-rp-phone" type="tel" name="phone" autocomplete="tel" inputmode="tel" maxlength="20" required></div>
					</div>
					<div class="m-field"><label class="m-field__label" for="m-rp-email">ইমেইল <span class="m-field__opt">(ঐচ্ছিক)</span></label><input class="m-input" id="m-rp-email" type="email" name="email" autocomplete="email" inputmode="email"></div>
					<div class="m-field"><label class="m-field__label" for="m-rp-msg">আপনার বক্তব্য</label><textarea class="m-input" id="m-rp-msg" name="message" rows="4" maxlength="3000" required placeholder="কোন অংশটি ভুল বা বিভ্রান্তিকর, সঠিক তথ্য কী — সূত্রসহ লিখুন।"></textarea></div>
					<div class="m-field m-report__file">
						<span class="m-field__label">প্রমাণ <span class="m-field__opt">(ছবি, ঐচ্ছিক)</span></span>
						<label class="m-report__drop" for="m-rp-file"><?php echo meeha_legacy_icon( 'upload' ); // phpcs:ignore ?><span data-file-label>ছবি বাছাই করুন — JPG, PNG বা WebP, সর্বোচ্চ ৩ MB</span></label>
						<input class="m-vh" id="m-rp-file" type="file" name="evidence" accept="image/jpeg,image/png,image/webp">
					</div>
					<?php echo meeha_mailpoet_consent_field( 'm-rp' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<?php if ( ! is_user_logged_in() && meeha_registration_open() ) : ?>
						<label class="m-check" for="m-rp-reg"><input type="checkbox" id="m-rp-reg" name="meeha_register" value="1"> <span>এই ইমেইলে মিহা নিউজের একাউন্ট খুলতে চাই (পাসওয়ার্ড সেট করার লিংক ইমেইলে যাবে)</span></label>
					<?php endif; ?>
					<?php echo meeha_legacy_honeypot(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<input type="hidden" name="post_id" value="">
				</div>
				<footer class="m-report__foot">
					<p class="m-report__msg" role="status" aria-live="polite"></p>
					<button type="submit" class="m-btn">রিপোর্ট জমা দিন</button>
				</footer>
			</form>
		</dialog>
	</template>
	<?php
	return ob_get_clean();
}

/* ---------- admin page ---------- */
function meeha_add_reports_admin_page() {
	add_submenu_page( meeha_legacy_menu_parent(), 'Meeha রিপোর্ট', 'রিপোর্ট', 'manage_options', 'meeha-reports', 'meeha_render_reports_admin_page' );
}
add_action( 'admin_menu', 'meeha_add_reports_admin_page', 20 );

/** Delete one report (+ its evidence file). */
function meeha_reports_handle_delete() {
	if ( ! isset( $_GET['page'], $_GET['meeha_delete_report'] ) || 'meeha-reports' !== $_GET['page'] || ! current_user_can( 'manage_options' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}
	$id = absint( $_GET['meeha_delete_report'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	check_admin_referer( 'meeha_delete_report_' . $id );
	global $wpdb;
	$table = meeha_reports_table();
	$url   = $wpdb->get_var( $wpdb->prepare( "SELECT evidence_url FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore
	if ( $url ) {
		$path = meeha_legacy_upload_url_to_path( $url );
		if ( $path && false !== strpos( wp_normalize_path( $path ), '/meeha-reports/' ) && is_file( $path ) ) {
			wp_delete_file( $path );
		}
	}
	$wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	wp_safe_redirect( remove_query_arg( array( 'meeha_delete_report', '_wpnonce' ), wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=meeha-reports' ) ) );
	exit;
}
add_action( 'admin_init', 'meeha_reports_handle_delete' );

function meeha_render_reports_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	global $wpdb;
	$table    = meeha_reports_table();
	$types    = meeha_get_report_types();
	$per_page = 50;
	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
	$filter = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';
	// phpcs:enable
	$filter = isset( $types[ $filter ] ) ? $filter : '';
	$where  = $filter ? $wpdb->prepare( 'WHERE report_type = %s', $filter ) : '';
	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where}" );
	$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} {$where} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", $per_page, ( $paged - 1 ) * $per_page ) );
	// phpcs:enable
	$pages = max( 1, (int) ceil( $total / $per_page ) );
	?>
	<div class="wrap">
		<h1>পাঠকদের রিপোর্ট <span class="title-count"><?php echo esc_html( meeha_legacy_bn( $total ) ); ?></span></h1>
		<ul class="subsubsub">
			<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=meeha-reports' ) ); ?>" <?php echo $filter ? '' : 'class="current"'; ?>>সব</a> |</li>
			<?php
			$i = 0;
			foreach ( $types as $k => $label ) :
				$i++;
				?>
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=meeha-reports&type=' . $k ) ); ?>" <?php echo $filter === $k ? 'class="current"' : ''; ?>><?php echo esc_html( $label ); ?></a><?php echo $i < count( $types ) ? ' |' : ''; ?></li>
			<?php endforeach; ?>
		</ul>
		<table class="widefat striped" style="clear:both">
			<thead><tr><th>তারিখ</th><th>পোস্ট</th><th>ধরন</th><th>নাম</th><th>ফোন</th><th>ইমেইল</th><th>বক্তব্য</th><th>প্রমাণ</th><th></th></tr></thead>
			<tbody>
				<?php if ( $rows ) : ?>
					<?php foreach ( $rows as $r ) : ?>
						<tr>
							<td><?php echo esc_html( $r->created_at ); ?></td>
							<td><a href="<?php echo esc_url( get_permalink( $r->post_id ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( get_the_title( $r->post_id ) ); ?></a></td>
							<td><?php echo esc_html( isset( $types[ $r->report_type ] ) ? $types[ $r->report_type ] : $r->report_type ); ?></td>
							<td><?php echo esc_html( $r->reporter_name ); ?></td>
							<td><?php echo esc_html( $r->reporter_phone ); ?></td>
							<td><?php echo esc_html( $r->reporter_email ); ?></td>
							<td><details><summary><?php echo esc_html( wp_trim_words( $r->message, 12 ) ); ?></summary><div style="white-space:pre-wrap;max-width:40em"><?php echo esc_html( $r->message ); ?></div></details></td>
							<td><?php if ( $r->evidence_url ) : ?><a href="<?php echo esc_url( $r->evidence_url ); ?>" target="_blank" rel="noopener noreferrer">দেখুন</a><?php endif; ?></td>
							<td><a class="submitdelete" href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'meeha_delete_report', (int) $r->id ), 'meeha_delete_report_' . (int) $r->id ) ); ?>" onclick="return confirm('রিপোর্টটি মুছে ফেলবেন?');">মুছুন</a></td>
						</tr>
					<?php endforeach; ?>
				<?php else : ?>
					<tr><td colspan="9">এখনো কোনো রিপোর্ট জমা পড়েনি।</td></tr>
				<?php endif; ?>
			</tbody>
		</table>
		<?php if ( $pages > 1 ) : ?>
			<div class="tablenav"><div class="tablenav-pages">
				<?php
				echo wp_kses_post(
					paginate_links(
						array(
							'base'    => add_query_arg( 'paged', '%#%' ),
							'format'  => '',
							'current' => $paged,
							'total'   => $pages,
						)
					)
				);
				?>
			</div></div>
		<?php endif; ?>
	</div>
	<?php
}
