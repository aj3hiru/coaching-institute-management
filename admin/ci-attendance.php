<?php
// Attendance (Institute only): the students of the institute's batches for one day — filter by course / batch / class or
// find a student by name, admission ID or mobile; mark Present / Absent / Leave by hand, or scan the student's ID card
// (barcode = admission ID): the first scan checks in, a scan after the set minutes checks out.
require_once __DIR__ . '/components/guard.php';
require_once INCLUDES_PATH . '/institute.php';
$me = ci_page_start($pdo, ['attendance']);
$today = date('Y-m-d');
$ST = ['present' => 'Present', 'absent' => 'Absent', 'leave' => 'Leave', 'holiday' => 'Holiday'];

// ---- actions (JSON) ----
$act = (string)($_POST['action'] ?? '');
if ($act !== '') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $out = function (array $d, int $code = 200) { http_response_code($code); exit(json_encode($d, JSON_UNESCAPED_UNICODE)); };
    if (!hash_equals(csrf_token(), (string)($_POST['_csrf'] ?? ''))) $out(['ok' => false, 'msg' => 'This page had expired. Please reload it.'], 400);
    $date = ci_clean_date($_POST['date'] ?? '') ?: $today;
    if ($date > $today) $out(['ok' => false, 'msg' => 'Attendance cannot be marked for a future date.'], 400);
    if ($act === 'scan') {
        if ($date !== $today) $out(['ok' => false, 'kind' => 'error', 'msg' => 'Scanning works for today only — switch the date to today.']);
        $r = ci_att_scan($pdo, (string)($_POST['code'] ?? ''), (int)($_POST['batch'] ?? 0), (int)$me['id']);
        $s = $r['student'] ?? null;
        $out(['ok' => $r['ok'], 'kind' => $r['kind'], 'msg' => $r['msg'], 'student' => $s ? ['id' => (int)$s['id'], 'name' => $s['name'], 'reg' => $s['reg_no'],
            'class' => $s['class_name'], 'photo' => ci_photo_url($s['photo'] ?? ''), 'avatar' => ci_avatar($s, 56)] : null, 'time' => date('h:i A')]);
    }
    if ($act === 'mark') { // one or many [student, batch] pairs → one status
        $status = (string)($_POST['status'] ?? '');
        $pairs = json_decode((string)($_POST['pairs'] ?? '[]'), true);
        if (!is_array($pairs) || !$pairs || count($pairs) > 2000) $out(['ok' => false, 'msg' => 'Nothing to mark.'], 400);
        if ($status === 'clear') {
            $del = $pdo->prepare("DELETE FROM ci_attendance WHERE student_id = ? AND batch_id = ? AND att_date = ?");
            foreach ($pairs as [$sid, $bid]) $del->execute([(int)$sid, (int)$bid, $date]);
            $out(['ok' => true]);
        }
        if (!isset($ST[$status])) $out(['ok' => false, 'msg' => 'Unknown status.'], 400);
        // Present by hand today = checked in now (like a scan); Absent / Leave / Holiday have no times
        $in = $status === 'present' && $date === $today ? date('Y-m-d H:i:s') : null;
        $ins = $pdo->prepare("INSERT INTO ci_attendance (student_id, batch_id, att_date, status, in_at, source, marked_by) VALUES (?,?,?,?,?, 'manual', ?)
            ON DUPLICATE KEY UPDATE status = VALUES(status), marked_by = VALUES(marked_by), updated_at = NOW(),
                in_at = IF(VALUES(status) = 'present', COALESCE(in_at, VALUES(in_at)), NULL), out_at = IF(VALUES(status) = 'present', out_at, NULL)");
        $pdo->beginTransaction();
        foreach ($pairs as [$sid, $bid]) $ins->execute([(int)$sid, (int)$bid, $date, $status, $in, (int)$me['id']]);
        $pdo->commit();
        $out(['ok' => true, 'n' => count($pairs), 'time' => $in ? date('h:i A', strtotime($in)) : '']);
    }
    if ($act === 'checkout') { // by hand: one student, or every checked-in student of the list in one click
        if ($date !== $today) $out(['ok' => false, 'msg' => 'Check-out is for today only.']);
        $pairs = json_decode((string)($_POST['pairs'] ?? '[]'), true);
        if (!is_array($pairs) || !$pairs || count($pairs) > 2000) $out(['ok' => false, 'msg' => 'Nobody to check out.'], 400);
        $up = $pdo->prepare("UPDATE ci_attendance SET out_at = NOW(), updated_at = NOW(), marked_by = ? WHERE student_id = ? AND batch_id = ? AND att_date = ? AND status = 'present' AND in_at IS NOT NULL AND out_at IS NULL");
        $n = 0; foreach ($pairs as [$sid, $bid]) { $up->execute([(int)$me['id'], (int)$sid, (int)$bid, $date]); $n += $up->rowCount(); }
        // "Check out all": the students of these batches who did not come today become absent (a mark already given stays)
        $abs = json_decode((string)($_POST['absent'] ?? '[]'), true); $na = 0;
        if (is_array($abs) && $abs && count($abs) <= 2000) {
            $ia = $pdo->prepare("INSERT IGNORE INTO ci_attendance (student_id, batch_id, att_date, status, source, marked_by) VALUES (?,?,?, 'absent', 'manual', ?)");
            foreach ($abs as [$sid, $bid]) { $ia->execute([(int)$sid, (int)$bid, $date, (int)$me['id']]); $na += $ia->rowCount(); }
        }
        $msg = ($n ? $n . ' student(s) checked out at ' . date('h:i A') : 'Nobody was checked in without a check-out') . ($na ? '; ' . $na . ' who did not come marked absent' : '') . '.';
        $out(['ok' => true, 'n' => $n, 'na' => $na, 'time' => date('h:i A'), 'msg' => $msg]);
    }
    if ($act === 'settings') {
        $min = max(0, min(720, (int)($_POST['min_out'] ?? 60)));
        edu_settings_save('institute', ['att_min_out' => $min]);
        $out(['ok' => true, 'min' => $min, 'msg' => 'Saved: check-out opens ' . $min . ' minutes after check-in.']);
    }
    $out(['ok' => false, 'msg' => 'Unknown action.'], 400);
}

