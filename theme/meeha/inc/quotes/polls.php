<?php
/**
 * Quote polls (port of the old feature) + download counter.
 *
 * Rules (unchanged): one vote per phone number per quote (DB UNIQUE post_id+voter_phone); results stay hidden
 * until post date + duration; while open only the live TOTAL is shown.
 * Privacy fix: voters' names/phones are never published (the old marquee is gone; the legacy tally AJAX
 * returns an empty voter list). JS renders everything with textContent.
 *
 * REST (no nonce — public, cached pages): GET meeha/v1/poll/{id} (tally, cached 15 s), POST meeha/v1/poll/{id}
 * (vote: rate-limited per hashed IP, honeypot, same-origin check), POST meeha/v1/quote-download/{id}.
 * Legacy AJAX kept as thin wrappers: meeha_poll_vote, meeha_poll_live_tally, meeha_track_download.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

/**
 * Votes table name.
 *
 * @return string
 */
function meeha_poll_table() {
	global $wpdb;
	return $wpdb->prefix . 'meeha_poll_votes';
}
if ( ! function_exists( 'meeha_poll_votes_table' ) ) {
	/**
	 * Legacy name.
	 *
	 * @return string
	 */
	function meeha_poll_votes_table() {
		return meeha_poll_table();
	}
}

/**
 * Create the table (same schema as the old theme; guarded by the legacy flag `meeha_poll_table_v1`).
 *
 * @param bool $force Force dbDelta.
 */
function meeha_poll_maybe_create_table( $force = false ) {
	if ( ! $force && '1' === get_option( 'meeha_poll_table_v1' ) ) {
		return;
	}
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$t = meeha_poll_table();
	dbDelta(
		"CREATE TABLE $t (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		post_id BIGINT UNSIGNED NOT NULL,
		voter_name VARCHAR(191) NOT NULL,
		voter_phone VARCHAR(32) NOT NULL,
		voter_email VARCHAR(191) NULL,
		choice VARCHAR(1) NOT NULL,
		created_at DATETIME NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY post_phone (post_id, voter_phone),
		KEY post_id (post_id)
	) " . $wpdb->get_charset_collate() . ';'
	);
	update_option( 'meeha_poll_table_v1', '1' );
}
add_action( 'init', 'meeha_poll_maybe_create_table' );

/**
 * Poll state.
 *
 * @param int $post_id Quote id.
 * @return array enabled, option_a, option_b, end_time, is_closed, count_a, count_b, total (+ winner when closed).
 */
function meeha_poll_data( $post_id ) {
	$post_id = (int) $post_id;
	if ( ! get_post_meta( $post_id, '_meeha_poll_enabled', true ) ) {
		return array(
			'enabled' => false,
			'total'   => 0,
		);
	}
	$a    = trim( (string) get_post_meta( $post_id, '_meeha_poll_option_a', true ) );
	$b    = trim( (string) get_post_meta( $post_id, '_meeha_poll_option_b', true ) );
	$days = absint( get_post_meta( $post_id, '_meeha_poll_duration_days', true ) );
	if ( ! $days ) {
		$days = max( 1, (int) meeha_opt( 'quotes', 'poll_days', 7 ) );
	}
	$end = (int) get_post_time( 'U', true, $post_id ) + $days * DAY_IN_SECONDS;
	$out = array(
		'enabled'   => true,
		'option_a'  => '' !== $a ? $a : 'একমত',
		'option_b'  => '' !== $b ? $b : 'দ্বিমত',
		'end_time'  => $end,
		'is_closed' => time() >= $end,
		'count_a'   => 0,
		'count_b'   => 0,
		'total'     => 0,
	);
	global $wpdb;
	$t    = meeha_poll_table();
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT choice, COUNT(*) AS cnt FROM $t WHERE post_id = %d GROUP BY choice", $post_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
	foreach ( (array) $rows as $r ) {
		if ( 'a' === $r->choice ) {
			$out['count_a'] = (int) $r->cnt;
		} elseif ( 'b' === $r->choice ) {
			$out['count_b'] = (int) $r->cnt;
		}
	}
	$out['total'] = $out['count_a'] + $out['count_b'];
	if ( $out['is_closed'] ) {
		$out['winner'] = $out['count_a'] === $out['count_b'] ? 'tie' : ( $out['count_a'] > $out['count_b'] ? 'a' : 'b' );
	}
	return $out;
}

