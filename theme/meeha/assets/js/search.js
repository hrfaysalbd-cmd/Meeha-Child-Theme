/* search.js — results page enhancements (owner C). The page works fully without it (GET forms).
   - sort <select> submits on change (no-JS: a <noscript> button);
   - the date-range inputs show only while "নির্দিষ্ট তারিখ" is chosen; picking a date selects it;
   - empty inputs are not submitted (cleaner, shareable URLs).
   Recent searches + the search dialog are handled by A's meeha.js (localStorage `m-recent-search`). */
(() => {
  const sort = document.querySelector('form[data-sort] select[name=sort]');
  if (sort) sort.addEventListener('change', () => sort.form.submit());

  const form = document.querySelector('form[data-filters]');
  if (!form) return;
  const range = form.querySelector('[data-daterange]');
  const radios = [...form.querySelectorAll('input[name=when]')];
  const sync = () => { const r = radios.find(x => x.checked); if (range) range.hidden = !(r && r.value === 'range'); };
  radios.forEach(r => r.addEventListener('change', sync));
  if (range) range.querySelectorAll('input').forEach(i => i.addEventListener('input', () => { const r = radios.find(x => x.value === 'range'); if (r) { r.checked = true; sync(); } }));
  sync();
  form.addEventListener('submit', () => {
    form.querySelectorAll('input, select').forEach(el => {
      if (!el.name || el.type === 'radio') return;
      if (el.value === '' || (el.name === 'cat' && el.value === '0')) el.disabled = true;
    });
    const w = radios.find(x => x.checked);
    if (w && w.value === 'any') w.disabled = true;
    if (w && w.value !== 'range' && range) range.querySelectorAll('input').forEach(i => { i.disabled = true; });
  });
})();
