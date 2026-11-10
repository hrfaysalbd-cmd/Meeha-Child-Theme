/* newsroom-os 1.22.0 — [meeha_app_landing] behaviour. No dependencies. */
(function () {
	'use strict';
	var root = document.getElementById('mhl');
	if (!root) return;
	root.classList.add('js');

	var cfg = {};
	try { cfg = JSON.parse(root.getAttribute('data-cfg') || '{}'); } catch (e) {}
	var ua = navigator.userAgent || '';
	var isIOS = /iPhone|iPad|iPod/i.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
	var isAndroid = /Android/i.test(ua);
	var bn = function (s) { return String(s).replace(/\d/g, function (d) { return '০১২৩৪৫৬৭৮৯'[d]; }); };

	// iPhone: the APK can't be installed — point to the web app instead.
	if (isIOS) {
		var ios = root.querySelector('[data-mhl-ios]');
		if (ios) ios.hidden = false;
	}

	// Newest version from android.json (via the plugin's cached /app/android).
	if (cfg.info && window.fetch) {
		fetch(cfg.info, { credentials: 'omit' }).then(function (r) { return r.ok ? r.json() : null; }).then(function (j) {
			if (!j || !j.version_name) return;
			root.querySelectorAll('[data-mhl-ver]').forEach(function (el) { el.textContent = ' · ভার্সন ' + bn(j.version_name); });
		}).catch(function () {});
	}

	// Toast after a download tap (Android), so people know what comes next.
	var toast = root.querySelector('[data-mhl-toast]');
	var tt;
	root.querySelectorAll('[data-mhl-dl]').forEach(function (a) {
		a.addEventListener('click', function () {
			if (window.dataLayer) window.dataLayer.push({ event: 'meeha_app_download' });
			if (!toast || isIOS) return;
			toast.hidden = false;
			clearTimeout(tt);
			tt = setTimeout(function () { toast.hidden = true; }, 9000);
		});
	});
	if (toast) toast.addEventListener('click', function () { toast.hidden = true; });

	// Reveal on scroll.
	var rv = root.querySelectorAll('.mhl-card, .mhl-shot, .mhl-steps li, .mhl-safe-list li, .mhl-faq details, .mhl-band, .mhl-qr, .mhl-strip > div');
	if ('IntersectionObserver' in window) {
		var io = new IntersectionObserver(function (es) {
			es.forEach(function (e) { if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); } });
		}, { rootMargin: '0px 0px -8% 0px' });
		rv.forEach(function (el, i) {
			el.classList.add('mhl-rv');
			el.style.transitionDelay = (i % 3) * 70 + 'ms';
			io.observe(el);
		});

		// Sticky download bar: shown once the hero button has scrolled away,
		// hidden again while another download button is on screen.
		var bar = root.querySelector('[data-mhl-sticky]');
		var btns = root.querySelectorAll('.mhl-btn[data-mhl-dl]');
		var seen = new Set();
		var passedHero = false;
		var hero = root.querySelector('.mhl-hero .mhl-btn');
		var upd = function () {
			if (!bar) return;
			var on = !isIOS && passedHero && seen.size === 0;
			bar.classList.toggle('on', on);
			bar.setAttribute('aria-hidden', on ? 'false' : 'true');
			var a = bar.querySelector('a');
			if (a) a.tabIndex = on ? 0 : -1;
		};
		var io2 = new IntersectionObserver(function (es) {
			es.forEach(function (e) {
				if (e.isIntersecting) seen.add(e.target); else seen.delete(e.target);
				if (e.target === hero && !e.isIntersecting) passedHero = e.boundingClientRect.top < 0;
			});
			upd();
		});
		btns.forEach(function (b) { io2.observe(b); });
	} else {
		rv.forEach(function (el) { el.classList.add('in'); });
	}

	// Screenshot dots follow the swipe.
	var track = root.querySelector('[data-mhl-shots]');
	var dots = root.querySelectorAll('[data-mhl-dots] span');
	if (track && dots.length) {
		var raf;
		track.addEventListener('scroll', function () {
			cancelAnimationFrame(raf);
			raf = requestAnimationFrame(function () {
				var items = track.children, mid = track.scrollLeft + track.clientWidth / 2, best = 0, bd = Infinity;
				for (var i = 0; i < items.length; i++) {
					var c = items[i].offsetLeft + items[i].offsetWidth / 2, d = Math.abs(c - mid);
					if (d < bd) { bd = d; best = i; }
				}
				dots.forEach(function (d, i) { d.classList.toggle('on', i === best); });
			});
		}, { passive: true });
	}

})();
