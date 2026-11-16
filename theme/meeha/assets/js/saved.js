/* r46 — পরে পড়ুন: save stories in this browser (localStorage), no account. */
(function () {
  'use strict';
  var KEY = 'meeha_saved';
  var CFG = window.meehaSaved || {};
  var MAX = 200;
  var store = {
    all: function () {
      try {
        var v = JSON.parse(localStorage.getItem(KEY) || '[]');
        return Array.isArray(v) ? v.filter(function (x) { return x && x.id > 0; }) : [];
      } catch (e) { return []; }
    },
    set: function (list) {
      try { localStorage.setItem(KEY, JSON.stringify(list.slice(0, MAX))); } catch (e) { /* private mode */ }
    },
    has: function (id) { return store.all().some(function (x) { return x.id === id; }); },
    toggle: function (id) {
      var list = store.all();
      var i = list.findIndex(function (x) { return x.id === id; });
      if (i >= 0) { list.splice(i, 1); } else { list.unshift({ id: id, t: Date.now() }); }
      store.set(list);
      return i < 0;
    }
  };
  try { localStorage.setItem(KEY + '_t', '1'); localStorage.removeItem(KEY + '_t'); } catch (e) { return; } // no storage: keep buttons hidden

  var bn = function (n) { return String(n).replace(/[0-9]/g, function (d) { return '০১২৩৪৫৬৭৮৯'[d]; }); };

  function paint(btn) {
    var id = +btn.getAttribute('data-save');
    var on = store.has(id);
    btn.hidden = false;
    btn.setAttribute('aria-pressed', on ? 'true' : 'false');
    var t = btn.querySelector('.m-save__t');
    if (t) t.textContent = on ? 'সংরক্ষিত' : 'পরে পড়ুন';
    btn.title = on ? 'সংরক্ষিত — আবার চাপলে সরবে' : 'পরে পড়ুন';
  }
  function count() {
    var n = store.all().length;
    document.querySelectorAll('[data-saved-count]').forEach(function (el) {
      el.hidden = !n;
      el.textContent = n > 99 ? '৯৯+' : bn(n);
      var a = el.closest('.m-savedlink');
      if (a) {
        a.classList.toggle('has-items', n > 0);
        a.setAttribute('aria-label', n ? 'পরে পড়ুন — ' + bn(n) + 'টি সংরক্ষিত খবর' : 'পরে পড়ুন — সংরক্ষিত খবর');
      }
    });
  }
  var toastTimer;
  function toast(on) {
    var el = document.querySelector('.m-save-toast');
    if (!el) {
      el = document.createElement('div');
      el.className = 'm-save-toast';
      el.setAttribute('role', 'status');
      document.body.appendChild(el);
    }
    el.innerHTML = '';
    el.appendChild(document.createTextNode(on ? 'পরে পড়ার তালিকায় রাখা হলো' : 'তালিকা থেকে সরানো হলো'));
    if (on && CFG.page) {
      var a = document.createElement('a');
      a.href = CFG.page; a.textContent = 'তালিকা দেখুন';
      el.appendChild(a);
    }
    el.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { el.hidden = true; }, 3200);
  }

  // Article page: a labelled button in the share bar(s).
  if (document.body.classList.contains('single')) {
    document.querySelectorAll('.m-share[data-post-id]').forEach(function (bar) {
      if (bar.querySelector('[data-save]')) return;
      var tpl = document.querySelector('.m-savedlink .m-save__ic');
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'm-save m-save--share';
      b.setAttribute('data-save', bar.getAttribute('data-post-id'));
      b.innerHTML = (tpl ? tpl.outerHTML : '') + '<span class="m-save__t">পরে পড়ুন</span>';
      bar.appendChild(b);
    });
  }

  function paintAll() { document.querySelectorAll('[data-save]').forEach(paint); count(); }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest && e.target.closest('[data-save]');
    if (!btn) return;
    e.preventDefault();
    var id = +btn.getAttribute('data-save');
    var on = store.toggle(id);
    document.querySelectorAll('[data-save="' + id + '"]').forEach(paint);
    count();
    toast(on);
    if (!on && btn.closest('[data-saved-list]')) {
      var card = btn.closest('.m-story');
      if (card) card.remove();
      if (!document.querySelector('[data-saved-list] .m-story')) renderEmpty();
    }
  });
  window.addEventListener('storage', function (e) { if (e.key === KEY) paintAll(); });

  // /saved/
  var page = document.querySelector('[data-saved-page]');
  var listEl = page && page.querySelector('[data-saved-list]');
  function renderEmpty() {
    if (!listEl) return;
    listEl.innerHTML = '<p class="m-savedpage__empty">এখনো কিছু সংরক্ষণ করেননি। যেকোনো খবরের পাশে বুকমার্ক চিহ্নে চাপুন — সেটি এখানে থাকবে।</p>';
    var tools = page.querySelector('.m-savedpage__tools');
    if (tools) tools.hidden = true;
  }
  if (listEl) {
    var ids = store.all().map(function (x) { return x.id; });
    if (!ids.length) { renderEmpty(); }
    else {
      fetch(CFG.api + (CFG.api.indexOf('?') < 0 ? '?' : '&') + 'ids=' + ids.slice(0, 100).join(','), { credentials: 'omit' })
        .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
        .then(function (d) {
          if (d.missing && d.missing.length) { // deleted / unpublished: forget them
            store.set(store.all().filter(function (x) { return d.missing.indexOf(x.id) < 0; }));
          }
          if (!d.items || !d.items.length) { renderEmpty(); return; }
          listEl.innerHTML = d.items.map(function (it) { return it.html; }).join('');
          page.querySelector('.m-savedpage__tools').hidden = false;
          paintAll();
        })
        .catch(function () { listEl.innerHTML = '<p class="m-savedpage__empty">তালিকা আনা গেল না। ইন্টারনেট সংযোগ দেখে পাতাটি আবার খুলুন।</p>'; });
    }
    var clear = page.querySelector('[data-saved-clear]');
    if (clear) clear.addEventListener('click', function () {
      if (!window.confirm('সংরক্ষিত সব খবর তালিকা থেকে মুছবেন?')) return;
      store.set([]);
      renderEmpty(); paintAll();
    });
  }
  paintAll();
})();
