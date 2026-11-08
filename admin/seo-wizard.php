<?php
// SEO Setup Wizard — the same steps as Rank Math's: Getting Started → Your Site → Search Console → Sitemaps →
// Optimization → Ready, then optional Advanced Options (Role Manager, 404 + Redirection, Schema Markup).
require_once __DIR__ . '/components/guard.php';
require_once INCLUDES_PATH . '/seo.php';
$me = admin_guard(['admin']);

const WZ_STEPS = ['start' => 'Getting Started', 'yoursite' => 'Your Site', 'analytics' => 'Search Console', 'sitemaps' => 'Sitemaps', 'optimization' => 'Optimization', 'ready' => 'Ready'];
const WZ_ADV = ['role' => 'Role Manager', 'redirection' => '404 + Redirection', 'schema' => 'Schema Markup'];
$step = $_GET['step'] ?? 'start';
if (!isset(WZ_STEPS[$step]) && !isset(WZ_ADV[$step])) $step = 'start';
$isAdv = isset(WZ_ADV[$step]);
$order = $isAdv ? array_keys(WZ_ADV) : array_keys(WZ_STEPS);
$next = function (string $s) use ($order, $isAdv) { $i = array_search($s, $order, true); return $order[$i + 1] ?? ($isAdv ? 'done' : 'ready'); };

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $f = fn($k) => !empty($_POST[$k]) ? '1' : '0';
    $t = fn($k, $max = 300) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max);
    $save = [];
    if (empty($_POST['skip'])) {
        switch ($step) {
            case 'start':
                $lvl = $_POST['level'] ?? 'advanced';
                $save = ['mode' => $lvl === 'original' ? 'original' : 'advanced', 'wizard_level' => in_array($lvl, ['easy', 'advanced'], true) ? $lvl : 'advanced'];
                break;
            case 'yoursite':
                $save = ['site_type' => in_array($_POST['site_type'] ?? '', ['blog', 'news', 'portfolio', 'business', 'otherpersonal', 'otherbusiness', 'education'], true) ? $_POST['site_type'] : 'news',
                    'org_type' => in_array($_POST['org_type'] ?? '', ['Organization', 'Person', 'EducationalOrganization', 'NewsMediaOrganization', 'LocalBusiness'], true) ? $_POST['org_type'] : 'Organization',
                    'site_name' => $t('site_name', 120), 'site_alt_name' => $t('site_alt_name', 120), 'org_name' => $t('org_name', 120), 'org_logo' => $t('org_logo'), 'og_image' => $t('og_image')];
                $save['schema_default'] = in_array($save['site_type'], ['news', 'education'], true) ? 'NewsArticle' : ($save['site_type'] === 'blog' ? 'BlogPosting' : 'Article');
                break;
            case 'analytics':
                $save = ['v_google' => $t('v_google'), 'v_bing' => $t('v_bing')];
                break;
            case 'sitemaps':
                $save = ['m_sitemap' => $f('m_sitemap'), 'sm_images' => $f('sm_images'), 'sm_posts' => $f('sm_posts'), 'sm_pages' => $f('sm_pages'), 'sm_categories' => $f('sm_categories'), 'sm_tags' => $f('sm_tags'),
                    'm_news_sitemap' => $f('m_news_sitemap'), 'news_name' => $t('news_name', 120)];
                break;
            case 'optimization':
                $save = ['noindex_empty' => $f('noindex_empty'), 'nofollow_external' => $f('nofollow_external'), 'external_blank' => $f('external_blank')];
                break;
            case 'role':
                $save = ['m_role_manager' => $f('m_role_manager'), 'role_editor_seo' => $f('role_editor_seo'), 'role_author_seo' => $f('role_author_seo')];
                break;
            case 'redirection':
                $save = ['m_404' => $f('m_404'), 'm_redirections' => $f('m_redirections')];
                break;
            case 'schema':
                $save = ['m_schema' => $f('m_schema'), 'schema_default' => in_array($_POST['schema_default'] ?? '', ['NewsArticle', 'BlogPosting', 'Article'], true) ? $_POST['schema_default'] : 'NewsArticle', 'page_schema' => 'Article'];
                break;
        }
    }
    $nx = $next($step);
    if ($nx === 'ready' || $nx === 'done' || $step === 'start' && ($save['mode'] ?? '') === 'original') $save['wizard_done'] = '1';
    if ($save) edu_settings_save('seo', $save);
    if (isset($save['mode']) || $nx === 'ready') {
        // robots.txt names the sitemap of the chosen mode; IndexNow key ready in Advanced mode
        $mode = $save['mode'] ?? (string)seo_opt('mode');
        $rf = ROOT_PATH . '/robots.txt';
        $rt = is_file($rf) ? (string)file_get_contents($rf) : "User-agent: *\nAllow: /\n";
        $b = rtrim(SITE_URL, '/');
        $rt = rtrim(preg_replace('/^\s*Sitemap:.*$\R?/mi', '', $rt)) . "\n\nSitemap: $b/" . ($mode === 'original' ? 'sitemap.xml' : 'sitemap_index.xml') . "\nSitemap: $b/news-sitemap.xml\n";
        @file_put_contents($rf, preg_replace("/\n{3,}/", "\n\n", $rt));
        if ($mode !== 'original') { require_once INCLUDES_PATH . '/indexing.php'; edu_ix_key(); }
    }
    admin_log('seo_wizard', 'SEO Setup Wizard: ' . (WZ_STEPS[$step] ?? WZ_ADV[$step]) . (empty($_POST['skip']) ? ' saved' : ' skipped'));
    if ($step === 'start' && ($save['mode'] ?? '') === 'original') { admin_flash('Basic SEO is on. You can switch to Advanced SEO any time (SEO → top right).'); header('Location: /admin/seo.php'); exit; }
    if ($nx === 'done') { admin_flash('Advanced options saved — your SEO is ready.'); header('Location: /admin/seo.php'); exit; }
    header('Location: /admin/seo-wizard.php?step=' . $nx);
    exit;
}
$o = fn($k) => htmlspecialchars((string)seo_opt($k), ENT_QUOTES, 'UTF-8');
$on = fn($k) => seo_opt($k) === '1' ? 'checked' : '';
$esc = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$logo = (string)es('general', 'logo', '');
$steps = $isAdv ? WZ_ADV : WZ_STEPS;
$cur = array_search($step, array_keys($steps), true);
$sw = fn($k, $label, $desc) => '<div class="wz-row"><div class="wz-l">' . $label . '</div><div class="wz-f"><label class="rm-sw"><input type="checkbox" name="' . $k . '" value="1" ' . $on($k) . '><span></span></label><p class="rm-desc">' . $desc . '</p></div></div>';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title>Setup Wizard - SEO</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="/assets/css/admin-ui.css?v=<?= @filemtime(ROOT_PATH . '/assets/css/admin-ui.css') ?>">
<link rel="stylesheet" href="/assets/css/admin-seo.css?v=<?= @filemtime(ROOT_PATH . '/assets/css/admin-seo.css') ?>">
<script src="/assets/js/admin-ui.js?v=<?= @filemtime(ROOT_PATH . '/assets/js/admin-ui.js') ?>" defer></script>
<script src="/assets/js/admin-media.js?v=<?= @filemtime(ROOT_PATH . '/assets/js/admin-media.js') ?>" defer></script>
<link rel="stylesheet" href="/assets/css/admin-flat.css?v=<?= @filemtime(ROOT_PATH . '/assets/css/admin-flat.css') ?>">
<script src="/assets/js/admin-select.js?v=<?= @filemtime(ROOT_PATH . '/assets/js/admin-select.js') ?>" defer></script>
<style>
body{background:#f0f2f5;margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen-Sans,Ubuntu,Cantarell,"Helvetica Neue",sans-serif;color:#1e293b}
.wz{max-width:970px;margin:0 auto;padding:40px 16px 60px}
.wz-logo{text-align:center;margin:0 0 34px}.wz-logo img{max-height:56px;max-width:260px}.wz-logo b{font-size:34px;color:#0c6878;font-weight:700}.wz-logo b span{color:#0f172a}
.wz-steps{display:flex;justify-content:space-between;position:relative;margin:0 0 40px;padding:0 0 0}
.wz-steps::before{content:"";position:absolute;left:0;right:0;bottom:8px;height:3px;background:#d1d9e0}
.wz-steps .bar{position:absolute;left:0;bottom:8px;height:3px;background:linear-gradient(90deg,#4caf50,#4caf50 calc(100% - 60px),#0c6878);transition:width .3s}
.wz-step{position:relative;text-align:center;flex:1;font-size:12.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#4b5563;padding-bottom:28px}
.wz-step:first-child{text-align:left}.wz-step:last-child{text-align:right}
.wz-step i{position:absolute;bottom:0;left:50%;transform:translateX(-50%);width:20px;height:20px;border-radius:50%;background:#d1d9e0;border:3px solid #f0f2f5;box-sizing:border-box;z-index:1}
.wz-step:first-child i{left:0;transform:none;display:none}.wz-step:last-child i{left:auto;right:0;transform:none}
.wz-step.done i{background:#4caf50}.wz-step.cur{color:#0c6878}.wz-step.cur i{background:#0c6878;box-shadow:0 0 0 3px #d4ecef}
.wz-card{background:#fff;border-radius:4px;box-shadow:0 1px 3px rgba(0,0,0,.06)}
.wz-body{padding:40px 40px 20px}
.wz-body h1{text-align:center;font-size:30px;margin:0 0 8px;color:#111827}
.wz-body .sub{text-align:center;color:#555;font-size:15px;margin:0 0 30px}
.wz-row{display:grid;grid-template-columns:190px minmax(0,1fr);gap:24px;padding:22px 0;border-top:1px solid #e9edf1}
.wz-l{font-weight:700;font-size:14px;padding-top:6px}
.wz-opt{display:flex;gap:14px;align-items:flex-start;border:1px solid #dde3e9;border-radius:4px;padding:20px 20px;margin:0 auto 14px;max-width:770px;cursor:pointer;background:#f8f9fa}
.wz-opt:has(input:checked){border:2px solid #0c6878;background:#f8fbfc;padding:19px}
.wz-opt input{margin-top:4px;width:18px;height:18px;accent-color:#0c6878}
.wz-opt b{display:block;font-size:17px;color:#111827;margin-bottom:6px}.wz-opt span{color:#555;font-size:14.5px;line-height:1.55}
.wz-note{max-width:770px;margin:6px auto 0;font-size:14px;color:#555}.wz-note em{font-style:normal;background:#fde68a;color:#78350f;font-weight:700;padding:2px 6px;border-radius:3px;margin-right:6px}
.wz-start{display:flex;justify-content:center;margin:34px 0 10px}
.wz-start button{border:0;background:#0c6878;color:#fff;font-size:22px;font-weight:600;padding:20px 46px;border-radius:99px;box-shadow:0 0 0 8px #d4ecef;cursor:pointer}
.wz-foot{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:20px 40px;border-top:1px solid #e9edf1;background:#fafbfc;border-radius:0 0 4px 4px}
.wz-foot .rm-btn{font-size:16px;padding:11px 28px}
.wz-back{display:block;text-align:center;margin:26px 0 0;color:#555;text-decoration:none;border-bottom:1px dotted #888;width:max-content;margin-left:auto;margin-right:auto;font-size:14px}
.wz-info{background:#f1f5f9;padding:14px 16px;border-radius:4px;font-size:14.5px;color:#334155;margin:0 0 6px}
.wz-checks label{display:flex;gap:8px;align-items:center;font-size:14.5px;margin:0 0 8px;cursor:pointer}.wz-checks input{width:16px;height:16px;accent-color:#0c6878}
.wz-ready{text-align:center;padding:10px 0 20px}.wz-ready h1{display:flex;gap:12px;align-items:center;justify-content:center}.wz-ready h1 i{color:#4caf50}
.wz-ready-grid{display:grid;grid-template-columns:1fr 1fr;gap:30px;align-items:center;max-width:720px;margin:24px auto 0;text-align:left}
.wz-ready-grid ul{list-style:none;margin:0;padding:0}.wz-ready-grid li{margin:0 0 14px;font-size:16px}.wz-ready-grid li i{width:22px;color:#64748b}.wz-ready-grid a{color:#0c6878;text-decoration:none}
.wz-gauge{--p:0;width:200px;height:100px;border-radius:200px 200px 0 0;background:conic-gradient(from -90deg at 50% 100%,#f05a2a 0 36deg,#fdd000 0 72deg,#a8c626 0 120deg,#4caf50 0 180deg,transparent 0);position:relative;margin:0 auto}
.wz-gauge::after{content:"";position:absolute;left:50%;bottom:0;width:110px;height:55px;border-radius:110px 110px 0 0;background:#fff;transform:translateX(-50%)}
.wz-gauge-lbl{text-align:center;font-weight:800;color:#475569;margin-top:10px;letter-spacing:.04em}
@media (max-width:700px){.wz-body{padding:26px 18px 10px}.wz-row{grid-template-columns:1fr;gap:8px}.wz-foot{padding:16px 18px}.wz-step{font-size:0;padding-bottom:22px}.wz-ready-grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="wz">
  <div class="wz-logo"><?php if ($logo): ?><img src="<?= $esc($logo) ?>" alt="<?= $esc(edu_site_name()) ?>"><?php else: ?><b><?= $esc(edu_site_name()) ?> <span>SEO</span></b><?php endif; ?></div>
  <div class="wz-steps"><span class="bar" style="width:<?= $cur === 0 ? 0 : round($cur / (count($steps) - 1) * 100) ?>%"></span>
    <?php $i = 0; foreach ($steps as $k => $l): ?><div class="wz-step<?= $i < $cur ? ' done' : ($i === $cur ? ' cur' : '') ?>"><?= $isAdv && $i === 0 ? '' : '' ?><?= $esc($l) ?><i></i></div><?php $i++; endforeach; ?>
  </div>
  <form class="wz-card" method="POST"><?= csrf_field() ?>
  <?php if ($step === 'start'): $lvl = seo_opt('mode') === 'original' ? 'original' : (string)seo_opt('wizard_level'); ?>
    <div class="wz-body" style="padding-top:50px">
      <label class="wz-opt"><input type="radio" name="level" value="easy" <?= $lvl === 'easy' ? 'checked' : '' ?>><span><b>Easy</b>For websites where you only want to change the basics and let the SEO do most of the heavy lifting. Most settings are set to default as per industry best practices. One just has to set it and forget it.</span></label>
      <label class="wz-opt"><input type="radio" name="level" value="advanced" <?= $lvl === 'advanced' ? 'checked' : '' ?>><span><b>Advanced</b>For the advanced users who want to control every SEO aspect of the website. You are offered options to change everything and have full control over the website’s SEO.</span></label>
      <label class="wz-opt"><input type="radio" name="level" value="original" <?= $lvl === 'original' ? 'checked' : '' ?>><span><b>Basic SEO</b>Keep exactly the site’s original SEO — its own titles, descriptions, structured data and /sitemap.xml. None of the settings here are used.</span></label>
      <p class="wz-note"><em>Note</em>You can easily switch between modes at any point.</p>
      <div class="wz-start"><button type="submit">Start Wizard <i class="fas fa-chevron-right" style="font-size:16px;margin-left:6px"></i></button></div>
    </div>
  <?php elseif ($step === 'ready'): ?>
    <div class="wz-body wz-ready">
      <h1><i class="fas fa-circle-check"></i> Your site is ready!</h1>
      <div class="wz-ready-grid">
        <div><div class="wz-gauge"></div><div class="wz-gauge-lbl">OPTIMIZE YOUR POSTS!</div><div style="text-align:center;margin-top:12px"><a class="rm-btn primary" href="/admin/blogs-manager.php" style="background:#f59e0b;border-color:#f59e0b;font-weight:800">CLICK HERE</a></div></div>
        <ul><li><b>Learn more</b></li><li><i class="fas fa-sitemap"></i> <a href="<?= $esc(rtrim(SITE_URL, '/')) ?>/<?= edu_seo_advanced() ? 'sitemap_index.xml' : 'sitemap.xml' ?>" target="_blank">View your sitemap</a></li><li><i class="fab fa-google"></i> <a href="https://search.google.com/search-console" target="_blank" rel="noopener">Open Google Search Console</a></li><li><i class="fas fa-magnifying-glass-chart"></i> <a href="/admin/seo.php?view=analyzer">Run the SEO Analyzer</a></li><li><i class="fas fa-bolt"></i> <a href="/admin/seo.php?view=indexing">Submit URLs for instant indexing</a></li></ul>
      </div>
    </div>
    <div class="wz-foot"><span style="display:flex;gap:10px;flex-wrap:wrap"><a class="rm-btn" href="/admin/seo.php">Return to dashboard</a><a class="rm-btn" href="/admin/seo.php?view=analyzer">Proceed to SEO Analyzer</a></span><a class="rm-btn primary" href="/admin/seo-wizard.php?step=role">Setup Advanced Options</a></div>
  <?php else: ?>
    <div class="wz-body">
    <?php if ($step === 'yoursite'): ?>
      <h1>Your Website: <?= $esc(edu_site_name()) ?></h1><p class="sub">Let us know a few things about your site…</p>
      <div class="wz-row"><div class="wz-l">My Website is a…</div><div><select class="rm-in" name="site_type"><?php foreach (['news' => 'Community Blog/News Site', 'education' => 'Jobs & Education Updates', 'blog' => 'Personal Blog', 'portfolio' => 'Personal Portfolio', 'business' => 'Small Business Site', 'otherpersonal' => 'Other Personal Website', 'otherbusiness' => 'Other Business Website'] as $k => $l): ?><option value="<?= $k ?>"<?= seo_opt('site_type') === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div></div>
      <div class="wz-row"><div class="wz-l">Business Type</div><div><select class="rm-in" name="org_type"><?php foreach (['Organization' => 'Organization', 'NewsMediaOrganization' => 'News Media Organization', 'EducationalOrganization' => 'Educational Organization', 'LocalBusiness' => 'Local Business', 'Person' => 'Person'] as $k => $l): ?><option value="<?= $k ?>"<?= seo_opt('org_type') === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select><p class="rm-desc">Select the type that best describes your business. If you can't find one that applies exactly, use the generic "Organization".</p></div></div>
      <div class="wz-row"><div class="wz-l">Website Name</div><div><input class="rm-in" name="site_name" value="<?= $o('site_name') ?: $esc(edu_site_name()) ?>"><p class="rm-desc">Enter the name of your site to appear in search results.</p></div></div>
      <div class="wz-row"><div class="wz-l">Website Alternate Name</div><div><input class="rm-in" name="site_alt_name" value="<?= $o('site_alt_name') ?>"><p class="rm-desc">An alternate version of your site name (for example, an acronym or shorter name).</p></div></div>
      <div class="wz-row"><div class="wz-l">Person/Organization Name</div><div><input class="rm-in" name="org_name" value="<?= $o('org_name') ?: $esc(edu_site_name()) ?>"><p class="rm-desc">Your name or company name intended to feature in Google's Knowledge Panel.</p></div></div>
      <?php foreach (['org_logo' => ['Logo for Google', '<b>Min Size: 112X112px.</b><br>A squared image is preferred by the search engines.'], 'og_image' => ['Default Social Share Image', 'When a featured image or an OpenGraph Image is not set for individual posts/pages, this image will be used as a fallback thumbnail when your post is shared on Facebook. <b>The recommended image size is 1200 x 630 pixels.</b>']] as $k => [$l, $d]): $v = (string)seo_opt($k); ?>
      <div class="wz-row"><div class="wz-l"><?= $l ?></div><div><div class="rm-img"><img data-prev="<?= $k ?>" src="<?= $esc($v ? edu_seo_abs($v) : '') ?>" alt=""<?= $v ? '' : ' hidden' ?>><input type="hidden" name="<?= $k ?>" value="<?= $esc($v) ?>"><button type="button" class="rm-btn" data-pick="<?= $k ?>">Add or Upload File</button><button type="button" class="rm-btn danger" data-clear="<?= $k ?>"<?= $v ? '' : ' hidden' ?>>Remove</button></div><p class="rm-desc"><?= $d ?></p></div></div>
      <?php endforeach; ?>
    <?php elseif ($step === 'analytics'): ?>
      <h1>Connect Google™ Services</h1><p class="sub">Verify your site in Google Search Console and Bing so you can see searches and submit your sitemap.</p>
      <div class="wz-info"><b>How:</b> open <a href="https://search.google.com/search-console" target="_blank" rel="noopener">Google Search Console</a> → Add property → URL prefix <b><?= $esc(rtrim(SITE_URL, '/')) ?>/</b> → choose “HTML tag” and paste the code below. Then open <b>Sitemaps</b> there and submit <b><?= $esc(rtrim(SITE_URL, '/')) ?>/sitemap_index.xml</b>.</div>
      <div class="wz-row"><div class="wz-l">Google Search Console</div><div><input class="rm-in" name="v_google" value="<?= $o('v_google') ?>" placeholder="Verification code or the whole &lt;meta&gt; tag"></div></div>
      <div class="wz-row"><div class="wz-l">Bing Webmaster Tools</div><div><input class="rm-in" name="v_bing" value="<?= $o('v_bing') ?>" placeholder="Verification code or the whole &lt;meta&gt; tag"><p class="rm-desc">Bing also feeds DuckDuckGo, Yahoo and ChatGPT search.</p></div></div>
    <?php elseif ($step === 'sitemaps'): ?>
      <h1>Sitemap</h1><p class="sub">Choose your Sitemap configuration and select which type of posts or pages you want to include in your Sitemaps.</p>
      <?= $sw('m_sitemap', 'Sitemaps', 'XML Sitemaps help search engines index your website’s content more effectively.') ?>
      <?= $sw('sm_images', 'Include Images', 'Include reference to images from the post content in sitemaps. This helps search engines index your images better.') ?>
      <div class="wz-row"><div class="wz-l">Public Post Types</div><div class="wz-checks"><label><input type="checkbox" name="sm_posts" value="1" <?= $on('sm_posts') ?>> Posts</label><label><input type="checkbox" name="sm_pages" value="1" <?= $on('sm_pages') ?>> Pages</label><p class="rm-desc">Select post types to include in the sitemap.</p></div></div>
      <div class="wz-row"><div class="wz-l">Public Taxonomies</div><div class="wz-checks"><label><input type="checkbox" name="sm_categories" value="1" <?= $on('sm_categories') ?>> Categories</label><label><input type="checkbox" name="sm_tags" value="1" <?= $on('sm_tags') ?>> Tags</label><p class="rm-desc">Select taxonomies to include in the sitemap.</p></div></div>
      <h1 style="margin-top:34px">News Sitemap</h1><p class="sub">News Sitemaps allow you to control which content you submit to Google News.</p>
      <?= $sw('m_news_sitemap', 'News Sitemaps', 'You will generally only need a News Sitemap when your website is included in Google News.') ?>
      <div class="wz-row"><div class="wz-l">Google News Publication Name</div><div><input class="rm-in" name="news_name" value="<?= $o('news_name') ?>" placeholder="<?= $esc(edu_site_name()) ?>"><p class="rm-desc">The name of the news publication. It must match the name exactly as it appears on your articles in news.google.com.</p></div></div>
    <?php elseif ($step === 'optimization'): ?>
      <h1>SEO Tweaks</h1><p class="sub">Automate some of your SEO tasks like making external links nofollow, opening them in a new tab, etc.</p>
      <?= $sw('noindex_empty', 'Noindex Empty Category and Tag Archives', 'Setting empty archives to <code>noindex</code> is useful for avoiding indexation of thin content pages and dilution of page rank. As soon as a post is added, the page is updated to <code>index</code>.') ?>
      <?= $sw('nofollow_external', 'Nofollow External Links', 'Automatically add <code>rel="nofollow"</code> attribute for external links appearing in your posts and pages. The attribute is applied when the content is displayed, and the stored content is not changed.') ?>
      <?= $sw('external_blank', 'Open External Links in New Tab/Window', 'Automatically add a <code>target="_blank"</code> attribute to external links appearing in your posts and pages. Applied when the content is displayed.') ?>
    <?php elseif ($step === 'role'): ?>
      <h1>Role Manager</h1><p class="sub">Set capabilities here.</p>
      <?= $sw('m_role_manager', 'Role Manager', 'The Role Manager allows you to use roles to control which of your site users can change SEO details in the post editor.') ?>
      <?= $sw('role_editor_seo', 'Editor', 'Editors get the SEO box (title, description, focus keyword, schema, social) in the post editor.') ?>
      <?= $sw('role_author_seo', 'Author', 'Authors get the SEO box for their own posts.') ?>
    <?php elseif ($step === 'redirection'): ?>
      <h1>404 Monitor</h1><p class="sub">Set default values for the 404 error monitor here.</p>
      <?= $sw('m_404', '404 Monitor', 'The 404 monitor will let you see if visitors or search engines bump into any 404 Not Found error while browsing your site.') ?>
      <h1 style="margin-top:30px">Redirections</h1><p class="sub">Set default values for the redirection module from here.</p>
      <?= $sw('m_redirections', 'Redirections', 'Set up temporary or permanent redirections. Combined with the 404 monitor, you can easily redirect faulty URLs on your site, or add custom redirections.') ?>
    <?php elseif ($step === 'schema'): ?>
      <h1>Schema Markup</h1><p class="sub">Schema adds metadata to your website, resulting in rich search results and more traffic.</p>
      <?= $sw('m_schema', 'Schema Type', 'Use automatic structured data to mark up content, to help Google better understand your content’s context for display in Search.') ?>
      <div class="wz-row"><div class="wz-l">Schema Type for Posts</div><div><div class="rm-seg"><?php foreach (['Article' => 'Article', 'BlogPosting' => 'Blog Post', 'NewsArticle' => 'News Article'] as $k => $l): ?><label><input type="radio" name="schema_default" value="<?= $k ?>"<?= seo_opt('schema_default') === $k ? ' checked' : '' ?>><?= $l ?></label><?php endforeach; ?></div><p class="rm-desc">Default rich snippet selected when creating a new post. Each post can change it in the editor.</p></div></div>
    <?php endif; ?>
    </div>
    <div class="wz-foot"><button type="submit" name="skip" value="1" class="rm-btn" formnovalidate>Skip Step</button><button type="submit" class="rm-btn primary">Save and Continue</button></div>
  <?php endif; ?>
  </form>
  <a class="wz-back" href="/admin/seo.php<?= seo_opt('wizard_done') === '1' ? '' : '?skip=1' ?>">Return to dashboard</a>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('[data-pick]').forEach(function (b) { b.onclick = function () { var k = b.dataset.pick; EduMedia.pick(function (it) { document.querySelector('[name="' + k + '"]').value = it.path; var im = document.querySelector('[data-prev="' + k + '"]'); im.src = it.path; im.hidden = false; document.querySelector('[data-clear="' + k + '"]').hidden = false; }); }; });
  document.querySelectorAll('[data-clear]').forEach(function (b) { b.onclick = function () { var k = b.dataset.clear; document.querySelector('[name="' + k + '"]').value = ''; document.querySelector('[data-prev="' + k + '"]').hidden = true; b.hidden = true; }; });
});
</script>
</body>
</html>
