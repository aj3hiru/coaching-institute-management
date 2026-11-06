<?php
// Pages made in Admin → Pages (shown at /slug). The table is created on first use.
function edu_pages_table(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo->exec("CREATE TABLE IF NOT EXISTS site_pages (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(200) NOT NULL, slug VARCHAR(160) NOT NULL, content MEDIUMTEXT NOT NULL,
        meta_description VARCHAR(300) DEFAULT NULL, seo_title VARCHAR(200) DEFAULT NULL, noindex TINYINT(1) NOT NULL DEFAULT 0,
        template ENUM('default','full') NOT NULL DEFAULT 'default', status ENUM('published','draft') NOT NULL DEFAULT 'published',
        author_user_id INT DEFAULT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_slug (slug)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/** Slugs that already belong to the site (files, built-in pages) and can't be used for a page. */
function edu_page_slug_taken(string $slug): bool
{
    if (preg_match('/-\d+$/', $slug)) return true; // looks like a post address (title-123)
    $reserved = ['admin', 'api', 'assets', 'uploads', 'cache', 'includes', 'components', 'templates', 'pages', 'search', 'categories', 'category', 'tag', 'author', 'state-jobs', 'latest-updates', 'sitemap', 'rss', 'story', 'stories', 'web-stories', 'documents', 'newsletter', 'push_notifications', 'vendor', 'index', 'post', 'page'];
    return in_array($slug, $reserved, true) || (is_file(ROOT_PATH . '/' . $slug . '.php') && !edu_page_is_stub($slug)) || is_dir(ROOT_PATH . '/' . $slug);
}

// The server applies .htaccess changes only after a restart, so each page also gets a tiny /slug.php that loads it
// (the existing "address → file.php" rule then serves /slug right away).
const EDU_PAGE_STUB_MARK = '// EDU_PAGE_STUB';

function edu_page_is_stub(string $slug): bool
{
    $f = ROOT_PATH . '/' . $slug . '.php';
    return is_file($f) && str_contains((string)file_get_contents($f, false, null, 0, 200), EDU_PAGE_STUB_MARK);
}

function edu_page_stub_write(string $slug): void
{
    if (!preg_match('/^[a-z0-9-]{1,160}$/', $slug)) return;
    $f = ROOT_PATH . '/' . $slug . '.php';
    if (is_file($f) && !edu_page_is_stub($slug)) return; // never touch a real site file
    file_put_contents($f, "<?php\n" . EDU_PAGE_STUB_MARK . " — created by Admin → Pages; loads the page \"$slug\".\n\$_GET['slug'] = '$slug';\nrequire __DIR__ . '/page.php';\n");
}

function edu_page_stub_remove(string $slug): void
{
    if (preg_match('/^[a-z0-9-]{1,160}$/', $slug) && edu_page_is_stub($slug)) @unlink(ROOT_PATH . '/' . $slug . '.php');
}


// ── Built-in pages (About Us, Contact Us, policies) → normal Pages that can be edited in Admin → Pages ──────────
const EDU_BUILTIN_PAGES = ['about-us', 'contact-us', 'privacy-policy', 'terms-of-service', 'cookie-policy', 'editorial-policy'];

/** True once a published Page exists for this built-in address (its old file then shows the Page). */
function edu_builtin_is_page(PDO $pdo, string $slug): bool
{
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM site_pages WHERE slug = ?");
        $st->execute([$slug]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
}

/** Copies the built-in pages' current text into Pages (once each). Returns the titles that were created. */
function edu_builtin_convert(PDO $pdo, int $userId): array
{
    edu_pages_table($pdo);
    $made = [];
    foreach (EDU_BUILTIN_PAGES as $slug) {
        $file = ROOT_PATH . "/pages/$slug.php";
        if (!is_file($file) || edu_builtin_is_page($pdo, $slug)) continue;
        // Render the old page and keep only its main text.
        $html = (function () use ($file) {
            $GLOBALS['__edu_builtin_render'] = true;
            extract($GLOBALS, EXTR_SKIP); // the old files use config variables like $site_url and $contact_email
            ob_start();
            try { include $file; } catch (Throwable $e) {}
            $out = (string)ob_get_clean();
            unset($GLOBALS['__edu_builtin_render']);
            return $out;
        })();
        if (!preg_match('#<main[^>]*>(.*)</main>#is', $html, $m)) continue;
        if (preg_match('#<b>(Warning|Notice|Deprecated|Fatal error)</b>|(Warning|Notice|Deprecated): #', $m[1])) continue; // never save a page with PHP errors in it
        $title = preg_match('#<h1[^>]*>(.*?)</h1>#is', $m[1], $h) ? trim(strip_tags($h[1])) : ucwords(str_replace('-', ' ', $slug));
        $body = preg_replace('#<h1[^>]*>.*?</h1>#is', '', $m[1], 1);
        // the contact form becomes the [contact_form] shortcode; scripts, comments and layout wrappers go
        $body = preg_replace('#<div id="ct-form".*?<div id="formResponse"[^>]*></div>\s*</div>#is', '[contact_form]', $body);
        $body = preg_replace(['#<script\b.*?</script>#is', '#<!--.*?-->#s', '#<style\b.*?</style>#is'], '', $body);
        $body = preg_replace('#^\s*<div class="container">(.*)</div>\s*$#is', '$1', trim($body));
        $body = preg_replace("#\n\s*\n+#", "\n", trim($body));
        $seoTitle = preg_match('#<title>(.*?)</title>#is', $html, $t) ? html_entity_decode(trim($t[1]), ENT_QUOTES, 'UTF-8') : null;
        $desc = preg_match('#<meta name="description" content="([^"]*)"#i', $html, $d) ? html_entity_decode($d[1], ENT_QUOTES, 'UTF-8') : null;
        $pdo->prepare("INSERT INTO site_pages (title, slug, content, meta_description, seo_title, noindex, template, status, author_user_id) VALUES (?, ?, ?, ?, ?, 0, 'default', 'published', ?)")
            ->execute([$title, $slug, $body, $desc ? mb_substr($desc, 0, 300) : null, $seoTitle ? mb_substr($seoTitle, 0, 200) : null, $userId]);
        $made[] = $title;
    }
    return $made;
}
