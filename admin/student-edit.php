<?php
// Add / edit a student: personal details, photo, class, and the courses/batches the student is enrolled in (with the
// fee of each), plus — when adding — the fee received at admission (saved as the first payment with a receipt).
require_once __DIR__ . '/components/guard.php';
require_once INCLUDES_PATH . '/institute.php';
$me = ci_page_start($pdo);
$L = ci_lists($pdo);
$curSession = ci_current_session($pdo);

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$student = null;
if ($id) {
    $q = $pdo->prepare("SELECT * FROM ci_students WHERE id = ?");
    $q->execute([$id]);
    $student = $q->fetch(PDO::FETCH_ASSOC);
    if (!$student) { admin_flash('Student not found.', 'error'); header('Location: /admin/students.php'); exit; }
}
$enrolls = $id ? ci_enrollments($pdo, $id) : [];
$errors = [];
$form = $student ?: ['status' => 'active', 'admission_date' => date('Y-m-d')];

/** Mobile number as 10 digits (drops +91 / 0 / spaces), or '' when it is not a valid Indian mobile. */
function ci_mobile(string $v): string
{
    $d = preg_replace('/\D+/', '', $v);
    if (strlen($d) === 12 && str_starts_with($d, '91')) $d = substr($d, 2);
    if (strlen($d) === 11 && $d[0] === '0') $d = substr($d, 1);
    return preg_match('/^[6-9]\d{9}$/', $d) ? $d : '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $in = fn($k, $max = 150) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max);
    $form = [
        'name' => $in('name'), 'father_name' => $in('father_name'), 'mother_name' => $in('mother_name'),
        'dob' => ci_clean_date($_POST['dob'] ?? ''), 'gender' => in_array($_POST['gender'] ?? '', ['Male', 'Female', 'Other'], true) ? $_POST['gender'] : null,
        'mobile' => $in('mobile', 20), 'alt_mobile' => $in('alt_mobile', 20), 'email' => $in('email'),
        'address' => $in('address', 500), 'city' => $in('city', 100), 'state' => $in('state', 100), 'pincode' => $in('pincode', 10),
        'class_id' => isset($L['classes'][(int)($_POST['class_id'] ?? 0)]) ? (int)$_POST['class_id'] : null,
        'school' => $in('school'), 'admission_date' => ci_clean_date($_POST['admission_date'] ?? '') ?: date('Y-m-d'),
        'status' => in_array($_POST['status'] ?? '', ['active', 'left', 'completed'], true) ? $_POST['status'] : 'active',
        'notes' => $in('notes', 2000), 'photo' => $student['photo'] ?? null, 'reg_no' => $student['reg_no'] ?? '',
    ];
    if ($form['name'] === '') $errors[] = 'Enter the student name.';
    $mob = ci_mobile($form['mobile']);
    if ($mob === '') $errors[] = 'Enter a valid 10-digit mobile number.'; else $form['mobile'] = $mob;
    if ($form['alt_mobile'] !== '') { $alt = ci_mobile($form['alt_mobile']); if ($alt === '') $errors[] = 'The alternate mobile number is not valid.'; else $form['alt_mobile'] = $alt; }
    if ($form['email'] !== '' && !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'The email address is not valid.';
    if ($form['pincode'] !== '' && !preg_match('/^\d{6}$/', $form['pincode'])) $errors[] = 'PIN code must be 6 digits.';
    if ($form['dob'] && $form['dob'] > date('Y-m-d')) $errors[] = 'Date of birth is in the future.';
    if (!$id && !$errors) {
        $dup = $pdo->prepare("SELECT reg_no FROM ci_students WHERE mobile = ? AND LOWER(name) = LOWER(?) LIMIT 1");
        $dup->execute([$form['mobile'], $form['name']]);
        if ($r = $dup->fetchColumn()) $errors[] = "This student is already added ($r). Open the student list to edit them.";
    }

    // enrollment rows: e_id[] (0 = new), e_course[], e_batch[], e_fee[], e_discount[], e_due[], e_status[], e_remove[]
    $rows = [];
    foreach ((array)($_POST['e_course'] ?? []) as $i => $c) {
        $eid = (int)($_POST['e_id'][$i] ?? 0);
        $c = (int)$c;
        $b = (int)($_POST['e_batch'][$i] ?? 0) ?: null;
        $remove = !empty($_POST['e_remove'][$i]);
        if (!$eid && !$c) continue; // empty new row
        if (!isset($L['courses'][$c])) { $errors[] = 'Choose a course in every course row.'; continue; }
        if ($b && (!isset($L['batches'][$b]) || (int)$L['batches'][$b]['course_id'] !== $c)) { $errors[] = 'A chosen batch does not belong to its course.'; continue; }
        $fee = ci_amount($_POST['e_fee'][$i] ?? 0);
        $disc = ci_amount($_POST['e_discount'][$i] ?? 0);
        if ($disc > $fee) $errors[] = 'A discount is bigger than its fee.';
        $status = in_array($_POST['e_status'][$i] ?? '', ['active', 'completed', 'dropped'], true) ? $_POST['e_status'][$i] : 'active';
        $rows[] = ['id' => $eid, 'course_id' => $c, 'batch_id' => $b, 'session_id' => $b ? ((int)$L['batches'][$b]['session_id'] ?: $curSession) : $curSession,
            'fee' => $fee, 'discount' => $disc, 'due_date' => ci_clean_date($_POST['e_due'][$i] ?? ''), 'status' => $status, 'remove' => $remove];
    }
    $known = array_column($enrolls, null, 'id');
    foreach ($rows as $r) {
        if ($r['id'] && !isset($known[$r['id']])) $errors[] = 'An enrollment row is out of date, please reload the page.';
        if ($r['id'] && $r['remove'] && (float)$known[$r['id']]['paid'] > 0) $errors[] = 'A course with payments cannot be removed; set it to Dropped instead.';
        if ($r['id'] && !$r['remove'] && $r['fee'] - $r['discount'] < (float)$known[$r['id']]['paid'] - 0.004) $errors[] = 'For ' . $known[$r['id']]['course_name'] . ' the fee after discount is less than what is already paid (' . ci_money($known[$r['id']]['paid']) . ').';
        if (!$r['remove'] && $r['batch_id'] && $r['status'] === 'active') {
            $cap = (int)($L['batches'][$r['batch_id']]['capacity'] ?? 0);
            if ($cap) {
                $n = $pdo->prepare("SELECT COUNT(*) FROM ci_enrollments WHERE batch_id = ? AND status = 'active' AND id <> ?");
                $n->execute([$r['batch_id'], $r['id']]);
                if ((int)$n->fetchColumn() >= $cap && (!$r['id'] || (int)$known[$r['id']]['batch_id'] !== $r['batch_id'])) $errors[] = 'Batch ' . $L['batches'][$r['batch_id']]['name'] . ' is full (' . $cap . ' seats).';
            }
        }
    }
    $ids = array_filter(array_column(array_filter($rows, fn($r) => !$r['remove']), 'batch_id'));
    if (count($ids) !== count(array_unique($ids))) $errors[] = 'The same batch is chosen twice.';

    $payAmt = ci_amount($_POST['pay_amount'] ?? 0);
    $payMethod = (string)($_POST['pay_method'] ?? 'Cash');
    if (!$id && $payAmt > 0) {
        $firstNew = array_values(array_filter($rows, fn($r) => !$r['id'] && !$r['remove']))[0] ?? null;
        if (!$firstNew) $errors[] = 'Add a course before entering the fee received.';
        elseif ($payAmt > $firstNew['fee'] - $firstNew['discount'] + 0.004) $errors[] = 'The fee received is more than the course fee after discount.';
    }

    $photoFile = $_FILES['photo'] ?? ['error' => UPLOAD_ERR_NO_FILE];
    $newPhoto = '';
    if (!$errors) {
        $pdo->beginTransaction();
        try {
            if (!$id) {
                for ($try = 0; $try < 3; $try++) {
                    $form['reg_no'] = ci_next_no($pdo, 'ci_students', 'reg_no', CI_REG_PREFIX, 4);
                    try {
                        $pdo->prepare("INSERT INTO ci_students (reg_no, name, mobile, created_by) VALUES (?, ?, ?, ?)")->execute([$form['reg_no'], $form['name'], $form['mobile'], (int)$me['id']]);
                        break;
                    } catch (PDOException $e) { if ($e->getCode() !== '23000' || $try === 2) throw $e; }
                }
                $id = (int)$pdo->lastInsertId();
            }
            [$newPhoto, $perr] = ci_save_photo($photoFile, $form['reg_no']);
            if ($perr) throw new RuntimeException($perr);
            $oldPhoto = $form['photo'] ?? null;
            if ($newPhoto) $form['photo'] = $newPhoto;
            elseif (!empty($_POST['photo_remove'])) $form['photo'] = null;
            $pdo->prepare("UPDATE ci_students SET name=?, father_name=?, mother_name=?, dob=?, gender=?, mobile=?, alt_mobile=?, email=?, address=?, city=?, state=?, pincode=?,
                class_id=?, school=?, photo=?, status=?, admission_date=?, notes=? WHERE id=?")->execute([
                $form['name'], $form['father_name'] ?: null, $form['mother_name'] ?: null, $form['dob'], $form['gender'], $form['mobile'], $form['alt_mobile'] ?: null,
                $form['email'] ?: null, $form['address'] ?: null, $form['city'] ?: null, $form['state'] ?: null, $form['pincode'] ?: null, $form['class_id'],
                $form['school'] ?: null, $form['photo'], $form['status'], $form['admission_date'], $form['notes'] ?: null, $id]);
            $firstNewId = null;
            foreach ($rows as $r) {
                if ($r['id'] && $r['remove']) {
                    $pdo->prepare("DELETE FROM ci_enrollments WHERE id = ? AND student_id = ? AND NOT EXISTS (SELECT 1 FROM ci_payments p WHERE p.enrollment_id = ?)")->execute([$r['id'], $id, $r['id']]);
                } elseif ($r['id']) {
                    $pdo->prepare("UPDATE ci_enrollments SET course_id=?, batch_id=?, session_id=?, fee=?, discount=?, due_date=?, status=? WHERE id=? AND student_id=?")
                        ->execute([$r['course_id'], $r['batch_id'], $r['session_id'], $r['fee'], $r['discount'], $r['due_date'], $r['status'], $r['id'], $id]);
                } elseif (!$r['remove']) {
                    $pdo->prepare("INSERT INTO ci_enrollments (student_id, course_id, batch_id, session_id, fee, discount, due_date, enrolled_on, status) VALUES (?,?,?,?,?,?,?,?,?)")
                        ->execute([$id, $r['course_id'], $r['batch_id'], $r['session_id'], $r['fee'], $r['discount'], $r['due_date'], $form['admission_date'], 'active']);
                    $firstNewId ??= (int)$pdo->lastInsertId();
                }
            }
            $receipt = null;
            if ($payAmt > 0 && $firstNewId && !$student) {
                $res = ci_add_payment($pdo, $firstNewId, $payAmt, $payMethod, $form['admission_date'], 'Fee at admission', (int)$me['id']);
                if (is_string($res)) throw new RuntimeException($res);
                $receipt = $res[0];
            }
            $pdo->commit();
            if ($oldPhoto && $oldPhoto !== $form['photo']) ci_delete_photo($oldPhoto); // only after the save went through
            admin_log('ci_student', ($student ? 'Updated' : 'Added') . " student {$form['reg_no']} {$form['name']}");
            admin_flash($student ? 'Student saved.' : 'Student added: ' . $form['reg_no'] . '.');
            header('Location: /admin/student-view.php?id=' . $id . ($receipt ? '&receipt=' . $receipt : ''));
            exit;
        } catch (Throwable $e) {
            $pdo->rollBack();
            if (!$student) { $id = 0; $form['reg_no'] = ''; }
            if ($newPhoto) ci_delete_photo($newPhoto);
            $form['photo'] = $student['photo'] ?? null;
            $errors[] = $e instanceof RuntimeException ? $e->getMessage() : 'Could not save, please try again. (' . $e->getMessage() . ')';
        }
    }
    // show what was typed again
    $enrolls = array_map(function ($r) use ($known) { return $r + ['paid' => (float)($known[$r['id']]['paid'] ?? 0)]; }, $rows);
}

