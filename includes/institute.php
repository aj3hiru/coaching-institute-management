<?php
/**
 * Coaching institute management: students, academic sessions, classes, courses, batches, enrollments, fee payments
 * and dues. Tables start with ci_ and are created the first time an institute page opens (nothing else is touched).
 *
 * Fee model: every enrollment (student → course, usually in a batch) has a total fee minus a discount. Payments are
 * instalments against one enrollment, each with its own receipt. Due = net fee − paid (a dropped enrollment has no due).
 */

if (!function_exists('ci_tables')) {

define('CI_REG_PREFIX', 'DK-');
define('CI_RECEIPT_PREFIX', 'RCPT-');
define('CI_METHODS', ['Cash' => 'Cash', 'UPI' => 'UPI', 'Card' => 'Card', 'Bank' => 'Bank transfer', 'Cheque' => 'Cheque', 'Other' => 'Other']);

function ci_tables(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $opt = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $pdo->exec("CREATE TABLE IF NOT EXISTS ci_classes (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL, sort INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_name (name)) $opt");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ci_sessions (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(40) NOT NULL, start_date DATE NULL, end_date DATE NULL,
        is_current TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_name (name)) $opt");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ci_courses (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(150) NOT NULL, code VARCHAR(40) NULL, duration VARCHAR(60) NULL,
        fee DECIMAL(10,2) NOT NULL DEFAULT 0, description TEXT NULL, status VARCHAR(12) NOT NULL DEFAULT 'active',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) $opt");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ci_batches (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(150) NOT NULL, course_id INT UNSIGNED NOT NULL, session_id INT UNSIGNED NULL,
        timing VARCHAR(80) NULL, days VARCHAR(80) NULL, start_date DATE NULL, end_date DATE NULL, capacity INT NULL,
        fee DECIMAL(10,2) NULL, teacher VARCHAR(120) NULL, status VARCHAR(12) NOT NULL DEFAULT 'active',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY k_course (course_id), KEY k_session (session_id)) $opt");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ci_students (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, reg_no VARCHAR(30) NOT NULL, name VARCHAR(150) NOT NULL,
        father_name VARCHAR(150) NULL, mother_name VARCHAR(150) NULL, dob DATE NULL, gender VARCHAR(10) NULL,
        mobile VARCHAR(15) NOT NULL, alt_mobile VARCHAR(15) NULL, email VARCHAR(150) NULL, address TEXT NULL,
        city VARCHAR(100) NULL, state VARCHAR(100) NULL, pincode VARCHAR(10) NULL, class_id INT UNSIGNED NULL,
        school VARCHAR(150) NULL, photo VARCHAR(255) NULL, status VARCHAR(12) NOT NULL DEFAULT 'active', admission_date DATE NULL,
        notes TEXT NULL, created_by INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_reg (reg_no), KEY k_mobile (mobile), KEY k_class (class_id), KEY k_status (status)) $opt");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ci_enrollments (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, student_id INT UNSIGNED NOT NULL, course_id INT UNSIGNED NOT NULL,
        batch_id INT UNSIGNED NULL, session_id INT UNSIGNED NULL, fee DECIMAL(10,2) NOT NULL DEFAULT 0,
        discount DECIMAL(10,2) NOT NULL DEFAULT 0, due_date DATE NULL, enrolled_on DATE NULL, status VARCHAR(12) NOT NULL DEFAULT 'active',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY k_student (student_id), KEY k_course (course_id), KEY k_batch (batch_id), KEY k_session (session_id)) $opt");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ci_payments (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, student_id INT UNSIGNED NOT NULL, enrollment_id INT UNSIGNED NOT NULL,
        amount DECIMAL(10,2) NOT NULL, method VARCHAR(20) NOT NULL DEFAULT 'Cash', receipt_no VARCHAR(30) NOT NULL, paid_on DATE NOT NULL,
        note VARCHAR(255) NULL, created_by INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_receipt (receipt_no), KEY k_student (student_id), KEY k_enrollment (enrollment_id), KEY k_paid (paid_on)) $opt");
    // a fresh install gets the current academic session and a starter list of classes (both editable in Setup)
    if (!(int)$pdo->query("SELECT COUNT(*) FROM ci_sessions")->fetchColumn()) {
        $y = (int)date('Y');
        $start = (int)date('n') >= 4 ? $y : $y - 1;
        $pdo->prepare("INSERT INTO ci_sessions (name, start_date, end_date, is_current) VALUES (?, ?, ?, 1)")
            ->execute([$start . '-' . substr((string)($start + 1), -2), "$start-04-01", ($start + 1) . '-03-31']);
    }
    if (!(int)$pdo->query("SELECT COUNT(*) FROM ci_classes")->fetchColumn()) {
        $ins = $pdo->prepare("INSERT INTO ci_classes (name, sort) VALUES (?, ?)");
        foreach (['6th', '7th', '8th', '9th', '10th', '11th', '12th', 'Graduation', 'Competitive'] as $i => $c) $ins->execute([$c, $i + 1]);
    }
}

