<?php
// Home page — DK Academy: slider, our courses (newest, 2×2 on phones + View more), categories, latest / upcoming batches,
// study materials, latest update (blog), free courses (YouTube), our impact, why choose us. Admin: Online Store → Homepage / Categories.
// The blog front page lives at /blog (blog.php).
require_once __DIR__ . '/includes/redirects.php';
edu_redirect_check();
$cf = __DIR__ . '/cache/index_home.html'; // index_* so publishing a post / Clear cache refreshes it too
$__czp = isset($_GET['czp']);
$__cs = @json_decode((string)@file_get_contents(__DIR__ . '/cache/.settings.json'), true) ?: [];
if (!empty($__cs['off'])) $__czp = true;
if (!$__czp && file_exists($cf) && time() - filemtime($cf) < min(900, (int)($__cs['home'] ?? 900))) { readfile($cf); exit; }
ob_start();
require_once __DIR__ . '/includes/config.php';
require_once INCLUDES_PATH . '/minify.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/settings.php';
require_once INCLUDES_PATH . '/theme.php';
require_once INCLUDES_PATH . '/ads.php';
require_once INCLUDES_PATH . '/institute.php';
require_once INCLUDES_PATH . '/shop.php';
$pdo = sh_db();
$inst = ci_institute();
$brand = $inst['brand']['title'] ?: edu_site_name();
$cats = sh_categories($pdo);
$catName = fn(array $p) => $cats[(int)($p['category_id'] ?? 0)]['name'] ?? '';
$slides = $pdo->query("SELECT * FROM hm_slides WHERE active = 1 ORDER BY sort, id")->fetchAll(PDO::FETCH_ASSOC);
if (!$slides) $slides = [ // until the admin adds slides (Online Store → Homepage): ready-made picture banners
    ['image' => 'assets/img/home/default-board.jpg', 'mobile_image' => 'assets/img/home/default-board-m.jpg', 'link' => '/courses/', 'title' => 'New batches — admissions open', 'new_tab' => 0],
    ['image' => 'assets/img/home/default-free.jpg', 'mobile_image' => 'assets/img/home/default-free-m.jpg', 'link' => '/free-courses/', 'title' => 'Free courses for every student', 'new_tab' => 0],
    ['image' => 'assets/img/home/default-notes.jpg', 'mobile_image' => 'assets/img/home/default-notes-m.jpg', 'link' => '/notes/', 'title' => 'Notes and study materials', 'new_tab' => 0]];
$cfg = sh_home_cfg(); // layout + options from Customize → Homepage
$cw = $cfg['courses_filter'] === 'all' ? '' : $cfg['courses_filter'];
$courses = sh_latest($pdo, 'course', 9, 0, 0, $cw); // 8 shown, the 9th tells if "View more" has anything
$moreCourses = count($courses) > 8; $courses = array_slice($courses, 0, 8);
$up = $pdo->query("SELECT p.*, (SELECT COUNT(*) FROM sh_lessons l WHERE l.product_id = p.id) AS lessons, (SELECT COUNT(*) FROM sh_files f WHERE f.product_id = p.id) AS files
    FROM sh_products p WHERE p.status = 'published' AND p.type = 'course' AND p.starts_at >= CURDATE() ORDER BY p.starts_at LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);
