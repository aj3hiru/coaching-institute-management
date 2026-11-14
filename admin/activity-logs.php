<?php
// Activity Logs — who did what and when: search, filter by action, user and date, and clear old entries.
// Only this site's actions are shown (entries other apps wrote into the same table, e.g. "ecom_…", are left out).
require_once __DIR__ . '/components/guard.php';
$me = admin_guard(['admin', 'editor', 'author', 'teacher', 'accountant']);
if (!admin_can('security.view_logs')) { http_response_code(403); exit('Access Denied'); }
$isAdmin = $me['role'] === 'admin';
// Only what the site's users (staff) do — entries about students (their records, fees, enrolments, online accounts,
// orders) are kept in the database but not listed here, and "Clear" leaves them alone.
const AL_SCOPE = "l.action_type NOT LIKE 'ecom\\_%' AND l.action_type NOT IN ('ci_student','ci_student_delete','ci_payment','ci_payment_delete','ci_promote','ci_enroll','shop_customer','shop_access','shop_order')";

// Friendly names for the actions this site records (anything else is shown as its own words).
const AL_LABELS = [
    'login_success' => 'Signed in', 'login_failed' => 'Wrong password', 'login_blocked' => 'Sign-in blocked', 'login_denied' => 'Sign-in denied', 'logout' => 'Signed out',
    'post_create' => 'Post created', 'post_edit' => 'Post edited', 'post_delete' => 'Post deleted', 'post_bulk_status' => 'Posts status changed', 'post_bulk_delete' => 'Posts deleted',
    'page_save' => 'Page saved', 'page_delete' => 'Page deleted', 'category_create' => 'Category created', 'category_update' => 'Category edited', 'category_delete' => 'Category deleted',
    'tag_create' => 'Tag created', 'tag_update' => 'Tag edited', 'tag_delete' => 'Tag deleted', 'comment_reply' => 'Comment reply', 'comment_delete' => 'Comment deleted', 'comment_status' => 'Comment moderated',
    'user_create' => 'User created', 'user_edit' => 'User edited', 'user_delete' => 'User deleted', 'user_role' => 'Role changed', 'author_save' => 'Author profile saved', 'profile_update' => 'Profile updated',
    'media_upload' => 'Media uploaded', 'media_delete' => 'Media deleted', 'pdf_upload' => 'PDF uploaded', 'pdf_delete' => 'PDF deleted',
    'push_send' => 'Push sent', 'push_settings' => 'Push settings', 'settings_update' => 'Settings saved', 'theme_publish' => 'Customizer published', 'seo_update' => 'SEO settings',
    'snippets_update' => 'Code snippets', 'performance_update' => 'Performance settings', 'ads_update' => 'Ads saved', 'country_redirect' => 'Country redirect', 'redirect_save' => 'Redirect saved',
    'bulk_update' => 'Bulk update', 'import' => 'Import', 'export' => 'Export', 'backup_create' => 'Backup created', 'backup_restore' => 'Backup restored', 'cache_clear' => 'Cache cleared',
    'contact_delete' => 'Message deleted', 'contact_reply' => 'Message replied', 'logs_clear' => 'Logs cleared',
];
function al_label(string $t): string { return AL_LABELS[$t] ?? ucfirst(str_replace('_', ' ', $t)); }
function al_tone(string $t): string
{
    if (preg_match('/failed|blocked|denied/', $t)) return 'danger';
    if (str_contains($t, 'delete') || str_contains($t, 'clear')) return 'danger';
    if (str_starts_with($t, 'login') || $t === 'logout') return 'info';
    if (str_contains($t, 'create') || str_contains($t, 'upload') || str_contains($t, 'send') || str_contains($t, 'publish')) return 'success';
    return 'warning';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$isAdmin) { admin_flash('Only admins can clear logs.', 'error'); header('Location: /admin/activity-logs.php'); exit; }
    $days = (int)($_POST['days'] ?? -1);
    if (in_array($days, [0, 7, 30, 90], true)) {
        $n = $pdo->exec("DELETE l FROM activity_logs l WHERE " . AL_SCOPE . ($days ? " AND l.created_at < NOW() - INTERVAL $days DAY" : ''));
        admin_log('logs_clear', $days ? "Cleared logs older than $days days ($n entries)" : "Cleared all logs ($n entries)");
        admin_flash("Cleared $n log entr" . ($n === 1 ? 'y' : 'ies') . '.');
    }
    header('Location: /admin/activity-logs.php');
    exit;
}

