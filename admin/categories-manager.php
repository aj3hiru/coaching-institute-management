<?php
// Category Manager — create, edit and delete categories (with their SEO title/description). One category is the
// default: posts saved without a category go there, and posts of a deleted category move there. It cannot be deleted.
require_once __DIR__ . '/components/guard.php';
require_once INCLUDES_PATH . '/taxonomy.php';
$me = admin_guard(['admin', 'editor', 'author', 'teacher', 'accountant']);
if (!admin_can('blogs.manage_categories')) { http_response_code(403); exit('Access Denied'); }
$defaultId = edu_default_category($pdo);

function cat_slug(string $s): string
{
    return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($s)), '-');
}
function cat_unique_slug(PDO $pdo, string $base, int $except): string
{
    $base = $base !== '' ? $base : 'category';
    for ($i = 1; ; $i++) {
        $try = $i === 1 ? $base : "$base-$i";
        $st = $pdo->prepare("SELECT COUNT(*) FROM categories WHERE slug = ? AND id <> ?");
        $st->execute([$try, $except]);
        if (!$st->fetchColumn()) return $try;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    $back = '/admin/categories-manager.php';
    try {
        if ($act === 'save') {
            $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 100);
            if ($name === '') throw new RuntimeException('Please enter the category name.');
            $slug = cat_unique_slug($pdo, cat_slug(trim((string)($_POST['slug'] ?? '')) ?: $name), $id);
            $mt = mb_substr(trim((string)($_POST['meta_title'] ?? '')), 0, 255) ?: null;
            $md = trim((string)($_POST['meta_description'] ?? '')) ?: null;
            $mk = trim((string)($_POST['meta_keywords'] ?? '')) ?: null;
            if ($id) {
                $pdo->prepare("UPDATE categories SET name = ?, slug = ?, meta_title = ?, meta_description = ?, meta_keywords = ? WHERE id = ?")->execute([$name, $slug, $mt, $md, $mk, $id]);
                admin_log('category_update', "Updated category: $name (#$id)");
                admin_flash("Category \"$name\" saved.");
            } else {
                $pdo->prepare("INSERT INTO categories (name, slug, meta_title, meta_description, meta_keywords) VALUES (?, ?, ?, ?, ?)")->execute([$name, $slug, $mt, $md, $mk]);
                $id = (int)$pdo->lastInsertId();
                admin_log('category_create', "Created category: $name (#$id)");
                admin_flash("Category \"$name\" created.");
            }
            if (!empty($_POST['make_default']) && $id !== $defaultId) {
                edu_settings_save('content', ['default_category' => $id]);
                admin_log('category_update', "Default category set to: $name (#$id)");
            }
            edu_clear_page_cache();
        } elseif ($act === 'delete' && $id) {
            if ($id === $defaultId) throw new RuntimeException('The default category cannot be deleted. Make another category the default first.');
            $st = $pdo->prepare("SELECT name FROM categories WHERE id = ?");
            $st->execute([$id]);
            $name = $st->fetchColumn();
            if ($name === false) throw new RuntimeException('This category no longer exists.');
            $pdo->beginTransaction();
            $moved = $pdo->prepare("UPDATE posts SET category_id = ? WHERE category_id = ?");
            $moved->execute([$defaultId, $id]);
            $n = $moved->rowCount();
            $pdo->prepare("DELETE FROM post_categories WHERE category_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM categories WHERE id = ?")->execute([$id]);
            $pdo->commit();
            edu_clear_page_cache();
            admin_log('category_delete', "Deleted category: $name (#$id)" . ($n ? " — $n post(s) moved to the default category" : ''));
            admin_flash("Category \"$name\" deleted" . ($n ? " — $n post(s) moved to the default category." : '.'));
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        admin_flash($e instanceof RuntimeException ? $e->getMessage() : 'Could not save: ' . $e->getMessage(), 'error');
        if ($act === 'save' && $id) $back .= '?edit=' . $id;
    }
    header('Location: ' . $back);
    exit;
}

$q = trim((string)($_GET['search'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$per = 20;
$where = $q !== '' ? 'WHERE c.name LIKE ? OR c.slug LIKE ?' : '';
$args = $q !== '' ? ["%$q%", "%$q%"] : [];
$st = $pdo->prepare("SELECT COUNT(*) FROM categories c $where");
$st->execute($args);
$total = (int)$st->fetchColumn();
$pages = max(1, (int)ceil($total / $per));
$page = min($page, $pages);
$st = $pdo->prepare("SELECT c.*, (SELECT COUNT(DISTINCT p.id) FROM posts p LEFT JOIN post_categories pc ON pc.post_id = p.id WHERE p.category_id = c.id OR pc.category_id = c.id) posts
    FROM categories c $where ORDER BY c.id = $defaultId DESC, c.name LIMIT $per OFFSET " . (($page - 1) * $per));
$st->execute($args);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
$editing = null;
if (!empty($_GET['edit'])) {
    $e = $pdo->prepare("SELECT * FROM categories WHERE id = ?");
    $e->execute([(int)$_GET['edit']]);
    $editing = $e->fetch(PDO::FETCH_ASSOC) ?: null;
}
$stats = $pdo->query("SELECT COUNT(*) total, SUM(meta_title IS NOT NULL AND meta_title <> '' AND meta_description IS NOT NULL AND meta_description <> '') seo, COALESCE(SUM(views), 0) views FROM categories")->fetch(PDO::FETCH_ASSOC);
$esc = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$v = fn($k) => $esc($editing[$k] ?? '');
$link = fn(array $o) => '/admin/categories-manager.php' . (($p = http_build_query(array_filter(array_merge(['search' => $q, 'page' => $page > 1 ? $page : ''], $o), fn($x) => $x !== '' && $x !== null))) ? "?$p" : '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title>Categories - <?= $esc(edu_site_name()) ?></title>
<?php admin_shell_head(); ?>
<link rel="stylesheet" href="/assets/css/admin-tax.css?v=<?= @filemtime(ROOT_PATH . '/assets/css/admin-tax.css') ?>">
</head>
<body>
<?php admin_shell_open('Categories', 'Create, edit and organize post categories'); ?>
<?= admin_flash() ?>
<div class="tx-stats">
  <div class="tx-stat"><small>Total Categories</small><b><?= (int)$stats['total'] ?></b></div>
  <div class="tx-stat"><small>SEO Complete</small><b style="color:#16a34a"><?= (int)$stats['seo'] ?></b></div>
  <div class="tx-stat"><small>Missing SEO</small><b style="color:#d97706"><?= (int)$stats['total'] - (int)$stats['seo'] ?></b></div>
  <div class="tx-stat"><small>Page Views</small><b style="color:#2563eb"><?= formatViews((int)$stats['views']) ?></b></div>
</div>
<div class="tx-grid">
  <div class="tx-card" id="txForm">
    <div class="tx-card-h"><span class="ico"><i class="fas <?= $editing ? 'fa-pen' : 'fa-plus' ?>"></i></span><h2><?= $editing ? 'Edit Category' : 'New Category' ?></h2><?php if ($editing): ?><a href="/admin/categories-manager.php" class="btn btn-secondary btn-sm">Cancel</a><?php endif; ?></div>
    <form method="POST" class="tx-form">
      <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
      <div class="form-group"><label>Name <small>required</small></label><input type="text" name="name" class="form-control" required maxlength="100" value="<?= $v('name') ?>" placeholder="e.g. Government Jobs" id="txName"></div>
      <div class="form-group"><label>Slug <small>made from the name if empty</small></label><input type="text" name="slug" class="form-control" maxlength="150" value="<?= $v('slug') ?>" placeholder="government-jobs" id="txSlug"><div class="tx-note"><?= $esc(rtrim(SITE_URL, '/')) ?>/categories/<b id="txSlugPrev"><?= $v('slug') ?: '…' ?></b></div></div>
      <div class="form-group"><label>SEO Title <small>shown in Google</small><span class="tx-count" data-for="mt">0/60</span></label><input type="text" name="meta_title" class="form-control" maxlength="255" value="<?= $v('meta_title') ?>" placeholder="e.g. Latest Government Jobs 2026 — Notifications &amp; Vacancies" data-count="mt"></div>
      <div class="form-group"><label>SEO Description <span class="tx-count" data-for="md">0/160</span></label><textarea name="meta_description" class="form-control" rows="3" placeholder="One or two sentences that describe this category for search results" data-count="md"><?= $v('meta_description') ?></textarea></div>
      <div class="form-group"><label>Keywords <small>comma separated</small></label><input type="text" name="meta_keywords" class="form-control" value="<?= $v('meta_keywords') ?>" placeholder="govt jobs, sarkari naukri, vacancy"></div>
      <?php if (!$editing || (int)$editing['id'] !== $defaultId): ?><label class="tx-check"><input type="checkbox" name="make_default" value="1"> Make this the default category</label>
      <?php else: ?><div class="alert alert-info tx-defnote"><i class="fas fa-shield"></i> <span>This is the default category — posts saved without a category go here. It can be renamed but not deleted.</span></div><?php endif; ?>
      <button type="submit" class="btn btn-primary" style="width:100%"><i class="fas <?= $editing ? 'fa-save' : 'fa-plus-circle' ?>"></i> <?= $editing ? 'Save Changes' : 'Create Category' ?></button>
    </form>
  </div>
  <div>
    <form class="tx-card tx-search" method="GET"><input type="search" name="search" class="form-control" value="<?= $esc($q) ?>" placeholder="Search by name or slug…"><button class="btn btn-primary"><i class="fas fa-search"></i> Search</button><?php if ($q !== ''): ?><a class="btn btn-secondary" href="/admin/categories-manager.php" title="Clear search"><i class="fas fa-xmark"></i></a><?php endif; ?></form>
    <div class="tx-card">
      <div class="tx-list-h">All Categories <span>(<?= $total ?>)</span></div>
      <div class="table-responsive"><table>
        <thead><tr><th class="tx-hide">#</th><th>Name / Slug</th><th>Posts</th><th class="tx-hide">SEO</th><th class="tx-hide">Views</th><th>Actions</th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="6"><div class="empty-state"><i class="fas fa-folder-open"></i><p><?= $q !== '' ? 'No categories match your search.' : 'No categories yet.' ?></p></div></td></tr><?php endif; ?>
        <?php foreach ($rows as $i => $c): $def = (int)$c['id'] === $defaultId; $seo = trim((string)$c['meta_title']) !== '' && trim((string)$c['meta_description']) !== ''; ?>
        <tr class="<?= $def ? 'tx-row-def' : '' ?>">
          <td class="tx-hide tx-num"><?= ($page - 1) * $per + $i + 1 ?></td>
          <td class="tx-name"><b><?= $esc($c['name']) ?><?php if ($def): ?><span class="tx-def"><i class="fas fa-shield"></i> Default</span><?php endif; ?></b><span class="tx-slug"><?= $esc($c['slug']) ?></span></td>
          <td><a class="tx-posts" href="/admin/blogs-manager.php?category=<?= (int)$c['id'] ?>"><?= (int)$c['posts'] ?></a></td>
          <td class="tx-hide"><span class="tx-seo <?= $seo ? 'ok' : 'no' ?>"><i class="fas <?= $seo ? 'fa-check' : 'fa-minus' ?>"></i> <?= $seo ? 'Complete' : 'Missing' ?></span></td>
          <td class="tx-hide"><?= formatViews((int)$c['views']) ?></td>
          <td><div class="tx-acts">
            <a class="btn-action btn-edit" href="/categories/<?= $esc($c['slug']) ?>" target="_blank" rel="noopener" title="View on site"><i class="fas fa-eye"></i></a>
            <a class="btn-action btn-edit" href="<?= $esc($link(['edit' => (int)$c['id']])) ?>#txForm"><i class="fas fa-edit"></i> <span>Edit</span></a>
            <?php if ($def): ?><button type="button" class="btn-action btn-edit tx-locked" title="The default category cannot be deleted"><i class="fas fa-lock"></i></button>
            <?php else: ?><form method="POST" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn-action btn-delete" title="Delete" data-confirm="Delete the category “<?= $esc($c['name']) ?>”?<?= (int)$c['posts'] ? ' Its ' . (int)$c['posts'] . ' post(s) move to the default category.' : '' ?>" data-confirm-title="Delete category" data-confirm-ok="Delete"><i class="fas fa-trash"></i></button></form><?php endif; ?>
          </div></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php if ($pages > 1): ?><div class="tx-pages"><?php for ($n = 1; $n <= $pages; $n++): ?><a class="<?= $n === $page ? 'cur' : '' ?>" href="<?= $esc($link(['page' => $n > 1 ? $n : ''])) ?>"><?= $n ?></a><?php endfor; ?></div><?php endif; ?>
    </div>
  </div>
</div>
<?php admin_shell_close(); ?>
<script>
(function () {
  var name = document.getElementById('txName'), slug = document.getElementById('txSlug'), prev = document.getElementById('txSlugPrev');
  function s(v) { return (v || '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, ''); }
  function upd() { prev.textContent = s(slug.value) || s(name.value) || '…'; }
  name.addEventListener('input', upd); slug.addEventListener('input', upd);
  document.querySelectorAll('[data-count]').forEach(function (f) {
    var c = document.querySelector('[data-for="' + f.dataset.count + '"]'), max = f.dataset.count === 'mt' ? 60 : 160;
    function u() { c.textContent = f.value.length + '/' + max; c.style.color = f.value.length > max ? '#dc2626' : ''; }
    f.addEventListener('input', u); u();
  });
})();
</script>
</body>
</html>
