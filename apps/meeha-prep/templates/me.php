<?php
/**
 * My page. Vars: $uid, $tab, $results, $saved, $orders, $items, $tickets, $streak.
 *
 * @package meeha-prep
 */

defined( 'ABSPATH' ) || exit;

if ( ! $uid ) {
	MHP_Front::login_box( 'আপনার ড্যাশবোর্ড, পয়েন্ট, সংরক্ষিত চাকরি, পরীক্ষার ফল ও কেনাকাটা দেখতে লগইন করুন।' );
	return;
}
$mhp_user = wp_get_current_user();
$mhp_base = mhp_url( 'me' );
$mhp_tabs = array(
	'overview'  => 'ড্যাশবোর্ড',
	'saved'     => 'সংরক্ষিত',
	'results'   => 'ফলাফল',
	'purchases' => 'কেনাকাটা',
	'tickets'   => 'টিকিট',
	'alerts'    => 'অ্যালার্ট',
	'story'     => 'গল্প পাঠান',
);
if ( class_exists( 'MHP_Consult' ) && ( MHP_Consult::any() || MHP_Consult::of_user( $uid ) ) ) {
	$mhp_tabs = array_slice( $mhp_tabs, 0, 5, true ) + array( 'consult' => 'পরামর্শ' ) + array_slice( $mhp_tabs, 5, null, true );
}
if ( class_exists( 'MHP_CV' ) ) {
	$mhp_tabs['cv'] = 'সিভি';
}
$mhp_st   = MHP_Orders::statuses();
?>
<section class="mc-g mc-detail">
	<div class="mc-row">
		<span class="mc-avatar mc-avatar--lg" aria-hidden="true"><?php echo esc_html( mhp_initial( $mhp_user->display_name ) ); ?></span>
		<div class="mc-grow">
			<h1 class="mc-h1"><?php echo esc_html( $mhp_user->display_name ); ?></h1>
			<div class="mc-xs mc-muted"><?php echo esc_html( $mhp_user->user_email ); ?></div>
		</div>
		<?php if ( function_exists( 'meeha_get_account_page_url' ) ) : ?>
			<a class="mc-btn mc-btn--ghost mc-btn--sm" href="<?php echo esc_url( meeha_get_account_page_url( 'my-account' ) ); ?>">প্রোফাইল</a>
		<?php endif; ?>
	</div>
</section>

<nav class="mc-g mc-chips-wrap" aria-label="আমার পাতার অংশ">
	<div class="mc-chips">
		<?php foreach ( $mhp_tabs as $mhp_k => $mhp_l ) : ?>
			<a class="mc-chip<?php echo $tab === $mhp_k ? ' is-on' : ''; ?>" href="<?php echo esc_url( 'overview' === $mhp_k ? $mhp_base : add_query_arg( 'tab', $mhp_k, $mhp_base ) ); ?>"<?php echo $tab === $mhp_k ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $mhp_l ); ?></a>
		<?php endforeach; ?>
	</div>
</nav>

<section class="mc-sec mc-g">
<?php if ( 'overview' === $tab ) : ?>
	<?php
	mhp_template(
		'dashboard',
		array_merge(
			MHP_Learn::dashboard( $uid ),
			array(
				'uid'     => $uid,
				'results' => $results,
				'saved'   => $saved,
				'items'   => $items,
			)
		)
	);
	?>

