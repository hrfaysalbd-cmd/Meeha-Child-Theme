<?php
/**
 * Settings framework — one admin page "মিহা থিম" (slug `meeha-theme`) with tabs; one option array per tab
 * (`meeha_opt_{tab}`); per-tab and per-field capabilities; fields the user may not change render read-only.
 *
 * Public API (CONTRACT §4):
 *   meeha_register_settings_tab( $id, $args )
 *   meeha_opt( $tab, $key = null, $default = null )
 *   meeha_opt_update( $tab, $values )
 * Extras: meeha_settings_url( $tab ), meeha_settings_cap( $cap ), meeha_settings_can_edit_field( $tab, $field ),
 *   field type `heading` (display only), tab args `form` (false = render callback prints its own forms) and
 *   `autoload` (bool).
 *
 * Capabilities: 'design' → manage_meeha_design (administrator, meeha_publisher);
 *               'editorial' → manage_meeha_editorial (administrator, meeha_publisher, editor);
 *               'admin' → manage_options.
 * Viewing: a 'design' tab is visible (read-only) to editorial users; an 'admin' tab is visible (read-only) to
 * design users; an 'editorial' tab needs manage_meeha_editorial.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

$GLOBALS['meeha_settings_tabs']  = isset( $GLOBALS['meeha_settings_tabs'] ) ? $GLOBALS['meeha_settings_tabs'] : array();
$GLOBALS['meeha_opt_cache']      = array();

/**
 * Register (or replace) a settings tab.
 *
 * @param string $id   Tab id (option = meeha_opt_{id}).
 * @param array  $args title, order, cap, fields, render, sanitize, desc, form, autoload.
 */
function meeha_register_settings_tab( $id, $args ) {
	$id   = sanitize_key( $id );
	$args = wp_parse_args(
		(array) $args,
		array(
			'title'    => $id,
			'order'    => 50,
			'cap'      => 'design',
			'fields'   => array(),
			'render'   => null,
			'sanitize' => null,
			'desc'     => '',
			'form'     => true,
			'autoload' => in_array( $id, array( 'header', 'footer', 'performance' ), true ),
		)
	);
	$fields = array();
	foreach ( (array) $args['fields'] as $f ) {
		if ( empty( $f['key'] ) && ( empty( $f['type'] ) || 'heading' !== $f['type'] ) ) {
			continue;
		}
		$f = wp_parse_args(
			$f,
			array(
				'key'      => '',
				'type'     => 'text',
				'label'    => '',
				'default'  => null,
				'desc'     => '',
				'options'  => array(),
				'cap'      => '',
				'sanitize' => null,
			)
		);
		if ( 'heading' === $f['type'] && '' === $f['key'] ) {
			$f['key'] = '_h' . count( $fields );
		}
		if ( null === $f['default'] ) {
			$f['default'] = meeha_settings_type_default( $f['type'] );
		}
		$fields[ $f['key'] ] = $f;
	}
	$args['fields']                         = $fields;
	$args['id']                             = $id;
	$GLOBALS['meeha_settings_tabs'][ $id ] = $args;
	unset( $GLOBALS['meeha_opt_cache'][ $id ] );
}

/**
 * Default empty value per type.
 *
 * @param string $type Field type.
 * @return mixed
 */
function meeha_settings_type_default( $type ) {
	switch ( $type ) {
		case 'checkbox':
		case 'number':
		case 'image':
		case 'category':
		case 'post_id':
			return 0;
		case 'categories':
		case 'repeater':
		case 'sortable':
			return array();
		default:
			return '';
	}
}

/**
 * All registered tabs, sorted by order.
 *
 * @return array
 */
function meeha_settings_tabs() {
	static $fired = false;
	if ( ! $fired ) {
		$fired = true;
		/**
		 * Register settings tabs late (alternative to registering at include time).
		 */
		do_action( 'meeha_register_settings' );
	}
	$tabs = $GLOBALS['meeha_settings_tabs'];
	uasort(
		$tabs,
		function ( $a, $b ) {
			return (int) $a['order'] - (int) $b['order'];
		}
	);
	return $tabs;
}

/**
 * One tab's definition or null.
 *
 * @param string $id Tab id.
 * @return array|null
 */
function meeha_settings_tab( $id ) {
	$tabs = meeha_settings_tabs();
	return isset( $tabs[ $id ] ) ? $tabs[ $id ] : null;
}

/**
 * Registered defaults of a tab.
 *
 * @param string $id Tab id.
 * @return array
 */
function meeha_settings_defaults( $id ) {
	$tab = meeha_settings_tab( $id );
	$out = array();
	if ( $tab ) {
		foreach ( $tab['fields'] as $k => $f ) {
			if ( 'heading' !== $f['type'] ) {
				$out[ $k ] = $f['default'];
			}
		}
	}
	return $out;
}

/**
 * Read a theme option (merged with registered defaults).
 *
 * @param string      $tab     Tab id.
 * @param string|null $key     Key or null for the whole array.
 * @param mixed       $default Used when the key is neither stored nor registered.
 * @return mixed
 */
