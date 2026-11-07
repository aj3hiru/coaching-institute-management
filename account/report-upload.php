<?php
// "Report us" attachment upload in pieces (the server takes only small requests). Logged-in students only.
// Raw body = one piece. Headers: X-Upload, X-Index, X-Total, X-Size, X-Name, X-CSRF. Image (JPG/PNG/WebP) or PDF, under 20 MB.
require_once dirname(__DIR__) . '/includes/config.php';
require_once INCLUDES_PATH . '/shop.php';
header('Content-Type: application/json'); header('Cache-Control: no-store');
$out = function (array $d, int $c = 200) { http_response_code($c); exit(json_encode($d)); };
$u = sh_user();
if (!$u) $out(['error' => 'Please log in again.'], 401);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !sh_csrf_ok()) $out(['error' => 'This page had expired. Please reload it.'], 400);
$h = fn($k) => trim((string)($_SERVER['HTTP_' . $k] ?? ''));
$id = $h('X_UPLOAD'); $idx = (int)$h('X_INDEX'); $total = (int)$h('X_TOTAL'); $size = (int)$h('X_SIZE');
$name = rawurldecode($h('X_NAME')); $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
if ($ext === 'jpeg') $ext = 'jpg';
if (!preg_match('/^[a-f0-9]{16,40}$/', $id) || $total < 1 || $total > 10 || $idx < 0 || $idx >= $total || $size < 1) $out(['error' => 'Bad upload'], 400);
if (!in_array($ext, ['jpg', 'png', 'webp', 'pdf'], true)) $out(['error' => 'Only images (JPG, PNG, WebP) and PDF files can be attached.'], 400);
if ($size > 20 * 1024 * 1024) $out(['error' => 'Please choose a file smaller than 20 MB.'], 400);
$sid = session_id();
$_SESSION['sh_rep_up'] = $_SESSION['sh_rep_up'] ?? [];
session_write_close();
$dir = sh_media_dir() . '/_reports';
if (!is_dir($dir) && !@mkdir($dir, 0750, true)) $out(['error' => 'Could not save the file on the server.'], 500);
$part = "$dir/$id.part";
if ($idx === 0) @unlink($part);
elseif (!is_file($part) || filesize($part) !== $idx * 4 * 1024 * 1024) $out(['error' => 'A piece is missing — please attach the file again.'], 409);
$in = fopen('php://input', 'rb'); $fh = fopen($part, 'ab');
stream_copy_to_stream($in, $fh, 5 * 1024 * 1024); fclose($fh); fclose($in);
clearstatcache(true, $part);
if (filesize($part) > 20 * 1024 * 1024) { @unlink($part); $out(['error' => 'Please choose a file smaller than 20 MB.'], 400); }
if ($idx < $total - 1) $out(['ok' => true]);
if (filesize($part) !== $size) { @unlink($part); $out(['error' => 'The file arrived incomplete. Please attach it again.'], 400); }
$head = (string)file_get_contents($part, false, null, 0, 16);
$ok = $ext === 'pdf' ? str_starts_with($head, '%PDF') : ($ext === 'png' ? str_starts_with($head, "\x89PNG") : ($ext === 'jpg' ? str_starts_with($head, "\xFF\xD8\xFF") : (str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP')));
if (!$ok) { @unlink($part); $out(['error' => 'This file is not a real image or PDF.'], 400); }
$tok = bin2hex(random_bytes(12));
$rel = '_reports/' . date('Ym') . '-' . $tok . '.' . $ext;
@mkdir(dirname(sh_media_path($rel)), 0750, true);
if (!@rename($part, sh_media_path($rel))) { @unlink($part); $out(['error' => 'Could not save the file on the server.'], 500); }
// remember it in the session so only this student can attach it to their report
session_id($sid); session_start();
$_SESSION['sh_rep_up'][$tok] = ['file' => $rel, 'name' => mb_substr($name, 0, 200), 'size' => $size, 'at' => time()];
$_SESSION['sh_rep_up'] = array_slice($_SESSION['sh_rep_up'], -5, null, true);
session_write_close();
$out(['ok' => true, 'done' => true, 'token' => $tok]);
