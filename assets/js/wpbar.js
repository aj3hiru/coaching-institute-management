/* Toolbar behaviour like WordPress' admin-bar.js: menus open on hover (desktop) and on tap (touch / phones),
   close on outside tap or Escape; "Clear Cache" clears in the background. */
(function () {
  function bar() { return document.getElementById('wpadminbar'); }
  function closeAll(except) {
    var b = bar(); if (!b) return;
    b.querySelectorAll('li.hover').forEach(function (li) { if (li !== except) { li.classList.remove('hover'); var a = li.querySelector(':scope>.ab-item'); a && a.setAttribute('aria-expanded', 'false'); } });
  }
  var touch = window.matchMedia('(hover: none), (max-width: 782px)');
  document.addEventListener('click', function (e) {
    var b = bar(); if (!b) return;
    var item = e.target.closest('#wpadminbar .ab-top-menu > li.menupop > .ab-item');
    if (item && touch.matches) {
      var li = item.parentNode;
      if (!li.classList.contains('hover')) { e.preventDefault(); closeAll(li); li.classList.add('hover'); item.setAttribute('aria-expanded', 'true'); return; }
    }
    if (!e.target.closest('#wpadminbar')) closeAll();
  }, true);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeAll(); });
  document.addEventListener('mouseover', function (e) {
    if (touch.matches) return;
    var li = e.target.closest && e.target.closest('#wpadminbar .ab-top-menu > li.menupop');
    if (li) { closeAll(li); li.classList.add('hover'); }
  });
  document.addEventListener('mouseout', function (e) {
    if (touch.matches) return;
    var li = e.target.closest && e.target.closest('#wpadminbar .ab-top-menu > li.menupop');
    if (li && !li.contains(e.relatedTarget)) li.classList.remove('hover');
  });
  document.addEventListener('focusin', function (e) {
    var li = e.target.closest && e.target.closest('#wpadminbar .ab-top-menu > li.menupop');
    closeAll(li || undefined); if (li) li.classList.add('hover');
  });

  function toast(msg, ok) {
    if (window.adminNotice) { window.adminNotice(msg, { type: ok ? 'success' : 'error', autoClose: 3500 }); return; }
    var t = document.createElement('div'); t.className = 'wpbar-toast'; t.textContent = msg; document.body.appendChild(t); setTimeout(function () { t.remove(); }, 3500);
  }
  document.addEventListener('click', function (e) {
    var a = e.target.closest('#wpadminbar [data-wpbar-clear]'); if (!a) return;
    e.preventDefault();
    // a second click closes the question that is already open (it never stacks a new one)
    var openOv = document.querySelector('.acm-overlay[data-wpbar-clear-q]');
    if (openOv) { var c = openOv.querySelector('.acm-btn-cancel'); if (c) c.click(); else openOv.remove(); return; }
    if (a.getAttribute('aria-busy') === 'true') return;
    var go = function () {
      a.setAttribute('aria-busy', 'true');
      var fd = new FormData(); fd.append('action', 'clear_all'); fd.append('_csrf', a.getAttribute('data-csrf'));
      fetch(a.getAttribute('href'), { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
        .then(function (r) { return r.json(); })
        .then(function (d) { toast(d.message || (d.ok ? 'Cache cleared.' : 'Could not clear the cache.'), !!d.ok); })
        .catch(function () { toast('Could not clear the cache.', false); })
        .then(function () { a.removeAttribute('aria-busy'); });
    };
    if (window.adminConfirm) {
      window.adminConfirm('Clear all cached pages? Visitors get fresh pages right away.', { title: 'Clear cache', confirmText: 'Clear cache' }).then(function (ok) { if (ok) go(); });
      var ovs = document.querySelectorAll('.acm-overlay'); if (ovs.length) ovs[ovs.length - 1].setAttribute('data-wpbar-clear-q', '1');
    }
    else if (confirm('Clear all cached pages?')) go();
  });
})();
/* superadmin: maintenance mode switch (admin bar) */
document.addEventListener('click', function (e) {
  var a = e.target.closest && e.target.closest('#wpadminbar [data-wpbar-maint]'); if (!a) return;
  e.preventDefault();
  var on = a.getAttribute('data-wpbar-maint') === '1';
  if (on && !confirm('Turn maintenance mode ON? Everyone except you will see "Software is under maintenance" in the admin area.')) return;
  var fd = new FormData(); fd.append('on', on ? '1' : '0'); fd.append('_csrf', a.getAttribute('data-csrf'));
  a.style.opacity = '.6';
  fetch('/admin/api/maintenance.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); })
    .then(function (d) { if (d.error) { alert(d.error); a.style.opacity = ''; return; } location.reload(); })
    .catch(function () { alert('Could not change maintenance mode. Please try again.'); a.style.opacity = ''; });
});
