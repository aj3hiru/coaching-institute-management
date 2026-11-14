<?php
// Reports — one place to see any report on the page and download it as a PDF (same plain business layout as the
// traffic report), in two boxes: INSTITUTE (fee collection, students, one student, course, batch, class) and ONLINE
// (online sales, online students, online courses, one online course with every lecture / PDF / MCQ, PDF notes).
require_once __DIR__ . '/components/guard.php';
require_once INCLUDES_PATH . '/institute.php';
require_once INCLUDES_PATH . '/shop.php';
$me = admin_guard(['admin', 'editor', 'author', 'teacher', 'accountant']);
$ciOK = ci_can($me);                 // institute reports
$storeOK = sh_perm($me, 'orders');   // online sales + online students
$prodOK = $storeOK || sh_perm($me, 'products'); // online courses / notes reports
if (!$ciOK && !$prodOK) { http_response_code(403); exit('Access Denied'); }
ci_tables($pdo); sh_tables($pdo);
$storeItems = $prodOK ? $pdo->query("SELECT id, title FROM sh_products ORDER BY title")->fetchAll(PDO::FETCH_KEY_PAIR) : [];
$storeProds = $prodOK ? $pdo->query("SELECT id, title, type, status FROM sh_products ORDER BY type, title")->fetchAll(PDO::FETCH_ASSOC) : [];
$storeCats = $prodOK ? $pdo->query("SELECT id, name FROM sh_categories ORDER BY sort, name")->fetchAll(PDO::FETCH_KEY_PAIR) : [];
$L = ci_lists($pdo);
$students = $pdo->query("SELECT s.id, s.reg_no, s.name, s.status, c.name AS class_name FROM ci_students s LEFT JOIN ci_classes c ON c.id = s.class_id ORDER BY s.name")->fetchAll(PDO::FETCH_ASSOC);
$courses = $pdo->query("SELECT id, name, status FROM ci_courses ORDER BY status = 'active' DESC, name")->fetchAll(PDO::FETCH_ASSOC);
$batches = $pdo->query("SELECT b.id, b.name, b.status, c.name AS course_name, se.name AS session_name FROM ci_batches b LEFT JOIN ci_courses c ON c.id = b.course_id LEFT JOIN ci_sessions se ON se.id = b.session_id ORDER BY b.status = 'active' DESC, c.name, b.name")->fetchAll(PDO::FETCH_ASSOC);
// attendance / ID cards: only the institute's (offline) side — courses that have batches, batches, non-online students
$instCourses = array_values(array_filter($courses, fn($c) => (int)$pdo->query("SELECT COUNT(*) FROM ci_batches WHERE course_id = " . (int)$c['id'])->fetchColumn() > 0));
$instStudents = $pdo->query("SELECT s.id, s.reg_no, s.name FROM ci_students s WHERE s.stype <> 'online' AND s.status = 'active' ORDER BY s.name")->fetchAll(PDO::FETCH_ASSOC);
$tag = fn(string $st) => $st === 'active' ? '' : ' — ' . ucfirst($st);
$canFees = ci_perm($me, 'fees');
$types = ($canFees ? ['fees'] : []) + [];
if ($ciOK) array_push($types, 'students', 'student', 'course', 'batch', 'class', 'attendance', 'idcards');
if ($storeOK) array_push($types, 'store', 'ostudents');
if ($prodOK) array_push($types, 'ocourses', 'ocourse', 'onotes');
$GROUPS = ['institute' => ['Institute', ['fees' => ['fa-indian-rupee-sign', 'Fee collection'], 'students' => ['fa-users', 'Students (filtered)'], 'student' => ['fa-user', 'One student'], 'course' => ['fa-book-open', 'Course'], 'batch' => ['fa-layer-group', 'Batch'], 'class' => ['fa-school', 'Class'], 'attendance' => ['fa-clipboard-user', 'Attendance'], 'idcards' => ['fa-id-card', 'ID Cards']]],
    'online' => ['Online', ['store' => ['fa-store', 'Online sales'], 'ostudents' => ['fa-user-graduate', 'Online students'], 'ocourses' => ['fa-photo-film', 'Online courses'], 'ocourse' => ['fa-film', 'One online course'], 'onotes' => ['fa-file-pdf', 'PDF notes']]]];
