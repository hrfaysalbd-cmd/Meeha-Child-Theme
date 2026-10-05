<?php
/**
 * Meeha News — header helper functions, used by header.php and inc/admin-settings.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Saved header settings, with defaults so the header looks complete before anything is configured
function meeha_get_header_settings() {
	$settings = get_option( 'meeha_header_settings', array() );

	return array(
		'topbar_enabled'      => ! isset( $settings['topbar_enabled'] ) || $settings['topbar_enabled'],
		'search_enabled'      => ! isset( $settings['search_enabled'] ) || $settings['search_enabled'],
		'account_enabled'     => ! isset( $settings['account_enabled'] ) || $settings['account_enabled'],
		'sticky_enabled'      => ! isset( $settings['sticky_enabled'] ) || $settings['sticky_enabled'],
		'desktop_menu_limit'  => ! empty( $settings['desktop_menu_limit'] ) ? absint( $settings['desktop_menu_limit'] ) : 8,
		'mobile_compact_items' => ! empty( $settings['mobile_compact_items'] ) && is_array( $settings['mobile_compact_items'] )
			? array_map( 'absint', $settings['mobile_compact_items'] )
			: array(),
		'social'              => array(
			'facebook'  => ! empty( $settings['social']['facebook'] ) ? esc_url_raw( $settings['social']['facebook'] ) : '',
			'youtube'   => ! empty( $settings['social']['youtube'] ) ? esc_url_raw( $settings['social']['youtube'] ) : '',
			'twitter'   => ! empty( $settings['social']['twitter'] ) ? esc_url_raw( $settings['social']['twitter'] ) : '',
			'instagram' => ! empty( $settings['social']['instagram'] ) ? esc_url_raw( $settings['social']['instagram'] ) : '',
			'whatsapp'  => ! empty( $settings['social']['whatsapp'] ) ? esc_url_raw( $settings['social']['whatsapp'] ) : '',
		),
		'ad_enabled'          => ! empty( $settings['ad_enabled'] ),
		'ad_size'             => in_array( ( $settings['ad_size'] ?? '' ), array( 'default', 'compact' ), true ) ? $settings['ad_size'] : 'default',
		'ad_image_desktop'    => ! empty( $settings['ad_image_desktop'] ) ? esc_url_raw( $settings['ad_image_desktop'] ) : '',
		'ad_image_mobile'     => ! empty( $settings['ad_image_mobile'] ) ? esc_url_raw( $settings['ad_image_mobile'] ) : '',
		'ad_link'             => ! empty( $settings['ad_link'] ) ? esc_url_raw( $settings['ad_link'] ) : '',
		'ad_alt'              => ! empty( $settings['ad_alt'] ) ? sanitize_text_field( $settings['ad_alt'] ) : 'বিজ্ঞাপন',
		'ad_label_enabled'    => ! isset( $settings['ad_label_enabled'] ) || $settings['ad_label_enabled'],
	);
}

// Converts 0-9 to Bangla digits; date_i18n() translates month/weekday names but not digits
function meeha_bn_number( $string ) {
	$en = array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' );
	$bn = array( '০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯' );
	return str_replace( $en, $bn, $string );
}

// Short date format e.g. "বৃহঃ ১০ সেপ্টেম্বর ২৬" - weekday abbreviation is hardcoded
// since translation files don't guarantee this exact short form
function meeha_get_header_date() {
	$weekday_abbrev = array( 'রবি', 'সোম', 'মঙ্গল', 'বুধ', 'বৃহঃ', 'শুক্র', 'শনি' ); // index 0 = Sunday
	$w              = (int) date_i18n( 'w' ); // 0-6, locale independent
	$day            = date_i18n( 'j' );
	$month          = date_i18n( 'F' ); // translated if site locale is bn_BD
	$year_short     = date_i18n( 'y' );

	$formatted = $weekday_abbrev[ $w ] . ' ' . $day . ' ' . $month . ' ' . $year_short;
	return meeha_bn_number( $formatted );
}

/**
 * Primary menu output. Falls back to top categories if no menu is assigned.
 * $context: 'desktop' or 'mobile' - same menu, different wrapper class.
 * Desktop gets a data-menu-limit attribute that JS reads to move overflow
 * items into a "more" panel so the logo/icons never get squeezed out.
 */
