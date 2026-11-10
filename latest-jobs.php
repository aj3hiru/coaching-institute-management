<?php // moved to /latest-posts (one Latest Posts page)
header('Location: /latest-posts' . (($p = (int)($_GET['page'] ?? 0)) > 1 ? '?page=' . $p : ''), true, 301); exit;
?><?php
// Latest Jobs: only posts with a job card (Post editor → Job card), newest first, 20 per page.
require_once __DIR__ . '/includes/redirects.php';
edu_redirect_check();
$page = max(1, (int)($_GET['page'] ?? 1));
$cf = "cache/latest-jobs-$page.html";
$__czp = isset($_GET['czp']);
$__cs = @json_decode((string)@file_get_contents(__DIR__ . '/cache/.settings.json'), true) ?: [];
if (!empty($__cs['off'])) $__czp = true; // cache system switched off in the Cache Manager
$__cdn = function () use ($__cs) { if (!empty($__cs['cdn']) && empty($_COOKIE['PHPSESSID'])) header('Cache-Control: public, max-age=' . max(0, (int)($__cs['max_age'] ?? 60)) . (!empty($__cs['swr']) ? ', stale-while-revalidate=300' : '')); };
if (!$__czp && file_exists($cf) && time() - filemtime($cf) < (int)($__cs['list'] ?? 3600)) { $__cdn(); readfile($cf); exit; }
ob_start();

require_once __DIR__ . '/includes/config.php';
require_once INCLUDES_PATH . '/minify.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/archive.php';
require_once INCLUDES_PATH . '/jobcard.php';

const EDU_JOBS_PER_PAGE = 20;
$jobWhere = "FROM posts p JOIN post_meta jm ON jm.post_id = p.id AND jm.meta_key = 'job_card' AND jm.meta_value = '1' WHERE p.status = 'published'";
$total = (int)$pdo->query("SELECT COUNT(*) $jobWhere")->fetchColumn();
$pages = max(1, (int)ceil($total / EDU_JOBS_PER_PAGE));
if ($page > $pages) { header('Location: /latest-jobs' . ($pages > 1 ? '?page=' . $pages : ''), true, 301); exit; }
$st = $pdo->prepare("SELECT p.id, p.title, p.slug, p.date $jobWhere ORDER BY p.date DESC LIMIT " . EDU_JOBS_PER_PAGE . " OFFSET " . (($page - 1) * EDU_JOBS_PER_PAGE));
$st->execute();
$jobs = $st->fetchAll(PDO::FETCH_ASSOC);
$pageUrl = fn(int $n) => '/latest-jobs' . ($n > 1 ? '?page=' . $n : '');

// SEO: every page is its own canonical; an empty list is not indexed
$seo = [
    'title'       => 'Latest Jobs 2026 – New Sarkari Naukri Vacancies' . ($page > 1 ? ' – Page ' . $page : '') . ' | ' . edu_site_name(),
    'description' => 'Latest government jobs 2026 with post name, department, salary, vacancies, qualification and last date. New Sarkari Naukri vacancies added daily on ' . edu_site_name() . '.',
    'image'       => SEO_DEFAULT_IMAGE,
    'type'        => SEO_DEFAULT_TYPE,
    'robots'      => $jobs ? SEO_DEFAULT_ROBOTS : 'noindex, follow',
];
require_once INCLUDES_PATH . '/seo.php';
require_once INCLUDES_PATH . '/ads.php';
$seo = edu_seo_apply($seo, []);
if (!$jobs) $seo['robots'] = 'noindex, follow';
$seo_ctx = ['kind' => 'other', 'crumb' => 'Latest Jobs'];
$seo_canonical = rtrim(SITE_URL, '/') . $pageUrl($page);

