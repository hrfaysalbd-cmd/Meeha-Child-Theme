// Round 24 — copies the theme's photocard renderer (cards.js), its fonts and
// its bundled frames into public/photocard/, so the publisher studio draws
// exactly what the site's own "ফটোকার্ড ডাউনলোড" draws. Run after changing
// theme/meeha/assets/js/cards.js:  npm run sync:photocard
import { cpSync, mkdirSync, rmSync, existsSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const here = dirname(fileURLToPath(import.meta.url))
const theme = join(here, '../../../../theme/meeha/assets')
const out = join(here, '../public/photocard')
if (!existsSync(join(theme, 'js/cards.js'))) {
  console.error('theme/meeha not found next to apps/ — nothing copied')
  process.exit(1)
}
rmSync(out, { recursive: true, force: true })
mkdirSync(join(out, 'assets'), { recursive: true })
cpSync(join(theme, 'js/cards.js'), join(out, 'cards.js'))
for (const dir of ['fonts', 'frames']) cpSync(join(theme, dir), join(out, 'assets', dir), { recursive: true })
console.log('photocard assets copied to public/photocard/')
