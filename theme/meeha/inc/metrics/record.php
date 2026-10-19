<?php
/**
 * Recording: views (beacon or legacy server mode), shares, reactions, comments → engagement.
 *
 * Views keep the legacy semantics of `_meeha_views` (lifetime int; one count per visitor-IP per post per
 * 24 h) — now with bot filtering, an atomic SQL increment and a matching hourly-table write. Videos
 * (`meeha_video`) are counted by newsroom-os and rejected here so nothing is double counted.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

/**
 * Post types whose views/engagement the theme records.
 *
 * @return string[]
 */
function meeha_metrics_post_types() {
	return (array) apply_filters( 'meeha_metrics_post_types', array( 'post', 'meeha_photocard' ) );
}

/**
 * Reaction types: legacy emoji set + the new editorial set (single/opinion UI).
 *
 * @return string[]
 */
function meeha_metrics_reaction_types() {
	return (array) apply_filters( 'meeha_reaction_types', array( 'like', 'love', 'haha', 'wow', 'sad', 'angry', 'important', 'informative', 'worrying', 'disagree', 'agree', 'partly' ) );
}

/**
 * Share networks accepted by the share counter.
 *
 * @return string[]
 */
function meeha_metrics_share_networks() {
	return (array) apply_filters( 'meeha_share_count_networks', array( 'facebook', 'whatsapp', 'x', 'telegram', 'copy', 'native', 'messenger', 'linkedin', 'email' ) );
}

/**
 * Known crawler / tool user agents (empty UA counts as a bot).
 *
 * @param string|null $ua User agent (default: current request).
 * @return bool
 */
function meeha_metrics_is_bot( $ua = null ) {
	if ( null === $ua ) {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
	}
	$ua = trim( (string) $ua );
	if ( '' === $ua || strlen( $ua ) < 12 ) {
		return true;
	}
	$re = '~bot\b|bot/|crawl|spider|slurp|archiver|facebookexternalhit|facebookcatalog|meta-externalagent|embedly|preview|headless|lighthouse|pagespeed|chrome-lighthouse|gtmetrix|pingdom|uptime|monitor|curl/|wget/|python|httpclient|http_request|java/|go-http|okhttp|axios|node-fetch|undici|libwww|phantomjs|selenium|puppeteer|playwright|scrapy|mediapartners|adsbot|feedfetcher|validator|w3c_|whatsapp/|telegrambot|discordbot|slackbot|skypeuripreview|bingpreview|yandex|baidu|petalbot|semrush|ahrefs|mj12|dotbot|dataforseo|bytespider|gptbot|claudebot|ccbot|amazonbot|applebot~i';
	return (bool) apply_filters( 'meeha_metrics_is_bot', (bool) preg_match( $re, $ua ), $ua );
}

/**
 * Same-origin guard for the anonymous write endpoints: the browser's Origin (or, failing that,
 * Referer) must be this site. Browsers send Origin on every sendBeacon / fetch / XHR POST, so a
 * request with neither header is not a page beacon and is refused.
 *
 * @return bool
 */
function meeha_metrics_same_origin() {
	$hosts = array_filter(
		array_unique(
			array(
				strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ),
				strtolower( (string) wp_parse_url( site_url(), PHP_URL_HOST ) ),
			)
		)
	);
	foreach ( array( 'HTTP_ORIGIN', 'HTTP_REFERER' ) as $h ) {
		if ( ! empty( $_SERVER[ $h ] ) ) {
			$host = strtolower( (string) wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER[ $h ] ) ), PHP_URL_HOST ) );
			return '' !== $host && in_array( $host, $hosts, true );
		}
	}
	return (bool) apply_filters( 'meeha_metrics_allow_no_origin', false );
}

/**
 * A published post of a counted type, or null.
 *
 * @param int $id Post id.
 * @return WP_Post|null
 */
function meeha_metrics_countable_post( $id ) {
	$p = get_post( absint( $id ) );
	if ( ! $p || 'publish' !== $p->post_status || ! in_array( $p->post_type, meeha_metrics_post_types(), true ) ) {
		return null;
	}
	return $p;
}

/**
 * Record one view (dedupe 24 h per visitor + post) into `_meeha_views` and the hourly table.
 *
 * @param int    $post_id Post.
 * @param string $vkey    Visitor key (default: current).
 * @return bool Counted.
 */
