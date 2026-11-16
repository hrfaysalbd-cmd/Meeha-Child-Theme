<?php
/**
 * Comments thread + form (Bengali). Rendered inside the collapsed card on posts (template-parts/single), directly
 * on quote singles and on newsroom-os video singles (comments_template()).
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

if ( post_password_required() ) {
	return;
}
meeha_comments_gettext_on();
if ( empty( $GLOBALS['meeha_comments_lazy'] ) ) {
	meeha_enqueue_bundle( 'comments' ); // late enqueue (footer) when no template asked for it earlier.
	if ( get_option( 'thread_comments' ) && comments_open() ) {
		wp_enqueue_script( 'comment-reply' );
	}
}
$meeha_cmt_n = (int) get_comments_number();
?>
<div id="comments" class="m-cmt">
	<?php if ( have_comments() ) : ?>
		<p class="m-cmt__count"><?php echo esc_html( meeha_bn_digits( number_format_i18n( $meeha_cmt_n ) ) ); ?>টি মন্তব্য</p>
		<ol class="comment-list m-cmt__list">
			<?php
			wp_list_comments(
				array(
					'style'       => 'ol',
					'short_ping'  => true,
					'avatar_size' => 44,
					'callback'    => 'meeha_comment_callback',
				)
			);
			?>
		</ol>
		<?php
		if ( get_comment_pages_count() > 1 && get_option( 'page_comments' ) ) {
			echo meeha_comments_bn_digits_in_text( // phpcs:ignore WordPress.Security.EscapeOutput -- core markup.
				get_the_comments_pagination(
					array(
						'prev_text'          => 'আগের মন্তব্য',
						'next_text'          => 'পরের মন্তব্য',
						'screen_reader_text' => 'মন্তব্যের পাতা',
						'aria_label'         => 'মন্তব্যের পাতা',
						'class'              => 'm-cmt__pagination',
					)
				)
			);
		}
		?>
	<?php elseif ( comments_open() ) : ?>
		<p class="m-cmt__empty">এখনো কোনো মন্তব্য নেই। প্রথম মন্তব্যটি আপনিই লিখুন।</p>
	<?php endif; ?>

	<?php
	if ( comments_open() ) {
		comment_form( meeha_comment_form_args() );
	} else {
		echo '<p class="m-cmt__closed">এই লেখায় মন্তব্য বন্ধ আছে।</p>';
	}
	?>
</div>
<?php
meeha_comments_gettext_off();
