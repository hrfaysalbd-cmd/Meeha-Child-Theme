<?php
/**
 * Question → intent. Everything is matched on a normalised form (theme search normaliser when present: NFC, nukta
 * forms, ঙ্গ/ংগ, final ী → ি, Bengali digits → ASCII, lower case, punctuation → space), word-prefix based so
 * Bengali case endings still match (কুমিল্লায়, খেলার, ঢাকার).
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

/**
 * Normalise text for matching.
 *
 * @param string $s Text.
 * @return string
 */
function meeha_as_norm( $s ) {
	if ( function_exists( 'meeha_search_normalize_chars' ) ) {
		return meeha_search_normalize_chars( $s );
	}
	$s = mb_strtolower( (string) $s, 'UTF-8' );
	$s = (string) preg_replace( '/[^\p{L}\p{M}\p{N}]+/u', ' ', $s );
	return trim( (string) preg_replace( '/\s+/u', ' ', $s ) );
}

/**
 * Does the normalised question contain a word starting with the (normalised) needle? Multi-word needles match as a
 * phrase. Latin needles must match a whole word (prefix ≥ 4 letters for words like "dhakar").
 *
 * @param string $q      Normalised question.
 * @param string $needle Raw needle.
 * @return bool
 */
function meeha_as_has( $q, $needle ) {
	$n = meeha_as_norm( $needle );
	if ( '' === $n ) {
		return false;
	}
	$latin = (bool) preg_match( '/^[a-z0-9 ]+$/', $n );
	$tail  = '';
	if ( $latin && strlen( $n ) < 4 ) {
		$tail = '(?=\s|$)';
	} elseif ( ! $latin && mb_strlen( $n ) <= 3 ) { // short Bengali words: only common endings (আজকের, দেশে), not হাইকোর্ট.
		$tail = '(?=\s|$|ে|র|কে|ও|টা|টি|য়)';
	}
	return (bool) preg_match( '/(?:^|\s)' . preg_quote( $n, '/' ) . $tail . '/u', $q );
}

/**
 * First needle (of a list) found in q, or ''.
 *
 * @param string   $q       Normalised question.
 * @param string[] $needles Needles.
 * @return string
 */
function meeha_as_any( $q, $needles ) {
	foreach ( (array) $needles as $n ) {
		if ( meeha_as_has( $q, $n ) ) {
			return $n;
		}
	}
	return '';
}

/**
 * Keyword groups.
 *
 * @return array<string,string[]>
 */
function meeha_as_keywords() {
	return (array) apply_filters(
		'meeha_assistant_keywords',
		array(
			'help'     => array( 'হ্যালো', 'হেলো', 'হাই', 'হায়', 'সালাম', 'আসসালামু', 'আস সালামু', 'নমস্কার', 'শুভ সকাল', 'শুভ সন্ধ্যা', 'কেমন আছ', 'তুমি কে', 'আপনি কে', 'কী পারো', 'কি পারো', 'কী করতে পার', 'কি করতে পার', 'সাহায্য', 'হেল্প', 'hi', 'hello', 'hey', 'salam', 'assalamu', 'help', 'who are you', 'what can you do' ),
			'trending' => array( 'ট্রেন্ডিং', 'ট্রেন্ড', 'আলোচিত', 'জনপ্রিয়', 'ভাইরাল', 'সবচেয়ে বেশি পড়া', 'বেশি পড়া', 'সর্বাধিক পঠিত', 'সর্বাধিক', 'হট', 'trending', 'trend', 'viral', 'popular', 'most read', 'hot news' ),
			'live'     => array( 'লাইভ', 'সরাসরি', 'live' ),
			'today'    => array( 'আজ', 'আজকে', 'আজকের', 'এখন', 'এইমাত্র', 'today', 'aj', 'aaj', 'ajke', 'ajker', 'aajker', 'now' ),
			'latest'   => array( 'সর্বশেষ', 'সাম্প্রতিক', 'লেটেস্ট', 'নতুন খবর', 'নতুন কী', 'নতুন কি', 'ব্রেকিং', 'তাজা', 'latest', 'recent', 'breaking', 'update', 'new news' ),
			'country'  => array( 'দেশে', 'দেশের', 'দেশ', 'সারাদেশ', 'সারা দেশ', 'বাংলাদেশ', 'জাতীয়', 'desh', 'deshe', 'desher', 'bangladesh', 'country', 'national' ),
			'happen'   => array( 'কী হচ্ছে', 'কি হচ্ছে', 'কী ঘটছে', 'কি ঘটছে', 'কী হলো', 'কি হলো', 'কী খবর', 'কি খবর', 'ki hocche', 'ki hoche', 'ki hoyeche', 'what is happening', 'whats happening', 'what happened' ),
			'mine'     => array( 'আমার জেলা', 'আমার এলাকা', 'আমার শহর', 'আমার জেলার', 'my district', 'my area', 'amar jela', 'amar zila' ),
		)
	);
}

