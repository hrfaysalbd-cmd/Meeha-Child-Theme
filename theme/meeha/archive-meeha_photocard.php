<?php
/**
 * উক্তি archive (/quote/) — proto/quotes.html: header, type chips with frame swatches, 12-per-page grid,
 * "আরও উক্তি দেখুন" (JS appends the next page; plain link without JS) + numeric pager.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<main id="main" class="m-quotes-page">
	<header class="m-qhead">
		<div class="m-container m-archhead__inner">
			<?php meeha_breadcrumb(); ?>
			<h1 class="m-archhead__title">উক্তি</h1>
			<?php
			$meeha_intro = trim( (string) meeha_opt( 'quotes', 'archive_intro', '' ) );
			if ( '' !== $meeha_intro ) {
				echo '<p class="m-archhead__desc">' . esc_html( $meeha_intro ) . '</p>';
			}
			echo meeha_quote_type_chips( 0 ); // phpcs:ignore WordPress.Security.EscapeOutput
			?>
		</div>
	</header>
	<?php meeha_quote_archive_grid(); ?>
</main>
<?php
get_footer();