function meeha_opt( $tab, $key = null, $default = null ) {
	if ( ! isset( $GLOBALS['meeha_opt_cache'][ $tab ] ) ) {
		$stored = get_option( 'meeha_opt_' . $tab, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$merged = array_merge( meeha_settings_defaults( $tab ), $stored );
		$def    = meeha_settings_tab( $tab );
		if ( $def ) {
			foreach ( $def['fields'] as $k => $f ) {
				if ( 'sortable' === $f['type'] ) {
					$merged[ $k ] = meeha_settings_merge_sortable( isset( $stored[ $k ] ) ? $stored[ $k ] : array(), (array) $f['default'] );
				}
			}
		}
		$GLOBALS['meeha_opt_cache'][ $tab ] = $merged;
	}
	$all = $GLOBALS['meeha_opt_cache'][ $tab ];
	if ( null === $key ) {
		return $all;
	}
	return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
}

/**
 * Merge values into a tab option (no sanitisation — callers pass clean data).
 *
 * @param string $tab    Tab id.
 * @param array  $values Values.
 */
function meeha_opt_update( $tab, $values ) {
	$tab    = sanitize_key( $tab );
	$stored = get_option( 'meeha_opt_' . $tab, array() );
	$stored = is_array( $stored ) ? $stored : array();
	$new    = array_merge( $stored, (array) $values );
	$def    = meeha_settings_tab( $tab );
	$auto   = $def ? (bool) $def['autoload'] : in_array( $tab, array( 'header', 'footer', 'performance' ), true );
	if ( false === get_option( 'meeha_opt_' . $tab, false ) ) {
		add_option( 'meeha_opt_' . $tab, $new, '', $auto ? 'yes' : 'no' );
	} else {
		update_option( 'meeha_opt_' . $tab, $new );
	}
	unset( $GLOBALS['meeha_opt_cache'][ $tab ] );
}

/**
 * Merge a stored sortable list with its default item set: stored order first (known ids only), then new items.
 *
 * @param array $stored  Stored items.
 * @param array $default Default items.
 * @return array
 */
function meeha_settings_merge_sortable( $stored, $default ) {
	$by_id = array();
	foreach ( (array) $default as $item ) {
		if ( isset( $item['id'] ) ) {
			$by_id[ (string) $item['id'] ] = wp_parse_args( $item, array( 'label' => '', 'enabled' => 1 ) );
		}
	}
	$out = array();
	foreach ( (array) $stored as $item ) {
		$id = isset( $item['id'] ) ? (string) $item['id'] : '';
		if ( '' === $id || ! isset( $by_id[ $id ] ) || isset( $out[ $id ] ) ) {
			continue;
		}
		$out[ $id ] = array_merge( $by_id[ $id ], $item, array( 'enabled' => empty( $item['enabled'] ) ? 0 : 1 ) );
		if ( '' === trim( (string) $out[ $id ]['label'] ) ) {
			$out[ $id ]['label'] = $by_id[ $id ]['label'];
		}
	}
	foreach ( $by_id as $id => $item ) {
		if ( ! isset( $out[ $id ] ) ) {
			$out[ $id ] = $item;
		}
	}
	return array_values( $out );
}

/* --------------------------------------------------------------------------
 * Capabilities
 * ------------------------------------------------------------------------ */

/**
 * Map a symbolic cap to a real capability.
 *
 * @param string $cap design|editorial|admin|<real cap>.
 * @return string
 */
function meeha_settings_cap( $cap ) {
	switch ( $cap ) {
		case 'design':
			return 'manage_meeha_design';
		case 'editorial':
			return 'manage_meeha_editorial';
		case 'admin':
		case '':
			return 'manage_options';
		default:
			return $cap;
	}
}

/**
 * Can the current user see a tab (possibly read-only)?
 *
 * @param array $tab Tab definition.
 * @return bool
 */
function meeha_settings_can_view_tab( $tab ) {
	$cap = $tab['cap'];
	if ( 'admin' === $cap ) {
		$ok = current_user_can( 'manage_options' ) || current_user_can( 'manage_meeha_design' );
	} elseif ( 'design' === $cap || 'editorial' === $cap ) {
		$ok = current_user_can( 'manage_meeha_editorial' ) || current_user_can( 'manage_meeha_design' );
	} else {
		$ok = current_user_can( meeha_settings_cap( $cap ) );
	}
	if ( ! $ok ) {
		foreach ( $tab['fields'] as $f ) {
			if ( 'heading' !== $f['type'] && meeha_settings_can_edit_field( $tab, $f ) ) {
				$ok = true;
				break;
			}
		}
	}
	return (bool) apply_filters( 'meeha_settings_can_view_tab', $ok, $tab );
}

/**
 * Can the current user change a field?
 *
 * @param array $tab   Tab definition.
 * @param array $field Field definition.
 * @return bool
 */
function meeha_settings_can_edit_field( $tab, $field ) {
	// Raw ad/script code: users without unfiltered_html see it read-only and the stored value is kept
	// (saving would otherwise silently strip an administrator's <script>).
	if ( isset( $field['type'] ) && 'code' === $field['type'] && ! current_user_can( 'unfiltered_html' ) ) {
		return false;
	}
	$cap = ! empty( $field['cap'] ) ? $field['cap'] : $tab['cap'];
	return current_user_can( meeha_settings_cap( $cap ) );
}

/**
 * Whether the current user can edit anything on a tab.
 *
 * @param array $tab Tab.
 * @return bool
 */
function meeha_settings_can_edit_tab( $tab ) {
	if ( current_user_can( meeha_settings_cap( $tab['cap'] ) ) ) {
		return true;
	}
	foreach ( $tab['fields'] as $f ) {
		if ( 'heading' !== $f['type'] && meeha_settings_can_edit_field( $tab, $f ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Administrators always hold the theme caps (even before the activation grant ran).
 */
add_filter(
	'user_has_cap',
	function ( $allcaps ) {
		if ( ! empty( $allcaps['manage_options'] ) ) {
			$allcaps['manage_meeha_design']    = true;
			$allcaps['manage_meeha_editorial'] = true;
		}
		return $allcaps;
	}
);

/**
 * Grant the theme capabilities to roles (activation / version bump / publisher role appearing later).
 *
 * @return array Roles touched.
 */
function meeha_grant_caps() {
	$map     = array(
		'administrator'   => array( 'manage_meeha_design', 'manage_meeha_editorial' ),
		'meeha_publisher' => array( 'manage_meeha_design', 'manage_meeha_editorial' ),
		'editor'          => array( 'manage_meeha_editorial' ),
	);
	$touched = array();
	foreach ( $map as $role_name => $caps ) {
		$role = get_role( $role_name );
		if ( ! $role ) {
			continue;
		}
		foreach ( $caps as $cap ) {
			if ( ! $role->has_cap( $cap ) ) {
				$role->add_cap( $cap );
			}
		}
		$touched[] = $role_name;
	}
	return $touched;
}

add_action(
	'admin_init',
	function () {
		$pub = get_role( 'meeha_publisher' );
		if ( $pub && ! $pub->has_cap( 'manage_meeha_design' ) ) {
			meeha_grant_caps();
		}
	}
);

/* --------------------------------------------------------------------------
 * Sanitising
 * ------------------------------------------------------------------------ */

/**
 * Sanitize one field value.
 *
 * @param array $field Field.
 * @param mixed $value Raw value (unslashed); null when not posted.
 * @param mixed $old   Previous stored value.
 * @return mixed
 */
function meeha_settings_sanitize_field( $field, $value, $old = null ) {
	if ( ! empty( $field['sanitize'] ) && is_callable( $field['sanitize'] ) ) {
		return call_user_func( $field['sanitize'], $value, $field, $old );
	}
	switch ( $field['type'] ) {
		case 'text':
			return sanitize_text_field( (string) $value );
		case 'textarea':
			return ! empty( $field['html'] ) ? wp_kses_post( (string) $value ) : sanitize_textarea_field( (string) $value );
		case 'url':
			return esc_url_raw( trim( (string) $value ) );
		case 'email':
			return sanitize_email( (string) $value );
		case 'number':
			$is_float = isset( $field['step'] ) && (float) $field['step'] !== floor( (float) $field['step'] );
			$v        = $is_float ? (float) $value : (int) round( (float) $value );
			if ( '' === $value || null === $value ) {
				$v = $field['default'];
			}
			if ( isset( $field['min'] ) ) {
				$v = max( $field['min'], $v );
			}
			if ( isset( $field['max'] ) ) {
				$v = min( $field['max'], $v );
			}
			return $v;
		case 'checkbox':
			return empty( $value ) ? 0 : 1;
		case 'select':
		case 'radio':
			$opts = (array) $field['options'];
			return array_key_exists( (string) $value, $opts ) ? (string) $value : $field['default'];
		case 'color':
			$c = sanitize_hex_color( (string) $value );
			return $c ? $c : '';
		case 'image':
			$id = absint( $value );
			return ( $id && 'attachment' === get_post_type( $id ) ) ? $id : 0;
		case 'category':
			$id = absint( $value );
			return ( $id && term_exists( $id, 'category' ) ) ? $id : 0;
		case 'categories':
			$out = array();
			foreach ( (array) $value as $v ) {
				$id = absint( $v );
				if ( $id && ! in_array( $id, $out, true ) && term_exists( $id, 'category' ) ) {
					$out[] = $id;
				}
			}
			return $out;
		case 'post_id':
			$id = absint( $value );
			return ( $id && get_post( $id ) ) ? $id : 0;
		case 'code':
			return current_user_can( 'unfiltered_html' ) ? trim( (string) $value ) : wp_kses_post( trim( (string) $value ) );
		case 'repeater':
			$rows = array();
			$max  = isset( $field['max'] ) ? (int) $field['max'] : 50;
			foreach ( (array) $value as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$clean = array();
				$empty = true;
				foreach ( (array) $field['fields'] as $sub ) {
					$sub   = wp_parse_args( $sub, array( 'key' => '', 'type' => 'text', 'default' => null, 'options' => array(), 'sanitize' => null ) );
					if ( null === $sub['default'] ) {
						$sub['default'] = meeha_settings_type_default( $sub['type'] );
					}
					$v = meeha_settings_sanitize_field( $sub, isset( $row[ $sub['key'] ] ) ? $row[ $sub['key'] ] : null );
					if ( 'checkbox' !== $sub['type'] && 'select' !== $sub['type'] && ! empty( $v ) ) {
						$empty = false;
					}
					$clean[ $sub['key'] ] = $v;
				}
				if ( ! $empty ) {
					$rows[] = $clean;
				}
				if ( count( $rows ) >= $max ) {
					break;
				}
			}
			return $rows;
		case 'sortable':
			$known = array();
			foreach ( (array) $field['default'] as $item ) {
				if ( isset( $item['id'] ) ) {
					$known[ (string) $item['id'] ] = $item;
				}
			}
			$out = array();
			foreach ( (array) $value as $item ) {
				$id = isset( $item['id'] ) ? sanitize_key( (string) $item['id'] ) : '';
				if ( '' === $id || ! isset( $known[ $id ] ) || isset( $out[ $id ] ) ) {
					continue;
				}
				$row = array(
					'id'      => $id,
					'label'   => isset( $item['label'] ) ? sanitize_text_field( (string) $item['label'] ) : $known[ $id ]['label'],
					'enabled' => empty( $item['enabled'] ) ? 0 : 1,
				);
				foreach ( $item as $k => $v ) { // extra scalar per-item props (e.g. count).
					if ( ! isset( $row[ $k ] ) && is_scalar( $v ) ) {
						$row[ sanitize_key( $k ) ] = sanitize_text_field( (string) $v );
					}
				}
				$out[ $id ] = $row;
			}
			return array_values( meeha_settings_merge_sortable( array_values( $out ), (array) $field['default'] ) );
		default:
			return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
	}
}

/**
 * Sanitize a whole tab submission. Fields the user cannot edit keep their stored value.
 *
 * @param array $tab Tab definition.
 * @param array $raw Raw posted array (unslashed).
 * @param array $old Stored array.
 * @return array
 */
function meeha_settings_sanitize_tab( $tab, $raw, $old ) {
	$clean = is_array( $old ) ? $old : array();
	foreach ( $tab['fields'] as $k => $f ) {
		if ( 'heading' === $f['type'] ) {
			continue;
		}
		if ( ! meeha_settings_can_edit_field( $tab, $f ) ) {
			continue;
		}
		$clean[ $k ] = meeha_settings_sanitize_field( $f, isset( $raw[ $k ] ) ? $raw[ $k ] : null, isset( $old[ $k ] ) ? $old[ $k ] : $f['default'] );
	}
	if ( ! empty( $tab['sanitize'] ) && is_callable( $tab['sanitize'] ) ) {
		$clean = (array) call_user_func( $tab['sanitize'], $clean, $raw, $old );
	}
	return $clean;
}

/**
 * Queue a notice shown after the redirect.
 *
 * @param string $message Message.
 * @param string $type    error|warning|success|info.
 */
function meeha_settings_notice( $message, $type = 'error' ) {
	$GLOBALS['meeha_settings_notices'][] = array( $message, $type );
}

/* --------------------------------------------------------------------------
 * Admin page
 * ------------------------------------------------------------------------ */

/**
 * URL of a settings tab.
 *
 * @param string $tab Tab id.
 * @return string
 */
function meeha_settings_url( $tab = '' ) {
	$args = array( 'page' => 'meeha-theme' );
	if ( $tab ) {
		$args['tab'] = $tab;
	}
	return add_query_arg( $args, admin_url( 'admin.php' ) );
}

/**
 * Admin menu "মিহা থিম": registered at priority 9 so the top-level page exists BEFORE core's
 * _add_post_type_submenus() (priority 10) attaches the CPTs that live under it (meeha_edit_req,
 * meeha_subscriber). Otherwise add_submenu_page() never creates the automatic "parent" first item and the
 * top-level link points at the first CPT list — the settings page became unreachable from the sidebar.
 * Items: "সেটিংস" (slug meeha-theme) + one item per tab the user may view (tab order); legacy pages follow.
 */
function meeha_settings_admin_menu() {
	$hook = add_menu_page( 'মিহা থিম', 'মিহা থিম', 'manage_meeha_editorial', 'meeha-theme', 'meeha_settings_page', 'dashicons-admin-appearance', 59 );
	add_submenu_page( 'meeha-theme', 'মিহা থিম — সেটিংস', 'সেটিংস', 'manage_meeha_editorial', 'meeha-theme', 'meeha_settings_page' );
	foreach ( meeha_settings_tabs() as $id => $t ) {
		if ( meeha_settings_can_view_tab( $t ) ) {
			add_submenu_page( 'meeha-theme', $t['title'], $t['title'], 'manage_meeha_editorial', meeha_settings_menu_slug( $id ) );
		}
	}
	add_action(
		'admin_enqueue_scripts',
		function ( $h ) use ( $hook ) {
			if ( $h !== $hook ) {
				return;
			}
			wp_enqueue_media();
			wp_enqueue_script( 'jquery-ui-sortable' );
		}
	);
}
add_action( 'admin_menu', 'meeha_settings_admin_menu', 9 );

/**
 * Sidebar slug of a tab item (a plain admin URL, relative to wp-admin/).
 *
 * @param string $tab Tab id.
 * @return string
 */
function meeha_settings_menu_slug( $tab ) {
	return 'admin.php?page=meeha-theme&tab=' . sanitize_key( $tab );
}

/**
 * Highlight the current tab (or "সেটিংস" when no tab is given) in the sidebar.
 */
add_filter(
	'parent_file',
	function ( $parent_file ) {
		global $plugin_page;
		return 'meeha-theme' === $plugin_page ? 'meeha-theme' : $parent_file;
	}
);
add_filter(
	'submenu_file',
	function ( $submenu_file ) {
		global $plugin_page;
		if ( 'meeha-theme' !== $plugin_page ) {
			return $submenu_file;
		}
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$def = $tab ? meeha_settings_tab( $tab ) : null;
		return ( $def && meeha_settings_can_view_tab( $def ) ) ? meeha_settings_menu_slug( $tab ) : 'meeha-theme';
	}
);

add_action( 'admin_post_meeha_save_settings', 'meeha_settings_handle_save' );

/**
 * Save handler (admin-post.php?action=meeha_save_settings).
 */
function meeha_settings_handle_save() {
	$tab_id = isset( $_POST['meeha_tab'] ) ? sanitize_key( wp_unslash( $_POST['meeha_tab'] ) ) : '';
	$tab    = meeha_settings_tab( $tab_id );
	if ( ! $tab ) {
		wp_die( esc_html( 'অজানা ট্যাব।' ), 400 );
	}
	check_admin_referer( 'meeha_settings_' . $tab_id );
	if ( ! meeha_settings_can_edit_tab( $tab ) ) {
		wp_die( esc_html( 'এই সেটিং বদলানোর অনুমতি আপনার নেই।' ), 403 );
	}
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised per field below.
	$raw   = isset( $_POST[ 'meeha_opt_' . $tab_id ] ) ? wp_unslash( $_POST[ 'meeha_opt_' . $tab_id ] ) : array();
	$raw   = is_array( $raw ) ? $raw : array();
	$old   = get_option( 'meeha_opt_' . $tab_id, array() );
	$old   = is_array( $old ) ? $old : array();
	$clean = meeha_settings_sanitize_tab( $tab, $raw, $old );

	if ( false === get_option( 'meeha_opt_' . $tab_id, false ) ) {
		add_option( 'meeha_opt_' . $tab_id, $clean, '', $tab['autoload'] ? 'yes' : 'no' );
	} else {
		update_option( 'meeha_opt_' . $tab_id, $clean );
	}
	unset( $GLOBALS['meeha_opt_cache'][ $tab_id ] );

	/**
	 * After a tab was saved.
	 *
	 * @param string $tab_id Tab.
	 * @param array  $clean  New value.
	 * @param array  $old    Previous value.
	 */
	do_action( 'meeha_settings_saved', $tab_id, $clean, $old );
	if ( 'tools' !== $tab_id ) {
		do_action( 'litespeed_purge_all' ); // page cache: new counts/labels must show at once (no-op without LiteSpeed).
	}

	$notices = isset( $GLOBALS['meeha_settings_notices'] ) ? $GLOBALS['meeha_settings_notices'] : array();
	if ( $notices ) {
		set_transient( 'meeha_settings_notices_' . get_current_user_id(), $notices, 120 );
	}
	wp_safe_redirect( add_query_arg( 'updated', '1', meeha_settings_url( $tab_id ) ) );
	exit;
}

/**
 * Render the settings page.
 */
function meeha_settings_page() {
	$tabs    = array_filter( meeha_settings_tabs(), 'meeha_settings_can_view_tab' );
	$current = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	if ( ! isset( $tabs[ $current ] ) ) {
		$current = $tabs ? (string) key( $tabs ) : '';
	}
	echo '<div class="wrap meeha-settings">';
	echo '<h1>মিহা থিম</h1>';
	if ( ! $tabs ) {
		echo '<p>কোনো সেটিং দেখার অনুমতি আপনার নেই।</p></div>';
		return;
	}
	echo '<nav class="nav-tab-wrapper" aria-label="সেটিং ট্যাব">';
	foreach ( $tabs as $id => $t ) {
		printf(
			'<a href="%s" class="nav-tab%s"%s>%s%s</a>',
			esc_url( meeha_settings_url( $id ) ),
			$id === $current ? ' nav-tab-active' : '',
			$id === $current ? ' aria-current="page"' : '',
			esc_html( $t['title'] ),
			meeha_settings_can_edit_tab( $t ) ? '' : ' <span class="dashicons dashicons-lock" aria-label="শুধু দেখা যাবে"></span>'
		);
	}
	echo '</nav>';

	$notices = get_transient( 'meeha_settings_notices_' . get_current_user_id() );
	if ( $notices ) {
		delete_transient( 'meeha_settings_notices_' . get_current_user_id() );
		foreach ( (array) $notices as $n ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $n[1] ), esc_html( $n[0] ) );
		}
	}
	if ( isset( $_GET['updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		echo '<div class="notice notice-success is-dismissible"><p>সেটিং সংরক্ষণ করা হয়েছে।</p></div>';
	}

	$tab      = $tabs[ $current ];
	$values   = meeha_opt( $current );
	$can_edit = meeha_settings_can_edit_tab( $tab );
	if ( ! $can_edit ) {
		echo '<div class="notice notice-info inline"><p>এই ট্যাবের সেটিং আপনি শুধু দেখতে পারবেন; বদলাতে পারবেন প্রশাসক বা প্রকাশক।</p></div>';
	}
	if ( $tab['desc'] ) {
		echo '<p class="description meeha-settings__desc">' . wp_kses_post( $tab['desc'] ) . '</p>';
	}

	$ctx = array(
		'tab'      => $tab,
		'name'     => 'meeha_opt_' . $current,
		'can_edit' => $can_edit,
	);

	if ( $tab['form'] ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="meeha-settings__form">';
		echo '<input type="hidden" name="action" value="meeha_save_settings">';
		echo '<input type="hidden" name="meeha_tab" value="' . esc_attr( $current ) . '">';
		wp_nonce_field( 'meeha_settings_' . $current );
	}
	if ( $tab['fields'] ) {
		meeha_settings_render_fields( $tab, $values );
	}
	if ( ! empty( $tab['render'] ) && is_callable( $tab['render'] ) ) {
		call_user_func( $tab['render'], $current, $values, $ctx );
	}
	if ( $tab['form'] ) {
		if ( $can_edit ) {
			submit_button( 'সংরক্ষণ করুন' );
		}
		echo '</form>';
	}
	echo '</div>';
	meeha_settings_print_assets();
}

/**
 * Render the field table of a tab.
 *
 * @param array $tab    Tab.
 * @param array $values Current values.
 */
function meeha_settings_render_fields( $tab, $values ) {
	$name = 'meeha_opt_' . $tab['id'];
	echo '<table class="form-table meeha-fields" role="presentation"><tbody>';
	foreach ( $tab['fields'] as $k => $f ) {
		if ( 'heading' === $f['type'] ) {
			echo '<tr class="meeha-fields__heading"><th colspan="2"><h2>' . esc_html( $f['label'] ) . '</h2>';
			if ( $f['desc'] ) {
				echo '<p class="description">' . wp_kses_post( $f['desc'] ) . '</p>';
			}
			echo '</th></tr>';
			continue;
		}
		$editable = meeha_settings_can_edit_field( $tab, $f );
		$id       = 'meeha-' . $tab['id'] . '-' . $k;
		$value    = array_key_exists( $k, $values ) ? $values[ $k ] : $f['default'];
		echo '<tr' . ( $editable ? '' : ' class="meeha-ro"' ) . '><th scope="row">';
		if ( in_array( $f['type'], array( 'checkbox', 'radio', 'categories', 'sortable', 'repeater', 'image' ), true ) ) {
			echo esc_html( $f['label'] );
		} else {
			echo '<label for="' . esc_attr( $id ) . '">' . esc_html( $f['label'] ) . '</label>';
		}
		echo '</th><td>';
		meeha_settings_render_control( $f, $name . '[' . $k . ']', $id, $value, $editable );
		if ( $f['desc'] ) {
			echo '<p class="description">' . wp_kses_post( $f['desc'] ) . '</p>';
		}
		if ( ! $editable ) {
			echo '<p class="description meeha-ro__note"><span class="dashicons dashicons-lock"></span> শুধু দেখা যাবে</p>';
		}
		echo '</td></tr>';
	}
	echo '</tbody></table>';
}

/**
 * Render one control.
 *
 * @param array  $f        Field.
 * @param string $name     Input name.
 * @param string $id       Input id.
 * @param mixed  $value    Value.
 * @param bool   $editable Editable.
 */
function meeha_settings_render_control( $f, $name, $id, $value, $editable = true ) {
	$dis = $editable ? '' : ' disabled';
	$ph  = isset( $f['placeholder'] ) ? ' placeholder="' . esc_attr( $f['placeholder'] ) . '"' : '';
	switch ( $f['type'] ) {
		case 'textarea':
			printf( '<textarea class="large-text" rows="%d" id="%s" name="%s"%s%s>%s</textarea>', isset( $f['rows'] ) ? (int) $f['rows'] : 4, esc_attr( $id ), esc_attr( $name ), $ph, $dis, esc_textarea( (string) $value ) );
			break;
		case 'code':
			printf( '<textarea class="large-text code meeha-code" rows="%d" id="%s" name="%s" spellcheck="false"%s>%s</textarea>', isset( $f['rows'] ) ? (int) $f['rows'] : 6, esc_attr( $id ), esc_attr( $name ), $dis, esc_textarea( (string) $value ) );
			if ( $editable && ! current_user_can( 'unfiltered_html' ) ) {
				echo '<p class="description">আপনার অ্যাকাউন্টে কাঁচা HTML/স্ক্রিপ্ট সংরক্ষণের অনুমতি নেই — সংরক্ষণের সময় স্ক্রিপ্ট বাদ যাবে।</p>';
			}
			break;
		case 'url':
		case 'email':
		case 'text':
			printf( '<input type="%s" class="regular-text" id="%s" name="%s" value="%s"%s%s>', 'text' === $f['type'] ? 'text' : esc_attr( $f['type'] ), esc_attr( $id ), esc_attr( $name ), esc_attr( (string) $value ), $ph, $dis );
			break;
		case 'number':
			printf(
				'<input type="number" class="small-text" id="%s" name="%s" value="%s"%s%s%s%s>',
				esc_attr( $id ),
				esc_attr( $name ),
				esc_attr( (string) $value ),
				isset( $f['min'] ) ? ' min="' . esc_attr( $f['min'] ) . '"' : '',
				isset( $f['max'] ) ? ' max="' . esc_attr( $f['max'] ) . '"' : '',
				isset( $f['step'] ) ? ' step="' . esc_attr( $f['step'] ) . '"' : '',
				$dis
			);
			if ( ! empty( $f['unit'] ) ) {
				echo ' <span>' . esc_html( $f['unit'] ) . '</span>';
			}
			break;
		case 'checkbox':
			printf( '<label><input type="checkbox" id="%s" name="%s" value="1"%s%s> %s</label>', esc_attr( $id ), esc_attr( $name ), checked( ! empty( $value ), true, false ), $dis, esc_html( isset( $f['text'] ) ? $f['text'] : 'চালু' ) );
			break;
		case 'select':
			printf( '<select id="%s" name="%s"%s>', esc_attr( $id ), esc_attr( $name ), $dis );
			foreach ( (array) $f['options'] as $k => $label ) {
				printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( (string) $value, (string) $k, false ), esc_html( $label ) );
			}
			echo '</select>';
			break;
		case 'radio':
			echo '<fieldset><legend class="screen-reader-text">' . esc_html( $f['label'] ) . '</legend>';
			foreach ( (array) $f['options'] as $k => $label ) {
				printf( '<label class="meeha-radio"><input type="radio" name="%s" value="%s"%s%s> %s</label> ', esc_attr( $name ), esc_attr( $k ), checked( (string) $value, (string) $k, false ), $dis, esc_html( $label ) );
			}
			echo '</fieldset>';
			break;
		case 'color':
			printf( '<input type="color" id="%s" name="%s" value="%s"%s> <code>%s</code>', esc_attr( $id ), esc_attr( $name ), esc_attr( $value ? $value : '#000000' ), $dis, esc_html( (string) $value ) );
			break;
		case 'image':
			$value = (int) $value;
			$thumb = $value ? wp_get_attachment_image( $value, 'thumbnail', false, array( 'style' => 'max-width:160px;height:auto' ) ) : '';
			echo '<div class="meeha-img" data-meeha-img>';
			printf( '<input type="hidden" name="%s" value="%d"%s>', esc_attr( $name ), (int) $value, $dis );
			echo '<span class="meeha-img__preview">' . ( $thumb ? $thumb : '<em>কোনো ছবি নেই</em>' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core markup.
			if ( $editable ) {
				echo '<button type="button" class="button" data-pick>ছবি বাছুন</button> <button type="button" class="button-link button-link-delete" data-clear>সরান</button>';
			}
			if ( $value ) {
				$meta = wp_get_attachment_metadata( $value );
				$path = get_attached_file( $value );
				$size = ( $path && file_exists( $path ) ) ? size_format( filesize( $path ) ) : '';
				if ( ! empty( $meta['width'] ) ) {
					echo ' <span class="description">' . esc_html( meeha_bn_digits( $meta['width'] . ' × ' . $meta['height'] ) . ( $size ? ' · ' . $size : '' ) ) . '</span>';
				}
			}
			echo '</div>';
			break;
		case 'category':
			wp_dropdown_categories(
				array(
					'name'              => $name,
					'id'                => $id,
					'selected'          => (int) $value,
					'show_option_none'  => '— বাছাই করুন —',
					'option_none_value' => 0,
					'hide_empty'        => false,
					'hierarchical'      => true,
					'class'             => $editable ? '' : 'meeha-disabled',
				)
			);
			if ( ! $editable ) {
				echo '<script>document.getElementById(' . wp_json_encode( $id ) . ').disabled=true;</script>';
			}
			break;
		case 'categories':
			$cats = get_categories( array( 'hide_empty' => false ) );
			$by   = array();
			foreach ( $cats as $c ) {
				$by[ $c->term_id ] = $c->name;
			}
			echo '<div class="meeha-cats" data-meeha-cats>';
			printf( '<input type="hidden" name="%s[]" value=""%s>', esc_attr( $name ), $dis );
			echo '<ol class="meeha-list">';
			foreach ( (array) $value as $cid ) {
				if ( ! isset( $by[ (int) $cid ] ) ) {
					continue;
				}
				printf( '<li><input type="hidden" name="%s[]" value="%d"%s><span>%s</span>', esc_attr( $name ), (int) $cid, $dis, esc_html( $by[ (int) $cid ] ) );
				if ( $editable ) {
					echo meeha_settings_row_buttons(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				echo '</li>';
			}
			echo '</ol>';
			if ( $editable ) {
				printf( '<select class="meeha-cats__add" data-name="%s[]"><option value="">— বিভাগ যোগ করুন —</option>', esc_attr( $name ) );
				foreach ( $by as $cid => $cname ) {
					printf( '<option value="%d">%s</option>', (int) $cid, esc_html( $cname ) );
				}
				echo '</select>';
			}
			echo '</div>';
			break;
		case 'post_id':
			printf( '<input type="number" class="small-text" min="0" id="%s" name="%s" value="%s"%s>', esc_attr( $id ), esc_attr( $name ), esc_attr( (string) $value ), $dis );
			if ( $value && get_post( (int) $value ) ) {
				printf( ' <a href="%s" target="_blank" rel="noopener">%s</a>', esc_url( get_permalink( (int) $value ) ), esc_html( get_the_title( (int) $value ) ) );
			}
			break;
		case 'sortable':
			echo '<ol class="meeha-list meeha-sortable" data-meeha-sortable>';
			$i = 0;
			foreach ( (array) $value as $item ) {
				$base = $name . '[' . $i . ']';
				echo '<li>';
				printf( '<input type="hidden" data-sub="id" name="%s[id]" value="%s"%s>', esc_attr( $base ), esc_attr( $item['id'] ), $dis );
				printf( '<label><input type="checkbox" data-sub="enabled" name="%s[enabled]" value="1"%s%s> <span class="screen-reader-text">চালু</span></label> ', esc_attr( $base ), checked( ! empty( $item['enabled'] ), true, false ), $dis );
				printf( '<input type="text" data-sub="label" class="regular-text" name="%s[label]" value="%s" aria-label="শিরোনাম"%s>', esc_attr( $base ), esc_attr( isset( $item['label'] ) ? $item['label'] : '' ), $dis );
				printf( ' <code>%s</code>', esc_html( $item['id'] ) );
				if ( $editable ) {
					echo meeha_settings_row_buttons( false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				echo '</li>';
				$i++;
			}
			echo '</ol>';
			break;
		case 'repeater':
			$subs = array();
			foreach ( (array) $f['fields'] as $sub ) {
				$sub = wp_parse_args( $sub, array( 'key' => '', 'type' => 'text', 'label' => '', 'default' => null, 'options' => array() ) );
				if ( null === $sub['default'] ) {
					$sub['default'] = meeha_settings_type_default( $sub['type'] );
				}
				$subs[] = $sub;
			}
			echo '<div class="meeha-repeater" data-meeha-repeater data-name="' . esc_attr( $name ) . '">';
			echo '<ol class="meeha-list">';
			$rows = (array) $value;
			foreach ( array_values( $rows ) as $i => $row ) {
				meeha_settings_render_repeater_row( $subs, $name . '[' . $i . ']', $id . '-' . $i, (array) $row, $editable );
			}
			echo '</ol>';
			if ( $editable ) {
				echo '<template>';
				meeha_settings_render_repeater_row( $subs, $name . '[__i__]', $id . '-__i__', array(), true );
				echo '</template>';
				echo '<button type="button" class="button" data-add>+ সারি যোগ করুন</button>';
			}
			echo '</div>';
			break;
		default:
			printf( '<input type="text" class="regular-text" id="%s" name="%s" value="%s"%s>', esc_attr( $id ), esc_attr( $name ), esc_attr( is_scalar( $value ) ? (string) $value : '' ), $dis );
	}
}

/**
 * One repeater row.
 *
 * @param array  $subs     Sub-fields.
 * @param string $base     Base name.
 * @param string $id       Base id.
 * @param array  $row      Row values.
 * @param bool   $editable Editable.
 */
function meeha_settings_render_repeater_row( $subs, $base, $id, $row, $editable ) {
	echo '<li class="meeha-repeater__row"><div class="meeha-repeater__fields">';
	foreach ( $subs as $sub ) {
		$v = array_key_exists( $sub['key'], $row ) ? $row[ $sub['key'] ] : $sub['default'];
		echo '<div class="meeha-repeater__field" data-sub="' . esc_attr( $sub['key'] ) . '"><span class="meeha-repeater__label">' . esc_html( $sub['label'] ) . '</span>';
		meeha_settings_render_control( $sub, $base . '[' . $sub['key'] . ']', $id . '-' . $sub['key'], $v, $editable );
		echo '</div>';
	}
	echo '</div>';
	if ( $editable ) {
		echo meeha_settings_row_buttons(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
	echo '</li>';
}

/**
 * Up / down / remove buttons for list rows.
 *
 * @param bool $remove Include the remove button.
 * @return string
 */
function meeha_settings_row_buttons( $remove = true ) {
	return ' <span class="meeha-rowbtns"><button type="button" class="button-link" data-up aria-label="উপরে">↑</button> <button type="button" class="button-link" data-down aria-label="নিচে">↓</button>' .
		( $remove ? ' <button type="button" class="button-link button-link-delete" data-remove aria-label="সরান">×</button>' : '' ) . '</span>';
}

/**
 * Inline admin CSS/JS for the settings page (admin only; nothing on the front end).
 */
function meeha_settings_print_assets() {
	?>
	<style>
	.meeha-settings .meeha-fields__heading th{padding:24px 0 0}.meeha-settings .meeha-fields__heading h2{margin:0;padding-top:16px;border-top:1px solid #dcdcde}
	.meeha-ro th,.meeha-ro td{opacity:.75}.meeha-ro__note{color:#646970}.meeha-ro__note .dashicons{font-size:16px;width:16px;height:16px}
	.meeha-list{margin:0 0 8px;padding:0;list-style:none;max-width:780px}.meeha-list>li{display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin:0 0 6px;padding:6px 10px;border:1px solid #dcdcde;border-radius:4px;background:#fff}
	.meeha-sortable li{cursor:move}.meeha-rowbtns{margin-left:auto;white-space:nowrap}.meeha-rowbtns .button-link{text-decoration:none;font-size:16px;padding:0 4px}
	.meeha-repeater__row{align-items:flex-start!important}.meeha-repeater__fields{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:8px 12px;flex:1}
	.meeha-repeater__label{display:block;font-size:12px;color:#646970}.meeha-repeater__field input[type=text],.meeha-repeater__field input[type=url],.meeha-repeater__field textarea{width:100%}
	.meeha-img{display:flex;flex-wrap:wrap;align-items:center;gap:10px}.meeha-img__preview img{display:block;border:1px solid #dcdcde;border-radius:4px}
	.meeha-code{font-family:Consolas,Monaco,monospace;font-size:12px}.meeha-radio{margin-right:14px}
	</style>
	<script>
	(function(){
		function renumber(list){
			var wrap=list.closest('[data-meeha-repeater]'),base=wrap?wrap.getAttribute('data-name'):null;
			[].forEach.call(list.children,function(li,i){
				[].forEach.call(li.querySelectorAll('[name]'),function(el){
					if(base){el.name=el.name.replace(/\[(\d+|__i__)\]/,'['+i+']');}
					else if(list.hasAttribute('data-meeha-sortable')){el.name=el.name.replace(/\[\d+\](\[[a-z_]+\])$/,'['+i+']$1');}
				});
			});
		}
		document.addEventListener('click',function(e){
			var b=e.target.closest('[data-up],[data-down],[data-remove],[data-add],[data-pick],[data-clear]');if(!b)return;
			var li=b.closest('li'),list=li?li.parentNode:null;
			if(b.hasAttribute('data-up')&&li.previousElementSibling){list.insertBefore(li,li.previousElementSibling);renumber(list);}
			else if(b.hasAttribute('data-down')&&li.nextElementSibling){list.insertBefore(li.nextElementSibling,li);renumber(list);}
			else if(b.hasAttribute('data-remove')){li.remove();renumber(list);}
			else if(b.hasAttribute('data-add')){var w=b.closest('[data-meeha-repeater]'),t=w.querySelector('template'),ol=w.querySelector('ol');var n=ol.children.length;ol.insertAdjacentHTML('beforeend',t.innerHTML.replace(/__i__/g,n));}
			else if(b.hasAttribute('data-pick')){e.preventDefault();var box=b.closest('[data-meeha-img]');var fr=wp.media({title:'ছবি বাছুন',library:{type:'image'},multiple:false,button:{text:'বেছে নিন'}});fr.on('select',function(){var a=fr.state().get('selection').first().toJSON();box.querySelector('input').value=a.id;var u=(a.sizes&&a.sizes.thumbnail?a.sizes.thumbnail.url:a.url);box.querySelector('.meeha-img__preview').innerHTML='<img src="'+u+'" alt="" style="max-width:160px;height:auto">';});fr.open();}
			else if(b.hasAttribute('data-clear')){var bx=b.closest('[data-meeha-img]');bx.querySelector('input').value='0';bx.querySelector('.meeha-img__preview').innerHTML='<em>কোনো ছবি নেই</em>';}
		});
		document.addEventListener('change',function(e){
			var s=e.target.closest('.meeha-cats__add');if(!s||!s.value)return;
			var ol=s.parentNode.querySelector('ol'),opt=s.options[s.selectedIndex],li=document.createElement('li');
			li.innerHTML='<input type="hidden"><span></span><span class="meeha-rowbtns"><button type="button" class="button-link" data-up aria-label="উপরে">↑</button> <button type="button" class="button-link" data-down aria-label="নিচে">↓</button> <button type="button" class="button-link button-link-delete" data-remove aria-label="সরান">×</button></span>';
			li.firstChild.name=s.getAttribute('data-name');li.firstChild.value=s.value;li.children[1].textContent=opt.textContent;ol.appendChild(li);s.value='';
		});
		if(window.jQuery&&jQuery.fn.sortable){jQuery('[data-meeha-sortable]').sortable({axis:'y',update:function(){renumber(this);}});}
	})();
	</script>
	<?php
}
