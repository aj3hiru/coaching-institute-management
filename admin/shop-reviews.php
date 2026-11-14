<?php
// Online store → Reviews. New and changed reviews wait for approval; approve (publish), hide, edit (stars, text, status)
// or delete. Only published reviews show on the course page and count in its rating.
require_once __DIR__ . '/components/guard.php';
require_once INCLUDES_PATH . '/institute.php';
require_once INCLUDES_PATH . '/shop.php';
$me = sh_page_start($pdo, ['products']);
$keep = fn() => http_build_query(array_intersect_key($_GET, array_flip(['product', 'status', 'stars'])));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0); $a = (string)($_POST['action'] ?? '');
    if ($a === 'approve' || $a === 'show') { $pdo->prepare("UPDATE sh_reviews SET status = 'published' WHERE id = ?")->execute([$id]); admin_flash('Review published — it shows on the course page now.'); }
    elseif ($a === 'hide') { $pdo->prepare("UPDATE sh_reviews SET status = 'hidden' WHERE id = ?")->execute([$id]); admin_flash('Review hidden from the course page.'); }
    elseif ($a === 'delete') { $pdo->prepare("DELETE FROM sh_reviews WHERE id = ?")->execute([$id]); admin_flash('Review deleted.'); }
    elseif ($a === 'edit') {
        $rating = max(1, min(5, (int)($_POST['rating'] ?? 5)));
        $body = trim(preg_replace("/\r\n?/", "\n", (string)($_POST['body'] ?? '')));
        $st = in_array($_POST['status'] ?? '', ['pending', 'published', 'hidden'], true) ? $_POST['status'] : 'published';
        $pdo->prepare("UPDATE sh_reviews SET rating = ?, body = ?, status = ?, updated_at = NOW() WHERE id = ?")->execute([$rating, $body !== '' ? mb_substr($body, 0, 2000) : null, $st, $id]);
        admin_flash('Review saved.');
    }
    admin_log('shop_review', ucfirst($a) . " review #$id");
    header('Location: /admin/shop-reviews.php?' . $keep()); exit;
}
$cnt = $pdo->query("SELECT status, COUNT(*) FROM sh_reviews GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$prod = (int)($_GET['product'] ?? 0);
$status = in_array($_GET['status'] ?? '', ['pending', 'published', 'hidden', 'all'], true) ? $_GET['status'] : (!empty($cnt['pending']) ? 'pending' : 'all');
$stars = (int)($_GET['stars'] ?? 0);
$w = []; $a = [];
if ($prod) { $w[] = 'r.product_id = ?'; $a[] = $prod; }
if ($status !== 'all') { $w[] = 'r.status = ?'; $a[] = $status; }
if ($stars >= 1 && $stars <= 5) { $w[] = 'r.rating = ?'; $a[] = $stars; }
$q = $pdo->prepare("SELECT r.*, u.name, u.mobile, p.title, p.slug, p.type FROM sh_reviews r JOIN sh_users u ON u.id = r.user_id JOIN sh_products p ON p.id = r.product_id" . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY r.status = 'pending' DESC, COALESCE(r.updated_at, r.created_at) DESC LIMIT 1000");
$q->execute($a); $rows = $q->fetchAll(PDO::FETCH_ASSOC);
$products = $pdo->query("SELECT p.id, p.title, COUNT(r.id) n FROM sh_products p LEFT JOIN sh_reviews r ON r.product_id = p.id GROUP BY p.id ORDER BY p.title")->fetchAll(PDO::FETCH_ASSOC);
$avg = $pdo->query("SELECT ROUND(AVG(rating),1) FROM sh_reviews WHERE status = 'published'")->fetchColumn();
$tabs = ['pending' => 'Waiting for approval', 'published' => 'Published', 'hidden' => 'Hidden', 'all' => 'All'];
$badge = ['pending' => '<span class="ci-badge ci-b-amber">Waiting</span>', 'published' => '<span class="ci-badge ci-b-green">Published</span>', 'hidden' => '<span class="ci-badge ci-b-grey">Hidden</span>'];
$rf = fn(array $r): string => ci_e(json_encode(['id' => (int)$r['id'], 'rating' => (int)$r['rating'], 'body' => (string)$r['body'], 'status' => $r['status']], JSON_UNESCAPED_UNICODE));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title>Reviews - <?= ci_e(edu_site_name()) ?></title>
<?php admin_shell_head(); ci_head(); ?>
<style>
.shr-st{color:#f59e0b;letter-spacing:1px;white-space:nowrap;font-size:15px}.shr-st i{color:#e5e7eb;font-style:normal}
.shr-body{max-width:520px;white-space:pre-line;color:#374151;line-height:1.55}
.shr-who b{display:block}.shr-who small{color:#6b7280}
.shr-item{color:#111827!important;font-weight:600}.shr-item small{display:block;color:#6b7280;font-weight:400;font-size:12px}
.ci-pills{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 14px}
.ci-pills a{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border:1px solid #e5e7eb;background:#fff;border-radius:999px;color:#374151!important;font-size:13.5px;font-weight:600;text-decoration:none!important}
.ci-pills a em{font-style:normal;font-size:11.5px;background:#f3f4f6;color:#374151;padding:1px 7px;border-radius:99px}
.ci-pills a.on{background:var(--primary,#4a3369);border-color:var(--primary,#4a3369);color:#fff!important}.ci-pills a.on em{background:rgba(255,255,255,.22);color:#fff}
.ci-pills a.warn em{background:#fef3c7;color:#92400e}
.shr-stars{display:flex;gap:4px}.shr-stars label{margin:0;cursor:pointer;font-size:26px;color:#e5e7eb;line-height:1}.shr-stars input{position:absolute;opacity:0;width:1px;height:1px}
.shr-stars label.on{color:#f59e0b}
</style>
</head>
<body>
<?php admin_shell_open('Reviews', 'What students wrote — new reviews show on the site after you approve them'); ?>
<div class="ci">
<?= admin_flash() ?>
<div class="ci-stats">
  <div class="ci-stat"><i class="fas fa-hourglass-half ci-i-amber"></i><div><b><?= (int)($cnt['pending'] ?? 0) ?></b><small>Waiting for approval</small></div></div>
  <div class="ci-stat"><i class="fas fa-check ci-i-green"></i><div><b><?= (int)($cnt['published'] ?? 0) ?></b><small>Published</small></div></div>
  <div class="ci-stat"><i class="fas fa-star ci-i-amber"></i><div><b><?= $avg ? number_format((float)$avg, 1) : '–' ?></b><small>Average rating</small></div></div>
</div>
<nav class="ci-pills" aria-label="Review status"><?php foreach ($tabs as $k => $l): $n = $k === 'all' ? array_sum($cnt) : (int)($cnt[$k] ?? 0); ?>
  <a href="?<?= ci_e(http_build_query(['status' => $k, 'product' => $prod ?: null, 'stars' => $stars ?: null])) ?>" class="<?= $status === $k ? 'on' : '' ?><?= $k === 'pending' && $n ? ' warn' : '' ?>"><?= $l ?> <em><?= $n ?></em></a>
<?php endforeach; ?></nav>
<form class="ci-filters" method="get"><input type="hidden" name="status" value="<?= ci_e($status) ?>">
  <div class="ci-f"><label>Course / notes</label><select name="product" onchange="this.form.submit()"><option value="0">All courses &amp; notes</option><?php foreach ($products as $pp): ?><option value="<?= (int)$pp['id'] ?>" <?= $prod === (int)$pp['id'] ? 'selected' : '' ?>><?= ci_e($pp['title']) ?></option><?php endforeach; ?></select></div>
  <div class="ci-f"><label>Stars</label><select name="stars" onchange="this.form.submit()"><option value="0">Any</option><?php for ($i = 5; $i >= 1; $i--): ?><option value="<?= $i ?>" <?= $stars === $i ? 'selected' : '' ?>><?= $i ?> star<?= $i === 1 ? '' : 's' ?></option><?php endfor; ?></select></div>
</form>
<div class="ci-card"><div class="ci-card-b ci-p0 ci-table-wrap"><table class="ci-table">
<thead><tr><th>Student</th><th>Course / notes</th><th>Rating</th><th>Review</th><th>Date</th><th>Status</th><th>Action</th></tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="7" class="ci-empty"><?= $status === 'pending' ? 'No reviews are waiting for approval.' : 'No reviews here.' ?></td></tr><?php endif; ?>
<?php foreach ($rows as $r): ?>
<tr><td class="shr-who"><a href="/admin/shop-customers.php?id=<?= (int)$r['user_id'] ?>"><b><?= ci_e($r['name']) ?></b></a><small><?= ci_tel($r['mobile']) ?></small></td>
<td><a class="shr-item" href="<?= ci_e(sh_url($r)) ?>#reviews" target="_blank"><?= ci_e($r['title']) ?><small><?= $r['type'] === 'notes' ? 'PDF notes' : 'Video course' ?></small></a></td>
<td class="shr-st" data-sort="<?= (int)$r['rating'] ?>"><?= str_repeat('★', (int)$r['rating']) ?><i><?= str_repeat('★', 5 - (int)$r['rating']) ?></i></td>
<td><div class="shr-body"><?= $r['body'] ? ci_e($r['body']) : '<span class="ci-muted">(rating only)</span>' ?></div></td>
<td class="ci-nw"><?= date('d M Y', strtotime($r['updated_at'] ?: $r['created_at'])) ?></td>
<td><?= $badge[$r['status']] ?? ci_e($r['status']) ?></td>
<td><div class="ci-icos">
  <?php if ($r['status'] !== 'published'): ?><?= ci_post_ico('check', $r['status'] === 'pending' ? 'Approve & publish' : 'Publish again', ['action' => 'approve', 'id' => $r['id']]) ?><?php else: ?><?= ci_post_ico('eye', 'Hide from the course page', ['action' => 'hide', 'id' => $r['id']]) ?><?php endif; ?>
  <?= ci_ico('edit', null, 'Edit review', 'button', ' data-mopen="#rvForm" data-mtitle="Edit review by ' . ci_e($r['name']) . '" data-fill="' . $rf($r) . '"') ?>
  <?= ci_post_ico('trash', 'Delete review', ['action' => 'delete', 'id' => $r['id']], 'Delete this review?') ?>
</div></td></tr>
<?php endforeach; ?>
</tbody></table></div></div>
</div>
<div class="ci-modal" id="rvForm" data-mform hidden><div class="ci-modal-box" role="dialog" aria-modal="true" aria-labelledby="rvFormH">
<div class="ci-card-h"><h3 id="rvFormH" data-mtitle-target>Edit review</h3><button type="button" class="ci-x" data-close aria-label="Close"><i class="fas fa-times"></i></button></div><div class="ci-card-b">
<form method="post" action="?<?= ci_e($keep()) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="edit"><input type="hidden" name="id" value="0">
  <div class="ci-f"><label>Rating</label><div class="shr-stars" id="rvStars"><?php for ($i = 1; $i <= 5; $i++): ?><label title="<?= $i ?> star<?= $i === 1 ? '' : 's' ?>"><input type="radio" name="rating" value="<?= $i ?>">★</label><?php endfor; ?></div></div>
  <div class="ci-f" style="margin-top:12px"><label>Review</label><textarea name="body" rows="5" maxlength="2000" placeholder="(rating only)"></textarea></div>
  <div class="ci-f" style="margin-top:12px"><label>Status</label><select name="status"><option value="pending">Waiting</option><option value="published">Published</option><option value="hidden">Hidden</option></select></div>
  <div class="ci-mfoot"><button type="button" class="ci-btn ci-ghost" data-close>Cancel</button><button class="ci-btn" type="submit"><i class="fas fa-check"></i> Save review</button></div>
</form></div></div></div>
<script>(function(){var box=document.getElementById('rvStars');function paint(){var v=+(box.querySelector('input:checked')||{value:0}).value;[].forEach.call(box.querySelectorAll('label'),function(l,i){l.classList.toggle('on',i<v);});}
box.addEventListener('change',paint);document.addEventListener('click',function(e){if(e.target.closest('[data-mopen="#rvForm"]'))setTimeout(paint,0);});})();</script>
<?php admin_shell_close(); ?>
</body>
</html>
