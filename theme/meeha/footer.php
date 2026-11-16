<?php
/**
 * Site footer: newsletter band (the only MailPoet form on the site), brand + editorial info, three link
 * columns, correspondent card + social, legal bar; then the drawer and search dialogs.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;
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
