<?php
/**
 * One event. Vars: $e, $ticket (array|null), $pay_ok.
 *
 * @package meeha-prep
 */

defined( 'ABSPATH' ) || exit;

$mhp_full   = $e['capacity'] && $e['taken'] >= $e['capacity'];
$mhp_over   = null !== $e['days'] && $e['days'] < 0;
$mhp_left   = $e['capacity'] ? max( 0, $e['capacity'] - $e['taken'] ) : null;
$mhp_online = $e['online'] && wp_http_validate_url( $e['online'] );
?>
<div class="mc-g mc-detail">
	<?php if ( 'publish' !== get_post_status( $e['id'] ) ) : ?>
		<div class="mc-note">ইভেন্টটি এখনো প্রকাশিত নয়। শুধু সম্পাদকেরা দেখছেন।</div>
	<?php endif; ?>
	<?php if ( $e['thumb'] ) : ?>
		<div class="mc-hero"><?php echo wp_get_attachment_image( $e['thumb'], 'large', false, array( 'alt' => $e['title'] ) ); ?></div>
	<?php endif; ?>
	<h1 class="mc-h1"><?php echo esc_html( $e['title'] ); ?></h1>
	<div class="mc-card mc-pad mc-mt">
		<dl class="mc-kv">
			<?php if ( mhp_is_date( $e['date'] ) ) : ?>
				<dt>তারিখ</dt><dd><?php echo esc_html( mhp_fdate( $e['date'] ) ); ?></dd>
			<?php endif; ?>
			<?php if ( $e['time'] ) : ?>
				<dt>সময়</dt><dd><?php echo esc_html( $e['time'] ); ?></dd>
			<?php endif; ?>
			<dt>স্থান</dt><dd><?php echo esc_html( $e['venue'] ? $e['venue'] : ( $e['online'] ? 'অনলাইন' : 'পরে জানানো হবে' ) ); ?></dd>
			<dt>ফি</dt><dd><?php echo esc_html( $e['price'] > 0 ? mhp_tk( $e['price'] ) : 'ফ্রি' ); ?></dd>
			<?php if ( null !== $mhp_left && ! $mhp_over ) : ?>
				<dt>আসন</dt><dd><?php echo esc_html( $mhp_left ? mhp_bn( $mhp_left ) . 'টি বাকি' : 'পূর্ণ' ); ?></dd>
			<?php endif; ?>
		</dl>
	</div>
	<?php if ( $e['sponsor'] ) : ?>
		<p class="mc-xs mc-muted"><?php echo esc_html( $e['sponsor'] ); ?></p>
	<?php endif; ?>

	<?php if ( $ticket ) : ?>
		<div class="mc-ticket mc-card mc-pad mc-mt">
			<div class="mc-xs mc-muted">আপনার টিকিট</div>
			<div class="mc-mono mc-mono--lg mc-ticket__code"><?php echo esc_html( $ticket['code'] ); ?></div>
			<div class="mc-xs mc-muted"><?php echo esc_html( wp_get_current_user()->display_name ); ?> · নিবন্ধন <?php echo esc_html( mhp_fdate( substr( get_date_from_gmt( $ticket['created_at'] ), 0, 10 ) ) ); ?></div>
			<?php if ( $mhp_online && ! $mhp_over ) : ?>
				<a class="mc-btn mc-btn--sm mc-mt" href="<?php echo esc_url( $e['online'] ); ?>" target="_blank" rel="noopener nofollow">অনলাইনে যোগ দিন <?php echo mhp_icon( 'ext' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
			<?php endif; ?>
			<p class="mc-xs mc-muted mc-mt">প্রবেশের সময় কোডটি দেখান। স্ক্রিনশট রেখে দিতে পারেন।</p>
		</div>
	<?php endif; ?>

	<?php if ( '' !== trim( wp_strip_all_tags( $e['content'] ) ) ) : ?>
		<div class="mc-prose mc-mt"><?php echo wp_kses_post( wpautop( $e['content'] ) ); ?></div>
	<?php endif; ?>
</div>

<?php if ( ! $ticket ) : ?>
	<div class="mc-sticky mc-g">
		<?php if ( $mhp_over ) : ?>
			<span class="mc-btn mc-grow is-disabled" aria-disabled="true">ইভেন্টটি শেষ হয়েছে</span>
		<?php elseif ( $mhp_full ) : ?>
			<span class="mc-btn mc-grow is-disabled" aria-disabled="true">সব আসন পূর্ণ</span>
		<?php elseif ( ! $e['reg_open'] ) : ?>
			<span class="mc-btn mc-grow is-disabled" aria-disabled="true">নিবন্ধন বন্ধ</span>
		<?php elseif ( ! is_user_logged_in() ) : ?>
			<a class="mc-btn mc-grow" href="<?php echo esc_url( mhp_login_url( mhp_url( 'event', $e['id'] ) ) ); ?>">নিবন্ধন করতে লগইন করুন</a>
		<?php elseif ( $e['price'] <= 0 ) : ?>
			<form method="post" class="mc-grow" action="<?php echo esc_url( mhp_url( 'event', $e['id'] ) ); ?>">
				<?php echo MHP_Front::form_fields( 'checkout' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<input type="hidden" name="item_type" value="event">
				<input type="hidden" name="item_id" value="<?php echo (int) $e['id']; ?>">
				<button type="submit" class="mc-btn mc-btn--block">ফ্রি নিবন্ধন করুন</button>
			</form>
		<?php elseif ( $pay_ok ) : ?>
			<a class="mc-btn mc-grow" href="<?php echo esc_url( mhp_url( 'checkout', 'event-' . $e['id'] ) ); ?>">টিকিট কিনুন · <?php echo esc_html( mhp_tk( $e['price'] ) ); ?></a>
		<?php else : ?>
			<span class="mc-btn mc-grow is-disabled" aria-disabled="true">অনলাইন নিবন্ধন শিগগির চালু হবে</span>
		<?php endif; ?>
	</div>
<?php endif; ?>
