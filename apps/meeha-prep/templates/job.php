<?php
/**
 * One job. Vars: $j, $saved, $track (array|null), $similar (array[]).
 *
 * @package meeha-prep
 */

defined( 'ABSPATH' ) || exit;

$mhp_rows = array(
	'পদসংখ্যা'      => $j['vacancies'] ? mhp_num( $j['vacancies'] ) . 'টি' : '',
	'যোগ্যতা'       => $j['education'],
	'বয়স/অভিজ্ঞতা' => $j['age'],
	'কর্মস্থল'      => $j['location'],
	'বেতন গ্রেড'    => $j['grade'],
	'বেতন'          => $j['salary'],
	'আবেদন ফি'      => $j['fee'],
	'আবেদন শুরু'    => mhp_is_date( $j['apply_start'] ) ? mhp_fdate( $j['apply_start'] ) : '',
	'প্রকাশ'        => mhp_fdate( $j['posted'] ),
);
$mhp_circ_url  = $j['circular_id'] ? wp_get_attachment_url( $j['circular_id'] ) : '';
$mhp_circ_img  = $j['circular_id'] && wp_attachment_is_image( $j['circular_id'] );
$mhp_apply_ok  = $j['apply_url'] && wp_http_validate_url( $j['apply_url'] );
$mhp_expired   = null !== $j['days_left'] && $j['days_left'] < 0;
?>
<div class="mc-g mc-detail">
	<?php if ( 'publish' !== $j['status'] ) : ?>
		<div class="mc-note">এই বিজ্ঞপ্তিটি এখনো প্রকাশিত নয় (<?php echo esc_html( 'pending' === $j['status'] ? 'যাচাই বাকি' : $j['status'] ); ?>)। শুধু সম্পাদকেরা দেখছেন।</div>
	<?php endif; ?>
	<div class="mc-row mc-top">
		<span class="mc-mono mc-mono--lg" aria-hidden="true"><?php echo esc_html( mhp_initial( $j['org'] ? $j['org'] : $j['title'] ) ); ?></span>
		<div class="mc-grow">
			<div class="mc-sm mc-muted"><?php echo esc_html( $j['org'] ); ?></div>
			<h1 class="mc-h1"><?php echo esc_html( $j['title'] ); ?></h1>
		</div>
	</div>
	<div class="mc-row mc-wrap mc-gap6">
		<?php if ( $j['featured'] ) : ?>
			<span class="mc-pill mc-pill--gold"><?php echo mhp_icon( 'star' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> ফিচার্ড</span>
		<?php endif; ?>
		<?php if ( $j['type'] ) : ?>
			<span class="mc-pill mc-pill--line"><?php echo esc_html( $j['type'] ); ?></span>
		<?php endif; ?>
		<?php echo mhp_dl_pill( $j['days_left'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</div>

	<div class="mc-card mc-pad mc-mt">
		<dl class="mc-kv">
			<?php foreach ( $mhp_rows as $mhp_k => $mhp_v ) : ?>
				<?php if ( '' !== (string) $mhp_v ) : ?>
					<dt><?php echo esc_html( $mhp_k ); ?></dt><dd><?php echo esc_html( $mhp_v ); ?></dd>
				<?php endif; ?>
			<?php endforeach; ?>
			<?php if ( mhp_is_date( $j['deadline'] ) ) : ?>
				<dt>শেষ তারিখ</dt><dd class="mc-bad"><?php echo esc_html( mhp_fdate( $j['deadline'] ) ); ?></dd>
			<?php endif; ?>
		</dl>
	</div>

	<div class="mc-row mc-mt">
		<?php echo mhp_save_button( $j['id'], $saved, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<?php if ( $mhp_circ_url ) : ?>
			<a class="mc-btn mc-btn--ghost mc-btn--sm mc-grow" href="#mc-circular"><?php echo mhp_icon( 'doc' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> মূল সার্কুলার</a>
		<?php elseif ( $j['source_url'] ) : ?>
			<a class="mc-btn mc-btn--ghost mc-btn--sm mc-grow" href="<?php echo esc_url( $j['source_url'] ); ?>" target="_blank" rel="noopener nofollow"><?php echo mhp_icon( 'doc' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> মূল সার্কুলার</a>
		<?php endif; ?>
	</div>
	<?php if ( is_user_logged_in() && $saved ) : ?>
		<p class="mc-xs mc-muted">সংরক্ষিত। "আমার পাতা › অ্যালার্ট"-এ রিমাইন্ডার চালু থাকলে শেষ তারিখের আগে ইমেইল পাবেন।</p>
	<?php endif; ?>

	<?php if ( '' !== trim( wp_strip_all_tags( $j['content'] ) ) ) : ?>
		<div class="mc-prose mc-mt">
			<?php echo wp_kses_post( wpautop( $j['content'] ) ); ?>
		</div>
	<?php endif; ?>

	<?php if ( $j['steps'] ) : ?>
		<h2 class="mc-h3">আবেদনের ধাপ</h2>
		<ol class="mc-steps">
			<?php foreach ( $j['steps'] as $mhp_s ) : ?>
				<li><?php echo esc_html( $mhp_s ); ?></li>
			<?php endforeach; ?>
		</ol>
	<?php endif; ?>

	<?php if ( $mhp_circ_url ) : ?>
		<h2 class="mc-h3" id="mc-circular">মূল সার্কুলার</h2>
		<?php if ( $mhp_circ_img ) : ?>
			<a href="<?php echo esc_url( $mhp_circ_url ); ?>" target="_blank" rel="noopener" class="mc-circular">
				<?php echo wp_get_attachment_image( $j['circular_id'], 'large', false, array( 'alt' => 'মূল সার্কুলার: ' . $j['title'], 'loading' => 'lazy' ) ); ?>
			</a>
			<p class="mc-xs mc-muted">ছবিতে চাপ দিলে বড় করে দেখা যাবে।</p>
		<?php else : ?>
			<a class="mc-btn mc-btn--ghost" href="<?php echo esc_url( $mhp_circ_url ); ?>" target="_blank" rel="noopener"><?php echo mhp_icon( 'doc' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> পিডিএফ খুলুন</a>
		<?php endif; ?>
	<?php endif; ?>
	<?php if ( $j['source'] ) : ?>
		<p class="mc-xs mc-muted mc-mt">সূত্র: <?php echo $j['source_url'] ? '<a href="' . esc_url( $j['source_url'] ) . '" target="_blank" rel="noopener nofollow">' . esc_html( $j['source'] ) . '</a>' : esc_html( $j['source'] ); ?>। আবেদনের আগে মূল বিজ্ঞপ্তির সঙ্গে মিলিয়ে নিন।</p>
	<?php endif; ?>

	<?php if ( $j['news'] && 'publish' === get_post_status( $j['news'] ) ) : ?>
		<a class="mc-card mc-cta mc-mt" href="<?php echo esc_url( get_permalink( $j['news'] ) ); ?>">
			<span class="mc-mono"><?php echo mhp_icon( 'doc' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			<span class="mc-grow"><span class="mc-xs mc-muted mc-block">সংশ্লিষ্ট খবর</span><b><?php echo esc_html( get_the_title( $j['news'] ) ); ?></b></span>
		</a>
	<?php endif; ?>

	<?php if ( $track ) : ?>
		<div class="mc-card mc-pad mc-mt mc-tint">
			<b>এই পরীক্ষার প্রস্তুতি নিন</b>
			<p class="mc-sm mc-muted"><?php echo esc_html( $track['name'] ); ?>: ফ্রি মডেল টেস্ট ও অনুশীলনের প্রশ্ন।</p>
			<a class="mc-btn mc-btn--sm" href="<?php echo esc_url( mhp_url( 'track', $track['slug'] ) ); ?>">প্রস্তুতি শুরু <?php echo mhp_icon( 'chev' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
		</div>
	<?php endif; ?>

	<?php
	if ( function_exists( 'meeha_campus_ad' ) ) {
		echo meeha_campus_ad( 'campus_mpu' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
	?>

	<?php if ( $similar ) : ?>
		<h2 class="mc-h3">একই ধরনের চাকরি</h2>
		<div class="mc-col">
			<?php
			$mhp_saved_list = is_user_logged_in() ? MHP_Jobs::saved( get_current_user_id() ) : array();
			foreach ( $similar as $mhp_s ) {
				echo mhp_job_card( $mhp_s, in_array( $mhp_s['id'], $mhp_saved_list, true ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			?>
		</div>
	<?php endif; ?>
</div>
<div class="mc-sticky mc-g">
	<button type="button" class="mc-btn mc-btn--ghost" data-mc-share aria-label="শেয়ার করুন"><?php echo mhp_icon( 'share' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
	<?php if ( $mhp_expired ) : ?>
		<span class="mc-btn mc-grow is-disabled" aria-disabled="true">আবেদনের সময় শেষ</span>
	<?php elseif ( $mhp_apply_ok ) : ?>
		<a class="mc-btn mc-grow" href="<?php echo esc_url( $j['apply_url'] ); ?>" target="_blank" rel="noopener nofollow">অফিসিয়াল সাইটে আবেদন <?php echo mhp_icon( 'ext' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
	<?php else : ?>
		<span class="mc-btn mc-btn--soft mc-grow" aria-disabled="true">আবেদনের নিয়ম সার্কুলারে দেখুন</span>
	<?php endif; ?>
</div>
