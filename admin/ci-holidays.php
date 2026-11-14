<?php
// Holidays (Institute): from – to, for every course and batch, one course or one batch. On those days there is no class:
// the attendance page shows Holiday, nobody is made absent, reports and the student's calendar count them as holidays.
require_once __DIR__ . '/components/guard.php';
require_once INCLUDES_PATH . '/institute.php';
$me = ci_page_start($pdo, ['attendance']);
$courses = $pdo->query("SELECT id, name FROM ci_courses WHERE status = 'active' ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);
$batches = $pdo->query("SELECT b.id, b.name, c.name AS course_name FROM ci_batches b LEFT JOIN ci_courses c ON c.id = b.course_id WHERE b.status = 'active' ORDER BY c.name, b.name")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $a = (string)($_POST['action'] ?? '');
    try {
        if ($a === 'add') {
            $title = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 150);
            $from = ci_clean_date($_POST['from_date'] ?? ''); $to = ci_clean_date($_POST['to_date'] ?? '') ?: $from;
            if ($title === '') throw new RuntimeException('Enter the name of the holiday (e.g. Holi, Summer vacation).');
            if (!$from) throw new RuntimeException('Choose the date the holiday starts.');
            if ($to < $from) throw new RuntimeException('The last day is before the first day.');
            $for = (string)($_POST['for'] ?? 'all'); $cid = $bid = null;
            if ($for === 'course') { $cid = (int)($_POST['course_id'] ?? 0); if (!isset($courses[$cid])) throw new RuntimeException('Choose the course.'); }
            if ($for === 'batch') { $bid = (int)($_POST['batch_id'] ?? 0); if (!in_array($bid, array_map('intval', array_column($batches, 'id')), true)) throw new RuntimeException('Choose the batch.'); }
            $pdo->prepare("INSERT INTO ci_holidays (title, from_date, to_date, course_id, batch_id, created_by) VALUES (?,?,?,?,?,?)")->execute([$title, $from, $to, $cid, $bid, (int)$me['id']]);
            admin_log('ci_holiday', "Added holiday $title ($from – $to)");
            admin_flash('Holiday saved: ' . $title . ', ' . ci_date($from) . ($to !== $from ? ' – ' . ci_date($to) : '') . '.');
        } elseif ($a === 'delete') {
            $pdo->prepare("DELETE FROM ci_holidays WHERE id = ?")->execute([(int)($_POST['id'] ?? 0)]);
            admin_flash('Holiday removed.');
        }
    } catch (RuntimeException $e) { admin_flash($e->getMessage(), 'error'); }
    header('Location: /admin/ci-holidays.php');
    exit;
}

