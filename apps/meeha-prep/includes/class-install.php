<?php
/**
 * Activation, database tables, capabilities, upgrades.
 *
 * Tables (all InnoDB/utf8mb4 via $wpdb->get_charset_collate()):
 *   {prefix}mhp_orders    one row per checkout (tran_id is what SSLCommerz sees)
 *   {prefix}mhp_access    what a user may open (test, product, event ticket); unique per user+item
 *   {prefix}mhp_attempts  test attempts with the frozen question order and answers
 *   {prefix}mhp_quiz      one row per user per daily quiz
 *   {prefix}mhp_cvs       CV studio: one row per reader CV (JSON data, share token)
 *   {prefix}mhp_bookings  career consultant sessions
 *   {prefix}mhp_points    points ledger; unique per user+source+ref, so nothing is counted twice
 *   {prefix}mhp_mistakes  the reader's wrong answers (ভুলের খাতা), one row per user+question
 *   {prefix}mhp_skills    per-subject answered/correct counts (দুর্বল বিষয়)
 *   {prefix}mhp_shipments printed publications: address, delivery fee, courier status
 *
 * @package meeha-prep
 */

defined( 'ABSPATH' ) || exit;

/**
 * Installer.
 */
class MHP_Install {

	/**
	 * Table names.
	 *
	 * @param string $t orders|access|attempts|quiz.
	 * @return string
	 */
	public static function table( $t ) {
		global $wpdb;
		return $wpdb->prefix . 'mhp_' . $t;
	}

	/**
	 * Activation.
	 */
	public static function activate() {
		self::create_tables();
		self::grant_caps();
		MHP_Post_Types::register();
		update_option( 'mhp_db_version', MHP_DB_VERSION, false );
		if ( ! get_option( 'mhp_seeded' ) ) {
			update_option( 'mhp_seed_pending', 1, false );
		}
		MHP_Cron::schedule();
		update_option( 'mhp_flush_rewrite', 1, false );
	}

	/**
	 * Deactivation: stop cron only (data stays).
	 */
	public static function deactivate() {
		MHP_Cron::unschedule();
		flush_rewrite_rules( false );
	}

	/**
	 * Upgrade when the DB version changes (plugins updated by FTP do not fire activation).
	 */
	public static function maybe_upgrade() {
		if ( (int) get_option( 'mhp_db_version', 0 ) < MHP_DB_VERSION ) {
			self::create_tables();
			self::grant_caps();
			update_option( 'mhp_db_version', MHP_DB_VERSION, false );
			MHP_Cron::schedule();
		}
	}

	/**
	 * Create / update tables with dbDelta.
	 */
	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();

		$orders   = self::table( 'orders' );
		$access   = self::table( 'access' );
		$attempts = self::table( 'attempts' );
		$quiz     = self::table( 'quiz' );
		$cvs      = self::table( 'cvs' );
		$bookings = self::table( 'bookings' );
		$points   = self::table( 'points' );
		$mistakes = self::table( 'mistakes' );
		$skills   = self::table( 'skills' );
		$ships    = self::table( 'shipments' );

