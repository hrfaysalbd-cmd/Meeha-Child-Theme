/* Meeha Prep admin: media picker and test question picker. */
(function ($) {
	'use strict';

	$(document).on('click', '[data-mhp-media-pick]', function (e) {
		e.preventDefault();
		var box = $(this).closest('[data-mhp-media]');
		var frame = wp.media({ title: 'সার্কুলার বাছাই', multiple: false, library: { type: ['image', 'application/pdf'] } });
		frame.on('select', function () {
			var a = frame.state().get('selection').first().toJSON();
			box.find('input[type=hidden]').val(a.id);
			box.find('.mhp-media__name').text(a.filename || a.title);
		});
		frame.open();
	});
	$(document).on('click', '[data-mhp-media-clear]', function (e) {
		e.preventDefault();
		var box = $(this).closest('[data-mhp-media]');
		box.find('input[type=hidden]').val('0');
		box.find('.mhp-media__name').text('কিছু বাছাই করা হয়নি');
	});

	var list = $('[data-mhp-qlist]');
	if (!list.length) { return; }
	var BN = '০১২৩৪৫৬৭৮৯';
	function bn(n) { return String(n).replace(/\d/g, function (d) { return BN[d]; }); }
	function count() { $('[data-mhp-qcount]').text(bn(list.children('li').length) + 'টি প্রশ্ন'); }
	function has(id) { return list.children('li[data-id="' + id + '"]').length > 0; }

	list.on('click', '[data-mhp-up]', function () { var li = $(this).closest('li'); li.prev().before(li); });
	list.on('click', '[data-mhp-down]', function () { var li = $(this).closest('li'); li.next().after(li); });
	list.on('change', '[data-mhp-remove]', function () { $(this).closest('li').toggleClass('is-removed', this.checked); });

	var t = null;
	$('[data-mhp-qsearch]').on('input', function () {
		var q = $.trim(this.value);
		clearTimeout(t);
		if (q.length < 2) { $('[data-mhp-qresults]').empty(); return; }
		t = setTimeout(function () {
			$.get(MHPA.ajax, { action: 'mhp_find_questions', nonce: MHPA.nonce, q: q }, function (res) {
				var ul = $('[data-mhp-qresults]').empty();
				if (!res || !res.success || !res.data.length) { ul.append($('<li/>').text('কিছু পাওয়া যায়নি')); return; }
				res.data.forEach(function (r) {
					var b = $('<button type="button" class="button button-small"/>').text(has(r.id) ? 'যোগ আছে' : 'যোগ করুন').prop('disabled', has(r.id));
					b.on('click', function () {
						if (has(r.id)) { return; }
						var li = $('<li/>').attr('data-id', r.id);
						li.append($('<input type="hidden" name="mhp_questions[]"/>').val(r.id));
						li.append($('<span class="mhp-qlist__t"/>').text(r.title), ' ', $('<span class="description"/>').text('#' + r.id), ' ');
						li.append('<button type="button" class="button-link" data-mhp-up aria-label="ওপরে">↑</button> <button type="button" class="button-link" data-mhp-down aria-label="নিচে">↓</button> ');
						li.append($('<label/>').append($('<input type="checkbox" name="mhp_remove[]" data-mhp-remove/>').val(r.id), ' সরান'));
						list.append(li);
						b.text('যোগ আছে').prop('disabled', true);
						count();
					});
					ul.append($('<li/>').append($('<span/>').text(r.title + ' '), b));
				});
			});
		}, 300);
	});
})(jQuery);
