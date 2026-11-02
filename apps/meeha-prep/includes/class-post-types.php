<?php
/**
 * Post types and taxonomies. None is publicly queryable: the section hub (theme) renders them under its own path,
 * so there is exactly one URL per item and the beta path stays private.
 *
 *   mh_job       চাকরির বিজ্ঞপ্তি   (pending = যাচাই বাকি)
 *   mh_question  প্রশ্ন ব্যাংক       (pending = অনুমোদন বাকি)
 *   mh_test      মডেল/মক টেস্ট
 *   mh_product   প্যাক, ই-বুক ও সেবা
 *   mh_event     ইভেন্ট
 *   mh_track     ট্র্যাক (বিসিএস, ব্যাংক…) — on questions, tests, products
 *   mh_subject   বিষয় (বাংলা, গণিত…)      — on questions
 *   mh_job_type  চাকরির ধরন (সরকারি, ব্যাংক…) — on jobs
 *
 * @package meeha-prep
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registration.
 */
class MHP_Post_Types {

	const TYPES = array( 'mh_job', 'mh_question', 'mh_test', 'mh_product', 'mh_event' );

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ), 5 );
		add_filter( 'user_has_cap', array( __CLASS__, 'admin_caps' ) );
	}

	/**
	 * Administrators always hold the plugin's capabilities.
	 *
	 * @param array $allcaps Caps.
	 * @return array
	 */
	public static function admin_caps( $allcaps ) {
		if ( ! empty( $allcaps['manage_options'] ) ) {
			foreach ( self::primitive_caps() as $c ) {
				$allcaps[ $c ] = true;
			}
			$allcaps['mhp_manage']        = true;
			$allcaps['mhp_manage_orders'] = true;
		}
		return $allcaps;
	}

	/**
	 * Primitive caps of the shared capability type.
	 *
	 * @return string[]
	 */
	public static function primitive_caps() {
		return array( 'edit_mhp_items', 'edit_others_mhp_items', 'publish_mhp_items', 'read_private_mhp_items', 'delete_mhp_items', 'delete_private_mhp_items', 'delete_published_mhp_items', 'delete_others_mhp_items', 'edit_private_mhp_items', 'edit_published_mhp_items' );
	}

	/**
	 * Labels helper.
	 *
	 * @param string $name     Plural.
	 * @param string $singular Singular.
	 * @return array
	 */
	private static function labels( $name, $singular ) {
		return array(
			'name'               => $name,
			'singular_name'      => $singular,
			'menu_name'          => $name,
			'add_new'            => 'নতুন যোগ',
			'add_new_item'       => 'নতুন ' . $singular,
			'edit_item'          => $singular . ' সম্পাদনা',
			'new_item'           => 'নতুন ' . $singular,
			'view_item'          => $singular . ' দেখুন',
			'search_items'       => $name . ' খুঁজুন',
			'not_found'          => 'কিছু পাওয়া যায়নি',
			'not_found_in_trash' => 'ট্র্যাশে কিছু নেই',
			'all_items'          => $name,
		);
	}

	/**
	 * Register everything.
	 */
	public static function register() {
		$common = array(
			'public'              => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_ui'             => true,
			'show_in_menu'        => 'mhp',
			'show_in_rest'        => false,
			'has_archive'         => false,
			'rewrite'             => false,
			'query_var'           => false,
			'capability_type'     => array( 'mhp_item', 'mhp_items' ),
			'map_meta_cap'        => true,
		);
		register_post_type(
			'mh_job',
			array_merge(
				$common,
				array(
					'labels'   => self::labels( 'চাকরির বিজ্ঞপ্তি', 'বিজ্ঞপ্তি' ),
					'supports' => array( 'title', 'editor', 'revisions' ),
				)
			)
		);
		register_post_type(
			'mh_question',
			array_merge(
				$common,
				array(
					'labels'   => self::labels( 'প্রশ্ন ব্যাংক', 'প্রশ্ন' ),
					'supports' => array( 'title', 'revisions' ),
				)
			)
		);
		register_post_type(
			'mh_test',
			array_merge(
				$common,
				array(
					'labels'   => self::labels( 'টেস্ট', 'টেস্ট' ),
					'supports' => array( 'title', 'excerpt', 'page-attributes' ),
				)
			)
		);
		register_post_type(
			'mh_product',
			array_merge(
				$common,
				array(
					'labels'   => self::labels( 'প্যাক, ই-বুক ও সেবা', 'পণ্য' ),
					'supports' => array( 'title', 'excerpt', 'page-attributes' ),
				)
			)
		);
		register_post_type(
			'mh_event',
			array_merge(
				$common,
				array(
					'labels'   => self::labels( 'ইভেন্ট', 'ইভেন্ট' ),
					'supports' => array( 'title', 'editor', 'thumbnail' ),
				)
			)
		);

		$tax = array(
			'public'            => false,
			'publicly_queryable' => false,
			'show_ui'           => true,
			'show_admin_column' => true,
			'show_in_rest'      => false,
			'hierarchical'      => true,
			'rewrite'           => false,
			'query_var'         => false,
			'capabilities'      => array(
				'manage_terms' => 'mhp_manage',
				'edit_terms'   => 'mhp_manage',
				'delete_terms' => 'mhp_manage',
				'assign_terms' => 'edit_mhp_items',
			),
		);
		register_taxonomy(
			'mh_track',
			array( 'mh_question', 'mh_test', 'mh_product' ),
			array_merge(
				$tax,
				array(
					'labels' => array(
						'name'          => 'ট্র্যাক',
						'singular_name' => 'ট্র্যাক',
						'add_new_item'  => 'নতুন ট্র্যাক',
						'edit_item'     => 'ট্র্যাক সম্পাদনা',
						'menu_name'     => 'ট্র্যাক',
					),
				)
			)
		);
		register_taxonomy(
			'mh_subject',
			array( 'mh_question' ),
			array_merge(
				$tax,
				array(
					'labels' => array(
						'name'          => 'বিষয়',
						'singular_name' => 'বিষয়',
						'add_new_item'  => 'নতুন বিষয়',
						'edit_item'     => 'বিষয় সম্পাদনা',
						'menu_name'     => 'বিষয়',
					),
				)
			)
		);
		register_taxonomy(
			'mh_job_type',
			array( 'mh_job' ),
			array_merge(
				$tax,
				array(
					'labels' => array(
						'name'          => 'চাকরির ধরন',
						'singular_name' => 'ধরন',
						'add_new_item'  => 'নতুন ধরন',
						'edit_item'     => 'ধরন সম্পাদনা',
						'menu_name'     => 'চাকরির ধরন',
					),
				)
			)
		);
	}

	/**
	 * Typed meta getter.
	 *
	 * @param int    $post_id Post.
	 * @param string $key     Key without the `_mhp_` prefix.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	public static function meta( $post_id, $key, $default = '' ) {
		$v = get_post_meta( (int) $post_id, '_mhp_' . $key, true );
		return ( '' === $v || null === $v || false === $v ) ? $default : $v;
	}
}

/**
 * Shorthand for MHP_Post_Types::meta().
 *
 * @param int    $post_id Post.
 * @param string $key     Key.
 * @param mixed  $default Default.
 * @return mixed
 */