/**
 * "আরও ৫ দিন" / "আরও ৩ ঘণ্টা".
 *
 * @param int $end End timestamp.
 * @return string
 */
function meeha_poll_remaining( $end ) {
	$s = max( 0, (int) $end - time() );
	if ( $s >= DAY_IN_SECONDS ) {
		return 'আরও ' . meeha_bn_digits( (int) ceil( $s / DAY_IN_SECONDS ) ) . ' দিন';
	}
	if ( $s >= HOUR_IN_SECONDS ) {
		return 'আরও ' . meeha_bn_digits( (int) ceil( $s / HOUR_IN_SECONDS ) ) . ' ঘণ্টা';
	}
	return 'আরও ' . meeha_bn_digits( max( 1, (int) ceil( $s / MINUTE_IN_SECONDS ) ) ) . ' মিনিট';
}

/**
 * Public tally (never names or phones). Results only after the poll closes.
 *
 * @param int $post_id Quote.
 * @return array
 */
function meeha_poll_public( $post_id ) {
	$d   = meeha_poll_data( $post_id );
	$out = array(
		'id'      => (int) $post_id,
		'enabled' => ! empty( $d['enabled'] ),
	);
	if ( empty( $d['enabled'] ) ) {
		return $out;
	}
	$out += array(
		'closed'     => (bool) $d['is_closed'],
		'total'      => (int) $d['total'],
		'total_bn'   => meeha_bn_digits( number_format_i18n( $d['total'] ) ),
		'ends_at'    => gmdate( 'c', $d['end_time'] ),
		'remaining'  => $d['is_closed'] ? '' : meeha_poll_remaining( $d['end_time'] ),
		'ends_label' => meeha_bn_digits( wp_date( 'j', $d['end_time'] ) ) . ' ' . meeha_bengali_month( (int) wp_date( 'n', $d['end_time'] ) ),
		'options'    => array(
			array( 'key' => 'a', 'label' => $d['option_a'] ),
			array( 'key' => 'b', 'label' => $d['option_b'] ),
		),
		'results'    => null,
	);
	if ( $d['is_closed'] ) {
		$tot            = max( 1, $d['total'] );
		$pa             = $d['total'] ? (int) round( $d['count_a'] * 100 / $tot ) : 0;
		$out['results'] = array(
			'a'      => array( 'count' => $d['count_a'], 'pct' => $pa ),
			'b'      => array( 'count' => $d['count_b'], 'pct' => $d['total'] ? 100 - $pa : 0 ),
			'winner' => $d['winner'],
		);
	}
	return $out;
}

/**
 * Normalise a phone number: Bengali digits → ASCII, BD numbers to 01XXXXXXXXX. '' when invalid.
 *
 * @param string $raw Raw.
 * @return string
 */
function meeha_poll_normalize_phone( $raw ) {
	$p = preg_replace( '/[^0-9]/', '', meeha_en_digits( (string) $raw ) );
	if ( 13 === strlen( $p ) && 0 === strpos( $p, '8801' ) ) {
		$p = '0' . substr( $p, 3 );
	} elseif ( 12 === strlen( $p ) && 0 === strpos( $p, '881' ) ) {
		$p = '0' . substr( $p, 2 );
	}
	$len = strlen( $p );
	return ( $len >= 6 && $len <= 15 ) ? $p : '';
}

/**
 * Cast a vote (shared by REST and the legacy AJAX wrapper).
 *
 * @param int   $post_id Quote.
 * @param array $p       choice, name, phone, email, register, consent, website (honeypot).
 * @return array|WP_Error ['message' => …, 'tally' => …]
 */
