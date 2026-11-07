<?php
// Sessions & Classes — academic sessions (e.g. 2026-27, one is "current") and the list of classes used in the student form.
require_once __DIR__ . '/components/guard.php';
require_once INCLUDES_PATH . '/institute.php';
$me = ci_page_start($pdo, ["sessions", "classes"]);
$tab = ($_GET['tab'] ?? '') === 'classes' ? 'classes' : 'sessions';
if (!ci_perm($me, $tab)) $tab = $tab === 'classes' ? 'sessions' : 'classes'; // only the part the user may manage

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $a = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    if ((str_starts_with($a, 'session') && !ci_perm($me, 'sessions')) || (str_starts_with($a, 'class') && !ci_perm($me, 'classes'))) { http_response_code(403); exit('Access Denied'); }
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
            // deletes the session with its batches, its students' courses and payments, the students who were only in it,
            // and the courses that only it used — after the user typed the session's name on the confirm screen
            if ($me['role'] !== 'admin') throw new RuntimeException('Only an admin can delete a session.');
            $q = $pdo->prepare("SELECT * FROM ci_sessions WHERE id = ?"); $q->execute([$id]);
            $ses = $q->fetch(PDO::FETCH_ASSOC);
            if (!$ses) throw new RuntimeException('Session not found.');
            if ((int)$ses['is_current']) throw new RuntimeException('This is the current session. Make another session current first.');
            if (trim((string)($_POST['confirm_name'] ?? '')) !== $ses['name']) { admin_flash('Type the session name exactly (' . $ses['name'] . ') to delete it.', 'error'); header('Location: /admin/ci-setup.php?purge=' . $id); exit; }
            $done = ci_session_purge($pdo, $id);
            admin_log('ci_session_delete', "Deleted session {$ses['name']}: " . count($done['batches']) . ' batches, ' . count($done['courses']) . ' courses, ' . count($done['students']) . ' students, ' . count($done['enrollments']) . ' course entries, ' . $done['payments'] . ' payments (' . ci_money($done['paid']) . ')');
            admin_flash('Session ' . $ses['name'] . ' deleted with ' . count($done['batches']) . ' batches, ' . count($done['courses']) . ' courses and ' . count($done['students']) . ' students.');
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
$sf = fn(array $x): string => ci_e(json_encode(['id' => (int)$x['id'], 'name' => $x['name'], 'start_date' => (string)$x['start_date'], 'end_date' => (string)$x['end_date'], 'is_current' => (int)$x['is_current']], JSON_UNESCAPED_UNICODE));
$cf = fn(array $x): string => ci_e(json_encode(['id' => (int)$x['id'], 'name' => $x['name'], 'sort' => (int)$x['sort']], JSON_UNESCAPED_UNICODE));
$addBtn = $tab === 'sessions'
    ? '<button type="button" class="ci-btn" data-mopen="#smForm" data-mtitle="Add a session" data-fill=\'{"id":0,"is_current":' . ($sessions ? 0 : 1) . '}\'><i class="fas fa-plus"></i> Add Session</button>'
    : '<button type="button" class="ci-btn" data-mopen="#kmForm" data-mtitle="Add a class" data-fill=\'{"id":0,"sort":' . (count($classes) + 1) . '}\'><i class="fas fa-plus"></i> Add Class</button>';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title>Sessions &amp; Classes - <?= ci_e(edu_site_name()) ?></title>
<?php admin_shell_head(); ci_head(); ci_report_assets(); ?>
</head>
<body>
<?php admin_shell_open('Sessions &amp; Classes', 'Academic sessions and the class list used in the student form', $addBtn); ?>
<div class="ci">
<?= admin_flash() ?>
<div class="ci-tabs"><a href="/admin/ci-setup.php" class="<?= $tab === 'sessions' ? 'on' : '' ?>">Academic Sessions</a><a href="?tab=classes" class="<?= $tab === 'classes' ? 'on' : '' ?>">Classes</a></div>

<?php $purge = (int)($_GET['purge'] ?? 0); $ps = $purge ? (array_values(array_filter($sessions, fn($x) => (int)$x['id'] === $purge))[0] ?? null) : null;
if ($tab === 'sessions' && $ps && !$ps['is_current'] && $me['role'] === 'admin'):
    $plan = ci_session_plan($pdo, $purge);
    $names = fn(string $t, string $col, array $ids, int $max = 40) => $ids ? $pdo->query("SELECT $col FROM $t WHERE id IN (" . implode(',', array_slice($ids, 0, $max)) . ") ORDER BY $col")->fetchAll(PDO::FETCH_COLUMN) : []; ?>
