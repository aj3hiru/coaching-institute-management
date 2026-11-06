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
