/* Ctrl+K command palette (Phase 5): jump to any view or any species.
   Views match instantly; species come from /api/v1/species/search. */
(function () {
  'use strict';

  var VIEWS = [
    { label: 'Today', href: '?view=Now' },
    { label: 'Live', href: '?view=Live' },
    { label: 'Timeline', href: '?view=Timeline' },
    { label: 'Birds (species gallery)', href: '?view=Species' },
    { label: 'Recordings', href: '?view=Recordings' },
    { label: 'Review queue', href: '?view=Review' },
    { label: 'Insights: Dashboard', href: '?view=Insights&subview=dashboard' },
    { label: 'Insights: Charts', href: '?view=Analytics' },
    { label: 'Insights: Behavior', href: '?view=Insights&subview=behavior' },
    { label: 'Insights: Migration', href: '?view=Insights&subview=migration' },
    { label: 'Insights: Weather', href: '?view=Insights&subview=environmental' },
    { label: 'Insights: Health', href: '?view=Insights&subview=health' },
    { label: 'Insights: Trends & Forecasting', href: '?view=Insights&subview=forecasting' },
    { label: 'Insights: Reports', href: '?view=Insights&subview=report' },
    { label: 'Year in Birds', href: '?view=Year' },
    { label: 'Station Doctor', href: '?view=Doctor' },
    { label: 'Settings', href: '?view=Settings' },
    { label: 'Advanced settings', href: '?view=Advanced' },
    { label: 'Services', href: '?view=Services' },
    { label: 'System controls', href: '?view=System Controls' },
    { label: 'eBird export', href: '?view=eBird Export' },
    { label: 'Tools', href: '?view=Tools' }
  ];

  var overlay = null;
  var input = null;
  var list = null;
  var results = [];
  var selected = 0;
  var searchTimer = null;
  var lastQuery = '';

  function build() {
    overlay = document.createElement('div');
    overlay.className = 'palette-overlay';
    overlay.innerHTML =
      '<div class="palette-box" role="dialog" aria-modal="true" aria-label="Command palette">' +
      '<input type="text" id="paletteInput" placeholder="Jump to a page or search a species…" autocomplete="off">' +
      '<ul id="paletteList" role="listbox"></ul>' +
      '<div class="palette-hint"><kbd>↑</kbd><kbd>↓</kbd> navigate · <kbd>Enter</kbd> open · <kbd>Esc</kbd> close</div>' +
      '</div>';
    document.body.appendChild(overlay);
    input = overlay.querySelector('#paletteInput');
    list = overlay.querySelector('#paletteList');

    overlay.addEventListener('mousedown', function (e) {
      if (e.target === overlay) close();
    });
    input.addEventListener('input', function () {
      refresh(input.value.trim());
    });
    input.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown') { selected = Math.min(results.length - 1, selected + 1); paint(); e.preventDefault(); }
      else if (e.key === 'ArrowUp') { selected = Math.max(0, selected - 1); paint(); e.preventDefault(); }
      else if (e.key === 'Enter') { open(selected); e.preventDefault(); }
      else if (e.key === 'Escape') { close(); }
    });
    list.addEventListener('click', function (e) {
      var li = e.target.closest('li[data-i]');
      if (li) open(parseInt(li.getAttribute('data-i'), 10));
    });
  }

  function show() {
    if (!overlay) build();
    overlay.classList.add('open');
    input.value = '';
    refresh('');
    setTimeout(function () { input.focus(); }, 30);
  }

  function close() {
    if (overlay) overlay.classList.remove('open');
  }

  function refresh(query) {
    lastQuery = query;
    var q = query.toLowerCase();
    results = VIEWS.filter(function (v) {
      return q === '' || v.label.toLowerCase().indexOf(q) !== -1;
    }).slice(0, q === '' ? 8 : 6).map(function (v) {
      return { label: v.label, href: v.href, kind: 'page' };
    });
    selected = 0;
    paint();

    clearTimeout(searchTimer);
    if (q.length >= 2) {
      searchTimer = setTimeout(function () {
        fetch('api/v1/species/search?q=' + encodeURIComponent(query), { headers: { 'Accept': 'application/json' } })
          .then(function (r) { return r.ok ? r.json() : []; })
          .then(function (species) {
            if (lastQuery !== query) return; // stale response
            (species || []).slice(0, 6).forEach(function (s) {
              results.push({
                label: s.name,
                sub: s.sciName,
                href: '?view=Bird&sci_name=' + encodeURIComponent(s.sciName),
                kind: 'bird'
              });
            });
            paint();
          })
          .catch(function () {});
      }, 220);
    }
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (ch) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[ch];
    });
  }

  function paint() {
    if (results.length === 0) {
      list.innerHTML = '<li class="palette-empty">No matches.</li>';
      return;
    }
    list.innerHTML = results.map(function (r, i) {
      return '<li data-i="' + i + '" role="option"' + (i === selected ? ' class="selected" aria-selected="true"' : '') + '>' +
        '<span class="palette-kind">' + (r.kind === 'bird' ? '🐦' : '→') + '</span>' +
        '<span class="palette-label">' + esc(r.label) + (r.sub ? ' <em>' + esc(r.sub) + '</em>' : '') + '</span>' +
        '</li>';
    }).join('');
    var sel = list.querySelector('.selected');
    if (sel) sel.scrollIntoView({ block: 'nearest' });
  }

  function open(i) {
    if (results[i]) window.location = results[i].href;
  }

  document.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) {
      e.preventDefault();
      if (overlay && overlay.classList.contains('open')) close(); else show();
    }
  });

  window.BirdNETPalette = { show: show };
})();
