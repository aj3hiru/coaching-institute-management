<?php
// JSON lookups for the Enroll / New order popup (staff with Orders or Customers access).
//   ?a=users&q=     → online accounts (name / mobile / email), up to 30
//   ?a=students&q=  → institute students (name / mobile / reg. no. / father), up to 30, with their online account if any
require_once __DIR__ . '/components/guard.php';
require_once INCLUDES_PATH . '/institute.php';
require_once INCLUDES_PATH . '/shop.php';
$me = sh_page_start($pdo, ['orders', 'customers']);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$a = (string)($_GET['a'] ?? '');
$q = trim((string)($_GET['q'] ?? ''));
$like = '%' . $q . '%';
if ($a === 'users') {
    $st = $pdo->prepare("SELECT id, name, mobile, email, status FROM sh_users WHERE " . ($q !== '' ? "(name LIKE ? OR mobile LIKE ? OR email LIKE ?)" : '1') . " ORDER BY id DESC LIMIT 30");
    $st->execute($q !== '' ? [$like, $like, $like] : []);
    exit(json_encode(['list' => array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'mobile' => $r['mobile'], 'email' => str_ends_with((string)$r['email'], '@noemail.invalid') ? '' : $r['email'], 'blocked' => $r['status'] !== 'active'], $st->fetchAll(PDO::FETCH_ASSOC))], JSON_UNESCAPED_UNICODE));
}
if ($a === 'students') {
    try {
        $st = $pdo->prepare("SELECT s.id, s.name, s.mobile, s.reg_no, s.father_name, c.name AS class_name, (SELECT u.id FROM sh_users u WHERE u.student_id = s.id OR u.mobile = s.mobile LIMIT 1) AS uid
            FROM ci_students s LEFT JOIN ci_classes c ON c.id = s.class_id WHERE s.status = 'active'" . ($q !== '' ? " AND (s.name LIKE ? OR s.mobile LIKE ? OR s.reg_no LIKE ? OR s.father_name LIKE ?)" : '') . " ORDER BY s.name LIMIT 30");
        $st->execute($q !== '' ? [$like, $like, $like, $like] : []);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) { $rows = []; }
    exit(json_encode(['list' => array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'mobile' => $r['mobile'], 'reg' => $r['reg_no'], 'father' => (string)$r['father_name'], 'class' => (string)$r['class_name'], 'uid' => (int)$r['uid']], $rows)], JSON_UNESCAPED_UNICODE));
}
http_response_code(400);
echo json_encode(['error' => 'Unknown request']);
