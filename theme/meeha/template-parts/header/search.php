<?php
/**
 * Search overlay (`<dialog>`; opened by the header button, "/" or Ctrl/⌘-K). Without JS the button is a
 * plain link to the results page. Recent searches come from localStorage (meeha.js).
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

$meeha_chips = meeha_search_chips();
?>
<dialog class="m-search" id="m-search" aria-label="খুঁজুন">
	<form class="m-search__form" action="<?php echo esc_url( home_url( '/' ) ); ?>" method="get" role="search">
		<?php echo meeha_icon( 'search', array( 'size' => 'lg' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<label class="m-vh" for="m-search-q">খুঁজুন</label>
		<input class="m-search__field" id="m-search-q" type="search" name="s" placeholder="কী খুঁজছেন?" autocomplete="off" enterkeyhint="search">
		<button class="m-iconbtn" type="button" data-close aria-label="খোঁজ বন্ধ করুন"><?php echo meeha_icon( 'close', array( 'size' => 'lg' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
	</form>
	<div class="m-search__body">
		<?php if ( $meeha_chips ) : ?>
			<p class="m-search__label">এখন যা বেশি খোঁজা হচ্ছে</p>
			<div class="m-search__chips">
				<?php foreach ( $meeha_chips as $meeha_c ) : ?>
					<a class="m-chip" href="<?php echo esc_url( $meeha_c[1] ); ?>"><?php echo esc_html( $meeha_c[0] ); ?></a>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
		<div class="m-search__recentwrap" data-recent hidden>
			<p class="m-search__label">আপনার সাম্প্রতিক খোঁজ <button type="button" class="m-search__clear" data-recent-clear>মুছুন</button></p>
			<ul class="m-search__recent" role="list" data-recent-list data-sprite="<?php echo esc_url( meeha_sprite_url() ); ?>" data-home="<?php echo esc_url( home_url( '/' ) ); ?>"></ul>
		</div>
		<?php do_action( 'meeha_search_dialog_body' ); ?>
		<p class="m-search__hint">যেকোনো পাতায় <kbd>/</kbd> চাপলেই খোঁজ খুলবে। ফলাফলে বিভাগ, তারিখ ও ধরন দিয়ে ছাঁকতে পারবেন।</p>
	</div>
</dialog>