function mhp_meta( $post_id, $key, $default = '' ) {
	return MHP_Post_Types::meta( $post_id, $key, $default );
}

/**
 * Track term data (glyph, colour, description, syllabus rows, job type link).
 *
 * @param WP_Term|int $term Term.
 * @return array|null
 */
function mhp_track( $term ) {
	$term = is_object( $term ) ? $term : get_term( (int) $term, 'mh_track' );
	if ( ! $term || is_wp_error( $term ) ) {
		return null;
	}
	$syl  = array();
	$raw  = (string) get_term_meta( $term->term_id, 'mhp_syllabus', true );
	foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
		$parts = array_map( 'trim', explode( '|', $line ) );
		if ( '' !== $parts[0] ) {
			$syl[] = array( $parts[0], isset( $parts[1] ) ? (float) meeha_prep_en_digits( $parts[1] ) : 0 );
		}
	}
	$color = sanitize_hex_color( (string) get_term_meta( $term->term_id, 'mhp_color', true ) );
	return array(
		'id'       => (int) $term->term_id,
		'slug'     => $term->slug,
		'name'     => $term->name,
		'desc'     => (string) get_term_meta( $term->term_id, 'mhp_short', true ),
		'about'    => $term->description,
		'glyph'    => (string) get_term_meta( $term->term_id, 'mhp_glyph', true ),
		'color'    => $color ? $color : '#025AB2',
		'syllabus' => $syl,
		'syl_note' => (string) get_term_meta( $term->term_id, 'mhp_syllabus_note', true ),
		'job_type' => (int) get_term_meta( $term->term_id, 'mhp_job_type', true ),
		'is_new'   => (bool) get_term_meta( $term->term_id, 'mhp_is_new', true ),
		'order'    => (int) get_term_meta( $term->term_id, 'mhp_order', true ),
	);
}

/**
 * All tracks sorted by their order field.
 *
 * @return array[]
 */
function mhp_tracks() {
	$terms = get_terms(
		array(
			'taxonomy'   => 'mh_track',
			'hide_empty' => false,
		)
	);
	$out = array();
	foreach ( is_array( $terms ) ? $terms : array() as $t ) {
		$out[] = mhp_track( $t );
	}
	usort(
		$out,
		function ( $a, $b ) {
			return ( $a['order'] - $b['order'] ) ? $a['order'] - $b['order'] : strcmp( $a['name'], $b['name'] );
		}
	);
	return $out;
}

/**
 * English digits from Bengali digits.
 *
 * @param string $s String.
 * @return string
 */
function meeha_prep_en_digits( $s ) {
	return strtr( (string) $s, array( '০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9' ) );
}
