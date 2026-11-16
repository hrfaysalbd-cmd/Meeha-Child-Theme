<?php
/**
 * Student dashboard (আমার › সারসংক্ষেপ). Vars: MHP_Learn::dashboard() keys, plus $uid, $results, $saved, $items.
 * Phones: one column in the order below (CSS `order`). Desktop: main column + side column.
 *
 * @package meeha-prep
 */

defined( 'ABSPATH' ) || exit;

$mhp_ic  = function ( $n ) {
	return mhp_icon( $n ); // Trusted SVG from the theme.
};
$mhp_avg = 0;
if ( $results ) {
	$mhp_sum = 0;
	foreach ( $results as $mhp_r ) {
		$mhp_sum += $mhp_r['total'] ? $mhp_r['score'] / $mhp_r['total'] : 0;
	}
	$mhp_avg = round( $mhp_sum / count( $results ) * 100 );
}
$mhp_gpct = min( 100, (int) round( $today / max( 1, $goal ) * 100 ) );
$mhp_plan = array();
if ( $quiz_ready ) {
	$mhp_plan[] = array( $quiz_done, 'আজকের কুইজ', $quiz_done ? 'শেষ করেছেন' . ( $streak > 1 ? ' · টানা ' . mhp_bn( $streak ) . ' দিন' : '' ) : 'প্রতি সঠিক উত্তরে ' . mhp_bn( MHP_Points::rules()['quiz_correct'] ) . ' পয়েন্ট', mhp_url( 'quiz' ), $quiz_done ? 'উত্তর দেখুন' : 'খেলুন', 'bolt' );
}
if ( $lesson ) {
	$mhp_plan[] = array( $lesson_ok, 'আজকের নির্বাচিত পাঠ', $lesson['title'], mhp_url( 'lesson', $lesson['id'] ), $lesson_ok ? 'আবার পড়ুন' : 'পড়ুন', 'book' );
}
if ( $next_test || $test_today ) {
	$mhp_plan[] = array( $test_today, 'একটি মডেল টেস্ট', $test_today ? 'আজ একটি টেস্ট দিয়েছেন' : $next_test['title'] . ' · ' . mhp_bn( $next_test['count'] ) . 'টি প্রশ্ন', $next_test ? mhp_url( 'test', $next_test['id'] ) : mhp_url( 'prep' ), $test_today ? 'আরও টেস্ট' : 'শুরু করুন', 'doc' );
}
if ( $open || $fix_today ) {
	$mhp_fix_goal = min( 5, $open + $fix_today );
	$mhp_plan[]   = array( $fix_today >= $mhp_fix_goal, 'ভুলগুলো শুধরে নিন', mhp_bn( $fix_today ) . '/' . mhp_bn( $mhp_fix_goal ) . ' · খাতায় ' . mhp_bn( $open ) . 'টি প্রশ্ন', mhp_url( 'mistakes' ), 'অনুশীলন', 'check' );
}
$mhp_tracks = mhp_tracks();
$mhp_src    = MHP_Points::sources();
?>
<div class="mc-dash">
	<div class="mc-dash__main">

		<div class="mc-card mc-lvl" style="order:1">
			<div class="mc-row">
				<span class="mc-lvl__n" aria-hidden="true"><?php echo esc_html( mhp_bn( $level['n'] ) ); ?></span>
				<div class="mc-grow">
					<span class="mc-xs mc-lvl__k">লেভেল <?php echo esc_html( mhp_bn( $level['n'] ) ); ?></span>
					<b class="mc-lvl__name"><?php echo esc_html( $level['name'] ); ?></b>
				</div>
				<div class="mc-lvl__pts"><b class="mc-num"><?php echo esc_html( mhp_num( $total ) ); ?></b><span class="mc-xs">পয়েন্ট</span></div>
			</div>
			<div class="mc-meter mc-meter--light" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo (int) $level['pct']; ?>" aria-label="পরের লেভেলের দিকে"><i style="width:<?php echo (int) $level['pct']; ?>%"></i></div>
			<p class="mc-xs mc-lvl__next"><?php echo esc_html( $level['next'] ? 'আর ' . mhp_num( $level['left'] ) . ' পয়েন্টে "' . $level['up'] . '" লেভেল' : 'সর্বোচ্চ লেভেলে পৌঁছেছেন!' ); ?></p>
			<div class="mc-lvl__stats">
				<span><b class="mc-num"><?php echo esc_html( mhp_num( $week ) ); ?></b>এই সপ্তাহে</span>
				<a href="<?php echo esc_url( mhp_url( 'leaderboard', '', array( 'range' => 'points' ) ) ); ?>"><b class="mc-num"><?php echo esc_html( $rank ? '#' . mhp_bn( $rank ) : '—' ); ?></b>সাপ্তাহিক র‍্যাঙ্ক</a>
				<span><b class="mc-num"><?php echo $mhp_ic( 'flame' ) . esc_html( mhp_bn( $streak ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></b>দিনের ধারা</span>
			</div>
		</div>

		<?php if ( $mhp_plan ) : ?>
			<div class="mc-card mc-pad" style="order:2">
				<div class="mc-row mc-between">
					<div>
						<h2 class="mc-dash__h">আজকের পরিকল্পনা</h2>
						<span class="mc-xs mc-muted">দৈনিক লক্ষ্য: <?php echo esc_html( mhp_bn( $today ) . '/' . mhp_bn( $goal ) ); ?> পয়েন্ট<?php echo $today >= $goal ? ' · পূরণ হয়েছে!' : ''; ?></span>
					</div>
					<span class="mc-ring mc-ring--sm" style="--p:<?php echo (int) $mhp_gpct; ?>" role="img" aria-label="<?php echo esc_attr( 'দৈনিক লক্ষ্যের ' . mhp_bn( $mhp_gpct ) . '%' ); ?>"><b><?php echo esc_html( mhp_bn( $mhp_gpct ) ); ?>%</b></span>
				</div>
				<ul class="mc-plan mc-plain">
					<?php foreach ( $mhp_plan as $mhp_p ) : ?>
						<li class="<?php echo $mhp_p[0] ? 'is-done' : ''; ?>">
							<span class="mc-plan__c" aria-hidden="true"><?php echo $mhp_p[0] ? $mhp_ic( 'check' ) : $mhp_ic( $mhp_p[5] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
							<span class="mc-grow"><b class="mc-block"><?php echo esc_html( $mhp_p[1] ); ?><?php echo $mhp_p[0] ? '<span class="screen-reader-text"> (সম্পন্ন)</span>' : ''; ?></b><span class="mc-xs mc-muted mc-clamp"><?php echo esc_html( $mhp_p[2] ); ?></span></span>
							<a class="mc-btn mc-btn--sm <?php echo $mhp_p[0] ? 'mc-btn--ghost' : 'mc-btn--soft'; ?>" href="<?php echo esc_url( $mhp_p[3] ); ?>"><?php echo esc_html( $mhp_p[4] ); ?></a>
						</li>
					<?php endforeach; ?>
				</ul>
				<details class="mc-dash__more">
					<summary class="mc-xs">দৈনিক লক্ষ্য বদলান</summary>
					<form method="post" class="mc-row mc-wrap mc-mt" action="<?php echo esc_url( mhp_url( 'me' ) ); ?>">
						<?php echo MHP_Front::form_fields( 'goal_set' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php foreach ( MHP_Points::GOALS as $mhp_g ) : ?>
							<label class="mc-chip"><input type="radio" name="goal" value="<?php echo (int) $mhp_g; ?>" <?php checked( $goal, $mhp_g ); ?>> <?php echo esc_html( mhp_bn( $mhp_g ) ); ?></label>
						<?php endforeach; ?>
						<button type="submit" class="mc-btn mc-btn--sm">রাখুন</button>
					</form>
				</details>
			</div>
		<?php endif; ?>

		<?php if ( $challenges ) : ?>
			<div class="mc-card mc-pad" style="order:3">
				<div class="mc-row mc-between">
					<h2 class="mc-dash__h">এই সপ্তাহের চ্যালেঞ্জ</h2>
					<span class="mc-xs mc-muted">শনি–শুক্র</span>
				</div>
				<ul class="mc-chal mc-plain">
					<?php foreach ( $challenges as $mhp_c ) : ?>
						<li>
							<div class="mc-row mc-between">
								<span class="mc-sm"><?php echo esc_html( $mhp_c['label'] ); ?></span>
								<?php if ( $mhp_c['won'] ) : ?>
									<span class="mc-pill mc-pill--ok"><?php echo $mhp_ic( 'trophy' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> জিতেছেন</span>
								<?php elseif ( $mhp_c['bonus'] ) : ?>
									<span class="mc-pill mc-pill--gold">+<?php echo esc_html( mhp_bn( $mhp_c['bonus'] ) ); ?></span>
								<?php endif; ?>
							</div>
							<div class="mc-row">
								<div class="mc-meter mc-grow" role="progressbar" aria-valuemin="0" aria-valuemax="<?php echo (int) $mhp_c['target']; ?>" aria-valuenow="<?php echo (int) $mhp_c['done']; ?>" aria-label="<?php echo esc_attr( $mhp_c['label'] ); ?>"><i style="width:<?php echo (int) round( $mhp_c['done'] / max( 1, $mhp_c['target'] ) * 100 ); ?>%"></i></div>
								<span class="mc-xs mc-num"><?php echo esc_html( mhp_bn( $mhp_c['done'] ) . '/' . mhp_bn( $mhp_c['target'] ) ); ?></span>
							</div>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>

		<div class="mc-card mc-pad" style="order:6">
			<h2 class="mc-dash__h">বিষয়ভিত্তিক দক্ষতা</h2>
			<?php if ( $skills ) : ?>
				<div class="mc-col mc-mt">
					<?php foreach ( $skills as $mhp_s ) : ?>
						<div class="mc-subj mc-subj--wide">
							<span><?php echo esc_html( $mhp_s['name'] ); ?> <span class="mc-xs mc-muted">(<?php echo esc_html( mhp_bn( $mhp_s['seen'] ) ); ?>)</span></span>
							<span class="mc-meter<?php echo $mhp_s['pct'] < 50 ? ' mc-meter--bad' : ( $mhp_s['pct'] >= 75 ? ' mc-meter--ok' : '' ); ?>"><i style="width:<?php echo (int) $mhp_s['pct']; ?>%"></i></span>
							<b class="mc-num"><?php echo esc_html( mhp_bn( $mhp_s['pct'] ) ); ?>%</b>
						</div>
					<?php endforeach; ?>
				</div>
				<?php if ( $weak ) : ?>
					<div class="mc-note">
						<b>জোর দিন:</b> <?php echo esc_html( implode( ', ', wp_list_pluck( $weak, 'name' ) ) ); ?>
						<div class="mc-row mc-wrap mc-gap6 mc-mt">
							<?php foreach ( $weak as $mhp_w ) : ?>
								<a class="mc-chip" href="<?php echo esc_url( mhp_url( 'mistakes', '', array( 'subj' => $mhp_w['id'] ) ) ); ?>"><?php echo esc_html( $mhp_w['name'] ); ?>: ভুলগুলো</a>
								<?php if ( MHP_Lessons::any() ) : ?>
									<a class="mc-chip" href="<?php echo esc_url( mhp_url( 'lessons', '', array( 'subj' => $mhp_w['id'] ) ) ); ?>"><?php echo esc_html( $mhp_w['name'] ); ?>: পাঠ</a>
								<?php endif; ?>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endif; ?>
				<p class="mc-xs mc-muted">টেস্ট ও কুইজের উত্তর থেকে হিসাব; বন্ধনীতে মোট উত্তর। কোনো বিষয়ে অন্তত ৫টি উত্তর হলে দুর্বলতা দেখানো হয়।</p>
			<?php else : ?>
				<p class="mc-sm mc-muted">টেস্ট বা কুইজ দিলে এখানে কোন বিষয়ে আপনি কতটা ভালো, তা দেখা যাবে।</p>
			<?php endif; ?>
		</div>

		<div class="mc-card mc-pad" style="order:9">
			<h2 class="mc-dash__h">ব্যাজ</h2>
			<ul class="mc-badges mc-plain">
				<?php foreach ( $badges as $mhp_b ) : ?>
					<li class="<?php echo $mhp_b['on'] ? 'is-on' : ''; ?>" title="<?php echo esc_attr( $mhp_b['desc'] ); ?>">
						<span class="mc-badge__i" aria-hidden="true"><?php echo $mhp_ic( $mhp_b['on'] ? 'star' : 'trophy' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<b><?php echo esc_html( $mhp_b['label'] ); ?></b>
						<span class="mc-xs mc-muted"><?php echo esc_html( $mhp_b['desc'] ); ?><?php echo $mhp_b['on'] ? '' : ' · বাকি'; ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
	</div>

	<aside class="mc-dash__side" aria-label="আরও">

		<div class="mc-card mc-pad mc-target" id="mc-target" style="order:4">
			<?php if ( $target['name'] || null !== $target['days'] ) : ?>
				<span class="mc-xs mc-muted">লক্ষ্য পরীক্ষা</span>
				<b class="mc-block"><?php echo esc_html( $target['name'] ? $target['name'] : 'আমার পরীক্ষা' ); ?></b>
				<?php if ( null !== $target['days'] ) : ?>
					<div class="mc-target__d"><b class="mc-num"><?php echo esc_html( mhp_bn( $target['days'] ) ); ?></b> <?php echo esc_html( 0 === $target['days'] ? 'আজই পরীক্ষা, শুভকামনা!' : 'দিন বাকি' ); ?></div>
					<span class="mc-xs mc-muted"><?php echo esc_html( mhp_fdate( $target['date'] ) ); ?></span>
				<?php endif; ?>
			<?php else : ?>
				<b class="mc-block">লক্ষ্য পরীক্ষা ঠিক করুন</b>
				<span class="mc-xs mc-muted">কোন পরীক্ষার প্রস্তুতি নিচ্ছেন জানালে কাউন্টডাউন আর সেই ট্র্যাকের পাঠ ও টেস্ট আগে দেখাব।</span>
			<?php endif; ?>
			<details class="mc-dash__more"<?php echo ( ! $target['name'] && null === $target['days'] ) ? ' open' : ''; ?>>
				<summary class="mc-xs"><?php echo $target['name'] ? 'বদলান' : 'যোগ করুন'; ?></summary>
				<form method="post" class="mc-col mc-mt" action="<?php echo esc_url( mhp_url( 'me' ) ); ?>">
					<?php echo MHP_Front::form_fields( 'target_set' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<label class="mc-lbl" for="mc-t-name">পরীক্ষার নাম</label>
					<input class="mc-inp" id="mc-t-name" name="t_name" maxlength="80" value="<?php echo esc_attr( $target['name'] ); ?>" placeholder="যেমন: ৪৭তম বিসিএস প্রিলিমিনারি">
					<label class="mc-lbl" for="mc-t-date">তারিখ (জানা থাকলে)</label>
					<input class="mc-inp" id="mc-t-date" name="t_date" type="date" min="<?php echo esc_attr( mhp_today() ); ?>" value="<?php echo esc_attr( $target['date'] ); ?>">
					<?php if ( $mhp_tracks ) : ?>
						<label class="mc-lbl" for="mc-t-track">প্রস্তুতির ট্র্যাক</label>
						<select class="mc-inp" id="mc-t-track" name="t_track">
							<option value="0">বেছে নিন</option>
							<?php foreach ( $mhp_tracks as $mhp_t ) : ?>
								<option value="<?php echo (int) $mhp_t['id']; ?>" <?php selected( (int) $target['track'], $mhp_t['id'] ); ?>><?php echo esc_html( $mhp_t['name'] ); ?></option>
							<?php endforeach; ?>
						</select>
					<?php endif; ?>
					<div class="mc-row">
						<button type="submit" class="mc-btn mc-btn--sm">সংরক্ষণ</button>
						<?php if ( $target['name'] || $target['date'] ) : ?>
							<button type="submit" class="mc-btn mc-btn--sm mc-btn--ghost" name="t_clear" value="1">মুছুন</button>
						<?php endif; ?>
					</div>
				</form>
			</details>
		</div>

		<a class="mc-card mc-cta" href="<?php echo esc_url( mhp_url( 'mistakes' ) ); ?>" style="order:5">
			<span class="mc-mono mc-mono--gold"><?php echo $mhp_ic( 'doc' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			<span class="mc-grow"><b class="mc-block">ভুলের খাতা</b><span class="mc-xs mc-muted"><?php echo esc_html( $open ? mhp_bn( $open ) . 'টি প্রশ্ন আবার অনুশীলনের অপেক্ষায়' : 'টেস্ট ও কুইজের ভুল উত্তর এখানে জমা হয়' ); ?></span></span>
			<?php echo $mhp_ic( 'chev' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</a>

		<?php if ( $jobs || $sessions || $events ) : ?>
			<div class="mc-card mc-pad" style="order:7">
				<h2 class="mc-dash__h">সামনে যা আছে</h2>
				<ul class="mc-plain mc-col mc-mt mc-upc">
					<?php foreach ( $jobs as $mhp_j ) : ?>
						<li><a class="mc-row" href="<?php echo esc_url( mhp_url( 'job', $mhp_j['id'] ) ); ?>"><?php echo $mhp_ic( 'brief' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span class="mc-grow mc-sm mc-clamp"><?php echo esc_html( $mhp_j['title'] ); ?></span><?php echo mhp_dl_pill( $mhp_j['days_left'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a></li>
					<?php endforeach; ?>
					<?php foreach ( $sessions as $mhp_b ) : ?>
						<li><a class="mc-row" href="<?php echo esc_url( mhp_url( 'me', '', array( 'tab' => 'consult' ) ) ); ?>"><?php echo $mhp_ic( 'chat' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span class="mc-grow mc-sm">পরামর্শ সেশন<?php echo $mhp_b['slot'] ? ': ' . esc_html( $mhp_b['slot'] ) : ''; ?></span></a></li>
					<?php endforeach; ?>
					<?php foreach ( $events as $mhp_e ) : ?>
						<li><a class="mc-row" href="<?php echo esc_url( mhp_url( 'event', $mhp_e['id'] ) ); ?>"><?php echo $mhp_ic( 'ticket' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span class="mc-grow mc-sm mc-clamp"><?php echo esc_html( $mhp_e['title'] ); ?></span><span class="mc-xs mc-muted"><?php echo esc_html( mhp_fdate( $mhp_e['date'], false ) ); ?></span></a></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>

		<div class="mc-stat3" style="order:8">
			<a class="mc-card mc-pad mc-center" href="<?php echo esc_url( add_query_arg( 'tab', 'results', mhp_url( 'me' ) ) ); ?>"><b class="mc-num"><?php echo esc_html( mhp_bn( count( $results ) ) ); ?></b><span class="mc-xs mc-muted mc-block">টেস্ট</span></a>
			<div class="mc-card mc-pad mc-center"><b class="mc-num"><?php echo esc_html( $results ? mhp_bn( $mhp_avg ) . '%' : '—' ); ?></b><span class="mc-xs mc-muted mc-block">গড় নম্বর</span></div>
			<a class="mc-card mc-pad mc-center" href="<?php echo esc_url( add_query_arg( 'tab', 'saved', mhp_url( 'me' ) ) ); ?>"><b class="mc-num"><?php echo esc_html( mhp_bn( count( $saved ) ) ); ?></b><span class="mc-xs mc-muted mc-block">সংরক্ষিত চাকরি</span></a>
		</div>

		<?php if ( $recent ) : ?>
			<div class="mc-card mc-pad" style="order:10">
				<h2 class="mc-dash__h">সাম্প্রতিক পয়েন্ট</h2>
				<ul class="mc-plain mc-col mc-mt mc-recent">
					<?php foreach ( $recent as $mhp_x ) : ?>
						<li class="mc-row mc-between mc-sm"><span><?php echo esc_html( isset( $mhp_src[ $mhp_x['source'] ] ) ? $mhp_src[ $mhp_x['source'] ] : $mhp_x['source'] ); ?> <span class="mc-xs mc-muted"><?php echo esc_html( mhp_fdate( $mhp_x['day'], false ) ); ?></span></span><b class="mc-ok mc-num">+<?php echo esc_html( mhp_bn( $mhp_x['points'] ) ); ?></b></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>

		<details class="mc-card mc-pad mc-dash__how" style="order:11">
			<summary><b>পয়েন্ট কীভাবে আসে</b></summary>
			<?php $mhp_ru = MHP_Points::rules(); ?>
			<ul class="mc-sm">
				<li>কুইজ: প্রতি সঠিক উত্তরে <?php echo esc_html( mhp_bn( $mhp_ru['quiz_correct'] ) ); ?>, শেষ করলে আরও <?php echo esc_html( mhp_bn( $mhp_ru['quiz_done'] ) ); ?></li>
				<li>মডেল টেস্ট (প্রতিটি টেস্টের প্রথমবার): প্রতি সঠিক উত্তরে ১, অর্ধেকের বেশি উত্তর দিলে আরও <?php echo esc_html( mhp_bn( $mhp_ru['test_done'] ) ); ?></li>
				<?php if ( MHP_Lessons::any() && $mhp_ru['lesson'] ) : ?>
					<li>পাঠ শেষ: <?php echo esc_html( mhp_bn( $mhp_ru['lesson'] ) ); ?></li>
				<?php endif; ?>
				<li>ভুলের খাতার প্রশ্ন শুধরালে: ১</li>
				<li>টানা ৭ দিন কুইজ: <?php echo esc_html( mhp_bn( $mhp_ru['streak7'] ) ); ?>, টানা ৩০ দিন: <?php echo esc_html( mhp_bn( $mhp_ru['streak30'] ) ); ?></li>
				<li>দৈনিক লক্ষ্য পূরণ: <?php echo esc_html( mhp_bn( $mhp_ru['goal'] ) ); ?>; সাপ্তাহিক চ্যালেঞ্জ: ঘোষিত বোনাস</li>
			</ul>
		</details>

		<div class="mc-col" style="order:12">
			<a class="mc-card mc-cta" href="<?php echo esc_url( add_query_arg( 'tab', 'purchases', mhp_url( 'me' ) ) ); ?>"><span class="mc-mono"><?php echo $mhp_ic( 'book' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span><span class="mc-grow"><b>কেনা প্যাক, বই ও অর্ডার</b><span class="mc-xs mc-muted mc-block">কেনা টেস্ট, প্যাক, প্রকাশনা ও পেমেন্টের হিসাব</span></span><?php echo $mhp_ic( 'chev' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
			<a class="mc-card mc-cta" href="<?php echo esc_url( add_query_arg( 'tab', 'alerts', mhp_url( 'me' ) ) ); ?>"><span class="mc-mono"><?php echo $mhp_ic( 'bell' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span><span class="mc-grow"><b>চাকরির অ্যালার্ট</b><span class="mc-xs mc-muted mc-block">জেলা, ধরন ও শেষ তারিখের রিমাইন্ডার</span></span><?php echo $mhp_ic( 'chev' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
		</div>
	</aside>
</div>
