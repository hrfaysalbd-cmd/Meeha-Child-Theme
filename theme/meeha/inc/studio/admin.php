<?php
/**
 * Studio admin pages: ফটোকার্ড › স্টুডিও · ফ্রেম লাইব্রেরি · তৈরি কার্ড. Post-list row action "ফটোকার্ড".
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'admin_menu',
	function () {
		$hook = add_menu_page( 'ফটোকার্ড স্টুডিও', 'ফটোকার্ড', 'meeha_make_cards', 'meeha-photocard-studio', 'meeha_studio_page', 'dashicons-format-image', 26 );
		add_submenu_page( 'meeha-photocard-studio', 'ফটোকার্ড স্টুডিও', 'স্টুডিও', 'meeha_make_cards', 'meeha-photocard-studio', 'meeha_studio_page' );
		$lib  = add_submenu_page( 'meeha-photocard-studio', 'ফ্রেম লাইব্রেরি', 'ফ্রেম লাইব্রেরি', 'meeha_make_cards', 'meeha-photocard-frames', 'meeha_studio_frames_page' );
		$made = add_submenu_page( 'meeha-photocard-studio', 'তৈরি কার্ড', 'তৈরি কার্ড', 'meeha_make_cards', 'meeha-photocard-made', 'meeha_studio_made_page' );
		$GLOBALS['meeha_studio_hooks'] = array( 'studio' => $hook, 'frames' => $lib, 'made' => $made );
	}
);

/**
 * Asset URL with filemtime version.
 *
 * @param string $rel Relative path.
 * @return string
 */
function meeha_studio_asset_ver( $rel ) {
	$f = MEEHA_DIR . '/' . $rel;
	return file_exists( $f ) ? (string) filemtime( $f ) : MEEHA_VERSION;
}

