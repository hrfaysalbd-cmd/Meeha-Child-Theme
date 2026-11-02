<?php
/**
 * Live posts — reader-facing HTML. The same functions render the first page load and every poll reply
 * (meeha/v1/liveblog), so a live update looks exactly like one that was there when the page opened.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

const MEEHA_LB_PAGE = 40; // Updates on the first load; older ones come with "আগের আপডেট".

/**
 * Small inline icon set for the live module.
 *
 * @param string $name Icon.
 * @return string
 */
function meeha_lb_icon( $name ) {
	$p = array(
		'pin'    => '<path d="M15 4.5 19.5 9l-3 1-3.5 3.5L12 18l-6-6 4.5-1L14 7.5z"/><path d="m9 15-4.5 4.5"/>',
		'doc'    => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h6"/>',
		'shield' => '<path d="M12 3 5 6v6c0 4.4 3 7.8 7 9 4-1.2 7-4.6 7-9V6z"/><path d="m9 12 2 2 4-4"/>',
		'alert'  => '<path d="M12 9v4M12 17h.01"/><path d="M10.3 4 2.6 17.5A2 2 0 0 0 4.3 20.5h15.4a2 2 0 0 0 1.7-3L13.7 4a2 2 0 0 0-3.4 0z"/>',
		'link'   => '<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/>',
		'up'     => '<path d="M12 19V5M5 12l7-7 7 7"/>',
		'sort'   => '<path d="M7 4v16M3 8l4-4 4 4M17 20V4M13 16l4 4 4-4"/>',
		'user'   => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
		'bolt'   => '<path d="M13 2 4 14h7l-1 8 9-12h-7z"/>',
		'plus'   => '<path d="M12 5v14M5 12h14"/>',
		'minus'  => '<path d="M5 12h14"/>',
	);
	if ( ! isset( $p[ $name ] ) ) {
		return '';
	}
	return '<svg class="m-lb-i" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p[ $name ] . '</svg>';
}

/**
 * Bengali clock + day for a unix time.
 *
 * @param int $ts Unix time.
 * @return array clock (৪:৩২ অপরাহ্ণ), day (২ নভেম্বর), iso
 */
function meeha_lb_time( $ts ) {
	$ts    = (int) $ts;
	$h     = (int) wp_date( 'G', $ts );
	$part  = $h < 5 ? 'রাত' : ( $h < 12 ? 'সকাল' : ( $h < 15 ? 'দুপুর' : ( $h < 18 ? 'বিকেল' : ( $h < 20 ? 'সন্ধ্যা' : 'রাত' ) ) ) );
	$clock = $part . ' ' . meeha_bn_digits( wp_date( 'g:i', $ts ) );
	$day   = meeha_bn_digits( wp_date( 'j', $ts ) ) . ' ' . meeha_bengali_month( (int) wp_date( 'n', $ts ) );
	if ( wp_date( 'Y', $ts ) !== wp_date( 'Y' ) ) {
		$day .= ' ' . meeha_bn_digits( wp_date( 'Y', $ts ) );
	}
	return array(
		'clock' => $clock,
		'day'   => $day,
		'iso'   => gmdate( 'c', $ts ),
	);
}

/**
 * The red "লাইভ" badge (or the grey "লাইভ শেষ" / amber "বিরতি").
 *
 * @param string $status live|paused|ended.
 * @param string $extra  Extra class.
 * @return string
 */
function meeha_lb_badge_html( $status, $extra = '' ) {
	$states = meeha_lb_states();
	if ( ! isset( $states[ $status ] ) ) {
		return '';
	}
	return '<span class="m-lb-badge m-lb-badge--' . esc_attr( $status ) . ( $extra ? ' ' . esc_attr( $extra ) : '' ) . '"><span class="m-lb-badge__dot" aria-hidden="true"></span>' . esc_html( $states[ $status ] ) . '</span>';
}

/**
 * One update.
 *
 * @param WP_Post $e   Update.
 * @param array   $cfg Live configuration.
 * @return string
 */
