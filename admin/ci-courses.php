<?php
// Courses — what the institute teaches (name, class, teacher, duration, total fee). Batches and enrollments belong to a course.
require_once __DIR__ . '/components/guard.php';
require_once INCLUDES_PATH . '/institute.php';
$me = ci_page_start($pdo, ["courses"]);
$classes = ci_lists($pdo)['classes'];
$teachers = ci_teachers($pdo, $pdo->query("SELECT teacher FROM ci_courses UNION SELECT teacher FROM ci_batches")->fetchAll(PDO::FETCH_COLUMN)); // staff with the Teacher role

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $a = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    try {
        if ($a === 'save') {
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') throw new RuntimeException('Enter the course name.');
            $cls = (int)($_POST['class_id'] ?? 0);
            $status = in_array($_POST['status'] ?? '', ['active', 'completed', 'inactive'], true) ? $_POST['status'] : 'active';
            $teacher = trim((string)($_POST['teacher'] ?? '')); $teacher = isset($teachers[$teacher]) ? $teacher : null;
            $vals = [$name, isset($classes[$cls]) ? $cls : null, trim((string)($_POST['duration'] ?? '')) ?: null, ci_amount($_POST['fee'] ?? 0),
                trim((string)($_POST['description'] ?? '')) ?: null, $status === 'completed' ? 'active' : $status];
            if ($id) { $vals[] = $id; $pdo->prepare("UPDATE ci_courses SET name=?, class_id=?, duration=?, fee=?, description=?, status=? WHERE id=?")->execute($vals); }
            else { $pdo->prepare("INSERT INTO ci_courses (name, class_id, duration, fee, description, status) VALUES (?,?,?,?,?,?)")->execute($vals); $id = (int)$pdo->lastInsertId(); }
            $pdo->prepare("UPDATE ci_courses SET teacher = ? WHERE id = ?")->execute([$teacher, $id]);
            if ($status === 'completed') ci_complete_course($pdo, $id); // all its batches complete too
            admin_log('ci_course', "Saved course $name");
            admin_flash('Course saved. The fee is used for new enrollments; existing students keep the fee they joined with.');
        } elseif ($a === 'complete' && $id) {
            ci_complete_course($pdo, $id);
            admin_log('ci_course', "Completed course #$id with all its batches");
            admin_flash('Course completed. All its batches are marked completed too.');
        } elseif ($a === 'delete' && $id) { // admins only: the course with all its batches, student entries (and payments), attendance and holidays
            ci_admin_only($me);
            $nm = (string)$pdo->query("SELECT name FROM ci_courses WHERE id = $id")->fetchColumn();
            $imp = ci_del_batch_impact($pdo, $pdo->query("SELECT id FROM ci_batches WHERE course_id = $id")->fetchAll(PDO::FETCH_COLUMN), $id);
            ci_del_tx($pdo, fn() => ci_del_course($pdo, $id));
            admin_log('ci_course_delete', "Deleted course #$id $nm" . ($imp['text'] ? ' with ' . $imp['text'] : ''));
            admin_flash('Course ' . $nm . ' deleted' . ($imp['text'] ? ' with ' . $imp['text'] : '') . '. The students stay.');
        }
    } catch (RuntimeException $e) {
        admin_flash($e->getMessage(), 'error');
    }
    header('Location: /admin/ci-courses.php');
    exit;
}