/**
 * Filler words dropped before a free-text search.
 *
 * @return string[] normalised
 */
function meeha_as_fillers() {
	static $f = null;
	if ( null === $f ) {
		$raw = array( 'খবর', 'খবরের', 'খবরগুলো', 'সংবাদ', 'নিউজ', 'বলো', 'বলুন', 'বল', 'দাও', 'দিন', 'জানাও', 'জানান', 'দেখাও', 'দেখান', 'চাই', 'জানতে', 'সম্পর্কে', 'বিষয়ে', 'বিষয়', 'ব্যাপারে', 'নিয়ে', 'নিয়ে', 'কী', 'কি', 'কে', 'কোথায়', 'কেন', 'কবে', 'কেমন', 'হচ্ছে', 'হলো', 'হয়েছে', 'ঘটছে', 'চলছে', 'আছে', 'আজ', 'আজকে', 'আজকের', 'এখন', 'সর্বশেষ', 'সাম্প্রতিক', 'লেটেস্ট', 'আপডেট', 'মিহা', 'প্লিজ', 'একটু', 'আমাকে', 'আমার', 'please', 'news', 'khobor', 'khabar', 'tell', 'me', 'about', 'latest', 'update', 'show', 'what', 'is', 'the', 'on', 'of', 'in', 'today', 'give', 'any' );
		$f   = array_values( array_unique( array_map( 'meeha_as_norm', $raw ) ) );
	}
	return $f;
}

/**
 * District table: Bengali name => [division, aliases…]. Aliases cover romanised and older spellings.
 *
 * @return array<string,array>
 */
