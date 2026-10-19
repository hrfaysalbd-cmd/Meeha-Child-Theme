<?php
/**
 * Bengali comments (port of the old inc/meeha-comments.php, restyled; owner D).
 *
 * - meeha_comment_callback(): avatar, name, area (B's meeha_comment_author_area(), plain text), author badge,
 *   Bengali relative time (exact date in title), pending notice, reply/edit.
 * - meeha_comment_form_args(): name / email / phone / auto-register / newsletter consent (B), no URL field.
 * - comment_post: optional account (B's meeha_maybe_create_account) + MailPoet only with explicit consent.
 *   The phone number is NEVER stored with the comment (only passed to the account helper on opt-in).
 * - Bengali gettext overrides for core comment strings — front end only, and only while rendering comments
 *   or handling wp-comments-post.php.
 * - Works on `post`, `meeha_photocard` and `meeha_video`.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

/**
 * Post types whose singles show the Bengali comments.
 *
 * @return string[]
 */
function meeha_comments_post_types() {
	return apply_filters( 'meeha_comments_post_types', array( 'post', 'meeha_photocard', 'meeha_video' ) );
}

/**
 * Plain commenter name.
 *
 * @param WP_Comment $comment Comment.
 * @return string
 */
function meeha_comment_display_name( $comment ) {
	$name = trim( wp_strip_all_tags( (string) $comment->comment_author ) );
	return '' === $name ? 'অতিথি পাঠক' : $name;
}

/**
 * "৫ অক্টোবর ২০২৬, ৩:২০ অপরাহ্ণ".
 *
 * @param WP_Comment $comment Comment.
 * @return string
 */
function meeha_comment_bengali_date( $comment ) {
	$ts = strtotime( $comment->comment_date );
	return meeha_bn_digits( gmdate( 'j', $ts ) ) . ' ' . meeha_bengali_month( (int) gmdate( 'n', $ts ) ) . ' ' . meeha_bn_digits( gmdate( 'Y', $ts ) )
		. ', ' . meeha_bn_digits( gmdate( 'g:i', $ts ) ) . ' ' . ( 'am' === gmdate( 'a', $ts ) ? 'পূর্বাহ্ণ' : 'অপরাহ্ণ' );
}

/**
 * wp_list_comments() callback — prints the opening <li>; the walker closes it.
 *
 * @param WP_Comment $comment Comment.
 * @param array      $args    Args.
 * @param int        $depth   Depth.
 */
