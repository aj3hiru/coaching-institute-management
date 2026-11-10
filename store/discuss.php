<?php
// Lecture Discussion (JSON) — like comments: every student who has the course, and the teachers / staff, read and write;
// everyone sees everyone's messages. GET l=ID → the list; POST l, m → write; POST l, del=ID → remove your own (staff: any).
// Private questions go to Doubts & Problems instead (store/doubt.php).
require_once dirname(__DIR__) . '/includes/config.php';
require_once INCLUDES_PATH . '/shop.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
$out = function (array $d, int $c = 200) { http_response_code($c); exit(json_encode($d, JSON_UNESCAPED_UNICODE)); };
$pdo = sh_db();
sh_tables($pdo);
$u = sh_user();
$lid = (int)($_GET['l'] ?? $_POST['l'] ?? 0);
$q = $pdo->prepare("SELECT id, product_id FROM sh_lessons WHERE id = ?"); $q->execute([$lid]);
$L = $q->fetch(PDO::FETCH_ASSOC);
if (!$L) $out(['error' => 'Lecture not found.'], 404);
$staffId = sh_staff() ? (int)($_SESSION['user_id'] ?? 0) : 0;
$uid = $u ? (int)$u['id'] : 0;
$owner = $uid && sh_has_access($pdo, $uid, (int)$L['product_id']);
if (!$owner && !$staffId) $out(['error' => 'Only students of this course can see and join the discussion.'], 403);
$short = function (string $n): string { $p = preg_split('/\s+/', trim(preg_replace('/\(.*?\)/', '', $n))); return trim(($p[0] ?? 'Student') . (isset($p[1]) ? ' ' . mb_substr($p[1], 0, 1) . '.' : '')) ?: 'Student'; };
$list = function () use ($pdo, $lid, $uid, $staffId, $short) {
    $q = $pdo->prepare("SELECT d.*, u.name, s.username, a.name AS aname FROM sh_discuss d LEFT JOIN sh_users u ON u.id = d.user_id LEFT JOIN users s ON s.id = d.staff_id
        LEFT JOIN authors a ON a.user_id = d.staff_id WHERE d.lesson_id = ? AND d.status = 'visible' ORDER BY d.id DESC LIMIT 200");
    $q->execute([$lid]);
    return array_map(fn($d) => ['id' => (int)$d['id'], 'teacher' => (bool)$d['staff_id'], 'who' => $d['staff_id'] ? trim((string)($d['aname'] ?: $d['username'])) ?: 'Teacher' : $short((string)$d['name']),
        'mine' => ($uid && (int)$d['user_id'] === $uid) || ($staffId && (int)$d['staff_id'] === $staffId), 'can_del' => ($uid && (int)$d['user_id'] === $uid) || $staffId > 0,
        'at' => date('d M Y, h:i A', strtotime($d['created_at'])), 'm' => $d['body']], $q->fetchAll(PDO::FETCH_ASSOC));
};
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!sh_csrf_ok()) $out(['error' => 'This page had expired. Please reload it.'], 400);
    if (($del = (int)($_POST['del'] ?? 0)) > 0) {
        $pdo->prepare("UPDATE sh_discuss SET status = 'hidden' WHERE id = ? AND lesson_id = ? AND (? > 0 OR (user_id = ? AND user_id IS NOT NULL))")->execute([$del, $lid, $staffId, $uid]);
        $out(['ok' => true, 'list' => $list()]);
    }
    $text = trim(preg_replace("/\r\n?/", "\n", (string)($_POST['m'] ?? '')));
    if (mb_strlen($text) < 2) $out(['error' => 'Please write your message.'], 400);
    if (mb_strlen($text) > 2000) $out(['error' => 'Please keep it under 2000 characters.'], 400);
    if (!$staffId) {
        $n = $pdo->prepare("SELECT COUNT(*) FROM sh_discuss WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)"); $n->execute([$uid]);
        if ((int)$n->fetchColumn() >= 40) $out(['error' => 'You have written many messages today. Please wait until tomorrow.'], 429);
    }
    $pdo->prepare("INSERT INTO sh_discuss (product_id, lesson_id, user_id, staff_id, body) VALUES (?, ?, ?, ?, ?)")
        ->execute([$L['product_id'], $lid, $staffId ? null : $uid, $staffId ?: null, $text]);
    $out(['ok' => true, 'list' => $list()]);
}
$out(['ok' => true, 'list' => $list()]);
