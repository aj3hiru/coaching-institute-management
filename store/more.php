<?php
// Homepage "View more": the next courses (newest first) as small cards. GET o=offset, n=count (max 24). Public data only.
require_once dirname(__DIR__) . '/includes/config.php';
require_once INCLUDES_PATH . '/shop.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: public, max-age=120');
$pdo = sh_db();
$o = max(0, (int)($_GET['o'] ?? 0)); $n = max(1, min(24, (int)($_GET['n'] ?? 8)));
$w = in_array($_GET['w'] ?? '', ['launched', 'upcoming'], true) ? $_GET['w'] : ''; // same filter as the homepage list
$rows = sh_latest($pdo, 'course', $n + 1, $o, 0, $w);
$cats = sh_categories($pdo, true);
$html = '';
foreach (array_slice($rows, 0, $n) as $p) $html .= sh_mini_card($p, $cats[(int)$p['category_id']]['name'] ?? '');
echo json_encode(['html' => $html, 'count' => min($n, count($rows)), 'more' => count($rows) > $n], JSON_UNESCAPED_UNICODE);
