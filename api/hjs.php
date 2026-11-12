<?php
// Browser errors for Site Health (assets: the small reporter in the page head). POST JSON {m, s, l, c, u} — a few per
// page at most; 30 a minute from one address (kept in memory, not the database); 5 MB of these a day. Answers 204.
ini_set('display_errors', '0');
header('Cache-Control: no-store'); header('X-Robots-Tag: noindex');
http_response_code(204);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 4000) exit;
$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
if (function_exists('apcu_fetch')) { $k = 'hjs:' . $ip . ':' . date('YmdHi'); $n = (int)apcu_fetch($k); if ($n >= 30) exit; apcu_store($k, $n + 1, 120); }
require_once dirname(__DIR__) . '/includes/config.php'; // also loads the Site Health helpers
if (!function_exists('edu_health_add')) exit;
$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) exit;
$m = trim((string)($in['m'] ?? ''));
if ($m === '' || stripos($m, 'script error') === 0 || preg_match('/ResizeObserver loop|chrome-extension|moz-extension|safari-extension/i', $m . ' ' . ($in['s'] ?? ''))) exit; // noise / browser add-ons
$f = edu_health_dir() . '/day-' . date('Ymd') . '.jsonl';
if (is_file($f) && filesize($f) > 5 * 1048576) exit; // enough browser reports for one day
$src = (string)parse_url((string)($in['s'] ?? ''), PHP_URL_PATH);
edu_health_add(['k' => 'js', 'lv' => 'Browser', 'm' => mb_substr($m, 0, 300), 'f' => mb_substr($src, 0, 160), 'l' => (int)($in['l'] ?? 0), 'c' => (int)($in['c'] ?? 0)]);
$q = &$GLOBALS['__edu_health_q']; foreach ($q as &$e) if (($e['k'] ?? '') === 'js') { $e['u'] = mb_substr((string)parse_url((string)($in['u'] ?? ''), PHP_URL_PATH), 0, 300); $e['ua'] = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 160); }
unset($e);
