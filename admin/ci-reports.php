<?php
// Reports — one place to see any report on the page and download it as a PDF (same plain business layout as the
// traffic report): one student, a filtered list of students, any course, batch (active or completed) or class.
require_once __DIR__ . '/components/guard.php';
require_once INCLUDES_PATH . '/institute.php';
$me = ci_page_start($pdo);
$L = ci_lists($pdo);
$students = $pdo->query("SELECT s.id, s.reg_no, s.name, s.status, c.name AS class_name FROM ci_students s LEFT JOIN ci_classes c ON c.id = s.class_id ORDER BY s.name")->fetchAll(PDO::FETCH_ASSOC);
$courses = $pdo->query("SELECT id, name, status FROM ci_courses ORDER BY status = 'active' DESC, name")->fetchAll(PDO::FETCH_ASSOC);
$batches = $pdo->query("SELECT b.id, b.name, b.status, c.name AS course_name, se.name AS session_name FROM ci_batches b LEFT JOIN ci_courses c ON c.id = b.course_id LEFT JOIN ci_sessions se ON se.id = b.session_id ORDER BY b.status = 'active' DESC, c.name, b.name")->fetchAll(PDO::FETCH_ASSOC);
$tag = fn(string $st) => $st === 'active' ? '' : ' — ' . ucfirst($st);
$pre = ['type' => (string)($_GET['type'] ?? 'students'), 'id' => (int)($_GET['id'] ?? 0)];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title>Reports - <?= ci_e(edu_site_name()) ?></title>
<?php admin_shell_head(); ci_head(); ci_report_assets(); ?>
<style>
.rp-pick{display:grid;grid-template-columns:220px 1fr;gap:16px;align-items:start}
@media (max-width:820px){.rp-pick{grid-template-columns:1fr}}
.rp-types{display:flex;flex-direction:column;border:1px solid var(--ci-b)}
.rp-types button{display:flex;align-items:center;gap:10px;padding:11px 14px;border:0;border-bottom:1px solid var(--ci-b);background:#fff;text-align:left;font:600 13.5px inherit;font-family:inherit;color:#374151;cursor:pointer}
.rp-types button:last-child{border-bottom:0}
.rp-types button.on{background:var(--ci-acc);color:#fff}
.rp-types button:not(.on):hover{background:#faf8fd;color:var(--ci-acc)}
.rp-pane[hidden]{display:none}
.rp-go{display:flex;gap:8px;margin-top:14px;flex-wrap:wrap}
.rp-out{margin-top:16px}
.rp-out:empty{display:none}
.rp-doc{background:#fff;border:1px solid #9ca3af;padding:26px 28px;max-width:1100px;margin:0 auto;color:#2d2d2d;font-size:13px}
.rp-top{display:flex;justify-content:space-between;gap:20px;padding-bottom:12px;border-bottom:2.5px solid #141414;flex-wrap:wrap}
.rp-top p{margin:6px 0 0;color:#6e6e6e;font-size:12px}
.rp-brand{position:relative;isolation:isolate;display:inline-flex;align-items:center;gap:10px;padding:6px 56px 6px 12px}
.rp-brand::before{content:"";position:absolute;inset:0 12px 0 0;z-index:-1;background:rgba(240,174,113,.43);clip-path:polygon(0 0,100% 0,97% 45%,100% 100%,0 100%)}
.rp-brand::after{content:"";position:absolute;bottom:0;right:6px;width:40px;height:55%;z-index:-1;background:#f02801;clip-path:polygon(50% 0,0 100%,25% 100%)}
.rp-brand img{width:45px;height:52px;display:block}
.rp-brand b{display:block;font-family:Poppins,Roboto,Arial,sans-serif;font-size:21px;color:#9e1b32}
.rp-brand span{display:block;font-size:13px;color:#1a1a1a;margin-top:2px}
.rp-meta{text-align:right}.rp-meta h2{margin:0 0 6px;font-size:19px;letter-spacing:.04em;color:#141414}
.rp-meta table{margin-left:auto;font-size:12px;border-collapse:collapse}.rp-meta td{padding:1px 0 1px 10px}.rp-meta td:first-child{color:#6e6e6e}.rp-meta td:last-child{font-weight:700;color:#141414}
.rp-boxes{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin:16px 0}
@media (max-width:700px){.rp-boxes{grid-template-columns:1fr}}
.rp-box{border:1px solid #969696}.rp-box h3{margin:0;padding:6px 10px;background:#ececec;border-bottom:1px solid #969696;font-size:11.5px;color:#141414}
.rp-box table{width:100%;border-collapse:collapse;font-size:12.5px}.rp-box td{padding:4px 10px;vertical-align:top}.rp-box td:first-child{color:#6e6e6e;width:40%}.rp-box td:last-child{font-weight:700;color:#141414}
.rp-sec{margin-top:18px}
.rp-st{display:flex;justify-content:space-between;align-items:baseline;border-bottom:1.5px solid #141414;padding-bottom:4px;margin-bottom:8px}
.rp-st b{font-size:12.5px;letter-spacing:.05em;text-transform:uppercase;color:#141414}.rp-st span{font-size:12px;color:#6e6e6e}
.rp-tw{overflow-x:auto}
.rp-t{width:100%;border-collapse:collapse;font-size:12.5px;border:1px solid #969696}
.rp-t th{background:#ececec;border:1px solid #969696;padding:6px 8px;text-align:left;white-space:nowrap;color:#141414}
.rp-t td{border:1px solid #cdcdcd;padding:5px 8px}
.rp-t tbody tr:nth-child(even) td{background:#f8f8f8}
.rp-t tfoot td{background:#ececec;font-weight:700;border:1px solid #969696;color:#141414}
.rp-t .r{text-align:right;white-space:nowrap}
.rp-t tr.rp-left td{font-weight:600}
.rp-empty{color:#6e6e6e;font-style:italic;margin:6px 0}
.rp-notes{margin:0;padding:10px 10px 10px 30px;border:1px solid #969696;line-height:1.6}
</style>
</head>
<body>
<?php admin_shell_open('Reports', 'See any report here and download it as a PDF'); ?>
<div class="ci">
<div class="ci-card"><div class="ci-card-b"><div class="rp-pick">
  <div class="rp-types" role="tablist">
    <?php foreach (['students' => ['fa-users', 'Students (filtered)'], 'student' => ['fa-user', 'One student'], 'course' => ['fa-book-open', 'Course'], 'batch' => ['fa-layer-group', 'Batch'], 'class' => ['fa-school', 'Class']] as $k => [$ic, $l]): ?>
    <button type="button" data-type="<?= $k ?>" class="<?= $pre['type'] === $k ? 'on' : '' ?>"><i class="fas <?= $ic ?>"></i> <?= $l ?></button>
    <?php endforeach; ?>
  </div>
  <div>
    <form class="rp-pane ci-filters" data-pane="students" onsubmit="return false">
      <div class="ci-f"><label>Status</label><select name="status"><?php foreach (['active' => 'Active', 'left' => 'Left', 'completed' => 'Completed', 'all' => 'All'] as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select></div>
      <div class="ci-f"><label>Class</label><select name="class"><option value="0">All classes</option><?php foreach ($L['classes'] as $id => $n): ?><option value="<?= $id ?>"><?= ci_e($n) ?></option><?php endforeach; ?></select></div>
      <div class="ci-f"><label>Course</label><select name="course"><option value="0">All courses</option><?php foreach ($courses as $c): ?><option value="<?= (int)$c['id'] ?>"><?= ci_e($c['name'] . $tag($c['status'])) ?></option><?php endforeach; ?></select></div>
      <div class="ci-f"><label>Batch</label><select name="batch"><option value="0">All batches</option><?php foreach ($batches as $b): ?><option value="<?= (int)$b['id'] ?>"><?= ci_e($b['name'] . ' · ' . $b['course_name'] . $tag($b['status'])) ?></option><?php endforeach; ?></select></div>
      <div class="ci-f"><label>Session</label><select name="session"><option value="0">All sessions</option><?php foreach ($L['sessions'] as $id => $n): ?><option value="<?= $id ?>"><?= ci_e($n) ?></option><?php endforeach; ?></select></div>
      <div class="ci-f"><label>Fee</label><select name="fee"><option value="">Any</option><option value="due">Has due</option><option value="overdue">Due date passed</option><option value="paid">Fully paid</option></select></div>
      <div class="ci-f"><label>Admitted from</label><input type="date" name="from"></div>
      <div class="ci-f"><label>Admitted to</label><input type="date" name="to"></div>
    </form>
    <div class="rp-pane" data-pane="student" hidden><div class="ci-f"><label>Student (any status)</label><select data-id><option value=""></option><?php foreach ($students as $s): ?><option value="<?= (int)$s['id'] ?>" <?= $pre['type'] === 'student' && $pre['id'] === (int)$s['id'] ? 'selected' : '' ?>><?= ci_e($s['name'] . ' (' . $s['reg_no'] . ')' . ($s['class_name'] ? ' · ' . $s['class_name'] : '') . $tag($s['status'])) ?></option><?php endforeach; ?></select></div></div>
    <div class="rp-pane" data-pane="course" hidden><div class="ci-f"><label>Course (active or completed)</label><select data-id><option value=""></option><?php foreach ($courses as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $pre['type'] === 'course' && $pre['id'] === (int)$c['id'] ? 'selected' : '' ?>><?= ci_e($c['name'] . $tag($c['status'])) ?></option><?php endforeach; ?></select></div></div>
    <div class="rp-pane" data-pane="batch" hidden><div class="ci-f"><label>Batch (active or completed)</label><select data-id><option value=""></option><?php foreach ($batches as $b): ?><option value="<?= (int)$b['id'] ?>" <?= $pre['type'] === 'batch' && $pre['id'] === (int)$b['id'] ? 'selected' : '' ?>><?= ci_e($b['name'] . ' · ' . $b['course_name'] . ($b['session_name'] ? ' (' . $b['session_name'] . ')' : '') . $tag($b['status'])) ?></option><?php endforeach; ?></select></div></div>
    <div class="rp-pane" data-pane="class" hidden><div class="ci-f"><label>Class</label><select data-id><option value=""></option><?php foreach ($L['classes'] as $id => $n): ?><option value="<?= $id ?>" <?= $pre['type'] === 'class' && $pre['id'] === $id ? 'selected' : '' ?>><?= ci_e($n) ?></option><?php endforeach; ?></select></div></div>
    <div class="rp-go"><button type="button" class="ci-btn" id="rpView"><i class="fas fa-eye"></i> View report</button><button type="button" class="ci-btn ci-ghost" id="rpPdf"><i class="fas fa-file-pdf"></i> Download PDF</button><span class="ci-muted" id="rpMsg" style="align-self:center"></span></div>
  </div>
</div></div></div>
<div class="rp-out" id="rpOut"></div>
</div>
<?php admin_shell_close(); ?>
<script>
(function () {
  var type = <?= json_encode(in_array($pre['type'], ['students', 'student', 'course', 'batch', 'class'], true) ? $pre['type'] : 'students') ?>, last = null, lastKey = '';
  var out = document.getElementById('rpOut'), msg = document.getElementById('rpMsg');
  function show(t) {
    type = t;
    document.querySelectorAll('.rp-types button').forEach(function (b) { b.classList.toggle('on', b.dataset.type === t); });
    document.querySelectorAll('.rp-pane').forEach(function (p) { p.hidden = p.dataset.pane !== t; });
    out.innerHTML = ''; last = null;
  }
  document.querySelectorAll('.rp-types button').forEach(function (b) { b.addEventListener('click', function () { show(b.dataset.type); }); });
  function url() {
    if (type === 'students') {
      var f = document.querySelector('.rp-pane[data-pane=students]'), q = new URLSearchParams();
      [].forEach.call(f.elements, function (el) { if (el.name && el.value !== '' && el.value !== '0') q.set(el.name, el.value); });
      q.set('export', 'json');
      return '/admin/students.php?' + q.toString();
    }
    var id = document.querySelector('.rp-pane[data-pane=' + type + '] [data-id]').value;
    return id ? '/admin/ci-report.php?type=' + type + '&id=' + encodeURIComponent(id) : '';
  }
  function load(then) {
    var u = url();
    if (!u) { msg.textContent = 'Choose a ' + type + ' first.'; return; }
    if (last && lastKey === u) { then(last); return; }
    msg.textContent = 'Loading…';
    fetch(u, { credentials: 'same-origin' }).then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
      .then(function (d) { if (d.error) throw new Error(d.error); last = d.kind === 'students' ? window.ciReportFromStudents(d) : d; lastKey = u; msg.textContent = ''; then(last); })
      .catch(function (e) { msg.textContent = 'Could not load the report: ' + e.message; });
  }
  document.getElementById('rpView').addEventListener('click', function () { load(function (d) { out.innerHTML = window.ciReportHtml(d); out.scrollIntoView({ behavior: 'smooth', block: 'start' }); }); });
  document.getElementById('rpPdf').addEventListener('click', function () {
    if (!window.jspdf || !window.jspdf.jsPDF) { msg.textContent = 'The PDF tool is still loading, try again in a moment.'; return; }
    load(function (d) { window.ciReportPdf(d); if (!out.innerHTML) out.innerHTML = window.ciReportHtml(d); });
  });
  document.addEventListener('change', function (e) { if (e.target.closest('.rp-pane')) { last = null; } });
  show(type);
  if (<?= $pre['id'] ? 'true' : 'false' ?>) document.addEventListener('DOMContentLoaded', function () { document.getElementById('rpView').click(); }); // the report scripts load deferred
})();
</script>
</body>
</html>
