<?php
/**
 * Meeha News — custom header template
 *
 * Fully replaces the parent (Newsmatic) theme's header.php via WordPress's
 * template hierarchy - a child theme header.php always wins.
 *
 * Wrapper divs: follows the common Underscores/_s convention - #page and
 * #content are opened here, and the parent theme's footer.php is assumed to
 * close both. If the footer HTML looks slightly off (an extra closing tag),
 * share footer.php and it can be reconciled.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$meeha_header     = meeha_get_header_settings();
$meeha_logged_in  = is_user_logged_in();
$meeha_has_social = count( array_filter( $meeha_header['social'] ) ) > 0; // show the list only if at least one link is set
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<link rel="profile" href="https://gmpg.org/xfn/11" />
	<?php wp_head(); ?>
</head>

<body <?php body_class( 'meeha-body' ); ?>>
<?php wp_body_open(); ?>

<a class="meeha-skip-link screen-reader-text" href="#content">প্রধান কন্টেন্টে যান</a>

<div id="page" class="site meeha-site">

	<header id="masthead" class="meeha-header<?php echo $meeha_header['sticky_enabled'] ? ' meeha-header--sticky' : ''; ?>" data-meeha-header>

		<?php if ( $meeha_header['topbar_enabled'] ) : ?>
		<?php
		// Account link is always CSS-hidden on mobile (separate icon in the main
		// row), so an empty right side on mobile means no social links. Desktop
		// also has the account link, so that's factored in too - two classes let
		// each breakpoint center the date correctly when there's nothing else.
		$meeha_topbar_classes = 'meeha-topbar';
		if ( ! $meeha_has_social ) {
			$meeha_topbar_classes .= ' meeha-topbar--center-mobile';
		}
		if ( ! $meeha_has_social && ! $meeha_header['account_enabled'] ) {
			$meeha_topbar_classes .= ' meeha-topbar--center-desktop';
		}
		?>
		<div class="<?php echo esc_attr( $meeha_topbar_classes ); ?>">
			<div class="meeha-container meeha-topbar__inner">
				<div class="meeha-topbar__date">
					<?php echo esc_html( meeha_get_header_date() ); ?>
				</div>

				<div class="meeha-topbar__right" data-meeha-topbar-right>
					<?php if ( $meeha_has_social ) : ?>
					<ul class="meeha-topbar__social">
						<?php foreach ( $meeha_header['social'] as $meeha_net => $meeha_url ) : ?>
							<?php if ( empty( $meeha_url ) ) { continue; } ?>
							<li>
								<a href="<?php echo esc_url( $meeha_url ); ?>" target="_blank" rel="noopener noreferrer" aria-label="<?php echo esc_attr( ucfirst( $meeha_net ) ); ?>">
									<?php meeha_social_icon( $meeha_net ); ?>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
					<?php endif; ?>

					<?php if ( $meeha_header['account_enabled'] ) : ?>
						<?php if ( $meeha_logged_in ) :
							$meeha_user = wp_get_current_user();
							?>
							<div class="meeha-topbar__account">
								<a href="<?php echo esc_url( meeha_get_account_page_url( 'my-account' ) ); ?>" class="meeha-topbar__account-link">
									<?php meeha_ui_icon( 'user' ); ?>
									<span><?php echo esc_html( $meeha_user->display_name ); ?></span>
								</a>
								<a href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>" class="meeha-topbar__logout">লগআউট</a>
							</div>
						<?php else : ?>
							<a href="<?php echo esc_url( meeha_get_account_page_url( 'login' ) ); ?>" class="meeha-topbar__account-link">
								<?php meeha_ui_icon( 'user' ); ?>
								<span>লগ ইন</span>
							</a>
						<?php endif; ?>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php endif; ?>

		<div class="meeha-header__main">
			<div class="meeha-container meeha-header__main-inner">

				<div class="meeha-header__logo">
					<?php $meeha_logo_url = meeha_get_header_logo_url(); ?>
					<a href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="home" class="meeha-logo-link">
						<?php if ( $meeha_logo_url ) : ?>
							<img src="<?php echo esc_url( $meeha_logo_url ); ?>" alt="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" class="meeha-logo-img" />
						<?php else : ?>
							<span class="meeha-logo-text"><?php bloginfo( 'name' ); ?></span>
							<?php $meeha_tagline = get_bloginfo( 'description' ); ?>
							<?php if ( $meeha_tagline ) : ?>
								<span class="meeha-logo-tagline"><?php echo esc_html( $meeha_tagline ); ?></span>
							<?php endif; ?>
						<?php endif; ?>
					</a>
				</div>

				<nav class="meeha-header__nav" aria-label="প্রধান মেনু">
					<?php meeha_render_primary_menu( 'desktop' ); ?>
				</nav>

				<div class="meeha-header__actions">
					<?php if ( $meeha_header['search_enabled'] ) : ?>
					<button type="button" class="meeha-icon-btn meeha-search-toggle" data-meeha-search-toggle aria-expanded="false" aria-controls="meeha-search-panel" aria-label="অনুসন্ধান খুলুন">
						<?php meeha_ui_icon( 'search' ); ?>
					</button>
					<?php endif; ?>

					<?php if ( $meeha_header['account_enabled'] ) : ?>
					<a href="<?php echo esc_url( $meeha_logged_in ? meeha_get_account_page_url( 'my-account' ) : meeha_get_account_page_url( 'login' ) ); ?>" class="meeha-icon-btn meeha-account-toggle" aria-label="একাউন্ট">
						<?php meeha_ui_icon( 'user' ); ?>
					</a>
					<?php endif; ?>
				</div>

			</div>
		</div>

		<?php // Mobile second row: a few chosen menu items + hamburger, hidden entirely on desktop ?>
		<div class="meeha-header__mobile-row">
			<div class="meeha-container meeha-mobile-row__inner">
				<ul class="meeha-mobile-compact-menu">
					<?php meeha_render_mobile_compact_menu(); ?>
				</ul>
				<button type="button" class="meeha-icon-btn meeha-menu-toggle" data-meeha-menu-toggle aria-expanded="false" aria-controls="meeha-mobile-drawer" aria-label="মেনু খুলুন">
					<?php meeha_ui_icon( 'menu' ); ?>
				</button>
			</div>
		</div>

		<?php if ( $meeha_header['search_enabled'] ) : ?>
		<div class="meeha-search-panel" id="meeha-search-panel" data-meeha-search-panel hidden>
			<div class="meeha-container">
				<form role="search" method="get" class="meeha-search-form" action="<?php echo esc_url( home_url( '/' ) ); ?>">
					<?php meeha_ui_icon( 'search' ); ?>
					<input type="search" class="meeha-search-form__input" name="s" placeholder="যা খুঁজছেন লিখুন…" value="<?php echo get_search_query() ? esc_attr( get_search_query() ) : ''; ?>" data-meeha-search-input />
					<button type="submit" class="meeha-search-form__submit">খুঁজুন</button>
				</form>
				<button type="button" class="meeha-icon-btn meeha-search-close" data-meeha-search-close aria-label="অনুসন্ধান বন্ধ করুন">
					<?php meeha_ui_icon( 'close' ); ?>
				</button>
			</div>
		</div>
		<?php endif; ?>

	</header>

	<div class="meeha-drawer-overlay" data-meeha-drawer-overlay hidden></div>

	<div class="meeha-drawer" id="meeha-mobile-drawer" data-meeha-drawer aria-hidden="true">
		<div class="meeha-drawer__head">
			<span class="meeha-drawer__title"><?php bloginfo( 'name' ); ?></span>
			<button type="button" class="meeha-icon-btn meeha-drawer-close" data-meeha-drawer-close aria-label="মেনু বন্ধ করুন">
				<?php meeha_ui_icon( 'close' ); ?>
			</button>
		</div>

		<?php if ( $meeha_header['search_enabled'] ) : ?>
		<form role="search" method="get" class="meeha-drawer__search" action="<?php echo esc_url( home_url( '/' ) ); ?>">
			<?php meeha_ui_icon( 'search' ); ?>
			<input type="search" name="s" placeholder="যা খুঁজছেন লিখুন…" value="<?php echo get_search_query() ? esc_attr( get_search_query() ) : ''; ?>" />
		</form>
		<?php endif; ?>

		<nav class="meeha-drawer__nav" aria-label="মোবাইল মেনু">
			<?php meeha_render_primary_menu( 'mobile' ); ?>
		</nav>

		<?php if ( $meeha_has_social ) : ?>
		<ul class="meeha-drawer__social">
			<?php foreach ( $meeha_header['social'] as $meeha_net => $meeha_url ) : ?>
				<?php if ( empty( $meeha_url ) ) { continue; } ?>
				<li>
					<a href="<?php echo esc_url( $meeha_url ); ?>" target="_blank" rel="noopener noreferrer" aria-label="<?php echo esc_attr( ucfirst( $meeha_net ) ); ?>">
						<?php meeha_social_icon( $meeha_net ); ?>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php endif; ?>
	</div>

	<?php meeha_render_headline_ticker(); ?>

	<?php meeha_render_header_ad_banner(); ?>

	<div id="content" class="site-content meeha-site-content">
<?php
/**
 * header.php ends here - the </div> tags are intentionally left unclosed.
 * #content and #page are closed by the parent theme's footer.php (Underscores/_s
 * convention), followed by </body></html>. Each page template (front-page.php,
 * single.php, category.php, etc.) outputs its own <main> right after
 * get_header(), which lands inside this #content div.
 */
