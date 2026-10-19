<?php
/**
 * Homepage section renderers S1–S12 (markup = r11/design/proto/home.html) and the registry
 * `meeha_home_sections()` (filter `meeha_home_sections` to add/replace renderers).
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

/**
 * Section registry: id => [label, render callable]. Renderers receive ( array $plan, string $title, string $id ).
 *
 * @return array
 */
function meeha_home_sections() {
	$def = array();
	foreach ( meeha_home_default_sections() as $row ) {
		$def[ $row['id'] ] = array(
			'label'  => $row['label'],
			'render' => 'meeha_home_render_' . $row['id'],
		);
	}
	return (array) apply_filters( 'meeha_home_sections', $def );
}

/**
 * Plan + ranked lists, with every post/term/meta/thumbnail/author cache primed in a few batched queries.
 * Called before get_header() (so the lead image preload costs no extra queries) and memoised.
 *
 * @return array
 */
function meeha_home_prepare() {
	static $plan = null;
	if ( null !== $plan ) {
		return $plan;
	}
	$plan    = meeha_home_plan();
	$enabled = meeha_home_enabled_sections();
	$ids     = meeha_home_plan_ids( $plan );
	$lists   = array();
	if ( isset( $enabled['s2'] ) && function_exists( 'meeha_get_trending' ) ) {
		$lists['trend'] = meeha_get_trending( max( 3, min( 8, (int) meeha_opt( 'home', 'trend_count', 5 ) ) ) );
		$ids            = array_merge( $ids, wp_list_pluck( wp_list_pluck( $lists['trend'], 'post' ), 'ID' ) );
	}
	if ( isset( $enabled['s10'] ) && function_exists( 'meeha_get_popular' ) ) {
		$lists['pop'] = meeha_get_popular( max( 4, (int) meeha_opt( 'home', 'pop_count', 4 ) ), max( 1, (int) meeha_opt( 'home', 'pop_days', 30 ) ) );
		$ids          = array_merge( $ids, wp_list_pluck( wp_list_pluck( $lists['pop'], 'post' ), 'ID' ) );
	}
	if ( isset( $enabled['s7'] ) && function_exists( 'meeha_metrics_most_read_pool' ) ) {
		$ids = array_merge( $ids, array_slice( array_keys( meeha_metrics_most_read_pool( (string) meeha_opt( 'home', 'mostread_home', 'today' ) ) ), 0, 10 ) );
	}
	$plan['_lists'] = $lists;
	meeha_home_all_cats(); // primes the term cache for every category (chips, kickers, links).
	meeha_home_prime( $ids );
	return $plan;
}

/**
 * Render the whole homepage body (inside <main>).
 */
function meeha_home_render() {
	$plan     = meeha_home_prepare();
	$sections = meeha_home_sections();
	foreach ( meeha_home_enabled_sections() as $sid => $title ) {
		if ( empty( $sections[ $sid ]['render'] ) || ! is_callable( $sections[ $sid ]['render'] ) ) {
			continue;
		}
		call_user_func( $sections[ $sid ]['render'], $plan, $title, $sid );
	}
}

/**
 * Ad band wrapper ('' when the slot is empty — the server never prints an empty box).
 *
 * @param string $slot  Slot.
 * @param string $class Extra classes.
 */
function meeha_home_ad( $slot, $class = '' ) {
	$ad = function_exists( 'meeha_ad' ) ? meeha_ad( $slot ) : '';
	if ( $ad ) {
		echo '<div class="m-container m-adband' . ( $class ? ' ' . esc_attr( $class ) : '' ) . '">' . $ad . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by meeha_ad().
	}
}

/**
 * Bengali number word for small counts (একনজরে lede).
 *
 * @param int $n Number.
 * @return string
 */
function meeha_home_bn_word( $n ) {
	$w = array( 1 => 'এক', 'দুই', 'তিন', 'চার', 'পাঁচ', 'ছয়', 'সাত', 'আট', 'নয়', 'দশ', 'এগারো', 'বারো' );
	return isset( $w[ $n ] ) ? $w[ $n ] : meeha_bn_digits( $n );
}

/**
 * Sparkline (8 values) — viewBox 0 0 56 36 like the prototype.
 *
 * @param int[] $vals Values.
 * @return string
 */
function meeha_spark_svg( $vals ) {
	$vals = array_map( 'intval', array_values( (array) $vals ) );
	$n    = count( $vals ) - 1;
	if ( $n < 1 ) {
		return '';
	}
	$mx  = max( 1, max( $vals ) );
	$pts = array();
	foreach ( $vals as $i => $v ) {
		$pts[] = sprintf( '%.1f,%.1f', 2 + $i * 52 / $n, 33 - ( $v / $mx ) * 29 );
	}
	return '<svg class="m-trend__spark" viewBox="0 0 56 36" aria-hidden="true" focusable="false"><path class="m-trend__area" d="M2,34 L' . esc_attr( implode( ' L', $pts ) ) . ' L54,34Z"/><polyline points="' . esc_attr( implode( ' ', $pts ) ) . '"/></svg>';
}

/**
 * Category chips [[name, url]] for a list of category ids (non-empty ones).
 *
 * @param int[] $ids Ids.
 * @param int   $max Max.
 * @return array
 */
