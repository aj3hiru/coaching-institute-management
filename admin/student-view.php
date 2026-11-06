<?php
// One student: details, fee summary, courses/batches with paid & due, payments (receipts), and "Collect fee".
require_once __DIR__ . '/components/guard.php';
require_once INCLUDES_PATH . '/institute.php';
$me = ci_page_start($pdo);
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$q = $pdo->prepare("SELECT s.*, c.name AS class_name FROM ci_students s LEFT JOIN ci_classes c ON c.id = s.class_id WHERE s.id = ?");
$q->execute([$id]);
$s = $q->fetch(PDO::FETCH_ASSOC);
if (!$s) { admin_flash('Student not found.', 'error'); header('Location: /admin/students.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $a = (string)($_POST['action'] ?? '');
    if ($a === 'pay') {
        $eid = (int)($_POST['enrollment_id'] ?? 0);
        $own = $pdo->prepare("SELECT COUNT(*) FROM ci_enrollments WHERE id = ? AND student_id = ?");
        $own->execute([$eid, $id]);
        $res = (int)$own->fetchColumn()
            ? ci_add_payment($pdo, $eid, ci_amount($_POST['amount'] ?? 0), (string)($_POST['method'] ?? 'Cash'), ci_clean_date($_POST['paid_on'] ?? ''), trim((string)($_POST['note'] ?? '')), (int)$me['id'])
            : "Choose one of this student's courses.";
        if (is_string($res)) { admin_flash($res, 'error'); header("Location: /admin/student-view.php?id=$id#pay"); exit; }
        $nd = ci_clean_date($_POST['next_due'] ?? '');
        $pdo->prepare("UPDATE ci_enrollments SET due_date = ? WHERE id = ?")->execute([$nd, $eid]);
        admin_log('ci_payment', "Fee " . ci_money($_POST['amount']) . " from {$s['reg_no']} {$s['name']} ({$res[1]})");
        admin_flash('Fee saved. Receipt ' . $res[1] . '.');
        header("Location: /admin/student-view.php?id=$id&receipt=" . $res[0]);
        exit;
    }
    if ($a === 'pay_delete' && $me['role'] === 'admin') {
        $pid = (int)($_POST['payment_id'] ?? 0);
        $p = $pdo->prepare("SELECT receipt_no, amount FROM ci_payments WHERE id = ? AND student_id = ?"); $p->execute([$pid, $id]);
        if ($row = $p->fetch(PDO::FETCH_ASSOC)) {
            $pdo->prepare("DELETE FROM ci_payments WHERE id = ?")->execute([$pid]);
            admin_log('ci_payment_delete', "Deleted receipt {$row['receipt_no']} (" . ci_money($row['amount']) . ") of {$s['reg_no']}");
            admin_flash('Payment ' . $row['receipt_no'] . ' deleted.');
        }
        header("Location: /admin/student-view.php?id=$id");
        exit;
    }
    if ($a === 'delete' && in_array($me['role'], ['admin', 'editor'], true)) {
        $n = (int)$pdo->query("SELECT COUNT(*) FROM ci_payments WHERE student_id = $id")->fetchColumn();
        if ($n) { admin_flash('This student has fee payments, so they cannot be deleted. Set the status to Left instead.', 'error'); header("Location: /admin/student-view.php?id=$id"); exit; }
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM ci_enrollments WHERE student_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM ci_students WHERE id = ?")->execute([$id]);
        $pdo->commit();
        ci_delete_photo($s['photo'] ?? null);
        admin_log('ci_student_delete', "Deleted student {$s['reg_no']} {$s['name']}");
        admin_flash('Student ' . $s['reg_no'] . ' deleted.');
        header('Location: /admin/students.php');
        exit;
    }
    header("Location: /admin/student-view.php?id=$id");
    exit;
}

$enr = ci_enrollments($pdo, $id);
$tot = ci_student_totals($pdo, $id);
$pq = $pdo->prepare("SELECT p.*, c.name AS course_name, u.username FROM ci_payments p LEFT JOIN ci_enrollments e ON e.id = p.enrollment_id
    LEFT JOIN ci_courses c ON c.id = e.course_id LEFT JOIN users u ON u.id = p.created_by WHERE p.student_id = ? ORDER BY p.paid_on DESC, p.id DESC");
$pq->execute([$id]);
$pays = $pq->fetchAll(PDO::FETCH_ASSOC);
$payable = array_values(array_filter($enr, fn($e) => $e['status'] !== 'dropped' && (float)$e['due'] > 0.004));
$newReceipt = (int)($_GET['receipt'] ?? 0);
$kv = [
    "Father's name" => $s['father_name'], "Mother's name" => $s['mother_name'],
    'Mobile' => $s['mobile'] ? '<a href="tel:' . ci_e($s['mobile']) . '">' . ci_e($s['mobile']) . '</a>' : '', 'Alternate mobile' => $s['alt_mobile'] ? '<a href="tel:' . ci_e($s['alt_mobile']) . '">' . ci_e($s['alt_mobile']) . '</a>' : '',
    'Email' => $s['email'] ? '<a href="mailto:' . ci_e($s['email']) . '">' . ci_e($s['email']) . '</a>' : '',
    'Date of birth' => $s['dob'] ? ci_date($s['dob']) . ' (' . (new DateTime($s['dob']))->diff(new DateTime())->y . ' yrs)' : '', 'Gender' => $s['gender'],
    'Class' => $s['class_name'], 'School / College' => $s['school'],
    'Address' => trim(implode(', ', array_filter([$s['address'], $s['city'], $s['state'], $s['pincode']]))),
    'Admission date' => ci_date($s['admission_date']), 'Notes' => $s['notes'] ? nl2br(ci_e($s['notes'])) : '',
];
$raw = ['Mobile', 'Alternate mobile', 'Email', 'Notes'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title><?= ci_e($s['name']) ?> - <?= ci_e(edu_site_name()) ?></title>
<?php admin_shell_head(); ci_head(); ?>
</head>
<body>
<?php admin_shell_open('Student', ci_e($s['reg_no'])); ?>
<div class="ci">
<?= admin_flash() ?>
<?php if ($newReceipt): ?><div class="ci-alert ci-alert-ok"><i class="fas fa-receipt"></i> Payment saved. <a href="/admin/ci-receipt.php?id=<?= $newReceipt ?>" target="_blank"><b>Print receipt</b></a></div><?php endif; ?>

<div class="ci-card"><div class="ci-card-b">
  <div class="ci-head" style="margin:0">
    <div class="ci-profile"><?= ci_avatar($s, 76) ?><div><h2><?= ci_e($s['name']) ?></h2><div class="ci-muted"><?= ci_e($s['reg_no']) ?> · <?= ci_status_badge($s['status']) ?><?= $s['class_name'] ? ' · ' . ci_e($s['class_name']) : '' ?></div></div></div>
    <div class="ci-actions">
      <?php if ($payable): ?><a class="ci-btn" href="#pay"><i class="fas fa-indian-rupee-sign"></i> Collect Fee</a><?php endif; ?>
      <a class="ci-btn ci-ghost" href="/admin/student-edit.php?id=<?= $id ?>"><i class="fas fa-pen"></i> Edit</a>
      <?php if (!$pays && in_array($me['role'], ['admin', 'editor'], true)): ?><form method="post" onsubmit="return confirm('Delete student <?= ci_e(addslashes($s['name'])) ?>? This cannot be undone.')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $id ?>"><button class="ci-btn ci-danger" type="submit"><i class="fas fa-trash"></i> Delete</button></form><?php endif; ?>
    </div>
  </div>
</div></div>

<div class="ci-sum" style="margin-bottom:16px">
  <div><small>Total fee (after discount)</small><b><?= ci_money($tot['net']) ?></b></div>
  <div><small>Paid</small><b style="color:#047857"><?= ci_money($tot['paid']) ?></b></div>
  <div><small>Due</small><b style="color:<?= $tot['due'] > 0 ? '#b91c1c' : '#047857' ?>"><?= ci_money($tot['due']) ?></b></div>
</div>

<div class="ci-grid2">
<div class="ci-card"><div class="ci-card-h"><h3>Details</h3></div><div class="ci-card-b"><dl class="ci-kv">
<?php foreach ($kv as $k => $val): if ($val === null || $val === '') continue; ?><dt><?= $k ?></dt><dd><?= in_array($k, $raw, true) ? $val : ci_e($val) ?></dd><?php endforeach; ?>
</dl></div></div>

<div>
<div class="ci-card"><div class="ci-card-h"><h3>Courses &amp; batches</h3><a href="/admin/student-edit.php?id=<?= $id ?>">Add / change</a></div><div class="ci-card-b ci-p0 ci-table-wrap"><table class="ci-table">
<thead><tr><th>Course / batch</th><th class="ci-num">Fee</th><th class="ci-num">Paid</th><th class="ci-num">Due</th></tr></thead><tbody>
<?php if (!$enr): ?><tr><td colspan="4" class="ci-empty">Not enrolled in any course yet.</td></tr><?php endif; ?>
<?php foreach ($enr as $e): $net = (float)$e['fee'] - (float)$e['discount']; ?>
<tr><td><b><?= ci_e($e['course_name'] ?? '—') ?></b> <?= $e['status'] !== 'active' ? ci_status_badge($e['status']) : '' ?>
<small class="ci-muted" style="display:block"><?= ci_e(implode(' · ', array_filter([$e['batch_name'], $e['timing'], $e['session_name']]))) ?: 'No batch' ?><?= $e['due_date'] && (float)$e['due'] > 0 ? ' · next due ' . ci_date($e['due_date']) : '' ?></small></td>
<td class="ci-num"><?= ci_money0($net) ?><?= (float)$e['discount'] > 0 ? '<small class="ci-muted" style="display:block">−' . ci_money0($e['discount']) . ' disc.</small>' : '' ?></td>
<td class="ci-num"><?= ci_money0($e['paid']) ?></td>
<td class="ci-num"><?= (float)$e['due'] > 0 ? '<b style="color:#b91c1c">' . ci_money0($e['due']) . '</b>' : '<span class="ci-badge ci-b-green">Paid</span>' ?></td></tr>
<?php endforeach; ?>
</tbody></table></div></div>

<?php if ($payable): ?>
<div class="ci-card" id="pay"><div class="ci-card-h"><h3>Collect fee</h3></div><div class="ci-card-b">
<form method="post" class="ci-inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="pay"><input type="hidden" name="id" value="<?= $id ?>">
  <div class="ci-f" style="grid-column:1/-1"><label>For</label><select name="enrollment_id" id="ci-pay-for" required><?php foreach ($payable as $e): ?><option value="<?= (int)$e['id'] ?>" data-due="<?= (float)$e['due'] ?>"><?= ci_e($e['course_name'] . ($e['batch_name'] ? ' · ' . $e['batch_name'] : '')) ?> — due <?= ci_money0($e['due']) ?></option><?php endforeach; ?></select></div>
  <div class="ci-f"><label>Amount (₹) <span class="ci-req">*</span></label><input type="number" name="amount" id="ci-pay-amt" min="1" step="0.01" required value="<?= (float)$payable[0]['due'] ?>"></div>
  <div class="ci-f"><label>Paid by</label><select name="method"><?php foreach (CI_METHODS as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select></div>
  <div class="ci-f"><label>Date</label><input type="date" name="paid_on" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>"></div>
  <div class="ci-f"><label>Next due date</label><input type="date" name="next_due"><span class="ci-hint">if fee is still left</span></div>
  <div class="ci-f" style="grid-column:1/-1"><label>Note</label><input name="note" maxlength="250" placeholder="e.g. 2nd instalment, UPI ref no."></div>
  <div class="ci-f"><button class="ci-btn" type="submit"><i class="fas fa-check"></i> Save &amp; get receipt</button></div>
</form></div></div>
<?php endif; ?>
</div>
</div>

<div class="ci-card"><div class="ci-card-h"><h3>Payments</h3><span class="ci-muted"><?= count($pays) ?> receipts</span></div><div class="ci-card-b ci-p0 ci-table-wrap"><table class="ci-table">
<thead><tr><th>Receipt</th><th>Date</th><th>For</th><th>Paid by</th><th>Note</th><th class="ci-num">Amount</th><th></th></tr></thead><tbody>
<?php if (!$pays): ?><tr><td colspan="7" class="ci-empty">No payments yet.</td></tr><?php endif; ?>
<?php foreach ($pays as $p): ?>
<tr><td><b><?= ci_e($p['receipt_no']) ?></b></td><td><?= ci_date($p['paid_on']) ?></td><td><?= ci_e($p['course_name'] ?? '—') ?></td>
<td><?= ci_e(CI_METHODS[$p['method']] ?? $p['method']) ?></td><td class="ci-muted"><?= ci_e($p['note'] ?? '') ?><?= $p['username'] ? '<small style="display:block">by ' . ci_e($p['username']) . '</small>' : '' ?></td>
<td class="ci-num"><b><?= ci_money($p['amount']) ?></b></td>
<td><div class="ci-row-actions"><a href="/admin/ci-receipt.php?id=<?= (int)$p['id'] ?>" target="_blank">Receipt</a>
<?php if ($me['role'] === 'admin'): ?><form method="post" onsubmit="return confirm('Delete payment <?= ci_e($p['receipt_no']) ?>? The due goes up again.')"><?= csrf_field() ?><input type="hidden" name="action" value="pay_delete"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>"><button type="submit" class="ci-del">Delete</button></form><?php endif; ?>
</div></td></tr>
<?php endforeach; ?>
</tbody></table></div></div>
</div>
<?php admin_shell_close(); ?>
<script>
(function () {
  var f = document.getElementById('ci-pay-for'), a = document.getElementById('ci-pay-amt');
  if (!f || !a) return;
  f.addEventListener('change', function () { var d = f.options[f.selectedIndex].dataset.due; a.value = d; a.max = d; });
  a.max = f.options[f.selectedIndex].dataset.due;
})();
</script>
</body>
</html>