function meeha_lb_entry_html( $e, $cfg ) {
	$kind   = meeha_lb_entry_kind( $e );
	$kinds  = meeha_lb_kinds();
	$pinned = (bool) get_post_meta( $e->ID, '_lb_pinned', true );
	$source = (string) get_post_meta( $e->ID, '_lb_source', true );
	$ts     = (int) get_post_time( 'U', true, $e );
	$t      = meeha_lb_time( $ts );
	$author = get_the_author_meta( 'display_name', (int) $e->post_author );
	$body   = apply_filters( 'meeha_lb_entry_content', wpautop( wptexturize( $e->post_content ) ), $e );
	$body   = wp_filter_content_tags( make_clickable( $body ) );
	$key    = in_array( $kind, array( 'key', 'breaking', 'milestone' ), true );
	$cls    = 'm-lb-entry m-lb-entry--' . $kind . ( $pinned ? ' is-pinned' : '' ) . ( $key ? ' is-key' : '' );

	ob_start();
	?>
	<article class="<?php echo esc_attr( $cls ); ?>" id="lb-<?php echo (int) $e->ID; ?>" data-id="<?php echo (int) $e->ID; ?>" data-t="<?php echo (int) $ts; ?>" data-kind="<?php echo esc_attr( $kind ); ?>" data-day="<?php echo esc_attr( wp_date( 'Y-m-d', $ts ) ); ?>">
		<div class="m-lb-entry__time">
			<time datetime="<?php echo esc_attr( $t['iso'] ); ?>"><?php echo esc_html( $t['clock'] ); ?></time>
			<span class="m-lb-entry__ago" data-ago="<?php echo (int) $ts; ?>"><?php echo esc_html( meeha_bengali_time_ago( $ts + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ) ); ?></span>
		</div>
		<div class="m-lb-entry__card">
			<div class="m-lb-entry__tags">
				<?php if ( $pinned ) : ?>
					<span class="m-lb-tag m-lb-tag--pin"><?php echo meeha_lb_icon( 'pin' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>পিন করা</span>
				<?php endif; ?>
				<?php if ( 'update' !== $kind ) : ?>
					<span class="m-lb-tag m-lb-tag--<?php echo esc_attr( $kind ); ?>"><?php echo ( 'document' === $kind || 'evidence' === $kind ) ? meeha_lb_icon( 'doc' ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?><?php echo esc_html( $kinds[ $kind ][0] ); ?></span>
				<?php endif; ?>
			</div>
			<?php if ( '' !== trim( $e->post_title ) ) : ?>
				<h3 class="m-lb-entry__title"><?php echo esc_html( $e->post_title ); ?></h3>
			<?php endif; ?>
			<div class="m-lb-entry__body m-prose"><?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput -- wp_kses_post on save + core content filters. ?></div>
			<?php if ( '' !== $source ) : ?>
				<p class="m-lb-entry__source"><?php echo meeha_lb_icon( 'doc' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><span>সূত্র: <?php echo esc_html( $source ); ?></span></p>
			<?php endif; ?>
			<footer class="m-lb-entry__foot">
				<span class="m-lb-entry__by"><?php echo esc_html( $author ); ?></span>
				<button type="button" class="m-lb-entry__share" data-lb-copy="<?php echo (int) $e->ID; ?>" aria-label="এই আপডেটের লিংক কপি করুন"><?php echo meeha_lb_icon( 'link' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><span>লিংক</span></button>
			</footer>
		</div>
	</article>
	<?php
	return trim( (string) ob_get_clean() );
}

/**
 * Status line: badge, start, newest update, count.
 *
 * @param WP_Post $post Live post.
 * @param array   $cfg  Configuration.
 * @param int     $count Published updates.
 * @return string
 */
function meeha_lb_statusbar_html( $post, $cfg, $count ) {
	$last = (int) get_post_meta( $post->ID, '_meeha_live_last', true );
	$out  = '<div class="m-lb-status m-lb-status--' . esc_attr( $cfg['status'] ) . '">';
	$out .= meeha_lb_badge_html( $cfg['status'] );
	if ( 'ended' === $cfg['status'] && $cfg['ended'] ) {
		$t    = meeha_lb_time( $cfg['ended'] );
		$out .= '<span>শেষ হয়েছে ' . esc_html( $t['day'] . ', ' . $t['clock'] ) . '</span>';
	} elseif ( $last ) {
		$out .= '<span>সর্বশেষ আপডেট <b data-ago="' . (int) $last . '">' . esc_html( meeha_bengali_time_ago( $last + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ) ) . '</b></span>';
	}
	$out .= '<span class="m-lb-status__count">মোট <b>' . esc_html( meeha_bn_digits( $count ) ) . '</b>টি আপডেট</span>';
	if ( 'live' === $cfg['status'] ) {
		$out .= '<span class="m-lb-status__auto" data-lb-auto>স্বয়ংক্রিয়ভাবে হালনাগাদ হচ্ছে</span>';
	}
	$out .= '</div>';
	return $out;
}

/**
 * Key figures tiles.
 *
 * @param array $facts [ {label, value} ].
 * @param string $cls  Extra class.
 * @return string
 */
function meeha_lb_facts_html( $facts, $cls = '' ) {
	if ( ! $facts ) {
		return '';
	}
	$out = '<dl class="m-lb-facts' . ( $cls ? ' ' . esc_attr( $cls ) : '' ) . '">';
	foreach ( $facts as $f ) {
		$out .= '<div class="m-lb-fact"><dt>' . esc_html( $f['label'] ) . '</dt><dd>' . esc_html( meeha_bn_digits( $f['value'] ) ) . '</dd></div>';
	}
	return $out . '</dl>';
}

/**
 * Small text-only list of entries (key moments / documents index).
 *
 * @param array  $rows  [ [id, ts, title, kind, source] ].
 * @param string $title Box heading.
 * @param string $cls   Extra class.
 * @return string
 */
function meeha_lb_index_html( $rows, $title, $cls = '' ) {
	if ( ! $rows ) {
		return '';
	}
	$kinds = meeha_lb_kinds();
	$out   = '<section class="m-lb-index ' . esc_attr( $cls ) . '"><h3 class="m-lb-h">' . esc_html( $title ) . '</h3><ol>';
	foreach ( $rows as $r ) {
		$t    = meeha_lb_time( $r['ts'] );
		$out .= '<li class="m-lb-index__row m-lb-index__row--' . esc_attr( $r['kind'] ) . '"><a href="#lb-' . (int) $r['id'] . '">';
		$out .= '<time datetime="' . esc_attr( $t['iso'] ) . '">' . esc_html( $t['day'] . ' · ' . $t['clock'] ) . '</time>';
		$out .= '<span class="m-lb-index__t">' . esc_html( $r['title'] ) . '</span>';
		$meta = isset( $kinds[ $r['kind'] ] ) ? $kinds[ $r['kind'] ][0] : '';
		if ( '' !== $r['source'] ) {
			$meta .= ' · ' . $r['source'];
		}
		$out .= '<span class="m-lb-index__m">' . esc_html( $meta ) . '</span>';
		$out .= '</a></li>';
	}
	return $out . '</ol></section>';
}

/**
 * Light rows of all published updates (for counts, mentions and indexes) — one query, memoised per request.
 *
 * @param int $post_id Live post ID.
 * @return array [ [id, ts, title, kind, source, text] ] newest first.
 */
function meeha_lb_rows( $post_id ) {
	static $memo = array();
	if ( isset( $memo[ $post_id ] ) ) {
		return $memo[ $post_id ];
	}
	global $wpdb;
	$res  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"SELECT p.ID, p.post_date_gmt, p.post_title, p.post_content,
				MAX(CASE WHEN m.meta_key = '_lb_kind' THEN m.meta_value END) AS kind,
				MAX(CASE WHEN m.meta_key = '_lb_source' THEN m.meta_value END) AS src
			FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key IN ('_lb_kind','_lb_source')
			WHERE p.post_parent = %d AND p.post_type = %s AND p.post_status = 'publish'
			GROUP BY p.ID ORDER BY p.post_date_gmt DESC, p.ID DESC LIMIT 1000",
			(int) $post_id,
			MEEHA_LB_TYPE
		)
	);
	$rows = array();
	foreach ( (array) $res as $r ) {
		$text   = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $r->post_content ) ) );
		$title  = trim( (string) $r->post_title );
		$rows[] = array(
			'id'     => (int) $r->ID,
			'ts'     => (int) strtotime( $r->post_date_gmt . ' UTC' ),
			'title'  => '' !== $title ? $title : wp_trim_words( $text, 14, '…' ),
			'kind'   => isset( meeha_lb_kinds()[ (string) $r->kind ] ) ? (string) $r->kind : 'update',
			'source' => (string) $r->src,
			'text'   => $title . ' ' . $text,
		);
	}
	$memo[ $post_id ] = $rows;
	return $rows;
}

