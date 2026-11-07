<?php
// Online store → Reviews: what students wrote about the courses / notes; hide, show again or delete.
require_once __DIR__ . '/components/guard.php';
require_once INCLUDES_PATH . '/institute.php';
require_once INCLUDES_PATH . '/shop.php';
$me = sh_page_start($pdo, ['products']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0); $a = (string)($_POST['action'] ?? '');
    if ($a === 'hide' || $a === 'show') { $pdo->prepare("UPDATE sh_reviews SET status = ? WHERE id = ?")->execute([$a === 'hide' ? 'hidden' : 'published', $id]); admin_flash($a === 'hide' ? 'Review hidden from the course page.' : 'Review shown again.'); }
    if ($a === 'delete') { $pdo->prepare("DELETE FROM sh_reviews WHERE id = ?")->execute([$id]); admin_flash('Review deleted.'); }
    admin_log('shop_review', ucfirst($a) . " review #$id");
    header('Location: /admin/shop-reviews.php?' . http_build_query(array_intersect_key($_GET, array_flip(['product', 'status', 'stars']))));
    exit;
}
$prod = (int)($_GET['product'] ?? 0);
$status = in_array($_GET['status'] ?? '', ['published', 'hidden'], true) ? $_GET['status'] : '';
$stars = (int)($_GET['stars'] ?? 0);
$w = []; $a = [];
if ($prod) { $w[] = 'r.product_id = ?'; $a[] = $prod; }
if ($status) { $w[] = 'r.status = ?'; $a[] = $status; }
if ($stars >= 1 && $stars <= 5) { $w[] = 'r.rating = ?'; $a[] = $stars; }
$q = $pdo->prepare("SELECT r.*, u.name, u.mobile, p.title, p.slug FROM sh_reviews r JOIN sh_users u ON u.id = r.user_id JOIN sh_products p ON p.id = r.product_id" . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY r.id DESC LIMIT 500");
$q->execute($a); $rows = $q->fetchAll(PDO::FETCH_ASSOC);
$products = $pdo->query("SELECT p.id, p.title, COUNT(r.id) n, ROUND(AVG(r.rating),1) avg FROM sh_products p LEFT JOIN sh_reviews r ON r.product_id = p.id AND r.status = 'published' GROUP BY p.id ORDER BY p.title")->fetchAll(PDO::FETCH_ASSOC);
$all = $pdo->query("SELECT COUNT(*) n, ROUND(AVG(rating),1) avg FROM sh_reviews WHERE status = 'published'")->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title>Store Reviews - <?= ci_e(edu_site_name()) ?></title>
<?php admin_shell_head(); ci_head(); ?>
<style>.shr-st{color:#f59e0b;letter-spacing:1px;white-space:nowrap}.shr-body{max-width:520px;white-space:pre-line;color:#374151}</style>
</head>
<body>
<?php admin_shell_open('Store Reviews', 'Ratings and reviews written by students who have the course'); ?>
<div class="ci">
<?= admin_flash() ?>
<div class="ci-stats">
  <div class="ci-stat"><i class="fas fa-star ci-i-amber"></i><div><b><?= $all['n'] ? number_format((float)$all['avg'], 1) : '–' ?></b><small>Average rating</small></div></div>
  <div class="ci-stat"><i class="fas fa-comment ci-i-blue"></i><div><b><?= (int)$all['n'] ?></b><small>Published reviews</small></div></div>
</div>
<form class="ci-filters" method="get">
  <div class="ci-f"><label>Course / notes</label><select name="product" onchange="this.form.submit()"><option value="0">All</option><?php foreach ($products as $pp): ?><option value="<?= (int)$pp['id'] ?>" <?= $prod === (int)$pp['id'] ? 'selected' : '' ?>><?= ci_e($pp['title'] . ($pp['n'] ? ' — ' . $pp['avg'] . '★ (' . $pp['n'] . ')' : '')) ?></option><?php endforeach; ?></select></div>
  <div class="ci-f"><label>Stars</label><select name="stars" onchange="this.form.submit()"><option value="0">Any</option><?php for ($i = 5; $i >= 1; $i--): ?><option value="<?= $i ?>" <?= $stars === $i ? 'selected' : '' ?>><?= $i ?> star<?= $i === 1 ? '' : 's' ?></option><?php endfor; ?></select></div>
  <div class="ci-f"><label>Status</label><select name="status" onchange="this.form.submit()"><option value="">All</option><option value="published" <?= $status === 'published' ? 'selected' : '' ?>>Shown</option><option value="hidden" <?= $status === 'hidden' ? 'selected' : '' ?>>Hidden</option></select></div>
</form>
<div class="ci-card"><div class="ci-card-b ci-p0 ci-table-wrap"><table class="ci-table">
<thead><tr><th>Student</th><th>Course / notes</th><th>Rating</th><th>Review</th><th>Date</th><th>Status</th><th>Action</th></tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="7" class="ci-empty">No reviews yet.</td></tr><?php endif; ?>
<?php foreach ($rows as $r): ?>
<tr><td><a href="/admin/shop-customers.php?id=<?= (int)$r['user_id'] ?>"><b><?= ci_e($r['name']) ?></b></a><small class="ci-muted" style="display:block"><?= ci_e($r['mobile']) ?></small></td>
<td><a href="<?= ci_e(sh_url($r)) ?>#reviews" target="_blank"><?= ci_e($r['title']) ?></a></td>
<td class="shr-st"><?= str_repeat('★', (int)$r['rating']) ?><span style="color:#d1d5db"><?= str_repeat('★', 5 - (int)$r['rating']) ?></span></td>
<td><div class="shr-body"><?= $r['body'] ? ci_e($r['body']) : '<span class="ci-muted">(rating only)</span>' ?></div></td>
<td class="ci-nw"><?= date('d M Y', strtotime($r['updated_at'] ?: $r['created_at'])) ?></td>
<td><?= $r['status'] === 'published' ? '<span class="ci-badge ci-b-green">Shown</span>' : '<span class="ci-badge ci-b-grey">Hidden</span>' ?></td>
<td><div class="ci-icos"><?= $r['status'] === 'published' ? ci_post_ico('eye', 'Hide from the course page', ['action' => 'hide', 'id' => $r['id']]) : ci_post_ico('check', 'Show again', ['action' => 'show', 'id' => $r['id']]) ?><?= ci_post_ico('trash', 'Delete review', ['action' => 'delete', 'id' => $r['id']], 'Delete this review?') ?></div></td></tr>
<?php endforeach; ?>
</tbody></table></div></div>
</div>
<?php admin_shell_close(); ?>
</body>
</html>
