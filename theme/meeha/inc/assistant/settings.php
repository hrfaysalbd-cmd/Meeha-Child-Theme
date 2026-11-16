<?php
/**
 * Settings tab "মিহা সহকারী" (option meeha_opt_assistant). The Anthropic API key is NOT part of that option: it
 * lives in its own non-autoloaded option `meeha_assistant_api_key`, is written only by administrators, is never
 * printed back (the field shows "সংরক্ষিত" + the last 4 characters) and never reaches the browser or a log.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

const MEEHA_AS_DEFAULT_MODEL = 'claude-haiku-5-5';

/**
 * Default floating / greeting suggestions (one per line in the settings).
 *
 * @return string
 */
function meeha_as_default_chips() {
	return "আজকের ট্রেন্ডিং\nআজ দেশে কী হচ্ছে\nআমার জেলার খবর";
}

/**
 * Stored API key ('' when none).
 *
 * @return string
 */
function meeha_as_api_key() {
	if ( defined( 'MEEHA_ANTHROPIC_API_KEY' ) && MEEHA_ANTHROPIC_API_KEY ) {
		return (string) MEEHA_ANTHROPIC_API_KEY;
	}
	return (string) get_option( 'meeha_assistant_api_key', '' );
}

/**
 * Is the AI tier usable right now (switch on + key present)?
 *
 * @return bool
 */
function meeha_as_ai_on() {
	return (bool) meeha_opt( 'assistant', 'ai_enabled', 0 ) && '' !== meeha_as_api_key();
}

/**
 * Lines of a textarea setting.
 *
 * @param string $key Key.
 * @param string $def Default.
 * @param int    $max Max lines.
 * @return string[]
 */
function meeha_as_lines( $key, $def, $max = 8 ) {
	$raw = (string) meeha_opt( 'assistant', $key, $def );
	$out = array();
	foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $l ) {
		$l = trim( wp_strip_all_tags( $l ) );
		if ( '' !== $l && ! in_array( $l, $out, true ) ) {
			$out[] = mb_substr( $l, 0, 40 );
		}
	}
	return array_slice( $out, 0, $max );
}

/**
 * Tab sanitize: move a newly typed key into its own option (administrators only).
 *
 * @param array $clean Clean values.
 * @param array $raw   Raw POST.
 * @return array
 */
function meeha_as_settings_sanitize( $clean, $raw ) {
	unset( $clean['api_key_new'], $clean['api_key_clear'] );
	if ( ! current_user_can( 'manage_options' ) ) {
		return $clean;
	}
	if ( ! empty( $raw['api_key_clear'] ) ) {
		delete_option( 'meeha_assistant_api_key' );
		meeha_settings_notice( 'API কী মুছে ফেলা হয়েছে; সহকারী এখন শুধু নিজস্ব (বিনা খরচের) উত্তর দেবে।', 'success' );
	} elseif ( isset( $raw['api_key_new'] ) ) {
		$key = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $raw['api_key_new'] );
		if ( '' !== $key ) {
			if ( strlen( $key ) < 20 ) {
				meeha_settings_notice( 'API কীটি খুব ছোট মনে হচ্ছে — সংরক্ষণ করা হয়নি।', 'warning' );
			} else {
				if ( false === get_option( 'meeha_assistant_api_key', false ) ) {
					add_option( 'meeha_assistant_api_key', $key, '', 'no' );
				} else {
					update_option( 'meeha_assistant_api_key', $key, false );
				}
				meeha_settings_notice( 'নতুন API কী সংরক্ষণ করা হয়েছে।', 'success' );
			}
		}
	}
	$model = isset( $clean['model'] ) ? preg_replace( '/[^a-z0-9.\-]/', '', strtolower( (string) $clean['model'] ) ) : '';
	$clean['model'] = $model ? $model : MEEHA_AS_DEFAULT_MODEL;
	update_option( 'meeha_as_cache_gen', (int) get_option( 'meeha_as_cache_gen', 0 ) + 1, false );
	return $clean;
}

