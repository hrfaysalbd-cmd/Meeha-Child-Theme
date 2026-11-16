<?php
/**
 * Search results (DESIGN-SPEC §12, proto/search.html). Every filter is a GET parameter (shareable,
 * cacheable, works without JS): s, type, cat, author, when, from, to, sort, paged.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

global $wp_query;
meeha_enqueue_bundle( 'search' );

$meeha_q       = get_search_query( false );
$meeha_f       = meeha_search_filters();
$meeha_terms   = meeha_search_terms( $meeha_q );
$meeha_regex   = meeha_search_highlight_regex( $meeha_terms );
$meeha_total   = (int) $wp_query->found_posts;
$meeha_counts  = $meeha_terms ? meeha_search_type_counts() : array();
$meeha_applied = meeha_search_applied();
$meeha_paged   = max( 1, (int) get_query_var( 'paged' ) );
$meeha_from    = ( $meeha_paged - 1 ) * 10 + 1;
$meeha_to      = $meeha_from + count( $wp_query->posts ) - 1;
$meeha_alts    = array();
foreach ( preg_split( '/\s+/u', trim( $meeha_q ), -1, PREG_SPLIT_NO_EMPTY ) as $meeha_w ) {
	$meeha_alts = array_merge( $meeha_alts, meeha_search_variant_forms( $meeha_w ) );
}
$meeha_alts = array_slice( array_values( array_unique( $meeha_alts ) ), 0, 3 );
if ( $wp_query->posts ) {
	meeha_home_prime( wp_list_pluck( $wp_query->posts, 'ID' ) );
}
get_header();
?>
<main id="main" class="m-search-page">
	<section class="m-searchhead" aria-label="খুঁজুন">
		<div class="m-container">
			<h1 class="m-vh"><?php echo esc_html( '' !== $meeha_q ? '‘' . $meeha_q . '’ খোঁজের ফলাফল' : 'খুঁজুন' ); ?></h1>
			<form class="m-searchhead__form" action="<?php echo esc_url( home_url( '/' ) ); ?>" method="get" role="search">
				<div class="m-searchhead__field"><?php echo meeha_icon( 'search', array( 'size' => 'lg' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><label class="m-vh" for="m-sq">খুঁজুন</label><input id="m-sq" type="search" name="s" value="<?php echo esc_attr( $meeha_q ); ?>" placeholder="খবর, বিষয় বা লেখকের নাম" enterkeyhint="search"></div>
				<button class="m-btn" type="submit">খুঁজুন</button>
			</form>
			<p class="m-searchhead__summary" role="status">
				<?php if ( '' === trim( $meeha_q ) ) : ?>
					কী খুঁজছেন লিখুন — খবর, বিষয়, জায়গা বা লেখকের নাম।
				<?php else : ?>
					‘<b><?php echo esc_html( $meeha_q ); ?></b>’ লিখে খুঁজে <b><?php echo esc_html( meeha_bn_digits( $meeha_total ) ); ?>টি</b> ফলাফল পাওয়া গেছে।
					<?php if ( $meeha_alts ) : ?>
						<span class="m-searchhead__variants"><?php echo esc_html( '‘' . implode( '’ ও ‘', $meeha_alts ) . '’ বানানও খোঁজা হয়েছে।' ); ?></span>
					<?php endif; ?>
				<?php endif; ?>
			</p>
		</div>
	</section>

	<div class="m-container">
		<?php if ( $meeha_terms ) : ?>
		<ul class="m-types" role="list" aria-label="ধরন">
			<?php foreach ( meeha_search_types() as $meeha_slug => $meeha_label ) : ?>
				<?php $meeha_n = isset( $meeha_counts[ $meeha_slug ] ) ? (int) $meeha_counts[ $meeha_slug ] : 0; ?>
				<li><a href="<?php echo esc_url( meeha_search_url( array( 'type' => 'all' === $meeha_slug ? null : $meeha_slug ) ) ); ?>"<?php echo $meeha_slug === $meeha_f['type'] ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $meeha_label ); ?> <b><?php echo esc_html( meeha_bn_digits( $meeha_n ) ); ?></b></a></li>
			<?php endforeach; ?>
		</ul>
		<?php endif; ?>

		<div class="m-results-layout">
			<details class="m-filters" data-open-desktop>
				<summary><span><?php echo meeha_icon( 'filter', array( 'size' => 'sm' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> ফিল্টার</span><?php if ( $meeha_applied ) : ?><b><?php echo esc_html( meeha_bn_digits( count( $meeha_applied ) ) ); ?>টি চালু</b><?php endif; ?></summary>
				<form class="m-filters__body" action="<?php echo esc_url( home_url( '/' ) ); ?>" method="get" data-filters>
					<input type="hidden" name="s" value="<?php echo esc_attr( $meeha_q ); ?>">
					<?php if ( 'all' !== $meeha_f['type'] ) : ?><input type="hidden" name="type" value="<?php echo esc_attr( $meeha_f['type'] ); ?>"><?php endif; ?>
					<?php if ( 'relevance' !== $meeha_f['sort'] ) : ?><input type="hidden" name="sort" value="<?php echo esc_attr( $meeha_f['sort'] ); ?>"><?php endif; ?>
					<fieldset class="m-fgroup"><legend>সময়</legend>
						<?php
						foreach ( array(
							'any'   => 'যেকোনো সময়',
							'1d'    => 'গত ২৪ ঘণ্টা',
							'7d'    => 'গত ৭ দিন',
							'30d'   => 'গত ৩০ দিন',
							'range' => 'নির্দিষ্ট তারিখ',
						) as $meeha_v => $meeha_l ) :
							?>
							<label class="m-radio"><input type="radio" name="when" value="<?php echo esc_attr( $meeha_v ); ?>"<?php checked( $meeha_f['when'], $meeha_v ); ?>> <?php echo esc_html( $meeha_l ); ?></label>
						<?php endforeach; ?>
						<div class="m-daterange" data-daterange><label>শুরু<input class="m-input" type="date" name="from" value="<?php echo esc_attr( $meeha_f['from'] ); ?>"></label><label>শেষ<input class="m-input" type="date" name="to" value="<?php echo esc_attr( $meeha_f['to'] ); ?>"></label></div>
					</fieldset>
					<div class="m-fgroup"><label class="m-fgroup__label" for="f-cat">বিভাগ</label>
						<?php
						wp_dropdown_categories(
							array(
								'name'            => 'cat',
								'id'              => 'f-cat',
								'class'           => 'm-select',
								'show_option_all' => 'সব বিভাগ',
								'hide_empty'      => true,
								'hierarchical'    => true,
								'orderby'         => 'name',
								'selected'        => (int) $meeha_f['cat'],
							)
						);
						?>
					</div>
					<div class="m-fgroup"><label class="m-fgroup__label" for="f-author">লেখক</label>
						<select class="m-select" id="f-author" name="author">
							<option value="">যেকোনো লেখক</option>
							<?php foreach ( meeha_search_authors() as $meeha_uid => $meeha_un ) : ?>
								<option value="<?php echo (int) $meeha_uid; ?>"<?php selected( (int) $meeha_f['author'], (int) $meeha_uid ); ?>><?php echo esc_html( $meeha_un ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<button class="m-btn m-btn--block" type="submit">ফলাফল দেখুন</button>
				</form>
			</details>

			<div>
				<?php if ( $meeha_applied ) : ?>
					<div class="m-applied" aria-label="চালু ফিল্টার">
						<?php foreach ( $meeha_applied as $meeha_a ) : ?>
							<a class="m-chip" href="<?php echo esc_url( $meeha_a[1] ); ?>"><?php echo esc_html( $meeha_a[0] ); ?><?php echo meeha_icon( 'close', array( 'label' => 'সরান' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
						<?php endforeach; ?>
						<a class="m-applied__clear" href="<?php echo esc_url( add_query_arg( 's', rawurlencode( $meeha_q ), home_url( '/' ) ) ); ?>">সব মুছুন</a>
					</div>
				<?php endif; ?>

				<?php if ( $wp_query->posts ) : ?>
					<div class="m-sortbar">
						<span><?php echo esc_html( meeha_bn_digits( $meeha_from ) . '–' . meeha_bn_digits( $meeha_to ) ); ?> দেখানো হচ্ছে</span>
						<form action="<?php echo esc_url( home_url( '/' ) ); ?>" method="get" data-sort>
							<?php
							foreach ( array( 's' => $meeha_q, 'type' => 'all' !== $meeha_f['type'] ? $meeha_f['type'] : '', 'cat' => $meeha_f['cat'] ? $meeha_f['cat'] : '', 'author' => $meeha_f['author'] ? $meeha_f['author'] : '', 'when' => 'any' !== $meeha_f['when'] ? $meeha_f['when'] : '', 'from' => $meeha_f['from'], 'to' => $meeha_f['to'] ) as $meeha_k => $meeha_v ) {
								if ( '' !== (string) $meeha_v ) {
									printf( '<input type="hidden" name="%s" value="%s">', esc_attr( $meeha_k ), esc_attr( $meeha_v ) );
								}
							}
							?>
							<label>সাজান <select name="sort">
								<option value="relevance"<?php selected( $meeha_f['sort'], 'relevance' ); ?>>প্রাসঙ্গিকতা</option>
								<option value="new"<?php selected( $meeha_f['sort'], 'new' ); ?>>নতুন আগে</option>
								<option value="old"<?php selected( $meeha_f['sort'], 'old' ); ?>>পুরোনো আগে</option>
							</select></label>
							<noscript><button class="m-btn m-btn--ghost" type="submit">সাজান</button></noscript>
						</form>
					</div>
					<ol class="m-results" role="list">
						<?php
						foreach ( $wp_query->posts as $meeha_i => $meeha_p ) {
							meeha_search_result( $meeha_p, $meeha_regex, $meeha_i );
						}
						?>
					</ol>
					<?php meeha_pager( $wp_query, array( 'label' => 'ফলাফলের পাতা' ) ); ?>
				<?php elseif ( '' !== trim( $meeha_q ) ) : ?>
					<?php $meeha_sug = meeha_search_suggest( $meeha_q ); ?>
					<div class="m-noresult">
						<h2><?php echo esc_html( '‘' . $meeha_q . '’ লিখে কিছু পাওয়া যায়নি' ); ?></h2>
						<?php if ( $meeha_sug ) : ?>
							<p class="m-dek">আপনি কি ‘<a href="<?php echo esc_url( add_query_arg( 's', rawurlencode( $meeha_sug ), home_url( '/' ) ) ); ?>"><?php echo esc_html( $meeha_sug ); ?></a>’ খুঁজছেন?</p>
						<?php endif; ?>
						<ul>
							<li>বানান বা শব্দ একটু বদলে দেখুন, যেমন ‘ডেঙ্গু’-র বদলে ‘ডেঙ্গি’।</li>
							<li>ফিল্টার কমিয়ে দিন: সময় ‘যেকোনো সময়’ করুন, বিভাগ ও লেখক বাদ দিন।</li>
							<li>ছোট শব্দে খুঁজুন: পুরো বাক্যের বদলে মূল শব্দ লিখুন।</li>
						</ul>
						<a class="m-btn m-btn--ghost" href="<?php echo esc_url( add_query_arg( 's', rawurlencode( $meeha_q ), home_url( '/' ) ) ); ?>">সব ফিল্টার মুছে আবার খুঁজুন</a>
					</div>
				<?php else : ?>
					<?php $meeha_chips = function_exists( 'meeha_search_chips' ) ? meeha_search_chips() : array(); ?>
					<?php if ( $meeha_chips ) : ?>
						<div class="m-noresult">
							<h2>এখন যা বেশি খোঁজা হচ্ছে</h2>
							<div class="m-noresult__actions">
								<?php foreach ( $meeha_chips as $meeha_c ) : ?>
									<a class="m-chip" href="<?php echo esc_url( $meeha_c[1] ); ?>"><?php echo esc_html( $meeha_c[0] ); ?></a>
								<?php endforeach; ?>
							</div>
						</div>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</div>
	</div>
</main>
<?php
get_footer();