<div class="ci-card ci-danger-card"><div class="ci-card-h"><h3>Delete session <?= ci_e($ps['name']) ?>?</h3><a href="/admin/ci-setup.php">Cancel</a></div><div class="ci-card-b">
  <p style="margin:0 0 12px">This deletes the session and <b>everything that belongs only to it</b>. It cannot be undone.</p>
  <div class="ci-stats" style="margin-bottom:12px">
    <div class="ci-stat"><i class="fas fa-layer-group ci-i-red"></i><div><b><?= count($plan['batches']) ?></b><small>batches</small></div></div>
    <div class="ci-stat"><i class="fas fa-book-open ci-i-red"></i><div><b><?= count($plan['courses']) ?></b><small>courses (used only here)</small></div></div>
    <div class="ci-stat"><i class="fas fa-user-graduate ci-i-red"></i><div><b><?= count($plan['students']) ?></b><small>students (only in this session)</small></div></div>
    <div class="ci-stat"><i class="fas fa-receipt ci-i-red"></i><div><b><?= $plan['payments'] ?></b><small>payments · <?= ci_money0($plan['paid']) ?></small></div></div>
  </div>
  <?php if ($plan['kept']): ?><p class="ci-muted" style="margin:0 0 10px"><b><?= count($plan['kept']) ?> student(s)</b> also study in another session or batch: they stay; only their courses of <?= ci_e($ps['name']) ?> are removed.</p><?php endif; ?>
  <?php foreach ([['Batches', 'ci_batches', 'name', $plan['batches']], ['Courses', 'ci_courses', 'name', $plan['courses']], ['Students', 'ci_students', 'name', $plan['students']]] as [$lbl, $tb, $col, $ids]): if (!$ids) continue; $n = $names($tb, $col, $ids); ?>
    <p style="margin:0 0 6px;font-size:13px"><b><?= $lbl ?>:</b> <?= ci_e(implode(', ', $n)) ?><?= count($ids) > count($n) ? ' … and ' . (count($ids) - count($n)) . ' more' : '' ?></p>
  <?php endforeach; ?>
  <form method="post" class="ci-inline-form" style="margin-top:14px" onsubmit="return confirm('Delete session <?= ci_e(addslashes($ps['name'])) ?> and everything listed? This cannot be undone.')"><?= csrf_field() ?><input type="hidden" name="action" value="session_delete"><input type="hidden" name="id" value="<?= (int)$ps['id'] ?>">
    <div class="ci-f"><label>Type <b><?= ci_e($ps['name']) ?></b> to confirm</label><input name="confirm_name" autocomplete="off" required></div>
    <div class="ci-f"><button class="ci-btn ci-danger-solid" type="submit"><?= ci_svg('trash') ?> Delete everything</button></div>
  </form>
</div></div>
<?php endif; ?>
<?php if ($tab === 'sessions'): ?>
<div class="ci-modal" id="smForm" data-mform<?= $es ? ' data-open-now' : '' ?> hidden><div class="ci-modal-box" role="dialog" aria-modal="true" aria-labelledby="smFormH">
<div class="ci-card-h"><h3 id="smFormH" data-mtitle-target><?= $es ? 'Edit session' : 'Add a session' ?></h3><button type="button" class="ci-x" data-close aria-label="Close"><i class="fas fa-times"></i></button></div><div class="ci-card-b">
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="session_save"><input type="hidden" name="id" value="<?= (int)($es['id'] ?? 0) ?>">
  <div class="ci-grid2 ci-mgrid">
  <div class="ci-f" style="grid-column:1/-1"><label>Session name <span class="ci-req">*</span></label><input name="name" required maxlength="40" placeholder="2026-27" value="<?= ci_e($es['name'] ?? '') ?>"></div>
  <div class="ci-f"><label>Starts</label><input type="date" name="start_date" value="<?= ci_e($es['start_date'] ?? '') ?>"></div>
  <div class="ci-f"><label>Ends</label><input type="date" name="end_date" value="<?= ci_e($es['end_date'] ?? '') ?>"></div>
  <div class="ci-f" style="grid-column:1/-1"><label style="display:flex;gap:8px;align-items:center;font-weight:500"><input type="checkbox" name="is_current" value="1" style="width:auto;height:auto" <?= !empty($es['is_current']) || !$sessions ? 'checked' : '' ?>> This is the current session</label></div>
  </div>
  <div class="ci-mfoot"><button type="button" class="ci-btn ci-ghost" data-close>Cancel</button><button class="ci-btn" type="submit"><i class="fas fa-check"></i> Save session</button></div>
