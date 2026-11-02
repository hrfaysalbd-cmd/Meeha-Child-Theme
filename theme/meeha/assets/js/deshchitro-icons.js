/* দেশচিত্র — indicator glyphs (24×24, stroke). Shared by the public page and the input sheet. */
(function () {
  'use strict';
  var P = {
    knife: '<path d="M14 4.5 20 2l-2.5 6L8 17.5 6.5 16z"/><path d="m6.5 16-3 3a1.4 1.4 0 0 0 2 2l3-3"/>',
    car: '<path d="M3.5 16v-3.2L5.6 7.6A2 2 0 0 1 7.5 6.3h9a2 2 0 0 1 1.9 1.3l2.1 5.2V16"/><path d="M2.5 16.5h19"/><circle cx="7" cy="17.5" r="1.8"/><circle cx="17" cy="17.5" r="1.8"/><path d="M5 12h14"/>',
    injury: '<rect x="3" y="7" width="18" height="10" rx="5" transform="rotate(-35 12 12)"/><path d="M10.6 10.6h.01M13.4 13.4h.01M13.4 10.6h.01M10.6 13.4h.01"/>',
    shield: '<path d="M12 3 4.5 6v5.5c0 4.6 3.1 8.4 7.5 9.5 4.4-1.1 7.5-4.9 7.5-9.5V6z"/><path d="M12 8.2v5.3M12 16.5v.01"/>',
    bag: '<path d="M5.5 8.5h13l-1 12h-11z"/><path d="M9 8.5V7a3 3 0 0 1 6 0v1.5"/><path d="m3 4 4 3M21 4l-4 3"/>',
    ribbon: '<path d="M12 4.5c1.8-2 4.4-1 4.4 1.6 0 2.2-2 4.4-4.4 7.2L7 21"/><path d="M12 4.5c-1.8-2-4.4-1-4.4 1.6 0 2.2 2 4.4 4.4 7.2L17 21"/>',
    water: '<path d="M2.5 9.5c2.4-2 4.6 2 7 0s4.6 2 7 0 4.6 2 5 1"/><path d="M2.5 14.5c2.4-2 4.6 2 7 0s4.6 2 7 0 4.6 2 5 1"/><path d="M2.5 19.5c2.4-2 4.6 2 7 0s4.6 2 7 0 4.6 2 5 1"/><path d="M12 2.5v3"/>',
    fire: '<path d="M12 21.5a6.5 6.5 0 0 0 6.5-6.5c0-4.6-4-6.4-4.6-11-2.6 1.6-4.3 4.2-4.3 7-1-.5-1.6-1.6-1.8-2.8C6.3 9.8 5.5 12 5.5 15a6.5 6.5 0 0 0 6.5 6.5Z"/><path d="M12 21.5a2.8 2.8 0 0 1-2.8-2.8c0-2 1.8-2.8 2.8-5 1 2.2 2.8 3 2.8 5a2.8 2.8 0 0 1-2.8 2.8Z"/>',
    cuffs: '<circle cx="7" cy="15.5" r="4"/><circle cx="17" cy="15.5" r="4"/><path d="M7 11.5V8.5a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v3"/>',
    star: '<path d="m12 3 2.7 5.6 6.1.8-4.5 4.2 1.1 6.1L12 16.8l-5.4 2.9 1.1-6.1-4.5-4.2 6.1-.8z"/>',
    missing: '<circle cx="12" cy="12" r="9"/><path d="M9.5 9.3a2.6 2.6 0 1 1 3.6 2.4c-.7.3-1.1.9-1.1 1.6v.7M12 17v.01"/>',
    pill: '<rect x="2.5" y="8" width="19" height="8" rx="4" transform="rotate(-40 12 12)"/><path d="m9 9 6 6"/>',
    bolt: '<path d="M13.5 2.5 5 13.5h6l-1 8 8.5-11h-6z"/>',
    alert: '<path d="M12 3.5 2.5 20h19z"/><path d="M12 10v4.5M12 17.2v.01"/>',
    people: '<circle cx="9" cy="8" r="3.2"/><path d="M3 20c.6-3.4 3-5.5 6-5.5s5.4 2.1 6 5.5"/><circle cx="17" cy="9" r="2.5"/><path d="M16.5 14.6c2.4.2 4 1.9 4.5 4.6"/>'
  };
  window.MeehaDCIcon = function (id, cls) {
    return '<svg class="' + (cls || 'dc-ico') + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' + (P[id] || P.alert) + '</svg>';
  };
})();
