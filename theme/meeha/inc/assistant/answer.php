<?php
/**
 * Tier 1 answers: a short Bengali sentence + 3–6 news cards + follow-up chips, from the theme's own data.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

/**
 * Midnight today (Asia/Dhaka unless the site uses another real timezone) as a GMT MySQL datetime.
 *
 * @return string
 */
function meeha_as_today_gmt() {
	$tz = wp_timezone();
	if ( in_array( $tz->getName(), array( 'UTC', '+00:00' ), true ) ) {
		$tz = new DateTimeZone( 'Asia/Dhaka' );
	}
	$d = new DateTime( 'today', $tz );
	$d->setTimezone( new DateTimeZone( 'UTC' ) );
	return $d->format( 'Y-m-d H:i:s' );
}

/**
 * Base query args for public posts.
 *
 * @param int $n Count.
 * @return array
 */
function meeha_as_qargs( $n ) {
	return array(
		'post_type'              => 'post',
		'post_status'            => 'publish',
		'has_password'           => false,
		'posts_per_page'         => $n,
		'no_found_rows'          => true,
		'ignore_sticky_posts'    => true,
		'update_post_term_cache' => true,
		'orderby'                => 'date',
		'order'                  => 'DESC',
	);
}

/**
 * Run a query → WP_Post[].
 *
 * @param array $args Args (merged over meeha_as_qargs).
 * @param int   $n    Count.
 * @return WP_Post[]
 */
function meeha_as_posts( $args, $n = 6 ) {
	$q = new WP_Query( array_merge( meeha_as_qargs( $n ), $args ) );
	return array_values( array_filter( $q->posts, 'meeha_as_public' ) );
}

/**
 * Is a post safe to show to an anonymous reader?
 *
 * @param WP_Post $p Post.
 * @return bool
 */
function meeha_as_public( $p ) {
	return $p instanceof WP_Post && 'publish' === $p->post_status && '' === (string) $p->post_password && is_post_type_viewable( $p->post_type );
}

/**
 * Free-text search with the theme's search (normalised index, relevance order). Falls back to the longest single
 * term when all terms together find nothing.
 *
 * @param string $topic Normalised topic.
 * @param int    $n     Count.
 * @param array  $extra Extra query args (category / date / meta).
 * @return WP_Post[]
 */
function meeha_as_search( $topic, $n = 6, $extra = array() ) {
	$topic = trim( (string) $topic );
	if ( '' === $topic ) {
		return array();
	}
	$run = function ( $s ) use ( $n, $extra ) {
		$args = array_merge(
			meeha_as_qargs( $n ),
			array(
				's'            => $s,
				'meeha_search' => 1,
				'orderby'      => 'relevance',
			),
			$extra
		);
		$q = new WP_Query( $args );
		return array_values( array_filter( $q->posts, 'meeha_as_public' ) );
	};
	$posts = $run( $topic );
	if ( ! $posts && false !== strpos( $topic, ' ' ) ) {
		$words = explode( ' ', $topic );
		usort(
			$words,
			function ( $a, $b ) {
				return mb_strlen( $b ) - mb_strlen( $a );
			}
		);
		$posts = $run( $words[0] );
	}
	return $posts;
}

/**
 * Card payload for one post.
 *
 * @param WP_Post $p Post.
 * @return array
 */
function meeha_as_card( $p ) {
	$thumb = get_the_post_thumbnail_url( $p, 'medium' );
	return array(
		'id'     => (int) $p->ID,
		'title'  => html_entity_decode( wp_strip_all_tags( get_the_title( $p ) ), ENT_QUOTES, 'UTF-8' ),
		'url'    => get_permalink( $p ),
		'time'   => function_exists( 'meeha_post_time_ago' ) ? meeha_post_time_ago( $p ) : human_time_diff( (int) get_post_time( 'U', true, $p ) ) . ' আগে',
		'thumb'  => $thumb ? $thumb : '',
		'kicker' => function_exists( 'meeha_post_kicker' ) ? (string) meeha_post_kicker( $p ) : '',
		'live'   => 'live' === (string) get_post_meta( $p->ID, '_meeha_live_status', true ),
	);
}

/**
 * Bengali digits.
 *
 * @param mixed $v Value.
 * @return string
 */
function meeha_as_bn( $v ) {
	return function_exists( 'meeha_bn_digits' ) ? meeha_bn_digits( $v ) : (string) $v;
}

/**
 * Running live posts.
 *
 * @param int $n Count.
 * @return WP_Post[]
 */
