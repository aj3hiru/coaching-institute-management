<?php
// The site's own "not found" page (pages and posts), in the site's new design: a split hero (message, search, quick links,
// picture), the latest courses, popular categories and the latest posts — so a visitor who hit a dead link keeps going.
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
// online store: the newest courses (homepage cards) and the quick links to the store pages
$nf_store = is_file(ROOT_PATH . '/account/login.php') && is_file(INCLUDES_PATH . '/shop.php');
$nf_courses = []; $nf_ccat = [];
if ($nf_store && $__nfdb) { try { require_once INCLUDES_PATH . '/shop.php'; $nf_courses = sh_latest($__nfdb, 'course', 4); foreach ($__nfdb->query('SELECT id, name FROM sh_categories') as $r) $nf_ccat[(int)$r['id']] = $r['name']; } catch (Throwable $e) { $nf_courses = []; } }
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
<?php if ($nf_courses): ?><link rel="stylesheet" href="/assets/css/store.css?v=<?= @filemtime(ROOT_PATH . '/assets/css/store.css') ?: 1 ?>"><link rel="stylesheet" href="/assets/css/home.css?v=<?= @filemtime(ROOT_PATH . '/assets/css/home.css') ?: 1 ?>"><?php endif; ?>
<style>
.nf{--nf-a:var(--gc-accent,#0c6878);--nf-line:#e5e7eb;max-width:calc(var(--nb-container,1200px) - 20px);margin:0 auto;padding:32px 20px 56px;box-sizing:border-box}
.nf-hero{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(0,.85fr);gap:32px;align-items:center;padding:36px 40px;border:1px solid var(--nf-line);border-radius:5px;background:#fff}
.nf-tag{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:5px;background:color-mix(in srgb,var(--nf-a) 10%,#fff);color:var(--nf-a);font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase}
.nf-hero h1{margin:14px 0 10px;font-size:clamp(26px,3.6vw,38px);line-height:1.15;font-weight:800;color:#111827}
.nf-hero p{margin:0 0 20px;max-width:520px;color:#4b5563;font-size:16px;line-height:1.6}
.nf-search{display:flex;max-width:520px;height:48px;border:1px solid #d1d5db;border-radius:5px;overflow:hidden;background:#fff}
.nf-search:focus-within{border-color:var(--nf-a);box-shadow:0 0 0 1px var(--nf-a)}
.nf-search input{flex:1;min-width:0;border:0;padding:0 14px;font:inherit;font-size:15px;outline:0;background:transparent}
.nf-search button{display:flex;align-items:center;gap:6px;border:0;padding:0 18px;background:var(--nf-a);color:#fff;font:inherit;font-weight:600;font-size:15px;cursor:pointer}
.nf-search button svg{width:17px;height:17px}
.nf-quick{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px;margin-top:20px;max-width:560px}
.nf-q{display:flex;align-items:center;gap:10px;padding:10px 12px;border:1px solid var(--nf-line);border-radius:5px;background:#fff;color:#111827!important;font-size:14px;font-weight:600;text-decoration:none!important;transition:border-color .15s,color .15s}
.nf-q:hover{border-color:var(--nf-a);color:var(--nf-a)!important}
.nf-q i{width:32px;height:32px;flex:0 0 32px;display:grid;place-items:center;border-radius:5px;background:color-mix(in srgb,var(--nf-a) 10%,#fff);color:var(--nf-a)}
.nf-q i svg{width:17px;height:17px}
.nf-art{display:block;width:100%;max-width:400px;height:auto;margin:0 auto}
.nf-h{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:40px 0 16px}
.nf-h h2{margin:0;font-size:22px;font-weight:700;color:#111827}
.nf-h a{color:var(--nf-a);font-size:14px;font-weight:600;text-decoration:none;white-space:nowrap}
.nf-cats{display:flex;flex-wrap:wrap;gap:8px}
.nf-cats a{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border:1px solid var(--nf-line);border-radius:5px;background:#fff;color:#374151;font-size:14px;font-weight:500;text-decoration:none}
.nf-cats a:hover{border-color:var(--nf-a);color:var(--nf-a)}
.nf-cats small{color:#9ca3af}
.nf-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px}
.nf-card{display:flex;flex-direction:column;border:1px solid var(--nf-line);border-radius:5px;overflow:hidden;background:#fff}
.nf-card img,.nf-card .ph{display:block;width:100%;aspect-ratio:16/9;height:auto;object-fit:cover;background:color-mix(in srgb,var(--nf-a) 8%,#fff)}
.nf-card .ph{display:grid;place-items:center;font-size:40px;font-weight:800;color:var(--nf-a)}
.nf-card div{padding:12px 14px 14px}
.nf-card .cat{font-size:12px;font-weight:700;letter-spacing:.03em;text-transform:uppercase;color:var(--nf-a)}
.nf-card h3{margin:6px 0 0;font-size:16px;line-height:1.4;font-weight:600}
.nf-card h3 a{color:#111827;text-decoration:none}.nf-card h3 a:hover{color:var(--nf-a)}
.nf .hm-grid{grid-template-columns:repeat(4,minmax(0,1fr))}
@media (max-width:980px){.nf-hero{grid-template-columns:1fr;padding:28px 22px;gap:18px}.nf-art{max-width:300px;order:-1}.nf .hm-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.nf-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:560px){.nf{padding:18px 14px 40px}.nf-hero{padding:22px 16px}.nf-art{max-width:230px}.nf-quick{grid-template-columns:1fr 1fr}.nf-grid{grid-template-columns:1fr}.nf-search button span{display:none}}
</style>
</head>
<body>
<?php include_once $_SERVER['DOCUMENT_ROOT'] . '/components/header.php'; ?>
<?php
$nfI = [
  'home' => '<path d="M3 11 12 4l9 7"/><path d="M5 10v10h14V10"/>',
  'grid' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
  'pdf' => '<path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6"/>',
  'play' => '<circle cx="12" cy="12" r="9"/><path d="m10 8 6 4-6 4z"/>',
  'news' => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 8h10M7 12h10M7 16h6"/>',
  'folder' => '<path d="M3 6a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
];
$nfIco = fn($k) => '<i aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $nfI[$k] . '</svg></i>';
$nfQuick = [['/', 'Home', 'home']];
if ($nf_store) { $nfQuick[] = ['/courses/', 'All Courses', 'grid']; $nfQuick[] = ['/notes/', 'PDF Notes', 'pdf']; if (is_file(ROOT_PATH . '/store/free.php')) $nfQuick[] = ['/free-courses/', 'Free Courses', 'play']; }
$nfQuick[] = ['/latest-updates', 'Latest Updates', 'news'];
if (!$nf_store) $nfQuick[] = ['/categories', 'All Categories', 'folder'];
?>
<main class="nf">
  <section class="nf-hero">
    <div>
      <span class="nf-tag">Error 404</span>
      <h1><?= htmlspecialchars($nf_kind === 'post' ? (function_exists('th') ? (string)th('nf_post_title') : '') : (function_exists('th') ? (string)th('nf_title') : '')) ?></h1>
      <p><?= htmlspecialchars($nf_kind === 'post' ? (function_exists('th') ? (string)th('nf_post_text') : '') : (function_exists('th') ? (string)th('nf_text') : '')) ?></p>
      <form class="nf-search" action="/search" method="get" role="search"><input type="search" name="q" placeholder="Search posts and updates…" aria-label="Search the site"><button type="submit"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg><span>Search</span></button></form>
      <nav class="nf-quick" aria-label="Popular pages"><?php foreach ($nfQuick as [$u, $l, $ic]): ?><a class="nf-q" href="<?= htmlspecialchars($u) ?>"><?= $nfIco($ic) ?><?= htmlspecialchars($l) ?></a><?php endforeach; ?></nav>
    </div>
    <svg class="nf-art" viewBox="0 0 400 300" role="img" aria-label="404">
      <defs><linearGradient id="nfG" x1="0" y1="0" x2="1" y2="1"><stop offset="0" style="stop-color:var(--gc-accent,#0c6878)"/><stop offset="1" style="stop-color:color-mix(in srgb,var(--gc-accent,#0c6878) 55%,#000)"/></linearGradient></defs>
      <rect x="20" y="40" width="360" height="220" rx="10" style="fill:color-mix(in srgb,var(--gc-accent,#0c6878) 7%,#fff)"/>
      <circle cx="330" cy="70" r="26" style="fill:color-mix(in srgb,var(--gc-accent,#0c6878) 16%,#fff)"/><circle cx="64" cy="232" r="14" style="fill:color-mix(in srgb,var(--gc-accent,#0c6878) 16%,#fff)"/>
      <text x="200" y="168" text-anchor="middle" font-family="inherit" font-size="118" font-weight="800" fill="url(#nfG)" letter-spacing="-4">404</text>
      <g transform="translate(140 186)"><path d="M0 14c22-12 46-12 60 0v46C46 48 22 48 0 60z" fill="#fff" stroke="url(#nfG)" stroke-width="4" stroke-linejoin="round"/><path d="M120 14c-22-12-46-12-60 0v46c14-12 38-12 60 0z" fill="#fff" stroke="url(#nfG)" stroke-width="4" stroke-linejoin="round"/><path d="M14 26h32M14 38h32M74 26h32M74 38h32" style="stroke:color-mix(in srgb,var(--gc-accent,#0c6878) 45%,#fff)" stroke-width="3" stroke-linecap="round"/></g>
    </svg>
  </section>
  <?php $nfOn = fn($k) => !function_exists('th_on') || th_on($k); // Customize → General → 404 Page ?>
  <?php if ($nf_courses && $nfOn('nf_courses')): ?>
  <div class="nf-h"><h2>Latest courses</h2><a href="/courses/">View all →</a></div>
  <div class="hm-grid"><?php foreach ($nf_courses as $p) echo sh_mini_card($p, $nf_ccat[(int)($p['category_id'] ?? 0)] ?? ''); ?></div>
  <?php endif; ?>
  <?php if ($nf_cats && $nfOn('nf_cats')): ?><div class="nf-h"><h2>Popular categories</h2><a href="/categories">All categories →</a></div><div class="nf-cats"><?php foreach ($nf_cats as $c): ?><a href="/categories/<?= htmlspecialchars($c['slug']) ?>"><?= htmlspecialchars($c['name']) ?><small><?= (int)$c['n'] ?></small></a><?php endforeach; ?></div><?php endif; ?>
  <?php if ($nf_posts && $nfOn('nf_posts')): ?><div class="nf-h"><h2>Latest posts</h2><a href="/latest-updates">More →</a></div><div class="nf-grid"><?php foreach ($nf_posts as $p): $u = postUrl($p['slug'], (int)$p['id']); ?>
    <article class="nf-card"><a href="<?= htmlspecialchars($u) ?>" tabindex="-1" aria-hidden="true"><?php if ($p['img']): ?><img src="/<?= htmlspecialchars(ltrim($p['img'], '/')) ?>" alt="" loading="lazy" width="640" height="360"><?php else: ?><span class="ph"><?= htmlspecialchars(mb_strtoupper(mb_substr((string)$p['title'], 0, 1))) ?></span><?php endif; ?></a>
      <div><?php if ($p['cat_name']): ?><span class="cat"><?= htmlspecialchars($p['cat_name']) ?></span><?php endif; ?><h3><a href="<?= htmlspecialchars($u) ?>"><?= htmlspecialchars($p['title']) ?></a></h3></div></article>
  <?php endforeach; ?></div><?php endif; ?>
</main>
<?php include_once $_SERVER['DOCUMENT_ROOT'] . '/components/footer.php'; ?>
</body>
</html>
