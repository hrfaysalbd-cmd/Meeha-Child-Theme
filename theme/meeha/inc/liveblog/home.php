<?php
/**
 * Live posts on the rest of the site (2.4.14):
 *  - "এখন লাইভ" band on the homepage, between the header/ticker and the lead section — only while at least one live
 *    post is running; newest first, the latest update lines, update count, "২ মিনিট আগে"; a horizontal scroller when
 *    several run at once. liveband.js refreshes it from the public poll (meeha/v1/liveblog/{id}?brief=1) every 60 s
 *    while the tab is visible.
 *  - a compact red "লাইভ" pill at the start of the section nav on every page (one live → that post, several → the
 *    homepage band).
 * Both appear and disappear by themselves from `_meeha_live_status`; the only settings are the on/off switches under
 * মিহা থিম › হোমপেজ › লাইভ.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

/**
 * Running (status "live") live posts, newest update first. Cached ids; flushed whenever a live status or update
 * changes (see the hooks below).
 *
 * @param int $limit Max posts.
 * @return WP_Post[]
 */
function meeha_lb_running( $limit = 6 ) {
	$ids = get_transient( 'meeha_lb_running' );
	if ( ! is_array( $ids ) ) {
		$q   = new WP_Query(
			array(
				'post_type'              => 'post',
				'post_status'            => 'publish',
				'has_password'           => false,
				'posts_per_page'         => 12,
				'fields'                 => 'ids',
				'meta_key'               => '_meeha_live_status', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'             => 'live', // phpcs:ignore WordPress.DB.SlowDBQuery
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_term_cache' => false,
				'suppress_filters'       => true,
			)
		);
		$ids = array_map( 'intval', $q->posts );
		$at  = array();
		foreach ( $ids as $id ) {
			$last      = (int) get_post_meta( $id, '_meeha_live_last', true );
			$at[ $id ] = $last ? $last : (int) get_post_time( 'U', true, $id );
		}
		usort(
			$ids,
			function ( $a, $b ) use ( $at ) {
				return $at[ $b ] - $at[ $a ];
			}
		);
		set_transient( 'meeha_lb_running', $ids, 10 * MINUTE_IN_SECONDS );
	}
	$out = array();
	foreach ( $ids as $id ) {
		$p = get_post( $id );
		if ( $p && 'publish' === $p->post_status && 'live' === meeha_lb_status( $p ) ) {
			$out[] = $p;
		}
		if ( count( $out ) >= $limit ) {
			break;
		}
	}
	return $out;
}

/**
 * Forget the running list (and, on a status change, purge the cached homepage so the band appears / disappears).
 *
 * @param bool $purge_home Also ask LiteSpeed to purge the front page.
 */
function meeha_lb_running_flush( $purge_home = false ) {
	delete_transient( 'meeha_lb_running' );
	if ( $purge_home ) {
		do_action( 'litespeed_purge_url', home_url( '/' ) );
	}
}
add_action( 'meeha_live_changed', 'meeha_lb_running_flush' );
foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $meeha_lb_hook ) {
	add_action(
		$meeha_lb_hook,
		function ( $mid, $post_id, $key ) {
			if ( '_meeha_live_status' === $key ) {
				meeha_lb_running_flush( true );
			}
		},
		10,
		3
	);
}
unset( $meeha_lb_hook );
add_action(
	'transition_post_status',
	function ( $new, $old, $post ) {
		if ( $new !== $old && $post instanceof WP_Post && 'post' === $post->post_type && meeha_lb_is_live_post( $post ) ) {
			meeha_lb_running_flush( true );
		}
	},
	10,
	3
);

/**
 * Short, plain summary of a live post for the band and the brief poll: newest lines, count, last update.
 *
 * @param WP_Post $post  Live post.
 * @param int     $lines How many update lines.
 * @return array id, url, title, status, count, last, items [ {id, t, clock, text, kind} ]
 */
