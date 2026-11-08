<?php
/**
 * NROS_PD_Feeds — 1.15.0: প্রোফাইল ডেস্কের অ্যালার্ট (নতুন পোস্ট/ভিডিওর সংখ্যা).
 *
 * A person ticks "পোস্ট" and/or "ভিডিও" on a profile's link; the desk then
 * shows how many new items came since that person last looked, as a number
 * on the platform's icon.
 *
 * What can be checked automatically (public, free, no login):
 *   - YouTube: the channel's public feed (youtube.com/feeds/videos.xml?channel_id=…)
 *   - Telegram public channels: the public web preview (t.me/s/<name>)
 *   - Websites / blogs with an RSS or Atom feed
 * What can not: Facebook, X, Instagram, TikTok, LinkedIn give no such
 * public feed to outside tools, and scraping them is against their rules and
 * gets the server blocked. For those, the tick puts the link on the
 * person's daily "আজ দেখা বাকি" list instead (opened → counted as seen).
 *
 * Checks run from WP-Cron every 15 minutes (only links someone watches),
 * so on a quiet site they run when the next visitor arrives.
 *
 * @package NewsroomOS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NROS_PD_Feeds {

	const AUTO_PLATFORMS = array( 'youtube', 'telegram', 'website' );

	/** Links checked per cron run. */
	const PER_RUN = 25;

	/** A link is not checked again sooner than this. */
	const MIN_GAP = 840;

	/** Items older than this are pruned. */
	const KEEP_DAYS = 45;

	const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';

	/**
	 * Registers hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		add_action( 'nros_pd_feeds', array( __CLASS__, 'cron' ) );
		add_action(
			'init',
			function () {
				if ( ! wp_next_scheduled( 'nros_pd_feeds' ) ) {
					wp_schedule_event( time() + 120, 'nros_15min', 'nros_pd_feeds' );
				}
			}
		);
	}

	/**
	 * Adds the 15-minute schedule.
	 *
	 * @param array $s Schedules.
	 * @return array
	 */
	public static function schedules( $s ) {
		$s['nros_15min'] = array(
			'interval' => 900,
			'display'  => 'Every 15 minutes (Newsroom OS)',
		);
		return $s;
	}

	/**
	 * Which feed (if any) a link has.
	 *
	 * @param string $platform Platform.
	 * @param string $url      URL.
	 * @return array {kind, url, state}
	 */
	public static function feed_for( $platform, $url ) {
		$n = NROS_Profile_Desk::norm_url( $url );
		if ( 'youtube' === $platform ) {
			if ( preg_match( '#/channel/(UC[A-Za-z0-9_-]{22})#i', $url, $m ) ) {
				return array( 'kind' => 'youtube', 'url' => 'https://www.youtube.com/feeds/videos.xml?channel_id=' . $m[1], 'state' => 'ready' );
			}
			return array( 'kind' => 'youtube', 'url' => '', 'state' => 'resolve' );
		}
		if ( 'telegram' === $platform ) {
			$parts = explode( '/', $n );
			$name  = isset( $parts[1] ) ? $parts[1] : '';
			if ( 's' === $name && isset( $parts[2] ) ) {
				$name = $parts[2];
			}
			if ( preg_match( '/^[A-Za-z][A-Za-z0-9_]{3,64}$/', $name ) && 'joinchat' !== strtolower( $name ) ) {
				return array( 'kind' => 'telegram', 'url' => 'https://t.me/s/' . $name, 'state' => 'ready' );
			}
			return array( 'kind' => '', 'url' => '', 'state' => 'private' );
		}
		if ( 'website' === $platform ) {
			return array( 'kind' => 'rss', 'url' => '', 'state' => 'resolve' );
		}
		return array( 'kind' => '', 'url' => '', 'state' => '' );
	}

	// ---------------------------------------------------------------------
	// Per-user state
	// ---------------------------------------------------------------------

	/**
	 * Viewer's alert state for many links.
	 *
	 * @param int[]   $ids  Link ids.
	 * @param WP_User $user Viewer.
	 * @return array<int, array>
	 */
	public static function state_for_links( $ids, $user ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
		if ( ! $ids || ! ( $user instanceof WP_User ) ) {
			return array();
		}
		$tw    = NROS_Profile_Desk::t( 'watch' );
		$ti    = NROS_Profile_Desk::t( 'feed_items' );
		$in    = implode( ',', $ids );
		$today = current_time( 'Y-m-d' ) . ' 00:00:00';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT w.link_id, w.posts, w.videos, w.seen_at, SUM(CASE WHEN i.kind <> 'video' THEN 1 ELSE 0 END) AS up, SUM(CASE WHEN i.kind = 'video' THEN 1 ELSE 0 END) AS uv FROM {$tw} w LEFT JOIN {$ti} i ON i.link_id = w.link_id AND i.published_at > w.seen_at WHERE w.user_id = %d AND w.link_id IN ({$in}) GROUP BY w.link_id, w.posts, w.videos, w.seen_at", (int) $user->ID ), ARRAY_A );
		$out  = array();
		foreach ( $rows as $r ) {
			$out[ (int) $r['link_id'] ] = array(
				'posts'         => (int) $r['posts'],
				'videos'        => (int) $r['videos'],
				'seen_at'       => $r['seen_at'],
				'unseen_posts'  => (int) $r['posts'] ? (int) $r['up'] : 0,
				'unseen_videos' => (int) $r['videos'] ? (int) $r['uv'] : 0,
				'checked_today' => $r['seen_at'] >= $today,
			);
		}
		return $out;
	}

	/**
	 * Total new items for a user (sidebar badge).
	 *
	 * @param WP_User $user User.
	 * @return int
	 */
	public static function unseen_total( $user ) {
		global $wpdb;
		if ( ! ( $user instanceof WP_User ) ) {
			return 0;
		}
		$tw = NROS_Profile_Desk::t( 'watch' );
		$ti = NROS_Profile_Desk::t( 'feed_items' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tw} w INNER JOIN {$ti} i ON i.link_id = w.link_id AND i.published_at > w.seen_at WHERE w.user_id = %d AND ((w.posts = 1 AND i.kind <> 'video') OR (w.videos = 1 AND i.kind = 'video'))", (int) $user->ID ) );
	}

	/**
	 * Latest items of one profile's links.
	 *
	 * @param int $entity_id Entity.
	 * @param int $n         How many.
	 * @return array
	 */
	public static function recent_items_for_entity( $entity_id, $n = 12 ) {
		global $wpdb;
		$tl = NROS_Profile_Desk::t( 'links' );
		$ti = NROS_Profile_Desk::t( 'feed_items' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT i.*, l.platform FROM {$ti} i INNER JOIN {$tl} l ON l.id = i.link_id WHERE l.entity_id = %d ORDER BY i.published_at DESC LIMIT %d", (int) $entity_id, (int) $n ), ARRAY_A );
		return array_map( array( __CLASS__, 'shape_item' ), $rows );
	}

	/**
	 * Shapes one feed item.
	 *
	 * @param array $r Row.
	 * @return array
	 */
	public static function shape_item( $r ) {
		return array(
			'id'           => (int) $r['id'],
			'link_id'      => (int) $r['link_id'],
			'platform'     => isset( $r['platform'] ) ? $r['platform'] : '',
			'kind'         => $r['kind'],
			'title'        => $r['title'],
			'url'          => $r['url'],
			'published_at' => $r['published_at'],
		);
	}

	/**
	 * Removes a link's items and watches.
	 *
	 * @param int $link_id Link.
	 */
	public static function forget_link( $link_id ) {
		global $wpdb;
		$wpdb->delete( NROS_Profile_Desk::t( 'feed_items' ), array( 'link_id' => (int) $link_id ) );
		$wpdb->delete( NROS_Profile_Desk::t( 'watch' ), array( 'link_id' => (int) $link_id ) );
	}

	// ---------------------------------------------------------------------
	// Routes
	// ---------------------------------------------------------------------

	/**
	 * Registers REST routes.
	 */
	public static function register_routes() {
		$acc = array( 'NROS_Profile_Desk', 'require_access' );
		$ns  = NROS_API_NS;
		register_rest_route( $ns, '/pd/links/(?P<id>\d+)/watch', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'watch' ), 'permission_callback' => $acc ) );
		register_rest_route( $ns, '/pd/links/seen', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'seen' ), 'permission_callback' => $acc ) );
		register_rest_route( $ns, '/pd/alerts', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'alerts' ), 'permission_callback' => $acc ) );
		register_rest_route( $ns, '/pd/alerts/check', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'check_now' ), 'permission_callback' => $acc ) );
	}

	/**
	 * A visible link row or null.
	 *
	 * @param int     $id   Link.
	 * @param WP_User $user User.
	 * @return array|null
	 */
	protected static function link( $id, $user ) {
		global $wpdb;
		$l = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NROS_Profile_Desk::t( 'links' ) . ' WHERE id = %d', (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $l && NROS_Profile_Desk::entity_row( (int) $l['entity_id'], $user ) ? $l : null;
	}

	/**
	 * POST /pd/links/{id}/watch — { posts: bool, videos: bool }. Turning a
	 * tick on starts counting from now; an automatic link is checked at once.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function watch( $request ) {
		global $wpdb;
		$user = NROS_Profile_Desk::me( $request );
		$l    = self::link( (int) $request->get_param( 'id' ), $user );
		if ( ! $l ) {
			return new WP_Error( 'nros_not_found', 'লিংক পাওয়া যায়নি।', array( 'status' => 404 ) );
		}
		$tw     = NROS_Profile_Desk::t( 'watch' );
		$posts  = $request->get_param( 'posts' ) ? 1 : 0;
		$videos = $request->get_param( 'videos' ) ? 1 : 0;
		$now    = current_time( 'mysql' );
		$had    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tw} WHERE user_id = %d AND link_id = %d", (int) $user->ID, (int) $l['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $posts && ! $videos ) {
			$wpdb->delete( $tw, array( 'user_id' => (int) $user->ID, 'link_id' => (int) $l['id'] ) );
		} elseif ( $had ) {
			$wpdb->update( $tw, array( 'posts' => $posts, 'videos' => $videos ), array( 'user_id' => (int) $user->ID, 'link_id' => (int) $l['id'] ) );
		} else {
			$wpdb->insert(
				$tw,
				array(
					'user_id'    => (int) $user->ID,
					'link_id'    => (int) $l['id'],
					'posts'      => $posts,
					'videos'     => $videos,
					// an automatic link counts from now; a manual one goes straight onto today's "দেখা বাকি" list
					'seen_at'    => '' !== $l['feed_kind'] ? $now : '2000-01-01 00:00:00',
					'created_at' => $now,
				)
			);
		}
		if ( ( $posts || $videos ) && '' !== $l['feed_kind'] && ( ! $l['last_checked'] || strtotime( $l['last_checked'] ) < time() - 600 ) ) {
			self::check_link( $l );
		}
		$fresh = self::link( (int) $l['id'], $user );
		$state = self::state_for_links( array( (int) $l['id'] ), $user );
		return new WP_REST_Response( NROS_Profile_Desk::shape_link( $fresh, isset( $state[ (int) $l['id'] ] ) ? $state[ (int) $l['id'] ] : null ), 200 );
	}

	/**
	 * POST /pd/links/seen — { ids: [] } → the viewer has looked at these
	 * links now (opening a link from the app does this).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function seen( $request ) {
		global $wpdb;
		$user = NROS_Profile_Desk::me( $request );
		$tw   = NROS_Profile_Desk::t( 'watch' );
		$now  = current_time( 'mysql' );
		$n    = 0;
		foreach ( array_slice( array_map( 'intval', (array) $request->get_param( 'ids' ) ), 0, 300 ) as $id ) {
			if ( ! self::link( $id, $user ) ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "INSERT INTO {$tw} (user_id, link_id, posts, videos, seen_at, created_at) VALUES (%d, %d, 0, 0, %s, %s) ON DUPLICATE KEY UPDATE seen_at = VALUES(seen_at)", (int) $user->ID, $id, $now, $now ) );
			++$n;
		}
		return new WP_REST_Response( array( 'seen' => $n, 'unseen' => self::unseen_total( $user ) ), 200 );
	}

	/**
	 * GET /pd/alerts — everything the viewer watches: new items per link,
	 * and the manual links not looked at today.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function alerts( $request ) {
		global $wpdb;
		$user = NROS_Profile_Desk::me( $request );
		$tw   = NROS_Profile_Desk::t( 'watch' );
		$tl   = NROS_Profile_Desk::t( 'links' );
		$ti   = NROS_Profile_Desk::t( 'feed_items' );
		$te   = NROS_Profile_Desk::t( 'entities' );
		$vis  = NROS_Profile_Desk::visible_sql( $user );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$links = $wpdb->get_results( $wpdb->prepare( "SELECT l.*, e.name_bn, e.name_en, e.photo_file_id, e.importance, w.seen_at AS w_seen FROM {$tw} w INNER JOIN {$tl} l ON l.id = w.link_id INNER JOIN {$te} e ON e.id = l.entity_id WHERE w.user_id = %d AND (w.posts = 1 OR w.videos = 1) AND {$vis} ORDER BY e.importance DESC, e.name_bn ASC", (int) $user->ID ), ARRAY_A );
		// phpcs:enable
		$state = self::state_for_links( wp_list_pluck( $links, 'id' ), $user );
		$by    = array();
		foreach ( $links as $l ) {
			$eid = (int) $l['entity_id'];
			if ( ! isset( $by[ $eid ] ) ) {
				$by[ $eid ] = array(
					'id'    => $eid,
					'name'  => '' !== $l['name_bn'] ? $l['name_bn'] : $l['name_en'],
					'photo' => (int) $l['photo_file_id'] ? NROS_PD_Files::signed_url( (int) $l['photo_file_id'], (int) $user->ID, 4 * HOUR_IN_SECONDS ) : '',
					'links' => array(),
				);
			}
			$st    = isset( $state[ (int) $l['id'] ] ) ? $state[ (int) $l['id'] ] : null;
			$shape = NROS_Profile_Desk::shape_link( $l, $st );
			$items = array();
			if ( $st && ( $st['unseen_posts'] || $st['unseen_videos'] ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$ti} WHERE link_id = %d AND published_at > %s ORDER BY published_at DESC LIMIT 6", (int) $l['id'], $l['w_seen'] ), ARRAY_A );
				$want  = array();
				foreach ( $items as $it ) {
					if ( ( 'video' === $it['kind'] && $st['videos'] ) || ( 'video' !== $it['kind'] && $st['posts'] ) ) {
						$it['platform'] = $l['platform'];
						$want[]         = self::shape_item( $it );
					}
				}
				$items = $want;
			}
			$shape['new_items']    = $items;
			$by[ $eid ]['links'][] = $shape;
		}
		$last = get_option( 'nros_pd_feeds_last', '' );
		return new WP_REST_Response(
			array(
				'entities'   => array_values( $by ),
				'unseen'     => self::unseen_total( $user ),
				'last_run'   => $last,
				'cron_ok'    => ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) || (bool) wp_next_scheduled( 'nros_pd_feeds' ),
			),
			200
		);
	}

	/**
	 * POST /pd/alerts/check — checks the viewer's automatic links now
	 * (at most once every two minutes per person).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function check_now( $request ) {
		global $wpdb;
		$user = NROS_Profile_Desk::me( $request );
		$key  = 'nros_pd_checknow_' . $user->ID;
		if ( get_transient( $key ) ) {
			return new WP_Error( 'nros_rate', 'একটু আগেই দেখা হয়েছে — দুই মিনিট পর আবার চাপুন।', array( 'status' => 429 ) );
		}
		set_transient( $key, 1, 120 );
		$tw  = NROS_Profile_Desk::t( 'watch' );
		$tl  = NROS_Profile_Desk::t( 'links' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT l.id FROM {$tw} w INNER JOIN {$tl} l ON l.id = w.link_id WHERE w.user_id = %d AND (w.posts = 1 OR w.videos = 1) AND l.feed_kind <> '' ORDER BY l.last_checked ASC LIMIT 15", (int) $user->ID ) );
		$new = 0;
		$start = time();
		foreach ( $ids as $id ) {
			if ( time() - $start > 40 ) {
				break;
			}
			$l = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tl} WHERE id = %d", (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $l ) {
				$new += max( 0, (int) self::check_link( $l ) );
			}
		}
		return new WP_REST_Response( array( 'checked' => count( $ids ), 'new' => $new, 'unseen' => self::unseen_total( $user ) ), 200 );
	}

	// ---------------------------------------------------------------------
	// Checking
	// ---------------------------------------------------------------------

	/**
	 * WP-Cron: checks the watched automatic links that are due, prunes old items.
	 */
	public static function cron() {
		global $wpdb;
		$tl  = NROS_Profile_Desk::t( 'links' );
		$tw  = NROS_Profile_Desk::t( 'watch' );
		$due = gmdate( 'Y-m-d H:i:s', time() + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) - self::MIN_GAP );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT DISTINCT l.* FROM {$tl} l INNER JOIN {$tw} w ON w.link_id = l.id AND (w.posts = 1 OR w.videos = 1) WHERE l.feed_kind <> '' AND (l.last_checked IS NULL OR l.last_checked < %s) ORDER BY l.last_checked ASC LIMIT %d", $due, self::PER_RUN ), ARRAY_A );
		$start = time();
		foreach ( $rows as $l ) {
			if ( time() - $start > 100 ) {
				break;
			}
			self::check_link( $l );
		}
		update_option( 'nros_pd_feeds_last', current_time( 'mysql' ), false );
		$old = gmdate( 'Y-m-d H:i:s', time() - self::KEEP_DAYS * DAY_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . NROS_Profile_Desk::t( 'feed_items' ) . ' WHERE found_at < %s', $old ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Fetches a URL.
	 *
	 * @param string $url URL.
	 * @return array|WP_Error {code, body, type}
	 */
	protected static function fetch( $url ) {
		$res = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 10,
				'redirection'         => 3,
				'limit_response_size' => 3 * MB_IN_BYTES,
				'user-agent'          => self::UA,
				'headers'             => array( 'Accept-Language' => 'bn,en;q=0.8' ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return array(
			'code' => (int) wp_remote_retrieve_response_code( $res ),
			'body' => (string) wp_remote_retrieve_body( $res ),
			'type' => (string) wp_remote_retrieve_header( $res, 'content-type' ),
		);
	}

	/**
	 * Local MySQL time of a feed date.
	 *
	 * @param string $d Date text.
	 * @return string|null
	 */
	protected static function local_time( $d ) {
		$ts = strtotime( (string) $d );
		if ( ! $ts ) {
			return null;
		}
		return get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $ts ) );
	}

	/**
	 * Checks one link: finds its feed when needed, reads it, stores new items.
	 *
	 * @param array $l Link row.
	 * @return int|WP_Error New items.
	 */
	public static function check_link( $l ) {
		global $wpdb;
		$tl  = NROS_Profile_Desk::t( 'links' );
		$upd = array( 'last_checked' => current_time( 'mysql' ) );
		$err = '';

		if ( 'resolve' === $l['feed_state'] || '' === $l['feed_url'] ) {
			$found = 'youtube' === $l['feed_kind'] ? self::resolve_youtube( $l['url'] ) : ( 'rss' === $l['feed_kind'] ? self::resolve_rss( $l['url'] ) : '' );
			if ( is_wp_error( $found ) || '' === $found ) {
				$upd['feed_state'] = 'error';
				$upd['feed_error'] = is_wp_error( $found ) ? mb_substr( $found->get_error_message(), 0, 250 ) : ( 'youtube' === $l['feed_kind'] ? 'চ্যানেল আইডি পাওয়া যায়নি — youtube.com/channel/UC… লিংক দিন।' : 'এই সাইটে RSS ফিড পাওয়া যায়নি।' );
				$wpdb->update( $tl, $upd, array( 'id' => (int) $l['id'] ) );
				return 0;
			}
			$l['feed_url']     = $found;
			$upd['feed_url']   = $found;
			$upd['feed_state'] = 'ready';
		}

		$items = array();
		$got   = self::fetch( $l['feed_url'] );
		if ( is_wp_error( $got ) ) {
			$err = 'সংযোগ হয়নি: ' . $got->get_error_message();
		} elseif ( $got['code'] >= 400 ) {
			$err = 'সাইট উত্তর দিয়েছে HTTP ' . $got['code'];
		} else {
			if ( 'youtube' === $l['feed_kind'] ) {
				$items = self::parse_youtube( $got['body'] );
			} elseif ( 'telegram' === $l['feed_kind'] ) {
				$items = self::parse_telegram( $got['body'], $l['feed_url'] );
			} else {
				$items = self::parse_rss( $got['body'] );
			}
			if ( is_wp_error( $items ) ) {
				$err   = $items->get_error_message();
				$items = array();
			}
		}
		if ( '' !== $err ) {
			$upd['feed_state'] = 'error';
			$upd['feed_error'] = mb_substr( $err, 0, 250 );
			$wpdb->update( $tl, $upd, array( 'id' => (int) $l['id'] ) );
			return 0;
		}
		$upd['feed_state'] = 'ready';
		$upd['feed_error'] = '';
		$wpdb->update( $tl, $upd, array( 'id' => (int) $l['id'] ) );

		$ti  = NROS_Profile_Desk::t( 'feed_items' );
		$now = current_time( 'mysql' );
		$new = array();
		foreach ( array_slice( $items, 0, 40 ) as $it ) {
			if ( empty( $it['key'] ) || empty( $it['published_at'] ) ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$ok = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$ti} (link_id, item_key, kind, title, url, published_at, found_at) VALUES (%d, %s, %s, %s, %s, %s, %s)", (int) $l['id'], mb_substr( $it['key'], 0, 190 ), $it['kind'], mb_substr( (string) $it['title'], 0, 490 ), substr( (string) $it['url'], 0, 500 ), $it['published_at'], $now ) );
			if ( $ok ) {
				$new[] = $it;
			}
		}
		if ( $new ) {
			self::notify( $l, $new );
		}
		return count( $new );
	}

	/**
	 * Bell notifications for the people watching a link (one per link per run).
	 *
	 * @param array $l   Link.
	 * @param array $new New items.
	 */
	protected static function notify( $l, $new ) {
		global $wpdb;
		if ( ! class_exists( 'NROS_Notifications' ) ) {
			return;
		}
		$tw = NROS_Profile_Desk::t( 'watch' );
		$e  = $wpdb->get_row( $wpdb->prepare( 'SELECT name_bn, name_en FROM ' . NROS_Profile_Desk::t( 'entities' ) . ' WHERE id = %d', (int) $l['entity_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $e ) {
			return;
		}
		$name  = '' !== $e['name_bn'] ? $e['name_bn'] : $e['name_en'];
		$plat  = isset( NROS_Profile_Desk::PLATFORMS[ $l['platform'] ] ) ? NROS_Profile_Desk::PLATFORMS[ $l['platform'] ] : $l['platform'];
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, posts, videos, seen_at FROM {$tw} WHERE link_id = %d AND (posts = 1 OR videos = 1)", (int) $l['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$fresh = gmdate( 'Y-m-d H:i:s', time() + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) - 6 * HOUR_IN_SECONDS );
		foreach ( $rows as $w ) {
			$v = 0;
			$p = 0;
			$first = null;
			foreach ( $new as $it ) {
				if ( $it['published_at'] <= $w['seen_at'] || $it['published_at'] < $fresh ) {
					continue;
				}
				if ( 'video' === $it['kind'] && $w['videos'] ) {
					++$v;
				} elseif ( 'video' !== $it['kind'] && $w['posts'] ) {
					++$p;
				} else {
					continue;
				}
				$first = $first ? $first : $it;
			}
			if ( ! $first ) {
				continue;
			}
			$what = $v && ! $p ? NROS_Core::bn_digits( $v ) . 'টি নতুন ভিডিও' : NROS_Core::bn_digits( $v + $p ) . 'টি নতুন পোস্ট';
			NROS_Notifications::add( array( (int) $w['user_id'] ), 'pd_alert', $name . ' — ' . $plat . 'ে ' . $what, mb_substr( (string) $first['title'], 0, 140 ), '/app/profile-desk/' . (int) $l['entity_id'], (int) $l['entity_id'] );
		}
	}

	/**
	 * Channel feed URL of a YouTube channel page (/@handle, /c/…, /user/…).
	 *
	 * @param string $url Channel URL.
	 * @return string|WP_Error
	 */
	public static function resolve_youtube( $url ) {
		if ( preg_match( '#/channel/(UC[A-Za-z0-9_-]{22})#', $url, $m ) ) {
			return 'https://www.youtube.com/feeds/videos.xml?channel_id=' . $m[1];
		}
		$got = self::fetch( $url );
		if ( is_wp_error( $got ) ) {
			return $got;
		}
		foreach ( array( '#<link rel="canonical" href="https://www\.youtube\.com/channel/(UC[A-Za-z0-9_-]{22})"#', '#"externalId":"(UC[A-Za-z0-9_-]{22})"#', '#<meta itemprop="identifier" content="(UC[A-Za-z0-9_-]{22})"#', '#"channelId":"(UC[A-Za-z0-9_-]{22})"#', '#youtube\.com/channel/(UC[A-Za-z0-9_-]{22})#' ) as $re ) {
			if ( preg_match( $re, $got['body'], $m ) ) {
				return 'https://www.youtube.com/feeds/videos.xml?channel_id=' . $m[1];
			}
		}
		return '';
	}

	/**
	 * Feed URL of a website (the page itself when it is a feed, a
	 * <link rel="alternate"> it announces, or WordPress' /feed/).
	 *
	 * @param string $url Site URL.
	 * @return string|WP_Error
	 */
	public static function resolve_rss( $url ) {
		$got = self::fetch( $url );
		if ( is_wp_error( $got ) ) {
			return $got;
		}
		$head = ltrim( substr( $got['body'], 0, 400 ) );
		if ( false !== stripos( $got['type'], 'xml' ) || preg_match( '/^(<\?xml|<rss|<feed)/i', $head ) ) {
			return $url;
		}
		if ( preg_match_all( '#<link\b[^>]*>#i', $got['body'], $tags ) ) {
			foreach ( $tags[0] as $tag ) {
				if ( preg_match( '#type=["\']application/(rss|atom)\+xml["\']#i', $tag ) && preg_match( '#href=["\']([^"\']+)["\']#i', $tag, $h ) ) {
					return WP_Http::make_absolute_url( html_entity_decode( $h[1] ), $url );
				}
			}
		}
		$try = trailingslashit( $url ) . 'feed/';
		$got = self::fetch( $try );
		if ( ! is_wp_error( $got ) && $got['code'] < 400 && preg_match( '/^(<\?xml|<rss|<feed)/i', ltrim( substr( $got['body'], 0, 400 ) ) ) ) {
			return $try;
		}
		return '';
	}

	/**
	 * Safe XML parse (no network, no entities).
	 *
	 * @param string $xml XML.
	 * @return SimpleXMLElement|WP_Error
	 */
	protected static function xml( $xml ) {
		$prev = libxml_use_internal_errors( true );
		$doc  = simplexml_load_string( $xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		if ( false === $doc ) {
			return new WP_Error( 'nros_pd_feed', 'ফিড পড়া যায়নি (ভুল ফরম্যাট)।' );
		}
		return $doc;
	}

	/**
	 * YouTube channel feed → items (all videos).
	 *
	 * @param string $body XML.
	 * @return array|WP_Error
	 */
	public static function parse_youtube( $body ) {
		$doc = self::xml( $body );
		if ( is_wp_error( $doc ) ) {
			return $doc;
		}
		$out = array();
		foreach ( $doc->entry as $e ) {
			$yt   = $e->children( 'http://www.youtube.com/xml/schemas/2015' );
			$vid  = (string) $yt->videoId;
			$link = '';
			foreach ( $e->link as $ln ) {
				$link = (string) $ln['href'];
			}
			$when = self::local_time( (string) $e->published );
			if ( '' === $vid || ! $when ) {
				continue;
			}
			$out[] = array(
				'key'          => 'yt:' . $vid,
				'kind'         => 'video',
				'title'        => (string) $e->title,
				'url'          => '' !== $link ? $link : 'https://www.youtube.com/watch?v=' . $vid,
				'published_at' => $when,
			);
		}
		return $out;
	}

	/**
	 * Telegram public channel preview page (t.me/s/name) → items.
	 *
	 * @param string $body HTML.
	 * @param string $feed Feed URL.
	 * @return array|WP_Error
	 */
	public static function parse_telegram( $body, $feed ) {
		if ( false === strpos( $body, 'tgme_widget_message' ) ) {
			return new WP_Error( 'nros_pd_feed', 'চ্যানেলটি পাবলিক নয়, অথবা টেলিগ্রাম পাতাটি দেখায়নি।' );
		}
		$out    = array();
		$chunks = preg_split( '#(?=<div class="tgme_widget_message_wrap)#', $body );
		foreach ( $chunks as $c ) {
			if ( ! preg_match( '#data-post="([A-Za-z0-9_]+/(\d+))"#', $c, $m ) ) {
				continue;
			}
			if ( ! preg_match( '#<time[^>]*datetime="([^"]+)"#', $c, $t ) ) {
				continue;
			}
			$when = self::local_time( $t[1] );
			if ( ! $when ) {
				continue;
			}
			$text = '';
			if ( preg_match( '#<div class="tgme_widget_message_text[^"]*"[^>]*>(.*?)</div>#s', $c, $x ) ) {
				$text = trim( html_entity_decode( wp_strip_all_tags( str_replace( '<br/>', ' ', $x[1] ) ), ENT_QUOTES, 'UTF-8' ) );
			}
			$video = (bool) preg_match( '#tgme_widget_message_(video|roundvideo)#', $c );
			$out[] = array(
				'key'          => 'tg:' . $m[1],
				'kind'         => $video ? 'video' : 'post',
				'title'        => '' !== $text ? mb_substr( $text, 0, 200 ) : ( $video ? 'ভিডিও' : 'নতুন পোস্ট' ),
				'url'          => 'https://t.me/' . $m[1],
				'published_at' => $when,
			);
		}
		return array_reverse( $out );
	}

	/**
	 * RSS 2.0 / Atom → items.
	 *
	 * @param string $body XML.
	 * @return array|WP_Error
	 */
	public static function parse_rss( $body ) {
		$doc = self::xml( $body );
		if ( is_wp_error( $doc ) ) {
			return $doc;
		}
		$out = array();
		if ( isset( $doc->channel->item ) ) {
			foreach ( $doc->channel->item as $it ) {
				$link  = trim( (string) $it->link );
				$guid  = trim( (string) $it->guid );
				$when  = self::local_time( (string) $it->pubDate );
				$video = false;
				foreach ( $it->enclosure as $enc ) {
					if ( 0 === strpos( (string) $enc['type'], 'video/' ) ) {
						$video = true;
					}
				}
				if ( ! $when || ( '' === $link && '' === $guid ) ) {
					continue;
				}
				$out[] = array(
					'key'          => 'rss:' . md5( '' !== $guid ? $guid : $link ),
					'kind'         => $video ? 'video' : 'post',
					'title'        => trim( html_entity_decode( wp_strip_all_tags( (string) $it->title ), ENT_QUOTES, 'UTF-8' ) ),
					'url'          => '' !== $link ? $link : $guid,
					'published_at' => $when,
				);
			}
		} elseif ( isset( $doc->entry ) ) {
			foreach ( $doc->entry as $e ) {
				$link = '';
				foreach ( $e->link as $ln ) {
					if ( '' === $link || 'alternate' === (string) $ln['rel'] ) {
						$link = (string) $ln['href'];
					}
				}
				$when = self::local_time( '' !== (string) $e->published ? (string) $e->published : (string) $e->updated );
				$id   = '' !== (string) $e->id ? (string) $e->id : $link;
				if ( ! $when || '' === $id ) {
					continue;
				}
				$out[] = array(
					'key'          => 'atom:' . md5( $id ),
					'kind'         => 'post',
					'title'        => trim( wp_strip_all_tags( (string) $e->title ) ),
					'url'          => $link,
					'published_at' => $when,
				);
			}
		}
		return $out;
	}
}
