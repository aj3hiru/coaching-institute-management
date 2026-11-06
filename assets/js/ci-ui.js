/* Institute pages: floating labels on forms with class "ci-float" (label on the field's top border once it has a value or focus). */
(function () {
  function fields(root) { return (root || document).querySelectorAll('.ci-float .ci-f'); }
  function ctl(f) { return f.querySelector(':scope > input:not([type=hidden]):not([type=checkbox]):not([type=radio]), :scope > select, :scope > textarea, :scope > .pwd-field > input'); }
  function sync(f) {
    var c = ctl(f);
    if (!c) { f.classList.add('is-up'); return; }
    var up = c.type === 'date' || c.type === 'file' || (c.tagName === 'SELECT' ? (c.options[c.selectedIndex] && c.options[c.selectedIndex].text.trim() !== '') : c.value !== '');
    f.classList.toggle('is-up', !!up);
    f.classList.toggle('ci-ta', c.tagName === 'TEXTAREA');
  }
  function all(root) { fields(root).forEach(sync); }
  document.addEventListener('focusin', function (e) { var f = e.target.closest && e.target.closest('.ci-float .ci-f'); if (f) f.classList.add('is-focus'); });
  document.addEventListener('focusout', function (e) { var f = e.target.closest && e.target.closest('.ci-float .ci-f'); if (f) { f.classList.remove('is-focus'); sync(f); } });
  ['input', 'change'].forEach(function (ev) { document.addEventListener(ev, function (e) { var f = e.target.closest && e.target.closest('.ci-float .ci-f'); if (f) sync(f); else if (e.target.closest && e.target.closest('.ci-float')) all(); }, true); });
  function start() {
    all();
    new MutationObserver(function () { all(); }).observe(document.body, { childList: true, subtree: true });
    setInterval(all, 800); // values set by scripts (fee fill-in, dropdowns) without events
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
/* Payment rows (data-pays): "+ Add" splits the payment over another mode, the bin removes a row, the total shows for 2+ rows. */
(function () {
  function refresh(box) {
    var rows = box.querySelectorAll('.ci-pay-row'), t = 0;
    box.querySelectorAll('.ci-pay-a').forEach(function (i) { t += parseFloat(i.value) || 0; });
    rows.forEach(function (r) { var d = r.querySelector('.ci-pay-del'); if (d) d.disabled = rows.length < 2; });
    var tot = box.querySelector('.ci-pays-total');
    if (tot) tot.textContent = rows.length > 1 ? 'Total ₹' + (Math.round(t * 100) / 100).toLocaleString('en-IN') : '';
  }
  function fire(box) { refresh(box); box.dispatchEvent(new Event('input', { bubbles: true })); box.dispatchEvent(new CustomEvent('ci-pays', { bubbles: true })); }
  document.addEventListener('click', function (e) {
    var add = e.target.closest && e.target.closest('.ci-pays-add'), del = e.target.closest && e.target.closest('.ci-pay-del');
    if (add) {
      var box = add.closest('[data-pays]'), rows = box.querySelectorAll('.ci-pay-row'), last = rows[rows.length - 1], n = last.cloneNode(true);
      var used = [].map.call(rows, function (r) { return r.querySelector('select').value; }), sel = n.querySelector('select');
      var free = [].filter.call(sel.options, function (o) { return used.indexOf(o.value) < 0; })[0];
      sel.value = free ? free.value : sel.options[0].value;
      n.querySelector('.ci-pay-a').value = '';
      last.after(n);
      fire(box);
      n.querySelector('.ci-pay-a').focus();
    } else if (del) {
      var b = del.closest('[data-pays]');
      if (b.querySelectorAll('.ci-pay-row').length > 1) del.closest('.ci-pay-row').remove(); else del.closest('.ci-pay-row').querySelector('.ci-pay-a').value = '';
      fire(b);
    }
  });
  document.addEventListener('input', function (e) { var b = e.target.closest && e.target.closest('[data-pays]'); if (b && e.target.classList.contains('ci-pay-a')) refresh(b); });
  function start() { document.querySelectorAll('[data-pays]').forEach(refresh); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
