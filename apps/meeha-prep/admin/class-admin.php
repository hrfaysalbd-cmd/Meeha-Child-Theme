<?php
/**
 * Admin: the "প্রস্তুতি" menu (dashboard, orders, quiz schedule, question import, tools) and list-table columns.
 *
 * @package meeha-prep
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin.
 */
class MHP_Admin {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_mhp_seed', array( __CLASS__, 'do_seed' ) );
		add_action( 'admin_post_mhp_quiz_override', array( __CLASS__, 'do_quiz_override' ) );
		add_action( 'admin_post_mhp_import', array( 'MHP_Importer', 'handle' ) );
		add_action( 'admin_post_mhp_order_action', array( 'MHP_Orders_Table', 'handle_action' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_filter( 'parent_file', array( __CLASS__, 'parent_file' ) );
		add_filter( 'manage_mh_job_posts_columns', array( __CLASS__, 'job_cols' ) );
		add_action( 'manage_mh_job_posts_custom_column', array( __CLASS__, 'job_col' ), 10, 2 );
		add_filter( 'manage_mh_question_posts_columns', array( __CLASS__, 'q_cols' ) );
		add_action( 'manage_mh_question_posts_custom_column', array( __CLASS__, 'q_col' ), 10, 2 );
		add_filter( 'manage_mh_test_posts_columns', array( __CLASS__, 't_cols' ) );
		add_action( 'manage_mh_test_posts_custom_column', array( __CLASS__, 't_col' ), 10, 2 );
		add_filter( 'display_post_states', array( __CLASS__, 'states' ), 10, 2 );
	}

	/**
	 * Menu.
	 */
	public static function menu() {
		add_menu_page( 'প্রস্তুতি', 'প্রস্তুতি', 'edit_mhp_items', 'mhp', array( __CLASS__, 'dashboard' ), 'dashicons-welcome-learn-more', 26 );
		add_submenu_page( 'mhp', 'প্রস্তুতি — ড্যাশবোর্ড', 'ড্যাশবোর্ড', 'edit_mhp_items', 'mhp', array( __CLASS__, 'dashboard' ) );
		add_submenu_page( 'mhp', 'ট্র্যাক', 'ট্র্যাক', 'mhp_manage', 'edit-tags.php?taxonomy=mh_track&post_type=mh_test' );
		add_submenu_page( 'mhp', 'বিষয়', 'বিষয়', 'mhp_manage', 'edit-tags.php?taxonomy=mh_subject&post_type=mh_question' );
		add_submenu_page( 'mhp', 'চাকরির ধরন', 'চাকরির ধরন', 'mhp_manage', 'edit-tags.php?taxonomy=mh_job_type&post_type=mh_job' );
		add_submenu_page( 'mhp', 'অর্ডার', 'অর্ডার', 'mhp_manage_orders', 'mhp-orders', array( 'MHP_Orders_Table', 'page' ) );
		add_submenu_page( 'mhp', 'কুইজের সূচি', 'কুইজের সূচি', 'mhp_manage', 'mhp-quiz', array( __CLASS__, 'quiz_page' ) );
		add_submenu_page( 'mhp', 'প্রশ্ন ইমপোর্ট', 'প্রশ্ন ইমপোর্ট', 'mhp_manage', 'mhp-import', array( 'MHP_Importer', 'page' ) );
		add_submenu_page( 'mhp', 'সরঞ্জাম', 'সরঞ্জাম', 'manage_options', 'mhp-tools', array( __CLASS__, 'tools_page' ) );
	}

	/**
	 * Keep the menu open on taxonomy screens.
	 *
	 * @param string $file Parent.
	 * @return string
	 */
	public static function parent_file( $file ) {
		global $current_screen;
		if ( $current_screen && in_array( $current_screen->taxonomy, array( 'mh_track', 'mh_subject', 'mh_job_type' ), true ) ) {
			return 'mhp';
		}
		return $file;
	}

	/**
	 * Notices: seeding result, missing theme, payment state.
	 */
	public static function notices() {
		$r = get_transient( 'mhp_seed_notice' );
		if ( $r && current_user_can( 'manage_options' ) ) {
			delete_transient( 'mhp_seed_notice' );
			printf(
				'<div class="notice notice-success is-dismissible"><p><b>মিহা প্রস্তুতি:</b> শুরুর কনটেন্ট যোগ হয়েছে। %s</p></div>',
				esc_html( sprintf( 'ট্যাক্সোনমি %d, প্রশ্ন %d, ফ্রি টেস্ট %d, ক্যারিয়ার গাইড খসড়া %d।', $r['terms'], $r['questions'], $r['tests'], $r['guides'] ) )
			);
		}
	}

	/**
	 * Dashboard.
	 */
	public static function dashboard() {
		global $wpdb;
		$jobs    = wp_count_posts( 'mh_job' );
		$qs      = wp_count_posts( 'mh_question' );
		$tests   = wp_count_posts( 'mh_test' );
		$open    = MHP_Jobs::query( array( 'per_page' => 1 ) );
		$at      = MHP_Install::table( 'attempts' );
		$qt      = MHP_Install::table( 'quiz' );
		$ot      = MHP_Install::table( 'orders' );
		$week    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$at} WHERE status = 'submitted' AND submitted_at >= %s", mhp_gmt( time() - 7 * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB
		$quiz    = MHP_Quiz::played_count( mhp_today() );
		$users   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT user_id) FROM {$qt} WHERE quiz_date >= %s", gmdate( 'Y-m-d', strtotime( mhp_today() . ' -6 days' ) ) ) ); // phpcs:ignore WordPress.DB
		$hold    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$ot} WHERE status = 'hold'" ); // phpcs:ignore WordPress.DB
		$sales   = MHP_Orders::totals_since( mhp_gmt( time() - 30 * DAY_IN_SECONDS ) );
		$creds   = MHP_Gateway_SSLCommerz::creds();
		$set_url = admin_url( 'admin.php?page=meeha-theme&tab=prep' );
		$hub     = function_exists( 'meeha_campus_hub_id' ) ? (int) meeha_campus_hub_id() : 0;
		$quiz_n  = count( MHP_Quiz::set_for( mhp_today() ) );
		$cards   = array(
			array( 'খোলা বিজ্ঞপ্তি', mhp_num( $open['total'] ), admin_url( 'edit.php?post_type=mh_job' ) ),
			array( 'যাচাই বাকি (নিয়োগদাতার জমা)', mhp_num( $jobs->pending ), admin_url( 'edit.php?post_status=pending&post_type=mh_job' ) ),
			array( 'প্রকাশিত প্রশ্ন', mhp_num( $qs->publish ), admin_url( 'edit.php?post_type=mh_question' ) ),
			array( 'প্রকাশিত টেস্ট', mhp_num( $tests->publish ), admin_url( 'edit.php?post_type=mh_test' ) ),
			array( '৭ দিনে টেস্ট জমা', mhp_num( $week ), '' ),
			array( 'আজ কুইজ শেষ করেছেন', mhp_num( $quiz ), admin_url( 'admin.php?page=mhp-quiz' ) ),
			array( '৭ দিনে কুইজ খেলোয়াড়', mhp_num( $users ), '' ),
		);
		if ( mhp_can_shop() ) {
			$cards[] = array( '৩০ দিনে বিক্রি', mhp_tk( $sales['sum'] ) . ' (' . mhp_num( $sales['count'] ) . ')', admin_url( 'admin.php?page=mhp-orders&status=paid' ) );
			$cards[] = array( 'পর্যালোচনায় অর্ডার', mhp_num( $hold ), admin_url( 'admin.php?page=mhp-orders&status=hold' ) );
		}
		?>
		<div class="wrap mhp-admin">
			<h1>মিহা প্রস্তুতি</h1>
			<?php if ( ! function_exists( 'meeha_campus_url' ) ) : ?>
				<div class="notice notice-error"><p>"Meeha News" থিমের শিক্ষা ও ক্যাম্পাস মডিউল পাওয়া যাচ্ছে না। থিমটি সর্বশেষ সংস্করণে হালনাগাদ করুন।</p></div>
			<?php elseif ( ! $hub ) : ?>
				<div class="notice notice-warning"><p>সেকশনের পাতা এখনো ঠিক করা হয়নি। <a href="<?php echo esc_url( admin_url( 'admin.php?page=meeha-theme&tab=campus' ) ); ?>">মিহা থিম › শিক্ষা ও ক্যাম্পাস</a> থেকে পাতা বেছে নিন।</p></div>
			<?php else : ?>
				<p>সেকশন: <a href="<?php echo esc_url( mhp_url( 'home' ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( mhp_url( 'home' ) ); ?></a></p>
			<?php endif; ?>
			<div class="mhp-cards">
				<?php foreach ( $cards as $c ) : ?>
					<div class="mhp-card">
						<div class="mhp-card__n"><?php echo $c[2] ? '<a href="' . esc_url( $c[2] ) . '">' . esc_html( $c[1] ) . '</a>' : esc_html( $c[1] ); ?></div>
						<div class="mhp-card__l"><?php echo esc_html( $c[0] ); ?></div>
					</div>
				<?php endforeach; ?>
			</div>
			<h2>অবস্থা</h2>
			<table class="widefat striped" style="max-width:820px">
				<tbody>
					<tr><th>অনলাইন পেমেন্ট</th><td>
						<?php
						if ( ! mhp_opt( 'pay_on', 0 ) ) {
							echo 'বন্ধ';
						} elseif ( ! MHP_Gateway_SSLCommerz::configured() ) {
							echo '<span style="color:#b32d2e">চালু, কিন্তু স্টোর আইডি/পাসওয়ার্ড নেই</span>';
						} else {
							echo esc_html( 'চালু · ' . ( 'live' === $creds['mode'] ? 'লাইভ' : 'স্যান্ডবক্স (পরীক্ষামূলক)' ) . ' · ' . ( 'donation' === $creds['source'] ? 'ডোনেশান প্লাগিনের স্টোর' : 'নিজস্ব স্টোর' ) . ( mhp_opt( 'pay_staff_only', 1 ) ? ' · শুধু স্টাফ কিনতে পারবেন' : '' ) );
						}
						?>
						— <a href="<?php echo esc_url( $set_url ); ?>">সেটিংস</a></td></tr>
					<tr><th>আজকের কুইজ</th><td><?php echo $quiz_n ? esc_html( mhp_bn( $quiz_n ) . 'টি প্রশ্ন প্রস্তুত' ) : '<span style="color:#b32d2e">কুইজের জন্য যথেষ্ট প্রশ্ন নেই (প্রশ্নে "দৈনিক কুইজে ব্যবহারযোগ্য" চিহ্ন দিন)</span>'; ?></td></tr>
					<tr><th>নির্ধারিত কাজ (ক্রন)</th><td>
						<?php
						$next = wp_next_scheduled( 'mhp_hourly' );
						echo $next ? esc_html( 'পরের ঘণ্টাভিত্তিক কাজ: ' . wp_date( 'Y-m-d H:i', $next, mhp_tz() ) ) : '<span style="color:#b32d2e">নির্ধারিত নেই; প্লাগিন একবার নিষ্ক্রিয় করে আবার সক্রিয় করুন</span>';
						if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
							echo '<br>' . esc_html( 'DISABLE_WP_CRON চালু: হোস্টিংয়ের ক্রন থেকে wp-cron.php চালানো নিশ্চিত করুন।' );
						}
						?>
					</td></tr>
				</tbody>
			</table>
			<style>.mhp-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px;margin:16px 0;max-width:1000px}.mhp-card{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:14px}.mhp-card__n{font-size:22px;font-weight:600}.mhp-card__n a{text-decoration:none}.mhp-card__l{color:#646970;margin-top:4px}</style>
		</div>
		<?php
	}

	/**
	 * Daily quiz schedule (today + next 6 days) with per-date overrides.
	 */
	public static function quiz_page() {
		if ( ! mhp_can_manage() ) {
			wp_die( esc_html( 'অনুমতি নেই।' ) );
		}
		$over = get_option( 'mhp_quiz_override', array() );
		$over = is_array( $over ) ? $over : array();
		$sets = get_option( 'mhp_quiz_sets', array() );
		$sets = is_array( $sets ) ? $sets : array();
		?>
		<div class="wrap">
			<h1>দৈনিক কুইজের সূচি</h1>
			<p>প্রতিদিনের প্রশ্ন নিজে থেকে বাছাই হয় "দৈনিক কুইজে ব্যবহারযোগ্য" চিহ্নিত প্রশ্ন থেকে (গত ১৪ দিনের প্রশ্ন বাদ দিয়ে)। কোনো দিনের জন্য নিজে প্রশ্ন ঠিক করতে প্রশ্নের আইডি কমা দিয়ে লিখুন। দিন শুরু হওয়ার পর প্রশ্ন বদলালে যাঁরা খেলেছেন তাঁদের স্কোর এলোমেলো হতে পারে।</p>
			<table class="widefat striped" style="max-width:1000px">
				<thead><tr><th>তারিখ</th><th>প্রশ্ন</th><th>নিজে ঠিক করুন</th></tr></thead>
				<tbody>
				<?php
				for ( $i = 0; $i < 7; $i++ ) :
					$d    = gmdate( 'Y-m-d', strtotime( mhp_today() . ' +' . $i . ' days' ) );
					$ids  = ! empty( $over[ $d ] ) ? array_map( 'intval', (array) $over[ $d ] ) : ( ! empty( $sets[ $d ] ) ? array_map( 'intval', (array) $sets[ $d ] ) : MHP_Quiz::pick( $d, $sets ) );
					$mode = ! empty( $over[ $d ] ) ? 'নিজে ঠিক করা' : ( ! empty( $sets[ $d ] ) ? 'চূড়ান্ত' : 'সম্ভাব্য' );
					?>
					<tr>
						<td><b><?php echo esc_html( mhp_fdate( $d ) ); ?></b><br><span class="description"><?php echo esc_html( $mode ); ?></span></td>
						<td>
							<?php if ( $ids ) : ?>
								<ol style="margin:0 0 0 18px">
									<?php foreach ( $ids as $qid ) : ?>
										<li><a href="<?php echo esc_url( get_edit_post_link( $qid ) ); ?>"><?php echo esc_html( wp_trim_words( get_the_title( $qid ), 12 ) ); ?></a> <span class="description">#<?php echo (int) $qid; ?></span></li>
									<?php endforeach; ?>
								</ol>
							<?php else : ?>
								<span style="color:#b32d2e">যথেষ্ট প্রশ্ন নেই</span>
							<?php endif; ?>
						</td>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="mhp_quiz_override">
								<input type="hidden" name="date" value="<?php echo esc_attr( $d ); ?>">
								<?php wp_nonce_field( 'mhp_quiz_override' ); ?>
								<input type="text" name="ids" class="regular-text" value="<?php echo esc_attr( ! empty( $over[ $d ] ) ? implode( ',', array_map( 'intval', (array) $over[ $d ] ) ) : '' ); ?>" placeholder="যেমন: 120,121,135">
								<button class="button">সংরক্ষণ</button>
							</form>
						</td>
					</tr>
				<?php endfor; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Save a quiz override.
	 */
	public static function do_quiz_override() {
		if ( ! mhp_can_manage() ) {
			wp_die( esc_html( 'অনুমতি নেই।' ), 403 );
		}
		check_admin_referer( 'mhp_quiz_override' );
		$d = isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : '';
		if ( ! mhp_is_date( $d ) ) {
			wp_die( esc_html( 'তারিখ ঠিক নেই।' ) );
		}
		$raw  = isset( $_POST['ids'] ) ? sanitize_text_field( wp_unslash( $_POST['ids'] ) ) : '';
		$ids  = array();
		foreach ( preg_split( '/[\s,]+/', meeha_prep_en_digits( $raw ) ) as $x ) {
			$x = absint( $x );
			if ( $x && 'mh_question' === get_post_type( $x ) && 'publish' === get_post_status( $x ) ) {
				$ids[] = $x;
			}
		}
		$over = get_option( 'mhp_quiz_override', array() );
		$over = is_array( $over ) ? $over : array();
		if ( $ids ) {
			$over[ $d ] = array_values( array_unique( array_slice( $ids, 0, 15 ) ) );
		} else {
			unset( $over[ $d ] );
		}
		update_option( 'mhp_quiz_override', $over, false );
		wp_safe_redirect( admin_url( 'admin.php?page=mhp-quiz&updated=1' ) );
		exit;
	}

	/**
	 * Tools.
	 */
	public static function tools_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html( 'অনুমতি নেই।' ) );
		}
		?>
		<div class="wrap">
			<h1>সরঞ্জাম</h1>
			<h2>শুরুর কনটেন্ট</h2>
			<p>চাকরির ধরন, বিষয়, ছয়টি ট্র্যাক (সিলেবাসসহ), ব্যাখ্যাসহ প্রশ্ন, প্রতি ট্র্যাকে একটি ফ্রি মডেল টেস্ট এবং তিনটি ক্যারিয়ার গাইড (খসড়া হিসেবে) যোগ করে। যা আগে থেকে আছে বা মুছে ট্র্যাশে রাখা হয়েছে, তা আবার তৈরি করে না।</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="mhp_seed">
				<?php wp_nonce_field( 'mhp_seed' ); ?>
				<button class="button button-primary">যা নেই তা যোগ করুন</button>
			</form>
			<?php if ( get_option( 'mhp_seeded' ) ) : ?>
				<p class="description">শেষবার চালানো হয়েছে: <?php echo esc_html( wp_date( 'Y-m-d H:i', (int) get_option( 'mhp_seeded' ), mhp_tz() ) ); ?></p>
			<?php endif; ?>
			<h2>সংস্করণ</h2>
			<p>প্লাগিন <?php echo esc_html( MHP_VERSION ); ?> · ডেটাবেস <?php echo (int) get_option( 'mhp_db_version' ); ?> · পিএইচপি <?php echo esc_html( PHP_VERSION ); ?></p>
		</div>
		<?php
	}

	/**
	 * Run the seeder.
	 */
	public static function do_seed() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html( 'অনুমতি নেই।' ), 403 );
		}
		check_admin_referer( 'mhp_seed' );
		$r = MHP_Seeder::run();
		update_option( 'mhp_seeded', time(), false );
		set_transient( 'mhp_seed_notice', $r, 10 * MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'admin.php?page=mhp-tools' ) );
		exit;
	}

	/* ---------------- List columns ---------------- */

	/**
	 * Job columns.
	 *
	 * @param array $c Columns.
	 * @return array
	 */
	public static function job_cols( $c ) {
		$date = isset( $c['date'] ) ? $c['date'] : null;
		unset( $c['date'] );
		$c['mhp_org'] = 'প্রতিষ্ঠান';
		$c['mhp_dl']  = 'শেষ তারিখ';
		if ( $date ) {
			$c['date'] = $date;
		}
		return $c;
	}

	/**
	 * Job column.
	 *
	 * @param string $col Column.
	 * @param int    $id  Post.
	 */
	public static function job_col( $col, $id ) {
		if ( 'mhp_org' === $col ) {
			echo esc_html( (string) mhp_meta( $id, 'org', '' ) );
		} elseif ( 'mhp_dl' === $col ) {
			$d = (string) mhp_meta( $id, 'deadline', '' );
			if ( mhp_is_date( $d ) ) {
				$left = mhp_days_left( $d );
				echo esc_html( mhp_fdate( $d ) );
				echo $left < 0 ? ' <span style="color:#646970">(শেষ)</span>' : ( $left <= 3 ? ' <span style="color:#b32d2e">(' . esc_html( mhp_bn( $left ) ) . ' দিন)</span>' : '' );
			} else {
				echo '<span style="color:#b32d2e">নেই</span>';
			}
		}
	}

	/**
	 * Question columns.
	 *
	 * @param array $c Columns.
	 * @return array
	 */
	public static function q_cols( $c ) {
		$c['mhp_rate'] = 'সঠিক হার';
		$c['mhp_quiz'] = 'কুইজ';
		return $c;
	}

	/**
	 * Question column.
	 *
	 * @param string $col Column.
	 * @param int    $id  Post.
	 */
	public static function q_col( $col, $id ) {
		if ( 'mhp_rate' === $col ) {
			$n = (int) get_post_meta( $id, '_mhp_answered', true );
			$c = (int) get_post_meta( $id, '_mhp_correct', true );
			echo $n ? esc_html( mhp_bn( round( $c / $n * 100 ) ) . '% (' . mhp_num( $n ) . ')' ) : '—';
		} elseif ( 'mhp_quiz' === $col ) {
			echo get_post_meta( $id, '_mhp_quiz_ok', true ) ? '✓' : '—';
		}
	}

	/**
	 * Test columns.
	 *
	 * @param array $c Columns.
	 * @return array
	 */
	public static function t_cols( $c ) {
		$c['mhp_n']     = 'প্রশ্ন';
		$c['mhp_price'] = 'মূল্য';
		return $c;
	}

	/**
	 * Test column.
	 *
	 * @param string $col Column.
	 * @param int    $id  Post.
	 */
	public static function t_col( $col, $id ) {
		if ( 'mhp_n' === $col ) {
			echo esc_html( mhp_bn( count( MHP_Tests::question_ids( $id ) ) ) );
		} elseif ( 'mhp_price' === $col ) {
			$p = (float) mhp_meta( $id, 'price', 0 );
			echo esc_html( $p > 0 ? mhp_tk( $p ) : 'ফ্রি' );
		}
	}

	/**
	 * Mark employer submissions.
	 *
	 * @param array   $s    States.
	 * @param WP_Post $post Post.
	 * @return array
	 */
	public static function states( $s, $post ) {
		if ( 'mh_job' === $post->post_type && get_post_meta( $post->ID, '_mhp_contact_phone', true ) ) {
			$s['mhp_employer'] = 'নিয়োগদাতার জমা';
		}
		if ( 'post' === $post->post_type && get_post_meta( $post->ID, '_mhp_reader_story', true ) ) {
			$s['mhp_story'] = 'পাঠকের গল্প';
		}
		return $s;
	}
}
