<?php
// Sessions & Classes — academic sessions (e.g. 2026-27, one is "current") and the list of classes used in the student form.
require_once __DIR__ . '/components/guard.php';
require_once INCLUDES_PATH . '/institute.php';
$me = ci_page_start($pdo);
$tab = ($_GET['tab'] ?? '') === 'classes' ? 'classes' : 'sessions';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $a = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    try {
        if ($a === 'session_save') {
            $name = trim((string)($_POST['name'] ?? ''));
            $start = ci_clean_date($_POST['start_date'] ?? '');
            $end = ci_clean_date($_POST['end_date'] ?? '');
            $cur = !empty($_POST['is_current']) ? 1 : 0;
            if ($name === '') throw new RuntimeException('Enter the session name, e.g. 2026-27.');
            if ($start && $end && $end < $start) throw new RuntimeException('The end date is before the start date.');
            if ($id) $pdo->prepare("UPDATE ci_sessions SET name = ?, start_date = ?, end_date = ? WHERE id = ?")->execute([$name, $start, $end, $id]);
            else { $pdo->prepare("INSERT INTO ci_sessions (name, start_date, end_date) VALUES (?, ?, ?)")->execute([$name, $start, $end]); $id = (int)$pdo->lastInsertId(); }
            if ($cur) { $pdo->exec("UPDATE ci_sessions SET is_current = 0"); $pdo->prepare("UPDATE ci_sessions SET is_current = 1 WHERE id = ?")->execute([$id]); }
            admin_log('ci_session', "Saved session $name");
            admin_flash('Session saved.');
        } elseif ($a === 'session_current' && $id) {
            $pdo->exec("UPDATE ci_sessions SET is_current = 0");
            $pdo->prepare("UPDATE ci_sessions SET is_current = 1 WHERE id = ?")->execute([$id]);
            admin_flash('Current session changed.');
        } elseif ($a === 'session_delete' && $id) {
            $used = (int)$pdo->query("SELECT (SELECT COUNT(*) FROM ci_batches WHERE session_id = $id) + (SELECT COUNT(*) FROM ci_enrollments WHERE session_id = $id)")->fetchColumn();
            if ($used) throw new RuntimeException('This session is used by batches or students, so it cannot be deleted.');
            $pdo->prepare("DELETE FROM ci_sessions WHERE id = ?")->execute([$id]);
            admin_flash('Session deleted.');
        } elseif ($a === 'class_save') {
            $name = trim((string)($_POST['name'] ?? ''));
            $sort = (int)($_POST['sort'] ?? 0);
            if ($name === '') throw new RuntimeException('Enter the class name.');
            if ($id) $pdo->prepare("UPDATE ci_classes SET name = ?, sort = ? WHERE id = ?")->execute([$name, $sort, $id]);
            else $pdo->prepare("INSERT INTO ci_classes (name, sort) VALUES (?, ?)")->execute([$name, $sort]);
            admin_flash('Class saved.');
        } elseif ($a === 'class_delete' && $id) {
            $used = (int)$pdo->query("SELECT COUNT(*) FROM ci_students WHERE class_id = $id")->fetchColumn();
            if ($used) throw new RuntimeException("$used students are in this class, so it cannot be deleted.");
            $pdo->prepare("DELETE FROM ci_classes WHERE id = ?")->execute([$id]);
            admin_flash('Class deleted.');
        }
    } catch (PDOException $e) {
        admin_flash($e->getCode() === '23000' ? 'That name is already in the list.' : 'Could not save, please try again.', 'error');
    } catch (RuntimeException $e) {
        admin_flash($e->getMessage(), 'error');
    }
    header('Location: /admin/ci-setup.php' . ($a !== '' && str_starts_with($a, 'class') ? '?tab=classes' : ''));
    exit;
}

