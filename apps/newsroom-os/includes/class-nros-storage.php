<?php
/**
 * r48 (1.30.0) — স্টোরেজ ম্যানেজার: how much room the site uses, the video files on the server and
 * whether each can go, and one-click clean-ups. Publisher only.
 *
 * Meeha now keeps finished videos on YouTube, not on its own hosting: a correspondent's upload stays
 * only until the news is edited and its YouTube link is in the post. A video file can be deleted
 * here only when every news that used it plays from a valid YouTube link (or no news uses it at
 * all, with an extra confirmation) — never while a news still plays it from the server.
 *
 * Routes (nros/v1):
 *   GET  /admin/storage                — overview + video list (?refresh=1 recounts the folders)
 *   POST /admin/storage/videos/delete  — { ids: number[], orphans?: bool }
 *   POST /admin/storage/clean          — { what: 'upload_temp' | 'transients' | 'page_cache' | 'object_cache' }
 *
 * @package NewsroomOS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NROS_Storage {

	const CACHE = 'nros_storage_sizes';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		$pub = array( 'NROS_Core', 'require_publisher' );
		register_rest_route(
			NROS_API_NS,
			'/admin/storage',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'overview' ),
				'permission_callback' => $pub,
			)
		);
		register_rest_route(
			NROS_API_NS,
			'/admin/storage/videos/delete',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'delete_videos' ),
				'permission_callback' => $pub,
			)
		);
		register_rest_route(
			NROS_API_NS,
			'/admin/storage/for/(?P<post>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'for_post' ),
				'permission_callback' => $pub,
			)
		);
		register_rest_route(
			NROS_API_NS,
			'/admin/storage/clean',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'clean' ),
				'permission_callback' => $pub,
			)
		);
	}

	// ------------------------------------------------------------------
	// Sizes
	// ------------------------------------------------------------------

	/**
	 * Bytes and file count under a folder (stops after `$limit` files so a huge folder can't hang the request).
	 *
	 * @param string $dir   Folder.
	 * @param int    $limit Max files to look at.
	 * @return array{bytes:int, files:int, partial:bool}
	 */
	protected static function folder_size( $dir, $limit = 200000 ) {
		$out = array( 'bytes' => 0, 'files' => 0, 'partial' => false );
		if ( ! $dir || ! is_dir( $dir ) ) {
			return $out;
		}
		try {
			$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::LEAVES_ONLY, RecursiveIteratorIterator::CATCH_GET_CHILD );
			foreach ( $it as $f ) {
				if ( $f->isFile() ) {
					$out['bytes'] += (int) $f->getSize();
					++$out['files'];
					if ( $out['files'] >= $limit ) {
						$out['partial'] = true;
						break;
					}
				}
			}
		} catch ( Exception $e ) {
			$out['partial'] = true;
		}
		return $out;
	}

	/**
	 * Folder sizes, cached for 30 minutes (walking uploads can take a few seconds on a big site).
	 *
	 * @param bool $refresh Recount now.
	 * @return array
	 */
	protected static function sizes( $refresh = false ) {
		$cached = get_transient( self::CACHE );
		if ( ! $refresh && is_array( $cached ) ) {
			return $cached;
		}
		$up      = wp_upload_dir( null, false );
		$base    = $up['basedir'];
		$uploads = self::folder_size( $base );
		$temp    = self::folder_size( trailingslashit( $base ) . 'nros-upload-sessions' );
		$private = self::folder_size( NROS_PRIVATE_DIR );
		$free    = function_exists( 'disk_free_space' ) ? @disk_free_space( $base ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$total   = function_exists( 'disk_total_space' ) ? @disk_total_space( $base ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$out     = array(
			'uploads'     => $uploads,
			'upload_temp' => $temp,
			'private'     => $private,
			'free'        => false === $free ? null : (int) $free,
			'total'       => false === $total ? null : (int) $total,
			'counted_at'  => current_time( 'mysql' ),
		);
		set_transient( self::CACHE, $out, 30 * MINUTE_IN_SECONDS );
		return $out;
	}

	// ------------------------------------------------------------------
	// Which news uses which video file
	// ------------------------------------------------------------------

	/**
	 * Map of attachment id => list of [post id, how it is used], plus the protected library ids.
	 *
	 * @return array{refs: array<int, array<int, array{post:int, role:string}>>, posts: array<int, array>, assets: array<int, string>}
	 */
	protected static function references() {
		global $wpdb;
		$keys = array( '_meeha_video_source', '_meeha_video_url', '_meeha_video_attachment_id', '_meeha_video_original_id', '_meeha_video_footage', '_meeha_video_prev_media', '_meeha_video_edit' );
		$in   = "'" . implode( "','", array_map( 'esc_sql', $keys ) ) . "'";
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT p.ID, p.post_title, p.post_status, m.meta_key, m.meta_value FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key IN ({$in}) WHERE p.post_type = %s AND p.post_status NOT IN ('auto-draft')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				NROS_Videos::POST_TYPE
			),
			ARRAY_A
		);
		$posts = array();
		foreach ( (array) $rows as $r ) {
			$id = (int) $r['ID'];
			if ( ! isset( $posts[ $id ] ) ) {
				$posts[ $id ] = array( 'id' => $id, 'title' => (string) $r['post_title'], 'status' => (string) $r['post_status'], 'meta' => array() );
			}
			if ( $r['meta_key'] ) {
				$posts[ $id ]['meta'][ $r['meta_key'] ] = (string) $r['meta_value'];
			}
		}

		// an HD copy hangs off its quick copy (attachment meta _nros_hd_id)
		$hd_of = array();
		foreach ( (array) $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_nros_hd_id'", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$hd_of[ (int) $r['post_id'] ] = (int) $r['meta_value'];
		}

		$refs   = array();
		$assets = array();
		$add    = function ( $att, $post, $role ) use ( &$refs, $hd_of ) {
			$att = (int) $att;
			if ( $att <= 0 ) {
				return;
			}
			$refs[ $att ][] = array( 'post' => $post, 'role' => $role );
			if ( ! empty( $hd_of[ $att ] ) ) {
				$refs[ $hd_of[ $att ] ][] = array( 'post' => $post, 'role' => 'hd' );
			}
		};
		foreach ( $posts as $pid => &$p ) {
			$m      = $p['meta'];
			$source = isset( $m['_meeha_video_source'] ) && 'youtube' === $m['_meeha_video_source'] ? 'youtube' : 'upload';
			$yt     = 'youtube' === $source && ! empty( $m['_meeha_video_url'] ) ? NROS_Videos::parse_youtube_id( $m['_meeha_video_url'] ) : null;
			$p['source']  = $source;
			$p['youtube'] = $yt ? (string) $m['_meeha_video_url'] : null;
			if ( 'upload' === $source && ! empty( $m['_meeha_video_attachment_id'] ) ) {
				$add( $m['_meeha_video_attachment_id'], $pid, 'main' );
			}
			if ( ! empty( $m['_meeha_video_original_id'] ) ) {
				$add( $m['_meeha_video_original_id'], $pid, 'original' );
			}
			foreach ( array( '_meeha_video_footage' => 'footage', '_meeha_video_prev_media' => 'previous' ) as $k => $role ) {
				$ids = isset( $m[ $k ] ) ? json_decode( $m[ $k ], true ) : null;
				foreach ( is_array( $ids ) ? $ids : array() as $a ) {
					$add( $a, $pid, $role );
				}
			}
			// clips an edit plays (intro/outro/ad/second video/voice) stay — they belong to the edit
			if ( ! empty( $m['_meeha_video_edit'] ) && preg_match_all( '/"id"\s*:\s*(\d+)/', $m['_meeha_video_edit'], $mm ) ) {
				foreach ( $mm[1] as $a ) {
					$assets[ (int) $a ] = 'edit';
				}
			}
			unset( $p['meta'] );
		}
		unset( $p );

		// the studio's library: ad clips, and the video kit (intros/outros/presets)
		foreach ( (array) $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_nros_video_ad' AND meta_value = '1'" ) as $a ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$assets[ (int) $a ] = 'ad';
		}
		if ( class_exists( 'NROS_Video_Kit' ) && defined( 'NROS_Video_Kit::OPTION' ) ) {
			$kit = wp_json_encode( get_option( NROS_Video_Kit::OPTION, array() ) );
			if ( $kit && preg_match_all( '/"id"\s*:\s*(\d+)/', $kit, $mm ) ) {
				foreach ( $mm[1] as $a ) {
					$assets[ (int) $a ] = 'kit';
				}
			}
		}
		return array( 'refs' => $refs, 'posts' => $posts, 'assets' => $assets );
	}

	/**
	 * Whether a video file may go, and why.
	 *
	 * @param int   $att   Attachment.
	 * @param array $index references().
	 * @return array{status:string, news:array}
	 */
	protected static function verdict( $att, $index ) {
		$news = array();
		$live = false;
		$yt   = 0;
		foreach ( isset( $index['refs'][ $att ] ) ? $index['refs'][ $att ] : array() as $ref ) {
			$p = isset( $index['posts'][ $ref['post'] ] ) ? $index['posts'][ $ref['post'] ] : null;
			if ( ! $p ) {
				continue;
			}
			$key = $p['id'];
			if ( ! isset( $news[ $key ] ) ) {
				$news[ $key ] = array(
					'id'      => $p['id'],
					'title'   => $p['title'],
					'status'  => $p['status'],
					'source'  => $p['source'],
					'youtube' => $p['youtube'],
					'roles'   => array(),
				);
				if ( 'upload' === $p['source'] ) {
					$live = true;
				} elseif ( $p['youtube'] ) {
					++$yt;
				} else {
					$live = true; // a YouTube news without a working link still needs its file
				}
			}
			$news[ $key ]['roles'][] = $ref['role'];
		}
		foreach ( $news as &$n ) {
			$n['roles'] = array_values( array_unique( $n['roles'] ) );
		}
		unset( $n );
		if ( isset( $index['assets'][ $att ] ) ) {
			$status = 'asset';
		} elseif ( $live ) {
			$status = 'needs_link';
		} elseif ( $yt > 0 ) {
			$status = 'on_youtube';
		} else {
			$status = 'unused';
		}
		return array( 'status' => $status, 'news' => array_values( $news ) );
	}

	// ------------------------------------------------------------------
	// Routes
	// ------------------------------------------------------------------

	public static function overview( $request ) {
		$sizes = self::sizes( (bool) $request->get_param( 'refresh' ) );
		$index = self::references();
		$ids   = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'video',
				'posts_per_page' => 1000,
				'fields'         => 'ids',
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);
		$videos = array();
		$sum    = array( 'all' => 0, 'on_youtube' => 0, 'unused' => 0, 'needs_link' => 0, 'asset' => 0 );
		$count  = array( 'all' => 0, 'on_youtube' => 0, 'unused' => 0, 'needs_link' => 0, 'asset' => 0 );
		foreach ( $ids as $id ) {
			$file = get_attached_file( $id );
			$size = $file && file_exists( $file ) ? (int) filesize( $file ) : 0;
			$v    = self::verdict( (int) $id, $index );
			$by   = (int) get_post_meta( $id, '_nros_uploaded_by', true );
			$u    = $by ? get_userdata( $by ) : null;
			$videos[] = array(
				'id'       => (int) $id,
				'name'     => $file ? wp_basename( $file ) : get_the_title( $id ),
				'size'     => $size,
				'missing'  => ! $file || ! file_exists( $file ),
				'date'     => get_post_time( 'c', true, $id ),
				'by'       => $u ? $u->display_name : '',
				'mime'     => (string) get_post_mime_type( $id ),
				'url'      => (string) wp_get_attachment_url( $id ),
				'status'   => $v['status'],
				'news'     => $v['news'],
			);
			$sum['all']           += $size;
			$sum[ $v['status'] ]  += $size;
			++$count['all'];
			++$count[ $v['status'] ];
		}
		return array(
			'disk'   => $sizes,
			'videos' => $videos,
			'sum'    => $sum,
			'count'  => $count,
		);
	}

	/**
	 * r49 — GET /admin/storage/for/{post}: the server video files of one news, with whether each
	 * may go now (the studio's "সার্ভারের ভিডিও মুছুন" after the YouTube link is set).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	public static function for_post( $request ) {
		$post  = (int) $request['post'];
		$index = self::references();
		$out   = array();
		$sum   = 0;
		$free  = 0;
		foreach ( $index['refs'] as $att => $refs ) {
			$mine = false;
			foreach ( $refs as $r ) {
				if ( (int) $r['post'] === $post ) {
					$mine = true;
					break;
				}
			}
			if ( ! $mine || 'attachment' !== get_post_type( $att ) || 0 !== strpos( (string) get_post_mime_type( $att ), 'video/' ) ) {
				continue;
			}
			$file  = get_attached_file( $att );
			$size  = $file && file_exists( $file ) ? (int) filesize( $file ) : 0;
			$v     = self::verdict( (int) $att, $index );
			$sum  += $size;
			$free += 'on_youtube' === $v['status'] ? $size : 0;
			$roles = array();
			foreach ( $refs as $r ) {
				if ( (int) $r['post'] === $post ) {
					$roles[] = $r['role'];
				}
			}
			$out[] = array(
				'id'     => (int) $att,
				'name'   => $file ? wp_basename( $file ) : get_the_title( $att ),
				'size'   => $size,
				'status' => $v['status'],
				'roles'  => array_values( array_unique( $roles ) ),
				'shared' => count( $v['news'] ) > 1,
			);
		}
		$p = isset( $index['posts'][ $post ] ) ? $index['posts'][ $post ] : null;
		return array(
			'post'      => $post,
			'source'    => $p ? $p['source'] : '',
			'youtube'   => $p ? $p['youtube'] : null,
			'videos'    => $out,
			'size'      => $sum,
			'deletable' => $free,
		);
	}

	public static function delete_videos( $request ) {
		$ids     = array_slice( array_unique( array_map( 'intval', (array) $request->get_param( 'ids' ) ) ), 0, 200 );
		$orphans = (bool) $request->get_param( 'orphans' );
		$index   = self::references();
		$done    = array();
		$skipped = array();
		$freed   = 0;
		foreach ( $ids as $id ) {
			if ( $id <= 0 || 'attachment' !== get_post_type( $id ) || 0 !== strpos( (string) get_post_mime_type( $id ), 'video/' ) ) {
				$skipped[] = array( 'id' => $id, 'why' => 'ভিডিও ফাইল নয়' );
				continue;
			}
			$v  = self::verdict( $id, $index );
			$ok = 'on_youtube' === $v['status'] || ( 'unused' === $v['status'] && $orphans );
			if ( ! $ok ) {
				$why = array(
					'needs_link' => 'খবরে এখনো YouTube লিংক বসেনি — আগে লিংক বসান',
					'asset'      => 'স্টুডিওর লাইব্রেরির ফাইল (বিজ্ঞাপন/ইন্ট্রো/এডিটে ব্যবহৃত)',
					'unused'     => 'কোনো খবরে নেই — আলাদা করে নিশ্চিত করুন',
				);
				$skipped[] = array( 'id' => $id, 'why' => isset( $why[ $v['status'] ] ) ? $why[ $v['status'] ] : 'মোছা যাবে না' );
				continue;
			}
			$file = get_attached_file( $id );
			$size = $file && file_exists( $file ) ? (int) filesize( $file ) : 0;
			if ( wp_delete_attachment( $id, true ) ) {
				$done[] = $id;
				$freed += $size;
				foreach ( $v['news'] as $n ) {
					self::forget_media( (int) $n['id'], $id );
				}
			} else {
				$skipped[] = array( 'id' => $id, 'why' => 'মোছা যায়নি' );
			}
		}
		if ( $done ) {
			delete_transient( self::CACHE );
		}
		return array( 'deleted' => $done, 'skipped' => $skipped, 'freed' => $freed );
	}

	/**
	 * A deleted file leaves the news' lists, so nothing points at a file that is gone.
	 *
	 * @param int $post_id News.
	 * @param int $att     Deleted attachment.
	 */
	protected static function forget_media( $post_id, $att ) {
		foreach ( array( '_meeha_video_prev_media', '_meeha_video_footage' ) as $k ) {
			$ids = json_decode( (string) get_post_meta( $post_id, $k, true ), true );
			if ( ! is_array( $ids ) ) {
				continue;
			}
			$left = array_values( array_filter( array_map( 'intval', $ids ), function ( $x ) use ( $att ) {
				return $x !== $att;
			} ) );
			if ( $left ) {
				update_post_meta( $post_id, $k, wp_json_encode( $left ) );
			} else {
				delete_post_meta( $post_id, $k );
			}
		}
		if ( (int) get_post_meta( $post_id, '_meeha_video_original_id', true ) === $att ) {
			delete_post_meta( $post_id, '_meeha_video_original_id' );
			delete_post_meta( $post_id, '_meeha_video_edit' );
		}
	}

	public static function clean( $request ) {
		$what = sanitize_key( (string) $request->get_param( 'what' ) );
		$msg  = '';
		switch ( $what ) {
			case 'upload_temp':
				// half-finished uploads older than an hour (a live upload writes every few seconds)
				$up    = wp_upload_dir( null, false );
				$dir   = trailingslashit( $up['basedir'] ) . 'nros-upload-sessions';
				$freed = 0;
				$n     = 0;
				foreach ( (array) glob( $dir . '/*' ) as $f ) {
					if ( ! is_string( $f ) || in_array( basename( $f ), array( 'index.php', '.htaccess' ), true ) || filemtime( $f ) > time() - HOUR_IN_SECONDS ) {
						continue;
					}
					if ( is_dir( $f ) ) {
						foreach ( (array) glob( $f . '/*' ) as $piece ) {
							if ( is_string( $piece ) && is_file( $piece ) ) {
								$freed += (int) filesize( $piece );
								wp_delete_file( $piece );
							}
						}
						@rmdir( $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
					} else {
						$freed += (int) filesize( $f );
						wp_delete_file( $f );
					}
					++$n;
				}
				$msg = sprintf( 'অসমাপ্ত আপলোডের %d টি অংশ মোছা হয়েছে (%s)।', $n, size_format( $freed ) );
				break;
			case 'transients':
				$before = (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE '\\_transient\\_timeout\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				delete_expired_transients( true );
				$after = (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE '\\_transient\\_timeout\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				delete_transient( self::CACHE );
				$msg = sprintf( 'মেয়াদ পেরোনো %d টি অস্থায়ী তথ্য (transient) মোছা হয়েছে।', max( 0, $before - $after ) );
				break;
			case 'page_cache':
				$did = array();
				if ( has_action( 'litespeed_purge_all' ) || defined( 'LSCWP_V' ) ) {
					do_action( 'litespeed_purge_all' );
					$did[] = 'LiteSpeed';
				}
				if ( function_exists( 'rocket_clean_domain' ) ) {
					rocket_clean_domain();
					$did[] = 'WP Rocket';
				}
				if ( function_exists( 'w3tc_flush_all' ) ) {
					w3tc_flush_all();
					$did[] = 'W3 Total Cache';
				}
				if ( function_exists( 'wp_cache_clear_cache' ) ) {
					wp_cache_clear_cache();
					$did[] = 'WP Super Cache';
				}
				$msg = $did ? 'পাতার ক্যাশ খালি করা হয়েছে: ' . implode( ', ', $did ) . '।' : 'কোনো পাতা-ক্যাশ প্লাগইন পাওয়া যায়নি — কিছু করার দরকার নেই।';
				break;
			case 'object_cache':
				wp_cache_flush();
				$msg = 'অবজেক্ট ক্যাশ খালি করা হয়েছে।';
				break;
			default:
				return new WP_Error( 'nros_bad_request', 'কী পরিষ্কার করবেন বোঝা যায়নি।', array( 'status' => 400 ) );
		}
		return array( 'ok' => true, 'message' => $msg );
	}
}
