<?php
// Batches — a group of students of one course in one session, with timing, days, teacher and seats. The fee belongs to the course (Courses page), not to the batch.
require_once __DIR__ . '/components/guard.php';
require_once INCLUDES_PATH . '/institute.php';
$me = ci_page_start($pdo, ["batches"]);
$L = ci_lists($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $a = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    try {
        if ($a === 'save') {
            $name = trim((string)($_POST['name'] ?? ''));
            $course = (int)($_POST['course_id'] ?? 0);
            $session = (int)($_POST['session_id'] ?? 0) ?: null;
            if ($name === '') throw new RuntimeException('Enter the batch name.');
            if (!isset($L['courses'][$course])) throw new RuntimeException('Choose the course of this batch.');
            if ($session && !isset($L['sessions'][$session])) $session = null;
            $start = ci_clean_date($_POST['start_date'] ?? '');
            $end = ci_clean_date($_POST['end_date'] ?? '');
            if ($start && $end && $end < $start) throw new RuntimeException('The end date is before the start date.');
            $fee = null; // batches have no fee of their own: the course fee is used
            $cap = trim((string)($_POST['capacity'] ?? '')) === '' ? null : max(0, (int)$_POST['capacity']);
            $status = in_array($_POST['status'] ?? '', ['active', 'completed', 'inactive'], true) ? $_POST['status'] : 'active';
            $vals = [$name, $course, $session, trim((string)($_POST['timing'] ?? '')) ?: null, trim((string)($_POST['days'] ?? '')) ?: null, $start, $end, $cap, $fee,
                trim((string)($_POST['teacher'] ?? '')) ?: null, $status];
            if ($id) {
                $old = $pdo->prepare("SELECT course_id FROM ci_batches WHERE id = ?"); $old->execute([$id]);
                $oldCourse = (int)$old->fetchColumn();
                $hasStudents = (int)$pdo->query("SELECT COUNT(*) FROM ci_enrollments WHERE batch_id = $id")->fetchColumn();
                if ($hasStudents && $oldCourse !== $course) throw new RuntimeException('This batch already has students, so its course cannot be changed.');
                $vals[] = $id;
                $pdo->prepare("UPDATE ci_batches SET name=?, course_id=?, session_id=?, timing=?, days=?, start_date=?, end_date=?, capacity=?, fee=?, teacher=?, status=? WHERE id=?")->execute($vals);
            } else {
                $pdo->prepare("INSERT INTO ci_batches (name, course_id, session_id, timing, days, start_date, end_date, capacity, fee, teacher, status) VALUES (?,?,?,?,?,?,?,?,?,?,?)")->execute($vals);
            }
            admin_log('ci_batch', "Saved batch $name");
            admin_flash('Batch saved.');
        } elseif ($a === 'complete' && $id) {
            $n = ci_complete_batch($pdo, $id);
            admin_log('ci_batch', "Completed batch #$id");
            admin_flash('Batch completed' . ($n ? " ($n students' course marked completed; their dues stay)" : '') . '.');
        } elseif ($a === 'delete' && $id) {
            $used = (int)$pdo->query("SELECT COUNT(*) FROM ci_enrollments WHERE batch_id = $id")->fetchColumn();
            if ($used) throw new RuntimeException('This batch has students. Mark it Completed or Inactive instead of deleting.');
            $pdo->prepare("DELETE FROM ci_batches WHERE id = ?")->execute([$id]);
            admin_flash('Batch deleted.');
        }
    } catch (RuntimeException $e) {
        admin_flash($e->getMessage(), 'error');
    }
    header('Location: /admin/ci-batches.php');
    exit;
}

