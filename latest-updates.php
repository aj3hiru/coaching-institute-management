<?php
require_once __DIR__ . '/includes/redirects.php';
edu_redirect_check();
$page = max(1, (int)($_GET['page'] ?? 1));
$cf = $page > 1 ? "cache/latest-updates-$page.html" : "cache/latest-updates.html";
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
require_once INCLUDES_PATH . '/theme.php';


// SEO SECTION ARRAY
$seo = [
    'title'       => 'Latest Updates 2026 – New Vacancy, Results & Admit Cards | EduMint24',

    'description' => 'All latest government job updates 2026 in one place. New vacancies, admit cards, results, and answer keys — updated daily on EduMint24.',

    'image'     => SEO_DEFAULT_IMAGE,
    'type'      => SEO_DEFAULT_TYPE,
    'robots'    => SEO_DEFAULT_ROBOTS
];
require_once INCLUDES_PATH . '/seo.php';
require_once INCLUDES_PATH . '/ads.php';
$seo = edu_seo_apply($seo, []);
$seo_ctx = ['kind' => 'other'];


// FAQ SECTION ARRAY
$faqs = [

    [
        'question'    => 'How often are new updates added on EduMint24?',
        'answer_html' => 'We add new government job updates every day. The newest posts are always at the top of this page, from all categories - new vacancies, admit cards, results, and answer keys — so bookmark it and check daily.',
    ],

    [
        'question'    => 'What types of updates are listed on this page?',
        'answer_html' => 'This page covers all types of <strong>sarkari updates 2026</strong> - new vacancy notifications, admit card releases, exam results, and answer keys from SSC, Railway, UPSC, Banking, State PSC, and other recruitment boards.',
    ],

    [
        'question'    => 'How do I see older updates?',
        'answer_html' => 'Use the page numbers at the bottom of the list. Every post shows its publish date, and only government job vacancies with full details are also listed on <a href="/latest-jobs">Latest Jobs</a>.',
    ],

    [
        'question'    => 'Can I apply directly from this page?',
        'answer_html' => 'No. Click any post to open its detail page. There you\'ll find the official apply online link, important dates, eligibility, and the full notification details.',
    ],

    [
        'question'    => 'How do I find updates for a specific category?',
        'answer_html' => 'Use our <a href="/categories">Categories</a> page to filter by SSC, Railway, Banking, Results, Admit Cards, and more. You can also use the Search to find any post by keyword.',
    ],

];


// Same cards as the homepage "Latest Posts" section; page 1 opens with the wide lead card (when on in the Customizer)
const EDU_LU_PER_PAGE = 21;
$luLead = th_on('home_lead');
$luFirst = EDU_LU_PER_PAGE + ($luLead ? 1 : 0);
$total = (int)$pdo->query("SELECT COUNT(*) FROM posts WHERE status = 'published'")->fetchColumn();
$pages = $total <= $luFirst ? 1 : 1 + (int)ceil(($total - $luFirst) / EDU_LU_PER_PAGE);
if ($page > $pages) { header('Location: /latest-updates' . ($pages > 1 ? '?page=' . $pages : ''), true, 301); exit; }
$luLimit = $page === 1 ? $luFirst : EDU_LU_PER_PAGE;
$luOffset = $page === 1 ? 0 : $luFirst + ($page - 2) * EDU_LU_PER_PAGE;
$recentPosts = $pdo->query("
    SELECT p.id, p.title, p.slug, p.date, LEFT(p.content, 900) AS content_head,
           m.file_path AS banner_image, m.alt_text AS banner_alt,
           c.name AS cat_name, c.slug AS cat_slug, a.name AS author_name, a.slug AS author_slug
    FROM posts p
    LEFT JOIN media m ON p.featured_image_id = m.id
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN authors a ON p.author_id = a.id
    WHERE p.status = 'published'
    ORDER BY p.date DESC
    LIMIT $luLimit OFFSET $luOffset
")->fetchAll(PDO::FETCH_ASSOC);
$pageUrl = fn(int $n) => '/latest-updates' . ($n > 1 ? '?page=' . $n : '');
$seo_canonical = rtrim(SITE_URL, '/') . $pageUrl($page);
if ($page > 1) $seo['title'] = preg_replace('/ \| /', ' – Page ' . $page . ' | ', (string)$seo['title'], 1);
function luExcerpt(string $html, int $len = 150): string {
    $t = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace('<', ' <', $html)), ENT_QUOTES, 'UTF-8')));
    return mb_strlen($t) > $len ? rtrim(mb_substr($t, 0, $len)) . '…' : $t;
}
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
<style id="lu-sls">
.lu-section{margin:18px 0 10px;container-type:inline-size}
</style>


    <!-- Breadcrumb Schema -->
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
      "name": "Latest Updates",
      "item": <?= json_encode(SITE_URL . '/latest-updates') ?>
    }
  ]
}
</script>

    <!-- ItemList Schema -->
    <script type="application/ld+json">
