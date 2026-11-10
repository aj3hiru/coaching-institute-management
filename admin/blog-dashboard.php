<?php
// Blog Dashboard (was the main Dashboard) — same layout as the new CMS: actions bar, Traffic Overview, Traffic Trend, Traffic by Country, Today's Posts.
require_once __DIR__ . '/components/guard.php';
require_once INCLUDES_PATH . '/stats.php';
$me = admin_guard(['admin', 'editor', 'author']);
if (!admin_blog_access()) { http_response_code(403); exit('Access Denied'); } // Users → Blogs → No access
$perm = json_decode($me['permissions'] ?? '{}', true) ?: [];
if ($me['role'] !== 'admin' && empty($perm['dashboard_access'])) { http_response_code(403); exit('Access Denied'); }
edu_stats_tables($pdo);

$isAdmin = in_array($me['role'], ['admin', 'blog_admin'], true); // blog admin sees everyone's blog figures
$users = $isAdmin ? $pdo->query("SELECT id, username, role FROM users WHERE status = 'active' ORDER BY username")->fetchAll(PDO::FETCH_ASSOC) : [];
$who = $isAdmin ? (int)($_GET['user'] ?? 0) : (int)$me['id'];
$authorIds = [];
if ($who) {
    $st = $pdo->prepare("SELECT id FROM authors WHERE user_id = ?");
    $st->execute([$who]);
    $authorIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)) ?: [0];
}
$authorSql = $authorIds ? ' AND author_id IN (' . implode(',', $authorIds) . ')' : '';
$myPostIds = null;
if ($authorIds) $myPostIds = array_flip(array_map('intval', $pdo->query("SELECT id FROM posts WHERE 1 $authorSql")->fetchAll(PDO::FETCH_COLUMN)));