if ($cfg['batches_filter'] === 'launched') { $up = []; $batches = sh_latest($pdo, 'course', 6, 0, 0, 'launched'); }
else {
    $batches = $up; $seen = array_column($up, 'id');
    if ($cfg['batches_filter'] === 'all') foreach (sh_latest($pdo, 'course', 8) as $p) { if (count($batches) >= 6) break; if (!in_array($p['id'], $seen)) $batches[] = $p; }
}
$notes = sh_latest($pdo, 'notes', 12, 0, 0, '', $cfg['notes_sort']);
$free = sh_free_courses($pdo, 12); // courses ticked "Show in Free Courses"
$posts = $pdo->query("SELECT p.id, p.title, p.slug, p.date, m.file_path AS img, c.name AS cat FROM posts p LEFT JOIN media m ON m.id = p.featured_image_id
    LEFT JOIN categories c ON c.id = p.category_id WHERE p.status = 'published' ORDER BY p.date DESC LIMIT " . (int)$cfg['posts_count'])->fetchAll(PDO::FETCH_ASSOC);
$teacherNames = [];
foreach ($batches as $p) if (!empty($p['teacher_id'])) $teacherNames[(int)$p['teacher_id']] ??= sh_teacher_name($pdo, (int)$p['teacher_id']);
// "Our Impact": numbers counted by the site unless the admin typed their own
$nStudents = (int)$pdo->query("SELECT COUNT(*) FROM sh_users")->fetchColumn();
try { $nStudents += (int)$pdo->query("SELECT COUNT(*) FROM ci_students s WHERE NOT EXISTS (SELECT 1 FROM sh_users u WHERE u.student_id = s.id)")->fetchColumn(); } catch (Throwable) {}
$auto = [
    'students' => $nStudents,
    'courses' => (int)$pdo->query("SELECT COUNT(*) FROM sh_products WHERE status = 'published' AND type = 'course'")->fetchColumn(),
    'free' => (int)$pdo->query("SELECT COUNT(*) FROM sh_lessons l JOIN sh_products p ON p.id = l.product_id WHERE p.status = 'published' AND (l.free = 1 OR p.price <= 0)")->fetchColumn() + (int)$pdo->query("SELECT COALESCE(SUM(videos),0) FROM hm_free WHERE active = 1")->fetchColumn(),
    'notes' => (int)$pdo->query("SELECT COUNT(*) FROM sh_files f JOIN sh_products p ON p.id = f.product_id WHERE p.status = 'published'")->fetchColumn(),
];
$teachers = sh_home_teachers($pdo);
$toppers = $pdo->query("SELECT * FROM hm_toppers WHERE active = 1 ORDER BY sort, id")->fetchAll(PDO::FETCH_ASSOC);
$impact = [];
foreach ($cfg['impact'] as $it) { $v = trim((string)$it['v']); if ($v === '' && isset($auto[$it['k'] ?? ''])) $v = number_format($auto[$it['k']]) . ($auto[$it['k']] >= 10 ? '+' : ''); if ($v !== '') $impact[] = [$it['l'], $v, $it['k'] ?? '', (string)($it['i'] ?? '')]; }
$classes = [];
try { $classes = $pdo->query("SELECT name FROM ci_classes ORDER BY sort, name")->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable) {}
$range = $classes ? reset($classes) . ' – ' . end($classes) : '5th – 12th';
$feat = $courses[0] ?? null;
$seo = [
    'title' => $brand . ' — Coaching for Classes ' . $range . ' | Online Courses & PDF Notes',
    'description' => es('general', 'home_description') !== '' ? es('general', 'home_description') : $brand . ' — expert teachers, recorded video courses and PDF notes for school students. Learn at the institute or online on any phone.',
    'image' => SEO_DEFAULT_IMAGE, 'type' => 'website', 'robots' => SEO_DEFAULT_ROBOTS,
];
require_once INCLUDES_PATH . '/seo.php';
$seo = edu_seo_apply($seo, []);
$seo_ctx = ['kind' => 'home'];
$ico = fn($n) => sh_icon($n);
$ttl = fn(string $id, string $def = '') => $cfg['titles'][$id] !== '' ? $cfg['titles'][$id] : ($def !== '' ? $def : SH_HOME_SECTIONS[$id]);
$S = []; // each section's HTML, printed below in the order set in Customize → Homepage
$short0 = function (string $n) { $p = preg_split('/\s+/', trim(preg_replace('/\(.*?\)/', '', $n))); return trim(($p[0] ?? '') . (isset($p[1]) ? ' ' . mb_substr($p[1], 0, 1) . '.' : '')); };
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars(edu_shortcodes((string)$seo['title'], true), ENT_QUOTES, 'UTF-8') ?></title>
<?php require_once ROOT_PATH . '/components/head_script.php'; ?>
<link rel="stylesheet" href="/assets/css/store.css?v=<?= @filemtime(ROOT_PATH . '/assets/css/store.css') ?: 1 ?>">
<link rel="stylesheet" href="/assets/css/home.css?v=<?= @filemtime(ROOT_PATH . '/assets/css/home.css') ?: 1 ?>">
<?php if ($slides): $s0 = $slides[0]; ?><link rel="preload" as="image" href="<?= sh_e(sh_upload_url($s0['image'])) ?>"<?= $s0['mobile_image'] ? ' media="(min-width:701px)"' : '' ?>><?php if ($s0['mobile_image']): ?><link rel="preload" as="image" href="<?= sh_e(sh_upload_url($s0['mobile_image'])) ?>" media="(max-width:700px)"><?php endif; ?><?php endif; ?>
<script type="application/ld+json"><?= json_encode(array_filter(['@context' => 'https://schema.org', '@type' => 'EducationalOrganization', 'name' => $brand, 'url' => SITE_URL,
    'address' => $inst['address'] ?: null, 'telephone' => $inst['phone'] ?: null, 'email' => $inst['email'] ?: null]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
</head>
<body class="hm-home">
<?php require_once ROOT_PATH . '/components/header.php'; ?>
<main class="st hm">

<?php ob_start(); ?><?php /* 1. slider */ ?>
<section class="hm-hs" data-hs data-auto="5000" aria-roledescription="carousel" aria-label="Highlights">
  <div class="hs-track">
  <?php if ($slides): foreach ($slides as $i => $sl): $img = sh_e(sh_upload_url($sl['image'])); $alt = sh_e($sl['title'] ?: $brand); ?>
    <div class="hs-s hm-slide<?= isset($sl['show_m']) && !(int)$sl['show_m'] ? ' hv-m' : '' ?><?= isset($sl['show_t']) && !(int)$sl['show_t'] ? ' hv-t' : '' ?><?= isset($sl['show_d']) && !(int)$sl['show_d'] ? ' hv-d' : '' ?>" role="group" aria-label="Slide <?= $i + 1 ?> of <?= count($slides) ?>"><?php if ($sl['link']): ?><a href="<?= sh_e($sl['link']) ?>"<?= $sl['new_tab'] ? ' target="_blank" rel="noopener"' : '' ?>><?php endif; ?>
      <picture><?php if ($sl['mobile_image']): ?><source media="(max-width:700px)" srcset="<?= sh_e(sh_upload_url($sl['mobile_image'])) ?>"><?php endif; ?><img src="<?= $img ?>" alt="<?= $alt ?>"<?= $i ? ' loading="lazy"' : ' fetchpriority="high"' ?>></picture>
    <?php if ($sl['link']): ?></a><?php endif; ?></div>
  <?php endforeach; endif; ?>
  </div>
  <?php if (count($slides) > 1): ?><button type="button" class="hs-prev" aria-label="Previous slide">‹</button><button type="button" class="hs-next" aria-label="Next slide">›</button><div class="hs-dots"></div><?php endif; ?>
</section>
<?php $S['slider'] = ob_get_clean(); ?>

<?php ob_start(); ?><?php /* 2. our courses: newest first, 2 rows (2 × 2 on phones), slider + View more */ ?>
<section class="hm-sec" id="courses"><div class="sp-wrap">
  <div class="hm-h"><div><h2><?= sh_e($ttl('courses')) ?></h2></div><a href="/courses/">View All »</a></div>
  <?php if (!$courses): ?><div class="st-empty">New courses are coming soon.</div><?php else: ?>
  <div class="hm-grid" id="hmCourses"><?php foreach ($courses as $p) echo sh_mini_card($p, $catName($p)); ?></div>
  <div class="hm-more"><?php if ($moreCourses || count($courses) > 4): ?><button type="button" class="hm-btn hm-pro hm-splash" id="hmMore" data-o="8" data-w="<?= sh_e($cw) ?>" data-more="<?= $moreCourses ? 1 : 0 ?>">View more courses <span class="hm-pro-a"><?= $ico('arrow') ?></span></button><?php endif; ?><a class="hm-btn hm-pro hm-splash" id="hmAll" href="/courses/"<?= $moreCourses || count($courses) > 4 ? ' hidden' : '' ?>>See all courses <span class="hm-pro-a"><?= $ico('arrow') ?></span></a></div>
  <?php endif; ?>
</div></section>
<?php $S['courses'] = ob_get_clean(); ?>

<?php ob_start(); ?><?php /* 3. categories */ ?>
<?php if ($cats): ?>
<section class="hm-sec hm-cats-s"><div class="sp-wrap">
  <?php $cd = $cfg['cats_d']; $cm = $cfg['cats_m']; $nc = count($cats); $hasMore = ($cd && $nc > $cd) || ($cm && $nc > $cm); $ci = 0; ?>
  <div class="hm-h"><div><h2><?= sh_e($ttl('categories')) ?></h2></div><?php if ($hasMore): ?><button type="button" class="hm-cats-all<?= $cd && $nc > $cd ? ' d' : '' ?><?= $cm && $nc > $cm ? ' m' : '' ?>" aria-expanded="false" data-more="View All »" data-less="Show less">View All »</button><?php else: ?><a href="/courses/">View All »</a><?php endif; ?></div>
  <div class="hm-cats hm-cats-n" id="hmCats" style="--cc-d:<?= (int)$cfg['cats_cols_d'] ?>;--cc-m:<?= (int)$cfg['cats_cols_m'] ?>"><?php foreach ($cats as $c): $ci++; $cv = (string)($cfg['cats_vis'][(int)$c['id']] ?? '111'); if ($cv === '000') continue; ?><a class="hm-cat<?= $cv[0] === '0' ? ' hv-m' : '' ?><?= $cv[1] === '0' ? ' hv-t' : '' ?><?= $cv[2] === '0' ? ' hv-d' : '' ?>" href="<?= sh_e(sh_cat_url($c['slug'])) ?>"><span class="hm-cat-i"><?php if ($c['icon']): ?><img src="<?= sh_e(sh_upload_url($c['icon'])) ?>" alt="" loading="lazy" width="44" height="44"><?php else: ?><b><?= sh_e(mb_strtoupper(mb_substr(preg_replace('/^Class\s+/i', '', $c['name']), 0, 2))) ?></b><?php endif; ?></span><span class="hm-cat-n"><?= sh_e($c['name']) ?></span><?= (int)$c['items'] ? '<small>' . (int)$c['items'] . ' course' . ((int)$c['items'] === 1 ? '' : 's') . '</small>' : '<small class="soon">Coming soon</small>' ?></a><?php endforeach; ?></div>
</div></section>
<?php endif; ?>
<?php $S['categories'] = ob_get_clean(); ?>

<?php ob_start(); ?><?php /* 4. latest courses / upcoming batches — one at a time */ ?>
<?php if ($batches): ?>
<section class="hm-sec hm-bt-s"><div class="sp-wrap">
  <div class="hm-h"><div><h2><?= sh_e($ttl('batches', $cfg['batches_filter'] === 'upcoming' ? 'Upcoming batches' : ($up ? 'Latest courses & upcoming batches' : 'Latest courses'))) ?></h2></div></div>
  <div class="hm-bt" data-hs data-auto="6500">
    <div class="hs-track"><?php foreach ($batches as $p): [$bt, $isUp] = sh_batch($p); $tn = $teacherNames[(int)($p['teacher_id'] ?? 0)] ?? ''; ?>
      <div class="hs-s"><div class="hm-btc">
        <a class="hm-btc-i" href="<?= sh_e(sh_url($p)) ?>" tabindex="-1" aria-hidden="true"><?= sh_thumb_html($p) ?></a>
        <div class="hm-btc-b">
          <div class="hm-btc-tags"><?= $isUp ? '<span class="t-up">Upcoming batch</span>' : '<span class="t-new">New</span>' ?><?= $catName($p) !== '' ? '<span>' . sh_e($catName($p)) . '</span>' : '' ?></div>
          <h3><a href="<?= sh_e(sh_url($p)) ?>"><?= sh_e($p['title']) ?></a></h3>
          <?php if ($p['subtitle']): ?><p><?= sh_e($p['subtitle']) ?></p><?php endif; ?>
          <ul class="hm-btc-m"><?php if ($bt !== ''): ?><li><?= $ico('calendar') ?> <?= sh_e($bt) ?></li><?php endif; ?><?php if ($tn !== ''): ?><li><?= $ico('user') ?> <?= sh_e($tn) ?></li><?php endif; ?><li><?= $ico('video') ?> <?= (int)$p['lessons'] ?> lecture<?= (int)$p['lessons'] === 1 ? '' : 's' ?></li></ul>
          <div class="hm-btc-f"><span class="hm-btc-p"><?= sh_price_html($p) ?></span><a class="hm-btn hm-splash" href="<?= sh_e(sh_url($p)) ?>"><?= $isUp ? 'Join this batch' : sh_cta($p, 'View course') ?> <?= $ico('arrow') ?></a></div>
        </div></div></div>
    <?php endforeach; ?></div>
    <?php if (count($batches) > 1): ?><button type="button" class="hs-prev" aria-label="Previous">‹</button><button type="button" class="hs-next" aria-label="Next">›</button><div class="hs-dots"></div><?php endif; ?>
  </div>
</div></section>
<?php endif; ?>
<?php $S['batches'] = ob_get_clean(); ?>

<?php ob_start(); ?><?php /* 5. study materials (PDF notes) */ ?>
<?php if ($notes): ?>
<section class="hm-sec"><div class="sp-wrap">
  <div class="hm-h"><div><h2><?= sh_e($ttl('notes')) ?></h2></div><a href="/notes/">View All »</a></div>
  <div class="hm-ns" data-hs><div class="hs-track"><?php foreach ($notes as $p): ?><div class="hs-s"><?= sh_mini_card($p, $catName($p)) ?></div><?php endforeach; ?></div>
    <button type="button" class="hs-prev" aria-label="Previous">‹</button><button type="button" class="hs-next" aria-label="Next">›</button><div class="hs-dots"></div></div>
  <div class="hm-more"><a class="hm-btn hm-pro hm-splash" href="/notes/">Study materials <span class="hm-pro-a"><?= $ico('arrow') ?></span></a></div>
</div></section>
<?php endif; ?>
<?php $S['notes'] = ob_get_clean(); ?>

<?php ob_start(); ?><?php /* 6. latest update — newest blog posts */ ?>
<?php if ($posts): ?>
<section class="hm-sec hm-ps-s"><div class="sp-wrap">
  <div class="hm-h"><div><h2><?= sh_e($ttl('posts')) ?></h2></div><a href="/blog">View All »</a></div>
  <div class="hm-ps" data-hs><div class="hs-track"><?php foreach ($posts as $po): $pimg = (string)$po['img']; ?>
    <div class="hs-s"><a class="hm-post" href="<?= htmlspecialchars(postUrl($po['slug'], $po['id'])) ?>"><span class="hm-post-i"><?php if ($pimg !== ''): ?><img src="<?= htmlspecialchars(preg_match('#^https?://#', $pimg) ? $pimg : '/' . ltrim($pimg, '/')) ?>" alt="" loading="lazy" onerror="this.remove()"><?php endif; ?></span>
      <span class="hm-post-b"><?php if ($po['cat']): ?><small class="hm-post-c"><?= htmlspecialchars($po['cat']) ?></small><?php endif; ?><b><?= htmlspecialchars($po['title']) ?></b><small><?= date('d M Y', strtotime($po['date'])) ?></small></span></a></div>
  <?php endforeach; ?></div>
    <button type="button" class="hs-prev" aria-label="Previous">‹</button><button type="button" class="hs-next" aria-label="Next">›</button><div class="hs-dots"></div></div>
</div></section>
<?php endif; ?>
<?php $S['posts'] = ob_get_clean(); ?>

<?php ob_start(); ?><?php /* 7. free courses — YouTube playlists */ ?>
<?php if ($free): ?>
<section class="hm-sec"><div class="sp-wrap">
  <div class="hm-h"><div><h2><?= sh_e($ttl('free')) ?></h2></div><a href="/free-courses/">View All »</a></div>
  <div class="hm-fs" data-hs><div class="hs-track"><?php foreach ($free as $f): ?>
    <div class="hs-s"><?= sh_free_card($f, $f['category_id'] && isset($cats[(int)$f['category_id']]) ? $cats[(int)$f['category_id']]['name'] : '') ?></div>
  <?php endforeach; ?></div>
    <button type="button" class="hs-prev" aria-label="Previous">‹</button><button type="button" class="hs-next" aria-label="Next">›</button><div class="hs-dots"></div></div>
</div></section>
<?php endif; ?>
<?php $S['free'] = ob_get_clean(); ?>

<?php ob_start(); ?><?php /* 8. our impact */ ?>
<?php if ($impact): ?>
<section class="hm-imp"><div class="sp-wrap">
  <div class="hm-h c"><h2><?= sh_e($ttl('impact')) ?></h2></div>
  <div class="hm-imp-g"><?php $ii = ['students' => 'user', 'courses' => 'video', 'free' => 'playsolid', 'notes' => 'notes']; foreach ($impact as [$l, $v, $k, $iic]): ?><div><span class="hm-imp-i"><?= $iic !== '' ? sh_pick_icon($iic) : $ico($ii[$k] ?? 'gauge') ?></span><span class="hm-imp-t"><b data-count="<?= sh_e($v) ?>"><?= sh_e($v) ?></b><small><?= sh_e($l) ?></small></span></div><?php endforeach; ?></div>
</div></section>
<?php endif; ?>
<?php $S['impact'] = ob_get_clean(); ?>

<?php ob_start(); ?><?php /* 9. why choose us */ ?>
<section class="hm-sec hm-why"><div class="sp-wrap hm-why-in">
  <div class="hm-why-l">
    <h2><?= htmlspecialchars($ttl('why', 'Why choose ' . $brand)) ?></h2>
    <?php if ($cfg['why_text'] !== ''): ?><p><?= htmlspecialchars($cfg['why_text']) ?></p><?php endif; ?>
    <div class="hm-why-cta"><a class="hm-btn" href="/courses/"><?= $ico('compass') ?> Explore courses</a></div>
  </div>
  <ol class="hm-why-g"><?php $wi = ['user', 'layers', 'play', 'pen', 'notes', 'shield', 'check', 'gauge'];
    // icon from the point's words, so a point typed in admin still gets a fitting one
    $wk = ['board|smart|digital' => 'board', 'mcq|quiz' => 'quiz', 'test|exam' => 'calendar', 'growth|guidance|progress' => 'trend', 'competitive|zero|level|rank' => 'trophy', 'doubt' => 'chat', 'teacher|faculty' => 'user', 'pace|record|any ?time|video' => 'play', 'note|pdf' => 'notes', 'course|chapter' => 'layers'];
    foreach ($cfg['why'] as $i => $w): $wn = $wi[$i % count($wi)]; foreach ($wk as $rx => $n) if (preg_match('/' . $rx . '/i', $w['t'])) { $wn = $n; break; } ?>
    <li><span class="hm-why-i"><?= ($w['i'] ?? '') !== '' ? sh_pick_icon((string)$w['i']) : $ico($wn) ?></span><b><?= htmlspecialchars($w['t']) ?></b></li>
  <?php endforeach; ?></ol>
</div></section>
<?php $S['why'] = ob_get_clean(); ?>

<?php /* 10. meet our teachers · 11. meet our toppers (includes/home-people.php) */
require_once INCLUDES_PATH . '/home-people.php';
$S['teachers'] = hm_teachers_html($teachers, '3', $ttl('teachers')); // profile rows with a passport photo
$S['toppers'] = hm_toppers_html($toppers, '4', $ttl('toppers'));     // passport cards: photo, name, marks, class

// print the sections in the chosen order (hidden ones are skipped); data-hm lets the Customizer scroll the preview to one
foreach ($cfg['order'] as $id) {
    if (empty($cfg['on'][$id]) || trim($S[$id] ?? '') === '') continue;
    echo preg_replace('/<section\b/', '<section data-hm="' . $id . '"', $S[$id], 1);
} ?>
</main>
<?php require_once ROOT_PATH . '/components/footer.php'; ?>
<script src="/assets/js/store.js?v=<?= @filemtime(ROOT_PATH . '/assets/js/store.js') ?: 1 ?>" defer></script>
<script src="/assets/js/home.js?v=<?= @filemtime(ROOT_PATH . '/assets/js/home.js') ?: 1 ?>" defer></script>
</body>
</html>
<?php $minified = minifyHTML(ob_get_clean()); if (empty($__czp)) { @file_put_contents($cf, $minified); } echo $minified; ?>
