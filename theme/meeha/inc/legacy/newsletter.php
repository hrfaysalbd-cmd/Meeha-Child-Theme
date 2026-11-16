<?php
/**
 * Newsletter fallback (used only when no MailPoet form id is configured).
 *
 *  - CPT meeha_subscriber (title = e-mail), unchanged key; menu under মিহা থিম.
 *  - Public REST POST meeha/v1/newsletter {email, website(honeypot)} — rate-limited per IP,
 *    no nonce; legacy AJAX meeha_newsletter_signup kept as a wrapper; no-JS POST via
 *    admin-post.php?action=meeha_newsletter (redirects back with #m-news).
 *  - No admin e-mail per signup any more: new addresses are queued and at most ONE digest
 *    e-mail per hour is sent by a single scheduled event.
 *  - The form renders on do_action( 'meeha_newsletter_fallback_form' ) (A's footer calls it).
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

function meeha_register_subscriber_post_type() {
	register_post_type(
		'meeha_subscriber',
		array(
			'label'        => 'সাবস্ক্রাইবার',
			'public'       => false,
			'show_ui'      => true,
			'show_in_menu' => meeha_legacy_menu_parent(),
			'supports'     => array( 'title' ),
			'labels'       => array(
				'name'          => 'সাবস্ক্রাইবাররা',
				'singular_name' => 'সাবস্ক্রাইবার',
				'all_items'     => 'নিউজলেটার (বিকল্প)',
			),
		)
	);
}
add_action( 'init', 'meeha_register_subscriber_post_type' );

/**
 * Subscribe an e-mail to the fallback list.
 *
 * @return array [ bool ok, array data ]
 */
function meeha_newsletter_subscribe( $p ) {
	if ( meeha_legacy_honeypot_tripped( isset( $p['website'] ) ? $p['website'] : '' ) ) {
		return array( true, array( 'message' => 'ধন্যবাদ! আপনি সফলভাবে সাবস্ক্রাইব করেছেন।' ) );
	}
	if ( ! meeha_legacy_rate_ok( 'newsletter', 5, HOUR_IN_SECONDS ) ) {
		return array( false, array( 'message' => 'অনেকবার চেষ্টা হয়েছে। কিছুক্ষণ পর আবার চেষ্টা করুন।', 'code' => 'rate' ) );
	}
	$email = isset( $p['email'] ) ? sanitize_email( (string) $p['email'] ) : '';
	if ( ! is_email( $email ) ) {
		return array( false, array( 'message' => 'সঠিক ইমেইল ঠিকানা দিন।' ) );
	}
	$email    = strtolower( $email );
	$existing = new WP_Query(
		array(
			'post_type'      => 'meeha_subscriber',
			'title'          => $email,
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		)
	);
	if ( $existing->have_posts() ) {
		return array( true, array( 'message' => 'ধন্যবাদ! আপনি সফলভাবে সাবস্ক্রাইব করেছেন।' ) ); // same as new: no enumeration
	}
	$id = wp_insert_post(
		array(
			'post_type'   => 'meeha_subscriber',
			'post_title'  => $email,
			'post_status' => 'publish',
		),
		true
	);
	if ( is_wp_error( $id ) ) {
		return array( false, array( 'message' => 'সাবস্ক্রাইব করা যায়নি, আবার চেষ্টা করুন।' ) );
	}
	meeha_newsletter_queue_digest( $email );
	return array( true, array( 'message' => 'ধন্যবাদ! আপনি সফলভাবে সাবস্ক্রাইব করেছেন।' ) );
}

/* ---------- admin digest: at most one e-mail per hour ---------- */
function meeha_newsletter_queue_digest( $email ) {
	$queue   = get_option( 'meeha_subscriber_digest_queue', array() );
	$queue   = is_array( $queue ) ? $queue : array();
	$queue[] = $email;
	update_option( 'meeha_subscriber_digest_queue', array_slice( array_unique( $queue ), -500 ), false );
	if ( ! wp_next_scheduled( 'meeha_subscriber_digest' ) ) {
		$last = (int) get_option( 'meeha_subscriber_digest_last', 0 );
		wp_schedule_single_event( max( time() + 60, $last + HOUR_IN_SECONDS ), 'meeha_subscriber_digest' );
	}
}

