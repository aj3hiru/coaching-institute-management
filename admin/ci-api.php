<?php
// Small JSON lookups for the institute popups (Collect fee / Add to course on list pages).
//   ?a=student&id=N → the student, their class and the courses that still have a due.
//   ?a=search&q=&class=&course=&batch=&in=N → up to 60 active students (name / mobile / email / reg. no. / father), with
//     their class and batches; "in" = a batch they may be added to (already=true when they are in it).
require_once __DIR__ . '/components/guard.php';
require_once INCLUDES_PATH . '/institute.php';
$me = ci_page_start($pdo);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$a = (string)($_GET['a'] ?? '');
$id = (int)($_GET['id'] ?? 0);
if ($a === 'student') {
    $q = $pdo->prepare("SELECT s.id, s.name, s.reg_no, s.class_id, s.status, c.name AS class_name FROM ci_students s LEFT JOIN ci_classes c ON c.id = s.class_id WHERE s.id = ?");
    $q->execute([$id]);
    $s = $q->fetch(PDO::FETCH_ASSOC);
    if (!$s) { http_response_code(404); exit(json_encode(['error' => 'Student not found'])); }
    $payable = [];
    foreach (ci_enrollments($pdo, $id) as $e) {
        if ($e['status'] === 'dropped' || (float)$e['due'] <= 0.004) continue;
        $n = $pdo->prepare("SELECT COUNT(*) FROM ci_payments WHERE enrollment_id = ?");
        $n->execute([$e['id']]);
        $payable[] = ['id' => (int)$e['id'], 'label' => $e['course_name'] . ($e['batch_name'] ? ' · ' . $e['batch_name'] : '') . ' — due ' . ci_money0($e['due']),
            'due' => round((float)$e['due'], 2), 'inst' => ($e['fee_type'] ?? 'full') === 'installment', 'n' => (int)$n->fetchColumn() + 1];
    }
    exit(json_encode(['student' => ['id' => (int)$s['id'], 'name' => $s['name'], 'reg' => $s['reg_no'], 'class_id' => (int)$s['class_id'], 'class' => $s['class_name'], 'status' => $s['status']],
        'payable' => $payable], JSON_UNESCAPED_UNICODE));
}
if ($a === 'search') {
    $w = ["s.status = 'active'"]; $args = [];
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q !== '') { $w[] = '(s.name LIKE ? OR s.mobile LIKE ? OR s.alt_mobile LIKE ? OR s.email LIKE ? OR s.reg_no LIKE ? OR s.father_name LIKE ?)'; array_push($args, ...array_fill(0, 6, '%' . $q . '%')); }
    if ($c = (int)($_GET['class'] ?? 0)) { $w[] = 's.class_id = ?'; $args[] = $c; }
    if ($c = (int)($_GET['course'] ?? 0)) { $w[] = 'EXISTS (SELECT 1 FROM ci_enrollments e WHERE e.student_id = s.id AND e.course_id = ?)'; $args[] = $c; }
    if ($c = (int)($_GET['batch'] ?? 0)) { $w[] = 'EXISTS (SELECT 1 FROM ci_enrollments e WHERE e.student_id = s.id AND e.batch_id = ?)'; $args[] = $c; }
    $in = (int)($_GET['in'] ?? 0);
    $st = $pdo->prepare("SELECT s.id, s.name, s.reg_no, s.mobile, s.email, s.father_name, c.name AS class_name,
        (SELECT GROUP_CONCAT(CONCAT(co.name, IFNULL(CONCAT(' · ', b.name), '')) ORDER BY e.id DESC SEPARATOR '||') FROM ci_enrollments e JOIN ci_courses co ON co.id = e.course_id LEFT JOIN ci_batches b ON b.id = e.batch_id WHERE e.student_id = s.id AND e.status = 'active') AS batches,
        " . ($in ? "(SELECT COUNT(*) FROM ci_enrollments e WHERE e.student_id = s.id AND e.batch_id = $in AND e.status = 'active')" : '0') . " AS already
        FROM ci_students s LEFT JOIN ci_classes c ON c.id = s.class_id WHERE " . implode(' AND ', $w) . " ORDER BY s.name LIMIT 61");
    $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    exit(json_encode(['more' => count($rows) > 60, 'list' => array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'reg' => $r['reg_no'], 'mobile' => $r['mobile'], 'email' => (string)$r['email'],
        'father' => (string)$r['father_name'], 'class' => (string)$r['class_name'], 'batches' => array_values(array_filter(explode('||', (string)$r['batches']))), 'already' => (int)$r['already'] > 0], array_slice($rows, 0, 60))], JSON_UNESCAPED_UNICODE));
}
http_response_code(400);
echo json_encode(['error' => 'Unknown request']);
