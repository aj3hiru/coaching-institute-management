<?php
/**
 * Online store — recorded video courses and PDF notes, sold with Razorpay.
 *
 *  sh_products   one course (type=course: sections + video lessons) or notes pack (type=notes: PDF files)
 *  sh_sections   chapters of a course            sh_lessons  videos (own file or a video link), free preview flag
 *  sh_files      PDFs of a notes pack (and PDFs attached to a course), free preview flag
 *  sh_users      buyers: name, mobile, email, password (linked to an institute student with the same mobile)
 *  sh_orders     one purchase attempt (Razorpay order → paid)   sh_access  who may open what (bought / given by admin)
 *  sh_progress   lessons a buyer has finished
 *
 * Videos and PDFs live outside the public folder (sh_media_dir) and are only sent through /store/media with a
 * short-lived signed link, after checking the buyer's access. Tables are created on first use (additive only).
 */
require_once __DIR__ . '/settings.php';

const SH_SCHEMA = 9;

function sh_db(): PDO
{
    $db = edu_db() ?? ($GLOBALS['pdo'] ?? null); // the site's connection (works when a page runs inside a function too)
    if (!$db instanceof PDO) throw new RuntimeException('No database connection');
    sh_tables($db);
    return $db;
}

function sh_tables(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    if ((int)es('shop', 'schema', 0) >= SH_SCHEMA) return;
    $t = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $pdo->exec("CREATE TABLE IF NOT EXISTS sh_products (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, type VARCHAR(10) NOT NULL DEFAULT 'course', title VARCHAR(200) NOT NULL,
        slug VARCHAR(190) NOT NULL, subtitle VARCHAR(300) NULL, description MEDIUMTEXT NULL, thumb VARCHAR(300) NULL,
        price DECIMAL(10,2) NOT NULL DEFAULT 0, mrp DECIMAL(10,2) NULL, validity_days INT UNSIGNED NOT NULL DEFAULT 0,
        status VARCHAR(12) NOT NULL DEFAULT 'draft', class_id INT UNSIGNED NULL, sort INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NULL DEFAULT NULL,
        UNIQUE KEY uq_slug (slug), KEY idx_status (status, type))$t");
    $pdo->exec("CREATE TABLE IF NOT EXISTS sh_sections (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, product_id INT UNSIGNED NOT NULL, title VARCHAR(200) NOT NULL, sort INT NOT NULL DEFAULT 0,
        KEY idx_p (product_id))$t");
    $pdo->exec("CREATE TABLE IF NOT EXISTS sh_lessons (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, product_id INT UNSIGNED NOT NULL, section_id INT UNSIGNED NULL,
        title VARCHAR(200) NOT NULL, video_file VARCHAR(300) NULL, video_url VARCHAR(500) NULL, size BIGINT UNSIGNED NOT NULL DEFAULT 0,
        duration VARCHAR(20) NULL, notes TEXT NULL, free TINYINT(1) NOT NULL DEFAULT 0, sort INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_p (product_id, section_id))$t");
    $pdo->exec("CREATE TABLE IF NOT EXISTS sh_files (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, product_id INT UNSIGNED NOT NULL, title VARCHAR(200) NOT NULL,
        file VARCHAR(300) NOT NULL, size BIGINT UNSIGNED NOT NULL DEFAULT 0, pages INT UNSIGNED NULL, free TINYINT(1) NOT NULL DEFAULT 0,
        sort INT NOT NULL DEFAULT 0, created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_p (product_id))$t");
    $pdo->exec("CREATE TABLE IF NOT EXISTS sh_users (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, name VARCHAR(150) NOT NULL, mobile VARCHAR(15) NOT NULL, email VARCHAR(190) NOT NULL,
        pass VARCHAR(255) NOT NULL, student_id INT UNSIGNED NULL, status VARCHAR(12) NOT NULL DEFAULT 'active',
        reset_hash CHAR(64) NULL, reset_until DATETIME NULL, last_login DATETIME NULL,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP, created_by INT UNSIGNED NULL,
        UNIQUE KEY uq_mobile (mobile), UNIQUE KEY uq_email (email), KEY idx_student (student_id))$t");
    $pdo->exec("CREATE TABLE IF NOT EXISTS sh_orders (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, order_no VARCHAR(30) NULL, user_id INT UNSIGNED NOT NULL, product_id INT UNSIGNED NOT NULL,
        title VARCHAR(200) NOT NULL, amount DECIMAL(10,2) NOT NULL, status VARCHAR(12) NOT NULL DEFAULT 'created',
        rzp_order VARCHAR(60) NULL, rzp_payment VARCHAR(60) NULL, method VARCHAR(30) NULL, error VARCHAR(300) NULL,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP, paid_at DATETIME NULL,
        UNIQUE KEY uq_rzp (rzp_order), KEY idx_user (user_id), KEY idx_status (status, paid_at))$t");
    $pdo->exec("CREATE TABLE IF NOT EXISTS sh_access (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, product_id INT UNSIGNED NOT NULL,
        source VARCHAR(12) NOT NULL DEFAULT 'purchase', order_id INT UNSIGNED NULL, granted_by INT UNSIGNED NULL, note VARCHAR(200) NULL,
        granted_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP, expires_at DATETIME NULL, revoked TINYINT(1) NOT NULL DEFAULT 0,
        UNIQUE KEY uq_up (user_id, product_id))$t");
    $pdo->exec("CREATE TABLE IF NOT EXISTS sh_progress (
        user_id INT UNSIGNED NOT NULL, lesson_id INT UNSIGNED NOT NULL, done TINYINT(1) NOT NULL DEFAULT 1, at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, lesson_id))$t");
    // v4: extra qualities of an uploaded video (720p / 480p / 360p files) and the original's height
    $col = fn($t, $c) => (bool)$pdo->query("SHOW COLUMNS FROM `$t` LIKE " . $pdo->quote($c))->fetch();
    if (!$col('sh_lessons', 'variants')) $pdo->exec("ALTER TABLE sh_lessons ADD COLUMN variants TEXT NULL AFTER size");
    if (!$col('sh_lessons', 'height')) $pdo->exec("ALTER TABLE sh_lessons ADD COLUMN height SMALLINT UNSIGNED NULL AFTER variants");
    // v5: reviews — one per student per course, only by students who have the course
    $pdo->exec("CREATE TABLE IF NOT EXISTS sh_reviews (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, product_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL,
        rating TINYINT UNSIGNED NOT NULL, body TEXT NULL, status VARCHAR(10) NOT NULL DEFAULT 'published',
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NULL,
        UNIQUE KEY uq_pu (product_id, user_id), KEY idx_p (product_id, status))$t");
    // v6: "Report us" messages from logged-in students (with an optional image / PDF)
    $pdo->exec("CREATE TABLE IF NOT EXISTS sh_reports (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, name VARCHAR(150) NOT NULL, topic VARCHAR(40) NOT NULL DEFAULT 'other',
        message TEXT NOT NULL, file VARCHAR(300) NULL, file_name VARCHAR(200) NULL, size INT UNSIGNED NOT NULL DEFAULT 0,
        status VARCHAR(10) NOT NULL DEFAULT 'new', note VARCHAR(500) NULL, created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_status (status, id), KEY idx_user (user_id))$t");
    // v7: teacher, course FAQ and "what you'll get"; units inside chapters; lecture number, PDF lectures, lecture notes,
    // VVI questions, quiz; students' doubts per lecture
    $add = function (string $tb, string $c, string $def) use ($pdo, $col) { if (!$col($tb, $c)) $pdo->exec("ALTER TABLE `$tb` ADD COLUMN $c $def"); };
    $add('sh_products', 'teacher_id', 'INT UNSIGNED NULL');
    $add('sh_products', 'faq', 'TEXT NULL');
    $add('sh_products', 'includes', 'TEXT NULL');
    $add('sh_lessons', 'unit_id', 'INT UNSIGNED NULL');
    $add('sh_lessons', 'lecture_no', 'INT UNSIGNED NULL');
    $add('sh_lessons', 'kind', "VARCHAR(8) NOT NULL DEFAULT 'video'");
    $add('sh_lessons', 'pdf_file', 'VARCHAR(300) NULL');
    $add('sh_lessons', 'pdf_pages', 'INT UNSIGNED NULL');
    $add('sh_lessons', 'vvi', 'MEDIUMTEXT NULL');
    $add('sh_lessons', 'quiz', 'MEDIUMTEXT NULL');
    $add('sh_lessons', 'doubts_on', 'TINYINT(1) NOT NULL DEFAULT 1');
    $add('sh_files', 'lesson_id', 'INT UNSIGNED NULL');
    $pdo->exec("CREATE TABLE IF NOT EXISTS sh_units (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, product_id INT UNSIGNED NOT NULL, section_id INT UNSIGNED NOT NULL, title VARCHAR(200) NOT NULL, sort INT NOT NULL DEFAULT 0,
        KEY idx_ps (product_id, section_id))$t");
    $pdo->exec("CREATE TABLE IF NOT EXISTS sh_doubts (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, lesson_id INT UNSIGNED NOT NULL, product_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL,
        question TEXT NOT NULL, answer TEXT NULL, answered_by INT UNSIGNED NULL, status VARCHAR(10) NOT NULL DEFAULT 'open',
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP, answered_at DATETIME NULL, KEY idx_l (lesson_id, status), KEY idx_p (product_id, status))$t");
    // v8: the student's own profile (address, pin code, photo)
    $add('sh_users', 'address', 'VARCHAR(300) NULL');
    $add('sh_users', 'pincode', 'VARCHAR(10) NULL');
    $add('sh_users', 'avatar', 'VARCHAR(150) NULL');
    // v9: course categories (made from the classes), batch start date, homepage slider and free YouTube courses
    $pdo->exec("CREATE TABLE IF NOT EXISTS sh_categories (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL, slug VARCHAR(140) NOT NULL, icon VARCHAR(190) NULL,
        description TEXT NULL, seo_title VARCHAR(200) NULL, seo_desc VARCHAR(320) NULL, sort INT NOT NULL DEFAULT 0, status VARCHAR(10) NOT NULL DEFAULT 'active',
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_slug (slug))$t");
    $add('sh_products', 'category_id', 'INT UNSIGNED NULL');
    $add('sh_products', 'starts_at', 'DATE NULL');
    $pdo->exec("CREATE TABLE IF NOT EXISTS hm_slides (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, image VARCHAR(190) NOT NULL, mobile_image VARCHAR(190) NULL, link VARCHAR(500) NULL, title VARCHAR(200) NULL,
        new_tab TINYINT(1) NOT NULL DEFAULT 0, sort INT NOT NULL DEFAULT 0, active TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP)$t");
    $pdo->exec("CREATE TABLE IF NOT EXISTS hm_free (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, url VARCHAR(500) NOT NULL, list_id VARCHAR(64) NULL, video_id VARCHAR(20) NULL, title VARCHAR(250) NOT NULL,
        thumb VARCHAR(500) NULL, channel VARCHAR(150) NULL, videos INT UNSIGNED NULL, category_id INT UNSIGNED NULL, sort INT NOT NULL DEFAULT 0, active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP)$t");
    // first time: one category for each class, and courses keep their class as their category
    if (!(int)$pdo->query("SELECT COUNT(*) FROM sh_categories")->fetchColumn()) {
        try { $cls = $pdo->query("SELECT id, name FROM ci_classes ORDER BY sort, name")->fetchAll(PDO::FETCH_KEY_PAIR); } catch (Throwable) { $cls = []; }
        $i = 0;
        foreach ($cls as $cid => $cn) {
            $name = preg_match('/^(\d{1,2})(st|nd|rd|th)$/i', trim($cn), $m) ? 'Class ' . $m[1] : trim($cn);
            $slug = sh_slug($name) ?: 'category-' . $cid;
            if ((int)$pdo->query("SELECT COUNT(*) FROM sh_categories WHERE slug = " . $pdo->quote($slug))->fetchColumn()) continue;
            $pdo->prepare("INSERT INTO sh_categories (name, slug, sort) VALUES (?, ?, ?)")->execute([$name, $slug, ++$i]);
            $pdo->prepare("UPDATE sh_products SET category_id = ? WHERE class_id = ? AND category_id IS NULL")->execute([(int)$pdo->lastInsertId(), $cid]);
        }
    }
    edu_settings_save('shop', ['schema' => SH_SCHEMA]);
}

