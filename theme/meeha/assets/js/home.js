/* home.js — homepage + archive progressive enhancement (owner C). Loaded with `defer` in the `home` and
   `archive` bundles. Without JS everything still works: the default most-read tab is server-rendered and
   "আরও খবর দেখুন" is a plain link to /page/N/.
   1. সর্বাধিক পঠিত tabs: other windows are fetched once from GET /wp-json/meeha/v1/most-read (public,
      cacheable) when their tab is first selected (A's meeha.js handles the tab switching itself).
   2. Archives: "আরও খবর দেখুন" fetches the next page's `?meeha_fragment=1` HTML (cacheable by LiteSpeed),
      appends its cards to the grid, then points the button at the following page (or removes it). */
(() => {
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const bn = n => String(n).replace(/\d/g, d => '০১২৩৪৫৬৭৮৯'[d]);

  /* ---- 1. most-read tabs ---- */
  $$('[data-mostread]').forEach(box => {
    const load = panel => {
      const src = panel && panel.dataset.src; if (!src || panel.dataset.loaded) return;
      panel.dataset.loaded = '1'; box.setAttribute('aria-busy', 'true');
      fetch(src, { credentials: 'omit' }).then(r => (r.ok ? r.json() : Promise.reject(r.status)))
        .then(d => { if (d && typeof d.html === 'string') panel.innerHTML = d.html; })
        .catch(() => { delete panel.dataset.loaded; panel.innerHTML = '<p class="m-rank__empty">তালিকা আনা যায়নি — আবার চেষ্টা করুন।</p>'; })
        .finally(() => box.removeAttribute('aria-busy'));
    };
    $$('[role=tab]', box).forEach(tab => {
      const go = () => load(document.getElementById(tab.getAttribute('aria-controls')));
      tab.addEventListener('click', go);
      tab.addEventListener('keyup', e => { if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') { const t = document.activeElement; if (t && t.getAttribute('role') === 'tab') load(document.getElementById(t.getAttribute('aria-controls'))); } });
      tab.addEventListener('pointerenter', go, { once: true }); // warm the cache on hover (desktop)
    });
  });

  /* ---- 2. archive load more ---- */
  const btn = document.querySelector('[data-loadmore]');
  const grid = document.querySelector('[data-archgrid]');
  if (btn && grid && window.fetch && 'DOMParser' in window) {
    const status = document.querySelector('[data-loadmore-status]');
    btn.addEventListener('click', e => {
      if (e.metaKey || e.ctrlKey || e.shiftKey || e.button > 0) return;
      e.preventDefault();
      if (btn.getAttribute('aria-busy') === 'true') return;
      const href = btn.href, url = new URL(href, location.href);
      url.searchParams.set('meeha_fragment', '1');
      btn.setAttribute('aria-busy', 'true'); const label = btn.textContent; btn.textContent = 'লোড হচ্ছে…';
      fetch(url.toString(), { credentials: 'same-origin' })
        .then(r => (r.ok ? r.text() : Promise.reject(r.status)))
        .then(html => {
          const doc = new DOMParser().parseFromString(html, 'text/html');
          const frag = doc.querySelector('[data-meeha-fragment]'); if (!frag) throw new Error('fragment');
          const cards = $$('[data-archgrid] > *', frag); let first = null;
          cards.forEach(c => { const n = document.importNode(c, true); n.classList.add('is-new'); grid.appendChild(n); if (!first) first = n; });
          if (status) status.textContent = bn(cards.length) + 'টি নতুন খবর যোগ হয়েছে';
          const next = frag.dataset.next;
          if (next) { btn.href = next; btn.textContent = label; btn.removeAttribute('aria-busy'); }
          else btn.closest('.m-loadmore').remove();
          const link = first && first.querySelector('.m-story__title a'); if (link) link.focus({ preventScroll: true });
        })
        .catch(() => { location.href = href; });
    });
  }
})();
