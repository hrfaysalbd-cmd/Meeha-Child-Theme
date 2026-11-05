<?php
/**
 * Lessons list. Vars: $res (items, pages, total), $track (slug), $subj (term id), $pg, $tracks, $today (array|null),
 * $done (completed lesson ids as strings).
 *
 * @package meeha-prep
 */

defined( 'ABSPATH' ) || exit;

$mhp_subjects = get_terms(
	array(
		'taxonomy'   => 'mh_subject',
		'hide_empty' => false,
	)
);
$mhp_args     = array_filter(
	array(
		'track' => $track,
		'subj'  => $subj,
	)
);
?>
<section class="mc-mast mc-g">
	<div class="mc-mast__eyebrow">শিক্ষা ও ক্যাম্পাস › পরীক্ষা প্রস্তুতি</div>
	<h1 class="mc-mast__title">পাঠ</h1>
	<p class="mc-mast__lede">ছোট ছোট পাঠে মূল বিষয়গুলো, প্রয়োজনে ভিডিওসহ। শেষে অনুশীলনের প্রশ্ন।</p>
</section>

<?php if ( $tracks ) : ?>
	<nav class="mc-g mc-chips-wrap" aria-label="ট্র্যাক">
		<div class="mc-chips">
			<a class="mc-chip<?php echo '' === $track ? ' is-on' : ''; ?>" href="<?php echo esc_url( mhp_url( 'lessons', '', array_filter( array( 'subj' => $subj ) ) ) ); ?>">সব</a>
			<?php foreach ( $tracks as $mhp_t ) : ?>
				<a class="mc-chip<?php echo $track === $mhp_t['slug'] ? ' is-on' : ''; ?>" href="<?php echo esc_url( mhp_url( 'lessons', '', array_filter( array( 'track' => $mhp_t['slug'], 'subj' => $subj ) ) ) ); ?>"><?php echo esc_html( $mhp_t['name'] ); ?></a>
			<?php endforeach; ?>
		</div>
	</nav>
<?php endif; ?>

