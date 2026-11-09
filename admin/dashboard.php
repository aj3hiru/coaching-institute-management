<?php
// Dashboard — the coaching institute at a glance: students, admissions, batches, fee collected and due.
// The blog's dashboard (traffic, posts) is now Blog → Blog Dashboard (/admin/blog-dashboard.php).
require_once __DIR__ . '/components/guard.php';
require_once INCLUDES_PATH . '/institute.php';
$me = admin_guard(['admin', 'editor', 'author', 'teacher', 'accountant']);
if (!ci_can($me)) {
    if (admin_blog_access()) { header('Location: /admin/blog-dashboard.php'); exit; }
    require_once INCLUDES_PATH . '/shop.php';
    if (sh_perm($me)) { header('Location: ' . (sh_perm($me, 'orders') ? '/admin/shop-orders.php' : (sh_perm($me, 'products') ? '/admin/shop-products.php' : (sh_perm($me, 'customers') ? '/admin/shop-customers.php' : '/admin/shop-doubts.php')))); exit; } // online store staff
    // no institute and no blog access: a plain welcome page (no figures)
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Dashboard</title>';
    admin_shell_head();
    echo '</head><body>';
    admin_shell_open('Dashboard', date('l, F j, Y'));
    echo '<div class="card" style="padding:1.5rem">Welcome, ' . htmlspecialchars($me['username']) . '. Use the menu on the left.</div>';
    admin_shell_close();
    echo '</body></html>';
    exit;
}
ci_tables($pdo);

