<?php
// SEO — two modes (Admin → SEO → Dashboard):
//   "advanced": Rank Math-style SEO — title & description templates per page type, robots meta, Open Graph / Twitter,
//               one JSON-LD @graph (Organization, WebSite, WebPage, ImageObject, BreadcrumbList, Person, Article…),
//               sitemap index, instant indexing, link & image tweaks;
//   "original": exactly the site's original SEO (titles, descriptions, schema and /sitemap.xml) — nothing here runs.
require_once __DIR__ . '/settings.php';

function edu_seo_defaults(): array
{
    return [
        'mode' => 'advanced', 'wizard_done' => '0', 'wizard_level' => 'advanced',
        // Your site
        'site_type' => 'news', 'org_type' => 'Organization', 'site_name' => '', 'site_alt_name' => '', 'org_name' => '', 'org_logo' => '', 'og_image' => '',
        // Modules (Dashboard)
        'm_sitemap' => '1', 'm_news_sitemap' => '1', 'm_schema' => '1', 'm_redirections' => '1', 'm_404' => '1', 'm_instant_indexing' => '1',
        'm_image_seo' => '1', 'm_analyzer' => '1', 'm_role_manager' => '1', 'm_link_counter' => '1',
        // General settings → Links / Images / Others
        'nofollow_external' => '0', 'nofollow_image_links' => '0', 'external_blank' => '1', 'affiliate_prefix' => '',
        'img_alt_missing' => '1', 'img_title_missing' => '0',
        // Titles & Meta → Global
        'robots_index' => 'index', 'robots_nofollow' => '0', 'robots_noarchive' => '0', 'robots_noimageindex' => '0', 'robots_nosnippet' => '0',
        'adv_snippet' => '-1', 'adv_video' => '-1', 'adv_image' => 'large', 'noindex_empty' => '1', 'sep' => '|', 'capitalize' => '0', 'twitter_card' => 'summary_large_image',
        // Social meta
        'fb_page' => '', 'fb_author' => '', 'fb_admin' => '', 'fb_app_id' => '', 'twitter_site' => '',
        // Homepage
        'home_title' => '', 'home_description' => '', 'home_og_title' => '', 'home_og_description' => '', 'home_og_image' => '',
        // Authors / misc
        'author_archives' => '1', 'noindex_authors' => '0', 'author_title' => '%name% %sep% %sitename%', 'author_description' => '%user_description%',
        'search_title' => '%search_query% %sep% %sitename%', 'title_404' => 'Page Not Found %sep% %sitename%',
        'noindex_search' => '1', 'noindex_paged' => '0', 'noindex_site' => '0',
        // Posts / pages / categories / tags
        'post_format' => '%title% %sep% %sitename%', 'post_description' => '%excerpt%', 'schema_default' => 'NewsArticle',
        'page_format' => '%title% %sep% %sitename%', 'page_description' => '%excerpt%', 'page_schema' => 'Article',
        'term_format' => '%term% %sep% %sitename%', 'cat_description' => '%term_description%', 'noindex_categories' => '0',
        'tag_format' => '%term% %sep% %sitename%', 'tag_description' => '%term_description%', 'noindex_tags' => '0',
        // Webmaster tools
        'v_google' => '', 'v_bing' => '', 'v_yandex' => '', 'v_pinterest' => '', 'v_baidu' => '', 'v_norton' => '',
        // Sitemaps
        'sm_posts' => '1', 'sm_pages' => '1', 'sm_categories' => '1', 'sm_tags' => '0', 'sm_authors' => '1', 'sm_states' => '1',
        'sm_images' => '1', 'sm_featured' => '1', 'sm_per' => '200', 'sm_exclude_posts' => '', 'sm_exclude_terms' => '',
        'news_name' => '', 'news_language' => 'en', 'news_exclude_cats' => '',
        // Instant indexing
        'ix_auto' => '1', 'ix_google_json' => '', 'ix_indexnow' => '1', 'ix_indexnow_key' => '',
        // 404 (old setting, now in Redirections)
        'redirect_404' => '0', 'redirect_404_to' => '/',
        // Role manager: who gets the SEO box in the post editor
        'role_author_seo' => '1', 'role_editor_seo' => '1',
    ];
}

function seo_opt(string $k)
{
    return es('seo', $k, edu_seo_defaults()[$k] ?? '');
}

/** The Rank Math-style (Advanced) SEO is always on — the old "Original / Basic" mode was removed. */
function edu_seo_advanced(): bool
{
    return true;
}

function edu_seo_module(string $m): bool
{
    return edu_seo_advanced() && seo_opt('m_' . $m) === '1';
}