/**
 * Extra tab output: API key field + what readers ask.
 *
 * @param string $tab_id Tab.
 * @param array  $values Values.
 * @param array  $ctx    Context.
 */
function meeha_as_settings_render( $tab_id, $values, $ctx ) {
	$key   = meeha_as_api_key();
	$admin = current_user_can( 'manage_options' );
	echo '<h2>Anthropic API কী</h2><table class="form-table" role="presentation"><tbody><tr><th scope="row"><label for="meeha-as-key">API কী</label></th><td>';
	if ( $key ) {
		echo '<p><strong>সংরক্ষিত</strong> (…' . esc_html( substr( $key, -4 ) ) . ')' . ( defined( 'MEEHA_ANTHROPIC_API_KEY' ) && MEEHA_ANTHROPIC_API_KEY ? ' — wp-config.php-এর MEEHA_ANTHROPIC_API_KEY থেকে' : '' ) . '</p>';
	}
	if ( $admin ) {
		printf( '<input type="password" class="regular-text" id="meeha-as-key" name="%s[api_key_new]" value="" autocomplete="new-password" spellcheck="false" placeholder="%s">', esc_attr( $ctx['name'] ), esc_attr( $key ? 'বদলাতে নতুন কী লিখুন' : 'sk-ant-…' ) );
		if ( $key ) {
			printf( '<p><label><input type="checkbox" name="%s[api_key_clear]" value="1"> কী মুছে ফেলুন</label></p>', esc_attr( $ctx['name'] ) );
		}
		echo '<p class="description">console.anthropic.com থেকে নেওয়া কী। এটি সার্ভারেই থাকে — পাঠকের ব্রাউজারে বা লগে কখনো যায় না। ফাঁকা রাখলে আগের কী থাকবে। কী না থাকলে বা কোনো ত্রুটি হলে সহকারী নিজস্ব (বিনা খরচের) উত্তর দেয়।</p>';
	} else {
		echo '<p class="description">শুধু প্রশাসক কী বদলাতে পারেন।</p>';
	}
	echo '</td></tr></tbody></table>';

	if ( function_exists( 'meeha_as_stats_html' ) ) {
		echo meeha_as_stats_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
	}
}

