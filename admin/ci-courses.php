<?php
// Courses — what the institute teaches (name, code, duration, total fee). Batches and enrollments belong to a course.
require_once __DIR__ . '/components/guard.php';
require_once INCLUDES_PATH . '/institute.php';
$me = ci_page_start($pdo);
$classes = ci_lists($pdo)['classes'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $a = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    try {
        if ($a === 'save') {
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') throw new RuntimeException('Enter the course name.');
            $cls = (int)($_POST['class_id'] ?? 0);
            $vals = [$name, trim((string)($_POST['code'] ?? '')) ?: null, isset($classes[$cls]) ? $cls : null, trim((string)($_POST['duration'] ?? '')) ?: null, ci_amount($_POST['fee'] ?? 0),
                trim((string)($_POST['description'] ?? '')) ?: null, ($_POST['status'] ?? '') === 'inactive' ? 'inactive' : 'active'];
            if ($id) { $vals[] = $id; $pdo->prepare("UPDATE ci_courses SET name=?, code=?, class_id=?, duration=?, fee=?, description=?, status=? WHERE id=?")->execute($vals); }
            else $pdo->prepare("INSERT INTO ci_courses (name, code, class_id, duration, fee, description, status) VALUES (?,?,?,?,?,?,?)")->execute($vals);
            admin_log('ci_course', "Saved course $name");
            admin_flash('Course saved. The fee is used for new enrollments; existing students keep the fee they joined with.');
        } elseif ($a === 'delete' && $id) {
            $used = (int)$pdo->query("SELECT (SELECT COUNT(*) FROM ci_batches WHERE course_id = $id) + (SELECT COUNT(*) FROM ci_enrollments WHERE course_id = $id)")->fetchColumn();
            if ($used) throw new RuntimeException('This course has batches or students. Set it to Inactive instead of deleting.');
            $pdo->prepare("DELETE FROM ci_courses WHERE id = ?")->execute([$id]);
            admin_flash('Course deleted.');
        }
    } catch (RuntimeException $e) {
        admin_flash($e->getMessage(), 'error');
    }
    header('Location: /admin/ci-courses.php');
    exit;
}

