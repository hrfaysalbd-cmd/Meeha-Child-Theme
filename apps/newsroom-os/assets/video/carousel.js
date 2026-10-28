/* Meeha News — video carousel arrows (cards work without JS). */
(() => {
	'use strict';
	const reduce = window.matchMedia('(prefers-reduced-motion: reduce)');
	document.querySelectorAll('.mvc').forEach((sec) => {
		const track = sec.querySelector('.mvc-track');
		const arrows = sec.querySelectorAll('.mvc-arrow');
		if (!track || arrows.length < 2) return;
		let t = 0;
		const update = () => {
			const max = track.scrollWidth - track.clientWidth - 2;
			arrows[0].disabled = track.scrollLeft <= 2;
			arrows[1].disabled = track.scrollLeft >= max;
			sec.classList.toggle('is-static', max <= 0);
		};
		sec.addEventListener('click', (e) => {
			const b = e.target.closest('.mvc-arrow');
			if (!b) return;
			const card = track.querySelector('.mvc-card');
			const w = card ? card.getBoundingClientRect().width + parseFloat(getComputedStyle(track).columnGap || 0) : 200;
			const n = Math.max(1, Math.floor(track.clientWidth / w) - 1);
			track.scrollBy({ left: +b.dataset.dir * n * w, behavior: reduce.matches ? 'auto' : 'smooth' });
		});
		track.addEventListener('scroll', () => { clearTimeout(t); t = setTimeout(update, 60); }, { passive: true });
		window.addEventListener('resize', update, { passive: true });
		update();
	});
})();
