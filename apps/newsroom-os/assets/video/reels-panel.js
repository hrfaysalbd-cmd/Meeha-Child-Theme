/* Meeha News — ভিডিও রিলস: engagement — likes, comments (bottom sheet on
   phone, side panel on desktop) and sharing. Needs reels.js (root.mvr). No deps. */
(() => {
	'use strict';
	const root = document.getElementById('mvr');
	const R = root && root.mvr;
	if (!R || root.dataset.panel) return;
	root.dataset.panel = '1';

	const $ = (s, el) => (el || root).querySelector(s);
	const $$ = (s, el) => Array.from((el || root).querySelectorAll(s));
	const { api, toast, bn, items, phone } = R;
	const side = $('.mvr-side');
	const cm = $('.mvr-cm');
	const list = $('.mvr-cm-list');
	const form = $('form.mvr-form');
	const shareEl = $('.mvr-share');
	const scrim = $('.mvr-scrim');
	cm.hidden = false;
	shareEl.hidden = false;

	/* ---------------- sheets ---------------- */
	let lastFocus = null;
	let openEl = null;
	const focusables = (el) => $$('a[href], button:not([disabled]), input:not([tabindex="-1"]), textarea', el).filter((n) => n.offsetParent !== null);
	function openSheet(el) {
		if (openEl && openEl !== el) closeSheets(true);
		lastFocus = document.activeElement;
		openEl = el;
		scrim.hidden = false;
		el.classList.add('is-open');
		el.setAttribute('aria-modal', 'true');
		root.classList.add('has-sheet');
		setTimeout(() => { const x = $('.mvr-x', el); if (x) x.focus({ preventScroll: true }); }, 60);
	}
	function closeSheets(silent) {
		if (!openEl) return;
		openEl.classList.remove('is-open');
		if (openEl === cm) cm.setAttribute('aria-modal', 'false');
		openEl = null;
		scrim.hidden = true;
		root.classList.remove('has-sheet');
		if (!silent && lastFocus && lastFocus.focus) lastFocus.focus({ preventScroll: true });
		lastFocus = null;
	}
	scrim.addEventListener('click', () => closeSheets());
	document.addEventListener('keydown', (e) => {
		if (!openEl) return;
		if (e.key === 'Escape') { e.preventDefault(); closeSheets(); return; }
		if (e.key !== 'Tab') return;
		const f = focusables(openEl);
		if (!f.length) return;
		const first = f[0]; const last = f[f.length - 1];
		if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
		else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
	});
	// Switching phone ↔ desktop layout with a sheet open: close it cleanly.
	const onLayout = () => { if (openEl === cm) closeSheets(true); };
	if (phone.addEventListener) phone.addEventListener('change', onLayout);

	function setTab(t, focus) {
		side.classList.toggle('is-c', t === 'c');
		$$('.mvr-tab').forEach((b) => {
			const on = b.dataset.tab === t;
			b.setAttribute('aria-selected', on ? 'true' : 'false');
			b.tabIndex = on ? 0 : -1;
			if (on && focus) b.focus();
		});
		if (t === 'c') loadComments(R.active, true);
	}
	$('.mvr-tabs').addEventListener('keydown', (e) => {
		if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
		e.preventDefault();
		setTab(side.classList.contains('is-c') ? 'q' : 'c', true);
	});

	/* ---------------- comments ---------------- */
	let cmFor = 0; let cmOffset = 0;
	function commentEl(c, fresh) {
		const el = document.createElement('div');
		el.className = 'mvr-c' + (fresh ? ' is-new' : '');
		const av = document.createElement('span');
		av.className = 'mvr-av';
		R.setAv(av, { name: c.author || 'পাঠক', avatar: c.avatar });
		const body = document.createElement('div');
		const h = document.createElement('div'); h.className = 'mvr-c-h';
		const b = document.createElement('b'); b.textContent = c.author || 'পাঠক';
		const t = document.createElement('span'); t.textContent = c.date_human || '';
		h.append(b, t);
		const p = document.createElement('p'); p.className = 'mvr-c-t'; p.textContent = c.content || '';
		body.append(h, p);
		el.append(av, body);
		return el;
	}
	function empty() {
		const d = document.createElement('div');
		d.className = 'mvr-c-none';
		const b = document.createElement('b');
		b.textContent = 'এখনো কোনো মন্তব্য নেই';
		d.append(b, 'প্রথম মন্তব্যটি আপনিই লিখুন।');
		return d;
	}
	async function loadComments(i, reset) {
		const it = items[i];
		if (!it) return;
		if (reset) {
			if (cmFor === it.id && list.childElementCount) return;
			cmFor = it.id; cmOffset = 0;
			list.innerHTML = '<div class="mvr-skel"></div><div class="mvr-skel"></div><div class="mvr-skel"></div>';
			list.setAttribute('aria-busy', 'true');
		}
		const id = it.id;
		try {
			const d = await api('public/videos/' + id + '/comments', null, { offset: cmOffset });
			if (cmFor !== id) return;
			if (reset) list.textContent = '';
			const lm = $('.mvr-cmore', list); if (lm) lm.remove();
			const arr = (d && d.items) || [];
			arr.forEach((c) => list.appendChild(commentEl(c)));
			cmOffset += arr.length;
			const total = (d && d.total) | 0;
			if (!list.querySelector('.mvr-c')) list.appendChild(empty());
			if (cmOffset < total && arr.length) {
				const b = document.createElement('button');
				b.type = 'button'; b.className = 'mvr-loadmore mvr-cmore'; b.textContent = 'আরও মন্তব্য দেখুন';
				list.appendChild(b);
			}
			it.stats.comments = Math.max(total, list.querySelectorAll('.mvr-c').length);
			R.renderStats(i);
		} catch (e) {
			if (cmFor !== id) return;
			cmFor = 0;
			list.textContent = '';
			const d = document.createElement('div');
			d.className = 'mvr-c-none';
			const b = document.createElement('button');
			b.type = 'button'; b.className = 'mvr-loadmore mvr-cretry'; b.textContent = 'আবার চেষ্টা করুন';
			d.append('মন্তব্য লোড করা যায়নি। ', b);
			list.appendChild(d);
		} finally {
			list.setAttribute('aria-busy', 'false');
		}
	}
	function openComments(i) {
		if (phone.matches) openSheet(cm); else setTab('c');
		loadComments(i, true);
	}

	if (form) initForm();
	function initForm() {
		const ta = form.elements.content;
		const msg = (t, err) => { const m = $('.mvr-msg', form); m.textContent = t; m.classList.toggle('is-err', !!err); };
		const grow = () => { ta.style.height = 'auto'; ta.style.height = Math.min(ta.scrollHeight + 2, 120) + 'px'; };
		ta.addEventListener('input', grow);
		ta.addEventListener('keydown', (e) => {
			if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); if (form.requestSubmit) form.requestSubmit(); }
		});
		if (R.CFG.user) {
			form.classList.add('is-user');
			const as = document.createElement('p'); as.className = 'mvr-as'; as.textContent = '‘' + R.CFG.user + '’ নামে মন্তব্য করছেন';
			form.insertBefore(as, form.firstChild);
		} else {
			const who = R.LS('mvr_who') || {};
			if (who.n) form.elements.author_name.value = who.n;
			if (who.e) form.elements.author_email.value = who.e;
		}
		async function freshNonce() {
			if (!R.CFG.ajax) return false;
			try {
				const r = await fetch(R.CFG.ajax + '?action=rest-nonce', { credentials: 'same-origin' });
				const t = (await r.text()).trim();
				if (r.ok && /^[a-f0-9]{10}$/.test(t)) { R.CFG.nonce = t; return true; }
			} catch (e) { /* offline */ }
			return false;
		}
		function asGuest() {
			R.CFG.user = ''; R.CFG.nonce = '';
			form.classList.remove('is-user');
			const as = $('.mvr-as', form); if (as) as.remove();
		}
		let sending = false;
		form.addEventListener('submit', async (e) => {
			e.preventDefault();
			if (sending) return;
			const i = items.findIndex((x) => x.id === cmFor);
			const it = items[i >= 0 ? i : R.active];
			const name = form.elements.author_name.value.trim();
			const email = form.elements.author_email.value.trim();
			const content = ta.value.trim();
			if (!R.CFG.user && !name) { msg('আপনার নাম লিখুন।', true); form.elements.author_name.focus(); return; }
			if (!content) { msg('মন্তব্য লিখুন।', true); ta.focus(); return; }
			if (!R.CFG.user && !email && form.elements.author_email.required) { msg('ইমেইল ঠিকানা লিখুন (প্রকাশ করা হবে না)।', true); form.elements.author_email.focus(); return; }
			if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { msg('ইমেইল ঠিকানাটি সঠিক নয়।', true); form.elements.author_email.focus(); return; }
			const send = $('.mvr-send', form);
			sending = true; send.disabled = true;
			msg('পাঠানো হচ্ছে…');
			try {
				const post = () => api('public/videos/' + it.id + '/comments', {
					author_name: name, author_email: email, content, website: form.elements.website.value,
				}, null, true);
				let d;
				try { d = await post(); } catch (er) {
					// A logged-in reader's nonce can expire while the tab stays open: the
					// server then treats them as a guest (no name → 400). Refresh it once.
					if (!(R.CFG.user && er.status === 400 && !name)) throw er;
					if (await freshNonce()) d = await post();
					else { asGuest(); throw new Error('লগইন সেশন শেষ হয়ে গেছে। নাম লিখে মন্তব্য করুন অথবা আবার লগইন করুন।'); }
				}
				ta.value = ''; grow();
				if (!R.CFG.user) R.LS('mvr_who', { n: name, e: email });
				if (d && d.comment) {
					if (cmFor === it.id) {
						const no = $('.mvr-c-none', list); if (no) no.remove();
						list.insertBefore(commentEl(d.comment, true), list.firstChild);
						list.scrollTop = 0;
					}
					it.stats.comments += 1;
					R.renderStats(items.indexOf(it));
					msg('মন্তব্য প্রকাশিত হয়েছে।');
				} else {
					msg('ধন্যবাদ! মন্তব্যটি অনুমোদনের পর প্রকাশিত হবে।');
				}
			} catch (er) {
				msg(er.message || 'মন্তব্য পাঠানো যায়নি। আবার চেষ্টা করুন।', true);
			} finally {
				sending = false; send.disabled = false;
			}
		});
	}

	/* ---------------- share ---------------- */
	let shareIdx = 0;
	function track(i, net) {
		const it = items[i];
		if (!it) return;
		api('public/videos/' + it.id + '/share', { network: net }).then((r) => {
			if (r && typeof r.shares === 'number') { it.stats.shares = r.shares; R.renderStats(i); }
		}).catch(() => {});
	}
	function share(i) {
		const it = items[i];
		if (navigator.share && phone.matches) {
			navigator.share({ title: it.title, url: it.link }).then(() => track(i, 'copy')).catch((er) => {
				if (!er || er.name !== 'AbortError') { shareIdx = i; openSheet(shareEl); }
			});
			return;
		}
		shareIdx = i;
		openSheet(shareEl);
	}
	async function copy(text) {
		try { await navigator.clipboard.writeText(text); return true; } catch (e) {
			const t = document.createElement('textarea');
			t.value = text; t.setAttribute('readonly', ''); t.style.cssText = 'position:fixed;top:0;opacity:0';
			document.body.appendChild(t); t.select();
			let ok = false;
			try { ok = document.execCommand('copy'); } catch (er) { ok = false; }
			t.remove();
			return ok;
		}
	}
	function shareTo(net, i) {
		const it = items[i];
		if (!it) return;
		const u = encodeURIComponent(it.link); const t = encodeURIComponent(it.title);
		const links = {
			facebook: 'https://www.facebook.com/sharer/sharer.php?u=' + u,
			whatsapp: 'https://wa.me/?text=' + t + '%20' + u,
			x: 'https://twitter.com/intent/tweet?text=' + t + '&url=' + u,
		};
		if (net === 'copy') {
			copy(it.link).then((ok) => {
				toast(ok ? 'লিংক কপি হয়েছে' : 'লিংক কপি করা যায়নি');
				if (ok) track(i, 'copy');
			});
		} else if (links[net]) {
			window.open(links[net], '_blank', 'noopener,width=640,height=600');
			track(i, net);
		} else return;
		if (openEl === shareEl) closeSheets();
	}

	/* ---------------- "আরও ভিডিও" queue (desktop side panel) ---------------- */
	const queue = $('.mvr-queue');
	const tplQ = queue && queue.firstElementChild ? queue.firstElementChild.cloneNode(true) : null;
	root.addEventListener('mvr:added', (e) => {
		if (!tplQ) return;
		const i = e.detail.i;
		const it = items[i];
		const li = tplQ.cloneNode(true);
		const b = li.querySelector('.mvr-qi');
		b.dataset.i = i; b.href = it.link; b.classList.remove('is-on'); b.removeAttribute('aria-current');
		const qt = li.querySelector('.mvr-qt');
		qt.className = 'mvr-qt' + (it.orientation === 'landscape' ? ' is-land' : '');
		qt.textContent = '';
		if (it.thumbnail) {
			const im = document.createElement('img');
			im.alt = ''; im.loading = 'lazy'; im.decoding = 'async'; im.width = 68; im.height = 120; im.src = it.thumbnail;
			qt.appendChild(im);
		}
		li.querySelector('.mvr-qh').textContent = it.title;
		const qm = li.querySelectorAll('.mvr-qm');
		qm[0].textContent = R.compact(it.stats.views) + ' বার দেখা হয়েছে';
		qm[1].textContent = it.date_human || '';
		queue.appendChild(li);
	});
	function syncQueue(i) {
		if (!queue) return;
		$$('.mvr-qi', queue).forEach((b) => {
			const on = +b.dataset.i === i;
			b.classList.toggle('is-on', on);
			if (on) b.setAttribute('aria-current', 'true'); else b.removeAttribute('aria-current');
		});
		const cur = $('.mvr-qi.is-on', queue);
		if (cur && queue.offsetParent) {
			const qr = queue.getBoundingClientRect(); const cr = cur.getBoundingClientRect();
			if (cr.top < qr.top || cr.bottom > qr.bottom) queue.scrollTo({ top: cur.parentNode.offsetTop - 8, behavior: R.reduce.matches ? 'auto' : 'smooth' });
		}
	}
	if (R.active > -1) syncQueue(R.active);
	if (queue) {
		queue.addEventListener('click', (e) => {
			const a = e.target.closest('.mvr-qi');
			if (!a || e.button || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
			e.preventDefault();
			R.go(+a.dataset.i);
		});
	}

	/* ---------------- playback extras ---------------- */
	root.addEventListener('mvr:failed', (e) => {
		const i = e.detail.i;
		const it = items[i];
		const box = document.createElement('div');
		box.className = 'mvr-err';
		box.setAttribute('role', 'alert');
		const p = document.createElement('p');
		p.textContent = 'ভিডিওটি এই ব্রাউজারে চালানো যাচ্ছে না।';
		const a = document.createElement('a');
		const yt = it.source === 'youtube' && it.youtube_id;
		a.href = yt ? 'https://www.youtube.com/watch?v=' + it.youtube_id : it.video_url;
		a.target = '_blank'; a.rel = 'noopener';
		a.textContent = yt ? 'ইউটিউবে দেখুন' : 'সরাসরি ভিডিও খুলুন';
		box.append(p, a);
		R.slides[i].querySelector('.mvr-stage').appendChild(box);
	});
	// Seek by tapping/dragging the progress bar of the active video.
	root.addEventListener('pointerdown', (e) => {
		const bar = e.target.closest('.mvr-prog');
		if (!bar) return;
		const i = +bar.closest('.mvr-slide').dataset.i;
		if (i !== R.active) return;
		e.preventDefault();
		bar.setPointerCapture(e.pointerId);
		bar.classList.add('is-drag');
		const move = (ev) => {
			const r = bar.getBoundingClientRect();
			R.seek(i, Math.min(1, Math.max(0, (ev.clientX - r.left) / r.width)));
		};
		move(e);
		const up = () => { bar.classList.remove('is-drag'); bar.removeEventListener('pointermove', move); };
		bar.addEventListener('pointermove', move);
		bar.addEventListener('pointerup', up, { once: true });
		bar.addEventListener('pointercancel', up, { once: true });
	});

	/* ---------------- like (optimistic, remembered in localStorage) ---------------- */
	function burst(i, x, y) {
		if (R.reduce.matches) return;
		const stage = R.slides[i].querySelector('.mvr-stage');
		const r = stage.getBoundingClientRect();
		const h = document.createElement('span');
		h.className = 'mvr-heart';
		h.style.left = (x ? x - r.left : r.width / 2) + 'px';
		h.style.top = (y ? y - r.top : r.height / 2) + 'px';
		h.style.setProperty('--r', (Math.random() * 30 - 15).toFixed(1) + 'deg');
		h.innerHTML = R.slides[i].querySelector('.mvr-like .mvr-i').outerHTML;
		stage.appendChild(h);
		setTimeout(() => h.remove(), 900);
	}
	function toggleLike(i, forceOn) {
		const it = items[i];
		const liked = R.liked;
		const on = forceOn || !liked.has(it.id);
		if (on === liked.has(it.id)) return;
		const save = () => R.LS('mvr_liked', Array.from(liked).slice(-500));
		if (on) liked.add(it.id); else liked.delete(it.id);
		save();
		it.stats.likes = Math.max(0, it.stats.likes + (on ? 1 : -1));
		R.renderStats(i);
		const btn = R.slides[i].querySelector('.mvr-like');
		btn.classList.remove('is-bump'); void btn.offsetWidth; btn.classList.add('is-bump');
		api('public/videos/' + it.id + '/like', { action: on ? 'like' : 'unlike' }).then((r) => {
			if (r && typeof r.likes === 'number') { it.stats.likes = r.likes; R.renderStats(i); }
		}).catch(() => {
			if (on) liked.delete(it.id); else liked.add(it.id);
			save();
			it.stats.likes = Math.max(0, it.stats.likes + (on ? -1 : 1));
			R.renderStats(i);
			toast('পছন্দ সংরক্ষণ করা যায়নি। আবার চেষ্টা করুন।');
		});
	}

	/* ---------------- events ---------------- */
	root.addEventListener('mvr:like', (e) => {
		const d = e.detail;
		if (d.on) burst(d.i, d.x, d.y);
		toggleLike(d.i, d.on);
	});
	root.addEventListener('mvr:comments', (e) => openComments(e.detail.i));
	root.addEventListener('mvr:share', (e) => share(e.detail.i));
	root.addEventListener('mvr:active', (e) => {
		syncQueue(e.detail.i);
		if (side.classList.contains('is-c') || openEl === cm) loadComments(e.detail.i, true);
	});
	root.addEventListener('click', (e) => {
		const t = e.target.closest('button');
		if (!t || !root.contains(t)) return;
		const c = t.classList;
		if (c.contains('mvr-tab')) setTab(t.dataset.tab);
		else if (t.dataset.net) shareTo(t.dataset.net, shareEl.contains(t) ? shareIdx : R.active);
		else if (c.contains('mvr-x')) closeSheets();
		else if (c.contains('mvr-cretry')) loadComments(R.active, true);
		else if (c.contains('mvr-cmore')) loadComments(items.findIndex((x) => x.id === cmFor), false);
	});
})();
