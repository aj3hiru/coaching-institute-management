<?php
require_once __DIR__ . '/includes/redirects.php';
edu_redirect_check();
require_once __DIR__ . '/includes/config.php';
require_once INCLUDES_PATH . '/minify.php';
require_once INCLUDES_PATH . '/functions.php';

$slug = $_GET['slug'] ?? null;

// Get category by slug
$stmt = $pdo->prepare("SELECT * FROM categories WHERE slug = ?");
$stmt->execute([$slug]);
$cat = $stmt->fetch(PDO::FETCH_ASSOC);
require_once INCLUDES_PATH . '/archive.php';
if (!$cat) edu_archive_404();
$category_id = $cat['id'];

// Increase category views by 1
$update = $pdo->prepare("UPDATE categories SET views = views + 1 WHERE id = ?");
$update->execute([$category_id]);

// Pagination setup
$posts_per_page = POSTS_PER_PAGE;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$page = max(1, $page);
$offset = ($page - 1) * $posts_per_page;

// Get total post count for this category using apcu if available cache for 1 hr
$cache_key = "total_published_posts_cat_$category_id";
$total_posts = false;

if (function_exists('apcu_fetch') && apcu_enabled()) {
    $total_posts = apcu_fetch($cache_key, $success);
    if (!$success) $total_posts = false;
}

if ($total_posts === false) {
    $count_stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM posts p
        JOIN post_categories pc ON p.id = pc.post_id
        WHERE pc.category_id = ? AND p.status = 'published'
    ");
    $count_stmt->execute([$category_id]);
    $total_posts = (int) $count_stmt->fetchColumn();
    if (function_exists('apcu_store') && apcu_enabled()) {
        apcu_store($cache_key, $total_posts, 3600);
    }
}

$total_pages = ceil($total_posts / $posts_per_page);

// Get posts for this category using post_categories junction
$sql = "
    SELECT 
        p.*, 
        a.name AS author_name,
        a.slug AS author_slug,
        m.file_path AS banner_image,
        m.alt_text AS banner_alt
    FROM posts p
    JOIN post_categories pc ON p.id = pc.post_id
    JOIN authors a ON p.author_id = a.id
    LEFT JOIN media m ON p.featured_image_id = m.id
    WHERE pc.category_id = :category_id 
      AND p.status = 'published'
    ORDER BY p.date DESC
    LIMIT :limit OFFSET :offset
";

$stmt = $pdo->prepare($sql);
$stmt->bindValue(':category_id', $category_id, PDO::PARAM_INT);
$stmt->bindValue(':limit', $posts_per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// SEO SECTION ARRAY
$page_suffix = $page > 1 ? " - Page $page" : "";

$seo = [
    'title'       => ($cat['meta_title'] ?: $cat['name']) . $page_suffix . " | " . edu_site_name(),
    'description' => $cat['meta_description'] ?: "Explore all posts in {$cat['name']} on " . edu_site_name(),
    'image'       => SEO_DEFAULT_IMAGE,
    'type'        => SEO_DEFAULT_TYPE,
    'robots'      => SEO_DEFAULT_ROBOTS,
];
require_once INCLUDES_PATH . '/seo.php';
require_once INCLUDES_PATH . '/ads.php';
$seo = edu_seo_apply($seo, ['cat' => $cat ?? null, 'count' => (int)$total_posts]);
$seo_ctx = ['kind' => 'category'];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(edu_shortcodes((string)$seo['title'], true), ENT_QUOTES, 'UTF-8') ?></title>
    
    <?php require_once ROOT_PATH . '/components/head_script.php'; ?>
    
<?php if ($page > 1): ?>
<link rel="prev" href="<?= htmlspecialchars(SITE_URL) ?>/categories/<?= htmlspecialchars($cat['slug']) ?>?page=<?= $page-1 ?>">
<?php endif; ?>
<?php if ($page < $total_pages): ?>
<link rel="next" href="<?= htmlspecialchars(SITE_URL) ?>/categories/<?= htmlspecialchars($cat['slug']) ?>?page=<?= $page+1 ?>">
<?php endif; ?>
    
 <!-- Breadcrumb Schema for SEO -->
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
      "name": "<?= htmlspecialchars($cat['name']) ?>"
    }
  ]
}
</script>

