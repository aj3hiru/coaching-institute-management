<?php
// Add / edit a student: personal details, photo, and the courses the student joins (course → its batch).
// Fee: only the course has a fee. Per course the fee type is "Final fee" (one time) or "Installment"; the course fee
// fills in and can be changed for this student only (the difference is shown as a discount on the receipt). When adding,
// the amount the student pays now + payment mode is saved as the first payment (receipt). Later payments (any number,
// any amount) are added from the student's profile → Collect fee.
require_once __DIR__ . '/components/guard.php';
require_once INCLUDES_PATH . '/institute.php';
$me = ci_page_start($pdo, [!empty($_GET['id']) || !empty($_POST['id']) ? 'edit_student' : 'add_student']);
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
$known = array_column($enrolls, null, 'id');
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

$rows = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $in = fn($k, $max = 150) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max);
    $form = [
        'name' => $in('name'), 'father_name' => $in('father_name'), 'mother_name' => $in('mother_name'),
        'dob' => ci_clean_date($_POST['dob'] ?? ''), 'gender' => in_array($_POST['gender'] ?? '', ['Male', 'Female', 'Other'], true) ? $_POST['gender'] : null,
        'mobile' => $in('mobile', 20), 'alt_mobile' => $in('alt_mobile', 20), 'email' => $in('email'),
        'address' => $in('address', 500), 'city' => $student['city'] ?? null, 'state' => $in('state', 100), 'pincode' => $in('pincode', 10),
        // class is on the form (it filters the courses); school / city are no longer asked — kept as saved before
        'class_id' => isset($L['classes'][(int)($_POST['class_id'] ?? 0)]) ? (int)$_POST['class_id'] : null, 'school' => $student['school'] ?? null,
        'admission_date' => ci_clean_date($_POST['admission_date'] ?? '') ?: date('Y-m-d'),
        'status' => in_array($_POST['status'] ?? '', ['active', 'left', 'completed'], true) ? $_POST['status'] : 'active',
        'notes' => $in('notes', 2000), 'photo' => $student['photo'] ?? null, 'reg_no' => $student['reg_no'] ?? '',
    ];
    if ($form['name'] === '') $errors[] = 'Enter the student name.';
    if (!$form['class_id'] && $L['classes']) $errors[] = 'Choose the class.';
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

    // course rows: e_id[] (0 = new), e_course[], e_batch[], e_type[] (full | installment), e_final[] (fee for this student),
    // e_due[] (next due date, installments), e_key[] + pay[key][m][] / pay[key][a][] (paid now, split over modes, new rows), e_status[] (existing), e_remove[idx]
    foreach ((array)($_POST['e_course'] ?? []) as $i => $c) {
        $eid = (int)($_POST['e_id'][$i] ?? 0);
        $c = (int)$c;
        $b = (int)($_POST['e_batch'][$i] ?? 0) ?: null;
        $remove = !empty($_POST['e_remove'][$i]);
        if (!$eid && !$c) continue; // empty new row
        if ($eid && !isset($known[$eid])) { $errors[] = 'A course row is out of date, please reload the page.'; continue; }
        if (!isset($L['courses'][$c])) { $errors[] = 'Choose a course in every course row.'; continue; }
        if ($b && (!isset($L['batches'][$b]) || (int)$L['batches'][$b]['course_id'] !== $c)) { $errors[] = 'A chosen batch does not belong to its course.'; continue; }
        $type = ($_POST['e_type'][$i] ?? '') === 'installment' ? 'installment' : 'full';
        // the course's own fee (kept from enrolment for an existing row of the same course)
        $list = ($eid && (int)$known[$eid]['course_id'] === $c) ? (float)$known[$eid]['fee'] : (float)$L['courses'][$c]['fee'];
        $final = trim((string)($_POST['e_final'][$i] ?? '')) === '' ? $list : ci_amount($_POST['e_final'][$i]);
        $status = $eid ? (in_array($_POST['e_status'][$i] ?? '', ['active', 'completed', 'left', 'dropped'], true) ? $_POST['e_status'][$i] : 'active') : 'active';
        $key = preg_replace('/[^A-Za-z0-9_]/', '', (string)($_POST['e_key'][$i] ?? ''));
        $parts = $eid ? [] : ci_parts_from_post((array)($_POST['pay'][$key]['m'] ?? []), (array)($_POST['pay'][$key]['a'] ?? []));
        $pay = round(array_sum(array_column($parts, 1)), 2);
        $paid = $eid ? (float)$known[$eid]['paid'] : 0;
        $cname = $L['courses'][$c]['name'];
        if (!$remove) {
            if ($final < $paid - 0.004) $errors[] = "For $cname the fee is less than what is already paid (" . ci_money($paid) . ').';
            if ($pay > $final + 0.004) $errors[] = "For $cname the amount paid now is more than its fee (" . ci_money($final) . ').';
        } elseif ($paid > 0) {
            $errors[] = "$cname has payments, so it cannot be removed; set it to Dropped instead.";
        }
        $rows[] = ['idx' => $i, 'id' => $eid, 'course_id' => $c, 'batch_id' => $b, 'session_id' => $b ? ((int)$L['batches'][$b]['session_id'] ?: $curSession) : $curSession,
            'type' => $type, 'list' => $list, 'final' => $final, 'fee' => max($list, $final), 'discount' => max(0, round($list - $final, 2)),
            'due_date' => $type === 'installment' ? ci_clean_date($_POST['e_due'][$i] ?? '') : null, 'status' => $status, 'remove' => $remove,
            'pay' => $pay, 'parts' => $parts, 'key' => $key ?: ('k' . $i), 'paid' => $paid, 'unlocked' => true];
    }
    foreach ($rows as $r) {
        if ($r['remove'] || !$r['batch_id'] || $r['status'] !== 'active') continue;
        $cap = (int)($L['batches'][$r['batch_id']]['capacity'] ?? 0);
        if (!$cap || ($r['id'] && (int)$known[$r['id']]['batch_id'] === $r['batch_id'])) continue;
        $n = $pdo->prepare("SELECT COUNT(*) FROM ci_enrollments WHERE batch_id = ? AND status = 'active' AND id <> ?");
        $n->execute([$r['batch_id'], $r['id']]);
        if ((int)$n->fetchColumn() >= $cap) $errors[] = 'Batch ' . $L['batches'][$r['batch_id']]['name'] . ' is full (' . $cap . ' seats).';
    }
    $ids = array_filter(array_column(array_filter($rows, fn($r) => !$r['remove']), 'batch_id'));
    if (count($ids) !== count(array_unique($ids))) $errors[] = 'The same batch is chosen twice.';

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
            if ($student && (int)($student['class_id'] ?? 0) !== (int)$form['class_id']) {
                $pdo->prepare("INSERT INTO ci_class_history (student_id, from_class, to_class, session_id, changed_on, note, created_by) VALUES (?,?,?,?,?,?,?)")
                    ->execute([$id, $student['class_id'] ?: null, $form['class_id'], $curSession, date('Y-m-d'), 'Changed in Edit Student', (int)$me['id']]);
            }
            if ($form['status'] === 'left' && ($student['status'] ?? '') !== 'left') $pdo->prepare("UPDATE ci_students SET left_on = CURDATE() WHERE id = ?")->execute([$id]);
            $leftNow = $form['status'] === 'left' && ($student['status'] ?? '') !== 'left';
            if ($form['status'] !== 'left') $pdo->prepare("UPDATE ci_students SET left_on = NULL WHERE id = ?")->execute([$id]);
            $pdo->prepare("UPDATE ci_students SET name=?, father_name=?, mother_name=?, dob=?, gender=?, mobile=?, alt_mobile=?, email=?, address=?, city=?, state=?, pincode=?,
                class_id=?, school=?, photo=?, status=?, admission_date=?, notes=? WHERE id=?")->execute([
                $form['name'], $form['father_name'] ?: null, $form['mother_name'] ?: null, $form['dob'], $form['gender'], $form['mobile'], $form['alt_mobile'] ?: null,
                $form['email'] ?: null, $form['address'] ?: null, $form['city'] ?: null, $form['state'] ?: null, $form['pincode'] ?: null, $form['class_id'],
                $form['school'] ?: null, $form['photo'], $form['status'], $form['admission_date'], $form['notes'] ?: null, $id]);
            $receipt = null;
            foreach ($rows as $r) {
                $eid = $r['id'];
                if ($eid && $r['remove']) {
                    $pdo->prepare("DELETE FROM ci_enrollments WHERE id = ? AND student_id = ? AND NOT EXISTS (SELECT 1 FROM ci_payments p WHERE p.enrollment_id = ?)")->execute([$eid, $id, $eid]);
                    ci_plan_clear($pdo, $eid);
                    continue;
                }
                if ($r['remove']) continue;
                if ($eid) {
                    $due = $r['type'] === 'installment' ? ($r['due_date'] ?? $known[$eid]['due_date']) : null;
                    $pdo->prepare("UPDATE ci_enrollments SET course_id=?, batch_id=?, session_id=?, fee=?, discount=?, fee_type=?, due_date=?, status=?,
                        ended_on = CASE WHEN ? = 'active' THEN NULL WHEN status <> ? OR ended_on IS NULL THEN CURDATE() ELSE ended_on END WHERE id=? AND student_id=?")
                        ->execute([$r['course_id'], $r['batch_id'], $r['session_id'], $r['fee'], $r['discount'], $r['type'], $due, $r['status'], $r['status'], $r['status'], $eid, $id]);
                    ci_plan_clear($pdo, $eid); // no automatic kists any more: one course fee, paid in any number of parts
                } else {
                    $pdo->prepare("INSERT INTO ci_enrollments (student_id, course_id, batch_id, session_id, fee, discount, fee_type, due_date, enrolled_on, status) VALUES (?,?,?,?,?,?,?,?,?,?)")
                        ->execute([$id, $r['course_id'], $r['batch_id'], $r['session_id'], $r['fee'], $r['discount'], $r['type'], $r['due_date'], $form['admission_date'], 'active']);
                    $eid = (int)$pdo->lastInsertId();
                    if ($r['pay'] > 0) {
                        $res = ci_add_payment($pdo, $eid, $r['pay'], $r['parts'], $form['admission_date'] > date('Y-m-d') ? date('Y-m-d') : $form['admission_date'],
                            $r['type'] === 'installment' ? 'Installment 1 (at admission)' : 'Fee at admission', (int)$me['id']);
                        if (is_string($res)) throw new RuntimeException($L['courses'][$r['course_id']]['name'] . ': ' . $res);
                        $receipt ??= $res[0];
                    }
                }
            }
            if ($leftNow) $pdo->prepare("UPDATE ci_enrollments SET status = 'left', ended_on = CURDATE() WHERE student_id = ? AND status = 'active'")->execute([$id]);
            $pdo->commit();
            if ($oldPhoto && $oldPhoto !== $form['photo']) ci_delete_photo($oldPhoto); // only after the save went through
            admin_log('ci_student', ($student ? 'Updated' : 'Added') . " student {$form['reg_no']} {$form['name']}");
            if (!$receipt) admin_flash($student ? 'Student saved.' : 'Student added: ' . $form['reg_no'] . '.'); // with a payment the profile shows "Saved — Print receipt"
            $online = !empty($_POST['then_online']) ? '&online=1' : ''; // open the online course / notes popup on the profile
            header('Location: /admin/student-view.php?id=' . $id . $online . ($receipt ? '&receipt=' . $receipt . '#payments' : '#fee'));
            exit;
        } catch (Throwable $e) {
            $pdo->rollBack();
            if (!$student) { $id = 0; $form['reg_no'] = ''; }
            if ($newPhoto) ci_delete_photo($newPhoto);
            $form['photo'] = $student['photo'] ?? null;
            $errors[] = $e instanceof RuntimeException ? $e->getMessage() : 'Could not save, please try again. (' . $e->getMessage() . ')';
        }
    }
}