function meeha_as_districts() {
	static $map = null;
	if ( null !== $map ) {
		return $map;
	}
	$extra = array(
		'ঢাকা'            => array( 'dhaka', 'dacca' ),
		'গাজীপুর'         => array( 'gazipur', 'gajipur' ),
		'নারায়ণগঞ্জ'      => array( 'narayanganj', 'narayangonj', 'নারায়নগঞ্জ' ),
		'টাঙ্গাইল'         => array( 'tangail', 'টাংগাইল' ),
		'কিশোরগঞ্জ'       => array( 'kishoreganj', 'kishorganj' ),
		'মানিকগঞ্জ'        => array( 'manikganj' ),
		'মুন্সিগঞ্জ'        => array( 'munshiganj', 'মুন্সীগঞ্জ' ),
		'নরসিংদী'         => array( 'narsingdi', 'narsingdhi' ),
		'রাজবাড়ী'         => array( 'rajbari', 'রাজবাড়ি' ),
		'ফরিদপুর'         => array( 'faridpur' ),
		'গোপালগঞ্জ'        => array( 'gopalganj' ),
		'মাদারীপুর'        => array( 'madaripur', 'মাদারিপুর' ),
		'শরীয়তপুর'        => array( 'shariatpur', 'শরিয়তপুর' ),
		'চট্টগ্রাম'         => array( 'chattogram', 'chittagong', 'ctg', 'চাটগাঁ' ),
		'কক্সবাজার'        => array( 'coxsbazar', 'cox s bazar', 'coxs bazar', 'coxbazar', 'কক্স বাজার' ),
		'রাঙ্গামাটি'        => array( 'rangamati', 'রাঙামাটি' ),
		'বান্দরবান'        => array( 'bandarban' ),
		'খাগড়াছড়ি'       => array( 'khagrachhari', 'khagrachari' ),
		'ফেনী'            => array( 'feni' ),
		'নোয়াখালী'        => array( 'noakhali' ),
		'লক্ষ্মীপুর'        => array( 'lakshmipur', 'laxmipur', 'লক্ষীপুর' ),
		'কুমিল্লা'          => array( 'cumilla', 'comilla', 'kumilla' ),
		'চাঁদপুর'          => array( 'chandpur' ),
		'ব্রাহ্মণবাড়িয়া'    => array( 'brahmanbaria', 'b baria', 'ব্রাক্ষণবাড়িয়া', 'বি বাড়িয়া' ),
		'রাজশাহী'         => array( 'rajshahi' ),
		'নাটোর'           => array( 'natore' ),
		'নওগাঁ'           => array( 'naogaon' ),
		'চাঁপাইনবাবগঞ্জ'    => array( 'chapainawabganj', 'chapai nawabganj', 'চাঁপাই নবাবগঞ্জ', 'চাঁপাই' ),
		'পাবনা'           => array( 'pabna' ),
		'সিরাজগঞ্জ'        => array( 'sirajganj' ),
		'বগুড়া'           => array( 'bogura', 'bogra' ),
		'জয়পুরহাট'        => array( 'joypurhat', 'jaipurhat' ),
		'খুলনা'           => array( 'khulna' ),
		'বাগেরহাট'         => array( 'bagerhat' ),
		'সাতক্ষীরা'        => array( 'satkhira' ),
		'যশোর'            => array( 'jashore', 'jessore', 'যশোহর' ),
		'ঝিনাইদহ'         => array( 'jhenaidah', 'jhenidah' ),
		'মাগুরা'           => array( 'magura' ),
		'নড়াইল'           => array( 'narail' ),
		'কুষ্টিয়া'          => array( 'kushtia' ),
		'চুয়াডাঙ্গা'        => array( 'chuadanga' ),
		'মেহেরপুর'         => array( 'meherpur' ),
		'বরিশাল'          => array( 'barishal', 'barisal' ),
		'পটুয়াখালী'        => array( 'patuakhali' ),
		'ভোলা'            => array( 'bhola' ),
		'পিরোজপুর'         => array( 'pirojpur' ),
		'বরগুনা'           => array( 'barguna' ),
		'ঝালকাঠি'          => array( 'jhalakathi', 'jhalokathi', 'ঝালকাঠী' ),
		'সিলেট'           => array( 'sylhet' ),
		'মৌলভীবাজার'       => array( 'moulvibazar', 'maulvibazar', 'মৌলভী বাজার' ),
		'হবিগঞ্জ'          => array( 'habiganj' ),
		'সুনামগঞ্জ'         => array( 'sunamganj' ),
		'রংপুর'           => array( 'rangpur' ),
		'দিনাজপুর'         => array( 'dinajpur' ),
		'ঠাকুরগাঁও'        => array( 'thakurgaon' ),
		'পঞ্চগড়'          => array( 'panchagarh' ),
		'নীলফামারী'        => array( 'nilphamari' ),
		'লালমনিরহাট'       => array( 'lalmonirhat' ),
		'কুড়িগ্রাম'         => array( 'kurigram' ),
		'গাইবান্ধা'         => array( 'gaibandha' ),
		'ময়মনসিংহ'        => array( 'mymensingh', 'moymonsingh' ),
		'জামালপুর'         => array( 'jamalpur' ),
		'শেরপুর'          => array( 'sherpur' ),
		'নেত্রকোণা'         => array( 'netrokona', 'netrakona', 'নেত্রকোনা' ),
	);
	$divs = function_exists( 'meeha_get_bd_divisions' ) ? (array) meeha_get_bd_divisions() : array();
	$map  = array();
	foreach ( $divs as $div => $list ) {
		foreach ( (array) $list as $d ) {
			$map[ $d ] = array_merge( array( $div, $d ), isset( $extra[ $d ] ) ? $extra[ $d ] : array() );
		}
	}
	if ( ! $map ) { // foundation missing: districts without divisions.
		foreach ( $extra as $d => $al ) {
			$map[ $d ] = array_merge( array( '', $d ), $al );
		}
	}
	return $map;
}

