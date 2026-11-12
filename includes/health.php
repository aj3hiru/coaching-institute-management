<?php
// Site Health (superadmin): records PHP problems, slow / heavy pages and browser errors for admin/site-health.php.
// Light by design: nothing goes to the database; each request keeps at most 20 events in memory and writes them once,
// at the end, as one append to a daily file outside the website folder (../dk-private/health). A day's file stops at
// 20 MB, files older than 14 days are removed. PHP's own error handling is not changed (the handler only watches).

const EDU_HEALTH_DAYS = 14;
const EDU_HEALTH_DAY_CAP = 20 * 1048576;
const EDU_HEALTH_SLOW_MS = 3000;
const EDU_HEALTH_MEM_MB = 64;

function edu_health_dir(): string
{
    static $d = null;
    if ($d !== null) return $d;
    $root = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__);
    $priv = dirname($root) . '/dk-private'; $d = $priv . '/health'; // the server's private folder (made by the store), outside the website
    if (is_dir($priv) && !is_dir($d)) @mkdir($d, 0750, true);
    if (!is_dir($d) || !is_writable($d)) { // no private folder (local copy): the cache folder, blocked from the web by a random name
        $d = $root . '/cache/.health-' . substr(hash('sha256', $root . (defined('DB_PASS') ? DB_PASS : '')), 0, 16);
        if (!is_dir($d)) @mkdir($d, 0750, true);
    }
    return $d;
}

/** Path shown without the server folders (also keeps logs short). */
function edu_health_short(string $file): string
{
    $root = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__);
    return str_starts_with($file, $root) ? substr($file, strlen($root)) : basename(dirname($file)) . '/' . basename($file);
}

/** Remembers one event for this request (written once at the end). kind: php | fatal | slow | js */
function edu_health_add(array $e): void
{
    $q = &$GLOBALS['__edu_health_q'];
    if (!is_array($q)) $q = [];
    if (count($q) >= 20) return;
    $e['t'] = time();
    $e['u'] = mb_substr((string)strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '#'), 0, 300);
    $e['sig'] = substr(md5(($e['k'] ?? '') . '|' . ($e['f'] ?? '') . '|' . ($e['l'] ?? '') . '|' . preg_replace('/\d+/', '#', (string)($e['m'] ?? ''))), 0, 12);
    foreach ($q as $x) if ($x['sig'] === $e['sig'] && ($e['k'] ?? '') !== 'slow') return; // the same problem once per request
    $q[] = $e;
}

function edu_health_write(): void
{
    $q = $GLOBALS['__edu_health_q'] ?? [];
    if (!$q) return;
    $GLOBALS['__edu_health_q'] = [];
    $f = edu_health_dir() . '/day-' . date('Ymd') . '.jsonl';
    if (is_file($f) && filesize($f) > EDU_HEALTH_DAY_CAP) return;
    $lines = '';
    foreach ($q as $e) $lines .= json_encode($e, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
    @file_put_contents($f, $lines, FILE_APPEND | LOCK_EX);
    if (mt_rand(1, 500) === 1) foreach (glob(edu_health_dir() . '/day-*.jsonl') ?: [] as $old) if (filemtime($old) < time() - EDU_HEALTH_DAYS * 86400) @unlink($old);
}

/** Starts watching this request (called from includes/security.php on every web request). */
function edu_health_boot(): void
{
    static $on = false;
    if ($on || PHP_SAPI === 'cli') return;
    $on = true;
    set_error_handler(function (int $no, string $msg, string $file = '', int $line = 0): bool {
        if (!(error_reporting() & $no)) return false; // silenced with @ — not a problem
        $lvl = [E_WARNING => 'Warning', E_USER_WARNING => 'Warning', E_NOTICE => 'Notice', E_USER_NOTICE => 'Notice', E_DEPRECATED => 'Deprecated', E_USER_DEPRECATED => 'Deprecated', E_USER_ERROR => 'Error', E_RECOVERABLE_ERROR => 'Error'][$no] ?? 'Error';
        if ($lvl !== 'Deprecated') edu_health_add(['k' => 'php', 'lv' => $lvl, 'm' => mb_substr($msg, 0, 400), 'f' => edu_health_short($file), 'l' => $line]);
        return false; // PHP goes on exactly as before
    });
    register_shutdown_function(function (): void {
        $e = error_get_last();
        if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            edu_health_add(['k' => 'fatal', 'lv' => 'Fatal', 'm' => mb_substr((string)$e['message'], 0, 600), 'f' => edu_health_short((string)$e['file']), 'l' => (int)$e['line']]);
        }
        $ms = (int)round((microtime(true) - (float)($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true))) * 1000);
        $mb = round(memory_get_peak_usage(true) / 1048576, 1);
        $path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $long = preg_match('#/(cron|push_notifications/cron|admin/backup-restore|admin/import-export|admin/api/media-upload|account/report-upload|store/media|store/webhook|admin/site-health)#', $path); // long by design
        if (!$long && ($ms >= EDU_HEALTH_SLOW_MS || $mb >= EDU_HEALTH_MEM_MB)) edu_health_add(['k' => 'slow', 'ms' => $ms, 'mb' => $mb, 'm' => ($_SERVER['REQUEST_METHOD'] ?? 'GET') . ' ' . $path]);
        edu_health_write();
    });
}

