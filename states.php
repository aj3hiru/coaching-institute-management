<?php
require_once __DIR__ . '/includes/redirects.php';
edu_redirect_check();
require_once __DIR__ . '/includes/config.php';
require_once INCLUDES_PATH . '/minify.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/jobcard.php';



$cache_key = 'all_states_list_sorted';
$states = false;

if (function_exists('apcu_fetch') && apcu_enabled()) {
    $states = apcu_fetch($cache_key, $success);
    if (!$success) $states = false;
}

if ($states === false) {
    $stmt = $pdo->query("SELECT * FROM states ORDER BY views DESC, state_name ASC");
    $states = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (function_exists('apcu_store') && apcu_enabled()) {
        apcu_store($cache_key, $states, 86400);
    }
}



// Published jobs per state, and the state code shown on each card
$stateJobs = $pdo->query("SELECT state_id, COUNT(*) n FROM posts WHERE status = 'published' AND state_id IS NOT NULL GROUP BY state_id")->fetchAll(PDO::FETCH_KEY_PAIR);
$stateCodes = ['andhra pradesh' => 'AP', 'arunachal pradesh' => 'AR', 'assam' => 'AS', 'bihar' => 'BR', 'chhattisgarh' => 'CG', 'goa' => 'GA', 'gujarat' => 'GJ',
    'haryana' => 'HR', 'himachal pradesh' => 'HP', 'jharkhand' => 'JH', 'karnataka' => 'KA', 'kerala' => 'KL', 'madhya pradesh' => 'MP', 'maharashtra' => 'MH',
    'manipur' => 'MN', 'meghalaya' => 'ML', 'mizoram' => 'MZ', 'nagaland' => 'NL', 'odisha' => 'OD', 'punjab' => 'PB', 'rajasthan' => 'RJ', 'sikkim' => 'SK',
    'tamil nadu' => 'TN', 'telangana' => 'TS', 'tripura' => 'TR', 'uttar pradesh' => 'UP', 'uttarakhand' => 'UK', 'west bengal' => 'WB',
    'andaman and nicobar islands' => 'AN', 'chandigarh' => 'CH', 'dadra and nagar haveli and daman and diu' => 'DN', 'delhi' => 'DL',
    'jammu and kashmir' => 'JK', 'ladakh' => 'LA', 'lakshadweep' => 'LD', 'puducherry' => 'PY'];
$uts = ['andaman and nicobar islands', 'chandigarh', 'dadra and nagar haveli and daman and diu', 'delhi', 'jammu and kashmir', 'ladakh', 'lakshadweep', 'puducherry'];
$byName = $states;
usort($byName, fn($x, $y) => strcasecmp($x['state_name'], $y['state_name']));
$groups = ['States' => [], 'Union Territories' => []];
foreach ($byName as $st) $groups[in_array(strtolower(trim($st['state_name'])), $uts, true) ? 'Union Territories' : 'States'][] = $st;
$stateCode = function (string $name) use ($stateCodes): string {
    $k = strtolower(trim($name));
    if (isset($stateCodes[$k])) return $stateCodes[$k];
    $w = preg_split('/\s+/', preg_replace('/\b(and|of)\b/i', '', $name), -1, PREG_SPLIT_NO_EMPTY);
    return strtoupper(count($w) > 1 ? mb_substr($w[0], 0, 1) . mb_substr($w[1], 0, 1) : mb_substr($name, 0, 2));
};
$tones = ['#0c6878', '#2563eb', '#7c3aed', '#db2777', '#dc2626', '#d97706', '#059669', '#0891b2'];


$seo = [
    'title'       => 'State Wise Government Jobs 2026 - Find Sarkari Naukri by State | ' . edu_site_name(),
    'description' => 'Find latest government jobs 2026 by state. Browse Sarkari Naukri notifications for Rajasthan, UP, Bihar, Maharashtra, Delhi and all other states in India.',
    'image'       => SEO_DEFAULT_IMAGE,
    'type'        => SEO_DEFAULT_TYPE,
    'robots'      => SEO_DEFAULT_ROBOTS,
];
require_once INCLUDES_PATH . '/seo.php';
require_once INCLUDES_PATH . '/ads.php';
$seo = edu_seo_apply($seo, []);
$seo_ctx = ['kind' => 'other'];