function meeha_poll_vote( $post_id, $p ) {
	$post_id = absint( $post_id );
	$post    = get_post( $post_id );
	if ( ! $post || 'meeha_photocard' !== $post->post_type || 'publish' !== $post->post_status ) {
		return new WP_Error( 'meeha_poll_404', 'পোলটি খুঁজে পাওয়া যায়নি।', array( 'status' => 404 ) );
	}
	if ( ! empty( $p['website'] ) ) {
		return new WP_Error( 'meeha_poll_spam', 'অনুরোধটি গ্রহণ করা যায়নি।', array( 'status' => 400 ) );
	}
	$choice = isset( $p['choice'] ) ? (string) $p['choice'] : '';
	if ( ! in_array( $choice, array( 'a', 'b' ), true ) ) {
		return new WP_Error( 'meeha_poll_choice', 'অনুগ্রহ করে একটি অপশন বেছে নিন।', array( 'status' => 400 ) );
	}
	$name = isset( $p['name'] ) ? sanitize_text_field( (string) $p['name'] ) : '';
	if ( mb_strlen( $name ) < 2 || mb_strlen( $name ) > 100 ) {
		return new WP_Error( 'meeha_poll_name', 'আপনার নাম লিখুন।', array( 'status' => 400 ) );
	}
	$phone = meeha_poll_normalize_phone( isset( $p['phone'] ) ? $p['phone'] : '' );
	if ( '' === $phone ) {
		return new WP_Error( 'meeha_poll_phone', 'সঠিক ফোন নম্বর দিন।', array( 'status' => 400 ) );
	}
	$email = isset( $p['email'] ) ? sanitize_email( (string) $p['email'] ) : '';
	if ( '' !== (string) ( isset( $p['email'] ) ? $p['email'] : '' ) && ! is_email( $email ) ) {
		return new WP_Error( 'meeha_poll_email', 'সঠিক ইমেইল দিন, অথবা ঘরটি খালি রাখুন।', array( 'status' => 400 ) );
	}
	$d = meeha_poll_data( $post_id );
	if ( empty( $d['enabled'] ) ) {
		return new WP_Error( 'meeha_poll_off', 'এই উক্তিতে ভোট চালু নেই।', array( 'status' => 400 ) );
	}
	if ( ! empty( $d['is_closed'] ) ) {
		return new WP_Error( 'meeha_poll_closed', 'ভোটের মেয়াদ শেষ হয়ে গেছে।', array( 'status' => 409 ) );
	}
	global $wpdb;
	$t        = meeha_poll_table();
	$variants = array_unique( array( $phone, '+88' . $phone, '88' . $phone ) );
	$exists   = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE post_id = %d AND voter_phone IN (%s, %s, %s) LIMIT 1", $post_id, $variants[0], isset( $variants[1] ) ? $variants[1] : $phone, isset( $variants[2] ) ? $variants[2] : $phone ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
	if ( $exists ) {
		// Same answer as a new vote: a response must not reveal whether a phone number already voted.
		return array(
			'message' => 'আপনার ভোট সংরক্ষিত হয়েছে। ধন্যবাদ!',
			'tally'   => meeha_poll_public( $post_id ),
		);
	}
	$ok = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$t,
		array(
			'post_id'     => $post_id,
			'voter_name'  => $name,
			'voter_phone' => $phone,
			'voter_email' => $email ? $email : null,
			'choice'      => $choice,
			'created_at'  => current_time( 'mysql' ),
		),
		array( '%d', '%s', '%s', '%s', '%s', '%s' )
	);
	if ( ! $ok ) { // lost a unique-key race: same generic answer.
		return array(
			'message' => 'আপনার ভোট সংরক্ষিত হয়েছে। ধন্যবাদ!',
			'tally'   => meeha_poll_public( $post_id ),
		);
	}
	delete_transient( 'meeha_poll_' . $post_id );
	if ( ! empty( $p['register'] ) && $email && function_exists( 'meeha_maybe_create_account' ) ) {
		meeha_maybe_create_account( $name, $email, $phone );
	}
	if ( function_exists( 'meeha_maybe_subscribe_mailpoet' ) ) {
		meeha_maybe_subscribe_mailpoet( $email, $name, ! empty( $p['consent'] ) );
	}
	return array(
		'message' => 'আপনার ভোট সংরক্ষিত হয়েছে। ধন্যবাদ!',
		'tally'   => meeha_poll_public( $post_id ),
	);
}

/**
 * Per-IP rate limit (B's limiter when present).
 *
 * @param string $bucket Bucket.
 * @param int    $max    Max.
 * @param int    $window Seconds.
 * @return bool
 */
