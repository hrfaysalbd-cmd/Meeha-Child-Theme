<?php
/**
 * Meeha News — মন্তব্য/ফিডব্যাক টেমপ্লেট
 * Child theme version — replace your child theme comments.php with this file.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( post_password_required() ) {
    return;
}

// "Save my name, email, and website..." checkbox is a separate core option,
// not part of the custom comment fields array below - force it off here
add_filter( 'pre_option_show_comments_cookies_opt_in', '__return_zero' );

$meeha_single_settings = function_exists('meeha_get_single_settings') ? meeha_get_single_settings() : array(
    'comments_heading' => 'মন্তব্য'
);
?>

<div id="comments" class="meeha-comments-wrap">

    <h2 class="meeha-comments-heading">
        <?php echo esc_html( $meeha_single_settings['comments_heading'] ); ?>
        <?php if ( get_comments_number() > 0 ) : ?>
            <span class="meeha-comments-count">(<?php echo esc_html( number_format_i18n( get_comments_number() ) ); ?>)</span>
        <?php endif; ?>
    </h2>

    <?php if ( have_comments() ) : ?>

        <?php
        // Comment navigation (previous / next) — Bengali labels
        if ( get_comment_pages_count() > 1 && get_option( 'page_comments' ) ) : ?>
            <nav class="comment-navigation" role="navigation" aria-label="Comment Navigation">
                <div class="nav-previous"><?php previous_comments_link( '← পূর্বের কমেন্ট' ); ?></div>
                <div class="nav-next"><?php next_comments_link( 'পরবর্তী কমেন্ট →' ); ?></div>
            </nav>
        <?php endif; ?>

        <ol class="comment-list">
            <?php
            wp_list_comments( array(
                'style'      => 'ol',
                'short_ping' => true,
                'avatar_size'=> 48,
                'callback'   => null, // use default walker; customize if you want a custom HTML structure
            ) );
            ?>
        </ol>

        <?php
        // Pagination (with Bengali labels)
        the_comments_pagination( array(
            'prev_text' => '← আগের মন্তব্য',
            'next_text' => 'পরের মন্তব্য →',
        ) );
        ?>

    <?php endif; ?>

    <?php
    if ( comments_open() ) :

        // Build custom fields: Name (required), Email (optional), Phone (optional, hidden if already on file). Website removed.
        $current_user_phone = is_user_logged_in() ? get_user_meta( get_current_user_id(), 'meeha_phone', true ) : '';

        $fields = array(
            'author' =>
                '<p class="comment-form-author"><label for="author">Name *</label>' .
                '<input id="author" name="author" type="text" value="' . esc_attr( wp_get_current_commenter()['comment_author'] ?? '' ) . '" size="30" required /></p>',

            'email' =>
                '<p class="comment-form-email"><label for="email">Email</label>' .
                '<input id="email" name="email" type="email" value="' . esc_attr( wp_get_current_commenter()['comment_author_email'] ?? '' ) . '" size="30" /></p>',
        );

        // phone field only shown when not already saved on the logged-in user's profile;
        // if already on file, send it along as a hidden field instead of asking again
        if ( $current_user_phone ) {
            $fields['phone'] = '<input type="hidden" name="phone" value="' . esc_attr( $current_user_phone ) . '" />';
        } else {
            $fields['phone'] =
                '<p class="comment-form-phone"><label for="phone">Phone Number</label>' .
                '<input id="phone" name="phone" type="text" value="" size="30" /></p>';
        }

        // Auto-registration checkbox - only for logged-out commenters, requires an email to work
        if ( ! is_user_logged_in() && meeha_is_auto_registration_enabled() ) {
            $fields['register'] =
                '<p class="comment-form-register"><label for="meeha_register">' .
                '<input id="meeha_register" name="meeha_register" type="checkbox" value="1" onchange="document.getElementById(\'email\').required = this.checked;" /> ' .
                'আপনি কি মিহা নিউজে রেজিস্ট্রেশন করতে চান? (একাউন্টের তথ্য ইমেইলে পাঠানো হবে)</label></p>';
        }

        $comment_field = '<p class="comment-form-comment"><label for="comment">আপনার মন্তব্য *</label><textarea id="comment" name="comment" rows="5" required></textarea></p>';

        comment_form( array(
            'title_reply'   => 'একটি মন্তব্য লিখুন',
            'label_submit'  => 'মন্তব্য পোস্ট করুন',
            'fields'        => $fields,
            'comment_field' => $comment_field,
            'class_submit'  => 'meeha-comment-submit',
            'comment_notes_after' => '<p class="meeha-comment-note">আপনার ইমেইল প্রকাশ করা হবে না। প্রয়োজনীয় ক্ষেত্রগুলো * দ্বারা চিহ্নিত।</p>',
        ) );

    else :
        ?>
        <p class="meeha-comments-closed">এই পোস্টে মন্তব্য বন্ধ আছে।</p>
        <?php
    endif;
    ?>

</div>
