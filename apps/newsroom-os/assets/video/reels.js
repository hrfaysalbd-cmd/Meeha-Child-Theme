/* Meeha News — ভিডিও রিলস: feed + playback core.
   Hydrates NROS_Video_Frontend::render_reels() markup and talks to
   nros/v1/public/videos*. Comments + share live in reels-panel.js. No deps. */
(() => {
	'use strict';
	const root = document.getElementById('mvr');
	if (!root || root.mvr) return;

	const CFG = window.NROS_VIDEO || {};
	const $ = (s, el) => (el || root).querySelector(s);
	const $$ = (s, el) => Array.from((el || root).querySelectorAll(s));
	const BN = '০১২৩৪৫৬৭৮৯';
	const bn = (v) => String(v).replace(/\d/g, (d) => BN[d]);
	/* 999 → ৯৯৯, 1543 → ১.৫হা, 250000 → ২.৫লা (floored, same as PHP) */
	const compact = (n) => {
		n = Math.max(0, n | 0);
		if (n < 1e3) return bn(n);
		const big = n >= 1e5;
		const v = Math.floor(n / (big ? 1e4 : 1e2)) / 10;
		return bn(String(v)) + (big ? 'লা' : 'হা');
	};
	const store = (type, k, v) => {
		try {
			const st = window[type];
			if (v === undefined) return JSON.parse(st.getItem(k));
			st.setItem(k, JSON.stringify(v));
		} catch (e) { /* storage blocked */ }
		return null;
	};
	const LS = (k, v) => store('localStorage', k, v);
	const SS = (k, v) => store('sessionStorage', k, v);

	const feed = $('.mvr-feed');
	const endEl = $('.mvr-end');
	const soundBtn = $('.mvr-sound');
	const info = $('.mvr-info');
	const mode = root.dataset.mode;
	const startId = +root.dataset.start || 0;
	const phone = window.matchMedia('(max-width: 767px), (hover: none) and (max-height: 500px)'); // PHONE_MQ (reels.css)
	const reduce = window.matchMedia('(prefers-reduced-motion: reduce)');

	let items = [];
	try { items = JSON.parse($('.mvr-json').textContent) || []; } catch (e) { items = []; }
	const slides = $$('.mvr-slide');
	if (!slides.length || !feed || slides.length !== items.length) return;
	root.classList.replace('mvr-nojs', 'is-js');

	const tplSlide = slides[0].cloneNode(true);
	const firstTitle = document.title;

	let hasMore = root.dataset.more === '1';
	let loading = false;
	let active = -1;
	let userPaused = false;
	let inView = true;
	let watched = 0;
	let soundOn = SS('mvr_sound') === 1;
	const liked = new Set(LS('mvr_liked') || []);
	const viewed = new Set(SS('mvr_viewed') || []);
	const yts = new Map(); // slide element → { p, ready, state }

	document.documentElement.classList.add('mvr-lock');
	const emit = (name, detail) => root.dispatchEvent(new CustomEvent('mvr:' + name, { detail }));

	/* ---------------- API ---------------- */
	const api = async (path, body, q, withNonce) => {
		let u = (CFG.rest || '/wp-json/nros/v1/') + path;
		if (q) u += (u.indexOf('?') > -1 ? '&' : '?') + new URLSearchParams(q).toString();
		const opt = { credentials: 'same-origin', headers: { Accept: 'application/json' } };
		if (body) {
			opt.method = 'POST';
			opt.headers['Content-Type'] = 'application/json';
			opt.body = JSON.stringify(body);
		}
		if (withNonce && CFG.nonce) opt.headers['X-WP-Nonce'] = CFG.nonce;
		let r;
		try { r = await fetch(u, opt); } catch (e) {
			throw new Error('ইন্টারনেট সংযোগ পাওয়া যাচ্ছে না। সংযোগ দেখে আবার চেষ্টা করুন।');
		}
		let d = null;
		try { d = await r.json(); } catch (e) { d = null; }
		if (!r.ok) {
			const er = new Error((d && d.message) || 'সার্ভারে সমস্যা হয়েছে। আবার চেষ্টা করুন।');
			er.status = r.status;
			throw er;
		}
		return d;
	};

	/* ---------------- small helpers ---------------- */
	const toastEl = $('.mvr-toast');
	let toastT = 0;
	const toast = (msg) => {
		toastEl.textContent = msg;
		toastEl.classList.add('is-on');
		clearTimeout(toastT);
		toastT = setTimeout(() => toastEl.classList.remove('is-on'), 2400);
	};
	const fixAvatar = (img) => {
		if (!img) return;
		const kill = () => img.remove();
		if (img.complete && !img.naturalWidth && img.getAttribute('src')) kill();
		else img.addEventListener('error', kill, { once: true });
	};
	const setAv = (av, a) => {
		if (!av) return;
		av.dataset.n = (a.name || '').trim().charAt(0);
		let img = av.querySelector('img');
		if (!a.avatar) { if (img) img.remove(); return; }
		if (!img) { img = document.createElement('img'); img.alt = ''; img.decoding = 'async'; av.appendChild(img); }
		img.src = a.avatar;
		fixAvatar(img);
	};
	const media = (i) => slides[i] && slides[i].querySelector('.mvr-media');
	const isYT = (i) => { const m = media(i); return !!m && m.classList.contains('mvr-yt'); };

	/* ---------------- slide setup ---------------- */
	function setup(s, i) {
		const v = s.querySelector('video.mvr-media');
		if (v) {
			v.removeAttribute('controls');
			v.addEventListener('waiting', () => s.classList.add('is-wait'));
			['playing', 'canplay', 'pause'].forEach((ev) => v.addEventListener(ev, () => s.classList.remove('is-wait')));
			v.addEventListener('loadedmetadata', () => {
				const r = v.videoWidth / (v.videoHeight || 1);
				s.querySelector('.mvr-stage').classList.toggle('is-fit', r > 0.7);
			});
			v.addEventListener('error', () => { if (v.getAttribute('src')) failed(+s.dataset.i); });
		}
		$$('.mvr-av img', s).forEach(fixAvatar);
		renderStats(i);
		requestAnimationFrame(() => checkCap(s));
	}
	function checkCap(s) {
		const cap = s.querySelector('.mvr-cap');
		const ex = s.querySelector('.mvr-ex');
		if (!cap || !ex || cap.classList.contains('is-open')) return;
		const t = s.querySelector('.mvr-title');
		const short = !ex.textContent.trim()
			? t.scrollHeight - t.clientHeight < 4
			: ex.scrollHeight - ex.clientHeight < 4 && t.scrollHeight - t.clientHeight < 4;
		cap.classList.toggle('is-short', short);
	}
	function failed(i) {
		const s = slides[i];
		if (!s || s.classList.contains('is-error')) return;
		s.classList.remove('is-wait');
		s.classList.add('is-error');
		emit('failed', { i });
	}

	function fill(s, it, i) {
		s.className = 'mvr-slide';
		s.dataset.i = i;
		s.dataset.id = it.id;
		s.setAttribute('aria-label', it.title);
		s.setAttribute('aria-posinset', i + 1);
		const stage = s.querySelector('.mvr-stage');
		stage.className = 'mvr-stage' + (it.orientation === 'landscape' ? ' is-land' : '');
		const bg = s.querySelector('.mvr-bg');
		bg.removeAttribute('style');
		if (it.thumbnail) bg.dataset.bg = it.thumbnail; else delete bg.dataset.bg;
		let m;
		if (it.source === 'youtube' && it.youtube_id) {
			m = document.createElement('div');
			m.className = 'mvr-media mvr-yt';
			m.dataset.yt = it.youtube_id;
			if (it.thumbnail) {
				const im = document.createElement('img');
				im.alt = ''; im.decoding = 'async'; im.dataset.src = it.thumbnail;
				m.appendChild(im);
			}
		} else {
			m = document.createElement('video');
			m.className = 'mvr-media';
			m.muted = true; m.loop = true; m.playsInline = true; m.preload = 'none';
			['playsinline', 'muted', 'loop', 'disablepictureinpicture'].forEach((a) => m.setAttribute(a, ''));
			m.dataset.src = it.video_url || '';
			if (it.thumbnail) m.dataset.poster = it.thumbnail;
		}
		s.querySelector('.mvr-media').replaceWith(m);
		const cap = s.querySelector('.mvr-cap');
		cap.className = 'mvr-cap';
		setAv(s.querySelector('.mvr-by .mvr-av'), it.author || {});
		s.querySelector('.mvr-name').textContent = (it.author && it.author.name) || '';
		s.querySelector('.mvr-when').textContent = it.date_human || '';
		const a = s.querySelector('.mvr-title a');
		a.href = it.link; a.textContent = it.title;
		s.querySelector('.mvr-ex').textContent = it.excerpt || '';
		const more = s.querySelector('.mvr-more');
		more.textContent = 'আরও'; more.setAttribute('aria-expanded', 'false');
		s.querySelector('.mvr-prog').style.removeProperty('--p');
		return s;
	}

	function addItem(it) {
		const i = items.length;
		items.push(it);
		const s = fill(tplSlide.cloneNode(true), it, i);
		feed.insertBefore(s, endEl);
		slides.push(s);
		setup(s, i);
		io.observe(s);
		emit('added', { i });
	}

	/* ---------------- stats ---------------- */
	function renderStats(i) {
		const it = items[i]; const s = slides[i];
		if (!it || !s) return;
		const st = it.stats;
		const on = liked.has(it.id);
		const like = s.querySelector('.mvr-like');
		like.setAttribute('aria-pressed', on ? 'true' : 'false');
		like.setAttribute('aria-label', (on ? 'পছন্দ তুলে নিন' : 'পছন্দ করুন') + ' (' + bn(st.likes) + 'টি পছন্দ)');
		like.querySelector('.mvr-n').textContent = compact(st.likes);
		const c = s.querySelector('.mvr-cmt');
		c.querySelector('.mvr-n').textContent = compact(st.comments);
		c.setAttribute('aria-label', 'মন্তব্য দেখুন (' + bn(st.comments) + 'টি মন্তব্য)');
		const sh = s.querySelector('.mvr-shr');
		sh.querySelector('.mvr-n').textContent = compact(st.shares);
		sh.setAttribute('aria-label', 'শেয়ার করুন (' + bn(st.shares) + ' বার শেয়ার হয়েছে)');
		if (i === active) renderInfo(i);
	}
	function renderInfo(i) {
		const it = items[i];
		const tn = $('.mvr-tab-n');
		if (tn) tn.textContent = compact(it.stats.comments);
		const n = $('.mvr-cm-n');
		if (n) n.textContent = it.stats.comments ? bn(it.stats.comments) : '';
		if (!info) return;
		$('.mvr-info-title', info).textContent = it.title;
		$('.mvr-info-name', info).textContent = (it.author && it.author.name) || '';
		$('.mvr-info-when', info).textContent = it.date_human || '';
		$('.mvr-info-ex', info).textContent = it.excerpt || '';
		setAv($('.mvr-av', info), it.author || {});
		['views', 'likes', 'shares'].forEach((k) => { $('[data-k="' + k + '"]', info).textContent = compact(it.stats[k]); });
	}

	/* ---------------- media loading ---------------- */
	function load(i) {
		const s = slides[i];
		if (!s) return;
		const bg = s.querySelector('.mvr-bg');
		if (bg.dataset.bg) { bg.style.setProperty('--bg', 'url("' + bg.dataset.bg.replace(/"/g, '%22') + '")'); delete bg.dataset.bg; }
		const v = media(i);
		if (!v) return;
		if (v.tagName === 'VIDEO') {
			if (v.dataset.poster) { v.poster = v.dataset.poster; delete v.dataset.poster; }
			v.preload = i === active ? 'auto' : 'metadata';
			if (v.dataset.src && !v.getAttribute('src')) v.src = v.dataset.src;
		} else {
			const im = v.querySelector('img[data-src]');
			if (im) { im.src = im.dataset.src; delete im.dataset.src; }
		}
	}
	function unload(i) {
		const v = media(i);
		if (!v) return;
		if (v.tagName === 'VIDEO') {
			if (v.getAttribute('src')) {
				if (!v.dataset.src) v.dataset.src = v.getAttribute('src');
				v.pause(); v.removeAttribute('src'); v.load();
			}
		} else ytDestroy(i);
	}

	/* ---------------- YouTube (official IFrame API) ---------------- */
	let ytApi = null;
	function ytLib() {
		if (window.YT && window.YT.Player) return Promise.resolve(window.YT);
		if (ytApi) return ytApi;
		ytApi = new Promise((res, rej) => {
			const prev = window.onYouTubeIframeAPIReady;
			window.onYouTubeIframeAPIReady = () => {
				if (typeof prev === 'function') { try { prev(); } catch (e) { /* other widget */ } }
				res(window.YT);
			};
			const sc = document.createElement('script');
			sc.src = 'https://www.youtube.com/iframe_api';
			sc.async = true;
			sc.onerror = () => { ytApi = null; rej(new Error('yt')); };
			document.head.appendChild(sc);
		});
		return ytApi;
	}
	function yt(i) { return yts.get(slides[i]); }
	function ytEnsure(i) {
		const s = slides[i];
		let rec = yts.get(s);
		if (rec) return rec;
		rec = { p: null, ready: false, state: -1 };
		yts.set(s, rec);
		const box = media(i);
		const slot = document.createElement('div');
		box.appendChild(slot);
		ytLib().then((YT) => {
			if (yts.get(s) !== rec) return;
			const id = box.dataset.yt;
			rec.p = new YT.Player(slot, {
				host: 'https://www.youtube-nocookie.com',
				videoId: id,
				width: '100%',
				height: '100%',
				playerVars: { autoplay: 0, mute: 1, playsinline: 1, controls: 0, rel: 0, modestbranding: 1, iv_load_policy: 3, loop: 1, playlist: id, disablekb: 1, fs: 0, enablejsapi: 1, origin: location.origin },
				events: {
					onReady: () => {
						rec.ready = true;
						const f = rec.p.getIframe && rec.p.getIframe();
						if (f) { f.tabIndex = -1; f.title = items[+s.dataset.i].title; f.setAttribute('allow', 'autoplay; encrypted-media; picture-in-picture'); }
						const k = +s.dataset.i;
						if (k === active && !userPaused && inView && !document.hidden) play(k); else rec.p.pauseVideo();
					},
					onStateChange: (e) => {
						rec.state = e.data;
						s.classList.toggle('is-wait', e.data === 3);
						if (e.data === 1) { box.classList.add('is-on'); if (+s.dataset.i === active) tick(); }
					},
					onError: () => failed(+s.dataset.i),
				},
			});
		}).catch(() => failed(i));
		return rec;
	}
	function ytDestroy(i) {
		const s = slides[i];
		const rec = yts.get(s);
		if (!rec) return;
		yts.delete(s);
		try { if (rec.p && rec.p.destroy) rec.p.destroy(); } catch (e) { /* already gone */ }
		const box = media(i);
		$$('iframe, div', box).forEach((n) => n.remove());
		box.classList.remove('is-on');
	}

	/* ---------------- playback ---------------- */
	function play(i) {
		const s = slides[i];
		if (!s || s.classList.contains('is-error')) return;
		s.classList.remove('is-paused');
		load(i);
		if (isYT(i)) {
			const rec = ytEnsure(i);
			if (!rec.ready) return; // onReady starts it
			if (soundOn) rec.p.unMute(); else rec.p.mute();
			rec.p.playVideo();
			if (soundOn) {
				// Unmuted autoplay can be refused by the browser: fall back to muted.
				setTimeout(() => {
					if (i === active && yt(i) === rec && rec.state !== 1 && rec.state !== 3 && !userPaused) {
						rec.p.mute(); rec.p.playVideo(); setSound(false, true);
					}
				}, 1500);
			}
			tick();
			return;
		}
		const v = media(i);
		v.muted = !soundOn;
		const p = v.play();
		if (p && p.catch) {
			p.catch((err) => {
				if (err && err.name === 'AbortError') return;
				if (!v.muted) {
					v.muted = true;
					setSound(false, true);
					v.play().catch(() => { if (i === active) s.classList.add('is-paused'); });
				} else if (i === active) s.classList.add('is-paused');
			});
		}
		tick();
	}
	function pause(i, byUser) {
		const s = slides[i];
		if (!s) return;
		if (byUser) s.classList.add('is-paused');
		if (isYT(i)) { const rec = yt(i); if (rec && rec.ready) rec.p.pauseVideo(); } else media(i).pause();
	}
	function isPlaying(i) {
		if (i < 0 || slides[i].classList.contains('is-paused')) return false;
		if (isYT(i)) { const rec = yt(i); return !!rec && rec.state === 1; }
		const v = media(i);
		return !v.paused && v.readyState >= 3;
	}
	function togglePlay(i) {
		if (i !== active) { go(i); return; }
		if (slides[i].classList.contains('is-paused')) { userPaused = false; play(i); } else { userPaused = true; pause(i, true); }
	}

	function setProg(i, p) {
		const b = slides[i] && slides[i].querySelector('.mvr-prog');
		if (b) b.style.setProperty('--p', Math.min(1, Math.max(0, p || 0)).toFixed(4));
	}
	let raf = 0;
	function tick() {
		cancelAnimationFrame(raf);
		const loop = () => {
			if (active < 0) return;
			const k = active;
			if (isYT(k)) {
				const rec = yt(k);
				if (!rec || !rec.ready) return;
				const d = rec.p.getDuration ? rec.p.getDuration() : 0;
				if (d) setProg(k, rec.p.getCurrentTime() / d);
				if (rec.state === 1 || rec.state === 3) raf = requestAnimationFrame(loop);
				return;
			}
			const v = media(k);
			if (v.duration) setProg(k, v.currentTime / v.duration);
			if (!v.paused) raf = requestAnimationFrame(loop);
		};
		raf = requestAnimationFrame(loop);
	}

	function setSound(on, transient) {
		soundOn = on;
		if (!transient) SS('mvr_sound', on ? 1 : 0);
		soundBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
		soundBtn.setAttribute('aria-label', on ? 'শব্দ বন্ধ করুন' : 'শব্দ চালু করুন');
		soundBtn.classList.remove('is-hint');
		if (active < 0) return;
		if (isYT(active)) {
			const rec = yt(active);
			if (rec && rec.ready) { if (on) rec.p.unMute(); else rec.p.mute(); }
		} else media(active).muted = !on;
	}

	/* ---------------- activation ---------------- */
	function setActive(i) {
		if (i === active || !slides[i]) return;
		const prev = active;
		active = i;
		userPaused = false;
		watched = 0;
		if (prev > -1 && slides[prev]) { pause(prev); slides[prev].classList.remove('is-active', 'is-paused'); }
		slides[i].classList.add('is-active');
		// Only the active video and the next one load; YouTube players exist only while active.
		slides.forEach((s, k) => {
			if (k === i || k === i + 1) load(k);
			if ((k !== i && isYT(k)) || k < i - 1 || k > i + 1) unload(k);
		});
		if (inView && !document.hidden) play(i);
		updateUrl(i);
		renderInfo(i);
		updateNav();
		emit('active', { i });
		if (hasMore && i >= items.length - 3) loadMore();
	}
	function updateNav() {
		const p = $('.mvr-prev'); const n = $('.mvr-next');
		if (p) p.disabled = active <= 0;
		if (n) n.disabled = active >= slides.length - 1 && !hasMore;
	}
	function updateUrl(i) {
		const it = items[i];
		try {
			if (mode === 'single') {
				history.replaceState(history.state, '', it.link);
				document.title = i === 0 ? firstTitle : it.title + ' – ' + (CFG.site || '');
			} else {
				const u = new URL(location.href);
				u.searchParams.set('v', it.id);
				history.replaceState(history.state, '', u.toString());
			}
		} catch (e) { /* sandboxed */ }
	}
	function go(i) {
		if (i >= slides.length && hasMore) { loadMore().then(() => { if (slides[i]) go(i); }); return; }
		if (i < 0 || i >= slides.length) return;
		feed.scrollTo({ top: slides[i].offsetTop - slides[0].offsetTop, behavior: reduce.matches ? 'auto' : 'smooth' });
	}

	let pendT = 0;
	const io = new IntersectionObserver((entries) => {
		entries.forEach((en) => {
			if (en.isIntersecting && en.intersectionRatio >= 0.6) {
				const k = +en.target.dataset.i;
				clearTimeout(pendT);
				pendT = setTimeout(() => setActive(k), active < 0 ? 0 : 80);
			}
		});
	}, { root: feed, threshold: [0.6] });
	slides.forEach((s, i) => { setup(s, i); io.observe(s); });

	new IntersectionObserver(([en]) => {
		inView = en.intersectionRatio >= 0.35;
		if (active < 0) return;
		if (!inView) pause(active); else if (!userPaused) play(active);
	}, { threshold: [0, 0.35, 0.7] }).observe(feed);
	document.addEventListener('visibilitychange', () => {
		if (active < 0) return;
		if (document.hidden) pause(active); else if (inView && !userPaused) play(active);
	});

	/* views: one POST after ~3 s of real playback (server dedupes per IP/day) */
	setInterval(() => {
		if (document.hidden || !inView || !isPlaying(active)) return;
		watched += 0.5;
		const it = items[active];
		if (watched >= 3 && !viewed.has(it.id)) {
			viewed.add(it.id);
			SS('mvr_viewed', Array.from(viewed).slice(-300));
			const i = active;
			api('public/videos/' + it.id + '/view', {}).then((r) => {
				if (r && typeof r.views === 'number') { it.stats.views = r.views; renderStats(i); }
			}).catch(() => {});
		}
	}, 500);

	/* ---------------- infinite load ---------------- */
	async function loadMore() {
		if (loading || !hasMore) return;
		loading = true;
		feed.setAttribute('aria-busy', 'true');
		endEl.textContent = 'আরও ভিডিও আসছে…';
		try {
			const q = { offset: items.length, limit: 10 };
			if (startId) q.start = startId;
			const d = await api('public/videos', null, q);
			const fresh = ((d && d.items) || []).filter((x) => !items.some((y) => y.id === x.id));
			fresh.forEach(addItem);
			hasMore = !!(d && d.has_more) && fresh.length > 0;
			endEl.textContent = hasMore ? '' : 'আপাতত এই পর্যন্তই। নতুন ভিডিও এলে এখানে পাবেন।';
			if (active > -1) load(active + 1);
		} catch (e) {
			endEl.textContent = '';
			const b = document.createElement('button');
			b.type = 'button'; b.className = 'mvr-loadmore mvr-retry'; b.textContent = 'আরও ভিডিও আনা যায়নি — আবার চেষ্টা করুন';
			endEl.appendChild(b);
		} finally {
			loading = false;
			feed.setAttribute('aria-busy', 'false');
			updateNav();
		}
	}

	let tapT = 0; let tapTimer = 0;
	function onTap(e, i) {
		if (!e.detail) { togglePlay(i); return; } // keyboard activation
		const now = Date.now();
		if (now - tapT < 300) {
			clearTimeout(tapTimer);
			tapT = 0;
			if (i === active) emit('like', { i, on: true, x: e.clientX, y: e.clientY });
			return;
		}
		tapT = now;
		tapTimer = setTimeout(() => togglePlay(i), 260);
	}

	/* ---------------- events ---------------- */
	const plain = (e) => !(e.button || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey);
	root.addEventListener('click', (e) => {
		const t = e.target.closest('button, a');
		if (!t || !root.contains(t)) return;
		const slide = t.closest('.mvr-slide');
		const i = slide ? +slide.dataset.i : active;
		const c = t.classList;
		if (c.contains('mvr-tap')) onTap(e, i);
		else if (c.contains('mvr-like')) emit('like', { i });
		else if (c.contains('mvr-cmt')) { if (i !== active) go(i); emit('comments', { i }); }
		else if (c.contains('mvr-shr')) emit('share', { i });
		else if (c.contains('mvr-more') || (t.closest('.mvr-title') && plain(e))) {
			e.preventDefault();
			const cap = t.closest('.mvr-cap');
			const o = cap.classList.toggle('is-open');
			const m = cap.querySelector('.mvr-more');
			m.textContent = o ? 'কম দেখুন' : 'আরও';
			m.setAttribute('aria-expanded', o ? 'true' : 'false');
		}
		else if (c.contains('mvr-prev')) go(active - 1);
		else if (c.contains('mvr-next')) go(active + 1);
		else if (c.contains('mvr-sound')) setSound(!soundOn);
		else if (c.contains('mvr-retry')) { endEl.textContent = ''; loadMore(); }
		else if (c.contains('mvr-back') && plain(e) && document.referrer.indexOf(location.origin + '/') === 0 && history.length > 1) { e.preventDefault(); history.back(); }
	});

	function seek(i, p) {
		setProg(i, p);
		if (isYT(i)) {
			const rec = yt(i);
			if (rec && rec.ready && rec.p.getDuration()) rec.p.seekTo(p * rec.p.getDuration(), true);
		} else if (media(i).duration) media(i).currentTime = p * media(i).duration;
	}

	document.addEventListener('keydown', (e) => {
		if (e.defaultPrevented || e.altKey || e.ctrlKey || e.metaKey) return;
		const tg = e.target;
		if (tg && tg.closest && tg.closest('input, textarea, select, [contenteditable="true"]')) return;
		if (root.classList.contains('has-sheet') || !inView || active < 0) return;
		const k = e.key;
		if (k === 'ArrowDown' || k === 'PageDown') { e.preventDefault(); go(active + 1); }
		else if (k === 'ArrowUp' || k === 'PageUp') { e.preventDefault(); go(active - 1); }
		else if (k === ' ' || k === 'Spacebar') {
			if (tg && tg.closest && tg.closest('button') && !tg.classList.contains('mvr-tap')) return;
			e.preventDefault(); togglePlay(active);
		} else if (k === 'm' || k === 'M' || k === 'ম') setSound(!soundOn);
	});

	window.addEventListener('resize', () => slides.forEach(checkCap), { passive: true });
	if (document.fonts && document.fonts.ready) document.fonts.ready.then(() => slides.forEach(checkCap));

	setSound(soundOn, true);
	if (!soundOn && phone.matches) {
		soundBtn.classList.add('is-hint');
		setTimeout(() => soundBtn.classList.remove('is-hint'), 4200);
	}
	updateNav();

	/* shared with reels-panel.js */
	root.mvr = {
		CFG, api, toast, bn, compact, LS, phone, reduce, items, slides, liked, renderStats, setAv, go, seek,
		get active() { return active; },
		isUserPaused: () => userPaused,
	};
})();
