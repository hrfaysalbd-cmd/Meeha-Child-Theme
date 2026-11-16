/* r46 — গোপন তথ্য পাঠান: tip form (multipart XHR with progress) + anonymous status checker. */
(function () {
  'use strict';
  var root = document.querySelector('[data-tips]');
  if (!root) return;
  var cfg = {};
  try { cfg = JSON.parse(root.getAttribute('data-tips')); } catch (e) { return; }
  var bn = function (n) { return String(n).replace(/[0-9]/g, function (d) { return '০১২৩৪৫৬৭৮৯'[d]; }); };
  var mb = function (b) { return bn((b / 1048576).toFixed(b < 10485760 ? 1 : 0)) + ' এমবি'; };
  var $ = function (s, el) { return (el || root).querySelector(s); };
  var form = $('.m-tips__form');
  var done = $('[data-done]');
  var check = $('#m-tips-check');

  // tabs
  var tabs = root.querySelectorAll('[role="tab"]');
  function show(which) {
    tabs.forEach(function (t) { t.setAttribute('aria-selected', t.getAttribute('data-tab') === which ? 'true' : 'false'); });
    form.hidden = which !== 'send';
    done.hidden = true;
    check.hidden = which !== 'check';
  }
  tabs.forEach(function (t) {
    t.addEventListener('click', function () { show(t.getAttribute('data-tab')); });
    t.addEventListener('keydown', function (e) {
      if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
      var o = t.getAttribute('data-tab') === 'send' ? 'check' : 'send';
      show(o);
      root.querySelector('[data-tab="' + o + '"]').focus();
    });
  });

  // files
  var input = $('#m-tips-files');
  var list = $('[data-filelist]');
  function fileProblem() {
    var fs = Array.prototype.slice.call(input.files || []);
    if (fs.length > cfg.maxFiles) return 'সর্বোচ্চ ' + bn(cfg.maxFiles) + 'টি ফাইল দেওয়া যায়।';
    for (var i = 0; i < fs.length; i++) {
      if (fs[i].size > cfg.maxFile) return '“' + fs[i].name + '” ফাইলটি বড় — প্রতিটি সর্বোচ্চ ' + mb(cfg.maxFile) + '।';
    }
    return '';
  }
  input.addEventListener('change', function () {
    list.innerHTML = '';
    Array.prototype.forEach.call(input.files || [], function (f, i) {
      var li = document.createElement('li');
      var bad = f.size > cfg.maxFile || i >= cfg.maxFiles;
      if (bad) li.className = 'is-bad';
      li.appendChild(document.createTextNode(f.name));
      var s = document.createElement('span');
      s.textContent = mb(f.size) + (bad ? ' — নেওয়া যাবে না' : '');
      li.appendChild(s);
      list.appendChild(li);
    });
  });

  var err = $('[data-err]');
  var bar = $('[data-progress]');
  function fail(msg) { err.textContent = msg; err.hidden = false; err.focus && err.scrollIntoView({ block: 'center' }); }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    err.hidden = true;
    var body = $('#m-tips-body').value.trim();
    if (body.length < 20) { fail('ঘটনাটি একটু খুলে লিখুন (অন্তত ২০ অক্ষর) — কী, কোথায়, কখন।'); $('#m-tips-body').focus(); return; }
    var p = fileProblem();
    if (p) { fail(p); input.focus(); return; }
    var btn = $('.m-tips__submit');
    btn.disabled = true; btn.textContent = 'পাঠানো হচ্ছে…';
    var fd = new FormData(form);
    var xhr = new XMLHttpRequest();
    xhr.open('POST', cfg.endpoint);
    xhr.withCredentials = false;
    xhr.setRequestHeader('Accept', 'application/json');
    if (input.files && input.files.length) {
      bar.hidden = false;
      xhr.upload.onprogress = function (ev) { if (ev.lengthComputable) bar.firstElementChild.style.width = Math.round(ev.loaded / ev.total * 100) + '%'; };
    }
    xhr.onload = function () {
      btn.disabled = false; btn.textContent = 'গোপনে পাঠান'; bar.hidden = true;
      var d = {};
      try { d = JSON.parse(xhr.responseText); } catch (x) { /* host error page */ }
      if (xhr.status >= 200 && xhr.status < 300 && d.code) {
        form.reset(); list.innerHTML = '';
        form.hidden = true;
        $('[data-code]', done).textContent = d.code;
        $('[data-pin]', done).textContent = d.pin;
        var link = $('[data-status-link]', done);
        link.href = d.status_url || (cfg.page + '?code=' + encodeURIComponent(d.code));
        done.hidden = false;
        done.focus();
        done.scrollIntoView({ block: 'start' });
        $('#m-tips-code').value = d.code;
        return;
      }
      fail(d.message || (xhr.status === 413 ? 'ফাইল বেশি বড় — কম বা ছোট ফাইল দিন।' : 'পাঠানো যায়নি (' + bn(xhr.status) + ')। একটু পরে আবার চেষ্টা করুন।'));
    };
    xhr.onerror = function () {
      btn.disabled = false; btn.textContent = 'গোপনে পাঠান'; bar.hidden = true;
      fail('ইন্টারনেট সংযোগে সমস্যা — লেখা মুছে যায়নি, আবার “গোপনে পাঠান” চাপুন।');
    };
    xhr.send(fd);
  });

  $('[data-copy-creds]', done).addEventListener('click', function () {
    var t = 'মিহা নিউজ — ট্র্যাকিং কোড: ' + $('[data-code]', done).textContent + ' · পিন: ' + $('[data-pin]', done).textContent;
    if (navigator.clipboard) navigator.clipboard.writeText(t).then(function () { this.textContent = 'কপি হয়েছে'; }.bind(this));
  });

  // status
  var cform = $('[data-checkform]');
  var cerr = $('[data-check-err]');
  var out = $('[data-status]');
  var creds = null;
  function post(url, data) {
    return fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(data), credentials: 'omit' })
      .then(function (r) { return r.json().catch(function () { return {}; }).then(function (d) { if (!r.ok) throw new Error(d.message || 'সমস্যা হয়েছে (' + bn(r.status) + ')'); return d; }); });
  }
  function el(tag, cls, text) { var x = document.createElement(tag); if (cls) x.className = cls; if (text != null) x.textContent = text; return x; }
  function when(iso) {
    try { return new Date(iso).toLocaleString('bn-BD', { day: 'numeric', month: 'long', hour: 'numeric', minute: '2-digit' }); } catch (e) { return ''; }
  }
  function render(d) {
    out.innerHTML = '';
    var h = el('p'); h.appendChild(el('span', 'm-tips__st m-tips__st--' + d.status, d.status_label));
    out.appendChild(h);
    out.appendChild(el('p', '', d.status_text));
    if (d.published_url) {
      var pa = el('a', 'm-btn', 'প্রকাশিত প্রতিবেদন পড়ুন'); pa.href = d.published_url; out.appendChild(pa);
    }
    out.appendChild(el('p', 'm-tips__hint', 'কোড ' + d.code + ' · ' + [d.category, d.district].filter(Boolean).join(' · ') + ' · পাঠিয়েছেন ' + when(d.created_at)));
    var ul = el('ul', 'm-tips__thread');
    (d.thread || []).forEach(function (m) {
      var li = el('li', 'm-tips__msg m-tips__msg--' + m.from);
      li.appendChild(el('small', '', m.who + ' · ' + when(m.at)));
      li.appendChild(document.createTextNode(m.body));
      ul.appendChild(li);
    });
    out.appendChild(ul);
    if (d.can_reply) {
      var f = el('form', 'm-tips__checkform');
      var lab = el('label', '', 'আরও কিছু জানাতে চান?'); lab.setAttribute('for', 'm-tips-fu');
      var ta = el('textarea', 'm-input'); ta.id = 'm-tips-fu'; ta.rows = 3; ta.maxLength = 5000;
      var fe = el('p', 'm-tips__err'); fe.hidden = true; fe.setAttribute('role', 'alert');
      var b = el('button', 'm-btn', 'পাঠান'); b.type = 'submit';
      f.appendChild(lab); f.appendChild(ta); f.appendChild(fe); f.appendChild(b);
      f.addEventListener('submit', function (ev) {
        ev.preventDefault();
        if (!ta.value.trim()) return;
        b.disabled = true;
        post(cfg.followup, { code: creds.code, pin: creds.pin, body: ta.value }).then(render).catch(function (x) { fe.textContent = x.message; fe.hidden = false; b.disabled = false; });
      });
      out.appendChild(f);
    }
    out.hidden = false;
  }
  cform.addEventListener('submit', function (e) {
    e.preventDefault();
    cerr.hidden = true;
    creds = { code: $('#m-tips-code').value.trim(), pin: $('#m-tips-pin').value.trim() };
    if (!creds.code || !creds.pin) { cerr.textContent = 'ট্র্যাকিং কোড ও পিন দুটোই লিখুন।'; cerr.hidden = false; return; }
    var b = cform.querySelector('button'); b.disabled = true;
    post(cfg.status, creds).then(function (d) { b.disabled = false; render(d); out.focus && out.setAttribute('tabindex', '-1'); out.focus(); })
      .catch(function (x) { b.disabled = false; cerr.textContent = x.message; cerr.hidden = false; out.hidden = true; });
  });
  if ($('#m-tips-code').value) $('#m-tips-pin').focus();
})();
