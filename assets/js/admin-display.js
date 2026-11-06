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
  var HEAD = '[data-ds-title], .meta-panel-header, .panel-header, .card-head, .box-head, .ci-card-h h3, .card-header h3, .card-header h2, .card-title, .section-title, .panel-title, .box-title, h2, h3, h4, legend, .form-section-title, .settings-title';
  // a heading's own words: without its links, buttons, badges and counters
  function headText(h) {
    if (!h) return '';
    var c = h.cloneNode(true);
    c.querySelectorAll('a, button, .badge, .count, small, [class*=count], [class*=badge], .pmx-n, .char-count, select, input').forEach(function (x) { x.remove(); });
    return txt(c) || txt(h);
  }
  function short(t) { t = (t || '').replace(/\s+/g, ' ').trim().replace(/\s*\d+\s*(\/\s*\d+)?\s*(added|chars?|words?)?$/i, '').replace(/[:\s]+$/, ''); return t.length > 38 ? t.slice(0, 36).replace(/\s+\S*$/, '') + '…' : t; }
  // a name for a block without a heading: what it holds, or its first words
  function vis(el) { return (el.innerText || el.textContent || '').replace(/\s*\n+\s*/g, ' · ').replace(/\s+/g, ' ').trim(); }
  function guess(el) {
    var t = vis(el), links = el.querySelectorAll('a, button');
    if (el.querySelector('.pagination, .pager, .pn-pager, nav[aria-label*=agin]') && t.length < 80) return 'Pagination';
    if (/(next|prev|previous)\s*[»›]?$|^[«‹]|[»›]$/i.test(t) && t.length < 60) return 'Pagination';
    if (/^\d+\s+selected\b/i.test(t) || el.querySelector('.bulk-actions, [class*=bulk]')) return 'Bulk actions';
    if (t.length < 160 && (el.matches('nav, .tabs, .subsubsub, [class*=tabs], [class*=filter-links], [role=tablist]') || el.querySelector(':scope > nav, :scope > .tabs, :scope > [role=tablist]'))) return 'Tabs';
    if (links.length >= 2 && t.length < 120 && /\d/.test(t) && !el.querySelector('input, select, textarea, table') && [].every.call(links, function (a) { return vis(a).length < 30; })) return 'Tabs';
    if (/^[\d,.₹%]+\b/.test(t) && !el.querySelector('table, form')) return 'Summary tiles';
    if (/^\S+( \/ \S+)+/.test(t) && el.querySelector('a') && t.length < 70) return 'Breadcrumb';
    if (el.matches('.alert, .notice, .info-box, .help, .hint, .ci-note') || el.querySelector(':scope > .alert, :scope > .notice')) return 'Notice';
    if (el.querySelector('input[type=search], .search-box, [name=q], [name=search]') && !el.querySelector('table')) return 'Search';
    var btns = el.querySelectorAll('button, .btn, a.button');
    if (btns.length && btns.length <= 6 && txt(el).length < 90 && !el.querySelector('input, select, textarea, table')) return 'Buttons: ' + short([].slice.call(btns, 0, 3).map(txt).filter(Boolean).join(', '));
    if (el.querySelector('canvas, .chart, .chart-container, [id*=chart], [id*=Chart]')) return 'Chart';
    if (el.querySelector('form, input, select, textarea')) { var lb = el.querySelector('label'); if (lb && txt(lb)) return 'Form: ' + short(txt(lb)); }
    var named = [].filter.call(el.querySelectorAll('strong, b, .title, [class*=title], [class*=head], label'), function (x) { var v = txt(x); return v && v.length < 60 && /[a-z]{3}/i.test(v); })[0];
    if (named) return short(txt(named));
    var id = el.id && el.id.replace(/[-_]+/g, ' ').replace(/([a-z])([A-Z])/g, '$1 $2').trim();
    if (id && /[a-z]{3}/i.test(id)) return short(id.charAt(0).toUpperCase() + id.slice(1));
    return t ? short(t.split(' · ')[0]) : '';
  }
  function nameOf(el, i) {
    if (el.dataset.ds) return el.dataset.ds;
    if (el.matches('.ci-stats, .stats-grid, .stat-cards, .dash-stats')) return 'Summary tiles';
    if (el.matches('.ci-tabs, .tabs, .ai-tabs')) return 'Tabs';
    if (el.matches('.ci-head')) return 'Page bar (count, buttons)';
    var h = el.querySelector(HEAD);
    var n = short(headText(h));
    if (!n && el.querySelector('form.ci-filters, .filter-group, .filters, .filter-bar, .filters-bar')) n = 'Filters';
    if (!n && el.querySelector('table')) n = 'Table';
    if (!n && el.getAttribute('aria-label')) n = short(el.getAttribute('aria-label'));
    if (!n) n = guess(el);
    return n || ('Section ' + (i + 1));
  }
  // blocks of the page (descend into wrappers such as <form>, tab panes and grids of cards)
  function collect(parent, out, depth) {
    [].forEach.call(parent.children, function (el) {
      if (!isBlock(el)) return;
      var kids = [].filter.call(el.children, isBlock);
      var wrapper = depth < 4 && (el.matches('form:not(.ci-filters), section.ci-pane, .ci-grid2, .ci-grid3, .dashboard-grid, .grid, .row, .editor-layout, .editor-main, .editor-sidebar-panel') && kids.length > 1 && !el.matches('.ci-card, .card'));
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
    // generic pages: inner cards / boxes with their own heading, settings rows, toolbar buttons
    if (!list.length || list.length < 2) {
      var seen = {};
      [].filter.call(sec.querySelectorAll('.card, .ci-card, .panel, .box, .pn-card, .setting-card, .settings-card, fieldset, .form-section, [class*=-card]'), function (c) { return c !== sec && !c.closest('table') && !c.closest('.modal, [role=dialog]'); }).forEach(function (c, i) {
        if (c.parentElement && c.parentElement.closest('.card, .ci-card, .panel, .box, .pn-card, fieldset, [class*=-card]') !== sec && c.parentElement.closest('.card, .ci-card, .panel, .box, .pn-card, fieldset, [class*=-card]')) return; // only the first level inside
        var l = short(vis(c.querySelector(HEAD + ', strong, b') || document.createElement('i')).split(' · ')[0]);
        if (!l || seen[l]) return; seen[l] = 1;
        list.push({ key: skey + ':p' + slug(l) + i, label: 'Part: ' + l, el: c });
      });
      sec.querySelectorAll('.form-row, .setting-row, .pn-field, .field, .pn-switch, .switch-row, .toggle-row').forEach(function (f, i) {
        if (f.closest('.modal, [role=dialog]')) return;
        var c = f.querySelector('input, select, textarea'); if (!c || c.required || c.type === 'hidden') return;
        var l = short(txt(f.querySelector('label, strong, b, .label, span')) || txt(f));
        if (!l || seen['f' + l]) return; seen['f' + l] = 1;
        list.push({ key: skey + ':g' + slug(l) + i, label: 'Field: ' + l, el: f });
      });
      if (list.length < 2) sec.querySelectorAll('input, select, textarea').forEach(function (c, i) {
        if (list.length > 40 || c.type === 'hidden' || c.type === 'submit' || c.type === 'button' || c.required || c.closest('table, .modal, [role=dialog], .dsp-panel, .dk-pop')) return;
        var lab = (c.id && sec.querySelector('label[for="' + c.id + '"]')) || c.closest('label');
        var box = c.closest('.form-group, .ci-f, .field, .form-row, .setting-row, .pn-field, .gs-row, .row-field') || (lab && lab.parentElement !== sec ? lab.parentElement : null) || c.parentElement;
        if (!box || box === sec) return;
        if (!lab) lab = box.querySelector('label, .label, strong, b');
        var l = short(lab ? vis(lab).split(' · ')[0] : (c.getAttribute('aria-label') || c.placeholder || ''));
        if (!l || seen['f' + l]) return; seen['f' + l] = 1;
        list.push({ key: skey + ':h' + slug(l) + i, label: 'Field: ' + l, el: box });
      });
      sec.querySelectorAll('.toolbar .btn, .actions-bar .btn, .header-actions .btn, .bulk-actions .btn').forEach(function (b) { var l = short(txt(b)); if (l && !seen['b' + l]) { seen['b' + l] = 1; list.push({ key: skey + ':x' + slug(l), label: 'Button: ' + l, el: b }); } });
    }
    if (!list.length) {
      var kids = [].filter.call(sec.children, function (k) { return isBlock(k) && txt(k); });
      if (kids.length >= 2 && kids.length <= 30) kids.forEach(function (k, i) {
        var l = short((vis(k.querySelector(HEAD + ', strong, b, label') || k) || '').split(' · ')[0]);
        if (l && !/^[\d,.]+$/.test(l)) list.push({ key: skey + ':k' + slug(l) + i, label: 'Part: ' + l, el: k });
      });
    }
    sec.querySelectorAll('.ci-kv dt').forEach(function (dt) { var l = txt(dt); list.push({ key: skey + ':k' + slug(l), label: 'Detail: ' + l, el: dt, el2: dt.nextElementSibling }); });
    return list;
  }
  function build() {
    root = container();
    if (!root) return false;
    var blocks = [];
    collect(root, blocks, 0);
    // the page is one big wrapper (e.g. analytics, file manager): use the blocks inside it
    for (var guard = 0; blocks.length === 1 && guard < 6; guard++) {
      var only = blocks[0], inner = [].filter.call(only.children, isBlock);
      if (!inner.length || only.matches('.ci-card, .card, table, form.ci-filters')) break;
      if (inner.length === 1) { blocks = [inner[0]]; continue; }
      blocks = []; collect(only, blocks, 1);
    }
    blocks = blocks.filter(function (el) { if (!el.classList.contains('dsp-hide') && getComputedStyle(el).display === 'none') return false; return txt(el) || el.querySelector('img, canvas, svg, input, select, textarea, iframe'); }); // empty boxes are not offered
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
/* Hover labels for icon buttons: a small label in the theme colour just under the mouse (replaces the browser's own tooltip). */
(function () {
  var tip = null, cur = null;
  // only the icon buttons of table Action columns (not the delete bin, not other buttons)
  var SEL = 'td:last-child .ci-ico:not([data-i="trash"]):not([data-i="edit"])'; // Action column icons only (not delete / edit — those are clear)
  function show(el, x, y) {
    var t = el.getAttribute('data-tip') || el.getAttribute('title') || el.getAttribute('aria-label');
    if (!t) return;
    if (el.hasAttribute('title')) { el.setAttribute('data-tip', el.getAttribute('title')); el.removeAttribute('title'); }
    if (!tip) { tip = document.createElement('div'); tip.className = 'dk-tip'; tip.setAttribute('role', 'tooltip'); document.body.appendChild(tip); }
    tip.textContent = t; tip.hidden = false; cur = el; move(x, y);
  }
  function move(x, y) {
    if (!tip || tip.hidden) return;
    var w = tip.offsetWidth, h = tip.offsetHeight, vw = document.documentElement.clientWidth, vh = window.innerHeight;
    var left = Math.max(6, Math.min(x - w / 2, vw - w - 6)), top = y + 18;
    if (top + h > vh - 6) top = y - h - 12;
    tip.style.left = left + 'px'; tip.style.top = top + 'px';
  }
  function hide() { if (tip) tip.hidden = true; cur = null; }
  document.addEventListener('mouseover', function (e) { var el = e.target.closest && e.target.closest(SEL); if (el && el !== cur) show(el, e.clientX, e.clientY); else if (!el && cur) hide(); }, { passive: true });
  document.addEventListener('mousemove', function (e) { if (cur) { if (!cur.contains(e.target)) hide(); else move(e.clientX, e.clientY); } }, { passive: true });
  ['mousedown', 'scroll', 'keydown'].forEach(function (ev) { document.addEventListener(ev, hide, true); });
})();
