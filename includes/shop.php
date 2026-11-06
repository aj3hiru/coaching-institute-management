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

const SH_SCHEMA = 4;

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
    edu_settings_save('shop', ['schema' => SH_SCHEMA]);
}

/* ---------- small helpers ---------- */

function sh_e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function sh_money($n): string { $n = (float)$n; return '₹' . number_format($n, fmod($n, 1) ? 2 : 0); }
function sh_on(): bool { return es('shop', 'enabled', '1') === '1'; }
function sh_url(array $p): string { return '/store/item?s=' . rawurlencode($p['slug']); }
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
function sh_mark_cookie(?string $first): void
{
    $o = ['path' => '/', 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly' => false];
    if ($first === null) { foreach (['sh_in', 'sh_n'] as $c) setcookie($c, '', ['expires' => time() - 3600] + $o); return; }
    setcookie('sh_in', '1', ['expires' => time() + 30 * 86400] + $o);
    setcookie('sh_n', rawurlencode(mb_substr($first, 0, 30)), ['expires' => time() + 30 * 86400] + $o);
}
function sh_login_user(array $u): void
{
    session_regenerate_id(true);
    $_SESSION['sh_uid'] = (int)$u['id'];
    sh_mark_cookie(explode(' ', trim((string)$u['name']))[0]);
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
    if (strlen($pass) < 6) throw new RuntimeException('The password must have at least 6 characters.');
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
    $ls = $pdo->prepare("SELECT * FROM sh_lessons WHERE product_id = ? ORDER BY sort, id");
    $ls->execute([$pid]);
    foreach ($ls as $l) { $k = isset($out[(int)$l['section_id']]) ? (int)$l['section_id'] : 0; $out[$k]['lessons'][] = $l; }
    if (!$out[0]['lessons']) unset($out[0]);
    return array_values($out);
}
function sh_files(PDO $pdo, int $pid): array
{
    $q = $pdo->prepare("SELECT * FROM sh_files WHERE product_id = ? ORDER BY sort, id");
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
        'eye' => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
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
    if (preg_match_all('~"playlistVideoRenderer":\{"videoId":"([\w-]{11})".{0,3000}?"title":\{"runs":\[\{"text":"((?:[^"\\\\]|\\\\.)*)"~s', $html, $m, PREG_SET_ORDER)) {
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
    if ($u['role'] === 'admin') return $r = true;
    $p = json_decode((string)$u['permissions'], true) ?: [];
    return $r = !empty($p['institute']['shop']);
}