add_action(
	'admin_enqueue_scripts',
	function ( $hook ) {
		$hooks = isset( $GLOBALS['meeha_studio_hooks'] ) ? $GLOBALS['meeha_studio_hooks'] : array();
		if ( ! in_array( $hook, $hooks, true ) ) {
			return;
		}
		wp_enqueue_style( 'meeha-cards', MEEHA_URI . '/assets/css/cards.css', array(), meeha_studio_asset_ver( 'assets/css/cards.css' ) );
		wp_enqueue_style( 'meeha-studio', MEEHA_URI . '/assets/css/studio.css', array( 'meeha-cards' ), meeha_studio_asset_ver( 'assets/css/studio.css' ) );
		if ( $hook === $hooks['frames'] ) {
			meeha_cards_register_script();
			wp_enqueue_script( 'meeha-studio-lib', MEEHA_URI . '/assets/js/studio.js', array( 'meeha-cards' ), meeha_studio_asset_ver( 'assets/js/studio.js' ), array( 'in_footer' => true ) );
			return;
		}
		if ( $hook !== $hooks['studio'] ) {
			return;
		}
		wp_enqueue_media();
		meeha_cards_register_script();
		wp_enqueue_script( 'meeha-cards' );
		wp_enqueue_script( 'meeha-studio', MEEHA_URI . '/assets/js/studio.js', array( 'meeha-cards', 'media-editor' ), meeha_studio_asset_ver( 'assets/js/studio.js' ), array( 'in_footer' => true ) );
		wp_add_inline_script( 'meeha-studio', 'window.MeehaStudio=' . wp_json_encode( meeha_studio_js_config(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP ) . ';', 'before' );
	}
);

/**
 * Recent cards (attachments with `_meeha_card_type`).
 *
 * @param int $n      Count.
 * @param int $paged  Page.
 * @param string $type Filter.
 * @return WP_Query
 */
function meeha_studio_cards_query( $n, $paged = 1, $type = '' ) {
	$meta = array(
		'key'     => '_meeha_card_type',
		'compare' => 'EXISTS',
	);
	if ( $type ) {
		$meta = array(
			'key'   => '_meeha_card_type',
			'value' => $type,
		);
	}
	return new WP_Query(
		array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => max( 1, (int) $n ),
			'paged'          => max( 1, (int) $paged ),
			'meta_query'     => array( $meta ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'orderby'        => 'date',
			'order'          => 'DESC',
		)
	);
}

/**
 * History rows for JS.
 *
 * @return array
 */
function meeha_studio_history() {
	$n = (int) meeha_studio_opt( 'history', 12 );
	if ( $n < 1 ) {
		return array();
	}
	$out = array();
	foreach ( meeha_studio_cards_query( $n )->posts as $a ) {
		$out[] = array(
			'id'    => $a->ID,
			'url'   => (string) wp_get_attachment_url( $a->ID ),
			'thumb' => (string) wp_get_attachment_image_url( $a->ID, 'thumbnail' ),
			'type'  => (string) get_post_meta( $a->ID, '_meeha_card_type', true ),
			'title' => get_the_title( $a ),
		);
	}
	return $out;
}

/**
 * Configuration handed to studio.js.
 *
 * @return array
 */
function meeha_studio_js_config() {
	$types = array();
	foreach ( meeha_studio_types() as $id => $t ) {
		$types[ $id ] = array(
			'label'   => $t['label'],
			'presets' => meeha_studio_presets_for( $id ),
			'photo'   => (bool) $t['photo'],
			'ad'      => (bool) $t['ad'],
		);
	}
	$frames = array();
	foreach ( meeha_studio_frames_all() as $r ) {
		$frames[] = meeha_studio_frame_public( $r );
	}
	$cats = array();
	foreach ( get_categories( array( 'hide_empty' => false, 'orderby' => 'name' ) ) as $c ) {
		if ( 'uncategorized' === $c->slug ) {
			continue;
		}
		$cats[] = array(
			'id'    => (int) $c->term_id,
			'name'  => html_entity_decode( $c->name, ENT_QUOTES, 'UTF-8' ),
			'color' => meeha_studio_category_color( $c ),
		);
	}
	$today  = meeha_studio_today();
	$offset = meeha_studio_hijri_offset();
	// Deep link ?type=opinion&post=ID (read-only GET, values validated here and again by REST).
	$init_type = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$init_post = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( ! isset( $types[ $init_type ] ) ) {
		$init_type = $init_post ? meeha_studio_type_for_post( $init_post ) : 'morning';
	}
	$now = new DateTime( 'now', new DateTimeZone( 'Asia/Dhaka' ) );
	return array(
		'rest'        => esc_url_raw( rest_url( 'meeha/v1/' ) ),
		'wpRest'      => esc_url_raw( rest_url( 'wp/v2/' ) ),
		'nonce'       => wp_create_nonce( 'wp_rest' ),
		'types'       => $types,
		'sizes'       => meeha_studio_sizes(),
		'frames'      => $frames,
		'frameBase'   => MEEHA_URI . '/assets/frames/',
		'canUpload'   => meeha_studio_can_upload_frames(),
		'canManage'   => meeha_studio_can_manage(),
		'canSave'     => current_user_can( 'upload_files' ),
		'ads'         => meeha_studio_ad_creatives(),
		'adDefault'   => (bool) meeha_studio_opt( 'ad_default', 0 ),
		'today'       => $today,
		'now'         => $now->format( 'H:i' ),
		'dates'       => meeha_studio_dates( $today, $offset ),
		'hijriOffset' => $offset,
		'titles'      => array_values( meeha_studio_enabled_list( 'morning_titles' ) ),
		'condPre'     => array_values( meeha_studio_enabled_list( 'condolence_pre' ) ),
		'defaults'    => array(
			'credit'             => (string) meeha_studio_opt( 'default_credit', 'মিহা নিউজ' ),
			'opinion_label'      => (string) meeha_studio_opt( 'opinion_label', 'মতামত' ),
			'feature_kicker'     => (string) meeha_studio_opt( 'feature_kicker', 'মিহা স্পেশাল' ),
			'condolence_heading' => (string) meeha_studio_opt( 'condolence_heading', 'গভীর শোক' ),
			'condolence_message' => (string) meeha_studio_opt( 'condolence_message', '' ),
			'birthday_heading'   => (string) meeha_studio_opt( 'birthday_heading', 'শুভ জন্মদিন' ),
			'birthday_message'   => (string) meeha_studio_opt( 'birthday_message', '' ),
		),
		'categories'  => $cats,
		'history'     => meeha_studio_history(),
		'historyMax'  => (int) meeha_studio_opt( 'history', 12 ),
		'quoteFrames' => meeha_studio_quote_frames(),
		'maxBytes'    => MEEHA_FRAME_MAX_BYTES,
		'init'        => array(
			'type' => $init_type,
			'post' => $init_post,
		),
		'madeUrl'     => admin_url( 'admin.php?page=meeha-photocard-made' ),
		'days'        => meeha_studio_days(),
		'newsDefault' => (string) meeha_studio_opt( 'news_default_frame', 'classic' ),
	);
}

/**
 * Studio page.
 */
function meeha_studio_page() {
	if ( ! current_user_can( 'meeha_make_cards' ) ) {
		wp_die( esc_html__( 'অনুমতি নেই।', 'meeha' ) );
	}
	$types = meeha_studio_types();
	$sizes = meeha_studio_sizes();
	$cfg   = meeha_studio_js_config();
	?>
	<div class="wrap ms-wrap" id="meeha-studio">
		<h1>ফটোকার্ড স্টুডিও</h1>
		<p class="page-desc">ছবি বাছুন, ফ্রেম বাছুন, লেখা দিন। ডান পাশে সঙ্গে সঙ্গে কার্ড তৈরি হবে; ব্রাউজারেই PNG ডাউনলোড হবে।</p>
		<noscript><div class="notice notice-error"><p>স্টুডিও চালাতে ব্রাউজারে জাভাস্ক্রিপ্ট চালু রাখুন।</p></div></noscript>
		<nav class="nav-tab-wrapper ms-tabs" aria-label="কার্ডের ধরন">
			<?php foreach ( $types as $id => $t ) : ?>
				<a class="nav-tab<?php echo $id === $cfg['init']['type'] ? ' nav-tab-active' : ''; ?>" data-type="<?php echo esc_attr( $id ); ?>" href="<?php echo esc_url( meeha_studio_url( $id ) ); ?>"<?php echo $id === $cfg['init']['type'] ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $t['label'] ); ?></a>
			<?php endforeach; ?>
		</nav>
		<div class="studio">
			<form class="controls" id="ms-form" onsubmit="return false" novalidate>

				<div class="postbox" data-for="news opinion feature breaking category"><h2>লেখা খুঁজুন <span class="ms-opt">(ঐচ্ছিক)</span></h2><div class="inside">
					<div class="field"><label for="ms-post">পোস্ট আইডি বা লিংক</label>
						<div class="inline"><input id="ms-post" type="text" inputmode="url" autocomplete="off" placeholder="যেমন ১১৮৭ বা https://meeha.news/…"><button class="button button-primary" type="button" id="ms-fetch">তথ্য আনুন</button></div>
						<span class="desc">শিরোনাম, লেখকের নাম, উপাধি, ছবি নিজে থেকে বসবে; পরে বদলানো যাবে।</span>
					</div>
					<div class="fetched" id="ms-fetched" hidden></div>
				</div></div>

				<div class="postbox" data-for="quote"><h2>উক্তি বাছুন</h2><div class="inside">
					<div class="field"><label for="ms-qsearch">উক্তি খুঁজুন</label>
						<div class="inline"><input id="ms-qsearch" type="search" autocomplete="off" placeholder="উক্তি বা বক্তার নাম"><button class="button" type="button" id="ms-qgo">খুঁজুন</button></div>
					</div>
					<ul class="ms-qlist" id="ms-qlist" role="listbox" aria-label="উক্তি"></ul>
					<p class="desc">উক্তি বাছলে লেখা, বক্তা, পরিচয় ও ধরন অনুযায়ী ফ্রেম বসে যাবে। নিচে নিজেও লেখা যায়।</p>
				</div></div>

				<div class="postbox" data-for="news morning feature breaking condolence category birthday day"><h2><span class="ms-n"></span>ছবি</h2><div class="inside">
					<div class="mediapick">
						<span class="ms-thumb" id="ms-thumb" aria-hidden="true"></span>
						<div class="field">
							<span class="inline"><button class="button" type="button" id="ms-pick"><span class="dashicons dashicons-format-image" aria-hidden="true"></span>মিডিয়া লাইব্রেরি থেকে বাছুন</button><button class="button-link ms-remove" type="button" id="ms-unpick" hidden>সরান</button></span>
							<span class="desc" id="ms-imginfo">ছবি বাছাই করা হয়নি।</span>
						</div>
					</div>
					<div class="field ms-zoom"><label for="ms-zoom">জুম <output id="ms-zoomv">১০০%</output></label><input id="ms-zoom" type="range" min="100" max="200" step="1" value="100"><span class="desc">প্রিভিউয়ের ছবিতে টেনে ফোকাস ঠিক করুন। <button type="button" class="button-link" id="ms-refocus">মাঝখানে ফেরান</button></span></div>
					<p class="desc" data-for="condolence">ছবি স্বয়ংক্রিয়ভাবে সাদা-কালো হবে।</p>
					<p class="desc" data-for="birthday">যাঁর জন্মদিন তাঁর ছবি (গোল করে বসবে)। না দিলে নামের প্রথম অক্ষর।</p>
					<p class="desc" data-for="day">ছবি ঐচ্ছিক — না দিলে থিমের নিজস্ব নকশা বসবে।</p>
					<p class="desc" data-for="breaking">ছবি ঐচ্ছিক — না দিলে শিরোনাম বড় হয়ে পুরো কার্ডে বসবে।</p>
				</div></div>

				<div class="postbox"><h2><span class="ms-n"></span>ফ্রেম</h2><div class="inside">
					<ul class="frames" id="ms-frames" role="radiogroup" aria-label="ফ্রেম"></ul>
					<div class="ms-upload" id="ms-upload"<?php echo $cfg['canUpload'] ? '' : ' hidden'; ?>>
						<div class="field"><label for="ms-flabel">নতুন ফ্রেমের নাম</label><input id="ms-flabel" type="text" maxlength="60" placeholder="যেমন বিজয় দিবস ২০২৬"></div>
						<input type="file" id="ms-ffile" accept="image/png" hidden>
					</div>
					<div class="ms-ftext" id="ms-ftext" hidden>
						<p class="ms-ftext__h">নিজের ফ্রেমের লেখা</p>
						<div class="field"><span class="lbl" id="ms-ft-ink-l">লেখার রং</span><div class="radios" role="radiogroup" aria-labelledby="ms-ft-ink-l"><label><input type="radio" name="ms-ft-ink" value="auto" checked>স্বয়ংক্রিয়</label><label><input type="radio" name="ms-ft-ink" value="light">সাদা</label><label><input type="radio" name="ms-ft-ink" value="dark">গাঢ়</label></div>
							<span class="desc">স্বয়ংক্রিয়: ফ্রেম ও ছবির যে অংশে লেখা বসে, তার উজ্জ্বলতা দেখে সাদা বা গাঢ় রং।</span></div>
						<div class="field"><span class="lbl" id="ms-ft-pos-l">লেখার অবস্থান</span><div class="radios" role="radiogroup" aria-labelledby="ms-ft-pos-l"><label><input type="radio" name="ms-ft-pos" value="bottom" checked>নিচে</label><label><input type="radio" name="ms-ft-pos" value="middle">মাঝে</label><label><input type="radio" name="ms-ft-pos" value="top">ওপরে</label></div></div>
						<div class="field"><span class="lbl">যা দেখাবে</span><div class="radios" id="ms-ft-show"></div></div>
					</div>
					<p class="field"><span class="desc" id="ms-framehelp">নিজের ফ্রেম: স্বচ্ছ PNG, ঠিক নির্বাচিত মাপের (১০৮০ × ১০৮০ ইত্যাদি), সর্বোচ্চ ১ এমবি। ফ্রেম ছবির ওপরে বসে, লেখার নিচে।</span></p>
					<p class="ms-msg" id="ms-framemsg" role="status" aria-live="polite"></p>
				</div></div>

				<div class="postbox"><h2><span class="ms-n"></span>লেখা</h2><div class="inside">
					<!-- নিউজ কার্ড -->
					<div class="field" data-for="news"><label for="ms-n-title">শিরোনাম</label><textarea id="ms-n-title" data-k="title" rows="3"></textarea><span class="desc">৬২ থেকে ৪২ পিক্সেল, সর্বোচ্চ ৩–৪ লাইন; না ধরলে ‘…’।</span></div>
					<div class="row2" data-for="news">
						<div class="field"><label for="ms-n-cat">বিভাগ</label><input id="ms-n-cat" type="text" data-k="category" maxlength="40"></div>
						<div class="field"><label for="ms-n-date">তারিখ</label><input id="ms-n-date" type="text" data-k="date" maxlength="40"></div>
					</div>
					<!-- জন্মদিন -->
					<div class="field" data-for="birthday"><label for="ms-d-head">শিরোনাম</label><input id="ms-d-head" type="text" data-k="heading" maxlength="40"></div>
					<div class="row2" data-for="birthday">
						<div class="field"><label for="ms-d-name">নাম</label><input id="ms-d-name" type="text" data-k="name"></div>
						<div class="field"><label for="ms-d-role">পরিচয়</label><input id="ms-d-role" type="text" data-k="role"></div>
					</div>
					<div class="field" data-for="birthday"><label for="ms-d-msg">শুভেচ্ছা বার্তা</label><textarea id="ms-d-msg" data-k="message" rows="2"></textarea></div>
					<!-- দিবস -->
					<div class="field" data-for="day"><label for="ms-y-pick">দিবস বাছুন</label><select id="ms-y-pick"></select><span class="desc">তালিকা বদলানো যায়: মিহা থিম › ফটোকার্ড › দিবসের তালিকা।</span></div>
					<div class="field" data-for="day"><label for="ms-y-title">শিরোনাম</label><input id="ms-y-title" type="text" data-k="title" maxlength="80"></div>
					<div class="field" data-for="day"><label for="ms-y-sub">উপশিরোনাম / তারিখ</label><input id="ms-y-sub" type="text" data-k="subtitle" maxlength="40"></div>
					<div class="field" data-for="day"><label for="ms-y-msg">বার্তা</label><textarea id="ms-y-msg" data-k="message" rows="2"></textarea></div>
					<!-- শুভ সকাল -->
					<div class="field" data-for="morning"><label for="ms-m-title">শিরোনাম</label><input id="ms-m-title" type="text" data-k="title">
						<span class="ms-chips" id="ms-m-presets"></span></div>
					<div class="field" data-for="morning"><label for="ms-m-caption">ক্যাপশন</label><input id="ms-m-caption" type="text" data-k="caption" maxlength="160"><span class="desc">এক লাইনে না ধরলে ২৪ পিক্সেল পর্যন্ত ছোট হবে, তারপর ‘…’।</span></div>
					<div class="field" data-for="morning"><label for="ms-m-credit">ছবি: (আলোকচিত্রী)</label><input id="ms-m-credit" type="text" data-k="credit" maxlength="60"></div>
					<!-- মতামত -->
					<div class="field" data-for="opinion"><label for="ms-o-title">শিরোনাম</label><textarea id="ms-o-title" data-k="title" rows="3"></textarea><span class="desc">৮০ পিক্সেল থেকে শুরু; ৫ লাইনে না ধরলে ৫২ পর্যন্ত ছোট হবে, তারপর ‘…’।</span></div>
					<div class="row2" data-for="opinion">
						<div class="field"><label for="ms-o-name">লেখকের নাম</label><input id="ms-o-name" type="text" data-k="author_name"></div>
						<div class="field"><label for="ms-o-role">উপাধি</label><input id="ms-o-role" type="text" data-k="author_title"></div>
					</div>
					<div class="field" data-for="opinion"><span class="inline"><label class="check"><input type="checkbox" id="ms-o-photo" data-k="show_photo" checked>লেখকের ছবি দেখান</label><button class="button-link" type="button" id="ms-o-pick">ছবি বদলান</button></span>
						<p class="notice ms-notice">ছবি না থাকলে নামের প্রথম অক্ষর দিয়ে গোল প্রতীক বসবে।</p></div>
					<div class="field" data-for="opinion"><label for="ms-o-label">লেবেল</label><input id="ms-o-label" type="text" data-k="label" maxlength="30"></div>
					<!-- ফিচার / বিভাগ -->
					<div class="field" data-for="category"><label for="ms-c-cat">বিভাগ</label><select id="ms-c-cat" data-k="category_id"></select><span class="desc">বিভাগের নাম লেবেল হিসেবে আর বিভাগের রং রেখা হিসেবে বসবে।</span></div>
					<div class="field" data-for="feature"><label for="ms-f-kicker">কিকার</label><input id="ms-f-kicker" type="text" data-k="kicker" maxlength="40"></div>
					<div class="field" data-for="feature category"><label for="ms-f-title">শিরোনাম</label><textarea id="ms-f-title" data-k="title" rows="3"></textarea><span class="desc">৭০ থেকে ৪৮ পিক্সেল, সর্বোচ্চ ৪ লাইন।</span></div>
					<!-- ব্রেকিং -->
					<div class="field" data-for="breaking"><span class="lbl">লেবেল</span><div class="radios"><label><input type="radio" name="ms-b-variant" value="breaking" checked>ব্রেকিং (লাল)</label><label><input type="radio" name="ms-b-variant" value="latest">সর্বশেষ (নীল)</label></div></div>
					<div class="field" data-for="breaking"><label for="ms-b-time">সময়</label><input id="ms-b-time" type="text" data-k="time" maxlength="20"></div>
					<div class="field" data-for="breaking"><label for="ms-b-title">শিরোনাম</label><textarea id="ms-b-title" data-k="title" rows="3"></textarea><span class="desc">৬৪ থেকে ৪৪ পিক্সেল, সর্বোচ্চ ৩ লাইন।</span></div>
					<!-- উক্তি -->
					<div class="field" data-for="quote"><label for="ms-q-text">উক্তি</label><textarea id="ms-q-text" data-k="text" rows="3" maxlength="400"></textarea></div>
					<div class="row2" data-for="quote">
						<div class="field"><label for="ms-q-speaker">বক্তা</label><input id="ms-q-speaker" type="text" data-k="speaker"></div>
						<div class="field"><label for="ms-q-role">পরিচয়/উপাধি</label><input id="ms-q-role" type="text" data-k="role"></div>
					</div>
					<div class="row2" data-for="quote">
						<div class="field"><label for="ms-q-type">ধরনের লেবেল</label><input id="ms-q-type" type="text" data-k="type_label" maxlength="30"></div>
						<div class="field"><label for="ms-q-year">সাল (ইতিহাস ফ্রেমে জলছাপ)</label><input id="ms-q-year" type="text" data-k="year" maxlength="8"></div>
					</div>
					<div class="field" data-for="quote"><label class="check"><input type="checkbox" id="ms-q-photo" checked>বক্তার ছবি দেখান (বাঁ-ঘেঁষা ফ্রেমে)</label></div>
					<!-- শোক -->
					<div class="field" data-for="condolence"><label for="ms-s-pre">প্রথম লাইন</label><select id="ms-s-pre" data-k="pre"></select></div>
					<div class="field" data-for="condolence"><label for="ms-s-head">শিরোনাম</label><input id="ms-s-head" type="text" data-k="heading" maxlength="40"></div>
					<div class="field" data-for="condolence"><label for="ms-s-name">নাম</label><input id="ms-s-name" type="text" data-k="name"></div>
					<div class="field" data-for="condolence"><label for="ms-s-role">পরিচয়</label><input id="ms-s-role" type="text" data-k="role"></div>
					<div class="row2" data-for="condolence">
						<div class="field"><label for="ms-s-born">জন্ম</label><input id="ms-s-born" type="text" data-k="born" placeholder="১৯৪৮"></div>
						<div class="field"><label for="ms-s-died">মৃত্যু</label><input id="ms-s-died" type="text" data-k="died"></div>
					</div>
					<div class="field" data-for="condolence"><label for="ms-s-msg">বার্তা</label><textarea id="ms-s-msg" data-k="message" rows="2"></textarea><span class="desc">সর্বোচ্চ ২ লাইন। শোক কার্ডে বিজ্ঞাপন, লাল/নীল রং বা ইমোজি বসে না।</span></div>
					<!-- CTA (news-type cards) -->
					<div class="field ms-cta" data-for="news opinion feature breaking category"><label for="ms-cta">কার্ডে লেখা (CTA)</label>
						<div class="inline"><input id="ms-cta" type="text" data-k="cta" maxlength="40" value="<?php echo esc_attr( (string) meeha_studio_opt( 'news_cta', 'বিস্তারিত কমেন্টে' ) ); ?>">
						<label class="ms-check"><input id="ms-cta-on" type="checkbox" data-k="cta_on"<?php checked( '' !== trim( (string) meeha_studio_opt( 'news_cta', 'বিস্তারিত কমেন্টে' ) ) ); ?>> দেখান</label></div>
						<span class="desc">ছোট বোতামের মতো কার্ডের নিচের সারিতে বসে (ওয়াটারমার্কের জায়গায়)। ডিফল্ট: মিহা থিম › ফটোকার্ড।</span></div>
				</div></div>

				<div class="postbox" data-for="morning"><h2><span class="ms-n"></span>তারিখ</h2><div class="inside">
					<div class="row2">
						<div class="field"><label for="ms-date">দিন</label><input id="ms-date" type="date" value="<?php echo esc_attr( $cfg['today'] ); ?>"></div>
						<div class="field"><label for="ms-hijri">হিজরি সমন্বয়</label><select id="ms-hijri">
							<?php foreach ( array( -1 => '−১ দিন', 0 => '০ (বাংলাদেশ)', 1 => '+১ দিন' ) as $v => $l ) : ?>
								<option value="<?php echo esc_attr( (string) $v ); ?>"<?php selected( (int) $cfg['hijriOffset'], $v ); ?>><?php echo esc_html( $l ); ?></option>
							<?php endforeach; ?>
						</select></div>
					</div>
					<div class="dates" id="ms-dates" aria-label="স্বয়ংক্রিয় তারিখ" aria-live="polite">
						<div><small>বার ও খ্রিষ্টাব্দ</small><b data-d="wg"></b></div>
						<div><small>বঙ্গাব্দ</small><b data-d="bangla"></b></div>
						<div><small>হিজরি</small><b data-d="hijri"></b></div>
					</div>
				</div></div>

				<div class="postbox"><h2><span class="ms-n"></span>মাপ ও বিজ্ঞাপন</h2><div class="inside">
					<div class="field"><span class="lbl" id="ms-size-l">মাপ</span><div class="radios" role="radiogroup" aria-labelledby="ms-size-l">
						<?php $first = true; foreach ( $sizes as $k => $l ) : ?>
							<label><input type="radio" name="ms-size" value="<?php echo esc_attr( $k ); ?>"<?php checked( $first ); ?>><?php echo esc_html( $l ); ?></label>
						<?php $first = false; endforeach; ?>
					</div></div>
					<label class="check"><input type="checkbox" id="ms-ad">নিচে বিজ্ঞাপন স্ট্রিপ যোগ করুন</label>
					<div class="field"><label for="ms-adsel">বিজ্ঞাপন</label><select id="ms-adsel" disabled></select>
						<span class="desc" id="ms-adhelp"></span></div>
				</div></div>
			</form>

			<div class="preview">
				<div class="preview__stage"><div class="preview__canvas" id="ms-stage"><canvas id="ms-canvas" width="1080" height="1080" aria-label="কার্ডের প্রিভিউ" role="img"></canvas><span class="preview__safe" aria-hidden="true"></span><span class="ms-busy" id="ms-busy" hidden>তৈরি হচ্ছে…</span></div></div>
				<div class="preview__bar">
					<span class="preview__meta" id="ms-meta">১০৮০ × ১০৮০ · নীল ডটেড রেখা = নিরাপদ এলাকা (৬০ পিক্সেল)</span>
					<span class="inline">
						<?php if ( $cfg['canSave'] ) : ?><button class="button" type="button" id="ms-save">মিডিয়া লাইব্রেরিতে রাখুন</button><?php endif; ?>
						<button class="button" type="button" id="ms-jpg">JPG</button>
						<button class="button button-primary button-hero" type="button" id="ms-png"><span class="dashicons dashicons-download" aria-hidden="true"></span>PNG ডাউনলোড</button>
					</span>
				</div>
				<p class="ms-msg" id="ms-status" role="status" aria-live="polite"></p>
				<div class="postbox"><h2>সাম্প্রতিক কার্ড <a class="ms-more" href="<?php echo esc_url( admin_url( 'admin.php?page=meeha-photocard-made' ) ); ?>">সব দেখুন</a></h2><div class="inside"><div class="history" id="ms-history"></div></div></div>
			</div>
		</div>
	</div>
	<?php
}

