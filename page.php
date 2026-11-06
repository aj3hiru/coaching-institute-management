<?php
// A page made in Admin → Pages, at /slug. Anything else that reaches here gets the site's own 404 page.
require_once __DIR__ . '/includes/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/settings.php';
require_once INCLUDES_PATH . '/pages.php';

// Redirections (Admin → Redirections)
require_once __DIR__ . '/includes/redirects.php';
edu_redirect_check(0, true);
$slug = strtolower(trim((string)($_GET['slug'] ?? ''), '/'));
// /feed and /rss (if the server didn't add the folder slash itself) → /feed/, as WordPress
if ($slug === 'feed' || $slug === 'rss') { require_once INCLUDES_PATH . '/feed.php'; edu_feed_redirect(); }
$page = null;
if (preg_match('/^[a-z0-9-]{1,160}$/', $slug)) {
    try {
        edu_pages_table($pdo);
        $st = $pdo->prepare("SELECT * FROM site_pages WHERE slug = ? LIMIT 1");
        $st->execute([$slug]);
        $page = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) { $page = null; }
}
// No page with this address: a post with a clean address (/slug) is shown instead
if (!$page && preg_match('/^[a-z0-9-]{1,160}$/', $slug) && edu_slug_only_from() !== PHP_INT_MAX) {
    try {
        $st = $pdo->prepare("SELECT id FROM posts WHERE slug = ? AND id >= ? ORDER BY id DESC LIMIT 1");
        $st->execute([$slug, edu_slug_only_from()]);
        if ($__pid = (int)$st->fetchColumn()) {
            $GLOBALS['__edu_post_id'] = $__pid;
            chdir(__DIR__);
            require __DIR__ . '/post.php';
            exit;
        }
    } catch (Throwable $e) { error_log('page.php post lookup: ' . $e->getMessage()); }
}
$isPreview = $page && $page['status'] !== 'published' && !empty($_SESSION['user_id']);
if (!$page || ($page['status'] !== 'published' && !$isPreview)) {
    http_response_code(404);
    include __DIR__ . '/templates/page_not_found.php';
    exit;
}

/** Shortcodes in a page (see includes/site.php): [contact_form], [site_title], [year] … */
function edu_page_shortcodes(string $html): string
{
    return edu_shortcodes_html($html);
}

require_once INCLUDES_PATH . '/seo.php';
$seo = [
    'title' => ($page['seo_title'] ? (function_exists('edu_seo_vars') ? edu_seo_vars((string)$page['seo_title'], ['title' => $page['title']]) : $page['seo_title']) : $page['title'] . ' | ' . edu_site_name()),
    'description' => $page['meta_description'] ?: mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($page['content']))), 0, 160),
    'image' => SEO_DEFAULT_IMAGE,
    'type' => 'website',
    'robots' => ($page['noindex'] || $isPreview) ? 'noindex, follow' : SEO_DEFAULT_ROBOTS,
];
require_once INCLUDES_PATH . '/seo.php';
$seo = edu_seo_apply($seo, ['page' => $page]);
$seo_ctx = ['kind' => 'page', 'crumb' => $page['title']];
$__graph = edu_seo_module('schema');
$seo_canonical = rtrim(SITE_URL, '/') . '/' . $page['slug'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars(edu_shortcodes((string)$seo['title'], true), ENT_QUOTES, 'UTF-8') ?></title>
<?php require_once ROOT_PATH . '/components/head_script.php'; ?>
<?php if (!$__graph): ?><script type="application/ld+json"><?= json_encode(['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => [
    ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => rtrim(SITE_URL, '/') . '/'],
    ['@type' => 'ListItem', 'position' => 2, 'name' => $page['title'], 'item' => $seo_canonical],
]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script><?php endif; ?>
<link rel="stylesheet" href="/assets/css/post.css?v=<?= @filemtime(ROOT_PATH . '/assets/css/post.css') ?>">
<style>.edu-page{max-width:<?= $page['template'] === 'full' ? '1200px' : '860px' ?>;margin:24px auto 40px;padding:0 16px}.edu-page .breadcrumbs{font-size:13px;color:#6b7280;margin:0 0 10px}.edu-page .breadcrumbs a{color:var(--gc-accent,#0c6878)}.edu-page h1{font-size:var(--edu-h1,32px);line-height:1.25;margin:0 0 18px;color:var(--t-content-title,#111827)}.edu-page .entry-content{font-size:var(--edu-font-size,17px);line-height:var(--edu-line-height,1.7);color:var(--t-content-text,#373737)}.edu-page .entry-content img{max-width:100%;height:auto;border-radius:8px}.edu-page .entry-content table{width:100%;border-collapse:collapse;margin:0 0 20px}.edu-page .entry-content td,.edu-page .entry-content th{border:1px solid #d1d5db;padding:8px}.edu-page .entry-content h2{margin:1.4em 0 .6em}.nb-tw{max-width:100%;overflow-x:auto;-webkit-overflow-scrolling:touch;margin:0 0 20px}.nb-tw>table{margin:0!important}@media(max-width:767px){.edu-page .entry-content td,.edu-page .entry-content th{min-width:80px}}.edu-cform{background:#fff;border:1px solid #e4e4e4;border-radius:8px;padding:20px;margin:10px 0 24px}.edu-cform .cf-row{display:grid;grid-template-columns:1fr 1fr;gap:14px}.edu-cform .cf-g{margin:0 0 14px}.edu-cform label{display:block;font-weight:600;font-size:15px;margin:0 0 6px;color:#1d1d1d}.edu-cform input,.edu-cform textarea{width:100%;box-sizing:border-box;padding:11px 12px;border:1px solid #d1d5db;border-radius:6px;font:inherit;font-size:16px}.edu-cform input:focus,.edu-cform textarea:focus{outline:0;border-color:var(--gc-accent,#0c6878);box-shadow:0 0 0 3px rgba(12,104,120,.12)}.edu-cform .cf-btn{background:var(--gc-accent,#0c6878);color:#fff;border:0;border-radius:6px;padding:12px 22px;font-weight:600;font-size:16px;cursor:pointer}.edu-cform .cf-btn:disabled{opacity:.7}.edu-cform .cf-resp{margin-top:12px;font-weight:600}.edu-cform .cf-resp.ok{color:#15803d}.edu-cform .cf-resp.err{color:#b91c1c}@media(max-width:600px){.edu-cform .cf-row{grid-template-columns:1fr;gap:0}}.edu-preview-bar{background:#fef3c7;color:#92400e;text-align:center;padding:8px;font-size:14px;font-weight:600}</style>
</head>
<body>
<?php require_once ROOT_PATH . '/components/header.php'; ?>
<?php if ($isPreview): ?><div class="edu-preview-bar">Draft preview — only signed-in staff can see this page.</div><?php endif; ?>
<main class="edu-page">
  <nav class="breadcrumbs" aria-label="Breadcrumb"><a href="/">Home</a> » <span><?= htmlspecialchars($page['title']) ?></span></nav>
  <article>
    <h1><?= htmlspecialchars($page['title']) ?></h1>
    <div class="entry-content"><?= edu_content_wrap_tables(edu_seo_filter_content(edu_page_shortcodes($page['content']), $page['title'])) ?></div>
  </article>
</main>
<?php require_once ROOT_PATH . '/components/footer.php'; ?>
</body>
</html>