function meeha_home_chips( $ids, $max = 4 ) {
	$out = array();
	foreach ( (array) $ids as $id ) {
		$t = get_term( (int) $id, 'category' );
		if ( $t && ! is_wp_error( $t ) ) {
			$out[] = array( $t->name, get_category_link( $t ) );
		}
		if ( count( $out ) >= $max ) {
			break;
		}
	}
	return $out;
}

/**
 * Child categories (with posts) of a category, as ids.
 *
 * @param int $parent Parent id.
 * @return int[]
 */
function meeha_home_children( $parent ) {
	$parent = (int) $parent;
	if ( ! $parent ) {
		return array();
	}
	$kids = array();
	foreach ( meeha_home_all_cats() as $t ) {
		if ( (int) $t->parent === $parent && (int) $t->count > 0 ) {
			$kids[ (int) $t->term_id ] = (int) $t->count;
		}
	}
	arsort( $kids );
	return array_keys( $kids );
}

/**
 * Story meta row: author · time · reading time (prototype order for lead/feature stories).
 *
 * @param WP_Post $p    Post.
 * @param bool    $read Include reading time.
 * @return string
 */
function meeha_home_meta( $p, $read = true ) {
	$out = '<div class="m-meta"><span>' . esc_html( meeha_post_byline( $p )['name'] ) . '</span>'; // 2.0.3: guest author aware.
	$out .= '<time datetime="' . esc_attr( get_post_time( 'c', true, $p ) ) . '">' . esc_html( meeha_post_time_ago( $p ) ) . '</time>';
	if ( $read ) {
		$out .= '<span>' . esc_html( meeha_reading_time_label( $p ) ) . '</span>';
	}
	return $out . '</div>';
}

/* ======================================================================
 * S1 · লিড প্যাকেজ
 * ==================================================================== */

/**
 * S1.
 *
 * @param array  $plan  Plan.
 * @param string $title Title (aria-label).
 */
