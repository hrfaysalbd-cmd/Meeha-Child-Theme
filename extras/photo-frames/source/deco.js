// Renders the frames.html designs with deco=1 (no text labels) for the theme's দিবস / জন্মদিন / শোক presets, 4 sizes each.
const { chromium } = require('/opt/node-tools/node_modules/playwright');
const path = require('path');
const SRC = '/home/claude/Meeha-Child-Theme/extras/photo-frames/source/frames.html', OUT = '/home/claude/Meeha-Child-Theme/theme/meeha/assets/frames/png/';
const ids = ['shok-kalo-border','jonmodin-confetti','jonmodin-sonali','bijoy-dibos','swadhinota-dibos','ekushe-february','eid-mubarak'];
const sizes = [[1080,1080],[1080,1350],[1200,630],[1080,1920]];
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' }); const p = await b.newPage();
  for (const id of ids) for (const [w,h] of sizes) {
    await p.setViewportSize({ width: w, height: h });
    await p.goto('file://' + SRC + `?id=${id}&w=${w}&h=${h}&deco=1`);
    await p.waitForSelector('body[data-ready="1"]'); await p.waitForTimeout(150);
    await p.locator('#stage').screenshot({ path: `${OUT}${id}-${w}x${h}.png`, omitBackground: true });
  }
  await b.close();
})();
