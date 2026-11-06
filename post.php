<?php
$id = (int)($GLOBALS['__edu_post_id'] ?? 0) ?: (filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0);
// A clean address ending in a number ("/ssc-cgl-2026") reaches here as if it were post 2026: the address map says which post it is
$__reqPath = trim((string)strtok($_SERVER['REQUEST_URI'] ?? '/', '?'), '/');
if (empty($GLOBALS['__edu_post_id']) && preg_match('/^[a-z0-9-]+-\d+$/', $__reqPath)) {
    $__map = @json_decode((string)@file_get_contents(__DIR__ . '/.edu-slugmap.json'), true);
    if (is_array($__map) && isset($__map[$__reqPath])) $id = (int)$__map[$__reqPath];
}

if ($id <= 0) {
    http_response_code(400);
    include 'templates/post_not_found.php';
    die();
}

// Cache check
$cf = "cache/post_$id.html";
// Redirections (Admin → Redirections) — checked before a cached copy is sent.
require_once __DIR__ . '/includes/redirects.php';
edu_redirect_check($id, true);
$__rd = (bool)array_filter(edu_redirect_rules()['rules'] ?? [], fn($r) => !empty($r['cc']) && !empty($r['active'])); // country rules: no shared CDN copy
$__czp = isset($_GET['czp']);
$__cs = @json_decode((string)@file_get_contents(__DIR__ . '/cache/.settings.json'), true) ?: [];
if (!empty($__cs['off'])) $__czp = true; // cache system switched off in the Cache Manager
$__cdn = function () use ($__cs, $__rd) { if (!empty($__cs['cdn']) && !$__rd && empty($_COOKIE['PHPSESSID'])) header('Cache-Control: public, max-age=' . max(0, (int)($__cs['max_age'] ?? 60)) . (!empty($__cs['swr']) ? ', stale-while-revalidate=300' : '')); };
// A clean-address post's saved copy is only served at its own address; its old /slug-ID form skips it and gets the 301 below
$__cu = @file_get_contents("cache/post_$id.url");
if (!$__czp && file_exists($cf) && time() - filemtime($cf) < (int)($__cs['post'] ?? 21600)
    && ($__cu === false || $__reqPath === '' || $__reqPath === 'post.php' || '/' . $__reqPath === $__cu)) {
    $__cdn();
    readfile($cf);
    exit;
}

ob_start();

require_once __DIR__ . '/includes/config.php';
require_once INCLUDES_PATH . '/minify.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/theme.php';
require_once INCLUDES_PATH . '/ads.php';

