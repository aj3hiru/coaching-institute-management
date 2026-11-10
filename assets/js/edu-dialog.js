/* EduDialog — our own small pop-ups in place of the browser's confirm() / prompt() / alert().
   EduDialog.confirm(text, {title, ok, danger}) → Promise<true|false>
   EduDialog.prompt(label, {title, value, placeholder, ok}) → Promise<string|null>
   EduDialog.alert(text, {title}) → Promise
   Used on the lecture page (Discussion) and in the admin lecture editor. Esc / clicking outside = Cancel. */
(function () {
  if (window.EduDialog) return;
  function css() {
    if (document.getElementById('edd-css')) return;
    var s = document.createElement('style'); s.id = 'edd-css';
    s.textContent = '.edd{position:fixed;inset:0;z-index:100000;display:grid;place-items:center;padding:16px;background:rgba(15,23,42,.45);opacity:0;transition:opacity .16s ease}'
      + '.edd.in{opacity:1}.edd-box{width:100%;max-width:400px;background:#fff;color:#111827;border-radius:8px;box-shadow:0 20px 50px rgba(15,23,42,.3);padding:20px;transform:translateY(8px) scale(.98);transition:transform .18s ease;font-family:inherit}'
      + '.edd.in .edd-box{transform:none}.edd-h{display:flex;align-items:center;gap:10px;margin:0 0 8px;font-size:17px;font-weight:700;line-height:1.3}'
      + '.edd-i{flex:0 0 auto;display:grid;place-items:center;width:34px;height:34px;border-radius:50%;background:#eef2ff;color:#4f46e5}.edd-i svg{width:18px;height:18px}'
      + '.edd.danger .edd-i{background:#fee2e2;color:#dc2626}.edd-t{margin:0 0 4px;font-size:14px;line-height:1.55;color:#4b5563}'
      + '.edd-in{width:100%;box-sizing:border-box;margin:8px 0 2px;padding:10px 12px;border:1px solid #d1d5db;border-radius:6px;font:inherit;font-size:15px;color:#111827;background:#fff;outline:none}'
      + '.edd-in:focus{border-color:var(--sa,var(--primary,#4f46e5));box-shadow:0 0 0 3px rgba(79,70,229,.15)}'
      + '.edd-b{display:flex;justify-content:flex-end;gap:8px;margin-top:16px}.edd-b button{min-width:88px;padding:9px 16px;border-radius:6px;font:inherit;font-size:14px;font-weight:600;cursor:pointer;border:1px solid #d1d5db;background:#fff;color:#374151}'
      + '.edd-b button:hover{background:#f9fafb}.edd-b .edd-ok{border-color:transparent;background:var(--sa,var(--primary,#4f46e5));color:#fff}.edd-b .edd-ok:hover{filter:brightness(.95);background:var(--sa,var(--primary,#4f46e5))}'
      + '.edd.danger .edd-b .edd-ok{background:#dc2626}.edd.danger .edd-b .edd-ok:hover{background:#b91c1c}'
      + '[data-theme="dark"] .edd-box{background:#1f2937;color:#e5e7eb}[data-theme="dark"] .edd-t{color:#9ca3af}[data-theme="dark"] .edd-in,[data-theme="dark"] .edd-b button:not(.edd-ok){background:#111827;color:#e5e7eb;border-color:#374151}';
    document.head.appendChild(s);
  }
  var ICON = { q: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>',
    del: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/></svg>',
    pen: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>' };
  function esc(t) { return String(t == null ? '' : t).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function open(kind, text, o) {
    o = o || {}; css();
    return new Promise(function (res) {
      var last = document.activeElement, w = document.createElement('div');
      w.className = 'edd' + (o.danger ? ' danger' : ''); w.setAttribute('role', 'dialog'); w.setAttribute('aria-modal', 'true');
      var title = o.title || (kind === 'prompt' ? text : kind === 'alert' ? 'Please note' : 'Are you sure?');
      w.innerHTML = '<div class="edd-box"><h3 class="edd-h"><span class="edd-i">' + (o.danger ? ICON.del : kind === 'prompt' ? ICON.pen : ICON.q) + '</span><span>' + esc(title) + '</span></h3>'
        + (kind !== 'prompt' || o.title ? '<p class="edd-t">' + esc(kind === 'prompt' ? text : text) + '</p>' : '')
        + (kind === 'prompt' ? '<input class="edd-in" type="text" maxlength="200">' : '')
        + '<div class="edd-b">' + (kind === 'alert' ? '' : '<button type="button" class="edd-no">Cancel</button>') + '<button type="button" class="edd-ok">' + esc(o.ok || (kind === 'alert' ? 'OK' : kind === 'prompt' ? 'Save' : o.danger ? 'Remove' : 'Yes')) + '</button></div></div>';
      if (kind === 'prompt' && o.title && text === o.title) w.querySelector('.edd-t').remove();
      var inp = w.querySelector('.edd-in'); if (inp) { inp.value = o.value || ''; inp.placeholder = o.placeholder || ''; }
      document.body.appendChild(w); requestAnimationFrame(function () { w.classList.add('in'); });
      (inp || w.querySelector('.edd-ok')).focus(); if (inp) inp.select();
      function done(v) { document.removeEventListener('keydown', key, true); w.classList.remove('in'); setTimeout(function () { w.remove(); }, 160); try { last && last.focus(); } catch (e) {} res(v); }
      function ok() { if (kind === 'prompt') { var v = inp.value.trim(); if (!v) { inp.focus(); return; } done(v); } else done(true); }
      function no() { done(kind === 'prompt' ? null : kind === 'alert' ? true : false); }
      function key(e) { if (e.key === 'Escape') { e.preventDefault(); no(); } else if (e.key === 'Enter' && (inp || document.activeElement === w.querySelector('.edd-ok'))) { e.preventDefault(); ok(); } }
      document.addEventListener('keydown', key, true);
      w.addEventListener('click', function (e) { if (e.target === w) no(); else if (e.target.closest('.edd-ok')) ok(); else if (e.target.closest('.edd-no')) no(); });
    });
  }
  window.EduDialog = { confirm: function (t, o) { return open('confirm', t, o); }, prompt: function (t, o) { return open('prompt', t, o); }, alert: function (t, o) { return open('alert', t, o); } };
})();