/* ---------- small helpers ---------- */

function sh_e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function sh_money($n): string { $n = (float)$n; return '₹' . number_format($n, fmod($n, 1) ? 2 : 0); }
function sh_on(): bool { return es('shop', 'enabled', '1') === '1'; }
/* Clean web addresses: /courses/slug, /notes/slug, /learn/slug, /category/slug, /free-courses/ID-name (old /store/… links redirect). */
function sh_url(array $p): string { return (($p['type'] ?? 'course') === 'notes' ? '/notes/' : '/courses/') . rawurlencode($p['slug']); }
/** The study page of a course / notes pack: sh_learn_url('slug', 'l=5', true) → /learn/slug?l=5&preview=1 */
function sh_learn_url(string $slug, string $q = '', bool $preview = false): string
{
    $q = trim($q . ($preview ? ($q !== '' ? '&' : '') . 'preview=1' : ''), '&');
    return '/learn/' . rawurlencode($slug) . ($q !== '' ? '?' . $q : '');
}
/** Are the .htaccess rewrite rules for the clean addresses live? (Set the first time a clean address arrives by rewrite,
    not through the 404 fallback.) Until then category links use /store/?c=… because the old /category rule answers 410. */
function sh_rw_live(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    $ok = (string)es('shop', 'rw_ok', '') === '1';
    if (!$ok && !defined('SH_VIA_404') && preg_match('#^/(courses|notes|learn|free-courses|category)/#', (string)($_SERVER['REQUEST_URI'] ?? ''))) {
        edu_settings_save('shop', ['rw_ok' => '1']); $ok = true;
        foreach (glob(ROOT_PATH . '/cache/*.html') ?: [] as $f) @unlink($f); // cached pages pick up the new category links
    }
    return $ok;
}
function sh_cat_url(string $slug): string { return sh_rw_live() ? '/category/' . rawurlencode($slug) : '/store/?c=' . rawurlencode($slug); }
function sh_free_url(array $f): string { return '/free-courses/' . (int)$f['id'] . (($s = sh_slug((string)($f['title'] ?? ''))) !== '' ? '-' . $s : ''); }
/** An old /store/… address was opened: send the visitor (and search engines) to the clean one. */
function sh_redirect_clean(string $to): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return;
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
    if (!preg_match('#^/store/#', $uri)) return;
    header('Location: ' . $to, true, 301); exit;
}
function sh_type_label(string $t): string { return $t === 'notes' ? 'PDF Notes' : 'Video Course'; }

/** Where videos and PDFs are kept — outside the public folder, so nobody can open them by address. */
function sh_media_dir(): string
{
    $d = trim((string)es('shop', 'media_dir', ''));
    if ($d === '') $d = dirname(ROOT_PATH) . '/dk-private/shop';
    if (!is_dir($d)) @mkdir($d, 0750, true);
    return rtrim($d, '/');
}
function sh_media_path(string $rel): string
{
    $rel = ltrim(str_replace(['..', "\0"], '', $rel), '/');
    return sh_media_dir() . '/' . $rel;
}

function sh_slug(string $s): string
{
    $s = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $s), '-'));
    return $s !== '' ? substr($s, 0, 150) : 'item';
}
function sh_unique_slug(PDO $pdo, string $title, int $exceptId = 0): string
{
    $base = sh_slug($title); $s = $base; $i = 2;
    $q = $pdo->prepare("SELECT COUNT(*) FROM sh_products WHERE slug = ? AND id <> ?");
    while (true) { $q->execute([$s, $exceptId]); if (!(int)$q->fetchColumn()) return $s; $s = $base . '-' . $i++; }
}

function sh_clean_mobile($m): string
{
    $m = preg_replace('/\D/', '', (string)$m);
    if (strlen($m) === 12 && str_starts_with($m, '91')) $m = substr($m, 2);
    if (strlen($m) === 11 && $m[0] === '0') $m = substr($m, 1);
    return $m;
}

/** CSRF token for the public (buyer) forms. */
function sh_csrf(): string { return $_SESSION['sh_csrf'] ??= bin2hex(random_bytes(24)); }
function sh_csrf_field(): string { return '<input type="hidden" name="_t" value="' . sh_csrf() . '">'; }
function sh_csrf_ok(?string $t = null): bool
{
    $t ??= (string)($_POST['_t'] ?? ($_SERVER['HTTP_X_CSRF'] ?? ''));
    return !empty($_SESSION['sh_csrf']) && is_string($t) && hash_equals($_SESSION['sh_csrf'], $t);
}

/* ---------- buyers (accounts) ---------- */

function sh_user(): ?array
{
    static $u = false;
    if ($u !== false) return $u;
    $id = (int)($_SESSION['sh_uid'] ?? 0);
    if (!$id) return $u = null;
    $q = sh_db()->prepare("SELECT * FROM sh_users WHERE id = ? AND status = 'active'");
    $q->execute([$id]);
    $u = $q->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$u) { unset($_SESSION['sh_uid']); if (!headers_sent()) sh_mark_cookie(null); }
    return $u;
}