/**
 * Division names (Bengali) with romanised aliases.
 *
 * @return array<string,string[]>
 */
function meeha_as_divisions() {
	return array(
		'ঢাকা'      => array( 'ঢাকা', 'dhaka' ),
		'চট্টগ্রাম'   => array( 'চট্টগ্রাম', 'chattogram', 'chittagong' ),
		'রাজশাহী'    => array( 'রাজশাহী', 'rajshahi' ),
		'খুলনা'     => array( 'খুলনা', 'khulna' ),
		'বরিশাল'    => array( 'বরিশাল', 'barishal', 'barisal' ),
		'সিলেট'     => array( 'সিলেট', 'sylhet' ),
		'রংপুর'     => array( 'রংপুর', 'rangpur' ),
		'ময়মনসিংহ'  => array( 'ময়মনসিংহ', 'mymensingh' ),
	);
}

/**
 * Find a district (or division) in a normalised question.
 *
 * @param string $q Normalised question.
 * @return array|null [name, level district|division, matched needle]
 */
function meeha_as_find_area( $q ) {
	foreach ( meeha_as_divisions() as $name => $aliases ) {
		foreach ( $aliases as $alias ) {
			if ( meeha_as_has( $q, $alias . ' বিভাগ' ) || meeha_as_has( $q, $alias . ' division' ) ) {
				return array( $name, 'division', $alias . ' বিভাগ' );
			}
		}
	}
	$best = null;
	foreach ( meeha_as_districts() as $name => $row ) {
		foreach ( array_slice( $row, 1 ) as $alias ) {
			if ( meeha_as_has( $q, $alias ) && ( ! $best || mb_strlen( $alias ) > mb_strlen( $best[2] ) ) ) {
				$best = array( $name, 'district', $alias );
			}
		}
	}
	return $best;
}

/**
 * Validate a district name sent by the browser (the remembered "আমার জেলা").
 *
 * @param string $d Name.
 * @return string
 */
function meeha_as_valid_district( $d ) {
	$d = trim( sanitize_text_field( (string) $d ) );
	return isset( meeha_as_districts()[ $d ] ) ? $d : '';
}

/**
 * Category groups: group => [needles matched against category slug + name, words a reader types].
 *
 * @return array<string,array>
 */
function meeha_as_cat_groups() {
	return (array) apply_filters(
		'meeha_assistant_cat_groups',
		array(
			'sports'        => array( 'label' => 'খেলা', 'match' => array( 'sport', 'খেলা', 'ক্রীড়া' ), 'words' => array( 'খেলা', 'খেলার', 'খেলাধুলা', 'ক্রীড়া', 'ক্রিকেট', 'ফুটবল', 'sports', 'sport', 'khela', 'khelar', 'cricket', 'football' ) ),
			'entertainment' => array( 'label' => 'বিনোদন', 'match' => array( 'entertain', 'বিনোদন' ), 'words' => array( 'বিনোদন', 'সিনেমা', 'নাটক', 'তারকা', 'entertainment', 'binodon', 'movie', 'cinema' ) ),
			'politics'      => array( 'label' => 'রাজনীতি', 'match' => array( 'politic', 'রাজনীতি' ), 'words' => array( 'রাজনীতি', 'রাজনৈতিক', 'politics', 'rajniti' ) ),
			'economy'       => array( 'label' => 'অর্থনীতি', 'match' => array( 'economy', 'business', 'অর্থনীতি', 'বাণিজ্য' ), 'words' => array( 'অর্থনীতি', 'অর্থনৈতিক', 'বাণিজ্য', 'ব্যবসা', 'শেয়ার', 'বাজার দর', 'economy', 'business', 'orthoniti' ) ),
			'world'         => array( 'label' => 'বিশ্ব', 'match' => array( 'world', 'international', 'বিশ্ব', 'আন্তর্জাতিক' ), 'words' => array( 'বিশ্ব', 'আন্তর্জাতিক', 'বিদেশ', 'দুনিয়া', 'world', 'international', 'global' ) ),
			'tech'          => array( 'label' => 'প্রযুক্তি', 'match' => array( 'tech', 'প্রযুক্তি' ), 'words' => array( 'প্রযুক্তি', 'টেক', 'মোবাইল', 'ইন্টারনেট', 'tech', 'technology' ) ),
			'health'        => array( 'label' => 'স্বাস্থ্য', 'match' => array( 'health', 'স্বাস্থ্য' ), 'words' => array( 'স্বাস্থ্য', 'চিকিৎসা', 'হাসপাতাল', 'health' ) ),
			'education'     => array( 'label' => 'শিক্ষা', 'match' => array( 'education', 'campus', 'শিক্ষা' ), 'words' => array( 'শিক্ষা', 'পরীক্ষা', 'ক্যাম্পাস', 'বিশ্ববিদ্যালয়', 'education', 'exam', 'campus' ) ),
			'religion'      => array( 'label' => 'ধর্ম', 'match' => array( 'relig', 'faith', 'ধর্ম', 'ইসলাম' ), 'words' => array( 'ধর্ম', 'ইসলাম', 'religion' ) ),
			'opinion'       => array( 'label' => 'মতামত', 'match' => array( 'opinion', 'editorial', 'মতামত', 'সম্পাদকীয়' ), 'words' => array( 'মতামত', 'সম্পাদকীয়', 'কলাম', 'opinion', 'editorial' ) ),
			'national'      => array( 'label' => 'জাতীয়', 'match' => array( 'national', 'bangladesh', 'country', 'জাতীয়', 'সারাদেশ', 'বাংলাদেশ', 'motherland' ), 'words' => array() ),
		)
	);
}

