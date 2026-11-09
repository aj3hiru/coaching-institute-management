<?php
require_once __DIR__ . '/includes/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/seo.php';
// Advanced SEO (Rank Math style): the sitemap is the sitemap index — like WordPress, /sitemap.xml redirects there.
if (edu_seo_module('sitemap') && seo_opt('noindex_site') !== '1') {
    header('Location: ' . rtrim(SITE_URL, '/') . '/sitemap_index.xml', true, 301);
    exit;
}

// Set correct content type
header('Content-Type: application/xml; charset=utf-8');

$stmt_global = $pdo->query("SELECT MAX(updated_at) as last_updated FROM posts WHERE status = 'published'");
$row_global = $stmt_global->fetch(PDO::FETCH_ASSOC);
$global_last_mod = $row_global['last_updated'] ? gmdate('c', strtotime($row_global['last_updated'])) : gmdate('c');

// Get site URL (adjust to your actual domain)
$site_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]";
$base_url = rtrim($site_url, '/');

// Start XML output
echo '<?xml version="1.0" encoding="UTF-8"?>';
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
        xmlns:news="http://www.google.com/schemas/sitemap-news/0.9"
        xmlns:xhtml="http://www.w3.org/1999/xhtml"
        xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">
    
    <!-- Homepage -->
    <url>
        <loc><?= htmlspecialchars($base_url) ?>/</loc>
        <lastmod><?= $global_last_mod ?></lastmod>
        <changefreq>daily</changefreq>
        <priority>1.0</priority>
    </url>
    
    <!-- RSS Feed -->
    <url>
        <loc><?= htmlspecialchars($base_url) ?>/rss.xml</loc>
        <changefreq>daily</changefreq>
        <priority>0.8</priority>
    </url>
    
    <!-- Static Pages -->
  <url>
    <loc><?= htmlspecialchars($base_url) ?>/about-us</loc>
    <changefreq>monthly</changefreq>
    <priority>0.5</priority>
  </url>

  <url>
    <loc><?= htmlspecialchars($base_url) ?>/contact-us</loc>
    <changefreq>monthly</changefreq>
    <priority>0.5</priority>
  </url>

  <url>
    <loc><?= htmlspecialchars($base_url) ?>/privacy-policy</loc>
    <changefreq>yearly</changefreq>
    <priority>0.3</priority>
  </url>
  
  <url>
    <loc><?= htmlspecialchars($base_url) ?>/terms-of-service</loc>
    <changefreq>yearly</changefreq>
    <priority>0.3</priority>
  </url>

  <url>
    <loc><?= htmlspecialchars($base_url) ?>/cookie-policy</loc>
    <changefreq>yearly</changefreq>
    <priority>0.3</priority>
  </url>
  
  <url>
    <loc><?= htmlspecialchars($base_url) ?>/editorial-policy</loc>
    <changefreq>yearly</changefreq>
    <priority>0.3</priority>
  </url>
  
  <url>
        <loc><?= htmlspecialchars($base_url) ?>/latest-posts</loc>
        <lastmod><?= $global_last_mod ?></lastmod>
        <changefreq>daily</changefreq>
        <priority>0.9</priority>
    </url>
    
    
  <!-- Online courses, notes, categories, free courses -->
<?php require_once INCLUDES_PATH . '/sitemaps.php'; if (function_exists('edu_sm_store_last') && edu_sm_store_last($pdo)): foreach (edu_sm_store_rows($pdo, $base_url) as [$u, $m]): ?>
    <url><loc><?= htmlspecialchars($u) ?></loc><lastmod><?= $m ? gmdate('c', strtotime($m)) : $global_last_mod ?></lastmod><changefreq>weekly</changefreq><priority>0.8</priority></url>
<?php endforeach; endif; ?>
  <!-- Categories & State Jobs-->
       	
<?php
$stmt = $pdo->query("
    SELECT s.slug, MAX(p.updated_at) as last_modified_date
    FROM states s
    INNER JOIN posts p ON s.id = p.state_id
    WHERE p.status = 'published'
    GROUP BY s.id, s.slug, s.state_name
    ORDER BY s.state_name ASC
");

while ($state = $stmt->fetch(PDO::FETCH_ASSOC)):
    $state_url = htmlspecialchars($base_url . '/state-jobs/' . $state['slug']);
    $last_mod_st = gmdate('c', strtotime($state['last_modified_date']));
?>
    <url>
        <loc><?= $state_url ?></loc>
        <lastmod><?= $last_mod_st ?></lastmod>
        <changefreq>daily</changefreq>
        <priority>0.8</priority>
    </url>
<?php endwhile; ?>

    
 <?php
$stmt = $pdo->query("
    SELECT c.slug, MAX(p.updated_at) as last_modified_date
    FROM categories c
    INNER JOIN posts p ON c.id = p.category_id
    WHERE p.status = 'published'
    GROUP BY c.id, c.slug
    ORDER BY c.id ASC
");

while ($cat = $stmt->fetch(PDO::FETCH_ASSOC)):
    $category_url = htmlspecialchars($base_url . '/categories/' . $cat['slug']);
    $last_mod_cat = gmdate('c', strtotime($cat['last_modified_date']));
?>
    <url>
        <loc><?= $category_url ?></loc>
        <lastmod><?= $last_mod_cat ?></lastmod>
        <changefreq>daily</changefreq>
        <priority>0.7</priority>
    </url>
<?php endwhile; ?>

    
    <?php
$stmt = $pdo->prepare("
    SELECT 
        p.id,
        p.slug,
        p.title,
        p.date,
        p.updated_at,
        m.file_path as banner_image,
        m.alt_text as banner_alt
    FROM posts p 
    LEFT JOIN media m ON p.featured_image_id = m.id
    WHERE p.status = 'published'
    ORDER BY p.date DESC
");
$stmt->execute();

while ($post = $stmt->fetch(PDO::FETCH_ASSOC)):
    $post_url = $base_url . postUrl($post['slug'], $post['id']);
    $last_mod = $post['updated_at'] ? $post['updated_at'] : $post['date'];

    $days_old = (time() - strtotime($post['date'])) / (60 * 60 * 24);
    if ($days_old < 7) {
        $priority = 0.9;
    } elseif ($days_old < 30) {
        $priority = 0.8;
    } elseif ($days_old < 90) {
        $priority = 0.7;
    } else {
        $priority = 0.6;
    }
?>
 
    <url>
<loc><?= htmlspecialchars($post_url) ?></loc>
        <lastmod><?= gmdate('c', strtotime($last_mod)) ?></lastmod>
        <changefreq><?= $days_old < 7 ? 'daily' : ($days_old < 30 ? 'weekly' : 'monthly') ?></changefreq>
        <priority><?= number_format($priority, 1) ?></priority>
        
        <?php if ($post['banner_image']): ?>
        <image:image>
            <image:loc><?= htmlspecialchars($base_url . '/' . ltrim($post['banner_image'], '/')) ?></image:loc>
            <image:title><?= htmlspecialchars($post['banner_alt'] ?: $post['title']) ?></image:title>
        </image:image>
        <?php endif; ?>
    </url>
<?php endwhile; ?>
    
</urlset>