$edit = (int)($_GET['edit'] ?? 0);
$fStatus = in_array($_GET['status'] ?? '', ['active', 'completed', 'inactive', 'all'], true) ? $_GET['status'] : 'active';
$courses = $pdo->query("SELECT c.*, k.name AS class_name, (SELECT COUNT(*) FROM ci_batches b WHERE b.course_id = c.id) AS batches,
    (SELECT COUNT(DISTINCT e.student_id) FROM ci_enrollments e WHERE e.course_id = c.id AND e.status = 'active') AS students,
    (SELECT COALESCE(SUM(" . CI_DUE_SQL . "),0) FROM ci_enrollments e WHERE e.course_id = c.id) AS due
    FROM ci_courses c LEFT JOIN ci_classes k ON k.id = c.class_id" . ($fStatus !== 'all' ? " WHERE c.status = " . $pdo->quote($fStatus) . ($edit ? " OR c.id = $edit" : '') : '') . " ORDER BY c.status = 'active' DESC, k.sort IS NULL, k.sort, c.name")->fetchAll(PDO::FETCH_ASSOC);
$ec = $edit ? (array_values(array_filter($courses, fn($c) => (int)$c['id'] === $edit))[0] ?? null) : null;
$open = $ec || isset($_GET["new"]) || (!$courses && $fStatus === "active"); // ?new=1 / ?edit=N (and no courses yet) open the popup on load
$cf = fn(array $c): string => ci_e(json_encode(['id' => (int)$c['id'], 'name' => $c['name'], 'class_id' => (int)$c['class_id'] ?: '', 'duration' => (string)$c['duration'], 'fee' => (float)$c['fee'],
    'status' => $c['status'], 'teacher' => (string)($c['teacher'] ?? ''), 'description' => (string)$c['description']], JSON_UNESCAPED_UNICODE));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title>Courses - <?= ci_e(edu_site_name()) ?></title>
<?php admin_shell_head(); ci_head(); ci_report_assets(); ?>
</head>
<body>
<?php admin_shell_open('Courses', 'Courses the institute teaches, with their total fee', '<button type="button" class="ci-btn" data-mopen="#cmForm" data-mtitle="Add a course" data-fill=\'{"id":0}\'><i class="fas fa-plus"></i> Add Course</button>'); ?>
<div class="ci">
<?= admin_flash() ?>

<div class="ci-modal" id="cmForm" data-mform<?= $open ? ' data-open-now' : '' ?> hidden><div class="ci-modal-box ci-modal-wide" role="dialog" aria-modal="true" aria-labelledby="cmFormH">
<div class="ci-card-h"><h3 id="cmFormH" data-mtitle-target><?= $ec ? 'Edit course' : 'Add a course' ?></h3><button type="button" class="ci-x" data-close aria-label="Close"><i class="fas fa-times"></i></button></div><div class="ci-card-b">
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($ec['id'] ?? 0) ?>">
<div class="ci-grid2 ci-mgrid">
  <div class="ci-f" style="grid-column:1/-1"><label>Course name <span class="ci-req">*</span></label><input name="name" required maxlength="150" placeholder="e.g. Foundation Maths, NEET, SSC GD" value="<?= ci_e($ec['name'] ?? '') ?>"></div>
  <div class="ci-f"><label>Class</label><select name="class_id"><option value="">Any class / not class-wise</option><?php foreach ($classes as $kid => $kn): ?><option value="<?= $kid ?>" <?= (int)($ec['class_id'] ?? 0) === $kid ? 'selected' : '' ?>><?= ci_e($kn) ?></option><?php endforeach; ?></select><span class="ci-hint">The admission form offers a class's own courses.</span></div>
  <div class="ci-f"><label>Teacher</label><select name="teacher"><option value="">— No teacher —</option><?php foreach ($teachers as $tn): ?><option value="<?= ci_e($tn) ?>" <?= ($ec['teacher'] ?? '') === $tn ? 'selected' : '' ?>><?= ci_e($tn) ?></option><?php endforeach; ?></select><span class="ci-hint"><?= $teachers ? 'New batches of this course get this teacher; a batch can change it.' : 'No teachers yet — add them in Users with the Teacher role.' ?></span></div>
  <div class="ci-f"><label>Duration</label><input name="duration" maxlength="60" placeholder="e.g. 6 months, 1 year" value="<?= ci_e($ec['duration'] ?? '') ?>"></div>
  <div class="ci-f"><label>Total fee (₹)</label><input name="fee" type="number" min="0" step="0.01" value="<?= ci_e(isset($ec['fee']) ? (float)$ec['fee'] : '') ?>"><span class="ci-hint">Every batch of this course uses this fee; it can be changed per student at admission.</span></div>
  <div class="ci-f"><label>Status</label><select name="status"><option value="active">Active</option><option value="completed" <?= ($ec['status'] ?? '') === 'completed' ? 'selected' : '' ?>>Completed</option><option value="inactive" <?= ($ec['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option></select></div>
</div>
<div class="ci-f" style="margin-top:12px"><label>Description</label><textarea name="description" maxlength="2000"><?= ci_e($ec['description'] ?? '') ?></textarea></div>
<div class="ci-mfoot"><button type="button" class="ci-btn ci-ghost" data-close>Cancel</button><button class="ci-btn" type="submit"><i class="fas fa-check"></i> Save course</button></div>
</form></div></div></div>

<div class="ci-head ci-head-show"><div class="ci-show"><label for="ci-cst">Status</label><select id="ci-cst" onchange="location.href='?status='+this.value"><?php foreach (['active' => 'Active', 'completed' => 'Completed', 'inactive' => 'Inactive', 'all' => 'All'] as $k => $l): ?><option value="<?= $k ?>" <?= $fStatus === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select><span class="ci-muted"><?= count($courses) ?> courses</span></div></div>
<div class="ci-card"><div class="ci-card-b ci-p0 ci-table-wrap"><table class="ci-table">
<thead><tr><th>Course</th><th>Class</th><th>Duration</th><th class="ci-num">Fee</th><th class="ci-num">Batches</th><th class="ci-num">Students</th><th class="ci-num">Due</th><th>Status</th><th>Action</th></tr></thead><tbody>
<?php if (!$courses): ?><tr><td colspan="9" class="ci-empty">No courses here. Press Add Course to add one.</td></tr><?php endif; ?>
<?php foreach ($courses as $c): ?>
<tr><td><b><?= ci_e($c['name']) ?></b><?php if (!empty($c['teacher'])): ?><small class="ci-muted" style="display:block"><?= ci_e($c['teacher']) ?></small><?php endif; ?></td>
<td><?= ci_e($c['class_name'] ?? '—') ?></td><td class="ci-muted"><?= ci_e($c['duration'] ?: '—') ?></td><td class="ci-num"><?= ci_money0($c['fee']) ?></td>
<td class="ci-num"><a href="/admin/ci-batches.php?course=<?= (int)$c['id'] ?>"><?= (int)$c['batches'] ?></a></td>
<td class="ci-num"><a href="/admin/students.php?course=<?= (int)$c['id'] ?>"><?= (int)$c['students'] ?></a></td>
<td class="ci-num"><?= (float)$c['due'] > 0 ? '<span style="color:#b91c1c">' . ci_money0($c['due']) . '</span>' : '—' ?></td>
<td><?= ci_status_badge($c['status']) ?></td>
<td><div class="ci-icos"><?= ci_ico('edit', null, 'Edit course', 'button', ' data-mopen="#cmForm" data-mtitle="Edit course" data-fill="' . $cf($c) . '"') ?><?= ci_ico('pdf', null, 'PDF report', 'button', ' data-report="course" data-id="' . (int)$c['id'] . '"') ?>
<?php if ($c['status'] === 'active'): ?><?= ci_post_ico('check', 'Mark course completed (all batches)', ['action' => 'complete', 'id' => $c['id']], 'Complete ' . $c['name'] . '? All its batches will be marked completed.') ?><?php endif; ?>
<?php if (ci_is_admin($me)): $imp = ci_del_batch_impact($pdo, $pdo->query("SELECT id FROM ci_batches WHERE course_id = " . (int)$c['id'])->fetchAll(PDO::FETCH_COLUMN), (int)$c['id']); ?><?= ci_post_ico('trash', 'Delete course', ['action' => 'delete', 'id' => $c['id']], 'Delete course ' . $c['name'] . '?' . ($imp['text'] ? "\n\nThis also deletes " . $imp['text'] . '.' : '') . "\nThe students stay. This cannot be undone.") ?><?php endif; ?>
</div></td></tr>
<?php endforeach; ?>
</tbody></table></div></div>
</div>
<?php admin_shell_close(); ?>
</body>
</html>
