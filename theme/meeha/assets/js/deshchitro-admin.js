/*
 * দেশচিত্র — daily input sheet (wp-admin → দেশচিত্র → দৈনিক হিসাব).
 * Talks to GET/POST/DELETE meeha/v1/deshchitro/sheet. Grid = 64 districts × indicators (Bangla or English digits,
 * Enter moves down, paste a block copied from Excel/Sheets), economy values with the previous sheet as a hint,
 * notable events (pick from the day's published news) and the editor's summary.
 */
(function () {
  'use strict';
  var mount = document.getElementById('meeha-dc-admin');
  if (!mount || !window.MeehaDC) return;
  var CFG = window.MeehaDC;
  var S = null, dirty = false, tab = 'grid', collapsed = {}, filter = '';

  var BN = '০১২৩৪৫৬৭৮৯';
  function bn(v) { return String(v).replace(/[0-9]/g, function (d) { return BN[+d]; }); }
  function en(v) { return String(v).replace(/[০-৯]/g, function (d) { return BN.indexOf(d); }); }
  function esc(s) { return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function ico(id) { return window.MeehaDCIcon ? window.MeehaDCIcon(id, 'dca-ico') : ''; }
  function shift(ymd, n) { var p = ymd.split('-'); var d = new Date(Date.UTC(+p[0], +p[1] - 1, +p[2] + n)); return d.toISOString().slice(0, 10); }

  function api(method, params, body) {
    var url = CFG.rest + (CFG.rest.indexOf('?') > -1 ? '&' : '?') + Object.keys(params).map(function (k) { return k + '=' + encodeURIComponent(params[k]); }).join('&');
    return fetch(url, {
      method: method, credentials: 'same-origin',
      headers: { 'X-WP-Nonce': CFG.nonce, 'Content-Type': 'application/json' },
      body: body ? JSON.stringify(body) : undefined
    }).then(function (r) {
      return r.json().then(function (j) { if (!r.ok) throw new Error(j && j.message ? j.message : 'HTTP ' + r.status); return j; });
    });
  }

  function load(date) {
    if (dirty && !window.confirm('সংরক্ষণ না করা পরিবর্তন আছে। অন্য দিনে গেলে সেগুলো হারাবে। যাবেন?')) return;
    mount.classList.add('is-busy');
    api('GET', { date: date }).then(function (s) {
      S = norm(s); dirty = false; mount.classList.remove('is-busy');
      try { var u = new URL(window.location.href); u.searchParams.set('date', s.day); window.history.replaceState(null, '', u.toString()); } catch (e) { /* ignore */ }
      render();
    }).catch(function (e) { mount.classList.remove('is-busy'); toast('লোড করা যায়নি: ' + e.message, true); });
  }

  /* PHP sends empty maps as [] — make them objects so string keys survive JSON.stringify. */
  function obj(v) { return v && !Array.isArray(v) && typeof v === 'object' ? v : {}; }
  function norm(s) {
    ['inc', 'unk', 'notes', 'eco'].forEach(function (k) { s.data[k] = obj(s.data[k]); });
    Object.keys(s.data.inc).forEach(function (dk) { s.data.inc[dk] = obj(s.data.inc[dk]); });
    s.data.hl = Array.isArray(s.data.hl) ? s.data.hl : [];
    s.prevEco = obj(s.prevEco); s.month = obj(s.month);
    return s;
  }
  function inds() { return S.indicators.filter(function (i) { return +i.on; }); }
  function ecos() { return S.economy.filter(function (e) { return +e.on; }); }
  function distsOf(dv) { return Object.keys(S.geo.districts).filter(function (k) { return S.geo.districts[k][1] === dv; }); }

  /* ---------------------------------------------------------------- render */
  function render() {
    var st = S.exists ? (S.status === 'publish' ? (S.day >= S.today ? 'publish-wait' : 'publish') : 'draft') : 'new';
    var stLabel = { 'new': 'নতুন — এখনো সংরক্ষণ হয়নি', draft: 'খসড়া (পাঠক দেখছেন না)', publish: 'প্রকাশিত — পাঠক দেখছেন', 'publish-wait': 'প্রকাশ নির্ধারিত — রাত ১২টায় দেখা যাবে' }[st];
    var pub = S.exists && S.status === 'publish';
    var html = '<div class="dca-bar">' +
      '<div class="dca-date"><button type="button" class="button" data-step="-1" aria-label="আগের দিন">‹</button>' +
      '<input type="date" data-date value="' + S.day + '" max="' + S.today + '">' +
      '<button type="button" class="button" data-step="1" aria-label="পরের দিন"' + (S.day >= S.today ? ' disabled' : '') + '>›</button>' +
      '<strong class="dca-date__l">' + esc(S.label) + '</strong></div>' +
      '<span class="dca-st dca-st--' + st + '">' + esc(stLabel) + '</span>' +
      (S.updatedAt ? '<span class="dca-upd">শেষ সংরক্ষণ: ' + esc(bn(S.updatedAt.slice(0, 16))) + (S.updatedBy ? ' — ' + esc(S.updatedBy) : '') + '</span>' : '') +
      '<span class="dca-bar__sp"></span>' +
      (S.pageUrl ? '<a class="button" target="_blank" rel="noopener" href="' + esc(S.pageUrl + (S.pageUrl.indexOf('?') > -1 ? '&' : '?') + 'dd=' + S.day) + '">পাতায় দেখুন ↗</a>' : '') +
      '<button type="button" class="button" data-save="draft">' + (pub ? 'খসড়ায় ফেরান' : 'খসড়া সংরক্ষণ') + '</button>' +
      '<button type="button" class="button button-primary" data-save="publish">' + (pub ? 'হালনাগাদ করুন' : S.day >= S.today ? 'প্রকাশ (রাত ১২টায়)' : 'প্রকাশ করুন') + '</button>' +
      '</div>';
    html += monthStrip();
    if (S.day >= S.today) html += '<p class="dca-warn">আজকের দিন এখনো শেষ হয়নি। এখন যা দেবেন তা খসড়া হিসেবে রাখুন বা "প্রকাশ" দিন — রাত ১২টার পর পাঠক দেখবেন।</p>';
    var tabs = [['grid', 'জেলার ঘটনা'], ['eco', 'অর্থনীতি ও বাজার'], ['hl', 'সারকথা ও উল্লেখযোগ্য ঘটনা'], ['news', 'সেদিনের খবর (' + bn(S.news.length) + ')']];
    html += '<div class="dca-tabs" role="tablist">' + tabs.map(function (t) { return '<button type="button" role="tab" aria-selected="' + (tab === t[0]) + '" data-tab="' + t[0] + '">' + esc(t[1]) + '</button>'; }).join('') + '</div>';
    html += '<div class="dca-panel" data-panel></div>';
    mount.innerHTML = html;
    renderPanel();
  }

  function monthStrip() {
    var ym = S.day.slice(0, 7), p = ym.split('-');
    var days = new Date(Date.UTC(+p[0], +p[1], 0)).getUTCDate();
    var out = '<div class="dca-month" aria-label="এই মাসের দিনগুলো">';
    for (var d = 1; d <= days; d++) {
      var ymd = ym + '-' + (d < 10 ? '0' : '') + d;
      var st = S.month[ymd] || '';
      var future = ymd > S.today;
      out += '<button type="button" class="dca-md' + (st ? ' is-' + st : '') + (ymd === S.day ? ' is-cur' : '') + '" data-go="' + ymd + '"' + (future ? ' disabled' : '') + ' title="' + (st === 'publish' ? 'প্রকাশিত' : st === 'draft' ? 'খসড়া' : 'হিসাব নেই') + '">' + bn(d) + '</button>';
    }
    return out + '<span class="dca-month__k"><i class="is-publish"></i>প্রকাশিত <i class="is-draft"></i>খসড়া <i></i>নেই</span></div>';
  }

  function renderPanel() {
    var panel = mount.querySelector('[data-panel]');
    mount.querySelectorAll('[data-tab]').forEach(function (b) { b.setAttribute('aria-selected', b.getAttribute('data-tab') === tab ? 'true' : 'false'); });
    if (tab === 'grid') panel.innerHTML = gridHtml();
    else if (tab === 'eco') panel.innerHTML = ecoHtml();
    else if (tab === 'hl') panel.innerHTML = hlHtml();
    else panel.innerHTML = newsHtml();
    if (tab === 'grid') { totals(); applyFilter(); }
  }

  /* grid */
  function cell(dk, k) { var v = (S.data.inc[dk] || {})[k]; return v ? bn(v) : ''; }
  function gridHtml() {
    var I = inds();
    var head = '<tr><th class="dca-g__d">জেলা</th>' + I.map(function (i) { return '<th class="dca-t-' + esc(i.tone) + '" title="' + esc(i.label) + '">' + ico(i.icon) + '<span>' + esc(i.short) + '</span></th>'; }).join('') + '<th class="dca-g__note">নোট (কার্সার রাখলে পাঠক দেখবেন)</th></tr>';
    var rows = '';
    Object.keys(S.geo.divisions).forEach(function (dv) {
      var c = collapsed[dv];
      rows += '<tr class="dca-div" data-div="' + dv + '"><th><button type="button" class="dca-div__b" data-toggle="' + dv + '" aria-expanded="' + (!c) + '">' + (c ? '▸' : '▾') + ' ' + esc(S.geo.divisions[dv]) + ' বিভাগ</button></th>' + I.map(function (i) { return '<td data-sub="' + dv + ':' + i.key + '"></td>'; }).join('') + '<td></td></tr>';
      distsOf(dv).forEach(function (dk) {
        rows += '<tr class="dca-row" data-row="' + dk + '" data-of="' + dv + '"' + (c ? ' hidden' : '') + '><th>' + esc(S.geo.districts[dk][0]) + '</th>' + I.map(function (i) {
          return '<td><input class="dca-n" inputmode="numeric" autocomplete="off" data-d="' + dk + '" data-i="' + esc(i.key) + '" value="' + cell(dk, i.key) + '" aria-label="' + esc(S.geo.districts[dk][0] + ' — ' + i.label) + '"></td>';
        }).join('') + '<td><input class="dca-note" maxlength="220" data-note="' + dk + '" value="' + esc(S.data.notes[dk] || '') + '" placeholder="ঐচ্ছিক"></td></tr>';
      });
    });
    rows += '<tr class="dca-unk"><th>জেলা অজানা / জাতীয়</th>' + I.map(function (i) { var v = S.data.unk[i.key]; return '<td><input class="dca-n" inputmode="numeric" data-d="_unk" data-i="' + esc(i.key) + '" value="' + (v ? bn(v) : '') + '" aria-label="জেলা অজানা — ' + esc(i.label) + '"></td>'; }).join('') + '<td class="dca-hint">যে ঘটনার জেলা জানা নেই</td></tr>';
    var foot = '<tr><th>সারা দেশ (মোট)</th>' + I.map(function (i) { return '<td data-tot="' + esc(i.key) + '"></td>'; }).join('') + '<td></td></tr>';
    return '<div class="dca-gtools"><input type="search" data-filter placeholder="জেলা খুঁজুন…" value="' + esc(filter) + '">' +
      '<button type="button" class="button" data-collapse="all">সব বিভাগ বন্ধ</button><button type="button" class="button" data-collapse="none">সব খুলুন</button>' +
      '<span class="dca-hint">খালি ঘর = ০। Enter চাপলে নিচের ঘরে যাবে। এক্সেল/শিট থেকে কয়েকটি ঘর কপি করে যেকোনো ঘরে পেস্ট করা যায়।</span></div>' +
      '<div class="dca-gwrap"><table class="dca-g"><thead>' + head + '</thead><tbody>' + rows + '</tbody><tfoot>' + foot + '</tfoot></table></div>';
  }
  function totals() {
    var I = inds();
    var tot = {}, sub = {};
    I.forEach(function (i) { tot[i.key] = 0; });
    Object.keys(S.data.inc).forEach(function (dk) {
      var dv = S.geo.districts[dk] ? S.geo.districts[dk][1] : '';
      I.forEach(function (i) { var v = S.data.inc[dk][i.key] || 0; tot[i.key] += v; sub[dv + ':' + i.key] = (sub[dv + ':' + i.key] || 0) + v; });
    });
    I.forEach(function (i) { tot[i.key] += S.data.unk[i.key] || 0; });
    mount.querySelectorAll('[data-tot]').forEach(function (td) { td.textContent = bn(tot[td.getAttribute('data-tot')] || 0); });
    mount.querySelectorAll('[data-sub]').forEach(function (td) { var v = sub[td.getAttribute('data-sub')] || 0; td.textContent = v ? bn(v) : '–'; });
  }
  function applyFilter() {
    var q = filter.trim();
    mount.querySelectorAll('.dca-row').forEach(function (tr) {
      var name = S.geo.districts[tr.getAttribute('data-row')][0];
      tr.hidden = q ? name.indexOf(q) === -1 : !!collapsed[tr.getAttribute('data-of')];
    });
  }
  function setNum(input) {
    var raw = en(input.value).replace(/[^0-9]/g, '');
    var n = raw ? Math.min(99999, parseInt(raw, 10)) : 0;
    input.value = n ? bn(n) : '';
    var dk = input.getAttribute('data-d'), k = input.getAttribute('data-i');
    if (dk === '_unk') { if (n) S.data.unk[k] = n; else delete S.data.unk[k]; }
    else {
      S.data.inc[dk] = S.data.inc[dk] || {};
      if (n) S.data.inc[dk][k] = n; else delete S.data.inc[dk][k];
    }
    input.classList.toggle('has-v', !!n);
    markDirty(); totals();
  }

  /* economy */
  function ecoHtml() {
    var by = {};
    ecos().forEach(function (e) { (by[e.group] = by[e.group] || []).push(e); });
    var out = '<div class="dca-gtools"><button type="button" class="button" data-copyeco>খালি ঘরে আগের হিসাবের মান বসান' + (S.prevDay ? ' (' + bn(S.prevDay) + ')' : '') + '</button><span class="dca-hint">মান না বদলালেও প্রতিদিন দিন, না হলে পাতায় "আগের দিনের মান" লেখা দেখাবে। কমা ছাড়া লিখুন।</span></div>';
    Object.keys(S.ecoGroups).forEach(function (g) {
      if (!by[g]) return;
      out += '<h3 class="dca-h3">' + esc(S.ecoGroups[g]) + (g === 'currency' ? ' <small>(১ একক = কত টাকা)</small>' : '') + '</h3><div class="dca-eco">' + by[g].map(function (e) {
        var v = S.data.eco[e.key], pv = S.prevEco[e.key];
        return '<label class="dca-eco__f"><span class="dca-eco__l">' + (e.code ? '<b>' + esc(e.code) + '</b> ' : '') + esc(e.label) + '</span>' +
          '<span class="dca-eco__in"><input inputmode="decimal" data-eco="' + esc(e.key) + '" value="' + (v === undefined || v === null ? '' : bn(v)) + '">' + (e.unit ? '<em>' + esc(e.unit) + '</em>' : '') + '</span>' +
          '<span class="dca-eco__p">' + (pv !== undefined ? 'আগের হিসাব: ' + bn(pv) : 'আগের হিসাব নেই') + '</span></label>';
      }).join('') + '</div>';
    });
    return out;
  }

  /* highlights + summary */
  function distOptions(sel) {
    return '<option value="">— জেলা —</option>' + Object.keys(S.geo.divisions).map(function (dv) {
      return '<optgroup label="' + esc(S.geo.divisions[dv]) + '">' + distsOf(dv).map(function (dk) { return '<option value="' + dk + '"' + (dk === sel ? ' selected' : '') + '>' + esc(S.geo.districts[dk][0]) + '</option>'; }).join('') + '</optgroup>';
    }).join('');
  }
  function hlHtml() {
    var rows = S.data.hl.map(function (h, i) {
      return '<div class="dca-hl" data-hl="' + i + '"><span class="dca-hl__n">' + bn(i + 1) + '</span>' +
        '<input class="dca-hl__t" data-hlf="t" maxlength="240" value="' + esc(h.t) + '" placeholder="এক লাইনে ঘটনা">' +
        '<select data-hlf="d">' + distOptions(h.d) + '</select>' +
        '<input class="dca-hl__u" data-hlf="u" type="url" value="' + esc(h.u) + '" placeholder="খবরের লিংক (ঐচ্ছিক)">' +
        '<button type="button" class="button-link dca-del" data-hldel="' + i + '" aria-label="মুছুন">×</button></div>';
    }).join('');
    return '<h3 class="dca-h3">দিনের সারকথা <small>(পাতার ওপরে দেখায়)</small></h3><textarea class="large-text" rows="3" maxlength="600" data-f="summary" placeholder="যেমন: গতকাল সড়কে প্রাণহানি আগের দিনের চেয়ে বেশি; ঢাকা ও চট্টগ্রামে ছিনতাই বেড়েছে।">' + esc(S.data.summary) + '</textarea>' +
      '<h3 class="dca-h3">উল্লেখযোগ্য ঘটনা <small>(সর্বোচ্চ ১৫টি; "সেদিনের খবর" ট্যাব থেকে এক ক্লিকে যোগ করা যায়)</small></h3><div data-hls>' + rows + '</div>' +
      (S.data.hl.length < 15 ? '<p><button type="button" class="button" data-hladd>+ ঘটনা যোগ করুন</button></p>' : '') +
      '<h3 class="dca-h3">উৎস ও ভেতরের নোট <small>(পাঠক দেখেন না)</small></h3><textarea class="large-text" rows="2" maxlength="600" data-f="sources" placeholder="কোন থানা, হাসপাতাল, প্রশাসন বা প্রতিবেদন থেকে সংখ্যা নেওয়া">' + esc(S.data.sources) + '</textarea>';
  }

  /* news helper */
  function newsHtml() {
    if (!S.news.length) return '<p class="dca-hint">এই দিনে কোনো প্রকাশিত খবর পাওয়া যায়নি।</p>';
    var im = {}; S.indicators.forEach(function (i) { im[i.key] = i; });
    return '<p class="dca-hint">এই দিনে প্রকাশিত খবর। শিরোনামের শব্দ দেখে সম্ভাব্য সূচক চিহ্নিত করা হয়েছে — এটা শুধু সাহায্য, সংখ্যা নিজে যাচাই করে দিন।</p><ul class="dca-news">' + S.news.map(function (n, i) {
      var used = S.data.hl.some(function (h) { return h.u === n.url; });
      return '<li><span class="dca-news__t">' + esc(bn(n.time)) + '</span><a href="' + esc(n.url) + '" target="_blank" rel="noopener">' + esc(n.title) + '</a>' +
        (n.d ? '<span class="dca-chip">' + esc(S.geo.districts[n.d][0]) + '</span>' : '') +
        n.tags.map(function (t) { return im[t] ? '<span class="dca-chip dca-t-' + esc(im[t].tone) + '">' + ico(im[t].icon) + esc(im[t].short) + '</span>' : ''; }).join('') +
        '<button type="button" class="button button-small" data-newsadd="' + i + '"' + (used || S.data.hl.length >= 15 ? ' disabled' : '') + '>' + (used ? 'যোগ হয়েছে' : 'উল্লেখযোগ্যে যোগ') + '</button></li>';
    }).join('') + '</ul>';
  }

  /* ---------------------------------------------------------------- events */
  function markDirty() { dirty = true; mount.classList.add('is-dirty'); }
  function toast(msg, bad) {
    document.querySelectorAll('.dca-toast').forEach(function (x) { x.remove(); });
    var t = document.createElement('div');
    t.className = 'dca-toast' + (bad ? ' is-bad' : '');
    t.setAttribute('role', 'status');
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(function () { t.remove(); }, 4200);
  }
  function save(status) {
    if (status === 'draft' && S.exists && S.status === 'publish' && !window.confirm('এই দিনটি এখন প্রকাশিত। খসড়ায় ফেরালে পাঠক আর দেখবেন না। ফেরাবেন?')) return;
    if (status === 'publish') {
      var total = 0;
      Object.keys(S.data.inc).forEach(function (dk) { Object.keys(S.data.inc[dk]).forEach(function (k) { total += S.data.inc[dk][k]; }); });
      if (!total && !window.confirm('কোনো জেলায় একটি সংখ্যাও নেই। তবুও প্রকাশ করবেন?')) return;
    }
    mount.classList.add('is-busy');
    api('POST', {}, { date: S.day, status: status, data: S.data }).then(function (s) {
      S = norm(s); dirty = false; mount.classList.remove('is-busy', 'is-dirty');
      render();
      toast(status === 'publish' ? (S.day >= S.today ? 'প্রকাশ নির্ধারিত — রাত ১২টায় পাতায় দেখা যাবে।' : 'প্রকাশিত হয়েছে।') : 'খসড়া সংরক্ষণ হয়েছে।');
    }).catch(function (e) { mount.classList.remove('is-busy'); toast('সংরক্ষণ হয়নি: ' + e.message, true); });
  }

  mount.addEventListener('click', function (e) {
    var b = e.target.closest('button');
    if (!b) return;
    if (b.hasAttribute('data-step')) load(shift(S.day, +b.getAttribute('data-step')));
    else if (b.hasAttribute('data-go')) load(b.getAttribute('data-go'));
    else if (b.hasAttribute('data-save')) save(b.getAttribute('data-save'));
    else if (b.hasAttribute('data-tab')) { tab = b.getAttribute('data-tab'); renderPanel(); }
    else if (b.hasAttribute('data-toggle')) { var dv = b.getAttribute('data-toggle'); collapsed[dv] = !collapsed[dv]; b.setAttribute('aria-expanded', !collapsed[dv]); b.textContent = (collapsed[dv] ? '▸ ' : '▾ ') + S.geo.divisions[dv] + ' বিভাগ'; applyFilter(); }
    else if (b.hasAttribute('data-collapse')) { var all = b.getAttribute('data-collapse') === 'all'; Object.keys(S.geo.divisions).forEach(function (d) { collapsed[d] = all; }); renderPanel(); }
    else if (b.hasAttribute('data-copyeco')) {
      var n = 0;
      ecos().forEach(function (x) { if ((S.data.eco[x.key] === undefined || S.data.eco[x.key] === null) && S.prevEco[x.key] !== undefined) { S.data.eco[x.key] = S.prevEco[x.key]; n++; } });
      if (n) markDirty();
      renderPanel(); toast(bn(n) + 'টি ঘরে আগের মান বসানো হয়েছে।');
    }
    else if (b.hasAttribute('data-hladd')) { S.data.hl.push({ t: '', u: '', d: '' }); markDirty(); renderPanel(); var ins = mount.querySelectorAll('.dca-hl__t'); if (ins.length) ins[ins.length - 1].focus(); }
    else if (b.hasAttribute('data-hldel')) { S.data.hl.splice(+b.getAttribute('data-hldel'), 1); markDirty(); renderPanel(); }
    else if (b.hasAttribute('data-newsadd')) {
      var it = S.news[+b.getAttribute('data-newsadd')];
      S.data.hl.push({ t: it.title, u: it.url, d: it.d || '' }); markDirty(); renderPanel(); toast('উল্লেখযোগ্য ঘটনায় যোগ হয়েছে।');
    }
  });
  mount.addEventListener('change', function (e) {
    var t = e.target;
    if (t.matches('[data-date]') && t.value) load(t.value);
    else if (t.matches('.dca-n')) setNum(t);
    else if (t.matches('[data-hlf]')) { var i = +t.closest('[data-hl]').getAttribute('data-hl'); S.data.hl[i][t.getAttribute('data-hlf')] = t.value; markDirty(); }
  });
  mount.addEventListener('input', function (e) {
    var t = e.target;
    if (t.matches('[data-filter]')) { filter = t.value; applyFilter(); }
    else if (t.matches('[data-note]')) { var dk = t.getAttribute('data-note'); if (t.value.trim()) S.data.notes[dk] = t.value; else delete S.data.notes[dk]; markDirty(); }
    else if (t.matches('[data-eco]')) {
      var raw = en(t.value).replace(/[^0-9.\-]/g, '');
      if (raw === '' || isNaN(+raw)) delete S.data.eco[t.getAttribute('data-eco')]; else S.data.eco[t.getAttribute('data-eco')] = +raw;
      markDirty();
    }
    else if (t.matches('[data-f]')) { S.data[t.getAttribute('data-f')] = t.value; markDirty(); }
    else if (t.matches('[data-hlf]') && t.tagName === 'INPUT') { var i = +t.closest('[data-hl]').getAttribute('data-hl'); S.data.hl[i][t.getAttribute('data-hlf')] = t.value; markDirty(); }
    else if (t.matches('.dca-n')) markDirty();
  });
  mount.addEventListener('keydown', function (e) {
    var t = e.target;
    if (!t.matches || !t.matches('.dca-n')) return;
    if (e.key === 'Enter' || e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      setNum(t);
      var all = Array.prototype.filter.call(mount.querySelectorAll('.dca-n[data-i="' + t.getAttribute('data-i') + '"]'), function (x) { return !x.closest('tr').hidden; });
      var idx = all.indexOf(t) + (e.key === 'ArrowUp' ? -1 : 1);
      if (all[idx]) { all[idx].focus(); all[idx].select(); }
    }
  });
  mount.addEventListener('paste', function (e) {
    var t = e.target;
    if (!t.matches || !t.matches('.dca-n')) return;
    var text = (e.clipboardData || window.clipboardData).getData('text');
    if (!/[\t\n]/.test(text.trim())) return; // single value: normal paste
    e.preventDefault();
    var lines = text.replace(/\r/g, '').replace(/\n$/, '').split('\n').map(function (l) { return l.split('\t'); });
    var rows = Array.prototype.filter.call(mount.querySelectorAll('tr.dca-row, tr.dca-unk'), function (tr) { return !tr.hidden; });
    var r0 = rows.indexOf(t.closest('tr'));
    var cols = inds().map(function (i) { return i.key; });
    var c0 = cols.indexOf(t.getAttribute('data-i'));
    var n = 0;
    lines.forEach(function (cells, ri) {
      var tr = rows[r0 + ri]; if (!tr) return;
      cells.forEach(function (v, ci) {
        var k = cols[c0 + ci]; if (!k) return;
        var inp = tr.querySelector('.dca-n[data-i="' + k + '"]'); if (!inp) return;
        inp.value = v; setNum(inp); n++;
      });
    });
    toast(bn(n) + 'টি ঘরে পেস্ট হয়েছে।');
  });
  document.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 's' && S) { e.preventDefault(); var a = document.activeElement; if (a && a.matches && a.matches('.dca-n')) setNum(a); save(S.status === 'publish' ? 'publish' : 'draft'); }
  });
  window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });

  load(mount.getAttribute('data-date'));
})();
