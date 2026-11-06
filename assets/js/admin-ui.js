/* Admin dialogs — the same styled confirm / notice boxes as the new CMS, used instead of the browser's plain ones.
     adminConfirm('Delete this?', {title, confirmText, cancelText, danger}) → Promise<boolean>
     adminNotice('Saved', {title, type: 'success'|'error'|'info', autoClose: ms})
   Any button/link/form with data-confirm="message" asks first (data-confirm-ok / data-confirm-title / data-confirm-safe optional). */
(function () {
  var X = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
  var WARN = '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>';
  var ICONS = {
    success: '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
    error: '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',
    info: '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>'
  };
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

  window.adminConfirm = function (message, o) {
    o = o || {};
    var danger = o.danger !== false;
    return new Promise(function (resolve) {
      var ov = document.createElement('div');
      ov.className = 'acm-overlay acm-open'; ov.setAttribute('role', 'dialog'); ov.setAttribute('aria-modal', 'true');
      ov.innerHTML = '<div class="acm-box"><div class="acm-icon' + (danger ? ' acm-danger' : '') + '">' + WARN + '</div>' +
        '<h3 class="acm-title">' + esc(o.title || 'Please Confirm') + '</h3><p class="acm-message">' + esc(message) + '</p>' +
        '<div class="acm-actions"><button type="button" class="acm-btn acm-btn-cancel">' + esc(o.cancelText || 'Cancel') + '</button>' +
        '<button type="button" class="acm-btn acm-btn-confirm' + (danger ? ' acm-danger' : '') + '">' + esc(o.confirmText || 'Confirm') + '</button></div>' +
        '<button type="button" class="acm-close" aria-label="Close">' + X + '</button></div>';
      function done(v) { document.removeEventListener('keydown', key, true); ov.remove(); resolve(v); }
      function key(e) { if (e.key === 'Escape') { e.stopPropagation(); done(false); } }
      ov.addEventListener('click', function (e) { if (e.target === ov || e.target.closest('.acm-btn-cancel,.acm-close')) done(false); else if (e.target.closest('.acm-btn-confirm')) done(true); });
      document.addEventListener('keydown', key, true);
      document.body.appendChild(ov);
      ov.querySelector('.acm-btn-confirm').focus();
    });
  };

  var noticeTimer;
  window.adminNotice = function (message, o) {
    o = o || {};
    var type = o.type || 'info', old = document.getElementById('anm-overlay');
    if (old) old.remove();
    clearTimeout(noticeTimer);
    var ov = document.createElement('div');
    ov.id = 'anm-overlay'; ov.className = 'anm-overlay anm-open'; ov.setAttribute('role', 'alertdialog');
    ov.innerHTML = '<div class="anm-box"><div class="anm-icon anm-' + type + '">' + ICONS[type] + '</div>' +
      '<h3 class="anm-title">' + esc(o.title || (type === 'error' ? 'Something Went Wrong' : type === 'success' ? 'Success' : 'Notice')) + '</h3>' +
      '<p class="anm-message">' + esc(message) + '</p><button type="button" class="anm-ok">OK</button><button type="button" class="anm-close" aria-label="Close">' + X + '</button></div>';
    ov.addEventListener('click', function (e) { if (e.target === ov || e.target.closest('.anm-ok,.anm-close')) ov.remove(); });
    document.body.appendChild(ov);
    ov.querySelector('.anm-ok').focus();
    var ms = o.autoClose === 0 ? 0 : (o.autoClose || 4000);
    if (ms) noticeTimer = setTimeout(function () { ov.remove(); }, ms);
  };

  // data-confirm on a link, button or form: ask with the styled box, then carry on.
  function opts(el) { return { title: el.getAttribute('data-confirm-title') || undefined, confirmText: el.getAttribute('data-confirm-ok') || undefined, danger: !el.hasAttribute('data-confirm-safe') }; }
  document.addEventListener('click', function (e) {
    var el = e.target.closest('a[data-confirm],button[data-confirm],input[type=submit][data-confirm]');
    if (!el || el.__confirmed) return;
    e.preventDefault(); e.stopImmediatePropagation();
    adminConfirm(el.getAttribute('data-confirm'), opts(el)).then(function (ok) {
      if (!ok) return;
      el.__confirmed = true;
      if (el.tagName === 'A') location.href = el.href;
      else if (el.form && (el.type === 'submit' || el.tagName === 'INPUT')) { if (el.name) { var h = document.createElement('input'); h.type = 'hidden'; h.name = el.name; h.value = el.value; el.form.appendChild(h); } el.form.__confirmed = true; el.form.requestSubmit ? el.form.requestSubmit() : el.form.submit(); }
      else el.click();
      setTimeout(function () { el.__confirmed = false; }, 0);
    });
  }, true);
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!f.hasAttribute || !f.hasAttribute('data-confirm') || f.__confirmed) { if (f) f.__confirmed = false; return; }
    e.preventDefault();
    adminConfirm(f.getAttribute('data-confirm'), opts(f)).then(function (ok) { if (ok) { f.__confirmed = true; f.submit(); } });
  }, true);
})();

/* Admin bar → Clear Cache: clears in the background and stays on this page (no jump to the Cache Manager). */
document.addEventListener('submit', function (e) {
  var f = e.target;
  if (!f.matches || !f.matches('form[data-ab-clear]')) return;
  e.preventDefault();
  var go = function () {
    var btn = f.querySelector('button'); if (btn) btn.disabled = true;
    fetch(f.getAttribute('action'), { method: 'POST', body: new FormData(f), credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
      .then(function (r) { return r.json(); })
      .then(function (d) { (window.adminNotice || alert)(d.message || (d.ok ? 'Cache cleared.' : 'Could not clear the cache.'), { type: d.ok ? 'success' : 'error', autoClose: 3500 }); })
      .catch(function () { (window.adminNotice || alert)('Could not clear the cache. Please reload and try again.', { type: 'error' }); })
      .then(function () { var btn = f.querySelector('button'); if (btn) btn.disabled = false; });
  };
  if (window.adminConfirm) window.adminConfirm('Clear all cached pages? Visitors get fresh pages right away.', { title: 'Clear cache', confirmText: 'Clear cache' }).then(function (ok) { if (ok) go(); });
  else if (confirm('Clear all cached pages?')) go();
});
