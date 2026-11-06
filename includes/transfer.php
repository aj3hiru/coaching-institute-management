<?php
// Posts / pages between sites — this site, another EduMint site or the StoryTimes CMS (Next.js). One ZIP format
// for all of them: manifest.json + posts/<slug>.json (or pages/<slug>.json) + media/… (the images).
// Each item carries its address path ("/slug" or "/slug-12"), so an import keeps the same URL (without the domain),
// its SEO fields, categories, tags, FAQs and images. Older EduMint exports (one posts.json) can still be imported.

const EDU_TX_ROBOTS = ['nofollow' => 'robots_nofollow', 'noarchive' => 'robots_noarchive', 'noimageindex' => 'robots_noimageindex', 'nosnippet' => 'robots_nosnippet'];

/** Upload paths ("uploads/…") of the images used in some HTML, with the exact text that points at them. */
function edu_tx_media_refs(string $html): array
{
    $out = [];
    $add = function (string $u) use (&$out) {
        $u = trim($u);
        if ($u === '' || isset($out[$u])) return;
        $path = preg_replace('#^(?:https?://[^/]+)?/?#i', '', $u);
        $path = strtok($path, '?#');
        if (preg_match('#^uploads/[^"\'<>]+\.(webp|jpe?g|png|gif|avif|svg)$#i', $path)) $out[$u] = $path;
    };
    preg_match_all('#\s(?:src|href|poster)=["\']([^"\']+)["\']#i', $html, $m);
    foreach ($m[1] as $u) $add($u);
    preg_match_all('#\ssrcset=["\']([^"\']+)["\']#i', $html, $m);
    foreach ($m[1] as $set) foreach (explode(',', $set) as $part) $add(preg_split('/\s+/', trim($part))[0] ?? '');
    return $out;
}

function edu_tx_zip_path(string $upload): string
{
    return 'media/' . preg_replace('#^uploads/#', '', ltrim($upload, '/'));
}