$edit = (int)($_GET['edit'] ?? 0);
$sessions = $pdo->query("SELECT s.*, (SELECT COUNT(*) FROM ci_batches b WHERE b.session_id = s.id) AS batches,
    (SELECT COUNT(DISTINCT e.student_id) FROM ci_enrollments e WHERE e.session_id = s.id) AS students
    FROM ci_sessions s ORDER BY s.start_date DESC, s.name DESC")->fetchAll(PDO::FETCH_ASSOC);
$classes = $pdo->query("SELECT c.*, (SELECT COUNT(*) FROM ci_students s WHERE s.class_id = c.id) AS students FROM ci_classes c ORDER BY c.sort, c.name")->fetchAll(PDO::FETCH_ASSOC);
$es = $tab === 'sessions' && $edit ? (array_values(array_filter($sessions, fn($s) => (int)$s['id'] === $edit))[0] ?? null) : null;
$ec = $tab === 'classes' && $edit ? (array_values(array_filter($classes, fn($s) => (int)$s['id'] === $edit))[0] ?? null) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title>Sessions &amp; Classes - <?= ci_e(edu_site_name()) ?></title>
<?php admin_shell_head(); ci_head(); ?>
</head>
<body>
<?php admin_shell_open('Sessions &amp; Classes', 'Academic sessions and the class list used in the student form'); ?>
<div class="ci">
<?= admin_flash() ?>
<div class="ci-tabs"><a href="/admin/ci-setup.php" class="<?= $tab === 'sessions' ? 'on' : '' ?>">Academic Sessions</a><a href="?tab=classes" class="<?= $tab === 'classes' ? 'on' : '' ?>">Classes</a></div>

<?php if ($tab === 'sessions'): ?>
<div class="ci-card"><div class="ci-card-h"><h3><?= $es ? 'Edit session' : 'Add a session' ?></h3><?php if ($es): ?><a href="/admin/ci-setup.php">Cancel</a><?php endif; ?></div><div class="ci-card-b">
<form method="post" class="ci-inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="session_save"><input type="hidden" name="id" value="<?= (int)($es['id'] ?? 0) ?>">
  <div class="ci-f"><label>Session name <span class="ci-req">*</span></label><input name="name" required maxlength="40" placeholder="2026-27" value="<?= ci_e($es['name'] ?? '') ?>"></div>
  <div class="ci-f"><label>Starts</label><input type="date" name="start_date" value="<?= ci_e($es['start_date'] ?? '') ?>"></div>
  <div class="ci-f"><label>Ends</label><input type="date" name="end_date" value="<?= ci_e($es['end_date'] ?? '') ?>"></div>
  <div class="ci-f"><label style="display:flex;gap:6px;align-items:center;height:38px"><input type="checkbox" name="is_current" value="1" style="width:auto;height:auto" <?= !empty($es['is_current']) || !$sessions ? 'checked' : '' ?>> Current session</label></div>
  <div class="ci-f"><button class="ci-btn" type="submit"><i class="fas fa-check"></i> <?= $es ? 'Save' : 'Add session' ?></button></div>
</form></div></div>
<div class="ci-card"><div class="ci-card-b ci-p0 ci-table-wrap"><table class="ci-table">
<thead><tr><th>Session</th><th>Dates</th><th class="ci-num">Batches</th><th class="ci-num">Students</th><th>Status</th><th></th></tr></thead><tbody>
<?php if (!$sessions): ?><tr><td colspan="6" class="ci-empty">No sessions yet.</td></tr><?php endif; ?>
<?php foreach ($sessions as $s): ?>
<tr><td><b><?= ci_e($s['name']) ?></b></td><td class="ci-muted"><?= ci_date($s['start_date']) ?> – <?= ci_date($s['end_date']) ?></td>
<td class="ci-num"><?= (int)$s['batches'] ?></td><td class="ci-num"><?= (int)$s['students'] ?></td>
<td><?= $s['is_current'] ? '<span class="ci-badge ci-b-green">Current</span>' : '' ?></td>
<td><div class="ci-row-actions"><a href="?edit=<?= (int)$s['id'] ?>">Edit</a>
<?php if (!$s['is_current']): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="session_current"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><button type="submit">Make current</button></form><?php endif; ?>
<?php if (!$s['batches'] && !$s['students']): ?><form method="post" onsubmit="return confirm('Delete session <?= ci_e(addslashes($s['name'])) ?>?')"><?= csrf_field() ?><input type="hidden" name="action" value="session_delete"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><button type="submit" class="ci-del">Delete</button></form><?php endif; ?>
</div></td></tr>
<?php endforeach; ?>
</tbody></table></div></div>

<?php else: ?>
<div class="ci-card"><div class="ci-card-h"><h3><?= $ec ? 'Edit class' : 'Add a class' ?></h3><?php if ($ec): ?><a href="?tab=classes">Cancel</a><?php endif; ?></div><div class="ci-card-b">
<form method="post" class="ci-inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="class_save"><input type="hidden" name="id" value="<?= (int)($ec['id'] ?? 0) ?>">
  <div class="ci-f"><label>Class name <span class="ci-req">*</span></label><input name="name" required maxlength="100" placeholder="e.g. 10th, B.Sc, SSC" value="<?= ci_e($ec['name'] ?? '') ?>"></div>
  <div class="ci-f"><label>Order in the list</label><input type="number" name="sort" value="<?= (int)($ec['sort'] ?? (count($classes) + 1)) ?>"></div>
  <div class="ci-f"><button class="ci-btn" type="submit"><i class="fas fa-check"></i> <?= $ec ? 'Save' : 'Add class' ?></button></div>
</form></div></div>
<div class="ci-card"><div class="ci-card-b ci-p0 ci-table-wrap"><table class="ci-table">
<thead><tr><th>#</th><th>Class</th><th class="ci-num">Students</th><th></th></tr></thead><tbody>
<?php if (!$classes): ?><tr><td colspan="4" class="ci-empty">No classes yet.</td></tr><?php endif; ?>
<?php foreach ($classes as $c): ?>
<tr><td class="ci-muted"><?= (int)$c['sort'] ?></td><td><b><?= ci_e($c['name']) ?></b></td><td class="ci-num"><?= (int)$c['students'] ?></td>
<td><div class="ci-row-actions"><a href="?tab=classes&amp;edit=<?= (int)$c['id'] ?>">Edit</a>
<?php if (!$c['students']): ?><form method="post" onsubmit="return confirm('Delete class <?= ci_e(addslashes($c['name'])) ?>?')"><?= csrf_field() ?><input type="hidden" name="action" value="class_delete"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button type="submit" class="ci-del">Delete</button></form><?php endif; ?>
</div></td></tr>
<?php endforeach; ?>
</tbody></table></div></div>
<?php endif; ?>
</div>
<?php admin_shell_close(); ?>
</body>
</html>