/**
 * Top block of a live event: status, scoreboard, key figures, key moments.
 *
 * @param WP_Post $post Live post.
 * @param array   $cfg  Configuration.
 * @return string
 */
function meeha_lb_event_top_html( $post, $cfg ) {
	$rows = meeha_lb_rows( $post->ID );
	$out  = meeha_lb_statusbar_html( $post, $cfg, count( $rows ) );
	$b    = $cfg['board'];
	if ( $b['on'] && ( '' !== $b['a_name'] || '' !== $b['b_name'] ) ) {
		$out .= '<div class="m-lb-board" aria-label="স্কোরবোর্ড">';
		$out .= '<div class="m-lb-board__side"><span class="m-lb-board__name">' . esc_html( $b['a_name'] ) . '</span><span class="m-lb-board__score">' . esc_html( meeha_bn_digits( $b['a_score'] ) ) . '</span></div>';
		$out .= '<div class="m-lb-board__mid">' . meeha_lb_badge_html( $cfg['status'], 'm-lb-badge--on-dark' ) . '<span class="m-lb-board__vs">বনাম</span></div>';
		$out .= '<div class="m-lb-board__side m-lb-board__side--b"><span class="m-lb-board__score">' . esc_html( meeha_bn_digits( $b['b_score'] ) ) . '</span><span class="m-lb-board__name">' . esc_html( $b['b_name'] ) . '</span></div>';
		if ( '' !== $b['note'] ) {
			$out .= '<p class="m-lb-board__note">' . esc_html( meeha_bn_digits( $b['note'] ) ) . '</p>';
		}
		$out .= '</div>';
	}
	$out .= meeha_lb_facts_html( $cfg['facts'] );
	$keys = array_values(
		array_filter(
			$rows,
			function ( $r ) {
				return in_array( $r['kind'], array( 'key', 'breaking', 'score' ), true );
			}
		)
	);
	$out .= meeha_lb_index_html( array_slice( $keys, 0, 8 ), 'মূল মুহূর্তগুলো', 'm-lb-index--moments' );
	return '<div class="m-lb-top m-lb-top--event">' . $out . '</div>';
}