<?= json_encode([
    '@context' => 'https://schema.org',
    '@type'    => 'ItemList',

    'name' =>
        'Latest Government Job Updates 2026 - ' . edu_site_name(),

    'url' =>
        $seo_canonical,

    'numberOfItems' =>
        count($recentPosts),

    'itemListElement' => array_map(
        static function ($post, $index) use ($luOffset) {
            return [
                '@type'    => 'ListItem',
                'position' => $luOffset + $index + 1,
                'url'      => SITE_URL . postUrl($post['slug'], $post['id']),
                'item'     => [
                    '@type'            => 'BlogPosting',
                    'headline'         => $post['title'],
                    'datePublished'    => date('c', strtotime($post['date'])),
                    'mainEntityOfPage' => [
                        '@type' => 'WebPage',
                        '@id'   => SITE_URL . postUrl($post['slug'], $post['id']),
                    ],
                ],
            ];
        },
        $recentPosts,
        array_keys($recentPosts)
    ),

], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>
</script>

</head>
<body>
<?php require_once ROOT_PATH . '/components/header.php'; ?>
<main>
	<nav class="breadcrumbs" aria-label="Breadcrumb">
            <ol>
                <li><a href="/">Home</a></li>
                <li aria-current="page">Latest Updates</li>
            </ol>
        </nav>  
        
    <div class="container">
    <h1>Latest Updates 2026 - Recent Government Jobs, Results & Admit Cards<?= $page > 1 ? ' (Page ' . $page . ')' : '' ?></h1>
    <?= edu_ads('before_content') ?>

        <section class="lu-section" aria-label="Recent updates">
          <div class="nb-grid nb-grid--feat">
            <?php foreach ($recentPosts as $i => $hp): $lead = $page === 1 && $i === 0 && $luLead; $url = postUrl($hp['slug'], $hp['id']); ?>
            <article class="nb-card nb-card--feat<?= $lead ? ' nb-card--lead' : '' ?>">
              <a class="nb-card-img" href="<?= $url ?>" tabindex="-1" aria-hidden="true"><?php if ($hp['banner_image']): ?><img src="/<?= htmlspecialchars($hp['banner_image']) ?>" alt="<?= htmlspecialchars($hp['banner_alt'] ?: $hp['title']) ?>" width="640" height="360" loading="<?= $i < 4 ? 'eager' : 'lazy' ?>"<?= $lead ? ' fetchpriority="high"' : '' ?> decoding="async"><?php else: ?><span class="nb-card-ph"><?= htmlspecialchars(mb_strtoupper(mb_substr($hp['title'], 0, 1))) ?></span><?php endif; ?></a>
              <div class="nb-card-body">
                <?php if ($hp['cat_name']): ?><a class="nb-card-cat" href="/categories/<?= htmlspecialchars($hp['cat_slug']) ?>"><svg class="nb-card-bolt" viewBox="0 0 24 24" aria-hidden="true"><path d="M13 2 4 14h7l-1 8 9-12h-7l1-8z"/></svg><?= htmlspecialchars($hp['cat_name']) ?></a><?php endif; ?>
                <h2 class="nb-card-title"><a href="<?= $url ?>"><?= htmlspecialchars($hp['title']) ?></a></h2>
                <div class="nb-card-meta"><?php if ($hp['author_name']): ?><span>By <a href="/author/<?= htmlspecialchars($hp['author_slug']) ?>"><?= htmlspecialchars($hp['author_name']) ?></a></span><span class="sep">·</span><?php endif; ?><time class="nb-card-date" datetime="<?= date('c', strtotime($hp['date'])) ?>"><?= date('j F Y', strtotime($hp['date'])) ?></time></div>
                <?php $ex = luExcerpt($hp['content_head'] ?? '', $lead ? 260 : 150); if ($ex !== ''): ?><p class="nb-card-excerpt"><?= htmlspecialchars($ex) ?></p><?php endif; ?>
                <?php if ($lead || th_on('home_grid_more')): ?><a class="nb-card-more" href="<?= $url ?>" aria-label="Read more: <?= htmlspecialchars($hp['title']) ?>">Read more</a><?php endif; ?>
              </div>
            </article>
            <?php endforeach; ?>
          </div>
        </section>
        <?= edu_archive_pagination($page, $pages, $pageUrl) ?>

    </div><!-- /container -->

    <?php require_once ROOT_PATH . '/components/faq.php'; ?>

<?= edu_ads('after_content') ?>
</main>
<?php require_once ROOT_PATH . '/components/footer.php'; ?>
</body>
</html>
<?php $minified = minifyHTML(ob_get_clean()); if (empty($__czp)) { file_put_contents($cf, $minified) or error_log("Failed to write cache: $cf"); } echo $minified; ?>
