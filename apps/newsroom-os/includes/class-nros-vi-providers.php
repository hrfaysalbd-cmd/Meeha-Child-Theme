<?php
/**
 * NROS_VI_Providers — ভিউ/ইনসাইট (newsroom-os 1.17.0): the talk with Facebook,
 * YouTube and TikTok. OAuth (start + callback), token refresh, the daily
 * numbers, top content and "who is live right now".
 *
 * Everything here goes through wp_remote_request(), so a site can test it
 * with the core `pre_http_request` filter (the round-34 tests do exactly that).
 *
 * Endpoints used (checked against the official docs on 2026-11-09 — these
 * change often, see DEPLOY-NOTES):
 *   Facebook  Graph API: /dialog/oauth, /oauth/access_token, /me/accounts,
 *             /{page}/insights (page_media_view, page_post_engagements,
 *             page_follows), /{page}/posts, /{page}/live_videos
 *   YouTube   accounts.google.com OAuth, Data API v3 channels / playlistItems
 *             / videos / liveBroadcasts, YouTube Analytics API v2 reports
 *   TikTok    Login Kit v2 (/v2/auth/authorize, /v2/oauth/token), Display API
 *             /v2/user/info, /v2/video/list
 *
 * @package NewsroomOS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NROS_VI_Providers {

	const FB_DEFAULT_VERSION = 'v25.0';
	const FB_SCOPES          = 'pages_show_list,pages_read_engagement,read_insights,business_management';
	const GOOGLE_SCOPES      = 'https://www.googleapis.com/auth/yt-analytics.readonly https://www.googleapis.com/auth/youtube.readonly';
	const TIKTOK_SCOPES      = 'user.info.basic,user.info.stats,video.list';

	/** Facebook keeps public-Page insights 2 years; ask for 729 days to stay inside. */
	const FB_BACK_DAYS = 729;
	/** Days per Facebook insights request (the API limits one since/until window). */
	const FB_CHUNK = 90;
	/** Days per YouTube Analytics request during backfill. */
	const YT_CHUNK = 365;

	/** Facebook page-level metrics → our column. Each is asked for on its own, so one retired metric never breaks the rest. */
	const FB_METRICS = array(
		'page_media_view'       => 'views',
		'page_post_engagements' => 'engagement',
		'page_follows'          => 'followers',
	);

	// ---------------------------------------------------------------------
	// HTTP
	// ---------------------------------------------------------------------

	/**
	 * One API call. Returns decoded JSON, or WP_Error with the platform's own message.
	 *
	 * @param string $method GET|POST.
	 * @param string $url    URL (query already on it).
	 * @param array  $args   headers, body.
	 * @return array|WP_Error
	 */
	public static function http( $method, $url, $args = array() ) {
		$res = wp_remote_request(
			$url,
			array(
				'method'  => $method,
				'timeout' => 25,
				'headers' => isset( $args['headers'] ) ? $args['headers'] : array(),
				'body'    => isset( $args['body'] ) ? $args['body'] : null,
			)
		);
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'nros_vi_network', 'নেটওয়ার্ক সমস্যা: ' . $res->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'nros_vi_bad_reply', 'অপ্রত্যাশিত উত্তর (HTTP ' . $code . ')', array( 'http' => $code ) );
		}
		// Facebook / Google style error object.
		if ( isset( $data['error'] ) && is_array( $data['error'] ) && ( ! isset( $data['error']['code'] ) || 'ok' !== $data['error']['code'] ) ) {
			$e    = $data['error'];
			$msg  = isset( $e['message'] ) ? (string) $e['message'] : ( isset( $data['error_description'] ) ? (string) $data['error_description'] : 'API error' );
			$ecode = isset( $e['code'] ) ? (string) $e['code'] : '';
			return new WP_Error( 'nros_vi_api', $msg, array( 'http' => $code, 'api_code' => $ecode, 'auth' => self::is_auth_error( $code, $ecode, $e ) ) );
		}
		// Google token endpoint: {"error":"invalid_grant","error_description":…}.
		if ( isset( $data['error'] ) && is_string( $data['error'] ) ) {
			$msg = isset( $data['error_description'] ) ? (string) $data['error_description'] : (string) $data['error'];
			return new WP_Error( 'nros_vi_api', $msg, array( 'http' => $code, 'api_code' => (string) $data['error'], 'auth' => in_array( $data['error'], array( 'invalid_grant', 'invalid_token', 'unauthorized_client', 'access_token_invalid' ), true ) ) );
		}
		if ( $code >= 400 ) {
			return new WP_Error( 'nros_vi_api', 'HTTP ' . $code, array( 'http' => $code, 'auth' => 401 === $code ) );
		}
		return $data;
	}

	/**
	 * Whether an API error means "connect again" rather than "try later".
	 *
	 * @param int    $http  HTTP status.
	 * @param string $code  Platform error code.
	 * @param array  $error Error object.
	 * @return bool
	 */
	protected static function is_auth_error( $http, $code, $error ) {
		if ( 401 === (int) $http ) {
			return true;
		}
		// Facebook 190 = token invalid/expired, 102 = session, 10/200 = permission.
		if ( in_array( (string) $code, array( '190', '102', 'access_token_invalid', 'access_token_expired' ), true ) ) {
			return true;
		}
		return false;
	}

	// ---------------------------------------------------------------------
	// App credentials (Settings → ভিউ/ইনসাইট সেটআপ)
	// ---------------------------------------------------------------------

	/**
	 * Raw stored app config for one provider (secret still encrypted).
	 *
	 * @param string $provider facebook|google|tiktok.
	 * @return array{id:string,secret:string,version:string}
	 */
	public static function app( $provider ) {
		$all = get_option( 'nros_vi_apps', array() );
		$a   = isset( $all[ $provider ] ) && is_array( $all[ $provider ] ) ? $all[ $provider ] : array();
		return array(
			'id'      => isset( $a['id'] ) ? (string) $a['id'] : '',
			'secret'  => ! empty( $a['secret'] ) ? NROS_Core::decrypt( (string) $a['secret'] ) : '',
			'version' => ! empty( $a['version'] ) ? (string) $a['version'] : self::FB_DEFAULT_VERSION,
		);
	}

	/** Whether a provider has both id and secret. */
	public static function app_ready( $provider ) {
		$a = self::app( $provider );
		return '' !== $a['id'] && '' !== $a['secret'];
	}

	/** Our OAuth return address, to paste in each developer console. */
	public static function redirect_uri( $provider ) {
		return rest_url( NROS_API_NS . '/vi/oauth/' . $provider );
	}

	/** Facebook Graph base. */
	protected static function graph( $path, $query = array() ) {
		$v = self::app( 'facebook' )['version'];
		return add_query_arg( array_map( 'rawurlencode', $query ), 'https://graph.facebook.com/' . rawurlencode( $v ) . '/' . ltrim( $path, '/' ) );
	}

	// ---------------------------------------------------------------------
	// OAuth start
	// ---------------------------------------------------------------------

	/**
	 * Provider sign-in URL for a fresh state.
	 *
	 * @param string $provider facebook|google|tiktok.
	 * @param string $state    State.
	 * @return string
	 */
	public static function auth_url( $provider, $state ) {
		$a  = self::app( $provider );
		$cb = self::redirect_uri( $provider );
		if ( 'facebook' === $provider ) {
			return add_query_arg(
				array_map(
					'rawurlencode',
					array(
						'client_id'     => $a['id'],
						'redirect_uri'  => $cb,
						'state'         => $state,
						'scope'         => self::FB_SCOPES,
						'response_type' => 'code',
					)
				),
				'https://www.facebook.com/' . rawurlencode( $a['version'] ) . '/dialog/oauth'
			);
		}
		if ( 'google' === $provider ) {
			return add_query_arg(
				array_map(
					'rawurlencode',
					array(
						'client_id'              => $a['id'],
						'redirect_uri'           => $cb,
						'response_type'          => 'code',
						'scope'                  => self::GOOGLE_SCOPES,
						'access_type'            => 'offline',
						'prompt'                 => 'consent select_account',
						'include_granted_scopes' => 'true',
						'state'                  => $state,
					)
				),
				'https://accounts.google.com/o/oauth2/v2/auth'
			);
		}
		return add_query_arg(
			array_map(
				'rawurlencode',
				array(
					'client_key'    => $a['id'],
					'scope'         => self::TIKTOK_SCOPES,
					'response_type' => 'code',
					'redirect_uri'  => $cb,
					'state'         => $state,
				)
			),
			'https://www.tiktok.com/v2/auth/authorize/'
		);
	}

	// ---------------------------------------------------------------------
	// OAuth callback: code → accounts
	// ---------------------------------------------------------------------

	/**
	 * Facebook: code → long-lived user token → the Pages this person runs.
	 * Page tokens made from a long-lived user token do not expire on a timer
	 * (they stop working if the password changes or the admin role goes).
	 *
	 * @param string $code Code.
	 * @return array|WP_Error list of {id,name,link,picture,followers,token}
	 */
	public static function facebook_exchange( $code ) {
		$a   = self::app( 'facebook' );
		$tok = self::http(
			'GET',
			self::graph(
				'oauth/access_token',
				array(
					'client_id'     => $a['id'],
					'client_secret' => $a['secret'],
					'redirect_uri'  => self::redirect_uri( 'facebook' ),
					'code'          => $code,
				)
			)
		);
		if ( is_wp_error( $tok ) ) {
			return $tok;
		}
		$short = isset( $tok['access_token'] ) ? (string) $tok['access_token'] : '';
		$long  = self::http(
			'GET',
			self::graph(
				'oauth/access_token',
				array(
					'grant_type'        => 'fb_exchange_token',
					'client_id'         => $a['id'],
					'client_secret'     => $a['secret'],
					'fb_exchange_token' => $short,
				)
			)
		);
		$user_token = ( ! is_wp_error( $long ) && ! empty( $long['access_token'] ) ) ? (string) $long['access_token'] : $short;

		$pages = array();
		$url   = self::graph(
			'me/accounts',
			array(
				'fields'       => 'id,name,link,access_token,followers_count,fan_count,picture{url}',
				'limit'        => '100',
				'access_token' => $user_token,
			)
		);
		for ( $i = 0; $i < 5 && $url; $i++ ) {
			$r = self::http( 'GET', $url );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			foreach ( (array) ( isset( $r['data'] ) ? $r['data'] : array() ) as $p ) {
				if ( empty( $p['id'] ) || empty( $p['access_token'] ) ) {
					continue;
				}
				$pages[] = array(
					'id'        => (string) $p['id'],
					'name'      => isset( $p['name'] ) ? (string) $p['name'] : '',
					'link'      => isset( $p['link'] ) ? (string) $p['link'] : 'https://www.facebook.com/' . $p['id'],
					'picture'   => isset( $p['picture']['data']['url'] ) ? (string) $p['picture']['data']['url'] : '',
					'followers' => isset( $p['followers_count'] ) ? (int) $p['followers_count'] : ( isset( $p['fan_count'] ) ? (int) $p['fan_count'] : null ),
					'token'     => (string) $p['access_token'],
				);
			}
			$url = isset( $r['paging']['next'] ) ? (string) $r['paging']['next'] : '';
		}
		return $pages;
	}

	/**
	 * Google: code → tokens → the channel(s) of the signed-in Google/brand account.
	 *
	 * @param string $code Code.
	 * @return array|WP_Error list of account rows to upsert
	 */
	public static function google_exchange( $code ) {
		$a   = self::app( 'google' );
		$tok = self::http(
			'POST',
			'https://oauth2.googleapis.com/token',
			array(
				'body' => array(
					'code'          => $code,
					'client_id'     => $a['id'],
					'client_secret' => $a['secret'],
					'redirect_uri'  => self::redirect_uri( 'google' ),
					'grant_type'    => 'authorization_code',
				),
			)
		);
		if ( is_wp_error( $tok ) ) {
			return $tok;
		}
		if ( empty( $tok['access_token'] ) ) {
			return new WP_Error( 'nros_vi_api', 'Google টোকেন দেয়নি।' );
		}
		$access = (string) $tok['access_token'];
		$ch     = self::http( 'GET', 'https://www.googleapis.com/youtube/v3/channels?part=snippet,statistics,contentDetails&mine=true', array( 'headers' => array( 'Authorization' => 'Bearer ' . $access ) ) );
		if ( is_wp_error( $ch ) ) {
			return $ch;
		}
		$out = array();
		foreach ( (array) ( isset( $ch['items'] ) ? $ch['items'] : array() ) as $c ) {
			$out[] = array(
				'platform'      => 'youtube',
				'ext_id'        => (string) $c['id'],
				'name'          => isset( $c['snippet']['title'] ) ? (string) $c['snippet']['title'] : '',
				'handle'        => isset( $c['snippet']['customUrl'] ) ? (string) $c['snippet']['customUrl'] : '',
				'url'           => 'https://www.youtube.com/channel/' . $c['id'],
				'avatar'        => isset( $c['snippet']['thumbnails']['default']['url'] ) ? (string) $c['snippet']['thumbnails']['default']['url'] : '',
				'followers'     => isset( $c['statistics']['subscriberCount'] ) ? (int) $c['statistics']['subscriberCount'] : null,
				'lifetime'      => isset( $c['statistics']['viewCount'] ) ? (int) $c['statistics']['viewCount'] : null,
				'started'       => isset( $c['snippet']['publishedAt'] ) ? substr( (string) $c['snippet']['publishedAt'], 0, 10 ) : '',
				'token'         => $access,
				'token_expires' => time() + ( isset( $tok['expires_in'] ) ? (int) $tok['expires_in'] : 3600 ) - 60,
				'refresh'       => isset( $tok['refresh_token'] ) ? (string) $tok['refresh_token'] : '',
				'refresh_expires' => 0,
				'meta'          => array( 'uploads' => isset( $c['contentDetails']['relatedPlaylists']['uploads'] ) ? (string) $c['contentDetails']['relatedPlaylists']['uploads'] : '' ),
			);
		}
		if ( ! $out ) {
			return new WP_Error( 'nros_vi_api', 'এই Google অ্যাকাউন্টে কোনো ইউটিউব চ্যানেল পাওয়া যায়নি। ব্র্যান্ড চ্যানেল হলে সাইন-ইনের সময় চ্যানেলটি বেছে নিন।' );
		}
		return $out;
	}

	/**
	 * TikTok: code → tokens (access 24 h, refresh about a year) → the account.
	 *
	 * @param string $code Code.
	 * @return array|WP_Error list with one account row
	 */
	public static function tiktok_exchange( $code ) {
		$a   = self::app( 'tiktok' );
		$tok = self::http(
			'POST',
			'https://open.tiktokapis.com/v2/oauth/token/',
			array(
				'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
				'body'    => array(
					'client_key'    => $a['id'],
					'client_secret' => $a['secret'],
					'code'          => $code,
					'grant_type'    => 'authorization_code',
					'redirect_uri'  => self::redirect_uri( 'tiktok' ),
				),
			)
		);
		if ( is_wp_error( $tok ) ) {
			return $tok;
		}
		if ( empty( $tok['access_token'] ) ) {
			return new WP_Error( 'nros_vi_api', 'TikTok টোকেন দেয়নি।' );
		}
		$acc  = array(
			'platform'        => 'tiktok',
			'ext_id'          => isset( $tok['open_id'] ) ? (string) $tok['open_id'] : '',
			'token'           => (string) $tok['access_token'],
			'token_expires'   => time() + ( isset( $tok['expires_in'] ) ? (int) $tok['expires_in'] : 86400 ) - 60,
			'refresh'         => isset( $tok['refresh_token'] ) ? (string) $tok['refresh_token'] : '',
			'refresh_expires' => time() + ( isset( $tok['refresh_expires_in'] ) ? (int) $tok['refresh_expires_in'] : 31536000 ),
		);
		$info = self::tiktok_user( $acc['token'] );
		if ( is_wp_error( $info ) ) {
			return $info;
		}
		$acc['ext_id']    = $acc['ext_id'] ? $acc['ext_id'] : (string) $info['open_id'];
		$acc['name']      = isset( $info['display_name'] ) ? (string) $info['display_name'] : 'TikTok';
		$acc['handle']    = isset( $info['username'] ) ? '@' . $info['username'] : '';
		$acc['url']       = isset( $info['profile_deep_link'] ) ? (string) $info['profile_deep_link'] : '';
		$acc['avatar']    = isset( $info['avatar_url'] ) ? (string) $info['avatar_url'] : '';
		$acc['followers'] = isset( $info['follower_count'] ) ? (int) $info['follower_count'] : null;
		return array( $acc );
	}

	/** TikTok user info. */
	protected static function tiktok_user( $token ) {
		$r = self::http(
			'GET',
			'https://open.tiktokapis.com/v2/user/info/?fields=open_id,display_name,username,avatar_url,profile_deep_link,follower_count,following_count,likes_count,video_count',
			array( 'headers' => array( 'Authorization' => 'Bearer ' . $token ) )
		);
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		return isset( $r['data']['user'] ) && is_array( $r['data']['user'] ) ? $r['data']['user'] : new WP_Error( 'nros_vi_api', 'TikTok ব্যবহারকারীর তথ্য পাওয়া যায়নি।' );
	}

	// ---------------------------------------------------------------------
	// Tokens
	// ---------------------------------------------------------------------

	/**
	 * A working access token for an account, refreshing Google/TikTok tokens when due.
	 *
	 * @param object $acc Account row.
	 * @return string|WP_Error
	 */
	public static function token( $acc ) {
		$token = $acc->token_enc ? NROS_Core::decrypt( $acc->token_enc ) : '';
		if ( 'facebook' === $acc->platform ) {
			return $token ? $token : new WP_Error( 'nros_vi_api', 'টোকেন নেই — আবার সংযোগ দিন।', array( 'auth' => true ) );
		}
		$exp = $acc->token_expires ? strtotime( $acc->token_expires . ' UTC' ) : 0;
		if ( $token && $exp > time() + 60 ) {
			return $token;
		}
		$refresh = $acc->refresh_enc ? NROS_Core::decrypt( $acc->refresh_enc ) : '';
		if ( ! $refresh ) {
			return new WP_Error( 'nros_vi_api', 'রিফ্রেশ টোকেন নেই — আবার সংযোগ দিন।', array( 'auth' => true ) );
		}
		if ( 'youtube' === $acc->platform ) {
			$a = self::app( 'google' );
			$r = self::http(
				'POST',
				'https://oauth2.googleapis.com/token',
				array(
					'body' => array(
						'client_id'     => $a['id'],
						'client_secret' => $a['secret'],
						'refresh_token' => $refresh,
						'grant_type'    => 'refresh_token',
					),
				)
			);
		} else {
			$a = self::app( 'tiktok' );
			$r = self::http(
				'POST',
				'https://open.tiktokapis.com/v2/oauth/token/',
				array(
					'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
					'body'    => array(
						'client_key'    => $a['id'],
						'client_secret' => $a['secret'],
						'grant_type'    => 'refresh_token',
						'refresh_token' => $refresh,
					),
				)
			);
		}
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		if ( empty( $r['access_token'] ) ) {
			return new WP_Error( 'nros_vi_api', 'টোকেন নবায়ন হয়নি।', array( 'auth' => true ) );
		}
		$fields = array(
			'token_enc'     => NROS_Core::encrypt( (string) $r['access_token'] ),
			'token_expires' => gmdate( 'Y-m-d H:i:s', time() + ( isset( $r['expires_in'] ) ? (int) $r['expires_in'] : 3600 ) - 60 ),
		);
		// TikTok rotates the refresh token; Google usually keeps the old one.
		if ( ! empty( $r['refresh_token'] ) ) {
			$fields['refresh_enc'] = NROS_Core::encrypt( (string) $r['refresh_token'] );
		}
		if ( ! empty( $r['refresh_expires_in'] ) ) {
			$fields['refresh_expires'] = gmdate( 'Y-m-d H:i:s', time() + (int) $r['refresh_expires_in'] );
		}
		NROS_VI::update_account( (int) $acc->id, $fields );
		return (string) $r['access_token'];
	}

	// ---------------------------------------------------------------------
	// Sync (one account)
	// ---------------------------------------------------------------------

	/**
	 * Pulls the latest numbers for an account (and a slice of history while
	 * the backfill is not finished).
	 *
	 * @param object $acc      Account row.
	 * @param int    $chunks   How many history slices to fetch this run.
	 * @return true|WP_Error
	 */
	public static function sync( $acc, $chunks = 3 ) {
		$token = self::token( $acc );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		if ( 'facebook' === $acc->platform ) {
			return self::sync_facebook( $acc, $token, $chunks );
		}
		if ( 'youtube' === $acc->platform ) {
			return self::sync_youtube( $acc, $token, $chunks );
		}
		if ( 'tiktok' === $acc->platform ) {
			return self::sync_tiktok( $acc, $token );
		}
		return new WP_Error( 'nros_vi_platform', 'অজানা প্ল্যাটফর্ম' );
	}

	/** Y-m-d $n days before today (site time). */
	protected static function day_ago( $n ) {
		return wp_date( 'Y-m-d', time() - (int) $n * DAY_IN_SECONDS );
	}

	/**
	 * Facebook Page: page node (followers now), daily insights, recent posts.
	 */
	protected static function sync_facebook( $acc, $token, $chunks ) {
		$node = self::http( 'GET', self::graph( $acc->ext_id, array( 'fields' => 'name,link,followers_count,fan_count,picture{url}', 'access_token' => $token ) ) );
		if ( is_wp_error( $node ) ) {
			return $node;
		}
		$followers = isset( $node['followers_count'] ) ? (int) $node['followers_count'] : ( isset( $node['fan_count'] ) ? (int) $node['fan_count'] : null );
		NROS_VI::update_account(
			(int) $acc->id,
			array(
				'name'      => isset( $node['name'] ) ? (string) $node['name'] : $acc->name,
				'avatar'    => isset( $node['picture']['data']['url'] ) ? (string) $node['picture']['data']['url'] : $acc->avatar,
				'followers' => $followers,
			)
		);
		if ( null !== $followers ) {
			NROS_VI::put_day( (int) $acc->id, wp_date( 'Y-m-d' ), array( 'followers' => $followers ), 'snapshot' );
		}

		// Recent days every run (Facebook revises the last few days).
		$warn   = array();
		$recent = self::facebook_window( $acc, $token, self::day_ago( 6 ), wp_date( 'Y-m-d' ), $warn );
		if ( is_wp_error( $recent ) ) {
			return $recent;
		}

		// History, newest first, until 2 years back.
		$target = self::day_ago( self::FB_BACK_DAYS );
		$cursor = $acc->backfill_until ? $acc->backfill_until : self::day_ago( 7 );
		for ( $i = 0; $i < $chunks && $cursor > $target; $i++ ) {
			$from = max( $target, wp_date( 'Y-m-d', strtotime( $cursor . ' 12:00 UTC' ) - ( self::FB_CHUNK - 1 ) * DAY_IN_SECONDS ) );
			$r    = self::facebook_window( $acc, $token, $from, $cursor, $warn );
			if ( is_wp_error( $r ) ) {
				break;
			}
			$cursor = wp_date( 'Y-m-d', strtotime( $from . ' 12:00 UTC' ) - DAY_IN_SECONDS );
		}
		NROS_VI::update_account( (int) $acc->id, array( 'backfill_until' => $cursor <= $target ? $target : $cursor, 'backfill_done' => $cursor <= $target ? 1 : 0, 'warning' => $warn ? 'এই মেট্রিক ফেসবুক এখন দেয় না (বাকিগুলো চলছে): ' . implode( ', ', array_unique( $warn ) ) : '' ) );

		self::facebook_posts( $acc, $token );
		return true;
	}

	/**
	 * One since/until window of page insights → daily rows.
	 *
	 * @param object $acc   Account.
	 * @param string $token Page token.
	 * @param string $from  Y-m-d.
	 * @param string $to    Y-m-d.
	 * @param array  $warn  Collected metric warnings.
	 * @return true|WP_Error
	 */
	protected static function facebook_window( $acc, $token, $from, $to, &$warn ) {
		$ok    = false;
		$first = null;
		foreach ( self::FB_METRICS as $metric => $col ) {
			$r = self::http(
				'GET',
				self::graph(
					$acc->ext_id . '/insights',
					array(
						'metric'       => $metric,
						'period'       => 'day',
						'since'        => $from,
						// until is exclusive.
						'until'        => wp_date( 'Y-m-d', strtotime( $to . ' 12:00 UTC' ) + DAY_IN_SECONDS ),
						'access_token' => $token,
					)
				)
			);
			if ( is_wp_error( $r ) ) {
				$d = $r->get_error_data();
				if ( ! empty( $d['auth'] ) ) {
					return $r;
				}
				$first  = $first ? $first : $r;
				$warn[] = $metric;
				continue;
			}
			$ok = true;
			foreach ( (array) ( isset( $r['data'] ) ? $r['data'] : array() ) as $series ) {
				foreach ( (array) ( isset( $series['values'] ) ? $series['values'] : array() ) as $v ) {
					if ( ! isset( $v['end_time'] ) || ! isset( $v['value'] ) || ! is_numeric( $v['value'] ) ) {
						continue;
					}
					// end_time is the END of the day (midnight US-Pacific) → the day before.
					$day = gmdate( 'Y-m-d', strtotime( (string) $v['end_time'] ) - 12 * HOUR_IN_SECONDS );
					NROS_VI::put_day( (int) $acc->id, $day, array( $col => (int) $v['value'] ), 'api' );
				}
			}
		}
		return $ok ? true : ( $first ? $first : new WP_Error( 'nros_vi_api', 'কোনো মেট্রিক পাওয়া যায়নি।' ) );
	}

	/** Recent Page posts with views where the API gives them (top content). */
	protected static function facebook_posts( $acc, $token ) {
		$r = self::http( 'GET', self::graph( $acc->ext_id . '/posts', array( 'fields' => 'id,message,permalink_url,created_time,full_picture,insights.metric(post_media_view)', 'limit' => '50', 'access_token' => $token ) ) );
		if ( is_wp_error( $r ) ) {
			// Older/limited apps: list without insights so at least the posts show.
			$r = self::http( 'GET', self::graph( $acc->ext_id . '/posts', array( 'fields' => 'id,message,permalink_url,created_time,full_picture', 'limit' => '50', 'access_token' => $token ) ) );
			if ( is_wp_error( $r ) ) {
				return;
			}
		}
		foreach ( (array) ( isset( $r['data'] ) ? $r['data'] : array() ) as $p ) {
			$views = null;
			foreach ( (array) ( isset( $p['insights']['data'] ) ? $p['insights']['data'] : array() ) as $m ) {
				if ( isset( $m['values'][0]['value'] ) && is_numeric( $m['values'][0]['value'] ) ) {
					$views = (int) $m['values'][0]['value'];
				}
			}
			NROS_VI::put_item(
				(int) $acc->id,
				array(
					'ext_id'       => (string) $p['id'],
					'title'        => isset( $p['message'] ) ? wp_html_excerpt( (string) $p['message'], 140, '…' ) : '',
					'url'          => isset( $p['permalink_url'] ) ? (string) $p['permalink_url'] : '',
					'thumb'        => isset( $p['full_picture'] ) ? (string) $p['full_picture'] : '',
					'published_at' => isset( $p['created_time'] ) ? gmdate( 'Y-m-d H:i:s', strtotime( (string) $p['created_time'] ) ) : null,
					'views'        => $views,
				)
			);
		}
	}

	/**
	 * YouTube channel: statistics (subscribers, lifetime views), Analytics by
	 * day (views, watch minutes, subscribers gained/lost), recent uploads.
	 */
	protected static function sync_youtube( $acc, $token, $chunks ) {
		$auth = array( 'headers' => array( 'Authorization' => 'Bearer ' . $token ) );
		$ch   = self::http( 'GET', 'https://www.googleapis.com/youtube/v3/channels?part=snippet,statistics,contentDetails&id=' . rawurlencode( $acc->ext_id ), $auth );
		if ( is_wp_error( $ch ) ) {
			return $ch;
		}
		$c = isset( $ch['items'][0] ) ? $ch['items'][0] : null;
		if ( ! $c ) {
			return new WP_Error( 'nros_vi_api', 'চ্যানেল পাওয়া যায়নি।' );
		}
		$subs  = isset( $c['statistics']['subscriberCount'] ) ? (int) $c['statistics']['subscriberCount'] : null;
		$meta  = NROS_VI::meta( $acc );
		$meta['uploads'] = isset( $c['contentDetails']['relatedPlaylists']['uploads'] ) ? (string) $c['contentDetails']['relatedPlaylists']['uploads'] : ( isset( $meta['uploads'] ) ? $meta['uploads'] : '' );
		$start = isset( $c['snippet']['publishedAt'] ) ? substr( (string) $c['snippet']['publishedAt'], 0, 10 ) : self::day_ago( 365 );
		NROS_VI::update_account(
			(int) $acc->id,
			array(
				'name'      => isset( $c['snippet']['title'] ) ? (string) $c['snippet']['title'] : $acc->name,
				'avatar'    => isset( $c['snippet']['thumbnails']['default']['url'] ) ? (string) $c['snippet']['thumbnails']['default']['url'] : $acc->avatar,
				'followers' => $subs,
				'lifetime'  => isset( $c['statistics']['viewCount'] ) ? (int) $c['statistics']['viewCount'] : null,
				'meta'      => wp_json_encode( $meta ),
			)
		);
		if ( null !== $subs ) {
			NROS_VI::put_day( (int) $acc->id, wp_date( 'Y-m-d' ), array( 'followers' => $subs ), 'snapshot' );
		}

		// Analytics lags 1–3 days; re-read the last 10 every run.
		$r = self::youtube_window( $acc, $auth, self::day_ago( 10 ), wp_date( 'Y-m-d' ) );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$cursor = $acc->backfill_until ? $acc->backfill_until : self::day_ago( 11 );
		for ( $i = 0; $i < $chunks && $cursor >= $start; $i++ ) {
			$from = max( $start, wp_date( 'Y-m-d', strtotime( $cursor . ' 12:00 UTC' ) - ( self::YT_CHUNK - 1 ) * DAY_IN_SECONDS ) );
			$w    = self::youtube_window( $acc, $auth, $from, $cursor );
			if ( is_wp_error( $w ) ) {
				break;
			}
			$cursor = wp_date( 'Y-m-d', strtotime( $from . ' 12:00 UTC' ) - DAY_IN_SECONDS );
		}
		NROS_VI::update_account( (int) $acc->id, array( 'backfill_until' => $cursor, 'backfill_done' => $cursor < $start ? 1 : 0, 'warning' => '' ) );
		NROS_VI::derive_followers( (int) $acc->id, $subs );
		self::youtube_videos( $acc, $auth, $meta['uploads'] );
		return true;
	}

	/** One Analytics report window → daily rows. */
	protected static function youtube_window( $acc, $auth, $from, $to ) {
		$url = add_query_arg(
			array(
				'ids'       => 'channel==MINE',
				'startDate' => $from,
				'endDate'   => $to,
				'metrics'   => 'views,estimatedMinutesWatched,subscribersGained,subscribersLost,likes,comments,shares',
				'dimensions' => 'day',
				'sort'      => 'day',
			),
			'https://youtubeanalytics.googleapis.com/v2/reports'
		);
		$r = self::http( 'GET', $url, $auth );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$idx = array();
		foreach ( (array) ( isset( $r['columnHeaders'] ) ? $r['columnHeaders'] : array() ) as $i => $h ) {
			$idx[ $h['name'] ] = $i;
		}
		foreach ( (array) ( isset( $r['rows'] ) ? $r['rows'] : array() ) as $row ) {
			$g = function ( $k ) use ( $row, $idx ) {
				return isset( $idx[ $k ] ) && isset( $row[ $idx[ $k ] ] ) ? (int) $row[ $idx[ $k ] ] : 0;
			};
			$day = isset( $idx['day'] ) ? (string) $row[ $idx['day'] ] : '';
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) ) {
				continue;
			}
			NROS_VI::put_day(
				(int) $acc->id,
				$day,
				array(
					'views'         => $g( 'views' ),
					'watch_minutes' => $g( 'estimatedMinutesWatched' ),
					'net_followers' => $g( 'subscribersGained' ) - $g( 'subscribersLost' ),
					'engagement'    => $g( 'likes' ) + $g( 'comments' ) + $g( 'shares' ),
				),
				'api'
			);
		}
		return true;
	}

	/** Last 50 uploads with lifetime views (top content). */
	protected static function youtube_videos( $acc, $auth, $uploads ) {
		if ( ! $uploads ) {
			return;
		}
		$pl = self::http( 'GET', 'https://www.googleapis.com/youtube/v3/playlistItems?part=contentDetails&maxResults=50&playlistId=' . rawurlencode( $uploads ), $auth );
		if ( is_wp_error( $pl ) ) {
			return;
		}
		$ids = array();
		foreach ( (array) ( isset( $pl['items'] ) ? $pl['items'] : array() ) as $it ) {
			if ( ! empty( $it['contentDetails']['videoId'] ) ) {
				$ids[] = (string) $it['contentDetails']['videoId'];
			}
		}
		if ( ! $ids ) {
			return;
		}
		$v = self::http( 'GET', 'https://www.googleapis.com/youtube/v3/videos?part=snippet,statistics&id=' . rawurlencode( implode( ',', $ids ) ), $auth );
		if ( is_wp_error( $v ) ) {
			return;
		}
		foreach ( (array) ( isset( $v['items'] ) ? $v['items'] : array() ) as $it ) {
			NROS_VI::put_item(
				(int) $acc->id,
				array(
					'ext_id'       => (string) $it['id'],
					'title'        => isset( $it['snippet']['title'] ) ? (string) $it['snippet']['title'] : '',
					'url'          => 'https://www.youtube.com/watch?v=' . $it['id'],
					'thumb'        => isset( $it['snippet']['thumbnails']['medium']['url'] ) ? (string) $it['snippet']['thumbnails']['medium']['url'] : '',
					'published_at' => isset( $it['snippet']['publishedAt'] ) ? gmdate( 'Y-m-d H:i:s', strtotime( (string) $it['snippet']['publishedAt'] ) ) : null,
					'views'        => isset( $it['statistics']['viewCount'] ) ? (int) $it['statistics']['viewCount'] : null,
					'likes'        => isset( $it['statistics']['likeCount'] ) ? (int) $it['statistics']['likeCount'] : null,
					'comments'     => isset( $it['statistics']['commentCount'] ) ? (int) $it['statistics']['commentCount'] : null,
				)
			);
		}
	}

	/**
	 * TikTok: only current totals exist. Followers + the sum of every video's
	 * view_count are saved for today; a day's views = today's total minus the
	 * previous saved total (so history starts the day it is connected).
	 */
	protected static function sync_tiktok( $acc, $token ) {
		$info = self::tiktok_user( $token );
		if ( is_wp_error( $info ) ) {
			return $info;
		}
		$total  = 0;
		$likes  = 0;
		$cursor = null;
		$seen   = 0;
		for ( $page = 0; $page < 50; $page++ ) {
			$body = array( 'max_count' => 20 );
			if ( null !== $cursor ) {
				$body['cursor'] = $cursor;
			}
			$r = self::http(
				'POST',
				'https://open.tiktokapis.com/v2/video/list/?fields=id,title,video_description,view_count,like_count,comment_count,share_count,create_time,cover_image_url,share_url',
				array(
					'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ),
					'body'    => wp_json_encode( $body ),
				)
			);
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			foreach ( (array) ( isset( $r['data']['videos'] ) ? $r['data']['videos'] : array() ) as $v ) {
				++$seen;
				$total += isset( $v['view_count'] ) ? (int) $v['view_count'] : 0;
				$likes += isset( $v['like_count'] ) ? (int) $v['like_count'] : 0;
				NROS_VI::put_item(
					(int) $acc->id,
					array(
						'ext_id'       => (string) $v['id'],
						'title'        => wp_html_excerpt( (string) ( ! empty( $v['title'] ) ? $v['title'] : ( isset( $v['video_description'] ) ? $v['video_description'] : '' ) ), 140, '…' ),
						'url'          => isset( $v['share_url'] ) ? (string) $v['share_url'] : '',
						'thumb'        => isset( $v['cover_image_url'] ) ? (string) $v['cover_image_url'] : '',
						'published_at' => isset( $v['create_time'] ) ? gmdate( 'Y-m-d H:i:s', (int) $v['create_time'] ) : null,
						'views'        => isset( $v['view_count'] ) ? (int) $v['view_count'] : null,
						'likes'        => isset( $v['like_count'] ) ? (int) $v['like_count'] : null,
						'comments'     => isset( $v['comment_count'] ) ? (int) $v['comment_count'] : null,
					)
				);
			}
			if ( empty( $r['data']['has_more'] ) ) {
				break;
			}
			$cursor = isset( $r['data']['cursor'] ) ? $r['data']['cursor'] : null;
		}
		$followers = isset( $info['follower_count'] ) ? (int) $info['follower_count'] : null;
		NROS_VI::update_account(
			(int) $acc->id,
			array(
				'name'      => isset( $info['display_name'] ) ? (string) $info['display_name'] : $acc->name,
				'avatar'    => isset( $info['avatar_url'] ) ? (string) $info['avatar_url'] : $acc->avatar,
				'followers' => $followers,
				'lifetime'  => $total,
				'backfill_done' => 1,
				'warning'   => $page >= 49 ? 'অনেক ভিডিও — প্রথম ১০০০টির ভিউ ধরা হয়েছে' : '',
			)
		);
		NROS_VI::put_snapshot_total( (int) $acc->id, wp_date( 'Y-m-d' ), $total, $followers, $likes );
		return true;
	}

	// ---------------------------------------------------------------------
	// Live right now
	// ---------------------------------------------------------------------

	/**
	 * Live broadcasts of one account with their current viewers.
	 *
	 * @param object $acc Account.
	 * @return array{supported:bool,items:array,error:string}
	 */
	public static function live( $acc ) {
		$out = array( 'supported' => true, 'items' => array(), 'error' => '' );
		if ( 'tiktok' === $acc->platform ) {
			$out['supported'] = false;
			return $out;
		}
		$token = self::token( $acc );
		if ( is_wp_error( $token ) ) {
			$out['error'] = $token->get_error_message();
			return $out;
		}
		if ( 'facebook' === $acc->platform ) {
			$r = self::http( 'GET', self::graph( $acc->ext_id . '/live_videos', array( 'broadcast_status' => '["LIVE"]', 'fields' => 'id,title,live_views,permalink_url,status', 'access_token' => $token ) ) );
			if ( is_wp_error( $r ) ) {
				$out['error'] = $r->get_error_message();
				return $out;
			}
			foreach ( (array) ( isset( $r['data'] ) ? $r['data'] : array() ) as $v ) {
				if ( isset( $v['status'] ) && 'LIVE' !== $v['status'] ) {
					continue;
				}
				$link           = isset( $v['permalink_url'] ) ? (string) $v['permalink_url'] : '';
				$out['items'][] = array(
					'title'   => isset( $v['title'] ) ? (string) $v['title'] : '',
					'viewers' => isset( $v['live_views'] ) ? (int) $v['live_views'] : 0,
					'url'     => $link && 0 !== strpos( $link, 'http' ) ? 'https://www.facebook.com' . $link : $link,
				);
			}
			return $out;
		}
		$auth = array( 'headers' => array( 'Authorization' => 'Bearer ' . $token ) );
		$b    = self::http( 'GET', 'https://www.googleapis.com/youtube/v3/liveBroadcasts?part=id,snippet&broadcastStatus=active&broadcastType=all&maxResults=10', $auth );
		if ( is_wp_error( $b ) ) {
			$out['error'] = $b->get_error_message();
			return $out;
		}
		$ids = array();
		foreach ( (array) ( isset( $b['items'] ) ? $b['items'] : array() ) as $it ) {
			$ids[] = (string) $it['id'];
		}
		if ( ! $ids ) {
			return $out;
		}
		$v = self::http( 'GET', 'https://www.googleapis.com/youtube/v3/videos?part=snippet,liveStreamingDetails&id=' . rawurlencode( implode( ',', $ids ) ), $auth );
		if ( is_wp_error( $v ) ) {
			$out['error'] = $v->get_error_message();
			return $out;
		}
		foreach ( (array) ( isset( $v['items'] ) ? $v['items'] : array() ) as $it ) {
			$out['items'][] = array(
				'title'   => isset( $it['snippet']['title'] ) ? (string) $it['snippet']['title'] : '',
				'viewers' => isset( $it['liveStreamingDetails']['concurrentViewers'] ) ? (int) $it['liveStreamingDetails']['concurrentViewers'] : 0,
				'url'     => 'https://www.youtube.com/watch?v=' . $it['id'],
			);
		}
		return $out;
	}
}