// rows to draw: what was posted (after an error), else the saved courses, else one empty row (batch preset from Batches page)
if ($rows) {
    $drawRows = $rows;
} elseif ($enrolls) {
    $drawRows = array_map(fn($e) => ['id' => (int)$e['id'], 'course_id' => (int)$e['course_id'], 'batch_id' => $e['batch_id'] ? (int)$e['batch_id'] : null,
        'type' => ($e['fee_type'] ?? 'full') === 'installment' ? 'installment' : 'full', 'list' => (float)$e['fee'], 'final' => (float)$e['fee'] - (float)$e['discount'],
        'due_date' => $e['due_date'], 'status' => $e['status'], 'paid' => (float)$e['paid']], $enrolls);
} else {
    $pb = (int)($_GET['batch'] ?? 0);
    $pb = isset($L['batches'][$pb]) ? $pb : 0;
    $pc = $pb ? (int)$L['batches'][$pb]['course_id'] : 0;
    $drawRows = [['id' => 0, 'course_id' => $pc, 'batch_id' => $pb ?: null, 'type' => 'full', 'list' => $pc ? (float)$L['courses'][$pc]['fee'] : null,
        'final' => null, 'due_date' => null, 'status' => 'active', 'paid' => 0, 'key' => 'n0']];
}

