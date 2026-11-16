<?php
/**
 * প্রস্তুতি → কুইজ পুরস্কার: ranking per period, disqualify, announce winners, mark prizes as sent.
 *
 * @package meeha-prep
 */

defined( 'ABSPATH' ) || exit;

/**
 * Prize desk.
 */
class MHP_Prize_Admin {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 21 );
		add_action( 'admin_post_mhp_prize', array( __CLASS__, 'handle' ) );
	}

	/**
	 * Menu.
	 */
	public static function menu() {
		add_submenu_page( 'mhp', 'কুইজ পুরস্কার', 'কুইজ পুরস্কার', 'mhp_manage', 'mhp-prizes', array( __CLASS__, 'page' ) );
	}

	/**
	 * Page.
	 */
	public static function page() {
		if ( ! mhp_can_manage() ) {
			wp_die( esc_html( 'অনুমতি নেই।' ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only screen.
		$periods = MHP_Prizes::recent_periods( 'day' === MHP_Prizes::kind() ? 14 : 8 );
		$key     = isset( $_GET['p'] ) ? sanitize_text_field( wp_unslash( $_GET['p'] ) ) : '';
		$done    = isset( $_GET['done'] ) ? sanitize_key( wp_unslash( $_GET['done'] ) ) : '';
		$msg     = isset( $_GET['msg'] ) ? sanitize_text_field( wp_unslash( $_GET['msg'] ) ) : '';
		// phpcs:enable
		$p = $key ? MHP_Prizes::period_by_key( $key ) : null;
		if ( ! $p ) {
			$p = isset( $periods[1] ) ? $periods[1] : $periods[0]; // The last finished period.
		}
		$results = MHP_Prizes::results();
		echo '<div class="wrap mhp-wrap"><h1>কুইজ পুরস্কার</h1>';
		if ( $done ) {
			echo '<div class="notice notice-' . ( 'fail' === $done ? 'error' : 'success' ) . ' is-dismissible"><p>' . esc_html( $msg ? $msg : 'সংরক্ষিত হয়েছে।' ) . '</p></div>';
		}
		if ( ! MHP_Prizes::on() ) {
			echo '<div class="notice notice-info inline"><p>পুরস্কার এখন বন্ধ। চালু করতে: <a href="' . esc_url( admin_url( 'admin.php?page=meeha-theme&tab=prep' ) ) . '">মিহা থিম › প্রস্তুতি ও পেমেন্ট › কুইজ পুরস্কার</a>। বন্ধ থাকলেও আগের ঘোষণা দেখা ও র‍্যাঙ্কিং যাচাই করা যায়।</p></div>';
		}
		echo '<form method="get" style="margin:12px 0"><input type="hidden" name="page" value="mhp-prizes"><label>সময়কাল: <select name="p">';
		foreach ( $periods as $i => $pp ) {
			echo '<option value="' . esc_attr( $pp['key'] ) . '"' . selected( $pp['key'], $p['key'], false ) . '>' . esc_html( $pp['label'] . ( 0 === $i ? ' (চলছে)' : '' ) . ( isset( $results[ $pp['key'] ] ) ? ' ✓ ঘোষিত' : '' ) ) . '</option>';
		}
		echo '</select></label> <button class="button">দেখুন</button></form>';

		$rows   = MHP_Prizes::ranking( $p, max( 10, (int) mhp_opt( 'prize_winners', 3 ) + 7 ), true );
		$n      = max( 1, min( 20, (int) mhp_opt( 'prize_winners', 3 ) ) );
		$over   = $p['to'] < mhp_today();
		$posted = isset( $results[ $p['key'] ] ) ? $results[ $p['key'] ] : null;
		echo '<h2>' . esc_html( $p['label'] ) . '</h2>';
		echo '<p class="description">ন্যূনতম ' . esc_html( mhp_bn( MHP_Prizes::min_days( $p ) ) ) . ' দিন খেলেছেন এমন পাঠক। ক্রম: বেশি সঠিক, তারপর কম সময়, তারপর বেশি দিন, তারপর যিনি আগে শেষ করেছেন।' . ( mhp_opt( 'prize_staff_out', 1 ) ? ' স্টাফ অ্যাকাউন্ট বাদ।' : '' ) . '</p>';

		if ( $posted ) {
			echo '<h3>ঘোষিত বিজয়ী</h3><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'mhp_prize' );
			echo '<input type="hidden" name="action" value="mhp_prize"><input type="hidden" name="p" value="' . esc_attr( $p['key'] ) . '">';
			echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>#</th><th>নাম</th><th>ইমেইল / ফোন</th><th>সঠিক</th><th>সময়</th><th>দিন</th><th>পুরস্কার পাঠানো</th></tr></thead><tbody>';
			foreach ( $posted['winners'] as $w ) {
				$u = get_userdata( (int) $w['user_id'] );
				echo '<tr><td>' . esc_html( mhp_bn( $w['rank'] ) ) . '</td><td>' . esc_html( $u ? $u->display_name : $w['name'] ) . '<br><span class="description">' . esc_html( $w['district'] ) . '</span></td>';
				echo '<td>' . esc_html( $u ? $u->user_email : '' ) . '<br>' . esc_html( $u ? (string) get_user_meta( $u->ID, 'meeha_phone', true ) : '' ) . '</td>';
				echo '<td>' . esc_html( mhp_bn( $w['correct'] ) ) . '</td><td>' . esc_html( mhp_mmss( $w['seconds'] ) ) . '</td><td>' . esc_html( mhp_bn( $w['days'] ) ) . '</td>';
				echo '<td><label><input type="checkbox" name="sent[]" value="' . (int) $w['user_id'] . '"' . checked( ! empty( $w['sent'] ), true, false ) . '> পাঠানো হয়েছে</label></td></tr>';
			}
			echo '</tbody></table><p><button class="button button-primary" name="do" value="sent">সংরক্ষণ</button> ';
			echo '<button class="button button-link-delete" name="do" value="revoke" onclick="return confirm(\'ঘোষণাটি তুলে নেবেন? পাঠকদের পাতা থেকে বিজয়ী তালিকা সরে যাবে।\')">ঘোষণা তুলে নিন</button></p></form>';
		}

		echo '<h3>র‍্যাঙ্কিং</h3>';
		if ( ! $rows ) {
			echo '<p>এই সময়কালে যোগ্য কেউ নেই।</p></div>';
			return;
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'mhp_prize' );
		echo '<input type="hidden" name="action" value="mhp_prize"><input type="hidden" name="p" value="' . esc_attr( $p['key'] ) . '">';
		echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>#</th><th>নাম</th><th>ইমেইল / ফোন</th><th>সঠিক</th><th>সময়</th><th>দিন</th><th>বাদ দিন</th></tr></thead><tbody>';
		$rank = 0;
		foreach ( $rows as $r ) {
			$u = get_userdata( $r['user_id'] );
			if ( ! $r['out'] ) {
				++$rank;
			}
			$style = $r['out'] ? ' style="opacity:.55"' : ( $rank <= $n ? ' style="background:#f0f7ee"' : '' );
			echo '<tr' . $style . '><td>' . esc_html( $r['out'] ? '—' : mhp_bn( $rank ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td>' . ( $u ? '<a href="' . esc_url( get_edit_user_link( $u->ID ) ) . '">' . esc_html( $u->display_name ) . '</a>' : esc_html( $r['name'] ) ) . '<br><span class="description">' . esc_html( $r['district'] ) . ( $r['staff'] ? ' · স্টাফ' : '' ) . '</span></td>';
			echo '<td>' . esc_html( $u ? $u->user_email : '' ) . '<br>' . esc_html( $u ? (string) get_user_meta( $u->ID, 'meeha_phone', true ) : '' ) . '</td>';
			echo '<td>' . esc_html( mhp_bn( $r['correct'] ) ) . '</td><td>' . esc_html( mhp_mmss( $r['seconds'] ) ) . '</td><td>' . esc_html( mhp_bn( $r['days'] ) ) . '</td>';
			echo '<td>' . ( $r['staff'] ? '' : '<label><input type="checkbox" name="ex[]" value="' . (int) $r['user_id'] . '"' . checked( $r['out'], true, false ) . '> বাদ</label>' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p><button class="button" name="do" value="exclude">বাদের তালিকা সংরক্ষণ</button></p>';
		if ( $over && ! $posted ) {
			echo '<p><label><input type="checkbox" name="email" value="1" checked> বিজয়ীদের ইমেইলে জানান</label></p>';
			echo '<p><button class="button button-primary" name="do" value="announce">সেরা ' . esc_html( mhp_bn( $n ) ) . ' জনকে বিজয়ী ঘোষণা করুন</button> <span class="description">"বাদ" চিহ্ন দেওয়া পাঠকদের বাদ দিয়েই ঘোষণা হবে।</span></p>';
		} elseif ( ! $over ) {
			echo '<p class="description">সময়কাল শেষ হলে ঘোষণার বোতাম আসবে।</p>';
		}
		echo '</form></div>';
	}

	/**
	 * POST handler.
	 */
	public static function handle() {
		check_admin_referer( 'mhp_prize' );
		if ( ! mhp_can_manage() ) {
			wp_die( esc_html( 'অনুমতি নেই।' ), 403 );
		}
		$key = isset( $_POST['p'] ) ? sanitize_text_field( wp_unslash( $_POST['p'] ) ) : '';
		$do  = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$p   = MHP_Prizes::period_by_key( $key );
		$go  = admin_url( 'admin.php?page=mhp-prizes&p=' . rawurlencode( $key ) );
		if ( ! $p ) {
			wp_safe_redirect( add_query_arg( 'done', 'fail', $go ) );
			exit;
		}
		if ( 'exclude' === $do || 'announce' === $do ) {
			// The tick boxes and the announce button share one form: save what is ticked before announcing.
			$ex          = isset( $_POST['ex'] ) ? array_values( array_unique( array_map( 'absint', (array) $_POST['ex'] ) ) ) : array();
			$all         = get_option( 'mhp_prize_excluded', array() );
			$all         = is_array( $all ) ? $all : array();
			$all[ $key ] = array_slice( $ex, 0, 200 );
			update_option( 'mhp_prize_excluded', array_slice( $all, -60, null, true ), false );
		}
		if ( 'exclude' === $do ) {
			wp_safe_redirect( add_query_arg( 'done', 'ok', $go ) );
			exit;
		}
		if ( 'announce' === $do ) {
			$n = MHP_Prizes::announce( $key, ! empty( $_POST['email'] ) );
			if ( is_wp_error( $n ) ) {
				wp_safe_redirect( add_query_arg( array( 'done' => 'fail', 'msg' => rawurlencode( $n->get_error_message() ) ), $go ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing
				exit;
			}
			wp_safe_redirect( add_query_arg( array( 'done' => 'ok', 'msg' => rawurlencode( mhp_bn( $n ) . ' জন বিজয়ী ঘোষিত হয়েছে।' ) ), $go ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing
			exit;
		}
		$all = get_option( MHP_Prizes::OPT, array() );
		$all = is_array( $all ) ? $all : array();
		if ( 'revoke' === $do ) {
			unset( $all[ $key ] );
			update_option( MHP_Prizes::OPT, $all, false );
		} elseif ( 'sent' === $do && isset( $all[ $key ] ) ) {
			$sent = isset( $_POST['sent'] ) ? array_map( 'absint', (array) $_POST['sent'] ) : array();
			foreach ( $all[ $key ]['winners'] as $i => $w ) {
				$all[ $key ]['winners'][ $i ]['sent'] = in_array( (int) $w['user_id'], $sent, true ) ? 1 : 0;
			}
			update_option( MHP_Prizes::OPT, $all, false );
		}
		wp_safe_redirect( add_query_arg( 'done', 'ok', $go ) );
		exit;
	}
}