/* --------------------------------------------------------------------------
 * ফ্রেম লাইব্রেরি
 * ------------------------------------------------------------------------ */

add_action( 'admin_post_meeha_card_frame_upload', 'meeha_studio_handle_frame_upload' );
add_action( 'admin_post_meeha_card_frame_delete', 'meeha_studio_handle_frame_delete' );

/**
 * Library upload (non-JS form).
 */
function meeha_studio_handle_frame_upload() {
	check_admin_referer( 'meeha_card_frame_upload' );
	$back = admin_url( 'admin.php?page=meeha-photocard-frames' );
	if ( ! meeha_studio_can_upload_frames() ) {
		wp_safe_redirect( add_query_arg( 'meeha_err', rawurlencode( 'ফ্রেম আপলোডের অনুমতি নেই।' ), $back ) );
		exit;
	}
	$file = isset( $_FILES['frame'] ) ? $_FILES['frame'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated in meeha_studio_store_frame().
	$type = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
	$size = isset( $_POST['size'] ) ? sanitize_text_field( wp_unslash( $_POST['size'] ) ) : '1080x1080';
	$lab  = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
	$row  = meeha_studio_store_frame( $file, $type, $size, $lab );
	if ( is_wp_error( $row ) ) {
		wp_safe_redirect( add_query_arg( 'meeha_err', rawurlencode( $row->get_error_message() ), $back ) );
		exit;
	}
	wp_safe_redirect( add_query_arg( array( 'meeha_ok' => 'up', 'meeha_warn' => $row['warning'] ? 1 : 0 ), $back ) );
	exit;
}

/**
 * Library delete.
 */
function meeha_studio_handle_frame_delete() {
	$id = isset( $_POST['frame'] ) ? sanitize_key( wp_unslash( $_POST['frame'] ) ) : '';
	check_admin_referer( 'meeha_card_frame_delete_' . $id );
	$back = admin_url( 'admin.php?page=meeha-photocard-frames' );
	if ( ! current_user_can( 'meeha_make_cards' ) ) {
		wp_die( esc_html__( 'অনুমতি নেই।', 'meeha' ), 403 );
	}
	$r = meeha_studio_delete_frame( $id );
	if ( is_wp_error( $r ) ) {
		wp_safe_redirect( add_query_arg( 'meeha_err', rawurlencode( $r->get_error_message() ), $back ) );
		exit;
	}
	wp_safe_redirect( add_query_arg( 'meeha_ok', 'del', $back ) );
	exit;
}

/**
 * Frame library page.
 */
function meeha_studio_frames_page() {
	if ( ! current_user_can( 'meeha_make_cards' ) ) {
		wp_die( esc_html__( 'অনুমতি নেই।', 'meeha' ) );
	}
	$types  = meeha_studio_types();
	$sizes  = meeha_studio_sizes( false );
	$frames = meeha_studio_frames_all();
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display-only notices.
	$err  = isset( $_GET['meeha_err'] ) ? sanitize_text_field( wp_unslash( $_GET['meeha_err'] ) ) : '';
	$ok   = isset( $_GET['meeha_ok'] ) ? sanitize_key( wp_unslash( $_GET['meeha_ok'] ) ) : '';
	$warn = ! empty( $_GET['meeha_warn'] );
	// phpcs:enable
	?>
	<div class="wrap ms-wrap ms-lib">
		<h1>ফ্রেম লাইব্রেরি</h1>
		<p class="page-desc">প্রতিটি কার্ডের ধরনের বিল্ট-ইন ফ্রেম আর আপলোড করা নিজের ফ্রেম। এগুলো স্টুডিওর ফ্রেম-তালিকায় সরাসরি দেখায় — মিডিয়া লাইব্রেরিতে নয়।</p>
		<?php if ( $err ) : ?><div class="notice notice-error is-dismissible"><p><?php echo esc_html( $err ); ?></p></div><?php endif; ?>
		<?php if ( 'up' === $ok ) : ?><div class="notice notice-success is-dismissible"><p>ফ্রেম আপলোড হয়েছে।<?php echo $warn ? ' ' . esc_html( 'সতর্কতা: ফ্রেমে কোনো স্বচ্ছ অংশ নেই — এটি ছবিটিকে পুরো ঢেকে দেবে।' ) : ''; ?></p></div><?php endif; ?>
		<?php if ( 'del' === $ok ) : ?><div class="notice notice-success is-dismissible"><p>ফ্রেম মুছে ফেলা হয়েছে।</p></div><?php endif; ?>

		<?php if ( meeha_studio_can_upload_frames() ) : ?>
		<div class="postbox ms-libup"><h2>নতুন ফ্রেম আপলোড</h2><div class="inside">
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ms-libform">
				<input type="hidden" name="action" value="meeha_card_frame_upload">
				<?php wp_nonce_field( 'meeha_card_frame_upload' ); ?>
				<div class="field"><label for="ml-type">কার্ডের ধরন</label><select id="ml-type" name="type"><?php foreach ( $types as $id => $t ) : ?><option value="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $t['label'] ); ?></option><?php endforeach; ?></select></div>
				<div class="field"><label for="ml-size">মাপ</label><select id="ml-size" name="size"><?php foreach ( $sizes as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $l ); ?></option><?php endforeach; ?></select></div>
				<div class="field"><label for="ml-label">নাম</label><input id="ml-label" name="label" type="text" maxlength="60" required></div>
				<div class="field"><label for="ml-file">স্বচ্ছ PNG (ঠিক ওই মাপের, ≤ ১ এমবি)</label><input id="ml-file" name="frame" type="file" accept="image/png" required></div>
				<p><button class="button button-primary" type="submit">আপলোড</button></p>
			</form>
		</div></div>
		<?php endif; ?>

		<?php foreach ( $types as $id => $t ) : ?>
			<?php $own = array_filter( $frames, function ( $r ) use ( $id ) { return $r['type'] === $id; } ); ?>
			<h2 class="ms-libh"><?php echo esc_html( $t['label'] ); ?> <a class="page-title-action" href="<?php echo esc_url( meeha_studio_url( $id ) ); ?>">স্টুডিওতে খুলুন</a></h2>
			<ul class="frames ms-libgrid">
				<?php foreach ( meeha_studio_presets_for( $id ) as $pid => $label ) : ?>
					<li><figure><span class="sw <?php echo esc_attr( 'quote' === $id ? 'sw-img' : 'sw-' . $id . '-' . $pid ); ?>"><?php if ( 'quote' === $id ) : ?><img src="<?php echo esc_url( MEEHA_URI . '/assets/frames/quote-' . $pid . '.svg' ); ?>" alt="" width="96" height="96" loading="lazy"><?php else : ?><canvas class="ms-thumbc" width="240" height="240" data-type="<?php echo esc_attr( $id ); ?>" data-preset="<?php echo esc_attr( $pid ); ?>" aria-hidden="true"></canvas><?php endif; ?></span><figcaption><?php echo esc_html( $label ); ?></figcaption><span class="tag">বিল্ট-ইন</span></figure></li>
				<?php endforeach; ?>
				<?php foreach ( $own as $r ) : ?>
					<li><figure><span class="sw sw-img sw-<?php echo esc_attr( str_replace( 'x', '-', $r['size'] ) ); ?>"><img src="<?php echo esc_url( $r['url'] ); ?>" alt="" loading="lazy"></span>
						<figcaption><?php echo esc_html( $r['label'] ); ?><br><small><?php echo esc_html( str_replace( 'x', ' × ', meeha_studio_bn( $r['size'] ) ) ); ?><?php echo empty( $r['alpha'] ) ? ' · অস্বচ্ছ' : ''; ?><?php echo in_array( $id, array( 'day', 'birthday', 'condolence', 'quote' ), true ) ? '<br>' . esc_html( 'নিজস্ব নকশা — বিল্ট-ইন নকশার সঙ্গে মেশে না' ) : ''; ?></small></figcaption><span class="tag">আপলোড</span>
						<?php if ( meeha_studio_can_delete_frame( $r ) ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ms-del" onsubmit="return confirm('এই ফ্রেমটি মুছে ফেলবেন?');">
								<input type="hidden" name="action" value="meeha_card_frame_delete"><input type="hidden" name="frame" value="<?php echo esc_attr( $r['id'] ); ?>">
								<?php wp_nonce_field( 'meeha_card_frame_delete_' . $r['id'] ); ?>
								<button class="button-link button-link-delete" type="submit">মুছুন</button>
							</form>
						<?php endif; ?>
					</figure></li>
				<?php endforeach; ?>
			</ul>
		<?php endforeach; ?>
	</div>
	<?php
}

/* --------------------------------------------------------------------------
 * তৈরি কার্ড
 * ------------------------------------------------------------------------ */

/**
 * Made-cards gallery.
 */
function meeha_studio_made_page() {
	if ( ! current_user_can( 'meeha_make_cards' ) ) {
		wp_die( esc_html__( 'অনুমতি নেই।', 'meeha' ) );
	}
	$types = meeha_studio_types();
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
	$type  = isset( $_GET['card_type'] ) ? sanitize_key( wp_unslash( $_GET['card_type'] ) ) : '';
	$paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
	// phpcs:enable
	if ( $type && ! isset( $types[ $type ] ) ) {
		$type = '';
	}
	$q = meeha_studio_cards_query( 24, $paged, $type );
	?>
	<div class="wrap ms-wrap ms-made">
		<h1>তৈরি কার্ড <a class="page-title-action" href="<?php echo esc_url( meeha_studio_url() ); ?>">নতুন কার্ড</a></h1>
		<p class="page-desc">স্টুডিও থেকে “মিডিয়া লাইব্রেরিতে রাখুন” করা কার্ডগুলো।</p>
		<form method="get" class="ms-filter"><input type="hidden" name="page" value="meeha-photocard-made">
			<label for="mm-type" class="screen-reader-text">ধরন</label>
			<select id="mm-type" name="card_type"><option value="">সব ধরন</option><?php foreach ( $types as $id => $t ) : ?><option value="<?php echo esc_attr( $id ); ?>"<?php selected( $type, $id ); ?>><?php echo esc_html( $t['label'] ); ?></option><?php endforeach; ?></select>
			<button class="button" type="submit">দেখান</button>
		</form>
		<?php if ( ! $q->have_posts() ) : ?>
			<p class="ms-empty">এখনো কোনো কার্ড রাখা হয়নি।</p>
		<?php else : ?>
			<ul class="ms-madegrid">
				<?php foreach ( $q->posts as $a ) : ?>
					<?php
					$t   = (string) get_post_meta( $a->ID, '_meeha_card_type', true );
					$url = (string) wp_get_attachment_url( $a->ID );
					$by  = get_userdata( (int) $a->post_author );
					?>
					<li><figure>
						<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><?php echo wp_get_attachment_image( $a->ID, 'medium', false, array( 'loading' => 'lazy' ) ); ?></a>
						<figcaption><b><?php echo esc_html( isset( $types[ $t ] ) ? $types[ $t ]['label'] : $t ); ?></b> · <?php echo esc_html( function_exists( 'meeha_bengali_date' ) ? wp_strip_all_tags( meeha_bengali_date( $a ) ) : get_the_date( '', $a ) ); ?><?php echo $by ? ' · ' . esc_html( $by->display_name ) : ''; ?>
							<br><a href="<?php echo esc_url( $url ); ?>" download>ডাউনলোড</a><?php if ( current_user_can( 'edit_post', $a->ID ) ) : ?> · <a href="<?php echo esc_url( get_edit_post_link( $a->ID ) ); ?>">সম্পাদনা</a><?php endif; ?></figcaption>
					</figure></li>
				<?php endforeach; ?>
			</ul>
			<?php
			$links = paginate_links(
				array(
					'base'      => add_query_arg( 'paged', '%#%' ),
					'format'    => '',
					'current'   => $paged,
					'total'     => (int) $q->max_num_pages,
					'prev_text' => '‹',
					'next_text' => '›',
				)
			);
			if ( $links ) {
				echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( $links ) . '</div></div>';
			}
			?>
		<?php endif; ?>
	</div>
	<?php
}

/* Row actions: "ফটোকার্ড" on posts and উক্তি lists. */
add_filter(
	'post_row_actions',
	function ( $actions, $post ) {
		if ( current_user_can( 'meeha_make_cards' ) && in_array( $post->post_type, array( 'post', 'meeha_photocard' ), true ) && current_user_can( 'read_post', $post->ID ) ) {
			$actions['meeha_card'] = sprintf( '<a href="%s">ফটোকার্ড</a>', esc_url( meeha_studio_url( meeha_studio_type_for_post( $post ), $post->ID ) ) );
		}
		return $actions;
	},
	10,
	2
);
