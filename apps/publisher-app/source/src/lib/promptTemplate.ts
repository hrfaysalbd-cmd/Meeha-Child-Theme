/**
 * Shortcode substitution for the AI-prompt-generation feature (round 8).
 * The Publisher writes a template (in the publisher app's Settings) with
 * {title}/{body}/{summary}/{category} placeholders; this fills them in
 * from whatever post is currently open, client-side, right before copying
 * the result to the clipboard. Kept as a tiny shared pure function rather
 * than inline in each screen since both apps need the exact same rule.
 */
// Reduces the rich editor's HTML body down to plain text for a prompt —
// an LLM has no use for the markup, and pasting raw HTML into a prompt
// just wastes tokens and confuses it. Mirrors the correspondent app's own
// plainPreview() helper (same idea, no truncation here).
export function stripHtmlForPrompt(html: string): string {
  return html
    .replace(/<img[^>]*>/gi, ' [ছবি] ')
    .replace(/<[^>]+>/g, ' ')
    .replace(/\s+/g, ' ')
    .trim()
}

// Round 9: generalized beyond the fixed four news-type shortcodes to accept
// any key→value map, so the same substitution rule also drives a
// library-type prompt's Publisher-defined custom fields (e.g. {original_url},
// {original_title} on a rewrite prompt) — not just {title}/{body}/{summary}/
// {category}. .split().join() (not regex) avoids any need to escape
// special regex characters in either the key or the pasted value.
export function fillPromptTemplate(template: string, values: Record<string, string | undefined>) {
  let result = template
  for (const [key, value] of Object.entries(values)) {
    result = result.split(`{${key}}`).join(value ?? '')
  }
  return result
}
