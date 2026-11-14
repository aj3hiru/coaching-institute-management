<?php
// All Students — search and filter by name / mobile / reg. no., class, course, batch, session, status, fee (due / paid),
// address or city and admission date; sort; page; export the same list as a PDF report. Row actions: edit, add course, mark as left.
require_once __DIR__ . '/components/guard.php';
require_once INCLUDES_PATH . '/institute.php';
$me = ci_page_start($pdo);
$L = ci_lists($pdo);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $sid = (int)($_POST['id'] ?? 0);
    if (($_POST['action'] ?? '') === 'left' && $sid && ci_perm($me, 'edit_student')) {
        $pdo->prepare("UPDATE ci_students SET status = 'left', left_on = CURDATE() WHERE id = ? AND status <> 'left'")->execute([$sid]);
        // their running courses end as "left" — the fee still due on them stays visible
        $pdo->prepare("UPDATE ci_enrollments SET status = 'left', ended_on = CURDATE() WHERE student_id = ? AND status = 'active'")->execute([$sid]);
        admin_log('ci_student', "Marked student #$sid as left");
        $nm = $pdo->prepare("SELECT name FROM ci_students WHERE id = ?"); $nm->execute([$sid]);
        $msg = ($nm->fetchColumn() ?: 'The student') . ' is marked as Left. Any fee still due stays on the record.';
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch') { header('Content-Type: application/json'); exit(json_encode(['ok' => true, 'msg' => $msg])); }
        admin_flash($msg);
    } elseif (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch') { header('Content-Type: application/json'); exit(json_encode(['ok' => false, 'msg' => 'Not allowed.'])); }
    header('Location: /admin/students.php' . (($_POST['back'] ?? '') !== '' ? '?' . preg_replace('/[^\w=&%.\-+]/', '', (string)$_POST['back']) : ''));
    exit;
}

$f = [
    'type' => isset(CI_STYPES[$_GET['type'] ?? '']) ? $_GET['type'] : '', // Institute / Online / Both
    'q' => trim((string)($_GET['q'] ?? '')),
    'class' => (int)($_GET['class'] ?? 0),
    'course' => (int)($_GET['course'] ?? 0),
    'batch' => (int)($_GET['batch'] ?? 0),
    'session' => (int)($_GET['session'] ?? 0),
    'status' => in_array($_GET['status'] ?? '', ['active', 'left', 'completed', 'all'], true) ? $_GET['status'] : 'active',
    'fee' => in_array($_GET['fee'] ?? '', ['due', 'paid', 'overdue'], true) ? $_GET['fee'] : '',
    'place' => trim((string)($_GET['place'] ?? '')),
    'from' => ci_clean_date($_GET['from'] ?? '') ?? '',
    'to' => ci_clean_date($_GET['to'] ?? '') ?? '',
    'sort' => in_array($_GET['sort'] ?? '', ['new', 'old', 'name', 'due', 'reg'], true) ? $_GET['sort'] : 'new',
];
if ($f['batch'] && isset($L['batches'][$f['batch']]) && !$f['course']) $f['course'] = (int)$L['batches'][$f['batch']]['course_id'];

