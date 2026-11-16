<?php
/**
 * NROS_Tips — গোপন তথ্য পাঠান (secure tipline), r46 / newsroom-os DB 17.
 *
 * A reader sends a tip from the website's /tips/ page without an account:
 * text, optional name/phone/email (kept encrypted at rest), up to five
 * files (images/PDF/audio/video, ≤ 25 MB each) stored in the non-public
 * nros-private/tips folder and only ever read through short-lived signed
 * links, a category and a district. They get a random tracking code + PIN
 * to follow the tip anonymously (status, the desk's replies, a follow-up
 * message).
 *
 * Privacy: the IP address is never stored — only a keyed hash, used for the
 * rate limit (5 an hour, 20 a day) and the duplicate check. Images are
 * re-encoded server-side (Imagick → GD) so EXIF/GPS never reaches the desk.
 * Tipster contact details are visible to Editors/Publishers only, never to a
 * correspondent the tip is routed to.
 *
 * Automation:
 *  - tracking code + PIN returned immediately (the “auto-reply”), plus a
 *    first system reply on the status page;
 *  - auto-route by district: the correspondent of that district with the
 *    fewest open tips becomes the default assignee, else an Editor;
 *  - urgent keywords / the “জীবনের ঝুঁকি” box / urgent categories → desk
 *    alert to all staff (NROS_Notifications → also web-push);
 *  - spam (honeypot, same text within 24 h) is closed automatically as
 *    “বাতিল (স্বয়ংক্রিয়)”;
 *  - daily digest to staff of tips still “নতুন” after 48 hours, and files of
 *    tips closed > 30 days ago are deleted from disk.
 *
 * Routes (nros/v1):
 *   POST   /tips                         public (origin-checked) — submit
 *   POST   /tips/status                  public — {code, pin} → status + replies
 *   POST   /tips/followup                public — {code, pin, body}
 *   GET    /tips/files/{id}?t=           signed link (staff / assignee)
 *   GET    /admin/tips                   staff — list (+ counts per status)
 *   GET    /admin/tips/people            staff — people a tip can be assigned to
 *   GET    /admin/tips/{id}              staff — one tip (marks it read)
 *   PATCH  /admin/tips/{id}              staff — status / assignee / published_url
 *   POST   /admin/tips/{id}/notes        staff — internal note or reply to tipster
 *   DELETE /admin/tips/{id}              publisher — erase tip + files
 *   GET    /tips/assigned                correspondent — tips routed to me
 *   GET    /tips/assigned/{id}           correspondent — one (no tipster contact)
 *   POST   /tips/assigned/{id}/notes     correspondent — internal note
 *
 * @package NewsroomOS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NROS_Tips {

	const CRON_DAILY = 'nros_tips_daily';

	/** Largest single file (bytes). */
	const MAX_FILE = 25 * MB_IN_BYTES;

	/** Most files in one tip. */
	const MAX_FILES = 5;

	/** Rate limits per IP (hashed). */
	const PER_HOUR = 5;
	const PER_DAY  = 20;

	/** Status lookups that may fail per IP an hour before it is paused. */
	const LOOKUP_FAILS = 12;

	const CATEGORIES = array(
		'corruption'  => 'দুর্নীতি',
		'crime'       => 'অপরাধ',
		'health'      => 'স্বাস্থ্য',
		'education'   => 'শিক্ষা',
		'environment' => 'পরিবেশ',
		'other'       => 'অন্যান্য',
	);

	const STATUSES = array(
		'new'       => 'নতুন',
		'verifying' => 'যাচাই হচ্ছে',
		'assigned'  => 'অ্যাসাইন',
		'rejected'  => 'বাতিল',
		'published' => 'প্রকাশিত',
	);

	/** What the tipster sees for each status. */
	const PUBLIC_STATUS = array(
		'new'       => 'আমরা আপনার তথ্য পেয়েছি — শিগগিরই একজন সম্পাদক দেখবেন।',
		'verifying' => 'আমাদের সম্পাদকেরা তথ্যটি যাচাই করছেন।',
		'assigned'  => 'একজন সাংবাদিক বিষয়টি নিয়ে কাজ করছেন।',
		'rejected'  => 'এই তথ্য নিয়ে এখন প্রতিবেদন করা সম্ভব হচ্ছে না।',
		'published' => 'আপনার তথ্যের ভিত্তিতে প্রতিবেদন প্রকাশিত হয়েছে।',
	);

	/** Words that make a tip urgent (desk alert). Filter: nros_tips_urgent_words. */
	const URGENT_WORDS = array( 'হুমকি', 'হত্যা', 'খুন', 'জরুরি', 'ধর্ষণ', 'অপহরণ', 'গুম', 'নির্যাতন', 'প্রাণনাশ', 'বিস্ফোরণ', 'অগ্নিকাণ্ড', 'জীবন ঝুঁকি', 'জীবনের ঝুঁকি' );

	/** Tracking-code alphabet (no 0/O/1/I). */
	const CODE_ABC = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( self::CRON_DAILY, array( __CLASS__, 'daily' ) );
		if ( ! wp_next_scheduled( self::CRON_DAILY ) ) {
			wp_schedule_event( strtotime( 'tomorrow 10:00' ) - (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ), 'daily', self::CRON_DAILY );
		}
	}

	// ------------------------------------------------------------------
	// tables
	// ------------------------------------------------------------------

	public static function t( $name = '' ) {
		global $wpdb;
		return $wpdb->prefix . 'nros_tips' . ( $name ? '_' . $name : '' );
	}

	public static function schema( $c ) {
		$t = self::t();
		$f = self::t( 'files' );
		$n = self::t( 'notes' );
		return array(
			"CREATE TABLE {$t} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			code VARCHAR(20) NOT NULL,
			pin_hash VARCHAR(255) NOT NULL DEFAULT '',
			body LONGTEXT,
			category VARCHAR(20) NOT NULL DEFAULT 'other',
			district VARCHAR(60) NOT NULL DEFAULT '',
			contact TEXT,
			has_contact TINYINT(1) NOT NULL DEFAULT 0,
			status VARCHAR(16) NOT NULL DEFAULT 'new',
			auto_closed TINYINT(1) NOT NULL DEFAULT 0,
			close_reason VARCHAR(40) NOT NULL DEFAULT '',
			urgent TINYINT(1) NOT NULL DEFAULT 0,
			assignee_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			routed VARCHAR(20) NOT NULL DEFAULT '',
			published_url VARCHAR(500) NOT NULL DEFAULT '',
			body_hash CHAR(40) NOT NULL DEFAULT '',
			ip_hash CHAR(40) NOT NULL DEFAULT '',
			files_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			unread TINYINT(1) NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY code (code),
			KEY status (status),
			KEY assignee_id (assignee_id),
			KEY body_hash (body_hash),
			KEY created_at (created_at)
		) {$c};",
			"CREATE TABLE {$f} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tip_id BIGINT UNSIGNED NOT NULL,
			disk_name VARCHAR(80) NOT NULL,
			name VARCHAR(190) NOT NULL DEFAULT '',
			mime VARCHAR(100) NOT NULL DEFAULT '',
			kind VARCHAR(10) NOT NULL DEFAULT 'file',
			size BIGINT UNSIGNED NOT NULL DEFAULT 0,
			stripped TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY tip_id (tip_id)
		) {$c};",
			"CREATE TABLE {$n} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tip_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			kind VARCHAR(10) NOT NULL DEFAULT 'note',
			body TEXT,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY tip_id (tip_id)
		) {$c};",
		);
	}

	protected static function ready() {
		global $wpdb;
		static $ok = null;
		if ( null === $ok ) {
			$ok = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', self::t() ) );
		}
		return $ok;
	}

	// ------------------------------------------------------------------
	// routes
	// ------------------------------------------------------------------

	public static function routes() {
		$ns   = NROS_API_NS;
		$open = '__return_true';
		$stf  = array( 'NROS_Core', 'require_staff' );
		$pub  = array( 'NROS_Core', 'require_publisher' );
		$cor  = array( 'NROS_Core', 'require_correspondent' );
		$r    = function ( $path, $method, $cb, $perm ) use ( $ns ) {
			register_rest_route( $ns, $path, array( 'methods' => $method, 'callback' => array( __CLASS__, $cb ), 'permission_callback' => $perm ) );
		};
		// Public. No nonce (the page is cached for everyone); the origin is checked instead, see origin_ok().
		$r( '/tips', 'POST', 'submit', $open );
		$r( '/tips/status', 'POST', 'status', $open );
		$r( '/tips/followup', 'POST', 'followup', $open );
		$r( '/tips/files/(?P<id>\d+)', 'GET', 'stream', $open ); // checked by the signed token.
		// Desk.
		$r( '/admin/tips', 'GET', 'admin_list', $stf );
		$r( '/admin/tips/people', 'GET', 'admin_people', $stf );
		$r( '/admin/tips/(?P<id>\d+)', 'GET', 'admin_get', $stf );
		$r( '/admin/tips/(?P<id>\d+)', 'PATCH', 'admin_update', $stf );
		$r( '/admin/tips/(?P<id>\d+)', 'DELETE', 'admin_delete', $pub );
		$r( '/admin/tips/(?P<id>\d+)/notes', 'POST', 'admin_note', $stf );
		// Correspondent the tip was routed to.
		$r( '/tips/assigned', 'GET', 'mine_list', $cor );
		$r( '/tips/assigned/(?P<id>\d+)', 'GET', 'mine_get', $cor );
		$r( '/tips/assigned/(?P<id>\d+)/notes', 'POST', 'mine_note', $cor );
	}

	// ------------------------------------------------------------------
	// helpers
	// ------------------------------------------------------------------

	/** Limits the website form shows. */
	public static function limits() {
		$max = (int) min( self::MAX_FILE, wp_max_upload_size() );
		return array(
			'max_file'   => $max,
			'max_files'  => self::MAX_FILES,
			'categories' => self::CATEGORIES,
			'endpoint'   => rest_url( NROS_API_NS . '/tips' ),
			'status_url' => rest_url( NROS_API_NS . '/tips/status' ),
			'follow_url' => rest_url( NROS_API_NS . '/tips/followup' ),
			'accept'     => 'image/jpeg,image/png,image/webp,image/gif,application/pdf,audio/*,video/mp4,video/webm,video/quicktime,video/3gpp',
		);
	}

	protected static function now() {
		return current_time( 'mysql' );
	}

	protected static function bn( $v ) {
		return NROS_Core::bn_digits( $v );
	}

	protected static function iso( $local ) {
		return $local ? mysql_to_rfc3339( get_gmt_from_date( $local ) ) . 'Z' : null;
	}

	/** Keyed hash of the client IP — the IP itself is never stored. */
	protected static function ip_hash() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) ) : '';
		return sha1( wp_salt( 'nonce' ) . '|nros-tips|' . $ip );
	}

	/**
	 * Public POSTs must come from the website itself (or one of the apps):
	 * Origin, else Referer, must name an allowed host.
	 */
	protected static function origin_ok( $request ) {
		$hosts = array( wp_parse_url( home_url(), PHP_URL_HOST ), wp_parse_url( site_url(), PHP_URL_HOST ) );
		foreach ( NROS_Core::allowed_origins() as $o ) {
			$hosts[] = wp_parse_url( $o, PHP_URL_HOST );
		}
		$hosts  = array_filter( array_map( 'strtolower', (array) apply_filters( 'nros_tips_allowed_hosts', $hosts ) ) );
		$origin = (string) $request->get_header( 'origin' );
		if ( '' === $origin || 'null' === $origin ) {
			$origin = (string) $request->get_header( 'referer' );
		}
		$host = strtolower( (string) wp_parse_url( $origin, PHP_URL_HOST ) );
		if ( '' === $host ) {
			return false;
		}
		$port = wp_parse_url( $origin, PHP_URL_PORT );
		$home = wp_parse_url( home_url() );
		// a dev site on a port (localhost:8080) — the port must match too.
		if ( $port && isset( $home['host'] ) && strtolower( $home['host'] ) === $host ) {
			return isset( $home['port'] ) && (int) $home['port'] === (int) $port;
		}
		return in_array( $host, $hosts, true );
	}

	protected static function forbidden_origin() {
		return new WP_Error( 'nros_tips_origin', 'এই ফর্মটি শুধু মিহা নিউজের ওয়েবসাইট থেকে পাঠানো যায়।', array( 'status' => 403 ) );
	}

	/**
	 * Sliding-window counter in a transient.
	 *
	 * @return int[] Timestamps inside the last day.
	 */
	protected static function hits( $key ) {
		$h = get_transient( $key );
		$h = is_array( $h ) ? $h : array();
		return array_values( array_filter( $h, function ( $t ) { return time() - (int) $t < DAY_IN_SECONDS; } ) );
	}

	protected static function new_code() {
		global $wpdb;
		$abc = self::CODE_ABC;
		for ( $try = 0; $try < 8; $try++ ) {
			$s = '';
			for ( $i = 0; $i < 8; $i++ ) {
				$s .= $abc[ random_int( 0, strlen( $abc ) - 1 ) ];
			}
			$code = 'MH-' . substr( $s, 0, 4 ) . '-' . substr( $s, 4 );
			if ( ! $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t() . ' WHERE code = %s', $code ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				return $code;
			}
		}
		return 'MH-' . strtoupper( wp_generate_password( 9, false ) );
	}

	protected static function norm_code( $code ) {
		$c = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $code ) );
		if ( 0 === strpos( $c, 'MH' ) ) {
			$c = substr( $c, 2 );
		}
		return strlen( $c ) >= 8 ? 'MH-' . substr( $c, 0, 4 ) . '-' . substr( $c, 4 ) : '';
	}

	/** Bengali → ASCII digits (PIN typed on a Bengali keyboard). */
	protected static function ascii_digits( $s ) {
		return strtr( (string) $s, array( '০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9' ) );
	}

	protected static function row( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	protected static function note( $tip_id, $uid, $kind, $body ) {
		global $wpdb;
		$wpdb->insert(
			self::t( 'notes' ),
			array(
				'tip_id'     => (int) $tip_id,
				'user_id'    => (int) $uid,
				'kind'       => $kind,
				'body'       => $body,
				'created_at' => self::now(),
			)
		);
		return (int) $wpdb->insert_id;
	}

	protected static function touch( $id, $data = array() ) {
		global $wpdb;
		$data['updated_at'] = self::now();
		$wpdb->update( self::t(), $data, array( 'id' => (int) $id ) );
	}

	protected static function is_urgent( $body, $category, $flag ) {
		if ( $flag ) {
			return true;
		}
		if ( in_array( $category, (array) apply_filters( 'nros_tips_urgent_categories', array() ), true ) ) {
			return true;
		}
		foreach ( (array) apply_filters( 'nros_tips_urgent_words', self::URGENT_WORDS ) as $w ) {
			if ( '' !== $w && false !== mb_strpos( $body, $w ) ) {
				return true;
			}
		}
		return false;
	}

	/** Open tips per user (for fair routing). */
	protected static function load_of( $uids ) {
		global $wpdb;
		$out = array_fill_keys( array_map( 'intval', $uids ), 0 );
		if ( ! $out ) {
			return $out;
		}
		$in   = implode( ',', array_keys( $out ) );
		$rows = $wpdb->get_results( 'SELECT assignee_id, COUNT(*) n FROM ' . self::t() . " WHERE assignee_id IN ({$in}) AND status NOT IN ('rejected','published') GROUP BY assignee_id", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		foreach ( $rows as $r ) {
			$out[ (int) $r['assignee_id'] ] = (int) $r['n'];
		}
		return $out;
	}

	/**
	 * Default assignee: the district's correspondent with the fewest open
	 * tips; otherwise the Editor with the fewest; otherwise nobody.
	 *
	 * @return array{0:int,1:string} [user id, 'district'|'editor'|'']
	 */
	protected static function route( $district ) {
		$pick = function ( $ids ) {
			$load = self::load_of( $ids );
			if ( ! $load ) {
				return 0;
			}
			asort( $load );
			return (int) key( $load );
		};
		if ( '' !== $district ) {
			$ids = get_users(
				array(
					'role'       => 'correspondent',
					'fields'     => 'ID',
					'number'     => 200,
					'meta_query' => array( array( 'key' => 'meeha_district', 'value' => $district ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				)
			);
			$ids = array_values( array_filter( array_map( 'intval', $ids ), array( __CLASS__, 'can_take' ) ) );
			if ( $ids ) {
				return array( $pick( $ids ), 'district' );
			}
		}
		$eds = array_map( 'intval', get_users( array( 'role' => 'editor', 'fields' => 'ID', 'number' => 200 ) ) );
		if ( ! $eds ) {
			$eds = array_map( 'intval', get_users( array( 'role__in' => array( 'meeha_publisher', 'administrator' ), 'fields' => 'ID', 'number' => 50 ) ) );
		}
		return $eds ? array( $pick( $eds ), 'editor' ) : array( 0, '' );
	}

	/** A correspondent who is suspended from news work is skipped. */
	public static function can_take( $uid ) {
		$types = NROS_Core::active_suspension_types( $uid );
		return ! array_intersect( (array) $types, array( 'account', 'news_submission' ) );
	}

	protected static function staff_ids() {
		return class_exists( 'NROS_Notifications' ) ? NROS_Notifications::staff_ids() : array();
	}

	protected static function notify( $uids, $type, $title, $body, $link, $id ) {
		if ( class_exists( 'NROS_Notifications' ) ) {
			NROS_Notifications::add( $uids, $type, $title, $body, $link, $id );
		}
	}

	protected static function label_of_district( $d ) {
		return '' !== $d ? $d : 'জেলা জানানো হয়নি';
	}

	// ------------------------------------------------------------------
	// files
	// ------------------------------------------------------------------

	protected static function dir() {
		$dir = NROS_PRIVATE_DIR . '/tips';
		if ( ! is_dir( $dir ) ) {
			NROS_Core::create_private_dir();
			wp_mkdir_p( $dir );
		}
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			@file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" );
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			@file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
		}
		// phpcs:enable
		return $dir;
	}

	protected static function path_of( $stored ) {
		return preg_match( '#^\d{4}/\d{2}/[a-f0-9]{32}\.bin$#', (string) $stored ) ? self::dir() . '/' . $stored : '';
	}

	/**
	 * Uploaded files as a flat list, whatever the field was called
	 * (files[], files, file).
	 */
	protected static function incoming_files( $request ) {
		$out = array();
		foreach ( (array) $request->get_file_params() as $f ) {
			if ( isset( $f['name'] ) && is_array( $f['name'] ) ) {
				foreach ( array_keys( $f['name'] ) as $i ) {
					$out[] = array(
						'name'     => $f['name'][ $i ],
						'type'     => $f['type'][ $i ],
						'tmp_name' => $f['tmp_name'][ $i ],
						'error'    => $f['error'][ $i ],
						'size'     => $f['size'][ $i ],
					);
				}
			} elseif ( isset( $f['name'] ) ) {
				$out[] = $f;
			}
		}
		return array_values(
			array_filter(
				$out,
				function ( $f ) {
					return UPLOAD_ERR_NO_FILE !== (int) $f['error'];
				}
			)
		);
	}

	/**
	 * Checks one upload. @return array|WP_Error [mime, kind, name]
	 */
	protected static function check_file( $f ) {
		if ( ! empty( $f['error'] ) ) {
			$big = in_array( (int) $f['error'], array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true );
			return new WP_Error( 'nros_tips_file', $big ? 'একটি ফাইল খুব বড় — প্রতিটি ফাইল সর্বোচ্চ ' . self::bn( (int) round( self::limits()['max_file'] / MB_IN_BYTES ) ) . ' এমবি হতে পারে।' : 'একটি ফাইল ঠিকমতো আসেনি, আবার চেষ্টা করুন।', array( 'status' => $big ? 413 : 400 ) );
		}
		if ( ! is_uploaded_file( $f['tmp_name'] ) ) {
			return new WP_Error( 'nros_tips_file', 'ফাইল আসেনি, আবার চেষ্টা করুন।', array( 'status' => 400 ) );
		}
		if ( (int) $f['size'] > self::MAX_FILE ) {
			return new WP_Error( 'nros_tips_file', 'প্রতিটি ফাইল সর্বোচ্চ ২৫ এমবি হতে পারে।', array( 'status' => 413 ) );
		}
		$name  = sanitize_file_name( (string) $f['name'] );
		$sniff = function_exists( 'mime_content_type' ) ? (string) mime_content_type( $f['tmp_name'] ) : '';
		if ( '' === $sniff ) {
			$chk   = wp_check_filetype_and_ext( $f['tmp_name'], $name );
			$sniff = (string) $chk['type'];
		}
		$ok = '/^(image\/(jpeg|png|gif|webp)|application\/pdf|audio\/(mpeg|mp4|x-m4a|aac|ogg|webm|wav|x-wav|3gpp|amr)|video\/(mp4|webm|quicktime|3gpp|x-matroska))$/';
		if ( ! preg_match( $ok, $sniff ) ) {
			return new WP_Error( 'nros_tips_file', 'এই ধরনের ফাইল নেওয়া যায় না — ছবি (JPG/PNG/WebP), PDF, অডিও বা ভিডিও দিন।', array( 'status' => 415 ) );
		}
		$kind = 0 === strpos( $sniff, 'image/' ) ? 'image' : ( 0 === strpos( $sniff, 'audio/' ) ? 'audio' : ( 0 === strpos( $sniff, 'video/' ) ? 'video' : 'pdf' ) );
		return array( $sniff, $kind, '' !== $name ? $name : 'file' );
	}

	/**
	 * Re-encodes an image so EXIF / GPS / camera serial never reach the desk.
	 * Imagick first (keeps quality, fixes rotation), GD otherwise.
	 *
	 * @return bool Whether the metadata is gone.
	 */
	public static function strip_metadata( $path, $mime ) {
		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ), true ) ) {
			return false;
		}
		try {
			if ( extension_loaded( 'imagick' ) && class_exists( 'Imagick' ) ) {
				$im = new Imagick( $path );
				if ( method_exists( $im, 'autoOrient' ) ) {
					$im->autoOrient();
				}
				$im->stripImage();
				$ok = $im->writeImages( $path, true );
				$im->clear();
				if ( $ok ) {
					return true;
				}
			}
		} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// fall back to GD.
		}
		if ( ! function_exists( 'imagecreatefromstring' ) || 'image/gif' === $mime ) {
			// GIFs carry no EXIF; GD would flatten an animation, so leave them.
			return 'image/gif' === $mime;
		}
		$info = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $info || $info[0] * $info[1] > 60000000 ) {
			return false; // unreadable or too large to decode safely.
		}
		$img = @imagecreatefromstring( (string) file_get_contents( $path ) ); // phpcs:ignore
		if ( ! $img ) {
			return false;
		}
		if ( 'image/jpeg' === $mime && function_exists( 'exif_read_data' ) ) {
			$exif = @exif_read_data( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$o    = isset( $exif['Orientation'] ) ? (int) $exif['Orientation'] : 1;
			$deg  = array( 3 => 180, 6 => -90, 8 => 90 );
			if ( isset( $deg[ $o ] ) ) {
				$rot = imagerotate( $img, $deg[ $o ], 0 );
				if ( $rot ) {
					imagedestroy( $img );
					$img = $rot;
				}
			}
		}
		$tmp = $path . '.tmp';
		$ok  = false;
		if ( 'image/jpeg' === $mime ) {
			$ok = imagejpeg( $img, $tmp, 90 );
		} elseif ( 'image/png' === $mime ) {
			imagesavealpha( $img, true );
			$ok = imagepng( $img, $tmp, 6 );
		} elseif ( 'image/webp' === $mime && function_exists( 'imagewebp' ) ) {
			$ok = imagewebp( $img, $tmp, 90 );
		}
		imagedestroy( $img );
		if ( $ok && filesize( $tmp ) > 0 ) {
			return rename( $tmp, $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
		}
		if ( file_exists( $tmp ) ) {
			wp_delete_file( $tmp );
		}
		return false;
	}

	/** Signed link to one file for one staff user. */
	protected static function file_url( $id, $uid, $dl = false ) {
		$exp  = (int) ( ceil( ( time() + 2 * HOUR_IN_SECONDS ) / 900 ) * 900 );
		$body = (int) $id . '.' . (int) $uid . '.' . $exp . '.' . ( $dl ? 1 : 0 );
		$sig  = substr( hash_hmac( 'sha256', $body, wp_salt( 'secure_auth' ) . '|nros-tips' ), 0, 40 );
		return rest_url( NROS_API_NS . '/tips/files/' . (int) $id ) . '?t=' . rawurlencode( $body . '.' . $sig );
	}

	protected static function files_of( $tip_id, $uid ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'files' ) . ' WHERE tip_id = %d ORDER BY id', $tip_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$out  = array();
		foreach ( $rows as $f ) {
			$out[] = array(
				'id'       => (int) $f['id'],
				'name'     => $f['name'],
				'mime'     => $f['mime'],
				'kind'     => $f['kind'],
				'size'     => (int) $f['size'],
				'stripped' => (bool) (int) $f['stripped'],
				'url'      => self::file_url( $f['id'], $uid ),
				'download' => self::file_url( $f['id'], $uid, true ),
			);
		}
		return $out;
	}

	protected static function delete_files( $tip_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, disk_name FROM ' . self::t( 'files' ) . ' WHERE tip_id = %d', $tip_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( $rows as $f ) {
			$p = self::path_of( $f['disk_name'] );
			if ( $p && file_exists( $p ) ) {
				wp_delete_file( $p );
			}
		}
		$wpdb->delete( self::t( 'files' ), array( 'tip_id' => (int) $tip_id ) );
		return count( $rows );
	}

	/**
	 * GET /tips/files/{id}?t= — streams a file to a staff member / the assignee.
	 */
	public static function stream( $request ) {
		global $wpdb;
		$id    = (int) $request['id'];
		$parts = explode( '.', (string) $request->get_param( 't' ) );
		$bad   = new WP_Error( 'nros_forbidden', 'লিংকের মেয়াদ শেষ। টিপটি আবার খুলুন।', array( 'status' => 403 ) );
		if ( 5 !== count( $parts ) ) {
			return $bad;
		}
		list( $fid, $uid, $exp, $dl, $sig ) = $parts;
		$want = substr( hash_hmac( 'sha256', $fid . '.' . $uid . '.' . $exp . '.' . $dl, wp_salt( 'secure_auth' ) . '|nros-tips' ), 0, 40 );
		if ( ! hash_equals( $want, (string) $sig ) || (int) $fid !== $id || (int) $exp < time() ) {
			return $bad;
		}
		$f    = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'files' ) . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$user = get_userdata( (int) $uid );
		$tip  = $f ? self::row( $f['tip_id'] ) : null;
		if ( ! $f || ! $user || ! $tip || ! ( user_can( $user, 'edit_others_posts' ) || (int) $tip['assignee_id'] === (int) $user->ID ) ) {
			return $bad;
		}
		$path = self::path_of( $f['disk_name'] );
		if ( ! $path || ! file_exists( $path ) ) {
			return new WP_Error( 'nros_not_found', 'ফাইলটি সার্ভারে নেই (হয়তো মেয়াদ শেষে মুছে ফেলা হয়েছে)।', array( 'status' => 404 ) );
		}
		$size   = (int) filesize( $path );
		$inline = '1' !== $dl && in_array( $f['kind'], array( 'image', 'audio', 'video', 'pdf' ), true );
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		$from = 0;
		$to   = $size - 1;
		$rng  = isset( $_SERVER['HTTP_RANGE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) ) : '';
		$part = false;
		if ( $size > 0 && preg_match( '/^bytes=(\d*)-(\d*)$/', $rng, $m ) && ( '' !== $m[1] || '' !== $m[2] ) ) {
			if ( '' === $m[1] ) {
				$from = max( 0, $size - (int) $m[2] );
			} else {
				$from = (int) $m[1];
				$to   = '' !== $m[2] ? min( $to, (int) $m[2] ) : $to;
			}
			if ( $from > $to ) {
				status_header( 416 );
				header( 'Content-Range: bytes */' . $size );
				exit;
			}
			$part = true;
		}
		status_header( $part ? 206 : 200 );
		header( 'Content-Type: ' . ( $f['mime'] ? $f['mime'] : 'application/octet-stream' ) );
		header( 'Content-Length: ' . ( $to - $from + 1 ) );
		header( 'Accept-Ranges: bytes' );
		if ( $part ) {
			header( "Content-Range: bytes {$from}-{$to}/{$size}" );
		}
		header( 'Content-Disposition: ' . ( $inline ? 'inline' : 'attachment' ) . "; filename*=UTF-8''" . rawurlencode( $f['name'] ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( "Content-Security-Policy: default-src 'none'; img-src 'self' data:; media-src 'self'; style-src 'unsafe-inline'" . ( 'pdf' === $f['kind'] ? '' : '; sandbox' ) );
		header( 'Cache-Control: private, no-store' );
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		// phpcs:disable WordPress.WP.AlternativeFunctions
		$fh = fopen( $path, 'rb' );
		fseek( $fh, $from );
		$left = $to - $from + 1;
		while ( $left > 0 && ! feof( $fh ) ) {
			$chunk = fread( $fh, (int) min( 262144, $left ) );
			echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			$left -= strlen( $chunk );
			flush();
		}
		fclose( $fh );
		// phpcs:enable
		exit;
	}

	// ------------------------------------------------------------------
	// public: submit / status / follow-up
	// ------------------------------------------------------------------

	/**
	 * POST /tips — multipart: body, category, district, name?, phone?, email?,
	 * urgent?, files[] (≤ 5), website (honeypot — must stay empty).
	 */
	public static function submit( $request ) {
		global $wpdb;
		if ( ! self::ready() ) {
			return new WP_Error( 'nros_tips_off', 'এই মুহূর্তে তথ্য নেওয়া যাচ্ছে না, একটু পরে চেষ্টা করুন।', array( 'status' => 503 ) );
		}
		if ( ! self::origin_ok( $request ) ) {
			return self::forbidden_origin();
		}
		// The body was larger than the server takes: PHP threw it away whole.
		$len = isset( $_SERVER['CONTENT_LENGTH'] ) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
		$pms = wp_convert_hr_to_bytes( (string) ini_get( 'post_max_size' ) );
		if ( $pms > 0 && $len > $pms ) {
			return new WP_Error( 'nros_tips_too_big', 'সব ফাইল মিলিয়ে আকার বেশি হয়ে গেছে (সর্বোচ্চ ' . self::bn( (int) floor( $pms / MB_IN_BYTES ) ) . ' এমবি)। কম ফাইল দিন বা ছোট করে পাঠান।', array( 'status' => 413 ) );
		}

		$ip_hash = self::ip_hash();
		$rl_key  = 'nros_tip_rl_' . substr( $ip_hash, 0, 32 );
		$hits    = self::hits( $rl_key );
		$hour    = count( array_filter( $hits, function ( $t ) { return time() - (int) $t < HOUR_IN_SECONDS; } ) );
		if ( $hour >= self::PER_HOUR || count( $hits ) >= self::PER_DAY ) {
			return new WP_Error( 'nros_tips_rate', 'খুব অল্প সময়ে অনেকগুলো তথ্য পাঠানো হয়েছে। কিছুক্ষণ পরে আবার চেষ্টা করুন।', array( 'status' => 429 ) );
		}

		$body     = trim( sanitize_textarea_field( (string) $request->get_param( 'body' ) ) );
		$category = sanitize_key( (string) $request->get_param( 'category' ) );
		$category = isset( self::CATEGORIES[ $category ] ) ? $category : 'other';
		$district = mb_substr( sanitize_text_field( (string) $request->get_param( 'district' ) ), 0, 60 );
		$honeypot = trim( (string) $request->get_param( 'website' ) );
		$spam     = '' !== $honeypot;

		if ( ! $spam && mb_strlen( $body ) < 20 ) {
			return new WP_Error( 'nros_tips_short', 'ঘটনাটি একটু খুলে লিখুন (অন্তত ২০ অক্ষর) — কী, কোথায়, কখন।', array( 'status' => 400 ) );
		}
		if ( mb_strlen( $body ) > 20000 ) {
			$body = mb_substr( $body, 0, 20000 );
		}

		$files = $spam ? array() : self::incoming_files( $request );
		if ( count( $files ) > self::MAX_FILES ) {
			return new WP_Error( 'nros_tips_files', 'একবারে সর্বোচ্চ ' . self::bn( self::MAX_FILES ) . 'টি ফাইল দেওয়া যায়।', array( 'status' => 400 ) );
		}
		$checked = array();
		foreach ( $files as $f ) {
			$c = self::check_file( $f );
			if ( is_wp_error( $c ) ) {
				return $c;
			}
			$checked[] = array( $f, $c );
		}

		// Contact (all optional) — encrypted at rest.
		$name    = mb_substr( sanitize_text_field( (string) $request->get_param( 'name' ) ), 0, 120 );
		$phone   = mb_substr( preg_replace( '/[^0-9০-৯+\-\s]/u', '', (string) $request->get_param( 'phone' ) ), 0, 30 );
		$email   = sanitize_email( (string) $request->get_param( 'email' ) );
		$contact = array_filter( array( 'name' => $name, 'phone' => trim( $phone ), 'email' => $email ) );

		// Spam: the same text from anyone within a day.
		$hash   = sha1( preg_replace( '/\s+/u', ' ', mb_strtolower( $body ) ) );
		$dup_of = 0;
		if ( ! $spam ) {
			$dup_of = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t() . ' WHERE body_hash = %s AND created_at > %s ORDER BY id LIMIT 1', $hash, gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DateTime.CurrentTimeTimestamp.Requested
		}
		$auto_close = $spam ? 'honeypot' : ( $dup_of ? 'duplicate' : '' );
		$urgent     = ! $auto_close && self::is_urgent( $body, $category, (bool) $request->get_param( 'urgent' ) );

		$hits[] = time();
		set_transient( $rl_key, $hits, DAY_IN_SECONDS );

		$code = self::new_code();
		$pin  = str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );
		$now  = self::now();

		list( $assignee, $routed ) = $auto_close ? array( 0, '' ) : self::route( $district );

		$wpdb->insert(
			self::t(),
			array(
				'code'         => $code,
				'pin_hash'     => wp_hash_password( $pin ),
				'body'         => $body,
				'category'     => $category,
				'district'     => $district,
				'contact'      => $contact ? NROS_Core::encrypt( wp_json_encode( $contact ) ) : '',
				'has_contact'  => $contact ? 1 : 0,
				'status'       => $auto_close ? 'rejected' : 'new',
				'auto_closed'  => $auto_close ? 1 : 0,
				'close_reason' => $auto_close,
				'urgent'       => $urgent ? 1 : 0,
				'assignee_id'  => (int) $assignee,
				'routed'       => $routed,
				'body_hash'    => $hash,
				'ip_hash'      => $ip_hash,
				'files_count'  => 0,
				'unread'       => $auto_close ? 0 : 1,
				'created_at'   => $now,
				'updated_at'   => $now,
			)
		);
		$id = (int) $wpdb->insert_id;
		if ( ! $id ) {
			return new WP_Error( 'nros_tips_db', 'তথ্যটি সংরক্ষণ করা গেল না, আবার চেষ্টা করুন।', array( 'status' => 500 ) );
		}

		// Files → nros-private/tips, metadata stripped from images.
		$stored = 0;
		foreach ( $checked as $pair ) {
			list( $f, $c )            = $pair;
			list( $mime, $kind, $nm ) = $c;
			$sub  = gmdate( 'Y/m' );
			$disk = $sub . '/' . bin2hex( random_bytes( 16 ) ) . '.bin';
			wp_mkdir_p( self::dir() . '/' . $sub );
			$dest = self::dir() . '/' . $disk;
			if ( ! @move_uploaded_file( $f['tmp_name'], $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				continue;
			}
			$stripped = 'image' === $kind ? self::strip_metadata( $dest, $mime ) : false;
			clearstatcache( true, $dest );
			$wpdb->insert(
				self::t( 'files' ),
				array(
					'tip_id'     => $id,
					'disk_name'  => $disk,
					'name'       => mb_substr( $nm, 0, 180 ),
					'mime'       => $mime,
					'kind'       => $kind,
					'size'       => (int) filesize( $dest ),
					'stripped'   => $stripped ? 1 : 0,
					'created_at' => $now,
				)
			);
			++$stored;
		}
		if ( $stored ) {
			$wpdb->update( self::t(), array( 'files_count' => $stored ), array( 'id' => $id ) );
		}

		// Automation trail.
		self::note( $id, 0, 'reply', 'ধন্যবাদ। আপনার তথ্য নিরাপদে আমাদের কাছে পৌঁছেছে। একজন সম্পাদক দেখে এখানে উত্তর দেবেন — ট্র্যাকিং কোড ও পিন দিয়ে যেকোনো সময় এই পাতায় ফিরে আসুন।' );
		if ( $auto_close ) {
			self::note( $id, 0, 'system', 'honeypot' === $auto_close ? 'স্বয়ংক্রিয়ভাবে বাতিল: স্প্যাম ফাঁদে ধরা পড়েছে।' : 'স্বয়ংক্রিয়ভাবে বাতিল: একই লেখা ২৪ ঘণ্টার মধ্যে আগেও এসেছে (টিপ #' . self::bn( $dup_of ) . ')।' );
		} else {
			$cat = self::CATEGORIES[ $category ];
			if ( $assignee ) {
				$who = get_userdata( $assignee );
				self::note( $id, 0, 'system', 'স্বয়ংক্রিয় রাউটিং: ' . ( 'district' === $routed ? self::label_of_district( $district ) . ' জেলার সংবাদদাতা ' : 'সম্পাদক ' ) . ( $who ? $who->display_name : '' ) . '-কে ডিফল্ট দায়িত্ব দেওয়া হলো।' );
				$link = 'district' === $routed ? '/app/tips?id=' . $id : '/app/tips?id=' . $id;
				self::notify( array( $assignee ), 'tip_assigned', 'নতুন গোপন তথ্য আপনার কাছে: ' . $cat . ' · ' . self::label_of_district( $district ), 'বিস্তারিত অ্যাপে দেখুন। তথ্যদাতার পরিচয় গোপন রাখুন।', $link, $id );
			}
			if ( $urgent ) {
				self::note( $id, 0, 'system', 'জরুরি হিসেবে চিহ্নিত — পুরো ডেস্ককে সতর্কবার্তা পাঠানো হয়েছে।' );
				// No tip text in the alert: push notices pass through third-party push services.
				self::notify( self::staff_ids(), 'tip_urgent', '⚠ জরুরি গোপন তথ্য: ' . $cat . ' · ' . self::label_of_district( $district ), 'টিপ #' . self::bn( $id ) . ' — এখনই দেখুন।', '/app/tips?id=' . $id, $id );
			}
		}

		/**
		 * A tip was stored.
		 *
		 * @param int  $id     Tip id.
		 * @param bool $urgent Urgent.
		 */
		do_action( 'nros_tip_received', $id, $urgent );

		return rest_ensure_response(
			array(
				'ok'         => true,
				'code'       => $code,
				'pin'        => $pin,
				'files'      => $stored,
				'status_url' => add_query_arg( 'code', rawurlencode( $code ), home_url( '/tips/' ) ),
				'message'    => 'আপনার তথ্য পৌঁছেছে। ট্র্যাকিং কোড ও পিন লিখে রাখুন — এগুলো আর দেখানো হবে না।',
			)
		);
	}

	/**
	 * Code + PIN → the tip row, with brute-force protection.
	 *
	 * @return array|WP_Error
	 */
	protected static function auth_tipster( $request ) {
		if ( ! self::ready() ) {
			return new WP_Error( 'nros_tips_off', 'এই মুহূর্তে দেখা যাচ্ছে না।', array( 'status' => 503 ) );
		}
		if ( ! self::origin_ok( $request ) ) {
			return self::forbidden_origin();
		}
		global $wpdb;
		$key   = 'nros_tip_lk_' . substr( self::ip_hash(), 0, 32 );
		$fails = self::hits( $key );
		$fails = array_values( array_filter( $fails, function ( $t ) { return time() - (int) $t < HOUR_IN_SECONDS; } ) );
		if ( count( $fails ) >= self::LOOKUP_FAILS ) {
			return new WP_Error( 'nros_tips_rate', 'অনেকবার ভুল কোড/পিন দেওয়া হয়েছে। এক ঘণ্টা পরে আবার চেষ্টা করুন।', array( 'status' => 429 ) );
		}
		$code = self::norm_code( $request->get_param( 'code' ) );
		$pin  = preg_replace( '/\D/', '', self::ascii_digits( $request->get_param( 'pin' ) ) );
		$row  = $code ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t() . ' WHERE code = %s', $code ), ARRAY_A ) : null; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $row || 6 !== strlen( $pin ) || ! wp_check_password( $pin, $row['pin_hash'] ) ) {
			$fails[] = time();
			set_transient( $key, $fails, HOUR_IN_SECONDS );
			return new WP_Error( 'nros_tips_auth', 'ট্র্যাকিং কোড বা পিন মিলছে না।', array( 'status' => 404 ) );
		}
		return $row;
	}

	protected static function public_view( $row ) {
		global $wpdb;
		$notes = $wpdb->get_results( $wpdb->prepare( 'SELECT kind, body, created_at FROM ' . self::t( 'notes' ) . " WHERE tip_id = %d AND kind IN ('reply','tipster') ORDER BY id", $row['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$label = self::STATUSES[ $row['status'] ] ?? $row['status'];
		$text  = self::PUBLIC_STATUS[ $row['status'] ] ?? '';
		if ( (int) $row['auto_closed'] ) {
			$label = 'বাতিল (স্বয়ংক্রিয়)';
			$text  = 'এই বার্তাটি স্বয়ংক্রিয়ভাবে বাতিল হয়েছে (একই লেখা আগে এসেছে বা স্প্যাম মনে হয়েছে)। ভুল হলে নতুন করে পাঠান।';
		}
		return array(
			'code'          => $row['code'],
			'status'        => $row['status'],
			'status_label'  => $label,
			'status_text'   => $text,
			'category'      => self::CATEGORIES[ $row['category'] ] ?? '',
			'district'      => $row['district'],
			'files'         => (int) $row['files_count'],
			'created_at'    => self::iso( $row['created_at'] ),
			'updated_at'    => self::iso( $row['updated_at'] ),
			'published_url' => 'published' === $row['status'] ? esc_url_raw( $row['published_url'] ) : '',
			'can_reply'     => ! (int) $row['auto_closed'],
			'thread'        => array_map(
				function ( $n ) {
					return array(
						'from' => 'tipster' === $n['kind'] ? 'you' : 'desk',
						'who'  => 'tipster' === $n['kind'] ? 'আপনি' : 'মিহা ডেস্ক',
						'body' => (string) $n['body'],
						'at'   => self::iso( $n['created_at'] ),
					);
				},
				$notes
			),
		);
	}

	/** POST /tips/status {code, pin}. */
	public static function status( $request ) {
		$row = self::auth_tipster( $request );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		return rest_ensure_response( self::public_view( $row ) );
	}

	/** POST /tips/followup {code, pin, body}. */
	public static function followup( $request ) {
		$row = self::auth_tipster( $request );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$body = trim( sanitize_textarea_field( (string) $request->get_param( 'body' ) ) );
		if ( mb_strlen( $body ) < 2 ) {
			return new WP_Error( 'nros_tips_short', 'কিছু লিখুন।', array( 'status' => 400 ) );
		}
		if ( (int) $row['auto_closed'] ) {
			return new WP_Error( 'nros_tips_closed', 'এই বার্তাটি বাতিল হয়েছে — নতুন করে পাঠান।', array( 'status' => 409 ) );
		}
		// at most 10 follow-ups a day per tip
		$key  = 'nros_tip_fu_' . (int) $row['id'];
		$hits = self::hits( $key );
		if ( count( $hits ) >= 10 ) {
			return new WP_Error( 'nros_tips_rate', 'আজ এই তথ্যের জন্য অনেক বার্তা পাঠানো হয়েছে। কাল আবার চেষ্টা করুন।', array( 'status' => 429 ) );
		}
		$hits[] = time();
		set_transient( $key, $hits, DAY_IN_SECONDS );
		self::note( $row['id'], 0, 'tipster', mb_substr( $body, 0, 5000 ) );
		self::touch( $row['id'], array( 'unread' => 1 ) );
		if ( (int) $row['assignee_id'] ) {
			self::notify( array( (int) $row['assignee_id'] ), 'tip_followup', 'তথ্যদাতা নতুন বার্তা দিয়েছেন: টিপ #' . self::bn( $row['id'] ), '', '/app/tips?id=' . (int) $row['id'], (int) $row['id'] );
		}
		return rest_ensure_response( self::public_view( self::row( $row['id'] ) ) );
	}

	// ------------------------------------------------------------------
	// desk
	// ------------------------------------------------------------------

	protected static function me( $request ) {
		return NROS_Auth::get_user_from_request( $request );
	}

	protected static function person( $uid ) {
		$u = $uid ? get_userdata( (int) $uid ) : null;
		if ( ! $u ) {
			return null;
		}
		$role = in_array( 'correspondent', (array) $u->roles, true ) ? 'সংবাদদাতা' : ( user_can( $u, 'nros_publisher' ) || user_can( $u, 'manage_options' ) ? 'পাবলিশার' : 'এডিটর' );
		return array(
			'id'       => (int) $u->ID,
			'name'     => $u->display_name,
			'role'     => $role,
			'district' => (string) get_user_meta( $u->ID, 'meeha_district', true ),
			'avatar'   => get_avatar_url( $u->ID, array( 'size' => 64 ) ),
		);
	}

	protected static function shape( $row, $full = false, $viewer = null, $with_contact = true ) {
		global $wpdb;
		$out = array(
			'id'            => (int) $row['id'],
			'code'          => $row['code'],
			'excerpt'       => wp_trim_words( (string) $row['body'], 30, '…' ),
			'category'      => $row['category'],
			'category_label'=> self::CATEGORIES[ $row['category'] ] ?? $row['category'],
			'district'      => $row['district'],
			'status'        => $row['status'],
			'status_label'  => (int) $row['auto_closed'] ? 'বাতিল (স্বয়ংক্রিয়)' : ( self::STATUSES[ $row['status'] ] ?? $row['status'] ),
			'auto_closed'   => (bool) (int) $row['auto_closed'],
			'close_reason'  => $row['close_reason'],
			'urgent'        => (bool) (int) $row['urgent'],
			'unread'        => (bool) (int) $row['unread'],
			'has_contact'   => (bool) (int) $row['has_contact'],
			'files_count'   => (int) $row['files_count'],
			'routed'        => $row['routed'],
			'assignee'      => self::person( $row['assignee_id'] ),
			'published_url' => $row['published_url'],
			'created_at'    => self::iso( $row['created_at'] ),
			'updated_at'    => self::iso( $row['updated_at'] ),
		);
		if ( $full ) {
			$out['body']  = (string) $row['body'];
			$out['files'] = self::files_of( $row['id'], $viewer ? $viewer->ID : 0 );
			$contact      = array();
			if ( $with_contact && $row['contact'] ) {
				$dec     = json_decode( NROS_Core::decrypt( $row['contact'] ), true );
				$contact = is_array( $dec ) ? $dec : array();
			}
			$out['contact'] = $with_contact ? (object) $contact : null;
			$notes          = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'notes' ) . ' WHERE tip_id = %d ORDER BY id', $row['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$out['notes']   = array_map(
				function ( $n ) {
					$p = self::person( $n['user_id'] );
					return array(
						'id'   => (int) $n['id'],
						'kind' => $n['kind'],
						'body' => (string) $n['body'],
						'who'  => 'tipster' === $n['kind'] ? 'তথ্যদাতা' : ( $p ? $p['name'] : ( 'system' === $n['kind'] ? 'স্বয়ংক্রিয়' : 'মিহা ডেস্ক' ) ),
						'at'   => self::iso( $n['created_at'] ),
					);
				},
				$notes
			);
		}
		return $out;
	}

	/** Unread, not closed — the nav badge. */
	public static function badge() {
		global $wpdb;
		if ( ! self::ready() ) {
			return 0;
		}
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::t() . " WHERE unread = 1 AND status <> 'rejected'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * GET /admin/tips?status=&q=&category=&urgent=1&mine=1&page=
	 */
	public static function admin_list( $request ) {
		global $wpdb;
		if ( ! self::ready() ) {
			return rest_ensure_response( array( 'items' => array(), 'total' => 0, 'counts' => (object) array(), 'unread' => 0 ) );
		}
		$me     = self::me( $request );
		$where  = array( '1=1' );
		$args   = array();
		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		if ( 'open' === $status ) {
			$where[] = "status IN ('new','verifying','assigned')";
		} elseif ( isset( self::STATUSES[ $status ] ) ) {
			$where[] = 'status = %s';
			$args[]  = $status;
		}
		$cat = sanitize_key( (string) $request->get_param( 'category' ) );
		if ( isset( self::CATEGORIES[ $cat ] ) ) {
			$where[] = 'category = %s';
			$args[]  = $cat;
		}
		if ( $request->get_param( 'urgent' ) ) {
			$where[] = 'urgent = 1';
		}
		if ( $request->get_param( 'mine' ) ) {
			$where[] = 'assignee_id = %d';
			$args[]  = (int) $me->ID;
		}
		$q = trim( sanitize_text_field( (string) $request->get_param( 'q' ) ) );
		if ( '' !== $q ) {
			$like    = '%' . $wpdb->esc_like( $q ) . '%';
			$where[] = '(body LIKE %s OR code LIKE %s OR district LIKE %s)';
			array_push( $args, $like, $like, $like );
		}
		$page = max( 1, (int) $request->get_param( 'page' ) );
		$per  = 30;
		$sql  = ' FROM ' . self::t() . ' WHERE ' . implode( ' AND ', $where );
		$prep = function ( $s ) use ( $wpdb, $args ) {
			return $args ? $wpdb->prepare( $s, $args ) : $s; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		};
		$total = (int) $wpdb->get_var( $prep( 'SELECT COUNT(*)' . $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows  = $wpdb->get_results( $prep( 'SELECT *' . $sql . ' ORDER BY unread DESC, urgent DESC, id DESC LIMIT ' . $per . ' OFFSET ' . ( ( $page - 1 ) * $per ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$cnt   = array_fill_keys( array_keys( self::STATUSES ), 0 );
		foreach ( $wpdb->get_results( 'SELECT status, COUNT(*) n FROM ' . self::t() . ' GROUP BY status', ARRAY_A ) as $c ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$cnt[ $c['status'] ] = (int) $c['n'];
		}
		return rest_ensure_response(
			array(
				'items'      => array_map( array( __CLASS__, 'shape' ), $rows ),
				'total'      => $total,
				'pages'      => (int) ceil( $total / $per ),
				'counts'     => $cnt,
				'unread'     => self::badge(),
				'statuses'   => self::STATUSES,
				'categories' => self::CATEGORIES,
			)
		);
	}

	/** GET /admin/tips/people?q= — Editors, Publishers and correspondents. */
	public static function admin_people( $request ) {
		$q    = trim( sanitize_text_field( (string) $request->get_param( 'q' ) ) );
		$args = array(
			'role__in' => array( 'administrator', 'meeha_publisher', 'editor', 'correspondent' ),
			'number'   => 60,
			'orderby'  => 'display_name',
			'fields'   => 'ID',
		);
		if ( '' !== $q ) {
			$args['search']         = '*' . $q . '*';
			$args['search_columns'] = array( 'display_name', 'user_login', 'user_email' );
		}
		$district = trim( sanitize_text_field( (string) $request->get_param( 'district' ) ) );
		$people   = array_values( array_filter( array_map( array( __CLASS__, 'person' ), get_users( $args ) ) ) );
		// the tip's own district first, then staff, then the rest
		usort(
			$people,
			function ( $a, $b ) use ( $district ) {
				$sa = ( '' !== $district && $a['district'] === $district ? 0 : ( 'সংবাদদাতা' === $a['role'] ? 2 : 1 ) );
				$sb = ( '' !== $district && $b['district'] === $district ? 0 : ( 'সংবাদদাতা' === $b['role'] ? 2 : 1 ) );
				return $sa === $sb ? strcmp( $a['name'], $b['name'] ) : $sa - $sb;
			}
		);
		return rest_ensure_response( array( 'items' => $people ) );
	}

	protected static function load( $request ) {
		$row = self::ready() ? self::row( (int) $request['id'] ) : null;
		return $row ? $row : new WP_Error( 'nros_not_found', 'টিপটি পাওয়া যায়নি।', array( 'status' => 404 ) );
	}

	/** GET /admin/tips/{id} — marks it read. */
	public static function admin_get( $request ) {
		$row = self::load( $request );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		if ( (int) $row['unread'] ) {
			global $wpdb;
			$wpdb->update( self::t(), array( 'unread' => 0 ), array( 'id' => (int) $row['id'] ) );
			$row['unread'] = 0;
		}
		return rest_ensure_response( self::shape( $row, true, self::me( $request ) ) );
	}

	/** PATCH /admin/tips/{id} {status?, assignee_id?, published_url?, urgent?}. */
	public static function admin_update( $request ) {
		$row = self::load( $request );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$me   = self::me( $request );
		$data = array();
		$log  = array();
		$st   = $request->get_param( 'status' );
		if ( null !== $st ) {
			$st = sanitize_key( (string) $st );
			if ( ! isset( self::STATUSES[ $st ] ) ) {
				return new WP_Error( 'nros_bad', 'অবস্থা ঠিক নেই।', array( 'status' => 400 ) );
			}
			if ( $st !== $row['status'] ) {
				$data['status']      = $st;
				$data['auto_closed'] = 0;
				$log[]               = 'অবস্থা: ' . self::STATUSES[ $st ];
			}
		}
		if ( null !== $request->get_param( 'assignee_id' ) ) {
			$aid = (int) $request->get_param( 'assignee_id' );
			$u   = $aid ? get_userdata( $aid ) : null;
			if ( $aid && ( ! $u || ( ! user_can( $u, 'edit_others_posts' ) && ! in_array( 'correspondent', (array) $u->roles, true ) ) ) ) {
				return new WP_Error( 'nros_bad', 'এই ব্যক্তিকে দায়িত্ব দেওয়া যায় না।', array( 'status' => 400 ) );
			}
			if ( $aid !== (int) $row['assignee_id'] ) {
				$data['assignee_id'] = $aid;
				$data['routed']      = $aid ? 'manual' : '';
				$log[]               = $aid ? 'দায়িত্ব: ' . $u->display_name : 'দায়িত্ব সরানো হলো';
				if ( $aid && $aid !== (int) $me->ID ) {
					$link = in_array( 'correspondent', (array) $u->roles, true ) ? '/app/tips?id=' . (int) $row['id'] : '/app/tips?id=' . (int) $row['id'];
					self::notify( array( $aid ), 'tip_assigned', 'আপনাকে একটি গোপন তথ্য দেওয়া হলো: ' . ( self::CATEGORIES[ $row['category'] ] ?? '' ) . ' · ' . self::label_of_district( $row['district'] ), 'দিয়েছেন ' . $me->display_name . '। তথ্যদাতার পরিচয় গোপন রাখুন।', $link, (int) $row['id'] );
				}
				if ( $aid && null === $st && 'new' === $row['status'] ) {
					$data['status'] = 'assigned';
					$log[]          = 'অবস্থা: ' . self::STATUSES['assigned'];
				}
			}
		}
		if ( null !== $request->get_param( 'published_url' ) ) {
			$data['published_url'] = esc_url_raw( (string) $request->get_param( 'published_url' ) );
		}
		if ( null !== $request->get_param( 'urgent' ) ) {
			$data['urgent'] = $request->get_param( 'urgent' ) ? 1 : 0;
		}
		if ( $data ) {
			self::touch( $row['id'], $data );
			if ( $log ) {
				self::note( $row['id'], (int) $me->ID, 'system', implode( ' · ', $log ) );
			}
		}
		return rest_ensure_response( self::shape( self::row( $row['id'] ), true, $me ) );
	}

	/** POST /admin/tips/{id}/notes {kind: note|reply, body}. */
	public static function admin_note( $request ) {
		$row = self::load( $request );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$me   = self::me( $request );
		$kind = 'reply' === $request->get_param( 'kind' ) ? 'reply' : 'note';
		$body = trim( sanitize_textarea_field( (string) $request->get_param( 'body' ) ) );
		if ( '' === $body ) {
			return new WP_Error( 'nros_bad', 'কিছু লিখুন।', array( 'status' => 400 ) );
		}
		self::note( $row['id'], (int) $me->ID, $kind, mb_substr( $body, 0, 5000 ) );
		$data = array();
		if ( 'reply' === $kind && 'new' === $row['status'] ) {
			$data['status'] = 'verifying';
		}
		self::touch( $row['id'], $data );
		return rest_ensure_response( self::shape( self::row( $row['id'] ), true, $me ) );
	}

	/** DELETE /admin/tips/{id} — erase everything (Publisher). */
	public static function admin_delete( $request ) {
		global $wpdb;
		$row = self::load( $request );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		self::delete_files( $row['id'] );
		$wpdb->delete( self::t( 'notes' ), array( 'tip_id' => (int) $row['id'] ) );
		$wpdb->delete( self::t(), array( 'id' => (int) $row['id'] ) );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	// ------------------------------------------------------------------
	// correspondent (the tip was routed to them) — never sees contact
	// ------------------------------------------------------------------

	public static function mine_list( $request ) {
		global $wpdb;
		$me   = self::me( $request );
		$rows = self::ready() ? $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t() . " WHERE assignee_id = %d AND status <> 'rejected' ORDER BY id DESC LIMIT 100", $me->ID ), ARRAY_A ) : array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return rest_ensure_response(
			array(
				'items' => array_map(
					function ( $r ) {
						$s = self::shape( $r );
						unset( $s['has_contact'] );
						return $s;
					},
					$rows
				),
			)
		);
	}

	protected static function load_mine( $request ) {
		$row = self::load( $request );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$me = self::me( $request );
		if ( (int) $row['assignee_id'] !== (int) $me->ID ) {
			return new WP_Error( 'nros_forbidden', 'এই টিপটি আপনাকে দেওয়া হয়নি।', array( 'status' => 403 ) );
		}
		return $row;
	}

	public static function mine_get( $request ) {
		$row = self::load_mine( $request );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$s = self::shape( $row, true, self::me( $request ), false );
		unset( $s['has_contact'] );
		return rest_ensure_response( $s );
	}

	public static function mine_note( $request ) {
		$row = self::load_mine( $request );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$me   = self::me( $request );
		$body = trim( sanitize_textarea_field( (string) $request->get_param( 'body' ) ) );
		if ( '' === $body ) {
			return new WP_Error( 'nros_bad', 'কিছু লিখুন।', array( 'status' => 400 ) );
		}
		self::note( $row['id'], (int) $me->ID, 'note', mb_substr( $body, 0, 5000 ) );
		self::touch( $row['id'], array( 'unread' => 1 ) );
		$s = self::shape( self::row( $row['id'] ), true, $me, false );
		unset( $s['has_contact'] );
		return rest_ensure_response( $s );
	}

	// ------------------------------------------------------------------
	// daily: digest + retention
	// ------------------------------------------------------------------

	public static function daily() {
		global $wpdb;
		if ( ! self::ready() ) {
			return;
		}
		$cut = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 2 * DAY_IN_SECONDS ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		$n   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t() . " WHERE status = 'new' AND created_at < %s", $cut ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( $n > 0 ) {
			$u = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t() . " WHERE status = 'new' AND urgent = 1 AND created_at < %s", $cut ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			self::notify( self::staff_ids(), 'tip_digest', '৪৮ ঘণ্টার বেশি পুরোনো ' . self::bn( $n ) . 'টি গোপন তথ্য এখনো “নতুন”', $u ? 'এর মধ্যে ' . self::bn( $u ) . 'টি জরুরি। টিপস পাতায় দেখুন।' : 'টিপস পাতায় দেখে অবস্থা বদলান বা দায়িত্ব দিন।', '/app/tips?status=new', 0 );
		}
		// Retention: files of tips closed (বাতিল) more than 30 days ago are removed from disk.
		$old = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 30 * DAY_IN_SECONDS ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . self::t() . " WHERE status = 'rejected' AND files_count > 0 AND updated_at < %s LIMIT 200", $old ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( $ids as $id ) {
			if ( self::delete_files( (int) $id ) ) {
				$wpdb->update( self::t(), array( 'files_count' => 0 ), array( 'id' => (int) $id ) );
				self::note( (int) $id, 0, 'system', 'বাতিল হওয়ার ৩০ দিন পর ফাইলগুলো স্বয়ংক্রিয়ভাবে মুছে ফেলা হয়েছে।' );
			}
		}
	}
}
