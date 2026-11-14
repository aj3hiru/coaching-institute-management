<?php
// Scan screen (Institute): a big screen for the door. A student scans the ID card (or staff types the admission ID) and the
// student's card shows at once — landscape, like the ID card — with fee status (Full paid / Due ₹…) and an animated tick when
// the attendance is saved. Built for 200+ scans a minute: the students' list is kept in the browser, so the card and the tick
// show the moment the card is scanned (no waiting for the server); every scan goes out at once (several together); a scan that can't
// reach the server waits in a queue kept in the browser and is sent by itself when the internet is back (with the time it was
// scanned); a keep-alive every 20 s keeps the login and the connection warm. Opened from Attendance (popup, ?embed=1 inside
// it) or in its own tab. Uses the attendance page's scan action.
require_once __DIR__ . '/components/guard.php';
require_once INCLUDES_PATH . '/institute.php';
$ping = isset($_GET['ping']); $roster = isset($_GET['roster']);
$me = ci_page_start($pdo, ['attendance'], $ping || $roster);
if ($roster) {
    // the students' list for the instant card, kept in the browser and only sent again when it changed:
    //  · details (name, photo, class, batch, father, fee due — no mobile number, today a holiday?) carry a fingerprint (v); the browser
    //    sends the one it has — same → "same" (a few bytes), changed → the whole list again (gzip: ~80–200 KB for 5000);
    //    every time the screen is opened it asks for the whole list (v empty), so it always starts fresh
    //  · today's check-in / check-out come as changes since the browser's last call (since)
    header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
    session_write_close();
    if (!headers_sent() && str_contains((string)($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''), 'gzip') && !ini_get('zlib.output_compression')) ob_start('ob_gzhandler');
    $today = date('Y-m-d'); $now = time();
    $rows = $pdo->query("SELECT s.id, s.reg_no, s.name, s.father_name, s.photo, c.name AS class_name,
            (SELECT COALESCE(SUM(" . CI_DUE_SQL . "), 0) FROM ci_enrollments e WHERE e.student_id = s.id) AS due
        FROM ci_students s LEFT JOIN ci_classes c ON c.id = s.class_id
        WHERE s.status = 'active' AND s.stype <> 'online'
          AND EXISTS (SELECT 1 FROM ci_enrollments e JOIN ci_batches b ON b.id = e.batch_id WHERE e.student_id = s.id AND e.status = 'active' AND b.status = 'active')
        ORDER BY s.id")->fetchAll(PDO::FETCH_ASSOC);
    $bat = [];
    foreach ($pdo->query("SELECT e.student_id, b.id, b.name, b.course_id FROM ci_enrollments e JOIN ci_batches b ON b.id = e.batch_id WHERE e.status = 'active' AND b.status = 'active' ORDER BY b.name") as $b) $bat[(int)$b['student_id']][(int)$b['id']] = $b;
    $hols = ci_holidays_between($pdo, $today, $today);
    $list = []; $regOf = [];
    foreach ($rows as $r) {
        $bs = $bat[(int)$r['id']] ?? [];
        $open = array_filter($bs, fn($b) => !ci_holiday_on($hols, $today, (int)$b['id'], (int)$b['course_id']));
        $regOf[(int)$r['id']] = $r['reg_no'];
        $list[] = ['reg' => $r['reg_no'], 'name' => $r['name'], 'class' => $r['class_name'], 'batches' => implode(', ', array_unique(array_column($bs, 'name'))),
            'father' => (string)($r['father_name'] ?? ''), 'photo' => ci_photo_url($r['photo'] ?? ''),
            'due' => round((float)$r['due'], 2), 'due_text' => (float)$r['due'] > 0 ? ci_money0($r['due']) : '', 'hol' => $bs && !$open ? 1 : 0];
    }
    $v = substr(md5($today . ci_att_min_out() . json_encode($list)), 0, 16);
    // today's in / out: everything (first call of the day) or only what changed since the last call (a few seconds of overlap)
    $since = (int)($_GET['since'] ?? 0);
    $aq = $pdo->prepare("SELECT student_id, MIN(in_at) AS i, MAX(out_at) AS o FROM ci_attendance WHERE att_date = ? AND status = 'present'"
        . ($since > 0 ? " AND student_id IN (SELECT student_id FROM ci_attendance WHERE att_date = ? AND GREATEST(updated_at, created_at) >= ?)" : '') . " GROUP BY student_id");
    $aq->execute($since > 0 ? [$today, $today, date('Y-m-d H:i:s', $since - 10)] : [$today]);
    $att = [];
    foreach ($aq as $a) if (isset($regOf[(int)$a['student_id']])) $att[] = [$regOf[(int)$a['student_id']], $a['i'] ? strtotime($a['i']) : 0, $a['o'] ? strtotime($a['o']) : 0];
    $out = ['ok' => true, 'day' => $today, 't' => $now, 'min' => ci_att_min_out(), 'v' => $v, 'n' => count($list), 'att' => $att];
    if (($_GET['v'] ?? '') === $v) $out['same'] = 1; else $out['list'] = $list;
    exit(json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}
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
.top{position:relative;z-index:21;display:flex;align-items:center;gap:12px;padding:12px 18px;background:#fff;border-bottom:1px solid #e5e7eb}
.top img{width:36px;height:36px;object-fit:contain}
.top b{font-family:Georgia,"Times New Roman",serif;font-size:18px;color:var(--p);letter-spacing:.02em;text-transform:uppercase}
.top .sp{flex:1}
.top .clk{font-variant-numeric:tabular-nums;font-weight:700;color:#374151;font-size:15px;text-align:right;line-height:1.2}.top .clk small{display:block;font-weight:500;color:#6b7280;font-size:12px}
.top a{display:inline-flex;align-items:center;gap:6px;height:34px;padding:0 12px;border:1px solid #d1d5db;background:#fff;color:#374151;font-size:13px;font-weight:600;text-decoration:none}
/* connection badge: animated icon per state — ok (tick drawn + soft pulse), sync (turning arrows), retry (wifi waves),
   off (waves fade + slash drawn), login (lock shakes) */
.net{display:inline-flex;align-items:center;gap:8px;height:34px;padding:0 12px 0 8px;border:1px solid #d1d5db;border-radius:99px;background:#fff;font-size:13px;font-weight:600;color:#374151;white-space:nowrap;transition:background .3s,border-color .3s,color .3s}
.net .n-ico{position:relative;display:block;width:22px;height:22px;flex:none;animation:n-in .35s cubic-bezier(.2,.9,.3,1.3)}
.net svg{width:100%;height:100%;display:block;fill:none;stroke:currentColor;stroke-width:2.2;stroke-linecap:round;stroke-linejoin:round;overflow:visible}
.net span{transition:opacity .2s;animation:n-txt .3s ease-out}
@keyframes n-txt{from{opacity:0;transform:translateX(-4px)}to{opacity:1;transform:none}}.net span em{font-style:normal;font-weight:500;opacity:.75;margin-right:1px}
.net a{height:auto;padding:0;border:0;background:none;color:inherit;text-decoration:underline}
@keyframes n-in{from{transform:scale(.4);opacity:0}to{transform:none;opacity:1}}
/* ok */
.net.n-ok{border-color:#bbf7d0;background:#f0fdf4;color:#166534}
.net.n-ok .n-ico{color:#16a34a}
.net.n-ok .n-ico::before{content:"";position:absolute;left:50%;top:50%;width:8px;height:8px;margin:-4px 0 0 -4px;border-radius:50%;background:rgba(22,163,74,.45);animation:n-pulse 2s ease-out infinite}
.n-live .l0{fill:currentColor;stroke:none;transform-origin:12px 12px;animation:n-dot 2s ease-in-out infinite}
.n-live .l1,.n-live .l2{opacity:.15;animation:n-ripple 2s ease-out infinite}
.n-live .l2{animation-delay:.25s}
@keyframes n-ripple{0%{opacity:.15}20%{opacity:1}55%,100%{opacity:.15}}
@keyframes n-dot{0%,100%{transform:scale(1)}15%{transform:scale(1.25)}}
@keyframes n-pulse{0%{transform:scale(1);opacity:.8}70%,100%{transform:scale(3.4);opacity:0}}
/* sync */
.net.n-sync{border-color:#fde68a;background:#fffbeb;color:#92400e}.net.n-sync .n-ico{color:#d97706}
.n-spin{animation:n-rot 1s linear infinite;transform-origin:12px 12px}
@keyframes n-rot{to{transform:rotate(360deg)}}
/* retry: waves light up one after another */
.net.n-retry{border-color:#fde68a;background:#fffbeb;color:#92400e}.net.n-retry .n-ico{color:#d97706}
.n-wifi .w1,.n-wifi .w2,.n-wifi .w3{opacity:.18;animation:n-wave 1.6s cubic-bezier(.4,0,.2,1) infinite}
.n-wifi .w2{animation-delay:.18s}.n-wifi .w3{animation-delay:.36s}.n-wifi .w0{fill:currentColor;stroke:none}
@keyframes n-wave{0%,100%{opacity:.18}30%,55%{opacity:1}}
/* off: waves dim, the slash is drawn, then the icon breathes */
.net.n-off{border-color:#fca5a5;background:#fef2f2;color:#991b1b}.net.n-off .n-ico{color:#dc2626;animation:n-in .35s cubic-bezier(.2,.9,.3,1.3),n-breath 1.6s .6s ease-in-out infinite}
.n-cut .w1,.n-cut .w2,.n-cut .w3{animation:none;opacity:.35}
.n-slash{stroke-width:2.6;stroke-dasharray:25;stroke-dashoffset:25;animation:n-draw .35s .15s ease-out forwards}
@keyframes n-breath{50%{opacity:.45}}
/* logged out */
.net.n-login{border-color:#fca5a5;background:#fef2f2;color:#991b1b}.net.n-login .n-ico{color:#dc2626}
.n-lock{animation:n-shake .6s .2s ease-in-out 2;transform-origin:12px 20px}.n-lock circle{fill:currentColor;stroke:none}
.n-shackle{animation:n-open 2.4s 1.2s ease-in-out infinite;transform-origin:16px 10.5px}
@keyframes n-shake{20%,60%{transform:rotate(-10deg)}40%,80%{transform:rotate(10deg)}}
@keyframes n-open{0%,70%,100%{transform:none}80%,90%{transform:translateY(-1.6px)}}
@media (prefers-reduced-motion:reduce){.net *,.net *::before{animation:none!important;stroke-dashoffset:0!important}}
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
.fee{display:inline-flex;white-space:nowrap;flex:none;align-items:center;gap:1cqw;padding:1cqw 2.4cqw;border-radius:99px;font-size:2.7cqw;font-weight:800;letter-spacing:.04em}
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
/* getting ready: % ring, bar and the steps; fades when ready */
.ld{position:fixed;inset:0;z-index:20;padding-top:70px;display:flex;align-items:center;justify-content:center;padding:20px;background:rgba(246,244,250,.96);backdrop-filter:blur(3px);transition:opacity .45s}
.ld.gone{opacity:0;pointer-events:none}
.ld-c{width:min(420px,100%);text-align:center}
.ld-ring{position:relative;width:132px;height:132px;margin:0 auto 14px}
.ld-ring svg{width:100%;height:100%;transform:rotate(-90deg)}
.ld-bg{fill:none;stroke:#e5e0ee;stroke-width:8}
.ld-r{fill:none;stroke:var(--p);stroke-width:8;stroke-linecap:round;stroke-dasharray:301.6;stroke-dashoffset:301.6;transition:stroke-dashoffset .3s ease,stroke .3s}
.ld-ok{fill:none;stroke:var(--ok);stroke-width:8;stroke-linecap:round;stroke-linejoin:round;stroke-dasharray:60;stroke-dashoffset:60;transform:rotate(90deg);transform-origin:55px 55px}
.ld-ring b{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:34px;font-weight:800;color:var(--p);font-variant-numeric:tabular-nums;transition:opacity .2s}
.ld-ring b small{font-size:16px;margin-left:2px;color:#7c6f93}
.ld.ready .ld-r{stroke:var(--ok)}.ld.ready .ld-ring b{opacity:0}.ld.ready .ld-ok{animation:draw .4s .1s ease-out forwards}
.ld-h{font-size:19px;font-weight:800;color:var(--p);margin-bottom:12px}.ld.ready .ld-h{color:var(--ok)}
.ld-bar{height:6px;background:#e5e0ee;border-radius:99px;overflow:hidden;margin:0 0 18px}
.ld-bar i{display:block;height:100%;width:0;background:linear-gradient(90deg,var(--p),var(--p2));border-radius:99px;transition:width .3s ease}
.ld.ready .ld-bar i{background:var(--ok)}
.ld-s{list-style:none;margin:0;padding:0;text-align:left;display:grid;gap:10px}
.ld-s li{display:flex;gap:10px;align-items:flex-start;color:#9ca3af;font-size:14px;font-weight:600}
.ld-s li small{display:block;font-weight:500;font-size:12.5px;color:#6b7280;margin-top:1px}
.ld-s li span{flex:none;width:20px;height:20px;border-radius:50%;border:2px solid #d1d5db;margin-top:1px;position:relative}
.ld-s li.on{color:#111827}.ld-s li.on span{border-color:var(--p);border-top-color:transparent;animation:spin .8s linear infinite}
.ld-s li.ok{color:#374151}.ld-s li.ok span{border-color:var(--ok);background:var(--ok)}
.ld-s li.ok span::after{content:"";position:absolute;left:5px;top:1px;width:5px;height:10px;border:solid #fff;border-width:0 2px 2px 0;transform:rotate(45deg)}
@keyframes spin{to{transform:rotate(360deg)}}
@media (prefers-reduced-motion:reduce){.ld-s li.on span{animation:none}}
<?php if ($embed): ?>.top .nt{display:none}<?php endif; ?>
@media (max-width:600px){.top{gap:8px;padding:10px 12px}.top b{font-size:14px}.top .clk{display:none}.net span{display:none}.net{padding:0 6px}.top a{padding:0 8px}}
</style>
</head>
<body>
<div class="top"><?php if ($logo): ?><img src="<?= ci_e($logo) ?>" alt=""><?php endif; ?><b><?= ci_e($inst['name']) ?></b><span class="sp"></span>
  <div class="net" id="net" role="status" aria-live="polite"><i class="n-ico"></i><span><em>Status:</em> Connecting</span></div>
  <div class="clk"><span id="clk"></span><small><?= date('l, d M Y') ?></small></div>
  <?php if (!$embed): ?><a href="/admin/ci-attendance.php">← Attendance</a><?php endif; ?></div>
<div class="inp"><form id="f" autocomplete="off"><input id="code" inputmode="text" placeholder="Scan card / admission ID" aria-label="Admission ID" autofocus><button type="submit">Mark</button></form></div>
<div class="stage"><div class="st idle" id="st">Ready — scan an ID card</div><div id="box"></div></div>
<div class="ld" id="ld" role="status" aria-live="polite"><div class="ld-c">
  <div class="ld-ring"><svg viewBox="0 0 110 110" aria-hidden="true"><circle class="ld-bg" cx="55" cy="55" r="48"/><circle class="ld-r" cx="55" cy="55" r="48"/><path class="ld-ok" d="M36 56l13 13 25-27"/></svg><b><span class="ld-n">0</span><small>%</small></b></div>
  <div class="ld-h">Getting the scan screen ready…</div>
  <div class="ld-bar"><i></i></div>
  <ol class="ld-s"><li data-s="1"><span></span><div>Connecting to the server<small></small></div></li><li data-s="2"><span></span><div>Students' list<small></small></div></li><li data-s="3"><span></span><div>Scans waiting from before<small></small></div></li></ol>
</div></div>
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
    var rows = [['Admission ID', s.reg], ['Class', s['class'] || '—'], ['Batch', s.batches || '—'], ["Father's Name", s.father || '—']]; // no mobile number on the scan screen's card
    var fee = s.due > 0 ? '<span class="fee due">DUE · ' + esc(s.due_text) + '</span>' : '<span class="fee paid">✓ FULL PAID</span>';
    var tm = (s['in'] ? 'In <b>' + esc(s['in']) + '</b>' : '') + (s.out ? ' · Out <b>' + esc(s.out) + '</b>' : '');
    box.innerHTML = '<div class="card pop"><div class="c-h">' + (LOGO ? '<img src="' + esc(LOGO) + '" alt="">' : '') + '<div><b>' + esc(INST.name) + '</b>' + (INST.address ? '<small>' + esc(INST.address) + '</small>' : '') + '</div></div>'
      + '<div class="c-t">STUDENT IDENTITY CARD</div>'
      + '<div class="c-b"><div class="c-ph">' + photo(s) + '</div><div class="c-d"><div class="c-nm">' + esc(s.name) + '</div><table>'
      + rows.map(function (r) { return '<tr><th>' + r[0] + '</th><td>' + esc(r[1]) + '</td></tr>'; }).join('') + '</table></div></div>'
      + '<div class="c-f">' + fee + '<span class="tm">' + (k === 'wait' || k === 'done' ? esc(d.msg) : tm) + '</span></div><div class="c-bar"></div>'
      + '<div class="mk ' + k + '" title="' + esc(d.msg) + '"><svg viewBox="0 0 54 54"><circle cx="27" cy="27" r="23.5"/><path d="' + MARK[k] + '"/></svg></div></div>';
  }
  // only the students who just became present (check-in) — not check-outs, repeat scans or errors; one row, slides once
  function addRecent(d, k) {
    var s = d.student; if (d.kind !== 'in' || !s) return;
    var el = document.createElement('div'); el.className = 'new'; el.title = d.msg || ''; el.setAttribute('data-k', k || '');
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
  // the connection badge: one animated icon per state, redrawn only when the state changes (so the animation is not restarted)
  var ICO = {
    ok: '<svg viewBox="0 0 24 24" class="n-live"><circle class="l0" cx="12" cy="12" r="2.6"/><path class="l1" d="M8.2 8.2a5.4 5.4 0 0 0 0 7.6M15.8 8.2a5.4 5.4 0 0 1 0 7.6"/><path class="l2" d="M5.1 5.1a9.8 9.8 0 0 0 0 13.8M18.9 5.1a9.8 9.8 0 0 1 0 13.8"/></svg>',
    sync: '<svg viewBox="0 0 24 24" class="n-spin"><path d="M20 12a8 8 0 0 1-13.7 5.6"/><path d="M4 12a8 8 0 0 1 13.7-5.6"/><path d="M17.7 2.8v3.8h-3.8"/><path d="M6.3 21.2v-3.8h3.8"/></svg>',
    retry: '<svg viewBox="0 0 24 24" class="n-wifi"><path class="w3" d="M2.5 9a14 14 0 0 1 19 0"/><path class="w2" d="M5.5 12.4a9.5 9.5 0 0 1 13 0"/><path class="w1" d="M8.6 15.8a5 5 0 0 1 6.8 0"/><circle class="w0" cx="12" cy="19.2" r="1.3"/></svg>',
    off: '<svg viewBox="0 0 24 24" class="n-wifi n-cut"><path class="w3" d="M2.5 9a14 14 0 0 1 19 0"/><path class="w2" d="M5.5 12.4a9.5 9.5 0 0 1 13 0"/><path class="w1" d="M8.6 15.8a5 5 0 0 1 6.8 0"/><circle class="w0" cx="12" cy="19.2" r="1.3"/><path class="n-slash" d="M3.5 3.5l17 17"/></svg>',
    login: '<svg viewBox="0 0 24 24" class="n-lock"><rect x="5" y="10.5" width="14" height="10" rx="2"/><path class="n-shackle" d="M8 10.5V7.5a4 4 0 0 1 8 0v3"/><circle cx="12" cy="15.5" r="1.2"/></svg>'
  };
  var netKey = '';
  function netShow() {
    var wait = Q.length, k = loggedOut ? 'login' : (!navigator.onLine || fails >= 3) ? 'off' : fails > 0 ? 'retry' : (wait || busy) ? 'sync' : 'ok';
    // professional wording: "Status: Live" and so on; the count is the scans kept on this computer, not yet on the server
    var txt = '<em>Status:</em> ' + (k === 'login' ? 'Session expired — <a href="/admin-login" target="_blank" rel="noopener">Sign in</a>' + (wait ? ' · ' + wait + ' queued' : '')
      : k === 'off' ? 'Offline' + (wait ? ' · ' + wait + ' queued' : '')
      : k === 'retry' ? 'Reconnecting' + (wait ? ' · ' + wait + ' queued' : '')
      : k === 'sync' ? 'Syncing · ' + Math.max(wait, 1) : 'Live');
    if (k !== netKey) { netKey = k; net.className = 'net n-' + k; net.innerHTML = '<i class="n-ico">' + ICO[k] + '</i><span></span>'; }
    net.querySelector('span').innerHTML = txt;
    net.title = k === 'ok' ? 'Live — connected, every scan is saved on the server' : k === 'sync' ? 'Syncing — sending scans to the server' : k === 'retry' ? 'Reconnecting — the server did not answer, trying again' : k === 'off' ? 'Offline — scans are kept on this computer and synced automatically when the connection is back' : 'Session expired — sign in again in another tab; queued scans sync by themselves';
  }
  // ---- instant: the students' list is kept in the browser; a scan is decided and shown at once, then sent in the queue ----
  var RK = 'ciScanRoster', SK = 'ciScanState', R = { min: 10, by: {} }, S = { day: DAY, st: {} }, srvOff = 0;
  function norm(c) { c = String(c).trim().toUpperCase(); return /^\d{1,5}$/.test(c) ? ('000000' + c).slice(-6) : c; }
  function hm(ms) { var d = new Date(ms), h = d.getHours(), m = d.getMinutes(); return ('0' + (h % 12 || 12)).slice(-2) + ':' + ('0' + m).slice(-2) + (h < 12 ? ' AM' : ' PM'); }
  try { var r0 = JSON.parse(localStorage.getItem(RK) || 'null'); if (r0 && r0.by) R = r0; } catch (x) {} // kept from day to day
  try { var s0 = JSON.parse(localStorage.getItem(SK) || 'null'); if (s0 && s0.day === DAY) S = s0; } catch (x) {}
  function saveS() { try { localStorage.setItem(SK, JSON.stringify(S)); } catch (x) {} }
  var loading = null;
  // photos: in the background, 4 at a time, paused while scans are being sent — they never slow the scanning
  var PH = [], phBusy = 0;
  function photoPump() {
    while (phBusy < 4 && PH.length && !busy) {
      phBusy++; var im = new Image(), u = PH.shift();
      im.onload = im.onerror = function () { phBusy--; setTimeout(photoPump, 30); };
      im.src = u;
    }
    if (PH.length && busy) setTimeout(photoPump, 500);
  }
  // the students' list; prog(0…1) while it downloads (counted byte by byte against the size it had last time)
  function loadRoster(prog, fresh) { // fresh: the whole list and all of today's in / out (every time the screen is opened)
    if (loading) return loading;
    var u = '/admin/ci-scan.php?roster=1&v=' + (fresh ? '' : encodeURIComponent(R.v || '')) + '&since=' + (!fresh && R.day === DAY && R.since ? R.since : 0) + '&_=' + Date.now();
    var ctl = window.AbortController ? new AbortController() : null, to = setTimeout(function () { if (ctl) ctl.abort(); }, 30000);
    loading = fetch(u, { credentials: 'same-origin', cache: 'no-store', headers: { 'X-Requested-With': 'fetch' }, signal: ctl ? ctl.signal : undefined })
      .then(function (r) {
        if (r.redirected && /admin-login/.test(r.url)) throw { login: 1 };
        if (!r.ok) throw { http: r.status };
        if (!prog || !r.body || !r.body.getReader || !window.TextDecoder) return r.text();
        var rd = r.body.getReader(), parts = [], got = 0, exp = R.bytes || 0;
        return (function next() {
          return rd.read().then(function (x) {
            if (x.done) { var all = new Uint8Array(got), o = 0; parts.forEach(function (p) { all.set(p, o); o += p.length; }); return new TextDecoder().decode(all); }
            parts.push(x.value); got += x.value.length;
            prog(exp ? Math.min(.98, got / exp) : 1 - Math.exp(-got / 150000)); // size unknown (first time): eases towards 100 %
            return next();
          });
        })();
      })
      .then(function (txt) {
        clearTimeout(to); loading = null;
        var d = JSON.parse(txt);
        if (!d || !d.ok) return false;
        if (d.day !== DAY) { if (!Q.length) location.reload(); return false; }
        srvOff = d.t * 1000 - Date.now(); // the server's clock, for check-out times
        if (d.list) { // the details changed: new list; photos only for new / changed students
          var by = {}; d.list.forEach(function (x) { var k = norm(x.reg), old = R.by[k]; by[k] = x; if (x.photo && (!old || old.photo !== x.photo || !R.v)) PH.push(x.photo); });
          R.by = by; R.v = d.v; R.n = d.n; R.bytes = txt.length; photoPump();
        }
        R.min = d.min; R.day = d.day; R.since = d.t; fails = 0; lastOk = Date.now();
        // what the server already has wins; a scan still waiting in the queue stays
        (d.att || []).forEach(function (a) { var k = norm(a[0]), m = S.st[k] || (S.st[k] = {}); if (a[1]) m['in'] = a[1] * 1000; if (a[2]) m.out = a[2] * 1000; });
        saveS(); try { localStorage.setItem(RK, JSON.stringify(R)); } catch (x) {}
        return true;
      })
      .catch(function (e) { clearTimeout(to); loading = null; if (e && e.login) { loggedOut = true; netShow(); } return false; });
    return loading;
  }
  function local(k, s, now) { // the same rules as the server: 1st scan in; again after the set minutes out; else wait / done
    var m = S.st[k] || (S.st[k] = {}), card = { reg: s.reg, name: s.name, 'class': s['class'], batches: s.batches, father: s.father, photo: s.photo, due: s.due, due_text: s.due_text };
    var d = { ok: false, kind: 'done', time: hm(now), student: card };
    if (s.hol && R.day === DAY) { d.kind = 'error'; d.msg = 'Today is a holiday for ' + s.name + "'s batch."; return d; }
    if (!m['in']) { m['in'] = now; d.ok = true; d.kind = 'in'; d.msg = 'Checked in at ' + hm(now); }
    else if (!m.out) {
      var open = m['in'] + R.min * 60000;
      if (now >= open) { m.out = now; d.ok = true; d.kind = 'out'; d.msg = 'Checked out at ' + hm(now); }
      else { d.kind = 'wait'; d.msg = 'Already checked in at ' + hm(m['in']) + '. Check-out opens at ' + hm(open) + ' (after ' + R.min + ' min).'; }
    } else d.msg = 'Already checked out at ' + hm(m.out) + '.';
    card['in'] = m['in'] ? hm(m['in']) : ''; card.out = m.out ? hm(m.out) : '';
    saveS(); return d;
  }
  function add(c) {
    if (window.__ldSkip) window.__ldSkip();
    var k = norm(c), now = Date.now();
    if (recent[k] && now - recent[k] < 4000) return; // the scanner read the same card twice
    recent[k] = now;
    var it = { id: now + '-' + Math.random().toString(36).slice(2, 7), code: c, at: now, day: DAY, seq: ++seq, tries: 0 }, s = R.by[k];
    if (s) {
      var d = local(k, s, now + srvOff);
      it.local = d.kind; it.name = s.name;
      beep(d.ok || d.kind === 'wait'); shown = it.seq; show(d, c); // the slider below gets the name once the server has saved it
      clearTimeout(idleT); idleT = setTimeout(idle, 9000);
      if (d.kind !== 'in' && d.kind !== 'out') return; // nothing to save
    } else { st.className = 'st idle'; st.textContent = 'Checking ' + c + '…'; }
    Q.push(it); save(); pump();
  }
  function pump() {
    netShow();
    if (loggedOut) return;
    Q.forEach(function (it) { if (busy < MAX && !it.going && (!it.next || it.next <= Date.now())) go(it); });
  }
  function done(it) { Q = Q.filter(function (x) { return x !== it; }); save(); if (!Q.length && PH.length) setTimeout(photoPump, 300); }
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
    if (EMBED && window.parent !== window) try { window.parent.postMessage({ ciScan: d }, location.origin); } catch (x) {}
    if (it.local) { // already shown: the slider gets the name now that it is saved; a refusal (e.g. a holiday added a minute ago) is shown
      if (d.kind === 'in') addRecent(d, norm(it.code));
      if (d.kind === 'error') {
        var k = norm(it.code), m = S.st[k]; if (m) { if (it.local === 'in') delete m['in']; if (it.local === 'out') delete m.out; saveS(); }
        beep(false); st.className = 'st error'; st.textContent = '⚠ Not saved — ' + (it.name || it.code) + ': ' + (d.msg || 'error');
        clearTimeout(idleT); idleT = setTimeout(idle, 9000);
      }
      return;
    }
    if (d.student) { var k2 = norm(d.student.reg); if (!R.by[k2]) loadRoster(); } // a new student: get the list again
    beep(d.ok); addRecent(d, d.student ? norm(d.student.reg) : '');
    if (it.seq < shown) return; shown = it.seq; show(d, it.code);
    clearTimeout(idleT); idleT = setTimeout(idle, 9000);
  }
  // ---- keep-alive: every 20 s (and when the screen / internet comes back) ----
  var pinging = false, reT = null;
  function refreshToken(force) {
    if (pinging && !force) return Promise.resolve(false); pinging = true;
    var ctl = window.AbortController ? new AbortController() : null, to = setTimeout(function () { if (ctl) ctl.abort(); }, 10000);
    return fetch('/admin/ci-scan.php?ping=1&_=' + Date.now(), { credentials: 'same-origin', cache: 'no-store', headers: { 'X-Requested-With': 'fetch' }, signal: ctl ? ctl.signal : undefined })
      .then(function (r) { if (r.redirected && /admin-login/.test(r.url)) throw { login: 1 }; return r.json(); })
      .then(function (d) {
        clearTimeout(to); pinging = false; if (!d || !d.ok) return false;
        fails = 0; lastOk = Date.now(); CSRF = d.csrf;
        if (loggedOut) loggedOut = false; // logged in again in another tab: send what is waiting
        if (d.day !== DAY && !Q.length) { location.reload(); return false; } // a new day: fresh page
        pump(); netShow(); return true;
      })
      .catch(function (e) {
        clearTimeout(to); pinging = false;
        if (e && e.login) loggedOut = true;
        else { fails++; clearTimeout(reT); reT = setTimeout(function () { refreshToken(true); }, fails < 3 ? 3000 : 8000); } // one miss is not "offline": check again soon
        netShow(); return false;
      });
  }
  setInterval(refreshToken, 20000);
  // watchdog: a tab open for hours gets its timers slowed by the browser — if nothing worked for 45 s, check right away
  setInterval(function () { if (!pinging && Date.now() - lastOk > 45000) refreshToken(true); }, 5000);
  setInterval(function () { loadRoster(); }, 60000); // the students' list every minute ("same" when nothing changed)
  setInterval(function () { if (Q.length) pump(); }, 5000);
  window.addEventListener('online', function () { fails = 0; Q.forEach(function (x) { x.next = 0; }); refreshToken(true); pump(); });
  window.addEventListener('offline', function () { netShow(); });
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
  idle(); netShow();
  // ---- getting ready: connection → students' list → scans left waiting from before → Ready (photos keep coming in the background) ----
  var ld = document.getElementById('ld'), ldP = 0, ldOn = true;
  function ldSet(p, step, txt) {
    if (!ldOn) return;
    ldP = Math.max(ldP, Math.min(100, Math.round(p)));
    ld.querySelector('.ld-n').textContent = ldP;
    ld.querySelector('.ld-r').style.strokeDashoffset = String(301.6 * (1 - ldP / 100));
    ld.querySelector('.ld-bar i').style.width = ldP + '%';
    if (step) [].forEach.call(ld.querySelectorAll('.ld-s li'), function (li) {
      var n = +li.getAttribute('data-s'); li.className = n < step ? 'ok' : n === step ? 'on' : '';
      if (n === step && txt) li.querySelector('small').textContent = txt;
    });
  }
  function ldTxt(step, txt) { var li = ld.querySelector('.ld-s li[data-s="' + step + '"] small'); if (li) li.textContent = txt; }
  function ldDone(msg) {
    if (!ldOn) return;
    ldSet(100, 9); ld.querySelector('.ld-h').textContent = msg || 'Ready — scan an ID card'; ld.classList.add('ready');
    ldOn = false; setTimeout(function () { ld.classList.add('gone'); setTimeout(function () { ld.hidden = true; }, 450); code.focus(); }, 650);
  }
  window.__ldSkip = function () { if (ldOn) { ldOn = false; ld.classList.add('gone'); setTimeout(function () { ld.hidden = true; }, 450); } }; // a card scanned while loading: get out of the way
  var cached = R.v && R.by && Object.keys(R.by).length;
  ldSet(4, 1, 'Checking the internet and your login…');
  refreshToken(true).then(function (online) {
    if (!online) {
      if (loggedOut) { ldSet(15, 1, 'Logged out — log in again in another tab, this screen carries on by itself'); return; }
      if (cached) { ldDone('Status: Offline — using the saved list of ' + Object.keys(R.by).length + ' students'); return; }
      ldDone('Offline — cards show when the internet is back'); return;
    }
    ldTxt(1, 'Online · logged in');
    ldSet(15, 2, 'Downloading the latest list of students…');
    return loadRoster(function (f) { ldSet(15 + f * 65, 2); }, true).then(function (ok) {
      var n = Object.keys(R.by).length;
      ldTxt(2, ok ? 'Up to date · ' + n + ' students' : 'Could not load — using the saved list of ' + n);
      var left = Q.length;
      ldSet(80, 3, left ? 'Saving ' + left + '…' : 'None waiting');
      if (!left) return true;
      Q.forEach(function (x) { x.seq = ++seq; x.tries = 0; x.next = 0; }); pump();
      return new Promise(function (res) {
        var t0 = Date.now(), iv = setInterval(function () {
          ldSet(80 + 15 * (1 - Q.length / left), 3, 'Saving ' + Q.length + ' scan' + (Q.length === 1 ? '' : 's') + ' left from before…');
          if (!Q.length || Date.now() - t0 > 10000) { clearInterval(iv); ldTxt(3, Q.length ? (left - Q.length) + ' saved · ' + Q.length + ' still sending in the background' : 'All ' + left + ' saved'); res(true); } // a slow server: the queue carries on behind
        }, 150);
      });
    }).then(function () { ldDone(); });
  });
})();
</script>
</body>
</html>
