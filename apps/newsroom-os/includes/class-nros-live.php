<?php
/**
 * NROS_Live — 1.10.0: live posts (লাইভ ইভেন্ট / অপরাধ তদন্ত লাইভ) in the publisher and correspondent apps.
 *
 * The model, sanitising and rendering live in the Meeha News theme (inc/liveblog): the live configuration is the
 * `_meeha_live` meta of an ordinary post, every timestamped update is a hidden `meeha_live_entry` child post. This
 * class only bridges the apps' JWT users to the theme's meeha_lb_* functions and decides who may do what:
 *
 *   Staff (Editor / Publisher / Administrator): start / stop live on any post, edit the scoreboard / case file,
 *   assign correspondents, add / edit / pin / delete / approve any update.
 *   An assigned correspondent: sees the live posts assigned to them, adds updates (published at once, or waiting
 *   for approval when the post is set to "যাচাই করে প্রকাশ"), edits / deletes only their own updates.
 *
 * Routes:
 *   GET    /live                              live posts (staff: all; correspondent: assigned)
 *   GET    /live/{id}                         one live post: config + updates + who can do what
 *   POST   /live/{id}/entries                 add an update
 *   PATCH  /live/{id}/entries/{eid}           edit (staff: also approve / pin / kind / time)
 *   DELETE /live/{id}/entries/{eid}           delete (to the trash)
 *   POST   /admin/live/{id}                   staff: switch live on / change the configuration
 *   DELETE /admin/live/{id}                   staff: make it an ordinary post again (updates are kept, hidden)
 *   GET    /admin/live-candidates?search=     staff: recent published posts that can be made live
 *
 * @package NewsroomOS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NROS_Live {

	/**
	 * Registers routes.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Registers REST routes under nros/v1.
	 */
	public static function register_routes() {
		$app   = array( 'NROS_Core', 'require_app_user' );
		$staff = array( 'NROS_Core', 'require_staff' );
		register_rest_route( NROS_API_NS, '/live', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'list_live' ), 'permission_callback' => $app ) );
		register_rest_route( NROS_API_NS, '/live/(?P<id>\d+)', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_one' ), 'permission_callback' => $app ) );
		register_rest_route( NROS_API_NS, '/live/(?P<id>\d+)/entries', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'add_entry' ), 'permission_callback' => $app ) );
		register_rest_route(
			NROS_API_NS,
			'/live/(?P<id>\d+)/entries/(?P<eid>\d+)',
			array(
				array( 'methods' => 'PATCH', 'callback' => array( __CLASS__, 'edit_entry' ), 'permission_callback' => $app ),
				array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'delete_entry' ), 'permission_callback' => $app ),
			)
		);
		register_rest_route(
			NROS_API_NS,
			'/admin/live/(?P<id>\d+)',
			array(
				array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'save_config' ), 'permission_callback' => $staff ),
				array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'disable' ), 'permission_callback' => $staff ),
			)
		);
		register_rest_route( NROS_API_NS, '/admin/live-candidates', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'candidates' ), 'permission_callback' => $staff ) );
	}

	/**
	 * Error when the theme's live module is missing (older theme).
	 *
	 * @return WP_Error|null
	 */
	protected static function unavailable() {
		if ( function_exists( 'meeha_lb_save_config' ) && post_type_exists( 'meeha_live_entry' ) ) {
			return null;
		}
		return new WP_Error( 'nros_live_unavailable', 'লাইভ পোস্টের জন্য Meeha News থিম ২.৪.৬ বা নতুন সংস্করণ লাগবে।', array( 'status' => 501 ) );
	}

	/**
	 * Current app user.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_User|null
	 */
	protected static function user( $req ) {
		$u = NROS_Auth::get_user_from_request( $req );
		return ( $u && ! is_wp_error( $u ) ) ? $u : null;
	}

	/**
	 * Staff?
	 *
	 * @param WP_User $u User.
	 * @return bool
	 */
	protected static function is_staff( $u ) {
		return $u && user_can( $u, 'edit_others_posts' );
	}

	/**
	 * The live post + its config, checking that this user may open it.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return array|WP_Error [post, cfg, user, staff]
	 */
	protected static function load( $req ) {
		$err = self::unavailable();
		if ( $err ) {
			return $err;
		}
		$u    = self::user( $req );
		$post = get_post( (int) $req['id'] );
		if ( ! $u || ! $post || 'post' !== $post->post_type ) {
			return new WP_Error( 'nros_live_missing', 'লাইভ পোস্টটি পাওয়া যায়নি।', array( 'status' => 404 ) );
		}
		$cfg   = meeha_lb_config( $post );
		$staff = self::is_staff( $u );
		if ( ! $cfg ) {
			return new WP_Error( 'nros_live_off', 'এই পোস্টে লাইভ চালু নেই।', array( 'status' => 404 ) );
		}
		if ( ! $staff && ! in_array( (int) $u->ID, $cfg['reporters'], true ) ) {
			return new WP_Error( 'nros_forbidden', 'এই লাইভ পোস্টে আপনাকে যুক্ত করা হয়নি।', array( 'status' => 403 ) );
		}
		return array( $post, $cfg, $u, $staff );
	}

	/**
	 * Card row for lists.
	 *
	 * @param WP_Post $p   Post.
	 * @param array   $cfg Config.
	 * @return array
	 */
	protected static function row( $p, $cfg ) {
		global $wpdb;
		$counts = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT post_status, COUNT(*) AS n FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'meeha_live_entry' AND post_status IN ('publish','pending') GROUP BY post_status", $p->ID ),
			OBJECT_K
		);
		return array(
			'id'        => (int) $p->ID,
			'title'     => html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ),
			'link'      => get_permalink( $p ),
			'post_status' => $p->post_status,
			'type'      => $cfg['type'],
			'status'    => $cfg['status'],
			'started'   => (int) $cfg['started'],
			'last'      => (int) get_post_meta( $p->ID, '_meeha_live_last', true ),
			'count'     => isset( $counts['publish'] ) ? (int) $counts['publish']->n : 0,
			'pending'   => isset( $counts['pending'] ) ? (int) $counts['pending']->n : 0,
			'thumb'     => (string) get_the_post_thumbnail_url( $p, 'thumbnail' ),
			'reporters' => count( $cfg['reporters'] ),
		);
	}

	/**
	 * GET /live
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function list_live( $req ) {
		$err = self::unavailable();
		if ( $err ) {
			return $err;
		}
		$u     = self::user( $req );
		$staff = self::is_staff( $u );
		$q     = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => array( 'publish', 'future', 'draft', 'pending' ),
				'posts_per_page' => 100,
				'meta_key'       => '_meeha_live_status', // phpcs:ignore WordPress.DB.SlowDBQuery
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);
		$items = array();
		foreach ( $q as $p ) {
			$cfg = meeha_lb_config( $p );
			if ( ! $cfg ) {
				continue;
			}
			if ( ! $staff && ( ! in_array( (int) $u->ID, $cfg['reporters'], true ) || 'publish' !== $p->post_status ) ) {
				continue;
			}
			$items[] = self::row( $p, $cfg );
		}
		// Running ones first.
		usort(
			$items,
			function ( $a, $b ) {
				$o = array( 'live' => 0, 'paused' => 1, 'ended' => 2 );
				return ( $o[ $a['status'] ] - $o[ $b['status'] ] ) ?: ( $b['last'] - $a['last'] );
			}
		);
		return rest_ensure_response( array( 'items' => $items ) );
	}

	/**
	 * One update for the apps.
	 *
	 * @param WP_Post $e     Update.
	 * @param WP_User $u     Viewer.
	 * @param bool    $staff Viewer is staff.
	 * @return array
	 */
	protected static function entry_out( $e, $u, $staff ) {
		$own = (int) $e->post_author === (int) $u->ID;
		return array(
			'id'       => (int) $e->ID,
			'title'    => $e->post_title,
			'content'  => $e->post_content,
			'kind'     => meeha_lb_entry_kind( $e ),
			'pinned'   => (bool) get_post_meta( $e->ID, '_lb_pinned', true ),
			'source'   => (string) get_post_meta( $e->ID, '_lb_source', true ),
			'status'   => $e->post_status,
			'time'     => (int) get_post_time( 'U', true, $e ),
			'modified' => (int) get_post_modified_time( 'U', true, $e ),
			'author'   => array(
				'id'   => (int) $e->post_author,
				'name' => get_the_author_meta( 'display_name', (int) $e->post_author ),
			),
			'can_edit' => $staff || $own,
		);
	}

	/**
	 * Full payload of a live post.
	 *
	 * @param WP_Post $post  Post.
	 * @param array   $cfg   Config.
	 * @param WP_User $u     Viewer.
	 * @param bool    $staff Staff.
	 * @return array
	 */
	protected static function payload( $post, $cfg, $u, $staff ) {
		$entries = array();
		foreach ( meeha_lb_entries( $post->ID, array( 'status' => 'any', 'limit' => 300 ) ) as $e ) {
			if ( 'pending' === $e->post_status && ! $staff && (int) $e->post_author !== (int) $u->ID ) {
				continue;
			}
			$entries[] = self::entry_out( $e, $u, $staff );
		}
		$reporters = array();
		foreach ( $cfg['reporters'] as $rid ) {
			$ru = get_userdata( $rid );
			if ( $ru ) {
				$reporters[] = array(
					'id'   => (int) $rid,
					'name' => $ru->display_name,
				);
			}
		}
		$photos = array();
		foreach ( array_merge( $cfg['case']['victims'], $cfg['case']['people'] ) as $x ) {
			if ( ! empty( $x['photo'] ) ) {
				$photos[ (int) $x['photo'] ] = (string) wp_get_attachment_image_url( (int) $x['photo'], 'thumbnail' );
			}
		}
		$row = self::row( $post, $cfg );
		return array(
			'post'       => $row,
			'config'     => $cfg,
			'entries'    => $entries,
			'reporters'  => $reporters,
			'photos'     => (object) $photos,
			'can_manage' => $staff,
			'me'         => (int) $u->ID,
			'kinds'      => array_map(
				function ( $k, $v ) {
					return array( 'id' => $k, 'label' => $v[0], 'for' => $v[1] );
				},
				array_keys( meeha_lb_kinds() ),
				meeha_lb_kinds()
			),
			'default_disclaimer' => meeha_lb_default_disclaimer(),
		);
	}

	/**
	 * GET /live/{id}
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_one( $req ) {
		$l = self::load( $req );
		if ( is_wp_error( $l ) ) {
			return $l;
		}
		list( $post, $cfg, $u, $staff ) = $l;
		return rest_ensure_response( self::payload( $post, $cfg, $u, $staff ) );
	}

	/**
	 * POST /live/{id}/entries
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function add_entry( $req ) {
		$l = self::load( $req );
		if ( is_wp_error( $l ) ) {
			return $l;
		}
		list( $post, $cfg, $u, $staff ) = $l;
		if ( 'ended' === $cfg['status'] && ! $staff ) {
			return new WP_Error( 'nros_live_ended', 'এই লাইভ শেষ হয়ে গেছে।', array( 'status' => 409 ) );
		}
		$raw = (array) $req->get_json_params();
		if ( ! $staff ) {
			unset( $raw['pinned'], $raw['time'] );
			$raw['status'] = $cfg['moderate'] ? 'pending' : 'publish';
		} else {
			$raw['status'] = 'publish';
		}
		$id = meeha_lb_insert_entry( $post->ID, $raw, $u->ID );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		if ( ! $staff && $cfg['moderate'] && class_exists( 'NROS_Notifications' ) ) {
			NROS_Notifications::add( NROS_Notifications::staff_ids(), 'live_pending', 'লাইভ আপডেট অনুমোদনের অপেক্ষায়', get_the_title( $post ) . ' — ' . $u->display_name, '/app/live/' . $post->ID, $post->ID );
		}
		return rest_ensure_response( self::entry_out( get_post( $id ), $u, $staff ) );
	}

	/**
	 * The update, checking it belongs to the live post and this user may change it.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return array|WP_Error [post, cfg, user, staff, entry]
	 */
	protected static function load_entry( $req ) {
		$l = self::load( $req );
		if ( is_wp_error( $l ) ) {
			return $l;
		}
		$e = get_post( (int) $req['eid'] );
		if ( ! $e || 'meeha_live_entry' !== $e->post_type || (int) $e->post_parent !== (int) $l[0]->ID || 'trash' === $e->post_status ) {
			return new WP_Error( 'nros_live_missing', 'আপডেটটি পাওয়া যায়নি।', array( 'status' => 404 ) );
		}
		if ( ! $l[3] && (int) $e->post_author !== (int) $l[2]->ID ) {
			return new WP_Error( 'nros_forbidden', 'শুধু নিজের আপডেট বদলাতে পারবেন।', array( 'status' => 403 ) );
		}
		$l[] = $e;
		return $l;
	}

	/**
	 * PATCH /live/{id}/entries/{eid}
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function edit_entry( $req ) {
		$l = self::load_entry( $req );
		if ( is_wp_error( $l ) ) {
			return $l;
		}
		list( $post, $cfg, $u, $staff, $e ) = $l;
		$raw = (array) $req->get_json_params();
		if ( ! $staff ) {
			unset( $raw['pinned'], $raw['time'], $raw['status'] );
		}
		$r = meeha_lb_update_entry( $e->ID, $raw );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		return rest_ensure_response( self::entry_out( get_post( $e->ID ), $u, $staff ) );
	}

	/**
	 * DELETE /live/{id}/entries/{eid}
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_entry( $req ) {
		$l = self::load_entry( $req );
		if ( is_wp_error( $l ) ) {
			return $l;
		}
		meeha_lb_delete_entry( $l[4]->ID );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * POST /admin/live/{id} — switch on / change the configuration.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function save_config( $req ) {
		$err = self::unavailable();
		if ( $err ) {
			return $err;
		}
		$u    = self::user( $req );
		$post = get_post( (int) $req['id'] );
		if ( ! $post || 'post' !== $post->post_type ) {
			return new WP_Error( 'nros_live_missing', 'পোস্টটি পাওয়া যায়নি।', array( 'status' => 404 ) );
		}
		$before  = meeha_lb_config( $post );
		$changes = (array) $req->get_json_params();
		$allowed = array( 'type', 'status', 'reporters', 'moderate', 'facts', 'board', 'case' );
		$changes = array_intersect_key( $changes, array_flip( $allowed ) );
		if ( isset( $changes['reporters'] ) ) {
			// Only real correspondents can be assigned.
			$changes['reporters'] = array_values(
				array_filter(
					array_map( 'absint', (array) $changes['reporters'] ),
					function ( $id ) {
						$ru = get_userdata( $id );
						return $ru && ( in_array( 'correspondent', (array) $ru->roles, true ) || user_can( $ru, 'edit_others_posts' ) );
					}
				)
			);
		}
		$cfg = meeha_lb_save_config( $post->ID, $changes );

		$old_rep = $before ? $before['reporters'] : array();
		$new_rep = array_values( array_diff( $cfg['reporters'], $old_rep ) );
		if ( $new_rep && class_exists( 'NROS_Notifications' ) ) {
			NROS_Notifications::add( $new_rep, 'live_assigned', 'আপনাকে একটি লাইভ পোস্টে যুক্ত করা হয়েছে', get_the_title( $post ), '/app/live/' . $post->ID, $post->ID );
		}
		return rest_ensure_response( self::payload( get_post( $post->ID ), $cfg, $u, true ) );
	}

	/**
	 * DELETE /admin/live/{id}
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function disable( $req ) {
		$err = self::unavailable();
		if ( $err ) {
			return $err;
		}
		meeha_lb_disable( (int) $req['id'] );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * GET /admin/live-candidates?search=
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public static function candidates( $req ) {
		$args = array(
			'post_type'      => 'post',
			'post_status'    => array( 'publish', 'draft', 'future' ),
			'posts_per_page' => 20,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		);
		$s    = sanitize_text_field( (string) $req->get_param( 'search' ) );
		if ( '' !== $s ) {
			if ( ctype_digit( $s ) ) {
				$args['p'] = (int) $s;
			} else {
				$args['s'] = $s;
			}
		}
		$out = array();
		foreach ( get_posts( $args ) as $p ) {
			$out[] = array(
				'id'      => (int) $p->ID,
				'title'   => html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ),
				'status'  => $p->post_status,
				'date'    => get_post_time( 'U', true, $p ),
				'thumb'   => (string) get_the_post_thumbnail_url( $p, 'thumbnail' ),
				'is_live' => '' !== (string) get_post_meta( $p->ID, '_meeha_live_status', true ),
			);
		}
		return rest_ensure_response( array( 'items' => $out ) );
	}
}