function meeha_as_live_posts( $n = 6 ) {
	if ( function_exists( 'meeha_lb_running' ) ) {
		return array_values( array_filter( meeha_lb_running( $n ), 'meeha_as_public' ) );
	}
	return meeha_as_posts(
		array(
			'meta_key'   => '_meeha_live_status', // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value' => 'live', // phpcs:ignore WordPress.DB.SlowDBQuery
		),
		$n
	);
}

/**
 * Trending posts (velocity) → most read today → most read 7 days → latest.
 *
 * @param int $n Count.
 * @return WP_Post[]
 */
function meeha_as_trending_posts( $n = 6 ) {
	$out = array();
	if ( function_exists( 'meeha_get_trending' ) ) {
		foreach ( (array) meeha_get_trending( min( 8, $n ) ) as $i ) {
			if ( isset( $i['post'] ) && meeha_as_public( $i['post'] ) ) {
				$out[] = $i['post'];
			}
		}
	}
	if ( count( $out ) < 3 && function_exists( 'meeha_get_most_read' ) ) {
		foreach ( array( 'today', '7d' ) as $w ) {
			foreach ( (array) meeha_get_most_read( $w, $n ) as $p ) {
				if ( meeha_as_public( $p ) && ! in_array( $p->ID, wp_list_pluck( $out, 'ID' ), true ) ) {
					$out[] = $p;
				}
			}
			if ( count( $out ) >= 3 ) {
				break;
			}
		}
	}
	return array_slice( $out, 0, $n );
}

/**
 * Today's posts first, topped up with the newest older ones.
 *
 * @param array $args  Extra args.
 * @param int   $n     Count.
 * @return array [posts, today_count]
 */
function meeha_as_today_then_latest( $args, $n = 6 ) {
	$today = meeha_as_posts(
		array_merge(
			$args,
			array(
				'date_query' => array(
					array(
						'column' => 'post_date_gmt',
						'after'  => meeha_as_today_gmt(),
					),
				),
			)
		),
		$n
	);
	$count = count( $today );
	if ( $count < 3 ) {
		$more  = meeha_as_posts( array_merge( $args, array( 'post__not_in' => wp_list_pluck( $today, 'ID' ) ) ), $n - $count );
		$today = array_merge( $today, $more );
	}
	return array( $today, $count );
}

/**
 * Posts for a district or division: area meta first, then posts that mention the name.
 *
 * @param string $name  Bengali name.
 * @param string $level district|division.
 * @param bool   $today Prefer today.
 * @param string $topic Optional topic.
 * @param int    $n     Count.
 * @return array [posts, today_count]
 */
function meeha_as_area_posts( $name, $level, $today, $topic = '', $n = 6 ) {
	$meta = 'division' === $level
		? array( array( 'key' => '_meeha_division', 'value' => $name ) )
		: array(
			'relation' => 'OR',
			array( 'key' => '_meeha_district', 'value' => $name ),
			array( 'key' => '_meeha_upazila', 'value' => $name ),
		);
	$args = array( 'meta_query' => $meta ); // phpcs:ignore WordPress.DB.SlowDBQuery
	if ( '' !== $topic ) {
		$posts = meeha_as_search( $topic, $n, $args );
		if ( $posts ) {
			return array( $posts, -1 );
		}
	}
	list( $posts, $tc ) = $today ? meeha_as_today_then_latest( $args, $n ) : array( meeha_as_posts( $args, $n ), -1 );
	if ( count( $posts ) < 3 ) {
		$ids = wp_list_pluck( $posts, 'ID' );
		foreach ( meeha_as_search( meeha_as_norm( $name ), $n ) as $p ) {
			if ( ! in_array( $p->ID, $ids, true ) ) {
				$posts[] = $p;
			}
		}
		usort(
			$posts,
			function ( $a, $b ) {
				return strcmp( $b->post_date_gmt, $a->post_date_gmt );
			}
		);
	}
	return array( array_slice( $posts, 0, $n ), $tc );
}

/**
 * The help text and chips.
 *
 * @return array
 */
