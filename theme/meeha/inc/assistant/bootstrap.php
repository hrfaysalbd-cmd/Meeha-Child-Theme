<?php
/**
 * মিহা সহকারী (reader assistant). A chat bubble on every public page; readers ask in Bengali / English / banglish
 * and get answers built ONLY from Meeha News's own published posts.
 *
 *  Tier 1 (always on, free): keyword intents + the theme's own data (trending, latest today, districts, categories,
 *          live posts, the theme search with its Bengali normalisation).
 *  Tier 2 (optional): when the admin saves an Anthropic API key, tier 1 retrieves ≤ 8 posts and Claude writes a
 *          short Bengali answer from them with [n] citations. Any error → tier 1.
 *
 * Files: settings (tab "মিহা সহকারী"), intents (normalise, districts, categories, intent rules), answer (tier 1),
 * ai (Messages API call), log (anonymous daily counts), rest (POST meeha/v1/assistant, GET …/assistant/state),
 * render (bubble bundle + config; the panel bundle is loaded by the bubble on first open).
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

foreach ( array( 'settings', 'intents', 'kb', 'answer', 'ai', 'log', 'rest', 'render' ) as $meeha_as_file ) {
	require_once __DIR__ . '/' . $meeha_as_file . '.php';
}
unset( $meeha_as_file );
