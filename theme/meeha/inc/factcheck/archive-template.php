<?php
/**
 * /factcheck/ — every fact-check, newest first, with verdict filter chips (?verdict=false).
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

global $wp_query;
meeha_enqueue_bundle( 'archive' );
meeha_enqueue_bundle( 'factcheck' );
$meeha_fc_vk    = sanitize_key( (string) get_query_var( 'verdict' ) );
$meeha_fc_all   = meeha_fc_verdicts();
$meeha_fc_vk    = isset( $meeha_fc_all[ $meeha_fc_vk ] ) ? $meeha_fc_vk : '';
$meeha_fc_cnt   = meeha_fc_counts();
$meeha_fc_base  = home_url( '/' . MEEHA_FC_SLUG . '/' );
$meeha_fc_paged = max( 1, (int) get_query_var( 'paged' ) );
get_header();
?>
<main id="main" class="m-archive-page m-fcarch">
	<header class="m-archhead m-fcarch__head">
		<div class="m-container m-archhead__inner">
			<p class="m-kicker m-archhead__label">মিহা যাচাই</p>
			<h1 class="m-archhead__title">ফ্যাক্টচেক</h1>
			<p class="m-archhead__desc">সামাজিক মাধ্যমে ছড়ানো দাবি, ছবি ও ভিডিও আমরা তথ্যপ্রমাণ দিয়ে যাচাই করি। কোনো দাবি যাচাই করাতে চাইলে <a href="<?php echo esc_url( home_url( '/tips/' ) ); ?>">আমাদের জানান</a>।</p>
			<nav class="m-chiprow m-fcarch__chips" aria-label="রায় অনুযায়ী ছাঁকুন">
				<a class="m-chip" href="<?php echo esc_url( $meeha_fc_base ); ?>"<?php echo '' === $meeha_fc_vk ? ' aria-current="page"' : ''; ?>>সব <span class="m-fcarch__n"><?php echo esc_html( meeha_bn_digits( (int) $meeha_fc_cnt[''] ) ); ?></span></a>
				<?php foreach ( $meeha_fc_all as $meeha_k => $meeha_v ) : ?>
					<a class="m-chip m-fcarch__chip" style="--fc:<?php echo esc_attr( $meeha_v[1] ); ?>" href="<?php echo esc_url( add_query_arg( 'verdict', $meeha_k, $meeha_fc_base ) ); ?>"<?php echo $meeha_k === $meeha_fc_vk ? ' aria-current="page"' : ''; ?>><i aria-hidden="true"></i><?php echo esc_html( $meeha_v[0] ); ?> <span class="m-fcarch__n"><?php echo esc_html( meeha_bn_digits( isset( $meeha_fc_cnt[ $meeha_k ] ) ? (int) $meeha_fc_cnt[ $meeha_k ] : 0 ) ); ?></span></a>
				<?php endforeach; ?>
			</nav>
		</div>
	</header>
	<div class="m-container m-archive">
		<?php if ( $wp_query->posts ) : ?>
			<div class="m-archgrid m-fcarch__grid">
				<?php
				foreach ( $wp_query->posts as $meeha_i => $meeha_p ) {
					meeha_card(
						$meeha_p,
						array(
							'variant'       => 'sm rowm',
							'heading_level' => 2,
							'show_dek'      => true,
							'dek_words'     => 22,
							'eager'         => 0 === $meeha_i,
						)
					);
				}
				?>
			</div>
			<?php meeha_pager( $wp_query ); ?>
		<?php else : ?>
			<div class="m-noresult">
				<h2><?php echo $meeha_fc_vk ? 'এই রায়ের কোনো ফ্যাক্টচেক এখনো নেই' : 'এখনো কোনো ফ্যাক্টচেক প্রকাশিত হয়নি'; ?></h2>
				<p><a href="<?php echo esc_url( $meeha_fc_base ); ?>">সব ফ্যাক্টচেক দেখুন</a></p>
			</div>
		<?php endif; ?>
	</div>
</main>
<?php
get_footer();