/** A small cookie (no secrets) so cached pages can show "My Library" instead of "Log in" in this browser. */
function sh_mark_cookie(?string $first, string $avatar = ''): void
{
    $o = ['path' => '/', 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly' => false];
    if ($first === null) { foreach (['sh_in', 'sh_n', 'sh_av'] as $c) setcookie($c, '', ['expires' => time() - 3600] + $o); return; }
    setcookie('sh_in', '1', ['expires' => time() + 30 * 86400] + $o);
    setcookie('sh_n', rawurlencode(mb_substr($first, 0, 30)), ['expires' => time() + 30 * 86400] + $o);
    setcookie('sh_av', $avatar, ['expires' => $avatar !== '' ? time() + 30 * 86400 : time() - 3600] + $o); // the photo in the student bar
}
/** Public URL of a student's photo ('' = none). */
function sh_avatar_url(array $u): string
{
    $a = (string)($u['avatar'] ?? '');
    return $a !== '' && preg_match('/^[a-z0-9-]+\.(jpg|png|webp)$/', $a) ? '/uploads/avatars/' . $a : '';
}
/** Saves an uploaded photo as a 320×320 JPG (centre crop). Returns [file name, error]. */
function sh_avatar_save(array $file, int $uid): array
{
    if (($file['error'] ?? 4) !== UPLOAD_ERR_OK) return ['', 'The photo could not be uploaded. Please try again.'];
    if ($file['size'] > 5 * 1024 * 1024) return ['', 'Please choose a photo smaller than 5 MB.'];
    $info = @getimagesize($file['tmp_name']);
    $read = [IMAGETYPE_JPEG => 'imagecreatefromjpeg', IMAGETYPE_PNG => 'imagecreatefrompng', IMAGETYPE_WEBP => 'imagecreatefromwebp'][$info[2] ?? 0] ?? '';
    if (!$info || !$read) return ['', 'The photo must be a JPG, PNG or WebP image.'];
    $dir = rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/') . '/uploads/avatars';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    if (!function_exists($read) || !function_exists('imagejpeg')) { // no image library: keep the checked original (max 5 MB)
        $name = 'u' . $uid . '-' . bin2hex(random_bytes(5)) . '.' . [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'][$info[2]];
        return @move_uploaded_file($file['tmp_name'], "$dir/$name") ? [$name, ''] : ['', 'The photo could not be saved.'];
    }
    $src = @$read($file['tmp_name']); if (!$src) return ['', 'This photo could not be read.'];
    $w = imagesx($src); $h = imagesy($src); $side = min($w, $h);
    $dst = imagecreatetruecolor(320, 320); imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, (int)(($w - $side) / 2), (int)(($h - $side) / 2), 320, 320, $side, $side);
    $name = 'u' . $uid . '-' . bin2hex(random_bytes(5)) . '.jpg';
    $ok = imagejpeg($dst, "$dir/$name", 86);
    imagedestroy($src); imagedestroy($dst);
    return $ok ? [$name, ''] : ['', 'The photo could not be saved.'];
}
function sh_login_user(array $u): void
{
    session_regenerate_id(true);
    $_SESSION['sh_uid'] = (int)$u['id'];
    sh_mark_cookie(explode(' ', trim((string)$u['name']))[0], sh_avatar_url($u));
    sh_db()->prepare("UPDATE sh_users SET last_login = NOW() WHERE id = ?")->execute([$u['id']]);
}

/** The institute student with this mobile (main or alternate number), to link the account. */
function sh_find_student(PDO $pdo, string $mobile): ?int
{
    if ($mobile === '') return null;
    try {
        $q = $pdo->prepare("SELECT id FROM ci_students WHERE mobile = ? OR alt_mobile = ? ORDER BY mobile = ? DESC, status = 'active' DESC, id DESC LIMIT 1");
        $q->execute([$mobile, $mobile, $mobile]);
        return (int)$q->fetchColumn() ?: null;
    } catch (Throwable) { return null; } // no institute tables yet
}

