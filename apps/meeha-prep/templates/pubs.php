<?php
/**
 * Publications list. Vars: $items (data rows), $kind, $used (kinds in use).
 *
 * @package meeha-prep
 */

defined( 'ABSPATH' ) || exit;

$mhp_kinds = MHP_Pubs::kinds();
?>
<section class="mc-mast mc-g">
	<div class="mc-mast__eyebrow">শিক্ষা ও ক্যাম্পাস</div>
	<h1 class="mc-mast__title">প্রকাশনা</h1>
	<p class="mc-mast__lede">সাময়িকী, সিলেবাস ও প্রস্তুতির বই। ডিজিটাল সংস্করণ সঙ্গে সঙ্গে ডাউনলোড, প্রিন্ট সংস্করণ বাড়িতে পৌঁছে যায়।</p>
</section>

<?php if ( count( $used ) > 1 ) : ?>
	<nav class="mc-g mc-chips-wrap" aria-label="ধরন">
		<div class="mc-chips">
			<a class="mc-chip<?php echo '' === $kind ? ' is-on' : ''; ?>" href="<?php echo esc_url( mhp_url( 'pubs' ) ); ?>">সব</a>
			<?php foreach ( $mhp_kinds as $mhp_k => $mhp_l ) : ?>
				<?php if ( in_array( $mhp_k, $used, true ) ) : ?>
					<a class="mc-chip<?php echo $kind === $mhp_k ? ' is-on' : ''; ?>" href="<?php echo esc_url( mhp_url( 'pubs', '', array( 'kind' => $mhp_k ) ) ); ?>"><?php echo esc_html( $mhp_l ); ?></a>
				<?php endif; ?>
			<?php endforeach; ?>
		</div>
	</nav>
<?php endif; ?>

<section class="mc-sec mc-g">
	<?php if ( $items ) : ?>
		<div class="mc-pubs">
			<?php foreach ( $items as $mhp_d ) : ?>
				<a class="mc-pubcard" href="<?php echo esc_url( mhp_url( 'pub', $mhp_d['id'] ) ); ?>">
					<span class="mc-cover">
						<?php if ( $mhp_d['thumb'] ) : ?>
							<?php echo wp_get_attachment_image( $mhp_d['thumb'], 'medium', false, array( 'alt' => '', 'loading' => 'lazy' ) ); ?>
						<?php else : ?>
							<span class="mc-cover__ph"><span class="mc-xs"><?php echo esc_html( $mhp_d['kind_label'] ); ?></span><b><?php echo esc_html( $mhp_d['title'] ); ?></b></span>
						<?php endif; ?>
					</span>
					<span class="mc-xs mc-muted"><?php echo esc_html( trim( $mhp_d['kind_label'] . ( $mhp_d['issue'] ? ' · ' . $mhp_d['issue'] : '' ) ) ); ?></span>
					<b class="mc-pubcard__t mc-clamp"><?php echo esc_html( $mhp_d['title'] ); ?></b>
					<span class="mc-pubcard__p">
						<?php if ( $mhp_d['digital'] ) : ?>
							<span><?php echo esc_html( 'ডিজিটাল ' . ( $mhp_d['d_price'] > 0 ? mhp_tk( $mhp_d['d_price'] ) : 'ফ্রি' ) ); ?></span>
						<?php endif; ?>
						<?php if ( $mhp_d['print'] ) : ?>
							<span><?php echo esc_html( 'প্রিন্ট ' . mhp_tk( $mhp_d['p_price'] ) ); ?><?php echo ( $mhp_d['stock_on'] && $mhp_d['stock'] < 1 ) ? ' <em class="mc-bad">(স্টক শেষ)</em>' : ''; ?></span>
						<?php endif; ?>
						<?php if ( ! $mhp_d['digital'] && ! $mhp_d['print'] ) : ?>
							<span class="mc-muted">শিগগির আসছে</span>
						<?php endif; ?>
					</span>
				</a>
			<?php endforeach; ?>
		</div>
	<?php else : ?>
		<div class="mc-card mc-pad mc-center mc-muted">এই ধরনের কোনো প্রকাশনা এখনো নেই।</div>
	<?php endif; ?>
</section>