function meeha_lb_brief( $post, $lines = 3 ) {
	$rows  = meeha_lb_rows( $post->ID );
	$items = array();
	foreach ( array_slice( $rows, 0, max( 1, min( 5, (int) $lines ) ) ) as $r ) {
		$t       = meeha_lb_time( $r['ts'] );
		$text    = function_exists( 'mb_substr' ) && mb_strlen( $r['title'] ) > 140 ? mb_substr( $r['title'], 0, 138 ) . '…' : $r['title'];
		$items[] = array(
			'id'    => (int) $r['id'],
			't'     => (int) $r['ts'],
			'clock' => $t['clock'],
			'text'  => html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ),
			'kind'  => $r['kind'],
		);
	}
	$last = (int) get_post_meta( $post->ID, '_meeha_live_last', true );
	if ( ! $last && $rows ) {
		$last = (int) $rows[0]['ts'];
	}
	return array(
		'id'     => (int) $post->ID,
		'url'    => get_permalink( $post ),
		'title'  => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
		'status' => meeha_lb_status( $post ),
		'count'  => count( $rows ),
		'last'   => $last,
		'items'  => $items,
	);
}

/**
 * Is the homepage band switched on?
 *
 * @return bool
 */
function meeha_lb_band_on() {
	return (bool) meeha_opt( 'home', 'live_band', 1 );
}

/**
 * The homepage "এখন লাইভ" band ('' when nothing runs).
 *
 * @return string
 */
