/* assistant.js — মিহা সহকারী bubble (tiny; loaded `defer` on every public page).
   - Builds the bubble + floating preset questions after the page has loaded (idle), so it never competes with LCP.
   - Floating questions: hidden while scrolling down, back on scroll up; × hides them for the rest of the day;
     on phones at most 2 (the live chip counts).
   - লাইভ: config.live (from the cached page) is refreshed from GET …/assistant/state (≤ once per 2 min per tab).
   - The chat panel (assistant-panel.js/.css) is loaded on first open, prefetched on hover/focus of the bubble.
   window.MeehaAssistant.open(question?) opens the panel from anywhere. */
(() => {
  const C = window.MeehaAssistantConfig;
  if (!C || !C.rest || window.MeehaAssistant) return;
  const ls = {
    get: k => { try { return localStorage.getItem(k); } catch (e) { return null; } },
    set: (k, v) => { try { localStorage.setItem(k, v); } catch (e) {} }
  };
  const ss = {
    get: k => { try { return JSON.parse(sessionStorage.getItem(k)); } catch (e) { return null; } },
    set: (k, v) => { try { sessionStorage.setItem(k, JSON.stringify(v)); } catch (e) {} }
  };
  const today = () => { const d = new Date(); return d.getFullYear() + '-' + (d.getMonth() + 1) + '-' + d.getDate(); };
  const el = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; };
  const mobile = () => window.matchMedia('(max-width: 600px)').matches;

  let root, btn, float, live = Array.isArray(C.live) ? C.live : [], panelP = null, opened = false;

  const loadPanel = () => {
    if (panelP) return panelP;
    panelP = new Promise((resolve, reject) => {
      const P = C.panel || {};
      (P.css || []).forEach(href => {
        const l = document.createElement('link'); l.rel = 'stylesheet'; l.href = href; document.head.appendChild(l);
      });
      const js = (P.js || [])[0];
      if (!js) return reject(new Error('no panel'));
      const s = document.createElement('script'); s.src = js; s.async = true;
      s.onload = () => window.MeehaAssistantPanel ? resolve(window.MeehaAssistantPanel) : reject(new Error('panel'));
      s.onerror = () => { panelP = null; reject(new Error('load')); };
      document.head.appendChild(s);
    });
    return panelP;
  };

  const setOpen = on => {
    opened = on;
    root.classList.toggle('m-as--open', on);
    btn.setAttribute('aria-expanded', on ? 'true' : 'false');
  };

  const open = q => {
    btn.setAttribute('aria-busy', 'true');
    return loadPanel().then(P => {
      btn.removeAttribute('aria-busy');
      setOpen(true);
      P.open({ config: C, question: q || '', live, onClose: () => { setOpen(false); btn.focus(); } });
    }).catch(() => {
      btn.removeAttribute('aria-busy');
      window.location.href = (C.home || '/') + '?s=' + encodeURIComponent(q || '');
    });
  };

  const dismissed = () => ls.get('meeha_as_float_off') === today();

  const renderFloat = () => {
    float.textContent = '';
    if (dismissed()) { float.hidden = true; return; }
    const items = [];
    if (C.liveOn && live[0]) {
      const a = el('a', 'm-as__chip m-as__chip--live');
      a.href = live[0].url;
      a.append(el('span', 'm-as__dot'), el('b', '', 'এখন লাইভ:'), el('span', '', live[0].title));
      a.setAttribute('aria-label', 'এখন লাইভ: ' + live[0].title);
      items.push(a);
    }
    (C.float || []).forEach(q => {
      const b = el('button', 'm-as__chip', q); b.type = 'button';
      b.addEventListener('click', () => open(q));
      items.push(b);
    });
    const max = mobile() ? 2 : 4;
    items.slice(0, max).forEach(n => float.appendChild(n));
    if (!float.children.length) { float.hidden = true; return; }
    const x = el('button', 'm-as__x', '×'); x.type = 'button';
    x.setAttribute('aria-label', 'প্রশ্নের পরামর্শ আজকের জন্য বন্ধ করুন');
    x.addEventListener('click', () => { ls.set('meeha_as_float_off', today()); float.hidden = true; btn.focus(); });
    float.appendChild(x);
    float.hidden = false;
  };

  const setLive = list => {
    live = Array.isArray(list) ? list : [];
    const on = !!(C.liveOn && live.length);
    root.classList.toggle('m-as--live', on);
    btn.setAttribute('aria-label', C.name + (on ? ' — এখন লাইভ চলছে' : ''));
    if (window.MeehaAssistantPanel && window.MeehaAssistantPanel.setLive) window.MeehaAssistantPanel.setLive(live);
    if (!opened) renderFloat();
  };

  const refreshLive = () => {
    if (!C.liveOn) return;
    const cached = ss.get('meeha_as_state');
    if (cached && Date.now() - cached.t < 12e4) return setLive(cached.live);
    fetch(C.rest + '/state', { credentials: 'omit' }).then(r => r.ok ? r.json() : null).then(j => {
      if (!j) return;
      ss.set('meeha_as_state', { t: Date.now(), live: j.live || [] });
      setLive(j.live || []);
    }).catch(() => {});
  };

  const build = () => {
    root = el('div', 'm-as');
    root.style.cssText = 'position:fixed;opacity:0';
    float = el('div', 'm-as__float'); float.hidden = true;
    float.setAttribute('role', 'group'); float.setAttribute('aria-label', 'যা জিজ্ঞেস করতে পারেন');
    btn = el('button', 'm-as__btn'); btn.type = 'button';
    btn.setAttribute('aria-haspopup', 'dialog'); btn.setAttribute('aria-expanded', 'false'); btn.setAttribute('aria-controls', 'm-asp');
    btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 5.5A2.5 2.5 0 0 1 6.5 3h11A2.5 2.5 0 0 1 20 5.5v8a2.5 2.5 0 0 1-2.5 2.5H10l-4.2 3.6c-.5.4-1.3.1-1.3-.6V16A2.5 2.5 0 0 1 4 13.5v-8Z" fill="currentColor" opacity=".18"/><path d="M4 5.5A2.5 2.5 0 0 1 6.5 3h11A2.5 2.5 0 0 1 20 5.5v8a2.5 2.5 0 0 1-2.5 2.5H10l-4.2 3.6c-.5.4-1.3.1-1.3-.6V16A2.5 2.5 0 0 1 4 13.5v-8Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M8.5 9.5h.01M12 9.5h.01M15.5 9.5h.01" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"/></svg>';
    btn.append(el('span', 'm-as__lbl', C.label || C.name), el('span', 'm-as__live', 'লাইভ'));
    btn.setAttribute('aria-label', C.name);
    btn.addEventListener('click', () => {
      if (opened && window.MeehaAssistantPanel) return window.MeehaAssistantPanel.close();
      open('');
    });
    ['pointerenter', 'focus', 'touchstart'].forEach(ev => btn.addEventListener(ev, () => { loadPanel().catch(() => {}); }, { once: true, passive: true }));
    root.append(float, btn);
    document.body.appendChild(root);

    setLive(live);
    root.classList.add('m-as--away');
    setTimeout(() => { root.classList.remove('m-as--away'); }, Math.max(0, C.delay || 0));
    refreshLive();

    // Never cover the footer's "উপরে যান" link (or anything marked [data-meeha-avoid]): lift above it while visible.
    const avoid = document.querySelectorAll('.m-totop, [data-meeha-avoid]');
    const lift = () => {
      let up = 0;
      const b = btn.getBoundingClientRect(), base = b.bottom + (parseFloat(root.dataset.lift) || 0);
      avoid.forEach(n => {
        const r = n.getBoundingClientRect();
        if (r.width && r.right > b.left - 8 && r.left < b.right + 8 && r.top < base + 8 && r.bottom > base - b.height - 8) up = Math.max(up, Math.round(base - r.top + 10));
      });
      root.dataset.lift = up;
      root.style.transform = up ? 'translateY(' + (-up) + 'px)' : '';
      document.documentElement.style.setProperty('--m-as-lift', up + 'px');
    };
    lift();
    window.addEventListener('resize', lift, { passive: true });

    let lastY = window.scrollY, ticking = false;
    window.addEventListener('scroll', () => {
      if (ticking) return; ticking = true;
      requestAnimationFrame(() => {
        const y = window.scrollY;
        if (Math.abs(y - lastY) > 8) { root.classList.toggle('m-as--away', y > lastY && y > 120); lastY = y; }
        lift();
        ticking = false;
      });
    }, { passive: true });
    window.matchMedia('(max-width: 600px)').addEventListener('change', () => { if (!opened) renderFloat(); });
  };

  window.MeehaAssistant = { open: q => (root ? open(q) : null), loadPanel };
  const start = () => ('requestIdleCallback' in window ? requestIdleCallback(build, { timeout: 2500 }) : setTimeout(build, 300));
  if (document.readyState === 'complete') start(); else window.addEventListener('load', start, { once: true });
})();