function meeha_render_primary_menu( $context = 'desktop' ) {
	$limit_attr = ( 'desktop' === $context )
		? ' data-menu-limit="' . absint( meeha_get_header_settings()['desktop_menu_limit'] ) . '"'
		: '';

	if ( has_nav_menu( 'primary' ) ) {
		$menu_html = wp_nav_menu( array(
			'theme_location' => 'primary',
			'container'      => false,
			'menu_class'     => 'meeha-nav-menu meeha-nav-menu--' . sanitize_html_class( $context ),
			'fallback_cb'    => false,
			'depth'          => 3,
			'echo'           => false,
		) );

		if ( $limit_attr && $menu_html ) {
			// wp_nav_menu() outputs <ul id="..." class="...">; insert right after
			// the tag rather than assuming attribute order
			$menu_html = preg_replace( '/^<ul/', '<ul' . $limit_attr, $menu_html, 1 );
		}

		echo $menu_html; // phpcs:ignore -- wp_nav_menu()'s own escaped output
		return;
	}

	// fallback: top categories by post count
	$categories = get_categories( array(
		'orderby'    => 'count',
		'order'      => 'DESC',
		'number'     => 12,
		'parent'     => 0,
		'hide_empty' => true,
	) );

	if ( empty( $categories ) ) {
		return;
	}
	?>
	<ul class="meeha-nav-fallback meeha-nav-fallback--<?php echo esc_attr( sanitize_html_class( $context ) ); ?>"<?php echo $limit_attr; // phpcs:ignore ?>>
		<?php foreach ( $categories as $cat ) : ?>
			<li>
				<a href="<?php echo esc_url( get_category_link( $cat ) ); ?>"><?php echo esc_html( $cat->name ); ?></a>
			</li>
		<?php endforeach; ?>
	</ul>
	<?php
}

// Top-level items of the menu assigned to 'primary' - used for the admin
// checkbox list and the mobile compact row, in menu order
function meeha_get_primary_menu_top_items() {
	$locations = get_nav_menu_locations();
	if ( empty( $locations['primary'] ) ) {
		return array();
	}
	$menu = wp_get_nav_menu_object( $locations['primary'] );
	if ( ! $menu ) {
		return array();
	}
	$items = wp_get_nav_menu_items( $menu->term_id );
	if ( ! $items ) {
		return array();
	}
	$top = array();
	foreach ( $items as $item ) {
		if ( 0 === (int) $item->menu_item_parent ) {
			$top[] = $item;
		}
	}
	return $top;
}

// Mobile "floating compact menu" row - admin-selected items + hamburger, second row of header.php
function meeha_render_mobile_compact_menu() {
	$settings  = meeha_get_header_settings();
	$selected  = $settings['mobile_compact_items'];
	if ( empty( $selected ) ) {
		return;
	}

	$top_items = meeha_get_primary_menu_top_items();
	if ( empty( $top_items ) ) {
		return;
	}

	foreach ( $top_items as $item ) {
		if ( ! in_array( (int) $item->ID, $selected, true ) ) {
			continue;
		}
		printf(
			'<li><a href="%1$s">%2$s</a></li>',
			esc_url( $item->url ),
			esc_html( $item->title )
		);
	}
}

// Ad banner below header, using <picture>/<source> so the browser renders
// exactly one image (avoids the double-image bug from CSS-based show/hide)
function meeha_render_header_ad_banner() {
	$settings = meeha_get_header_settings();

	if ( ! $settings['ad_enabled'] || empty( $settings['ad_image_desktop'] ) ) {
		return;
	}

	$alt      = $settings['ad_alt'];
	$has_link = ! empty( $settings['ad_link'] );
	$size_class = 'compact' === $settings['ad_size'] ? ' meeha-header-ad--compact' : '';
	?>
	<div class="meeha-header-ad<?php echo esc_attr( $size_class ); ?>">
		<?php if ( $settings['ad_label_enabled'] ) : ?>
			<span class="meeha-header-ad__label">বিজ্ঞাপন</span>
		<?php endif; ?>

		<?php if ( $has_link ) : ?>
			<a href="<?php echo esc_url( $settings['ad_link'] ); ?>" class="meeha-header-ad__link" target="_blank" rel="noopener noreferrer sponsored">
		<?php endif; ?>

			<picture>
				<?php if ( $settings['ad_image_mobile'] ) : ?>
					<source media="(max-width: 767px)" srcset="<?php echo esc_url( $settings['ad_image_mobile'] ); ?>" />
				<?php endif; ?>
				<img src="<?php echo esc_url( $settings['ad_image_desktop'] ); ?>" alt="<?php echo esc_attr( $alt ); ?>" loading="eager" />
			</picture>

		<?php if ( $has_link ) : ?>
			</a>
		<?php endif; ?>
	</div>
	<?php
}

