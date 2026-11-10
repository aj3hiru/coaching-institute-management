<?php
// Tag Manager — create, edit, switch off and delete tags, and attach a tag to posts.
require_once __DIR__ . '/components/guard.php';
$me = admin_guard(['admin', 'editor', 'author']);
if (!admin_can('blogs.manage_tags')) { http_response_code(403); exit('Access Denied'); }

function tag_slug(string $s): string
{
    return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($s)), '-');
}
function tag_unique_slug(PDO $pdo, string $base, int $except): string
{
    $base = $base !== '' ? $base : 'tag';
    for ($i = 1; ; $i++) {
        $try = $i === 1 ? $base : "$base-$i";
        $st = $pdo->prepare("SELECT COUNT(*) FROM tags WHERE slug = ? AND id <> ?");
        $st->execute([$try, $except]);
        if (!$st->fetchColumn()) return $try;
    }
}

// Post search for the "Attach to posts" box
if (($_GET['ajax'] ?? '') === 'posts') {
    header('Content-Type: application/json');
    $tid = (int)($_GET['tag'] ?? 0);
    $q = trim((string)($_GET['q'] ?? ''));
    $st = $pdo->prepare("SELECT p.id, p.title, p.status, EXISTS(SELECT 1 FROM post_tag pt WHERE pt.post_id = p.id AND pt.tag_id = ?) linked
        FROM posts p WHERE (? = '' OR p.title LIKE ? OR p.id = ?) ORDER BY linked DESC, p.date DESC LIMIT 30");
    $st->execute([$tid, $q, "%$q%", ctype_digit($q) ? (int)$q : 0]);
    echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    $ajax = !empty($_POST['ajax']);
    try {
        if ($act === 'save') {
            $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 100);
            if ($name === '') throw new RuntimeException('Please enter the tag name.');
            $slug = tag_unique_slug($pdo, tag_slug(trim((string)($_POST['slug'] ?? '')) ?: $name), $id);
            $active = !empty($_POST['is_active']) ? 1 : 0;
            if ($id) {
                $pdo->prepare("UPDATE tags SET name = ?, slug = ?, is_active = ? WHERE id = ?")->execute([$name, $slug, $active, $id]);
                admin_log('tag_update', "Updated tag: $name (#$id)");
                admin_flash("Tag \"$name\" saved.");
            } else {
                $pdo->prepare("INSERT INTO tags (name, slug, is_active) VALUES (?, ?, ?)")->execute([$name, $slug, $active]);
                admin_log('tag_create', "Created tag: $name");
                admin_flash("Tag \"$name\" created.");
            }
            edu_clear_page_cache();
        } elseif ($act === 'toggle' && $id) {
            $pdo->prepare("UPDATE tags SET is_active = ? WHERE id = ?")->execute([!empty($_POST['on']) ? 1 : 0, $id]);
            edu_clear_page_cache();
            if ($ajax) { header('Content-Type: application/json'); exit('{"ok":true}'); }
        } elseif ($act === 'link' && $id) {
            $pid = (int)($_POST['post_id'] ?? 0);
            if (!empty($_POST['on'])) $pdo->prepare("INSERT IGNORE INTO post_tag (post_id, tag_id) VALUES (?, ?)")->execute([$pid, $id]);
            else $pdo->prepare("DELETE FROM post_tag WHERE post_id = ? AND tag_id = ?")->execute([$pid, $id]);
            edu_clear_page_cache();
            $c = $pdo->prepare("SELECT COUNT(*) FROM post_tag WHERE tag_id = ?");
            $c->execute([$id]);
            header('Content-Type: application/json');
            exit(json_encode(['ok' => true, 'count' => (int)$c->fetchColumn()]));
        } elseif ($act === 'delete' && $id) {
            $st = $pdo->prepare("SELECT name FROM tags WHERE id = ?");
            $st->execute([$id]);
            $name = $st->fetchColumn();
            $pdo->prepare("DELETE FROM post_tag WHERE tag_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM tags WHERE id = ?")->execute([$id]);
            edu_clear_page_cache();
            admin_log('tag_delete', "Deleted tag: $name (#$id)");
            admin_flash("Tag \"$name\" deleted.");
        }
    } catch (Throwable $e) {
        if ($ajax) { header('Content-Type: application/json'); exit(json_encode(['ok' => false, 'error' => $e->getMessage()])); }
        admin_flash($e instanceof RuntimeException ? $e->getMessage() : 'Could not save: ' . $e->getMessage(), 'error');
    }
    header('Location: /admin/tag-manager.php' . ($act === 'save' && !empty($_POST['id']) && isset($e) ? '?edit=' . $id : ''));
    exit;
}