/** One course row ($r = [] is the template for "Add another course"). */
$rowHtml = function (array $r, int $idx) use ($L): string {
    $id = (int)($r['id'] ?? 0);
    $paid = (float)($r['paid'] ?? 0);
    $type = ($r['type'] ?? 'full') === 'installment' ? 'installment' : 'full';
    $key = $r['key'] ?? ($id ? 'e' . $id : '__K__');
    $num = fn($v) => $v === null || $v === '' ? '' : rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.');
    $cname = isset($L['courses'][(int)($r['course_id'] ?? 0)]) ? $L['courses'][(int)$r['course_id']]['name'] : '';
    $bname = isset($L['batches'][(int)($r['batch_id'] ?? 0)]) ? $L['batches'][(int)$r['batch_id']]['name'] : '';
    $locked = $id && empty($r['unlocked']); // a course the student already has: shown as a summary until "Edit" is pressed
    $h = '<div class="ci-enr-row ci-fee-row ci-ec' . ($locked ? ' is-locked' : '') . (!empty($r['remove']) ? ' is-removing' : '') . '"' . ($id ? ' id="enr-' . $id . '"' : '') . ' data-paid="' . $paid . '" data-new="' . ($id ? 0 : 1) . '">';
    $h .= '<input type="hidden" name="e_id[]" value="' . $id . '"><input type="hidden" name="e_key[]" value="' . ci_e($key) . '">';
    // head: what this course is, and its buttons
    $h .= '<div class="ci-ec-h"><div class="ci-ec-t"><span class="ci-ec-ico">' . ci_svg('book-plus') . '</span><div><b class="ci-ec-name">' . ci_e($cname ?: 'New course') . '</b><small class="ci-ec-sub">'
        . ci_e(implode(' · ', array_filter([$bname ? 'Batch ' . $bname : ($id ? 'No batch' : ''), $id ? ($type === 'installment' ? 'Installment' : 'Final fee') : '', $id && ($r['status'] ?? 'active') !== 'active' ? ucfirst($r['status']) : '']))) . '</small></div></div><div class="ci-icos">';
    if ($id) $h .= '<button type="button" class="ci-ico ci-ec-edit" aria-label="Change this course">' . ci_svg('edit') . '</button>';
    if (!$id) $h .= '<button type="button" class="ci-ico ci-ico-danger ci-enr-del" aria-label="Remove this course">' . ci_svg('trash') . '</button>';
    elseif ($paid <= 0) $h .= '<label class="ci-ico ci-ico-danger ci-enr-rm" aria-label="Remove this course (no fee paid yet)"><input type="checkbox" name="e_remove[' . $idx . ']" value="1"' . (!empty($r['remove']) ? ' checked' : '') . '>' . ci_svg('trash') . '</label>';
    $h .= '</div></div>';
    // body: the fields
    $h .= '<div class="ci-ec-b">';
    $h .= '<div class="ci-f"><label>Course</label><select name="e_course[]" class="ci-course" data-search><option value="">Choose course…</option>';
    foreach ($L['courses'] as $cid => $c) {
        $sel = (int)($r['course_id'] ?? 0) === $cid;
        if ($c['status'] !== 'active' && !$sel) continue;
        $h .= '<option value="' . $cid . '" data-fee="' . (float)$c['fee'] . '" data-class="' . (int)$c['class_id'] . '"' . ($sel ? ' selected' : '') . '>' . ci_e($c['name']) . ' — ' . ci_money0($c['fee']) . '</option>';
    }
    $h .= '</select></div>';
    $h .= '<div class="ci-f"><label>Batch</label><select name="e_batch[]" class="ci-batch" data-search data-val="' . (int)($r['batch_id'] ?? 0) . '"><option value="">Choose the course first</option></select></div>';
    $h .= '<div class="ci-f"><label>Fee plan</label><select name="e_type[]" class="ci-type"><option value="full"' . ($type === 'full' ? ' selected' : '') . '>One-time fee</option><option value="installment"' . ($type === 'installment' ? ' selected' : '') . '>Installment</option></select></div>';
    $h .= '<div class="ci-f"><label>Fee for this student (₹)</label><input name="e_final[]" class="ci-final" type="number" min="0" step="0.01" value="' . ci_e($num($r['final'] ?? null)) . '" data-list="' . ci_e($num($r['list'] ?? null)) . '"></div>';
    if ($id) {
        $h .= '<div class="ci-f"><label>Status</label><select name="e_status[]">';
        foreach (['active' => 'Active', 'completed' => 'Completed', 'left' => 'Left', 'dropped' => 'Dropped'] as $k => $l) $h .= '<option value="' . $k . '"' . (($r['status'] ?? '') === $k ? ' selected' : '') . '>' . $l . '</option>';
        $h .= '</select></div>';
    } else {
        $h .= '<input type="hidden" name="e_status[]" value="active">';
    }
    $h .= '<div class="ci-f ci-due-f"><label>Next due date</label><input name="e_due[]" type="date" value="' . ci_e($r['due_date'] ?? '') . '"></div>';
    if (!$id) $h .= '<div class="ci-f ci-nofloat ci-paybox">' . ci_pays_html('pay[' . $key . '][m][]', 'pay[' . $key . '][a][]', $r['parts'] ?? [], 'Paying now') . '</div>';
    $h .= '</div>';
    $h .= '<div class="ci-row-sum ci-ec-f"></div>';
    if ($id) $h .= '<div class="ci-ec-note">Paid so far <b>' . ci_money($paid) . '</b> · more fee is collected in the <a href="/admin/student-view.php?id=' . (int)($r['student_id'] ?? 0) . '#enr-' . $id . '">profile</a></div>';
    return $h . '</div>';
};
$batchJs = [];
foreach ($L['batches'] as $bid => $b) {
    $batchJs[] = ['id' => $bid, 'c' => (int)$b['course_id'], 'n' => $b['name'] . ($b['timing'] ? ' · ' . $b['timing'] : '') . ($b['session_name'] ? ' (' . $b['session_name'] . ')' : '') . ($b['status'] !== 'active' ? ' — ' . $b['status'] : ''), 'a' => $b['status'] === 'active'];
}
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
<?php admin_shell_open($title, $student ? ci_e($student['reg_no'] . ' · ' . $student['name']) : 'Admission form: student details, course, batch and fee'); ?>
<div class="ci">
<?= admin_flash() ?>
<?php if ($errors): ?><div class="ci-alert ci-alert-err"><b>Please fix:</b><ul style="margin:6px 0 0 18px;padding:0"><?php foreach (array_unique($errors) as $e): ?><li><?= ci_e($e) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if (!$L['courses']): ?><div class="ci-alert ci-alert-err">There are no courses yet. <a href="/admin/ci-courses.php?new=1">Add a course</a> first, then come back.</div><?php endif; ?>

