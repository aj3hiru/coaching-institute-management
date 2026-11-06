/* Typed dates instead of the browser's date picker (admin, every page, phones too).
   Each <input type="date"> becomes a plain text box where the date is typed as dd/mm/yyyy (slashes are added
   while typing). The original input stays in the form, hidden, and still holds yyyy-mm-dd — so the server and
   any script reading or setting .value work exactly as before. A wrong date is marked red and stops the form. */
(function () {
  'use strict';
  var NATIVE = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');
  var bound = new WeakSet();

  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function toDMY(iso) { var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso || ''); return m ? m[3] + '/' + m[2] + '/' + m[1] : ''; }
  // "7/3/2026", "07-03-2026", "07032026", "7.3.26" → "2026-03-07"; '' when empty; null when not a real date
  function parse(t) {
    t = (t || '').trim();
    if (!t) return '';
    var m = /^(\d{1,2})\D+(\d{1,2})\D+(\d{2}|\d{4})$/.exec(t) || /^(\d{2})(\d{2})(\d{4})$/.exec(t);
    if (!m) return null;
    var d = +m[1], mo = +m[2], y = +m[3];
    if (m[3].length === 2) y += 2000;
    var dt = new Date(y, mo - 1, d);
    if (dt.getFullYear() !== y || dt.getMonth() !== mo - 1 || dt.getDate() !== d || y < 1900 || y > 2100) return null;
    return y + '-' + pad(mo) + '-' + pad(d);
  }
  // keeps what was typed in the dd/mm/yyyy shape, adding the slashes
  function shape(raw) {
    var g = ['', '', ''], i = 0, max = [2, 2, 4];
    for (var k = 0; k < raw.length; k++) {
      var c = raw.charAt(k);
      if (c >= '0' && c <= '9') {
        if (g[i].length >= max[i]) { if (i < 2) i++; else continue; }
        g[i] += c;
      } else if (g[i].length && i < 2) { // a separator typed after "7" means "07"
        if (g[i].length === 1) g[i] = '0' + g[i];
        i++;
      }
    }
    return g[0] + (g[0].length === 2 && (i > 0 || g[1]) ? '/' : (g[0].length === 2 ? '/' : '')) + g[1] + (g[1].length === 2 ? '/' : '') + g[2];
  }

  function bind(orig) {
    if (bound.has(orig)) return;
    bound.add(orig);
    // a copied row (e.g. "+ Add course") brings the old text box along: drop it, a fresh one is made
    var prev = orig.previousElementSibling;
    if (prev && prev.classList.contains('dk-date')) prev.remove();
    var box = document.createElement('input');
    box.type = 'text';
    box.className = ((orig.getAttribute('class') || '') + ' dk-date').trim();
    if (orig.getAttribute('style')) box.setAttribute('style', orig.getAttribute('style'));
    box.placeholder = 'dd/mm/yyyy';
    box.setAttribute('inputmode', 'numeric');
    box.setAttribute('autocomplete', 'off');
    box.maxLength = 10;
    box.setAttribute('aria-label', orig.getAttribute('aria-label') || (orig.closest('.ci-f, .al-f, .jc-f, label') && (orig.closest('.ci-f, .al-f, .jc-f, label').querySelector('label') || {}).textContent) || 'Date (dd/mm/yyyy)');
    if (orig.required) { box.required = true; orig.required = false; }
    box.disabled = orig.disabled;
    box.readOnly = orig.readOnly;
    orig.setAttribute('data-dk-date', '1');
    var min = orig.getAttribute('min') || '', max = orig.getAttribute('max') || '';
    orig.type = 'hidden';
    NATIVE.set.call(box, toDMY(NATIVE.get.call(orig)));
    orig.parentNode.insertBefore(box, orig);

    function bad(on, why) { box.classList.toggle('dk-date-bad', on); box.title = ''; box.setAttribute('aria-invalid', on ? 'true' : 'false'); box._why = on ? why : ''; }
    function store(iso) {
      var old = NATIVE.get.call(orig);
      NATIVE.set.call(orig, iso);
      if (old !== iso) { orig.dispatchEvent(new Event('input', { bubbles: true })); orig.dispatchEvent(new Event('change', { bubbles: true })); }
    }
    function check(final) {
      var iso = parse(box.value);
      if (iso === null) { if (final) bad(true, 'Please type a real date as dd/mm/yyyy, e.g. ' + toDMY(new Date().toISOString().slice(0, 10)) + '.'); store(''); return; }
      if (iso && min && iso < min) { bad(true, 'The date cannot be before ' + toDMY(min) + '.'); store(''); return; }
      if (iso && max && iso > max) { bad(true, 'The date cannot be after ' + toDMY(max) + '.'); store(''); return; }
      bad(false);
      if (final && iso) NATIVE.set.call(box, toDMY(iso));
      store(iso);
    }
    box.addEventListener('input', function (e) {
      var atEnd = box.selectionStart === box.value.length;
      if (atEnd && e.inputType && e.inputType.indexOf('insert') === 0) NATIVE.set.call(box, shape(box.value));
      if (box.classList.contains('dk-date-bad')) bad(false);
      var iso = parse(box.value);
      if (iso && box.value.length >= 8) check(false); else if (!box.value) store('');
    });
    box.addEventListener('blur', function () { check(true); });
    // tapping a filled box selects the date, so a new one can simply be typed over it
    box.addEventListener('focus', function () { if (box.value) setTimeout(function () { if (document.activeElement === box) box.select(); }, 0); });
    box.addEventListener('keydown', function (e) { if (e.key === 'Enter') check(true); });

    // scripts that set or read the date keep using the original input
    Object.defineProperty(orig, 'value', {
      configurable: true,
      get: function () { return NATIVE.get.call(orig); },
      set: function (v) { v = v == null ? '' : String(v); NATIVE.set.call(orig, v); NATIVE.set.call(box, toDMY(v)); bad(false); }
    });
    // keep disabled / read-only in step when a script changes them later
    new MutationObserver(function () { box.disabled = orig.disabled; box.readOnly = orig.readOnly; }).observe(orig, { attributes: true, attributeFilter: ['disabled', 'readonly'] });
  }

  function scan(root) {
    if (!root.querySelectorAll) return;
    if (root.matches && (root.matches('input[type=date]') || root.matches('input[data-dk-date]'))) bind(root);
    root.querySelectorAll('input[type=date], input[data-dk-date]').forEach(bind);
  }

  // a wrong date stops the form, with the reason
  document.addEventListener('submit', function (e) {
    var f = e.target, wrong = null;
    f.querySelectorAll('.dk-date').forEach(function (b) { if (!wrong) { b.dispatchEvent(new Event('blur')); if (b.classList.contains('dk-date-bad')) wrong = b; } });
    if (!wrong) return;
    e.preventDefault(); e.stopImmediatePropagation();
    wrong.focus();
    var msg = wrong._why || 'Please check the date.';
    if (window.adminNotice) window.adminNotice(msg, { type: 'error', title: 'Check the date' }); else alert(msg);
  }, true);

  function start() {
    scan(document);
    new MutationObserver(function (list) {
      list.forEach(function (m) { m.addedNodes.forEach(function (n) { if (n.nodeType === 1) scan(n); }); });
    }).observe(document.body, { childList: true, subtree: true });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