/** Creates an account. Returns the new id, or throws RuntimeException with a message for the visitor. */
function sh_register(PDO $pdo, string $name, string $mobile, string $email, string $pass, ?int $by = null): int
{
    $name = trim(preg_replace('/\s+/', ' ', $name));
    $mobile = sh_clean_mobile($mobile);
    $email = strtolower(trim($email));
    if (mb_strlen($name) < 2) throw new RuntimeException('Please enter your name.');
    if (!preg_match('/^[6-9]\d{9}$/', $mobile)) throw new RuntimeException('Please enter a valid 10-digit mobile number.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Please enter a valid email address.');
    if (strlen($pass) < 4) throw new RuntimeException('The password must have at least 4 characters.');
    $q = $pdo->prepare("SELECT mobile, email FROM sh_users WHERE mobile = ? OR email = ?");
    $q->execute([$mobile, $email]);
    foreach ($q as $r) {
        if ($r['mobile'] === $mobile) throw new RuntimeException('An account with this mobile number already exists. Please log in.');
        if ($r['email'] === $email) throw new RuntimeException('An account with this email already exists. Please log in.');
    }
    $pdo->prepare("INSERT INTO sh_users (name, mobile, email, pass, student_id, created_by) VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([mb_substr($name, 0, 150), $mobile, mb_substr($email, 0, 190), password_hash($pass, PASSWORD_DEFAULT), sh_find_student($pdo, $mobile), $by]);
    return (int)$pdo->lastInsertId();
}

/** Login with mobile number or email. Returns the user or null. Slows down repeated wrong tries. */
function sh_check_login(PDO $pdo, string $id, string $pass): ?array
{
    $key = 'sh_fail_' . md5(($_SERVER['REMOTE_ADDR'] ?? '') . '|' . strtolower(trim($id)));
    $fails = (int)($_SESSION[$key] ?? 0);
    if ($fails >= 5) usleep(min(5, $fails - 4) * 600000); // a little slower after 5 wrong tries
    $id = trim($id);
    $mob = sh_clean_mobile($id);
    $q = $pdo->prepare("SELECT * FROM sh_users WHERE (email = ? OR mobile = ?) LIMIT 1");
    $q->execute([strtolower($id), $mob !== '' ? $mob : '-']);
    $u = $q->fetch(PDO::FETCH_ASSOC);
    if (!$u || !password_verify($pass, $u['pass']) || $u['status'] !== 'active') { $_SESSION[$key] = $fails + 1; return null; }
    unset($_SESSION[$key]);
    if (password_needs_rehash($u['pass'], PASSWORD_DEFAULT)) $pdo->prepare("UPDATE sh_users SET pass = ? WHERE id = ?")->execute([password_hash($pass, PASSWORD_DEFAULT), $u['id']]);
    if (!$u['student_id'] && ($sid = sh_find_student($pdo, $u['mobile']))) { $pdo->prepare("UPDATE sh_users SET student_id = ? WHERE id = ?")->execute([$sid, $u['id']]); $u['student_id'] = $sid; }
    return $u;
}

/** Sends a password reset link (valid 1 hour). Always "succeeds" for the visitor, so emails can't be guessed. */
function sh_send_reset(PDO $pdo, string $email): bool
{
    $q = $pdo->prepare("SELECT id, name, email FROM sh_users WHERE email = ? AND status = 'active'");
    $q->execute([strtolower(trim($email))]);
    $u = $q->fetch(PDO::FETCH_ASSOC);
    if (!$u) return true;
    $tok = bin2hex(random_bytes(24));
    $pdo->prepare("UPDATE sh_users SET reset_hash = ?, reset_until = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id = ?")->execute([hash('sha256', $tok), $u['id']]);
    require_once __DIR__ . '/mailer.php';
    $link = SITE_URL . '/account/reset?t=' . $tok;
    $site = edu_site_name();
    $html = '<div style="font-family:Arial,sans-serif;font-size:15px;color:#222;line-height:1.6"><p>Hello ' . sh_e($u['name']) . ',</p>'
        . '<p>We got a request to reset the password of your ' . sh_e($site) . ' account.</p>'
        . '<p><a href="' . sh_e($link) . '" style="display:inline-block;background:#4a3369;color:#fff;padding:10px 18px;text-decoration:none">Set a new password</a></p>'
        . '<p style="color:#666;font-size:13px">This link works for 1 hour. If you did not ask for it, you can ignore this email.</p></div>';
    return edu_send_mail($u['email'], 'Reset your password — ' . $site, $html);
}

/* ---------- products and access ---------- */

function sh_product(PDO $pdo, $idOrSlug, bool $publishedOnly = true): ?array
{
    $col = is_int($idOrSlug) ? 'id' : 'slug';
    $q = $pdo->prepare("SELECT * FROM sh_products WHERE $col = ?" . ($publishedOnly ? " AND status = 'published'" : ''));
    $q->execute([$idOrSlug]);
    return $q->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Does this buyer currently have the product (bought or given, not revoked, not expired)? */
function sh_has_access(PDO $pdo, ?int $uid, int $pid): bool
{
    if (!$uid) return false;
    $q = $pdo->prepare("SELECT 1 FROM sh_access WHERE user_id = ? AND product_id = ? AND revoked = 0 AND (expires_at IS NULL OR expires_at > NOW())");
    $q->execute([$uid, $pid]);
    return (bool)$q->fetchColumn();
}

/** Gives (or renews) access. validity_days of the product sets the end date; 0 = lifetime. */
function sh_grant(PDO $pdo, int $uid, int $pid, string $source, ?int $orderId = null, ?int $by = null, string $note = '', ?int $days = null): void
{
    $p = sh_product($pdo, $pid, false);
    if (!$p) throw new RuntimeException('Product not found.');
    $days ??= (int)$p['validity_days'];
    $exp = $days > 0 ? date('Y-m-d H:i:s', time() + $days * 86400) : null;
    $pdo->prepare("INSERT INTO sh_access (user_id, product_id, source, order_id, granted_by, note, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE source = VALUES(source), order_id = COALESCE(VALUES(order_id), order_id), granted_by = VALUES(granted_by),
        note = VALUES(note), granted_at = NOW(), expires_at = VALUES(expires_at), revoked = 0")
        ->execute([$uid, $pid, $source, $orderId, $by, mb_substr($note, 0, 200), $exp]);
}

/** Lessons of a course grouped by section: [['id'=>…, 'title'=>…, 'lessons'=>[…]], …] (lessons without a section first). */
function sh_curriculum(PDO $pdo, int $pid): array
{
    $secs = $pdo->prepare("SELECT id, title FROM sh_sections WHERE product_id = ? ORDER BY sort, id");
    $secs->execute([$pid]);
    $out = [0 => ['id' => 0, 'title' => '', 'lessons' => []]];
    foreach ($secs as $s) $out[(int)$s['id']] = ['id' => (int)$s['id'], 'title' => $s['title'], 'lessons' => []];
    $units = [];
    try { $uq = $pdo->prepare("SELECT id, title FROM sh_units WHERE product_id = ?"); $uq->execute([$pid]); $units = $uq->fetchAll(PDO::FETCH_KEY_PAIR); } catch (Throwable) {}
    $ls = $pdo->prepare("SELECT * FROM sh_lessons WHERE product_id = ? ORDER BY sort, id");
    $ls->execute([$pid]);
    foreach ($ls as $l) { $l['unit_title'] = $units[(int)($l['unit_id'] ?? 0)] ?? ''; $k = isset($out[(int)$l['section_id']]) ? (int)$l['section_id'] : 0; $out[$k]['lessons'][] = $l; }
    if (!$out[0]['lessons']) unset($out[0]);
    return array_values($out);
}
function sh_files(PDO $pdo, int $pid): array
{
    $q = $pdo->prepare("SELECT * FROM sh_files WHERE product_id = ? AND lesson_id IS NULL ORDER BY sort, id"); // lecture notes belong to their lecture (sh_lesson_notes)
    $q->execute([$pid]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

/* ---------- signed media links ---------- */

function sh_secret(): string
{
    $s = (string)es('shop', 'secret', '');
    if (strlen($s) < 32) { $s = bin2hex(random_bytes(32)); edu_settings_save('shop', ['secret' => $s]); }
    return $s;
}
/** A link to a lesson video or a PDF, valid for $ttl seconds, for this buyer only. */
function sh_media_link(string $kind, int $id, int $uid, int $ttl = 14400, string $q = ''): string
{
    $exp = time() + $ttl;
    $sig = substr(hash_hmac('sha256', "$kind|$id|$uid|$exp|$q", sh_secret()), 0, 32);
    return '/store/media?k=' . $kind . '&i=' . $id . '&u=' . $uid . '&e=' . $exp . ($q !== '' ? '&q=' . $q : '') . '&s=' . $sig;
}
function sh_media_check(string $kind, int $id, int $uid, int $exp, string $sig, string $q = ''): bool
{
    if ($exp < time()) return false;
    return hash_equals(substr(hash_hmac('sha256', "$kind|$id|$uid|$exp|$q", sh_secret()), 0, 32), $sig);
}
/** Qualities of an uploaded lesson video: ['720' => 'path', …] (the original is not in the list). */
function sh_variants(array $l): array
{
    $v = json_decode((string)($l['variants'] ?? ''), true);
    if (!is_array($v)) return [];
    $v = array_filter($v, fn($p, $k) => in_array((string)$k, ['1080', '720', '480', '360', '240'], true) && is_string($p) && $p !== '', ARRAY_FILTER_USE_BOTH);
    krsort($v, SORT_NUMERIC);
    return $v;
}

/** Sends a file with HTTP range support (video seeking). */
function sh_send_file(string $path, string $mime, string $downloadName = '', bool $inline = true): void
{
    $size = filesize($path);
    $start = 0; $end = $size - 1;
    header('Content-Type: ' . $mime);
    header('Accept-Ranges: bytes');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . preg_replace('/[^A-Za-z0-9._ -]/', '', $downloadName ?: basename($path)) . '"');
    if (!empty($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
        if ($m[1] === '' && $m[2] !== '') { $start = max(0, $size - (int)$m[2]); }
        else { $start = (int)$m[1]; if ($m[2] !== '') $end = min((int)$m[2], $size - 1); }
        if ($start > $end || $start >= $size) { http_response_code(416); header("Content-Range: bytes */$size"); exit; }
        http_response_code(206);
        header("Content-Range: bytes $start-$end/$size");
    }
    header('Content-Length: ' . ($end - $start + 1));
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'HEAD') exit;
    @set_time_limit(0);
    while (ob_get_level()) ob_end_clean();
    $fh = fopen($path, 'rb');
    fseek($fh, $start);
    $left = $end - $start + 1;
    while ($left > 0 && !feof($fh) && !connection_aborted()) {
        $chunk = fread($fh, (int)min(262144, $left));
        if ($chunk === false) break;
        echo $chunk; flush();
        $left -= strlen($chunk);
    }
    fclose($fh);
    exit;
}

/* ---------- Razorpay ---------- */

function sh_rzp_keys(): array
{
    return ['id' => trim((string)es('shop', 'rzp_key_id', '')), 'secret' => trim((string)es('shop', 'rzp_key_secret', '')), 'hook' => trim((string)es('shop', 'rzp_webhook_secret', ''))];
}
function sh_rzp_ready(): bool { $k = sh_rzp_keys(); return $k['id'] !== '' && $k['secret'] !== ''; }
/** Demo payment mode (for showing the store before launch): a clearly marked practice checkout, no real money, no Razorpay. */
function sh_pay_demo(): bool { return (string)es('shop', 'pay_demo', '0') === '1'; }

/** Creates a Razorpay order. Returns its id or throws. Amount in rupees. */
function sh_rzp_order(float $amount, string $receipt, array $notes = []): string
{
    $k = sh_rzp_keys();
    if ($k['id'] === '' || $k['secret'] === '') throw new RuntimeException('Online payment is not set up yet. Please contact us.');
    $ch = curl_init('https://api.razorpay.com/v1/orders');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_USERPWD => $k['id'] . ':' . $k['secret'],
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['amount' => (int)round($amount * 100), 'currency' => 'INR', 'receipt' => substr($receipt, 0, 40), 'notes' => $notes])]);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $d = json_decode((string)$res, true);
    if ($code !== 200 || empty($d['id'])) {
        error_log('razorpay order: HTTP ' . $code . ' ' . ($err ?: substr((string)$res, 0, 300)));
        throw new RuntimeException('Could not start the payment. Please try again in a minute.');
    }
    return (string)$d['id'];
}

/** Marks an order paid and gives access (safe to call twice — from the browser and from the webhook). */
function sh_order_paid(PDO $pdo, int $orderId, string $paymentId, string $method = ''): bool
{
    $pdo->beginTransaction();
    try {
        $q = $pdo->prepare("SELECT * FROM sh_orders WHERE id = ? FOR UPDATE");
        $q->execute([$orderId]);
        $o = $q->fetch(PDO::FETCH_ASSOC);
        if (!$o) { $pdo->rollBack(); return false; }
        if ($o['status'] !== 'paid') {
            $pdo->prepare("UPDATE sh_orders SET status = 'paid', rzp_payment = ?, method = ?, paid_at = NOW(), error = NULL, order_no = COALESCE(order_no, ?) WHERE id = ?")
                ->execute([$paymentId, mb_substr($method, 0, 30) ?: null, 'DKO-' . str_pad((string)$o['id'], 6, '0', STR_PAD_LEFT), $o['id']]);
            sh_grant($pdo, (int)$o['user_id'], (int)$o['product_id'], 'purchase', (int)$o['id']);
        }
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('shop paid: ' . $e->getMessage());
        return false;
    }
}

/** Sends visitors who are not logged in to the login page, then back here. */
function sh_need_login(): array
{
    $u = sh_user();
    if ($u) return $u;
    header('Location: /account/login?next=' . rawurlencode($_SERVER['REQUEST_URI'] ?? '/account/'));
    exit;
}
function sh_safe_next(string $n, string $fallback = '/account/'): string
{
    return (preg_match('#^/(?!/)[^\s\\\\]*$#', $n) && !str_starts_with($n, '/account/log')) ? $n : $fallback;
}

/** Small line icons for the store pages. */
function sh_icon(string $n): string
{
    static $p = [
        'play' => '<circle cx="12" cy="12" r="10"/><path d="m10 8 6 4-6 4Z"/>',
        'file' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6M8 13h8M8 17h6"/>',
        'check' => '<circle cx="12" cy="12" r="10"/><path d="m8 12 3 3 5-6"/>',
        'lock' => '<rect x="4" y="11" width="16" height="10"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
        'clock' => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
        'video' => '<rect x="2" y="5" width="15" height="14"/><path d="m17 10 5-3v10l-5-3"/>',
        'infinity' => '<path d="M18.2 8.2a5 5 0 1 1 0 7.6L12 12 5.8 8.2a5 5 0 1 0 0 7.6L12 12"/>',
        'device' => '<rect x="5" y="2" width="14" height="20"/><path d="M11 18h2"/>',
        'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/>',
        'download' => '<path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/>',
        'eye' => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
        'layers' => '<path d="m12 2 10 5-10 5L2 7Z"/><path d="m2 17 10 5 10-5M2 12l10 5 10-5"/>',
        'calendar' => '<rect x="3" y="4" width="18" height="18"/><path d="M16 2v4M8 2v4M3 10h18"/>',
        'gauge' => '<path d="M12 14 16 9"/><path d="M3.3 19a10 10 0 1 1 17.4 0"/>',
        'playsolid' => '<path d="M8 5v14l11-7Z" fill="currentColor" stroke="none"/>',
        'back' => '<path d="m15 18-6-6 6-6"/>',
        'book' => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20V3H6.5A2.5 2.5 0 0 0 4 5.5Z"/><path d="M4 19.5A2.5 2.5 0 0 0 6.5 22H20v-5"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'receipt' => '<path d="M5 2h14v20l-3-2-2 2-2-2-2 2-2-2-3 2Z"/><path d="M9 7h6M9 11h6M9 15h4"/>',
        'flag' => '<path d="M4 22V4"/><path d="M4 4h13l-2 4 2 4H4"/>',
        'grid' => '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5M21 12H9"/>',
        'login' => '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><path d="m10 17 5-5-5-5M15 12H3"/>',
        'clip' => '<path d="m21.4 11.1-9.2 9.2a6 6 0 0 1-8.5-8.5l9.2-9.2a4 4 0 0 1 5.7 5.7l-9.2 9.2a2 2 0 0 1-2.8-2.8l8.5-8.5"/>',
        'compass' => '<circle cx="12" cy="12" r="10"/><path d="m16.2 7.8-2.1 6.4-6.4 2.1 2.1-6.4Z"/>',
        'notes' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6M8 13h8M8 17h5"/>',
        'pen' => '<path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
        'menu' => '<path d="M3 6h18M3 12h18M3 18h18"/>',
        'cart' => '<circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.7 13.4a2 2 0 0 0 2 1.6h9.7a2 2 0 0 0 2-1.6L23 6H6"/>',
    ];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($p[$n] ?? '') . '</svg>';
}

/** Price block for a product card / page. */
function sh_price_html(array $p): string
{
    if ((float)$p['price'] <= 0) return '<b>Free</b>';
    $h = '<b>' . sh_money($p['price']) . '</b>';
    if ($p['mrp'] !== null && (float)$p['mrp'] > (float)$p['price']) {
        $h .= '<s>' . sh_money($p['mrp']) . '</s><span class="off">' . (int)round(100 - (float)$p['price'] * 100 / (float)$p['mrp']) . '% off</span>';
    }
    return $h;
}
function sh_thumb_html(array $p): string
{
    return '<div class="st-thumb">' . ($p['thumb'] ? '<img src="' . sh_e($p['thumb']) . '" alt="" loading="lazy">' : '<span class="st-ph">' . sh_e($p['title']) . '</span>')
        . '<span class="st-type">' . sh_e(sh_type_label($p['type'])) . '</span></div>';
}

/* ---------- YouTube (lessons can be YouTube videos instead of uploaded files) ---------- */

function sh_yt_id(string $url): ?string
{
    $url = trim($url);
    if (preg_match('/^[\w-]{11}$/', $url)) return $url;
    return preg_match('~(?:youtu\.be/|youtube(?:-nocookie)?\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/|v/))([\w-]{11})~', $url, $m) ? $m[1] : null;
}
function sh_yt_list(string $url): ?string
{
    return preg_match('~[?&]list=([\w-]{10,64})~', $url, $m) ? $m[1] : null;
}
function sh_http_get(string $url, int $timeout = 12): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => ['Accept-Language: en-US,en;q=0.8,hi;q=0.6'], CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; StoreBot/1.0)']);
    $r = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code === 200 && is_string($r) ? $r : '';
}
/** Title of a YouTube video (oEmbed, no key needed); '' when it cannot be read (private / deleted / offline). */
function sh_yt_title(string $id): string
{
    $j = json_decode(sh_http_get('https://www.youtube.com/oembed?format=json&url=' . rawurlencode('https://www.youtube.com/watch?v=' . $id), 8), true);
    return trim((string)($j['title'] ?? ''));
}
/** Videos of a public / unlisted playlist: [[id, title], …] in playlist order (first ~100 that YouTube puts on the page). */
function sh_yt_playlist(string $list): array
{
    $html = sh_http_get('https://www.youtube.com/playlist?list=' . rawurlencode($list), 20);
    if ($html === '') return [];
    $out = [];
    // YouTube's newer page: one "lockupViewModel" per video (thumbnail …/vi/ID/, title, length badge)
    foreach (array_slice(explode('"lockupViewModel":{', $html), 1) as $seg) {
        $seg = substr($seg, 0, 15000);
        if (!preg_match('~i\.ytimg\.com/vi/([\w-]{11})/~', $seg, $mm) && !preg_match('~"videoId":"([\w-]{11})"~', $seg, $mm)) continue;
        if (isset($out[$mm[1]])) continue;
        $t = preg_match('~"lockupMetadataViewModel":\{"title":\{"content":"((?:[^"\\\\]|\\\\.)*)"~', $seg, $tm) ? (string)json_decode('"' . $tm[1] . '"') : '';
        $out[$mm[1]] = [$mm[1], $t];
    }
    if (!$out && preg_match_all('~"playlistVideoRenderer":\{"videoId":"([\w-]{11})".{0,3000}?"title":\{"runs":\[\{"text":"((?:[^"\\\\]|\\\\.)*)"~s', $html, $m, PREG_SET_ORDER)) {
        foreach ($m as $x) if (!isset($out[$x[1]])) $out[$x[1]] = [$x[1], (string)json_decode('"' . $x[2] . '"')];
    }
    if (!$out && preg_match_all('~"videoId":"([\w-]{11})"~', $html, $m)) foreach ($m[1] as $id) $out[$id] ??= [$id, ''];
    return array_values($out);
}
/** Duration "m:ss" of a YouTube video read from its page; '' if unknown. */
function sh_yt_duration(string $id): string
{
    $html = sh_http_get('https://www.youtube.com/watch?v=' . $id, 10);
    if (!preg_match('~"lengthSeconds":"(\d+)"~', $html, $m)) return '';
    $s = (int)$m[1];
    return $s >= 3600 ? sprintf('%d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60) : sprintf('%d:%02d', intdiv($s, 60), $s % 60);
}

/** Is a staff member with store access logged in (they may preview drafts)? */
function sh_staff(): bool
{
    static $r = null;
    if ($r !== null) return $r;
    $id = (int)($_SESSION['user_id'] ?? 0);
    if (!$id) return $r = false;
    $q = sh_db()->prepare("SELECT role, permissions, status FROM users WHERE id = ?");
    $q->execute([$id]);
    $u = $q->fetch(PDO::FETCH_ASSOC);
    if (!$u || $u['status'] !== 'active') return $r = false;
    return $r = sh_perm($u, 'products') || sh_perm($u, 'customers');
}

/* ---------- staff permissions (Users → Advance Access → Online Store) ---------- */
const SH_PERMS = ['products' => 'Courses & notes (add, edit, upload)', 'orders' => 'Orders & sales reports', 'customers' => 'Customers (accounts, give access)', 'settings' => 'Store settings (Razorpay keys)', 'teacher' => 'Teacher (can teach a course, answers doubts)'];

/** May this staff user do $key in the store? Admins always. The older single "Manage online store" permission = all but settings. */
function sh_perm(array $u, string $key = ''): bool
{
    if (($u['role'] ?? '') === 'admin') return true;
    $p = is_array($u['permissions'] ?? null) ? $u['permissions'] : (json_decode((string)($u['permissions'] ?? '{}'), true) ?: []);
    $legacy = !empty($p['institute']['shop']);
    if ($key === '') { foreach (array_keys(SH_PERMS) as $k) if (!empty($p['store'][$k])) return true; return $legacy; }
    return !empty($p['store'][$key]) || ($legacy && !in_array($key, ['settings', 'teacher'], true));
}
/** Admin store page guard: needs one of $keys. */
function sh_page_start(PDO $pdo, array $keys): array
{
    $me = admin_guard(['admin', 'editor', 'author']);
    if (!array_filter($keys, fn($k) => sh_perm($me, $k))) { http_response_code(403); exit('Access Denied'); }
    sh_tables($pdo);
    return $me;
}

/** Staff preview: only when a staff member opens a store page with ?preview=1 (from the admin "View" buttons).
    Without it staff see the page exactly like a visitor, so locks can be checked. */
function sh_preview(): bool { return isset($_GET['preview']) && sh_staff(); }

/** "9:59" / "1:02:03" → seconds; and seconds → "3h 12m" / "42m" / "45s". */
function sh_dur_sec(?string $d): int
{
    if (!$d || !preg_match('/^(\d+):(\d{2})(?::(\d{2}))?$/', trim($d), $m)) return 0;
    return isset($m[3]) && $m[3] !== '' ? (int)$m[1] * 3600 + (int)$m[2] * 60 + (int)$m[3] : (int)$m[1] * 60 + (int)$m[2];
}
function sh_dur_text(int $s): string
{
    if ($s <= 0) return '';
    if ($s >= 3600) return intdiv($s, 3600) . 'h ' . intdiv($s % 3600, 60) . 'm';
    return $s >= 60 ? round($s / 60) . 'm' : $s . 's';
}


/* ---------- reviews ---------- */

/** Average rating, count and 5..1 star counts of the published reviews of a product. */
function sh_rating(PDO $pdo, int $pid): array
{
    $q = $pdo->prepare("SELECT rating, COUNT(*) n FROM sh_reviews WHERE product_id = ? AND status = 'published' GROUP BY rating");
    $q->execute([$pid]);
    $dist = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0]; $sum = 0; $n = 0;
    foreach ($q as $r) { $dist[(int)$r['rating']] = (int)$r['n']; $sum += (int)$r['rating'] * (int)$r['n']; $n += (int)$r['n']; }
    return ['avg' => $n ? round($sum / $n, 1) : 0, 'count' => $n, 'dist' => $dist];
}
/** Stars as SVG (supports halves): 4.5 → ★★★★½ */
function sh_stars(float $v, string $cls = 'sp-stars'): string
{
    $h = '<span class="' . $cls . '" aria-label="' . $v . ' out of 5">';
    for ($i = 1; $i <= 5; $i++) {
        $fill = $v >= $i ? 100 : ($v > $i - 1 ? (int)round(($v - $i + 1) * 100) : 0);
        $h .= '<svg viewBox="0 0 24 24" aria-hidden="true"><defs><linearGradient id="sg' . $i . 'x' . $fill . '"><stop offset="' . $fill . '%" stop-color="#f59e0b"/><stop offset="' . $fill . '%" stop-color="#d1d5db"/></linearGradient></defs>'
            . '<path fill="url(#sg' . $i . 'x' . $fill . ')" d="m12 2 2.9 6.6 7.1.6-5.4 4.7 1.6 7L12 17.3 5.8 21l1.6-7L2 9.2l7.1-.6Z"/></svg>';
    }
    return $h . '</span>';
}