<!-- Category Page Schema for SEO -->
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": ["CollectionPage", "Blog"],
  "name": "<?= htmlspecialchars($cat['name']) ?>",
  "description": "<?= htmlspecialchars($seo['description'], ENT_QUOTES, 'UTF-8') ?>",
  "url": "<?= htmlspecialchars(SITE_URL) ?>/categories/<?= htmlspecialchars($cat['slug']) ?>",
  "mainEntity": {
    "@type": "Blog",
    "name": "<?= edu_site_name() ?>",
    "url": "<?= htmlspecialchars(SITE_URL) ?>"
  },
  "hasPart": [
    <?php foreach ($posts as $index => $post): ?>
    {
      "@type": "BlogPosting",
      "headline": "<?= htmlspecialchars($post['title']) ?>",
      "url": "<?= htmlspecialchars(SITE_URL . postUrl($post['slug'], $post['id'])) ?>",
      "datePublished": "<?= date('c', strtotime($post['date'])) ?>",
      <?php if (!empty($post['updated_at'])): ?>
      "dateModified": "<?= date('c', strtotime($post['updated_at'])) ?>",
      <?php endif; ?>
      "author": {
        "@type": "Person",
        "name": "<?= htmlspecialchars($post['author_name']) ?>",
          "url": "<?= SITE_URL . '/author/' . $post['author_slug'] ?>"
      },
      "publisher": {
        "@type": "Organization",
        "name": "<?= edu_site_name() ?>",
        "logo": {
          "@type": "ImageObject",
          "url": "<?= htmlspecialchars(SITE_LOGO) ?>"
        }
      },
      <?php if (!empty($post['banner_image'])): ?>
      "image": "<?= htmlspecialchars(SITE_URL . '/' . $post['banner_image']) ?>",
      <?php endif; ?>
      "mainEntityOfPage": {
        "@type": "WebPage",
        "@id": "<?= htmlspecialchars(SITE_URL . postUrl($post['slug'], $post['id'])) ?>"
      }
    }<?= $index < count($posts) - 1 ? ',' : '' ?>
    <?php endforeach; ?>
  ]
}
</script>

</head>
<body>
	
    <?php require_once ROOT_PATH . '/components/header.php'; ?>
    
    <main>
    	<!-- Breadcrumbs -->
        <nav class="breadcrumbs" aria-label="Breadcrumb">
            <ol>
                <li><a href="/">Home</a></li>
                <li><a href="/categories">Categories</a></li>
                <li aria-current="page"><?= htmlspecialchars($cat['name']) ?></li>
            </ol>
        </nav>
        <div class="container">
            <?php // breadcrumb above already says "Categories › name" — no extra label or post count here ?>
            <?= edu_archive_head('', $cat['name'], trim((string)($cat['meta_description'] ?? ''))) ?>
            <?php if (empty($posts)): ?>
            <p class="nb-arch-empty">No posts in this category yet.</p>
            <?php else: ?>
            <?= edu_ads('before_content') ?>
            <?= edu_archive_cards($pdo, $posts) ?>
            <?= edu_archive_pagination($page, (int)$total_pages, fn($n) => '/categories/' . rawurlencode($cat['slug']) . ($n > 1 ? '?page=' . $n : '')) ?>
               	
               
            <?php endif; ?>
        </div>
        
        
    <?= edu_ads('after_content') ?>
</main>
    
    <?php require_once ROOT_PATH . '/components/footer.php'; ?>
    
</body>
</html>
<?php ob_end_flush(); ?>