<?php
/**
 * `[meeha_deshchitro]` — the public page. The server prints the hero, a plain summary (crawlers, no-JS readers,
 * share previews), a GET filter form and the first payload; assets/js/deshchitro.js draws the rest (tiles, map,
 * charts, economy, table) and re-fetches GET meeha/v1/deshchitro when the filter changes.
 *
 * GET params: dr = range (day|7|30|month), dd = date (Y-m-d). They never collide with WordPress query vars.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

/**
 * Args from the page URL.
 *
 * @return array
 */
function meeha_dc_args_from_get() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput
	return meeha_dc_args(
		array(
			'range' => isset( $_GET['dr'] ) ? sanitize_key( wp_unslash( $_GET['dr'] ) ) : 'day',
			'date'  => isset( $_GET['dd'] ) ? sanitize_text_field( wp_unslash( $_GET['dd'] ) ) : '',
		)
	);
	// phpcs:enable
}

/**
 * Plain-language summary of a payload ("গতকাল সারা দেশে খুন ৮, সড়কে নিহত ১৫ …").
 *
 * @param array $p Payload.
 * @return string
 */
function meeha_dc_summary_text( $p ) {
	if ( ! $p['covered'] ) {
		return '';
	}
	$bits = array();
	foreach ( $p['indicators'] as $i ) {
		$n = isset( $p['totals'][ $i['key'] ] ) ? (int) $p['totals'][ $i['key'] ] : 0;
		$bits[] = $i['short'] . ' ' . meeha_bn_digits( $n );
	}
	return $p['label'] . ' — সারা দেশে ' . implode( ', ', $bits ) . '।';
}

/**
 * Shortcode.
 *
 * @param array $atts Unused.
 * @return string
 */
function meeha_dc_shortcode( $atts = array() ) {
	meeha_dc_enqueue();
	$a    = meeha_dc_args_from_get();
	$p    = meeha_dc_payload( $a );
	$t    = $p['text'];
	$rest = esc_url_raw( rest_url( 'meeha/v1/deshchitro' ) );
	$sum  = meeha_dc_summary_text( $p );
	$live = meeha_dc_live_on() ? meeha_dc_live_payload() : null;
	ob_start();
	?>
	<section class="dc" id="meeha-dc" data-rest="<?php echo esc_attr( $rest ); ?>" aria-labelledby="dc-title">
		<?php
		if ( $live ) {
			meeha_dc_live_ssr( $live, $p['indicators'] );
		}
		?>
		<header class="dc-hero">
			<div class="dc-hero__in">
				<div class="dc-hero__text">
					<p class="dc-kicker"><span class="dc-kicker__dot" aria-hidden="true"></span><?php echo esc_html( $t['kicker'] ); ?></p>
					<h2 class="dc-title" id="dc-title"><?php echo esc_html( $t['title'] ); ?></h2>
					<p class="dc-sub"><?php echo esc_html( $t['subtitle'] ); ?></p>
				</div>
				<div class="dc-when" data-dc-when>
					<span class="dc-when__k"><?php echo 1 === $p['span'] ? 'হিসাবের দিন' : 'হিসাবের সময়কাল'; ?></span>
					<strong class="dc-when__v"><?php echo esc_html( $p['label'] ); ?></strong>
				</div>
			</div>
			<form class="dc-filter" method="get" data-dc-filter>
				<div class="dc-seg" role="group" aria-label="সময়কাল">
					<?php foreach ( array( 'day' => 'একদিন', '7' => '৭ দিন', '30' => '৩০ দিন', 'month' => 'মাস' ) as $k => $label ) : ?>
						<button type="submit" name="dr" value="<?php echo esc_attr( $k ); ?>" class="dc-seg__b" aria-pressed="<?php echo $k === $p['range'] ? 'true' : 'false'; ?>"><?php echo esc_html( $label ); ?></button>
					<?php endforeach; ?>
				</div>
				<div class="dc-date">
					<button type="button" class="dc-date__step" data-dc-step="-1" aria-label="আগের দিন">‹</button>
					<label class="dc-date__l"><span class="screen-reader-text">তারিখ</span>
						<input type="date" name="dd" value="<?php echo esc_attr( $p['to'] ); ?>" max="<?php echo esc_attr( meeha_dc_shift( $p['today'], -1 ) ); ?>" <?php echo $p['first'] ? 'min="' . esc_attr( $p['first'] ) . '"' : ''; ?>>
					</label>
					<button type="button" class="dc-date__step" data-dc-step="1" aria-label="পরের দিন">›</button>
					<noscript><button type="submit" class="dc-seg__b">দেখুন</button></noscript>
				</div>
			</form>
		</header>

		<div class="dc-body" data-dc-body aria-live="polite">
			<?php if ( $sum ) : ?>
				<p class="dc-ssr"><?php echo esc_html( $sum ); ?></p>
			<?php else : ?>
				<p class="dc-empty">এই সময়ের হিসাব এখনো প্রকাশ হয়নি।</p>
			<?php endif; ?>
		</div>

		<footer class="dc-foot">
			<p class="dc-foot__dis"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 2 21h20L12 3Zm0 6v5m0 3v.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg><span><?php echo esc_html( $t['disclaimer'] ); ?></span></p>
			<p class="dc-foot__m"><?php echo esc_html( $t['method'] ); ?></p>
			<p class="dc-foot__src">মানচিত্রের সীমানা: বাংলাদেশ পরিসংখ্যান ব্যুরো (BBS) ও OCHA, geoBoundaries-এর মাধ্যমে (CC BY 3.0 IGO)।</p>
		</footer>
		<?php
		meeha_dc_json_script(
			'meeha-dc-data',
			array(
				'p'    => $p,
				'geo'  => meeha_dc_geo(),
				'live' => $live,
			)
		);
		?>
	</section>
	<?php
	return (string) ob_get_clean();
}
add_shortcode( 'meeha_deshchitro', 'meeha_dc_shortcode' );