// Logo URL straight from get_theme_mod('custom_logo') - not the_custom_logo(),
// to avoid its own <a> wrapper (nested-anchor risk) and parent-theme CSS collisions
function meeha_get_header_logo_url() {
	$logo_id = get_theme_mod( 'custom_logo' );
	if ( ! $logo_id ) {
		return '';
	}
	$url = wp_get_attachment_image_url( $logo_id, 'full' );
	return $url ? $url : '';
}

/**
 * Single-post sub-header: category + topic in a bar right below the site
 * header, so both are immediately visible together in one clean strip
 * (separate from the in-article category badges further down the page).
 * Uses get_queried_object_id() rather than the_ID()/the_category() since
 * this renders before the main Loop's the_post() call in single.php.
 */
function meeha_render_single_subheader() {
	if ( ! is_singular( 'post' ) ) {
		return;
	}
	$post_id = get_queried_object_id();
	if ( ! $post_id ) {
		return;
	}
	$cats  = get_the_category( $post_id );
	$topic = meeha_get_topic_label( $post_id );
	if ( empty( $cats ) && ! $topic ) {
		return;
	}
	?>
	<div class="meeha-subheader">
		<div class="meeha-container meeha-subheader__inner">
			<?php if ( ! empty( $cats ) ) : ?>
				<a href="<?php echo esc_url( get_category_link( $cats[0] ) ); ?>" class="meeha-subheader__category"><?php echo esc_html( $cats[0]->name ); ?></a>
			<?php endif; ?>
			<?php if ( $topic ) : ?>
				<?php if ( ! empty( $cats ) ) : ?><span class="meeha-subheader__sep">/</span><?php endif; ?>
				<span class="meeha-subheader__topic"><?php echo esc_html( $topic ); ?></span>
			<?php endif; ?>
		</div>
	</div>
	<?php
}

/**
 * Headline ticker shown site-wide, right below the header. Content is
 * rendered twice back-to-back so the CSS translateX(-50%) animation loops
 * seamlessly with no visible jump/restart (the previous homepage-only
 * version only rendered the list once, which the CSS's -50% loop assumed
 * was duplicated - causing a jump every cycle).
 */
