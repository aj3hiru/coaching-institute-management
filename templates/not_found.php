<?php
// The site's own "not found" page (pages and posts) — header, footer and colours of the theme, a search box,
// the latest posts and the popular categories, so a visitor who hit a dead link keeps reading.
// Set $nf_kind = 'post' before including for a missing post.
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once INCLUDES_PATH . '/redirects.php';
edu_redirect_404(); // Admin → Redirections: rules, 404 redirect, 404 monitor
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/settings.php';
if (!headers_sent()) { http_response_code(404); header('Cache-Control: no-store'); }
$nf_kind = $nf_kind ?? 'page';
$__nfdb = function_exists('edu_db') ? (edu_db() ?: ($pdo ?? null)) : ($pdo ?? null);
$nf_posts = $nf_cats = [];
try {
    if ($__nfdb) {
        $nf_posts = $__nfdb->query("SELECT p.id, p.title, p.slug, p.date, m.file_path img, c.name cat_name, c.slug cat_slug FROM posts p LEFT JOIN media m ON m.id = p.featured_image_id LEFT JOIN categories c ON c.id = p.category_id WHERE p.status = 'published' ORDER BY p.date DESC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);
        $nf_cats = $__nfdb->query("SELECT c.name, c.slug, COUNT(p.id) n FROM categories c JOIN posts p ON p.category_id = c.id AND p.status = 'published' GROUP BY c.id ORDER BY n DESC, c.name LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {}
$seo = ['title' => ($nf_kind === 'post' ? 'Post Not Found' : 'Page Not Found') . ' | ' . edu_site_name(), 'description' => 'The page you are looking for could not be found.', 'image' => defined('SEO_DEFAULT_IMAGE') ? SEO_DEFAULT_IMAGE : '', 'type' => 'website', 'robots' => 'noindex, follow'];
$seo_canonical = rtrim(SITE_URL, '/') . '/';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= htmlspecialchars(edu_shortcodes((string)$seo['title'], true), ENT_QUOTES, 'UTF-8') ?></title>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/components/head_script.php'; ?>
<style>
.nf-wrap{max-width:1100px;margin:0 auto;padding:36px 16px 56px}
.nf-hero{text-align:center;background:#fff;border:1px solid #e4e4e4;border-radius:10px;padding:44px 20px 40px;position:relative;overflow:hidden}
.nf-hero::before{content:"";position:absolute;inset:0 0 auto 0;height:5px;background:var(--gc-accent,#0c6878)}
.nf-wrap .nf-code{font-size:clamp(84px,16vw,150px)!important;line-height:1!important;font-weight:800;letter-spacing:-.04em;margin:0;color:var(--gc-accent,#0c6878);opacity:.95}
.nf-code span{display:inline-block;color:transparent;-webkit-text-stroke:3px var(--gc-accent,#0c6878)}
.nf-hero h1{font-size:clamp(22px,4vw,30px);margin:10px 0 8px;color:#1d1d1d;font-weight:700}
.nf-hero p{color:#5f5f5f;font-size:16px;line-height:1.6;max-width:560px;margin:0 auto 22px}
.nf-search{display:flex;max-width:520px;margin:0 auto 18px;border:2px solid var(--gc-accent,#0c6878);border-radius:8px;overflow:hidden;background:#fff}
.nf-search input{flex:1;min-width:0;border:0;padding:12px 14px;font-size:16px;outline:0;font-family:inherit}
.nf-search button{border:0;background:var(--gc-accent,#0c6878);color:#fff;padding:0 18px;font-weight:600;font-size:15px;cursor:pointer;font-family:inherit}
.nf-btns{display:flex;gap:10px;justify-content:center;flex-wrap:wrap}
.nf-btn{display:inline-flex;align-items:center;gap:8px;padding:10px 18px;border-radius:6px;font-weight:600;font-size:15px;text-decoration:none;border:1px solid var(--gc-accent,#0c6878);color:var(--gc-accent,#0c6878);background:#fff}
.nf-btn.solid{background:var(--gc-accent,#0c6878);color:#fff}
.nf-btn:hover{opacity:.9;text-decoration:none}
.nf-h{display:flex;align-items:center;gap:10px;margin:36px 0 16px;font-size:20px;font-weight:700;color:#1d1d1d}
.nf-h::after{content:"";flex:1;height:1px;background:#e4e4e4}
.nf-cats{display:flex;flex-wrap:wrap;gap:8px}
.nf-cats a{padding:7px 14px;border:1px solid #e4e4e4;border-radius:99px;background:#fff;color:#373737;font-size:14px;text-decoration:none;font-weight:500}
.nf-cats a:hover{border-color:var(--gc-accent,#0c6878);color:var(--gc-accent,#0c6878)}
.nf-cats a small{color:#9ca3af;margin-left:4px}
.nf-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px}
.nf-card{background:#fff;border:1px solid #e4e4e4;border-radius:8px;overflow:hidden;display:flex;flex-direction:column}
.nf-card img,.nf-card .ph{width:100%;height:auto;aspect-ratio:16/9;object-fit:cover;display:block;background:#eef6f7}
.nf-card .ph{display:grid;place-items:center;font-size:40px;font-weight:800;color:var(--gc-accent,#0c6878)}
.nf-card div{padding:12px 14px 14px}
.nf-card .cat{font-size:12px;font-weight:700;text-transform:uppercase;color:var(--gc-accent,#0c6878);letter-spacing:.02em}
.nf-card h3{font-size:16px;line-height:1.35;margin:6px 0 0;font-weight:600}
.nf-card h3 a{color:#1d1d1d;text-decoration:none}.nf-card h3 a:hover{color:var(--gc-accent,#0c6878)}
@media (max-width:900px){.nf-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:560px){.nf-grid{grid-template-columns:1fr}.nf-hero{padding:34px 16px 30px}}
</style>
</head>
<body>
<?php include_once $_SERVER['DOCUMENT_ROOT'] . '/components/header.php'; ?>
<main class="nf-wrap">
  <section class="nf-hero">
    <div class="nf-code" aria-hidden="true">4<span>0</span>4</div>
    <h1><?= $nf_kind === 'post' ? 'This post is not available' : 'Oops! Page not found' ?></h1>
    <p><?= $nf_kind === 'post' ? 'The post you opened may have been removed or its address changed. Search for it, or read the latest updates below.' : 'The page you are looking for may have been moved, renamed or never existed. Try a search, or pick something from below.' ?></p>
    <form class="nf-search" action="/search" method="get" role="search"><input type="search" name="q" placeholder="Search jobs, results, admit cards…" aria-label="Search"><button type="submit">Search</button></form>
    <div class="nf-btns"><a class="nf-btn solid" href="/">← Back to Home</a><a class="nf-btn" href="/latest-updates">Latest Updates</a><a class="nf-btn" href="/categories">All Categories</a></div>
  </section>
  <?php if ($nf_cats): ?><h2 class="nf-h">Popular categories</h2><div class="nf-cats"><?php foreach ($nf_cats as $c): ?><a href="/categories/<?= htmlspecialchars($c['slug']) ?>"><?= htmlspecialchars($c['name']) ?><small><?= (int)$c['n'] ?></small></a><?php endforeach; ?></div><?php endif; ?>
  <?php if ($nf_posts): ?><h2 class="nf-h">Latest posts</h2><div class="nf-grid"><?php foreach ($nf_posts as $p): $u = postUrl($p['slug'], (int)$p['id']); ?>
    <article class="nf-card"><a href="<?= htmlspecialchars($u) ?>" tabindex="-1" aria-hidden="true"><?php if ($p['img']): ?><img src="/<?= htmlspecialchars(ltrim($p['img'], '/')) ?>" alt="" loading="lazy" width="640" height="360"><?php else: ?><span class="ph"><?= htmlspecialchars(mb_strtoupper(mb_substr($p['title'], 0, 1))) ?></span><?php endif; ?></a>
      <div><?php if ($p['cat_name']): ?><span class="cat"><?= htmlspecialchars($p['cat_name']) ?></span><?php endif; ?><h3><a href="<?= htmlspecialchars($u) ?>"><?= htmlspecialchars($p['title']) ?></a></h3></div></article>
  <?php endforeach; ?></div><?php endif; ?>
</main>
<?php include_once $_SERVER['DOCUMENT_ROOT'] . '/components/footer.php'; ?>
</body>
</html>