$one = fn(string $sql, array $a = []) => (function () use ($pdo, $sql, $a) { $s = $pdo->prepare($sql); $s->execute($a); return $s->fetchColumn(); })();
$today = date('Y-m-d');
$month = date('Y-m-01');
$stats = [
    // institute students (Institute + Both); online-only students are counted on their own
    'active' => (int)$one("SELECT COUNT(*) FROM ci_students WHERE status = 'active' AND stype <> 'online'"),
    'new' => (int)$one("SELECT COUNT(*) FROM ci_students WHERE admission_date >= ? AND stype <> 'online'", [$month]),
    'online' => (int)$one("SELECT COUNT(*) FROM ci_students WHERE status = 'active' AND stype IN ('online','both')"),
    'batches' => (int)$one("SELECT COUNT(*) FROM ci_batches WHERE status = 'active'"),
    'courses' => (int)$one("SELECT COUNT(*) FROM ci_courses WHERE status = 'active'"),
    'today' => (float)$one("SELECT COALESCE(SUM(amount),0) FROM ci_payments WHERE paid_on = ?", [$today]),
    'month' => (float)$one("SELECT COALESCE(SUM(amount),0) FROM ci_payments WHERE paid_on >= ?", [$month]),
    'due' => (float)$one("SELECT COALESCE(SUM(" . CI_DUE_SQL . "),0) FROM ci_enrollments e"),
    'late' => (int)$one("SELECT COUNT(*) FROM ci_enrollments e WHERE e.status <> 'dropped' AND e.due_date IS NOT NULL AND e.due_date < CURDATE() AND " . CI_DUE_SQL . " > 0.004"),
];
$recentStudents = $pdo->query("SELECT s.*, (SELECT GROUP_CONCAT(c.name SEPARATOR ', ') FROM ci_enrollments e JOIN ci_courses c ON c.id = e.course_id WHERE e.student_id = s.id AND e.status <> 'dropped') AS courses
    FROM ci_students s WHERE s.stype <> 'online' ORDER BY s.id DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
$recentPays = $pdo->query("SELECT p.*, s.name, s.reg_no, s.photo, u.username FROM ci_payments p JOIN ci_students s ON s.id = p.student_id LEFT JOIN users u ON u.id = p.created_by ORDER BY p.id DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
$upcoming = $pdo->query("SELECT e.id, e.due_date, s.id AS sid, s.name, s.mobile, s.photo, c.name AS course_name, " . CI_DUE_SQL . " AS due
    FROM ci_enrollments e JOIN ci_students s ON s.id = e.student_id LEFT JOIN ci_courses c ON c.id = e.course_id
    WHERE e.status <> 'dropped' AND " . CI_DUE_SQL . " > 0.004 ORDER BY (e.due_date IS NULL), e.due_date, due DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
$batches = $pdo->query("SELECT b.id, b.name, b.timing, b.capacity, c.name AS course_name, (SELECT COUNT(*) FROM ci_enrollments e WHERE e.batch_id = b.id AND e.status = 'active') AS n
    FROM ci_batches b LEFT JOIN ci_courses c ON c.id = b.course_id WHERE b.status = 'active' ORDER BY n DESC, b.name LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
$empty = !$stats['active'] && !$stats['courses'] && !$stats['batches'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title>Dashboard - <?= ci_e(edu_site_name()) ?></title>
<?php admin_shell_head(); ci_head(); ?>
<style>.ci-bars{display:flex;align-items:flex-end;gap:12px;height:170px;padding:8px 4px 0}.ci-bars div{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;height:100%;gap:6px}.ci-bars span.v{font-size:11.5px;color:#6b7280;white-space:nowrap}.ci-bars i{display:block;width:100%;max-width:46px;background:#4a3369;border-radius:3px 3px 0 0;min-height:2px}.ci-bars small{font-size:12px;color:#6b7280}.ci-list{list-style:none;margin:0;padding:0}.ci-list li{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 16px;border-bottom:1px solid #f1f2f4}.ci-list li:last-child{border-bottom:0}.ci-list .r{text-align:right;white-space:nowrap}.ci-meter{height:6px;background:#f1f2f4;border-radius:3px;overflow:hidden;margin-top:6px;width:120px}.ci-meter i{display:block;height:100%;background:#4a3369}</style>
</head>
<body>
<?php admin_shell_open('Dashboard', date('l, F j, Y'), '<div class="ci-top-actions">'
    . (ci_perm($me, 'add_student') ? '<a class="ci-btn" href="/admin/student-edit.php"><i class="fas fa-user-plus"></i> Add Student</a>' : '')
    . (ci_perm($me, 'fees') ? '<a class="ci-btn ci-ghost" href="/admin/ci-dues.php?period=all"><i class="fas fa-indian-rupee-sign"></i> Collect Fee</a>' : '')
    . (ci_perm($me, 'batches') ? '<a class="ci-btn ci-ghost" href="/admin/ci-batches.php?new=1"><i class="fas fa-layer-group"></i> New Batch</a>' : '') . '</div>'); ?>
<div class="ci">
<?= admin_flash() ?>

<?php if ($empty): ?>
<div class="ci-card"><div class="ci-card-b"><b>Getting started</b><ol style="margin:8px 0 0 18px;padding:0;line-height:1.9">
  <li>Check the academic session and class list in <a href="/admin/ci-sessions.php">Sessions</a> and <a href="/admin/ci-classes.php">Classes</a>.</li>
  <li>Add your <a href="/admin/ci-courses.php?new=1">courses</a> with their fee.</li>
  <li>Create <a href="/admin/ci-batches.php?new=1">batches</a> (timing, teacher, seats).</li>
  <li><a href="/admin/student-edit.php">Add students</a> and take the fee — a receipt is made for every payment.</li>
</ol></div></div>
<?php endif; ?>

<div class="ci-stats">
  <a class="ci-stat" href="/admin/students.php"><i class="fas fa-user-graduate ci-i-violet"></i><div><b><?= number_format($stats['active']) ?></b><small>Active students<?= $stats['online'] ? ' · <span style="color:#0e7490">' . number_format($stats['online']) . ' online</span>' : '' ?></small></div></a>
  <a class="ci-stat" href="/admin/students.php?status=all&amp;from=<?= $month ?>"><i class="fas fa-user-plus ci-i-blue"></i><div><b><?= number_format($stats['new']) ?></b><small>Admissions this month</small></div></a>
  <a class="ci-stat" href="/admin/ci-batches.php"><i class="fas fa-layer-group ci-i-cyan"></i><div><b><?= number_format($stats['batches']) ?></b><small>Active batches · <?= $stats['courses'] ?> courses</small></div></a>
  <a class="ci-stat" href="/admin/ci-fees.php?period=today"><i class="fas fa-indian-rupee-sign ci-i-green"></i><div><b><?= ci_money0($stats['today']) ?></b><small>Fee received today</small></div></a>
  <a class="ci-stat" href="/admin/ci-fees.php?period=month"><i class="fas fa-wallet ci-i-green"></i><div><b><?= ci_money0($stats['month']) ?></b><small>Fee this month</small></div></a>
  <a class="ci-stat" href="/admin/ci-dues.php?period=all"><i class="fas fa-hourglass-half ci-i-red"></i><div><b><?= ci_money0($stats['due']) ?></b><small>Total due</small></div></a>
  <a class="ci-stat" href="/admin/ci-dues.php?period=all&amp;late=1"><i class="fas fa-calendar-xmark ci-i-amber"></i><div><b><?= number_format($stats['late']) ?></b><small>Due date passed</small></div></a>
</div>

<div class="ci-grid2">
  <div class="ci-card"><div class="ci-card-h"><h3>New admissions</h3><a href="/admin/students.php?status=all">All students</a></div><div class="ci-card-b ci-p0"><ul class="ci-list">
    <?php if (!$recentStudents): ?><li class="ci-muted">No students yet.</li><?php endif; ?>
    <?php foreach ($recentStudents as $s): ?><li><div class="ci-person"><div><a href="/admin/student-view.php?id=<?= (int)$s['id'] ?>"><?= ci_e($s['name']) ?></a><small><?= $s['courses'] ? ci_e($s['courses']) : '&nbsp;' ?></small></div></div><span class="r ci-muted"><?= ci_date($s['admission_date']) ?></span></li><?php endforeach; ?>
  </ul></div></div>
  <div class="ci-card"><div class="ci-card-h"><h3>Recent payments</h3><a href="/admin/ci-fees.php?period=month">All payments</a></div><div class="ci-card-b ci-p0"><ul class="ci-list">
    <?php if (!$recentPays): ?><li class="ci-muted">No payments yet.</li><?php endif; ?>
    <?php foreach ($recentPays as $p): ?><li><div class="ci-person"><div><a href="/admin/student-view.php?id=<?= (int)$p['student_id'] ?>"><?= ci_e($p['name']) ?></a><small><?= ci_date($p['paid_on']) ?> · <?= ci_e(ci_pay_mode($p)) ?><?= $p['username'] ? ' · by ' . ci_e($p['username']) : '' ?></small></div></div><span class="r ci-amt-row"><b><?= ci_money0($p['amount']) ?></b><a class="ci-ico" href="/admin/ci-receipt.php?id=<?= (int)$p['id'] ?>" target="_blank" aria-label="Print receipt" style="width:28px;height:28px"><?= ci_svg('print') ?></a></span></li><?php endforeach; ?>
  </ul></div></div>
  <div class="ci-card"><div class="ci-card-h"><h3>Fee to collect</h3><a href="/admin/ci-dues.php?period=all">All dues</a></div><div class="ci-card-b ci-p0"><ul class="ci-list">
    <?php if (!$upcoming): ?><li class="ci-muted">No dues. Everyone has paid.</li><?php endif; ?>
    <?php foreach ($upcoming as $u): $late = $u['due_date'] && $u['due_date'] < $today; ?><li><div class="ci-person"><div><a href="/admin/student-view.php?id=<?= (int)$u['sid'] ?>#pay"><?= ci_e($u['name']) ?></a><small><?= ci_e($u['course_name']) ?> · <a href="tel:<?= ci_e($u['mobile']) ?>"><?= ci_e($u['mobile']) ?></a></small></div></div><span class="r"><b style="color:#b91c1c"><?= ci_money0($u['due']) ?></b><br><?= $u['due_date'] ? ($late ? '<span class="ci-badge ci-b-amber">' . ci_date($u['due_date']) . '</span>' : '<small class="ci-muted">' . ci_date($u['due_date']) . '</small>') : '' ?></span></li><?php endforeach; ?>
  </ul></div></div>
  <div class="ci-card"><div class="ci-card-h"><h3>Batches</h3><a href="/admin/ci-batches.php">All batches</a></div><div class="ci-card-b ci-p0"><ul class="ci-list">
    <?php if (!$batches): ?><li class="ci-muted">No active batches.</li><?php endif; ?>
    <?php foreach ($batches as $b): ?><li><div><a href="/admin/students.php?batch=<?= (int)$b['id'] ?>" style="font-weight:600;color:#111827"><?= ci_e($b['name']) ?></a><small class="ci-muted" style="display:block"><?= ci_e(implode(' · ', array_filter([$b['course_name'], $b['timing']]))) ?></small></div>
      <span class="r"><b><?= (int)$b['n'] ?></b><?= $b['capacity'] ? ' <span class="ci-muted">/ ' . (int)$b['capacity'] . '</span><div class="ci-meter"><i style="width:' . min(100, round($b['n'] / max(1, $b['capacity']) * 100)) . '%"></i></div>' : ' <span class="ci-muted">students</span>' ?></span></li><?php endforeach; ?>
  </ul></div></div>
</div>

</div>
<?php admin_shell_close(); ?>
</body>
</html>
