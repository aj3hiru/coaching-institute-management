<?php
require_once __DIR__ . '/includes/redirects.php';
edu_redirect_check();
require_once __DIR__ . '/includes/config.php';
require_once INCLUDES_PATH . '/minify.php';
require_once INCLUDES_PATH . '/functions.php';

$slug = $_GET['slug'] ?? null;
$stmt = $pdo->prepare("SELECT * FROM authors WHERE slug = ?");
$stmt->execute([$slug]);
$author = $stmt->fetch(PDO::FETCH_ASSOC);
require_once INCLUDES_PATH . '/archive.php';
require_once INCLUDES_PATH . '/social_icons.php';
if (!$author) edu_archive_404();

// Pagination setup
$posts_per_page = POSTS_PER_PAGE;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$page = max(1, $page);
$offset = ($page - 1) * $posts_per_page;

// Get total post count for this author using apcu if available 
$cache_key = 'author_post_count_' . $author['id'];
$total_posts = false;

if (function_exists('apcu_fetch') && apcu_enabled()) {
    $total_posts = apcu_fetch($cache_key, $success);
    if (!$success) $total_posts = false;
}

if ($total_posts === false) {
    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE author_id = ? AND status = 'published'");
    $count_stmt->execute([$author['id']]);
    $total_posts = (int) $count_stmt->fetchColumn();
    if (function_exists('apcu_store') && apcu_enabled()) {
        apcu_store($cache_key, $total_posts, 21600);
    }
}

$total_pages = ceil($total_posts / $posts_per_page);


// Get posts with pagination
$sql = "
    SELECT 
        p.*, 
        a.name as author_name,
        m.file_path as banner_image,
        m.alt_text as banner_alt
    FROM posts p 
    JOIN authors a ON p.author_id = a.id 
    LEFT JOIN media m ON p.featured_image_id = m.id
    WHERE p.author_id = " . (int)$author['id'] . " AND p.status = 'published'
    ORDER BY p.date DESC
    LIMIT " . (int)$posts_per_page . " OFFSET " . (int)$offset;
$stmt = $pdo->query($sql);
$posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// SameAs Data for schema markup 
$sameAs = array_values(array_filter([
    $author['instagram'] ?? null,
    $author['threads']   ?? null,
    $author['linkedin']  ?? null,
    $author['facebook']  ?? null,
    $author['twitter']   ?? null,
]));

//SEO SECTION ARRAY
$seo = [
    'title'       => 'Articles by ' . $author['name'] . ' | ' . edu_site_name(),
    'description' => 'Browse all articles and updates written by ' . $author['name'] . ' on ' . edu_site_name() . '.',
    'image'       => SEO_DEFAULT_IMAGE,
    'type'        => SEO_DEFAULT_TYPE,
    'robots'      => SEO_DEFAULT_ROBOTS,
];
require_once INCLUDES_PATH . '/seo.php';
require_once INCLUDES_PATH . '/ads.php';
$seo = edu_seo_apply($seo, ['author' => $author ?? null]);
$seo_ctx = ['kind' => 'author', 'author' => $author ?? null];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(edu_shortcodes((string)$seo['title'], true), ENT_QUOTES, 'UTF-8') ?></title>
    
<?php require_once ROOT_PATH . '/components/head_script.php'; ?>

<?php if ($page > 1): ?>
<link rel="prev" href="<?= htmlspecialchars(SITE_URL . '/author/' . $slug . '?page=' . ($page - 1)) ?>">
<?php endif; ?>
<?php if ($page < $total_pages): ?>
<link rel="next" href="<?= htmlspecialchars(SITE_URL . '/author/' . $slug . '?page=' . ($page + 1)) ?>">
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
      "item": <?= json_encode(SITE_URL) ?>
    },
    {
      "@type": "ListItem",
      "position": 2,
      "name": "Author - <?= htmlspecialchars($author['name']) ?>"
    }
  ]
}
</script>

