<?php
// Fees & Dues — Payments: every fee received (dates, method, course, batch), with totals and export.
//              Dues: who still has to pay, per course/batch, with the next due date (late ones marked).
require_once __DIR__ . '/components/guard.php';
require_once INCLUDES_PATH . '/institute.php';
$me = ci_page_start($pdo, ["fees"]);
$L = ci_lists($pdo);
$tab = ($_GET['tab'] ?? '') === 'dues' ? 'dues' : 'payments';
$q = trim((string)($_GET['q'] ?? ''));
$fCourse = (int)($_GET['course'] ?? 0);
$fBatch = (int)($_GET['batch'] ?? 0);
$fSession = (int)($_GET['session'] ?? 0);
$csv = ($_GET['export'] ?? '') === 'csv';

if ($tab === 'payments') {
    $from = ci_clean_date($_GET['from'] ?? '') ?? date('Y-m-01');
    $to = ci_clean_date($_GET['to'] ?? '') ?? date('Y-m-d');
    if ($to < $from) [$from, $to] = [$to, $from];
    $method = isset(CI_METHODS[$_GET['method'] ?? '']) ? $_GET['method'] : '';
    $w = ['p.paid_on BETWEEN ? AND ?'];
    $a = [$from, $to];
    if ($method) { $w[] = '(p.method = ? OR p.parts LIKE ?)'; $a[] = $method; $a[] = '%"m":"' . $method . '"%'; }
    if ($fCourse) { $w[] = 'e.course_id = ?'; $a[] = $fCourse; }
    if ($fBatch) { $w[] = 'e.batch_id = ?'; $a[] = $fBatch; }
    if ($fSession) { $w[] = 'e.session_id = ?'; $a[] = $fSession; }
    if ($q !== '') { $w[] = '(s.name LIKE ? OR s.reg_no LIKE ? OR s.mobile LIKE ? OR p.receipt_no LIKE ?)'; array_push($a, ...array_fill(0, 4, "%$q%")); }
    $sql = "SELECT p.*, s.name, s.reg_no, s.mobile, s.photo, c.name AS course_name, b.name AS batch_name, u.username
        FROM ci_payments p JOIN ci_students s ON s.id = p.student_id LEFT JOIN ci_enrollments e ON e.id = p.enrollment_id
        LEFT JOIN ci_courses c ON c.id = e.course_id LEFT JOIN ci_batches b ON b.id = e.batch_id LEFT JOIN users u ON u.id = p.created_by
        WHERE " . implode(' AND ', $w) . " ORDER BY p.paid_on DESC, p.id DESC";
    $st = $pdo->prepare($sql);
    $st->execute($a);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if ($csv) {
        ci_csv("fee-payments-$from-to-$to.csv", ['Receipt', 'Date', 'Reg. no.', 'Student', 'Mobile', 'Course', 'Batch', 'Paid by', 'Amount', 'Note', 'Entered by'],
            array_map(fn($r) => [$r['receipt_no'], $r['paid_on'], $r['reg_no'], $r['name'], $r['mobile'], $r['course_name'], $r['batch_name'], ci_pay_mode($r),
                number_format((float)$r['amount'], 2, '.', ''), $r['note'], $r['username']], $rows));
    }
    $byMethod = [];
    foreach ($rows as $r) foreach (ci_pay_split($r) as $m => $v) $byMethod[$m] = ($byMethod[$m] ?? 0) + $v; // split payments counted per mode
    $sum = array_sum($byMethod);
} else {
    $late = !empty($_GET['late']);
    $w = ["e.status <> 'dropped'", CI_DUE_SQL . ' > 0.004'];
    $a = [];
    if ($fCourse) { $w[] = 'e.course_id = ?'; $a[] = $fCourse; }
    if ($fBatch) { $w[] = 'e.batch_id = ?'; $a[] = $fBatch; }
    if ($fSession) { $w[] = 'e.session_id = ?'; $a[] = $fSession; }
    if ($late) $w[] = 'e.due_date IS NOT NULL AND e.due_date < CURDATE()';
    if ($q !== '') { $w[] = '(s.name LIKE ? OR s.reg_no LIKE ? OR s.mobile LIKE ?)'; array_push($a, ...array_fill(0, 3, "%$q%")); }
    $sql = "SELECT e.*, s.id AS sid, s.name, s.reg_no, s.mobile, s.alt_mobile, s.photo, s.status AS sstatus, c.name AS course_name, b.name AS batch_name,
        " . CI_PAID_SQL . " AS paid, " . CI_DUE_SQL . " AS due
        FROM ci_enrollments e JOIN ci_students s ON s.id = e.student_id LEFT JOIN ci_courses c ON c.id = e.course_id LEFT JOIN ci_batches b ON b.id = e.batch_id
        WHERE " . implode(' AND ', $w) . " ORDER BY (e.due_date IS NULL), e.due_date, due DESC";
    $st = $pdo->prepare($sql);
    $st->execute($a);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if ($csv) {
        ci_csv('fee-dues-' . date('Y-m-d') . '.csv', ['Reg. no.', 'Student', 'Mobile', 'Alternate mobile', 'Course', 'Batch', 'Fee', 'Discount', 'Paid', 'Due', 'Next due date'],
            array_map(fn($r) => [$r['reg_no'], $r['name'], $r['mobile'], $r['alt_mobile'], $r['course_name'], $r['batch_name'], number_format((float)$r['fee'], 2, '.', ''),
                number_format((float)$r['discount'], 2, '.', ''), number_format((float)$r['paid'], 2, '.', ''), number_format((float)$r['due'], 2, '.', ''), $r['due_date']], $rows));
    }
    $sum = array_sum(array_column($rows, 'due'));
}
$qs = function (array $over = []) {
    $p = array_merge($_GET, $over);
    unset($p['page']);
    return http_build_query(array_filter($p, fn($v) => $v !== '' && $v !== '0' && $v !== 0));
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title>Fees &amp; Dues - <?= ci_e(edu_site_name()) ?></title>
<?php admin_shell_head(); ci_head(); ?>
</head>
<body>
<?php admin_shell_open('Fees &amp; Dues', 'Fee received and fee still to come'); ?>
<div class="ci">
<?= admin_flash() ?>
<?php if ((int)($_GET['paid'] ?? 0)): ?><div class="ci-alert ci-alert-ok"><i class="fas fa-receipt"></i> <a href="/admin/ci-receipt.php?id=<?= (int)$_GET['paid'] ?>" target="_blank"><b>Print receipt</b></a></div><?php endif; ?>
<div class="ci-tabs"><a href="/admin/ci-fees.php" class="<?= $tab === 'payments' ? 'on' : '' ?>">Fee Payments</a><a href="?tab=dues" class="<?= $tab === 'dues' ? 'on' : '' ?>">Dues</a></div>

<div class="ci-card"><div class="ci-card-b"><form method="get" class="ci-filters">
  <?php if ($tab === 'dues'): ?><input type="hidden" name="tab" value="dues"><?php endif; ?>
  <div class="ci-f"><label>Search</label><input name="q" value="<?= ci_e($q) ?>" placeholder="Name, mobile, reg. no.<?= $tab === 'payments' ? ', receipt' : '' ?>"></div>
  <?php if ($tab === 'payments'): ?>
  <div class="ci-f"><label>From</label><input type="date" name="from" value="<?= ci_e($from) ?>"></div>
  <div class="ci-f"><label>To</label><input type="date" name="to" value="<?= ci_e($to) ?>"></div>
  <div class="ci-f"><label>Paid by</label><select name="method"><option value="">Any</option><?php foreach (CI_METHODS as $k => $l): ?><option value="<?= $k ?>" <?= $method === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
  <?php endif; ?>
  <div class="ci-f"><label>Course</label><select name="course"><option value="0">All courses</option><?php foreach ($L['courses'] as $id => $c): ?><option value="<?= $id ?>" <?= $fCourse === $id ? 'selected' : '' ?>><?= ci_e($c['name']) ?></option><?php endforeach; ?></select></div>
  <div class="ci-f"><label>Batch</label><select name="batch"><option value="0">All batches</option><?php foreach ($L['batches'] as $id => $b): ?><option value="<?= $id ?>" <?= $fBatch === $id ? 'selected' : '' ?>><?= ci_e($b['name']) ?></option><?php endforeach; ?></select></div>
  <div class="ci-f"><label>Session</label><select name="session"><option value="0">All sessions</option><?php foreach ($L['sessions'] as $id => $n): ?><option value="<?= $id ?>" <?= $fSession === $id ? 'selected' : '' ?>><?= ci_e($n) ?></option><?php endforeach; ?></select></div>
  <?php if ($tab === 'dues'): ?><div class="ci-f"><label style="display:flex;gap:6px;align-items:center;height:38px;font-weight:400"><input type="checkbox" name="late" value="1" style="width:auto;height:auto" <?= $late ? 'checked' : '' ?>> Only due date passed</label></div><?php endif; ?>
  <div class="ci-fbtn"><button class="ci-btn" type="submit"><i class="fas fa-filter"></i> Filter</button><a class="ci-btn ci-ghost" href="?<?= $qs(['export' => 'csv']) ?>"><i class="fas fa-file-excel"></i> Export</a></div>
</form></div></div>

<?php if ($tab === 'payments'): ?>
<div class="ci-stats">
  <div class="ci-stat"><i class="fas fa-indian-rupee-sign ci-i-green"></i><div><b><?= ci_money0($sum) ?></b><small><?= count($rows) ?> payments · <?= ci_date($from) ?> – <?= ci_date($to) ?></small></div></div>
  <?php foreach ($byMethod as $m => $v): ?><div class="ci-stat"><i class="fas fa-wallet ci-i-violet"></i><div><b><?= ci_money0($v) ?></b><small><?= ci_e(CI_METHODS[$m] ?? $m) ?></small></div></div><?php endforeach; ?>
</div>
<div class="ci-card"><div class="ci-card-b ci-p0 ci-table-wrap"><table class="ci-table">
<thead><tr><th>Receipt</th><th>Date</th><th>Student</th><th>Course / batch</th><th>Paid by</th><th class="ci-num">Amount</th><th></th></tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="7" class="ci-empty">No payments in this period.</td></tr><?php endif; ?>
<?php foreach ($rows as $r): ?>
<tr><td><b><?= ci_e($r['receipt_no']) ?></b></td><td><?= ci_date($r['paid_on']) ?></td>
<td><div class="ci-person"><?= ci_avatar($r, 30) ?><div><a href="/admin/student-view.php?id=<?= (int)$r['student_id'] ?>"><?= ci_e($r['name']) ?></a><small><?= ci_e($r['reg_no']) ?> · <?= ci_e($r['mobile']) ?></small></div></div></td>
<td><?= ci_e($r['course_name'] ?? '—') ?><?= $r['batch_name'] ? '<small class="ci-muted" style="display:block">' . ci_e($r['batch_name']) . '</small>' : '' ?></td>
<td><?= ci_e(ci_pay_mode($r)) ?><?= $r['note'] ? '<small class="ci-muted" style="display:block">' . ci_e($r['note']) . '</small>' : '' ?><?= $r['username'] ? '<small class="ci-by">by ' . ci_e($r['username']) . '</small>' : '' ?></td>
<td class="ci-num"><b><?= ci_money($r['amount']) ?></b></td><td><a href="/admin/ci-receipt.php?id=<?= (int)$r['id'] ?>" target="_blank" class="ci-ico" aria-label="Print receipt"><?= ci_svg('print') ?></a></td></tr>
<?php endforeach; ?>
<?php if ($rows): ?><tr class="ci-total-row"><td colspan="5">Total</td><td class="ci-num"><?= ci_money($sum) ?></td><td></td></tr><?php endif; ?>
</tbody></table></div></div>

<?php else: ?>
<div class="ci-stats">
  <div class="ci-stat"><i class="fas fa-hourglass-half ci-i-red"></i><div><b><?= ci_money0($sum) ?></b><small>due from <?= count(array_unique(array_column($rows, 'sid'))) ?> students</small></div></div>
  <div class="ci-stat"><i class="fas fa-calendar-xmark ci-i-amber"></i><div><b><?= count(array_filter($rows, fn($r) => $r['due_date'] && $r['due_date'] < date('Y-m-d'))) ?></b><small>due date passed</small></div></div>
</div>
<div class="ci-card"><div class="ci-card-b ci-p0 ci-table-wrap"><table class="ci-table">
<thead><tr><th>Student</th><th>Course / batch</th><th class="ci-num">Fee</th><th class="ci-num">Paid</th><th class="ci-num">Due</th><th>Next due date</th><th></th></tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="7" class="ci-empty">No dues. Everyone has paid.</td></tr><?php endif; ?>
<?php foreach ($rows as $r): $lateRow = $r['due_date'] && $r['due_date'] < date('Y-m-d'); ?>
<tr><td><div class="ci-person"><?= ci_avatar($r, 30) ?><div><a href="/admin/student-view.php?id=<?= (int)$r['sid'] ?>"><?= ci_e($r['name']) ?></a><small><?= ci_e($r['reg_no']) ?> · <a href="tel:<?= ci_e($r['mobile']) ?>"><?= ci_e($r['mobile']) ?></a></small></div></div></td>
<td><?= ci_e($r['course_name'] ?? '—') ?><?= $r['batch_name'] ? '<small class="ci-muted" style="display:block">' . ci_e($r['batch_name']) . '</small>' : '' ?></td>
<td class="ci-num"><?= ci_money0((float)$r['fee'] - (float)$r['discount']) ?></td><td class="ci-num"><?= ci_money0($r['paid']) ?></td>
<td class="ci-num"><b style="color:#b91c1c"><?= ci_money0($r['due']) ?></b></td>
<td><?= $r['due_date'] ? ($lateRow ? '<span class="ci-badge ci-b-amber">' . ci_date($r['due_date']) . '</span>' : ci_date($r['due_date'])) : '<span class="ci-muted">—</span>' ?></td>
<td><?= ci_ico('rupee', null, 'Collect fee', 'button', ' data-collect-sid="' . (int)$r['sid'] . '" data-eid="' . (int)$r['id'] . '"') ?></td></tr>
<?php endforeach; ?>
<?php if ($rows): ?><tr class="ci-total-row"><td colspan="4">Total due</td><td class="ci-num"><?= ci_money($sum) ?></td><td colspan="2"></td></tr><?php endif; ?>
</tbody></table></div></div>
<?php endif; ?>
</div>
<?php require __DIR__ . '/components/ci-modals.php'; ?>
<?php admin_shell_close(); ?>
</body>
</html>