function meeha_metrics_record_view( $post_id, $vkey = '' ) {
	$post_id = (int) $post_id;
	$vkey    = $vkey ? $vkey : meeha_metrics_vkey();
	if ( ! meeha_metrics_ready() ) {
		meeha_metrics_install();
	}
	if ( ! meeha_metrics_once( $vkey, $post_id, 'view', DAY_IN_SECONDS ) ) {
		return false;
	}
	// Per-post daily cap per IPv6 /48 (a hosting customer's whole range): at most N counted views per
	// post per day, so rotating /64s inside one allocation cannot inflate `_meeha_views` (paid bonuses).
	$ip = function_exists( 'meeha_client_ip' ) ? (string) apply_filters( 'meeha_metrics_client_ip', meeha_client_ip() ) : '';
	if ( function_exists( 'meeha_ip_bucket' ) && false !== strpos( $ip, ':' ) ) {
		$net = substr( wp_hash( meeha_ip_bucket( $ip, 48 ) . '|' . $post_id . '|meeha-v48' ), 0, 24 );
		if ( ! meeha_metrics_rate_ok( 'v48', (int) apply_filters( 'meeha_views_per_net48_day', 20, $post_id ), DAY_IN_SECONDS, $net ) ) {
			return false;
		}
	}
	meeha_metrics_incr_views( $post_id, 1 );
	meeha_metrics_bump( meeha_metrics_table( 'hourly' ), array( 'post_id' => $post_id, 'hour' => meeha_metrics_hour() ), array( 'views' => 1 ) );
	/**
	 * After a view was counted.
	 *
	 * @param int $post_id Post.
	 */
	do_action( 'meeha_view_counted', $post_id );
	return true;
}

/**
 * Current share counts (ints; legacy networks always present).
 *
 * @param int $post_id Post.
 * @return array
 */
function meeha_metrics_share_counts( $post_id ) {
	$c = meeha_metrics_int_counts( get_post_meta( (int) $post_id, '_meeha_share_counts', true ) );
	return array_merge( array( 'facebook' => 0, 'whatsapp' => 0, 'x' => 0, 'copy' => 0 ), $c );
}

/**
 * Record a share click (once per visitor + post + network per 24 h).
 *
 * @param int    $post_id Post.
 * @param string $network Network.
 * @param string $vkey    Visitor key.
 * @return array ['counts' => array, 'total' => int, 'counted' => bool]
 */
function meeha_metrics_record_share( $post_id, $network, $vkey = '' ) {
	$post_id = (int) $post_id;
	$vkey    = $vkey ? $vkey : meeha_metrics_vkey();
	$counted = false;
	if ( meeha_metrics_once( $vkey, $post_id, 'share:' . substr( $network, 0, 16 ), DAY_IN_SECONDS ) ) {
		$counts  = meeha_metrics_update_array_meta(
			$post_id,
			'_meeha_share_counts',
			function ( $cur ) use ( $network ) {
				$cur             = meeha_metrics_int_counts( $cur );
				$cur[ $network ] = ( isset( $cur[ $network ] ) ? $cur[ $network ] : 0 ) + 1;
				return $cur;
			}
		);
		$counted = true;
		meeha_metrics_bump( meeha_metrics_table( 'engagement' ), array( 'post_id' => $post_id, 'day' => meeha_metrics_day() ), array( 'shares' => 1 ) );
	}
	$counts = meeha_metrics_share_counts( $post_id );
	return array(
		'counts'  => $counts,
		'total'   => array_sum( $counts ),
		'counted' => $counted,
	);
}

/**
 * Current reaction counts (ints; every allowed type present).
 *
 * @param int $post_id Post.
 * @return array
 */
function meeha_metrics_reaction_counts( $post_id ) {
	$c = meeha_metrics_int_counts( get_post_meta( (int) $post_id, '_meeha_reactions', true ) );
	return array_merge( array_fill_keys( meeha_metrics_reaction_types(), 0 ), $c );
}

/**
 * Record a reaction. The server keeps one record per visitor + post (the visitor's current reaction),
 * so a visitor can switch reactions (their own previous one is decremented) but can never decrement
 * anybody else's: `prev` from the client is only honoured when it matches that record.
 *
 * @param int    $post_id Post.
 * @param string $type    Reaction type.
 * @param string $prev    Client-side previous reaction (legacy localStorage `meeha_reaction_{id}`).
 * @param string $vkey    Visitor key.
 * @return array ['counts' => array, 'mine' => string, 'changed' => bool]
 */
