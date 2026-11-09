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
if ($t === '') $frees = sh_free_courses($pdo, 12, $cid); // courses ticked "Show in Free Courses"
$tNames = [];
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
<h1 class="screen-reader-text"><?= sh_e($cat ? $cat['name'] : $stTitle) ?></h1></div>

<?php if (!$upc && !$crs && !$nts && !$frees): ?>
<section class="hm-sec"><div class="sp-wrap"><div class="cp-empty"><?= $ico('notes') ?><b>Nothing here yet</b><p>New courses and notes<?= $cat ? ' for ' . sh_e($cat['name']) : '' ?> are coming soon.</p><a class="hm-btn hm-pro hm-splash" href="/courses/">See all courses <span class="hm-pro-a"><?= $ico('arrow') ?></span></a></div></div></section>
<?php endif; ?>

<?php if ($upc): // upcoming batches: cards (3 at a time on computers) in a slider; each card has its Upcoming tag ?>
<section class="hm-sec cp-up-s" id="upcoming" aria-label="Upcoming batches"><div class="sp-wrap">
  <div class="hm-ns cp-up" data-hs><div class="hs-track"><?php foreach ($upc as $p): ?><div class="hs-s"><?= sh_mini_card($p, $catName($p)) ?></div><?php endforeach; ?></div>
    <?php if (count($upc) > 1): ?><button type="button" class="hs-prev" aria-label="Previous">‹</button><button type="button" class="hs-next" aria-label="Next">›</button><div class="hs-dots"></div><?php endif; ?></div>
</div></section>
<?php endif; ?>

<?php if ($crs): ?>
<section class="hm-sec" id="courses"><div class="sp-wrap">
  <div class="hm-h"><div><h2><?= $cat ? sh_e($cat['name']) . ' courses' : 'Video courses' ?></h2><p class="hm-hsub">Recorded classes — watch anytime, as many times as you like.</p></div><a href="<?= sh_e($allC) ?>">View All »</a></div>
  <div class="hm-grid" id="cpCourses"><?php foreach ($crs as $p) echo sh_mini_card($p, $catName($p)); ?></div>
  <div class="hm-more"><?php if ($moreC || count($crs) > 4): ?><button type="button" class="hm-btn hm-pro hm-splash" data-more-grid="cpCourses" data-all="cpCoursesAll" data-t="course" data-w="launched" data-c="<?= $cid ?>" data-o="8" data-more="<?= $moreC ? 1 : 0 ?>">View more courses <span class="hm-pro-a"><?= $ico('arrow') ?></span></button><?php endif; ?><a class="hm-btn hm-pro hm-splash" id="cpCoursesAll" href="<?= sh_e($allC) ?>"<?= $moreC || count($crs) > 4 ? ' hidden' : '' ?>>See all courses <span class="hm-pro-a"><?= $ico('arrow') ?></span></a></div>
</div></section>
<?php endif; ?>

<?php if ($nts): ?>
<section class="hm-sec cp-notes" id="notes"><div class="sp-wrap">
  <div class="hm-h"><div><h2>PDF notes</h2><p class="hm-hsub">Chapter-wise notes and study material — read on any device.</p></div><a href="<?= sh_e($allN) ?>">View All »</a></div>
  <div class="hm-grid" id="cpNotes"><?php foreach ($nts as $p) echo sh_mini_card($p, $catName($p)); ?></div>
  <div class="hm-more"><?php if ($moreN || count($nts) > 4): ?><button type="button" class="hm-btn hm-pro hm-splash" data-more-grid="cpNotes" data-all="cpNotesAll" data-t="notes" data-c="<?= $cid ?>" data-o="8" data-more="<?= $moreN ? 1 : 0 ?>">View more notes <span class="hm-pro-a"><?= $ico('arrow') ?></span></button><?php endif; ?><a class="hm-btn hm-pro hm-splash" id="cpNotesAll" href="<?= sh_e($allN) ?>"<?= $moreN || count($nts) > 4 ? ' hidden' : '' ?>>See all notes <span class="hm-pro-a"><?= $ico('arrow') ?></span></a></div>
</div></section>
<?php endif; ?>

<?php if ($frees): ?>
<section class="hm-sec" id="free"><div class="sp-wrap">
  <div class="hm-h"><div><h2>Free courses</h2></div><a href="/free-courses/">View All »</a></div>
  <div class="hm-fs" data-hs><div class="hs-track"><?php foreach ($frees as $f): ?>
    <div class="hs-s"><?= sh_free_card($f, $f['category_id'] && isset($cats[(int)$f['category_id']]) ? $cats[(int)$f['category_id']]['name'] : '') ?></div>
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
