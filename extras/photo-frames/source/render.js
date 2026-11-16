const { chromium } = require('/opt/node-tools/node_modules/playwright');
const path = require('path'), fs = require('fs');
const ids = ['shok-kalo-border','shok-kona-fita','jonmodin-confetti','jonmodin-sonali','bijoy-dibos','swadhinota-dibos','ekushe-february','pohela-boishakh','eid-mubarak','sharodiya-shubhechha','news-nil-bracket','news-breaking','news-exclusive','news-sorboshesh','news-meeha-special'];
const sizes = [[1080,1080],[1080,1350]];
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const p = await b.newPage();
  fs.mkdirSync('out/png', { recursive: true }); fs.mkdirSync('out/preview', { recursive: true });
  for (const id of ids) for (const [w,h] of sizes) for (const pv of [0,1]) {
    await p.setViewportSize({ width: w, height: h });
    await p.goto('file://' + path.resolve('frames.html') + `?id=${id}&w=${w}&h=${h}` + (pv ? '&preview=1' : ''));
    await p.waitForSelector('body[data-ready="1"]');
    await p.waitForTimeout(150);
    const out = pv ? `out/preview/${id}-${w}x${h}.jpg` : `out/png/${id}-${w}x${h}.png`;
    await p.locator('#stage').screenshot({ path: out, omitBackground: !pv, ...(pv ? { type: 'jpeg', quality: 80 } : {}) });
  }
  await b.close();
})();
