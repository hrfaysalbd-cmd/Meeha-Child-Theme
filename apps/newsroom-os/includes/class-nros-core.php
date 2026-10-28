<?php
/**
 * NROS_Core — activation/deactivation, DB tables, private upload dir,
 * CORS handling for the cross-subdomain API, shared permission callbacks,
 * and the at-rest encryption helpers used for withdraw-method account numbers.
 *
 * @package NewsroomOS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NROS_Core {

	/**
	 * Subdomains allowed to call this API with credentials. Filterable so a
	 * staging subdomain or a new app (ads./publisher.) can be added without
	 * touching code.
	 *
	 * @return array
	 */
	public static function allowed_origins() {
		$origins = array(
			'https://correspondent.meeha.news',
			'https://publisher.meeha.news',
			'https://ads.meeha.news',
		);

		return apply_filters( 'nros_allowed_origins', $origins );
	}

	/**
	 * Returns the distinct suspension types currently in force for a
	 * correspondent (e.g. 'account', 'news_submission', 'all_commission',
	 * 'client_commission'), lazily expiring any whose time-boxed window has
	 * already passed so callers never need a separate cron just to keep
	 * status accurate. Used to gate login (require_app_user does not call
	 * this — it's checked explicitly in NROS_Auth::login()) and article
	 * submission.
	 *
	 * @param int $user_id Correspondent user id.
	 * @return string[]
	 */
	public static function active_suspension_types( $user_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'nros_suspensions';
		$now   = current_time( 'mysql' );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'expired' WHERE correspondent_id = %d AND status = 'active' AND ends_at IS NOT NULL AND ends_at <= %s", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$user_id,
				$now
			)
		);

		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT type FROM {$table} WHERE correspondent_id = %d AND status = 'active'", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$user_id
			)
		);

		return $rows ? $rows : array();
	}

	/**
	 * Whether financial figures (ledger/commission totals, not plain
	 * engagement analytics) should be shown to this user: always for
	 * Publisher/Administrator, otherwise only when Publisher has switched
	 * on the "Editor can see financial figures" setting.
	 *
	 * @param WP_User $user User.
	 * @return bool
	 */
	public static function financial_visible_to( $user ) {
		if ( ! $user || is_wp_error( $user ) ) {
			return false;
		}
		if ( user_can( $user, 'nros_publisher' ) || user_can( $user, 'manage_options' ) ) {
			return true;
		}
		return (bool) get_option( 'nros_editor_finance_visible', false );
	}

	/**
	 * Round 10 — the three post-management powers the Publisher can hand to
	 * Editors, with their defaults. Changing the date/author of an article is
	 * routine desk work (so Editors get it unless the Publisher turns it
	 * off), whereas deleting is destructive and starts off Publisher-only.
	 *
	 * @var array<string, bool>
	 */
	const EDITOR_PERMISSION_DEFAULTS = array(
		'delete_posts'  => false,
		'change_date'   => true,
		'change_author' => true,
	);

	/**
	 * The Publisher-configured Editor permissions (option
	 * `nros_editor_permissions`), always returned with every known key
	 * present and boolean — unknown keys stored by hand are dropped so they
	 * can never leak into API output.
	 *
	 * @return array<string, bool>
	 */
	public static function editor_permissions() {
		$stored = get_option( 'nros_editor_permissions', array() );
		$stored = is_array( $stored ) ? $stored : array();
		$out    = array();
		foreach ( self::EDITOR_PERMISSION_DEFAULTS as $key => $default ) {
			$out[ $key ] = array_key_exists( $key, $stored ) ? (bool) $stored[ $key ] : $default;
		}
		return $out;
	}

	/**
	 * Effective post-management permission for one staff user: Publisher
	 * and Administrator always have it; an Editor has it only when the
	 * Publisher allowed it; anyone else (correspondent, subscriber) never.
	 * Enforced server-side on every write — the `can` flags in /me only let
	 * the apps hide controls, they are not the security boundary.
	 *
	 * @param WP_User|WP_Error|null $user User.
	 * @param string                $perm One of delete_posts|change_date|change_author.
	 * @return bool
	 */
	public static function staff_can( $user, $perm ) {
		if ( ! $user || is_wp_error( $user ) ) {
			return false;
		}
		if ( user_can( $user, 'nros_publisher' ) || user_can( $user, 'manage_options' ) ) {
			return true;
		}
		if ( ! user_can( $user, 'edit_others_posts' ) ) {
			return false;
		}
		$perms = self::editor_permissions();
		return ! empty( $perms[ $perm ] );
	}

	/**
	 * Converts ASCII digits to Bengali digits for user-facing strings (API
	 * error messages, relative times) — every number the apps show is in
	 * Bengali, so server-composed text should match.
	 *
	 * @param string|int|float $value Value.
	 * @return string
	 */
	public static function bn_digits( $value ) {
		return strtr( (string) $value, array( '0' => '০', '1' => '১', '2' => '২', '3' => '৩', '4' => '৪', '5' => '৫', '6' => '৬', '7' => '৭', '8' => '৮', '9' => '৯' ) );
	}

	/**
	 * Bengali relative time ("৩ ঘণ্টা আগে") for a site-local MySQL datetime.
	 * WordPress's human_time_diff() is only Bengali when the site locale is
	 * bn_BD, which meeha.news does not run, so this is composed here.
	 *
	 * @param string $mysql_local_date 'Y-m-d H:i:s' in site time.
	 * @return string
	 */
	public static function bn_time_ago( $mysql_local_date ) {
		$then = get_gmt_from_date( $mysql_local_date, 'U' );
		$diff = max( 0, time() - (int) $then );

		if ( $diff < MINUTE_IN_SECONDS ) {
			return 'এইমাত্র';
		}
		$units = array(
			array( YEAR_IN_SECONDS, 'বছর' ),
			array( MONTH_IN_SECONDS, 'মাস' ),
			array( WEEK_IN_SECONDS, 'সপ্তাহ' ),
			array( DAY_IN_SECONDS, 'দিন' ),
			array( HOUR_IN_SECONDS, 'ঘণ্টা' ),
			array( MINUTE_IN_SECONDS, 'মিনিট' ),
		);
		foreach ( $units as $unit ) {
			if ( $diff >= $unit[0] ) {
				return self::bn_digits( (int) floor( $diff / $unit[0] ) ) . ' ' . $unit[1] . ' আগে';
			}
		}
		return 'এইমাত্র';
	}

	/**
	 * Runs once on plugin activation: creates custom tables, the private
	 * (non-public) KYC upload directory, and the Publisher role.
	 *
	 * Round 10: also asks NROS_Videos to flush rewrite rules on the next
	 * `init` (after the meeha_video post type is registered) so
	 * /video/{slug}/ resolves straight away.
	 */
	public static function activate() {
		self::create_tables();
		self::create_private_dir();
		self::register_roles();
		if ( class_exists( 'NROS_Videos' ) ) {
			NROS_Videos::schedule_rewrite_flush();
		}
	}

	/**
	 * Registers the one new custom role this plugin needs: Publisher
	 * (site owner / super-admin for the business side).
	 *
	 * Editor deliberately does NOT get a new custom role — WordPress's own
	 * built-in 'editor' role already has exactly the right capability set
	 * for it (full content/comment/category management, upload files, but
	 * no theme/plugin/server access), so it's reused as-is rather than
	 * duplicated under a new name, the same "reuse over duplication"
	 * principle already applied to IT/Dev (built-in Administrator).
	 *
	 * Publisher = everything Editor/built-in 'editor' can do, PLUS WordPress's
	 * own user-management capabilities (so Publisher can create Editor/Ad
	 * Staff/Correspondent accounts) and a custom `nros_publisher` marker
	 * capability used by require_publisher() for the handful of actions that
	 * are Publisher-only even among staff (serious-case investigation
	 * decisions, suspension appeals, the Editor financial-visibility toggle,
	 * user/role creation). Publisher intentionally does NOT get
	 * manage_options/install_plugins/edit_theme_options/switch_themes — per
	 * the project's final spec, theme/plugin/server access stays optional
	 * and separate, in the IT/Dev (built-in Administrator) context.
	 *
	 * Safe to call on every activation: add_role() is a no-op if the role
	 * already exists, and this re-asserts the capability set in case it
	 * was changed by this plugin's own earlier version.
	 */
	public static function register_roles() {
		$editor_role = get_role( 'editor' );
		$caps        = $editor_role ? $editor_role->capabilities : array();

		$caps = array_merge(
			$caps,
			array(
				'list_users'     => true,
				'create_users'   => true,
				'edit_users'     => true,
				'promote_users'  => true,
				'nros_publisher' => true,
			)
		);

		remove_role( 'meeha_publisher' );
		add_role( 'meeha_publisher', 'Publisher', $caps );
	}

	/**
	 * Intentionally does nothing destructive — deactivating the plugin must
	 * never delete correspondent data, applications, or ledger history.
	 */
	public static function deactivate() {
		// No-op by design.
	}

	/**
	 * Creates/updates all custom tables via dbDelta(), which is safe to call
	 * repeatedly (on every activation) since it only adds what's missing.
	 *
	 * Round 7: `nros_ledger` gains `dedupe_key` plus a `UNIQUE KEY (type,
	 * dedupe_key)` — a DB-level backstop against the "post_payment" row
	 * ever being inserted twice for the same post (e.g. two near-
	 * simultaneous approve requests both racing past the application-level
	 * existence check in NROS_Ledger::maybe_apply_default_payment()/
	 * set_post_payment()). NULL never conflicts with NULL under a MySQL/
	 * MariaDB UNIQUE KEY, so every OTHER ledger type (which leaves
	 * dedupe_key NULL) is completely unaffected — including `view_bonus`,
	 * which legitimately inserts multiple rows per post over time. Only
	 * `post_payment` rows set dedupe_key (to the post's id), so at most one
	 * such row can ever exist per post, full stop — the second of two
	 * racing inserts simply fails (wpdb::insert() returns false without
	 * throwing), leaving the application-level check as the normal path and
	 * this constraint as the guarantee when that check itself races.
	 */
	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$applications   = $wpdb->prefix . 'nros_applications';
		$app_files      = $wpdb->prefix . 'nros_application_files';
		$ledger         = $wpdb->prefix . 'nros_ledger';
		$withdrawals    = $wpdb->prefix . 'nros_withdraw_requests';
		$daily_stats    = $wpdb->prefix . 'nros_daily_stats';
		$ad_clients     = $wpdb->prefix . 'nros_ad_clients';
		$investigations = $wpdb->prefix . 'nros_investigation_cases';
		$suspensions    = $wpdb->prefix . 'nros_suspensions';
		$prompts        = $wpdb->prefix . 'nros_prompts';

		$sql = "CREATE TABLE {$applications} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			full_name VARCHAR(191) NOT NULL,
			email VARCHAR(191) NOT NULL,
			phone VARCHAR(32) NOT NULL,
			division VARCHAR(100) DEFAULT '' ,
			district VARCHAR(100) DEFAULT '',
			upazila VARCHAR(100) DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			created_user_id BIGINT UNSIGNED DEFAULT NULL,
			reject_reason TEXT,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY email (email)
		) {$charset_collate};

		CREATE TABLE {$app_files} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			application_id BIGINT UNSIGNED NOT NULL,
			field VARCHAR(20) NOT NULL,
			stored_name VARCHAR(191) NOT NULL,
			original_name VARCHAR(191) NOT NULL,
			mime_type VARCHAR(100) NOT NULL,
			size_bytes BIGINT UNSIGNED NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY application_id (application_id)
		) {$charset_collate};

		CREATE TABLE {$ledger} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			type VARCHAR(30) NOT NULL,
			amount DECIMAL(12,2) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'approved',
			note TEXT,
			post_id BIGINT UNSIGNED DEFAULT NULL,
			created_by BIGINT UNSIGNED DEFAULT NULL,
			created_at DATETIME NOT NULL,
			dedupe_key BIGINT UNSIGNED DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY status (status),
			KEY post_id (post_id),
			UNIQUE KEY type_dedupe (type, dedupe_key)
		) {$charset_collate};

		CREATE TABLE {$withdrawals} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			amount DECIMAL(12,2) NOT NULL,
			method VARCHAR(20) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			admin_note TEXT,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY status (status)
		) {$charset_collate};

		CREATE TABLE {$daily_stats} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id BIGINT UNSIGNED NOT NULL,
			stat_date DATE NOT NULL,
			views BIGINT UNSIGNED NOT NULL DEFAULT 0,
			reactions BIGINT UNSIGNED NOT NULL DEFAULT 0,
			shares BIGINT UNSIGNED NOT NULL DEFAULT 0,
			comments BIGINT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY post_date (post_id, stat_date),
			KEY stat_date (stat_date)
		) {$charset_collate};

		CREATE TABLE {$ad_clients} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			business_name VARCHAR(191) NOT NULL,
			contact_name VARCHAR(191) DEFAULT '',
			phone VARCHAR(32) DEFAULT '',
			whatsapp VARCHAR(32) DEFAULT '',
			address TEXT,
			visiting_card_id BIGINT UNSIGNED DEFAULT NULL,
			dealing_status VARCHAR(20) DEFAULT NULL,
			referred_by BIGINT UNSIGNED DEFAULT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			notes TEXT,
			created_by BIGINT UNSIGNED DEFAULT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY referred_by (referred_by),
			KEY status (status)
		) {$charset_collate};

		CREATE TABLE {$investigations} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			correspondent_id BIGINT UNSIGNED NOT NULL,
			client_id BIGINT UNSIGNED DEFAULT NULL,
			opened_by BIGINT UNSIGNED NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'open',
			summary TEXT,
			evidence_notes TEXT,
			resolution_notes TEXT,
			resolved_by BIGINT UNSIGNED DEFAULT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			resolved_at DATETIME DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY correspondent_id (correspondent_id),
			KEY client_id (client_id),
			KEY status (status)
		) {$charset_collate};

		CREATE TABLE {$suspensions} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			correspondent_id BIGINT UNSIGNED NOT NULL,
			case_id BIGINT UNSIGNED DEFAULT NULL,
			type VARCHAR(30) NOT NULL,
			client_id BIGINT UNSIGNED DEFAULT NULL,
			duration VARCHAR(10) NOT NULL,
			starts_at DATETIME NOT NULL,
			ends_at DATETIME DEFAULT NULL,
			reason TEXT,
			issued_by BIGINT UNSIGNED NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			appeal_status VARCHAR(20) DEFAULT NULL,
			appeal_notes TEXT,
			appeal_reviewed_by BIGINT UNSIGNED DEFAULT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY correspondent_id (correspondent_id),
			KEY case_id (case_id),
			KEY status (status)
		) {$charset_collate};

		CREATE TABLE {$prompts} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			type VARCHAR(20) NOT NULL DEFAULT 'news',
			label VARCHAR(190) NOT NULL,
			body LONGTEXT NOT NULL,
			visible_roles TEXT NOT NULL,
			fields TEXT NULL,
			sort_order INT NOT NULL DEFAULT 0,
			created_by BIGINT UNSIGNED DEFAULT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY type_idx (type),
			KEY sort_order (sort_order)
		) {$charset_collate};";

		dbDelta( $sql );

		// Round 18 — in-app notifications.
		if ( class_exists( 'NROS_Notifications' ) ) {
			dbDelta( NROS_Notifications::schema( $charset_collate ) );
		}
	}

	/**
	 * Creates the private KYC-document directory outside the public uploads
	 * tree, locked down with a deny-all .htaccess plus an index.php silence
	 * file as a defense-in-depth fallback for servers that ignore .htaccess.
	 */
	public static function create_private_dir() {
		if ( ! file_exists( NROS_PRIVATE_DIR ) ) {
			wp_mkdir_p( NROS_PRIVATE_DIR );
		}

		$htaccess = NROS_PRIVATE_DIR . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Require all denied\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		$index = NROS_PRIVATE_DIR . '/index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}

	/**
	 * Wires up CORS handling for the REST API. Runs on every request, not
	 * just nros/v1, but only acts when the Origin header matches our
	 * allowlist, so it's harmless to other namespaces.
	 */
	public static function init() {
		self::maybe_upgrade();

		// Round 9 follow-up 4: tell every page cache (LiteSpeed Cache first
		// of all) not to cache this API — as early as possible, again once
		// cache plugins have registered their hooks, and again right before
		// a preflight exits. See disable_page_cache_for_api().
		self::disable_page_cache_for_api();
		add_action( 'init', array( __CLASS__, 'disable_page_cache_for_api' ), 0 );

		add_action( 'rest_api_init', array( __CLASS__, 'add_cors_headers' ), 15 );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'add_no_cache_headers' ), 10, 3 );

		// Round 10 — make the Publisher's Editor permissions hold outside
		// this API too (wp-admin, core /wp/v2). See guard_* below.
		add_filter( 'map_meta_cap', array( __CLASS__, 'guard_delete_cap' ), 10, 4 );
		add_filter( 'wp_insert_post_data', array( __CLASS__, 'guard_post_update' ), 99, 2 );
		add_filter( 'rest_request_before_callbacks', array( __CLASS__, 'enter_api_handler' ), 10, 3 );
		add_filter( 'rest_request_after_callbacks', array( __CLASS__, 'leave_api_handler' ), 10, 3 );
	}

	/**
	 * Post types covered by the Editor permissions.
	 *
	 * @var string[]
	 */
	const GUARDED_POST_TYPES = array( 'post', 'meeha_video' );

	/**
	 * True while one of this plugin's own nros/v1 handlers runs. Those
	 * handlers already enforce staff_can() themselves (and act on behalf of
	 * the bearer-token user, not the WordPress "current user"), so the
	 * wp-admin guards below stand aside to avoid double-blocking.
	 *
	 * @var bool
	 */
	protected static $in_api_handler = false;

	/**
	 * rest_request_before_callbacks: flags our own namespace's handlers.
	 *
	 * @param mixed           $response Response so far.
	 * @param array           $handler  Route handler.
	 * @param WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public static function enter_api_handler( $response, $handler, $request ) {
		if ( 0 === strpos( $request->get_route(), '/' . NROS_API_NS . '/' ) ) {
			self::$in_api_handler = true;
		}
		return $response;
	}

	/**
	 * rest_request_after_callbacks: clears the flag.
	 *
	 * @param mixed           $response Response.
	 * @param array           $handler  Route handler.
	 * @param WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public static function leave_api_handler( $response, $handler, $request ) {
		self::$in_api_handler = false;
		return $response;
	}

	/**
	 * A staff user whose post-management powers depend on the Publisher's
	 * settings: has editor-level capabilities but is neither Publisher nor
	 * Administrator.
	 *
	 * @param WP_User|false $user User.
	 * @return bool
	 */
	protected static function is_restricted_editor( $user ) {
		return $user && $user->ID
			&& user_can( $user, 'edit_others_posts' )
			&& ! user_can( $user, 'nros_publisher' )
			&& ! user_can( $user, 'manage_options' );
	}

	/**
	 * map_meta_cap: an Editor without the delete_posts permission cannot
	 * delete/trash articles or videos anywhere — WordPress's own
	 * `delete_others_posts` would otherwise let them do in wp-admin (or via
	 * /wp/v2) exactly what the Publisher switched off here.
	 *
	 * @param string[] $caps    Required primitive caps.
	 * @param string   $cap     Meta cap being checked.
	 * @param int      $user_id User id.
	 * @param array    $args    Extra args (post id first).
	 * @return string[]
	 */
	public static function guard_delete_cap( $caps, $cap, $user_id, $args ) {
		if ( 'delete_post' !== $cap || self::$in_api_handler || empty( $args[0] ) ) {
			return $caps;
		}
		$post = get_post( (int) $args[0] );
		if ( ! $post || ! in_array( $post->post_type, self::GUARDED_POST_TYPES, true ) ) {
			return $caps;
		}
		$user = get_userdata( $user_id );
		if ( self::is_restricted_editor( $user ) && ! self::staff_can( $user, 'delete_posts' ) ) {
			$caps[] = 'do_not_allow';
		}
		return $caps;
	}

	/**
	 * wp_insert_post_data (late): when an Editor without the matching
	 * permission UPDATES an existing article/video outside this API
	 * (wp-admin, quick edit, /wp/v2), silently keeps the stored author
	 * and/or publish date instead of the submitted one.
	 *
	 * The date is only protected once it is real — the post is already
	 * published or scheduled and has a non-zero GMT date — so ordinary
	 * publishing of a draft, where WordPress itself stamps the date, is
	 * untouched. When the date is reverted, the status is recomputed from
	 * it (WordPress derived 'future'/'publish' from the rejected date).
	 *
	 * @param array $data    Sanitized post data about to be saved.
	 * @param array $postarr Raw post array (ID present on updates).
	 * @return array
	 */
	public static function guard_post_update( $data, $postarr ) {
		if ( self::$in_api_handler || empty( $postarr['ID'] ) || ! in_array( $data['post_type'], self::GUARDED_POST_TYPES, true ) ) {
			return $data;
		}
		$user = wp_get_current_user();
		if ( ! self::is_restricted_editor( $user ) ) {
			return $data;
		}
		$existing = get_post( (int) $postarr['ID'] );
		if ( ! $existing || $existing->post_type !== $data['post_type'] ) {
			return $data;
		}

		if ( ! self::staff_can( $user, 'change_author' ) && (int) $data['post_author'] !== (int) $existing->post_author ) {
			$data['post_author'] = $existing->post_author;
		}

		$date_is_set = in_array( $existing->post_status, array( 'publish', 'future' ), true ) && '0000-00-00 00:00:00' !== $existing->post_date_gmt;
		if ( $date_is_set && ! self::staff_can( $user, 'change_date' )
			&& ( $data['post_date'] !== $existing->post_date || $data['post_date_gmt'] !== $existing->post_date_gmt ) ) {
			$data['post_date']     = $existing->post_date;
			$data['post_date_gmt'] = $existing->post_date_gmt;
			if ( in_array( $data['post_status'], array( 'publish', 'future' ), true ) ) {
				$data['post_status'] = strtotime( $existing->post_date_gmt . ' UTC' ) > time() ? 'future' : 'publish';
			}
		}

		return $data;
	}

	/**
	 * Whether the current HTTP request targets this plugin's REST namespace
	 * — pretty permalinks (/wp-json/nros/v1/...) or the ?rest_route= form.
	 * Cheap string checks only: it runs before WordPress has parsed the
	 * request.
	 *
	 * @return bool
	 */
	public static function is_api_request() {
		$prefix = function_exists( 'rest_get_url_prefix' ) ? rest_get_url_prefix() : 'wp-json';
		$uri    = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( false !== strpos( $uri, '/' . $prefix . '/' . NROS_API_NS ) ) {
			return true;
		}
		$route = isset( $_GET['rest_route'] ) ? (string) wp_unslash( $_GET['rest_route'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		return 0 === strpos( ltrim( $route, '/' ), NROS_API_NS );
	}

	/**
	 * Round 9 follow-up 4 — keeps every page cache away from this API.
	 *
	 * The browser console on correspondent.meeha.news showed every
	 * authenticated request failing with "Response to preflight request
	 * doesn't pass access control check: No 'Access-Control-Allow-Origin'
	 * header". This plugin sends that header on every preflight from an
	 * allowed origin, so those preflight answers were not produced by this
	 * code — something in front of WordPress was serving them. LiteSpeed
	 * Cache for WordPress (installed by default on Hostinger) caches REST
	 * API responses unless told not to ("Cache REST API" is on by default),
	 * and it decides cacheability through its own control header, which can
	 * outrank a plain Cache-Control: no-store. A cached copy made for a
	 * request without our Origin (a bot, a crawler) has no CORS headers and
	 * then gets served to everyone. The same mechanism would also serve
	 * stale per-user lists (e.g. a payment not appearing).
	 *
	 * So, for any request to this namespace, use every documented "do not
	 * cache this" signal: LiteSpeed's own API action and response header,
	 * plus the DONOTCACHEPAGE / LSCACHE_NO_CACHE constants that LiteSpeed
	 * and most other WordPress page caches honour. Safe to call repeatedly.
	 */
	public static function disable_page_cache_for_api() {
		if ( ! self::is_api_request() ) {
			return;
		}

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		if ( ! defined( 'LSCACHE_NO_CACHE' ) ) {
			define( 'LSCACHE_NO_CACHE', true );
		}

		// LiteSpeed Cache for WordPress API — no-op if that plugin isn't active.
		do_action( 'litespeed_control_set_nocache', 'newsroom-os API: per-user, CORS-sensitive responses' );

		if ( ! headers_sent() ) {
			header( 'X-LiteSpeed-Cache-Control: no-cache' );
			header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
			header( 'Pragma: no-cache' );
			header( 'Vary: Origin' );
		}
	}

	/**
	 * Round 8 fix: a correspondent reported the dashboard looking fine
	 * right after login, then going blank — posts/stats stop updating and
	 * new submissions stop landing. Tracing it found the frontend's own
	 * missing-.catch() bug (fixed app-side), but a shared-hosting page/
	 * object cache (e.g. LiteSpeed Cache — this project runs on Hostinger)
	 * caching a GET REST response, including an unlucky 401 taken right
	 * when an access token expired, would reproduce the exact same
	 * symptom from the server side. Every nros/v1 response now explicitly
	 * refuses to be cached, closing that possibility for good regardless
	 * of whatever caching layer the host has active.
	 *
	 * @param WP_REST_Response|WP_HTTP_Response|mixed $response Response to filter.
	 * @param WP_REST_Server                          $server   Server instance.
	 * @param WP_REST_Request                         $request  Request object.
	 * @return mixed
	 */
	public static function add_no_cache_headers( $response, $server, $request ) {
		if ( $response instanceof WP_REST_Response && 0 === strpos( $request->get_route(), '/' . NROS_API_NS ) ) {
			$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
			$response->header( 'Pragma', 'no-cache' );
			// LiteSpeed's own cache-control header — see disable_page_cache_for_api().
			$response->header( 'X-LiteSpeed-Cache-Control', 'no-cache' );
		}
		return $response;
	}

	/**
	 * Safety net for in-place plugin-file updates on an already-active
	 * install, where `activate()` never runs again: re-checks the stored
	 * db version on every request (one cheap option read) and, only when
	 * it's stale, re-runs the idempotent table/role setup once and bumps
	 * the stored version so this doesn't repeat on every page load.
	 */
	protected static function maybe_upgrade() {
		if ( get_option( 'nros_db_version' ) === NROS_DB_VERSION ) {
			return;
		}

		self::create_tables();
		self::create_private_dir();
		self::register_roles();

		// Round 9 — one-time, idempotent migration of the old single
		// summary-template/staff-template options into the new nros_prompts
		// table. Guarded by its own flag (not just the db-version gate) so a
		// future, unrelated db-version bump can never accidentally re-run it
		// and duplicate rows.
		if ( class_exists( 'NROS_Prompts' ) ) {
			NROS_Prompts::migrate_legacy_options();
		}

		update_option( 'nros_db_version', NROS_DB_VERSION );
	}

	/**
	 * Sends exact-origin CORS headers (never a wildcard, since requests may
	 * carry cookies/bearer tokens) and short-circuits OPTIONS preflight
	 * requests with a 200.
	 *
	 * Round 8 follow-up fix: the OPTIONS branch below calls `exit` directly,
	 * which means it short-circuits before WordPress ever dispatches a
	 * response object — so `add_no_cache_headers()` (hooked to
	 * `rest_post_dispatch`) never runs for a preflight request, and a
	 * preflight response had no Cache-Control header of its own at all.
	 * That let a host-level cache (e.g. LiteSpeed Cache) cache a preflight
	 * response from *before* a CORS-headers change (such as this round's
	 * `Access-Control-Allow-Headers` addition) and keep serving the old,
	 * now-wrong headers for that specific endpoint URL indefinitely — which
	 * is exactly how some endpoints could start working again right after a
	 * CORS fix while others, whose preflight happened to already be cached,
	 * kept failing. Explicitly marking the OPTIONS response no-store closes
	 * that gap for good, regardless of what the host's cache is configured
	 * to do with ordinary responses.
	 */
	public static function add_cors_headers() {
		$origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : '';

		if ( $origin && in_array( $origin, self::allowed_origins(), true ) ) {
			header( 'Access-Control-Allow-Origin: ' . $origin );
			header( 'Access-Control-Allow-Credentials: true' );
			header( 'Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS' );
			header( 'Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce, Cache-Control' );
			// Round 9 follow-up 4: let the *browser* reuse a successful
			// preflight for 10 minutes (Chrome's default is 5 seconds) —
			// roughly halves the number of requests the apps send.
			header( 'Access-Control-Max-Age: 600' );
			header( 'Vary: Origin' );
		}

		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'OPTIONS' === $_SERVER['REQUEST_METHOD'] ) {
			// Exits before any REST response object exists, so mark it
			// uncacheable here, for LiteSpeed Cache's own API too — a cached
			// preflight answer is exactly what broke every request in round
			// 9 follow-up 4 (see disable_page_cache_for_api()).
			self::disable_page_cache_for_api();
			header( 'X-LiteSpeed-Cache-Control: no-cache' );
			header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
			header( 'Pragma: no-cache' );
			status_header( 200 );
			exit;
		}
	}

	/**
	 * REST permission_callback helper: requires a valid bearer-token user
	 * who holds the correspondent role.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public static function require_correspondent( $request ) {
		$user = NROS_Auth::get_user_from_request( $request );
		if ( ! $user || is_wp_error( $user ) ) {
			return new WP_Error( 'nros_unauthorized', 'লগইন প্রয়োজন।', array( 'status' => 401 ) );
		}
		if ( ! in_array( 'correspondent', (array) $user->roles, true ) ) {
			return new WP_Error( 'nros_forbidden', 'এই অ্যাক্সেস নেই।', array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * REST permission_callback helper: requires a valid bearer-token user
	 * who can manage the site — Editor (built-in 'editor' role), Publisher,
	 * or Administrator level. Used for every endpoint in the shared
	 * publisher.meeha.news app that both Editor and Publisher may use
	 * (editorial queue, correspondent management, applications, analytics).
	 * `edit_others_posts` is a reliable proxy for "staff" because it's a
	 * capability all three of those roles carry and no public-facing role
	 * (correspondent, subscriber) does.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public static function require_staff( $request ) {
		$user = NROS_Auth::get_user_from_request( $request );
		if ( ! $user || is_wp_error( $user ) ) {
			return new WP_Error( 'nros_unauthorized', 'লগইন প্রয়োজন।', array( 'status' => 401 ) );
		}
		if ( ! user_can( $user, 'edit_others_posts' ) ) {
			return new WP_Error( 'nros_forbidden', 'এই অ্যাক্সেস নেই।', array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * REST permission_callback helper: requires Publisher (or built-in
	 * Administrator) specifically — for the handful of actions the final
	 * spec reserves to Publisher even among staff: user/role creation,
	 * the Editor financial-visibility toggle, serious-case investigation
	 * decisions, and suspension appeal review. Editor is deliberately
	 * excluded here even though Editor passes require_staff().
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public static function require_publisher( $request ) {
		$user = NROS_Auth::get_user_from_request( $request );
		if ( ! $user || is_wp_error( $user ) ) {
			return new WP_Error( 'nros_unauthorized', 'লগইন প্রয়োজন।', array( 'status' => 401 ) );
		}
		if ( ! user_can( $user, 'nros_publisher' ) && ! user_can( $user, 'manage_options' ) ) {
			return new WP_Error( 'nros_forbidden', 'এই অ্যাক্সেস শুধু পাবলিশারের জন্য।', array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * REST permission_callback helper: requires any authenticated app
	 * user — correspondent, Editor, Publisher, or Administrator — with
	 * no further capability check. Used only by endpoints genuinely
	 * shared across both frontend apps regardless of role, such as
	 * GET /me (each app's own screens then narrow further by role).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public static function require_app_user( $request ) {
		$user = NROS_Auth::get_user_from_request( $request );
		if ( ! $user || is_wp_error( $user ) ) {
			return new WP_Error( 'nros_unauthorized', 'লগইন প্রয়োজন।', array( 'status' => 401 ) );
		}
		if ( ! in_array( 'correspondent', (array) $user->roles, true ) && ! user_can( $user, 'edit_others_posts' ) ) {
			return new WP_Error( 'nros_forbidden', 'এই অ্যাক্সেস নেই।', array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Returns the encryption key used for withdraw-method account numbers,
	 * preferring a dedicated wp-config constant and falling back to AUTH_KEY
	 * (still secret, but shared with other WP internals) if it isn't set.
	 *
	 * @return string
	 */
	protected static function enc_key() {
		if ( defined( 'NROS_ENC_KEY' ) && NROS_ENC_KEY ) {
			return NROS_ENC_KEY;
		}
		return defined( 'AUTH_KEY' ) ? AUTH_KEY : 'nros-fallback-key-change-me';
	}

	/**
	 * Encrypts a plaintext string with AES-256-CBC using a random IV per
	 * call, returning base64( iv . ciphertext ) so it's one storable string.
	 *
	 * @param string $plaintext Value to encrypt.
	 * @return string
	 */
	public static function encrypt( $plaintext ) {
		$key = hash( 'sha256', self::enc_key(), true );
		$iv  = openssl_random_pseudo_bytes( openssl_cipher_iv_length( 'aes-256-cbc' ) );

		$ciphertext = openssl_encrypt( $plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );

		return base64_encode( $iv . $ciphertext ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Reverses encrypt(). Returns an empty string if decoding/decryption
	 * fails, rather than raising a warning into API output.
	 *
	 * @param string $encoded Value produced by encrypt().
	 * @return string
	 */
	public static function decrypt( $encoded ) {
		$raw = base64_decode( $encoded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw ) {
			return '';
		}

		$iv_len = openssl_cipher_iv_length( 'aes-256-cbc' );
		if ( strlen( $raw ) <= $iv_len ) {
			return '';
		}

		$iv         = substr( $raw, 0, $iv_len );
		$ciphertext = substr( $raw, $iv_len );
		$key        = hash( 'sha256', self::enc_key(), true );

		$plaintext = openssl_decrypt( $ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );

		return false === $plaintext ? '' : $plaintext;
	}

	/**
	 * Builds a chronological "what changed, and by whom" timeline for one
	 * post, for the correspondent-training feature: a journalist opens
	 * their published article and sees exactly how the editorial desk
	 * improved it. Reuses WordPress's own built-in post-revision system
	 * (already running on every wp_insert_post()/wp_update_post() call this
	 * plugin makes — no new storage) and the same wp_text_diff() WordPress's
	 * native revision-comparison screen uses, so the output looks like the
	 * familiar red/green WordPress diff.
	 *
	 * Shared by NROS_Submissions (own post, correspondent-facing) and
	 * NROS_Admin_Posts (any post, staff-facing) rather than duplicated —
	 * both just need to apply their own ownership/permission check before
	 * calling this.
	 *
	 * @param int $post_id Post id.
	 * @return array<int, array{id:int, author:string|null, date:string, title_diff:string, excerpt_diff:string, content_diff:string}>
	 */
	public static function build_revision_timeline( $post_id ) {
		if ( ! function_exists( 'wp_text_diff' ) ) {
			require_once ABSPATH . 'wp-includes/wp-diff.php';
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return array();
		}

		// wp_get_post_revisions() returns newest-first; this timeline reads
		// top-to-bottom as "what happened over time", so oldest-first.
		$revisions = array_reverse( wp_get_post_revisions( $post_id ) );

		// The current live post is the final entry in the timeline — it's
		// the only way to see the diff for the most recent save, since that
		// save's own "after" state isn't itself a stored revision.
		$versions = array_values( $revisions );
		$versions[] = $post;

		$diff_args = array( 'show_split_view' => true );

		$timeline  = array();
		$prev      = null;

		foreach ( $versions as $version ) {
			if ( null === $prev ) {
				$prev = $version;
				continue;
			}

			$title_diff   = wp_text_diff( $prev->post_title, $version->post_title, $diff_args );
			$excerpt_diff = wp_text_diff( $prev->post_excerpt, $version->post_excerpt, $diff_args );
			$content_diff = wp_text_diff( $prev->post_content, $version->post_content, $diff_args );

			// wp_text_diff() returns false/empty when the two strings are
			// identical — skip entries where nothing actually changed
			// (e.g. an autosave with no edits) so the timeline isn't noise.
			if ( ! $title_diff && ! $excerpt_diff && ! $content_diff ) {
				$prev = $version;
				continue;
			}

			$author = get_userdata( $version->post_author );

			$timeline[] = array(
				'id'           => $version->ID,
				'author'       => $author ? $author->display_name : null,
				'date'         => $version->post_modified,
				'title_diff'   => $title_diff ?: '',
				'excerpt_diff' => $excerpt_diff ?: '',
				'content_diff' => $content_diff ?: '',
			);

			$prev = $version;
		}

		return $timeline;
	}
}
