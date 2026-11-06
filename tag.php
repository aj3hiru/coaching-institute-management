<?php
require_once __DIR__ . '/includes/redirects.php';
edu_redirect_check();
require_once __DIR__ . '/includes/config.php';
require_once INCLUDES_PATH . '/minify.php';
require_once INCLUDES_PATH . '/functions.php';

$tag_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($tag_id <= 0) {
    header("Location: " . SITE_URL);
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM tags WHERE id = ?");
$stmt->execute([$tag_id]);
$tag = $stmt->fetch(PDO::FETCH_ASSOC);

require_once INCLUDES_PATH . '/archive.php';
if (!$tag) edu_archive_404();
// one address per tag: /tag/<slug>-<id> (an old or mistyped slug moves there)
$__tagPath = tagUrl($tag['slug'], $tag['id']);
$__reqPath = rtrim((string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
if (str_starts_with($__reqPath, '/tag/') && rawurldecode($__reqPath) !== $__tagPath) {
    $__q = (string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY);
    header('Location: ' . $__tagPath . ($__q !== '' ? '?' . $__q : ''), true, 301);
    exit;
}

// Increment View Count (Tracking)
$pdo->prepare("UPDATE tags SET views = views + 1 WHERE id = ?")->execute([$tag_id]);


$posts_per_page = POSTS_PER_PAGE;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$page = max(1, $page);
$offset = ($page - 1) * $posts_per_page;


$count_sql = "
    SELECT COUNT(*) 
    FROM posts p
    JOIN post_tag pt ON p.id = pt.post_id
    WHERE pt.tag_id = :tag_id 
    AND p.status = 'published'
";
$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute([':tag_id' => $tag_id]);
$total_posts = $count_stmt->fetchColumn();
$total_pages = ceil($total_posts / $posts_per_page);


$sql = "
    SELECT 
        p.*, 
        a.name AS author_name,
        a.slug AS author_slug,
        m.file_path AS banner_image,
        m.alt_text AS banner_alt
    FROM posts p
    JOIN post_tag pt ON p.id = pt.post_id
    JOIN authors a ON p.author_id = a.id
    LEFT JOIN media m ON p.featured_image_id = m.id
    WHERE pt.tag_id = :tag_id 
    AND p.status = 'published'
    ORDER BY p.date DESC
    LIMIT :limit OFFSET :offset
";

$stmt = $pdo->prepare($sql);
$stmt->bindValue(':tag_id', $tag_id, PDO::PARAM_INT);
$stmt->bindValue(':limit', $posts_per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$posts = $stmt->fetchAll(PDO::FETCH_ASSOC);


$seo = [
    'title'       => $tag['name'] . ($page > 1 ? " - Page $page" : '') . ' | ' . edu_site_name(),
    'description' => 'Explore all articles and resources related to ' . $tag['name'] . ' on ' . edu_site_name() . '. Updated regularly.',
    'image'       => !empty($posts[0]['banner_image'])
                        ? SITE_URL . '/' . $posts[0]['banner_image']
                        : SEO_DEFAULT_IMAGE,
    'type'        => SEO_DEFAULT_TYPE,
    'robots'      => $page > 1 ? 'noindex, follow' : SEO_DEFAULT_ROBOTS,
];
require_once INCLUDES_PATH . '/seo.php';
require_once INCLUDES_PATH . '/ads.php';
$seo = edu_seo_apply($seo, ['tag' => $tag ?? null, 'count' => (int)$total_posts]);
$seo_ctx = ['kind' => 'tag'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(edu_shortcodes((string)$seo['title'], true), ENT_QUOTES, 'UTF-8') ?></title>

<?php require_once ROOT_PATH . '/components/head_script.php'; ?>
     	
<style id="tp-inline">.no-results{text-align:center;padding:48px 16px}
.no-results-text{font-size:1.1rem;font-weight:500;color:#444;margin-bottom:16px}
.no-results-btn{display:inline-block;padding:10px 20px;font-size:.95rem;font-weight:600;color:#fff;background:#2563eb;border-radius:8px;text-decoration:none;transition:background .2s ease}
.no-results-btn:hover{background:#1e40af}</style>


    <script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "BreadcrumbList",
  "itemListElement": [
    { "@type": "ListItem", "position": 1, "name": "Home", "item": "<?= htmlspecialchars(SITE_URL) ?>" },
    { "@type": "ListItem", "position": 2, "name": "<?= htmlspecialchars($tag['name'], ENT_QUOTES, 'UTF-8') ?>" }
  ]
}
</script>



    <script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "CollectionPage",
  "name": "<?= htmlspecialchars($seo['title'], ENT_QUOTES, 'UTF-8') ?>",
  "description": "<?= htmlspecialchars($seo['description'], ENT_QUOTES, 'UTF-8') ?>",
  "mainEntity": {
    "@type": "ItemList",
    "itemListElement": [
      <?php foreach ($posts as $index => $post): ?>
      {
        "@type": "ListItem",
        "position": <?= $index + 1 ?>,
        "url": "<?= htmlspecialchars(SITE_URL . postUrl($post['slug'], $post['id'])) ?>",
        "name": "<?= htmlspecialchars($post['title']) ?>"
      }<?= $index < count($posts) - 1 ? ',' : '' ?>
      <?php endforeach; ?>
    ]
  }
}
</script>

</head>
<body>
	
    <?php require_once ROOT_PATH . '/components/header.php'; ?>
    
    <main>
        <nav class="breadcrumbs" aria-label="Breadcrumb">
            <ol>
                <li><a href="/">Home</a></li>
                <li aria-current="page"><?= htmlspecialchars($tag['name'], ENT_QUOTES, 'UTF-8') ?></li>
            </ol>
        </nav>

        <div class="container">
                <?= edu_archive_head('Tag', $tag['name'], '', (int)$total_posts) ?>
            <?php if (empty($posts)): ?>
                <div class="no-results">
    <p class="no-results-text">No posts found for this tag.</p>
    <a href="/" class="no-results-btn">Back to Home</a>
</div>
            <?php else: ?>
                <?= edu_ads('before_content') ?>
                <?= edu_archive_cards($pdo, $posts) ?>
                <?= edu_archive_pagination($page, (int)$total_pages, fn($n) => tagUrl($tag['slug'], $tag['id']) . ($n > 1 ? '?page=' . $n : '')) ?>
                	
                
                
            <?php endif; ?>
        </div>
    <?= edu_ads('after_content') ?>
</main>
    
    <?php require_once ROOT_PATH . '/components/footer.php'; ?>
    
</body>
</html>
<?php ob_end_flush(); ?>