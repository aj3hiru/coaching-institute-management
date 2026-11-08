<?php
// Sends a lesson video (k=v) or a PDF (k=f) — only with a valid signed link from the learn page, only to the same
// logged-in buyer (or anyone for a free preview), and only while the buyer still has access.
require_once dirname(__DIR__) . '/includes/config.php';
require_once INCLUDES_PATH . '/shop.php';
$k = (string)($_GET['k'] ?? ''); $id = (int)($_GET['i'] ?? 0); $uid = (int)($_GET['u'] ?? 0); $exp = (int)($_GET['e'] ?? 0); $sig = (string)($_GET['s'] ?? ''); $qual = preg_match('/^\d{3,4}$/', (string)($_GET['q'] ?? '')) ? (string)$_GET['q'] : '';
$sessUid = (int)($_SESSION['sh_uid'] ?? 0);
session_write_close(); // a long video must not hold the visitor's session
$deny = function (int $code = 403) { http_response_code($code); header('Content-Type: text/plain'); exit($code === 410 ? 'This link has expired. Please reload the page.' : 'Not allowed'); };
if (!in_array($k, ['v', 'f', 'p'], true) || !$id) $deny(404); // v = lecture video, f = a PDF (course / notes / lecture notes), p = a PDF lecture
if ($exp < time()) $deny(410);
if (!sh_media_check($k, $id, $uid, $exp, $sig, $qual)) $deny();
if ($uid && $uid !== $sessUid) $deny(); // the link only works in the buyer's own logged-in browser
$pdo = sh_db();
if ($k === 'v') { $q = $pdo->prepare("SELECT product_id, free, video_file AS file, variants, title FROM sh_lessons WHERE id = ?"); }
elseif ($k === 'p') { $q = $pdo->prepare("SELECT product_id, free, pdf_file AS file, title FROM sh_lessons WHERE id = ?"); }
else { $q = $pdo->prepare("SELECT f.product_id, COALESCE(l.free, f.free) AS free, f.file, f.title FROM sh_files f LEFT JOIN sh_lessons l ON l.id = f.lesson_id WHERE f.id = ?"); } // notes of a free lecture are free too
$q->execute([$id]);
$r = $q->fetch(PDO::FETCH_ASSOC);
if (!$r || !$r['file']) $deny(404);
if ($k === 'v' && $qual !== '') { $vv = sh_variants($r); if (!isset($vv[$qual])) $deny(404); $r['file'] = $vv[$qual]; } // a smaller quality of the video
$p = sh_product($pdo, (int)$r['product_id'], false);
if ($p && $p['status'] === 'draft' && !sh_staff()) $p = null; // drafts only for staff previews
if (!$p) $deny(404);
if (!$r['free'] && !sh_has_access($pdo, $uid, (int)$p['id']) && !($uid === 0 && sh_staff())) $deny(); // staff preview links are made for user 0
$path = sh_media_path((string)$r['file']);
if (!is_file($path)) $deny(404);
$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mime = ['mp4' => 'video/mp4', 'm4v' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime', 'mkv' => 'video/x-matroska', 'pdf' => 'application/pdf'][$ext] ?? 'application/octet-stream';
$dl = $k !== 'v' && !empty($_GET['dl']); // PDFs: dl=1 downloads the file (same access checks as reading)
sh_send_file($path, $mime, (trim(preg_replace('/\s+/', ' ', (string)$r['title'])) ?: 'notes') . '.' . $ext, !$dl); // downloaded under the title given to it
