/* Meeha Studio settings: media pickers and custom-frame rows. */
(function () {
	'use strict';
	function pick(onSelect, title) {
		const frame = wp.media({ title: title || 'ছবি বাছুন', library: { type: 'image' }, multiple: false });
		frame.on('select', () => onSelect(frame.state().get('selection').first().toJSON()));
		frame.open();
	}
	document.addEventListener('click', (e) => {
		const box = e.target.closest('[data-ms-media]');
		if (box && e.target.matches('[data-ms-pick]')) {
			pick((a) => {
				box.querySelector('input').value = a.id;
				const url = (a.sizes && (a.sizes.medium || a.sizes.full) || a).url;
				box.querySelector('.ms-media-field__thumb').innerHTML = '<img src="' + url + '" alt="">';
			});
		}
		if (box && e.target.matches('[data-ms-clear]')) {
			box.querySelector('input').value = '0';
			box.querySelector('.ms-media-field__thumb').innerHTML = '';
		}
		if (e.target.matches('[data-ms-add-frame]')) {
			const sizes = JSON.parse(e.target.dataset.sizes);
			pick((a) => {
				const tbody = document.querySelector('[data-ms-frames] tbody');
				const i = 'n' + Date.now();
				const tr = document.createElement('tr');
				const opts = Object.entries(sizes).map(([k, v]) => {
					const o = document.createElement('option');
					o.value = k; o.textContent = v;
					if (a.width === 1080 && a.height === 1920 && k === 'story') o.selected = true;
					if (a.width === 1080 && a.height === 1350 && k === 'portrait') o.selected = true;
					if (a.width === 1200 && a.height === 630 && k === 'link') o.selected = true;
					return o.outerHTML;
				}).join('');
				const thumb = (a.sizes && a.sizes.thumbnail || a).url;
				tr.innerHTML = '<td><input type="hidden" name="frame_id[' + i + ']" value="' + a.id + '"><img src="' + thumb + '" width="80" alt=""></td>' +
					'<td><input name="frame_name[' + i + ']"></td><td><select name="frame_size[' + i + ']">' + opts + '</select></td>' +
					'<td><input type="checkbox" name="frame_keep[' + i + ']" value="1" checked></td>';
				tr.querySelector('input[name^="frame_name"]').value = a.title || 'নিজের ফ্রেম';
				tbody.appendChild(tr);
			}, 'স্বচ্ছ PNG ফ্রেম বাছুন');
		}
	});
})();
