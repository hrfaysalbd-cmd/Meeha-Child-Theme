<?php
/**
 * NROS_Guest_Authors — round 13: "অতিথি লেখক" (guest author) for articles.
 *
 * A post may carry a guest byline in post meta `_meeha_guest_author`
 * = { name (≤ 80), title (≤ 120), photo_id (image attachment id or 0) }.
 * The theme shows that guest everywhere a byline appears; `post_author`
 * stays the real WordPress user (payments/ledger/ownership unchanged).
 *
 * Guests can be remembered for re-use in the option `nros_guest_authors`
 * (array of { id, name, title, photo_id, updated }, max 200, autoload no),
 * upserted by name.
 *
 * Permissions: reading the saved list is open to all staff; setting /
 * changing / removing a post's guest, saving to and deleting from the
 * list need the same right as changing a post's author
 * (NROS_Core::staff_can( $user, 'change_author' ): Publisher/Admin always,
 * an Editor only when the Publisher allowed it). Like apply_date_author(),
 * a value equal to the stored one is not a change, so an Editor without
 * the right can still save the rest of the form.
 *
 * @package NewsroomOS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NROS_Guest_Authors {

	const META      = '_meeha_guest_author';
	const OPTION    = 'nros_guest_authors';
	const MAX_SAVED = 200;
	const NAME_MAX  = 80;
	const TITLE_MAX = 120;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Routes.
	 */
	public static function register_routes() {
		register_rest_route(
			NROS_API_NS,
			'/admin/guest-authors',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'list_saved' ),
					'permission_callback' => array( 'NROS_Core', 'require_staff' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'create_saved' ),
					'permission_callback' => array( __CLASS__, 'require_manage' ),
				),
			)
		);
		register_rest_route(
			NROS_API_NS,
			'/admin/guest-authors/(?P<id>[A-Za-z0-9]{4,16})',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'delete_saved' ),
				'permission_callback' => array( __CLASS__, 'require_manage' ),
			)
		);
	}

	/**
	 * Staff with the change-author right.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function require_manage( $request ) {
		$ok = NROS_Core::require_staff( $request );
		if ( true !== $ok ) {
			return $ok;
		}
		if ( ! NROS_Core::staff_can( NROS_Auth::get_user_from_request( $request ), 'change_author' ) ) {
			return self::forbidden();
		}
		return true;
	}

	/**
	 * @return WP_Error
	 */
	protected static function forbidden() {
		return new WP_Error( 'nros_forbidden', 'অতিথি লেখক যোগ বা পরিবর্তনের অনুমতি আপনার নেই — লেখক পরিবর্তনের (পাবলিশারের) অনুমতি প্রয়োজন।', array( 'status' => 403 ) );
	}

	// ---------------------------------------------------------------------
	// Values
	// ---------------------------------------------------------------------

	/**
	 * Single-line text, whitespace collapsed, cut to $max characters.
	 *
	 * @param mixed $value Raw.
	 * @param int   $max   Max chars.
	 * @return string
	 */
	protected static function clean_text( $value, $max ) {
		$value = is_scalar( $value ) ? (string) $value : '';
		$value = trim( preg_replace( '/\s+/u', ' ', sanitize_text_field( $value ) ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max ) : substr( $value, 0, $max );
	}

	/**
	 * Validates a raw guest object → { name, title, photo_id } | null (= none) | WP_Error.
	 *
	 * @param mixed   $raw  Raw param.
	 * @param WP_User $user Acting user (photo must be readable by them).
	 * @return array|null|WP_Error
	 */
	public static function normalize( $raw, $user ) {
		if ( null === $raw || false === $raw || '' === $raw || array() === $raw ) {
			return null;
		}
		if ( ! is_array( $raw ) ) {
			return new WP_Error( 'nros_bad_guest', 'অতিথি লেখকের তথ্য সঠিক নয়।', array( 'status' => 400 ) );
		}
		$name_raw  = isset( $raw['name'] ) && is_scalar( $raw['name'] ) ? (string) $raw['name'] : '';
		$title_raw = isset( $raw['title'] ) && is_scalar( $raw['title'] ) ? (string) $raw['title'] : '';
		$len       = function_exists( 'mb_strlen' ) ? 'mb_strlen' : 'strlen';
		if ( $len( trim( sanitize_text_field( $name_raw ) ) ) > self::NAME_MAX ) {
			return new WP_Error( 'nros_too_long', 'অতিথি লেখকের নাম সর্বোচ্চ ৮০ অক্ষর।', array( 'status' => 400 ) );
		}
		if ( $len( trim( sanitize_text_field( $title_raw ) ) ) > self::TITLE_MAX ) {
			return new WP_Error( 'nros_too_long', 'অতিথি লেখকের পদবি সর্বোচ্চ ১২০ অক্ষর।', array( 'status' => 400 ) );
		}
		$name     = self::clean_text( $name_raw, self::NAME_MAX );
		$title    = self::clean_text( $title_raw, self::TITLE_MAX );
		$photo_id = isset( $raw['photo_id'] ) && is_numeric( $raw['photo_id'] ) ? (int) $raw['photo_id'] : 0;

		if ( '' === $name ) {
			if ( '' === $title && $photo_id <= 0 ) {
				return null; // An empty form = no guest.
			}
			return new WP_Error( 'nros_bad_guest', 'অতিথি লেখকের নাম দিন।', array( 'status' => 400 ) );
		}
		if ( $photo_id > 0 ) {
			$att = get_post( $photo_id );
			if ( ! $att || 'attachment' !== $att->post_type || ! wp_attachment_is_image( $photo_id ) || ! user_can( $user, 'read_post', $photo_id ) ) {
				return new WP_Error( 'nros_bad_guest_photo', 'অতিথি লেখকের ছবিটি সঠিক নয় — গ্যালারির একটি ছবি দিন।', array( 'status' => 400 ) );
			}
		} else {
			$photo_id = 0;
		}
		return array(
			'name'     => $name,
			'title'    => $title,
			'photo_id' => $photo_id,
		);
	}

	/**
	 * Stored guest of a post (validated shape) or null.
	 *
	 * @param int $post_id Post id.
	 * @return array|null
	 */
	public static function stored( $post_id ) {
		$g = get_post_meta( (int) $post_id, self::META, true );
		if ( ! is_array( $g ) || ! isset( $g['name'] ) || '' === trim( (string) $g['name'] ) ) {
			return null;
		}
		return array(
			'name'     => (string) $g['name'],
			'title'    => isset( $g['title'] ) ? (string) $g['title'] : '',
			'photo_id' => isset( $g['photo_id'] ) ? (int) $g['photo_id'] : 0,
		);
	}

	/**
	 * Photo URL for an attachment id (thumbnail size), or null.
	 *
	 * @param int $photo_id Attachment id.
	 * @return string|null
	 */
	protected static function photo_url( $photo_id ) {
		if ( $photo_id <= 0 ) {
			return null;
		}
		$url = wp_get_attachment_image_url( $photo_id, 'thumbnail' );
		return $url ? $url : null;
	}

	/**
	 * API shape for a post: { name, title, photo_id, photo_url } | null.
	 *
	 * @param int $post_id Post id.
	 * @return array|null
	 */
	public static function shape_for_post( $post_id ) {
		$g = self::stored( $post_id );
		if ( ! $g ) {
			return null;
		}
		$g['photo_url'] = self::photo_url( $g['photo_id'] );
		return $g;
	}

	// ---------------------------------------------------------------------
	// Post writes
	// ---------------------------------------------------------------------

	/**
	 * Validates `guest_author` / `save_guest_author` before anything is written.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param WP_User         $user    Acting staff user.
	 * @param WP_Post|null    $post    Existing post (null on create).
	 * @return array|WP_Error Plan for apply(): [] = nothing to do.
	 */
	public static function prepare( $request, $user, $post ) {
		if ( ! $request->has_param( 'guest_author' ) ) {
			return array();
		}
		$guest = self::normalize( $request->get_param( 'guest_author' ), $user );
		if ( is_wp_error( $guest ) ) {
			return $guest;
		}
		$current = $post ? self::stored( $post->ID ) : null;
		$changed = $guest !== $current;
		$save    = $guest && rest_sanitize_boolean( $request->get_param( 'save_guest_author' ) );

		if ( ( $changed || $save ) && ! NROS_Core::staff_can( $user, 'change_author' ) ) {
			return self::forbidden();
		}
		return array(
			'changed' => $changed,
			'guest'   => $guest,
			'save'    => $save,
		);
	}

	/**
	 * Writes a prepared plan.
	 *
	 * @param int   $post_id Post id.
	 * @param array $plan    From prepare().
	 */
	public static function apply( $post_id, $plan ) {
		if ( empty( $plan ) ) {
			return;
		}
		if ( ! empty( $plan['changed'] ) ) {
			if ( $plan['guest'] ) {
				update_post_meta( (int) $post_id, self::META, $plan['guest'] );
			} else {
				delete_post_meta( (int) $post_id, self::META );
			}
		}
		if ( ! empty( $plan['save'] ) && $plan['guest'] ) {
			self::upsert( $plan['guest'] );
		}
	}

	// ---------------------------------------------------------------------
	// Saved list
	// ---------------------------------------------------------------------

	/**
	 * @return array[] Raw saved rows.
	 */
	protected static function saved() {
		$list = get_option( self::OPTION, array() );
		$out  = array();
		foreach ( is_array( $list ) ? $list : array() as $row ) {
			if ( is_array( $row ) && ! empty( $row['id'] ) && isset( $row['name'] ) && '' !== (string) $row['name'] ) {
				$out[] = array(
					'id'       => (string) $row['id'],
					'name'     => (string) $row['name'],
					'title'    => isset( $row['title'] ) ? (string) $row['title'] : '',
					'photo_id' => isset( $row['photo_id'] ) ? (int) $row['photo_id'] : 0,
					'updated'  => isset( $row['updated'] ) ? (int) $row['updated'] : 0,
				);
			}
		}
		return $out;
	}

	/**
	 * @param array[] $list Rows.
	 */
	protected static function store( $list ) {
		usort(
			$list,
			function ( $a, $b ) {
				return $b['updated'] - $a['updated'];
			}
		);
		$list = array_slice( array_values( $list ), 0, self::MAX_SAVED );
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, $list, '', 'no' );
		} else {
			update_option( self::OPTION, $list, false );
		}
	}

	/**
	 * Name key for "same guest" matching.
	 *
	 * @param string $name Name.
	 * @return string
	 */
	protected static function key( $name ) {
		$name = trim( preg_replace( '/\s+/u', ' ', (string) $name ) );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $name ) : strtolower( $name );
	}

	/**
	 * Adds a guest to the saved list or updates the one with the same name.
	 *
	 * @param array $guest { name, title, photo_id }.
	 * @return array Saved row.
	 */
	public static function upsert( $guest ) {
		$list = self::saved();
		$key  = self::key( $guest['name'] );
		$row  = null;
		foreach ( $list as $i => $r ) {
			if ( self::key( $r['name'] ) === $key ) {
				$list[ $i ] = array_merge( $r, $guest, array( 'updated' => time() ) );
				$row        = $list[ $i ];
				break;
			}
		}
		if ( ! $row ) {
			$row    = array_merge( array( 'id' => strtolower( wp_generate_password( 10, false, false ) ) ), $guest, array( 'updated' => time() ) );
			$list[] = $row;
		}
		self::store( $list );
		return $row;
	}

	/**
	 * API shape of a saved row.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	protected static function shape_saved( $row ) {
		return array(
			'id'        => $row['id'],
			'name'      => $row['name'],
			'title'     => $row['title'],
			'photo_id'  => $row['photo_id'] > 0 && get_post( $row['photo_id'] ) ? $row['photo_id'] : 0,
			'photo_url' => self::photo_url( $row['photo_id'] ),
		);
	}

	/**
	 * GET /admin/guest-authors?search= — saved guests, most recently saved first (max 50).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function list_saved( $request ) {
		$search = self::key( sanitize_text_field( (string) $request->get_param( 'search' ) ) );
		$items  = array();
		foreach ( self::saved() as $row ) {
			if ( '' !== $search ) {
				$hay = self::key( $row['name'] . ' ' . $row['title'] );
				if ( false === ( function_exists( 'mb_strpos' ) ? mb_strpos( $hay, $search ) : strpos( $hay, $search ) ) ) {
					continue;
				}
			}
			$items[] = $row;
		}
		usort(
			$items,
			function ( $a, $b ) {
				return $b['updated'] - $a['updated'];
			}
		);
		return new WP_REST_Response( array_map( array( __CLASS__, 'shape_saved' ), array_slice( $items, 0, 50 ) ), 200 );
	}

	/**
	 * POST /admin/guest-authors {name, title, photo_id} — save (upsert by name).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_saved( $request ) {
		$user  = NROS_Auth::get_user_from_request( $request );
		$guest = self::normalize(
			array(
				'name'     => $request->get_param( 'name' ),
				'title'    => $request->get_param( 'title' ),
				'photo_id' => $request->get_param( 'photo_id' ),
			),
			$user
		);
		if ( is_wp_error( $guest ) ) {
			return $guest;
		}
		if ( ! $guest ) {
			return new WP_Error( 'nros_bad_guest', 'অতিথি লেখকের নাম দিন।', array( 'status' => 400 ) );
		}
		return new WP_REST_Response( self::shape_saved( self::upsert( $guest ) ), 201 );
	}

	/**
	 * DELETE /admin/guest-authors/{id} — removes a saved guest (posts that use it keep their own copy).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_saved( $request ) {
		$id   = (string) $request->get_param( 'id' );
		$list = self::saved();
		$left = array_values(
			array_filter(
				$list,
				function ( $r ) use ( $id ) {
					return $r['id'] !== $id;
				}
			)
		);
		if ( count( $left ) === count( $list ) ) {
			return new WP_Error( 'nros_not_found', 'সংরক্ষিত অতিথি লেখক পাওয়া যায়নি।', array( 'status' => 404 ) );
		}
		self::store( $left );
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}
}