/**
 * Days between a Y-m-d date and today (site time), or null.
 *
 * @param string $ymd Date.
 * @return int|null
 */
function meeha_lb_days_since( $ymd ) {
	if ( '' === $ymd ) {
		return null;
	}
	$from = date_create_immutable( $ymd, wp_timezone() );
	if ( ! $from ) {
		return null;
	}
	$now = new DateTimeImmutable( 'today', wp_timezone() );
	return max( 0, (int) $from->diff( $now )->format( '%r%a' ) );
}

/**
 * Bengali date for Y-m-d.
 *
 * @param string $ymd Date.
 * @return string
 */
function meeha_lb_ymd_bn( $ymd ) {
	if ( '' === $ymd ) {
		return '';
	}
	$p = array_map( 'intval', explode( '-', $ymd ) );
	return meeha_bn_digits( $p[2] ) . ' ' . meeha_bengali_month( $p[1] ) . ' ' . meeha_bn_digits( $p[0] );
}

/**
 * Person / victim avatar (photo or initial).
 *
 * @param int    $photo Attachment ID.
 * @param string $name  Name.
 * @param string $cls   Class.
 * @return string
 */
function meeha_lb_avatar( $photo, $name, $cls = 'm-lb-ava' ) {
	$url = $photo ? wp_get_attachment_image_url( $photo, 'thumbnail' ) : '';
	if ( $url ) {
		return '<img class="' . esc_attr( $cls ) . '" src="' . esc_url( $url ) . '" alt="" loading="lazy" width="56" height="56">';
	}
	$first = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 1 ) : substr( $name, 0, 1 );
	return '<span class="' . esc_attr( $cls ) . ' ' . esc_attr( $cls ) . '--txt" aria-hidden="true">' . esc_html( $first ) . '</span>';
}

/**
 * Top block of a crime investigation live: the case file.
 *
 * @param WP_Post $post Live post.
 * @param array   $cfg  Configuration.
 * @return string
 */
