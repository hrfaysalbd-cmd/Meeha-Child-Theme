<?php
/**
 * Search module (owner C): Bengali-normalised index (`_meeha_search_text` + title/summary fields), relevance
 * query on the main search only, results page helpers (type tabs + counts, GET filters, snippets with <mark>,
 * did-you-mean) and the admin/cron index backfill.
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/normalize.php';
require_once __DIR__ . '/index.php';
require_once __DIR__ . '/query.php';
require_once __DIR__ . '/render.php';
