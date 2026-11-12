<?php
// Site Health (superadmin only): crash / problem report of the whole site.
//   Overview — health checks, each run on its own (one request at a time, never all together), with reason and fix.
//   PHP errors, Slow pages, Browser errors — collected by includes/health.php (files, not the database), grouped.
//   Server logs — HTTP 5xx from the web server's access log and crashes from its error log (only their newest part).
// Every list is grouped and paged (25 a page); files are read with a size limit, so a busy site never makes it heavy.
require_once __DIR__ . '/components/guard.php';
$me = admin_guard(['admin']);
if (!function_exists('edu_is_super') || !edu_is_super((int)$me['id'])) { http_response_code(404); exit('Not found'); }
require_once INCLUDES_PATH . '/health.php';
session_write_close(); // long checks never hold the session

$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$site = rtrim(SITE_URL, '/');
$logs = dirname(ROOT_PATH) . '/logs';

/** Reads at most $cap bytes from the end of a file, as whole lines (newest last). */
function sh_tail_lines(string $f, int $cap): array
{
    if (!is_file($f) || !is_readable($f)) return [];
    $size = filesize($f); $h = @fopen($f, 'rb'); if (!$h) return [];
    if ($size > $cap) { fseek($h, $size - $cap); fgets($h); }
    $out = []; while (($l = fgets($h)) !== false) $out[] = rtrim($l, "\r\n");
    fclose($h);
    return $out;
}

