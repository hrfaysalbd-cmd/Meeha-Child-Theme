<?php
/**
 * NROS_Analytics — correspondent-facing engagement analytics (views, likes/
 * reactions, comments, shares) for the earnings dashboard's chart section.
 *
 * Reads the theme's EXISTING engagement meta keys live for lifetime totals
 * (_meeha_views, _meeha_reactions, _meeha_share_counts, native comment
 * counts) — zero theme changes required. A daily cron snapshot additionally
 * populates nros_daily_stats so the app can show a 7-day trend without
 * having to diff live numbers on every request.
 *
 * @package NewsroomOS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NROS_Analytics {

	/**
	 * Registers routes and the daily snapshot cron job.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );

		add_action( 'nros_daily_snapshot_event', array( __CLASS__, 'run_daily_snapshot' ) );

		if ( ! wp_next_scheduled( 'nros_daily_snapshot_event' ) ) {
			wp_schedule_event( strtotime( 'tomorrow 00:10:00' ), 'daily', 'nros_daily_snapshot_event' );
		}
	}

	/**
	 * Registers REST routes under nros/v1.
	 */
	public static function register_routes() {
		register_rest_route(
			NROS_API_NS,
			'/me/analytics',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'me_analytics' ),
				'permission_callback' => array( 'NROS_Core', 'require_correspondent' ),
			)
		);
	}

	/**
	 * Post types whose engagement counts toward a correspondent's (and the
	 * site's) totals. Round 10: video news (`meeha_video`) uses exactly the
	 * same engagement meta keys as articles, so it is simply another type
	 * here rather than a parallel analytics pipeline.
	 *
	 * @var string[]
	 */
	const ENGAGEMENT_POST_TYPES = array( 'post', 'meeha_video' );

	/**
	 * Sums a theme engagement meta value into one number.
	 *
	 * Round 10 fix: `_meeha_reactions` ({like, love, haha, wow, sad, angry})
	 * and `_meeha_share_counts` ({facebook, whatsapp, x, copy}) are stored
	 * by the theme as SERIALIZED ARRAYS, not integers. The old code cast
	 * them with (int) — a PHP array casts to 1 — and SUM(CAST(meta_value AS
	 * UNSIGNED)) in SQL — a serialized string "a:6:{...}" casts to 0 — so
	 * likes/shares were always wrong. This accepts the raw DB string, an
	 * already-unserialized array, or a legacy plain number, and never
	 * unserializes objects (maybe_unserialize() on DB data we wrote is
	 * fine, but non-numeric members are ignored defensively).
	 *
	 * @param mixed $raw Meta value (raw string from SQL, or get_post_meta() result).
	 * @return int
	 */
	public static function sum_meta_value( $raw ) {
		$value = is_string( $raw ) ? maybe_unserialize( $raw ) : $raw;

		if ( is_array( $value ) ) {
			$sum = 0;
			foreach ( $value as $count ) {
				if ( is_numeric( $count ) && $count > 0 ) {
					$sum += (int) $count;
				}
			}
			return $sum;
		}

		return is_numeric( $value ) ? max( 0, (int) $value ) : 0;
	}

	/**
	 * Live engagement for many posts at once: ONE postmeta query (all three
	 * meta keys for every id) plus ONE grouped comment-count query per
	 * chunk, summed in PHP — instead of 4 queries per post. Chunked at 500
	 * ids so the IN() list stays a sane size on a large archive.
	 *
	 * @param int[] $post_ids Post ids.
	 * @return array<int, array{views:int, reactions:int, shares:int, comments:int}> Keyed by post id; every requested id is present.
	 */
	public static function engagement_for_posts( $post_ids ) {
		global $wpdb;

		$post_ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $post_ids ) ) ) );
		$out      = array();
		foreach ( $post_ids as $id ) {
			$out[ $id ] = array(
				'views'     => 0,
				'reactions' => 0,
				'shares'    => 0,
				'comments'  => 0,
			);
		}

		$key_map = array(
			'_meeha_views'        => 'views',
			'_meeha_reactions'    => 'reactions',
			'_meeha_share_counts' => 'shares',
		);

		foreach ( array_chunk( $post_ids, 500 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );

			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ({$placeholders}) AND meta_key IN ('_meeha_views','_meeha_reactions','_meeha_share_counts')", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders
					$chunk
				),
				ARRAY_A
			);
			foreach ( (array) $rows as $row ) {
				$pid = (int) $row['post_id'];
				$key = $key_map[ $row['meta_key'] ];
				$out[ $pid ][ $key ] += self::sum_meta_value( $row['meta_value'] );
			}

			$comment_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT comment_post_ID AS post_id, COUNT(*) AS c FROM {$wpdb->comments} WHERE comment_post_ID IN ({$placeholders}) AND comment_approved = '1' GROUP BY comment_post_ID", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders
					$chunk
				),
				ARRAY_A
			);
			foreach ( (array) $comment_rows as $row ) {
				$out[ (int) $row['post_id'] ]['comments'] = (int) $row['c'];
			}
		}

		return $out;
	}

	/**
	 * Sums engagement_for_posts() into one totals row.
	 *
	 * @param int[] $post_ids Post ids.
	 * @return array{views:int, reactions:int, shares:int, comments:int}
	 */
	public static function totals_for_posts( $post_ids ) {
		$totals = array(
			'views'     => 0,
			'reactions' => 0,
			'shares'    => 0,
			'comments'  => 0,
		);
		foreach ( self::engagement_for_posts( $post_ids ) as $row ) {
			foreach ( $totals as $key => $unused ) {
				$totals[ $key ] += $row[ $key ];
			}
		}
		return $totals;
	}

	/**
	 * Reads a single post's live engagement numbers from the theme's
	 * existing meta keys and native comment count (reactions/shares summed
	 * across their per-type arrays — see sum_meta_value()).
	 *
	 * @param int $post_id Post id.
	 * @return array {views, reactions, shares, comments}
	 */
	public static function live_engagement( $post_id ) {
		return array(
			'views'     => self::sum_meta_value( get_post_meta( $post_id, '_meeha_views', true ) ),
			'reactions' => self::sum_meta_value( get_post_meta( $post_id, '_meeha_reactions', true ) ),
			'shares'    => self::sum_meta_value( get_post_meta( $post_id, '_meeha_share_counts', true ) ),
			'comments'  => (int) get_comments_number( $post_id ),
		);
	}

	/**
	 * Supported values for the ?range= query param on /me/analytics, mapped
	 * to how many days back the trend should include. 'all' has no lower
	 * bound (every snapshot row that exists). Unknown/missing values fall
	 * back to '7' so existing callers that don't pass a range keep the
	 * original behaviour.
	 *
	 * @var array<string, int|null>
	 */
	const RANGES = array(
		'today' => 0,
		'3'     => 2,
		'7'     => 6,
		'15'    => 14,
		'30'    => 29,
		'all'   => null,
	);

	/**
	 * GET /me/analytics — lifetime totals (read live, across all of the
	 * correspondent's published posts) plus a trend (read from the
	 * daily-snapshot table) for the dashboard's views/likes/comments/shares
	 * chart. The trend window is controlled by ?range=, one of
	 * today|3|7|15|30|all (default 7).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function me_analytics( $request ) {
		global $wpdb;
		$user = NROS_Auth::get_user_from_request( $request );

		$range = sanitize_key( (string) $request->get_param( 'range' ) );
		if ( ! array_key_exists( $range, self::RANGES ) ) {
			$range = '7';
		}

		$post_ids = get_posts(
			array(
				'post_type'      => self::ENGAGEMENT_POST_TYPES,
				'author'         => $user->ID,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		// Round 7 replaced an O(n) per-post get_post_meta() loop with one
		// SQL SUM — but SUM(CAST(...)) silently returned 0 for the
		// serialized reaction/share arrays. Round 10: still a constant
		// number of queries (one meta fetch + one comment count per 500
		// posts), with the arrays summed in PHP. Includes video news.
		$totals = self::totals_for_posts( $post_ids );

		$trend = array();

		if ( $post_ids ) {
			$days_back    = self::RANGES[ $range ];
			$placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );

			// One extra "buffer" day is fetched before the visible range so
			// there's a cumulative baseline to diff the first visible day
			// against — see cumulative_rows_to_daily_deltas()'s docblock.
			// It's trimmed back out of the response, never returned itself.
			$range_start = null === $days_back ? null : gmdate( 'Y-m-d', strtotime( "-{$days_back} days" ) );

			if ( null === $days_back ) {
				// 'all' — no lower date bound, every snapshot row on file.
				$query_args = $post_ids;
				$date_sql   = '';
			} else {
				$buffer_start = gmdate( 'Y-m-d', strtotime( '-' . ( $days_back + 1 ) . ' days' ) );
				$query_args   = array_merge( $post_ids, array( $buffer_start ) );
				$date_sql     = 'AND stat_date >= %s';
			}

			$raw_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT stat_date, SUM(views) AS views, SUM(reactions) AS reactions, SUM(shares) AS shares, SUM(comments) AS comments " // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					. "FROM {$wpdb->prefix}nros_daily_stats WHERE post_id IN ({$placeholders}) {$date_sql} " // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					. 'GROUP BY stat_date ORDER BY stat_date ASC',
					$query_args
				),
				ARRAY_A
			);

			$trend = self::cumulative_rows_to_daily_deltas( $raw_rows, $range_start, $totals );
		}

		return new WP_REST_Response(
			array(
				'totals' => $totals,
				'trend'  => $trend,
				'range'  => $range,
			),
			200
		);
	}

	/**
	 * Turns a series of CUMULATIVE-to-date snapshot rows (oldest first, as
	 * stored by run_daily_snapshot()) into DAILY DELTAS — how much was
	 * actually gained each day, which is what a "trend" chart should show
	 * rather than an ever-climbing running total — and replaces today's
	 * entry with one computed from live totals instead of the stale
	 * once-daily (00:10) cron snapshot, so "today" on the chart keeps
	 * moving through the day instead of freezing until the next midnight.
	 *
	 * $rows is expected to include exactly one extra day before
	 * $range_start (the caller's job to fetch) purely to seed the first
	 * visible day's baseline; that buffer day is used here but trimmed back
	 * out of the returned series, never returned itself.
	 *
	 * @param array       $rows Cumulative rows, oldest first, {stat_date, views, reactions, shares, comments}.
	 * @param string|null $range_start First date ('Y-m-d') to include in the output, or null for no trim (the 'all' range).
	 * @param array       $live_totals Today's true current cumulative {views, reactions, shares, comments}.
	 * @return array Daily-delta rows, oldest first, same shape as $rows.
	 */
	public static function cumulative_rows_to_daily_deltas( $rows, $range_start, $live_totals ) {
		$keys  = array( 'views', 'reactions', 'shares', 'comments' );
		$today = current_time( 'Y-m-d' );

		// Drop any stored row for today — even if the cron already ran
		// today, it's stale the moment any further engagement happens, so
		// it's always rebuilt live below instead of trusted as-is.
		$rows = array_values(
			array_filter(
				$rows,
				function ( $r ) use ( $today ) {
					return $r['stat_date'] !== $today;
				}
			)
		);

		$deltas   = array();
		$previous = null;

		foreach ( $rows as $row ) {
			$delta = array( 'stat_date' => $row['stat_date'] );
			foreach ( $keys as $key ) {
				$cur           = (float) $row[ $key ];
				$delta[ $key ] = $previous ? max( 0, $cur - (float) $previous[ $key ] ) : $cur;
			}
			$previous = $row;

			if ( null === $range_start || $row['stat_date'] >= $range_start ) {
				$deltas[] = $delta;
			}
		}

		$today_delta = array( 'stat_date' => $today );
		foreach ( $keys as $key ) {
			$today_delta[ $key ] = $previous ? max( 0, (float) $live_totals[ $key ] - (float) $previous[ $key ] ) : (float) $live_totals[ $key ];
		}
		$deltas[] = $today_delta;

		return $deltas;
	}

	/**
	 * Cron-driven: snapshots every published post's current engagement
	 * numbers into nros_daily_stats for today's date, batched 200/page via
	 * WP_Query with no_found_rows to avoid a memory spike on a large site.
	 *
	 * Round 5: also checks each post's view-count against the
	 * Publisher-configured bonus tiers in the same pass
	 * (`NROS_Ledger::apply_view_tier_bonus()`) — reusing this existing daily
	 * loop instead of adding a second full-site scan.
	 */
	public static function run_daily_snapshot() {
		global $wpdb;

		$today = current_time( 'Y-m-d' );
		$page  = 1;

		do {
			$query = new WP_Query(
				array(
					'post_type'      => self::ENGAGEMENT_POST_TYPES,
					'post_status'    => 'publish',
					'posts_per_page' => 200,
					'paged'          => $page,
					'no_found_rows'  => true,
					'fields'         => 'ids',
					'orderby'        => 'ID',
					'order'          => 'ASC',
				)
			);

			// One batched engagement read per 200-post page (round 10)
			// instead of 4 queries per post — and with reactions/shares
			// correctly summed from their arrays rather than cast to 1.
			$engagement = self::engagement_for_posts( $query->posts );

			foreach ( $query->posts as $post_id ) {
				$row = $engagement[ (int) $post_id ];

				$wpdb->query(
					$wpdb->prepare(
						"INSERT INTO {$wpdb->prefix}nros_daily_stats (post_id, stat_date, views, reactions, shares, comments) " // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
						. 'VALUES (%d, %s, %d, %d, %d, %d) '
						. 'ON DUPLICATE KEY UPDATE views = VALUES(views), reactions = VALUES(reactions), shares = VALUES(shares), comments = VALUES(comments)',
						$post_id,
						$today,
						$row['views'],
						$row['reactions'],
						$row['shares'],
						$row['comments']
					)
				);

				// View-tier bonuses are an article payment rule; videos are
				// counted in engagement but do not (yet) earn tier bonuses.
				if ( 'post' !== get_post_type( $post_id ) ) {
					continue;
				}
				$author_id = (int) get_post_field( 'post_author', $post_id );
				if ( $author_id ) {
					NROS_Ledger::apply_view_tier_bonus( $post_id, $author_id, $row['views'] );
				}
			}

			$has_more = count( $query->posts ) === 200;
			++$page;
		} while ( $has_more );
	}
}