</form></div></div></div>
<div class="ci-card"><div class="ci-card-b ci-p0 ci-table-wrap"><table class="ci-table">
<thead><tr><th>Session</th><th>Dates</th><th class="ci-num">Batches</th><th class="ci-num">Students</th><th>Status</th><th>Action</th></tr></thead><tbody>
<?php if (!$sessions): ?><tr><td colspan="6" class="ci-empty">No sessions yet.</td></tr><?php endif; ?>
<?php foreach ($sessions as $s): ?>
<tr><td><b><?= ci_e($s['name']) ?></b></td><td class="ci-muted"><?= ci_date($s['start_date']) ?> – <?= ci_date($s['end_date']) ?></td>
<td class="ci-num"><?= (int)$s['batches'] ?></td><td class="ci-num"><?= (int)$s['students'] ?></td>
<td><?= $s['is_current'] ? '<span class="ci-badge ci-b-green">Current</span>' : '' ?></td>
<td><div class="ci-icos"><?= ci_ico('edit', null, 'Edit session', 'button', ' data-mopen="#smForm" data-mtitle="Edit session" data-fill="' . $sf($s) . '"') ?>
<?php if (!$s['is_current']): ?><?= ci_post_ico('flag', 'Make this the current session', ['action' => 'session_current', 'id' => $s['id']]) ?><?php endif; ?>
<?php if (!$s['is_current'] && $me['role'] === 'admin'): ?><?= ci_ico('trash', '?purge=' . (int)$s['id'], 'Delete session') ?><?php endif; ?>
</div></td></tr>
<?php endforeach; ?>
</tbody></table></div></div>

<?php else: ?>
<div class="ci-modal" id="kmForm" data-mform<?= $ec ? ' data-open-now' : '' ?> hidden><div class="ci-modal-box" role="dialog" aria-modal="true" aria-labelledby="kmFormH">
<div class="ci-card-h"><h3 id="kmFormH" data-mtitle-target><?= $ec ? 'Edit class' : 'Add a class' ?></h3><button type="button" class="ci-x" data-close aria-label="Close"><i class="fas fa-times"></i></button></div><div class="ci-card-b">
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="class_save"><input type="hidden" name="id" value="<?= (int)($ec['id'] ?? 0) ?>">
  <div class="ci-grid2 ci-mgrid">
  <div class="ci-f"><label>Class name <span class="ci-req">*</span></label><input name="name" required maxlength="100" placeholder="e.g. 10th, B.Sc, SSC" value="<?= ci_e($ec['name'] ?? '') ?>"></div>
  <div class="ci-f"><label>Order in the list</label><input type="number" name="sort" value="<?= (int)($ec['sort'] ?? (count($classes) + 1)) ?>"></div>
  </div>
  <div class="ci-mfoot"><button type="button" class="ci-btn ci-ghost" data-close>Cancel</button><button class="ci-btn" type="submit"><i class="fas fa-check"></i> Save class</button></div>
</form></div></div></div>
<div class="ci-card"><div class="ci-card-b ci-p0 ci-table-wrap"><table class="ci-table">
<thead><tr><th>#</th><th>Class</th><th class="ci-num">Students</th><th>Action</th></tr></thead><tbody>
<?php if (!$classes): ?><tr><td colspan="4" class="ci-empty">No classes yet.</td></tr><?php endif; ?>
<?php foreach ($classes as $c): ?>
<tr><td class="ci-muted"><?= (int)$c['sort'] ?></td><td><b><?= ci_e($c['name']) ?></b></td><td class="ci-num"><?= (int)$c['students'] ?></td>
<td><div class="ci-icos"><?= ci_ico('edit', null, 'Edit class', 'button', ' data-mopen="#kmForm" data-mtitle="Edit class" data-fill="' . $cf($c) . '"') ?><?= ci_ico('pdf', null, 'Class report (PDF)', 'button', ' data-report="class" data-id="' . (int)$c['id'] . '"') ?>
<?php if (!$c['students']): ?><?= ci_post_ico('trash', 'Delete class', ['action' => 'class_delete', 'id' => $c['id']], 'Delete class ' . $c['name'] . '?') ?><?php endif; ?>
</div></td></tr>
<?php endforeach; ?>
</tbody></table></div></div>
<?php endif; ?>
</div>
<?php admin_shell_close(); ?>
</body>
</html>
