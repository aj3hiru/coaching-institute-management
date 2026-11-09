<?php
// Latest Update — the blog / job-news front page (it was the site home page; the home page is now the institute page).
require_once __DIR__ . '/includes/redirects.php';
edu_redirect_check();
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;

$cf="cache/index_latest_$page.html"; // index_* so the same cache clearing (new post, Clear cache) covers it
$__czp = isset($_GET['czp']);
$__cs = @json_decode((string)@file_get_contents(__DIR__ . '/cache/.settings.json'), true) ?: [];
if (!empty($__cs['off'])) $__czp = true; // cache system switched off in the Cache Manager
$__cdn = function () use ($__cs) { if (!empty($__cs['cdn']) && empty($_COOKIE['PHPSESSID'])) header('Cache-Control: public, max-age=' . max(0, (int)($__cs['max_age'] ?? 60)) . (!empty($__cs['swr']) ? ', stale-while-revalidate=300' : '')); };
if (!$__czp && file_exists($cf) && time() - filemtime($cf) < (int)($__cs['home'] ?? 3600)) { $__cdn(); readfile($cf); exit; }
ob_start();

require_once __DIR__ . '/includes/config.php';
require_once INCLUDES_PATH . '/minify.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/settings.php';
require_once INCLUDES_PATH . '/theme.php';
require_once INCLUDES_PATH . '/ads.php';


// SEO SECTION ARRAY
$seo = [
    'title' => 'Latest Update – New Vacancy 2026, Government Bharti & Results | ' . edu_site_name(),

    'description' => 'Check all New Vacancy 2026 updates including latest government bharti.',

    'image' => SEO_DEFAULT_IMAGE,

    'type' => SEO_DEFAULT_TYPE,
    
    'robots' => SEO_DEFAULT_ROBOTS
];
require_once INCLUDES_PATH . '/seo.php';
$seo = edu_seo_apply($seo, []);
$seo_ctx = ['kind' => 'page'];
$seo_canonical = rtrim(SITE_URL, '/') . '/latest-update';
if (es('general', 'home_description') !== '') $seo['description'] = es('general', 'home_description');


// FAQ SECTION ARRAY
$faqs = [

    [
        'question' =>
            'How can I apply for new government jobs online in 2026?',

        'answer_html' =>
            'To apply for <strong>new government jobs 2026 online</strong>, just click the official link we shared in the post. You’ll go on the application page click on “Apply Online” button, fill in your basic details, upload the required documents, and submit. It’s a easy process.',
    ],

    [
        'question' =>
            'Which government jobs are available for 12th pass students?',

        'answer_html' =>
            'Many job openings exist for <strong>12th-pass candidates</strong> like SSC, Railways, Police, Army, Post Office, and various state departments. We update new vacancies every day on our <a href="/categories/12th-pass-govt-jobs">12th Pass Govt Jobs</a> page, so save it and check regularly.',
    ],

    [
        'question' =>
            'What documents do I need to apply for government jobs online?',

        'answer_html' =>
            'Most applications ask for your 10th/12th mark sheets, a recent passport size photo, signature, Aadhaar or ID proof, and caste/category certificate (if applicable). Some posts also need a domicile certificate or experience letter. The exact list is always in the official notification check it on <a href="' . SITE_URL . '">' . APP_NAME . '</a> before you filling the form.',
    ],

    [
        'question' =>
            'How will I know the exam date and when the admit card is released?',

        'answer_html' =>
            'Once you’ve applied, keep an eye on the official website or our <a href="/categories/admit-card">Admit Cards</a> section. We post the admit cards information and share confirmed exam dates so you never miss it.',
    ],

    [
        'question' =>
            'Can I directly apply online for new government jobs 2026 from your website?',

        'answer_html' =>
            'No, we don’t host the application forms but we give you the <strong>official apply online link</strong> for every <strong>new government job 2026</strong> when it’s released. Just click the link in the post, and you’ll be taken straight to the real government portal (like <a href="https://ssc.gov.in/">ssc.gov.in</a>, <a href="https://upsconline.nic.in">upsconline.nic.in</a>, etc.) to fill and submit your form safely.',
    ],

];