function edu_seo_site_name(): string
{
    $n = trim((string)seo_opt('site_name'));
    return $n !== '' ? $n : edu_site_name();
}

/** Fills %variables% in a title / description template. */
function edu_seo_vars(string $tpl, array $v = []): string
{
    $sep = (string)seo_opt('sep');
    $map = [
        '%sitename%' => edu_seo_site_name(), '%sep%' => $sep, '%separator%' => $sep, '%sitedesc%' => (string)es('general', 'tagline', ''),
        '%currentyear%' => date('Y'), '%currentmonth%' => date('F'), '%currentday%' => date('j'), '%currentdate%' => date('F j, Y'),
        '%title%' => $v['title'] ?? '', '%excerpt%' => $v['excerpt'] ?? '', '%category%' => $v['category'] ?? '', '%focuskw%' => $v['focuskw'] ?? '',
        '%term%' => $v['term'] ?? '', '%term_description%' => $v['term_description'] ?? '', '%name%' => $v['name'] ?? '', '%user_description%' => $v['user_description'] ?? '',
        '%search_query%' => $v['search_query'] ?? '', '%page%' => !empty($v['page']) && $v['page'] > 1 ? $sep . ' Page ' . (int)$v['page'] : '', '%date%' => $v['date'] ?? '',
    ];
    $out = strtr($tpl, $map);
    $out = preg_replace('/%[a-z_]+%/', '', $out);
    $out = trim(preg_replace('/\s+/u', ' ', $out));
    if ($sep !== '') $out = trim(preg_replace('/^(' . preg_quote($sep, '/') . '\s*)+|(\s*' . preg_quote($sep, '/') . ')+$/u', '', $out));
    if (seo_opt('capitalize') === '1') $out = mb_convert_case($out, MB_CASE_TITLE, 'UTF-8');
    return $out;
}