function meeha_render_headline_ticker() {
	$breaking = meeha_get_breaking_settings();
	if ( ! $breaking['enabled'] ) {
		return;
	}
	$args = array(
		'post_type'           => 'post',
		'post_status'         => 'publish',
		'posts_per_page'      => 8,
		'ignore_sticky_posts' => true,
		'no_found_rows'       => true,
		'orderby'             => 'date',
		'order'               => 'DESC',
	);
	// no category chosen in settings -> latest posts across every category,
	// instead of showing nothing (the previous behaviour)
	if ( $breaking['category'] ) {
		$args['cat'] = $breaking['category'];
	}
	$query = new WP_Query( $args );
	if ( ! $query->have_posts() ) {
		wp_reset_postdata();
		return;
	}
	$items = array();
	foreach ( $query->posts as $p ) {
		$items[] = array( 'url' => get_permalink( $p ), 'title' => get_the_title( $p ) );
	}
	wp_reset_postdata();
	?>
	<div class="meeha-ticker" data-meeha-ticker>
		<span class="meeha-ticker__label"><?php echo esc_html( $breaking['label'] ); ?></span>
		<div class="meeha-ticker__track">
			<ul class="meeha-ticker__list">
				<?php for ( $copy = 0; $copy < 2; $copy++ ) : // duplicated for a seamless loop, hide the copy from assistive tech ?>
					<?php foreach ( $items as $item ) : ?>
						<li<?php echo $copy ? ' aria-hidden="true"' : ''; ?>><a href="<?php echo esc_url( $item['url'] ); ?>"><?php echo esc_html( $item['title'] ); ?></a></li>
					<?php endforeach; ?>
				<?php endfor; ?>
			</ul>
		</div>
		<button type="button" class="meeha-ticker__toggle" data-meeha-ticker-toggle aria-label="টিকার থামান" aria-pressed="false">
			<?php meeha_ui_icon( 'pause' ); ?>
		</button>
	</div>
	<?php
}
// width/height also set directly on the SVG as a fallback if CSS is slow to load.
// Inline SVG icons - no icon font, currentColor so CSS controls the color.
// width/height also set directly on the SVG as a fallback if CSS is slow to load.
function meeha_ui_icon( $name ) {
	$icons = array(
		'menu'   => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>',
		'search' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>',
		'close'  => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>',
		'user'   => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>',
		'chevron' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>',
		'pause'   => '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><rect x="6" y="5" width="4" height="14" rx="1"></rect><rect x="14" y="5" width="4" height="14" rx="1"></rect></svg>',
		'play'    => '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M7 5v14l12-7z"></path></svg>',
	);

	if ( isset( $icons[ $name ] ) ) {
		echo $icons[ $name ]; // phpcs:ignore -- fixed hardcoded SVG markup, no user input
	}
}

// Social icons - used in topbar and mobile drawer
function meeha_social_icon( $network ) {
	$icons = array(
		'facebook'  => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M13.5 21v-7.5h2.5l.5-3h-3V8.5c0-.9.25-1.5 1.55-1.5H16.5V4.3c-.28-.04-1.22-.12-2.32-.12-2.3 0-3.88 1.4-3.88 3.98V10.5H8v3h2.3V21h3.2z"/></svg>',
		'youtube'   => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M22 12s0-3.2-.4-4.7c-.24-.87-.9-1.55-1.76-1.79C18.3 5 12 5 12 5s-6.3 0-7.84.51c-.86.24-1.52.92-1.76 1.79C2 8.8 2 12 2 12s0 3.2.4 4.7c.24.87.9 1.53 1.76 1.77C5.7 19 12 19 12 19s6.3 0 7.84-.53c.86-.24 1.52-.9 1.76-1.77.4-1.5.4-4.7.4-4.7zM10 15.2V8.8L15.5 12 10 15.2z"/></svg>',
		'twitter'   => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M18.9 3H21l-5.6 6.4L22 21h-6.2l-4.9-6.4L5.2 21H3l6-6.9L2 3h6.3l4.4 5.9L18.9 3zm-1.1 16h1.2L7.3 5H6l11.8 14z"/></svg>',
		'instagram' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="5"></rect><circle cx="12" cy="12" r="4"></circle><line x1="17.5" y1="6.5" x2="17.5" y2="6.5"></line></svg>',
		'whatsapp'  => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5.1-1.3A10 10 0 1 0 12 2zm0 18.2c-1.6 0-3.1-.4-4.5-1.2l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1 1 12 20.2zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8-.2-.1-.4-.1-.6.1-.2.2-.7.8-.8 1-.2.2-.3.2-.5.1-.2-.1-1-.4-2-1.2-.7-.6-1.2-1.4-1.4-1.6-.2-.2 0-.4.1-.5l.4-.5c.1-.2.2-.3.2-.5.1-.2 0-.4 0-.5-.1-.1-.6-1.5-.9-2-.2-.5-.5-.4-.6-.4h-.5c-.2 0-.5.1-.7.3-.2.2-1 1-1 2.3 0 1.4 1 2.7 1.1 2.9.1.2 2 3 4.8 4.3.7.3 1.2.5 1.6.6.7.2 1.3.2 1.8.1.5-.1 1.5-.6 1.8-1.2.2-.6.2-1.1.2-1.2-.1-.2-.3-.3-.5-.4z"/></svg>',
	);

	if ( isset( $icons[ $network ] ) ) {
		echo $icons[ $network ]; // phpcs:ignore -- fixed hardcoded SVG markup
	}
}
