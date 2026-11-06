<?php
// Advanced SEO <head> output, in Rank Math's format: description, robots, canonical, Open Graph, Twitter (with
// "Written by" / "Time to read" labels) and a single JSON-LD @graph: Organization, WebSite (+ site search),
// ImageObject, BreadcrumbList, WebPage, Person (author) and the article (NewsArticle / BlogPosting / Article).
// $ctx (set by the page before head_script.php): ['kind' => 'post'|'page'|'category'|'tag'|'author'|'search'|'home'|'other',
//   'post' => row, 'author' => row, 'category' => [name, slug], 'tags' => [names], 'image' => [path, alt], 'focuskw', 'faq' => [[q,a]], 'schema' => type]
require_once __DIR__ . '/seo.php';

function edu_seo_img_info(string $url, string $alt = ''): array
{
    $info = ['url' => $url, 'width' => null, 'height' => null, 'type' => null, 'alt' => $alt];
    $path = parse_url($url, PHP_URL_PATH);
    $own = parse_url(SITE_URL, PHP_URL_HOST);
    $host = parse_url($url, PHP_URL_HOST);
    if ($path && (!$host || $host === $own)) {
        $f = ROOT_PATH . '/' . ltrim(rawurldecode($path), '/');
        if (is_file($f) && ($s = @getimagesize($f))) { $info['width'] = $s[0]; $info['height'] = $s[1]; $info['type'] = $s['mime'] ?? null; }
    }
    return $info;
}

function edu_seo_org(): array
{
    $type = in_array(seo_opt('org_type'), ['Organization', 'Person', 'EducationalOrganization', 'NewsMediaOrganization', 'LocalBusiness'], true) ? seo_opt('org_type') : 'Organization';
    $base = rtrim(SITE_URL, '/');
    $name = trim((string)seo_opt('org_name')) ?: edu_seo_site_name();
    $logo = (string)seo_opt('org_logo') ?: (string)es('general', 'logo', '') ?: (defined('SITE_LOGO') ? SITE_LOGO : '');
    $org = ['@type' => $type, '@id' => $base . '/#' . ($type === 'Person' ? 'person' : 'organization'), 'name' => $name, 'url' => $base];
    $same = array_values(array_filter(edu_social()));
    if (seo_opt('fb_page') !== '') array_unshift($same, (string)seo_opt('fb_page'));
    if ($same) $org['sameAs'] = array_values(array_unique($same));
    if ($logo !== '') {
        $li = edu_seo_img_info(edu_seo_abs($logo), $name);
        $org[$type === 'Person' ? 'image' : 'logo'] = array_filter(['@type' => 'ImageObject', '@id' => $base . '/#logo', 'url' => $li['url'], 'contentUrl' => $li['url'], 'caption' => $name, 'inLanguage' => 'en-IN', 'width' => $li['width'] ? (string)$li['width'] : null, 'height' => $li['height'] ? (string)$li['height'] : null]);
    }
    if ($type !== 'Person' && defined('CONTACT_EMAIL') && CONTACT_EMAIL) $org['email'] = CONTACT_EMAIL;
    return $org;
}

