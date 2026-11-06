<?php
// Small JSON lookups for the institute popups (Collect fee / Add to course on list pages).
//   ?a=student&id=N → the student, their class and the courses that still have a due.
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
http_response_code(400);
echo json_encode(['error' => 'Unknown request']);