$where = ['1'];
$args = [];
if ($sc = ci_scope_sql($me)) $where[] = $sc; // staff who work with only institute / only online students
if ($f['q'] !== '') {
    $where[] = '(s.name LIKE ? OR s.reg_no LIKE ? OR s.mobile LIKE ? OR s.alt_mobile LIKE ? OR s.father_name LIKE ? OR s.email LIKE ?)';
    array_push($args, ...array_fill(0, 6, '%' . $f['q'] . '%'));
}
if ($f['type'] !== '') { $where[] = 's.stype = ?'; $args[] = $f['type']; }
if ($f['class']) { $where[] = 's.class_id = ?'; $args[] = $f['class']; }
if ($f['status'] !== 'all') { $where[] = 's.status = ?'; $args[] = $f['status']; }
if ($f['place'] !== '') { $where[] = '(s.address LIKE ? OR s.city LIKE ? OR s.state LIKE ? OR s.pincode LIKE ?)'; array_push($args, ...array_fill(0, 4, '%' . $f['place'] . '%')); }
if ($f['from']) { $where[] = 's.admission_date >= ?'; $args[] = $f['from']; }
if ($f['to']) { $where[] = 's.admission_date <= ?'; $args[] = $f['to']; }
$ew = [];
$eargs = [];
if ($f['course']) { $ew[] = 'e2.course_id = ?'; $eargs[] = $f['course']; }
if ($f['batch']) { $ew[] = 'e2.batch_id = ?'; $eargs[] = $f['batch']; }
if ($f['session']) { $ew[] = 'e2.session_id = ?'; $eargs[] = $f['session']; }
if ($ew) { $where[] = 'EXISTS (SELECT 1 FROM ci_enrollments e2 WHERE e2.student_id = s.id AND e2.status <> \'dropped\' AND ' . implode(' AND ', $ew) . ')'; array_push($args, ...$eargs); }
$having = '';
if ($f['fee'] === 'due') $having = 'HAVING due > 0.004';
if ($f['fee'] === 'paid') $having = 'HAVING net > 0 AND due <= 0.004';
if ($f['fee'] === 'overdue') $having = 'HAVING overdue > 0';
$order = ['new' => 's.id DESC', 'old' => 's.id ASC', 'name' => 's.name ASC', 'due' => 'due DESC, s.name', 'reg' => 's.reg_no ASC'][$f['sort']];

$hasShop = (bool)$pdo->query("SHOW TABLES LIKE 'sh_access'")->fetch();
$sql = "SELECT s.*, c.name AS class_name,
    (SELECT COALESCE(SUM(CASE WHEN e.status = 'dropped' THEN 0 ELSE e.fee - e.discount END),0) FROM ci_enrollments e WHERE e.student_id = s.id) AS net,
    (SELECT COALESCE(SUM(" . CI_DUE_SQL . "),0) FROM ci_enrollments e WHERE e.student_id = s.id) AS due,
    (SELECT COALESCE(SUM(p.amount),0) FROM ci_payments p WHERE p.student_id = s.id) AS paid,
    (SELECT COUNT(*) FROM ci_enrollments e WHERE e.student_id = s.id AND e.status <> 'dropped' AND e.due_date IS NOT NULL AND e.due_date < CURDATE() AND " . CI_DUE_SQL . " > 0) AS overdue,
    (SELECT GROUP_CONCAT(CONCAT(co.name, IFNULL(CONCAT(' · ', b.name), '')) ORDER BY e.id SEPARATOR '||') FROM ci_enrollments e
        LEFT JOIN ci_courses co ON co.id = e.course_id LEFT JOIN ci_batches b ON b.id = e.batch_id WHERE e.student_id = s.id AND e.status <> 'dropped') AS courses" . ($hasShop ? ",
    (SELECT GROUP_CONCAT(p.title ORDER BY a.id SEPARATOR '||') FROM sh_users u JOIN sh_access a ON a.user_id = u.id AND a.revoked = 0 AND a.blocked = 0 JOIN sh_products p ON p.id = a.product_id WHERE u.student_id = s.id) AS online" : ", '' AS online") . "
    FROM ci_students s LEFT JOIN ci_classes c ON c.id = s.class_id
    WHERE " . implode(' AND ', $where) . " $having ORDER BY $order";