function edu_seo_head_advanced(array $seo, string $canonical, array $ctx = []): string
{
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $base = rtrim(SITE_URL, '/');
    $kind = $ctx['kind'] ?? 'other';
    $site = edu_seo_site_name();
    $title = (string)($seo['title'] ?? $site);
    $desc = (string)($seo['description'] ?? '');
    $ogTitle = (string)($seo['og_title'] ?? $title);
    $ogDesc = (string)($seo['og_description'] ?? $desc);
    $img = (string)($seo['image'] ?? '');
    $imgInfo = $img !== '' ? edu_seo_img_info($img, (string)($ctx['image_alt'] ?? $title)) : null;
    $post = $ctx['post'] ?? null;
    $isArticle = $kind === 'post' && $post;
    $o = [];
    if ($desc !== '') $o[] = '<meta name="description" content="' . $e($desc) . '"/>';
    $o[] = '<meta name="robots" content="' . $e($seo['robots'] ?? edu_seo_robots()) . '"/>';
    $o[] = '<link rel="canonical" href="' . $e($canonical) . '" />';
    $__pl = $GLOBALS['edu_page_lang'] ?? 'en';
    $o[] = '<meta property="og:locale" content="' . ($__pl === 'hi' ? 'hi_IN' : 'en_US') . '" />';
    $o[] = '<meta property="og:type" content="' . ($isArticle ? 'article' : ($kind === 'author' ? 'profile' : 'website')) . '" />';
    $o[] = '<meta property="og:title" content="' . $e($ogTitle) . '" />';
    if ($ogDesc !== '') $o[] = '<meta property="og:description" content="' . $e($ogDesc) . '" />';
    $o[] = '<meta property="og:url" content="' . $e($canonical) . '" />';
    $o[] = '<meta property="og:site_name" content="' . $e($site) . '" />';
    if ($isArticle) {
        if (seo_opt('fb_page') !== '') $o[] = '<meta property="article:publisher" content="' . $e(seo_opt('fb_page')) . '" />';
        $fbAuthor = (string)($ctx['author']['facebook'] ?? '') ?: (string)seo_opt('fb_author');
        if ($fbAuthor !== '') $o[] = '<meta property="article:author" content="' . $e($fbAuthor) . '" />';
        foreach (($ctx['tags'] ?? []) as $t) $o[] = '<meta property="article:tag" content="' . $e($t) . '" />';
        if (!empty($ctx['category']['name'])) $o[] = '<meta property="article:section" content="' . $e($ctx['category']['name']) . '" />';
        if (!empty($post['updated_at'])) $o[] = '<meta property="og:updated_time" content="' . date('c', strtotime($post['updated_at'])) . '" />';
    }
    if ($imgInfo) {
        $o[] = '<meta property="og:image" content="' . $e($imgInfo['url']) . '" />';
        if (str_starts_with($imgInfo['url'], 'https://')) $o[] = '<meta property="og:image:secure_url" content="' . $e($imgInfo['url']) . '" />';
        if ($imgInfo['width']) { $o[] = '<meta property="og:image:width" content="' . $imgInfo['width'] . '" />'; $o[] = '<meta property="og:image:height" content="' . $imgInfo['height'] . '" />'; }
        $o[] = '<meta property="og:image:alt" content="' . $e($imgInfo['alt'] ?: $title) . '" />';
        if ($imgInfo['type']) $o[] = '<meta property="og:image:type" content="' . $e($imgInfo['type']) . '" />';
    }
    if ($isArticle) {
        $o[] = '<meta property="article:published_time" content="' . date('c', strtotime($post['date'])) . '" />';
        if (!empty($post['updated_at'])) $o[] = '<meta property="article:modified_time" content="' . date('c', strtotime($post['updated_at'])) . '" />';
    }
    if (seo_opt('fb_app_id') !== '') $o[] = '<meta property="fb:app_id" content="' . $e(seo_opt('fb_app_id')) . '" />';
    if (seo_opt('fb_admin') !== '') $o[] = '<meta property="fb:admins" content="' . $e(seo_opt('fb_admin')) . '" />';
    $o[] = '<meta name="twitter:card" content="' . (seo_opt('twitter_card') === 'summary' ? 'summary' : 'summary_large_image') . '" />';
    $o[] = '<meta name="twitter:title" content="' . $e($ogTitle) . '" />';
    if ($ogDesc !== '') $o[] = '<meta name="twitter:description" content="' . $e($ogDesc) . '" />';
    if (seo_opt('twitter_site') !== '') $o[] = '<meta name="twitter:site" content="@' . $e(ltrim((string)seo_opt('twitter_site'), '@')) . '" />';
    $tw = (string)($ctx['author']['twitter'] ?? '');
    if ($tw !== '' && preg_match('#(?:twitter|x)\.com/([A-Za-z0-9_]{1,15})#', $tw, $m)) $o[] = '<meta name="twitter:creator" content="@' . $e($m[1]) . '" />';
    if ($imgInfo) $o[] = '<meta name="twitter:image" content="' . $e($imgInfo['url']) . '" />';
    if ($isArticle) {
        $words = str_word_count(strip_tags((string)$post['content'])) ?: count(preg_split('/\s+/u', trim(strip_tags((string)$post['content']))));
        $mins = max(1, (int)ceil($words / 200));
        $o[] = '<meta name="twitter:label1" content="Written by" />';
        $o[] = '<meta name="twitter:data1" content="' . $e($ctx['author']['name'] ?? $site) . '" />';
        $o[] = '<meta name="twitter:label2" content="Time to read" />';
        $o[] = '<meta name="twitter:data2" content="' . ($mins === 1 ? 'Less than a minute' : $mins . ' minutes') . '" />';
    }

    // ── JSON-LD @graph ──────────────────────────────────────────────────────────────────────────────────────────
    if (edu_seo_module('schema')) {
        $org = edu_seo_org();
        $graph = [$org];
        $website = ['@type' => 'WebSite', '@id' => $base . '/#website', 'url' => $base, 'name' => $site, 'publisher' => ['@id' => $org['@id']], 'inLanguage' => 'en-IN',
            'potentialAction' => ['@type' => 'SearchAction', 'target' => $base . '/search?q={search_term_string}', 'query-input' => 'required name=search_term_string']];
        if (seo_opt('site_alt_name') !== '') $website['alternateName'] = (string)seo_opt('site_alt_name');
        $graph[] = $website;
        $pageId = $canonical . '#webpage';
        if ($imgInfo) {
            $graph[] = array_filter(['@type' => 'ImageObject', '@id' => $imgInfo['url'], 'url' => $imgInfo['url'], 'width' => $imgInfo['width'] ? (string)$imgInfo['width'] : null, 'height' => $imgInfo['height'] ? (string)$imgInfo['height'] : null, 'caption' => $imgInfo['alt'] ?: $title, 'inLanguage' => (($GLOBALS['edu_page_lang'] ?? 'en') === 'hi' ? 'hi-IN' : 'en-IN')]);
        }
        // Posts and Pages get the whole graph; lists, archives and the homepage keep their own WebPage / breadcrumb data
        $full = in_array($kind, ['post', 'page'], true);
        // Breadcrumbs
        $crumbs = [['Home', $base . '/']];
        if ($isArticle && !empty($ctx['category']['name'])) $crumbs[] = [$ctx['category']['name'], $base . '/categories/' . $ctx['category']['slug']];
        if ($kind !== 'home') $crumbs[] = [$ctx['crumb'] ?? preg_replace('/\s*' . preg_quote((string)seo_opt('sep'), '/') . '\s*' . preg_quote($site, '/') . '$/u', '', $title), $canonical];
        if ($full) $graph[] = ['@type' => 'BreadcrumbList', '@id' => $canonical . '#breadcrumb', 'itemListElement' => array_map(fn($c, $i) => ['@type' => 'ListItem', 'position' => (string)($i + 1), 'item' => ['@id' => $c[1], 'name' => $c[0]]], $crumbs, array_keys($crumbs))];
        $pageType = ['author' => 'ProfilePage', 'search' => 'SearchResultsPage', 'category' => 'CollectionPage', 'tag' => 'CollectionPage', 'home' => 'WebPage'][$kind] ?? 'WebPage';
        $webpage = ['@type' => $pageType, '@id' => $pageId, 'url' => $canonical, 'name' => $title, 'isPartOf' => ['@id' => $base . '/#website'], 'inLanguage' => (($GLOBALS['edu_page_lang'] ?? 'en') === 'hi' ? 'hi-IN' : 'en-IN'), 'breadcrumb' => ['@id' => $canonical . '#breadcrumb']];
        if ($desc !== '') $webpage['description'] = $desc;
        if ($imgInfo) $webpage['primaryImageOfPage'] = ['@id' => $imgInfo['url']];
        if ($isArticle) { $webpage['datePublished'] = date('c', strtotime($post['date'])); if (!empty($post['updated_at'])) $webpage['dateModified'] = date('c', strtotime($post['updated_at'])); }
        if ($kind === 'home') $webpage['about'] = ['@id' => $org['@id']];
        if ($full) $graph[] = $webpage;
        $a = $ctx['author'] ?? null;
        if ($a && !empty($a['name'])) {
            $aUrl = $base . '/author/' . ($a['slug'] ?? '');
            $person = ['@type' => 'Person', '@id' => $aUrl, 'name' => $a['name'], 'url' => $aUrl];
            if (!empty($a['bio'])) $person['description'] = edu_seo_excerpt((string)$a['bio'], 300);
            if (!empty($a['designation'])) $person['jobTitle'] = $a['designation'];
            if (!empty($a['profile_image'])) { $pi = edu_seo_abs((string)$a['profile_image']); $person['image'] = ['@type' => 'ImageObject', '@id' => $pi, 'url' => $pi, 'caption' => $a['name'], 'inLanguage' => 'en-IN']; }
            $sa = array_values(array_filter([$a['facebook'] ?? '', $a['twitter'] ?? '', $a['instagram'] ?? '', $a['linkedin'] ?? '', $a['threads'] ?? '']));
            $person['sameAs'] = $sa ?: [$base];
            if (!empty($a['qualifications'])) $person['hasCredential'] = array_map(fn($q) => ['@type' => 'EducationalOccupationalCredential', 'name' => trim($q)], array_filter(explode(',', (string)$a['qualifications'])));
            if (!empty($a['languages_known'])) $person['knowsLanguage'] = array_values(array_filter(array_map('trim', explode(',', (string)$a['languages_known']))));
            $person['worksFor'] = ['@id' => $org['@id']];

            $graph[] = $person;
        }
        if ($isArticle) {
            $type = in_array($ctx['schema'] ?? '', ['NewsArticle', 'BlogPosting', 'Article'], true) ? $ctx['schema'] : (in_array(seo_opt('schema_default'), ['NewsArticle', 'BlogPosting', 'Article'], true) ? seo_opt('schema_default') : 'Article');
            $art = ['@type' => $type, 'headline' => mb_substr($title, 0, 110), 'description' => $desc, 'articleSection' => $ctx['category']['name'] ?? null,
                'keywords' => trim(implode(', ', array_filter(array_merge([(string)($ctx['focuskw'] ?? '')], $ctx['tags'] ?? []))), ', ') ?: null,
                'author' => $a ? ['@id' => $base . '/author/' . ($a['slug'] ?? ''), 'name' => $a['name']] : ['@id' => $org['@id']],
                'datePublished' => date('c', strtotime($post['date'])), 'dateModified' => date('c', strtotime($post['updated_at'] ?? $post['date'])),
                'image' => $imgInfo ? ['@id' => $imgInfo['url']] : null, 'copyrightYear' => date('Y', strtotime($post['date'])), 'name' => $title,
                '@id' => $canonical . '#schema-' . (int)$post['id'], 'isPartOf' => ['@id' => $pageId], 'publisher' => ['@id' => $org['@id']], 'inLanguage' => (($GLOBALS['edu_page_lang'] ?? 'en') === 'hi' ? 'hi-IN' : 'en-IN'), 'mainEntityOfPage' => ['@id' => $pageId],
                'wordCount' => count(preg_split('/\s+/u', trim(html_entity_decode(strip_tags(str_replace('<', ' <', (string)$post['content'])), ENT_QUOTES, 'UTF-8')), -1, PREG_SPLIT_NO_EMPTY)) ?: null];
            $graph[] = array_filter($art, fn($v) => $v !== null && $v !== '');
            if (!empty($ctx['faq'])) {
                $graph[] = ['@type' => 'FAQPage', '@id' => $canonical . '#faq', 'mainEntity' => array_values(array_map(fn($f) => ['@type' => 'Question', 'name' => $f['q'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['a']]], array_filter($ctx['faq'], fn($f) => !empty($f['q']) && !empty($f['a']))))];
            }
        }
        $o[] = '<script type="application/ld+json" class="edu-schema-graph">' . json_encode(['@context' => 'https://schema.org', '@graph' => $graph], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';
    }
    return "<!-- Search Engine Optimization: " . $e(edu_site_name()) . " SEO -->\n" . implode("\n", $o) . "\n<!-- /SEO -->\n";
}
