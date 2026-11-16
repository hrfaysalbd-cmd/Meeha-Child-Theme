/*
 * দেশচিত্র — public page renderer for [meeha_deshchitro].
 * Reads the first payload from #meeha-dc-data, draws KPI tiles, the district map (window.MeehaBDMap), rankings,
 * division bars, the trend chart, the economy board, highlights and the full table; refetches
 * GET meeha/v1/deshchitro?range=&date= when the filter changes and keeps ?dr=&dd= in the address bar.
 */
(function () {
  'use strict';

  var root = document.getElementById('meeha-dc');
  var dataEl = document.getElementById('meeha-dc-data');
  if (!root || !dataEl) return;
  var boot;
  try { boot = JSON.parse(dataEl.textContent); } catch (e) { return; }
  var GEO = boot.geo;
  var MAP = window.MeehaBDMap;
  var ICON = window.MeehaDCIcon || function () { return ''; };
  var body = root.querySelector('[data-dc-body]');
  var whenEl = root.querySelector('[data-dc-when]');
  var form = root.querySelector('[data-dc-filter]');
  var dateIn = form.querySelector('input[type=date]');
  var REST = root.getAttribute('data-rest');

  var P = boot.p;
  var state = { layer: 'neg', district: '', sort: { key: 'name', dir: 1 } };
  var cache = {};

  /* ------------------------------------------------------------------ helpers */
  var BN = '০১২৩৪৫৬৭৮৯';
  function bn(v) { return String(v).replace(/[0-9]/g, function (d) { return BN[+d]; }); }
  function fmt(n, dec) {
    if (n === null || n === undefined || isNaN(n)) return '—';
    dec = dec || 0;
    var s = Number(n).toFixed(dec);
    var parts = s.split('.');
    // South-Asian grouping: 12,34,567
    var x = parts[0], neg = x.charAt(0) === '-';
    if (neg) x = x.slice(1);
    var last3 = x.slice(-3), rest = x.slice(0, -3);
    if (rest) last3 = ',' + last3;
    x = rest.replace(/\B(?=(\d{2})+(?!\d))/g, ',') + last3;
    return bn((neg ? '-' : '') + x + (parts[1] ? '.' + parts[1] : ''));
  }
  function esc(s) {
    return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  var MONTHS = ['জানুয়ারি', 'ফেব্রুয়ারি', 'মার্চ', 'এপ্রিল', 'মে', 'জুন', 'জুলাই', 'আগস্ট', 'সেপ্টেম্বর', 'অক্টোবর', 'নভেম্বর', 'ডিসেম্বর'];
  var WD = ['রবি', 'সোম', 'মঙ্গল', 'বুধ', 'বৃহস্পতি', 'শুক্র', 'শনি'];
  function d8(ymd) { var p = ymd.split('-'); return new Date(Date.UTC(+p[0], +p[1] - 1, +p[2])); }
  function shortDate(ymd) { var d = d8(ymd); return bn(d.getUTCDate()) + ' ' + MONTHS[d.getUTCMonth()]; }
  function wday(ymd) { return WD[d8(ymd).getUTCDay()] + 'বার'; }
  function shift(ymd, n) { var d = d8(ymd); d.setUTCDate(d.getUTCDate() + n); return d.toISOString().slice(0, 10); }
  function clockBn(mysql) {
    if (!mysql) return '';
    var t = mysql.split(' ')[1] || '';
    var h = +t.slice(0, 2), m = t.slice(3, 5);
    var part = h < 4 ? 'রাত' : h < 6 ? 'ভোর' : h < 12 ? 'সকাল' : h < 15 ? 'দুপুর' : h < 18 ? 'বিকেল' : h < 20 ? 'সন্ধ্যা' : 'রাত';
    var h12 = h % 12 || 12;
    return part + ' ' + bn(h12) + ':' + bn(m);
  }

  function indMap() { var m = {}; P.indicators.forEach(function (i) { m[i.key] = i; }); return m; }
  function negKeys() { return P.indicators.filter(function (i) { return i.tone === 'bad' || i.tone === 'warn'; }).map(function (i) { return i.key; }); }
  function layerInfo() {
    if (state.layer === 'neg') return { key: 'neg', label: 'সব নেতিবাচক ঘটনা', short: 'নেতিবাচক ঘটনা', tone: 'bad', icon: 'alert' };
    return indMap()[state.layer] || { key: 'neg', label: 'সব নেতিবাচক ঘটনা', short: 'নেতিবাচক ঘটনা', tone: 'bad', icon: 'alert' };
  }
  function distVal(dk, layer) {
    var v = P.districts[dk] || {};
    if (layer === 'neg') return negKeys().reduce(function (s, k) { return s + (v[k] || 0); }, 0);
    return v[layer] || 0;
  }
  function totalVal(tot, layer) {
    if (layer === 'neg') return negKeys().reduce(function (s, k) { return s + (tot[k] || 0); }, 0);
    return tot[layer] || 0;
  }
  function seriesFor(layer) {
    var keys = layer === 'neg' ? negKeys() : [layer];
    return P.series.days.map(function (d, i) {
      var any = false, s = 0;
      keys.forEach(function (k) { var x = (P.series.v[k] || [])[i]; if (x !== null && x !== undefined) { any = true; s += x; } });
      return any ? s : null;
    });
  }
  function rangeWord() {
    return { day: 'আগের দিন', '7': 'আগের ৭ দিন', '30': 'আগের ৩০ দিন', month: 'আগের সমান সময়' }[P.range] || 'আগের সময়';
  }
  function periodWord() {
    var y = shift(P.today, -1);
    if (P.span === 1) return P.to === y ? 'গতকাল' : shortDate(P.to);
    return { '7': '৭ দিনে', '30': '৩০ দিনে', month: 'এই মাসে' }[P.range] || '';
  }

  /* levels: 0 = none, 1–3 by tertiles of the non-zero values */
  function breaks(vals) {
    var nz = vals.filter(function (v) { return v > 0; }).sort(function (a, b) { return a - b; });
    if (!nz.length) return { b1: 0, b2: 0, max: 0 };
    var q = function (p) { return nz[Math.min(nz.length - 1, Math.floor(p * (nz.length - 1) + 0.5))]; };
    return { b1: q(0.34), b2: q(0.67), max: nz[nz.length - 1] };
  }
  function level(v, br) { if (!v) return 0; if (v <= br.b1) return 1; if (v <= br.b2) return 2; return 3; }

  /* ------------------------------------------------------------------ render */
  function render() {
    if (whenEl) {
      whenEl.innerHTML = '<span class="dc-when__k">' + (P.span === 1 ? 'হিসাবের দিন' : 'হিসাবের সময়কাল') + '</span>' +
        '<strong class="dc-when__v">' + esc(P.label) + '</strong>' +
        (P.updated ? '<span class="dc-when__u"><span class="dc-live" aria-hidden="true"></span>হালনাগাদ ' + esc(shortDate(P.updated.slice(0, 10))) + ', ' + esc(clockBn(P.updated)) + '</span>' : '');
    }
    syncFilter();
    var html = '';
    html += notices();
    if (!P.covered) {
      html += emptyState();
      html += highlightsSection();
      body.innerHTML = html;
      bindEmpty();
      return;
    }
    html += summary();
    html += '<section class="dc-sec" aria-label="মূল সংখ্যা"><div class="dc-kpis">' + P.indicators.map(kpi).join('') + '</div></section>';
    html += mapSection();
    html += trendSection();
    html += economySection();
    html += highlightsSection();
    html += tableSection();
    body.innerHTML = html;
    drawMap();
    drawSide();
    drawTrend();
    drawTable();
    bindBody();
  }

  function notices() {
    var out = '';
    var y = shift(P.today, -1);
    if (!P.requested && P.range === 'day' && P.latest && P.latest < y) {
      out += '<p class="dc-note"><b>গতকালের (' + esc(shortDate(y)) + ') হিসাব প্রস্তুত হচ্ছে।</b> ততক্ষণ সর্বশেষ প্রকাশিত দিনের হিসাব দেখানো হচ্ছে।</p>';
    }
    if (P.span > 1 && P.covered && P.covered < P.span) {
      out += '<p class="dc-note">এই ' + bn(P.span) + ' দিনের মধ্যে ' + bn(P.covered) + ' দিনের হিসাব প্রকাশিত হয়েছে; মোট সংখ্যা সেই দিনগুলোর।</p>';
    }
    return out;
  }
  function emptyState() {
    var btn = P.latest && P.latest !== P.to ? '<button type="button" class="dc-btn" data-go="' + esc(P.latest) + '">সর্বশেষ হিসাব দেখুন (' + esc(shortDate(P.latest)) + ')</button>' : '';
    return '<div class="dc-emptycard">' + ICON('alert', 'dc-emptycard__i') +
      '<h3>' + (P.latest ? 'এই সময়ের হিসাব প্রকাশ হয়নি' : 'হিসাব শিগগিরই আসছে') + '</h3>' +
      '<p>' + (P.latest ? 'অন্য তারিখ বেছে নিন।' : 'প্রতিদিন রাত ১২টার পর আগের দিনের হিসাব এখানে প্রকাশ হবে।') + '</p>' + btn + '</div>';
  }
  function bindEmpty() {
    var b = body.querySelector('[data-go]');
    if (b) b.addEventListener('click', function () { load('day', b.getAttribute('data-go')); });
  }

  function summary() {
    var tot = P.totals, im = indMap();
    var neg = totalVal(tot, 'neg');
    var deaths = ['khun', 'sorok_nihoto', 'attohotya', 'dube'].reduce(function (s, k) { return s + (im[k] ? (tot[k] || 0) : 0); }, 0);
    var top = topDistricts('neg', 1)[0];
    var lead = '<p class="dc-lead">' + esc(periodWord()) + ' সারা দেশে <b>' + fmt(neg) + 'টি</b> নেতিবাচক ঘটনার হিসাব মিলেছে' +
      (deaths ? '; অস্বাভাবিক মৃত্যু (খুন, সড়ক, আত্মহত্যা, ডুবে) <b>' + fmt(deaths) + ' জন</b>' : '') +
      (top && top.v ? '। সবচেয়ে বেশি ঘটনা <b>' + esc(GEO.districts[top.k][0]) + '</b> জেলায় (' + fmt(top.v) + 'টি)' : '') + '।</p>';
    var ed = P.summary ? '<blockquote class="dc-editor"><span class="dc-editor__k">দিনের সারকথা</span>' + esc(P.summary).replace(/\n/g, '<br>') + '</blockquote>' : '';
    return '<section class="dc-sec dc-summary">' + lead + ed + '</section>';
  }

  function delta(now, then, tone, hasPrev) {
    if (!hasPrev) return '<span class="dc-delta dc-delta--na">' + esc(rangeWord()) + 'র হিসাব নেই</span>';
    var d = now - then;
    if (!d) return '<span class="dc-delta dc-delta--eq">' + esc(rangeWord()) + 'র সমান</span>';
    var up = d > 0;
    var good = tone === 'good' ? up : tone === 'info' ? null : !up;
    var cls = good === null ? 'dc-delta--nu' : good ? 'dc-delta--good' : 'dc-delta--bad';
    var pct = then ? ' (' + bn(Math.round(Math.abs(d) / then * 100)) + '%)' : '';
    return '<span class="dc-delta ' + cls + '"><span aria-hidden="true">' + (up ? '▲' : '▼') + '</span> ' + fmt(Math.abs(d)) + pct + ' <span class="dc-delta__w">' + (up ? 'বেশি' : 'কম') + ', ' + esc(rangeWord()) + 'র চেয়ে</span></span>';
  }

  function spark(vals, tone) {
    var w = 120, h = 30, n = vals.length;
    var nums = vals.filter(function (v) { return v !== null; });
    if (nums.length < 2) return '';
    var max = Math.max.apply(null, nums.concat([1]));
    var pts = [], seg = '';
    vals.forEach(function (v, i) {
      var x = (i / (n - 1)) * (w - 4) + 2;
      if (v === null) { seg = ''; return; }
      var y = h - 3 - (v / max) * (h - 8);
      pts.push([x, y]);
      seg += (seg ? 'L' : 'M') + x.toFixed(1) + ',' + y.toFixed(1);
      pts[pts.length - 1].seg = seg;
    });
    var path = '', cur = '';
    vals.forEach(function (v, i) {
      var x = (i / (n - 1)) * (w - 4) + 2;
      if (v === null) { if (cur) path += cur; cur = ''; return; }
      var y = h - 3 - (v / max) * (h - 8);
      cur += (cur ? 'L' : 'M') + x.toFixed(1) + ',' + y.toFixed(1);
    });
    path += cur;
    var last = pts[pts.length - 1];
    return '<svg class="dc-spark dc-t-' + tone + '" viewBox="0 0 ' + w + ' ' + h + '" preserveAspectRatio="none" aria-hidden="true"><path d="' + path + '"/><circle cx="' + last[0].toFixed(1) + '" cy="' + last[1].toFixed(1) + '" r="2.6"/></svg>';
  }

  function kpi(i) {
    var now = P.totals[i.key] || 0, then = P.prevTotals[i.key] || 0;
    var vals = (P.series.v[i.key] || []).slice(-14);
    return '<button type="button" class="dc-kpi dc-t-' + esc(i.tone) + (state.layer === i.key ? ' is-on' : '') + '" data-layer="' + esc(i.key) + '" aria-pressed="' + (state.layer === i.key) + '">' +
      '<span class="dc-kpi__top"><span class="dc-kpi__i">' + ICON(i.icon) + '</span><span class="dc-kpi__l">' + esc(i.label) + '</span></span>' +
      '<span class="dc-kpi__n">' + fmt(now) + '</span>' +
      delta(now, then, i.tone, P.prevCovered > 0) +
      spark(vals, i.tone) +
      '</button>';
  }

  /* -------------------------------------------------------------- map section */
  function mapSection() {
    var chips = '<button type="button" class="dc-chip" data-layer="neg" aria-pressed="' + (state.layer === 'neg') + '">' + ICON('alert') + 'সব নেতিবাচক</button>' +
      P.indicators.map(function (i) {
        return '<button type="button" class="dc-chip dc-t-' + esc(i.tone) + '" data-layer="' + esc(i.key) + '" aria-pressed="' + (state.layer === i.key) + '">' + ICON(i.icon) + esc(i.short) + '</button>';
      }).join('');
    var opts = '<option value="">জেলা বেছে নিন…</option>' + Object.keys(GEO.divisions).map(function (dv) {
      return '<optgroup label="' + esc(GEO.divisions[dv]) + ' বিভাগ">' + Object.keys(GEO.districts).filter(function (k) { return GEO.districts[k][1] === dv; }).map(function (k) {
        return '<option value="' + k + '"' + (state.district === k ? ' selected' : '') + '>' + esc(GEO.districts[k][0]) + '</option>';
      }).join('') + '</optgroup>';
    }).join('');
    return '<section class="dc-sec dc-mapsec" aria-labelledby="dc-map-h">' +
      '<div class="dc-sec__head"><h3 class="dc-h" id="dc-map-h">জেলাভিত্তিক মানচিত্র</h3><p class="dc-sec__sub">স্তর বেছে নিন; জেলার ওপর কার্সার রাখুন বা ট্যাপ করুন।</p></div>' +
      '<div class="dc-chips" role="group" aria-label="মানচিত্রের স্তর">' + chips + '</div>' +
      '<div class="dc-mapgrid">' +
        '<div class="dc-mapcard"><div class="dc-map" data-map></div><div class="dc-tip" data-tip hidden></div><div class="dc-legend" data-legend></div></div>' +
        '<aside class="dc-side"><label class="dc-pick"><span>জেলা</span><select data-pick>' + opts + '</select></label><div data-side></div></aside>' +
      '</div></section>';
  }

  var TONE_RAMP = { bad: 'neg', warn: 'neg', good: 'good', info: 'info' };
  function rampOf(tone) { return TONE_RAMP[tone] || 'neg'; }
  var LEVEL_WORDS = { neg: ['কোনো ঘটনা নেই', 'কম', 'মাঝারি', 'বেশি'], good: ['নেই', 'কিছু', 'ভালো', 'অনেক'], info: ['নেই', 'কম', 'মাঝারি', 'বেশি'] };

  function drawMap() {
    var box = body.querySelector('[data-map]');
    if (!box || !MAP) return;
    var L = layerInfo(), ramp = rampOf(L.tone);
    var vals = {}, all = [];
    MAP.d.forEach(function (d) { var v = distVal(d.k, L.key); vals[d.k] = v; all.push(v); });
    var br = breaks(all);
    var paths = '', marks = '';
    MAP.d.forEach(function (d) {
      var v = vals[d.k], lv = level(v, br);
      paths += '<path class="dc-d dc-' + ramp + '-' + lv + (state.district === d.k ? ' is-sel' : '') + '" data-k="' + d.k + '" d="' + d.p + '"/>';
    });
    // symbols, small first so big ones sit on top
    var ranked = MAP.d.filter(function (d) { return vals[d.k] > 0; }).sort(function (a, b) { return vals[b.k] - vals[a.k]; });
    var SYM_MAX = 16, cut = ranked.length > SYM_MAX;
    ranked.slice(0, SYM_MAX).reverse().forEach(function (d) {
      var v = vals[d.k];
      var r = 7 + 9 * Math.sqrt(v / (br.max || 1));
      marks += '<g class="dc-sym dc-' + ramp + '-sym" data-k="' + d.k + '" transform="translate(' + d.c[0] + ' ' + d.c[1] + ')"><circle r="' + r.toFixed(1) + '"/><text dy=".35em">' + bn(v) + '</text></g>';
    });
    box.innerHTML = '<svg viewBox="0 0 ' + MAP.w + ' ' + MAP.h + '" role="img" aria-label="বাংলাদেশের জেলাভিত্তিক মানচিত্র: ' + esc(L.label) + '">' +
      '<path class="dc-outline" d="' + MAP.out + '"/>' +
      '<g class="dc-ds">' + paths + '</g>' +
      '<path class="dc-divl" d="' + MAP.div + '"/>' +
      '<g class="dc-syms">' + marks + '</g></svg>';
    // legend
    var words = LEVEL_WORDS[ramp];
    var ranges = [
      '০',
      br.b1 ? (br.b1 > 1 ? '১–' + bn(br.b1) : '১') : '',
      br.b2 > br.b1 ? (br.b2 > br.b1 + 1 ? bn(br.b1 + 1) + '–' + bn(br.b2) : bn(br.b2)) : '',
      br.max > br.b2 ? (br.max > br.b2 + 1 ? bn(br.b2 + 1) + '–' + bn(br.max) : bn(br.max)) : ''
    ];
    var leg = body.querySelector('[data-legend]');
    leg.innerHTML = '<span class="dc-legend__t">' + ICON(L.icon) + esc(L.label) + '</span>' + [0, 1, 2, 3].map(function (lv) {
      if (lv && !ranges[lv]) return '';
      return '<span class="dc-legend__i"><i class="dc-sw dc-' + ramp + '-' + lv + '"></i>' + esc(words[lv]) + (lv ? ' <small>(' + ranges[lv] + ')</small>' : '') + '</span>';
    }).join('') + '<span class="dc-legend__i"><i class="dc-sw dc-sw--sym dc-' + ramp + '-sym"></i>বৃত্তে সংখ্যা' + (cut ? ' (শীর্ষ ' + bn(SYM_MAX) + ' জেলা; বাকিগুলো রঙে ও কার্সারে)' : '') + '</span>';
  }

  function tipHtml(k) {
    var g = GEO.districts[k];
    var v = P.districts[k] || {};
    var L = layerInfo();
    var rows = P.indicators.filter(function (i) { return v[i.key]; }).map(function (i) {
      return '<li class="dc-t-' + esc(i.tone) + (L.key === i.key ? ' is-on' : '') + '"><span class="dc-tip__i">' + ICON(i.icon) + '</span><b>' + fmt(v[i.key]) + '</b> ' + esc(i.short) + '</li>';
    }).join('');
    var note = P.notes && P.notes[k] ? '<p class="dc-tip__n">' + esc(P.notes[k]) + '</p>' : '';
    return '<p class="dc-tip__h"><b>' + esc(g[0]) + '</b> <span>' + esc(GEO.divisions[g[1]]) + ' বিভাগ</span></p>' +
      '<p class="dc-tip__big">' + fmt(distVal(k, L.key)) + ' <span>' + esc(L.short) + '</span></p>' +
      (rows ? '<ul class="dc-tip__l">' + rows + '</ul>' : '<p class="dc-tip__calm">কোনো ঘটনার হিসাব নেই</p>') + note;
  }

  function topDistricts(layer, n) {
    return Object.keys(GEO.districts).map(function (k) { return { k: k, v: distVal(k, layer) }; })
      .filter(function (x) { return x.v > 0; })
      .sort(function (a, b) { return b.v - a.v || GEO.districts[a.k][0].localeCompare(GEO.districts[b.k][0], 'bn'); })
      .slice(0, n);
  }

  function drawSide() {
    var side = body.querySelector('[data-side]');
    if (!side) return;
    var L = layerInfo(), ramp = rampOf(L.tone);
    var html = '';
    if (state.district) {
      var k = state.district, g = GEO.districts[k], v = P.districts[k] || {};
      html += '<div class="dc-dcard"><div class="dc-dcard__h"><h4>' + esc(g[0]) + '</h4><span>' + esc(GEO.divisions[g[1]]) + ' বিভাগ</span><button type="button" class="dc-x" data-clear aria-label="বন্ধ">×</button></div>' +
        '<ul class="dc-dcard__l">' + P.indicators.map(function (i) {
          return '<li class="dc-t-' + esc(i.tone) + (v[i.key] ? '' : ' is-zero') + '"><span class="dc-dcard__i">' + ICON(i.icon) + '</span><span>' + esc(i.label) + '</span><b>' + fmt(v[i.key] || 0) + '</b></li>';
        }).join('') + '</ul>' +
        (P.notes && P.notes[k] ? '<p class="dc-dcard__n">' + esc(P.notes[k]) + '</p>' : '') + '</div>';
    }
    var top = topDistricts(L.key, 8);
    var max = top.length ? top[0].v : 1;
    html += '<div class="dc-rank"><h4 class="dc-h4">' + esc(L.short) + ': শীর্ষ জেলা</h4>' +
      (top.length ? '<ol>' + top.map(function (x) {
        return '<li><button type="button" data-k="' + x.k + '"><span class="dc-rank__n">' + esc(GEO.districts[x.k][0]) + '</span><span class="dc-bar"><i class="dc-' + ramp + '-bar" style="width:' + Math.max(4, x.v / max * 100).toFixed(1) + '%"></i></span><b>' + fmt(x.v) + '</b></button></li>';
      }).join('') + '</ol>' : '<p class="dc-calm">' + ICON('star') + 'কোনো জেলায় এই ঘটনার হিসাব নেই।</p>') + '</div>';
    // divisions
    var dv = Object.keys(GEO.divisions).map(function (d) {
      var s = 0;
      Object.keys(GEO.districts).forEach(function (k) { if (GEO.districts[k][1] === d) s += distVal(k, L.key); });
      return { d: d, v: s };
    }).sort(function (a, b) { return b.v - a.v; });
    var dmax = Math.max(1, dv[0].v);
    html += '<div class="dc-rank dc-rank--div"><h4 class="dc-h4">বিভাগ অনুযায়ী</h4><ol>' + dv.map(function (x) {
      return '<li><span class="dc-rank__s"><span class="dc-rank__n">' + esc(GEO.divisions[x.d]) + '</span><span class="dc-bar"><i class="dc-' + ramp + '-bar" style="width:' + (x.v ? Math.max(3, x.v / dmax * 100) : 0).toFixed(1) + '%"></i></span><b>' + fmt(x.v) + '</b></span></li>';
    }).join('') + '</ol>';
    var unk = L.key === 'neg' ? negKeys().reduce(function (s, k) { return s + (P.unknown[k] || 0); }, 0) : (P.unknown[L.key] || 0);
    if (unk) html += '<p class="dc-fine">এ ছাড়া জেলা উল্লেখ নেই এমন ' + fmt(unk) + 'টি ঘটনা জাতীয় মোটে ধরা আছে।</p>';
    html += '</div>';
    side.innerHTML = html;
  }

  /* -------------------------------------------------------------- trend */
  function trendSection() {
    return '<section class="dc-sec" aria-labelledby="dc-tr-h"><div class="dc-sec__head"><h3 class="dc-h" id="dc-tr-h">দিনভিত্তিক প্রবণতা</h3>' +
      '<p class="dc-sec__sub" data-trend-sub></p></div><div class="dc-trend"><div data-trend></div><div class="dc-tip dc-tip--sm" data-ttip hidden></div></div></section>';
  }
  function drawTrend() {
    var box = body.querySelector('[data-trend]');
    if (!box) return;
    var L = layerInfo(), ramp = rampOf(L.tone);
    var vals = seriesFor(L.key), days = P.series.days, n = vals.length;
    body.querySelector('[data-trend-sub]').textContent = L.label + ' — শেষ ' + bn(n) + ' দিন। গাঢ় দাগ = বেছে নেওয়া সময়কাল।';
    var W = Math.max(320, Math.min(1100, Math.round(box.clientWidth || 720))), H = W < 500 ? 200 : 220, pl = 34, pr = 8, pt = 12, pb = 28;
    var max = Math.max.apply(null, vals.map(function (v) { return v || 0; }).concat([1]));
    var nice = Math.pow(10, Math.floor(Math.log10(max)));
    var step = [1, 2, 5, 10].map(function (m) { return m * nice; }).filter(function (s) { return max / s <= 5; })[0] || nice * 10;
    var top = Math.ceil(max / step) * step;
    var bw = (W - pl - pr) / n;
    var grid = '';
    for (var g = 0; g <= top; g += step) {
      var y = pt + (H - pt - pb) * (1 - g / top);
      grid += '<line x1="' + pl + '" x2="' + (W - pr) + '" y1="' + y.toFixed(1) + '" y2="' + y.toFixed(1) + '"/><text x="' + (pl - 6) + '" y="' + (y + 4).toFixed(1) + '" text-anchor="end">' + bn(g) + '</text>';
    }
    var bars = '', xl = '';
    var every = Math.ceil(n / Math.max(3, Math.floor(W / 90)));
    vals.forEach(function (v, i) {
      var x = pl + i * bw + 1, w = Math.max(2, bw - 2);
      var inP = days[i] >= P.from && days[i] <= P.to;
      if (v === null) {
        bars += '<rect class="dc-tr-na" x="' + x.toFixed(1) + '" y="' + (H - pb - 3) + '" width="' + w.toFixed(1) + '" height="3" rx="1"/>';
      } else {
        var h = (H - pt - pb) * (v / top), y0 = H - pb - h;
        bars += '<path class="dc-' + ramp + '-bar' + (inP ? '' : ' is-dim') + '" d="' + roundTop(x, y0, w, Math.max(h, v ? 2 : 0)) + '"/>';
      }
      bars += '<rect class="dc-tr-hit" data-i="' + i + '" x="' + (pl + i * bw).toFixed(1) + '" y="' + pt + '" width="' + bw.toFixed(1) + '" height="' + (H - pt) + '"/>';
      if ((n - 1 - i) % every === 0) xl += '<text x="' + (i === n - 1 ? W - pr : pl + i * bw + bw / 2).toFixed(1) + '" y="' + (H - 8) + '" text-anchor="' + (i === n - 1 ? 'end' : 'middle') + '">' + esc(shortDate(days[i])) + '</text>';
    });
    box.innerHTML = '<svg class="dc-trsvg" viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="' + esc(L.label) + ': দিনভিত্তিক সংখ্যা"><g class="dc-grid">' + grid + '</g><g>' + bars + '</g><g class="dc-xl">' + xl + '</g></svg>';
    var tip = body.querySelector('[data-ttip]');
    var svg = box.querySelector('svg');
    function show(e) {
      var t = e.target.closest('.dc-tr-hit');
      if (!t) { tip.hidden = true; return; }
      var i = +t.getAttribute('data-i');
      tip.innerHTML = '<b>' + (vals[i] === null ? 'হিসাব নেই' : fmt(vals[i])) + '</b> <span>' + esc(L.short) + '</span><br><small>' + esc(shortDate(days[i]) + ', ' + wday(days[i])) + '</small>';
      tip.hidden = false;
      var r = box.getBoundingClientRect(), tr = t.getBoundingClientRect();
      var x = tr.left - r.left + tr.width / 2;
      tip.style.left = Math.min(Math.max(x, 60), r.width - 60) + 'px';
      tip.style.top = '0px';
    }
    svg.addEventListener('pointermove', show);
    svg.addEventListener('pointerdown', show);
    svg.addEventListener('pointerleave', function () { tip.hidden = true; });
  }
  function roundTop(x, y, w, h) {
    var r = Math.min(4, w / 2, h);
    return 'M' + x.toFixed(1) + ',' + (y + h).toFixed(1) + 'V' + (y + r).toFixed(1) + 'Q' + x.toFixed(1) + ',' + y.toFixed(1) + ' ' + (x + r).toFixed(1) + ',' + y.toFixed(1) +
      'H' + (x + w - r).toFixed(1) + 'Q' + (x + w).toFixed(1) + ',' + y.toFixed(1) + ' ' + (x + w).toFixed(1) + ',' + (y + r).toFixed(1) + 'V' + (y + h).toFixed(1) + 'Z';
  }

  /* -------------------------------------------------------------- economy */
  function ecoDelta(e) {
    if (e.value === null || e.prev === null || e.prev === undefined) return '<span class="dc-delta dc-delta--na">আগের মান নেই</span>';
    var d = e.value - e.prev;
    if (Math.abs(d) < Math.pow(10, -(e.dec || 0)) / 2) return '<span class="dc-delta dc-delta--eq">অপরিবর্তিত</span>';
    var up = d > 0;
    var good = e.good === 'up' ? up : e.good === 'down' ? !up : null;
    var cls = good === null ? 'dc-delta--nu' : good ? 'dc-delta--good' : 'dc-delta--bad';
    var pct = e.prev ? ' (' + bn((Math.abs(d) / e.prev * 100).toFixed(2)) + '%)' : '';
    return '<span class="dc-delta ' + cls + '"><span aria-hidden="true">' + (up ? '▲' : '▼') + '</span> ' + fmt(Math.abs(d), e.dec) + pct + '</span>';
  }
  function ecoVal(e) {
    if (e.value === null) return '<span class="dc-eco__v dc-eco__v--na">—</span>';
    var u = e.unit || '';
    var pre = u === '৳' ? '৳' : '';
    var post = u && u !== '৳' ? ' <small>' + esc(u) + '</small>' : '';
    return '<span class="dc-eco__v">' + pre + fmt(e.value, e.dec) + post + '</span>';
  }
  function economySection() {
    var groups = {};
    P.economy.forEach(function (e) { (groups[e.group] = groups[e.group] || []).push(e); });
    if (!P.economy.some(function (e) { return e.value !== null; })) return '';
    var html = '<section class="dc-sec dc-eco" aria-labelledby="dc-eco-h"><div class="dc-sec__head"><h3 class="dc-h" id="dc-eco-h">অর্থনীতি ও বাজার</h3><p class="dc-sec__sub">' + esc(shortDate(P.to)) + ' পর্যন্ত সর্বশেষ মান; পরিবর্তন আগের হিসাবের তুলনায়।</p></div>';
    Object.keys(P.ecoGroups).forEach(function (g) {
      var rows = (groups[g] || []).filter(function (e) { return e.value !== null; });
      if (!rows.length) return;
      if (g === 'currency') {
        html += '<h4 class="dc-h4">' + esc(P.ecoGroups[g]) + ' <small>(১ একক = টাকা)</small></h4><div class="dc-fx">' + rows.map(function (e) {
          return '<div class="dc-fx__c"><span class="dc-fx__code">' + esc(e.code || e.key.toUpperCase()) + '</span><span class="dc-fx__n">' + esc(e.label) + '</span>' + ecoVal(e) + ecoDelta(e) + staleNote(e) + '</div>';
        }).join('') + '</div>';
      } else {
        html += '<h4 class="dc-h4">' + esc(P.ecoGroups[g]) + '</h4><ul class="dc-ecol">' + rows.map(function (e) {
          return '<li><span class="dc-ecol__n">' + esc(e.label) + staleNote(e) + '</span>' + spark(e.spark.slice(-14), e.good === 'up' ? 'info' : e.good === 'down' ? 'warn' : 'info') + ecoVal(e) + ecoDelta(e) + '</li>';
        }).join('') + '</ul>';
      }
    });
    return html + '</section>';
  }
  function staleNote(e) { return e.stale && e.day ? ' <small class="dc-stale">(' + esc(shortDate(e.day)) + '-এর মান)</small>' : ''; }

  /* -------------------------------------------------------------- highlights */
  function highlightsSection() {
    var own = P.meeha || [];
    if (!P.highlights.length && !own.length) return '';
    var im = indMap();
    var html = '<section class="dc-sec" aria-labelledby="dc-hl-h"><div class="dc-sec__head"><h3 class="dc-h" id="dc-hl-h">উল্লেখযোগ্য ঘটনা</h3>' +
      (own.length ? '<span class="dc-sec__note">মিহা নিউজের প্রতিবেদন থেকে</span>' : '') + '</div>';
    if (own.length) {
      html += '<ol class="dc-own">' + own.map(function (p) {
        var chips = Object.keys(p.c).filter(function (k) { return im[k]; }).map(function (k) {
          var i = im[k];
          return '<span class="dc-pc dc-t-' + esc(i.tone) + '">' + ICON(i.icon) + esc(i.short) + ' <b>' + fmt(p.c[k]) + '</b></span>';
        }).join('');
        var meta = [P.span > 1 ? shortDate(p.day) : '', p.dn, p.up].filter(Boolean).map(esc).join(' · ');
        return '<li class="dc-own__i"><a class="dc-own__a" href="' + esc(p.url) + '">' +
          (p.img ? '<img class="dc-own__img" src="' + esc(p.img) + '" alt="" loading="lazy" width="72" height="72">' : '<span class="dc-own__img dc-own__img--ph" aria-hidden="true">' + ICON('alert') + '</span>') +
          '<span class="dc-own__b"><span class="dc-own__m">' + meta + '</span><span class="dc-own__t">' + esc(p.title) + '</span><span class="dc-pcs">' + chips + '</span></span></a></li>';
      }).join('') + '</ol>';
    }
    if (P.highlights.length) {
      html += (own.length ? '<h4 class="dc-h4">ডেস্কের বাছাই</h4>' : '') + '<ol class="dc-hl">' + P.highlights.map(function (h) {
        var dist = h.d && GEO.districts[h.d] ? '<span class="dc-hl__d">' + esc(GEO.districts[h.d][0]) + '</span>' : '';
        var day = P.span > 1 ? '<span class="dc-hl__day">' + esc(shortDate(h.day)) + '</span>' : '';
        var t = esc(h.t);
        return '<li>' + day + dist + (h.u ? '<a href="' + esc(h.u) + '">' + t + ' <span aria-hidden="true">→</span></a>' : '<span>' + t + '</span>') + '</li>';
      }).join('') + '</ol>';
    }
    return html + '</section>';
  }

  /* -------------------------------------------------------------- table */
  function tableSection() {
    return '<section class="dc-sec"><details class="dc-tablewrap"><summary><span class="dc-h">সব জেলার পূর্ণ সারণি</span> <small>(' + bn(64) + ' জেলা × ' + bn(P.indicators.length) + ' সূচক)</small></summary><div class="dc-tscroll" data-table></div></details></section>';
  }
  function drawTable() {
    var box = body.querySelector('[data-table]');
    if (!box) return;
    var s = state.sort;
    var keys = Object.keys(GEO.districts);
    keys.sort(function (a, b) {
      if (s.key === 'name') {
        var da = Object.keys(GEO.divisions).indexOf(GEO.districts[a][1]), db = Object.keys(GEO.divisions).indexOf(GEO.districts[b][1]);
        return (da - db) * s.dir || 0;
      }
      return ((P.districts[b] || {})[s.key] || 0) - ((P.districts[a] || {})[s.key] || 0) || 0;
    });
    if (s.key !== 'name' && s.dir < 0) keys.reverse();
    var th = '<th scope="col"><button type="button" data-sort="name">জেলা (বিভাগ)</button></th>' + P.indicators.map(function (i) {
      return '<th scope="col" class="dc-t-' + esc(i.tone) + '"><button type="button" data-sort="' + esc(i.key) + '" title="' + esc(i.label) + '">' + ICON(i.icon) + '<span>' + esc(i.short) + '</span>' + (s.key === i.key ? (s.dir > 0 ? ' ↓' : ' ↑') : '') + '</button></th>';
    }).join('');
    var rows = keys.map(function (k) {
      var v = P.districts[k] || {};
      return '<tr><th scope="row">' + esc(GEO.districts[k][0]) + ' <small>' + esc(GEO.divisions[GEO.districts[k][1]]) + '</small></th>' + P.indicators.map(function (i) {
        var n = v[i.key] || 0;
        return '<td' + (n ? '' : ' class="is-zero"') + '>' + fmt(n) + '</td>';
      }).join('') + '</tr>';
    }).join('');
    var unkRow = Object.keys(P.unknown).length ? '<tr class="dc-unk"><th scope="row">জেলা অজানা</th>' + P.indicators.map(function (i) { return '<td>' + fmt(P.unknown[i.key] || 0) + '</td>'; }).join('') + '</tr>' : '';
    var foot = '<tr><th scope="row">সারা দেশ</th>' + P.indicators.map(function (i) { return '<td>' + fmt(P.totals[i.key] || 0) + '</td>'; }).join('') + '</tr>';
    box.innerHTML = '<table class="dc-table"><caption class="screen-reader-text">' + esc(P.label) + ' — জেলাভিত্তিক সারণি</caption><thead><tr>' + th + '</tr></thead><tbody>' + rows + unkRow + '</tbody><tfoot>' + foot + '</tfoot></table>';
  }

  /* -------------------------------------------------------------- events */
  function setLayer(l) {
    state.layer = l;
    body.querySelectorAll('[data-layer]').forEach(function (b) {
      var on = b.getAttribute('data-layer') === l;
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
      b.classList.toggle('is-on', on);
    });
    drawMap(); drawSide(); drawTrend();
  }
  function selectDistrict(k) {
    state.district = k || '';
    var pick = body.querySelector('[data-pick]');
    if (pick) pick.value = state.district;
    body.querySelectorAll('.dc-d').forEach(function (p) { p.classList.toggle('is-sel', p.getAttribute('data-k') === state.district); });
    drawSide();
  }

  function bindBody() {
    body.addEventListener('click', onClick);
    var pick = body.querySelector('[data-pick]');
    if (pick) pick.addEventListener('change', function () { selectDistrict(pick.value); });
    var mapBox = body.querySelector('[data-map]');
    var tip = body.querySelector('[data-tip]');
    if (mapBox && tip) {
      var lastK = '';
      var move = function (e) {
        var t = e.target.closest('[data-k]');
        if (!t) { tip.hidden = true; lastK = ''; return; }
        var k = t.getAttribute('data-k');
        if (k !== lastK) { tip.innerHTML = tipHtml(k); lastK = k; }
        tip.hidden = false;
        var card = mapBox.parentNode.getBoundingClientRect();
        var x = e.clientX - card.left, y = e.clientY - card.top;
        var tw = tip.offsetWidth, thh = tip.offsetHeight;
        var left = x + 16 + tw > card.width ? x - tw - 16 : x + 16;
        var top = Math.min(Math.max(8, y - thh / 2), card.height - thh - 8);
        tip.style.left = Math.max(8, left) + 'px';
        tip.style.top = top + 'px';
        body.querySelectorAll('.dc-d.is-hover').forEach(function (p) { p.classList.remove('is-hover'); });
        var path = mapBox.querySelector('.dc-d[data-k="' + k + '"]');
        if (path) path.classList.add('is-hover');
      };
      mapBox.addEventListener('pointermove', move);
      mapBox.addEventListener('pointerdown', move);
      mapBox.addEventListener('pointerleave', function (e) {
        if (e.pointerType === 'mouse') { tip.hidden = true; lastK = ''; }
        body.querySelectorAll('.dc-d.is-hover').forEach(function (p) { p.classList.remove('is-hover'); });
      });
      mapBox.addEventListener('click', function (e) {
        var t = e.target.closest('[data-k]');
        if (t) selectDistrict(t.getAttribute('data-k'));
      });
    }
  }
  function onClick(e) {
    var b = e.target.closest('button');
    if (!b || !body.contains(b)) return;
    if (b.hasAttribute('data-layer')) { setLayer(b.getAttribute('data-layer')); return; }
    if (b.hasAttribute('data-clear')) { selectDistrict(''); return; }
    if (b.hasAttribute('data-k') && b.closest('[data-side]')) { selectDistrict(b.getAttribute('data-k')); return; }
    if (b.hasAttribute('data-sort')) {
      var k = b.getAttribute('data-sort');
      state.sort = state.sort.key === k ? { key: k, dir: -state.sort.dir } : { key: k, dir: 1 };
      drawTable();
    }
  }

  /* -------------------------------------------------------------- filter */
  function syncFilter() {
    form.querySelectorAll('.dc-seg__b').forEach(function (b) { b.setAttribute('aria-pressed', b.value === P.range ? 'true' : 'false'); });
    dateIn.value = P.to;
    dateIn.max = shift(P.today, -1);
    if (P.first) dateIn.min = P.first;
    var y = shift(P.today, -1);
    var next = form.querySelector('[data-dc-step="1"]'), prev = form.querySelector('[data-dc-step="-1"]');
    next.disabled = P.to >= y;
    prev.disabled = !!P.first && P.to <= P.first;
  }
  function load(range, date) {
    var url = REST + (REST.indexOf('?') > -1 ? '&' : '?') + 'range=' + encodeURIComponent(range) + (date ? '&date=' + encodeURIComponent(date) : '');
    var q = new URL(window.location.href);
    q.searchParams.set('dr', range);
    if (date) q.searchParams.set('dd', date); else q.searchParams.delete('dd');
    if (range === 'day' && !date) { q.searchParams.delete('dr'); }
    try { window.history.replaceState(null, '', q.toString()); } catch (er) { /* ignore */ }
    root.classList.add('is-loading');
    var done = function (p) { P = p; root.classList.remove('is-loading'); render(); };
    if (cache[url]) { done(cache[url]); return; }
    fetch(url, { credentials: 'same-origin' }).then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
      .then(function (p) { cache[url] = p; done(p); })
      .catch(function () { root.classList.remove('is-loading'); window.location.href = q.toString(); });
  }
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var r = e.submitter && e.submitter.name === 'dr' ? e.submitter.value : P.range;
    load(r, dateIn.value || P.to);
  });
  form.querySelectorAll('.dc-seg__b').forEach(function (b) {
    b.addEventListener('click', function (e) { e.preventDefault(); load(b.value, P.to); });
  });
  dateIn.addEventListener('change', function () { if (dateIn.value) load(P.range, dateIn.value); });
  form.querySelectorAll('[data-dc-step]').forEach(function (b) {
    b.addEventListener('click', function () {
      var stepDays = P.range === '7' ? 7 : P.range === '30' ? 30 : 1;
      if (P.range === 'month') {
        var d = d8(P.to); d.setUTCDate(1); d.setUTCMonth(d.getUTCMonth() + (+b.getAttribute('data-dc-step')));
        var last = new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth() + 1, 0)).toISOString().slice(0, 10);
        load('month', last > shift(P.today, -1) ? shift(P.today, -1) : last);
        return;
      }
      load(P.range, shift(P.to, stepDays * (+b.getAttribute('data-dc-step'))));
    });
  });

  var rz;
  window.addEventListener('resize', function () { clearTimeout(rz); rz = setTimeout(function () { if (P.covered) drawTrend(); }, 200); });
  cache[REST + '?range=' + P.range + (P.requested ? '&date=' + P.requested : '')] = P;
  root.classList.add('is-ready');
  render();
})();