<form method="post" enctype="multipart/form-data" id="ci-student-form" class="ci-float" novalidate>
<?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$id ?>">

<div class="ci-card"><div class="ci-card-h"><h3>Student details</h3><?php if ($student): ?><span class="ci-muted">Reg. no. <b><?= ci_e($student['reg_no']) ?></b></span><?php endif; ?></div><div class="ci-card-b">
  <div class="ci-photo" style="margin-bottom:16px">
    <span class="ci-photo-prev" id="ci-photo-prev"><?= ci_avatar($form, 84) ?></span>
    <div class="ci-f ci-nofloat"><label>Photo</label><input type="file" name="photo" id="ci-photo-in" accept="image/jpeg,image/png,image/webp" style="height:auto;padding:6px"><span class="ci-hint">JPG, PNG or WebP, up to 3 MB.</span>
    <?php if (!empty($form['photo'])): ?><label class="ci-chk"><input type="checkbox" name="photo_remove" value="1"> Remove photo</label><?php endif; ?></div>
  </div>
  <div class="ci-grid3">
    <div class="ci-f"><label>Student name <span class="ci-req">*</span></label><input name="name" required maxlength="150" value="<?= $v('name') ?>" autofocus></div>
    <div class="ci-f"><label>Father's name</label><input name="father_name" maxlength="150" value="<?= $v('father_name') ?>"></div>
    <div class="ci-f"><label>Mother's name</label><input name="mother_name" maxlength="150" value="<?= $v('mother_name') ?>"></div>
    <div class="ci-f"><label>Mobile number <span class="ci-req">*</span></label><input name="mobile" required inputmode="numeric" maxlength="15" placeholder="10 digits" value="<?= $v('mobile') ?>"></div>
    <div class="ci-f"><label>Alternate mobile</label><input name="alt_mobile" inputmode="numeric" maxlength="15" placeholder="parent / guardian" value="<?= $v('alt_mobile') ?>"></div>
    <div class="ci-f"><label>Email</label><input type="email" name="email" maxlength="150" value="<?= $v('email') ?>"></div>
    <div class="ci-f"><label>Date of birth</label><input type="date" name="dob" max="<?= date('Y-m-d') ?>" value="<?= $v('dob') ?>"></div>
    <div class="ci-f"><label>Gender</label><select name="gender"><option value=""></option><?php foreach (['Male', 'Female', 'Other'] as $g): ?><option <?= ($form['gender'] ?? '') === $g ? 'selected' : '' ?>><?= $g ?></option><?php endforeach; ?></select></div>
    <div class="ci-f"><label>Class <span class="ci-req">*</span></label><select name="class_id" id="ci-class"><option value=""></option><?php foreach ($L['classes'] as $cid => $cn): ?><option value="<?= $cid ?>" <?= (int)($form['class_id'] ?? 0) === $cid ? 'selected' : '' ?>><?= ci_e($cn) ?></option><?php endforeach; ?></select></div>
    <div class="ci-f"><label>Admission date</label><input type="date" name="admission_date" value="<?= $v('admission_date') ?>"></div>
    <div class="ci-f"><label>Status</label><select name="status"><?php foreach (['active' => 'Active', 'completed' => 'Completed', 'left' => 'Left'] as $k => $l): ?><option value="<?= $k ?>" <?= ($form['status'] ?? 'active') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
  </div>
  <div class="ci-sec" style="margin-top:18px">Address</div>
  <div class="ci-grid4">
    <div class="ci-f" style="grid-column:span 2"><label>Address (village / city)</label><input name="address" maxlength="500" value="<?= $v('address') ?>"></div>
    <div class="ci-f"><label>State</label><input name="state" maxlength="100" value="<?= $v('state') ?>"></div>
    <div class="ci-f"><label>PIN code</label><input name="pincode" inputmode="numeric" maxlength="6" value="<?= $v('pincode') ?>"></div>
  </div>
  <div class="ci-f" style="margin-top:12px"><label>Notes</label><textarea name="notes" maxlength="2000" placeholder="anything to remember about this student"><?= $v('notes') ?></textarea></div>