/** Who may use the institute pages: admins, editors, or users given the "institute" permission. */
function ci_can(array $u): bool
{
    if (in_array($u['role'] ?? '', ['admin', 'editor'], true)) return true;
    $p = json_decode($u['permissions'] ?? '{}', true) ?: [];
    return !empty($p['institute']['manage']);
}

function ci_money($n): string
{
    return '₹' . number_format((float)$n, 2);
}

function ci_money0($n): string
{
    return '₹' . number_format((float)$n);
}

function ci_date(?string $d): string
{
    return ($d && $d !== '0000-00-00') ? date('d M Y', strtotime($d)) : '—';
}

/** Valid Y-m-d date or null. */
function ci_clean_date($v): ?string
{
    $v = trim((string)$v);
    if ($v === '') return null;
    $d = DateTime::createFromFormat('Y-m-d', $v);
    return ($d && $d->format('Y-m-d') === $v) ? $v : null;
}

function ci_amount($v): float
{
    $v = (float)str_replace([',', '₹', ' '], '', (string)$v);
    return $v > 0 ? round($v, 2) : 0.0;
}

function ci_e($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/** Next number after the highest one used, e.g. DK-0042 → DK-0043 (works across deleted rows). */
function ci_next_no(PDO $pdo, string $table, string $col, string $prefix, int $pad): string
{
    $st = $pdo->prepare("SELECT $col FROM $table WHERE $col LIKE ? ORDER BY LENGTH($col) DESC, $col DESC LIMIT 1");
    $st->execute([$prefix . '%']);
    $last = (string)$st->fetchColumn();
    $n = $last !== '' ? (int)substr($last, strlen($prefix)) : 0;
    return $prefix . str_pad((string)($n + 1), $pad, '0', STR_PAD_LEFT);
}

function ci_lists(PDO $pdo): array
{
    return [
        'classes' => $pdo->query("SELECT id, name FROM ci_classes ORDER BY sort, name")->fetchAll(PDO::FETCH_KEY_PAIR),
        'sessions' => $pdo->query("SELECT id, name FROM ci_sessions ORDER BY start_date DESC, name DESC")->fetchAll(PDO::FETCH_KEY_PAIR),
        'courses' => $pdo->query("SELECT id, name, fee, status FROM ci_courses ORDER BY name")->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC),
        'batches' => $pdo->query("SELECT b.id, b.name, b.course_id, b.session_id, b.fee, b.timing, b.status, b.capacity, s.name AS session_name
            FROM ci_batches b LEFT JOIN ci_sessions s ON s.id = b.session_id ORDER BY b.status = 'active' DESC, b.name")->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC),
    ];
}

function ci_current_session(PDO $pdo): ?int
{
    $id = $pdo->query("SELECT id FROM ci_sessions WHERE is_current = 1 ORDER BY id DESC LIMIT 1")->fetchColumn();
    return $id ? (int)$id : null;
}

/** SQL (subquery) for paid / due of one enrollment row aliased e. */
define('CI_PAID_SQL', "(SELECT COALESCE(SUM(p.amount),0) FROM ci_payments p WHERE p.enrollment_id = e.id)");
define('CI_DUE_SQL', "(CASE WHEN e.status = 'dropped' THEN 0 ELSE GREATEST(e.fee - e.discount - (SELECT COALESCE(SUM(p.amount),0) FROM ci_payments p WHERE p.enrollment_id = e.id), 0) END)");

/** Fee summary of a student: net fee, paid, due. */
function ci_student_totals(PDO $pdo, int $sid): array
{
    $st = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN e.status = 'dropped' THEN 0 ELSE e.fee - e.discount END),0) AS net,
        COALESCE(SUM(" . CI_DUE_SQL . "),0) AS due FROM ci_enrollments e WHERE e.student_id = ?");
    $st->execute([$sid]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    $p = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM ci_payments WHERE student_id = ?");
    $p->execute([$sid]);
    return ['net' => (float)$r['net'], 'paid' => (float)$p->fetchColumn(), 'due' => (float)$r['due']];
}

