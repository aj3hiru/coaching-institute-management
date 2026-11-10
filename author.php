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
      "item": <?= json_encode(SITE_URL, JSON_HEX_TAG) ?>
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
    "sameAs": <?= json_encode($sameAs, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>
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
<style id="nb-apx-css">
/* author profile: a compact card — picture, name + tick, role, articles, social; bio; details row (theme colours) */
.nb-apx{margin:12px 0 22px}
.nb-apx-card{border:1px solid var(--edu-card-border,#e5e7eb);border-radius:5px;padding:20px;background:var(--edu-card-bg,#fff)}
.nb-apx-top{display:flex;gap:18px;align-items:center}
.nb-apx-img{width:110px;height:110px;flex:0 0 110px;object-fit:cover;border-radius:5px;background:#f3f4f6;display:block}
.nb-apx-who{flex:1;min-width:0}
.nb-apx-name{display:flex;align-items:center;gap:8px;margin:0;font-size:24px;line-height:1.25;font-weight:700;color:var(--text-primary,#1f2937)}
.nb-apx-name > span:first-child{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.nb-apx-name .nb-ap-tick{display:inline-flex;flex-shrink:0;line-height:0;color:var(--gc-accent,#7c3aed)}
.nb-apx-role{margin:4px 0 0;font-size:16px;color:#374151}
.nb-apx-count{margin:4px 0 0;font-size:14px;color:#6b7280}
.nb-apx-soc{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
.nb-apx-soc a{display:inline-grid;place-items:center;width:30px;height:30px;border-radius:5px;background:color-mix(in srgb,var(--gc-accent,#7c3aed) 8%,#fff);color:var(--gc-accent,#7c3aed);text-decoration:none;transition:background .15s,color .15s}
.nb-apx-soc a:hover{background:var(--gc-accent,#7c3aed);color:#fff}
.nb-apx-bio{margin:16px 0 0;font-size:16px;line-height:1.6;color:#374151}
.nb-apx-facts{list-style:none;margin:16px 0 0;padding:14px 0 0;border-top:1px solid var(--edu-card-border,#e5e7eb);display:flex;flex-wrap:wrap;gap:8px 22px}
.nb-apx-facts li{display:flex;align-items:center;gap:7px;font-size:14.5px;color:#374151;margin:0}
.nb-apx-facts svg{color:var(--gc-accent,#7c3aed);flex-shrink:0}
.nb-apx-facts a{color:inherit;text-decoration:none}.nb-apx-facts a:hover{color:var(--gc-accent,#7c3aed)}
.nb-apx-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px;margin-top:14px}
.nb-apx-more{border:1px solid var(--edu-card-border,#e5e7eb);border-radius:5px;padding:12px 14px}.nb-apx-more h2{margin:0 0 6px;font-size:15px}.nb-apx-more p{margin:0;font-size:14.5px;line-height:1.55;color:#374151}
.nb-apx-h{margin:0 0 18px!important}
/* "Latest posts by …" = the sidebar title bar (Customize → Colors → Sidebar Widgets → Widget Title) */
.nb-apx-h.em-wt{display:flex;align-items:stretch;justify-content:space-between;min-height:36px;border-radius:5px;overflow:hidden;padding:0;background:var(--t-sidebar-title-bg,var(--gc-accent,#7c3aed));color:var(--t-sidebar-title,#fff);font:700 14px/18px var(--font-ui,inherit);text-transform:uppercase;letter-spacing:.02em}
.nb-apx-h .em-wt-l{display:flex;align-items:center;flex:1;min-width:0;padding:9px 14px}
.nb-apx-h .em-wt-a{flex:0 0 38px;display:grid;place-items:center;background:var(--t-sidebar-title,color-mix(in srgb,var(--gc-accent,#7c3aed) 62%,#000));color:#fff}
@media (max-width:600px){.nb-apx-card{padding:16px}.nb-apx-top{align-items:flex-start;gap:14px}.nb-apx-img{width:84px;height:84px;flex-basis:84px}.nb-apx-name{font-size:19px}.nb-apx-role{font-size:14.5px}.nb-apx-soc{gap:6px}.nb-apx-soc a{width:28px;height:28px}.nb-apx-bio{font-size:15px}.nb-apx-facts{gap:6px 16px}.nb-apx-facts li{font-size:14px}}
[data-theme="dark"] .nb-apx-card{background:#151b23;border-color:#263040}[data-theme="dark"] .nb-apx-role,[data-theme="dark"] .nb-apx-bio,[data-theme="dark"] .nb-apx-facts li{color:#d1d5db}
</style>
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
            <section class="nb-apx" aria-label="About <?= htmlspecialchars($author['name']) ?>"><div class="nb-apx-card">
                <div class="nb-apx-top">
                    <img class="nb-apx-img" src="<?= htmlspecialchars($apImg) ?>" alt="<?= htmlspecialchars($author['name']) ?>" width="110" height="110" onerror="this.onerror=null;this.src='/assets/img/user.png'">
                    <div class="nb-apx-who">
                        <h1 class="nb-apx-name"><span><?= htmlspecialchars($author['name']) ?></span><span class="nb-ap-tick" title="Verified author"><?= edu_social_svg('verified', 22) ?></span></h1>
                        <?php if (!empty($author['designation'])): ?><p class="nb-apx-role"><?= htmlspecialchars($author['designation']) ?></p><?php endif; ?>
                        <p class="nb-apx-count"><?= number_format((int)$total_posts) ?> <?= (int)$total_posts === 1 ? 'article' : 'articles' ?></p>
                        <?php if ($apSoc): ?><nav class="nb-apx-soc" aria-label="<?= htmlspecialchars($author['name']) ?> on social media"><?php foreach ($apSoc as $net => $u): ?><a href="<?= htmlspecialchars($u) ?>" target="_blank" rel="noopener nofollow me" aria-label="<?= ucfirst($net === 'x' ? 'X (Twitter)' : $net) ?>"><?= edu_social_svg($net, 15) ?></a><?php endforeach; ?></nav><?php endif; ?>
                    </div>
                </div>
                <?php if (!empty($author['bio'])): ?><p class="nb-apx-bio"><?= nl2br(htmlspecialchars($author['bio'])) ?></p><?php endif; ?>
                <?php if ($apFacts || $apMail): ?>
                <ul class="nb-apx-facts">
                    <?php foreach ($apFacts as $ic => $t): ?><li><?= edu_social_svg($ic, 16) ?><span><?= htmlspecialchars($t) ?></span></li><?php endforeach; ?>
                    <?php if ($apMail): ?><li><?= edu_social_svg('mail', 16) ?><a href="mailto:<?= htmlspecialchars($apMail) ?>"><?= htmlspecialchars($apMail) ?></a></li><?php endif; ?>
                </ul>
                <?php endif; ?>
                <?php if ($apCards): ?>
                <div class="nb-apx-cards"><?php foreach ($apCards as $h => $t): ?><div class="nb-apx-more"><h2><?= $h ?></h2><p><?= nl2br(htmlspecialchars($t)) ?></p></div><?php endforeach; ?></div>
                <?php endif; ?>
            </div></section>

            <h2 class="nb-apx-h em-wt"><span class="em-wt-l">Latest posts by <?= htmlspecialchars($author['name']) ?></span><span class="em-wt-a" aria-hidden="true"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg></span></h2>
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