/**
 * Meeha Studio: the editor screen.
 *
 * Left: controls (post picker, image, text, style, frame, date, ad).
 * Right: live canvas preview, size switch, download and save.
 */
(function () {
	'use strict';

	const C = window.MeehaStudio || {};
	const R = window.MeehaRender;
	const root = document.getElementById('meeha-studio-app');
	if (!root || !R) return;

	const STORE = 'meehaStudio.v1.' + (C.user || 0);

	// ------------------------------------------------------------------
	// Fields shown per template
	// ------------------------------------------------------------------
	const HIGHLIGHT_HINT = 'যে শব্দগুলো ফুটিয়ে তুলতে চান সেগুলো **দুই তারকা** দিয়ে ঘিরে দিন।';
	const FIELDS = {
		news: [['category', 'ক্যাটেগরি'], ['title', 'শিরোনাম', 'area', HIGHLIGHT_HINT], ['excerpt', 'সারাংশ (পোর্ট্রেট ও স্টোরিতে দেখাবে)', 'area'], ['credit', 'ছবি: (আলোকচিত্রী)']],
		breaking: [['title', 'শিরোনাম', 'area', HIGHLIGHT_HINT], ['time', 'সময় (যেমন ১০:৪২)']],
		feature: [['label', 'কিকার (যেমন মিহা স্পেশাল)'], ['title', 'শিরোনাম', 'area', HIGHLIGHT_HINT]],
		opinion: [['label', 'লেবেল (মতামত, সাক্ষাৎকার, বিশ্লেষণ)'], ['quote', 'বক্তার মূল কথা', 'area', HIGHLIGHT_HINT], ['author', 'লেখক বা বক্তার নাম'], ['designation', 'উপাধি'], ['title', 'লেখার শিরোনাম (উক্তি খালি থাকলে এটি বসবে)', 'area']],
		quote: [['label', 'লেবেল (উক্তি, ধর্ম, দর্শন…)'], ['quote', 'উক্তি', 'area', HIGHLIGHT_HINT], ['author', 'যাঁর উক্তি'], ['designation', 'পরিচয়']],
		literature: [['label', 'ধরন (গল্প, প্রবন্ধ, স্মৃতিকথা…)'], ['title', 'শিরোনাম'], ['author', 'লেখক'], ['excerpt', 'অংশবিশেষ', 'area']],
		poem: [['label', 'লেবেল (কবিতা, ছড়া, গান…)'], ['title', 'কবিতার নাম'], ['author', 'কবি'], ['poem', 'কবিতা (প্রতিটি লাইন আলাদা লাইনে, স্তবকের মাঝে ফাঁকা লাইন)', 'area-tall']],
		daily: [['title', 'শুভেচ্ছা (শুভ সকাল, শুভ জুমা, শুভ রাত্রি…)'], ['caption', 'ছবির ক্যাপশন'], ['credit', 'ছবি: (আলোকচিত্রী)']],
		occasion: [['title', 'শিরোনাম (খালি রাখলে দিবসের নাম)'], ['author', 'নাম'], ['designation', 'পরিচয়'], ['message', 'বার্তা', 'area'], ['label', 'ওপরের ছোট লেখা (শোক কার্ডে ধর্মীয় বাক্য)']],
	};
	const USES_IMAGE = { news: 1, breaking: 1, feature: 1, literature: 1, poem: 1, daily: 1, occasion: 1 };
	const USES_AUTHOR_PHOTO = { opinion: 1 };

	// ------------------------------------------------------------------
	// State
	// ------------------------------------------------------------------
	const today = new Date();
	const iso = (d) => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
	const defaults = {
		template: 'news', layout: 'classic', size: 'square', palette: C.palette || 'category', catColor: '',
		title: '', excerpt: '', quote: '', author: '', designation: '', category: '', label: '', credit: '', caption: '',
		poem: '', message: '', time: '', occasion: 'birthday', date: iso(today), hijriOffset: C.hijriOffset ?? -1,
		site: C.site || '', showAd: false, frame: '', guides: true, img: { zoom: 1, ox: 0, oy: 0 },
		imageUrl: '', authorUrl: '', postId: 0,
	};
	let S = { ...defaults };
	try {
		const saved = JSON.parse(localStorage.getItem(STORE) || 'null');
		if (saved && typeof saved === 'object') S = { ...defaults, ...saved, img: { ...defaults.img, ...(saved.img || {}) } };
	} catch (e) { /* storage unavailable */ }
	const save = () => { try { localStorage.setItem(STORE, JSON.stringify(S)); } catch (e) { /* ignore */ } };

	const A = { brand: C.brand || 'Meeha News' }; // loaded images
	const cache = new Map();
	function loadImg(url) {
		if (!url) return Promise.resolve(null);
		if (cache.has(url)) return cache.get(url);
		const p = new Promise((res) => {
			const i = new Image();
			i.crossOrigin = 'anonymous';
			i.onload = () => res(i);
			i.onerror = () => res(null);
			i.src = url;
		});
		cache.set(url, p);
		return p;
	}

	// ------------------------------------------------------------------
	// DOM helpers
	// ------------------------------------------------------------------
	function h(tag, attrs, ...kids) {
		const el = document.createElement(tag);
		for (const [k, v] of Object.entries(attrs || {})) {
			if (v == null || v === false) continue;
			if (k === 'class') el.className = v;
			else if (k.startsWith('on')) el.addEventListener(k.slice(2), v);
			else if (k === 'html') el.innerHTML = v;
			else el.setAttribute(k, v === true ? '' : v);
		}
		kids.flat().forEach((c) => c != null && c !== false && el.append(c.nodeType ? c : document.createTextNode(c)));
		return el;
	}
	const section = (title, ...body) => h('section', { class: 'ms-sec' }, h('h3', { class: 'ms-sec__title' }, title), ...body);

	async function api(path, params) {
		const url = new URL(C.rest + path);
		Object.entries(params || {}).forEach(([k, v]) => url.searchParams.set(k, v));
		url.searchParams.set('_', Date.now());
		const r = await fetch(url, { headers: { 'X-WP-Nonce': C.nonce }, credentials: 'same-origin' });
		const j = await r.json().catch(() => ({}));
		if (!r.ok) throw new Error(j.message || 'সার্ভার থেকে তথ্য আনা যায়নি।');
		return j;
	}

	// ------------------------------------------------------------------
	// Layout
	// ------------------------------------------------------------------
	const tabs = h('nav', { class: 'ms-tabs', role: 'tablist' });
	const panel = h('div', { class: 'ms-panel' });
	const canvas = h('canvas', { class: 'ms-canvas', 'aria-label': 'কার্ডের প্রিভিউ' });
	const status = h('p', { class: 'ms-status', role: 'status' });
	const sizeBar = h('div', { class: 'ms-sizes' });
	const guideToggle = h('label', { class: 'ms-guides' });
	const btnDownload = h('button', { class: 'button button-primary button-hero', type: 'button', onclick: download }, '⬇ PNG ডাউনলোড');
	const btnSave = h('button', { class: 'button button-hero', type: 'button', onclick: saveToLibrary, disabled: !C.canUpload }, 'মিডিয়া লাইব্রেরিতে রাখুন');

	root.innerHTML = '';
	root.append(
		h('div', { class: 'ms-app' },
			h('header', { class: 'ms-head' },
				h('div', {}, h('h1', {}, 'গ্রাফিক্স স্টুডিও'), h('p', {}, 'ধরন বাছুন, পোস্ট থেকে তথ্য আনুন বা নিজে লিখুন। ডানে সঙ্গে সঙ্গে কার্ড তৈরি হবে।')),
				C.settingsUrl ? h('a', { class: 'button', href: C.settingsUrl }, 'সেটিংস') : null),
			tabs,
			h('div', { class: 'ms-body' }, panel,
				h('main', { class: 'ms-preview' }, sizeBar,
					h('div', { class: 'ms-stage' }, canvas),
					h('div', { class: 'ms-actions' }, guideToggle, h('span', { class: 'ms-spacer' }), btnSave, btnDownload),
					status))));

	// ------------------------------------------------------------------
	// Panel sections
	// ------------------------------------------------------------------
	const recentList = h('ul', { class: 'ms-recent' });
	const refInput = h('input', { type: 'text', class: 'ms-input', placeholder: 'পোস্ট আইডি বা লিংক', onkeydown: (e) => { if (e.key === 'Enter') { e.preventDefault(); fetchPost(refInput.value); } } });
	const searchInput = h('input', { type: 'search', class: 'ms-input ms-input--sm', placeholder: 'শিরোনামে খুঁজুন…', oninput: debounce(() => loadRecent(), 350) });
	let recentOffset = 0;

	function buildPanel() {
		panel.innerHTML = '';
		const tpl = R.templates[S.template];

		// 1. post
		panel.append(section('১. পোস্ট থেকে তথ্য',
			h('div', { class: 'ms-row' }, refInput, h('button', { class: 'button button-primary', type: 'button', onclick: () => fetchPost(refInput.value) }, 'তথ্য আনুন')),
			h('div', { class: 'ms-recent-head' },
				h('span', {}, 'সাম্প্রতিক প্রকাশিত'),
				h('span', { class: 'ms-recent-tools' },
					h('button', { class: 'button-link', type: 'button', title: 'আগের ৫টি', onclick: () => { recentOffset = Math.max(0, recentOffset - 5); loadRecent(); } }, '‹'),
					h('button', { class: 'button-link', type: 'button', title: 'পরের ৫টি', onclick: () => { recentOffset += 5; loadRecent(); } }, '›'),
					h('button', { class: 'button button-small', type: 'button', onclick: () => { recentOffset = 0; loadRecent(); } }, '↻ রিফ্রেশ'))),
			searchInput, recentList));

		// 2. images
		const imgSec = [];
		if (USES_IMAGE[S.template]) {
			imgSec.push(h('div', { class: 'ms-row ms-row--wrap' },
				h('button', { class: 'button', type: 'button', onclick: () => pickMedia('imageUrl') }, 'মিডিয়া লাইব্রেরি'),
				fileButton('imageUrl', 'কম্পিউটার থেকে'),
				h('button', { class: 'button-link-delete', type: 'button', onclick: () => { S.imageUrl = ''; refreshImages(); } }, 'ছবি সরান')));
			imgSec.push(h('label', { class: 'ms-range' }, 'জুম ', h('input', { type: 'range', min: 1, max: 3, step: 0.01, value: S.img.zoom, oninput: (e) => { S.img.zoom = +e.target.value; draw(); } })));
			imgSec.push(h('p', { class: 'ms-hint' }, 'প্রিভিউতে ছবি টেনে ফোকাস ঠিক করুন। ', h('button', { class: 'button-link', type: 'button', onclick: () => { S.img = { zoom: 1, ox: 0, oy: 0 }; buildPanel(); draw(); } }, 'আগের অবস্থায় ফেরান')));
		}
		if (USES_AUTHOR_PHOTO[S.template]) {
			imgSec.push(h('p', { class: 'ms-label' }, 'লেখক বা বক্তার ছবি'));
			imgSec.push(h('div', { class: 'ms-row ms-row--wrap' },
				h('button', { class: 'button', type: 'button', onclick: () => pickMedia('authorUrl') }, 'মিডিয়া লাইব্রেরি'),
				fileButton('authorUrl', 'কম্পিউটার থেকে'),
				h('button', { class: 'button-link-delete', type: 'button', onclick: () => { S.authorUrl = ''; refreshImages(); } }, 'ছবি সরান')));
			imgSec.push(h('p', { class: 'ms-hint' }, 'ছবি না থাকলে নামের প্রথম অক্ষর দিয়ে গোল প্রতীক বসবে।'));
		}
		if (imgSec.length) panel.append(section('২. ছবি', ...imgSec));

		// 3. text
		const textSec = [];
		if (S.template === 'occasion') {
			textSec.push(h('label', { class: 'ms-field' }, h('span', {}, 'দিবস বা উপলক্ষ'),
				h('select', { class: 'ms-input', onchange: (e) => { S.occasion = e.target.value; draw(); } },
					Object.entries(R.occasions).map(([k, o]) => h('option', { value: k, selected: S.occasion === k }, o.name)))));
		}
		for (const [key, label, type, hint] of FIELDS[S.template] || []) {
			const tall = type === 'area-tall';
			const input = type && type.startsWith('area')
				? h('textarea', { class: 'ms-input', rows: tall ? 10 : 3 })
				: h('input', { type: 'text', class: 'ms-input' });
			input.value = S[key] || '';
			input.addEventListener('input', () => { S[key] = input.value; draw(); });
			textSec.push(h('label', { class: 'ms-field' }, h('span', {}, label), input, hint ? h('small', { class: 'ms-hint' }, hint) : null));
		}
		panel.append(section('৩. লেখা', ...textSec));

		// 4. style
		const layouts = h('div', { class: 'ms-chips' }, Object.entries(tpl.layouts).map(([k, v]) =>
			h('button', { type: 'button', class: 'ms-chip' + (S.layout === k ? ' is-on' : ''), onclick: () => { S.layout = k; buildPanel(); draw(); } }, v)));
		const swatches = h('div', { class: 'ms-swatches' });
		const sw = (key, name, color) => h('button', {
			type: 'button', class: 'ms-swatch' + (S.palette === key ? ' is-on' : ''), title: name, 'aria-label': name,
			style: `--c:${color}`, onclick: () => { S.palette = key; buildPanel(); draw(); },
		});
		swatches.append(sw('category', 'ক্যাটেগরির রং' + (S.catColor ? '' : ' (পোস্ট থেকে তথ্য আনলে বসবে)'), S.catColor || '#c3ccd8'));
		Object.entries(R.palettes).forEach(([k, p]) => swatches.append(sw(k, p.name, p.accent)));
		panel.append(section('৪. স্টাইল', h('p', { class: 'ms-label' }, 'বিন্যাস'), layouts, h('p', { class: 'ms-label' }, 'রং'), swatches));

		// 5. frame overlay
		const frames = h('div', { class: 'ms-frames-grid' });
		const fbtn = (id, name, url) => h('button', { type: 'button', class: 'ms-frame' + (S.frame === id ? ' is-on' : ''), title: name, onclick: () => { S.frame = id; refreshImages(); buildPanel(); } },
			url ? h('img', { src: url, alt: '' }) : h('span', { class: 'ms-frame__none' }, 'ফ্রেম ছাড়া'), h('small', {}, name));
		frames.append(fbtn('', 'কোনোটি নয়', ''));
		(C.frames || []).forEach((f) => frames.append(fbtn(f.id, f.name, f.sizes[S.size] || Object.values(f.sizes)[0])));
		panel.append(section('৫. ফ্রেম ওভারলে', h('p', { class: 'ms-hint' }, 'ফ্রেম ছবির ওপরে আর লেখার নিচে বসে। নিজের ফ্রেম সেটিংস থেকে যোগ করুন।'), frames));

		// 6. date & ad
		const extra = [];
		extra.push(h('label', { class: 'ms-field' }, h('span', {}, 'তারিখ'), h('input', { type: 'date', class: 'ms-input', value: S.date, onchange: (e) => { S.date = e.target.value || iso(new Date()); dateInfo(); draw(); } })));
		const info = h('div', { class: 'ms-dates' });
		const dateInfo = () => {
			const D = R.dates(S.date, S.hijriOffset);
			info.innerHTML = '';
			[['বার', D.weekday], ['খ্রিষ্টাব্দ', D.greg], ['বঙ্গাব্দ', D.bangla], ['হিজরি', D.hijri]].forEach(([k, v]) => info.append(h('div', {}, h('span', {}, k), h('b', {}, v))));
		};
		dateInfo();
		extra.push(info);
		extra.push(h('label', { class: 'ms-field ms-field--inline' }, h('span', {}, 'হিজরি সমন্বয়'),
			h('select', { class: 'ms-input', onchange: (e) => { S.hijriOffset = +e.target.value; dateInfo(); draw(); } },
				[-2, -1, 0, 1, 2].map((o) => h('option', { value: o, selected: +S.hijriOffset === o }, R.bn((o > 0 ? '+' : '') + o) + ' দিন')))));
		const hasAd = C.ads && C.ads[S.size];
		extra.push(h('label', { class: 'ms-check' },
			h('input', { type: 'checkbox', checked: S.showAd && hasAd, disabled: !hasAd, onchange: (e) => { S.showAd = e.target.checked; draw(); } }),
			' নিচে বিজ্ঞাপন স্ট্রিপ যোগ করুন'));
		if (!hasAd) extra.push(h('p', { class: 'ms-hint' }, 'এই মাপের বিজ্ঞাপন ছবি সেটিংসে দেওয়া নেই।'));
		panel.append(section('৬. তারিখ ও বিজ্ঞাপন', ...extra));
	}

	function buildTabs() {
		tabs.innerHTML = '';
		Object.entries(R.templates).forEach(([k, t]) => tabs.append(
			h('button', { type: 'button', role: 'tab', 'aria-selected': S.template === k ? 'true' : 'false', class: 'ms-tab' + (S.template === k ? ' is-on' : ''),
				onclick: () => { S.template = k; S.layout = Object.keys(t.layouts)[0]; buildTabs(); buildPanel(); refreshImages(); } }, t.label)));
	}

	function buildSizes() {
		sizeBar.innerHTML = '';
		Object.entries(R.sizes).forEach(([k, sz]) => sizeBar.append(
			h('label', { class: 'ms-size' + (S.size === k ? ' is-on' : '') },
				h('input', { type: 'radio', name: 'ms-size', value: k, checked: S.size === k, onchange: () => { S.size = k; buildSizes(); buildPanel(); refreshImages(); } }), ' ' + sz.label)));
		guideToggle.innerHTML = '';
		if (S.size === 'story') {
			guideToggle.append(h('input', { type: 'checkbox', checked: S.guides, onchange: (e) => { S.guides = e.target.checked; draw(); } }), ' স্টোরির নিরাপদ এলাকা দেখান');
		}
	}

	function fileButton(key, label) {
		const input = h('input', { type: 'file', accept: 'image/*', hidden: true, onchange: () => {
			const f = input.files && input.files[0];
			if (!f) return;
			S[key] = URL.createObjectURL(f);
			refreshImages();
		} });
		return h('span', {}, input, h('button', { class: 'button', type: 'button', onclick: () => input.click() }, label));
	}

	function pickMedia(key) {
		if (!window.wp || !wp.media) return;
		const frame = wp.media({ title: 'ছবি বাছুন', library: { type: 'image' }, multiple: false });
		frame.on('select', () => {
			const a = frame.state().get('selection').first().toJSON();
			S[key] = C.rest + 'image/' + a.id + '?_wpnonce=' + C.nonce;
			if (key === 'imageUrl') S.img = { zoom: 1, ox: 0, oy: 0 };
			refreshImages();
			buildPanel();
		});
		frame.open();
	}

	// ------------------------------------------------------------------
	// Data
	// ------------------------------------------------------------------
	async function loadRecent() {
		recentList.innerHTML = '';
		recentList.append(h('li', { class: 'ms-recent__msg' }, 'লোড হচ্ছে…'));
		try {
			const posts = await api('posts', { offset: recentOffset, search: searchInput.value.trim() });
			recentList.innerHTML = '';
			if (!posts.length) recentList.append(h('li', { class: 'ms-recent__msg' }, 'কোনো পোস্ট পাওয়া যায়নি।'));
			posts.forEach((p) => recentList.append(h('li', {},
				h('button', { type: 'button', class: 'ms-recent__item' + (S.postId === p.id ? ' is-on' : ''), onclick: () => fetchPost(String(p.id)) },
					p.thumb ? h('img', { src: p.thumb, alt: '' }) : h('span', { class: 'ms-recent__ph' }),
					h('span', { class: 'ms-recent__text' }, h('b', {}, p.title), h('small', {}, 'আইডি ' + R.bn(p.id) + ' · ' + R.bn(p.date)))))));
		} catch (e) {
			recentList.innerHTML = '';
			recentList.append(h('li', { class: 'ms-recent__msg is-err' }, e.message));
		}
	}

	async function fetchPost(ref) {
		ref = String(ref || '').trim();
		if (!ref) { say('পোস্ট আইডি বা লিংক দিন।', true); return; }
		say('তথ্য আনা হচ্ছে…');
		try {
			const p = await api('post', { ref });
			S.postId = p.id;
			S.title = p.title;
			S.excerpt = S.template === 'literature' ? firstWords(p.content, 70) : (p.summary || p.excerpt);
			S.quote = trimTo(p.summary || p.excerpt, 220);
			S.author = p.author && p.author.name || '';
			S.designation = p.author && p.author.title || '';
			S.authorUrl = p.author && p.author.avatar || '';
			S.category = p.category ? p.category.name : '';
			S.catColor = p.category ? p.category.color : '';
			S.credit = p.credit || S.credit;
			S.imageUrl = p.image || '';
			S.img = { zoom: 1, ox: 0, oy: 0 };
			S.date = p.date || S.date;
			S.time = R.bn(p.time || '');
			if (S.template === 'poem') S.poem = p.content.split('\n').slice(0, 20).join('\n');
			refInput.value = String(p.id);
			buildPanel();
			await refreshImages();
			loadRecentMark();
			say('“' + p.title + '” থেকে তথ্য বসেছে। দরকার হলে লেখা বদলে নিন।');
		} catch (e) {
			say(e.message, true);
		}
	}

	function loadRecentMark() {
		recentList.querySelectorAll('.ms-recent__item').forEach((b) => b.classList.toggle('is-on', b.textContent.includes('আইডি ' + R.bn(S.postId) + ' ')));
	}

	const trimTo = (t, n) => { t = String(t || '').trim(); return t.length > n ? t.slice(0, n).replace(/\s+\S*$/, '') + '…' : t; };
	const firstWords = (t, n) => String(t || '').replace(/\s+/g, ' ').trim().split(' ').slice(0, n).join(' ') + '…';

	async function refreshImages() {
		const frame = (C.frames || []).find((f) => f.id === S.frame);
		const [image, author, logo, logoLight, ad, overlay] = await Promise.all([
			loadImg(S.imageUrl), loadImg(S.authorUrl), loadImg(C.logo), loadImg(C.logoLight),
			loadImg(C.ads && C.ads[S.size]), loadImg(frame ? frame.sizes[S.size] || Object.values(frame.sizes)[0] : ''),
		]);
		Object.assign(A, { image, author, logo, logoLight, ad, overlay });
		draw();
	}

	// ------------------------------------------------------------------
	// Render, drag, export
	// ------------------------------------------------------------------
	let raf = 0;
	function draw() {
		cancelAnimationFrame(raf);
		raf = requestAnimationFrame(() => {
			R.render(canvas, { ...S, guides: S.guides }, A);
			save();
		});
	}

	let drag = null;
	canvas.addEventListener('pointerdown', (e) => {
		if (!A.image) return;
		drag = { x: e.clientX, y: e.clientY, ox: S.img.ox, oy: S.img.oy };
		canvas.setPointerCapture(e.pointerId);
		canvas.classList.add('is-drag');
	});
	canvas.addEventListener('pointermove', (e) => {
		if (!drag) return;
		const rect = canvas.getBoundingClientRect();
		const k = 2.4 / Math.max(1, S.img.zoom);
		S.img.ox = clamp(drag.ox - ((e.clientX - drag.x) / rect.width) * k, -1, 1);
		S.img.oy = clamp(drag.oy - ((e.clientY - drag.y) / rect.height) * k, -1, 1);
		draw();
	});
	const endDrag = () => { drag = null; canvas.classList.remove('is-drag'); };
	canvas.addEventListener('pointerup', endDrag);
	canvas.addEventListener('pointercancel', endDrag);
	const clamp = (v, a, b) => Math.min(b, Math.max(a, v));

	function exportBlob() {
		return new Promise((res, rej) => {
			const out = document.createElement('canvas');
			R.render(out, { ...S, guides: false }, A);
			try {
				out.toBlob((b) => (b ? res(b) : rej(new Error('ছবি তৈরি করা যায়নি।'))), 'image/png');
			} catch (e) {
				rej(new Error('বাইরের সাইটের ছবি থাকায় ডাউনলোড আটকে গেছে। ছবিটি মিডিয়া লাইব্রেরি বা কম্পিউটার থেকে দিন।'));
			}
		});
	}

	function fileName() {
		const sz = R.sizes[S.size];
		return 'meeha-' + S.template + (S.postId ? '-' + S.postId : '') + '-' + sz.w + 'x' + sz.h + '-' + S.date + '.png';
	}

	async function download() {
		try {
			const b = await exportBlob();
			const a = h('a', { href: URL.createObjectURL(b), download: fileName() });
			document.body.append(a);
			a.click();
			setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1500);
			say('ডাউনলোড হয়েছে: ' + fileName());
		} catch (e) {
			say(e.message, true);
		}
	}

	async function saveToLibrary() {
		btnSave.disabled = true;
		say('মিডিয়া লাইব্রেরিতে রাখা হচ্ছে…');
		try {
			const b = await exportBlob();
			const r = await fetch(C.mediaRest, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': C.nonce, 'Content-Type': 'image/png', 'Content-Disposition': 'attachment; filename="' + fileName() + '"' },
				body: b,
			});
			const j = await r.json().catch(() => ({}));
			if (!r.ok) throw new Error(j.message || 'রাখা যায়নি।');
			say('মিডিয়া লাইব্রেরিতে রাখা হয়েছে (আইডি ' + R.bn(j.id) + ')।');
		} catch (e) {
			say(e.message, true);
		} finally {
			btnSave.disabled = !C.canUpload;
		}
	}

	function say(msg, err) {
		status.textContent = msg;
		status.classList.toggle('is-err', !!err);
	}

	function debounce(fn, ms) { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; }

	// ------------------------------------------------------------------
	// Boot
	// ------------------------------------------------------------------
	(async function boot() {
		if (!R.templates[S.template]) S.template = 'news';
		buildTabs();
		buildSizes();
		buildPanel();
		say('ফন্ট লোড হচ্ছে…');
		try { await Promise.all(R.fonts.map((f) => document.fonts.load(f, 'অআকখ'))); } catch (e) { /* fall back */ }
		say('');
		await refreshImages();
		loadRecent();
		const q = new URLSearchParams(location.search).get('post');
		if (q) fetchPost(q);
	})();
})();
