<?php
// Student profile — everything about one student on one page (like the customer profile of the shop):
// header with call / WhatsApp / collect fee, fee totals, and tabs: Overview (all details), Fee (each course: course fee,
// discount, fee for this student, paid, due, and every installment paid for it) and Payments (every receipt + balance).
require_once __DIR__ . '/components/guard.php';
require_once INCLUDES_PATH . '/institute.php';
$me = ci_page_start($pdo);
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$q = $pdo->prepare("SELECT s.*, c.name AS class_name, u.username AS added_by FROM ci_students s LEFT JOIN ci_classes c ON c.id = s.class_id
    LEFT JOIN users u ON u.id = s.created_by WHERE s.id = ?");
$q->execute([$id]);
$s = $q->fetch(PDO::FETCH_ASSOC);
if (!$s) { admin_flash('Student not found.', 'error'); header('Location: /admin/students.php'); exit; }
$back = fn(string $tab = '') => "/admin/student-view.php?id=$id" . ($tab ? "#$tab" : '');

/** The enrollment, only when it belongs to this student. */
$ownEnr = function (int $eid) use ($pdo, $id): ?array {
    $st = $pdo->prepare("SELECT * FROM ci_enrollments WHERE id = ? AND student_id = ?");
    $st->execute([$eid, $id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $a = (string)($_POST['action'] ?? '');
    $eid = (int)($_POST['enrollment_id'] ?? 0);
    if ($a === 'pay') {
        if (!ci_perm($me, 'fees')) { http_response_code(403); exit('Access Denied'); }
        $e = $ownEnr($eid);
        $res = $e ? ci_add_payment($pdo, $eid, ci_amount($_POST['amount'] ?? 0), (string)($_POST['method'] ?? 'Cash'), ci_clean_date($_POST['paid_on'] ?? ''), trim((string)($_POST['note'] ?? '')), (int)$me['id'])
            : "Choose one of this student's courses.";
        if (is_string($res)) { admin_flash($res, 'error'); header('Location: ' . $back('fee')); exit; }
        // the next due date is typed by hand when some fee is still left (empty = none)
        $pdo->prepare("UPDATE ci_enrollments SET due_date = ? WHERE id = ?")->execute([ci_clean_date($_POST['next_due'] ?? ''), $eid]);
        admin_log('ci_payment', 'Fee ' . ci_money(ci_amount($_POST['amount'] ?? 0)) . " from {$s['reg_no']} {$s['name']} ({$res[1]})");
        admin_flash('Fee saved. Receipt ' . $res[1] . '.');
        header("Location: /admin/student-view.php?id=$id&receipt=" . $res[0] . '#payments');
        exit;
    }
    if ($a === 'promote' && ci_perm($me, 'edit_student')) {
        $to = (int)($_POST['to_class'] ?? 0);
        $ok = $pdo->prepare("SELECT COUNT(*) FROM ci_classes WHERE id = ?"); $ok->execute([$to]);
        if (!$to || !(int)$ok->fetchColumn() || $to === (int)$s['class_id']) { admin_flash('Choose the new class.', 'error'); header('Location: ' . $back()); exit; }
        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO ci_class_history (student_id, from_class, to_class, session_id, changed_on, note, created_by) VALUES (?,?,?,?,?,?,?)")
            ->execute([$id, $s['class_id'] ?: null, $to, ci_current_session($pdo), ci_clean_date($_POST['on'] ?? '') ?: date('Y-m-d'), mb_substr(trim((string)($_POST['note'] ?? '')), 0, 250) ?: null, (int)$me['id']]);
        $pdo->prepare("UPDATE ci_students SET class_id = ? WHERE id = ?")->execute([$to, $id]);
        // the old class's courses can be closed as completed — they stay in the record with any fee still due
        if (!empty($_POST['close_old'])) $pdo->prepare("UPDATE ci_enrollments SET status = 'completed', ended_on = CURDATE() WHERE student_id = ? AND status = 'active'")->execute([$id]);
        $pdo->commit();
        admin_log('ci_promote', "Moved {$s['reg_no']} to class #$to");
        admin_flash('Class changed. The earlier courses and their fees are kept. Add the new class\'s course now.');
        header('Location: /admin/student-edit.php?id=' . $id . '&addcourse=1');
        exit;
    }
    if ($a === 'pay_delete' && $me['role'] === 'admin') {
        $pid = (int)($_POST['payment_id'] ?? 0);
        $p = $pdo->prepare("SELECT receipt_no, amount, enrollment_id FROM ci_payments WHERE id = ? AND student_id = ?");
        $p->execute([$pid, $id]);
        if ($row = $p->fetch(PDO::FETCH_ASSOC)) {
            $pdo->prepare("DELETE FROM ci_payments WHERE id = ?")->execute([$pid]);
            ci_sync_due($pdo, (int)$row['enrollment_id']);
            admin_log('ci_payment_delete', "Deleted receipt {$row['receipt_no']} (" . ci_money($row['amount']) . ") of {$s['reg_no']}");
            admin_flash('Payment ' . $row['receipt_no'] . ' deleted.');
        }
        header('Location: ' . $back('payments'));
        exit;
    }
    if ($a === 'delete' && ci_perm($me, 'edit_student')) {
        $n = (int)$pdo->query("SELECT COUNT(*) FROM ci_payments WHERE student_id = $id")->fetchColumn();
        if ($n) { admin_flash('This student has fee payments, so they cannot be deleted. Set the status to Left instead.', 'error'); header('Location: ' . $back()); exit; }
        $pdo->beginTransaction();
        $pdo->prepare("DELETE i FROM ci_installments i JOIN ci_enrollments e ON e.id = i.enrollment_id WHERE e.student_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM ci_class_history WHERE student_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM ci_enrollments WHERE student_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM ci_students WHERE id = ?")->execute([$id]);
        $pdo->commit();
        ci_delete_photo($s['photo'] ?? null);
        admin_log('ci_student_delete', "Deleted student {$s['reg_no']} {$s['name']}");
        admin_flash('Student ' . $s['reg_no'] . ' deleted.');
        header('Location: /admin/students.php');
        exit;
    }
    header('Location: ' . $back());
    exit;
}

// ---- data
$enr = ci_enrollments($pdo, $id);
$tq = $pdo->prepare("SELECT b.id, b.teacher, b.days FROM ci_batches b JOIN ci_enrollments e ON e.batch_id = b.id WHERE e.student_id = ?");
$tq->execute([$id]);
$batchInfo = $tq->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
$tot = ci_student_totals($pdo, $id);
$hq = $pdo->prepare("SELECT h.*, a.name AS from_name, b.name AS to_name, se.name AS session_name, u.username FROM ci_class_history h LEFT JOIN ci_classes a ON a.id = h.from_class
    LEFT JOIN ci_classes b ON b.id = h.to_class LEFT JOIN ci_sessions se ON se.id = h.session_id LEFT JOIN users u ON u.id = h.created_by WHERE h.student_id = ? ORDER BY h.changed_on DESC, h.id DESC");
$hq->execute([$id]);
$history = $hq->fetchAll(PDO::FETCH_ASSOC);
$classes = ci_lists($pdo)['classes'];
$late = 0.0;
$next = null;
$byEnr = [];
foreach ($enr as &$e) {
    $e['net'] = (float)$e['fee'] - (float)$e['discount'];
    if ($e['status'] === 'dropped' || (float)$e['due'] <= 0.004 || !$e['due_date']) continue;
    if ($e['due_date'] < date('Y-m-d')) $late += (float)$e['due'];
    if (!$next || $e['due_date'] < $next['date']) $next = ['date' => $e['due_date'], 'amount' => (float)$e['due'], 'course' => $e['course_name']];
}
unset($e);
$pq = $pdo->prepare("SELECT p.*, c.name AS course_name, u.username FROM ci_payments p LEFT JOIN ci_enrollments e ON e.id = p.enrollment_id
    LEFT JOIN ci_courses c ON c.id = e.course_id LEFT JOIN users u ON u.id = p.created_by WHERE p.student_id = ? ORDER BY p.paid_on, p.id");
$pq->execute([$id]);
$pays = $pq->fetchAll(PDO::FETCH_ASSOC);
// balance after each payment (all non-dropped courses), shown newest first
$run = $tot['net'];
foreach ($pays as &$p) {
    $run -= (float)$p['amount'];
    $p['balance'] = max(0, $run);
    $p['no'] = count($byEnr[$p['enrollment_id']] ?? []) + 1; // installment number within its course
    $byEnr[$p['enrollment_id']][] = $p;
}
unset($p);
$pays = array_reverse($pays);
$payable = array_values(array_filter($enr, fn($e) => $e['status'] !== 'dropped' && (float)$e['due'] > 0.004));
$newReceipt = (int)($_GET['receipt'] ?? 0);
$age = $s['dob'] ? (new DateTime($s['dob']))->diff(new DateTime())->y : null;
$address = trim(implode(', ', array_filter([$s['address'], $s['city'], $s['state'], $s['pincode']])));
$waText = 'Namaste, ' . $s['name'] . ' (' . $s['reg_no'] . ')' . ($tot['due'] > 0 ? ' ki baaki fee ' . ci_money0($tot['due']) . ' hai' . ($next ? ', agli tareekh ' . ci_date($next['date']) . ' ko.' : '.') : '.') . ' — ' . edu_site_name();
$wa = ci_wa($s['mobile'], $waText);
$kv = fn(array $rows) => implode('', array_map(fn($k, $v) => ($v === null || $v === '' ? '' : '<dt>' . $k . '</dt><dd>' . $v . '</dd>'), array_keys($rows), $rows));
$tel = fn(?string $m) => $m ? '<a href="tel:' . ci_e($m) . '">' . ci_e($m) . '</a>' : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title><?= ci_e($s['name']) ?> - <?= ci_e(edu_site_name()) ?></title>
<?php admin_shell_head(); ci_head(); ci_report_assets(); ?>
</head>
<body>
<?php admin_shell_open('Student Profile', ci_e($s['reg_no'] . ' · ' . $s['name'])); ?>
<div class="ci">
<?= admin_flash() ?>
<?php if ($newReceipt): ?><div class="ci-alert ci-alert-ok"><i class="fas fa-receipt"></i> Payment saved. <a href="/admin/ci-receipt.php?id=<?= $newReceipt ?>" target="_blank"><b>Print receipt</b></a></div><?php endif; ?>

<div class="ci-card ci-ph"><div class="ci-card-b">
  <div class="ci-ph-main">
    <?= ci_avatar($s, 88) ?>
    <div class="ci-ph-txt">
      <h2><?= ci_e($s['name']) ?></h2>
      <div class="ci-ph-tags"><span class="ci-badge ci-b-violet"><?= ci_e($s['reg_no']) ?></span><?= ci_status_badge($s['status']) ?><?= $s['class_name'] ? '<span class="ci-badge ci-b-blue">Class ' . ci_e($s['class_name']) . '</span>' : '' ?><?= $tot['due'] > 0.004 ? '<span class="ci-badge ci-b-red">Due ' . ci_money0($tot['due']) . '</span>' : ($tot['net'] > 0 ? '<span class="ci-badge ci-b-green">Fee fully paid</span>' : '') ?></div>
      <div class="ci-ph-meta">
        <span><i class="fas fa-phone"></i> <?= $tel($s['mobile']) ?><?= $s['alt_mobile'] ? ' · ' . $tel($s['alt_mobile']) : '' ?></span>
        <?php if ($s['father_name']): ?><span><i class="fas fa-user"></i> S/D of <?= ci_e($s['father_name']) ?></span><?php endif; ?>
        <?php if ($s['city'] || $s['address']): ?><span><i class="fas fa-location-dot"></i> <?= ci_e($s['city'] ?: $s['address']) ?></span><?php endif; ?>
        <span><i class="fas fa-calendar"></i> Admitted <?= ci_date($s['admission_date']) ?></span>
      </div>
    </div>
  </div>
  <div class="ci-actions ci-noprint">
    <?php if ($s['mobile']): ?><?= ci_ico('phone', 'tel:' . $s['mobile'], 'Call') ?><?php endif; ?>
    <?php if ($wa): ?><a class="ci-ico ci-ico-ok" href="<?= ci_e($wa) ?>" target="_blank" rel="noopener" title="WhatsApp (fee reminder)" aria-label="WhatsApp"><i class="fab fa-whatsapp" style="font-size:16px"></i></a><?php endif; ?>
    <?= ci_ico('pdf', null, 'Student report (PDF)', 'button', ' data-report="student" data-id="' . $id . '"') ?>
    <?php if (ci_perm($me, 'edit_student')): ?><?= ci_ico('edit', '/admin/student-edit.php?id=' . $id, 'Edit student') ?><a class="ci-btn ci-ghost" href="/admin/student-edit.php?id=<?= $id ?>&amp;addcourse=1"><?= ci_svg('book-plus') ?> Add to course / batch</a><?php endif; ?>
    <?php if (!$pays && ci_perm($me, 'edit_student')): ?><?= ci_post_ico('trash', 'Delete student', ['action' => 'delete', 'id' => $id], 'Delete student ' . $s['name'] . '? This cannot be undone.') ?><?php endif; ?>
    <?php if ($payable && ci_perm($me, 'fees')): ?><button type="button" class="ci-btn" data-collect="<?= (int)$payable[0]['id'] ?>"><i class="fas fa-indian-rupee-sign"></i> Collect Fee</button><?php endif; ?>
  </div>
</div></div>

<div class="ci-stats ci-stats6">
  <div class="ci-stat"><i class="fas fa-file-invoice ci-i-violet"></i><div><b><?= ci_money0($tot['net']) ?></b><small>Total fee (after discount)</small></div></div>
  <div class="ci-stat"><i class="fas fa-circle-check ci-i-green"></i><div><b><?= ci_money0($tot['paid']) ?></b><small>Paid · <?= count($pays) ?> receipts</small></div></div>
  <div class="ci-stat"><i class="fas fa-hourglass-half ci-i-red"></i><div><b><?= ci_money0($tot['due']) ?></b><small>Due (baaki)</small></div></div>
  <div class="ci-stat"><i class="fas fa-calendar-xmark ci-i-amber"></i><div><b><?= ci_money0($late) ?></b><small>Due date passed</small></div></div>
  <div class="ci-stat"><i class="fas fa-calendar-day ci-i-blue"></i><div><b><?= $next ? ci_money0($next['amount']) : '—' ?></b><small><?= $next ? 'Next due · ' . ci_date($next['date']) : 'No due date set' ?></small></div></div>
</div>

<div class="ci-tabs ci-noprint" id="ci-ptabs">
  <a href="#overview" data-tab="overview"><i class="fas fa-id-card"></i> Overview</a>
  <a href="#fee" data-tab="fee"><i class="fas fa-indian-rupee-sign"></i> Fee (<?= count($enr) ?>)</a>
  <a href="#payments" data-tab="payments"><i class="fas fa-receipt"></i> Payments (<?= count($pays) ?>)</a>
</div>

<section class="ci-pane" data-pane="overview">
<div class="ci-grid2">
  <div class="ci-card"><div class="ci-card-h"><h3>Personal</h3></div><div class="ci-card-b"><dl class="ci-kv"><?= $kv([
      'Full name' => ci_e($s['name']), 'Reg. no.' => ci_e($s['reg_no']),
      'Date of birth' => $s['dob'] ? ci_date($s['dob']) . ' (' . $age . ' yrs)' : '', 'Gender' => ci_e($s['gender'] ?? ''),
      'Status' => ci_status_badge($s['status']),
  ]) ?></dl></div></div>
  <div class="ci-card"><div class="ci-card-h"><h3>Parents &amp; contact</h3></div><div class="ci-card-b"><dl class="ci-kv"><?= $kv([
      "Father's name" => ci_e($s['father_name'] ?? ''), "Mother's name" => ci_e($s['mother_name'] ?? ''),
      'Mobile' => $tel($s['mobile']), 'Alternate mobile' => $tel($s['alt_mobile']),
      'Email' => $s['email'] ? '<a href="mailto:' . ci_e($s['email']) . '">' . ci_e($s['email']) . '</a>' : '',
  ]) ?></dl></div></div>
  <div class="ci-card"><div class="ci-card-h"><h3>Address</h3></div><div class="ci-card-b"><dl class="ci-kv"><?= $kv([
      'Address' => ci_e($s['address'] ?? ''), 'City / Village' => ci_e($s['city'] ?? ''), 'State' => ci_e($s['state'] ?? ''), 'PIN code' => ci_e($s['pincode'] ?? ''),
      'Map' => $address ? '<a href="https://www.google.com/maps/search/?api=1&amp;query=' . rawurlencode($address) . '" target="_blank" rel="noopener">Open in Google Maps</a>' : '',
  ]) ?: '<span class="ci-muted">No address added.</span>' ?></dl></div></div>
  <div class="ci-card"><div class="ci-card-h"><h3>Academic</h3></div><div class="ci-card-b"><dl class="ci-kv"><?= $kv([
      'Class' => ci_e($s['class_name'] ?? ''), 'School / College' => ci_e($s['school'] ?? ''), 'Admission date' => ci_date($s['admission_date']),
      'Courses' => implode('<br>', array_map(fn($e) => ci_e($e['course_name'] . ($e['batch_name'] ? ' · ' . $e['batch_name'] : '')) . ($e['status'] !== 'active' ? ' ' . ci_status_badge($e['status']) : ''), $enr)),
      'Added' => ci_e(date('d M Y, h:i A', strtotime($s['created_at']))) . ($s['added_by'] ? ' by ' . ci_e($s['added_by']) : ''),
  ]) ?></dl></div></div>
</div>
<div class="ci-card"><div class="ci-card-h"><h3>Class history</h3><?php if (ci_perm($me, 'edit_student')): ?><a class="ci-btn ci-ghost ci-sm ci-noprint" href="/admin/student-edit.php?id=<?= $id ?>&amp;addcourse=1"><?= ci_svg('book-plus') ?> Add to course / batch</a><?php endif; ?></div><div class="ci-card-b ci-p0 ci-table-wrap"><table class="ci-table">
<thead><tr><th>Date</th><th>From class</th><th>To class</th><th>Session</th><th>Note</th></tr></thead><tbody>
<?php if (!$history): ?><tr><td colspan="5" class="ci-empty">In <?= ci_e($s['class_name'] ?? '—') ?> since admission (<?= ci_date($s['admission_date']) ?>). A class change made in Edit Student is listed here; earlier courses and fees stay as they are.</td></tr><?php endif; ?>
<?php foreach ($history as $h): ?><tr><td><?= ci_date($h['changed_on']) ?></td><td><?= ci_e($h['from_name'] ?? '—') ?></td><td><b><?= ci_e($h['to_name'] ?? '—') ?></b></td><td class="ci-muted"><?= ci_e($h['session_name'] ?? '—') ?></td><td class="ci-muted"><?= ci_e($h['note'] ?? '') ?><?= $h['username'] ? ' <small>by ' . ci_e($h['username']) . '</small>' : '' ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<?php if ($s['notes']): ?><div class="ci-card"><div class="ci-card-h"><h3>Notes</h3></div><div class="ci-card-b"><?= nl2br(ci_e($s['notes'])) ?></div></div><?php endif; ?>
</section>

<section class="ci-pane" data-pane="fee">
<?php if (!$enr): ?><div class="ci-card"><div class="ci-card-b ci-empty">Not enrolled in any course yet. <a href="/admin/student-edit.php?id=<?= $id ?>">Add a course</a>.</div></div><?php endif; ?>
<?php foreach ($enr as $e): $eid = (int)$e['id']; $bi = $batchInfo[$e['batch_id']] ?? []; $dropped = $e['status'] === 'dropped'; $inst = ($e['fee_type'] ?? 'full') === 'installment'; $ep = array_reverse($byEnr[$eid] ?? []); ?>
<div class="ci-card ci-enr" id="enr-<?= $eid ?>">
  <div class="ci-card-h"><div><h3><?= ci_e($e['course_name'] ?? '—') ?> <span class="ci-badge <?= $inst ? 'ci-b-blue' : 'ci-b-violet' ?>"><?= $inst ? 'Installment' : 'Final fee' ?></span> <?= $e['status'] !== 'active' ? ci_status_badge($e['status']) : '' ?></h3>
    <small class="ci-muted"><?= ci_e(implode(' · ', array_filter([$e['batch_name'] ? 'Batch ' . $e['batch_name'] : 'No batch', $e['timing'], $bi['days'] ?? '', ($bi['teacher'] ?? '') ? 'Teacher ' . $bi['teacher'] : '', $e['session_name'] ? 'Session ' . $e['session_name'] : '']))) ?></small></div>
    <div class="ci-actions ci-noprint">
      <?php if (!$dropped && (float)$e['due'] > 0.004 && ci_perm($me, 'fees')): ?><button type="button" class="ci-btn ci-sm" data-collect="<?= $eid ?>"><i class="fas fa-indian-rupee-sign"></i> Collect<?= $inst ? ' installment' : ' fee' ?></button><?php endif; ?>
      <?php if (ci_perm($me, 'edit_student')): ?><?= ci_ico('edit', '/admin/student-edit.php?id=' . $id, 'Change course / fee') ?><?php endif; ?>
    </div>
  </div>
  <div class="ci-card-b">
    <div class="ci-money">
      <div><small>Course fee</small><b><?= ci_money($e['fee']) ?></b></div>
      <div><small>Discount</small><b><?= (float)$e['discount'] > 0 ? '−' . ci_money($e['discount']) : '—' ?></b></div>
      <div><small>Fee for this student</small><b><?= ci_money($dropped ? 0 : $e['net']) ?></b></div>
      <div><small>Paid</small><b class="ci-g"><?= ci_money($e['paid']) ?></b></div>
      <div><small>Due</small><b class="<?= (float)$e['due'] > 0.004 ? 'ci-r' : 'ci-g' ?>"><?= ci_money($e['due']) ?></b></div>
    </div>
    <?php if (!$dropped && (float)$e['due'] > 0.004 && $e['due_date']): ?><p class="ci-muted" style="margin:10px 0 0">Next due date: <b<?= $e['due_date'] < date('Y-m-d') ? ' class="ci-r"' : '' ?>><?= ci_date($e['due_date']) ?></b><?= $e['due_date'] < date('Y-m-d') ? ' (passed)' : '' ?></p><?php endif; ?>
    <div class="ci-plan-head"><b><?= $inst ? 'Installments paid' : 'Payments' ?></b> · <?= count($ep) ?></div>
    <div class="ci-table-wrap"><table class="ci-table ci-plan">
      <thead><tr><th><?= $inst ? 'Installment' : '#' ?></th><th>Date</th><th>Receipt</th><th>Paid by</th><th class="ci-num">Amount</th><th class="ci-noprint"></th></tr></thead><tbody>
      <?php if (!$ep): ?><tr><td colspan="6" class="ci-empty">Nothing paid yet for this course.</td></tr><?php endif; ?>
      <?php foreach ($ep as $p): ?><tr><td><b><?= $inst ? 'Installment ' . $p['no'] : $p['no'] ?></b></td><td><?= ci_date($p['paid_on']) ?></td><td><?= ci_e($p['receipt_no']) ?></td><td><?= ci_e(CI_METHODS[$p['method']] ?? $p['method']) ?></td><td class="ci-num"><b class="ci-g"><?= ci_money($p['amount']) ?></b></td><td class="ci-noprint"><a class="ci-ico" href="/admin/ci-receipt.php?id=<?= (int)$p['id'] ?>" target="_blank" title="Print receipt" aria-label="Print receipt"><?= ci_svg('print') ?></a></td></tr><?php endforeach; ?>
      </tbody></table></div>
  </div>
</div>
<?php endforeach; ?>
</section>

<section class="ci-pane" data-pane="payments">
<div class="ci-card"><div class="ci-card-h"><h3>Fee statement</h3><div class="ci-actions ci-noprint"><?php if ($payable): ?><button type="button" class="ci-btn ci-sm" data-collect="<?= (int)$payable[0]['id'] ?>"><i class="fas fa-plus"></i> Collect fee</button><?php endif; ?><button type="button" class="ci-btn ci-ghost ci-sm" onclick="window.print()"><i class="fas fa-print"></i> Print</button></div></div>
<div class="ci-card-b ci-p0 ci-table-wrap"><table class="ci-table">
<thead><tr><th>Date</th><th>Receipt</th><th>For</th><th>Paid by</th><th>Note</th><th class="ci-num">Amount</th><th class="ci-num">Balance after</th><th class="ci-noprint"></th></tr></thead><tbody>
<?php if (!$pays): ?><tr><td colspan="8" class="ci-empty">No payments yet.</td></tr><?php endif; ?>
<?php foreach ($pays as $p): ?>
<tr<?= (int)$p['id'] === $newReceipt ? ' class="ci-hl"' : '' ?>><td><?= ci_date($p['paid_on']) ?></td><td><b><?= ci_e($p['receipt_no']) ?></b></td><td><?= ci_e($p['course_name'] ?? '—') ?></td>
<td><?= ci_e(CI_METHODS[$p['method']] ?? $p['method']) ?></td><td class="ci-muted"><?= ci_e($p['note'] ?? '') ?><?= $p['username'] ? '<small style="display:block">by ' . ci_e($p['username']) . '</small>' : '' ?></td>
<td class="ci-num"><b class="ci-g"><?= ci_money($p['amount']) ?></b></td><td class="ci-num"><?= ci_money($p['balance']) ?></td>
<td class="ci-noprint"><div class="ci-icos"><a class="ci-ico" href="/admin/ci-receipt.php?id=<?= (int)$p['id'] ?>" target="_blank" title="Print receipt" aria-label="Print receipt"><?= ci_svg('print') ?></a>
<?php if ($me['role'] === 'admin'): ?><?= ci_post_ico('trash', 'Delete payment', ['action' => 'pay_delete', 'id' => $id, 'payment_id' => $p['id']], 'Delete payment ' . $p['receipt_no'] . '? The due goes up again.') ?><?php endif; ?>
</div></td></tr>
<?php endforeach; ?>
<?php if ($pays): ?><tr class="ci-total-row"><td colspan="5">Total paid</td><td class="ci-num"><?= ci_money($tot['paid']) ?></td><td class="ci-num"><?= ci_money($tot['due']) ?></td><td class="ci-noprint"></td></tr><?php endif; ?>
</tbody></table></div></div>
</section>

<?php if ($payable): ?>
<div class="ci-modal" id="ci-collect" hidden><div class="ci-modal-box" role="dialog" aria-modal="true" aria-labelledby="ci-collect-h">
  <div class="ci-card-h"><h3 id="ci-collect-h">Collect fee — <?= ci_e($s['name']) ?></h3><button type="button" class="ci-x" data-close aria-label="Close"><i class="fas fa-times"></i></button></div>
  <form method="post" class="ci-card-b"><?= csrf_field() ?><input type="hidden" name="action" value="pay"><input type="hidden" name="id" value="<?= $id ?>">
    <div class="ci-f"><label>For</label><select name="enrollment_id" id="ci-pay-for" required><?php foreach ($payable as $e): ?>
      <option value="<?= (int)$e['id'] ?>" data-due="<?= (float)$e['due'] ?>" data-net="<?= (float)$e['net'] ?>" data-inst="<?= ($e['fee_type'] ?? 'full') === 'installment' ? 1 : 0 ?>" data-n="<?= count($byEnr[$e['id']] ?? []) + 1 ?>"><?= ci_e($e['course_name'] . ($e['batch_name'] ? ' · ' . $e['batch_name'] : '')) ?> — due <?= ci_money0($e['due']) ?></option>
    <?php endforeach; ?></select></div>
    <div class="ci-chips" id="ci-chips"></div>
    <div class="ci-grid2" style="gap:12px;margin-top:12px">
      <div class="ci-f"><label>Amount (₹) <span class="ci-req">*</span></label><input type="number" name="amount" id="ci-pay-amt" min="1" step="0.01" required></div>
      <div class="ci-f"><label>Paid by</label><select name="method"><?php foreach (CI_METHODS as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select></div>
      <div class="ci-f"><label>Date</label><input type="date" name="paid_on" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>"></div>
      <div class="ci-f" id="ci-nd"><label>Next due date</label><input type="date" name="next_due"><span class="ci-hint">if some fee is still left</span></div>
    </div>
    <div class="ci-f" style="margin-top:12px"><label>Note</label><input name="note" maxlength="250" placeholder="e.g. UPI ref no."></div>
    <p class="ci-muted" id="ci-pay-info" style="margin:10px 0 0;font-size:12.5px"></p>
    <div style="display:flex;gap:8px;margin-top:14px"><button class="ci-btn" type="submit"><i class="fas fa-check"></i> Save &amp; get receipt</button><button type="button" class="ci-btn ci-ghost" data-close>Cancel</button></div>
  </form>
</div></div>
<?php endif; ?>
</div>
<?php admin_shell_close(); ?>
<script>
(function () {
  var inr = function (n) { return '₹' + (Math.round(n * 100) / 100).toLocaleString('en-IN'); };
  // tabs (remembered in the address: #overview / #fee / #payments)
  var tabs = document.querySelectorAll('#ci-ptabs a'), panes = document.querySelectorAll('.ci-pane');
  function show(t) {
    if (!document.querySelector('.ci-pane[data-pane="' + t + '"]')) t = 'overview';
    tabs.forEach(function (a) { a.classList.toggle('on', a.dataset.tab === t); });
    panes.forEach(function (p) { p.hidden = p.dataset.pane !== t; });
  }
  tabs.forEach(function (a) { a.addEventListener('click', function (e) { e.preventDefault(); history.replaceState(null, '', '#' + a.dataset.tab); show(a.dataset.tab); }); });
  show((location.hash || '#overview').slice(1));
  window.addEventListener('hashchange', function () { show(location.hash.slice(1)); });

  // simple modals (promote)
  document.querySelectorAll('[data-open]').forEach(function (b) { b.addEventListener('click', function () { var md = document.getElementById(b.dataset.open); if (md) { md.hidden = false; document.body.style.overflow = 'hidden'; } }); });
  document.querySelectorAll('.ci-modal').forEach(function (md) { if (md.id === 'ci-collect') return; md.addEventListener('click', function (e) { if (e.target === md || e.target.closest('[data-close]')) { md.hidden = true; document.body.style.overflow = ''; } }); });
  // collect fee box
  var m = document.getElementById('ci-collect');
  if (m) {
    var f = document.getElementById('ci-pay-for'), amt = document.getElementById('ci-pay-amt'), chips = document.getElementById('ci-chips'), nd = document.getElementById('ci-nd'), info = document.getElementById('ci-pay-info');
    var note = m.querySelector('[name=note]');
    var refresh = function () {
      var o = f.options[f.selectedIndex], due = +o.dataset.due, inst = o.dataset.inst === '1', n = +o.dataset.n;
      chips.innerHTML = '<button type="button" class="ci-chip" data-v="' + due + '">Full due · ' + inr(due) + '</button>';
      amt.max = due;
      amt.value = inst ? '' : due;          // installment: type what the student gives now
      note.value = inst ? 'Installment ' + n : '';
      nd.style.display = '';
      info.textContent = inst ? 'Installment ' + n + ': enter any amount up to the due (' + inr(due) + '). Pay again later for the rest.' : 'Any amount up to the due can be paid.';
    };
    chips.addEventListener('click', function (e) { var b = e.target.closest('.ci-chip'); if (b) amt.value = b.dataset.v; });
    f.addEventListener('change', refresh);
    var open = function (eid) { if (eid) { f.value = eid; } refresh(); m.hidden = false; document.body.style.overflow = 'hidden'; setTimeout(function () { amt.focus(); amt.select(); }, 30); };
    var close = function () { m.hidden = true; document.body.style.overflow = ''; };
    document.querySelectorAll('[data-collect]').forEach(function (b) { b.addEventListener('click', function () { open(b.dataset.collect); }); });
    m.addEventListener('click', function (e) { if (e.target === m || e.target.closest('[data-close]')) close(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !m.hidden && !document.querySelector('.dk-pop')) close(); });
    if (location.search.indexOf('collect=1') > -1) open();
  }

})();
</script>
</body>
</html>
