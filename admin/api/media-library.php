<?php
// Media Library for the post editor: lists uploaded images (newest first, 40 per page, search by file name,
// alt text or title; ?folder=logos = job card logos only) and saves an image's alt text. Nothing here deletes files.
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin', 'editor', 'author'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

$imageWhere = "(file_type IN ('image','banner') OR file_path REGEXP '\\\\.(webp|jpe?g|png|gif|avif)$')";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id  = (int)($_POST['id'] ?? 0);
    $alt = mb_substr(trim((string)($_POST['alt_text'] ?? '')), 0, 200);
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing image']);
        exit;
    }
    $pdo->prepare("UPDATE media SET alt_text = ? WHERE id = ?")->execute([$alt, $id]);
    echo json_encode(['ok' => true]);
    exit;
}

$page   = max(1, (int)($_GET['page'] ?? 1));
$per    = 36;
$q      = trim((string)($_GET['q'] ?? ''));
$params = [];
$where  = $imageWhere;
// ?folder=logos → only the job card logos
if (($_GET['folder'] ?? '') === 'logos') $where .= " AND file_path LIKE 'uploads/logos/%'";
if ($q !== '') {
    $where   .= " AND (file_path LIKE ? OR alt_text LIKE ? OR title LIKE ?)";
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}
$total = $pdo->prepare("SELECT COUNT(*) FROM media WHERE $where");
$total->execute($params);
$count = (int)$total->fetchColumn();

$stmt = $pdo->prepare("SELECT id, file_path, file_type, alt_text, title, uploaded_at, responsive_set FROM media WHERE $where ORDER BY id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
$stmt->execute($params);
$items = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
    $set   = json_decode($m['responsive_set'] ?? '', true) ?: [];
    $thumb = $set['sm'] ?? $set['md'] ?? $m['file_path'];
    $items[] = [
        'id'    => (int)$m['id'],
        'path'  => '/' . ltrim($m['file_path'], '/'),
        'thumb' => '/' . ltrim($thumb, '/'),
        'alt'   => (string)($m['alt_text'] ?? ''),
        'title' => (string)($m['title'] ?? ''),
        'type'  => $m['file_type'],
        'date'  => $m['uploaded_at'],
    ];
}
echo json_encode(['items' => $items, 'page' => $page, 'pages' => max(1, (int)ceil($count / $per)), 'total' => $count]);