$edit = (int)($_GET['edit'] ?? 0);
$courses = $pdo->query("SELECT c.*, k.name AS class_name, (SELECT COUNT(*) FROM ci_batches b WHERE b.course_id = c.id) AS batches,
    (SELECT COUNT(DISTINCT e.student_id) FROM ci_enrollments e WHERE e.course_id = c.id AND e.status = 'active') AS students,
    (SELECT COALESCE(SUM(" . CI_DUE_SQL . "),0) FROM ci_enrollments e WHERE e.course_id = c.id) AS due
    FROM ci_courses c LEFT JOIN ci_classes k ON k.id = c.class_id ORDER BY c.status = 'active' DESC, k.sort IS NULL, k.sort, c.name")->fetchAll(PDO::FETCH_ASSOC);
$ec = $edit ? (array_values(array_filter($courses, fn($c) => (int)$c['id'] === $edit))[0] ?? null) : null;
$open = $ec || isset($_GET['new']) || !$courses;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title>Courses - <?= ci_e(edu_site_name()) ?></title>
<?php admin_shell_head(); ci_head(); ?>
</head>
<body>
<?php admin_shell_open('Courses', 'Courses the institute teaches, with their total fee'); ?>
<div class="ci">
<?= admin_flash() ?>
<div class="ci-head"><div><h2>Courses</h2><div class="ci-sub"><?= count($courses) ?> courses</div></div>
<div class="ci-actions"><?php if (!$open): ?><a class="ci-btn" href="?new=1"><i class="fas fa-plus"></i> Add Course</a><?php endif; ?></div></div>

<?php if ($open): ?>
<div class="ci-card"><div class="ci-card-h"><h3><?= $ec ? 'Edit course' : 'Add a course' ?></h3><?php if ($courses): ?><a href="/admin/ci-courses.php">Cancel</a><?php endif; ?></div><div class="ci-card-b">
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($ec['id'] ?? 0) ?>">
<div class="ci-grid3">
  <div class="ci-f"><label>Course name <span class="ci-req">*</span></label><input name="name" required maxlength="150" placeholder="e.g. Foundation Maths, NEET, SSC GD" value="<?= ci_e($ec['name'] ?? '') ?>"></div>
  <div class="ci-f"><label>Class</label><select name="class_id"><option value="">Any class / not class-wise</option><?php foreach ($classes as $kid => $kn): ?><option value="<?= $kid ?>" <?= (int)($ec['class_id'] ?? 0) === $kid ? 'selected' : '' ?>><?= ci_e($kn) ?></option><?php endforeach; ?></select><span class="ci-hint">The admission form offers a class's own courses.</span></div>
  <div class="ci-f"><label>Course code</label><input name="code" maxlength="40" placeholder="optional" value="<?= ci_e($ec['code'] ?? '') ?>"></div>
  <div class="ci-f"><label>Duration</label><input name="duration" maxlength="60" placeholder="e.g. 6 months, 1 year" value="<?= ci_e($ec['duration'] ?? '') ?>"></div>
  <div class="ci-f"><label>Total fee (₹)</label><input name="fee" type="number" min="0" step="0.01" value="<?= ci_e(isset($ec['fee']) ? (float)$ec['fee'] : '') ?>"><span class="ci-hint">A batch can have its own fee; if not, this one is used.</span></div>
  <div class="ci-f"><label>Status</label><select name="status"><option value="active">Active</option><option value="inactive" <?= ($ec['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive (not offered)</option></select></div>
</div>
<div class="ci-f" style="margin-top:12px"><label>Description</label><textarea name="description" maxlength="2000"><?= ci_e($ec['description'] ?? '') ?></textarea></div>
<div style="margin-top:14px"><button class="ci-btn" type="submit"><i class="fas fa-check"></i> <?= $ec ? 'Save course' : 'Add course' ?></button></div>
</form></div></div>
<?php endif; ?>

<div class="ci-card"><div class="ci-card-b ci-p0 ci-table-wrap"><table class="ci-table">
<thead><tr><th>Course</th><th>Class</th><th>Duration</th><th class="ci-num">Fee</th><th class="ci-num">Batches</th><th class="ci-num">Students</th><th class="ci-num">Due</th><th>Status</th><th></th></tr></thead><tbody>
<?php if (!$courses): ?><tr><td colspan="9" class="ci-empty">No courses yet. Add your first course above.</td></tr><?php endif; ?>
<?php foreach ($courses as $c): ?>
<tr><td><b><?= ci_e($c['name']) ?></b><?php if ($c['code']): ?> <span class="ci-muted">(<?= ci_e($c['code']) ?>)</span><?php endif; ?></td>
<td><?= ci_e($c['class_name'] ?? '—') ?></td><td class="ci-muted"><?= ci_e($c['duration'] ?: '—') ?></td><td class="ci-num"><?= ci_money0($c['fee']) ?></td>
<td class="ci-num"><a href="/admin/ci-batches.php?course=<?= (int)$c['id'] ?>"><?= (int)$c['batches'] ?></a></td>
<td class="ci-num"><a href="/admin/students.php?course=<?= (int)$c['id'] ?>"><?= (int)$c['students'] ?></a></td>
<td class="ci-num"><?= (float)$c['due'] > 0 ? '<span style="color:#b91c1c">' . ci_money0($c['due']) . '</span>' : '—' ?></td>
<td><?= ci_status_badge($c['status']) ?></td>
<td><div class="ci-row-actions"><a href="?edit=<?= (int)$c['id'] ?>">Edit</a>
<?php if (!$c['batches'] && !$c['students']): ?><form method="post" onsubmit="return confirm('Delete course <?= ci_e(addslashes($c['name'])) ?>?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button type="submit" class="ci-del">Delete</button></form><?php endif; ?>
</div></td></tr>
<?php endforeach; ?>
</tbody></table></div></div>
</div>
<?php admin_shell_close(); ?>
</body>
</html>
