/**
 * Shortcode substitution for the AI-prompt-generation feature (round 8).
 * The Publisher writes a template (in the publisher app's Settings) with
 * {title}/{body}/{summary}/{category} placeholders; this fills them in
 * from whatever post is currently open, client-side, right before copying
 * the result to the clipboard. Kept as a tiny shared pure function rather
 * than inline in each screen since both apps need the exact same rule.
 */
// Round 9: generalized beyond the fixed four news-type shortcodes to accept
// any key→value map, since prompts are now role-visible and come from a
// unified backend list rather than a single fixed template.
export function fillPromptTemplate(template: string, values: Record<string, string | undefined>) {
  let result = template
  for (const [key, value] of Object.entries(values)) {
    result = result.split(`{${key}}`).join(value ?? '')
  }
  return result
}
