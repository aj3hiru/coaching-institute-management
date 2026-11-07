/* Popup forms for admin list pages (Batches, Courses, Sessions & Classes …).
   <button data-mopen="#id" data-fill='{"name":"…"}' data-mtitle="Edit batch">  opens the .ci-modal #id, resets its form,
   fills fields by name and sets the heading ([data-mtitle-target]). [data-close], Esc or a click outside closes it.
   A modal with data-open-now is shown when the page loads (e.g. ?new=1 / ?edit=N links, or after a form error). */
(function () {
  'use strict';
  var last = null;
  function lock(on) { document.documentElement.style.overflow = on ? 'hidden' : ''; if (window.ciLock) try { window.ciLock(on); } catch (e) {} }
  function fill(form, data) {
    Object.keys(data || {}).forEach(function (k) {
      var els = form.querySelectorAll('[name="' + k + '"]');
      [].forEach.call(els, function (el) {
        var v = data[k] == null ? '' : String(data[k]);
        if (el.type === 'checkbox' || el.type === 'radio') el.checked = el.type === 'checkbox' ? (v === '1' || v === 'true' || v === el.value) : el.value === v;
        else el.value = v;
        el.dispatchEvent(new Event('change', { bubbles: true }));
      });
    });
  }
  function open(m, btn) {
    var form = m.querySelector('form');
    if (form && btn) {
      form.reset();
      [].forEach.call(form.querySelectorAll('input[type=date]'), function (d) { d.value = d.defaultValue; });
      [].forEach.call(form.querySelectorAll('select'), function (s) { s.dispatchEvent(new Event('change', { bubbles: true })); });
      var data = {}; try { data = JSON.parse(btn.getAttribute('data-fill') || '{}'); } catch (e) {}
      fill(form, data);
    }
    var t = m.querySelector('[data-mtitle-target]');
    if (t && btn && btn.getAttribute('data-mtitle')) t.textContent = btn.getAttribute('data-mtitle');
    [].forEach.call(m.querySelectorAll('[data-mshow]'), function (x) { x.hidden = !btn || x.getAttribute('data-mshow') !== (btn.getAttribute('data-mmode') || ''); });
    last = document.activeElement;
    m.hidden = false; lock(true);
    var f = m.querySelector('input:not([type=hidden]):not([disabled]), select, textarea');
    if (f) setTimeout(function () { try { f.focus(); } catch (e) {} }, 30);
  }
  function close(m) { m.hidden = true; if (!document.querySelector('.ci-modal:not([hidden])')) lock(false); if (last && last.focus) try { last.focus(); } catch (e) {} }
  window.ciModal = { open: function (sel, btn) { var m = document.querySelector(sel); if (m) open(m, btn || null); }, close: function (sel) { var m = document.querySelector(sel); if (m) close(m); } };
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-mopen]');
    if (b) { var m = document.querySelector(b.getAttribute('data-mopen')); if (m) { e.preventDefault(); open(m, b); } return; }
    var c = e.target.closest('.ci-modal [data-close]');
    if (c) { e.preventDefault(); close(c.closest('.ci-modal')); return; }
    if (e.target.classList && e.target.classList.contains('ci-modal') && e.target.hasAttribute('data-mform')) close(e.target);
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { var m = [].slice.call(document.querySelectorAll('.ci-modal[data-mform]:not([hidden])')).pop(); if (m) close(m); } });
  function boot() { [].forEach.call(document.querySelectorAll('.ci-modal[data-open-now]'), function (m) { open(m, null); }); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
