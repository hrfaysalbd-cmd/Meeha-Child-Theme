<?php
/**
 * NROS_Prompts — round 9: the unified AI-prompt management system behind the
 * publisher-app "প্রোম্পট" menu, replacing round 8's single correspondent
 * summary-template option and staff-template-list option with one proper
 * table that supports many prompts, each independently role-visible.
 *
 * Two prompt types:
 * - 'news'    — dynamic, tied to whatever post is open. Rendered next to the
 *               Title field in the post editor (both apps). Shortcodes
 *               ({title}, {body}, {summary}, {category}) are substituted
 *               from the live post before copying, same mechanism as round 8.
 * - 'library' — not tied to any open post. Has its own Publisher-defined set
 *               of custom input fields (e.g. a "rewrite" prompt might ask for
 *               an original URL/title and an original body); filling those in
 *               substitutes them into the template before copying. This is
 *               what "প্রোম্পট লাইব্রেরি" (Prompt Library) shows — the set of
 *               library-type prompts whose visible_roles includes the viewer.
 *
 * Visibility: every prompt (of either type) carries a `visible_roles` list
 * the Publisher sets per-prompt (e.g. a summary prompt visible only to
 * 'correspondent'). Editor and Publisher both see every prompt regardless of
 * visible_roles via the staff-only /admin/prompts route — visible_roles is
 * what narrows what a *correspondent* sees via the shared /prompts route;
 * Publisher alone may create/update/delete (Editor can view and copy only).
 *
 * @package NewsroomOS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NROS_Prompts {

	const TYPES = array( 'news', 'library' );

	const ROLES = array( 'correspondent', 'editor', 'publisher' );

	const FIELD_TYPES = array( 'text', 'textarea', 'select' );

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

		// Shared route: any logged-in app user, role-filtered server-side.
		// Used by the correspondent app's Write screen and by the Title-
		// adjacent prompt dropdown in both apps' post editors.
		register_rest_route( NROS_API_NS, '/prompts', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'list_for_viewer' ),
			'permission_callback' => array( 'NROS_Core', 'require_app_user' ),
		) );

		// Staff-only management surface behind the Publisher "প্রোম্পট" menu.
		register_rest_route( NROS_API_NS, '/admin/prompts', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_all' ),
				'permission_callback' => array( 'NROS_Core', 'require_staff' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'create' ),
				'permission_callback' => array( 'NROS_Core', 'require_publisher' ),
			),
		) );

		register_rest_route( NROS_API_NS, '/admin/prompts/(?P<id>\d+)', array(
			array(
				'methods'             => 'PATCH',
				'callback'            => array( __CLASS__, 'update' ),
				'permission_callback' => array( 'NROS_Core', 'require_publisher' ),
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'delete' ),
				'permission_callback' => array( 'NROS_Core', 'require_publisher' ),
			),
		) );
	}

	// -------------------------------------------------------------------------
	// Reads
	// -------------------------------------------------------------------------

	/**
	 * GET /prompts — returns prompts visible to the requesting user's role.
	 * Staff (Editor/Publisher/Administrator) always get every prompt — the
	 * "Editor views all prompts" requirement — while a correspondent only
	 * gets prompts whose visible_roles includes 'correspondent'. Supports an
	 * optional `?type=news|library` filter.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function list_for_viewer( $request ) {
		$user = NROS_Auth::get_user_from_request( $request );
		if ( ! $user || is_wp_error( $user ) ) {
			return new WP_Error( 'nros_unauthorized', 'লগইন প্রয়োজন।', array( 'status' => 401 ) );
		}

		$type = self::sanitize_type_filter( $request->get_param( 'type' ) );
		$rows = self::fetch_rows( $type );

		$is_staff = user_can( $user, 'edit_others_posts' ) || user_can( $user, 'manage_options' );

		if ( ! $is_staff ) {
			$rows = array_values(
				array_filter(
					$rows,
					function ( $row ) {
						return in_array( 'correspondent', $row['visible_roles'], true );
					}
				)
			);
		}

		return new WP_REST_Response( array_map( array( __CLASS__, 'to_public' ), $rows ), 200 );
	}

	/**
	 * GET /admin/prompts — the full, unfiltered list for the Publisher's
	 * management screen (and Editor's read-only "সব প্রোম্পট" view).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function list_all( $request ) {
		$type = self::sanitize_type_filter( $request->get_param( 'type' ) );
		$rows = self::fetch_rows( $type );
		return new WP_REST_Response( array_map( array( __CLASS__, 'to_public' ), $rows ), 200 );
	}

	/**
	 * @param string $type_filter '' for no filter, else one of self::TYPES.
	 * @return array<int, array> Decoded rows (visible_roles/fields as arrays).
	 */
	protected static function fetch_rows( $type_filter ) {
		global $wpdb;
		$table = $wpdb->prefix . 'nros_prompts';

		if ( '' !== $type_filter ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE type = %s ORDER BY sort_order ASC, id ASC", $type_filter ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} else {
			$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY sort_order ASC, id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		return array_map( array( __CLASS__, 'decode_row' ), $rows ?: array() );
	}

	/**
	 * @param array $row Raw DB row.
	 * @return array Row with visible_roles/fields JSON-decoded.
	 */
	protected static function decode_row( $row ) {
		$roles           = json_decode( (string) $row['visible_roles'], true );
		$row['visible_roles'] = is_array( $roles ) ? array_values( array_intersect( $roles, self::ROLES ) ) : array();

		$fields        = json_decode( (string) $row['fields'], true );
		$row['fields'] = is_array( $fields ) ? $fields : array();

		return $row;
	}

	/**
	 * Shapes a decoded row for the API response.
	 *
	 * @param array $row Decoded row.
	 * @return array
	 */
	protected static function to_public( $row ) {
		return array(
			'id'            => (int) $row['id'],
			'type'          => (string) $row['type'],
			'label'         => (string) $row['label'],
			'body'          => (string) $row['body'],
			'visible_roles' => $row['visible_roles'],
			'fields'        => $row['fields'],
			'sort_order'    => (int) $row['sort_order'],
			'created_at'    => (string) $row['created_at'],
			'updated_at'    => (string) $row['updated_at'],
		);
	}

	/**
	 * @param mixed $raw Raw `type` query param.
	 * @return string '' if absent/invalid, else the sanitized type.
	 */
	protected static function sanitize_type_filter( $raw ) {
		$type = sanitize_key( (string) $raw );
		return in_array( $type, self::TYPES, true ) ? $type : '';
	}

	// -------------------------------------------------------------------------
	// Writes (Publisher only)
	// -------------------------------------------------------------------------

	/**
	 * POST /admin/prompts.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create( $request ) {
		global $wpdb;
		$current = NROS_Auth::get_user_from_request( $request );

		$label = sanitize_text_field( (string) $request->get_param( 'label' ) );
		$body  = (string) $request->get_param( 'body' );
		if ( '' === $label || '' === trim( $body ) ) {
			return new WP_Error( 'nros_bad_request', 'প্রোম্পট লেবেল ও প্রোম্পট টেক্সট আবশ্যক।', array( 'status' => 400 ) );
		}

		$type          = self::sanitize_type( $request->get_param( 'type' ) );
		$visible_roles = self::sanitize_visible_roles( $request->get_param( 'visible_roles' ) );
		$fields        = self::sanitize_fields( $request->get_param( 'fields' ), $type );

		$fields_error = self::validate_body_placeholders( $type, $body, $fields );
		if ( is_wp_error( $fields_error ) ) {
			return $fields_error;
		}

		$now = current_time( 'mysql' );
		$wpdb->insert(
			$wpdb->prefix . 'nros_prompts',
			array(
				'type'          => $type,
				'label'         => $label,
				'body'          => sanitize_textarea_field( $body ),
				'visible_roles' => wp_json_encode( $visible_roles ),
				'fields'        => wp_json_encode( $fields ),
				'sort_order'    => (int) $request->get_param( 'sort_order' ),
				'created_by'    => $current && ! is_wp_error( $current ) ? $current->ID : null,
				'created_at'    => $now,
				'updated_at'    => $now,
			)
		);

		return new WP_REST_Response( array( 'ok' => true, 'id' => (int) $wpdb->insert_id ), 201 );
	}

	/**
	 * PATCH /admin/prompts/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update( $request ) {
		global $wpdb;
		$table = $wpdb->prefix . 'nros_prompts';
		$id    = (int) $request->get_param( 'id' );

		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $existing ) {
			return new WP_Error( 'nros_not_found', 'পাওয়া যায়নি।', array( 'status' => 404 ) );
		}
		$existing = self::decode_row( $existing );

		$update = array( 'updated_at' => current_time( 'mysql' ) );

		$type = $existing['type'];
		if ( null !== $request->get_param( 'type' ) ) {
			$type          = self::sanitize_type( $request->get_param( 'type' ) );
			$update['type'] = $type;
		}

		$label = $request->get_param( 'label' );
		if ( null !== $label ) {
			$label = sanitize_text_field( (string) $label );
			if ( '' === $label ) {
				return new WP_Error( 'nros_bad_request', 'প্রোম্পট লেবেল খালি রাখা যাবে না।', array( 'status' => 400 ) );
			}
			$update['label'] = $label;
		}

		$body = $request->get_param( 'body' );
		if ( null !== $body ) {
			if ( '' === trim( (string) $body ) ) {
				return new WP_Error( 'nros_bad_request', 'প্রোম্পট টেক্সট খালি রাখা যাবে না।', array( 'status' => 400 ) );
			}
			$body           = sanitize_textarea_field( (string) $body );
			$update['body'] = $body;
		} else {
			$body = $existing['body'];
		}

		$fields = $existing['fields'];
		if ( null !== $request->get_param( 'fields' ) ) {
			$fields           = self::sanitize_fields( $request->get_param( 'fields' ), $type );
			$update['fields'] = wp_json_encode( $fields );
		} elseif ( 'library' !== $type && ! empty( $fields ) ) {
			// Type just changed away from 'library' without new fields being
			// sent — the old custom-field defs no longer apply to a 'news'
			// prompt, so clear them rather than leave stale data behind.
			$fields           = array();
			$update['fields'] = wp_json_encode( $fields );
		}

		if ( null !== $request->get_param( 'visible_roles' ) ) {
			$update['visible_roles'] = wp_json_encode( self::sanitize_visible_roles( $request->get_param( 'visible_roles' ) ) );
		}

		if ( null !== $request->get_param( 'sort_order' ) ) {
			$update['sort_order'] = (int) $request->get_param( 'sort_order' );
		}

		$fields_error = self::validate_body_placeholders( $type, $body, $fields );
		if ( is_wp_error( $fields_error ) ) {
			return $fields_error;
		}

		$wpdb->update( $table, $update, array( 'id' => $id ) );

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * DELETE /admin/prompts/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete( $request ) {
		global $wpdb;
		$id      = (int) $request->get_param( 'id' );
		$deleted = $wpdb->delete( $wpdb->prefix . 'nros_prompts', array( 'id' => $id ) );

		if ( ! $deleted ) {
			return new WP_Error( 'nros_not_found', 'পাওয়া যায়নি।', array( 'status' => 404 ) );
		}

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	// -------------------------------------------------------------------------
	// Sanitizers / validation
	// -------------------------------------------------------------------------

	/**
	 * @param mixed $raw Raw `type` param.
	 * @return string One of self::TYPES ('news' default).
	 */
	protected static function sanitize_type( $raw ) {
		$type = sanitize_key( (string) $raw );
		return in_array( $type, self::TYPES, true ) ? $type : 'news';
	}

	/**
	 * @param mixed $raw Raw `visible_roles` param — expected array of role
	 *                    strings from self::ROLES.
	 * @return array<int, string> Deduped, validated roles (possibly empty).
	 */
	protected static function sanitize_visible_roles( $raw ) {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$clean = array();
		foreach ( $raw as $role ) {
			$role = sanitize_key( (string) $role );
			if ( in_array( $role, self::ROLES, true ) && ! in_array( $role, $clean, true ) ) {
				$clean[] = $role;
			}
		}
		return $clean;
	}

	/**
	 * Validates/sanitizes the custom-field definitions a library-type prompt
	 * carries (e.g. "মূল নিউজ ইউআরএল/টাইটেল", "মূল নিউজ" for a rewrite
	 * prompt). News-type prompts ignore fields entirely — they only ever
	 * substitute the fixed {title}/{body}/{summary}/{category} shortcodes
	 * from the open post, so an empty array is returned for them regardless
	 * of what was sent.
	 *
	 * A field's `field_type` of 'select' is how a Publisher offers preset
	 * style/tone choices on a library prompt — `options` is the list the
	 * correspondent/editor picks from when filling the prompt in.
	 *
	 * @param mixed  $raw  Raw `fields` param.
	 * @param string $type Sanitized prompt type.
	 * @return array<int, array{key:string, label:string, field_type:string, options?:array<int,string>}>
	 */
	protected static function sanitize_fields( $raw, $type ) {
		if ( 'library' !== $type || ! is_array( $raw ) ) {
			return array();
		}

		$clean = array();
		$seen  = array();

		foreach ( array_slice( $raw, 0, 20 ) as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$key = sanitize_key( (string) ( $field['key'] ?? '' ) );
			if ( '' === $key || isset( $seen[ $key ] ) ) {
				continue; // Blank or duplicate key — unusable as a shortcode, so drop it rather than save garbage.
			}
			$label = sanitize_text_field( (string) ( $field['label'] ?? '' ) );
			if ( '' === $label ) {
				continue;
			}
			$field_type = sanitize_key( (string) ( $field['field_type'] ?? 'text' ) );
			if ( ! in_array( $field_type, self::FIELD_TYPES, true ) ) {
				$field_type = 'text';
			}

			$entry = array(
				'key'        => $key,
				'label'      => $label,
				'field_type' => $field_type,
			);

			if ( 'select' === $field_type ) {
				$options = array();
				if ( isset( $field['options'] ) && is_array( $field['options'] ) ) {
					foreach ( array_slice( $field['options'], 0, 20 ) as $option ) {
						$option = sanitize_text_field( (string) $option );
						if ( '' !== $option ) {
							$options[] = $option;
						}
					}
				}
				$entry['options'] = $options;
			}

			$seen[ $key ] = true;
			$clean[]      = $entry;
		}

		return $clean;
	}

	/**
	 * A library prompt's body is only useful if it actually references the
	 * custom fields the Publisher just defined for it — catches the easy
	 * mistake of adding a field but forgetting its {key} shortcode in the
	 * template (or vice versa), before it reaches a confused correspondent.
	 * This is a soft check: it only warns when NONE of the defined fields
	 * appear in the body at all, since a field used conditionally in only
	 * part of a template is still a legitimate design.
	 *
	 * @param string $type   Sanitized prompt type.
	 * @param string $body   Sanitized body.
	 * @param array  $fields Sanitized fields.
	 * @return true|WP_Error
	 */
	protected static function validate_body_placeholders( $type, $body, $fields ) {
		if ( 'library' !== $type || empty( $fields ) ) {
			return true;
		}
		foreach ( $fields as $field ) {
			if ( false !== strpos( $body, '{' . $field['key'] . '}' ) ) {
				return true; // At least one defined field is actually used — good enough.
			}
		}
		return new WP_Error(
			'nros_bad_request',
			'প্রোম্পট টেক্সটে অন্তত একটি কাস্টম ফিল্ড শর্টকোড (যেমন {' . $fields[0]['key'] . '}) ব্যবহার করুন, যাতে ফিল্ড পূরণ করলে তা প্রোম্পটে বসে।',
			array( 'status' => 400 )
		);
	}

	// -------------------------------------------------------------------------
	// One-time legacy migration
	// -------------------------------------------------------------------------

	/**
	 * Converts round 8's `nros_summary_prompt_template` (single string) and
	 * `nros_staff_prompt_templates` (JSON array) options into rows of the
	 * new nros_prompts table, preserving anything the Publisher already
	 * configured rather than resetting them to the built-in defaults. Runs
	 * at most once, guarded by its own flag — independent of
	 * NROS_DB_VERSION — so it can never duplicate rows on a later, unrelated
	 * version bump.
	 */
	public static function migrate_legacy_options() {
		if ( get_option( 'nros_prompts_migrated' ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'nros_prompts';
		$now   = current_time( 'mysql' );

		// Only migrate a summary template the Publisher actually saved —
		// round 8's built-in default is recreated as a seed below if
		// nothing was ever saved, rather than migrating the hardcoded
		// English-named default as if it were a deliberate choice.
		$summary_raw = get_option( 'nros_summary_prompt_template', null );
		if ( is_string( $summary_raw ) && '' !== trim( $summary_raw ) ) {
			$wpdb->insert(
				$table,
				array(
					'type'          => 'news',
					'label'         => 'সারাংশ (এ.আই)',
					'body'          => $summary_raw,
					'visible_roles' => wp_json_encode( array( 'correspondent' ) ),
					'fields'        => wp_json_encode( array() ),
					'sort_order'    => 0,
					'created_by'    => null,
					'created_at'    => $now,
					'updated_at'    => $now,
				)
			);
		}

		$staff_raw = get_option( 'nros_staff_prompt_templates', null );
		if ( is_string( $staff_raw ) && '' !== $staff_raw ) {
			$decoded = json_decode( $staff_raw, true );
			if ( is_array( $decoded ) ) {
				$order = 1;
				foreach ( $decoded as $tpl ) {
					if ( ! is_array( $tpl ) ) {
						continue;
					}
					$label = isset( $tpl['label'] ) ? sanitize_text_field( (string) $tpl['label'] ) : '';
					$body  = isset( $tpl['body'] ) ? (string) $tpl['body'] : '';
					if ( '' === $label || '' === trim( $body ) ) {
						continue;
					}
					$wpdb->insert(
						$table,
						array(
							'type'          => 'news',
							'label'         => $label,
							'body'          => $body,
							'visible_roles' => wp_json_encode( array( 'editor', 'publisher' ) ),
							'fields'        => wp_json_encode( array() ),
							'sort_order'    => $order++,
							'created_by'    => null,
							'created_at'    => $now,
							'updated_at'    => $now,
						)
					);
				}
			}
		}

		// Nothing was ever configured on this install (fresh install, or an
		// upgrade from before round 8 even existed) — seed one usable
		// correspondent-facing summary prompt so the feature isn't blank on
		// day one, matching round 8's own built-in default text.
		if ( ( ! is_string( $summary_raw ) || '' === trim( (string) $summary_raw ) )
			&& ( ! is_string( $staff_raw ) || '' === $staff_raw ) ) {
			$wpdb->insert(
				$table,
				array(
					'type'          => 'news',
					'label'         => 'সারাংশ (এ.আই)',
					'body'          => "তুমি একজন অভিজ্ঞ বাংলা সংবাদ এডিটর। নিচের নিউজ থেকে ৩-৪ লাইনের একটি সংক্ষিপ্ত, তথ্যবহুল সারাংশ লিখো — পাঠক পুরো খবর না পড়েও মূল বিষয়টা জানতে পারবে এমনভাবে:\n\nশিরোনাম: {title}\n\nবিষয়বস্তু:\n{body}",
					'visible_roles' => wp_json_encode( array( 'correspondent' ) ),
					'fields'        => wp_json_encode( array() ),
					'sort_order'    => 0,
					'created_by'    => null,
					'created_at'    => $now,
					'updated_at'    => $now,
				)
			);
		}

		update_option( 'nros_prompts_migrated', 1 );
	}
}