try {
    // Fetch post with author, category, media, meta
    $stmt = $pdo->prepare("
        SELECT p.*,
               a.name AS author_name, a.slug AS author_slug, a.bio AS author_bio,
               a.profile_image AS author_image,
               a.instagram AS author_instagram, a.threads AS author_threads,
               a.linkedin AS author_linkedin, a.facebook AS author_facebook,
               a.twitter AS author_twitter, a.designation AS author_designation,
               a.qualifications AS author_qualifications, a.languages_known AS author_languages,
               c.name AS cat_name, c.slug AS cat_slug,
               m.file_path AS banner_path, m.alt_text AS banner_alt,
               m.responsive_set AS banner_responsive,
               pm.meta_value AS meta_description
        FROM posts p
        JOIN authors a   ON p.author_id = a.id
        JOIN categories c ON p.category_id = c.id
        LEFT JOIN media m ON p.featured_image_id = m.id
        LEFT JOIN post_meta pm ON p.id = pm.post_id AND pm.meta_key = 'description'
        WHERE p.id = ?
        LIMIT 1
    ");
    $stmt->execute([$id]);
    $post = $stmt->fetch();

    if (!$post) {
        http_response_code(404);
        include 'templates/post_not_found.php';
        die();
    }
    // One address per post: a clean-address post opened at its old /slug-ID form goes to /slug (301)
    $__canon = postUrl($post['slug'], $post['id']);
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_GET['czp']) && $__reqPath !== '' && $__reqPath !== 'post.php' && '/' . $__reqPath !== $__canon
        && (int)$post['id'] >= edu_slug_only_from()) {
        $qs = (string)($_SERVER['QUERY_STRING'] ?? '');
        $qs = trim(preg_replace('/(^|&)id=\d+/', '', $qs), '&');
        header('Location: ' . $__canon . ($qs !== '' ? '?' . $qs : ''), true, 301);
        exit;
    }
    // Drafts and archived posts: a preview for signed-in staff only — never cached, never indexed
    $__isDraft = $post['status'] !== 'published';
    if ($__isDraft) {
        if (empty($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin', 'editor', 'author'], true)) {
            http_response_code(404);
            header('Cache-Control: no-store, private');
            include 'templates/post_not_found.php';
            die();
        }
        $__czp = true;
        header('Cache-Control: no-store, private');
        header('X-Robots-Tag: noindex, nofollow');
    }

    // Fetch comments
    [$commentTree, $commentCount] = getCommentTree($pdo, $id);

    // Fetch tags
    $stmt = $pdo->prepare("
        SELECT t.id, t.slug, t.name
        FROM tags t
        JOIN post_tag pt ON t.id = pt.tag_id
        WHERE pt.post_id = ?
    ");
    $stmt->execute([$id]);
    $post_tags = $stmt->fetchAll();

    // Extra fields from the editor (summary, key points, SEO box)
    $stmt = $pdo->prepare("SELECT meta_key, meta_value FROM post_meta WHERE post_id = ?");
    $stmt->execute([$id]);
    $pmeta = array_column($stmt->fetchAll(), 'meta_value', 'meta_key');
    $keyPoints = json_decode($pmeta['key_points'] ?? '[]', true) ?: [];

    // Fetch related posts
    $stmt = $pdo->prepare("
        SELECT p.id, p.title, p.slug, p.date,
               a.name AS author_name,
               m.file_path AS banner_path, m.alt_text AS banner_alt
        FROM posts p
        JOIN authors a ON p.author_id = a.id
        LEFT JOIN media m ON p.featured_image_id = m.id
        WHERE p.category_id = ? AND p.id != ? AND p.status = 'published'
        ORDER BY p.date DESC
        LIMIT 12
    ");
    $stmt->execute([$post['category_id'], $id]);
    $related_posts = $stmt->fetchAll();

    // Lists used by the sidebar, the Also Read boxes and the related grid (Customize decides which).
    $postList = function (string $order, int $limit) use ($pdo, $id) {
        $q = $pdo->prepare("SELECT p.id, p.title, p.slug, p.date, a.name AS author_name, m.file_path AS banner_path, m.alt_text AS banner_alt
            FROM posts p JOIN authors a ON p.author_id = a.id LEFT JOIN media m ON p.featured_image_id = m.id
            WHERE p.id != ? AND p.status = 'published' ORDER BY $order LIMIT " . (int)$limit);
        $q->execute([$id]);
        return $q->fetchAll();
    };
    $sbPosts  = th('sb_source') === 'latest' ? $postList('p.date DESC', (int)th('sb_count', 5)) : array_slice($related_posts, 0, (int)th('sb_count', 5));
    $relGrid  = array_slice($related_posts ?: $postList('p.date DESC', 9), 0, (int)th('p_related_count', 3));
    $schemaRelated = array_slice($related_posts, 0, 5);

    // Generate TOC
    $tocData = generateTOC($post['content']);
    $toc     = $tocData['toc'];
    $content = $tocData['content'];
    $content = edu_shortcodes_html($content); // [site_title], [year], [contact_form] … (Customize → Shortcodes)
    require_once INCLUDES_PATH . '/seo.php';
    $content = edu_content_wrap_tables(edu_seo_filter_content($content, $post['title'])); // Advanced SEO link/image rules + mobile-scrolling tables

    // SEO ARRAY
    $seo = [
        'title'       => $post['title'] . ' | ' . edu_site_name(),
        'description' => !empty($post['meta_description'])
                            ? $post['meta_description']
                            : mb_substr(strip_tags(html_entity_decode($post['content'], ENT_QUOTES | ENT_HTML5, 'UTF-8')), 0, 160),
        'image'       => !empty($post['banner_path'])
                            ? rtrim(SITE_URL, '/') . '/' . ltrim($post['banner_path'], '/')
                            : SEO_DEFAULT_IMAGE,
        'type'        => 'article',
        'robots'      => SEO_DEFAULT_ROBOTS,
    ];
require_once INCLUDES_PATH . '/seo.php';
$seo = edu_seo_apply($seo, ['post' => $post, 'custom_title' => !empty($pmeta['seo_title']), 'category' => $post['cat_name'] ?? '', 'focuskw' => $pmeta['focus_keyword'] ?? '',
    'excerpt' => edu_seo_excerpt((string)($pmeta['summary'] ?? '') ?: (string)$post['content'])]);
// Everything the Advanced SEO head (Open Graph, Twitter, JSON-LD @graph) needs about this post
$seo_ctx = [
    'kind' => 'post', 'post' => $post, 'focuskw' => $pmeta['focus_keyword'] ?? '', 'schema' => $pmeta['schema_type'] ?? '',
    'category' => ['name' => $post['cat_name'] ?? '', 'slug' => $post['cat_slug'] ?? ''], 'tags' => array_column($post_tags, 'name'),
    'image_alt' => $post['banner_alt'] ?: $post['title'], 'crumb' => $post['title'],
    'author' => ['name' => $post['author_name'], 'slug' => $post['author_slug'], 'bio' => $post['author_bio'], 'profile_image' => $post['author_image'], 'designation' => $post['author_designation'] ?? '',
        'qualifications' => $post['author_qualifications'] ?? '', 'languages_known' => $post['author_languages'] ?? '', 'facebook' => $post['author_facebook'], 'twitter' => $post['author_twitter'],
        'instagram' => $post['author_instagram'], 'linkedin' => $post['author_linkedin'], 'threads' => $post['author_threads']],
    'faq' => (!empty($post['faq_json']) && th_on('p_faqs')) ? (json_decode($post['faq_json'], true) ?: []) : [],
];
$__graph = edu_seo_module('schema'); // the combined @graph replaces this page's own article / breadcrumb / FAQ data
    // The post's own SEO box (title, social title/description, canonical, noindex) — part of the advanced SEO only.
    if (edu_seo_advanced()) {
        if (!empty($pmeta['noindex']))        $seo['robots'] = 'noindex, follow';
        // Rank Math variables (%title%, %sep%, %sitename% …) work in the post's own titles too
        $__sv = ['title' => $post['title'], 'category' => $post['cat_name'] ?? '', 'focuskw' => $pmeta['focus_keyword'] ?? '', 'excerpt' => edu_seo_excerpt((string)($pmeta['summary'] ?? '') ?: (string)$post['content'])];
        if (!empty($pmeta['seo_title']))      $seo['title'] = edu_seo_vars((string)$pmeta['seo_title'], $__sv);
        if (!empty($pmeta['og_title']))       $seo['og_title'] = edu_seo_vars((string)$pmeta['og_title'], $__sv);
        if (!empty($pmeta['og_description'])) $seo['og_description'] = edu_seo_vars((string)$pmeta['og_description'], $__sv);
        if (!empty($pmeta['canonical']))      $seo_canonical = $pmeta['canonical'];
    }
    if (!empty($__isDraft)) $seo['robots'] = 'noindex, nofollow'; // preview of a draft

} catch (PDOException $e) {
    error_log("DB Error post.php ID:$id — " . $e->getMessage());
    http_response_code(500);
    include 'templates/error.php';
    die();
}
?>
<!DOCTYPE html>
<?php $__lang = edu_text_lang($post['title'] . ' ' . mb_substr((string)$post['content'], 0, 4000)); $GLOBALS['edu_page_lang'] = $__lang; ?>
<html lang="<?= $__lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(edu_shortcodes((string)$seo['title'], true), ENT_QUOTES, 'UTF-8') ?></title>
    
 <meta name="author" content="<?= htmlspecialchars($post['author_name']) ?>">
<?php if (!edu_seo_advanced()): // Advanced SEO prints these in its own block ?>
<!-- Article-specific Open Graph Tags -->
<meta property="article:published_time" content="<?= htmlspecialchars(date('c', strtotime($post['date']))) ?>">
	
<?php if (!empty($post['updated_at'])): ?>
<meta property="article:modified_time" content="<?= htmlspecialchars(date('c', strtotime($post['updated_at']))) ?>">
<?php endif; ?>
	
<meta property="article:section" content="<?= htmlspecialchars($post['cat_name']) ?>">   
<?php endif; ?>
       
    <?php $__eaPostId = (int)$post['id']; require_once ROOT_PATH . '/components/head_script.php'; ?>
    
<style id="sp-css">.container{max-width:var(--container-lg);margin:0 auto;padding:0 var(--space-4)}.breadcrumbs{font-family:var(--font-ui);font-size:var(--text-sm);margin:var(--space-2) var(--space-4) 0}.breadcrumbs ol{display:flex;flex-wrap:wrap;align-items:center;list-style:none;padding:0;margin:0;color:var(--text-muted)}.breadcrumbs li{display:flex;align-items:center;margin-left:0}.breadcrumbs li:not(:last-child)::after{content:'/';margin:0 var(--space-1);color:var(--text-faint)}.breadcrumbs a{color:var(--text-link);transition:color var(--ease-fast)}.breadcrumbs a:hover{color:var(--text-link-hover);text-decoration:underline}.breadcrumbs li[aria-current="page"]{color:var(--text-secondary);font-weight:var(--weight-semibold)}.nb-avatar-fallback{display:inline-flex;align-items:center;justify-content:center;border-radius:50%;background:#e5e7eb;color:#9ca3af;overflow:hidden;flex-shrink:0}.nb-avatar-fallback svg{width:70%;height:70%;margin-top:18%}.nb-pill-ico{width:18px;height:18px;fill:currentColor}.single-post .post-layout.nb-no-sidebar{grid-template-columns:minmax(0,840px);justify-content:center}.post-sidebar.nb-sidebar-static{position:static}.single-post .post-main>*{max-width:100%}.single-post h1.entry-title{font-size:var(--pt-title-size,30px)}.single-post .content p,.single-post .content li{font-size:var(--pt-p-size,17px)}.nb-featured{margin:0 0 20px;width:815px;max-width:100%}.nb-featured .post-featured-img{width:100%;height:auto;aspect-ratio:16 / 9;object-fit:cover;border-radius:0;background:#f1f1f1}.nb-featured .post-featured-caption{font-size:13px;color:#6b7280;text-align:center;padding:6px 0 0}.nb-head-card{width:815px;max-width:100%;box-sizing:border-box;margin:0 0 25px;padding:22px 16px 26px;border:1px solid #e4e4e4;border-radius:5px;background:var(--t-content-background,#fff)}.nb-head-cat{display:inline-flex;align-items:center;gap:8px;color:var(--gc-accent,#0c6878);font-size:15px;font-weight:600;text-transform:uppercase;letter-spacing:.02em;margin-bottom:12px}.nb-head-cat svg{width:14px;height:14px}.nb-head-card h1.entry-title{margin:0 0 22px;font-size:var(--pt-title-size,30px);line-height:1.25;font-weight:600;color:var(--t-content-title,#111827)}.nb-head-meta{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap}.nb-head-author{display:flex;align-items:center;gap:12px}.nb-head-author img,.nb-head-author .nb-avatar-fallback{width:40px;height:40px;border-radius:50%;object-fit:cover}.nb-head-by{font-size:13px;font-weight:600;color:var(--t-content-meta-link,#222);display:flex;align-items:center;gap:6px}.nb-head-by a{color:inherit}.nb-head-date{display:block;font-size:12px;color:var(--t-content-meta-text,#222);margin-top:2px}.nb-pref-source--card .pill-text{font-size:11px;line-height:1.25;color:#3c4043}.nb-post--card .post-featured-img{border-radius:0}@media (max-width:767px){.nb-head-card{padding:16px 14px 18px}.nb-head-card h1.entry-title{font-size:24px}}.single-post details.nb-toc>summary{display:flex;align-items:center;justify-content:space-between;list-style:none;cursor:pointer;min-height:41px;margin:0;padding:8px 10px;border-radius:5px;background:var(--gc-accent,#0c6878);color:#fff;font-size:20px;line-height:24px;font-weight:600}.single-post details.nb-toc[open]>summary{margin-bottom:16px}.single-post details.nb-toc>summary::-webkit-details-marker{display:none}.single-post .nb-toc li.nb-toc-sub{margin-left:18px;font-size:15px;list-style:circle}.single-post .entry-content h2[id],.single-post .entry-content h3[id]{scroll-margin-top:80px}.single-post .pst-faq-cont details.w>summary{list-style:none;cursor:pointer}.single-post .pst-faq-cont details.w>summary::-webkit-details-marker{display:none}.single-post .pst-faq-cont details.w[open] .sp{transform:rotate(180deg)}.single-post .pst-faq-cont details.w .a{display:block;max-height:none;opacity:1}.nb-archive-layout .post-sidebar{position:sticky;top:80px}.nb-author-head img,.nb-author-head .nb-avatar-fallback{width:90px;height:90px;border-radius:50%;object-fit:cover;flex-shrink:0}@media (max-width:1024px){.nb-archive-layout .post-sidebar{position:static;width:100%}}.single-post .breadcrumbs,.single-post .breadcrumbs p{width:auto;max-width:815px;height:auto;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.single-post .breadcrumbs .last{display:inline;max-width:none}.nb-head-meta{flex-wrap:nowrap}.nb-head-author{min-width:0}.nb-head-actions{display:flex;align-items:center;gap:8px;flex-shrink:0}.nb-gn-box{display:inline-flex;align-items:center;justify-content:center;width:44px;height:44px;border:1px solid #e5e7eb;border-radius:6px;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.08)}.nb-gn-box svg{width:26px;height:26px}.nb-pref-source--card .pill-icon-google svg{width:20px;height:20px}@media (max-width:767px){.nb-head-meta{gap:8px}.nb-head-author{gap:8px}.nb-head-author img,.nb-head-author .nb-avatar-fallback{width:32px;height:32px}.nb-head-by{font-size:12px;gap:4px}.nb-head-date{font-size:11px}.nb-head-actions{gap:5px}.nb-pref-source--card .pill-text{font-size:9.5px;line-height:1.2}.nb-pref-source--card .pill-icon-google svg{width:16px;height:16px}.nb-gn-box{width:38px;height:38px}.nb-gn-box svg{width:22px;height:22px}}@media (max-width:360px){.nb-pref-source--card .pill-text{display:none}}@media (max-width:1260px){.single-post{width:100%;margin-left:0}}@media (max-width:1260px){.single-post .post-layout{grid-template-columns:minmax(0,1fr) 300px}.single-post .post-layout.nb-no-sidebar{grid-template-columns:minmax(0,1fr)}.single-post .post-sidebar,.single-post .trending-box{width:100%}.single-post .post-main>*,.single-post .post-main .toc,.single-post .post-main .breadcrumbs,.single-post .entry-content>*{max-width:100%}}@media (max-width:980px){.single-post .post-layout,.nb-archive-layout{grid-template-columns:minmax(0,1fr);row-gap:30px}.single-post .post-main{padding-right:0}.post-sidebar{position:static !important}}.nb-pref-source--card,.nb-gn-box,.nb-pref-source{box-shadow:none !important}.single-post .content .nb-also img,.entry-content .nb-also img{max-width:100% !important;width:100% !important;height:100% !important;margin:0 !important}.single-post #blog-comment-form .blog-comment-remember{height:auto;min-height:26px;margin:16px 0 22px;align-items:flex-start;gap:9px}.single-post #blog-comment-form .blog-comment-remember input{margin:5px 0 0;flex-shrink:0}.single-post #blog-comment-form .blog-comment-remember label{line-height:1.5}@media (max-width:767px){.single-post #blog-comment-form .blog-comment-remember label{font-size:15px}}.single-post .content .nb-keypoints h2.nb-keypoints-title,.entry-content .nb-keypoints h2.nb-keypoints-title{border:0;padding:0;background:none;text-align:left}.single-post{margin:var(--space-3) 0;width:calc(100% + 40px);margin-left:-20px;--color-primary:#2c6e79;--color-primary-hover:#235860;--color-primary-active:#1c464c;--color-primary-light:#dcecee;--color-primary-soft:#eef6f7;--bg-page:#ffffff;--bg-card:#ffffff;--bg-subtle:#f7f7f8;--border-light:#e5e7eb}.single-post .breadcrumbs{display:block;width:815px;box-sizing:border-box;height:18px;margin:0 0 8px;padding:0;font-family:inherit;font-size:12px;line-height:18px}.single-post .breadcrumbs p{display:block;width:100%;height:18px;box-sizing:border-box;margin:0;padding:0;color:#5f5f5f;font-size:12px;line-height:18px;font-weight:500}.single-post .breadcrumbs a{color:#2c8cfa;font-size:12px;line-height:18px;text-decoration:none}.single-post .breadcrumbs a:hover,.single-post .breadcrumbs a:focus-visible{color:#2c8cfa;text-decoration:underline}.single-post .breadcrumbs .separator,.single-post .breadcrumbs .last{color:#6b7280;font-size:12px;line-height:18px}.single-post .breadcrumbs .last{color:#5f5f5f;font-weight:500}.single-post .breadcrumbs .last{display:inline-block;max-width:calc(100% - 48px);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;vertical-align:bottom}.single-post h1{font-family:var(--font-heading);font-size:30px;font-weight:var(--weight-semibold);color:var(--text-primary);line-height:1.2;margin:0 0 20px 0}.post-action-pills{column-gap:6px;display:flex;flex-wrap:wrap;row-gap:6px}@media (max-width:767px){.post-action-pills{align-items:center;align-self:center;column-gap:8px;display:flex;justify-content:center;justify-items:center;justify-self:center;row-gap:6px}}.post-action-pill{align-items:center;column-gap:5px;display:flex;border:1px solid var(--border-light,#e5e7eb);border-radius:5px;padding:6px 8px;font-family:var(--font-ui);font-size:11px;font-weight:600;color:var(--text-primary,#1f2937);line-height:1.2;text-decoration:none}@media (max-width:767px){.post-action-pill{padding-left:6px;padding-right:6px}}.post-action-pill .pill-text{display:flex;flex-direction:column}.pill-icon-wa{background-color:#26bd41;display:inline-flex;border-radius:99px;padding:4px}.pill-icon-wa svg{color:#fff;fill:currentColor;height:20px;width:20px}@media (max-width:767px){.pill-icon-wa svg{height:18px;width:18px}}.pill-icon-news{display:inline-flex}.pill-icon-news svg{height:22px;width:22px}@media (max-width:767px){.pill-icon-news{padding:4px}.pill-icon-news svg{height:18px;width:18px}}.pill-icon-google{display:inline-flex}.pill-icon-google svg{height:22px;width:22px}@media (max-width:767px){.pill-icon-google svg{height:18px;width:18px}}.post-layout{display:grid;grid-template-columns:840px 360px;gap:0;align-items:start}.post-main{min-width:0;padding-right:25px;box-sizing:border-box}.post-sidebar{width:360px;position:sticky;top:calc(var(--header-height) + var(--space-4));display:flex;flex-direction:column;gap:var(--space-4)}.single-post .content{line-height:var(--leading-loose);color:var(--text-primary);margin-bottom:var(--space-6)}.single-post h2{font-size:26px;font-weight:var(--weight-semibold);line-height:1.2;margin-bottom:20px;color:var(--text-primary)}.single-post h3{font-size:22px;font-weight:var(--weight-semibold);line-height:1.2;margin-bottom:20px}.single-post .content img{display:block;width:auto;height:auto;max-width:100%;margin:12px auto;border-radius:var(--border-radius-sm)}.single-post ul,.single-post ol{margin:var(--space-2) 0 var(--space-2) var(--space-5);padding-left:var(--space-5)}.single-post li{margin:var(--space-1) 0}.single-post ol li[data-list="bullet"]{list-style-type:disc !important}.single-post a:hover{text-decoration:none}.single-post blockquote{margin:var(--space-4) 0;padding:var(--space-3) var(--space-4);border-left:3px solid var(--color-primary);background:var(--bg-subtle);font-style:italic;color:var(--text-secondary);border-radius:var(--border-radius-xs)}.single-post hr{margin:var(--space-3) 0;border:none;border-top:var(--border-width) solid var(--border-light)}.single-post .content table{border-collapse:collapse;width:100%;margin:var(--space-4) 0;font-size:var(--text-sm)}.single-post .content th,.single-post .content td{border:var(--border-width) solid var(--border-default);padding:var(--space-2) var(--space-3);text-align:left;vertical-align:top;word-break:break-word}.single-post .content th{background:var(--bg-subtle);font-weight:var(--weight-semibold);color:var(--text-primary)}.single-post .content td{background:var(--bg-card)}.single-post .ql-indent-1{margin-left:2rem}.single-post .ql-indent-2{margin-left:4rem}@media (max-width:767px){.single-post{width:auto;margin-left:0}.post-layout{grid-template-columns:1fr;row-gap:30px}.post-main{padding-right:0}.post-sidebar{width:auto;margin-top:0;padding-top:0}.post-sidebar{position:static}}.single-post,.single-post .post-main,.single-post .content,.single-post .content p,.single-post .content li,.single-post .content table,.single-post .content th,.single-post .content td{font-family:inherit}.single-post .content{font-size:17px;font-weight:500;line-height:25.5px;color:#373737}.single-post h1{margin-bottom:15px}.single-post .content p{margin:0 0 25.5px}.single-post .content h2{font-size:26px;line-height:31.2px;font-weight:600;color:var(--t-content-h2,#1d1d1d);margin:0 0 20px}.single-post .content h3{font-size:22px;line-height:26.4px;font-weight:600;color:var(--t-content-h3,#1d1d1d);margin:0 0 20px}.single-post .content ul,.single-post .content ol{margin:0 0 25.5px 51px;padding:0}.single-post .content li{margin:0}.single-post .content table{width:100%;margin:0 0 25.5px;border-collapse:collapse;table-layout:fixed;font-size:17px;line-height:25.5px;color:#373737}.single-post .content th,.single-post .content td{border:1px solid #373737;padding:8.5px;text-align:left;vertical-align:top;word-break:break-word}.single-post .content th{font-weight:700}.post-sidebar{position:sticky;top:150px;align-self:start;width:360px;min-width:0;gap:0}@media (max-width:767px){.post-sidebar,.trending-box,.trending-box-header,.trending-item{width:100%}.post-sidebar{position:static}.single-post .breadcrumbs{width:100%;height:18px;margin-bottom:8px}.single-post .breadcrumbs p{padding-left:0;padding-right:0}.single-post .breadcrumbs .last{max-width:calc(100% - 48px)}}.single-post .more-posts-header{color:#fff !important;font-size:20px !important;line-height:24px !important}.single-post .more-posts-header svg{color:#fff !important;fill:#fff !important}.single-post .more-posts-item-title{color:#373737 !important;font-size:16px !important;line-height:19.2px !important;font-weight:600 !important}.single-post .blog-comment-form-container{width:815px;box-sizing:border-box;margin:50px 0 0;padding:25px;border:1px solid #e4e4e4;border-radius:7px;background:#fff;color:#373737;font-family:inherit}.single-post .blog-comment-form-container>h2{display:block;position:relative;z-index:1;width:763px;height:37px;box-sizing:border-box;margin:-45px 0 40px;padding:4px 30px;background:#fff;color:#1d1d1d;font-family:inherit;font-size:22px;line-height:26.4px;font-weight:600}.single-post #blog-comment-form{width:763px;margin:0;padding:0;color:#373737;font-family:inherit}.single-post #blog-comment-form .blog-comment-form-group{width:763px;margin:0 0 10px;padding:0}.single-post #blog-comment-form .screen-reader-text{position:absolute;width:1px;height:1px;margin:-1px;padding:0;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}.single-post #blog-comment-content{display:inline-block;width:763px;height:226px;box-sizing:border-box;margin:0;padding:10px 15px;border:1px solid #e4e4e4;border-radius:0;outline:0;resize:vertical;background:#fff;color:#373737;font-family:inherit;font-size:17px;line-height:25.5px;font-weight:500}.single-post #blog-comment-name,.single-post #blog-comment-email,.single-post #blog-comment-website{display:block;width:324px;height:48px;box-sizing:border-box;margin:0 0 10px;padding:10px 15px;border:1px solid #e4e4e4;border-radius:0;outline:0;background:#fff;color:#373737;font-family:inherit;font-size:17px;line-height:25.5px;font-weight:500}.single-post #blog-comment-content:focus,.single-post #blog-comment-name:focus,.single-post #blog-comment-email:focus,.single-post #blog-comment-website:focus{border-color:#e4e4e4;box-shadow:none}.single-post #blog-comment-form .blog-comment-remember{display:flex;align-items:center;width:763px;height:26px;box-sizing:border-box;margin:0 0 25.5px;padding:0;gap:0}.single-post #blog-comment-remember{width:13px;height:13px;margin:0 8.5px 0 0;padding:0}.single-post #blog-comment-form .blog-comment-remember label{color:#373737;font-family:inherit;font-size:17px;line-height:25.5px;font-weight:500}.single-post .blog-comment-submit-btn{display:inline-block;height:46px;box-sizing:border-box;margin:0 0 10px;padding:10px 20px;border:1px solid transparent;border-radius:0;background:var(--gc-accent,#0c6878);color:#fff;font-family:inherit;font-size:16px;line-height:24px;font-weight:500;cursor:pointer;transform:none}.single-post .blog-comment-submit-btn:hover,.single-post .blog-comment-submit-btn:focus{background:var(--gc-accent,#0c6878);color:#fff;transform:none}@media (max-width:767px){.single-post .blog-comment-form-container,.single-post .blog-comment-form-container>h2,.single-post #blog-comment-form,.single-post #blog-comment-form .blog-comment-form-group,.single-post #blog-comment-content,.single-post #blog-comment-form .blog-comment-remember{width:100%}.single-post .blog-comment-form-container{padding:16px}.single-post .blog-comment-form-container>h2{height:auto;margin:0 0 20px;padding:0}.single-post #blog-comment-name,.single-post #blog-comment-email,.single-post #blog-comment-website{width:100%}}.single-post .blog-comments-section{width:815px;box-sizing:border-box;margin:25px 0 0;padding:25px;border:1px solid #e4e4e4;border-radius:7px;background:#fff;color:#373737;font-family:inherit}.single-post .blog-comments-section>h2{margin:0 0 20px;color:#1d1d1d;font-family:inherit;font-size:22px;line-height:26.4px;font-weight:600}.single-post .blog-comments-container{width:763px;margin:0}.single-post .blog-comment-item{width:763px;box-sizing:border-box;margin:0;padding:16px 0;border-bottom:1px solid #e4e4e4;color:#373737;font-family:inherit}.single-post .blog-comment-item:first-child{padding-top:0}.single-post .blog-comment-item:last-child{border-bottom:0}.single-post .blog-comment-reply{margin-left:25px;width:calc(100% - 25px);padding-left:15px;border-left:2px solid var(--gc-accent,#0c6878)}.single-post .blog-comment-header{display:flex;align-items:baseline;justify-content:space-between;gap:16px;margin:0 0 6px;font-family:inherit}.single-post .blog-comment-author{color:var(--gc-accent,#0c6878);font-size:16px;line-height:24px;font-weight:600}.single-post .blog-comment-date{flex:0 0 auto;color:#6b7280;font-size:12px;line-height:18px;font-weight:500}.single-post .blog-comment-content{margin:0 0 8px;color:#373737;font-family:inherit;font-size:16px;line-height:24px;font-weight:500;overflow-wrap:anywhere}.single-post .blog-comment-actions{margin:0}.single-post .blog-comment-reply-btn{display:inline-flex;align-items:center;min-height:30px;box-sizing:border-box;margin:0;padding:5px 12px;border:1px solid var(--gc-accent,#0c6878);border-radius:4px;background:#fff;color:var(--gc-accent,#0c6878);font-family:inherit;font-size:13px;line-height:18px;font-weight:600;text-decoration:none}.single-post .blog-comment-reply-btn:hover,.single-post .blog-comment-reply-btn:focus{border-color:var(--gc-accent,#0c6878);background:var(--gc-accent,#0c6878);color:#fff;text-decoration:none}.single-post .load-more-btn-cont{margin:25px 0 0;text-align:center}.single-post .load-more-cmt-btn,.single-post #load-more-comments{display:inline-flex;align-items:center;justify-content:center;min-height:46px;box-sizing:border-box;margin:0;padding:10px 20px;border:1px solid var(--gc-accent,#0c6878);border-radius:4px;background:var(--gc-accent,#0c6878);color:#fff;font-family:inherit;font-size:16px;line-height:24px;font-weight:500;cursor:pointer;text-decoration:none;transition:background .15s ease,border-color .15s ease,transform .15s ease}.single-post .load-more-cmt-btn:hover,.single-post .load-more-cmt-btn:focus,.single-post #load-more-comments:hover,.single-post #load-more-comments:focus{border-color:var(--color-primary-hover,#095363);background:var(--color-primary-hover,#095363);color:#fff;transform:translateY(-1px)}@media (max-width:767px){.single-post .blog-comments-section,.single-post .blog-comments-container,.single-post .blog-comment-item{width:100%}.single-post .blog-comments-section{padding:16px}.single-post .blog-comment-reply{width:calc(100% - 16px);margin-left:16px;padding-left:12px}.single-post .blog-comment-header{align-items:flex-start;flex-direction:column;gap:2px}}.single-post .content{font-weight:400 !important}.single-post .content p,.single-post .content li,.single-post .content td{font-weight:400 !important}.single-post .content strong,.single-post .content b{font-weight:700 !important}.single-post .content th,.single-post .content th strong,.single-post .content th b{font-weight:700 !important}.single-post .toc{width:815px;box-sizing:border-box;margin:25px 0;padding:20px;border:1px solid #e4e4e4;border-radius:7px;background:#fff;color:#373737;font-family:inherit}.single-post .toc h2{display:flex;align-items:center;justify-content:space-between;width:100%;min-height:41px;box-sizing:border-box;margin:0 0 16px;padding:8px 10px;border-radius:5px;background:var(--gc-accent,#0c6878);color:#fff;font-family:inherit;font-size:20px;line-height:24px;font-weight:600}.single-post .toc h2 button{all:unset;box-sizing:border-box;padding:4px 10px;border:1px solid rgba(255,255,255,.75);border-radius:4px;color:#fff;font-family:inherit;font-size:12px;line-height:18px;font-weight:500;cursor:pointer}.single-post .toc h2 button:hover{background:rgba(255,255,255,.14);color:#fff}.single-post .toc>ol,.single-post .toc ol{margin:0 !important;padding:0 0 0 28px !important;color:#373737}.single-post .toc li{margin:5px 0;padding-left:2px;color:#373737;font-size:16px;line-height:24px;font-weight:400 !important}.single-post .toc a{color:var(--gc-accent,#0c6878);font-size:16px;line-height:24px;font-weight:500;text-decoration:none}.single-post .toc a:hover{color:var(--color-primary-hover,#095363);text-decoration:underline}.single-post .pst-faq-cont{width:815px;max-width:none;box-sizing:border-box;margin:30px 0;padding:0;font-family:inherit}.single-post .pst-faq-h{display:flex;align-items:center;width:815px;min-height:41px;box-sizing:border-box;margin:0 0 20px;padding:8px 10px;border-radius:5px;background:var(--gc-accent,#0c6878);color:#fff;font-family:inherit;font-size:20px;line-height:24px;font-weight:600;text-align:left}.single-post .pst-faq-cont .w{margin:0 0 12px;border:1px solid #e4e4e4;border-radius:7px;background:#fff;overflow:hidden;box-shadow:none}.single-post .pst-faq-cont .q{display:flex;align-items:center;justify-content:space-between;width:100%;box-sizing:border-box;margin:0;padding:14px 16px;border:0;background:#fff;color:#373737;font-family:inherit;font-size:17px;line-height:25.5px;font-weight:600;text-align:left}.single-post .pst-faq-cont .q:hover,.single-post .pst-faq-cont .w.open .q{background:#f1fbfc;color:var(--gc-accent,#0c6878)}.single-post .pst-faq-cont .sp{width:20px;height:20px;min-width:20px;margin-left:16px;opacity:1;stroke:var(--gc-accent,#0c6878)}.single-post .pst-faq-cont .a{box-sizing:border-box;background:#f7fbfc;color:#373737;font-family:inherit;font-size:16px;line-height:24px;font-weight:400;padding:0 16px}.single-post .pst-faq-cont .a p{margin:0;padding:14px 0 16px;font-weight:400 !important}@media (max-width:767px){.single-post .toc,.single-post .pst-faq-cont,.single-post .pst-faq-h{width:100%}.single-post .toc{padding:16px}}.single-post .content .pst-faq-h{min-height:41px;height:41px;color:#fff !important;font-size:20px !important;line-height:24px !important;font-weight:600 !important;text-align:left !important}.single-post .trending-list{display:grid;gap:0}.single-post .trending-item{width:328px;min-height:78px;box-sizing:border-box;margin:0 0 10px;padding:0 0 10px;border-bottom:1px solid #e4e4e4}.single-post .trending-item:last-child{margin-bottom:0;padding-bottom:0;border-bottom:0}.single-post .trending-item + .trending-item{padding-top:10px}@media (max-width:767px){.single-post .trending-item{width:100%}}.single-post .post-join-wa{display:flex;align-items:center;justify-content:space-between;width:815px;height:56px;box-sizing:border-box;margin:0;padding:10px 20px;border:1px solid rgba(37,211,102,.1);border-radius:5px;background:rgb(231,249,255);animation:1s ease infinite WPLiteTheme-GreenBorderAnimation}.single-post .post-join-wa h2{display:flex;align-items:center;gap:8px;width:auto;height:19px;margin:0;padding:0;color:#000;font-family:inherit;font-size:16px;line-height:19.2px;font-weight:600}.single-post .post-join-wa h2 svg{display:inline;width:19px;height:19px;flex:0 0 19px;margin:0;color:rgb(44,182,65);fill:currentColor;stroke:none}.single-post .post-join-wa .post-join-btn{display:flex;align-items:center;justify-content:center;width:95px;height:34px;box-sizing:border-box;margin:0;padding:7px 20px;border:0;border-radius:5px;background:rgb(44,182,65);color:#fff;font-family:inherit;font-size:13px;line-height:19.5px;font-weight:500;text-decoration:none}.single-post .post-join-wa .post-join-btn:hover,.single-post .post-join-wa .post-join-btn:focus{background:rgb(44,182,65);color:#fff;text-decoration:none;transform:none}@media (max-width:767px){.single-post .post-join-wa{width:100%}}.nb-head-avatar{width:40px;height:40px;border-radius:50%;object-fit:cover;flex-shrink:0}.nb-head-read{color:#6b7280;font-size:13px}.post-banner-placeholder{aspect-ratio:16/9;display:grid;place-items:center;font-size:64px;font-weight:800;color:#fff;background:linear-gradient(135deg,var(--gc-accent,#0c6878),color-mix(in srgb,var(--gc-accent,#0c6878) 70%,#3bd9f0))}.nb-progress{position:fixed;top:0;left:0;right:0;height:3px;z-index:100003;pointer-events:none}.nb-progress span{display:block;height:100%;width:0;background:var(--gc-accent,#0c6878)}body:has(#wpadminbar) .nb-progress{top: var(--wpab-top,32px)}.nb-tw{max-width:100%;overflow-x:auto;-webkit-overflow-scrolling:touch;margin:0 0 25.5px}.single-post .content .nb-tw>table{margin:0}@media (max-width:767px){.single-post .content .nb-tw>table{table-layout:auto;font-size:15px;line-height:22px}.single-post .content .nb-tw th,.single-post .content .nb-tw td{word-break:normal;overflow-wrap:break-word;min-width:80px;padding:7px}}.screen-reader-text{position:absolute!important;width:1px;height:1px;overflow:hidden;clip:rect(1px,1px,1px,1px);white-space:nowrap}/* article spacing: headings get their own space above + tighter space below; phones get smaller headings and paragraph gaps */.single-post .content>h2{margin:32px 0 12px}.single-post .content>h3{margin:26px 0 10px}.single-post .content>h4{margin:22px 0 8px}.single-post .content>h2:first-child,.single-post .content>h3:first-child{margin-top:0}.single-post .content>h2+h3{margin-top:14px}@media (max-width:767px){.single-post .content>h2{font-size:22px;line-height:1.3;margin:26px 0 10px}.single-post .content>h3{font-size:19px;line-height:1.35;margin:22px 0 8px}.single-post .content>h4{font-size:17px;line-height:1.4;margin:18px 0 6px}.single-post .content p{margin:0 0 16px;line-height:1.7}.single-post .content li{line-height:1.65;margin-bottom:6px}.single-post .content ul,.single-post .content ol{margin:0 0 16px}}</style>
<link rel="stylesheet" href="/assets/css/post.css?v=<?= @filemtime(ROOT_PATH . '/assets/css/post.css') ?>" media="print" onload="this.media='all'"><noscript><link rel="stylesheet" href="/assets/css/post.css"></noscript>


<!-- Breadcrumb Schema -->
<?php if (!$__graph): ?>
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "BreadcrumbList",
  "itemListElement": [
    {
      "@type": "ListItem",
      "position": 1,
      "name": "Home",
      "item": "<?= htmlspecialchars(SITE_URL) ?>"
    },
    {
      "@type": "ListItem",
      "position": 2,
      "name": "Categories",
      "item": "<?= htmlspecialchars(SITE_URL) ?>/categories"
    },
    {
      "@type": "ListItem",
      "position": 3,
      "name": "<?= htmlspecialchars($post['cat_name']) ?>",
      "item": "<?= htmlspecialchars(SITE_URL . '/categories/' . $post['cat_slug']) ?>"
    },
    {
      "@type": "ListItem",
      "position": 4,
      "name": "<?= htmlspecialchars($post['title']) ?>",
      "item": "<?= SITE_URL . postUrl($post['slug'], $post['id']) ?>"
    }
  ]
}
</script>
<?php endif; ?>


<!-- Job posting (Google for Jobs) — only for posts with a job card -->
<?php require_once INCLUDES_PATH . '/jobcard.php'; echo edu_job_posting_jsonld($pdo, $post, $pmeta ?? []); ?>
<!-- Article + Comments Schema -->
<?php if (!$__graph): ?>
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "<?= htmlspecialchars(!edu_seo_advanced() ? 'BlogPosting' : (in_array($pmeta['schema_type'] ?? '', ['NewsArticle', 'BlogPosting', 'Article'], true) ? $pmeta['schema_type'] : (in_array(seo_opt('schema_default'), ['NewsArticle', 'BlogPosting', 'Article'], true) ? seo_opt('schema_default') : 'BlogPosting'))) ?>",
  "headline": "<?= htmlspecialchars($post['title']) ?>",
  "description": "<?= htmlspecialchars($seo['description'], ENT_QUOTES, 'UTF-8') ?>",
  "articleSection": "<?= htmlspecialchars($post['cat_name']) ?>",
  "image": {
    "@type": "ImageObject",
    "url": "<?= htmlspecialchars($seo['image'], ENT_QUOTES, 'UTF-8') ?>",
    "width": 1280,
    "height": 720
  },
  "author": {
    "@type": "Person",
    "name": "<?= htmlspecialchars($post['author_name']) ?>",
      "url": "<?= SITE_URL . '/author/' . htmlspecialchars($post['author_slug']) ?>"
  },
  "publisher": {
    "@type": "Organization",
    "name": "<?= edu_site_name() ?>",
    "logo": {
      "@type": "ImageObject",
      "url": "<?= SITE_LOGO ?>"
    }
  },
  "datePublished": "<?= htmlspecialchars(date('c', strtotime($post['date']))) ?>",
  <?php if (!empty($post['updated_at'])): ?>
"dateModified": "<?= htmlspecialchars(date('c', strtotime($post['updated_at']))) ?>",
<?php endif; ?>
  "mainEntityOfPage": {
    "@type": "WebPage",
    "@id": "<?= SITE_URL . postUrl($post['slug'], $post['id']) ?>"
  },
  "url": "<?= SITE_URL . postUrl($post['slug'], $post['id']) ?>",
  "wordCount": <?= str_word_count(strip_tags($post['content'])) ?>,
  "inLanguage": "<?= $__lang === 'hi' ? 'hi-IN' : 'en-IN' ?>",
  "isFamilyFriendly": true
  
    <?php if (!empty($commentTree)): ?>
 , "commentCount": <?= (int)$commentCount ?>,
  "comment": <?php
    $allComments = [];
    $iterator = function($comments) use(&$iterator, &$allComments) {
        foreach ($comments as $c) {
            $allComments[] = [
                "@type" => "Comment",
                "author" => [
                    "@type" => "Person",
                    "name" => htmlspecialchars($c['name'])
                ],
                "datePublished" => date('c', strtotime($c['date'])),
                "text" => htmlspecialchars($c['content'])
            ];
            if (!empty($c['children'])) $iterator($c['children']);
        }
    };
    $iterator($commentTree);
    echo json_encode($allComments, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
  ?>
  <?php endif; ?>
}
</script>
<?php endif; ?>

<!-- RELATED POSTS SCHEMA -->
<?php if (!empty($related_posts)): ?>
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "ItemList",
  "name": "More from <?= htmlspecialchars($post['cat_name']) ?>",
  "itemListElement": [
    <?php foreach ($schemaRelated as $i => $rpost): ?>
    {
      "@type": "ListItem",
      "position": <?= $i + 1 ?>,
      "url": "<?= SITE_URL . postUrl($rpost['slug'], $rpost['id']) ?>"
    }<?= $i + 1 < count($schemaRelated) ? ',' : '' ?>
    <?php endforeach; ?>
  ]
}
</script>
<?php endif; ?>
	
	
</head>
<body>
<?php if (!empty($__isDraft)): ?><div style="position:sticky;top:0;z-index:100006;padding:8px 14px;background:#fef3c7;color:#92400e;font:600 13.5px/1.4 system-ui,sans-serif;text-align:center;border-bottom:1px solid #fde68a">Preview — this post is a <?= htmlspecialchars($post['status']) ?> and is not visible to readers. <a href="/admin/post-manager.php?id=<?= (int)$id ?>" style="color:#7c2d12;text-decoration:underline">Edit post</a></div><?php endif; ?>
    <?php require_once ROOT_PATH . '/components/header.php'; ?>
    
<main>
<?php
  $postUrlAbs = SITE_URL . postUrl($post['slug'], $post['id']);
  $authorImg  = !empty($post['author_image']) ? '/' . htmlspecialchars($post['author_image']) : '/assets/img/user.png';
  $readMins   = max(1, (int)ceil(str_word_count(strip_tags($post['content'])) / 200));
  $verified   = '<svg class="nb-verified" viewBox="0 0 24 24" width="15" height="15" aria-hidden="true"><path fill="#1d9bf0" d="M22.5 12.5c0-1.58-.875-2.95-2.148-3.6.154-.435.238-.905.238-1.4 0-2.21-1.71-3.998-3.818-3.998-.47 0-.92.084-1.336.25C14.818 2.415 13.51 1.5 12 1.5s-2.816.917-3.437 2.25c-.415-.165-.866-.25-1.336-.25-2.11 0-3.818 1.79-3.818 4 0 .494.083.964.237 1.4-1.272.65-2.147 2.018-2.147 3.6 0 1.495.782 2.798 1.942 3.486-.02.17-.032.34-.032.514 0 2.21 1.708 4 3.818 4 .47 0 .92-.086 1.335-.25.62 1.334 1.926 2.25 3.437 2.25 1.512 0 2.818-.916 3.437-2.25.415.163.865.248 1.336.248 2.11 0 3.818-1.79 3.818-4 0-.174-.012-.344-.033-.513 1.158-.687 1.943-1.99 1.943-3.484zm-6.616-3.334l-4.334 6.5c-.145.217-.382.334-.625.334-.143 0-.288-.04-.416-.126l-.115-.094-2.415-2.415c-.293-.293-.293-.768 0-1.06s.768-.294 1.06 0l1.77 1.767 3.825-5.74c.23-.345.696-.436 1.04-.207.346.23.44.696.21 1.04z"/></svg>';

  // Paragraph positions in the article, for boxes placed "after paragraph N".
  $afterParagraph = function (string $html, int $n, string $box): string {
      if ($n <= 0) return $box . $html;
      $pos = -4;
      for ($i = 0; $i < $n; $i++) {
          $next = stripos($html, '</p>', $pos + 4);
          if ($next === false) return $html . $box;
          $pos = $next;
      }
      return substr_replace($html, '</p>' . $box, $pos, 4);
  };

  // "Also Read" boxes inside the article (Customize → Also Read): each box after its own paragraph,
  // filled one after another so no post shows in two boxes.
  if (th_on('ar_enabled')) {
      require_once INCLUDES_PATH . '/alsoread.php';
      $paras = substr_count(strtolower($content), '</p>');
      $arGroups = array_values(array_filter((array)th('ar_groups', []), fn($g) => (int)($g['after'] ?? 0) >= 1 && (int)$g['after'] <= $paras));
      usort($arGroups, fn($x, $y) => (int)$x['after'] <=> (int)$y['after']);
      $arUsed = [(int)$id];
      $arBoxes = [];
      foreach ($arGroups as $g) {
          $arPosts = edu_also_read_posts($pdo, $g, (int)$post['category_id'], $arUsed);
          foreach ($arPosts as $ap) $arUsed[] = (int)$ap['id'];
          $html = edu_also_read_html($arPosts, (string)($g['style'] ?? 'card'), (string)($g['label'] ?? 'Also Read'));
          if ($html !== '') $arBoxes[] = [(int)$g['after'], $html];
      }
      // from the last paragraph backwards, so earlier paragraph numbers stay right
      foreach (array_reverse($arBoxes) as [$after, $html]) $content = $afterParagraph($content, $after, $html);
      if ($arBoxes) $content .= edu_also_read_script();
  }

  if ($keyPoints && th_on('p_keypoints')) {
      $kpStyle = in_array(th('p_keypoints_style'), ['dot', 'check', 'number'], true) ? th('p_keypoints_style') : 'dot';
      $kpHtml = '<section class="nb-keypoints nb-keypoints--' . $kpStyle . '" aria-label="' . htmlspecialchars((string)th('p_keypoints_heading')) . '"><h2 class="nb-keypoints-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>' . htmlspecialchars((string)th('p_keypoints_heading')) . '</h2>' . ($kpStyle === 'number' ? '<ol>' : '<ul>');
      foreach ($keyPoints as $kp) $kpHtml .= '<li>' . htmlspecialchars($kp) . '</li>';
      $kpHtml .= ($kpStyle === 'number' ? '</ol>' : '</ul>') . '</section>';
      $content = $afterParagraph($content, (int)th('p_keypoints_after', 1), $kpHtml);
  }
  // Table of contents always right after the first paragraph (at the top when there is no paragraph)
  if ($toc && th_on('p_toc')) {
      $tocHtml = $toc . (th_on('p_toc_collapsed') ? '<script>addEventListener("DOMContentLoaded",function(){setTimeout(function(){var b=document.querySelector(".toc h2 button");if(b&&/hide/i.test(b.textContent))b.click()},50)});</script>' : '');
      $content = stripos($content, '</p>') !== false ? $afterParagraph($content, 1, $tocHtml) : $tocHtml . $content;
  }
  // GK / MCQ quiz: tap an option → right / wrong, score bar (includes/quiz.php)
  if (stripos($content, 'edu-quiz') !== false) { require_once INCLUDES_PATH . '/quiz.php'; $content .= edu_quiz_assets($__lang ?? 'en'); }

  $shownDate = th('p_date_shown') === 'published' ? $post['date'] : ($post['updated_at'] ?: $post['date']);

  $shareU = rawurlencode($postUrlAbs);
  $shareT = rawurlencode($post['title']);
  $shares = [
      ['facebook', 'Facebook', "https://www.facebook.com/sharer/sharer.php?u=$shareU", 'M9.101 23.691v-7.98H6.627v-3.667h2.474v-1.58c0-4.085 1.848-5.978 5.858-5.978.401 0 .955.042 1.468.103a8.68 8.68 0 0 1 1.141.195v3.325a8.623 8.623 0 0 0-.653-.036 26.805 26.805 0 0 0-.733-.009c-.707 0-1.259.096-1.675.309a1.686 1.686 0 0 0-.679.622c-.258.42-.374.995-.374 1.752v1.297h3.919l-.386 2.103-.287 1.564h-3.246v8.245C19.396 23.238 24 18.179 24 12.044c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.628 3.874 10.35 9.101 11.647Z'],
      ['x', 'X', "https://twitter.com/intent/tweet?url=$shareU&text=$shareT", 'M18.901 1.153h3.68l-8.04 9.19L24 22.846h-7.406l-5.8-7.584-6.638 7.584H.474l8.6-9.83L0 1.154h7.594l5.243 6.932ZM17.61 20.644h2.039L6.486 3.24H4.298Z'],
      ['whatsapp', 'WhatsApp', "https://api.whatsapp.com/send?text=$shareT%20$shareU", 'M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2.05 22l5.25-1.38c1.45.79 3.08 1.21 4.74 1.21 5.46 0 9.91-4.45 9.91-9.91C21.95 6.45 17.5 2 12.04 2Zm5.8 14.03c-.25.69-1.44 1.32-2 1.41-.51.08-1.16.11-1.87-.12-.43-.14-.98-.32-1.69-.62-2.98-1.29-4.93-4.29-5.08-4.49-.15-.2-1.21-1.61-1.21-3.07 0-1.46.77-2.18 1.04-2.48.27-.3.6-.37.79-.37h.57c.18.01.43-.07.67.51.25.6.84 2.06.92 2.21.07.15.12.32.02.52-.1.2-.15.32-.3.5-.15.17-.31.39-.45.52-.15.15-.3.31-.13.61.17.3.77 1.27 1.65 2.06 1.14 1.01 2.09 1.33 2.39 1.48.3.15.47.12.64-.07.17-.2.74-.87.94-1.16.2-.3.4-.25.67-.15.27.1 1.73.82 2.03.97.3.15.5.22.57.35.07.12.07.72-.17 1.41Z'],
      ['telegram', 'Telegram', "https://t.me/share/url?url=$shareU&text=$shareT", 'M11.944 0A12 12 0 1 0 24 12 12 12 0 0 0 11.944 0Zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635Z'],
      ['linkedin', 'LinkedIn', "https://www.linkedin.com/sharing/share-offsite/?url=$shareU", 'M20.45 20.45h-3.56v-5.57c0-1.33-.02-3.04-1.85-3.04-1.85 0-2.14 1.45-2.14 2.94v5.67H9.35V9h3.41v1.56h.05c.48-.9 1.64-1.85 3.37-1.85 3.6 0 4.27 2.37 4.27 5.46v6.28ZM5.34 7.43a2.06 2.06 0 1 1 0-4.13 2.06 2.06 0 0 1 0 4.13ZM7.12 20.45H3.56V9h3.56v11.45ZM22.22 0H1.77C.79 0 0 .77 0 1.73v20.54C0 23.23.79 24 1.77 24h20.45c.98 0 1.78-.77 1.78-1.73V1.73C24 .77 23.2 0 22.22 0Z'],
  ];
  $authorSoc = array_filter([
      'Instagram' => $post['author_instagram'], 'Threads' => $post['author_threads'], 'LinkedIn' => $post['author_linkedin'],
      'Facebook' => $post['author_facebook'], 'X' => $post['author_twitter'],
  ]);
?>
  <div class="container">
    <article class="single-post nb-post nb-post--card">
      <div class="post-layout<?= th_on('sb_post') ? '' : ' nb-no-sidebar' ?>">
        <div class="post-main">
          <?= edu_ads('before_post') ?>

          <?php if (th_on('p_breadcrumb')): ?>
          <nav class="breadcrumbs nb-card-bc" aria-label="Breadcrumb">
            <p><a href="/">Home</a><span class="separator"> » </span><a href="/categories">Categories</a><span class="separator"> » </span><a href="/categories/<?= htmlspecialchars($post['cat_slug']) ?>"><?= htmlspecialchars($post['cat_name']) ?></a><span class="separator"> » </span><span class="last"><?= htmlspecialchars($post['title']) ?></span></p>
          </nav>
          <?php endif; ?>

          <header class="nb-head-card">
            <?php if (th_on('p_cat_badge') || th_on('p_reading_time')): ?>
            <div class="nb-head-kicker">
              <?php if (th_on('p_cat_badge')): ?><a class="nb-head-cat" href="/categories/<?= htmlspecialchars($post['cat_slug']) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M13 2 4 14h7l-1 8 9-12h-7l1-8z"/></svg><?= htmlspecialchars($post['cat_name']) ?></a><?php endif; ?>
              <?php if (th_on('p_cat_badge') && th_on('p_reading_time')): ?><span class="nb-head-divider" aria-hidden="true">|</span><?php endif; ?>
              <?php if (th_on('p_reading_time')): ?><span class="nb-head-read"><?= $readMins ?> min read</span><?php endif; ?>
            </div>
            <?php endif; ?>
            <h1 class="entry-title"><?= htmlspecialchars($post['title']) ?></h1>
            <?php if (th_on('p_author_date') || th_on('p_pref_source') || th_on('p_gnews')): ?>
            <div class="nb-head-meta">
              <?php if (th_on('p_author_date')): ?>
              <div class="nb-head-author">
                <img src="<?= $authorImg ?>" alt="<?= htmlspecialchars($post['author_name']) ?>" width="40" height="40" class="nb-head-avatar" onerror="this.onerror=null;this.src='/assets/img/user.png'">
                <div>
                  <div class="nb-head-by">By <a href="/author/<?= htmlspecialchars($post['author_slug']) ?>"><?= htmlspecialchars($post['author_name']) ?></a> <?= $verified ?></div>
                  <time class="nb-head-date" datetime="<?= date('c', strtotime($shownDate)) ?>"><?= date(es('general', 'date_format', 'j F Y'), strtotime($shownDate)) ?></time>
                </div>
              </div>
              <?php endif; ?>
              <?php if (th_on('p_pref_source') || th_on('p_gnews')): ?>
              <div class="nb-head-actions">
                <?php if (th_on('p_pref_source')): ?><a class="post-action-pill nb-pref-source nb-pref-source--card" href="https://www.google.com/preferences/source?q=<?= rawurlencode(parse_url(SITE_URL, PHP_URL_HOST) ?: '') ?>" target="_blank" rel="noopener nofollow"><span class="pill-icon-google"><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/></svg></span><span class="pill-text"><span>Add as a preferred</span><span>source on Google</span></span></a><?php endif; ?>
                <?php if (th_on('p_gnews')): ?><a class="nb-gn-box" href="<?= htmlspecialchars((string)th('p_gnews_url', 'https://news.google.com/home')) ?>" target="_blank" rel="noopener nofollow" aria-label="Follow us on Google News" title="Follow us on Google News"><svg viewBox="0 0 48 48" aria-hidden="true"><path fill="#0c9d58" d="M10 7v24c0 1.1.9 2 2 2h24c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2H12c-1.1 0-2 .9-2 2z"/><path fill="#ea4335" d="M22.5 10.8 16.7 31.9c-.3 1 .3 2 1.3 2.3l20.9 5.8c1 .3 2-.3 2.3-1.3l5.8-21.1c.3-1-.3-2-1.3-2.3L24.8 9.5c-1-.3-2 .3-2.3 1.3z"/><path fill="#fbbc04" d="m1.1 18 7.5 20.5c.3 1 1.4 1.4 2.3 1.1l23.4-8.5c.9-.3 1.4-1.4 1.1-2.3L27.9 8.2c-.3-.9-1.4-1.4-2.3-1.1L2.2 15.6c-.9.4-1.4 1.4-1.1 2.4z"/><path fill="#4285f4" d="M6 17v24c0 1.1.9 2 2 2h32c1.1 0 2-.9 2-2V17c0-1.1-.9-2-2-2H8c-1.1 0-2 .9-2 2z"/><path fill="#fff" d="M25 25v-3h10c.6 0 1 .4 1 1v1c0 .6-.4 1-1 1H25zm0 5v-3h12c.6 0 1 .4 1 1v1c0 .6-.4 1-1 1H25zm0 5v-3h10c.6 0 1 .4 1 1v1c0 .6-.4 1-1 1H25zM10 28.5a6.5 6.5 0 0 1 11.1-4.6L19 26a3.5 3.5 0 1 0 1 3.5h-3v-1.5h6v.5a6.5 6.5 0 1 1-13 0z"/></svg></a><?php endif; ?>
              </div>
              <?php endif; ?>
            </div>
            <?php endif; ?>
          </header>

          <?php
          $sumHtml = !empty($pmeta['summary']) && th_on('p_summary')
              ? '<section class="nb-summary" aria-label="' . htmlspecialchars((string)th('p_summary_heading')) . '"><h2 class="nb-summary-title">' . htmlspecialchars((string)th('p_summary_heading')) . '</h2><div class="nb-summary-text">' . htmlspecialchars($pmeta['summary']) . '</div></section>'
              : '';
          $sumPos = in_array(th('p_summary_pos'), ['before_featured', 'after_featured', 'before_content'], true) ? th('p_summary_pos') : 'before_content';
          if ($sumPos === 'before_featured') echo $sumHtml;
          ?>
          <?= edu_ads('before_featured_image') ?>
          <?php if (th_on('p_featured')): ?>
          <figure class="nb-featured">
            <?php if (!empty($post['banner_path'])):
              $r = json_decode($post['banner_responsive'] ?? '[]', true);
              $sizes = ['sm'=>480,'md'=>768,'lg'=>1024,'xl'=>1280];
              $srcset = [];
              foreach ($sizes as $k => $w)
                if (!empty($r[$k])) $srcset[] = "/{$r[$k]} {$w}w";
            ?>
              <img src="/<?= htmlspecialchars($post['banner_path']) ?>"
                   alt="<?= htmlspecialchars($post['banner_alt'] ?? $post['title']) ?>"
                   class="post-featured-img post-banner-img"
                   width="1280" height="720"
                   fetchpriority="high" decoding="async"
                   <?= !empty($srcset) ? 'srcset="'.htmlspecialchars(implode(', ',$srcset)).'" sizes="(max-width:860px) 100vw, 815px"' : '' ?>>
            <?php else: ?>
              <div class="post-banner-placeholder"><?= htmlspecialchars(mb_strtoupper(mb_substr($post['title'], 0, 1))) ?></div>
            <?php endif; ?>
            <?php if (th_on('p_caption') && !empty($post['banner_alt'])): ?><figcaption class="post-featured-caption"><?= htmlspecialchars($post['banner_alt']) ?></figcaption><?php endif; ?>
          </figure>
          <?php endif; ?>
          <?= edu_ads('after_featured_image') ?>

          <?php if ($sumPos === 'after_featured') echo $sumHtml; ?>


          <?php if ($sumPos === 'before_content') echo $sumHtml; ?>
          <?= edu_ads('before_content') ?>

          <div class="content entry-content">

            <?= edu_ads_in_content($content) ?>

          </div>

          <?= edu_ads('after_content') ?>

          <?php
            $d = (!empty($post['faq_json']) && th_on('p_faqs')) ? json_decode($post['faq_json'], 1) : [];
            if ($d):
              $s = ["@context"=>"https://schema.org","@type"=>"FAQPage","mainEntity"=>[]];
          ?>
          <div class="pst-faq-cont">
            <h2 class="pst-faq-h">Frequently Asked Questions</h2>
            <?php foreach ($d as $i):
              $s['mainEntity'][] = ["@type"=>"Question","name"=>$i['q'],"acceptedAnswer"=>["@type"=>"Answer","text"=>$i['a']]];
            ?>
              <div class="w">
                <h3 class="q">
                  <?= htmlspecialchars($i['q']) ?>
                  <svg class="sp" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                </h3>
                <div class="a"><p><?= nl2br(htmlspecialchars($i['a'])) ?></p></div>
              </div>
            <?php endforeach; ?>
          </div>
          <?php if (!$__graph): ?><script type="application/ld+json"><?= json_encode($s) ?></script><?php endif; ?>
          <?php endif; ?>

          <?php if (!empty($post_tags) && th_on('p_tags')): ?>
          <div class="post-tags wplt-tag-badges">
            <?php foreach ($post_tags as $tag): ?>
              <a href="<?= tagUrl($tag['slug'], $tag['id']) ?>" class="tag-link wplt-tag-badge"><svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.59 13.41 13.42 20.58a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82Z"/><circle cx="7" cy="7" r="1.5"/></svg><?= htmlspecialchars($tag['name']) ?></a>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>

          <?php if (th_on('p_join') && (th('p_join_whatsapp') || th('p_join_telegram'))): ?>
          <div class="edu-join">
            <?php if (th('p_join_whatsapp')): ?><a class="edu-join-box edu-join--wa" href="<?= htmlspecialchars((string)th('p_join_whatsapp')) ?>" target="_blank" rel="noopener nofollow"><svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2.05 22l5.25-1.38c1.45.79 3.08 1.21 4.74 1.21 5.46 0 9.91-4.45 9.91-9.91C21.95 6.45 17.5 2 12.04 2Z"/></svg><span>Join our WhatsApp Channel</span><b>Join Now</b></a><?php endif; ?>
            <?php if (th('p_join_telegram')): ?><a class="edu-join-box edu-join--tg" href="<?= htmlspecialchars((string)th('p_join_telegram')) ?>" target="_blank" rel="noopener nofollow"><svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M11.944 0A12 12 0 1 0 24 12 12 12 0 0 0 11.944 0Zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635Z"/></svg><span>Join our Telegram Channel</span><b>Join Now</b></a><?php endif; ?>
          </div>
          <?php endif; ?>

          <?php if (th_on('p_share')): ?>
          <section class="nb-share" aria-label="Share">
            <span class="nb-share-label">Share</span>
            <div class="nb-share-grid">
              <button type="button" class="nb-share-btn nb-share--device" aria-label="Share" title="Share" data-share-device><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="m8.6 13.5 6.8 4M15.4 6.5l-6.8 4"/></svg></button>
              <?php foreach ($shares as [$k, $n, $u, $d2]): ?><a class="nb-share-btn nb-share--<?= $k ?>" href="<?= htmlspecialchars($u) ?>" target="_blank" rel="noopener nofollow" aria-label="<?= $n ?>" title="<?= $n ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="<?= $d2 ?>"/></svg></a><?php endforeach; ?>
              <button type="button" class="nb-share-btn nb-share--copy" aria-label="Copy link" title="Copy link" data-share-copy><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg></button>
            </div>
          </section>
          <?php endif; ?>

          <?php if (th_on('p_author_box')):
            require_once INCLUDES_PATH . '/social_icons.php';
            $abSoc = edu_author_socials($post, 'author_');
            $abUrl = '/author/' . rawurlencode((string)$post['author_slug']);
          ?>
          <section class="nb-ab" aria-labelledby="nb-ab-h">
            <h2 class="nb-ab-h" id="nb-ab-h">About the Author</h2>
            <div class="nb-ab-card">
              <div class="nb-ab-top">
                <a class="nb-ab-img" href="<?= $abUrl ?>" tabindex="-1" aria-hidden="true"><img src="<?= $authorImg ?>" alt="<?= htmlspecialchars($post['author_name']) ?>" width="110" height="110" loading="lazy" onerror="this.onerror=null;this.src='/assets/img/user.png'"></a>
                <div class="nb-ab-who">
                  <h3 class="nb-ab-name"><a href="<?= $abUrl ?>"><?= htmlspecialchars($post['author_name']) ?></a> <span class="nb-ap-tick" title="Verified author"><?= edu_social_svg('verified', 20) ?></span></h3>
                  <?php if (!empty($post['author_designation'])): ?><p class="nb-ab-role"><?= htmlspecialchars($post['author_designation']) ?></p><?php endif; ?>
                  <div class="nb-ab-row">
                    <?php if ($abSoc): ?><nav class="nb-ap-soc" aria-label="<?= htmlspecialchars($post['author_name']) ?> on social media"><?php foreach ($abSoc as $net => $u): ?><a href="<?= htmlspecialchars($u) ?>" target="_blank" rel="noopener nofollow" aria-label="<?= ucfirst($net === 'x' ? 'X (Twitter)' : $net) ?>"><?= edu_social_svg($net, 16) ?></a><?php endforeach; ?></nav><?php else: ?><span></span><?php endif; ?>
                    <a class="nb-ab-btn" href="<?= $abUrl ?>">View Profile</a>
                  </div>
                </div>
              </div>
              <?php if (!empty($post['author_bio'])): ?><p class="nb-ab-bio"><?= nl2br(htmlspecialchars($post['author_bio'])) ?></p><?php endif; ?>
            </div>
          </section>
          <?php endif; ?>

          <?php if (th_on('p_related') && $relGrid): ?>
          <section class="edu-related" aria-label="<?= htmlspecialchars((string)th('p_related_heading')) ?>">
            <h2 class="edu-related-h"><?= htmlspecialchars((string)th('p_related_heading')) ?></h2>
            <div class="nb-grid nb-grid--feat">
              <?php foreach ($relGrid as $rp): $ru = postUrl($rp['slug'], $rp['id']); ?>
              <article class="nb-card nb-card--feat">
                <a class="nb-card-img" href="<?= $ru ?>" tabindex="-1" aria-hidden="true"><?php if ($rp['banner_path']): ?><img src="/<?= htmlspecialchars($rp['banner_path']) ?>" alt="" width="640" height="360" loading="lazy" decoding="async"><?php endif; ?></a>
                <div class="nb-card-body"><h3 class="nb-card-title"><a href="<?= $ru ?>"><?= htmlspecialchars($rp['title']) ?></a></h3><div class="nb-card-meta"><time><?= date(es('general', 'date_format', 'j F Y'), strtotime($rp['date'])) ?></time></div></div>
              </article>
              <?php endforeach; ?>
            </div>
          </section>
          <?php endif; ?>

          <?php if (th_on('p_comments')): ?>
          <?= edu_ads('before_comments') ?>
          <!-- Comment Form -->
          <section class="blog-comment-form-container" id="comment-form">
            <h2>Leave a Comment</h2>
            <div id="blog-comment-alert-container" class="blog-comment-alert-container"></div>
            <div id="blog-comment-replying-to" class="blog-comment-replying-to">
              <span>Replying to <strong id="blog-comment-reply-to-name"></strong></span>
              <button type="button" class="blog-comment-cancel-reply">&#10005; Cancel</button>
            </div>
            <form id="blog-comment-form" data-post-id="<?= $id ?>">
              <input type="hidden" name="cTkn" value="" id="cTkn-inpt">
              <div class="blog-comment-form-group">
                <label class="screen-reader-text" for="blog-comment-content">Comment</label>
                <textarea id="blog-comment-content" name="content" required placeholder="Share your thoughts..."></textarea>
              </div>
              <div class="blog-comment-form-group">
                <label class="screen-reader-text" for="blog-comment-name">Name</label>
                <input type="text" id="blog-comment-name" name="name" value="" placeholder="Name *" required autocomplete="name">
              </div>
              <div class="blog-comment-form-group">
                <label class="screen-reader-text" for="blog-comment-email">Email</label>
                <input type="email" id="blog-comment-email" name="email" value="" placeholder="Email *" required autocomplete="email">
              </div>
              <div class="blog-comment-remember">
                <input type="checkbox" id="blog-comment-remember" name="remember" checked>
                <label for="blog-comment-remember">Save my name and email in this browser for the next time I comment.</label>
              </div>
              <input type="hidden" id="blog-comment-parent-id" name="parent_id" value="">
              <input type="hidden" name="post_id" value="<?= $id ?>">
              <input type="hidden" name="ajax" value="1">
              <button type="submit" class="blog-comment-submit-btn" id="blog-comment-submit-btn">
                Post Comment
                <span class="blog-comment-loading-spinner" id="blog-comment-loading-spinner"></span>
              </button>
            </form>
          </section>

          <!-- Comments Section -->
          <section class="blog-comments-section">
            <h2>Comments (<?= $commentCount ?>)</h2>
            <div id="blog-comments-container" class="blog-comments-container">
              <?php if (empty($commentTree)): ?>
              <p>No comments yet. Be the first to comment!</p>
              <?php else: ?>
              <?php renderComments($commentTree); ?>
              <?php endif; ?>
            </div>
            <?php if ($commentCount > 5): ?>
            <div class="load-more-btn-cont">
              <button id="load-more-comments" class="load-more-cmt-btn" data-offset="5">Show more &#65291;</button>
            </div>
            <?php endif; ?>
          </section>

          <?= edu_ads('after_comments') ?>
          <?php endif; ?>
          <?= edu_ads('after_post') ?>
        </div><!-- /post-main -->

        <?php if (th_on('sb_post')): ?>
        <aside class="post-sidebar">
          <div class="trending-box">
            <div class="trending-box-header"><svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="currentColor" d="M16 6l2.29 2.29-4.88 4.88-4-4L2 16.59 3.41 18l6-6 4 4 6.3-6.29L22 12V6z"/></svg><h2><?= htmlspecialchars(th_text('sb_heading', ['category' => $post['cat_name']])) ?></h2></div>
            <div class="trending-list">
              <?php if (empty($sbPosts)): ?>
                <p class="trending-empty">No related posts found.</p>
              <?php else: foreach ($sbPosts as $rpost): $ru = postUrl($rpost['slug'], $rpost['id']); ?>
              <div class="trending-item">
                <div class="trending-item-thumb"><a href="<?= $ru ?>" tabindex="-1" aria-hidden="true"><?php if ($rpost['banner_path']): ?><img src="/<?= htmlspecialchars($rpost['banner_path']) ?>" alt="" loading="lazy" decoding="async" width="200" height="120"><?php endif; ?></a></div>
                <div class="trending-item-info">
                  <p class="trending-item-title"><a href="<?= $ru ?>"><?= htmlspecialchars($rpost['title']) ?></a></p>
                  <div class="trending-item-date"><svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg><?= date('j F Y', strtotime($rpost['date'])) ?></div>
                </div>
              </div>
              <?php endforeach; endif; ?>
            </div>
          </div>
        </aside>
        <?php endif; ?>

      </div><!-- /post-layout -->
    </article>
  </div>
</main>
<style>.edu-join{display:grid;gap:10px;margin:22px 0;max-width:815px}.edu-join-box{display:flex;align-items:center;gap:10px;padding:12px 16px;border-radius:6px;color:#111!important;font-weight:600}.edu-join-box svg{width:22px;height:22px;flex-shrink:0}.edu-join-box span{flex:1}.edu-join-box b{padding:7px 16px;border-radius:5px;color:#fff;font-size:13px}.edu-join--wa{background:#e7f9ee;border:1px solid #bdecc9}.edu-join--wa svg{color:#25d366}.edu-join--wa b{background:#25d366}.edu-join--tg{background:#e8f4fd;border:1px solid #bfe0f7}.edu-join--tg svg{color:#229ed9}.edu-join--tg b{background:#229ed9}.edu-related{max-width:815px;margin:30px 0}.edu-related-h{font-size:22px;margin:0 0 16px}.edu-related .nb-grid--feat{grid-template-columns:repeat(3,minmax(0,1fr))}@media (max-width:700px){.edu-related .nb-grid--feat{grid-template-columns:1fr}}.single-post .post-layout.nb-no-sidebar{grid-template-columns:minmax(0,840px);justify-content:center}</style>
<div class="nb-progress" aria-hidden="true"><span id="nbProgress"></span></div>
<?php if (th_on('rp_button')) { require_once INCLUDES_PATH . '/alsoread.php'; $GLOBALS['__edu_rp_pill'] = true; echo edu_reading_progress_html((int)($readMins ?? 1)); } ?>
<script>(function(){
var b=document.querySelector("[data-share-device]"),c=document.querySelector("[data-share-copy]"),u=location.href;
function toast(t){var e=document.createElement("div");e.className="nb-push-toast";e.textContent=t;document.body.appendChild(e);setTimeout(function(){e.remove()},1800)}
function copy(){(navigator.clipboard?navigator.clipboard.writeText(u):Promise.reject()).then(function(){toast("Link copied")},function(){prompt("Copy this link:",u)})}
b&&(navigator.share?b.addEventListener("click",function(){navigator.share({title:document.title,url:u}).catch(function(){})}):b.remove());
c&&c.addEventListener("click",copy);
var p=document.getElementById("nbProgress"),m=document.querySelector(".post-main");
if(p&&m){var f=function(){var r=m.getBoundingClientRect(),h=r.height-innerHeight;p.style.width=(h>0?Math.min(100,Math.max(0,-r.top/h*100)):100)+"%"};addEventListener("scroll",f,{passive:true});f()}
})();</script>

    
    <?php require_once ROOT_PATH . '/components/footer.php'; ?>

<script id="post" src="/assets/js/post.min.js?v=<?= @filemtime(ROOT_PATH . "/assets/js/post.min.js") ?>" defer></script>
</body>
</html>
<?php $minified = minifyHTML(ob_get_clean()); if (empty($__czp)) { if ((int)$post['id'] >= edu_slug_only_from()) @file_put_contents("cache/post_$id.url", $__canon); else @unlink("cache/post_$id.url"); file_put_contents($cf, $minified) or error_log("Failed to write cache: $cf"); } echo $minified; ?>