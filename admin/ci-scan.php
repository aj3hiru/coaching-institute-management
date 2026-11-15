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
$me = ci_page_start($pdo, ['attendance', 'att_scan'], $ping || $roster); // full attendance or "Scan screen only"
$canAtt = ci_perm($me, 'attendance');
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
.net span{display:inline-block;animation:n-txt .3s ease-out} /* slide only — the text is never see-through, even if the browser pauses animations */
@keyframes n-txt{from{transform:translateX(-5px)}to{transform:none}}.net span em{font-style:normal;font-weight:500;opacity:.75;margin-right:1px}
.net a{height:auto;padding:0;border:0;background:none;color:inherit;text-decoration:underline}
@keyframes n-in{from{transform:scale(.4);opacity:0}to{transform:none;opacity:1}}
/* ok */
.net.n-ok{border-color:#bbf7d0;background:#f0fdf4;color:#166534}
.net.n-ok .n-ico{color:#16a34a}
.net.n-ok .n-ico::before{content:"";position:absolute;left:50%;top:50%;width:8px;height:8px;margin:-4px 0 0 -4px;border-radius:50%;background:rgba(22,163,74,.45);animation:n-pulse 2s ease-out infinite}
.n-live .l0{fill:currentColor;stroke:none;transform-origin:12px 12px;animation:n-dot 2s ease-in-out infinite}
.n-live .l1,.n-live .l2{opacity:.45;animation:n-ripple 2s ease-out infinite}
.n-live .l2{animation-delay:.25s}
@keyframes n-ripple{0%{opacity:.45}20%{opacity:1}55%,100%{opacity:.45}}
@keyframes n-dot{0%,100%{transform:scale(1)}15%{transform:scale(1.25)}}
@keyframes n-pulse{0%{transform:scale(1);opacity:.8}70%,100%{transform:scale(3.4);opacity:0}}
/* sync */
.net.n-sync{border-color:#fde68a;background:#fffbeb;color:#92400e}.net.n-sync .n-ico{color:#d97706}
.n-spin{animation:n-rot 1s linear infinite;transform-origin:12px 12px}
@keyframes n-rot{to{transform:rotate(360deg)}}
/* retry: waves light up one after another */
.net.n-retry{border-color:#fde68a;background:#fffbeb;color:#92400e}.net.n-retry .n-ico{color:#d97706}
.n-wifi .w1,.n-wifi .w2,.n-wifi .w3{opacity:.35;animation:n-wave 1.6s cubic-bezier(.4,0,.2,1) infinite}
.n-wifi .w2{animation-delay:.18s}.n-wifi .w3{animation-delay:.36s}.n-wifi .w0{fill:currentColor;stroke:none}
@keyframes n-wave{0%,100%{opacity:.35}30%,55%{opacity:1}}
/* off: waves dim, the slash is drawn, then the icon breathes */
.net.n-off{border-color:#fca5a5;background:#fef2f2;color:#991b1b}.net.n-off .n-ico{color:#dc2626;animation:n-in .35s cubic-bezier(.2,.9,.3,1.3),n-breath 1.6s .6s ease-in-out infinite}
.n-cut .w1,.n-cut .w2,.n-cut .w3{animation:none;opacity:.35}
.n-slash{stroke-width:2.6;transform-origin:12px 12px;animation:n-slash .4s .1s cubic-bezier(.2,.9,.3,1.4) backwards} /* always drawn; pops in */
@keyframes n-slash{from{transform:scale(.2) rotate(-25deg)}to{transform:none}}
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
/* "Scan your ID card" (waiting): white card with scanner corners; a soft rise when it shows, then radar rings round the
   icon and a scanning line — calm, always on while waiting; never on the student's card */
.ph-idle{position:relative;border:1px solid #ebe7f3;background:#fff;box-shadow:0 20px 50px rgba(41,24,66,.10),0 2px 6px rgba(0,0,0,.04);gap:10px;animation:ph-in .5s cubic-bezier(.2,.8,.2,1) both}
.ph-fr{position:absolute;inset:22px;pointer-events:none;--c:#c4b5fd;
  background:linear-gradient(var(--c),var(--c)) 0 0/34px 3px,linear-gradient(var(--c),var(--c)) 0 0/3px 34px,
  linear-gradient(var(--c),var(--c)) 100% 0/34px 3px,linear-gradient(var(--c),var(--c)) 100% 0/3px 34px,
  linear-gradient(var(--c),var(--c)) 0 100%/34px 3px,linear-gradient(var(--c),var(--c)) 0 100%/3px 34px,
  linear-gradient(var(--c),var(--c)) 100% 100%/34px 3px,linear-gradient(var(--c),var(--c)) 100% 100%/3px 34px;background-repeat:no-repeat;
  animation:ph-fr 2.4s ease-in-out infinite}
.ph-ic{position:relative;display:flex;align-items:center;justify-content:center;width:96px;height:96px;margin:-30px 0 40px;border-radius:50%;background:#f5f3ff;color:var(--p)}
.ph-ic::before,.ph-ic::after{content:"";position:absolute;inset:0;border-radius:50%;border:2px solid rgba(124,58,237,.35);animation:ph-ring 2.4s cubic-bezier(.2,.6,.3,1) infinite}
.ph-ic::after{animation-delay:1.2s}
.ph-idle .ph-ic svg{width:50px;height:50px}
.ph-laser{position:absolute;left:26px;right:26px;top:32px;height:2px;border-radius:2px;background:#ef4444;box-shadow:0 0 6px 1px rgba(239,68,68,.6);animation:ph-laser 1.4s ease-in-out infinite alternate}
.ph-idle b{color:var(--p);letter-spacing:.01em}
.ph-idle span:not(.ph-ic){color:#6b7280;font-size:15px}
@keyframes ph-in{from{opacity:0;transform:translateY(10px) scale(.985)}to{opacity:1;transform:none}}
@keyframes ph-fr{0%,100%{inset:22px;opacity:.9}50%{inset:16px;opacity:1}}
@keyframes ph-ring{0%{transform:scale(1);opacity:.9}100%{transform:scale(1.75);opacity:0}}
@keyframes ph-laser{from{top:30px}to{top:64px}}
@media (prefers-reduced-motion:reduce){.ph-idle,.ph-fr,.ph-ic::before,.ph-ic::after,.ph-laser{animation:none}}
/* fee due on the card: a splash — a band of light sweeps across the chip again and again (no ripple) */
.fee.due{position:relative;overflow:hidden;isolation:isolate;background:#fee2e2;color:#991b1b;box-shadow:inset 0 0 0 .25cqw #fecaca;animation:due-in .45s .3s ease-out both}
.fee.due::after{content:"";position:absolute;top:0;bottom:0;left:-60%;width:45%;z-index:-1;background:linear-gradient(100deg,rgba(255,255,255,0),rgba(255,255,255,.95) 50%,rgba(255,255,255,0));transform:skewX(-20deg);animation:due-shine 2.4s .7s ease-in-out infinite}
@keyframes due-in{from{opacity:0;transform:translateY(.8cqw)}to{opacity:1;transform:none}}
@keyframes due-shine{0%{left:-60%}45%,100%{left:130%}}
@media (prefers-reduced-motion:reduce){.fee.due,.fee.due::after{animation:none}}
/* preview while an admission ID is typed by hand: the card faded, nothing marked yet */
.card.pv{opacity:.45;filter:saturate(.6);animation:pvin .25s ease-out}
@keyframes pvin{from{opacity:0}to{opacity:.45}}
.st.pv{color:#6b7280;font-weight:700}
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
.ld[hidden]{display:none} /* really gone once ready (its blur would keep the graphics card busy behind the whole screen) */
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
/* side column: who is checked in now (not checked out yet), in the order they came — check out one by one or all */
.main{flex:1;display:flex;min-height:0}
.main .stage{flex:1;min-width:0}
.side{width:340px;flex:none;display:flex;flex-direction:column;min-height:0;background:#fff;border-left:1px solid #e5e7eb}
.sd-h{display:flex;align-items:center;gap:8px;padding:12px 14px;border-bottom:1px solid #e5e7eb}
.sd-h b{font-size:15px;color:var(--p)}
.sd-h .sd-n{min-width:26px;height:22px;padding:0 7px;border-radius:5px;background:#dcfce7;color:#166534;font-size:13px;font-weight:800;display:inline-flex;align-items:center;justify-content:center;font-variant-numeric:tabular-nums}
.sd-all{margin-left:auto;height:32px;padding:0 12px;border:0;border-radius:5px;background:var(--out);color:#fff;font:inherit;font-size:13px;font-weight:700;cursor:pointer;white-space:nowrap}
.sd-all.sure{background:var(--bad)}.sd-all:disabled{opacity:.45;cursor:not-allowed}
.sd-q{padding:8px 14px;border-bottom:1px solid #f1f5f9}
.sd-q input{width:100%;height:34px;padding:0 10px;border:1px solid #d1d5db;border-radius:5px;font:inherit;font-size:13px}
.sd-q input:focus{outline:none;border-color:var(--p)}
.sd-l{flex:1;overflow:auto;list-style:none;margin:0;padding:0;min-height:0}
.sd-l li{display:flex;align-items:center;gap:10px;padding:7px 14px;border-bottom:1px solid #f1f5f9}
.sd-l li.new{animation:sdnew 2s ease-out}
@keyframes sdnew{from{background:#dcfce7}to{background:#fff}}
.sd-l .no{flex:none;min-width:24px;text-align:right;color:#6b7280;font-size:13px;font-weight:700;font-variant-numeric:tabular-nums}
.sd-l li img,.sd-l li svg{width:28px;height:36px;object-fit:cover;flex:none}
.sd-l .nm{flex:1;min-width:0;line-height:1.25}
.sd-l .nm b{display:block;font-size:13.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sd-l .nm small{display:block;font-size:12px;color:#6b7280;white-space:nowrap}
.sd-l button{flex:none;height:30px;padding:0 10px;border:1px solid #93c5fd;border-radius:5px;background:#fff;color:#1d4ed8;font:inherit;font-size:12px;font-weight:700;cursor:pointer}
.sd-l button:hover{background:#eff6ff}.sd-l button:disabled{opacity:.5;cursor:wait}
.sd-e{padding:28px 16px;text-align:center;color:#9ca3af;font-size:13px}
@media (max-width:900px){.main{flex-direction:column}.side{width:auto;border-left:0;border-top:1px solid #e5e7eb;max-height:45vh}}
/* display options: an icon in the header; what is hidden is kept in this browser */
.dso{position:relative}
.dso-b{display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;padding:0;border:1px solid #d1d5db;border-radius:5px;background:#fff;color:#374151;cursor:pointer}
.dso-b:hover,.dso-b[aria-expanded=true]{border-color:var(--p);color:var(--p)}
.dso-b svg{width:18px;height:18px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round}
.dso-p{position:absolute;right:0;top:calc(100% + 6px);z-index:40;width:270px;max-height:min(560px,calc(100vh - 80px));overflow:auto;background:#fff;border:1px solid #e5e7eb;border-radius:5px;box-shadow:0 12px 32px rgba(0,0,0,.14);padding:6px 0;font-size:13px}
.dso-p[hidden]{display:none}
.dso-h{display:flex;align-items:center;justify-content:space-between;padding:6px 12px 8px;border-bottom:1px solid #f1f5f9}
.dso-h b{font-family:inherit;font-size:14px;color:#111827;text-transform:none;letter-spacing:0}.dso-h button{border:0;background:none;color:var(--p);font:inherit;font-weight:700;cursor:pointer;padding:0}
.dso-p label{display:flex;align-items:center;gap:8px;padding:6px 12px;cursor:pointer;color:#111827;font-weight:600}
.dso-p label.sub{padding-left:32px;font-weight:500;color:#374151}
.dso-p label.off{opacity:.45;pointer-events:none}
.dso-p label:hover{background:#f9fafb}
.dso-p input{width:15px;height:15px;margin:0;accent-color:var(--p)}
body.h-brand .top>img,body.h-brand .top>b{display:none}
body.h-net #net{display:none}
body.h-clk .top .clk{display:none}
body.h-inp .inp{position:absolute;left:-9999px;top:0;width:300px;opacity:0;pointer-events:none} /* still takes the scanner's typing */
body.h-st #st{display:none}
body.h-card #box{display:none}
body.h-side .side{display:none}
body.h-rec .rec{display:none}
body.h-c_photo .c-ph{display:none}
body.h-c_reg .c-d tr[data-r=reg],body.h-c_class .c-d tr[data-r=class],body.h-c_batch .c-d tr[data-r=batch],body.h-c_father .c-d tr[data-r=father]{display:none}
body.h-c_fee .c-f .fee{display:none}
body.h-c_time .c-f .tm{display:none}
body.h-s_photo .sd-l li>img,body.h-s_photo .sd-l li>svg{display:none}
body.h-s_find .sd-q{display:none}
body.h-s_all .sd-all{display:none}
<?php if ($embed): ?>.top .nt{display:none}<?php endif; ?>
@media (max-width:600px){.top{gap:8px;padding:10px 12px}.top b{font-size:14px}.top .clk{display:none}.net span{display:none}.net{padding:0 6px}.top a{padding:0 8px}}
</style>
</head>
<body>
<script>try { (JSON.parse(localStorage.getItem('ciScanView') || '[]') || []).forEach(function (k) { document.body.classList.add('h-' + k); }); } catch (x) {}</script>
<div class="top"><?php if ($logo): ?><img src="<?= ci_e($logo) ?>" alt=""><?php endif; ?><b><?= ci_e($inst['name']) ?></b><span class="sp"></span>
  <div class="net" id="net" role="status" aria-live="polite"><i class="n-ico"></i><span><em>Status:</em> Connecting</span></div>
  <div class="clk"><span id="clk"></span><small><?= date('l, d M Y') ?></small></div>
  <div class="dso"><button type="button" class="dso-b" id="dsoB" aria-label="Display options" title="Display options" aria-expanded="false"><svg viewBox="0 0 24 24"><path d="M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12M20 18h0"/><circle cx="16" cy="6" r="2"/><circle cx="10" cy="12" r="2"/><circle cx="18" cy="18" r="2"/></svg></button><div class="dso-p" id="dsoP" hidden></div></div>
  <?php if (!$embed): ?><a href="<?= $canAtt ? '/admin/ci-attendance.php' : '/admin/dashboard.php' ?>">← <?= $canAtt ? 'Attendance' : 'Dashboard' ?></a><?php endif; ?></div>
<div class="inp"><form id="f" autocomplete="off"><input id="code" inputmode="text" placeholder="Scan card / admission ID" aria-label="Admission ID" autofocus><button type="submit">Mark</button></form></div>
<div class="main"><div class="stage"><div class="st idle" id="st">Ready — scan an ID card</div><div id="box"></div></div>
<aside class="side" aria-label="Checked in now"><div class="sd-h"><b>Checked in</b><span class="sd-n" id="sdN">0</span><button type="button" class="sd-all" id="sdAll" disabled>Check out all</button></div>
  <div class="sd-q"><input type="search" id="sdQ" placeholder="Find a name or admission ID" aria-label="Find in checked-in list"></div>
  <ol class="sd-l" id="sdL"></ol></aside></div>
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
  function idle() { st.className = 'st idle'; st.textContent = 'Ready — scan an ID card'; box.innerHTML = '<div class="ph ph-idle"><i class="ph-fr"></i><span class="ph-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 7V5a2 2 0 0 1 2-2h2M17 3h2a2 2 0 0 1 2 2v2M21 17v2a2 2 0 0 1-2 2h-2M7 21H5a2 2 0 0 1-2-2v-2M7 8v8M10 8v8M13 8v8M17 8v8"/></svg><i class="ph-laser"></i></span><b>Scan your ID card</b><span>Your card shows here and the attendance is marked.</span></div>'; }
  function show(d, code) {
    var s = d.student, k = d.kind || 'error';
    st.className = 'st ' + k;
    st.textContent = (k === 'in' ? '✓ ' : k === 'out' ? '✓ ' : '') + HEAD[k] + (s ? ' — ' + s.name : '') + (k === 'in' || k === 'out' ? ' · ' + d.time : '');
    if (!s) { box.innerHTML = '<div class="ph err"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M9 9l6 6M15 9l-6 6"/></svg><b>' + esc(d.msg || 'Not found') + '</b><span>' + esc(code) + '</span></div>'; return; }
    var rows = [['reg', 'Admission ID', s.reg], ['class', 'Class', s['class'] || '—'], ['batch', 'Batch', s.batches || '—'], ['father', "Father's Name", s.father || '—']]; // no mobile number on the scan screen's card
    var fee = s.due > 0 ? '<span class="fee due">DUE · ' + esc(s.due_text) + '</span>' : '<span class="fee paid">✓ FULL PAID</span>';
    var tm = (s['in'] ? 'In <b>' + esc(s['in']) + '</b>' : '') + (s.out ? ' · Out <b>' + esc(s.out) + '</b>' : '');
    box.innerHTML = '<div class="card pop"><div class="c-h">' + (LOGO ? '<img src="' + esc(LOGO) + '" alt="">' : '') + '<div><b>' + esc(INST.name) + '</b>' + (INST.address ? '<small>' + esc(INST.address) + '</small>' : '') + '</div></div>'
      + '<div class="c-t">STUDENT IDENTITY CARD</div>'
      + '<div class="c-b"><div class="c-ph">' + photo(s) + '</div><div class="c-d"><div class="c-nm">' + esc(s.name) + '</div><table>'
      + rows.map(function (r) { return '<tr data-r="' + r[0] + '"><th>' + r[1] + '</th><td>' + esc(r[2]) + '</td></tr>'; }).join('') + '</table></div></div>'
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
  // each open scan screen (popup, tab) has its own queue in the browser, so two open at once never overwrite each other;
  // a queue left by a screen that was closed (no heartbeat for 40 s) is taken over and sent by the one still open
  var TAB = (function () { var t = ''; try { t = sessionStorage.getItem('ciScanTab') || ''; if (!t) { t = Date.now().toString(36) + Math.random().toString(36).slice(2, 6); sessionStorage.setItem('ciScanTab', t); } } catch (x) { t = 'x' + Date.now().toString(36); } return t; })();
  var QK = 'ciScanQ:' + TAB, HK = 'ciScanHB:' + TAB, Q = [], busy = 0, MAX = 6, fails = 0, lastOk = Date.now(), loggedOut = false, retryT = null, recent = {};
  function readQ(k) { try { return (JSON.parse(localStorage.getItem(k) || '[]') || []).filter(function (x) { return x && x.code && x.day === DAY; }); } catch (x) { return []; } }
  Q = readQ(QK);
  function save() { try { if (Q.length) localStorage.setItem(QK, JSON.stringify(Q.map(function (x) { return { id: x.id, code: x.code, at: x.at, day: x.day }; }))); else localStorage.removeItem(QK); } catch (x) {} }
  function beat() { try { localStorage.setItem(HK, String(Date.now())); } catch (x) {} }
  function adopt() { // queues of closed screens (and the old single queue) → this one
    var got = 0, have = {}; Q.forEach(function (x) { have[x.id] = 1; });
    try {
      for (var i = localStorage.length - 1; i >= 0; i--) {
        var k = localStorage.key(i); if (!k) continue;
        var other = k === 'ciScanQ' ? 'old' : (k.indexOf('ciScanQ:') === 0 && k !== QK ? k.slice(8) : '');
        if (!other) { if (k.indexOf('ciScanHB:') === 0 && Date.now() - (+localStorage.getItem(k) || 0) > 86400000) localStorage.removeItem(k); continue; }
        if (other !== 'old' && Date.now() - (+localStorage.getItem('ciScanHB:' + other) || 0) < 40000) continue; // still open
        readQ(k).forEach(function (x) { if (!have[x.id]) { x.seq = ++seq; x.tries = 0; Q.push(x); have[x.id] = 1; got++; } });
        localStorage.removeItem(k); localStorage.removeItem('ciScanHB:' + other);
      }
    } catch (x) {}
    if (got) { save(); pump(); }
  }
  beat(); setInterval(beat, 10000); setTimeout(adopt, 1500); setInterval(adopt, 30000);
  setInterval(function () { var t = Date.now() - 10000; for (var k in recent) if (recent[k] < t) delete recent[k]; }, 60000); // forget old double-read marks
  var net = document.getElementById('net');
  // the connection badge: one animated icon per state, redrawn only when the state changes (so the animation is not restarted)
  var ICO = {
    ok: '<svg viewBox="0 0 24 24" class="n-live"><circle class="l0" cx="12" cy="12" r="2.6"/><path class="l1" d="M8.2 8.2a5.4 5.4 0 0 0 0 7.6M15.8 8.2a5.4 5.4 0 0 1 0 7.6"/><path class="l2" d="M5.1 5.1a9.8 9.8 0 0 0 0 13.8M18.9 5.1a9.8 9.8 0 0 1 0 13.8"/></svg>',
    sync: '<svg viewBox="0 0 24 24" class="n-spin"><path d="M20 12a8 8 0 0 1-13.7 5.6"/><path d="M4 12a8 8 0 0 1 13.7-5.6"/><path d="M17.7 2.8v3.8h-3.8"/><path d="M6.3 21.2v-3.8h3.8"/></svg>',
    retry: '<svg viewBox="0 0 24 24" class="n-wifi"><path class="w3" d="M2.5 9a14 14 0 0 1 19 0"/><path class="w2" d="M5.5 12.4a9.5 9.5 0 0 1 13 0"/><path class="w1" d="M8.6 15.8a5 5 0 0 1 6.8 0"/><circle class="w0" cx="12" cy="19.2" r="1.3"/></svg>',
    off: '<svg viewBox="0 0 24 24" class="n-wifi n-cut"><path class="w3" d="M2.5 9a14 14 0 0 1 19 0"/><path class="w2" d="M5.5 12.4a9.5 9.5 0 0 1 13 0"/><path class="w1" d="M8.6 15.8a5 5 0 0 1 6.8 0"/><circle class="w0" cx="12" cy="19.2" r="1.3"/><path class="n-slash" d="M3.5 3.5l17 17"/></svg>',
    login: '<svg viewBox="0 0 24 24" class="n-lock"><rect x="5" y="10.5" width="14" height="10" rx="2"/><path class="n-shackle" d="M8 10.5V7.5a4 4 0 0 1 8 0v3"/><circle cx="12" cy="15.5" r="1.2"/></svg>'
  };
  var netKey = '', syncSince = 0, holdT = null;
  function netShow() {
    var wait = Q.length, k = loggedOut ? 'login' : (!navigator.onLine || fails >= 3) ? 'off' : fails > 0 ? 'retry' : (wait || busy) ? 'sync' : 'ok';
    // fast scanning sends and finishes many times a second: "Syncing" shows only when sending takes longer than 0.7 s, and
    // stays at least 1 s — so the badge does not blink between Live and Syncing on every scan
    clearTimeout(holdT);
    if (k === 'sync' && netKey === 'ok') { if (!syncSince) syncSince = Date.now(); if (Date.now() - syncSince < 700) { holdT = setTimeout(netShow, 720 - (Date.now() - syncSince)); k = 'ok'; } }
    else if (k !== 'sync') syncSince = 0;
    if (k === 'ok' && netKey === 'sync' && Date.now() - net._at < 1000) { holdT = setTimeout(netShow, 1000 - (Date.now() - net._at) + 20); k = 'sync'; }
    // professional wording: "Status: Live" and so on; the count is the scans kept on this computer, not yet on the server
    var txt = '<em>Status:</em> ' + (k === 'login' ? 'Session expired — <a href="/admin-login" target="_blank" rel="noopener">Sign in</a>' + (wait ? ' · ' + wait + ' queued' : '')
      : k === 'off' ? 'Offline' + (wait ? ' · ' + wait + ' queued' : '')
      : k === 'retry' ? 'Reconnecting' + (wait ? ' · ' + wait + ' queued' : '')
      : k === 'sync' ? 'Syncing · ' + Math.max(wait, 1) : 'Live');
    if (k !== netKey) { netKey = k; net._at = Date.now(); net.className = 'net n-' + k; net.innerHTML = '<i class="n-ico">' + ICO[k] + '</i><span></span>'; }
    net.querySelector('span').innerHTML = txt;
    net.title = k === 'ok' ? 'Live — connected, every scan is saved on the server' : k === 'sync' ? 'Syncing — sending scans to the server' : k === 'retry' ? 'Reconnecting — the server did not answer, trying again' : k === 'off' ? 'Offline — scans are kept on this computer and synced automatically when the connection is back' : 'Session expired — sign in again in another tab; queued scans sync by themselves';
  }
  // ---- instant: the students' list is kept in the browser; a scan is decided and shown at once, then sent in the queue ----
  var RK = 'ciScanRoster', SK = 'ciScanState', R = { min: 10, by: {} }, S = { day: DAY, st: {} }, srvOff = 0;
  function norm(c) { c = String(c).trim().toUpperCase(); return /^\d{1,5}$/.test(c) ? ('000000' + c).slice(-6) : c; }
  function hm(ms) { var d = new Date(ms), h = d.getHours(), m = d.getMinutes(); return ('0' + (h % 12 || 12)).slice(-2) + ':' + ('0' + m).slice(-2) + (h < 12 ? ' AM' : ' PM'); }
  try { var r0 = JSON.parse(localStorage.getItem(RK) || 'null'); if (r0 && r0.by) R = r0; } catch (x) {} // kept from day to day
  try { var s0 = JSON.parse(localStorage.getItem(SK) || 'null'); if (s0 && s0.day === DAY) S = s0; } catch (x) {}
  function mergeS(o) { // today's in / out from another open screen: the earliest check-in and the latest check-out win
    if (!o || o.day !== DAY || !o.st) return;
    for (var k in o.st) { var a = o.st[k] || {}, m = S.st[k] || (S.st[k] = {});
      if (a['in'] && (!m['in'] || a['in'] < m['in'])) m['in'] = a['in'];
      if (a.out && (!m.out || a.out > m.out)) m.out = a.out; }
  }
  function saveS() { try { mergeS(JSON.parse(localStorage.getItem(SK) || 'null')); localStorage.setItem(SK, JSON.stringify(S)); } catch (x) {} sideSoon(); }
  window.addEventListener('storage', function (e) { if (e.key === SK && e.newValue) try { mergeS(JSON.parse(e.newValue)); sideSoon(); } catch (x) {} });
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
  // ---- side column: checked in now (not checked out yet), numbered in the order they came; check out one / all ----
  var sdL = document.getElementById('sdL'), sdN = document.getElementById('sdN'), sdAll = document.getElementById('sdAll'), sdQ = document.getElementById('sdQ');
  var sdSig = '', sdSeen = null, sdRaf = 0, pend = {}, outAll = false, sureT = null;
  function sideSoon() { if (!sdRaf) sdRaf = requestAnimationFrame(function () { sdRaf = 0; sideRender(); }); }
  function sideRender() {
    var list = [];
    for (var k in S.st) { var m = S.st[k]; if (m && m['in'] && !m.out) list.push([k, m['in']]); }
    list.sort(function (a, b) { return a[1] - b[1]; });
    var q = sdQ.value.trim().toLowerCase(), sig = q + '|' + outAll + '|' + list.map(function (x) { return x[0] + (pend[x[0]] ? '*' : '') + ((R.by[x[0]] || {}).photo ? 'p' : ''); }).join(',');
    sdN.textContent = list.length; sdAll.disabled = !list.length || outAll;
    if (sig === sdSig) return; sdSig = sig;
    var atEnd = sdL.scrollHeight - sdL.scrollTop - sdL.clientHeight < 40, added = false, seen = {};
    var html = list.map(function (x, i) {
      var k = x[0], s = R.by[k] || { reg: k, name: k }; seen[k] = 1;
      if (q && (String(s.name) + ' ' + String(s.reg)).toLowerCase().indexOf(q) < 0) return '';
      var isNew = sdSeen && !sdSeen[k]; if (isNew) added = true;
      return '<li data-k="' + esc(k) + '"' + (isNew ? ' class="new"' : '') + '><span class="no">' + (i + 1) + '</span>' + photo(s) + '<div class="nm"><b>' + esc(s.name) + '</b><small>' + esc(s.reg) + ' · In ' + hm(x[1]) + '</small></div>'
        + '<button type="button" data-out="' + esc(k) + '"' + (pend[k] || outAll ? ' disabled' : '') + '>Check out</button></li>';
    }).join('');
    sdSeen = seen;
    sdL.innerHTML = html || '<li class="sd-e">' + (list.length ? 'No one by that name in the list.' : 'No one is checked in right now.') + '</li>';
    if (added && atEnd) sdL.scrollTop = sdL.scrollHeight; // the newest is at the bottom: keep it in view
  }
  sdQ.addEventListener('input', sideSoon);
  function checkout(keys, all) {
    keys.forEach(function (k) { pend[k] = 1; }); if (all) outAll = true; sideSoon();
    var body = new URLSearchParams({ action: 'outreg', _csrf: CSRF });
    if (all) body.set('all', '1'); else body.set('codes', JSON.stringify(keys.map(function (k) { return (R.by[k] || {}).reg || k; })));
    var end = function () { keys.forEach(function (k) { delete pend[k]; }); if (all) outAll = false; sdSig = ''; sideSoon(); };
    return fetch('/admin/ci-attendance.php', { method: 'POST', body: body, credentials: 'same-origin', cache: 'no-store', headers: { 'X-Requested-With': 'fetch' } })
      .then(function (r) { if (r.redirected && /admin-login/.test(r.url)) throw { login: 1 }; return r.json(); })
      .then(function (d) {
        if (d && d.csrf) { refreshToken(true); throw { msg: 'The page token was renewed — press Check out again.' }; }
        if (!d || !d.ok) throw { msg: (d && d.msg) || 'Could not check out.' };
        (d.att || []).forEach(function (a) { var k = norm(a[0]), m = S.st[k] || (S.st[k] = {}); if (a[1] && !m['in']) m['in'] = a[1] * 1000; if (a[2]) m.out = a[2] * 1000; });
        saveS(); end();
        var still = (all ? Object.keys(S.st) : keys).filter(function (k) { var m = S.st[k]; return m && m['in'] && !m.out; });
        var one = !all && keys.length === 1 ? (R.by[keys[0]] || {}).name || keys[0] : '';
        beep(true); st.className = 'st out';
        st.textContent = still.length ? '⚠ ' + (one ? one + "'s check-in is still being sent — try again in a moment" : still.length + ' check-in(s) still being sent — try again in a moment')
          : '✓ ' + (one ? 'Checked out — ' + one + ' · ' + d.time : d.msg);
        if (still.length) st.className = 'st wait';
        clearTimeout(idleT); idleT = setTimeout(idle, 9000);
      })
      .catch(function (e) {
        end(); if (e && e.login) { loggedOut = true; netShow(); }
        beep(false); st.className = 'st error'; st.textContent = '⚠ Not checked out — ' + (e && e.msg ? e.msg : e && e.login ? 'please sign in again' : 'no connection, try again');
        clearTimeout(idleT); idleT = setTimeout(idle, 9000);
      });
  }
  sdL.addEventListener('click', function (e) { var b = e.target.closest('button[data-out]'); if (b && !b.disabled) { b.disabled = true; checkout([b.getAttribute('data-out')], false); } });
  sdAll.addEventListener('click', function () { // two clicks: "Check out all" → "Sure? Check out N" (4 s)
    if (!sdAll.classList.contains('sure')) { sdAll.classList.add('sure'); sdAll.textContent = 'Sure? Check out ' + sdN.textContent; clearTimeout(sureT); sureT = setTimeout(function () { sdAll.classList.remove('sure'); sdAll.textContent = 'Check out all'; }, 4000); return; }
    clearTimeout(sureT); sdAll.classList.remove('sure'); sdAll.textContent = 'Check out all'; checkout([], true);
  });
  // ---- typing an admission ID by hand: a faded preview of the card before Mark (a scanner types too fast to show it) ----
  var pvT = null;
  function preview() {
    var c = code.value.trim(); if (!c) { if (box.querySelector('.card.pv') || st.classList.contains('pv')) idle(); return; }
    var k = norm(c), s = R.by[k];
    clearTimeout(idleT);
    if (!s) { if (c.length >= 3) { st.className = 'st pv'; st.textContent = 'No student with Admission ID ' + c; if (box.querySelector('.card')) idle(), st.className = 'st pv', st.textContent = 'No student with Admission ID ' + c; } return; }
    var m = S.st[k] || {};
    show({ kind: 'pv', msg: '', time: '', student: { reg: s.reg, name: s.name, 'class': s['class'], batches: s.batches, father: s.father, photo: s.photo, due: s.due, due_text: s.due_text, 'in': m['in'] ? hm(m['in']) : '', out: m.out ? hm(m.out) : '' } }, c);
    var cd = box.querySelector('.card'); if (cd) { cd.classList.remove('pop'); cd.classList.add('pv'); var mk = cd.querySelector('.mk'); if (mk) mk.remove(); }
    st.className = 'st pv'; st.textContent = 'Preview — press Mark if this is the student';
  }
  code.addEventListener('input', function () { clearTimeout(pvT); pvT = setTimeout(preview, 220); });
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
  f.addEventListener('submit', function (e) { e.preventDefault(); clearTimeout(pvT); var c = code.value.trim(); code.value = ''; if (c) add(c); code.focus(); });
  // the scanner types into the box: keep it focused
  document.addEventListener('click', function (e) { if (!e.target.closest('a,button,input,label,.dso-p')) code.focus(); });
  window.addEventListener('focus', function () { code.focus(); });
  setInterval(function () { if (document.activeElement !== code && !document.querySelector('input:focus,select:focus,textarea:focus')) code.focus(); }, 1500);
  // ---- display options (header icon): show / hide parts of this screen; kept in this browser ----
  (function () {
    var OPT = [['brand', 'Logo & institute name'], ['net', 'Status (Live / Offline)'], ['clk', 'Clock & date'], ['inp', 'Scan box (the scanner still works)'],
      ['st', 'Message line (Present — name)'], ['card', 'ID card', [['c_photo', 'Photo'], ['c_reg', 'Admission ID'], ['c_class', 'Class'], ['c_batch', 'Batch'], ['c_father', "Father's Name"], ['c_fee', 'Fee status (Full paid / Due)'], ['c_time', 'In / out time']]],
      ['side', 'Checked-in column', [['s_photo', 'Photos'], ['s_find', 'Search box'], ['s_all', 'Check out all button']]], ['rec', 'Recent names (bottom row)']];
    var b = document.getElementById('dsoB'), p = document.getElementById('dsoP'), H = {};
    try { (JSON.parse(localStorage.getItem('ciScanView') || '[]') || []).forEach(function (k) { H[k] = 1; }); } catch (x) {}
    function apply() { OPT.forEach(function (o) { document.body.classList.toggle('h-' + o[0], !!H[o[0]]); (o[2] || []).forEach(function (c) { document.body.classList.toggle('h-' + c[0], !!H[c[0]]); }); });
      try { localStorage.setItem('ciScanView', JSON.stringify(Object.keys(H))); } catch (x) {} }
    function box(k, l, sub, off) { return '<label class="' + (sub ? 'sub' : '') + (off ? ' off' : '') + '"><input type="checkbox" data-k="' + k + '"' + (H[k] ? '' : ' checked') + (off ? ' disabled' : '') + '>' + esc(l) + '</label>'; }
    function draw() { p.innerHTML = '<div class="dso-h"><b>Display options</b><button type="button" data-all>Show all</button></div>' + OPT.map(function (o) { return box(o[0], o[1]) + (o[2] || []).map(function (c) { return box(c[0], c[1], 1, H[o[0]]); }).join(''); }).join(''); }
    b.addEventListener('click', function (e) { e.stopPropagation(); if (p.hidden) draw(); p.hidden = !p.hidden; b.setAttribute('aria-expanded', p.hidden ? 'false' : 'true'); if (p.hidden) code.focus(); });
    document.addEventListener('click', function (e) { if (!p.hidden && !e.target.closest('.dso')) { p.hidden = true; b.setAttribute('aria-expanded', 'false'); } });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !p.hidden) { p.hidden = true; b.setAttribute('aria-expanded', 'false'); code.focus(); } });
    p.addEventListener('click', function (e) { if (e.target.closest('[data-all]')) { H = {}; apply(); draw(); } });
    p.addEventListener('change', function (e) { var k = e.target.getAttribute('data-k'); if (!k) return; if (e.target.checked) delete H[k]; else H[k] = 1; apply(); draw(); });
  })();
  idle(); netShow(); sideRender();
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
