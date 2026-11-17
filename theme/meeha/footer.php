<?php
/**
 * Site footer: newsletter band (the only MailPoet form on the site), brand + editorial info, three link
 * columns, correspondent card + social, legal bar; then the drawer and search dialogs.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;
?>
<?php
$meeha_footer_ad = function_exists( 'meeha_ad' ) ? meeha_ad( 'footer_leader' ) : ''; // r47
if ( '' !== $meeha_footer_ad ) {
	echo '<div class="m-container">' . $meeha_footer_ad . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built in meeha_ad().
}
?>
<footer class="m-footer" id="m-footer">
	<?php
	get_template_part( 'template-parts/footer/newsletter' );
	get_template_part( 'template-parts/footer/main' );
	get_template_part( 'template-parts/footer/legal' );
	?>
</footer>
<?php
get_template_part( 'template-parts/header/drawer' );
get_template_part( 'template-parts/header/search' );
wp_footer();
?>
</body>
</html>
