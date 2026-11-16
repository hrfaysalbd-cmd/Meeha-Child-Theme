/* liveband.js — the homepage "এখন লাইভ" band (2.4.14). Deferred; without JS the band is simply the server's copy.
   Every 60 s while the tab is visible each card asks the public poll GET meeha/v1/liveblog/{id}?brief=N for the
   newest lines, the update count and the live state; a story that ended (or stopped being live) leaves the band, and
   the band leaves the page when nothing runs any more. Paused while the tab is hidden; catches up on return.
   "২ মিনিট আগে" labels keep ticking. Desktop arrows scroll the band when 3+ lives run. */
(() => {
  const band = document.querySelector('[data-liveband]');
  if (!band || !window.fetch) return;
  const track = band.querySelector('[data-liveband-track]');
  const lines = Math.max(2, Math.min(3, parseInt(band.dataset.lines, 10) || 3));
  const EVERY = 60000;
  const bn = n => String(n).replace(/\d/g, d => '০১২৩৪৫৬৭৮৯'[d]);
  const ago = ts => {
    const s = Math.max(0, Math.floor(Date.now() / 1000) - ts);
    if (s < 60) return 'এইমাত্র';
    const u = [[31536000, 'বছর'], [2592000, 'মাস'], [604800, 'সপ্তাহ'], [86400, 'দিন'], [3600, 'ঘণ্টা'], [60, 'মিনিট']];
    for (const [sec, l] of u) if (s >= sec) return bn(Math.floor(s / sec)) + ' ' + l + ' আগে';
    return 'এইমাত্র';
  };
  const tick = () => band.querySelectorAll('[data-ago]').forEach(el => { el.textContent = ago(+el.dataset.ago); });
  const TAGS = { breaking: 'ব্রেকিং', key: 'গুরুত্বপূর্ণ' };

  const drop = card => {
    card.remove();
    if (!band.querySelector('[data-livecard]')) band.remove();
    else arrows();
  };

  const paint = (card, d) => {
    const list = card.querySelector('[data-livecard-list]');
    const count = card.querySelector('[data-livecard-count]');
    if (count) count.textContent = 'মোট ' + bn(d.count || 0) + 'টি আপডেট';
    let last = card.querySelector('.m-livecard__meta [data-ago]');
    if (d.last) {
      if (!last) {
        const span = document.createElement('span');
        span.textContent = 'সর্বশেষ আপডেট ';
        last = document.createElement('b');
        span.appendChild(last);
        card.querySelector('.m-livecard__meta').prepend(span);
      }
      last.dataset.ago = d.last;
      card.dataset.last = d.last;
    }
    if (!list || !Array.isArray(d.items)) return;
    const seen = new Set([...list.querySelectorAll('a')].map(a => a.getAttribute('href')));
    const frag = document.createDocumentFragment();
    d.items.slice(0, lines).forEach(it => {
      const href = d.url + '#lb-' + it.id;
      const li = document.createElement('li');
      li.className = 'm-livecard__u m-livecard__u--' + String(it.kind || 'update').replace(/[^a-z]/g, '');
      if (!seen.has(href)) li.classList.add('is-new');
      const a = document.createElement('a');
      a.href = href;
      const t = document.createElement('time');
      t.dateTime = new Date(it.t * 1000).toISOString();
      t.textContent = it.clock || '';
      a.appendChild(t);
      a.append(' ');
      if (TAGS[it.kind]) {
        const tag = document.createElement('span');
        tag.className = 'm-livecard__tag';
        tag.textContent = TAGS[it.kind];
        a.appendChild(tag);
        a.append(' ');
      }
      const txt = document.createElement('span');
      txt.className = 'm-livecard__txt';
      txt.textContent = it.text || '';
      a.appendChild(txt);
      li.appendChild(a);
      frag.appendChild(li);
    });
    list.replaceChildren(frag);
    list.hidden = !d.items.length;
  };

  let timer = 0, lastRun = Date.now();
  const run = () => {
    clearTimeout(timer);
    lastRun = Date.now();
    const cards = [...band.querySelectorAll('[data-livecard]')];
    Promise.all(cards.map(card => {
      const api = card.dataset.api;
      if (!api) return null;
      return fetch(api + (api.indexOf('?') < 0 ? '?' : '&') + 'brief=' + lines, { credentials: 'omit', headers: { Accept: 'application/json' } })
        .then(r => {
          if (r.status === 404) { drop(card); return null; }
          return r.ok ? r.json() : null;
        })
        .then(d => {
          if (!d) return;
          if (d.status !== 'live') { drop(card); return; }
          paint(card, d);
        })
        .catch(() => {});
    })).then(() => {
      tick();
      if (document.body.contains(band) && !document.hidden) timer = setTimeout(run, EVERY);
    });
  };

  document.addEventListener('visibilitychange', () => {
    if (document.hidden) { clearTimeout(timer); timer = 0; return; }
    if (!document.body.contains(band)) return;
    if (Date.now() - lastRun >= EVERY) run();
    else { clearTimeout(timer); timer = setTimeout(run, EVERY - (Date.now() - lastRun)); }
  });

  /* desktop arrows (3+ lives) */
  const prev = band.querySelector('[data-liveband-step="-1"]');
  const next = band.querySelector('[data-liveband-step="1"]');
  const arrows = () => {
    if (!prev || !next || !track) return;
    const max = track.scrollWidth - track.clientWidth - 2;
    prev.disabled = track.scrollLeft <= 2;
    next.disabled = track.scrollLeft >= max;
  };
  if (prev && next && track) {
    [prev, next].forEach(b => b.addEventListener('click', () => {
      const card = track.querySelector('[data-livecard]');
      const w = card ? card.getBoundingClientRect().width + 16 : track.clientWidth;
      track.scrollBy({ left: +b.dataset.livebandStep * w, behavior: 'smooth' });
    }));
    track.addEventListener('scroll', () => requestAnimationFrame(arrows), { passive: true });
    arrows();
  }

  tick();
  setInterval(() => { if (!document.hidden) tick(); }, 30000);
  if (!document.hidden) timer = setTimeout(run, EVERY);
})();
