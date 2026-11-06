<?php
// Student profile — everything about one student on one page (like the customer profile of the shop):
// header with call / WhatsApp / collect fee, fee totals, and tabs: Overview (all details), Fee & kists (each course with
// its instalment plan, collect, edit the plan) and Payments (every receipt with the balance after it).
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
        $e = $ownEnr($eid);
        $res = $e ? ci_add_payment($pdo, $eid, ci_amount($_POST['amount'] ?? 0), (string)($_POST['method'] ?? 'Cash'), ci_clean_date($_POST['paid_on'] ?? ''), trim((string)($_POST['note'] ?? '')), (int)$me['id'])
            : "Choose one of this student's courses.";
        if (is_string($res)) { admin_flash($res, 'error'); header('Location: ' . $back('fee')); exit; }
        // without a plan the next due date is typed by hand (empty = none); with a plan it follows the kists
        if (!ci_plan($pdo, $eid, 0)) $pdo->prepare("UPDATE ci_enrollments SET due_date = ? WHERE id = ?")->execute([ci_clean_date($_POST['next_due'] ?? ''), $eid]);
        admin_log('ci_payment', 'Fee ' . ci_money(ci_amount($_POST['amount'] ?? 0)) . " from {$s['reg_no']} {$s['name']} ({$res[1]})");
        admin_flash('Fee saved. Receipt ' . $res[1] . '.');
        header("Location: /admin/student-view.php?id=$id&receipt=" . $res[0] . '#payments');
        exit;
    }
    if ($a === 'plan_save') {
        $e = $ownEnr($eid);
        if (!$e) { admin_flash("Choose one of this student's courses.", 'error'); header('Location: ' . $back('fee')); exit; }
        if ($e['status'] === 'dropped') { admin_flash('This course is dropped; it has no fee to plan.', 'error'); header('Location: ' . $back('fee')); exit; }
        [$rows, $err] = ci_plan_from_post((array)($_POST['k_amount'] ?? []), (array)($_POST['k_date'] ?? []), round((float)$e['fee'] - (float)$e['discount'], 2));
        if ($err) { admin_flash($err, 'error'); header("Location: /admin/student-view.php?id=$id&plan=$eid#fee"); exit; }
        $pdo->beginTransaction();
        ci_plan_save($pdo, $eid, $rows);
        $pdo->commit();
        admin_log('ci_plan', "Fee plan of {$s['reg_no']} set to " . count($rows) . ' instalments');
        admin_flash('Fee plan saved: ' . count($rows) . ' instalments.');
        header('Location: ' . $back('fee'));
        exit;
    }
    if ($a === 'plan_clear') {
        if ($ownEnr($eid)) {
            ci_plan_clear($pdo, $eid);
            admin_flash('Instalments removed. The fee is now one amount; set its due date when you collect.');
        }
        header('Location: ' . $back('fee'));
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
    if ($a === 'delete' && in_array($me['role'], ['admin', 'editor'], true)) {
        $n = (int)$pdo->query("SELECT COUNT(*) FROM ci_payments WHERE student_id = $id")->fetchColumn();
        if ($n) { admin_flash('This student has fee payments, so they cannot be deleted. Set the status to Left instead.', 'error'); header('Location: ' . $back()); exit; }
        $pdo->beginTransaction();
        $pdo->prepare("DELETE i FROM ci_installments i JOIN ci_enrollments e ON e.id = i.enrollment_id WHERE e.student_id = ?")->execute([$id]);
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
$late = 0.0;
$next = null;
foreach ($enr as &$e) {
    $e['plan'] = $e['status'] === 'dropped' ? [] : ci_plan($pdo, (int)$e['id'], (float)$e['paid']);
    $e['sum'] = $e['plan'] ? ci_plan_summary($e['plan']) : null;
    $e['net'] = (float)$e['fee'] - (float)$e['discount'];
    if ($e['status'] === 'dropped' || (float)$e['due'] <= 0.004) continue;
    if ($e['sum']) {
        $late += $e['sum']['late'];
        $cand = $e['sum']['next'] ? ['date' => $e['sum']['next']['due_date'], 'amount' => $e['sum']['next']['left'], 'course' => $e['course_name']] : null;
    } else {
        if ($e['due_date'] && $e['due_date'] < date('Y-m-d')) $late += (float)$e['due'];
        $cand = $e['due_date'] ? ['date' => $e['due_date'], 'amount' => (float)$e['due'], 'course' => $e['course_name']] : null;
    }
    if ($cand && (!$next || $cand['date'] < $next['date'])) $next = $cand;
}
unset($e);
$pq = $pdo->prepare("SELECT p.*, c.name AS course_name, u.username FROM ci_payments p LEFT JOIN ci_enrollments e ON e.id = p.enrollment_id
    LEFT JOIN ci_courses c ON c.id = e.course_id LEFT JOIN users u ON u.id = p.created_by WHERE p.student_id = ? ORDER BY p.paid_on, p.id");
$pq->execute([$id]);
$pays = $pq->fetchAll(PDO::FETCH_ASSOC);
// balance after each payment (all non-dropped courses), shown newest first
$run = $tot['net'];
foreach ($pays as &$p) { $run -= (float)$p['amount']; $p['balance'] = max(0, $run); }
unset($p);
$pays = array_reverse($pays);
$payable = array_values(array_filter($enr, fn($e) => $e['status'] !== 'dropped' && (float)$e['due'] > 0.004));
$newReceipt = (int)($_GET['receipt'] ?? 0);
$openPlan = (int)($_GET['plan'] ?? 0);
$age = $s['dob'] ? (new DateTime($s['dob']))->diff(new DateTime())->y : null;
$address = trim(implode(', ', array_filter([$s['address'], $s['city'], $s['state'], $s['pincode']])));
$waText = 'Namaste, ' . $s['name'] . ' (' . $s['reg_no'] . ')' . ($tot['due'] > 0 ? ' ki baaki fee ' . ci_money0($tot['due']) . ' hai' . ($next ? ', agli kist ' . ci_date($next['date']) . ' ko.' : '.') : '.') . ' — ' . edu_site_name();
$wa = ci_wa($s['mobile'], $waText);
$kv = fn(array $rows) => implode('', array_map(fn($k, $v) => ($v === null || $v === '' ? '' : '<dt>' . $k . '</dt><dd>' . $v . '</dd>'), array_keys($rows), $rows));
$tel = fn(?string $m) => $m ? '<a href="tel:' . ci_e($m) . '">' . ci_e($m) . '</a>' : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title><?= ci_e($s['name']) ?> - <?= ci_e(edu_site_name()) ?></title>
<?php admin_shell_head(); ci_head(); ?>
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
    <?php if ($s['mobile']): ?><a class="ci-btn ci-ghost ci-icon" href="tel:<?= ci_e($s['mobile']) ?>" title="Call"><i class="fas fa-phone"></i></a><?php endif; ?>
    <?php if ($wa): ?><a class="ci-btn ci-ghost ci-icon ci-wa" href="<?= ci_e($wa) ?>" target="_blank" rel="noopener" title="WhatsApp (fee reminder)"><i class="fab fa-whatsapp"></i></a><?php endif; ?>
    <?php if ($payable): ?><button type="button" class="ci-btn" data-collect="<?= (int)$payable[0]['id'] ?>"><i class="fas fa-indian-rupee-sign"></i> Collect Fee</button><?php endif; ?>
    <a class="ci-btn ci-ghost" href="/admin/student-edit.php?id=<?= $id ?>"><i class="fas fa-pen"></i> Edit</a>
    <?php if (!$pays && in_array($me['role'], ['admin', 'editor'], true)): ?><form method="post" onsubmit="return confirm('Delete student <?= ci_e(addslashes($s['name'])) ?>? This cannot be undone.')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $id ?>"><button class="ci-btn ci-danger ci-icon" type="submit" title="Delete student"><i class="fas fa-trash"></i></button></form><?php endif; ?>
  </div>
</div></div>

<div class="ci-stats ci-stats6">
  <div class="ci-stat"><i class="fas fa-file-invoice ci-i-violet"></i><div><b><?= ci_money0($tot['net']) ?></b><small>Total fee (after discount)</small></div></div>
  <div class="ci-stat"><i class="fas fa-circle-check ci-i-green"></i><div><b><?= ci_money0($tot['paid']) ?></b><small>Paid · <?= count($pays) ?> receipts</small></div></div>
  <div class="ci-stat"><i class="fas fa-hourglass-half ci-i-red"></i><div><b><?= ci_money0($tot['due']) ?></b><small>Due (baaki)</small></div></div>
  <div class="ci-stat"><i class="fas fa-calendar-xmark ci-i-amber"></i><div><b><?= ci_money0($late) ?></b><small>Late (date passed)</small></div></div>
  <div class="ci-stat"><i class="fas fa-calendar-day ci-i-blue"></i><div><b><?= $next ? ci_money0($next['amount']) : '—' ?></b><small><?= $next ? 'Next kist · ' . ci_date($next['date']) : 'No upcoming kist' ?></small></div></div>
</div>

<div class="ci-tabs ci-noprint" id="ci-ptabs">
  <a href="#overview" data-tab="overview"><i class="fas fa-id-card"></i> Overview</a>
  <a href="#fee" data-tab="fee"><i class="fas fa-indian-rupee-sign"></i> Fee &amp; Kists (<?= count($enr) ?>)</a>
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
<?php if ($s['notes']): ?><div class="ci-card"><div class="ci-card-h"><h3>Notes</h3></div><div class="ci-card-b"><?= nl2br(ci_e($s['notes'])) ?></div></div><?php endif; ?>
</section>

<section class="ci-pane" data-pane="fee">
<?php if (!$enr): ?><div class="ci-card"><div class="ci-card-b ci-empty">Not enrolled in any course yet. <a href="/admin/student-edit.php?id=<?= $id ?>">Add a course</a>.</div></div><?php endif; ?>
<?php foreach ($enr as $e): $eid = (int)$e['id']; $bi = $batchInfo[$e['batch_id']] ?? []; $dropped = $e['status'] === 'dropped'; ?>
<div class="ci-card ci-enr" id="enr-<?= $eid ?>">
  <div class="ci-card-h"><div><h3><?= ci_e($e['course_name'] ?? '—') ?> <?= $e['status'] !== 'active' ? ci_status_badge($e['status']) : '' ?></h3>
    <small class="ci-muted"><?= ci_e(implode(' · ', array_filter([$e['batch_name'] ? 'Batch ' . $e['batch_name'] : 'No batch', $e['timing'], $bi['days'] ?? '', ($bi['teacher'] ?? '') ? 'Teacher ' . $bi['teacher'] : '', $e['session_name'] ? 'Session ' . $e['session_name'] : '']))) ?></small></div>
    <div class="ci-actions ci-noprint">
      <?php if (!$dropped && (float)$e['due'] > 0.004): ?><button type="button" class="ci-btn ci-sm" data-collect="<?= $eid ?>"><i class="fas fa-indian-rupee-sign"></i> Collect</button><?php endif; ?>
      <?php if (!$dropped && $e['net'] > 0): ?><button type="button" class="ci-btn ci-ghost ci-sm" data-plan-toggle="<?= $eid ?>"><i class="fas fa-list-ol"></i> <?= $e['plan'] ? 'Change kists' : 'Make kists' ?></button><?php endif; ?>
    </div>
  </div>
  <div class="ci-card-b">
    <div class="ci-money">
      <div><small>Course fee</small><b><?= ci_money($e['fee']) ?></b></div>
      <div><small>Discount</small><b><?= (float)$e['discount'] > 0 ? '−' . ci_money($e['discount']) : '—' ?></b></div>
      <div><small>Fee to pay</small><b><?= ci_money($dropped ? 0 : $e['net']) ?></b></div>
      <div><small>Paid</small><b class="ci-g"><?= ci_money($e['paid']) ?></b></div>
      <div><small>Due</small><b class="<?= (float)$e['due'] > 0.004 ? 'ci-r' : 'ci-g' ?>"><?= ci_money($e['due']) ?></b></div>
    </div>
    <?php if ($e['plan']): $sm = $e['sum']; ?>
      <div class="ci-plan-head"><b><?= $sm['n'] ?> instalments</b> · <?= $sm['paid_n'] ?> paid<?= $sm['late'] > 0 ? ' · <span class="ci-r">' . ci_money0($sm['late']) . ' late</span>' : '' ?><?= $sm['next'] ? ' · next ' . ci_money0($sm['next']['left']) . ' on ' . ci_date($sm['next']['due_date']) : '' ?></div>
      <div class="ci-table-wrap"><table class="ci-table ci-plan">
        <thead><tr><th>Kist</th><th>Due date</th><th class="ci-num">Amount</th><th class="ci-num">Paid</th><th class="ci-num">Left</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($e['plan'] as $k): ?><tr class="ci-k-<?= $k['state'] ?>"><td><b>Kist <?= $k['no'] ?></b></td><td><?= ci_date($k['due_date']) ?></td><td class="ci-num"><?= ci_money($k['amount']) ?></td><td class="ci-num"><?= $k['paid'] > 0 ? ci_money($k['paid']) : '—' ?></td><td class="ci-num"><?= $k['left'] > 0 ? '<b>' . ci_money($k['left']) . '</b>' : '—' ?></td><td><?= ci_plan_badge($k['state']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    <?php elseif (!$dropped && $e['net'] > 0): ?>
      <p class="ci-muted" style="margin:12px 0 0">One-time fee<?= $e['due_date'] && (float)$e['due'] > 0.004 ? ' · due date <b' . ($e['due_date'] < date('Y-m-d') ? ' class="ci-r"' : '') . '>' . ci_date($e['due_date']) . '</b>' : '' ?>. The student can pay it in any number of parts; use <b>Make kists</b> to fix instalments with dates.</p>
    <?php endif; ?>

    <?php if (!$dropped && $e['net'] > 0): $rowsK = $e['plan'] ?: ci_plan_split($e['net'], 3, $e['due_date'] ?: date('Y-m-d')); ?>
    <form method="post" class="ci-plan-edit ci-noprint" data-plan="<?= $eid ?>" data-net="<?= $e['net'] ?>" data-paid="<?= (float)$e['paid'] ?>"<?= $openPlan === $eid ? '' : ' hidden' ?>>
      <?= csrf_field() ?><input type="hidden" name="action" value="plan_save"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="enrollment_id" value="<?= $eid ?>">
      <div class="ci-plan-split">
        <b>Split equally:</b>
        <label>into <select class="ci-ps-n"><?php foreach ([2, 3, 4, 5, 6, 8, 10, 12] as $n): ?><option value="<?= $n ?>" <?= count($rowsK) === $n ? 'selected' : '' ?>><?= $n ?> kists</option><?php endforeach; ?></select></label>
        <label>from <input type="date" class="ci-ps-d" value="<?= ci_e($rowsK[0]['due_date']) ?>"></label>
        <label>every <select class="ci-ps-e"><option value="1">month</option><option value="2">2 months</option><option value="3">3 months</option><option value="6">6 months</option></select></label>
        <button type="button" class="ci-btn ci-ghost ci-sm ci-ps-go">Split</button>
      </div>
      <table class="ci-table ci-plan-rows"><thead><tr><th>Kist</th><th>Amount (₹)</th><th>Due date</th><th></th></tr></thead><tbody>
      <?php foreach ($rowsK as $i => $k): ?><tr><td class="ci-kn"><?= $i + 1 ?></td><td><input type="number" name="k_amount[]" min="0" step="0.01" value="<?= (float)$k['amount'] ?>" class="ci-in"></td><td><input type="date" name="k_date[]" value="<?= ci_e($k['due_date']) ?>" class="ci-in"></td><td><button type="button" class="ci-btn ci-danger ci-sm ci-k-del" title="Remove"><i class="fas fa-times"></i></button></td></tr><?php endforeach; ?>
      </tbody></table>
      <div class="ci-plan-foot">
        <button type="button" class="ci-btn ci-ghost ci-sm ci-k-add"><i class="fas fa-plus"></i> Add kist</button>
        <span class="ci-plan-total"></span>
        <span style="flex:1"></span>
        <button type="submit" class="ci-btn ci-sm"><i class="fas fa-check"></i> Save kists</button>
        <button type="button" class="ci-btn ci-ghost ci-sm" data-plan-toggle="<?= $eid ?>">Cancel</button>
      </div>
    </form>
    <?php if ($e['plan']): ?><form method="post" class="ci-noprint" style="margin-top:8px" onsubmit="return confirm('Remove the instalments of this course? Payments stay as they are.')"><?= csrf_field() ?><input type="hidden" name="action" value="plan_clear"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="enrollment_id" value="<?= $eid ?>"><button type="submit" class="ci-linkbtn">Remove kists (make it a one-time fee)</button></form><?php endif; ?>
    <?php endif; ?>
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
<td class="ci-noprint"><div class="ci-row-actions"><a href="/admin/ci-receipt.php?id=<?= (int)$p['id'] ?>" target="_blank">Receipt</a>
<?php if ($me['role'] === 'admin'): ?><form method="post" onsubmit="return confirm('Delete payment <?= ci_e($p['receipt_no']) ?>? The due goes up again.')"><?= csrf_field() ?><input type="hidden" name="action" value="pay_delete"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>"><button type="submit" class="ci-del">Delete</button></form><?php endif; ?>
</div></td></tr>
<?php endforeach; ?>
<?php if ($pays): ?><tr class="ci-total-row"><td colspan="5">Total paid</td><td class="ci-num"><?= ci_money($tot['paid']) ?></td><td class="ci-num"><?= ci_money($tot['due']) ?></td><td class="ci-noprint"></td></tr><?php endif; ?>
</tbody></table></div></div>
</section>

<?php if ($payable): ?>
<div class="ci-modal" id="ci-collect" hidden><div class="ci-modal-box" role="dialog" aria-modal="true" aria-labelledby="ci-collect-h">
  <div class="ci-card-h"><h3 id="ci-collect-h">Collect fee — <?= ci_e($s['name']) ?></h3><button type="button" class="ci-x" data-close aria-label="Close"><i class="fas fa-times"></i></button></div>
  <form method="post" class="ci-card-b"><?= csrf_field() ?><input type="hidden" name="action" value="pay"><input type="hidden" name="id" value="<?= $id ?>">
    <div class="ci-f"><label>For</label><select name="enrollment_id" id="ci-pay-for" required><?php foreach ($payable as $e): $nx = $e['sum']['next'] ?? null; ?>
      <option value="<?= (int)$e['id'] ?>" data-due="<?= (float)$e['due'] ?>" data-late="<?= $e['sum'] ? (float)$e['sum']['late'] : ($e['due_date'] && $e['due_date'] < date('Y-m-d') ? (float)$e['due'] : 0) ?>" data-next="<?= $nx ? (float)$nx['left'] : '' ?>" data-nextd="<?= $nx ? ci_e(ci_date($nx['due_date'])) : '' ?>" data-plan="<?= $e['plan'] ? 1 : 0 ?>"><?= ci_e($e['course_name'] . ($e['batch_name'] ? ' · ' . $e['batch_name'] : '')) ?> — due <?= ci_money0($e['due']) ?></option>
    <?php endforeach; ?></select></div>
    <div class="ci-chips" id="ci-chips"></div>
    <div class="ci-grid2" style="gap:12px;margin-top:12px">
      <div class="ci-f"><label>Amount (₹) <span class="ci-req">*</span></label><input type="number" name="amount" id="ci-pay-amt" min="1" step="0.01" required></div>
      <div class="ci-f"><label>Paid by</label><select name="method"><?php foreach (CI_METHODS as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select></div>
      <div class="ci-f"><label>Date</label><input type="date" name="paid_on" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>"></div>
      <div class="ci-f" id="ci-nd"><label>Next due date</label><input type="date" name="next_due"><span class="ci-hint">if some fee is still left</span></div>
    </div>
    <div class="ci-f" style="margin-top:12px"><label>Note</label><input name="note" maxlength="250" placeholder="e.g. 2nd kist, UPI ref no."></div>
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

  // collect fee box
  var m = document.getElementById('ci-collect');
  if (m) {
    var f = document.getElementById('ci-pay-for'), amt = document.getElementById('ci-pay-amt'), chips = document.getElementById('ci-chips'), nd = document.getElementById('ci-nd'), info = document.getElementById('ci-pay-info');
    var refresh = function () {
      var o = f.options[f.selectedIndex], due = +o.dataset.due, late = +o.dataset.late, next = o.dataset.next === '' ? 0 : +o.dataset.next, plan = o.dataset.plan === '1';
      var c = [];
      if (plan && next > 0) c.push(['Next kist' + (o.dataset.nextd ? ' (' + o.dataset.nextd + ')' : ''), next]);
      if (late > 0 && late !== next) c.push(['All late', late]);
      c.push(['Full due', due]);
      chips.innerHTML = c.map(function (x) { return '<button type="button" class="ci-chip" data-v="' + x[1] + '">' + x[0] + ' · ' + inr(x[1]) + '</button>'; }).join('');
      amt.max = due; amt.value = c[0][1];
      nd.style.display = plan ? 'none' : '';
      info.textContent = plan ? 'This course has kists: the amount is taken against the oldest unpaid kist first. Any amount up to the due can be paid.' : 'Any amount up to the due can be paid; pay again later for the rest.';
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

  // instalment plan editor
  document.querySelectorAll('[data-plan-toggle]').forEach(function (b) {
    b.addEventListener('click', function () { var fm = document.querySelector('.ci-plan-edit[data-plan="' + b.dataset.planToggle + '"]'); if (fm) { fm.hidden = !fm.hidden; if (!fm.hidden) total(fm); } });
  });
  function total(fm) {
    var net = +fm.dataset.net, sum = 0;
    fm.querySelectorAll('[name="k_amount[]"]').forEach(function (i) { sum += parseFloat(i.value) || 0; });
    fm.querySelectorAll('.ci-kn').forEach(function (td, i) { td.textContent = i + 1; });
    sum = Math.round(sum * 100) / 100;
    var el = fm.querySelector('.ci-plan-total'), ok = Math.abs(sum - net) < 0.005;
    el.innerHTML = 'Total ' + inr(sum) + ' of ' + inr(net) + (ok ? ' <span class="ci-g">✓ matches</span>' : ' <span class="ci-r">(' + (sum > net ? 'more by ' + inr(sum - net) : 'short by ' + inr(net - sum)) + ')</span>');
    fm.querySelector('[type=submit]').disabled = !ok;
  }
  function row(amount, date) {
    var tr = document.createElement('tr');
    tr.innerHTML = '<td class="ci-kn"></td><td><input type="number" name="k_amount[]" min="0" step="0.01" class="ci-in"></td><td><input type="date" name="k_date[]" class="ci-in"></td><td><button type="button" class="ci-btn ci-danger ci-sm ci-k-del" title="Remove"><i class="fas fa-times"></i></button></td>';
    tr.querySelector('[name="k_amount[]"]').value = amount; tr.querySelector('[name="k_date[]"]').value = date;
    return tr;
  }
  document.querySelectorAll('.ci-plan-edit').forEach(function (fm) {
    var body = fm.querySelector('.ci-plan-rows tbody');
    fm.addEventListener('input', function () { total(fm); });
    fm.addEventListener('click', function (e) {
      if (e.target.closest('.ci-k-del')) { if (body.rows.length > 1) e.target.closest('tr').remove(); total(fm); }
      if (e.target.closest('.ci-k-add')) {
        var last = body.rows[body.rows.length - 1], d = last ? last.querySelector('[name="k_date[]"]').value : '';
        if (d) { var x = new Date(d + 'T00:00:00'); x.setMonth(x.getMonth() + 1); d = x.getFullYear() + '-' + String(x.getMonth() + 1).padStart(2, '0') + '-' + String(x.getDate()).padStart(2, '0'); }
        body.appendChild(row('', d)); total(fm);
      }
      if (e.target.closest('.ci-ps-go')) {
        var n = +fm.querySelector('.ci-ps-n').value, ev = +fm.querySelector('.ci-ps-e').value, d0 = fm.querySelector('.ci-ps-d').value, net = +fm.dataset.net;
        if (!d0) { fm.querySelector('.ci-ps-d').focus(); return; }
        var p = d0.split('-').map(Number), base = Math.floor(net / n);
        body.innerHTML = '';
        for (var i = 0; i < n; i++) {
          var mm = p[1] - 1 + i * ev, yy = p[0] + Math.floor(mm / 12); mm = mm % 12;
          var dd = Math.min(p[2], new Date(yy, mm + 1, 0).getDate());
          body.appendChild(row(i === n - 1 ? Math.round((net - base * (n - 1)) * 100) / 100 : base, yy + '-' + String(mm + 1).padStart(2, '0') + '-' + String(dd).padStart(2, '0')));
        }
        total(fm);
      }
    });
    if (!fm.hidden) total(fm);
  });
})();
</script>
</body>
</html>
