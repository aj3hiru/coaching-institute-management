<?php
// SEO — laid out like Rank Math: Dashboard (modules), General Settings, Titles & Meta, Sitemap Settings, Instant Indexing,
// SEO Analyzer and Status & Tools (Redirections and the 404 Monitor live in Admin → Redirections).
// The first visit opens the Setup Wizard.
require_once __DIR__ . '/components/guard.php';
require_once INCLUDES_PATH . '/seo.php';
require_once INCLUDES_PATH . '/indexing.php';
$me = admin_guard(['admin']);

if (isset($_GET['skip'])) { edu_settings_save('seo', ['wizard_done' => '1']); header('Location: /admin/seo.php'); exit; }
if (seo_opt('wizard_done') !== '1') { header('Location: /admin/seo-wizard.php'); exit; }
$D = edu_seo_defaults();
$base = rtrim(SITE_URL, '/');

// ── Modules (Dashboard cards) ─────────────────────────────────────────────────────────────────────────────────────
const SEO_MODULES = [
    'sitemap' => ['Sitemap', 'fa-sitemap', '#16a34a', '#ecfdf5', 'Enable the XML sitemap index (/sitemap_index.xml), which helps search engines intelligently crawl your website’s content.', 'sitemap'],
    'news_sitemap' => ['News Sitemap', 'fa-newspaper', '#2563eb', '#eff6ff', 'Create a News Sitemap for your news-related content. You only need it if you post news and are (or plan to be) in Google News.', 'sitemap&tab=news'],
    'schema' => ['Schema (Structured Data)', 'fa-diagram-project', '#b45309', '#fff7ed', 'Adds Schema code (Organization, Article, Person, FAQ …) to your website, resulting in rich search results, better CTR and more traffic.', 'titles&tab=posts'],
    'instant_indexing' => ['Instant Indexing', 'fa-bolt', '#7c3aed', '#f5f3ff', 'Directly notify search engines like Bing & Yandex using the IndexNow API (and Google with the Indexing API) when pages are added, updated and removed.', 'indexing'],
    'redirections' => ['Redirections', 'fa-route', '#ea580c', '#fff7ed', 'Redirect non-existent content easily with 301 and 302 status codes, for all visitors or only some countries.', '../redirections.php'],
    '404' => ['404 Monitor', 'fa-triangle-exclamation', '#dc2626', '#fef2f2', 'Records the URLs on which visitors & search engines run into 404 Errors. You can then redirect them.', '../redirections.php?tab=monitor'],
    'image_seo' => ['Image SEO', 'fa-images', '#0891b2', '#ecfeff', 'Automatically adds missing ALT and TITLE text to the images in your posts on the fly.', 'general&tab=images'],
    'analyzer' => ['SEO Analyzer', 'fa-magnifying-glass-chart', '#db2777', '#fdf2f8', 'Let the site analyse itself and your content against more than 10 checks to provide tailor-made SEO advice.', 'analyzer'],
    'role_manager' => ['Role Manager', 'fa-user-shield', '#4f46e5', '#eef2ff', 'Control which roles (editors, authors) see the SEO box in the post editor.', 'general&tab=roles'],
    'link_counter' => ['Link Counter', 'fa-link', '#0d9488', '#f0fdfa', 'Counts the internal and external links inside your posts, shown in the post editor’s SEO checks.', ''],
];

