<?php
/**
 * Question bank packs (প্রস্তুতি › প্রশ্নব্যাংক): ready-made question sets shipped in data/bank/ — past papers
 * (stems and options as set in the exam, answers and explanations written by Meeha Prep) and original practice
 * sets per subject. Each pack imports as drafts with its source, past papers also get a draft test with the exam's
 * time and negative marking, and a teacher checks the printable review sheet before publishing.
 *
 * Questions whose answer needs a second look carry `_mhp_review` (unsure / timebound) and stay drafts when a pack
 * is published; publishing such a question from its edit screen clears the mark.
 *
 * @package meeha-prep
 */

defined( 'ABSPATH' ) || exit;

/**
 * Bank.
 */
class MHP_Bank {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_post_mhp_bank', array( __CLASS__, 'handle' ) );
		add_action( 'transition_post_status', array( __CLASS__, 'on_publish' ), 10, 3 );
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_review' ) );
		add_filter( 'views_edit-mh_question', array( __CLASS__, 'review_view' ) );
		add_filter( 'manage_mh_question_posts_columns', array( __CLASS__, 'cols' ), 20 );
		add_action( 'manage_mh_question_posts_custom_column', array( __CLASS__, 'col' ), 10, 2 );
	}

	/**
	 * Menu.
	 */
	public static function menu() {
		add_submenu_page( 'mhp', 'প্রশ্নব্যাংক', 'প্রশ্নব্যাংক', 'mhp_manage', 'mhp-bank', array( __CLASS__, 'page' ) );
	}

	/**
	 * Pack index: slug => meta.
	 *
	 * @return array
	 */
	public static function packs() {
		$f = MHP_DIR . '/data/bank/index.php';
		$p = file_exists( $f ) ? include $f : array();
		return is_array( $p ) ? $p : array();
	}

	/**
	 * One pack's questions.
	 *
	 * @param string $slug Pack slug.
	 * @return array Rows: subject, stem, options, answer (0-based), explanation, quiz, flag, number.
	 */
	public static function rows( $slug ) {
		if ( ! preg_match( '/^[a-z0-9-]+$/', $slug ) ) {
			return array();
		}
		$f = MHP_DIR . '/data/bank/' . $slug . '.php';
		$r = file_exists( $f ) ? include $f : array();
		return is_array( $r ) ? $r : array();
	}

	/**
	 * Ids of questions (or tests) tagged with a pack.
	 *
	 * @param string $slug   Pack.
	 * @param string $type   Post type.
	 * @param string $status Status or 'any'.
	 * @return int[]
	 */
	private static function ids( $slug, $type = 'mh_question', $status = 'any' ) {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => $type,
					'post_status'    => 'any' === $status ? array( 'publish', 'draft', 'pending', 'private' ) : $status,
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_key'       => '_mhp_pack', // phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_value'     => $slug, // phpcs:ignore WordPress.DB.SlowDBQuery
				)
			)
		);
	}

	/**
	 * Find a question by seed key (trash included, so a deleted question is not recreated).
	 *
	 * @param string $key Key.
	 * @return int
	 */
	private static function find( $key ) {
		$ids = get_posts(
			array(
				'post_type'      => array( 'mh_question', 'mh_test' ),
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => '_mhp_seed_key', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => $key, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Term id by slug, created when missing.
	 *
	 * @param string $slug Slug.
	 * @param string $tax  Taxonomy.
	 * @return int
	 */
	private static function term( $slug, $tax ) {
		$t = get_term_by( 'slug', $slug, $tax );
		if ( $t ) {
			return (int) $t->term_id;
		}
		if ( 'mh_track' === $tax ) {
			return 0; // Tracks carry syllabus/settings; never invent one here.
		}
		$names = array(
			'physics'    => 'পদার্থবিজ্ঞান',
			'chemistry'  => 'রসায়ন',
			'biology'    => 'জীববিজ্ঞান',
			'accounting' => 'হিসাববিজ্ঞান',
			'business'   => 'ব্যবসায় সংগঠন ও ব্যবস্থাপনা',
			'banking'    => 'ব্যাংকিং ও অর্থনীতি',
		);
		$r = wp_insert_term( isset( $names[ $slug ] ) ? $names[ $slug ] : $slug, $tax, array( 'slug' => $slug ) );
		return is_wp_error( $r ) ? 0 : (int) $r['term_id'];
	}

	/**
	 * Import one pack as drafts.
	 *
	 * @param string $slug Pack.
	 * @return array Counts.
	 */
	public static function import( $slug ) {
		$packs = self::packs();
		$out   = array(
			'added'  => 0,
			'linked' => 0,
			'skipped' => 0,
			'tests'  => 0,
		);
		if ( empty( $packs[ $slug ] ) ) {
			return $out;
		}
		$p = $packs[ $slug ];
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		wp_defer_term_counting( true );
		wp_suspend_cache_invalidation( true );

		$tracks = array();
		foreach ( (array) $p['tracks'] as $t ) {
			$id = self::term( $t, 'mh_track' );
			if ( $id ) {
				$tracks[] = $id;
			}
		}
		$subjects = array();
		$order    = array();
		foreach ( self::rows( $slug ) as $r ) {
			list( $subj, $text, $opts, $ans, $explain, $quiz, $flag ) = $r;
			if ( 'skip' === $flag || $ans < 0 || $ans > 3 || count( $opts ) < 2 || '' === trim( $text ) ) {
				++$out['skipped'];
				continue;
			}
			$key = 'bank-' . md5( $text . '|' . implode( '|', $opts ) );
			$qid = self::find( $key );
			if ( $qid ) {
				// Same question in another paper or set: link it, keep its first source.
				if ( 'trash' !== get_post_status( $qid ) ) {
					if ( ! in_array( $slug, get_post_meta( $qid, '_mhp_pack' ), true ) ) {
						add_post_meta( $qid, '_mhp_pack', $slug );
						if ( 'paper' === $p['kind'] ) {
							$also = (string) get_post_meta( $qid, '_mhp_source_also', true );
							update_post_meta( $qid, '_mhp_source_also', trim( $also . ( $also ? '; ' : '' ) . $p['source'] ) );
						}
					}
					if ( $tracks ) {
						wp_set_object_terms( $qid, $tracks, 'mh_track', true );
					}
					$order[] = $qid;
					++$out['linked'];
				}
				continue;
			}
			$qid = wp_insert_post(
				array(
					'post_type'   => 'mh_question',
					'post_status' => 'draft',
					'post_title'  => $text,
					'post_author' => get_current_user_id(),
				),
				true
			);
			if ( is_wp_error( $qid ) ) {
				++$out['skipped'];
				continue;
			}
			update_post_meta( $qid, '_mhp_seed_key', $key );
			add_post_meta( $qid, '_mhp_pack', $slug );
			update_post_meta( $qid, '_mhp_opts', array_values( $opts ) );
			update_post_meta( $qid, '_mhp_answer', (int) $ans );
			update_post_meta( $qid, '_mhp_explain', $explain );
			update_post_meta( $qid, '_mhp_quiz_ok', $quiz ? 1 : 0 );
			update_post_meta( $qid, '_mhp_source', $p['source'] );
			if ( 'ok' !== $flag ) {
				update_post_meta( $qid, '_mhp_review', $flag );
			}
			if ( ! isset( $subjects[ $subj ] ) ) {
				$subjects[ $subj ] = self::term( $subj, 'mh_subject' );
			}
			if ( $subjects[ $subj ] ) {
				wp_set_object_terms( $qid, array( $subjects[ $subj ] ), 'mh_subject' );
			}
			if ( $tracks ) {
				wp_set_object_terms( $qid, $tracks, 'mh_track' );
			}
			$order[] = (int) $qid;
			++$out['added'];
		}

		// Tests: the full paper, or practice sets of 25.
		$order = array_values( array_unique( $order ) );
		$sets  = 'paper' === $p['kind'] ? array( $order ) : array_chunk( $order, 25 );
		foreach ( $sets as $i => $ids ) {
			$key = 'bank-test-' . $slug . '-' . ( $i + 1 );
			if ( count( $ids ) < 5 || self::find( $key ) ) {
				continue;
			}
			$title = 'paper' === $p['kind'] ? $p['title'] : $p['title'] . ' — সেট ' . mhp_bn( $i + 1 );
			$tid   = wp_insert_post(
				array(
					'post_type'    => 'mh_test',
					'post_status'  => 'draft',
					'post_title'   => $title,
					'post_excerpt' => 'paper' === $p['kind'] ? 'আসল প্রশ্নে পূর্ণ পরীক্ষা, শেষে ব্যাখ্যাসহ উত্তর।' : 'বিষয়ভিত্তিক অনুশীলন, শেষে ব্যাখ্যাসহ উত্তর।',
					'post_author'  => get_current_user_id(),
				),
				true
			);
			if ( is_wp_error( $tid ) ) {
				continue;
			}
			update_post_meta( $tid, '_mhp_seed_key', $key );
			add_post_meta( $tid, '_mhp_pack', $slug );
			update_post_meta( $tid, '_mhp_questions', $ids );
			update_post_meta( $tid, '_mhp_price', 0 );
			update_post_meta( $tid, '_mhp_duration', 'paper' === $p['kind'] ? (int) $p['minutes'] : max( 10, count( $ids ) ) );
			update_post_meta( $tid, '_mhp_negative', (float) $p['negative'] );
			if ( $tracks ) {
				wp_set_object_terms( $tid, $tracks, 'mh_track' );
			}
			++$out['tests'];
		}

		wp_suspend_cache_invalidation( false );
		wp_defer_term_counting( false );
		$done          = (array) get_option( 'mhp_bank_done', array() );
		$done[ $slug ] = time();
		update_option( 'mhp_bank_done', $done, false );
		return $out;
	}

	/**
	 * Publish a pack: questions without a review mark, then its tests.
	 *
	 * @param string $slug Pack.
	 * @return array Counts.
	 */
	public static function publish( $slug ) {
		$out = array(
			'published' => 0,
			'held'      => 0,
			'tests'     => 0,
		);
		foreach ( self::ids( $slug, 'mh_question', 'draft' ) as $id ) {
			if ( get_post_meta( $id, '_mhp_review', true ) ) {
				++$out['held'];
				continue;
			}
			wp_update_post(
				array(
					'ID'          => $id,
					'post_status' => 'publish',
				)
			);
			++$out['published'];
		}
		foreach ( self::ids( $slug, 'mh_test', 'draft' ) as $id ) {
			wp_update_post(
				array(
					'ID'          => $id,
					'post_status' => 'publish',
				)
			);
			++$out['tests'];
		}
		return $out;
	}

	/**
	 * Whether a pack still has draft questions without a review mark (they would go live on publish).
	 *
	 * @param string $slug Pack.
	 * @return bool
	 */
	private static function unreviewed_drafts( $slug ) {
		foreach ( self::ids( $slug, 'mh_question', 'draft' ) as $id ) {
			if ( ! get_post_meta( $id, '_mhp_review', true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A teacher publishing a marked question from its edit screen has checked it.
	 *
	 * @param string  $new  New status.
	 * @param string  $old  Old status.
	 * @param WP_Post $post Post.
	 */
	public static function on_publish( $new, $old, $post ) {
		if ( 'publish' === $new && 'publish' !== $old && 'mh_question' === $post->post_type && ! empty( $_POST['mhp'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			delete_post_meta( $post->ID, '_mhp_review' );
		}
	}

	/**
	 * Admin list: ?mhp_review=1 shows marked questions, ?mhp_pack=slug one pack.
	 *
	 * @param WP_Query $q Query.
	 */
	public static function filter_review( $q ) {
		if ( ! is_admin() || ! $q->is_main_query() || 'mh_question' !== $q->get( 'post_type' ) ) {
			return;
		}
		$mq = array();
		if ( ! empty( $_GET['mhp_review'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$mq[] = array(
				'key'     => '_mhp_review',
				'compare' => 'EXISTS',
			);
		}
		$pack = isset( $_GET['mhp_pack'] ) ? sanitize_key( wp_unslash( $_GET['mhp_pack'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $pack ) {
			$mq[] = array(
				'key'   => '_mhp_pack',
				'value' => $pack,
			);
		}
		if ( $mq ) {
			$q->set( 'meta_query', $mq );
		}
	}

	/**
	 * "যাচাই দরকার" view link.
	 *
	 * @param array $v Views.
	 * @return array
	 */
	public static function review_view( $v ) {
		$n = count(
			get_posts(
				array(
					'post_type'      => 'mh_question',
					'post_status'    => array( 'draft', 'pending' ),
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_key'       => '_mhp_review', // phpcs:ignore WordPress.DB.SlowDBQuery
				)
			)
		);
		if ( $n ) {
			$cur              = ! empty( $_GET['mhp_review'] ) ? ' class="current"' : ''; // phpcs:ignore WordPress.Security.NonceVerification
			$v['mhp_review'] = '<a href="' . esc_url( admin_url( 'edit.php?post_type=mh_question&post_status=draft&mhp_review=1' ) ) . '"' . $cur . '>যাচাই দরকার <span class="count">(' . esc_html( mhp_num( $n ) ) . ')</span></a>';
		}
		return $v;
	}

	/**
	 * Source column.
	 *
	 * @param array $c Columns.
	 * @return array
	 */
	public static function cols( $c ) {
		$c['mhp_src'] = 'উৎস';
		return $c;
	}

	/**
	 * Source column cell.
	 *
	 * @param string $col Column.
	 * @param int    $id  Post.
	 */
	public static function col( $col, $id ) {
		if ( 'mhp_src' !== $col ) {
			return;
		}
		echo esc_html( (string) get_post_meta( $id, '_mhp_source', true ) ?: '—' );
		$r = (string) get_post_meta( $id, '_mhp_review', true );
		if ( $r ) {
			echo '<br><span style="color:#b32d2e">' . esc_html( self::flag_label( $r ) ) . '</span>';
		}
	}

	/**
	 * Review mark label.
	 *
	 * @param string $f Flag.
	 * @return string
	 */
	public static function flag_label( $f ) {
		$l = array(
			'unsure'    => 'উত্তর যাচাই দরকার',
			'timebound' => 'সময়ের সাথে বদলাতে পারে',
		);
		return isset( $l[ $f ] ) ? $l[ $f ] : $f;
	}

	/**
	 * Admin page: pack list, or a printable review sheet.
	 */
	public static function page() {
		if ( ! mhp_can_manage() ) {
			wp_die( esc_html( 'অনুমতি নেই।' ) );
		}
		$packs = self::packs();
		$sheet = isset( $_GET['sheet'] ) ? sanitize_key( wp_unslash( $_GET['sheet'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $sheet && isset( $packs[ $sheet ] ) ) {
			self::sheet( $sheet, $packs[ $sheet ] );
			return;
		}
		$r = get_transient( 'mhp_bank_' . get_current_user_id() );
		if ( $r ) {
			delete_transient( 'mhp_bank_' . get_current_user_id() );
		}
		$done  = (array) get_option( 'mhp_bank_done', array() );
		$kinds = array(
			'paper'    => 'বিগত সালের প্রশ্ন',
			'practice' => 'বিষয়ভিত্তিক অনুশীলনী (মিহা প্রস্তুতির নিজস্ব)',
		);
		$form  = function ( $op, $slug, $label, $primary = false, $confirm = '' ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline"' . ( $confirm ? ' onsubmit="return confirm(\'' . esc_js( $confirm ) . '\')"' : '' ) . '>';
			echo '<input type="hidden" name="action" value="mhp_bank"><input type="hidden" name="op" value="' . esc_attr( $op ) . '"><input type="hidden" name="pack" value="' . esc_attr( $slug ) . '">';
			wp_nonce_field( 'mhp_bank' );
			echo '<button class="button' . ( $primary ? ' button-primary' : '' ) . '">' . esc_html( $label ) . '</button></form> ';
		};
		?>
		<div class="wrap">
			<h1>প্রশ্নব্যাংক</h1>
			<?php if ( $r ) : ?>
				<div class="notice notice-success"><p><?php echo esc_html( $r ); ?></p></div>
			<?php endif; ?>
			<p>তৈরি প্রশ্নের সেট। <b>ইমপোর্ট</b> করলে সব প্রশ্ন ও টেস্ট খসড়া হিসেবে ঢোকে, সাইটে দেখা যায় না। শিক্ষক <b>রিভিউ শিট</b> প্রিন্ট করে উত্তর ও ব্যাখ্যা মিলিয়ে নেবেন, ভুল থাকলে প্রশ্নটি এডিট করবেন। তারপর <b>প্রকাশ</b> চাপলে "যাচাই দরকার" চিহ্ন ছাড়া প্রশ্ন আর টেস্টগুলো প্রকাশ হবে; চিহ্নিত প্রশ্ন এডিট স্ক্রিন থেকে একে একে প্রকাশ করতে হবে।</p>
			<p>বিগত সালের প্রশ্নের মূল লেখা ও অপশন সংশ্লিষ্ট পরীক্ষার; উত্তর ও ব্যাখ্যা মিহা প্রস্তুতির। প্রতিটি প্রশ্নে পরীক্ষার নাম ও সাল উৎস হিসেবে থাকে।</p>
			<?php
			$waiting = array();
			foreach ( $packs as $slug => $p ) {
				if ( isset( $done[ $slug ] ) && self::ids( $slug, 'mh_test', 'draft' ) ) {
					$waiting[] = $p['title'];
				}
			}
			if ( $waiting ) :
				?>
				<div class="notice notice-warning inline"><p><b><?php echo esc_html( mhp_num( count( $waiting ) ) ); ?>টি সেট ইমপোর্ট হয়েছে কিন্তু এখনো খসড়ায়, তাই সাইটে দেখা যাচ্ছে না:</b> <?php echo esc_html( implode( ', ', array_slice( $waiting, 0, 12 ) ) . ( count( $waiting ) > 12 ? ' …' : '' ) ); ?></p>
				<p>রিভিউ শেষ হলে নিচের সারিতে "প্রকাশ করুন" চাপুন, অথবা একবারে সব প্রকাশ করুন। "যাচাই দরকার" চিহ্নিত প্রশ্নগুলো খসড়াতেই থাকবে; টেস্টে সেগুলো বাদে বাকি প্রশ্ন আসবে।</p>
				<?php
				if ( current_user_can( 'publish_mhp_items' ) ) {
					$form( 'publish_all', '', 'রিভিউ-করা সব সেট প্রকাশ করুন', true, 'সব ইমপোর্ট-করা সেটের প্রশ্ন (যাচাই-চিহ্ন ছাড়া) ও টেস্ট প্রকাশ হবে। নিশ্চিত?' );
				}
				?>
				</div>
			<?php endif; ?>
			<p><?php $form( 'import_all', '', 'সব সেট ইমপোর্ট করুন (খসড়া)', true ); ?>
			<a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=mh_question&post_status=draft&mhp_review=1' ) ); ?>">যাচাই দরকার এমন প্রশ্ন</a></p>
			<?php foreach ( $kinds as $kind => $kl ) : ?>
				<h2><?php echo esc_html( $kl ); ?></h2>
				<table class="widefat striped" style="max-width:1100px">
					<thead><tr><th>সেট</th><th>প্রশ্ন</th><th>ট্র্যাক</th><th>সাইটে</th><th></th></tr></thead>
					<tbody>
					<?php
					foreach ( $packs as $slug => $p ) :
						if ( $kind !== $p['kind'] ) {
							continue;
						}
						$imported = isset( $done[ $slug ] );
						$pub      = $imported ? count( self::ids( $slug, 'mh_question', 'publish' ) ) : 0;
						$all      = $imported ? count( self::ids( $slug ) ) : 0;
						?>
						<tr>
							<td><b><?php echo esc_html( $p['title'] ); ?></b><?php echo ! empty( $p['date'] ) ? '<br><span class="description">পরীক্ষা: ' . esc_html( $p['date'] ) . '</span>' : ''; ?></td>
							<td><?php echo esc_html( mhp_num( $p['count'] ) ); ?><?php echo $p['flagged'] ? '<br><span class="description">যাচাই দরকার ' . esc_html( mhp_num( $p['flagged'] ) ) . '</span>' : ''; ?><?php echo $p['skipped'] ? '<br><span class="description">ছবিনির্ভর/বাদ ' . esc_html( mhp_num( $p['skipped'] ) ) . '</span>' : ''; ?></td>
							<td><?php echo esc_html( implode( ', ', (array) $p['tracks'] ) ); ?></td>
							<td><?php echo $imported ? ( $pub ? '' : '<b style="color:#b32d2e">খসড়া, সাইটে নেই</b><br>' ) . esc_html( sprintf( '%s / %s প্রকাশিত', mhp_num( $pub ), mhp_num( $all ) ) ) : '—'; ?></td>
							<td>
								<a class="button" target="_blank" href="<?php echo esc_url( admin_url( 'admin.php?page=mhp-bank&sheet=' . $slug ) ); ?>">রিভিউ শিট</a>
								<?php
								if ( ! $imported ) {
									$form( 'import', $slug, 'ইমপোর্ট', true );
								} else {
									echo '<a class="button" href="' . esc_url( admin_url( 'edit.php?post_type=mh_question&mhp_pack=' . $slug ) ) . '">প্রশ্নগুলো</a> ';
									if ( current_user_can( 'publish_mhp_items' ) && $pub < $all ) {
										$form( 'publish', $slug, 'প্রকাশ করুন', true, 'রিভিউ শেষ হয়েছে? যাচাই-চিহ্ন ছাড়া সব প্রশ্ন ও টেস্ট প্রকাশ হবে।' );
									}
								}
								?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Printable review sheet from the pack file (works before import too).
	 *
	 * @param string $slug Pack.
	 * @param array  $p    Meta.
	 */
	private static function sheet( $slug, $p ) {
		$letters = array( 'ক', 'খ', 'গ', 'ঘ' );
		?>
		<style>
			.mhp-sheet{max-width:900px;background:#fff;padding:24px;font-size:15px;line-height:1.6}
			.mhp-sheet ol{padding-left:28px}.mhp-sheet li{margin:0 0 14px;break-inside:avoid}
			.mhp-sheet .o{display:grid;grid-template-columns:1fr 1fr;gap:2px 16px}
			.mhp-sheet .ok{font-weight:700;text-decoration:underline}
			.mhp-sheet .x{color:#555;font-size:14px}.mhp-sheet .f{color:#b32d2e;font-weight:600}
			.mhp-sheet .box{display:inline-block;border:1px solid #999;width:14px;height:14px;vertical-align:middle;margin-right:6px}
			@media print{#adminmenumain,#wpadminbar,#wpfooter,.noprint,.notice{display:none!important}#wpcontent{margin:0!important;padding:0!important}.mhp-sheet{padding:0}}
		</style>
		<div class="wrap mhp-sheet">
			<p class="noprint"><a href="<?php echo esc_url( admin_url( 'admin.php?page=mhp-bank' ) ); ?>">← প্রশ্নব্যাংক</a> · <button class="button" onclick="window.print()">প্রিন্ট / PDF</button></p>
			<h1 style="font-size:22px"><?php echo esc_html( $p['title'] ); ?> — রিভিউ শিট</h1>
			<p class="x">উৎস: <?php echo esc_html( $p['source'] ); ?> · সঠিক উত্তর মোটা ও নিচে দাগ দেওয়া · ভুল পেলে বাক্সে টিক দিয়ে পাশে লিখুন।</p>
			<ol>
			<?php
			foreach ( self::rows( $slug ) as $r ) :
				list( $subj, $text, $opts, $ans, $explain, $quiz, $flag ) = $r;
				$num = isset( $r[7] ) ? (int) $r[7] : 0;
				?>
				<li<?php echo $num ? ' value="' . (int) $num . '"' : ''; ?>>
					<span class="box"></span><?php echo esc_html( $text ); ?>
					<?php echo 'ok' !== $flag ? ' <span class="f">[' . esc_html( 'skip' === $flag ? 'বাদ: ইমপোর্ট হবে না' : self::flag_label( $flag ) ) . ']</span>' : ''; ?>
					<div class="o">
						<?php foreach ( array_values( $opts ) as $i => $o ) : ?>
							<span<?php echo $i === (int) $ans ? ' class="ok"' : ''; ?>><?php echo esc_html( $letters[ $i ] . ') ' . $o ); ?></span>
						<?php endforeach; ?>
					</div>
					<div class="x"><?php echo esc_html( $explain ); ?></div>
				</li>
			<?php endforeach; ?>
			</ol>
		</div>
		<?php
	}

	/**
	 * Form handler.
	 */
	public static function handle() {
		if ( ! mhp_can_manage() ) {
			wp_die( esc_html( 'অনুমতি নেই।' ), 403 );
		}
		check_admin_referer( 'mhp_bank' );
		$op    = isset( $_REQUEST['op'] ) ? sanitize_key( wp_unslash( $_REQUEST['op'] ) ) : '';
		$slug  = isset( $_POST['pack'] ) ? sanitize_key( wp_unslash( $_POST['pack'] ) ) : '';
		$packs = self::packs();
		$msg   = '';
		if ( 'import' === $op && isset( $packs[ $slug ] ) ) {
			$r   = self::import( $slug );
			$msg = sprintf( '%s: নতুন %s প্রশ্ন, আগের প্রশ্নের সাথে যুক্ত %s, বাদ %s, টেস্ট %s (সব খসড়া)।', $packs[ $slug ]['title'], mhp_num( $r['added'] ), mhp_num( $r['linked'] ), mhp_num( $r['skipped'] ), mhp_num( $r['tests'] ) );
		} elseif ( 'import_all' === $op ) {
			// A pack or two per request keeps each request well inside shared-hosting time limits.
			$done  = (array) get_option( 'mhp_bank_done', array() );
			$start = time();
			$n     = 0;
			foreach ( array_keys( $packs ) as $s ) {
				if ( isset( $done[ $s ] ) ) {
					continue;
				}
				self::import( $s );
				++$n;
				if ( time() - $start > 20 ) {
					break;
				}
			}
			$left = count( array_diff( array_keys( $packs ), array_keys( (array) get_option( 'mhp_bank_done', array() ) ) ) );
			if ( $left && $n ) {
				$next = add_query_arg(
					array(
						'action'   => 'mhp_bank',
						'op'       => 'import_all',
						'_wpnonce' => wp_create_nonce( 'mhp_bank' ),
					),
					admin_url( 'admin-post.php' )
				);
				wp_safe_redirect( $next );
				exit;
			}
			$msg = 'সব সেট খসড়া হিসেবে ইমপোর্ট হয়েছে।';
		} elseif ( 'publish_all' === $op && current_user_can( 'publish_mhp_items' ) ) {
			// Pack by pack, a few per request (a 200-question paper takes a while on shared hosting).
			$done  = (array) get_option( 'mhp_bank_done', array() );
			$start = time();
			$prev  = 'POST' === $_SERVER['REQUEST_METHOD'] ? false : get_transient( 'mhp_bank_pub_' . get_current_user_id() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$tot   = ( is_array( $prev ) ? $prev : array() ) + array(
				'published' => 0,
				'held'      => 0,
				'tests'     => 0,
			);
			$left  = false;
			foreach ( array_keys( $packs ) as $s ) {
				if ( ! isset( $done[ $s ] ) || ( ! self::ids( $s, 'mh_test', 'draft' ) && ! self::unreviewed_drafts( $s ) ) ) {
					continue;
				}
				if ( time() - $start > 20 ) {
					$left = true;
					break;
				}
				$r = self::publish( $s );
				foreach ( $r as $k => $v ) {
					$tot[ $k ] += $v;
				}
			}
			if ( $left ) {
				set_transient( 'mhp_bank_pub_' . get_current_user_id(), $tot, 600 );
				wp_safe_redirect(
					add_query_arg(
						array(
							'action'   => 'mhp_bank',
							'op'       => 'publish_all',
							'_wpnonce' => wp_create_nonce( 'mhp_bank' ),
						),
						admin_url( 'admin-post.php' )
					)
				);
				exit;
			}
			delete_transient( 'mhp_bank_pub_' . get_current_user_id() );
			$msg = sprintf( 'সব সেট প্রকাশ হয়েছে: %s প্রশ্ন ও %s টেস্ট; যাচাই-চিহ্নের জন্য খসড়ায় রইল %s।', mhp_num( $tot['published'] ), mhp_num( $tot['tests'] ), mhp_num( $tot['held'] ) );
		} elseif ( 'publish' === $op && isset( $packs[ $slug ] ) && current_user_can( 'publish_mhp_items' ) ) {
			$r   = self::publish( $slug );
			$msg = sprintf( '%s: প্রকাশ %s প্রশ্ন ও %s টেস্ট; যাচাই-চিহ্নের জন্য খসড়ায় রইল %s।', $packs[ $slug ]['title'], mhp_num( $r['published'] ), mhp_num( $r['tests'] ), mhp_num( $r['held'] ) );
		}
		if ( $msg ) {
			set_transient( 'mhp_bank_' . get_current_user_id(), $msg, 300 );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=mhp-bank' ) );
		exit;
	}
}
