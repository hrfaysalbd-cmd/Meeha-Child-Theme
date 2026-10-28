<?php
/**
 * NROS_Auth — login/refresh/logout/me for the correspondent, publisher, and
 * (future) ads frontend apps, using a short-lived JWT access token (returned
 * in the JSON body, kept in memory by the frontend) plus a rotating refresh
 * token stored in an httpOnly cookie.
 *
 * Round 9 follow-up — per-app refresh cookie: each app now gets its OWN
 * refresh-cookie NAME (nros_refresh_correspondent / nros_refresh_staff /
 * nros_refresh_ads), picked per-request from the Origin header — see
 * refresh_cookie_name() below, which mirrors the same Origin-based trust
 * NROS_Core::add_cors_headers() already uses. All of them still share the
 * same .meeha.news cookie domain and /wp-json/nros/v1/auth path, but a
 * different cookie *name* is a different browser storage slot, so the
 * three apps can never read or overwrite each other's session no matter
 * how many different accounts are used across them in the same browser.
 *
 * (An earlier version of this class used a single shared cookie name on
 * purpose, aiming for eventual SSO across subdomains. That meant the
 * browser only ever had ONE slot for it — so logging into publisher-app on
 * a phone that already had a correspondent-app session open would silently
 * overwrite the correspondent's refresh token with the staff account's,
 * which surfaced as "ডাটা লোড করতে সমস্যা হয়েছে" and, once the frontend
 * learned to detect and reject the wrong identity, as the session getting
 * dropped a few reloads later. Per-app cookie names remove the shared slot
 * entirely instead of just detecting when it's been stolen.)
 *
 * A request with no recognizable Origin (direct API testing, a non-browser
 * client) falls back to the original 'nros_refresh' cookie name, so nothing
 * that depended on that name breaks. Note this also means every session
 * that was active before this change ends once — the next refresh looks for
 * a per-app cookie name that doesn't exist yet — and the next login simply
 * issues one; this is a one-time re-login, not a bug.
 *
 * Round 9 follow-up 3 — one refresh session PER DEVICE, not per user:
 * until now the server kept exactly ONE refresh-token id per user
 * (usermeta nros_refresh_token_id) and rotated it on every refresh, with a
 * 20-second grace for the previous id. That single slot broke in two
 * ordinary, non-malicious situations:
 *   1. The same account on two devices/browsers (phone + laptop, or two
 *      browsers on one phone): each device's refresh rotated the shared
 *      slot, so the other device's token went stale and it was logged out
 *      on its next reload.
 *   2. A refresh response that never reached the browser — on mobile data,
 *      reloading the page while /auth/refresh is still in flight aborts it,
 *      but the server has already rotated. The browser keeps the old token,
 *      the next reload rotates again, and two or three quick reloads later
 *      the browser's token is neither "current" nor "previous" → logged
 *      out. This is the "auto-logout after 3-4 reloads on the phone".
 *
 * Now each login creates its own session (random `sid`, carried inside the
 * refresh JWT) stored in its own usermeta row (nros_rs_<sid>), so devices
 * can never rotate each other's tokens. Within a session, the server keeps
 * {current, prev}; presenting `current` rotates (prev := current, new
 * current), presenting `prev` does NOT rotate again — it just re-sends the
 * current token. So a browser that missed a rotation response is never more
 * than one step behind and always recovers on its next refresh, however long
 * that takes. Once the browser HAS used the new token, the old one is
 * honoured for only ROTATE_MIN_INTERVAL more seconds (requests already in
 * flight), so a copied old cookie stops working. A session rotates at most
 * once per ROTATE_MIN_INTERVAL, so a burst of simultaneous refreshes can't
 * advance it two steps, and rotation is done under a MySQL named lock so two
 * tabs refreshing in the same instant can't each rotate and leave the cookie
 * pointing at the loser's id. Logout removes only that one device's session
 * (under the same lock); a password change revokes every session of the
 * account; every refresh re-checks the app role and account suspension, the
 * same gates login() applies; a database hiccup answers 503 (the apps retry)
 * rather than 401 (the apps log out).
 * Tokens issued by the old single-slot scheme (no `sid` claim) are still
 * accepted and transparently migrated into a session — remembered, so a lost
 * migration response or a second tab resumes that same session — so this
 * change forces no re-login of its own.
 *
 * All of the above is exercised by a multi-worker test harness (real class,
 * SQLite-backed usermeta, flock-emulated GET_LOCK): two devices, lost
 * responses, 8 simultaneous tabs, per-device logout, legacy migration with a
 * lost response, stolen-old-cookie rejection, password revocation, DB error,
 * role removal.
 *
 * @package NewsroomOS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NROS_Auth {

	const ACCESS_TTL  = 900;      // 15 minutes.
	const REFRESH_TTL = 2592000;  // 30 days.

	/**
	 * Round 7→8 fix: how long (seconds) a just-rotated-out refresh-token id
	 * stays acceptable after a newer one replaces it. Without this, two
	 * refresh calls landing within the same second or two of each other —
	 * two open tabs/devices for the same correspondent, a flaky mobile
	 * connection causing the frontend to retry, etc. — would race: the
	 * first call rotates the stored id, and the second call (which read
	 * the old id a moment earlier) gets rejected as "invalid/reused" even
	 * though nothing malicious happened, logging the user out mid-session.
	 * A short grace window tolerates that race with a negligible security
	 * cost (a stolen-then-rotated-out token is only replayable for this
	 * many seconds, not indefinitely).
	 */
	const REFRESH_GRACE = 20;

	/**
	 * Round 9 follow-up 3: usermeta key prefix for per-device refresh
	 * sessions (one row per login, key = prefix + sid). REFRESH_GRACE above
	 * now only applies to migrating tokens from the old single-slot scheme.
	 */
	const SESSION_META_PREFIX = 'nros_rs_';

	/**
	 * Most concurrent sessions (devices/browsers) one account may keep;
	 * logging in on an 11th evicts the least recently used one.
	 */
	const MAX_SESSIONS = 10;

	/**
	 * A session rotates its refresh-token id at most once per this many
	 * seconds. Without it, a burst of near-simultaneous refreshes (several
	 * tabs waking up together, a reload storm on a slow connection) could
	 * rotate twice inside the burst — once by an early request, again by a
	 * request that already carried the new cookie — leaving a third,
	 * still-in-flight request holding an id that is now two generations old
	 * and rejected. Within the interval, presenting the current id simply
	 * re-sends it, so no burst can ever advance the session more than one
	 * step. (Confirmed with a multi-worker test harness; see DEPLOY-NOTES.)
	 */
	const ROTATE_MIN_INTERVAL = 60;

	/**
	 * Maps an allowed frontend Origin to its own refresh-cookie name. Each
	 * app gets a distinct cookie *name*, giving it its own browser storage
	 * slot instead of all apps sharing one "nros_refresh" cookie slot (see
	 * the class docblock for why that collided).
	 *
	 * @return array<string, string> Origin => cookie name.
	 */
	protected static function refresh_cookie_names_by_origin() {
		return array(
			'https://correspondent.meeha.news' => 'nros_refresh_correspondent',
			'https://publisher.meeha.news'     => 'nros_refresh_staff',
			'https://ads.meeha.news'           => 'nros_refresh_ads',
		);
	}

	/**
	 * Reads the calling app's Origin header off the current request — the
	 * same signal NROS_Core::add_cors_headers() already trusts, and for the
	 * same reason: it's the one thing a browser won't let the page lie
	 * about. Falls back to $_SERVER['HTTP_ORIGIN'] for the rare case a
	 * WP_REST_Request isn't on hand.
	 *
	 * @param WP_REST_Request|null $request Current request, when available.
	 * @return string Origin (e.g. "https://correspondent.meeha.news"), or ''.
	 */
	protected static function request_origin( $request = null ) {
		if ( $request && $request->get_header( 'origin' ) ) {
			return (string) $request->get_header( 'origin' );
		}

		return isset( $_SERVER['HTTP_ORIGIN'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : '';
	}

	/**
	 * Resolves which refresh-cookie name to read/write for the current
	 * request. An unrecognized or missing Origin (direct API testing, a
	 * client that sends no Origin header) falls back to the original
	 * shared cookie name so nothing that depended on it breaks.
	 *
	 * @param WP_REST_Request|null $request Current request, when available.
	 * @return string Cookie name.
	 */
	protected static function refresh_cookie_name( $request = null ) {
		$origin = self::request_origin( $request );
		$map    = self::refresh_cookie_names_by_origin();

		return isset( $map[ $origin ] ) ? $map[ $origin ] : 'nros_refresh';
	}

	/**
	 * Registers the auth routes.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );

		// A password change ends every session of that account, on every
		// device and app (round 9 follow-up 3 — per-device sessions would
		// otherwise survive a password reset).
		add_action( 'after_password_reset', array( __CLASS__, 'on_password_reset' ), 10, 1 );
		add_action( 'wp_set_password', array( __CLASS__, 'on_set_password' ), 10, 2 );
		add_action( 'profile_update', array( __CLASS__, 'on_profile_update' ), 10, 2 );
	}

	/**
	 * Registers REST routes under nros/v1.
	 */
	public static function register_routes() {
		register_rest_route(
			NROS_API_NS,
			'/auth/login',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'login' ),
				'permission_callback' => '__return_true',
			)
		);

		// Round 12 — second login step when the theme requires an OTP for this user.
		register_rest_route(
			NROS_API_NS,
			'/auth/login/otp',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'login_otp' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			NROS_API_NS,
			'/auth/login/otp/resend',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'login_otp_resend' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			NROS_API_NS,
			'/auth/refresh',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'refresh' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			NROS_API_NS,
			'/auth/logout',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'logout' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			NROS_API_NS,
			'/me',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'me' ),
				'permission_callback' => array( 'NROS_Core', 'require_app_user' ),
			)
		);
	}

	/**
	 * Derives the cookie domain (".meeha.news") from the site's home_url(),
	 * so the refresh cookie is readable from every subdomain app.
	 *
	 * @return string
	 */
	protected static function cookie_domain() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$host = preg_replace( '/^www\./', '', (string) $host );
		return '.' . $host;
	}

	/**
	 * Sets the rotating refresh-token cookie, under whichever per-app name
	 * this request resolved to (see refresh_cookie_name()). Scoped to the
	 * auth path only (not the whole API) since no other route needs to
	 * read it.
	 *
	 * @param string $refresh_jwt Signed refresh JWT.
	 * @param string $cookie_name Cookie name to set (per-app).
	 */
	protected static function set_refresh_cookie( $refresh_jwt, $cookie_name ) {
		$params = array(
			'expires'  => time() + self::REFRESH_TTL,
			'path'     => '/wp-json/' . NROS_API_NS . '/auth',
			'domain'   => self::cookie_domain(),
			'secure'   => true,
			'httponly' => true,
			'samesite' => 'None',
		);

		if ( PHP_VERSION_ID >= 70300 ) {
			setcookie( $cookie_name, $refresh_jwt, $params );
		} else {
			setcookie(
				$cookie_name,
				$refresh_jwt,
				$params['expires'],
				$params['path'] . '; SameSite=None',
				$params['domain'],
				true,
				true
			);
		}
	}

	/**
	 * Clears the refresh cookie on logout, under whichever per-app name
	 * this request resolved to.
	 *
	 * @param string $cookie_name Cookie name to clear (per-app).
	 */
	protected static function clear_refresh_cookie( $cookie_name ) {
		$params = array(
			'expires'  => time() - 3600,
			'path'     => '/wp-json/' . NROS_API_NS . '/auth',
			'domain'   => self::cookie_domain(),
			'secure'   => true,
			'httponly' => true,
			'samesite' => 'None',
		);

		if ( PHP_VERSION_ID >= 70300 ) {
			setcookie( $cookie_name, '', $params );
		} else {
			setcookie( $cookie_name, '', $params['expires'], $params['path'] . '; SameSite=None', $params['domain'], true, true );
		}
	}

	/**
	 * Signs an access token plus a refresh token bound to one session.
	 * Pure token minting — all session bookkeeping happens in the callers.
	 *
	 * @param WP_User $user User.
	 * @param string  $sid  Session id.
	 * @param string  $rid  The session's current refresh-token id.
	 * @return array {access_token, refresh_token}
	 */
	protected static function issue_tokens( $user, $sid, $rid ) {
		$access_token = NROS_JWT::issue(
			array(
				'uid' => $user->ID,
			),
			self::ACCESS_TTL
		);

		$refresh_token = NROS_JWT::issue(
			array(
				'uid' => $user->ID,
				'sid' => $sid,
				'rid' => $rid,
				'typ' => 'refresh',
			),
			self::REFRESH_TTL
		);

		return array(
			'access_token'  => $access_token,
			'refresh_token' => $refresh_token,
		);
	}

	/**
	 * @param string $sid Session id.
	 * @return string usermeta key for that session.
	 */
	protected static function session_meta_key( $sid ) {
		return self::SESSION_META_PREFIX . $sid;
	}

	/**
	 * Session ids are always our own random_id( 16 ) output (32 hex chars);
	 * anything else in a (validly signed) token is rejected before
	 * it reaches a meta key or lock name.
	 *
	 * @param mixed $sid Candidate session id.
	 * @return bool
	 */
	protected static function is_valid_sid( $sid ) {
		return is_string( $sid ) && 1 === preg_match( '/^[A-Za-z0-9]{16,64}$/', $sid );
	}

	/**
	 * MySQL named-lock name for one session (or the legacy slot) — hashed so
	 * it stays under MySQL's 64-character limit whatever the table prefix,
	 * and namespaced by database + prefix so two WordPress sites on the same
	 * MySQL server can't contend for each other's locks.
	 *
	 * @param int    $uid User id.
	 * @param string $sid Session id, or 'legacy'.
	 * @return string
	 */
	protected static function lock_name( $uid, $sid ) {
		global $wpdb;
		$db = defined( 'DB_NAME' ) ? DB_NAME : '';
		return 'nros_' . md5( $db . '|' . $wpdb->prefix . '|' . (int) $uid . '|' . $sid );
	}

	/**
	 * Takes a MySQL named lock (waits up to 5s). Best effort: if the host's
	 * MySQL refuses GET_LOCK for any reason, the caller still proceeds —
	 * exactly as safe as before this lock existed, never less.
	 *
	 * @param string $name Lock name.
	 * @return bool Whether the lock was acquired (and must be released).
	 */
	protected static function acquire_lock( $name ) {
		global $wpdb;
		$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, 5 ) );
		return '1' === (string) $got;
	}

	/**
	 * @param string $name Lock name.
	 */
	protected static function release_lock( $name ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
	}

	/**
	 * Reads one session straight from the database, bypassing WordPress's
	 * user-meta cache: called while holding the session lock, so it must
	 * see what a concurrent request just committed, not a cached copy (a
	 * persistent object cache such as LiteSpeed/Redis would otherwise make
	 * the lock pointless).
	 *
	 * @param int    $uid User id.
	 * @param string $sid Session id.
	 * @return array|null
	 */
	protected static function read_session( $uid, $sid ) {
		global $wpdb;
		$raw = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s ORDER BY umeta_id DESC LIMIT 1",
				(int) $uid,
				self::session_meta_key( $sid )
			)
		);
		if ( null === $raw ) {
			return null;
		}
		$session = maybe_unserialize( $raw );
		return is_array( $session ) ? $session : null;
	}

	/**
	 * @param int    $uid     User id.
	 * @param string $sid     Session id.
	 * @param array  $session Session data.
	 */
	protected static function write_session( $uid, $sid, array $session ) {
		update_user_meta( (int) $uid, self::session_meta_key( $sid ), $session );
	}

	/**
	 * Drops sessions unused for longer than the refresh-token lifetime, and
	 * — if the account is at MAX_SESSIONS — the least recently used ones,
	 * leaving room for one new session.
	 *
	 * @param int $uid User id.
	 */
	protected static function prune_sessions( $uid ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key LIKE %s",
				(int) $uid,
				$wpdb->esc_like( self::SESSION_META_PREFIX ) . '%'
			)
		);

		$now  = time();
		$live = array();
		foreach ( (array) $rows as $row ) {
			$session   = maybe_unserialize( $row->meta_value );
			$last_used = is_array( $session ) && isset( $session['last_used'] ) ? (int) $session['last_used'] : 0;
			if ( $now - $last_used > self::REFRESH_TTL ) {
				delete_user_meta( (int) $uid, $row->meta_key );
				continue;
			}
			$live[ $row->meta_key ] = $last_used;
		}

		if ( count( $live ) >= self::MAX_SESSIONS ) {
			asort( $live ); // Least recently used first.
			$excess = count( $live ) - self::MAX_SESSIONS + 1;
			foreach ( array_slice( array_keys( $live ), 0, $excess ) as $meta_key ) {
				delete_user_meta( (int) $uid, $meta_key );
			}
		}
	}

	/**
	 * Unpredictable id for a session or a refresh-token id. Deliberately
	 * not wp_generate_password(): that passes through the `random_password`
	 * filter, which a security plugin could alter into something that fails
	 * is_valid_sid() and silently log everyone out.
	 *
	 * @param int $bytes Random bytes (hex output is twice as long).
	 * @return string
	 */
	protected static function random_id( $bytes ) {
		return bin2hex( random_bytes( $bytes ) );
	}

	/**
	 * A transient database failure must not look like "session gone" (401
	 * → the app logs out); report it as 503 so the app keeps the session
	 * and simply retries.
	 *
	 * @return WP_Error|null
	 */
	protected static function db_unavailable_error() {
		global $wpdb;
		if ( ! empty( $wpdb->last_error ) ) {
			return new WP_Error( 'nros_auth_unavailable', 'সার্ভার এই মুহূর্তে ব্যস্ত, একটু পরে আবার চেষ্টা করুন।', array( 'status' => 503 ) );
		}
		return null;
	}

	/**
	 * Starts a brand-new refresh session for one device/browser (a login,
	 * or a migrated legacy token).
	 *
	 * @param WP_User $user        User.
	 * @param string  $cookie_name Which app's cookie this session lives in (informational).
	 * @return array {0: sid, 1: current rid}
	 */
	protected static function create_session( $user, $cookie_name ) {
		self::prune_sessions( $user->ID );

		$sid = self::random_id( 16 );
		$rid = self::random_id( 24 );
		$now = time();

		self::write_session(
			$user->ID,
			$sid,
			array(
				'rid'        => $rid,
				'prev'       => '',
				'seen'       => false, // Has the browser presented `rid` yet?
				'rotated_at' => $now,
				'created'    => $now,
				'last_used'  => $now,
				'app'        => $cookie_name,
			)
		);

		return array( $sid, $rid );
	}

	/**
	 * Validates a session-bound refresh token and advances the session.
	 *
	 * - Presenting the current id marks it `seen` and rotates (at most once
	 *   per ROTATE_MIN_INTERVAL).
	 * - Presenting the previous id re-sends the current id without rotating
	 *   again — the browser missed the last rotation's response (reload on
	 *   a slow connection), or another tab raced this one. Accepted while the
	 *   browser has not yet used the current id at all, or within
	 *   ROTATE_MIN_INTERVAL of the rotation (in-flight requests); once the
	 *   browser has demonstrably moved on, a copy of the old cookie no longer
	 *   works.
	 * - Anything else → 401.
	 *
	 * @param WP_User $user         User.
	 * @param string  $sid          Session id from the token.
	 * @param string  $incoming_rid Refresh-token id from the token.
	 * @return array|WP_Error {0: sid, 1: rid to issue}
	 */
	protected static function advance_session( $user, $sid, $incoming_rid ) {
		$invalid = new WP_Error( 'nros_invalid_refresh', 'সেশন অবৈধ, পুনরায় লগইন করুন।', array( 'status' => 401 ) );

		if ( ! self::is_valid_sid( $sid ) || '' === $incoming_rid ) {
			return $invalid;
		}

		$lock   = self::lock_name( $user->ID, $sid );
		$locked = self::acquire_lock( $lock );

		try {
			// Drop any user-meta values this request cached before taking the
			// lock, so update_user_meta() below compares against fresh data.
			wp_cache_delete( $user->ID, 'user_meta' );

			$session = self::read_session( $user->ID, $sid );
			$db_err  = self::db_unavailable_error();
			if ( $db_err ) {
				return $db_err;
			}
			if ( ! $session || empty( $session['rid'] ) ) {
				return $invalid; // Logged out, evicted, revoked, or pruned.
			}

			$now        = time();
			$rotated_at = isset( $session['rotated_at'] ) ? (int) $session['rotated_at'] : 0;

			if ( hash_equals( (string) $session['rid'], $incoming_rid ) ) {
				$session['seen'] = true;
				if ( $now - $rotated_at >= self::ROTATE_MIN_INTERVAL ) {
					$session['prev']       = (string) $session['rid'];
					$session['rid']        = self::random_id( 24 );
					$session['rotated_at'] = $now;
					$session['seen']       = false;
				}
				// Else: rotated moments ago (another tab, a quick reload) —
				// re-send the current id; see ROTATE_MIN_INTERVAL.
			} elseif ( ! empty( $session['prev'] )
				&& hash_equals( (string) $session['prev'], $incoming_rid )
				&& ( empty( $session['seen'] ) || $now - $rotated_at < self::ROTATE_MIN_INTERVAL ) ) {
				// Browser is one rotation behind — hand it the current id again.
				$session['catch_ups'] = isset( $session['catch_ups'] ) ? (int) $session['catch_ups'] + 1 : 1;
			} else {
				return $invalid;
			}

			if ( empty( $session['first_used_at'] ) ) {
				$session['first_used_at'] = $now;
			}
			$session['last_used'] = $now;
			self::write_session( $user->ID, $sid, $session );

			return array( $sid, (string) $session['rid'] );
		} finally {
			if ( $locked ) {
				self::release_lock( $lock );
			}
		}
	}

	/**
	 * Re-sends a session's current id to a browser still holding the legacy
	 * token that was migrated into it (its migration response was lost, or
	 * a second tab raced the first). Only while the browser hasn't started
	 * using the session's own tokens yet, plus ROTATE_MIN_INTERVAL for
	 * requests already in flight.
	 *
	 * @param WP_User $user User.
	 * @param string  $sid  Session the legacy token was migrated into.
	 * @return array|WP_Error {0: sid, 1: rid}
	 */
	protected static function resume_migrated_session( $user, $sid ) {
		$invalid = new WP_Error( 'nros_invalid_refresh', 'সেশন অবৈধ, পুনরায় লগইন করুন।', array( 'status' => 401 ) );

		$lock   = self::lock_name( $user->ID, $sid );
		$locked = self::acquire_lock( $lock );

		try {
			wp_cache_delete( $user->ID, 'user_meta' );
			$session = self::read_session( $user->ID, $sid );
			$db_err  = self::db_unavailable_error();
			if ( $db_err ) {
				return $db_err;
			}
			if ( ! $session || empty( $session['rid'] ) ) {
				return $invalid;
			}

			$now = time();
			if ( ! empty( $session['first_used_at'] ) && $now - (int) $session['first_used_at'] >= self::ROTATE_MIN_INTERVAL ) {
				return $invalid; // The browser has long since moved on to the session's own token.
			}

			$session['last_used'] = $now;
			self::write_session( $user->ID, $sid, $session );

			return array( $sid, (string) $session['rid'] );
		} finally {
			if ( $locked ) {
				self::release_lock( $lock );
			}
		}
	}

	/**
	 * Accepts a refresh token from the old single-slot scheme (no `sid`
	 * claim), using that scheme's own rules (current id, or previous id
	 * within REFRESH_GRACE), then retires the slot and moves the browser
	 * onto a new per-device session. The retired id(s) are remembered
	 * (usermeta nros_legacy_migrated) so that if the migration response is
	 * lost, or a second tab presents the same legacy token, they resume the
	 * SAME new session instead of being rejected or each spawning another.
	 *
	 * @param WP_User $user         User.
	 * @param string  $incoming_rid Refresh-token id from the token.
	 * @param string  $cookie_name  Cookie name for the new session.
	 * @return array|WP_Error {0: sid, 1: rid}
	 */
	protected static function migrate_legacy_token( $user, $incoming_rid, $cookie_name ) {
		$invalid = new WP_Error( 'nros_invalid_refresh', 'সেশন অবৈধ, পুনরায় লগইন করুন।', array( 'status' => 401 ) );

		if ( '' === $incoming_rid ) {
			return $invalid;
		}

		$lock       = self::lock_name( $user->ID, 'legacy' );
		$locked     = self::acquire_lock( $lock );
		$mapped_sid = null;

		try {
			wp_cache_delete( $user->ID, 'user_meta' );

			// Already migrated (lost response / racing tab)?
			$migrated = get_user_meta( $user->ID, 'nros_legacy_migrated', true );
			if ( is_array( $migrated ) && isset( $migrated['sid'], $migrated['rids'] ) && self::is_valid_sid( $migrated['sid'] ) ) {
				foreach ( (array) $migrated['rids'] as $old_rid ) {
					if ( is_string( $old_rid ) && '' !== $old_rid && hash_equals( $old_rid, $incoming_rid ) ) {
						$mapped_sid = (string) $migrated['sid'];
						break;
					}
				}
			}

			if ( null === $mapped_sid ) {
				$stored_rid = (string) get_user_meta( $user->ID, 'nros_refresh_token_id', true );
				$prev_rid   = (string) get_user_meta( $user->ID, 'nros_refresh_token_prev_id', true );
				$rotated_at = (int) get_user_meta( $user->ID, 'nros_refresh_token_rotated_at', true );
				$prev_live  = '' !== $prev_rid && ( time() - $rotated_at ) <= self::REFRESH_GRACE;

				$valid = ( '' !== $stored_rid && hash_equals( $stored_rid, $incoming_rid ) )
					|| ( $prev_live && hash_equals( $prev_rid, $incoming_rid ) );

				if ( ! $valid ) {
					return $invalid;
				}

				list( $sid, $rid ) = self::create_session( $user, $cookie_name );

				// Every legacy id still valid right now maps to this one
				// session, then the legacy slot is retired for good.
				update_user_meta(
					$user->ID,
					'nros_legacy_migrated',
					array(
						'sid'  => $sid,
						'rids' => array_values( array_filter( array( $stored_rid, $prev_live ? $prev_rid : '' ) ) ),
					)
				);
				delete_user_meta( $user->ID, 'nros_refresh_token_id' );
				delete_user_meta( $user->ID, 'nros_refresh_token_prev_id' );
				delete_user_meta( $user->ID, 'nros_refresh_token_rotated_at' );

				return array( $sid, $rid );
			}
		} finally {
			if ( $locked ) {
				self::release_lock( $lock );
			}
		}

		// Outside the legacy lock: resuming takes the session's own lock.
		return self::resume_migrated_session( $user, $mapped_sid );
	}

	/**
	 * Ends every session of an account, on every device and app — used when
	 * the password changes, so a lost phone or a copied cookie can't outlive
	 * a password reset.
	 *
	 * @param int $user_id User id.
	 */
	public static function revoke_all_sessions( $user_id ) {
		global $wpdb;
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return;
		}

		$keys = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT meta_key FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key LIKE %s",
				$user_id,
				$wpdb->esc_like( self::SESSION_META_PREFIX ) . '%'
			)
		);
		foreach ( (array) $keys as $meta_key ) {
			delete_user_meta( $user_id, $meta_key );
		}

		foreach ( array( 'nros_refresh_token_id', 'nros_refresh_token_prev_id', 'nros_refresh_token_rotated_at', 'nros_legacy_migrated' ) as $legacy_key ) {
			delete_user_meta( $user_id, $legacy_key );
		}
	}

	/**
	 * `after_password_reset` (the "forgot password" flow).
	 *
	 * @param WP_User $user User.
	 */
	public static function on_password_reset( $user ) {
		if ( $user && ! is_wp_error( $user ) ) {
			self::revoke_all_sessions( $user->ID );
		}
	}

	/**
	 * `wp_set_password` (WordPress 6.2+; never fires on older versions).
	 *
	 * @param string $password Unused.
	 * @param int    $user_id  User id.
	 */
	public static function on_set_password( $password, $user_id ) {
		self::revoke_all_sessions( $user_id );
	}

	/**
	 * `profile_update` — revokes only when the password hash actually
	 * changed (an admin or the user changing it on the profile screen).
	 *
	 * @param int     $user_id       User id.
	 * @param WP_User $old_user_data User object before the update.
	 */
	public static function on_profile_update( $user_id, $old_user_data ) {
		$new = get_userdata( $user_id );
		if ( $new && $old_user_data && isset( $old_user_data->user_pass ) && $old_user_data->user_pass !== $new->user_pass ) {
			self::revoke_all_sessions( $user_id );
		}
	}

	/**
	 * Whether a user holds any role one of the three frontend apps logs
	 * in — correspondent (correspondent app), built-in editor or the
	 * custom meeha_publisher role (the shared publisher app), or site
	 * Administrator (IT/Dev, allowed everywhere as a convenience).
	 *
	 * @param WP_User $user User.
	 * @return bool
	 */
	protected static function has_app_access( $user ) {
		$allowed = array( 'correspondent', 'editor', 'meeha_publisher', 'administrator' );
		return (bool) array_intersect( $allowed, (array) $user->roles );
	}

	/**
	 * Picks a single "primary" role string for the frontend to gate UI on,
	 * in priority order, for users who may technically hold more than one
	 * WordPress role.
	 *
	 * @param WP_User $user User.
	 * @return string
	 */
	public static function primary_role( $user ) {
		if ( ! $user || is_wp_error( $user ) ) {
			return '';
		}

		$roles = (array) $user->roles;

		foreach ( array( 'administrator', 'meeha_publisher', 'editor', 'correspondent' ) as $role ) {
			if ( in_array( $role, $roles, true ) ) {
				return $role;
			}
		}

		return $roles ? (string) $roles[0] : '';
	}

	/**
	 * POST /auth/login — accepts an email, username, or phone identifier
	 * plus password. Deliberately returns the same generic error for "no
	 * such user" and "wrong password" so login can't be used to enumerate
	 * accounts.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function login( $request ) {
		$identifier = sanitize_text_field( (string) $request->get_param( 'identifier' ) );
		if ( '' === $identifier ) {
			// Round 12 contract spells it `username`; `identifier` (the apps so far) still works.
			$identifier = sanitize_text_field( (string) $request->get_param( 'username' ) );
		}
		$password   = (string) $request->get_param( 'password' );

		if ( '' === $identifier || '' === $password ) {
			return new WP_Error( 'nros_bad_request', 'পরিচয় ও পাসওয়ার্ড দিন।', array( 'status' => 400 ) );
		}

		$user = false;

		if ( is_email( $identifier ) ) {
			$user = get_user_by( 'email', $identifier );
		} else {
			$user = get_user_by( 'login', $identifier );
		}

		if ( ! $user ) {
			// Fall back to phone-number lookup against the theme's existing meta key.
			$users = get_users(
				array(
					'meta_key'   => 'meeha_phone', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value' => $identifier, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'number'     => 1,
				)
			);
			$user = $users ? $users[0] : false;
		}

		$generic_error = new WP_Error( 'nros_invalid_credentials', 'পরিচয় অথবা পাসওয়ার্ড ভুল।', array( 'status' => 401 ) );

		// Round 12 — for an account the theme protects with an OTP, the theme's login lock
		// applies here too (refused while locked; a wrong password counts towards it).
		$otp_user = $user && self::otp_required( $user );
		if ( $otp_user ) {
			$locked = self::otp_lock_error( $user );
			if ( $locked ) {
				return $locked;
			}
		}

		if ( ! $user || ! wp_check_password( $password, $user->user_pass, $user->ID ) ) {
			if ( $otp_user ) {
				do_action( 'wp_login_failed', $user->user_login, new WP_Error( 'incorrect_password', 'ভুল পাসওয়ার্ড' ) );
			}
			return $generic_error;
		}

		if ( ! self::has_app_access( $user ) ) {
			return $generic_error;
		}

		if ( in_array( 'correspondent', (array) $user->roles, true )
			&& in_array( 'account', NROS_Core::active_suspension_types( $user->ID ), true ) ) {
			return new WP_Error( 'nros_suspended', 'আপনার একাউন্ট সাময়িকভাবে স্থগিত করা হয়েছে। বিস্তারিত জানতে সম্পাদকের সাথে যোগাযোগ করুন।', array( 'status' => 403 ) );
		}

		// Round 12 — the theme requires an OTP for this account: send it and stop here. No
		// session, no tokens, no cookie until POST /auth/login/otp succeeds.
		if ( $otp_user ) {
			return self::start_otp( $user );
		}

		return self::complete_login( $user, $request );
	}

	/**
	 * Issues the normal login response: a new device session, the access token and the
	 * per-app refresh cookie. Shared by login() and (after the code is verified) login_otp().
	 *
	 * @param WP_User         $user    User.
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	protected static function complete_login( $user, $request ) {
		// Each login is its own device session — logging in here never
		// touches any other device's (or app's) session for this account.
		$cookie_name       = self::refresh_cookie_name( $request );
		list( $sid, $rid ) = self::create_session( $user, $cookie_name );
		$tokens            = self::issue_tokens( $user, $sid, $rid );
		self::set_refresh_cookie( $tokens['refresh_token'], $cookie_name );

		return new WP_REST_Response(
			array(
				'access_token' => $tokens['access_token'],
				'expires_in'   => self::ACCESS_TTL,
				'user'         => self::public_profile( $user ),
			),
			200
		);
	}

	// -------------------------------------------------------------------------
	// Round 12 — OTP login step (theme `meeha` security module)
	// -------------------------------------------------------------------------

	/** Seconds before "resend code" is allowed again. */
	const OTP_RESEND_AFTER = 60;

	/** Wrong codes allowed per OTP token (same as the theme's login form). */
	const OTP_MAX_ATTEMPTS = 5;

	/** Resends allowed per OTP token (same as the theme's login form). */
	const OTP_MAX_RESENDS = 3;

	/**
	 * Does the theme require an OTP for this user? False whenever the theme's security module
	 * is absent or 2FA is globally disabled (MEEHA_DISABLE_2FA) — login is then unchanged.
	 *
	 * @param WP_User $user User.
	 * @return bool
	 */
	public static function otp_required( $user ) {
		if ( ! ( $user instanceof WP_User ) ) {
			return false;
		}
		foreach ( array( 'meeha_2fa_required_for_user', 'meeha_otp_start', 'meeha_otp_hash', 'meeha_get_security_settings' ) as $fn ) {
			if ( ! function_exists( $fn ) ) {
				return false;
			}
		}
		if ( function_exists( 'meeha_2fa_globally_disabled' ) && meeha_2fa_globally_disabled() ) {
			return false;
		}
		return (bool) apply_filters( 'nros_login_otp_required', (bool) meeha_2fa_required_for_user( $user ), $user );
	}

	/**
	 * The theme's login lock (failed attempts per IP+login), as a 429, or null.
	 *
	 * @param WP_User $user User.
	 * @return WP_Error|null
	 */
	protected static function otp_lock_error( $user ) {
		if ( ! function_exists( 'meeha_login_lock_remaining' ) ) {
			return null;
		}
		$remaining = (int) meeha_login_lock_remaining( $user->user_login );
		if ( $remaining <= 0 ) {
			return null;
		}
		$message = function_exists( 'meeha_lock_message' ) ? meeha_lock_message( $remaining ) : 'অনেকবার ভুল চেষ্টা হয়েছে। কিছুক্ষণ পর আবার চেষ্টা করুন।';
		return new WP_Error( 'nros_otp_locked', $message, array( 'status' => 429, 'retry_after' => $remaining, 'restart' => true ) );
	}

	/**
	 * Transient key the theme uses for an OTP token.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	protected static function otp_key( $token ) {
		return 'meeha_otp_' . $token;
	}

	/**
	 * OTP lifetime in seconds (theme setting).
	 *
	 * @return int
	 */
	protected static function otp_ttl() {
		$s = meeha_get_security_settings();
		return max( 60, (int) $s['otp_ttl_minutes'] * MINUTE_IN_SECONDS );
	}

	/**
	 * Maps a meeha_otp_start() error to an API error.
	 *
	 * @param WP_Error $err Theme error.
	 * @return WP_Error
	 */
	protected static function otp_send_error( $err ) {
		if ( 'meeha_otp_rate' === $err->get_error_code() ) {
			return new WP_Error( 'nros_otp_rate', $err->get_error_message(), array( 'status' => 429, 'restart' => true ) );
		}
		return new WP_Error( 'nros_otp_send_failed', $err->get_error_message(), array( 'status' => 503 ) );
	}

	/**
	 * Marks a theme OTP transient as an app login (so a token from the site's own login form
	 * can't finish an app login and vice versa is harmless) and records the send time and the
	 * code's fixed expiry (wrong attempts must not extend it).
	 *
	 * @param string $token Token.
	 * @return array|null Transient data.
	 */
	protected static function otp_mark( $token ) {
		$data = get_transient( self::otp_key( $token ) );
		if ( ! is_array( $data ) ) {
			return null;
		}
		$ttl                  = self::otp_ttl();
		$data['nros']         = 1;
		$data['nros_sent_at'] = time();
		$data['nros_exp']     = time() + $ttl;
		set_transient( self::otp_key( $token ), $data, $ttl );
		return $data;
	}

	/**
	 * Sends the code (theme's meeha_otp_start()) and answers the first login step.
	 *
	 * @param WP_User $user User whose password was verified.
	 * @return WP_REST_Response|WP_Error
	 */
	protected static function start_otp( $user ) {
		$started = meeha_otp_start( $user );
		if ( is_wp_error( $started ) ) {
			return self::otp_send_error( $started );
		}
		if ( ! self::otp_mark( $started['token'] ) ) {
			return new WP_Error( 'nros_otp_send_failed', 'ওটিপি শুরু করা যায়নি। একটু পরে আবার চেষ্টা করুন।', array( 'status' => 503 ) );
		}
		return new WP_REST_Response(
			array(
				'otp_required' => true,
				'otp_token'    => (string) $started['token'],
				'method'       => 'sms' === $started['method'] ? 'sms' : 'email',
				'destination'  => (string) $started['masked'],
				'resend_after' => self::OTP_RESEND_AFTER,
				'expires_in'   => self::otp_ttl(),
				'message'      => ( 'sms' === $started['method'] ? 'আপনার মোবাইলে ' : 'আপনার ইমেইলে ' ) . $started['masked'] . ' ঠিকানায় ৬ সংখ্যার ওটিপি পাঠানো হয়েছে।',
			),
			200
		);
	}

	/**
	 * Reads an app OTP token from the request and its transient.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error {0: token, 1: data, 2: WP_User}
	 */
	protected static function otp_session( $request ) {
		$expired = new WP_Error( 'nros_otp_expired', 'ওটিপির মেয়াদ শেষ হয়েছে। আবার লগইন করুন।', array( 'status' => 401, 'restart' => true ) );
		if ( ! self::otp_required_possible() ) {
			return $expired;
		}
		$token = (string) $request->get_param( 'otp_token' );
		if ( ! preg_match( '/^[A-Za-z0-9]{16,64}$/', $token ) ) {
			return $expired;
		}
		$data = get_transient( self::otp_key( $token ) );
		if ( ! is_array( $data ) || empty( $data['nros'] ) || empty( $data['user_id'] ) ) {
			return $expired;
		}
		if ( ! empty( $data['nros_exp'] ) && time() >= (int) $data['nros_exp'] ) {
			delete_transient( self::otp_key( $token ) );
			return $expired;
		}
		$user = get_userdata( (int) $data['user_id'] );
		if ( ! $user ) {
			delete_transient( self::otp_key( $token ) );
			return $expired;
		}
		return array( $token, $data, $user );
	}

	/**
	 * Whether the theme's OTP functions exist at all.
	 *
	 * @return bool
	 */
	protected static function otp_required_possible() {
		return function_exists( 'meeha_otp_start' ) && function_exists( 'meeha_otp_hash' ) && function_exists( 'meeha_get_security_settings' );
	}

	/**
	 * Re-saves OTP state without extending the code's original expiry.
	 *
	 * @param string $token Token.
	 * @param array  $data  Data.
	 */
	protected static function otp_save( $token, $data ) {
		$left = ! empty( $data['nros_exp'] ) ? (int) $data['nros_exp'] - time() : self::otp_ttl();
		if ( $left <= 0 ) {
			delete_transient( self::otp_key( $token ) );
			return;
		}
		set_transient( self::otp_key( $token ), $data, $left );
	}

	/**
	 * POST /auth/login/otp {otp_token, code} — verifies the code with the theme's rules
	 * (hash, 5 wrong attempts per token, the theme's login lock, Bengali digits accepted) and,
	 * only then, returns the normal login response (session + refresh cookie).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function login_otp( $request ) {
		$session = self::otp_session( $request );
		if ( is_wp_error( $session ) ) {
			return $session;
		}
		list( $token, $data, $user ) = $session;

		$code = preg_replace(
			'/\D/',
			'',
			strtr( (string) $request->get_param( 'code' ), array( '০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9' ) )
		);
		if ( '' === $code ) {
			return new WP_Error( 'nros_bad_request', 'ওটিপি দিন।', array( 'status' => 400 ) );
		}

		$locked = self::otp_lock_error( $user );
		if ( $locked ) {
			delete_transient( self::otp_key( $token ) );
			return $locked;
		}

		if ( ! hash_equals( (string) $data['hash'], meeha_otp_hash( $code ) ) ) {
			$data['attempts'] = (int) $data['attempts'] + 1;
			do_action( 'wp_login_failed', $user->user_login, new WP_Error( 'meeha_otp_failed', 'ওটিপি ভুল' ) );
			if ( $data['attempts'] >= self::OTP_MAX_ATTEMPTS ) {
				delete_transient( self::otp_key( $token ) );
				return new WP_Error( 'nros_otp_locked', 'অনেকবার ভুল ওটিপি দেওয়া হয়েছে। আবার লগইন করুন।', array( 'status' => 429, 'restart' => true ) );
			}
			self::otp_save( $token, $data );
			$left = self::OTP_MAX_ATTEMPTS - $data['attempts'];
			return new WP_Error(
				'nros_otp_invalid',
				'ওটিপি সঠিক নয়। আবার দিন। (আর ' . NROS_Core::bn_digits( $left ) . ' বার চেষ্টা করা যাবে)',
				array( 'status' => 401, 'attempts_left' => $left )
			);
		}

		delete_transient( self::otp_key( $token ) );

		// The account may have changed while the code was in flight — same gates as login().
		if ( ! self::has_app_access( $user ) ) {
			return new WP_Error( 'nros_invalid_credentials', 'পরিচয় অথবা পাসওয়ার্ড ভুল।', array( 'status' => 401 ) );
		}
		if ( in_array( 'correspondent', (array) $user->roles, true )
			&& in_array( 'account', NROS_Core::active_suspension_types( $user->ID ), true ) ) {
			return new WP_Error( 'nros_suspended', 'আপনার একাউন্ট সাময়িকভাবে স্থগিত করা হয়েছে। বিস্তারিত জানতে সম্পাদকের সাথে যোগাযোগ করুন।', array( 'status' => 403 ) );
		}

		// Successful login clears the theme's failed-attempt counter for this login (as its
		// own `wp_login` handler does) — without firing wp_login / setting a wp-admin cookie.
		if ( function_exists( 'meeha_on_login_success' ) ) {
			meeha_on_login_success( $user->user_login );
		}

		return self::complete_login( $user, $request );
	}

	/**
	 * POST /auth/login/otp/resend {otp_token} — a new code on the same token (theme's
	 * meeha_otp_start( $user, $token ): max 3 resends per token, 6 codes per user per 15 min),
	 * at most once per OTP_RESEND_AFTER seconds.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function login_otp_resend( $request ) {
		$session = self::otp_session( $request );
		if ( is_wp_error( $session ) ) {
			return $session;
		}
		list( $token, $data, $user ) = $session;

		$wait = self::OTP_RESEND_AFTER - ( time() - (int) ( isset( $data['nros_sent_at'] ) ? $data['nros_sent_at'] : 0 ) );
		if ( $wait > 0 ) {
			return new WP_Error(
				'nros_otp_resend_wait',
				NROS_Core::bn_digits( $wait ) . ' সেকেন্ড পর আবার ওটিপি চাইতে পারবেন।',
				array( 'status' => 429, 'resend_after' => $wait )
			);
		}
		if ( (int) ( isset( $data['resends'] ) ? $data['resends'] : 0 ) >= self::OTP_MAX_RESENDS ) {
			return new WP_Error( 'nros_otp_resend_limit', 'আর ওটিপি পাঠানো যাবে না। আবার লগইন করুন।', array( 'status' => 429, 'restart' => true ) );
		}
		$locked = self::otp_lock_error( $user );
		if ( $locked ) {
			delete_transient( self::otp_key( $token ) );
			return $locked;
		}

		$started = meeha_otp_start( $user, $token );
		if ( is_wp_error( $started ) ) {
			return self::otp_send_error( $started );
		}
		self::otp_mark( $started['token'] );

		return new WP_REST_Response(
			array(
				'ok'           => true,
				'otp_token'    => (string) $started['token'],
				'method'       => 'sms' === $started['method'] ? 'sms' : 'email',
				'destination'  => (string) $started['masked'],
				'resend_after' => self::OTP_RESEND_AFTER,
				'expires_in'   => self::otp_ttl(),
				'message'      => 'নতুন ওটিপি পাঠানো হয়েছে।',
			),
			200
		);
	}

	/**
	 * POST /auth/refresh — reads this app's refresh cookie, validates it
	 * against that device's own session (see advance_session()), and issues
	 * a new pair. Returns 401 only when the session is genuinely gone
	 * (logged out, evicted, expired, or an unknown token) — the frontends
	 * treat 401 as "log out" and any other failure as a temporary problem.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function refresh( $request ) {
		$cookie_name = self::refresh_cookie_name( $request );
		$cookie      = isset( $_COOKIE[ $cookie_name ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ $cookie_name ] ) ) : '';

		if ( '' === $cookie ) {
			return new WP_Error( 'nros_no_refresh', 'সেশন নেই, পুনরায় লগইন করুন।', array( 'status' => 401 ) );
		}

		$payload = NROS_JWT::verify( $cookie );
		if ( is_wp_error( $payload ) || empty( $payload['typ'] ) || 'refresh' !== $payload['typ'] ) {
			return new WP_Error( 'nros_invalid_refresh', 'সেশন অবৈধ, পুনরায় লগইন করুন।', array( 'status' => 401 ) );
		}

		$user = get_user_by( 'id', (int) $payload['uid'] );
		if ( ! $user ) {
			return new WP_Error( 'nros_invalid_refresh', 'সেশন অবৈধ, পুনরায় লগইন করুন।', array( 'status' => 401 ) );
		}

		// Same gates as login(): an account that lost its app role, or a
		// correspondent whose whole account was suspended, can't keep
		// renewing a session that started before the change.
		if ( ! self::has_app_access( $user ) ) {
			return new WP_Error( 'nros_invalid_refresh', 'সেশন অবৈধ, পুনরায় লগইন করুন।', array( 'status' => 401 ) );
		}
		if ( in_array( 'correspondent', (array) $user->roles, true )
			&& in_array( 'account', NROS_Core::active_suspension_types( $user->ID ), true ) ) {
			return new WP_Error( 'nros_suspended', 'আপনার একাউন্ট সাময়িকভাবে স্থগিত করা হয়েছে। বিস্তারিত জানতে সম্পাদকের সাথে যোগাযোগ করুন।', array( 'status' => 401 ) );
		}

		$incoming_rid = isset( $payload['rid'] ) ? (string) $payload['rid'] : '';

		// Session-bound token (round 9 follow-up 3+) vs. a token from the old
		// single-slot scheme, which is accepted once and migrated.
		if ( isset( $payload['sid'] ) ) {
			$result = self::advance_session( $user, (string) $payload['sid'], $incoming_rid );
		} else {
			$result = self::migrate_legacy_token( $user, $incoming_rid, $cookie_name );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		list( $sid, $rid ) = $result;
		$tokens            = self::issue_tokens( $user, $sid, $rid );
		self::set_refresh_cookie( $tokens['refresh_token'], $cookie_name );

		return new WP_REST_Response(
			array(
				'access_token' => $tokens['access_token'],
				'expires_in'   => self::ACCESS_TTL,
			),
			200
		);
	}

	/**
	 * POST /auth/logout — ends this device's session only (other devices of
	 * the same account stay logged in) and clears the cookie.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function logout( $request ) {
		$cookie_name = self::refresh_cookie_name( $request );
		$cookie      = isset( $_COOKIE[ $cookie_name ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ $cookie_name ] ) ) : '';

		if ( '' !== $cookie ) {
			$payload = NROS_JWT::verify( $cookie );
			if ( ! is_wp_error( $payload ) && ! empty( $payload['uid'] ) ) {
				$uid = (int) $payload['uid'];
				if ( isset( $payload['sid'] ) && self::is_valid_sid( $payload['sid'] ) ) {
					// Only this device's session — the account's other
					// devices stay logged in. Under the session's lock, so a
					// refresh already in flight can't write the row back.
					$lock   = self::lock_name( $uid, $payload['sid'] );
					$locked = self::acquire_lock( $lock );
					delete_user_meta( $uid, self::session_meta_key( $payload['sid'] ) );
					if ( $locked ) {
						self::release_lock( $lock );
					}
				} else {
					delete_user_meta( $uid, 'nros_refresh_token_id' );
					delete_user_meta( $uid, 'nros_refresh_token_prev_id' );
					delete_user_meta( $uid, 'nros_refresh_token_rotated_at' );
				}
			}
		}

		self::clear_refresh_cookie( $cookie_name );

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * GET /me — returns the logged-in correspondent's public profile.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function me( $request ) {
		$user = self::get_user_from_request( $request );
		return new WP_REST_Response( self::public_profile( $user ), 200 );
	}

	/**
	 * Resolves the "Authorization: Bearer <token>" header on a request into
	 * a WP_User, or a WP_Error if missing/invalid/expired. Shared by every
	 * class's permission_callback (via NROS_Core::require_*) and by any
	 * route handler that also needs the acting user.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_User|WP_Error
	 */
	public static function get_user_from_request( $request ) {
		$header = $request->get_header( 'authorization' );

		if ( ! $header || 0 !== stripos( $header, 'Bearer ' ) ) {
			return new WP_Error( 'nros_no_token', 'টোকেন নেই।', array( 'status' => 401 ) );
		}

		$token   = trim( substr( $header, 7 ) );
		$payload = NROS_JWT::verify( $token );

		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		if ( ! empty( $payload['typ'] ) ) {
			// Access tokens carry no 'typ' claim; a refresh token must never work here.
			return new WP_Error( 'nros_wrong_token_type', 'অবৈধ টোকেন।', array( 'status' => 401 ) );
		}

		$user = get_user_by( 'id', (int) $payload['uid'] );
		if ( ! $user ) {
			return new WP_Error( 'nros_user_not_found', 'ব্যবহারকারী পাওয়া যায়নি।', array( 'status' => 401 ) );
		}

		return $user;
	}

	/**
	 * Shapes a WP_User into the public profile fields the correspondent app
	 * needs, pulling area/title fields from the theme's existing user meta
	 * keys rather than inventing new ones.
	 *
	 * @param WP_User $user User.
	 * @return array
	 */
	public static function public_profile( $user ) {
		if ( ! $user || is_wp_error( $user ) ) {
			return array();
		}

		return array(
			'id'       => $user->ID,
			'name'     => $user->display_name,
			'email'    => $user->user_email,
			'phone'    => get_user_meta( $user->ID, 'meeha_phone', true ),
			'title'    => get_user_meta( $user->ID, 'meeha_author_title', true ),
			'division' => get_user_meta( $user->ID, 'meeha_division', true ),
			'district' => get_user_meta( $user->ID, 'meeha_district', true ),
			'upazila'  => get_user_meta( $user->ID, 'meeha_upazila', true ),
			'avatar'   => NROS_Media::avatar_url( $user->ID ),
			// Lets both frontend apps gate their own UI: the publisher app
			// uses 'role' to tell Editor and Publisher apart (and to hide
			// Publisher-only screens from Editor), the correspondent app
			// simply ignores these fields.
			'role'     => self::primary_role( $user ),
			'roles'    => array_values( (array) $user->roles ),
			'can'      => array(
				'publisher'          => user_can( $user, 'nros_publisher' ) || user_can( $user, 'manage_options' ),
				'manage_staff'       => user_can( $user, 'edit_others_posts' ),
				'manage_options'     => user_can( $user, 'manage_options' ),
				// Round 10 — effective post-management powers (Publisher/
				// Administrator always; Editor per the Publisher's
				// nros_editor_permissions setting). UI hints only: every
				// write re-checks NROS_Core::staff_can() server-side.
				'delete_posts'       => NROS_Core::staff_can( $user, 'delete_posts' ),
				'change_post_date'   => NROS_Core::staff_can( $user, 'change_date' ),
				'change_post_author' => NROS_Core::staff_can( $user, 'change_author' ),
			),
		);
	}
}
