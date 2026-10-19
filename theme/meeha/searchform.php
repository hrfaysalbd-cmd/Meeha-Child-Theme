<?php
/**
 * Search form (get_search_form()).
 *
 * @package meeha
 *
 * @var array $args Core search-form args (aria_label).
 */

defined( 'ABSPATH' ) || exit;

$meeha_id = wp_unique_id( 'm-sf-' );
?>
<form role="search" method="get" class="m-searchform" action="<?php echo esc_url( home_url( '/' ) ); ?>"<?php echo ! empty( $args['aria_label'] ) ? ' aria-label="' . esc_attr( $args['aria_label'] ) . '"' : ''; ?>>
	<label class="m-vh" for="<?php echo esc_attr( $meeha_id ); ?>">খুঁজুন</label>
	<input type="search" class="m-input" id="<?php echo esc_attr( $meeha_id ); ?>" name="s" value="<?php echo esc_attr( get_search_query() ); ?>" placeholder="খবর, বিষয় বা লেখকের নাম" enterkeyhint="search">
	<button class="m-btn" type="submit">খুঁজুন</button>
</form>
