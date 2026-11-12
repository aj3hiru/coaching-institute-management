<?php
// Sends a PDF (course / notes PDF, lecture notes, a PDF lecture) for /store/pf?t=TOKEN (sh_pdf_url in includes/shop.php).
// No signed link and no file name in the page: the token is sealed and is not a key — the file goes only to a browser whose
// own login may read it (a buyer with access, staff, or anyone for a free one), checked again on every request.
// &dl=1 downloads it (same checks). Pages of other websites cannot load it.
require_once dirname(__DIR__) . '/includes/config.php';
require_once INCLUDES_PATH . '/shop.php';
$deny = function (int $code = 403, string $msg = 'Not allowed') { http_response_code($code); header('Content-Type: text/plain; charset=utf-8'); header('Cache-Control: no-store'); header('X-Robots-Tag: noindex'); exit($msg); };
$site = (string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? 'same-origin');
if (!in_array($site, ['same-origin', 'none'], true)) $deny(); // only from this site's own pages (or the address typed by the student)
$t = sh_pdf_open(is_string($_GET['t'] ?? null) ? $_GET['t'] : '');
if (!$t) $deny(404, 'Not found');
[$k, $id] = $t;
$u = sh_user(); $uid = $u ? (int)$u['id'] : 0; $staff = sh_staff();
if (session_status() === PHP_SESSION_ACTIVE) session_write_close(); // a long download must not hold the session
if (function_exists('edu_throttle') && !edu_throttle('pf:' . ($uid ?: edu_client_ip()), 900, 60)) $deny(429, 'Too many requests. Please wait a minute.'); // the reader asks for parts of a big PDF
$pdo = sh_db();
if ($k === 'p') $q = $pdo->prepare("SELECT product_id, free, pdf_file AS file, title FROM sh_lessons WHERE id = ?");
else $q = $pdo->prepare("SELECT f.product_id, COALESCE(l.free, f.free) AS free, f.file, f.title FROM sh_files f LEFT JOIN sh_lessons l ON l.id = f.lesson_id WHERE f.id = ?"); // notes of a free lecture are free too
$q->execute([$id]);
$r = $q->fetch(PDO::FETCH_ASSOC);
if (!$r || !$r['file']) $deny(404, 'Not found');
$p = sh_product($pdo, (int)$r['product_id'], false);
if ($p && $p['status'] === 'draft' && !$staff) $p = null; // drafts only for staff previews
if (!$p) $deny(404, 'Not found');
if (!$r['free'] && !$staff && !sh_has_access($pdo, $uid, (int)$p['id'])) $deny(403, 'Please log in with the account that has this course.');
$path = sh_media_path((string)$r['file']);
if (!is_file($path) || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'pdf') $deny(404, 'Not found');
header('X-Robots-Tag: noindex');
header_remove('Content-Security-Policy'); // the browser's own PDF viewer counts as a plugin (object-src)
sh_send_file($path, 'application/pdf', (trim(preg_replace('/\s+/', ' ', (string)$r['title'])) ?: 'notes') . '.pdf', empty($_GET['dl']));
