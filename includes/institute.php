<?php
/**
 * Coaching institute management: students, academic sessions, classes, courses, batches, enrollments, fee payments
 * and dues. Tables start with ci_ and are created the first time an institute page opens (nothing else is touched).
 *
 * Fee model: every enrollment (student → course, usually in a batch) has a total fee minus a discount. Payments are
 * made against one enrollment, any number of times, each with its own receipt. Due = net fee − paid (a dropped
 * enrollment has no due). An enrollment may have an instalment plan (ci_installments: amount + due date per kist);
 * what is paid fills the instalments oldest first, and e.due_date is kept = the date of the first unpaid instalment,
 * so every "next due / due date passed" list works the same with or without a plan.
 */

if (!function_exists('ci_tables')) {

define('CI_REG_PREFIX', ''); // registration numbers are 6 digits: 000001, 000002 … (old DK-0001 style ones are changed once)
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
    $pdo->exec("CREATE TABLE IF NOT EXISTS ci_installments (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, enrollment_id INT UNSIGNED NOT NULL, no INT NOT NULL DEFAULT 1,
        amount DECIMAL(10,2) NOT NULL, due_date DATE NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY k_enrollment (enrollment_id), KEY k_due (due_date)) $opt");
    // added later: a course can belong to a class (the enrollment form then offers the class's courses first)
    if (!$pdo->query("SHOW COLUMNS FROM ci_courses LIKE 'class_id'")->fetch()) $pdo->exec("ALTER TABLE ci_courses ADD COLUMN class_id INT UNSIGNED NULL AFTER code");
    // added later: how the student pays a course — 'full' (final fee, one time) or 'installment' (any amount, any number of times)
    // class changes (promotion 6th → 7th …): the old courses and their fees stay as they are
    $pdo->exec("CREATE TABLE IF NOT EXISTS ci_class_history (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, student_id INT UNSIGNED NOT NULL, from_class INT UNSIGNED NULL, to_class INT UNSIGNED NULL,
        session_id INT UNSIGNED NULL, changed_on DATE NOT NULL, note VARCHAR(255) NULL, created_by INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY k_student (student_id)) $opt");
    // when a student left / a course ended (shown in course & batch reports)
    if (!$pdo->query("SHOW COLUMNS FROM ci_students LIKE 'left_on'")->fetch()) $pdo->exec("ALTER TABLE ci_students ADD COLUMN left_on DATE NULL AFTER admission_date");
    if (!$pdo->query("SHOW COLUMNS FROM ci_enrollments LIKE 'ended_on'")->fetch()) $pdo->exec("ALTER TABLE ci_enrollments ADD COLUMN ended_on DATE NULL AFTER enrolled_on");
    // split payments (part Cash + part UPI …): one receipt, its parts as JSON [{"m":"Cash","a":3000},…]
    if (!$pdo->query("SHOW COLUMNS FROM ci_payments LIKE 'parts'")->fetch()) $pdo->exec("ALTER TABLE ci_payments ADD COLUMN parts TEXT NULL AFTER method");
    if (!$pdo->query("SHOW COLUMNS FROM ci_enrollments LIKE 'fee_type'")->fetch()) $pdo->exec("ALTER TABLE ci_enrollments ADD COLUMN fee_type VARCHAR(12) NOT NULL DEFAULT 'full' AFTER discount");
    // student type: institute (offline batches), online (online courses / notes only) or both
    if (!$pdo->query("SHOW COLUMNS FROM ci_students LIKE 'stype'")->fetch()) $pdo->exec("ALTER TABLE ci_students ADD COLUMN stype VARCHAR(10) NOT NULL DEFAULT 'institute' AFTER status, ADD KEY k_stype (stype)");
    // old "DK-0001" registration numbers become "000001" (the same number, 6 digits)
    foreach ($pdo->query("SELECT id, reg_no FROM ci_students WHERE reg_no REGEXP '^DK-[0-9]+$'")->fetchAll(PDO::FETCH_KEY_PAIR) as $sid => $rn) {
        $newNo = str_pad((string)(int)substr($rn, 3), 6, '0', STR_PAD_LEFT);
        $q = $pdo->prepare("SELECT COUNT(*) FROM ci_students WHERE reg_no = ?"); $q->execute([$newNo]);
        if (!(int)$q->fetchColumn()) $pdo->prepare("UPDATE ci_students SET reg_no = ? WHERE id = ?")->execute([$newNo, $sid]);
    }
    // attendance: one row per student, batch (0 = no batch) and day; a scan of the ID card sets check-in, a later scan check-out
    $pdo->exec("CREATE TABLE IF NOT EXISTS ci_attendance (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, student_id INT UNSIGNED NOT NULL, batch_id INT UNSIGNED NOT NULL DEFAULT 0, att_date DATE NOT NULL,
        status VARCHAR(8) NOT NULL DEFAULT 'present', in_at DATETIME NULL, out_at DATETIME NULL, source VARCHAR(8) NOT NULL DEFAULT 'manual',
        marked_by INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NULL,
        UNIQUE KEY uq_day (student_id, batch_id, att_date), KEY k_date (att_date, batch_id)) $opt");
    // roll number in the class (on the ID card and the attendance list); students who had none get one in admission order
    if (!$pdo->query("SHOW COLUMNS FROM ci_students LIKE 'roll_no'")->fetch()) {
        $pdo->exec("ALTER TABLE ci_students ADD COLUMN roll_no VARCHAR(20) NULL AFTER reg_no");
        $n = []; $up = $pdo->prepare("UPDATE ci_students SET roll_no = ? WHERE id = ?");
        foreach ($pdo->query("SELECT id, class_id FROM ci_students WHERE stype <> 'online' AND class_id IS NOT NULL ORDER BY class_id, admission_date, id") as $r) {
            $c = (int)$r['class_id']; $n[$c] = ($n[$c] ?? 0) + 1; $up->execute([(string)$n[$c], (int)$r['id']]);
        }
    }
    // batch class time (start / end): the attendance page opens on the batch running now; "Timing" text is made from it.
    // Old batches get it read from their timing text ("7:00–8:30 AM", "4:00–5:30 PM").
    if (!$pdo->query("SHOW COLUMNS FROM ci_batches LIKE 'start_time'")->fetch()) {
        $pdo->exec("ALTER TABLE ci_batches ADD COLUMN start_time TIME NULL AFTER timing, ADD COLUMN end_time TIME NULL AFTER start_time");
        $up = $pdo->prepare("UPDATE ci_batches SET start_time = ?, end_time = ? WHERE id = ?");
        foreach ($pdo->query("SELECT id, timing FROM ci_batches WHERE timing IS NOT NULL") as $b) if ($t = ci_parse_timing((string)$b['timing'])) $up->execute([$t[0], $t[1], (int)$b['id']]);
    }
    // holidays: from – to, for every course and batch, one course, or one batch (no class: not absent)
    $pdo->exec("CREATE TABLE IF NOT EXISTS ci_holidays (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, title VARCHAR(150) NOT NULL, from_date DATE NOT NULL, to_date DATE NOT NULL,
        course_id INT UNSIGNED NULL, batch_id INT UNSIGNED NULL, created_by INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY k_dates (from_date, to_date)) $opt");
    // a fresh install gets the current academic session and a starter list of classes (both editable in Setup)
    if (!(int)$pdo->query("SELECT COUNT(*) FROM ci_sessions")->fetchColumn()) {
        $y = (int)date('Y');
        $start = (int)date('n') >= 4 ? $y : $y - 1;
        $pdo->prepare("INSERT INTO ci_sessions (name, start_date, end_date, is_current) VALUES (?, ?, ?, 1)")
            ->execute([$start . '-' . substr((string)($start + 1), -2), "$start-04-01", ($start + 1) . '-03-31']);
    }
    if (!(int)$pdo->query("SELECT COUNT(*) FROM ci_classes")->fetchColumn()) {
        $ins = $pdo->prepare("INSERT INTO ci_classes (name, sort) VALUES (?, ?)");
        foreach (['5th', '6th', '7th', '8th', '9th', '10th', '11th', '12th'] as $i => $c) $ins->execute([$c, $i + 1]);
    }
}