$periodOpts = fn(string $sel, bool $all = true) => implode('', array_map(fn($k, $l) => '<option value="' . $k . '"' . ($k === $sel ? ' selected' : '') . '>' . $l . '</option>', array_keys($o = ['today' => 'Today', 'yesterday' => 'Yesterday', 'week' => 'This week', 'month' => 'This month', 'lastmonth' => 'Last month', 'year' => 'This year'] + ($all ? ['all' => 'All time'] : []) + ['custom' => 'Choose dates']), $o));
$dates = '<div class="ci-f rp-cd" hidden><label>From</label><input type="date" name="from" value="' . date('Y-m-01') . '" max="' . date('Y-m-d') . '"></div><div class="ci-f rp-cd" hidden><label>To</label><input type="date" name="to" value="' . date('Y-m-d') . '" max="' . date('Y-m-d') . '"></div>';
$pre = ['type' => in_array((string)($_GET['type'] ?? ''), $types, true) ? (string)$_GET['type'] : $types[0], 'id' => (int)($_GET['id'] ?? 0)];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title>Reports - <?= ci_e(edu_site_name()) ?></title>
<?php admin_shell_head(); ci_head(); ci_report_assets(); ?>
<style>
.rp-pick{display:grid;grid-template-columns:250px 1fr;gap:16px;align-items:start}
@media (max-width:820px){.rp-pick{grid-template-columns:1fr}}
.rp-types{display:flex;flex-direction:column;border:1px solid var(--ci-b)}
.rp-types button{display:flex;align-items:center;gap:10px;padding:11px 14px;border:0;border-bottom:1px solid var(--ci-b);background:#fff;text-align:left;font:600 13.5px inherit;font-family:inherit;color:#374151;cursor:pointer}
.rp-types button:last-child{border-bottom:0}
.rp-types button.on{background:var(--ci-acc);color:#fff}
.rp-types button:not(.on):hover{background:#faf8fd;color:var(--ci-acc)}
.rp-pane[hidden]{display:none}
/* the report lists in two boxes, each with its name on the border (light, in the page's own colours); the filters stay on the right */
.rp-side{display:flex;flex-direction:column;gap:22px;padding-top:10px}
.rp-group{position:relative;border:1px solid var(--ci-b,#e5e7eb);padding:18px 10px 10px;background:#fff}
.rp-group > .rp-glabel{position:absolute;top:-10px;left:10px;display:inline-flex;align-items:center;gap:6px;padding:0 8px;background:#fff;color:var(--ci-acc,#4a3369);font-size:11.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;line-height:1.6}
.rp-two{display:grid;grid-template-columns:minmax(0,2fr) minmax(0,1fr);gap:12px}
@media (max-width:700px){.rp-two{grid-template-columns:1fr}}
.rp-go{display:flex;gap:8px;margin-top:14px;flex-wrap:wrap}
.rp-out{margin-top:16px;overflow-x:auto}
.rp-pdf:disabled{opacity:.45;cursor:not-allowed;pointer-events:none}
/* the preview sits in the right column: it never widens the page; wide tables scroll inside, cells stay on one line */
.rp-main{min-width:0}
.rp-main .rp-doc{max-width:none;padding:18px 18px;font-size:12.5px}
.rp-main .rp-tw{overflow-x:auto;-webkit-overflow-scrolling:touch}
.rp-main .rp-t th,.rp-main .rp-t td{white-space:nowrap}
@media (max-width:820px){.rp-main .rp-doc{padding:12px}.rp-opts{padding:10px}}
.rp-opts{margin-top:14px;border:1px solid var(--ci-b,#e5e7eb);background:#fafafa;padding:12px 14px}
.rp-opts-h{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin:0;cursor:pointer;list-style:none;padding-right:26px;position:relative}
.rp-opts-h::-webkit-details-marker{display:none}
.rp-opts-h::after{content:"";position:absolute;right:4px;top:50%;width:8px;height:8px;border-right:2px solid #6b7280;border-bottom:2px solid #6b7280;transform:translateY(-70%) rotate(45deg);transition:transform .2s}
.rp-opts[open] > .rp-opts-h{margin-bottom:8px}.rp-opts[open] > .rp-opts-h::after{transform:translateY(-30%) rotate(-135deg)}
.rp-opts-h b{font-size:13.5px}.rp-opts-h span{font-size:12px;color:#6b7280}
.rp-opts-g{display:flex;flex-wrap:wrap;gap:6px 14px;padding:8px 0;border-top:1px dashed #e5e7eb}
.rp-opts-g:first-of-type{border-top:0}
.rp-opts-g > em{flex-basis:100%;font-style:normal;font-size:11.5px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:#6b7280}
.rp-opts label{display:inline-flex;align-items:center;gap:6px;font-size:13px;color:#374151;cursor:pointer;user-select:none}
.rp-opts label.rp-sec-l{font-weight:700;flex-basis:100%}
.rp-opts .rp-cols{display:flex;flex-wrap:wrap;gap:4px 12px;padding-left:22px;flex-basis:100%}
.rp-opts .rp-cols.off{opacity:.45;pointer-events:none}
.rp-opts input{width:15px;height:15px;accent-color:var(--ci-acc,#4a3369)}
.rp-sign{display:flex;justify-content:flex-end;margin-top:26px}.rp-sign span{display:inline-block;min-width:170px;padding-top:6px;border-top:1px solid #141414;text-align:center;font-size:12px;color:#6e6e6e}
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
<div class="ci-card" data-ds="Reports"><div class="ci-card-b"><div class="rp-pick">
  <div class="rp-side">
<?php foreach ($GROUPS as $gk => [$glabel, $gitems]): $gi = array_intersect_key($gitems, array_flip($types)); if (!$gi) continue; ?>
    <div class="rp-group rp-<?= $gk ?>" data-group="<?= $gk ?>" data-ds-section data-ds="<?= $glabel ?> reports"><span class="rp-glabel"><i class="fas <?= $gk === 'online' ? 'fa-globe' : 'fa-school' ?>"></i> <?= $glabel ?></span>
      <div class="rp-types" role="tablist"><?php foreach ($gi as $k => [$ic, $l]): ?><button type="button" data-type="<?= $k ?>" data-ds-item="<?= ci_e($l) ?>" class="<?= $pre['type'] === $k ? 'on' : '' ?>"><i class="fas <?= $ic ?>"></i> <?= $l ?></button><?php endforeach; ?></div>
    </div>
<?php endforeach; ?>
  </div>
  <div class="rp-main">

    <?php if ($canFees): ?><form class="rp-pane ci-filters" data-pane="fees" onsubmit="return false" hidden>
      <div class="ci-f"><label>Period</label><select name="period"><?php foreach (['today' => 'Today', 'yesterday' => 'Yesterday', 'week' => 'This week', 'month' => 'This month', 'lastmonth' => 'Last month', 'year' => 'This year', 'custom' => 'Choose dates'] as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select></div>
      <div class="ci-f rp-custom" hidden><label>From</label><input type="date" name="from" value="<?= date('Y-m-01') ?>" max="<?= date('Y-m-d') ?>"></div>
      <div class="ci-f rp-custom" hidden><label>To</label><input type="date" name="to" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>"></div>
      <div class="ci-f"><label>Course</label><select name="course"><option value="0">All courses</option><?php foreach ($courses as $c): ?><option value="<?= (int)$c['id'] ?>"><?= ci_e($c['name'] . $tag($c['status'])) ?></option><?php endforeach; ?></select></div>
      <div class="ci-f"><label>Payment mode</label><select name="method"><option value="">All modes</option><?php foreach (CI_METHODS as $k => $l): ?><option value="<?= ci_e($k) ?>"><?= ci_e($l) ?></option><?php endforeach; ?></select></div>
    </form><?php endif; ?>
    <?php if ($ciOK): ?>
    <form class="rp-pane ci-filters" data-pane="students" onsubmit="return false">
      <div class="ci-f"><label>Student type</label><select name="type"><option value="">All students</option><option value="institute">Institute only</option><option value="online">Online only</option><option value="both">Both (institute + online)</option></select></div>
      <div class="ci-f"><label>Student status</label><select name="status"><?php foreach (['active' => 'Active', 'left' => 'Left', 'completed' => 'Completed', 'all' => 'All'] as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select></div>
      <div class="ci-f"><label>Class</label><select name="class"><option value="0">All classes</option><?php foreach ($L['classes'] as $id => $n): ?><option value="<?= $id ?>"><?= ci_e($n) ?></option><?php endforeach; ?></select></div>
      <div class="ci-f"><label>Course</label><select name="course"><option value="0">All courses</option><?php foreach ($courses as $c): ?><option value="<?= (int)$c['id'] ?>"><?= ci_e($c['name'] . $tag($c['status'])) ?></option><?php endforeach; ?></select></div>
      <div class="ci-f"><label>Batch</label><select name="batch"><option value="0">All batches</option><?php foreach ($batches as $b): ?><option value="<?= (int)$b['id'] ?>"><?= ci_e($b['name'] . ' · ' . $b['course_name'] . $tag($b['status'])) ?></option><?php endforeach; ?></select></div>
      <div class="ci-f"><label>Session</label><select name="session"><option value="0">All sessions</option><?php foreach ($L['sessions'] as $id => $n): ?><option value="<?= $id ?>"><?= ci_e($n) ?></option><?php endforeach; ?></select></div>
      <div class="ci-f"><label>Fee status</label><select name="fee"><option value="">Any</option><option value="due">Has due</option><option value="overdue">Due date passed</option><option value="paid">Fully paid</option></select></div>
      <div class="ci-f"><label>Admitted from</label><input type="date" name="from"></div>
      <div class="ci-f"><label>Admitted to</label><input type="date" name="to"></div>
    </form>
    <div class="rp-pane" data-pane="student" hidden><div class="ci-f"><label>Student (any status)</label><select data-id><option value=""></option><?php foreach ($students as $s): ?><option value="<?= (int)$s['id'] ?>" <?= $pre['type'] === 'student' && $pre['id'] === (int)$s['id'] ? 'selected' : '' ?>><?= ci_e($s['name'] . ' (' . $s['reg_no'] . ')' . ($s['class_name'] ? ' · ' . $s['class_name'] : '') . $tag($s['status'])) ?></option><?php endforeach; ?></select></div></div>
    <div class="rp-pane rp-two" data-pane="course" hidden><div class="ci-f"><label>Course (active or completed)</label><select data-id><option value="all">All courses</option><?php foreach ($courses as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $pre['type'] === 'course' && $pre['id'] === (int)$c['id'] ? 'selected' : '' ?>><?= ci_e($c['name'] . $tag($c['status'])) ?></option><?php endforeach; ?></select></div><div class="ci-f rp-all-st" hidden><label>Status</label><select data-st><option value="all">All</option><option value="active">Active</option><option value="completed">Completed</option><option value="inactive">Inactive</option></select></div></div>
    <div class="rp-pane rp-two" data-pane="batch" hidden><div class="ci-f"><label>Batch (active or completed)</label><select data-id><option value="all">All batches</option><?php foreach ($batches as $b): ?><option value="<?= (int)$b['id'] ?>" <?= $pre['type'] === 'batch' && $pre['id'] === (int)$b['id'] ? 'selected' : '' ?>><?= ci_e($b['name'] . ' · ' . $b['course_name'] . ($b['session_name'] ? ' (' . $b['session_name'] . ')' : '') . $tag($b['status'])) ?></option><?php endforeach; ?></select></div><div class="ci-f rp-all-st" hidden><label>Status</label><select data-st><option value="all">All</option><option value="active">Active</option><option value="completed">Completed</option><option value="inactive">Inactive</option></select></div></div>
    <div class="rp-pane" data-pane="class" hidden><div class="ci-f"><label>Class</label><select data-id><option value="all">All classes</option><?php foreach ($L['classes'] as $id => $n): ?><option value="<?= $id ?>" <?= $pre['type'] === 'class' && $pre['id'] === $id ? 'selected' : '' ?>><?= ci_e($n) ?></option><?php endforeach; ?></select></div></div>
    <form class="rp-pane ci-filters" data-pane="attendance" data-generic onsubmit="return false" hidden>
      <div class="ci-f"><label>Period</label><select name="period"><?php foreach (['month' => 'This month', 'lastmonth' => 'Last month', 'week' => 'This week', 'today' => 'Today', 'yesterday' => 'Yesterday', 'custom' => 'Choose dates'] as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select></div><?= $dates ?>
      <div class="ci-f"><label>Class</label><select name="class"><option value="0">All classes</option><?php foreach ($L['classes'] as $id => $n): ?><option value="<?= $id ?>"><?= ci_e($n) ?></option><?php endforeach; ?></select></div>
      <div class="ci-f"><label>Course</label><select name="course"><option value="0">All courses</option><?php foreach ($instCourses as $c): ?><option value="<?= (int)$c['id'] ?>"><?= ci_e($c['name'] . $tag($c['status'])) ?></option><?php endforeach; ?></select></div>
      <div class="ci-f"><label>Batch</label><select name="batch"><option value="0">All batches</option><?php foreach ($batches as $b): ?><option value="<?= (int)$b['id'] ?>"><?= ci_e($b['name'] . ' · ' . $b['course_name'] . $tag($b['status'])) ?></option><?php endforeach; ?></select></div>
    </form>
    <form class="rp-pane ci-filters" data-pane="idcards" onsubmit="return false" hidden>
      <div class="ci-f"><label>Class</label><select name="class"><option value="0">All classes</option><?php foreach ($L['classes'] as $id => $n): ?><option value="<?= $id ?>"><?= ci_e($n) ?></option><?php endforeach; ?></select></div>
      <div class="ci-f"><label>Course</label><select name="course"><option value="0">All courses</option><?php foreach ($instCourses as $c): if ($c['status'] !== 'active') continue; ?><option value="<?= (int)$c['id'] ?>"><?= ci_e($c['name']) ?></option><?php endforeach; ?></select></div>
      <div class="ci-f"><label>Batch</label><select name="batch"><option value="0">All batches</option><?php foreach ($batches as $b): if ($b['status'] !== 'active') continue; ?><option value="<?= (int)$b['id'] ?>"><?= ci_e($b['name'] . ' · ' . $b['course_name']) ?></option><?php endforeach; ?></select></div>
      <div class="ci-f"><label>One student</label><select name="student" data-search><option value="0">All students of the choice</option><?php foreach ($instStudents as $st): ?><option value="<?= (int)$st['id'] ?>"><?= ci_e(($st['reg_no'] ?? '') . ' · ' . $st['name']) ?></option><?php endforeach; ?></select></div>
      <p class="ci-muted" style="grid-column:1/-1;margin:0;font-size:13px"><i class="fas fa-print"></i> The cards open in a new tab, ready to print or save as PDF (9 cards on an A4 page).</p>
    </form>
    <?php endif; ?>
    <?php if ($storeOK): ?><form class="rp-pane ci-filters" data-pane="store" data-generic onsubmit="return false" hidden>
      <div class="ci-f"><label>Period</label><select name="period"><?php foreach (['today' => 'Today', 'yesterday' => 'Yesterday', 'week' => 'This week', 'month' => 'This month', 'lastmonth' => 'Last month', 'year' => 'This year', 'all' => 'All time', 'custom' => 'Choose dates'] as $k => $l): ?><option value="<?= $k ?>" <?= $k === 'month' ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
      <div class="ci-f rp-custom2" hidden><label>From</label><input type="date" name="from" value="<?= date('Y-m-01') ?>" max="<?= date('Y-m-d') ?>"></div>
      <div class="ci-f rp-custom2" hidden><label>To</label><input type="date" name="to" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>"></div>
      <div class="ci-f"><label>Course / notes</label><select name="product"><option value="0">All courses &amp; notes</option><?php foreach ($storeItems as $k => $l): ?><option value="<?= (int)$k ?>"><?= ci_e($l) ?></option><?php endforeach; ?></select></div>
    </form><?php endif; ?>
    <?php if ($storeOK): ?><form class="rp-pane ci-filters" data-pane="ostudents" data-generic onsubmit="return false" hidden>
      <div class="ci-f"><label>Joined</label><select name="period"><?= $periodOpts('all') ?></select></div><?= $dates ?>
      <div class="ci-f"><label>Show</label><select name="show"><option value="all">All accounts</option><option value="buyers">Bought something</option><option value="free">Joined a free course</option><option value="none">Own nothing yet</option><option value="linked">Also institute students</option></select></div>
    </form><?php endif; ?>
    <?php if ($prodOK): ?><form class="rp-pane ci-filters" data-pane="ocourses" data-generic onsubmit="return false" hidden>
      <div class="ci-f"><label>Type</label><select name="kind"><option value="">Courses &amp; notes</option><option value="course">Video courses</option><option value="notes">PDF notes</option></select></div>
      <div class="ci-f"><label>Price</label><select name="price"><option value="">Paid &amp; free</option><option value="paid">Paid only</option><option value="free">Free only</option></select></div>
      <div class="ci-f"><label>Category</label><select name="category"><option value="0">All categories</option><?php foreach ($storeCats as $k => $l): ?><option value="<?= (int)$k ?>"><?= ci_e($l) ?></option><?php endforeach; ?></select></div>
      <div class="ci-f"><label>Show</label><select name="status"><option value="">Published only</option><option value="all">Published &amp; drafts</option></select></div>
      <div class="ci-f"><label>Sales in</label><select name="period"><?= $periodOpts('all') ?></select></div><?= $dates ?>
    </form>
    <div class="rp-pane" data-pane="ocourse" hidden><div class="ci-f"><label>Online course / PDF notes</label><select data-id><option value=""></option><?php foreach ($storeProds as $sp): ?><option value="<?= (int)$sp['id'] ?>"><?= ci_e($sp['title'] . ' — ' . ($sp['type'] === 'notes' ? 'PDF notes' : 'Video course') . ($sp['status'] !== 'published' ? ' (' . $sp['status'] . ')' : '')) ?></option><?php endforeach; ?></select></div></div>
    <form class="rp-pane ci-filters" data-pane="onotes" data-generic onsubmit="return false" hidden>
      <div class="ci-f"><label>Price</label><select name="price"><option value="">Paid &amp; free</option><option value="paid">Paid only</option><option value="free">Free only</option></select></div>
      <div class="ci-f"><label>Category</label><select name="category"><option value="0">All categories</option><?php foreach ($storeCats as $k => $l): ?><option value="<?= (int)$k ?>"><?= ci_e($l) ?></option><?php endforeach; ?></select></div>
      <div class="ci-f"><label>Sold in</label><select name="period"><?= $periodOpts('all') ?></select></div><?= $dates ?>
    </form><?php endif; ?>
    <div class="rp-go"><button type="button" class="ci-btn rp-view"><i class="fas fa-eye"></i> View report</button><button type="button" class="ci-btn ci-ghost rp-pdf" disabled title="View the report first"><i class="fas fa-file-pdf"></i> Download PDF</button><span class="ci-muted rp-msg" style="align-self:center"></span></div>
    <?php // after View report: what goes into the PDF (the header always stays), then the preview — right under the filters ?>
    <details class="rp-opts" id="rpOpts" hidden></details>
    <div class="rp-out" id="rpOut"></div>
  </div>
</div></div></div>
</div>
<?php admin_shell_close(); ?>
<script>
(function () {
  var type = <?= json_encode($pre['type'], JSON_HEX_TAG) ?>, last = null, lastKey = '';
  var out = document.getElementById('rpOut'), msg = { set textContent(v) { var m = document.querySelector('.rp-msg'); if (m) m.textContent = v; } };
  function show(t) {
    type = t;
    document.querySelectorAll('.rp-types button').forEach(function (b) { b.classList.toggle('on', b.dataset.type === t); });
    document.querySelectorAll('.rp-pane').forEach(function (p) { p.hidden = p.dataset.pane !== t; });
    out.innerHTML = ''; last = null; reset();
    document.querySelectorAll('.rp-pdf').forEach(function (x) { x.hidden = t === 'idcards'; }); // ID cards print from their own page
    document.querySelectorAll('.rp-view').forEach(function (x) { x.innerHTML = t === 'idcards' ? '<i class="fas fa-id-card"></i> Open ID cards' : '<i class="fas fa-eye"></i> View report'; });
  }
  // ---- after View report: tick what goes into the PDF (header always); preview and download follow the ticks ----
  var opts = document.getElementById('rpOpts'), pdfBtns = document.querySelectorAll('.rp-pdf'), shown = null, sel = {};
  function reset() { shown = null; sel = {}; opts.hidden = true; opts.innerHTML = ''; pdfBtns.forEach(function (b) { b.disabled = true; b.title = 'View the report first'; }); }
  function esc(t) { return String(t == null ? '' : t).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function box(path, label, cls) { return '<label' + (cls ? ' class="' + cls + '"' : '') + '><input type="checkbox" checked data-p="' + path + '"> ' + esc(label) + '</label>'; }
  function paint() { out.innerHTML = window.ciReportHtml(window.ciReportPick(shown, sel)); }
  function buildOpts(d) {
    sel = {}; var h = '<summary class="rp-opts-h"><b><i class="fas fa-sliders-h"></i> What goes into the PDF</b><span>Closed by default — open to leave a part or a column out. The header always stays.</span></summary>';
    if ((d.boxes || []).length) h += '<div class="rp-opts-g"><em>Info boxes</em>' + d.boxes.map(function (b, i) { return box('boxes.' + i, b.head); }).join('') + '</div>';
    (d.sections || []).forEach(function (s, i) {
      h += '<div class="rp-opts-g"><em>Table</em>' + box('sec.' + i, s.title + (s.right_text ? ' (' + s.right_text + ')' : ''), 'rp-sec-l')
        + ((s.body || []).length && (s.head || []).length > 1 ? '<div class="rp-cols" data-cols="' + i + '">' + s.head.map(function (c, j) { return box('cols.' + i + '.' + j, c || 'Column ' + (j + 1)); }).join('') + '</div>' : '') + '</div>';
    });
    h += '<div class="rp-opts-g"><em>End of the report</em>' + ((d.notes || []).length ? box('notes', 'Notes') : '') + box('sign', 'Authorised Signatory') + '</div>';
    opts.innerHTML = h; opts.open = false; opts.hidden = false; // a new report: the choices start closed
  }
  opts.addEventListener('change', function (e) {
    var c = e.target; if (!c.dataset || !c.dataset.p) return;
    var p = c.dataset.p.split('.'), v = c.checked ? undefined : false;
    if (p[0] === 'notes' || p[0] === 'sign') sel[p[0]] = v;
    else if (p[0] === 'cols') { sel.cols = sel.cols || {}; sel.cols[p[1]] = sel.cols[p[1]] || {}; sel.cols[p[1]][p[2]] = v;
      // at least one column stays in a table
      var cs = opts.querySelectorAll('[data-cols="' + p[1] + '"] input'); if (![].some.call(cs, function (x) { return x.checked; })) { c.checked = true; delete sel.cols[p[1]][p[2]]; msg.textContent = 'A table keeps at least one column.'; return; } }
    else { sel[p[0]] = sel[p[0]] || {}; sel[p[0]][p[1]] = v; if (p[0] === 'sec') { var g = opts.querySelector('[data-cols="' + p[1] + '"]'); if (g) g.classList.toggle('off', !c.checked); } }
    msg.textContent = ''; paint();
  });
  document.querySelectorAll('.rp-types button').forEach(function (b) { b.addEventListener('click', function () { show(b.dataset.type); }); });
  function url() {
    if (type === 'fees') {
      var g = document.querySelector('.rp-pane[data-pane=fees]'), p = new URLSearchParams({ type: 'fees' });
      [].forEach.call(g.elements, function (el) { if (!el.name || el.value === '' || el.value === '0') return; if ((el.name === 'from' || el.name === 'to') && g.elements.period.value !== 'custom') return; p.set(el.name, el.value); });
      if (g.elements.period.value === 'custom' && !g.elements.from.value) { msg.textContent = 'Type the From date as dd/mm/yyyy.'; return ''; }
      return '/admin/ci-report.php?' + p.toString();
    }
    var gp = document.querySelector('.rp-pane[data-pane=' + type + '][data-generic]');
    if (gp) { // online sales, online students, online courses, PDF notes: every field of the form, dates only for "Choose dates"
      var sq = new URLSearchParams({ type: type });
      [].forEach.call(gp.elements, function (el) { if (!el.name || el.value === '' || el.value === '0' || el.disabled) return; if ((el.name === 'from' || el.name === 'to') && gp.elements.period.value !== 'custom') return; sq.set(el.name, el.value); });
      return '/admin/ci-report.php?' + sq.toString();
    }
    if (type === 'students') {
      var f = document.querySelector('.rp-pane[data-pane=students]'), q = new URLSearchParams();
      [].forEach.call(f.elements, function (el) { if (el.name && el.value !== '' && el.value !== '0') q.set(el.name, el.value); });
      q.set('export', 'json');
      return '/admin/students.php?' + q.toString();
    }
    var pane = document.querySelector('.rp-pane[data-pane=' + type + ']'), id = pane.querySelector('[data-id]').value, st = pane.querySelector('[data-st]');
    if (id === 'all') return '/admin/ci-report.php?type=' + type + (type === 'class' ? 'es' : (type === 'batch' ? 'es' : 's')) + (st ? '&status=' + st.value : '');
    return id ? '/admin/ci-report.php?type=' + type + '&id=' + encodeURIComponent(id) : '';
  }
  function load(then) {
    var u = url();
    if (!u) { if (type !== 'fees') msg.textContent = 'Choose ' + (type === 'ocourse' ? 'a course' : 'a ' + type) + ' first.'; return; }
    if (last && lastKey === u) { then(last); return; }
    msg.textContent = 'Loading…';
    fetch(u, { credentials: 'same-origin' }).then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
      .then(function (d) { if (d.error) throw new Error(d.error); last = d.kind === 'students' ? window.ciReportFromStudents(d) : d; lastKey = u; msg.textContent = ''; then(last); })
      .catch(function (e) { msg.textContent = 'Could not load the report: ' + e.message; });
  }
  document.querySelectorAll('.rp-view').forEach(function (b) { b.addEventListener('click', function (ev) {
    if (type === 'idcards') { ev.stopImmediatePropagation(); var f = document.querySelector('.rp-pane[data-pane=idcards]'), q = new URLSearchParams();
      [].forEach.call(f.elements, function (el) { if (el.name && el.value && el.value !== '0') q.set(el.name, el.value); });
      window.open('/admin/ci-idcards.php' + (q.toString() ? '?' + q.toString() : ''), '_blank'); return; }
  }, true); });
  document.querySelectorAll('.rp-view').forEach(function (b) { b.addEventListener('click', function () { load(function (d) {
    var fresh = shown !== d; shown = d; if (fresh) buildOpts(d); paint();
    pdfBtns.forEach(function (x) { x.disabled = false; x.title = ''; });
    (window.innerWidth < 821 ? opts : b).scrollIntoView({ behavior: 'smooth', block: 'start' });
  }); }); });
  document.querySelectorAll('.rp-pdf').forEach(function (b) { b.addEventListener('click', function () {
    if (!shown) { msg.textContent = 'View the report first.'; return; }
    if (!window.jspdf || !window.jspdf.jsPDF) { msg.textContent = 'The PDF tool is still loading, try again in a moment.'; return; }
    window.ciReportPdf(window.ciReportPick(shown, sel)); // exactly what the preview shows
  }); });
  // the Status filter shows only for "All courses / All batches"
  function syncAll() {
    document.querySelectorAll('.rp-two').forEach(function (p) { var all = p.querySelector('[data-id]').value === 'all'; p.querySelector('.rp-all-st').hidden = !all; });
    var per = document.querySelector('.rp-pane[data-pane=fees] [name=period]'), custom = !!per && per.value === 'custom';
    var sper = document.querySelector('.rp-pane[data-pane=store] [name=period]');
    document.querySelectorAll('.rp-custom2').forEach(function (c) { c.hidden = !(sper && sper.value === 'custom'); });
    document.querySelectorAll('.rp-custom').forEach(function (c) { c.hidden = !custom; });
    document.querySelectorAll('.rp-pane[data-generic]').forEach(function (f) { var on = f.elements.period && f.elements.period.value === 'custom'; f.querySelectorAll('.rp-cd').forEach(function (c) { c.hidden = !on; }); });
  }
  document.addEventListener('change', function (e) { if (e.target.closest('.rp-pane')) { last = null; out.innerHTML = ''; reset(); syncAll(); } }); // new filters: view again before downloading
  syncAll();
  show(type);
  if (<?= $pre['id'] ? 'true' : 'false' ?>) document.addEventListener('DOMContentLoaded', function () { var v = document.querySelector('.rp-view'); if (v) v.click(); }); // the report scripts load deferred
})();
</script>
</body>
</html>
