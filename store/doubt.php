<?php
// Doubts & problems of a lecture (JSON). GET l=ID → the list; POST l, q → ask. Only students who have the course (or a
// staff preview) see the tab. Doubts are private: a student sees only their own (staff see all). Open talk is the Discussion tab.
require_once dirname(__DIR__) . '/includes/config.php';
require_once INCLUDES_PATH . '/shop.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
$out = function (array $d, int $c = 200) { http_response_code($c); exit(json_encode($d, JSON_UNESCAPED_UNICODE)); };
$pdo = sh_db();
$u = sh_user();
$lid = (int)($_GET['l'] ?? $_POST['l'] ?? 0);
$q = $pdo->prepare("SELECT id, product_id, doubts_on, title FROM sh_lessons WHERE id = ?"); $q->execute([$lid]);
$L = $q->fetch(PDO::FETCH_ASSOC);
if (!$L) $out(['error' => 'Lecture not found.'], 404);
$staff = sh_staff();
$owner = $u && sh_has_access($pdo, (int)$u['id'], (int)$L['product_id']);
if (!$owner && !$staff) $out(['error' => 'Only students of this course can ask doubts.'], 403);
$uid = $u ? (int)$u['id'] : 0;
$list = function () use ($pdo, $lid, $uid) {
    $q = $pdo->prepare("SELECT d.*, u.name, s.username AS by_user, a.name AS by_name FROM sh_doubts d JOIN sh_users u ON u.id = d.user_id LEFT JOIN users s ON s.id = d.answered_by LEFT JOIN authors a ON a.user_id = d.answered_by
        WHERE d.lesson_id = ? AND d.status <> 'hidden' AND (d.user_id = ? OR ? = 1) ORDER BY d.id DESC LIMIT 100");
    $q->execute([$lid, $uid, sh_staff() ? 1 : 0]);
    return array_map(function ($d) use ($uid) {
        $p = preg_split('/\s+/', trim(preg_replace('/\(.*?\)/', '', (string)$d['name'])));
        return ['id' => (int)$d['id'], 'mine' => (int)$d['user_id'] === $uid, 'who' => trim(($p[0] ?? 'Student') . (isset($p[1]) ? ' ' . mb_substr($p[1], 0, 1) . '.' : '')) ?: 'Student',
            'at' => date('d M Y', strtotime($d['created_at'])), 'q' => $d['question'], 'a' => $d['answer'], 'by' => $d['answer'] !== null && $d['answer'] !== '' ? (trim((string)$d['by_name']) ?: 'Teacher') : ''];
    }, $q->fetchAll(PDO::FETCH_ASSOC));
};
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!$u) $out(['error' => 'Please log in again.'], 401);
    if (!sh_csrf_ok()) $out(['error' => 'This page had expired. Please reload it.'], 400);
    if (!$L['doubts_on']) $out(['error' => 'Doubts are closed for this lecture.'], 403);
    $text = trim(preg_replace("/\r\n?/", "\n", (string)($_POST['q'] ?? '')));
    if (mb_strlen($text) < 5) $out(['error' => 'Please write your doubt.'], 400);
    if (mb_strlen($text) > 2000) $out(['error' => 'Please keep it under 2000 characters.'], 400);
    $n = $pdo->prepare("SELECT COUNT(*) FROM sh_doubts WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)"); $n->execute([$uid]);
    if ((int)$n->fetchColumn() >= 15) $out(['error' => 'You have asked many doubts today. Please wait until tomorrow.'], 429);
    $pdo->prepare("INSERT INTO sh_doubts (lesson_id, product_id, user_id, question) VALUES (?, ?, ?, ?)")->execute([$lid, $L['product_id'], $uid, $text]);
    $out(['ok' => true, 'list' => $list()]);
}
$out(['ok' => true, 'list' => $list()]);
