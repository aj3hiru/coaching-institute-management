<?php
// Store / category page: /store/ (everything) · /category/slug (one class).  Sections like the homepage, one under the other:
// upcoming batches · courses (8 + View more) · PDF notes (8 + View more) · free courses · other categories. ?t=course|notes keeps only that part.
require_once dirname(__DIR__) . '/includes/config.php';
require_once INCLUDES_PATH . '/shop.php';
$pdo = sh_db();
if (!sh_on()) { http_response_code(404); require ROOT_PATH . '/templates/page_not_found.php'; exit; }
$t = in_array($_GET['t'] ?? '', ['course', 'notes'], true) ? $_GET['t'] : '';
$u = sh_user();
$cat = null;
if (($cs = (string)($_GET['c'] ?? '')) !== '') { $q = $pdo->prepare("SELECT * FROM sh_categories WHERE slug = ? AND status = 'active'"); $q->execute([$cs]); $cat = $q->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$cat) { if (str_starts_with((string)($_SERVER['REQUEST_URI'] ?? ''), '/category/')) { http_response_code(410); exit('Gone'); } http_response_code(404); require ROOT_PATH . '/templates/page_not_found.php'; exit; } }
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
if ($cat) $seo_canonical = rtrim(SITE_URL, '/') . sh_cat_url($cat['slug']);
// old addresses (/store/?c=…, /store/?t=course) move to the clean ones
if ($cat) { if (sh_rw_live()) sh_redirect_clean(sh_cat_url($cat['slug']) . ($t !== '' ? '?t=' . $t : '')); }
elseif ($t === 'course') sh_redirect_clean('/courses/');
elseif ($t === 'notes') sh_redirect_clean('/notes/');
if ($cat === null && ($cs ?? '') === '' && str_starts_with((string)($_SERVER['REQUEST_URI'] ?? ''), '/category')) { http_response_code(410); exit; }

// ---- the sections ----
$cid = $cat ? (int)$cat['id'] : 0;
$wantC = $t !== 'notes'; $wantN = $t !== 'course';
$upc = $wantC ? sh_latest($pdo, 'course', 10, 0, $cid, 'upcoming') : [];
$crs = $wantC ? sh_latest($pdo, 'course', 9, 0, $cid, 'launched') : []; $moreC = count($crs) > 8; $crs = array_slice($crs, 0, 8);
$nts = $wantN ? sh_latest($pdo, 'notes', 9, 0, $cid) : []; $moreN = count($nts) > 8; $nts = array_slice($nts, 0, 8);
$frees = [];
if ($t === '') { try { $frees = $pdo->query("SELECT * FROM hm_free WHERE active = 1" . ($cid ? " AND category_id = $cid" : '') . " ORDER BY sort, id DESC LIMIT 12")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($frees as &$__f) if (!empty($__f['thumb_custom'])) $__f['thumb'] = sh_upload_url($__f['thumb_custom']); unset($__f); } catch (Throwable) {} }
$tNames = [];
foreach ($upc as $p) if (!empty($p['teacher_id'])) $tNames[(int)$p['teacher_id']] ??= sh_teacher_name($pdo, (int)$p['teacher_id']);
$catName = fn(array $p) => $cats[(int)($p['category_id'] ?? 0)]['name'] ?? '';
$others = array_values(array_filter($cats, fn($c) => !$cat || (int)$c['id'] !== $cid));
$allC = $cat ? '/courses/?c=' . rawurlencode($cat['slug']) : '/courses/';
$allN = $cat ? '/notes/?c=' . rawurlencode($cat['slug']) : '/notes/';
$ico = fn($n) => sh_icon($n);
$plural = fn(int $n, string $one, string $many) => $n . ' ' . ($n === 1 ? $one : $many);
$stMain = 'hm cp';
require ROOT_PATH . '/components/store-open.php';
?>
<link rel="stylesheet" href="/assets/css/home.css?v=<?= @filemtime(ROOT_PATH . '/assets/css/home.css') ?: 1 ?>">
<div class="sp-wrap"><p class="st-crumb cp-crumb"><a href="/">Home</a> › <?= $cat ? '<a href="/all-categories/">Categories</a> › ' . sh_e($cat['name']) : 'Courses &amp; Notes' ?></p>
<header class="cp-head">
  <h1><?= sh_e($cat ? $cat['name'] : $stTitle) ?></h1>
  <?php if ($cat && $cat['description']): ?><div class="cp-sub"><?= nl2br(sh_e($cat['description'])) ?></div>
  <?php else: ?><p class="cp-sub"><?= $cat ? 'Video courses, new batches and PDF notes for ' . sh_e($cat['name']) . '.' : 'Recorded classes and notes from our teachers — buy once, study anytime.' ?></p><?php endif; ?>