/** Most bought published courses / notes (for the "Popular courses" carousel). */
function sh_popular(PDO $pdo, array $exclude = [], int $limit = 10, string $type = ''): array
{
    $ex = $exclude ? ' AND p.id NOT IN (' . implode(',', array_map('intval', $exclude)) . ')' : '';
    $ty = in_array($type, ['course', 'notes'], true) ? " AND p.type = '$type'" : '';
    return $pdo->query("SELECT p.*, (SELECT COUNT(*) FROM sh_access a WHERE a.product_id = p.id AND a.revoked = 0) AS buyers,
        (SELECT COUNT(*) FROM sh_lessons l WHERE l.product_id = p.id) AS lessons, (SELECT COUNT(*) FROM sh_files f WHERE f.product_id = p.id) AS files,
        (SELECT ROUND(AVG(r.rating),1) FROM sh_reviews r WHERE r.product_id = p.id AND r.status = 'published') AS rating
        FROM sh_products p WHERE p.status = 'published'$ex$ty ORDER BY buyers DESC, p.sort, p.id DESC LIMIT " . max(1, $limit))->fetchAll(PDO::FETCH_ASSOC);
}

/** A product card (store grids and carousels). $owned shows "In your library" instead of the price. */
function sh_card_html(array $p, bool $owned = false): string
{
    $meta = $p['type'] === 'notes' ? (int)($p['files'] ?? 0) . ' PDF' . ((int)($p['files'] ?? 0) === 1 ? '' : 's') : (int)($p['lessons'] ?? 0) . ' video' . ((int)($p['lessons'] ?? 0) === 1 ? '' : 's');
    $rate = !empty($p['rating']) ? '<span class="st-rt">★ ' . number_format((float)$p['rating'], 1) . '</span>' : '';
    return '<a class="st-card" href="' . sh_e(sh_url($p)) . '">' . sh_thumb_html($p) . '<div class="st-cb"><h3>' . sh_e($p['title']) . '</h3>'
        . ($p['subtitle'] ? '<p>' . sh_e($p['subtitle']) . '</p>' : '') . '<span class="st-meta">' . $meta . $rate . '</span>'
        . '<div class="st-price">' . ($owned ? '<span class="st-own">✓ In your library</span>' : sh_price_html($p)) . '</div></div></a>';
}

/** Account page navigation (My Courses, Profile, Orders, Report us). */
function sh_account_nav(array $u, string $on): string
{
    $i = fn($n) => sh_icon($n);
    $items = [['', 'My Courses', 'book'], ['profile', 'My Profile', 'user'], ['orders', 'Orders', 'receipt'], ['report', 'Report us', 'flag']];
    $h = '<nav class="ac-nav" aria-label="My account"><div class="ac-who"><span class="ac-av">' . sh_e(mb_strtoupper(mb_substr($u['name'], 0, 1))) . '</span><div><b>' . sh_e($u['name']) . '</b><small>' . sh_e($u['mobile']) . '</small></div></div>';
    foreach ($items as [$k, $l, $ic]) $h .= '<a href="/account/' . ($k ? $k : '') . '" class="' . ($on === $k ? 'on' : '') . '">' . $i($ic) . '<span>' . $l . '</span></a>';
    $h .= '<a href="/courses/">' . $i('grid') . '<span>All Courses</span></a><a href="/notes/">' . $i('notes') . '<span>PDF Notes</span></a>';
    $h .= '<form method="post" action="/account/logout">' . sh_csrf_field() . '<button type="submit">' . $i('logout') . '<span>Log out</span></button></form></nav>';
    return $h;
}


/* ---------- teachers, course FAQ / includes, lecture extras ---------- */

/** Staff who can be chosen as a course teacher: [user id => display name] (author name when they have an author profile). */
function sh_teachers(PDO $pdo): array
{
    $out = [];
    foreach ($pdo->query("SELECT u.id, u.username, u.role, u.permissions, u.status, a.name AS author_name FROM users u LEFT JOIN authors a ON a.user_id = u.id ORDER BY u.username")->fetchAll(PDO::FETCH_ASSOC) as $u) {
        if ($u['status'] !== 'active') continue;
        $p = json_decode((string)$u['permissions'], true) ?: [];
        if (!empty($p['store']['teacher']) || $u['role'] === 'teacher') $out[(int)$u['id']] = trim((string)$u['author_name']) ?: $u['username'];
    }
    return $out;
}
function sh_teacher_name(PDO $pdo, ?int $id): string
{
    if (!$id) return '';
    $q = $pdo->prepare("SELECT u.username, a.name FROM users u LEFT JOIN authors a ON a.user_id = u.id WHERE u.id = ?"); $q->execute([$id]);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    return $r ? (trim((string)$r['name']) ?: (string)$r['username']) : '';
}
/** JSON list helpers (always arrays). */
function sh_json_list($v): array { $d = is_array($v) ? $v : json_decode((string)$v, true); return is_array($d) ? $d : []; }
/** VVI: ['qa' => [[q, a], …], 'links' => [[title, url], …]] */
function sh_vvi(array $l): array
{
    $d = sh_json_list($l['vvi'] ?? '');
    $qa = array_values(array_filter(array_map(fn($x) => [trim((string)($x['q'] ?? '')), trim((string)($x['a'] ?? ''))], sh_json_list($d['qa'] ?? [])), fn($x) => $x[0] !== ''));
    $links = array_values(array_filter(array_map(fn($x) => [trim((string)($x['t'] ?? '')), trim((string)($x['u'] ?? ''))], sh_json_list($d['links'] ?? [])), fn($x) => preg_match('#^https?://#', $x[1])));
    return ['qa' => $qa, 'links' => $links];
}
/** Quiz: ['items' => [[q, [o1..o4], answerIndex, explain], …], 'links' => [[title, url], …]] */
function sh_quiz(array $l): array
{
    $d = sh_json_list($l['quiz'] ?? '');
    $items = [];
    foreach (sh_json_list($d['items'] ?? []) as $x) {
        $q = trim((string)($x['q'] ?? '')); $o = array_values(array_filter(array_map(fn($v) => trim((string)$v), sh_json_list($x['o'] ?? [])), fn($v) => $v !== ''));
        $a = (int)($x['a'] ?? 0);
        if ($q !== '' && count($o) >= 2 && $a >= 0 && $a < count($o)) $items[] = [$q, $o, $a, trim((string)($x['e'] ?? ''))];
    }
    $links = array_values(array_filter(array_map(fn($x) => [trim((string)($x['t'] ?? '')), trim((string)($x['u'] ?? ''))], sh_json_list($d['links'] ?? [])), fn($x) => preg_match('#^https?://#', $x[1])));
    return ['items' => $items, 'links' => $links];
}

/** What a video link is: ['ok' => bool, 'kind' => youtube|vimeo|drive|file|embed, 'embed' => url, 'title' => …, 'duration' => …, 'error' => …]. */
function sh_video_probe(string $url): array
{
    $url = trim($url);
    if (!preg_match('#^https?://#i', $url)) return ['ok' => false, 'error' => 'Paste a full link starting with https://'];
    if ($id = sh_yt_id($url)) {
        $j = json_decode(sh_http_get('https://www.youtube.com/oembed?format=json&url=' . rawurlencode('https://www.youtube.com/watch?v=' . $id), 8), true);
        if (empty($j['title'])) return ['ok' => false, 'kind' => 'youtube', 'error' => 'This YouTube video could not be found. Check that it is Public or Unlisted (not Private).'];
        return ['ok' => true, 'kind' => 'youtube', 'title' => (string)$j['title'], 'by' => (string)($j['author_name'] ?? ''), 'duration' => sh_yt_duration($id), 'thumb' => 'https://i.ytimg.com/vi/' . $id . '/hqdefault.jpg'];
    }
    if (preg_match('~vimeo\.com/(?:video/)?(\d+)~', $url, $m)) {
        $j = json_decode(sh_http_get('https://vimeo.com/api/oembed.json?url=' . rawurlencode('https://vimeo.com/' . $m[1]), 8), true);
        if (empty($j['title'])) return ['ok' => false, 'kind' => 'vimeo', 'error' => 'This Vimeo video could not be found (it may be private).'];
        $s = (int)($j['duration'] ?? 0);
        return ['ok' => true, 'kind' => 'vimeo', 'title' => (string)$j['title'], 'duration' => $s ? sprintf('%d:%02d', intdiv($s, 60), $s % 60) : '', 'thumb' => (string)($j['thumbnail_url'] ?? '')];
    }
    if (preg_match('~drive\.google\.com/(?:file/d/|open\?id=|uc\?(?:.*&)?id=)([\w-]{20,})~', $url, $m)) {
        $html = sh_http_get('https://drive.google.com/file/d/' . $m[1] . '/view', 10);
        if ($html === '' || stripos($html, 'accounts.google.com') !== false && stripos($html, 'og:title') === false) return ['ok' => false, 'kind' => 'drive', 'error' => 'Google Drive did not share this file. Set it to "Anyone with the link can view".'];
        preg_match('~<meta property="og:title" content="([^"]+)"~', $html, $t);
        return ['ok' => true, 'kind' => 'drive', 'title' => html_entity_decode($t[1] ?? 'Google Drive video', ENT_QUOTES), 'duration' => ''];
    }
    // a direct video file on any server (MP4 / WebM)
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 4, CURLOPT_TIMEOUT => 10, CURLOPT_RETURNTRANSFER => true, CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; StoreBot/1.0)']);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE); $len = (float)curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
    curl_close($ch);
    if ($code >= 200 && $code < 300 && preg_match('#^video/#i', $type)) return ['ok' => true, 'kind' => 'file', 'title' => basename((string)parse_url($url, PHP_URL_PATH)), 'size' => $len > 0 ? round($len / 1048576, 1) . ' MB' : '', 'duration' => ''];
    if ($code >= 200 && $code < 400) return ['ok' => true, 'kind' => 'embed', 'title' => 'Video page', 'duration' => '', 'note' => 'This is not a direct video file — it will be shown inside a frame. If it does not play, use a YouTube, Drive or MP4 link.'];
    return ['ok' => false, 'error' => 'The link did not open (HTTP ' . ($code ?: 'no answer') . '). Check it in your browser.'];
}
/** Address used to show a lesson video link: YouTube / Vimeo / Drive embed, or the link itself. */
function sh_embed_url(string $url): string
{
    if ($id = sh_yt_id($url)) return 'https://www.youtube-nocookie.com/embed/' . $id . '?rel=0&modestbranding=1&playsinline=1';
    if (preg_match('~vimeo\.com/(?:video/)?(\d+)~', $url, $m)) return 'https://player.vimeo.com/video/' . $m[1];
    if (preg_match('~drive\.google\.com/(?:file/d/|open\?id=|uc\?(?:.*&)?id=)([\w-]{20,})~', $url, $m)) return 'https://drive.google.com/file/d/' . $m[1] . '/preview';
    return $url;
}
function sh_is_direct_video(string $url): bool { return (bool)preg_match('#\.(mp4|m4v|webm|mov)(\?|$)#i', $url); }