function meeha_quotes_rate_ok( $bucket, $max, $window ) {
	if ( function_exists( 'meeha_legacy_rate_ok' ) ) {
		return meeha_legacy_rate_ok( $bucket, $max, $window );
	}
	$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	$key = 'meeha_rl_' . $bucket . '_' . substr( wp_hash( $ip ), 0, 16 );
	$n   = (int) get_transient( $key );
	if ( $n >= $max ) {
		return false;
	}
	set_transient( $key, $n + 1, $window );
	return true;
}

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'meeha/v1',
			'/poll/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => '__return_true',
					'callback'            => function ( $req ) {
						$id   = (int) $req['id'];
						$post = get_post( $id );
						if ( ! $post || 'meeha_photocard' !== $post->post_type || 'publish' !== $post->post_status ) {
							return new WP_Error( 'meeha_poll_404', 'পোলটি খুঁজে পাওয়া যায়নি।', array( 'status' => 404 ) );
						}
						$data = get_transient( 'meeha_poll_' . $id );
						if ( ! is_array( $data ) ) {
							$data = meeha_poll_public( $id );
							set_transient( 'meeha_poll_' . $id, $data, 15 );
						}
						$res = rest_ensure_response( $data );
						$res->header( 'Cache-Control', 'public, max-age=15' );
						return $res;
					},
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => '__return_true',
					'callback'            => function ( $req ) {
						if ( function_exists( 'meeha_legacy_same_origin' ) && ! meeha_legacy_same_origin() ) {
							return new WP_Error( 'meeha_poll_origin', 'অনুরোধটি গ্রহণ করা যায়নি।', array( 'status' => 403 ) );
						}
						if ( ! meeha_quotes_rate_ok( 'poll', 10, HOUR_IN_SECONDS ) ) {
							return new WP_Error( 'meeha_poll_rate', 'অল্প সময়ে অনেকবার চেষ্টা হয়েছে। কিছুক্ষণ পরে আবার চেষ্টা করুন।', array( 'status' => 429 ) );
						}
						$r = meeha_poll_vote(
							(int) $req['id'],
							array(
								'choice'   => (string) $req->get_param( 'choice' ),
								'name'     => (string) $req->get_param( 'name' ),
								'phone'    => (string) $req->get_param( 'phone' ),
								'email'    => (string) $req->get_param( 'email' ),
								'register' => (bool) $req->get_param( 'meeha_register' ),
								'consent'  => (bool) $req->get_param( 'meeha_newsletter_consent' ),
								'website'  => (string) $req->get_param( 'website' ),
							)
						);
						if ( is_wp_error( $r ) ) {
							return $r;
						}
						$res = rest_ensure_response( $r );
						$res->header( 'Cache-Control', 'no-store' );
						return $res;
					},
				),
			)
		);
		register_rest_route(
			'meeha/v1',
			'/quote-download/(?P<id>\d+)',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => function ( $req ) {
					$n = meeha_quote_track_download( (int) $req['id'] );
					return is_wp_error( $n ) ? $n : rest_ensure_response( array( 'count' => $n ) );
				},
			)
		);
	}
);

/**
 * Atomic download counter (`_meeha_download_count`), rate-limited per IP.
 *
 * @param int $id Quote.
 * @return int|WP_Error New count.
 */
function meeha_quote_track_download( $id ) {
	$p = get_post( $id );
	if ( ! $p || 'meeha_photocard' !== $p->post_type || 'publish' !== $p->post_status ) {
		return new WP_Error( 'meeha_dl_404', 'উক্তি পাওয়া যায়নি।', array( 'status' => 404 ) );
	}
	if ( function_exists( 'meeha_legacy_same_origin' ) && ! meeha_legacy_same_origin() ) {
		return new WP_Error( 'meeha_dl_origin', 'অনুরোধটি গ্রহণ করা যায়নি।', array( 'status' => 403 ) );
	}
	if ( ! meeha_quotes_rate_ok( 'qdl', 30, HOUR_IN_SECONDS ) ) {
		return new WP_Error( 'meeha_dl_rate', 'অনেকবার চেষ্টা হয়েছে।', array( 'status' => 429 ) );
	}
	global $wpdb;
	$n = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_value = meta_value + 1 WHERE post_id = %d AND meta_key = '_meeha_download_count'", $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	if ( ! $n ) {
		add_post_meta( $id, '_meeha_download_count', 1, true );
	}
	wp_cache_delete( $id, 'post_meta' );
	return (int) get_post_meta( $id, '_meeha_download_count', true );
}

