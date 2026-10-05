<?php
/**
 * Meeha News — two new homepage sections:
 * 1) A 4-column "topic box" row (one headline per chosen category, colored
 *    label overlaid on the image) - matches a common news-portal pattern.
 * 2) A tabbed sidebar (জনপ্রিয়/ট্রেন্ড/নতুন) next to a normal category
 *    block (reuses the existing single-category "lead + list" layout).
 *
 * Both are optional and fully configured in Admin > Meeha হোমপেজ.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ---------- settings ---------- */

function meeha_get_topic_boxes_settings() {
	$settings = get_option( 'meeha_homepage_settings', array() );
	$cats     = array();
	for ( $i = 1; $i <= 4; $i++ ) {
		$cats[] = isset( $settings[ 'topicbox_cat_' . $i ] ) ? (int) $settings[ 'topicbox_cat_' . $i ] : 0;
	}
	return array(
		'enabled'    => ! empty( $settings['topicbox_enabled'] ),
		'categories' => $cats,
	);
}

function meeha_get_tabbed_sidebar_settings() {
	$settings = get_option( 'meeha_homepage_settings', array() );
	return array(
		'enabled'  => ! empty( $settings['tabbed_enabled'] ),
		'category' => isset( $settings['tabbed_category'] ) ? (int) $settings['tabbed_category'] : 0,
		'title'    => ! empty( $settings['tabbed_title'] ) ? sanitize_text_field( $settings['tabbed_title'] ) : '',
	);
}

/* ---------- Section B: 4-column topic boxes ---------- */

function meeha_render_topic_boxes_section() {
	$settings = meeha_get_topic_boxes_settings();
	if ( ! $settings['enabled'] ) {
		return;
	}
	// four fixed accent colors, one per box position (matches the reference layout)
	$palette = array( '#2857A6', '#8A2E2E', '#4E5A2E', '#5B3A29' );
	$boxes   = array();

	foreach ( $settings['categories'] as $i => $cat_id ) {
		if ( ! $cat_id ) {
			continue;
		}
		$q = new WP_Query( array(
			'cat'                 => $cat_id,
			'posts_per_page'      => 1,
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
		) );
		if ( ! $q->have_posts() ) {
			wp_reset_postdata();
			continue;
		}
		$q->the_post();
		$term      = get_term( $cat_id );
		$boxes[]   = array(
			'label'   => ( $term && ! is_wp_error( $term ) ) ? $term->name : '',
			'title'   => get_the_title(),
			'url'     => get_permalink(),
			'image'   => get_the_post_thumbnail_url( get_the_ID(), 'medium_large' ),
			'color'   => $palette[ $i % count( $palette ) ],
		);
		wp_reset_postdata();
	}

	if ( empty( $boxes ) ) {
		return;
	}
	?>
	<section class="meeha-topicboxes">
		<?php foreach ( $boxes as $box ) : ?>
			<a href="<?php echo esc_url( $box['url'] ); ?>" class="meeha-topicbox" style="--meeha-topicbox-color: <?php echo esc_attr( $box['color'] ); ?>;<?php echo $box['image'] ? ' background-image:url(' . esc_url( $box['image'] ) . ');' : ''; ?>">
				<div class="meeha-topicbox__overlay">
					<?php if ( $box['label'] ) : ?><span class="meeha-topicbox__label"><?php echo esc_html( $box['label'] ); ?></span><?php endif; ?>
					<span class="meeha-topicbox__title"><?php echo esc_html( $box['title'] ); ?></span>
				</div>
			</a>
		<?php endforeach; ?>
	</section>
	<?php
}

/* ---------- Section C: tabbed sidebar + category block ---------- */