/** Notes PDFs of one lecture. */
function sh_lesson_notes(PDO $pdo, int $lid): array
{
    $q = $pdo->prepare("SELECT * FROM sh_files WHERE lesson_id = ? ORDER BY sort, id"); $q->execute([$lid]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}


/* ---------- categories, batches, homepage ---------- */

/** Active categories [id => row] (with the number of published courses / notes in each). */
function sh_categories(PDO $pdo, bool $all = false): array
{
    $rows = $pdo->query("SELECT c.*, (SELECT COUNT(*) FROM sh_products p WHERE p.category_id = c.id AND p.status = 'published') AS items FROM sh_categories c"
        . ($all ? '' : " WHERE c.status = 'active'") . " ORDER BY c.sort, c.name")->fetchAll(PDO::FETCH_ASSOC);
    $out = []; foreach ($rows as $r) $out[(int)$r['id']] = $r; return $out;
}
function sh_category_name(PDO $pdo, int $id): string
{
    if (!$id) return '';
    static $c = []; if (!isset($c[$id])) { $q = $pdo->prepare("SELECT name FROM sh_categories WHERE id = ?"); $q->execute([$id]); $c[$id] = (string)$q->fetchColumn(); }
    return $c[$id];
}
function sh_upload_url(string $f): string { return $f === '' ? '' : (preg_match('#^https?://#', $f) ? $f : '/' . ltrim($f, '/')); }
/** Saves an uploaded image (PNG / JPG / WebP / SVG for icons) under uploads/<dir>/. Returns [relative path, error]. */
function sh_image_save(array $file, string $dir, int $maxMb = 5, bool $svg = false): array
{
    if (($file['error'] ?? 4) !== UPLOAD_ERR_OK) return ['', 'The image could not be uploaded.'];
    if ($file['size'] > $maxMb * 1048576) return ['', "Please choose an image smaller than $maxMb MB."];
    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    $info = @getimagesize($file['tmp_name']);
    $map = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
    if ($info && isset($map[$info[2]])) $ext = $map[$info[2]];
    elseif ($svg && $ext === 'svg') { $x = (string)file_get_contents($file['tmp_name'], false, null, 0, 200000); if (!str_contains($x, '<svg') || preg_match('/<script|on\w+\s*=|javascript:/i', $x)) return ['', 'This SVG is not allowed.']; }
    else return ['', 'Please upload a PNG, JPG or WebP image' . ($svg ? ' (or SVG)' : '') . '.'];
    $base = rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/') . '/uploads/' . $dir;
    if (!is_dir($base)) @mkdir($base, 0755, true);
    $name = date('ymd') . '-' . bin2hex(random_bytes(5)) . '.' . $ext;
    if (!@move_uploaded_file($file['tmp_name'], "$base/$name")) return ['', 'The image could not be saved.'];
    @chmod("$base/$name", 0644);
    return ["uploads/$dir/$name", ''];
}
function sh_image_drop(?string $rel): void
{
    $rel = (string)$rel;
    if ($rel !== '' && preg_match('#^uploads/(slides|categories)/[\w.-]+$#', $rel)) @unlink(rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/') . '/' . $rel);
}
/** The homepage is cached: refresh it after a change. */
function sh_home_refresh(): void { foreach (glob(ROOT_PATH . '/cache/index_home*.html') ?: [] as $f) @unlink($f); }
/** Batch start: [text, upcoming?] for a course with a start date. */
function sh_batch(array $p): array
{
    if (empty($p['starts_at'])) return ['', false];
    $t = strtotime($p['starts_at']); $up = $t >= strtotime('today');
    return [($up ? 'Batch starts ' : 'Started ') . date('d M Y', $t), $up];
}
/** Compact course card for the homepage (2 per row on phones). */
function sh_mini_card(array $p, string $cat = ''): string
{
    [$bt, $up] = sh_batch($p);
    $meta = $p['type'] === 'notes' ? (int)($p['files'] ?? 0) . ' PDF' . ((int)($p['files'] ?? 0) === 1 ? '' : 's') : (int)($p['lessons'] ?? 0) . ' lecture' . ((int)($p['lessons'] ?? 0) === 1 ? '' : 's');
    return '<a class="hc" href="' . sh_e(sh_url($p)) . '"><span class="hc-img">' . sh_thumb_html($p) . ($up ? '<em class="hc-up">Upcoming</em>' : ((float)$p['price'] <= 0 ? '<em class="hc-free">Free</em>' : '')) . '</span>'
        . '<span class="hc-b">' . ($cat !== '' ? '<small class="hc-cat">' . sh_e($cat) . '</small>' : '') . '<b class="hc-t">' . sh_e($p['title']) . '</b>'
        . '<small class="hc-m">' . sh_e($bt !== '' ? $bt : $meta) . '</small><span class="hc-p">' . sh_price_html($p) . '</span><span class="hc-go">View details ›</span></span></a>';
}
/** Published courses, newest first (for the homepage list and its "View more"). */
function sh_latest(PDO $pdo, string $type, int $limit, int $offset = 0, int $cat = 0): array
{
    $sql = "SELECT p.*, (SELECT COUNT(*) FROM sh_lessons l WHERE l.product_id = p.id) AS lessons, (SELECT COUNT(*) FROM sh_files f WHERE f.product_id = p.id) AS files
        FROM sh_products p WHERE p.status = 'published' AND p.type = " . $pdo->quote($type) . ($cat ? ' AND p.category_id = ' . $cat : '') . " ORDER BY p.id DESC LIMIT " . max(1, $limit) . ' OFFSET ' . max(0, $offset);
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}
/** YouTube playlist (or single video) details for a Free course: [list, video, title, thumb, channel, videos, error]. */
function sh_yt_free_info(string $url): array
{
    $url = trim($url);
    $list = preg_match('/[?&]list=([A-Za-z0-9_-]{10,64})/', $url, $m) ? $m[1] : '';
    $vid = preg_match('~(?:youtu\.be/|v=|/embed/|/shorts/|/live/)([A-Za-z0-9_-]{11})~', $url, $m) ? $m[1] : '';
    if ($list === '' && $vid === '') return ['error' => 'Paste a YouTube playlist link (it has "list=" in it) or a video link.'];
    $page = $list !== '' ? 'https://www.youtube.com/playlist?list=' . $list : 'https://www.youtube.com/watch?v=' . $vid;
    $o = json_decode(sh_http_get('https://www.youtube.com/oembed?format=json&url=' . rawurlencode($page), 12), true) ?: [];
    if (empty($o['title'])) return ['error' => 'Could not read this link. Make sure the playlist is Public or Unlisted on YouTube, then try again.'];
    $videos = null;
    if ($list !== '') { $pl = sh_yt_playlist($list); if ($pl) { $videos = count($pl); if ($vid === '') $vid = (string)array_values($pl)[0][0]; } }
    $thumb = (string)($o['thumbnail_url'] ?? ''); if ($thumb === '' && $vid !== '') $thumb = 'https://i.ytimg.com/vi/' . $vid . '/hqdefault.jpg';
    return ['list' => $list, 'video' => $vid, 'title' => (string)$o['title'], 'thumb' => $thumb, 'channel' => (string)($o['author_name'] ?? ''), 'videos' => $videos, 'error' => ''];
}
/** Homepage texts / numbers kept in settings (with sensible defaults). */
function sh_home_cfg(): array
{
    $j = fn($k) => json_decode((string)es('home', $k, ''), true);
    $impact = $j('impact'); $why = $j('why');
    return [
        'impact' => is_array($impact) && $impact ? $impact : [['k' => 'students', 'l' => 'Registered students', 'v' => ''], ['k' => 'courses', 'l' => 'Active courses', 'v' => ''], ['k' => 'free', 'l' => 'Free videos', 'v' => ''], ['k' => 'notes', 'l' => 'PDF notes', 'v' => ''], ['k' => 'years', 'l' => 'Years of teaching', 'v' => '10+']],
        'why' => is_array($why) && $why ? $why : [
            ['t' => 'Expert teachers', 'd' => 'Experienced teachers who explain every chapter step by step, in simple language.'],
            ['t' => 'Chapter-wise courses', 'd' => 'Well-planned courses for every class, with notes, VVI questions and quizzes under each lecture.'],
            ['t' => 'Learn at your own pace', 'd' => 'Watch recorded classes any time on your phone or computer — pause, repeat, and change the speed.'],
            ['t' => 'Doubt solving', 'd' => 'Ask your doubt under any lecture and get the teacher\'s answer right there.']],
        'why_title' => (string)es('home', 'why_title', ''),
    ];
}

/* ---------- orders made by staff (student pays at the office: cash / UPI / card …) ---------- */

const SH_PAY_MODES = ['cash' => 'Cash', 'upi' => 'UPI', 'card' => 'Card', 'bank' => 'Bank transfer', 'cheque' => 'Cheque', 'online' => 'Online (other)', 'free' => 'Free (no payment)'];

/** The online account for an institute student (found by link or mobile, else made). Returns [user id, new password or '']. */
function sh_account_for_student(PDO $pdo, int $sid, int $by = 0): array
{
    $s = $pdo->prepare("SELECT id, name, mobile, email FROM ci_students WHERE id = ?"); $s->execute([$sid]);
    $st = $s->fetch(PDO::FETCH_ASSOC);
    if (!$st) throw new RuntimeException('Student not found.');
    $q = $pdo->prepare("SELECT id FROM sh_users WHERE student_id = ? OR mobile = ? ORDER BY student_id = ? DESC LIMIT 1"); $q->execute([$sid, sh_clean_mobile((string)$st['mobile']), $sid]);
    if ($uid = (int)$q->fetchColumn()) { $pdo->prepare("UPDATE sh_users SET student_id = COALESCE(student_id, ?) WHERE id = ?")->execute([$sid, $uid]); return [$uid, '']; }
    [$uid, $pass] = sh_account_new($pdo, (string)$st['name'], (string)$st['mobile'], (string)$st['email'], '', $by);
    $pdo->prepare("UPDATE sh_users SET student_id = ? WHERE id = ?")->execute([$sid, $uid]);
    return [$uid, $pass];
}
/** A new online account made by staff; email may be empty (a placeholder is used, the student can change it in My Profile). */
function sh_account_new(PDO $pdo, string $name, string $mobile, string $email, string $pass, int $by = 0): array
{
    $mobile = sh_clean_mobile($mobile);
    $email = strtolower(trim($email));
    if ($email === '' && preg_match('/^[6-9]\d{9}$/', $mobile)) $email = 'm' . $mobile . '@noemail.invalid';
    $made = $pass === '';
    if ($made) $pass = (string)random_int(100000, 999999); // 6 digits — easy to tell the student at the desk
    $uid = sh_register($pdo, $name, $mobile, $email, $pass, $by ?: null);
    return [$uid, $made ? $pass : ''];
}
/** Staff order: gives the course / notes and records the payment. $who = ['uid'=>N] | ['sid'=>N] | ['new'=>[name, mobile, email, pass]].
    Returns ['uid', 'order_id', 'order_no', 'password' (when an account was made), 'name']. */
function sh_manual_order(PDO $pdo, array $who, int $pid, ?float $amount, string $mode, ?string $paidOn, string $note, ?int $days, int $by): array
{
    $p = sh_product($pdo, $pid, false);
    if (!$p) throw new RuntimeException('Choose a course or notes.');
    if (!isset(SH_PAY_MODES[$mode])) throw new RuntimeException('Choose how the student paid.');
    $amount = $mode === 'free' ? 0.0 : round(max(0, $amount ?? (float)$p['price']), 2);
    $pass = '';
    $pdo->beginTransaction();
    try {
        if (!empty($who['uid'])) $uid = (int)$who['uid'];
        elseif (!empty($who['sid'])) [$uid, $pass] = sh_account_for_student($pdo, (int)$who['sid'], $by);
        else { $n = $who['new'] ?? []; [$uid, $pass] = sh_account_new($pdo, (string)($n['name'] ?? ''), (string)($n['mobile'] ?? ''), (string)($n['email'] ?? ''), (string)($n['pass'] ?? ''), $by); }
        $u = $pdo->prepare("SELECT id, name FROM sh_users WHERE id = ?"); $u->execute([$uid]); $u = $u->fetch(PDO::FETCH_ASSOC);
        if (!$u) throw new RuntimeException('Account not found.');
        $at = $paidOn && preg_match('/^\d{4}-\d{2}-\d{2}$/', $paidOn) && $paidOn <= date('Y-m-d') ? $paidOn . date(' H:i:s') : date('Y-m-d H:i:s');
        $oid = null; $no = '';
        if ($mode === 'free') sh_grant($pdo, $uid, $pid, 'admin', null, $by, trim('Given free at the office. ' . $note), $days);
        else {
            $pdo->prepare("INSERT INTO sh_orders (user_id, product_id, title, amount, status, rzp_order, rzp_payment, method, error, created_at, paid_at) VALUES (?,?,?,?, 'paid', ?, ?, ?, ?, ?, ?)")
                ->execute([$uid, $pid, $p['title'], $amount, 'manual_' . bin2hex(random_bytes(6)), 'OFFICE-' . strtoupper(bin2hex(random_bytes(4))), 'office · ' . $mode, $note !== '' ? mb_substr('Note: ' . $note, 0, 300) : null, $at, $at]);
            $oid = (int)$pdo->lastInsertId(); $no = 'DKO-' . str_pad((string)$oid, 6, '0', STR_PAD_LEFT);
            $pdo->prepare("UPDATE sh_orders SET order_no = ? WHERE id = ?")->execute([$no, $oid]);
            sh_grant($pdo, $uid, $pid, 'purchase', $oid, $by, $note, $days);
        }
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    return ['uid' => $uid, 'order_id' => $oid, 'order_no' => $no, 'password' => $pass, 'name' => $u['name'], 'title' => $p['title'], 'amount' => $amount];
}