function meeha_as_help_answer() {
	return array(
		'text'  => "আমি মিহা নিউজের প্রকাশিত খবর থেকে উত্তর দিই, আর মিহা সম্পর্কিত প্রশ্নেরও। যেমন জিজ্ঞেস করতে পারেন:\n• আজকের ট্রেন্ডিং খবর\n• আজ দেশে কী হচ্ছে\n• কোনো জেলার খবর — যেমন \"কুমিল্লার খবর\"\n• বিভাগের খবর — খেলা, বিনোদন, রাজনীতি…\n• এখন কী লাইভ চলছে\n• যেকোনো বিষয়ে সর্বশেষ — যেমন \"ডেঙ্গু নিয়ে সর্বশেষ\"\n• মিহা নিউজ কী, কিভাবে লেখা পাঠাব, ভুল পেলে কিভাবে রিপোর্ট করব",
		'cards' => array(),
		'chips' => array( 'আজকের ট্রেন্ডিং', 'আজ দেশে কী হচ্ছে', 'আমার জেলার খবর', 'লাইভ', 'খেলা', 'বিনোদন' ),
	);
}

/**
 * The reader's own words for a (normalised) topic: raw words whose normalised form is in the topic.
 *
 * @param string $raw   Raw question.
 * @param string $topic Normalised topic.
 * @return string
 */
function meeha_as_display_topic( $raw, $topic ) {
	$want = explode( ' ', $topic );
	$keep = array();
	foreach ( preg_split( '/[\s,।?!]+/u', (string) $raw ) as $w ) {
		$n = meeha_as_norm( $w );
		if ( '' !== $n && in_array( $n, $want, true ) ) {
			$keep[] = trim( $w, " \t\"'“”‘’" );
		}
	}
	return $keep ? implode( ' ', $keep ) : $topic;
}

/**
 * Build a tier 1 answer.
 *
 * @param array $in Detected intent (meeha_as_detect()).
 * @param int   $n  Max cards.
 * @return array text, cards, chips, intent, (ask), posts (WP_Post[] for the AI tier; removed before output)
 */
