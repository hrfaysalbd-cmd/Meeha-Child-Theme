<?php
/**
 * NROS_Insights — the publisher dashboard's analytics (round 20, plugin 1.5.0).
 *
 * Why this exists: until 1.4.0 every trend came from nros_daily_stats, a
 * once-a-night (00:10) snapshot of each post's LIFETIME counters, and a day's
 * number was "this snapshot minus the previous one". That had two faults:
 *   1. The 00:10 snapshot of day D holds the totals up to the END OF D-1 but
 *      was labelled D, so every day showed the previous day's views, and
 *      "today" (live minus the last stored snapshot) silently swallowed
 *      yesterday — Hafiz's "গতকালের ভিউ/শেয়ার দেখা যায় না".
 *   2. It needs WP-Cron. On meeha.news the cron had not run for 3 days
 *      (theme status "তালিকা শেষ হিসাব ৩ দিন আগে"), so whole days were missing.
 * The theme already records every counted view in the HOUR it happened
 * ({prefix}meeha_views_hourly, rolled into meeha_views_daily after 35 days)
 * and every share/reaction/comment on its DAY ({prefix}meeha_engagement). This
 * class reads those event tables directly, so a day's number is exactly what
 * happened that day (site time zone) and nothing depends on cron.
 *
 * Routes (staff only):
 *   GET /admin/insights?period=today|yesterday|7d|this_month|last_month|all&range=7|30|90
 *   GET /admin/realtime
 *   GET /admin/reporters?period=…|date&date=Y-m-d&filter=all|active|zero
 *
 * @package NewsroomOS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NROS_Insights {

	/** Everything the theme counts views for, plus video news. */
	const SITE_TYPES = array( 'post', 'meeha_video', 'meeha_photocard' );

	/** Roles listed in the reporter productivity table (anyone else who published in the window is added). */
	const REPORTER_ROLES = array( 'correspondent', 'author', 'contributor', 'editor' );

	const PERIODS = array( 'today', 'yesterday', '7d', 'this_month', 'last_month', 'all' );

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route(
			NROS_API_NS,
			'/admin/insights',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'insights' ),
				'permission_callback' => array( 'NROS_Core', 'require_staff' ),
			)
		);
		register_rest_route(
			NROS_API_NS,
			'/admin/realtime',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'realtime' ),
				'permission_callback' => array( 'NROS_Core', 'require_staff' ),
			)
		);
		register_rest_route(
			NROS_API_NS,
			'/admin/reporters',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'reporters' ),
				'permission_callback' => array( 'NROS_Core', 'require_staff' ),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Time helpers (all bounds are site-local, returned as Unix timestamps)
	// -------------------------------------------------------------------------

	/**
	 * DateTimeImmutable "now" in the site time zone.
	 *
	 * @return DateTimeImmutable
	 */
	protected static function now() {
		return new DateTimeImmutable( 'now', wp_timezone() );
	}

	/**
	 * [start, end) of a named period, plus the comparison window.
	 *
	 * "today" compares with yesterday up to the same clock time, "this_month"
	 * with the same number of elapsed days of last month — so a delta at 11 am
	 * is not "down 60 %" just because the day is not over yet.
	 *
	 * @param string                 $key Period.
	 * @param DateTimeImmutable|null $now Now.
	 * @return array{start:int,end:int,prev_start:?int,prev_end:?int,label:string}
	 */
	public static function bounds( $key, $now = null ) {
		$now   = $now ? $now : self::now();
		$t     = $now->getTimestamp();
		$mid   = $now->setTime( 0, 0, 0 );
		$month = $now->modify( 'first day of this month' )->setTime( 0, 0, 0 );
		switch ( $key ) {
			case 'yesterday':
				$s = $mid->modify( '-1 day' );
				return array( 'start' => $s->getTimestamp(), 'end' => $mid->getTimestamp(), 'prev_start' => $s->modify( '-1 day' )->getTimestamp(), 'prev_end' => $s->getTimestamp(), 'label' => 'গতকাল' );
			case '7d':
				$s = $mid->modify( '-6 days' );
				return array( 'start' => $s->getTimestamp(), 'end' => $t, 'prev_start' => $s->modify( '-7 days' )->getTimestamp(), 'prev_end' => $s->getTimestamp() + ( $t - $mid->getTimestamp() ), 'label' => 'গত ৭ দিন' );
			case 'this_month':
				$ps = $month->modify( '-1 month' );
				return array( 'start' => $month->getTimestamp(), 'end' => $t, 'prev_start' => $ps->getTimestamp(), 'prev_end' => min( $month->getTimestamp(), $ps->getTimestamp() + ( $t - $month->getTimestamp() ) ), 'label' => 'চলতি মাস' );
			case 'last_month':
				$s = $month->modify( '-1 month' );
				return array( 'start' => $s->getTimestamp(), 'end' => $month->getTimestamp(), 'prev_start' => $s->modify( '-1 month' )->getTimestamp(), 'prev_end' => $s->getTimestamp(), 'label' => 'গত মাস' );
			case 'all':
				return array( 'start' => 0, 'end' => $t, 'prev_start' => null, 'prev_end' => null, 'label' => 'সব সময়' );
			case 'today':
			default:
				$ys = $mid->modify( '-1 day' );
				return array( 'start' => $mid->getTimestamp(), 'end' => $t, 'prev_start' => $ys->getTimestamp(), 'prev_end' => $ys->getTimestamp() + ( $t - $mid->getTimestamp() ), 'label' => 'আজ' );
		}
	}

	/**
	 * Bounds of one calendar day (?date=Y-m-d), or null when invalid / in the future.
	 *
	 * @param string $date Date.
	 * @return array|null
	 */
	public static function day_bounds( $date ) {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $date ) ) {
			return null;
		}
		$d = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, wp_timezone() );
		if ( ! $d || $d->getTimestamp() > time() ) {
			return null;
		}
		$s = $d->getTimestamp();
		$e = min( time(), $d->modify( '+1 day' )->getTimestamp() );
		$months = array( 'জানুয়ারি', 'ফেব্রুয়ারি', 'মার্চ', 'এপ্রিল', 'মে', 'জুন', 'জুলাই', 'আগস্ট', 'সেপ্টেম্বর', 'অক্টোবর', 'নভেম্বর', 'ডিসেম্বর' );
		$bn     = function ( $n ) {
			return strtr( (string) $n, array( '0' => '০', '1' => '১', '2' => '২', '3' => '৩', '4' => '৪', '5' => '৫', '6' => '৬', '7' => '৭', '8' => '৮', '9' => '৯' ) );
		};
		$label  = $bn( $d->format( 'j' ) ) . ' ' . $months[ (int) $d->format( 'n' ) - 1 ] . ' ' . $bn( $d->format( 'Y' ) );
		return array( 'start' => $s, 'end' => $e, 'prev_start' => $d->modify( '-1 day' )->getTimestamp(), 'prev_end' => $s, 'label' => $label );
	}

	/** Site-local 'Y-m-d H:i:s' for a timestamp (posts.post_date is site-local). */
	protected static function local( $ts ) {
		return wp_date( 'Y-m-d H:i:s', (int) $ts );
	}

	/** Are the theme's event tables available? */
	public static function theme_tables() {
		return function_exists( 'meeha_metrics_table' ) && function_exists( 'meeha_metrics_ready' ) && meeha_metrics_ready();
	}

	// -------------------------------------------------------------------------
	// Event-table readers
	// -------------------------------------------------------------------------

	/**
	 * SQL fragment + args restricting a metrics table alias `x` to posts of
	 * the given types (and optionally one author).
	 *
	 * @param string[] $types  Post types.
	 * @param int      $author Author id (0 = any).
	 * @return array{0:string,1:array}
	 */
	protected static function post_filter( $types, $author = 0 ) {
		global $wpdb;
		$types = array_values( array_map( 'sanitize_key', (array) $types ) );
		$sql   = " AND x.post_id IN (SELECT p.ID FROM {$wpdb->posts} p WHERE p.post_type IN (" . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ')';
		$args  = $types;
		if ( $author ) {
			$sql   .= ' AND p.post_author = %d';
			$args[] = (int) $author;
		}
		return array( $sql . ')', $args );
	}

	/**
	 * Views between two timestamps: hourly rows (UTC hours) + rolled-up daily
	 * rows (site-local days, only for days older than the hourly window).
	 *
	 * @param int      $start  Start.
	 * @param int      $end    End (exclusive).
	 * @param string[] $types  Post types.
	 * @param int      $author Author.
	 * @param int[]    $post_ids Restrict to these posts (optional).
	 * @return int
	 */
	public static function views_between( $start, $end, $types = self::SITE_TYPES, $author = 0, $post_ids = array() ) {
		global $wpdb;
		if ( ! self::theme_tables() || $end <= $start ) {
			return 0;
		}
		list( $f, $fa ) = self::post_filter( $types, $author );
		if ( $post_ids ) {
			$f .= ' AND x.post_id IN (' . implode( ',', array_map( 'intval', $post_ids ) ) . ')';
		}
		$h = meeha_metrics_table( 'hourly' );
		$d = meeha_metrics_table( 'daily' );
		// An hour row belongs to [start, end) when the hour STARTS inside it (Dhaka is a whole-hour offset).
		$hs = gmdate( 'Y-m-d H:00:00', (int) $start + 3599 );
		$he = gmdate( 'Y-m-d H:i:s', (int) $end );
		$v  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(x.views),0) FROM {$h} x WHERE x.`hour` >= %s AND x.`hour` < %s{$f}", array_merge( array( $hs, $he ), $fa ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$ds = wp_date( 'Y-m-d', (int) $start );
		$de = wp_date( 'Y-m-d', max( (int) $start, (int) $end - 1 ) );
		$v += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(x.views),0) FROM {$d} x WHERE x.`day` >= %s AND x.`day` <= %s{$f}", array_merge( array( $ds, $de ), $fa ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		return $v;
	}

	/**
	 * Shares / reactions / comments between two timestamps (day granularity).
	 *
	 * @param int      $start  Start.
	 * @param int      $end    End.
	 * @param string[] $types  Types.
	 * @param int      $author Author.
	 * @return array{shares:int,reactions:int,comments:int}
	 */
	public static function engagement_between( $start, $end, $types = self::SITE_TYPES, $author = 0 ) {
		global $wpdb;
		$out = array( 'shares' => 0, 'reactions' => 0, 'comments' => 0 );
		if ( ! self::theme_tables() || $end <= $start ) {
			return $out;
		}
		list( $f, $fa ) = self::post_filter( $types, $author );
		$e   = meeha_metrics_table( 'engagement' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT COALESCE(SUM(x.shares),0) AS s, COALESCE(SUM(x.reactions),0) AS r, COALESCE(SUM(x.comments),0) AS c FROM {$e} x WHERE x.`day` >= %s AND x.`day` <= %s{$f}", array_merge( array( wp_date( 'Y-m-d', (int) $start ), wp_date( 'Y-m-d', max( (int) $start, (int) $end - 1 ) ) ), $fa ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( $row ) {
			$out = array( 'shares' => (int) $row->s, 'reactions' => (int) $row->r, 'comments' => (int) $row->c );
		}
		return $out;
	}

	/**
	 * Day-by-day series [{stat_date, views, reactions, shares, comments, published}]
	 * for every day from $first_day to today (zero-filled), from the event tables.
	 *
	 * @param string   $first_day Y-m-d (site-local).
	 * @param string[] $types     Types.
	 * @param int      $author    Author (0 = whole site).
	 * @return array
	 */
	public static function daily_series( $first_day, $types = self::SITE_TYPES, $author = 0 ) {
		global $wpdb;
		$tz    = wp_timezone();
		$today = self::now()->setTime( 0, 0, 0 );
		$cur   = DateTimeImmutable::createFromFormat( '!Y-m-d', $first_day, $tz );
		$cur   = $cur ? $cur : $today;
		$days  = array();
		while ( $cur <= $today && count( $days ) < 1000 ) {
			$k          = $cur->format( 'Y-m-d' );
			$days[ $k ] = array( 'stat_date' => $k, 'views' => 0, 'reactions' => 0, 'shares' => 0, 'comments' => 0, 'published' => 0 );
			$cur        = $cur->modify( '+1 day' );
		}
		if ( ! $days ) {
			return array();
		}
		$first = (string) array_key_first( $days );
		$start = DateTimeImmutable::createFromFormat( '!Y-m-d', $first, $tz )->getTimestamp();

		if ( self::theme_tables() ) {
			list( $f, $fa ) = self::post_filter( $types, $author );
			$h    = meeha_metrics_table( 'hourly' );
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT x.`hour` AS h, SUM(x.views) AS v FROM {$h} x WHERE x.`hour` >= %s{$f} GROUP BY x.`hour`", array_merge( array( gmdate( 'Y-m-d H:00:00', $start ) ), $fa ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			foreach ( (array) $rows as $r ) {
				$k = wp_date( 'Y-m-d', strtotime( $r->h . ' UTC' ) );
				if ( isset( $days[ $k ] ) ) {
					$days[ $k ]['views'] += (int) $r->v;
				}
			}
			$d    = meeha_metrics_table( 'daily' );
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT x.`day` AS d, SUM(x.views) AS v FROM {$d} x WHERE x.`day` >= %s{$f} GROUP BY x.`day`", array_merge( array( $first ), $fa ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			foreach ( (array) $rows as $r ) {
				if ( isset( $days[ $r->d ] ) ) {
					$days[ $r->d ]['views'] += (int) $r->v;
				}
			}
			$e    = meeha_metrics_table( 'engagement' );
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT x.`day` AS d, SUM(x.shares) AS s, SUM(x.reactions) AS r, SUM(x.comments) AS c FROM {$e} x WHERE x.`day` >= %s{$f} GROUP BY x.`day`", array_merge( array( $first ), $fa ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			foreach ( (array) $rows as $r ) {
				if ( isset( $days[ $r->d ] ) ) {
					$days[ $r->d ]['shares']    += (int) $r->s;
					$days[ $r->d ]['reactions'] += (int) $r->r;
					$days[ $r->d ]['comments']  += (int) $r->c;
				}
			}
		}

		$ptypes = array_values( array_intersect( (array) $types, array( 'post', 'meeha_video' ) ) );
		if ( $ptypes ) {
			$sql  = "SELECT DATE(post_date) AS d, COUNT(*) AS n FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN (" . implode( ',', array_fill( 0, count( $ptypes ), '%s' ) ) . ') AND post_date >= %s';
			$args = array_merge( $ptypes, array( $first . ' 00:00:00' ) );
			if ( $author ) {
				$sql   .= ' AND post_author = %d';
				$args[] = (int) $author;
			}
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( $sql . ' GROUP BY DATE(post_date)', $args ) ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL
				if ( isset( $days[ $r->d ] ) ) {
					$days[ $r->d ]['published'] = (int) $r->n;
				}
			}
		}
		return array_values( $days );
	}

	/**
	 * First day any event was recorded (for the 'all' range).
	 *
	 * @return string Y-m-d
	 */
	public static function first_event_day() {
		global $wpdb;
		$c = array();
		if ( self::theme_tables() ) {
			$h = $wpdb->get_var( 'SELECT MIN(`hour`) FROM ' . meeha_metrics_table( 'hourly' ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			if ( $h ) {
				$c[] = wp_date( 'Y-m-d', strtotime( $h . ' UTC' ) );
			}
			foreach ( array( 'daily', 'engagement' ) as $t ) {
				$d = $wpdb->get_var( 'SELECT MIN(`day`) FROM ' . meeha_metrics_table( $t ) ); // phpcs:ignore WordPress.DB.PreparedSQL
				if ( $d ) {
					$c[] = $d;
				}
			}
		}
		$c[] = wp_date( 'Y-m-d', strtotime( '-29 days' ) );
		return min( $c );
	}

	/**
	 * Drop-in replacement for the old snapshot-based ?range= trend
	 * (today|3|7|15|30|all), same row shape, used by /admin/analytics and
	 * /me/analytics. Returns null when the theme tables are missing so the
	 * caller can fall back to the snapshot table.
	 *
	 * @param string $range  Range key.
	 * @param int    $author Author (0 = site).
	 * @param array  $types  Types.
	 * @return array|null
	 */
	public static function range_trend( $range, $author = 0, $types = self::SITE_TYPES ) {
		if ( ! self::theme_tables() ) {
			return null;
		}
		$back  = isset( NROS_Analytics::RANGES[ $range ] ) ? NROS_Analytics::RANGES[ $range ] : 6;
		$first = null === $back ? self::first_event_day() : wp_date( 'Y-m-d', self::now()->setTime( 12, 0 )->modify( "-{$back} days" )->getTimestamp() );
		return self::daily_series( $first, $types, $author );
	}

	// -------------------------------------------------------------------------
	// Publishing counts
	// -------------------------------------------------------------------------

	/**
	 * Published posts/videos with post_date in [start, end).
	 *
	 * @param int $start Start.
	 * @param int $end   End.
	 * @return array{news:int,video:int,total:int}
	 */
	public static function published_between( $start, $end ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_type, COUNT(*) AS n FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('post','meeha_video') AND post_date >= %s AND post_date < %s GROUP BY post_type", self::local( $start ), self::local( $end ) ) );
		$out  = array( 'news' => 0, 'video' => 0, 'total' => 0 );
		foreach ( (array) $rows as $r ) {
			$out[ 'post' === $r->post_type ? 'news' : 'video' ] = (int) $r->n;
		}
		$out['total'] = $out['news'] + $out['video'];
		return $out;
	}

	// -------------------------------------------------------------------------
	// GET /admin/insights
	// -------------------------------------------------------------------------

	public static function insights( $request ) {
		global $wpdb;
		$period = sanitize_key( (string) $request->get_param( 'period' ) );
		$period = in_array( $period, self::PERIODS, true ) ? $period : 'today';
		$range  = (int) $request->get_param( 'range' );
		$range  = in_array( $range, array( 7, 30, 90 ), true ) ? $range : 30;
		$now    = self::now();

		// Publishing + views for every period card.
		$cards = array();
		foreach ( self::PERIODS as $key ) {
			$b    = self::bounds( $key, $now );
			$pub  = 'all' === $key ? self::published_all() : self::published_between( $b['start'], $b['end'] );
			$card = array(
				'key'       => $key,
				'label'     => $b['label'],
				'published' => $pub,
				'views'     => 'all' === $key ? self::lifetime_views() : self::views_between( $b['start'], $b['end'] ),
			);
			if ( null !== $b['prev_start'] ) {
				$card['prev_published'] = self::published_between( $b['prev_start'], $b['prev_end'] )['total'];
				$card['prev_views']     = self::views_between( $b['prev_start'], $b['prev_end'] );
			}
			$cards[] = $card;
		}

		// Engagement KPIs for the selected period.
		$b   = self::bounds( $period, $now );
		$eng = self::engagement_between( $b['start'], $b['end'] );
		$kpi = array(
			'period'    => $period,
			'label'     => $b['label'],
			'views'     => 'all' === $period ? self::lifetime_views() : self::views_between( $b['start'], $b['end'] ),
			'shares'    => $eng['shares'],
			'reactions' => $eng['reactions'],
			'comments'  => $eng['comments'],
			'published' => 'all' === $period ? self::published_all()['total'] : self::published_between( $b['start'], $b['end'] )['total'],
		);
		if ( 'all' === $period ) {
			$life             = self::lifetime_engagement();
			$kpi['shares']    = $life['shares'];
			$kpi['reactions'] = $life['reactions'];
			$kpi['comments']  = $life['comments'];
		}
		if ( null !== $b['prev_start'] ) {
			$pe                     = self::engagement_between( $b['prev_start'], $b['prev_end'] );
			$kpi['prev_views']      = self::views_between( $b['prev_start'], $b['prev_end'] );
			$kpi['prev_shares']     = $pe['shares'];
			$kpi['prev_reactions']  = $pe['reactions'];
			$kpi['prev_comments']   = $pe['comments'];
			$kpi['prev_published']  = self::published_between( $b['prev_start'], $b['prev_end'] )['total'];
		}
		$kpi['avg_views_per_news'] = $kpi['published'] ? round( self::views_of_posts_published_between( $b['start'], $b['end'] ) / $kpi['published'], 1 ) : 0;

		return new WP_REST_Response(
			array(
				'generated_at' => time(),
				'timezone'     => wp_timezone_string(),
				'cards'        => $cards,
				'kpi'          => $kpi,
				'trend'        => self::daily_series( wp_date( 'Y-m-d', $now->setTime( 12, 0 )->modify( '-' . ( $range - 1 ) . ' days' )->getTimestamp() ) ),
				'hourly'       => self::hourly_today_vs_yesterday( $now ),
				'top_posts'    => self::top_posts( $b['start'], $b['end'], 10, 'all' === $period ),
				'categories'   => self::categories( $b['start'], $b['end'] ),
				'sources'      => self::share_networks(),
				'health'       => self::health(),
			),
			200
		);
	}

	/** Lifetime views of all published content (the theme's per-post counter). */
	public static function lifetime_views() {
		global $wpdb;
		$in = "'" . implode( "','", array_map( 'esc_sql', self::SITE_TYPES ) ) . "'";
		return (int) $wpdb->get_var( "SELECT COALESCE(SUM(CAST(pm.meta_value AS UNSIGNED)),0) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_meeha_views' AND p.post_status = 'publish' AND p.post_type IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/** Lifetime shares/reactions/comments of all published content. */
	protected static function lifetime_engagement() {
		global $wpdb;
		$in  = "'" . implode( "','", array_map( 'esc_sql', self::SITE_TYPES ) ) . "'";
		$out = array();
		foreach ( array( 'reactions' => '_meeha_reactions', 'shares' => '_meeha_share_counts' ) as $k => $meta ) {
			$sum = 0;
			foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND p.post_status = 'publish' AND p.post_type IN ({$in})", $meta ) ) as $raw ) { // phpcs:ignore WordPress.DB.PreparedSQL
				$sum += NROS_Analytics::sum_meta_value( $raw );
			}
			$out[ $k ] = $sum;
		}
		$out['comments'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} c INNER JOIN {$wpdb->posts} p ON p.ID = c.comment_post_ID WHERE c.comment_approved = '1' AND p.post_status = 'publish' AND p.post_type IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL
		return $out;
	}

	/** Every published news/video. */
	protected static function published_all() {
		$c     = wp_count_posts( 'post' );
		$v     = post_type_exists( 'meeha_video' ) ? wp_count_posts( 'meeha_video' ) : null;
		$news  = isset( $c->publish ) ? (int) $c->publish : 0;
		$video = $v && isset( $v->publish ) ? (int) $v->publish : 0;
		return array( 'news' => $news, 'video' => $video, 'total' => $news + $video );
	}

	/** Views (lifetime counter) of the news published in a window — for "average views per news". */
	protected static function views_of_posts_published_between( $start, $end ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(CAST(pm.meta_value AS UNSIGNED)),0) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_meeha_views' AND p.post_status = 'publish' AND p.post_type IN ('post','meeha_video') AND p.post_date >= %s AND p.post_date < %s", self::local( $start ), self::local( $end ) ) );
	}

	/**
	 * Views per local hour today and yesterday (24 buckets each).
	 *
	 * @param DateTimeImmutable $now Now.
	 * @return array{today:int[],yesterday:int[],hour:int}
	 */
	protected static function hourly_today_vs_yesterday( $now ) {
		global $wpdb;
		$out = array( 'today' => array_fill( 0, 24, 0 ), 'yesterday' => array_fill( 0, 24, 0 ), 'hour' => (int) $now->format( 'G' ) );
		if ( ! self::theme_tables() ) {
			return $out;
		}
		$mid  = $now->setTime( 0, 0, 0 );
		$ys   = $mid->modify( '-1 day' )->getTimestamp();
		$h    = meeha_metrics_table( 'hourly' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT `hour` AS h, SUM(views) AS v FROM {$h} WHERE `hour` >= %s GROUP BY `hour`", gmdate( 'Y-m-d H:00:00', $ys ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$today = $mid->format( 'Y-m-d' );
		foreach ( (array) $rows as $r ) {
			$ts  = strtotime( $r->h . ' UTC' );
			$key = wp_date( 'Y-m-d', $ts ) === $today ? 'today' : 'yesterday';
			$hr  = (int) wp_date( 'G', $ts );
			if ( $ts >= $ys ) {
				$out[ $key ][ $hr ] += (int) $r->v;
			}
		}
		return $out;
	}

	/**
	 * Most-read content in a window.
	 *
	 * @param int  $start    Start.
	 * @param int  $end      End.
	 * @param int  $limit    Limit.
	 * @param bool $lifetime Use the lifetime counter (the 'all' period).
	 * @return array
	 */
	public static function top_posts( $start, $end, $limit = 10, $lifetime = false ) {
		global $wpdb;
		$in   = "'" . implode( "','", array_map( 'esc_sql', self::SITE_TYPES ) ) . "'";
		$rows = array();
		if ( $lifetime || ! self::theme_tables() ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT p.ID AS id, CAST(pm.meta_value AS UNSIGNED) AS v FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_meeha_views' AND p.post_status = 'publish' AND p.post_type IN ({$in}) ORDER BY v DESC LIMIT %d", $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		} else {
			$h    = meeha_metrics_table( 'hourly' );
			$d    = meeha_metrics_table( 'daily' );
			$hs   = gmdate( 'Y-m-d H:00:00', (int) $start + 3599 );
			$he   = gmdate( 'Y-m-d H:i:s', (int) $end );
			$ds   = wp_date( 'Y-m-d', (int) $start );
			$de   = wp_date( 'Y-m-d', max( (int) $start, (int) $end - 1 ) );
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT t.id, SUM(t.v) AS v FROM ( SELECT post_id AS id, views AS v FROM {$h} WHERE `hour` >= %s AND `hour` < %s UNION ALL SELECT post_id AS id, views AS v FROM {$d} WHERE `day` >= %s AND `day` <= %s ) t INNER JOIN {$wpdb->posts} p ON p.ID = t.id WHERE p.post_status = 'publish' AND p.post_type IN ({$in}) GROUP BY t.id ORDER BY v DESC LIMIT %d", $hs, $he, $ds, $de, $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		$out = array();
		foreach ( (array) $rows as $r ) {
			$p = get_post( (int) $r->id );
			if ( ! $p ) {
				continue;
			}
			$author = get_userdata( (int) $p->post_author );
			$cats   = 'post' === $p->post_type ? get_the_category( $p->ID ) : array();
			$shares = NROS_Analytics::sum_meta_value( get_post_meta( $p->ID, '_meeha_share_counts', true ) );
			$out[]  = array(
				'id'       => $p->ID,
				'title'    => html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ),
				'url'      => get_permalink( $p ),
				'type'     => $p->post_type,
				'views'    => (int) $r->v,
				'lifetime' => (int) get_post_meta( $p->ID, '_meeha_views', true ),
				'shares'   => $shares,
				'author'   => $author ? $author->display_name : '',
				'category' => $cats ? html_entity_decode( $cats[0]->name, ENT_QUOTES, 'UTF-8' ) : '',
				'date'     => $p->post_date,
				'thumb'    => get_the_post_thumbnail_url( $p, 'thumbnail' ) ? get_the_post_thumbnail_url( $p, 'thumbnail' ) : '',
			);
		}
		return $out;
	}

	/**
	 * Top categories in a window: news published + views of the window.
	 *
	 * @param int $start Start.
	 * @param int $end   End.
	 * @return array
	 */
	protected static function categories( $start, $end ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT tt.term_id AS id, COUNT(DISTINCT p.ID) AS n FROM {$wpdb->posts} p INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'category' WHERE p.post_type = 'post' AND p.post_status = 'publish' AND p.post_date >= %s AND p.post_date < %s GROUP BY tt.term_id ORDER BY n DESC LIMIT 12",
				self::local( $start ),
				self::local( $end )
			)
		);
		$views = array();
		if ( self::theme_tables() ) {
			$h  = meeha_metrics_table( 'hourly' );
			$d  = meeha_metrics_table( 'daily' );
			$hs = gmdate( 'Y-m-d H:00:00', (int) $start + 3599 );
			$he = gmdate( 'Y-m-d H:i:s', (int) $end );
			$ds = wp_date( 'Y-m-d', (int) $start );
			$de = wp_date( 'Y-m-d', max( (int) $start, (int) $end - 1 ) );
			$vr = $wpdb->get_results( $wpdb->prepare( "SELECT tt.term_id AS id, SUM(t.v) AS v FROM ( SELECT post_id AS pid, views AS v FROM {$h} WHERE `hour` >= %s AND `hour` < %s UNION ALL SELECT post_id AS pid, views AS v FROM {$d} WHERE `day` >= %s AND `day` <= %s ) t INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = t.pid INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'category' GROUP BY tt.term_id ORDER BY v DESC LIMIT 12", $hs, $he, $ds, $de ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			foreach ( (array) $vr as $r ) {
				$views[ (int) $r->id ] = (int) $r->v;
			}
		}
		$ids = array_unique( array_merge( wp_list_pluck( (array) $rows, 'id' ), array_keys( $views ) ) );
		$pub = array();
		foreach ( (array) $rows as $r ) {
			$pub[ (int) $r->id ] = (int) $r->n;
		}
		$out = array();
		foreach ( $ids as $id ) {
			$term = get_term( (int) $id, 'category' );
			if ( ! $term || is_wp_error( $term ) ) {
				continue;
			}
			$out[] = array(
				'id'        => (int) $id,
				'name'      => html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ),
				'published' => isset( $pub[ $id ] ) ? $pub[ $id ] : 0,
				'views'     => isset( $views[ $id ] ) ? $views[ $id ] : 0,
			);
		}
		usort(
			$out,
			function ( $a, $b ) {
				return ( $b['views'] <=> $a['views'] ) ?: ( $b['published'] <=> $a['published'] );
			}
		);
		return array_slice( $out, 0, 8 );
	}

	/** Lifetime share clicks per network (where readers share from). */
	protected static function share_networks() {
		global $wpdb;
		$out = array();
		foreach ( (array) $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_meeha_share_counts'" ) as $raw ) {
			$v = maybe_unserialize( $raw );
			if ( is_array( $v ) ) {
				foreach ( $v as $net => $n ) {
					if ( is_numeric( $n ) && $n > 0 ) {
						$out[ (string) $net ] = ( isset( $out[ $net ] ) ? $out[ $net ] : 0 ) + (int) $n;
					}
				}
			}
		}
		arsort( $out );
		return $out;
	}

	/**
	 * Data-health facts shown as a warning strip when something is off.
	 *
	 * @return array
	 */
	public static function health() {
		$lag  = 0;
		$crons = function_exists( '_get_cron_array' ) ? _get_cron_array() : array();
		if ( is_array( $crons ) && $crons ) {
			$lag = max( 0, time() - (int) min( array_keys( $crons ) ) );
		}
		$last = get_option( 'meeha_metrics_last_run' );
		$resc = get_option( 'meeha_cron_rescued' );
		return array(
			'theme_metrics'  => self::theme_tables(),
			'live'           => function_exists( 'meeha_live_summary' ),
			'cron_lag'       => $lag,
			'cron_disabled'  => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'lists_last_run' => is_array( $last ) ? (int) $last['time'] : 0,
			'rescued_at'     => is_array( $resc ) ? (int) $resc['time'] : 0,
		);
	}

	// -------------------------------------------------------------------------
	// GET /admin/realtime
	// -------------------------------------------------------------------------

	public static function realtime( $request ) {
		$live = function_exists( 'meeha_live_summary' ) ? meeha_live_summary( 8 ) : array( 'enabled' => false, 'now' => 0, 'last30' => 0, 'today' => 0, 'devices' => array(), 'sources' => array(), 'pages' => array(), 'window' => 150 );
		$live['supported'] = function_exists( 'meeha_live_summary' );
		$live['time']      = time();
		// Views counted in the last 30 minutes (hour granularity → this and the previous hour, prorated is
		// misleading, so report the current hour's views as-is).
		$live['views_this_hour'] = 0;
		if ( self::theme_tables() ) {
			global $wpdb;
			$live['views_this_hour'] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(views),0) FROM ' . meeha_metrics_table( 'hourly' ) . ' WHERE `hour` = %s', gmdate( 'Y-m-d H:00:00' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		$res = new WP_REST_Response( $live, 200 );
		$res->header( 'Cache-Control', 'no-store' );
		return $res;
	}

	// -------------------------------------------------------------------------
	// GET /admin/reporters
	// -------------------------------------------------------------------------

	public static function reporters( $request ) {
		global $wpdb;
		$period = sanitize_key( (string) $request->get_param( 'period' ) );
		$b      = null;
		if ( 'date' === $period ) {
			$b = self::day_bounds( (string) $request->get_param( 'date' ) );
		}
		if ( ! $b ) {
			$period = in_array( $period, self::PERIODS, true ) ? $period : 'today';
			$b      = self::bounds( $period );
		}
		$filter = sanitize_key( (string) $request->get_param( 'filter' ) );
		$filter = in_array( $filter, array( 'all', 'active', 'zero' ), true ) ? $filter : 'all';
		$s      = self::local( $b['start'] );
		$e      = self::local( $b['end'] );

		$count = function ( $sql, $args ) use ( $wpdb ) {
			$out = array();
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ) ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL
				$out[ (int) $r->a ] = (int) $r->n;
			}
			return $out;
		};
		$published = $count( "SELECT post_author AS a, COUNT(*) AS n FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish' AND post_date >= %s AND post_date < %s GROUP BY post_author", array( $s, $e ) );
		$videos    = $count( "SELECT post_author AS a, COUNT(*) AS n FROM {$wpdb->posts} WHERE post_type = 'meeha_video' AND post_status = 'publish' AND post_date >= %s AND post_date < %s GROUP BY post_author", array( $s, $e ) );
		// Submitted for review in the window (still waiting), by when it was last saved.
		$pending  = $count( "SELECT post_author AS a, COUNT(*) AS n FROM {$wpdb->posts} WHERE post_type IN ('post','meeha_video') AND post_status = 'pending' AND post_modified >= %s AND post_modified < %s GROUP BY post_author", array( $s, $e ) );
		$lifetime = array();
		$last     = array();
		foreach ( (array) $wpdb->get_results( "SELECT post_author AS a, COUNT(*) AS n, MAX(post_date) AS d FROM {$wpdb->posts} WHERE post_type IN ('post','meeha_video') AND post_status = 'publish' GROUP BY post_author" ) as $r ) {
			$lifetime[ (int) $r->a ] = (int) $r->n;
			$last[ (int) $r->a ]     = (string) $r->d;
		}
		$views = array();
		if ( self::theme_tables() ) {
			$h  = meeha_metrics_table( 'hourly' );
			$d  = meeha_metrics_table( 'daily' );
			$vr = $wpdb->get_results( $wpdb->prepare( "SELECT p.post_author AS a, SUM(t.v) AS n FROM ( SELECT post_id AS pid, views AS v FROM {$h} WHERE `hour` >= %s AND `hour` < %s UNION ALL SELECT post_id AS pid, views AS v FROM {$d} WHERE `day` >= %s AND `day` <= %s ) t INNER JOIN {$wpdb->posts} p ON p.ID = t.pid WHERE p.post_type IN ('post','meeha_video') GROUP BY p.post_author", gmdate( 'Y-m-d H:00:00', $b['start'] + 3599 ), gmdate( 'Y-m-d H:i:s', $b['end'] ), wp_date( 'Y-m-d', $b['start'] ), wp_date( 'Y-m-d', max( $b['start'], $b['end'] - 1 ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			foreach ( (array) $vr as $r ) {
				$views[ (int) $r->a ] = (int) $r->n;
			}
		}

		$users = get_users( array( 'role__in' => self::REPORTER_ROLES, 'fields' => 'all' ) );
		$ids   = wp_list_pluck( $users, 'ID' );
		// Anyone else (an administrator, say) who published in the window is listed too.
		foreach ( array_keys( $published + $videos ) as $uid ) {
			if ( ! in_array( $uid, $ids, true ) ) {
				$u = get_userdata( $uid );
				if ( $u ) {
					$users[] = $u;
					$ids[]   = $uid;
				}
			}
		}
		$labels = array( 'correspondent' => 'সংবাদদাতা', 'author' => 'লেখক', 'contributor' => 'অবদানকারী', 'editor' => 'এডিটর', 'meeha_publisher' => 'পাবলিশার', 'administrator' => 'অ্যাডমিন' );
		$items  = array();
		$sum    = array( 'reporters' => 0, 'active' => 0, 'zero' => 0, 'published' => 0, 'videos' => 0, 'pending' => 0 );
		foreach ( $users as $u ) {
			$id    = (int) $u->ID;
			$role  = '';
			foreach ( array_merge( self::REPORTER_ROLES, array( 'meeha_publisher', 'administrator' ) ) as $r ) {
				if ( in_array( $r, (array) $u->roles, true ) ) {
					$role = $r;
					break;
				}
			}
			$n    = isset( $published[ $id ] ) ? $published[ $id ] : 0;
			$vids = isset( $videos[ $id ] ) ? $videos[ $id ] : 0;
			$row  = array(
				'id'             => $id,
				'name'           => $u->display_name,
				'avatar'         => get_avatar_url( $id, array( 'size' => 64 ) ),
				'role'           => $role,
				'role_label'     => isset( $labels[ $role ] ) ? $labels[ $role ] : $role,
				'division'       => (string) get_user_meta( $id, 'meeha_division', true ),
				'published'      => $n,
				'videos'         => $vids,
				'pending'        => isset( $pending[ $id ] ) ? $pending[ $id ] : 0,
				'views'          => isset( $views[ $id ] ) ? $views[ $id ] : 0,
				'total'          => isset( $lifetime[ $id ] ) ? $lifetime[ $id ] : 0,
				'last_published' => isset( $last[ $id ] ) ? $last[ $id ] : null,
			);
			++$sum['reporters'];
			$sum['published'] += $n;
			$sum['videos']    += $vids;
			$sum['pending']   += $row['pending'];
			if ( $n + $vids > 0 ) {
				++$sum['active'];
			} else {
				++$sum['zero'];
			}
			if ( 'active' === $filter && 0 === $n + $vids ) {
				continue;
			}
			if ( 'zero' === $filter && $n + $vids > 0 ) {
				continue;
			}
			$items[] = $row;
		}
		usort(
			$items,
			function ( $a, $b ) {
				return ( ( $b['published'] + $b['videos'] ) <=> ( $a['published'] + $a['videos'] ) ) ?: ( $b['views'] <=> $a['views'] ) ?: strcmp( (string) $b['last_published'], (string) $a['last_published'] );
			}
		);

		return new WP_REST_Response(
			array(
				'period'  => array( 'key' => $period, 'label' => $b['label'], 'start' => $s, 'end' => $e ),
				'filter'  => $filter,
				'summary' => $sum,
				'items'   => $items,
			),
			200
		);
	}
}
