<?php
// Chunked upload for store videos / PDFs (the server takes only small requests, so big files come in 4 MB pieces).
// POST body = raw bytes of one piece. Headers: X-Upload (id), X-Index, X-Total, X-Size, X-Name, X-Kind (v = lesson video,
// f = PDF), X-Target (lesson id for v; product id for f), X-Title (PDF title), X-Duration (optional), X-CSRF-Token.
require_once __DIR__ . '/components/guard.php';
require_once INCLUDES_PATH . '/institute.php';
require_once INCLUDES_PATH . '/shop.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$out = function (array $d, int $code = 200) { http_response_code($code); exit(json_encode($d, JSON_UNESCAPED_UNICODE)); };
$me = admin_guard(['admin', 'editor', 'author', 'teacher', 'accountant']);
if (!sh_perm($me, 'products')) $out(['error' => 'Access denied'], 403);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') $out(['error' => 'Invalid request'], 405);
csrf_check();
session_write_close();
sh_tables($pdo);

$h = fn($k) => trim((string)($_SERVER['HTTP_' . $k] ?? ''));
$upId = $h('X_UPLOAD'); $idx = (int)$h('X_INDEX'); $total = (int)$h('X_TOTAL'); $size = (int)$h('X_SIZE');
$name = rawurldecode($h('X_NAME')); $kind = $h('X_KIND'); $target = (int)$h('X_TARGET');
$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
$okExt = $kind === 'v' ? ['mp4', 'm4v', 'webm', 'mov'] : ['pdf']; // v = lesson video; f = PDF of a product; lp = a PDF lecture; ln = notes PDF of a lecture
$maxSize = ($kind === 'v' ? (int)es('shop', 'max_video_mb', 4096) : 300) * 1024 * 1024; // PDFs up to 300 MB
if (!preg_match('/^[a-f0-9]{16,40}$/', $upId) || $total < 1 || $idx < 0 || $idx >= $total || $size < 1) $out(['error' => 'Bad upload request'], 400);
if (!in_array($kind, ['v', 'f', 'lp', 'ln'], true) || !$target) $out(['error' => 'Bad upload request'], 400);
if (!in_array($ext, $okExt, true)) $out(['error' => $kind === 'v' ? 'Please upload an MP4 video (WebM / MOV also accepted).' : 'Please upload a PDF file.'], 400);
if ($size > $maxSize) $out(['error' => 'The file is too large (max ' . round($maxSize / 1048576) . ' MB).'], 400);

// the lesson / product must exist
$qual = preg_match('/^(1080|720|480|360|240)$/', $h('X_QUALITY')) ? $h('X_QUALITY') : ''; // '' = the main (original) video
if ($kind === 'v' || $kind === 'lp' || $kind === 'ln') { $q = $pdo->prepare("SELECT id, product_id, video_file, variants, pdf_file FROM sh_lessons WHERE id = ?"); }
else { $q = $pdo->prepare("SELECT id, id AS product_id FROM sh_products WHERE id = ?"); }
$q->execute([$target]);
$row = $q->fetch(PDO::FETCH_ASSOC);
if (!$row) $out(['error' => $kind === 'f' ? 'Product not found.' : 'Lecture not found.'], 404);

$dir = sh_media_dir();
if (!is_dir($dir) || !is_writable($dir)) $out(['error' => 'The private media folder is not writable: ' . $dir], 500);
$tmpDir = $dir . '/_tmp';
if (!is_dir($tmpDir)) @mkdir($tmpDir, 0750, true);
$part = $tmpDir . '/' . $upId . '.part';
$meta = $tmpDir . '/' . $upId . '.json';
$m = is_file($meta) ? (json_decode((string)file_get_contents($meta), true) ?: []) : [];
if ($idx === 0 && !$m) {
    $free = @disk_free_space($dir);
    if ($free !== false && $free < $size + 300 * 1024 * 1024) $out(['error' => 'Not enough space on the server for this file (' . round($free / 1073741824, 1) . ' GB free).'], 507);
    $m = ['next' => 0, 'size' => $size, 'name' => $name, 'kind' => $kind, 'target' => $target, 'total' => $total];
    @unlink($part);
}
if (!$m || $m['size'] !== $size || $m['kind'] !== $kind || (int)$m['target'] !== $target || (int)$m['total'] !== $total) $out(['error' => 'This upload does not match — please start again.'], 409);
if ($idx < $m['next']) $out(['ok' => true, 'next' => $m['next']]); // a piece sent again after a network error
if ($idx > $m['next']) $out(['error' => 'A piece is missing — retrying.', 'next' => $m['next']], 409);

$in = fopen('php://input', 'rb');
$fh = fopen($part, 'ab');
if (!$in || !$fh) $out(['error' => 'Could not write the file on the server.'], 500);
$got = stream_copy_to_stream($in, $fh, 8 * 1024 * 1024);
fclose($fh); fclose($in);
if (!$got) $out(['error' => 'Empty piece — retrying.', 'next' => $m['next']], 400);
$m['next'] = $idx + 1;
file_put_contents($meta, json_encode($m));
if ($m['next'] < $total) $out(['ok' => true, 'next' => $m['next']]);