$q = trim((string)($_GET['search'] ?? ''));
$sort = in_array($_GET['sort'] ?? '', ['oldest', 'name', 'posts', 'views'], true) ? $_GET['sort'] : 'newest';
$order = ['newest' => 't.id DESC', 'oldest' => 't.id ASC', 'name' => 't.name ASC', 'posts' => 'posts DESC, t.name', 'views' => 't.views DESC, t.name'][$sort];
$page = max(1, (int)($_GET['page'] ?? 1));
$per = 20;
$where = $q !== '' ? 'WHERE t.name LIKE ? OR t.slug LIKE ?' : '';
$args = $q !== '' ? ["%$q%", "%$q%"] : [];
$st = $pdo->prepare("SELECT COUNT(*) FROM tags t $where");
$st->execute($args);
$total = (int)$st->fetchColumn();
$pages = max(1, (int)ceil($total / $per));
$page = min($page, $pages);
$st = $pdo->prepare("SELECT t.*, (SELECT COUNT(*) FROM post_tag pt WHERE pt.tag_id = t.id) posts FROM tags t $where ORDER BY $order LIMIT $per OFFSET " . (($page - 1) * $per));
$st->execute($args);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
$editing = null;
if (!empty($_GET['edit'])) {
    $e = $pdo->prepare("SELECT * FROM tags WHERE id = ?");
    $e->execute([(int)$_GET['edit']]);
    $editing = $e->fetch(PDO::FETCH_ASSOC) ?: null;
}
$stats = $pdo->query("SELECT COUNT(*) total, COALESCE(SUM(is_active), 0) active, COALESCE(SUM(views), 0) views, (SELECT COUNT(DISTINCT tag_id) FROM post_tag) used FROM tags")->fetch(PDO::FETCH_ASSOC);
$esc = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$v = fn($k) => $esc($editing[$k] ?? '');
$link = fn(array $o) => '/admin/tag-manager.php' . (($p = http_build_query(array_filter(array_merge(['search' => $q, 'sort' => $sort === 'newest' ? '' : $sort, 'page' => $page > 1 ? $page : ''], $o), fn($x) => $x !== '' && $x !== null))) ? "?$p" : '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title>Tags - <?= $esc(edu_site_name()) ?></title>
<?php admin_shell_head(); ?>
<link rel="stylesheet" href="/assets/css/admin-tax.css?v=<?= @filemtime(ROOT_PATH . '/assets/css/admin-tax.css') ?>">
<style>
.tg-search{display:flex;gap:10px;padding:16px 18px;margin-bottom:18px;flex-wrap:wrap}.tg-search input{flex:1;min-width:180px}.tg-search select{width:auto}
.tg-list{max-height:340px;overflow-y:auto;border:1px solid var(--gray-200);border-radius:10px;margin-top:12px}
.tg-post{display:flex;align-items:center;gap:10px;padding:10px 12px;border-bottom:1px solid var(--gray-100);cursor:pointer;font-size:.875rem}
.tg-post:last-child{border-bottom:0}.tg-post:hover{background:var(--gray-50,#f9fafb)}
.tg-post span{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.tg-post small{color:var(--gray-400);font-size:.72rem}
.tg-empty{padding:20px;text-align:center;color:var(--gray-500);font-size:.875rem}
</style>
</head>
<body>
<?php admin_shell_open('Tags', 'Create and manage post tags'); ?>
<?= admin_flash() ?>
<div class="tx-stats">
  <div class="tx-stat"><small>Total Tags</small><b><?= (int)$stats['total'] ?></b></div>
  <div class="tx-stat"><small>Active</small><b style="color:#16a34a"><?= (int)$stats['active'] ?></b></div>
  <div class="tx-stat"><small>Used on posts</small><b style="color:#7c3aed"><?= (int)$stats['used'] ?></b></div>
  <div class="tx-stat"><small>Page Views</small><b style="color:#2563eb"><?= formatViews((int)$stats['views']) ?></b></div>
</div>
<div class="tx-grid">
  <div class="tx-card" id="txForm">
    <div class="tx-card-h"><span class="ico"><i class="fas <?= $editing ? 'fa-pen' : 'fa-plus' ?>"></i></span><h2><?= $editing ? 'Edit Tag' : 'New Tag' ?></h2><?php if ($editing): ?><a href="/admin/tag-manager.php" class="btn btn-secondary btn-sm">Cancel</a><?php endif; ?></div>
    <form method="POST" class="tx-form">
      <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
      <div class="form-group"><label>Name <small>required</small></label><input type="text" name="name" class="form-control" required maxlength="100" value="<?= $v('name') ?>" placeholder="e.g. SSC CGL 2026" id="txName"></div>
      <div class="form-group"><label>Slug <small>made from the name if empty</small></label><input type="text" name="slug" class="form-control" maxlength="150" value="<?= $v('slug') ?>" placeholder="ssc-cgl-2026" id="txSlug"><div class="tx-note"><?= $esc(rtrim(SITE_URL, '/')) ?>/tag/<b id="txSlugPrev"><?= $v('slug') ?: '…' ?></b>-<?= $editing ? (int)$editing['id'] : 'id' ?></div></div>
      <label class="tx-check"><span class="tx-toggle"><input type="checkbox" name="is_active" value="1" <?= !$editing || $editing['is_active'] ? 'checked' : '' ?>><span></span></span> Active — shown on posts and has its own page</label>
      <button type="submit" class="btn btn-primary" style="width:100%"><i class="fas <?= $editing ? 'fa-save' : 'fa-plus-circle' ?>"></i> <?= $editing ? 'Save Changes' : 'Create Tag' ?></button>
    </form>
  </div>
  <div>
    <form class="tx-card tg-search" method="GET">
      <input type="search" name="search" class="form-control" value="<?= $esc($q) ?>" placeholder="Search tags…">
      <select name="sort" class="form-control" onchange="this.form.submit()"><?php foreach (['newest' => 'Newest first', 'oldest' => 'Oldest first', 'name' => 'Name A–Z', 'posts' => 'Most posts', 'views' => 'Most viewed'] as $k => $l): ?><option value="<?= $k ?>"<?= $sort === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
      <button class="btn btn-primary"><i class="fas fa-search"></i> Search</button><?php if ($q !== ''): ?><a class="btn btn-secondary" href="/admin/tag-manager.php" title="Clear search"><i class="fas fa-xmark"></i></a><?php endif; ?>
    </form>
    <div class="tx-card">
      <div class="tx-list-h">All Tags <span>(<?= $total ?>)</span></div>
      <div class="table-responsive"><table>
        <thead><tr><th>Name / Slug</th><th>Posts</th><th class="tx-hide">Views</th><th>Active</th><th>Actions</th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="5"><div class="empty-state"><i class="fas fa-tags"></i><p><?= $q !== '' ? 'No tags match your search.' : 'No tags yet.' ?></p></div></td></tr><?php endif; ?>
        <?php foreach ($rows as $t): ?>
        <tr>
          <td class="tx-name"><b><?= $esc($t['name']) ?></b><span class="tx-slug"><?= $esc($t['slug']) ?></span></td>
          <td><button type="button" class="btn-action btn-edit" data-link="<?= (int)$t['id'] ?>" data-name="<?= $esc($t['name']) ?>" title="Attach to posts"><i class="fas fa-link"></i> <b data-count="<?= (int)$t['id'] ?>"><?= (int)$t['posts'] ?></b></button></td>
          <td class="tx-hide"><?= formatViews((int)$t['views']) ?></td>
          <td><label class="tx-toggle" title="Active"><input type="checkbox" data-toggle="<?= (int)$t['id'] ?>" <?= $t['is_active'] ? 'checked' : '' ?>><span></span></label></td>
          <td><div class="tx-acts">
            <a class="btn-action btn-edit" href="/tag/<?= $esc($t['slug']) ?>-<?= (int)$t['id'] ?>" target="_blank" rel="noopener" title="View on site"><i class="fas fa-eye"></i></a>
            <a class="btn-action btn-edit" href="<?= $esc($link(['edit' => (int)$t['id']])) ?>#txForm"><i class="fas fa-edit"></i> <span>Edit</span></a>
            <form method="POST" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><button class="btn-action btn-delete" title="Delete" data-confirm="Delete the tag “<?= $esc($t['name']) ?>”? It is removed from <?= (int)$t['posts'] ?> post(s); the posts stay." data-confirm-title="Delete tag" data-confirm-ok="Delete"><i class="fas fa-trash"></i></button></form>
          </div></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php if ($pages > 1): ?><div class="tx-pages"><?php for ($n = 1; $n <= $pages; $n++): ?><a class="<?= $n === $page ? 'cur' : '' ?>" href="<?= $esc($link(['page' => $n > 1 ? $n : ''])) ?>"><?= $n ?></a><?php endfor; ?></div><?php endif; ?>
    </div>
  </div>
</div>

<div class="modal-overlay" id="tgModal"><div class="modal" style="max-width:560px">
  <div class="modal-header"><div class="modal-title"><i class="fas fa-link" style="color:var(--primary);margin-right:.5rem"></i> Attach “<span id="tgName"></span>” to posts</div><button type="button" class="modal-close" data-close><i class="fas fa-times"></i></button></div>
  <div class="modal-body"><input type="search" class="form-control" id="tgQ" placeholder="Search posts by title or ID…"><div class="tg-list" id="tgList"></div><p class="tx-note" style="margin-top:8px">Tick a post to add this tag to it, untick to remove it. Changes save at once.</p></div>
  <div class="modal-footer"><button type="button" class="btn btn-primary" data-close>Done</button></div>
</div></div>
<?php admin_shell_close(); ?>
<script>
(function () {
  var token = <?= json_encode(csrf_token(), JSON_HEX_TAG) ?>;
  function post(d) { var fd = new FormData(); fd.append('_csrf', token); fd.append('ajax', '1'); Object.keys(d).forEach(function (k) { fd.append(k, d[k]); }); return fetch('/admin/tag-manager.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }); }
  var name = document.getElementById('txName'), slug = document.getElementById('txSlug'), prev = document.getElementById('txSlugPrev');
  function s(v) { return (v || '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, ''); }
  function upd() { prev.textContent = s(slug.value) || s(name.value) || '…'; }
  name.addEventListener('input', upd); slug.addEventListener('input', upd);
  document.querySelectorAll('[data-toggle]').forEach(function (c) {
    c.addEventListener('change', function () { post({ action: 'toggle', id: c.dataset.toggle, on: c.checked ? 1 : '' }).then(function (d) { if (!d.ok) { c.checked = !c.checked; adminNotice(d.error || 'Could not save.', { type: 'error' }); } }); });
  });
  var m = document.getElementById('tgModal'), list = document.getElementById('tgList'), qi = document.getElementById('tgQ'), tag = 0, timer;
  document.body.appendChild(m);
  function esc(t) { var d = document.createElement('div'); d.textContent = t; return d.innerHTML; }
  function load() {
    list.innerHTML = '<div class="tg-empty"><i class="fas fa-spinner fa-spin"></i></div>';
    fetch('/admin/tag-manager.php?ajax=posts&tag=' + tag + '&q=' + encodeURIComponent(qi.value.trim()), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (rows) {
      list.innerHTML = rows.length ? rows.map(function (p) { return '<label class="tg-post"><input type="checkbox" data-pid="' + p.id + '"' + (+p.linked ? ' checked' : '') + '><span>' + esc(p.title) + '</span><small>#' + p.id + (p.status !== 'published' ? ' · ' + p.status : '') + '</small></label>'; }).join('') : '<div class="tg-empty">No posts found.</div>';
    });
  }
  list.addEventListener('change', function (e) {
    var c = e.target; if (!c.dataset.pid) return;
    post({ action: 'link', id: tag, post_id: c.dataset.pid, on: c.checked ? 1 : '' }).then(function (d) { if (d.ok) document.querySelector('[data-count="' + tag + '"]').textContent = d.count; else c.checked = !c.checked; });
  });
  qi.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(load, 250); });
  document.querySelectorAll('[data-link]').forEach(function (b) {
    b.addEventListener('click', function () { tag = b.dataset.link; document.getElementById('tgName').textContent = b.dataset.name; qi.value = ''; load(); m.classList.add('open'); setTimeout(function () { qi.focus(); }, 50); });
  });
  m.addEventListener('click', function (e) { if (e.target === m || e.target.closest('[data-close]')) m.classList.remove('open'); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') m.classList.remove('open'); });
})();
</script>
</body>
</html>