/**
 * Category ids for a group (cached per request).
 *
 * @param string $group Group.
 * @return int[]
 */
function meeha_as_group_cats( $group ) {
	static $cache = array();
	if ( isset( $cache[ $group ] ) ) {
		return $cache[ $group ];
	}
	$groups = meeha_as_cat_groups();
	$ids    = array();
	if ( isset( $groups[ $group ] ) ) {
		$cats = get_categories( array( 'hide_empty' => false ) );
		foreach ( (array) $cats as $c ) {
			$hay = mb_strtolower( $c->slug . ' ' . $c->name, 'UTF-8' );
			foreach ( $groups[ $group ]['match'] as $n ) {
				if ( false !== mb_strpos( $hay, mb_strtolower( $n, 'UTF-8' ) ) ) {
					$ids[] = (int) $c->term_id;
					break;
				}
			}
		}
	}
	$cache[ $group ] = $ids;
	return $ids;
}

/**
 * Find a category intent: a known group word or any category name the reader typed.
 *
 * @param string $q Normalised question.
 * @return array|null [label, int[] ids, matched needle]
 */
function meeha_as_find_category( $q ) {
	foreach ( meeha_as_cat_groups() as $g => $def ) {
		$hit = meeha_as_any( $q, $def['words'] );
		if ( $hit ) {
			$ids = meeha_as_group_cats( $g );
			if ( $ids ) {
				return array( $def['label'], $ids, $hit );
			}
		}
	}
	$best = null;
	foreach ( (array) get_categories( array( 'hide_empty' => true ) ) as $c ) {
		if ( 'uncategorized' === $c->slug || mb_strlen( $c->name ) < 3 ) {
			continue;
		}
		if ( meeha_as_has( $q, $c->name ) && ( ! $best || mb_strlen( $c->name ) > mb_strlen( $best[2] ) ) ) {
			$best = array( $c->name, array( (int) $c->term_id ), $c->name );
		}
	}
	return $best;
}

/**
 * Remove matched needles + fillers; what is left is the topic.
 *
 * @param string   $q       Normalised question.
 * @param string[] $remove  Raw needles already used.
 * @return string normalised topic ('' when nothing meaningful remains)
 */