// rows to draw: existing enrollments (or what was posted) + one empty row for a new student / batch preset from the batches page
$drawRows = $enrolls;
$presetBatch = (int)($_GET['batch'] ?? 0);
if (!$drawRows) {
    $pb = $presetBatch && isset($L['batches'][$presetBatch]) ? $L['batches'][$presetBatch] : null;
    $pc = $pb ? (int)$pb['course_id'] : 0;
    $drawRows[] = ['id' => 0, 'course_id' => $pc, 'batch_id' => $pb ? $presetBatch : null, 'fee' => $pb ? ($pb['fee'] ?? $L['courses'][$pc]['fee'] ?? 0) : '', 'discount' => 0, 'due_date' => null, 'status' => 'active', 'paid' => 0];
}
$batchJs = [];
foreach ($L['batches'] as $bid => $b) {
    $batchJs[] = ['id' => $bid, 'c' => (int)$b['course_id'], 'n' => $b['name'] . ($b['timing'] ? ' · ' . $b['timing'] : '') . ($b['session_name'] ? ' (' . $b['session_name'] . ')' : '') . ($b['status'] !== 'active' ? ' — ' . $b['status'] : ''),
        'f' => $b['fee'] !== null ? (float)$b['fee'] : null, 'a' => $b['status'] === 'active'];
}
$courseFees = array_map(fn($c) => (float)$c['fee'], $L['courses']);
$title = $student ? 'Edit Student' : 'Add Student';
$v = fn($k) => ci_e($form[$k] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title><?= $title ?> - <?= ci_e(edu_site_name()) ?></title>
<?php admin_shell_head(); ci_head(); ?>
</head>
<body>
<?php admin_shell_open($title, $student ? ci_e($student['reg_no'] . ' · ' . $student['name']) : 'Enrollment form: details, photo, courses and batches, fee'); ?>
<div class="ci">
<?= admin_flash() ?>
<?php if ($errors): ?><div class="ci-alert ci-alert-err"><b>Please fix:</b><ul style="margin:6px 0 0 18px;padding:0"><?php foreach (array_unique($errors) as $e): ?><li><?= ci_e($e) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if (!$L['courses']): ?><div class="ci-alert ci-alert-err">There are no courses yet. <a href="/admin/ci-courses.php?new=1">Add a course</a> first, then come back.</div><?php endif; ?>

<form method="post" enctype="multipart/form-data" id="ci-student-form" novalidate>
<?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$id ?>">

<div class="ci-card"><div class="ci-card-h"><h3>Student details</h3><?php if ($student): ?><span class="ci-muted">Reg. no. <b><?= ci_e($student['reg_no']) ?></b></span><?php endif; ?></div><div class="ci-card-b">
  <div class="ci-photo" style="margin-bottom:16px">
    <?= ci_avatar($form, 84) ?>
    <div class="ci-f"><label>Photo</label><input type="file" name="photo" accept="image/jpeg,image/png,image/webp" style="height:auto;padding:6px"><span class="ci-hint">JPG, PNG or WebP, up to 3 MB.</span>
    <?php if (!empty($form['photo'])): ?><label style="font-weight:400;display:flex;gap:6px;align-items:center"><input type="checkbox" name="photo_remove" value="1" style="width:auto;height:auto"> Remove photo</label><?php endif; ?></div>
  </div>
  <div class="ci-grid3">
    <div class="ci-f"><label>Student name <span class="ci-req">*</span></label><input name="name" required maxlength="150" value="<?= $v('name') ?>" autofocus></div>
    <div class="ci-f"><label>Father's name</label><input name="father_name" maxlength="150" value="<?= $v('father_name') ?>"></div>
    <div class="ci-f"><label>Mother's name</label><input name="mother_name" maxlength="150" value="<?= $v('mother_name') ?>"></div>
    <div class="ci-f"><label>Mobile number <span class="ci-req">*</span></label><input name="mobile" required inputmode="numeric" maxlength="15" placeholder="10 digits" value="<?= $v('mobile') ?>"></div>
    <div class="ci-f"><label>Alternate mobile</label><input name="alt_mobile" inputmode="numeric" maxlength="15" placeholder="parent / guardian" value="<?= $v('alt_mobile') ?>"></div>
    <div class="ci-f"><label>Email</label><input type="email" name="email" maxlength="150" value="<?= $v('email') ?>"></div>
    <div class="ci-f"><label>Date of birth</label><input type="date" name="dob" max="<?= date('Y-m-d') ?>" value="<?= $v('dob') ?>"></div>
    <div class="ci-f"><label>Gender</label><select name="gender"><option value="">—</option><?php foreach (['Male', 'Female', 'Other'] as $g): ?><option <?= ($form['gender'] ?? '') === $g ? 'selected' : '' ?>><?= $g ?></option><?php endforeach; ?></select></div>
    <div class="ci-f"><label>Class</label><select name="class_id"><option value="">—</option><?php foreach ($L['classes'] as $cid => $cn): ?><option value="<?= $cid ?>" <?= (int)($form['class_id'] ?? 0) === $cid ? 'selected' : '' ?>><?= ci_e($cn) ?></option><?php endforeach; ?></select><span class="ci-hint"><a href="/admin/ci-setup.php?tab=classes">Edit class list</a></span></div>
    <div class="ci-f"><label>School / College</label><input name="school" maxlength="150" value="<?= $v('school') ?>"></div>
    <div class="ci-f"><label>Admission date</label><input type="date" name="admission_date" value="<?= $v('admission_date') ?>"></div>
    <div class="ci-f"><label>Status</label><select name="status"><?php foreach (['active' => 'Active', 'completed' => 'Completed', 'left' => 'Left'] as $k => $l): ?><option value="<?= $k ?>" <?= ($form['status'] ?? 'active') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
  </div>
  <div class="ci-sec" style="margin-top:18px">Address</div>
  <div class="ci-grid4">
    <div class="ci-f" style="grid-column:span 2"><label>Address</label><input name="address" maxlength="500" value="<?= $v('address') ?>"></div>
    <div class="ci-f"><label>City / Village</label><input name="city" maxlength="100" value="<?= $v('city') ?>"></div>
    <div class="ci-f"><label>State</label><input name="state" maxlength="100" value="<?= $v('state') ?>"></div>
    <div class="ci-f"><label>PIN code</label><input name="pincode" inputmode="numeric" maxlength="6" value="<?= $v('pincode') ?>"></div>
  </div>
  <div class="ci-f" style="margin-top:12px"><label>Notes</label><textarea name="notes" maxlength="2000" placeholder="anything to remember about this student"><?= $v('notes') ?></textarea></div>
</div></div>

<div class="ci-card"><div class="ci-card-h"><h3>Courses &amp; batches</h3><button type="button" class="ci-btn ci-ghost ci-sm" id="ci-add-row"><i class="fas fa-plus"></i> Add another course</button></div><div class="ci-card-b" id="ci-rows">
<?php foreach (array_values($drawRows) as $idx => $r): $paid = (float)($r['paid'] ?? 0); ?>
  <div class="ci-enr-row" data-paid="<?= $paid ?>">
    <input type="hidden" name="e_id[]" value="<?= (int)$r['id'] ?>">
    <div class="ci-f"><label>Course</label><select name="e_course[]" class="ci-course"><option value="">Choose course…</option><?php foreach ($L['courses'] as $cid => $c): ?><option value="<?= $cid ?>" <?= (int)$r['course_id'] === $cid ? 'selected' : '' ?> <?= $c['status'] !== 'active' && (int)$r['course_id'] !== $cid ? 'disabled' : '' ?>><?= ci_e($c['name']) ?></option><?php endforeach; ?></select></div>
    <div class="ci-f"><label>Batch</label><select name="e_batch[]" class="ci-batch" data-val="<?= (int)($r['batch_id'] ?? 0) ?>"><option value="">No batch</option></select></div>
    <div class="ci-f"><label>Total fee (₹)</label><input name="e_fee[]" class="ci-fee" type="number" min="0" step="0.01" value="<?= ci_e($r['fee'] === '' ? '' : (float)$r['fee']) ?>"></div>
    <div class="ci-f"><label>Discount (₹)</label><input name="e_discount[]" type="number" min="0" step="0.01" value="<?= ci_e((float)($r['discount'] ?? 0) ?: '') ?>"></div>
    <div class="ci-f"><label>Next due date</label><input name="e_due[]" type="date" value="<?= ci_e($r['due_date'] ?? '') ?>"></div>
    <?php if ($r['id']): ?>
    <div class="ci-f"><label>Status<?= $paid > 0 ? ' · paid ' . ci_money0($paid) : '' ?></label><select name="e_status[]"><?php foreach (['active' => 'Active', 'completed' => 'Completed', 'dropped' => 'Dropped'] as $k => $l): ?><option value="<?= $k ?>" <?= $r['status'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
      <?php if ($paid <= 0): ?><label style="font-weight:400;display:flex;gap:6px;align-items:center;margin-top:4px"><input type="checkbox" name="e_remove[<?= $idx ?>]" value="1" style="width:auto;height:auto"> Remove</label><?php endif; ?></div>
    <?php else: ?>
    <input type="hidden" name="e_status[]" value="active"><button type="button" class="ci-btn ci-danger ci-sm ci-enr-del" title="Remove this row"><i class="fas fa-times"></i></button>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
  <p class="ci-muted" style="margin:4px 0 0;font-size:12.5px">The fee fills in from the batch (or the course) and can be changed for this student. Session comes from the batch, otherwise the current session.</p>
</div></div>

<?php if (!$student): ?>
<div class="ci-card"><div class="ci-card-h"><h3>Fee received now</h3><span class="ci-muted">optional — for the first course above</span></div><div class="ci-card-b"><div class="ci-grid4">
  <div class="ci-f"><label>Amount (₹)</label><input type="number" name="pay_amount" min="0" step="0.01" value="<?= ci_e($_POST['pay_amount'] ?? '') ?>" placeholder="0"></div>
  <div class="ci-f"><label>Paid by</label><select name="pay_method"><?php foreach (CI_METHODS as $k => $l): ?><option value="<?= $k ?>" <?= ($_POST['pay_method'] ?? '') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
  <div class="ci-f"><label>&nbsp;</label><span class="ci-muted" id="ci-net" style="line-height:38px"></span></div>
</div></div></div>
<?php endif; ?>

<div style="display:flex;gap:10px;margin:0 0 30px">
  <button class="ci-btn" type="submit"><i class="fas fa-check"></i> <?= $student ? 'Save student' : 'Add student' ?></button>
  <a class="ci-btn ci-ghost" href="<?= $student ? '/admin/student-view.php?id=' . (int)$student['id'] : '/admin/students.php' ?>">Cancel</a>
</div>
</form>

<template id="ci-row-tpl"><div class="ci-enr-row" data-paid="0">
  <input type="hidden" name="e_id[]" value="0">
  <div class="ci-f"><label>Course</label><select name="e_course[]" class="ci-course"><option value="">Choose course…</option><?php foreach ($L['courses'] as $cid => $c): if ($c['status'] !== 'active') continue; ?><option value="<?= $cid ?>"><?= ci_e($c['name']) ?></option><?php endforeach; ?></select></div>
  <div class="ci-f"><label>Batch</label><select name="e_batch[]" class="ci-batch" data-val="0"><option value="">No batch</option></select></div>
  <div class="ci-f"><label>Total fee (₹)</label><input name="e_fee[]" class="ci-fee" type="number" min="0" step="0.01"></div>
  <div class="ci-f"><label>Discount (₹)</label><input name="e_discount[]" type="number" min="0" step="0.01"></div>
  <div class="ci-f"><label>Next due date</label><input name="e_due[]" type="date"></div>
  <input type="hidden" name="e_status[]" value="active"><button type="button" class="ci-btn ci-danger ci-sm ci-enr-del" title="Remove this row"><i class="fas fa-times"></i></button>
</div></template>
</div>
<?php admin_shell_close(); ?>
<script>
(function () {
  var B = <?= json_encode($batchJs, JSON_UNESCAPED_UNICODE) ?>, CF = <?= json_encode((object)$courseFees) ?>;
  var rows = document.getElementById('ci-rows');
  function fillBatches(row, keepFee) {
    var c = +row.querySelector('.ci-course').value, sel = row.querySelector('.ci-batch'), want = +(sel.dataset.val || sel.value || 0);
    sel.innerHTML = '<option value="">No batch</option>';
    B.forEach(function (b) {
      if (b.c !== c || (!b.a && b.id !== want)) return;
      var o = document.createElement('option'); o.value = b.id; o.textContent = b.n; if (b.id === want) o.selected = true; sel.appendChild(o);
    });
    sel.dataset.val = '';
    if (!keepFee) setFee(row);
  }
  function setFee(row) {
    var fee = row.querySelector('.ci-fee');
    if (fee.dataset.typed === '1') return;
    var c = row.querySelector('.ci-course').value, b = +row.querySelector('.ci-batch').value, v = c ? CF[c] : '';
    B.forEach(function (x) { if (x.id === b && x.f !== null) v = x.f; });
    fee.value = v === '' || v === undefined ? '' : v;
    net();
  }
  function net() {
    var el = document.getElementById('ci-net'); if (!el) return;
    var r = rows.querySelector('.ci-enr-row'); if (!r) { el.textContent = ''; return; }
    var f = parseFloat(r.querySelector('.ci-fee').value) || 0, d = parseFloat(r.querySelector('[name="e_discount[]"]').value) || 0;
    el.textContent = f ? 'First course fee after discount: ₹' + (f - d).toLocaleString('en-IN') : '';
  }
  function wire(row, init) {
    row.querySelector('.ci-course').addEventListener('change', function () { row.querySelector('.ci-fee').dataset.typed = ''; fillBatches(row, false); });
    row.querySelector('.ci-batch').addEventListener('change', function () { row.querySelector('.ci-fee').dataset.typed = ''; setFee(row); });
    row.querySelector('.ci-fee').addEventListener('input', function () { this.dataset.typed = '1'; net(); });
    row.querySelector('[name="e_discount[]"]').addEventListener('input', net);
    var del = row.querySelector('.ci-enr-del');
    if (del) del.addEventListener('click', function () { if (rows.querySelectorAll('.ci-enr-row').length > 1) row.remove(); else { row.querySelector('.ci-course').value = ''; fillBatches(row, false); } net(); });
    fillBatches(row, init);
  }
  rows.querySelectorAll('.ci-enr-row').forEach(function (r) { var f = r.querySelector('.ci-fee'); if (f.value !== '') f.dataset.typed = '1'; wire(r, true); });
  document.getElementById('ci-add-row').addEventListener('click', function () {
    var n = document.getElementById('ci-row-tpl').content.firstElementChild.cloneNode(true);
    rows.insertBefore(n, rows.querySelector('p')); wire(n, false);
  });
  net();
})();
</script>
</body>
</html>