function meeha_lb_home_band_html() {
	if ( ! meeha_lb_band_on() ) {
		return '';
	}
	$posts = meeha_lb_running( max( 1, min( 6, (int) meeha_opt( 'home', 'live_band_count', 4 ) ) ) );
	if ( ! $posts ) {
		return '';
	}
	$lines = max( 2, min( 3, (int) meeha_opt( 'home', 'live_band_lines', 3 ) ) );
	$n     = count( $posts );
	$off   = (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );
	$kinds = meeha_lb_kinds();

	ob_start();
	?>
	<div role="region" class="m-liveband m-liveband--<?php echo 1 === $n ? 'one' : ( 2 === $n ? 'two' : 'many' ); ?>" id="live-now" data-liveband data-lines="<?php echo (int) $lines; ?>" aria-labelledby="m-liveband-title">
		<div class="m-container">
			<header class="m-liveband__head">
				<h2 class="m-liveband__title" id="m-liveband-title"><span class="m-liveband__pulse" aria-hidden="true"></span>এখন লাইভ</h2>
				<?php if ( $n > 1 ) : ?>
					<span class="m-liveband__n"><?php echo esc_html( meeha_bn_digits( $n ) ); ?>টি ঘটনার সরাসরি আপডেট</span>
					<span class="m-liveband__nav" aria-hidden="true">
						<button type="button" class="m-liveband__arrow" data-liveband-step="-1" tabindex="-1" aria-label="আগের"><?php echo meeha_icon( 'chev-l' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></button>
						<button type="button" class="m-liveband__arrow" data-liveband-step="1" tabindex="-1" aria-label="পরের"><?php echo meeha_icon( 'chev-r' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></button>
					</span>
				<?php endif; ?>
			</header>
			<div class="m-liveband__track" data-liveband-track role="list">
				<?php
				foreach ( $posts as $i => $p ) :
					$b     = meeha_lb_brief( $p, $lines );
					$cat   = meeha_primary_category( $p );
					$thumb = 1 === $n ? meeha_post_img( $p, 'card', array( 'sizes' => '(min-width: 1024px) 360px, (min-width: 600px) 40vw, 100vw' ) ) : '';
					?>
					<article class="m-livecard" role="listitem" data-livecard data-id="<?php echo (int) $p->ID; ?>" data-api="<?php echo esc_url( rest_url( 'meeha/v1/liveblog/' . $p->ID ) ); ?>" data-last="<?php echo (int) $b['last']; ?>">
						<?php if ( $thumb ) : ?>
							<a class="m-livecard__media m-media" href="<?php echo esc_url( $b['url'] ); ?>" tabindex="-1" aria-hidden="true"><?php echo $thumb; // phpcs:ignore WordPress.Security.EscapeOutput ?></a>
						<?php endif; ?>
						<div class="m-livecard__body">
							<p class="m-livecard__kicker">
								<?php echo meeha_lb_badge_html( 'live' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
								<?php if ( $cat ) : ?>
									<span><?php echo esc_html( $cat->name ); ?></span>
								<?php endif; ?>
							</p>
							<h3 class="m-livecard__title"><a href="<?php echo esc_url( $b['url'] ); ?>"><?php echo esc_html( $b['title'] ); ?></a></h3>
							<p class="m-livecard__meta">
								<?php if ( $b['last'] ) : ?>
									<span>সর্বশেষ আপডেট <b data-ago="<?php echo (int) $b['last']; ?>"><?php echo esc_html( meeha_bengali_time_ago( $b['last'] + $off ) ); ?></b></span>
								<?php endif; ?>
								<span data-livecard-count>মোট <?php echo esc_html( meeha_bn_digits( $b['count'] ) ); ?>টি আপডেট</span>
							</p>
							<ol class="m-livecard__updates" data-livecard-list<?php echo $b['items'] ? '' : ' hidden'; ?>>
								<?php foreach ( $b['items'] as $it ) : ?>
									<li class="m-livecard__u m-livecard__u--<?php echo esc_attr( $it['kind'] ); ?>">
										<a href="<?php echo esc_url( $b['url'] . '#lb-' . $it['id'] ); ?>">
											<time datetime="<?php echo esc_attr( gmdate( 'c', $it['t'] ) ); ?>"><?php echo esc_html( $it['clock'] ); ?></time>
											<?php if ( in_array( $it['kind'], array( 'breaking', 'key' ), true ) ) : ?>
												<span class="m-livecard__tag"><?php echo esc_html( $kinds[ $it['kind'] ][0] ); ?></span>
											<?php endif; ?>
											<span class="m-livecard__txt"><?php echo esc_html( $it['text'] ); ?></span>
										</a>
									</li>
								<?php endforeach; ?>
							</ol>
							<a class="m-livecard__go" href="<?php echo esc_url( $b['url'] . '#live' ); ?>">সব আপডেট দেখুন<?php echo meeha_icon( 'chev-r', array( 'size' => 'sm' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></a>
						</div>
					</article>
				<?php endforeach; ?>
			</div>
		</div>
	</div>
	<?php
	return trim( (string) ob_get_clean() );
}

/* The band sits in front of the first homepage section (S1 lead). */
add_action(
	'meeha_home_before_sections',
	function () {
		$html = meeha_lb_home_band_html();
		if ( '' !== $html ) {
			meeha_enqueue_bundle( 'liveband' );
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
		}
	}
);

/* Bundle in <head> on the homepage while something runs (so its CSS never flashes in late). */
add_filter(
	'meeha_home_extra_bundles',
	function ( $b ) {
		if ( meeha_lb_band_on() && meeha_lb_running( 1 ) ) {
			$b[] = 'liveband';
		}
		return $b;
	}
);

/**
 * Header pill: [url, label, count] or null.
 *
 * @return array|null
 */
function meeha_lb_pill() {
	if ( ! meeha_opt( 'home', 'live_pill', 1 ) ) {
		return null;
	}
	$run = meeha_lb_running( 6 );
	if ( ! $run ) {
		return null;
	}
	if ( 1 === count( $run ) ) {
		return array(
			'url'   => get_permalink( $run[0] ),
			'title' => 'এখন লাইভ: ' . get_the_title( $run[0] ),
			'count' => 1,
		);
	}
	return array(
		'url'   => ( meeha_lb_band_on() ? home_url( '/#live-now' ) : get_permalink( $run[0] ) ),
		'title' => 'এখন ' . meeha_bn_digits( count( $run ) ) . 'টি লাইভ চলছে',
		'count' => count( $run ),
	);
}

/**
 * Print the header pill (as the first item of the section nav list).
 */
function meeha_lb_nav_pill() {
	$p = meeha_lb_pill();
	if ( ! $p ) {
		return;
	}
	$here = is_singular() && 1 === $p['count'] && get_permalink( get_queried_object_id() ) === $p['url'];
	echo '<li class="m-nav__liveitem"><a class="m-nav__live" href="' . esc_url( $p['url'] ) . '" title="' . esc_attr( $p['title'] ) . '"' . ( $here ? ' aria-current="page"' : '' ) . '><span class="m-nav__livedot" aria-hidden="true"></span>লাইভ'
		. ( $p['count'] > 1 ? '<span class="m-nav__livecount">' . esc_html( meeha_bn_digits( $p['count'] ) ) . '</span>' : '' )
		. '<span class="screen-reader-text">: ' . esc_html( $p['title'] ) . '</span></a></li>';
}
add_action( 'meeha_nav_list_start', 'meeha_lb_nav_pill' );