function meeha_as_topic( $q, $remove = array() ) {
	foreach ( $remove as $r ) {
		$n = meeha_as_norm( $r );
		if ( '' !== $n ) {
			$q = (string) preg_replace( '/(?:^|\s)' . preg_quote( $n, '/' ) . '\S*/u', ' ', $q );
		}
	}
	$words = array();
	$fill  = meeha_as_fillers();
	foreach ( preg_split( '/\s+/u', trim( $q ) ) as $w ) {
		if ( '' === $w || in_array( $w, $fill, true ) ) {
			continue;
		}
		// Drop fillers with a case ending too (খবরটা, বলেন…): compare the first 3+ letters of fillers ≥ 4 letters.
		$skip = false;
		foreach ( $fill as $f ) {
			if ( mb_strlen( $f ) >= 3 && 0 === mb_strpos( $w, $f ) && mb_strlen( $w ) - mb_strlen( $f ) <= 2 ) {
				$skip = true;
				break;
			}
		}
		if ( ! $skip && ( mb_strlen( $w ) >= 2 || ctype_digit( $w ) ) ) {
			$words[] = $w;
		}
	}
	return implode( ' ', array_slice( $words, 0, 6 ) );
}

/**
 * Detect the intent of a question.
 *
 * @param string $question Raw question (already trimmed / stripped).
 * @param array  $ctx      district (remembered "আমার জেলা").
 * @return array intent + data (area, cat, topic, today, latest).
 */
function meeha_as_detect( $question, $ctx = array() ) {
	$q  = meeha_as_norm( $question );
	$kw = meeha_as_keywords();
	$r  = array(
		'intent' => 'search',
		'q'      => $q,
		'raw'    => (string) $question,
		'today'  => (bool) meeha_as_any( $q, $kw['today'] ),
		'latest' => (bool) meeha_as_any( $q, $kw['latest'] ),
	);
	if ( '' === $q ) {
		$r['intent'] = 'help';
		return $r;
	}

	$used = array();
	foreach ( array( 'today', 'latest', 'happen' ) as $g ) {
		$h = meeha_as_any( $q, $kw[ $g ] );
		if ( $h ) {
			$used[] = $h;
		}
	}

	// Help / greeting: a greeting word and (almost) nothing else.
	$hi = meeha_as_any( $q, $kw['help'] );
	if ( $hi && '' === meeha_as_topic( $q, array( $hi ) ) ) {
		$r['intent'] = 'help';
		return $r;
	}

	// r47 — questions ABOUT Meeha (কি/কীভাবে): the knowledge base answers before any news intent.
	if ( function_exists( 'meeha_as_kb_match' ) ) {
		$kb = meeha_as_kb_match( $q );
		if ( $kb ) {
			$r['intent'] = 'kb';
			$r['kb']     = $kb;
			return $r;
		}
	}

	// "আমার জেলা".
	$mine = meeha_as_any( $q, $kw['mine'] );
	if ( $mine ) {
		$d = meeha_as_valid_district( isset( $ctx['district'] ) ? $ctx['district'] : '' );
		$area = meeha_as_find_area( $q );
		if ( $area ) {
			$d = $area[0];
		}
		if ( ! $d ) {
			$r['intent'] = 'ask_district';
			return $r;
		}
		$r['intent'] = 'area';
		$r['area']   = array( $d, 'district' );
		return $r;
	}

	$live = meeha_as_any( $q, $kw['live'] );
	if ( $live ) {
		$r['intent'] = 'live';
		$r['topic']  = meeha_as_topic( $q, array_merge( $used, array( $live ) ) );
		return $r;
	}

	$tr = meeha_as_any( $q, $kw['trending'] );
	if ( $tr ) {
		$r['intent'] = 'trending';
		return $r;
	}

	$area = meeha_as_find_area( $q );
	if ( $area ) {
		$r['intent'] = 'area';
		$r['area']   = array( $area[0], $area[1] );
		$r['topic']  = meeha_as_topic( $q, array_merge( $used, array( $area[2] ) ) );
		return $r;
	}

	$cat = meeha_as_find_category( $q );
	if ( $cat ) {
		$r['intent'] = 'category';
		$r['cat']    = array( $cat[0], $cat[1] );
		$r['topic']  = meeha_as_topic( $q, array_merge( $used, array( $cat[2] ) ) );
		return $r;
	}

	$country = meeha_as_any( $q, $kw['country'] );
	if ( $country ) {
		$used[] = $country;
	}
	$topic = meeha_as_topic( $q, $used );
	if ( '' === $topic ) {
		$r['intent']  = $country ? 'country' : 'latest';
		return $r;
	}
	$r['topic'] = $topic;
	return $r;
}