/** Reads events of the last $days days (newest first), at most $cap bytes per day file (its newest part). */
function edu_health_read(int $days, int $cap = 6291456): array
{
    $out = [];
    for ($i = 0; $i < $days; $i++) {
        $f = edu_health_dir() . '/day-' . date('Ymd', strtotime("-$i day")) . '.jsonl';
        if (!is_file($f)) continue;
        $size = filesize($f); $h = @fopen($f, 'rb'); if (!$h) continue;
        if ($size > $cap) { fseek($h, $size - $cap); fgets($h); } // start at a whole line
        while (($ln = fgets($h)) !== false) { $e = json_decode($ln, true); if (is_array($e)) $out[] = $e; }
        fclose($h);
    }
    return $out;
}

/** A plain-words reason (and what to do) for a recorded message, when it is a known kind of problem. */
function edu_health_reason(string $msg): string
{
    $m = strtolower($msg);
    $r = [
        'allowed memory size' => 'The page used more memory than the server allows (memory_limit). Usually a list or report loads too many rows at once — it needs paging or a smaller limit.',
        'maximum execution time' => 'The page ran longer than the time limit. A slow database query or an outside service (YouTube, mail, payment) did not answer in time.',
        'undefined array key' => 'The code reads a value that is not there (a missing form field, setting or row column). Usually harmless but the value shows empty; the code should check it first.',
        'undefined variable' => 'The code uses a value that was never set in that case — something on the page may show empty or wrong.',
        'undefined index' => 'The code reads a value that is not there. Usually harmless but the value shows empty.',
        'trying to access array offset on value of type null' => 'A row or setting the code expected was not found (empty result) — the page should handle "nothing found".',
        'call to undefined function' => 'A function is missing — a file was not loaded or a PHP extension is not installed on the server.',
        'call to a member function' => 'The code used something that was empty (null) — usually a database row or object that was not found.',
        'must be of type' => 'A value of the wrong kind reached a function (for example a list where text was expected) — often from an unusual address or form input.',
        'sqlstate[hy000] [2002]' => 'The database server could not be reached (down or restarting).',
        'too many connections' => 'The database had too many open connections at once — heavy traffic or a stuck process.',
        'sqlstate[42s02]' => 'A database table is missing — an update was not completed.',
        'sqlstate[42s22]' => 'A database column is missing — an update was not completed.',
        'sqlstate[23000]' => 'A duplicate or linked record blocked the save (e.g. the same mobile / email / slug already exists).',
        'sqlstate' => 'A database query failed — see the message for the table / column.',
        'failed to open stream' => 'A file could not be opened — it is missing, moved or not readable.',
        'permission denied' => 'The server could not read / write a file or folder — folder permissions need fixing.',
        'division by zero' => 'A calculation divided by zero (e.g. a percentage with nothing to count).',
        'headers already sent' => 'Something was printed before the page set a cookie or redirect — a login / redirect may not work on that page.',
        'curl' => 'A call to an outside service failed (internet, the service, or its keys).',
        'syntax error' => 'A PHP file has a typing mistake and cannot run — the page is down until it is fixed.',
        'script error' => 'A script from another website failed; the browser hides the details. Usually an ad, analytics or embed.',
        'is not defined' => 'A page script used something that was not loaded (a script file failed or loaded late).',
        'cannot read properties of null' => 'A page script looked for a part of the page that is not there on that page.',
        'failed to fetch' => 'The browser could not reach the server for a background request (offline, blocked, or the server failed).',
    ];
    foreach ($r as $k => $v) if (str_contains($m, $k)) return $v;
    return '';
}

/** The small browser-error reporter (in the <head> of site and admin pages): at most 3 reports per page view. */
function edu_health_js_tag(): string
{
    return '<script>(function(){var n=0,seen={};function send(m,s,l,c){if(n>=3||!m)return;var k=m+"|"+l;if(seen[k])return;seen[k]=1;n++;try{var b=JSON.stringify({m:String(m).slice(0,300),s:String(s||"").slice(0,300),l:l|0,c:c|0,u:location.pathname});'
        . 'if(navigator.sendBeacon)navigator.sendBeacon("/api/hjs.php",new Blob([b],{type:"application/json"}));else fetch("/api/hjs.php",{method:"POST",body:b,keepalive:true})}catch(e){}}'
        . 'addEventListener("error",function(e){if(e.message)send(e.message,e.filename,e.lineno,e.colno)});addEventListener("unhandledrejection",function(e){var r=e.reason;send("Unhandled promise: "+(r&&r.message?r.message:r),"",0,0)})})();</script>';
}