</header></div>

<?php if (!$upc && !$crs && !$nts && !$frees): ?>
<section class="hm-sec"><div class="sp-wrap"><div class="cp-empty"><?= $ico('notes') ?><b>Nothing here yet</b><p>New courses and notes<?= $cat ? ' for ' . sh_e($cat['name']) : '' ?> are coming soon.</p><a class="hm-btn hm-pro hm-splash" href="/courses/">See all courses <span class="hm-pro-a"><?= $ico('arrow') ?></span></a></div></div></section>
<?php endif; ?>

<?php if ($upc): ?>
<section class="hm-sec hm-bt-s" id="upcoming"><div class="sp-wrap">
  <div class="hm-h"><div><h2>Upcoming batches</h2><p class="hm-hsub">New batches<?= $cat ? ' for ' . sh_e($cat['name']) : '' ?> — join before the first class.</p></div><a href="/upcoming-courses/<?= $cat ? '?c=' . rawurlencode($cat['slug']) : '' ?>">View all ›</a></div>
  <div class="hm-bt" data-hs data-auto="6500">
    <div class="hs-track"><?php foreach ($upc as $p): [$bt] = sh_batch($p); $tn = $tNames[(int)($p['teacher_id'] ?? 0)] ?? ''; $fr = (float)$p['price'] <= 0; ?>
      <div class="hs-s"><div class="hm-btc">
        <a class="hm-btc-i" href="<?= sh_e(sh_url($p)) ?>" tabindex="-1" aria-hidden="true"><?= sh_thumb_html($p) ?></a>
        <div class="hm-btc-b">
          <div class="hm-btc-tags"><span class="t-up">Upcoming batch</span><?= $catName($p) !== '' ? '<span>' . sh_e($catName($p)) . '</span>' : '' ?></div>
          <h3><a href="<?= sh_e(sh_url($p)) ?>"><?= sh_e($p['title']) ?></a></h3>
          <?php if ($p['subtitle']): ?><p><?= sh_e($p['subtitle']) ?></p><?php endif; ?>
          <ul class="hm-btc-m"><?php if ($bt !== ''): ?><li><?= $ico('calendar') ?> <?= sh_e($bt) ?></li><?php endif; ?><?php if ($tn !== ''): ?><li><?= $ico('user') ?> <?= sh_e($tn) ?></li><?php endif; ?><li><?= $ico('video') ?> <?= $plural((int)$p['lessons'], 'lecture', 'lectures') ?></li><?php if ((int)$p['validity_days']): ?><li><?= $ico('clock') ?> <?= (int)$p['validity_days'] ?> days access</li><?php endif; ?></ul>
          <div class="hm-btc-f"><span class="hm-btc-p"><?= sh_price_html($p) ?></span><a class="hm-btn hm-splash" href="<?= sh_e(sh_url($p)) ?>">Join this batch <?= $ico('arrow') ?></a></div>
        </div></div></div>
    <?php endforeach; ?></div>
    <?php if (count($upc) > 1): ?><button type="button" class="hs-prev" aria-label="Previous">‹</button><button type="button" class="hs-next" aria-label="Next">›</button><div class="hs-dots"></div><?php endif; ?>
  </div>
</div></section>
<?php endif; ?>

<?php if ($crs): ?>
<section class="hm-sec" id="courses"><div class="sp-wrap">
  <div class="hm-h"><div><h2><?= $cat ? sh_e($cat['name']) . ' courses' : 'Video courses' ?></h2><p class="hm-hsub">Recorded classes — watch anytime, as many times as you like.</p></div><a href="<?= sh_e($allC) ?>">View all ›</a></div>
  <div class="hm-grid" id="cpCourses"><?php foreach ($crs as $p) echo sh_mini_card($p, $catName($p)); ?></div>
  <div class="hm-more"><?php if ($moreC || count($crs) > 4): ?><button type="button" class="hm-btn hm-pro hm-splash" data-more-grid="cpCourses" data-all="cpCoursesAll" data-t="course" data-w="launched" data-c="<?= $cid ?>" data-o="8" data-more="<?= $moreC ? 1 : 0 ?>">View more courses <span class="hm-pro-a"><?= $ico('arrow') ?></span></button><?php endif; ?><a class="hm-btn hm-pro hm-splash" id="cpCoursesAll" href="<?= sh_e($allC) ?>"<?= $moreC || count($crs) > 4 ? ' hidden' : '' ?>>See all courses <span class="hm-pro-a"><?= $ico('arrow') ?></span></a></div>
