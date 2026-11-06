<?php
require_once __DIR__ . '/security.php';
// Redirections (Admin → Redirections), checked at the very start of each page — before any cached copy is sent.
// Rules live in cache/.redirect-rules.json (written by the admin page), so this needs no database:
//   {"rules":[{"id","match":"url|post","source":"/old-path","post":12,"to":"https://…","code":301,"cc":["IN"],"active":1}],
//    "404":{"on":0,"to":"/","code":301}}
// A rule with countries only applies to visitors from those countries (Cloudflare's CF-IPCountry header).
if (!function_exists('edu_redirect_check')) {
    function edu_redirect_rules(): array
    {
        static $r = null;
        if ($r === null) $r = @json_decode((string)@file_get_contents(dirname(__DIR__) . '/cache/.redirect-rules.json'), true) ?: [];
        return $r;
    }

    function edu_redirect_hit(string $id): void
    {
        $f = dirname(__DIR__) . '/cache/.redirect-hits.json';
        $fp = @fopen($f, 'c+');
        if (!$fp) return;
        if (flock($fp, LOCK_EX)) {
            $size = fstat($fp)['size'];
            $d = json_decode($size ? (string)fread($fp, $size) : '{}', true) ?: [];
            $d[$id] = ['n' => (int)($d[$id]['n'] ?? 0) + 1, 't' => time()];
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($d));
            flock($fp, LOCK_UN);
        }
        fclose($fp);
    }

    function edu_redirect_go(array $rule): void
    {
        if (!empty($rule['id'])) edu_redirect_hit((string)$rule['id']);
        $code = in_array((int)($rule['code'] ?? 301), [301, 302, 307, 308], true) ? (int)$rule['code'] : 301;
        header('Cache-Control: ' . ($code === 301 || $code === 308 ? 'public, max-age=3600' : 'private, no-store'));
        if (!empty($rule['cc'])) { header('Cache-Control: private, no-store'); header('Vary: CF-IPCountry'); }
        header('X-Redirect-By: ' . (defined('SITE_NAME') ? edu_site_name() : 'Site'));
        header('Location: ' . $rule['to'], true, $code);
        exit;
    }

    /** $postId: the post being opened (post.php), so a rule made for a post follows it even if its address changes. */
    function edu_redirect_check(int $postId = 0, bool $isContent = false): void
    {
        if (isset($_GET['czp']) || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return;
        $all = edu_redirect_rules();
        if (empty($all['rules'])) return;
        $path = '/' . trim(rawurldecode((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH)), '/');
        $full = $path . (($q = (string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY)) !== '' ? '?' . $q : '');
        $cc = strtoupper((string)($_SERVER['HTTP_CF_IPCOUNTRY'] ?? ''));
        foreach ($all['rules'] as $r) {
            if (empty($r['active']) || empty($r['to'])) continue;
            if ($r['match'] === 'post') { if (!$postId || (int)$r['post'] !== $postId) continue; }
            elseif ($r['match'] === 'content') { if (!$isContent) continue; } // every post and page (not the homepage or lists)
            else {
                $src = '/' . trim((string)$r['source'], '/');
                $hit = str_ends_with($src, '*') ? str_starts_with($path, rtrim(substr($src, 0, -1), '/')) : (str_contains($src, '?') ? strcasecmp($full, $src) === 0 : strcasecmp($path, $src) === 0);
                if (!$hit) continue;
            }
            if (!empty($r['cc']) && !in_array($cc, (array)$r['cc'], true)) continue;
            // never send a visitor back to the same address
            $toPath = '/' . trim((string)parse_url($r['to'], PHP_URL_PATH), '/');
            $toHost = parse_url($r['to'], PHP_URL_HOST);
            if ($toPath === $path && (!$toHost || strcasecmp($toHost, (string)($_SERVER['HTTP_HOST'] ?? '')) === 0)) continue;
            edu_redirect_go($r);
        }
    }

    /** For pages that don't exist: the 404 redirect (if switched on) — also logs the address for the 404 monitor. */
    function edu_redirect_404(): void
    {
        edu_redirect_check();
        $path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $skip = preg_match('#\.(js|css|map|png|jpe?g|gif|webp|svg|ico|woff2?|ttf|php)$#i', $path) || str_starts_with($path, '/admin') || str_starts_with($path, '/wp-');
        if (!$skip) {
            $f = dirname(__DIR__) . '/cache/.404-log.json';
            $fp = @fopen($f, 'c+');
            if ($fp && flock($fp, LOCK_EX)) {
                $size = fstat($fp)['size'];
                $d = json_decode($size ? (string)fread($fp, $size) : '{}', true) ?: [];
                $k = mb_substr($path, 0, 300);
                $d[$k] = ['n' => (int)($d[$k]['n'] ?? 0) + 1, 't' => time(), 'r' => mb_substr((string)($_SERVER['HTTP_REFERER'] ?? ''), 0, 200)];
                if (count($d) > 500) { uasort($d, fn($a, $b) => $b['t'] <=> $a['t']); $d = array_slice($d, 0, 400, true); }
                ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode($d)); flock($fp, LOCK_UN);
            }
            if ($fp) fclose($fp);
        }
        $n = edu_redirect_rules()['404'] ?? [];
        if (!empty($n['on']) && !empty($n['to'])) edu_redirect_go(['id' => '404', 'to' => $n['to'], 'code' => $n['code'] ?? 301]);
    }
}