if (($_GET['export'] ?? '') === 'json') {
    // data for the PDF report (same filters as the list)
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $rows = [];
    foreach ($st as $r) $rows[] = ['reg' => $r['reg_no'], 'type' => CI_STYPES[$r['stype'] ?? 'institute'] ?? '', 'name' => $r['name'], 'father' => $r['father_name'], 'mobile' => $r['mobile'], 'class' => $r['class_name'],
        'courses' => str_replace('||', '; ', trim($r['courses'] . ($r['online'] ? '||Online: ' . $r['online'] : ''), '|')), 'admitted' => $r['admission_date'], 'status' => $r['status'],
        'fee' => round((float)$r['net'], 2), 'paid' => round((float)$r['paid'], 2), 'due' => round((float)$r['due'], 2)];
    $desc = [];
    if ($f['type'] !== '') $desc[] = 'Type: ' . CI_STYPES[$f['type']];
    if ($f['q'] !== '') $desc[] = 'Search: ' . $f['q'];
    if ($f['class'] && isset($L['classes'][$f['class']])) $desc[] = 'Class: ' . $L['classes'][$f['class']];
    if ($f['course'] && isset($L['courses'][$f['course']])) $desc[] = 'Course: ' . $L['courses'][$f['course']]['name'];
    if ($f['batch'] && isset($L['batches'][$f['batch']])) $desc[] = 'Batch: ' . $L['batches'][$f['batch']]['name'];
    if ($f['session'] && isset($L['sessions'][$f['session']])) $desc[] = 'Session: ' . $L['sessions'][$f['session']];
    $desc[] = 'Status: ' . ucfirst($f['status']);
    if ($f['fee']) $desc[] = 'Fee: ' . ['due' => 'Has due', 'paid' => 'Fully paid', 'overdue' => 'Due date passed'][$f['fee']];
    if ($f['place'] !== '') $desc[] = 'Address: ' . $f['place'];
    if ($f['from'] || $f['to']) $desc[] = 'Admitted: ' . ($f['from'] ?: '…') . ' to ' . ($f['to'] ?: '…');
    admin_log('ci_export', 'Students PDF (' . count($rows) . ' students)');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['kind' => 'students', 'institute' => ci_institute(), 'viewer' => ['name' => $me['username'], 'role' => $me['role']], 'generated' => date('d M Y, h:i A'),
        'filters' => $desc, 'rows' => $rows], JSON_UNESCAPED_UNICODE);
    exit;
}

$per = in_array((int)($_GET['per'] ?? 0), [10, 20, 50, 100, 1000], true) ? (int)$_GET['per'] : 20; // Show [N] entries
$cnt = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(due),0) FROM ($sql) t");
$cnt->execute($args);
[$total, $totalDue] = $cnt->fetch(PDO::FETCH_NUM);
$total = (int)$total;
$pages = max(1, (int)ceil($total / $per));
$page = min($pages, max(1, (int)($_GET['page'] ?? 1)));
$st = $pdo->prepare($sql . " LIMIT $per OFFSET " . (($page - 1) * $per));
$st->execute($args);
$students = $st->fetchAll(PDO::FETCH_ASSOC);