function meeha_render_tabbed_sidebar_section() {
	$settings = meeha_get_tabbed_sidebar_settings();
	if ( ! $settings['enabled'] ) {
		return;
	}
	$tabs = array(
		'popular' => 'জনপ্রিয়',
		'trend'   => 'ট্রেন্ড',
		'new'     => 'নতুন',
	);
	?>
	<section class="meeha-tabbed-section">
		<div class="meeha-tabbed-section__sidebar">
			<div class="meeha-tabbed-tabs" role="tablist" id="meeha-tabbed-tabs">
				<?php $first = true; ?>
				<?php foreach ( $tabs as $key => $label ) : ?>
					<button type="button" class="meeha-tabbed-tab<?php echo $first ? ' is-active' : ''; ?>" data-tab="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></button>
					<?php $first = false; ?>
				<?php endforeach; ?>
			</div>
			<ol class="meeha-tabbed-list" id="meeha-tabbed-list">
				<?php meeha_render_tabbed_sidebar_items( 'popular' ); ?>
			</ol>
		</div>

		<div class="meeha-tabbed-section__main">
			<?php meeha_render_category_section_block( $settings['category'], $settings['title'] ); ?>
		</div>
	</section>
	<?php
}

/**
 * Same three data sources used both for the initial server render and the
 * AJAX tab switch, so they can never drift out of sync.
 */
function meeha_get_tabbed_sidebar_posts( $tab ) {
	if ( 'popular' === $tab ) {
		// এখন ভিউ-কাউন্ট দিয়ে ডাইনামিক - সাইটজোড়া "সার্বাধিক পঠিত" সেকশনের
		// সাথে সামঞ্জস্যপূর্ণ, একই ডেটা সোর্স (কোনো আলাদা ম্যানুয়াল কিউরেশন
		// লাগে না)
		return meeha_get_dynamic_most_read_posts( 8, 'all' );
	}

	if ( 'trend' === $tab ) {
		// এখন এডিটর ম্যানুয়ালি বেছে দেন (Posts লিস্টের "ট্রেন্ডিং" কলাম/Quick
		// Edit থেকে) - আগে এটা স্বয়ংক্রিয়ভাবে গত ৭ দিনের সবচেয়ে বেশি-দেখা
		// পোস্ট বের করতো, কিন্তু নতুন পোস্টে যথেষ্ট ভিউ জমতে সময় লাগায়
		// প্রায়ই খালি দেখাচ্ছিল।
		return meeha_get_trending_posts( 8 );
	}

	// 'new': plain latest posts
	$q = new WP_Query( array(
		'post_type'      => 'post',
		'post_status'    => 'publish',
		'posts_per_page' => 8,
		'orderby'        => 'date',
		'order'          => 'DESC',
		'no_found_rows'  => true,
	) );
	return $q->posts;
}

function meeha_render_tabbed_sidebar_items( $tab ) {
	$posts = meeha_get_tabbed_sidebar_posts( $tab );
	if ( empty( $posts ) ) {
		echo '<li class="meeha-tabbed-list__empty">এখানে দেখানোর মতো কিছু নেই।</li>';
		return;
	}
	$rank = 1;
	foreach ( $posts as $p ) {
		?>
		<li>
			<span class="meeha-tabbed-list__rank"><?php echo esc_html( meeha_bn_number( $rank ) ); ?></span>
			<a href="<?php echo esc_url( get_permalink( $p ) ); ?>"><?php echo esc_html( get_the_title( $p ) ); ?></a>
		</li>
		<?php
		$rank++;
	}
}

/**
 * "জনপ্রিয়/ট্রেন্ড/নতুন" ট্যাব-উইজেটের কম্প্যাক্ট ভার্সন - শুধু ট্যাব + তালিকা
 * (পাশের ক্যাটাগরি-ব্লক ছাড়া), সিঙ্গেল পোস্ট ও ক্যাটাগরি আর্কাইভ পেজের আসল
 * সাইডবারে (.meeha-sidebar) বসানোর জন্য। হোমপেজের মূল উইজেটের মতোই একই
 * settings/markup/AJAX পুনর্ব্যবহার করে, তাই দুই জায়গায় দুই রকম "জনপ্রিয়"
 * লজিক থাকার ঝুঁকি নেই।
 */