/* ---- legacy AJAX wrappers (old cached HTML may still call them; nonces are not enforced any more) ---- */
add_action( 'wp_ajax_meeha_poll_vote', 'meeha_ajax_poll_vote' );
add_action( 'wp_ajax_nopriv_meeha_poll_vote', 'meeha_ajax_poll_vote' );
/**
 * Legacy vote.
 */
function meeha_ajax_poll_vote() {
	// phpcs:disable WordPress.Security.NonceVerification.Missing
	if ( function_exists( 'meeha_legacy_same_origin' ) && ! meeha_legacy_same_origin() ) {
		wp_send_json_error( array( 'message' => 'অনুরোধটি গ্রহণ করা যায়নি।' ), 403 );
	}
	if ( ! meeha_quotes_rate_ok( 'poll', 10, HOUR_IN_SECONDS ) ) {
		wp_send_json_error( array( 'message' => 'কিছুক্ষণ পরে আবার চেষ্টা করুন।' ) );
	}
	$g = function ( $k ) {
		return isset( $_POST[ $k ] ) ? sanitize_text_field( wp_unslash( $_POST[ $k ] ) ) : '';
	};
	$r = meeha_poll_vote(
		absint( $g( 'post_id' ) ),
		array(
			'choice'   => $g( 'choice' ),
			'name'     => $g( 'name' ),
			'phone'    => $g( 'phone' ),
			'email'    => $g( 'email' ),
			'register' => '' !== $g( 'meeha_register' ),
			'consent'  => '' !== $g( 'meeha_newsletter_consent' ),
			'website'  => $g( 'website' ),
		)
	);
	// phpcs:enable
	if ( is_wp_error( $r ) ) {
		wp_send_json_error( array( 'message' => $r->get_error_message() ) );
	}
	wp_send_json_success( array( 'message' => $r['message'] ) );
}
add_action( 'wp_ajax_meeha_poll_live_tally', 'meeha_ajax_poll_tally' );
add_action( 'wp_ajax_nopriv_meeha_poll_live_tally', 'meeha_ajax_poll_tally' );
/**
 * Legacy tally: total only, never voter names/phones.
 */
function meeha_ajax_poll_tally() {
	$id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	if ( ! $id || 'meeha_photocard' !== get_post_type( $id ) ) {
		wp_send_json_error();
	}
	$d = meeha_poll_data( $id );
	if ( empty( $d['enabled'] ) ) {
		wp_send_json_error();
	}
	wp_send_json_success(
		array(
			'total'  => (int) $d['total'],
			'voters' => array(),
		)
	);
}
add_action( 'wp_ajax_meeha_track_download', 'meeha_ajax_track_download' );
add_action( 'wp_ajax_nopriv_meeha_track_download', 'meeha_ajax_track_download' );
/**
 * Legacy download counter.
 */
