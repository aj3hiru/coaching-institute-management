<?php // moved to /latest-posts (one Latest Posts page)
header('Location: /latest-posts' . (($p = (int)($_GET['page'] ?? 0)) > 1 ? '?page=' . $p : ''), true, 301); exit;
?><?php
require_once __DIR__ . '/includes/redirects.php';
edu_redirect_check();
require_once __DIR__ . '/includes/config.php';
require_once INCLUDES_PATH . '/minify.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/jobcard.php';

// The page lists every category at once (it used to be split into ?page=N): old page addresses go to the one list
if (isset($_GET['page'])) { header('Location: /categories', true, 301); exit; }
$page = 1;

// Every category with its number of published posts and its newest post (two light queries)
$all_cats = $pdo->query("
    SELECT c.id, c.name, c.slug, c.meta_description, COUNT(p.id) AS n, MAX(p.date) AS last_date
    FROM categories c
    LEFT JOIN posts p ON p.category_id = c.id AND p.status = 'published'
    GROUP BY c.id, c.name, c.slug, c.meta_description, c.views
    ORDER BY n DESC, c.views DESC, c.name ASC
")->fetchAll(PDO::FETCH_ASSOC);
$latest = [];
foreach ($pdo->query("
    SELECT p.id, p.title, p.slug, p.date, p.category_id
    FROM posts p
    JOIN (SELECT category_id, MAX(date) d FROM posts WHERE status = 'published' GROUP BY category_id) m ON m.category_id = p.category_id AND m.d = p.date
    WHERE p.status = 'published'
") as $r) { if (!isset($latest[$r['category_id']])) $latest[$r['category_id']] = $r; }
$withPosts = array_values(array_filter($all_cats, fn($c) => (int)$c['n'] > 0));
$empty = array_values(array_filter($all_cats, fn($c) => (int)$c['n'] === 0));
$totalPosts = array_sum(array_map(fn($c) => (int)$c['n'], $all_cats));

/** A small icon that fits the category name. */
function edu_cat_icon(string $name): string
{
    $n = strtolower($name);
    $p = [
        'rail' => 'M12 2c4 0 7 .5 7 4v9.5a3.5 3.5 0 0 1-3.5 3.5l1.5 1.5v.5H7v-.5L8.5 19A3.5 3.5 0 0 1 5 15.5V6c0-3.5 3-4 7-4zM7 7v4h10V7zm1.5 6.5a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3zm7 0a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3z',
        'bank' => 'M12 2 2 7v2h20V7zM4 11v7h3v-7zm6.5 0v7h3v-7zM17 11v7h3v-7zM2 20v2h20v-2z',
        'admit' => 'M4 4h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2zm4 4a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5zm-4 9h8c0-1.7-1.8-3-4-3s-4 1.3-4 3zm10-8v2h6V9zm0 4v2h4v-2z',
        'answer' => 'M7 3h10a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2zm1.3 9.3-1.4 1.4L9.6 16.4 15.1 11l-1.4-1.4-4.1 4.1z',
        'result' => 'M12 2 9.2 7.7 3 8.6l4.5 4.4-1.1 6.2L12 16.3l5.6 2.9-1.1-6.2L21 8.6l-6.2-.9z',
        'police' => 'M12 2 4 5v6c0 5 3.4 9.7 8 11 4.6-1.3 8-6 8-11V5zm0 5 1.2 2.6 2.8.3-2.1 1.9.6 2.8L12 13.2 9.5 14.6l.6-2.8L8 9.9l2.8-.3z',
        'defence' => 'M12 2 4 5v6c0 5 3.4 9.7 8 11 4.6-1.3 8-6 8-11V5z',
        'teach' => 'M12 3 1 9l11 6 9-4.9V17h2V9zM5 13.2V17c0 1.7 3.1 3 7 3s7-1.3 7-3v-3.8l-7 3.8z',
        'scholar' => 'M12 3 1 9l11 6 9-4.9V17h2V9zM5 13.2V17c0 1.7 3.1 3 7 3s7-1.3 7-3v-3.8l-7 3.8z',
        'pass' => 'M5 3h11l3 3v15H5zm3 6v2h8V9zm0 4v2h8v-2zm0 4v2h5v-2z',
        'medical' => 'M10 3h4v7h7v4h-7v7h-4v-7H3v-4h7z',
        'engineer' => 'M19.4 13a7.6 7.6 0 0 0 0-2l2.1-1.6-2-3.4-2.5 1a7.3 7.3 0 0 0-1.7-1L15 3.5h-4l-.4 2.6a7.3 7.3 0 0 0-1.7 1l-2.5-1-2 3.4L6.6 11a7.6 7.6 0 0 0 0 2l-2.1 1.6 2 3.4 2.5-1a7.3 7.3 0 0 0 1.7 1l.4 2.6h4l.4-2.6a7.3 7.3 0 0 0 1.7-1l2.5 1 2-3.4zM13 15a3 3 0 1 1 0-6 3 3 0 0 1 0 6z',
        'yojana' => 'M12 21s-7-4.4-9.3-9A5.5 5.5 0 0 1 12 6a5.5 5.5 0 0 1 9.3 6C19 16.6 12 21 12 21z',
        'job' => 'M10 3h4a2 2 0 0 1 2 2v1h4a2 2 0 0 1 2 2v4H2V8a2 2 0 0 1 2-2h4V5a2 2 0 0 1 2-2zm0 3h4V5h-4zM2 14h8v2h4v-2h8v5a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2z',
    ];
    $k = match (true) {
        str_contains($n, 'rail') => 'rail', str_contains($n, 'bank') => 'bank', str_contains($n, 'admit') => 'admit',
        str_contains($n, 'answer') => 'answer', str_contains($n, 'result') || str_contains($n, 'merit') => 'result',
        str_contains($n, 'police') => 'police', str_contains($n, 'defen') || str_contains($n, 'army') || str_contains($n, 'navy') || str_contains($n, 'air force') => 'defence',
        str_contains($n, 'teach') => 'teach', str_contains($n, 'scholar') => 'scholar', str_contains($n, 'pass') => 'pass',
        str_contains($n, 'medic') || str_contains($n, 'nurs') || str_contains($n, 'health') => 'medical', str_contains($n, 'engineer') => 'engineer',
        str_contains($n, 'yojana') || str_contains($n, 'scheme') => 'yojana', default => 'job',
    };
    return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="' . $p[$k] . '"/></svg>';
}


// SEO SECTION ARRAY

$seo = [
    'title'       => 'All Categories | ' . edu_site_name(),
    'description' => 'Browse all categories on ' . edu_site_name() . '. Find the latest updates, notifications, and alerts across all topics in one place.',
    'image'       => SEO_DEFAULT_IMAGE,
    'type'        => SEO_DEFAULT_TYPE,
    'robots'      => SEO_DEFAULT_ROBOTS,
];
require_once INCLUDES_PATH . '/seo.php';
require_once INCLUDES_PATH . '/ads.php';
$seo = edu_seo_apply($seo, []);
$seo_ctx = ['kind' => 'other'];


// FAQ SECTION ARRAY
$faqs = [

    [
        'question'    => 'What is the upcoming govt job vacancy 2026 list?',
        'answer_html' => 'The upcoming govt job vacancy 2026 list includes all India central and state government recruitment notifications across SSC, Railway, Banking, UPSC, Defence, Police, PSU and other departments. This page provides a complete and regularly updated vacancy list with direct apply online links.',
    ],

    [
        'question'    => 'Where can I check all India government job vacancy 2026?',
        'answer_html' => 'You can check the complete all India government job vacancy 2026 list on this page. It covers central government jobs, state recruitment notifications, qualification-wise vacancies, and latest job updates in one place.',
    ],

    [
        'question'    => 'How to apply for government job notification 2026?',
        'answer_html' => 'To apply for a government job notification 2026, select the desired vacancy, read the official notification carefully, verify eligibility criteria, and use the direct apply online link provided with each listing.',
    ],

];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(edu_shortcodes((string)$seo['title'], true), ENT_QUOTES, 'UTF-8') ?></title>
    
    <?php require_once ROOT_PATH . '/components/head_script.php'; ?>
    
    <!-- Breadcrumb + collection schema (JSON built with json_encode, always valid) -->
    <script type="application/ld+json"><?= json_encode([
        '@context' => 'https://schema.org',
        '@graph' => [
            ['@type' => 'BreadcrumbList', 'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => SITE_URL . '/'],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'Categories', 'item' => SITE_URL . '/categories'],
            ]],
            ['@type' => 'CollectionPage', 'name' => (string)$seo['title'], 'description' => (string)$seo['description'], 'url' => SITE_URL . '/categories',
                'mainEntity' => ['@type' => 'ItemList', 'numberOfItems' => count($withPosts), 'itemListElement' => array_map(fn($c, $i) => [
                    '@type' => 'ListItem', 'position' => $i + 1, 'name' => $c['name'], 'url' => SITE_URL . '/categories/' . $c['slug'],
                ], $withPosts, array_keys($withPosts))]],
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
    <style>
    .dx-hero{margin:6px 0 22px}
    .dx-hero .page-title{margin:0 0 8px}
    .dx-lead{margin:0 0 16px;color:#4b5563;font-size:15.5px;line-height:1.6;max-width:820px}
    .dx-stats{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 16px}
    .dx-stats span{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:99px;background:#f3f4f6;font-size:13.5px;color:#374151}
    .dx-stats b{color:#111827}
    .dx-search{position:relative;display:block;max-width:520px}
    .dx-search input{width:100%;height:48px;padding:0 16px 0 44px;border:1px solid #d1d5db;border-radius:12px;font:inherit;font-size:15px;background:#fff;color:#111827;box-sizing:border-box;transition:border-color .15s,box-shadow .15s}
    .dx-search input:focus{outline:none;border-color:var(--gc-accent,#0c6878);box-shadow:0 0 0 4px rgba(12,104,120,.12)}
    .dx-search svg{position:absolute;left:15px;top:50%;width:18px;height:18px;transform:translateY(-50%);fill:#9ca3af}
    .dx-h2{margin:26px 0 12px;font-size:20px;font-weight:800;color:#111827}
    .dx-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px}
    .dx-card{display:flex;gap:14px;align-items:flex-start;padding:16px;border:1px solid #e5e7eb;border-radius:14px;background:#fff;color:#111827 !important;transition:border-color .15s,box-shadow .15s,transform .15s}
    .dx-card:hover{border-color:var(--gc-accent,#0c6878);box-shadow:0 8px 22px rgba(16,24,40,.08);transform:translateY(-2px)}
    .dx-ico{flex-shrink:0;width:44px;height:44px;border-radius:12px;display:grid;place-items:center;background:rgba(12,104,120,.09);color:var(--gc-accent,#0c6878)}
    .dx-ico svg{width:22px;height:22px;fill:currentColor}
    .dx-body{min-width:0;flex:1}
    .dx-name{display:flex;align-items:center;justify-content:space-between;gap:8px;margin:0;font-size:16px;font-weight:700;line-height:1.3;color:#111827}
    .dx-n{flex-shrink:0;font-size:12px;font-weight:700;color:var(--gc-accent,#0c6878);background:rgba(12,104,120,.09);border-radius:99px;padding:2px 9px}
    .dx-latest{margin:6px 0 0;font-size:13.5px;line-height:1.45;color:#4b5563;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
    .dx-when{display:block;margin-top:4px;font-size:12px;color:#9ca3af}
    .dx-soon{display:flex;flex-wrap:wrap;gap:8px}
    .dx-soon a{padding:7px 13px;border:1px dashed #d1d5db;border-radius:99px;font-size:13.5px;color:#6b7280 !important;background:#fafafa}
    .dx-soon a:hover{border-color:var(--gc-accent,#0c6878);color:var(--gc-accent,#0c6878) !important}
    .dx-none{display:none;padding:16px;border:1px dashed #d1d5db;border-radius:12px;color:#6b7280;text-align:center}
    @media (max-width:600px){.dx-grid{grid-template-columns:1fr;gap:10px}.dx-card{padding:14px}.dx-lead{font-size:15px}}
    [data-theme="dark"] .dx-card{background:#151b23;border-color:#263040;color:#e5e7eb !important}[data-theme="dark"] .dx-name,[data-theme="dark"] .dx-h2{color:#e5e7eb}[data-theme="dark"] .dx-latest{color:#9ca3af}[data-theme="dark"] .dx-search input{background:#151b23;border-color:#263040;color:#e5e7eb}[data-theme="dark"] .dx-stats span{background:#1f2733;color:#d1d5db}[data-theme="dark"] .dx-stats b{color:#fff}
    </style>
</head>
<body>
	
    <?php require_once ROOT_PATH . '/components/header.php'; ?>
    
    <main>
    	<!-- Breadcrumbs -->
        <nav class="breadcrumbs" aria-label="Breadcrumb">
            <ol>
                <li><a href="/">Home</a></li>
                <li aria-current="page">Categories</li>
            </ol>
        </nav>
            
        <div class="container">
            <h1 class="page-title">Upcoming Govt Job Vacancy 2026 – All India Government Jobs Notification List</h1>
           
<div class="dx-hero">
  <p class="dx-lead">Browse every type of Sarkari Naukri in one place — railway, banking, SSC, UPSC, police, teaching, admit cards, answer keys, results and more. Pick a category to see its latest notifications.</p>
  <div class="dx-stats"><span><b><?= count($withPosts) ?></b> categories</span><span><b><?= number_format($totalPosts) ?></b> job updates</span></div>
  <label class="dx-search"><span class="screen-reader-text" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)">Find a category</span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 2a8 8 0 0 1 6.3 12.9l5.4 5.4-1.4 1.4-5.4-5.4A8 8 0 1 1 10 2zm0 2a6 6 0 1 0 0 12 6 6 0 0 0 0-12z"/></svg><input type="search" id="dx-q" placeholder="Search categories — e.g. Railway, Bank, Admit Card" autocomplete="off"></label>
</div>
<?= edu_ads('before_content') ?>
<?php if (!$all_cats): ?>
  <p class="no-posts-message">No categories found.</p>
<?php else: ?>
  <h2 class="dx-h2" id="dx-h-all">All job categories</h2>
  <div class="dx-grid" id="dx-list">
    <?php foreach ($withPosts as $c): $l = $latest[$c['id']] ?? null; ?>
    <a class="dx-card" href="/categories/<?= htmlspecialchars($c['slug']) ?>" data-name="<?= htmlspecialchars(mb_strtolower($c['name'])) ?>">
      <span class="dx-ico"><?= edu_cat_icon($c['name']) ?></span>
      <span class="dx-body">
        <span class="dx-name"><?= htmlspecialchars($c['name']) ?><span class="dx-n"><?= number_format((int)$c['n']) ?></span></span>
        <?php if ($l): ?><span class="dx-latest"><?= htmlspecialchars($l['title']) ?></span><span class="dx-when">Updated <?= date('j M Y', strtotime($l['date'])) ?></span><?php endif; ?>
      </span>
    </a>
    <?php endforeach; ?>
  </div>
  <?php if ($empty): ?>
  <h2 class="dx-h2" id="dx-h-soon">Coming soon</h2>
  <div class="dx-soon" id="dx-soon">
    <?php foreach ($empty as $c): ?><a href="/categories/<?= htmlspecialchars($c['slug']) ?>" data-name="<?= htmlspecialchars(mb_strtolower($c['name'])) ?>"><?= htmlspecialchars($c['name']) ?></a><?php endforeach; ?>
  </div>
  <?php endif; ?>
  <p class="dx-none" id="dx-none">No category matches your search.</p>
<?php $latestJobs = $pdo->query("SELECT p.id, p.title, p.slug, p.date FROM posts p JOIN post_meta jm ON jm.post_id = p.id AND jm.meta_key = 'job_card' AND jm.meta_value = '1' WHERE p.status = 'published' ORDER BY p.date DESC LIMIT 12")->fetchAll(PDO::FETCH_ASSOC); if ($latestJobs): ?>
<section class="jl-latest" aria-labelledby="jl-h">
  <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin:30px 0 10px"><h2 id="jl-h" style="margin:0;font-size:20px;font-weight:800">Latest jobs</h2><a href="/latest-updates" style="font-weight:600;font-size:14.5px;color:var(--gc-accent,#0c6878)">View all ›</a></div>
  <?= edu_job_cards($pdo, $latestJobs) ?>
</section>
<?php endif; ?>

  <script>
  (function () {
    var q = document.getElementById('dx-q'); if (!q) return;
    q.addEventListener('input', function () {
      var t = q.value.trim().toLowerCase(), shown = 0;
      [['dx-list', 'dx-h-all'], ['dx-soon', 'dx-h-soon']].forEach(function (g) {
        var box = document.getElementById(g[0]), h = document.getElementById(g[1]), n = 0; if (!box) return;
        box.querySelectorAll('[data-name]').forEach(function (el) { var on = !t || el.getAttribute('data-name').indexOf(t) >= 0; el.style.display = on ? '' : 'none'; if (on) n++; });
        box.style.display = n ? '' : 'none'; if (h) h.style.display = n ? '' : 'none'; shown += n;
      });
      document.getElementById('dx-none').style.display = shown ? 'none' : 'block';
    });
  })();
  </script>
<?php endif; ?>
        </div>
        
<?php require_once ROOT_PATH . '/components/faq.php'; ?>
 
    <?= edu_ads('after_content') ?>
</main>
    
    <?php require_once ROOT_PATH . '/components/footer.php'; ?>
</body>
</html>
<?php ob_end_flush(); ?>