<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": ["CollectionPage", "Blog"],
  "name": "Posts by <?= htmlspecialchars($author['name']) ?>",
  "description": "Read blog posts written by <?= htmlspecialchars($author['name']) ?> on <?= edu_site_name() ?>.",
  "url": "<?= htmlspecialchars(SITE_URL) ?>/author/<?= htmlspecialchars($author['slug']) ?>",
  "author": {
    "@type": "Person",
    "name": "<?= htmlspecialchars($author['name']) ?>",
    "description": "<?= htmlspecialchars($author['bio'] ?? '') ?>",
    <?php if (!empty($sameAs)): ?>
    "sameAs": <?= json_encode($sameAs, JSON_UNESCAPED_SLASHES) ?>
    <?php endif; ?>
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
          "url": "<?= SITE_URL . '/author/' . htmlspecialchars($author['slug']) ?>"
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
        <nav class="breadcrumbs" aria-label="Breadcrumb">
            <ol>
                <li><a href="/">Home</a></li>
                <li aria-current="page"><?= htmlspecialchars($author['name']) ?></li>
            </ol>
        </nav>
        <?php
        $apImg = !empty($author['profile_image']) ? '/' . ltrim($author['profile_image'], '/') : '/assets/img/user.png';
        $apSoc = edu_author_socials($author);
        $apFacts = array_filter([
            'pin' => trim((string)($author['address'] ?? '')),
            'globe' => trim((string)($author['languages_known'] ?? '')),
            'cap' => trim((string)($author['qualifications'] ?? '')),
        ]);
        $apMail = filter_var((string)($author['email'] ?? ''), FILTER_VALIDATE_EMAIL) ?: '';
        // Experience: a short value ("3", "5+ years") sits with the details above; only long text gets its own card
        $apExp = trim((string)($author['experience'] ?? ''));
        if ($apExp !== '' && mb_strlen($apExp) <= 60 && !str_contains($apExp, "\n")) {
            if (preg_match('/^(\d+(?:\.\d+)?)\s*(\+?)$/u', $apExp, $em)) $apExp = $em[1] . $em[2] . ' ' . ((float)$em[1] === 1.0 && $em[2] === '' ? 'year' : 'years') . ' experience';
            elseif (!preg_match('/experience|anubhav|अनुभव/iu', $apExp)) $apExp .= ' experience';
            $apFacts['briefcase'] = $apExp;
            $apExp = '';
        }
        $apCards = array_filter([
            'Experience' => $apExp,
            'Specialisations &amp; Certifications' => trim((string)($author['certifications'] ?? '')),
        ]);
        ?>
        <div class="container">
            <section class="nb-ap" aria-label="About <?= htmlspecialchars($author['name']) ?>">
                <div class="nb-ap-top">
                    <a class="nb-ap-back" href="/" onclick="if(history.length>1&&document.referrer.indexOf(location.host)>-1){history.back();return false}" aria-label="Back"><?= edu_social_svg('back', 22) ?></a>
                    <div><b><?= htmlspecialchars($author['name']) ?></b><small><?= number_format((int)$total_posts) ?> <?= (int)$total_posts === 1 ? 'Post' : 'Posts' ?></small></div>
                </div>
                <div class="nb-ap-cover" aria-hidden="true"></div>
                <div class="nb-ap-main">
                    <img class="nb-ap-avatar" src="<?= htmlspecialchars($apImg) ?>" alt="<?= htmlspecialchars($author['name']) ?>" width="150" height="150" onerror="this.onerror=null;this.src='/assets/img/user.png'">
                    <h1 class="nb-ap-name"><?= htmlspecialchars($author['name']) ?> <span class="nb-ap-tick" title="Verified author"><?= edu_social_svg('verified', 30) ?></span></h1>
                    <?php if (!empty($author['designation'])): ?><p class="nb-ap-role"><?= htmlspecialchars($author['designation']) ?></p><?php endif; ?>
                    <?php if ($apSoc): ?>
                    <nav class="nb-ap-soc" aria-label="<?= htmlspecialchars($author['name']) ?> on social media">
                        <?php foreach ($apSoc as $net => $u): ?><a href="<?= htmlspecialchars($u) ?>" target="_blank" rel="noopener nofollow me" aria-label="<?= ucfirst($net === 'x' ? 'X (Twitter)' : $net) ?>"><?= edu_social_svg($net, 16) ?></a><?php endforeach; ?>
                    </nav>
                    <?php endif; ?>
                    <?php if (!empty($author['bio'])): ?><p class="nb-ap-bio"><?= nl2br(htmlspecialchars($author['bio'])) ?></p><?php endif; ?>
                    <?php if ($apFacts || $apMail): ?>
                    <ul class="nb-ap-facts">
                        <?php foreach ($apFacts as $ic => $t): ?><li><?= edu_social_svg($ic, 17) ?><span><?= htmlspecialchars($t) ?></span></li><?php endforeach; ?>
                        <?php if ($apMail): ?><li><?= edu_social_svg('mail', 17) ?><a href="mailto:<?= htmlspecialchars($apMail) ?>"><?= htmlspecialchars($apMail) ?></a></li><?php endif; ?>
                    </ul>
                    <?php endif; ?>
                    <?php if ($apCards): ?>
                    <div class="nb-ap-cards<?= count($apCards) === 1 ? ' nb-ap-cards--one' : '' ?>">
                        <?php foreach ($apCards as $h => $t): ?><div class="nb-ap-card"><h2><?= $h ?></h2><p><?= nl2br(htmlspecialchars($t)) ?></p></div><?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </section>

            <h2 class="nb-ap-h">Latest posts by <?= htmlspecialchars($author['name']) ?></h2>
            <?php if (empty($posts)): ?>
            <p class="nb-arch-empty">No posts by this author yet.</p>
            <?php else: ?>
            <?= edu_ads('before_content') ?>
            <?= edu_archive_cards($pdo, array_map(fn($p) => $p + ['author_slug' => $author['slug']], $posts)) ?>
            <?= edu_archive_pagination($page, (int)$total_pages, fn($n) => '/author/' . rawurlencode($author['slug']) . ($n > 1 ? '?page=' . $n : '')) ?>
            <?php endif; ?>
        </div>
    <?= edu_ads('after_content') ?>
</main>
    
    <?php require_once ROOT_PATH . '/components/footer.php'; ?>
    
</body>
</html>
<?php ob_end_flush(); ?>