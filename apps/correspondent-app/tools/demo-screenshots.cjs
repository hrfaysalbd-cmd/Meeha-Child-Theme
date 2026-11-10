// Renders the correspondent app with demo data for marketing screenshots (round 39).
// Build: VITE_API_BASE=https://api.mock/nros/v1 npx vite build --outDir <dist>
// Run:   node demo-screenshots.cjs <dist> <outDir> '[["home","/app",0],["chat","/app/chat",0]]'
const { chromium, devices } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const DIST = process.argv[2], OUT = process.argv[3];
const mime = {'.js':'text/javascript','.css':'text/css','.html':'text/html','.svg':'image/svg+xml','.png':'image/png','.json':'application/json','.webmanifest':'application/manifest+json','.woff2':'font/woff2'};
const srv = http.createServer((q, r) => { let p = decodeURIComponent(q.url.split('?')[0]); let f = path.join(DIST, p); if (!fs.existsSync(f) || fs.statSync(f).isDirectory()) f = path.join(DIST, 'index.html'); r.writeHead(200, {'content-type': mime[path.extname(f)] || 'application/octet-stream'}); fs.createReadStream(f).pipe(r); }).listen(5181);
const day = (n, h = 10) => { const d = new Date('2026-11-10T00:00:00'); d.setDate(d.getDate() - n); d.setHours(h); return d.toISOString().slice(0, 19).replace('T', ' '); };
const me = { id: 7, name: 'রাকিব হাসান', email: 'rakib@example.com', phone: '01700000000', title: 'জেলা প্রতিনিধি', division: 'রাজশাহী', district: 'বগুড়া', upazila: 'শেরপুর', avatar: 'data:image/svg+xml;utf8,'+encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 80"><defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#ef4444"/><stop offset="1" stop-color="#7f1d1d"/></linearGradient></defs><rect width="80" height="80" fill="url(#g)"/><circle cx="40" cy="31" r="14" fill="#fde2d2"/><path d="M12 80c2-18 14-27 28-27s26 9 28 27z" fill="#fde2d2"/></svg>'), role: 'correspondent', roles: ['correspondent'] };
const titles = ['বগুড়ায় আলু চাষে বাম্পার ফলন, দাম নিয়ে শঙ্কায় কৃষক','শেরপুরে মহাসড়কে বাস-ট্রাক সংঘর্ষে আহত ১২','করতোয়া নদীর ভাঙনে ঘরছাড়া ৪০ পরিবার','স্কুলের নতুন ভবন উদ্বোধন, খুশি শিক্ষার্থীরা','হাটে বেড়েছে শীতের সবজির সরবরাহ','গ্রামীণ সড়ক সংস্কারে ধীরগতি, ভোগান্তি চরমে','তরুণদের উদ্যোগে বিনামূল্যে রক্তদান কর্মসূচি','দই উৎসবে মানুষের ঢল'];
const st = ['publish','publish','pending','publish','draft','publish','publish','publish'];
const subs = titles.map((t, i) => ({ id: 100 + i, title: t, content: '<p>…</p>', excerpt: '', ai_summary: '', status: st[i], category_id: 3, topic: '', division: 'রাজশাহী', district: 'বগুড়া', upazila: 'শেরপুর', views: [4820, 2310, 0, 1290, 0, 960, 3400, 7650][i], featured_image: null, featured_image_id: null, featured_image_alt: '', featured_image_caption: '', payment: st[i] === 'publish' ? 150 : null, payment_status: st[i] === 'publish' ? 'approved' : null, rejected: false, reject_reason: null, created_at: day(i * 2 + (i > 3 ? 3 : 0)), updated_at: day(i * 2), permalink: st[i] === 'publish' ? 'https://meeha.news/x' : null }));
// more history for streaks/badges
for (let i = 0; i < 10; i++) subs.push({ ...subs[0], id: 300 + i, title: 'আজকের সংবাদ ' + (i + 1), views: 1500 + i * 90, created_at: day(i, 15), updated_at: day(i, 15) });
for (let i = 0; i < 16; i++) subs.push({ ...subs[0], id: 200 + i, title: 'পুরোনো সংবাদ ' + (i + 1), views: 500 + i * 40, created_at: day(17 + i * 3), updated_at: day(17 + i * 3) });
const trend = []; for (let i = 29; i >= 0; i--) { const b = 900 + Math.round(600 * Math.sin(i / 3) + (30 - i) * 45); trend.push({ stat_date: day(i).slice(0, 10), views: String(b), reactions: String(Math.round(b * 0.12)), shares: String(Math.round(b * 0.03)), comments: String(Math.round(b * 0.02)) }); }
const sum = k => trend.reduce((a, p) => a + Number(p[k]), 0);
const ledger = []; let lid = 1; subs.filter(s => s.status === 'publish').forEach(s => ledger.push({ id: lid++, type: 'post_payment', amount: '150', type_label: 'সংবাদ', post_id: s.id, status: 'approved', note: s.title, created_at: s.created_at }));
ledger.push({ id: lid++, type: 'view_bonus', amount: '500', type_label: 'ভিউ বোনাস', status: 'approved', note: '৫ হাজার ভিউ ছাড়াল', created_at: day(3) });
ledger.push({ id: lid++, type: 'video_payment', amount: '300', type_label: 'ভিডিও', status: 'approved', note: 'দই উৎসব ভিডিও', created_at: day(5) });
const rooms = [
 { id: 1, kind: 'family', name: 'মিহা পরিবার', about: 'সবাই এক রুমে', icon: '🏠', avatar: '', online: true, posting: 'all', archived: false, members: 128, is_admin: false, can_post: true, muted: false, muted_until: null, unread: 3, mentions: 0, urgent: 1, last: { id: 9, text: 'আজ সন্ধ্যা ৭টায় অনলাইন মিটিং', label: 'meeting', author: 'বার্তা সম্পাদক', at: day(0, 9) }, last_at: day(0, 9) },
 { id: 2, kind: 'desk', name: 'নিউজ ডেস্ক', about: '', icon: '📰', avatar: '', online: true, posting: 'all', archived: false, members: 6, is_admin: false, can_post: true, muted: false, muted_until: null, unread: 1, mentions: 1, urgent: 0, last: { id: 8, text: 'ছবিগুলো দারুণ হয়েছে, প্রকাশ করছি', label: '', author: 'ডেস্ক', at: day(0, 8) }, last_at: day(0, 8) },
 { id: 3, kind: 'area', name: 'রাজশাহী বিভাগ', about: '', icon: '📍', avatar: '', online: false, posting: 'all', archived: false, members: 24, is_admin: false, can_post: true, muted: false, muted_until: null, unread: 0, mentions: 0, urgent: 0, last: { id: 7, text: 'বগুড়ার আপডেট পাঠালাম', label: '', author: 'আপনি', at: day(1) }, last_at: day(1) },
];
const labels = [{ key: 'urgent', name: 'জরুরি', color: '#dc2626' }, { key: 'meeting', name: 'মিটিং', color: '#7c3aed' }, { key: 'notice', name: 'নোটিশ', color: '#2563eb' }, { key: 'task', name: 'কাজ', color: '#d97706' }, { key: 'info', name: 'তথ্য', color: '#0891b2' }, { key: 'general', name: 'সাধারণ', color: '#64748b' }];
function api(p) {
  if (p.startsWith('/auth/refresh')) return { access_token: 'x.y.z', expires_in: 3600 };
  if (p === '/me') return me;
  if (p.startsWith('/me/analytics')) return { totals: { views: sum('views'), reactions: sum('reactions'), shares: sum('shares'), comments: sum('comments') }, trend, range: '30' };
  if (p.startsWith('/me/earnings')) return { available_balance: 4850, entries: ledger };
  if (p.startsWith('/me/withdraw-method')) return { method: 'bkash', account_last4: '4521', configured: true };
  if (p.startsWith('/me/withdraw-requests')) return [];
  if (p.startsWith('/submissions')) return subs;
  if (p.startsWith('/videos')) return [];
  if (p.startsWith('/categories')) return [{ id: 3, name: 'সারাদেশ', slug: 'saradesh' }];
  if (p.startsWith('/notifications')) return { items: [{ id: 5, type: 'approved', title: 'আপনার সংবাদ প্রকাশিত হয়েছে', body: titles[0], link: '', object_id: 100, read: false, created_at: day(0, 8) }], unread: 2, latest_id: 5 };
  if (p.startsWith('/live')) return { items: [{ id: 50, title: 'বগুড়া পৌর নির্বাচন: সরাসরি', link: '', type: 'event', status: 'live', last: Date.now() / 1000 - 600, count: 14, pending: 0, thumb: '' }] };
  if (p.startsWith('/chat/summary')) return { unread: 4, mentions: 1, urgent: 1, latest_id: 9, latest: null };
  if (p.startsWith('/chat/rooms')) return { rooms, labels, reactions: ['👍', '❤️'], me: { id: 7, staff: false, publisher: false } };
  if (p.startsWith('/push/prefs')) return { chat: true, urgent: true, inbox: true, alert: true };
  if (p.startsWith('/app/android')) return { available: false };
  return {};
}
(async () => {
  const b = await chromium.launch();
  for (const [name, route, scroll] of JSON.parse(process.argv[4])) {
    const ctx = await b.newContext({ ...devices['Pixel 7'], deviceScaleFactor: 2, serviceWorkers: 'block', locale: 'bn-BD' });
    await ctx.route('https://api.mock/**', r => { const u = new URL(r.request().url()); const p = u.pathname.replace('/nros/v1', '') + u.search; r.fulfill({ status: 200, contentType: 'application/json', headers: { 'access-control-allow-origin': 'http://localhost:5181', 'access-control-allow-credentials': 'true', 'access-control-allow-headers': '*', 'access-control-allow-methods': '*' }, body: JSON.stringify(api(p.split('?')[0] === '/me' ? '/me' : p)) }); });
    const pg = await ctx.newPage();
    pg.on('pageerror', e => console.log('ERR', name, e.message));
    await pg.addInitScript(() => { localStorage.setItem('nros_native_perms_v1', '1'); });
    await pg.goto('http://localhost:5181' + route); await pg.waitForTimeout(2500);
    if (scroll) { await pg.evaluate(y => { const m = document.querySelector('main') || document.scrollingElement; (document.scrollingElement).scrollTo(0, y); document.querySelectorAll('*').forEach(e => { if (e.scrollHeight > e.clientHeight + 50 && getComputedStyle(e).overflowY === 'auto') e.scrollTop = y; }); }, scroll); await pg.waitForTimeout(800); }
    await pg.screenshot({ path: path.join(OUT, name + '.png') });
    await ctx.close();
  }
  await b.close(); srv.close();
})();
