<?php
// Online store → Products: video courses and PDF notes packs (price, access period, thumbnail, status).
// The videos / PDFs inside a product are managed on shop-content.php.
require_once __DIR__ . '/components/guard.php';
require_once INCLUDES_PATH . '/institute.php';
require_once INCLUDES_PATH . '/shop.php';
$me = sh_page_start($pdo, ['products']);
sh_tables($pdo);

/** Saves an uploaded thumbnail (JPG/PNG/WebP, max 3 MB) to /uploads/store and returns its address. */
function shp_thumb(array $f, string $slug): string
{
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('The picture could not be uploaded (it may be too large).');
    if ($f['size'] > 3 * 1024 * 1024) throw new RuntimeException('The picture is larger than 3 MB.');
    $info = @getimagesize($f['tmp_name']);
    $ext = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'][$info[2] ?? 0] ?? null;
    if (!$ext) throw new RuntimeException('The picture must be a JPG, PNG or WebP image.');
    $dir = ROOT_PATH . '/uploads/store';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) throw new RuntimeException('The uploads folder is not writable.');
    $name = substr($slug, 0, 60) . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) throw new RuntimeException('The picture could not be saved.');
    return '/uploads/store/' . $name;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $a = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    $back = '/admin/shop-products.php';
    try {
        if ($a === 'save') {
            $title = trim((string)($_POST['title'] ?? ''));
            if ($title === '') throw new RuntimeException('Enter the name.');
            $type = ($_POST['type'] ?? '') === 'notes' ? 'notes' : 'course';
            $price = round(max(0, (float)($_POST['price'] ?? 0)), 2);
            $mrp = trim((string)($_POST['mrp'] ?? '')) === '' ? null : round(max(0, (float)$_POST['mrp']), 2);
            if ($mrp !== null && $mrp <= $price) $mrp = null; // a "was" price only when it is higher
            $status = in_array($_POST['status'] ?? '', ['draft', 'published', 'hidden'], true) ? $_POST['status'] : 'draft';
            $days = max(0, min(3650, (int)($_POST['validity_days'] ?? 0)));
            $slugIn = trim((string)($_POST['slug'] ?? ''));
            $slug = sh_unique_slug($pdo, $slugIn !== '' ? $slugIn : $title, $id);
            $thumb = $id ? (string)$pdo->query("SELECT thumb FROM sh_products WHERE id = $id")->fetchColumn() : '';
            if (!empty($_FILES['thumb']['name'])) $thumb = shp_thumb($_FILES['thumb'], $slug);
            if (!empty($_POST['thumb_remove'])) $thumb = '';
            $cls = (int)($_POST['class_id'] ?? 0); $cls = $cls && isset(ci_lists($pdo)['classes'][$cls]) ? $cls : null;
            $teacher = (int)($_POST['teacher_id'] ?? 0); $teacher = $teacher && isset(sh_teachers($pdo)[$teacher]) ? $teacher : null;
            $faq = [];
            foreach ((array)($_POST['faq_q'] ?? []) as $i => $q) { $q = trim((string)$q); $an = trim((string)($_POST['faq_a'][$i] ?? '')); if ($q !== '' && $an !== '') $faq[] = ['q' => mb_substr($q, 0, 300), 'a' => mb_substr($an, 0, 3000)]; }
            $inc = array_values(array_filter(array_map(fn($v) => mb_substr(trim((string)$v), 0, 200), (array)($_POST['inc'] ?? [])), fn($v) => $v !== ''));
            $vals = [$type, mb_substr($title, 0, 200), $slug, mb_substr(trim((string)($_POST['subtitle'] ?? '')), 0, 300) ?: null, trim((string)($_POST['description'] ?? '')) ?: null,
                $thumb ?: null, $price, $mrp, $days, $status, (int)($_POST['sort'] ?? 0), $cls, $teacher, $faq ? json_encode($faq, JSON_UNESCAPED_UNICODE) : null, $inc ? json_encode($inc, JSON_UNESCAPED_UNICODE) : null];
            if ($id) { $vals[] = $id; $pdo->prepare("UPDATE sh_products SET type=?, title=?, slug=?, subtitle=?, description=?, thumb=?, price=?, mrp=?, validity_days=?, status=?, sort=?, class_id=?, teacher_id=?, faq=?, includes=?, updated_at=NOW() WHERE id=?")->execute($vals); }
            else { $pdo->prepare("INSERT INTO sh_products (type, title, slug, subtitle, description, thumb, price, mrp, validity_days, status, sort, class_id, teacher_id, faq, includes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute($vals); $id = (int)$pdo->lastInsertId(); }
            admin_log('shop_product', "Saved store product #$id $title");
            admin_flash(isset($_POST['go_content']) ? 'Saved. Now add the ' . ($type === 'course' ? 'lectures' : 'PDFs') . '.' : 'Saved.');
            $back = isset($_POST['go_content']) ? ($type === 'course' ? '/admin/shop-lecture.php?course=' . $id : '/admin/shop-content.php?id=' . $id) : '/admin/shop-products.php?type=' . $type;
        } elseif ($a === 'delete' && $id) {
            $sold = (int)$pdo->query("SELECT (SELECT COUNT(*) FROM sh_access WHERE product_id = $id) + (SELECT COUNT(*) FROM sh_orders WHERE product_id = $id AND status = 'paid')")->fetchColumn();
            if ($sold) throw new RuntimeException('People have bought or been given this item, so it cannot be deleted. Set it to Hidden instead.');
            $media = [];
            foreach ($pdo->query("SELECT video_file, variants, pdf_file FROM sh_lessons WHERE product_id = $id")->fetchAll(PDO::FETCH_ASSOC) as $l) foreach (array_filter(array_merge([$l['video_file'], $l['pdf_file']], array_values(sh_variants($l)))) as $f) $media[] = $f;
            foreach ($pdo->query("SELECT file FROM sh_files WHERE product_id = $id")->fetchAll(PDO::FETCH_COLUMN) as $f) $media[] = $f;
            foreach (['sh_lessons', 'sh_sections', 'sh_units', 'sh_files', 'sh_orders', 'sh_reviews', 'sh_doubts'] as $t) $pdo->exec("DELETE FROM $t WHERE product_id = $id");
            $pdo->exec("DELETE FROM sh_products WHERE id = $id");
            foreach ($media as $f) { $pth = sh_media_path($f); if (is_file($pth)) @unlink($pth); }
            admin_log('shop_product', "Deleted store product #$id");
            admin_flash('Deleted.');
        }
    } catch (RuntimeException $e) {
        admin_flash($e->getMessage(), 'error');
        if ($a === 'save') $back = '/admin/shop-products.php?' . ($id ? 'edit=' . $id : 'new=1&type=' . (($_POST['type'] ?? '') === 'notes' ? 'notes' : 'course'));
    }
    header('Location: ' . $back);
    exit;
}

$edit = (int)($_GET['edit'] ?? 0);
$ep0 = $edit ? sh_product($pdo, $edit, false) : null;
$fType = $ep0 ? $ep0['type'] : (($_GET['type'] ?? '') === 'notes' ? 'notes' : 'course'); // two sections: Online Courses and PDF Notes
$isC = $fType === 'course';
$classesL = ci_lists($pdo)['classes'];
$teachers = sh_teachers($pdo);
$fStatus = in_array($_GET['status'] ?? '', ['draft', 'published', 'hidden'], true) ? $_GET['status'] : '';
$w = []; if ($fType) $w[] = "p.type = " . $pdo->quote($fType); if ($fStatus) $w[] = "p.status = " . $pdo->quote($fStatus);
$rows = $pdo->query("SELECT p.*, (SELECT COUNT(*) FROM sh_lessons l WHERE l.product_id = p.id) AS lessons, (SELECT COUNT(*) FROM sh_files f WHERE f.product_id = p.id) AS files,
    (SELECT COUNT(*) FROM sh_access a WHERE a.product_id = p.id AND a.revoked = 0) AS buyers, (SELECT COALESCE(SUM(o.amount),0) FROM sh_orders o WHERE o.product_id = p.id AND o.status = 'paid') AS sales
    FROM sh_products p" . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY p.sort, p.id DESC")->fetchAll(PDO::FETCH_ASSOC);
$ep = $ep0;
$epFaq = $ep ? sh_json_list($ep['faq']) : []; $epInc = $ep ? sh_json_list($ep['includes']) : [];
$epLect = $ep ? (int)$pdo->query("SELECT COUNT(*) FROM sh_lessons WHERE product_id = " . (int)$ep['id'])->fetchColumn() : 0;
$open = $ep || isset($_GET['new']);
$badge = fn($s) => '<span class="ci-badge ' . ['published' => 'ci-b-green', 'draft' => 'ci-b-amber', 'hidden' => 'ci-b-grey'][$s] . '">' . ['published' => 'Published', 'draft' => 'Draft', 'hidden' => 'Hidden'][$s] . '</span>';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title><?= $isC ? 'Online Courses' : 'PDF Notes' ?> - <?= ci_e(edu_site_name()) ?></title>
<?php admin_shell_head(); ci_head(); ?>
<style>.shp-th{width:72px;height:40px;object-fit:cover;display:block;background:#eef}.shp-thp{width:72px;height:40px;display:grid;place-items:center;background:linear-gradient(135deg,#0c6878,#4a3369);color:#fff;font-size:10px;font-weight:700}
.shp-prev{display:flex;gap:10px;align-items:center;margin-top:8px}.shp-prev img{width:120px;height:68px;object-fit:cover}
.shp-rep{display:flex;flex-direction:column;gap:8px}.shp-row{display:grid;grid-template-columns:1fr 34px;gap:8px;align-items:start;border:1px solid #e5e7eb;padding:10px;background:#fafafa}
.shp-row .shp-qa{display:flex;flex-direction:column;gap:6px}.shp-row input,.shp-row textarea{width:100%}.shp-sec{margin-top:18px;padding-top:14px;border-top:1px solid #eef0f3}.shp-sec > b{display:block;font-size:15px;margin-bottom:8px}
.shp-count{display:inline-flex;gap:6px;align-items:center;font-weight:700;color:#0c6878;background:#eef7f8;padding:8px 12px}</style>
</head>
<body>
<?php admin_shell_open($isC ? 'Online Courses' : 'PDF Notes', $isC ? 'Recorded video courses sold on the website' : 'PDF notes packs sold on the website', $open ? '' : '<a class="ci-btn ci-ghost" href="' . ($isC ? '/store/' : '/store/notes') . '" target="_blank"><i class="fas fa-store"></i> View on site</a>' . ($isC ? '<a class="ci-btn ci-ghost" href="/admin/shop-lecture.php"><i class="fas fa-film"></i> Add Lecture</a>' : '') . '<a class="ci-btn" href="?new=1&type=' . $fType . '"><i class="fas fa-plus"></i> ' . ($isC ? 'Add Course' : 'Add Notes') . '</a>'); ?>
<div class="ci">
<?= admin_flash() ?>
<?php if (!sh_rzp_ready()): ?><div class="ci-alert ci-alert-err">Online payment is not set up yet — buyers cannot pay until the Razorpay keys are saved in <a href="/admin/shop-settings.php">Store Settings</a>. Free items work already.</div><?php endif; ?>

<?php if ($open): ?>
<div class="ci-card"><div class="ci-card-h"><h3><?= $ep ? 'Edit: ' . ci_e($ep['title']) : ($isC ? 'Add a new course' : 'Add a notes pack') ?></h3><a href="/admin/shop-products.php?type=<?= $fType ?>">Cancel</a></div><div class="ci-card-b">
<form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($ep['id'] ?? 0) ?>">
<input type="hidden" name="type" value="<?= $fType ?>">
<div class="ci-grid3">
  <?php if ($isC): ?><div class="ci-f"><label>Class <span class="ci-muted" style="font-weight:400">(optional)</span></label><select name="class_id"><option value="0">— Any / not class-wise —</option><?php foreach ($classesL as $k => $l): ?><option value="<?= $k ?>" <?= (int)($ep['class_id'] ?? 0) === (int)$k ? 'selected' : '' ?>><?= ci_e($l) ?></option><?php endforeach; ?></select><span class="ci-hint">From Sessions &amp; Classes. Lecture names start with it.</span></div><?php endif; ?>
  <div class="ci-f" style="grid-column:span <?= $isC ? 2 : 3 ?>"><label><?= $isC ? 'Course name' : 'Notes name' ?></label><input name="title" required maxlength="200" placeholder="<?= $isC ? 'e.g. Maths — Full Course' : 'e.g. Class 10 Maths Formula Notes' ?>" value="<?= ci_e($ep['title'] ?? '') ?>"></div>
  <div class="ci-f" style="grid-column:1/-1"><label>Short line (shown under the name)</label><input name="subtitle" maxlength="300" placeholder="e.g. All chapters with solved examples, in Hindi" value="<?= ci_e($ep['subtitle'] ?? '') ?>"></div>
  <div class="ci-f"><label>Original price (₹)</label><input name="mrp" type="number" min="0" step="1" value="<?= ci_e(isset($ep['mrp']) && $ep['mrp'] !== null ? (float)$ep['mrp'] : '') ?>"><span class="ci-hint">Shown struck out.</span></div>
  <div class="ci-f"><label>Sale price (₹)</label><input name="price" type="number" min="0" step="1" required value="<?= ci_e(isset($ep['price']) ? (float)$ep['price'] : '') ?>"><span class="ci-hint">What students pay. 0 = free.</span></div>
  <?php if ($isC): ?><div class="ci-f"><label>Teacher</label><select name="teacher_id"><option value="0">— Choose a teacher —</option><?php foreach ($teachers as $k => $l): ?><option value="<?= $k ?>" <?= (int)($ep['teacher_id'] ?? 0) === $k ? 'selected' : '' ?>><?= ci_e($l) ?></option><?php endforeach; ?></select><span class="ci-hint"><?= $teachers ? 'Staff with the Teacher permission.' : 'No teachers yet — give a user the Teacher permission (Users → Advance Access → Online Store).' ?></span></div><?php endif; ?>
  <div class="ci-f"><label>Access</label><select name="validity_days"><?php $vd = (int)($ep['validity_days'] ?? 0); $opts = [0 => 'Lifetime', 30 => '1 month', 90 => '3 months', 180 => '6 months', 365 => '1 year', 730 => '2 years']; if (!isset($opts[$vd])) $opts[$vd] = $vd . ' days'; foreach ($opts as $k => $l): ?><option value="<?= $k ?>" <?= $vd === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
  <div class="ci-f"><label>Status</label><select name="status"><?php foreach (['draft' => 'Draft (only admins see it)', 'published' => 'Published (on the site)', 'hidden' => 'Hidden (buyers keep access)'] as $k => $l): ?><option value="<?= $k ?>" <?= ($ep['status'] ?? 'draft') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
  <div class="ci-f"><label>Web address</label><input name="slug" maxlength="150" placeholder="made from the name" value="<?= ci_e($ep['slug'] ?? '') ?>"><span class="ci-hint">/store/item?s=…</span></div>
  <div class="ci-f"><label>Order on the site</label><input name="sort" type="number" value="<?= (int)($ep['sort'] ?? 0) ?>"><span class="ci-hint">Smaller numbers come first.</span></div>
  <?php if ($isC && $ep): ?><div class="ci-f"><label>Lectures</label><span class="shp-count"><i class="fas fa-film"></i> <?= $epLect ?> lecture<?= $epLect === 1 ? '' : 's' ?> — counted automatically</span></div><?php endif; ?>
</div>
<div class="ci-f" style="margin-top:12px"><label>About (what students will learn)</label><textarea name="description" rows="6" maxlength="20000"><?= ci_e($ep['description'] ?? '') ?></textarea></div>
<div class="ci-f" style="margin-top:12px"><label>Picture (16:9, JPG / PNG / WebP, up to 3 MB)</label><input type="file" name="thumb" accept="image/jpeg,image/png,image/webp">
  <?php if (!empty($ep['thumb'])): ?><div class="shp-prev"><img src="<?= ci_e($ep['thumb']) ?>" alt=""><label style="font-weight:400"><input type="checkbox" name="thumb_remove" value="1"> Remove picture</label></div><?php endif; ?></div>
<div class="shp-sec"><b>What students get <span class="ci-muted" style="font-weight:400">— one point per line, shown in "What's included"</span></b>
  <div class="shp-rep" id="shpInc"><?php foreach ($epInc ?: [''] as $v): ?><div class="shp-row"><input name="inc[]" maxlength="200" placeholder="e.g. Doubt solving by the teacher" value="<?= ci_e($v) ?>"><button type="button" class="ci-ico" data-i="trash" aria-label="Remove" data-rm><?= ci_svg('trash') ?></button></div><?php endforeach; ?></div>
  <button type="button" class="ci-btn ci-ghost ci-sm" data-add="shpInc" style="margin-top:8px"><i class="fas fa-plus"></i> Add point</button></div>
<div class="shp-sec"><b>FAQ of this <?= $isC ? 'course' : 'notes pack' ?> <span class="ci-muted" style="font-weight:400">— shown first in "Frequently asked questions"</span></b>
  <div class="shp-rep" id="shpFaq"><?php foreach ($epFaq ?: [['q' => '', 'a' => '']] as $f): ?><div class="shp-row"><div class="shp-qa"><input name="faq_q[]" maxlength="300" placeholder="Question — e.g. Is this course in Hindi?" value="<?= ci_e($f['q'] ?? '') ?>"><textarea name="faq_a[]" rows="2" maxlength="3000" placeholder="Answer"><?= ci_e($f['a'] ?? '') ?></textarea></div><button type="button" class="ci-ico" data-i="trash" aria-label="Remove" data-rm><?= ci_svg('trash') ?></button></div><?php endforeach; ?></div>
  <button type="button" class="ci-btn ci-ghost ci-sm" data-add="shpFaq" style="margin-top:8px"><i class="fas fa-plus"></i> Add question</button></div>
<div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap"><button class="ci-btn" type="submit" name="go_content" value="1"><i class="fas fa-check"></i> Save &amp; add <?= $isC ? 'lectures' : 'PDFs' ?></button><button class="ci-btn ci-ghost" type="submit">Save</button></div>
</form></div></div>
<?php endif; ?>

<form class="ci-filters" method="get"><input type="hidden" name="type" value="<?= $fType ?>"><div class="ci-f"><label>Status</label><select name="status" onchange="this.form.submit()"><option value="">All</option><?php foreach (['published' => 'Published', 'draft' => 'Draft', 'hidden' => 'Hidden'] as $k => $l): ?><option value="<?= $k ?>" <?= $fStatus === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div></form>
<div class="ci-card"><div class="ci-card-b ci-p0 ci-table-wrap"><table class="ci-table">
<thead><tr><th>Product</th><th>Type</th><th>Inside</th><th class="ci-num">Price</th><th>Access</th><th class="ci-num">Buyers</th><th class="ci-num">Sales</th><th>Status</th><th>Action</th></tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="9" class="ci-empty">No products yet. Add your first course or notes.</td></tr><?php endif; ?>
<?php foreach ($rows as $p): ?>
<tr><td><div style="display:flex;gap:10px;align-items:center"><?= $p['thumb'] ? '<img class="shp-th" src="' . ci_e($p['thumb']) . '" alt="">' : '<span class="shp-thp">' . ($p['type'] === 'notes' ? 'PDF' : 'VIDEO') . '</span>' ?><div><b><?= ci_e($p['title']) ?></b><?= $p['subtitle'] ? '<small class="ci-muted" style="display:block">' . ci_e(mb_strimwidth($p['subtitle'], 0, 70, '…')) . '</small>' : '' ?></div></div></td>
<td><?= $p['type'] === 'notes' ? 'PDF notes' : 'Video course' ?><?= $p['type'] === 'course' && $p['teacher_id'] ? '<small class="ci-muted" style="display:block">' . ci_e($teachers[(int)$p['teacher_id']] ?? '') . '</small>' : '' ?></td>
<td class="ci-nw"><a href="/admin/shop-content.php?id=<?= (int)$p['id'] ?>"><?= $p['type'] === 'course' ? (int)$p['lessons'] . ' videos' . ((int)$p['files'] ? ' · ' . (int)$p['files'] . ' PDFs' : '') : (int)$p['files'] . ' PDFs' ?></a></td>
<td class="ci-num"><?= (float)$p['price'] > 0 ? ci_money0($p['price']) : 'Free' ?><?= $p['mrp'] !== null ? '<small class="ci-muted" style="display:block;text-decoration:line-through">' . ci_money0($p['mrp']) . '</small>' : '' ?></td>
<td class="ci-nw"><?= (int)$p['validity_days'] ? (int)$p['validity_days'] . ' days' : 'Lifetime' ?></td>
<td class="ci-num"><?= (int)$p['buyers'] ?></td><td class="ci-num"><?= ci_money0($p['sales']) ?></td><td><?= $badge($p['status']) ?></td>
<td><div class="ci-icos"><?= ci_ico('edit', '?edit=' . (int)$p['id'], 'Edit') ?><?= $p['type'] === 'course' ? ci_ico('plus', '/admin/shop-lecture.php?course=' . (int)$p['id'], 'Add lecture') : '' ?><?= ci_ico('book-plus', '/admin/shop-content.php?id=' . (int)$p['id'], $p['type'] === 'course' ? 'Chapters & lectures' : 'PDFs') ?><?= ci_ico('eye', sh_url($p) . '&preview=1', 'View on the store', 'link', ' target="_blank"') ?>
<?php if (!(int)$p['buyers']): ?><?= ci_post_ico('trash', 'Delete', ['action' => 'delete', 'id' => $p['id']], 'Delete ' . $p['title'] . ' with all its videos and PDFs?') ?><?php endif; ?></div></td></tr>
<?php endforeach; ?>
</tbody></table></div></div>
</div>
<?php admin_shell_close(); ?>
<script>
document.addEventListener('click', function (e) {
  var add = e.target.closest('[data-add]'), rm = e.target.closest('[data-rm]');
  if (add) { var box = document.getElementById(add.getAttribute('data-add')), row = box.lastElementChild.cloneNode(true); row.querySelectorAll('input, textarea').forEach(function (f) { f.value = ''; }); box.appendChild(row); row.querySelector('input, textarea').focus(); }
  if (rm) { var r = rm.closest('.shp-row'), b = r.parentNode; if (b.children.length > 1) r.remove(); else r.querySelectorAll('input, textarea').forEach(function (f) { f.value = ''; }); }
});
</script>
</body>
</html>