$q = trim((string)($_GET['q'] ?? ''));
$action = preg_replace('/[^a-z0-9_]/', '', (string)($_GET['action'] ?? ''));
$uid = (int)($_GET['user'] ?? 0);
$range = in_array($_GET['range'] ?? '', ['today', '7', '30', '90', 'custom'], true) ? $_GET['range'] : '';
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : '';
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : '';
if ($from || $to) $range = 'custom';
$page = max(1, (int)($_GET['page'] ?? 1));
$per = 30;

$where = [AL_SCOPE];
$args = [];
if ($q !== '') { $where[] = "(l.description LIKE ? OR l.ip_address LIKE ? OR u.username LIKE ?)"; array_push($args, "%$q%", "%$q%", "%$q%"); }
if ($action !== '') { $where[] = "l.action_type = ?"; $args[] = $action; }
if ($uid) { $where[] = "l.user_id = ?"; $args[] = $uid; }
if ($range === 'today') $where[] = "l.created_at >= CURDATE()";
elseif (in_array($range, ['7', '30', '90'], true)) $where[] = "l.created_at >= NOW() - INTERVAL $range DAY";
if ($from) { $where[] = "l.created_at >= ?"; $args[] = "$from 00:00:00"; }
if ($to) { $where[] = "l.created_at <= ?"; $args[] = "$to 23:59:59"; }
$w = 'WHERE ' . implode(' AND ', $where);

