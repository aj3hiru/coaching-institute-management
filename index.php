<?php
// Home page — DK Academy (coaching institute): courses, PDF notes, why us, reviews, latest updates, visit / call.
// The old blog / job-news front page now lives at /latest-update (latest-update.php).
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
$courses = sh_popular($pdo, [], 10, 'course');
$notes = sh_popular($pdo, [], 4, 'notes');
$rv = $pdo->query("SELECT r.rating, r.body, u.name, p.title FROM sh_reviews r JOIN sh_users u ON u.id = r.user_id JOIN sh_products p ON p.id = r.product_id
    WHERE r.status = 'published' AND r.body IS NOT NULL AND p.status = 'published' ORDER BY r.rating DESC, r.created_at DESC LIMIT 12")->fetchAll(PDO::FETCH_ASSOC);
$avg = $pdo->query("SELECT ROUND(AVG(rating),1) a, COUNT(*) n FROM sh_reviews WHERE status = 'published'")->fetch(PDO::FETCH_ASSOC);
$nStudents = (int)$pdo->query("SELECT COUNT(*) FROM sh_users")->fetchColumn();
try { $nStudents += (int)$pdo->query("SELECT COUNT(*) FROM ci_students s WHERE NOT EXISTS (SELECT 1 FROM sh_users u WHERE u.student_id = s.id)")->fetchColumn(); } catch (Throwable) {}
$nCourses = (int)$pdo->query("SELECT COUNT(*) FROM sh_products WHERE status = 'published' AND type = 'course'")->fetchColumn();
$nNotes = (int)$pdo->query("SELECT COUNT(*) FROM sh_products WHERE status = 'published' AND type = 'notes'")->fetchColumn();
$nLessons = (int)$pdo->query("SELECT COUNT(*) FROM sh_lessons l JOIN sh_products p ON p.id = l.product_id WHERE p.status = 'published'")->fetchColumn();
$classes = [];
try { $classes = $pdo->query("SELECT name FROM ci_classes ORDER BY sort, name")->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable) {}
$posts = $pdo->query("SELECT p.id, p.title, p.slug, p.date, m.file_path AS img, c.name AS cat FROM posts p LEFT JOIN media m ON m.id = p.featured_image_id
    LEFT JOIN categories c ON c.id = p.category_id WHERE p.status = 'published' ORDER BY p.date DESC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);
$phone = preg_replace('/[^\d+]/', '', (string)$inst['phone']);
$wa = preg_replace('/\D/', '', $phone); if (strlen($wa) === 10) $wa = '91' . $wa;
$hero = $courses[0] ?? null;
$range = $classes ? reset($classes) . ' – ' . end($classes) : '5th – 12th';
$seo = [
    'title' => $brand . ' — Coaching for Classes ' . $range . ' | Online Courses & PDF Notes',
    'description' => es('general', 'home_description') !== '' ? es('general', 'home_description') : $brand . ' — expert teachers, recorded video courses and PDF notes for school students. Learn at the institute or online on any phone.',
    'image' => SEO_DEFAULT_IMAGE, 'type' => 'website', 'robots' => SEO_DEFAULT_ROBOTS,
];
require_once INCLUDES_PATH . '/seo.php';
$seo = edu_seo_apply($seo, []);
$seo_ctx = ['kind' => 'home'];
$ico = fn($n) => sh_icon($n);
$short = function (string $n) { $p = preg_split('/\s+/', trim(preg_replace('/\(.*?\)/', '', $n))); return trim(($p[0] ?? '') . (isset($p[1]) ? ' ' . mb_substr($p[1], 0, 1) . '.' : '')); };
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
<script type="application/ld+json"><?= json_encode(array_filter(['@context' => 'https://schema.org', '@type' => 'EducationalOrganization', 'name' => $brand, 'url' => SITE_URL,
    'address' => $inst['address'] ?: null, 'telephone' => $inst['phone'] ?: null, 'email' => $inst['email'] ?: null]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
</head>
<body>
<?php require_once ROOT_PATH . '/components/header.php'; ?>
<main class="st hm">

<section class="hm-hero"><div class="sp-wrap hm-hero-in">
  <div class="hm-hero-t">
    <span class="hm-pill"><?= $ico('shield') ?> <?= htmlspecialchars($brand) ?> · Classes <?= htmlspecialchars($range) ?></span>
    <h1>Learn smarter.<br><span>Score higher.</span></h1>
    <p>Expert teachers, chapter-wise recorded classes and PDF notes — study at our institute or online on any phone.</p>
    <div class="hm-cta"><a class="hm-btn" href="/store/"><?= $ico('compass') ?> Explore courses</a><a class="hm-btn ghost" href="/store/notes"><?= $ico('notes') ?> Free &amp; premium notes</a></div>
    <ul class="hm-trust">
      <?php if ((int)$avg['n']): ?><li><b><?= number_format((float)$avg['a'], 1) ?> ★</b><span>Student rating</span></li><?php endif; ?>
      <?php if ($nStudents): ?><li><b><?= number_format($nStudents) ?>+</b><span>Students</span></li><?php endif; ?>
      <li><b><?= $nLessons ?: '—' ?></b><span>Video lessons</span></li>
    </ul>
  </div>
  <div class="hm-hero-v" aria-hidden="true">
    <div class="hm-orb"></div>
    <?php if ($hero): ?><a class="hm-float" href="<?= sh_e(sh_url($hero)) ?>" tabindex="-1"><?= sh_thumb_html($hero) ?><div class="hm-float-b"><b><?= sh_e($hero['title']) ?></b><span><?= (int)$hero['lessons'] ?> videos · <?= (float)$hero['price'] > 0 ? sh_money($hero['price']) : 'Free' ?></span></div></a><?php endif; ?>
    <div class="hm-chip c1"><?= $ico('play') ?> Speed up to 2x</div>
    <div class="hm-chip c2"><?= $ico('check') ?> Track your progress</div>
    <div class="hm-chip c3"><?= $ico('notes') ?> PDF notes included</div>
  </div>
</div></section>

<section class="hm-stats"><div class="sp-wrap"><div class="hm-stats-in">
  <div><?= $ico('user') ?><b><?= $nStudents ? number_format($nStudents) . '+' : 'New' ?></b><span>Students learning</span></div>
  <div><?= $ico('video') ?><b><?= $nCourses ?></b><span>Video courses</span></div>
  <div><?= $ico('notes') ?><b><?= $nNotes ?></b><span>Notes packs</span></div>
  <div><?= $ico('layers') ?><b><?= $classes ? count($classes) : 8 ?></b><span>Classes covered</span></div>
</div></div></section>

<?php if ($courses): ?>
<section class="hm-sec"><div class="sp-wrap">
  <div class="st-car" data-car style="margin:0">
    <div class="st-car-h hm-h"><div><span class="hm-k">Courses</span><h2>Popular courses</h2></div><a href="/store/">View all ›</a></div>
    <div class="st-car-track" tabindex="0" aria-label="Popular courses — swipe for more"><?php foreach ($courses as $p) echo '<div class="st-car-s">' . sh_card_html($p) . '</div>'; ?></div>
    <div class="st-car-dots"><?php foreach ($courses as $i => $_): ?><button type="button" aria-label="Slide <?= $i + 1 ?>"<?= $i === 0 ? ' class="is-active"' : '' ?>></button><?php endforeach; ?></div>
  </div>
</div></section>
<?php endif; ?>

<section class="hm-sec hm-why"><div class="sp-wrap">
  <div class="hm-h c"><span class="hm-k">Why <?= htmlspecialchars($brand) ?></span><h2>Everything you need to do well</h2></div>
  <div class="hm-why-g">
    <div><span><?= $ico('user') ?></span><b>Experienced teachers</b><p>Concepts explained simply, step by step, the way our classroom students learn.</p></div>
    <div><span><?= $ico('video') ?></span><b>Recorded classes</b><p>Watch any lesson again, at your speed — up to 2x — on any phone or computer.</p></div>
    <div><span><?= $ico('notes') ?></span><b>PDF notes</b><p>Chapter-wise notes and formula sheets for quick revision before tests.</p></div>
    <div><span><?= $ico('check') ?></span><b>Track progress</b><p>Mark lessons done and continue right where you stopped last time.</p></div>
    <div><span><?= $ico('shield') ?></span><b>Safe payments</b><p>Pay by UPI, card or net banking through Razorpay. Access opens at once.</p></div>
    <div><span><?= $ico('layers') ?></span><b>Institute + online</b><p>Our institute students use the same mobile number to study online too.</p></div>
  </div>
</div></section>

<?php if ($notes): ?>
<section class="hm-sec"><div class="sp-wrap">
  <div class="st-car-h hm-h"><div><span class="hm-k">PDF Notes</span><h2>Notes for quick revision</h2></div><a href="/store/notes">All notes ›</a></div>
  <div class="hm-notes"><?php foreach ($notes as $p): ?>
    <a class="hm-note" href="<?= sh_e(sh_url($p)) ?>"><span class="hm-note-i"><?= $ico('notes') ?></span><div><b><?= sh_e($p['title']) ?></b><small><?= (int)$p['files'] ?> PDF<?= (int)$p['files'] === 1 ? '' : 's' ?><?= $p['rating'] ? ' · ★ ' . number_format((float)$p['rating'], 1) : '' ?></small></div><em><?= (float)$p['price'] > 0 ? sh_money($p['price']) : 'Free' ?></em></a>
  <?php endforeach; ?></div>
</div></section>
<?php endif; ?>

<section class="hm-sec hm-steps"><div class="sp-wrap">
  <div class="hm-h c"><span class="hm-k">How it works</span><h2>Start learning in 3 steps</h2></div>
  <div class="hm-steps-g">
    <div><span>1</span><b>Create a free account</b><p>With your mobile number and email — takes a minute.</p></div>
    <div><span>2</span><b>Pick a course or notes</b><p>Watch the free preview first, then join with a safe payment.</p></div>
    <div><span>3</span><b>Learn anywhere</b><p>Open <b>My Courses</b> on any phone or computer and start.</p></div>
  </div>
</div></section>

<?php if ($rv): ?>
<section class="hm-sec hm-rv"><div class="sp-wrap">
  <div class="hm-h c"><span class="hm-k">Reviews</span><h2>What our students say</h2><?php if ((int)$avg['n']): ?><p class="hm-sub"><?= sh_stars((float)$avg['a']) ?> <b><?= number_format((float)$avg['a'], 1) ?></b> from <?= (int)$avg['n'] ?> rating<?= (int)$avg['n'] === 1 ? '' : 's' ?></p><?php endif; ?></div>
  <div class="sp-rvs"><div class="sp-rvs-track" tabindex="0" aria-label="Student reviews — swipe for more">
  <?php foreach ($rv as $i => $r): $nm = $short($r['name']) ?: 'Student'; ?>
    <article class="sp-rvc hm-rvc"><div class="sp-rvc-who"><span class="sp-av sp-av<?= $i % 3 ?>"><?= sh_e(mb_strtoupper(mb_substr($nm, 0, 1))) ?></span><div class="sp-rvc-nm"><b><?= sh_e($nm) ?></b><?= sh_stars((float)$r['rating'], 'sp-stars sm') ?></div></div>
      <p class="sp-rvc-q"><?= sh_e($r['body']) ?></p><small class="hm-rvc-c"><?= sh_e($r['title']) ?></small></article>
  <?php endforeach; ?>
  </div><?php if (count($rv) > 1): ?><div class="sp-rvs-dots"><?php foreach ($rv as $i => $_): ?><button type="button" aria-label="Review <?= $i + 1 ?>"<?= $i === 0 ? ' class="is-active"' : '' ?>></button><?php endforeach; ?></div><?php endif; ?></div>
</div></section>
<?php endif; ?>

<?php if ($posts): ?>
<section class="hm-sec"><div class="sp-wrap">
  <div class="st-car-h hm-h"><div><span class="hm-k">Latest Update</span><h2>News, jobs &amp; results</h2></div><a href="/latest-update">See all updates ›</a></div>
  <div class="hm-posts"><?php foreach ($posts as $po): ?>
    <a class="hm-post" href="<?= htmlspecialchars(postUrl($po['slug'], $po['id'])) ?>"><?php if ($po['img']): ?><img src="<?= htmlspecialchars(preg_match('#^https?://#', (string)$po['img']) ? (string)$po['img'] : '/' . ltrim((string)$po['img'], '/')) ?>" alt="" loading="lazy" onerror="this.remove()"><?php endif; ?>
      <div><?php if ($po['cat']): ?><span class="hm-k"><?= htmlspecialchars($po['cat']) ?></span><?php endif; ?><b><?= htmlspecialchars($po['title']) ?></b><small><?= date('d M Y', strtotime($po['date'])) ?></small></div></a>
  <?php endforeach; ?></div>
</div></section>
<?php endif; ?>

<section class="hm-visit"><div class="sp-wrap hm-visit-in">
  <div><span class="hm-k">Visit us</span><h2>Join <?= htmlspecialchars($brand) ?> — at the institute or online</h2>
    <?php if ($inst['address']): ?><p><?= htmlspecialchars($inst['address']) ?></p><?php endif; ?></div>
  <div class="hm-visit-b">
    <?php if ($phone): ?><a class="hm-btn light" href="tel:<?= htmlspecialchars($phone) ?>">Call <?= htmlspecialchars($inst['phone']) ?></a><?php endif; ?>
    <?php if (strlen($wa) >= 11): ?><a class="hm-btn wa" href="https://wa.me/<?= htmlspecialchars($wa) ?>" target="_blank" rel="noopener">WhatsApp us</a><?php endif; ?>
    <a class="hm-btn ghost" href="/account/register">Create free account</a>
  </div>
</div></section>
</main>
<?php require_once ROOT_PATH . '/components/footer.php'; ?>
<script src="/assets/js/store.js?v=<?= @filemtime(ROOT_PATH . '/assets/js/store.js') ?: 1 ?>" defer></script>
</body>
</html>
<?php $minified = minifyHTML(ob_get_clean()); if (empty($__czp)) { @file_put_contents($cf, $minified); } echo $minified; ?>