function meeha_render_tabbed_widget_compact() {
	$settings = meeha_get_tabbed_sidebar_settings();
	if ( ! $settings['enabled'] ) {
		return;
	}
	$tabs = array(
		'popular' => 'জনপ্রিয়',
		'trend'   => 'ট্রেন্ড',
		'new'     => 'নতুন',
	);
	?>
	<div class="meeha-sidebar__block meeha-sidebar__block--soft">
		<div class="meeha-tabbed-tabs" role="tablist" id="meeha-tabbed-tabs">
			<?php $first = true; ?>
			<?php foreach ( $tabs as $key => $label ) : ?>
				<button type="button" class="meeha-tabbed-tab<?php echo $first ? ' is-active' : ''; ?>" data-tab="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></button>
				<?php $first = false; ?>
			<?php endforeach; ?>
		</div>
		<ol class="meeha-tabbed-list" id="meeha-tabbed-list">
			<?php meeha_render_tabbed_sidebar_items( 'popular' ); ?>
		</ol>
	</div>
	<?php
}

/**
 * ট্যাব-উইজেটের CSS/JS + AJAX নন্স রেজিস্টার করে - হোমপেজ এনকিউ ফাংশন থেকে এবং
 * সিঙ্গেল/ক্যাটাগরি পেজের এনকিউ ফাংশন থেকে, দুই জায়গা থেকেই কল করা যায়
 * (একই হ্যান্ডেল দুইবার এনকিউ করলে ওয়ার্ডপ্রেস নিজে থেকেই ডিডুপ করে)।
 */
function meeha_enqueue_tabbed_widget_assets( $style_dependency = 'meeha-child-style' ) {
	wp_enqueue_style(
		'meeha-homepage-sections-style',
		MEEHA_CHILD_URI . '/assets/css/meeha-homepage-sections.css',
		array( $style_dependency ),
		MEEHA_CHILD_VERSION
	);
	wp_enqueue_script(
		'meeha-homepage-sections-script',
		MEEHA_CHILD_URI . '/assets/js/meeha-homepage-sections.js',
		array(),
		MEEHA_CHILD_VERSION,
		true
	);
	wp_add_inline_script(
		'meeha-homepage-sections-script',
		'window.meehaTabbedAjax = { url: "' . esc_url( admin_url( 'admin-ajax.php' ) ) . '", nonce: "' . esc_js( wp_create_nonce( 'meeha_tabbed_nonce' ) ) . '" };',
		'before'
	);
}

function meeha_handle_ajax_tabbed_sidebar() {
	check_ajax_referer( 'meeha_tabbed_nonce', 'nonce' );
	$tab = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : 'popular';
	if ( ! in_array( $tab, array( 'popular', 'trend', 'new' ), true ) ) {
		$tab = 'popular';
	}
	ob_start();
	meeha_render_tabbed_sidebar_items( $tab );
	wp_send_json_success( array( 'html' => ob_get_clean() ) );
}
add_action( 'wp_ajax_meeha_tabbed_sidebar', 'meeha_handle_ajax_tabbed_sidebar' );
add_action( 'wp_ajax_nopriv_meeha_tabbed_sidebar', 'meeha_handle_ajax_tabbed_sidebar' );

/**
 * The "lead + list" layout, factored out of meeha_render_category_section()
 * so it can be reused here without a duplicate copy of that markup. Takes a
 * category ID + optional title override directly, rather than a numbered
 * settings block.
 */