function meeha_newsletter_send_digest() {
	$queue = get_option( 'meeha_subscriber_digest_queue', array() );
	if ( ! is_array( $queue ) || ! $queue ) {
		return;
	}
	update_option( 'meeha_subscriber_digest_queue', array(), false );
	update_option( 'meeha_subscriber_digest_last', time(), false );
	wp_mail(
		get_option( 'admin_email' ),
		'নতুন নিউজলেটার সাবস্ক্রাইবার (' . meeha_legacy_bn( count( $queue ) ) . ')',
		"গত এক ঘণ্টায় যাঁরা নিউজলেটারে সাবস্ক্রাইব করেছেন:\n\n" . implode( "\n", $queue ) . "\n\nসব সাবস্ক্রাইবার: " . admin_url( 'edit.php?post_type=meeha_subscriber' )
	);
}
add_action( 'meeha_subscriber_digest', 'meeha_newsletter_send_digest' );

/* ---------- transports ---------- */
function meeha_newsletter_rest_routes() {
	register_rest_route(
		'meeha/v1',
		'/newsletter',
		array(
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'args'                => array(
				'email'   => array( 'type' => 'string', 'required' => true ),
				'website' => array( 'type' => 'string', 'default' => '' ),
			),
			'callback'            => function ( WP_REST_Request $r ) {
				if ( ! meeha_legacy_same_origin() ) {
					return new WP_REST_Response( array( 'success' => false, 'data' => array( 'message' => 'অনুরোধ গ্রহণযোগ্য নয়।' ) ), 403 );
				}
				$out = meeha_newsletter_subscribe( $r->get_params() );
				$res = new WP_REST_Response( array( 'success' => $out[0], 'data' => $out[1] ), $out[0] ? 200 : ( isset( $out[1]['code'] ) ? 429 : 400 ) );
				$res->header( 'Cache-Control', 'no-store' );
				return $res;
			},
		)
	);
}
add_action( 'rest_api_init', 'meeha_newsletter_rest_routes' );

function meeha_handle_newsletter_signup() {
	if ( ! meeha_legacy_same_origin() ) {
		wp_send_json_error( array( 'message' => 'অনুরোধ গ্রহণযোগ্য নয়।' ), 403 );
	}
	meeha_legacy_ajax_send( meeha_newsletter_subscribe( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
}
add_action( 'wp_ajax_meeha_newsletter_signup', 'meeha_handle_newsletter_signup' );
add_action( 'wp_ajax_nopriv_meeha_newsletter_signup', 'meeha_handle_newsletter_signup' );

function meeha_newsletter_post() {
	$ok  = meeha_legacy_same_origin();
	$out = $ok ? meeha_newsletter_subscribe( wp_unslash( $_POST ) ) : array( false, array() ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	meeha_legacy_redirect_back( array( 'meeha_nl' => $out[0] ? 'ok' : ( isset( $out[1]['code'] ) ? 'rate' : 'err' ) ), home_url( '/' ), 'm-news' );
}
add_action( 'admin_post_nopriv_meeha_newsletter', 'meeha_newsletter_post' );
add_action( 'admin_post_meeha_newsletter', 'meeha_newsletter_post' );

/* ---------- the fallback form (footer newsletter band, markup per footer.css) ---------- */
function meeha_newsletter_fallback_form() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$state = isset( $_GET['meeha_nl'] ) ? sanitize_key( wp_unslash( $_GET['meeha_nl'] ) ) : '';
	$msgs  = array(
		'ok'   => 'ধন্যবাদ! আপনি সফলভাবে সাবস্ক্রাইব করেছেন।',
		'rate' => 'অনেকবার চেষ্টা হয়েছে। কিছুক্ষণ পর আবার চেষ্টা করুন।',
		'err'  => 'সঠিক ইমেইল ঠিকানা দিন।',
	);
	?>
	<form class="m-news__form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" id="meeha-newsletter-form">
		<input type="hidden" name="action" value="meeha_newsletter">
		<label class="m-vh" for="m-news-email">ইমেইল ঠিকানা</label>
		<div class="m-news__field"><input class="m-input" id="m-news-email" type="email" name="email" inputmode="email" autocomplete="email" placeholder="আপনার ইমেইল ঠিকানা" required><button class="m-btn" type="submit">সাবস্ক্রাইব করুন</button></div>
		<?php echo meeha_legacy_honeypot(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		<p class="m-news__fine" role="status"><?php echo isset( $msgs[ $state ] ) ? esc_html( $msgs[ $state ] ) : 'সাবস্ক্রাইব করলে আপনি আমাদের গোপনীয়তা নীতি মেনে নিচ্ছেন। যেকোনো সময় বাতিল করা যায়।'; ?></p>
	</form>
	<?php
}
add_action( 'meeha_newsletter_fallback_form', 'meeha_newsletter_fallback_form' );