function meeha_ajax_track_download() {
	$n = meeha_quote_track_download( isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	if ( is_wp_error( $n ) ) {
		wp_send_json_error();
	}
	wp_send_json_success( array( 'count' => $n ) );
}

/**
 * Poll form / results (server-rendered state; quotes.js submits via REST and refreshes the total every 15 s).
 *
 * @param WP_Post $q Quote.
 */
function meeha_quote_poll_form( $q ) {
	$t = meeha_poll_public( $q->ID );
	if ( empty( $t['enabled'] ) ) {
		return;
	}
	$question = (string) meeha_opt( 'quotes', 'poll_question', 'আপনি কি এই কথার সঙ্গে একমত?' );
	printf(
		'<form class="m-poll%1$s" data-poll="%2$d" data-endpoint="%3$s" method="post" action="%4$s" novalidate aria-labelledby="m-poll-q">',
		$t['closed'] ? ' is-closed' : '',
		(int) $q->ID,
		esc_url( rest_url( 'meeha/v1/poll/' . $q->ID ) ),
		esc_url( admin_url( 'admin-ajax.php' ) )
	);
	echo '<p class="m-poll__q" id="m-poll-q">' . esc_html( $question ) . '</p>';
	if ( $t['closed'] ) {
		foreach ( array( 'a', 'b' ) as $i => $k ) {
			$pct = (int) $t['results'][ $k ]['pct'];
			echo '<div class="m-poll__opt' . ( 'b' === $k ? ' m-poll__opt--no' : '' ) . '"><div class="m-poll__row"><span>' . esc_html( $t['options'][ $i ]['label'] ) . '</span><span>' . esc_html( meeha_bn_digits( $pct ) ) . '%</span></div><div class="m-poll__bar"><i style="width:' . (int) $pct . '%"></i></div></div>';
		}
		echo '<p class="m-poll__note">' . esc_html( $t['total_bn'] . ' জন ভোট দিয়েছেন। ভোট শেষ হয়েছে।' ) . '</p></form>';
		return;
	}
	echo '<input type="hidden" name="action" value="meeha_poll_vote"><input type="hidden" name="post_id" value="' . (int) $q->ID . '">';
	echo '<fieldset class="m-poll__choices"><legend class="m-vh">আপনার মত</legend>';
	foreach ( $t['options'] as $o ) {
		echo '<label class="m-poll__choice"><input type="radio" name="choice" value="' . esc_attr( $o['key'] ) . '" required><span>' . esc_html( $o['label'] ) . '</span></label>';
	}
	echo '</fieldset><div class="m-poll__fields">';
	echo '<label class="m-poll__field"><span>আপনার নাম</span><input class="m-input" type="text" name="name" autocomplete="name" maxlength="100" required></label>';
	echo '<label class="m-poll__field"><span>ফোন নম্বর</span><input class="m-input" type="tel" name="phone" autocomplete="tel" inputmode="tel" maxlength="20" required></label>';
	echo '<label class="m-poll__field m-poll__field--wide"><span>ইমেইল <small>(ঐচ্ছিক)</small></span><input class="m-input" type="email" name="email" autocomplete="email" inputmode="email" maxlength="100"></label>';
	echo '</div>';
	$checks = '';
	if ( ! is_user_logged_in() && function_exists( 'meeha_registration_open' ) && meeha_registration_open() ) {
		$checks .= '<label class="m-check"><input type="checkbox" name="meeha_register" value="1"> <span>এই ইমেইলে মিহা নিউজের একাউন্ট খুলতে চাই</span></label>';
	}
	if ( function_exists( 'meeha_mailpoet_consent_field' ) ) {
		$checks .= meeha_mailpoet_consent_field( 'm-poll' );
	}
	if ( '' !== $checks ) {
		echo '<div class="m-poll__checks">' . $checks . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped/fixed markup.
	}
	echo '<p class="m-hp" aria-hidden="true"><label>ওয়েবসাইট<input type="text" name="website" value="" tabindex="-1" autocomplete="off"></label></p>';
	echo '<button class="m-btn" type="submit">ভোট দিন</button>';
	echo '<p class="m-poll__note" data-poll-note>' . esc_html( $t['total_bn'] . ' জন ভোট দিয়েছেন। ফলাফল জানা যাবে ' . $t['ends_label'] . '-এ (ভোট চলবে ' . $t['remaining'] . ')।' ) . '</p>';
	echo '<p class="m-poll__privacy">আপনার নাম ও ফোন নম্বর কোথাও প্রকাশ করা হয় না। একটি নম্বর দিয়ে একবারই ভোট দেওয়া যায়।</p>';
	echo '<p class="m-poll__msg" role="status" aria-live="polite" data-poll-msg></p>';
	echo '</form>';
}

/* Breadcrumb on a quote single: … / উক্তি / {type}. */
add_filter(
	'meeha_breadcrumb_items',
	function ( $items ) {
		if ( is_singular( 'meeha_photocard' ) ) {
			$terms = get_the_terms( get_queried_object_id(), 'meeha_quote_type' );
			if ( $terms && ! is_wp_error( $terms ) ) {
				$items[] = array( $terms[0]->name, get_term_link( $terms[0] ) );
			}
		}
		return $items;
	}
);
