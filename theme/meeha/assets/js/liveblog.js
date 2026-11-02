/* Meeha — live posts (2.5.0). Keeps an open live page current without a reload:
 * polls meeha/v1/liveblog/{id} every ~30 s while the tab is visible (pauses when hidden, catches up on return),
 * inserts new updates (flash), replaces edited ones, drops deleted ones, refreshes the status / scoreboard / case
 * file, keeps "৫ মিনিট আগে" labels ticking, and offers filters, oldest-first order, older pages and per-update links. */
(function () {
	'use strict';
	var feed = document.querySelector('[data-lb]');
	if (!feed || !window.fetch) {
		return;
	}
	var api = feed.getAttribute('data-lb-api');
	var list = feed.querySelector('[data-lb-list]');
	var pins = feed.querySelector('[data-lb-pins]');
	var empty = feed.querySelector('[data-lb-empty]');
	var newBtn = feed.querySelector('[data-lb-new]');
	var moreBtn = feed.querySelector('[data-lb-more]');
	var topWrap = document.querySelector('[data-lb-top]');
	var status = feed.getAttribute('data-lb-status');
	var since = parseInt(feed.getAttribute('data-lb-since'), 10) || Math.floor(Date.now() / 1000);
	var timer = null;
	var delay = 30;
	var failures = 0;
	var unseen = 0;
	var BN = '০১২৩৪৫৬৭৮৯';

	function bn(n) {
		return String(n).replace(/[0-9]/g, function (d) { return BN[+d]; });
	}
	function ago(ts) {
		var s = Math.max(0, Math.floor(Date.now() / 1000) - ts);
		if (s < 60) { return 'এইমাত্র'; }
		var u = [[31536000, 'বছর'], [2592000, 'মাস'], [604800, 'সপ্তাহ'], [86400, 'দিন'], [3600, 'ঘণ্টা'], [60, 'মিনিট']];
		for (var i = 0; i < u.length; i++) {
			if (s >= u[i][0]) { return bn(Math.floor(s / u[i][0])) + ' ' + u[i][1] + ' আগে'; }
		}
		return 'এইমাত্র';
	}
	function tick() {
		var els = document.querySelectorAll('[data-ago]');
		for (var i = 0; i < els.length; i++) {
			els[i].textContent = ago(parseInt(els[i].getAttribute('data-ago'), 10));
		}
	}
	function toNode(html) {
		var t = document.createElement('template');
		t.innerHTML = html.trim();
		return t.content.firstElementChild;
	}
	function byId(id) {
		return feed.querySelector('.m-lb-entry[data-id="' + id + '"]');
	}
	/* Newest-first by time (then id) inside the stream; pinned ones live in their own box. */
	function place(node, pinned) {
		var box = pinned ? pins : list;
		var t = +node.getAttribute('data-t');
		var id = +node.getAttribute('data-id');
		var kids = box.children;
		for (var i = 0; i < kids.length; i++) {
			var kt = +kids[i].getAttribute('data-t');
			var kid = +kids[i].getAttribute('data-id');
			if (t > kt || (t === kt && id > kid)) {
				box.insertBefore(node, kids[i]);
				return;
			}
		}
		box.appendChild(node);
	}
	function sync() {
		pins.hidden = !pins.children.length;
		if (empty) { empty.hidden = !!(list.children.length || pins.children.length); }
	}
	function feedTopVisible() {
		var r = feed.getBoundingClientRect();
		return r.top > -40 && r.top < window.innerHeight;
	}
	function showNew() {
		if (!unseen || feedTopVisible()) {
			unseen = 0;
			newBtn.hidden = true;
			return;
		}
		newBtn.querySelector('span').textContent = bn(unseen) + 'টি নতুন আপডেট';
		newBtn.hidden = false;
	}

	function apply(data) {
		var known = {};
		(data.ids || []).forEach(function (id) { known[id] = 1; });
		var all = feed.querySelectorAll('.m-lb-entry');
		for (var i = 0; i < all.length; i++) {
			if (!known[all[i].getAttribute('data-id')]) { all[i].remove(); }
		}
		(data.entries || []).forEach(function (e) {
			var node = toNode(e.html);
			if (!node) { return; }
			var old = byId(e.id);
			if (old) {
				old.remove();
			} else {
				node.classList.add('is-new');
				unseen++;
			}
			place(node, e.pinned);
		});
		if (data.top && topWrap) {
			topWrap.innerHTML = data.top;
		}
		if (data.status && data.status !== status) {
			status = data.status;
			feed.setAttribute('data-lb-status', status);
			var badges = feed.querySelectorAll('.m-lb-feed__title .m-lb-badge');
			for (var b = 0; b < badges.length; b++) {
				badges[b].className = 'm-lb-badge m-lb-badge--' + status;
				badges[b].lastChild.textContent = { live: 'লাইভ', paused: 'বিরতি', ended: 'লাইভ শেষ' }[status] || '';
			}
		}
		sync();
		tick();
		showNew();
	}

	function schedule(sec) {
		clearTimeout(timer);
		timer = null;
		if (sec > 0 && !document.hidden) {
			timer = setTimeout(poll, sec * 1000);
		}
	}
	function poll() {
		timer = null;
		fetch(api + (api.indexOf('?') < 0 ? '?' : '&') + 'since=' + since, { credentials: 'omit', headers: { Accept: 'application/json' } })
			.then(function (r) {
				if (!r.ok) { throw new Error(r.status); }
				return r.json();
			})
			.then(function (data) {
				failures = 0;
				if (data.now) { since = data.now; }
				apply(data);
				delay = typeof data.next === 'number' ? data.next : delay;
				schedule(delay);
			})
			.catch(function () {
				failures++;
				schedule(Math.min(300, delay * Math.pow(2, failures)));
			});
	}

	document.addEventListener('visibilitychange', function () {
		if (document.hidden) {
			clearTimeout(timer);
			timer = null;
		} else if (!timer && 'ended' !== status) {
			poll();
		}
	});
	window.addEventListener('scroll', function () {
		if (!newBtn.hidden && feedTopVisible()) { showNew(); }
	}, { passive: true });
	newBtn.addEventListener('click', function () {
		unseen = 0;
		newBtn.hidden = true;
		feed.scrollIntoView({ behavior: 'smooth', block: 'start' });
	});

	/* filters + order */
	feed.addEventListener('click', function (ev) {
		var f = ev.target.closest('[data-lb-filter]');
		if (f) {
			var chips = feed.querySelectorAll('[data-lb-filter]');
			for (var i = 0; i < chips.length; i++) {
				var on = chips[i] === f;
				chips[i].classList.toggle('is-on', on);
				chips[i].setAttribute('aria-pressed', on ? 'true' : 'false');
			}
			feed.setAttribute('data-lb-view', f.getAttribute('data-lb-filter'));
			return;
		}
		var s = ev.target.closest('[data-lb-sort]');
		if (s) {
			var asc = !feed.classList.contains('is-asc');
			feed.classList.toggle('is-asc', asc);
			s.classList.toggle('is-on', asc);
			s.setAttribute('aria-pressed', asc ? 'true' : 'false');
			s.querySelector('span').textContent = asc ? 'নতুন আগে' : 'পুরনো আগে';
			return;
		}
		var c = ev.target.closest('[data-lb-copy]');
		if (c) {
			var url = location.href.split('#')[0] + '#lb-' + c.getAttribute('data-lb-copy');
			var done = function () {
				var lbl = c.querySelector('span');
				var was = lbl.textContent;
				lbl.textContent = 'কপি হয়েছে';
				setTimeout(function () { lbl.textContent = was; }, 1600);
			};
			if (navigator.share && window.matchMedia('(pointer: coarse)').matches) {
				navigator.share({ url: url, title: document.title }).catch(function () {});
			} else if (navigator.clipboard) {
				navigator.clipboard.writeText(url).then(done, function () {});
			}
		}
	});

	/* older updates */
	if (moreBtn) {
		moreBtn.addEventListener('click', function () {
			var last = list.lastElementChild;
			var before = last ? last.getAttribute('data-t') : since;
			moreBtn.disabled = true;
			fetch(api + (api.indexOf('?') < 0 ? '?' : '&') + 'before=' + before, { credentials: 'omit' })
				.then(function (r) { return r.json(); })
				.then(function (data) {
					(data.entries || []).forEach(function (e) {
						if (!byId(e.id)) {
							var n = toNode(e.html);
							if (n) { list.appendChild(n); }
						}
					});
					tick();
					if (!data.more) { moreBtn.remove(); } else { moreBtn.disabled = false; }
				})
				.catch(function () { moreBtn.disabled = false; });
		});
	}

	/* a shared #lb-123 link: open at that update even if it is on an older page */
	if (/^#lb-\d+$/.test(location.hash) && !document.querySelector(location.hash)) {
		feed.scrollIntoView();
	}

	setInterval(tick, 60000);
	if ('ended' !== status) {
		schedule(status === 'paused' ? 90 : 30);
	} else {
		var auto = document.querySelector('[data-lb-auto]');
		if (auto) { auto.classList.add('is-off'); }
	}
})();
