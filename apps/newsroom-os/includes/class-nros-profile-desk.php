<?php
/**
 * NROS_Profile_Desk — 1.15.0: প্রোফাইল ডেস্ক (the publisher app's CRM).
 *
 * A private desk for the newsroom: profiles of people (politicians,
 * activists, celebrities, officials, journalists) and organisations (news
 * agencies, parties, domestic and foreign law-enforcement agencies,
 * ministries, NGOs), their social links, groups ("NCP top leaders"), files
 * kept under each profile, and PIN+OTP vaults. Files, vaults and alerts
 * live in NROS_PD_Files and NROS_PD_Feeds; this class holds the tables,
 * the access rules, profiles, links, groups and search.
 *
 * Access: Publisher / Administrator always; an Editor only after the
 * Publisher turns it on for that Editor (usermeta nros_pd_access). Nothing
 * here is ever public: every route checks require_access(), no profile is
 * a post, nothing reaches the site's search, feeds or sitemap.
 *
 *   GET    /pd/meta
 *   GET    /pd/entities            ?q kind category country district tag group importance status alert files sort page per_page
 *   POST   /pd/entities
 *   GET    /pd/entities/{id}
 *   PATCH  /pd/entities/{id}
 *   DELETE /pd/entities/{id}
 *   POST   /pd/entities/{id}/links
 *   PATCH  /pd/links/{id}
 *   DELETE /pd/links/{id}
 *   GET    /pd/groups              POST /pd/groups
 *   PATCH  /pd/groups/{id}         DELETE /pd/groups/{id}
 *   GET    /pd/access              POST /pd/access     (Publisher)
 *   GET    /pd/audit                                   (Publisher)
 *
 * @package NewsroomOS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NROS_Profile_Desk {

	/** Who / what a profile is. Keys are stored; labels are what the app shows. */
	const CATEGORIES = array(
		'politician'  => array( 'person', 'রাজনীতিবিদ' ),
		'activist'    => array( 'person', 'অনলাইন অ্যাক্টিভিস্ট' ),
		'celebrity'   => array( 'person', 'সেলেব্রেটি' ),
		'journalist'  => array( 'person', 'সাংবাদিক' ),
		'official'    => array( 'person', 'কর্মকর্তা' ),
		'person'      => array( 'person', 'অন্যান্য ব্যক্তি' ),
		'party'       => array( 'org', 'রাজনৈতিক দল' ),
		'media'       => array( 'org', 'সংবাদ সংস্থা' ),
		'law_bd'      => array( 'org', 'দেশি আইনপ্রয়োগকারী সংস্থা' ),
		'law_foreign' => array( 'org', 'বিদেশি আইনপ্রয়োগকারী সংস্থা' ),
		'govt'        => array( 'org', 'সরকারি দপ্তর' ),
		'ngo'         => array( 'org', 'এনজিও / সংগঠন' ),
		'org'         => array( 'org', 'অন্যান্য সংস্থা' ),
	);

	const STATUSES = array(
		'active'   => 'সক্রিয়',
		'arrested' => 'গ্রেফতার',
		'abroad'   => 'বিদেশে',
		'fugitive' => 'পলাতক',
		'inactive' => 'নিষ্ক্রিয়',
		'deceased' => 'মৃত',
	);

	/** Platforms the app shows an icon for. */
	const PLATFORMS = array(
		'facebook'  => 'ফেসবুক',
		'x'         => 'এক্স (টুইটার)',
		'youtube'   => 'ইউটিউব',
		'tiktok'    => 'টিকটক',
		'instagram' => 'ইনস্টাগ্রাম',
		'telegram'  => 'টেলিগ্রাম',
		'linkedin'  => 'লিংকডইন',
		'whatsapp'  => 'হোয়াটসঅ্যাপ',
		'website'   => 'ওয়েবসাইট',
		'other'     => 'অন্যান্য',
	);

	const MAX_PER_PAGE = 120;

	/**
	 * Registers hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	// ---------------------------------------------------------------------
	// Tables
	// ---------------------------------------------------------------------

	/**
	 * Table name helper.
	 *
	 * @param string $name Short name.
	 * @return string
	 */
	public static function t( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'nros_pd_' . $name;
	}

	/**
	 * CREATE TABLE statements for NROS_Core::create_tables() (dbDelta).
	 *
	 * @param string $c Charset/collation.
	 * @return string
	 */
	public static function schema( $c ) {
		$entities = self::t( 'entities' );
		$links    = self::t( 'links' );
		$groups   = self::t( 'groups' );
		$members  = self::t( 'group_members' );
		$folders  = self::t( 'folders' );
		$files    = self::t( 'files' );
		$items    = self::t( 'feed_items' );
		$watch    = self::t( 'watch' );
		$audit    = self::t( 'audit' );

		return "CREATE TABLE {$entities} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			kind VARCHAR(10) NOT NULL DEFAULT 'person',
			category VARCHAR(20) NOT NULL DEFAULT 'person',
			name_bn VARCHAR(190) NOT NULL DEFAULT '',
			name_en VARCHAR(190) NOT NULL DEFAULT '',
			aliases TEXT NULL,
			title VARCHAR(255) NOT NULL DEFAULT '',
			org_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			parent_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			country VARCHAR(80) NOT NULL DEFAULT 'বাংলাদেশ',
			division VARCHAR(100) NOT NULL DEFAULT '',
			district VARCHAR(100) NOT NULL DEFAULT '',
			upazila VARCHAR(100) NOT NULL DEFAULT '',
			tags TEXT NULL,
			importance TINYINT NOT NULL DEFAULT 3,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			sensitivity VARCHAR(12) NOT NULL DEFAULT 'normal',
			bio TEXT NULL,
			private_note LONGTEXT NULL,
			phone VARCHAR(120) NOT NULL DEFAULT '',
			email VARCHAR(190) NOT NULL DEFAULT '',
			photo_file_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			search_blob TEXT NULL,
			created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			updated_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY kind_cat (kind, category),
			KEY org_id (org_id),
			KEY district (district),
			KEY updated_at (updated_at)
		) {$c};

		CREATE TABLE {$links} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			entity_id BIGINT UNSIGNED NOT NULL,
			platform VARCHAR(20) NOT NULL DEFAULT 'other',
			url VARCHAR(500) NOT NULL DEFAULT '',
			url_norm VARCHAR(500) NOT NULL DEFAULT '',
			handle VARCHAR(190) NOT NULL DEFAULT '',
			label VARCHAR(190) NOT NULL DEFAULT '',
			verified TINYINT NOT NULL DEFAULT 0,
			feed_kind VARCHAR(12) NOT NULL DEFAULT '',
			feed_url VARCHAR(500) NOT NULL DEFAULT '',
			feed_state VARCHAR(20) NOT NULL DEFAULT '',
			feed_error VARCHAR(255) NOT NULL DEFAULT '',
			last_checked DATETIME NULL,
			sort_order INT NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY entity_id (entity_id),
			KEY url_norm (url_norm(191)),
			KEY feed_kind (feed_kind)
		) {$c};

		CREATE TABLE {$groups} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(190) NOT NULL,
			color VARCHAR(20) NOT NULL DEFAULT 'brand',
			note VARCHAR(255) NOT NULL DEFAULT '',
			sort_order INT NOT NULL DEFAULT 0,
			created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id)
		) {$c};

		CREATE TABLE {$members} (
			group_id BIGINT UNSIGNED NOT NULL,
			entity_id BIGINT UNSIGNED NOT NULL,
			sort_order INT NOT NULL DEFAULT 0,
			PRIMARY KEY  (group_id, entity_id),
			KEY entity_id (entity_id)
		) {$c};

		CREATE TABLE {$folders} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			scope VARCHAR(16) NOT NULL DEFAULT 'entity',
			entity_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			owner_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			parent_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			name VARCHAR(190) NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY scope_entity (scope, entity_id),
			KEY owner_id (owner_id),
			KEY parent_id (parent_id)
		) {$c};

		CREATE TABLE {$files} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			scope VARCHAR(16) NOT NULL DEFAULT 'entity',
			folder_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			entity_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			owner_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			name VARCHAR(255) NOT NULL,
			ext VARCHAR(16) NOT NULL DEFAULT '',
			mime VARCHAR(120) NOT NULL DEFAULT '',
			kind VARCHAR(10) NOT NULL DEFAULT 'other',
			size BIGINT UNSIGNED NOT NULL DEFAULT 0,
			disk_name VARCHAR(190) NOT NULL,
			enc_key TEXT NULL,
			enc_iv VARCHAR(64) NOT NULL DEFAULT '',
			sha256 CHAR(64) NOT NULL DEFAULT '',
			note TEXT NULL,
			source VARCHAR(500) NOT NULL DEFAULT '',
			collected_on DATE NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY folder_id (folder_id),
			KEY entity_scope (entity_id, scope),
			KEY owner_id (owner_id),
			KEY sha256 (sha256)
		) {$c};

		CREATE TABLE {$items} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			link_id BIGINT UNSIGNED NOT NULL,
			item_key VARCHAR(190) NOT NULL,
			kind VARCHAR(10) NOT NULL DEFAULT 'post',
			title VARCHAR(500) NOT NULL DEFAULT '',
			url VARCHAR(500) NOT NULL DEFAULT '',
			published_at DATETIME NOT NULL,
			found_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY link_item (link_id, item_key),
			KEY link_pub (link_id, published_at)
		) {$c};

		CREATE TABLE {$watch} (
			user_id BIGINT UNSIGNED NOT NULL,
			link_id BIGINT UNSIGNED NOT NULL,
			posts TINYINT NOT NULL DEFAULT 0,
			videos TINYINT NOT NULL DEFAULT 0,
			seen_at DATETIME NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (user_id, link_id),
			KEY link_id (link_id)
		) {$c};

		CREATE TABLE {$audit} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			action VARCHAR(40) NOT NULL,
			object_type VARCHAR(20) NOT NULL DEFAULT '',
			object_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			detail VARCHAR(255) NOT NULL DEFAULT '',
			ip VARCHAR(64) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY user_id (user_id)
		) {$c};";
	}

	// ---------------------------------------------------------------------
	// Access
	// ---------------------------------------------------------------------

	/**
	 * Publisher or Administrator.
	 *
	 * @param WP_User|null $user User.
	 * @return bool
	 */
	public static function is_boss( $user ) {
		return $user instanceof WP_User && ( user_can( $user, 'nros_publisher' ) || user_can( $user, 'manage_options' ) );
	}

	/**
	 * Whether a user may open প্রোফাইল ডেস্ক at all.
	 *
	 * @param WP_User|null $user User.
	 * @return bool
	 */
	public static function can_access( $user ) {
		if ( ! ( $user instanceof WP_User ) ) {
			return false;
		}
		if ( self::is_boss( $user ) ) {
			return true;
		}
		return user_can( $user, 'edit_others_posts' ) && '1' === (string) get_user_meta( $user->ID, 'nros_pd_access', true );
	}

	/**
	 * REST permission callback for every /pd route.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function require_access( $request ) {
		$user = NROS_Auth::get_user_from_request( $request );
		if ( ! $user || is_wp_error( $user ) ) {
			return new WP_Error( 'nros_unauthorized', 'লগইন প্রয়োজন।', array( 'status' => 401 ) );
		}
		if ( ! self::can_access( $user ) ) {
			return new WP_Error( 'nros_forbidden', 'প্রোফাইল ডেস্কের অ্যাক্সেস নেই। পাবলিশারের কাছে অনুমতি চান।', array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * REST permission callback for Publisher-only /pd routes.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function require_boss( $request ) {
		$ok = self::require_access( $request );
		if ( true !== $ok ) {
			return $ok;
		}
		if ( ! self::is_boss( self::me( $request ) ) ) {
			return new WP_Error( 'nros_forbidden', 'এটা শুধু পাবলিশারের জন্য।', array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Current user.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_User
	 */
	public static function me( $request ) {
		return NROS_Auth::get_user_from_request( $request );
	}

	/**
	 * SQL condition that hides "restricted" profiles from everyone but the
	 * Publisher and the person who added them.
	 *
	 * @param WP_User $user  User.
	 * @param string  $alias Table alias.
	 * @return string
	 */
	public static function visible_sql( $user, $alias = 'e' ) {
		global $wpdb;
		if ( self::is_boss( $user ) ) {
			return '1=1';
		}
		return $wpdb->prepare( "({$alias}.sensitivity <> 'restricted' OR {$alias}.created_by = %d)", (int) $user->ID ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * One visible entity row, or null.
	 *
	 * @param int     $id   Id.
	 * @param WP_User $user User.
	 * @return array|null
	 */
	public static function entity_row( $id, $user ) {
		global $wpdb;
		$t   = self::t( 'entities' );
		$vis = self::visible_sql( $user );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} e WHERE e.id = %d AND {$vis}", (int) $id ), ARRAY_A );
	}

	/**
	 * Writes one audit line.
	 *
	 * @param int    $user_id User.
	 * @param string $action  Action key.
	 * @param string $type    Object type.
	 * @param int    $id      Object id.
	 * @param string $detail  Short detail.
	 */
	public static function audit( $user_id, $action, $type = '', $id = 0, $detail = '' ) {
		global $wpdb;
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$wpdb->insert(
			self::t( 'audit' ),
			array(
				'user_id'     => (int) $user_id,
				'action'      => substr( $action, 0, 40 ),
				'object_type' => substr( $type, 0, 20 ),
				'object_id'   => (int) $id,
				'detail'      => mb_substr( (string) $detail, 0, 250 ),
				'ip'          => substr( $ip, 0, 64 ),
				'created_at'  => current_time( 'mysql' ),
			)
		);
	}

	// ---------------------------------------------------------------------
	// Text helpers
	// ---------------------------------------------------------------------

	/**
	 * Folds spelling differences so "হাসিনা" finds "হাসীনা", "রিয়াজ"
	 * finds "রিযাজ", "ঢাকা" finds "ঢাকা" typed with a different nukta form,
	 * and English is case-insensitive. Used on stored text and on queries.
	 *
	 * @param string $s Text.
	 * @return string
	 */
	public static function norm( $s ) {
		$s = (string) $s;
		$s = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
		$map = array(
			"\u{200C}" => '',
			"\u{200D}" => '',
			"\u{09DF}" => "\u{09AF}", // য় → য
			"\u{09DC}" => "\u{09A1}", // ড় → ড
			"\u{09DD}" => "\u{09A2}", // ঢ় → ঢ
			"\u{09BC}" => '',         // nukta
			"\u{09C0}" => "\u{09BF}", // ী → ি
			"\u{09C2}" => "\u{09C1}", // ূ → ু
			"\u{0988}" => "\u{0987}", // ঈ → ই
			"\u{098A}" => "\u{0989}", // ঊ → উ
			"\u{09A3}" => "\u{09A8}", // ণ → ন
			"\u{09B7}" => "\u{09B6}", // ষ → শ
			"\u{09B8}" => "\u{09B6}", // স → শ
			"\u{09CE}" => "\u{09A4}", // ৎ → ত
			"\u{0981}" => '',         // ঁ
			"\u{09CD}\u{09AF}" => "\u{09CD}\u{09AF}",
			'০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4',
			'৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9',
		);
		$s = strtr( $s, $map );
		$s = preg_replace( '/[^\p{L}\p{M}\p{N}@.\/_-]+/u', ' ', $s );
		return trim( preg_replace( '/\s+/u', ' ', (string) $s ) );
	}

	/**
	 * A link reduced to host + path for "whose link is this?" lookups.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function norm_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		if ( ! preg_match( '#^[a-z][a-z0-9+.-]*://#i', $url ) ) {
			$url = 'https://' . $url;
		}
		$p = wp_parse_url( $url );
		if ( empty( $p['host'] ) ) {
			return '';
		}
		$host = strtolower( $p['host'] );
		$host = preg_replace( '/^(www|m|mobile|web|mbasic)\./', '', $host );
		if ( 'fb.com' === $host ) {
			$host = 'facebook.com';
		}
		if ( 'twitter.com' === $host ) {
			$host = 'x.com';
		}
		$path = isset( $p['path'] ) ? rtrim( strtolower( $p['path'] ), '/' ) : '';
		// facebook profile.php?id=123 — the id is the identity
		if ( 'facebook.com' === $host && '/profile.php' === $path && ! empty( $p['query'] ) ) {
			parse_str( $p['query'], $q );
			if ( ! empty( $q['id'] ) ) {
				$path .= '?id=' . preg_replace( '/\D/', '', (string) $q['id'] );
			}
		}
		return substr( $host . $path, 0, 500 );
	}

	/**
	 * Which platform a URL belongs to.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function detect_platform( $url ) {
		$n    = self::norm_url( $url );
		$host = strtok( $n, '/' );
		$map  = array(
			'facebook.com'  => 'facebook',
			'fb.watch'      => 'facebook',
			'x.com'         => 'x',
			'youtube.com'   => 'youtube',
			'youtu.be'      => 'youtube',
			'tiktok.com'    => 'tiktok',
			'instagram.com' => 'instagram',
			't.me'          => 'telegram',
			'telegram.me'   => 'telegram',
			'linkedin.com'  => 'linkedin',
			'wa.me'         => 'whatsapp',
			'whatsapp.com'  => 'whatsapp',
		);
		if ( isset( $map[ $host ] ) ) {
			return $map[ $host ];
		}
		return $host ? 'website' : 'other';
	}

	/**
	 * A readable handle from a URL ("@name", channel name, page name).
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function guess_handle( $url ) {
		$n     = self::norm_url( $url );
		$parts = explode( '/', $n );
		if ( count( $parts ) < 2 ) {
			return '';
		}
		$seg = $parts[1];
		if ( in_array( $seg, array( 'channel', 'c', 'user', 'company', 'in', 's', 'pages', 'people' ), true ) && isset( $parts[2] ) ) {
			$seg = $parts[2];
		}
		if ( 0 === strpos( $seg, 'profile.php?id=' ) ) {
			return '';
		}
		$seg = ltrim( urldecode( $seg ), '@' );
		return '' !== $seg ? '@' . mb_substr( $seg, 0, 120 ) : '';
	}

	/**
	 * Clean list of tags from a string or array.
	 *
	 * @param mixed $raw Raw.
	 * @return string[]
	 */
	protected static function clean_tags( $raw ) {
		if ( is_string( $raw ) ) {
			$raw = preg_split( '/[,،;\n]+/u', $raw );
		}
		$out = array();
		foreach ( (array) $raw as $tag ) {
			$tag = trim( sanitize_text_field( (string) $tag ) );
			if ( '' !== $tag && mb_strlen( $tag ) <= 60 && ! in_array( $tag, $out, true ) ) {
				$out[] = $tag;
			}
		}
		return array_slice( $out, 0, 30 );
	}

	/**
	 * Rebuilds the folded search text of one entity (names, aliases, title,
	 * tags, organisation, area, contacts, link handles and URLs).
	 *
	 * @param int $id Entity id.
	 */
	public static function reindex( $id ) {
		global $wpdb;
		$e = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'entities' ) . ' WHERE id = %d', (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $e ) {
			return;
		}
		$bits = array( $e['name_bn'], $e['name_en'], $e['aliases'], $e['title'], $e['tags'], $e['country'], $e['division'], $e['district'], $e['upazila'], $e['phone'], $e['email'] );
		if ( (int) $e['org_id'] ) {
			$org = $wpdb->get_row( $wpdb->prepare( 'SELECT name_bn, name_en, aliases FROM ' . self::t( 'entities' ) . ' WHERE id = %d', (int) $e['org_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( $org ) {
				$bits[] = $org['name_bn'] . ' ' . $org['name_en'] . ' ' . $org['aliases'];
			}
		}
		$links = $wpdb->get_results( $wpdb->prepare( 'SELECT url_norm, handle, label FROM ' . self::t( 'links' ) . ' WHERE entity_id = %d', (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( $links as $l ) {
			$bits[] = $l['url_norm'] . ' ' . $l['handle'] . ' ' . $l['label'];
		}
		$blob = ' ' . self::norm( implode( ' ', array_map( 'strval', $bits ) ) ) . ' ';
		$wpdb->update( self::t( 'entities' ), array( 'search_blob' => $blob ), array( 'id' => (int) $id ) );
	}

	// ---------------------------------------------------------------------
	// Routes
	// ---------------------------------------------------------------------

	/**
	 * Registers REST routes.
	 */
	public static function register_routes() {
		$acc  = array( __CLASS__, 'require_access' );
		$boss = array( __CLASS__, 'require_boss' );
		$ns   = NROS_API_NS;

		register_rest_route( $ns, '/pd/meta', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'meta' ), 'permission_callback' => $acc ) );
		register_rest_route(
			$ns,
			'/pd/entities',
			array(
				array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'list_entities' ), 'permission_callback' => $acc ),
				array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'create_entity' ), 'permission_callback' => $acc ),
			)
		);
		register_rest_route(
			$ns,
			'/pd/entities/(?P<id>\d+)',
			array(
				array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_entity' ), 'permission_callback' => $acc ),
				array( 'methods' => 'PATCH', 'callback' => array( __CLASS__, 'update_entity' ), 'permission_callback' => $acc ),
				array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'delete_entity' ), 'permission_callback' => $acc ),
			)
		);
		register_rest_route( $ns, '/pd/entities/(?P<id>\d+)/links', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'add_link' ), 'permission_callback' => $acc ) );
		register_rest_route(
			$ns,
			'/pd/links/(?P<id>\d+)',
			array(
				array( 'methods' => 'PATCH', 'callback' => array( __CLASS__, 'update_link' ), 'permission_callback' => $acc ),
				array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'delete_link' ), 'permission_callback' => $acc ),
			)
		);
		register_rest_route(
			$ns,
			'/pd/groups',
			array(
				array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'list_groups' ), 'permission_callback' => $acc ),
				array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'create_group' ), 'permission_callback' => $acc ),
			)
		);
		register_rest_route(
			$ns,
			'/pd/groups/(?P<id>\d+)',
			array(
				array( 'methods' => 'PATCH', 'callback' => array( __CLASS__, 'update_group' ), 'permission_callback' => $acc ),
				array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'delete_group' ), 'permission_callback' => $acc ),
			)
		);
		register_rest_route(
			$ns,
			'/pd/access',
			array(
				array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'list_access' ), 'permission_callback' => $boss ),
				array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'set_access' ), 'permission_callback' => $boss ),
			)
		);
		register_rest_route( $ns, '/pd/audit', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'list_audit' ), 'permission_callback' => $boss ) );
	}

	/**
	 * GET /pd/meta — lists for the app's forms and the desk's own state.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function meta( $request ) {
		global $wpdb;
		$user = self::me( $request );
		$cats = array();
		foreach ( self::CATEGORIES as $key => $row ) {
			$cats[] = array( 'key' => $key, 'kind' => $row[0], 'label' => $row[1] );
		}
		$plats = array();
		foreach ( self::PLATFORMS as $key => $label ) {
			$plats[] = array(
				'key'   => $key,
				'label' => $label,
				'auto'  => in_array( $key, NROS_PD_Feeds::AUTO_PLATFORMS, true ),
			);
		}
		$statuses = array();
		foreach ( self::STATUSES as $key => $label ) {
			$statuses[] = array( 'key' => $key, 'label' => $label );
		}
		$vis  = self::visible_sql( $user );
		$te   = self::t( 'entities' );
		$tags = array();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $wpdb->get_col( "SELECT tags FROM {$te} e WHERE {$vis} AND tags <> '' LIMIT 3000" ) as $row ) {
			foreach ( self::clean_tags( $row ) as $tag ) {
				$tags[ $tag ] = isset( $tags[ $tag ] ) ? $tags[ $tag ] + 1 : 1;
			}
		}
		arsort( $tags );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$districts = $wpdb->get_col( "SELECT DISTINCT district FROM {$te} e WHERE {$vis} AND district <> '' ORDER BY district LIMIT 200" );
		$countries = $wpdb->get_col( "SELECT DISTINCT country FROM {$te} e WHERE {$vis} AND country <> '' ORDER BY country LIMIT 200" );
		$totals    = $wpdb->get_results( "SELECT kind, COUNT(*) n FROM {$te} e WHERE {$vis} GROUP BY kind", ARRAY_A );
		// phpcs:enable
		$count = array( 'person' => 0, 'org' => 0 );
		foreach ( $totals as $r ) {
			$count[ $r['kind'] ] = (int) $r['n'];
		}
		return new WP_REST_Response(
			array(
				'categories' => $cats,
				'platforms'  => $plats,
				'statuses'   => $statuses,
				'tags'       => array_slice( array_keys( $tags ), 0, 80 ),
				'districts'  => array_values( array_map( 'strval', $districts ) ),
				'countries'  => array_values( array_map( 'strval', $countries ) ),
				'totals'     => $count,
				'is_boss'    => self::is_boss( $user ),
				'vault'      => NROS_PD_Files::vault_state( $user, $request ),
				'storage'    => NROS_PD_Files::storage_summary(),
				'max_file'   => NROS_PD_Files::max_bytes(),
				'unseen'     => NROS_PD_Feeds::unseen_total( $user ),
			),
			200
		);
	}

	/**
	 * Shapes one entity row for the app.
	 *
	 * @param array   $e    Row.
	 * @param WP_User $user Viewer.
	 * @param bool    $full Include the private note and long bio.
	 * @return array
	 */
	public static function shape( $e, $user, $full = false ) {
		$out = array(
			'id'          => (int) $e['id'],
			'kind'        => $e['kind'],
			'category'    => $e['category'],
			'name_bn'     => $e['name_bn'],
			'name_en'     => $e['name_en'],
			'name'        => '' !== $e['name_bn'] ? $e['name_bn'] : $e['name_en'],
			'aliases'     => (string) $e['aliases'],
			'title'       => $e['title'],
			'org_id'      => (int) $e['org_id'],
			'parent_id'   => (int) $e['parent_id'],
			'country'     => $e['country'],
			'division'    => $e['division'],
			'district'    => $e['district'],
			'upazila'     => $e['upazila'],
			'tags'        => self::clean_tags( (string) $e['tags'] ),
			'importance'  => (int) $e['importance'],
			'status'      => $e['status'],
			'sensitivity' => $e['sensitivity'],
			'phone'       => $e['phone'],
			'email'       => $e['email'],
			'photo'       => (int) $e['photo_file_id'] ? NROS_PD_Files::signed_url( (int) $e['photo_file_id'], (int) $user->ID, 4 * HOUR_IN_SECONDS ) : '',
			'photo_file_id' => (int) $e['photo_file_id'],
			'created_by'  => (int) $e['created_by'],
			'created_at'  => $e['created_at'],
			'updated_at'  => $e['updated_at'],
			'can_edit'    => true,
			'can_delete'  => self::is_boss( $user ) || (int) $e['created_by'] === (int) $user->ID,
		);
		if ( $full ) {
			$out['bio']          = (string) $e['bio'];
			$out['private_note'] = '' !== (string) $e['private_note'] ? NROS_Core::decrypt( (string) $e['private_note'] ) : '';
		} else {
			$out['bio'] = mb_substr( wp_strip_all_tags( (string) $e['bio'] ), 0, 160 );
		}
		return $out;
	}

	/**
	 * Links of many entities, keyed by entity id, with the viewer's alert
	 * state and unseen counts.
	 *
	 * @param int[]   $ids  Entity ids.
	 * @param WP_User $user Viewer.
	 * @return array<int, array>
	 */
	public static function links_for( $ids, $user ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
		if ( ! $ids ) {
			return array();
		}
		$in    = implode( ',', $ids );
		$tl    = self::t( 'links' );
		$rows  = $wpdb->get_results( "SELECT * FROM {$tl} WHERE entity_id IN ({$in}) ORDER BY sort_order ASC, id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$state = NROS_PD_Feeds::state_for_links( wp_list_pluck( $rows, 'id' ), $user );
		$out   = array();
		foreach ( $rows as $l ) {
			$out[ (int) $l['entity_id'] ][] = self::shape_link( $l, isset( $state[ (int) $l['id'] ] ) ? $state[ (int) $l['id'] ] : null );
		}
		return $out;
	}

	/**
	 * Shapes one link.
	 *
	 * @param array      $l     Row.
	 * @param array|null $state Viewer state from NROS_PD_Feeds::state_for_links().
	 * @return array
	 */
	public static function shape_link( $l, $state ) {
		return array(
			'id'           => (int) $l['id'],
			'entity_id'    => (int) $l['entity_id'],
			'platform'     => $l['platform'],
			'url'          => $l['url'],
			'handle'       => $l['handle'],
			'label'        => $l['label'],
			'verified'     => (int) $l['verified'],
			'auto'         => '' !== $l['feed_kind'],
			'feed_state'   => $l['feed_state'],
			'feed_error'   => $l['feed_error'],
			'last_checked' => $l['last_checked'],
			'watch'        => array(
				'posts'  => $state ? (bool) $state['posts'] : false,
				'videos' => $state ? (bool) $state['videos'] : false,
			),
			'unseen'       => array(
				'posts'  => $state ? (int) $state['unseen_posts'] : 0,
				'videos' => $state ? (int) $state['unseen_videos'] : 0,
			),
			'seen_at'      => $state ? $state['seen_at'] : null,
			'checked_today' => $state ? (bool) $state['checked_today'] : false,
		);
	}

	/**
	 * GET /pd/entities — search + filters.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function list_entities( $request ) {
		global $wpdb;
		$user  = self::me( $request );
		$te    = self::t( 'entities' );
		$where = array( self::visible_sql( $user ) );
		$join  = '';

		$q = trim( (string) $request->get_param( 'q' ) );
		if ( '' !== $q ) {
			// a pasted link: whose is it?
			if ( preg_match( '#^(https?://|www\.)|^[a-z0-9-]+\.(com|me|be|net|org)/#i', $q ) ) {
				$n = self::norm_url( $q );
				if ( '' !== $n ) {
					$where[] = $wpdb->prepare( 'e.id IN (SELECT entity_id FROM ' . self::t( 'links' ) . ' WHERE url_norm = %s OR url_norm LIKE %s)', $n, $wpdb->esc_like( $n ) . '%' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				}
			} else {
				foreach ( array_slice( explode( ' ', self::norm( $q ) ), 0, 6 ) as $term ) {
					if ( '' === $term ) {
						continue;
					}
					$where[] = $wpdb->prepare( 'e.search_blob LIKE %s', '%' . $wpdb->esc_like( $term ) . '%' );
				}
			}
		}
		$kind = (string) $request->get_param( 'kind' );
		if ( in_array( $kind, array( 'person', 'org' ), true ) ) {
			$where[] = $wpdb->prepare( 'e.kind = %s', $kind );
		}
		$cats = array_filter( array_map( 'sanitize_key', explode( ',', (string) $request->get_param( 'category' ) ) ) );
		$cats = array_values( array_intersect( $cats, array_keys( self::CATEGORIES ) ) );
		if ( $cats ) {
			$where[] = "e.category IN ('" . implode( "','", $cats ) . "')";
		}
		foreach ( array( 'country', 'district', 'status' ) as $col ) {
			$v = trim( sanitize_text_field( (string) $request->get_param( $col ) ) );
			if ( '' !== $v ) {
				$where[] = $wpdb->prepare( "e.{$col} = %s", $v ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}
		$tag = trim( sanitize_text_field( (string) $request->get_param( 'tag' ) ) );
		if ( '' !== $tag ) {
			$where[] = $wpdb->prepare( 'CONCAT(\',\', e.tags, \',\') LIKE %s', '%,' . $wpdb->esc_like( $tag ) . ',%' );
		}
		$imp = (int) $request->get_param( 'importance' );
		if ( $imp >= 1 && $imp <= 5 ) {
			$where[] = $wpdb->prepare( 'e.importance >= %d', $imp );
		}
		$org = (int) $request->get_param( 'org' );
		if ( $org ) {
			$where[] = $wpdb->prepare( '(e.org_id = %d OR e.parent_id = %d)', $org, $org );
		}
		$group = (int) $request->get_param( 'group' );
		if ( $group ) {
			$join   .= ' INNER JOIN ' . self::t( 'group_members' ) . ' gm ON gm.entity_id = e.id AND gm.group_id = ' . $group;
		}
		if ( $request->get_param( 'alert' ) ) {
			$where[] = $wpdb->prepare( 'e.id IN (SELECT l.entity_id FROM ' . self::t( 'links' ) . ' l INNER JOIN ' . self::t( 'watch' ) . ' w ON w.link_id = l.id AND w.user_id = %d AND (w.posts = 1 OR w.videos = 1))', (int) $user->ID ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		if ( $request->get_param( 'files' ) ) {
			$where[] = "e.id IN (SELECT entity_id FROM " . self::t( 'files' ) . " WHERE scope = 'entity')";
		}
		$platform = sanitize_key( (string) $request->get_param( 'platform' ) );
		if ( isset( self::PLATFORMS[ $platform ] ) ) {
			$where[] = $wpdb->prepare( 'e.id IN (SELECT entity_id FROM ' . self::t( 'links' ) . ' WHERE platform = %s)', $platform ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		$sort  = (string) $request->get_param( 'sort' );
		$order = 'e.updated_at DESC';
		if ( 'name' === $sort ) {
			$order = "COALESCE(NULLIF(e.name_bn, ''), e.name_en) ASC";
		} elseif ( 'importance' === $sort ) {
			$order = 'e.importance DESC, e.updated_at DESC';
		} elseif ( $group ) {
			$order = 'gm.sort_order ASC, e.importance DESC';
		}

		$per   = max( 1, min( self::MAX_PER_PAGE, (int) ( $request->get_param( 'per_page' ) ?: 60 ) ) );
		$page  = max( 1, (int) $request->get_param( 'page' ) );
		$off   = ( $page - 1 ) * $per;
		$w     = implode( ' AND ', $where );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT e.id) FROM {$te} e {$join} WHERE {$w}" );
		$rows  = $wpdb->get_results( "SELECT e.* FROM {$te} e {$join} WHERE {$w} ORDER BY {$order} LIMIT {$off}, {$per}", ARRAY_A );
		// phpcs:enable

		$ids   = array_map( 'intval', wp_list_pluck( $rows, 'id' ) );
		$links = self::links_for( $ids, $user );
		$orgs  = self::names_of( array_merge( wp_list_pluck( $rows, 'org_id' ), wp_list_pluck( $rows, 'parent_id' ) ) );
		$files = array();
		if ( $ids ) {
			$tf = self::t( 'files' );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( $wpdb->get_results( "SELECT entity_id, COUNT(*) n FROM {$tf} WHERE scope = 'entity' AND entity_id IN (" . implode( ',', $ids ) . ') GROUP BY entity_id', ARRAY_A ) as $r ) {
				$files[ (int) $r['entity_id'] ] = (int) $r['n'];
			}
		}
		$items = array();
		foreach ( $rows as $e ) {
			$s            = self::shape( $e, $user );
			$s['links']   = isset( $links[ (int) $e['id'] ] ) ? $links[ (int) $e['id'] ] : array();
			$s['org']     = isset( $orgs[ (int) $e['org_id'] ] ) ? $orgs[ (int) $e['org_id'] ] : null;
			$s['parent']  = isset( $orgs[ (int) $e['parent_id'] ] ) ? $orgs[ (int) $e['parent_id'] ] : null;
			$s['files']   = isset( $files[ (int) $e['id'] ] ) ? $files[ (int) $e['id'] ] : 0;
			$items[]      = $s;
		}
		return new WP_REST_Response(
			array(
				'items' => $items,
				'total' => $total,
				'page'  => $page,
				'pages' => (int) ceil( $total / $per ),
			),
			200
		);
	}

	/**
	 * {id: {id, name}} for a set of entity ids.
	 *
	 * @param int[] $ids Ids.
	 * @return array
	 */
	public static function names_of( $ids ) {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $ids ) ) ) );
		if ( ! $ids ) {
			return array();
		}
		$out = array();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $wpdb->get_results( 'SELECT id, name_bn, name_en, kind, category FROM ' . self::t( 'entities' ) . ' WHERE id IN (' . implode( ',', $ids ) . ')', ARRAY_A ) as $r ) {
			$out[ (int) $r['id'] ] = array(
				'id'       => (int) $r['id'],
				'name'     => '' !== $r['name_bn'] ? $r['name_bn'] : $r['name_en'],
				'kind'     => $r['kind'],
				'category' => $r['category'],
			);
		}
		return $out;
	}

	/**
	 * Validated entity columns from a request (create or patch).
	 *
	 * @param WP_REST_Request $request Request.
	 * @param bool            $create  Whether every field gets a default.
	 * @return array|WP_Error
	 */
	protected static function entity_fields( $request, $create ) {
		$p   = $request->get_json_params();
		$p   = is_array( $p ) ? $p : array();
		$out = array();
		$has = function ( $k ) use ( $p, $create ) {
			return $create || array_key_exists( $k, $p );
		};
		$get = function ( $k, $d = '' ) use ( $p ) {
			return array_key_exists( $k, $p ) ? $p[ $k ] : $d;
		};

		if ( $has( 'category' ) ) {
			$cat = sanitize_key( (string) $get( 'category', 'person' ) );
			if ( ! isset( self::CATEGORIES[ $cat ] ) ) {
				$cat = 'person';
			}
			$out['category'] = $cat;
			$out['kind']     = self::CATEGORIES[ $cat ][0];
		}
		foreach ( array( 'name_bn' => 190, 'name_en' => 190, 'title' => 255, 'country' => 80, 'division' => 100, 'district' => 100, 'upazila' => 100, 'phone' => 120, 'email' => 190 ) as $k => $max ) {
			if ( $has( $k ) ) {
				$out[ $k ] = mb_substr( trim( sanitize_text_field( (string) $get( $k, 'country' === $k ? 'বাংলাদেশ' : '' ) ) ), 0, $max );
			}
		}
		if ( $has( 'aliases' ) ) {
			$al             = $get( 'aliases', '' );
			$out['aliases'] = implode( ', ', self::clean_tags( is_array( $al ) ? $al : (string) $al ) );
		}
		if ( $has( 'tags' ) ) {
			$out['tags'] = implode( ',', self::clean_tags( $get( 'tags', array() ) ) );
		}
		if ( $has( 'bio' ) ) {
			$out['bio'] = mb_substr( sanitize_textarea_field( (string) $get( 'bio', '' ) ), 0, 20000 );
		}
		if ( $has( 'private_note' ) ) {
			$note                = sanitize_textarea_field( (string) $get( 'private_note', '' ) );
			$out['private_note'] = '' !== $note ? NROS_Core::encrypt( mb_substr( $note, 0, 50000 ) ) : '';
		}
		if ( $has( 'importance' ) ) {
			$out['importance'] = max( 1, min( 5, (int) $get( 'importance', 3 ) ) );
		}
		if ( $has( 'status' ) ) {
			$st            = sanitize_key( (string) $get( 'status', 'active' ) );
			$out['status'] = isset( self::STATUSES[ $st ] ) ? $st : 'active';
		}
		if ( $has( 'sensitivity' ) ) {
			$out['sensitivity'] = 'restricted' === $get( 'sensitivity', 'normal' ) ? 'restricted' : 'normal';
		}
		foreach ( array( 'org_id', 'parent_id', 'photo_file_id' ) as $k ) {
			if ( $has( $k ) ) {
				$out[ $k ] = max( 0, (int) $get( $k, 0 ) );
			}
		}
		if ( $create && '' === $out['name_bn'] && '' === $out['name_en'] ) {
			return new WP_Error( 'nros_bad_request', 'নাম দিন (বাংলা অথবা ইংরেজি)।', array( 'status' => 400 ) );
		}
		if ( ! empty( $out['email'] ) && ! is_email( $out['email'] ) ) {
			return new WP_Error( 'nros_bad_request', 'ইমেইল ঠিক নয়।', array( 'status' => 400 ) );
		}
		return $out;
	}

	/**
	 * Similar names already on the desk (for the "আগে থেকেই আছে?" warning).
	 *
	 * @param string  $name_bn Name.
	 * @param string  $name_en Name.
	 * @param WP_User $user    Viewer.
	 * @param int     $except  Id to skip.
	 * @return array
	 */
	protected static function similar( $name_bn, $name_en, $user, $except = 0 ) {
		global $wpdb;
		$te  = self::t( 'entities' );
		$vis = self::visible_sql( $user );
		$or  = array();
		foreach ( array( $name_bn, $name_en ) as $n ) {
			$n = self::norm( $n );
			if ( mb_strlen( $n ) >= 3 ) {
				$or[] = $wpdb->prepare( 'e.search_blob LIKE %s', '% ' . $wpdb->esc_like( $n ) . ' %' );
			}
		}
		if ( ! $or ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, name_bn, name_en, title FROM {$te} e WHERE {$vis} AND e.id <> %d AND (" . implode( ' OR ', $or ) . ') LIMIT 5', (int) $except ), ARRAY_A );
		return array_map(
			function ( $r ) {
				return array(
					'id'    => (int) $r['id'],
					'name'  => '' !== $r['name_bn'] ? $r['name_bn'] : $r['name_en'],
					'title' => $r['title'],
				);
			},
			$rows
		);
	}

	/**
	 * POST /pd/entities — { ...fields, links?: [{url, platform?, label?}], group_ids?: [] }.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_entity( $request ) {
		global $wpdb;
		$user   = self::me( $request );
		$fields = self::entity_fields( $request, true );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}
		if ( ! $request->get_param( 'force' ) ) {
			$dupes = self::similar( $fields['name_bn'], $fields['name_en'], $user );
			if ( $dupes ) {
				return new WP_Error( 'nros_pd_duplicate', 'এই নামে আগে থেকেই প্রোফাইল আছে। তবুও নতুন বানাবেন?', array( 'status' => 409, 'similar' => $dupes ) );
			}
		}
		$now                  = current_time( 'mysql' );
		$fields['created_by'] = (int) $user->ID;
		$fields['updated_by'] = (int) $user->ID;
		$fields['created_at'] = $now;
		$fields['updated_at'] = $now;
		if ( ! $wpdb->insert( self::t( 'entities' ), $fields ) ) {
			return new WP_Error( 'nros_db', 'সংরক্ষণ করা যায়নি।', array( 'status' => 500 ) );
		}
		$id = (int) $wpdb->insert_id;
		foreach ( (array) $request->get_param( 'links' ) as $i => $l ) {
			if ( is_array( $l ) && ! empty( $l['url'] ) ) {
				self::insert_link( $id, $l, $i );
			} elseif ( is_string( $l ) && '' !== trim( $l ) ) {
				self::insert_link( $id, array( 'url' => $l ), $i );
			}
		}
		foreach ( array_map( 'intval', (array) $request->get_param( 'group_ids' ) ) as $gid ) {
			self::add_member( $gid, $id );
		}
		self::reindex( $id );
		self::audit( $user->ID, 'entity_create', 'entity', $id, $fields['name_bn'] ?: $fields['name_en'] );
		$request->set_param( 'id', $id );
		$res = self::get_entity( $request );
		if ( $res instanceof WP_REST_Response ) {
			$res->set_status( 201 );
		}
		return $res;
	}

	/**
	 * Inserts one link (platform detected from the URL when not given).
	 *
	 * @param int   $entity_id Entity.
	 * @param array $l         { url, platform?, label?, handle?, verified? }.
	 * @param int   $order     Sort order.
	 * @return int|WP_Error Link id.
	 */
	public static function insert_link( $entity_id, $l, $order = 0 ) {
		global $wpdb;
		$url = trim( (string) $l['url'] );
		if ( '' !== $url && ! preg_match( '#^[a-z][a-z0-9+.-]*://#i', $url ) ) {
			$url = 'https://' . $url;
		}
		$url = esc_url_raw( $url, array( 'http', 'https' ) );
		if ( '' === $url ) {
			return new WP_Error( 'nros_bad_request', 'লিংক ঠিক নয়।', array( 'status' => 400 ) );
		}
		$platform = isset( $l['platform'] ) ? sanitize_key( (string) $l['platform'] ) : '';
		if ( ! isset( self::PLATFORMS[ $platform ] ) ) {
			$platform = self::detect_platform( $url );
		}
		$handle = isset( $l['handle'] ) && '' !== trim( (string) $l['handle'] ) ? sanitize_text_field( (string) $l['handle'] ) : self::guess_handle( $url );
		$feed   = NROS_PD_Feeds::feed_for( $platform, $url );
		$wpdb->insert(
			self::t( 'links' ),
			array(
				'entity_id'  => (int) $entity_id,
				'platform'   => $platform,
				'url'        => substr( $url, 0, 500 ),
				'url_norm'   => self::norm_url( $url ),
				'handle'     => mb_substr( $handle, 0, 190 ),
				'label'      => isset( $l['label'] ) ? mb_substr( sanitize_text_field( (string) $l['label'] ), 0, 190 ) : '',
				'verified'   => ! empty( $l['verified'] ) ? 1 : 0,
				'feed_kind'  => $feed['kind'],
				'feed_url'   => $feed['url'],
				'feed_state' => $feed['state'],
				'sort_order' => (int) $order,
				'created_at' => current_time( 'mysql' ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * GET /pd/entities/{id} — the whole profile.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_entity( $request ) {
		global $wpdb;
		$user = self::me( $request );
		$e    = self::entity_row( (int) $request->get_param( 'id' ), $user );
		if ( ! $e ) {
			return new WP_Error( 'nros_not_found', 'প্রোফাইল পাওয়া যায়নি।', array( 'status' => 404 ) );
		}
		$id    = (int) $e['id'];
		$out   = self::shape( $e, $user, true );
		$links = self::links_for( array( $id ), $user );

		$out['links']  = isset( $links[ $id ] ) ? $links[ $id ] : array();
		$names         = self::names_of( array( $e['org_id'], $e['parent_id'] ) );
		$out['org']    = isset( $names[ (int) $e['org_id'] ] ) ? $names[ (int) $e['org_id'] ] : null;
		$out['parent'] = isset( $names[ (int) $e['parent_id'] ] ) ? $names[ (int) $e['parent_id'] ] : null;

		$tg = self::t( 'groups' );
		$tm = self::t( 'group_members' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out['groups'] = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT g.id FROM {$tg} g INNER JOIN {$tm} m ON m.group_id = g.id WHERE m.entity_id = %d", $id ) ) );

		// people / branches under an organisation
		$te  = self::t( 'entities' );
		$vis = self::visible_sql( $user );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$under = $wpdb->get_results( $wpdb->prepare( "SELECT id, name_bn, name_en, title, kind, category, importance FROM {$te} e WHERE {$vis} AND (e.org_id = %d OR e.parent_id = %d) ORDER BY e.importance DESC, e.name_bn ASC LIMIT 200", $id, $id ), ARRAY_A );
		$out['members'] = array_map(
			function ( $r ) {
				return array(
					'id'         => (int) $r['id'],
					'name'       => '' !== $r['name_bn'] ? $r['name_bn'] : $r['name_en'],
					'title'      => $r['title'],
					'kind'       => $r['kind'],
					'category'   => $r['category'],
					'importance' => (int) $r['importance'],
				);
			},
			$under
		);
		$out['files']        = NROS_PD_Files::entity_files( $id, $user, $request );
		$out['recent_items'] = NROS_PD_Feeds::recent_items_for_entity( $id, 12 );
		$out['creator']      = $e['created_by'] ? get_the_author_meta( 'display_name', (int) $e['created_by'] ) : '';
		if ( 'restricted' === $e['sensitivity'] ) {
			self::audit( $user->ID, 'entity_view', 'entity', $id, $out['name'] );
		}
		return new WP_REST_Response( $out, 200 );
	}

	/**
	 * PATCH /pd/entities/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_entity( $request ) {
		global $wpdb;
		$user = self::me( $request );
		$e    = self::entity_row( (int) $request->get_param( 'id' ), $user );
		if ( ! $e ) {
			return new WP_Error( 'nros_not_found', 'প্রোফাইল পাওয়া যায়নি।', array( 'status' => 404 ) );
		}
		$fields = self::entity_fields( $request, false );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}
		// only the Publisher or the creator may change who can see it
		if ( isset( $fields['sensitivity'] ) && ! self::is_boss( $user ) && (int) $e['created_by'] !== (int) $user->ID ) {
			unset( $fields['sensitivity'] );
		}
		if ( isset( $fields['org_id'] ) && (int) $fields['org_id'] === (int) $e['id'] ) {
			$fields['org_id'] = 0;
		}
		if ( isset( $fields['parent_id'] ) && (int) $fields['parent_id'] === (int) $e['id'] ) {
			$fields['parent_id'] = 0;
		}
		if ( isset( $fields['photo_file_id'] ) && $fields['photo_file_id'] && ! NROS_PD_Files::file_belongs( $fields['photo_file_id'], (int) $e['id'] ) ) {
			unset( $fields['photo_file_id'] );
		}
		$fields['updated_by'] = (int) $user->ID;
		$fields['updated_at'] = current_time( 'mysql' );
		$wpdb->update( self::t( 'entities' ), $fields, array( 'id' => (int) $e['id'] ) );
		$gids = $request->get_param( 'group_ids' );
		if ( is_array( $gids ) ) {
			$wpdb->delete( self::t( 'group_members' ), array( 'entity_id' => (int) $e['id'] ) );
			foreach ( array_map( 'intval', $gids ) as $gid ) {
				self::add_member( $gid, (int) $e['id'] );
			}
		}
		self::reindex( (int) $e['id'] );
		// people under this organisation carry its name in their search text
		if ( 'org' === $e['kind'] && ( isset( $fields['name_bn'] ) || isset( $fields['name_en'] ) || isset( $fields['aliases'] ) ) ) {
			$te = self::t( 'entities' );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$te} WHERE org_id = %d LIMIT 500", (int) $e['id'] ) ) as $mid ) {
				self::reindex( (int) $mid );
			}
		}
		self::audit( $user->ID, 'entity_update', 'entity', (int) $e['id'], implode( ',', array_keys( $fields ) ) );
		return self::get_entity( $request );
	}

	/**
	 * DELETE /pd/entities/{id} — Publisher or the creator. Files under it are
	 * deleted from disk too; vault files tagged with it only lose the tag.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_entity( $request ) {
		global $wpdb;
		$user = self::me( $request );
		$e    = self::entity_row( (int) $request->get_param( 'id' ), $user );
		if ( ! $e ) {
			return new WP_Error( 'nros_not_found', 'প্রোফাইল পাওয়া যায়নি।', array( 'status' => 404 ) );
		}
		if ( ! self::is_boss( $user ) && (int) $e['created_by'] !== (int) $user->ID ) {
			return new WP_Error( 'nros_forbidden', 'শুধু পাবলিশার বা যিনি যোগ করেছেন তিনি মুছতে পারবেন।', array( 'status' => 403 ) );
		}
		$id = (int) $e['id'];
		NROS_PD_Files::delete_entity_files( $id );
		$tl = self::t( 'links' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$tl} WHERE entity_id = %d", $id ) ) as $lid ) {
			NROS_PD_Feeds::forget_link( (int) $lid );
		}
		$wpdb->delete( $tl, array( 'entity_id' => $id ) );
		$wpdb->delete( self::t( 'group_members' ), array( 'entity_id' => $id ) );
		$wpdb->update( self::t( 'entities' ), array( 'org_id' => 0 ), array( 'org_id' => $id ) );
		$wpdb->update( self::t( 'entities' ), array( 'parent_id' => 0 ), array( 'parent_id' => $id ) );
		$wpdb->delete( self::t( 'entities' ), array( 'id' => $id ) );
		self::audit( $user->ID, 'entity_delete', 'entity', $id, '' !== $e['name_bn'] ? $e['name_bn'] : $e['name_en'] );
		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/**
	 * A link row the viewer may touch, or WP_Error.
	 *
	 * @param int     $id   Link id.
	 * @param WP_User $user User.
	 * @return array|WP_Error
	 */
	protected static function link_row( $id, $user ) {
		global $wpdb;
		$l = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'links' ) . ' WHERE id = %d', (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $l || ! self::entity_row( (int) $l['entity_id'], $user ) ) {
			return new WP_Error( 'nros_not_found', 'লিংক পাওয়া যায়নি।', array( 'status' => 404 ) );
		}
		return $l;
	}

	/**
	 * POST /pd/entities/{id}/links — { url, platform?, label?, handle?, verified? }
	 * or { urls: "many links, one per line" }.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function add_link( $request ) {
		global $wpdb;
		$user = self::me( $request );
		$e    = self::entity_row( (int) $request->get_param( 'id' ), $user );
		if ( ! $e ) {
			return new WP_Error( 'nros_not_found', 'প্রোফাইল পাওয়া যায়নি।', array( 'status' => 404 ) );
		}
		$tl    = self::t( 'links' );
		$order = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(sort_order), 0) FROM {$tl} WHERE entity_id = %d", (int) $e['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$many  = (string) $request->get_param( 'urls' );
		$list  = '' !== trim( $many ) ? array_map( function ( $u ) { return array( 'url' => $u ); }, preg_split( '/[\s,]+/', trim( $many ) ) ) : array( $request->get_json_params() );
		$added = 0;
		foreach ( $list as $l ) {
			if ( empty( $l['url'] ) ) {
				continue;
			}
			$n = self::norm_url( (string) $l['url'] );
			if ( $n && $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$tl} WHERE entity_id = %d AND url_norm = %s", (int) $e['id'], $n ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				continue;
			}
			$r = self::insert_link( (int) $e['id'], $l, ++$order );
			if ( is_wp_error( $r ) ) {
				if ( 1 === count( $list ) ) {
					return $r;
				}
				continue;
			}
			++$added;
		}
		if ( ! $added && 1 === count( $list ) ) {
			return new WP_Error( 'nros_pd_link_exists', 'এই লিংক এই প্রোফাইলে আগে থেকেই আছে।', array( 'status' => 409 ) );
		}
		self::reindex( (int) $e['id'] );
		$links = self::links_for( array( (int) $e['id'] ), $user );
		return new WP_REST_Response( array( 'added' => $added, 'links' => isset( $links[ (int) $e['id'] ] ) ? $links[ (int) $e['id'] ] : array() ), 201 );
	}

	/**
	 * PATCH /pd/links/{id} — { url?, platform?, label?, handle?, verified?, sort_order? }.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_link( $request ) {
		global $wpdb;
		$user = self::me( $request );
		$l    = self::link_row( (int) $request->get_param( 'id' ), $user );
		if ( is_wp_error( $l ) ) {
			return $l;
		}
		$p   = (array) $request->get_json_params();
		$upd = array();
		if ( isset( $p['url'] ) && '' !== trim( (string) $p['url'] ) ) {
			$url = trim( (string) $p['url'] );
			if ( ! preg_match( '#^[a-z][a-z0-9+.-]*://#i', $url ) ) {
				$url = 'https://' . $url;
			}
			$url = esc_url_raw( $url, array( 'http', 'https' ) );
			if ( '' === $url ) {
				return new WP_Error( 'nros_bad_request', 'লিংক ঠিক নয়।', array( 'status' => 400 ) );
			}
			$upd['url']      = substr( $url, 0, 500 );
			$upd['url_norm'] = self::norm_url( $url );
		}
		if ( isset( $p['platform'] ) && isset( self::PLATFORMS[ $p['platform'] ] ) ) {
			$upd['platform'] = $p['platform'];
		} elseif ( isset( $upd['url'] ) ) {
			$upd['platform'] = self::detect_platform( $upd['url'] );
		}
		if ( isset( $upd['url'] ) || isset( $upd['platform'] ) ) {
			$feed              = NROS_PD_Feeds::feed_for( isset( $upd['platform'] ) ? $upd['platform'] : $l['platform'], isset( $upd['url'] ) ? $upd['url'] : $l['url'] );
			$upd['feed_kind']  = $feed['kind'];
			$upd['feed_url']   = $feed['url'];
			$upd['feed_state'] = $feed['state'];
			$upd['feed_error'] = '';
		}
		foreach ( array( 'label', 'handle' ) as $k ) {
			if ( isset( $p[ $k ] ) ) {
				$upd[ $k ] = mb_substr( sanitize_text_field( (string) $p[ $k ] ), 0, 190 );
			}
		}
		if ( isset( $p['verified'] ) ) {
			$upd['verified'] = max( 0, min( 2, (int) $p['verified'] ) ); // 0 unchecked, 1 verified, 2 fake/closed
		}
		if ( isset( $p['sort_order'] ) ) {
			$upd['sort_order'] = (int) $p['sort_order'];
		}
		if ( $upd ) {
			$wpdb->update( self::t( 'links' ), $upd, array( 'id' => (int) $l['id'] ) );
			self::reindex( (int) $l['entity_id'] );
		}
		$links = self::links_for( array( (int) $l['entity_id'] ), $user );
		foreach ( $links[ (int) $l['entity_id'] ] as $one ) {
			if ( $one['id'] === (int) $l['id'] ) {
				return new WP_REST_Response( $one, 200 );
			}
		}
		return new WP_REST_Response( array(), 200 );
	}

	/**
	 * DELETE /pd/links/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_link( $request ) {
		global $wpdb;
		$user = self::me( $request );
		$l    = self::link_row( (int) $request->get_param( 'id' ), $user );
		if ( is_wp_error( $l ) ) {
			return $l;
		}
		NROS_PD_Feeds::forget_link( (int) $l['id'] );
		$wpdb->delete( self::t( 'links' ), array( 'id' => (int) $l['id'] ) );
		self::reindex( (int) $l['entity_id'] );
		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	// ---------------------------------------------------------------------
	// Groups
	// ---------------------------------------------------------------------

	/**
	 * Adds one entity to a group (no-op when either is missing).
	 *
	 * @param int $gid Group.
	 * @param int $eid Entity.
	 */
	protected static function add_member( $gid, $eid ) {
		global $wpdb;
		if ( $gid <= 0 || $eid <= 0 ) {
			return;
		}
		$tg = self::t( 'groups' );
		if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$tg} WHERE id = %d", $gid ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return;
		}
		$tm    = self::t( 'group_members' );
		$order = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(sort_order), 0) FROM {$tm} WHERE group_id = %d", $gid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$tm} (group_id, entity_id, sort_order) VALUES (%d, %d, %d)", $gid, $eid, $order + 1 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * GET /pd/groups — every group with its members' links (for "সব খুলুন").
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function list_groups( $request ) {
		global $wpdb;
		$user = self::me( $request );
		$tg   = self::t( 'groups' );
		$tm   = self::t( 'group_members' );
		$te   = self::t( 'entities' );
		$vis  = self::visible_sql( $user );
		$out  = array();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$groups = $wpdb->get_results( "SELECT * FROM {$tg} ORDER BY sort_order ASC, id ASC", ARRAY_A );
		$rows   = $wpdb->get_results( "SELECT m.group_id, e.id, e.name_bn, e.name_en, e.photo_file_id FROM {$tm} m INNER JOIN {$te} e ON e.id = m.entity_id WHERE {$vis} ORDER BY m.sort_order ASC", ARRAY_A );
		// phpcs:enable
		$by    = array();
		$ids   = array();
		foreach ( $rows as $r ) {
			$by[ (int) $r['group_id'] ][] = $r;
			$ids[]                        = (int) $r['id'];
		}
		$links = self::links_for( array_unique( $ids ), $user );
		foreach ( $groups as $g ) {
			$members = array();
			foreach ( isset( $by[ (int) $g['id'] ] ) ? $by[ (int) $g['id'] ] : array() as $r ) {
				$members[] = array(
					'id'    => (int) $r['id'],
					'name'  => '' !== $r['name_bn'] ? $r['name_bn'] : $r['name_en'],
					'photo' => (int) $r['photo_file_id'] ? NROS_PD_Files::signed_url( (int) $r['photo_file_id'], (int) $user->ID, 4 * HOUR_IN_SECONDS ) : '',
					'links' => isset( $links[ (int) $r['id'] ] ) ? $links[ (int) $r['id'] ] : array(),
				);
			}
			$out[] = array(
				'id'         => (int) $g['id'],
				'name'       => $g['name'],
				'color'      => self::group_color( $g['color'] ),
				'note'       => $g['note'],
				'created_by' => (int) $g['created_by'],
				'members'    => $members,
			);
		}
		return new WP_REST_Response( $out, 200 );
	}

	/**
	 * A group colour as "#rrggbb" (older rows may hold a bare hex or a word).
	 *
	 * @param mixed $c Colour.
	 * @return string
	 */
	public static function group_color( $c ) {
		$c = ltrim( strtolower( trim( (string) $c ) ), '#' );
		return preg_match( '/^[0-9a-f]{6}$/', $c ) ? '#' . $c : '#475569';
	}

	/**
	 * POST /pd/groups — { name, color?, note?, entity_ids? }.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_group( $request ) {
		global $wpdb;
		$user = self::me( $request );
		$name = mb_substr( trim( sanitize_text_field( (string) $request->get_param( 'name' ) ) ), 0, 190 );
		if ( '' === $name ) {
			return new WP_Error( 'nros_bad_request', 'গ্রুপের নাম দিন।', array( 'status' => 400 ) );
		}
		$tg = self::t( 'groups' );
		$wpdb->insert(
			$tg,
			array(
				'name'       => $name,
				'color'      => self::group_color( $request->get_param( 'color' ) ),
				'note'       => mb_substr( sanitize_text_field( (string) $request->get_param( 'note' ) ), 0, 255 ),
				'sort_order' => (int) $wpdb->get_var( "SELECT COALESCE(MAX(sort_order), 0) + 1 FROM {$tg}" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'created_by' => (int) $user->ID,
				'created_at' => current_time( 'mysql' ),
			)
		);
		$gid = (int) $wpdb->insert_id;
		foreach ( array_map( 'intval', (array) $request->get_param( 'entity_ids' ) ) as $eid ) {
			if ( self::entity_row( $eid, $user ) ) {
				self::add_member( $gid, $eid );
			}
		}
		self::audit( $user->ID, 'group_create', 'group', $gid, $name );
		return new WP_REST_Response( array( 'id' => $gid ), 201 );
	}

	/**
	 * PATCH /pd/groups/{id} — { name?, color?, note?, add?: [], remove?: [], order?: [] }.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_group( $request ) {
		global $wpdb;
		$user = self::me( $request );
		$gid  = (int) $request->get_param( 'id' );
		$tg   = self::t( 'groups' );
		$tm   = self::t( 'group_members' );
		if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$tg} WHERE id = %d", $gid ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return new WP_Error( 'nros_not_found', 'গ্রুপ পাওয়া যায়নি।', array( 'status' => 404 ) );
		}
		$upd = array();
		foreach ( array( 'name' => 190, 'note' => 255 ) as $k => $max ) {
			if ( null !== $request->get_param( $k ) ) {
				$upd[ $k ] = mb_substr( trim( sanitize_text_field( (string) $request->get_param( $k ) ) ), 0, $max );
			}
		}
		if ( isset( $upd['name'] ) && '' === $upd['name'] ) {
			unset( $upd['name'] );
		}
		if ( null !== $request->get_param( 'color' ) ) {
			$upd['color'] = self::group_color( $request->get_param( 'color' ) );
		}
		if ( $upd ) {
			$wpdb->update( $tg, $upd, array( 'id' => $gid ) );
		}
		foreach ( array_map( 'intval', (array) $request->get_param( 'add' ) ) as $eid ) {
			if ( self::entity_row( $eid, $user ) ) {
				self::add_member( $gid, $eid );
			}
		}
		foreach ( array_map( 'intval', (array) $request->get_param( 'remove' ) ) as $eid ) {
			$wpdb->delete( $tm, array( 'group_id' => $gid, 'entity_id' => $eid ) );
		}
		foreach ( array_values( array_map( 'intval', (array) $request->get_param( 'order' ) ) ) as $i => $eid ) {
			$wpdb->update( $tm, array( 'sort_order' => $i + 1 ), array( 'group_id' => $gid, 'entity_id' => $eid ) );
		}
		return new WP_REST_Response( array( 'id' => $gid ), 200 );
	}

	/**
	 * DELETE /pd/groups/{id} — the group only, never its people.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function delete_group( $request ) {
		global $wpdb;
		$gid = (int) $request->get_param( 'id' );
		$wpdb->delete( self::t( 'group_members' ), array( 'group_id' => $gid ) );
		$wpdb->delete( self::t( 'groups' ), array( 'id' => $gid ) );
		self::audit( self::me( $request )->ID, 'group_delete', 'group', $gid );
		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	// ---------------------------------------------------------------------
	// Access management + audit (Publisher)
	// ---------------------------------------------------------------------

	/**
	 * GET /pd/access — every Editor/Publisher with their desk access.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function list_access( $request ) {
		$users = get_users(
			array(
				'role__in' => array( 'administrator', 'meeha_publisher', 'editor' ),
				'orderby'  => 'display_name',
				'number'   => 300,
			)
		);
		$out = array();
		foreach ( $users as $u ) {
			$boss  = self::is_boss( $u );
			$out[] = array(
				'id'     => (int) $u->ID,
				'name'   => $u->display_name,
				'email'  => $u->user_email,
				'role'   => $boss ? 'publisher' : 'editor',
				'access' => $boss || '1' === (string) get_user_meta( $u->ID, 'nros_pd_access', true ),
				'fixed'  => $boss,
				'avatar' => NROS_Media::avatar_url( $u->ID ),
			);
		}
		return new WP_REST_Response( $out, 200 );
	}

	/**
	 * POST /pd/access — { user_id, access: bool }.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function set_access( $request ) {
		$uid = (int) $request->get_param( 'user_id' );
		$u   = get_userdata( $uid );
		if ( ! $u || ! user_can( $u, 'edit_others_posts' ) ) {
			return new WP_Error( 'nros_bad_request', 'শুধু এডিটর বা পাবলিশারকে অ্যাক্সেস দেওয়া যায়।', array( 'status' => 400 ) );
		}
		if ( self::is_boss( $u ) ) {
			return new WP_REST_Response( array( 'access' => true ), 200 );
		}
		$on = (bool) $request->get_param( 'access' );
		if ( $on ) {
			update_user_meta( $uid, 'nros_pd_access', '1' );
		} else {
			delete_user_meta( $uid, 'nros_pd_access' );
			NROS_PD_Files::lock_all_for( $uid );
		}
		self::audit( self::me( $request )->ID, $on ? 'access_grant' : 'access_revoke', 'user', $uid, $u->display_name );
		return new WP_REST_Response( array( 'access' => $on ), 200 );
	}

	/**
	 * GET /pd/audit — the last 300 lines.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function list_audit( $request ) {
		global $wpdb;
		$ta   = self::t( 'audit' );
		$rows = $wpdb->get_results( "SELECT * FROM {$ta} ORDER BY id DESC LIMIT 300", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out  = array();
		foreach ( $rows as $r ) {
			$out[] = array(
				'id'          => (int) $r['id'],
				'user'        => $r['user_id'] ? get_the_author_meta( 'display_name', (int) $r['user_id'] ) : '',
				'action'      => $r['action'],
				'object_type' => $r['object_type'],
				'object_id'   => (int) $r['object_id'],
				'detail'      => $r['detail'],
				'ip'          => $r['ip'],
				'created_at'  => $r['created_at'],
			);
		}
		return new WP_REST_Response( $out, 200 );
	}
}
