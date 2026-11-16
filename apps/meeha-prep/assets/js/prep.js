/**
 * Meeha Prep front end: exam timer + autosave, daily quiz, saved jobs, share, print-order total.
 * Every feature enhances a plain HTML form that already works without JavaScript.
 */
(function () {
	'use strict';

	var C = window.MHP || {};
	var BN = ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'];

	function bn(s) {
		return String(s).replace(/\d/g, function (d) { return BN[d]; });
	}
	function mmss(s) {
		s = Math.max(0, Math.floor(s));
		var m = Math.floor(s / 60), r = s % 60;
		return bn(m + ':' + (r < 10 ? '0' : '') + r);
	}
	function $(sel, root) { return (root || document).querySelector(sel); }
	function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

	/* ---------------- REST with one nonce refresh ---------------- */

	function refreshNonce() {
		return fetch(C.ajax + '?action=mhp_nonce', { credentials: 'same-origin', cache: 'no-store' })
			.then(function (r) { return r.json(); })
			.then(function (j) {
				if (j && j.success) { C.nonce = j.data.nonce; C.logged = !!j.data.logged; }
				return C.nonce;
			});
	}

	function api(path, body, retried) {
		var h = { 'Content-Type': 'application/json' };
		if (C.nonce) { h['X-WP-Nonce'] = C.nonce; }
		return fetch(C.rest + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: h,
			body: JSON.stringify(body || {})
		}).then(function (r) {
			return r.json().catch(function () { return {}; }).then(function (j) {
				if (r.status === 403 && j && j.code === 'rest_cookie_invalid_nonce' && !retried) {
					return refreshNonce().then(function () { return api(path, body, true); });
				}
				return { status: r.status, ok: r.ok, data: j };
			});
		});
	}

	/* ---------------- Exam runner ---------------- */

	function runner(form) {
		var id = form.getAttribute('data-attempt');
		var total = parseInt(form.getAttribute('data-total'), 10) || 0;
		var deadline = Date.now() + (parseInt(form.getAttribute('data-left'), 10) || 0) * 1000;
		var timerEl = $('[data-mc-timer]', form);
		var stateEl = $('[data-mc-save-state]', form);
		var submitting = false, dirty = false, saveTimer = null, saving = false;

		function answers() {
			var out = {};
			$$('input[type=radio]:checked', form).forEach(function (el) {
				var m = el.name.match(/^ans\[(\d+)\]$/);
				if (m) { out[m[1]] = parseInt(el.value, 10); }
			});
			return out;
		}

		function paint() {
			var a = answers(), n = Object.keys(a).length;
			var cnt = $('[data-mc-answered]', form);
			if (cnt) { cnt.textContent = bn(n); }
			var bar = $('[data-mc-progress]', form);
			if (bar) { bar.style.width = (total ? Math.round(n / total * 100) : 0) + '%'; }
			$$('[data-mc-palette] a', form).forEach(function (el) {
				el.classList.toggle('is-on', a.hasOwnProperty(el.getAttribute('data-i')));
			});
			$$('.mc-opt', form).forEach(function (lab) {
				var inp = $('input', lab);
				if (inp) { lab.classList.toggle('is-sel', inp.checked); }
			});
			$$('[data-mc-clear]', form).forEach(function (b) {
				b.hidden = !a.hasOwnProperty(b.getAttribute('data-mc-clear'));
			});
		}

		function submit() {
			if (submitting) { return; }
			submitting = true;
			window.removeEventListener('beforeunload', guard);
			HTMLFormElement.prototype.submit.call(form);
		}

		function save() {
			if (!dirty || saving || submitting) { return; }
			saving = true;
			dirty = false;
			if (stateEl) { stateEl.textContent = 'সংরক্ষণ হচ্ছে…'; }
			api('attempts/' + id + '/save', { answers: answers() }).then(function (res) {
				saving = false;
				if (res.status === 409) { submit(); return; }
				if (res.ok && res.data && typeof res.data.left === 'number') {
					deadline = Date.now() + res.data.left * 1000;
					if (stateEl) { stateEl.textContent = 'উত্তর সংরক্ষিত'; }
				} else {
					dirty = true;
					if (stateEl) { stateEl.textContent = 'সংরক্ষণ করা যায়নি, আবার চেষ্টা হবে। জমা দিলে উত্তর ঠিকই যাবে।'; }
				}
			}).catch(function () {
				saving = false;
				dirty = true;
				if (stateEl) { stateEl.textContent = 'ইন্টারনেট সংযোগ নেই। উত্তরগুলো এই পাতায় আছে; সংযোগ ফিরলে সংরক্ষণ হবে।'; }
			});
		}

		function guard(e) {
			if (submitting) { return; }
			e.preventDefault();
			e.returnValue = '';
		}

		function tick() {
			var left = (deadline - Date.now()) / 1000;
			if (timerEl) {
				timerEl.textContent = mmss(left);
				timerEl.classList.toggle('is-low', left <= 300);
			}
			if (left <= 0) { submit(); return; }
			setTimeout(tick, 1000 - (Date.now() % 1000));
		}

		form.addEventListener('change', function () {
			paint();
			dirty = true;
			clearTimeout(saveTimer);
			saveTimer = setTimeout(save, 1500);
		});
		form.addEventListener('click', function (e) {
			var b = e.target.closest('[data-mc-clear]');
			if (!b) { return; }
			$$('input[name="ans[' + b.getAttribute('data-mc-clear') + ']"]', form).forEach(function (r) { r.checked = false; });
			form.dispatchEvent(new Event('change'));
		});
		form.addEventListener('submit', function (e) {
			if (submitting) { return; }
			var left = total - Object.keys(answers()).length;
			var msg = left > 0 ? bn(left) + 'টি প্রশ্নের উত্তর বাকি। তবুও জমা দেবেন?' : 'উত্তরপত্র জমা দেবেন?';
			if (!window.confirm(msg)) { e.preventDefault(); return; }
			submitting = true;
			window.removeEventListener('beforeunload', guard);
		});
		window.addEventListener('beforeunload', guard);
		setInterval(save, 30000);
		document.addEventListener('visibilitychange', function () { if (document.hidden) { save(); } });
		paint();
		tick();
	}

	/* ---------------- Daily quiz ---------------- */

	function quiz(list) {
		var total = parseInt(list.getAttribute('data-total'), 10) || 0;
		var guest = list.getAttribute('data-guest') === '1';
		var key = 'mhp_quiz_' + list.getAttribute('data-date');
		var store = {};
		if (guest) {
			try { store = JSON.parse(window.localStorage.getItem(key) || '{}') || {}; } catch (e) { store = {}; }
		}

		function show(li, r) {
			$$('.mc-opt', li).forEach(function (b) {
				var c = parseInt(b.getAttribute('data-choice'), 10);
				b.disabled = true;
				b.classList.toggle('is-right', c === r.answer);
				b.classList.toggle('is-wrong', c === r.chosen && c !== r.answer);
			});
			li.classList.add('is-done');
			var ex = $('[data-mc-explain]', li);
			if (ex && r.explain) { ex.textContent = r.explain; ex.hidden = false; }
		}

		function finish(score, done) {
			var sc = $('[data-mc-quiz-score]');
			if (sc && score !== null) { sc.textContent = 'স্কোর ' + bn(score) + '/' + bn(total); }
			if (done) {
				var box = $('[data-mc-quiz-done]');
				var fin = $('[data-mc-quiz-final]');
				if (fin && score !== null) { fin.textContent = bn(score) + '/' + bn(total) + ' সঠিক'; }
				if (box) { box.hidden = false; }
			}
		}

		// Guests: replay what this browser answered today.
		if (guest) {
			var g = 0, n = 0;
			Object.keys(store).forEach(function (i) {
				var li = $('li[data-i="' + i + '"]', list);
				if (li) { show(li, store[i]); n++; if (store[i].correct) { g++; } }
			});
			if (n) { finish(g, n >= total); }
		}

		list.addEventListener('submit', function (e) {
			var form = e.target;
			var li = form.closest('li[data-i]');
			var btn = e.submitter || $('button:focus', form);
			if (!li || !btn || li.classList.contains('is-done')) { return; }
			e.preventDefault();
			var i = parseInt(li.getAttribute('data-i'), 10);
			var choice = parseInt(btn.getAttribute('data-choice'), 10);
			$$('.mc-opt', li).forEach(function (b) { b.disabled = true; });
			api('quiz/answer', { i: i, choice: choice }).then(function (res) {
				if (!res.ok || !res.data || typeof res.data.answer !== 'number') {
					$$('.mc-opt', li).forEach(function (b) { b.disabled = false; });
					window.alert((res.data && res.data.message) || 'উত্তর পাঠানো যায়নি। আবার চেষ্টা করুন।');
					return;
				}
				var r = res.data;
				show(li, r);
				if (guest) {
					store[i] = { answer: r.answer, chosen: r.chosen, correct: r.correct, explain: r.explain };
					try { window.localStorage.setItem(key, JSON.stringify(store)); } catch (err) { /* private mode */ }
					var c = 0, k = Object.keys(store);
					k.forEach(function (x) { if (store[x].correct) { c++; } });
					finish(c, k.length >= total);
				} else {
					finish(r.score, r.done);
				}
				var next = li.nextElementSibling;
				if (next && !next.classList.contains('is-done')) {
					setTimeout(function () { next.scrollIntoView({ behavior: 'smooth', block: 'center' }); }, 900);
				}
			}).catch(function () {
				$$('.mc-opt', li).forEach(function (b) { b.disabled = false; });
				window.alert('ইন্টারনেট সংযোগ দেখুন।');
			});
		});
	}

	/* ---------------- Saved jobs ---------------- */

	function saveJobs() {
		document.addEventListener('submit', function (e) {
			var form = e.target.closest && e.target.closest('form[data-mhp-save]');
			if (!form || !C.logged) { return; }
			e.preventDefault();
			var btn = $('button', form);
			btn.disabled = true;
			api('jobs/' + form.getAttribute('data-mhp-save') + '/save', {}).then(function (res) {
				btn.disabled = false;
				if (res.status === 401) { window.location.href = C.login; return; }
				if (!res.ok) { return; }
				var on = !!res.data.saved;
				$$('form[data-mhp-save="' + form.getAttribute('data-mhp-save') + '"] button').forEach(function (b) {
					b.classList.toggle('is-on', on);
					b.setAttribute('aria-pressed', on ? 'true' : 'false');
					b.setAttribute('aria-label', on ? 'সংরক্ষিত (সরাতে চাপুন)' : 'সংরক্ষণ করুন');
					var t = $('span', b);
					if (t) { t.textContent = on ? 'সংরক্ষিত' : 'সংরক্ষণ'; }
				});
			}).catch(function () {
				btn.disabled = false;
				HTMLFormElement.prototype.submit.call(form);
			});
		});
	}

	/* ---------------- Share ---------------- */

	function share() {
		document.addEventListener('click', function (e) {
			var b = e.target.closest && e.target.closest('[data-mc-share]');
			if (!b) { return; }
			var url = b.getAttribute('data-url') || window.location.href.split('#')[0];
			var text = b.getAttribute('data-text') || document.title;
			if (navigator.share) {
				navigator.share({ title: document.title, text: text, url: url }).catch(function () {});
				return;
			}
			if (navigator.clipboard) {
				navigator.clipboard.writeText(url).then(function () {
					var old = b.getAttribute('aria-label');
					b.setAttribute('data-copied', '1');
					b.setAttribute('aria-label', 'লিংক কপি হয়েছে');
					setTimeout(function () { b.removeAttribute('data-copied'); if (old) { b.setAttribute('aria-label', old); } }, 2000);
				});
			} else {
				window.prompt('লিংকটি কপি করুন', url);
			}
		});
	}

	// Print order: show the total (books × copies + delivery) as the reader fills the form. The server recomputes it.
	function pubTotal(form) {
		var out = $('[data-mc-total]', form);
		if (!out) { return; }
		var price = parseFloat(form.getAttribute('data-price')) || 0;
		var fd = parseFloat(form.getAttribute('data-fd')) || 0;
		var fo = parseFloat(form.getAttribute('data-fo')) || 0;
		var free = parseFloat(form.getAttribute('data-free')) || 0;
		function tk(n) { return '৳' + bn(Math.round(n).toLocaleString('en-IN')); }
		function paint() {
			var qty = parseInt((form.elements.qty || {}).value, 10) || 1;
			var dist = (form.elements.district || {}).value || '';
			var zone = $('input[name="zone"]:checked', form);
			var inside = zone && zone.value === 'dhaka' && (dist === '' || dist === 'ঢাকা');
			var sub = price * qty;
			var fee = (free > 0 && sub >= free) ? 0 : (inside ? fd : fo);
			out.textContent = 'বই ' + tk(sub) + ' + ডেলিভারি ' + tk(fee) + ' = মোট ' + tk(sub + fee);
			out.hidden = false;
		}
		form.addEventListener('change', paint);
		paint();
	}

	function init() {
		$$('[data-mc-pubform]').forEach(pubTotal);
		var run = document.getElementById('mc-run');
		if (run) { runner(run); }
		var q = $('[data-mc-quiz]');
		if (q) { quiz(q); }
		saveJobs();
		share();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
