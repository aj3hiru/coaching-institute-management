<?php
// Student profile — everything about one student on one page (like the customer profile of the shop):
// header with call / WhatsApp / collect fee, fee totals, and tabs: Overview (all details), Fee (each course: course fee,
// discount, fee for this student, paid, due, and every installment paid for it) and Payments (every receipt + balance).
require_once __DIR__ . '/components/guard.php';
require_once INCLUDES_PATH . '/institute.php';
if (is_file(INCLUDES_PATH . '/shop.php')) require_once INCLUDES_PATH . '/shop.php'; // online account parts of the profile
$me = ci_page_start($pdo);
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$q = $pdo->prepare("SELECT s.*, c.name AS class_name, u.username AS added_by FROM ci_students s LEFT JOIN ci_classes c ON c.id = s.class_id
    LEFT JOIN users u ON u.id = s.created_by WHERE s.id = ?");
$q->execute([$id]);
$s = $q->fetch(PDO::FETCH_ASSOC);
if (!$s) { admin_flash('Student not found.', 'error'); header('Location: /admin/students.php'); exit; }
ci_scope_guard($me, $s['stype'] ?? 'institute'); // only institute / only online staff
$back = fn(string $tab = '') => "/admin/student-view.php?id=$id" . ($tab ? "#$tab" : '');
// popups on list pages post here with back = that page; go back there after saving
$listBack = (string)($_POST['back'] ?? '');
$listBack = preg_match('#^/admin/[\w\-./]*\.php(\?[^\s<>"]*)?$#', $listBack) ? $listBack : '';
if ($listBack) { $listBack = rtrim(str_replace(['?&', '&&'], ['?', '&'], preg_replace('/([?&])(paid|ok)=[^&]*/', '$1', $listBack)), '?&'); } // no old receipt link
$toList = function (string $msg, ?int $paid = null) use ($listBack) {
    $u = preg_replace('/([?&])(paid|ok)=[^&]*/', '$1', $listBack);
    $u = rtrim(str_replace(['?&', '&&'], ['?', '&'], $u), '?&');
    admin_flash($msg);
    header('Location: ' . $u . (str_contains($u, '?') ? '&' : '?') . ($paid ? 'paid=' . $paid : 'ok=1'));
    exit;
};

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
        $parts = ci_parts_from_post((array)($_POST['pm'] ?? []), (array)($_POST['pa'] ?? [])); // one or more modes (split payment)
        $res = $e ? ($parts ? ci_add_payment($pdo, $eid, 0, $parts, ci_clean_date($_POST['paid_on'] ?? ''), trim((string)($_POST['note'] ?? '')), (int)$me['id']) : 'Enter the amount received.')
            : "Choose one of this student's courses.";
        if (is_string($res)) { admin_flash($res, 'error'); header('Location: ' . ($listBack ?: $back('fee'))); exit; }
        // the next due date is typed by hand when some fee is still left (empty = none)
        $pdo->prepare("UPDATE ci_enrollments SET due_date = ? WHERE id = ?")->execute([ci_clean_date($_POST['next_due'] ?? ''), $eid]);
        admin_log('ci_payment', 'Fee ' . ci_money(array_sum(array_column($parts, 1))) . " from {$s['reg_no']} {$s['name']} ({$res[1]})");
        if ($listBack) $toList('Fee saved for ' . $s['name'] . ' (' . $res[1] . ').', $res[0]);
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
    if ($a === 'enroll' && (ci_perm($me, 'edit_student') || ci_perm($me, 'add_student'))) {
        $parts = (ci_perm($me, 'fees') || ci_perm($me, 'add_student')) ? ci_parts_from_post((array)($_POST['epm'] ?? []), (array)($_POST['epa'] ?? [])) : [];
        $fin = trim((string)($_POST['final'] ?? ''));
        $pdo->beginTransaction();
        $res = ci_enroll($pdo, $id, (int)($_POST['course_id'] ?? 0), (int)($_POST['batch_id'] ?? 0) ?: null, (string)($_POST['fee_type'] ?? 'full'),
            $fin === '' ? null : ci_amount($fin), ci_clean_date($_POST['due'] ?? ''), $parts, date('Y-m-d'), (int)$me['id']);
        if (is_string($res)) { $pdo->rollBack(); admin_flash($res, 'error'); header('Location: ' . ($listBack ?: $back())); exit; }
        $pdo->commit();
        admin_log('ci_enroll', "Added {$s['reg_no']} to course #" . (int)$_POST['course_id']);
        if ($listBack) $toList($s['name'] . ' added to the course' . ($res[1] ? ' and the payment saved.' : '.'), $res[1]);
        if (!$res[1]) admin_flash('Course added.'); // with a payment the page shows "Payment saved — Print receipt"
        header("Location: /admin/student-view.php?id=$id" . ($res[1] ? '&receipt=' . $res[1] : ''));
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
        // an online account with courses or orders stays (suspend it instead); an empty one goes with the student
        $oaIds = [];
        try { $oaIds = $pdo->query("SELECT id FROM sh_users WHERE student_id = $id")->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable) {}
        if ($oaIds) {
            $inU = implode(',', array_map('intval', $oaIds));
            if ((int)$pdo->query("SELECT (SELECT COUNT(*) FROM sh_access WHERE user_id IN ($inU) AND source <> 'block') + (SELECT COUNT(*) FROM sh_orders WHERE user_id IN ($inU))")->fetchColumn()) {
                admin_flash('This student has online courses or orders, so they cannot be deleted. Suspend the online account or set the status to Left instead.', 'error'); header('Location: ' . $back()); exit;
            }
        }
        $pdo->beginTransaction();
        if ($oaIds) { $pdo->exec("DELETE FROM sh_access WHERE user_id IN ($inU)"); $pdo->exec("DELETE FROM sh_users WHERE id IN ($inU)"); }
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
$L = ci_lists($pdo);
$classes = $L['classes'];
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
$tel = fn(?string $m) => ci_tel($m);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title><?= ci_e($s['name']) ?> - <?= ci_e(edu_site_name()) ?></title>
<?php admin_shell_head(); ci_head(); ci_report_assets(); ?>
<style id="svAttCss">
.sv-att-sum{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 12px}
.sv-att-sum span{padding:6px 12px;border:1px solid #e5e7eb;font-size:13px;color:#374151}.sv-att-sum b{margin-left:4px;font-size:15px}
.sv-att-sum .p{border-color:#bbf7d0;background:#f0fdf4}.sv-att-sum .a{border-color:#fecaca;background:#fef2f2}.sv-att-sum .l{border-color:#fde68a;background:#fffbeb}.sv-att-sum .h{border-color:#ddd6fe;background:#f5f3ff}
.sv-cal{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:4px}
.sv-cal-h{padding:4px 0;text-align:center;font-size:11.5px;font-weight:700;color:#6b7280;text-transform:uppercase}
.sv-cal-d{position:relative;min-height:56px;padding:5px 6px;border:1px solid #e5e7eb;background:#fff;font-size:12px}
.sv-cal-d b{font-size:12.5px;color:#374151}.sv-cal-d em{position:absolute;top:5px;right:6px;font-style:normal;font-weight:800;font-size:13px}
.sv-cal-d small{display:block;margin-top:14px;font-size:10.5px;color:#4b5563}
.sv-cal-d.is-x{border:0;background:none}.sv-cal-d.is-sun{background:#f9fafb}.sv-cal-d.is-fut{opacity:.45}
.sv-cal-d.is-present{background:#f0fdf4;border-color:#86efac}.sv-cal-d.is-present em{color:#15803d}
.sv-cal-d.is-absent{background:#fef2f2;border-color:#fca5a5}.sv-cal-d.is-absent em{color:#b91c1c}
.sv-cal-d.is-leave{background:#fffbeb;border-color:#fcd34d}.sv-cal-d.is-leave em{color:#b45309}
.sv-cal-d.is-holiday{background:#f5f3ff;border-color:#c4b5fd}.sv-cal-d.is-holiday em{color:#6d28d9}.sv-cal-d.is-holiday small{overflow:hidden;white-space:nowrap;text-overflow:ellipsis}
@media (max-width:640px){.sv-cal-d{min-height:44px;padding:3px}.sv-cal-d small{display:none}.sv-cal-d em{top:auto;bottom:3px;right:4px}}
.sv-att-print{display:none}
</style>
</head>
<body>
<?php admin_shell_open('Student Profile', ci_e($s['reg_no'] . ' · ' . $s['name'])); ?>
<div class="ci">
<?= admin_flash() ?>
<?php if ($newReceipt): ?><div class="ci-alert ci-alert-ok"><i class="fas fa-receipt"></i> Saved. <a href="/admin/ci-receipt.php?id=<?= $newReceipt ?>" target="_blank"><b>Print receipt</b></a></div><?php endif; ?>

<div class="ci-card ci-ph"><div class="ci-card-b">
  <div class="ci-ph-main">
    <?= ci_avatar($s, 88) ?>
    <div class="ci-ph-txt">
      <h2><?= ci_e($s['name']) ?></h2>
      <div class="ci-ph-tags"><span class="ci-badge ci-b-violet"><?= ci_e($s['reg_no']) ?></span><span class="ci-badge ci-tb-<?= ci_e($s['stype'] ?? 'institute') ?>"><?= ci_e((CI_STYPES[$s['stype'] ?? 'institute'] ?? '') . ' student') ?></span><?= ci_status_badge($s['status']) ?><?= $s['class_name'] ? '<span class="ci-badge ci-b-blue">Class ' . ci_e($s['class_name']) . '</span>' : '' ?><?= $tot['due'] > 0.004 ? '<span class="ci-badge ci-b-red">Due ' . ci_money0($tot['due']) . '</span>' : ($tot['net'] > 0 ? '<span class="ci-badge ci-b-green">Fee fully paid</span>' : '') ?></div>
      <div class="ci-ph-meta">
        <span><?= $tel($s['mobile']) ?><?= $s['alt_mobile'] ? ' · ' . $tel($s['alt_mobile']) : '' ?></span>
        <?php if ($s['father_name']): ?><span><i class="fas fa-user"></i> S/D of <?= ci_e($s['father_name']) ?></span><?php endif; ?>
        <?php if ($s['city'] || $s['address']): ?><span><i class="fas fa-location-dot"></i> <?= ci_e($s['city'] ?: $s['address']) ?></span><?php endif; ?>
        <span><i class="fas fa-calendar"></i> Admitted <?= ci_date($s['admission_date']) ?></span>
      </div>
    </div>
  </div>
  <div class="ci-actions ci-noprint">
    <?php if ($s['mobile']): ?><?= ci_ico('phone', 'tel:' . $s['mobile'], 'Call') ?><?php endif; ?>
    <?php if ($wa): ?><a class="ci-ico ci-ico-ok" href="<?= ci_e($wa) ?>" target="_blank" rel="noopener" aria-label="WhatsApp"><i class="fab fa-whatsapp" style="font-size:16px"></i></a><?php endif; ?>
    <?= ci_ico('pdf', null, 'Student report (PDF)', 'button', ' data-report="student" data-id="' . $id . '"') ?>
    <?php if (ci_perm($me, 'edit_student')): ?><?= ci_ico('edit', '/admin/student-edit.php?id=' . $id, 'Edit student') ?><button type="button" class="ci-btn ci-ghost" data-open="ci-addc"><?= ci_svg('book-plus') ?> Add to course / batch</button><?php endif; ?>
    <?php if (function_exists('sh_perm') && sh_perm($me, 'orders')): ?><button type="button" class="ci-btn ci-ghost" data-enroll="Enroll <?= ci_e($s['name']) ?>" data-enroll-sid="<?= $id ?>" data-enroll-name="<?= ci_e($s['name']) ?>"><i class="fas fa-laptop"></i> Enroll in a course / notes (paid)</button><?php endif; ?>
    <?php if (!$pays && ci_perm($me, 'edit_student')): ?><?= ci_post_ico('trash', 'Delete student', ['action' => 'delete', 'id' => $id], 'Delete student ' . $s['name'] . '? This cannot be undone.') ?><?php endif; ?>
    <?php if ($payable && ci_perm($me, 'fees')): ?><button type="button" class="ci-btn" data-collect="<?= (int)$payable[0]['id'] ?>"><i class="fas fa-indian-rupee-sign"></i> Collect Fee</button><?php endif; ?>
  </div>
</div></div>

<?php // one layout for every student (Institute / Online / Both): the same tiles, the same sections in the same order
$oa = null;
if (function_exists('sh_perm') && sh_perm($me, 'customers')) { sh_tables($pdo); require_once __DIR__ . '/components/online-account.php'; $oa = oa_load($pdo, $s); } ?>
<div class="ci-stats ci-stats6 ci-pf-stats">
  <div class="ci-stat"><i class="fas fa-file-invoice ci-i-violet"></i><div><b><?= ci_money0($tot['net']) ?></b><small>Institute fee · <?= count($enr) ?> course<?= count($enr) === 1 ? '' : 's' ?></small></div></div>
  <div class="ci-stat"><i class="fas fa-circle-check ci-i-green"></i><div><b><?= ci_money0($tot['paid']) ?></b><small>Fee paid · <?= count($pays) ?> receipt<?= count($pays) === 1 ? '' : 's' ?></small></div></div>
  <div class="ci-stat"><i class="fas fa-hourglass-half ci-i-red"></i><div><b><?= ci_money0($tot['due']) ?></b><small><?= $late > 0 ? 'Due · ' . ci_money0($late) . ' late' : ($next ? 'Due · next ' . ci_date($next['date']) : 'Due (baaki)') ?></small></div></div>
  <div class="ci-stat"><i class="fas fa-laptop ci-i-blue"></i><div><b><?= $oa ? (int)$oa['open'] : '—' ?></b><small>Online courses / notes open</small></div></div>
  <div class="ci-stat"><i class="fas fa-cart-shopping ci-i-amber"></i><div><b><?= $oa ? ci_money0($oa['spent']) : '—' ?></b><small>Online orders<?= $oa ? ' · ' . (int)$oa['paid_n'] . ' paid' : '' ?></small></div></div>
  <div class="ci-stat"><i class="fas fa-right-to-bracket ci-i-violet"></i><div><b><?= $oa && $oa['u'] && $oa['u']['last_login'] ? date('d M Y', strtotime($oa['u']['last_login'])) : ($oa && $oa['u'] ? 'Never' : 'No login') ?></b><small>Last online login</small></div></div>
</div>

<?php
  $age = $s['dob'] ? (new DateTime($s['dob']))->diff(new DateTime())->y : null;
  $mainKv = array_filter(["Mother's name" => $s['mother_name'], 'Date of birth' => $s['dob'] ? ci_date($s['dob']) . ' (' . $age . ' yrs)' : '', 'Gender' => $s['gender'], 'Email' => $s['email']]);
  $allKv = array_filter(['Full name' => $s['name'], 'Reg. no.' => $s['reg_no'], 'Class' => $s['class_name'], "Father's name" => $s['father_name'], "Mother's name" => $s['mother_name'],
      'Mobile' => $s['mobile'], 'Alternate mobile' => $s['alt_mobile'], 'Email' => $s['email'], 'Date of birth' => $s['dob'] ? ci_date($s['dob']) . ' (' . $age . ' yrs)' : '', 'Gender' => $s['gender'],
      'Address' => $s['address'], 'City' => $s['city'], 'State' => $s['state'], 'PIN code' => $s['pincode'], 'School / College' => $s['school'], 'Admission date' => ci_date($s['admission_date']),
      'Status' => ucfirst($s['status']) . ($s['status'] === 'left' && $s['left_on'] ? ' (' . ci_date($s['left_on']) . ')' : ''), 'Added' => date('d M Y, h:i A', strtotime($s['created_at'])) . ($s['added_by'] ? ' by ' . $s['added_by'] : '')]);
?>
<div class="ci-pf">
  <aside class="ci-pf-side">
    <div class="ci-card ci-det" data-ds="Student details"><div class="ci-card-h"><h3>Student details</h3><?php if (ci_perm($me, 'edit_student')): ?><?= ci_ico('edit', '/admin/student-edit.php?id=' . $id, 'Edit details') ?><?php endif; ?></div><div class="ci-card-b">
      <?php if ($mainKv): ?><dl class="ci-kv ci-kv1"><?php foreach ($mainKv as $k => $v): ?><dt><?= ci_e($k) ?></dt><dd><?= ci_e($v) ?></dd><?php endforeach; ?></dl><?php else: ?><p class="ci-muted" style="margin:0">Nothing more than the card above.</p><?php endif; ?>
      <div class="ci-det-all" hidden><dl class="ci-kv ci-kv1"><?php foreach ($allKv as $k => $v): ?><dt><?= ci_e($k) ?></dt><dd><?= ci_e($v) ?></dd><?php endforeach; ?></dl>
        <?php if ($s['notes']): ?><div class="ci-det-notes"><b>Notes</b><br><?= nl2br(ci_e($s['notes'])) ?></div><?php endif; ?></div>
      <button type="button" class="ci-more-btn" data-more>View all details <i class="fas fa-chevron-down"></i></button>
    </div></div>
    <?php if ($oa) oa_side($oa, $me); // online account (login, status, password, block) ?>
    <div class="ci-card" data-ds="Class history"><div class="ci-card-h"><h3>Class history</h3></div><div class="ci-card-b">
      <ul class="ci-timeline">
        <?php foreach ($history as $h): ?><li><b><?= ci_e($h['from_name'] ?? '—') ?> → <?= ci_e($h['to_name'] ?? '—') ?></b><small><?= ci_date($h['changed_on']) ?><?= $h['session_name'] ? ' · ' . ci_e($h['session_name']) : '' ?><?= $h['note'] ? ' · ' . ci_e($h['note']) : '' ?></small></li><?php endforeach; ?>
        <li><b>Admitted<?= !$history && $s['class_name'] ? ' in ' . ci_e($s['class_name']) : '' ?></b><small><?= ci_date($s['admission_date']) ?></small></li>
      </ul>
    </div></div>
  </aside>
  <div class="ci-pf-main">
    <div class="ci-sec-h" data-ds="Courses &amp; batches"><h3>Institute courses &amp; batches <span class="ci-muted">(<?= count($enr) ?>)</span></h3><?php if (ci_perm($me, 'edit_student') || ci_perm($me, 'add_student')): ?><button type="button" class="ci-btn ci-sm ci-noprint" data-open="ci-addc"><?= ci_svg('book-plus') ?> Add to course / batch</button><?php endif; ?></div>
    <?php if (!$enr): ?><div class="ci-card"><div class="ci-card-b ci-empty">Not in any institute course / batch yet.</div></div><?php endif; ?>
    <?php foreach ($enr as $e): $eid = (int)$e['id']; $bi = $batchInfo[$e['batch_id']] ?? []; $dropped = $e['status'] === 'dropped'; $inst = ($e['fee_type'] ?? 'full') === 'installment'; $ep = array_reverse($byEnr[$eid] ?? []); $late = $e['due_date'] && $e['due_date'] < date('Y-m-d') && (float)$e['due'] > 0.004; ?>
    <div class="ci-cc<?= $e['status'] !== 'active' ? ' is-off' : '' ?>" id="enr-<?= $eid ?>">
      <div class="ci-cc-h">
        <div class="ci-cc-t"><h4><?= ci_e($e['course_name'] ?? '—') ?></h4>
          <div class="ci-cc-tags"><span class="ci-badge <?= $inst ? 'ci-b-blue' : 'ci-b-violet' ?>"><?= $inst ? 'Installment' : 'Final fee' ?></span><?= ci_status_badge($e['status']) ?><?= (float)$e['due'] > 0.004 ? '<span class="ci-badge ci-b-red">Due ' . ci_money0($e['due']) . '</span>' : ((float)$e['paid'] > 0 ? '<span class="ci-badge ci-b-green">Paid</span>' : '') ?><?= $late ? '<span class="ci-badge ci-b-amber">Due date passed</span>' : '' ?></div>
          <div class="ci-cc-meta"><?= ci_e(implode(' · ', array_filter([$e['batch_name'] ? 'Batch ' . $e['batch_name'] : 'No batch', $e['timing'], $bi['days'] ?? '', ($bi['teacher'] ?? '') ? 'Teacher ' . $bi['teacher'] : '', $e['session_name'] ? 'Session ' . $e['session_name'] : '']))) ?></div></div>
        <div class="ci-icos ci-noprint"><?php if (!$dropped && (float)$e['due'] > 0.004 && ci_perm($me, 'fees')): ?><button type="button" class="ci-btn ci-sm" data-collect="<?= $eid ?>"><?= ci_svg('rupee') ?> Collect</button><?php endif; ?><?php if (ci_perm($me, 'edit_student')): ?><?= ci_ico('edit', '/admin/student-edit.php?id=' . $id . '#enr-' . $eid, 'Change course / fee') ?><?php endif; ?></div>
      </div>
      <div class="ci-cc-money">
        <div><small>Course fee</small><b><?= ci_money0($e['fee']) ?></b></div>
        <div><small>Discount</small><b><?= (float)$e['discount'] > 0 ? '−' . ci_money0($e['discount']) : '—' ?></b></div>
        <div><small>Fee</small><b><?= ci_money0($dropped ? 0 : $e['net']) ?></b></div>
        <div><small>Paid</small><b class="ci-g"><?= ci_money0($e['paid']) ?></b></div>
        <div><small>Due</small><b class="<?= (float)$e['due'] > 0.004 ? 'ci-r' : 'ci-g' ?>"><?= ci_money0($e['due']) ?></b></div>
        <div><small>Next due</small><b<?= $late ? ' class="ci-r"' : '' ?>><?= (float)$e['due'] > 0.004 && $e['due_date'] ? ci_date($e['due_date']) : '—' ?></b></div>
      </div>
      <details class="ci-cc-pays"<?= $ep ? '' : ' hidden' ?>><summary><?= $inst ? 'Installments paid' : 'Payments' ?> (<?= count($ep) ?>)</summary>
        <table class="ci-table ci-plan"><thead><tr><th><?= $inst ? 'Installment' : '#' ?></th><th>Date</th><th>Receipt</th><th>Paid by</th><th class="ci-num">Amount</th><th class="ci-noprint"></th></tr></thead><tbody>
        <?php foreach ($ep as $p): ?><tr><td><b><?= $inst ? 'Installment ' . $p['no'] : $p['no'] ?></b></td><td class="ci-nw"><?= ci_date($p['paid_on']) ?></td><td><?= ci_e($p['receipt_no']) ?></td><td><?= ci_e(ci_pay_mode($p)) ?><?= $p['username'] ? '<small class="ci-by">by ' . ci_e($p['username']) . '</small>' : '' ?></td><td class="ci-num"><b class="ci-g"><?= ci_money($p['amount']) ?></b></td><td class="ci-noprint"><a class="ci-ico" href="/admin/ci-receipt.php?id=<?= (int)$p['id'] ?>" target="_blank" aria-label="Print receipt"><?= ci_svg('print') ?></a></td></tr><?php endforeach; ?>
        </tbody></table></details>
    </div>
    <?php endforeach; ?>

    <div class="ci-card" data-ds="Fee statement" style="margin-top:16px"><div class="ci-card-h"><h3>Institute fee statement</h3><div class="ci-actions ci-noprint"><button type="button" class="ci-btn ci-ghost ci-sm" onclick="window.print()"><?= ci_svg('print') ?> Print</button></div></div>
    <div class="ci-card-b ci-p0 ci-table-wrap"><table class="ci-table">
    <thead><tr><th>Date</th><th>Receipt</th><th>For</th><th>Paid by</th><th class="ci-num">Amount</th><th class="ci-num">Balance after</th><th class="ci-noprint"></th></tr></thead><tbody>
    <?php if (!$pays): ?><tr><td colspan="7" class="ci-empty">No payments yet.</td></tr><?php endif; ?>
    <?php foreach ($pays as $p): ?>
    <tr<?= (int)$p['id'] === $newReceipt ? ' class="ci-hl"' : '' ?>><td class="ci-nw"><?= ci_date($p['paid_on']) ?></td><td class="ci-nw"><b><?= ci_e($p['receipt_no']) ?></b><?= $p['note'] ? '<small class="ci-muted" style="display:block">' . ci_e($p['note']) . '</small>' : '' ?></td><td><?= ci_e($p['course_name'] ?? '—') ?></td>
    <td><?= ci_e(ci_pay_mode($p)) ?><?= $p['username'] ? '<small class="ci-by">by ' . ci_e($p['username']) . '</small>' : '' ?></td><td class="ci-num"><b class="ci-g"><?= ci_money($p['amount']) ?></b></td><td class="ci-num"><?= ci_money($p['balance']) ?></td>
    <td class="ci-noprint"><div class="ci-icos"><a class="ci-ico" href="/admin/ci-receipt.php?id=<?= (int)$p['id'] ?>" target="_blank" aria-label="Print receipt"><?= ci_svg('print') ?></a>
    <?php if ($me['role'] === 'admin'): ?><?= ci_post_ico('trash', 'Delete payment', ['action' => 'pay_delete', 'id' => $id, 'payment_id' => $p['id']], 'Delete payment ' . $p['receipt_no'] . '? The due goes up again.') ?><?php endif; ?>
    </div></td></tr>
    <?php endforeach; ?>
    <?php if ($pays): ?><tr class="ci-total-row"><td colspan="4">Total paid</td><td class="ci-num"><?= ci_money($tot['paid']) ?></td><td class="ci-num"><?= ci_money($tot['due']) ?></td><td class="ci-noprint"></td></tr><?php endif; ?>
    </tbody></table></div></div>
    <?php if ($s['stype'] !== 'online'): // ---- attendance: one month as a calendar (any month since admission), printable
      $mNow = date('Y-m'); $mFirst = substr((string)($s['admission_date'] ?: $s['created_at']), 0, 7) ?: $mNow;
      $fq = $pdo->prepare("SELECT MIN(att_date) FROM ci_attendance WHERE student_id = ?"); $fq->execute([$id]); if ($fa = $fq->fetchColumn()) $mFirst = min($mFirst, substr($fa, 0, 7));
      $am = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['att'] ?? '')) ? (string)$_GET['att'] : $mNow; if ($am > $mNow) $am = $mNow; if ($am < $mFirst) $mFirst = $am;
      $AT = ci_att_student($pdo, $id, $am . '-01', date('Y-m-t', strtotime($am . '-01'))); // marks + holidays, by day
      $byDay = $AT['days']; $marked = $AT['count']['days'] + $AT['count']['holiday'];
      $cnt = ['present' => 0, 'absent' => 0, 'leave' => 0, 'holiday' => 0]; foreach ($byDay as $dk => $x) if ($dk <= date('Y-m-d')) $cnt[$x['status']]++; // counts till today (coming holidays only show)
      $pc = ($cnt['present'] + $cnt['absent'] + $cnt['leave']) ? (int)round($cnt['present'] / ($cnt['present'] + $cnt['absent'] + $cnt['leave']) * 100) : null;
      $months = []; for ($t = strtotime($mFirst . '-01'); date('Y-m', $t) <= $mNow; $t = strtotime('+1 month', $t)) $months[date('Y-m', $t)] = date('F Y', $t);
      $months = array_reverse($months, true);
      $first = strtotime($am . '-01'); $lead = (int)date('N', $first) - 1; $nd = (int)date('t', $first); $todayD = date('Y-m-d');
      $instInfo = ci_institute(); ?>
    <div class="ci-card sv-att" id="attendance" data-ds="Attendance" style="margin-top:16px">
      <div class="sv-att-print"><b><?= ci_e($instInfo['name']) ?></b><span><?= ci_e(implode(' · ', array_filter([$instInfo['address'], $instInfo['phone'], $instInfo['email']]))) ?></span>
        <h2>Attendance — <?= ci_e($months[$am] ?? date('F Y', $first)) ?></h2>
        <p><?= ci_e($s['name']) ?> · Adm. ID <?= ci_e($s['reg_no']) ?><?= $s['class_name'] ? ' · Class ' . ci_e($s['class_name']) : '' ?></p></div>
      <div class="ci-card-h"><h3>Attendance</h3><div class="ci-actions ci-noprint">
        <form method="get" action="#attendance" style="display:inline-flex;gap:6px;align-items:center" onchange="this.submit()"><input type="hidden" name="id" value="<?= $id ?>"><select name="att" aria-label="Month"><?php foreach ($months as $k => $l): ?><option value="<?= $k ?>"<?= $k === $am ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></form>
        <button type="button" class="ci-btn ci-ghost ci-sm" id="svAttPrint"><i class="fas fa-print"></i> Print</button></div></div>
      <div class="ci-card-b">
        <div class="sv-att-sum"><span class="p">Present <b><?= $cnt['present'] ?></b></span><span class="a">Absent <b><?= $cnt['absent'] ?></b></span><span class="l">Leave <b><?= $cnt['leave'] ?></b></span><span class="h">Holiday <b><?= $cnt['holiday'] ?></b></span><span>Attendance <b><?= $pc === null ? '—' : $pc . '%' ?></b></span></div>
        <div class="sv-cal" role="table" aria-label="Attendance calendar">
          <?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $wd): ?><div class="sv-cal-h"><?= $wd ?></div><?php endforeach; ?>
          <?php for ($i = 0; $i < $lead; $i++): ?><div class="sv-cal-d is-x"></div><?php endfor; ?>
          <?php for ($dd = 1; $dd <= $nd; $dd++): $dt = $am . '-' . str_pad((string)$dd, 2, '0', STR_PAD_LEFT); $x = $byDay[$dt] ?? null; $st = $x['status'] ?? '';
            $in = $x['in'] ?? null; $outT = $x['out'] ?? null; ?>
          <div class="sv-cal-d<?= $st ? ' is-' . $st : '' ?><?= date('N', strtotime($dt)) === '7' ? ' is-sun' : '' ?><?= $dt > $todayD ? ' is-fut' : '' ?>" title="<?= ci_e(date('D, d M Y', strtotime($dt)) . ($st ? ' — ' . ucfirst($st) : '') . (!empty($x['title']) ? ': ' . $x['title'] : '') . (!empty($x['batch']) ? ' · ' . $x['batch'] : '')) ?>">
            <b><?= $dd ?></b><?php if ($st): ?><em><?= ['present' => 'P', 'absent' => 'A', 'leave' => 'L', 'holiday' => 'H'][$st] ?></em><?php endif; ?>
            <?php if ($in): ?><small><?= date('h:i', strtotime($in)) ?><?= $outT ? '–' . date('h:i', strtotime($outT)) : '' ?></small><?php elseif (!empty($x['title'])): ?><small><?= ci_e($x['title']) ?></small><?php endif; ?>
          </div>
          <?php endfor; ?>
        </div>
        <?php if (!$marked): ?><p class="ci-muted" style="margin:10px 0 0">No attendance marked in <?= ci_e($months[$am] ?? '') ?>.</p><?php endif; ?>
      </div>
    </div>
    <script>
    document.getElementById('svAttPrint').addEventListener('click', function () { // only this card, on its own page
      var card = document.getElementById('attendance').cloneNode(true), css = document.getElementById('svAttCss').textContent;
      card.querySelector('.ci-card-h').remove(); card.querySelector('.sv-att-print').style.display = 'block';
      var w = window.open('', '_blank', 'width=900,height=1000'); if (!w) return;
      w.document.write('<!DOCTYPE html><html><head><meta charset="utf-8"><title>Attendance</title><style>' + css + 'body{margin:0;padding:12mm;font-family:Arial,sans-serif;color:#111827;-webkit-print-color-adjust:exact;print-color-adjust:exact}'
        + '.sv-att-print{margin:0 0 10px;padding:0 0 8px;border-bottom:1px solid #111}.sv-att-print b{font-size:18px}.sv-att-print span{display:block;font-size:11px;color:#374151}.sv-att-print h2{margin:10px 0 2px;font-size:16px}.sv-att-print p{margin:0;font-size:12px}'
        + '.ci-muted{color:#6b7280}@page{size:A4;margin:0}</style></head><body>' + card.innerHTML + '</body></html>');
      w.document.close(); w.focus(); setTimeout(function () { w.print(); }, 250);
    });
    </script>
    <?php endif; ?>
    <?php if ($oa) { echo '<div style="height:20px"></div>'; oa_main($oa, $me); } ?>
  </div>
</div>
<?php if ($oa) oa_modals($oa); ?>

<?php if (ci_perm($me, 'edit_student') || ci_perm($me, 'add_student')): $myClass = (string)(int)$s['class_id']; ?>
<div class="ci-modal" id="ci-addc" hidden><div class="ci-modal-box ci-modal-wide" role="dialog" aria-modal="true" aria-labelledby="ci-addc-h">
  <div class="ci-card-h"><h3 id="ci-addc-h">Add <?= ci_e($s['name']) ?> to a course / batch</h3><button type="button" class="ci-x" data-close aria-label="Close"><i class="fas fa-times"></i></button></div>
  <form method="post" class="ci-card-b ci-float" id="ci-addc-f"><?= csrf_field() ?><input type="hidden" name="action" value="enroll"><input type="hidden" name="id" value="<?= $id ?>">
    <div class="ci-grid2" style="gap:16px 12px">
      <div class="ci-f"><label>Course</label><select name="course_id" id="ac-course" data-search required><option value=""></option>
        <?php $mine = array_filter($L['courses'], fn($c) => $c['status'] === 'active' && (string)(int)$c['class_id'] === $myClass && $myClass !== '0');
              $rest = array_filter($L['courses'], fn($c) => $c['status'] === 'active' && !((string)(int)$c['class_id'] === $myClass && $myClass !== '0'));
              $opt = fn($cid, $c) => '<option value="' . $cid . '" data-fee="' . (float)$c['fee'] . '">' . ci_e($c['name']) . ' — ' . ci_money0($c['fee']) . '</option>';
              if ($mine) { echo '<optgroup label="Class ' . ci_e($s['class_name']) . ' courses">'; foreach ($mine as $cid => $c) echo $opt($cid, $c); echo '</optgroup><optgroup label="Other courses">'; foreach ($rest as $cid => $c) echo $opt($cid, $c); echo '</optgroup>'; }
              else foreach ($rest as $cid => $c) echo $opt($cid, $c); ?></select></div>
      <div class="ci-f"><label>Batch</label><select name="batch_id" id="ac-batch" data-search><option value="">Choose the course first</option></select></div>
      <div class="ci-f"><label>Fee plan</label><select name="fee_type" id="ac-type"><option value="full">One-time fee</option><option value="installment">Installment</option></select></div>
      <div class="ci-f"><label>Fee for this student (₹)</label><input type="number" name="final" id="ac-final" min="0" step="0.01"></div>
      <?php if (ci_perm($me, 'fees') || ci_perm($me, 'add_student')): ?><div class="ci-f ci-nofloat" style="grid-column:1/-1"><?= ci_pays_html('epm[]', 'epa[]', [], 'Paying now') ?></div><?php endif; ?>
      <div class="ci-f" id="ac-due-f" hidden><label>Next due date</label><input type="date" name="due"></div>
    </div>
    <div class="ci-row-sum" id="ac-sum" style="margin-top:12px"></div>
    <div style="display:flex;gap:8px;margin-top:16px"><button class="ci-btn" type="submit"><?= ci_svg('check') ?> Save</button><button type="button" class="ci-btn ci-ghost" data-close>Cancel</button></div>
  </form>
</div></div>
<?php endif; ?>
<?php if ($payable): ?>
<div class="ci-modal" id="ci-collect" hidden><div class="ci-modal-box" role="dialog" aria-modal="true" aria-labelledby="ci-collect-h">
  <div class="ci-card-h"><h3 id="ci-collect-h">Collect fee — <?= ci_e($s['name']) ?></h3><button type="button" class="ci-x" data-close aria-label="Close"><i class="fas fa-times"></i></button></div>
  <form method="post" class="ci-card-b"><?= csrf_field() ?><input type="hidden" name="action" value="pay"><input type="hidden" name="id" value="<?= $id ?>">
    <div class="ci-f"><label>Fee for course</label><select name="enrollment_id" id="ci-pay-for" required><?php foreach ($payable as $e): ?>
      <option value="<?= (int)$e['id'] ?>" data-due="<?= (float)$e['due'] ?>" data-net="<?= (float)$e['net'] ?>" data-inst="<?= ($e['fee_type'] ?? 'full') === 'installment' ? 1 : 0 ?>" data-n="<?= count($byEnr[$e['id']] ?? []) + 1 ?>"><?= ci_e($e['course_name'] . ($e['batch_name'] ? ' · ' . $e['batch_name'] : '')) ?> — due <?= ci_money0($e['due']) ?></option>
    <?php endforeach; ?></select></div>
    <div class="ci-chips" id="ci-chips"></div>
    <div class="ci-grid2" style="gap:12px;margin-top:12px">
      <div class="ci-f ci-nofloat" style="grid-column:1/-1"><?= ci_pays_html('pm[]', 'pa[]', [], 'Amount received') ?></div>
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
<?php if (function_exists('sh_perm') && sh_perm($me, 'orders')) require __DIR__ . '/components/sh-enroll.php'; ?>
<?php if (!empty($_GET['online'])): ?><script>window.addEventListener('load',function(){var b=document.querySelector('[data-enroll-sid]');if(b)b.click();});</script><?php endif; ?>
<?php admin_shell_close(); ?>
<script>
(function () {
  var inr = function (n) { return '₹' + (Math.round(n * 100) / 100).toLocaleString('en-IN'); };
  // Add to course / batch (popup on this page)
  (function () {
    var f = document.getElementById('ci-addc-f'); if (!f) return;
    var B = <?= json_encode(array_values(array_map(fn($b, $bid) => ['id' => $bid, 'c' => (int)$b['course_id'], 'n' => $b['name'] . ($b['timing'] ? ' · ' . $b['timing'] : '') . ($b['session_name'] ? ' (' . $b['session_name'] . ')' : ''), 'a' => $b['status'] === 'active'], $L['batches'], array_keys($L['batches']))), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
    var course = f.querySelector('#ac-course'), batch = f.querySelector('#ac-batch'), type = f.querySelector('#ac-type'), fin = f.querySelector('#ac-final'), sum = f.querySelector('#ac-sum');
    var inr = function (n) { return '₹' + (Math.round(n * 100) / 100).toLocaleString('en-IN'); };
    var fee = function () { var o = course.options[course.selectedIndex]; return o && o.value ? +o.dataset.fee : null; };
    var first = function () { return f.querySelector('.ci-pay-a'); };
    function onePay(v) { f.querySelectorAll('.ci-pay-row').forEach(function (r, k) { if (k) r.remove(); }); if (first()) { first().value = v; f.querySelector('[data-pays]').dispatchEvent(new Event('input', { bubbles: true })); } }
    function paid() { var t = 0; f.querySelectorAll('.ci-pay-a').forEach(function (i) { t += parseFloat(i.value) || 0; }); return t; }
    function show() {
      var l = fee(), v = parseFloat(fin.value);
      f.querySelector('#ac-due-f').hidden = type.value !== 'installment';
      if (l === null || isNaN(v)) { sum.innerHTML = ''; return; }
      var p = paid(), d = Math.max(0, v - p);
      sum.innerHTML = '<span>Course fee <b>' + inr(l) + '</b></span>' + (v < l ? '<span>Discount <b>' + inr(l - v) + '</b></span>' : '') + '<span>Fee <b>' + inr(v) + '</b></span><span>Paid now <b class="ci-g">' + inr(p) + '</b></span><span>Due <b class="' + (d > 0 ? 'ci-r' : 'ci-g') + '">' + inr(d) + '</b></span>' + (p > v + 0.004 ? '<span class="ci-r"><b>More than the fee</b></span>' : '');
    }
    course.addEventListener('change', function () {
      var c = +course.value, n = 0;
      batch.innerHTML = '<option value="">' + (c ? 'Choose batch…' : 'Choose the course first') + '</option>';
      B.forEach(function (b) { if (b.c === c && b.a) { var o = document.createElement('option'); o.value = b.id; o.textContent = b.n; batch.appendChild(o); n++; } });
      if (c && !n) batch.options[0].textContent = 'No batch for this course';
      var l = fee(); fin.value = l === null ? '' : l; onePay(type.value === 'full' && l !== null ? l : ''); show();
    });
    type.addEventListener('change', function () { onePay(type.value === 'full' ? fin.value : ''); show(); if (type.value === 'installment' && first()) first().focus(); });
    fin.addEventListener('input', function () { if (type.value === 'full') onePay(fin.value); show(); });
    f.addEventListener('input', function (e) { if (e.target.closest('[data-pays]')) show(); });
    f.addEventListener('ci-pays', show);
    f.addEventListener('submit', function (e) { if (!course.value) { e.preventDefault(); alert('Choose a course.'); } });
    if (/[?&]addcourse=1/.test(location.search)) { var md = document.getElementById('ci-addc'); md.hidden = false; }
  })();
  // View all details
  document.querySelectorAll('[data-more]').forEach(function (b) { b.addEventListener('click', function () { var box = b.parentNode.querySelector('.ci-det-all'), open = box.hidden; box.hidden = !open; b.classList.toggle('open', open); b.firstChild.textContent = open ? 'Show less ' : 'View all details '; }); });
  // a course card linked from elsewhere (#enr-N) opens its payments
  if (/^#enr-\d+$/.test(location.hash)) { var cc = document.querySelector(location.hash); if (cc) { var dt = cc.querySelector('details'); if (dt) dt.open = true; cc.scrollIntoView({ block: 'center' }); } }

  // simple modals (promote)
  document.querySelectorAll('[data-open]').forEach(function (b) { b.addEventListener('click', function () { var md = document.getElementById(b.dataset.open); if (md) { md.hidden = false; (window.ciLock ? ciLock(true) : null); } }); });
  document.querySelectorAll('.ci-modal').forEach(function (md) { if (md.id === 'ci-collect') return; md.addEventListener('click', function (e) { if (e.target === md || e.target.closest('[data-close]')) { md.hidden = true; (window.ciLock ? ciLock(false) : null); } }); });
  // collect fee box
  var m = document.getElementById('ci-collect');
  if (m) {
    var f = document.getElementById('ci-pay-for'), amtFirst = function () { return m.querySelector('.ci-pay-a'); }, chips = document.getElementById('ci-chips'), nd = document.getElementById('ci-nd'), info = document.getElementById('ci-pay-info');
    var note = m.querySelector('[name=note]');
    var refresh = function () {
      var o = f.options[f.selectedIndex], due = +o.dataset.due, inst = o.dataset.inst === '1', n = +o.dataset.n;
      chips.innerHTML = '<button type="button" class="ci-chip" data-v="' + due + '">Full due · ' + inr(due) + '</button>';
      // one payment row again, filled with the due (installment: empty — type what the student gives now)
      m.querySelectorAll('.ci-pay-row').forEach(function (r, k) { if (k) r.remove(); });
      amtFirst().value = inst ? '' : due;
      m.querySelector('[data-pays]').dispatchEvent(new Event('input', { bubbles: true }));
      note.value = inst ? 'Installment ' + n : '';
      nd.style.display = '';
      info.textContent = inst ? 'Installment ' + n + ': enter any amount up to the due (' + inr(due) + '). Pay again later for the rest.' : 'Any amount up to the due can be paid.';
    };
    chips.addEventListener('click', function (e) { var b = e.target.closest('.ci-chip'); if (b) { m.querySelectorAll('.ci-pay-row').forEach(function (r, k) { if (k) r.remove(); }); amtFirst().value = b.dataset.v; m.querySelector('[data-pays]').dispatchEvent(new Event('input', { bubbles: true })); } });
    f.addEventListener('change', refresh);
    var open = function (eid) { if (eid) { f.value = eid; } refresh(); m.hidden = false; (window.ciLock ? ciLock(true) : null); setTimeout(function () { var a = amtFirst(); a.focus(); a.select(); }, 30); };
    var close = function () { m.hidden = true; (window.ciLock ? ciLock(false) : null); };
    document.querySelectorAll('[data-collect]').forEach(function (b) { b.addEventListener('click', function () { open(b.dataset.collect); }); });
    m.addEventListener('click', function (e) { if (e.target === m || e.target.closest('[data-close]')) close(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !m.hidden && !document.querySelector('.dk-pop')) close(); });
    if (location.search.indexOf('collect=1') > -1) open();
  }

})();
</script>
</body>
</html>
