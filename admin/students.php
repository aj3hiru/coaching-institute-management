<?php
// All Students — search and filter by name / mobile / reg. no., class, course, batch, session, status, fee (due / paid),
// address or city and admission date; sort; page; and export the same list to Excel (CSV).
require_once __DIR__ . '/components/guard.php';
require_once INCLUDES_PATH . '/institute.php';
$me = ci_page_start($pdo);
$L = ci_lists($pdo);

$f = [
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
if ($f['q'] !== '') {
    $where[] = '(s.name LIKE ? OR s.reg_no LIKE ? OR s.mobile LIKE ? OR s.alt_mobile LIKE ? OR s.father_name LIKE ? OR s.email LIKE ?)';
    array_push($args, ...array_fill(0, 6, '%' . $f['q'] . '%'));
}
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

$sql = "SELECT s.*, c.name AS class_name,
    (SELECT COALESCE(SUM(CASE WHEN e.status = 'dropped' THEN 0 ELSE e.fee - e.discount END),0) FROM ci_enrollments e WHERE e.student_id = s.id) AS net,
    (SELECT COALESCE(SUM(" . CI_DUE_SQL . "),0) FROM ci_enrollments e WHERE e.student_id = s.id) AS due,
    (SELECT COALESCE(SUM(p.amount),0) FROM ci_payments p WHERE p.student_id = s.id) AS paid,
    (SELECT COUNT(*) FROM ci_enrollments e WHERE e.student_id = s.id AND e.status <> 'dropped' AND e.due_date IS NOT NULL AND e.due_date < CURDATE() AND " . CI_DUE_SQL . " > 0) AS overdue,
    (SELECT GROUP_CONCAT(CONCAT(co.name, IFNULL(CONCAT(' · ', b.name), '')) ORDER BY e.id SEPARATOR '||') FROM ci_enrollments e
        LEFT JOIN ci_courses co ON co.id = e.course_id LEFT JOIN ci_batches b ON b.id = e.batch_id WHERE e.student_id = s.id AND e.status <> 'dropped') AS courses
    FROM ci_students s LEFT JOIN ci_classes c ON c.id = s.class_id
    WHERE " . implode(' AND ', $where) . " $having ORDER BY $order";

if (($_GET['export'] ?? '') === 'csv') {
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $rows = [];
    foreach ($st as $r) {
        $rows[] = [$r['reg_no'], $r['name'], $r['father_name'], $r['mother_name'], $r['mobile'], $r['alt_mobile'], $r['email'], $r['dob'], $r['gender'], $r['class_name'],
            $r['school'], str_replace('||', '; ', (string)$r['courses']), $r['address'], $r['city'], $r['state'], $r['pincode'], $r['admission_date'], $r['status'],
            number_format((float)$r['net'], 2, '.', ''), number_format((float)$r['paid'], 2, '.', ''), number_format((float)$r['due'], 2, '.', '')];
    }
    admin_log('ci_export', 'Exported ' . count($rows) . ' students');
    ci_csv('students-' . date('Y-m-d') . '.csv', ['Reg. no.', 'Name', "Father's name", "Mother's name", 'Mobile', 'Alternate mobile', 'Email', 'Date of birth', 'Gender', 'Class',
        'School', 'Courses / batches', 'Address', 'City', 'State', 'PIN', 'Admission date', 'Status', 'Total fee', 'Paid', 'Due'], $rows);
}

$per = 30;
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
$filtered = $f['q'] !== '' || $f['class'] || $f['course'] || $f['batch'] || $f['session'] || $f['status'] !== 'active' || $f['fee'] || $f['place'] !== '' || $f['from'] || $f['to'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title>Students - <?= ci_e(edu_site_name()) ?></title>
<?php admin_shell_head(); ci_head(); ?>
</head>
<body>
<?php admin_shell_open('Students', 'All students with search and filters'); ?>
<div class="ci">
<?= admin_flash() ?>
<div class="ci-head"><div><h2>Students</h2><div class="ci-sub"><?= number_format($total) ?> found<?= $totalDue > 0 ? ' · due ' . ci_money0($totalDue) : '' ?></div></div>
<div class="ci-actions"><a class="ci-btn ci-ghost" href="?<?= $qs(['export' => 'csv']) ?>"><i class="fas fa-file-excel"></i> Export</a><a class="ci-btn" href="/admin/student-edit.php"><i class="fas fa-user-plus"></i> Add Student</a></div></div>

<div class="ci-card"><div class="ci-card-b"><form method="get" class="ci-filters" id="ci-filter">
  <div class="ci-f" style="grid-column:span 2"><label>Search</label><input name="q" value="<?= ci_e($f['q']) ?>" placeholder="Name, mobile, reg. no., father's name, email"></div>
  <div class="ci-f"><label>Course</label><select name="course" id="ci-f-course"><option value="0">All courses</option><?php foreach ($L['courses'] as $id => $c): ?><option value="<?= $id ?>" <?= $f['course'] === $id ? 'selected' : '' ?>><?= ci_e($c['name']) ?></option><?php endforeach; ?></select></div>
  <div class="ci-f"><label>Batch</label><select name="batch" id="ci-f-batch"><option value="0">All batches</option><?php foreach ($L['batches'] as $id => $b): ?><option value="<?= $id ?>" data-c="<?= (int)$b['course_id'] ?>" <?= $f['batch'] === $id ? 'selected' : '' ?>><?= ci_e($b['name'] . ($b['timing'] ? ' · ' . $b['timing'] : '')) ?></option><?php endforeach; ?></select></div>
  <div class="ci-f"><label>Session</label><select name="session"><option value="0">All sessions</option><?php foreach ($L['sessions'] as $id => $n): ?><option value="<?= $id ?>" <?= $f['session'] === $id ? 'selected' : '' ?>><?= ci_e($n) ?></option><?php endforeach; ?></select></div>
  <div class="ci-f"><label>Status</label><select name="status"><?php foreach (['active' => 'Active', 'completed' => 'Completed', 'left' => 'Left', 'all' => 'All'] as $k => $l): ?><option value="<?= $k ?>" <?= $f['status'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
  <div class="ci-f"><label>Fee</label><select name="fee"><option value="">Any</option><option value="due" <?= $f['fee'] === 'due' ? 'selected' : '' ?>>Has due</option><option value="overdue" <?= $f['fee'] === 'overdue' ? 'selected' : '' ?>>Due date passed</option><option value="paid" <?= $f['fee'] === 'paid' ? 'selected' : '' ?>>Fully paid</option></select></div>
  <div class="ci-f"><label>Address / city / PIN</label><input name="place" value="<?= ci_e($f['place']) ?>" placeholder="e.g. Bettiah"></div>
  <div class="ci-f"><label>Admitted from</label><input type="date" name="from" value="<?= ci_e($f['from']) ?>"></div>
  <div class="ci-f"><label>Admitted to</label><input type="date" name="to" value="<?= ci_e($f['to']) ?>"></div>
  <div class="ci-f"><label>Sort</label><select name="sort"><?php foreach (['new' => 'Newest first', 'old' => 'Oldest first', 'name' => 'Name A–Z', 'reg' => 'Reg. no.', 'due' => 'Highest due'] as $k => $l): ?><option value="<?= $k ?>" <?= $f['sort'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
  <div class="ci-fbtn"><button class="ci-btn" type="submit"><i class="fas fa-filter"></i> Filter</button><?php if ($filtered): ?><a class="ci-btn ci-ghost" href="/admin/students.php">Clear</a><?php endif; ?></div>
</form></div></div>

<div class="ci-card"><div class="ci-card-b ci-p0 ci-table-wrap"><table class="ci-table">
<thead><tr><th>Student</th><th>Mobile</th><th>Courses / batches</th><th>Admission</th><th>Fee</th><th>Status</th></tr></thead><tbody>
<?php if (!$students): ?><tr><td colspan="6" class="ci-empty"><?= $filtered ? 'No students match these filters.' : 'No students yet. <a href="/admin/student-edit.php">Add the first student</a>.' ?></td></tr><?php endif; ?>
<?php foreach ($students as $s): ?>
<tr><td><div class="ci-person"><?= ci_avatar($s, 36) ?><div><a href="/admin/student-view.php?id=<?= (int)$s['id'] ?>"><?= ci_e($s['name']) ?></a><small><?= ci_e($s['reg_no']) ?><?= $s['father_name'] ? ' · ' . ci_e($s['father_name']) : '' ?></small></div></div></td>
<td><a href="tel:<?= ci_e($s['mobile']) ?>"><?= ci_e($s['mobile']) ?></a><?= $s['alt_mobile'] ? '<small class="ci-muted" style="display:block">' . ci_e($s['alt_mobile']) . '</small>' : '' ?></td>
<td><?php $cs = array_filter(explode('||', (string)$s['courses'])); echo $cs ? implode('<br>', array_map('ci_e', $cs)) : '<span class="ci-muted">—</span>'; ?></td>
<td class="ci-muted"><?= ci_date($s['admission_date']) ?><?= $s['city'] ? '<small style="display:block">' . ci_e($s['city']) . '</small>' : '' ?></td>
<td><?= ci_fee_badge((float)$s['due'], (float)$s['net']) ?><?= $s['overdue'] ? ' <span class="ci-badge ci-b-amber">Late</span>' : '' ?></td>
<td><?= ci_status_badge($s['status']) ?></td></tr>
<?php endforeach; ?>
</tbody></table></div>
<?php if ($pages > 1): ?><div class="ci-pager"><span>Page <?= $page ?> of <?= $pages ?></span><div class="ci-pg">
<?php for ($i = max(1, $page - 3); $i <= min($pages, $page + 3); $i++): ?><?= $i === $page ? '<span class="on">' . $i . '</span>' : '<a href="?' . $qs(['page' => $i]) . '">' . $i . '</a>' ?><?php endfor; ?>
</div></div><?php endif; ?>
</div>
</div>
<?php admin_shell_close(); ?>
<script>
(function () {
  var c = document.getElementById('ci-f-course'), b = document.getElementById('ci-f-batch');
  function sync() { var v = c.value; [].forEach.call(b.options, function (o) { if (!o.dataset.c) return; o.hidden = v !== '0' && o.dataset.c !== v; if (o.hidden && o.selected) b.value = '0'; }); }
  c.addEventListener('change', sync); sync();
})();
</script>
</body>
</html>