meeha_register_settings_tab(
	'assistant',
	array(
		'title'    => 'মিহা সহকারী',
		'order'    => 47,
		'cap'      => 'design',
		'desc'     => 'পাঠকের প্রশ্নের উত্তর দেয় শুধু মিহা নিউজের প্রকাশিত খবর থেকে। প্রতিটি পাতার নিচে ডানে একটি বাবল দেখায়। নিজস্ব উত্তর সবসময় চালু ও বিনা খরচের; AI উত্তর শুধু API কী দিলে।',
		'render'   => 'meeha_as_settings_render',
		'sanitize' => 'meeha_as_settings_sanitize',
		'fields'   => array(
			array( 'key' => 'enabled', 'type' => 'checkbox', 'label' => 'মিহা সহকারী চালু', 'default' => 1 ),
			array( 'key' => 'name', 'type' => 'text', 'label' => 'নাম (প্যানেলের শিরোনাম)', 'default' => 'মিহা সহকারী' ),
			array( 'key' => 'label', 'type' => 'text', 'label' => 'বাবলের লেখা', 'default' => 'জিজ্ঞেস করুন', 'desc' => 'ডেস্কটপে বাবলের পাশে দেখায়; মোবাইলে শুধু আইকন।' ),
			array( 'key' => 'greeting', 'type' => 'textarea', 'rows' => 2, 'label' => 'শুভেচ্ছা বার্তা', 'default' => 'আসসালামু আলাইকুম! আমি মিহা সহকারী। মিহা নিউজের খবর থেকে আপনার প্রশ্নের উত্তর খুঁজে দিই। কী জানতে চান?' ),
			array( 'key' => 'disclaimer', 'type' => 'text', 'label' => 'দায়মুক্তি (প্যানেলের নিচে)', 'default' => 'উত্তর মিহা নিউজের প্রকাশিত খবর থেকে স্বয়ংক্রিয়ভাবে তৈরি' ),
			array( 'type' => 'heading', 'label' => 'ভাসমান প্রশ্ন', 'desc' => 'বাবলের ওপরে ছোট বোতাম — খোলার আগেই দেখা যায়; চাপলে প্যানেল খুলে প্রশ্নটি করে। পাঠক × চাপলে সেদিন আর দেখায় না। মোবাইলে সর্বোচ্চ ২টি।' ),
			array( 'key' => 'float_on', 'type' => 'checkbox', 'label' => 'ভাসমান প্রশ্ন দেখান', 'default' => 1 ),
			array( 'key' => 'float_chips', 'type' => 'textarea', 'rows' => 4, 'label' => 'ভাসমান প্রশ্ন (প্রতি লাইনে একটি)', 'default' => meeha_as_default_chips(), 'desc' => '"আমার জেলার খবর" পাঠকের জেলা জিজ্ঞেস করে (ব্রাউজারে মনে রাখে)।' ),
			array( 'key' => 'float_delay', 'type' => 'number', 'label' => 'পাতা খোলার কতক্ষণ পর দেখাবে', 'default' => 3, 'min' => 0, 'max' => 60, 'unit' => 'সেকেন্ড' ),
			array( 'key' => 'live_chip', 'type' => 'checkbox', 'label' => 'লাইভ চলাকালে বাবলে "লাইভ" চিহ্ন ও "এখন লাইভ" বোতাম', 'default' => 1 ),
			array( 'type' => 'heading', 'label' => 'AI উত্তর (ঐচ্ছিক)', 'desc' => 'চালু থাকলে প্রাসঙ্গিক ৮টি পর্যন্ত খবর বেছে Anthropic Claude সংক্ষিপ্ত বাংলা উত্তর লেখে, প্রতিটি তথ্যের পাশে [১], [২] সূত্র। খরচ Anthropic অ্যাকাউন্টে যায়; একই প্রশ্নের উত্তর ১০ মিনিট ক্যাশে থাকে।' ),
			array( 'key' => 'ai_enabled', 'type' => 'checkbox', 'label' => 'AI উত্তর চালু', 'default' => 0, 'cap' => 'admin' ),
			array( 'key' => 'model', 'type' => 'text', 'label' => 'মডেল', 'default' => MEEHA_AS_DEFAULT_MODEL, 'cap' => 'admin', 'desc' => 'ডিফল্ট claude-haiku-5-5 (কম খরচ, দ্রুত)। আরও ভালো উত্তর চাইলে claude-sonnet-5-5 বা claude-opus-5-5 (খরচ বেশি)।' ),
			array( 'type' => 'heading', 'label' => 'অপব্যবহার সীমা (প্রতি IP)' ),
			array( 'key' => 'rate_hour', 'type' => 'number', 'label' => 'ঘণ্টায় সর্বোচ্চ প্রশ্ন', 'default' => 20, 'min' => 1, 'max' => 500 ),
			array( 'key' => 'rate_day', 'type' => 'number', 'label' => 'দিনে সর্বোচ্চ প্রশ্ন', 'default' => 60, 'min' => 1, 'max' => 2000 ),
			array( 'key' => 'ai_hour', 'type' => 'number', 'label' => 'AI উত্তর ঘণ্টায় সর্বোচ্চ', 'default' => 6, 'min' => 0, 'max' => 200, 'desc' => 'সীমা পেরোলে নিজস্ব উত্তর দেয়।' ),
			array( 'key' => 'ai_day', 'type' => 'number', 'label' => 'AI উত্তর দিনে সর্বোচ্চ', 'default' => 20, 'min' => 0, 'max' => 1000 ),
		),
	)
);