/** Plain-text excerpt of HTML content (for %excerpt% and the automatic meta description). */
function edu_seo_excerpt(string $html, int $len = 160): string
{
    $txt = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(preg_replace('#<(script|style)\b.*?</\1>#is', '', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    if (mb_strlen($txt) <= $len) return $txt;
    $cut = mb_substr($txt, 0, $len);
    $sp = mb_strrpos($cut, ' ');
    return rtrim($sp > $len * .6 ? mb_substr($cut, 0, $sp) : $cut, " ,.;:-");
}

/** Robots meta, like Rank Math: "index, follow, max-snippet:-1, max-video-preview:-1, max-image-preview:large". */
function edu_seo_robots(bool $noindex = false, ?array $custom = null): string
{
    if (seo_opt('noindex_site') === '1') $noindex = true;
    $c = $custom ?? [];
    $noindex = $noindex || !empty($c['noindex']) || seo_opt('robots_index') === 'noindex';
    $parts = [$noindex ? 'noindex' : 'index', (!empty($c['nofollow']) || seo_opt('robots_nofollow') === '1') ? 'nofollow' : 'follow'];
    if (!empty($c['noarchive']) || seo_opt('robots_noarchive') === '1') $parts[] = 'noarchive';
    if (!empty($c['noimageindex']) || seo_opt('robots_noimageindex') === '1') $parts[] = 'noimageindex';
    if (!empty($c['nosnippet']) || seo_opt('robots_nosnippet') === '1') $parts[] = 'nosnippet';
    if (!$noindex) {
        $parts[] = 'max-snippet:' . (seo_opt('adv_snippet') === '' ? '-1' : (string)(int)seo_opt('adv_snippet'));
        $parts[] = 'max-video-preview:' . (seo_opt('adv_video') === '' ? '-1' : (string)(int)seo_opt('adv_video'));
        $parts[] = 'max-image-preview:' . (in_array(seo_opt('adv_image'), ['large', 'standard', 'none'], true) ? seo_opt('adv_image') : 'large');
    }
    return implode(', ', $parts);
}

/**
 * Rewrites $seo for the current page in Advanced mode. $ctx carries the page's own objects:
 * post (+ category, excerpt, focuskw, robots), page, cat (+ count), tag (+ count), author, search.
 */
function edu_seo_apply(array $seo, array $ctx = []): array
{
    if (!edu_seo_advanced()) return $seo; // original EduMint SEO, untouched
    $script = basename($_SERVER['SCRIPT_FILENAME'] ?? '');
    if ($script !== 'post.php' && !empty($ctx['post'])) $script = 'post.php';
    if (!empty($ctx['page']) && is_array($ctx['page'])) $script = 'page.php';
    $pageNo = (int)($_GET['page'] ?? 1);
    $noindex = false;
    if ($script === 'post.php' && !empty($ctx['post'])) {
        $p = $ctx['post'];
        $vars = ['title' => $p['title'], 'excerpt' => $ctx['excerpt'] ?? edu_seo_excerpt((string)($p['content'] ?? '')), 'category' => $ctx['category'] ?? '', 'focuskw' => $ctx['focuskw'] ?? '', 'date' => !empty($p['date']) ? date('F j, Y', strtotime($p['date'])) : ''];
        if (empty($ctx['custom_title'])) $seo['title'] = edu_seo_vars((string)seo_opt('post_format'), $vars);
        if (empty($p['meta_description'])) $seo['description'] = edu_seo_vars((string)seo_opt('post_description'), $vars) ?: $seo['description'];
    } elseif ($script === 'page.php' && !empty($ctx['page'])) {
        $pg = $ctx['page'];
        if (empty($pg['seo_title'])) $seo['title'] = edu_seo_vars((string)seo_opt('page_format'), ['title' => $pg['title']]);
        if (empty($pg['meta_description'])) $seo['description'] = edu_seo_vars((string)seo_opt('page_description'), ['excerpt' => edu_seo_excerpt((string)$pg['content'])]) ?: $seo['description'];
    } elseif ($script === 'category.php' && !empty($ctx['cat'])) {
        $c = $ctx['cat'];
        if (empty($c['meta_title'])) $seo['title'] = edu_seo_vars((string)seo_opt('term_format'), ['term' => $c['name'], 'page' => $pageNo]);
        if (empty($c['meta_description'])) { $d = edu_seo_vars((string)seo_opt('cat_description'), ['term' => $c['name'], 'term_description' => (string)($c['description'] ?? '')]); if ($d !== '') $seo['description'] = $d; }
        $noindex = seo_opt('noindex_categories') === '1' || (seo_opt('noindex_empty') === '1' && isset($ctx['count']) && (int)$ctx['count'] === 0);
    } elseif ($script === 'tag.php' && !empty($ctx['tag'])) {
        $seo['title'] = edu_seo_vars((string)seo_opt('tag_format'), ['term' => $ctx['tag']['name'], 'page' => $pageNo]);
        $d = edu_seo_vars((string)seo_opt('tag_description'), ['term' => $ctx['tag']['name']]);
        if ($d !== '') $seo['description'] = $d;
        $noindex = seo_opt('noindex_tags') === '1' || (seo_opt('noindex_empty') === '1' && isset($ctx['count']) && (int)$ctx['count'] === 0);
    } elseif ($script === 'author.php' && !empty($ctx['author'])) {
        $a = $ctx['author'];
        $seo['title'] = edu_seo_vars((string)seo_opt('author_title'), ['name' => $a['name'], 'page' => $pageNo]);
        $d = edu_seo_vars((string)seo_opt('author_description'), ['name' => $a['name'], 'user_description' => edu_seo_excerpt((string)($a['bio'] ?? ''))]);
        if ($d !== '') $seo['description'] = $d;
        $noindex = seo_opt('noindex_authors') === '1' || seo_opt('author_archives') !== '1';
    } elseif ($script === 'search.php') {
        $seo['title'] = edu_seo_vars((string)seo_opt('search_title'), ['search_query' => (string)($ctx['search'] ?? ($_GET['q'] ?? ''))]);
        $noindex = seo_opt('noindex_search') === '1';
    } elseif ($script === 'index.php') {
        if (seo_opt('home_title') !== '') $seo['title'] = edu_seo_vars((string)seo_opt('home_title'), ['page' => $pageNo]);
        if (seo_opt('home_description') !== '') $seo['description'] = edu_seo_vars((string)seo_opt('home_description'));
        if (seo_opt('home_og_title') !== '') $seo['og_title'] = edu_seo_vars((string)seo_opt('home_og_title'));
        if (seo_opt('home_og_description') !== '') $seo['og_description'] = edu_seo_vars((string)seo_opt('home_og_description'));
        if (seo_opt('home_og_image') !== '') $seo['image'] = edu_seo_abs((string)seo_opt('home_og_image'));
    }
    if (seo_opt('noindex_paged') === '1' && $pageNo > 1) $noindex = true;
    $was = strtolower((string)($seo['robots'] ?? ''));
    $seo['robots'] = edu_seo_robots($noindex || str_contains($was, 'noindex'), $ctx['robots'] ?? null);
    // Default share image when the page has none of its own
    if (seo_opt('og_image') !== '' && (($seo['image'] ?? '') === SEO_DEFAULT_IMAGE || empty($seo['image']))) $seo['image'] = edu_seo_abs((string)seo_opt('og_image'));
    return $seo;
}

function edu_seo_abs(string $u): string
{
    return $u === '' ? '' : (preg_match('#^https?://#', $u) ? $u : rtrim(SITE_URL, '/') . '/' . ltrim($u, '/'));
}

/** Verification codes (both modes — needed for Search Console). */
function edu_seo_head_tags(): string
{
    $out = '';
    foreach (['v_google' => 'google-site-verification', 'v_bing' => 'msvalidate.01', 'v_yandex' => 'yandex-verification', 'v_pinterest' => 'p:domain_verify', 'v_baidu' => 'baidu-site-verification', 'v_norton' => 'norton-safeweb-site-verification'] as $k => $name) {
        $v = trim((string)seo_opt($k));
        if ($v !== '') {
            if (preg_match('/content="([^"]+)"/', $v, $m)) $v = $m[1]; // a whole <meta> tag was pasted
            $out .= '<meta name="' . $name . '" content="' . htmlspecialchars($v) . '">';
        }
    }
    return $out;
}

/** Links & images in post/page content (Advanced mode): nofollow / new tab for external links, missing alt/title. */
// Tables scroll sideways on phones instead of squeezing: each <table> gets its own .nb-tw wrapper (nested tables too).
function edu_content_wrap_tables(string $html): string
{
    if (stripos($html, '<table') === false || strpos($html, 'class="nb-tw"') !== false) return $html;
    $html = preg_replace('/<table\b/i', '<div class="nb-tw"><table', $html);
    return preg_replace('#</table>#i', '</table></div>', $html);
}

function edu_seo_filter_content(string $html, string $title = ''): string
{
    if (!edu_seo_advanced() || $html === '') return $html;
    $own = strtolower(preg_replace('/^www\./', '', (string)parse_url(SITE_URL, PHP_URL_HOST)));
    $prefixes = array_filter(array_map('trim', preg_split('/\R/', (string)seo_opt('affiliate_prefix'))));
    $nf = seo_opt('nofollow_external') === '1';
    $nfImg = seo_opt('nofollow_image_links') === '1';
    $blank = seo_opt('external_blank') === '1';
    if ($nf || $nfImg || $blank) {
        $html = preg_replace_callback('#<a\s([^>]*href=["\']([^"\']+)["\'][^>]*)>#i', function ($m) use ($own, $nf, $nfImg, $blank, $prefixes) {
            $href = $m[2];
            $host = strtolower(preg_replace('/^www\./', '', (string)parse_url($href, PHP_URL_HOST)));
            $isAff = false;
            foreach ($prefixes as $pf) if ($pf !== '' && str_starts_with($href, $pf)) $isAff = true;
            if (!(($host !== '' && $host !== $own) || $isAff)) return $m[0];
            $attrs = $m[1];
            $isImg = (bool)preg_match('/\.(jpe?g|png|gif|webp|avif|svg)(\?|$)/i', $href);
            $rel = preg_match('/\brel=["\']([^"\']*)["\']/i', $attrs, $r) ? preg_split('/\s+/', trim($r[1])) : [];
            if (($nf && !in_array('dofollow', $rel, true)) || ($nfImg && $isImg)) $rel[] = 'nofollow';
            if ($blank && !preg_match('/\btarget=/i', $attrs)) { $attrs .= ' target="_blank"'; $rel[] = 'noopener'; }
            $rel = array_values(array_unique(array_filter($rel, fn($x) => $x !== '' && $x !== 'dofollow')));
            $attrs = preg_replace('/\s*\brel=["\'][^"\']*["\']/i', '', $attrs);
            if ($rel) $attrs .= ' rel="' . implode(' ', $rel) . '"';
            return '<a ' . trim($attrs) . '>';
        }, $html);
    }
    if (edu_seo_module('image_seo') && $title !== '') {
        $n = 0;
        $html = preg_replace_callback('#<img\b([^>]*)>#i', function ($m) use ($title, &$n) {
            $a = $m[1];
            $n++;
            $label = htmlspecialchars($title . ($n > 1 ? ' ' . $n : ''), ENT_QUOTES, 'UTF-8');
            if (seo_opt('img_alt_missing') === '1' && (!preg_match('/\balt=["\']([^"\']*)["\']/i', $a, $alt) || trim($alt[1]) === '')) $a = preg_replace('/\s*\balt=["\'][^"\']*["\']/i', '', $a) . ' alt="' . $label . '"';
            if (seo_opt('img_title_missing') === '1' && !preg_match('/\btitle=/i', $a)) $a .= ' title="' . $label . '"';
            return '<img' . $a . '>';
        }, $html);
    }
    return $html;
}