$qs = function (array $over = []) use ($f) {
    $p = array_filter(array_merge($f, $over), fn($v) => $v !== '' && $v !== 0 && $v !== null);
    if (($p['status'] ?? '') === 'active') unset($p['status']);
    if (($p['sort'] ?? '') === 'new') unset($p['sort']);
    return http_build_query($p);
};
$filtered = $f['type'] !== '' || $f['q'] !== '' || $f['class'] || $f['course'] || $f['batch'] || $f['session'] || $f['status'] !== 'active' || $f['fee'] || $f['place'] !== '' || $f['from'] || $f['to'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title>Students - <?= ci_e(edu_site_name()) ?></title>
<?php admin_shell_head(); ci_head(); ci_report_assets(); ?>
</head>
<body>
<?php admin_shell_open('Students', 'All students with search and filters', '<div class="ci-top-actions"><button type="button" class="ci-btn ci-ghost" data-report="students" data-src="/admin/students.php?' . ci_e($qs(['export' => 'json'])) . '"><i class="fas fa-file-pdf"></i> Export PDF</button>'
    . (ci_perm($me, 'add_student') ? '<a class="ci-btn" href="/admin/student-edit.php"><i class="fas fa-user-plus"></i> Add Student</a>' : '') . '</div>'); ?>
<div class="ci">
<?= admin_flash() ?>
<?php if ((int)($_GET['paid'] ?? 0)): ?><div class="ci-alert ci-alert-ok"><i class="fas fa-receipt"></i> <a href="/admin/ci-receipt.php?id=<?= (int)$_GET['paid'] ?>" target="_blank"><b>Print receipt</b></a></div><?php endif; ?>

<div class="ci-card"><div class="ci-card-b"><form method="get" class="ci-filters" id="ci-filter">
  <div class="ci-f"><label>Student type</label><select name="type"><option value="">All students</option><?php foreach (['institute' => 'Institute only', 'online' => 'Online only', 'both' => 'Both (institute + online)'] as $k => $l): ?><option value="<?= $k ?>" <?= $f['type'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
  <div class="ci-f" style="grid-column:span 2"><label>Search</label><input name="q" value="<?= ci_e($f['q']) ?>" placeholder="Name, mobile, reg. no., father's name, email"></div>
  <div class="ci-f"><label>Class</label><select name="class"><option value="0">All classes</option><?php foreach ($L['classes'] as $id => $n): ?><option value="<?= $id ?>" <?= $f['class'] === $id ? 'selected' : '' ?>><?= ci_e($n) ?></option><?php endforeach; ?></select></div>
  <div class="ci-f"><label>Course</label><select name="course" id="ci-f-course"><option value="0">All courses</option><?php foreach ($L['courses'] as $id => $c): ?><option value="<?= $id ?>" <?= $f['course'] === $id ? 'selected' : '' ?>><?= ci_e($c['name']) ?></option><?php endforeach; ?></select></div>
  <div class="ci-f"><label>Batch</label><select name="batch" id="ci-f-batch"><option value="0">All batches</option><?php foreach ($L['batches'] as $id => $b): ?><option value="<?= $id ?>" data-c="<?= (int)$b['course_id'] ?>" <?= $f['batch'] === $id ? 'selected' : '' ?>><?= ci_e($b['name'] . ($b['timing'] ? ' · ' . $b['timing'] : '')) ?></option><?php endforeach; ?></select></div>
  <div class="ci-f"><label>Session</label><select name="session"><option value="0">All sessions</option><?php foreach ($L['sessions'] as $id => $n): ?><option value="<?= $id ?>" <?= $f['session'] === $id ? 'selected' : '' ?>><?= ci_e($n) ?></option><?php endforeach; ?></select></div>
  <div class="ci-f"><label>Status</label><select name="status"><?php foreach (['active' => 'Active', 'completed' => 'Completed', 'left' => 'Left', 'all' => 'All'] as $k => $l): ?><option value="<?= $k ?>" <?= $f['status'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
  <div class="ci-f"><label>Fee status</label><select name="fee"><option value="">Any</option><option value="due" <?= $f['fee'] === 'due' ? 'selected' : '' ?>>Has due</option><option value="overdue" <?= $f['fee'] === 'overdue' ? 'selected' : '' ?>>Due date passed</option><option value="paid" <?= $f['fee'] === 'paid' ? 'selected' : '' ?>>Fully paid</option></select></div>
  <div class="ci-f"><label>Address / city / PIN</label><input name="place" value="<?= ci_e($f['place']) ?>" placeholder="e.g. Bettiah"></div>
  <div class="ci-f"><label>Admitted from</label><input type="date" name="from" value="<?= ci_e($f['from']) ?>"></div>
  <div class="ci-f"><label>Admitted to</label><input type="date" name="to" value="<?= ci_e($f['to']) ?>"></div>
  <div class="ci-f"><label>Sort</label><select name="sort"><?php foreach (['new' => 'Newest first', 'old' => 'Oldest first', 'name' => 'Name A–Z', 'reg' => 'Reg. no.', 'due' => 'Highest due'] as $k => $l): ?><option value="<?= $k ?>" <?= $f['sort'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
  <div class="ci-fbtn"><button class="ci-btn" type="submit"><i class="fas fa-filter"></i> Filter</button><?php if ($filtered): ?><a class="ci-btn ci-ghost" href="/admin/students.php">Clear</a><?php endif; ?></div>
</form></div></div>

<div class="ci-head ci-head-show"><div class="ci-sub"><?= number_format($total) ?> found<?= $totalDue > 0 ? ' · due ' . ci_money0($totalDue) : '' ?></div></div>
<div class="ci-card"><div class="ci-card-b ci-p0 ci-table-wrap"><table class="ci-table" data-sheet-total="<?= (int)$total ?>" data-sheet-page="<?= (int)$page ?>" data-sheet-per="<?= $per === 1000 ? 0 : (int)$per ?>">
<thead><tr><th>Student</th><th>Mobile</th><th>Class</th><th>Courses / batches</th><th>Admission</th><th class="ci-num">Fee due</th><th>Action</th></tr></thead><tbody>
<?php if (!$students): ?><tr><td colspan="7" class="ci-empty"><?= $filtered ? 'No students match these filters.' : 'No students yet. <a href="/admin/student-edit.php">Add the first student</a>.' ?></td></tr><?php endif; ?>
<?php $back = $qs(['page' => $page]); foreach ($students as $s): $sid = (int)$s['id']; ?>
<tr><td><div class="ci-person"><?= ci_avatar($s, 36) ?><div><a href="/admin/student-view.php?id=<?= $sid ?>"><?= ci_e($s['name']) ?></a><small><?= ci_e($s['reg_no']) ?></small></div></div></td>
<td><?= ci_tel($s['mobile']) ?></td>
<td><?= ci_e($s['class_name'] ?? '—') ?></td>
<td><?php $cs = array_filter(explode('||', (string)$s['courses'])); $os = array_filter(explode('||', (string)$s['online'])); echo ($cs || $os) ? implode('<br>', array_merge(array_map('ci_e', $cs), array_map(fn($t) => '<span class="ci-onl"><i class="fas fa-laptop"></i> ' . ci_e($t) . '</span>', $os))) : '<span class="ci-muted">—</span>'; ?></td>
<td class="ci-muted ci-nw"><?= ci_date($s['admission_date']) ?></td>
<td class="ci-num"><div class="ci-due-cell"><?php if ((float)$s['due'] > 0.004): ?><b class="ci-r"><?= ci_money0($s['due']) ?></b><?= $s['overdue'] ? ' <span class="ci-badge ci-b-amber">Late</span>' : '' ?><?php if (ci_perm($me, 'fees')): ?><?= ci_ico('rupee', null, 'Collect fee', 'button', ' data-collect-sid="' . $sid . '"') ?><?php endif; ?><?php elseif ((float)$s['net'] > 0): ?><span class="ci-badge ci-b-green">Paid</span><?php else: ?><span class="ci-muted">—</span><?php endif; ?></div></td>
<td><div class="ci-icos">
<?php if (ci_perm($me, 'edit_student')): ?><?= ci_ico('edit', '/admin/student-edit.php?id=' . $sid, 'Edit student') ?><?= ci_ico('book-plus', null, 'Add to course / batch', 'button', ' data-addc-sid="' . $sid . '"') ?>
<?php if ($s['status'] !== 'left'): ?><span class="ci-left-slot"><?= str_replace('class="ci-ico-form"', 'class="ci-ico-form" data-ajax="left"', ci_post_ico('logout', 'Mark as left', ['action' => 'left', 'id' => $sid, 'back' => $back], 'Mark ' . $s['name'] . ' as Left? Their running courses end as Left; any fee still due stays on the record.')) ?></span><?php else: ?><span class="ci-badge ci-b-grey">Left</span><?php endif; ?>
<?php else: ?><?= ci_ico('eye', '/admin/student-view.php?id=' . $sid, 'Open profile') ?><?php endif; ?>
</div></td></tr>
<?php endforeach; ?>
</tbody></table></div>
<?php /* pages: the sheet footer (admin-table.js) */ ?>
</div>
</div>
<?php require __DIR__ . '/components/ci-modals.php'; ?>
<?php admin_shell_close(); ?>
<script>
(function () {
  var c = document.getElementById('ci-f-course'), b = document.getElementById('ci-f-batch');
  function sync() { var v = c.value; [].forEach.call(b.options, function (o) { if (!o.dataset.c) return; o.hidden = v !== '0' && o.dataset.c !== v; if (o.hidden && o.selected) b.value = '0'; }); }
  c.addEventListener('change', sync); sync();
  // Mark as left — right here, without leaving the page
  document.addEventListener('submit', function (e) {
    var f = e.target.closest('form[data-ajax="left"]'); if (!f) return;
    e.preventDefault(); e.stopPropagation();
    if (f.onsubmit && f.onsubmit.call(f, e) === false) return; // the "Mark … as Left?" question
    var btn = f.querySelector('button'); btn.disabled = true;
    fetch(location.pathname, { method: 'POST', body: new FormData(f), credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) { btn.disabled = false; ciToast(d.msg || 'Could not mark as left.', false); return; }
        var slot = f.closest('.ci-left-slot'); slot.outerHTML = '<span class="ci-badge ci-b-grey">Left</span>';
        ciToast(d.msg);
      }).catch(function () { btn.disabled = false; ciToast('Could not mark as left. Please try again.', false); });
  }, true);
})();
</script>
</body>
</html>
