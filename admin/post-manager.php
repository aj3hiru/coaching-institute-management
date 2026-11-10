<?php
// ============================================================
// POST MANAGER — WordPress-style post editor with TinyMCE (Visual / Text), Rank Math-style SEO panel,
// featured image, categories, tags, FAQs, key points, summary and table templates.
// TinyMCE is served from jsDelivr (open-source build, no monthly editor-load limit, cached by the browser).
// To use Tiny Cloud instead, define('TINYMCE_API_KEY', '…') in includes/config.php (the domain must be added at tiny.cloud).
// ============================================================
$tinymce_src = defined('TINYMCE_API_KEY') && TINYMCE_API_KEY !== ''
    ? 'https://cdn.tiny.cloud/1/' . rawurlencode(TINYMCE_API_KEY) . '/tinymce/7/tinymce.min.js'
    : 'https://cdn.jsdelivr.net/npm/tinymce@7.6.1/tinymce.min.js';
$tinymce_cloud = defined('TINYMCE_API_KEY') && TINYMCE_API_KEY !== '';

// ============================================================
// SECTION 1: GLOBAL PATH CONSTANTS
// ============================================================
define('DROOT_PATH',      $_SERVER['DOCUMENT_ROOT']);        // /var/www/html
define('UPLOAD_DIR_URL', 'uploads/');                       // DB path  (relative, no leading slash)
define('UPLOAD_DIR_ABS', DROOT_PATH . '/uploads/');          // Disk path (absolute)

// ============================================================
// SECTION 2: BOOTSTRAP
// ============================================================
require_once DROOT_PATH . '/admin/components/guard.php';
require_once INCLUDES_PATH . '/taxonomy.php';
require_once INCLUDES_PATH . '/seo.php';



// ============================================================
// SECTION 3: AUTH CHECK
// ============================================================
$me = admin_guard(['admin', 'editor', 'author']);
if (!admin_blog_access()) { http_response_code(403); exit('Access Denied'); } // Users → Blogs → No access
$canSeo = $me['role'] === 'admin' || (edu_seo_module('role_manager') ? seo_opt('role_' . $me['role'] . '_seo') === '1' : true) || !edu_seo_advanced();
// "Has this focus keyword been used before?" check for the SEO panel
if (isset($_GET['kwcheck'])) {
    header('Content-Type: application/json');
    $kw = mb_strtolower(trim((string)$_GET['kwcheck']));
    $st = $pdo->prepare("SELECT COUNT(*) FROM post_meta WHERE meta_key = 'focus_keyword' AND post_id <> ? AND (LOWER(meta_value) = ? OR LOWER(meta_value) LIKE ?)");
    $st->execute([(int)($_GET['id'] ?? 0), $kw, $kw . ',%']);
    exit(json_encode(['used' => (int)$st->fetchColumn()]));
}
// Table templates saved from the editor (Insert → Templates → "Save selected table as template")
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['tpl_action'] ?? '', ['save', 'delete'], true)) {
    csrf_check();
    header('Content-Type: application/json');
    if (!in_array($me['role'], ['admin', 'blog_admin', 'editor'], true)) exit(json_encode(['ok' => false, 'error' => 'Only admins and editors can manage templates.']));
    if ($_POST['tpl_action'] === 'save') {
        $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 100);
        $html = (string)($_POST['html'] ?? '');
        if ($name === '' || stripos($html, '<table') === false) exit(json_encode(['ok' => false, 'error' => 'Give the template a name and select a table.']));
        $html = preg_replace('#<script\b.*?</script>#is', '', $html);
        $pdo->prepare("INSERT INTO table_templates (name, html) VALUES (?, ?)")->execute([$name, $html]);
        admin_log('post_edit', 'Saved table template: ' . $name);
    } else {
        $pdo->prepare("DELETE FROM table_templates WHERE id = ?")->execute([(int)($_POST['id'] ?? 0)]);
    }
    exit(json_encode(['ok' => true]));
}
// Posts may hold long tables: make sure the content column is MEDIUMTEXT (16 MB) instead of TEXT (64 KB).
try {
    $col = $pdo->query("SHOW COLUMNS FROM posts LIKE 'content'")->fetch(PDO::FETCH_ASSOC);
    if ($col && strtolower($col['Type']) === 'text') $pdo->exec("ALTER TABLE posts MODIFY content MEDIUMTEXT NOT NULL");
} catch (Throwable $e) {}


// ============================================================
// SECTION 4: DETECT EDIT MODE
// ============================================================
$is_edit   = isset($_GET['id']) && is_numeric($_GET['id']);
$post_id   = $is_edit ? (int)$_GET['id'] : null;
$post_data = null;
$post_meta = [];

// ============================================================
// SECTION 5: FETCH POST DATA (edit mode)
// ============================================================
if ($is_edit) {
    try {
        $stmt = $pdo->prepare("
            SELECT p.*,
                   pm_keywords.meta_value AS keywords,
                   pm_desc.meta_value     AS description
            FROM posts p
            LEFT JOIN post_meta pm_keywords ON p.id = pm_keywords.post_id AND pm_keywords.meta_key = 'keywords'
            LEFT JOIN post_meta pm_desc     ON p.id = pm_desc.post_id     AND pm_desc.meta_key     = 'description'
            WHERE p.id = ?
        ");
        $stmt->execute([$post_id]);
        $post_data = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$post_data) {
            die('Post not found');
        }
        $stmt_meta = $pdo->prepare("SELECT meta_key, meta_value FROM post_meta WHERE post_id = ?");
        $stmt_meta->execute([$post_id]);
        $post_meta = array_column($stmt_meta->fetchAll(PDO::FETCH_ASSOC), 'meta_value', 'meta_key');
        if ($me['role'] !== 'admin' && !admin_can('blogs.edit_all')) {
            $stmt_own = $pdo->prepare("SELECT 1 FROM authors WHERE id = ? AND user_id = ?");
            $stmt_own->execute([$post_data['author_id'], $_SESSION['user_id']]);
            if (!$stmt_own->fetch() || !admin_can('blogs.edit_own')) {
                http_response_code(403);
                die('Access Denied — you can only edit your own posts.');
            }
        }
    } catch (PDOException $e) {
        die('Error loading post: ' . $e->getMessage());
    }
}

if (!$is_edit && !admin_can('blogs.create')) { http_response_code(403); die('Access Denied — your role cannot create posts.'); }

// ============================================================
// SECTION 6: FETCH ADDITIONAL CATEGORIES (edit mode)
// ============================================================
$additional_category_ids = [];
if ($is_edit) {
    $stmt = $pdo->prepare("SELECT category_id FROM post_categories WHERE post_id = ?");
    $stmt->execute([$post_id]);
    $additional_category_ids = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'category_id');
}

// ============================================================
// SECTION 7: FETCH ALL TAGS + SELECTED TAGS (edit mode)
// ============================================================
$all_tags        = $pdo->query("SELECT * FROM tags ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$selected_tag_ids = [];
if ($is_edit) {
    $stmt = $pdo->prepare("SELECT tag_id FROM post_tag WHERE post_id = ?");
    $stmt->execute([$post_id]);
    $selected_tag_ids = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'tag_id');
}

// ============================================================
// SECTION 8: HANDLE LEGACY IMAGE UPLOAD (Quill toolbar image)
// ============================================================
if (isset($_FILES['image']) && !isset($_POST['title'])) {
    if (!file_exists(UPLOAD_DIR_ABS)) {
        mkdir(UPLOAD_DIR_ABS, 0777, true);
    }

    $file_extension = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
    $file_name      = uniqid('img_') . '.' . $file_extension;
    $db_path        = UPLOAD_DIR_URL . $file_name;   // saved in DB
    $disk_path      = UPLOAD_DIR_ABS . $file_name;   // written to disk

    if (move_uploaded_file($_FILES['image']['tmp_name'], $disk_path)) {
        $stmt = $pdo->prepare("INSERT INTO media (file_path, file_type, uploaded_at) VALUES (?, 'image', NOW())");
        $stmt->execute([$db_path]);
        echo json_encode(['location' => $db_path]);
        exit;
    }

    http_response_code(500);
    echo json_encode(['error' => 'Upload failed']);
    exit;
}