// ── Settings screens: view → tab → [title, description, icon, fields] ──────────────────────────────────────────────
$sepOpts = array_combine(['-', '–', '—', '»', '|', '•', '·', '/'], ['-', '–', '—', '»', '|', '•', '·', '/']);
$robotsSel = ['default' => 'Default (from Global Meta)', 'index' => 'Index', 'noindex' => 'No Index'];
$artTypes = ['NewsArticle' => 'News Article', 'BlogPosting' => 'Blog Post', 'Article' => 'Article'];
$vars = 'Variables: <code>%title%</code> <code>%sep%</code> <code>%sitename%</code> <code>%excerpt%</code> <code>%category%</code> <code>%focuskw%</code> <code>%currentyear%</code>';
$termVars = 'Variables: <code>%term%</code> <code>%term_description%</code> <code>%sep%</code> <code>%sitename%</code> <code>%page%</code>';
$SCREENS = [
    'general' => ['General Settings', [
        'links' => ['Links', 'Change how some of the links open and operate on your website.', 'fa-link', [
            ['nofollow_external', 'toggle', 'Nofollow External Links', 'Automatically add <code>rel="nofollow"</code> attribute for external links appearing in your posts and pages. The attribute is applied when the content is displayed — the stored content is not changed.'],
            ['nofollow_image_links', 'toggle', 'Nofollow Image File Links', 'Automatically add <code>rel="nofollow"</code> attribute for links pointing to external image files.'],
            ['external_blank', 'toggle', 'Open External Links in New Tab/Window', 'Automatically add a <code>target="_blank"</code> attribute to external links appearing in your posts and pages.'],
            ['affiliate_prefix', 'textarea', 'Affiliate Link Prefix', 'Add the URI prefixes you use for affiliate (cloaked) links, which redirect to external sites. One per line.'],
        ]],
        'images' => ['Images', 'SEO options related to featured images and media appearing in your post content.', 'fa-image', [
            ['img_alt_missing', 'toggle', 'Add missing ALT attributes', 'Add the post title as <code>alt</code> text to images that have none. Applied when the content is displayed.'],
            ['img_title_missing', 'toggle', 'Add missing TITLE attributes', 'Add the post title as <code>title</code> text to images that have none.'],
        ]],
        'webmaster' => ['Webmaster Tools', 'Enter verification codes for third-party webmaster tools.', 'fa-shield-halved', [
            ['v_google', 'text', 'Google Search Console', 'Enter your Google Search Console verification HTML code or ID. <a href="https://search.google.com/search-console" target="_blank" rel="noopener">Get it from here.</a>'],
            ['v_bing', 'text', 'Bing Webmaster Tools', 'Enter your Bing Webmaster Tools verification HTML code or ID. <a href="https://www.bing.com/webmasters" target="_blank" rel="noopener">Get it from here.</a>'],
            ['v_baidu', 'text', 'Baidu Webmaster Tools', 'Enter your Baidu Webmaster Tools verification HTML code or ID.'],
            ['v_yandex', 'text', 'Yandex Verification ID', 'Enter your Yandex verification HTML code or ID.'],
            ['v_pinterest', 'text', 'Pinterest Verification ID', 'Enter your Pinterest verification HTML code or ID.'],
            ['v_norton', 'text', 'Norton Safe Web Verification ID', 'Enter your Norton Safe Web verification HTML code or ID.'],
        ]],
        'robots' => ['Edit robots.txt', 'Edit your robots.txt file to control what bots see. The “Sitemap:” lines follow the SEO mode automatically.', 'fa-robot', [
            ['robots_txt', 'code', 'robots.txt', 'Leave the “Sitemap:” lines in place so search engines find your sitemap.'],
        ]],
        'roles' => ['Role Manager', 'Choose which roles get the SEO box (title, description, focus keyword, schema) in the post editor. Admins always have it.', 'fa-user-shield', [
            ['role_editor_seo', 'toggle', 'Editors', 'Editors can change a post’s SEO title, description, schema and social preview.'],
            ['role_author_seo', 'toggle', 'Authors', 'Authors can change the SEO box of their own posts.'],
        ]],
    ]],
    'titles' => ['Titles & Meta', [
        'global' => ['Global Meta', 'Change Global meta settings that take effect across your website.', 'fa-gear', [
            ['robots', 'robots', 'Robots Meta', 'Default values for robots meta tag. These can be changed for individual posts and categories.'],
            ['advrobots', 'advrobots', 'Advanced Robots Meta', ''],
            ['noindex_empty', 'toggle', 'Noindex Empty Category and Tag Archives', 'Setting empty archives to <code>noindex</code> is useful for avoiding indexation of thin content pages and dilution of page rank. As soon as a post is added, the page is updated to <code>index</code>.'],
            ['sep', 'seg', 'Separator Character', 'You can use the separator character in titles by inserting <code>%separator%</code> or <code>%sep%</code> in the title fields.', $sepOpts],
            ['capitalize', 'toggle', 'Capitalize Titles', 'Automatically capitalize the first character of each word in the titles.'],
            ['og_image', 'image', 'OpenGraph Thumbnail', 'When a featured image or an OpenGraph Image is not set for individual posts/pages, this image will be used as a fallback thumbnail when your post is shared on Facebook. The recommended image size is 1200 x 630 pixels.'],
            ['twitter_card', 'select', 'Twitter Card Type', 'Card type used for posts and pages.', ['summary_large_image' => 'Summary Card with Large Image', 'summary' => 'Summary Card']],
            ['noindex_site', 'toggle', 'Discourage search engines', '<b style="color:#b91c1c">Keep this off on the live site.</b> Hides the whole site from search engines.'],
        ]],
        'social' => ['Social Meta', 'Add social account information to your website’s Schema and Open Graph.', 'fa-share-nodes', [
            ['fb_page', 'text', 'Facebook Page URL', 'Enter your complete Facebook page URL here. eg: <code>https://www.facebook.com/yourpage/</code>'],
            ['fb_author', 'text', 'Facebook Authorship', 'Insert a personal profile or page URL to show the authorship when your posts are shared on Facebook (an author’s own Facebook link wins).'],
            ['fb_admin', 'text', 'Facebook Admin', 'Enter numeric user ID of Facebook.'],
            ['fb_app_id', 'text', 'Facebook App', 'Enter numeric app ID of Facebook.'],
            ['twitter_site', 'text', 'Twitter Username', 'Enter the Twitter username of the site (without @).'],
        ]],
        'homepage' => ['Homepage', 'Add SEO meta and OpenGraph details to your homepage.', 'fa-house', [
            ['home_title', 'tpl', 'Homepage Title', 'Homepage title tag. Empty = the site’s normal homepage title. ' . $vars],
            ['home_description', 'textarea', 'Homepage Meta Description', 'Homepage meta description. Empty = the site’s normal description.'],
            ['home_og_title', 'text', 'Homepage Title for Facebook', 'Title of your site when shared on Facebook, Twitter and other social networks.'],
            ['home_og_description', 'textarea', 'Homepage Description for Facebook', 'Description of your site when shared on Facebook, Twitter and other social networks.'],
            ['home_og_image', 'image', 'Homepage Thumbnail for Facebook', 'Image displayed when your homepage is shared on Facebook and other social networks. Use images that are at least 1200 x 630 pixels.'],
        ]],
        'authors' => ['Authors', 'Change SEO options related to the author archives.', 'fa-users', [
            ['author_archives', 'toggle', 'Author Archives', 'Author pages (/author/name) can be indexed. Turn off to keep them out of search engines.'],
            ['noindex_authors', 'toggle', 'Author Robots Meta: No Index', 'Hide author pages from search results (and from the sitemap).'],
            ['author_title', 'tpl', 'Author Archive Title', 'Title tag on author archives. Variables: <code>%name%</code> <code>%sep%</code> <code>%sitename%</code> <code>%page%</code>'],
            ['author_description', 'text', 'Author Archive Description', 'Variables: <code>%name%</code> <code>%user_description%</code> (the author’s bio)'],
        ]],
        'misc' => ['Misc Pages', 'Customize SEO meta settings of pages like search results, 404s, etc.', 'fa-ellipsis', [
            ['search_title', 'tpl', 'Search Results Title', 'Title tag on search results page. Variables: <code>%search_query%</code> <code>%sep%</code> <code>%sitename%</code>'],
            ['title_404', 'tpl', '404 Title', 'Title tag on 404 Not Found error page.'],
            ['noindex_search', 'toggle', 'Noindex Search Results', 'Prevent search results pages from getting indexed by search engines.'],
            ['noindex_paged', 'toggle', 'Noindex Paginated Pages', 'Set this to on to prevent /page/2 and further of any archive to show up in the search results.'],
        ]],
        'posts' => ['Posts', 'Change Global SEO, Schema, and other settings for single posts.', 'fa-file-lines', [
            ['post_format', 'tpl', 'Single Post Title', 'Default title tag for single post pages. This can be changed on a per-post basis in the post editor. ' . $vars],
            ['post_description', 'tpl', 'Single Post Description', 'Default description for single post pages (used when the post has none of its own). ' . $vars],
            ['schema_default', 'seg', 'Article Type', 'Default rich snippet type for posts (each post can change it in the editor’s Schema tab).', $artTypes],
        ]],
        'pages' => ['Pages', 'Change Global SEO, Schema, and other settings for Pages (Admin → Pages).', 'fa-file', [
            ['page_format', 'tpl', 'Single Page Title', 'Default title tag for pages. A page’s own SEO title wins. ' . $vars],
            ['page_description', 'tpl', 'Single Page Description', 'Default description for pages. ' . $vars],
        ]],
        'categories' => ['Categories', 'Change SEO options related to categories.', 'fa-folder', [
            ['term_format', 'tpl', 'Category Archive Titles', 'Title tag for category pages (a category’s own SEO title in Admin → Categories wins). ' . $termVars],
            ['cat_description', 'tpl', 'Category Archive Descriptions', 'Description for category pages (a category’s own SEO description wins). ' . $termVars],
            ['noindex_categories', 'toggle', 'Category Robots Meta: No Index', 'Hide category pages from search results.'],
        ]],
        'tags' => ['Tags', 'Change SEO options related to tags.', 'fa-tags', [
            ['tag_format', 'tpl', 'Tag Archive Titles', 'Title tag for tag pages. ' . $termVars],
            ['tag_description', 'tpl', 'Tag Archive Descriptions', 'Description for tag pages. ' . $termVars],
            ['noindex_tags', 'toggle', 'Tag Robots Meta: No Index', 'Hide tag pages from search results (and from the sitemap).'],
        ]],
    ]],
    'sitemap' => ['Sitemap Settings', [
        'general' => ['General', 'This tab contains General settings related to the XML sitemaps.', 'fa-gear', [
            ['__sminfo', 'info', '', ''],
            ['sm_per', 'number', 'Links Per Sitemap', 'Max number of links on each sitemap page.'],
            ['sm_images', 'toggle', 'Images in Sitemaps', 'Include reference to images from the post content in sitemaps. This helps search engines index the important images on your pages.'],
            ['sm_featured', 'toggle', 'Include Featured Images', 'Include the Featured Image too, even if it does not appear directly in the post content.'],
            ['sm_exclude_posts', 'text', 'Exclude Posts', 'Enter post IDs of posts you want to exclude from the sitemap, separated by commas.'],
            ['sm_exclude_terms', 'text', 'Exclude Terms', 'Add category / tag IDs, separated by comma.'],
        ]],
        'posts' => ['Posts', 'Change Sitemap settings of Posts.', 'fa-file-lines', [['sm_posts', 'toggle', 'Include in Sitemap', 'Include Posts in the XML Sitemap (post-sitemap.xml).']]],
        'pages' => ['Pages', 'Change Sitemap settings of Pages.', 'fa-file', [['sm_pages', 'toggle', 'Include in Sitemap', 'Include the homepage, Pages and main lists in the XML Sitemap (page-sitemap.xml).']]],
        'categories' => ['Categories', 'Change Sitemap settings of Categories.', 'fa-folder', [['sm_categories', 'toggle', 'Include in Sitemap', 'Include category pages in the XML Sitemap (category-sitemap.xml). Empty categories are left out.']]],
        'tags' => ['Tags', 'Change Sitemap settings of Tags.', 'fa-tags', [['sm_tags', 'toggle', 'Include in Sitemap', 'Include tag pages in the XML Sitemap (post_tag-sitemap.xml).']]],
        'authors' => ['Authors', 'Set the sitemap options for author archive pages.', 'fa-users', [['sm_authors', 'toggle', 'Include in Sitemap', 'Include author archives in the XML sitemap (author-sitemap.xml).']]],
        'states' => ['State Jobs', 'Set the sitemap options for state job pages.', 'fa-map-location-dot', [['sm_states', 'toggle', 'Include in Sitemap', 'Include state job pages in the XML sitemap (state-sitemap.xml).']]],
        'news' => ['News Sitemap', 'Google News sitemap (news-sitemap.xml): posts of the last 2 days.', 'fa-newspaper', [
            ['news_name', 'text', 'Google News Publication Name', 'The name of the news publication. It must match the name exactly as it appears on your articles in news.google.com. Empty = the site name.'],
            ['news_language', 'select', 'Language', 'Language of your news articles.', ['en' => 'English', 'hi' => 'Hindi', 'bn' => 'Bengali', 'mr' => 'Marathi', 'ta' => 'Tamil', 'te' => 'Telugu', 'gu' => 'Gujarati', 'kn' => 'Kannada', 'ml' => 'Malayalam', 'pa' => 'Punjabi', 'ur' => 'Urdu']],
            ['news_exclude_cats', 'text', 'Exclude Categories', 'Category IDs to leave out of the news sitemap, separated by comma.'],
        ]],
    ]],
];

