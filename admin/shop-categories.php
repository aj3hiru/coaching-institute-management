<?php
// Online Store → Categories: groups for courses and notes (made from the classes at first). Each has a name, an icon
// (PNG / JPG / WebP / SVG), a description and its own SEO title + description for its page (/store/?c=slug).
require_once __DIR__ . '/components/guard.php';
require_once INCLUDES_PATH . '/institute.php';
require_once INCLUDES_PATH . '/shop.php';
$me = sh_page_start($pdo, ['products']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $a = (string)($_POST['action'] ?? ''); $id = (int)($_POST['id'] ?? 0);
    if ($a === 'save') {
        $name = trim(preg_replace('/\s+/', ' ', (string)($_POST['name'] ?? '')));
        if ($name === '') { admin_flash('Please type the category name.', 'error'); header('Location: /admin/shop-categories.php?' . ($id ? 'edit=' . $id : 'new=1')); exit; }
        $slug = sh_slug(trim((string)($_POST['slug'] ?? '')) ?: $name) ?: 'category';
        $base = $slug; $n = 1;
        while ((int)$pdo->query("SELECT COUNT(*) FROM sh_categories WHERE slug = " . $pdo->quote($slug) . " AND id <> $id")->fetchColumn()) $slug = $base . '-' . (++$n);
        $icon = $id ? (string)$pdo->query("SELECT icon FROM sh_categories WHERE id = $id")->fetchColumn() : '';
        if (!empty($_FILES['icon']['name'])) {
            [$new, $err] = sh_image_save($_FILES['icon'], 'categories', 2, true);
            if ($err) { admin_flash($err, 'error'); header('Location: /admin/shop-categories.php?' . ($id ? 'edit=' . $id : 'new=1')); exit; }
            sh_image_drop($icon); $icon = $new;
        } elseif (!empty($_POST['icon_rm'])) { sh_image_drop($icon); $icon = ''; }
        $v = [mb_substr($name, 0, 120), $slug, $icon ?: null, trim((string)($_POST['description'] ?? '')) ?: null, mb_substr(trim((string)($_POST['seo_title'] ?? '')), 0, 200) ?: null,
            mb_substr(trim((string)($_POST['seo_desc'] ?? '')), 0, 320) ?: null, (int)($_POST['sort'] ?? 0), ($_POST['status'] ?? '') === 'hidden' ? 'hidden' : 'active'];
        if ($id) { $v[] = $id; $pdo->prepare("UPDATE sh_categories SET name=?, slug=?, icon=?, description=?, seo_title=?, seo_desc=?, sort=?, status=? WHERE id=?")->execute($v); }
        else { $pdo->prepare("INSERT INTO sh_categories (name, slug, icon, description, seo_title, seo_desc, sort, status) VALUES (?,?,?,?,?,?,?,?)")->execute($v); $id = (int)$pdo->lastInsertId(); }
        admin_log('shop_category', "Saved category #$id $name"); admin_flash('Category saved.');
    } elseif ($a === 'delete' && $id) {
        $icon = (string)$pdo->query("SELECT icon FROM sh_categories WHERE id = $id")->fetchColumn();
        $pdo->prepare("UPDATE sh_products SET category_id = NULL WHERE category_id = ?")->execute([$id]);
        $pdo->prepare("UPDATE hm_free SET category_id = NULL WHERE category_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM sh_categories WHERE id = ?")->execute([$id]); sh_image_drop($icon);
        admin_log('shop_category', "Deleted category #$id"); admin_flash('Category deleted. Its courses are kept (now without a category).');
    }
    sh_home_refresh();
    header('Location: /admin/shop-categories.php'); exit;
}
$cats = sh_categories($pdo, true);
$edit = isset($_GET['edit']) ? ($cats[(int)$_GET['edit']] ?? null) : null;
$open = $edit || isset($_GET['new']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title>Categories - <?= ci_e(edu_site_name()) ?></title>
<?php admin_shell_head(); ci_head(); ?>
<style>.scg-ico{width:42px;height:42px;display:grid;place-items:center;background:#f3f4f6;border:1px solid #e5e7eb;overflow:hidden}.scg-ico img{width:100%;height:100%;object-fit:contain}.scg-ico b{color:#4a3369;font-size:16px}
.scg-seo{border:1px dashed #d1d5db;background:#fafafa;padding:12px;margin-top:6px}.scg-prev{font-family:Arial,sans-serif;max-width:600px}.scg-prev b{display:block;color:#1a0dab;font-size:17px;font-weight:400}.scg-prev small{display:block;color:#006621;font-size:13px}.scg-prev p{margin:2px 0 0;color:#545454;font-size:13px;line-height:1.45}</style>
</head>
<body>
<?php admin_shell_open('Categories', 'Course &amp; notes categories — shown on the homepage, each with its own page'); ?>
<div class="ci">
<?= admin_flash() ?>
<?php if ($open): $c = $edit ?? []; ?>
<div class="ci-card"><div class="ci-card-h"><h3><?= $edit ? 'Edit: ' . ci_e($c['name']) : 'Add a category' ?></h3><a href="/admin/shop-categories.php">Cancel</a></div><div class="ci-card-b">
<form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($c['id'] ?? 0) ?>">
<div class="ci-grid3">
  <div class="ci-f" style="grid-column:span 2"><label>Category name</label><input name="name" id="scgName" required maxlength="120" placeholder="e.g. Class 10, NEET, Board Exam" value="<?= ci_e($c['name'] ?? '') ?>"></div>
  <div class="ci-f"><label>Order</label><input name="sort" type="number" value="<?= (int)($c['sort'] ?? 0) ?>"><span class="ci-hint">Smaller numbers show first.</span></div>
  <div class="ci-f"><label>Icon <span class="ci-muted" style="font-weight:400">(PNG with a clear background works best)</span></label>
    <div style="display:flex;gap:10px;align-items:center"><span class="scg-ico" id="scgPrev"><?php if (!empty($c['icon'])): ?><img src="<?= ci_e(sh_upload_url($c['icon'])) ?>" alt=""><?php else: ?><b><?= ci_e(mb_substr($c['name'] ?? '?', 0, 1)) ?></b><?php endif; ?></span><input type="file" name="icon" id="scgIcon" accept="image/png,image/jpeg,image/webp,image/svg+xml,.svg"></div>
    <?php if (!empty($c['icon'])): ?><label style="font-weight:400;margin-top:6px;display:flex;gap:6px;align-items:center"><input type="checkbox" name="icon_rm" value="1"> Remove icon</label><?php endif; ?></div>
  <div class="ci-f"><label>Link (slug)</label><input name="slug" id="scgSlug" maxlength="140" placeholder="made from the name" value="<?= ci_e($c['slug'] ?? '') ?>"><span class="ci-hint">Page: /store/?c=<span id="scgSlugV"><?= ci_e($c['slug'] ?? '') ?></span></span></div>
  <div class="ci-f"><label>Show</label><select name="status"><option value="active">Show on the site</option><option value="hidden" <?= ($c['status'] ?? '') === 'hidden' ? 'selected' : '' ?>>Hidden</option></select></div>
  <div class="ci-f" style="grid-column:1/-1"><label>About this category <span class="ci-muted" style="font-weight:400">(optional — shown on its page)</span></label><textarea name="description" rows="3" maxlength="5000"><?= ci_e($c['description'] ?? '') ?></textarea></div>
</div>
<div class="scg-seo"><b style="display:block;margin-bottom:8px">SEO — how this page shows on Google</b>
  <div class="ci-grid3">
    <div class="ci-f" style="grid-column:span 3"><label>SEO title <span class="ci-muted" style="font-weight:400" id="scgTc"></span></label><input name="seo_title" id="scgT" maxlength="200" placeholder="e.g. Class 10 Online Courses & Notes | <?= ci_e(edu_site_name()) ?>" value="<?= ci_e($c['seo_title'] ?? '') ?>"></div>
    <div class="ci-f" style="grid-column:span 3"><label>SEO description <span class="ci-muted" style="font-weight:400" id="scgDc"></span></label><textarea name="seo_desc" id="scgD" rows="2" maxlength="320" placeholder="One or two lines about the courses in this category."><?= ci_e($c['seo_desc'] ?? '') ?></textarea></div>
  </div>
  <div class="scg-prev"><b id="scgPT"></b><small><?= ci_e(preg_replace('#^https?://#', '', rtrim(SITE_URL, '/'))) ?>/store/?c=<span id="scgPS"></span></small><p id="scgPD"></p></div>
</div>
<div style="margin-top:14px"><button class="ci-btn" type="submit"><i class="fas fa-check"></i> Save category</button></div>
</form></div></div>
<script>(function(){var n=document.getElementById('scgName'),sl=document.getElementById('scgSlug'),t=document.getElementById('scgT'),d=document.getElementById('scgD'),site=<?= json_encode(edu_site_name()) ?>;
var slug=function(v){return v.toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-+|-+$/g,'');};
function paint(){var s=sl.value.trim()||slug(n.value);document.getElementById('scgSlugV').textContent=s;document.getElementById('scgPS').textContent=s;
var tt=t.value.trim()||(n.value.trim()?n.value.trim()+' — Online Courses & Notes | '+site:'');document.getElementById('scgPT').textContent=tt;document.getElementById('scgTc').textContent='('+tt.length+' / 60 best)';
var dd=d.value.trim()||'Video courses and PDF notes for '+(n.value.trim()||'this category')+' from '+site+'.';document.getElementById('scgPD').textContent=dd;document.getElementById('scgDc').textContent='('+dd.length+' / 160 best)';}
[n,sl,t,d].forEach(function(e){e.addEventListener('input',paint);});paint();
document.getElementById('scgIcon').addEventListener('change',function(){var f=this.files[0];if(!f)return;var p=document.getElementById('scgPrev');p.innerHTML='';var i=new Image();i.src=URL.createObjectURL(f);p.appendChild(i);});})();</script>
<?php endif; ?>
<div class="ci-card"><div class="ci-card-h"><h3>All categories (<?= count($cats) ?>)</h3><?php if (!$open): ?><a class="ci-btn ci-sm" href="/admin/shop-categories.php?new=1"><i class="fas fa-plus"></i> Add category</a><?php endif; ?></div>
<div class="ci-card-b ci-p0 ci-table-wrap"><table class="ci-table">
<thead><tr><th>Icon</th><th>Category</th><th>Courses &amp; notes</th><th>Order</th><th>Status</th><th>Action</th></tr></thead><tbody>
<?php if (!$cats): ?><tr><td colspan="6" class="ci-empty">No categories yet.</td></tr><?php endif; ?>
<?php foreach ($cats as $c): ?>
<tr><td><span class="scg-ico"><?php if ($c['icon']): ?><img src="<?= ci_e(sh_upload_url($c['icon'])) ?>" alt=""><?php else: ?><b><?= ci_e(mb_substr($c['name'], 0, 1)) ?></b><?php endif; ?></span></td>
<td><b><?= ci_e($c['name']) ?></b><small class="ci-muted" style="display:block">/store/?c=<?= ci_e($c['slug']) ?><?= $c['seo_title'] ? ' · SEO set' : '' ?></small></td>
<td><?= (int)$c['items'] ?></td><td><?= (int)$c['sort'] ?></td>
<td><?= $c['status'] === 'active' ? '<span class="ci-badge ci-b-green">Shown</span>' : '<span class="ci-badge ci-b-grey">Hidden</span>' ?></td>
<td><div class="ci-icos"><?= ci_ico('eye', '/store/?c=' . rawurlencode($c['slug']), 'View', 'link', ' target="_blank"') ?><?= ci_ico('edit', '/admin/shop-categories.php?edit=' . (int)$c['id'], 'Edit') ?><?= ci_post_ico('trash', 'Delete', ['action' => 'delete', 'id' => $c['id']], 'Delete this category? Its courses stay, without a category.') ?></div></td></tr>
<?php endforeach; ?>
</tbody></table></div></div>
</div>
<?php admin_shell_close(); ?>
</body>
</html>
