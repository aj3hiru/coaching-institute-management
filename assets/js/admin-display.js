/* Display options (top of every admin page): show / hide each section of the page and each element inside it —
 * summary tiles, table columns, buttons, list rows, optional form fields. Choices are kept per page in this browser.
 * Sections are found automatically (cards and blocks of the page); a page can name one with data-ds="Name".
 */
(function () {
  'use strict';
  var KEY = 'dsp:' + location.pathname + (/[?&]tab=([\w-]+)/.exec(location.search) || ['', ''])[1];
  var state = { hidden: {} };
  try { state = JSON.parse(localStorage.getItem(KEY)) || state; state.hidden = state.hidden || {}; } catch (e) {}
  function save() { try { localStorage.setItem(KEY, JSON.stringify(state)); } catch (e) {} }
  function txt(el) { return el ? (el.textContent || '').replace(/\s+/g, ' ').trim() : ''; }
  function slug(s) { return s.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 40) || 'x'; }

  var root, sections = [], styleEl;
  function container() {
    var cw = document.querySelector('.main-content .content-wrapper');
    if (!cw) return null;
    var ci = cw.querySelector(':scope > .ci');
    return ci || cw;
  }
  function isBlock(el) {
    if (!el || el.nodeType !== 1) return false;
    if (/^(SCRIPT|STYLE|TEMPLATE|INPUT|LINK|META)$/.test(el.tagName)) return false;
    if (el.matches('.ci-modal, .modal, [role=dialog], .ci-alert, .alert, .toast, #umModal, #umDel')) return false;
    return true;
  }
  function nameOf(el, i) {
    if (el.dataset.ds) return el.dataset.ds;
    if (el.matches('.ci-stats, .stats-grid, .stat-cards, .dash-stats')) return 'Summary tiles';
    if (el.matches('.ci-tabs, .tabs, .ai-tabs')) return 'Tabs';
    if (el.matches('.ci-head')) return 'Page bar (count, buttons)';
    var h = el.querySelector('.ci-card-h h3, .card-header h3, .card-title, h2, h3, h4, legend, .form-section-title');
    var n = txt(h);
    if (!n && el.querySelector('form.ci-filters, .filter-group, .filters')) n = 'Filters';
    if (!n && el.querySelector('table')) n = 'Table';
    return n || ('Section ' + (i + 1));
  }
  // blocks of the page (descend into wrappers such as <form>, tab panes and grids of cards)
  function collect(parent, out, depth) {
    [].forEach.call(parent.children, function (el) {
      if (!isBlock(el)) return;
      var kids = [].filter.call(el.children, isBlock);
      var wrapper = depth < 3 && (el.matches('form:not(.ci-filters), section.ci-pane, .ci-grid2, .ci-grid3, .dashboard-grid, .grid, .row') && kids.length > 1 && !el.matches('.ci-card, .card'));
      if (wrapper) collect(el, out, depth + 1); else out.push(el);
    });
  }
  function elementsOf(sec, skey) {
    var list = [];
    sec.querySelectorAll('.ci-stat, .stat-card').forEach(function (t, i) { list.push({ key: skey + ':t' + i, label: 'Tile: ' + (txt(t.querySelector('small, .stat-label, p')) || txt(t).slice(0, 30)), el: t }); });
    sec.querySelectorAll('table').forEach(function (tb, ti) {
      var ths = tb.querySelectorAll('thead th');
      if (!tb.dataset.dspT) tb.dataset.dspT = skey.replace(/[^a-z0-9]/g, '') + ti;
      ths.forEach(function (th, ci) { var l = txt(th) || (ci === ths.length - 1 ? 'Actions' : 'Column ' + (ci + 1)); list.push({ key: skey + ':c' + ti + '_' + ci, label: 'Column: ' + l, col: [tb.dataset.dspT, ci + 1] }); });
    });
    sec.querySelectorAll('.ci-list > li').forEach(function () {}); // rows change with data — not offered
    sec.querySelectorAll('.ci-actions > a, .ci-actions > button, .ci-top-actions > a, .ci-fbtn > a, .ci-fbtn > button, .card-header .btn').forEach(function (b, i) { var l = txt(b) || b.title; if (l) list.push({ key: skey + ':b' + slug(l), label: 'Button: ' + l, el: b }); });
    sec.querySelectorAll('.ci-f, .form-group, .filter-group').forEach(function (f, i) {
      var c = f.querySelector('input, select, textarea');
      if (!c || c.required || c.type === 'hidden') return;
      var l = txt(f.querySelector('label')); if (!l) return;
      list.push({ key: skey + ':f' + slug(l) + i, label: 'Field: ' + l, el: f });
    });
    sec.querySelectorAll('.ci-kv dt').forEach(function (dt) { var l = txt(dt); list.push({ key: skey + ':k' + slug(l), label: 'Detail: ' + l, el: dt, el2: dt.nextElementSibling }); });
    return list;
  }
  function build() {
    root = container();
    if (!root) return false;
    var blocks = [];
    collect(root, blocks, 0);
    var seen = {};
    sections = blocks.map(function (el, i) {
      var name = nameOf(el, i), key = 's' + slug(name);
      if (seen[key]) key += '-' + (++seen[key]); else seen[key] = 1;
      return { key: key, name: name, el: el, items: elementsOf(el, key) };
    }).filter(function (s) { return s.name !== 'Page bar (count, buttons)' || s.items.length; });
    return sections.length > 0;
  }
  function apply() {
    var css = [];
    sections.forEach(function (s) {
      s.el.classList.toggle('dsp-hide', !!state.hidden[s.key]);
      s.items.forEach(function (it) {
        var h = !!state.hidden[it.key];
        if (it.el) it.el.classList.toggle('dsp-hide', h);
        if (it.el2) it.el2.classList.toggle('dsp-hide', h);
        if (it.col && h) css.push('table[data-dsp-t="' + it.col[0] + '"] tr > :nth-child(' + it.col[1] + '){display:none!important}');
      });
    });
    if (!styleEl) { styleEl = document.createElement('style'); document.head.appendChild(styleEl); }
    styleEl.textContent = css.join('\n');
    var n = Object.keys(state.hidden).filter(function (k) { return state.hidden[k]; }).length;
    var badge = document.getElementById('dspCount'); if (badge) { badge.textContent = n ? n : ''; badge.hidden = !n; }
  }
  function panel() {
    var p = document.getElementById('dspPanel');
    p.innerHTML = '';
    var head = document.createElement('div'); head.className = 'dsp-head';
    head.innerHTML = '<b>Display options</b><button type="button" class="dsp-reset">Show all</button>';
    p.appendChild(head);
    var body = document.createElement('div'); body.className = 'dsp-body'; p.appendChild(body);
    sections.forEach(function (s) {
      var g = document.createElement('div'); g.className = 'dsp-group';
      var on = !state.hidden[s.key];
      g.innerHTML = '<label class="dsp-sec"><input type="checkbox" data-k="' + s.key + '"' + (on ? ' checked' : '') + '><span>' + s.name.replace(/</g, '&lt;') + '</span>' + (s.items.length ? '<button type="button" class="dsp-more" aria-label="Show elements">' + s.items.length + ' ▾</button>' : '') + '</label>';
      if (s.items.length) {
        var ul = document.createElement('div'); ul.className = 'dsp-items'; ul.hidden = true;
        s.items.forEach(function (it) {
          var l = document.createElement('label'); l.className = 'dsp-item';
          l.innerHTML = '<input type="checkbox" data-k="' + it.key + '"' + (!state.hidden[it.key] ? ' checked' : '') + (on ? '' : ' disabled') + '><span></span>';
          l.querySelector('span').textContent = it.label;
          ul.appendChild(l);
        });
        g.appendChild(ul);
      }
      body.appendChild(g);
    });
  }
  function start() {
    var btn = document.getElementById('dspBtn');
    if (!btn) return;
    if (!build()) { btn.hidden = true; return; }
    apply();
    var p = document.getElementById('dspPanel');
    btn.addEventListener('click', function (e) { e.stopPropagation(); if (p.hidden) { build(); panel(); } p.hidden = !p.hidden; btn.setAttribute('aria-expanded', p.hidden ? 'false' : 'true'); });
    document.addEventListener('click', function (e) { if (!p.hidden && !p.contains(e.target) && e.target !== btn) { p.hidden = true; btn.setAttribute('aria-expanded', 'false'); } });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !p.hidden) p.hidden = true; });
    p.addEventListener('click', function (e) {
      if (e.target.closest('.dsp-reset')) { state.hidden = {}; save(); apply(); panel(); return; }
      var more = e.target.closest('.dsp-more');
      if (more) { e.preventDefault(); var it = more.closest('.dsp-group').querySelector('.dsp-items'); it.hidden = !it.hidden; more.classList.toggle('open', !it.hidden); }
    });
    p.addEventListener('change', function (e) {
      var c = e.target; if (!c.dataset.k) return;
      if (c.checked) delete state.hidden[c.dataset.k]; else state.hidden[c.dataset.k] = true;
      if (c.closest('.dsp-sec')) c.closest('.dsp-group').querySelectorAll('.dsp-item input').forEach(function (x) { x.disabled = !c.checked; });
      save(); apply();
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
