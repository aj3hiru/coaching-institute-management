<?php
// Scan screen (Institute): a big screen for the door. A student scans the ID card (or staff types the admission ID) and the
// student's card shows at once — landscape, like the ID card — with fee status (Full paid / Due ₹…) and an animated tick when
// the attendance is saved. Built for 100+ scans a minute: every scan goes out at once (several together); a scan that can't
// reach the server waits in a queue kept in the browser and is sent by itself when the internet is back (with the time it was
// scanned); a keep-alive every 20 s keeps the login and the connection warm. Opened from Attendance (popup, ?embed=1 inside
// it) or in its own tab. Uses the attendance page's scan action.
require_once __DIR__ . '/components/guard.php';
require_once INCLUDES_PATH . '/institute.php';
$ping = isset($_GET['ping']);
$me = ci_page_start($pdo, ['attendance'], $ping);
if ($ping) { // keep-alive, every 20 s: keeps the login and the connection alive, gives the current form token, the server clock
    header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
    $tok = csrf_token(); session_write_close();
    exit(json_encode(['ok' => true, 'csrf' => $tok, 'day' => date('Y-m-d'), 't' => time()]));
}
$inst = ci_institute();
$logo = $inst['logo'] ? (preg_match('~^https?://~', $inst['logo']) ? $inst['logo'] : '/' . ltrim($inst['logo'], '/')) : '';
$embed = isset($_GET['embed']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title>Scan screen - <?= ci_e($inst['name']) ?></title>
<style>
:root{--p:#4a3369;--p2:#6a4c94;--y:#fcd34d;--ok:#16a34a;--out:#2563eb;--warn:#d97706;--bad:#dc2626}
*{box-sizing:border-box}
html,body{height:100%}
body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;color:#111827;background:radial-gradient(ellipse at top,#efeaf7 0%,#f6f4fa 45%,#eef0f4 100%);display:flex;flex-direction:column;overflow-x:hidden}
.top{display:flex;align-items:center;gap:12px;padding:12px 18px;background:#fff;border-bottom:1px solid #e5e7eb}
.top img{width:36px;height:36px;object-fit:contain}
.top b{font-family:Georgia,"Times New Roman",serif;font-size:18px;color:var(--p);letter-spacing:.02em;text-transform:uppercase}
.top .sp{flex:1}
.top .clk{font-variant-numeric:tabular-nums;font-weight:700;color:#374151;font-size:15px;text-align:right;line-height:1.2}.top .clk small{display:block;font-weight:500;color:#6b7280;font-size:12px}
.top a{display:inline-flex;align-items:center;gap:6px;height:34px;padding:0 12px;border:1px solid #d1d5db;background:#fff;color:#374151;font-size:13px;font-weight:600;text-decoration:none}
/* connection: green = online, amber = slow / sending the waiting scans, red = no internet or logged out */
.net{display:inline-flex;align-items:center;gap:7px;height:34px;padding:0 12px;border:1px solid #d1d5db;background:#fff;font-size:13px;font-weight:600;color:#374151;white-space:nowrap}
.net i{width:9px;height:9px;border-radius:50%;background:#16a34a;box-shadow:0 0 0 3px rgba(22,163,74,.18);flex:none}
.net.slow i{background:#d97706;box-shadow:0 0 0 3px rgba(217,119,6,.2)}
.net.off{border-color:#fca5a5;background:#fff5f5;color:#991b1b}.net.off i{background:#dc2626;box-shadow:0 0 0 3px rgba(220,38,38,.2);animation:blink 1s infinite}
.net a{height:auto;padding:0;border:0;background:none;color:inherit;text-decoration:underline}
@keyframes blink{50%{opacity:.3}}
.inp{display:flex;justify-content:center;padding:14px 16px 0}
.inp form{display:flex;width:min(520px,100%);gap:8px}
.inp input{flex:1;min-width:0;height:46px;padding:0 14px;border:2px solid var(--p);font:inherit;font-size:17px;letter-spacing:.04em;background:#fff}
.inp input:focus{outline:none;box-shadow:0 0 0 3px rgba(74,51,105,.18)}
.inp button{height:46px;padding:0 18px;border:0;background:var(--p);color:#fff;font:inherit;font-weight:700;font-size:15px;cursor:pointer}
.stage{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:18px 16px;min-height:0}
/* status line above the card */
.st{display:flex;align-items:center;gap:10px;margin-bottom:14px;font-size:clamp(18px,2.6vw,26px);font-weight:800;text-align:center;min-height:36px}
.st.in{color:var(--ok)}.st.out{color:var(--out)}.st.wait,.st.done{color:var(--warn)}.st.error{color:var(--bad)}.st.idle{color:#6b7280;font-weight:600}
/* the landscape card (85.6 × 54) */
.card{position:relative;width:min(680px,94vw);aspect-ratio:85.6/54;background:#fff;border-radius:18px;overflow:hidden;box-shadow:0 20px 50px rgba(41,24,66,.18),0 2px 6px rgba(0,0,0,.06);display:flex;flex-direction:column;container-type:inline-size}
.card.pop{animation:pop .42s cubic-bezier(.2,.9,.3,1.25)}
@keyframes pop{from{transform:scale(.86) translateY(14px);opacity:0}to{transform:none;opacity:1}}
.c-h{display:flex;align-items:center;gap:2.4cqw;padding:2.4cqw 3.4cqw;background:linear-gradient(120deg,var(--p),var(--p2));color:#fff}
.c-h img{width:8cqw;height:8cqw;object-fit:contain;background:#fff;border-radius:50%;padding:.6cqw;flex:none}
.c-h b{display:block;font-family:Georgia,"Times New Roman",serif;font-size:4.1cqw;letter-spacing:.02em;text-transform:uppercase;line-height:1.05}
.c-h small{display:block;font-size:1.9cqw;opacity:.85;margin-top:.4cqw}
.c-t{background:var(--y);color:var(--p);text-align:center;font-size:2.1cqw;font-weight:800;letter-spacing:.28em;padding:.9cqw 0}
.c-b{flex:1;display:flex;gap:3.6cqw;padding:3cqw 3.6cqw 0;min-height:0}
.c-ph{flex:none;width:21cqw;aspect-ratio:7/9;align-self:flex-start;border:.5cqw solid var(--p);padding:.4cqw;background:#fff}
.c-ph img,.c-ph svg{width:100%;height:100%;object-fit:cover;object-position:50% 30%;display:block}
.c-d{flex:1;min-width:0}
.c-nm{font-size:4.6cqw;font-weight:800;color:var(--p);text-transform:uppercase;line-height:1.1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;padding-right:13cqw}
.c-d table{border-collapse:collapse;margin-top:1.6cqw;width:100%;table-layout:fixed;font-size:2.55cqw;line-height:1.3}
.c-d th{width:21cqw;text-align:left;font-weight:600;color:#6b7280;padding:.35cqw 0;vertical-align:top;white-space:nowrap}
.c-d td{font-weight:700;padding:.35cqw 0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.c-f{display:flex;align-items:center;gap:2cqw;padding:2cqw 3.6cqw 2.6cqw}
.fee{display:inline-flex;align-items:center;gap:1cqw;padding:1cqw 2.4cqw;border-radius:99px;font-size:2.7cqw;font-weight:800;letter-spacing:.04em}
.fee.paid{background:#dcfce7;color:#166534}.fee.due{background:#fee2e2;color:#991b1b}
.c-f .tm{margin-left:auto;font-size:2.4cqw;color:#374151;font-weight:600;text-align:right}.c-f .tm b{color:#111827}
.c-bar{height:1.4cqw;background:var(--p);border-top:.5cqw solid #646c3e}
/* the animated mark (top right of the card) */
.mk{position:absolute;right:3.2cqw;top:19cqw;width:13cqw;height:13cqw}
.mk svg{width:100%;height:100%;display:block;filter:drop-shadow(0 3px 8px rgba(0,0,0,.15))}
.mk circle{fill:#fff;stroke-width:5;stroke-dasharray:166;stroke-dashoffset:166;animation:draw .5s ease-out forwards}
.mk path{fill:none;stroke-width:6;stroke-linecap:round;stroke-linejoin:round;stroke-dasharray:60;stroke-dashoffset:60;animation:draw .35s .42s ease-out forwards}
.mk.in circle,.mk.in path{stroke:var(--ok)}.mk.out circle,.mk.out path{stroke:var(--out)}.mk.wait circle,.mk.wait path,.mk.done circle,.mk.done path{stroke:var(--warn)}.mk.error circle,.mk.error path{stroke:var(--bad)}
.mk.in svg,.mk.out svg{animation:bump .3s .75s ease-out}
@keyframes draw{to{stroke-dashoffset:0}}
@keyframes bump{50%{transform:scale(1.12)}}
/* idle / error (no student) */
.ph{width:min(680px,94vw);aspect-ratio:85.6/54;border:3px dashed #cfc6de;border-radius:18px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px;color:#7c6f93;text-align:center;padding:20px;background:rgba(255,255,255,.5)}
.ph svg{width:72px;height:72px}
.ph b{font-size:clamp(18px,2.4vw,24px);color:var(--p)}
.ph.err{border-color:#fca5a5;color:#991b1b;background:#fff5f5}.ph.err b{color:var(--bad)}
/* recent scans */
.rec{overflow:hidden;padding:0 16px 16px;-webkit-mask-image:linear-gradient(90deg,#000 88%,transparent);mask-image:linear-gradient(90deg,#000 88%,transparent)} /* one row: the newest present slides in once from the left */
.rec-t{display:flex;flex-wrap:nowrap;gap:8px;width:max-content;will-change:transform}
.rec-t div{display:flex;align-items:center;gap:8px;flex:none;padding:6px 12px 6px 6px;background:#fff;border:1px solid #e5e7eb;border-left:4px solid var(--ok);font-size:13px;max-width:300px}
.rec-t div.new{animation:recin .45s ease-out}
@keyframes recin{from{opacity:0}to{opacity:1}}
.rec-t div img,.rec-t div svg{width:28px;height:36px;object-fit:cover;flex:none}
.rec-t div span{min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.rec-t div em{font-style:normal;color:#6b7280;font-size:12px;margin-left:4px}
<?php if ($embed): ?>.top .nt{display:none}<?php endif; ?>
@media (max-width:600px){.top b{font-size:15px}.top .clk small{display:none}.net span{display:none}}
</style>
</head>
<body>
<div class="top"><?php if ($logo): ?><img src="<?= ci_e($logo) ?>" alt=""><?php endif; ?><b><?= ci_e($inst['name']) ?></b><span class="sp"></span>
  <div class="net" id="net" role="status" aria-live="polite"><i></i><span>Online</span></div>
  <div class="clk"><span id="clk"></span><small><?= date('l, d M Y') ?></small></div>
  <?php if (!$embed): ?><a href="/admin/ci-attendance.php">← Attendance</a><?php endif; ?></div>
<div class="inp"><form id="f" autocomplete="off"><input id="code" inputmode="text" placeholder="Scan card / admission ID" aria-label="Admission ID" autofocus><button type="submit">Mark</button></form></div>
<div class="stage"><div class="st idle" id="st">Ready — scan an ID card</div><div id="box"></div></div>
<div class="rec" aria-label="Checked in now"><div class="rec-t" id="rec"></div></div>
<script>
(function () {
  var DAY = <?= json_encode(date('Y-m-d')) ?>, CSRF = <?= json_encode(csrf_token(), JSON_HEX_TAG) ?>, LOGO = <?= json_encode($logo, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES) ?>, INST = <?= json_encode(['name' => $inst['name'], 'address' => $inst['address'], 'phone' => $inst['phone']], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?>;
  var EMBED = <?= $embed ? 'true' : 'false' ?>;
  var BLANK = <?= json_encode(ci_photo_blank(), JSON_HEX_TAG) ?>;
  var f = document.getElementById('f'), code = document.getElementById('code'), st = document.getElementById('st'), box = document.getElementById('box'), rec = document.getElementById('rec');
  var seq = 0, shown = 0, idleT = null;
  function esc(t) { return String(t == null ? '' : t).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function photo(s) { return s && s.photo ? '<img src="' + esc(s.photo) + '" alt="">' : BLANK; }
  var MARK = { in: 'M15 27l8 8 15-17', out: 'M15 27l8 8 15-17', wait: 'M27 15v14M27 37v.5', done: 'M27 15v14M27 37v.5', error: 'M18 18l18 18M36 18L18 36' };
  var HEAD = { in: 'Present', out: 'Checked out', wait: 'Already checked in', done: 'Already checked out', error: 'Not marked' };
  (function tick() { var d = new Date(); document.getElementById('clk').textContent = d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' }); setTimeout(tick, 1000 - d.getMilliseconds()); })();
  function beep(ok) { try { var a = new (window.AudioContext || window.webkitAudioContext)(), o = a.createOscillator(), g = a.createGain(); o.frequency.value = ok ? 990 : 220; g.gain.value = .15; o.connect(g); g.connect(a.destination); o.start(); o.stop(a.currentTime + (ok ? .1 : .25)); } catch (x) {} }
  function idle() { st.className = 'st idle'; st.textContent = 'Ready — scan an ID card'; box.innerHTML = '<div class="ph"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 7V5a2 2 0 0 1 2-2h2M17 3h2a2 2 0 0 1 2 2v2M21 17v2a2 2 0 0 1-2 2h-2M7 21H5a2 2 0 0 1-2-2v-2M7 8v8M10 8v8M13 8v8M17 8v8"/></svg><b>Scan your ID card</b><span>Your card shows here and the attendance is marked.</span></div>'; }
  function show(d, code) {
    var s = d.student, k = d.kind || 'error';
    st.className = 'st ' + k;
    st.textContent = (k === 'in' ? '✓ ' : k === 'out' ? '✓ ' : '') + HEAD[k] + (s ? ' — ' + s.name : '') + (k === 'in' || k === 'out' ? ' · ' + d.time : '');
    if (!s) { box.innerHTML = '<div class="ph err"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M9 9l6 6M15 9l-6 6"/></svg><b>' + esc(d.msg || 'Not found') + '</b><span>' + esc(code) + '</span></div>'; return; }
    var rows = [['Admission ID', s.reg], ['Class', s['class'] || '—'], ['Batch', s.batches || '—'], ["Father's Name", s.father || '—'], ['Contact No.', s.mobile || '—']];
    var fee = s.due > 0 ? '<span class="fee due">DUE · ' + esc(s.due_text) + '</span>' : '<span class="fee paid">✓ FULL PAID</span>';
    var tm = (s['in'] ? 'In <b>' + esc(s['in']) + '</b>' : '') + (s.out ? ' · Out <b>' + esc(s.out) + '</b>' : '');
    box.innerHTML = '<div class="card pop"><div class="c-h">' + (LOGO ? '<img src="' + esc(LOGO) + '" alt="">' : '') + '<div><b>' + esc(INST.name) + '</b>' + (INST.address ? '<small>' + esc(INST.address) + '</small>' : '') + '</div></div>'
      + '<div class="c-t">STUDENT IDENTITY CARD</div>'
      + '<div class="c-b"><div class="c-ph">' + photo(s) + '</div><div class="c-d"><div class="c-nm">' + esc(s.name) + '</div><table>'
      + rows.map(function (r) { return '<tr><th>' + r[0] + '</th><td>' + (r[0] === 'Contact No.' && s.mobile ? TEL : '') + esc(r[1]) + '</td></tr>'; }).join('') + '</table></div></div>'
      + '<div class="c-f">' + fee + '<span class="tm">' + (k === 'wait' || k === 'done' ? esc(d.msg) : tm) + '</span></div><div class="c-bar"></div>'
      + '<div class="mk ' + k + '" title="' + esc(d.msg) + '"><svg viewBox="0 0 54 54"><circle cx="27" cy="27" r="23.5"/><path d="' + MARK[k] + '"/></svg></div></div>';
  }
  // only the students who just became present (check-in) — not check-outs, repeat scans or errors; one row, slides once
  var TEL = '<svg viewBox="0 0 24 24" width="1em" height="1em" fill="none" stroke="#16a34a" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-.12em;margin-right:.3em"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8.1 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2z"/></svg>';
  function addRecent(d) {
    var s = d.student; if (d.kind !== 'in' || !s) return;
    var el = document.createElement('div'); el.className = 'new'; el.title = d.msg || '';
    el.innerHTML = photo(s) + '<span><b>' + esc(s.name) + '</b><em>' + esc(s.reg || '') + ' · ' + esc(d.time || '') + '</em></span>';
    rec.insertBefore(el, rec.firstChild); while (rec.children.length > 20) rec.removeChild(rec.lastChild);
    var w = el.offsetWidth + 8; // the row moves right by one name, once
    rec.style.transition = 'none'; rec.style.transform = 'translateX(-' + w + 'px)'; void rec.offsetWidth;
    rec.style.transition = 'transform .45s cubic-bezier(.2,.8,.2,1)'; rec.style.transform = 'translateX(0)';
    setTimeout(function () { el.classList.remove('new'); }, 500);
  }
  // ---- sending: a queue kept in the browser, so no scan is ever lost ----
  // every scan goes out at once (up to 6 together); no internet / server busy → it stays in the queue and is tried again
  // (1 s, 2 s, 4 s … up to 15 s, and at once when the internet comes back), sent with how long ago it was scanned
  var QK = 'ciScanQ', Q = [], busy = 0, MAX = 6, fails = 0, lastOk = Date.now(), loggedOut = false, retryT = null, recent = {};
  try { Q = JSON.parse(localStorage.getItem(QK) || '[]').filter(function (x) { return x && x.code && x.day === DAY; }); } catch (x) { Q = []; }
  function save() { try { localStorage.setItem(QK, JSON.stringify(Q.map(function (x) { return { id: x.id, code: x.code, at: x.at, day: x.day }; }))); } catch (x) {} }
  var net = document.getElementById('net');
  function netShow() {
    var wait = Q.length, off = loggedOut || !navigator.onLine || fails >= 2;
    net.className = 'net' + (off ? ' off' : (wait ? ' slow' : ''));
    net.innerHTML = '<i></i><span>' + (loggedOut ? 'Logged out — <a href="/admin-login" target="_blank" rel="noopener">log in again</a>' + (wait ? ' · ' + wait + ' waiting' : '')
      : off ? 'No internet' + (wait ? ' · ' + wait + ' scan' + (wait > 1 ? 's' : '') + ' waiting — sent by itself when back' : '')
      : wait ? 'Sending ' + wait + '…' : 'Online') + '</span>';
  }
  function add(c) {
    var k = c.toUpperCase(), now = Date.now();
    if (recent[k] && now - recent[k] < 4000) return; // the scanner read the same card twice
    recent[k] = now;
    var it = { id: now + '-' + Math.random().toString(36).slice(2, 7), code: c, at: now, day: DAY, seq: ++seq, tries: 0 };
    Q.push(it); save(); pump();
  }
  function pump() {
    netShow();
    if (loggedOut) return;
    Q.forEach(function (it) { if (busy < MAX && !it.going && (!it.next || it.next <= Date.now())) go(it); });
  }
  function done(it) { Q = Q.filter(function (x) { return x !== it; }); save(); }
  function retry(it) {
    it.going = false; busy--; it.tries++;
    it.next = Date.now() + Math.min(15000, 1000 * Math.pow(2, Math.min(it.tries - 1, 4)));
    clearTimeout(retryT); retryT = setTimeout(pump, Math.min(15000, 1000 * Math.pow(2, Math.min(it.tries - 1, 4))) + 50);
    netShow();
  }
  function go(it) {
    it.going = true; busy++;
    var ctl = window.AbortController ? new AbortController() : null, to = setTimeout(function () { if (ctl) ctl.abort(); }, 12000);
    var ago = Math.max(0, Math.round((Date.now() - it.at) / 1000));
    var body = new URLSearchParams({ action: 'scan', code: it.code, batch: '0', ago: ago > 3 ? String(ago) : '0', _csrf: CSRF });
    fetch('/admin/ci-attendance.php', { method: 'POST', body: body, credentials: 'same-origin', cache: 'no-store', headers: { 'X-Requested-With': 'fetch' }, signal: ctl ? ctl.signal : undefined })
      .then(function (r) {
        if (r.redirected && /admin-login/.test(r.url)) throw { login: 1 };
        return r.json().catch(function () { throw { bad: 1 }; });
      })
      .then(function (d) {
        clearTimeout(to);
        if (d && d.csrf) { refreshToken(true); throw { token: 1 }; } // the form token changed: get the new one and send again
        fails = 0; lastOk = Date.now(); it.going = false; busy--; done(it);
        result(d, it);
        pump();
      })
      .catch(function (e) {
        clearTimeout(to);
        if (e && e.login) { loggedOut = true; it.going = false; busy--; netShow(); return; }
        if (!(e && e.token)) fails++;
        retry(it);
      });
  }
  function result(d, it) {
    beep(d.ok); addRecent(d);
    if (EMBED && window.parent !== window) try { window.parent.postMessage({ ciScan: d }, location.origin); } catch (x) {}
    if (it.seq < shown) return; shown = it.seq; show(d, it.code);
    clearTimeout(idleT); idleT = setTimeout(idle, 9000);
  }
  // ---- keep-alive: every 20 s (and when the screen / internet comes back) ----
  var pinging = false;
  function refreshToken(force) {
    if (pinging && !force) return; pinging = true;
    fetch('/admin/ci-scan.php?ping=1&_=' + Date.now(), { credentials: 'same-origin', cache: 'no-store', headers: { 'X-Requested-With': 'fetch' } })
      .then(function (r) { if (r.redirected && /admin-login/.test(r.url)) throw { login: 1 }; return r.json(); })
      .then(function (d) {
        pinging = false; if (!d || !d.ok) return;
        fails = 0; lastOk = Date.now(); CSRF = d.csrf;
        if (loggedOut) loggedOut = false; // logged in again in another tab: send what is waiting
        if (d.day !== DAY && !Q.length) { location.reload(); return; } // a new day: fresh page
        pump();
      })
      .catch(function (e) { pinging = false; if (e && e.login) loggedOut = true; else fails = Math.max(fails, 2); netShow(); });
  }
  setInterval(refreshToken, 20000);
  setInterval(function () { if (Q.length) pump(); }, 5000);
  window.addEventListener('online', function () { fails = 0; Q.forEach(function (x) { x.next = 0; }); refreshToken(true); pump(); });
  window.addEventListener('offline', netShow);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) { refreshToken(true); keepAwake(); } });
  // the door screen never sleeps (where the browser allows it)
  var lock = null;
  function keepAwake() { try { if (navigator.wakeLock && !lock) navigator.wakeLock.request('screen').then(function (l) { lock = l; l.addEventListener('release', function () { lock = null; }); }).catch(function () {}); } catch (x) {} }
  keepAwake();
  window.addEventListener('beforeunload', function (e) { if (Q.length) { e.preventDefault(); e.returnValue = ''; } });
  f.addEventListener('submit', function (e) { e.preventDefault(); var c = code.value.trim(); code.value = ''; if (c) add(c); code.focus(); });
  // the scanner types into the box: keep it focused
  document.addEventListener('click', function (e) { if (!e.target.closest('a,button,input')) code.focus(); });
  window.addEventListener('focus', function () { code.focus(); });
  setInterval(function () { if (document.activeElement !== code && !document.querySelector('input:focus,select:focus,textarea:focus')) code.focus(); }, 1500);
  idle(); netShow(); if (Q.length) { Q.forEach(function (x) { x.seq = ++seq; x.tries = 0; }); pump(); }
})();
</script>
</body>
</html>
