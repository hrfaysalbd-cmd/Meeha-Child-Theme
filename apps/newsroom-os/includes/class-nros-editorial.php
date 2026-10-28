<?php
/**
 * NROS_Editorial — round 12: the theme's editorial fields in the app APIs.
 *
 * Shared by NROS_Admin_Posts (staff — AdminPost) and NROS_Submissions
 * (correspondent — only tags/area/topic):
 *
 * - tags (WordPress post_tag, created on save; ≤ TAGS_MAX, each ≤ TAG_MAX chars)
 * - area {division, district, upazila} — the theme's `_meeha_division/_district/_upazila`
 *   post meta, validated against meeha_get_bd_divisions()/meeha_get_upazilas_for_district()
 * - topic (`_meeha_topic`, ≤ 80, empty → deleted, like the theme's meta box)
 * - flags {breaking, trending, investigation, glance} + glance_text — the theme's
 *   "মিহা সম্পাদকীয়" meta box keys, with the same `_meeha_breaking_at` / `_meeha_trending_at`
 *   stamping rules (fresh stamp when a flag is switched on, removed when switched off). STAFF ONLY.
 * - lit — the theme's "সাহিত্য তথ্য" `_meeha_lit_*` fields, sanitized with the theme's own
 *   meeha_lit_sanitize_field(). STAFF ONLY.
 *
 * Everything is validated by prepare() BEFORE the post is written, then written by apply(),
 * so a 400 never leaves a half-saved article.
 *
 * Also: GET /admin/tags (autocomplete), GET /admin/lit-schema, and a defensive kses
 * allow-list for the highlight markup (CONTRACT-R12 §B).
 *
 * @package NewsroomOS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NROS_Editorial {

	const TAGS_MAX     = 15;
	const TAG_MAX      = 40;
	const TOPIC_MAX    = 80;
	const GLANCE_MAX   = 90;
	const AREA_MAX     = 60;
	const LIT_TEXT_MAX = 1000;
	const FLAGS        = array( 'breaking', 'trending', 'investigation', 'glance' );

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_filter( 'wp_kses_allowed_html', array( __CLASS__, 'allow_highlight_markup' ), 20, 2 );
	}

	/**
	 * Routes.
	 */
	public static function register_routes() {
		register_rest_route(
			NROS_API_NS,
			'/admin/tags',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_tags' ),
				'permission_callback' => array( 'NROS_Core', 'require_staff' ),
			)
		);
		register_rest_route(
			NROS_API_NS,
			'/admin/lit-schema',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'lit_schema' ),
				'permission_callback' => array( 'NROS_Core', 'require_staff' ),
			)
		);
	}

	/**
	 * Highlight markup (CONTRACT-R12 §B) must survive wp_kses_post():
	 * `<blockquote class="wp-block-quote is-style-meeha-highlight"><p>…</p></blockquote>` and
	 * `<mark class="m-mark">…</mark>`. Core already allows both (class is a global attribute),
	 * this only guarantees it if another plugin trims the list.
	 *
	 * @param array  $tags    Allowed tags.
	 * @param string $context Context.
	 * @return array
	 */
	public static function allow_highlight_markup( $tags, $context ) {
		if ( 'post' !== $context || ! is_array( $tags ) ) {
			return $tags;
		}
		foreach ( array( 'blockquote', 'mark', 'p' ) as $tag ) {
			if ( ! isset( $tags[ $tag ] ) || ! is_array( $tags[ $tag ] ) ) {
				$tags[ $tag ] = array();
			}
			if ( empty( $tags[ $tag ]['class'] ) ) {
				$tags[ $tag ]['class'] = true;
			}
		}
		return $tags;
	}

	// ---------------------------------------------------------------------
	// Reading
	// ---------------------------------------------------------------------

	/**
	 * Tag names of a post.
	 *
	 * @param int $post_id Post id.
	 * @return string[]
	 */
	public static function tags_of( $post_id ) {
		$terms = wp_get_post_terms( $post_id, 'post_tag', array( 'fields' => 'names' ) );
		return is_wp_error( $terms ) ? array() : array_values( array_map( 'strval', $terms ) );
	}

	/**
	 * Area triplet of a post.
	 *
	 * @param int $post_id Post id.
	 * @return array{division:string,district:string,upazila:string}
	 */
	public static function area_of( $post_id ) {
		return array(
			'division' => (string) get_post_meta( $post_id, '_meeha_division', true ),
			'district' => (string) get_post_meta( $post_id, '_meeha_district', true ),
			'upazila'  => (string) get_post_meta( $post_id, '_meeha_upazila', true ),
		);
	}

	/**
	 * Whether the theme's literature helpers are available.
	 *
	 * @return bool
	 */
	public static function lit_available() {
		return function_exists( 'meeha_lit_fields' ) && function_exists( 'meeha_lit_sanitize_field' );
	}

	/**
	 * Literature field values (keys without the `_meeha_lit_` prefix, plus `genre`), or null
	 * when the theme's literature module is not active.
	 *
	 * @param int $post_id Post id.
	 * @return array<string,string>|null
	 */
	public static function lit_of( $post_id ) {
		if ( ! self::lit_available() ) {
			return null;
		}
		$out = array( 'genre' => (string) get_post_meta( $post_id, '_meeha_lit_genre', true ) );
		foreach ( meeha_lit_fields() as $key => $def ) {
			$out[ $key ] = (string) get_post_meta( $post_id, '_meeha_lit_' . $key, true );
		}
		return $out;
	}

	/**
	 * Is the post in the বাঙালি সাহিত্য-সংস্কৃতি tree?
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	public static function is_literature( $post ) {
		return function_exists( 'meeha_lit_is_literature' ) ? (bool) meeha_lit_is_literature( $post ) : false;
	}

	/**
	 * Editorial flags.
	 *
	 * @param int $post_id Post id.
	 * @return array<string,bool>
	 */
	public static function flags_of( $post_id ) {
		$out = array();
		foreach ( self::FLAGS as $flag ) {
			$out[ $flag ] = '1' === (string) get_post_meta( $post_id, '_meeha_' . $flag, true );
		}
		return $out;
	}

	/**
	 * Fields added to the staff AdminPost shape.
	 *
	 * @param WP_Post $post Post.
	 * @return array
	 */
	public static function staff_fields( $post ) {
		$lit       = self::lit_of( $post->ID );
		$cover_url = null;
		if ( $lit && ! empty( $lit['cover'] ) ) {
			$url       = wp_get_attachment_image_url( (int) $lit['cover'], 'medium' );
			$cover_url = $url ? $url : null;
		}
		$breaking_at = (int) get_post_meta( $post->ID, '_meeha_breaking_at', true );
		$trending_at = (int) get_post_meta( $post->ID, '_meeha_trending_at', true );

		return array(
			'tags'                => self::tags_of( $post->ID ),
			'area'                => self::area_of( $post->ID ),
			'topic'               => (string) get_post_meta( $post->ID, '_meeha_topic', true ),
			'flags'               => self::flags_of( $post->ID ),
			// Unix seconds; the theme stops showing a trending pin 24 h after trending_at.
			'flag_times'          => array(
				'breaking_at' => $breaking_at > 0 ? $breaking_at : null,
				'trending_at' => $trending_at > 0 ? $trending_at : null,
			),
			'glance_text'         => (string) get_post_meta( $post->ID, '_meeha_glance_text', true ),
			'lit'                 => $lit,
			'lit_cover_url'       => $cover_url,
			// Genre the theme actually uses (explicit pick, else from the category); '' when not literature.
			'lit_genre_effective' => function_exists( 'meeha_lit_genre_of' ) ? (string) meeha_lit_genre_of( $post ) : '',
			'is_literature'       => self::is_literature( $post ),
		);
	}

	/**
	 * Fields added to the correspondent submission shape.
	 *
	 * @param WP_Post $post Post.
	 * @return array
	 */
	public static function correspondent_fields( $post ) {
		return array(
			'tags' => self::tags_of( $post->ID ),
			'area' => self::area_of( $post->ID ),
		);
	}

	// ---------------------------------------------------------------------
	// Writing: prepare (validate) → apply
	// ---------------------------------------------------------------------

	/**
	 * Truthy param → bool.
	 *
	 * @param mixed $v Value.
	 * @return bool
	 */
	protected static function to_bool( $v ) {
		if ( is_bool( $v ) ) {
			return $v;
		}
		if ( is_int( $v ) || is_float( $v ) ) {
			return 0 != $v; // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual
		}
		$v = strtolower( trim( (string) $v ) );
		return in_array( $v, array( '1', 'true', 'yes', 'on' ), true );
	}

	/**
	 * Normalises the `tags` param: array of names (or a comma-separated string). Commas
	 * (`,` and `،`) inside an entry split it into several tags.
	 *
	 * @param mixed $raw Raw param.
	 * @return string[]|WP_Error
	 */
	public static function normalize_tags( $raw ) {
		if ( is_string( $raw ) ) {
			$raw = array( $raw );
		}
		if ( ! is_array( $raw ) ) {
			return new WP_Error( 'nros_bad_tags', 'ট্যাগ সঠিক নয়।', array( 'status' => 400 ) );
		}
		$out  = array();
		$seen = array();
		foreach ( $raw as $entry ) {
			if ( ! is_scalar( $entry ) ) {
				return new WP_Error( 'nros_bad_tags', 'ট্যাগ সঠিক নয়।', array( 'status' => 400 ) );
			}
			foreach ( preg_split( '/[,،]/u', (string) $entry ) as $name ) {
				$name = trim( preg_replace( '/\s+/u', ' ', sanitize_text_field( $name ) ) );
				if ( '' === $name ) {
					continue;
				}
				if ( mb_strlen( $name ) > self::TAG_MAX ) {
					return new WP_Error( 'nros_bad_tags', 'প্রতিটি ট্যাগ সর্বোচ্চ ' . NROS_Core::bn_digits( self::TAG_MAX ) . ' অক্ষরের হতে পারে।', array( 'status' => 400 ) );
				}
				$k = function_exists( 'mb_strtolower' ) ? mb_strtolower( $name ) : strtolower( $name );
				if ( isset( $seen[ $k ] ) ) {
					continue;
				}
				$seen[ $k ] = true;
				$out[]      = $name;
			}
		}
		if ( count( $out ) > self::TAGS_MAX ) {
			return new WP_Error( 'nros_bad_tags', 'সর্বোচ্চ ' . NROS_Core::bn_digits( self::TAGS_MAX ) . 'টি ট্যাগ দেওয়া যায়।', array( 'status' => 400 ) );
		}
		return $out;
	}

	/**
	 * Validates an area triplet against the theme's data. A triplet identical to what the
	 * post already stores is accepted as-is (old data, upazila overrides that changed since).
	 *
	 * @param mixed $raw     Raw `area` param.
	 * @param array $current Current triplet.
	 * @return array|WP_Error Normalised triplet.
	 */
	public static function normalize_area( $raw, $current ) {
		if ( ! is_array( $raw ) ) {
			return new WP_Error( 'nros_bad_area', 'এলাকার তথ্য সঠিক নয়।', array( 'status' => 400 ) );
		}
		$area = array();
		foreach ( array( 'division', 'district', 'upazila' ) as $k ) {
			$v = isset( $raw[ $k ] ) && is_scalar( $raw[ $k ] ) ? trim( sanitize_text_field( (string) $raw[ $k ] ) ) : '';
			if ( mb_strlen( $v ) > self::AREA_MAX ) {
				return new WP_Error( 'nros_bad_area', 'এলাকার নাম অনেক বড়।', array( 'status' => 400 ) );
			}
			$area[ $k ] = $v;
		}
		if ( $area === $current ) {
			return $area;
		}
		if ( '' === $area['division'] && ( '' !== $area['district'] || '' !== $area['upazila'] ) ) {
			return new WP_Error( 'nros_bad_area', 'জেলা/উপজেলা দিলে বিভাগও দিন।', array( 'status' => 400 ) );
		}
		if ( '' === $area['district'] && '' !== $area['upazila'] ) {
			return new WP_Error( 'nros_bad_area', 'উপজেলা দিলে জেলাও দিন।', array( 'status' => 400 ) );
		}
		if ( function_exists( 'meeha_get_bd_divisions' ) && '' !== $area['division'] ) {
			$divisions = (array) meeha_get_bd_divisions();
			if ( ! isset( $divisions[ $area['division'] ] ) ) {
				return new WP_Error( 'nros_bad_area', 'বিভাগ সঠিক নয়।', array( 'status' => 400 ) );
			}
			if ( '' !== $area['district'] && ! in_array( $area['district'], (array) $divisions[ $area['division'] ], true ) ) {
				return new WP_Error( 'nros_bad_area', 'জেলাটি এই বিভাগের নয়।', array( 'status' => 400 ) );
			}
			if ( '' !== $area['upazila'] && function_exists( 'meeha_get_upazilas_for_district' ) ) {
				$upazilas = array_map( 'strval', (array) meeha_get_upazilas_for_district( $area['district'] ) );
				$same_old = $area['district'] === $current['district'] && $area['upazila'] === $current['upazila'];
				if ( $upazilas && ! $same_old && ! in_array( $area['upazila'], $upazilas, true ) ) {
					return new WP_Error( 'nros_bad_area', 'উপজেলাটি এই জেলার নয়।', array( 'status' => 400 ) );
				}
			}
		}
		return $area;
	}

	/**
	 * Validates the round-12 editorial params of a request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param WP_Post|null    $post    Existing post (null on create).
	 * @param bool            $staff   Staff route (flags/glance/lit allowed).
	 * @return array|WP_Error Plan for apply().
	 */
	public static function prepare( $request, $post, $staff ) {
		$plan    = array();
		$post_id = $post ? (int) $post->ID : 0;

		if ( ! $staff ) {
			foreach ( array( 'flags', 'glance_text', 'lit', 'breaking', 'trending', 'investigation', 'glance' ) as $forbidden ) {
				if ( null !== $request->get_param( $forbidden ) ) {
					return new WP_Error( 'nros_forbidden_field', 'ব্রেকিং/ট্রেন্ডিং/একনজরে/সাহিত্য তথ্য শুধু সম্পাদক বা পাবলিশার ঠিক করতে পারেন।', array( 'status' => 403 ) );
				}
			}
			// Round 13 — the guest byline is staff-only too.
			if ( $request->has_param( 'guest_author' ) || null !== $request->get_param( 'save_guest_author' ) ) {
				return new WP_Error( 'nros_forbidden_field', 'অতিথি লেখক শুধু সম্পাদক বা পাবলিশার যোগ করতে পারেন।', array( 'status' => 403 ) );
			}
		}

		// Tags.
		$tags = $request->get_param( 'tags' );
		if ( null !== $tags ) {
			$tags = self::normalize_tags( $tags );
			if ( is_wp_error( $tags ) ) {
				return $tags;
			}
			$plan['tags'] = $tags;
		}

		// Area (new object form). The legacy top-level division/district/upazila params keep
		// their old, unvalidated behaviour (old app builds) — see legacy_area().
		$area = $request->get_param( 'area' );
		if ( null !== $area ) {
			$current = $post_id ? self::area_of( $post_id ) : array( 'division' => '', 'district' => '', 'upazila' => '' );
			$area    = self::normalize_area( $area, $current );
			if ( is_wp_error( $area ) ) {
				return $area;
			}
			$plan['area'] = $area;
		} else {
			$legacy = array();
			foreach ( array( 'division', 'district', 'upazila' ) as $k ) {
				$v = $request->get_param( $k );
				if ( null !== $v ) {
					$legacy[ $k ] = sanitize_text_field( (string) $v );
				}
			}
			if ( $legacy ) {
				$plan['legacy_area'] = $legacy;
			}
		}

		// Topic (both roles; theme rule: ≤ 80, empty → deleted).
		$topic = $request->get_param( 'topic' );
		if ( null !== $topic ) {
			$topic         = trim( sanitize_text_field( is_scalar( $topic ) ? (string) $topic : '' ) );
			$plan['topic'] = mb_substr( $topic, 0, self::TOPIC_MAX );
		}

		if ( ! $staff ) {
			return $plan;
		}

		// Flags.
		$flags = $request->get_param( 'flags' );
		if ( null !== $flags ) {
			if ( ! is_array( $flags ) ) {
				return new WP_Error( 'nros_bad_flags', 'সম্পাদকীয় চিহ্ন সঠিক নয়।', array( 'status' => 400 ) );
			}
			$plan['flags'] = array();
			foreach ( self::FLAGS as $flag ) {
				if ( array_key_exists( $flag, $flags ) ) {
					$plan['flags'][ $flag ] = self::to_bool( $flags[ $flag ] );
				}
			}
		}

		// Glance sentence (≤ 90 after the theme's whitespace normalisation).
		$glance = $request->get_param( 'glance_text' );
		if ( null !== $glance ) {
			$glance = trim( preg_replace( '/\s+/u', ' ', sanitize_text_field( is_scalar( $glance ) ? (string) $glance : '' ) ) );
			if ( mb_strlen( $glance ) > self::GLANCE_MAX ) {
				return new WP_Error( 'nros_too_long', 'একনজরে বাক্য সর্বোচ্চ ' . NROS_Core::bn_digits( self::GLANCE_MAX ) . ' অক্ষরের হতে পারে।', array( 'status' => 400 ) );
			}
			$plan['glance_text'] = $glance;
		}

		// Literature.
		$lit = $request->get_param( 'lit' );
		if ( null !== $lit && self::lit_available() ) {
			if ( ! is_array( $lit ) ) {
				return new WP_Error( 'nros_bad_lit', 'সাহিত্য তথ্য সঠিক নয়।', array( 'status' => 400 ) );
			}
			$defs        = meeha_lit_fields();
			$plan['lit'] = array();
			if ( array_key_exists( 'genre', $lit ) ) {
				$g      = sanitize_key( is_scalar( $lit['genre'] ) ? (string) $lit['genre'] : '' );
				$genres = function_exists( 'meeha_lit_genre_defaults' ) ? meeha_lit_genre_defaults() : array();
				if ( '' !== $g && ! array_key_exists( $g, $genres ) ) {
					return new WP_Error( 'nros_bad_lit', 'সাহিত্যের ধরন সঠিক নয়।', array( 'status' => 400 ) );
				}
				$plan['lit']['genre'] = $g;
			}
			foreach ( $defs as $key => $def ) {
				if ( ! array_key_exists( $key, $lit ) ) {
					continue;
				}
				$raw = $lit[ $key ];
				if ( is_bool( $raw ) ) {
					$raw = $raw ? '1' : '';
				}
				if ( null !== $raw && ! is_scalar( $raw ) ) {
					return new WP_Error( 'nros_bad_lit', 'সাহিত্য তথ্য সঠিক নয়: ' . $def[0], array( 'status' => 400 ) );
				}
				if ( mb_strlen( (string) $raw ) > self::LIT_TEXT_MAX ) {
					return new WP_Error( 'nros_too_long', 'সাহিত্য তথ্য অনেক বড়: ' . $def[0], array( 'status' => 400 ) );
				}
				$plan['lit'][ $key ] = (string) meeha_lit_sanitize_field( $def[1], (string) $raw );
			}
		}

		return $plan;
	}

	/**
	 * Writes a prepared plan onto a post.
	 *
	 * @param int   $post_id Post id.
	 * @param array $plan    From prepare().
	 */
	public static function apply( $post_id, $plan ) {
		$post_id = (int) $post_id;

		if ( isset( $plan['tags'] ) ) {
			$ids = array();
			foreach ( $plan['tags'] as $name ) {
				$term = get_term_by( 'name', $name, 'post_tag' );
				if ( $term && ! is_wp_error( $term ) ) {
					$ids[] = (int) $term->term_id;
					continue;
				}
				$made = wp_insert_term( $name, 'post_tag' );
				if ( is_wp_error( $made ) ) {
					$existing = $made->get_error_data( 'term_exists' );
					if ( $existing ) {
						$ids[] = (int) $existing;
					}
					continue;
				}
				$ids[] = (int) $made['term_id'];
			}
			wp_set_post_terms( $post_id, array_values( array_unique( $ids ) ), 'post_tag', false );
		}

		if ( isset( $plan['legacy_area'] ) ) {
			foreach ( $plan['legacy_area'] as $k => $v ) {
				update_post_meta( $post_id, '_meeha_' . $k, $v );
			}
		}
		if ( isset( $plan['area'] ) ) {
			foreach ( $plan['area'] as $k => $v ) {
				if ( '' === $v ) {
					delete_post_meta( $post_id, '_meeha_' . $k );
				} else {
					update_post_meta( $post_id, '_meeha_' . $k, $v );
				}
			}
		}

		if ( isset( $plan['topic'] ) ) {
			if ( '' === $plan['topic'] ) {
				delete_post_meta( $post_id, '_meeha_topic' );
			} else {
				update_post_meta( $post_id, '_meeha_topic', $plan['topic'] );
			}
		}

		if ( isset( $plan['flags'] ) ) {
			foreach ( $plan['flags'] as $flag => $on ) {
				$key = '_meeha_' . $flag;
				if ( $on ) {
					if ( '1' !== (string) get_post_meta( $post_id, $key, true ) ) {
						update_post_meta( $post_id, $key, '1' );
						if ( 'breaking' === $flag || 'trending' === $flag ) {
							update_post_meta( $post_id, $key . '_at', time() ); // Fresh pin/breaking, as the theme does.
						}
					}
				} else {
					delete_post_meta( $post_id, $key );
					if ( 'breaking' === $flag || 'trending' === $flag ) {
						delete_post_meta( $post_id, $key . '_at' );
					}
				}
			}
		}

		if ( isset( $plan['glance_text'] ) ) {
			if ( '' === $plan['glance_text'] ) {
				delete_post_meta( $post_id, '_meeha_glance_text' );
			} else {
				update_post_meta( $post_id, '_meeha_glance_text', $plan['glance_text'] );
			}
		}

		if ( isset( $plan['lit'] ) ) {
			foreach ( $plan['lit'] as $key => $value ) {
				if ( '' === $value ) {
					delete_post_meta( $post_id, '_meeha_lit_' . $key );
				} else {
					update_post_meta( $post_id, '_meeha_lit_' . $key, $value );
				}
			}
		}
	}

	// ---------------------------------------------------------------------
	// Routes
	// ---------------------------------------------------------------------

	/**
	 * GET /admin/tags?search= — up to 20 tags for autocomplete, most used first.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function list_tags( $request ) {
		$search = trim( sanitize_text_field( (string) $request->get_param( 'search' ) ) );
		$args   = array(
			'taxonomy'   => 'post_tag',
			'hide_empty' => false,
			'number'     => 20,
			'orderby'    => 'count',
			'order'      => 'DESC',
		);
		if ( '' !== $search ) {
			$args['name__like'] = mb_substr( $search, 0, self::TAG_MAX );
		}
		$terms = get_terms( $args );
		$items = array();
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $t ) {
				$items[] = array(
					'id'    => (int) $t->term_id,
					'name'  => html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' ),
					'count' => (int) $t->count,
				);
			}
		}
		return new WP_REST_Response( $items, 200 );
	}

	/**
	 * GET /admin/lit-schema — the theme's literature genres and fields.
	 *
	 * { available, root_category_id, category_ids[], genres:[{id,label,category_id}],
	 *   fields:[{key,label,type,genres[]}] } — `genres: []` on a field = all genres.
	 *
	 * @return WP_REST_Response
	 */
	public static function lit_schema() {
		if ( ! self::lit_available() ) {
			return new WP_REST_Response(
				array(
					'available'        => false,
					'root_category_id' => 0,
					'category_ids'     => array(),
					'genres'           => array(),
					'fields'           => array(),
				),
				200
			);
		}

		$genres = array();
		$merged = function_exists( 'meeha_lit_genres' ) ? meeha_lit_genres() : array();
		$defs   = function_exists( 'meeha_lit_genre_defaults' ) ? meeha_lit_genre_defaults() : array();
		foreach ( $defs as $id => $def ) {
			$g        = isset( $merged[ $id ] ) ? $merged[ $id ] : $def;
			$genres[] = array(
				'id'          => (string) $id,
				'label'       => (string) $g['label'],
				'category_id' => isset( $g['cat'] ) ? (int) $g['cat'] : 0,
			);
		}

		$fields = array();
		foreach ( meeha_lit_fields() as $key => $def ) {
			$fields[] = array(
				'key'    => (string) $key,
				'label'  => (string) $def[0],
				'type'   => (string) $def[1],
				'genres' => ! empty( $def[2] ) ? array( (string) $def[2] ) : array(),
			);
		}

		$root = function_exists( 'meeha_lit_root_slug' ) ? get_category_by_slug( meeha_lit_root_slug() ) : null;

		return new WP_REST_Response(
			array(
				'available'        => true,
				'root_category_id' => $root ? (int) $root->term_id : 0,
				'category_ids'     => function_exists( 'meeha_lit_tree_ids' ) ? array_values( array_map( 'intval', meeha_lit_tree_ids() ) ) : array(),
				'genres'           => $genres,
				'fields'           => $fields,
			),
			200
		);
	}
}