/** Institute permissions (Users → Advance Access → Institute). Admins have all of them. */
define('CI_PERMS', ['add_student' => 'Add student', 'edit_student' => 'Edit student', 'batches' => 'Manage batches', 'courses' => 'Manage courses',
    'fees' => 'Manage fee & dues', 'sessions' => 'Manage sessions', 'classes' => 'Manage classes', 'attendance' => 'Take attendance']);

function ci_perm(array $u, string $key): bool
{
    if (($u['role'] ?? '') === 'admin') return true;
    if (!function_exists('edu_role_allows')) require_once INCLUDES_PATH . '/roles.php';
    if (!edu_role_allows((string)($u['role'] ?? ''), 'institute')) return false; // blog people never reach the institute
    $p = is_array($u['permissions'] ?? null) ? $u['permissions'] : (json_decode((string)($u['permissions'] ?? '{}'), true) ?: []);
    return !empty($p['institute'][$key]);
}

/** May the user open the institute section at all (any one institute permission)? */
function ci_can(array $u): bool
{
    foreach (array_keys(CI_PERMS) as $k) if ($k !== 'shop' && ci_perm($u, $k)) return true; // the online store is separate from the institute pages
    return false;
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
    if (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$#', $v, $m)) $v = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]); // typed dd/mm/yyyy
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

/** Next number after the highest one used, e.g. 000042 → 000043 (works across deleted rows). With no prefix only all-digit numbers count. */
function ci_next_no(PDO $pdo, string $table, string $col, string $prefix, int $pad): string
{
    if ($prefix === '') {
        $n = (int)$pdo->query("SELECT MAX(CAST($col AS UNSIGNED)) FROM $table WHERE $col REGEXP '^[0-9]+$'")->fetchColumn();
        return str_pad((string)($n + 1), $pad, '0', STR_PAD_LEFT);
    }
    $st = $pdo->prepare("SELECT $col FROM $table WHERE $col LIKE ? ORDER BY LENGTH($col) DESC, $col DESC LIMIT 1");
    $st->execute([$prefix . '%']);
    $last = (string)$st->fetchColumn();
    $n = $last !== '' ? (int)substr($last, strlen($prefix)) : 0;
    return $prefix . str_pad((string)($n + 1), $pad, '0', STR_PAD_LEFT);
}

if (!defined('CI_STYPES')) define('CI_STYPES', ['institute' => 'Institute', 'online' => 'Online', 'both' => 'Both']);

/** An "Online" student who joins an institute course / batch, or an "Institute" one who gets an online course / notes, becomes "Both". */
function ci_type_sync(PDO $pdo, int $sid): void
{
    if ($sid <= 0) return;
    $q = $pdo->prepare("SELECT stype FROM ci_students WHERE id = ?"); $q->execute([$sid]);
    $t = (string)$q->fetchColumn();
    if ($t === '' || $t === 'both') return;
    $inst = $t === 'institute' || (int)$pdo->query("SELECT COUNT(*) FROM ci_enrollments WHERE student_id = " . $sid)->fetchColumn() > 0;
    $onl = $t === 'online';
    if (!$onl) {
        try { $onl = (int)$pdo->query("SELECT COUNT(*) FROM sh_access a JOIN sh_users u ON u.id = a.user_id WHERE u.student_id = $sid AND a.revoked = 0 AND a.blocked = 0")->fetchColumn() > 0; } catch (Throwable) { $onl = false; }
    }
    if ($inst && $onl) $pdo->prepare("UPDATE ci_students SET stype = 'both' WHERE id = ?")->execute([$sid]);
}

function ci_lists(PDO $pdo): array
{
    return [
        'classes' => $pdo->query("SELECT id, name FROM ci_classes ORDER BY sort, name")->fetchAll(PDO::FETCH_KEY_PAIR),
        'sessions' => $pdo->query("SELECT id, name FROM ci_sessions ORDER BY start_date DESC, name DESC")->fetchAll(PDO::FETCH_KEY_PAIR),
        'courses' => $pdo->query("SELECT c.id, c.name, c.fee, c.status, c.class_id FROM ci_courses c LEFT JOIN ci_classes k ON k.id = c.class_id ORDER BY k.sort IS NULL, k.sort, c.name")->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC),
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
/**
 * Payment rows posted from a form: $methods[] and $amounts[] → [[method, amount], …] (empty / zero rows dropped).
 * A method that appears twice is added up.
 */
function ci_parts_from_post(array $methods, array $amounts): array
{
    $out = [];
    foreach ($amounts as $i => $a) {
        $a = ci_amount($a);
        if ($a <= 0) continue;
        $m = (string)($methods[$i] ?? 'Cash');
        if (!isset(CI_METHODS[$m])) $m = 'Cash';
        $out[$m] = round(($out[$m] ?? 0) + $a, 2);
    }
    return array_map(fn($m, $a) => [$m, $a], array_keys($out), array_values($out));
}

/** Payment rows (mode + amount, "+ Add" for a split payment, like the shop's billing screen). JS: assets/js/ci-ui.js */
function ci_pays_html(string $nameM, string $nameA, array $parts = [], string $label = 'Paying now'): string
{
    if (!$parts) $parts = [['Cash', '']];
    $h = '<div class="ci-pays" data-pays><div class="ci-pays-h"><span class="ci-pays-l">' . ci_e($label) . '</span><button type="button" class="ci-pays-add">+ Add</button></div>';
    foreach ($parts as [$m, $a]) {
        $h .= '<div class="ci-pay-row"><select name="' . ci_e($nameM) . '" aria-label="Payment mode">';
        foreach (CI_METHODS as $k => $l) $h .= '<option value="' . $k . '"' . ($m === $k ? ' selected' : '') . '>' . $l . '</option>';
        $h .= '</select><span class="ci-pay-amt"><i>₹</i><input type="number" name="' . ci_e($nameA) . '" class="ci-pay-a" min="0" step="0.01" placeholder="Amount" value="' . ci_e($a === '' || $a === null ? '' : rtrim(rtrim(number_format((float)$a, 2, '.', ''), '0'), '.')) . '"></span>'
            . '<button type="button" class="ci-ico ci-ico-danger ci-pay-del" aria-label="Remove this payment row">' . ci_svg('trash') . '</button></div>';
    }
    return $h . '<div class="ci-pays-total"></div></div>';
}

/** "Cash" or, for a split payment, "Cash ₹3,000 + UPI ₹2,000". */
function ci_pay_mode(array $p, bool $money = true): string
{
    $parts = json_decode((string)($p['parts'] ?? ''), true);
    if (is_array($parts) && count($parts) > 1) return implode(' + ', array_map(fn($x) => (CI_METHODS[$x['m']] ?? $x['m']) . ($money ? ' ' . ci_money0($x['a']) : ''), $parts));
    return CI_METHODS[$p['method'] ?? ''] ?? (string)($p['method'] ?? '');
}

/** Amount per payment method of one payment row (split payments counted per part). */
function ci_pay_split(array $p): array
{
    $parts = json_decode((string)($p['parts'] ?? ''), true);
    if (is_array($parts) && $parts) { $o = []; foreach ($parts as $x) $o[$x['m']] = ($o[$x['m']] ?? 0) + (float)$x['a']; return $o; }
    return [(string)$p['method'] => (float)$p['amount']];
}

/** Records one payment; $method may be an array of [method, amount] parts (split payment) — then $amount is their total. */
function ci_add_payment(PDO $pdo, int $enrollmentId, float $amount, $method, ?string $date, string $note, int $userId)
{
    $parts = null;
    if (is_array($method)) {
        $parts = $method;
        if (!$parts) return 'Enter the amount received.';
        $amount = round(array_sum(array_column($parts, 1)), 2);
        $method = count($parts) > 1 ? 'Split' : $parts[0][0];
        $parts = count($parts) > 1 ? json_encode(array_map(fn($x) => ['m' => $x[0], 'a' => $x[1]], $parts)) : null;
    }
    $st = $pdo->prepare("SELECT e.*, " . CI_DUE_SQL . " AS due FROM ci_enrollments e WHERE e.id = ?");
    $st->execute([$enrollmentId]);
    $e = $st->fetch(PDO::FETCH_ASSOC);
    if (!$e) return 'Choose the course the fee is for.';
    if ($amount <= 0) return 'Enter the amount received.';
    if ($e['status'] === 'dropped') return 'This enrollment is dropped; no fee can be taken for it.';
    if ($amount > (float)$e['due'] + 0.004) return 'Amount is more than the due (' . ci_money($e['due']) . ').';
    if ($date && $date > date('Y-m-d')) return 'The payment date cannot be in the future.';
    if ($method !== 'Split' && !isset(CI_METHODS[$method])) $method = 'Cash';
    for ($try = 0; $try < 3; $try++) {
        $no = ci_next_no($pdo, 'ci_payments', 'receipt_no', CI_RECEIPT_PREFIX, 6);
        try {
            $pdo->prepare("INSERT INTO ci_payments (student_id, enrollment_id, amount, method, parts, receipt_no, paid_on, note, created_by) VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([(int)$e['student_id'], $enrollmentId, $amount, $method, $parts, $no, $date ?: date('Y-m-d'), mb_substr($note, 0, 250), $userId]);
            $pid = (int)$pdo->lastInsertId();
            ci_sync_due($pdo, $enrollmentId);
            return [$pid, $no];
        } catch (PDOException $ex) {
            if ($ex->getCode() !== '23000') throw $ex; // same receipt number taken at the same moment → try the next one
        }
    }
    return 'Could not save the payment, please try again.';
}

/**
 * Instalment plan of one enrollment (oldest first) with what is paid / left on each kist and its state:
 * paid, part (partly paid), late (due date passed, not fully paid), due (still to come). [] when there is no plan.
 */
function ci_plan(PDO $pdo, int $eid, ?float $paid = null): array
{
    $st = $pdo->prepare("SELECT id, no, amount, due_date FROM ci_installments WHERE enrollment_id = ? ORDER BY due_date, no, id");
    $st->execute([$eid]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return [];
    if ($paid === null) {
        $p = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM ci_payments WHERE enrollment_id = ?");
        $p->execute([$eid]);
        $paid = (float)$p->fetchColumn();
    }
    $today = date('Y-m-d');
    $left = $paid;
    foreach ($rows as $i => &$r) {
        $r['no'] = $i + 1;
        $r['amount'] = (float)$r['amount'];
        $r['paid'] = round(min($r['amount'], max(0, $left)), 2);
        $left -= $r['paid'];
        $r['left'] = round($r['amount'] - $r['paid'], 2);
        $r['state'] = $r['left'] <= 0.004 ? 'paid' : ($r['due_date'] < $today ? 'late' : ($r['paid'] > 0 ? 'part' : 'due'));
    }
    unset($r);
    return $rows;
}

/** Summary of a plan: count, paid count, late amount, next unpaid kist. */
function ci_plan_summary(array $plan): array
{
    $next = null;
    $late = 0.0;
    $paidN = 0;
    foreach ($plan as $r) {
        if ($r['state'] === 'paid') { $paidN++; continue; }
        $next ??= $r;
        if ($r['state'] === 'late') $late += $r['left'];
    }
    return ['n' => count($plan), 'paid_n' => $paidN, 'late' => round($late, 2), 'next' => $next];
}

/** Splits a fee into $n instalments, one every $every months from $first (whole rupees; the last one takes the rest). */
function ci_plan_split(float $net, int $n, string $first, int $every = 1): array
{
    $n = max(1, min(36, $n));
    $every = max(1, min(12, $every));
    $base = $n > 1 ? floor($net / $n) : $net;
    [$y, $m, $d] = array_map('intval', explode('-', $first));
    $rows = [];
    for ($i = 0; $i < $n; $i++) {
        $mm = $m - 1 + $i * $every;
        $yy = $y + intdiv($mm, 12);
        $mm = $mm % 12 + 1;
        $dd = min($d, (int)date('t', mktime(0, 0, 0, $mm, 1, $yy)));
        $rows[] = ['amount' => $i === $n - 1 ? round($net - $base * ($n - 1), 2) : $base, 'due_date' => sprintf('%04d-%02d-%02d', $yy, $mm, $dd)];
    }
    return $rows;
}

/** Replaces the plan of an enrollment (rows: amount, due_date) and updates its next due date. */
function ci_plan_save(PDO $pdo, int $eid, array $rows): void
{
    $pdo->prepare("DELETE FROM ci_installments WHERE enrollment_id = ?")->execute([$eid]);
    usort($rows, fn($a, $b) => strcmp($a['due_date'], $b['due_date']));
    $ins = $pdo->prepare("INSERT INTO ci_installments (enrollment_id, no, amount, due_date) VALUES (?, ?, ?, ?)");
    foreach (array_values($rows) as $i => $r) $ins->execute([$eid, $i + 1, round((float)$r['amount'], 2), $r['due_date']]);
    ci_sync_due($pdo, $eid);
}

/** Removes the plan (the fee is then one amount with a manual next due date). */
function ci_plan_clear(PDO $pdo, int $eid): void
{
    $pdo->prepare("DELETE FROM ci_installments WHERE enrollment_id = ?")->execute([$eid]);
}

/** When the fee of an enrollment with a plan changes: same number of kists and dates, amounts split again. */
function ci_plan_resplit(PDO $pdo, int $eid): void
{
    $plan = ci_plan($pdo, $eid, 0);
    if (!$plan) return;
    $e = $pdo->prepare("SELECT fee - discount FROM ci_enrollments WHERE id = ?");
    $e->execute([$eid]);
    $net = max(0, (float)$e->fetchColumn());
    if (abs(array_sum(array_column($plan, 'amount')) - $net) < 0.005) { ci_sync_due($pdo, $eid); return; }
    $split = ci_plan_split($net, count($plan), $plan[0]['due_date']);
    foreach ($plan as $i => $r) $split[$i]['due_date'] = $r['due_date'];
    ci_plan_save($pdo, $eid, $split);
}

/** Keeps e.due_date = the first unpaid instalment's date (only for enrollments with a plan). */
function ci_sync_due(PDO $pdo, int $eid): void
{
    $plan = ci_plan($pdo, $eid);
    if (!$plan) return;
    $next = ci_plan_summary($plan)['next'];
    $pdo->prepare("UPDATE ci_enrollments SET due_date = ? WHERE id = ?")->execute([$next['due_date'] ?? null, $eid]);
}

/** Checks posted plan rows (amount[], date[]) against the net fee. Returns [rows, error|null]. */
function ci_plan_from_post(array $amounts, array $dates, float $net): array
{
    $rows = [];
    foreach ($amounts as $i => $a) {
        $a = ci_amount($a);
        $d = ci_clean_date($dates[$i] ?? '');
        if (!$a && !$d) continue;
        if (!$a) return [[], 'Every instalment needs an amount.'];
        if (!$d) return [[], 'Every instalment needs a due date.'];
        $rows[] = ['amount' => $a, 'due_date' => $d];
    }
    if (!$rows) return [[], 'Add at least one instalment.'];
    if (count($rows) > 36) return [[], 'At most 36 instalments.'];
    $sum = round(array_sum(array_column($rows, 'amount')), 2);
    if (abs($sum - $net) > 0.005) return [[], 'The instalments add up to ' . ci_money($sum) . ' but the fee after discount is ' . ci_money($net) . '.'];
    return [$rows, null];
}

function ci_plan_badge(string $state): string
{
    $m = ['paid' => ['Paid', 'ci-b-green'], 'part' => ['Part paid', 'ci-b-blue'], 'late' => ['Late', 'ci-b-red'], 'due' => ['Upcoming', 'ci-b-grey']];
    [$l, $c] = $m[$state] ?? [$state, 'ci-b-grey'];
    return '<span class="ci-badge ' . $c . '">' . $l . '</span>';
}

/** WhatsApp link for an Indian mobile number. */
function ci_wa(?string $mobile, string $text = ''): string
{
    $d = preg_replace('/\D+/', '', (string)$mobile);
    if (strlen($d) === 10) $d = '91' . $d;
    return $d ? 'https://wa.me/' . $d . ($text !== '' ? '?text=' . rawurlencode($text) : '') : '';
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

/** The student's photo, or a grey profile picture when there is none. */
function ci_avatar(array $s, int $size = 40): string
{
    $url = ci_photo_url($s['photo'] ?? '');
    if ($url) return '<img class="ci-av" src="' . ci_e($url) . '" alt="" width="' . $size . '" height="' . $size . '" style="width:' . $size . 'px;height:' . $size . 'px">';
    // no photo: the usual grey profile picture
    return '<span class="ci-av ci-av-none" role="img" aria-label="No photo" style="width:' . $size . 'px;height:' . $size . 'px"><svg viewBox="0 0 64 64" width="100%" height="100%" aria-hidden="true">'
        . '<rect width="64" height="64" fill="#c9ced6"/><circle cx="32" cy="25" r="12" fill="#fff"/><path d="M8 64c1.6-13 11.6-21 24-21s22.4 8 24 21z" fill="#fff"/></svg></span>';
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

/** The site's header brand (Customize → Site Identity = Settings → General): logo (may be empty), site title, tagline. */
function ci_brand(): array
{
    if (!function_exists('th') && defined('INCLUDES_PATH') && is_file(INCLUDES_PATH . '/theme.php')) require_once INCLUDES_PATH . '/theme.php';
    $th = function_exists('th') ? fn($k) => trim((string)th($k)) : fn($k) => '';
    $g = function_exists('edu_settings') ? (array)edu_settings('general') : [];
    return [
        'title' => function_exists('edu_site_name') ? edu_site_name() : 'Institute',
        'line' => trim((string)($g['tagline'] ?? '')) ?: trim((string)($g['address'] ?? '')),
        'emblem' => trim((string)($g['logo'] ?? '')), // empty = no logo
    ];
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
        'brand' => ci_brand(),
    ];
}

/** Page head extras shared by the institute pages. */
function ci_head(): void
{
    $v = @filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/institute.css') ?: 1;
    echo '<link rel="stylesheet" href="/assets/css/institute.css?v=' . $v . '">' . "\n";
    echo '<script src="/assets/js/ci-ui.js?v=' . (@filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/ci-ui.js') ?: 1) . '" defer></script>' . "\n";
}

/** Opens an institute admin page: guard + tables. $need = permissions of which the user needs at least one (none = any). */
function ci_page_start(PDO $pdo, array $need = []): array
{
    $me = admin_guard(['admin', 'editor', 'author', 'teacher', 'accountant']);
    $ok = $need ? (bool)array_filter($need, fn($k) => ci_perm($me, $k)) : ci_can($me);
    if (!$ok) { http_response_code(403); exit('Access Denied'); }
    ci_tables($pdo);
    return $me;
}

/**
 * Adds a student to a course (and batch): fee type, the fee for this student (below the course fee = discount),
 * next due date and the payment made now (parts = [[mode, amount], …]). Returns [enrollment id, payment id|null] or an error.
 */
function ci_enroll(PDO $pdo, int $sid, int $courseId, ?int $batchId, string $type, ?float $final, ?string $due, array $parts, string $date, int $userId)
{
    $c = $pdo->prepare("SELECT * FROM ci_courses WHERE id = ?");
    $c->execute([$courseId]);
    $course = $c->fetch(PDO::FETCH_ASSOC);
    if (!$course) return 'Choose a course.';
    $session = null;
    if ($batchId) {
        $b = $pdo->prepare("SELECT * FROM ci_batches WHERE id = ?");
        $b->execute([$batchId]);
        $batch = $b->fetch(PDO::FETCH_ASSOC);
        if (!$batch || (int)$batch['course_id'] !== $courseId) return 'The chosen batch does not belong to this course.';
        if ($batch['status'] !== 'active') return 'This batch is not active.';
        if ((int)$batch['capacity']) {
            $n = $pdo->prepare("SELECT COUNT(*) FROM ci_enrollments WHERE batch_id = ? AND status = 'active'");
            $n->execute([$batchId]);
            if ((int)$n->fetchColumn() >= (int)$batch['capacity']) return 'Batch ' . $batch['name'] . ' is full (' . (int)$batch['capacity'] . ' seats).';
        }
        $dupe = $pdo->prepare("SELECT COUNT(*) FROM ci_enrollments WHERE student_id = ? AND batch_id = ? AND status = 'active'");
        $dupe->execute([$sid, $batchId]);
        if ((int)$dupe->fetchColumn()) return 'The student is already in this batch.';
        $session = $batch['session_id'] ? (int)$batch['session_id'] : null;
    }
    $session ??= ci_current_session($pdo);
    $type = $type === 'installment' ? 'installment' : 'full';
    $list = (float)$course['fee'];
    $final = $final === null ? $list : max(0, $final);
    $pay = round(array_sum(array_column($parts, 1)), 2);
    if ($pay > $final + 0.004) return 'The amount paid now is more than the fee (' . ci_money($final) . ').';
    $pdo->prepare("INSERT INTO ci_enrollments (student_id, course_id, batch_id, session_id, fee, discount, fee_type, due_date, enrolled_on, status) VALUES (?,?,?,?,?,?,?,?,?,?)")
        ->execute([$sid, $courseId, $batchId, $session, max($list, $final), max(0, round($list - $final, 2)), $type, $type === 'installment' ? $due : null, $date, 'active']);
    $eid = (int)$pdo->lastInsertId();
    $pid = null;
    if ($pay > 0) {
        $res = ci_add_payment($pdo, $eid, $pay, $parts, min($date, date('Y-m-d')), $type === 'installment' ? 'Installment 1' : 'Course fee', $userId);
        if (is_string($res)) return $res;
        $pid = $res[0];
    }
    ci_type_sync($pdo, $sid); // an "Online" student joining a batch becomes "Both"
    return [$eid, $pid];
}

/**
 * Everything that belongs to one academic session (for "delete session"):
 *   batches of the session; enrollments in the session or in those batches (+ their payments and kists);
 *   students whose every course is in that list (students who also study in another session or batch are kept —
 *   only their courses of this session go); courses whose every batch and every student course are in that list.
 */
function ci_session_plan(PDO $pdo, int $sid): array
{
    $ids = fn(string $sql, array $a = []) => (function () use ($pdo, $sql, $a) { $q = $pdo->prepare($sql); $q->execute($a); return array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN)); })();
    $in = fn(array $x) => $x ? implode(',', $x) : '0';
    $batches = $ids("SELECT id FROM ci_batches WHERE session_id = ?", [$sid]);
    $enr = $ids("SELECT id FROM ci_enrollments WHERE session_id = ? OR batch_id IN (" . $in($batches) . ")", [$sid]);
    $touched = $ids("SELECT DISTINCT student_id FROM ci_enrollments WHERE id IN (" . $in($enr) . ")");
    $keep = $touched ? $ids("SELECT DISTINCT student_id FROM ci_enrollments WHERE student_id IN (" . $in($touched) . ") AND id NOT IN (" . $in($enr) . ")") : [];
    $students = array_values(array_diff($touched, $keep));
    $cands = $ids("SELECT DISTINCT course_id FROM ci_batches WHERE id IN (" . $in($batches) . ") UNION SELECT DISTINCT course_id FROM ci_enrollments WHERE id IN (" . $in($enr) . ")");
    $courses = [];
    foreach ($cands as $c) {
        $otherB = (int)$pdo->query("SELECT COUNT(*) FROM ci_batches WHERE course_id = $c AND id NOT IN (" . $in($batches) . ")")->fetchColumn();
        $otherE = (int)$pdo->query("SELECT COUNT(*) FROM ci_enrollments WHERE course_id = $c AND id NOT IN (" . $in($enr) . ")")->fetchColumn();
        if (!$otherB && !$otherE) $courses[] = $c;
    }
    $pay = $pdo->query("SELECT COUNT(*), COALESCE(SUM(amount),0) FROM ci_payments WHERE enrollment_id IN (" . $in($enr) . ")")->fetch(PDO::FETCH_NUM);
    return ['batches' => $batches, 'enrollments' => $enr, 'students' => $students, 'kept' => $keep, 'courses' => $courses, 'payments' => (int)$pay[0], 'paid' => (float)$pay[1]];
}

/** Deletes a session and everything in ci_session_plan() — in one transaction. Returns the plan that was carried out. */
function ci_session_purge(PDO $pdo, int $sid): array
{
    $plan = ci_session_plan($pdo, $sid);
    $in = fn(array $x) => $x ? implode(',', $x) : '0';
    $photos = $plan['students'] ? $pdo->query("SELECT photo FROM ci_students WHERE id IN (" . $in($plan['students']) . ") AND photo IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) : [];
    $pdo->beginTransaction();
    try {
        $pdo->exec("DELETE FROM ci_payments WHERE enrollment_id IN (" . $in($plan['enrollments']) . ")");
        $pdo->exec("DELETE FROM ci_installments WHERE enrollment_id IN (" . $in($plan['enrollments']) . ")");
        $pdo->exec("DELETE FROM ci_enrollments WHERE id IN (" . $in($plan['enrollments']) . ")");
        if ($plan['students']) {
            $pdo->exec("DELETE FROM ci_payments WHERE student_id IN (" . $in($plan['students']) . ")"); // nothing should be left, but never keep orphans
            $pdo->exec("DELETE FROM ci_class_history WHERE student_id IN (" . $in($plan['students']) . ")");
            $pdo->exec("DELETE FROM ci_students WHERE id IN (" . $in($plan['students']) . ")");
        }
        $pdo->exec("DELETE FROM ci_batches WHERE id IN (" . $in($plan['batches']) . ")");
        $pdo->exec("DELETE FROM ci_courses WHERE id IN (" . $in($plan['courses']) . ")");
        $pdo->exec("UPDATE ci_class_history SET session_id = NULL WHERE session_id = " . (int)$sid);
        $pdo->prepare("DELETE FROM ci_sessions WHERE id = ?")->execute([$sid]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    foreach ($photos as $ph) ci_delete_photo($ph);
    return $plan;
}

/** Batch finished: the batch and its students' active courses in it become "completed" (dues stay as they are). */
function ci_complete_batch(PDO $pdo, int $bid): int
{
    $pdo->prepare("UPDATE ci_batches SET status = 'completed', end_date = CURDATE() WHERE id = ?")->execute([$bid]); // ends today
    $st = $pdo->prepare("UPDATE ci_enrollments SET status = 'completed', ended_on = CURDATE() WHERE batch_id = ? AND status = 'active'");
    $st->execute([$bid]);
    return $st->rowCount();
}

/** Course finished: the course, every batch of it and every active enrollment in it become "completed". */
function ci_complete_course(PDO $pdo, int $cid): void
{
    $pdo->prepare("UPDATE ci_courses SET status = 'completed' WHERE id = ?")->execute([$cid]);
    $b = $pdo->prepare("SELECT id FROM ci_batches WHERE course_id = ? AND status <> 'completed'");
    $b->execute([$cid]);
    foreach ($b->fetchAll(PDO::FETCH_COLUMN) as $bid) ci_complete_batch($pdo, (int)$bid);
    $pdo->prepare("UPDATE ci_enrollments SET status = 'completed', ended_on = CURDATE() WHERE course_id = ? AND status = 'active'")->execute([$cid]);
}

/** Small square icon button/link: ci_ico('edit', '/url', 'Edit') or ci_ico('trash', null, 'Delete', 'submit'). */
function ci_svg(string $n): string
{
    static $p = [
        'refresh' => '<path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/>',
        'ban' => '<circle cx="12" cy="12" r="9"/><path d="m5.7 5.7 12.6 12.6"/>',
        'unlink' => '<path d="M9 17H7A5 5 0 0 1 7 7h2"/><path d="M15 7h2a5 5 0 0 1 4 8"/><path d="M8 12h4"/><path d="m2 2 20 20"/>',
        'edit' => '<path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
        'trash' => '<path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/>',
        'eye' => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
        'user-plus' => '<path d="M15 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/>',
        'book-plus' => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20V3H6.5A2.5 2.5 0 0 0 4 5.5z"/><path d="M12 7v6M9 10h6"/>',
        'check' => '<path d="M20 6 9 17l-5-5"/>',
        'flag' => '<path d="M4 22V4"/><path d="M4 4h13l-2 4 2 4H4"/>',
        'pdf' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M9 15h6M9 18h4"/>',
        'rupee' => '<path d="M6 3h12M6 8h12M6 13l8.5 8M6 13h3a5 5 0 0 0 0-10"/>',
        'receipt' => '<path d="M4 2v20l3-2 3 2 3-2 3 2 3-2 1 .7V2l-1 .7-3-2-3 2-3-2-3 2-3-2z"/><path d="M8 7h8M8 11h8M8 15h5"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
        'up' => '<path d="M12 19V5"/><path d="m5 12 7-7 7 7"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'film' => '<rect x="2" y="3" width="20" height="18"/><path d="M7 3v18M17 3v18M2 9h5M2 15h5M17 9h5M17 15h5"/>',
        'down' => '<path d="M12 5v14"/><path d="m19 12-7 7-7-7"/>',
        'print' => '<path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M6 14h12v8H6z"/>',
        'whatsapp' => '<path d="M3 21l1.7-5A8.5 8.5 0 1 1 8 19.3z"/>',
        'phone' => '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8.1 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2z"/>',
    ];
    return '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($p[$n] ?? '') . '</svg>';
}

function ci_ico(string $icon, ?string $href, string $label, string $kind = 'link', string $extra = ''): string
{
    $cls = 'ci-ico' . ($icon === 'trash' || $icon === 'logout' ? ' ci-ico-danger' : '') . ($icon === 'check' || $icon === 'rupee' ? ' ci-ico-ok' : '');
    // delete needs no hover label (the bin says it); every other icon gets one
    $a = ' aria-label="' . ci_e($label) . '" data-i="' . $icon . '" class="' . $cls . '"' . $extra; // label on hover only in table Action columns (admin-display.js)
    if ($kind === 'submit') return '<button type="submit"' . $a . '>' . ci_svg($icon) . '</button>';
    if ($kind === 'button') return '<button type="button"' . $a . '>' . ci_svg($icon) . '</button>';
    return '<a href="' . ci_e((string)$href) . '"' . $a . '>' . ci_svg($icon) . '</a>';
}

/** A one-button POST form (with CSRF and an optional confirm text). */
function ci_post_ico(string $icon, string $label, array $fields, string $confirm = '', string $action = ''): string
{
    $h = '<form method="post"' . ($action !== '' ? ' action="' . ci_e($action) . '"' : '') . ' class="ci-ico-form"' . ($confirm !== '' ? ' onsubmit="return confirm(' . ci_e(json_encode($confirm)) . ')"' : '') . '>' . csrf_field();
    foreach ($fields as $k => $v) $h .= '<input type="hidden" name="' . ci_e($k) . '" value="' . ci_e($v) . '">';
    return $h . ci_ico($icon, null, $label, 'submit') . '</form>';
}

/** Script tags for the PDF reports (jsPDF + AutoTable + the report layout). */
function ci_report_assets(): void
{
    $v = @filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/ci-report.js') ?: 1;
    echo '<script src="/assets/js/vendor/jspdf.umd.min.js" defer></script><script src="/assets/js/vendor/jspdf.plugin.autotable.min.js" defer></script>'
        . '<script src="/assets/js/ci-report.js?v=' . $v . '" defer></script>' . "\n";
}

}

/** The next roll number in a class (highest number + 1). */
function ci_next_roll(PDO $pdo, ?int $classId, int $exceptId = 0): ?string
{
    if (!$classId) return null;
    $q = $pdo->prepare("SELECT MAX(CAST(roll_no AS UNSIGNED)) FROM ci_students WHERE class_id = ? AND id <> ? AND roll_no REGEXP '^[0-9]+$'");
    $q->execute([$classId, $exceptId]);
    return (string)((int)$q->fetchColumn() + 1);
}

/** Minutes after check-in before a second scan counts as check-out (Attendance → Settings). */
function ci_att_min_out(): int
{
    return max(0, min(720, (int)(function_exists('es') ? es('institute', 'att_min_out', 60) : 60)));
}

/** Code 128 (set B) barcode as an inline SVG — what a USB / Bluetooth barcode scanner reads back as text. */
function ci_barcode_svg(string $text, int $height = 40, float $module = 1.4): string
{
    static $P = ['212222','222122','222221','121223','121322','131222','122213','122312','132212','221213','221312','231212','112232','122132','122231','113222','123122','123221','223211','221132',
        '221231','213212','223112','312131','311222','321122','321221','312212','322112','322211','212123','212321','232121','111323','131123','131321','112313','132113','132311','211313',
        '231113','231311','112133','112331','132131','113123','113321','133121','313121','211331','231131','213113','213311','213131','311123','311321','331121','312113','312311','332111',
        '314111','221411','431111','111224','111422','121124','121421','141122','141221','112214','112412','122114','122411','142112','142211','241211','221114','413111','241112','134111',
        '111242','121142','121241','114212','124112','124211','411212','421112','421211','212141','214121','412121','111143','111341','131141','114113','114311','411113','411311','113141',
        '114131','311141','411131','211412','211214','211232','2331112'];
    $text = preg_replace('/[^\x20-\x7E]/', '', $text);
    $codes = [104]; $sum = 104;
    foreach (str_split($text) as $i => $ch) { $v = ord($ch) - 32; $codes[] = $v; $sum += ($i + 1) * $v; }
    $codes[] = $sum % 103; $codes[] = 106;
    $x = 10 * $module; $bars = '';
    foreach ($codes as $c) {
        foreach (str_split($P[$c]) as $k => $w) {
            $wd = (int)$w * $module;
            if ($k % 2 === 0) $bars .= '<rect x="' . round($x, 2) . '" y="0" width="' . round($wd, 2) . '" height="' . $height . '"/>';
            $x += $wd;
        }
    }
    $w = round($x + 10 * $module, 2);
    return '<svg class="ci-barcode" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $w . ' ' . $height . '" width="' . $w . '" height="' . $height . '" role="img" aria-label="Barcode ' . htmlspecialchars($text, ENT_QUOTES) . '" shape-rendering="crispEdges"><rect width="100%" height="100%" fill="#fff"/><g fill="#000">' . $bars . '</g></svg>';
}

/**
 * One scan of an ID card (or a typed admission ID) on the attendance page: the first scan of the day checks the student in
 * (present), a scan after the set minutes checks them out. $batch limits it to one batch; otherwise every active batch of
 * the student. Returns ['ok' => bool, 'msg' => text, 'student' => row, 'kind' => in|out|wait|done|error].
 */
function ci_att_scan(PDO $pdo, string $code, int $batch, int $userId): array
{
    $code = trim($code);
    if ($code === '') return ['ok' => false, 'kind' => 'error', 'msg' => 'Scan an ID card or type the admission ID.'];
    $q = $pdo->prepare("SELECT s.*, c.name AS class_name FROM ci_students s LEFT JOIN ci_classes c ON c.id = s.class_id WHERE s.reg_no = ? AND s.stype <> 'online' LIMIT 1");
    $q->execute([$code]);
    $s = $q->fetch(PDO::FETCH_ASSOC);
    if (!$s && ctype_digit($code)) { $q->execute([str_pad($code, 6, '0', STR_PAD_LEFT)]); $s = $q->fetch(PDO::FETCH_ASSOC); } // "17" → "000017"
    if (!$s) return ['ok' => false, 'kind' => 'error', 'msg' => 'No institute student with admission ID ' . $code . '.'];
    if ($s['status'] !== 'active') return ['ok' => false, 'kind' => 'error', 'student' => $s, 'msg' => $s['name'] . ' is not an active student (' . $s['status'] . ').'];
    $bq = $pdo->prepare("SELECT DISTINCT e.batch_id FROM ci_enrollments e JOIN ci_batches b ON b.id = e.batch_id WHERE e.student_id = ? AND e.status = 'active' AND b.status = 'active'");
    $bq->execute([(int)$s['id']]);
    $mine = array_map('intval', $bq->fetchAll(PDO::FETCH_COLUMN));
    if ($batch) {
        if (!in_array($batch, $mine, true)) return ['ok' => false, 'kind' => 'error', 'student' => $s, 'msg' => $s['name'] . ' is not in this batch.'];
        $list = [$batch];
    } else $list = $mine ?: [0];
    $today = date('Y-m-d'); $now = date('Y-m-d H:i:s'); $min = ci_att_min_out(); $kind = 'done'; $msg = '';
    $hols = ci_holidays_between($pdo, $today, $today);
    if ($hols) {
        $cq = $pdo->prepare("SELECT course_id FROM ci_batches WHERE id = ?");
        $list = array_values(array_filter($list, function ($b) use ($hols, $today, $cq) { $cq->execute([$b]); return !ci_holiday_on($hols, $today, $b, (int)$cq->fetchColumn()); }));
        if (!$list) return ['ok' => false, 'kind' => 'error', 'student' => $s, 'msg' => 'Today is a holiday for ' . $s['name'] . "'s batch."];
    }
    $get = $pdo->prepare("SELECT * FROM ci_attendance WHERE student_id = ? AND batch_id = ? AND att_date = ?");
    foreach ($list as $b) {
        $get->execute([(int)$s['id'], $b, $today]);
        $r = $get->fetch(PDO::FETCH_ASSOC);
        if (!$r || !$r['in_at']) {
            $pdo->prepare("INSERT INTO ci_attendance (student_id, batch_id, att_date, status, in_at, source, marked_by) VALUES (?,?,?,'present',?, 'scan', ?)
                ON DUPLICATE KEY UPDATE status = 'present', in_at = VALUES(in_at), source = 'scan', marked_by = VALUES(marked_by), updated_at = NOW()")->execute([(int)$s['id'], $b, $today, $now, $userId]);
            $kind = 'in'; $msg = 'Checked in at ' . date('h:i A');
        } elseif (!$r['out_at']) {
            $open = strtotime($r['in_at']) + $min * 60;
            if (time() >= $open) {
                $pdo->prepare("UPDATE ci_attendance SET out_at = ?, updated_at = NOW() WHERE id = ?")->execute([$now, (int)$r['id']]);
                $kind = 'out'; $msg = 'Checked out at ' . date('h:i A');
            } elseif ($kind !== 'in') { $kind = 'wait'; $msg = 'Already checked in at ' . date('h:i A', strtotime($r['in_at'])) . '. Check-out opens at ' . date('h:i A', $open) . ' (after ' . $min . ' min).'; }
        } elseif ($kind === 'done') $msg = 'Already checked out at ' . date('h:i A', strtotime($r['out_at'])) . '.';
    }
    return ['ok' => $kind === 'in' || $kind === 'out', 'kind' => $kind, 'msg' => $msg, 'student' => $s];
}

/** "7:00–8:30 AM" / "4:00 PM - 5:30 PM" / "6–8 AM" → ['07:00:00', '08:30:00'], or null. */
function ci_parse_timing(string $t): ?array
{
    if (!preg_match('/(\d{1,2})(?:[:.](\d{2}))?\s*(am|pm)?\s*(?:–|—|-|to)\s*(\d{1,2})(?:[:.](\d{2}))?\s*(am|pm)?/i', $t, $m)) return null;
    $ap2 = strtolower($m[6] ?? ''); $ap1 = strtolower($m[3] ?? '') ?: $ap2;
    $h = function (int $h, string $ap) { if ($ap === 'pm' && $h < 12) $h += 12; if ($ap === 'am' && $h === 12) $h = 0; return $h; };
    $a = $h((int)$m[1], $ap1); $b = $h((int)$m[4], $ap2);
    if ($a > $b && !($m[3] ?? '') && $ap2 === 'pm') $a -= 12; // "11:30–1:00 PM": the start is in the morning
    if ($a < 0 || $a > 23 || $b > 23) return null;
    return [sprintf('%02d:%02d:00', $a, (int)($m[2] ?? 0)), sprintf('%02d:%02d:00', $b, (int)($m[5] ?? 0))];
}

/** "07:00:00", "08:30:00" → "7:00 AM – 8:30 AM". */
function ci_time_range(?string $a, ?string $b): string
{
    if (!$a || !$b) return '';
    return date('g:i A', strtotime($a)) . ' – ' . date('g:i A', strtotime($b));
}

/** Holidays touching [$from, $to] (each: title, from_date, to_date, course_id, batch_id). */
function ci_holidays_between(PDO $pdo, string $from, string $to): array
{
    $q = $pdo->prepare("SELECT * FROM ci_holidays WHERE from_date <= ? AND to_date >= ? ORDER BY from_date");
    $q->execute([$to, $from]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

/** The holiday (its title) on $date for a batch of a course, from a list of ci_holidays_between, or null. */
function ci_holiday_on(array $hols, string $date, int $batchId, int $courseId): ?string
{
    foreach ($hols as $h) {
        if ($date < $h['from_date'] || $date > $h['to_date']) continue;
        if ($h['batch_id'] ? (int)$h['batch_id'] === $batchId : (!$h['course_id'] || (int)$h['course_id'] === $courseId)) return $h['title'];
    }
    return null;
}

/**
 * Closes the attendance of past class times: in a batch whose attendance was taken on a day (at least one student present),
 * every student of the batch who was not marked becomes Absent once the class time is over (today after its end time, any
 * earlier day) — except on that batch's holidays. Runs when attendance is opened / reported, for [$from, $to].
 */
function ci_att_autoclose(PDO $pdo, string $from, string $to): int
{
    $today = date('Y-m-d'); if ($to > $today) $to = $today;
    if ($from > $to) return 0;
    $q = $pdo->prepare("SELECT a.batch_id, a.att_date, b.course_id, b.end_time FROM ci_attendance a JOIN ci_batches b ON b.id = a.batch_id
        WHERE a.att_date BETWEEN ? AND ? AND a.batch_id > 0 GROUP BY a.batch_id, a.att_date, b.course_id, b.end_time
        HAVING SUM(a.status = 'present') > 0 AND SUM(a.status = 'holiday') = 0");
    $q->execute([$from, $to]);
    $hols = ci_holidays_between($pdo, $from, $to);
    $ins = $pdo->prepare("INSERT IGNORE INTO ci_attendance (student_id, batch_id, att_date, status, source)
        SELECT DISTINCT e.student_id, e.batch_id, ?, 'absent', 'auto' FROM ci_enrollments e JOIN ci_students s ON s.id = e.student_id
        WHERE e.batch_id = ? AND e.status = 'active' AND s.status = 'active' AND s.stype <> 'online' AND (e.enrolled_on IS NULL OR e.enrolled_on <= ?)");
    $n = 0;
    foreach ($q as $r) {
        if ($r['att_date'] === $today && (!$r['end_time'] || date('H:i:s') < $r['end_time'])) continue; // class not over yet
        if (ci_holiday_on($hols, $r['att_date'], (int)$r['batch_id'], (int)$r['course_id'])) continue;
        $ins->execute([$r['att_date'], (int)$r['batch_id'], $r['att_date']]); $n += $ins->rowCount();
    }
    return $n;
}

/**
 * One student's attendance by day for [$from, $to]: [date => ['status' => present|absent|leave|holiday, 'title', 'in', 'out', 'batch']].
 * Marked days come from ci_attendance (present beats leave beats absent across batches); holidays of the student's batches
 * fill the days with no mark. Counts: present, absent, leave, holiday, days (= present + absent + leave), pct.
 */
function ci_att_student(PDO $pdo, int $sid, string $from, string $to): array
{
    ci_att_autoclose($pdo, $from, $to);
    $q = $pdo->prepare("SELECT a.att_date, a.status, a.in_at, a.out_at, b.name AS batch FROM ci_attendance a LEFT JOIN ci_batches b ON b.id = a.batch_id
        WHERE a.student_id = ? AND a.att_date BETWEEN ? AND ? ORDER BY a.att_date");
    $q->execute([$sid, $from, $to]);
    $rank = ['present' => 4, 'holiday' => 3, 'leave' => 2, 'absent' => 1];
    $days = [];
    foreach ($q as $r) {
        $d = $r['att_date']; $cur = $days[$d] ?? null;
        if (!$cur || $rank[$r['status']] > $rank[$cur['status']]) $days[$d] = ['status' => $r['status'], 'title' => '', 'in' => $r['in_at'], 'out' => $r['out_at'], 'batch' => $r['batch']];
        elseif ($r['in_at'] && !$cur['in']) $days[$d]['in'] = $r['in_at'];
    }
    // holidays of the student's batches / courses (only where nothing is marked)
    $bq = $pdo->prepare("SELECT DISTINCT COALESCE(e.batch_id, 0) AS b, e.course_id AS c FROM ci_enrollments e WHERE e.student_id = ? AND e.status <> 'dropped'");
    $bq->execute([$sid]);
    $mine = $bq->fetchAll(PDO::FETCH_ASSOC);
    $hols = ci_holidays_between($pdo, $from, $to);
    if ($hols && $mine) for ($t = strtotime($from); $t <= strtotime($to); $t += 86400) {
        $d = date('Y-m-d', $t); if (isset($days[$d])) continue;
        foreach ($mine as $m) if ($title = ci_holiday_on($hols, $d, (int)$m['b'], (int)$m['c'])) { $days[$d] = ['status' => 'holiday', 'title' => $title, 'in' => null, 'out' => null, 'batch' => null]; break; }
    }
    ksort($days);
    $c = ['present' => 0, 'absent' => 0, 'leave' => 0, 'holiday' => 0];
    foreach ($days as $x) $c[$x['status']]++;
    $c['days'] = $c['present'] + $c['absent'] + $c['leave'];
    $c['pct'] = $c['days'] ? (int)round($c['present'] / $c['days'] * 100) : null;
    return ['days' => $days, 'count' => $c];
}