function meeha_home_render_s1( $plan, $title ) {
	if ( empty( $plan['s1']['lead'] ) ) {
		return;
	}
	$s    = $plan['s1'];
	$lead = get_post( $s['lead'] );
	if ( ! $lead ) {
		return;
	}
	$url    = get_permalink( $lead );
	$img    = meeha_post_img( $lead, 'lead', array( 'alt' => wp_strip_all_tags( get_the_title( $lead ) ) ) );
	$inv    = meeha_is_investigative( $lead );
	$kicker = meeha_post_kicker( $lead );
	$cat    = meeha_primary_category( $lead );
	$dek    = meeha_post_dek( $lead, 40 );
	$latest_url = meeha_home_page_with( 'meeha_latest_feed' );
	?>
<section class="m-lead" aria-label="<?php echo esc_attr( $title ); ?>">
	<div class="m-container m-lead__grid">
		<div class="m-lead__main">
			<article class="m-story m-story--lead">
				<?php if ( $img ) : ?>
				<a class="m-story__media" href="<?php echo esc_url( $url ); ?>" tabindex="-1" aria-hidden="true"><div class="m-media m-viewfinder"><?php echo $img; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core image markup. ?></div></a>
				<?php endif; ?>
				<div class="m-story__body">
					<?php if ( '' !== $kicker ) : ?>
						<?php if ( $cat ) : ?>
							<a class="m-kicker m-story__kicker<?php echo $inv ? ' m-kicker--inv' : ''; ?>" href="<?php echo esc_url( get_category_link( $cat ) ); ?>"><?php echo esc_html( $kicker ); ?></a>
						<?php else : ?>
							<span class="m-kicker m-story__kicker<?php echo $inv ? ' m-kicker--inv' : ''; ?>"><?php echo esc_html( $kicker ); ?></span>
						<?php endif; ?>
					<?php endif; ?>
					<h2 class="m-story__title"><a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( get_the_title( $lead ) ); ?></a></h2>
					<?php if ( '' !== $dek ) : ?>
						<p class="m-story__dek"><?php echo esc_html( $dek ); ?></p>
					<?php endif; ?>
					<?php echo meeha_home_meta( $lead ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
				</div>
			</article>
		</div>
		<?php if ( $s['side'] ) : ?>
		<div class="m-lead__side">
			<?php
			foreach ( $s['side'] as $id ) {
				meeha_card( $id, array( 'variant' => 'sm rowm' ) );
			}
			?>
		</div>
		<?php endif; ?>
		<?php if ( $s['more'] ) : ?>
		<div class="m-lead__more">
			<?php
			foreach ( $s['more'] as $id ) {
				meeha_card( $id, array( 'variant' => 'row' ) );
			}
			?>
		</div>
		<?php endif; ?>
		<?php if ( $s['latest'] ) : ?>
		<aside class="m-lead__latest" aria-labelledby="latest-h">
			<?php
			meeha_sechead(
				(string) meeha_opt( 'home', 'latest_title', 'সর্বশেষ' ),
				array(
					'url'      => $latest_url,
					'modifier' => 'sm',
					'id'       => 'latest-h',
				)
			);
			?>
			<ol class="m-latest" role="list">
				<?php foreach ( $s['latest'] as $i => $id ) : ?>
					<?php $p = get_post( $id ); ?>
					<?php if ( $p ) : ?>
						<li<?php echo $i >= 5 ? ' class="m-hide-m"' : ''; ?>><time datetime="<?php echo esc_attr( get_post_time( 'c', true, $p ) ); ?>"><?php echo esc_html( meeha_post_clock( $p ) ); ?></time><a href="<?php echo esc_url( get_permalink( $p ) ); ?>"><?php echo esc_html( get_the_title( $p ) ); ?></a></li>
					<?php endif; ?>
				<?php endforeach; ?>
			</ol>
		</aside>
		<?php endif; ?>
	</div>
</section>
	<?php
}

/* ======================================================================
 * S2 · ট্রেন্ডিং
 * ==================================================================== */

/**
 * S2 (+ the home_leader ad that follows it).
 *
 * @param array  $plan  Plan.
 * @param string $title Title.
 */
function meeha_home_render_s2( $plan, $title ) {
	$items = isset( $plan['_lists']['trend'] ) ? $plan['_lists']['trend'] : array();
	if ( count( $items ) >= 3 ) {
		?>
<section class="m-trend" aria-labelledby="trend-h">
	<div class="m-container m-trend__inner">
		<h2 class="m-trend__label" id="trend-h"><?php echo meeha_icon( 'trend' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span><?php echo esc_html( $title ); ?><br><small>গত ২৪ ঘণ্টায় দ্রুত উঠছে</small></span></h2>
		<ol class="m-rail m-trend__rail" role="list">
			<?php foreach ( $items as $it ) : ?>
				<?php $p = $it['post']; ?>
				<li class="m-trend__item"><?php echo meeha_spark_svg( $it['spark'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><h3 class="m-trend__title"><a href="<?php echo esc_url( get_permalink( $p ) ); ?>"><?php echo esc_html( get_the_title( $p ) ); ?></a></h3><?php echo meeha_home_trend_rate( $it ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></li>
			<?php endforeach; ?>
		</ol>
	</div>
</section>
		<?php
	}
	meeha_home_ad( 'home_leader' );
}

/**
 * Rate line of a trending item.
 *
 * @param array $it Item.
 * @return string
 */
function meeha_home_trend_rate( $it ) {
	if ( ! empty( $it['pinned'] ) ) {
		return '<span class="m-trend__rate m-trend__pin">' . meeha_icon( 'pin', array( 'size' => 'sm' ) ) . ' সম্পাদকের বাছাই</span>';
	}
	if ( null !== $it['velocity'] && $it['velocity'] >= 2 ) {
		return '<span class="m-trend__rate"><b>' . esc_html( meeha_bn_digits( (int) floor( $it['velocity'] ) ) ) . ' গুণ</b> বেশি পড়া হচ্ছে</span>';
	}
	if ( ! empty( $it['views'] ) ) {
		return '<span class="m-trend__rate">৬ ঘণ্টায় <b>' . esc_html( meeha_bn_number_short( (int) $it['views'] ) ) . '</b> বার পড়া</span>';
	}
	return '<span class="m-trend__rate">বেশি পড়া হচ্ছে</span>';
}

/* ======================================================================
 * S3 · একনজরে
 * ==================================================================== */

/**
 * S3.
 *
 * @param array  $plan  Plan.
 * @param string $title Title.
 */
function meeha_home_render_s3( $plan, $title ) {
	$ids = isset( $plan['s3']['ids'] ) ? $plan['s3']['ids'] : array();
	$ids = array_values( array_filter( $ids, 'get_post' ) );
	if ( ! $ids ) {
		return;
	}
	$mid   = function_exists( 'meeha_metrics_local_midnight' ) ? meeha_metrics_local_midnight() : strtotime( 'today' );
	$older = false;
	foreach ( $ids as $id ) {
		if ( (int) get_post_time( 'U', true, $id ) < $mid ) {
			$older = true;
		}
	}
	$d    = meeha_dates();
	$date = $d['weekday'] . ', ' . meeha_bn_digits( wp_date( 'j' ) ) . ' ' . meeha_bengali_month( (int) wp_date( 'n' ) );
	$lede = ( $older ? 'গত ২৪ ঘণ্টায়' : 'আজ সকাল থেকে এ পর্যন্ত' ) . ' যা জানা জরুরি, ' . meeha_home_bn_word( count( $ids ) ) . ' লাইনে। প্রতিটি লাইনে চাপ দিলে পুরো খবর।';
	$more = (string) meeha_opt( 'home', 'glance_url', '' );
	$more = $more ? $more : meeha_home_page_with( 'meeha_at_a_glance' );
	?>
<section class="m-section m-digest" id="digest" aria-labelledby="digest-h">
	<div class="m-container m-digest__grid">
		<div class="m-digest__intro">
			<h2 class="m-digest__title" id="digest-h"><?php echo esc_html( $title ); ?></h2>
			<p class="m-digest__date"><?php echo esc_html( $date ); ?></p>
			<p class="m-digest__lede"><?php echo esc_html( $lede ); ?></p>
			<?php if ( $more ) : ?>
				<a class="m-btn m-btn--ghost" href="<?php echo esc_url( $more ); ?>">দিনের সব খবর</a>
			<?php endif; ?>
		</div>
		<ol class="m-digest__list" role="list">
			<?php foreach ( $ids as $i => $id ) : ?>
				<?php
				$p    = get_post( $id );
				$text = function_exists( 'meeha_glance_text' ) ? meeha_glance_text( $p ) : get_the_title( $p );
				$cat  = meeha_primary_category( $p );
				?>
				<li class="m-digest__item<?php echo $i >= 6 ? ' m-hide-m' : ''; ?>"><time datetime="<?php echo esc_attr( get_post_time( 'c', true, $p ) ); ?>"><?php echo esc_html( meeha_post_clock( $p ) ); ?></time><p class="m-digest__text"><a href="<?php echo esc_url( get_permalink( $p ) ); ?>"><?php echo esc_html( $text ); ?></a><?php if ( $cat ) : ?><span class="m-digest__cat"><?php echo esc_html( $cat->name ); ?></span><?php endif; ?></p></li>
			<?php endforeach; ?>
		</ol>
	</div>
</section>
	<?php
}

/* ======================================================================
 * S4 · অনুসন্ধান (night band)
 * ==================================================================== */

/**
 * S4.
 *
 * @param array  $plan  Plan.
 * @param string $title Title.
 */
function meeha_home_render_s4( $plan, $title ) {
	$ids = isset( $plan['s4']['ids'] ) ? array_values( array_filter( $plan['s4']['ids'], 'get_post' ) ) : array();
	if ( ! $ids ) {
		return;
	}
	$cats = meeha_home_cats( 'inv_cats' );
	$url  = $cats ? get_category_link( $cats[0] ) : '';
	?>
<section class="m-section m-night" aria-labelledby="inv-h">
	<div class="m-container">
		<?php
		meeha_sechead(
			$title,
			array(
				'url'        => $url,
				'more_label' => 'সব অনুসন্ধান',
				'sub'        => (string) meeha_opt( 'home', 'inv_note', '' ),
				'id'         => 'inv-h',
			)
		);
		?>
		<div class="m-night__grid<?php echo 1 === count( $ids ) ? ' m-night__grid--solo' : ''; ?>">
			<?php
			meeha_card(
				$ids[0],
				array(
					'variant'    => 'big',
					'show_dek'   => true,
					'show_meta'  => 'author',
					'viewfinder' => true,
				)
			);
			if ( count( $ids ) > 1 ) {
				echo '<div class="m-night__side">';
				foreach ( array_slice( $ids, 1, 3 ) as $id ) {
					meeha_card( $id, array( 'variant' => 'side' ) );
				}
				echo '</div>';
			}
			?>
		</div>
	</div>
</section>
	<?php
}

/* ======================================================================
 * S5 · মাতৃভূমি + এলাকার খবর + MPU
 * ==================================================================== */

/**
 * S5.
 *
 * @param array  $plan  Plan.
 * @param string $title Title.
 */
function meeha_home_render_s5( $plan, $title ) {
	$ids = isset( $plan['s5']['ids'] ) ? array_values( array_filter( $plan['s5']['ids'], 'get_post' ) ) : array();
	if ( count( $ids ) < 2 ) {
		return;
	}
	$cats   = meeha_home_cats( 'mb_cats' );
	$root   = $cats ? $cats[0] : 0;
	$layout = ( 'list' === meeha_opt( 'home', 'mb_layout', 'mosaic' ) || count( $ids ) < 5 ) ? 'list' : 'mosaic';
	$area   = '';
	if ( meeha_opt( 'home', 'mb_area', 1 ) && function_exists( 'meeha_render_area_widget' ) ) {
		$area = meeha_render_area_widget(
			array(
				'count'   => 3,
				'heading' => 'h3',
				'id'      => 'm-area-home',
				'echo'    => false,
			)
		);
	}
	$ad = function_exists( 'meeha_ad' ) ? meeha_ad( 'home_mpu_1' ) : '';
	?>
<section class="m-section" aria-labelledby="mb-h">
	<div class="m-container">
		<?php
		meeha_sechead(
			$title,
			array(
				'url'   => $root ? get_category_link( $root ) : '',
				'chips' => meeha_home_chips( meeha_home_children( $root ), 4 ),
				'id'    => 'mb-h',
			)
		);
		?>
		<div class="<?php echo ( $area || $ad ) ? 'm-split' : 'm-home-solo'; ?>">
			<div class="m-mosaic<?php echo 'list' === $layout ? ' m-mosaic--list' : ''; ?>">
				<?php
				meeha_card(
					$ids[0],
					array(
						'variant'   => 'big',
						'show_dek'  => true,
						'show_meta' => 'author',
					)
				);
				echo '<div class="m-mosaic__grid">';
				foreach ( array_slice( $ids, 1 ) as $id ) {
					meeha_card(
						$id,
						array(
							'variant'   => 'list' === $layout ? 'row' : 'sm rowm',
							'show_meta' => 'author',
						)
					);
				}
				echo '</div>';
				?>
			</div>
			<?php if ( $area || $ad ) : ?>
			<aside class="m-split__aside" aria-label="এলাকার খবর ও বিজ্ঞাপন">
				<?php
				echo $area; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built and escaped by B.
				echo $ad; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by meeha_ad().
				?>
			</aside>
			<?php endif; ?>
		</div>
	</div>
</section>
	<?php
}

/* ======================================================================
 * S6 · ভিডিও (own rail — the plugin's carousel assets are NOT loaded on the home page)
 * ==================================================================== */

/**
 * YouTube id from a URL.
 *
 * @param string $url URL.
 * @return string
 */
function meeha_home_youtube_id( $url ) {
	if ( preg_match( '~(?:youtu\.be/|youtube(?:-nocookie)?\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/|v/))([A-Za-z0-9_-]{11})~', (string) $url, $m ) ) {
		return $m[1];
	}
	return '';
}

/**
 * Duration label m:ss in Bengali digits ('' when unknown).
 *
 * @param mixed $sec Seconds.
 * @return string
 */
function meeha_home_duration( $sec ) {
	$sec = (int) $sec;
	if ( $sec <= 0 ) {
		return '';
	}
	$h = (int) floor( $sec / 3600 );
	$m = (int) floor( ( $sec % 3600 ) / 60 );
	$s = $sec % 60;
	return meeha_bn_digits( $h ? sprintf( '%d:%02d:%02d', $h, $m, $s ) : sprintf( '%d:%02d', $m, $s ) );
}

/**
 * S6.
 *
 * @param array  $plan  Plan.
 * @param string $title Title.
 */
function meeha_home_render_s6( $plan, $title ) {
	$ids = isset( $plan['s6']['ids'] ) ? array_values( array_filter( $plan['s6']['ids'], 'get_post' ) ) : array();
	if ( count( $ids ) < 3 ) {
		return;
	}
	$all = (string) meeha_opt( 'home', 'video_url', '' );
	$all = $all ? $all : meeha_home_page_with( 'meeha_video_reels' );
	$nav = '<div class="m-railnav"><button type="button" aria-label="আগের ভিডিও">' . meeha_icon( 'chev-l' ) . '</button><button type="button" aria-label="পরের ভিডিও">' . meeha_icon( 'chev-r' ) . '</button></div>';
	?>
<section class="m-section m-section--band" id="video" aria-labelledby="video-h" data-rail>
	<div class="m-container">
		<?php
		meeha_sechead(
			$title,
			array(
				'url'        => $all,
				'more_label' => 'সব ভিডিও',
				'id'         => 'video-h',
				'after'      => $nav,
			)
		);
		?>
		<ul class="m-rail m-video-rail" role="list">
			<?php foreach ( $ids as $id ) : ?>
				<?php
				$p     = get_post( $id );
				$thumb = (int) get_post_thumbnail_id( $p );
				$img   = $thumb ? meeha_img( $thumb, 'portrait' ) : '';
				if ( ! $img ) {
					$yt = meeha_home_youtube_id( (string) get_post_meta( $id, '_meeha_video_url', true ) );
					if ( $yt ) {
						$img = '<img src="' . esc_url( 'https://i.ytimg.com/vi/' . $yt . '/hqdefault.jpg' ) . '" width="480" height="360" alt="" loading="lazy" decoding="async">';
					}
				}
				$dur = meeha_home_duration( get_post_meta( $id, '_meeha_video_duration', true ) );
				?>
				<li class="m-video"><div class="m-media m-media--9x16"><?php echo $img; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped image markup. ?><span class="m-video__play"><?php echo meeha_icon( 'play' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php echo $dur ? esc_html( $dur ) : '<span class="m-vh">ভিডিও</span>'; ?></span></div><h3 class="m-video__title"><a href="<?php echo esc_url( get_permalink( $p ) ); ?>"><?php echo esc_html( get_the_title( $p ) ); ?></a></h3></li>
			<?php endforeach; ?>
		</ul>
	</div>
</section>
	<?php
}

/* ======================================================================
 * S7 · বিশ্ব + সর্বাধিক পঠিত
 * ==================================================================== */

/**
 * The tabbed most-read box (default tab server-rendered, others fetched from REST on demand).
 *
 * @param array $args title, count, default (window), id prefix, category (int).
 * @return string
 */
function meeha_mostread_box( $args = array() ) {
	$args    = wp_parse_args(
		(array) $args,
		array(
			'title'    => 'সর্বাধিক পঠিত',
			'count'    => 5,
			'default'  => 'today',
			'id'       => 'mr',
			'category' => 0,
			'class'    => '',
		)
	);
	$windows = array( 'today', '7d', '30d' );
	$count   = max( 3, min( 10, (int) $args['count'] ) );
	$q       = $args['category'] ? array( 'category' => (int) $args['category'] ) : array();
	$sel     = in_array( $args['default'], $windows, true ) ? $args['default'] : 'today';
	$posts   = meeha_get_most_read( $sel, $count, $q );
	if ( ! $posts ) {
		// Never show an empty default tab: move to the next window that has stories.
		foreach ( $windows as $w ) {
			if ( $w === $sel ) {
				continue;
			}
			$try = meeha_get_most_read( $w, $count, $q );
			if ( $try ) {
				$sel   = $w;
				$posts = $try;
				break;
			}
		}
	}
	if ( ! $posts ) {
		return '';
	}
	$id  = sanitize_html_class( $args['id'] );
	$out = '<aside class="m-mostread' . ( $args['class'] ? ' ' . esc_attr( $args['class'] ) : '' ) . '" aria-labelledby="' . $id . '-h" data-tabs data-mostread>';
	$out .= '<div class="m-sechead"><h2 class="m-sechead__title" id="' . $id . '-h">' . esc_html( $args['title'] ) . '</h2></div>';
	$out .= '<div class="m-tabs__list" role="tablist" aria-label="সময়সীমা">';
	foreach ( $windows as $i => $w ) {
		$n    = $i + 1;
		$out .= '<button class="m-tabs__tab" type="button" role="tab" aria-selected="' . ( $w === $sel ? 'true' : 'false' ) . '" aria-controls="' . $id . '-' . $n . '" id="' . $id . '-t' . $n . '"' . ( $w === $sel ? '' : ' tabindex="-1"' ) . '>' . esc_html( meeha_metrics_window_label( $w ) ) . '</button>';
	}
	$out .= '</div>';
	foreach ( $windows as $i => $w ) {
		$n    = $i + 1;
		$src  = add_query_arg(
			array_filter(
				array(
					'window' => $w,
					'count'  => $count,
					'cat'    => (int) $args['category'],
				)
			),
			rest_url( 'meeha/v1/most-read' )
		);
		$out .= '<div class="m-tabs__panel" role="tabpanel" id="' . $id . '-' . $n . '" aria-labelledby="' . $id . '-t' . $n . '"' . ( $w === $sel ? '' : ' hidden data-src="' . esc_url( $src ) . '"' ) . '>';
		$out .= $w === $sel ? meeha_rank_list_html( $posts ) : '<p class="m-rank__empty">লোড হচ্ছে…</p>';
		$out .= '</div>';
	}
	return $out . '</aside>';
}

/**
 * S7.
 *
 * @param array  $plan  Plan.
 * @param string $title Title.
 */
function meeha_home_render_s7( $plan, $title ) {
	$ids  = isset( $plan['s7']['ids'] ) ? array_values( array_filter( $plan['s7']['ids'], 'get_post' ) ) : array();
	$o    = meeha_opt( 'home' );
	$box  = function_exists( 'meeha_get_most_read' ) ? meeha_mostread_box(
		array(
			'title'   => (string) $o['mostread_title'],
			'count'   => (int) $o['mostread_count'],
			'default' => (string) $o['mostread_home'],
		)
	) : '';
	$show = count( $ids ) >= 2;
	if ( ! $show && '' === $box ) {
		return;
	}
	$cats = meeha_home_cats( 'world_cats' );
	?>
<section class="m-section" aria-labelledby="<?php echo $show ? 'world-h' : 'mr-h'; ?>">
	<div class="m-container<?php echo $show && $box ? ' m-split m-split--wide' : ''; ?>">
		<?php if ( $show ) : ?>
		<div>
			<?php
			meeha_sechead(
				$title,
				array(
					'url'   => $cats ? get_category_link( $cats[0] ) : '',
					'chips' => count( $cats ) > 1 ? meeha_home_chips( array_reverse( $cats ), 4 ) : meeha_home_chips( meeha_home_children( $cats ? $cats[0] : 0 ), 4 ),
					'id'    => 'world-h',
				)
			);
			if ( 'list' === $o['world_layout'] ) {
				echo '<div class="m-world-list">';
				foreach ( $ids as $id ) {
					meeha_card( $id, array( 'variant' => 'row' ) );
				}
				echo '</div>';
			} else {
				meeha_card(
					$ids[0],
					array(
						'variant'   => 'side-lg',
						'show_dek'  => true,
						'show_meta' => 'author',
					)
				);
				if ( count( $ids ) > 1 ) {
					echo '<div class="m-grid m-grid--3 m-world-row">';
					foreach ( array_slice( $ids, 1, 3 ) as $id ) {
						meeha_card( $id, array( 'variant' => 'sm rowm' ) );
					}
					echo '</div>';
				}
			}
			?>
		</div>
		<?php endif; ?>
		<?php echo $box; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
	</div>
</section>
	<?php
}

/* ======================================================================
 * S8 · মতামত (+ home_mpu_2, mobile only)
 * ==================================================================== */

/**
 * Columnist role line: designation, else the post's category.
 *
 * @param WP_Post $p Post.
 * @return string
 */
function meeha_home_columnist_role( $p ) {
	$r = meeha_post_byline( $p )['title']; // 2.0.3: the guest's পদবি for a guest byline.
	if ( '' === $r ) {
		$c = meeha_primary_category( $p );
		$r = $c ? $c->name : '';
	}
	return $r;
}

/**
 * S8.
 *
 * @param array  $plan  Plan.
 * @param string $title Title.
 */
function meeha_home_render_s8( $plan, $title ) {
	$ed   = ! empty( $plan['s8']['editorial'] ) ? get_post( $plan['s8']['editorial'] ) : null;
	$cols = isset( $plan['s8']['columnists'] ) ? array_values( array_filter( array_map( 'get_post', $plan['s8']['columnists'] ) ) ) : array();
	if ( ! $ed && ! $cols ) {
		meeha_home_ad( 'home_mpu_2', 'm-show-m' );
		return;
	}
	$edc   = meeha_home_cats( 'op_editorial_cats' );
	$colc  = meeha_home_cats( 'op_columnist_cats' );
	$chips = meeha_home_chips( array_merge( $edc, $colc ), 4 );
	$url   = $edc ? get_category_link( $edc[0] ) : ( $colc ? get_category_link( $colc[0] ) : '' );
	?>
<section class="m-section m-section--band" aria-labelledby="op-h">
	<div class="m-container">
		<?php
		meeha_sechead(
			$title,
			array(
				'url'        => $url,
				'more_label' => 'সব মতামত',
				'chips'      => $chips,
				'id'         => 'op-h',
			)
		);
		?>
		<div class="m-opinion<?php echo $ed ? '' : ' m-opinion--noed'; ?>">
			<?php if ( $ed ) : ?>
			<article class="m-editorial m-story">
				<p class="m-editorial__label">সম্পাদকীয়</p>
				<h3 class="m-story__title"><a href="<?php echo esc_url( get_permalink( $ed ) ); ?>"><?php echo esc_html( get_the_title( $ed ) ); ?></a></h3>
				<?php $dek = meeha_post_dek( $ed, 28 ); ?>
				<?php if ( '' !== $dek ) : ?>
					<p><?php echo esc_html( $dek ); ?></p>
				<?php endif; ?>
			</article>
			<?php endif; ?>
			<?php if ( $cols ) : ?>
			<ul class="m-rail m-opinion__rail" role="list">
				<?php foreach ( $cols as $p ) : ?>
					<?php $by = meeha_post_byline( $p, 96 ); ?>
					<li class="m-columnist"><span class="m-columnist__photo"><?php echo meeha_byline_photo_html( $by, 96 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span><span class="m-columnist__name"><?php echo esc_html( $by['name'] ); ?></span><span class="m-columnist__role"><?php echo esc_html( meeha_home_columnist_role( $p ) ); ?></span><h3 class="m-columnist__title"><a href="<?php echo esc_url( get_permalink( $p ) ); ?>"><?php echo esc_html( get_the_title( $p ) ); ?></a></h3></li>
				<?php endforeach; ?>
			</ul>
			<?php endif; ?>
		</div>
	</div>
</section>
	<?php
	meeha_home_ad( 'home_mpu_2', 'm-show-m' );
}

/* ======================================================================
 * S9 · অর্থব্যবস্থা · খেলা · বিনোদন
 * ==================================================================== */

/**
 * S9.
 *
 * @param array  $plan  Plan.
 * @param string $title Title (aria-label fallback).
 */
function meeha_home_render_s9( $plan, $title ) {
	$cols = isset( $plan['s9']['cols'] ) ? $plan['s9']['cols'] : array();
	$cols = array_values(
		array_filter(
			$cols,
			function ( $c ) {
				return ! empty( $c['ids'] ) && get_term( (int) $c['cat'], 'category' ) instanceof WP_Term;
			}
		)
	);
	if ( ! $cols ) {
		return;
	}
	$names = array();
	foreach ( $cols as $c ) {
		$names[] = get_term( (int) $c['cat'], 'category' )->name;
	}
	$label = count( $names ) > 1 ? implode( ', ', array_slice( $names, 0, -1 ) ) . ' ও ' . end( $names ) : $names[0];
	?>
<section class="m-section" aria-label="<?php echo esc_attr( $label ); ?>">
	<div class="m-container m-grid m-grid--<?php echo (int) max( 2, count( $cols ) ); ?> m-trio">
		<?php foreach ( $cols as $c ) : ?>
			<?php $term = get_term( (int) $c['cat'], 'category' ); ?>
			<div class="m-catblock">
				<?php
				meeha_sechead(
					$term->name,
					array(
						'url'        => get_category_link( $term ),
						'more_label' => 'আরও',
						'modifier'   => 'sm',
					)
				);
				meeha_card(
					$c['ids'][0],
					array(
						'variant'       => 'sm',
						'show_kicker'   => false,
						'heading_level' => 3,
					)
				);
				$rest = array_slice( $c['ids'], 1 );
				if ( $rest ) {
					echo '<ul class="m-list" role="list">';
					foreach ( $rest as $id ) {
						printf( '<li><h3 class="m-list__title"><a href="%s">%s</a></h3></li>', esc_url( get_permalink( $id ) ), esc_html( get_the_title( $id ) ) );
					}
					echo '</ul>';
				}
				?>
			</div>
		<?php endforeach; ?>
	</div>
</section>
	<?php
}

/* ======================================================================
 * S10 · জনপ্রিয়
 * ==================================================================== */

/**
 * S10.
 *
 * @param array  $plan  Plan.
 * @param string $title Title.
 */
function meeha_home_render_s10( $plan, $title ) {
	$items = isset( $plan['_lists']['pop'] ) ? $plan['_lists']['pop'] : array();
	$items = array_values(
		array_filter(
			$items,
			function ( $i ) {
				return $i['score'] > 0;
			}
		)
	);
	if ( count( $items ) < 4 ) {
		return;
	}
	$top  = max( 1, (int) $items[0]['score'] );
	$days = max( 1, (int) meeha_opt( 'home', 'pop_days', 30 ) );
	?>
<section class="m-section m-section--band" aria-labelledby="pop-h">
	<div class="m-container">
		<?php
		meeha_sechead(
			$title,
			array(
				'sub' => 'গত ' . meeha_bn_digits( $days ) . ' দিনে পাঠকের প্রতিক্রিয়া, শেয়ার আর মন্তব্যে এগিয়ে',
				'id'  => 'pop-h',
			)
		);
		?>
		<ol class="m-grid m-grid--4 m-poplist" role="list">
			<?php foreach ( $items as $it ) : ?>
				<?php
				$p   = $it['post'];
				$img = meeha_post_img( $p, 'card' );
				$pct = max( 4, min( 100, (int) round( 100 * $it['score'] / $top ) ) );
				?>
				<li class="m-pop"><div class="m-media m-media--3x2"><?php echo $img; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core image markup. ?></div><h3 class="m-story__title"><a href="<?php echo esc_url( get_permalink( $p ) ); ?>"><?php echo esc_html( get_the_title( $p ) ); ?></a></h3><div class="m-pop__meter" aria-hidden="true"><i style="width:<?php echo (int) $pct; ?>%"></i></div><p class="m-pop__signals"><span><?php echo meeha_icon( 'heart' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span class="m-vh">প্রতিক্রিয়া</span><?php echo esc_html( meeha_bn_number_short( $it['reactions'] ) ); ?></span><span><?php echo meeha_icon( 'share' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span class="m-vh">শেয়ার</span><?php echo esc_html( meeha_bn_number_short( $it['shares'] ) ); ?></span><span><?php echo meeha_icon( 'comment' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span class="m-vh">মন্তব্য</span><?php echo esc_html( meeha_bn_number_short( $it['comments'] ) ); ?></span></p></li>
			<?php endforeach; ?>
		</ol>
	</div>
</section>
	<?php
}

/* ======================================================================
 * S11 · সাহিত্য teaser, S12 · উক্তি — rendered by D's modules when present
 * ==================================================================== */

/**
 * Call a D renderer that may echo or return its HTML.
 *
 * @param string $fn   Function.
 * @param array  $args Args.
 */
function meeha_home_call_external( $fn, $args ) {
	if ( ! function_exists( $fn ) ) {
		return;
	}
	ob_start();
	$ret = call_user_func( $fn, $args );
	$out = (string) ob_get_clean();
	if ( '' === trim( $out ) && is_string( $ret ) ) {
		$out = $ret;
	}
	echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- D's renderer escapes its own markup.
}

/**
 * S11 — D's `meeha_render_home_literature( $args )`.
 *
 * @param array  $plan  Plan.
 * @param string $title Title.
 */
function meeha_home_render_s11( $plan, $title ) {
	meeha_home_call_external(
		'meeha_render_home_literature',
		array(
			'title'      => $title,
			'count'      => max( 1, (int) meeha_opt( 'home', 'lit_count', 4 ) - 1 ), // D: 1 verse card + `count` list items.
			'cat'        => (int) current( meeha_home_cats( 'lit_cats' ) ),
			'heading_id' => 'lit-h',
			'echo'       => false,
		)
	);
}

/**
 * S12 — D's `meeha_render_home_quotes( $args )`.
 *
 * @param array  $plan  Plan.
 * @param string $title Title.
 */
function meeha_home_render_s12( $plan, $title ) {
	meeha_home_call_external(
		'meeha_render_home_quotes',
		array(
			'title'        => $title,
			'count'        => (int) meeha_opt( 'home', 'quotes_count', 8 ),
			'max_per_type' => 2,
			'heading_id'   => 'q-h',
			'echo'         => false,
		)
	);
}

/* ======================================================================
 * Assets on the home page
 * ==================================================================== */

/**
 * Pre-enqueue D's quotes bundle (S12) in <head> so the rail never flashes unstyled.
 */
add_filter(
	'meeha_home_extra_bundles',
	function ( $b ) {
		$on = meeha_home_enabled_sections();
		$m  = function_exists( 'meeha_manifest' ) ? meeha_manifest() : array();
		if ( isset( $on['s12'] ) && function_exists( 'meeha_render_home_quotes' ) ) {
			foreach ( array( 'quote-cards', 'quotes' ) as $name ) {
				if ( ! empty( $m['bundles'][ $name ] ) ) {
					$b[] = $name;
					break;
				}
			}
		}
		return $b;
	}
);

/**
 * The plugin enqueues its carousel CSS/JS on every front page (for the old theme's [meeha_video_carousel]);
 * the theme renders its own video rail, so drop them here (render-blocking otherwise).
 */
add_action(
	'wp_enqueue_scripts',
	function () {
		if ( is_front_page() && ! is_paged() ) {
			wp_dequeue_style( 'nros-video-carousel' );
			wp_dequeue_script( 'nros-video-carousel' );
		}
	},
	100
);
