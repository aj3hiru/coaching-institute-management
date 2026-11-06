<?php
// All Posts — same layout as the new CMS: status tabs, filters, search, row actions, bulk actions, mobile cards.
require_once __DIR__ . '/components/guard.php';
$me = admin_guard(['admin', 'editor', 'author']);
if (!admin_blog_access()) { http_response_code(403); exit('Access Denied'); } // Users → Blogs → No access
// "own posts" users (Blog → Add / edit own) see and manage only their own posts; "all posts" users see everything
$isAuthor = $me['role'] !== 'admin' && !admin_can('blogs.edit_all');
$myAuthorIds = [];
if ($isAuthor) {
    $st = $pdo->prepare("SELECT id FROM authors WHERE user_id = ?");
    $st->execute([$me['id']]);
    $myAuthorIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)) ?: [0];
}
$ownSql = $isAuthor ? ' AND p.author_id IN (' . implode(',', $myAuthorIds) . ')' : '';

/** Deletes a post, its meta, links and its own images (a featured image another post still uses is kept). */
function edu_delete_post(PDO $pdo, int $id, string $ownSql): bool
{
    $st = $pdo->prepare("SELECT p.id, p.title, p.featured_image_id FROM posts p WHERE p.id = ? $ownSql");
    $st->execute([$id]);
    $post = $st->fetch(PDO::FETCH_ASSOC);
    if (!$post) return false;
    $root = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/';
    $feat = (int)$post['featured_image_id'];
    $shared = 0;
    if ($feat) { $c = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE featured_image_id = ? AND id <> ?"); $c->execute([$feat, $id]); $shared = (int)$c->fetchColumn(); }
    $where = $shared || !$feat ? "post_id = ?" : "post_id = ? OR id = ?";
    $params = $shared || !$feat ? [$id] : [$id, $feat];
    $pdo->beginTransaction();
    $m = $pdo->prepare("SELECT file_path, responsive_set FROM media WHERE $where");
    $m->execute($params);
    $files = [];
    foreach ($m->fetchAll(PDO::FETCH_ASSOC) as $r) $files = array_merge($files, [$r['file_path']], array_values(json_decode($r['responsive_set'] ?? '', true) ?: []));
    $pdo->prepare("DELETE FROM media WHERE $where")->execute($params);
    foreach (['post_meta', 'post_categories', 'post_tag'] as $t) $pdo->prepare("DELETE FROM $t WHERE post_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM posts WHERE id = ?")->execute([$id]);
    $pdo->commit();
    foreach (array_filter($files) as $f) { $f = $root . ltrim($f, '/'); if (is_file($f)) @unlink($f); }
    @unlink($root . 'cache/post_' . $id . '.html');
    require_once INCLUDES_PATH . '/stats.php';
    edu_stats_forget_post($pdo, $id);
    admin_log('post_delete', 'Deleted Post: ' . mb_strimwidth($post['title'], 0, 50, '...') . " (ID: $id)");
    return true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? []))));
    $action = $_POST['bulk'] ?? '';
    $done = 0;
    if ($action === 'delete') {
        foreach ($ids as $id) { try { if (edu_delete_post($pdo, $id, $ownSql)) $done++; } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); error_log($e->getMessage()); } }
        admin_flash($done . ' post' . ($done === 1 ? '' : 's') . ' deleted.', $done ? 'success' : 'error');
    } elseif (in_array($action, ['published', 'draft', 'archived'], true) && $ids && $me['role'] !== 'author') {
        $in = implode(',', $ids);
        $done = $pdo->exec("UPDATE posts SET status = " . $pdo->quote($action) . ", updated_at = NOW() WHERE id IN ($in)");
        foreach ($ids as $id) @unlink(rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/cache/post_' . $id . '.html');
        foreach (['latest-updates*.html', 'latest-jobs-*.html'] as $__cg) @array_map('unlink', glob(rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/cache/' . $__cg) ?: []);
        @array_map('unlink', glob(rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/cache/index_*.html') ?: []);
        if (function_exists('apcu_delete') && class_exists('APCUIterator') && apcu_enabled()) @apcu_delete(new APCUIterator('/^total_published_posts_cat_/')); // category post counts
        admin_log('post_bulk_status', "Set $done post(s) to $action");
        admin_flash($done . ' post' . ($done === 1 ? '' : 's') . ' set to ' . $action . '.');
    }
    header('Location: /admin/blogs-manager.php?' . http_build_query(array_intersect_key($_GET, array_flip(['status', 'category', 'author', 'state', 'per_page', 'date', 'search', 'page']))));
    exit;
}

$status = in_array($_GET['status'] ?? '', ['published', 'draft', 'archived'], true) ? $_GET['status'] : 'all';
$cat = (int)($_GET['category'] ?? 0);
$author = (int)($_GET['author'] ?? 0);
$state = (int)($_GET['state'] ?? 0);
$per = (int)($_GET['per_page'] ?? 20);
if (!in_array($per, [10, 20, 50, 100], true)) $per = 20;
$date = in_array($_GET['date'] ?? '', ['today', '7', '30', 'month'], true) ? $_GET['date'] : '';
$search = trim((string)($_GET['search'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));

$where = "WHERE 1 $ownSql";
$params = [];
if ($cat) { $where .= " AND (p.category_id = ? OR p.id IN (SELECT post_id FROM post_categories WHERE category_id = ?))"; $params[] = $cat; $params[] = $cat; }
if ($author) { $where .= " AND p.author_id = ?"; $params[] = $author; }
if ($state) { $where .= " AND p.state_id = ?"; $params[] = $state; }
if ($date === 'today') $where .= " AND DATE(p.date) = CURDATE()";
elseif ($date === '7' || $date === '30') $where .= " AND p.date >= NOW() - INTERVAL " . (int)$date . " DAY";
elseif ($date === 'month') $where .= " AND YEAR(p.date) = YEAR(CURDATE()) AND MONTH(p.date) = MONTH(CURDATE())";
if ($search !== '') { $where .= " AND (p.title LIKE ? OR a.name LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }

$cnt = $pdo->prepare("SELECT p.status, COUNT(*) c FROM posts p LEFT JOIN authors a ON a.id = p.author_id $where GROUP BY p.status");
$cnt->execute($params);
$counts = ['all' => 0, 'published' => 0, 'draft' => 0, 'archived' => 0];
foreach ($cnt->fetchAll(PDO::FETCH_ASSOC) as $r) { $counts[$r['status']] = (int)$r['c']; $counts['all'] += (int)$r['c']; }
if ($status !== 'all') { $where .= " AND p.status = ?"; $params[] = $status; }
$total = $counts[$status];
$pages = max(1, (int)ceil($total / $per));
$page = min($page, $pages);
$st = $pdo->prepare("SELECT p.id, p.title, p.slug, p.status, p.date, p.updated_at, a.name AS author_name, c.name AS cat_name, COALESCE(v.views, 0) AS views,
        m.file_path, m.responsive_set
    FROM posts p LEFT JOIN authors a ON a.id = p.author_id LEFT JOIN categories c ON c.id = p.category_id
    LEFT JOIN post_views v ON v.post_id = p.id LEFT JOIN media m ON m.id = p.featured_image_id
    $where ORDER BY p.date DESC LIMIT $per OFFSET " . (($page - 1) * $per));
$st->execute($params);
$posts = $st->fetchAll(PDO::FETCH_ASSOC);
$pending = json_decode((string)@file_get_contents(ROOT_PATH . '/cj_smart_cache/blog_views.json'), true) ?: [];
require_once INCLUDES_PATH . '/stats.php';
$adj = edu_adjust_factor($pdo, $me, '2000-01-01', date('Y-m-d'));

$categories = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$authors = $pdo->query("SELECT id, name FROM authors ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$states = $pdo->query("SELECT id, state_name FROM states ORDER BY state_name")->fetchAll(PDO::FETCH_ASSOC);
$qs = fn(array $over) => '?' . http_build_query(array_filter(array_merge(['status' => $status !== 'all' ? $status : null, 'category' => $cat ?: null, 'author' => $author ?: null, 'state' => $state ?: null, 'per_page' => $per !== 20 ? $per : null, 'date' => $date ?: null, 'search' => $search ?: null], $over), fn($v) => $v !== null && $v !== ''));
$thumb = function ($p) { if (!$p['file_path']) return ''; $s = json_decode($p['responsive_set'] ?? '', true) ?: []; return '/' . ltrim($s['sm'] ?? $p['file_path'], '/'); };
$canPush = admin_can('push_notifications.send');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title>All Posts - <?= htmlspecialchars(edu_site_name()) ?></title>
<?php admin_shell_head(); ?>
<style>.post-thumb.ph,.mpc-thumb.ph{display:inline-grid;place-items:center;background:#e5e7eb;color:#9ca3af}.bulk-bar{display:none;align-items:center;gap:10px;flex-wrap:wrap;margin:0 0 12px;padding:10px 14px;border:1px solid var(--primary);border-radius:10px;background:var(--primary-light,#ede9fe)}.bulk-bar.show{display:flex}.bulk-bar select{padding:6px 10px;border:1px solid #d1d5db;border-radius:6px}.pg{display:flex;gap:6px;justify-content:center;flex-wrap:wrap;margin:16px 0}.pg a,.pg span{padding:6px 12px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;font-size:.85rem}.pg a.active{background:var(--primary);color:#fff;border-color:var(--primary)}.ra-delete{color:#dc2626}.pt-meta-cat{font-size:.75rem;color:var(--gray-500,#6b7280)}</style>
</head>
<body>
<?php admin_shell_open('All Posts', 'Manage all your blog posts'); ?>
<?= admin_flash() ?>
<div>
  <div class="allposts-header"><h2>Posts</h2><div class="allposts-header-actions"><a class="btn-add-post" href="/admin/post-manager.php"><span class="add-post-icon"><i class="fas fa-plus"></i></span> Add New Post</a></div></div>
  <div class="post-status-tabs">
    <?php foreach (['all' => 'All', 'published' => 'Published', 'draft' => 'Drafts', 'archived' => 'Archived'] as $k => $l): ?><a class="pst-link<?= $status === $k ? ' active' : '' ?>" href="<?= $qs(['status' => $k === 'all' ? null : $k, 'page' => null]) ?>"><?= $l ?> <span class="pst-count"><?= $counts[$k] ?></span></a><?php endforeach; ?>
  </div>
  <div class="posts-toolbar-card"><form method="GET">
    <?php if ($status !== 'all'): ?><input type="hidden" name="status" value="<?= $status ?>"><?php endif; ?>
    <div class="pt-filters">
      <div class="pt-select-wrap"><i class="fas fa-layer-group pt-select-icon"></i><select name="category" class="pt-select" onchange="this.form.submit()"><option value="">All Categories</option><?php foreach ($categories as $c): ?><option value="<?= $c['id'] ?>" <?= $cat === (int)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option><?php endforeach; ?></select></div>
      <?php if (!$isAuthor): ?><div class="pt-select-wrap"><i class="fas fa-user pt-select-icon"></i><select name="author" class="pt-select" onchange="this.form.submit()"><option value="">All Authors</option><?php foreach ($authors as $a): ?><option value="<?= $a['id'] ?>" <?= $author === (int)$a['id'] ? 'selected' : '' ?>><?= htmlspecialchars($a['name']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
      <div class="pt-select-wrap"><i class="fas fa-map-location-dot pt-select-icon"></i><select name="state" class="pt-select" onchange="this.form.submit()"><option value="">All States</option><?php foreach ($states as $s): ?><option value="<?= $s['id'] ?>" <?= $state === (int)$s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['state_name']) ?></option><?php endforeach; ?></select></div>
      <div class="pt-select-wrap"><i class="fas fa-list-ol pt-select-icon"></i><select name="per_page" class="pt-select" onchange="this.form.submit()"><?php foreach ([10, 20, 50, 100] as $n): ?><option value="<?= $n ?>" <?= $per === $n ? 'selected' : '' ?>><?= $n ?> per page</option><?php endforeach; ?></select></div>
      <div class="pt-select-wrap"><i class="fas fa-calendar-day pt-select-icon"></i><select name="date" class="pt-select" onchange="this.form.submit()"><?php foreach (['' => 'Any date', 'today' => 'Today', '7' => 'Last 7 days', '30' => 'Last 30 days', 'month' => 'This month'] as $k => $l): ?><option value="<?= $k ?>" <?= $date === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    </div>
    <div class="pt-search-wrap"><input type="text" class="pt-input" placeholder="Search posts…" name="search" value="<?= htmlspecialchars($search) ?>"><button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Search</button></div>
  </form></div>

  <form method="POST" id="bulk-form" action="<?= htmlspecialchars($qs([])) ?>">
    <?= csrf_field() ?>
    <div class="bulk-bar" id="bulk-bar"><strong id="bulk-n">0 selected</strong>
      <select name="bulk" id="bulk-action"><option value="">Bulk action…</option><?php if (!$isAuthor): ?><option value="published">Publish</option><option value="draft">Move to Drafts</option><option value="archived">Archive</option><?php endif; ?><option value="delete">Delete</option></select>
      <button type="submit" class="btn btn-primary btn-sm">Apply</button><button type="button" class="btn btn-secondary btn-sm" id="bulk-clear">Clear</button></div>
    <?php if (!$posts): ?>
    <div class="table-card"><div style="padding:40px;text-align:center;color:var(--gray-500)"><i class="fas fa-file-circle-question" style="font-size:2rem;display:block;margin-bottom:8px"></i>No posts found.</div></div>
    <?php else: ?>
    <div class="table-card"><div class="table-responsive"><table class="data-table">
      <thead><tr><th style="width:36px"><input type="checkbox" id="check-all"></th><th>Title</th><th>Author</th><th>Status</th><th>Views</th><th>Date</th></tr></thead>
      <tbody>
      <?php foreach ($posts as $p): $url = postUrl($p['slug'], $p['id']); $views = edu_adjust((int)$p['views'] + (int)($pending[$p['id']] ?? 0), $adj); $th = $thumb($p); ?>
        <tr>
          <td><input type="checkbox" name="ids[]" value="<?= (int)$p['id'] ?>" class="row-check"></td>
          <td class="dt-title-td"><div class="dt-title-row"><?php if ($th): ?><img src="<?= htmlspecialchars($th) ?>" alt="" class="post-thumb" loading="lazy"><?php else: ?><span class="post-thumb ph"><i class="fas fa-image"></i></span><?php endif; ?><div class="dt-title-info"><a class="pt-title" href="/admin/post-manager.php?id=<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['title']) ?></a><div class="pt-author"><?= htmlspecialchars($p['cat_name'] ?? '') ?></div></div></div>
            <div class="row-actions"><a class="ra-link ra-edit" href="/admin/post-manager.php?id=<?= (int)$p['id'] ?>"><i class="fas fa-pen"></i> Edit</a><a target="_blank" class="ra-link ra-view" href="<?= htmlspecialchars($url) ?>"><i class="fas fa-eye"></i> View</a><?php if ($canPush && $p['status'] === 'published'): ?><a class="ra-link ra-view" href="/push_notifications/push-manager.php?post=<?= (int)$p['id'] ?>"><i class="fas fa-bell"></i> Push</a><?php endif; ?><button type="button" class="ra-link ra-delete" data-del="<?= (int)$p['id'] ?>" data-title="<?= htmlspecialchars($p['title']) ?>"><i class="fas fa-trash"></i> Delete</button></div></td>
          <td class="text-muted"><?= htmlspecialchars($p['author_name'] ?? '—') ?></td>
          <td><span class="badge badge-<?= htmlspecialchars($p['status']) ?>"><?= htmlspecialchars($p['status']) ?></span></td>
          <td class="text-muted"><i class="fas fa-eye" style="font-size:.7rem;margin-right:3px"></i><?= number_format($views) ?></td>
          <td class="text-muted" style="white-space:nowrap"><i class="fas fa-calendar-check" style="font-size:.7rem;margin-right:3px"></i><?= date('j M Y', strtotime($p['date'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div></div>
    <div class="mobile-post-list">
      <?php foreach ($posts as $p): $url = postUrl($p['slug'], $p['id']); $views = edu_adjust((int)$p['views'] + (int)($pending[$p['id']] ?? 0), $adj); $th = $thumb($p); ?>
      <div class="mobile-post-card"><div class="mpc-top"><input type="checkbox" class="mpc-bulk-check row-check-m" value="<?= (int)$p['id'] ?>"><?php if ($th): ?><img src="<?= htmlspecialchars($th) ?>" alt="" class="mpc-thumb" loading="lazy"><?php else: ?><span class="mpc-thumb ph"><i class="fas fa-image"></i></span><?php endif; ?>
        <div class="mpc-info"><a class="pt-title" href="/admin/post-manager.php?id=<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['title']) ?></a><div class="mpc-meta-row"><span class="mpc-author"><?= htmlspecialchars($p['author_name'] ?? '') ?></span><span class="badge badge-<?= htmlspecialchars($p['status']) ?>"><?= htmlspecialchars($p['status']) ?></span><span class="mpc-views"><i class="fas fa-eye"></i> <?= number_format($views) ?></span></div></div></div>
        <div class="mpc-actions"><a class="ra-link ra-edit" href="/admin/post-manager.php?id=<?= (int)$p['id'] ?>"><i class="fas fa-pen"></i> Edit</a><a target="_blank" class="ra-link ra-view" href="<?= htmlspecialchars($url) ?>"><i class="fas fa-eye"></i> View</a><button type="button" class="ra-link ra-delete" data-del="<?= (int)$p['id'] ?>" data-title="<?= htmlspecialchars($p['title']) ?>"><i class="fas fa-trash"></i> Delete</button></div></div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </form>
  <?php if ($pages > 1): ?><div class="pg"><?php if ($page > 1): ?><a href="<?= $qs(['page' => $page - 1]) ?>">« Prev</a><?php endif; ?><?php for ($i = max(1, $page - 3); $i <= min($pages, $page + 3); $i++): ?><a class="<?= $i === $page ? 'active' : '' ?>" href="<?= $qs(['page' => $i]) ?>"><?= $i ?></a><?php endfor; ?><?php if ($page < $pages): ?><a href="<?= $qs(['page' => $page + 1]) ?>">Next »</a><?php endif; ?></div><?php endif; ?>
</div>
<?php admin_shell_close(); ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var form = document.getElementById('bulk-form'), bar = document.getElementById('bulk-bar');
  function checked() { return Array.from(form.querySelectorAll('.row-check:checked')).map(function (c) { return c.value; }); }
  function sync() { var n = checked().length; bar.classList.toggle('show', n > 0); document.getElementById('bulk-n').textContent = n + ' selected'; }
  form.querySelectorAll('.row-check').forEach(function (c) { c.addEventListener('change', sync); });
  form.querySelectorAll('.row-check-m').forEach(function (m) { m.addEventListener('change', function () { var d = form.querySelector('.row-check[value="' + m.value + '"]'); if (d) { d.checked = m.checked; sync(); } }); });
  var all = document.getElementById('check-all'); if (all) all.onchange = function () { form.querySelectorAll('.row-check,.row-check-m').forEach(function (c) { c.checked = all.checked; }); sync(); };
  document.getElementById('bulk-clear').onclick = function () { form.querySelectorAll('input[type=checkbox]').forEach(function (c) { c.checked = false; }); sync(); };
  form.addEventListener('submit', function (e) {
    var a = document.getElementById('bulk-action').value, n = checked().length;
    if (!a || !n) { e.preventDefault(); alert('Choose posts and an action first.'); return; }
    if (a === 'delete' && !confirm('Delete ' + n + ' post' + (n > 1 ? 's' : '') + '? This cannot be undone.')) e.preventDefault();
  });
  document.querySelectorAll('[data-del]').forEach(function (b) { b.onclick = function () {
    if (!confirm('Delete "' + b.dataset.title + '"?\n\nThis cannot be undone.')) return;
    form.querySelectorAll('input[type=checkbox]').forEach(function (c) { c.checked = false; });
    var d = form.querySelector('.row-check[value="' + b.dataset.del + '"]'); d.checked = true;
    document.getElementById('bulk-action').value = 'delete'; form.submit();
  }; });
});
</script>
</body>
</html>
