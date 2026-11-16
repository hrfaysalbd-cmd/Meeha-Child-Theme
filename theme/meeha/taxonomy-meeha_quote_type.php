<?php
/**
 * উক্তির ধরন archive (/quote-type/{term}/): same layout as the quote archive, current chip active.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

$meeha_term = get_queried_object();
get_header();
?>
<main id="main" class="m-quotes-page">
	<header class="m-qhead">
		<div class="m-container m-archhead__inner">
			<?php meeha_breadcrumb(); ?>
			<h1 class="m-archhead__title"><?php echo esc_html( $meeha_term ? $meeha_term->name : 'উক্তি' ); ?></h1>
			<?php
			$meeha_desc = $meeha_term ? trim( (string) term_description( $meeha_term ) ) : '';
			if ( '' !== $meeha_desc ) {
				echo '<div class="m-archhead__desc">' . wp_kses_post( $meeha_desc ) . '</div>';
			} else {
				echo '<p class="m-archhead__desc">' . esc_html( 'মোট ' . meeha_bn_digits( (int) ( $meeha_term ? $meeha_term->count : 0 ) ) . 'টি উক্তি' ) . '</p>';
			}
			echo meeha_quote_type_chips( $meeha_term ? (int) $meeha_term->term_id : 0 ); // phpcs:ignore WordPress.Security.EscapeOutput
			?>
		</div>
	</header>
	<?php meeha_quote_archive_grid(); ?>
</main>
<?php
get_footer();