<?php if ( $today ) : ?>
	<section class="mc-sec mc-g">
		<a class="mc-card mc-today" href="<?php echo esc_url( mhp_url( 'lesson', $today['id'] ) ); ?>">
			<?php if ( $today['thumb'] ) : ?>
				<span class="mc-today__media"><?php echo wp_get_attachment_image( $today['thumb'], 'medium_large', false, array( 'alt' => '', 'loading' => 'lazy' ) ); ?></span>
			<?php endif; ?>
			<span class="mc-today__body">
				<span class="mc-pill mc-pill--gold"><?php echo mhp_icon( 'star' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> আজকের নির্বাচিত পাঠ</span>
				<b class="mc-today__t"><?php echo esc_html( $today['title'] ); ?></b>
				<?php if ( $today['excerpt'] ) : ?>
					<span class="mc-sm mc-muted mc-clamp"><?php echo esc_html( $today['excerpt'] ); ?></span>
				<?php endif; ?>
				<span class="mc-xs mc-muted"><?php echo esc_html( trim( $today['subject'] . ' · ' . mhp_bn( $today['minutes'] ) . ' মিনিট' . ( $today['video'] ? ' · ভিডিও' : '' ), ' ·' ) ); ?><?php echo in_array( (string) $today['id'], $done, true ) ? ' · <b class="mc-ok">পড়েছেন</b>' : ''; ?></span>
			</span>
		</a>
	</section>
<?php endif; ?>

<section class="mc-sec mc-g">
	<div class="mc-row mc-between mc-mb">
		<span class="mc-sm mc-muted"><?php echo esc_html( mhp_bn( $res['total'] ) ); ?>টি পাঠ</span>
		<?php if ( $mhp_subjects && ! is_wp_error( $mhp_subjects ) ) : ?>
			<form method="get" class="mc-row" action="<?php echo esc_url( mhp_url( 'lessons' ) ); ?>">
				<?php if ( $track ) : ?>
					<input type="hidden" name="track" value="<?php echo esc_attr( $track ); ?>">
				<?php endif; ?>
				<label class="screen-reader-text" for="mc-l-subj">বিষয়</label>
				<select class="mc-inp mc-inp--sm" id="mc-l-subj" name="subj" onchange="this.form.submit()">
					<option value="0">সব বিষয়</option>
					<?php foreach ( $mhp_subjects as $mhp_s ) : ?>
						<option value="<?php echo (int) $mhp_s->term_id; ?>" <?php selected( $subj, (int) $mhp_s->term_id ); ?>><?php echo esc_html( $mhp_s->name ); ?></option>
					<?php endforeach; ?>
				</select>
				<noscript><button type="submit" class="mc-btn mc-btn--sm">দেখান</button></noscript>
			</form>
		<?php endif; ?>
	</div>
	<?php if ( $res['items'] ) : ?>
		<div class="mc-lessons">
			<?php foreach ( $res['items'] as $mhp_l ) : ?>
				<?php $mhp_done = in_array( (string) $mhp_l['id'], $done, true ); ?>
				<a class="mc-card mc-lesson" href="<?php echo esc_url( mhp_url( 'lesson', $mhp_l['id'] ) ); ?>">
					<span class="mc-mono<?php echo $mhp_done ? ' mc-mono--ok' : ''; ?>" aria-hidden="true"><?php echo mhp_icon( $mhp_done ? 'check' : ( $mhp_l['video'] ? 'bolt' : 'book' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<span class="mc-grow">
						<b class="mc-lesson__t"><?php echo esc_html( $mhp_l['title'] ); ?></b>
						<span class="mc-xs mc-muted mc-block"><?php echo esc_html( trim( ( $mhp_l['track'] ? $mhp_l['track']['name'] . ' · ' : '' ) . $mhp_l['subject'] . ' · ' . mhp_bn( $mhp_l['minutes'] ) . ' মিনিট' . ( $mhp_l['qcount'] ? ' · ' . mhp_bn( $mhp_l['qcount'] ) . 'টি প্রশ্ন' : '' ), ' ·' ) ); ?></span>
					</span>
					<?php if ( $mhp_l['pack'] ) : ?>
						<span class="mc-pill mc-pill--gold">প্যাকে</span>
					<?php elseif ( $mhp_done ) : ?>
						<span class="mc-pill mc-pill--ok">পড়েছেন</span>
					<?php else : ?>
						<span class="mc-pill mc-pill--brand">ফ্রি</span>
					<?php endif; ?>
				</a>
			<?php endforeach; ?>
		</div>
		<?php if ( $res['pages'] > 1 ) : ?>
			<nav class="mc-pager mc-row mc-between mc-mt" aria-label="পাতা">
				<?php if ( $pg > 1 ) : ?>
					<a class="mc-btn mc-btn--ghost mc-btn--sm" href="<?php echo esc_url( mhp_url( 'lessons', '', array_merge( $mhp_args, array( 'pg' => $pg - 1 ) ) ) ); ?>">আগের পাতা</a>
				<?php else : ?>
					<span></span>
				<?php endif; ?>
				<span class="mc-xs mc-muted"><?php echo esc_html( mhp_bn( $pg ) . '/' . mhp_bn( $res['pages'] ) ); ?></span>
				<?php if ( $pg < $res['pages'] ) : ?>
					<a class="mc-btn mc-btn--ghost mc-btn--sm" href="<?php echo esc_url( mhp_url( 'lessons', '', array_merge( $mhp_args, array( 'pg' => $pg + 1 ) ) ) ); ?>">পরের পাতা</a>
				<?php else : ?>
					<span></span>
				<?php endif; ?>
			</nav>
		<?php endif; ?>
	<?php else : ?>
		<div class="mc-card mc-pad mc-center mc-muted">এই বাছাইয়ে এখনো কোনো পাঠ নেই।</div>
	<?php endif; ?>
</section>