// ---------- one health check (JSON) ----------
if (isset($_GET['check'])) {
    header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
    @set_time_limit(40);
    $c = (string)$_GET['check'];
    $r = ['status' => 'ok', 'value' => '', 'reason' => '', 'fix' => ''];
    $mb = fn($b) => round($b / 1048576, 1) . ' MB';
    $bytes = function (string $v): int { $n = (int)$v; $u = strtolower(substr(trim($v), -1)); return $u === 'g' ? $n * 1073741824 : ($u === 'm' ? $n * 1048576 : ($u === 'k' ? $n * 1024 : $n)); };
    try {
        switch ($c) {
            case 'php':
                $miss = array_values(array_filter(['gd', 'curl', 'openssl', 'mbstring', 'pdo_mysql', 'zip', 'json'], fn($x) => !extension_loaded($x)));
                $mem = (string)ini_get('memory_limit');
                $r['value'] = 'PHP ' . PHP_VERSION . ' · memory ' . $mem . ' · upload ' . ini_get('upload_max_filesize') . ' · post ' . ini_get('post_max_size');
                if ($miss) { $r['status'] = 'bad'; $r['reason'] = 'Missing PHP extensions: ' . implode(', ', $miss) . '. Features that need them (pictures, payments, backups) fail.'; $r['fix'] = 'Ask the hosting to enable them.'; }
                elseif ($mem !== '-1' && $bytes($mem) < 128 * 1048576) { $r['status'] = 'warn'; $r['reason'] = 'Memory limit is low — big reports and imports may stop half-way.'; $r['fix'] = 'Raise memory_limit to 256M.'; }
                if (version_compare(PHP_VERSION, '8.1', '<')) { $r['status'] = 'warn'; $r['reason'] = 'Old PHP version.'; $r['fix'] = 'Move to PHP 8.2 or newer.'; }
                break;
            case 'disk':
                $free = @disk_free_space(ROOT_PATH); $tot = @disk_total_space(ROOT_PATH);
                $r['value'] = $free !== false ? round($free / 1073741824, 1) . ' GB free of ' . round($tot / 1073741824, 1) . ' GB' : 'unknown';
                if ($free !== false && $free < 2 * 1073741824) { $r['status'] = $free < 512 * 1048576 ? 'bad' : 'warn'; $r['reason'] = 'The server disk is almost full. Uploads, backups, cache and the database stop working when it is full.'; $r['fix'] = 'Delete old backups / unused uploads, or get more disk space.'; }
                break;
            case 'db':
                $t = microtime(true); $pdo->query('SELECT 1')->fetchColumn(); $ms = round((microtime(true) - $t) * 1000, 1);
                $ver = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
                $th = (int)($pdo->query("SHOW STATUS LIKE 'Threads_connected'")->fetch(PDO::FETCH_NUM)[1] ?? 0);
                $mx = (int)($pdo->query("SHOW VARIABLES LIKE 'max_connections'")->fetch(PDO::FETCH_NUM)[1] ?? 0);
                $size = (float)$pdo->query("SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchColumn();
                $r['value'] = 'answers in ' . $ms . ' ms · ' . $ver . ' · connections ' . $th . ($mx ? ' / ' . $mx : '') . ' · size ' . $mb($size);
                if ($ms > 200) { $r['status'] = 'warn'; $r['reason'] = 'The database answers slowly — every page waits for it.'; $r['fix'] = 'Check the server load; restart MySQL if it stays slow.'; }
                if ($mx && $th > $mx * 0.8) { $r['status'] = 'bad'; $r['reason'] = 'Almost all database connections are in use — new visitors may get errors.'; $r['fix'] = 'Look for stuck processes / heavy traffic; raise max_connections.'; }
                break;
            case 'tables':
                $rows = $pdo->query("SELECT table_name n, table_rows r, ROUND((data_length + index_length) / 1048576, 1) mb FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY (data_length + index_length) DESC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);
                $r['value'] = implode(' · ', array_map(fn($x) => $x['n'] . ' ' . $x['mb'] . ' MB (~' . number_format((int)$x['r']) . ' rows)', $rows));
                $big = array_filter($rows, fn($x) => (float)$x['mb'] > 500);
                if ($big) { $r['status'] = 'warn'; $r['reason'] = 'A very large table: lists and reports on it need paging.'; $r['fix'] = 'Old log / visit rows can be cleared from their own pages.'; }
                break;
            case 'cache':
                $dir = ROOT_PATH . '/cache'; $n = count(glob($dir . '/*.html') ?: []);
                $r['value'] = is_writable($dir) ? $n . ' cached pages' : 'not writable';
                if (!is_writable($dir)) { $r['status'] = 'bad'; $r['reason'] = 'The page cache folder cannot be written — every page is built fresh and the site is slower.'; $r['fix'] = 'Fix the folder permission of /cache.'; }
                break;
            case 'cron':
                $st = function_exists('edu_cron_state') ? edu_cron_state() : (is_file(INCLUDES_PATH . '/cron.php') ? (function () { require_once INCLUDES_PATH . '/cron.php'; return edu_cron_state(); })() : []);
                $last = max((int)($st['minute_at'] ?? 0), (int)($st['web_at'] ?? 0));
                $r['value'] = $last ? 'last run ' . date('d M, h:i A', $last) . ' (' . max(0, round((time() - $last) / 60)) . ' min ago)' : 'never ran';
                if (!$last || time() - $last > 900) { $r['status'] = 'bad'; $r['reason'] = 'Scheduled jobs are not running: push notifications, automatic backups and daily clean-ups stay waiting.'; $r['fix'] = 'Add the server crontab (see Settings → Cron Manager) or a web cron with the key.'; }
                break;
            case 'push':
                $n = 0; try { $n = (int)$pdo->query("SELECT COUNT(*) FROM push_queue WHERE status = 'pending'")->fetchColumn(); } catch (Throwable $x) {}
                $r['value'] = $n . ' notifications waiting';
                if ($n > 500) { $r['status'] = 'warn'; $r['reason'] = 'Many push notifications are waiting to be sent.'; $r['fix'] = 'Check that the cron job runs.'; }
                break;
            case 'mail':
                require_once INCLUDES_PATH . '/mailer.php';
                $ok = edu_mail_ready(); $r['value'] = $ok ? 'set up' : 'not set up';
                if (!$ok) { $r['status'] = 'warn'; $r['reason'] = 'Email is not set up: forgot-password links, contact replies and receipts cannot be emailed.'; $r['fix'] = 'Settings → Email (SMTP).'; }
                break;
            case 'pay':
                require_once INCLUDES_PATH . '/shop.php';
                $demo = sh_pay_demo(); $k = sh_rzp_keys();
                $r['value'] = $demo ? 'demo mode' : (!empty($k['id']) && !empty($k['secret']) ? 'Razorpay live' : 'keys missing');
                if ($demo) { $r['status'] = 'bad'; $r['reason'] = 'Payments are in demo mode: a paid course can be "bought" without real money.'; $r['fix'] = 'Store Settings → add the Razorpay keys and switch demo off.'; }
                elseif (empty($k['secret'])) { $r['status'] = 'bad'; $r['reason'] = 'Razorpay keys are missing: buying a paid course fails.'; $r['fix'] = 'Store Settings → Razorpay keys.'; }
                elseif (empty($k['hook'])) { $r['status'] = 'warn'; $r['reason'] = 'No webhook secret: if a buyer closes the page right after paying, the course is not opened automatically.'; $r['fix'] = 'Add the Razorpay webhook and its secret in Store Settings.'; }
                break;
            case 'errors':
                $by = ['fatal' => 0, 'php' => 0, 'slow' => 0, 'js' => 0]; // today's and yesterday's files, counted for the last 24 hours
                foreach (edu_health_read(2) as $x) if (($x['t'] ?? 0) >= time() - 86400 && isset($by[$x['k'] ?? ''])) $by[$x['k']]++;
                $r['value'] = 'last 24 h: ' . $by['fatal'] . ' crashes · ' . $by['php'] . ' PHP warnings · ' . $by['slow'] . ' slow pages · ' . $by['js'] . ' browser errors';
                if ($by['fatal']) { $r['status'] = 'bad'; $r['reason'] = 'Pages crashed (visitors saw an error / blank page).'; $r['fix'] = 'Open the PHP errors tab — each crash shows the file, line and page.'; }
                elseif ($by['php'] > 50 || $by['slow'] > 20) { $r['status'] = 'warn'; $r['reason'] = 'Many warnings or slow pages.'; $r['fix'] = 'See the PHP errors / Slow pages tabs.'; }
                break;
            default:
                if (!str_starts_with($c, 'page:')) { http_response_code(400); exit('{"error":"unknown check"}'); }
                $path = '/' . ltrim(substr($c, 5), '/');
                if (!preg_match('#^/[A-Za-z0-9/_.?=&%-]*$#', $path)) { http_response_code(400); exit('{"error":"bad path"}'); }
                $ch = curl_init($site . $path);
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_USERAGENT => 'DK-SiteHealth/1.0', CURLOPT_HTTPHEADER => ['Cache-Control: no-cache']]);
                $body = (string)curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $ms = round(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000); $err = curl_error($ch); curl_close($ch);
                $r['value'] = ($code ?: 'no answer') . ' in ' . $ms . ' ms · ' . round(strlen($body) / 1024) . ' KB';
                if (!$code) { $r['status'] = 'bad'; $r['reason'] = 'The page did not answer: ' . $err; $r['fix'] = 'The server or PHP may be down / overloaded.'; }
                elseif ($code >= 500) { $r['status'] = 'bad'; $r['reason'] = 'The page crashes (HTTP ' . $code . ').'; $r['fix'] = 'See PHP errors / Server logs for this address.'; }
                elseif ($code >= 400 && $code !== 404) { $r['status'] = 'warn'; $r['reason'] = 'The page answers ' . $code . '.'; }
                elseif ($code === 404) { $r['status'] = 'warn'; $r['reason'] = 'Not found (404).'; }
                elseif (preg_match('/(Fatal error|Warning<\/b>:|Notice<\/b>:|Uncaught )/', $body)) { $r['status'] = 'bad'; $r['reason'] = 'The page shows a PHP error message to visitors.'; $r['fix'] = 'See the PHP errors tab.'; }
                elseif ($ms > 3000) { $r['status'] = 'warn'; $r['reason'] = 'Slow: more than 3 seconds (first load, without the page cache).'; $r['fix'] = 'See the Slow pages tab; heavy lists need paging / caching.'; }
        }
    } catch (Throwable $x) { $r = ['status' => 'bad', 'value' => 'check failed', 'reason' => $x->getMessage(), 'fix' => '']; }
    exit(json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

// ---------- clear the collected reports ----------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'clear') {
    csrf_check();
    foreach (glob(edu_health_dir() . '/day-*.jsonl') ?: [] as $f) @unlink($f);
    header('Location: /admin/site-health.php?tab=' . rawurlencode((string)($_POST['tab'] ?? 'overview')) . '&cleared=1'); exit;
}

// ---------- lists ----------
$tab = in_array($_GET['tab'] ?? '', ['overview', 'errors', 'slow', 'js', 'server'], true) ? $_GET['tab'] : 'overview';
$days = in_array((int)($_GET['days'] ?? 7), [1, 3, 7, 14], true) ? (int)($_GET['days'] ?? 7) : 7;
$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
$pg = max(1, (int)($_GET['p'] ?? 1)); $per = 25;
$groups = []; $total = 0;
if (in_array($tab, ['errors', 'slow', 'js'], true)) {
    $kinds = ['errors' => ['php', 'fatal'], 'slow' => ['slow'], 'js' => ['js']][$tab];
    foreach (edu_health_read($days) as $x) {
        if (!in_array($x['k'] ?? '', $kinds, true)) continue;
        if ($q !== '' && stripos(($x['m'] ?? '') . ' ' . ($x['f'] ?? '') . ' ' . ($x['u'] ?? ''), $q) === false) continue;
        $key = $tab === 'slow' ? (string)($x['m'] ?? '') : (string)$x['sig'];
        $g = &$groups[$key];
        if (!$g) $g = ['n' => 0, 'first' => $x['t'], 'last' => $x['t'], 'x' => $x, 'urls' => [], 'ms' => 0, 'maxms' => 0, 'maxmb' => 0];
        $g['n']++; $g['first'] = min($g['first'], $x['t']); if ($x['t'] >= $g['last']) { $g['last'] = $x['t']; $g['x'] = $x; }
        if (!empty($x['u']) && count($g['urls']) < 4 && !in_array($x['u'], $g['urls'], true)) $g['urls'][] = $x['u'];
        if ($tab === 'slow') { $g['ms'] += (int)($x['ms'] ?? 0); $g['maxms'] = max($g['maxms'], (int)($x['ms'] ?? 0)); $g['maxmb'] = max($g['maxmb'], (float)($x['mb'] ?? 0)); }
        unset($g); $total++;
    }
    uasort($groups, fn($a, $b) => ($b['x']['k'] === 'fatal') <=> ($a['x']['k'] === 'fatal') ?: $b['last'] <=> $a['last']);
}
$server = ['5xx' => [], 'crash' => [], 'have' => is_dir($logs)];
if ($tab === 'server' && $server['have']) {
    foreach (sh_tail_lines($logs . '/access.log', 4 * 1048576) as $l) { // only the newest 4 MB of the access log
        if (!preg_match('#\[([^\]]+)\] "([A-Z]+) ([^ "]+)[^"]*" (\d{3})#', $l, $m) || (int)$m[4] < 500) continue;
        $p = (string)strtok($m[3], '?');
        if ($q !== '' && stripos($p, $q) === false) continue;
        $g = &$server['5xx'][$p . '|' . $m[4]]; if (!$g) $g = ['p' => $p, 'code' => $m[4], 'n' => 0, 'last' => '']; $g['n']++; $g['last'] = $m[1]; unset($g);
    }
    $cur = null;
    foreach (sh_tail_lines($logs . '/error.log', 1048576) as $l) { // the newest 1 MB of the error log
        if (preg_match('#^(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)\S* \[[A-Z]+\].*?(PHP (Fatal error|Parse error|Warning|Notice|Deprecated)):\s*(.*)$#', $l, $m)) {
            $msg = preg_replace('#/home/[^/]+/[^ ]*?public_html#', '', $m[4]);
            if ($q !== '' && stripos($msg, $q) === false) continue;
            $k = md5($m[3] . preg_replace('/\d+/', '#', $msg));
            $g = &$server['crash'][$k]; if (!$g) $g = ['lv' => $m[3], 'm' => $msg, 'n' => 0, 'last' => '']; $g['n']++; $g['last'] = $m[1]; unset($g);
        }
    }
    uasort($server['5xx'], fn($a, $b) => $b['n'] <=> $a['n']);
    uasort($server['crash'], fn($a, $b) => strcmp($b['last'], $a['last']));
}
$pages = max(1, (int)ceil(count($groups) / $per)); $pg = min($pg, $pages);
$shown = array_slice($groups, ($pg - 1) * $per, $per, true);
$qs = fn(array $o = []) => http_build_query(array_merge(['tab' => $tab, 'days' => $days, 'q' => $q], $o));
$ago = function (int $t): string { $d = time() - $t; return $d < 60 ? 'just now' : ($d < 3600 ? floor($d / 60) . ' min ago' : ($d < 86400 ? floor($d / 3600) . ' h ago' : date('d M, h:i A', $t))); };
// pages to test on the Overview: home, lists, one course, one post, log in, sitemap
$testPages = ['/', '/courses/', '/notes/', '/blog', '/account/login', '/sitemap_index.xml', '/search?q=class'];
try { $cp = $pdo->query("SELECT slug, type FROM sh_products WHERE status = 'published' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC); if ($cp) $testPages[] = ($cp['type'] === 'notes' ? '/notes/' : '/courses/') . $cp['slug']; } catch (Throwable $x) {}
try { $pp = $pdo->query("SELECT slug, id FROM posts WHERE status = 'published' ORDER BY date DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC); if ($pp && function_exists('postUrl')) $testPages[] = (string)parse_url(postUrl($pp['slug'], (int)$pp['id']), PHP_URL_PATH); } catch (Throwable $x) {}
$checks = [['php', 'PHP & server settings'], ['disk', 'Disk space'], ['db', 'Database'], ['tables', 'Biggest tables'], ['cache', 'Page cache'], ['cron', 'Scheduled jobs (cron)'], ['push', 'Push queue'], ['mail', 'Email'], ['pay', 'Payments'], ['errors', 'Problems in the last 24 hours']];
foreach ($testPages as $tp) $checks[] = ['page:' . $tp, 'Page ' . $tp];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title>Site Health - <?= $e(edu_site_name()) ?></title>
<?php admin_shell_head(); require_once INCLUDES_PATH . '/institute.php'; ci_head(); ?>
<style>
.sh-tabs{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 14px}.sh-tabs a{padding:8px 14px;border:1px solid #e5e7eb;background:#fff;color:#374151;text-decoration:none;font-weight:600;font-size:13.5px;border-radius:6px}.sh-tabs a.on{background:var(--ci-acc,#4a3369);border-color:var(--ci-acc,#4a3369);color:#fff}
.sh-chk{display:grid;grid-template-columns:22px 210px 1fr;gap:4px 12px;align-items:start;padding:11px 14px;border-bottom:1px solid #f1f5f9}.sh-chk:last-child{border-bottom:0}
.sh-dot{width:12px;height:12px;border-radius:50%;margin-top:4px;background:#d1d5db}.sh-dot.ok{background:#16a34a}.sh-dot.warn{background:#f59e0b}.sh-dot.bad{background:#dc2626}.sh-dot.run{background:#93c5fd;animation:shp 1s infinite alternate}@keyframes shp{to{opacity:.3}}
.sh-chk b{font-size:13.5px}.sh-v{font-size:13px;color:#374151;word-break:break-word}.sh-r{grid-column:3;font-size:12.5px;color:#991b1b}.sh-r.warn{color:#92400e}.sh-f{grid-column:3;font-size:12.5px;color:#1e40af}
@media (max-width:700px){.sh-chk{grid-template-columns:18px 1fr}.sh-v,.sh-r,.sh-f{grid-column:2}}
.sh-msg{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12.5px;color:#111827;word-break:break-word}.sh-why{font-size:12.5px;color:#1e40af;margin-top:4px}.sh-urls{font-size:12px;color:#6b7280;margin-top:4px;word-break:break-all}
.sh-lv{display:inline-block;padding:1px 7px;border-radius:4px;font-size:11px;font-weight:700;text-transform:uppercase}.sh-lv.Fatal,.sh-lv.Error{background:#fee2e2;color:#991b1b}.sh-lv.Warning{background:#fef3c7;color:#92400e}.sh-lv.Notice,.sh-lv.Browser{background:#e0e7ff;color:#3730a3}
.sh-sum{display:flex;gap:10px;flex-wrap:wrap;margin:0 0 12px}.sh-sum span{padding:6px 12px;background:#fff;border:1px solid #e5e7eb;border-radius:6px;font-size:13px}
</style>
</head>
<body>
<?php admin_shell_open('Site Health', 'Crash report of the whole site — superadmin only'); ?>
<div class="ci">
<?php if (isset($_GET['cleared'])): ?><div class="ci-alert ci-alert-ok">Collected reports cleared.</div><?php endif; ?>
<nav class="sh-tabs"><?php foreach (['overview' => 'Overview', 'errors' => 'PHP errors & crashes', 'slow' => 'Slow / heavy pages', 'js' => 'Browser errors', 'server' => 'Server logs'] as $k => $l): ?><a href="?<?= $e(http_build_query(['tab' => $k, 'days' => $days])) ?>" class="<?= $tab === $k ? 'on' : '' ?>"><?= $l ?></a><?php endforeach; ?></nav>

<?php if ($tab === 'overview'): ?>
<div class="ci-card"><div class="ci-card-h" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap"><b>Health checks</b><span><span class="ci-muted" id="shState">Starting…</span> <button type="button" class="ci-btn ci-ghost" id="shRun"><i class="fas fa-rotate"></i> Run again</button></span></div>
<div class="ci-card-b ci-p0" id="shList"><?php foreach ($checks as [$k, $l]): ?><div class="sh-chk" data-c="<?= $e($k) ?>"><span class="sh-dot"></span><b><?= $e($l) ?></b><span class="sh-v ci-muted">waiting…</span></div><?php endforeach; ?></div></div>
<p class="ci-muted" style="font-size:12.5px">Checks run one after another (never all at once), so the site is not loaded. Page speed is measured from the server without the page cache.</p>
<script>
(function(){var rows=[].slice.call(document.querySelectorAll('.sh-chk')),st=document.getElementById('shState'),btn=document.getElementById('shRun'),busy=false;
function esc(s){return String(s||'').replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]})}
function run(){if(busy)return;busy=true;btn.disabled=true;var i=0,bad=0,warn=0;rows.forEach(function(r){r.querySelector('.sh-dot').className='sh-dot';[].forEach.call(r.querySelectorAll('.sh-r,.sh-f'),function(x){x.remove()});r.querySelector('.sh-v').textContent='waiting…'});
 (function next(){if(i>=rows.length){busy=false;btn.disabled=false;st.textContent=bad?bad+' problem(s), '+warn+' warning(s)':(warn?warn+' warning(s), nothing broken':'All good');return}
  var r=rows[i++],d=r.querySelector('.sh-dot');d.className='sh-dot run';st.textContent='Checking '+i+' of '+rows.length+'…';
  fetch('?check='+encodeURIComponent(r.dataset.c),{credentials:'same-origin'}).then(function(x){return x.json()}).catch(function(){return{status:'bad',value:'check did not answer',reason:'The check timed out or the server failed.'}}).then(function(j){
   d.className='sh-dot '+(j.status||'bad');if(j.status==='bad')bad++;if(j.status==='warn')warn++;var v=r.querySelector('.sh-v');v.textContent=j.value||'';v.classList.remove('ci-muted');
   if(j.reason)r.insertAdjacentHTML('beforeend','<span class="sh-r '+(j.status==='warn'?'warn':'')+'">'+esc(j.reason)+'</span>');if(j.fix)r.insertAdjacentHTML('beforeend','<span class="sh-f">Fix: '+esc(j.fix)+'</span>');
   setTimeout(next,150);});})();}
btn.addEventListener('click',run);run();})();
</script>

<?php elseif ($tab === 'server'): ?>
<?php if (!$server['have']): ?><div class="ci-card"><div class="ci-card-b ci-muted">The web server's logs are not available here (only on the live server).</div></div>
<?php else: ?>
<form class="ci-filters" method="get" style="margin:0 0 12px"><input type="hidden" name="tab" value="server"><div class="ci-f"><label>Search address / message</label><input type="search" name="q" value="<?= $e($q) ?>"></div><div class="ci-fbtn"><button class="ci-btn">Filter</button></div></form>
<div class="ci-card"><div class="ci-card-h"><b>Pages that answered with a server error (HTTP 5xx)</b> <span class="ci-muted">· newest 4 MB of the access log</span></div><div class="ci-card-b ci-p0 ci-table-wrap"><table class="ci-table"><thead><tr><th>Address</th><th>Code</th><th class="r">Times</th><th>Last</th></tr></thead><tbody>
<?php foreach (array_slice($server['5xx'], 0, 50) as $g): ?><tr><td class="sh-msg"><?= $e($g['p']) ?></td><td><?= $e($g['code']) ?><?= $g['code'] === '503' ? ' <span class="ci-muted">(maintenance / overload)</span>' : '' ?></td><td class="r"><?= (int)$g['n'] ?></td><td><?= $e($g['last']) ?></td></tr><?php endforeach; ?>
<?php if (!$server['5xx']): ?><tr><td colspan="4" class="ci-empty">No server errors in the recent log.</td></tr><?php endif; ?>
</tbody></table></div></div>
<div class="ci-card"><div class="ci-card-h"><b>PHP problems in the server error log</b> <span class="ci-muted">· newest 1 MB</span></div><div class="ci-card-b ci-p0 ci-table-wrap"><table class="ci-table"><thead><tr><th>Problem</th><th class="r">Times</th><th>Last</th></tr></thead><tbody>
<?php foreach (array_slice($server['crash'], 0, 50) as $g): $why = edu_health_reason($g['m']); ?><tr><td><span class="sh-lv <?= $g['lv'] === 'Fatal error' || $g['lv'] === 'Parse error' ? 'Fatal' : $e(explode(' ', $g['lv'])[0]) ?>"><?= $e($g['lv']) ?></span><div class="sh-msg"><?= $e(mb_substr($g['m'], 0, 400)) ?></div><?php if ($why): ?><div class="sh-why">Why: <?= $e($why) ?></div><?php endif; ?></td><td class="r"><?= (int)$g['n'] ?></td><td><?= $e($g['last']) ?></td></tr><?php endforeach; ?>
<?php if (!$server['crash']): ?><tr><td colspan="3" class="ci-empty">No PHP problems in the recent server log.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?php endif; ?>

<?php else: ?>
<form class="ci-filters" method="get" style="margin:0 0 12px"><input type="hidden" name="tab" value="<?= $e($tab) ?>">
  <div class="ci-f"><label>Period</label><select name="days" onchange="this.form.submit()"><?php foreach ([1 => 'Last 24 hours', 3 => 'Last 3 days', 7 => 'Last 7 days', 14 => 'Last 14 days'] as $k => $l): ?><option value="<?= $k ?>"<?= $days === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
  <div class="ci-f"><label>Search message / file / page</label><input type="search" name="q" value="<?= $e($q) ?>"></div>
  <div class="ci-fbtn"><button class="ci-btn"><i class="fas fa-filter"></i> Filter</button></div>
</form>
<div class="sh-sum"><span><b><?= count($groups) ?></b> different <?= $tab === 'slow' ? 'pages' : 'problems' ?></span><span><b><?= $total ?></b> times in total</span></div>
<div class="ci-card"><div class="ci-card-b ci-p0 ci-table-wrap"><table class="ci-table"><thead><tr>
<?php if ($tab === 'slow'): ?><th>Page</th><th class="r">Times</th><th class="r">Average</th><th class="r">Slowest</th><th class="r">Memory</th><th>Last</th>
<?php else: ?><th>Problem</th><th>Where</th><th class="r">Times</th><th>Last</th><?php endif; ?></tr></thead><tbody>
<?php foreach ($shown as $g): $x = $g['x']; ?>
<?php if ($tab === 'slow'): ?><tr><td class="sh-msg"><?= $e($x['m'] ?? '') ?><?php if ($g['maxmb'] >= EDU_HEALTH_MEM_MB): ?><div class="sh-why">Heavy: used <?= $g['maxmb'] ?> MB — a list or report loads too much at once and needs paging.</div><?php elseif ($g['maxms'] >= 10000): ?><div class="sh-why">Very slow — usually a heavy database query or an outside service that answers late.</div><?php endif; ?></td><td class="r"><?= (int)$g['n'] ?></td><td class="r"><?= number_format($g['ms'] / max(1, $g['n']) / 1000, 1) ?> s</td><td class="r"><?= number_format($g['maxms'] / 1000, 1) ?> s</td><td class="r"><?= $g['maxmb'] ?> MB</td><td><?= $e($ago($g['last'])) ?></td></tr>
<?php else: $why = edu_health_reason((string)($x['m'] ?? '')); ?><tr><td><span class="sh-lv <?= $e($x['lv'] ?? 'Error') ?>"><?= $e($x['lv'] ?? 'Error') ?></span><div class="sh-msg"><?= $e($x['m'] ?? '') ?></div><?php if ($why): ?><div class="sh-why">Why: <?= $e($why) ?></div><?php endif; ?><?php if ($g['urls']): ?><div class="sh-urls">Seen on: <?= $e(implode(' · ', $g['urls'])) ?></div><?php endif; ?></td>
<td class="sh-msg"><?= $e(($x['f'] ?? '') . (!empty($x['l']) ? ':' . $x['l'] : '')) ?></td><td class="r"><?= (int)$g['n'] ?></td><td><?= $e($ago($g['last'])) ?></td></tr><?php endif; ?>
<?php endforeach; ?>
<?php if (!$shown): ?><tr><td colspan="6" class="ci-empty">Nothing recorded in this period<?= $q !== '' ? ' for this search' : '' ?>.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?php if ($pages > 1): ?><div class="ci-pager" style="display:flex;gap:6px;align-items:center;margin-top:10px"><?php if ($pg > 1): ?><a class="ci-btn ci-ghost" href="?<?= $e($qs(['p' => $pg - 1])) ?>">‹ Prev</a><?php endif; ?><span class="ci-muted">Page <?= $pg ?> of <?= $pages ?></span><?php if ($pg < $pages): ?><a class="ci-btn ci-ghost" href="?<?= $e($qs(['p' => $pg + 1])) ?>">Next ›</a><?php endif; ?></div><?php endif; ?>
<?php endif; ?>

<?php if ($tab !== 'server'): ?>
<form method="post" style="margin-top:16px" onsubmit="return confirm('Clear all collected PHP / slow page / browser reports?')"><?= csrf_field() ?><input type="hidden" name="action" value="clear"><input type="hidden" name="tab" value="<?= $e($tab) ?>"><button class="ci-btn ci-ghost"><i class="fas fa-broom"></i> Clear collected reports</button> <span class="ci-muted" style="font-size:12.5px">Reports are kept for 14 days, then removed by themselves.</span></form>
<?php endif; ?>
</div>
<?php admin_shell_close(); ?>
</body>
</html>
