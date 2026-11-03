/*
 * দেশচিত্র LIVE (2.4.9) — the broadcast-style "আজ সরাসরি" band on top of [meeha_deshchitro].
 * Today's figures are the sum of the দেশচিত্র counts typed on today's published Meeha News posts
 * (GET meeha/v1/deshchitro/live). Redraws every 60 s while the tab is visible; counters roll up,
 * changed numbers flash with a "+n" badge, districts with new incidents pulse on the mini map,
 * and the latest headlines run in a ticker.
 */
(function () {
  'use strict';

  var root = document.querySelector('[data-dc-live]');
  var dataEl = document.getElementById('meeha-dc-data');
  if (!root || !dataEl) return;
  var boot;
  try { boot = JSON.parse(dataEl.textContent); } catch (e) { return; }
  if (!boot.live || !boot.p) return;

  var REST = root.getAttribute('data-live-rest');
  var MAP = window.MeehaBDMap;
  var ICON = window.MeehaDCIcon || function () { return ''; };
  var GEO = boot.geo;
  var INDS = boot.p.indicators;
  var L = boot.live;
  var prev = null; // previous payload, for change highlights
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var inner = root.querySelector('[data-live-in]');
  var clockEl;

  var BN = '০১২৩৪৫৬৭৮৯';
  function bn(v) { return String(v).replace(/[0-9]/g, function (d) { return BN[+d]; }); }
  function esc(s) {
    return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function clock12(hm) {
    if (!hm) return '';
    var h = +hm.slice(0, 2), m = hm.slice(3, 5);
    var part = h < 4 ? 'রাত' : h < 6 ? 'ভোর' : h < 12 ? 'সকাল' : h < 15 ? 'দুপুর' : h < 18 ? 'বিকেল' : h < 20 ? 'সন্ধ্যা' : 'রাত';
    return part + ' ' + bn(h % 12 || 12) + ':' + bn(m);
  }
  function negKeys() { return INDS.filter(function (i) { return i.tone === 'bad' || i.tone === 'warn'; }).map(function (i) { return i.key; }); }
  function sumKeys(o, keys) { return keys.reduce(function (s, k) { return s + ((o || {})[k] || 0); }, 0); }
  function indMap() { var m = {}; INDS.forEach(function (i) { m[i.key] = i; }); return m; }

  /* ---------------------------------------------------------------- clock */
  var fmtClock = null;
  try {
    fmtClock = new Intl.DateTimeFormat('en-GB', { timeZone: 'Asia/Dhaka', hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23' });
  } catch (e) { fmtClock = null; }
  var skew = 0; // server clock minus this device's clock (set from live responses; embedded data may be cached)
  function tick() {
    if (!clockEl) return;
    var d = new Date(Date.now() + skew);
    var s = fmtClock ? fmtClock.format(d) : d.toTimeString().slice(0, 8);
    clockEl.textContent = bn(s);
  }

  /* ---------------------------------------------------------------- render */
  function render() {
    var im = indMap(), neg = negKeys();
    var deaths = ['khun', 'sorok_nihoto', 'attohotya', 'dube'].filter(function (k) { return im[k]; });
    var totNeg = sumKeys(L.tot, neg), totDeath = sumKeys(L.tot, deaths);
    var prevTot = prev ? prev.tot : null;
    var nDist = Object.keys(L.districts || {}).length;

    var html = '';
    // Top bar: ON AIR + title + date + running clock.
    html += '<div class="dcl-bar"><span class="dcl-onair"><i aria-hidden="true"></i>লাইভ</span>' +
      '<h2 class="dcl-h" id="dcl-h">আজ সরাসরি <span>দেশচিত্র</span></h2>' +
      '<span class="dcl-date">' + esc(L.label) + '</span>' +
      '<span class="dcl-clock" aria-label="এখনকার সময়" data-clock></span></div>';

    html += '<div class="dcl-grid">';
    // Scoreboard.
    html += '<div class="dcl-board">';
    html += '<div class="dcl-hero">' +
      '<div class="dcl-big"><span class="dcl-big__k">আজ এখন পর্যন্ত নেতিবাচক ঘটনা</span><strong class="dcl-big__v" data-roll="' + totNeg + '" data-from="' + (prevTot ? sumKeys(prevTot, neg) : 0) + '">' + bn(totNeg) + '</strong></div>' +
      (deaths.length ? '<div class="dcl-big dcl-big--sub"><span class="dcl-big__k">অস্বাভাবিক মৃত্যু</span><strong class="dcl-big__v" data-roll="' + totDeath + '" data-from="' + (prevTot ? sumKeys(prevTot, deaths) : 0) + '">' + bn(totDeath) + '</strong><span class="dcl-big__u">জন</span></div>' : '') +
      '<div class="dcl-big dcl-big--sub"><span class="dcl-big__k">জেলা</span><strong class="dcl-big__v">' + bn(nDist) + '</strong><span class="dcl-big__u">/ ৬৪</span></div>' +
      '</div>';
    html += '<ul class="dcl-kpis">' + INDS.map(function (i) {
      var v = L.tot[i.key] || 0, was = prevTot ? (prevTot[i.key] || 0) : v;
      var up = v > was ? '<span class="dcl-plus">+' + bn(v - was) + '</span>' : '';
      return '<li class="dcl-kpi dcl-t-' + esc(i.tone) + (v > was ? ' is-new' : '') + (v ? '' : ' is-zero') + '">' + ICON(i.icon, 'dcl-ico') +
        '<span class="dcl-kpi__l">' + esc(i.short) + '</span><strong class="dcl-kpi__v" data-roll="' + v + '" data-from="' + was + '">' + bn(v) + '</strong>' + up + '</li>';
    }).join('') + '</ul>';
    html += '</div>';

    // Mini map.
    html += '<div class="dcl-map"><div class="dcl-map__box" data-live-map></div><p class="dcl-map__cap"><span class="dcl-dot" aria-hidden="true"></span>আজ যেসব জেলার খবরে ঘটনা আছে</p></div>';

    // Latest feed.
    html += '<div class="dcl-feed"><h3 class="dcl-feed__h">সর্বশেষ খবর</h3>';
    if (!L.posts.length) {
      html += '<p class="dcl-empty">আজ এখনো দেশচিত্রের ঘরসহ কোনো খবর প্রকাশ হয়নি। খবর প্রকাশ হলেই এখানে যোগ হবে।</p>';
    } else {
      var seen = {};
      (prev ? prev.posts : []).forEach(function (p) { seen[p.id] = 1; });
      html += '<ol class="dcl-list">' + L.posts.slice(0, 6).map(function (p) {
        var chips = Object.keys(p.c).filter(function (k) { return im[k]; }).map(function (k) {
          return '<span class="dcl-chip dcl-t-' + esc(im[k].tone) + '">' + esc(im[k].short) + ' ' + bn(p.c[k]) + '</span>';
        }).join('');
        return '<li class="dcl-item' + (prev && !seen[p.id] ? ' is-new' : '') + '"><a href="' + esc(p.url) + '">' +
          '<span class="dcl-item__m"><time>' + esc(clock12(p.time)) + '</time>' + (p.dn ? ' · ' + esc(p.dn) : '') + '</span>' +
          '<span class="dcl-item__t">' + esc(p.title) + '</span><span class="dcl-chips">' + chips + '</span></a></li>';
      }).join('') + '</ol>';
    }
    html += '</div>';
    html += '</div>'; // grid

    // Ticker.
    if (L.posts.length) {
      var items = L.posts.slice(0, 12).map(function (p) {
        return '<a class="dcl-tk__i" href="' + esc(p.url) + '"><b>' + esc(clock12(p.time)) + '</b>' + (p.dn ? '<em>' + esc(p.dn) + '</em>' : '') + esc(p.title) + '</a>';
      }).join('');
      html += '<div class="dcl-tk"><span class="dcl-tk__k">ব্রেকিং</span><div class="dcl-tk__vp"><div class="dcl-tk__run' + (reduce ? ' is-static' : '') + '">' + items + (reduce ? '' : '<span aria-hidden="true" class="dcl-tk__dup">' + items + '</span>') + '</div></div></div>';
    }

    html += '<p class="dcl-note">মিহা নিউজে আজ প্রকাশিত ' + bn(L.n) + 'টি খবরে দেওয়া হিসাব থেকে স্বয়ংক্রিয়ভাবে যোগ করা' +
      (L.last ? ' · সর্বশেষ খবর ' + esc(clock12(L.last)) : '') + ' · প্রতি মিনিটে হালনাগাদ। সব মাধ্যম মিলিয়ে চূড়ান্ত হিসাব রাত ১২টার পর নিচে প্রকাশ হয়।</p>';

    inner.innerHTML = html;
    clockEl = inner.querySelector('[data-clock]');
    tick();
    drawMap();
    roll();
    var dup = inner.querySelector('.dcl-tk__run:not(.is-static)');
    if (dup) {
      // ~70 px per second regardless of how many headlines there are.
      var w = dup.scrollWidth / 2;
      dup.style.animationDuration = Math.max(20, Math.round(w / 70)) + 's';
    }
  }

  function roll() {
    if (reduce) return;
    inner.querySelectorAll('[data-roll]').forEach(function (el) {
      var to = +el.getAttribute('data-roll'), from = +el.getAttribute('data-from');
      if (from === to) return;
      var t0 = null, dur = 900;
      function step(t) {
        if (!t0) t0 = t;
        var k = Math.min(1, (t - t0) / dur);
        k = 1 - Math.pow(1 - k, 3);
        el.textContent = bn(Math.round(from + (to - from) * k));
        if (k < 1) window.requestAnimationFrame(step);
      }
      el.textContent = bn(from);
      window.requestAnimationFrame(step);
    });
  }

  /* ---------------------------------------------------------------- map */
  var tip;
  function drawMap() {
    var box = inner.querySelector('[data-live-map]');
    if (!box || !MAP) return;
    var neg = negKeys(), D = L.districts || {}, P0 = prev ? prev.districts || {} : null;
    var max = 0;
    Object.keys(D).forEach(function (k) { max = Math.max(max, sumKeys(D[k], INDS.map(function (i) { return i.key; }))); });
    var paths = '', dots = '';
    MAP.d.forEach(function (d) {
      var all = sumKeys(D[d.k], INDS.map(function (i) { return i.key; }));
      var n = sumKeys(D[d.k], neg);
      var good = sumKeys(D[d.k], INDS.filter(function (i) { return i.tone === 'good'; }).map(function (i) { return i.key; }));
      var kind = n ? 'neg' : good ? 'pos' : 'info';
      var cls = all ? 'is-' + kind : '';
      paths += '<path class="dcl-d ' + cls + '" data-k="' + d.k + '" d="' + d.p + '"/>';
      if (all) {
        var r = 13 + 13 * Math.sqrt(all / (max || 1));
        var fresh = P0 && sumKeys(P0[d.k], INDS.map(function (i) { return i.key; })) < all;
        dots += '<g class="dcl-pin' + (kind === 'neg' ? '' : ' dcl-pin--' + kind) + (fresh || !P0 ? ' is-pulse' : '') + '" data-k="' + d.k + '" transform="translate(' + d.c[0] + ' ' + d.c[1] + ')">' +
          '<circle class="dcl-pin__ring" r="' + r.toFixed(1) + '"/><circle class="dcl-pin__c" r="' + r.toFixed(1) + '"/><text dy=".36em">' + bn(all) + '</text></g>';
      }
    });
    box.innerHTML = '<svg viewBox="0 0 ' + MAP.w + ' ' + MAP.h + '" role="img" aria-label="আজকের ঘটনার জেলাভিত্তিক মানচিত্র">' +
      '<path class="dcl-out" d="' + MAP.out + '"/><g>' + paths + '</g><path class="dcl-divl" d="' + MAP.div + '"/><g>' + dots + '</g></svg>';
    if (!tip) {
      tip = document.createElement('div');
      tip.className = 'dcl-tip';
      tip.hidden = true;
      root.appendChild(tip);
    }
    var svg = box.querySelector('svg');
    function show(e) {
      var g = e.target.closest('[data-k]');
      if (!g) { tip.hidden = true; return; }
      var k = g.getAttribute('data-k'), v = D[k] || {}, im = indMap();
      var rows = Object.keys(v).filter(function (x) { return im[x]; }).map(function (x) {
        return '<li class="dcl-t-' + esc(im[x].tone) + '"><span>' + esc(im[x].short) + '</span><b>' + bn(v[x]) + '</b></li>';
      }).join('');
      tip.innerHTML = '<strong>' + esc(GEO.districts[k] ? GEO.districts[k][0] : k) + '</strong>' + (rows ? '<ul>' + rows + '</ul>' : '<p>আজ এখনো কোনো ঘটনা নেই</p>');
      tip.hidden = false;
      var rb = root.getBoundingClientRect();
      var x = (e.clientX || 0) - rb.left, y = (e.clientY || 0) - rb.top;
      var tw = tip.offsetWidth, th = tip.offsetHeight;
      x = Math.min(Math.max(8, x + 14), rb.width - tw - 8);
      y = y - th - 12 < 0 ? y + 16 : y - th - 12;
      tip.style.transform = 'translate(' + x + 'px,' + y + 'px)';
    }
    svg.addEventListener('mousemove', show);
    svg.addEventListener('click', show);
    svg.addEventListener('mouseleave', function () { tip.hidden = true; });
  }

  /* ---------------------------------------------------------------- polling */
  var busy = false;
  function refresh() {
    if (busy || document.hidden || !REST) return;
    busy = true;
    fetch(REST + (REST.indexOf('?') > -1 ? '&' : '?') + '_=' + Math.floor(Date.now() / 30000), { credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
      .then(function (p) {
        busy = false;
        if (!p || !p.on) { root.hidden = true; return; }
        var dayChanged = p.day !== L.day;
        prev = dayChanged ? null : L;
        L = p;
        if (p.ts) skew = p.ts * 1000 - Date.now();
        render();
      })
      .catch(function () { busy = false; });
  }

  root.classList.add('is-ready');
  render();
  // The page may come from a full-page cache: refresh right away if the embedded data is older than a minute.
  if (!L.ts || Math.abs(Date.now() - L.ts * 1000) > 60000) refresh();
  setInterval(tick, 1000);
  setInterval(refresh, 60000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) refresh(); });
})();