$today = date('Y-m-d');
$daily = edu_stats_daily();
$todayPosts = (array)($daily[$today] ?? []);
if ($myPostIds !== null) $todayPosts = array_intersect_key($todayPosts, $myPostIds);
$todayViews = array_sum($todayPosts);
$postsViewed = count(array_filter($todayPosts));
$uq = $pdo->prepare("SELECT COUNT(DISTINCT visitor_id) FROM visitor_log WHERE visit_date = ?" . ($myPostIds !== null ? ' AND post_id IN (' . (implode(',', array_keys($myPostIds)) ?: '0') . ')' : ''));
$uq->execute([$today]);
$unique = (int)$uq->fetchColumn();
$hourly = edu_stats_hourly();
$cq = $pdo->prepare("SELECT country, SUM(views) v FROM post_stats_daily WHERE stat_date = ?" . ($myPostIds !== null ? ' AND post_id IN (' . (implode(',', array_keys($myPostIds)) ?: '0') . ')' : '') . " GROUP BY country ORDER BY v DESC");
$cq->execute([$today]);
$countries = $cq->fetchAll(PDO::FETCH_ASSOC);
$ctotal = array_sum(array_column($countries, 'v')) ?: 1;
$top = array_slice($countries, 0, 7);
$other = array_sum(array_column(array_slice($countries, 7), 'v'));
// Traffic Adjustment (non-admin viewers only)
$adj = edu_adjust_factor($pdo, $me, $today, $today, $myPostIds !== null ? ' AND post_id IN (' . (implode(',', array_keys($myPostIds)) ?: '0') . ')' : '');
if ($adj < 1) {
    $todayViews = edu_adjust($todayViews, $adj); $unique = edu_adjust($unique, $adj);
    $hourly = array_map(fn($v) => edu_adjust($v, $adj), $hourly);
    foreach ($countries as &$cRow) $cRow['v'] = edu_adjust($cRow['v'], $adj); unset($cRow);
    $ctotal = array_sum(array_column($countries, 'v')) ?: 1; $top = array_slice($countries, 0, 7); $other = array_sum(array_column(array_slice($countries, 7), 'v'));
}
$pubToday = (int)$pdo->query("SELECT COUNT(*) FROM posts WHERE status = 'published' AND DATE(date) = CURDATE() $authorSql")->fetchColumn();
$pubYest = (int)$pdo->query("SELECT COUNT(*) FROM posts WHERE status = 'published' AND DATE(date) = CURDATE() - INTERVAL 1 DAY $authorSql")->fetchColumn();
$glance = [
    ['fa-file-alt', 'Published posts', (int)$pdo->query("SELECT COUNT(*) FROM posts WHERE status = 'published' $authorSql")->fetchColumn(), '/admin/blogs-manager.php'],
    ['fa-pen', 'Drafts', (int)$pdo->query("SELECT COUNT(*) FROM posts WHERE status = 'draft' $authorSql")->fetchColumn(), '/admin/blogs-manager.php'],
];
// Only what this user may open
if (admin_can('blogs.manage_comments')) $glance[] = ['fa-comments', 'Comments', (int)$pdo->query("SELECT COUNT(*) FROM comments")->fetchColumn(), '/admin/comments-manager.php'];
if (admin_can('push_notifications.send')) $glance[] = ['fa-bell', 'Push subscribers', (int)$pdo->query("SELECT COUNT(*) FROM push_subscriptions")->fetchColumn(), '/push_notifications/push-manager.php?tab=subscribers'];
$flash = ['created' => 'Post created successfully!', 'updated' => 'Post updated successfully!', 'deleted' => 'Post deleted.'][$_GET['success'] ?? ''] ?? '';
$colors = ['#2563eb', '#16a34a', '#f59e0b', '#db2777', '#7c3aed', '#0891b2', '#ea580c', '#64748b'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title>Blog Dashboard - <?= htmlspecialchars(edu_site_name()) ?></title>
<?php admin_shell_head(); ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js" defer></script>
<style>.db-glance{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.db-glance a{display:flex;align-items:center;gap:12px;padding:14px;border:1px solid var(--gray-200,#e5e7eb);border-radius:12px;background:#fff;color:inherit}.db-glance a:hover{border-color:var(--primary)}.db-glance i{width:38px;height:38px;border-radius:10px;display:grid;place-items:center;background:var(--primary-light,#ede9fe);color:var(--primary)}.db-glance b{display:block;font-size:1.2rem}.db-glance small{color:var(--gray-500,#6b7280)}@media (max-width:760px){.db-glance{grid-template-columns:repeat(2,minmax(0,1fr))}}.db-empty{padding:18px;color:var(--gray-500,#6b7280);font-size:.9rem;text-align:center}[data-section].is-off{display:none}</style>
</head>
<body>
<?php admin_shell_open('Blog Dashboard', date('l, F j, Y') . ' — ' . htmlspecialchars(edu_site_name()) . ' Admin Panel'); ?>
<?php if ($flash): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= $flash ?></div><?php endif; ?>
<div class="wp-dashboard-wrap">
  <div class="db-actions-bar">
    <?php if (admin_can('blogs.create')): ?><a class="db-action-btn db-action-primary" href="/admin/post-manager.php"><i class="fas fa-plus"></i> Add New Post</a><?php endif; ?>
    <?php if (admin_can('analytics.view_basic')): ?><a class="db-action-btn db-action-ghost" href="/admin/analytics.php"><i class="fas fa-chart-line"></i> Full Analytics</a><?php endif; ?>
    <div class="db-display-wrap"><button class="db-action-btn db-action-ghost" type="button" id="db-display-btn"><i class="fas fa-sliders-h"></i> Display Options <i class="fas fa-chevron-down"></i></button>
      <div class="db-display-panel" id="db-display-panel">
        <?php foreach (['overview' => 'Traffic Overview', 'chart' => 'Traffic Chart', 'country' => 'Traffic by Country', 'posts' => "Today's Posts", 'glance' => 'At a Glance'] as $k => $l): ?><label class="db-display-check"><input type="checkbox" data-opt="<?= $k ?>" checked><?= $l ?></label><?php endforeach; ?>
      </div>
    </div>
    <?php if ($isAdmin): ?>
    <div class="db-user-filter"><i class="fas fa-user-clock db-user-filter-icon"></i><select class="db-user-filter-select" aria-label="View a different user's dashboard" onchange="location.href='?user='+this.value"><option value="0">All</option><?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>" <?= $who === (int)$u['id'] ? 'selected' : '' ?>><?= htmlspecialchars($u['username']) ?> (<?= $u['role'] ?>)</option><?php endforeach; ?></select></div>
    <?php endif; ?>
  </div>

  <div class="db-card db-card-full" data-section="overview">
    <div class="db-card-head"><div class="db-card-title"><span class="dci dci-indigo"><i class="fas fa-chart-line"></i></span>Traffic Overview</div><?php if (admin_can('analytics.view_basic')): ?><a class="db-card-link" href="/admin/analytics.php">View Full Analytics <i class="fas fa-arrow-right"></i></a><?php endif; ?></div>
    <div class="db-card-body"><div class="traffic-grid">
      <div class="traffic-card traffic-today"><div class="tc-icon"><i class="fas fa-calendar-day"></i></div><div class="tc-info"><div class="tc-lbl">Today's Views</div><div class="tc-num"><?= number_format($todayViews) ?></div><div class="tc-sub"><i class="fas fa-user"></i> <?= number_format($unique) ?> unique visitors</div></div></div>
      <div class="traffic-card traffic-yesterday"><div class="tc-icon"><i class="fas fa-user-friends"></i></div><div class="tc-info"><div class="tc-lbl">Unique Visitors</div><div class="tc-num"><?= number_format($unique) ?></div><div class="tc-sub"><i class="fas fa-calendar-day"></i> today</div></div></div>
      <div class="traffic-card traffic-week"><div class="tc-icon"><i class="fas fa-file-alt"></i></div><div class="tc-info"><div class="tc-lbl">Posts Viewed</div><div class="tc-num"><?= number_format($postsViewed) ?></div><div class="tc-sub"><i class="fas fa-calendar-day"></i> got views today</div></div></div>
    </div></div>
  </div>

  <div class="db-row2">
    <div class="db-card db-card-full" data-section="chart">
      <div class="db-card-head"><div class="db-card-title"><span class="dci dci-emerald"><i class="fas fa-wave-square"></i></span>Traffic Trend</div><div class="db-card-sub">Today · hour by hour</div></div>
      <div class="db-card-body"><div class="db-chart-wrap"><div style="position:relative;height:260px;width:100%"><canvas id="db-trend" role="img" aria-label="Views per hour today"></canvas></div></div></div>
    </div>
    <div class="db-card db-card-full" data-section="country">
      <div class="db-card-head"><div class="db-card-title"><span class="dci dci-indigo"><i class="fas fa-flag"></i></span>Traffic by Country</div><div class="db-card-sub">Today · top 7, rest as Other</div></div>
      <div class="db-card-body">
        <?php if (!$countries): ?><div class="db-empty">No visits recorded yet today.</div><?php else: ?>
        <ul class="db-country-list">
          <?php foreach ($top as $i => $c): $pct = round($c['v'] / $ctotal * 100); ?>
          <li class="db-country-row"><span class="db-country-flag"><?= edu_country_flag($c['country']) ?></span><span class="db-country-name" title="<?= htmlspecialchars(edu_country_name($c['country'])) ?>"><?= htmlspecialchars(edu_country_name($c['country'])) ?></span><span class="db-country-views"><?= number_format($c['v']) ?></span><span class="db-country-pct"><?= $pct ?>%</span><div class="db-country-bar-wrap"><div class="db-country-bar" style="width:<?= $pct ?>%;background:<?= $colors[$i % 8] ?>"></div></div></li>
          <?php endforeach; ?>
          <?php if ($other): $pct = round($other / $ctotal * 100); ?><li class="db-country-row"><span class="db-country-flag">🌐</span><span class="db-country-name">Other</span><span class="db-country-views"><?= number_format($other) ?></span><span class="db-country-pct"><?= $pct ?>%</span><div class="db-country-bar-wrap"><div class="db-country-bar" style="width:<?= $pct ?>%;background:#94a3b8"></div></div></li><?php endif; ?>
        </ul>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="db-card db-card-full" data-section="posts">
    <div class="db-card-head"><div class="db-card-title"><span class="dci dci-violet"><i class="fas fa-file-alt"></i></span>Today's Posts</div><a class="db-card-link" href="/admin/blogs-manager.php">All Posts <i class="fas fa-arrow-right"></i></a></div>
    <div class="db-card-body"><div class="tp-stat-row">
      <div class="tp-stat-card tp-today"><div class="tp-stat-num"><?= $pubToday ?></div><div class="tp-stat-lbl"><i class="fas fa-calendar-day"></i> Published Today</div></div>
      <div class="tp-stat-card tp-yesterday"><div class="tp-stat-num"><?= $pubYest ?></div><div class="tp-stat-lbl"><i class="fas fa-calendar-minus"></i> Published Yesterday</div></div>
    </div></div>
  </div>

  <div class="db-card db-card-full" data-section="glance">
    <div class="db-card-head"><div class="db-card-title"><span class="dci dci-emerald"><i class="fas fa-gauge"></i></span>At a Glance</div></div>
    <div class="db-card-body"><div class="db-glance"><?php foreach ($glance as [$ic, $l, $n, $u]): ?><a href="<?= $u ?>"><i class="fas <?= $ic ?>"></i><span><b><?= number_format($n) ?></b><small><?= $l ?></small></span></a><?php endforeach; ?></div></div>
  </div>
</div>
<?php admin_shell_close(); ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var btn = document.getElementById('db-display-btn'), panel = document.getElementById('db-display-panel'), saved = {};
  try { saved = JSON.parse(localStorage.getItem('edu_dash_opts') || '{}'); } catch (e) {}
  var chev = btn.querySelector('.fa-chevron-down');
  function show(o) { panel.classList.toggle('open', o); chev.style.transform = o ? 'rotate(180deg)' : ''; btn.setAttribute('aria-expanded', o ? 'true' : 'false'); }
  btn.onclick = function (e) { e.stopPropagation(); show(!panel.classList.contains('open')); };
  document.addEventListener('click', function (e) { if (!e.target.closest('.db-display-wrap')) show(false); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') show(false); });
  document.querySelectorAll('[data-opt]').forEach(function (c) {
    var k = c.dataset.opt; if (saved[k] === false) c.checked = false;
    var apply = function () { document.querySelectorAll('[data-section="' + k + '"]').forEach(function (s) { s.classList.toggle('is-off', !c.checked); }); };
    apply(); c.onchange = function () { saved[k] = c.checked; try { localStorage.setItem('edu_dash_opts', JSON.stringify(saved)); } catch (e) {} apply(); };
  });
  var hourly = <?= json_encode(array_values($hourly), JSON_HEX_TAG) ?>, now = new Date().getHours();
  (function draw() {
    if (!window.Chart) return setTimeout(draw, 100);
    var ctx = document.getElementById('db-trend').getContext('2d'), g = ctx.createLinearGradient(0, 0, 0, 260);
    g.addColorStop(0, 'rgba(16,185,129,.28)'); g.addColorStop(1, 'rgba(16,185,129,0)');
    new Chart(ctx, { type: 'line', data: { labels: hourly.map(function (_, h) { return (h % 12 || 12) + (h < 12 ? ' am' : ' pm'); }).slice(0, now + 1), datasets: [{ label: 'Views', data: hourly.slice(0, now + 1), borderColor: '#10b981', backgroundColor: g, fill: true, tension: .35, pointRadius: 2, borderWidth: 2 }] },
      options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { grid: { display: false }, ticks: { maxTicksLimit: 8 } }, y: { beginAtZero: true, ticks: { precision: 0 } } } } });
  })();
});
</script>
</body>
</html>
