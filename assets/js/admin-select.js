/* Themed dropdown for every <select> in the admin panel.
 * The real <select> stays in the page exactly as it was (same size, same form value, same "required" check, same
 * change handlers) — it only stops receiving the mouse (CSS pointer-events:none), so the browser's own list never
 * opens. A click (or Space / Enter / arrow keys) on it opens this panel instead; choosing sets select.value and fires
 * the normal input + change events. Options are read fresh every time it opens, so lists that pages change with
 * JavaScript (hidden / disabled / rebuilt options) always show correctly. A search box appears for long lists.
 * Skipped: <select multiple>, size > 1, data-native, and anything inside the top admin bar.
 */
(function () {
  'use strict';
  if (window.__dkSelect) return;
  window.__dkSelect = true;

  var list = [];               // enhanced selects
  var pop = null, cur = null, items = [], act = -1, q = null, typed = '', typedAt = 0;

  function eligible(s) {
    return !s.multiple && !(s.size > 1) && !s.hasAttribute('data-native') && !s.closest('#wpadminbar, .tox, .mce-container');
  }
  function enhance(s) {
    if (s.__dk || !eligible(s)) return;
    s.__dk = true;
    s.classList.add('dk-sel');
    s.addEventListener('keydown', onSelectKey);
    list.push(s);
  }
  function scan(root) {
    if (!root || root.nodeType !== 1) return;
    if (root.tagName === 'SELECT') enhance(root);
    else root.querySelectorAll && root.querySelectorAll('select').forEach(enhance);
  }

  // which enhanced select is under the point (and not covered by something else, e.g. a modal)
  function hit(x, y) {
    var el = document.elementFromPoint(x, y);
    if (!el || (pop && pop.contains(el))) return null;
    for (var i = list.length - 1; i >= 0; i--) {
      var s = list[i];
      if (!s.isConnected) { list.splice(i, 1); continue; }
      if (s.matches(':disabled')) continue;
      var r = s.getBoundingClientRect();
      if (!r.width || !r.height) continue;
      if (x >= r.left && x <= r.right && y >= r.top && y <= r.bottom && el.contains(s)) return s;
    }
    return null;
  }

  function text(o) { return (o.label || o.textContent || '').trim(); }

  function build() {
    var box = pop.querySelector('.dk-list'), f = q ? q.value.trim().toLowerCase() : '';
    box.innerHTML = '';
    items = [];
    var addOpt = function (o, groupDisabled) {
      if (o.hidden || o.style.display === 'none') return;
      var t = text(o);
      if (f && t.toLowerCase().indexOf(f) === -1) return;
      var d = document.createElement('div');
      d.className = 'dk-opt' + (o.selected ? ' is-sel' : '') + (o.disabled || groupDisabled ? ' is-dis' : '') + (o.value === '' ? ' is-ph' : '');
      d.setAttribute('role', 'option');
      d.setAttribute('aria-selected', o.selected ? 'true' : 'false');
      d.textContent = t || ' ';
      d.title = t;
      d.dataset.i = o.index;
      box.appendChild(d);
      items.push({ el: d, opt: o, dis: o.disabled || groupDisabled });
    };
    [].forEach.call(cur.children, function (c) {
      if (c.tagName === 'OPTGROUP') {
        if (c.hidden) return;
        var before = items.length, g = document.createElement('div');
        g.className = 'dk-grp'; g.textContent = c.label; box.appendChild(g);
        [].forEach.call(c.children, function (o) { if (o.tagName === 'OPTION') addOpt(o, c.disabled); });
        if (items.length === before) g.remove();
      } else if (c.tagName === 'OPTION') addOpt(c, false);
    });
    if (!items.length) { var e = document.createElement('div'); e.className = 'dk-empty'; e.textContent = f ? 'No match' : 'No options'; box.appendChild(e); }
    act = -1;
    for (var i = 0; i < items.length; i++) if (items[i].opt.selected && !items[i].dis) { act = i; break; }
    if (act < 0 && f) act = next(-1, 1);
    paint(true);
  }

  function next(from, dir) {
    for (var i = from + dir; i >= 0 && i < items.length; i += dir) if (!items[i].dis) return i;
    return from;
  }
  function paint(scroll) {
    items.forEach(function (it, i) { it.el.classList.toggle('is-act', i === act); });
    if (scroll && items[act]) {
      var box = pop.querySelector('.dk-list'), el = items[act].el;
      if (el.offsetTop < box.scrollTop) box.scrollTop = el.offsetTop - 4;
      else if (el.offsetTop + el.offsetHeight > box.scrollTop + box.clientHeight) box.scrollTop = el.offsetTop + el.offsetHeight - box.clientHeight + 4;
    }
  }

  function place() {
    if (!pop || !cur) return;
    var r = cur.getBoundingClientRect(), vw = document.documentElement.clientWidth, vh = window.innerHeight;
    if (r.bottom < 0 || r.top > vh || !r.width) { close(false); return; }
    var w = Math.min(Math.max(r.width, 160), vw - 16);
    pop.style.width = w + 'px';
    pop.style.left = Math.max(8, Math.min(r.left, vw - w - 8)) + 'px';
    var below = vh - r.bottom - 8, above = r.top - 8, h = Math.min(pop.scrollHeight, 320);
    if (below >= Math.min(h, 200) || below >= above) { pop.style.top = (r.bottom + 2) + 'px'; pop.style.bottom = ''; pop.style.maxHeight = Math.max(120, Math.min(320, below)) + 'px'; }
    else { pop.style.top = ''; pop.style.bottom = (vh - r.top + 2) + 'px'; pop.style.maxHeight = Math.max(120, Math.min(320, above)) + 'px'; }
  }

  function open(s) {
    close(false);
    cur = s;
    s.classList.add('dk-open');
    try { s.focus({ preventScroll: true }); } catch (e) { s.focus(); }
    pop = document.createElement('div');
    pop.className = 'dk-pop';
    pop.setAttribute('role', 'listbox');
    var count = 0;
    [].forEach.call(s.options, function (o) { if (!o.hidden) count++; });
    if (count > 8) {
      q = document.createElement('input');
      q.type = 'text'; q.className = 'dk-q'; q.placeholder = 'Search…'; q.autocomplete = 'off'; q.setAttribute('aria-label', 'Search options');
      q.addEventListener('input', build);
      q.addEventListener('keydown', onPopKey);
      pop.appendChild(q);
    } else q = null;
    var box = document.createElement('div'); box.className = 'dk-list'; pop.appendChild(box);
    pop.addEventListener('mousedown', function (e) { if (e.target !== q) e.preventDefault(); });
    pop.addEventListener('click', function (e) {
      var d = e.target.closest('.dk-opt'); if (!d) return;
      var it = items.filter(function (x) { return x.el === d; })[0];
      if (it && !it.dis) choose(it.opt);
    });
    pop.addEventListener('mousemove', function (e) {
      var d = e.target.closest('.dk-opt'); if (!d) return;
      for (var i = 0; i < items.length; i++) if (items[i].el === d && !items[i].dis && act !== i) { act = i; paint(false); }
    });
    document.body.appendChild(pop);
    build();
    place();
    paint(true);
    if (q && !window.matchMedia('(pointer: coarse)').matches) q.focus();
    window.addEventListener('scroll', place, true);
    window.addEventListener('resize', onResize);
  }

  function close(refocus) {
    if (!pop) return;
    pop.remove();
    pop = null; q = null; items = []; act = -1;
    window.removeEventListener('scroll', place, true);
    window.removeEventListener('resize', onResize);
    if (cur) {
      cur.classList.remove('dk-open');
      if (refocus) { try { cur.focus({ preventScroll: true }); } catch (e) { cur.focus(); } }
    }
    cur = null;
  }
  function onResize() { close(false); }

  function choose(o) {
    var s = cur;
    close(true);
    if (!s || o.disabled) return;
    if (s.value !== o.value || s.selectedIndex !== o.index) {
      s.selectedIndex = o.index;
      s.dispatchEvent(new Event('input', { bubbles: true }));
      s.dispatchEvent(new Event('change', { bubbles: true }));
    }
  }

  function onPopKey(e) {
    if (!pop) return;
    var k = e.key;
    if (k === 'ArrowDown') { e.preventDefault(); act = next(act, 1); paint(true); }
    else if (k === 'ArrowUp') { e.preventDefault(); act = next(act, -1); paint(true); }
    else if (k === 'Home' && !q) { e.preventDefault(); act = next(-1, 1); paint(true); }
    else if (k === 'End' && !q) { e.preventDefault(); act = next(items.length, -1); paint(true); }
    else if (k === 'PageDown') { e.preventDefault(); for (var i = 0; i < 8; i++) act = next(act, 1); paint(true); }
    else if (k === 'PageUp') { e.preventDefault(); for (var j = 0; j < 8; j++) act = next(act, -1); paint(true); }
    else if (k === 'Enter') { e.preventDefault(); if (items[act] && !items[act].dis) choose(items[act].opt); }
    else if (k === 'Escape') { e.preventDefault(); e.stopPropagation(); close(true); }
    else if (k === 'Tab') { close(false); }
    else if (!q && k.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) {
      // type to jump (lists without a search box)
      var now = Date.now(); typed = (now - typedAt > 700 ? '' : typed) + k.toLowerCase(); typedAt = now;
      for (var n = 0; n < items.length; n++) if (!items[n].dis && text(items[n].opt).toLowerCase().indexOf(typed) === 0) { act = n; paint(true); break; }
      e.preventDefault();
    }
  }

  function onSelectKey(e) {
    var s = e.currentTarget;
    if (pop && cur === s) { onPopKey(e); return; }
    var k = e.key;
    if (k === ' ' || k === 'Enter' || k === 'ArrowDown' || k === 'ArrowUp' || k === 'F4' || (e.altKey && k === 'ArrowDown')) {
      if (k === 'Enter' && s.form && !e.altKey) { /* Enter on a closed list opens it instead of sending the form */ }
      e.preventDefault();
      open(s);
    }
  }

  // mouse / touch: the select itself gets no pointer events, so find it from the point that was clicked
  document.addEventListener('mousedown', function (e) {
    if (e.button !== 0) return;
    var s = hit(e.clientX, e.clientY);
    if (s) e.preventDefault();             // no text selection / focus jump; the click below opens the panel
    else if (pop && !pop.contains(e.target)) close(false);
  }, true);
  document.addEventListener('click', function (e) {
    if (e.detail === 0 && !e.clientX && !e.clientY) return; // keyboard "click"
    var s = hit(e.clientX, e.clientY);
    if (!s) { if (pop && !pop.contains(e.target)) close(false); return; }
    e.preventDefault();
    e.stopPropagation();
    if (pop && cur === s) close(true); else open(s);
  }, true);
  var raf = 0;
  document.addEventListener('mousemove', function (e) {
    if (raf) return;
    raf = requestAnimationFrame(function () {
      raf = 0;
      document.documentElement.classList.toggle('dk-sel-hover', !!hit(e.clientX, e.clientY));
    });
  }, { passive: true });
  document.addEventListener('keydown', function (e) { if (pop && q && document.activeElement !== q && e.target !== cur) onPopKey(e); });

  function start() {
    scan(document.body);
    new MutationObserver(function (muts) {
      muts.forEach(function (m) { m.addedNodes.forEach(scan); });
      if (cur && !cur.isConnected) close(false);
    }).observe(document.body, { childList: true, subtree: true });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
