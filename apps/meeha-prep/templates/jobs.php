<?php
/**
 * Jobs list. Vars: $f (filters), $res (items,total,pages), $types (WP_Term[]), $facets, $saved (int[]).
 *
 * @package meeha-prep
 */

defined( 'ABSPATH' ) || exit;

$mhp_base   = mhp_url( 'jobs' );
$mhp_keep   = array_filter(
	array(
		'q'   => $f['q'],
		'edu' => $f['edu'],
		'loc' => $f['loc'],
	)
);
$mhp_active = ( $f['edu'] ? 1 : 0 ) + ( $f['loc'] ? 1 : 0 );
?>
<section class="mc-mast mc-g">
	<div class="mc-mast__eyebrow">শিক্ষা ও ক্যাম্পাস › নিয়োগ</div>
	<h1 class="mc-mast__title">চাকরির বিজ্ঞপ্তি</h1>
	<p class="mc-mast__lede">যাচাই করা সার্কুলার, শেষ তারিখের কাউন্টডাউনসহ</p>
	<form class="mc-search" method="get" action="<?php echo esc_url( $mhp_base ); ?>" role="search">
		<label class="mc-field mc-grow"><?php echo mhp_icon( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<span class="screen-reader-text">চাকরি খুঁজুন</span>
			<input type="search" name="q" value="<?php echo esc_attr( $f['q'] ); ?>" placeholder="পদ বা প্রতিষ্ঠানের নাম" maxlength="80">
		</label>
		<?php if ( $f['type'] ) : ?>
			<input type="hidden" name="type" value="<?php echo esc_attr( $f['type'] ); ?>">
		<?php endif; ?>
		<details class="mc-filter">
			<summary class="mc-ib mc-ib--light" aria-label="ফিল্টার"><?php echo mhp_icon( 'filter' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php echo $mhp_active ? '<span class="mc-dot"></span>' : ''; ?></summary>
			<div class="mc-filter__panel mc-card mc-pad">
				<label class="mc-lbl" for="mc-f-edu">শিক্ষাগত যোগ্যতা</label>
				<select class="mc-inp" id="mc-f-edu" name="edu">
					<option value="">সব</option>
					<?php foreach ( array( 'এসএসসি', 'এইচএসসি', 'ডিপ্লোমা', 'স্নাতক', 'স্নাতকোত্তর' ) as $mhp_e ) : ?>
						<option value="<?php echo esc_attr( $mhp_e ); ?>" <?php selected( $f['edu'], $mhp_e ); ?>><?php echo esc_html( $mhp_e ); ?></option>
					<?php endforeach; ?>
				</select>
				<label class="mc-lbl" for="mc-f-loc">কর্মস্থল (জেলা)</label>
				<select class="mc-inp" id="mc-f-loc" name="loc">
					<option value="">সব জেলা</option>
					<?php foreach ( mhp_districts() as $mhp_d ) : ?>
						<option value="<?php echo esc_attr( $mhp_d ); ?>" <?php selected( $f['loc'], $mhp_d ); ?>><?php echo esc_html( $mhp_d ); ?></option>
					<?php endforeach; ?>
				</select>
				<div class="mc-row">
					<a class="mc-btn mc-btn--ghost mc-grow" href="<?php echo esc_url( $mhp_base ); ?>">মুছুন</a>
					<button class="mc-btn mc-grow" type="submit">প্রয়োগ করুন</button>
				</div>
			</div>
		</details>
	</form>
</section>

<nav class="mc-g mc-chips-wrap" aria-label="চাকরির ধরন">
	<div class="mc-chips">
		<a class="mc-chip<?php echo ( ! $f['type'] && ! $f['soon'] ) ? ' is-on' : ''; ?>" href="<?php echo esc_url( add_query_arg( $mhp_keep, $mhp_base ) ); ?>">সব</a>
		<?php foreach ( is_array( $types ) ? $types : array() as $mhp_t ) : ?>
			<a class="mc-chip<?php echo $f['type'] === $mhp_t->slug ? ' is-on' : ''; ?>" href="<?php echo esc_url( add_query_arg( array_merge( $mhp_keep, array( 'type' => $mhp_t->slug ) ), $mhp_base ) ); ?>"><?php echo esc_html( $mhp_t->name ); ?></a>
		<?php endforeach; ?>
		<a class="mc-chip<?php echo $f['soon'] ? ' is-on' : ''; ?>" href="<?php echo esc_url( add_query_arg( array_merge( $mhp_keep, array( 'soon' => 1 ) ), $mhp_base ) ); ?>">৭ দিনে শেষ</a>
	</div>
</nav>

<section class="mc-sec mc-g">
	<div class="mc-row mc-between mc-sm mc-muted mc-mb">
		<span><?php echo esc_html( mhp_num( $res['total'] ) . 'টি বিজ্ঞপ্তি' . ( $mhp_active || $f['q'] ? ' · ফিল্টার চালু' : '' ) ); ?></span>
		<?php if ( is_user_logged_in() ) : ?>
			<a class="mc-link" href="<?php echo esc_url( mhp_url( 'me', '', array( 'tab' => 'saved' ) ) ); ?>">সংরক্ষিত (<?php echo esc_html( mhp_bn( count( $saved ) ) ); ?>)</a>
		<?php endif; ?>
	</div>
	<?php if ( $res['items'] ) : ?>
		<div class="mc-col mc-grid2">
			<?php
			foreach ( $res['items'] as $mhp_i => $mhp_j ) {
				echo mhp_job_card( $mhp_j, in_array( $mhp_j['id'], $saved, true ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
				if ( 2 === $mhp_i && function_exists( 'meeha_campus_ad' ) ) {
					echo meeha_campus_ad( 'campus_infeed' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- theme ad markup.
				}
			}
			?>
		</div>
		<?php
		if ( $res['pages'] > 1 ) {
			echo '<nav class="mc-pager" aria-label="পাতা">';
			for ( $mhp_p = 1; $mhp_p <= min( $res['pages'], 20 ); $mhp_p++ ) {
				$mhp_u = add_query_arg( array_merge( $mhp_keep, array_filter( array( 'type' => $f['type'], 'soon' => $f['soon'] ? 1 : '' ) ), array( 'pg' => $mhp_p ) ), $mhp_base );
				printf( '<a href="%s"%s>%s</a>', esc_url( $mhp_u ), $mhp_p === $f['page'] ? ' aria-current="page" class="is-on"' : '', esc_html( mhp_bn( $mhp_p ) ) );
			}
			echo '</nav>';
		}
		?>
	<?php else : ?>
		<div class="mc-card mc-pad mc-center mc-muted">
			<?php if ( $mhp_active || $f['q'] || $f['type'] || $f['soon'] ) : ?>
				এই ফিল্টারে কোনো খোলা বিজ্ঞপ্তি নেই। <a class="mc-link" href="<?php echo esc_url( $mhp_base ); ?>">ফিল্টার মুছুন</a>
			<?php else : ?>
				এই মুহূর্তে খোলা কোনো বিজ্ঞপ্তি তালিকায় নেই। নিয়োগের খবর দেখুন নিচে।
			<?php endif; ?>
		</div>
		<?php
		if ( function_exists( 'meeha_campus_news_list' ) ) {
			meeha_campus_news_list( 'employment', 5, 'নিয়োগের খবর' );
		}
		?>
	<?php endif; ?>
</section>

<section class="mc-sec mc-g">
	<a class="mc-card mc-cta" href="<?php echo esc_url( mhp_url( 'employer' ) ); ?>">
		<span class="mc-mono"><?php echo mhp_icon( 'mega' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
		<span class="mc-grow"><b>আপনার প্রতিষ্ঠানের বিজ্ঞপ্তি এখানে দিন</b><span class="mc-xs mc-muted mc-block">যাচাইয়ের পর প্রকাশ; ফিচার্ড বিজ্ঞপ্তি তালিকার ওপরে থাকে</span></span>
		<?php echo mhp_icon( 'chev' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</a>
</section>