function meeha_render_category_section_block( $cat_id, $title_override = '' ) {
	if ( ! $cat_id ) {
		echo '<p class="meeha-account-msg">এখনো কোনো ক্যাটাগরি বাছাই করা হয়নি।</p>';
		return;
	}
	$term = get_term( $cat_id );
	if ( is_wp_error( $term ) || ! $term ) {
		return;
	}
	$title = $title_override ? $title_override : $term->name;

	$query = new WP_Query( array(
		'cat'                 => $cat_id,
		'posts_per_page'      => 5,
		'ignore_sticky_posts' => true,
		'no_found_rows'       => true,
	) );
	if ( ! $query->have_posts() ) {
		wp_reset_postdata();
		return;
	}
	?>
	<div class="meeha-section__head">
		<span class="meeha-eyebrow-bar"></span>
		<h2><?php echo esc_html( $title ); ?></h2>
		<a class="meeha-section__more" href="<?php echo esc_url( get_category_link( $cat_id ) ); ?>">সব দেখুন →</a>
	</div>
	<div class="meeha-feature-list">
		<?php
		$is_lead = true;
		while ( $query->have_posts() ) :
			$query->the_post();
			if ( $is_lead ) :
				$is_lead = false;
				?>
				<a href="<?php the_permalink(); ?>" class="meeha-feature-list__lead">
					<div class="meeha-feature-list__lead-image"><?php meeha_card_thumbnail( 'medium_large' ); ?></div>
					<div class="meeha-feature-list__lead-text">
						<h3 class="meeha-card__title"><?php the_title(); ?></h3>
						<p class="meeha-feature-list__lead-excerpt"><?php echo esc_html( wp_trim_words( get_the_excerpt(), 18 ) ); ?></p>
						<span class="meeha-meta"><?php echo esc_html( meeha_bengali_time_ago( get_the_time( 'U' ) ) ); ?></span>
					</div>
				</a>
				<ul class="meeha-feature-list__rest">
				<?php
			else :
				?>
				<li>
					<a href="<?php the_permalink(); ?>">
						<span class="meeha-feature-list__title"><?php the_title(); ?></span>
						<span class="meeha-meta"><?php echo esc_html( meeha_bengali_time_ago( get_the_time( 'U' ) ) ); ?></span>
					</a>
				</li>
				<?php
			endif;
		endwhile;
		?>
		</ul>
	</div>
	<?php
	wp_reset_postdata();
}

/* ---------- area-based news filter (reuses the existing sidebar widget) ---------- */

/**
 * Wraps the existing meeha_render_area_sidebar() widget (already used on
 * single/category sidebars) in a full-width homepage section. Reuses that
 * exact same markup/AJAX (meeha_handle_area_filter(), already in
 * functions.php) rather than a parallel copy, so there's only one filter
 * to maintain.
 */
function meeha_render_area_filter_section() {
	echo '<section class="meeha-section meeha-area-section">';
	meeha_render_area_sidebar();
	echo '</section>';
}

/* ---------- asset enqueue (front-page.php only) ---------- */

function meeha_enqueue_homepage_sections_assets() {
	if ( ! is_front_page() ) {
		return;
	}
	meeha_enqueue_tabbed_widget_assets( 'meeha-homepage-style' );

	// এরিয়া ভিত্তিক খবর সেকশনটা meeha-single.js-এর area-search হ্যান্ডলার
	// পুনর্ব্যবহার করে (single/category সাইডবারেও একই জেএস ব্যবহার হয়) -
	// হোমপেজে সেটাই যথেষ্ট, পুরো ফাইলটার বাকি অংশ (রিডিং প্রগ্রেস, ফন্ট
	// কন্ট্রোল ইত্যাদি) `if (element)` গার্ড থাকায় হোমপেজে কিছু না পেয়ে
	// নিজে থেকেই কিছু করবে না
	wp_enqueue_script(
		'meeha-single-script',
		MEEHA_CHILD_URI . '/assets/js/meeha-single.js',
		array(),
		MEEHA_CHILD_VERSION,
		true
	);
	wp_add_inline_script(
		'meeha-single-script',
		'window.meehaPollAjax = window.meehaPollAjax || { url: "' . esc_url( admin_url( 'admin-ajax.php' ) ) . '", nonce: "' . esc_js( wp_create_nonce( 'meeha_poll_nonce' ) ) . '" };',
		'before'
	);
}
add_action( 'wp_enqueue_scripts', 'meeha_enqueue_homepage_sections_assets' );