function meeha_lb_case_top_html( $post, $cfg ) {
	$c       = $cfg['case'];
	$rows    = meeha_lb_rows( $post->ID );
	$states  = meeha_lb_case_states();
	$pstates = meeha_lb_person_states();
	$stages  = meeha_lb_stages();
	$docs    = array_values(
		array_filter(
			$rows,
			function ( $r ) {
				return in_array( $r['kind'], array( 'document', 'evidence' ), true );
			}
		)
	);
	$disc    = '' !== $c['disclaimer'] ? $c['disclaimer'] : meeha_lb_default_disclaimer();
	$days    = meeha_lb_days_since( '' !== $c['filed'] ? $c['filed'] : $c['incident'] );
	if ( null === $days && $cfg['started'] ) {
		$days = max( 0, (int) floor( ( time() - $cfg['started'] ) / DAY_IN_SECONDS ) );
	}

	ob_start();
	?>
	<div class="m-lb-top m-lb-top--case">
		<?php echo meeha_lb_statusbar_html( $post, $cfg, count( $rows ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>

		<section class="m-lb-file" aria-label="কেস ফাইল">
			<div class="m-lb-file__head">
				<span class="m-lb-file__eyebrow"><?php echo meeha_lb_icon( 'shield' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>অপরাধ তদন্ত লাইভ · কেস ফাইল</span>
				<span class="m-lb-case-state m-lb-case-state--<?php echo esc_attr( $c['state'] ); ?>">কেস স্ট্যাটাস: <b><?php echo esc_html( $states[ $c['state'] ][0] ); ?></b></span>
			</div>
			<?php if ( '' !== $c['summary'] ) : ?>
				<p class="m-lb-file__summary"><?php echo esc_html( $c['summary'] ); ?></p>
			<?php endif; ?>
			<dl class="m-lb-file__meta">
				<?php if ( '' !== $c['case_no'] ) : ?>
					<div><dt>মামলা নম্বর</dt><dd><?php echo esc_html( meeha_bn_digits( $c['case_no'] ) ); ?></dd></div>
				<?php endif; ?>
				<?php if ( '' !== $c['incident'] ) : ?>
					<div><dt>ঘটনার তারিখ</dt><dd><?php echo esc_html( meeha_lb_ymd_bn( $c['incident'] ) ); ?></dd></div>
				<?php endif; ?>
				<?php if ( '' !== $c['filed'] ) : ?>
					<div><dt>মামলা দায়ের</dt><dd><?php echo esc_html( meeha_lb_ymd_bn( $c['filed'] ) ); ?></dd></div>
				<?php endif; ?>
				<?php if ( '' !== $c['place'] ) : ?>
					<div><dt>স্থান</dt><dd><?php echo esc_html( $c['place'] ); ?></dd></div>
				<?php endif; ?>
			</dl>
			<ol class="m-lb-steps" aria-label="তদন্তের ধাপ">
				<?php
				$cur = array_search( $c['stage'], array_keys( $stages ), true );
				$i   = 0;
				foreach ( $stages as $sid => $slabel ) :
					$st = $i < $cur ? 'done' : ( $i === $cur ? 'now' : 'next' );
					?>
					<li class="m-lb-step m-lb-step--<?php echo esc_attr( $st ); ?>"<?php echo 'now' === $st ? ' aria-current="step"' : ''; ?>><span class="m-lb-step__dot"><?php echo esc_html( meeha_bn_digits( $i + 1 ) ); ?></span><span class="m-lb-step__l"><?php echo esc_html( $slabel ); ?></span></li>
					<?php
					++$i;
				endforeach;
				?>
			</ol>
		</section>

		<div class="m-lb-note" role="note">
			<?php echo meeha_lb_icon( 'alert' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<p><b>বিঃদ্রঃ</b> <?php echo esc_html( $disc ); ?></p>
		</div>

		<dl class="m-lb-facts m-lb-facts--auto">
			<div class="m-lb-fact"><dt>মোট আপডেট</dt><dd><?php echo esc_html( meeha_bn_digits( count( $rows ) ) ); ?></dd></div>
			<div class="m-lb-fact"><dt>নথি ও প্রমাণ</dt><dd><?php echo esc_html( meeha_bn_digits( count( $docs ) ) ); ?></dd></div>
			<div class="m-lb-fact"><dt>যাদের নাম এসেছে</dt><dd><?php echo esc_html( meeha_bn_digits( count( $c['people'] ) ) ); ?></dd></div>
			<?php if ( null !== $days ) : ?>
				<div class="m-lb-fact"><dt>দিন পেরিয়েছে</dt><dd><?php echo esc_html( meeha_bn_digits( $days ) ); ?></dd></div>
			<?php endif; ?>
			<?php foreach ( $cfg['facts'] as $f ) : ?>
				<div class="m-lb-fact"><dt><?php echo esc_html( $f['label'] ); ?></dt><dd><?php echo esc_html( meeha_bn_digits( $f['value'] ) ); ?></dd></div>
			<?php endforeach; ?>
		</dl>

		<?php if ( $c['victims'] ) : ?>
			<section class="m-lb-sec">
				<h3 class="m-lb-h">ভিক্টিম</h3>
				<div class="m-lb-victims">
					<?php foreach ( $c['victims'] as $v ) : ?>
						<div class="m-lb-victim">
							<?php echo meeha_lb_avatar( $v['photo'], $v['name'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<div>
								<p class="m-lb-victim__name"><?php echo esc_html( $v['name'] ); ?><?php echo '' !== $v['age'] ? '<span>, ' . esc_html( meeha_bn_digits( $v['age'] ) ) . '</span>' : ''; ?></p>
								<?php if ( '' !== $v['detail'] ) : ?>
									<p class="m-lb-victim__d"><?php echo esc_html( $v['detail'] ); ?></p>
								<?php endif; ?>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			</section>
		<?php endif; ?>

		<?php
		if ( $c['people'] ) :
			$people = $c['people'];
			foreach ( $people as &$pp ) {
				$hits = 0;
				foreach ( $rows as $r ) {
					if ( false !== mb_stripos( $r['text'], $pp['name'] ) ) {
						++$hits;
					}
				}
				$pp['hits'] = $hits;
			}
			unset( $pp );
			$by_share = $people;
			usort(
				$by_share,
				function ( $a, $b ) {
					return $b['share'] - $a['share'];
				}
			);
			?>
			<section class="m-lb-sec">
				<h3 class="m-lb-h">এখন পর্যন্ত যাদের নাম এসেছে</h3>
				<?php if ( $c['show_share'] ) : ?>
					<p class="m-lb-sub">সম্পৃক্ততার মাত্রা — সংগৃহীত নথি ও তথ্যের ভিত্তিতে মিহা নিউজের মূল্যায়ন, আদালতের সিদ্ধান্ত নয়।</p>
					<ul class="m-lb-bars">
						<?php foreach ( $by_share as $p ) : ?>
							<li class="m-lb-bar m-lb-bar--<?php echo esc_attr( $pstates[ $p['status'] ][1] ); ?>">
								<span class="m-lb-bar__who"><?php echo meeha_lb_avatar( $p['photo'], $p['name'], 'm-lb-ava m-lb-ava--sm' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><span><b><?php echo esc_html( $p['name'] ); ?></b><?php echo '' !== $p['role'] ? '<small>' . esc_html( $p['role'] ) . '</small>' : ''; ?></span></span>
								<span class="m-lb-bar__track" role="img" aria-label="<?php echo esc_attr( $p['name'] . ': ' . $p['share'] . '%' ); ?>"><span class="m-lb-bar__fill" style="--w:<?php echo (int) $p['share']; ?>%"></span></span>
								<span class="m-lb-bar__val"><?php echo esc_html( meeha_bn_digits( $p['share'] ) ); ?>%</span>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
				<div class="m-lb-table-wrap">
					<table class="m-lb-table">
						<caption class="screen-reader-text">যাদের নাম এসেছে — তুলনামূলক তথ্য</caption>
						<thead><tr><th scope="col">নাম</th><th scope="col">ভূমিকা</th><th scope="col">অবস্থা</th><?php echo $c['show_share'] ? '<th scope="col">সম্পৃক্ততা</th>' : ''; ?><th scope="col">আপডেটে উল্লেখ</th></tr></thead>
						<tbody>
							<?php foreach ( $people as $p ) : ?>
								<tr>
									<th scope="row"><?php echo esc_html( $p['name'] ); ?><?php echo '' !== $p['note'] ? '<small>' . esc_html( $p['note'] ) . '</small>' : ''; ?></th>
									<td><?php echo esc_html( $p['role'] ); ?></td>
									<td><span class="m-lb-pill m-lb-pill--<?php echo esc_attr( $pstates[ $p['status'] ][1] ); ?>"><?php echo esc_html( $pstates[ $p['status'] ][0] ); ?></span></td>
									<?php if ( $c['show_share'] ) : ?>
										<td class="m-lb-num"><?php echo esc_html( meeha_bn_digits( $p['share'] ) ); ?>%</td>
									<?php endif; ?>
									<td class="m-lb-num"><?php echo esc_html( meeha_bn_digits( $p['hits'] ) ); ?> বার</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</section>
		<?php endif; ?>

		<?php
		if ( $c['strengths'] || $c['weaknesses'] ) :
			$sum_s = array_sum( wp_list_pluck( $c['strengths'], 'weight' ) );
			$sum_w = array_sum( wp_list_pluck( $c['weaknesses'], 'weight' ) );
			$ratio = ( $sum_s + $sum_w ) > 0 ? $sum_s / ( $sum_s + $sum_w ) : 0.5;
			$pct   = (int) round( $ratio * 100 );
			$verd  = $ratio >= 0.65 ? array( 'শক্ত ভিত্তি', 'strong' ) : ( $ratio >= 0.4 ? array( 'মিশ্র ভিত্তি', 'mixed' ) : array( 'দুর্বল ভিত্তি', 'weak' ) );
			?>
			<section class="m-lb-sec">
				<h3 class="m-lb-h">তদন্তের শক্তিশালী ও দুর্বল দিক</h3>
				<div class="m-lb-balance m-lb-balance--<?php echo esc_attr( $verd[1] ); ?>">
					<div class="m-lb-balance__bar" role="img" aria-label="<?php echo esc_attr( 'শক্তিশালী দিক ' . $pct . '%, দুর্বল দিক ' . ( 100 - $pct ) . '%' ); ?>">
						<span class="m-lb-balance__s" style="--w:<?php echo (int) $pct; ?>%"></span>
						<span class="m-lb-balance__w"></span>
					</div>
					<div class="m-lb-balance__legend">
						<span class="is-s">শক্তিশালী <?php echo esc_html( meeha_bn_digits( $pct ) ); ?>%</span>
						<b><?php echo esc_html( $verd[0] ); ?></b>
						<span class="is-w">দুর্বল <?php echo esc_html( meeha_bn_digits( 100 - $pct ) ); ?>%</span>
					</div>
				</div>
				<div class="m-lb-sw">
					<?php
					foreach ( array(
						'strengths'  => array( 'শক্তিশালী দিক', 's', 'plus' ),
						'weaknesses' => array( 'দুর্বল দিক', 'w', 'minus' ),
					) as $k => $meta ) :
						if ( ! $c[ $k ] ) {
							continue;
						}
						?>
						<div class="m-lb-sw__col m-lb-sw__col--<?php echo esc_attr( $meta[1] ); ?>">
							<h4><?php echo meeha_lb_icon( $meta[2] ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php echo esc_html( $meta[0] ); ?></h4>
							<ul>
								<?php foreach ( $c[ $k ] as $it ) : ?>
									<li><span><?php echo esc_html( $it['text'] ); ?></span><span class="m-lb-dots" role="img" aria-label="<?php echo esc_attr( 'গুরুত্ব ' . $it['weight'] . '/৫' ); ?>"><?php echo str_repeat( '<i class="on"></i>', $it['weight'] ) . str_repeat( '<i></i>', 5 - $it['weight'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span></li>
								<?php endforeach; ?>
							</ul>
						</div>
					<?php endforeach; ?>
				</div>
			</section>
		<?php endif; ?>

		<?php echo meeha_lb_index_html( array_slice( $docs, 0, 10 ), 'নথি ও তথ্য-প্রমাণ', 'm-lb-index--docs' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
	</div>
	<?php
	return trim( (string) ob_get_clean() );
}

/**
 * The top block for a live post.
 *
 * @param WP_Post $post Live post.
 * @param array   $cfg  Configuration.
 * @return string
 */
function meeha_lb_top_html( $post, $cfg ) {
	return 'investigation' === $cfg['type'] ? meeha_lb_case_top_html( $post, $cfg ) : meeha_lb_event_top_html( $post, $cfg );
}

/**
 * Pinned updates (newest first, at most 3).
 *
 * @param int $post_id Live post ID.
 * @return WP_Post[]
 */
function meeha_lb_pinned( $post_id ) {
	return get_posts(
		array(
			'post_type'        => MEEHA_LB_TYPE,
			'post_parent'      => (int) $post_id,
			'post_status'      => 'publish',
			'posts_per_page'   => 3,
			'meta_key'         => '_lb_pinned', // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value'       => '1', // phpcs:ignore WordPress.DB.SlowDBQuery
			'orderby'          => 'date',
			'order'            => 'DESC',
			'no_found_rows'    => true,
			'suppress_filters' => true,
		)
	);
}

/**
 * The feed: pinned updates, then the stream (newest first), with a day divider whenever the date changes.
 *
 * @param WP_Post $post Live post.
 * @param array   $cfg  Configuration.
 * @return string
 */
function meeha_lb_feed_html( $post, $cfg ) {
	$entries = meeha_lb_entries( $post->ID, array( 'limit' => MEEHA_LB_PAGE + 1 ) );
	$more    = count( $entries ) > MEEHA_LB_PAGE;
	$entries = array_slice( $entries, 0, MEEHA_LB_PAGE );
	$pins    = meeha_lb_pinned( $post->ID );
	$is_case = 'investigation' === $cfg['type'];
	$api     = rest_url( 'meeha/v1/liveblog/' . $post->ID );

	ob_start();
	?>
	<section class="m-lb-feed" id="live" data-lb data-lb-api="<?php echo esc_url( $api ); ?>" data-lb-status="<?php echo esc_attr( $cfg['status'] ); ?>" data-lb-since="<?php echo (int) time(); ?>" aria-label="<?php echo $is_case ? 'তদন্তের হালনাগাদ' : 'লাইভ আপডেট'; ?>">
		<header class="m-lb-feed__head">
			<h2 class="m-lb-feed__title"><?php echo meeha_lb_badge_html( $cfg['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php echo $is_case ? 'তদন্তের হালনাগাদ' : 'প্রতি মুহূর্তের আপডেট'; ?></h2>
			<div class="m-lb-feed__tools" role="group" aria-label="আপডেট দেখার ধরন">
				<button type="button" class="m-lb-chip is-on" data-lb-filter="all" aria-pressed="true">সব</button>
				<button type="button" class="m-lb-chip" data-lb-filter="key" aria-pressed="false">শুধু গুরুত্বপূর্ণ</button>
				<?php if ( $is_case ) : ?>
					<button type="button" class="m-lb-chip" data-lb-filter="doc" aria-pressed="false">নথি ও প্রমাণ</button>
				<?php endif; ?>
				<button type="button" class="m-lb-chip m-lb-chip--sort" data-lb-sort aria-pressed="false"><?php echo meeha_lb_icon( 'sort' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><span>পুরনো আগে</span></button>
			</div>
		</header>
		<button type="button" class="m-lb-new" data-lb-new hidden><?php echo meeha_lb_icon( 'up' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><span></span></button>
		<div class="m-lb-pins" data-lb-pins<?php echo $pins ? '' : ' hidden'; ?>>
			<?php
			foreach ( $pins as $e ) {
				echo meeha_lb_entry_html( $e, $cfg ); // phpcs:ignore WordPress.Security.EscapeOutput
			}
			?>
		</div>
		<div class="m-lb-list" data-lb-list>
			<?php
			$pin_ids = wp_list_pluck( $pins, 'ID' );
			foreach ( $entries as $e ) {
				if ( in_array( $e->ID, $pin_ids, true ) ) {
					continue;
				}
				echo meeha_lb_entry_html( $e, $cfg ); // phpcs:ignore WordPress.Security.EscapeOutput
			}
			?>
		</div>
		<p class="m-lb-empty" data-lb-empty<?php echo $entries ? ' hidden' : ''; ?>><?php echo 'ended' === $cfg['status'] ? 'কোনো আপডেট নেই।' : 'শিগগিরই প্রথম আপডেট আসছে — পাতাটি খোলা রাখুন।'; ?></p>
		<?php if ( $more ) : ?>
			<button type="button" class="m-lb-more" data-lb-more>আগের আপডেট দেখুন</button>
		<?php endif; ?>
	</section>
	<?php
	return trim( (string) ob_get_clean() );
}