$st = $pdo->prepare("SELECT COUNT(*) FROM activity_logs l LEFT JOIN users u ON u.id = l.user_id $w");
$st->execute($args);
$total = (int)$st->fetchColumn();
$pages = max(1, (int)ceil($total / $per));
$page = min($page, $pages);
$st = $pdo->prepare("SELECT l.*, u.username, u.role FROM activity_logs l LEFT JOIN users u ON u.id = l.user_id $w ORDER BY l.created_at DESC, l.id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
$st->execute($args);
$logs = $st->fetchAll(PDO::FETCH_ASSOC);

$actions = $pdo->query("SELECT l.action_type, COUNT(*) c FROM activity_logs l WHERE " . AL_SCOPE . " GROUP BY l.action_type")->fetchAll(PDO::FETCH_KEY_PAIR);
uksort($actions, fn($a, $b) => strcmp(al_label($a), al_label($b)));
$users = $pdo->query("SELECT id, username FROM users ORDER BY username")->fetchAll(PDO::FETCH_KEY_PAIR);
$today = (int)$pdo->query("SELECT COUNT(*) FROM activity_logs l WHERE " . AL_SCOPE . " AND l.created_at >= CURDATE()")->fetchColumn();
$failed = (int)$pdo->query("SELECT COUNT(*) FROM activity_logs l WHERE l.action_type IN ('login_failed','login_blocked','login_denied') AND l.created_at >= NOW() - INTERVAL 7 DAY")->fetchColumn();
$all = (int)$pdo->query("SELECT COUNT(*) FROM activity_logs l WHERE " . AL_SCOPE)->fetchColumn();
$filtered = $q !== '' || $action !== '' || $uid || $range !== '';
$esc = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$link = function (array $over) use ($q, $action, $uid, $range, $from, $to) {
    $p = array_filter(array_merge(['q' => $q, 'action' => $action, 'user' => $uid ?: '', 'range' => $range === 'custom' ? '' : $range, 'from' => $from, 'to' => $to], $over), fn($v) => $v !== '' && $v !== null && $v !== 0);
    return '/admin/activity-logs.php' . ($p ? '?' . http_build_query($p) : '');
};
$ago = function (string $d): string {
    $s = time() - strtotime($d);
    if ($s < 60) return 'just now';
    if ($s < 3600) return floor($s / 60) . ' min ago';
    if ($s < 86400) return floor($s / 3600) . ' h ago';
    return floor($s / 86400) . ' d ago';
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title>Activity Logs - <?= $esc(edu_site_name()) ?></title>
<?php admin_shell_head(); ?>
<style>
.al-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin:0 0 16px}
.al-stat{background:#fff;border:1px solid var(--gray-200);border-radius:12px;padding:14px 16px;display:flex;align-items:center;gap:12px}
.al-stat i{width:38px;height:38px;border-radius:10px;display:grid;place-items:center;font-size:15px;flex-shrink:0}
.al-stat b{display:block;font-size:1.25rem;line-height:1.2;color:var(--gray-900)}.al-stat span{font-size:.78rem;color:var(--gray-500)}
.al-card{background:#fff;border:1px solid var(--gray-200);border-radius:14px;padding:16px 18px;margin-bottom:16px}
.al-grid{display:grid;grid-template-columns:2fr 1.3fr 1fr 1fr auto;gap:12px;align-items:end}
.al-f label{display:block;font-size:.75rem;font-weight:600;color:var(--gray-600);margin-bottom:5px}
.al-f .form-control{height:40px;padding:0 .75rem}
.al-dates{display:none;grid-template-columns:1fr 1fr;gap:12px;margin-top:12px;max-width:420px}.al-dates.show{display:grid}
.al-btns{display:flex;gap:8px}
.al-btns .btn{height:40px}
.al-chips{display:flex;flex-wrap:wrap;gap:6px;margin-top:12px}
.al-chips a{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:99px;background:var(--primary-lighter,#f5f3ff);color:var(--primary);font-size:.75rem;font-weight:600;text-decoration:none}
.al-chips a:hover{background:var(--primary-light,#ede9fe)}
.al-bar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin:0 0 10px}
.al-count{font-size:.8125rem;color:var(--gray-500)}
.al-clear{display:flex;align-items:center;gap:6px;flex-wrap:wrap}.al-clear span{font-size:.78rem;font-weight:600;color:var(--gray-500)}
.al-user{display:flex;align-items:center;gap:10px;min-width:0}
.al-av{width:32px;height:32px;border-radius:50%;background:var(--primary-light,#ede9fe);color:var(--primary);display:grid;place-items:center;font-weight:700;font-size:.8rem;flex-shrink:0}
.al-user b{display:block;font-size:.85rem;color:var(--gray-900);font-weight:600}.al-user small{font-size:.72rem;color:var(--gray-500);text-transform:capitalize}
.al-act{display:inline-block;padding:3px 9px;border-radius:99px;font-size:.72rem;font-weight:700;white-space:nowrap}
.al-act.success{background:#dcfce7;color:#166534}.al-act.danger{background:#fee2e2;color:#b91c1c}.al-act.info{background:#e0f2fe;color:#075985}.al-act.warning{background:#fef3c7;color:#92400e}
.al-desc{font-size:.85rem;color:var(--gray-700);max-width:520px;overflow-wrap:anywhere}
.al-ip{font:12px ui-monospace,Menlo,monospace;color:var(--gray-500);white-space:nowrap}
.al-when{white-space:nowrap;font-size:.8rem;color:var(--gray-600)}.al-when small{display:block;color:var(--gray-400);font-size:.72rem}
.al-pages{display:flex;justify-content:center;gap:6px;margin-top:16px;flex-wrap:wrap}
.al-pages a,.al-pages span{min-width:34px;height:34px;padding:0 10px;border-radius:8px;border:1px solid var(--gray-200);display:inline-flex;align-items:center;justify-content:center;font-size:.8125rem;color:var(--gray-700);text-decoration:none;background:#fff}
.al-pages a.cur{background:var(--primary);border-color:var(--primary);color:#fff}.al-pages span{border:0;background:none}
@media (max-width:1100px){.al-grid{grid-template-columns:1fr 1fr}.al-btns{grid-column:1/-1}}
@media (max-width:640px){.al-grid{grid-template-columns:1fr}.al-stats{grid-template-columns:1fr 1fr}.al-stats .al-stat:last-child{grid-column:1/-1}.al-hide{display:none}}
</style>
</head>
<body>
<?php admin_shell_open('Activity Logs', ''); ?>
<?= admin_flash() ?>
<div class="al-stats">
  <div class="al-stat"><i class="fas fa-list-ul" style="background:#ede9fe;color:#7c3aed"></i><div><b><?= number_format($all) ?></b><span>Total entries</span></div></div>
  <div class="al-stat"><i class="fas fa-calendar-day" style="background:#e0f2fe;color:#0284c7"></i><div><b><?= number_format($today) ?></b><span>Today</span></div></div>
  <div class="al-stat"><i class="fas fa-user-lock" style="background:#fee2e2;color:#dc2626"></i><div><b><?= number_format($failed) ?></b><span>Failed sign-ins (7 days)</span></div></div>
</div>

<form class="al-card" method="GET" id="alForm">
  <div class="al-grid">
    <div class="al-f"><label>Search</label><input type="search" name="q" class="form-control" value="<?= $esc($q) ?>" placeholder="Description, IP or username…"></div>
    <div class="al-f"><label>Action</label><select name="action" class="form-control" onchange="this.form.submit()"><option value="">All actions</option><?php foreach ($actions as $a => $c): ?><option value="<?= $esc($a) ?>"<?= $action === $a ? ' selected' : '' ?>><?= $esc(al_label($a)) ?> (<?= $c ?>)</option><?php endforeach; ?></select></div>
    <div class="al-f"><label>User</label><select name="user" class="form-control" onchange="this.form.submit()"><option value="">All users</option><?php foreach ($users as $id => $n): ?><option value="<?= (int)$id ?>"<?= $uid === (int)$id ? ' selected' : '' ?>><?= $esc($n) ?></option><?php endforeach; ?></select></div>
    <div class="al-f"><label>Date</label><select name="range" class="form-control" id="alRange"><option value="">Any time</option><?php foreach (['today' => 'Today', '7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 90 days', 'custom' => 'Custom dates…'] as $k => $l): ?><option value="<?= $k ?>"<?= $range === (string)$k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    <div class="al-btns"><button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button><a href="/admin/activity-logs.php" class="btn btn-secondary" title="Reset filters"><i class="fas fa-xmark"></i></a></div>
  </div>
  <div class="al-dates<?= $range === 'custom' ? ' show' : '' ?>" id="alDates">
    <div class="al-f"><label>From</label><input type="date" name="from" class="form-control" value="<?= $esc($from) ?>" max="<?= date('Y-m-d') ?>"></div>
    <div class="al-f"><label>To</label><input type="date" name="to" class="form-control" value="<?= $esc($to) ?>" max="<?= date('Y-m-d') ?>"></div>
  </div>
  <?php if ($filtered): ?><div class="al-chips">
    <?php if ($q !== ''): ?><a href="<?= $esc($link(['q' => ''])) ?>">“<?= $esc($q) ?>” <i class="fas fa-xmark"></i></a><?php endif; ?>
    <?php if ($action !== ''): ?><a href="<?= $esc($link(['action' => ''])) ?>"><?= $esc(al_label($action)) ?> <i class="fas fa-xmark"></i></a><?php endif; ?>
    <?php if ($uid): ?><a href="<?= $esc($link(['user' => ''])) ?>">User: <?= $esc($users[$uid] ?? '#' . $uid) ?> <i class="fas fa-xmark"></i></a><?php endif; ?>
    <?php if ($range !== ''): ?><a href="<?= $esc($link(['range' => '', 'from' => '', 'to' => ''])) ?>"><?= $range === 'custom' ? $esc(($from ? date('j M Y', strtotime($from)) : '…') . ' – ' . ($to ? date('j M Y', strtotime($to)) : 'today')) : ['today' => 'Today', '7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 90 days'][$range] ?> <i class="fas fa-xmark"></i></a><?php endif; ?>
  </div><?php endif; ?>
</form>

<div class="al-bar">
  <div class="al-count"><?= number_format($total) ?> entr<?= $total === 1 ? 'y' : 'ies' ?><?= $filtered ? ' matching your filters' : '' ?></div>
  <?php if ($isAdmin): ?><form method="POST" class="al-clear"><?= csrf_field() ?><span>Clear logs:</span>
    <button class="btn btn-secondary btn-sm" name="days" value="90" data-confirm="Delete all log entries older than 90 days? This cannot be undone." data-confirm-title="Clear old logs" data-confirm-ok="Clear">Older than 90 days</button>
    <button class="btn btn-secondary btn-sm" name="days" value="30" data-confirm="Delete all log entries older than 30 days? This cannot be undone." data-confirm-title="Clear old logs" data-confirm-ok="Clear">Older than 30 days</button>
    <button class="btn btn-danger btn-sm" name="days" value="0" data-confirm="Delete ALL activity log entries? This cannot be undone." data-confirm-title="Clear all logs" data-confirm-ok="Clear all"><i class="fas fa-trash"></i> Clear all</button>
  </form><?php endif; ?>
</div>

<div class="table-wrap"><div class="table-responsive"><table>
  <thead><tr><th>User</th><th>Action</th><th>Details</th><th class="al-hide">IP Address</th><th>When</th></tr></thead>
  <tbody>
  <?php if (!$logs): ?><tr><td colspan="5"><div class="empty-state"><i class="fas fa-clipboard-list"></i><p><?= $filtered ? 'No entries match these filters.' : 'No activity recorded yet.' ?></p></div></td></tr><?php endif; ?>
  <?php foreach ($logs as $l): $name = $l['username'] ?? ''; ?>
  <tr>
    <td><div class="al-user"><span class="al-av"><?= $esc($name !== '' ? mb_strtoupper(mb_substr($name, 0, 1)) : '?') ?></span><div style="min-width:0"><?php if ($name !== ''): ?><a href="<?= $esc($link(['user' => (int)$l['user_id'], 'page' => ''])) ?>" style="text-decoration:none"><b><?= $esc($name) ?></b></a><small><?= $esc($l['role'] ?? '') ?></small><?php else: ?><b style="color:var(--gray-500)">Visitor</b><small>not signed in</small><?php endif; ?></div></div></td>
    <td><a href="<?= $esc($link(['action' => $l['action_type'], 'page' => ''])) ?>" style="text-decoration:none"><span class="al-act <?= al_tone($l['action_type']) ?>"><?= $esc(al_label($l['action_type'])) ?></span></a></td>
    <td><div class="al-desc"><?= $esc($l['description']) ?></div></td>
    <td class="al-hide"><span class="al-ip"><?= $esc($l['ip_address']) ?></span></td>
    <td><div class="al-when" title="<?= $esc($l['created_at']) ?>"><?= date('j M Y, g:i a', strtotime($l['created_at'])) ?><small><?= $ago($l['created_at']) ?></small></div></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table></div></div>

<?php if ($pages > 1): ?><nav class="al-pages">
  <?php if ($page > 1): ?><a href="<?= $esc($link(['page' => $page - 1])) ?>"><i class="fas fa-chevron-left"></i></a><?php endif; ?>
  <?php $shown = []; foreach ([1, $page - 2, $page - 1, $page, $page + 1, $page + 2, $pages] as $n) if ($n >= 1 && $n <= $pages) $shown[$n] = true; ksort($shown); $prev = 0;
  foreach (array_keys($shown) as $n): if ($n - $prev > 1): ?><span>…</span><?php endif; $prev = $n; ?><a class="<?= $n === $page ? 'cur' : '' ?>" href="<?= $esc($link(['page' => $n])) ?>"><?= $n ?></a><?php endforeach; ?>
  <?php if ($page < $pages): ?><a href="<?= $esc($link(['page' => $page + 1])) ?>"><i class="fas fa-chevron-right"></i></a><?php endif; ?>
</nav><?php endif; ?>
<?php admin_shell_close(); ?>
<script>
document.getElementById('alRange').addEventListener('change', function () {
  var custom = this.value === 'custom';
  document.getElementById('alDates').classList.toggle('show', custom);
  if (!custom) { this.form.from.value = ''; this.form.to.value = ''; this.form.submit(); }
});
</script>
</body>
</html>
