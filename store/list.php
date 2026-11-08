<?php
// Listing pages (menu strip):  /courses/ all video courses · /upcoming-courses/ batches that start today or later ·
// /notes/ PDF notes · /all-categories/ every class / category.  Search (?q=), class filter (?c=slug), free / paid (?f=),
// 12 a page (?page=N). Cards are the homepage ones (sh_mini_card); the old /store/ page stays as it was.
if (isset($_GET['s'])) { require __DIR__ . '/item.php'; return; } // /courses/?s=slug (no clean addresses) → the course page
require_once dirname(__DIR__) . '/includes/config.php';
require_once INCLUDES_PATH . '/shop.php';
$pdo = sh_db();
if (!sh_on()) { http_response_code(404); require ROOT_PATH . '/templates/page_not_found.php'; exit; }
$lsMode = in_array($_GET['m'] ?? '', ['courses', 'upcoming', 'notes', 'cats'], true) ? $_GET['m'] : 'courses';
$base = ['courses' => '/courses/', 'upcoming' => '/upcoming-courses/', 'notes' => '/notes/', 'cats' => '/all-categories/'][$lsMode];
$per = 12;
$pg = max(1, (int)($_GET['page'] ?? 1));
$qq = trim(mb_substr((string)($_GET['q'] ?? ''), 0, 80));
$fl = in_array($_GET['f'] ?? '', ['free', 'paid'], true) ? $_GET['f'] : '';
$cats = sh_categories($pdo);
$catBy = []; foreach ($cats as $c) $catBy[$c['slug']] = $c;
$cs = (string)($_GET['c'] ?? ''); $cat = $catBy[$cs] ?? null;
$catName = fn(array $p) => $cats[(int)($p['category_id'] ?? 0)]['name'] ?? '';
$tNames = [];