$faqs = [
    ['question' => 'Which jobs are listed on this page?', 'answer_html' => 'Only government job vacancies with full details — post name, department, location, salary, number of posts, qualification and the last date to apply. Results, admit cards and other updates are on <a href="/latest-updates">Latest Updates</a>.'],
    ['question' => 'How often is this list updated?', 'answer_html' => 'New vacancies are added every day as soon as an official notification is released. The newest jobs are always at the top.'],
    ['question' => 'How do I find jobs for my qualification?', 'answer_html' => 'Use the qualification buttons above the list (10th, 12th, ITI, Graduation…) to see only the jobs you are eligible for.'],
    ['question' => 'How do I apply for a job?', 'answer_html' => 'Open the job card to read the full notification, eligibility and important dates, then use the official apply online link given in the post before the last date.'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(edu_shortcodes((string)$seo['title'], true), ENT_QUOTES, 'UTF-8') ?></title>
    <?php require_once ROOT_PATH . '/components/head_script.php'; ?>
    <?php if ($page > 1): ?><link rel="prev" href="<?= htmlspecialchars(rtrim(SITE_URL, '/') . $pageUrl($page - 1)) ?>"><?php endif; ?>
    <?php if ($page < $pages): ?><link rel="next" href="<?= htmlspecialchars(rtrim(SITE_URL, '/') . $pageUrl($page + 1)) ?>"><?php endif; ?>
    <script type="application/ld+json"><?= json_encode([
        '@context' => 'https://schema.org',
        '@graph' => [
            ['@type' => 'BreadcrumbList', 'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => rtrim(SITE_URL, '/') . '/'],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'Latest Jobs', 'item' => rtrim(SITE_URL, '/') . '/latest-jobs'],
            ]],
            ['@type' => 'CollectionPage', 'name' => (string)$seo['title'], 'description' => (string)$seo['description'], 'url' => $seo_canonical,
                'mainEntity' => ['@type' => 'ItemList', 'numberOfItems' => count($jobs), 'itemListElement' => array_map(fn($j, $i) => [
                    '@type' => 'ListItem', 'position' => ($page - 1) * EDU_JOBS_PER_PAGE + $i + 1, 'name' => $j['title'], 'url' => rtrim(SITE_URL, '/') . postUrl($j['slug'], (int)$j['id']),
                ], $jobs, array_keys($jobs))]],
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_TAG) ?></script>
    <style>
    .lj-head{margin:6px 0 16px}
    .lj-head .page-title{margin:0 0 6px}
    .lj-lead{margin:0;color:#4b5563;font-size:15.5px;line-height:1.6;max-width:820px}
    .lj-count{display:inline-block;margin-top:10px;padding:5px 12px;border-radius:99px;background:#f3f4f6;font-size:13.5px;color:#374151}.lj-count b{color:#111827}
    .lj-empty{padding:40px 20px;border:1px dashed #d1d5db;border-radius:14px;text-align:center;color:#6b7280}
    .lj-empty a{color:var(--gc-accent,#0c6878);font-weight:600}
    </style>
</head>
<body>
<?php require_once ROOT_PATH . '/components/header.php'; ?>
<main>
    <nav class="breadcrumbs" aria-label="Breadcrumb">
        <ol>
            <li><a href="/">Home</a></li>
            <li aria-current="page">Latest Jobs</li>
        </ol>
    </nav>
    <div class="container">
        <div class="lj-head">
            <h1 class="page-title">Latest Jobs 2026 – New Sarkari Naukri Vacancies<?= $page > 1 ? ' (Page ' . $page . ')' : '' ?></h1>
            <p class="lj-lead">New government job vacancies with post name, department, salary, number of posts, qualification and last date — updated every day.</p>
            <?php if ($total): ?><span class="lj-count"><b><?= number_format($total) ?></b> job<?= $total === 1 ? '' : 's' ?></span><?php endif; ?>
        </div>
        <?= edu_ads('before_content') ?>
        <?php if (!$jobs): ?>
            <p class="lj-empty">New job vacancies will appear here soon. Meanwhile see <a href="/latest-updates">Latest Updates</a>.</p>
        <?php else: ?>
            <?= edu_job_cards($pdo, $jobs) ?>
            <?= edu_archive_pagination($page, $pages, $pageUrl) ?>
        <?php endif; ?>
    </div>
    <?php require_once ROOT_PATH . '/components/faq.php'; ?>
    <?= edu_ads('after_content') ?>
</main>
<?php require_once ROOT_PATH . '/components/footer.php'; ?>
</body>
</html>
<?php $minified = minifyHTML(ob_get_clean()); if (empty($__czp)) { file_put_contents($cf, $minified) or error_log("Failed to write cache: $cf"); } echo $minified; ?>