// ---- the list ----
$L = ci_lists($pdo);
$f = [
    'date' => min($today, ci_clean_date($_GET['date'] ?? '') ?: $today),
    'course' => (int)($_GET['course'] ?? 0), 'batch' => (int)($_GET['batch'] ?? 0), 'class' => (int)($_GET['class'] ?? 0),
    'q' => mb_substr(trim((string)($_GET['q'] ?? '')), 0, 60), 'show' => in_array($_GET['show'] ?? '', ['present', 'absent', 'leave', 'holiday', 'none'], true) ? $_GET['show'] : '',
];
$batches = $pdo->query("SELECT b.id, b.name, b.timing, b.course_id, b.start_time, b.end_time, c.name AS course_name FROM ci_batches b LEFT JOIN ci_courses c ON c.id = b.course_id WHERE b.status = 'active' ORDER BY b.start_time IS NULL, b.start_time, c.name, b.name")->fetchAll(PDO::FETCH_ASSOC);
ci_att_autoclose($pdo, date('Y-m-d', strtotime('-14 days')), $today); // past classes: the ones not marked are absent
// opened with no choice: the batch whose class is on now (15 minutes before it starts until 15 minutes after it ends)
$running = null;
if (!array_intersect(array_keys($_GET), ['date', 'course', 'batch', 'class', 'q', 'show', 'all'])) {
    $now = date('H:i:s', time() - CI_ATT_GRACE * 60); $soon = date('H:i:s', time() + 15 * 60);
    foreach ($batches as $b) if ($b['start_time'] && $b['end_time'] && $b['start_time'] <= $soon && $now <= $b['end_time']) { $running = $b; break; }
}
if ($running) $f['batch'] = (int)$running['id'];
$courses = $pdo->query("SELECT id, name FROM ci_courses WHERE status = 'active' ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);
$w = ["s.status = 'active'", "s.stype <> 'online'", "e.status = 'active'", "(e.batch_id IS NULL OR b.status = 'active')", "(e.enrolled_on IS NULL OR e.enrolled_on <= ?)"];
$a = [$f['date']];
if ($f['course']) { $w[] = 'e.course_id = ?'; $a[] = $f['course']; }
if ($f['batch']) { $w[] = 'e.batch_id = ?'; $a[] = $f['batch']; }
if ($f['class']) { $w[] = 's.class_id = ?'; $a[] = $f['class']; }
if ($f['q'] !== '') { $w[] = '(s.name LIKE ? OR s.reg_no = ? OR s.reg_no = ? OR s.mobile LIKE ?)'; array_push($a, '%' . $f['q'] . '%', $f['q'], ctype_digit($f['q']) ? str_pad($f['q'], 6, '0', STR_PAD_LEFT) : $f['q'], '%' . $f['q'] . '%'); }
$q = $pdo->prepare("SELECT s.id, s.name, s.reg_no, s.roll_no, s.photo, s.mobile, cl.name AS class_name, COALESCE(e.batch_id, 0) AS batch_id, b.name AS batch_name, b.timing, e.course_id, co.name AS course_name,
        a.status, a.in_at, a.out_at, a.source
    FROM ci_enrollments e JOIN ci_students s ON s.id = e.student_id LEFT JOIN ci_batches b ON b.id = e.batch_id LEFT JOIN ci_courses co ON co.id = e.course_id
    LEFT JOIN ci_classes cl ON cl.id = s.class_id
    LEFT JOIN ci_attendance a ON a.student_id = s.id AND a.batch_id = COALESCE(e.batch_id, 0) AND a.att_date = ?
    WHERE " . implode(' AND ', $w) . "
    ORDER BY cl.sort IS NULL, cl.sort, s.reg_no, s.name");
$q->execute(array_merge([$f['date']], $a));
$rows = []; foreach ($q as $r) $rows[$r['id'] . '-' . $r['batch_id']] ??= $r; // one row per student and batch
$rows = array_values($rows);
$hols = ci_holidays_between($pdo, $f['date'], $f['date']);
foreach ($rows as &$r) { $r['hol'] = ci_holiday_on($hols, $f['date'], (int)$r['batch_id'], (int)$r['course_id']); if (!$r['status'] && $r['hol']) $r['status'] = 'holiday'; } unset($r);
$cnt = ['all' => count($rows), 'present' => 0, 'absent' => 0, 'leave' => 0, 'holiday' => 0, 'none' => 0];
foreach ($rows as $r) $cnt[$r['status'] ?: 'none']++;
// this month for the same choice: class days, holidays and marks
$mA = date('Y-m-01', strtotime($f['date'])); $mB = min($today, date('Y-m-t', strtotime($f['date'])));
$mPairs = array_map(fn($r) => [(int)$r['id'], (int)$r['batch_id'], (int)$r['course_id']], $rows);
$mon = ['days' => 0, 'hol' => 0, 'present' => 0, 'absent' => 0, 'leave' => 0];
if ($mPairs) {
    $ids = implode(',', array_unique(array_column($mPairs, 0)));
    $mq = $pdo->prepare("SELECT student_id, batch_id, att_date, status FROM ci_attendance WHERE student_id IN ($ids) AND att_date BETWEEN ? AND ?");
    $mq->execute([$mA, $mB]); $keys = array_flip(array_map(fn($p) => $p[0] . '-' . $p[1], $mPairs)); $dset = [];
    foreach ($mq as $m) { if (!isset($keys[$m['student_id'] . '-' . $m['batch_id']]) || !isset($mon[$m['status']])) continue; $mon[$m['status']]++; if ($m['status'] !== 'holiday') $dset[$m['att_date']] = 1; }
    $mon['days'] = count($dset);
    $mh = ci_holidays_between($pdo, $mA, $mB);
    for ($t = strtotime($mA); $t <= strtotime($mB); $t += 86400) { $dd = date('Y-m-d', $t); foreach ($mPairs as [$x, $bid, $cid]) if (ci_holiday_on($mh, $dd, $bid, $cid)) { $mon['hol']++; break; } }
}
$mPct = ($mon['present'] + $mon['absent'] + $mon['leave']) ? round($mon['present'] / ($mon['present'] + $mon['absent'] + $mon['leave']) * 100) : null;
if ($f['show']) $rows = array_values(array_filter($rows, fn($r) => ($r['status'] ?: 'none') === $f['show']));
$minOut = ci_att_min_out();
$isToday = $f['date'] === $today;
$qs = fn(array $x) => '?' . http_build_query(array_filter(array_merge($f, $x), fn($v) => $v !== '' && $v !== 0 && $v !== null));
$checkedIn = count(array_filter($rows, fn($r) => $r['status'] === 'present' && $r['in_at'] && !$r['out_at']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title>Attendance - <?= ci_e(edu_site_name()) ?></title>
<?php admin_shell_head(); ci_head(); ?>
<style>
.at-top{display:grid;grid-template-columns:minmax(0,1fr) 360px;gap:16px;align-items:start;margin-bottom:16px}
@media (max-width:1100px){.at-top{grid-template-columns:1fr}}
.at-scan{border:1px solid var(--ci-b,#e5e7eb);background:#fff;padding:16px}
.at-scan h3{margin:0 0 10px;font-size:15px;display:flex;align-items:center;gap:8px}.at-scan h3 i{color:var(--ci-acc,#4a3369)}
.at-scan input{width:100%;box-sizing:border-box;height:46px;padding:0 12px;border:2px solid var(--ci-acc,#4a3369);font:inherit;font-size:16px;letter-spacing:.04em}
.at-scan input:focus{outline:none;box-shadow:0 0 0 3px rgba(74,51,105,.15)}
.at-scan small{display:block;margin-top:6px;color:#6b7280;font-size:12px}
.at-scan-row{display:flex;gap:8px}.at-scan-row input{flex:1;min-width:0}.at-scan-row button{flex:none;height:46px;padding:0 16px;border:0;background:var(--ci-acc,#4a3369);color:#fff;font:inherit;font-size:14px;font-weight:700;cursor:pointer}.at-scan-row button:disabled{opacity:.5;cursor:not-allowed}
.at-last{display:flex;gap:12px;align-items:center;margin-top:12px;padding:10px;border:1px solid #e5e7eb;min-height:56px}
.at-last[hidden]{display:none}
.at-last.in{border-color:#bbf7d0;background:#f0fdf4}.at-last.out{border-color:#bfdbfe;background:#eff6ff}.at-last.wait,.at-last.done{border-color:#fde68a;background:#fffbeb}.at-last.error{border-color:#fecaca;background:#fef2f2}
.at-last b{display:block;font-size:15px}.at-last span{display:block;font-size:13px;color:#374151}.at-last em{font-style:normal;font-size:12px;color:#6b7280}
.at-sum{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 12px}
.at-sum a{display:inline-flex;align-items:center;gap:6px;padding:7px 12px;border:1px solid #e5e7eb;background:#fff;color:#374151;font-size:13px;font-weight:600;text-decoration:none}
.at-sum a b{font-size:15px}.at-sum a.on{border-color:var(--ci-acc,#4a3369);background:var(--ci-acc,#4a3369);color:#fff}
.at-st{display:inline-flex;border:1px solid #d1d5db}
.at-st button{min-width:38px;height:32px;padding:0 10px;border:0;border-left:1px solid #d1d5db;background:#fff;color:#374151;font:inherit;font-size:13px;font-weight:700;cursor:pointer}
.at-st button:first-child{border-left:0}
.at-st button.on[data-s=present]{background:#16a34a;color:#fff}.at-st button.on[data-s=absent]{background:#dc2626;color:#fff}.at-st button.on[data-s=leave]{background:#d97706;color:#fff}
.at-st button.on[data-s=holiday]{background:#7c3aed;color:#fff}
.at-st button:disabled{opacity:.5;cursor:wait}
.at-io{display:inline-flex;align-items:center;gap:4px;height:26px;margin-top:3px;padding:0 9px;border:1px solid #d1d5db;background:#fff;color:#374151;font:inherit;font-size:12px;font-weight:600;cursor:pointer}
.at-io.out{border-color:#93c5fd;color:#1d4ed8}.at-io:disabled{opacity:.5;cursor:wait}
.at-hol{display:block;font-size:11.5px;color:#6d28d9;margin-top:3px}
.at-month{display:flex;flex-wrap:wrap;align-items:stretch;gap:0;margin:0 0 14px;border:1px solid #e5e7eb;background:#fff}
.at-month>div{flex:1 1 110px;padding:10px 14px;border-left:1px solid #f1f5f9}.at-month>div:first-child{border-left:0;flex:1.6 1 180px;background:#faf9fc}
.at-month small{display:block;font-size:11.5px;color:#6b7280;text-transform:uppercase;letter-spacing:.04em}.at-month b{font-size:19px;color:#111827}
.at-month .p b{color:#15803d}.at-month .a b{color:#b91c1c}.at-month .l b{color:#b45309}.at-month .h b{color:#6d28d9}
.at-run{padding:8px 12px;margin:0 0 12px;border:1px solid #c4b5fd;background:#f5f3ff;color:#4c1d95;font-size:13px}.at-run a{color:inherit;font-weight:700;margin-left:6px}
.at-t{font-size:12.5px;color:#374151;white-space:nowrap}.at-t small{display:block;color:#9ca3af}
.at-bulk{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-left:auto}
.at-name{display:flex;align-items:center;gap:10px}.at-name a{font-weight:600;color:#111827;text-decoration:none}.at-name small{display:block;color:#6b7280;font-size:12px}
tr.at-hl td{background:#fefce8}
.at-past{padding:8px 12px;margin:0 0 12px;border:1px solid #fde68a;background:#fffbeb;color:#92400e;font-size:13px}
</style>
</head>
<body>
<?php admin_shell_open('Attendance', date('l, d M Y', strtotime($f['date'])), '<div class="ci-top-actions"><button type="button" class="ci-btn ci-ghost" id="atSetBtn"><i class="fas fa-clock"></i> Check-out time</button><a class="ci-btn ci-ghost" href="/admin/ci-reports.php?type=attendance"><i class="fas fa-file-pdf"></i> Attendance report</a></div>'); ?>
<div class="ci">
<div class="at-top">
  <form class="ci-filters" method="get" id="atF" data-ds="Filters" style="margin:0">
    <div class="ci-f"><label>Date</label><input type="date" name="date" value="<?= ci_e($f['date']) ?>" max="<?= $today ?>"></div>
    <div class="ci-f"><label>Course</label><select name="course"><option value="0">All courses</option><?php foreach ($courses as $id => $n): ?><option value="<?= (int)$id ?>"<?= $f['course'] === (int)$id ? ' selected' : '' ?>><?= ci_e($n) ?></option><?php endforeach; ?></select></div>
    <div class="ci-f"><label>Batch</label><select name="batch"><option value="0">All batches</option><?php foreach ($batches as $b): ?><option value="<?= (int)$b['id'] ?>"<?= $f['batch'] === (int)$b['id'] ? ' selected' : '' ?>><?= ci_e($b['name'] . ($b['timing'] ? ' · ' . $b['timing'] : '')) ?></option><?php endforeach; ?></select></div>
    <div class="ci-f"><label>Class</label><select name="class"><option value="0">All classes</option><?php foreach ($L['classes'] as $id => $n): ?><option value="<?= (int)$id ?>"<?= $f['class'] === (int)$id ? ' selected' : '' ?>><?= ci_e($n) ?></option><?php endforeach; ?></select></div>
    <div class="ci-f"><label>Name / admission ID / mobile</label><input type="search" name="q" value="<?= ci_e($f['q']) ?>" placeholder="Search"></div>
    <?php if ($f['show']): ?><input type="hidden" name="show" value="<?= ci_e($f['show']) ?>"><?php endif; ?>
  </form>
  <div class="at-scan" data-ds="Scan ID card or enter admission ID">
    <h3><i class="fas fa-barcode"></i> Scan ID card or enter Admission ID</h3>
    <form class="at-scan-row" id="atScanF"><input type="text" id="atScan" inputmode="text" autocomplete="off" aria-label="Admission ID" placeholder="<?= $isToday ? 'Admission ID' : 'Switch the date to today' ?>"<?= $isToday ? ' autofocus' : ' disabled' ?>><button type="submit"<?= $isToday ? '' : ' disabled' ?>><i class="fas fa-check"></i> Mark</button></form>
    <small><?= $isToday ? 'By hand: type the admission ID (e.g. 17 or 000017) and press Enter or Mark.' : 'For another day, use the P / A / L buttons in the list.' ?></small>
    <div class="at-last" id="atLast" hidden></div>
  </div>
</div>
<?php if ($running): ?><div class="at-run"><i class="fas fa-bell"></i> Class on now: <b><?= ci_e($running['name']) ?></b> (<?= ci_e(ci_time_range($running['start_time'], $running['end_time'])) ?>) — showing this batch.<a href="?all=1">Show all batches</a></div><?php endif; ?>
<div class="at-month" data-ds="This month">
  <div><small><?= date('F Y', strtotime($f['date'])) ?><?= $f['batch'] || $f['course'] || $f['class'] || $f['q'] ? ' · this list' : '' ?></small><b><?= $mon['days'] ?></b> class day<?= $mon['days'] === 1 ? '' : 's' ?><?= $mPct !== null ? ' · ' . $mPct . '%' : '' ?></div>
  <div class="p"><small>Present</small><b><?= $mon['present'] ?></b></div>
  <div class="a"><small>Absent</small><b><?= $mon['absent'] ?></b></div>
  <div class="l"><small>Leave</small><b><?= $mon['leave'] ?></b></div>
  <div class="h"><small>Holidays</small><b><?= $mon['hol'] ?></b> <a href="/admin/ci-holidays.php" style="font-size:12px">Manage</a></div>
</div>
<?php if (!$isToday): ?><div class="at-past"><i class="fas fa-calendar-day"></i> You are marking attendance for <b><?= ci_date($f['date']) ?></b>, not today.</div><?php endif; ?>

<div class="at-sum" data-ds="Summary">
  <?php foreach (['' => ['All', $cnt['all']], 'present' => ['Present', $cnt['present']], 'absent' => ['Absent', $cnt['absent']], 'leave' => ['Leave', $cnt['leave']], 'holiday' => ['Holiday', $cnt['holiday']], 'none' => ['Not marked', $cnt['none']]] as $k => [$l, $n]): ?>
  <a href="<?= ci_e($qs(['show' => $k])) ?>" class="<?= $f['show'] === $k ? 'on' : '' ?>"><?= $l ?> <b><?= $n ?></b></a>
  <?php endforeach; ?>
  <div class="at-bulk">
    <button type="button" class="ci-btn ci-ghost" data-bulk="present"><i class="fas fa-check-double"></i> All present</button>
    <button type="button" class="ci-btn ci-ghost" data-bulk="absent-rest"><i class="fas fa-user-xmark"></i> Rest absent</button>
    <?php if ($isToday): ?><button type="button" class="ci-btn" id="atOutAll"<?= $checkedIn || array_filter($rows, fn($r) => $r['status'] === 'present') ? '' : ' disabled' ?>><i class="fas fa-right-from-bracket"></i> <span>Check out all (<?= $checkedIn ?>)</span></button><?php endif; ?>
  </div>
</div>

<div class="ci-card" data-ds="Students"><div class="ci-card-b ci-p0 ci-table-wrap"><table class="ci-table" id="atT" data-sheet="off">
  <thead><tr><th>Adm. ID</th><th>Student</th><th>Class</th><th>Batch</th><th>Attendance</th><th>Check-in / out</th></tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="6" class="ci-empty">No students for these filters. Students show here when they are in an active batch / course.</td></tr><?php endif; ?>
  <?php foreach ($rows as $r): ?>
  <tr data-sid="<?= (int)$r['id'] ?>" data-bid="<?= (int)$r['batch_id'] ?>" data-reg="<?= ci_e($r['reg_no']) ?>">
    <td class="ci-nw"><b><?= ci_e($r['reg_no']) ?></b></td>
    <td><div class="at-name"><?= ci_avatar($r, 34) ?><div><a href="/admin/student-view.php?id=<?= (int)$r['id'] ?>#attendance"><?= ci_e($r['name']) ?></a><small><?= ci_e($r['mobile']) ?></small></div></div></td>
    <td class="ci-nw"><?= ci_e($r['class_name'] ?: '—') ?></td>
    <td><?= $r['batch_name'] ? ci_e($r['batch_name']) . ($r['timing'] ? '<small class="ci-muted" style="display:block">' . ci_e($r['timing']) . '</small>' : '') : '<span class="ci-muted">' . ci_e($r['course_name'] ?: 'No batch') . '</span>' ?></td>
    <td><div class="at-st" role="group" aria-label="Attendance of <?= ci_e($r['name']) ?>"><?php foreach (['present' => 'P', 'absent' => 'A', 'leave' => 'L', 'holiday' => 'H'] as $k => $l): ?><button type="button" data-s="<?= $k ?>" class="<?= $r['status'] === $k ? 'on' : '' ?>" title="<?= $ST[$k] ?>"><?= $l ?></button><?php endforeach; ?></div><?= $r['hol'] ? '<span class="at-hol"><i class="fas fa-umbrella-beach"></i> ' . ci_e($r['hol']) . '</span>' : '' ?></td>
    <td class="at-t" data-in="<?= $r['in_at'] ? date('h:i A', strtotime($r['in_at'])) : '' ?>" data-out="<?= $r['out_at'] ? date('h:i A', strtotime($r['out_at'])) : '' ?>" data-st="<?= ci_e((string)$r['status']) ?>"></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table></div></div>
</div>

<div class="ci-modal" id="atSet" hidden><div class="ci-modal-box" role="dialog" aria-modal="true" aria-labelledby="atSetH">
  <div class="ci-card-h"><h3 id="atSetH">Check-out time</h3><button type="button" class="ci-x" data-close aria-label="Close"><i class="fas fa-times"></i></button></div>
  <form class="ci-card-b" id="atSetF">
    <div class="ci-f"><label>Minimum minutes after check-in before a scan counts as check-out</label><input type="number" name="min_out" min="0" max="720" value="<?= $minOut ?>" required></div>
    <p class="ci-muted" style="margin:8px 0 14px;font-size:13px">A second scan before this time only shows the check-in time.</p>
    <div style="display:flex;gap:8px"><button class="ci-btn" type="submit"><?= ci_svg('check') ?> Save</button><button type="button" class="ci-btn ci-ghost" data-close>Cancel</button></div>
  </form>
</div></div>
<?php admin_shell_close(); ?>
<script>
(function () {
  var CSRF = <?= json_encode(csrf_token(), JSON_HEX_TAG) ?>, DATE = <?= json_encode($f['date'], JSON_HEX_TAG) ?>, BATCH = <?= (int)$f['batch'] ?>, TODAY = <?= $isToday ? 'true' : 'false' ?>;
  // the class on now: 15 minutes after it ends the students who did not come become absent — reload then to show it
  <?php if ($running): ?>setTimeout(function () { location.reload(); }, Math.max(5000, <?= (strtotime($today . ' ' . $running['end_time']) + CI_ATT_GRACE * 60 - time()) * 1000 + 5000 ?>));<?php endif; ?>
  function post(data) {
    var fd = new FormData(); fd.append('_csrf', CSRF); fd.append('date', DATE);
    Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
    return fetch('/admin/ci-attendance.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
  }
  var tEl = null, tTimer = 0;
  function toast(m, ok) { // a short note at the bottom of the screen
    if (!tEl) { tEl = document.createElement('div'); tEl.setAttribute('role', 'status'); tEl.style.cssText = 'position:fixed;left:50%;bottom:24px;transform:translateX(-50%);z-index:9999;max-width:90vw;padding:10px 16px;color:#fff;font-size:14px;box-shadow:0 8px 24px rgba(0,0,0,.2)'; document.body.appendChild(tEl); }
    tEl.textContent = m; tEl.style.background = ok === false ? '#b91c1c' : '#166534'; tEl.hidden = false;
    clearTimeout(tTimer); tTimer = setTimeout(function () { tEl.hidden = true; }, 3500);
  }
  // filters: a change shows the list at once
  var F = document.getElementById('atF');
  F.addEventListener('change', function (e) { if (e.target.name !== 'q') F.submit(); });
  // P / A / L on a row (clicking the chosen one again clears it)
  function setRow(tr, st) { tr.querySelectorAll('.at-st button').forEach(function (b) { b.classList.toggle('on', b.dataset.s === st); }); var t = tr.querySelector('.at-t'); t.dataset.st = st; if (st !== 'present') { t.dataset.in = ''; t.dataset.out = ''; } drawT(t); }
  // check-in / out cell: times, or a button to check in / out by hand (today, a student without a card)
  function drawT(t) {
    var h = '';
    if (t.dataset.in) h += 'In ' + t.dataset.in;
    if (t.dataset.out) h += '<small>Out ' + t.dataset.out + '</small>';
    if (TODAY && t.dataset.st !== 'absent' && t.dataset.st !== 'leave' && t.dataset.st !== 'holiday') {
      if (!t.dataset.in) h += '<button type="button" class="at-io" data-io="in"><i class="fas fa-right-to-bracket"></i> Check in</button>';
      else if (!t.dataset.out) h += '<br><button type="button" class="at-io out" data-io="out"><i class="fas fa-right-from-bracket"></i> Check out</button>';
    }
    t.innerHTML = h || '<span class="ci-muted">—</span>';
  }
  function countIn() { var n = [].filter.call(document.querySelectorAll('#atT .at-t'), function (t) { return t.dataset.in && !t.dataset.out && t.dataset.st === 'present'; }).length, b = document.getElementById('atOutAll'); if (b) { b.querySelector('span').textContent = 'Check out all (' + n + ')'; b.disabled = !n && !document.querySelector('#atT .at-t[data-st="present"]'); } return n; }
  document.querySelectorAll('#atT .at-t').forEach(drawT);
  document.getElementById('atT').addEventListener('click', function (e) {
    var io = e.target.closest('.at-io'); if (!io) return;
    var tr = io.closest('tr'), t = tr.querySelector('.at-t'), pair = JSON.stringify([[+tr.dataset.sid, +tr.dataset.bid]]); io.disabled = true;
    (io.dataset.io === 'in' ? post({ action: 'mark', status: 'present', pairs: pair }) : post({ action: 'checkout', pairs: pair })).then(function (d) {
      if (!d.ok) throw new Error(d.msg || 'Not saved');
      if (io.dataset.io === 'in') { t.dataset.in = d.time; setRow(tr, 'present'); toast(tr.querySelector('.at-name a').textContent + ' checked in at ' + d.time); }
      else { t.dataset.out = d.time; drawT(t); toast(tr.querySelector('.at-name a').textContent + ' checked out at ' + d.time); }
      countIn();
    }).catch(function (err) { toast(err.message, false); io.disabled = false; });
  });
  var outAll = document.getElementById('atOutAll');
  if (outAll) outAll.addEventListener('click', function () {
    var all = [].slice.call(document.querySelectorAll('#atT tr[data-sid]'));
    var trs = all.filter(function (tr) { var t = tr.querySelector('.at-t'); return t.dataset.in && !t.dataset.out && t.dataset.st === 'present'; });
    var held = {}; all.forEach(function (tr) { if (tr.querySelector('.at-t').dataset.st === 'present') held[tr.dataset.bid] = 1; }); // batches whose class was held today
    var rest = all.filter(function (tr) { return !tr.querySelector('.at-t').dataset.st && held[tr.dataset.bid] && !tr.querySelector('.at-hol'); });
    if (!trs.length && !rest.length) return;
    var go = function () {
      outAll.disabled = true;
      var pr = function (l) { return JSON.stringify(l.map(function (tr) { return [+tr.dataset.sid, +tr.dataset.bid]; })); };
      post({ action: 'checkout', pairs: pr(trs), absent: pr(rest) }).then(function (d) {
        if (!d.ok) throw new Error(d.msg);
        trs.forEach(function (tr) { var t = tr.querySelector('.at-t'); t.dataset.out = d.time; drawT(t); });
        rest.forEach(function (tr) { setRow(tr, 'absent'); });
        countIn(); toast(d.msg);
      }).catch(function (err) { toast(err.message, false); countIn(); });
    };
    var text = 'Check out ' + trs.length + ' present student(s) now' + (rest.length ? ' and mark the other ' + rest.length + ' student(s) of this batch absent' : '') + '?';
    if (window.EduDialog) EduDialog.confirm(text, { title: 'Check out', ok: 'Check out all' }).then(function (y) { if (y) go(); }); else if (confirm(text)) go();
  });
  document.getElementById('atT').addEventListener('click', function (e) {
    var b = e.target.closest('.at-st button'); if (!b) return;
    var tr = b.closest('tr'), st = b.classList.contains('on') ? 'clear' : b.dataset.s, btns = tr.querySelectorAll('.at-st button');
    btns.forEach(function (x) { x.disabled = true; });
    post({ action: 'mark', status: st, pairs: JSON.stringify([[+tr.dataset.sid, +tr.dataset.bid]]) }).then(function (d) {
      if (!d.ok) throw new Error(d.msg || 'Not saved');
      var t = tr.querySelector('.at-t'); if (st === 'present' && d.time && !t.dataset.in) t.dataset.in = d.time; // P today = checked in now
      if (st === 'clear') { t.dataset.in = ''; t.dataset.out = ''; }
      setRow(tr, st === 'clear' ? '' : st); countIn();
    }).catch(function (err) { toast(err.message, false); }).then(function () { btns.forEach(function (x) { x.disabled = false; }); });
  });
  // whole list: all present / the unmarked ones absent
  document.querySelectorAll('[data-bulk]').forEach(function (b) { b.addEventListener('click', function () {
    var rest = b.dataset.bulk === 'absent-rest', trs = [].filter.call(document.querySelectorAll('#atT tr[data-sid]'), function (tr) { return !rest || !tr.querySelector('.at-st button.on'); });
    if (!trs.length) { toast(rest ? 'Everyone is already marked.' : 'No students in the list.', false); return; }
    var go = function () {
      b.disabled = true;
      post({ action: 'mark', status: rest ? 'absent' : 'present', pairs: JSON.stringify(trs.map(function (tr) { return [+tr.dataset.sid, +tr.dataset.bid]; })) })
        .then(function (d) { if (!d.ok) throw new Error(d.msg); location.reload(); }).catch(function (e) { toast(e.message, false); b.disabled = false; });
    };
    var text = (rest ? 'Mark ' + trs.length + ' unmarked student(s) absent?' : 'Mark all ' + trs.length + ' student(s) in this list present?');
    if (window.EduDialog) EduDialog.confirm(text, { title: 'Attendance', ok: rest ? 'Mark absent' : 'Mark present' }).then(function (y) { if (y) go(); }); else if (confirm(text)) go();
  }); });
  // barcode scanner (types the admission ID + Enter) or typing by hand
  var scan = document.getElementById('atScan'), last = document.getElementById('atLast'), busy = false;
  function esc(t) { return String(t == null ? '' : t).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  var scanF = document.getElementById('atScanF');
  if (scanF) scanF.addEventListener('submit', function (e) {
    e.preventDefault();
    var code = scan.value.trim(); if (!code || busy) return; busy = true; scan.value = '';
    post({ action: 'scan', code: code, batch: BATCH }).then(function (d) {
      var s = d.student;
      last.className = 'at-last ' + (d.kind || 'error'); last.hidden = false;
      last.innerHTML = (s ? s.avatar : '<i class="fas fa-circle-xmark" style="font-size:28px;color:#dc2626"></i>') + '<div><b>' + esc(s ? s.name : code) + '</b><span>' + esc(d.msg) + '</span>'
        + (s ? '<em>' + esc([s.class ? 'Class ' + s.class : '', s.reg].filter(Boolean).join(' · ')) + ' · ' + esc(d.time) + '</em>' : '') + '</div>';
      if (s) { // the student's row in the list: mark it and light it up
        document.querySelectorAll('#atT tr[data-sid="' + s.id + '"]').forEach(function (tr) {
          var t = tr.querySelector('.at-t');
          if (d.kind === 'in') { t.dataset.in = d.time; setRow(tr, 'present'); }
          if (d.kind === 'out') { t.dataset.out = d.time; drawT(t); }
          countIn();
          tr.classList.add('at-hl'); setTimeout(function () { tr.classList.remove('at-hl'); }, 2500);
        });
      }
      try { var a = new (window.AudioContext || window.webkitAudioContext)(), o = a.createOscillator(); o.frequency.value = d.ok ? 880 : 220; o.connect(a.destination); o.start(); o.stop(a.currentTime + 0.12); } catch (x) {}
    }).catch(function () { toast('Could not reach the server — scan again.', false); }).then(function () { busy = false; scan.focus(); });
  });
  // check-out time setting
  var m = document.getElementById('atSet'), sf = document.getElementById('atSetF');
  document.getElementById('atSetBtn').addEventListener('click', function () { m.hidden = false; sf.min_out.focus(); });
  m.addEventListener('click', function (e) { if (e.target === m || e.target.closest('[data-close]')) m.hidden = true; });
  sf.addEventListener('submit', function (e) { e.preventDefault(); post({ action: 'settings', min_out: sf.min_out.value }).then(function (d) {
    if (!d.ok) throw new Error(d.msg); m.hidden = true; toast(d.msg);
  }).catch(function (err) { toast(err.message, false); }); });
})();
</script>
<script src="/assets/js/edu-dialog.js?v=<?= @filemtime(ROOT_PATH . '/assets/js/edu-dialog.js') ?: 1 ?>" defer></script>
</body>
</html>
