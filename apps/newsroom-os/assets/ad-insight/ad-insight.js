/* r50 — বিজ্ঞাপনের ফলাফল: the client's private insight page ([meeha_ad_insight]).
 * Reads GET /nros/v1/ads/insight?c=&k=&days= and draws everything with plain SVG — no libraries.
 * Redraws every minute while the tab is open. */
(function () {
  'use strict';
  var root = document.getElementById('nai');
  if (!root) return;
  var body = root.querySelector('.nai-body');
  var cfg = {};
  try { cfg = JSON.parse(root.getAttribute('data-cfg') || '{}'); } catch (e) {}
  var qs = new URLSearchParams(location.search);
  var C = qs.get('c'), K = qs.get('k');
  var days = [7, 30, 90].indexOf(+qs.get('days')) >= 0 ? +qs.get('days') : 30;
  var data = null, timer = 0, lastOk = 0;

  /* ---------- helpers ---------- */
  var BN = '০১২৩৪৫৬৭৮৯';
  function bnd(s) { return String(s).replace(/\d/g, function (d) { return BN[d]; }); }
  function num(n) { n = +n || 0; try { return n.toLocaleString('bn-BD'); } catch (e) { return bnd(n); } }
  function short(n) {
    n = +n || 0;
    if (n >= 1e7) return bnd((n / 1e7).toFixed(n >= 1e8 ? 0 : 1)) + ' কোটি';
    if (n >= 1e5) return bnd((n / 1e5).toFixed(n >= 1e6 ? 0 : 1)) + ' লাখ';
    if (n >= 1e4) return bnd((n / 1e3).toFixed(0)) + ' হাজার';
    return num(n);
  }
  function pct(n, d) { return bnd((+n || 0).toFixed(d == null ? 1 : d)) + '%'; }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  var MON = ['জানু', 'ফেব্রু', 'মার্চ', 'এপ্রিল', 'মে', 'জুন', 'জুলাই', 'আগস্ট', 'সেপ্টে', 'অক্টো', 'নভে', 'ডিসে'];
  function dayLabel(ymd) { var p = String(ymd).split('-'); return bnd(+p[2]) + ' ' + MON[+p[1] - 1]; }
  function dateLong(ymd) { var p = String(ymd).split('-'); return bnd(+p[2]) + ' ' + MON[+p[1] - 1] + ' ' + bnd(p[0]); }
  function change(cur, prev) {
    if (!prev) return cur ? '<span class="nai-chg is-new">নতুন</span>' : '';
    var c = (cur - prev) / prev * 100, up = c >= 0;
    return '<span class="nai-chg ' + (up ? 'is-up' : 'is-down') + '" title="আগের ' + bnd(days) + ' দিনের তুলনায়">' + (up ? '▲ ' : '▼ ') + pct(Math.abs(c), 0) + '</span>';
  }
  var DEV = { mobile: 'মোবাইল', desktop: 'কম্পিউটার', tablet: 'ট্যাবলেট' };
  var OS = { android: 'Android', ios: 'iPhone / iPad', windows: 'Windows', mac: 'Mac', linux: 'Linux', other: 'অন্যান্য' };
  var BR = { chrome: 'Chrome', facebook: 'Facebook অ্যাপের ভেতরে', safari: 'Safari', samsung: 'Samsung Internet', edge: 'Edge', firefox: 'Firefox', opera: 'Opera', uc: 'UC Browser', other: 'অন্যান্য' };
  var SEC = { home: 'প্রথম পাতা', article: 'খবরের পাতা', opinion: 'মতামত', literature: 'সাহিত্য', category: 'বিভাগের পাতা', video: 'ভিডিও', quotes: 'উক্তি', page: 'অন্য পাতা', search: 'খোঁজ', live: 'লাইভ', other: 'অন্যান্য' };
  var SRC = { direct: 'সরাসরি / বুকমার্ক', internal: 'মিহার ভেতর থেকে', google: 'Google', facebook: 'Facebook', youtube: 'YouTube', social: 'অন্য সোশ্যাল', other: 'অন্য সাইট' };
  var KIND = { video: 'ভিডিওর কোণে বিজ্ঞাপন', youtube: 'YouTube', facebook: 'Facebook', photocard: 'ফটোকার্ড', print: 'ছাপা', other: 'অন্যান্য' };

  /* ---------- tooltip ---------- */
  var tip = document.createElement('div');
  tip.className = 'nai-tip'; tip.setAttribute('role', 'tooltip'); tip.hidden = true;
  document.body.appendChild(tip);
  function showTip(html, x, y) {
    tip.innerHTML = html; tip.hidden = false;
    var w = tip.offsetWidth, h = tip.offsetHeight;
    var left = Math.min(window.innerWidth - w - 8, Math.max(8, x + 14));
    var top = y - h - 12; if (top < 8) top = y + 16;
    tip.style.left = left + 'px'; tip.style.top = top + 'px';
  }
  function hideTip() { tip.hidden = true; }

  /* ---------- charts ---------- */
  // Drawing width follows the card, so phone labels stay readable.
  function VW() { return Math.max(340, Math.min(720, (body.clientWidth || 720) - 40)); }
  function ticks(max) {
    if (max <= 0) return [0, 1];
    var step = Math.pow(10, Math.floor(Math.log10(max))), m = max / step;
    step *= m <= 2 ? 0.5 : m <= 5 ? 1 : 2;
    var out = [];
    for (var v = 0; v <= max + step * 0.001; v += step) out.push(v);
    if (out[out.length - 1] < max) out.push(out[out.length - 1] + step);
    return out;
  }
  /** Lines over days (one axis) + an optional dashed forecast with its range. */
  function lineChart(series, labels, fc) {
    var W = VW(), H = W < 500 ? 230 : 250, L = 48, R = 12, T = 14, B = 30;
    var n = labels.length + (fc ? fc.days.length : 0);
    var max = 0;
    series.forEach(function (s) { s.values.forEach(function (v) { if (v > max) max = v; }); });
    if (fc) fc.days.forEach(function (v) { if (v > max) max = v; });
    var tk = ticks(max), top = tk[tk.length - 1] || 1;
    var x = function (i) { return L + (n <= 1 ? 0 : i * (W - L - R) / (n - 1)); };
    var y = function (v) { return T + (H - T - B) * (1 - v / top); };
    var g = '';
    tk.forEach(function (t) { g += '<line class="nai-gl" x1="' + L + '" x2="' + (W - R) + '" y1="' + y(t) + '" y2="' + y(t) + '"/><text class="nai-ax" x="' + (L - 6) + '" y="' + (y(t) + 4) + '" text-anchor="end">' + short(t) + '</text>'; });
    var every = Math.max(1, Math.ceil(n / (W < 500 ? 4 : 8)));
    labels.forEach(function (d, i) { if (i % every === 0 || i === labels.length - 1) g += '<text class="nai-ax" x="' + x(i) + '" y="' + (H - 8) + '" text-anchor="middle">' + dayLabel(d) + '</text>'; });
    if (fc) {
      var last = labels.length - 1;
      g += '<rect class="nai-fczone" x="' + x(last) + '" y="' + T + '" width="' + (x(n - 1) - x(last)) + '" height="' + (H - T - B) + '"/>';
      g += '<text class="nai-ax nai-ax--fc" x="' + ((x(last) + x(n - 1)) / 2) + '" y="' + (T + 12) + '" text-anchor="middle">আনুমানিক</text>';
    }
    series.forEach(function (s, si) {
      var d = s.values.map(function (v, i) { return (i ? 'L' : 'M') + x(i).toFixed(1) + ' ' + y(v).toFixed(1); }).join('');
      if (si === series.length - 1) {
        g += '<path class="nai-area" style="fill:' + s.color + '" d="' + d + 'L' + x(s.values.length - 1) + ' ' + y(0) + 'L' + x(0) + ' ' + y(0) + 'Z"/>';
      }
      g += '<path class="nai-line" style="stroke:' + s.color + '" d="' + d + '"/>';
      if (fc && s.fc) {
        // from yesterday's point (today is still running) to the next 7 days
        var li = Math.max(0, s.values.length - 2), lv = s.values[li];
        var fd = 'M' + x(li) + ' ' + y(lv) + fc.days.map(function (v, k) { return 'L' + x(s.values.length + k).toFixed(1) + ' ' + y(v).toFixed(1); }).join('');
        g += '<path class="nai-line nai-line--fc" style="stroke:' + s.color + '" d="' + fd + '"/>';
      }
      // direct label at the line's end
      var lx = x(s.values.length - 1), ly = y(s.values[s.values.length - 1]);
      g += '<circle class="nai-end nai-end--today" cx="' + lx + '" cy="' + ly + '" r="4" style="stroke:' + s.color + '"><title>আজ — এখনো চলছে</title></circle>';
    });
    g += '<line class="nai-cross" x1="0" x2="0" y1="' + T + '" y2="' + (H - B) + '" hidden/>';
    g += '<rect class="nai-hit" x="' + L + '" y="' + T + '" width="' + (W - L - R) + '" height="' + (H - T - B) + '"/>';
    var svg = '<svg class="nai-svg" viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="দিনভিত্তিক চার্ট">' + g + '</svg>';
    return {
      html: svg,
      bind: function (el) {
        var s = el.querySelector('svg'), hit = s.querySelector('.nai-hit'), cr = s.querySelector('.nai-cross');
        var move = function (ev) {
          var r = s.getBoundingClientRect(), px = (ev.clientX - r.left) * W / r.width;
          var i = Math.round((px - L) / ((W - L - R) / Math.max(1, n - 1)));
          i = Math.max(0, Math.min(n - 1, i));
          cr.setAttribute('x1', x(i)); cr.setAttribute('x2', x(i)); cr.removeAttribute('hidden');
          var h;
          if (i < labels.length) {
            h = '<b>' + dateLong(labels[i]) + (i === labels.length - 1 ? ' (আজ — এখনো চলছে)' : '') + '</b>' + series.map(function (q) { return '<span><i style="background:' + q.color + '"></i>' + q.name + ': ' + num(q.values[i]) + '</span>'; }).join('');
          } else {
            var k = i - labels.length;
            h = '<b>সামনের ' + bnd(k + 1) + ' নম্বর দিন</b><span>আনুমানিক নিশ্চিত দেখা: ' + num(fc.days[k]) + '</span>';
          }
          showTip(h, ev.clientX, ev.clientY);
        };
        hit.addEventListener('pointermove', move);
        hit.addEventListener('pointerdown', move);
        hit.addEventListener('pointerleave', function () { cr.setAttribute('hidden', ''); hideTip(); });
      }
    };
  }
  /** Columns (one series). */
  function columns(values, labels, color, name, fmtLabel, hiIdx) {
    var W = VW(), H = W < 500 ? 180 : 200, L = 40, R = 8, T = 12, B = 28, n = values.length;
    var max = Math.max.apply(null, values.concat([0]));
    var tk = ticks(max), top = tk[tk.length - 1] || 1;
    var bw = (W - L - R) / n, gap = Math.min(6, bw * 0.25);
    var y = function (v) { return T + (H - T - B) * (1 - v / top); };
    var g = '';
    tk.forEach(function (t) { g += '<line class="nai-gl" x1="' + L + '" x2="' + (W - R) + '" y1="' + y(t) + '" y2="' + y(t) + '"/><text class="nai-ax" x="' + (L - 6) + '" y="' + (y(t) + 4) + '" text-anchor="end">' + short(t) + '</text>'; });
    var every = Math.max(1, Math.ceil(n / (W < 500 ? 6 : 12)));
    values.forEach(function (v, i) {
      var x0 = L + i * bw + gap / 2, w = Math.max(1, bw - gap), h = Math.max(v > 0 ? 2 : 0, y(0) - y(v));
      var rr = Math.min(4, w / 2);
      g += '<path class="nai-col' + (i === hiIdx ? ' is-hi' : '') + '" data-i="' + i + '" style="fill:' + color + '" d="M' + x0 + ' ' + y(0) + 'V' + (y(0) - h + rr) + 'Q' + x0 + ' ' + (y(0) - h) + ' ' + (x0 + rr) + ' ' + (y(0) - h) + 'H' + (x0 + w - rr) + 'Q' + (x0 + w) + ' ' + (y(0) - h) + ' ' + (x0 + w) + ' ' + (y(0) - h + rr) + 'V' + y(0) + 'Z"/>';
      g += '<rect class="nai-colhit" data-i="' + i + '" x="' + (L + i * bw) + '" y="' + T + '" width="' + bw + '" height="' + (H - T - B) + '"/>';
      if (i % every === 0) g += '<text class="nai-ax" x="' + (L + i * bw + bw / 2) + '" y="' + (H - 8) + '" text-anchor="middle">' + fmtLabel(labels[i], true) + '</text>';
    });
    return {
      html: '<svg class="nai-svg" viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="' + esc(name) + '">' + g + '</svg>',
      bind: function (el) {
        el.querySelectorAll('.nai-colhit').forEach(function (r) {
          var i = +r.getAttribute('data-i');
          var on = function (ev) { showTip('<b>' + fmtLabel(labels[i]) + '</b><span><i style="background:' + color + '"></i>' + name + ': ' + num(values[i]) + '</span>', ev.clientX, ev.clientY); };
          r.addEventListener('pointermove', on); r.addEventListener('pointerdown', on); r.addEventListener('pointerleave', hideTip);
        });
      }
    };
  }
  /** Horizontal bars, each with its number and share. */
  function hbars(rows, names, color) {
    var total = rows.reduce(function (a, r) { return a + r.imp; }, 0) || 1;
    var max = Math.max.apply(null, rows.map(function (r) { return r.imp; }).concat([1]));
    return '<ul class="nai-hb">' + rows.slice(0, 8).map(function (r) {
      var ctr = r.imp ? r.clk / r.imp * 100 : 0;
      return '<li title="' + esc((names[r.val] || r.val) + ': ' + num(r.imp) + ' দেখা, ' + num(r.clk) + ' ক্লিক') + '"><span class="nai-hb__n">' + esc(names[r.val] || r.val) + '</span><span class="nai-hb__bar"><i style="width:' + (r.imp / max * 100).toFixed(1) + '%;background:' + color + '"></i></span><span class="nai-hb__v">' + pct(r.imp / total * 100, 0) + ' <small>' + short(r.imp) + (r.clk ? ' · ক্লিক হার ' + pct(ctr, 1) : '') + '</small></span></li>';
    }).join('') + '</ul>';
  }
  /** One 100% bar split by category, with a legend and labels. */
  function split(rows, names, colors) {
    var total = rows.reduce(function (a, r) { return a + r.imp; }, 0);
    if (!total) return '<p class="nai-empty">এখনো হিসাব নেই।</p>';
    var bar = '', leg = '';
    rows.forEach(function (r, i) {
      var p = r.imp / total * 100, c = colors[i % colors.length];
      bar += '<i style="flex:' + r.imp + ';background:' + c + '" title="' + esc((names[r.val] || r.val) + ' ' + pct(p, 0)) + '">' + (p >= 12 ? '<b>' + pct(p, 0) + '</b>' : '') + '</i>';
      leg += '<li><i style="background:' + c + '"></i>' + esc(names[r.val] || r.val) + ' <b>' + pct(p, 0) + '</b> <small>' + short(r.imp) + '</small></li>';
    });
    return '<div class="nai-split">' + bar + '</div><ul class="nai-legend">' + leg + '</ul>';
  }
  function ring(score) {
    var r = 52, c = 2 * Math.PI * r, v = Math.max(0, Math.min(100, score));
    return '<svg class="nai-ring" viewBox="0 0 132 132" role="img" aria-label="পারফরম্যান্স ' + bnd(v) + '/১০০"><circle cx="66" cy="66" r="' + r + '" class="nai-ring__bg"/><circle cx="66" cy="66" r="' + r + '" class="nai-ring__fg" stroke-dasharray="' + (c * v / 100).toFixed(1) + ' ' + c.toFixed(1) + '" transform="rotate(-90 66 66)"/><text x="66" y="70" text-anchor="middle" class="nai-ring__t">' + bnd(v) + '</text><text x="66" y="90" text-anchor="middle" class="nai-ring__s">১০০-এর মধ্যে</text></svg>';
  }

  /* ---------- page ---------- */
  var COL = { srv: 'var(--nai-s3)', imp: 'var(--nai-s1)', clk: 'var(--nai-s2)', reach: 'var(--nai-s4)' };
  function card(title, inner, cls, sub) {
    return '<section class="nai-card ' + (cls || '') + '"><h2>' + title + '</h2>' + (sub ? '<p class="nai-sub">' + sub + '</p>' : '') + inner + '</section>';
  }
  function render() {
    var d = data, t = d.totals, p = d.prev, binds = [];
    var h = '';
    // hero
    h += '<div class="nai-hero"><div><p class="nai-kicker">' + esc(cfg.site || d.site.name) + ' · বিজ্ঞাপনের ফলাফল</p><h1>' + esc(d.client.name) + '</h1>' +
      '<p class="nai-range">' + dateLong(d.from) + ' — ' + dateLong(d.to) + ' <span class="nai-live" title="পাতাটি প্রতি মিনিটে নিজে হালনাগাদ হয়"><i></i>সরাসরি</span></p></div>' +
      '<div class="nai-tools"><div class="nai-seg" role="tablist" aria-label="সময়">' + [7, 30, 90].map(function (n) { return '<button type="button" role="tab" data-days="' + n + '" aria-selected="' + (n === days) + '">' + bnd(n) + ' দিন</button>'; }).join('') + '</div>' +
      '<button type="button" class="nai-btn" data-act="print">PDF / প্রিন্ট</button><button type="button" class="nai-btn" data-act="copy">লিংক কপি</button></div></div>';
    // KPI tiles
    var kpis = [
      ['srv', 'ইমপ্রেশন', t.srv, p.srv, 'পাতায় আপনার বিজ্ঞাপন যতবার দেখানো হয়েছে'],
      ['imp', 'নিশ্চিত দেখা', t.imp, p.imp, 'অন্তত অর্ধেক অংশ টানা ১ সেকেন্ড পর্দায় ছিল'],
      ['reach', 'মানুষ (আনুমানিক)', t.reach, p.reach, 'দিনে প্রতিটি ফোন/কম্পিউটার একবার গোনা'],
      ['clk', 'ক্লিক', t.clk, p.clk, 'বিজ্ঞাপনে চাপ দিয়ে আপনার পাতায় গেছেন'],
    ];
    h += '<div class="nai-kpis">' + kpis.map(function (k) {
      return '<div class="nai-kpi" style="--c:' + COL[k[0]] + '"><span class="nai-kpi__l">' + k[1] + '</span><b class="nai-kpi__v">' + short(k[2]) + '</b>' + change(k[2], k[3]) + '<small>' + k[4] + '</small></div>';
    }).join('') +
      '<div class="nai-kpi" style="--c:var(--nai-s5)"><span class="nai-kpi__l">ক্লিকের হার (CTR)</span><b class="nai-kpi__v">' + pct(t.ctr, 2) + '</b><small>মিহার গড় ' + pct(d.bench.ctr, 2) + '</small></div>' +
      '<div class="nai-kpi" style="--c:var(--nai-s6)"><span class="nai-kpi__l">দৃশ্যমানতা</span><b class="nai-kpi__v">' + pct(t.view_rate, 0) + '</b><small>দেখানোর কত ভাগ সত্যিই দেখা গেছে · গড় ' + pct(d.bench.view_rate, 0) + '</small></div></div>';

    // trend + forecast
    var labels = d.daily.map(function (x) { return x.day; });
    var fc = d.forecast && d.forecast.imp;
    var lc = lineChart([
      { name: 'ইমপ্রেশন', color: COL.srv, values: d.daily.map(function (x) { return x.srv; }) },
      { name: 'নিশ্চিত দেখা', color: COL.imp, values: d.daily.map(function (x) { return x.imp; }), fc: true },
    ], labels, fc);
    var tbl = '<table class="nai-table"><thead><tr><th>দিন</th><th>ইমপ্রেশন</th><th>নিশ্চিত দেখা</th><th>ক্লিক</th></tr></thead><tbody>' +
      d.daily.slice().reverse().map(function (x) { return '<tr><td>' + dateLong(x.day) + '</td><td>' + num(x.srv) + '</td><td>' + num(x.imp) + '</td><td>' + num(x.clk) + '</td></tr>'; }).join('') + '</tbody></table>';
    h += card('দিন অনুযায়ী', '<ul class="nai-legend nai-legend--top"><li><i style="background:' + COL.srv + '"></i>ইমপ্রেশন</li><li><i style="background:' + COL.imp + '"></i>নিশ্চিত দেখা</li>' + (fc ? '<li><i class="is-dash" style="border-color:' + COL.imp + '"></i>সামনের ৭ দিন (আনুমানিক)</li>' : '') + '</ul><div class="nai-chart" data-bind="0">' + lc.html + '</div><details class="nai-tv"><summary>সংখ্যায় দেখুন</summary>' + tbl + '</details>', 'nai-card--wide');
    binds.push(lc);

    // score + forecast
    var sc = d.score;
    var scoreHtml = sc ? '<div class="nai-score">' + ring(sc.value) + '<ul class="nai-parts">' +
      '<li><span>দৃশ্যমানতা</span><b>' + bnd(sc.view) + '/৪০</b><i style="--w:' + (sc.view / 40 * 100) + '%"></i></li>' +
      '<li><span>ক্লিকের হার, মিহার গড়ের তুলনায়</span><b>' + bnd(sc.ctr) + '/৪০</b><i style="--w:' + (sc.ctr / 40 * 100) + '%"></i></li>' +
      '<li><span>প্রতিদিন নিয়মিত দেখা</span><b>' + bnd(sc.steady) + '/২০</b><i style="--w:' + (sc.steady / 20 * 100) + '%"></i></li></ul></div>' +
      '<p class="nai-note">মিহার নিজস্ব মাপকাঠি: ৭০% দৃশ্যমানতায় পুরো ৪০, মিহার সব বিজ্ঞাপনের গড় ক্লিক হারে পুরো ৪০, আর প্রচার শুরুর পর প্রতিদিন দেখা হলে পুরো ২০।</p>'
      : '<p class="nai-empty">যথেষ্ট হিসাব জমলে স্কোর দেখাবে।</p>';
    h += card('পারফরম্যান্স স্কোর', scoreHtml, 'nai-card--score');
    var f1 = d.forecast && d.forecast.imp, f2 = d.forecast && d.forecast.clk;
    var fh = f1 ? '<div class="nai-fc"><div><span>নিশ্চিত দেখা</span><b>' + short(f1.low) + ' – ' + short(f1.high) + '</b><small>সম্ভাব্য ' + short(f1.total) + '</small></div>' +
      (f2 ? '<div><span>ক্লিক</span><b>' + num(f2.low) + ' – ' + num(f2.high) + '</b><small>সম্ভাব্য ' + num(f2.total) + '</small></div>' : '') + '</div>' +
      '<p class="nai-note">শেষ ' + bnd(f1.basis) + ' দিনের ধারা থেকে সরল হিসাব (লিনিয়ার ট্রেন্ড)। বড় খবর, ছুটি বা বিজ্ঞাপনের জায়গা বদলালে আসল সংখ্যা এর বাইরে যেতে পারে — এটা প্রতিশ্রুতি নয়, অনুমান।</p>' +
      '<p class="nai-trend ' + (f1.trend >= 0 ? 'is-up' : 'is-down') + '">' + (f1.trend >= 0 ? '▲ সপ্তাহে প্রায় ' : '▼ সপ্তাহে প্রায় ') + pct(Math.abs(f1.trend), 0) + (f1.trend >= 0 ? ' বাড়ছে' : ' কমছে') + '</p>'
      : '<p class="nai-empty">অন্তত ৫ দিনের হিসাব জমলে সামনের ৭ দিনের অনুমান দেখাবে।</p>';
    h += card('সামনের ৭ দিন (আনুমানিক)', fh, 'nai-card--fc');

    // clicks
    var cc = columns(d.daily.map(function (x) { return x.clk; }), labels, COL.clk, 'ক্লিক', function (v) { return dayLabel(v); }, -1);
    h += card('ক্লিক, দিন অনুযায়ী', '<div class="nai-chart" data-bind="1">' + cc.html + '</div>', 'nai-card--wide');
    binds.push(cc);

    // devices
    var dev = d.dims.dev || [];
    h += card('কোন ডিভাইসে দেখেছেন', split(dev, DEV, ['var(--nai-s1)', 'var(--nai-s2)', 'var(--nai-s3)']) + '<div class="nai-two"><div><h3>সিস্টেম</h3>' + hbars(d.dims.os || [], OS, COL.imp) + '</div><div><h3>ব্রাউজার</h3>' + hbars(d.dims.br || [], BR, COL.imp) + '</div></div>', 'nai-card--wide');

    // hours
    var hrs = []; for (var i = 0; i < 24; i++) hrs.push(0);
    (d.dims.hr || []).forEach(function (r) { hrs[+r.val] = r.imp; });
    var best = hrs.indexOf(Math.max.apply(null, hrs));
    var hfmt = function (v, s) { var a = v % 12 === 0 ? 12 : v % 12; var part = v < 5 ? 'রাত' : v < 12 ? 'সকাল' : v < 16 ? 'দুপুর' : v < 18 ? 'বিকেল' : v < 20 ? 'সন্ধ্যা' : 'রাত'; return s ? bnd(a) : part + ' ' + bnd(a) + 'টা'; };
    var hc = columns(hrs, hrs.map(function (_, i) { return i; }), COL.imp, 'নিশ্চিত দেখা', hfmt, Math.max.apply(null, hrs) > 0 ? best : -1);
    h += card('দিনের কোন সময়ে বেশি দেখা', '<div class="nai-chart" data-bind="2">' + hc.html + '</div>' + (hrs[best] ? '<p class="nai-note">সবচেয়ে বেশি: <b>' + hfmt(best) + '</b> (বাংলাদেশ সময়)।</p>' : ''), 'nai-card--wide');
    binds.push(hc);

    // where
    h += card('সাইটের কোন অংশে', hbars(d.dims.sec || [], SEC, COL.imp));
    h += card('পাঠক কোথা থেকে এসেছিলেন', hbars(d.dims.src || [], SRC, COL.imp));

    // placements
    var camp = {}; (d.campaign || []).forEach(function (c) { camp[c.slot] = c; });
    var slotRows = d.slots.slice();
    (d.campaign || []).forEach(function (c) { if (!slotRows.some(function (s) { return s.slot === c.slot; })) slotRows.push({ slot: c.slot, label: c.label, tid: c.tid, srv: 0, imp: 0, clk: 0, ctr: 0, view: 0 }); });
    h += card('বিজ্ঞাপনের জায়গা ও ট্র্যাকিং আইডি', slotRows.length ? '<div class="nai-scroll"><table class="nai-table nai-table--slots"><thead><tr><th>জায়গা</th><th>ইমপ্রেশন</th><th>নিশ্চিত দেখা</th><th>দৃশ্যমানতা</th><th>ক্লিক</th><th>ক্লিক হার</th></tr></thead><tbody>' +
      slotRows.map(function (s) {
        var c = camp[s.slot];
        var st = c ? (c.live ? '<span class="nai-pill is-on">চলছে</span>' : '<span class="nai-pill">বন্ধ</span>') + (c.end ? ' <small>' + (c.days_left >= 0 ? 'শেষ ' + dateLong(c.end) + ' · আর ' + bnd(c.days_left) + ' দিন' : 'শেষ হয়েছে ' + dateLong(c.end)) + '</small>' : '') : '<span class="nai-pill">আগের প্রচার</span>';
        return '<tr><td>' + (c && c.thumb ? '<img src="' + esc(c.thumb) + '" alt="" loading="lazy">' : '') + '<b>' + esc(s.label) + '</b><code>' + esc(s.tid) + '</code>' + st + '</td><td>' + num(s.srv) + '</td><td>' + num(s.imp) + '</td><td>' + pct(s.view, 0) + '</td><td>' + num(s.clk) + '</td><td>' + pct(s.ctr, 2) + '</td></tr>';
      }).join('') + '</tbody></table></div><p class="nai-note">আপনার ওয়েবসাইটের Google Analytics-এ <code>utm_campaign</code> = ট্র্যাকিং আইডি দিয়ে খুঁজলে মিহা থেকে আসা ভিজিটরদের আলাদা দেখতে পাবেন।</p>' : '<p class="nai-empty">এখনো কোনো জায়গা দেওয়া হয়নি।</p>', 'nai-card--wide');

    // audience
    var au = d.audience;
    var auh;
    if (au && au.on) {
      var ages = au.ages || {}, amax = Math.max.apply(null, Object.keys(ages).map(function (k) { return +ages[k] || 0; }).concat([1]));
      auh = '<div class="nai-gender"><div class="nai-split">' + (au.male ? '<i style="flex:' + au.male + ';background:var(--nai-s1)"><b>' + pct(au.male, 0) + '</b></i>' : '') + (au.female ? '<i style="flex:' + au.female + ';background:var(--nai-s5)"><b>' + pct(au.female, 0) + '</b></i>' : '') + '</div><ul class="nai-legend"><li><i style="background:var(--nai-s1)"></i>পুরুষ ' + pct(au.male, 0) + '</li><li><i style="background:var(--nai-s5)"></i>নারী ' + pct(au.female, 0) + '</li></ul></div>' +
        '<h3>বয়স</h3><ul class="nai-hb">' + Object.keys(ages).map(function (k) { return '<li><span class="nai-hb__n">' + bnd(k) + ' বছর</span><span class="nai-hb__bar"><i style="width:' + ((+ages[k] || 0) / amax * 100) + '%;background:var(--nai-s4)"></i></span><span class="nai-hb__v">' + pct(ages[k], 0) + '</span></li>'; }).join('') + '</ul>' +
        '<p class="nai-note">এটি মিহার <b>সব পাঠকের</b> গড় ছবি' + (au.source ? ' (' + esc(au.source) + ')' : '') + (au.updated ? ', হালনাগাদ ' + dateLong(au.updated) : '') + ' — শুধু আপনার বিজ্ঞাপন দেখা মানুষদের আলাদা হিসাব নয়। মিহা কোনো পাঠকের লিঙ্গ বা বয়স সংগ্রহ করে না।</p>';
    } else {
      auh = '<p class="nai-note">মিহা কোনো পাঠকের লিঙ্গ, বয়স বা ব্যক্তিগত তথ্য সংগ্রহ করে না, তাই আন্দাজ করে এখানে কিছু দেখানো হয় না। ডিভাইস, সময়, সাইটের অংশ আর পাঠক কোথা থেকে এসেছেন — এগুলো ওপরে আসল হিসাব থেকে দেখানো হয়েছে।</p>';
    }
    h += card('পাঠকের লিঙ্গ ও বয়স', auh);

    // off-site
    var off = d.offsite;
    if (off && off.entries.length) {
      h += card('অন্যান্য প্রচার', '<div class="nai-fc"><div><span>মোট দেখা</span><b>' + short(off.views) + '</b></div>' + (off.clicks ? '<div><span>মোট ক্লিক</span><b>' + num(off.clicks) + '</b></div>' : '') + '</div><ul class="nai-off">' +
        off.entries.map(function (e) { return '<li><span class="nai-pill">' + esc(KIND[e.kind] || e.kind) + '</span> <b>' + (e.link ? '<a href="' + esc(e.link) + '" target="_blank" rel="noopener">' + esc(e.title || e.link) + '</a>' : esc(e.title)) + '</b><small>' + dateLong(e.date) + ' · ' + num(e.views) + ' দেখা' + (e.clicks ? ' · ' + num(e.clicks) + ' ক্লিক' : '') + (e.note ? ' · ' + esc(e.note) : '') + '</small></li>'; }).join('') +
        '</ul><p class="nai-note">এগুলো প্রকাশকের দেওয়া হিসাব (যেমন YouTube/Facebook-এর নিজস্ব ভিউ) — ওপরের ওয়েবসাইটের স্বয়ংক্রিয় হিসাবের সাথে যোগ করা হয়নি।</p>', 'nai-card--wide');
    }

    // tips
    if (d.tips && d.tips.length) {
      h += card('পরামর্শ — কীভাবে আরও ভালো ফল পাবেন', '<ul class="nai-tips">' + d.tips.map(function (x) { return '<li class="is-' + x.tone + '"><i aria-hidden="true">' + (x.tone === 'good' ? '✓' : x.tone === 'warn' ? '!' : 'i') + '</i><span class="nai-tips__k">' + (x.tone === 'good' ? 'ভালো' : x.tone === 'warn' ? 'নজর দিন' : 'পরামর্শ') + '</span>' + esc(x.text) + '</li>'; }).join('') + '</ul>', 'nai-card--wide');
    }

    // how counted
    h += card('কীভাবে গোনা হয়', '<dl class="nai-dl"><dt>ইমপ্রেশন</dt><dd>পাতা খোলার পর আপনার বিজ্ঞাপন পাতায় দেখানো হয়েছে (লুকানো বা অন্য ডিভাইসের জন্য রাখা বিজ্ঞাপন গোনা হয় না)।</dd><dt>নিশ্চিত দেখা</dt><dd>বিজ্ঞাপনের অন্তত অর্ধেক অংশ টানা এক সেকেন্ড পাঠকের পর্দায় ছিল — ডিসপ্লে বিজ্ঞাপনের প্রচলিত "viewable" মাপ।</dd><dt>মানুষ</dt><dd>প্রতিদিন প্রতিটি ফোন বা কম্পিউটার একবার; কয়েক দিনের যোগফল, তাই একই মানুষ ভিন্ন দিনে আবার গোনা হতে পারেন।</dd><dt>ক্লিক</dt><dd>বিজ্ঞাপনের লিংকে চাপ।</dd><dt>বাদ যায়</dt><dd>সার্চ ইঞ্জিন ও অন্যান্য বট। লগইন করা পাঠকও সবার মতোই গোনা হন।</dd></dl><p class="nai-note">কোনো পাঠকের নাম, ফোন, আইপি বা কুকি রাখা হয় না — শুধু দিনভিত্তিক মোট সংখ্যা।</p>', 'nai-card--wide');

    var contact = '';
    if (cfg.wa) contact += '<a class="nai-btn nai-btn--wa" href="https://wa.me/' + esc(cfg.wa) + '?text=' + encodeURIComponent('বিজ্ঞাপন নিয়ে কথা বলতে চাই — ' + d.client.name) + '" target="_blank" rel="noopener">WhatsApp-এ যোগাযোগ</a>';
    if (cfg.phone) contact += '<a class="nai-btn" href="tel:' + esc(cfg.phone) + '">ফোন: ' + esc(bnd(cfg.phone)) + '</a>';
    h += '<footer class="nai-foot"><span>সর্বশেষ হালনাগাদ: <b data-upd></b></span>' + contact + '</footer>';

    body.innerHTML = '<div class="nai-grid">' + h + '</div>';
    body.querySelectorAll('[data-bind]').forEach(function (el) { binds[+el.getAttribute('data-bind')].bind(el); });
    stamp();
  }
  function stamp() {
    var el = body.querySelector('[data-upd]');
    if (!el || !lastOk) return;
    var s = Math.round((Date.now() - lastOk) / 1000);
    el.textContent = s < 60 ? 'এইমাত্র' : bnd(Math.round(s / 60)) + ' মিনিট আগে';
  }
  function fail(msg) { body.innerHTML = '<div class="nai-error" role="alert"><b>ফলাফল দেখানো যাচ্ছে না।</b><p>' + esc(msg) + '</p></div>'; }

  function load(quiet) {
    if (!C || !K) { fail('এই পাতার লিংকে আপনার গোপন চাবি নেই। মিহার বিজ্ঞাপন বিভাগ থেকে পাঠানো পুরো লিংকটি খুলুন।'); return; }
    if (!quiet) body.setAttribute('aria-busy', 'true');
    fetch(cfg.api + '?c=' + encodeURIComponent(C) + '&k=' + encodeURIComponent(K) + '&days=' + days, { credentials: 'omit', cache: 'no-store' })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (x) {
        body.removeAttribute('aria-busy');
        if (!x.ok) { fail((x.j && x.j.message) || 'আবার চেষ্টা করুন।'); return; }
        data = x.j; lastOk = Date.now(); render();
        document.title = data.client.name + ' — বিজ্ঞাপনের ফলাফল · ' + (cfg.site || '');
      })
      .catch(function () { body.removeAttribute('aria-busy'); if (!data) fail('ইন্টারনেট সংযোগ দেখে আবার চেষ্টা করুন।'); });
  }
  root.addEventListener('click', function (e) {
    var b = e.target.closest('[data-days]');
    if (b) { days = +b.getAttribute('data-days'); qs.set('days', days); history.replaceState(null, '', '?' + qs.toString()); load(); return; }
    var a = e.target.closest('[data-act]');
    if (!a) return;
    if (a.getAttribute('data-act') === 'print') window.print();
    if (a.getAttribute('data-act') === 'copy') {
      var done = function () { a.textContent = 'কপি হয়েছে ✓'; setTimeout(function () { a.textContent = 'লিংক কপি'; }, 1800); };
      if (navigator.clipboard) navigator.clipboard.writeText(location.href).then(done, function () {}); else done();
    }
  });
  var rw = 0, lastW = body.clientWidth;
  addEventListener('resize', function () { clearTimeout(rw); rw = setTimeout(function () { if (data && Math.abs(body.clientWidth - lastW) > 40) { lastW = body.clientWidth; render(); } }, 250); });
  load();
  timer = setInterval(function () { if (!document.hidden) load(true); else stamp(); }, 60000);
  setInterval(stamp, 15000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden && Date.now() - lastOk > 60000) load(true); });
})();