		dbDelta(
			"CREATE TABLE {$orders} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  tran_id varchar(40) NOT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  item_type varchar(20) NOT NULL DEFAULT '',
  item_id bigint(20) unsigned NOT NULL DEFAULT 0,
  item_name varchar(255) NOT NULL DEFAULT '',
  amount decimal(10,2) NOT NULL DEFAULT 0.00,
  currency char(3) NOT NULL DEFAULT 'BDT',
  status varchar(20) NOT NULL DEFAULT 'pending',
  gateway varchar(20) NOT NULL DEFAULT '',
  mode varchar(10) NOT NULL DEFAULT '',
  phone varchar(20) NOT NULL DEFAULT '',
  val_id varchar(64) NOT NULL DEFAULT '',
  bank_tran_id varchar(80) NOT NULL DEFAULT '',
  card_type varchar(60) NOT NULL DEFAULT '',
  risk_level tinyint(4) NOT NULL DEFAULT -1,
  note text NULL,
  created_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  updated_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  paid_at datetime NULL DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY tran_id (tran_id),
  KEY user_id (user_id),
  KEY status_created (status,created_at)
) {$c};"
		);
		dbDelta(
			"CREATE TABLE {$access} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  item_type varchar(20) NOT NULL,
  item_id bigint(20) unsigned NOT NULL,
  order_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  PRIMARY KEY  (id),
  UNIQUE KEY user_item (user_id,item_type,item_id),
  KEY item (item_type,item_id)
) {$c};"
		);
		dbDelta(
			"CREATE TABLE {$attempts} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  test_id bigint(20) unsigned NOT NULL,
  status varchar(12) NOT NULL DEFAULT 'active',
  is_first tinyint(1) NOT NULL DEFAULT 0,
  started_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  deadline_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  submitted_at datetime NULL DEFAULT NULL,
  qids longtext NOT NULL,
  answers longtext NULL,
  score decimal(8,2) NOT NULL DEFAULT 0.00,
  correct smallint(5) unsigned NOT NULL DEFAULT 0,
  wrong smallint(5) unsigned NOT NULL DEFAULT 0,
  skipped smallint(5) unsigned NOT NULL DEFAULT 0,
  total smallint(5) unsigned NOT NULL DEFAULT 0,
  seconds int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY user_test (user_id,test_id),
  KEY test_rank (test_id,is_first,status,score)
) {$c};"
		);
		dbDelta(
			"CREATE TABLE {$quiz} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  quiz_date date NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  answers text NULL,
  correct tinyint(3) unsigned NOT NULL DEFAULT 0,
  answered tinyint(3) unsigned NOT NULL DEFAULT 0,
  total tinyint(3) unsigned NOT NULL DEFAULT 0,
  started_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  finished_at datetime NULL DEFAULT NULL,
  seconds int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY day_user (quiz_date,user_id),
  KEY day_rank (quiz_date,correct,seconds)
) {$c};"
		);
		dbDelta(
			"CREATE TABLE {$cvs} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  tpl_id bigint(20) unsigned NOT NULL DEFAULT 0,
  title varchar(150) NOT NULL DEFAULT '',
  data longtext NULL,
  token varchar(32) NOT NULL,
  is_public tinyint(1) NOT NULL DEFAULT 0,
  photo varchar(100) NOT NULL DEFAULT '',
  created_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  updated_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  PRIMARY KEY  (id),
  UNIQUE KEY token (token),
  KEY user_id (user_id)
) {$c};"
		);
		dbDelta(
			"CREATE TABLE {$bookings} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  consultant_id bigint(20) unsigned NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'requested',
  topic varchar(150) NOT NULL DEFAULT '',
  question text NULL,
  pref_date date NULL DEFAULT NULL,
  pref_time varchar(30) NOT NULL DEFAULT '',
  phone varchar(20) NOT NULL DEFAULT '',
  fee decimal(10,2) NOT NULL DEFAULT 0.00,
  order_id bigint(20) unsigned NOT NULL DEFAULT 0,
  slot varchar(60) NOT NULL DEFAULT '',
  meet_link varchar(255) NOT NULL DEFAULT '',
  admin_note text NULL,
  created_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  updated_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  PRIMARY KEY  (id),
  KEY user_id (user_id),
  KEY consultant_status (consultant_id,status)
) {$c};"
		);
		dbDelta(
			"CREATE TABLE {$points} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  source varchar(20) NOT NULL,
  ref varchar(40) NOT NULL,
  points int(11) NOT NULL DEFAULT 0,
  day date NOT NULL,
  created_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  PRIMARY KEY  (id),
  UNIQUE KEY user_src_ref (user_id,source,ref),
  KEY day_user (day,user_id),
  KEY user_day (user_id,day)
) {$c};"
		);
		dbDelta(
			"CREATE TABLE {$mistakes} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  question_id bigint(20) unsigned NOT NULL,
  source varchar(10) NOT NULL DEFAULT '',
  wrong_count smallint(5) unsigned NOT NULL DEFAULT 1,
  last_wrong datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  avail_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  fixed_at datetime NULL DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY user_q (user_id,question_id),
  KEY user_open (user_id,fixed_at)
) {$c};"
		);
		dbDelta(
			"CREATE TABLE {$skills} (
  user_id bigint(20) unsigned NOT NULL,
  subject_id bigint(20) unsigned NOT NULL,
  seen int(10) unsigned NOT NULL DEFAULT 0,
  correct int(10) unsigned NOT NULL DEFAULT 0,
  updated_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  PRIMARY KEY  (user_id,subject_id)
) {$c};"
		);
		dbDelta(
			"CREATE TABLE {$ships} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  pub_id bigint(20) unsigned NOT NULL,
  qty smallint(5) unsigned NOT NULL DEFAULT 1,
  unit_price decimal(10,2) NOT NULL DEFAULT 0.00,
  ship_fee decimal(10,2) NOT NULL DEFAULT 0.00,
  amount decimal(10,2) NOT NULL DEFAULT 0.00,
  pay varchar(10) NOT NULL DEFAULT 'online',
  status varchar(20) NOT NULL DEFAULT 'unpaid',
  zone varchar(10) NOT NULL DEFAULT '',
  name varchar(100) NOT NULL DEFAULT '',
  phone varchar(20) NOT NULL DEFAULT '',
  district varchar(40) NOT NULL DEFAULT '',
  address text NULL,
  order_id bigint(20) unsigned NOT NULL DEFAULT 0,
  stock_taken tinyint(1) NOT NULL DEFAULT 0,
  courier varchar(60) NOT NULL DEFAULT '',
  tracking varchar(80) NOT NULL DEFAULT '',
  note text NULL,
  created_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  updated_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  PRIMARY KEY  (id),
  KEY user_id (user_id),
  KEY status_created (status,created_at),
  KEY pub_id (pub_id)
) {$c};"
		);
	}

	/**
	 * Capabilities: mhp_manage (content) and mhp_manage_orders (money).
	 */
	public static function grant_caps() {
		$items = MHP_Post_Types::primitive_caps();
		$map   = array(
			'administrator'   => array_merge( $items, array( 'mhp_manage', 'mhp_manage_orders' ) ),
			'meeha_publisher' => array_merge( $items, array( 'mhp_manage', 'mhp_manage_orders' ) ),
			'editor'          => array_merge( $items, array( 'mhp_manage' ) ),
		);
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
		}
	}
}