$fCourse = (int)($_GET['course'] ?? 0);
$fSession = isset($_GET['session']) ? (int)$_GET['session'] : (int)ci_current_session($pdo);
$fStatus = in_array($_GET['status'] ?? '', ['active', 'completed', 'inactive', 'all'], true) ? $_GET['status'] : 'active';
$where = ['1'];
$args = [];
if ($fCourse) { $where[] = 'b.course_id = ?'; $args[] = $fCourse; }
if ($fSession) { $where[] = 'b.session_id = ?'; $args[] = $fSession; }
if ($fStatus !== 'all') { $where[] = 'b.status = ?'; $args[] = $fStatus; }
$st = $pdo->prepare("SELECT b.*, c.name AS course_name, c.fee AS course_fee, s.name AS session_name,
    (SELECT COUNT(*) FROM ci_enrollments e WHERE e.batch_id = b.id AND e.status = 'active') AS students,
    (SELECT COALESCE(SUM(" . CI_DUE_SQL . "),0) FROM ci_enrollments e WHERE e.batch_id = b.id) AS due
    FROM ci_batches b LEFT JOIN ci_courses c ON c.id = b.course_id LEFT JOIN ci_sessions s ON s.id = b.session_id
    WHERE " . implode(' AND ', $where) . " ORDER BY b.status = 'active' DESC, b.start_date DESC, b.name");
$st->execute($args);
$all = $st->fetchAll(PDO::FETCH_ASSOC);
// rows per page: 10 (default), 20, 50 or all
$per = in_array($_GET['per'] ?? '', ['10', '20', '50', 'all'], true) ? $_GET['per'] : '10';
$total = count($all);
$pages = $per === 'all' ? 1 : max(1, (int)ceil($total / (int)$per));
$page = min($pages, max(1, (int)($_GET['page'] ?? 1)));
$batches = $per === 'all' ? $all : array_slice($all, ($page - 1) * (int)$per, (int)$per);
$qs = fn(array $o = []) => http_build_query(array_merge(['course' => $fCourse, 'session' => $fSession, 'status' => $fStatus, 'per' => $per], $o));

$edit = (int)($_GET['edit'] ?? 0);
$eb = null;
if ($edit) { $q = $pdo->prepare("SELECT * FROM ci_batches WHERE id = ?"); $q->execute([$edit]); $eb = $q->fetch(PDO::FETCH_ASSOC) ?: null; }
$open = $eb || isset($_GET['new']);
$curSession = ci_current_session($pdo);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title>Batches - <?= ci_e(edu_site_name()) ?></title>
<?php admin_shell_head(); ci_head(); ci_report_assets(); ?>
</head>
<body>
<?php admin_shell_open('Batches', 'Groups of students per course and session, with timing and seats'); ?>
<div class="ci">
<?= admin_flash() ?>
<div class="ci-head"><div class="ci-show"><label for="ci-per">Show</label><select id="ci-per" onchange="location.href='?<?= ci_e($qs(['per' => '__P__', 'page' => 1])) ?>'.replace('__P__', this.value)"><?php foreach (['10' => '10', '20' => '20', '50' => '50', 'all' => 'All'] as $k => $l): ?><option value="<?= $k ?>" <?= $per === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select><span class="ci-muted">of <?= $total ?></span></div>
<div class="ci-actions"><?php if (!$open): ?><a class="ci-btn" href="?new=1"><i class="fas fa-plus"></i> New Batch</a><?php endif; ?></div></div>

<?php if (!$L['courses']): ?><div class="ci-alert ci-alert-err">Add a course first: <a href="/admin/ci-courses.php?new=1">Courses → Add Course</a>.</div><?php endif; ?>

<?php if ($open && $L['courses']): ?>
<div class="ci-card"><div class="ci-card-h"><h3><?= $eb ? 'Edit batch' : 'New batch' ?></h3><a href="/admin/ci-batches.php">Cancel</a></div><div class="ci-card-b">
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($eb['id'] ?? 0) ?>">
<div class="ci-grid4">
  <div class="ci-f"><label>Batch name <span class="ci-req">*</span></label><input name="name" required maxlength="150" placeholder="e.g. Morning A" value="<?= ci_e($eb['name'] ?? '') ?>"></div>
  <div class="ci-f"><label>Course <span class="ci-req">*</span></label><select name="course_id" required><option value="">Choose…</option><?php foreach ($L['courses'] as $cid => $c): ?><option value="<?= $cid ?>" <?= (int)($eb['course_id'] ?? $fCourse) === $cid ? 'selected' : '' ?>><?= ci_e($c['name']) ?> (<?= ci_money0($c['fee']) ?>)</option><?php endforeach; ?></select></div>
  <div class="ci-f"><label>Session</label><select name="session_id"><option value="">—</option><?php foreach ($L['sessions'] as $sid => $sn): ?><option value="<?= $sid ?>" <?= (int)($eb['session_id'] ?? $curSession) === $sid ? 'selected' : '' ?>><?= ci_e($sn) ?></option><?php endforeach; ?></select></div>
  <div class="ci-f"><label>Status</label><select name="status"><?php foreach (['active' => 'Active', 'completed' => 'Completed', 'inactive' => 'Inactive'] as $k => $l): ?><option value="<?= $k ?>" <?= ($eb['status'] ?? 'active') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
  <div class="ci-f"><label>Timing</label><input name="timing" maxlength="80" placeholder="e.g. 7:00–9:00 AM" value="<?= ci_e($eb['timing'] ?? '') ?>"></div>
  <div class="ci-f"><label>Days</label><input name="days" maxlength="80" placeholder="e.g. Mon–Sat" value="<?= ci_e($eb['days'] ?? '') ?>"></div>
  <div class="ci-f"><label>Teacher</label><input name="teacher" maxlength="120" value="<?= ci_e($eb['teacher'] ?? '') ?>"></div>
  <div class="ci-f"><label>Seats</label><input type="number" name="capacity" min="0" placeholder="no limit" value="<?= ci_e($eb['capacity'] ?? '') ?>"></div>
  <div class="ci-f"><label>Starts</label><input type="date" name="start_date" value="<?= ci_e($eb['start_date'] ?? '') ?>"></div>
  <div class="ci-f"><label>Ends</label><input type="date" name="end_date" value="<?= ci_e($eb['end_date'] ?? '') ?>"></div>
</div>
<div style="margin-top:14px"><button class="ci-btn" type="submit"><i class="fas fa-check"></i> <?= $eb ? 'Save batch' : 'Create batch' ?></button></div>
</form></div></div>
<?php endif; ?>

<div class="ci-card"><div class="ci-card-b"><form method="get" class="ci-filters">
  <div class="ci-f"><label>Course</label><select name="course"><option value="0">All courses</option><?php foreach ($L['courses'] as $cid => $c): ?><option value="<?= $cid ?>" <?= $fCourse === $cid ? 'selected' : '' ?>><?= ci_e($c['name']) ?></option><?php endforeach; ?></select></div>
  <div class="ci-f"><label>Session</label><select name="session"><option value="0">All sessions</option><?php foreach ($L['sessions'] as $sid => $sn): ?><option value="<?= $sid ?>" <?= $fSession === $sid ? 'selected' : '' ?>><?= ci_e($sn) ?></option><?php endforeach; ?></select></div>
  <div class="ci-f"><label>Status</label><select name="status"><?php foreach (['active' => 'Active', 'completed' => 'Completed', 'inactive' => 'Inactive', 'all' => 'All'] as $k => $l): ?><option value="<?= $k ?>" <?= $fStatus === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
  <div class="ci-fbtn"><button class="ci-btn" type="submit"><i class="fas fa-filter"></i> Filter</button><a class="ci-btn ci-ghost" href="/admin/ci-batches.php?session=0&amp;status=all">Reset</a></div>
</form></div></div>

<div class="ci-card"><div class="ci-card-b ci-p0 ci-table-wrap"><table class="ci-table">
<thead><tr><th>Batch</th><th>Course</th><th>Timing</th><th>Session</th><th class="ci-num">Course fee</th><th class="ci-num">Students</th><th class="ci-num">Due</th><th>Status</th><th>Action</th></tr></thead><tbody>
<?php if (!$batches): ?><tr><td colspan="9" class="ci-empty">No batches for these filters.</td></tr><?php endif; ?>
<?php foreach ($batches as $b): $fee = $b['course_fee']; ?>
<tr><td><b><?= ci_e($b['name']) ?></b><?php if ($b['teacher']): ?><small class="ci-muted" style="display:block"><?= ci_e($b['teacher']) ?></small><?php endif; ?></td>
<td><?= ci_e($b['course_name'] ?? '—') ?></td>
<td class="ci-muted"><?= ci_e(trim(($b['timing'] ?? '') . ' ' . ($b['days'] ? '· ' . $b['days'] : '')) ?: '—') ?></td><td class="ci-muted"><?= ci_e($b['session_name'] ?? '—') ?></td>
<td class="ci-num"><?= ci_money0($fee) ?></td>
<td class="ci-num"><a href="/admin/students.php?batch=<?= (int)$b['id'] ?>"><?= (int)$b['students'] ?></a><?= $b['capacity'] ? ' <span class="ci-muted">/ ' . (int)$b['capacity'] . '</span>' : '' ?></td>
<td class="ci-num"><?= (float)$b['due'] > 0 ? '<span style="color:#b91c1c">' . ci_money0($b['due']) . '</span>' : '—' ?></td>
<td><?= ci_status_badge($b['status']) ?></td>
<td><div class="ci-icos"><?= ci_ico('edit', '?edit=' . (int)$b['id'], 'Edit batch') ?><?php if ($b['status'] === 'active'): ?><?= ci_ico('user-plus', '/admin/student-edit.php?batch=' . (int)$b['id'], 'Add student to this batch') ?><?php endif; ?><?= ci_ico('pdf', null, 'PDF report', 'button', ' data-report="batch" data-id="' . (int)$b['id'] . '"') ?>
<?php if ($b['status'] === 'active'): ?><?= ci_post_ico('check', 'Mark batch completed', ['action' => 'complete', 'id' => $b['id']], 'Complete batch ' . $b['name'] . '? Its students\' course in this batch is marked completed (dues stay).') ?><?php endif; ?>
<?php if (!(int)$pdo->query("SELECT COUNT(*) FROM ci_enrollments WHERE batch_id = " . (int)$b['id'])->fetchColumn()): ?><?= ci_post_ico('trash', 'Delete batch', ['action' => 'delete', 'id' => $b['id']], 'Delete batch ' . $b['name'] . '?') ?><?php endif; ?>
</div></td></tr>
<?php endforeach; ?>
</tbody></table></div>
<?php if ($pages > 1): ?><div class="ci-pager"><span>Page <?= $page ?> of <?= $pages ?></span><div class="ci-pg">
<?php for ($i = 1; $i <= $pages; $i++): ?><?= $i === $page ? '<span class="on">' . $i . '</span>' : '<a href="?' . ci_e($qs(['page' => $i])) . '">' . $i . '</a>' ?><?php endfor; ?>
</div></div><?php endif; ?></div>
</div>
<?php admin_shell_close(); ?>
</body>
</html>
