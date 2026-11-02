<?php
/**
 * Prep home. Vars: $tracks, $live (array|null), $products (WP_Post[]), $last (array[]).
 *
 * @package meeha-prep
 */

defined( 'ABSPATH' ) || exit;

$mhp_uid = get_current_user_id();
?>
<section class="mc-mast mc-g">
	<div class="mc-mast__eyebrow">শিক্ষা ও ক্যাম্পাস › পরীক্ষা প্রস্তুতি</div>
	<h1 class="mc-mast__title">পরীক্ষার প্রস্তুতি</h1>
	<p class="mc-mast__lede">ফ্রি মডেল টেস্ট, দৈনিক কুইজ আর ব্যাখ্যাসহ অনুশীলনের প্রশ্ন। পেইড টেস্ট একবার কিনলেই অ্যাকাউন্টে থাকে, মাসিক চার্জ নেই।</p>
</section>

<?php if ( $last ) : ?>
	<?php $mhp_lt = MHP_Tests::info( $last[0]['test_id'] ); ?>
	<section class="mc-sec mc-g">
		<a class="mc-card mc-pad mc-block" href="<?php echo esc_url( mhp_url( 'result', $last[0]['id'] ) ); ?>">
			<span class="mc-row mc-between"><b>আপনার সর্বশেষ ফল</b><span class="mc-link">দেখুন</span></span>
			<span class="mc-sm mc-muted mc-block"><?php echo esc_html( ( $mhp_lt ? $mhp_lt['title'] : 'টেস্ট' ) . ': ' . mhp_score( $last[0]['score'] ) . '/' . mhp_bn( $last[0]['total'] ) ); ?></span>
		</a>
	</section>
<?php endif; ?>

<section class="mc-sec mc-g">
	<div class="mc-sech"><h2>ট্র্যাক বেছে নিন</h2></div>
	<?php if ( $tracks ) : ?>
		<div class="mc-tracks">
			<?php
			foreach ( $tracks as $mhp_t ) {
				echo mhp_track_tile( $mhp_t ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			?>
		</div>
	<?php else : ?>
		<p class="mc-muted">ট্র্যাক এখনো যোগ হয়নি।</p>
	<?php endif; ?>
</section>

<?php if ( $live ) : ?>
	<section class="mc-sec mc-g">
		<div class="mc-sech"><h2><?php echo esc_html( 'open' === $live['state'] ? 'এখন চলছে: লাইভ টেস্ট' : 'আসছে: লাইভ টেস্ট' ); ?></h2></div>
		<?php echo mhp_test_row( $live, $mhp_uid ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</section>
<?php endif; ?>

<section class="mc-sec mc-g">
	<a class="mc-card mc-cta" href="<?php echo esc_url( mhp_url( 'quiz' ) ); ?>">
		<span class="mc-mono"><?php echo mhp_icon( 'bolt' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
		<span class="mc-grow"><b>আজকের কুইজ</b><span class="mc-xs mc-muted mc-block">প্রতিদিন নতুন প্রশ্ন, ব্যাখ্যাসহ। লিডারবোর্ডে নিজের জায়গা দেখুন।</span></span>
		<?php echo mhp_icon( 'chev' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</a>
</section>

<?php
$mhp_tgt = $mhp_uid ? MHP_Learn::target( $mhp_uid ) : array( 'track' => 0 );
$mhp_tl  = MHP_Lessons::today( (int) $mhp_tgt['track'] );
?>
<?php if ( $mhp_tl || MHP_Pubs::any() || $mhp_uid ) : ?>
	<section class="mc-sec mc-g">
		<div class="mc-col mc-grid2">
			<?php if ( $mhp_tl ) : ?>
				<a class="mc-card mc-cta" href="<?php echo esc_url( mhp_url( 'lesson', $mhp_tl['id'] ) ); ?>">
					<span class="mc-mono mc-mono--gold"><?php echo mhp_icon( 'star' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<span class="mc-grow"><b>আজকের নির্বাচিত পাঠ</b><span class="mc-xs mc-muted mc-block mc-clamp"><?php echo esc_html( $mhp_tl['title'] ); ?></span></span>
					<?php echo mhp_icon( 'chev' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</a>
			<?php endif; ?>
			<?php if ( MHP_Lessons::any() ) : ?>
				<a class="mc-card mc-cta" href="<?php echo esc_url( mhp_url( 'lessons' ) ); ?>">
					<span class="mc-mono"><?php echo mhp_icon( 'book' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<span class="mc-grow"><b>সব পাঠ</b><span class="mc-xs mc-muted mc-block">বিষয়ভিত্তিক ছোট পাঠ, ভিডিও ও অনুশীলন</span></span>
					<?php echo mhp_icon( 'chev' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</a>
			<?php endif; ?>
			<?php if ( $mhp_uid ) : ?>
				<a class="mc-card mc-cta" href="<?php echo esc_url( mhp_url( 'mistakes' ) ); ?>">
					<span class="mc-mono"><?php echo mhp_icon( 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<span class="mc-grow"><b>ভুলের খাতা</b><span class="mc-xs mc-muted mc-block">আগের ভুলগুলো আবার করে শিখুন</span></span>
					<?php echo mhp_icon( 'chev' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</a>
			<?php endif; ?>
			<?php if ( MHP_Pubs::any() ) : ?>
				<a class="mc-card mc-cta" href="<?php echo esc_url( mhp_url( 'pubs' ) ); ?>">
					<span class="mc-mono"><?php echo mhp_icon( 'doc' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<span class="mc-grow"><b>প্রকাশনা</b><span class="mc-xs mc-muted mc-block">সাময়িকী ও সিলেবাস বই: ডিজিটাল বা প্রিন্ট</span></span>
					<?php echo mhp_icon( 'chev' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</a>
			<?php endif; ?>
		</div>
	</section>
<?php endif; ?>

<?php if ( $products ) : ?>
	<section class="mc-sec mc-g">
		<div class="mc-sech"><h2>প্যাক, বই ও সেবা</h2></div>
		<div class="mc-col">
			<?php
			foreach ( $products as $mhp_p ) {
				echo mhp_product_card( $mhp_p, $mhp_uid ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			?>
		</div>
	</section>
<?php endif; ?>

<?php
if ( function_exists( 'meeha_campus_partner_box' ) ) {
	meeha_campus_partner_box();
}
if ( function_exists( 'meeha_campus_ad' ) ) {
	echo '<section class="mc-sec mc-g">' . meeha_campus_ad( 'campus_banner' ) . '</section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
