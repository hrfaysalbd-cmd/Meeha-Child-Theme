<?php
/**
 * Hub page template (chosen by template_include on the `[meeha_campus]` page, whatever page template is set).
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

meeha_enqueue_bundle( 'campus' );
get_header();
?>
<main id="main" class="m-campus">
	<h1 class="m-vh"><?php echo esc_html( meeha_campus_view_title() ); ?></h1>
	<?php meeha_campus_render(); ?>
</main>
<?php
get_footer();
