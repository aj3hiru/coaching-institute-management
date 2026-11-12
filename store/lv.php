<?php
// YouTube without ids in the page (see sh_yt_tok in includes/shop.php).
//   ?c=TOKEN&s=l|m|x → the lesson picture (large / medium / extra large); our server keeps a copy in cache/lv/.
//   ?k=TOKEN → {"v": id} for the player — only for a script on this site (not a typed address), and a lecture's id
//                 only for a buyer of that course, its free preview, or staff. At most 300 a minute from one IP.
require_once dirname(__DIR__) . '/includes/config.php';
require_once INCLUDES_PATH . '/shop.php';
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

if (isset($_GET['c'])) {
    $t = sh_yt_open(is_string($_GET['c']) ? $_GET['c'] : '');
    $size = ['m' => 'mq', 'x' => 'maxres'][$_GET['s'] ?? ''] ?? 'hq';
    if (!$t) { http_response_code(404); exit; }
    $dir = ROOT_PATH . '/cache/lv';
    $file = $dir . '/' . substr(hash('sha256', $t[0] . '|' . $size . '|' . sh_yt_key()), 0, 32) . '.jpg'; // the file name does not give the id away either
    if (!is_file($file) || filesize($file) < 500) {
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $img = sh_http_get('https://i.ytimg.com/vi/' . $t[0] . '/' . $size . 'default.jpg', 8);
        if ($img === '' && $size === 'maxres') $img = sh_http_get('https://i.ytimg.com/vi/' . $t[0] . '/hqdefault.jpg', 8);
        if (strlen($img) < 500 || !str_starts_with($img, "\xFF\xD8")) { http_response_code(404); header('Cache-Control: max-age=300'); exit; }
        @file_put_contents($file . '.tmp', $img, LOCK_EX); @rename($file . '.tmp', $file);
    }
    $et = '"' . substr(md5_file($file), 0, 16) . '"';
    header('Content-Type: image/jpeg'); header('Cache-Control: public, max-age=2592000, immutable'); header('ETag: ' . $et); // pictures may be indexed (Google Images / Discover)
    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $et) { http_response_code(304); exit; }
    header('Content-Length: ' . filesize($file));
    readfile($file); exit;
}

header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store, private'); header('X-Robots-Tag: noindex');
$no = function (int $c, string $m) { http_response_code($c); exit(json_encode(['error' => $m])); };
// only the site's own player script: it sends this header, and the browser marks the request as coming from this site
if (($_SERVER['HTTP_X_LV'] ?? '') !== '1' || !in_array($_SERVER['HTTP_SEC_FETCH_SITE'] ?? 'same-origin', ['same-origin'], true)) $no(403, 'Not allowed');
if (function_exists('edu_throttle') && !edu_throttle('yt:' . edu_client_ip(), 300, 60)) $no(429, 'Too many requests');
$t = sh_yt_open(is_string($_GET['k'] ?? null) ? $_GET['k'] : '');
if (!$t) $no(404, 'Not found');
[$vid, $ctx, $ref] = $t;
if ($ctx === 'l') { // a lecture: free preview, a buyer of the course, or staff
    $pdo = sh_db();
    $q = $pdo->prepare("SELECT product_id, free FROM sh_lessons WHERE id = ?"); $q->execute([$ref]); $L = $q->fetch(PDO::FETCH_ASSOC);
    if (!$L) $no(404, 'Not found');
    $u = sh_user();
    if (!(int)$L['free'] && !sh_staff() && !($u && sh_has_access($pdo, (int)$u['id'], (int)$L['product_id']))) $no(403, 'Please log in with the account that has this course.');
} elseif ($ctx !== 'p') $no(404, 'Not found');
echo json_encode(['v' => $vid]);
