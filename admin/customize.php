<?php
// Customize — the theme editor with live preview (same layout and controls as the new CMS Customizer).
// Changes go to a draft in the admin's session (the preview shows it via ?czp=1); "Publish" saves them for everyone.
require_once __DIR__ . '/components/guard.php';
require_once INCLUDES_PATH . '/theme.php';
admin_guard(['admin']);

$identityKeys = ['site_title', 'tagline', 'logo', 'logo_w', 'header_logo', 'favicon'];

// Lookups for the Menus "Add Items" panel and the Also Read post picker
if ($_SERVER['REQUEST_METHOD'] === 'GET' && (isset($_GET['src']) || isset($_GET['posts_brief']) || isset($_GET['titles']))) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $q = trim((string)($_GET['q'] ?? $_GET['posts_brief'] ?? ''));
    $like = '%' . $q . '%';
    try {
        if (isset($_GET['titles'])) {
            $ids = array_slice(array_values(array_filter(array_map('intval', explode(',', (string)$_GET['titles'])))), 0, 50);
            $out = [];
            if ($ids) foreach ($pdo->query("SELECT id, title FROM posts WHERE id IN (" . implode(',', $ids) . ")") as $r) $out[(int)$r['id']] = $r['title'];
            echo json_encode((object)$out, JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (isset($_GET['posts_brief'])) {
            $st = $pdo->prepare("SELECT id, title FROM posts WHERE status = 'published'" . ($q !== '' ? " AND title LIKE ?" : '') . " ORDER BY date DESC LIMIT 20");
            $st->execute($q !== '' ? [$like] : []);
            echo json_encode(array_map(fn($r) => ['id' => (int)$r['id'], 'title' => $r['title']], $st->fetchAll(PDO::FETCH_ASSOC)), JSON_UNESCAPED_UNICODE);
            exit;
        }
        $out = [];
        $src = (string)$_GET['src'];
        if ($src === 'pages') {
            if ($q === '' || stripos('home front page', $q) !== false) $out[] = ['label' => 'Home', 'url' => '/', 'kind' => 'Front Page'];
            $st = $pdo->prepare("SELECT title, slug FROM site_pages WHERE status = 'published'" . ($q !== '' ? " AND title LIKE ?" : '') . " ORDER BY title LIMIT 40");
            $st->execute($q !== '' ? [$like] : []);
            foreach ($st as $r) $out[] = ['label' => $r['title'], 'url' => '/' . $r['slug'], 'kind' => 'Page'];
            foreach ([['Job Categories', '/categories'], ['State Wise Jobs', '/state-jobs'], ['Latest Updates', '/latest-updates']] as [$l, $u]) {
                if ($q === '' || stripos($l, $q) !== false) $out[] = ['label' => $l, 'url' => $u, 'kind' => 'Archive'];
            }
        } elseif ($src === 'posts') {
            $st = $pdo->prepare("SELECT id, title, slug FROM posts WHERE status = 'published'" . ($q !== '' ? " AND title LIKE ?" : '') . " ORDER BY date DESC LIMIT 30");
            $st->execute($q !== '' ? [$like] : []);
            foreach ($st as $r) $out[] = ['label' => $r['title'], 'url' => postUrl($r['slug'], $r['id']), 'kind' => 'Post'];
        } elseif ($src === 'categories') {
            $st = $pdo->prepare("SELECT name, slug FROM categories" . ($q !== '' ? " WHERE name LIKE ?" : '') . " ORDER BY name LIMIT 60");
            $st->execute($q !== '' ? [$like] : []);
            foreach ($st as $r) $out[] = ['label' => $r['name'], 'url' => '/categories/' . $r['slug'], 'kind' => 'Category'];
        } elseif ($src === 'tags') {
            $st = $pdo->prepare("SELECT id, name, slug FROM tags" . ($q !== '' ? " WHERE name LIKE ?" : '') . " ORDER BY name LIMIT 60");
            $st->execute($q !== '' ? [$like] : []);
            foreach ($st as $r) $out[] = ['label' => $r['name'], 'url' => tagUrl($r['slug'], $r['id']), 'kind' => 'Tag'];
        }
        echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        http_response_code(500);
        echo '[]';
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    csrf_check();
    $action = $_POST['action'] ?? '';
    $data = json_decode((string)($_POST['data'] ?? '{}'), true) ?: [];
    $defaults = edu_theme_defaults();
    $theme = edu_theme_sanitize(array_intersect_key((array)($data['theme'] ?? []), $defaults));
    $general = array_intersect_key((array)($data['general'] ?? []), array_flip($identityKeys));
    foreach (['site_title' => 100, 'tagline' => 200] as $gk => $max) if (isset($general[$gk])) $general[$gk] = mb_substr(trim((string)$general[$gk]), 0, $max);
    if (isset($general['logo_w'])) $general['logo_w'] = max(20, min(600, (int)$general['logo_w']));

    if ($action === 'draft') {
        $_SESSION['edu_draft'] = ['theme' => $theme, 'general' => $general];
        echo json_encode(['ok' => true]);
        exit;
    }
    if ($action === 'publish') {
        $old = edu_settings('theme');
        $_SESSION['edu_theme_backup'] = ['at' => date('c'), 'theme' => $old];
        edu_settings_save('theme', $theme);
        if ($general) edu_settings_save('general', $general);
        unset($_SESSION['edu_draft']);
        admin_log('theme_publish', 'Published Customizer changes');
        echo json_encode(['ok' => true]);
        exit;
    }
    if ($action === 'reset') {
        $old = edu_settings('theme');
        edu_settings_save('theme_backup', ['at' => date('c'), 'theme' => $old]);
        $pdo->prepare("UPDATE app_config SET config_value = '{}' WHERE config_key = 'edu_theme'")->execute();
        edu_clear_page_cache();
        unset($_SESSION['edu_draft']);
        admin_log('theme_reset', 'Reset the Customizer design to default');
        echo json_encode(['ok' => true]);
        exit;
    }
    if ($action === 'undo_reset') {
        $b = edu_settings('theme_backup');
        if (empty($b['theme'])) { echo json_encode(['ok' => false, 'message' => 'Nothing to restore.']); exit; }
        $pdo->prepare("UPDATE app_config SET config_value = ? WHERE config_key = 'edu_theme'")->execute([json_encode($b['theme'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        edu_clear_page_cache();
        admin_log('theme_reset_undo', 'Restored the design from before the reset');
        echo json_encode(['ok' => true]);
        exit;
    }
    echo json_encode(['ok' => false]);
    exit;
}

unset($_SESSION['edu_draft']);
$theme = edu_theme();
$general = array_intersect_key(edu_settings('general'), array_flip($identityKeys)) + ['site_title' => '', 'tagline' => '', 'logo' => '', 'header_logo' => '1', 'favicon' => ''];
$general['logo_w'] = edu_logo_size()[0]; // until saved once: the size the logo has today
$backup = edu_settings('theme_backup');
$latest = $pdo->query("SELECT id, slug FROM posts WHERE status = 'published' ORDER BY date DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$latestUrl = $latest ? postUrl($latest['slug'], $latest['id']) : '/';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title>Customize - <?= htmlspecialchars(edu_site_name()) ?></title>
<?php admin_shell_head(); ?>
<link rel="stylesheet" href="/assets/css/customize.css?v=<?= @filemtime(ROOT_PATH . '/assets/css/customize.css') ?>">
<script src="/assets/js/admin-media.js?v=<?= @filemtime(ROOT_PATH . '/assets/js/admin-media.js') ?>" defer></script>
<style>
body{margin:0;overflow:hidden}
.cz-list-item{display:grid;grid-template-columns:1fr 1fr auto;gap:6px;margin-bottom:6px}
.cz-list-item input{min-width:0}
.cz-list-item .cz-li-btns{display:flex;gap:2px}
.cz-list-item button{border:1px solid #dcdcde;background:#fff;border-radius:4px;width:26px;height:30px;cursor:pointer;color:#50575e}
.cz-list-add{margin-top:4px}
.cz-media-box{border:1px dashed #c3c4c7;border-radius:6px;min-height:70px;display:flex;align-items:center;justify-content:center;background:#f6f7f7;overflow:hidden;margin-bottom:6px;color:#8c8f94;font-size:12px}
.cz-media-box img{max-width:100%;max-height:110px;display:block}
.cz-codearea{font:12px/1.5 ui-monospace,Menlo,monospace;min-height:220px}
</style>
</head>
<body>
<div class="cz-root" id="cz-root">
  <aside class="cz-panel">
    <div class="cz-top">
      <a class="cz-close" title="Close" href="/admin/dashboard.php"><i class="fas fa-xmark"></i></a>
      <span class="cz-status" id="cz-status">All changes published</span>
      <button type="button" class="cz-publish" id="cz-publish" disabled>Published</button>
    </div>
    <div class="cz-head">
      <button type="button" class="cz-back" id="cz-back" hidden aria-label="Back"><i class="fas fa-chevron-left"></i></button>
      <div><small id="cz-head-small">You are customizing</small><h1 id="cz-head-title"><?= htmlspecialchars(edu_site_name()) ?></h1></div>
    </div>
    <div class="cz-body" id="cz-body"></div>
    <div class="cz-footer">
      <button type="button" class="cz-hide" id="cz-hide"><i class="fas fa-circle-chevron-left"></i> Hide Controls</button>
      <span class="cz-devices" id="cz-devices">
        <button type="button" class="active" data-dev="d" title="Desktop"><i class="fas fa-desktop"></i><span>Desktop</span></button>
        <button type="button" data-dev="t" title="Tablet"><i class="fas fa-tablet-screen-button"></i><span>Tablet</span></button>
        <button type="button" data-dev="m" title="Mobile"><i class="fas fa-mobile-screen-button"></i><span>Mobile</span></button>
      </span>
    </div>
  </aside>
  <main class="cz-preview">
    <div class="cz-preview-bar">
      <span>Preview</span>
      <button type="button" data-path="/"><i class="fas fa-house"></i> Homepage</button>
      <button type="button" data-path="<?= htmlspecialchars($latestUrl) ?>"><i class="fas fa-file-lines"></i> Latest post</button>
      <button type="button" data-path="/categories"><i class="fas fa-folder"></i> Categories</button>
      <button type="button" id="cz-reload" title="Reload preview"><i class="fas fa-rotate-right"></i></button>
      <span class="cz-refreshing" id="cz-refreshing" hidden>Updating preview…</span>
      <a href="/" target="_blank" rel="noopener">Open site <i class="fas fa-arrow-up-right-from-square"></i></a>
    </div>
    <div class="cz-frame-wrap cz-dev-d" id="cz-wrap">
      <div class="cz-loading" id="cz-loading"><span class="cz-spinner"></span> Loading preview…</div>
      <iframe title="Site preview" id="cz-frame" class="is-active" src="/?czp=1"></iframe>
    </div>
  </main>
</div>
<button type="button" class="cz-show" id="cz-show" hidden><i class="fas fa-circle-chevron-right"></i> Show Controls</button>

<script>
const CSRF = <?= json_encode(csrf_token()) ?>;
const DEFAULTS = <?= json_encode(edu_theme_defaults(), JSON_UNESCAPED_UNICODE) ?>;
const state = { theme: <?= json_encode($theme, JSON_UNESCAPED_UNICODE) ?>, general: <?= json_encode($general, JSON_UNESCAPED_UNICODE) ?> };
const HAS_BACKUP = <?= !empty($backup['theme']) ? 'true' : 'false' ?>;
const BACKUP_AT = <?= json_encode($backup['at'] ?? '') ?>;
const FONTS = [['system', 'System font (fastest)'], ['Inter', 'Inter'], ['Roboto', 'Roboto'], ['Poppins', 'Poppins'], ['Noto Sans', 'Noto Sans (Hindi too)'], ['Mukta', 'Mukta (Hindi)'], ['Hind', 'Hind (Hindi)'], ['Open Sans', 'Open Sans'], ['Lato', 'Lato'], ['Merriweather', 'Merriweather (serif)'], ['Georgia', 'Georgia (serif)']];
const SITE_NAME_DEFAULT = <?= json_encode(SITE_NAME) ?>;
const COLOR_GROUPS = <?= json_encode(EDU_COLOR_GROUPS, JSON_UNESCAPED_UNICODE) ?>;
const TYPO_TARGETS = <?= json_encode(array_map(fn($x) => $x[0], EDU_TYPO_TARGETS), JSON_UNESCAPED_UNICODE) ?>;
const SHORTCODES = <?= json_encode(edu_shortcode_help(), JSON_UNESCAPED_UNICODE) ?>;

// --- panel definitions: [type, key, label, extra] ; keys starting with "g." edit General Settings (identity) ---
const PANELS = [
  { id: 'identity', icon: 'fa-id-card', label: 'Site Identity', sub: 'Title, tagline, logo, site icon', sections: [
    ['Site Title & Tagline', [
      ['text', 'g.site_title', 'Site Title', { placeholder: SITE_NAME_DEFAULT, hint: 'Empty = ' + SITE_NAME_DEFAULT + '. Used in the header, footer, page titles, feeds and [site_title].' }],
      ['toggle', 'id_hide_title', 'Hide site title', { hint: 'The logo is always shown; the title can show next to it.' }],
      ['text', 'g.tagline', 'Tagline', { hint: 'In a few words, explain what this site is about.' }],
      ['toggle', 'id_hide_tagline', 'Hide site tagline'],
    ]],
    ['Logo', [
      ['media', 'g.logo', 'Logo', { hint: 'Empty = the built-in EduMint logo.' }],
      ['toggle', 'g.header_logo', 'Show this logo in the header'],
      ['media', 'id_retina_logo', 'Retina Logo', { hint: 'Twice the size of the logo, for sharp display on high-resolution screens.' }],
      ['range', 'g.logo_w', 'Logo width', { min: 40, max: 400, unit: 'px', hint: 'The height follows the logo’s own shape.' }],
    ]],
    ['Header Brand', [
      ['select', 'id_brand_style', 'Header brand style', { options: [['logo', 'Logo (with title / tagline as set above)'], ['school', 'School style brand in this header: emblem + name + address on a tinted panel'], ['school_full', 'Full school layout: top bar + brand panel + menu beside it']] }],
      ['media', 'id_brand_emblem', 'Emblem (round logo)', { hint: 'School style only. Empty = the built-in DK Academy emblem. Square image works best.' }],
      ['text', 'id_header_title', 'Name in the header', { hint: 'School style only. Empty = the Site Title.' }],
      ['text', 'id_header_line', 'Line under the name', { hint: 'School style only, e.g. the address. Empty = the address in General Settings.' }],
      ['text', 'id_school_phone', 'Top bar: phone', { hint: 'School style only. Empty = the phone in General Settings.' }],
      ['text', 'id_school_wa', 'Top bar: WhatsApp number', { hint: 'Empty = the same phone.' }],
      ['text', 'id_school_bar_text', 'Top bar: text on the right', { hint: 'e.g. Admission Open 2026-27. Empty = none.' }],
      ['text', 'id_school_login_text', 'Top bar: login button text', { hint: 'Uses Header → Log in (on/off and its address).' }],
    ]],
    ['Site Icon', [
      ['media', 'g.favicon', 'Site Icon', { hint: 'The Site Icon is what you see in browser tabs, bookmark bars, and within mobile apps. It should be square and at least 512 × 512 pixels.' }],
    ]],
  ]},
  { id: 'colors', icon: 'fa-palette', label: 'Colors', sub: 'Global colors and every area of the site', render: renderColors },
  { id: 'typography', icon: 'fa-font', label: 'Typography', sub: 'Fonts, sizes, weights', render: renderTypography },
  { id: 'header', icon: 'fa-window-maximize', label: 'Header', sub: 'Search, bell, social icons, top bar', fields: [
    ['toggle', 'h_sticky', 'Sticky header'],
    ['toggle', 'h_search', 'Show search'],
    ['toggle', 'h_voice', 'Voice search'],
    ['text', 'h_search_placeholder', 'Placeholder'],
    ['toggle', 'h_login', 'Show “Log in”'],
    ['text', 'h_login_text', 'Log in text'],
    ['text', 'h_login_url', 'Log in link', { hint: 'Empty = the admin login page.' }],
    ['toggle', 'h_sub', 'Show “Subscribe”'],
    ['text', 'h_sub_text', 'Subscribe text'],
    ['text', 'h_sub_url', 'Subscribe link', { hint: 'Empty = turns on job alerts (push notifications).' }],
    ['heading', 'Mobile menu'],
    ['toggle', 'h_drawer_hello', 'Show the greeting (“Hello, Reader”)'],
    ['text', 'h_drawer_title', 'Greeting (mobile menu)'],
    ['text', 'h_drawer_text', 'Text under the greeting'],
    ['toggle', 'h_drawer_login', 'Show “Log in” in the mobile menu', { hint: 'Also needs “Show Log in” above.' }],
    ['toggle', 'h_drawer_sub', 'Show “Subscribe” in the mobile menu', { hint: 'Also needs “Show Subscribe” above.' }],
    ['heading', 'More'],
    ['toggle', 'h_bell', 'Notification bell'],
    ['toggle', 'h_socials', 'Social icons on the left'],
    ['toggle', 'h_dark', 'Dark mode switch'],
    ['range', 'h_width', 'Content width', { min: 960, max: 1600, step: 10, unit: 'px' }],
    ['toggle', 'h_topbar', 'Show top bar (above the header)'],
    ['textarea', 'h_topbar_text', 'Top bar text / HTML'],
  ]},
  { id: 'menus', icon: 'fa-bars', label: 'Menus', sub: 'Primary menu and the menu strip', render: renderMenus },
  { id: 'footer', icon: 'fa-shoe-prints', label: 'Footer', sub: 'Columns, follow box, copyright', render: renderFooter },
  { id: 'sidebar', icon: 'fa-table-columns', label: 'Sidebar', sub: 'Where it shows and what it lists', fields: [
    ['toggle', 'sb_post', 'Posts (articles)'],
    ['text', 'sb_heading', 'Heading', { hint: '{category} = the post’s category.' }],
    ['range', 'sb_count', 'Number of posts', { min: 3, max: 10 }],
    ['seg', 'sb_source', 'Posts', { options: [['category', 'Same category'], ['latest', 'Latest']] }],
    ['toggle', 'sb_sticky', 'Sticky while scrolling'],
  ]},
  { id: 'post', icon: 'fa-file-lines', label: 'Post Template', sub: 'Design and sections of article pages', sections: [
    ['Top of the post', [['toggle', 'p_breadcrumb', 'Breadcrumb'], ['toggle', 'p_cat_badge', 'Category label (⚡ CATEGORY)'], ['toggle', 'p_author_date', 'Author & date row'], ['seg', 'p_date_shown', 'Date shown', { options: [['updated', 'Last updated'], ['published', 'Published']] }], ['toggle', 'p_reading_time', 'Reading time'], ['toggle', 'p_pref_source', '“Add as a preferred source on Google”'], ['toggle', 'p_gnews', 'Google News icon (own box)'], ['text', 'p_gnews_url', 'Google News link', { hint: 'Your publication page on news.google.com.' }]]],
    ['Featured image', [['toggle', 'p_featured', 'Featured image'], ['toggle', 'p_caption', 'Caption under the image (alt text)']]],
    ['Summary & Key Points', [['toggle', 'p_summary', 'Summary box'], ['text', 'p_summary_heading', 'Summary heading'], ['seg', 'p_summary_pos', 'Summary position', { options: [['before_featured', 'Before image'], ['after_featured', 'After image'], ['before_content', 'Before content']] }], ['toggle', 'p_keypoints', 'Key Points box'], ['text', 'p_keypoints_heading', 'Key Points heading'], ['seg', 'p_keypoints_style', 'Bullet style', { options: [['dot', 'Dot'], ['check', 'Check'], ['number', 'Number']] }], ['range', 'p_keypoints_after', 'Show after paragraph (0 = above the article)', { min: 0, max: 6 }]]],
    ['Table of contents', [['toggle', 'p_toc', 'Table of contents'], ['toggle', 'p_toc_collapsed', 'Start collapsed']]],
    ['End of the post', [['toggle', 'p_faqs', 'FAQs'], ['toggle', 'p_tags', 'Tags'], ['toggle', 'p_share', 'Share buttons'], ['toggle', 'p_author_box', 'Author box'], ['toggle', 'p_comments', 'Comments']]],
    ['Related posts', [['toggle', 'p_related', 'Related posts grid'], ['text', 'p_related_heading', 'Related heading'], ['range', 'p_related_count', 'How many', { min: 2, max: 9 }]]],
    ['Join boxes', [['toggle', 'p_join', 'Join WhatsApp / Telegram boxes'], ['text', 'p_join_whatsapp', 'WhatsApp link'], ['text', 'p_join_telegram', 'Telegram link']]],
  ]},
  { id: 'alsoread', icon: 'fa-newspaper', label: 'Also Read', sub: 'Related-post boxes inside articles', render: renderAlsoRead },
  { id: 'progress', icon: 'fa-circle-notch', label: 'Reading Progress', sub: 'Floating progress button on articles', fields: [
    ['toggle', 'rp_button', 'Reading progress button', { hint: 'A floating pill that fills as the reader scrolls, shows the section and minutes left, and lists the H2 headings to jump to.' }],
    ['text', 'rp_label', 'Title of the sections list'],
    ['toggle', 'rp_desktop', 'Also show on desktop'],
    ['toggle', 'rp_bar', 'Thin progress bar at the top of articles'],
    ['hint', 'Switch the preview to Mobile (bottom of this panel) to see the button. The sections list shows the article’s H2 headings.'],
  ]},
  { id: 'home', icon: 'fa-table-cells-large', label: 'Homepage & Archives', sub: 'Post grids and lists', sections: [
    ['Homepage', [['toggle', 'home_hero', 'Search box & category buttons'], ['text', 'home_heading', 'Heading above the posts (optional)'], ['range', 'home_posts', 'Posts on the homepage', { min: 0, max: 30 }], ['toggle', 'home_lead', 'First post as a large card'], ['toggle', 'home_grid_more', '“Read more” button on the 3-per-row cards'], ['toggle', 'home_tables', 'Job tables (Recent Updates, Admit Card, Answer Key, Result)'], ['toggle', 'home_faqs', 'FAQs']]],
    ['Archives (categories, tags, authors, search)', [['range', 'arch_columns', 'Columns', { min: 2, max: 4 }]]],
  ]},
  { id: 'consent', icon: 'fa-cookie-bite', label: 'Cookie Consent', sub: 'Privacy popup for Google & AdSense', sections: [
    ['Popup', [['toggle', 'cc_enabled', 'Show the cookie consent popup'], ['seg', 'cc_region', 'Show to', { options: [['all', 'Everyone'], ['eea', 'EEA, UK & CH only']] }], ['seg', 'cc_position', 'Position', { options: [['bottom', 'Bottom bar'], ['left', 'Bottom left'], ['center', 'Center']] }], ['hint', 'Google Consent Mode v2 is set up automatically: ads and analytics wait for the visitor’s choice.']]],
    ['Texts', [['text', 'cc_title', 'Title'], ['textarea', 'cc_message', 'Message'], ['text', 'cc_accept', '“Accept” button'], ['text', 'cc_reject', '“Reject” button'], ['text', 'cc_customize', '“Customize” button'], ['text', 'cc_save', '“Save” button (in Customize)'], ['text', 'cc_privacy_text', 'Privacy link text'], ['text', 'cc_privacy_url', 'Privacy policy URL']]],
    ['Behaviour', [['toggle', 'cc_sticky', 'Sticky cookie button (to change the choice later)'], ['select', 'cc_reask', 'Show the popup again', { options: [['never', 'Never (remember the choice)'], ['daily', 'Once a day if rejected'], ['visit', 'Every visit if rejected']] }], ['range', 'cc_days', 'Remember the choice for', { min: 30, max: 395, unit: 'days' }], ['toggle', 'cc_footer_link', '“Cookie settings” link in the footer']]],
  ]},
  { id: 'shortcodes', icon: 'fa-code', label: 'Shortcodes', sub: 'Codes you can use anywhere', render: renderShortcodes },
  { id: 'css', icon: 'fa-code', label: 'Additional CSS', sub: 'Your own CSS rules', fields: [
    ['code', 'custom_css', 'CSS'],
  ]},
];

const $ = id => document.getElementById(id);
let dirty = false, panel = null, draftTimer = null;
const getV = k => k.startsWith('g.') ? state.general[k.slice(2)] : state.theme[k];
function setV(k, v) {
  if (k.startsWith('g.')) state.general[k.slice(2)] = v; else state.theme[k] = v;
  touch();
}
const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
function post(action, extra) {
  const fd = new FormData(); fd.append('_csrf', CSRF); fd.append('action', action); fd.append('data', JSON.stringify(state));
  return fetch('/admin/customize.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(r => r.json());
}
function pushDraft() { $('cz-refreshing').hidden = false; post('draft').then(reloadFrame); }
let framePath = '/';
function reloadFrame() {
  $('cz-loading').hidden = false; $('cz-refreshing').hidden = false;
  const f = $('cz-frame'); const y = (() => { try { return f.contentWindow.scrollY; } catch (e) { return 0; } })();
  f.onload = () => { $('cz-loading').hidden = true; $('cz-refreshing').hidden = true; try { f.contentWindow.scrollTo(0, y); } catch (e) {} };
  f.src = framePath + (framePath.includes('?') ? '&' : '?') + 'czp=1&t=' + Date.now();
}
$('cz-frame').onload = () => { $('cz-loading').hidden = true; };

// --- field renderers ---
function field(f) {
  const [type, key, label, ex = {}] = f;
  const w = document.createElement('div');
  if (type === 'hint') { w.className = 'cz-hint'; w.textContent = key; return w; }
  if (type === 'heading') { const h = document.createElement('h3'); h.className = 'cz-heading'; h.textContent = key; return h; }
  const v = getV(key);
  if (type === 'toggle') {
    w.innerHTML = '<label class="cz-toggle"><span class="cz-toggle-text">' + esc(label) + (ex.hint ? '<small>' + esc(ex.hint) + '</small>' : '') + '</span><input type="checkbox"' + (String(v) === '1' ? ' checked' : '') + '><span class="cz-switch" aria-hidden="true"></span></label>';
    w.querySelector('input').onchange = e => setV(key, e.target.checked ? '1' : '0');
  } else if (type === 'text' || type === 'textarea' || type === 'code') {
    w.innerHTML = '<label class="cz-field"><span class="cz-label">' + esc(label) + '</span>' + (type === 'text' ? '<input type="text">' : '<textarea rows="' + (type === 'code' ? 14 : 4) + '"' + (type === 'code' ? ' class="cz-codearea" spellcheck="false"' : '') + '></textarea>') + (ex.hint ? '<small class="cz-hint">' + esc(ex.hint) + '</small>' : '') + '</label>';
    const i = w.querySelector('input,textarea'); i.value = v ?? ''; if (ex.placeholder) i.placeholder = ex.placeholder; i.oninput = e => setV(key, e.target.value);
  } else if (type === 'range') {
    w.innerHTML = '<div class="cz-field"><span class="cz-label">' + esc(label) + '</span><div class="cz-range"><input type="range" min="' + ex.min + '" max="' + ex.max + '" step="' + (ex.step || 1) + '"><span class="cz-num"><input type="number" min="' + ex.min + '" max="' + ex.max + '" step="' + (ex.step || 1) + '">' + (ex.unit ? '<em>' + ex.unit + '</em>' : '') + '</span></div></div>';
    const [r, n] = w.querySelectorAll('input'); r.value = n.value = v;
    r.oninput = () => { n.value = r.value; setV(key, Number(r.value)); };
    n.oninput = () => { r.value = n.value; setV(key, Number(n.value)); };
  } else if (type === 'select') {
    w.innerHTML = '<label class="cz-field"><span class="cz-label">' + esc(label) + '</span><select>' + ex.options.map(o => '<option value="' + esc(o[0]) + '">' + esc(o[1]) + '</option>').join('') + '</select></label>';
    const s = w.querySelector('select'); s.value = v; s.onchange = () => setV(key, s.value);
  } else if (type === 'seg') {
    w.innerHTML = '<div class="cz-field"><span class="cz-label">' + esc(label) + '</span><div class="cz-seg">' + ex.options.map(o => '<button type="button" data-v="' + esc(o[0]) + '"' + (o[0] === v ? ' class="active"' : '') + '>' + esc(o[1]) + '</button>').join('') + '</div></div>';
    w.querySelectorAll('button').forEach(b => b.onclick = () => { w.querySelectorAll('button').forEach(x => x.classList.toggle('active', x === b)); setV(key, b.dataset.v); });
  } else if (type === 'color') {
    w.innerHTML = '<div class="cz-color"><span class="cz-color-label">' + esc(label) + (DEFAULTS[key] === '' ? '<small>' + (v ? 'Custom' : 'Follows the accent color') + '</small>' : '') + '</span><button type="button" class="cz-swatch" title="' + esc(v || 'Accent') + '"></button></div>';
    const sw = w.querySelector('.cz-swatch'); sw.style.background = v || getV('c_accent');
    sw.onclick = () => {
      let p = w.querySelector('.cz-picker'); if (p) { p.remove(); return; }
      p = document.createElement('div'); p.className = 'cz-picker';
      p.innerHTML = '<div class="cz-picker-title">Custom</div><div class="cz-picker-row"><input type="color"><input type="text" placeholder="#hex"></div><div class="cz-picker-row"><button type="button" class="cz-link-btn"><i class="fas fa-rotate-left"></i> Default</button><button type="button" class="cz-btn-primary cz-btn-sm">Done</button></div>';
      const [ci, ti] = p.querySelectorAll('input'); ci.value = /^#[0-9a-f]{6}$/i.test(getV(key)) ? getV(key) : '#000000'; ti.value = getV(key);
      const apply = val => { sw.style.background = val; sw.title = val; setV(key, val); };
      ci.oninput = () => { ti.value = ci.value; apply(ci.value); };
      ti.oninput = () => { if (/^#[0-9a-f]{3,8}$/i.test(ti.value.trim())) { ci.value = ti.value.trim().length === 7 ? ti.value.trim() : ci.value; apply(ti.value.trim()); } };
      p.querySelector('.cz-link-btn').onclick = () => { const d = DEFAULTS[key]; ci.value = d || getV('c_accent'); ti.value = d; apply(d); sw.style.background = d || getV('c_accent'); };
      p.querySelector('.cz-btn-primary').onclick = () => p.remove();
      w.querySelector('.cz-color').appendChild(p);
    };
  } else if (type === 'media') {
    w.innerHTML = '<div class="cz-field"><span class="cz-label">' + esc(label) + '</span><div class="cz-media-box"></div><div class="cz-btn-row"><button type="button" class="cz-btn-outline" data-a="pick">Select image</button><button type="button" class="cz-link-btn" data-a="clear">Remove</button></div>' + (ex.hint ? '<small class="cz-hint">' + esc(ex.hint) + '</small>' : '') + '</div>';
    const box = w.querySelector('.cz-media-box');
    const show = () => { const cur = getV(key); box.innerHTML = cur ? '<img src="' + esc(cur) + '" alt="">' : 'No image selected'; };
    show();
    w.querySelector('[data-a=pick]').onclick = () => EduMedia.pick(it => { setV(key, it.path); show(); });
    w.querySelector('[data-a=clear]').onclick = () => { setV(key, ''); show(); };
  } else if (type === 'list') {
    w.className = 'cz-field';
    const render = () => {
      const items = (getV(key) || []).slice();
      w.innerHTML = '<span class="cz-label">' + esc(label) + '</span>' + items.map((it, i) => '<div class="cz-list-item" data-i="' + i + '"><input type="text" placeholder="Label" data-f="label" value="' + esc(it.label) + '"><input type="text" placeholder="/url or https://" data-f="url" value="' + esc(it.url) + '"><span class="cz-li-btns"><button type="button" data-m="up" title="Move up">↑</button><button type="button" data-m="del" title="Remove">✕</button></span></div>').join('') + '<button type="button" class="cz-btn-outline cz-list-add">+ Add link</button>';
      w.querySelectorAll('.cz-list-item input').forEach(inp => inp.oninput = () => { const i = +inp.closest('.cz-list-item').dataset.i; items[i][inp.dataset.f] = inp.value; setV(key, items); });
      w.querySelectorAll('[data-m]').forEach(b => b.onclick = () => {
        const i = +b.closest('.cz-list-item').dataset.i;
        if (b.dataset.m === 'del') items.splice(i, 1); else if (i > 0) [items[i - 1], items[i]] = [items[i], items[i - 1]];
        setV(key, items); render();
      });
      w.querySelector('.cz-list-add').onclick = () => { items.push({ label: '', url: '' }); state.theme[key] = items; render(); };
    };
    render();
  }
  return w;
}
function section(title, fields, open) {
  const s = document.createElement('div'); s.className = 'cz-section' + (open ? ' open' : '');
  s.innerHTML = '<button type="button" class="cz-section-head"><span>' + esc(title) + '</span><i class="fas fa-chevron-' + (open ? 'up' : 'down') + '"></i></button><div class="cz-section-body"' + (open ? '' : ' hidden') + '></div>';
  const body = s.querySelector('.cz-section-body'); fields.forEach(f => body.appendChild(field(f)));
  s.querySelector('.cz-section-head').onclick = () => { const o = body.hidden; body.hidden = !o; s.classList.toggle('open', o); s.querySelector('.cz-section-head i').className = 'fas fa-chevron-' + (o ? 'up' : 'down'); };
  return s;
}
// ===== Customizer v2 panels (same controls as the CMS): menus, footer columns, colors, typography, Also Read, shortcodes =====
const html = s => { const t = document.createElement('template'); t.innerHTML = String(s).trim(); return t.content.firstElementChild; };
function touch() {
  dirty = true; $('cz-status').textContent = 'Unsaved changes'; $('cz-publish').disabled = false; $('cz-publish').textContent = 'Publish';
  clearTimeout(draftTimer); draftTimer = setTimeout(pushDraft, 450);
}
function rerender() { if (panel) { const y = $('cz-body').scrollTop; showPanel(panel, true); $('cz-body').scrollTop = y; } }
function hintEl(t) { return html('<p class="cz-hint">' + esc(t) + '</p>'); }
function circleBtn(icon, title, onClick, danger) {
  const b = html('<button type="button" class="cz-circle' + (danger ? ' danger' : '') + '" title="' + esc(title) + '" aria-label="' + esc(title) + '"><i class="fas ' + icon + '"></i></button>');
  b.onclick = onClick; return b;
}
function sectionEl(title, build, open, badge) {
  const s = html('<div class="cz-section' + (open ? ' open' : '') + '"><button type="button" class="cz-section-head"><span>' + esc(title) + (badge ? '<em class="cz-badge">' + esc(badge) + '</em>' : '') + '</span><i class="fas fa-chevron-' + (open ? 'up' : 'down') + '"></i></button><div class="cz-section-body"' + (open ? '' : ' hidden') + '></div></div>');
  const body = s.querySelector('.cz-section-body'); build(body);
  s.querySelector('.cz-section-head').onclick = () => { const o = body.hidden; body.hidden = !o; s.classList.toggle('open', o); s.querySelector('.cz-section-head i').className = 'fas fa-chevron-' + (o ? 'up' : 'down'); };
  return s;
}
// controls bound to a getter / setter (for values inside lists)
function cText(label, get, set, o = {}) {
  const w = html('<label class="cz-field"><span class="cz-label">' + esc(label) + '</span>' + (o.multi ? '<textarea rows="4"></textarea>' : '<input type="text">') + (o.hint ? '<small class="cz-hint">' + esc(o.hint) + '</small>' : '') + '</label>');
  const i = w.querySelector('input,textarea'); i.value = get() ?? ''; if (o.placeholder) i.placeholder = o.placeholder;
  i.oninput = () => { set(i.value); touch(); }; return w;
}
function cToggle(label, get, set, hint) {
  const w = html('<label class="cz-toggle"><span class="cz-toggle-text">' + esc(label) + (hint ? '<small>' + esc(hint) + '</small>' : '') + '</span><input type="checkbox"><span class="cz-switch" aria-hidden="true"></span></label>');
  const i = w.querySelector('input'); i.checked = !!get(); i.onchange = () => { set(i.checked); touch(); }; return w;
}
function cRange(label, get, set, min, max, step = 1, unit = '') {
  const w = html('<div class="cz-field"><span class="cz-label">' + esc(label) + '</span><div class="cz-range"><input type="range" min="' + min + '" max="' + max + '" step="' + step + '"><span class="cz-num"><input type="number" min="' + min + '" max="' + max + '" step="' + step + '">' + (unit ? '<em>' + unit + '</em>' : '') + '</span></div></div>');
  const [r, n] = w.querySelectorAll('input'); r.value = n.value = get();
  const go = v => { v = Math.max(min, Math.min(max, Number(v) || min)); set(v); touch(); };
  r.oninput = () => { n.value = r.value; go(r.value); }; n.onchange = () => { r.value = n.value; go(n.value); }; return w;
}
function cSelect(label, get, set, options, hint) {
  const w = html('<label class="cz-field"><span class="cz-label">' + esc(label) + '</span><select>' + options.map(o => '<option value="' + esc(o[0]) + '">' + esc(o[1]) + '</option>').join('') + '</select>' + (hint ? '<small class="cz-hint">' + esc(hint) + '</small>' : '') + '</label>');
  const s = w.querySelector('select'); s.value = get(); s.onchange = () => { set(s.value); touch(); }; return w;
}
function cSeg(label, get, set, options, after) {
  const w = html('<div class="cz-field">' + (label ? '<span class="cz-label">' + esc(label) + '</span>' : '') + '<div class="cz-seg">' + options.map(o => '<button type="button" data-v="' + esc(o[0]) + '"' + (o[0] === get() ? ' class="active"' : '') + '>' + (o[2] ? '<i class="fas ' + o[2] + '"></i> ' : '') + esc(o[1]) + '</button>').join('') + '</div></div>');
  w.querySelectorAll('button').forEach(b => b.onclick = () => { w.querySelectorAll('button').forEach(x => x.classList.toggle('active', x === b)); set(b.dataset.v); touch(); if (after) after(); });
  return w;
}

/* ---------- Colors ---------- */
const COLOR_RX = /^(#[0-9a-f]{3,8}|(rgb|rgba|hsl|hsla)\([0-9.,%\s\/deg]+\)|transparent)$/i;
function toHex(v) { return /^#[0-9a-f]{6}$/i.test(v) ? v : /^#[0-9a-f]{3}$/i.test(v) ? '#' + v.slice(1).split('').map(c => c + c).join('') : '#ffffff'; }
function colorCtl(label, get, set) {
  const w = html('<div class="cz-color"><span class="cz-color-label"></span><button type="button" class="cz-swatch"></button></div>');
  const lab = w.querySelector('.cz-color-label'), sw = w.querySelector('.cz-swatch');
  const pal = () => state.theme.global_colors || [];
  const shown = () => { const v = get() || '', m = /^var\(--gc-([\w-]+)\)$/.exec(v), g = m && pal().find(p => p.slug === m[1]); return [m ? (g ? g.color : '') : v, g ? g.name : '']; };
  w._paint = () => { const [c, name] = shown(), v = get() || ''; sw.style.background = c || ''; sw.classList.toggle('is-empty', !v); sw.title = v || 'Default'; lab.innerHTML = esc(label) + (name ? '<small>' + esc(name) + '</small>' : ''); };
  w._paint();
  sw.onclick = () => {
    let p = w.querySelector('.cz-picker'); if (p) { p.remove(); return; }
    document.querySelectorAll('.cz-picker').forEach(x => x.remove());
    p = html('<div class="cz-picker"><div class="cz-picker-title">Global colors</div><div class="cz-picker-palette"></div><div class="cz-picker-title">Custom</div><div class="cz-picker-row"><input type="color"><input type="text" placeholder="#hex or rgba()"></div><div class="cz-picker-row"><button type="button" class="cz-link-btn"><i class="fas fa-rotate-left"></i> Default</button><button type="button" class="cz-btn-primary cz-btn-sm">Done</button></div></div>');
    const box = p.querySelector('.cz-picker-palette'), [ci, ti] = p.querySelectorAll('input');
    pal().forEach(g => {
      const b = html('<button type="button" class="cz-pal" title="' + esc(g.name) + '"></button>'); b.style.background = g.color;
      if (get() === 'var(--gc-' + g.slug + ')') b.classList.add('active');
      b.onclick = () => { set('var(--gc-' + g.slug + ')'); touch(); w._paint(); box.querySelectorAll('.cz-pal').forEach(x => x.classList.toggle('active', x === b)); ti.value = get(); ci.value = toHex(g.color); };
      box.appendChild(b);
    });
    ci.value = toHex(shown()[0]); ti.value = get() || '';
    ci.oninput = () => { ti.value = ci.value; set(ci.value); touch(); w._paint(); box.querySelectorAll('.cz-pal').forEach(x => x.classList.remove('active')); };
    ti.oninput = () => { const v = ti.value.trim(); if (v === '' || COLOR_RX.test(v)) { set(v); touch(); w._paint(); if (/^#[0-9a-f]{6}$/i.test(v)) ci.value = v; } };
    p.querySelector('.cz-link-btn').onclick = () => { set(''); touch(); ti.value = ''; w._paint(); box.querySelectorAll('.cz-pal').forEach(x => x.classList.remove('active')); };
    p.querySelector('.cz-btn-primary').onclick = () => p.remove();
    w.appendChild(p);
  };
  return w;
}
document.addEventListener('mousedown', e => { document.querySelectorAll('.cz-picker').forEach(p => { if (!p.parentNode.contains(e.target)) p.remove(); }); });
let gpalEditing = null;
function renderColors(wrap) {
  const T = state.theme;
  if (!T.colors || Array.isArray(T.colors)) T.colors = {};
  Object.keys(T.colors).forEach(g => { if (Array.isArray(T.colors[g])) T.colors[g] = {}; });
  if (!Array.isArray(T.global_colors)) T.global_colors = [];
  const block = html('<div class="cz-block"><div class="cz-block-head"><strong>Global Colors</strong><span class="cz-block-actions"></span></div><div class="cz-gpal"></div><div></div></div>');
  const repaintAll = () => wrap.querySelectorAll('.cz-color').forEach(x => x._paint && x._paint());
  const draw = () => {
    const box = block.querySelector('.cz-gpal'), ed = block.children[2]; box.innerHTML = ''; ed.innerHTML = '';
    T.global_colors.forEach((g, i) => {
      const b = html('<button type="button" class="cz-gpal-item' + (gpalEditing === i ? ' active' : '') + '" title="' + esc(g.name) + ' — click to edit"></button>');
      b.style.background = g.color; b.onclick = () => { gpalEditing = gpalEditing === i ? null : i; draw(); }; box.appendChild(b);
    });
    const g = gpalEditing !== null ? T.global_colors[gpalEditing] : null;
    if (g) {
      const row = html('<div class="cz-gpal-edit"><input type="color"><input class="cz-gpal-hex"><input class="cz-gpal-name" placeholder="Name"></div>');
      const [c, h, n] = row.querySelectorAll('input'); c.value = toHex(g.color); h.value = g.color; n.value = g.name;
      const swatch = () => { box.children[gpalEditing].style.background = g.color; repaintAll(); };
      c.oninput = () => { g.color = c.value; h.value = c.value; touch(); swatch(); };
      h.oninput = () => { const v = h.value.trim(); if (COLOR_RX.test(v)) { g.color = v; if (/^#[0-9a-f]{6}$/i.test(v)) c.value = v; touch(); swatch(); } };
      n.oninput = () => { g.name = n.value; touch(); };
      if (gpalEditing > 6) row.appendChild(circleBtn('fa-trash', 'Delete color', () => { T.global_colors.splice(gpalEditing, 1); gpalEditing = null; touch(); draw(); repaintAll(); }, true));
      ed.appendChild(row);
      if (g.slug === 'accent') ed.appendChild(hintEl('Accent = links, buttons, badges and highlights across the site.'));
    }
  };
  block.querySelector('.cz-block-actions').appendChild(circleBtn('fa-plus', 'Add color', () => { T.global_colors.push({ slug: 'custom-' + Date.now().toString(36), name: 'Custom', color: '#888888' }); gpalEditing = T.global_colors.length - 1; touch(); draw(); }));
  draw();
  block.appendChild(hintEl('Colors below can use a global color — change the global color and everything using it updates.'));
  wrap.appendChild(block);
  Object.entries(COLOR_GROUPS).forEach(([g, [label, fields]], idx) => {
    wrap.appendChild(sectionEl(label, body => {
      Object.entries(fields).forEach(([f, fl]) => body.appendChild(colorCtl(fl, () => (T.colors[g] || {})[f] || '', v => {
        if (!T.colors[g] || Array.isArray(T.colors[g])) T.colors[g] = {};
        if (v) T.colors[g][f] = v; else delete T.colors[g][f];
      })));
    }, idx === 0));
  });
}

/* ---------- Typography ---------- */
const SYSTEM_FONTS = ['Arial', 'Helvetica', 'Georgia', 'Times New Roman', 'Verdana', 'Tahoma', 'Trebuchet MS'];
const GOOGLE_FONTS = ['Noto Sans Devanagari', 'Noto Serif Devanagari', 'Hind', 'Mukta', 'Poppins', 'Inter', 'Roboto', 'Open Sans', 'Lato', 'Montserrat', 'Source Sans 3', 'Nunito', 'Nunito Sans',
  'Raleway', 'Rubik', 'Work Sans', 'PT Sans', 'Merriweather', 'Playfair Display', 'Lora', 'Oswald', 'Barlow', 'DM Sans', 'Manrope', 'Figtree', 'Lexend', 'Lexend Deca',
  'Libre Baskerville', 'Baloo 2', 'Tiro Devanagari Hindi', 'Khand', 'Rajdhani', 'Yatra One', 'Roboto Slab', 'Roboto Condensed', 'Ubuntu', 'Mulish', 'Quicksand', 'Josefin Sans',
  'Archivo', 'IBM Plex Sans', 'Plus Jakarta Sans', 'Outfit', 'Sora', 'Space Grotesk', 'Kanit', 'Prompt', 'Cairo', 'Tajawal', 'Noto Sans', 'Noto Serif', 'Source Serif 4'];
const VARIANTS = ['100', '200', '300', '400', '500', '600', '700', '800', '900', '400italic', '700italic'];
const WEIGHTS = [['', 'Default'], ...['100', '200', '300', '400', '500', '600', '700', '800', '900'].map(w => [w, w]), ['normal', 'Normal'], ['bold', 'Bold']];
const TYPO_GROUPS = [['Base', ['body', 'headings']], ['Header', ['site_title', 'tagline']], ['Navigation', ['primary_menu', 'sub_menu', 'menu_strip']],
  ['Content', ['buttons', 'post_title', 'archive_title', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'meta']], ['Widgets', ['widget_title']], ['Footer', ['footer']], ['Custom', ['custom']]];
let device = 'd', openFont = null, openRule = null;
function setDevice(d) {
  device = d;
  document.querySelectorAll('#cz-devices button').forEach(x => x.classList.toggle('active', x.dataset.dev === d));
  $('cz-wrap').className = 'cz-frame-wrap cz-dev-' + d;
  if (panel && panel.id === 'typography') rerender();
}
function newRule(target) { const e = () => ({ d: '', t: '', m: '' }); return { id: Math.random().toString(36).slice(2, 9), target, selector: '', family: '', weight: '', transform: '', style: '', decoration: '', size: e(), sizeUnit: 'px', lineHeight: e(), letterSpacing: e(), marginBottom: e() }; }
function respCtl(label, obj, unit, slider) {
  const v = obj[device] || '', fb = device === 'm' ? (obj.t || obj.d || '') : device === 't' ? (obj.d || '') : '';
  const w = html('<div class="cz-field"><span class="cz-label cz-label-row">' + esc(label) + '<span class="cz-devices cz-devices-sm">' + [['d', 'fa-desktop', 'Desktop'], ['t', 'fa-tablet-screen-button', 'Tablet'], ['m', 'fa-mobile-screen-button', 'Mobile']].map(([d, ic, t]) => '<button type="button" data-d="' + d + '" title="' + t + '"' + (device === d ? ' class="active"' : '') + '><i class="fas ' + ic + '"></i></button>').join('') + '</span></span><div class="cz-range"><input type="range" min="' + slider[0] + '" max="' + slider[1] + '" step="' + slider[2] + '"><span class="cz-num"><input type="text" inputmode="decimal">' + (unit ? '<em>' + unit + '</em>' : '') + '</span><button type="button" class="cz-reset" title="Reset"' + (v ? '' : ' hidden') + '><i class="fas fa-rotate-left"></i></button></div></div>');
  w.querySelectorAll('[data-d]').forEach(b => b.onclick = () => setDevice(b.dataset.d));
  const [r, n] = w.querySelectorAll('.cz-range input'), reset = w.querySelector('.cz-reset');
  r.value = v || fb || slider[0]; n.value = v; n.placeholder = fb;
  const put = val => { obj[device] = val; reset.hidden = !val; touch(); };
  r.oninput = () => { n.value = r.value; put(r.value); };
  n.oninput = () => { const x = n.value.trim(); if (x === '' || /^-?\d*\.?\d+$/.test(x)) { if (x !== '') r.value = x; put(x); } };
  reset.onclick = () => { n.value = ''; r.value = fb || slider[0]; put(''); };
  return w;
}
function renderTypography(wrap) {
  const T = state.theme;
  if (!Array.isArray(T.fonts)) T.fonts = [];
  if (!Array.isArray(T.typography)) T.typography = [];
  // Font Manager
  const fb = html('<div class="cz-block"><div class="cz-block-head"><strong>Font Manager</strong></div></div>');
  T.fonts.forEach((f, i) => {
    const it = html('<div class="cz-item' + (openFont === i ? ' open' : '') + '"><div class="cz-item-row"><span class="cz-item-title"></span></div></div>');
    const ttl = it.querySelector('.cz-item-title'); ttl.textContent = f.family || 'New font'; if (f.family) ttl.style.fontFamily = '"' + f.family + '", ' + (f.fallback || 'sans-serif');
    const row = it.querySelector('.cz-item-row');
    row.appendChild(circleBtn(openFont === i ? 'fa-chevron-up' : 'fa-chevron-down', 'Edit font', () => { openFont = openFont === i ? null : i; rerender(); }));
    row.appendChild(circleBtn('fa-trash-can', 'Delete font', () => { T.fonts.splice(i, 1); openFont = null; touch(); rerender(); }, true));
    if (openFont === i) {
      const card = html('<div class="cz-item-card"></div>');
      const cb = html('<div class="cz-field cz-combobox"><span class="cz-label">Font family name</span><input placeholder="Search Google Fonts or type a name"><div class="cz-combo-list" hidden></div></div>');
      const inp = cb.querySelector('input'), list = cb.querySelector('.cz-combo-list'); inp.value = f.family || '';
      const showList = () => {
        const q = inp.value.toLowerCase(), m = GOOGLE_FONTS.filter(x => !q || x.toLowerCase().includes(q)).slice(0, 12);
        list.innerHTML = ''; m.forEach(x => { const b = html('<button type="button"></button>'); b.textContent = x; b.onmousedown = e => e.preventDefault(); b.onclick = () => { inp.value = x; f.family = x; f.google = true; list.hidden = true; touch(); ttl.textContent = x; }; list.appendChild(b); });
        list.hidden = !m.length;
      };
      inp.onfocus = showList; inp.oninput = () => { f.family = inp.value; touch(); ttl.textContent = inp.value || 'New font'; showList(); }; inp.onblur = () => setTimeout(() => list.hidden = true, 150);
      card.appendChild(cb);
      card.appendChild(cToggle('Use Google Fonts API', () => f.google, v => { f.google = v; rerender(); }, 'Off = a font already installed on the device.'));
      if (f.google) {
        const vw = html('<div class="cz-field"><span class="cz-label">Variants</span><div class="cz-checks"></div></div>');
        VARIANTS.forEach(v => { const l = html('<label><input type="checkbox"> ' + v + '</label>'), c = l.querySelector('input'); c.checked = (f.variants || []).includes(v); c.onchange = () => { f.variants = c.checked ? [...(f.variants || []), v] : (f.variants || []).filter(x => x !== v); touch(); }; vw.querySelector('.cz-checks').appendChild(l); });
        card.appendChild(vw);
      }
      card.appendChild(cSelect('Category (fallback)', () => f.fallback || 'sans-serif', v => f.fallback = v, [['sans-serif', 'Sans-serif'], ['serif', 'Serif'], ['monospace', 'Monospace'], ['cursive', 'Handwriting'], ['system-ui', 'System UI']]));
      const close = html('<button type="button" class="cz-btn-outline cz-btn-sm">Close</button>'); close.onclick = () => { openFont = null; rerender(); }; card.appendChild(close);
      it.appendChild(card);
    }
    fb.appendChild(it);
  });
  const addF = html('<button type="button" class="cz-btn-primary">Add Font</button>');
  addF.onclick = () => { T.fonts.push({ family: '', google: true, variants: ['400', '700'], fallback: 'sans-serif' }); openFont = T.fonts.length - 1; touch(); rerender(); };
  fb.appendChild(addF);
  fb.appendChild(cSelect('Google font-display', () => T.font_display || 'swap', v => T.font_display = v, [['auto', 'Auto'], ['block', 'Block'], ['swap', 'Swap'], ['fallback', 'Fallback'], ['optional', 'Optional']]));
  wrap.appendChild(fb);
  // Typography Manager
  const tb = html('<div class="cz-block"><div class="cz-block-head"><strong>Typography Manager</strong></div></div>');
  const fams = [['', 'Default'], ...T.fonts.filter(f => f.family).map(f => [f.family, f.family]), ...SYSTEM_FONTS.map(f => [f, f])];
  TYPO_GROUPS.forEach(([gl, targets]) => {
    const rules = T.typography.filter(r => targets.includes(r.target)); if (!rules.length) return;
    const grp = html('<div class="cz-typo-group"><h4>' + esc(gl) + '</h4></div>');
    rules.forEach(r => {
      const it = html('<div class="cz-item' + (openRule === r.id ? ' open' : '') + '"><div class="cz-item-row"><span class="cz-item-title"></span></div></div>');
      it.querySelector('.cz-item-title').textContent = [r.target === 'custom' ? (r.selector || 'Custom') : TYPO_TARGETS[r.target], r.family, r.size && r.size.d ? r.size.d + r.sizeUnit : ''].filter(Boolean).join(' / ');
      const row = it.querySelector('.cz-item-row');
      row.appendChild(circleBtn(openRule === r.id ? 'fa-chevron-up' : 'fa-chevron-down', 'Edit', () => { openRule = openRule === r.id ? null : r.id; rerender(); }));
      row.appendChild(circleBtn('fa-trash-can', 'Delete', () => { T.typography = T.typography.filter(x => x.id !== r.id); touch(); rerender(); }, true));
      if (openRule === r.id) {
        const card = html('<div class="cz-item-card"></div>');
        card.appendChild(cSelect('Target Element', () => r.target, v => { r.target = v; rerender(); }, Object.entries(TYPO_TARGETS)));
        if (r.target === 'custom') card.appendChild(cText('CSS selector', () => r.selector, v => r.selector = v, { placeholder: '.entry-content p' }));
        card.appendChild(cSelect('Font Family', () => r.family || '', v => r.family = v, fams));
        if (!T.fonts.length) card.appendChild(hintEl('Add fonts in the Font Manager above to use them here.'));
        const g2 = html('<div class="cz-grid2"></div>');
        g2.appendChild(cSelect('Font Weight', () => r.weight || '', v => r.weight = v, WEIGHTS));
        g2.appendChild(cSelect('Text Transform', () => r.transform || '', v => r.transform = v, [['', 'Default'], ['none', 'None'], ['uppercase', 'Uppercase'], ['lowercase', 'Lowercase'], ['capitalize', 'Capitalize']]));
        g2.appendChild(cSelect('Font Style', () => r.style || '', v => r.style = v, [['', 'Default'], ['normal', 'Normal'], ['italic', 'Italic']]));
        g2.appendChild(cSelect('Text Decoration', () => r.decoration || '', v => r.decoration = v, [['', 'Default'], ['none', 'None'], ['underline', 'Underline'], ['line-through', 'Line-through']]));
        card.appendChild(g2);
        ['size', 'lineHeight', 'letterSpacing', 'marginBottom'].forEach(k => { if (!r[k] || typeof r[k] !== 'object' || Array.isArray(r[k])) r[k] = { d: '', t: '', m: '' }; });
        card.appendChild(respCtl('Font size', r.size, r.sizeUnit || 'px', [8, 80, 1]));
        const units = html('<div class="cz-unit-row">' + ['px', 'em', 'rem'].map(u => '<button type="button" data-u="' + u + '"' + ((r.sizeUnit || 'px') === u ? ' class="active"' : '') + '>' + u + '</button>').join('') + '</div>');
        units.querySelectorAll('button').forEach(b => b.onclick = () => { r.sizeUnit = b.dataset.u; touch(); rerender(); });
        card.appendChild(units);
        card.appendChild(respCtl(r.target === 'body' ? 'Line Height (article text)' : 'Line Height', r.lineHeight, '', [0.8, 3, 0.05]));
        card.appendChild(respCtl('Letter Spacing', r.letterSpacing, 'em', [-0.1, 0.5, 0.01]));
        card.appendChild(respCtl(r.target === 'body' ? 'Paragraph Bottom Margin' : 'Bottom Margin', r.marginBottom, 'em', [0, 4, 0.1]));
        const close = html('<button type="button" class="cz-btn-outline cz-btn-sm">Close</button>'); close.onclick = () => { openRule = null; rerender(); }; card.appendChild(close);
        it.appendChild(card);
      }
      grp.appendChild(it);
    });
    tb.appendChild(grp);
  });
  const addR = html('<button type="button" class="cz-btn-primary">Add Typography</button>');
  addR.onclick = () => {
    const used = new Set(T.typography.map(r => r.target)), next = Object.keys(TYPO_TARGETS).find(k => !used.has(k) && k !== 'custom') || 'custom', r = newRule(next);
    T.typography.push(r); openRule = r.id; touch(); rerender();
  };
  tb.appendChild(addR);
  wrap.appendChild(tb);
}

/* ---------- Menus ---------- */
let menuView = null, miOpen = null, addingFor = null;
function kindOf(url) {
  if (/^https?:\/\//.test(url)) return 'Custom Link';
  if (url === '/') return 'Front Page';
  if (url.startsWith('/categories/')) return 'Category';
  if (url === '/categories' || url === '/state-jobs' || url === '/latest-updates') return 'Archive';
  if (url.startsWith('/tag/')) return 'Tag';
  return 'Link';
}
// The items list: drag to reorder, expand to edit, move under / out of the item above (nested = sub-menus allowed)
function menuEditor(container, getItems, setItems, nested, key) {
  const items = getItems();
  const box = html('<div class="cz-menu-items"></div>');
  if (!items.length) box.appendChild(hintEl('Time to add some links! Click “Add Items” to start putting pages, categories, and custom links in your menu.'));
  let drag = null;
  const save = v => { setItems(v); touch(); rerender(); };
  const row = (it, id, onChange, onRemove, actions) => {
    const open = miOpen === key + ':' + id;
    const r = html('<div class="cz-mi' + (open ? ' open' : '') + '"><button type="button" class="cz-mi-head"><i class="fas fa-grip-vertical cz-mi-grip" aria-hidden="true"></i><span class="cz-mi-title"></span><span class="cz-mi-kind"></span><i class="fas fa-caret-' + (open ? 'up' : 'down') + '"></i></button></div>');
    r.querySelector('.cz-mi-title').textContent = it.label || '(no label)'; r.querySelector('.cz-mi-kind').textContent = kindOf(it.url || '');
    r.querySelector('.cz-mi-head').onclick = () => { miOpen = open ? null : key + ':' + id; rerender(); };
    if (open) {
      const b = html('<div class="cz-mi-body"></div>');
      b.appendChild(cText('Navigation Label', () => it.label, v => { it.label = v; onChange(); r.querySelector('.cz-mi-title').textContent = v || '(no label)'; }));
      b.appendChild(cText('URL', () => it.url, v => { it.url = v; onChange(); r.querySelector('.cz-mi-kind').textContent = kindOf(v); }));
      const nt = html('<label class="cz-check"><input type="checkbox"> Open link in a new tab</label>'), ntc = nt.querySelector('input');
      ntc.checked = !!it.newTab; ntc.onchange = () => { if (ntc.checked) it.newTab = true; else delete it.newTab; onChange(); touch(); };
      b.appendChild(nt);
      const mv = html('<div class="cz-mi-move"><span>Move</span></div>'); actions.forEach(([t, fn, dis]) => { const x = html('<button type="button"></button>'); x.textContent = t; x.disabled = !!dis; x.onclick = fn; mv.appendChild(x); }); b.appendChild(mv);
      const rm = html('<button type="button" class="cz-mi-remove">Remove</button>'); rm.onclick = onRemove; b.appendChild(rm);
      r.appendChild(b);
    }
    return r;
  };
  items.forEach((it, i) => {
    const wrapI = html('<div class="cz-mi-wrap" draggable="true"></div>');
    wrapI.ondragstart = e => { drag = i; e.dataTransfer.effectAllowed = 'move'; };
    wrapI.ondragover = e => { e.preventDefault(); wrapI.classList.add('drag-over'); };
    wrapI.ondragleave = () => wrapI.classList.remove('drag-over');
    wrapI.ondrop = e => { e.preventDefault(); wrapI.classList.remove('drag-over'); if (drag === null || drag === i) return; const n = items.slice(), [m] = n.splice(drag, 1); n.splice(i, 0, m); drag = null; save(n); };
    const acts = [['Up one', () => { const n = items.slice(); [n[i - 1], n[i]] = [n[i], n[i - 1]]; save(n); }, i === 0], ['Down one', () => { const n = items.slice(); [n[i + 1], n[i]] = [n[i], n[i + 1]]; save(n); }, i === items.length - 1]];
    if (nested && i > 0) acts.push(['Under ' + (items[i - 1].label || 'previous'), () => { const n = items.slice(), [m] = n.splice(i, 1), p = n[i - 1]; p.children = [...(p.children || []), { label: m.label, url: m.url, ...(m.newTab ? { newTab: true } : {}) }, ...(m.children || [])]; save(n); }]);
    wrapI.appendChild(row(it, String(i), () => setItems(items), () => save(items.filter((_, j) => j !== i)), acts));
    (it.children || []).forEach((c, j) => {
      const ch = html('<div class="cz-mi-child"></div>');
      ch.appendChild(row(c, i + '-' + j, () => setItems(items), () => { it.children.splice(j, 1); save(items); },
        [['Out from under ' + (it.label || 'parent'), () => { const n = items.slice(), [m] = it.children.splice(j, 1); n.splice(i + 1, 0, m); save(n); }]]));
      wrapI.appendChild(ch);
    });
    box.appendChild(wrapI);
  });
  container.appendChild(box);
  const foot = html('<div class="cz-menu-foot"><button type="button" class="cz-btn-outline cz-add-items' + (addingFor === key ? ' active' : '') + '"><i class="fas ' + (addingFor === key ? 'fa-xmark' : 'fa-plus') + '"></i> Add Items</button><span class="cz-hint">Drag items to reorder.</span></div>');
  foot.querySelector('button').onclick = () => { addingFor = addingFor === key ? null : key; rerender(); };
  container.appendChild(foot);
  if (addingFor === key) addItemsPanel(it => { const n = getItems().slice(); n.push(it); setItems(n); touch(); rerender(); });
}
let aiSection = 'pages', aiQuery = '';
function addItemsPanel(onAdd) {
  document.querySelectorAll('.cz-additems').forEach(x => x.remove());
  const p = html('<div class="cz-additems" role="dialog" aria-label="Add menu items"><div class="cz-additems-head"><span>Add Menu Items</span><button type="button" aria-label="Close"><i class="fas fa-xmark"></i></button></div><label class="cz-additems-search"><span>Search Menu Items</span><input placeholder="Search…"></label></div>');
  p.querySelector('.cz-additems-head button').onclick = () => { addingFor = null; p.remove(); rerender(); };
  const q = p.querySelector('input'); q.value = aiQuery;
  const secs = [['custom', 'Custom Links'], ['pages', 'Pages'], ['posts', 'Posts'], ['categories', 'Categories'], ['tags', 'Tags']];
  const draw = () => {
    p.querySelectorAll('.cz-ai-sec').forEach(x => x.remove());
    secs.forEach(([id, label]) => {
      const s = html('<div class="cz-ai-sec' + (aiSection === id ? ' open' : '') + '"><button type="button" class="cz-ai-head">' + esc(label) + '<i class="fas fa-caret-' + (aiSection === id ? 'up' : 'down') + '"></i></button></div>');
      s.querySelector('.cz-ai-head').onclick = () => { aiSection = aiSection === id ? null : id; draw(); };
      if (aiSection === id) {
        if (id === 'custom') {
          const c = html('<div class="cz-ai-custom"><label>URL<input value="https://"></label><label>Link Text<input></label><button type="button" class="cz-btn-outline" disabled><i class="fas fa-plus"></i> Add to Menu</button></div>');
          const [u, t] = c.querySelectorAll('input'), b = c.querySelector('button');
          const ok = () => b.disabled = !t.value.trim() || !u.value.trim() || u.value.trim() === 'https://';
          u.oninput = ok; t.oninput = ok;
          b.onclick = () => { onAdd({ label: t.value.trim(), url: u.value.trim() }); };
          s.appendChild(c);
        } else {
          const list = html('<div class="cz-ai-list"><p class="cz-hint">Loading…</p></div>'); s.appendChild(list);
          fetch('/admin/customize.php?src=' + id + '&q=' + encodeURIComponent(q.value.trim()), { credentials: 'same-origin' }).then(r => r.json()).then(rows => {
            list.innerHTML = '';
            if (!rows.length) list.innerHTML = '<p class="cz-hint">No results.</p>';
            rows.forEach(it => {
              const b = html('<button type="button"><i class="fas fa-plus"></i><span></span><em></em></button>');
              b.querySelector('span').textContent = it.label; b.querySelector('em').textContent = it.kind;
              b.onclick = () => { onAdd({ label: it.label, url: it.url }); };
              list.appendChild(b);
            });
          }).catch(() => { list.innerHTML = '<p class="cz-hint">Could not load. Please try again.</p>'; });
        }
      }
      p.appendChild(s);
    });
  };
  let t; q.oninput = () => { aiQuery = q.value; clearTimeout(t); t = setTimeout(draw, 250); };
  draw();
  document.getElementById('cz-root').appendChild(p);
}
function renderMenus(wrap) {
  const T = state.theme;
  if (!Array.isArray(T.menu)) T.menu = [];
  if (!Array.isArray(T.strip_items)) T.strip_items = [];
  if (!menuView) {
    wrap.appendChild(hintEl('Menus are lists of links shown in the header. Pick a menu to add, remove or reorder its links.'));
    const cards = html('<div class="cz-menu-cards"></div>');
    [['primary', 'fa-bars', 'Primary Menu', T.menu.length + ' items · The mobile menu (slide-out)'], ['strip', 'fa-grip-lines', 'Menu Strip', (T.strip_source === 'categories' ? 'All categories (automatic)' : T.strip_items.length + ' items') + ' · Row below the header']].forEach(([id, ic, t, s]) => {
      const b = html('<button type="button"><i class="fas ' + ic + '"></i><span><strong>' + esc(t) + '</strong><small>' + esc(s) + '</small></span><i class="fas fa-chevron-right"></i></button>');
      b.onclick = () => { menuView = id; miOpen = null; addingFor = null; rerender(); }; cards.appendChild(b);
    });
    wrap.appendChild(cards);
    const loc = html('<div class="cz-block"><div class="cz-block-head"><strong>Menu Locations</strong></div></div>');
    loc.appendChild(hintEl('Primary Menu → the mobile slide-out menu (items can have sub-menus). Menu Strip → the row under the header on every screen size.'));
    wrap.appendChild(loc);
    return;
  }
  const head = html('<div class="cz-subhead"><button type="button" class="cz-link-btn"><i class="fas fa-arrow-left"></i> All menus</button><strong>' + (menuView === 'strip' ? 'Menu Strip' : 'Primary Menu') + '</strong></div>');
  head.querySelector('button').onclick = () => { menuView = null; addingFor = null; document.querySelectorAll('.cz-additems').forEach(x => x.remove()); rerender(); };
  wrap.appendChild(head);
  if (menuView === 'strip') {
    const blk = html('<div class="cz-block"></div>');
    blk.appendChild(cSeg('Links in the strip', () => T.strip_source || 'custom', v => T.strip_source = v, [['categories', 'All categories', 'fa-folder-tree'], ['custom', 'Custom menu', 'fa-list']], rerender));
    blk.appendChild(cSeg('Style', () => T.menu_style, v => T.menu_style = v, [['plain', 'Plain'], ['pills', 'Pills'], ['underline', 'Underline']]));
    blk.appendChild(cSeg('Alignment (desktop)', () => T.menu_align, v => T.menu_align = v, [['left', 'Left', 'fa-align-left'], ['center', 'Center', 'fa-align-center']]));
    blk.appendChild(cToggle('Dark mode icon at the end of the strip', () => T.menu_dark_icon === '1', v => T.menu_dark_icon = v ? '1' : '0'));
    wrap.appendChild(blk);
    if (T.strip_source === 'categories') { wrap.appendChild(hintEl('Every category is listed automatically, newest categories included. Switch to “Custom menu” to choose the links yourself.')); return; }
    menuEditor(wrap, () => T.strip_items, v => T.strip_items = v, false, 'strip');
  } else {
    menuEditor(wrap, () => T.menu, v => T.menu = v, true, 'primary');
  }
}

/* ---------- Footer ---------- */
function renderFooter(wrap) {
  const T = state.theme;
  if (!Array.isArray(T.f_columns)) T.f_columns = [];
  wrap.appendChild(sectionEl('About', b => {
    b.appendChild(field(['media', 'f_logo', 'Footer logo', { hint: 'Empty = the site title as text.' }]));
    b.appendChild(field(['textarea', 'f_desc', 'Description', { hint: 'HTML and shortcodes allowed. Empty = the footer text from General Settings.' }]));
    b.appendChild(field(['toggle', 'f_play', 'Show “Get it on Google Play” badge', { hint: 'Shown under the description.' }]));
    b.appendChild(field(['text', 'f_play_url', 'Google Play link', { placeholder: 'https://play.google.com/store/apps/details?id=…', hint: 'Optional. Empty = the badge shows without a link.' }]));
  }, true));
  wrap.appendChild(sectionEl('Link columns', b => {
    T.f_columns.forEach((col, i) => {
      const cb = html('<div class="cz-colbox"><div class="cz-item-row"><input class="cz-inline-input" placeholder="Column heading"></div></div>');
      const ti = cb.querySelector('input'); ti.value = col.title || ''; ti.oninput = () => { col.title = ti.value; touch(); };
      cb.querySelector('.cz-item-row').appendChild(circleBtn('fa-trash-can', 'Remove column', () => { T.f_columns.splice(i, 1); touch(); rerender(); }, true));
      cb.appendChild(cToggle('List the most-read categories automatically', () => col.auto === 'categories', v => { col.auto = v ? 'categories' : ''; if (!Array.isArray(col.links)) col.links = []; rerender(); }));
      if (col.auto === 'categories') cb.appendChild(cRange('How many categories', () => col.count || 6, v => col.count = v, 1, 20));
      else { if (!Array.isArray(col.links)) col.links = []; menuEditor(cb, () => col.links, v => col.links = v, false, 'fcol' + i); }
      b.appendChild(cb);
    });
    if (T.f_columns.length < 4) {
      const add = html('<button type="button" class="cz-btn-outline"><i class="fas fa-plus"></i> Add column</button>');
      add.onclick = () => { T.f_columns.push({ title: 'Links', auto: '', count: 6, links: [] }); touch(); rerender(); };
      b.appendChild(add);
    }
  }, true, String(T.f_columns.length)));
  wrap.appendChild(sectionEl('Follow Us box', b => {
    [['text', 'f_follow_heading', 'Heading'], ['toggle', 'f_follow', 'Show the box'], ['text', 'f_follow_title', 'Box title'], ['text', 'f_follow_sub', 'Box subtitle'], ['text', 'f_follow_btn', 'Button text (optional)'], ['text', 'f_follow_btn_url', 'Button link']].forEach(f => b.appendChild(field(f)));
    b.appendChild(html('<p class="cz-hint">The social icons come from <a href="/admin/general-settings.php#social" target="_blank">General Settings → Social</a> and are also used in the header and the mobile menu.</p>'));
  }, true));
  wrap.appendChild(sectionEl('Copyright', b => b.appendChild(field(['text', 'f_copyright', 'Copyright text', { hint: 'Shortcodes: [year] [site_title] [site_link]' }])), true));
}

/* ---------- Also Read ---------- */
const AR_STYLES = [['card', 'Embed card', 'Image, title, excerpt. Several posts = swipe slider with a position bar.'], ['accent', 'Highlight bar', 'Colored box with the post titles listed.'], ['minimal', 'Minimal line', 'Simple list of titles between two lines.']];
let arOpen = undefined, arTitles = {};
function renderAlsoRead(wrap) {
  const T = state.theme;
  if (!Array.isArray(T.ar_groups)) T.ar_groups = [];
  if (arOpen === undefined) arOpen = T.ar_groups[0] ? T.ar_groups[0].id : null;
  wrap.appendChild(cToggle('Show “Also Read” boxes inside articles', () => T.ar_enabled === '1', v => T.ar_enabled = v ? '1' : '0'));
  wrap.appendChild(hintEl('Each box appears after the paragraph you choose and holds all of its posts in one container. A paragraph number larger than the article is skipped.'));
  const need = [...new Set(T.ar_groups.flatMap(g => g.post_ids || []))].filter(id => !arTitles[id]);
  if (need.length) fetch('/admin/customize.php?titles=' + need.join(','), { credentials: 'same-origin' }).then(r => r.json()).then(t => { Object.assign(arTitles, t); need.forEach(id => arTitles[id] = arTitles[id] || 'Post #' + id); rerender(); });
  T.ar_groups.forEach((g, i) => {
    const it = html('<div class="cz-item cz-ar' + (arOpen === g.id ? ' open' : '') + '"><div class="cz-item-row"><span class="cz-item-title"></span></div></div>');
    const st = AR_STYLES.find(s => s[0] === g.style);
    it.querySelector('.cz-item-title').textContent = 'Box ' + (i + 1) + ': after paragraph ' + g.after + ' · ' + (g.source === 'manual' ? (g.post_ids || []).length : g.count) + ' post' + ((g.source === 'manual' ? (g.post_ids || []).length : g.count) === 1 ? '' : 's') + ' · ' + (st ? st[1] : '');
    const row = it.querySelector('.cz-item-row');
    row.appendChild(circleBtn(arOpen === g.id ? 'fa-chevron-up' : 'fa-chevron-down', 'Edit', () => { arOpen = arOpen === g.id ? null : g.id; rerender(); }));
    row.appendChild(circleBtn('fa-trash-can', 'Delete box', () => { T.ar_groups = T.ar_groups.filter(x => x.id !== g.id); touch(); rerender(); }, true));
    if (arOpen === g.id) {
      const card = html('<div class="cz-item-card"></div>');
      card.appendChild(cRange('Show after paragraph', () => g.after, v => g.after = v, 1, 40));
      card.appendChild(cText('Label', () => g.label, v => g.label = v));
      card.appendChild(cSeg('Which posts', () => g.source, v => g.source = v, [['category', 'Same category'], ['latest', 'Latest'], ['random', 'Random'], ['manual', 'Pick posts']], rerender));
      if (g.source === 'manual') card.appendChild(postPicker(g));
      else card.appendChild(cRange('Number of posts', () => g.count, v => g.count = v, 1, 12));
      card.appendChild(html('<span class="cz-label">Design</span>'));
      const opts = html('<div class="cz-also"></div>');
      AR_STYLES.forEach(([v, l, d]) => {
        const b = html('<button type="button" class="cz-also-opt' + (g.style === v ? ' active' : '') + '"><span class="cz-also-demo cz-also-demo--' + v + '" aria-hidden="true"><i></i><b></b><u></u></span><strong>' + esc(l) + '</strong><small>' + esc(d) + '</small></button>');
        b.onclick = () => { g.style = v; touch(); rerender(); }; opts.appendChild(b);
      });
      card.appendChild(opts);
      it.appendChild(card);
    }
    wrap.appendChild(it);
  });
  const add = html('<button type="button" class="cz-btn-primary"><i class="fas fa-plus"></i> Add “Also Read” box</button>');
  add.onclick = () => {
    const last = T.ar_groups[T.ar_groups.length - 1], g = { id: 'ar' + Date.now().toString(36), after: Math.min(40, (last ? last.after : 0) + 4), count: 3, source: 'category', post_ids: [], style: 'card', label: 'Also Read' };
    T.ar_groups.push(g); arOpen = g.id; touch(); rerender();
  };
  wrap.appendChild(add);
}
function postPicker(g) {
  if (!Array.isArray(g.post_ids)) g.post_ids = [];
  const w = html('<div class="cz-field"><span class="cz-label">Posts (' + g.post_ids.length + ')</span><div class="cz-picked"></div><input placeholder="Search posts to add…"><div class="cz-ai-list cz-ai-list--compact"></div></div>');
  const picked = w.querySelector('.cz-picked');
  g.post_ids.forEach((id, i) => {
    const p = html('<div class="cz-picked-item"><span></span><button type="button" title="Move up"' + (i === 0 ? ' disabled' : '') + '><i class="fas fa-arrow-up"></i></button><button type="button" title="Remove"><i class="fas fa-xmark"></i></button></div>');
    p.querySelector('span').textContent = arTitles[id] || 'Post #' + id;
    const [up, rm] = p.querySelectorAll('button');
    up.onclick = () => { if (i > 0) { [g.post_ids[i - 1], g.post_ids[i]] = [g.post_ids[i], g.post_ids[i - 1]]; touch(); rerender(); } };
    rm.onclick = () => { g.post_ids.splice(i, 1); g.count = Math.max(1, g.post_ids.length); touch(); rerender(); };
    picked.appendChild(p);
  });
  const q = w.querySelector('input'), hits = w.querySelector('.cz-ai-list');
  const search = () => fetch('/admin/customize.php?posts_brief=' + encodeURIComponent(q.value.trim()), { credentials: 'same-origin' }).then(r => r.json()).then(rows => {
    hits.innerHTML = '';
    rows.filter(h => !g.post_ids.includes(h.id)).slice(0, 8).forEach(h => {
      const b = html('<button type="button"><i class="fas fa-plus"></i><span></span></button>'); b.querySelector('span').textContent = h.title;
      b.onclick = () => { arTitles[h.id] = h.title; g.post_ids.push(h.id); g.count = Math.max(1, g.post_ids.length); touch(); rerender(); };
      hits.appendChild(b);
    });
  });
  let t; q.oninput = () => { clearTimeout(t); t = setTimeout(search, 250); };
  search();
  return w;
}

/* ---------- Shortcodes ---------- */
function renderShortcodes(wrap) {
  const box = html('<div class="cz-codes"></div>');
  box.appendChild(hintEl('Use these anywhere: posts, pages, the footer, the top bar, SEO titles and descriptions. Click to copy.'));
  SHORTCODES.forEach(([code, desc]) => {
    const b = html('<button type="button" class="cz-code"><code></code><span></span></button>');
    b.querySelector('code').textContent = code; b.querySelector('span').textContent = desc;
    b.onclick = () => {
      const done = () => { b.querySelector('span').textContent = 'Copied!'; setTimeout(() => b.querySelector('span').textContent = desc, 1200); };
      if (navigator.clipboard) navigator.clipboard.writeText(code).then(done, done); else { const ta = document.createElement('textarea'); ta.value = code; document.body.appendChild(ta); ta.select(); try { document.execCommand('copy'); } catch (e) {} ta.remove(); done(); }
    };
    box.appendChild(b);
  });
  wrap.appendChild(box);
}

function showHome() {
  panel = null; addingFor = null; document.querySelectorAll('.cz-additems').forEach(x => x.remove()); $('cz-back').hidden = true; $('cz-head-small').textContent = 'You are customizing'; $('cz-head-title').textContent = <?= json_encode(edu_site_name()) ?>;
  const b = $('cz-body'); b.innerHTML = '';
  const nav = document.createElement('nav'); nav.className = 'cz-nav';
  PANELS.forEach(p => {
    const btn = document.createElement('button'); btn.type = 'button';
    btn.innerHTML = '<span class="cz-nav-ico"><i class="fas ' + p.icon + '"></i></span><span class="cz-nav-text"><strong>' + esc(p.label) + '</strong><small>' + esc(p.sub) + '</small></span><i class="fas fa-chevron-right"></i>';
    btn.onclick = () => showPanel(p); nav.appendChild(btn);
  });
  b.appendChild(nav);
  const r = document.createElement('div'); r.className = 'cz-dreset';
  r.innerHTML = '<strong>Reset design</strong><small>All Customizer settings back to the default theme. Posts, pages, media, push and SEO are not touched.</small><button type="button" class="cz-dreset-btn"><i class="fas fa-rotate-left"></i> Reset to default</button>' + (HAS_BACKUP ? '<button type="button" class="cz-dreset-undo">Undo the last reset' + (BACKUP_AT ? ' (' + new Date(BACKUP_AT).toLocaleString() + ')' : '') + '</button>' : '');
  r.querySelector('.cz-dreset-btn').onclick = () => {
    if (!confirm('Reset every Customizer setting to the default design?\n\nPosts, pages, media, push and SEO are not touched. You can undo this afterwards.')) return;
    post('reset').then(() => location.reload());
  };
  const u = r.querySelector('.cz-dreset-undo');
  if (u) u.onclick = () => { if (confirm('Restore the design from before the last reset?')) post('undo_reset').then(() => location.reload()); };
  b.appendChild(r);
}
function showPanel(p, keep) {
  if (!keep) { menuView = null; miOpen = null; addingFor = null; arOpen = undefined; openFont = null; openRule = null; gpalEditing = null; }
  if (!addingFor) document.querySelectorAll('.cz-additems').forEach(x => x.remove());
  panel = p; $('cz-back').hidden = false; $('cz-head-small').textContent = 'Customizing'; $('cz-head-title').textContent = p.label;
  const b = $('cz-body'); b.innerHTML = '';
  const wrap = document.createElement('div'); wrap.className = 'cz-panel-fields';
  if (p.fields) p.fields.forEach(f => wrap.appendChild(field(f)));
  if (p.sections) p.sections.forEach((s, i) => wrap.appendChild(section(s[0], s[1], i === 0)));
  if (p.render) p.render(wrap);
  b.appendChild(wrap);
  if (keep) return;
  if (p.id === 'post' || p.id === 'alsoread' || p.id === 'progress' || p.id === 'sidebar' || p.id === 'typography') goTo(<?= json_encode($latestUrl) ?>);
  else if (p.id === 'home') goTo('/');
}
function goTo(path) { if (path === framePath) return; framePath = path; reloadFrame(); }
$('cz-back').onclick = showHome;
document.querySelectorAll('.cz-preview-bar [data-path]').forEach(b => b.onclick = () => { framePath = ''; goTo(b.dataset.path); });
$('cz-reload').onclick = reloadFrame;
$('cz-publish').onclick = () => {
  $('cz-publish').disabled = true; $('cz-publish').textContent = 'Publishing…';
  post('publish').then(r => { if (r.ok) { dirty = false; $('cz-status').textContent = 'All changes published'; $('cz-publish').textContent = 'Published'; } else { $('cz-publish').disabled = false; $('cz-publish').textContent = 'Publish'; alert('Could not publish. Please try again.'); } });
};
$('cz-hide').onclick = () => { $('cz-root').classList.add('cz-collapsed'); $('cz-show').hidden = false; };
$('cz-show').onclick = () => { $('cz-root').classList.remove('cz-collapsed'); $('cz-show').hidden = true; };
document.querySelectorAll('#cz-devices button').forEach(b => b.onclick = () => setDevice(b.dataset.dev));
window.addEventListener('beforeunload', e => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
showHome();
</script>
</body>
</html>