// ── Saving ─────────────────────────────────────────────────────────────────────────────────────────────────────────
function seo_field_value(array $f, string $D)
{
    [$k, $type] = $f;
    $v = $_POST[$k] ?? null;
    return match ($type) {
        'toggle' => !empty($v) ? '1' : '0',
        'number' => (string)max(1, min(50000, (int)$v ?: (int)$D)),
        'select', 'seg' => array_key_exists((string)$v, $f[4] ?? []) ? (string)$v : $D,
        'textarea' => mb_substr(trim(str_replace("\r\n", "\n", (string)$v)), 0, 2000),
        default => mb_substr(trim((string)$v), 0, 500),
    };
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $view = preg_replace('/[^a-z]/', '', $_POST['view'] ?? 'dashboard');
    $tab = preg_replace('/[^a-z]/', '', $_POST['tab'] ?? '');
    $act = $_POST['action'] ?? 'save';
    $back = '/admin/seo.php?view=' . $view . ($tab ? '&tab=' . $tab : '');
    if ($act === 'module') {
        $m = (string)($_POST['module'] ?? '');
        if (isset(SEO_MODULES[$m])) edu_settings_save('seo', ['m_' . $m => !empty($_POST['on']) ? '1' : '0']);
        header('Content-Type: application/json'); exit('{"ok":true}');
    }
    if ($act === 'mode') {
        $mode = ($_POST['mode'] ?? '') === 'original' ? 'original' : 'advanced';
        edu_settings_save('seo', ['mode' => $mode]);
        seo_robots_sync($mode);
        admin_log('seo_update', 'SEO mode: ' . ($mode === 'original' ? 'Basic SEO' : 'Advanced SEO'));
        admin_flash($mode === 'original' ? 'Basic SEO is on — the site uses exactly its original SEO and /sitemap.xml.' : 'Advanced SEO is on — Rank Math-style titles, schema and the /sitemap_index.xml sitemap are used.');
        header('Location: ' . ($_POST['back'] ?? '/admin/seo.php')); exit;
    }
    if ($act === 'submit') {
        $urls = array_values(array_filter(array_map('trim', preg_split('/\R/', (string)($_POST['urls'] ?? '')))));
        $urls = array_slice(array_filter($urls, fn($u) => preg_match('#^https?://#', $u) || str_starts_with($u, '/')), 0, 10000);
        $n = edu_ix_queue($pdo, $urls, ($_POST['ix_action'] ?? '') === 'delete' ? 'delete' : 'update', true);
        $res = $n ? edu_ix_process($pdo, 500) : '';
        admin_log('seo_update', 'Instant indexing: submitted ' . count($urls) . ' URL(s)');
        admin_flash($n ? 'Submitted — ' . $res . '.' : 'Nothing was submitted. Turn on IndexNow or add a Google key in Settings, and enter full URLs.', $n ? 'success' : 'error');
        header('Location: /admin/seo.php?view=indexing&tab=' . ($n ? 'history' : 'submit')); exit;
    }
    if ($act === 'ixsettings') {
        $save = ['ix_auto' => !empty($_POST['ix_auto']) ? '1' : '0', 'ix_indexnow' => !empty($_POST['ix_indexnow']) ? '1' : '0'];
        if (!empty($_POST['ix_newkey'])) $save['ix_indexnow_key'] = '';
        $json = trim((string)($_POST['ix_google_json'] ?? ''));
        if (!empty($_POST['ix_google_remove'])) $save['ix_google_json'] = '';
        elseif ($json !== '') {
            $j = json_decode($json, true);
            if (!is_array($j) || empty($j['client_email']) || empty($j['private_key'])) { admin_flash('That is not a Google service-account key (JSON with client_email and private_key).', 'error'); header('Location: /admin/seo.php?view=indexing&tab=settings'); exit; }
            $save['ix_google_json'] = json_encode(['client_email' => $j['client_email'], 'private_key' => $j['private_key'], 'project_id' => $j['project_id'] ?? '']);
        }
        if (!empty($save['ix_indexnow_key']) || isset($save['ix_indexnow_key'])) { $old = (string)seo_opt('ix_indexnow_key'); if ($old) foreach ([ROOT_PATH . "/$old.txt", ROOT_PATH . "/assets/indexnow/$old.txt"] as $kf) if (is_file($kf)) @unlink($kf); }
        edu_settings_save('seo', $save);
        if ($save['ix_indexnow'] === '1') edu_ix_key();
        admin_flash('Instant Indexing settings saved.');
        header('Location: /admin/seo.php?view=indexing&tab=settings'); exit;
    }
    if ($act === 'ixclear') { edu_ix_table($pdo); $pdo->exec("DELETE FROM seo_index_log"); admin_flash('History cleared.'); header('Location: /admin/seo.php?view=indexing&tab=history'); exit; }
    if ($act === 'tool') {
        $t = $_POST['tool'] ?? '';
        if ($t === 'clear404') { @unlink(ROOT_PATH . '/cache/.404-log.json'); admin_flash('404 log cleared.'); }
        elseif ($t === 'clearhits') { @unlink(ROOT_PATH . '/cache/.redirect-hits.json'); admin_flash('Redirection hit counts reset.'); }
        elseif ($t === 'clearix') { edu_ix_table($pdo); $pdo->exec("DELETE FROM seo_index_log"); admin_flash('Instant indexing history cleared.'); }
        elseif ($t === 'resetall') { $keep = array_intersect_key(edu_settings('seo'), array_flip(['wizard_done', 'mode', 'ix_indexnow_key', 'ix_google_json', 'v_google', 'v_bing', 'v_yandex', 'v_pinterest', 'v_baidu', 'v_norton'])); $pdo->prepare("UPDATE app_config SET config_value = ? WHERE config_key = 'edu_seo'")->execute([json_encode($keep)]); edu_clear_page_cache(); admin_flash('All SEO settings were reset to their defaults (mode, verification codes and keys were kept).'); }
        elseif ($t === 'export') { header('Content-Type: application/json'); header('Content-Disposition: attachment; filename="seo-settings-' . date('Y-m-d') . '.json"'); $out = edu_settings('seo'); unset($out['ix_google_json']); echo json_encode(['type' => 'edumint-seo-settings', 'version' => 1, 'settings' => $out], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
        elseif ($t === 'import') {
            $j = json_decode((string)@file_get_contents($_FILES['file']['tmp_name'] ?? ''), true);
            if (($j['type'] ?? '') !== 'edumint-seo-settings' || !is_array($j['settings'] ?? null)) admin_flash('This is not an SEO settings file exported from here.', 'error');
            else { $in = array_intersect_key(array_map(fn($v) => is_scalar($v) ? (string)$v : '', $j['settings']), $D); unset($in['ix_google_json']); edu_settings_save('seo', $in); admin_flash(count($in) . ' SEO settings imported.'); }
        }
        admin_log('seo_update', 'SEO Status & Tools: ' . $t);
        header('Location: /admin/seo.php?view=status&tab=' . ($t === 'import' || $t === 'export' ? 'importexport' : 'tools')); exit;
    }
    // Settings screens
    $fields = $SCREENS[$view][1][$tab][3] ?? [];
    $save = [];
    foreach ($fields as $f) {
        if ($f[1] === 'info') continue;
        if ($act === 'reset') {
            if ($f[1] === 'robots') foreach (['robots_index', 'robots_nofollow', 'robots_noarchive', 'robots_noimageindex', 'robots_nosnippet'] as $k) $save[$k] = $D[$k];
            elseif ($f[1] === 'advrobots') foreach (['adv_snippet', 'adv_video', 'adv_image'] as $k) $save[$k] = $D[$k];
            elseif ($f[1] !== 'code') $save[$f[0]] = $D[$f[0]] ?? '';
            continue;
        }
        if ($f[1] === 'robots') {
            $save['robots_index'] = ($_POST['robots_index'] ?? '') === 'noindex' ? 'noindex' : 'index';
            foreach (['robots_nofollow', 'robots_noarchive', 'robots_noimageindex', 'robots_nosnippet'] as $k) $save[$k] = !empty($_POST[$k]) ? '1' : '0';
        } elseif ($f[1] === 'advrobots') {
            $save['adv_snippet'] = !empty($_POST['adv_snippet_on']) ? (string)(int)($_POST['adv_snippet'] ?? -1) : '-1';
            $save['adv_video'] = !empty($_POST['adv_video_on']) ? (string)(int)($_POST['adv_video'] ?? -1) : '-1';
            $save['adv_image'] = in_array($_POST['adv_image'] ?? '', ['large', 'standard', 'none'], true) ? $_POST['adv_image'] : 'large';
        } elseif ($f[1] === 'code') {
            if (@file_put_contents(ROOT_PATH . '/robots.txt', rtrim(str_replace("\r\n", "\n", (string)($_POST['robots_txt'] ?? ''))) . "\n") === false) { admin_flash('robots.txt could not be saved (file permissions).', 'error'); header('Location: ' . $back); exit; }
        } else {
            $save[$f[0]] = seo_field_value($f, (string)($D[$f[0]] ?? ''));
        }
    }
    if ($save) edu_settings_save('seo', $save);
    if ($view === 'sitemap') { require_once INCLUDES_PATH . '/sitemaps.php'; }
    admin_log('seo_update', ($act === 'reset' ? 'Reset' : 'Saved') . ' SEO settings: ' . ($SCREENS[$view][0] ?? $view) . ' → ' . $tab);
    admin_flash($act === 'reset' ? 'Options reset to their defaults.' : 'Settings saved.');
    header('Location: ' . $back);
    exit;
}

/** robots.txt follows the SEO mode (the right sitemap address). */
function seo_robots_sync(string $mode): void
{
    $f = ROOT_PATH . '/robots.txt';
    $txt = is_file($f) ? (string)file_get_contents($f) : "User-agent: *\nAllow: /\n";
    $b = rtrim(SITE_URL, '/');
    $lines = ["Sitemap: $b/" . ($mode === 'advanced' ? 'sitemap_index.xml' : 'sitemap.xml'), "Sitemap: $b/news-sitemap.xml"];
    $txt = rtrim(preg_replace('/^\s*Sitemap:.*$\R?/mi', '', $txt)) . "\n\n" . implode("\n", $lines) . "\n";
    @file_put_contents($f, preg_replace("/\n{3,}/", "\n\n", $txt));
}

$view = preg_replace('/[^a-z]/', '', $_GET['view'] ?? ($_GET['tab'] ?? 'dashboard'));
if (!in_array($view, ['dashboard', 'general', 'titles', 'sitemap', 'indexing', 'analyzer', 'status'], true)) $view = 'dashboard';
$adv = edu_seo_advanced();
$o = fn($k) => htmlspecialchars((string)seo_opt($k), ENT_QUOTES, 'UTF-8');
$esc = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$viewNames = ['dashboard' => 'Dashboard', 'general' => 'General Settings', 'titles' => 'Titles & Meta', 'sitemap' => 'Sitemap Settings', 'indexing' => 'Instant Indexing', 'analyzer' => 'SEO Analyzer', 'status' => 'Status & Tools'];

/** One settings row. */
function seo_row(array $f, callable $o): string
{
    [$k, $type, $label, $desc] = $f + [3 => ''];
    $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $d = $desc !== '' ? '<p class="rm-desc">' . $desc . '</p>' : '';
    $base = rtrim(SITE_URL, '/');
    switch ($type) {
        case 'info':
            return '<div class="rm-info">Your sitemap index can be found here: <a href="' . $base . '/sitemap_index.xml" target="_blank">' . $base . '/sitemap_index.xml</a>' . (edu_seo_advanced() ? '' : ' <b>(only in Advanced SEO mode — Original mode uses /sitemap.xml)</b>') . '</div>';
        case 'toggle':
            return '<div class="rm-row"><div class="lbl">' . $e($label) . '</div><div><label class="rm-sw"><input type="checkbox" name="' . $k . '" value="1"' . (seo_opt($k) === '1' ? ' checked' : '') . '><span></span></label>' . $d . '</div></div>';
        case 'textarea':
            return '<div class="rm-row"><label for="f-' . $k . '">' . $e($label) . '</label><div><textarea class="rm-in" id="f-' . $k . '" name="' . $k . '" rows="3">' . $o($k) . '</textarea>' . $d . '</div></div>';
        case 'number':
            return '<div class="rm-row"><label for="f-' . $k . '">' . $e($label) . '</label><div><input type="number" class="rm-in" id="f-' . $k . '" name="' . $k . '" value="' . $o($k) . '" min="1" style="max-width:220px">' . $d . '</div></div>';
        case 'select':
            $h = '<select class="rm-in" id="f-' . $k . '" name="' . $k . '">';
            foreach ($f[4] as $v => $l) $h .= '<option value="' . $e($v) . '"' . ((string)seo_opt($k) === (string)$v ? ' selected' : '') . '>' . $e($l) . '</option>';
            return '<div class="rm-row"><label for="f-' . $k . '">' . $e($label) . '</label><div>' . $h . '</select>' . $d . '</div></div>';
        case 'seg':
            $h = '<div class="rm-seg">';
            foreach ($f[4] as $v => $l) $h .= '<label><input type="radio" name="' . $k . '" value="' . $e($v) . '"' . ((string)seo_opt($k) === (string)$v ? ' checked' : '') . '>' . $e($l) . '</label>';
            return '<div class="rm-row"><div class="lbl">' . $e($label) . '</div><div>' . $h . '</div>' . $d . '</div></div>';
        case 'image':
            $v = (string)seo_opt($k);
            return '<div class="rm-row"><div class="lbl">' . $e($label) . '</div><div><div class="rm-img"><img data-prev="' . $k . '" src="' . $e($v ? edu_seo_abs($v) : '') . '" alt=""' . ($v ? '' : ' hidden') . '><input type="hidden" name="' . $k . '" value="' . $e($v) . '"><button type="button" class="rm-btn" data-pick="' . $k . '">Add or Upload File</button><button type="button" class="rm-btn danger" data-clear="' . $k . '"' . ($v ? '' : ' hidden') . '>Remove</button></div>' . $d . '</div></div>';
        case 'robots':
            $h = '<div class="rm-checks"><label><input type="radio" name="robots_index" value="index"' . (seo_opt('robots_index') !== 'noindex' ? ' checked' : '') . '> Index</label><label><input type="radio" name="robots_index" value="noindex"' . (seo_opt('robots_index') === 'noindex' ? ' checked' : '') . '> No Index</label>';
            foreach (['robots_nofollow' => 'No Follow', 'robots_noarchive' => 'No Archive', 'robots_noimageindex' => 'No Image Index', 'robots_nosnippet' => 'No Snippet'] as $rk => $rl) $h .= '<label><input type="checkbox" name="' . $rk . '" value="1"' . (seo_opt($rk) === '1' ? ' checked' : '') . '> ' . $rl . '</label>';
            return '<div class="rm-row"><div class="lbl">' . $e($label) . '</div><div>' . $h . '</div>' . $d . '</div></div>';
        case 'advrobots':
            $sn = (string)seo_opt('adv_snippet'); $vi = (string)seo_opt('adv_video'); $im = (string)seo_opt('adv_image');
            return '<div class="rm-row"><div class="lbl">' . $e($label) . '</div><div><div class="rm-adv">'
                . '<div><label><input type="checkbox" name="adv_snippet_on" value="1" checked> Snippet</label><input class="rm-in" type="number" name="adv_snippet" value="' . $e($sn) . '" min="-1"></div>'
                . '<div><label><input type="checkbox" name="adv_video_on" value="1" checked> Video Preview</label><input class="rm-in" type="number" name="adv_video" value="' . $e($vi) . '" min="-1"></div>'
                . '<div><label>Image Preview</label><select class="rm-in" name="adv_image">' . implode('', array_map(fn($v) => '<option value="' . $v . '"' . ($im === $v ? ' selected' : '') . '>' . ucfirst($v) . '</option>', ['large', 'standard', 'none'])) . '</select></div>'
                . '</div><p class="rm-desc">-1 = no limit. These become <code>max-snippet</code>, <code>max-video-preview</code> and <code>max-image-preview</code> in the robots meta tag.</p></div></div>';
        case 'code':
            $txt = is_file(ROOT_PATH . '/robots.txt') ? (string)file_get_contents(ROOT_PATH . '/robots.txt') : '';
            return '<div class="rm-row" style="grid-template-columns:1fr"><div><textarea class="rm-in code" name="robots_txt">' . $e($txt) . '</textarea>' . $d . ' <a href="' . $base . '/robots.txt" target="_blank">View robots.txt</a></div></div>';
        case 'tpl':
        default:
            return '<div class="rm-row"><label for="f-' . $k . '">' . $e($label) . '</label><div><input class="rm-in" id="f-' . $k . '" name="' . $k . '" value="' . $o($k) . '"' . ($type === 'tpl' ? ' data-tpl' : '') . '>' . ($type === 'tpl' ? '<div class="rm-prev" data-prevfor="f-' . $k . '"></div>' : '') . $d . '</div></div>';
    }
}

// ── Data for Dashboard / Analyzer / Indexing / Status ──────────────────────────────────────────────────────────────
$robots = is_file(ROOT_PATH . '/robots.txt') ? (string)file_get_contents(ROOT_PATH . '/robots.txt') : '';
$smName = $adv ? 'sitemap_index.xml' : 'sitemap.xml';
$checks = [];
if ($view === 'analyzer') {
    $pub = (int)$pdo->query("SELECT COUNT(*) FROM posts WHERE status = 'published'")->fetchColumn();
    $q = fn($sql) => (int)$pdo->query($sql)->fetchColumn();
    $noDesc = $q("SELECT COUNT(*) FROM posts p WHERE p.status = 'published' AND NOT EXISTS (SELECT 1 FROM post_meta m WHERE m.post_id = p.id AND m.meta_key = 'description' AND TRIM(m.meta_value) <> '')");
    $noKw = $q("SELECT COUNT(*) FROM posts p WHERE p.status = 'published' AND NOT EXISTS (SELECT 1 FROM post_meta m WHERE m.post_id = p.id AND m.meta_key = 'focus_keyword' AND TRIM(m.meta_value) <> '')");
    $noImg = $q("SELECT COUNT(*) FROM posts WHERE status = 'published' AND featured_image_id IS NULL");
    $noAlt = $q("SELECT COUNT(*) FROM media WHERE file_type IN ('image','banner') AND (alt_text IS NULL OR alt_text = '')");
    $catNoSeo = $q("SELECT COUNT(*) FROM categories c WHERE (c.meta_description IS NULL OR c.meta_description = '') AND EXISTS (SELECT 1 FROM posts p WHERE p.category_id = c.id AND p.status = 'published')");
    $blocked = (bool)preg_match('/User-agent:\s*\*\s*\R(?:(?!User-agent).*\R)*?\s*Disallow:\s*\/\s*$/mi', $robots);
    $checks = [
        ['Site can be indexed', seo_opt('noindex_site') !== '1' && !$blocked, 'The whole site is hidden from search engines (Titles & Meta → Global Meta, or robots.txt blocks everything).', 'titles&tab=global'],
        ['Google Search Console verified', seo_opt('v_google') !== '', 'Add the verification code, then submit ' . $smName . ' in Search Console.', 'general&tab=webmaster'],
        ['Bing Webmaster Tools verified', seo_opt('v_bing') !== '', 'Bing also powers other search engines.', 'general&tab=webmaster'],
        ['robots.txt lists the sitemap', stripos($robots, $smName) !== false, 'robots.txt should contain “Sitemap: ' . rtrim(SITE_URL, '/') . '/' . $smName . '”.', 'general&tab=robots'],
        ['Sitemap module is on', seo_opt('m_sitemap') === '1' || !$adv, 'Switch on the Sitemap module on the Dashboard.', 'dashboard'],
        ['Instant indexing is on', seo_opt('ix_indexnow') === '1' || edu_ix_google_creds() !== null, 'Turn on IndexNow (and/or add a Google Indexing API key).', 'indexing&tab=settings'],
        ['Schema (structured data) is on', seo_opt('m_schema') === '1' || !$adv, 'Switch on the Schema module.', 'dashboard'],
        ['Default share image set', seo_opt('og_image') !== '', 'Used when a page has no image of its own (1200×630).', 'titles&tab=global'],
        ['Posts have a meta description', $noDesc === 0, "$noDesc of $pub published posts use the automatic description.", null],
        ['Posts have a focus keyword', $noKw === 0, "$noKw of $pub published posts have no focus keyword.", null],
        ['Posts have a featured image', $noImg === 0, "$noImg published post(s) have no featured image (needed for Discover).", null],
        ['Images have ALT text', $noAlt === 0 || (seo_opt('img_alt_missing') === '1' && $adv), "$noAlt image(s) have no ALT text (turn on Image SEO or add it in the File Manager).", 'general&tab=images'],
        ['Categories have SEO descriptions', $catNoSeo === 0, "$catNoSeo categor" . ($catNoSeo === 1 ? 'y' : 'ies') . ' with posts have no SEO description.', null],
        ['HTTPS', str_starts_with(SITE_URL, 'https://'), 'The site address should start with https://', null],
    ];
}
$tab = preg_replace('/[^a-z]/', '', $_GET['tab'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title><?= $esc($viewNames[$view]) ?> · SEO - <?= $esc(edu_site_name()) ?></title>
<?php admin_shell_head(); ?>
<link rel="stylesheet" href="/assets/css/admin-seo.css?v=<?= @filemtime(ROOT_PATH . '/assets/css/admin-seo.css') ?>">
<script src="/assets/js/admin-media.js?v=<?= @filemtime(ROOT_PATH . '/assets/js/admin-media.js') ?>" defer></script>
</head>
<body>
<?php admin_shell_open('SEO', 'Titles, schema, sitemaps &amp; indexing', '<form method="POST" style="margin:0">' . csrf_field() . '<input type="hidden" name="action" value="mode"><input type="hidden" name="back" value="' . $esc($_SERVER['REQUEST_URI']) . '"><div class="rm-mode" title="Which SEO the site uses"><button name="mode" value="advanced" class="' . ($adv ? 'on' : '') . '">Advanced</button><button name="mode" value="original" class="' . (!$adv ? 'on' : '') . '">Original</button></div></form>'); ?>
<?= admin_flash() ?>
<?php if (!$adv): ?><div class="rm-warn" style="margin:0 0 14px;max-width:1060px"><b>Basic SEO is on.</b> The site uses exactly its original titles, descriptions, schema and /sitemap.xml. The settings here are saved but only used after switching to <b>Advanced</b> (top right).</div><?php endif; ?>
<div class="rm-top"><div class="rm-crumbs"><a href="/admin/seo.php">Dashboard</a><?= $view !== 'dashboard' ? ' / ' . $esc($viewNames[$view]) : ' / Modules' ?></div></div>

<?php if ($view === 'dashboard'): ?>
<div class="rm-tabs-h"><a class="on" href="/admin/seo.php">Modules</a><a href="/admin/seo-wizard.php">Setup Wizard</a><a href="/admin/seo.php?view=status&tab=importexport">Import &amp; Export</a><a href="/admin/seo.php?view=analyzer">SEO Analyzer</a></div>
<div class="rm-modules">
  <?php foreach (SEO_MODULES as $k => [$name, $ic, $col, $bg, $desc, $link]): $on = seo_opt('m_' . $k) === '1'; ?>
  <div class="rm-mod<?= $on ? '' : ' off' ?>" data-mod="<?= $k ?>">
    <div class="rm-mod-body"><div class="rm-mod-ic" style="background:<?= $bg ?>;color:<?= $col ?>"><i class="fas <?= $ic ?>"></i></div><h3><?= $esc($name) ?></h3><p><?= $esc($desc) ?></p></div>
    <div class="rm-mod-foot"><?php if ($link !== ''): ?><a class="rm-btn" href="<?= str_starts_with($link, '../') ? '/admin/' . substr($link, 3) : '/admin/seo.php?view=' . $link ?>">Settings</a><?php else: ?><span></span><?php endif; ?><label class="rm-sw" title="Turn <?= $esc($name) ?> on or off"><input type="checkbox" data-module="<?= $k ?>" <?= $on ? 'checked' : '' ?>><span></span></label></div>
  </div>
  <?php endforeach; ?>
</div>

<?php elseif (isset($SCREENS[$view])): $tabs = $SCREENS[$view][1]; if (!isset($tabs[$tab])) $tab = array_key_first($tabs); [$tTitle, $tDesc, $tIc, $fields] = $tabs[$tab]; ?>
<form class="rm-card" method="POST" id="rmForm"><?= csrf_field() ?><input type="hidden" name="view" value="<?= $view ?>"><input type="hidden" name="tab" value="<?= $tab ?>">
  <div class="rm-card-head"><h2><?= $esc($tTitle) ?></h2><p><?= $esc($tDesc) ?></p></div>
  <div class="rm-wrap">
    <nav class="rm-vtabs">
      <?php $groups = ['titles' => ['posts' => 'Post Types:', 'categories' => 'Taxonomies:'], 'sitemap' => ['posts' => 'Post Types:', 'categories' => 'Taxonomies:', 'news' => 'Special Sitemaps:']][$view] ?? []; ?>
      <?php foreach ($tabs as $k => [$tl, , $ti]): if (isset($groups[$k])): ?><div class="grp"><?= $groups[$k] ?></div><?php endif; ?><a href="/admin/seo.php?view=<?= $view ?>&amp;tab=<?= $k ?>" class="<?= $tab === $k ? 'on' : '' ?>"><i class="fas <?= $ti ?>"></i> <?= $esc($tl) ?></a><?php endforeach; ?>
    </nav>
    <div class="rm-fields"><?php foreach ($fields as $f) echo seo_row($f, $o); ?></div>
  </div>
  <div class="rm-foot"><button type="submit" name="action" value="reset" class="rm-btn" formnovalidate data-confirm="Reset the options on this tab to their defaults?" data-confirm-title="Reset Options" data-confirm-ok="Reset" data-confirm-safe>Reset Options</button><button type="submit" name="action" value="save" class="rm-btn primary">Save Changes</button></div>
</form>

<?php elseif ($view === 'indexing'): if (!in_array($tab, ['submit', 'settings', 'history'], true)) $tab = 'submit'; edu_ix_table($pdo); $gc = edu_ix_google_creds(); $key = seo_opt('ix_indexnow') === '1' ? edu_ix_key() : (string)seo_opt('ix_indexnow_key'); ?>
<div class="rm-card">
  <div class="rm-card-head"><h2><?= ['submit' => 'Submit URLs', 'settings' => 'Settings', 'history' => 'History'][$tab] ?></h2><p><?= ['submit' => 'Send URLs directly to the IndexNow API and Google’s Indexing API.', 'settings' => 'Instant Indexing module settings.', 'history' => 'The last 100 submissions.'][$tab] ?></p></div>
  <div class="rm-wrap">
    <nav class="rm-vtabs"><?php foreach (['submit' => ['Submit URLs', 'fa-paper-plane'], 'settings' => ['Settings', 'fa-gear'], 'history' => ['History', 'fa-clock-rotate-left']] as $k => [$l, $i]): ?><a href="/admin/seo.php?view=indexing&amp;tab=<?= $k ?>" class="<?= $tab === $k ? 'on' : '' ?>"><i class="fas <?= $i ?>"></i> <?= $l ?></a><?php endforeach; ?></nav>
    <div class="rm-fields">
    <?php if ($tab === 'submit'): ?>
      <form method="POST"><?= csrf_field() ?><input type="hidden" name="action" value="submit">
        <div class="rm-row" style="grid-template-columns:1fr"><div><label style="font-weight:700;display:block;margin:0 0 8px">Insert URLs to send to the IndexNow API (one per line, up to 10,000):</label><textarea class="rm-in" name="urls" rows="10" placeholder="<?= $base ?>/your-post-address"></textarea>
          <div style="display:flex;gap:10px;align-items:center;margin-top:12px;flex-wrap:wrap"><select class="rm-in" name="ix_action" style="width:auto"><option value="update">URL updated / new</option><option value="delete">URL removed</option></select><button class="rm-btn primary"><i class="fas fa-paper-plane"></i> Submit URLs</button></div>
          <p class="rm-desc">Engines: <?= seo_opt('ix_indexnow') === '1' ? '<b>IndexNow</b> (Bing, Yandex, Seznam, Naver)' : 'IndexNow is off' ?><?= $gc ? ' · <b>Google Indexing API</b>' : '' ?>.</p></div></div>
      </form>
    <?php elseif ($tab === 'settings'): ?>
      <form method="POST"><?= csrf_field() ?><input type="hidden" name="action" value="ixsettings">
        <div class="rm-row"><div class="lbl">Auto-Submit</div><div><label class="rm-sw"><input type="checkbox" name="ix_auto" value="1" <?= seo_opt('ix_auto') === '1' ? 'checked' : '' ?>><span></span></label><p class="rm-desc">Automatically submit posts when they are published, updated or deleted.</p></div></div>
        <div class="rm-row"><div class="lbl">IndexNow</div><div><label class="rm-sw"><input type="checkbox" name="ix_indexnow" value="1" <?= seo_opt('ix_indexnow') === '1' ? 'checked' : '' ?>><span></span></label><p class="rm-desc">Bing, Yandex, Seznam, Naver. Free, no account needed.</p></div></div>
        <div class="rm-row"><div class="lbl">API Key</div><div><?php if ($key): ?><input class="rm-in" readonly value="<?= $esc($key) ?>" style="font-family:ui-monospace,Menlo,monospace"><p class="rm-desc">Key file: <a href="<?= $esc(edu_ix_key_url($key)) ?>" target="_blank"><?= $esc(edu_ix_key_url($key)) ?></a></p><label style="display:flex;gap:8px;align-items:center;font-size:13.5px"><input type="checkbox" name="ix_newkey" value="1"> Change key</label><?php else: ?><p class="rm-desc">Made when IndexNow is switched on.</p><?php endif; ?></div></div>
        <div class="rm-row"><div class="lbl">Google Indexing API</div><div><?php if ($gc): ?><span class="rm-pill ok">Connected</span> <?= $esc($gc['client_email']) ?><p class="rm-desc">Add this email as an <b>Owner</b> of the site in Search Console (Settings → Users and permissions).</p><label style="display:flex;gap:8px;align-items:center;font-size:13.5px"><input type="checkbox" name="ix_google_remove" value="1"> Remove this key</label><?php else: ?><textarea class="rm-in" name="ix_google_json" rows="5" style="font-family:ui-monospace,Menlo,monospace;font-size:12.5px" placeholder='Paste the service-account key file (JSON) — {"type": "service_account", "client_email": …}'></textarea><p class="rm-desc">Google’s instant indexing (meant for job postings and live pages). <a href="https://developers.google.com/search/apis/indexing-api/v3/prereqs" target="_blank" rel="noopener">How to get the key</a>.</p><?php endif; ?></div></div>
        <div class="rm-foot" style="margin:0 -28px -10px;border-radius:0"><span></span><button class="rm-btn primary">Save Changes</button></div>
      </form>
    <?php else: $log = $pdo->query("SELECT * FROM seo_index_log ORDER BY id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC); ?>
      <div style="padding:18px 0">
      <?php if (!$log): ?><p class="rm-desc">No submissions yet.</p><?php else: ?>
      <div style="overflow-x:auto"><table class="rm-table"><thead><tr><th>Time</th><th>URL</th><th>Engine</th><th>Status</th><th>Response</th></tr></thead><tbody>
        <?php foreach ($log as $l): ?><tr><td style="white-space:nowrap"><?= date('j M, g:i a', strtotime($l['created_at'])) ?></td><td style="word-break:break-all"><?= $esc($l['url']) ?><?= $l['action'] === 'delete' ? ' <small>(removed)</small>' : '' ?></td><td><?= $l['engine'] === 'google' ? 'Google' : 'IndexNow' ?></td><td><span class="rm-pill <?= $l['status'] ?>"><?= $l['status'] ?></span></td><td><small><?= $esc((string)$l['response']) ?></small></td></tr><?php endforeach; ?>
      </tbody></table></div>
      <form method="POST" style="margin-top:12px"><?= csrf_field() ?><input type="hidden" name="action" value="ixclear"><button class="rm-btn danger" data-confirm="Clear the whole submission history?" data-confirm-title="Clear History" data-confirm-ok="Clear">Clear History</button></form>
      <?php endif; ?></div>
    <?php endif; ?>
    </div>
  </div>
</div>

<?php elseif ($view === 'analyzer'): $passed = count(array_filter($checks, fn($c) => $c[1])); $score = (int)round($passed / max(1, count($checks)) * 100); ?>
<div class="rm-card">
  <div class="rm-card-head"><h2>SEO Analyzer</h2><p>Site-wide analysis of your SEO setup and content.</p></div>
  <div class="rm-score"><div class="rm-gauge" style="--p:<?= $score ?>;--c:<?= $score >= 80 ? '#16a34a' : ($score >= 50 ? '#f59e0b' : '#dc2626') ?>"><span><?= $score ?></span></div><div><b style="font-size:18px;color:#0f172a"><?= $passed ?> of <?= count($checks) ?> checks passed</b><p class="rm-desc">Fix the orange items to help search engines find and show your pages. <a href="<?= $base ?>/<?= $smName ?>" target="_blank">View sitemap</a> · <a href="https://search.google.com/test/rich-results?url=<?= rawurlencode($base . '/') ?>" target="_blank" rel="noopener">Rich Results Test</a> · <a href="https://pagespeed.web.dev/analysis?url=<?= rawurlencode($base . '/') ?>" target="_blank" rel="noopener">PageSpeed</a></p></div></div>
  <ul class="rm-checklist"><?php foreach ($checks as [$l, $ok, $help, $go]): ?><li class="<?= $ok ? 'ok' : 'no' ?>"><i class="fas <?= $ok ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i><div><b><?= $esc($l) ?></b><?php if (!$ok): ?><small><?= $esc($help) ?></small><?php endif; ?></div><?php if (!$ok && $go): ?><a href="/admin/seo.php?view=<?= $go ?>">Fix <i class="fas fa-arrow-right"></i></a><?php endif; ?></li><?php endforeach; ?></ul>
</div>

<?php else: if (!in_array($tab, ['tools', 'system', 'importexport'], true)) $tab = 'tools'; ?>
<div class="rm-card">
  <div class="rm-card-head"><h2><?= ['tools' => 'Database Tools', 'system' => 'System Status', 'importexport' => 'Import & Export'][$tab] ?></h2><p><?= ['tools' => 'Clear logs and reset SEO data.', 'system' => 'What the SEO features need from the server.', 'importexport' => 'Back up your SEO settings or move them to another site.'][$tab] ?></p></div>
  <div class="rm-wrap">
    <nav class="rm-vtabs"><?php foreach (['tools' => ['Database Tools', 'fa-database'], 'system' => ['System Status', 'fa-server'], 'importexport' => ['Import & Export', 'fa-right-left']] as $k => [$l, $i]): ?><a href="/admin/seo.php?view=status&amp;tab=<?= $k ?>" class="<?= $tab === $k ? 'on' : '' ?>"><i class="fas <?= $i ?>"></i> <?= $l ?></a><?php endforeach; ?></nav>
    <div class="rm-fields">
    <?php if ($tab === 'tools'): foreach ([['clear404', 'Clear 404 Log', 'Delete the list of addresses recorded by the 404 Monitor.'], ['clearhits', 'Reset Redirection Hits', 'Set the “times used” counter of every redirect back to zero.'], ['clearix', 'Clear Indexing History', 'Delete the Instant Indexing submission history.'], ['resetall', 'Reset All SEO Settings', 'Set every SEO option back to its default. Your SEO mode, verification codes and API keys are kept.']] as [$t, $l, $dsc]): ?>
      <form method="POST" class="rm-row"><?= csrf_field() ?><input type="hidden" name="action" value="tool"><input type="hidden" name="tool" value="<?= $t ?>"><div class="lbl"><?= $l ?></div><div><button class="rm-btn<?= $t === 'resetall' ? ' danger' : '' ?>" data-confirm="<?= $esc($dsc) ?> Continue?" data-confirm-title="<?= $esc($l) ?>" data-confirm-ok="Yes"><?= $l ?></button><p class="rm-desc"><?= $esc($dsc) ?></p></div></form>
    <?php endforeach; elseif ($tab === 'system'): require_once INCLUDES_PATH . '/cron.php'; $cron = edu_cron_state(); $rows = [
        ['PHP version', PHP_VERSION, version_compare(PHP_VERSION, '8.0', '>=')], ['cURL (indexing, Cloudflare test)', extension_loaded('curl') ? 'Available' : 'Missing', extension_loaded('curl')],
        ['OpenSSL (Google Indexing API)', extension_loaded('openssl') ? 'Available' : 'Missing', extension_loaded('openssl')], ['WebP images (GD)', function_exists('imagewebp') ? 'Available' : 'Missing', function_exists('imagewebp')],
        ['robots.txt writable', is_writable(ROOT_PATH . '/robots.txt') ? 'Yes' : 'No', is_writable(ROOT_PATH . '/robots.txt')], ['SEO mode', $adv ? 'Advanced (Rank Math style)' : 'Basic SEO', true],
        ['Sitemap', $base . '/' . $smName, true], ['Background jobs (cron)', !empty($cron['minute_at']) ? 'Last run ' . date('j M, g:i a', (int)$cron['minute_at']) : 'Not seen yet', !empty($cron['minute_at']) && time() - (int)$cron['minute_at'] < 600],
      ]; ?>
      <div style="padding:16px 0"><table class="rm-table"><tbody><?php foreach ($rows as [$l, $v, $ok]): ?><tr><th style="width:42%;background:none;text-transform:none;font-size:13.5px;color:#334155"><?= $esc($l) ?></th><td><span class="rm-pill <?= $ok ? 'ok' : 'warn' ?>"><?= $ok ? 'OK' : 'Check' ?></span> <?= $esc($v) ?></td></tr><?php endforeach; ?></tbody></table></div>
    <?php else: ?>
      <form method="POST" class="rm-row"><?= csrf_field() ?><input type="hidden" name="action" value="tool"><input type="hidden" name="tool" value="export"><div class="lbl">Export Settings</div><div><button class="rm-btn primary"><i class="fas fa-download"></i> Export</button><p class="rm-desc">Downloads all SEO settings as a JSON file (the Google key is left out).</p></div></form>
      <form method="POST" enctype="multipart/form-data" class="rm-row"><?= csrf_field() ?><input type="hidden" name="action" value="tool"><input type="hidden" name="tool" value="import"><div class="lbl">Import Settings</div><div><input type="file" name="file" accept=".json,application/json" required class="rm-in" style="max-width:420px"><div style="margin-top:10px"><button class="rm-btn" data-confirm="Imported settings replace the current ones. Continue?" data-confirm-title="Import Settings" data-confirm-ok="Import" data-confirm-safe><i class="fas fa-upload"></i> Import</button></div><p class="rm-desc">Choose a file exported from this screen.</p></div></form>
    <?php endif; ?>
    </div>
  </div>
</div>
<?php endif; ?>
<?php admin_shell_close(); ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var token = <?= json_encode(csrf_token()) ?>, SITE = <?= json_encode(edu_seo_site_name()) ?>;
  document.querySelectorAll('[data-module]').forEach(function (c) {
    c.addEventListener('change', function () {
      var fd = new FormData(); fd.append('_csrf', token); fd.append('action', 'module'); fd.append('module', c.dataset.module); fd.append('on', c.checked ? '1' : '');
      fetch('/admin/seo.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function () { c.closest('.rm-mod').classList.toggle('off', !c.checked); }).catch(function () { c.checked = !c.checked; });
    });
  });
  document.querySelectorAll('[data-pick]').forEach(function (b) { b.onclick = function () { var k = b.dataset.pick; EduMedia.pick(function (it) { document.querySelector('[name="' + k + '"]').value = it.path; var im = document.querySelector('[data-prev="' + k + '"]'); im.src = it.path; im.hidden = false; document.querySelector('[data-clear="' + k + '"]').hidden = false; }); }; });
  document.querySelectorAll('[data-clear]').forEach(function (b) { b.onclick = function () { var k = b.dataset.clear; document.querySelector('[name="' + k + '"]').value = ''; document.querySelector('[data-prev="' + k + '"]').hidden = true; b.hidden = true; }; });
  // live preview of title templates
  var sepEl = function () { var r = document.querySelector('[name="sep"]:checked'); return r ? r.value : <?= json_encode((string)seo_opt('sep')) ?>; };
  function prev(i) { var p = document.querySelector('[data-prevfor="' + i.id + '"]'); if (!p) return; var s = sepEl(); p.textContent = i.value ? 'Preview: ' + i.value.replace(/%title%/g, 'Your Post Title').replace(/%term%/g, 'Category Name').replace(/%name%/g, 'Author Name').replace(/%search_query%/g, 'search words').replace(/%excerpt%/g, 'The first lines of the post…').replace(/%sitename%/g, SITE).replace(/%sep%|%separator%/g, s).replace(/%currentyear%/g, new Date().getFullYear()).replace(/%[a-z_]+%/g, '').replace(/\s+/g, ' ').trim() : ''; }
  document.querySelectorAll('[data-tpl]').forEach(function (i) { i.addEventListener('input', function () { prev(i); }); prev(i); });
});
</script>
</body>
</html>
