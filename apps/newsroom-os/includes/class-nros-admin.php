<?php
/**
 * NROS_Admin — plain wp-admin screens (normal cookie-auth + nonces, NOT the
 * JWT API) for staff to review correspondent applications, process withdraw
 * requests, and add manual ledger (honorarium/commission) entries, without
 * needing the correspondent subdomain app themselves.
 *
 * @package NewsroomOS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NROS_Admin {

	/**
	 * Registers admin hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_actions' ) );
		add_action( 'admin_notices', array( __CLASS__, 'setup_notice' ) );
	}

	/**
	 * Warns in wp-admin if the required secrets haven't been defined in
	 * wp-config.php yet — the plugin falls back to derived defaults, but
	 * those are weaker and should be replaced before going live.
	 */
	public static function setup_notice() {
		if ( defined( 'NROS_JWT_SECRET' ) && defined( 'NROS_ENC_KEY' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p><strong>Newsroom OS:</strong> '
			. 'wp-config.php-এ <code>NROS_JWT_SECRET</code> ও <code>NROS_ENC_KEY</code> কনস্ট্যান্ট সংজ্ঞায়িত করা হয়নি — '
			. 'এখন পর্যন্ত একটি ফলব্যাক কী ব্যবহার হচ্ছে, যা প্রোডাকশনে যথেষ্ট সুরক্ষিত নয়। '
			. 'লাইভে যাওয়ার আগে দুটি দীর্ঘ, আলাদা র‍্যান্ডম স্ট্রিং যুক্ত করুন।</p></div>';
	}

	/**
	 * Adds the plugin's admin-menu entries, nested under the theme's own
	 * "Meeha হোমপেজ সেটিংস" menu when it exists, else as a standalone
	 * top-level menu.
	 */
	public static function menu() {
		$parent = 'meeha-homepage-settings';
		$has_parent = false;

		global $submenu;
		if ( isset( $GLOBALS['admin_page_hooks'][ $parent ] ) ) {
			$has_parent = true;
		}

		if ( $has_parent ) {
			add_submenu_page( $parent, 'সংবাদদাতা আবেদন (নতুন)', 'সংবাদদাতা আবেদন (নতুন)', 'edit_others_posts', 'nros-applications', array( __CLASS__, 'render_applications' ) );
			add_submenu_page( $parent, 'উইথড্র রিকোয়েস্ট', 'উইথড্র রিকোয়েস্ট', 'edit_others_posts', 'nros-withdrawals', array( __CLASS__, 'render_withdrawals' ) );
			add_submenu_page( $parent, 'লেজার এন্ট্রি (সম্মানী/কমিশন)', 'লেজার এন্ট্রি (সম্মানী/কমিশন)', 'edit_others_posts', 'nros-ledger', array( __CLASS__, 'render_ledger' ) );
		} else {
			add_menu_page( 'Newsroom OS', 'Newsroom OS', 'edit_others_posts', 'nros-applications', array( __CLASS__, 'render_applications' ), 'dashicons-id-alt' );
			add_submenu_page( 'nros-applications', 'সংবাদদাতা আবেদন (নতুন)', 'সংবাদদাতা আবেদন (নতুন)', 'edit_others_posts', 'nros-applications', array( __CLASS__, 'render_applications' ) );
			add_submenu_page( 'nros-applications', 'উইথড্র রিকোয়েস্ট', 'উইথড্র রিকোয়েস্ট', 'edit_others_posts', 'nros-withdrawals', array( __CLASS__, 'render_withdrawals' ) );
			add_submenu_page( 'nros-applications', 'লেজার এন্ট্রি (সম্মানী/কমিশন)', 'লেজার এন্ট্রি (সম্মানী/কমিশন)', 'edit_others_posts', 'nros-ledger', array( __CLASS__, 'render_ledger' ) );
		}
	}

	/**
	 * Handles the admin-side form POSTs for all three screens. Each action
	 * is nonce-protected and re-uses the REST classes' own methods where
	 * possible (by constructing a minimal fake WP_REST_Request) so the
	 * business logic lives in one place, not duplicated between the API
	 * and the wp-admin UI.
	 */
	public static function handle_actions() {
		if ( empty( $_POST['nros_action'] ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_others_posts' ) ) {
			return;
		}

		check_admin_referer( 'nros_admin_action', 'nros_nonce' );

		$action = sanitize_key( wp_unslash( $_POST['nros_action'] ) );

		if ( 'approve_application' === $action ) {
			$request = new WP_REST_Request( 'POST' );
			$request->set_param( 'id', (int) $_POST['application_id'] );
			NROS_Applications::approve( $request );
			wp_safe_redirect( add_query_arg( 'nros_done', 'approved', wp_get_referer() ) );
			exit;
		}

		if ( 'reject_application' === $action ) {
			$request = new WP_REST_Request( 'POST' );
			$request->set_param( 'id', (int) $_POST['application_id'] );
			$request->set_param( 'reason', sanitize_textarea_field( wp_unslash( $_POST['reason'] ?? '' ) ) );
			NROS_Applications::reject( $request );
			wp_safe_redirect( add_query_arg( 'nros_done', 'rejected', wp_get_referer() ) );
			exit;
		}

		if ( 'mark_paid' === $action ) {
			global $wpdb;
			$wpdb->update(
				$wpdb->prefix . 'nros_withdraw_requests',
				array(
					'status'     => 'paid',
					'admin_note' => sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) ),
					'updated_at' => current_time( 'mysql' ),
				),
				array(
					'id'     => (int) $_POST['request_id'],
					'status' => 'pending',
				)
			);
			wp_safe_redirect( add_query_arg( 'nros_done', 'paid', wp_get_referer() ) );
			exit;
		}

		if ( 'add_ledger' === $action ) {
			global $wpdb;
			$user_id = (int) $_POST['user_id'];
			$type    = sanitize_key( wp_unslash( $_POST['type'] ?? '' ) );
			$amount  = (float) ( $_POST['amount'] ?? 0 );
			$note    = sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) );

			if ( get_user_by( 'id', $user_id ) && in_array( $type, array( 'honorarium', 'commission', 'bonus', 'adjustment' ), true ) && 0 !== $amount ) {
				$wpdb->insert(
					$wpdb->prefix . 'nros_ledger',
					array(
						'user_id'    => $user_id,
						'type'       => $type,
						'amount'     => $amount,
						'status'     => 'approved',
						'note'       => $note,
						'created_by' => get_current_user_id(),
						'created_at' => current_time( 'mysql' ),
					)
				);
			}
			wp_safe_redirect( add_query_arg( 'nros_done', 'ledger-added', wp_get_referer() ) );
			exit;
		}
	}

	/**
	 * Renders the "সংবাদদাতা আবেদন (নতুন)" screen — a plain table of
	 * pending KYC applications with approve/reject forms.
	 */
	public static function render_applications() {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}nros_applications ORDER BY created_at DESC LIMIT 100", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		?>
		<div class="wrap">
			<h1>সংবাদদাতা আবেদন</h1>
			<?php if ( ! empty( $_GET['nros_done'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>সম্পন্ন হয়েছে।</p></div>
			<?php endif; ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th>নাম</th><th>ইমেইল</th><th>ফোন</th><th>এলাকা</th><th>অবস্থা</th><th>ফাইল</th><th>কর্ম</th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row['full_name'] ); ?></td>
						<td><?php echo esc_html( $row['email'] ); ?></td>
						<td><?php echo esc_html( $row['phone'] ); ?></td>
						<td><?php echo esc_html( trim( $row['division'] . ' / ' . $row['district'] . ' / ' . $row['upazila'], ' /' ) ); ?></td>
						<td><?php echo esc_html( $row['status'] ); ?></td>
						<td>
							<?php foreach ( array( 'cv', 'nid', 'certificate' ) as $field ) : ?>
								<a href="<?php echo esc_url( rest_url( NROS_API_NS . '/applications/' . $row['id'] . '/files/' . $field ) ); ?>" target="_blank"><?php echo esc_html( strtoupper( $field ) ); ?></a>
							<?php endforeach; ?>
						</td>
						<td>
							<?php if ( 'pending' === $row['status'] ) : ?>
								<form method="post" style="display:inline">
									<?php wp_nonce_field( 'nros_admin_action', 'nros_nonce' ); ?>
									<input type="hidden" name="nros_action" value="approve_application" />
									<input type="hidden" name="application_id" value="<?php echo esc_attr( $row['id'] ); ?>" />
									<button type="submit" class="button button-primary">অনুমোদন</button>
								</form>
								<form method="post" style="display:inline">
									<?php wp_nonce_field( 'nros_admin_action', 'nros_nonce' ); ?>
									<input type="hidden" name="nros_action" value="reject_application" />
									<input type="hidden" name="application_id" value="<?php echo esc_attr( $row['id'] ); ?>" />
									<input type="text" name="reason" placeholder="কারণ (ঐচ্ছিক)" />
									<button type="submit" class="button">প্রত্যাখ্যান</button>
								</form>
							<?php else : ?>
								&mdash;
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Renders the "উইথড্র রিকোয়েস্ট" screen.
	 */
	public static function render_withdrawals() {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT w.*, u.display_name, u.user_email FROM {$wpdb->prefix}nros_withdraw_requests w " // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			. "INNER JOIN {$wpdb->users} u ON u.ID = w.user_id ORDER BY w.created_at DESC LIMIT 200",
			ARRAY_A
		);
		?>
		<div class="wrap">
			<h1>উইথড্র রিকোয়েস্ট</h1>
			<?php if ( ! empty( $_GET['nros_done'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>সম্পন্ন হয়েছে।</p></div>
			<?php endif; ?>
			<table class="widefat striped">
				<thead>
					<tr><th>সংবাদদাতা</th><th>পরিমাণ</th><th>পদ্ধতি</th><th>অবস্থা</th><th>তারিখ</th><th>কর্ম</th></tr>
				</thead>
				<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row['display_name'] ); ?> (<?php echo esc_html( $row['user_email'] ); ?>)</td>
						<td><?php echo esc_html( number_format_i18n( (float) $row['amount'], 2 ) ); ?></td>
						<td><?php echo esc_html( strtoupper( $row['method'] ) ); ?></td>
						<td><?php echo esc_html( $row['status'] ); ?></td>
						<td><?php echo esc_html( $row['created_at'] ); ?></td>
						<td>
							<?php if ( 'pending' === $row['status'] ) : ?>
								<form method="post" style="display:inline">
									<?php wp_nonce_field( 'nros_admin_action', 'nros_nonce' ); ?>
									<input type="hidden" name="nros_action" value="mark_paid" />
									<input type="hidden" name="request_id" value="<?php echo esc_attr( $row['id'] ); ?>" />
									<input type="text" name="note" placeholder="নোট (ঐচ্ছিক)" />
									<button type="submit" class="button button-primary">পরিশোধিত চিহ্নিত করুন</button>
								</form>
							<?php else : ?>
								&mdash;
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Renders the "লেজার এন্ট্রি (সম্মানী/কমিশন)" screen — the manual entry
	 * form that is the interim stopgap until automatic honorarium/commission
	 * calculation is built.
	 */
	public static function render_ledger() {
		$correspondents = get_users( array( 'role' => 'correspondent', 'orderby' => 'display_name' ) );
		?>
		<div class="wrap">
			<h1>লেজার এন্ট্রি যুক্ত করুন</h1>
			<p>সম্মানী/কমিশন এখন ম্যানুয়ালি এখান থেকে যুক্ত করা হচ্ছে। (ভবিষ্যতে প্রকাশের সাথে সাথে সম্মানী ও বিজ্ঞাপন পেমেন্ট নিশ্চিত হওয়ার পর কমিশন স্বয়ংক্রিয়ভাবে যুক্ত হওয়ার পরিকল্পনা আছে।)</p>
			<?php if ( ! empty( $_GET['nros_done'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>যুক্ত হয়েছে।</p></div>
			<?php endif; ?>
			<form method="post">
				<?php wp_nonce_field( 'nros_admin_action', 'nros_nonce' ); ?>
				<input type="hidden" name="nros_action" value="add_ledger" />
				<table class="form-table">
					<tr>
						<th><label for="user_id">সংবাদদাতা</label></th>
						<td>
							<select name="user_id" id="user_id" required>
								<option value="">— নির্বাচন করুন —</option>
								<?php foreach ( $correspondents as $c ) : ?>
									<option value="<?php echo esc_attr( $c->ID ); ?>"><?php echo esc_html( $c->display_name . ' (' . $c->user_email . ')' ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="type">ধরন</label></th>
						<td>
							<select name="type" id="type" required>
								<option value="honorarium">সম্মানী (Honorarium)</option>
								<option value="commission">বিজ্ঞাপন কমিশন (Commission)</option>
								<option value="bonus">বোনাস</option>
								<option value="adjustment">সমন্বয় (Adjustment)</option>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="amount">পরিমাণ (৳)</label></th>
						<td><input type="number" step="0.01" name="amount" id="amount" required /></td>
					</tr>
					<tr>
						<th><label for="note">নোট</label></th>
						<td><textarea name="note" id="note" rows="3" class="large-text"></textarea></td>
					</tr>
				</table>
				<?php submit_button( 'যুক্ত করুন' ); ?>
			</form>
		</div>
		<?php
	}
}
