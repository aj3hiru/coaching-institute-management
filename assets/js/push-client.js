/* Push notifications for readers: asks with the browser's own permission prompt, subscribes with the site's
   current key (and subscribes again by itself when the key was reset), and keeps the subscriber details fresh.
   Settings come from window.EDU_PUSH = {on, key}; nothing runs when collecting is turned off. */
(function () {
  var C = window.EDU_PUSH || {};
  if (!C.on || !C.key) return;
  if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) return;

  var SW = '/sw.js?v=12102026', SYNC = 'edu_push_sync', ASKED = 'edu_push_asked', root = document.documentElement;
  function store(k, v) { try { if (v === null) localStorage.removeItem(k); else localStorage.setItem(k, v); } catch (e) {} }
  function read(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
  function b64ToBytes(s) {
    var p = '='.repeat((4 - s.length % 4) % 4), b = atob((s + p).replace(/-/g, '+').replace(/_/g, '/'));
    return Uint8Array.from(b, function (c) { return c.charCodeAt(0); });
  }
  function keyOf(sub) {
    try {
      var k = sub.options && sub.options.applicationServerKey; if (!k) return '';
      var a = new Uint8Array(k), s = ''; for (var i = 0; i < a.length; i++) s += String.fromCharCode(a[i]);
      return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    } catch (e) { return ''; }
  }
  function bells(show) { document.querySelectorAll('#push-notify-btn').forEach(function (b) { b.style.display = show ? '' : 'none'; }); }
  function subscribed(on) { root.classList.toggle('nb-push-sub', on); store('push_subscribed', on ? 'true' : null); bells(!on); }

  // the service worker is registered after the page has loaded, so it never slows the first paint / input
  var retried = false, ready = new Promise(function (res) {
    function reg() { res(navigator.serviceWorker.register(SW).then(function () { return navigator.serviceWorker.ready; })); }
    document.readyState === 'complete' ? reg() : window.addEventListener('load', reg, { once: true });
  });

  function save(sub) {
    var body = { subscription: sub.toJSON(), key: C.key, touch: navigator.maxTouchPoints > 1 ? 1 : 0, lang: navigator.language || '' };
    try { body.tz = Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch (e) {}
    return fetch('/push_notifications/save-subscriptions.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body), credentials: 'omit' })
      .then(function (r) { return r.json().then(function (d) { return { status: r.status, d: d }; }); })
      .then(function (x) {
        if (x.d && x.d.ok) { store(SYNC, JSON.stringify({ e: sub.endpoint, k: C.key, t: Date.now() })); return true; }
        // the page was older than the site's key: subscribe again with the current one
        if (x.status === 409 && x.d && x.d.key && !retried) { retried = true; C.key = x.d.key; return sub.unsubscribe().then(function () { return ensure(true); }); }
        return false;
      });
  }

  function ensure(force) {
    return ready.then(function (reg) {
      return reg.pushManager.getSubscription().then(function (sub) {
        if (sub && keyOf(sub) && keyOf(sub) !== C.key) return sub.unsubscribe().then(function () { return null; });
        return sub;
      }).then(function (sub) {
        return sub || reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64ToBytes(C.key) });
      }).then(function (sub) {
        subscribed(true);
        var last = null; try { last = JSON.parse(read(SYNC) || 'null'); } catch (e) {}
        if (force || !last || last.e !== sub.endpoint || last.k !== C.key || Date.now() - last.t > 3 * 86400000) return save(sub);
        return true;
      });
    }).catch(function () { subscribed(false); return false; });
  }

  function toast(t) {
    var el = document.createElement('div'); el.className = 'nb-push-toast'; el.textContent = t; document.body.appendChild(el);
    setTimeout(function () { el.remove(); }, 3200);
  }
  // Blocked (or quietly suppressed by the browser): no site may show the prompt again, so show how to allow it.
  function guide() {
    if (document.getElementById('nb-push-guide')) return;
    var w = document.createElement('div'); w.id = 'nb-push-guide';
    w.setAttribute('style', 'position:fixed;inset:0;z-index:2147483000;background:rgba(15,23,42,.5);display:flex;align-items:flex-start;justify-content:center;padding:14px;font:15px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif');
    w.innerHTML = '<div role="dialog" aria-modal="true" aria-labelledby="nbpg-t" style="position:relative;width:min(420px,100%);margin-top:6vh;background:#fff;color:#1f2937;border-radius:16px;padding:22px 20px 18px;box-shadow:0 20px 50px rgba(0,0,0,.3)">'
      + '<button type="button" data-x aria-label="Close" style="position:absolute;top:8px;right:8px;width:34px;height:34px;border:0;border-radius:50%;background:#f1f5f9;font-size:20px;cursor:pointer;color:#334155">×</button>'
      + '<div style="width:46px;height:46px;border-radius:12px;background:#ecfdf5;display:grid;place-items:center;margin-bottom:10px"><svg width="24" height="24" viewBox="0 0 24 24" fill="#059669" aria-hidden="true"><path d="M12 22a2.5 2.5 0 0 0 2.45-2h-4.9A2.5 2.5 0 0 0 12 22zm7-6V11a7 7 0 0 0-5-6.7V3.5a2 2 0 1 0-4 0v.8A7 7 0 0 0 5 11v5l-2 2v1h18v-1z"/></svg></div>'
      + '<b id="nbpg-t" style="display:block;font-size:18px;margin-bottom:4px">Allow notifications</b>'
      + '<p style="margin:0 0 12px;color:#4b5563">Notifications are blocked for this site in your browser. To get job alerts:</p>'
      + '<ol style="margin:0 0 16px;padding-left:20px;color:#374151"><li>Tap the <b>lock</b> or <b>settings</b> icon next to the web address</li><li>Open <b>Notifications</b> (or Site settings → Notifications)</li><li>Choose <b>Allow</b></li></ol>'
      + '<p style="margin:0 0 14px;font-size:13px;color:#6b7280">You are subscribed automatically as soon as you allow it.</p>'
      + '<button type="button" data-ok style="width:100%;height:44px;border:0;border-radius:10px;background:var(--gc-accent,#0c6878);color:#fff;font-weight:700;font-size:15px;cursor:pointer">OK</button></div>';
    var close = function () { w.remove(); };
    w.addEventListener('click', function (e) { if (e.target === w || e.target.closest('[data-x],[data-ok]')) close(); });
    document.body.appendChild(w);
  }

  function ask(fromClick) {
    if (Notification.permission === 'granted') return ensure(true).then(function (ok) { if (fromClick && ok !== false) toast('✓ Subscribed to job alerts'); });
    if (Notification.permission === 'denied') { if (fromClick) guide(); return; }
    try { sessionStorage.setItem(ASKED, '1'); } catch (e) {}
    var t0 = Date.now();
    var done = function (p) {
      if (p === 'granted') { ensure(true).then(function (ok) { if (fromClick && ok !== false) toast('✓ Subscribed to job alerts'); }); return; }
      // a click that "answers" at once means the browser suppressed the prompt (blocked or quieted): explain how to allow
      if (fromClick && (p === 'denied' || Date.now() - t0 < 350)) guide();
    };
    var r = Notification.requestPermission(done); // older Safari uses the callback
    if (r && r.then) r.then(done);
  }
  window.eduPush = { ask: function () { ask(true); }, subscribed: function () { return root.classList.contains('nb-push-sub'); } };

  // Allowed later from the browser's site settings: subscribe right away, no reload needed
  try {
    navigator.permissions.query({ name: 'notifications' }).then(function (st) {
      st.onchange = function () { if (st.state === 'granted') { var g = document.getElementById('nb-push-guide'); if (g) g.remove(); ensure(true).then(function () { toast('✓ Subscribed to job alerts'); }); } };
    }).catch(function () {});
  } catch (e) {}

  if (Notification.permission === 'granted') { ensure(false); return; }
  bells(true);
  if (Notification.permission === 'denied') return;

  // The browser's own prompt. Browsers show it reliably on the reader's first tap / click / key, so it is asked then;
  // Chrome / Edge are also asked after a moment if the reader has not interacted yet. At most once per visit —
  // the browser itself also stops prompts that keep being closed.
  try { if (sessionStorage.getItem(ASKED)) return; } catch (e) {}
  var ua = navigator.userAgent, needsGesture = /Firefox|FxiOS/.test(ua) || (/Safari/.test(ua) && !/Chrome|CriOS|Chromium|Edg|OPR|SamsungBrowser/.test(ua));
  var asked = false, timer = 0;
  var go = function (e) {
    if (e && e.target && e.target.closest && e.target.closest('#push-notify-btn,[data-edu-subscribe]')) return; // those ask themselves
    if (asked) return; asked = true; clearTimeout(timer);
    ['pointerdown', 'keydown', 'touchend'].forEach(function (t) { document.removeEventListener(t, go, true); });
    if (Notification.permission === 'default') ask(false);
  };
  ['pointerdown', 'keydown', 'touchend'].forEach(function (t) { document.addEventListener(t, go, true); });
  if (!needsGesture) timer = setTimeout(function () { go(null); }, 2500);
})();