/** Builds the export ZIP (posts of some categories, or all pages). Returns [file, count]. */
function edu_tx_export(PDO $pdo, string $type, array $cats = []): array
{
    require_once __DIR__ . '/tools.php';
    $zipFile = edu_private_dir('exports') . '/' . ($type === 'posts' ? 'posts' : 'pages') . '_export_' . date('Y-m-d_His') . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Could not create the ZIP file.');
    $added = [];
    $addFile = function (string $upload) use ($zip, &$added): ?string {
        $upload = ltrim($upload, '/');
        $zp = edu_tx_zip_path($upload);
        if (isset($added[$zp])) return $zp;
        $abs = ROOT_PATH . '/' . $upload;
        if (!is_file($abs)) return null;
        $zip->addFile($abs, $zp);
        $added[$zp] = true;
        return $zp;
    };
    $contentMedia = function (string $html) use ($addFile): array {
        $list = [];
        foreach (edu_tx_media_refs($html) as $src => $path) if ($zp = $addFile($path)) $list[] = ['original_src' => $src, 'zip_path' => $zp];
        return $list;
    };
    $names = [];
    $count = 0;
    if ($type === 'posts') {
        $cats = array_values(array_filter(array_map('intval', $cats)));
        $sql = "SELECT p.*, a.name AS author_name, c.name AS cat_name, c.slug AS cat_slug, m.file_path AS banner_path, m.responsive_set AS banner_set, m.alt_text AS banner_alt, s.state_name
            FROM posts p LEFT JOIN authors a ON a.id = p.author_id LEFT JOIN categories c ON c.id = p.category_id LEFT JOIN media m ON m.id = p.featured_image_id
            LEFT JOIN states s ON s.id = p.state_id WHERE p.status = 'published'";
        if ($cats) $sql .= " AND (p.category_id IN (" . implode(',', $cats) . ") OR p.id IN (SELECT post_id FROM post_categories WHERE category_id IN (" . implode(',', $cats) . ")))";
        $metaSt = $pdo->prepare("SELECT meta_key, meta_value FROM post_meta WHERE post_id = ?");
        $tagSt = $pdo->prepare("SELECT t.name, t.slug FROM tags t JOIN post_tag pt ON pt.tag_id = t.id WHERE pt.post_id = ?");
        $catSt = $pdo->prepare("SELECT c.name, c.slug FROM categories c JOIN post_categories pc ON pc.category_id = c.id WHERE pc.post_id = ? AND c.id <> ?");
        $used = [];
        foreach ($pdo->query($sql . " ORDER BY p.date")->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $metaSt->execute([$p['id']]);
            $meta = array_filter($metaSt->fetchAll(PDO::FETCH_KEY_PAIR), fn($v) => $v !== null && $v !== '');
            foreach (EDU_TX_ROBOTS as $mine => $cms) if (isset($meta[$mine])) $meta[$cms] = $meta[$mine]; // the CMS names them robots_*
            $tagSt->execute([$p['id']]);
            $catSt->execute([$p['id'], (int)$p['category_id']]);
            $path = postUrl($p['slug'], $p['id']);
            $item = [
                'title' => $p['title'], 'slug' => ltrim($path, '/'), 'path' => $path, 'content' => (string)$p['content'],
                'excerpt' => '', 'status' => $p['status'], 'created_at' => $p['date'] ? date('c', strtotime($p['date'])) : null,
                'updated_at' => $p['updated_at'] ? date('c', strtotime($p['updated_at'])) : null, 'last_date' => $p['last_date'],
                'faq_json' => $p['faq_json'] ?: null, 'category_name' => $p['cat_name'], 'category_slug' => $p['cat_slug'],
                'additional_cats' => $catSt->fetchAll(PDO::FETCH_ASSOC), 'author_name' => $p['author_name'],
                'meta_keywords' => $meta['keywords'] ?? '', 'meta_description' => $meta['description'] ?? '', 'meta' => $meta,
                'featured_image' => null, 'featured_image_alt' => $p['banner_alt'], 'content_media' => $contentMedia((string)$p['content']),
                'tags' => $tagSt->fetchAll(PDO::FETCH_ASSOC), 'state' => $p['state_name'],
            ];
            if ($p['banner_path'] && ($zp = $addFile($p['banner_path']))) {
                $item['featured_image'] = $zp;
                $resp = [];
                foreach (json_decode((string)$p['banner_set'], true) ?: [] as $size => $rel) if ($rel && ($z = $addFile($rel))) $resp[$size] = $z;
                if ($resp) $item['featured_image_responsive'] = $resp;
            }
            $file = $item['slug'] !== '' ? $item['slug'] : 'post-' . $p['id'];
            if (isset($used[$file])) $file .= '-' . $p['id'];
            $used[$file] = true;
            $zip->addFromString('posts/' . $file . '.json', json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            if ($p['cat_name']) $names[$p['cat_name']] = true;
            $count++;
        }
        if (!$count) { $zip->close(); @unlink($zipFile); throw new RuntimeException('No published posts in the chosen categories.'); }
    } else {
        foreach ($pdo->query("SELECT * FROM site_pages ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $item = ['title' => $p['title'], 'slug' => $p['slug'], 'path' => '/' . $p['slug'], 'content' => (string)$p['content'], 'status' => $p['status'],
                'meta_title' => (string)$p['seo_title'], 'meta_description' => (string)$p['meta_description'], 'noindex' => (int)$p['noindex'], 'template' => $p['template'],
                'created_at' => $p['created_at'] ? date('c', strtotime($p['created_at'])) : null, 'updated_at' => $p['updated_at'] ? date('c', strtotime($p['updated_at'])) : null,
                'content_media' => $contentMedia((string)$p['content'])];
            $zip->addFromString('pages/' . $p['slug'] . '.json', json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            $count++;
        }
        if (!$count) { $zip->close(); @unlink($zipFile); throw new RuntimeException('There are no pages to export.'); }
    }
    $zip->addFromString('manifest.json', json_encode([
        'type' => $type === 'posts' ? 'posts_export' : 'pages_export', 'cms_version' => 'edumint-php', 'format_version' => 2,
        'site_url' => rtrim(SITE_URL, '/'), 'site_name' => edu_site_name(), 'export_date' => date('c'),
        $type === 'posts' ? 'total_posts' : 'total_pages' => $count, 'categories' => array_keys($names), 'exported_by' => $_SESSION['username'] ?? '',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    $zip->close();
    return [$zipFile, $count];
}

/**
 * Reads an export ZIP (this format, the CMS format, or an older EduMint posts.json / pages.json) into
 * ['type' => posts|pages, 'source' => [...], 'items' => [...]] — items in the shared shape. Throws when it isn't one.
 */
function edu_tx_read(string $zipFile): array
{
    $zip = new ZipArchive();
    if ($zip->open($zipFile) !== true) throw new RuntimeException('This file is not a valid ZIP.');
    try {
        $manifest = $zip->getFromName('manifest.json');
        if ($manifest !== false) {
            $m = json_decode($manifest, true);
            if (!is_array($m) || !in_array($m['type'] ?? '', ['posts_export', 'pages_export'], true)) throw new RuntimeException('manifest.json is not from an Export (unknown type).');
            $type = $m['type'] === 'posts_export' ? 'posts' : 'pages';
            $prefix = $type . '/';
            $items = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $n = $zip->getNameIndex($i);
                if (!str_starts_with($n, $prefix) || !str_ends_with($n, '.json')) continue;
                $it = json_decode((string)$zip->getFromIndex($i), true);
                if (!is_array($it) || trim((string)($it['title'] ?? '')) === '') continue;
                $items[] = $it;
            }
            $source = ['name' => (string)($m['site_name'] ?? ''), 'url' => (string)($m['site_url'] ?? ''), 'app' => (string)($m['cms_version'] ?? ''), 'date' => (string)($m['export_date'] ?? '')];
        } else {
            $json = $zip->getFromName('posts.json');
            $type = 'posts';
            if ($json === false) { $json = $zip->getFromName('pages.json'); $type = 'pages'; }
            if ($json === false) throw new RuntimeException('This ZIP is not an Export file (no manifest.json, posts.json or pages.json inside).');
            $d = json_decode($json, true);
            if (!is_array($d) || !isset($d['items']) || !is_array($d['items'])) throw new RuntimeException('The export file inside the ZIP is damaged.');
            $items = [];
            foreach ($d['items'] as $it) {
                if (!is_array($it) || trim((string)($it['title'] ?? '')) === '') continue;
                $cm = [];
                foreach (edu_tx_media_refs((string)($it['content'] ?? '')) as $src => $path) $cm[] = ['original_src' => $src, 'zip_path' => $path];
                if ($type === 'posts') {
                    $tags = array_map(fn($t) => ['name' => (string)$t, 'slug' => trim(preg_replace('/[^a-z0-9]+/', '-', strtolower((string)$t)), '-')], (array)($it['tags'] ?? []));
                    $items[] = ['title' => $it['title'], 'slug' => (string)($it['slug'] ?? ''), 'content' => (string)($it['content'] ?? ''), 'created_at' => $it['date'] ?? null,
                        'last_date' => $it['last_date'] ?? null, 'faq_json' => $it['faq_json'] ?? null, 'author_name' => $it['author'] ?? '',
                        'category_name' => $it['category']['name'] ?? '', 'category_slug' => $it['category']['slug'] ?? '', 'additional_cats' => (array)($it['categories'] ?? []),
                        'state' => $it['state'] ?? null, 'tags' => $tags, 'meta' => (array)($it['meta'] ?? []), 'status' => 'published',
                        'featured_image' => $it['featured']['path'] ?? null, 'featured_image_responsive' => (array)($it['featured']['sizes'] ?? []),
                        'featured_image_alt' => $it['featured']['alt'] ?? null, 'content_media' => $cm];
                } else {
                    $items[] = ['title' => $it['title'], 'slug' => (string)($it['slug'] ?? ''), 'path' => '/' . ($it['slug'] ?? ''), 'content' => (string)($it['content'] ?? ''),
                        'status' => $it['status'] ?? 'published', 'meta_title' => $it['seo_title'] ?? '', 'meta_description' => $it['meta_description'] ?? '',
                        'noindex' => (int)($it['noindex'] ?? 0), 'template' => $it['template'] ?? 'default', 'content_media' => $cm];
                }
            }
            $source = ['name' => '', 'url' => (string)($d['site'] ?? ''), 'app' => 'edumint-php (older export)', 'date' => (string)($d['exported'] ?? '')];
        }
    } finally {
        $zip->close();
    }
    if (!$items) throw new RuntimeException('The file has no ' . ($type ?? 'posts') . ' in it.');
    return ['type' => $type, 'source' => $source, 'items' => $items];
}

/** The address an item gets here: its original path ("/slug", "/slug-12") when that works, else its slug. */
function edu_tx_target_slug(array $it, string $type): string
{
    $path = trim(strtolower((string)($it['path'] ?? '')), '/');
    if ($type === 'posts' && edu_slug_only_from() === PHP_INT_MAX) $path = ''; // without clean addresses the path can't be kept
    if ($path !== '' && preg_match('/^[a-z0-9-]{1,150}$/', $path)) return $path;
    $slug = generateSlug((string)($it['slug'] ?? ''));
    if ($slug === '') $slug = generateSlug((string)$it['title']);
    return $slug !== '' ? $slug : ($type === 'posts' ? 'post' : 'page');
}

/** Where an item would land and what is already there. */
function edu_tx_existing(PDO $pdo, array $it, string $type): ?array
{
    $slug = edu_tx_target_slug($it, $type);
    if ($type === 'pages') {
        $st = $pdo->prepare("SELECT id, title FROM site_pages WHERE slug = ? LIMIT 1");
        $st->execute([$slug]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if ($slug === 'post') return null;
    // a clean-address post with this slug, or an older post whose /slug-ID is this path
    $st = $pdo->prepare("SELECT id, title FROM posts WHERE slug = ? AND id >= ? LIMIT 1");
    $st->execute([$slug, min(edu_slug_only_from(), PHP_INT_MAX)]);
    if ($r = $st->fetch(PDO::FETCH_ASSOC)) return $r;
    if (preg_match('/^(.+)-(\d+)$/', $slug, $m)) {
        $st = $pdo->prepare("SELECT id, title FROM posts WHERE id = ? AND slug = ? LIMIT 1");
        $st->execute([(int)$m[2], $m[1]]);
        if ($r = $st->fetch(PDO::FETCH_ASSOC)) return $r;
    }
    if (edu_slug_only_from() === PHP_INT_MAX) {
        $st = $pdo->prepare("SELECT id, title FROM posts WHERE slug = ? LIMIT 1");
        $st->execute([$slug]);
        if ($r = $st->fetch(PDO::FETCH_ASSOC)) return $r;
    }
    return null;
}

/** Check before importing: counts, items already on this site, authors named in the file. */
function edu_tx_scan(PDO $pdo, array $data): array
{
    $conflicts = [];
    $authors = [];
    foreach ($data['items'] as $i => $it) {
        if ($data['type'] === 'posts' && !empty($it['author_name'])) $authors[(string)$it['author_name']] = true;
        if ($ex = edu_tx_existing($pdo, $it, $data['type'])) {
            $conflicts[] = ['i' => $i, 'title' => (string)$it['title'], 'path' => '/' . edu_tx_target_slug($it, $data['type']), 'existing' => (string)$ex['title']];
        }
    }
    return ['type' => $data['type'], 'count' => count($data['items']), 'source' => $data['source'], 'conflicts' => $conflicts, 'authors' => array_keys($authors),
        'titles' => array_map(fn($it) => (string)$it['title'], array_slice($data['items'], 0, 8))];
}

/**
 * Imports one item. $opt = ['decision' => keep_both|replace|skip (for an item already here), 'author' => 0 (match the
 * original author by name, else $opt['fallback_author']) | author id, 'status' => file|draft|published, 'user_id' => …].
 * $cache holds the images already copied in this import. Returns ['outcome' => …, 'title' => …, 'url' => …].
 */
function edu_tx_import_item(PDO $pdo, ZipArchive $zip, array $it, string $type, array $opt, array &$cache): array
{
    $title = mb_substr(trim((string)$it['title']), 0, 200);
    $existing = edu_tx_existing($pdo, $it, $type);
    $decision = $existing ? ($opt['decision'] ?? 'keep_both') : 'new';
    if ($decision === 'skip') return ['outcome' => 'skipped', 'title' => $title, 'url' => ''];
    $status = ($opt['status'] ?? 'file') === 'file' ? (in_array($it['status'] ?? '', ['published', 'draft'], true) ? $it['status'] : 'draft') : (($opt['status'] ?? '') === 'published' ? 'published' : 'draft');

    $folder = 'uploads/imported/' . date('Y-m');
    if (!is_dir(ROOT_PATH . '/' . $folder)) mkdir(ROOT_PATH . '/' . $folder, 0755, true);
    $copy = function (?string $zp) use ($zip, $folder, &$cache, $pdo, $opt): ?string {
        $zp = ltrim((string)$zp, '/');
        if ($zp === '' || !preg_match('/\.(webp|jpe?g|png|gif|avif|svg)$/i', $zp)) return null;
        if (isset($cache[$zp])) return $cache[$zp];
        $bytes = $zip->getFromName($zp);
        if ($bytes === false) return null;
        $name = substr(preg_replace('/[^a-z0-9.-]+/', '-', strtolower(basename($zp))), -80);
        $to = $folder . '/' . substr(uniqid(), -6) . '-' . $name;
        if (file_put_contents(ROOT_PATH . '/' . $to, $bytes) === false) return null;
        return $cache[$zp] = $to;
    };
    // images inside the text: every place that pointed at the old file now points at the copy here
    $content = (string)($it['content'] ?? '');
    foreach ((array)($it['content_media'] ?? []) as $cm) {
        if (empty($cm['original_src']) || empty($cm['zip_path'])) continue;
        if ($new = $copy($cm['zip_path'])) $content = str_replace((string)$cm['original_src'], '/' . $new, $content);
    }
    $slug = edu_tx_target_slug($it, $type);

    if ($type === 'pages') {
        require_once __DIR__ . '/pages.php';
        if ($decision === 'keep_both') {
            $base = $slug; $n = 2;
            $chk = $pdo->prepare("SELECT COUNT(*) FROM site_pages WHERE slug = ?");
            do { $slug = $base . '-copy' . ($n > 2 ? '-' . chr(95 + $n) : ''); $chk->execute([$slug]); $n++; } while ($chk->fetchColumn() && $n < 30);
        }
        if ($decision !== 'replace' && edu_page_slug_taken($slug)) return ['outcome' => 'skipped', 'title' => $title . ' (address /' . $slug . ' is used by the site)', 'url' => ''];
        $vals = [$title, $content, mb_substr((string)($it['meta_description'] ?? ''), 0, 300), mb_substr((string)($it['meta_title'] ?? ''), 0, 200), (int)($it['noindex'] ?? 0),
            in_array($it['template'] ?? '', ['default', 'full'], true) ? $it['template'] : 'default', $status];
        if ($decision === 'replace') {
            $pdo->prepare("UPDATE site_pages SET title = ?, content = ?, meta_description = ?, seo_title = ?, noindex = ?, template = ?, status = ? WHERE id = ?")->execute([...$vals, $existing['id']]);
            $outcome = 'replaced';
        } else {
            $pdo->prepare("INSERT INTO site_pages (title, content, meta_description, seo_title, noindex, template, status, slug, author_user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([...$vals, $slug, $opt['user_id'] ?? null]);
            edu_page_stub_write($slug);
            $outcome = $decision === 'keep_both' ? 'renamed' : 'imported';
        }
        return ['outcome' => $outcome, 'title' => $title, 'url' => '/' . $slug];
    }

    // ---- posts ----
    $authorId = (int)($opt['author'] ?? 0);
    if (!$authorId && !empty($it['author_name'])) {
        $a = $pdo->prepare("SELECT id FROM authors WHERE name = ? LIMIT 1");
        $a->execute([(string)$it['author_name']]);
        $authorId = (int)$a->fetchColumn();
    }
    if (!$authorId) $authorId = (int)($opt['fallback_author'] ?? 0);
    if (!$authorId) throw new RuntimeException('No author to give the posts to — create an author first.');
    $catId = function (string $name, string $slug) use ($pdo): ?int {
        $name = trim($name);
        if ($name === '' && $slug === '') return null;
        $slug = $slug !== '' ? generateSlug($slug) : generateSlug($name);
        $st = $pdo->prepare("SELECT id FROM categories WHERE slug = ? OR name = ? LIMIT 1");
        $st->execute([$slug, $name]);
        if ($id = $st->fetchColumn()) return (int)$id;
        $pdo->prepare("INSERT INTO categories (name, slug) VALUES (?, ?)")->execute([$name !== '' ? $name : $slug, $slug !== '' ? $slug : 'category-' . substr(uniqid(), -4)]);
        return (int)$pdo->lastInsertId();
    };
    $cid = $catId((string)($it['category_name'] ?? ''), (string)($it['category_slug'] ?? '')) ?? (int)$pdo->query("SELECT id FROM categories ORDER BY id LIMIT 1")->fetchColumn();
    $stateId = null;
    if (!empty($it['state'])) {
        $s = $pdo->prepare("SELECT id FROM states WHERE state_name = ? LIMIT 1");
        $s->execute([(string)$it['state']]);
        $stateId = $s->fetchColumn() ?: null;
    }
    $featId = null;
    if (!empty($it['featured_image']) && ($main = $copy((string)$it['featured_image']))) {
        $sizes = [];
        foreach ((array)($it['featured_image_responsive'] ?? []) as $k => $v) if ($v && ($n = $copy((string)$v))) $sizes[$k] = $n;
        $pdo->prepare("INSERT INTO media (file_path, responsive_set, file_type, alt_text, uploaded_at, uploaded_by) VALUES (?, ?, 'banner', ?, NOW(), ?)")
            ->execute([$main, $sizes ? json_encode($sizes, JSON_UNESCAPED_SLASHES) : null, mb_substr((string)($it['featured_image_alt'] ?? $title), 0, 255), $opt['user_id'] ?? null]);
        $featId = (int)$pdo->lastInsertId();
    }
    $date = !empty($it['created_at']) && strtotime((string)$it['created_at']) ? date('Y-m-d H:i:s', strtotime((string)$it['created_at'])) : date('Y-m-d H:i:s');
    $lastDate = !empty($it['last_date']) && strtotime((string)$it['last_date']) ? date('Y-m-d', strtotime((string)$it['last_date'])) : null;
    $faq = !empty($it['faq_json']) ? (is_string($it['faq_json']) ? $it['faq_json'] : json_encode($it['faq_json'], JSON_UNESCAPED_UNICODE)) : null;

    if ($decision === 'replace') {
        $pid = (int)$existing['id'];
        $pdo->prepare("UPDATE posts SET title = ?, content = ?, faq_json = ?, last_date = ?, author_id = ?, state_id = ?, category_id = ?, status = ?, featured_image_id = COALESCE(?, featured_image_id), updated_at = NOW() WHERE id = ?")
            ->execute([$title, $content, $faq, $lastDate, $authorId, $stateId, $cid, $status, $featId, $pid]);
        $pdo->prepare("DELETE FROM post_meta WHERE post_id = ?")->execute([$pid]);
        $pdo->prepare("DELETE FROM post_tag WHERE post_id = ?")->execute([$pid]);
        $pdo->prepare("DELETE FROM post_categories WHERE post_id = ?")->execute([$pid]);
        @unlink(ROOT_PATH . '/cache/post_' . $pid . '.html');
        $outcome = 'replaced';
    } else {
        $clean = edu_slug_only_from() !== PHP_INT_MAX;
        if ($slug !== 'post' && $clean) $slug = edu_unique_post_slug($pdo, $slug); // "keep both" gets "-2", "-3" …
        $pdo->prepare("INSERT INTO posts (title, content, faq_json, last_date, author_id, state_id, category_id, slug, status, featured_image_id, date, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())")
            ->execute([$title, $content, $faq, $lastDate, $authorId, $stateId, $cid, $slug, $status, $featId, $date]);
        $pid = (int)$pdo->lastInsertId();
        $outcome = $decision === 'keep_both' ? 'renamed' : 'imported';
    }
    $pc = $pdo->prepare("INSERT IGNORE INTO post_categories (post_id, category_id) VALUES (?, ?)");
    $pc->execute([$pid, $cid]);
    foreach ((array)($it['additional_cats'] ?? []) as $c) if (is_array($c) && ($x = $catId((string)($c['name'] ?? ''), (string)($c['slug'] ?? '')))) $pc->execute([$pid, $x]);
    // SEO and the other post fields (the CMS calls the robots options robots_*)
    $meta = (array)($it['meta'] ?? []);
    if (!empty($it['meta_description']) && empty($meta['description'])) $meta['description'] = $it['meta_description'];
    if (!empty($it['meta_keywords']) && empty($meta['keywords'])) $meta['keywords'] = $it['meta_keywords'];
    foreach (EDU_TX_ROBOTS as $mine => $cms) if (!isset($meta[$mine]) && isset($meta[$cms])) $meta[$mine] = $meta[$cms];
    foreach (EDU_TX_ROBOTS as $cms) unset($meta[$cms]);
    $pm = $pdo->prepare("INSERT INTO post_meta (post_id, meta_key, meta_value) VALUES (?, ?, ?)");
    foreach ($meta as $k => $v) {
        if (!preg_match('/^[a-z_]{1,50}$/', (string)$k) || $v === null || $v === '' || is_array($v)) continue;
        $pm->execute([$pid, $k, (string)$v]);
    }
    foreach ((array)($it['tags'] ?? []) as $t) {
        $tn = trim((string)(is_array($t) ? ($t['name'] ?? '') : $t));
        if ($tn === '') continue;
        $ts = generateSlug(is_array($t) && !empty($t['slug']) ? (string)$t['slug'] : $tn) ?: 'tag-' . substr(md5($tn), 0, 6);
        $q = $pdo->prepare("SELECT id FROM tags WHERE slug = ? LIMIT 1");
        $q->execute([$ts]);
        $tid = $q->fetchColumn();
        if (!$tid) { $pdo->prepare("INSERT INTO tags (name, slug, created_at, updated_at) VALUES (?, ?, NOW(), NOW())")->execute([$tn, $ts]); $tid = $pdo->lastInsertId(); }
        $pdo->prepare("INSERT IGNORE INTO post_tag (post_id, tag_id) VALUES (?, ?)")->execute([$pid, $tid]);
    }
    $s = $pdo->prepare("SELECT slug FROM posts WHERE id = ?");
    $s->execute([$pid]);
    return ['outcome' => $outcome, 'title' => $title, 'url' => postUrl((string)$s->fetchColumn(), $pid)];
}