$list = $pdo->query("SELECT h.*, c.name AS course_name, b.name AS batch_name FROM ci_holidays h LEFT JOIN ci_courses c ON c.id = h.course_id LEFT JOIN ci_batches b ON b.id = h.batch_id
    ORDER BY h.to_date < CURDATE(), CASE WHEN h.to_date < CURDATE() THEN NULL ELSE h.from_date END, h.from_date DESC")->fetchAll(PDO::FETCH_ASSOC);
$today = date('Y-m-d');
$days = fn(array $h) => (int)round((strtotime($h['to_date']) - strtotime($h['from_date'])) / 86400) + 1;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title>Holidays - <?= ci_e(edu_site_name()) ?></title>
<?php admin_shell_head(); ci_head(); ?>
<style>
.hd-grid{display:grid;grid-template-columns:360px minmax(0,1fr);gap:16px;align-items:start}.hd-grid>*{min-width:0}
@media (max-width:1000px){.hd-grid{grid-template-columns:1fr}}
.hd-form .ci-f{margin-bottom:12px}
.hd-for{display:grid;grid-template-columns:repeat(3,1fr);border:1px solid #d1d5db;background:#fff}
.hd-for label{position:relative;display:flex;align-items:center;justify-content:center;gap:6px;min-height:40px;padding:6px 8px;margin:0;border-left:1px solid #d1d5db;cursor:pointer;font-size:13px;font-weight:600;color:#374151;text-align:center;line-height:1.2}
.hd-for label:first-child{border-left:0}.hd-for label i{font-size:12px;color:#9ca3af}
.hd-for input{position:absolute;opacity:0;width:1px;height:1px;margin:0;pointer-events:none}
.hd-for label:has(input:checked){background:var(--ci-acc,#4a3369);color:#fff}.hd-for label:has(input:checked) i{color:#fff}
.hd-for label:has(input:focus-visible){outline:2px solid var(--ci-acc,#4a3369);outline-offset:2px}
.hd-form .ci-btn[type=submit]{width:100%;justify-content:center}
.hd-st{display:inline-block;padding:2px 8px;font-size:12px;font-weight:700}.hd-st.now{background:#ede9fe;color:#5b21b6}.hd-st.next{background:#f0fdf4;color:#15803d}.hd-st.past{background:#f3f4f6;color:#6b7280}
</style>
</head>
<body>
<?php admin_shell_open('Holidays', 'No class on these days — nobody is marked absent', '<div class="ci-top-actions"><a class="ci-btn ci-ghost" href="/admin/ci-attendance.php"><i class="fas fa-clipboard-user"></i> Attendance</a></div>'); ?>
<div class="ci">
<?= admin_flash() ?>
<div class="hd-grid">
  <form class="ci-card hd-form" method="post" data-ds="Add holiday"><?= csrf_field() ?><input type="hidden" name="action" value="add">
    <div class="ci-card-h"><h3>Add a holiday</h3></div><div class="ci-card-b">
      <div class="ci-f"><label>Holiday name <span class="ci-req">*</span></label><input name="title" required maxlength="150" placeholder="e.g. Holi, Summer vacation"></div>
      <div class="ci-grid2" style="gap:12px"><div class="ci-f"><label>First day <span class="ci-req">*</span></label><input type="date" name="from_date" required value="<?= $today ?>"></div>
        <div class="ci-f"><label>Last day</label><input type="date" name="to_date" value="<?= $today ?>"></div></div>
      <div class="ci-f"><label>For</label><div class="hd-for">
        <label><input type="radio" name="for" value="all" checked><i class="fas fa-layer-group"></i> All</label>
        <label><input type="radio" name="for" value="course"><i class="fas fa-book-open"></i> Course</label>
        <label><input type="radio" name="for" value="batch"><i class="fas fa-users"></i> Batch</label></div></div>
      <div class="ci-f" data-for="course" hidden><label>Course</label><select name="course_id"><option value="">Choose course…</option><?php foreach ($courses as $id => $n): ?><option value="<?= (int)$id ?>"><?= ci_e($n) ?></option><?php endforeach; ?></select></div>
      <div class="ci-f" data-for="batch" hidden><label>Batch</label><select name="batch_id"><option value="">Choose batch…</option><?php foreach ($batches as $b): ?><option value="<?= (int)$b['id'] ?>"><?= ci_e($b['name'] . ' · ' . $b['course_name']) ?></option><?php endforeach; ?></select></div>
      <button class="ci-btn" type="submit"><?= ci_svg('check') ?> Save holiday</button>
    </div></form>
  <div class="ci-card" data-ds="Holidays"><div class="ci-card-h"><h3>Holidays <span class="ci-muted">(<?= count($list) ?>)</span></h3></div><div class="ci-card-b ci-p0 ci-table-wrap">
    <table class="ci-table" data-sheet="off"><thead><tr><th>Holiday</th><th>Dates</th><th>Days</th><th>For</th><th>When</th><th></th></tr></thead><tbody>
    <?php if (!$list): ?><tr><td colspan="6" class="ci-empty">No holidays yet.</td></tr><?php endif; ?>
    <?php foreach ($list as $h): $st = $h['to_date'] < $today ? ['past', 'Over'] : ($h['from_date'] <= $today ? ['now', 'Today'] : ['next', 'Coming']); ?>
      <tr><td><b><?= ci_e($h['title']) ?></b></td>
        <td class="ci-nw"><?= ci_date($h['from_date']) ?><?= $h['to_date'] !== $h['from_date'] ? ' – ' . ci_date($h['to_date']) : '' ?></td><td><?= $days($h) ?></td>
        <td><?= $h['batch_id'] ? 'Batch ' . ci_e($h['batch_name'] ?? '#' . $h['batch_id']) : ($h['course_id'] ? ci_e($h['course_name'] ?? 'Course #' . $h['course_id']) : 'All courses &amp; batches') ?></td>
        <td><span class="hd-st <?= $st[0] ?>"><?= $st[1] ?></span></td>
        <td><form method="post" data-del><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$h['id'] ?>"><button class="ci-ico" type="submit" aria-label="Remove <?= ci_e($h['title']) ?>"><?= ci_svg('trash') ?></button></form></td></tr>
    <?php endforeach; ?>
    </tbody></table></div></div>
</div>
</div>
<?php admin_shell_close(); ?>
<script>
(function () {
  var f = document.querySelector('.hd-form');
  function sync() { var v = f.querySelector('[name=for]:checked').value; f.querySelectorAll('[data-for]').forEach(function (d) { d.hidden = d.dataset.for !== v; d.querySelector('select').required = d.dataset.for === v; }); }
  f.addEventListener('change', function (e) { if (e.target.name === 'for') sync(); if (e.target.name === 'from_date' && f.to_date.value < e.target.value) f.to_date.value = e.target.value; });
  sync();
  document.querySelectorAll('form[data-del]').forEach(function (d) { d.addEventListener('submit', function (e) {
    if (d._ok) return; e.preventDefault();
    var go = function (y) { if (y) { d._ok = true; d.submit(); } };
    if (window.EduDialog) EduDialog.confirm('Remove this holiday? Those days become normal class days again.', { title: 'Remove holiday', ok: 'Remove', danger: true }).then(go); else go(confirm('Remove this holiday?'));
  }); });
})();
</script>
<script src="/assets/js/edu-dialog.js?v=<?= @filemtime(ROOT_PATH . '/assets/js/edu-dialog.js') ?: 1 ?>" defer></script>
</body>
</html>