/**
 * LIVE band (server-rendered fallback; assets/js/deshchitro-live.js redraws it and keeps it current).
 *
 * @param array   $live From meeha_dc_live_payload().
 * @param array[] $inds Active indicators.
 */
function meeha_dc_live_ssr( $live, $inds ) {
	?>
	<section class="dcl" data-dc-live data-live-rest="<?php echo esc_attr( esc_url_raw( rest_url( 'meeha/v1/deshchitro/live' ) ) ); ?>" aria-labelledby="dcl-h" aria-live="off">
		<div class="dcl-in" data-live-in>
			<div class="dcl-bar"><span class="dcl-onair"><i aria-hidden="true"></i>লাইভ</span><h2 class="dcl-h" id="dcl-h">আজ সরাসরি <span>দেশচিত্র</span></h2><span class="dcl-date"><?php echo esc_html( $live['label'] ); ?></span></div>
			<ul class="dcl-kpis">
				<?php foreach ( $inds as $i ) : ?>
					<li class="dcl-kpi dcl-t-<?php echo esc_attr( $i['tone'] ); ?>"><span class="dcl-kpi__l"><?php echo esc_html( $i['short'] ); ?></span><strong class="dcl-kpi__v"><?php echo esc_html( meeha_bn_digits( (string) ( isset( $live['tot'][ $i['key'] ] ) ? $live['tot'][ $i['key'] ] : 0 ) ) ); ?></strong></li>
				<?php endforeach; ?>
			</ul>
			<?php if ( $live['posts'] ) : ?>
				<ol class="dcl-list">
					<?php foreach ( array_slice( $live['posts'], 0, 6 ) as $post ) : ?>
						<li class="dcl-item"><a href="<?php echo esc_url( $post['url'] ); ?>"><span class="dcl-item__t"><?php echo esc_html( $post['title'] ); ?></span></a></li>
					<?php endforeach; ?>
				</ol>
			<?php endif; ?>
		</div>
	</section>
	<?php
}

/**
 * Print a JSON payload in a non-executable script tag.
 *
 * @param string $id   Element id.
 * @param mixed  $data Data.
 */
function meeha_dc_json_script( $id, $data ) {
	echo '<script type="application/json" id="' . esc_attr( $id ) . '">'
		. wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT )
		. '</script>';
}

/** Enqueue the page bundle. */
function meeha_dc_enqueue() {
	if ( function_exists( 'meeha_enqueue_bundle' ) ) {
		meeha_enqueue_bundle( 'deshchitro' );
		return;
	}
	wp_enqueue_style( 'meeha-deshchitro', MEEHA_URI . '/assets/css/deshchitro.css', array(), MEEHA_VERSION );
	wp_enqueue_script( 'meeha-deshchitro-map', MEEHA_URI . '/assets/js/deshchitro-map.js', array(), MEEHA_VERSION, true );
	wp_enqueue_script( 'meeha-deshchitro', MEEHA_URI . '/assets/js/deshchitro.js', array( 'meeha-deshchitro-map' ), MEEHA_VERSION, true );
	wp_enqueue_script( 'meeha-deshchitro-live', MEEHA_URI . '/assets/js/deshchitro-live.js', array( 'meeha-deshchitro-map' ), MEEHA_VERSION, true );
}

/** Enqueue early on the configured page (styles in <head>, no flash). */
function meeha_dc_maybe_enqueue() {
	if ( ! is_singular() ) {
		return;
	}
	$post = get_post();
	if ( $post && ( (int) meeha_dc_config()['page_id'] === (int) $post->ID || has_shortcode( (string) $post->post_content, 'meeha_deshchitro' ) ) ) {
		meeha_dc_enqueue();
	}
}
add_action( 'wp_enqueue_scripts', 'meeha_dc_maybe_enqueue', 5 );

/** The page is a theme "app" page (container width, no prose styles). */
add_filter(
	'meeha_theme_shortcodes',
	function ( $codes ) {
		$codes[] = 'meeha_deshchitro';
		return $codes;
	}
);
