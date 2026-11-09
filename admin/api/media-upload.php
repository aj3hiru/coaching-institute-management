<?php
// Uploads one file for the admin (File Manager, Media Library popup, logo, Customizer images, post images…).
// Images become WebP (largest side 1280px, plus 480/768/1024 sizes, original shape) under /uploads/YYYY/MM (or /uploads/site);
// a featured image (purpose=featured, from the post editor's Featured Image box) is cropped to 16:9 and saved
// at exactly 1200×675 (+ 480/768/1024 wide), kept under 100 KB by lowering the quality only as far as needed;
// PDFs go to /files (so their /documents/ID link works); other documents, audio and video to /uploads/files/YYYY/MM.
// The file name is made from the title (or the original name): "ssc-result.webp", then "ssc-result-2.webp", …
// Adds a row to `media` and returns {id, path, thumb, alt, title, type}.
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once INCLUDES_PATH . '/media.php';
header('Content-Type: application/json; charset=utf-8');

function mu_fail(int $code, string $msg): void
{
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin', 'blog_admin', 'editor', 'author', 'teacher', 'accountant'], true)) mu_fail(403, 'Access denied');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['file'])) mu_fail(400, 'No file received');
$err = (int)$_FILES['file']['error'];
if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) mu_fail(413, 'The file is larger than the server allows (' . ini_get('upload_max_filesize') . ').');
if ($err !== UPLOAD_ERR_OK) mu_fail(400, 'The upload did not finish. Please try again.');

$images = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif', 'image/x-icon' => 'ico', 'image/vnd.microsoft.icon' => 'ico', 'image/svg+xml' => 'svg', 'image/avif' => 'avif'];
$others = [
    'application/pdf' => ['pdf', 'pdf'], 'video/mp4' => ['mp4', 'video'], 'video/webm' => ['webm', 'video'], 'audio/mpeg' => ['mp3', 'audio'], 'audio/mp4' => ['m4a', 'audio'], 'audio/x-m4a' => ['m4a', 'audio'],
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx', 'document'], 'application/msword' => ['doc', 'document'],
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx', 'document'], 'application/vnd.ms-excel' => ['xls', 'document'],
    'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['pptx', 'document'], 'application/zip' => ['zip', 'archive'], 'text/csv' => ['csv', 'document'],
];
$tmp = $_FILES['file']['tmp_name'];
$mime = function_exists('mime_content_type') ? (string)mime_content_type($tmp) : '';
$ext0 = strtolower(pathinfo((string)$_FILES['file']['name'], PATHINFO_EXTENSION));
if ($mime === 'text/xml' || $mime === 'text/plain' || $mime === 'image/svg') {
    $head = (string)file_get_contents($tmp, false, null, 0, 512);
    if (stripos($head, '<svg') !== false) $mime = 'image/svg+xml';
    elseif ($ext0 === 'csv') $mime = 'text/csv';
}
if ($mime === 'application/octet-stream' && $ext0 === 'docx') $mime = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
$isImage = isset($images[$mime]);
if (!$isImage && !isset($others[$mime])) mu_fail(415, 'This file type is not allowed. Upload images, PDF, Word/Excel/PowerPoint, ZIP, MP3 or MP4 files.');
$limit = $isImage ? 15 : 64;
if ($_FILES['file']['size'] > $limit * 1024 * 1024) mu_fail(413, "The file is larger than $limit MB.");
if ($mime === 'image/svg+xml' && preg_match('/<script|on\w+\s*=|javascript:/i', (string)file_get_contents($tmp))) mu_fail(415, 'This SVG contains scripts and was not accepted.');

$title = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 200);
$alt = mb_substr(trim((string)($_POST['alt'] ?? '')), 0, 200);
$base = edu_media_slug($title !== '' ? $title : pathinfo((string)$_FILES['file']['name'], PATHINFO_FILENAME));
if ($title === '') $title = ucfirst(trim(preg_replace('/[-_]+/', ' ', pathinfo((string)$_FILES['file']['name'], PATHINFO_FILENAME))));

if ($isImage) {
    // 'site' = logo / favicon of the site, 'logos' = job card logos (their own library in the post editor)
    $sub = match ($_POST['folder'] ?? '') { 'site' => 'uploads/site', 'logos' => 'uploads/logos', default => 'uploads/' . date('Y/m') };
} else {
    [$ext, $kind] = $others[$mime];
    $sub = $kind === 'pdf' ? 'files' : 'uploads/files/' . date('Y/m');
}
$dir = ROOT_PATH . '/' . $sub;
if (!is_dir($dir) && !mkdir($dir, 0755, true)) mu_fail(500, 'Could not create the upload folder.');

$raster = $isImage && in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/avif'], true) && function_exists('imagewebp');
$outExt = $raster ? 'webp' : ($isImage ? $images[$mime] : $ext);
$base = edu_media_unique_base($dir, $sub, $base, $outExt);
$set = [];
$w = $h = 0;
if ($raster) {
    $src = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($tmp), 'image/png' => @imagecreatefrompng($tmp),
        'image/webp' => @imagecreatefromwebp($tmp), 'image/avif' => function_exists('imagecreatefromavif') ? @imagecreatefromavif($tmp) : false,
    };
    if ($src && $mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $o = (int)(@exif_read_data($tmp)['Orientation'] ?? 1);
        if ($o === 3) $src = imagerotate($src, 180, 0); elseif ($o === 6) $src = imagerotate($src, -90, 0); elseif ($o === 8) $src = imagerotate($src, 90, 0);
    }
    if (!$src) { $raster = false; $outExt = $images[$mime]; }
}
if ($raster) {
    [$path, $set, $w, $h] = edu_media_save_webp($src, $dir, $sub, $base, ($_POST['purpose'] ?? '') === 'featured', ($_POST['purpose'] ?? '') === 'slide' ? 1920 : 1280);
} else {
    $name = $base . '.' . $outExt;
    if (!move_uploaded_file($tmp, $dir . '/' . $name)) mu_fail(500, 'Could not save the file.');
    @chmod($dir . '/' . $name, 0644);
    $path = $sub . '/' . $name;
}
$type = $isImage ? 'image' : $others[$mime][1];
edu_media_columns($pdo);
$pdo->prepare("INSERT INTO media (file_path, responsive_set, file_type, alt_text, title, uploaded_at, uploaded_by) VALUES (?, ?, ?, ?, ?, NOW(), ?)")
    ->execute([$path, $set ? json_encode($set) : null, $type, $alt, $title, (int)$_SESSION['user_id']]);
$__dim = $raster ? [$w, $h] : (($gi = @getimagesize(ROOT_PATH . '/' . $path)) ? [$gi[0], $gi[1]] : [0, 0]);
echo json_encode(['id' => (int)$pdo->lastInsertId(), 'path' => '/' . $path, 'thumb' => '/' . ($set['sm'] ?? $path), 'alt' => $alt, 'title' => $title, 'type' => $type,
    'webp' => $raster, 'size' => (int)@filesize(ROOT_PATH . '/' . $path), 'orig_size' => (int)($_FILES['file']['size'] ?? 0), 'w' => (int)$__dim[0], 'h' => (int)$__dim[1]]);
