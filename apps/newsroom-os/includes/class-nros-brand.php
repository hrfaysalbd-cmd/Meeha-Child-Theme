<?php
/**
 * NROS_Brand — মিহার তথ্য + স্টেশনারি (r43, newsroom-os 1.26.0).
 *
 * Hafiz (2026-11-11): "মিহার তথ্য (যোগাযোগ, অফিস ইত্যাদি) একবার সেটিংস থেকে
 * সেইভ করে রাখলে প্রতি টেমপ্লেটে/ডিজাইনে তাই থাকবে।"
 *
 *  - One option, `nros_org_info`: name, address, phones, e-mail, web,
 *    social links, editor/publisher names, signature + seal images. Every
 *    ID card, visiting card, letterhead, pad … reads it.
 *  - The designs themselves are drawn in the apps (src/brand, shared by
 *    both apps); this class keeps the saved, edited copies (table
 *    nros_stationery) so a letter can be opened again and changed.
 *
 * @package NewsroomOS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NROS_Brand {

	const OPTION = 'nros_org_info';

	/** Every field of মিহার তথ্য with its default. */
	public static function defaults() {
		return array(
			'name_bn'        => 'মিহা নিউজ',
			'name_en'        => 'Meeha News',
			'tagline_bn'     => 'অনুসন্ধানী ও সর্বশেষ বাংলা সংবাদ',
			'tagline_en'     => 'Investigative & latest Bangla news',
			'website'        => 'meeha.news',
			'email'          => 'tips@meeha.news',
			'email2'         => '',
			'phone'          => '',
			'phone2'         => '',
			'whatsapp'       => '',
			'address_bn'     => '',
			'address_en'     => '',
			'office_hours'   => '',
			'reg_no'         => '',
			'facebook'       => '',
			'youtube'        => '',
			'editor_name'    => '',
			'editor_title'   => 'সম্পাদক',
			'publisher_name' => '',
			'publisher_title' => 'প্রকাশক',
			'signer_name'    => '',
			'signer_title'   => 'সম্পাদক ও প্রকাশক',
			'signature_url'  => '',
			'seal_url'       => '',
			'logo_url'       => '',
			'color_primary'  => '#025AB2',
			'color_accent'   => '#C8102E',
			'color_gold'     => '#C9A54C',
		);
	}

	/** @return array Saved info merged over the defaults. */
	public static function get() {
		$saved = get_option( self::OPTION, array() );
		$info  = array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
		$host  = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( '' === $info['website'] && $host ) {
			$info['website'] = $host;
		}
		$info['home_url'] = home_url( '/' );
		$info['verify_base'] = class_exists( 'NROS_ID_Cards' ) ? NROS_ID_Cards::verify_url( '' ) : home_url( '/verify/' );
		return $info;
	}

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function t() {
		global $wpdb;
		return $wpdb->prefix . 'nros_stationery';
	}

	/**
	 * @param string $c Charset.
	 * @return string
	 */
	public static function schema( $c ) {
		$t = self::t();
		return "CREATE TABLE {$t} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			kind VARCHAR(30) NOT NULL,
			design VARCHAR(40) NOT NULL,
			title VARCHAR(190) NOT NULL DEFAULT '',
			data LONGTEXT,
			shared TINYINT(1) NOT NULL DEFAULT 1,
			owner_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			updated_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY kind (kind),
			KEY owner_id (owner_id)
		) {$c};";
	}

	public static function register_routes() {
		$ns  = NROS_API_NS;
		$app = array( 'NROS_Core', 'require_app_user' );
		$stf = array( 'NROS_Core', 'require_staff' );
		register_rest_route( $ns, '/brand/org', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_org' ), 'permission_callback' => $app ) );
		register_rest_route( $ns, '/brand/org', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'save_org' ), 'permission_callback' => array( 'NROS_Core', 'require_publisher' ) ) );
		register_rest_route( $ns, '/stationery', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'list_docs' ), 'permission_callback' => $stf ) );
		register_rest_route( $ns, '/stationery', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'save_doc' ), 'permission_callback' => $stf ) );
		register_rest_route( $ns, '/stationery/(?P<id>\d+)', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'save_doc' ), 'permission_callback' => $stf ) );
		register_rest_route( $ns, '/stationery/(?P<id>\d+)', array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'delete_doc' ), 'permission_callback' => $stf ) );
	}

	public static function get_org() {
		return rest_ensure_response( array( 'org' => self::get(), 'defaults' => self::defaults(), 'assets' => self::assets() ) );
	}

	/** Signature / seal / logo as data URIs (for drawing on cards and letters). */
	public static function assets() {
		$o = self::get();
		return array(
			'signature' => self::data_uri( $o['signature_url'], 600 ),
			'seal'      => self::data_uri( $o['seal_url'], 500 ),
			'logo'      => self::data_uri( $o['logo_url'], 900 ),
		);
	}

	/** POST /brand/org — Publisher only. */
	public static function save_org( $request ) {
		$in   = (array) $request->get_json_params();
		$in   = isset( $in['org'] ) && is_array( $in['org'] ) ? $in['org'] : $in;
		$keep = array();
		foreach ( self::defaults() as $k => $def ) {
			if ( ! array_key_exists( $k, $in ) ) {
				continue;
			}
			$v = (string) $in[ $k ];
			if ( substr( $k, -4 ) === '_url' || in_array( $k, array( 'facebook', 'youtube' ), true ) ) {
				$v = esc_url_raw( $v );
			} elseif ( 0 === strpos( $k, 'color_' ) ) {
				$v = sanitize_hex_color( $v ) ? sanitize_hex_color( $v ) : $def;
			} elseif ( 0 === strpos( $k, 'address' ) ) {
				$v = sanitize_textarea_field( $v );
			} else {
				$v = sanitize_text_field( $v );
			}
			$keep[ $k ] = mb_substr( $v, 0, 400 );
		}
		$old = get_option( self::OPTION, array() );
		update_option( self::OPTION, array_merge( is_array( $old ) ? $old : array(), $keep ), false );
		return rest_ensure_response( array( 'ok' => true, 'org' => self::get() ) );
	}

	protected static function shape( $r ) {
		$data = json_decode( (string) $r['data'], true );
		$p    = class_exists( 'NROS_Chat' ) ? NROS_Chat::person( $r['updated_by'] ? $r['updated_by'] : $r['owner_id'] ) : array( 'name' => '' );
		return array(
			'id'         => (int) $r['id'],
			'kind'       => $r['kind'],
			'design'     => $r['design'],
			'title'      => $r['title'],
			'data'       => is_array( $data ) ? $data : new stdClass(),
			'shared'     => (bool) (int) $r['shared'],
			'owner_id'   => (int) $r['owner_id'],
			'by'         => $p['name'],
			'updated_at' => mysql_to_rfc3339( get_gmt_from_date( $r['updated_at'] ) ) . 'Z',
		);
	}

	/** GET /stationery?kind= — the team's saved designs + my own. */
	public static function list_docs( $request ) {
		global $wpdb;
		$u    = NROS_Auth::get_user_from_request( $request );
		$kind = sanitize_key( (string) $request->get_param( 'kind' ) );
		$sql  = 'SELECT * FROM ' . self::t() . ' WHERE (shared = 1 OR owner_id = %d)';
		$args = array( $u->ID );
		if ( $kind ) {
			$sql   .= ' AND kind = %s';
			$args[] = $kind;
		}
		$rows = $wpdb->get_results( $wpdb->prepare( $sql . ' ORDER BY updated_at DESC LIMIT 300', $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return rest_ensure_response( array( 'items' => array_map( array( __CLASS__, 'shape' ), (array) $rows ) ) );
	}

	/** POST /stationery[/{id}] — save a design copy. */
	public static function save_doc( $request ) {
		global $wpdb;
		$u    = NROS_Auth::get_user_from_request( $request );
		$id   = (int) $request->get_param( 'id' );
		$data = $request->get_param( 'data' );
		$json = wp_json_encode( is_array( $data ) ? $data : array() );
		if ( strlen( $json ) > 400000 ) {
			return new WP_Error( 'nros_too_big', 'ডিজাইনটি অনেক বড় (ছবি ছোট করুন)।', array( 'status' => 400 ) );
		}
		$row = array(
			'kind'       => sanitize_key( (string) $request->get_param( 'kind' ) ),
			'design'     => sanitize_key( (string) $request->get_param( 'design' ) ),
			'title'      => mb_substr( sanitize_text_field( (string) $request->get_param( 'title' ) ), 0, 190 ),
			'data'       => $json,
			'shared'     => false === $request->get_param( 'shared' ) ? 0 : 1,
			'updated_by' => $u->ID,
			'updated_at' => current_time( 'mysql' ),
		);
		if ( ! $row['kind'] || ! $row['design'] ) {
			return new WP_Error( 'nros_bad_request', 'ডিজাইনের ধরন নেই।', array( 'status' => 400 ) );
		}
		if ( $id ) {
			$old = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( ! $old || ( ! (int) $old['shared'] && (int) $old['owner_id'] !== (int) $u->ID ) ) {
				return new WP_Error( 'nros_not_found', 'ডিজাইনটি পাওয়া যায়নি।', array( 'status' => 404 ) );
			}
			$wpdb->update( self::t(), $row, array( 'id' => $id ) );
		} else {
			$row['owner_id']   = $u->ID;
			$row['created_at'] = $row['updated_at'];
			$wpdb->insert( self::t(), $row );
			$id = (int) $wpdb->insert_id;
		}
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return rest_ensure_response( array( 'ok' => true, 'item' => $r ? self::shape( $r ) : null ) );
	}

	public static function delete_doc( $request ) {
		global $wpdb;
		$u   = NROS_Auth::get_user_from_request( $request );
		$id  = (int) $request->get_param( 'id' );
		$old = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $old ) {
			return new WP_Error( 'nros_not_found', 'ডিজাইনটি পাওয়া যায়নি।', array( 'status' => 404 ) );
		}
		if ( (int) $old['owner_id'] !== (int) $u->ID && ! NROS_Chat::is_publisher( $u ) ) {
			return new WP_Error( 'nros_forbidden', 'শুধু যিনি বানিয়েছেন বা পাবলিশার মুছতে পারেন।', array( 'status' => 403 ) );
		}
		$wpdb->delete( self::t(), array( 'id' => $id ) );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * A local upload as a data: URI, so the apps can draw it on a canvas
	 * without cross-origin trouble (signature, seal, photo).
	 *
	 * @param string $url   Upload URL (this site).
	 * @param int    $max   Longest side in px.
	 * @return string '' when not a local image.
	 */
	public static function data_uri( $url, $max = 700 ) {
		$url = (string) $url;
		if ( '' === $url ) {
			return '';
		}
		$up   = wp_get_upload_dir();
		$base = preg_replace( '#^https?:#', '', $up['baseurl'] );
		$rel  = preg_replace( '#^https?:#', '', strtok( $url, '?' ) );
		if ( 0 !== strpos( $rel, $base ) ) {
			return '';
		}
		$path = $up['basedir'] . substr( $rel, strlen( $base ) );
		$real = realpath( $path );
		if ( ! $real || 0 !== strpos( $real, realpath( $up['basedir'] ) ) || ! is_readable( $real ) ) {
			return '';
		}
		$cache_key = 'nros_du_' . md5( $real . filemtime( $real ) . $max );
		$hit       = get_transient( $cache_key );
		if ( is_string( $hit ) && '' !== $hit ) {
			return $hit;
		}
		$mime = wp_check_filetype( $real );
		$mime = $mime['type'] ? $mime['type'] : 'image/jpeg';
		if ( 0 !== strpos( $mime, 'image/' ) ) {
			return '';
		}
		$bytes = '';
		$ed    = wp_get_image_editor( $real );
		if ( ! is_wp_error( $ed ) ) {
			$size = $ed->get_size();
			if ( $size['width'] > $max || $size['height'] > $max ) {
				$ed->resize( $max, $max, false );
			}
			$keep_png = 'image/png' === $mime;
			$ed->set_quality( 88 );
			$tmp = wp_tempnam( 'nros-du' );
			$res = $ed->save( $tmp, $keep_png ? 'image/png' : 'image/jpeg' );
			if ( ! is_wp_error( $res ) && ! empty( $res['path'] ) ) {
				$bytes = (string) file_get_contents( $res['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				$mime  = $keep_png ? 'image/png' : 'image/jpeg';
				wp_delete_file( $res['path'] );
			}
			wp_delete_file( $tmp );
		}
		if ( '' === $bytes && filesize( $real ) < 1500000 ) {
			$bytes = (string) file_get_contents( $real ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}
		if ( '' === $bytes ) {
			return '';
		}
		$out = 'data:' . $mime . ';base64,' . base64_encode( $bytes ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		set_transient( $cache_key, $out, DAY_IN_SECONDS );
		return $out;
	}
}