function meeha_comment_callback( $comment, $args, $depth ) {
	$tag = ( 'div' === $args['style'] ) ? 'div' : 'li';
	if ( in_array( $comment->comment_type, array( 'pingback', 'trackback' ), true ) ) {
		printf( '<%1$s id="comment-%2$d" %3$s><div class="m-cmt__ping"><span>লিংক:</span> ', $tag, (int) $comment->comment_ID, comment_class( 'm-cmt__item m-cmt__item--ping', $comment, null, false ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		comment_author_link( $comment );
		echo '</div>';
		return;
	}
	$post       = get_post( $comment->comment_post_ID );
	$is_author  = $post && $comment->user_id && (int) $comment->user_id === (int) $post->post_author;
	$is_pending = '0' === (string) $comment->comment_approved;
	$size       = $depth > 1 ? 36 : 44;
	$classes    = 'm-cmt__item' . ( ! empty( $args['has_children'] ) ? ' m-cmt__item--parent' : '' ) . ( $is_pending ? ' m-cmt__item--pending' : '' );
	$ts         = (int) get_comment_time( 'U', true, false, $comment );
	$area       = function_exists( 'meeha_comment_author_area' ) ? meeha_comment_author_area( $comment ) : '';
	?>
	<<?php echo $tag; // phpcs:ignore ?> id="comment-<?php comment_ID(); ?>" <?php comment_class( $classes, $comment ); ?>>
		<article class="m-cmt__card" id="div-comment-<?php comment_ID(); ?>">
			<div class="m-cmt__avatar"><?php echo get_avatar( $comment, $size, '', '', array( 'class' => 'm-cmt__avatar-img', 'loading' => 'lazy' ) ); ?></div>
			<div class="m-cmt__main">
				<header class="m-cmt__head">
					<span class="m-cmt__who">
						<span class="m-cmt__name"><?php echo esc_html( meeha_comment_display_name( $comment ) ); ?></span>
						<?php if ( '' !== $area ) : ?>
							<span class="m-cmt__area"><?php echo esc_html( $area ); ?></span>
						<?php endif; ?>
						<?php if ( $is_author ) : ?>
							<span class="m-cmt__badge">লেখক</span>
						<?php endif; ?>
					</span>
					<a class="m-cmt__time" href="<?php echo esc_url( get_comment_link( $comment ) ); ?>"><time datetime="<?php echo esc_attr( get_comment_time( 'c', true, true, $comment ) ); ?>" title="<?php echo esc_attr( meeha_comment_bengali_date( $comment ) ); ?>"><?php echo esc_html( meeha_bengali_time_ago( $ts ) ); ?></time></a>
				</header>
				<?php if ( $is_pending ) : ?>
					<p class="m-cmt__pending" role="status">আপনার মন্তব্যটি মডারেশনের অপেক্ষায় আছে।</p>
				<?php endif; ?>
				<div class="m-cmt__text"><?php comment_text( $comment ); ?></div>
				<?php
				$reply = $is_pending ? '' : get_comment_reply_link(
					array_merge(
						$args,
						array(
							'add_below'     => 'div-comment',
							'depth'         => $depth,
							'max_depth'     => $args['max_depth'],
							'reply_text'    => '<svg class="m-cmt__icon" viewBox="0 0 20 20" width="16" height="16" aria-hidden="true" focusable="false"><path d="M8 5V2L2 8l6 6v-3.1c4.2 0 7.1 1.3 9 4.1-.8-4-3.2-8-9-10Z" fill="currentColor"/></svg><span>উত্তর দিন</span>',
							'reply_to_text' => '%s-এর মন্তব্যের উত্তর দিন',
							'login_text'    => 'উত্তর দিতে লগইন করুন',
							'before'        => '',
							'after'         => '',
						)
					),
					$comment
				);
				$edit  = current_user_can( 'edit_comment', $comment->comment_ID ) ? '<a class="m-cmt__edit" href="' . esc_url( (string) get_edit_comment_link( $comment ) ) . '">সম্পাদনা</a>' : '';
				if ( $reply || $edit ) {
					echo '<footer class="m-cmt__actions">' . $reply . $edit . '</footer>'; // phpcs:ignore WordPress.Security.EscapeOutput -- core link + escaped.
				}
				?>
			</div>
		</article>
	<?php
}

/**
 * comment_form() arguments — Bengali, no URL field, phone/register/consent.
 *
 * @return array
 */
function meeha_comment_form_args() {
	$commenter  = wp_get_current_commenter();
	$user_phone = is_user_logged_in() ? (string) get_user_meta( get_current_user_id(), 'meeha_phone', true ) : '';
	$req_email  = (bool) get_option( 'require_name_email' );
	$req        = '<span class="required" aria-hidden="true">*</span>';
	$post_id    = get_the_ID();
	$opt        = '<span class="m-cmt__opt">(ঐচ্ছিক)</span>';

	$fields = array(
		'author' => '<p class="comment-form-author m-cmt__field"><label for="author">নাম ' . $req . '</label><input id="author" name="author" type="text" value="' . esc_attr( $commenter['comment_author'] ) . '" size="30" maxlength="245" autocomplete="name" required></p>',
		'email'  => '<p class="comment-form-email m-cmt__field"><label for="email">ইমেইল ' . ( $req_email ? $req : $opt ) . '</label><input id="email" name="email" type="email" value="' . esc_attr( $commenter['comment_author_email'] ) . '" size="30" maxlength="100" autocomplete="email" inputmode="email" aria-describedby="m-cmt-note"' . ( $req_email ? ' required' : '' ) . '></p>',
	);
	if ( $user_phone ) {
		$fields['phone'] = '';
	} else {
		$fields['phone'] = '<p class="comment-form-phone m-cmt__field"><label for="m-cmt-phone">ফোন নম্বর ' . $opt . '</label><input id="m-cmt-phone" name="phone" type="tel" value="" size="30" maxlength="20" autocomplete="tel" inputmode="tel"></p>';
	}
	$extra = '';
	if ( ! is_user_logged_in() && function_exists( 'meeha_registration_open' ) && meeha_registration_open() ) {
		$extra .= '<label class="m-check" for="meeha_register"><input id="meeha_register" name="meeha_register" type="checkbox" value="1"> <span>মিহা নিউজে একাউন্ট খুলতে চাই <small>(পাসওয়ার্ড সেট করার লিংক ইমেইলে যাবে, তাই ইমেইল দিতে হবে)</small></span></label>';
	}
	if ( function_exists( 'meeha_mailpoet_consent_field' ) ) {
		$extra .= meeha_mailpoet_consent_field( 'm-cmt' );
	}
	if ( '' !== $extra ) {
		$fields['meeha_extra'] = '<div class="m-cmt__checks">' . $extra . '</div>';
	}

	$login_url   = wp_login_url( get_permalink( $post_id ) );
	$account_url = function_exists( 'meeha_get_account_page_url' ) ? meeha_get_account_page_url( 'my-account' ) : admin_url( 'profile.php' );
	$user        = wp_get_current_user();

	return array(
		'fields'               => $fields,
		'comment_field'        => '<p class="comment-form-comment m-cmt__field m-cmt__field--text"><label for="comment">আপনার মন্তব্য ' . $req . '</label><textarea id="comment" name="comment" rows="4" maxlength="65525" required placeholder="এই লেখা নিয়ে আপনার মতামত লিখুন…"></textarea></p>',
		'class_container'      => 'comment-respond m-cmt__respond',
		'class_form'           => 'comment-form m-cmt__form',
		'title_reply'          => 'একটি মন্তব্য লিখুন',
		/* translators: %s: comment author */
		'title_reply_to'       => '%s-এর মন্তব্যের উত্তর দিন',
		'title_reply_before'   => '<h3 id="reply-title" class="comment-reply-title m-cmt__form-title">',
		'title_reply_after'    => '</h3>',
		'cancel_reply_link'    => 'উত্তর বাতিল',
		'cancel_reply_before'  => ' <small class="m-cmt__cancel">',
		'cancel_reply_after'   => '</small>',
		'label_submit'         => 'মন্তব্য পোস্ট করুন',
		'class_submit'         => 'submit m-btn m-cmt__submit',
		'submit_field'         => '<p class="form-submit m-cmt__submit-row">%1$s %2$s</p>',
		'comment_notes_before' => '<p class="comment-notes m-cmt__note" id="m-cmt-note">আপনার ইমেইল ও ফোন নম্বর প্রকাশ করা হবে না। * চিহ্নিত ঘর পূরণ করা আবশ্যক।</p>',
		'comment_notes_after'  => '',
		'logged_in_as'         => '<p class="logged-in-as m-cmt__note"><strong>' . esc_html( $user->display_name ) . '</strong> হিসেবে মন্তব্য করছেন। <a href="' . esc_url( $account_url ) . '">প্রোফাইল</a> | <a href="' . esc_url( wp_logout_url( get_permalink( $post_id ) ) ) . '">লগআউট</a></p>',
		'must_log_in'          => '<p class="must-log-in m-cmt__note">মন্তব্য করতে হলে আগে <a href="' . esc_url( $login_url ) . '">লগইন করুন</a>।</p>',
	);
}

/**
 * Bengali digits in the text parts (not URLs) of pagination HTML.
 *
 * @param string $html HTML.
 * @return string
 */
function meeha_comments_bn_digits_in_text( $html ) {
	return (string) preg_replace_callback(
		'/>([^<]+)</u',
		function ( $m ) {
			return '>' . meeha_bn_digits( $m[1] ) . '<';
		},
		(string) $html
	);
}

/**
 * Bengali overrides for core comment strings (front end only; hooked only around comment rendering/posting).
 *
 * @param string $translation Translation.
 * @param string $text        Original.
 * @param string $domain      Domain.
 * @return string
 */
function meeha_comments_gettext( $translation, $text, $domain ) {
	if ( 'default' !== $domain ) {
		return $translation;
	}
	static $map = array(
		'Anonymous'                                     => 'অতিথি পাঠক',
		'Your comment is awaiting moderation.'          => 'আপনার মন্তব্যটি মডারেশনের অপেক্ষায় আছে।',
		'Click here to cancel reply.'                   => 'উত্তর বাতিল',
		'Comments are closed.'                          => 'এই লেখায় মন্তব্য বন্ধ আছে।',
		'Comments navigation'                           => 'মন্তব্যের পাতা',
		'<strong>Error:</strong> Please fill the required fields.' => '<strong>ত্রুটি:</strong> নাম ও প্রয়োজনীয় ঘরগুলো পূরণ করুন।',
		'<strong>Error:</strong> Please type your comment text.'  => '<strong>ত্রুটি:</strong> মন্তব্যের ঘরটি খালি।',
		'<strong>Error:</strong> Please enter a valid email address.' => '<strong>ত্রুটি:</strong> সঠিক ইমেইল ঠিকানা লিখুন।',
		'Duplicate comment detected; it looks as though you&#8217;ve already said that!' => 'একই মন্তব্য আগেই পাঠানো হয়েছে।',
		'You are posting comments too quickly. Slow down.' => 'খুব দ্রুত মন্তব্য পাঠাচ্ছেন। একটু পরে আবার চেষ্টা করুন।',
		'Comment Submission Failure'                    => 'মন্তব্য পাঠানো যায়নি',
	);
	return isset( $map[ $text ] ) ? $map[ $text ] : $translation;
}

/**
 * Turn the gettext overrides on (idempotent).
 */
function meeha_comments_gettext_on() {
	if ( ! is_admin() && ! has_filter( 'gettext', 'meeha_comments_gettext' ) ) {
		add_filter( 'gettext', 'meeha_comments_gettext', 10, 3 );
	}
}

/**
 * Turn them off again.
 */
function meeha_comments_gettext_off() {
	remove_filter( 'gettext', 'meeha_comments_gettext', 10 );
}

/* wp-comments-post.php: error pages in Bengali too. */
add_action( 'pre_comment_on_post', 'meeha_comments_gettext_on' );

/* The phone input is never saved as comment data; account + newsletter only on explicit opt-in. */
add_action(
	'comment_post',
	function ( $comment_id, $approved, $data ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- core comment form (no nonce for anonymous commenters).
		if ( is_user_logged_in() ) {
			return;
		}
		$name  = isset( $data['comment_author'] ) ? sanitize_text_field( $data['comment_author'] ) : '';
		$email = isset( $data['comment_author_email'] ) ? sanitize_email( $data['comment_author_email'] ) : '';
		if ( ! empty( $_POST['meeha_register'] ) && function_exists( 'meeha_maybe_create_account' ) ) {
			$phone = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
			meeha_maybe_create_account( $name, $email, $phone );
		}
		if ( function_exists( 'meeha_maybe_subscribe_mailpoet' ) ) {
			meeha_maybe_subscribe_mailpoet( $email, $name, ! empty( $_POST['meeha_newsletter_consent'] ) );
		}
		// phpcs:enable
	},
	10,
	3
);

/* No "save my name" checkbox (legacy behaviour) — only on the front end. */
add_filter(
	'pre_option_show_comments_cookies_opt_in',
	function ( $v ) {
		return is_admin() ? $v : 0;
	}
);

/* Comments bundle on quote singles in <head> (posts load it on demand from the collapsed card; any other template
   that calls comments_template(), e.g. a video single, gets it late from comments.php). */
add_action(
	'wp',
	function () {
		if ( is_singular( array( 'meeha_photocard' ) ) ) {
			$id = get_queried_object_id();
			if ( comments_open( $id ) || get_comments_number( $id ) ) {
				meeha_enqueue_bundle( 'comments' );
				if ( get_option( 'thread_comments' ) && comments_open( $id ) ) {
					wp_enqueue_script( 'comment-reply' );
				}
			}
		}
	},
	20
);