<?php elseif ( 'saved' === $tab ) : ?>
	<?php
	$mhp_jobs = array();
	foreach ( $saved as $mhp_id ) {
		$mhp_j = MHP_Jobs::data( $mhp_id );
		if ( $mhp_j && 'publish' === $mhp_j['status'] ) {
			$mhp_jobs[] = $mhp_j;
		}
	}
	usort(
		$mhp_jobs,
		function ( $x, $y ) {
			$dx = null === $x['days_left'] ? 9999 : ( $x['days_left'] < 0 ? 99999 : $x['days_left'] );
			$dy = null === $y['days_left'] ? 9999 : ( $y['days_left'] < 0 ? 99999 : $y['days_left'] );
			return $dx - $dy;
		}
	);
	?>
	<?php if ( $mhp_jobs ) : ?>
		<div class="mc-col mc-grid2">
			<?php
			foreach ( $mhp_jobs as $mhp_j ) {
				echo mhp_job_card( $mhp_j, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			?>
		</div>
	<?php else : ?>
		<div class="mc-card mc-pad mc-center mc-muted">কোনো চাকরি সংরক্ষণ করা নেই। <a class="mc-link" href="<?php echo esc_url( mhp_url( 'jobs' ) ); ?>">বিজ্ঞপ্তি দেখুন</a></div>
	<?php endif; ?>

<?php elseif ( 'results' === $tab ) : ?>
	<?php if ( $results ) : ?>
		<div class="mc-card mc-col mc-plain">
			<?php foreach ( $results as $mhp_r ) : ?>
				<a class="mc-row mc-pad mc-between" href="<?php echo esc_url( mhp_url( 'result', $mhp_r['id'] ) ); ?>">
					<span class="mc-grow"><b class="mc-block"><?php echo esc_html( get_the_title( (int) $mhp_r['test_id'] ) ); ?></b><span class="mc-xs mc-muted"><?php echo esc_html( mhp_fdate( substr( get_date_from_gmt( $mhp_r['submitted_at'] ), 0, 10 ) ) ); ?></span></span>
					<b class="mc-num"><?php echo esc_html( mhp_score( $mhp_r['score'] ) . '/' . mhp_bn( $mhp_r['total'] ) ); ?></b>
				</a>
			<?php endforeach; ?>
		</div>
	<?php else : ?>
		<div class="mc-card mc-pad mc-center mc-muted">এখনো কোনো টেস্ট দেননি। <a class="mc-link" href="<?php echo esc_url( mhp_url( 'prep' ) ); ?>">ফ্রি মডেল টেস্ট দিন</a></div>
	<?php endif; ?>

<?php elseif ( 'purchases' === $tab ) : ?>
	<?php if ( $items ) : ?>
		<div class="mc-sech"><h2>আপনার প্যাক ও বই</h2></div>
		<div class="mc-col">
			<?php
			foreach ( $items as $mhp_it ) {
				$mhp_p = get_post( (int) $mhp_it['item_id'] );
				if ( $mhp_p && 'mh_product' === $mhp_p->post_type ) {
					echo mhp_product_card( $mhp_p, $uid ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
			}
			?>
		</div>
	<?php endif; ?>
	<?php
	$mhp_dig  = MHP_Access::of_user( $uid, 'pubd' );
	$mhp_ship = MHP_Pubs::of_user( $uid );
	$mhp_sst  = MHP_Pubs::statuses();
	?>
	<?php if ( $mhp_dig ) : ?>
		<div class="mc-sech"><h2>ডিজিটাল সংস্করণ</h2></div>
		<div class="mc-card mc-col mc-plain">
			<?php foreach ( $mhp_dig as $mhp_dg ) : ?>
				<?php $mhp_pd = MHP_Pubs::data( (int) $mhp_dg['item_id'] ); ?>
				<?php if ( $mhp_pd ) : ?>
					<div class="mc-row mc-pad mc-between">
						<a class="mc-grow" href="<?php echo esc_url( mhp_url( 'pub', $mhp_pd['id'] ) ); ?>"><b class="mc-block"><?php echo esc_html( $mhp_pd['title'] ); ?></b><span class="mc-xs mc-muted"><?php echo esc_html( trim( $mhp_pd['issue'] . ' · ' . $mhp_pd['format'], ' ·' ) ); ?></span></a>
						<a class="mc-btn mc-btn--sm" href="<?php echo esc_url( mhp_url( 'pub-file', $mhp_pd['id'] ) ); ?>">ডাউনলোড</a>
					</div>
				<?php endif; ?>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
	<?php if ( $mhp_ship ) : ?>
		<div class="mc-sech mc-mt"><h2>বইয়ের অর্ডার (প্রিন্ট)</h2></div>
		<div class="mc-col">
			<?php foreach ( $mhp_ship as $mhp_sh ) : ?>
				<?php $mhp_ss = isset( $mhp_sst[ $mhp_sh['status'] ] ) ? $mhp_sst[ $mhp_sh['status'] ] : array( $mhp_sh['status'], 'n' ); ?>
				<div class="mc-card mc-pad">
					<div class="mc-row mc-between">
						<b><?php echo esc_html( get_the_title( (int) $mhp_sh['pub_id'] ) . ' × ' . mhp_bn( $mhp_sh['qty'] ) ); ?></b>
						<span class="mc-pill mc-pill--<?php echo esc_attr( 'ok' === $mhp_ss[1] ? 'ok' : ( 'warn' === $mhp_ss[1] ? 'gold' : ( 'bad' === $mhp_ss[1] ? 'bad' : 'line' ) ) ); ?>"><?php echo esc_html( $mhp_ss[0] ); ?></span>
					</div>
					<p class="mc-xs mc-muted"><?php echo esc_html( 'অর্ডার #' . mhp_bn( $mhp_sh['id'] ) . ' · ' . mhp_fdate( substr( get_date_from_gmt( $mhp_sh['created_at'] ), 0, 10 ) ) . ' · মোট ' . mhp_tk( $mhp_sh['amount'] ) . ( 'cod' === $mhp_sh['pay'] ? ' (ক্যাশ অন ডেলিভারি)' : '' ) ); ?></p>
					<p class="mc-xs"><?php echo esc_html( $mhp_sh['name'] . ', ' . $mhp_sh['address'] . ', ' . $mhp_sh['district'] ); ?></p>
					<?php if ( $mhp_sh['courier'] || $mhp_sh['tracking'] ) : ?>
						<p class="mc-xs"><b>কুরিয়ার:</b> <?php echo esc_html( trim( $mhp_sh['courier'] . ( $mhp_sh['tracking'] ? ' · ট্র্যাকিং ' . $mhp_sh['tracking'] : '' ), ' ·' ) ); ?></p>
					<?php endif; ?>
					<div class="mc-row mc-wrap">
						<?php if ( 'unpaid' === $mhp_sh['status'] ) : ?>
							<a class="mc-btn mc-btn--sm" href="<?php echo esc_url( mhp_url( 'checkout', 'pubp-' . $mhp_sh['id'] ) ); ?>"><?php echo esc_html( mhp_tk( $mhp_sh['amount'] ) ); ?> পরিশোধ করুন</a>
						<?php endif; ?>
						<?php if ( in_array( $mhp_sh['status'], array( 'unpaid', 'cod' ), true ) ) : ?>
							<form method="post" action="<?php echo esc_url( add_query_arg( 'tab', 'purchases', $mhp_base ) ); ?>" data-confirm="অর্ডারটি বাতিল করবেন?" onsubmit="return confirm(this.getAttribute('data-confirm'));">
								<?php echo MHP_Front::form_fields( 'pub_cancel' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<input type="hidden" name="shipment_id" value="<?php echo (int) $mhp_sh['id']; ?>">
								<button type="submit" class="mc-btn mc-btn--sm mc-btn--ghost">বাতিল করুন</button>
							</form>
						<?php endif; ?>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
	<div class="mc-sech mc-mt"><h2>অর্ডারের ইতিহাস</h2></div>
	<?php if ( $orders ) : ?>
		<div class="mc-card mc-col mc-plain">
			<?php foreach ( $orders as $mhp_o ) : ?>
				<?php $mhp_s = isset( $mhp_st[ $mhp_o['status'] ] ) ? $mhp_st[ $mhp_o['status'] ] : array( $mhp_o['status'], 'n' ); ?>
				<a class="mc-row mc-pad mc-between" href="<?php echo esc_url( mhp_url( 'order', $mhp_o['tran_id'] ) ); ?>">
					<span class="mc-grow"><b class="mc-block"><?php echo esc_html( $mhp_o['item_name'] ); ?></b><span class="mc-xs mc-muted"><?php echo esc_html( $mhp_o['tran_id'] . ' · ' . mhp_fdate( substr( get_date_from_gmt( $mhp_o['created_at'] ), 0, 10 ) ) ); ?></span></span>
					<span class="mc-col mc-center"><b><?php echo esc_html( mhp_tk( $mhp_o['amount'] ) ); ?></b><span class="mc-pill mc-pill--<?php echo esc_attr( 'ok' === $mhp_s[1] ? 'ok' : ( 'bad' === $mhp_s[1] ? 'bad' : 'line' ) ); ?>"><?php echo esc_html( $mhp_s[0] ); ?></span></span>
				</a>
			<?php endforeach; ?>
		</div>
	<?php else : ?>
		<div class="mc-card mc-pad mc-center mc-muted">কোনো অর্ডার নেই।</div>
	<?php endif; ?>

<?php elseif ( 'tickets' === $tab ) : ?>
	<?php if ( $tickets ) : ?>
		<div class="mc-col">
			<?php foreach ( $tickets as $mhp_t ) : ?>
				<?php $mhp_e = MHP_Events::data( (int) $mhp_t['item_id'] ); ?>
				<?php if ( $mhp_e ) : ?>
					<a class="mc-card mc-ticket mc-pad" href="<?php echo esc_url( mhp_url( 'event', $mhp_e['id'] ) ); ?>">
						<b class="mc-block"><?php echo esc_html( $mhp_e['title'] ); ?></b>
						<span class="mc-xs mc-muted mc-block"><?php echo esc_html( trim( mhp_fdate( $mhp_e['date'] ) . ' · ' . $mhp_e['time'], ' ·' ) ); ?></span>
						<span class="mc-mono mc-ticket__code"><?php echo esc_html( MHP_Access::ticket_code( (int) $mhp_t['id'], $uid ) ); ?></span>
					</a>
				<?php endif; ?>
			<?php endforeach; ?>
		</div>
	<?php else : ?>
		<div class="mc-card mc-pad mc-center mc-muted">কোনো ইভেন্টে নিবন্ধন নেই। <a class="mc-link" href="<?php echo esc_url( mhp_url( 'events' ) ); ?>">ইভেন্ট দেখুন</a></div>
	<?php endif; ?>

<?php elseif ( 'alerts' === $tab ) : ?>
	<?php
	$mhp_dist  = (string) get_user_meta( $uid, 'mhp_district', true );
	$mhp_types = (array) get_user_meta( $uid, 'mhp_alert_types', true );
	$mhp_rem   = (int) get_user_meta( $uid, 'mhp_remind', true );
	$mhp_terms = get_terms(
		array(
			'taxonomy'   => 'mh_job_type',
			'hide_empty' => false,
		)
	);
	$mhp_mp    = (int) mhp_opt( 'mailpoet_list', 0 ) && class_exists( '\MailPoet\API\API' );
	?>
	<form method="post" class="mc-card mc-pad mc-col" action="<?php echo esc_url( add_query_arg( 'tab', 'alerts', $mhp_base ) ); ?>">
		<?php echo MHP_Front::form_fields( 'alerts_save' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<label class="mc-lbl" for="mc-a-dist">আপনার জেলা</label>
		<select class="mc-inp" id="mc-a-dist" name="district">
			<option value="">বেছে নিন</option>
			<?php foreach ( mhp_districts() as $mhp_d ) : ?>
				<option value="<?php echo esc_attr( $mhp_d ); ?>" <?php selected( $mhp_dist, $mhp_d ); ?>><?php echo esc_html( $mhp_d ); ?></option>
			<?php endforeach; ?>
		</select>
		<span class="mc-xs mc-muted">লিডারবোর্ডে নামের নিচে দেখা যাবে।</span>
		<?php if ( $mhp_terms && ! is_wp_error( $mhp_terms ) ) : ?>
			<fieldset>
				<legend class="mc-lbl">যে ধরনের চাকরিতে আগ্রহী</legend>
				<div class="mc-row mc-wrap mc-gap6">
					<?php foreach ( $mhp_terms as $mhp_term ) : ?>
						<label class="mc-chip"><input type="checkbox" name="types[]" value="<?php echo esc_attr( $mhp_term->slug ); ?>" <?php checked( in_array( $mhp_term->slug, $mhp_types, true ) ); ?>> <?php echo esc_html( $mhp_term->name ); ?></label>
					<?php endforeach; ?>
				</div>
			</fieldset>
		<?php endif; ?>
		<label class="mc-setting"><span class="mc-grow"><b class="mc-block">শেষ তারিখের রিমাইন্ডার</b><span class="mc-xs mc-muted">সংরক্ষিত চাকরির আবেদন শেষ হওয়ার <?php echo esc_html( mhp_bn( (int) mhp_opt( 'reminder_days', 2 ) ) ); ?> দিন আগে ইমেইল</span></span><input type="checkbox" class="mc-tgl" name="remind" value="1" <?php checked( $mhp_rem ); ?>></label>
		<?php if ( $mhp_mp ) : ?>
			<label class="mc-setting"><span class="mc-grow"><b class="mc-block">নতুন বিজ্ঞপ্তির ইমেইল</b><span class="mc-xs mc-muted"><?php echo get_user_meta( $uid, 'mhp_email_list', true ) ? 'আপনি তালিকায় আছেন' : 'সাপ্তাহিক নিয়োগ বিজ্ঞপ্তির তালিকায় যোগ দিন'; ?></span></span><input type="checkbox" class="mc-tgl" name="email_list" value="1"></label>
		<?php endif; ?>
		<button type="submit" class="mc-btn">সংরক্ষণ করুন</button>
	</form>

<?php elseif ( 'consult' === $tab ) : ?>
	<?php
	$mhp_bk  = MHP_Consult::of_user( $uid );
	$mhp_bst = MHP_Consult::statuses();
	$mhp_win = MHP_Consult::windows();
	?>
	<?php if ( ! $mhp_bk ) : ?>
		<div class="mc-card mc-pad mc-center"><p>এখনো কোনো পরামর্শ বুক করেননি।</p><a class="mc-btn mc-btn--soft" href="<?php echo esc_url( mhp_url( 'consult' ) ); ?>">কনসাল্টেন্ট দেখুন</a></div>
	<?php else : ?>
		<div class="mc-col">
			<?php foreach ( $mhp_bk as $mhp_b ) : ?>
				<?php
				$mhp_c = MHP_Consult::data( (int) $mhp_b['consultant_id'], false );
				$mhp_s = isset( $mhp_bst[ $mhp_b['status'] ] ) ? $mhp_bst[ $mhp_b['status'] ] : array( $mhp_b['status'], 'n' );
				?>
				<div class="mc-card mc-pad">
					<div class="mc-row mc-between">
						<b><?php echo esc_html( $mhp_c ? $mhp_c['name'] : 'কনসাল্টেন্ট' ); ?></b>
						<span class="mc-pill mc-pill--<?php echo esc_attr( 'ok' === $mhp_s[1] ? 'ok' : ( 'warn' === $mhp_s[1] ? 'bad' : 'line' ) ); ?>"><?php echo esc_html( $mhp_s[0] ); ?></span>
					</div>
					<p class="mc-xs mc-muted"><?php echo esc_html( $mhp_b['topic'] . ' · পছন্দ: ' . ( $mhp_b['pref_date'] ? mhp_fdate( $mhp_b['pref_date'] ) : '' ) . ' · ' . ( isset( $mhp_win[ $mhp_b['pref_time'] ] ) ? $mhp_win[ $mhp_b['pref_time'] ] : '' ) . ( (float) $mhp_b['fee'] > 0 ? ' · ফি ' . mhp_tk( $mhp_b['fee'] ) : '' ) ); ?></p>
					<?php if ( 'confirmed' === $mhp_b['status'] ) : ?>
						<dl class="mc-kv">
							<?php if ( $mhp_b['slot'] ) : ?>
								<dt>সময়</dt><dd><?php echo esc_html( $mhp_b['slot'] ); ?></dd>
							<?php endif; ?>
							<?php if ( $mhp_b['meet_link'] ) : ?>
								<dt>লিংক</dt><dd><a href="<?php echo esc_url( $mhp_b['meet_link'] ); ?>" target="_blank" rel="noopener nofollow"><?php echo esc_html( wp_parse_url( $mhp_b['meet_link'], PHP_URL_HOST ) ); ?></a></dd>
							<?php endif; ?>
						</dl>
					<?php endif; ?>
					<?php if ( $mhp_b['admin_note'] && in_array( $mhp_b['status'], array( 'confirmed', 'done', 'cancelled' ), true ) ) : ?>
						<p class="mc-sm"><?php echo esc_html( $mhp_b['admin_note'] ); ?></p>
					<?php endif; ?>
					<div class="mc-row mc-wrap">
						<?php if ( 'unpaid' === $mhp_b['status'] ) : ?>
							<a class="mc-btn mc-btn--sm" href="<?php echo esc_url( mhp_url( 'checkout', 'consult-' . $mhp_b['id'] ) ); ?>">ফি পরিশোধ করুন</a>
						<?php endif; ?>
						<?php if ( in_array( $mhp_b['status'], array( 'unpaid', 'requested' ), true ) ) : ?>
							<form method="post" action="<?php echo esc_url( add_query_arg( 'tab', 'consult', $mhp_base ) ); ?>" data-confirm="বুকিংটি বাতিল করবেন?" onsubmit="return confirm(this.getAttribute('data-confirm'));">
								<?php echo MHP_Front::form_fields( 'consult_cancel' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<input type="hidden" name="booking_id" value="<?php echo (int) $mhp_b['id']; ?>">
								<button type="submit" class="mc-btn mc-btn--sm mc-btn--ghost">বাতিল করুন</button>
							</form>
						<?php endif; ?>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

<?php elseif ( 'cv' === $tab ) : ?>
	<?php $mhp_cvs = MHP_CV::of_user( $uid ); ?>
	<div class="mc-card mc-pad mc-center">
		<p><?php echo $mhp_cvs ? esc_html( 'আপনার ' . mhp_bn( count( $mhp_cvs ) ) . 'টি সিভি আছে।' ) : 'এখনো কোনো সিভি বানাননি।'; ?></p>
		<a class="mc-btn" href="<?php echo esc_url( mhp_url( 'cv' ) ); ?>">সিভি স্টুডিওতে যান</a>
	</div>

<?php else : ?>
	<form method="post" class="mc-card mc-pad mc-col" action="<?php echo esc_url( add_query_arg( 'tab', 'story', $mhp_base ) ); ?>">
		<?php echo MHP_Front::form_fields( 'story_submit' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<p class="mc-sm">চাকরি বা ভর্তি পরীক্ষায় সফল হয়েছেন? আপনার প্রস্তুতির গল্প অন্যদের পথ দেখাবে। সম্পাদক যাচাই করে বাছাই করা গল্প "সাফল্যের গল্প" বিভাগে প্রকাশ করবেন।</p>
		<label class="mc-lbl" for="mc-s-name">আপনার নাম</label>
		<input class="mc-inp" id="mc-s-name" name="story_name" required maxlength="100" value="<?php echo esc_attr( $mhp_user->display_name ); ?>">
		<label class="mc-lbl" for="mc-s-exam">কোন পরীক্ষা/পদ</label>
		<input class="mc-inp" id="mc-s-exam" name="exam" maxlength="150" placeholder="যেমন: ৪৪তম বিসিএস, প্রশাসন ক্যাডার">
		<label class="mc-lbl" for="mc-s-body">আপনার গল্প</label>
		<textarea class="mc-inp" id="mc-s-body" name="body" rows="8" required minlength="80" maxlength="5000" placeholder="কীভাবে প্রস্তুতি নিয়েছেন, কী কাজে লেগেছে, নতুনদের জন্য পরামর্শ"></textarea>
		<div class="mc-hp" aria-hidden="true"><label>Website <input name="website" tabindex="-1" autocomplete="off"></label></div>
		<button type="submit" class="mc-btn">পাঠান</button>
	</form>
<?php endif; ?>
</section>