// ---- what this page lists ----
$rows = []; $total = 0;
if ($lsMode === 'cats') {
    $all = array_values($cats);
    if ($qq !== '') $all = array_values(array_filter($all, fn($c) => mb_stripos($c['name'], $qq) !== false));
    $total = count($all);
    $pages = max(1, (int)ceil($total / $per)); $pg = min($pg, $pages);
    $rows = array_slice($all, ($pg - 1) * $per, $per);
    // how many courses / notes in each
    $cnt = [];
    foreach ($pdo->query("SELECT category_id, type, COUNT(*) n FROM sh_products WHERE status = 'published' GROUP BY category_id, type")->fetchAll(PDO::FETCH_ASSOC) as $r) $cnt[(int)$r['category_id']][$r['type']] = (int)$r['n'];
} else {
    $w = "p.status = 'published' AND p.type = " . ($lsMode === 'notes' ? "'notes'" : "'course'"); $a = [];
    if ($lsMode === 'upcoming') $w .= " AND p.starts_at >= CURDATE()";
    if ($cat) $w .= ' AND p.category_id = ' . (int)$cat['id'];
    if ($qq !== '') { $w .= " AND (p.title LIKE ? OR p.subtitle LIKE ? OR p.description LIKE ?)"; array_push($a, "%$qq%", "%$qq%", "%$qq%"); }
    if ($fl === 'free') $w .= ' AND p.price <= 0'; elseif ($fl === 'paid') $w .= ' AND p.price > 0';
    $q = $pdo->prepare("SELECT COUNT(*) FROM sh_products p WHERE $w"); $q->execute($a); $total = (int)$q->fetchColumn();
    $pages = max(1, (int)ceil($total / $per)); $pg = min($pg, $pages);
    $order = $lsMode === 'upcoming' ? 'p.starts_at, p.id DESC' : 'p.id DESC';
    $q = $pdo->prepare("SELECT p.*, (SELECT COUNT(*) FROM sh_lessons l WHERE l.product_id = p.id) AS lessons, (SELECT COUNT(*) FROM sh_files f WHERE f.product_id = p.id) AS files
        FROM sh_products p WHERE $w ORDER BY $order LIMIT $per OFFSET " . (($pg - 1) * $per));
    $q->execute($a); $rows = $q->fetchAll(PDO::FETCH_ASSOC);
}

// ---- page text / SEO ----
$H = ['courses' => ['All Courses', 'Every recorded video course — newest first.'], 'upcoming' => ['Upcoming Courses', 'New batches starting soon — join before the first class.'],
    'notes' => ['PDF Notes', 'Chapter-wise notes and study material — read on any phone or computer.'], 'cats' => ['All Categories', 'Find courses and notes for your class.']][$lsMode];
$stTitle = $H[0] . ($cat ? ' — ' . $cat['name'] : '') . ($pg > 1 ? ' — Page ' . $pg : '');
$stDesc = $H[1] . ' ' . edu_site_name() . '.';
$stIndex = $qq === '' && $fl === '';
$link = function (array $over = []) use ($base, $qq, $fl, $cs, $pg) {
    $q = array_filter(array_merge(['q' => $qq, 'f' => $fl, 'c' => $cs, 'page' => $pg > 1 ? $pg : ''], $over), fn($v) => $v !== '' && $v !== null && $v !== 1 && $v !== '1');
    if (isset($over['page']) && (int)$over['page'] > 1) $q['page'] = (int)$over['page'];
    return $base . ($q ? '?' . http_build_query($q) : '');
};
$seo_canonical = rtrim(SITE_URL, '/') . $link(['q' => '', 'f' => '']);
require ROOT_PATH . '/components/store-open.php';
$ico = fn($n) => sh_icon($n);
?>
<link rel="stylesheet" href="/assets/css/home.css?v=<?= @filemtime(ROOT_PATH . '/assets/css/home.css') ?: 1 ?>">
<div class="ls">
  <p class="st-crumb"><a href="/">Home</a> › <?= $cat ? '<a href="' . sh_e($base) . '">' . sh_e($H[0]) . '</a> › ' . sh_e($cat['name']) : sh_e($H[0]) ?></p>
  <header class="ls-hd">
    <div><h1><?= sh_e($H[0]) ?><?= $cat ? ' <span>· ' . sh_e($cat['name']) . '</span>' : '' ?></h1></div>
    <form class="ls-search" method="get" action="<?= sh_e($base) ?>" role="search">
      <?php if ($cs !== ''): ?><input type="hidden" name="c" value="<?= sh_e($cs) ?>"><?php endif; ?><?php if ($fl !== ''): ?><input type="hidden" name="f" value="<?= sh_e($fl) ?>"><?php endif; ?>
      <?= $ico('compass') ?><input type="search" name="q" value="<?= sh_e($qq) ?>" placeholder="<?= $lsMode === 'cats' ? 'Search a class…' : 'Search ' . strtolower($H[0]) . '…' ?>" aria-label="Search"><button type="submit">Search</button>
    </form>
  </header>
  <p class="ls-count"><?= $total ?> <?= $lsMode === 'cats' ? ($total === 1 ? 'category' : 'categories') : ($lsMode === 'notes' ? ($total === 1 ? 'notes pack' : 'notes packs') : ($total === 1 ? 'course' : 'courses')) ?><?= $qq !== '' ? ' for “' . sh_e($qq) . '”' : '' ?><?= $pages > 1 ? ' · page ' . $pg . ' of ' . $pages : '' ?></p>

  <?php if (!$rows): ?>
    <div class="st-empty"><?= $lsMode === 'upcoming' ? 'No upcoming batch right now — new batches are announced here first.' : ($qq !== '' || $fl !== '' || $cat ? 'Nothing matches — try another class or clear the search.' : 'Coming soon.') ?><?php if ($qq !== '' || $fl !== '' || $cat): ?> <a href="<?= sh_e($base) ?>">Show all</a><?php endif; ?></div>
  <?php elseif ($lsMode === 'cats'): ?>
    <div class="hm-cats ls-cats"><?php foreach ($rows as $c): $nc = $cnt[(int)$c['id']]['course'] ?? 0; $nn = $cnt[(int)$c['id']]['notes'] ?? 0; ?>
      <a class="hm-cat" href="<?= sh_e(sh_cat_url($c['slug'])) ?>"><span class="hm-cat-i"><?php if ($c['icon']): ?><img src="<?= sh_e(sh_upload_url($c['icon'])) ?>" alt="" loading="lazy" width="44" height="44"><?php else: ?><b><?= sh_e(mb_strtoupper(mb_substr(preg_replace('/^Class\s+/i', '', $c['name']), 0, 2))) ?></b><?php endif; ?></span>
        <span class="hm-cat-n"><?= sh_e($c['name']) ?></span><?= $nc || $nn ? '<small>' . ($nc ? $nc . ' course' . ($nc === 1 ? '' : 's') : '') . ($nc && $nn ? ' · ' : '') . ($nn ? $nn . ' notes' : '') . '</small>' : '<small class="soon">Coming soon</small>' ?></a>
    <?php endforeach; ?></div>
  <?php else: ?>
    <div class="ls-list"><?php foreach ($rows as $p): [$bt, $isUp] = sh_batch($p); $isNotes = $p['type'] === 'notes'; $free = (float)$p['price'] <= 0;
      $tn = !empty($p['teacher_id']) ? ($tNames[(int)$p['teacher_id']] ??= sh_teacher_name($pdo, (int)$p['teacher_id'])) : ''; ?>
      <div class="hm-btc ls-card">
        <a class="hm-btc-i" href="<?= sh_e(sh_url($p)) ?>" tabindex="-1" aria-hidden="true"><?= sh_thumb_html($p) ?></a>
        <div class="hm-btc-b">
          <div class="hm-btc-tags"><?= $isUp ? '<span class="t-up">Upcoming batch</span>' : ($isNotes ? '<span class="t-new">PDF notes</span>' : '<span class="t-new">Video course</span>') ?><?= $catName($p) !== '' ? '<span>' . sh_e($catName($p)) . '</span>' : '' ?><?= $free ? '<span class="t-free hm-splash">Free</span>' : '' ?></div>
          <h3><a href="<?= sh_e(sh_url($p)) ?>"><?= sh_e($p['title']) ?></a></h3>
          <?php if ($p['subtitle']): ?><p><?= sh_e($p['subtitle']) ?></p><?php endif; ?>
          <ul class="hm-btc-m"><?php if ($bt !== ''): ?><li><?= $ico('calendar') ?> <?= sh_e($bt) ?></li><?php endif; ?><?php if ($tn !== ''): ?><li><?= $ico('user') ?> <?= sh_e($tn) ?></li><?php endif; ?>
            <?php if ($isNotes): ?><li><?= $ico('pdf') ?> <?= (int)$p['files'] ?> PDF<?= (int)$p['files'] === 1 ? '' : 's' ?></li><?php else: ?><li><?= $ico('video') ?> <?= (int)$p['lessons'] ?> lecture<?= (int)$p['lessons'] === 1 ? '' : 's' ?></li><?php endif; ?>
            <?php if ((int)$p['validity_days']): ?><li><?= $ico('clock') ?> <?= (int)$p['validity_days'] ?> days access</li><?php endif; ?></ul>
          <div class="hm-btc-f"><span class="hm-btc-p"><?= sh_price_html($p) ?></span><a class="hm-btn hm-splash" href="<?= sh_e(sh_url($p)) ?>"><?= $isUp ? 'Join this batch' : ($isNotes ? 'View notes' : 'View course') ?> <?= $ico('arrow') ?></a></div>
        </div></div>
    <?php endforeach; ?></div>
  <?php endif; ?>

  <?php if ($pages > 1): // pagination: ‹ 1 … 4 5 6 … 12 › ?>
  <nav class="ls-pg" aria-label="Pages">
    <?php if ($pg > 1): ?><a href="<?= sh_e($link(['page' => $pg - 1])) ?>" rel="prev" aria-label="Previous page">‹ Prev</a><?php else: ?><span class="off">‹ Prev</span><?php endif; ?>
    <?php $shown = array_unique(array_filter([1, $pg - 1, $pg, $pg + 1, $pages], fn($n) => $n >= 1 && $n <= $pages)); sort($shown); $prevN = 0;
    foreach ($shown as $n): if ($n - $prevN > 1): ?><span class="gap">…</span><?php endif; $prevN = $n; ?>
      <?php if ($n === $pg): ?><span class="on" aria-current="page"><?= $n ?></span><?php else: ?><a href="<?= sh_e($link(['page' => $n])) ?>"><?= $n ?></a><?php endif; ?>
    <?php endforeach; ?>
    <?php if ($pg < $pages): ?><a href="<?= sh_e($link(['page' => $pg + 1])) ?>" rel="next" aria-label="Next page">Next ›</a><?php else: ?><span class="off">Next ›</span><?php endif; ?>
  </nav>
  <?php endif; ?>
</div>
<script src="/assets/js/home.js?v=<?= @filemtime(ROOT_PATH . '/assets/js/home.js') ?: 1 ?>" defer></script>
<?php require ROOT_PATH . '/components/store-close.php'; ?>