</div></section>
<?php endif; ?>

<?php if ($nts): ?>
<section class="hm-sec cp-notes" id="notes"><div class="sp-wrap">
  <div class="hm-h"><div><h2>PDF notes</h2><p class="hm-hsub">Chapter-wise notes and study material — read on any device.</p></div><a href="<?= sh_e($allN) ?>">View all ›</a></div>
  <div class="hm-grid" id="cpNotes"><?php foreach ($nts as $p) echo sh_mini_card($p, $catName($p)); ?></div>
  <div class="hm-more"><?php if ($moreN || count($nts) > 4): ?><button type="button" class="hm-btn hm-pro hm-splash" data-more-grid="cpNotes" data-all="cpNotesAll" data-t="notes" data-c="<?= $cid ?>" data-o="8" data-more="<?= $moreN ? 1 : 0 ?>">View more notes <span class="hm-pro-a"><?= $ico('arrow') ?></span></button><?php endif; ?><a class="hm-btn hm-pro hm-splash" id="cpNotesAll" href="<?= sh_e($allN) ?>"<?= $moreN || count($nts) > 4 ? ' hidden' : '' ?>>See all notes <span class="hm-pro-a"><?= $ico('arrow') ?></span></a></div>
</div></section>
<?php endif; ?>

<?php if ($frees): ?>
<section class="hm-sec" id="free"><div class="sp-wrap">
  <div class="hm-h"><div><h2>Free courses</h2><p class="hm-hsub">Full playlists — free for every student.</p></div><a href="/free-courses/">All free courses ›</a></div>
  <div class="hm-fs" data-hs><div class="hs-track"><?php foreach ($frees as $f): ?>
    <div class="hs-s"><a class="hm-fc" href="<?= sh_e(sh_free_url($f)) ?>"><span class="hm-fc-i"><img src="<?= sh_e($f['thumb'] ?: '') ?>" alt="" loading="lazy" onerror="this.remove()"><span class="hm-fc-pl"><?= $ico('playsolid') ?></span><?php if ($f['videos']): ?><em><?= (int)$f['videos'] >= 100 ? '100+' : (int)$f['videos'] ?> videos</em><?php endif; ?></span>
      <span class="hm-fc-b"><small>FREE<?= $f['category_id'] && isset($cats[(int)$f['category_id']]) ? ' · ' . sh_e($cats[(int)$f['category_id']]['name']) : '' ?></small><b><?= sh_e($f['title']) ?></b></span></a></div>
  <?php endforeach; ?></div>
    <button type="button" class="hs-prev" aria-label="Previous">‹</button><button type="button" class="hs-next" aria-label="Next">›</button><div class="hs-dots"></div></div>
</div></section>
<?php endif; ?>

<?php if ($others): ?>
<section class="hm-sec hm-cats-s cp-others"><div class="sp-wrap">
  <div class="hm-h"><div><h2><?= $cat ? 'Other categories' : 'Categories' ?></h2></div><a href="/all-categories/">All categories ›</a></div>
  <div class="hm-cats"><?php foreach (array_slice($others, 0, 12) as $c): ?><a class="hm-cat" href="<?= sh_e(sh_cat_url($c['slug'])) ?>"><span class="hm-cat-i"><?php if ($c['icon']): ?><img src="<?= sh_e(sh_upload_url($c['icon'])) ?>" alt="" loading="lazy" width="44" height="44"><?php else: ?><b><?= sh_e(mb_strtoupper(mb_substr(preg_replace('/^Class\s+/i', '', $c['name']), 0, 2))) ?></b><?php endif; ?></span><span class="hm-cat-n"><?= sh_e($c['name']) ?></span><?= (int)$c['items'] ? '<small>' . (int)$c['items'] . ' course' . ((int)$c['items'] === 1 ? '' : 's') . '</small>' : '<small class="soon">Coming soon</small>' ?></a><?php endforeach; ?></div>
</div></section>
<?php endif; ?>
<script src="/assets/js/home.js?v=<?= @filemtime(ROOT_PATH . '/assets/js/home.js') ?: 1 ?>" defer></script>
<?php require ROOT_PATH . '/components/store-close.php'; ?>
