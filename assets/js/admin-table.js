/* Admin "sheet": every .ci-table gets a toolbar on top (Show [N] entries) and a footer (Showing a to b of n entries + pages),
   with sortable column headers. Tables paged by the server (data-sheet-total / -page / -per / -url) get the same look
   with links instead. Opt out with data-sheet="off" (or class ci-plan). */
(function () {
  'use strict';
  var SIZES = [10, 20, 50, 100, 0];
  var store = { get: function (k) { try { return localStorage.getItem(k); } catch (e) { return null; } }, set: function (k, v) { try { localStorage.setItem(k, v); } catch (e) {} } };
  function el(tag, cls, html) { var e = document.createElement(tag); if (cls) e.className = cls; if (html != null) e.innerHTML = html; return e; }
  function sizeSelect(cur, onChange) {
    var s = el('select', 'ci-sheet-size'); s.setAttribute('aria-label', 'Entries per page');
    SIZES.forEach(function (n) { var o = el('option', null, n ? String(n) : 'All'); o.value = n; if (n === cur) o.selected = true; s.appendChild(o); });
    s.addEventListener('change', function () { onChange(+s.value); });
    return s;
  }
  function pager(page, pages, href, onPage) {
    var nav = el('nav', 'ci-sheet-pg'); nav.setAttribute('aria-label', 'Pages');
    var add = function (label, p, dis, on) {
      var a;
      if (dis || on) { a = el('span', on ? 'on' : 'dis', label); }
      else if (href) { a = el('a', null, label); a.href = href(p); }
      else { a = el('button', null, label); a.type = 'button'; a.addEventListener('click', function () { onPage(p); }); }
      if (on) a.setAttribute('aria-current', 'page');
      nav.appendChild(a);
    };
    add('‹ Previous', page - 1, page <= 1);
    var from = Math.max(1, page - 2), to = Math.min(pages, page + 2);
    if (from > 1) { add('1', 1); if (from > 2) nav.appendChild(el('span', 'gap', '…')); }
    for (var i = from; i <= to; i++) add(String(i), i, false, i === page);
    if (to < pages) { if (to < pages - 1) nav.appendChild(el('span', 'gap', '…')); add(String(pages), pages); }
    add('Next ›', page + 1, page >= pages);
    return nav;
  }
  var DATE = /^(\d{1,2})\s([A-Z][a-z]{2})\s(\d{4})/, MON = { Jan: 0, Feb: 1, Mar: 2, Apr: 3, May: 4, Jun: 5, Jul: 6, Aug: 7, Sep: 8, Oct: 9, Nov: 10, Dec: 11 };
  function key(td) {
    if (!td) return '';
    var t = (td.getAttribute('data-sort') || td.innerText || '').trim();
    var d = t.match(DATE); if (d && MON[d[2]] != null) return new Date(+d[3], MON[d[2]], +d[1]).getTime();
    var n = t.replace(/[₹,\s]/g, '').match(/^-?\d+(\.\d+)?/); if (n && /^[₹\d\s,.-]/.test(t)) return parseFloat(n[0]);
    return t.toLowerCase();
  }
  // S.No. column for tables marked data-sno: added here (so sorting / paging keep it right) and renumbered on every draw;
  // a page from the server starts at its own offset (page 2 of 20 → 21)
  function snoAdd(table) {
    if (!table.hasAttribute('data-sno') || table.dataset.snoDone) return;
    table.dataset.snoDone = '1';
    var hr = table.tHead && table.tHead.rows[0]; if (!hr) return;
    var th = el('th', 'ci-sno', 'S.No.'); th.dataset.nosort = ''; th.setAttribute('scope', 'col'); hr.insertBefore(th, hr.cells[0]);
    [].forEach.call(table.tBodies, function (tb) { [].forEach.call(tb.rows, function (r) {
      if ((r.cells.length === 1 && r.cells[0].colSpan > 1) || r.classList.contains('ci-total-row')) { r.cells[0].colSpan += 1; return; } // "nothing found" / total row: no number
      r.insertBefore(el('td', 'ci-sno'), r.cells[0]);
    }); });
    snoNum(table);
  }
  function snoNum(table) {
    if (!table.dataset.snoDone) return;
    var off = table.dataset.sheetTotal != null ? ((+table.dataset.sheetPage || 1) - 1) * (+table.dataset.sheetPer || 1000) : 0, n = off;
    [].forEach.call(table.tBodies, function (tb) { [].forEach.call(tb.rows, function (r) { var c = r.cells[0]; if (c && c.classList.contains('ci-sno')) c.textContent = ++n; }); });
  }
  function setup(table, idx) {
    snoAdd(table);
    if (table.dataset.sheet === 'off' || table.classList.contains('ci-plan') || table.closest('.ci-sheet-done, form .ci-modal, .ci-modal-b')) return;
    var wrap = table.closest('.ci-table-wrap') || table.parentNode, card = wrap.closest('.ci-card') || wrap;
    if (card.classList.contains('ci-sheet-done')) return;
    card.classList.add('ci-sheet', 'ci-sheet-done');
    var tb = table.tBodies[0]; if (!tb) return;
    var rows = [].slice.call(tb.rows).filter(function (r) { return !r.querySelector('td.ci-empty'); });
    var complex = rows.some(function (r) { return r.cells.length === 1 && r.cells[0].colSpan > 1; });
    var server = table.dataset.sheetTotal != null;
    var pkey = 'sheet:' + location.pathname + ':' + idx;
    var per = server ? +table.dataset.sheetPer : (store.get(pkey) != null ? +store.get(pkey) : 20);
    if (SIZES.indexOf(per) < 0) per = 20;
    var page = server ? +table.dataset.sheetPage || 1 : 1;
    var top = el('div', 'ci-sheet-top'), foot = el('div', 'ci-sheet-foot');
    var lab = el('label', 'ci-sheet-show'); lab.appendChild(document.createTextNode('Show '));
    lab.appendChild(sizeSelect(per, function (n) {
      if (server) { var u = new URL(location.href); u.searchParams.set('per', n || 1000); u.searchParams.delete('page'); location.href = u.toString(); return; }
      per = n; store.set(pkey, n); page = 1; draw();
    }));
    lab.appendChild(document.createTextNode(' entries'));
    top.appendChild(lab);
    var info = el('span', 'ci-sheet-info'); foot.appendChild(info);
    card.insertBefore(top, card.firstChild);
    card.appendChild(foot);
    var urlFor = function (p) { var u = new URL(location.href); u.searchParams.set('page', p); return u.toString(); };
    function draw() {
      snoNum(table);
      var old = foot.querySelector('.ci-sheet-pg'); if (old) old.remove();
      if (server) {
        var total = +table.dataset.sheetTotal, pp = +table.dataset.sheetPer || 1000, pages = Math.max(1, Math.ceil(total / pp)), a = total ? (page - 1) * pp + 1 : 0;
        info.textContent = total ? 'Showing ' + a + ' to ' + Math.min(total, a + rows.length - 1) + ' of ' + total.toLocaleString('en-IN') + ' entries' : 'Showing 0 entries';
        if (pages > 1) foot.appendChild(pager(page, pages, urlFor));
        return;
      }
      var n = rows.length, size = complex || !per ? n : per, pages = Math.max(1, Math.ceil(n / (size || 1)));
      if (page > pages) page = pages;
      var a = (page - 1) * size;
      rows.forEach(function (r, i) { r.hidden = !complex && per && (i < a || i >= a + size); });
      info.textContent = n ? 'Showing ' + (a + 1) + ' to ' + Math.min(n, a + size) + ' of ' + n.toLocaleString('en-IN') + ' entries' : 'Showing 0 entries';
      if (!complex && pages > 1) foot.appendChild(pager(page, pages, null, function (p) { page = p; draw(); var y = card.getBoundingClientRect().top + window.scrollY - 90; if (window.scrollY > y) window.scrollTo({ top: y, behavior: 'smooth' }); }));
    }
    // sortable headers (not the Action column, not empty headers, not on server-paged / complex tables)
    var head = table.tHead && table.tHead.rows[0];
    if (head && !server && !complex && rows.length > 1) {
      [].forEach.call(head.cells, function (th, ci) {
        var t = th.textContent.trim();
        if (!t || /^actions?$/i.test(t) || th.dataset.nosort != null || th.querySelector('input')) return;
        th.classList.add('ci-sort'); th.tabIndex = 0; th.setAttribute('aria-sort', 'none');
        var go = function () {
          var dir = th.getAttribute('aria-sort') === 'ascending' ? -1 : 1;
          [].forEach.call(head.cells, function (h) { if (h.classList.contains('ci-sort')) h.setAttribute('aria-sort', 'none'); });
          th.setAttribute('aria-sort', dir === 1 ? 'ascending' : 'descending');
          rows.sort(function (x, y) { var p = key(x.cells[ci]), q = key(y.cells[ci]); return (p > q ? 1 : p < q ? -1 : 0) * dir; });
          rows.forEach(function (r) { tb.appendChild(r); });
          page = 1; draw();
        };
        th.addEventListener('click', go);
        th.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); go(); } });
      });
    }
    draw();
  }
  function run() { [].forEach.call(document.querySelectorAll('table.ci-table'), setup); [].forEach.call(document.querySelectorAll('table[data-sno]'), snoAdd); }
  window.ciSnoRenumber = function (t) { snoNum(t); }; // for pages that filter rows themselves
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run); else run();
})();