// ---- last piece: check the whole file, then keep it ----
clearstatcache(true, $part);
if (filesize($part) !== $size) { @unlink($part); @unlink($meta); $out(['error' => 'The file arrived incomplete. Please upload it again.'], 400); }
$head = (string)file_get_contents($part, false, null, 0, 16);
$looks = $kind !== 'v' ? str_starts_with($head, '%PDF') : (substr($head, 4, 4) === 'ftyp' || str_starts_with($head, "\x1A\x45\xDF\xA3") || in_array(substr($head, 4, 4), ['moov', 'mdat', 'free', 'wide'], true));
if (!$looks) { @unlink($part); @unlink($meta); $out(['error' => $kind !== 'v' ? 'This is not a real PDF file.' : 'This does not look like a video file.'], 400); }
$pid = (int)$row['product_id'];
@mkdir($dir . '/' . $pid, 0750, true);
$rel = $pid . '/' . ($kind === 'v' ? 'v' . $target . ($qual !== '' ? '-' . $qual . 'p' : '') : ($kind === 'lp' ? 'p' . $target : ($kind === 'ln' ? 'n' . $target : 'f'))) . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
if (!@rename($part, $dir . '/' . $rel)) { @unlink($part); @unlink($meta); $out(['error' => 'Could not save the file on the server.'], 500); }
@unlink($meta);
@chmod($dir . '/' . $rel, 0640);
if ($kind === 'v' && $qual !== '') { // an extra quality for the player's Quality menu
    if (empty($row['video_file'])) { @unlink($dir . '/' . $rel); $out(['error' => 'Upload the main video first, then its smaller qualities.'], 400); }
    $vv = sh_variants($row);
    if (isset($vv[$qual]) && $vv[$qual] !== $rel) { $old = sh_media_path($vv[$qual]); if (is_file($old)) @unlink($old); }
    $vv[$qual] = $rel;
    $pdo->prepare("UPDATE sh_lessons SET variants = ? WHERE id = ?")->execute([json_encode($vv), $target]);
    admin_log('shop_upload', "Uploaded the {$qual}p quality for lesson #$target");
    $out(['ok' => true, 'done' => true, 'file' => $rel]);
}
if ($kind === 'v') {
    $ht = (int)$h('X_HEIGHT');
    $dur = preg_match('/^\d{1,3}:\d{2}(:\d{2})?$/', $h('X_DURATION')) ? $h('X_DURATION') : null;
    $pdo->prepare("UPDATE sh_lessons SET video_file = ?, video_url = NULL, size = ?, height = ?" . ($dur ? ", duration = ?" : '') . " WHERE id = ?")
        ->execute($dur ? [$rel, $size, $ht > 0 && $ht < 5000 ? $ht : null, $dur, $target] : [$rel, $size, $ht > 0 && $ht < 5000 ? $ht : null, $target]);
    if (!empty($row['video_file']) && $row['video_file'] !== $rel) { // replaced: the old file and its smaller qualities go
        foreach (array_merge([$row['video_file']], array_values(sh_variants($row))) as $old) { $old = sh_media_path($old); if (is_file($old)) @unlink($old); }
        $pdo->prepare("UPDATE sh_lessons SET variants = NULL WHERE id = ?")->execute([$target]);
    }
    admin_log('shop_upload', "Uploaded a video for lesson #$target (" . round($size / 1048576, 1) . " MB)");
    $out(['ok' => true, 'done' => true, 'file' => $rel]);
}
$countPages = function (string $path) { return preg_match_all('#/Type\s*/Page[^s]#', (string)file_get_contents($path, false, null, 0, 20 * 1024 * 1024), $mm) ? (count($mm[0]) ?: null) : null; };
if ($kind === 'lp') { // the lecture itself is a PDF
    if (!empty($row['pdf_file']) && $row['pdf_file'] !== $rel) { $old = sh_media_path($row['pdf_file']); if (is_file($old)) @unlink($old); }
    $pdo->prepare("UPDATE sh_lessons SET kind = 'pdf', pdf_file = ?, pdf_pages = ?, size = ? WHERE id = ?")->execute([$rel, $countPages($dir . '/' . $rel), $size, $target]);
    admin_log('shop_upload', "Uploaded the PDF of lecture #$target");
    $out(['ok' => true, 'done' => true]);
}
$title = trim(rawurldecode($h('X_TITLE'))) ?: preg_replace('/\.pdf$/i', '', $name);
$pages = null;
if (preg_match_all('#/Type\s*/Page[^s]#', (string)file_get_contents($dir . '/' . $rel, false, null, 0, 20 * 1024 * 1024), $mm)) $pages = count($mm[0]) ?: null;
$sort = (int)$pdo->query("SELECT COALESCE(MAX(sort),0) + 1 FROM sh_files WHERE product_id = $pid")->fetchColumn();
$pdo->prepare("INSERT INTO sh_files (product_id, title, file, size, pages, free, sort, lesson_id) VALUES (?, ?, ?, ?, ?, 0, ?, ?)")->execute([$pid, mb_substr($title, 0, 200), $rel, $size, $pages, $sort, $kind === 'ln' ? $target : null]); // ln = notes of one lecture
admin_log('shop_upload', "Uploaded PDF \"$title\" to store product #$pid");
$out(['ok' => true, 'done' => true, 'id' => (int)$pdo->lastInsertId()]);