// Fetch posts by category slug, limit 10
function getPostsByCategory(PDO $pdo, string $slug, int $limit = 10): array {
    $stmt = $pdo->prepare("
        SELECT p.id, p.title, p.slug, p.date
        FROM posts p
        JOIN categories c ON p.category_id = c.id
        WHERE c.slug = :slug AND p.status = 'published'
        ORDER BY p.date DESC
        LIMIT :limit
    ");
    $stmt->bindValue(':slug', $slug);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getRecentPosts(PDO $pdo, int $limit = 10): array {
    $stmt = $pdo->prepare("
        SELECT p.id, p.title, p.slug, p.date
        FROM posts p
        WHERE p.status = 'published'
        ORDER BY p.date DESC
        LIMIT :limit
    ");
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// "New" badge – posts within last 3 days
function isNew(string $dateStr): bool {
    return (time() - strtotime($dateStr)) < (3 * 86400);
}

$recentPosts = getRecentPosts($pdo, 15);
// Admit Card + Answer Key + Result together show 15 posts: 5 each, and when one has fewer the other two fill in.
$hvSide = [getPostsByCategory($pdo, 'admit-card', 15), getPostsByCategory($pdo, 'answer-key', 15), getPostsByCategory($pdo, 'result', 15)];
$hvTake = [0, 0, 0];
for ($left = 15; $left > 0;) {
    $moved = false;
    foreach ($hvSide as $i => $list) if ($left > 0 && $hvTake[$i] < count($list)) { $hvTake[$i]++; $left--; $moved = true; }
    if (!$moved) break;
}
[$admitCards, $answerKeys, $results] = array_map(fn($list, $n) => array_slice($list, 0, $n), $hvSide, $hvTake);

// Latest posts for the cards section (lead card + 3 per row): image, category, author, short excerpt.
$homeLimit = (int)th('home_posts', 7);
$homeCards = $homeLimit <= 0 ? [] : $pdo->query("
    SELECT p.id, p.title, p.slug, p.date, LEFT(p.content, 900) AS content_head,
           m.file_path AS banner_image, m.alt_text AS banner_alt,
           c.name AS cat_name, c.slug AS cat_slug, a.name AS author_name, a.slug AS author_slug
    FROM posts p
    LEFT JOIN media m ON p.featured_image_id = m.id
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN authors a ON p.author_id = a.id
    WHERE p.status = 'published'
    ORDER BY p.date DESC
    LIMIT " . min(30, $homeLimit) . "
")->fetchAll(PDO::FETCH_ASSOC);
function homeExcerpt(string $html, int $len = 170): string {
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

	
<style id="home-sls">
 /* ── Hero Search Section ───────────────────────── */
.hero-search-section {
  padding: var(--space-4) 0;
}

.hero-search-card {
  background: var(--gradient-primary);
  border-radius: var(--border-radius-lg);
  padding: var(--space-10) var(--space-8);
  text-align: center;
  position: relative;
  overflow: hidden;
  box-shadow: var(--shadow-lg);
}

.hero-search-card::before,
.hero-search-card::after {
  content: "";
  position: absolute;
  border-radius: 50%;
  opacity: 0.15;
  pointer-events: none;
}
.hero-search-card::before {
  width: 300px; height: 300px;
  background: #fff;
  top: -80px; right: -60px;
}
.hero-search-card::after {
  width: 200px; height: 200px;
  background: #fff;
  bottom: -60px; left: -40px;
}

.hero-search-label {
  font-family: var(--font-heading);
  font-size: var(--text-2xl);
  font-weight: var(--weight-bold);
  color: var(--text-white);
  margin-bottom: var(--space-2);
  line-height: var(--leading-tight);
}

.hero-search-sub {
  font-size: var(--text-base);
  color: rgba(255, 255, 255, 0.75);
  margin-bottom: var(--space-6);
}

.hero-search-form {
  display: flex;
  align-items: center;
  max-width: 560px;
  margin: 0 auto;
  background: var(--bg-card);
  border-radius: var(--border-radius-full);
  padding: 5px 5px 5px var(--space-5);
  box-shadow: 0 4px 24px rgba(0, 0, 0, 0.15);
  gap: var(--space-2);
}

.hero-search-input {
  flex: 1;
  border: none;
  outline: none;
  font-family: var(--font-body);
  font-size: var(--text-base);
  color: var(--text-body);
  background: transparent;
  min-width: 0;
}

.hero-search-input::placeholder {
  color: var(--text-faint);
}

.hero-search-btn {
  background: var(--gradient-dark);
  color: var(--text-white);
  border: none;
  border-radius: var(--border-radius-full);
  padding: 0 var(--space-6);
  height: 38px;
  font-size: var(--text-sm);
  font-weight: var(--weight-semibold);
  cursor: pointer;
  white-space: nowrap;
  display: flex;
  align-items: center;
  gap: var(--space-2);
  flex-shrink: 0;
  transition: opacity var(--ease-fast);
}

.hero-search-btn:hover { opacity: 0.88; }

/* ── Category Buttons ──────────────────────────── */
.hero-categories {
  display: grid;
  grid-template-columns: repeat(5, 1fr);
  gap: var(--space-3);
  margin-top: var(--space-5);
  list-style: none;
  padding: 0;
}

.hc-item {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: var(--space-2);
  padding: var(--space-4) var(--space-2);
  background: var(--bg-card);
  border: var(--border-width) solid var(--border-light);
  border-radius: var(--border-radius-md);
  text-decoration: none;
  color: var(--text-secondary);
  box-shadow: var(--shadow-sm);
  transition:
    background var(--ease-fast),
    border-color var(--ease-fast),
    color var(--ease-fast),
    box-shadow var(--ease-fast),
    transform var(--ease-fast);
}

.hc-item:hover {
  background: var(--color-primary-soft);
  border-color: var(--color-primary-light);
  color: var(--color-primary);
  box-shadow: var(--shadow-md);
  transform: translateY(-2px);
}

.hc-icon {
  width: 32px;
  height: 32px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.hc-icon svg {
  width: 28px;
  height: 28px;
  stroke: var(--text-muted);
  fill: none;
  stroke-width: 1.6;
  stroke-linecap: round;
  stroke-linejoin: round;
  transition: stroke var(--ease-fast);
}

.hc-item:hover .hc-icon svg {
  stroke: var(--color-primary);
}

.hc-name {
  font-size: var(--text-xs);
  font-weight: var(--weight-semibold);
  text-align: center;
  line-height: var(--leading-tight);
}

/* ── Responsive ────────────────────────────────── */
@media (max-width: 900px) {
  .hero-categories { grid-template-columns: repeat(5, 1fr); }
}

@media (max-width: 560px) {
  .hero-search-card { padding: var(--space-8) var(--space-5); }
  .hero-search-label { font-size: var(--text-xl); }
  .hero-categories { grid-template-columns: repeat(3, 1fr); gap: var(--space-2); }
  .hc-item { padding: var(--space-3) var(--space-1); }
}




</style>


    <!-- Breadcrumbs Schema MARKUP SEO -->
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
   "name": "Latest Update",
   "item": <?= json_encode(rtrim(SITE_URL, '/') . '/latest-update') ?>
  }
 ]
}
</script>

<!-- Recent Posts Schema MARKUP SEO -->
<script type="application/ld+json">
<?= json_encode([
    '@context' => 'https://schema.org',
    '@type'    => 'ItemList',

    'name' =>
        'Recent Posts - ' . edu_site_name(),

    'url' =>
        SITE_URL,

    'numberOfItems' =>
        count($recentPosts),

    'itemListElement' => array_map(
        static function ($post, $index) {

            return [
                '@type'    => 'ListItem',

                'position' =>
                    $index + 1,

                'url' =>
                    SITE_URL . postUrl($post['slug'], $post['id']),

                'item' => [
                    '@type' => 'BlogPosting',

                    'headline' =>
                        $post['title'],

                    'datePublished' =>
                        date('c', strtotime($post['date'])),

                    'mainEntityOfPage' => [
                        '@type' => 'WebPage',

                        '@id' =>
                            SITE_URL . postUrl($post['slug'], $post['id']),
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
        <div class="container">
<?php if (th_on('home_hero')): ?>
<section class="hero-search-section">

  <!-- Gradient Search Card -->
  <form class="hero-search-card" action="/search" method="get">
  <h1 class="hero-search-label">Find Government Jobs 2026</h1>

  <p class="hero-search-sub">
    Search from thousands of latest sarkari naukri updates
  </p>

  <div class="hero-search-form">
    <input
      class="hero-search-input"
      name="q"
      type="search"
      placeholder="Search jobs, exams, results…"
      autocomplete="off"
      required
    />

    <button type="submit" class="hero-search-btn">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
        <circle cx="11" cy="11" r="8"></circle>
        <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
      </svg>
      Search
    </button>
  </div>
</form>

  <!-- Category Buttons -->
  <nav class="hero-categories" aria-label="Courses and blog categories">

    <a href="/courses/" class="hc-item">
      <span class="hc-icon">
        <svg viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M10 9l5 3-5 3V9z"/></svg>
      </span>
      <span class="hc-name">All Courses</span>
    </a>
    <a href="/categories/general-knowledge" class="hc-item">
      <span class="hc-icon">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.5 3.8 5.5 3.8 9s-1.3 6.5-3.8 9c-2.5-2.5-3.8-5.5-3.8-9S9.5 5.5 12 3z"/></svg>
      </span>
      <span class="hc-name">General Knowledge</span>
    </a>
    <a href="/categories/quiz" class="hc-item">
      <span class="hc-icon">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 015 0c0 1.7-2.5 2-2.5 4"/><circle cx="12" cy="17" r=".6" fill="currentColor"/></svg>
      </span>
      <span class="hc-name">Quiz</span>
    </a>
    <a href="/categories/scholarship" class="hc-item">
      <span class="hc-icon">
        <svg viewBox="0 0 24 24"><path d="M22 9L12 5 2 9l10 4 10-4z"/><path d="M6 11v5c0 2 2.5 4 6 4s6-2 6-4v-5"/></svg>
      </span>
      <span class="hc-name">Scholarship</span>
    </a>
    <a href="/categories/latest-update" class="hc-item">
      <span class="hc-icon">
        <svg viewBox="0 0 24 24"><path d="M13 2L4 14h7l-1 8 9-12h-7l1-8z"/></svg>
      </span>
      <span class="hc-name">Latest Update</span>
    </a>

  </nav>

</section>
<?php endif; ?>
 
<?= edu_ads('before_content') ?>
<?php if ($homeCards): ?>
<section class="home-posts" aria-labelledby="home-posts-title">
  <div class="home-sec-head"><h2 id="home-posts-title" class="home-sec-title"><?= edu_shortcodes(htmlspecialchars((string)th('home_heading') ?: 'Latest Posts')) ?></h2><a href="/latest-updates" class="home-sec-more">View All »</a></div>
  <div class="nb-grid nb-grid--feat">
    <?php foreach ($homeCards as $i => $hp): $lead = $i === 0 && th_on('home_lead'); $url = postUrl($hp['slug'], $hp['id']); ?>
    <article class="nb-card nb-card--feat<?= $lead ? ' nb-card--lead' : '' ?>">
      <a class="nb-card-img" href="<?= $url ?>" tabindex="-1" aria-hidden="true"><?php if ($hp['banner_image']): ?><img src="/<?= htmlspecialchars($hp['banner_image']) ?>" alt="<?= htmlspecialchars($hp['banner_alt'] ?: $hp['title']) ?>" width="640" height="360" loading="<?= $i < 4 ? 'eager' : 'lazy' ?>"<?= $lead ? ' fetchpriority="high"' : '' ?> decoding="async"><?php else: ?><span class="nb-card-ph"><?= htmlspecialchars(mb_strtoupper(mb_substr($hp['title'], 0, 1))) ?></span><?php endif; ?></a>
      <div class="nb-card-body">
        <?php if ($hp['cat_name']): ?><a class="nb-card-cat" href="/categories/<?= htmlspecialchars($hp['cat_slug']) ?>"><svg class="nb-card-bolt" viewBox="0 0 24 24" aria-hidden="true"><path d="M13 2 4 14h7l-1 8 9-12h-7l1-8z"/></svg><?= htmlspecialchars($hp['cat_name']) ?></a><?php endif; ?>
        <h3 class="nb-card-title"><a href="<?= $url ?>"><?= htmlspecialchars($hp['title']) ?></a></h3>
        <div class="nb-card-meta"><?php if ($hp['author_name']): ?><span>By <a href="/author/<?= htmlspecialchars($hp['author_slug']) ?>"><?= htmlspecialchars($hp['author_name']) ?></a></span><span class="sep">·</span><?php endif; ?><time class="nb-card-date" datetime="<?= date('c', strtotime($hp['date'])) ?>"><?= date('j F Y', strtotime($hp['date'])) ?></time></div>
        <?php $ex = homeExcerpt($hp['content_head'] ?? '', $lead ? 260 : 150); if ($ex !== ''): ?><p class="nb-card-excerpt"><?= htmlspecialchars($ex) ?></p><?php endif; ?>
        <?php if ($lead || th_on('home_grid_more')): ?><a class="nb-card-more" href="<?= $url ?>" aria-label="Read more: <?= htmlspecialchars($hp['title']) ?>">Read more</a><?php endif; ?>
      </div>
    </article>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php // job cards slider (posts with a job card), right above "Latest New Vacancy"
require_once INCLUDES_PATH . '/jobcard.php'; echo edu_job_slider($pdo); ?>

<?php if (th_on('home_tables')): ?>
<?php
// "Latest New Vacancy" boxes: one card per list, rows as tappable links, cards only as tall as their content
function renderHvCard(array $posts, string $key, string $title, string $viewAllUrl, string $icon): void {
    $n = count($posts);
    ?>
    <article class="hv-card hv-<?= $key ?>">
      <header class="hv-head">
        <span class="hv-ico" aria-hidden="true"><svg viewBox="0 0 24 24"><?= $icon ?></svg></span>
        <h3 class="hv-title"><?= htmlspecialchars($title) ?></h3>
        <?php if ($n): ?><span class="hv-count"><?= $n ?></span><?php endif; ?>
        <a href="<?= $viewAllUrl ?>" class="hv-all" aria-label="View all: <?= htmlspecialchars($title) ?>">View all<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg></a>
      </header>
      <?php if (!$n): ?>
      <p class="hv-empty">New <?= htmlspecialchars(strtolower($title)) ?> updates will appear here.</p>
      <?php else: ?>
      <ul class="hv-list">
        <?php foreach ($posts as $post): $ts = strtotime($post['date']); $u = postUrl($post['slug'], $post['id']); ?>
        <li><a class="hv-row" href="<?= $u ?>">
          <time class="hv-date" datetime="<?= date('Y-m-d', $ts) ?>"><b><?= date('d', $ts) ?></b><span><?= date('M', $ts) ?></span></time>
          <span class="hv-text"><?php if (isNew($post['date'])): ?><span class="hv-new">New</span><?php endif; ?><?= htmlspecialchars($post['title']) ?></span>
          <svg class="hv-go" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </a></li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </article>
    <?php
}
?>
<section class="hv-section" aria-labelledby="hv-heading">
  <style>
  .hv-section{margin:34px 0 10px}
  .hv-section .hv-h2{margin:0 0 6px;font-size:24px;line-height:1.3;font-weight:800;color:var(--text-primary,#111827)}
  .hv-sub{margin:0 0 18px;color:#6b7280;font-size:14.5px}
  .hv-grid{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(0,1fr);gap:18px;align-items:start}.hv-side{display:flex;flex-direction:column;gap:18px;min-width:0}
  .hv-card{--hv:var(--gc-accent,#0c6878);--hv-soft:#e6f2f4;background:#fff;border:1px solid #e5e7eb;border-radius:14px;overflow:hidden;box-shadow:0 1px 2px rgba(16,24,40,.04)}
  .hv-recent{--hv:var(--gc-accent,#0c6878);--hv-soft:#e6f2f4}.hv-admit{--hv:#2563eb;--hv-soft:#eaf1fe}.hv-answer{--hv:#059669;--hv-soft:#e7f7f1}.hv-result{--hv:#d97706;--hv-soft:#fdf3e2}
  .hv-head{display:flex;align-items:center;gap:10px;padding:14px 16px;border-bottom:1px solid #f0f1f3;border-top:3px solid var(--hv)}
  .hv-ico{width:34px;height:34px;border-radius:10px;background:var(--hv-soft);color:var(--hv);display:grid;place-items:center;flex-shrink:0}
  .hv-ico svg{width:19px;height:19px;fill:currentColor}
  .hv-section .hv-title{margin:0;font-size:17px;font-weight:700;color:#111827;line-height:1.2}
  .hv-count{font-size:12px;font-weight:700;color:var(--hv);background:var(--hv-soft);border-radius:99px;padding:2px 8px}
  .hv-all{margin-left:auto;display:inline-flex;align-items:center;gap:2px;font-size:13.5px;font-weight:600;color:var(--hv) !important;white-space:nowrap;padding:6px 4px}
  .hv-all svg{width:15px;height:15px}.hv-all:hover{opacity:.8}
  .hv-list{list-style:none;margin:0;padding:4px 0}
  .hv-list li+li{border-top:1px solid #f3f4f6}
  .hv-row{display:flex;align-items:center;gap:12px;padding:10px 16px;color:#1f2937 !important;transition:background .15s}
  .hv-row:hover{background:#f9fafb}.hv-row:hover .hv-text{color:var(--hv)}.hv-row:hover .hv-go{color:var(--hv);transform:translateX(2px)}
  .hv-date{flex-shrink:0;width:44px;padding:4px 0;border-radius:8px;background:#f5f6f8;text-align:center;line-height:1.1}
  .hv-date b{display:block;font-size:16px;font-weight:800;color:#111827}.hv-date span{display:block;font-size:10.5px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:#6b7280}
  .hv-text{flex:1;min-width:0;font-size:14.5px;font-weight:500;line-height:1.45;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;transition:color .15s}
  .hv-new{display:inline-block;vertical-align:1px;margin-right:6px;padding:1px 6px;border-radius:4px;background:#dc2626;color:#fff;font-size:10.5px;font-weight:700;letter-spacing:.03em;text-transform:uppercase}
  .hv-go{width:16px;height:16px;flex-shrink:0;color:#9ca3af;transition:transform .15s,color .15s}
  .hv-empty{margin:0;padding:18px 16px;color:#9ca3af;font-size:14px}
  @media (max-width:860px){.hv-grid{grid-template-columns:1fr;gap:14px}.hv-side{gap:14px}.hv-section .hv-h2{font-size:21px}}
  @media (max-width:480px){.hv-head{padding:12px 14px}.hv-row{padding:10px 14px;gap:10px}.hv-text{font-size:14px}.hv-date{width:40px}}
  [data-theme="dark"] .hv-card{background:#151b23;border-color:#263040}[data-theme="dark"] .hv-section .hv-title,[data-theme="dark"] .hv-date b{color:#e5e7eb}[data-theme="dark"] .hv-row{color:#d1d5db !important}[data-theme="dark"] .hv-date{background:#1f2733}[data-theme="dark"] .hv-head,[data-theme="dark"] .hv-list li+li{border-color:#263040}[data-theme="dark"] .hv-row:hover{background:#1b2230}
  </style>
  <h2 class="m-h2 hv-h2" id="hv-heading">Latest New Vacancy 2026 - Sarkari Jobs & Government Bharti</h2>
  <p class="hv-sub">Latest jobs, admit cards, answer keys and results — updated daily.</p>
  <div class="hv-grid">
    <?php
      renderHvCard($recentPosts, 'recent', 'Recent Updates', '/latest-updates', '<path d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2zm1 10.4 3.3 3.3-1.4 1.4L11 13.2V6h2z"/>');
      echo '<div class="hv-side">';
      renderHvCard($admitCards, 'admit', 'Admit Card', '/categories/admit-card', '<path d="M4 4h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2zm4 4a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5zm-4 9h8c0-1.7-1.8-3-4-3s-4 1.3-4 3zm10-8v2h6V9zm0 4v2h4v-2z"/>');
      renderHvCard($answerKeys, 'answer', 'Answer Key', '/categories/answer-key', '<path d="M7 3h10a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2zm1.3 9.3-1.4 1.4L9.6 16.4 15.1 11l-1.4-1.4-4.1 4.1z"/>');
      renderHvCard($results, 'result', 'Result', '/categories/result', '<path d="M12 2 9.2 7.7 3 8.6l4.5 4.4-1.1 6.2L12 16.3l5.6 2.9-1.1-6.2L21 8.6l-6.2-.9z"/>');
      echo '</div>';
    ?>
  </div>
</section>
<?php endif; ?>
            
        </div> <!-- container closer -->
    

<?= edu_ads('after_content') ?>
<?php if (th_on('home_faqs')) { $faqLayout = 'home'; require_once ROOT_PATH . '/components/faq.php'; } ?>

    </main>
    <?php require_once ROOT_PATH . '/components/footer.php'; ?>
    
</body>
</html>
<?php $minified = minifyHTML(ob_get_clean()); if (empty($__czp)) { file_put_contents($cf, $minified) or error_log("Failed to write cache: $cf"); } echo $minified; ?> 