<?php
require_once __DIR__ . '/components/guard.php';
require_once INCLUDES_PATH . '/pages.php';
$me = admin_guard(['admin', 'editor']);
edu_pages_table($pdo);
// The site's built-in pages (About Us, Contact Us, policies) become normal Pages the first time this list opens.
$__made = edu_builtin_convert($pdo, (int)$me['id']);
if ($__made) { admin_log('page_save', 'Moved built-in pages into Pages: ' . implode(', ', $__made)); edu_clear_page_cache(); admin_flash('These built-in pages are now normal Pages you can edit: ' . implode(', ', $__made) . '.'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    if (($_POST['action'] ?? '') === 'delete' && $id) {
        $st = $pdo->prepare("SELECT title, slug FROM site_pages WHERE id = ?"); $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: ['title' => '', 'slug' => ''];
        $pdo->prepare("DELETE FROM site_pages WHERE id = ?")->execute([$id]);
        edu_page_stub_remove((string)$row['slug']);
        admin_log('page_delete', 'Deleted page: ' . mb_strimwidth((string)$row['title'], 0, 60, '…'));
        admin_flash('Page deleted.');
    }
    header('Location: /admin/pages-list.php');
    exit;
}
$status = in_array($_GET['status'] ?? '', ['published', 'draft'], true) ? $_GET['status'] : 'all';
$search = trim((string)($_GET['search'] ?? ''));
$counts = ['all' => 0, 'published' => 0, 'draft' => 0];
foreach ($pdo->query("SELECT status, COUNT(*) c FROM site_pages GROUP BY status") as $r) { $counts[$r['status']] = (int)$r['c']; $counts['all'] += (int)$r['c']; }
$sql = "SELECT p.*, u.username FROM site_pages p LEFT JOIN users u ON u.id = p.author_user_id WHERE 1";
$params = [];
if ($status !== 'all') { $sql .= " AND p.status = ?"; $params[] = $status; }
if ($search !== '') { $sql .= " AND p.title LIKE ?"; $params[] = "%$search%"; }
$st = $pdo->prepare($sql . " ORDER BY p.updated_at DESC");
$st->execute($params);
$pages = $st->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title>Pages - <?= htmlspecialchars(edu_site_name()) ?></title>
<?php admin_shell_head(); ?>
</head>
<body>
<?php admin_shell_open('Pages', 'Static pages like About, Contact or any page you create'); ?>
<?= admin_flash() ?>
<div class="allposts-header"><h2>Pages</h2><div class="allposts-header-actions"><a class="btn-add-post" href="/admin/page-editor.php"><span class="add-post-icon"><i class="fas fa-plus"></i></span> Add New Page</a></div></div>
<div class="post-status-tabs"><?php foreach (['all' => 'All', 'published' => 'Published', 'draft' => 'Drafts'] as $k => $l): ?><a class="pst-link<?= $status === $k ? ' active' : '' ?>" href="?<?= $k === 'all' ? '' : 'status=' . $k ?>"><?= $l ?> <span class="pst-count"><?= $counts[$k] ?></span></a><?php endforeach; ?></div>
<div class="posts-toolbar-card"><form method="GET"><?php if ($status !== 'all'): ?><input type="hidden" name="status" value="<?= $status ?>"><?php endif; ?><div class="pt-search-wrap" style="margin-left:auto"><input type="text" class="pt-input" placeholder="Search pages…" name="search" value="<?= htmlspecialchars($search) ?>"><button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Search</button></div></form></div>
<div class="table-card"><div class="table-responsive"><table class="data-table">
  <thead><tr><th>Title</th><th>Address</th><th>Status</th><th>Updated</th></tr></thead>
  <tbody>
  <?php if (!$pages): ?><tr><td colspan="4" style="text-align:center;padding:30px;color:var(--gray-500)">No pages yet. Click “Add New Page” to create one.</td></tr><?php endif; ?>
  <?php foreach ($pages as $p): ?>
    <tr><td class="dt-title-td"><a class="pt-title" href="/admin/page-editor.php?id=<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['title']) ?></a><?php if ($p['username']): ?><div class="pt-author">by <?= htmlspecialchars($p['username']) ?></div><?php endif; ?>
      <div class="row-actions"><a class="ra-link ra-edit" href="/admin/page-editor.php?id=<?= (int)$p['id'] ?>"><i class="fas fa-pen"></i> Edit</a><a class="ra-link ra-view" target="_blank" href="/<?= htmlspecialchars($p['slug']) ?>"><i class="fas fa-eye"></i> View</a><form method="POST" style="display:inline" onsubmit="return confirm('Delete this page?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button type="submit" class="ra-link ra-delete" style="color:#dc2626"><i class="fas fa-trash"></i> Delete</button></form></div></td>
      <td class="text-muted">/<?= htmlspecialchars($p['slug']) ?></td>
      <td><span class="badge badge-<?= $p['status'] ?>"><?= $p['status'] ?></span></td>
      <td class="text-muted"><?= date('j M Y', strtotime($p['updated_at'])) ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div></div>
<?php admin_shell_close(); ?>
</body>
</html>