// ============================================================
// SECTION 9: HANDLE FORM SUBMISSION (create + update)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['title'])) {
    try {
        $pdo->beginTransaction();

        // --- 9a. Sanitize inputs ---
        $title                   = trim((string)($_POST['title'] ?? ''));
        $content                 = $_POST['content'];
        $faq_json                = !empty($_POST['faq_json'])        ? $_POST['faq_json']              : null;
        $last_date               = !empty($_POST['last_date'])       ? $_POST['last_date']             : null;
        $author_id               = $_POST['author_id'];
        $state_id                = !empty($_POST['state_id'])        ? (int)$_POST['state_id']         : null;
        $category_id             = !empty($_POST['category_id'])     ? (int)$_POST['category_id']      : edu_default_category($pdo); // like WordPress: no category → the default one
        $additional_category_ids = !empty($_POST['additional_category_ids'])
                                   ? array_map('intval', $_POST['additional_category_ids'])
                                   : [];
        $additional_category_ids = array_diff($additional_category_ids, [$category_id]);
        $slug                    = generateSlug(!empty($_POST['slug']) ? (string)$_POST['slug'] : $title);
        if ($slug === '') $slug = 'post'; // e.g. a Hindi-only title: the address becomes /post-ID
        $status                  = in_array($_POST['status'] ?? '', ['published', 'draft', 'archived'], true) ? $_POST['status'] : 'draft';
        // Authors without the "Publish" right save drafts (an editor publishes them).
        if ($status === 'published' && $me['role'] !== 'admin' && !admin_can('blogs.publish') && ($post_data['status'] ?? '') !== 'published') $status = 'draft';
        $meta_keywords           = $_POST['meta_keywords']           ?? '';
        $meta_description        = $_POST['meta_description']        ?? '';
        $edit_post_id            = $_POST['post_id']                 ?? null;
        $featured_image_id       = $_POST['existing_featured_image_id'] ?? null;

        // Tags typed as "tag1, tag2" (new editor) → existing tag ids, or new names prefixed so a numeric name is never taken as an id
        if (isset($_POST['tags_csv'])) {
            $_POST['tags'] = [];
            $find_tag = $pdo->prepare("SELECT id FROM tags WHERE slug = ? OR name = ? LIMIT 1");
            foreach (array_unique(array_filter(array_map('trim', explode(',', (string)$_POST['tags_csv'])))) as $tag_name) {
                $find_tag->execute([generateSlug($tag_name), $tag_name]);
                $tid = $find_tag->fetchColumn();
                $_POST['tags'][] = $tid ? (string)(int)$tid : 'new:' . $tag_name;
            }
        }

        // --- 9b. Logging variables ---
        $log_ip = $_SERVER['REMOTE_ADDR']     ?? '';
        $log_ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

        // --------------------------------------------------------
        // 9c. Banner upload (multi-size WebP responsive set)
        // --------------------------------------------------------
        if (
            isset($_FILES['banner']['name'], $_FILES['banner']['tmp_name'])
            && is_array($_FILES['banner']['name'])
            && array_filter($_FILES['banner']['name'])
        ) {
            if (!file_exists(UPLOAD_DIR_ABS)) {
                mkdir(UPLOAD_DIR_ABS, 0777, true);
            }

            // Delete old banner files from disk + DB row — only when no other post uses that image (it may have been picked from the Media Library)
            $old_in_use = 0;
            if ($featured_image_id) {
                $chk = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE featured_image_id = ? AND id <> ?");
                $chk->execute([(int)$featured_image_id, (int)($edit_post_id ?: 0)]);
                $old_in_use = (int)$chk->fetchColumn();
            }
            if ($featured_image_id && !$old_in_use) {
                $stmt = $pdo->prepare("SELECT file_path, responsive_set FROM media WHERE id = ?");
                $stmt->execute([$featured_image_id]);
                if ($old = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    if (!empty($old['file_path']) && file_exists(DROOT_PATH . '/' . $old['file_path'])) {
                        @unlink(DROOT_PATH . '/' . $old['file_path']);
                    }
                    if (!empty($old['responsive_set'])) {
                        foreach (json_decode($old['responsive_set'], true) ?: [] as $v) {
                            if (!empty($v) && file_exists(DROOT_PATH . '/' . $v)) {
                                @unlink(DROOT_PATH . '/' . $v);
                            }
                        }
                    }
                    $pdo->prepare("DELETE FROM media WHERE id = ?")->execute([$featured_image_id]);
                }
            }

            // Upload new responsive set
            $banner_versions = [];
            foreach ($_FILES['banner']['name'] as $i => $name) {
                if ($_FILES['banner']['error'][$i] === UPLOAD_ERR_OK) {
                    $tmp       = $_FILES['banner']['tmp_name'][$i];
                    $filename  = $slug . '-' . uniqid() . '.webp';
                    $db_path   = UPLOAD_DIR_URL . $filename;   // stored in DB
                    $disk_path = UPLOAD_DIR_ABS . $filename;   // written to disk

                    if (move_uploaded_file($tmp, $disk_path)) {
                        if      (strpos($name, '_xl') !== false) $banner_versions['xl']           = $db_path;
                        elseif  (strpos($name, '_lg') !== false) $banner_versions['lg']           = $db_path;
                        elseif  (strpos($name, '_md') !== false) $banner_versions['md']           = $db_path;
                        elseif  (strpos($name, '_sm') !== false) $banner_versions['sm']           = $db_path;
                        else                                     $banner_versions['auto_' . $i]   = $db_path;
                    }
                }
            }

            if (!empty($banner_versions)) {
                $main_banner = $banner_versions['xl'] ?? reset($banner_versions);
                $stmt = $pdo->prepare("
                    INSERT INTO media (file_path, responsive_set, file_type, alt_text, uploaded_at)
                    VALUES (?, ?, 'banner', ?, NOW())
                ");
                $stmt->execute([$main_banner, json_encode($banner_versions), $title . ' banner']);
                $featured_image_id = $pdo->lastInsertId();
            }
        }

        // --------------------------------------------------------
        // 9d. Process base64 inline images from Quill content
        // --------------------------------------------------------
        $content = preg_replace_callback(
            '/<img[^>]+src=["\']data:image\/([a-zA-Z]+);base64,([^"\']+)["\']([^>]*)>/i',
            function ($matches) {
                $image_type  = $matches[1];
                $base64      = $matches[2];
                $extra_attrs = $matches[3];

                $image_data = base64_decode($base64, true);
                if ($image_data === false) return $matches[0];

                if (!file_exists(UPLOAD_DIR_ABS)) {
                    mkdir(UPLOAD_DIR_ABS, 0777, true);
                }

                $file      = uniqid('img_') . '.' . $image_type;
                $db_path   = UPLOAD_DIR_URL . $file;   // stored in DB & src attr
                $disk_path = UPLOAD_DIR_ABS . $file;   // written to disk

                if (file_put_contents($disk_path, $image_data)) {
                    $alt_text = '';
                    if (preg_match('/alt=["\']([^"\']*)["\']/', $extra_attrs, $alt_matches)) {
                        $alt_text = $alt_matches[1];
                    }
                    try {
                        $stmt = $GLOBALS['pdo']->prepare("
                            INSERT INTO media (file_path, file_type, alt_text, uploaded_at)
                            VALUES (?, 'image', ?, NOW())
                        ");
                        $stmt->execute([$db_path, $alt_text]);
                    } catch (PDOException $e) {
                        error_log('Failed to insert media: ' . $e->getMessage());
                    }
                    return '<img src="' . $db_path . '"' . $extra_attrs . '>';
                }

                return $matches[0];
            },
            $content
        );

        // Normalize featured_image_id
        if (empty($featured_image_id) || !is_numeric($featured_image_id)) {
            $featured_image_id = null;
        } else {
            $featured_image_id = (int)$featured_image_id;
        }

        // --------------------------------------------------------
        // 9e. Save post (create or update)
        // --------------------------------------------------------
        $current_post_id = null;
        // Posts with a clean address (/slug, no ID) need a slug no other post or page uses ("-2", "-3" … like WordPress)
        $cleanAddr = $edit_post_id ? ((int)$edit_post_id >= edu_slug_only_from()) : (edu_slug_only_from() !== PHP_INT_MAX);
        if ($cleanAddr && $slug !== 'post') $slug = edu_unique_post_slug($pdo, $slug, (int)$edit_post_id);

        if ($edit_post_id) {
            // UPDATE
            $stmt = $pdo->prepare("
                UPDATE posts
                SET title = ?, content = ?, faq_json = ?, last_date = ?, author_id = ?,
                    state_id = ?, category_id = ?, slug = ?, status = ?,
                    featured_image_id = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                $title, $content, $faq_json, $last_date, $author_id,
                $state_id, $category_id, $slug, $status,
                $featured_image_id, $edit_post_id
            ]);
            $current_post_id = $edit_post_id;

            // Delete cache file on update
            $cache_file = DROOT_PATH . '/cache/post_' . $edit_post_id . '.html';
            if (file_exists($cache_file)) {
                @unlink($cache_file);
            }

            // Clear old meta
            $pdo->prepare("DELETE FROM post_meta WHERE post_id = ?")->execute([$current_post_id]);

        } else {
            // CREATE
            $stmt = $pdo->prepare("
                INSERT INTO posts (title, content, faq_json, last_date, author_id, state_id,
                                   category_id, slug, status, featured_image_id, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $title, $content, $faq_json, $last_date, $author_id,
                $state_id, $category_id, $slug, $status, $featured_image_id
            ]);
            $current_post_id = $pdo->lastInsertId();

            // Link orphaned base64 images to this post
            $stmt = $pdo->prepare("UPDATE media SET post_id = ? WHERE post_id IS NULL AND file_path LIKE 'uploads/img_%'");
            $stmt->execute([$current_post_id]);
        }
        if ($cleanAddr) edu_slugmap_write($pdo); // addresses like /ssc-cgl-2026
        // --------------------------------------------------------
        // 9f. Save meta (keywords & description)
        // --------------------------------------------------------
        if (!empty($meta_keywords)) {
            $stmt = $pdo->prepare("INSERT INTO post_meta (post_id, meta_key, meta_value) VALUES (?, 'keywords', ?)");
            $stmt->execute([$current_post_id, $meta_keywords]);
        }
        if (!empty($meta_description)) {
            $stmt = $pdo->prepare("INSERT INTO post_meta (post_id, meta_key, meta_value) VALUES (?, 'description', ?)");
            $stmt->execute([$current_post_id, $meta_description]);
        }

        // Summary, Key Points and the SEO box fields (same as the new CMS editor)
        $key_points = array_values(array_filter(array_map(fn($k) => mb_substr(trim((string)$k), 0, 500), (array)($_POST['key_points'] ?? [])), 'strlen'));
        $extra_meta = [
            'summary'        => mb_substr(trim((string)($_POST['summary'] ?? '')), 0, 2000),
            'key_points'     => $key_points ? json_encode($key_points, JSON_UNESCAPED_UNICODE) : '',
            'focus_keyword'  => mb_substr(trim((string)($_POST['focus_keyword'] ?? '')), 0, 200),
            'seo_title'      => mb_substr(trim((string)($_POST['seo_title'] ?? '')), 0, 200),
            'schema_type'    => in_array($_POST['schema_type'] ?? '', ['NewsArticle', 'BlogPosting', 'Article', 'JobPosting'], true) ? $_POST['schema_type'] : '',
            'og_title'       => mb_substr(trim((string)($_POST['og_title'] ?? '')), 0, 200),
            'og_description' => mb_substr(trim((string)($_POST['og_description'] ?? '')), 0, 300),
            'noindex'        => (!empty($_POST['noindex']) || ($_POST['robots_mode'] ?? '') === 'noindex') ? '1' : '',
            'nofollow'       => !empty($_POST['nofollow']) ? '1' : '',
            'noarchive'      => !empty($_POST['noarchive']) ? '1' : '',
            'noimageindex'   => !empty($_POST['noimageindex']) ? '1' : '',
            'nosnippet'      => !empty($_POST['nosnippet']) ? '1' : '',
            'og_image'       => mb_substr(trim((string)($_POST['og_image'] ?? '')), 0, 255),
            'pillar'         => !empty($_POST['pillar']) ? '1' : '',
            'canonical'      => filter_var(trim((string)($_POST['canonical'] ?? '')), FILTER_VALIDATE_URL) ?: '',
            // Job card (listing pages)
            'job_card'          => !empty($_POST['job_card']) ? '1' : '',
            // same as the title = "use the title" (stays in step when the title changes later)
            'job_post_name'     => ($jpn = mb_substr(trim((string)($_POST['job_post_name'] ?? '')), 0, 150)) === mb_substr(trim((string)$title), 0, 150) ? '' : $jpn,
            'job_org'           => mb_substr(trim((string)($_POST['job_org'] ?? '')), 0, 150),
            'job_logo'          => preg_match('#^(/|uploads/|https?://)[^\s"<>]+$#', $jl = trim((string)($_POST['job_logo'] ?? ''))) ? mb_substr($jl, 0, 255) : '',
            'job_location'      => mb_substr(trim((string)($_POST['job_location'] ?? '')), 0, 100),
            'job_salary'        => mb_substr(trim(preg_replace('/^\s*₹\s*/u', '', (string)($_POST['job_salary'] ?? ''))), 0, 100),
            'job_vacancies'     => mb_substr(preg_replace('/\D/', '', (string)($_POST['job_vacancies'] ?? '')), 0, 9),
            'job_qualification' => mb_substr(trim((string)(($_POST['job_qualification_sel'] ?? '') === '__other' ? ($_POST['job_qualification_other'] ?? '') : ($_POST['job_qualification_sel'] ?? ''))), 0, 100),
        ];
        if (!$canSeo && $edit_post_id) {
            // no SEO box for this role: keep what was there
            foreach (['focus_keyword', 'seo_title', 'schema_type', 'og_title', 'og_description', 'noindex', 'nofollow', 'noarchive', 'noimageindex', 'nosnippet', 'og_image', 'pillar', 'canonical'] as $keep) $extra_meta[$keep] = (string)($post_meta[$keep] ?? '');
            if (!empty($post_meta['description'])) $meta_description = $post_meta['description'];
        }
        $stmt_extra = $pdo->prepare("INSERT INTO post_meta (post_id, meta_key, meta_value) VALUES (?, ?, ?)");
        foreach ($extra_meta as $mk => $mv) {
            if ($mv !== '') $stmt_extra->execute([$current_post_id, $mk, $mv]);
        }

        // --------------------------------------------------------
        // 9g. Save categories (main + additional)
        // --------------------------------------------------------
        try {
            $pdo->prepare("DELETE FROM post_categories WHERE post_id = ?")->execute([$current_post_id]);

            if ($category_id) {
                $stmt = $pdo->prepare("INSERT IGNORE INTO post_categories (post_id, category_id) VALUES (?, ?)");
                $stmt->execute([$current_post_id, $category_id]);
            }
            if (!empty($additional_category_ids)) {
                $stmt = $pdo->prepare("INSERT IGNORE INTO post_categories (post_id, category_id) VALUES (?, ?)");
                foreach ($additional_category_ids as $cat_id) {
                    $stmt->execute([$current_post_id, $cat_id]);
                }
            }
        } catch (Exception $e) {
            error_log('Failed to save post categories: ' . $e->getMessage());
        }

        // --------------------------------------------------------
        // 9h. Save tags (create new tag if not exists)
        // --------------------------------------------------------
        try {
            $pdo->prepare("DELETE FROM post_tag WHERE post_id = ?")->execute([$current_post_id]);

            if (!empty($_POST['tags']) && is_array($_POST['tags'])) {
                $tags_input      = array_unique($_POST['tags']);
                $stmt_check_slug = $pdo->prepare("SELECT id FROM tags WHERE slug = ? LIMIT 1");
                $stmt_insert_tag = $pdo->prepare("INSERT INTO tags (name, slug, created_at, updated_at) VALUES (?, ?, NOW(), NOW())");
                $stmt_link_tag   = $pdo->prepare("INSERT IGNORE INTO post_tag (post_id, tag_id) VALUES (?, ?)");

                foreach ($tags_input as $input_val) {
                    $tag_id_to_link = null;

                    if (is_numeric($input_val)) {
                        $tag_id_to_link = (int)$input_val;
                    } else {
                        $tag_name = trim(preg_replace('/^new:/', '', $input_val));
                        if (empty($tag_name)) continue;

                        $tag_slug = generateSlug($tag_name);
                        $stmt_check_slug->execute([$tag_slug]);
                        $existing_tag = $stmt_check_slug->fetch(PDO::FETCH_ASSOC);

                        if ($existing_tag) {
                            $tag_id_to_link = $existing_tag['id'];
                        } else {
                            try {
                                $stmt_insert_tag->execute([$tag_name, $tag_slug]);
                                $tag_id_to_link = $pdo->lastInsertId();
                            } catch (Exception $e) {
                                error_log("Error creating new tag '$tag_name': " . $e->getMessage());
                                continue;
                            }
                        }
                    }

                    if ($tag_id_to_link) {
                        $stmt_link_tag->execute([$current_post_id, $tag_id_to_link]);
                    }
                }
            }
        } catch (Exception $e) {
            error_log('Failed to process tags: ' . $e->getMessage());
        }

        // --------------------------------------------------------
        // 9i. Commit transaction
        // --------------------------------------------------------
        $pdo->commit();

        // List pages that show this post (homepage, Latest Updates, Latest Jobs) are rebuilt on the next visit
        foreach (['index_*.html', 'latest-updates*.html', 'latest-jobs-*.html'] as $__cg) @array_map('unlink', glob(DROOT_PATH . '/cache/' . $__cg) ?: []);
        if (function_exists('apcu_delete') && class_exists('APCUIterator') && apcu_enabled()) @apcu_delete(new APCUIterator('/^total_published_posts_cat_/')); // category post counts

        // Push: notify subscribers about a brand-new published post (Push Notifications → Settings).
        if (!$edit_post_id && $status === 'published') {
            try {
                require_once INCLUDES_PATH . '/push.php';
                if (es('push', 'auto_send', '0') === '1' && edu_push_ready()) {
                    $fimg = '';
                    if ($featured_image_id) { $fi = $pdo->prepare("SELECT file_path FROM media WHERE id = ?"); $fi->execute([$featured_image_id]); $fimg = (string)$fi->fetchColumn(); }
                    edu_push_queue($title, $meta_description ?: '', postUrl($slug, $current_post_id), $fimg ? '/' . ltrim($fimg, '/') : '', (int)$current_post_id);
                }
            } catch (Throwable $e) { error_log('auto push failed: ' . $e->getMessage()); }
        }

        // --------------------------------------------------------
        // 9j. Activity log
        // --------------------------------------------------------
        try {
            $post_id_for_url = $edit_post_id ?? $current_post_id;
            $log_action_type = $edit_post_id ? 'post_edit'   : 'post_create';
            $log_verb        = $edit_post_id ? 'Updated'     : 'Created';
            $log_desc        = $log_verb . ' Post: ' . mb_strimwidth($title, 0, 50, '...') . ' (ID: ' . $post_id_for_url . ')';

            $stmt_log = $pdo->prepare("
                INSERT INTO activity_logs (user_id, action_type, description, ip_address, user_agent)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt_log->execute([$_SESSION['user_id'], $log_action_type, $log_desc, $log_ip, $log_ua]);
        } catch (Exception $e) {
            error_log('Activity log failed: ' . $e->getMessage());
        }

        // --------------------------------------------------------
        // 9k. Redirect after success
        // --------------------------------------------------------
        // Tell search engines (Instant Indexing) about the new / changed / unpublished address.
        try {
            require_once INCLUDES_PATH . '/indexing.php';
            $post_url = rtrim(SITE_URL, '/') . postUrl($slug, (int)$current_post_id);
            if ($status === 'published' && empty($extra_meta['noindex'])) edu_ix_queue($pdo, [$post_url], 'update');
            elseif ($edit_post_id && ($post_data['status'] ?? '') === 'published') edu_ix_queue($pdo, [$post_url], 'delete');
        } catch (Throwable $e) { error_log('indexing queue: ' . $e->getMessage()); }
        edu_clear_page_cache();
        // Stay in the editor (like WordPress) with a notice and a link to the post.
        header('Location: /admin/post-manager.php?id=' . (int)$current_post_id . '&saved=' . ($edit_post_id ? 'updated' : ($status === 'published' ? 'published' : 'draft')));
        exit;

    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log('Database error: ' . $e->getMessage());
        $form_error = 'Failed to save post: ' . htmlspecialchars($e->getMessage());
    }
}

// ============================================================
// SECTION 10: FETCH FORM DATA (authors, categories, states, templates)
// ============================================================
// Admins can credit any author; others their own author profile(s). The post's current author is always listed so saving never changes it silently.
if (in_array($_SESSION['role'] ?? '', ['admin', 'blog_admin'], true)) { // a blog admin credits any author too
    $authors = $pdo->query("SELECT * FROM authors ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt = $pdo->prepare("SELECT * FROM authors WHERE user_id = ? OR id = ? ORDER BY name");
    $stmt->execute([$_SESSION['user_id'], $is_edit ? (int)$post_data['author_id'] : 0]);
    $authors = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
$categories = $pdo->query("SELECT * FROM categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$states     = $pdo->query("SELECT id, state_name FROM states ORDER BY state_name")->fetchAll(PDO::FETCH_ASSOC);
require_once INCLUDES_PATH . '/table_templates.php';
$templates  = array_merge(edu_table_templates(), array_map(fn($t) => $t + ['custom' => true], $pdo->query("SELECT * FROM table_templates ORDER BY name")->fetchAll(PDO::FETCH_ASSOC)));

// ============================================================
// SECTION 11: PAGE VARIABLES
// ============================================================
$seo_robots = 'noindex, nofollow, noarchive, nosnippet';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title><?= $is_edit ? 'Edit Post' : 'Add New Post' ?> - <?= htmlspecialchars(edu_site_name()) ?></title>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/components/shell.php'; admin_shell_head(); ?>
<link href="/assets/css/post-editor.css?v=<?= @filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/post-editor.css') ?>" rel="stylesheet">
<style id="pm2-css">
/* ===== Post Manager 2 : all CSS lives inside this file ===== */
/* --- two independent scroll areas (content | sidebar) on desktop --- */
@media (min-width:1024px){
  html.pm2-split,html.pm2-split body{overflow:hidden !important}
  /* the page itself never scrolls here (the two columns do), so the header stays in place — sticky would add the admin bar's 32px twice and cover the top of the editor */
  html.pm2-split .top-nav{position:relative !important;top:0 !important}
  /* the page itself doesn't scroll here, so the admin sidebar must not be sticky (with overflow:hidden its sticky offset
     was added below the admin bar again → a 36px gap above the logo) */
  html.pm2-split .admin-container>.sidebar,html.pm2-split body:has(#wpadminbar) .admin-container>.sidebar{position:relative;top:0 !important}
  html.pm2-split .editor-layout{grid-template-columns:minmax(0,1fr) 320px;grid-template-rows:minmax(0,1fr);align-items:stretch;gap:1.25rem;overflow:hidden}
  html.pm2-split .editor-main,html.pm2-split .editor-sidebar-panel{min-height:0;height:100%;overflow-y:auto;overflow-x:hidden;overscroll-behavior:contain;scrollbar-width:none;-ms-overflow-style:none;padding-bottom:48px}
  html.pm2-split .editor-main{padding-right:8px}
  /* panels keep their natural height; the column scrolls instead of squeezing them */
  html.pm2-split .editor-main>*,html.pm2-split .editor-sidebar-panel>*{flex:0 0 auto !important;min-height:auto}
  html.pm2-split .editor-main>.meta-panel,html.pm2-split .editor-sidebar-panel>.meta-panel{overflow:visible}
  html.pm2-split .editor-sidebar-panel>.meta-panel .meta-panel-body{overflow:visible}
  html.pm2-split .editor-sidebar-panel{padding-right:4px}
  html.pm2-split .editor-main::-webkit-scrollbar,html.pm2-split .editor-sidebar-panel::-webkit-scrollbar{display:none;width:0;height:0}
}
/* --- editor box (WordPress classic look) --- */
.pm2-wrap{position:relative;background:#fff;border:0;border-radius:4px;overflow:visible}
.meta-panel:has(.pm2-wrap){overflow:visible}
.pm2-wrap .be-tabs{display:flex;align-items:flex-end;justify-content:space-between;padding:8px 8px 0;background:#f6f7f7;border-bottom:1px solid #dcdcde;border-radius:4px 4px 0 0}
.pm2-addmedia{display:inline-flex;align-items:center;gap:6px;margin:0 0 8px;padding:5px 12px;border:1px solid #2271b1;border-radius:3px;background:#f6f7f7;color:#2271b1;font:600 13px/1.6 inherit;cursor:pointer}
.pm2-addmedia:hover{background:#f0f0f1;border-color:#0a4b78;color:#0a4b78}
.pm2-wrap .be-tabs .be-mode{margin:0 0 -1px !important;border:0 !important;gap:0;display:inline-flex}
.pm2-wrap .be-tabs .be-mode button{border:1px solid #dcdcde;border-bottom:0;border-radius:3px 3px 0 0;background:#ebebeb;color:#50575e;padding:6px 14px;font-size:13px;font-weight:600;margin-left:4px}
.pm2-wrap .be-tabs .be-mode button.is-active{background:#fff !important;color:#1d2327 !important;border-bottom:1px solid #fff}
.pm2-wrap .be-status{display:none}
#pe-visual>textarea#editor{display:block;width:100%;height:560px;visibility:hidden;border:0}
.pm2-wrap .pe-html{min-height:560px;border-radius:0 0 4px 4px;background:#fff !important;color:#1d2327 !important;padding:24px 32px !important;border:0 !important;outline:0 !important;box-shadow:none !important;font-size:14px;line-height:1.7}
/* no coloured focus border around the writing area */
.pm2-wrap .tox .tox-edit-area::before{display:none !important;border:0 !important;opacity:0 !important}
.pm2-wrap .tox .tox-edit-area__iframe,.pm2-wrap .tox .tox-edit-area{outline:0 !important;box-shadow:none !important}
.pm2-wrap .tox.tox-tinymce:focus-within,.pm2-wrap .tox .tox-edit-focus{outline:0 !important;box-shadow:none !important}
/* focus: koi border/colour change nahi (click karne par bhi) */
.pm2-wrap:focus-within,.pm2-wrap.be-wrap:focus-within,.meta-panel:has(.pm2-wrap):focus-within,.meta-panel:has(.pm2-wrap):focus{border-color:#dcdcde !important;box-shadow:none !important;outline:0 !important}
.pm2-wrap .tox .tox-edit-area.tox-edit-focus,.pm2-wrap .tox .tox-edit-area.tox-edit-focus::before,.pm2-wrap .tox .tox-tinymce--focus,.pm2-wrap .tox.tox-tinymce.tox-tinymce--focus{border:0 !important;outline:0 !important;box-shadow:none !important}
.pm2-wrap .tox .tox-edit-area__iframe:focus,.pm2-wrap .tox .tox-edit-area__iframe:focus-visible{outline:0 !important;box-shadow:none !important}
/* writing area padding (original jaisa) */
.pm2-wrap .tox .tox-edit-area__iframe{box-sizing:border-box !important;padding:0 32px !important;background:#fff !important}
/* Text tab: hamesha white background */
html body .pm2-wrap textarea#html-source.pe-html,html body .pm2-wrap textarea#html-source.pe-html:focus,html body .pm2-wrap textarea#html-source.pe-html:active{background:#fff !important;background-color:#fff !important;color:#1d2327 !important;-webkit-text-fill-color:#1d2327 !important;caret-color:#1d2327;color-scheme:light;border:0 !important;outline:0 !important;box-shadow:none !important;font-family:Consolas,Monaco,'Courier New',monospace}
/* --- TinyMCE skin tuned to WordPress --- */
.pm2-wrap .tox.tox-tinymce{border:0 !important;border-radius:0 0 4px 4px !important;overflow:visible !important;font-family:inherit}
.pm2-wrap .tox .tox-editor-container{overflow:visible !important;background:#fff}
.pm2-wrap .tox .tox-editor-header{position:sticky !important;top:0;z-index:30;background:#f6f7f7 !important;border-bottom:1px solid #dcdcde !important;box-shadow:none !important;padding:0 !important}
body:has(#wpadminbar) .pm2-wrap .tox .tox-editor-header{top: var(--wpab-top,32px)}
html.pm2-split .pm2-wrap .tox .tox-editor-header,html.pm2-split body:has(#wpadminbar) .pm2-wrap .tox .tox-editor-header{top:0}
.pm2-wrap .tox .tox-toolbar,.pm2-wrap .tox .tox-toolbar__primary,.pm2-wrap .tox .tox-toolbar-overlord{background:#f6f7f7 !important;background-image:none !important}
.pm2-wrap .tox .tox-toolbar__group{padding:0 4px;border-right:0 !important}
.pm2-wrap .tox .tox-tbtn{height:30px;margin:2px 1px;border-radius:3px}
.pm2-wrap .tox .tox-tbtn:hover{background:#e9e9ea}
.pm2-wrap .tox .tox-tbtn--enabled,.pm2-wrap .tox .tox-tbtn--enabled:hover{background:#dcdcde}
.pm2-wrap .tox .tox-tbtn--select{width:auto}
.pm2-wrap .tox .tox-statusbar{background:#f6f7f7;border-top:1px solid #dcdcde;color:#50575e;font-size:12px;height:28px;border-radius:0 0 4px 4px}
.pm2-wrap .tox .tox-promotion,.pm2-wrap .tox .tox-statusbar__branding{display:none}
.pm2-wrap .tox .tox-edit-area__iframe{background:#fff}
/* compact toolbar (normal WordPress size) + extra tools behind the "more" button */
.pm2-wrap .tox .tox-tbtn{height:26px !important;min-width:26px;margin:1px !important}
.pm2-wrap .tox .tox-tbtn svg{transform:scale(.88)}
.pm2-wrap .tox .tox-tbtn__select-label{font-size:13px}
.pm2-wrap .tox .tox-toolbar__group{padding:0 3px !important;margin:0}
.pm2-wrap .tox .tox-toolbar,.pm2-wrap .tox .tox-toolbar__primary{min-height:0 !important;padding:2px 4px !important}
.pm2-wrap:not(.pm2-more) .tox-editor-header .tox-toolbar__group:nth-child(n+7),
.pm2-wrap:not(.pm2-more) .tox-toolbar__group.pm2-hide{display:none !important}
/* toolbar tab tak chhupa rehta hai jab tak groups set na ho jaye (no blink) */
.pm2-wrap:not(.pm2-ready) .tox .tox-editor-header{visibility:hidden}
.pm2-wrap .tox .tox-statusbar{height:24px !important;min-height:0}
.pm2-wrap .be-tabs{padding-top:5px !important}
.pm2-addmedia{margin-bottom:5px !important;padding:3px 10px !important}
.pm2-wrap .be-tabs .be-mode button{padding:4px 12px !important}
/* dropdown / menu hover: blue background + white text */
body .tox .tox-collection__item--active,body .tox .tox-collection--list .tox-collection__item--active,body .tox .tox-collection--list .tox-collection__item:hover{background:#2271b1 !important;color:#fff !important}
body .tox .tox-collection__item--active *,body .tox .tox-collection__item:hover *{color:#fff !important;fill:#fff !important}
body .tox .tox-collection__item--active .tox-collection__item-icon svg,body .tox .tox-collection__item:hover .tox-collection__item-icon svg{fill:#fff !important}
/* popups must sit above TinyMCE and the sticky toolbar */
.mlb-overlay{z-index:100050 !important}
.table-modal{z-index:100060 !important}
body .tox-tinymce-aux,body .tox.tox-silver-sink{z-index:100100 !important}
.table-template-preview{pointer-events:none;user-select:none;display:block}
.table-template-info{flex-direction:column;align-items:stretch;gap:10px}.table-template-info>div{width:100%}
.table-template-info .table-insert-btn{flex:1;border-radius:6px;display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:10px 16px;background:#0c6878;color:#fff}.table-template-info .table-insert-btn:hover{background:#095363}
.table-template-card:hover{transform:none}
.tpl-target{display:grid;gap:8px;margin:0 0 16px;padding:12px 14px;border:1px solid #bfdbfe;background:#eff6ff;border-radius:8px;font-size:14px;color:#1e3a8a}
.tpl-target label{display:flex;gap:8px;align-items:flex-start;cursor:pointer;margin:0}.tpl-target input{margin-top:3px}
/* --- SEO sidebar box: clean spacing, patli lines --- */
.seo-box .meta-panel-header{display:flex;align-items:center;justify-content:space-between;gap:8px}
.seo-box .meta-panel-body{display:flex !important;flex-direction:column;gap:10px !important;padding:12px 14px !important}
.seo-box .seo-score{display:inline-block;flex:0 0 auto;margin:0;padding:2px 9px !important;border:0 !important;border-radius:999px;font-size:12px !important;font-weight:600;line-height:1.5;background:#f0f0f1;color:#50575e}
.seo-box .seo-score.good{background:#e6f4ea;color:#137333}.seo-box .seo-score.warn{background:#fef3c7;color:#92400e}.seo-box .seo-score.bad{background:#fde8e8;color:#b42318}
.seo-box .seo-tabs{display:flex;gap:2px;margin:0;padding:0;border:0;border-bottom:1px solid #e5e7eb}
.seo-box .seo-tabs button{flex:1 1 0;margin:0 0 -1px;padding:6px 4px;border:0 !important;border-bottom:2px solid transparent !important;border-radius:0;background:none;box-shadow:none;font-size:12px;font-weight:600;color:#6b7280;cursor:pointer}
.seo-box .seo-tabs button.active{color:#2271b1;border-bottom-color:#2271b1 !important;background:none}
.seo-box [data-pane]:not([hidden]){display:flex;flex-direction:column;gap:10px}
.seo-box .seo-preview{margin:0;padding:10px 12px;border:1px solid #e5e7eb;border-radius:6px;background:#fff}
.seo-box .seo-label{display:block;margin:0;font-size:12px;font-weight:600;color:#374151;line-height:1.4}
.seo-box .seo-label input,.seo-box .seo-label textarea,.seo-box .seo-label select{display:block;width:100%;box-sizing:border-box;margin:4px 0 0;padding:6px 8px;font-size:13px;font-weight:400}
.seo-box .seo-hint{font-weight:400;color:#6b7280}
.seo-box .seo-meter{display:block;height:3px !important;min-height:0 !important;margin:6px 0 0 !important;padding:0 !important;border:0 !important;border-radius:2px;background:#e5e7eb;overflow:hidden}
.seo-box .seo-meter span{display:block;height:100% !important;width:0;border-radius:2px;background:#9ca3af;transition:width .2s}
.seo-box .seo-meter.good span{background:#16a34a}.seo-box .seo-meter.warn span{background:#f59e0b}.seo-box .seo-meter.bad span{background:#dc2626}
.seo-box .seo-checks{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:2px}
.seo-box .seo-checks li{display:flex;align-items:flex-start;gap:6px;margin:0;padding:3px 0;border:0;font-size:12px;line-height:1.4;color:#374151}
.seo-box .seo-checks li.ok i{color:#16a34a}.seo-box .seo-checks li.no i{color:#dc2626}.seo-box .seo-checks li.info i{color:#6b7280}
.seo-box .seo-check{display:flex;align-items:center;gap:6px;margin:0;font-size:13px}
.seo-box .field-hint{margin:0}
html.tox-fullscreen #wpadminbar,body.tox-fullscreen #wpadminbar{display:none}
.rmx .meta-panel-header{display:flex;align-items:center;justify-content:space-between}
.rmx-score{font-size:12.5px;font-weight:700;padding:3px 10px;border-radius:4px;color:#fff;background:#dc2626;display:inline-flex;align-items:center;gap:5px}.rmx-score::before{content:"\f201";font-family:"Font Awesome 6 Free";font-weight:900;font-size:11px}
.rmx-score.good{background:#16a34a}.rmx-score.warn{background:#f59e0b}.rmx-score.bad{background:#dc2626}
.rmx .meta-panel-body{display:block!important;padding:0!important}
.rmx-tabs{display:grid;grid-template-columns:repeat(4,1fr);border-bottom:1px solid #e5e7eb}
.rmx-tabs button{border:0;background:none;padding:10px 4px;font:inherit;font-size:12px;font-weight:600;color:#6b7280;cursor:pointer;display:flex;flex-direction:column;align-items:center;gap:4px;border-bottom:2px solid transparent;margin-bottom:-1px}
.rmx-tabs button i{font-size:15px}.rmx-tabs button.active{color:#0c6878;border-bottom-color:#0c6878}
.rmx [data-pane]{padding:14px}
.rmx-h{font-size:13px;font-weight:700;color:#1f2937;margin:0 0 8px;display:flex;align-items:center;gap:6px;justify-content:space-between}
.rmx-h i.fa-circle-question{color:#9ca3af;font-size:12px}
.rmx-dev{display:inline-flex;border:1px solid #e5e7eb;border-radius:6px;overflow:hidden}.rmx-dev button{border:0;background:#fff;padding:4px 8px;cursor:pointer;color:#9ca3af}.rmx-dev button.on{background:#f3f4f6;color:#111827}
.rmx-serp{font-family:arial,sans-serif;border:1px solid #e5e7eb;border-radius:8px;padding:12px;background:#fff}
.rmx-serp-site{display:flex;gap:8px;align-items:center;margin-bottom:6px}.rmx-fav{width:26px;height:26px;border-radius:50%;background:#e8f0fe;color:#1a0dab;display:grid;place-items:center;font-size:12px;font-weight:700;flex-shrink:0}
.rmx-serp-name{font-size:13px;color:#202124}.rmx-serp-url{font-size:11.5px;color:#4d5156;word-break:break-all}
.rmx-serp-title{font-size:17px;line-height:1.3;color:#1a0dab;margin-bottom:3px;display:-webkit-box;-webkit-line-clamp:1;-webkit-box-orient:vertical;overflow:hidden}
.rmx-serp-desc{font-size:13px;line-height:1.55;color:#4d5156;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.rmx-serp.mobile{max-width:300px;margin:0 auto}.rmx-serp.mobile .rmx-serp-title{-webkit-line-clamp:2}.rmx-serp.mobile .rmx-serp-desc{-webkit-line-clamp:3}
.rmx-edit{margin-top:10px;border:0;background:#0c6878;color:#fff;border-radius:4px;padding:7px 12px;font:inherit;font-size:13px;font-weight:600;cursor:pointer}
.rmx-kw{display:flex;flex-wrap:wrap;gap:6px;border:1px solid #d1d5db;border-radius:6px;padding:6px;min-height:40px;cursor:text;background:#fff}
.rmx-kw input{border:0!important;outline:0;flex:1;min-width:120px;padding:4px!important;font-size:13px;box-shadow:none!important}
.rmx-chip{display:inline-flex;align-items:center;gap:5px;background:#eef2ff;color:#3730a3;border-radius:99px;padding:3px 6px 3px 10px;font-size:12.5px;font-weight:600}
.rmx-chip.primary{background:#fde68a;color:#78350f}.rmx-chip button{border:0;background:rgba(0,0,0,.08);color:inherit;width:18px;height:18px;border-radius:50%;cursor:pointer;line-height:1;padding:0}
.rmx-check{display:flex!important;align-items:center;gap:7px;font-size:13px!important;font-weight:500!important;color:#374151;margin:10px 0 0!important;cursor:pointer}
.rmx-check input{width:15px;height:15px;accent-color:#0c6878;margin:0}
.rmx-grid2{display:grid;grid-template-columns:1fr 1fr;gap:0 10px}
.rmx-label{display:block;font-size:12.5px;font-weight:700;color:#374151;margin:12px 0 0}
.rmx-label input,.rmx-label textarea{display:block;width:100%;box-sizing:border-box;margin:5px 0 0;padding:7px 9px;border:1px solid #d1d5db;border-radius:6px;font:inherit;font-size:13px;font-weight:400}
.rmx-hint{font-size:11.5px;color:#6b7280;margin:5px 0 0;line-height:1.5}.rmx-hint code{background:#f3f4f6;padding:0 4px;border-radius:3px}
.rmx-cnt{float:right;font-weight:500;color:#6b7280}
.rmx-meter{height:3px;background:#e5e7eb;border-radius:2px;margin-top:6px;overflow:hidden}.rmx-meter span{display:block;height:100%;width:0;background:#9ca3af;transition:width .2s}
.rmx-meter.good span{background:#16a34a}.rmx-meter.warn span{background:#f59e0b}.rmx-meter.bad span{background:#dc2626}
.rmx-group{border-top:1px solid #e5e7eb;margin:12px -14px 0}
.rmx-group-h{width:100%;display:flex;align-items:center;gap:8px;border:0;background:none;padding:12px 14px;font:inherit;font-size:13.5px;font-weight:700;color:#111827;cursor:pointer;text-align:left}
.rmx-group-h i{margin-left:auto;color:#9ca3af;transition:transform .2s}.rmx-group.open .rmx-group-h i{transform:rotate(180deg)}
.rmx-badge{font-size:11px;font-weight:700;padding:2px 8px;border-radius:99px}.rmx-badge.good{background:#dcfce7;color:#166534}.rmx-badge.bad{background:#fee2e2;color:#b91c1c}
.rmx-group ul{list-style:none;margin:0;padding:0 14px 10px;display:none}.rmx-group.open ul{display:block}
.rmx-group li{display:flex;gap:8px;align-items:flex-start;font-size:12.5px;line-height:1.45;color:#374151;padding:5px 0}
.rmx-group li i{margin-top:2px}.rmx-group li.ok i{color:#16a34a}.rmx-group li.no i{color:#dc2626}
.rmx-warn{margin-top:12px;background:#fffbeb;border:1px solid #fde68a;color:#78350f;border-radius:6px;padding:10px;font-size:12.5px}
.rmx-schema{display:flex;gap:10px;align-items:center;border:1px solid #e5e7eb;border-radius:8px;padding:10px}.rmx-schema i{font-size:20px;color:#0c6878}.rmx-schema b{display:block;font-size:13.5px}.rmx-schema small{font-size:11.5px;color:#6b7280}
.rmx-subtabs{display:flex;gap:6px;margin-bottom:10px}.rmx-subtabs button{flex:1;border:1px solid #e5e7eb;background:#fff;border-radius:6px;padding:7px;font:inherit;font-size:12.5px;font-weight:600;cursor:pointer;color:#374151}.rmx-subtabs button.on{background:#0c6878;border-color:#0c6878;color:#fff}
.rmx-card{border:1px solid #dadde1;border-radius:4px;overflow:hidden;background:#f2f3f5}.rmx-card-img{aspect-ratio:1.91/1;background:#e5e7eb;display:grid;place-items:center;font-size:12px;color:#6b7280}.rmx-card-img img{width:100%;height:100%;object-fit:cover;display:block}
.rmx-card-body{padding:8px 10px}.rmx-card-body small{display:block;font-size:11px;color:#606770}.rmx-card-body b{display:block;font-size:14px;color:#1d2129;margin:2px 0;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}.rmx-card-body span{font-size:12px;color:#606770;display:-webkit-box;-webkit-line-clamp:1;-webkit-box-orient:vertical;overflow:hidden}
.rmx-card.tw{border-radius:14px;background:#fff}.rmx-card.tw .rmx-card-body{padding:10px 12px}
.rmx-imgrow{display:flex;gap:6px;margin-top:5px}
.rmx-modal .rmx-label{font-size:13.5px}
.tbl-striped tbody tr:nth-child(even) td{background:#f8fafc}

.jcard-box .jc-f{display:flex;flex-direction:column;gap:4px;margin:0 0 .7rem;font-size:.8rem;font-weight:600;color:var(--gray-700,#374151)}
.jcard-box .jc-f input[type=text]{width:100%;box-sizing:border-box;padding:.5rem .65rem;border:1px solid var(--gray-300,#d1d5db);border-radius:6px;font:inherit;font-size:.85rem;font-weight:400}
.jcard-box .jc-f input[type=text]:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px var(--primary-light,rgba(124,58,237,.15))}
.jc-logo-row{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.jc-logo-row img{width:48px;height:48px;object-fit:contain;border:1px solid #e5e7eb;border-radius:6px;background:#fff}
.jc-switch{display:flex;gap:10px;align-items:flex-start;cursor:pointer;font-size:.85rem;color:#374151}
.jc-switch input{flex-shrink:0;width:36px;height:20px;margin:2px 0 0;appearance:none;-webkit-appearance:none;border-radius:20px;background:#cbd5e1;position:relative;cursor:pointer;transition:background .2s}
.jc-switch input::after{content:"";position:absolute;top:2px;left:2px;width:16px;height:16px;border-radius:50%;background:#fff;transition:transform .2s}
.jc-switch input:checked{background:var(--primary)}.jc-switch input:checked::after{transform:translateX(16px)}
.jc-switch small{display:block;font-weight:400;color:#6b7280;font-size:.75rem;margin-top:2px}
.jc-fields{margin-top:.8rem;padding-top:.8rem;border-top:1px solid #f1f5f9}
.jcard-box .jc-f small{font-weight:400;color:#9ca3af}
.jcard-box .jc-f select,.jcard-box .jc-f input[type=date],.jcard-box .jc-f input.dk-date{width:100%;box-sizing:border-box;padding:.5rem .65rem;border:1px solid var(--gray-300,#d1d5db);border-radius:6px;font:inherit;font-size:.85rem;font-weight:400;background:#fff}
.jcard-box .jc-f select+input{margin-top:6px}
.jc-rupee{display:flex;align-items:stretch;border:1px solid var(--gray-300,#d1d5db);border-radius:6px;overflow:hidden;background:#fff}
.jc-rupee>span{display:flex;align-items:center;padding:0 .6rem;background:#f8fafc;border-right:1px solid var(--gray-300,#d1d5db);font-weight:700;color:#374151}
.jcard-box .jc-rupee input[type=text]{border:0;border-radius:0}
.jcard-box .jc-rupee:focus-within{border-color:var(--primary);box-shadow:0 0 0 3px var(--primary-light,rgba(124,58,237,.15))}
.jc-del{color:var(--danger,#dc2626) !important;border-color:var(--danger,#dc2626) !important}
.llb{position:fixed;inset:0;z-index:100050;background:rgba(15,23,42,.5);display:flex;align-items:flex-start;justify-content:center;padding:6vh 14px 14px}
.llb[hidden]{display:none}
.llb-box{width:min(760px,100%);max-height:84vh;display:flex;flex-direction:column;background:#fff;border-radius:14px;box-shadow:0 24px 60px rgba(0,0,0,.3);overflow:hidden}
.llb-head{display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid #eef0f3;font-size:16px}
.llb-x{width:34px;height:34px;border:0;border-radius:50%;background:#f1f5f9;font-size:20px;cursor:pointer;color:#334155}
.llb-tools{display:flex;gap:10px;align-items:center;padding:12px 18px;flex-wrap:wrap}
.llb-search{position:relative;flex:1;min-width:200px}.llb-search i{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#9ca3af;font-size:13px}
.llb-search input{width:100%;box-sizing:border-box;height:40px;padding:0 12px 0 34px;border:1px solid #d1d5db;border-radius:9px;font:inherit;font-size:14px}
.llb-search input:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px var(--primary-light,rgba(124,58,237,.15))}
.llb-up{display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:0 18px 12px}.llb-up input[type=text]{flex:1;min-width:200px;height:38px;padding:0 10px;border:1px solid #d1d5db;border-radius:8px;font:inherit;font-size:14px}
.llb-file{position:relative;overflow:hidden;cursor:pointer}.llb-file input{position:absolute;inset:0;opacity:0;cursor:pointer}
.llb-msg{font-size:13px;color:#6b7280}
.llb-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:10px;padding:4px 18px 14px;overflow-y:auto}
.llb-item{display:flex;flex-direction:column;align-items:center;gap:6px;padding:10px 8px;border:1px solid #e5e7eb;border-radius:10px;background:#fff;cursor:pointer;font:inherit}
.llb-item:hover{border-color:var(--primary);box-shadow:0 4px 12px rgba(16,24,40,.08)}
.llb-item img{width:64px;height:64px;object-fit:contain}.llb-item span{font-size:12.5px;color:#374151;text-align:center;line-height:1.3;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.llb-empty{grid-column:1/-1;margin:20px 0;text-align:center;color:#6b7280}
.llb-foot{display:flex;justify-content:center;padding:0 18px 14px}
.tox-dialog:has(.eq-help) .tox-dialog__body-content{padding:18px 22px 8px}.tox-dialog:has(.eq-help) .tox-form__group{margin-bottom:16px !important}.tox-dialog:has(.eq-help) .tox-label{margin-bottom:7px;font-weight:600;color:#1f2937;font-size:13.5px}.tox-dialog:has(.eq-help) .tox-textfield{padding:9px 12px;min-height:42px;font-size:14.5px}.tox-dialog:has(.eq-help) .tox-textarea{min-height:220px !important;padding:12px 14px;font-size:14.5px;line-height:1.7}.tox-dialog:has(.eq-help){max-height:calc(100vh - 32px) !important}.tox-dialog:has(.eq-help) .tox-dialog__content-js{display:flex;flex-direction:column;flex:1 1 auto;min-height:0}.tox-dialog:has(.eq-help) .tox-dialog__body{flex:1 1 auto;min-height:0;overflow:hidden}.tox-dialog:has(.eq-help) .tox-dialog__body-content{overflow-y:auto;max-height:none}.tox-dialog:has(.eq-help) .tox-dialog__footer{flex-shrink:0}.tox-dialog:has(.eq-help) .eq-help{margin-top:4px;padding-top:12px;border-top:1px solid #e5e7eb}.tox-dialog:has(.eq-help) .tox-dialog__footer{padding:12px 22px 16px}
.tg-box{display:flex;flex-wrap:wrap;align-items:center;gap:6px;min-height:40px;padding:5px 8px;border:1px solid var(--gray-300,#d1d5db);border-radius:8px;background:#fff;cursor:text}.tg-box:focus-within{border-color:var(--primary,#7c3aed);box-shadow:0 0 0 3px var(--primary-light,#ede9fe)}#tg-chips{display:contents}.tg-chip{display:inline-flex;align-items:center;gap:4px;max-width:100%;padding:3px 4px 3px 9px;border-radius:999px;background:var(--primary-lighter,#f5f3ff);border:1px solid var(--primary-light,#ede9fe);color:var(--gray-800,#1f2937);font-size:13px;line-height:1.3}.tg-chip span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.tg-chip button{width:18px;height:18px;border:0;border-radius:50%;background:transparent;color:var(--gray-500,#6b7280);cursor:pointer;font-size:14px;line-height:1;display:grid;place-items:center;padding:0}.tg-chip button:hover{background:#fee2e2;color:#dc2626}#tg-entry{flex:1;min-width:110px;border:0 !important;outline:0 !important;box-shadow:none !important;padding:4px 2px !important;background:transparent;font-size:14px}
.pmx-h{cursor:grab;user-select:none}.pmx-h:active{cursor:grabbing}.meta-panel[data-lock] > .pmx-h,.meta-panel[data-lock] > .pmx-h:active{cursor:pointer}
.pmx-ctl{display:inline-flex;align-items:center;gap:2px;margin-left:auto;flex-shrink:0}
.pmx-b{width:24px;height:24px;padding:0;border:0;border-radius:5px;background:none;color:#9ca3af;cursor:pointer;display:grid;place-items:center;font-size:11px}
.pmx-b:hover,.pmx-b:focus-visible{background:#f1f5f9;color:#111827;outline:none}
.pmx-t i{font-size:15px;transition:transform .2s}
.pmx-closed > :not(.meta-panel-header){display:none !important}
.pmx-closed .pmx-t i{transform:rotate(-90deg)}
.pmx-closed .meta-panel-header{border-bottom:0 !important}
.pmx-drag{opacity:.55;outline:2px dashed var(--primary);outline-offset:2px}
</style>
<link rel="preload" href="<?= htmlspecialchars($tinymce_src) ?>" as="script" crossorigin="anonymous">
<script src="<?= htmlspecialchars($tinymce_src) ?>" referrerpolicy="origin" crossorigin="anonymous"></script>
<script src="/assets/js/gp.js"></script>
</head>
<body>
<?php
// ---- values for the form ----
$pm = $post_meta ?? [];
$v_title   = $is_edit ? $post_data['title'] : '';
$v_slug    = $is_edit ? $post_data['slug'] : '';
$v_status  = $is_edit ? $post_data['status'] : 'published';
$myAuthor = 0;
foreach ($authors as $a) if ((int)($a['user_id'] ?? 0) === (int)$me['id']) { $myAuthor = (int)$a['id']; break; }
$v_author  = $is_edit ? (int)$post_data['author_id'] : ($myAuthor ?: (int)($authors[0]['id'] ?? 0));
$v_last    = $is_edit && !empty($post_data['last_date']) ? $post_data['last_date'] : '';
$v_desc    = $is_edit ? ($post_data['description'] ?? '') : '';
$v_kps     = json_decode($pm['key_points'] ?? '[]', true) ?: [];
$v_tags    = [];
foreach ($all_tags as $t) if (in_array($t['id'], $selected_tag_ids)) $v_tags[] = $t['name'];
$status_labels = ['published' => 'Published', 'draft' => 'Draft', 'archived' => 'Archived'];
$author_name = '';
foreach ($authors as $a) if ((int)$a['id'] === $v_author) $author_name = $a['name'];
$feat = null;
if ($is_edit && !empty($post_data['featured_image_id'])) {
    $st = $pdo->prepare("SELECT id, file_path, alt_text FROM media WHERE id = ?");
    $st->execute([$post_data['featured_image_id']]);
    $feat = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
$host = preg_replace('#^https?://#', '', rtrim(SITE_URL, '/'));
$viewUrl = $is_edit ? postUrl($post_data['slug'], $post_id) : '';
admin_shell_open($is_edit ? 'Edit Post' : 'Add New Post', $is_edit ? 'Update your post' : 'Write and publish a new post',
    $is_edit ? '<a href="' . htmlspecialchars($viewUrl) . '" target="_blank" class="btn btn-secondary btn-sm"><i class="fas fa-external-link-alt"></i> View Post</a>' : '');
?>
<?php if (!empty($_GET['saved']) && $is_edit): $msg = ['published' => 'Post published.', 'updated' => 'Post updated.', 'draft' => 'Draft saved.'][$_GET['saved']] ?? 'Saved.'; ?>
<div class="alert alert-success" style="display:flex;align-items:center;gap:10px"><i class="fas fa-check-circle"></i> <span><?= $msg ?> <?php if ($post_data['status'] === 'published'): ?><a href="<?= htmlspecialchars($viewUrl) ?>" target="_blank" style="font-weight:700">View post</a><?php endif; ?></span><a href="/admin/post-manager.php" style="margin-left:auto;font-weight:600"><i class="fas fa-plus"></i> Add New Post</a></div>
<?php endif; ?>
<?php if (!empty($form_error)): ?>
<div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= $form_error ?></div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data" id="post-form" class="pe-form">
<?php if ($is_edit): ?><input type="hidden" name="post_id" value="<?= $post_id ?>"><?php endif; ?>
<input type="hidden" name="existing_featured_image_id" id="featured_image_id" value="<?= $feat ? (int)$feat['id'] : '' ?>">
<input type="hidden" name="meta_keywords" value="<?= htmlspecialchars($is_edit ? ($post_data['keywords'] ?? '') : '') ?>">
<input type="file" name="banner[]" id="banner" accept="image/*" multiple hidden>

<div class="editor-layout">
  <div class="editor-main">

    <div class="meta-panel">
      <div class="meta-panel-body" style="gap:.5rem">
        <input type="text" id="title" name="title" class="post-title-input" placeholder="Add title" autocomplete="off" maxlength="200" required value="<?= htmlspecialchars($v_title) ?>">
        <div class="permalink-row"><strong>Permalink:</strong><span class="permalink-base"><?= htmlspecialchars($host) ?>/</span><input type="text" id="slug" name="slug" class="permalink-input" placeholder="post-url-slug" maxlength="120" value="<?= htmlspecialchars($v_slug) ?>"><?php if ($is_edit && (int)$post_id < edu_slug_only_from()): ?><span class="permalink-id">-<?= $post_id ?></span><?php endif; ?></div>
      </div>
    </div>

    <div class="meta-panel" data-lock="1">
      <div class="meta-panel-header"><span><i class="fas fa-align-left"></i> Summary</span><span class="pe-count" id="summary-count">0/600</span></div>
      <div class="meta-panel-body">
        <textarea name="summary" id="summary" rows="3" maxlength="2000" placeholder="A short summary of the article, shown in a highlighted box at the top of the post (2–3 sentences)."><?= htmlspecialchars($pm['summary'] ?? '') ?></textarea>
        <span class="field-hint">Shown on the post below the featured image. Turn the box on/off in Customize → Post Template.</span>
      </div>
    </div>

    <div class="meta-panel">
      <div class="meta-panel-body" style="padding:0;gap:0">
        <div class="be-wrap pm2-wrap">
          <div class="be-tabs">
            <button type="button" class="pm2-addmedia" id="pm2-add-media"><i class="fas fa-photo-film"></i> Add Media</button>
            <div class="be-mode"><button type="button" class="is-active" data-mode="visual">Visual</button><button type="button" data-mode="html">Text</button></div>
          </div>
          <div id="pe-visual"><textarea id="editor" aria-label="Post content"><?= $is_edit ? htmlspecialchars((string)$post_data['content']) : '' ?></textarea></div>
          <textarea id="html-source" class="pe-html" spellcheck="false" hidden></textarea>
          <div class="be-status" id="be-status">0 words</div>
          <input type="hidden" name="content" id="content">
        </div>
      </div>
    </div>

    <div class="meta-panel" data-lock="1">
      <div class="meta-panel-header"><span><i class="fas fa-list-check"></i> Key Points</span><span class="pe-count" id="kp-count">0 added</span></div>
      <div class="meta-panel-body">
        <div class="kp-editor">
          <div id="kp-list"></div>
          <button type="button" class="btn btn-secondary kp-add" id="kp-add"><i class="fas fa-plus"></i> Add key point</button>
        </div>
        <span class="field-hint">Shown as a bulleted “Key Points” box at the top of the post. Press Enter to add the next point.</span>
      </div>
    </div>
  </div>

  <div class="editor-sidebar-panel">
    <div class="meta-panel" id="publish-box">
      <div class="meta-panel-header"><span>Publish</span></div>
      <div class="meta-panel-body">
        <div class="wp-pub-btn-row">
          <button type="button" class="wp-outline-btn" id="save-draft-btn">Save Draft</button>
          <button type="button" class="wp-outline-btn" id="preview-btn">Preview</button>
        </div>
        <div class="wp-pub-row"><i class="fas fa-toggle-on wp-pub-icon"></i><span>Status: <strong id="status-label"><?= $status_labels[$v_status] ?? 'Draft' ?></strong></span><a href="#" class="wp-pub-editlink" data-edit="status-box">Edit</a></div>
        <div class="wp-pub-editbox" id="status-box">
          <select name="status" id="status">
            <?php foreach ($status_labels as $k => $l): ?><option value="<?= $k ?>" <?= $v_status === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
          </select>
          <div class="wp-pub-editactions"><button type="button" class="wp-pub-ok" data-ok="status">OK</button></div>
        </div>
        <div class="wp-pub-row"><i class="fas fa-user wp-pub-icon"></i><span>Author: <strong id="author-label"><?= htmlspecialchars($author_name ?: '—') ?></strong></span><a href="#" class="wp-pub-editlink" data-edit="author-box">Edit</a></div>
        <div class="wp-pub-editbox" id="author-box">
          <select name="author_id" id="author_id" required>
            <?php foreach ($authors as $a): ?><option value="<?= $a['id'] ?>" <?= (int)$a['id'] === $v_author ? 'selected' : '' ?>><?= htmlspecialchars($a['name']) ?></option><?php endforeach; ?>
          </select>
          <div class="wp-pub-editactions"><button type="button" class="wp-pub-ok" data-ok="author">OK</button></div>
        </div>
        <div class="wp-pub-row"><i class="fas fa-calendar-days wp-pub-icon"></i><span>Last date: <strong id="last-label"><?= $v_last ? date('j M Y', strtotime($v_last)) : 'Not set' ?></strong></span><a href="#job-card" class="wp-pub-editlink" id="last-jump">Edit</a></div>
        <?php if ($is_edit): ?>
        <div class="wp-pub-row"><i class="fas fa-clock wp-pub-icon"></i><span>Updated: <strong><?= date('j M Y, g:i a', strtotime($post_data['updated_at'] ?? $post_data['date'])) ?></strong></span></div>
        <?php endif; ?>
        <div class="publish-actions">
          <button type="submit" class="btn btn-primary" id="publish-btn" style="width:100%;border-radius:4px"><i class="fas fa-upload"></i> <span><?= $is_edit ? 'Update Post' : 'Publish Post' ?></span></button>
        </div>
        <button type="button" id="restore_draft_btn" class="pe-restore" style="display:none">Restore unsaved draft</button>
      </div>
    </div>

    <div class="meta-panel">
      <div class="meta-panel-header"><span>Featured Image</span></div>
      <div class="meta-panel-body" id="feat-img-box">
        <div class="feat-img-visual" id="feat-visual">
          <?php if ($feat): ?>
            <img src="/<?= htmlspecialchars(ltrim($feat['file_path'], '/')) ?>" alt="<?= htmlspecialchars($feat['alt_text'] ?? '') ?>" id="feat-img">
          <?php else: ?>
            <div class="feat-img-placeholder"><i class="fas fa-image"></i><span>Set featured image</span></div>
          <?php endif; ?>
        </div>
        <div class="feat-img-actions">
          <button type="button" class="upload-label" id="feat-set"><?= $feat ? '↻ Replace Image' : '+ Set Featured Image' ?></button>
          <button type="button" class="upload-label" id="feat-remove" style="color:var(--danger,#dc2626);border-color:var(--danger,#dc2626)" <?= $feat ? '' : 'hidden' ?>>Remove</button>
        </div>
        <span class="field-hint" style="display:block;margin-top:.5rem">Recommended 1280×720px. Automatic WebP conversion to ~50KB.</span>
      </div>
    </div>

    <div class="meta-panel">
      <div class="meta-panel-header"><span>Category</span></div>
      <div class="meta-panel-body">
        <?php $defCat = edu_default_category($pdo); ?>
        <select name="category_id" id="main_category_id">
          <option value="">— Default category —</option>
          <?php foreach ($categories as $c): ?><option value="<?= $c['id'] ?>" <?= ($is_edit && $post_data['category_id'] == $c['id']) ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?><?= (int)$c['id'] === $defCat ? ' (default)' : '' ?></option><?php endforeach; ?>
        </select>
        <span class="field-hint">No category chosen → the post goes to the default category.</span>
        <details class="pe-more-cats" <?= $additional_category_ids && count($additional_category_ids) > 1 ? 'open' : '' ?>>
          <summary>Also show in other categories</summary>
          <div class="pe-checklist">
            <?php foreach ($categories as $c): ?>
              <label><input type="checkbox" name="additional_category_ids[]" value="<?= $c['id'] ?>" <?= ($is_edit && in_array($c['id'], $additional_category_ids) && $post_data['category_id'] != $c['id']) ? 'checked' : '' ?>> <?= htmlspecialchars($c['name']) ?></label>
            <?php endforeach; ?>
          </div>
        </details>
      </div>
    </div>

    <div class="meta-panel">
      <div class="meta-panel-header"><span>Job State</span></div>
      <div class="meta-panel-body">
        <select name="state_id" id="state_id">
          <option value="">— All India / none —</option>
          <?php foreach ($states as $s): ?><option value="<?= $s['id'] ?>" <?= ($is_edit && $post_data['state_id'] == $s['id']) ? 'selected' : '' ?>><?= htmlspecialchars($s['state_name']) ?></option><?php endforeach; ?>
        </select>
        <span class="field-hint">The post is listed under this state on State Wise Jobs.</span>
      </div>
    </div>

    <div class="meta-panel">
      <div class="meta-panel-header"><span>Tags</span></div>
      <div class="meta-panel-body">
        <div class="tg-box" id="tg-box"><span id="tg-chips"></span><input id="tg-entry" type="text" placeholder="Add a tag" list="tag-suggest" autocomplete="off" aria-label="Add a tag"></div>
        <input type="hidden" id="tags" name="tags_csv" value="<?= htmlspecialchars(implode(', ', $v_tags)) ?>">
        <datalist id="tag-suggest"><?php foreach ($all_tags as $t): ?><option value="<?= htmlspecialchars($t['name']) ?>"><?php endforeach; ?></datalist>
        <span class="field-hint">Type a tag and press Enter or comma. Click × to remove.</span>
      </div>
    </div>

    <div class="meta-panel">
      <div class="meta-panel-header"><span>Post FAQs</span></div>
      <div class="meta-panel-body">
        <p style="font-size:.75rem;color:var(--gray-500);margin:0 0 .5rem">Shown at the end of the post with FAQ schema for Google.</p>
        <button type="button" class="btn btn-secondary" id="open-faq-modal" style="width:100%"><i class="fas fa-circle-question"></i> Manage FAQs</button>
        <p id="faq-count" style="font-size:11px;color:var(--gray-400);font-style:italic;margin:6px 0 0">No FAQs added.</p>
        <input type="hidden" name="faq_json" id="faq_json_input" value="<?= ($is_edit && !empty($post_data['faq_json'])) ? htmlspecialchars($post_data['faq_json']) : '' ?>">
      </div>
    </div>

    <?php if ($canSeo): $kwList = array_values(array_filter(array_map('trim', explode(',', (string)($pm['focus_keyword'] ?? ''))))); ?>
    <div class="meta-panel seo-box rmx">
      <div class="meta-panel-header"><span><i class="fas fa-chart-simple"></i> SEO</span><span class="rmx-score bad" id="seo-score" title="SEO score">0 / 100</span></div>
      <div class="meta-panel-body">
        <div class="rmx-tabs" role="tablist">
          <button type="button" class="active" data-tab="general" title="General"><i class="fas fa-gear"></i><span>General</span></button>
          <button type="button" data-tab="advanced" title="Advanced"><i class="fas fa-toolbox"></i><span>Advanced</span></button>
          <button type="button" data-tab="schema" title="Schema"><i class="fas fa-diagram-project"></i><span>Schema</span></button>
          <button type="button" data-tab="social" title="Social"><i class="fas fa-share-nodes"></i><span>Social</span></button>
        </div>

        <div data-pane="general">
          <div class="rmx-h">Preview <span class="rmx-dev"><button type="button" class="on" data-dev="desktop" title="Desktop preview"><i class="fas fa-desktop"></i></button><button type="button" data-dev="mobile" title="Mobile preview"><i class="fas fa-mobile-screen"></i></button></span></div>
          <div class="rmx-serp" id="rmx-serp">
            <div class="rmx-serp-site"><span class="rmx-fav"><?= htmlspecialchars(mb_strtoupper(mb_substr(edu_site_name(), 0, 1))) ?></span><div><div class="rmx-serp-name"><?= htmlspecialchars(edu_site_name()) ?></div><div class="rmx-serp-url"><?= htmlspecialchars($host) ?> › <span id="sp-url">post-url</span></div></div></div>
            <div class="rmx-serp-title" id="sp-title">Post title</div>
            <div class="rmx-serp-desc" id="sp-desc"></div>
          </div>
          <button type="button" class="rmx-edit" id="rmx-edit-snippet"><i class="fas fa-pen"></i> Edit Snippet</button>

          <div class="rmx-h" style="margin-top:14px">Focus Keyword <i class="fas fa-circle-question" title="The main search phrase you want this post to rank for. Add more after it — the first one is checked."></i></div>
          <div class="rmx-kw" id="rmx-kw"><input type="text" id="rmx-kw-in" placeholder="Example: SSC CGL notification 2026" autocomplete="off"></div>
          <input type="hidden" name="focus_keyword" id="focus_keyword" value="<?= htmlspecialchars(implode(', ', $kwList)) ?>">
          <label class="rmx-check"><input type="checkbox" name="pillar" value="1" <?= !empty($pm['pillar']) ? 'checked' : '' ?>> This post is Pillar Content <i class="fas fa-circle-question" title="Your most important, complete guides on a topic."></i></label>

          <div id="rmx-groups"></div>
        </div>

        <div data-pane="advanced" hidden>
          <div class="rmx-h">Robots Meta</div>
          <div class="rmx-grid2">
            <label class="rmx-check"><input type="radio" name="robots_mode" value="index" <?= empty($pm['noindex']) ? 'checked' : '' ?>> Index</label>
            <label class="rmx-check"><input type="radio" name="robots_mode" value="noindex" <?= !empty($pm['noindex']) ? 'checked' : '' ?>> No Index</label>
            <label class="rmx-check"><input type="checkbox" name="nofollow" value="1" <?= !empty($pm['nofollow']) ? 'checked' : '' ?>> Nofollow</label>
            <label class="rmx-check"><input type="checkbox" name="noarchive" value="1" <?= !empty($pm['noarchive']) ? 'checked' : '' ?>> No Archive</label>
            <label class="rmx-check"><input type="checkbox" name="noimageindex" value="1" <?= !empty($pm['noimageindex']) ? 'checked' : '' ?>> No Image Index</label>
            <label class="rmx-check"><input type="checkbox" name="nosnippet" value="1" <?= !empty($pm['nosnippet']) ? 'checked' : '' ?>> No Snippet</label>
          </div>
          <input type="hidden" name="noindex" id="noindex" value="<?= !empty($pm['noindex']) ? '1' : '' ?>">
          <p class="rmx-hint">“No Index” keeps this post out of Google and the sitemap.</p>
          <label class="rmx-label">Canonical URL<input type="url" name="canonical" placeholder="<?= htmlspecialchars(rtrim(SITE_URL, '/')) ?>/post-url" value="<?= htmlspecialchars($pm['canonical'] ?? '') ?>"></label>
          <p class="rmx-hint">The canonical URL that this page should point to. Leave empty to default to the permalink.</p>
        </div>

        <div data-pane="schema" hidden>
          <div class="rmx-h">Schema in Use</div>
          <div class="rmx-schema"><i class="fas fa-newspaper"></i><div><b id="rmx-schema-name">Article</b><small>Shows as rich result data for Google</small></div></div>
          <div class="rmx-h" style="margin-top:12px">Article Type</div>
          <?php $stype = $pm['schema_type'] ?? ''; foreach (['' => 'Default (from Titles & Meta → Posts: ' . (string)seo_opt('schema_default') . ')', 'Article' => 'Article', 'BlogPosting' => 'Blog Post', 'NewsArticle' => 'News Article'] as $k => $l): ?>
          <label class="rmx-check"><input type="radio" name="schema_type" value="<?= $k ?>" <?= $stype === $k ? 'checked' : '' ?>> <?= htmlspecialchars($l) ?></label>
          <?php endforeach; ?>
          <p class="rmx-hint">Job &amp; result updates usually use <b>News Article</b>; guides use <b>Blog Post</b>. FAQs added below the post become FAQ schema, the author becomes Person schema, and the featured image an ImageObject — automatically.</p>
        </div>

        <div data-pane="social" hidden>
          <div class="rmx-subtabs"><button type="button" class="on" data-soc="fb"><i class="fab fa-facebook"></i> Facebook</button><button type="button" data-soc="tw"><i class="fab fa-x-twitter"></i> Twitter</button></div>
          <div class="rmx-card" id="rmx-social-card"><div class="rmx-card-img" id="rmx-soc-img"></div><div class="rmx-card-body"><small><?= htmlspecialchars(strtoupper($host)) ?></small><b id="rmx-soc-title"></b><span id="rmx-soc-desc"></span></div></div>
          <label class="rmx-label">Image<span class="rmx-imgrow"><button type="button" class="btn btn-secondary btn-sm" id="rmx-og-pick">Add or Upload File</button><button type="button" class="btn btn-secondary btn-sm" id="rmx-og-clear" <?= empty($pm['og_image']) ? 'hidden' : '' ?>>Remove</button></span></label>
          <input type="hidden" name="og_image" id="og_image" value="<?= htmlspecialchars($pm['og_image'] ?? '') ?>">
          <p class="rmx-hint">Empty = the featured image. Use 1200 × 630 px.</p>
          <label class="rmx-label">Title<input type="text" name="og_title" id="og_title" maxlength="200" placeholder="Same as the SEO title" value="<?= htmlspecialchars($pm['og_title'] ?? '') ?>"></label>
          <label class="rmx-label">Description<textarea name="og_description" id="og_description" rows="3" maxlength="300" placeholder="Same as the meta description"><?= htmlspecialchars($pm['og_description'] ?? '') ?></textarea></label>
          <p class="rmx-hint">Used by Facebook, WhatsApp, X (Twitter), LinkedIn and Telegram link previews.</p>
        </div>
        <input type="hidden" name="seo_title" id="seo_title" value="<?= htmlspecialchars($pm['seo_title'] ?? '') ?>">
        <input type="hidden" name="meta_description" id="meta_description" value="<?= htmlspecialchars($v_desc) ?>">
      </div>
    </div>
    <?php else: ?>
    <input type="hidden" name="meta_description" id="meta_description" value="<?= htmlspecialchars($v_desc) ?>">
    <?php endif; ?>

    <?php $jv = fn($k) => htmlspecialchars((string)($pm[$k] ?? ''), ENT_QUOTES); $jOn = ($pm['job_card'] ?? '') === '1'; $jQual = (string)($pm['job_qualification'] ?? ''); $jKnown = in_array($jQual, ['5th Pass','8th Pass','9th Pass','10th Pass','12th Pass','ITI','Diploma','Polytechnic','Graduation (Any)','B.A','B.Sc','B.Com','B.Tech / B.E','BCA','BBA','B.Ed','LLB','B.Pharma','MBBS','B.Sc Nursing','GNM / ANM','Post Graduation (Any)','M.A','M.Sc','M.Com','M.Tech / M.E','MCA','MBA','M.Ed','LLM','Ph.D'], true); ?>
    <div class="meta-panel jcard-box" id="job-card">
      <div class="meta-panel-header"><span><i class="fas fa-id-card"></i> Job card</span></div>
      <div class="meta-panel-body">
        <label class="jc-switch"><input type="checkbox" name="job_card" value="1" id="job_card" <?= $jOn ? 'checked' : '' ?>><span><b>Create job card for this post</b><small>Shown as a job card on Latest Updates, category and state pages.</small></span></label>
        <div class="jc-fields" id="jc-fields" <?= $jOn ? '' : 'hidden' ?>>
          <div class="jc-f">Logo
            <div class="jc-logo-row"><img id="job-logo-prev" alt="" <?= !empty($pm['job_logo']) ? 'src="/' . htmlspecialchars(ltrim((string)$pm['job_logo'], '/')) . '"' : 'hidden' ?>><button type="button" class="upload-label" id="job-logo-pick"><?= !empty($pm['job_logo']) ? 'Change' : '+ Choose logo' ?></button><button type="button" class="upload-label jc-del" id="job-logo-clear" <?= !empty($pm['job_logo']) ? '' : 'hidden' ?>>Remove</button></div>
            <input type="hidden" name="job_logo" id="job_logo" value="<?= $jv('job_logo') ?>">
            <span class="field-hint">Empty = the featured image.</span>
          </div>
          <label class="jc-f">Post name <small>(shown on the card — the title by default, edit if you like)</small><input type="text" name="job_post_name" id="job_post_name" maxlength="150" value="<?= $jv('job_post_name') ?>" data-auto="<?= ($pm['job_post_name'] ?? '') === '' ? '1' : '0' ?>" placeholder="e.g. BPM, ABPM, Dak Sevak"></label>
          <label class="jc-f">Department <small>(optional)</small><input type="text" name="job_org" maxlength="150" value="<?= $jv('job_org') ?>" placeholder="e.g. Indian Post"></label>
          <label class="jc-f">State / Location<input type="text" name="job_location" id="job_location" maxlength="100" value="<?= $jv('job_location') ?>" data-auto="<?= ($pm['job_location'] ?? '') === '' ? '1' : '0' ?>" placeholder="All India"></label>
          <label class="jc-f">Salary<span class="jc-rupee"><span aria-hidden="true">₹</span><input type="text" name="job_salary" maxlength="100" value="<?= htmlspecialchars(preg_replace('/^\s*₹\s*/u', '', (string)($pm['job_salary'] ?? '')), ENT_QUOTES) ?>" placeholder="29,380 per month — or text, e.g. As per rules"></span></label>
          <label class="jc-f">Vacancies (seats)<input type="text" name="job_vacancies" id="job_vacancies" inputmode="numeric" pattern="[0-9]*" maxlength="9" value="<?= $jv('job_vacancies') ?>" placeholder="e.g. 23757"></label>
          <label class="jc-f">Qualification
            <select name="job_qualification_sel" id="job_qual_sel"><option value="">— Choose —</option><optgroup label="School"><option>5th Pass</option><option>8th Pass</option><option>9th Pass</option><option>10th Pass</option><option>12th Pass</option></optgroup><optgroup label="Technical"><option>ITI</option><option>Diploma</option><option>Polytechnic</option></optgroup><optgroup label="Graduation"><option>Graduation (Any)</option><option>B.A</option><option>B.Sc</option><option>B.Com</option><option>B.Tech / B.E</option><option>BCA</option><option>BBA</option><option>B.Ed</option><option>LLB</option><option>B.Pharma</option><option>MBBS</option><option>B.Sc Nursing</option><option>GNM / ANM</option></optgroup><optgroup label="Post Graduation"><option>Post Graduation (Any)</option><option>M.A</option><option>M.Sc</option><option>M.Com</option><option>M.Tech / M.E</option><option>MCA</option><option>MBA</option><option>M.Ed</option><option>LLM</option><option>Ph.D</option></optgroup><option value="__other" <?= $jQual !== '' && !$jKnown ? 'selected' : '' ?>>Other (type)</option></select>
            <input type="text" name="job_qualification_other" id="job_qual_other" maxlength="100" placeholder="Type the qualification" value="<?= $jKnown ? '' : $jv('job_qualification') ?>" <?= $jQual !== '' && !$jKnown ? '' : 'hidden' ?>>
          </label>
          <label class="jc-f">Last date<input type="date" name="last_date" id="last_date" value="<?= htmlspecialchars($v_last) ?>"><span class="field-hint">Last date to apply for this vacancy.</span></label>
        </div>
      </div>
    </div>
  </div>
</div>
</form>

<!-- Logo library (job card logos only) -->
<div class="llb" id="llb" hidden role="dialog" aria-modal="true" aria-labelledby="llb-h">
  <div class="llb-box">
    <div class="llb-head"><b id="llb-h">Logo library</b><button type="button" class="llb-x" id="llb-close" aria-label="Close">&times;</button></div>
    <div class="llb-tools">
      <label class="llb-search"><i class="fas fa-magnifying-glass" aria-hidden="true"></i><input type="search" id="llb-q" placeholder="Search logos by title — e.g. Indian Post" autocomplete="off"></label>
      <button type="button" class="btn btn-primary btn-sm" id="llb-up-btn"><i class="fas fa-upload"></i> Upload new logo</button>
    </div>
    <div class="llb-up" id="llb-up" hidden>
      <input type="text" id="llb-title" maxlength="200" placeholder="Logo title (e.g. Indian Post) — used for search">
      <label class="upload-label llb-file">Choose image<input type="file" id="llb-file" accept="image/png,image/jpeg,image/webp,image/svg+xml,image/gif"></label>
      <span class="llb-msg" id="llb-msg"></span>
    </div>
    <div class="llb-grid" id="llb-grid"></div>
    <div class="llb-foot"><button type="button" class="upload-label" id="llb-more" hidden>Load more</button></div>
  </div>
</div>

<!-- Media Library -->
<div class="mlb-overlay" id="mlb" hidden>
  <div class="mlb-box">
    <div class="mlb-head"><h3><i class="fas fa-images"></i> Media Library</h3><button type="button" class="mlb-x" data-mlb-close>✕</button></div>
    <div class="mlb-main">
      <div class="mlb-left">
        <div class="mlb-tools"><input type="text" class="form-control" id="mlb-search" placeholder="Search images…"><label class="btn btn-secondary" style="margin:0;cursor:pointer"><i class="fas fa-upload"></i> Upload<input type="file" accept="image/*" id="mlb-upload" multiple hidden></label></div>
        <div class="mlb-grid" id="mlb-grid"></div>
        <div class="mlb-pager" id="mlb-pager"></div>
      </div>
      <div class="mlb-right" id="mlb-detail"><div class="mlb-empty"><i class="fas fa-hand-pointer"></i>Select an image to view &amp; edit details</div></div>
    </div>
    <div class="mlb-foot"><span id="mlb-selname">No image selected</span><div><button type="button" class="btn btn-secondary" data-mlb-close>Cancel</button> <button type="button" class="btn btn-primary" id="mlb-use" disabled><i class="fas fa-check"></i> Use This Image</button></div></div>
  </div>
</div>

<!-- FAQ modal -->
<div class="wp-modal-overlay" id="faqModal" hidden>
  <div class="wp-modal" style="max-width:580px">
    <div class="wp-modal-head"><h2>Manage FAQs</h2><button type="button" class="wp-modal-close" id="closeFaqModal">✕</button></div>
    <div class="wp-modal-body"><div id="faq-rows"></div><button type="button" id="add-faq-btn">+ Add Another Question</button></div>
    <div class="pe-modal-foot"><button type="button" class="btn btn-primary" id="insert-faq-btn">Save FAQs</button></div>
  </div>
</div>

<!-- Edit Snippet (SEO) -->
<?php if ($canSeo): ?>
<div class="wp-modal-overlay" id="snippetModal" hidden>
  <div class="wp-modal" style="max-width:720px">
    <div class="wp-modal-head"><h2>Preview Snippet Editor</h2><button type="button" class="wp-modal-close" data-snip-close>✕</button></div>
    <div class="wp-modal-body rmx-modal">
      <div class="rmx-serp" style="margin-bottom:16px"><div class="rmx-serp-site"><span class="rmx-fav"><?= htmlspecialchars(mb_strtoupper(mb_substr(edu_site_name(), 0, 1))) ?></span><div><div class="rmx-serp-name"><?= htmlspecialchars(edu_site_name()) ?></div><div class="rmx-serp-url"><?= htmlspecialchars($host) ?> › <span id="snp-url"></span></div></div></div><div class="rmx-serp-title" id="snp-title"></div><div class="rmx-serp-desc" id="snp-desc"></div></div>
      <label class="rmx-label">Title <span class="rmx-cnt" id="snp-tcount">0 / 60</span><input type="text" id="snp-title-in" maxlength="200" placeholder="<?= htmlspecialchars((string)seo_opt('post_format')) ?>"></label>
      <div class="rmx-meter" id="snp-tmeter"><span></span></div>
      <p class="rmx-hint">This is what will appear in the first line when this post shows up in the search results. Empty = <code><?= htmlspecialchars((string)seo_opt('post_format')) ?></code>. Variables: <code>%title%</code> <code>%sep%</code> <code>%sitename%</code> <code>%category%</code> <code>%currentyear%</code></p>
      <label class="rmx-label">Permalink<input type="text" id="snp-slug" maxlength="120"></label>
      <p class="rmx-hint">This is the unique URL of this post, displayed below the post title in the search results.</p>
      <label class="rmx-label">Description <span class="rmx-cnt" id="snp-dcount">0 / 160</span><textarea id="snp-desc-in" rows="3" maxlength="300"></textarea></label>
      <div class="rmx-meter" id="snp-dmeter"><span></span></div>
      <p class="rmx-hint">This is what will appear as the description when this post shows up in the search results. It is filled from the summary / first lines automatically — edit it to make it better.</p>
    </div>
    <div class="pe-modal-foot"><button type="button" class="btn btn-secondary" data-snip-close>Close</button> <button type="button" class="btn btn-primary" id="snp-apply">Done</button></div>
  </div>
</div>
<?php endif; ?>

<!-- Table templates -->
<div id="tableTemplateModal" class="table-modal">
  <div class="table-modal-content">
    <div class="table-modal-header"><h2>Table Templates</h2><div style="display:flex;gap:8px;align-items:center"><button type="button" class="btn btn-secondary btn-sm" id="saveTplBtn" title="Click inside a table in the post first"><i class="fas fa-floppy-disk"></i> Save selected table as template</button><button type="button" class="table-modal-close" id="closeTableModal">&#10005;</button></div></div>
    <div class="table-modal-body">
      <div class="tpl-target" id="tplTargetBar" hidden>
        <label><input type="radio" name="tplMode" value="design" checked> <b>Change only the design</b> — this table keeps its text, rows and columns, and gets the colours and style of the template you pick</label>
        <label><input type="radio" name="tplMode" value="replace"> <b>Replace this table</b> with the template (its text is replaced too)</label>
      </div>
      <div class="table-templates-grid">
        <?php foreach ($templates as $t): ?>
        <div class="table-template-card" data-html="<?= htmlspecialchars($t['html']) ?>">
          <div class="table-template-preview"><?= $t['html'] ?></div>
          <div class="table-template-info"><h4><?= htmlspecialchars($t['name']) ?><?= !empty($t['custom']) ? ' <small style="color:#7c3aed">(yours)</small>' : '' ?></h4><div style="display:flex;gap:6px"><button type="button" class="table-insert-btn"><i class="fas fa-plus"></i> Insert</button><?php if (!empty($t['custom'])): ?><button type="button" class="btn-action btn-delete" data-del-tpl="<?= (int)$t['id'] ?>" title="Delete this template"><i class="fas fa-trash"></i></button><?php endif; ?></div></div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php if (empty($templates)): ?><div class="no-templates"><p>No table templates available yet.</p></div><?php endif; ?>
    </div>
  </div>
</div>

<?php admin_shell_close(); ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
const IS_EDIT = <?= $is_edit ? 'true' : 'false' ?>;
const SITE_NAME = <?= json_encode(edu_site_name(), JSON_HEX_TAG) ?>;
const HOST = <?= json_encode($host, JSON_HEX_TAG) ?>;
const existingContent = <?= $is_edit ? json_encode($post_data['content']) : 'null' ?>;
const form = document.getElementById('post-form');
const $ = id => document.getElementById(id);
// Popups live at page level so the admin layout can't clip or hide them (same as the CMS's portals).
['mlb', 'faqModal', 'tableTemplateModal'].forEach(id => { const el = $(id); if (el) document.body.appendChild(el); });

/* ---------- TinyMCE editor ---------- */
let editor = null, mode = 'visual';
const contentHtml = () => mode === 'html' ? $('html-source').value : (editor ? editor.getContent() : (existingContent || ''));
const escAttr = s => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

// Posts saved by the old (Quill) editor: turn its bullet lists (<ol data-list="bullet">) into real <ul> and drop its helper markup.
function cleanLegacy(html) {
  if (!/ql-|data-list/.test(html)) return html;
  const doc = new DOMParser().parseFromString('<body>' + html + '</body>', 'text/html');
  doc.querySelectorAll('.ql-ui').forEach(n => n.remove());
  doc.querySelectorAll('ol').forEach(ol => {
    const first = ol.querySelector(':scope > li');
    if (first && first.getAttribute('data-list') === 'bullet') {
      const ul = doc.createElement('ul');
      while (ol.firstChild) ul.appendChild(ol.firstChild);
      ol.replaceWith(ul);
    }
  });
  doc.querySelectorAll('li[data-list]').forEach(li => li.removeAttribute('data-list'));
  doc.querySelectorAll('.ql-table-better').forEach(w => w.replaceWith(...Array.from(w.childNodes)));
  return doc.body.innerHTML;
}

// Upload pasted / dropped / chosen images to the Media Library (WebP + responsive sizes) and return their URL.
function uploadImage(blobInfo) {
  return new Promise((resolve, reject) => {
    const fd = new FormData();
    fd.append('file', blobInfo.blob(), blobInfo.filename());
    fd.append('alt', $('title').value.trim());
    fetch('/admin/api/media-upload.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(r => r.json())
      .then(d => d.path ? resolve(d.path) : reject({ message: d.error || 'Upload failed', remove: true }))
      .catch(() => reject({ message: 'Upload failed — please try again.', remove: true }));
  });
}

// "Link Button" (a styled call-to-action button). Same markup as the old editor, so old posts stay editable.
function openLinkButtonDialog(ed) {
  const found = ed.selection.getNode().closest ? ed.selection.getNode().closest('div[data-text][data-url]') : null;
  const d = found ? found.dataset : {};
  ed.windowManager.open({
    title: 'Link Button',
    size: 'normal',
    initialData: {
      text: d.text || 'Button Text', url: d.url || 'https://', bg: d.bg || '#0c6878', color: d.color || '#ffffff',
      radius: d.radius || '8px', padding: d.padding || '12px 28px', align: d.align || 'center', nofollow: d.rel === 'nofollow'
    },
    body: {
      type: 'panel',
      items: [
        { type: 'input', name: 'text', label: 'Button text' },
        { type: 'input', name: 'url', label: 'URL' },
        { type: 'colorinput', name: 'bg', label: 'Background' },
        { type: 'colorinput', name: 'color', label: 'Text colour' },
        { type: 'input', name: 'radius', label: 'Border radius' },
        { type: 'input', name: 'padding', label: 'Padding' },
        { type: 'selectbox', name: 'align', label: 'Alignment', items: [{ value: 'left', text: 'Left' }, { value: 'center', text: 'Center' }, { value: 'right', text: 'Right' }] },
        { type: 'checkbox', name: 'nofollow', label: 'Add rel="nofollow"' }
      ]
    },
    buttons: [{ type: 'cancel', text: 'Cancel' }, { type: 'submit', text: found ? 'Update button' : 'Insert button', buttonType: 'primary' }],
    onSubmit: api => {
      const v = api.getData();
      const t = (v.text || '').trim() || 'Button Text', u = (v.url || '').trim() || '#';
      const bg = v.bg || '#0c6878', col = v.color || '#ffffff', rad = (v.radius || '').trim() || '8px', pad = (v.padding || '').trim() || '12px 28px';
      const al = v.align || 'center', rel = v.nofollow ? 'nofollow' : '';
      const jc = al === 'center' ? 'center' : al === 'right' ? 'flex-end' : 'flex-start';
      const html = '<div style="display:flex;margin:10px 0;width:100%;justify-content:' + jc + '" data-text="' + escAttr(t) + '" data-url="' + escAttr(u) + '" data-bg="' + escAttr(bg) + '" data-color="' + escAttr(col) + '" data-radius="' + escAttr(rad) + '" data-padding="' + escAttr(pad) + '" data-align="' + al + '"' + (rel ? ' data-rel="nofollow"' : '') + '>' +
        '<a href="' + escAttr(u) + '" target="_blank" rel="noopener' + (rel ? ' nofollow' : '') + '" style="background-color:' + escAttr(bg) + ';color:' + escAttr(col) + ';border-radius:' + escAttr(rad) + ';padding:' + escAttr(pad) + ';text-decoration:none;display:inline-block;cursor:pointer;border:none">' + escAttr(t) + '</a></div>';
      if (found) ed.selection.select(found);
      ed.insertContent(html);
      api.close();
    }
  });
}

/* Table templates (from the Table Templates list). Opened from the main toolbar → inserts a new table; opened from a
   table's own toolbar → that table takes the template's design (its text stays) or is replaced by the template. */
const tableModal = $('tableTemplateModal');
let tplTarget = null;
// ── GK / MCQ quiz: questions typed or pasted as text become quiz cards (shown on the post with right ✔ / wrong ✘ and a score) ──
const EQ_HI = { 'क': 'A', 'ख': 'B', 'ग': 'C', 'घ': 'D', 'ङ': 'E', 'च': 'F' };
const eqEsc = s => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
function eqParse(text) {
  const qs = [], errs = [];
  let cur = null, mode = '';
  const letter = x => EQ_HI[x] || String(x).toUpperCase();
  const reA = /^\s*(?:ans(?:wer)?|correct(?:\s*answer)?|सही\s*उत्तर|उत्तर)\s*[:\-–=.]?\s*(?:[(\[]?\s*([A-Fa-fकखगघङच])\s*[)\]]?(?=$|[\s.)\-:।])\s*[.)\-:।]?\s*)?(.*)$/i;
  const reE = /^\s*(?:explanation|exp|solution|व्याख्या|हल|स्पष्टीकरण)\s*[:\-–.]\s*(.*)$/i;
  const reQ = /^\s*(?:Q(?:ues(?:tion)?)?\s*\.?\s*|प्रश्न\s*|प्र\.\s*)?(\d{1,4})\s*[.)\-:।]\s*(.+)$/i;
  const reO = /^\s*(?:\(\s*([A-Fa-fकखगघङच])\s*\)|([A-Fa-fकखगघङच])\s*[.)\-:।])\s*(.+)$/;
  text.replace(/\r/g, '').split('\n').forEach((raw, i) => {
    const line = raw.trim();
    if (!line) { if (mode === 'exp') mode = 'exp-gap'; return; }
    let m;
    if (cur && (m = line.match(reA)) && /^(ans|correct|सही|उत्तर)/i.test(line)) {
      let l = m[1] ? letter(m[1]) : '';
      if (!l && m[2]) { const t = m[2].trim().toLowerCase(); const hit = cur.opts.find(o => o.t.toLowerCase() === t); if (hit) l = hit.l; }
      cur.ans = l; mode = 'ans'; return;
    }
    if (cur && (m = line.match(reE))) { cur.exp = m[1].trim(); mode = 'exp'; return; }
    if ((m = line.match(reQ)) && !(cur && cur.opts.length && cur.opts.length < 2)) { cur = { q: m[2].trim(), opts: [], ans: '', exp: '', line: i + 1 }; qs.push(cur); mode = 'q'; return; }
    if (cur && (m = line.match(reO))) {
      let t = m[3].trim(), l = letter(m[1] || m[2]);
      if (/\s*[*✔✓]\s*$/.test(t) || /^[*✔✓]\s*/.test(t)) { t = t.replace(/^[*✔✓]\s*|\s*[*✔✓]\s*$/g, ''); cur.ans = l; }
      cur.opts.push({ l, t }); mode = 'opt'; return;
    }
    if (cur && (mode === 'exp' || mode === 'exp-gap')) { cur.exp += (cur.exp ? ' ' : '') + line; mode = 'exp'; return; }
    if (cur && mode === 'q') { cur.q += ' ' + line; return; }
    if (!cur) { errs.push('Line ' + (i + 1) + ': write a question number first, e.g. "1. Question"'); return; }
    errs.push('Line ' + (i + 1) + ' not understood: ' + line.slice(0, 60));
  });
  qs.forEach((q, n) => {
    if (q.opts.length < 2) errs.push('Question ' + (n + 1) + ' (line ' + q.line + '): needs at least 2 options (A. … B. …)');
    else if (!q.ans) errs.push('Question ' + (n + 1) + ' (line ' + q.line + '): add the right answer, e.g. "Answer: B" or a * after the right option');
    else if (!q.opts.some(o => o.l === q.ans)) errs.push('Question ' + (n + 1) + ': answer ' + q.ans + ' is not one of its options');
  });
  if (!qs.length && !errs.length) errs.push('No questions found.');
  return { qs, errs };
}
function eqHtml(title, qs) {
  return '<div class="edu-quiz">' + (title ? '<div class="eq-title">' + eqEsc(title) + '</div>' : '') + qs.map((q, i) =>
    '<div class="eq-card" data-ans="' + q.ans + '"><div class="eq-q"><span class="eq-n">' + (i + 1) + '.</span> ' + eqEsc(q.q) + '</div><ol class="eq-opts">' +
    q.opts.map(o => '<li data-opt="' + o.l + '">' + eqEsc(o.t) + '</li>').join('') + '</ol>' + (q.exp ? '<div class="eq-exp">' + eqEsc(q.exp) + '</div>' : '') + '</div>').join('') + '</div><p>&nbsp;</p>';
}
function eqToText(box) {
  return [...box.querySelectorAll('.eq-card')].map((c, i) => {
    const q = (c.querySelector('.eq-q')?.textContent || '').replace(/^\s*\d+\s*[.)]\s*/, '').trim();
    const opts = [...c.querySelectorAll('li[data-opt]')].map(li => li.getAttribute('data-opt') + '. ' + li.textContent.trim()).join('\n');
    const exp = (c.querySelector('.eq-exp')?.textContent || '').trim();
    return (i + 1) + '. ' + q + '\n' + opts + '\nAnswer: ' + (c.getAttribute('data-ans') || '') + (exp ? '\nExplanation: ' + exp : '');
  }).join('\n\n');
}
function openQuizDialog(ed) {
  const box = ed.dom.getParent(ed.selection.getNode(), 'div.edu-quiz');
  const help = '<div class="eq-help" style="font-size:13px;line-height:1.6;color:#475569"><b>Format</b> — one question after another (paste 100 at once):<pre style="background:#f1f5f9;padding:10px 12px;border-radius:8px;margin:8px 0 10px;white-space:pre-wrap;line-height:1.55">1. भारत की राजधानी क्या है?\nA. मुंबई\nB. नई दिल्ली\nC. कोलकाता\nD. चेन्नई\nAnswer: B\nExplanation: नई दिल्ली 1931 से राजधानी है।</pre>Also works: <code>Q1.</code> / <code>प्रश्न 1.</code>, options <code>(A)</code> or <code>क.</code> <code>ख.</code>, answer <code>उत्तर: ख</code> / <code>Ans: B</code>, or a <code>*</code> after the right option. Explanation is optional.</div>';
  ed.windowManager.open({
    title: box ? 'Edit GK Quiz' : 'GK Quiz (MCQ)', size: 'large',
    body: { type: 'panel', items: [
      { type: 'textarea', name: 'txt', label: 'Questions', maximized: true },
      { type: 'htmlpanel', html: help }
    ] },
    initialData: { txt: box ? eqToText(box) : '' },
    buttons: [{ type: 'cancel', text: 'Cancel' }, { type: 'submit', text: box ? 'Update quiz' : 'Insert quiz', primary: true }],
    onSubmit: api => {
      const d = api.getData(), r = eqParse(d.txt || '');
      if (r.errs.length) { ed.windowManager.alert(r.errs.slice(0, 8).join('\n') + (r.errs.length > 8 ? '\n… and ' + (r.errs.length - 8) + ' more' : '')); return; }
      const html = eqHtml('', r.qs);
      ed.undoManager.transact(() => { if (box) { ed.dom.setOuterHTML(box, html.replace(/<p>&nbsp;<\/p>$/, '')); } else ed.insertContent(html); });
      ed.nodeChanged(); api.close();
      if (typeof window.showToast === 'function') window.showToast(r.qs.length + ' questions ' + (box ? 'updated' : 'added'));
    }
  });
}

function openTemplates(target) {
  tplTarget = target && target.nodeName === 'TABLE' ? target : null;
  $('tplTargetBar').hidden = !tplTarget;
  tableModal.querySelectorAll('.table-insert-btn').forEach(b => { b.innerHTML = tplTarget ? '<i class="fas fa-check"></i> Apply' : '<i class="fas fa-plus"></i> Insert'; });
  tableModal.querySelector('.table-modal-header h2').textContent = tplTarget ? 'Change this table to a template' : 'Table Templates';
  tableModal.classList.add('active'); document.body.style.overflow = 'hidden';
}
const closeTemplates = () => { tableModal.classList.remove('active'); document.body.style.overflow = ''; tplTarget = null; };
$('closeTableModal').addEventListener('click', closeTemplates);
tableModal.addEventListener('click', e => { if (e.target === tableModal) closeTemplates(); });

/** Gives `table` the look of the template's first table: table, header-cell, cell and first-column styles. Text stays. */
function applyTemplateDesign(table, tplHtml) {
  const box = document.createElement('div'); box.innerHTML = tplHtml;
  const t = box.querySelector('table'); if (!t) return false;
  const cols = Math.max(...[...t.rows].map(r => r.cells.length));
  // header style: a <th> that is not a full-width caption (the "Event | Date" row), else any <th>
  const ths = [...t.querySelectorAll('th')];
  const colTh = ths.find(c => (c.colSpan || 1) < cols) || ths[0] || null;
  const capTh = ths.find(c => (c.colSpan || 1) >= cols && cols > 1) || null;
  const tds = [...t.querySelectorAll('td')];
  const body = tds.find((c, i) => c.cellIndex > 0) || tds[0] || null;
  const first = tds.find(c => c.cellIndex === 0) || null;
  const firstDiffers = first && body && first.getAttribute('style') !== body.getAttribute('style');
  const set = (el, src) => { if (src && src.getAttribute('style')) editor.dom.setAttrib(el, 'style', src.getAttribute('style')); };
  const tcols = Math.max(...[...table.rows].map(r => r.cells.length));
  set(table, t);
  editor.dom.setAttrib(table, 'data-mce-style', null);
  [...table.rows].forEach(r => [...r.cells].forEach(c => {
    if (c.nodeName === 'TH') set(c, capTh && (c.colSpan || 1) >= tcols && tcols > 1 ? capTh : (colTh || capTh));
    else set(c, firstDiffers && c.cellIndex === 0 ? first : body);
    editor.dom.setAttrib(c, 'data-mce-style', null);
  }));
  // a table without header cells takes the template's header look on its first row
  if (!table.querySelector('th') && colTh && table.rows[0]) [...table.rows[0].cells].forEach(c => set(c, colTh));
  return true;
}

function useTemplate(card) {
  const html = card.getAttribute('data-html'); if (!html || !editor) return;
  if (mode === 'html') { adminNotice('Switch to the Visual tab to use a template.', { type: 'info' }); return; }
  const target = tplTarget && editor.getBody().contains(tplTarget) ? tplTarget : null;
  editor.undoManager.transact(() => {
    if (target && document.querySelector('input[name="tplMode"]:checked').value === 'design') {
      if (!applyTemplateDesign(target, html)) adminNotice('This template has no table to copy the design from.', { type: 'info' });
    } else if (target) {
      editor.dom.setOuterHTML(target, html);
    } else {
      editor.focus();
      // never put a table inside another table's cell: with the cursor in a table, the template goes after that table
      let outer = null, n = editor.selection.getNode();
      while ((n = editor.dom.getParent(n, 'table')) && n !== editor.getBody()) { outer = n; n = n.parentNode; }
      if (outer) {
        const para = editor.dom.create('p', {}, '<br data-mce-bogus="1">');
        editor.dom.insertAfter(para, outer);
        editor.selection.setCursorLocation(para, 0);
      }
      editor.insertContent(html + '<p></p>');
    }
  });
  editor.nodeChanged(); closeTemplates(); isDirty = true; seoLater();
  if (window.jcAfterInsert) window.jcAfterInsert();
}
// the whole card picks the template; links inside the preview never open (they used to open a new tab)
document.querySelectorAll('.table-template-card').forEach(card => card.addEventListener('click', e => {
  e.preventDefault();
  if (e.target.closest('[data-del-tpl]')) return;
  useTemplate(card);
}));
const tplPost = d => { const fd = new FormData(); fd.append('_csrf', <?= json_encode(csrf_token(), JSON_HEX_TAG) ?>); Object.keys(d).forEach(k => fd.append(k, d[k])); return fetch('/admin/post-manager.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(r => r.json()); };
$('saveTplBtn').addEventListener('click', () => {
  const node = editor && editor.selection ? editor.dom.getParent(editor.selection.getNode(), 'table') : null;
  if (!node) { adminNotice('Click inside a table in the post first, then save it as a template.', { type: 'info' }); return; }
  const name = prompt('Template name'); if (!name) return;
  tplPost({ tpl_action: 'save', name: name, html: node.outerHTML }).then(d => { if (!d.ok) return adminNotice(d.error || 'Could not save.', { type: 'error' }); adminNotice('Template saved — it appears here next time.', { type: 'success' }); });
});
document.querySelectorAll('[data-del-tpl]').forEach(b => b.addEventListener('click', e => {
  e.stopPropagation();
  adminConfirm('Delete this template? Posts that already use it are not changed.', { title: 'Delete template', confirmText: 'Delete' }).then(ok => { if (ok) tplPost({ tpl_action: 'delete', id: b.dataset.delTpl }).then(d => { if (d.ok) b.closest('.table-template-card').remove(); }); });
}));

// Post editor → Embed Course: the choices (any other site link can be pasted too)
const DK_EMBEDS = <?php require_once INCLUDES_PATH . '/shop.php'; $__eo = [['All courses', SITE_URL . '/courses/'], ['Upcoming courses', SITE_URL . '/upcoming-courses/'], ['Free courses', SITE_URL . '/free-courses/'], ['All PDF notes', SITE_URL . '/notes/']];
try { foreach (sh_categories($pdo) as $__c) $__eo[] = ['Category · ' . $__c['name'], rtrim(SITE_URL, '/') . sh_cat_url($__c['slug'])];
    foreach ($pdo->query("SELECT * FROM sh_products WHERE status = 'published' ORDER BY type, title")->fetchAll(PDO::FETCH_ASSOC) as $__p) $__eo[] = [($__p['type'] === 'notes' ? 'Notes · ' : 'Course · ') . $__p['title'], rtrim(SITE_URL, '/') . sh_url($__p)];
} catch (Throwable $e) {}
echo json_encode(array_map(fn($x) => ['text' => $x[0], 'value' => $x[1]], $__eo), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
window.DK_EMBEDS = DK_EMBEDS;
function openEmbedDialog(ed) {
  const cur = ed.dom.getParent(ed.selection.getNode(), '.dk-embed');
  ed.windowManager.open({
    title: 'Embed a course, notes or category', size: 'normal',
    body: { type: 'panel', items: [
      { type: 'listbox', name: 'pick', label: 'Choose', items: [{ text: '— or paste a link below —', value: '' }].concat(DK_EMBEDS) },
      { type: 'input', name: 'url', label: 'Link (course, notes, category, upcoming or free courses page)', placeholder: '<?= rtrim(SITE_URL, '/') ?>/courses/…' },
      { type: 'htmlpanel', html: '<p style="margin:6px 0 0;color:#646970;font-size:12.5px">The post shows it as a card with the picture, name, lectures and price. A category / list link shows its latest four.</p>' } ] },
    initialData: { pick: '', url: cur ? cur.getAttribute('data-url') : '' },
    buttons: [{ type: 'cancel', text: 'Cancel' }, { type: 'submit', text: cur ? 'Update' : 'Insert', primary: true }],
    onChange: (api, d) => { if (d.name === 'pick') { const v = api.getData().pick; if (v) api.setData({ url: v }); } },
    onSubmit: (api) => {
      const url = (api.getData().url || '').trim(); if (!url) { ed.windowManager.alert('Choose an item or paste a link.'); return; }
      const opt = DK_EMBEDS.find(o => o.value === url), label = opt ? opt.text : url;
      const html = '<div class="dk-embed" data-url="' + ed.dom.encode(url) + '" contenteditable="false">' + ed.dom.encode(label) + '</div>';
      ed.undoManager.transact(() => { if (cur) ed.dom.setOuterHTML(cur, html); else ed.insertContent(html + '<p>&nbsp;</p>'); });
      api.close();
    }
  });
}

// Editor look = how the post looks on the website
const EDITOR_CSS = '.dk-embed{display:block;border:2px dashed #c4b5fd;border-radius:5px;padding:14px 16px 14px 46px;margin:14px 0;background:#faf8ff;color:#4c1d95;font-weight:600;position:relative;cursor:pointer}.dk-embed::before{content:"\\25B6";position:absolute;left:16px;top:50%;transform:translateY(-50%);width:20px;height:20px;border-radius:5px;background:#7c3aed;color:#fff;font-size:10px;display:grid;place-items:center}.dk-embed::after{content:"  (shown as a card on the website \\2014  double-click to change)";font-weight:400;color:#6b7280;font-size:12px}' +
  '.edu-quiz{border:2px dashed #c7d2fe;border-radius:10px;padding:10px 12px;margin:14px 0;background:#fafaff}.edu-quiz::before{content:"GK Quiz \\2014  click inside, then the GK Quiz button to edit";display:block;font:600 12px system-ui,sans-serif;color:#4f46e5;margin:0 0 6px}.eq-title{font-weight:800;font-size:18px;margin:0 0 6px}.eq-card{border:1px solid #e5e7eb;border-radius:8px;padding:12px 14px;margin:0 0 12px;background:#fff}.eq-q{font-weight:600;margin:0 0 8px}.eq-opts{list-style:none;margin:0 !important;padding:0 !important}.eq-opts li{padding:6px 10px;border-radius:6px;margin:0 0 6px;border:1px solid #eef0f3}.eq-opts li::before{content:attr(data-opt) ". ";font-weight:700}' + ['A','B','C','D','E','F'].map(l => '.eq-card[data-ans="' + l + '"] li[data-opt="' + l + '"]').join(',') + '{background:#dcfce7;box-shadow:inset 0 0 0 1px #16a34a}.eq-exp{font-size:14px;color:#475569;border-left:3px solid #6366f1;padding:4px 0 4px 10px;margin-top:10px}.eq-exp::before{content:"Explanation:";margin-right:.35em;font-weight:700;color:#334155}' + 'html{background:#fff}body{font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;font-size:17px;line-height:1.75;color:#373737;padding:24px 0 40px !important;margin:0 !important;box-sizing:border-box}' +
  'h2{font-size:26px;line-height:1.2;font-weight:600;color:#1d1d1d;margin:1.2em 0 .6em}h3{font-size:22px;line-height:1.2;font-weight:600;color:#1d1d1d;margin:1.1em 0 .5em}h4{font-size:19px;margin:1em 0 .5em}h5{font-size:17px;margin:1em 0 .5em}' +
  'p{margin:0 0 1.2em}img{max-width:100%;height:auto;border-radius:6px}a{color:#0c6878}' +
  'ul,ol{margin:0 0 1.2em 1.4em;padding-left:1.2em}' +
  'table{border-collapse:collapse;width:100%;margin:0 0 1.2em}th,td{border:1px solid #373737;padding:8.5px;vertical-align:top}th{font-weight:700;background:#f7f7f8}' +
  'blockquote{margin:1.2em 0;padding:12px 16px;border-left:3px solid #2c6e79;background:#f7f7f8;font-style:italic;color:#4b5563}' +
  'hr{border:0;border-top:1px solid #d1d5db;margin:1.2em 0}pre{background:#0f172a;color:#e2e8f0;padding:12px 14px;border-radius:6px;overflow:auto}' +
  'iframe{max-width:100%}div[data-text][data-url]{outline:1px dashed #cbd5e1;outline-offset:2px}.ql-indent-1{margin-left:2rem}.ql-indent-2{margin-left:4rem}.ql-align-center{text-align:center}.ql-align-right{text-align:right}.ql-align-justify{text-align:justify}';

setTimeout(() => { const w = document.querySelector('.pm2-wrap'); if (w) w.classList.add('pm2-ready'); }, 2500);
tinymce.init({
  selector: '#editor',
<?php if (!$tinymce_cloud): ?>  license_key: 'gpl',
<?php endif; ?>
  min_height: 560,
  placeholder: 'Start writing your post…',
  menubar: false,
  plugins: 'advlist autolink lists link image charmap preview anchor searchreplace visualblocks code codesample fullscreen insertdatetime media table help wordcount autoresize emoticons nonbreaking pagebreak',
  toolbar: 'blocks | bold italic underline | bullist numlist | alignleft aligncenter alignright | link image table tabletemplate gkquiz dkembed | moretoggle | blockquote undo redo | strikethrough forecolor backcolor | hr media linkbutton | unlink pastetext removeformat charmap | outdent indent | searchreplace visualblocks code | emoticons pagebreak | fullscreen help',
  toolbar_mode: 'wrap',
  toolbar_sticky: false,
  statusbar: true,
  elementpath: true,
  resize: false,
  menu: { insert: { title: 'Insert', items: 'image link media linkbutton tabletemplate gkquiz dkembed codesample | hr pagebreak | anchor charmap emoticons nonbreaking | insertdatetime' } },
  block_formats: 'Paragraph=p; Heading 2=h2; Heading 3=h3; Heading 4=h4; Heading 5=h5; Preformatted=pre',
  contextmenu: 'link image table',
  autoresize_bottom_margin: 40,
  branding: false,
  promotion: false,
  browser_spellcheck: true,
  entity_encoding: 'raw',
  convert_urls: false,
  relative_urls: false,
  // links (useful for SEO: target + rel options)
  link_default_protocol: 'https',
  link_assume_external_targets: 'https',
  link_title: false,
  link_context_toolbar: true,
  link_target_list: [{ title: 'Same window', value: '' }, { title: 'New window', value: '_blank' }],
  link_rel_list: [
    { title: 'None', value: '' },
    { title: 'nofollow', value: 'nofollow' },
    { title: 'sponsored nofollow', value: 'sponsored nofollow' },
    { title: 'noopener noreferrer', value: 'noopener noreferrer' }
  ],
  // images
  image_caption: true,
  image_description: true,
  image_advtab: true,
  automatic_uploads: true,
  paste_data_images: true,
  images_upload_handler: uploadImage,
  file_picker_types: 'image',
  file_picker_callback: (cb, value, meta) => { if (meta.filetype === 'image') openMlb('editor', (path, alt) => cb(path, { alt: alt || '' })); },
  // tables
  table_default_styles: { width: '100%' },
  table_responsive_width: true,
  table_advtab: true,
  table_class_list: [{ title: 'Default', value: '' }, { title: 'Striped rows', value: 'tbl-striped' }, { title: 'No borders', value: 'tbl-plain' }],
  // WordPress-like table tools: header row / column, insert & delete rows and columns, merge and split cells
  table_toolbar: 'tabletplchange | tableprops tabledelete | tableinsertrowbefore tableinsertrowafter tabledeleterow | tableinsertcolbefore tableinsertcolafter tabledeletecol | tablerowheader tablecolheader | tablemergecells tablesplitcells | tablecellbackgroundcolor',
  table_header_type: 'sectionCells',
  table_use_colgroups: false,
  table_sizing_mode: 'responsive',
  // Pasting from Word, Google Docs or another website keeps tables, lists, links and headings
  paste_webkit_styles: 'color font-weight font-style text-decoration background-color text-align',
  paste_merge_formats: true,
  smart_paste: true,
  content_style: EDITOR_CSS,
  setup: function (ed) {
    ed.ui.registry.addToggleButton('moretoggle', {
      icon: 'more-drawer', tooltip: 'Show / hide more tools',
      onAction: api => { const w = ed.getContainer().closest('.pm2-wrap'); const on = !w.classList.contains('pm2-more'); w.classList.toggle('pm2-more', on); api.setActive(on); ed.dispatch('ResizeEditor'); },
      onSetup: api => { api.setActive(ed.getContainer().closest('.pm2-wrap').classList.contains('pm2-more')); return () => {}; }
    });
    ed.ui.registry.addButton('linkbutton', { text: 'Button', tooltip: 'Insert / edit link button', onAction: () => openLinkButtonDialog(ed) });
    ed.ui.registry.addButton('tabletemplate', { text: 'Templates', tooltip: 'Insert a table template', onAction: () => openTemplates(null) });
    ed.ui.registry.addButton('tabletplchange', { text: 'Templates', icon: 'table', tooltip: 'Change this table to a template design', onAction: () => openTemplates(ed.dom.getParent(ed.selection.getNode(), 'table')) });
    ed.ui.registry.addButton('dkembed', { text: 'Embed Course', icon: 'embed', tooltip: 'Embed a course, notes, category or upcoming courses (shown as a card)', onAction: () => openEmbedDialog(ed) });
    ed.ui.registry.addMenuItem('dkembed', { text: 'Course / notes card…', icon: 'embed', onAction: () => openEmbedDialog(ed) });
    ed.on('dblclick', (e) => { if (e.target.closest && e.target.closest('.dk-embed')) openEmbedDialog(ed); });
    ed.ui.registry.addButton('gkquiz', { text: 'GK Quiz', tooltip: 'Insert / edit GK quiz (MCQ questions)', onAction: () => openQuizDialog(ed) });
    ed.ui.registry.addMenuItem('gkquiz', { text: 'GK Quiz (MCQ)…', onAction: () => openQuizDialog(ed) });
    ed.ui.registry.addMenuItem('linkbutton', { text: 'Link button…', onAction: () => openLinkButtonDialog(ed) });
    ed.ui.registry.addMenuItem('tabletemplate', { text: 'Table template…', onAction: () => openTemplates(ed.dom.getParent(ed.selection.getNode(), 'table')) });
    ed.on('BeforeSetContent', e => { if (typeof e.content === 'string') e.content = cleanLegacy(e.content); });
    ed.on('dblclick', e => { if (e.target && e.target.closest && e.target.closest('div[data-text][data-url]')) { e.preventDefault(); openLinkButtonDialog(ed); } });
    // first 6 groups always visible (… Table, Templates, More); the rest (undo/redo too, as WordPress) only after "more" is on
    const markToolbar = () => {
      const c = ed.getContainer(); if (!c) return;
      const gs = c.querySelectorAll('.tox-editor-header .tox-toolbar__group');
      gs.forEach((g, i) => g.classList.toggle('pm2-hide', i >= 6));
      if (gs.length) { const w = c.closest('.pm2-wrap'); if (w) w.classList.add('pm2-ready'); }
    };
    ed.on('SkinLoaded ResizeEditor ResizeWindow PostRender', markToolbar);
    ed.on('init', () => {
      markToolbar(); setTimeout(markToolbar, 300); setTimeout(markToolbar, 1000);
      editor = ed;
      updateAll();
      ed.on('input change keyup undo redo SetContent', () => { seoLater(); });
      ed.on('input change undo redo', () => { isDirty = true; });
    });
  }
});

/* Visual / HTML */
document.querySelectorAll('.be-mode button').forEach(btn => btn.addEventListener('click', () => {
  const to = btn.dataset.mode; if (to === mode || !editor) return;
  if (to === 'html') { $('html-source').value = editor.getContent(); $('html-source').hidden = false; $('pe-visual').hidden = true; }
  else { editor.setContent($('html-source').value); $('html-source').hidden = true; $('pe-visual').hidden = false; }
  mode = to;
  document.querySelectorAll('.be-mode button').forEach(x => x.classList.toggle('is-active', x === btn));
  updateAll();
}));

/* ---------- title / slug / summary ---------- */
const slugify = t => t.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 120);
let slugTouched = IS_EDIT || $('slug').value !== '';
$('slug').addEventListener('input', () => { slugTouched = true; });
$('title').addEventListener('input', () => { if (!slugTouched) $('slug').value = slugify($('title').value); });
$('slug').addEventListener('blur', () => { $('slug').value = slugify($('slug').value); });
const sumCount = () => { $('summary-count').textContent = $('summary').value.length + '/600'; };
$('summary').addEventListener('input', sumCount); sumCount();

/* ---------- key points ---------- */
const kpInit = <?= json_encode($v_kps, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
function kpRender(values) {
  const list = $('kp-list'); list.innerHTML = '';
  (values.length ? values : ['']).forEach(v => kpAdd(v, false));
  kpRefresh();
}
function kpAdd(value, focus) {
  const row = document.createElement('div'); row.className = 'kp-row';
  row.innerHTML = '<span class="kp-num"></span><input type="text" maxlength="500" name="key_points[]" placeholder="e.g. Applications open from 10 October"><button type="button" class="kp-btn" data-kp="up" title="Move up"><i class="fas fa-arrow-up"></i></button><button type="button" class="kp-btn" data-kp="down" title="Move down"><i class="fas fa-arrow-down"></i></button><button type="button" class="kp-btn kp-del" data-kp="del" title="Remove"><i class="fas fa-xmark"></i></button>';
  row.querySelector('input').value = value || '';
  $('kp-list').appendChild(row);
  if (focus) row.querySelector('input').focus();
  kpRefresh();
  return row;
}
function kpRefresh() {
  const rows = [...$('kp-list').children];
  rows.forEach((r, i) => { r.querySelector('.kp-num').textContent = i + 1; r.querySelector('[data-kp=up]').disabled = i === 0; r.querySelector('[data-kp=down]').disabled = i === rows.length - 1; });
  $('kp-count').textContent = rows.filter(r => r.querySelector('input').value.trim()).length + ' added';
}
$('kp-add').addEventListener('click', () => kpAdd('', true));
$('kp-list').addEventListener('click', e => {
  const b = e.target.closest('[data-kp]'); if (!b) return;
  const row = b.closest('.kp-row');
  if (b.dataset.kp === 'up' && row.previousElementSibling) row.parentNode.insertBefore(row, row.previousElementSibling);
  else if (b.dataset.kp === 'down' && row.nextElementSibling) row.parentNode.insertBefore(row.nextElementSibling, row);
  else if (b.dataset.kp === 'del') { if ($('kp-list').children.length > 1) row.remove(); else row.querySelector('input').value = ''; }
  kpRefresh();
});
$('kp-list').addEventListener('input', kpRefresh);
$('kp-list').addEventListener('keydown', e => {
  if (e.key === 'Enter' && e.target.matches('input')) { e.preventDefault(); const row = e.target.closest('.kp-row'); const n = kpAdd('', false); row.after(n); kpRefresh(); n.querySelector('input').focus(); }
});
kpRender(kpInit);

/* ---------- tags: chips (Enter / comma adds, × removes) saved as "a, b, c" in the hidden tags_csv field ---------- */
(() => {
  const hid = $('tags'), inp = $('tg-entry'), box = $('tg-chips');
  const list = () => hid.value.split(',').map(t => t.trim()).filter(Boolean);
  const set = (arr) => { const seen = new Set(), out = []; arr.forEach(t => { const k = t.toLowerCase(); if (t && !seen.has(k)) { seen.add(k); out.push(t.slice(0, 80)); } }); hid.value = out.join(', '); render(); hid.dispatchEvent(new Event('input', { bubbles: true })); };
  function render() { box.innerHTML = list().map((t, i) => '<span class="tg-chip"><span title="' + escAttr(t) + '">' + escAttr(t) + '</span><button type="button" data-i="' + i + '" aria-label="Remove tag ' + escAttr(t) + '">×</button></span>').join(''); }
  function add() { const v = inp.value.split(',').map(t => t.trim()).filter(Boolean); if (v.length) set(list().concat(v)); inp.value = ''; }
  inp.addEventListener('keydown', e => {
    if (e.key === 'Enter' || e.key === ',') { e.preventDefault(); add(); }
    else if (e.key === 'Backspace' && !inp.value) { const l = list(); l.pop(); set(l); }
  });
  inp.addEventListener('input', () => { if (inp.value.includes(',')) add(); });
  inp.addEventListener('change', () => { if (inp.value.trim() && [...document.querySelectorAll('#tag-suggest option')].some(o => o.value === inp.value.trim())) add(); }); // picked from suggestions
  inp.addEventListener('blur', add);
  box.addEventListener('click', e => { const b = e.target.closest('button[data-i]'); if (!b) return; const l = list(); l.splice(+b.dataset.i, 1); set(l); inp.focus(); });
  $('tg-box').addEventListener('click', e => { if (e.target === e.currentTarget) inp.focus(); });
  $('post-form').addEventListener('submit', add, true); // a tag still being typed is kept
  window.tagsRender = render;
  render();
})();
// Enter in a one-line field never publishes the post (only the Publish / Save buttons do)
$('post-form').addEventListener('keydown', e => {
  if (e.key !== 'Enter' || e.defaultPrevented || e.isComposing) return;
  const t = e.target;
  if (t.matches('input:not([type=submit]):not([type=button]):not([type=checkbox]):not([type=radio]):not([type=file]), select')) e.preventDefault();
});

/* ---------- publish box ---------- */
document.querySelectorAll('[data-edit]').forEach(a => a.addEventListener('click', e => { e.preventDefault(); const box = $(a.dataset.edit); box.hidden = false; box.classList.toggle('open'); }));
document.querySelectorAll('[data-ok]').forEach(b => b.addEventListener('click', () => {
  const k = b.dataset.ok;
  if (k === 'status') { $('status-label').textContent = $('status').selectedOptions[0].text; $('status-box').classList.remove('open'); }
  if (k === 'author') { $('author-label').textContent = $('author_id').selectedOptions[0]?.text || '—'; $('author-box').classList.remove('open'); }
  if (k === 'last') { const v = $('last_date').value; $('last-label').textContent = v ? new Date(v + 'T00:00:00').toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' }) : 'Not set'; $('last-box').classList.remove('open'); }
}));
$('save-draft-btn').addEventListener('click', () => { $('status').value = 'draft'; if (form.reportValidity()) form.requestSubmit ? form.requestSubmit() : form.submit(); });
$('preview-btn').addEventListener('click', () => {
  const w = window.open('', '_blank'); if (!w) return;
  const esc = s => s.replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const kps = [...document.querySelectorAll('[name="key_points[]"]')].map(i => i.value.trim()).filter(Boolean);
  const feat = $('feat-img') ? '<img src="' + $('feat-img').src + '" style="width:100%;aspect-ratio:16/9;object-fit:cover;border-radius:6px">' : '';
  w.document.write('<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Preview: ' + esc($('title').value) + '</title><link rel="stylesheet" href="/assets/css/post.css"><style>body{font-family:system-ui,sans-serif;max-width:815px;margin:24px auto;padding:0 16px;color:#222;line-height:1.7}.pv-tag{display:inline-block;background:#fef3c7;color:#92400e;font-size:12px;font-weight:700;padding:4px 10px;border-radius:999px}.pv-box{background:#f0f8f9;border-left:4px solid #0c6878;padding:12px 16px;margin:16px 0;border-radius:6px}img{max-width:100%}table{border-collapse:collapse;width:100%}td,th{border:1px solid #ddd;padding:8px}</style></head><body><span class="pv-tag">Preview — not published</span><h1>' + esc($('title').value || 'Untitled') + '</h1>' + feat + ($('summary').value.trim() ? '<div class="pv-box"><b>Summary</b><br>' + esc($('summary').value) + '</div>' : '') + (kps.length ? '<div class="pv-box"><b>Key Points</b><ul>' + kps.map(k => '<li>' + esc(k) + '</li>').join('') + '</ul></div>' : '') + '<div class="entry-content">' + contentHtml() + '</div></body></html>');
  w.document.close();
});

/* ---------- featured image: media library + upload/crop ---------- */
const mlb = $('mlb'); let mlbPage = 1, mlbSel = null, mlbTimer, mlbMode = 'featured', mlbCb = null;
function openMlb(m, cb) { mlbMode = m || 'featured'; mlbCb = cb || null; mlb.hidden = false; document.body.style.overflow = 'hidden'; loadMedia(1); }
function setFeatured(src, id, alt) {
  $('featured_image_id').value = id || '';
  $('feat-visual').innerHTML = src ? '<img id="feat-img" src="' + src + '" alt="' + (alt || '').replace(/"/g, '&quot;') + '">' : '<div class="feat-img-placeholder"><i class="fas fa-image"></i><span>Set featured image</span></div>';
  $('feat-set').textContent = src ? '↻ Replace Image' : '+ Set Featured Image';
  $('feat-remove').hidden = !src;
  updateAll();
}
$('feat-set').addEventListener('click', () => openMlb('featured'));
function setJobLogo(p) { const v = (p || '').replace(/^\/+/, ''); $('job_logo').value = v; const im = $('job-logo-prev'); im.hidden = !v; if (v) im.src = '/' + v; $('job-logo-clear').hidden = !v; $('job-logo-pick').textContent = v ? 'Change' : '+ Choose logo'; }
// WordPress-style boxes: ▲ ▼ to move, ▾ to fold, drag by the header; order + folded boxes remembered (per user, this browser).
// Summary and Key Points (data-lock) stay in place around the content — they can only be folded.
(() => {
  const KEY = 'edu_pm_layout_<?= (int)($_SESSION['user_id'] ?? 0) ?>', cols = { main: document.querySelector('.editor-main'), side: document.querySelector('.editor-sidebar-panel') };
  let state = { order: {}, closed: [] };
  try { state = Object.assign(state, JSON.parse(localStorage.getItem(KEY) || '{}')); } catch (e) {}
  const save = () => { try { localStorage.setItem(KEY, JSON.stringify(state)); } catch (e) {} };
  const slug = (t) => t.toLowerCase().replace(/\d+\s*\/\s*\d+|\d+ added/g, '').replace(/[^a-z]+/g, '-').replace(/^-|-$/g, '');
  const boxes = (col) => [...col.children].filter((el) => el.classList.contains('meta-panel') && el.dataset.box && !el.dataset.lock);
  Object.entries(cols).forEach(([name, col]) => {
    if (!col) return;
    [...col.children].forEach((p) => {
      if (!p.classList.contains('meta-panel')) return;
      const h = p.querySelector(':scope > .meta-panel-header'); if (!h) return;
      const k = p.id || slug(h.querySelector('span') ? h.querySelector('span').textContent : h.textContent); if (!k) return;
      p.dataset.box = k;
      const ctl = document.createElement('span'); ctl.className = 'pmx-ctl';
      const locked = p.dataset.lock === '1';
      ctl.innerHTML = locked ? '<button type="button" class="pmx-b pmx-t" aria-label="Show or hide this box" title="Show / hide"><i class="fas fa-caret-down"></i></button>' : '<button type="button" class="pmx-b" data-mv="-1" aria-label="Move up" title="Move up"><i class="fas fa-chevron-up"></i></button><button type="button" class="pmx-b" data-mv="1" aria-label="Move down" title="Move down"><i class="fas fa-chevron-down"></i></button><button type="button" class="pmx-b pmx-t" aria-label="Show or hide this box" title="Show / hide"><i class="fas fa-caret-down"></i></button>';
      h.appendChild(ctl); h.classList.add('pmx-h'); if (!locked) h.setAttribute('draggable', 'true');
      if (state.closed.includes(k)) p.classList.add('pmx-closed');
      ctl.addEventListener('click', (e) => {
        const b = e.target.closest('.pmx-b'); if (!b) return; e.stopPropagation();
        if (b.classList.contains('pmx-t')) { toggle(p); return; }
        const list = boxes(col), i = list.indexOf(p), j = i + (+b.dataset.mv);
        if (j < 0 || j >= list.length) return;
        if (+b.dataset.mv < 0) col.insertBefore(p, list[j]); else col.insertBefore(p, list[j].nextSibling);
        remember(name); p.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); b.focus();
      });
      h.addEventListener('click', (e) => { if (e.target.closest('button, a, input, select, label, .pmx-ctl')) return; toggle(p); });
      if (!locked) h.addEventListener('dragstart', (e) => { p.classList.add('pmx-drag'); e.dataTransfer.effectAllowed = 'move'; try { e.dataTransfer.setData('text/plain', k); } catch (x) {} });
      h.addEventListener('dragend', () => { p.classList.remove('pmx-drag'); remember(name); });
    });
    col.addEventListener('dragover', (e) => {
      const drag = col.querySelector('.pmx-drag'); if (!drag) return; e.preventDefault();
      const after = boxes(col).filter((x) => x !== drag).find((x) => { const r = x.getBoundingClientRect(); return e.clientY < r.top + r.height / 2; });
      if (after) col.insertBefore(drag, after); else { const l = boxes(col); col.insertBefore(drag, l[l.length - 1].nextSibling); }
    });
    // saved order
    const saved = state.order[name];
    if (Array.isArray(saved)) {
      const list = boxes(col), byKey = Object.fromEntries(list.map((x) => [x.dataset.box, x])), anchor = list.length ? list[list.length - 1].nextSibling : null;
      saved.forEach((k) => { if (byKey[k]) col.insertBefore(byKey[k], anchor); });
      list.filter((x) => !saved.includes(x.dataset.box)).forEach((x) => col.insertBefore(x, anchor)); // new boxes keep their place at the end
    }
  });
  function toggle(p) { p.classList.toggle('pmx-closed'); const k = p.dataset.box; state.closed = state.closed.filter((x) => x !== k); if (p.classList.contains('pmx-closed')) state.closed.push(k); save(); }
  function remember(name) { state.order[name] = boxes(cols[name]).map((x) => x.dataset.box); save(); }
})();
// Job card ⇄ "Job Details" table in the post: the cells marked data-jc mirror the Job card fields, both ways
(() => {
  const F = {
    job_post_name: () => $('job_post_name'), job_org: () => document.querySelector('[name="job_org"]'), job_location: () => $('job_location'),
    job_salary: () => document.querySelector('[name="job_salary"]'), job_vacancies: () => $('job_vacancies'), last_date: () => $('last_date'),
  };
  const MONTHS = ['january','february','march','april','may','june','july','august','september','october','november','december'];
  const ed = () => (window.tinymce && tinymce.get('editor')) || null;
  const cells = (k) => { const e = ed(); return e ? e.dom.select('table[data-jobcard] td[data-jc' + (k ? '="' + k + '"' : '') + ']') : []; };
  function qualValue() { const sel = $('job_qual_sel'); return sel.value === '__other' ? $('job_qual_other').value.trim() : sel.value; }
  function display(k) {
    if (k === 'job_qualification') return qualValue();
    const el = F[k](); let v = (el ? el.value : '').trim();
    if (k === 'job_post_name' && !v) v = $('title').value.trim();
    if (k === 'job_salary' && /^\d/.test(v)) v = '₹' + v;
    if (k === 'job_vacancies' && v) v = Number(v).toLocaleString('en-IN');
    if (k === 'last_date' && v) v = new Date(v + 'T00:00:00').toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' });
    return v;
  }
  let pushing = false;
  function toTable(only) {
    const e = ed(); if (!e) return; pushing = true;
    cells().forEach((td) => { const k = td.getAttribute('data-jc'); if (only && k !== only) return; const v = display(k) || '—'; if (td.textContent.trim() !== v) td.textContent = v; });
    pushing = false;
  }
  function parseDate(t) {
    t = t.trim().toLowerCase(); let m;
    if ((m = t.match(/^(\d{4})-(\d{1,2})-(\d{1,2})$/))) return m[1] + '-' + m[2].padStart(2, '0') + '-' + m[3].padStart(2, '0');
    if ((m = t.match(/^(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{4})$/))) return m[3] + '-' + m[2].padStart(2, '0') + '-' + m[1].padStart(2, '0');
    if ((m = t.match(/^(\d{1,2})\s*([a-z]+)\.?,?\s*(\d{4})$/))) { const i = MONTHS.findIndex((x) => x.startsWith(m[2].slice(0, 3))); if (i >= 0) return m[3] + '-' + String(i + 1).padStart(2, '0') + '-' + m[1].padStart(2, '0'); }
    return '';
  }
  function fromTable() {
    if (pushing) return;
    cells().forEach((td) => {
      const k = td.getAttribute('data-jc'), t = td.textContent.replace(/ /g, ' ').trim(); if (t === '—' || t === display(k)) return;
      if (k === 'job_qualification') {
        const sel = $('job_qual_sel'), opt = [...sel.options].find((o) => o.value && o.value.toLowerCase() === t.toLowerCase());
        if (opt) { sel.value = opt.value; $('job_qual_other').hidden = true; } else if (t) { sel.value = '__other'; $('job_qual_other').hidden = false; $('job_qual_other').value = t; } else sel.value = '';
        return;
      }
      const el = F[k](); if (!el) return;
      let v = t;
      if (k === 'job_salary') v = t.replace(/^\s*₹\s*/, '');
      if (k === 'job_vacancies') v = t.replace(/\D/g, '');
      if (k === 'last_date') { v = parseDate(t); if (!v && t) return; }
      if (el.value !== v) { el.value = v; if (el.dataset.auto !== undefined) el.dataset.auto = '0'; if (k === 'last_date') el.dispatchEvent(new Event('change')); }
    });
  }
  // fields → table (live)
  Object.keys(F).forEach((k) => { const el = F[k](); if (el) ['input', 'change'].forEach((ev) => el.addEventListener(ev, () => toTable(k))); });
  ['change', 'input'].forEach((ev) => { $('job_qual_sel').addEventListener(ev, () => toTable('job_qualification')); $('job_qual_other').addEventListener(ev, () => toTable('job_qualification')); });
  $('title').addEventListener('input', () => toTable('job_post_name')); $('state_id').addEventListener('change', () => setTimeout(() => toTable('job_location'), 0));
  // table → fields (as you type in the post)
  const hook = () => { const e = ed(); if (!e) return setTimeout(hook, 300); e.on('input keyup change SetContent Undo Redo', () => { clearTimeout(hook.t); hook.t = setTimeout(fromTable, 250); }); };
  hook();
  window.jcAfterInsert = () => { if (!cells().length) return; const on = $('job_card'); if (!on.checked) { on.checked = true; on.dispatchEvent(new Event('change')); } toTable(); };
})();
// Logo library: only the logos uploaded here (uploads/logos), live search by title, upload with a title
(() => {
  const box = $('llb'), grid = $('llb-grid'), q = $('llb-q'), more = $('llb-more'); let page = 1, pages = 1, timer = 0, seq = 0;
  const esc = (t) => String(t || '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  function load(p) {
    const my = ++seq; page = p;
    fetch('/admin/api/media-library.php?folder=logos&page=' + p + '&q=' + encodeURIComponent(q.value.trim()), { credentials: 'same-origin' }).then((r) => r.json()).then((d) => {
      if (my !== seq) return;
      pages = d.pages || 1;
      const html = (d.items || []).map((it) => '<button type="button" class="llb-item" data-path="' + esc(it.path) + '"><img src="' + esc(it.thumb) + '" alt="" loading="lazy"><span>' + esc(it.title || it.alt || it.path.split('/').pop()) + '</span></button>').join('');
      if (p === 1) grid.innerHTML = html || '<p class="llb-empty">' + (q.value.trim() ? 'No logo matches “' + esc(q.value.trim()) + '”.' : 'No logos yet — upload the first one.') + '</p>'; else grid.insertAdjacentHTML('beforeend', html);
      more.hidden = page >= pages;
    }).catch(() => { grid.innerHTML = '<p class="llb-empty">Could not load the logos.</p>'; });
  }
  function open() { box.hidden = false; document.body.style.overflow = 'hidden'; q.value = ''; load(1); setTimeout(() => q.focus(), 50); }
  function close() { box.hidden = true; document.body.style.overflow = ''; $('llb-up').hidden = true; $('llb-msg').textContent = ''; }
  $('job-logo-pick').addEventListener('click', open);
  $('llb-close').addEventListener('click', close);
  box.addEventListener('click', (e) => { if (e.target === box) close(); const it = e.target.closest('.llb-item'); if (it) { setJobLogo(it.dataset.path); close(); } });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !box.hidden) close(); });
  q.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(() => load(1), 250); });
  more.addEventListener('click', () => load(page + 1));
  $('llb-up-btn').addEventListener('click', () => { $('llb-up').hidden = !$('llb-up').hidden; if (!$('llb-up').hidden) $('llb-title').focus(); });
  $('llb-file').addEventListener('change', () => {
    const f = $('llb-file').files[0], msg = $('llb-msg'); if (!f) return;
    const title = $('llb-title').value.trim() || f.name.replace(/\.[^.]+$/, '');
    const fd = new FormData(); fd.append('file', f); fd.append('title', title); fd.append('alt', title + ' logo'); fd.append('folder', 'logos');
    msg.textContent = 'Uploading…';
    fetch('/admin/api/media-upload.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then((r) => r.json()).then((d) => {
      $('llb-file').value = '';
      if (!d.path) { msg.textContent = d.error || 'Upload failed.'; return; }
      msg.textContent = ''; $('llb-title').value = ''; setJobLogo(d.path); close();
    }).catch(() => { msg.textContent = 'Upload failed.'; });
  });
})();
$('job-logo-clear').addEventListener('click', () => setJobLogo(''));
// Job card: switch, qualification "Other", location follows the State until typed by hand, digits-only seats
(() => {
  const on = $('job_card'), box = $('jc-fields'), sel = $('job_qual_sel'), other = $('job_qual_other'), loc = $('job_location'), st = $('state_id'), seats = $('job_vacancies');
  on.addEventListener('change', () => { box.hidden = !on.checked; if (on.checked) syncLoc(); });
  const saved = <?= json_encode((string)($pm['job_qualification'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
  if (saved && [...sel.options].some(o => o.value === saved)) sel.value = saved;
  sel.addEventListener('change', () => { other.hidden = sel.value !== '__other'; if (!other.hidden) other.focus(); });
  const pn = $('job_post_name'), tt = $('title');
  function syncName() { if (pn.dataset.auto === '1') pn.value = tt.value.trim().slice(0, 150); }
  pn.addEventListener('input', () => { pn.dataset.auto = pn.value.trim() === '' ? '1' : '0'; });
  tt.addEventListener('input', syncName);
  on.addEventListener('change', syncName); if (on.checked && !pn.value) syncName();
  function syncLoc() { if (loc.dataset.auto !== '1') return; const o = st.selectedOptions[0]; loc.value = st.value && o ? o.textContent.trim() : 'All India'; }
  loc.addEventListener('input', () => { loc.dataset.auto = loc.value.trim() === '' ? '1' : '0'; });
  st.addEventListener('change', syncLoc);
  if (on.checked && !loc.value) syncLoc();
  seats.addEventListener('input', () => { const v = seats.value.replace(/\D/g, ''); if (v !== seats.value) seats.value = v; });
  $('last_date').addEventListener('change', () => { const v = $('last_date').value; $('last-label').textContent = v ? new Date(v + 'T00:00:00').toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' }) : 'Not set'; });
  $('last-jump').addEventListener('click', (e) => { e.preventDefault(); if (!on.checked) { on.checked = true; box.hidden = false; syncLoc(); } $('job-card').scrollIntoView({ behavior: 'smooth', block: 'start' }); setTimeout(() => $('last_date').focus(), 350); });
})();
$('feat-visual').addEventListener('click', () => $('feat-set').click());
$('feat-remove').addEventListener('click', () => { $('banner').value = ''; setFeatured('', '', ''); });
mlb.addEventListener('click', e => { if (e.target === mlb || e.target.closest('[data-mlb-close]')) { mlb.hidden = true; document.body.style.overflow = ''; } });
$('mlb-search').addEventListener('input', () => { clearTimeout(mlbTimer); mlbTimer = setTimeout(() => loadMedia(1), 300); });
function loadMedia(page, selectItem) {
  mlbPage = page;
  $('mlb-grid').innerHTML = '<div class="mlb-msg"><i class="fas fa-spinner fa-spin"></i></div>';
  fetch('/admin/api/media-library.php?page=' + page + '&q=' + encodeURIComponent($('mlb-search').value.trim()), { credentials: 'same-origin' })
    .then(r => r.json()).then(d => {
      const g = $('mlb-grid'); g.innerHTML = '';
      if (!d.items || !d.items.length) { g.innerHTML = '<div class="mlb-msg"><i class="fas fa-image"></i>No images found</div>'; }
      if (!selectItem) $('mlb-selname').textContent = mlbSel ? 'Selected: ' + mlbSel.path.split('/').pop() : 'No image selected';
      (d.items || []).forEach(it => {
        const c = document.createElement('button'); c.type = 'button'; c.className = 'mlb-item' + (mlbSel && mlbSel.id === it.id ? ' is-sel' : '');
        c.innerHTML = '<img loading="lazy" src="' + it.thumb + '" alt="">';
        c.onclick = () => { mlbSel = it; g.querySelectorAll('.mlb-item').forEach(x => x.classList.toggle('is-sel', x === c)); showDetail(it); };
        g.appendChild(c);
        if (selectItem && selectItem.id === it.id) c.click();
      });
      $('mlb-pager').innerHTML = d.pages > 1 ? '<button type="button" class="btn-action btn-edit" ' + (page <= 1 ? 'disabled' : '') + ' data-p="' + (page - 1) + '">« Prev</button><span>Page ' + page + ' of ' + d.pages + '</span><button type="button" class="btn-action btn-edit" ' + (page >= d.pages ? 'disabled' : '') + ' data-p="' + (page + 1) + '">Next »</button>' : '';
    }).catch(() => { $('mlb-grid').innerHTML = '<div class="mlb-msg">Could not load images.</div>'; });
}
$('mlb-pager').addEventListener('click', e => { const b = e.target.closest('[data-p]'); if (b) loadMedia(+b.dataset.p); });
function showDetail(it) {
  $('mlb-use').disabled = false;
  $('mlb-selname').textContent = 'Selected: ' + it.path.split('/').pop();
  $('mlb-detail').innerHTML = '<img src="' + it.path + '" class="mlb-prev" alt=""><div class="form-group"><label>Alt Text</label><input class="form-control" id="mlb-alt"></div><span class="mlb-saved" id="mlb-saved"></span><div class="form-group"><label>File URL</label><input class="form-control" readonly value="' + location.origin + it.path + '"></div><a class="btn-action btn-view" href="' + it.path + '" download><i class="fas fa-download"></i> Download</a>';
  const alt = $('mlb-alt'); alt.value = it.alt || '';
  let t; alt.addEventListener('input', () => { clearTimeout(t); t = setTimeout(() => {
    const fd = new FormData(); fd.append('id', it.id); fd.append('alt_text', alt.value);
    fetch('/admin/api/media-library.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(() => { it.alt = alt.value; $('mlb-saved').textContent = 'Saved'; });
  }, 500); });
}
$('mlb-use').addEventListener('click', () => {
  if (!mlbSel) return;
  if (mlbMode === 'editor' && mlbCb) { const cb = mlbCb; mlbCb = null; cb(mlbSel.path, mlbSel.alt || ''); }
  else { $('banner').value = ''; setFeatured(mlbSel.path, mlbSel.id, mlbSel.alt); }
  mlb.hidden = true; document.body.style.overflow = '';
});
$('mlb-upload').addEventListener('change', function () {
  const files = [...this.files].filter(f => f.type.startsWith('image/')); this.value = '';
  if (!files.length) return;
  let done = 0, last = null;
  $('mlb-selname').textContent = 'Uploading… 0%';
  const next = () => {
    if (done >= files.length) { $('mlb-search').value = ''; loadMedia(1, last); return; }
    const fd = new FormData(); fd.append('file', files[done]); fd.append('alt', $('title').value.trim());
    if (mlbMode === 'featured') fd.append('purpose', 'featured'); // → WebP 1200×675 (16:9 crop); article images keep their shape
    fetch('/admin/api/media-upload.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(r => r.json()).then(d => { if (d.error) alert(d.error); else last = d; })
      .catch(() => alert('Upload failed — please try again.'))
      .finally(() => { done++; $('mlb-selname').textContent = 'Uploading… ' + Math.round(done / files.length * 100) + '%'; next(); });
  };
  next();
});

/* ---------- FAQs ---------- */
const faqModal = $('faqModal'), faqInput = $('faq_json_input');
let faqs = []; try { faqs = JSON.parse(faqInput.value || '[]') || []; } catch (e) { faqs = []; }
const faqCount = () => { $('faq-count').textContent = faqs.length ? faqs.length + ' FAQ' + (faqs.length > 1 ? 's' : '') + ' added.' : 'No FAQs added.'; };
function faqRow(q, a) {
  const d = document.createElement('div'); d.className = 'faq-row';
  d.innerHTML = '<div class="faq-row-head"><span class="faq-row-num"></span><button type="button" class="faq-rm"><i class="fas fa-trash-alt"></i> Remove</button></div><label>Question</label><input type="text" class="faq-q" placeholder="e.g. What is the last date?"><label style="margin-top:7px">Answer</label><textarea class="faq-a" placeholder="Detailed answer…"></textarea>';
  d.querySelector('.faq-q').value = q || ''; d.querySelector('.faq-a').value = a || '';
  d.querySelector('.faq-rm').onclick = () => { d.remove(); faqNums(); };
  $('faq-rows').appendChild(d); faqNums();
}
const faqNums = () => [...$('faq-rows').children].forEach((r, i) => { r.querySelector('.faq-row-num').textContent = 'FAQ #' + (i + 1); });
$('open-faq-modal').addEventListener('click', () => { $('faq-rows').innerHTML = ''; (faqs.length ? faqs : [{ q: '', a: '' }]).forEach(f => faqRow(f.q, f.a)); faqModal.hidden = false; document.body.style.overflow = 'hidden'; });
const closeFaq = () => { faqModal.hidden = true; document.body.style.overflow = ''; };
$('closeFaqModal').addEventListener('click', closeFaq);
faqModal.addEventListener('click', e => { if (e.target === faqModal) closeFaq(); });
$('add-faq-btn').addEventListener('click', () => faqRow('', ''));
$('insert-faq-btn').addEventListener('click', () => {
  faqs = [...$('faq-rows').children].map(r => ({ q: r.querySelector('.faq-q').value.trim(), a: r.querySelector('.faq-a').value.trim() })).filter(f => f.q && f.a);
  faqInput.value = faqs.length ? JSON.stringify(faqs) : ''; faqCount(); closeFaq(); isDirty = true;
});
faqCount();

/* ---------- SEO panel (Rank Math style) ---------- */
const HAS_SEO = !!$('rmx-groups');
const SEO_POST_FORMAT = <?= json_encode((string)seo_opt('post_format'), JSON_HEX_TAG) ?>, SEO_SEP = <?= json_encode((string)seo_opt('sep'), JSON_HEX_TAG) ?>, SEO_SITE = <?= json_encode(edu_seo_site_name(), JSON_HEX_TAG) ?>;
const POWER_WORDS = ['amazing','best','complete','easy','essential','exclusive','free','guide','latest','new','official','proven','quick','simple','secret','top','ultimate','urgent','important','big','huge','alert','breaking','last date','apply now','released','out','declared','big news','good news'];
const strip = h => h.replace(/<(script|style)[\s\S]*?<\/\1>/gi, ' ').replace(/<[^>]+>/g, ' ').replace(/&nbsp;/g, ' ').replace(/&amp;/g, '&').replace(/\s+/g, ' ').trim();
let descTouched = !!(document.getElementById('meta_description') && $('meta_description').value.trim());
function autoDesc() { const sum = $('summary').value.trim(); const txt = sum || strip(contentHtml()); if (txt.length <= 158) return txt; const cut = txt.slice(0, 158); const sp = cut.lastIndexOf(' '); return (sp > 100 ? cut.slice(0, sp) : cut).replace(/[ ,.;:-]+$/, ''); }
function seoTitleOf(t) { const tpl = ($('seo_title') && $('seo_title').value.trim()) || SEO_POST_FORMAT || '%title% %sep% %sitename%'; const cat = $('main_category_id') && $('main_category_id').selectedIndex > 0 ? $('main_category_id').selectedOptions[0].text : ''; return tpl.replace(/%title%/g, t || 'Post title').replace(/%sitename%/g, SEO_SITE).replace(/%sep%|%separator%/g, SEO_SEP).replace(/%category%/g, cat).replace(/%currentyear%/g, new Date().getFullYear()).replace(/%focuskw%/g, keywords()[0] || '').replace(/%[a-z_]+%/g, '').replace(/\s+/g, ' ').trim(); }
function keywords() { return ($('focus_keyword') ? $('focus_keyword').value : '').split(',').map(s => s.trim()).filter(Boolean); }
// Focus keyword chips
function kwRender() {
  if (!HAS_SEO) return;
  const box = $('rmx-kw'), inp = $('rmx-kw-in');
  box.querySelectorAll('.rmx-chip').forEach(c => c.remove());
  keywords().forEach((k, i) => { const c = document.createElement('span'); c.className = 'rmx-chip' + (i === 0 ? ' primary' : ''); c.innerHTML = (i === 0 ? '<i class="fas fa-star"></i> ' : '') + k.replace(/[<>&]/g, '') + ' <button type="button" aria-label="Remove">×</button>'; c.querySelector('button').onclick = () => { const l = keywords(); l.splice(i, 1); $('focus_keyword').value = l.join(', '); kwRender(); seoLater(); isDirty = true; }; box.insertBefore(c, inp); });
  inp.placeholder = keywords().length ? 'Add another keyword' : 'Example: SSC CGL notification 2026';
}
if (HAS_SEO) {
  const inp = $('rmx-kw-in');
  const add = () => { const v = inp.value.replace(/,/g, ' ').trim(); if (!v) return; const l = keywords(); if (!l.map(x => x.toLowerCase()).includes(v.toLowerCase())) l.push(v); $('focus_keyword').value = l.slice(0, 5).join(', '); inp.value = ''; kwRender(); seoLater(); isDirty = true; };
  inp.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ',') { e.preventDefault(); add(); } else if (e.key === 'Backspace' && !inp.value) { const l = keywords(); l.pop(); $('focus_keyword').value = l.join(', '); kwRender(); seoLater(); } });
  inp.addEventListener('blur', add);
  $('rmx-kw').addEventListener('click', () => inp.focus());
  kwRender();
  document.querySelectorAll('.rmx-tabs button').forEach(b => b.addEventListener('click', () => {
    document.querySelectorAll('.rmx-tabs button').forEach(x => x.classList.toggle('active', x === b));
    document.querySelectorAll('.rmx [data-pane]').forEach(p => { p.hidden = p.dataset.pane !== b.dataset.tab; });
  }));
  document.querySelectorAll('[data-dev]').forEach(b => b.addEventListener('click', () => { document.querySelectorAll('[data-dev]').forEach(x => x.classList.toggle('on', x === b)); $('rmx-serp').classList.toggle('mobile', b.dataset.dev === 'mobile'); }));
  document.querySelectorAll('[name=robots_mode]').forEach(r => r.addEventListener('change', () => { $('noindex').value = (document.querySelector('[name=robots_mode]:checked') || {}).value === 'noindex' ? '1' : ''; }));
  document.querySelectorAll('[name=schema_type]').forEach(r => r.addEventListener('change', updateSchemaName));
  let soc = 'fb';
  document.querySelectorAll('[data-soc]').forEach(b => b.addEventListener('click', () => { soc = b.dataset.soc; document.querySelectorAll('[data-soc]').forEach(x => x.classList.toggle('on', x === b)); $('rmx-social-card').classList.toggle('tw', soc === 'tw'); }));
  $('rmx-og-pick').addEventListener('click', () => openMlb('editor', path => { $('og_image').value = path; $('rmx-og-clear').hidden = false; updateSocial(); isDirty = true; }));
  $('rmx-og-clear').addEventListener('click', () => { $('og_image').value = ''; $('rmx-og-clear').hidden = true; updateSocial(); });
  ['og_title', 'og_description'].forEach(id => $(id).addEventListener('input', updateSocial));
  // Edit Snippet popup
  const snip = $('snippetModal'); document.body.appendChild(snip);
  const snipSync = () => {
    const t = seoTitleOf($('title').value.trim()), tIn = $('snp-title-in').value.trim();
    const shown = tIn ? tIn.replace(/%title%/g, $('title').value.trim()).replace(/%sitename%/g, SEO_SITE).replace(/%sep%|%separator%/g, SEO_SEP).replace(/%[a-z_]+%/g, '').replace(/\s+/g, ' ').trim() : t;
    $('snp-title').textContent = shown; $('snp-url').textContent = $('snp-slug').value || 'post-url';
    const d = $('snp-desc-in').value.trim() || autoDesc(); $('snp-desc').textContent = d;
    $('snp-tcount').textContent = shown.length + ' / 60'; $('snp-dcount').textContent = $('snp-desc-in').value.trim().length + ' / 160';
    meter($('snp-tmeter'), shown.length, 30, 60); meter($('snp-dmeter'), $('snp-desc-in').value.trim().length, 120, 160);
  };
  $('rmx-edit-snippet').addEventListener('click', () => {
    $('snp-title-in').value = $('seo_title').value; $('snp-slug').value = $('slug').value; $('snp-desc-in').value = $('meta_description').value || autoDesc();
    snipSync(); snip.hidden = false; document.body.style.overflow = 'hidden'; setTimeout(() => $('snp-title-in').focus(), 30);
  });
  ['snp-title-in', 'snp-slug', 'snp-desc-in'].forEach(id => $(id).addEventListener('input', snipSync));
  const snipClose = () => { snip.hidden = true; document.body.style.overflow = ''; };
  snip.addEventListener('click', e => { if (e.target === snip || e.target.closest('[data-snip-close]')) snipClose(); });
  $('snp-apply').addEventListener('click', () => {
    $('seo_title').value = $('snp-title-in').value.trim();
    $('slug').value = slugify($('snp-slug').value); slugTouched = true;
    const d = $('snp-desc-in').value.trim(); $('meta_description').value = d; descTouched = d !== '' && d !== autoDesc();
    snipClose(); isDirty = true; updateAll();
  });
}
function updateSchemaName() { if (!HAS_SEO) return; const v = document.querySelector('[name=schema_type]:checked')?.value || <?= json_encode((string)seo_opt('schema_default'), JSON_HEX_TAG) ?>; $('rmx-schema-name').textContent = { NewsArticle: 'News Article', BlogPosting: 'Blog Post', Article: 'Article' }[v] || v; }
function updateSocial() {
  if (!HAS_SEO) return;
  const img = $('og_image').value || ($('feat-img') ? $('feat-img').getAttribute('src') : '');
  $('rmx-soc-img').innerHTML = img ? '<img src="' + escAttr(img) + '" alt="">' : '<span>No image — add a featured image</span>';
  $('rmx-soc-title').textContent = $('og_title').value.trim() || seoTitleOf($('title').value.trim());
  $('rmx-soc-desc').textContent = $('og_description').value.trim() || $('meta_description').value.trim() || autoDesc();
}
function meter(el, v, min, max) { if (!el) return; const s = el.querySelector('span'); s.style.width = Math.min(100, v / max * 100) + '%'; el.className = el.className.replace(/ ?(good|warn|bad)/g, '') + ' ' + (v === 0 ? '' : v < min ? 'warn' : v > max ? 'bad' : 'good'); }
let kwUsedCache = {};
function kwUsedCheck(kw) {
  if (!kw || kw in kwUsedCache) return;
  kwUsedCache[kw] = null;
  fetch('/admin/post-manager.php?kwcheck=' + encodeURIComponent(kw) + '&id=' + (<?= $is_edit ? (int)$post_id : 0 ?>), { credentials: 'same-origin' }).then(r => r.json()).then(d => { kwUsedCache[kw] = d.used || 0; updateAll(); }).catch(() => {});
}
const openGroups = {};
function updateAll() {
  const content = contentHtml(), text = strip(content), low = text.toLowerCase();
  const words = text ? text.split(' ').length : 0;
  $('be-status').innerHTML = words + ' words';
  if (!HAS_SEO) return;
  const title = $('title').value.trim(), slug = $('slug').value.trim();
  if (!descTouched) $('meta_description').value = autoDesc();
  const desc = $('meta_description').value.trim(), shownTitle = seoTitleOf(title);
  $('sp-title').textContent = shownTitle; $('sp-url').textContent = slug || 'post-url'; $('sp-desc').textContent = desc || 'Add content or a summary — the description is filled in from it.';
  updateSocial(); updateSchemaName();
  const kws = keywords(), kw = (kws[0] || '').toLowerCase();
  const esc = s => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const hrefs = [...content.matchAll(/<a\s[^>]*href="([^"]+)"[^>]*>/gi)].map(m => ({ href: m[1], tag: m[0] }));
  const ext = hrefs.filter(h => /^https?:\/\//.test(h.href) && !h.href.includes(HOST));
  const int = hrefs.filter(h => (h.href.startsWith('/') && !h.href.startsWith('//')) || h.href.includes(HOST));
  const paras = [...content.matchAll(/<p[\s>][\s\S]*?<\/p>/gi)].map(m => strip(m[0]).split(' ').filter(Boolean).length);
  const heads = (content.match(/<h[2-4][\s>]/gi) || []).length;
  const imgs = [...content.matchAll(/<img\s[^>]*>/gi)].map(m => m[0]);
  const hasMedia = imgs.length > 0 || /<iframe|<video/i.test(content);
  const kwSlug = kw.replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
  const count = kw ? low.split(kw).length - 1 : 0, dens = words && kw ? count * kw.split(' ').length * 100 / words : 0;
  const first10 = low.slice(0, Math.max(150, Math.round(low.length * .1)));
  const titleLow = shownTitle.toLowerCase(), tPos = kw ? titleLow.indexOf(kw) : -1;
  if (kw) kwUsedCheck(kw);
  const used = kw ? kwUsedCache[kw] : 0;
  const G = (name, items) => ({ name, items });
  const groups = kw ? [
    G('Basic SEO', [
      [titleLow.includes(kw), 'Hurray! You’re using Focus Keyword in the SEO Title.', 'Add Focus Keyword to the SEO title.'],
      [desc.toLowerCase().includes(kw), 'Focus Keyword used inside SEO Meta Description.', 'Add Focus Keyword to your SEO Meta Description.'],
      [!!kwSlug && slug.toLowerCase().includes(kwSlug), 'Focus Keyword used in the URL.', 'Use Focus Keyword in the URL.'],
      [first10.includes(kw), 'Focus Keyword appears in the first 10% of the content.', 'Use Focus Keyword at the beginning of your content.'],
      [low.includes(kw), 'Focus Keyword found in the content.', 'Focus Keyword doesn’t appear in the content.'],
      [words >= 600, 'Content is ' + words + ' words long. Good job!', 'Content is ' + words + ' words long. Consider using at least 600 words.'],
    ]),
    G('Additional', [
      [new RegExp('<h[2-6][^>]*>[^<]*' + esc(kw), 'i').test(content), 'Focus Keyword found in the subheading(s).', 'Use Focus Keyword in subheading(s) like H2, H3, H4, etc.'],
      [imgs.some(t => new RegExp('alt="[^"]*' + esc(kw), 'i').test(t)) || ($('feat-img') && ($('feat-img').alt || '').toLowerCase().includes(kw)), 'Focus Keyword found in image alt attribute(s).', 'Add an image with your Focus Keyword as alt text.'],
      [dens >= .5 && dens <= 2.5, 'Keyword Density is ' + dens.toFixed(2) + '%, the Focus Keyword appears ' + count + ' times.', 'Keyword Density is ' + dens.toFixed(2) + '%. Aim for around 1% Keyword Density.'],
      [slug.length > 0 && slug.length <= 75, 'URL is ' + slug.length + ' characters long. Kudos!', 'URL is ' + slug.length + ' characters long. Consider shortening it.'],
      [ext.length > 0, 'You are linking to external resources.', 'Link out to external resources.'],
      [ext.some(h => !/rel="[^"]*nofollow/i.test(h.tag)), 'At least one external link with DoFollow found in your content.', 'Add DoFollow links pointing to external resources.'],
      [int.length > 0, 'You are linking to other resources on your website which is great.', 'Add internal links in your content.'],
      [used === 0 || used === null, used === null ? 'Checking if this Focus Keyword was used before…' : 'You haven’t used this Focus Keyword before.', 'You have already used this Focus Keyword in ' + used + ' other post(s).'],
    ]),
    G('Title Readability', [
      [tPos >= 0 && tPos <= titleLow.length / 2, 'Focus Keyword used at the beginning of SEO title.', 'Use the Focus Keyword near the beginning of SEO title.'],
      [POWER_WORDS.some(w => titleLow.includes(w)), 'Your title contains a power word.', 'Your title doesn’t contain a power word. Add at least one.'],
      [/\d/.test(shownTitle), 'You are using a number in your SEO title.', 'Add a number to your title to improve CTR.'],
      [shownTitle.length >= 30 && shownTitle.length <= 65, 'SEO title is ' + shownTitle.length + ' characters long.', 'SEO title is ' + shownTitle.length + ' characters long — aim for 30–65.'],
    ]),
    G('Content Readability', [
      [heads >= 2, 'You seem to be using a Table of Contents (from your headings).', 'Use subheadings (H2/H3) — the post then gets a Table of Contents.'],
      [paras.length === 0 || Math.max(...paras) <= 120, 'You are using short paragraphs.', 'Add short and concise paragraphs for better readability and UX.'],
      [hasMedia || !!$('feat-img'), 'Your content contains images and/or video(s).', 'Add a few images and/or videos to make your content appealing.'],
    ]),
  ] : [];
  let ok = 0, total = 0;
  groups.forEach(g => g.items.forEach(i => { total++; if (i[0]) ok++; }));
  const score = kw ? Math.round(ok / total * 100) : 0;
  $('seo-score').textContent = score + ' / 100'; $('seo-score').className = 'rmx-score ' + (score >= 80 ? 'good' : score >= 50 ? 'warn' : 'bad');
  $('rmx-groups').innerHTML = kw ? groups.map(g => {
    const bad = g.items.filter(i => !i[0]).length, key = g.name;
    const open = openGroups[key] !== undefined ? openGroups[key] : g.name === 'Basic SEO';
    return '<div class="rmx-group' + (open ? ' open' : '') + '" data-g="' + key + '"><button type="button" class="rmx-group-h">' + key + ' <span class="rmx-badge ' + (bad ? 'bad' : 'good') + '">' + (bad ? (bad === 1 ? '1 Error' : bad + ' Errors') : '✓ All Good') + '</span><i class="fas fa-chevron-down"></i></button><ul>' +
      g.items.map(i => '<li class="' + (i[0] ? 'ok' : 'no') + '"><i class="fas ' + (i[0] ? 'fa-circle-check' : 'fa-circle-xmark') + '"></i><span>' + (i[0] ? i[1] : i[2]).replace(/</g, '&lt;') + '</span></li>').join('') + '</ul></div>';
  }).join('') : '<div class="rmx-warn"><i class="fas fa-circle-info"></i> Set a Focus Keyword for this content to see the SEO checks and score.</div>';
}
if (HAS_SEO) $('rmx-groups').addEventListener('click', e => { const h = e.target.closest('.rmx-group-h'); if (!h) return; const g = h.closest('.rmx-group'); g.classList.toggle('open'); openGroups[g.dataset.g] = g.classList.contains('open'); });
let seoT; const seoLater = () => { clearTimeout(seoT); seoT = setTimeout(updateAll, 250); };
['title', 'slug', 'summary', 'html-source'].forEach(id => $(id) && $(id).addEventListener('input', seoLater));
if ($('main_category_id')) $('main_category_id').addEventListener('change', seoLater);
updateAll();

/* ---------- submit, unsaved warning, local autosave ---------- */
let isDirty = false;
form.addEventListener('input', () => { isDirty = true; });
window.addEventListener('beforeunload', e => { if (isDirty) { e.preventDefault(); e.returnValue = ''; } });
form.addEventListener('submit', e => {
  const html = contentHtml();
  if (!strip(html) && !/<img|<table|<iframe/i.test(html)) { e.preventDefault(); alert('Please add some content.'); return; }
  $('content').value = html; isDirty = false;
  try { localStorage.removeItem(storageKey); } catch (err) {}
  const b = $('publish-btn'); b.disabled = true; b.querySelector('span').textContent = 'Saving…';
  $('save-draft-btn').disabled = true;
});

const storageKey = 'edumint_autosave_' + (<?= $is_edit ? (int)$post_id : '"new"' ?>);
const saved = localStorage.getItem(storageKey) || localStorage.getItem('careerdiksha_autosave_' + (<?= $is_edit ? (int)$post_id : '"new_post"' ?>));
if (saved) {
  const rb = $('restore_draft_btn'); rb.style.display = 'block';
  rb.addEventListener('click', () => {
    let d; try { d = JSON.parse(saved); } catch (e) { return; }
    GlobalPopup.show({ title: 'Restore Draft?', message: 'An unsaved draft was found. Restore it? This replaces the current fields.', buttons: [{ text: 'Cancel', class: 'gp-btn-cancel', value: false }, { text: 'Restore', class: 'gp-btn-ok', value: true }] }).then(ok => {
      if (!ok) return;
      if (d.title) $('title').value = d.title;
      if (d.slug) $('slug').value = d.slug;
      if (d.content) { if (mode === 'html') $('html-source').value = d.content; else if (editor) editor.setContent(d.content); }
      if (d.meta) $('meta_description').value = d.meta;
      if (d.summary) $('summary').value = d.summary;
      if (d.main_cat) $('main_category_id').value = d.main_cat;
      if (d.state) $('state_id').value = d.state;
      if (d.last_date) $('last_date').value = d.last_date;
      if (typeof d.tags === 'string') { $('tags').value = d.tags; if (window.tagsRender) window.tagsRender(); }
      if (Array.isArray(d.key_points)) kpRender(d.key_points);
      if (d.faq_json) { faqInput.value = d.faq_json; try { faqs = JSON.parse(d.faq_json); } catch (e) {} faqCount(); }
      rb.style.display = 'none'; sumCount(); updateAll();
    });
  });
}
setInterval(() => {
  if (!isDirty) return;
  try {
    localStorage.setItem(storageKey, JSON.stringify({
      title: $('title').value, slug: $('slug').value, content: contentHtml(), meta: $('meta_description').value, summary: $('summary').value,
      main_cat: $('main_category_id').value, state: $('state_id').value, last_date: $('last_date').value, tags: $('tags').value,
      key_points: [...document.querySelectorAll('[name="key_points[]"]')].map(i => i.value), faq_json: faqInput.value, timestamp: Date.now()
    }));
    const keys = []; for (let i = 0; i < localStorage.length; i++) { const k = localStorage.key(i); if (k.startsWith('edumint_autosave_')) { try { keys.push([k, JSON.parse(localStorage.getItem(k)).timestamp || 0]); } catch (e) {} } }
    keys.sort((a, b) => a[1] - b[1]); while (keys.length > 5) localStorage.removeItem(keys.shift()[0]);
  } catch (e) {}
  fetch('/api/e6n8f5j3k8.php').catch(() => {});
}, 15000);

/* ---------- Add Media (like WordPress) ---------- */
$('pm2-add-media').addEventListener('click', () => {
  if (!editor || mode === 'html') { alert('Switch to the Visual tab to insert media.'); return; }
  openMlb('editor', (path, alt) => { editor.focus(); editor.insertContent('<img src="' + escAttr(path) + '" alt="' + escAttr(alt || $('title').value.trim()) + '">'); isDirty = true; seoLater(); });
});

/* ---------- content area and sidebar scroll separately (desktop) ---------- */
function fitLayout() {
  const lay = document.querySelector('.editor-layout'); if (!lay) return;
  const on = window.innerWidth >= 1024;
  document.documentElement.classList.toggle('pm2-split', on);
  if (!on) { lay.style.height = ''; return; }
  window.scrollTo(0, 0);
  lay.style.height = 'auto';
  const top = lay.getBoundingClientRect().top;
  lay.style.height = Math.max(380, window.innerHeight - top - 12) + 'px';
}
fitLayout();
window.addEventListener('resize', fitLayout);
window.addEventListener('load', () => { fitLayout(); setTimeout(fitLayout, 400); });
});
</script>
</body>
</html>