</div></div>

<div class="ci-card"><div class="ci-card-h"><h3>Course, batch &amp; fee</h3><button type="button" class="ci-btn ci-ghost ci-sm" id="ci-add-row"><i class="fas fa-plus"></i> Add another course</button></div><div class="ci-card-b" id="ci-rows">
<?php foreach (array_values($drawRows) as $n => $r) echo $rowHtml($r + ['student_id' => $id], $n); ?>
</div></div>

<?php if (is_file(INCLUDES_PATH . '/shop.php') && (require_once INCLUDES_PATH . '/shop.php') && sh_perm($me, 'orders')): ?>
<label class="ci-then-online"><input type="checkbox" name="then_online" value="1"> <span><b>Also give an online course / notes</b><small>After saving, a form opens to sell or give a recorded course or PDF notes (cash, UPI, free …).</small></span></label>
<?php endif; ?>
<div style="display:flex;gap:10px;margin:0 0 30px">
  <button class="ci-btn" type="submit"><i class="fas fa-check"></i> <?= $student ? 'Save changes' : 'Save' ?></button>
  <a class="ci-btn ci-ghost" href="<?= $student ? '/admin/student-view.php?id=' . (int)$student['id'] : '/admin/students.php' ?>">Cancel</a>
</div>
</form>