/** Enrollments of a student with course/batch/session names and paid/due. */
function ci_enrollments(PDO $pdo, int $sid): array
{
    $st = $pdo->prepare("SELECT e.*, c.name AS course_name, b.name AS batch_name, b.timing, s.name AS session_name,
        " . CI_PAID_SQL . " AS paid, " . CI_DUE_SQL . " AS due
        FROM ci_enrollments e LEFT JOIN ci_courses c ON c.id = e.course_id LEFT JOIN ci_batches b ON b.id = e.batch_id
        LEFT JOIN ci_sessions s ON s.id = e.session_id WHERE e.student_id = ? ORDER BY e.status = 'active' DESC, e.id DESC");
    $st->execute([$sid]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Records a payment (never more than the enrollment's due). Returns [payment id, receipt no] or an error text. */
function ci_add_payment(PDO $pdo, int $enrollmentId, float $amount, string $method, ?string $date, string $note, int $userId)
{
    $st = $pdo->prepare("SELECT e.*, " . CI_DUE_SQL . " AS due FROM ci_enrollments e WHERE e.id = ?");
    $st->execute([$enrollmentId]);
    $e = $st->fetch(PDO::FETCH_ASSOC);
    if (!$e) return 'Choose the course the fee is for.';
    if ($amount <= 0) return 'Enter the amount received.';
    if ($e['status'] === 'dropped') return 'This enrollment is dropped; no fee can be taken for it.';
    if ($amount > (float)$e['due'] + 0.004) return 'Amount is more than the due (' . ci_money($e['due']) . ').';
    if ($date && $date > date('Y-m-d')) return 'The payment date cannot be in the future.';
    if (!isset(CI_METHODS[$method])) $method = 'Cash';
    for ($try = 0; $try < 3; $try++) {
        $no = ci_next_no($pdo, 'ci_payments', 'receipt_no', CI_RECEIPT_PREFIX, 6);
        try {
            $pdo->prepare("INSERT INTO ci_payments (student_id, enrollment_id, amount, method, receipt_no, paid_on, note, created_by) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([(int)$e['student_id'], $enrollmentId, $amount, $method, $no, $date ?: date('Y-m-d'), mb_substr($note, 0, 250), $userId]);
            return [(int)$pdo->lastInsertId(), $no];
        } catch (PDOException $ex) {
            if ($ex->getCode() !== '23000') throw $ex; // same receipt number taken at the same moment → try the next one
        }
    }
    return 'Could not save the payment, please try again.';
}

/** Saves an uploaded student photo (JPG/PNG/WebP, max 3 MB), scaled down to 600px. Returns the stored path, '' or an error. */
function ci_save_photo(array $file, string $regNo): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return ['', null];
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) return ['', 'The photo could not be uploaded.'];
    if ($file['size'] > 3 * 1024 * 1024) return ['', 'The photo is larger than 3 MB.'];
    $info = @getimagesize($file['tmp_name']);
    $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    if (!$info || !isset($types[$info[2]])) return ['', 'The photo must be a JPG, PNG or WebP image.'];
    $dir = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/uploads/students';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $name = preg_replace('/[^A-Za-z0-9-]/', '', $regNo) . '-' . bin2hex(random_bytes(4));
    $dest = "$dir/$name.jpg";
    $ok = false;
    $reader = [IMAGETYPE_JPEG => 'imagecreatefromjpeg', IMAGETYPE_PNG => 'imagecreatefrompng', IMAGETYPE_WEBP => 'imagecreatefromwebp'][$info[2]];
    if (function_exists('imagecreatetruecolor') && function_exists('imagejpeg') && function_exists($reader)) {
        $src = @$reader($file['tmp_name']);
        if ($src) {
            [$w, $h] = [imagesx($src), imagesy($src)];
            $s = min(1, 600 / max($w, $h));
            $nw = max(1, (int)round($w * $s)); $nh = max(1, (int)round($h * $s));
            $dst = imagecreatetruecolor($nw, $nh);
            imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
            $ok = imagejpeg($dst, $dest, 85);
            imagedestroy($src); imagedestroy($dst);
        }
    }
    if (!$ok) {
        $dest = "$dir/$name." . $types[$info[2]];
        $ok = move_uploaded_file($file['tmp_name'], $dest);
    }
    return $ok ? ['uploads/students/' . basename($dest), null] : ['', 'The photo could not be saved.'];
}

/** Delete a student photo file — only ever a file inside uploads/students/. */
function ci_delete_photo(?string $p): void
{
    if (!$p || !preg_match('#^uploads/students/[A-Za-z0-9-]+\.(jpg|png|webp)$#', $p)) return;
    $f = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/' . $p;
    if (is_file($f)) @unlink($f);
}

function ci_photo_url(?string $p): string
{
    return $p ? '/' . ltrim($p, '/') : '';
}

/** Initials avatar when there is no photo. */
function ci_avatar(array $s, int $size = 40): string
{
    $url = ci_photo_url($s['photo'] ?? '');
    if ($url) return '<img class="ci-av" src="' . ci_e($url) . '" alt="" width="' . $size . '" height="' . $size . '" style="width:' . $size . 'px;height:' . $size . 'px">';
    $parts = preg_split('/\s+/', trim((string)($s['name'] ?? '?')));
    $ini = mb_strtoupper(mb_substr($parts[0] ?? '?', 0, 1) . (count($parts) > 1 ? mb_substr(end($parts), 0, 1) : ''));
    return '<span class="ci-av ci-av-txt" style="width:' . $size . 'px;height:' . $size . 'px;font-size:' . round($size * .38) . 'px">' . ci_e($ini) . '</span>';
}

function ci_status_badge(string $s): string
{
    $map = ['active' => ['Active', 'ci-b-green'], 'left' => ['Left', 'ci-b-grey'], 'completed' => ['Completed', 'ci-b-blue'], 'dropped' => ['Dropped', 'ci-b-grey'], 'inactive' => ['Inactive', 'ci-b-grey']];
    [$l, $c] = $map[$s] ?? [ucfirst($s), 'ci-b-grey'];
    return '<span class="ci-badge ' . $c . '">' . $l . '</span>';
}

function ci_fee_badge(float $due, float $net): string
{
    if ($net <= 0) return '<span class="ci-badge ci-b-grey">No fee</span>';
    if ($due <= 0.004) return '<span class="ci-badge ci-b-green">Paid</span>';
    return '<span class="ci-badge ci-b-red">Due ' . ci_money0($due) . '</span>';
}

/** CSV download (Excel opens ₹ and Hindi correctly thanks to the BOM). */
function ci_csv(string $filename, array $head, array $rows): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $head);
    foreach ($rows as $r) fputcsv($out, $r);
    fclose($out);
    exit;
}

/** Institute name/address/phone for receipts (from Settings → General). */
function ci_institute(): array
{
    $g = function_exists('edu_settings') ? (array)edu_settings('general') : [];
    return [
        'name' => function_exists('edu_site_name') ? edu_site_name() : 'Institute',
        'logo' => trim((string)($g['logo'] ?? '')),
        'address' => trim((string)($g['address'] ?? (defined('COMPANY_ADDRESS') ? COMPANY_ADDRESS : ''))),
        'phone' => trim((string)($g['phone'] ?? (defined('PHONE_NUMBER') ? PHONE_NUMBER : ''))),
        'email' => trim((string)($g['contact_email'] ?? (defined('CONTACT_EMAIL') ? CONTACT_EMAIL : ''))),
    ];
}

/** Page head extras shared by the institute pages. */
function ci_head(): void
{
    $v = @filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/institute.css') ?: 1;
    echo '<link rel="stylesheet" href="/assets/css/institute.css?v=' . $v . '">' . "\n";
}

/** Opens an institute admin page: guard + tables. Returns the user. */
function ci_page_start(PDO $pdo): array
{
    $me = admin_guard(['admin', 'editor', 'author']);
    if (!ci_can($me)) { http_response_code(403); exit('Access Denied'); }
    ci_tables($pdo);
    return $me;
}

}
