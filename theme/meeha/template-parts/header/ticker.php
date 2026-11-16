<?php
/**
 * Breaking / latest ticker. Breaking mode (red, pulsing dot) when a post flagged `_meeha_breaking` is within the
 * window (`_meeha_breaking_at`, else its publish time); otherwise the latest N headlines. CSS marquee; the list is
 * duplicated once (aria-hidden, links tabindex -1) for the seamless loop.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

$meeha_t = meeha_ticker_data();
if ( ! $meeha_t['items'] ) {
	return;
}
$meeha_breaking = 'breaking' === $meeha_t['mode'];
$meeha_label    = $meeha_breaking ? meeha_opt( 'header', 'breaking_label', 'ব্রেকিং' ) : meeha_opt( 'header', 'latest_label', 'সর্বশেষ' );
$meeha_speed    = max( 20, (int) meeha_opt( 'header', 'ticker_speed', 60 ) );
?>
<div class="m-ticker<?php echo $meeha_breaking ? ' m-ticker--breaking' : ''; ?>" role="region" aria-label="<?php echo esc_attr( $meeha_breaking ? 'ব্রেকিং নিউজ' : 'সর্বশেষ শিরোনাম' ); ?>" style="--m-ticker-dur:<?php echo (int) $meeha_speed; ?>s">
	<div class="m-container m-ticker__inner">
		<span class="m-ticker__label"><?php if ( $meeha_breaking ) : ?><span class="m-ticker__dot" aria-hidden="true"></span><?php endif; ?><?php echo esc_html( $meeha_label ); ?></span>
		<div class="m-ticker__viewport" tabindex="0">
			<div class="m-ticker__track">
				<?php for ( $meeha_i = 0; $meeha_i < 2; $meeha_i++ ) : ?>
				<ul class="m-ticker__list" role="list"<?php echo $meeha_i ? ' aria-hidden="true"' : ''; ?>>
					<?php foreach ( $meeha_t['items'] as $meeha_p ) : ?>
						<li><time datetime="<?php echo esc_attr( get_post_time( 'c', true, $meeha_p ) ); ?>"><?php echo esc_html( meeha_post_clock( $meeha_p ) ); ?></time><a href="<?php echo esc_url( get_permalink( $meeha_p ) ); ?>"<?php echo $meeha_i ? ' tabindex="-1"' : ''; ?>><?php echo esc_html( get_the_title( $meeha_p ) ); ?></a></li>
					<?php endforeach; ?>
				</ul>
				<?php endfor; ?>
			</div>
		</div>
	</div>
</div>