<template id="ci-row-tpl"><?= $rowHtml([], 0) ?></template>
</div>
<?php admin_shell_close(); ?>
<script>
(function () {
  var B = <?= json_encode($batchJs, JSON_UNESCAPED_UNICODE) ?>;
  var rows = document.getElementById('ci-rows');
  var inr = function (n) { return '₹' + (Math.round(n * 100) / 100).toLocaleString('en-IN'); };
  function courseFee(row) { var s = row.querySelector('.ci-course'), o = s.options[s.selectedIndex]; return o && o.value ? +o.dataset.fee : null; }
  function fillBatches(row) {
    var c = +row.querySelector('.ci-course').value, sel = row.querySelector('.ci-batch'), want = +(sel.dataset.val || sel.value || 0), n = 0;
    sel.innerHTML = '';
    var first = document.createElement('option'); first.value = '';
    sel.appendChild(first);
    B.forEach(function (b) {
      if (b.c !== c || (!b.a && b.id !== want)) return;
      var o = document.createElement('option'); o.value = b.id; o.textContent = b.n; if (b.id === want) o.selected = true; sel.appendChild(o); n++;
    });
    first.textContent = !c ? 'Choose the course first' : (n ? 'Choose batch…' : 'No batch for this course');
    sel.dataset.val = '';
  }
  // fee for this student: filled from the course; typing changes it for this student only.
  // Paying now = one or more payment rows (mode + amount); the first row gets the whole fee for "Final fee".
  function pays(row) { return [].slice.call(row.querySelectorAll('.ci-pay-a')); }
  function paidNow(row) { return pays(row).reduce(function (t, i) { return t + (parseFloat(i.value) || 0); }, 0); }
  function setFirstPay(row, v) { var p = pays(row); if (!p.length) return; p.forEach(function (i, k) { if (k) i.closest('.ci-pay-row').remove(); }); p[0].value = v; row.querySelector('[data-pays]') && row.querySelector('[data-pays]').dispatchEvent(new Event('input', { bubbles: true })); }
  function sum(row) {
    var fin = row.querySelector('.ci-final'), list = parseFloat(fin.dataset.list), f = parseFloat(fin.value);
    var type = row.querySelector('.ci-type').value, box = row.querySelector('.ci-row-sum'), isNew = row.dataset.new === '1';
    row.querySelector('.ci-due-f').style.display = type === 'installment' ? '' : 'none';
    var pl = row.querySelector('.ci-pays-l'); if (pl) pl.textContent = type === 'installment' ? 'Paying now (1st installment)' : 'Paying now';
    if (isNaN(f) || !row.querySelector('.ci-course').value) { box.innerHTML = ''; return; }
    var paid = +row.dataset.paid + (isNew ? paidNow(row) : 0), due = Math.max(0, f - paid);
    box.innerHTML = (!isNaN(list) ? '<span>Course fee <b>' + inr(list) + '</b></span>' : '') + (!isNaN(list) && f < list ? '<span>Discount <b>' + inr(list - f) + '</b></span>' : '')
      + '<span>Fee <b>' + inr(f) + '</b></span><span>Paid <b class="ci-g">' + inr(paid) + '</b></span><span>Due <b class="' + (due > 0 ? 'ci-r' : 'ci-g') + '">' + inr(due) + '</b></span>'
      + (isNew && paid > f + 0.004 ? '<span class="ci-r"><b>Paying more than the fee</b></span>' : '');
  }
  function setFee(row) {
    var fin = row.querySelector('.ci-final'), fee = courseFee(row);
    fin.dataset.list = fee === null ? '' : fee;
    fin.value = fee === null ? '' : fee;
    setFirstPay(row, row.querySelector('.ci-type').value === 'full' && fee !== null ? fee : '');
    sum(row);
  }
  function wire(row, init) {
    var course = row.querySelector('.ci-course'), type = row.querySelector('.ci-type'), fin = row.querySelector('.ci-final');
    course.addEventListener('change', function () { fillBatches(row); setFee(row); });
    type.addEventListener('change', function () {
      // Final fee → the whole (edited) fee is paid now; Installment → type what the student gives now
      setFirstPay(row, type.value === 'full' ? fin.value : '');
      sum(row);
      var p = pays(row)[0]; if (p && type.value === 'installment') p.focus();
    });
    fin.addEventListener('input', function () { if (type.value === 'full') setFirstPay(row, fin.value); sum(row); });
    row.addEventListener('input', function (e) { if (e.target.closest('[data-pays]')) sum(row); });
    row.addEventListener('ci-pays', function () { sum(row); });
    var del = row.querySelector('.ci-enr-del');
    if (del) del.addEventListener('click', function () { if (rows.querySelectorAll('.ci-enr-row').length > 1) row.remove(); else { course.value = ''; fillBatches(row); setFee(row); } });
    var ed = row.querySelector('.ci-ec-edit');
    if (ed) ed.addEventListener('click', function () { var l = row.classList.toggle('is-locked'); ed.classList.toggle('on', !l); if (!l) row.querySelector('.ci-final').focus(); });
    var title = function () {
      var o = course.options[course.selectedIndex], b = row.querySelector('.ci-batch'), bo = b.options[b.selectedIndex];
      row.querySelector('.ci-ec-name').textContent = o && o.value ? o.textContent.replace(/ — ₹[\d,]+$/, '') : 'New course';
      row.querySelector('.ci-ec-sub').textContent = [bo && bo.value ? 'Batch ' + bo.textContent.split(' · ')[0] : '', type.value === 'installment' ? 'Installment' : 'Final fee'].filter(Boolean).join(' · ');
    };
    [course, row.querySelector('.ci-batch'), type].forEach(function (el) { el.addEventListener('change', title); });
    var rm = row.querySelector('.ci-enr-rm input');
    if (rm) rm.addEventListener('change', function () { row.classList.toggle('is-removing', rm.checked); });
    fillBatches(row);
    if (!init) setFee(row);
    else { if (fin.value === '' && courseFee(row) !== null) setFee(row); else sum(row); }
  }
  // class → only that class's courses (courses without a class always show)
  var cls = document.getElementById('ci-class');
  // the student's class's courses come first (in their own group); every other course stays below
  function classFilter(row) {
    var k = cls ? cls.value : '', sel = row.querySelector('.ci-course'), keep = sel.value;
    var opts = [].slice.call(sel.querySelectorAll('option')).filter(function (o) { return o.value; });
    sel.querySelectorAll('optgroup').forEach(function (g) { g.remove(); });
    var mine = opts.filter(function (o) { return k && o.dataset.class === k; }), rest = opts.filter(function (o) { return !(k && o.dataset.class === k); });
    if (mine.length) {
      var cn = cls.options[cls.selectedIndex].text, g1 = document.createElement('optgroup'), g2 = document.createElement('optgroup');
      g1.label = 'Class ' + cn + ' courses'; g2.label = 'Other courses';
      mine.forEach(function (o) { g1.appendChild(o); }); rest.forEach(function (o) { g2.appendChild(o); });
      sel.appendChild(g1); if (rest.length) sel.appendChild(g2);
    } else rest.forEach(function (o) { sel.appendChild(o); });
    sel.value = keep;
  }
  if (cls) cls.addEventListener('change', function () {
    rows.querySelectorAll('.ci-enr-row').forEach(function (r) {
      var c = r.querySelector('.ci-course'), o = c.options[c.selectedIndex];
      classFilter(r);
    });
  });
  var _wire = wire; wire = function (row, init) { classFilter(row); _wire(row, init); row.querySelector('.ci-course').addEventListener('change', function () { var o = this.options[this.selectedIndex]; if (cls && !cls.value && o && o.dataset.class && o.dataset.class !== '0') { cls.value = o.dataset.class; cls.dispatchEvent(new Event('change', { bubbles: true })); } }); };
  rows.querySelectorAll('.ci-enr-row').forEach(function (r) { wire(r, true); });
  // photo: show the chosen picture at once
  var pin = document.getElementById('ci-photo-in'), prev = document.getElementById('ci-photo-prev');
  if (pin && prev) pin.addEventListener('change', function () {
    var f = pin.files && pin.files[0];
    if (!f) return;
    if (!/^image\/(jpeg|png|webp)$/.test(f.type)) { alert('Please choose a JPG, PNG or WebP image.'); pin.value = ''; return; }
    if (f.size > 3 * 1024 * 1024) { alert('The photo is larger than 3 MB.'); pin.value = ''; return; }
    var url = URL.createObjectURL(f);
    prev.innerHTML = '<img class="ci-av" src="' + url + '" alt="" style="width:84px;height:84px">';
  });
  document.getElementById('ci-add-row').addEventListener('click', function () {
    var n = document.getElementById('ci-row-tpl').content.firstElementChild.cloneNode(true);
    n.innerHTML = n.innerHTML.replace(/__K__/g, 'n' + Date.now().toString(36) + Math.random().toString(36).slice(2, 6)); n.querySelector('[name="e_key[]"]').value = n.querySelector('[name^="pay["]').name.replace(/^pay\[([^\]]+)\].*$/, '$1');
    rows.appendChild(n); wire(n, false);
  });
  // ?addcourse=1 (from the students list): open a new course row straight away
  if (/[?&]addcourse=1/.test(location.search)) { document.getElementById('ci-add-row').click(); var nr = rows.querySelectorAll('.ci-enr-row'); nr = nr[nr.length - 1]; if (nr) { nr.scrollIntoView({ block: 'center' }); setTimeout(function () { nr.querySelector('.ci-course').focus(); }, 300); } }
})();
</script>
</body>
</html>