function meeha_as_basic_answer( $in, $n = 6 ) {
	$intent = $in['intent'];
	$posts  = array();
	$chips  = array();
	$text   = '';
	$extra  = array();

	switch ( $intent ) {
		case 'kb':
			$kb    = isset( $in['kb'] ) ? $in['kb'] : array( 'text' => '', 'links' => array() );
			$cards = array();
			foreach ( (array) $kb['links'] as $l ) {
				$cards[] = array( 'id' => 0, 'title' => $l['t'], 'url' => $l['u'], 'time' => '', 'thumb' => '', 'kicker' => 'লিংক', 'live' => false );
			}
			return array(
				'intent' => 'kb',
				'text'   => (string) $kb['text'],
				'cards'  => $cards,
				'chips'  => array( 'আজকের ট্রেন্ডিং', 'আজ দেশে কী হচ্ছে' ),
				'posts'  => array(),
			);
		case 'help':
			return array_merge( meeha_as_help_answer(), array( 'intent' => 'help', 'posts' => array() ) );

		case 'ask_district':
			return array(
				'intent' => 'ask_district',
				'text'   => 'আপনার জেলার নাম বেছে নিন বা লিখুন — যেমন "কুমিল্লা"। পরের বার মনে রাখব।',
				'cards'  => array(),
				'chips'  => array( 'ঢাকা', 'চট্টগ্রাম', 'কুমিল্লা', 'সিলেট' ),
				'ask'    => 'district',
				'options' => array_keys( meeha_as_districts() ),
				'posts'  => array(),
			);

		case 'live':
			$posts = meeha_as_live_posts( $n );
			if ( ! empty( $in['topic'] ) && $posts ) {
				$hit = array_values(
					array_filter(
						$posts,
						function ( $p ) use ( $in ) {
							return false !== mb_strpos( meeha_as_norm( get_the_title( $p ) ), $in['topic'] );
						}
					)
				);
				$posts = $hit ? $hit : $posts;
			}
			$text  = $posts
				? sprintf( 'এখন মিহা নিউজে %sটি লাইভ চলছে:', meeha_as_bn( count( $posts ) ) )
				: 'এই মুহূর্তে কোনো লাইভ চলছে না। সর্বশেষ খবর দেখুন:';
			if ( ! $posts ) {
				list( $posts ) = meeha_as_today_then_latest( array(), 4 );
			}
			$chips = array( 'আজকের ট্রেন্ডিং', 'আজ দেশে কী হচ্ছে', 'খেলা' );
			break;

		case 'trending':
			$posts = meeha_as_trending_posts( $n );
			$text  = 'এখন মিহা নিউজে সবচেয়ে বেশি পড়া হচ্ছে:';
			if ( ! $posts ) {
				list( $posts ) = meeha_as_today_then_latest( array(), $n );
				$text          = 'ট্রেন্ডিং তালিকা এখনো তৈরি হয়নি — আজকের সর্বশেষ খবর:';
			}
			$chips = array( 'আজ দেশে কী হচ্ছে', 'লাইভ', 'খেলা', 'বিনোদন' );
			break;

		case 'area':
			list( $name, $level ) = $in['area'];
			$topic                = isset( $in['topic'] ) ? $in['topic'] : '';
			list( $posts, $tc )   = meeha_as_area_posts( $name, $level, true, $topic, $n );
			$label                = 'division' === $level ? $name . ' বিভাগ' : $name;
			if ( ! $posts ) {
				$text = sprintf( 'এ বিষয়ে মিহা নিউজে এখনো খবর নেই — %s থেকে কোনো খবর পাইনি।', $label );
			} elseif ( $tc > 0 ) {
				$text = sprintf( 'আজ %s থেকে মিহা নিউজে %sটি খবর:', $label, meeha_as_bn( $tc ) );
			} elseif ( 0 === $tc ) {
				$text = sprintf( 'আজ %s থেকে নতুন খবর আসেনি। সর্বশেষ খবরগুলো:', $label );
			} else {
				$text = sprintf( '%s-এর সর্বশেষ খবর:', $label );
			}
			$extra['district'] = 'district' === $level ? $name : '';
			$chips             = array( 'আজকের ট্রেন্ডিং', 'আজ দেশে কী হচ্ছে', 'লাইভ' );
			break;

		case 'category':
			list( $label, $ids ) = $in['cat'];
			$args                = array( 'category__in' => $ids );
			$topic               = isset( $in['topic'] ) ? $in['topic'] : '';
			$tc                  = -1;
			if ( '' !== $topic ) {
				$posts = meeha_as_search( $topic, $n, $args );
			}
			if ( ! $posts ) {
				list( $posts, $tc ) = $in['today'] ? meeha_as_today_then_latest( $args, $n ) : array( meeha_as_posts( $args, $n ), -1 );
			}
			$text  = $tc > 0 ? sprintf( 'আজকের %s খবর:', $label ) : sprintf( '%s বিভাগের সর্বশেষ খবর:', $label );
			$chips = array_values( array_diff( array( 'খেলা', 'বিনোদন', 'রাজনীতি', 'আজকের ট্রেন্ডিং' ), array( $label ) ) );
			break;

		case 'country':
		case 'latest':
			$args = array();
			if ( 'country' === $intent ) {
				$ids = meeha_as_group_cats( 'national' );
				if ( $ids ) {
					$args = array( 'category__in' => $ids );
				}
			}
			list( $posts, $tc ) = meeha_as_today_then_latest( $args, $n );
			if ( ! $posts && $args ) {
				list( $posts, $tc ) = meeha_as_today_then_latest( array(), $n );
			}
			if ( $tc > 0 ) {
				$text = 'country' === $intent ? 'আজ দেশে যা ঘটছে — মিহা নিউজের সর্বশেষ খবর:' : 'আজকের সর্বশেষ খবর:';
			} else {
				$text = 'আজ এখনো নতুন খবর প্রকাশ হয়নি। সর্বশেষ খবরগুলো:';
			}
			$chips = array( 'আজকের ট্রেন্ডিং', 'আমার জেলার খবর', 'লাইভ', 'খেলা' );
			break;

		default: // search.
			$topic = isset( $in['topic'] ) ? $in['topic'] : $in['q'];
			$posts = meeha_as_search( $topic, $n );
			if ( $posts && $in['latest'] ) {
				usort(
					$posts,
					function ( $a, $b ) {
						return strcmp( $b->post_date_gmt, $a->post_date_gmt );
					}
				);
			}
			$text  = $posts
				? sprintf( '"%s" বিষয়ে মিহা নিউজের %s খবর:', mb_substr( meeha_as_display_topic( isset( $in['raw'] ) ? $in['raw'] : '', $topic ), 0, 40 ), $in['latest'] ? 'সর্বশেষ' : 'প্রাসঙ্গিক' )
				: 'এ বিষয়ে মিহা নিউজে এখনো খবর নেই।';
			$chips = $posts ? array( 'আজকের ট্রেন্ডিং', 'আজ দেশে কী হচ্ছে' ) : array( 'আজকের ট্রেন্ডিং', 'আজ দেশে কী হচ্ছে', 'লাইভ', 'সাহায্য' );
			break;
	}

	$posts = array_slice( $posts, 0, $n );
	return array_merge(
		array(
			'intent' => $intent,
			'text'   => $text,
			'cards'  => array_map( 'meeha_as_card', $posts ),
			'chips'  => array_slice( $chips, 0, 4 ),
			'posts'  => $posts,
		),
		$extra
	);
}
