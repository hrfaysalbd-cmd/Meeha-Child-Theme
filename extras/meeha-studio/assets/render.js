/**
 * Meeha Studio: canvas renderer.
 *
 * Pure drawing code, no DOM besides the canvas it is given. Every template is
 * laid out in units of u = W / 1080 so one design works at every size; the
 * landscape link size (1200x630) gets its own side-by-side arrangement.
 *
 * Text may mark highlighted words with **double asterisks**.
 */
(function () {
	'use strict';

	const R = {};
	window.MeehaRender = R;

	// ------------------------------------------------------------------
	// Fonts, palettes, sizes
	// ------------------------------------------------------------------
	const SERIF = '"Noto Serif Bengali", "Hind Siliguri", serif';
	const SANS = '"Hind Siliguri", "Noto Sans Bengali", sans-serif';
	const LIT = '"Tiro Bangla", "Noto Serif Bengali", serif';
	R.fonts = [
		'800 40px "Noto Serif Bengali"', '600 40px "Noto Serif Bengali"',
		'500 40px "Hind Siliguri"', '600 40px "Hind Siliguri"', '700 40px "Hind Siliguri"',
		'400 40px "Tiro Bangla"', 'italic 400 40px "Tiro Bangla"',
	];

	R.sizes = {
		square: { w: 1080, h: 1080, label: '১০৮০ × ১০৮০ (বর্গ)' },
		portrait: { w: 1080, h: 1350, label: '১০৮০ × ১৩৫০ (পোর্ট্রেট)' },
		story: { w: 1080, h: 1920, label: '১০৮০ × ১৯২০ (স্টোরি)' },
		link: { w: 1200, h: 630, label: '১২০০ × ৬৩০ (লিংক/ফেসবুক)' },
	};

	// Brand-led, lightly colourful palettes. accent = strong, soft = tint
	// background, deep = dark companion, ink = body text.
	R.palettes = {
		nil: { name: 'নীল (ব্র্যান্ড)', accent: '#025AB2', soft: '#EAF2FB', deep: '#063A73', ink: '#0F1B2D', hi: '#FFD166' },
		akash: { name: 'আকাশি', accent: '#0B88B5', soft: '#E6F5FA', deep: '#06506B', ink: '#10242C', hi: '#FFE08A' },
		sobuj: { name: 'সবুজ', accent: '#0E8A6A', soft: '#E7F6F1', deep: '#08513F', ink: '#10261F', hi: '#FFE08A' },
		lal: { name: 'লাল', accent: '#D0283A', soft: '#FDECEE', deep: '#7A1220', ink: '#2A1013', hi: '#FFE08A' },
		kamla: { name: 'কমলা', accent: '#E06A1B', soft: '#FDF1E7', deep: '#8A3D0B', ink: '#2B170A', hi: '#FFF1A8' },
		begun: { name: 'বেগুনি', accent: '#6D4BB0', soft: '#F2EDFA', deep: '#3D2670', ink: '#1E1530', hi: '#FFD166' },
		golapi: { name: 'গোলাপি', accent: '#C9306E', soft: '#FCE9F1', deep: '#7A1641', ink: '#2B0F1C', hi: '#FFE08A' },
		sonali: { name: 'সোনালি', accent: '#B07D12', soft: '#FBF4E3', deep: '#5E420A', ink: '#2A2110', hi: '#FFE9A8' },
		kalo: { name: 'কালো (শোক)', accent: '#2B2B2B', soft: '#F1F1F1', deep: '#141414', ink: '#141414', hi: '#E6E6E6' },
	};

	/** Build a palette from any accent colour (used for category colours). */
	R.paletteFromAccent = function (hex) {
		const [r, g, b] = hexRgb(hex);
		return {
			name: 'ক্যাটেগরির রং', accent: hex,
			soft: rgbHex(mix([r, g, b], [255, 255, 255], 0.9)),
			deep: rgbHex(mix([r, g, b], [0, 0, 0], 0.45)),
			ink: rgbHex(mix([r, g, b], [10, 14, 20], 0.85)), hi: '#FFD166',
		};
	};

	// ------------------------------------------------------------------
	// Small helpers
	// ------------------------------------------------------------------
	function hexRgb(h) {
		h = String(h || '#025AB2').replace('#', '');
		if (h.length === 3) h = h.split('').map((c) => c + c).join('');
		const n = parseInt(h, 16);
		return [(n >> 16) & 255, (n >> 8) & 255, n & 255];
	}
	function rgbHex(c) { return '#' + c.map((v) => Math.round(v).toString(16).padStart(2, '0')).join(''); }
	function mix(a, b, t) { return a.map((v, i) => v + (b[i] - v) * t); }
	function rgba(hex, a) { const [r, g, b] = hexRgb(hex); return `rgba(${r},${g},${b},${a})`; }
	R.rgba = rgba;

	const BN = ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'];
	const bn = (v) => String(v).replace(/[0-9]/g, (d) => BN[d]);
	R.bn = bn;

	function font(weight, size, fam) { return `${weight} ${Math.round(size)}px ${fam}`; }

	function roundRect(ctx, x, y, w, h, r) {
		r = Math.min(r, w / 2, h / 2);
		ctx.beginPath();
		ctx.moveTo(x + r, y);
		ctx.arcTo(x + w, y, x + w, y + h, r);
		ctx.arcTo(x + w, y + h, x, y + h, r);
		ctx.arcTo(x, y + h, x, y, r);
		ctx.arcTo(x, y, x + w, y, r);
		ctx.closePath();
	}

	/**
	 * Split "a **b c**, d" into word tokens. A word can mix highlighted and
	 * plain parts ("আচ্ছাদন**," → [আচ্ছাদন(hi), ","]) so punctuation stays
	 * attached. Newlines become {br:true}.
	 */
	function tokens(text) {
		const out = [];
		let hi = false;
		String(text || '').replace(/\r/g, '').split(/(\n)/).forEach((seg) => {
			if (seg === '\n') { out.push({ br: true }); return; }
			seg.split(/[ \t]+/).forEach((word) => {
				const parts = [];
				word.split(/(\*\*)/).forEach((p) => {
					if (p === '**') { hi = !hi; return; }
					if (p) parts.push({ t: p, hi });
				});
				if (parts.length) out.push({ parts, hi: parts.some((x) => x.hi) });
			});
		});
		return out;
	}
	R.plain = (text) => String(text || '').replace(/\*\*/g, '');

	/** Wrap tokens into lines no wider than maxW at the ctx's current font. */
	function wrapTokens(ctx, toks, maxW) {
		const lines = [];
		let line = [];
		let width = 0;
		const space = ctx.measureText(' ').width;
		for (const tk of toks) {
			if (tk.br) { lines.push(line); line = []; width = 0; continue; }
			tk.parts.forEach((p) => { p.w = ctx.measureText(p.t).width; });
			const w = tk.parts.reduce((n, p) => n + p.w, 0);
			if (line.length && width + space + w > maxW) { lines.push(line); line = []; width = 0; }
			line.push({ ...tk, w });
			width += (line.length > 1 ? space : 0) + w;
		}
		if (line.length) lines.push(line);
		return lines;
	}

	/**
	 * Fit text into a box: shrinks from maxSize to minSize until it fits in
	 * maxLines; at minSize it truncates with an ellipsis.
	 */
	function fit(ctx, text, o) {
		const toks = tokens(text);
		let size = o.maxSize;
		let lines;
		for (; size >= o.minSize; size -= 2) {
			ctx.font = font(o.weight || 700, size, o.fam || SERIF);
			lines = wrapTokens(ctx, toks, o.maxW);
			if (lines.length <= o.maxLines && (!o.maxH || lines.length * size * (o.lh || 1.3) <= o.maxH)) break;
		}
		size = Math.max(size, o.minSize);
		ctx.font = font(o.weight || 700, size, o.fam || SERIF);
		lines = wrapTokens(ctx, toks, o.maxW);
		if (lines.length > o.maxLines) {
			lines = lines.slice(0, o.maxLines);
			const last = lines[o.maxLines - 1];
			if (last.length) {
				const tk = last[last.length - 1];
				const parts = tk.parts.slice();
				parts[parts.length - 1] = { ...parts[parts.length - 1], t: parts[parts.length - 1].t + '…' };
				last[last.length - 1] = { ...tk, parts };
			}
		}
		return { size, lines, lh: size * (o.lh || 1.3), weight: o.weight || 700, fam: o.fam || SERIF };
	}

	/**
	 * Draw fitted lines. Highlighted words get `hiColor` text and/or a marker
	 * band in `hiBand`. Returns the y below the last line.
	 */
	function draw(ctx, f, x, y, o = {}) {
		const align = o.align || 'left';
		ctx.font = font(f.weight, f.size, f.fam);
		ctx.textBaseline = 'alphabetic';
		const space = ctx.measureText(' ').width;
		let yy = y + f.size;
		for (const line of f.lines) {
			const lw = line.reduce((s, t) => s + t.w, 0) + space * Math.max(0, line.length - 1);
			let xx = align === 'center' ? x - lw / 2 : align === 'right' ? x - lw : x;
			for (let i = 0; i < line.length; i++) {
				const t = line[i];
				let px = xx;
				for (let j = 0; j < t.parts.length; j++) {
					const p = t.parts[j];
					if (p.hi && o.hiBand) {
						ctx.fillStyle = o.hiBand;
						const pad = f.size * 0.08;
						const nextHi = j + 1 < t.parts.length ? t.parts[j + 1].hi : i + 1 < line.length && line[i + 1].parts[0].hi;
						const join = nextHi ? (j + 1 < t.parts.length ? 0 : space) : 0;
						ctx.fillRect(px - pad, yy - f.size * 0.42, p.w + pad * 2 + join, f.size * 0.5);
					}
					ctx.fillStyle = p.hi && o.hiColor ? o.hiColor : o.color || '#111';
					ctx.fillText(p.t, px, yy);
					px += p.w;
				}
				xx += t.w + space;
			}
			yy += f.lh;
		}
		return yy - f.lh + f.size * 0.35;
	}

	function text1(ctx, str, x, y, size, o = {}) {
		ctx.font = font(o.weight || 500, size, o.fam || SANS);
		ctx.fillStyle = o.color || '#111';
		ctx.textAlign = o.align || 'left';
		ctx.textBaseline = o.base || 'alphabetic';
		let s = R.plain(str);
		if (o.maxW) while (s.length > 1 && ctx.measureText(s).width > o.maxW) s = s.slice(0, -2) + '…';
		ctx.fillText(s, x, y);
		const w = ctx.measureText(s).width;
		ctx.textAlign = 'left';
		ctx.textBaseline = 'alphabetic';
		return w;
	}

	/** Pill label, returns its width. */
	function chip(ctx, str, x, y, size, bg, fg, o = {}) {
		ctx.font = font(o.weight || 700, size, o.fam || SANS);
		const w = ctx.measureText(str).width + size * 1.4;
		const h = size * 1.7;
		const xx = o.align === 'center' ? x - w / 2 : o.align === 'right' ? x - w : x;
		ctx.fillStyle = bg;
		roundRect(ctx, xx, y, w, h, o.radius ?? h / 2);
		ctx.fill();
		if (o.stroke) { ctx.strokeStyle = o.stroke; ctx.lineWidth = o.strokeW || 2; ctx.stroke(); }
		ctx.fillStyle = fg;
		ctx.textBaseline = 'middle';
		ctx.fillText(str, xx + size * 0.7, y + h / 2 + size * 0.06);
		ctx.textBaseline = 'alphabetic';
		return w;
	}

	/** Draw an image to cover a box, honouring zoom and drag offsets. */
	function cover(ctx, img, x, y, w, h, s = {}, o = {}) {
		if (!img) {
			const g = ctx.createLinearGradient(x, y, x, y + h);
			g.addColorStop(0, o.ph1 || '#dfe7f1');
			g.addColorStop(1, o.ph2 || '#b9c7d8');
			ctx.fillStyle = g;
			ctx.fillRect(x, y, w, h);
			return;
		}
		const zoom = Math.max(1, s.zoom || 1);
		const scale = Math.max(w / img.width, h / img.height) * zoom;
		const dw = img.width * scale;
		const dh = img.height * scale;
		const ox = (dw - w) / 2;
		const oy = (dh - h) / 2;
		const dx = x - ox - ox * (s.ox || 0);
		const dy = y - oy - oy * (s.oy || 0);
		ctx.save();
		ctx.beginPath();
		ctx.rect(x, y, w, h);
		ctx.clip();
		if (o.filter) ctx.filter = o.filter;
		ctx.drawImage(img, dx, dy, dw, dh);
		ctx.restore();
	}

	function circleImage(ctx, img, cx, cy, r, o = {}) {
		ctx.save();
		ctx.beginPath();
		ctx.arc(cx, cy, r, 0, Math.PI * 2);
		ctx.closePath();
		ctx.clip();
		if (img) {
			const sc = Math.max((2 * r) / img.width, (2 * r) / img.height);
			if (o.filter) ctx.filter = o.filter;
			ctx.drawImage(img, cx - (img.width * sc) / 2, cy - (img.height * sc) / 2, img.width * sc, img.height * sc);
		} else {
			ctx.fillStyle = o.bg || '#d7dee8';
			ctx.fillRect(cx - r, cy - r, 2 * r, 2 * r);
			const init = (o.name || '').trim().charAt(0);
			if (init) text1(ctx, init, cx, cy + r * 0.05, r * 0.9, { align: 'center', base: 'middle', weight: 700, fam: SERIF, color: o.fg || '#5b6b80' });
		}
		ctx.restore();
		if (o.ring) {
			ctx.beginPath();
			ctx.arc(cx, cy, r + (o.ringGap || 0), 0, Math.PI * 2);
			ctx.strokeStyle = o.ring;
			ctx.lineWidth = o.ringW || 6;
			ctx.stroke();
		}
	}

	function vGradient(ctx, x, y, w, h, stops) {
		const g = ctx.createLinearGradient(0, y, 0, y + h);
		stops.forEach(([p, c]) => g.addColorStop(p, c));
		ctx.fillStyle = g;
		ctx.fillRect(x, y, w, h);
	}

	/** Logo image (or wordmark fallback). align: left|center|right. Returns width. */
	function logo(ctx, A, x, y, h, o = {}) {
		// On dark backgrounds only the light logo is used; otherwise a white wordmark.
		const img = o.light ? A.logoLight : A.logo;
		if (img) {
			const w = (img.width / img.height) * h;
			const xx = o.align === 'center' ? x - w / 2 : o.align === 'right' ? x - w : x;
			ctx.drawImage(img, xx, y, w, h);
			return w;
		}
		return text1(ctx, A.brand || 'Meeha News', x, y + h * 0.78, h * 0.62, { weight: 800, fam: SERIF, color: o.color || '#025AB2', align: o.align });
	}

	// Bengali dates --------------------------------------------------------
	const WD = ['রবিবার', 'সোমবার', 'মঙ্গলবার', 'বুধবার', 'বৃহস্পতিবার', 'শুক্রবার', 'শনিবার'];
	const GM = ['জানুয়ারি', 'ফেব্রুয়ারি', 'মার্চ', 'এপ্রিল', 'মে', 'জুন', 'জুলাই', 'আগস্ট', 'সেপ্টেম্বর', 'অক্টোবর', 'নভেম্বর', 'ডিসেম্বর'];
	const BM = ['বৈশাখ', 'জ্যৈষ্ঠ', 'আষাঢ়', 'শ্রাবণ', 'ভাদ্র', 'আশ্বিন', 'কার্তিক', 'অগ্রহায়ণ', 'পৌষ', 'মাঘ', 'ফাল্গুন', 'চৈত্র'];
	const HM = ['মহররম', 'সফর', 'রবিউল আউয়াল', 'রবিউস সানি', 'জমাদিউল আউয়াল', 'জমাদিউস সানি', 'রজব', 'শাবান', 'রমজান', 'শাওয়াল', 'জিলকদ', 'জিলহজ'];
	const isLeap = (y) => (y % 4 === 0 && y % 100 !== 0) || y % 400 === 0;

	/** Bangladesh's revised Bangla calendar (2019): 1 Boishakh = 14 April. */
	function bangla(d) {
		const y = d.getFullYear();
		let start = new Date(y, 3, 14);
		if (d < start) start = new Date(y - 1, 3, 14);
		const by = start.getFullYear() - 593;
		let days = Math.round((Date.UTC(d.getFullYear(), d.getMonth(), d.getDate()) - Date.UTC(start.getFullYear(), 3, 14)) / 864e5);
		const len = [31, 31, 31, 31, 31, 31, 30, 30, 30, 30, isLeap(start.getFullYear() + 1) ? 30 : 29, 30];
		let m = 0;
		while (m < 11 && days >= len[m]) { days -= len[m]; m++; }
		return `${bn(days + 1)} ${BM[m]} ${bn(by)}`;
	}

	function hijri(d, offset) {
		const dd = new Date(d.getTime() + (offset || 0) * 864e5);
		try {
			const parts = new Intl.DateTimeFormat('en-u-ca-islamic-umalqura-nu-latn', { day: 'numeric', month: 'numeric', year: 'numeric' }).formatToParts(dd);
			const get = (t) => parseInt((parts.find((p) => p.type === t) || {}).value, 10);
			return `${bn(get('day'))} ${HM[get('month') - 1]} ${bn(get('year'))}`;
		} catch (e) {
			return '';
		}
	}

	R.dates = function (iso, hijriOffset) {
		const d = iso ? new Date(iso + 'T12:00:00') : new Date();
		return {
			weekday: WD[d.getDay()],
			greg: `${bn(d.getDate())} ${GM[d.getMonth()]} ${bn(d.getFullYear())}`,
			bangla: bangla(d),
			hijri: hijri(d, hijriOffset),
		};
	};

	// Decorations -------------------------------------------------------------
	function cornerBrackets(ctx, inset, len, thick, color) {
		const W = ctx.canvas.width, H = ctx.canvas.height;
		ctx.fillStyle = color;
		ctx.fillRect(inset, inset, len, thick); ctx.fillRect(inset, inset, thick, len);
		ctx.fillRect(W - inset - len, H - inset - thick, len, thick); ctx.fillRect(W - inset - thick, H - inset - len, thick, len);
	}

	function softBlobs(ctx, P, W, H, u) {
		// Gentle colourful circles in two corners.
		const blob = (x, y, r, c, a) => { ctx.beginPath(); ctx.arc(x, y, r, 0, Math.PI * 2); ctx.fillStyle = rgba(c, a); ctx.fill(); };
		blob(W * 0.95, H * 0.04, 190 * u, P.accent, 0.12);
		blob(W * 0.86, H * 0.0, 90 * u, P.hi, 0.35);
		blob(W * 0.03, H * 0.97, 220 * u, P.accent, 0.1);
		blob(W * 0.16, H * 1.0, 80 * u, P.hi, 0.3);
	}

	function dots(ctx, x, y, w, h, color, gap) {
		ctx.fillStyle = color;
		for (let yy = y; yy < y + h; yy += gap) for (let xx = x; xx < x + w; xx += gap) { ctx.beginPath(); ctx.arc(xx, yy, gap * 0.09, 0, Math.PI * 2); ctx.fill(); }
	}

	function paper(ctx, W, H, base) {
		ctx.fillStyle = base;
		ctx.fillRect(0, 0, W, H);
		let seed = 7;
		const rnd = () => (seed = (seed * 16807) % 2147483647) / 2147483647;
		for (let i = 0; i < (W * H) / 900; i++) {
			ctx.fillStyle = `rgba(120,90,40,${rnd() * 0.05})`;
			ctx.fillRect(rnd() * W, rnd() * H, 1.5, 1.5);
		}
		const g = ctx.createRadialGradient(W / 2, H / 2, Math.min(W, H) * 0.3, W / 2, H / 2, Math.max(W, H) * 0.75);
		g.addColorStop(0, 'rgba(0,0,0,0)');
		g.addColorStop(1, 'rgba(110,80,30,0.12)');
		ctx.fillStyle = g;
		ctx.fillRect(0, 0, W, H);
	}

	/** Leafy vine in one corner; rot = 0..3 picks the corner. */
	function vine(ctx, x, y, s, color, rot) {
		ctx.save();
		ctx.translate(x, y);
		ctx.rotate((rot * Math.PI) / 2);
		ctx.strokeStyle = color;
		ctx.fillStyle = color;
		ctx.lineWidth = 3 * s;
		ctx.beginPath();
		ctx.moveTo(0, 220 * s);
		ctx.bezierCurveTo(0, 90 * s, 90 * s, 0, 220 * s, 0);
		ctx.stroke();
		const leaf = (lx, ly, a, sz) => {
			ctx.save(); ctx.translate(lx, ly); ctx.rotate(a);
			ctx.beginPath(); ctx.moveTo(0, 0);
			ctx.quadraticCurveTo(sz * 0.5, -sz * 0.45, sz, 0);
			ctx.quadraticCurveTo(sz * 0.5, sz * 0.45, 0, 0);
			ctx.fill(); ctx.restore();
		};
		const pts = [[6, 170, -2.4], [22, 110, -2.1], [55, 60, -1.7], [105, 24, -1.2], [160, 6, -0.9], [20, 150, 0.6], [45, 82, 0.4], [92, 36, 0.1], [140, 14, -0.1]];
		pts.forEach(([a, b, ang], i) => leaf(a * s, b * s, ang, (i % 2 ? 34 : 42) * s));
		ctx.beginPath(); ctx.arc(12 * s, 12 * s, 9 * s, 0, Math.PI * 2); ctx.fill();
		ctx.restore();
	}

	function flourish(ctx, cx, y, w, color, u) {
		ctx.strokeStyle = color;
		ctx.fillStyle = color;
		ctx.lineWidth = 2.5 * u;
		ctx.beginPath();
		ctx.moveTo(cx - w / 2, y);
		ctx.bezierCurveTo(cx - w / 4, y - 14 * u, cx - w / 8, y + 14 * u, cx - 16 * u, y);
		ctx.moveTo(cx + w / 2, y);
		ctx.bezierCurveTo(cx + w / 4, y - 14 * u, cx + w / 8, y + 14 * u, cx + 16 * u, y);
		ctx.stroke();
		ctx.save(); ctx.translate(cx, y); ctx.rotate(Math.PI / 4);
		ctx.fillRect(-8 * u, -8 * u, 16 * u, 16 * u);
		ctx.restore();
	}

	function stars(ctx, W, H, n, color) {
		let seed = 3;
		const rnd = () => (seed = (seed * 16807) % 2147483647) / 2147483647;
		for (let i = 0; i < n; i++) {
			ctx.fillStyle = rgba(color, 0.3 + rnd() * 0.6);
			ctx.beginPath();
			ctx.arc(rnd() * W, rnd() * H * 0.9, 0.8 + rnd() * 2.2, 0, Math.PI * 2);
			ctx.fill();
		}
	}

	function crescent(ctx, x, y, r, color) {
		ctx.save();
		ctx.beginPath(); ctx.arc(x, y, r, 0, Math.PI * 2);
		ctx.arc(x + r * 0.42, y - r * 0.22, r * 0.86, 0, Math.PI * 2, true);
		ctx.fillStyle = color; ctx.fill('evenodd');
		ctx.restore();
	}

	function confetti(ctx, W, H, band, u) {
		const cs = ['#F94144', '#F8961E', '#F9C74F', '#90BE6D', '#43AA8B', '#4D96FF', '#C77DFF'];
		let seed = 11;
		const rnd = () => (seed = (seed * 16807) % 2147483647) / 2147483647;
		for (let i = 0; i < 220; i++) {
			const e = Math.floor(rnd() * 4);
			let x = rnd() * W, y = rnd() * H;
			if (e === 0) y = rnd() * band; else if (e === 1) y = H - rnd() * band; else if (e === 2) x = rnd() * band; else x = W - rnd() * band;
			ctx.save(); ctx.translate(x, y); ctx.rotate(rnd() * 3);
			ctx.fillStyle = cs[Math.floor(rnd() * cs.length)];
			const sz = (8 + rnd() * 12) * u;
			if (rnd() > 0.5) { ctx.beginPath(); ctx.arc(0, 0, sz / 2, 0, Math.PI * 2); ctx.fill(); } else ctx.fillRect(-sz / 2, -sz / 4, sz, sz / 2);
			ctx.restore();
		}
	}

	function ribbon(ctx, x, y, h, color, outline) {
		const s = h / 160;
		ctx.save(); ctx.translate(x, y); ctx.scale(s, s);
		const p = new Path2D('M28 150 L58 80 C72 50 68 14 50 14 C32 14 28 50 42 80 L72 150');
		ctx.lineJoin = 'round';
		if (outline) { ctx.strokeStyle = outline; ctx.lineWidth = 22; ctx.stroke(p); }
		ctx.strokeStyle = color; ctx.lineWidth = 14; ctx.stroke(p);
		ctx.restore();
	}

	function shaheedMinar(ctx, x, y, w, color, sun) {
		const s = w / 300;
		ctx.save(); ctx.translate(x, y); ctx.scale(s, s);
		ctx.fillStyle = sun; ctx.beginPath(); ctx.arc(150, 78, 58, 0, Math.PI * 2); ctx.fill();
		ctx.strokeStyle = color; ctx.lineJoin = 'round';
		const col = (cx, cw, ch) => { ctx.lineWidth = 8; ctx.beginPath(); ctx.moveTo(cx, 230); ctx.lineTo(cx, 230 - ch); ctx.lineTo(cx + cw, 230 - ch); ctx.lineTo(cx + cw, 230); ctx.stroke(); };
		col(26, 36, 90); col(74, 36, 118); col(190, 36, 118); col(238, 36, 90);
		ctx.lineWidth = 10; ctx.beginPath(); ctx.moveTo(122, 230); ctx.lineTo(122, 58); ctx.lineTo(150, 20); ctx.lineTo(178, 58); ctx.lineTo(178, 230); ctx.stroke();
		ctx.lineWidth = 5; ctx.beginPath(); ctx.moveTo(122, 58); ctx.lineTo(178, 58); ctx.moveTo(122, 110); ctx.lineTo(178, 110); ctx.stroke();
		ctx.fillStyle = color; ctx.fillRect(6, 230, 288, 14);
		ctx.restore();
	}

	function sunIcon(ctx, x, y, r, color) {
		ctx.fillStyle = color;
		ctx.beginPath(); ctx.arc(x, y, r, Math.PI, 0); ctx.fill();
		ctx.strokeStyle = color; ctx.lineWidth = r * 0.16; ctx.lineCap = 'round';
		for (let i = 0; i <= 6; i++) {
			const a = Math.PI + (i * Math.PI) / 6;
			ctx.beginPath(); ctx.moveTo(x + Math.cos(a) * r * 1.3, y + Math.sin(a) * r * 1.3); ctx.lineTo(x + Math.cos(a) * r * 1.65, y + Math.sin(a) * r * 1.65); ctx.stroke();
		}
		ctx.fillRect(x - r * 1.7, y + r * 0.12, r * 3.4, r * 0.16);
		ctx.lineCap = 'butt';
	}

	// ------------------------------------------------------------------
	// Shared pieces
	// ------------------------------------------------------------------

	/** Footer row: logo left, date/site right, above an optional rule. */
	function footer(ctx, A, s, x, y, w, u, o = {}) {
		const dark = !!o.dark;
		if (o.rule !== false) {
			ctx.fillStyle = dark ? 'rgba(255,255,255,.25)' : 'rgba(15,27,45,.12)';
			ctx.fillRect(x, y, w, 2 * u);
		}
		const lh = (o.logoH || 52) * u;
		logo(ctx, A, x, y + 22 * u, lh, { light: dark, color: dark ? '#fff' : s.P.accent });
		const right = [s.dateLine, s.site].filter(Boolean);
		right.forEach((t, i) => text1(ctx, t, x + w, y + 22 * u + lh * 0.45 + i * 30 * u, 24 * u, { align: 'right', color: dark ? 'rgba(255,255,255,.85)' : '#5b6676', weight: i ? 500 : 600 }));
	}

	/** Reserve the ad strip and draw it; returns the usable height. */
	function adStrip(ctx, A, s, W, H, u) {
		if (!s.showAd || !A.ad) return H;
		const h = Math.round(Math.min(130 * u, H * 0.12));
		ctx.fillStyle = '#fff';
		ctx.fillRect(0, H - h, W, h);
		cover(ctx, A.ad, 0, H - h, W, h, {});
		return H - h;
	}

	// ------------------------------------------------------------------
	// Templates
	// ------------------------------------------------------------------
	const T = {};
	R.templates = T;

	// --- সংবাদ কার্ড -----------------------------------------------------
	T.news = {
		label: 'সংবাদ',
		layouts: { classic: 'ক্লাসিক', overlay: 'ছবির ওপর লেখা', soft: 'হালকা রঙিন' },
		draw(ctx, W, H, s, A, hook) {
			const u = W / 1080, P = s.P, land = W / H > 1.3, story = H / W > 1.6;
			H = adStrip(ctx, A, s, W, H, u);
			const pad = 64 * u;
			if (s.layout === 'overlay') {
				cover(ctx, A.image, 0, 0, W, H, s.img);
				hook();
				vGradient(ctx, 0, H * 0.3, W, H * 0.7, [[0, rgba(P.deep, 0)], [0.55, rgba(P.deep, 0.78)], [1, rgba(P.deep, 0.96)]]);
				const maxW = land ? W * 0.62 : W - pad * 2;
				const f = fit(ctx, s.title, { maxW, maxLines: land ? 3 : story ? 5 : 4, maxSize: (story ? 84 : 72) * u, minSize: 42 * u });
				const footH = 110 * u;
				const top = H - (story ? 300 * u : 0) - footH - f.lines.length * f.lh - 40 * u;
				if (s.category) chip(ctx, s.category, pad, top - 70 * u, 28 * u, P.accent, '#fff');
				draw(ctx, f, pad, top, { color: '#fff', hiColor: P.hi });
				footer(ctx, A, s, pad, H - (story ? 300 * u : 0) - footH, W - pad * 2, u, { dark: true });
				return;
			}
			if (s.layout === 'soft') {
				ctx.fillStyle = P.soft; ctx.fillRect(0, 0, W, H);
				softBlobs(ctx, P, W, H, u);
				const top = story ? 250 * u : pad;
				const ph = land ? H - pad * 2 : story ? H * 0.42 : H * (H > W ? 0.5 : 0.47);
				const pw = land ? W * 0.48 : W - pad * 2;
				ctx.save(); ctx.shadowColor = 'rgba(0,0,0,.18)'; ctx.shadowBlur = 30 * u; ctx.shadowOffsetY = 10 * u;
				ctx.fillStyle = '#fff'; roundRect(ctx, pad - 10 * u, top - 10 * u, pw + 20 * u, ph + 20 * u, 34 * u); ctx.fill(); ctx.restore();
				ctx.save(); roundRect(ctx, pad, top, pw, ph, 26 * u); ctx.clip(); cover(ctx, A.image, pad, top, pw, ph, s.img); ctx.restore();
				hook();
				const tx = land ? pad + pw + 48 * u : pad;
				const tw = land ? W - tx - pad : W - pad * 2;
				let y = land ? pad + 10 * u : top + ph + 46 * u;
				if (s.category) { chip(ctx, s.category, tx, y, 26 * u, P.accent, '#fff'); y += 72 * u; }
				const footH = 100 * u;
				const avail = (land ? H - pad : H - (story ? 280 * u : pad)) - footH - y;
				const f = fit(ctx, s.title, { maxW: tw, maxLines: land ? 4 : 4, maxSize: (land ? 54 : story ? 76 : 64) * u, minSize: 36 * u, maxH: avail * (s.excerpt && !land ? 0.68 : 1) });
				y = draw(ctx, f, tx, y, { color: P.ink, hiColor: P.accent });
				if (s.excerpt && !land && H > W) {
					const e = fit(ctx, s.excerpt, { maxW: tw, maxLines: story ? 4 : 2, maxSize: 30 * u, minSize: 24 * u, weight: 500, fam: SANS, lh: 1.45 });
					draw(ctx, e, tx, y + 18 * u, { color: '#4a5566' });
				}
				footer(ctx, A, s, tx, (land ? H - pad : H - (story ? 280 * u : pad)) - footH + 10 * u, tw, u);
				return;
			}
			// classic
			ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, W, H);
			if (land) {
				const pw = W * 0.52;
				cover(ctx, A.image, 0, 0, pw, H, s.img); hook();
				ctx.fillStyle = P.accent; ctx.fillRect(pw, 0, 8 * u, H);
				const tx = pw + 52 * u, tw = W - tx - 48 * u;
				let y = 52 * u;
				if (s.category) { chip(ctx, s.category, tx, y, 24 * u, P.accent, '#fff'); y += 66 * u; }
				const f = fit(ctx, s.title, { maxW: tw, maxLines: 4, maxSize: 50 * u, minSize: 32 * u, maxH: H - y - 140 * u });
				draw(ctx, f, tx, y, { color: P.ink, hiColor: P.accent });
				footer(ctx, A, s, tx, H - 120 * u, tw, u, { logoH: 46 });
				return;
			}
			const ph = story ? H * 0.5 : H * (H > W ? 0.56 : 0.55);
			cover(ctx, A.image, 0, 0, W, ph, s.img); hook();
			ctx.fillStyle = P.accent; ctx.fillRect(0, ph, W, 10 * u);
			if (s.credit) text1(ctx, 'ছবি: ' + s.credit, W - 24 * u, ph - 22 * u, 20 * u, { align: 'right', color: 'rgba(255,255,255,.9)' });
			let y = ph + 48 * u;
			if (s.category) { chip(ctx, s.category, pad, y, 26 * u, P.soft, P.accent, { weight: 700 }); y += 70 * u; }
			const bottom = H - (story ? 260 * u : 40 * u) - 110 * u;
			const f = fit(ctx, s.title, { maxW: W - pad * 2, maxLines: story ? 5 : 4, maxSize: (story ? 76 : 66) * u, minSize: 38 * u, maxH: (bottom - y) * (s.excerpt && H > W ? 0.7 : 1) });
			y = draw(ctx, f, pad, y, { color: P.ink, hiColor: P.accent });
			if (s.excerpt && H > W) {
				const e = fit(ctx, s.excerpt, { maxW: W - pad * 2, maxLines: story ? 4 : 2, maxSize: 30 * u, minSize: 24 * u, weight: 500, fam: SANS, lh: 1.45, maxH: bottom - y - 20 * u });
				draw(ctx, e, pad, y + 16 * u, { color: '#4a5566' });
			}
			footer(ctx, A, s, pad, bottom, W - pad * 2, u);
		},
	};

	// --- ব্রেকিং ----------------------------------------------------------
	T.breaking = {
		label: 'ব্রেকিং',
		layouts: { band: 'লাল ব্যান্ড', bold: 'পূর্ণ লাল' },
		draw(ctx, W, H, s, A, hook) {
			const u = W / 1080, red = '#D62828', land = W / H > 1.3, story = H / W > 1.6;
			H = adStrip(ctx, A, s, W, H, u);
			const pad = 60 * u;
			const top = story ? 200 * u : 0;
			if (s.layout === 'bold') {
				ctx.fillStyle = red; ctx.fillRect(0, 0, W, H);
				dots(ctx, 0, 0, W, H, 'rgba(255,255,255,.08)', 26 * u);
				const ph = land ? H : H * (story ? 0.36 : 0.42);
				const pw = land ? W * 0.45 : W;
				cover(ctx, A.image, land ? W - pw : 0, story ? top : 0, pw, ph, s.img); hook();
				const tx = pad, tw = land ? W - pw - pad * 2 : W - pad * 2;
				let y = land ? pad : (story ? top : 0) + ph + 50 * u;
				chip(ctx, '● ব্রেকিং নিউজ', tx, y, 32 * u, '#fff', red, { radius: 8 * u }); y += 90 * u;
				const f = fit(ctx, s.title, { maxW: tw, maxLines: 5, maxSize: (land ? 54 : 80) * u, minSize: 40 * u, maxH: H - y - 150 * u - (story ? 260 * u : 0) });
				draw(ctx, f, tx, y, { color: '#fff', hiColor: '#FFE08A' });
				footer(ctx, A, s, tx, H - 130 * u - (story ? 260 * u : 0), tw, u, { dark: true });
				return;
			}
			ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, W, H);
			const bandH = 104 * u;
			const ph = land ? H - bandH : story ? H * 0.42 : H * (H > W ? 0.5 : 0.46);
			cover(ctx, A.image, land ? 0 : 0, top + bandH, land ? W * 0.5 : W, ph, s.img); hook();
			ctx.fillStyle = red; ctx.fillRect(0, 0, W, top + bandH);
			ctx.fillStyle = '#fff'; ctx.beginPath(); ctx.arc(pad + 14 * u, top + bandH / 2, 14 * u, 0, Math.PI * 2); ctx.fill();
			ctx.strokeStyle = 'rgba(255,255,255,.4)'; ctx.lineWidth = 8 * u; ctx.beginPath(); ctx.arc(pad + 14 * u, top + bandH / 2, 24 * u, 0, Math.PI * 2); ctx.stroke();
			text1(ctx, 'ব্রেকিং নিউজ', pad + 54 * u, top + bandH / 2 + 4 * u, 46 * u, { weight: 800, fam: SERIF, color: '#fff', base: 'middle' });
			if (s.time) text1(ctx, s.time, W - pad, top + bandH / 2 + 4 * u, 34 * u, { weight: 700, color: '#fff', align: 'right', base: 'middle' });
			const tx = land ? W * 0.5 + 44 * u : pad, tw = land ? W * 0.5 - 88 * u : W - pad * 2;
			let y = land ? top + bandH + 40 * u : top + bandH + ph + 50 * u;
			const bottom = H - (story ? 260 * u : 30 * u) - 110 * u;
			const f = fit(ctx, s.title, { maxW: tw, maxLines: 4, maxSize: (land ? 48 : story ? 76 : 66) * u, minSize: 34 * u, maxH: bottom - y - 30 * u });
			y = draw(ctx, f, tx, y, { color: '#141414', hiColor: red });
			ctx.fillStyle = red; ctx.fillRect(tx, y + 14 * u, 90 * u, 7 * u);
			footer(ctx, A, s, tx, bottom, tw, u, { rule: false });
		},
	};

	// --- ফিচার -------------------------------------------------------------
	T.feature = {
		label: 'ফিচার',
		layouts: { cinema: 'সিনেমাটিক', magazine: 'ম্যাগাজিন', frame: 'ফ্রেমড' },
		draw(ctx, W, H, s, A, hook) {
			const u = W / 1080, P = s.P, land = W / H > 1.3, story = H / W > 1.6;
			H = adStrip(ctx, A, s, W, H, u);
			const pad = 70 * u;
			const kicker = s.label || 'মিহা স্পেশাল';
			if (s.layout === 'magazine') {
				ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, W, H);
				const ph = land ? H : H * (story ? 0.58 : 0.64);
				cover(ctx, A.image, 0, 0, land ? W * 0.55 : W, ph, s.img); hook();
				const tx = land ? W * 0.55 + 50 * u : pad, tw = land ? W * 0.45 - 100 * u : W - pad * 2;
				let y = land ? 60 * u : ph + 48 * u;
				ctx.fillStyle = P.accent; ctx.fillRect(tx, y + 12 * u, 46 * u, 5 * u);
				text1(ctx, kicker, tx + 62 * u, y + 24 * u, 30 * u, { weight: 700, color: P.accent });
				y += 54 * u;
				const bottom = H - (story ? 260 * u : 36 * u) - 100 * u;
				const f = fit(ctx, s.title, { maxW: tw, maxLines: 4, maxSize: (land ? 52 : 74) * u, minSize: 36 * u, maxH: bottom - y - 20 * u, weight: 800 });
				draw(ctx, f, tx, y, { color: P.ink, hiColor: P.accent });
				footer(ctx, A, s, tx, bottom, tw, u);
				return;
			}
			cover(ctx, A.image, 0, 0, W, H, s.img); hook();
			vGradient(ctx, 0, H * 0.35, W, H * 0.65, [[0, 'rgba(8,14,24,0)'], [0.6, 'rgba(8,14,24,.72)'], [1, 'rgba(8,14,24,.94)']]);
			if (s.layout === 'frame') {
				ctx.strokeStyle = 'rgba(255,255,255,.85)'; ctx.lineWidth = 3 * u;
				ctx.strokeRect(36 * u, 36 * u, W - 72 * u, H - 72 * u);
				cornerBrackets(ctx, 22 * u, 90 * u, 8 * u, P.hi);
			} else {
				cornerBrackets(ctx, 40 * u, 70 * u, 6 * u, '#fff');
			}
			const tw = land ? W * 0.7 : W - pad * 2;
			const footY = H - (story ? 300 * u : pad) - 70 * u;
			const f = fit(ctx, s.title, { maxW: tw, maxLines: 4, maxSize: (land ? 56 : story ? 86 : 76) * u, minSize: 40 * u, weight: 800 });
			const ty = footY - 40 * u - f.lines.length * f.lh;
			ctx.fillStyle = P.hi; ctx.fillRect(pad, ty - 50 * u, 46 * u, 5 * u);
			text1(ctx, kicker, pad + 62 * u, ty - 36 * u, 30 * u, { weight: 700, color: P.hi });
			draw(ctx, f, pad, ty, { color: '#fff', hiColor: P.hi });
			logo(ctx, A, pad, footY + 10 * u, 50 * u, { light: true, color: '#fff' });
			if (s.site) text1(ctx, s.site, W - pad, footY + 46 * u, 24 * u, { align: 'right', color: 'rgba(255,255,255,.85)' });
		},
	};

	// --- মতামত -------------------------------------------------------------
	T.opinion = {
		label: 'মতামত',
		layouts: { highlight: 'হাইলাইট উক্তি', split: 'লেখকের ছবি পাশে', bold: 'গাঢ় রঙ' },
		draw(ctx, W, H, s, A, hook) {
			const u = W / 1080, P = s.P, land = W / H > 1.3, story = H / W > 1.6;
			H = adStrip(ctx, A, s, W, H, u);
			const pad = 72 * u;
			const quote = s.quote || s.title || '';
			const label = s.label || 'মতামত';
			const safeTop = story ? 220 * u : 0, safeBot = story ? 260 * u : 0;

			if (s.layout === 'split') {
				ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, W, H);
				const vertical = !land;
				const blockH = vertical ? (story ? H * 0.4 : H * 0.38) : H;
				const blockW = vertical ? W : W * 0.38;
				ctx.fillStyle = P.accent; ctx.fillRect(0, 0, blockW, blockH);
				dots(ctx, 0, 0, blockW, blockH, 'rgba(255,255,255,.09)', 24 * u);
				hook();
				const r = (vertical ? 130 : 120) * u;
				const cx = vertical ? pad + r : blockW / 2;
				const cy = vertical ? safeTop + blockH / 2 - (story ? 40 * u : 0) : H * 0.4;
				circleImage(ctx, A.author, cx, cy, r, { ring: '#fff', ringW: 8 * u, ringGap: 6 * u, name: s.author });
				if (vertical) {
					const nx = cx + r + 44 * u;
					chip(ctx, label, nx, cy - 96 * u, 24 * u, 'rgba(255,255,255,.18)', '#fff');
					text1(ctx, s.author || '', nx, cy + 6 * u, 46 * u, { weight: 800, fam: SERIF, color: '#fff', maxW: W - nx - pad });
					text1(ctx, s.designation || '', nx, cy + 54 * u, 27 * u, { color: 'rgba(255,255,255,.88)', maxW: W - nx - pad });
				} else {
					const nf = fit(ctx, s.author || '', { maxW: blockW - 50 * u, maxLines: 2, maxSize: 34 * u, minSize: 24 * u, weight: 800 });
					const ny = draw(ctx, nf, cx, cy + r + 24 * u, { align: 'center', color: '#fff' });
					const df = fit(ctx, s.designation || '', { maxW: blockW - 50 * u, maxLines: 2, maxSize: 22 * u, minSize: 18 * u, weight: 500, fam: SANS });
					draw(ctx, df, cx, ny + 6 * u, { align: 'center', color: 'rgba(255,255,255,.88)' });
				}
				const tx = vertical ? pad : blockW + 50 * u, tw = vertical ? W - pad * 2 : W - blockW - 100 * u;
				let y = vertical ? blockH + 40 * u : 50 * u;
				text1(ctx, '“', tx - 8 * u, y + 150 * u, 200 * u, { fam: SERIF, weight: 800, color: rgba(P.accent, 0.2) });
				y += 70 * u;
				const bottom = H - safeBot - 110 * u;
				const f = fit(ctx, quote, { maxW: tw, maxLines: land ? 5 : 8, maxSize: (land ? 46 : story ? 76 : 66) * u, minSize: 30 * u, lh: 1.4, maxH: bottom - y - 30 * u });
				const bh = f.lines.length * f.lh;
				draw(ctx, f, tx, y + Math.max(0, (bottom - y - 30 * u - bh) * 0.4), { color: P.ink, hiColor: P.deep, hiBand: rgba(P.hi, 0.75) });
				footer(ctx, A, s, tx, bottom, tw, u, { logoH: 46 });
				return;
			}

			const bold = s.layout === 'bold';
			if (bold) {
				const g = ctx.createLinearGradient(0, 0, W, H);
				g.addColorStop(0, P.accent); g.addColorStop(1, P.deep);
				ctx.fillStyle = g; ctx.fillRect(0, 0, W, H);
			} else {
				ctx.fillStyle = P.soft; ctx.fillRect(0, 0, W, H);
				softBlobs(ctx, P, W, H, u);
				ctx.fillStyle = P.accent; ctx.fillRect(0, 0, 14 * u, H);
			}
			hook();
			const ink = bold ? '#fff' : P.ink;
			const sub = bold ? 'rgba(255,255,255,.85)' : '#4a5566';
			let y = safeTop + pad * 0.8;
			chip(ctx, label, pad, y, 26 * u, bold ? 'rgba(255,255,255,.16)' : P.accent, '#fff', { stroke: bold ? 'rgba(255,255,255,.6)' : null });
			logo(ctx, A, W - pad, y - 2 * u, 48 * u, { light: bold, align: 'right', color: bold ? '#fff' : P.accent });
			y += 64 * u;
			const qColor = bold ? 'rgba(255,255,255,.22)' : rgba(P.accent, 0.22);
			const hiOpts = bold ? { color: '#fff', hiColor: P.hi } : { color: ink, hiColor: P.deep, hiBand: rgba(P.hi, 0.8) };
			if (land) {
				// quote left, author column right
				const colW = W * 0.3, tw = W - pad * 2 - colW - 40 * u;
				text1(ctx, '“', pad - 8 * u, y + 150 * u, 200 * u, { fam: SERIF, weight: 800, color: qColor });
				const top = y + 40 * u, bottom = H - 50 * u;
				const f = fit(ctx, quote, { maxW: tw, maxLines: 5, maxSize: 50 * u, minSize: 30 * u, lh: 1.42, maxH: bottom - top });
				const bh = f.lines.length * f.lh;
				draw(ctx, f, pad, top + Math.max(0, (bottom - top - bh) / 2), hiOpts);
				const cx = W - pad - colW / 2, r = 90 * u, cy = H * 0.45;
				ctx.fillStyle = bold ? 'rgba(255,255,255,.25)' : rgba(P.accent, 0.25);
				ctx.fillRect(W - pad - colW - 20 * u, top, 2 * u, bottom - top);
				circleImage(ctx, A.author, cx, cy, r, { ring: bold ? '#fff' : P.accent, ringW: 5 * u, ringGap: 5 * u, name: s.author });
				const nf = fit(ctx, s.author || '', { maxW: colW, maxLines: 2, maxSize: 34 * u, minSize: 24 * u, weight: 800 });
				const ny = draw(ctx, nf, cx, cy + r + 20 * u, { align: 'center', color: ink });
				const df = fit(ctx, s.designation || '', { maxW: colW, maxLines: 2, maxSize: 22 * u, minSize: 18 * u, weight: 500, fam: SANS });
				draw(ctx, df, cx, ny + 6 * u, { align: 'center', color: sub });
				return;
			}
			text1(ctx, '“', pad - 10 * u, y + 200 * u, 270 * u, { fam: SERIF, weight: 800, color: qColor });
			y += 130 * u;
			const authorBlock = 200 * u;
			const bottom = H - safeBot - pad * 0.5 - authorBlock;
			const tw = W - pad * 2;
			const f = fit(ctx, quote, { maxW: tw, maxLines: story ? 10 : H > W ? 8 : 6, maxSize: (story ? 82 : H > W ? 74 : 68) * u, minSize: 32 * u, lh: 1.42, maxH: bottom - y - 30 * u });
			const bh = f.lines.length * f.lh;
			// Keep the quote near the quote mark, but centre it when there is lots of room.
			draw(ctx, f, pad, y + Math.max(0, (bottom - y - bh) * 0.35), hiOpts);
			let ay = bottom + 20 * u;
			ctx.fillStyle = bold ? 'rgba(255,255,255,.3)' : rgba(P.accent, 0.25);
			ctx.fillRect(pad, ay, W - pad * 2, 2 * u);
			ay += 34 * u;
			const r = 66 * u;
			circleImage(ctx, A.author, pad + r, ay + r, r, { ring: bold ? '#fff' : P.accent, ringW: 5 * u, ringGap: 5 * u, name: s.author });
			const nx = pad + r * 2 + 36 * u;
			text1(ctx, s.author || '', nx, ay + r - 6 * u, 42 * u, { weight: 800, fam: SERIF, color: ink, maxW: W - nx - pad });
			text1(ctx, s.designation || '', nx, ay + r + 40 * u, 27 * u, { color: sub, maxW: W - nx - pad });
		},
	};

	// --- উক্তি -------------------------------------------------------------
	T.quote = {
		label: 'উক্তি',
		layouts: { arch: 'খিলান', clean: 'পরিচ্ছন্ন' },
		draw(ctx, W, H, s, A, hook) {
			const u = W / 1080, P = s.P, story = H / W > 1.6, land = W / H > 1.3;
			H = adStrip(ctx, A, s, W, H, u);
			const quote = s.quote || s.title || '';
			if (s.layout === 'clean') {
				ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, W, H);
				ctx.fillStyle = P.soft; ctx.fillRect(0, 0, W, H * 0.12);
				ctx.fillStyle = P.accent; ctx.fillRect(0, H * 0.12, W, 6 * u);
				hook();
				const pad = 90 * u;
				chip(ctx, s.label || 'উক্তি', pad, H * 0.06 - 24 * u, 26 * u, P.accent, '#fff');
				logo(ctx, A, W - pad, H * 0.06 - 26 * u, 50 * u, { align: 'right', color: P.accent });
				const tw = W - pad * 2;
				const f = fit(ctx, quote, { maxW: tw, maxLines: land ? 4 : 9, maxSize: (land ? 46 : story ? 84 : 70) * u, minSize: 34 * u, lh: 1.45, maxH: H * 0.55 });
				const blockH = f.lines.length * f.lh;
				let y = H * 0.12 + (H * 0.68 - blockH) / 2;
				ctx.fillStyle = P.accent; ctx.fillRect(pad - 34 * u, y, 8 * u, blockH);
				draw(ctx, f, pad, y, { color: P.ink, hiColor: P.accent });
				text1(ctx, '— ' + (s.author || ''), pad, H * 0.86, 36 * u, { weight: 700, fam: SERIF, color: P.ink });
				text1(ctx, s.designation || '', pad, H * 0.86 + 44 * u, 26 * u, { color: '#5b6676' });
				return;
			}
			ctx.fillStyle = P.deep; ctx.fillRect(0, 0, W, H);
			// faint lattice
			ctx.strokeStyle = rgba(P.hi, 0.1); ctx.lineWidth = 1.5 * u;
			for (let x = -H; x < W; x += 60 * u) { ctx.beginPath(); ctx.moveTo(x, 0); ctx.lineTo(x + H, H); ctx.moveTo(x + H, 0); ctx.lineTo(x, H); ctx.stroke(); }
			hook();
			const m = 44 * u, top = story ? 220 * u : m, bot = story ? 240 * u : m;
			const gold = P.hi;
			ctx.strokeStyle = gold; ctx.lineWidth = 3 * u;
			const aw = W - m * 2, r = aw / 2;
			ctx.beginPath();
			ctx.moveTo(m, H - bot); ctx.lineTo(m, top + r); ctx.arc(W / 2, top + r, r, Math.PI, 0); ctx.lineTo(W - m, H - bot); ctx.closePath(); ctx.stroke();
			ctx.lineWidth = 1.5 * u;
			const m2 = m + 14 * u, r2 = r - 14 * u;
			ctx.beginPath(); ctx.moveTo(m2, H - bot - 14 * u); ctx.lineTo(m2, top + r); ctx.arc(W / 2, top + r, r2, Math.PI, 0); ctx.lineTo(W - m2, H - bot - 14 * u); ctx.closePath(); ctx.stroke();
			let y = top + (land ? 30 : 120) * u;
			chip(ctx, s.label || 'উক্তি', W / 2, y, 24 * u, 'transparent', gold, { align: 'center', stroke: gold });
			y += 80 * u;
			text1(ctx, '“', W / 2, y + 110 * u, 150 * u, { align: 'center', fam: SERIF, weight: 800, color: gold });
			y += 130 * u;
			const f = fit(ctx, quote, { maxW: W * 0.7, maxLines: land ? 3 : story ? 9 : 6, maxSize: (land ? 40 : 62) * u, minSize: 32 * u, lh: 1.45, maxH: H - bot - y - 220 * u });
			y = draw(ctx, f, W / 2, y, { align: 'center', color: '#fff', hiColor: gold });
			text1(ctx, s.author || '', W / 2, y + 70 * u, 38 * u, { align: 'center', weight: 800, fam: SERIF, color: '#fff' });
			text1(ctx, s.designation || '', W / 2, y + 112 * u, 24 * u, { align: 'center', color: 'rgba(255,255,255,.75)' });
			if (land) logo(ctx, A, W - 70 * u, 60 * u, 40 * u, { light: true, align: 'right', color: gold });
			else logo(ctx, A, W / 2, H - bot - 80 * u, 44 * u, { light: true, align: 'center', color: gold });
		},
	};

	// --- সাহিত্য ------------------------------------------------------------
	T.literature = {
		label: 'সাহিত্য',
		layouts: { paper: 'কাগজ', window: 'জানালা ছবি', modern: 'আধুনিক' },
		draw(ctx, W, H, s, A, hook) {
			const u = W / 1080, P = s.P, story = H / W > 1.6, land = W / H > 1.3;
			H = adStrip(ctx, A, s, W, H, u);
			const label = s.label || 'সাহিত্য';
			const top = story ? 220 * u : 0, bot = story ? 240 * u : 0;
			if (s.layout === 'modern') {
				ctx.fillStyle = P.soft; ctx.fillRect(0, 0, W, H);
				const ph = land ? H : H * (story ? 0.45 : 0.46);
				cover(ctx, A.image, 0, 0, land ? W * 0.46 : W, ph, s.img); hook();
				const pad = 70 * u, tx = land ? W * 0.46 + 50 * u : pad, tw = land ? W * 0.54 - 100 * u : W - pad * 2;
				let y = land ? 50 * u : ph + 44 * u;
				text1(ctx, label.toUpperCase(), tx, y + 26 * u, 26 * u, { weight: 700, color: P.accent });
				y += 46 * u;
				const f = fit(ctx, s.title, { maxW: tw, maxLines: 2, maxSize: (land ? 50 : 70) * u, minSize: 36 * u, weight: 800 });
				y = draw(ctx, f, tx, y, { color: P.ink });
				text1(ctx, s.author ? 'লিখেছেন ' + s.author : '', tx, y + 46 * u, 28 * u, { fam: LIT, color: P.accent, weight: 400 });
				y += 74 * u;
				const bottom = H - bot - 100 * u;
				if (s.excerpt) {
					ctx.fillStyle = P.accent; ctx.fillRect(tx, y, 6 * u, Math.min(bottom - y - 30 * u, 400 * u));
					const e = fit(ctx, s.excerpt, { maxW: tw - 34 * u, maxLines: 14, maxSize: (story ? 38 : 32) * u, minSize: 24 * u, weight: 400, fam: LIT, lh: 1.6, maxH: bottom - y - 30 * u });
					draw(ctx, e, tx + 34 * u, y - 8 * u, { color: '#3d4350', hiColor: P.accent });
				}
				footer(ctx, A, s, tx, bottom, tw, u, { logoH: 44 });
				return;
			}
			paper(ctx, W, H, '#FBF6EC');
			const ink = '#2E2618';
			const ornament = s.P === R.palettes.nil ? '#8C6A2E' : P.accent;
			const m = 40 * u;
			ctx.strokeStyle = rgba(ornament, 0.7); ctx.lineWidth = 3 * u; ctx.strokeRect(m, m + top * 0.4, W - m * 2, H - m * 2 - (top + bot) * 0.4);
			ctx.lineWidth = 1.2 * u; ctx.strokeRect(m + 12 * u, m + 12 * u + top * 0.4, W - m * 2 - 24 * u, H - m * 2 - 24 * u - (top + bot) * 0.4);
			let y = top + 110 * u;
			if (s.layout === 'window' && !land) {
				const ww = W * 0.56, wh = H * (story ? 0.28 : 0.3), wx = (W - ww) / 2;
				ctx.save();
				ctx.beginPath(); ctx.moveTo(wx, y + wh); ctx.lineTo(wx, y + ww / 2); ctx.arc(W / 2, y + ww / 2, ww / 2, Math.PI, 0); ctx.lineTo(wx + ww, y + wh); ctx.closePath();
				ctx.clip(); cover(ctx, A.image, wx, y, ww, wh, s.img, { ph1: '#e9dcc0', ph2: '#cdb88f' }); ctx.restore();
				ctx.strokeStyle = ornament; ctx.lineWidth = 3 * u;
				ctx.beginPath(); ctx.moveTo(wx, y + wh); ctx.lineTo(wx, y + ww / 2); ctx.arc(W / 2, y + ww / 2, ww / 2, Math.PI, 0); ctx.lineTo(wx + ww, y + wh); ctx.closePath(); ctx.stroke();
				hook();
				y += wh + 40 * u;
			} else {
				hook();
				vine(ctx, m + 24 * u, m + 24 * u + top * 0.4, 0.9 * u, rgba(ornament, 0.55), 0);
				vine(ctx, W - m - 24 * u, H - m - 24 * u - bot * 0.4, 0.9 * u, rgba(ornament, 0.55), 2);
			}
			text1(ctx, '— ' + label + ' —', W / 2, y + 30 * u, 28 * u, { align: 'center', weight: 600, color: ornament, fam: SANS });
			y += 60 * u;
			const f = fit(ctx, s.title, { maxW: W * 0.72, maxLines: 2, maxSize: (land ? 52 : story ? 84 : 74) * u, minSize: 38 * u, weight: 800 });
			y = draw(ctx, f, W / 2, y, { align: 'center', color: ink });
			if (s.author) { text1(ctx, s.author, W / 2, y + 50 * u, 32 * u, { align: 'center', fam: LIT, weight: 400, color: ornament }); y += 56 * u; }
			flourish(ctx, W / 2, y + 40 * u, 280 * u, ornament, u);
			y += 80 * u;
			const bottom = H - bot - 150 * u;
			if (s.excerpt && bottom - y > 60 * u) {
				const e = fit(ctx, s.excerpt, { maxW: W * 0.72, maxLines: 14, maxSize: (story ? 44 : H > W ? 38 : 34) * u, minSize: 26 * u, weight: 400, fam: LIT, lh: 1.6, maxH: bottom - y });
				draw(ctx, e, W / 2, y + Math.max(0, (bottom - y - e.lines.length * e.lh) * 0.3), { align: 'center', color: '#3B3224', hiColor: ornament });
			}
			logo(ctx, A, W / 2, H - bot - 120 * u, 44 * u, { align: 'center', color: ornament });
		},
	};

	// --- কবিতা --------------------------------------------------------------
	T.poem = {
		label: 'কবিতা',
		layouts: { paper: 'কাগজ ও লতা', night: 'রাতের আকাশ', photo: 'ছবির ওপর' },
		draw(ctx, W, H, s, A, hook) {
			const u = W / 1080, P = s.P, story = H / W > 1.6, land = W / H > 1.3;
			H = adStrip(ctx, A, s, W, H, u);
			const top = story ? 220 * u : 0, bot = story ? 240 * u : 0;
			let ink, sub, accent;
			if (s.layout === 'night') {
				const g = ctx.createLinearGradient(0, 0, 0, H);
				g.addColorStop(0, '#0B1A3A'); g.addColorStop(1, '#22164A');
				ctx.fillStyle = g; ctx.fillRect(0, 0, W, H);
				stars(ctx, W, H, 160, '#ffffff');
				if (land) crescent(ctx, 110 * u, 110 * u, 50 * u, '#F6D88A'); else crescent(ctx, W - 160 * u, top + 150 * u, 60 * u, '#F6D88A');
				hook();
				ink = '#F4F1E8'; sub = '#F6D88A'; accent = '#F6D88A';
			} else if (s.layout === 'photo') {
				cover(ctx, A.image, 0, 0, W, H, s.img, { filter: 'blur(6px) brightness(.55)' });
				hook();
				ctx.fillStyle = 'rgba(0,0,0,.2)'; ctx.fillRect(0, 0, W, H);
				ink = '#fff'; sub = 'rgba(255,255,255,.85)'; accent = P.hi;
			} else {
				paper(ctx, W, H, '#FCF8EF');
				hook();
				accent = s.P === R.palettes.nil ? '#3E7A5A' : P.accent;
				vine(ctx, 30 * u, 30 * u + top * 0.5, 1.1 * u, rgba(accent, 0.6), 0);
				vine(ctx, W - 30 * u, 30 * u + top * 0.5, 1.1 * u, rgba(accent, 0.6), 1);
				vine(ctx, W - 30 * u, H - 30 * u - bot * 0.5, 1.1 * u, rgba(accent, 0.6), 2);
				vine(ctx, 30 * u, H - 30 * u - bot * 0.5, 1.1 * u, rgba(accent, 0.6), 3);
				ink = '#2B2418'; sub = accent;
			}
			const lines = String(s.poem || '').replace(/\r/g, '').split('\n');
			// Landscape: title block on the left, poem on the right.
			const cx = land ? W * 0.24 : W / 2;
			const colW = land ? W * 0.36 : W * 0.7;
			let y = land ? H * 0.22 : top + (story ? 120 : 110) * u;
			text1(ctx, s.label || 'কবিতা', cx, y, 26 * u, { align: 'center', weight: 600, color: sub });
			y += 24 * u;
			const f = fit(ctx, s.title, { maxW: colW, maxLines: 2, maxSize: (land ? 50 : story ? 76 : 68) * u, minSize: 34 * u, weight: 800 });
			y = draw(ctx, f, cx, y, { align: 'center', color: ink });
			if (s.author) { text1(ctx, s.author, cx, y + 46 * u, (story ? 34 : 30) * u, { align: 'center', fam: LIT, weight: 400, color: sub }); y += 50 * u; }
			flourish(ctx, cx, y + 36 * u, 220 * u, accent, u);
			y += 74 * u;
			if (land) logo(ctx, A, cx, H - 110 * u, 42 * u, { align: 'center', light: s.layout !== 'paper', color: accent });
			const px = land ? W * 0.68 : W / 2;
			const py = land ? 60 * u : y;
			const avail = (land ? H - 60 * u : H - bot - 140 * u) - py;
			const maxLineW = land ? W * 0.44 : W * 0.8;
			// Poem lines keep their own breaks; size shrinks to fit the space.
			let size = (story ? 58 : H > W ? 50 : 44) * u;
			for (; size > 16 * u; size -= 1 * u) {
				ctx.font = font(400, size, LIT);
				const widest = Math.max(...lines.map((l) => ctx.measureText(R.plain(l)).width), 0);
				if (lines.length * size * 1.6 <= avail && widest <= maxLineW) break;
			}
			const blockH = lines.length * size * 1.6;
			let ly = py + Math.max(0, (avail - blockH) / 2) + size;
			for (const l of lines) {
				text1(ctx, R.plain(l), px, ly, size, { align: 'center', fam: LIT, weight: 400, color: ink, maxW: maxLineW * 1.05 });
				ly += size * 1.6;
			}
			if (!land) logo(ctx, A, W / 2, H - bot - 110 * u, 42 * u, { align: 'center', light: s.layout !== 'paper', color: accent });
		},
	};

	// --- দিনের শুভেচ্ছা (শুভ সকাল) ---------------------------------------------
	T.daily = {
		label: 'শুভ সকাল',
		layouts: { classic: 'ক্লাসিক', soft: 'হালকা রঙিন' },
		draw(ctx, W, H, s, A, hook) {
			const u = W / 1080, P = s.P, story = H / W > 1.6, land = W / H > 1.3;
			H = adStrip(ctx, A, s, W, H, u);
			const D = R.dates(s.date, s.hijriOffset);
			const pad = 56 * u;
			const soft = s.layout === 'soft';
			ctx.fillStyle = soft ? P.soft : '#fff'; ctx.fillRect(0, 0, W, H);
			if (soft) softBlobs(ctx, P, W, H, u);
			const k = story ? 1.3 : 1;
			const panelH = land ? H : (story ? 760 : H > W ? 420 : 340) * u;
			const ph = land ? H : H - panelH;
			const pw = land ? W * 0.55 : W;
			if (soft) {
				const m = 40 * u;
				ctx.save(); roundRect(ctx, m, m, pw - m * 2, ph - m, 30 * u); ctx.clip(); cover(ctx, A.image, m, m, pw - m * 2, ph - m, s.img); ctx.restore();
			} else {
				cover(ctx, A.image, 0, 0, pw, ph, s.img);
			}
			hook();
			if (!soft) { ctx.fillStyle = P.accent; ctx.fillRect(land ? pw : 0, land ? 0 : ph, land ? 8 * u : W, land ? H : 8 * u); }
			const px = land ? pw + 44 * u : pad, pwid = land ? W - pw - 88 * u : W - pad * 2;
			let y = land ? 34 * u : ph + 30 * u;
			if (s.caption || s.credit) {
				text1(ctx, s.caption || '', px, y + 22 * u, 24 * u * k, { color: '#3a4555', maxW: pwid * 0.68 });
				if (s.credit) text1(ctx, 'ছবি: ' + s.credit, px + pwid, y + 22 * u, 20 * u * k, { align: 'right', color: '#7a8594' });
				y += 44 * u * k;
				ctx.fillStyle = 'rgba(15,27,45,.1)'; ctx.fillRect(px, y, pwid, 2 * u);
			}
			y += 36 * u * k;
			const greet = s.title || 'শুভ সকাল';
			const leftW = land ? pwid : pwid * 0.55;
			const gs = (land ? 64 : 88 * k) * u;
			sunIcon(ctx, px + gs * 0.38, y + gs * 0.72, gs * 0.3, '#F2A516');
			const f = fit(ctx, greet, { maxW: leftW - gs, maxLines: 1, maxSize: gs, minSize: 40 * u, weight: 800 });
			draw(ctx, f, px + gs, y - 8 * u, { color: P.accent });
			text1(ctx, D.weekday, px, y + gs * 1.6, 36 * u * (land ? 0.85 : k), { weight: 700, color: P.ink });
			logo(ctx, A, px, y + gs * 1.6 + 28 * u * k, 44 * u * (land ? 0.9 : k), { color: P.accent });
			const cal = [['খ্রিষ্টাব্দ', D.greg], ['বঙ্গাব্দ', D.bangla], ['হিজরি', D.hijri]];
			if (land) {
				// three calendars side by side under the greeting
				const cy = H - 120 * u, cw = pwid / 3;
				ctx.fillStyle = 'rgba(15,27,45,.1)'; ctx.fillRect(px, cy - 24 * u, pwid, 2 * u);
				cal.forEach(([kk, v], i) => {
					const parts = v.split(' ');
					const yr = parts.length > 1 ? parts.pop() : '';
					text1(ctx, kk, px + i * cw, cy - 4 * u, 18 * u, { color: '#7a8594' });
					text1(ctx, parts.join(' '), px + i * cw, cy + 30 * u, 22 * u, { weight: 700, color: P.ink, maxW: cw - 10 * u });
					text1(ctx, yr, px + i * cw, cy + 60 * u, 22 * u, { weight: 700, color: P.ink });
				});
				return;
			}
			const cx = px + pwid * 0.6;
			let cy = y - 6 * u;
			ctx.fillStyle = 'rgba(15,27,45,.12)'; ctx.fillRect(cx - 26 * u, y, 2 * u, 210 * u * k);
			cal.forEach(([kk, v]) => {
				text1(ctx, kk, cx, cy + 20 * u * k, 18 * u * k, { color: '#7a8594' });
				text1(ctx, v, cx, cy + 54 * u * k, 29 * u * k, { weight: 700, color: P.ink, maxW: pwid * 0.4 });
				cy += 72 * u * k;
			});
		},
	};

	// --- দিবস ও শুভেচ্ছা -----------------------------------------------------
	R.occasions = {
		birthday: { name: 'জন্মদিন', title: 'শুভ জন্মদিন', bg: '#FFF4F8', accent: '#D63384', ink: '#3A1028' },
		shok: { name: 'শোক', title: 'গভীর শোক', bg: '#1B1B1B', accent: '#E6E6E6', ink: '#FFFFFF', dark: true },
		bijoy: { name: 'বিজয় দিবস', title: 'মহান বিজয় দিবস', bg: '#F1F8F4', accent: '#006A4E', red: '#F42A41', ink: '#0D2A20', date: '১৬ ডিসেম্বর' },
		swadhinota: { name: 'স্বাধীনতা দিবস', title: 'মহান স্বাধীনতা দিবস', bg: '#F1F8F4', accent: '#006A4E', red: '#F42A41', ink: '#0D2A20', date: '২৬ মার্চ' },
		ekushe: { name: 'অমর একুশে', title: 'অমর একুশে', bg: '#F6F6F6', accent: '#1A1A1A', red: '#D62828', ink: '#141414', date: '২১ ফেব্রুয়ারি' },
		boishakh: { name: 'পহেলা বৈশাখ', title: 'শুভ নববর্ষ', bg: '#FFF7F2', accent: '#D62828', ink: '#3A0F0F' },
		eid: { name: 'ঈদ', title: 'ঈদ মোবারক', bg: '#0B4F3C', accent: '#E0B84F', ink: '#FFFFFF', dark: true },
		puja: { name: 'শারদীয়', title: 'শারদীয় শুভেচ্ছা', bg: '#FFF6EC', accent: '#D9480F', ink: '#3A1A08' },
		custom: { name: 'কাস্টম', title: 'শুভেচ্ছা', bg: '#EAF2FB', accent: '#025AB2', ink: '#0F1B2D' },
	};

	T.occasion = {
		label: 'দিবস ও শুভেচ্ছা',
		layouts: { portrait: 'ব্যক্তির ছবি', scene: 'পূর্ণ ছবি' },
		draw(ctx, W, H, s, A, hook) {
			const u = W / 1080, story = H / W > 1.6, land = W / H > 1.3;
			H = adStrip(ctx, A, s, W, H, u);
			const O = R.occasions[s.occasion] || R.occasions.custom;
			const top = story ? 200 * u : 0, bot = story ? 240 * u : 0;
			const title = s.title || O.title;
			const scene = s.layout === 'scene';
			ctx.fillStyle = O.bg; ctx.fillRect(0, 0, W, H);
			if (scene) {
				cover(ctx, A.image, 0, 0, W, H, s.img, { filter: s.occasion === 'shok' ? 'grayscale(1)' : 'none' });
				vGradient(ctx, 0, H * 0.4, W, H * 0.6, [[0, rgba(O.dark ? '#000000' : O.bg, 0)], [1, rgba(O.dark ? '#000000' : O.bg, 0.95)]]);
			}
			hook();
			// motif per occasion
			const k = s.occasion;
			if (k === 'birthday') confetti(ctx, W, H, 90 * u, u);
			if (k === 'shok') { ctx.strokeStyle = 'rgba(255,255,255,.6)'; ctx.lineWidth = 2 * u; ctx.strokeRect(34 * u, 34 * u, W - 68 * u, H - 68 * u); ribbon(ctx, 60 * u, 60 * u + top, 130 * u, '#111', '#fff'); }
			if (k === 'bijoy' || k === 'swadhinota') {
				ctx.fillStyle = O.accent; ctx.fillRect(0, 0, W, 22 * u); ctx.fillRect(0, H - 22 * u, W, 22 * u);
				ctx.fillStyle = O.red; ctx.beginPath(); ctx.arc(W - 120 * u, top + 120 * u, 56 * u, 0, Math.PI * 2); ctx.fill();
			}
			if (k === 'ekushe') shaheedMinar(ctx, W - 380 * u, H - bot - 340 * u, 320 * u, scene ? '#fff' : '#1A1A1A', O.red);
			if (k === 'boishakh') { ctx.strokeStyle = O.accent; ctx.lineWidth = 26 * u; ctx.strokeRect(13 * u, 13 * u, W - 26 * u, H - 26 * u); ctx.fillStyle = '#fff'; for (let x = 40 * u; x < W; x += 60 * u) { ctx.beginPath(); ctx.arc(x, 13 * u, 6 * u, 0, 7); ctx.arc(x, H - 13 * u, 6 * u, 0, 7); ctx.fill(); } }
			if (k === 'eid') { stars(ctx, W, H * 0.5, 70, '#F6E7B0'); crescent(ctx, W - 170 * u, top + 160 * u, 70 * u, O.accent); }
			if (k === 'puja') { ctx.strokeStyle = '#B91C1C'; ctx.lineWidth = 18 * u; ctx.strokeRect(9 * u, 9 * u, W - 18 * u, H - 18 * u); ctx.strokeStyle = '#F59F00'; ctx.lineWidth = 5 * u; ctx.strokeRect(26 * u, 26 * u, W - 52 * u, H - 52 * u); }
			if (k === 'custom') softBlobs(ctx, R.paletteFromAccent(O.accent), W, H, u);

			const dark = O.dark || (scene && k === 'shok');
			const ink = scene ? (O.dark ? '#fff' : O.ink) : O.ink;
			// Taller canvases get a bigger, vertically spread stack.
			const k2 = land ? 1 : story ? 1.35 : H > W ? 1.15 : 1;
			let y = top + (land ? 40 : story ? 300 : H > W ? 130 : 110) * u;
			if (k === 'shok' && s.label) { text1(ctx, s.label, W / 2, y, 26 * u * k2, { align: 'center', color: 'rgba(255,255,255,.8)' }); y += 20 * u; }
			else if (O.date) { chip(ctx, O.date, W / 2, y - 30 * u, 26 * u * k2, O.red || O.accent, '#fff', { align: 'center' }); y += 50 * u * k2; }
			const tf = fit(ctx, title, { maxW: W * 0.8, maxLines: 2, maxSize: (land ? 60 : 92 * k2) * u, minSize: 44 * u, weight: 800 });
			y = draw(ctx, tf, W / 2, y, { align: 'center', color: k === 'shok' ? '#fff' : O.accent });
			if (!scene && !land) {
				const r = (story ? 290 : H > W ? 210 : 160) * u;
				const cy = y + 60 * u * k2 + r;
				circleImage(ctx, A.image, W / 2, cy, r, { ring: k === 'shok' ? '#fff' : O.accent, ringW: 8 * u, ringGap: 10 * u, filter: k === 'shok' ? 'grayscale(1)' : 'none', name: s.author });
				y = cy + r + 40 * u * k2;
			} else if (scene) {
				y = H - bot - 360 * u * k2;
			}
			const light = dark || O.dark;
			if (s.author) { const nf = fit(ctx, s.author, { maxW: W * 0.8, maxLines: 1, maxSize: 52 * u * k2, minSize: 32 * u, weight: 800 }); y = draw(ctx, nf, W / 2, y + 10 * u, { align: 'center', color: light ? '#fff' : ink }); }
			if (s.designation) { text1(ctx, s.designation, W / 2, y + 40 * u * k2, 28 * u * k2, { align: 'center', color: light ? 'rgba(255,255,255,.8)' : '#4a5566' }); y += 50 * u * k2; }
			if (s.message) {
				const mf = fit(ctx, s.message, { maxW: W * 0.76, maxLines: 4, maxSize: 30 * u * k2, minSize: 22 * u, weight: 500, fam: SANS, lh: 1.5, maxH: H - bot - 130 * u - y - 20 * u });
				draw(ctx, mf, W / 2, y + 20 * u * k2, { align: 'center', color: light ? 'rgba(255,255,255,.9)' : ink });
			}
			logo(ctx, A, W / 2, H - bot - 100 * u, 44 * u, { align: 'center', light: !!(dark || O.dark), color: dark || O.dark ? '#fff' : O.accent });
		},
	};

	// ------------------------------------------------------------------
	// Entry point
	// ------------------------------------------------------------------
	/**
	 * Render a card.
	 * @param {HTMLCanvasElement} canvas
	 * @param {object} s  state: template, layout, size, palette, title, …
	 * @param {object} A  loaded images: image, author, logo, logoLight, ad, overlay
	 */
	R.render = function (canvas, s, A) {
		const size = R.sizes[s.size] || R.sizes.square;
		canvas.width = size.w;
		canvas.height = size.h;
		const ctx = canvas.getContext('2d');
		ctx.clearRect(0, 0, size.w, size.h);
		const tpl = T[s.template] || T.news;
		const st = { ...s };
		st.P = s.palette === 'category' && s.catColor ? R.paletteFromAccent(s.catColor) : R.palettes[s.palette] || R.palettes.nil;
		if (!tpl.layouts[st.layout]) st.layout = Object.keys(tpl.layouts)[0];
		st.img = s.img || {};
		const D = R.dates(s.date, s.hijriOffset);
		st.dateLine = st.dateLine ?? D.greg;
		// Frame overlay sits above the photo/background and below the text.
		let overlaid = false;
		const hook = () => {
			if (overlaid || !A.overlay) return;
			overlaid = true;
			ctx.drawImage(A.overlay, 0, 0, size.w, size.h);
		};
		ctx.save();
		tpl.draw(ctx, size.w, size.h, st, A, hook);
		ctx.restore();
		hook();
		if (s.guides && s.size === 'story') {
			ctx.fillStyle = 'rgba(214,40,40,.12)';
			ctx.fillRect(0, 0, size.w, 220);
			ctx.fillRect(0, size.h - 260, size.w, 260);
		}
	};
})();