$faqs = [

    [
        'question'    => 'How to find government jobs in my state?',
        'answer_html' => 'Simply click on your state name from the list above. You will be directed to a dedicated page listing all active recruitments, exam notifications, and upcoming vacancies for that specific state.',
    ],

    [
        'question'    => 'Does this list cover all states in India?',
        'answer_html' => 'Yes, we provide government job updates for all 28 states and 8 Union Territories of India, including Rajasthan, Uttar Pradesh, Bihar, Maharashtra, Delhi, and others.',
    ],

    [
        'question'    => 'Are these jobs updated daily?',
        'answer_html' => 'Yes, our team monitors official state public service commission websites (like RPSC, UPPSC, BPSC) and other departmental sites daily to provide the latest free job alerts.',
    ],

    [
        'question'    => 'Can 10th and 12th pass students apply for state jobs?',
        'answer_html' => 'Yes, many state government vacancies like Police Constable, Clerk (LDC), Forest Guard, and Group D posts are specifically released for 10th and 12th pass candidates.',
    ],

    [
        'question'    => 'Is registration required to view these jobs?',
        'answer_html' => 'No, you do not need to register or pay any fee to view these job alerts. Our platform is completely free for all students looking for Sarkari Naukri updates.',
    ],

];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(edu_shortcodes((string)$seo['title'], true), ENT_QUOTES, 'UTF-8') ?></title>
    
    <?php require_once ROOT_PATH . '/components/head_script.php'; ?>

    <style>
    .sx-lead{margin:0 0 16px;color:#4b5563;font-size:15.5px;line-height:1.6;max-width:820px}
    .sx-search{position:relative;display:block;max-width:520px;margin:0 0 6px}
    .sx-search input{width:100%;height:48px;padding:0 16px 0 44px;border:1px solid #d1d5db;border-radius:12px;font:inherit;font-size:15px;background:#fff;color:#111827;box-sizing:border-box;transition:border-color .15s,box-shadow .15s}
    .sx-search input:focus{outline:none;border-color:var(--gc-accent,#0c6878);box-shadow:0 0 0 4px rgba(12,104,120,.12)}
    .sx-search svg{position:absolute;left:15px;top:50%;width:18px;height:18px;transform:translateY(-50%);fill:#9ca3af}
    .sx-h2{display:flex;align-items:center;gap:8px;margin:26px 0 12px;font-size:20px;font-weight:800;color:#111827}
    .sx-h2 small{font-size:13px;font-weight:600;color:#6b7280;background:#f3f4f6;border-radius:99px;padding:2px 9px}
    .sx-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:12px}
    .sx-card{display:flex;align-items:center;gap:12px;padding:12px 14px;border:1px solid #e5e7eb;border-radius:14px;background:#fff;color:#111827 !important;transition:border-color .15s,box-shadow .15s,transform .15s}
    .sx-card:hover{border-color:var(--tone);box-shadow:0 8px 22px rgba(16,24,40,.08);transform:translateY(-2px)}
    .sx-code{flex-shrink:0;width:44px;height:44px;border-radius:12px;display:grid;place-items:center;font-size:14px;font-weight:800;letter-spacing:.02em;color:var(--tone);background:color-mix(in srgb,var(--tone) 11%,#fff)}
    .sx-body{min-width:0;flex:1}
    .sx-name{display:block;margin:0;font-size:15.5px;font-weight:700;line-height:1.3;color:#111827}
    .sx-meta{display:block;margin-top:2px;font-size:12.5px;color:#6b7280}
    .sx-meta b{color:var(--tone)}
    .sx-go{width:18px;height:18px;flex-shrink:0;color:#c0c4cc;transition:transform .15s,color .15s}
    .sx-card:hover .sx-go{color:var(--tone);transform:translateX(2px)}
    .sx-wrap:not(.sx-all):not(.sx-searching) .sx-extra,.sx-wrap:not(.sx-all):not(.sx-searching) .sx-gextra{display:none}
    .sx-morebar{display:flex;justify-content:center;margin:18px 0 4px}
    .sx-more{display:inline-flex;align-items:center;gap:6px;padding:11px 22px;border:1px solid var(--gc-accent,#0c6878);border-radius:99px;background:#fff;color:var(--gc-accent,#0c6878);font:inherit;font-size:15px;font-weight:700;cursor:pointer;transition:background .15s,color .15s}
    .sx-more:hover{background:var(--gc-accent,#0c6878);color:#fff}.sx-more span{font-weight:500;opacity:.8}
    .sx-none{display:none;padding:16px;border:1px dashed #d1d5db;border-radius:12px;color:#6b7280;text-align:center;margin-top:16px}
    @media (max-width:600px){.sx-grid{grid-template-columns:1fr 1fr;gap:10px}.sx-card{flex-direction:column;align-items:flex-start;gap:8px;padding:12px}.sx-go{display:none}.sx-name{font-size:14.5px}.sx-lead{font-size:15px}}
    [data-theme="dark"] .sx-card{background:#151b23;border-color:#263040;color:#e5e7eb !important}[data-theme="dark"] .sx-name,[data-theme="dark"] .sx-h2{color:#e5e7eb}[data-theme="dark"] .sx-search input{background:#151b23;border-color:#263040;color:#e5e7eb}[data-theme="dark"] .sx-code{background:#1f2733}
    </style>

<!-- BREADCRUMBS SCHEMA -->    
    <script type="application/ld+json"><?= json_encode([
        '@context' => 'https://schema.org',
        '@graph' => [
            ['@type' => 'BreadcrumbList', 'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => SITE_URL . '/'],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'State Wise Jobs', 'item' => SITE_URL . '/state-jobs'],
            ]],
            ['@type' => 'CollectionPage', 'name' => (string)$seo['title'], 'description' => (string)$seo['description'], 'url' => SITE_URL . '/state-jobs',
                'hasPart' => array_map(fn($st) => array_filter(['@type' => 'WebPage', 'name' => $st['state_name'] . ' Government Jobs', 'url' => SITE_URL . '/state-jobs/' . $st['slug'],
                    'description' => trim(preg_replace('/\s+/u', ' ', strip_tags((string)$st['description'])))]), $states)],
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>

</head>
<body>
	
    <?php require_once ROOT_PATH . '/components/header.php'; ?>
    
    <main>
        <nav class="breadcrumbs" aria-label="Breadcrumb">
            <ol>
                <li><a href="/">Home</a></li>
                <li aria-current="page">State Wise Jobs</li>
            </ol>
        </nav>

        <div class="container">
             <h1 class="page-title">State Wise Government Jobs 2026 - Find Sarkari Naukri by State</h1>
    <?= edu_ads('before_content') ?>
                <p class="sx-lead">Explore latest Sarkari Naukri notifications, recruitment exams, and vacancy updates across all states and union territories of India. Pick your state to see its jobs.</p>
            <?php if (empty($states)): ?>
                <p>No states found.</p>
            <?php else: ?>
                <label class="sx-search"><span style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)">Find your state</span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 2a8 8 0 0 1 6.3 12.9l5.4 5.4-1.4 1.4-5.4-5.4A8 8 0 1 1 10 2zm0 2a6 6 0 1 0 0 12 6 6 0 0 0 0-12z"/></svg><input type="search" id="sx-q" placeholder="Search your state — e.g. Bihar, Rajasthan, Delhi" autocomplete="off"></label>
                <?php $ti = 0; $SHOW = 12; $totalStates = array_sum(array_map('count', $groups)); ?>
                <div class="sx-wrap" id="sx-wrap">
                <?php foreach ($groups as $gName => $list): if (!$list) continue; ?>
                <section class="sx-group<?= $ti >= $SHOW ? ' sx-gextra' : '' ?>" data-group>
                  <h2 class="sx-h2"><?= $gName ?> <small><?= count($list) ?></small></h2>
                  <div class="sx-grid">
                    <?php foreach ($list as $state): $n = (int)($stateJobs[$state['id']] ?? 0); $extra = $ti >= $SHOW; $tone = $tones[$ti++ % count($tones)]; ?>
                    <a class="sx-card<?= $extra ? ' sx-extra' : '' ?>" href="/state-jobs/<?= htmlspecialchars($state['slug']) ?>" style="--tone:<?= $tone ?>" data-name="<?= htmlspecialchars(mb_strtolower($state['state_name'])) ?>">
                      <span class="sx-code" aria-hidden="true"><?= htmlspecialchars($stateCode($state['state_name'])) ?></span>
                      <span class="sx-body"><span class="sx-name"><?= htmlspecialchars($state['state_name']) ?></span><span class="sx-meta"><?= $n ? '<b>' . number_format($n) . '</b> ' . ($n === 1 ? 'job' : 'jobs') : 'Govt jobs' ?></span></span>
                      <svg class="sx-go" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </a>
                    <?php endforeach; ?>
                  </div>
                </section>
                <?php endforeach; ?>
                </div>
                <?php if ($totalStates > $SHOW): ?><div class="sx-morebar"><button type="button" class="sx-more" id="sx-more" aria-expanded="false">See more states <span>(<?= $totalStates - $SHOW ?>)</span></button></div><?php endif; ?>
                <p class="sx-none" id="sx-none">No state matches your search.</p>
<?php $latestJobs = $pdo->query("SELECT p.id, p.title, p.slug, p.date FROM posts p JOIN post_meta jm ON jm.post_id = p.id AND jm.meta_key = 'job_card' AND jm.meta_value = '1' WHERE p.status = 'published' ORDER BY p.date DESC LIMIT 12")->fetchAll(PDO::FETCH_ASSOC); if ($latestJobs): ?>
<section class="jl-latest" aria-labelledby="jl-h">
  <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin:30px 0 10px"><h2 id="jl-h" style="margin:0;font-size:20px;font-weight:800">Latest jobs</h2><a href="/latest-updates" style="font-weight:600;font-size:14.5px;color:var(--gc-accent,#0c6878)">View all ›</a></div>
  <?= edu_job_cards($pdo, $latestJobs) ?>
</section>
<?php endif; ?>

                <script>
                (function () {
                  var q = document.getElementById('sx-q'); if (!q) return;
                  q.addEventListener('input', function () {
                    var t = q.value.trim().toLowerCase(), all = 0, wrap = document.getElementById('sx-wrap'), mb = document.querySelector('.sx-morebar');
                    wrap.classList.toggle('sx-searching', !!t); if (mb) mb.style.display = t ? 'none' : '';
                    document.querySelectorAll('[data-group]').forEach(function (g) {
                      var shown = 0;
                      g.querySelectorAll('[data-name]').forEach(function (el) { var on = !t || el.getAttribute('data-name').indexOf(t) >= 0; el.style.display = on ? '' : 'none'; if (on) shown++; });
                      g.style.display = shown ? '' : 'none'; all += shown;
                    });
                    document.getElementById('sx-none').style.display = all ? 'none' : 'block';
                  });
                  var more = document.getElementById('sx-more');
                  if (more) more.addEventListener('click', function () {
                    var wrap = document.getElementById('sx-wrap'), open = !wrap.classList.contains('sx-all');
                    wrap.classList.toggle('sx-all', open); more.setAttribute('aria-expanded', open ? 'true' : 'false');
                    more.innerHTML = open ? 'Show fewer states' : 'See more states <span>(<?= max(0, ($totalStates ?? 0) - 12) ?>)</span>';
                    if (!open) document.getElementById('sx-wrap').scrollIntoView({ block: 'start', behavior: 'smooth' });
                  });
                })();
                </script>
            <?php endif; ?>
        </div>

<?php require_once ROOT_PATH . '/components/faq.php'; ?>
    
    <?= edu_ads('after_content') ?>
</main>
    
    <?php require_once ROOT_PATH . '/components/footer.php'; ?>
    
</body>
</html>
<?php ob_end_flush(); ?>