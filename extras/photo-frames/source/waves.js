// Draws the wave-footer news frames (theme presets dheu, dheubreaking): three green wave layers at the bottom,
// a thin red stripe, optional red ব্রেকিং নিউজ band. Sizes: square, portrait, link (left photo half only).
const { chromium } = require('/opt/node-tools/node_modules/playwright');
const F = 'file:///home/claude/Meeha-Child-Theme/theme/meeha/assets/fonts/';
const OUT = process.argv[2] || 'out/';
const SIZES = [[1080, 1080], [1080, 1350], [1200, 630]];
const G = ['#6F8A63', '#4A6650', '#2B4636'], RED = '#D62828';
function wave(x0, x1, y, amp, phase, fill, bottom) { // smooth 2-hump wave from x0 to x1 around y
  const w = x1 - x0, k = w / 4; let d = `M${x0} ${y + amp * Math.sin(phase)}`;
  for (let i = 0; i < 4; i++) { const xa = x0 + k * i, xb = xa + k;
    const ya = y + amp * Math.sin(phase + i * Math.PI / 2), yb = y + amp * Math.sin(phase + (i + 1) * Math.PI / 2);
    d += ` C${xa + k / 2} ${ya} ${xb - k / 2} ${yb} ${xb} ${yb}`; }
  return `<path d="${d} L${x1} ${bottom} L${x0} ${bottom} Z" fill="${fill}"/>`; }
function frame(W, H, breaking) {
  const land = W === 1200, s = Math.min(W, H) / 1080, x1 = land ? 656 : W, sr = land ? s : 1;
  const off = land ? [165, 120, 78] : [410, 335, 262], amp = (land ? 18 : 30) * sr;
  let o = `<defs><linearGradient id="sh" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#000" stop-opacity="0"/><stop offset="1" stop-color="#000" stop-opacity=".18"/></linearGradient></defs>`;
  [0, 1, 2].forEach(i => { o += wave(0, x1, H - off[i] * (land ? 1 : 1), amp, [0.6, 2.4, 4.1][i], G[i], H); });
  o += `<rect x="0" y="${H - off[2] * .55}" width="${x1}" height="${off[2] * .55}" fill="url(#sh)"/>`;
  o += `<rect x="0" y="${H - 12 * s}" width="${W}" height="${12 * s}" fill="${RED}"/>`;
  if (breaking) { const bh = 54 * Math.max(s, .75), bw = land ? x1 : W;
    o += `<rect x="0" y="0" width="${bw}" height="${bh}" fill="${RED}"/><circle cx="${30 * s + 8}" cy="${bh / 2}" r="${9 * Math.max(s, .75)}" fill="#fff"/><circle cx="${30 * s + 8}" cy="${bh / 2}" r="${15 * Math.max(s, .75)}" fill="none" stroke="#fff" stroke-opacity=".5" stroke-width="3"/>
      <text x="${58 * s + 14}" y="${bh / 2 + 2}" font-family="MS" font-weight="600" font-size="${30 * Math.max(s, .75)}" fill="#fff" dominant-baseline="middle">ব্রেকিং নিউজ</text>`; }
  return o; }
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' }); const pg = await b.newPage();
  for (const [name, br] of [['news-dheu-sobuj', false], ['news-dheu-breaking', true]]) for (const [W, H] of SIZES) {
    await pg.setViewportSize({ width: W, height: H });
    await pg.setContent(`<style>@font-face{font-family:MS;font-weight:600;src:url(${F}meeha-sans-bn-600.woff2)}html,body{margin:0;background:transparent}</style><svg xmlns="http://www.w3.org/2000/svg" width="${W}" height="${H}">${frame(W, H, br)}</svg>`);
    await pg.evaluate(() => document.fonts.ready); await pg.waitForTimeout(80);
    await pg.screenshot({ path: `${OUT}${name}-${W}x${H}.png`, omitBackground: true });
  }
  await b.close(); })();
