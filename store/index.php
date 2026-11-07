<?php
// Store: every published video course and PDF notes pack.  /store  (?t=course|notes, ?c=category-slug)
require_once dirname(__DIR__) . '/includes/config.php';
require_once INCLUDES_PATH . '/shop.php';
$pdo = sh_db();
if (!sh_on()) { http_response_code(404); require ROOT_PATH . '/templates/page_not_found.php'; exit; }
$t = in_array($_GET['t'] ?? '', ['course', 'notes'], true) ? $_GET['t'] : '';
$u = sh_user();
$cat = null;
if (($cs = (string)($_GET['c'] ?? '')) !== '') { $q = $pdo->prepare("SELECT * FROM sh_categories WHERE slug = ? AND status = 'active'"); $q->execute([$cs]); $cat = $q->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$cat) { http_response_code(404); require ROOT_PATH . '/templates/page_not_found.php'; exit; } }
$rows = $pdo->query("SELECT p.*, (SELECT COUNT(*) FROM sh_lessons l WHERE l.product_id = p.id) AS lessons, (SELECT COUNT(*) FROM sh_files f WHERE f.product_id = p.id) AS files
    FROM sh_products p WHERE p.status = 'published'" . ($t ? " AND p.type = " . $pdo->quote($t) : '') . ($cat ? ' AND p.category_id = ' . (int)$cat['id'] : '') . " ORDER BY p.sort, p.id DESC")->fetchAll(PDO::FETCH_ASSOC);
$owned = [];
if ($u) { $q = $pdo->prepare("SELECT product_id FROM sh_access WHERE user_id = ? AND revoked = 0 AND (expires_at IS NULL OR expires_at > NOW())"); $q->execute([$u['id']]); $owned = array_flip($q->fetchAll(PDO::FETCH_COLUMN)); }
$stTitle = $t === 'notes' ? 'PDF Notes' : ($t === 'course' ? 'Video Courses' : 'Courses & Notes');
$stDesc = 'Recorded video courses and PDF notes from ' . edu_site_name() . '. Learn at your own pace on any device.';
$stIndex = true;
if ($cat) {
    $stTitle = $cat['name'];
    $stFullTitle = $cat['seo_title'] ?: $cat['name'] . ' — Online Courses & Notes | ' . edu_site_name();
    $stDesc = $cat['seo_desc'] ?: 'Video courses and PDF notes for ' . $cat['name'] . ' from ' . edu_site_name() . '.';
    if ($cat['icon']) $stImage = rtrim(SITE_URL, '/') . sh_upload_url($cat['icon']);
}
$cats = sh_categories($pdo);
require ROOT_PATH . '/components/store-open.php';
?>
<p class="st-crumb"><a href="/">Home</a> › <?= $cat ? '<a href="/store/">Store</a> › ' . sh_e($cat['name']) : 'Store' ?></p>
<?php if ($cat): ?><div class="st-cathd"><?php if ($cat['icon']): ?><img src="<?= sh_e(sh_upload_url($cat['icon'])) ?>" alt="" width="56" height="56"><?php endif; ?><h1><?= sh_e($cat['name']) ?></h1></div>
<?php if ($cat['description']): ?><div class="st-lead"><?= nl2br(sh_e($cat['description'])) ?></div><?php else: ?><p class="st-lead">Courses and notes for <?= sh_e($cat['name']) ?>.</p><?php endif; ?>
<?php else: ?><h1><?= sh_e($stTitle) ?></h1>
<p class="st-lead">Recorded classes and notes from our teachers — buy once, study anytime on your phone or computer.</p><?php endif; ?>
<?php if ($cats): ?><nav class="st-cats" aria-label="Categories"><a href="/store/<?= $t ? '?t=' . $t : '' ?>" class="<?= $cat ? '' : 'on' ?>">All</a><?php foreach ($cats as $c): ?><a href="/store/?c=<?= rawurlencode($c['slug']) ?><?= $t ? '&amp;t=' . $t : '' ?>" class="<?= $cat && (int)$cat['id'] === (int)$c['id'] ? 'on' : '' ?>"><?= sh_e($c['name']) ?></a><?php endforeach; ?></nav><?php endif; ?>
<?php $cq = $cat ? 'c=' . rawurlencode($cat['slug']) : ''; ?>
<nav class="st-tabs"><a href="/store/<?= $cq ? '?' . $cq : '' ?>" class="<?= $t === '' ? 'on' : '' ?>">All</a><a href="/store/?t=course<?= $cq ? '&amp;' . $cq : '' ?>" class="<?= $t === 'course' ? 'on' : '' ?>">Video Courses</a><a href="/store/notes">PDF Notes</a><?php if ($u): ?><a href="/account/">My Courses</a><?php endif; ?></nav>
<?php if (!$rows): ?><div class="st-empty">Nothing here yet — new courses and notes are coming soon.</div><?php endif; ?>
<div class="st-grid">
<?php foreach ($rows as $p): ?>
  <a class="st-card" href="<?= sh_e(sh_url($p)) ?>"><?= sh_thumb_html($p) ?>
    <div class="st-cb"><h3><?= sh_e($p['title']) ?></h3><?php if ($p['subtitle']): ?><p><?= sh_e($p['subtitle']) ?></p><?php endif; ?>
      <span class="st-meta"><?= $p['type'] === 'notes' ? (int)$p['files'] . ' PDF' . ((int)$p['files'] === 1 ? '' : 's') : (int)$p['lessons'] . ' video' . ((int)$p['lessons'] === 1 ? '' : 's') ?><?= (int)$p['validity_days'] ? ' · ' . (int)$p['validity_days'] . ' days access' : ' · Lifetime access' ?></span>
      <div class="st-price"><?= isset($owned[$p['id']]) ? '<span class="st-own">✓ In your library</span>' : sh_price_html($p) ?></div>
    </div></a>
<?php endforeach; ?>
</div>
<?php require ROOT_PATH . '/components/store-close.php'; ?>
