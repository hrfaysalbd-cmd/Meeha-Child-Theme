<?php
/**
 * Metrics module (owner C): view beacon + hourly table, shares, reactions, engagement table,
 * most-read / trending / popular lists (WP-Cron every 10 min → transients) and the legacy AJAX wrappers.
 *
 * Public API (CONTRACT §5):
 *   meeha_get_most_read( $window, $count, $args = [] ) · meeha_get_trending( $count ) ·
 *   meeha_get_popular( $count, $days = 30 ) · meeha_get_views( $post_id )
 * REST (no nonce, same-origin + bot filter + per-IP rate limits):
 *   POST meeha/v1/view {id} · POST meeha/v1/share {id,network} · POST meeha/v1/react {id,type,prev?}
 *   GET  meeha/v1/most-read?window=today|7d|30d&count=5[&cat=ID]  (public, cacheable)
 * JS: assets/js/metrics.js → window.MeehaMetrics.share(id, net) / .react(id, type, prev).
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/record.php';
require_once __DIR__ . '/lists.php';
require_once __DIR__ . '/cron.php';
require_once __DIR__ . '/rest.php';
require_once __DIR__ . '/assets.php';