function meeha_metrics_record_reaction( $post_id, $type, $prev = '', $vkey = '' ) {
	global $wpdb;
	$post_id = (int) $post_id;
	$vkey    = $vkey ? $vkey : meeha_metrics_vkey();
	$t       = meeha_metrics_table( 'log' );
	$now     = time();
	$changed = false;
	$mine    = $type;
	$old     = $wpdb->get_var( $wpdb->prepare( "SELECT val FROM {$t} WHERE vkey = %s AND post_id = %d AND kind = 'react'", $vkey, $post_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL

	if ( null === $old ) {
		// First reaction of this visitor (an unverifiable client `prev` is ignored: nothing to decrement).
		if ( $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$t} (vkey, post_id, kind, val, n, ts) VALUES (%s, %d, 'react', %s, 1, %d)", $vkey, $post_id, $type, $now ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL
			meeha_metrics_update_array_meta(
				$post_id,
				'_meeha_reactions',
				function ( $cur ) use ( $type ) {
					$cur          = meeha_metrics_int_counts( $cur );
					$cur[ $type ] = ( isset( $cur[ $type ] ) ? $cur[ $type ] : 0 ) + 1;
					return $cur;
				}
			);
			meeha_metrics_bump( meeha_metrics_table( 'engagement' ), array( 'post_id' => $post_id, 'day' => meeha_metrics_day() ), array( 'reactions' => 1 ) );
			$changed = true;
		}
	} elseif ( (string) $old !== $type ) {
		// Switch: only this visitor's own recorded reaction is moved (CAS on the record).
		if ( $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET val = %s, ts = %d, n = n + 1 WHERE vkey = %s AND post_id = %d AND kind = 'react' AND val = %s", $type, $now, $vkey, $post_id, (string) $old ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$from = (string) $old;
			meeha_metrics_update_array_meta(
				$post_id,
				'_meeha_reactions',
				function ( $cur ) use ( $type, $from ) {
					$cur          = meeha_metrics_int_counts( $cur );
					$cur[ $from ] = max( 0, ( isset( $cur[ $from ] ) ? $cur[ $from ] : 0 ) - 1 );
					$cur[ $type ] = ( isset( $cur[ $type ] ) ? $cur[ $type ] : 0 ) + 1;
					return $cur;
				}
			);
			$changed = true;
		}
	}
	unset( $prev ); // documented: never trusted on its own.
	return array(
		'counts'  => meeha_metrics_reaction_counts( $post_id ),
		'mine'    => $mine,
		'changed' => $changed,
	);
}

/* --------------------------------------------------------------------------
 * Comments → engagement
 * ------------------------------------------------------------------------ */

/**
 * +1 / −1 comments on the engagement table when a comment becomes (un)approved.
 *
 * @param WP_Comment|object $comment Comment.
 * @param int               $delta   Delta.
 */
function meeha_metrics_comment_delta( $comment, $delta ) {
	if ( ! $comment || ! empty( $comment->comment_type ) && 'comment' !== $comment->comment_type ) {
		return;
	}
	$post = get_post( (int) $comment->comment_post_ID );
	if ( ! $post || ! in_array( $post->post_type, meeha_metrics_post_types(), true ) || ! meeha_metrics_ready() ) {
		return;
	}
	$ts = strtotime( (string) $comment->comment_date_gmt . ' UTC' );
	meeha_metrics_bump( meeha_metrics_table( 'engagement' ), array( 'post_id' => (int) $post->ID, 'day' => meeha_metrics_day( $ts ? $ts : null ) ), array( 'comments' => (int) $delta ) );
}
add_action(
	'wp_insert_comment',
	function ( $id, $comment ) {
		if ( 1 === (int) $comment->comment_approved || '1' === (string) $comment->comment_approved ) {
			meeha_metrics_comment_delta( $comment, 1 );
		}
	},
	10,
	2
);
add_action(
	'transition_comment_status',
	function ( $new, $old, $comment ) {
		if ( 'approved' === $new && 'approved' !== $old ) {
			meeha_metrics_comment_delta( $comment, 1 );
		} elseif ( 'approved' === $old && 'approved' !== $new ) {
			meeha_metrics_comment_delta( $comment, -1 );
		}
	},
	10,
	3
);

/* --------------------------------------------------------------------------
 * Legacy server-side mode ("পুরোনো পদ্ধতি") — counts while PHP renders the page
 * ------------------------------------------------------------------------ */

/**
 * View counting mode: 'beacon' (default) or 'legacy'.
 *
 * @return string
 */
function meeha_metrics_mode() {
	$m = function_exists( 'meeha_opt' ) ? (string) meeha_opt( 'home', 'view_mode', 'beacon' ) : 'beacon';
	return 'legacy' === $m ? 'legacy' : 'beacon';
}

/**
 * Should the current logged-in user be skipped (editors and above, when the setting is on)?
 *
 * @return bool
 */
function meeha_metrics_skip_user() {
	return is_user_logged_in() && current_user_can( 'edit_others_posts' ) && (int) meeha_opt( 'home', 'view_skip_editors', 1 );
}

add_action(
	'template_redirect',
	function () {
		if ( 'legacy' !== meeha_metrics_mode() || ! is_singular( meeha_metrics_post_types() ) || is_preview() || is_feed() ) {
			return;
		}
		if ( meeha_metrics_is_bot() || meeha_metrics_skip_user() ) {
			return;
		}
		$p = meeha_metrics_countable_post( get_queried_object_id() );
		if ( $p ) {
			meeha_metrics_record_view( $p->ID );
		}
	},
	20
);
