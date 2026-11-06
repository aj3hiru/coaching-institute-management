<?php
// "Mark as done" for a lesson (JSON).  POST l=lessonId&done=1|0
require_once dirname(__DIR__) . '/includes/config.php';
require_once INCLUDES_PATH . '/shop.php';
header('Content-Type: application/json'); header('Cache-Control: no-store');
$u = sh_user();
if (!$u || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !sh_csrf_ok()) { http_response_code(403); exit('{"error":"Please log in again."}'); }
$pdo = sh_db();
$l = (int)($_POST['l'] ?? 0);
$q = $pdo->prepare("SELECT product_id FROM sh_lessons WHERE id = ?"); $q->execute([$l]);
$pid = (int)$q->fetchColumn();
if (!$pid || !sh_has_access($pdo, (int)$u['id'], $pid)) { http_response_code(403); exit('{"error":"Not allowed"}'); }
if (($_POST['done'] ?? '1') === '1') $pdo->prepare("INSERT INTO sh_progress (user_id, lesson_id, done) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE done = 1, at = NOW()")->execute([$u['id'], $l]);
else $pdo->prepare("DELETE FROM sh_progress WHERE user_id = ? AND lesson_id = ?")->execute([$u['id'], $l]);
echo '{"ok":true}';
