<?php
// Cron Manager — status of the server's scheduled jobs, "Run now", recent runs and the visitor-log retention.
require_once __DIR__ . '/components/guard.php';
require_once INCLUDES_PATH . '/cron.php';
$me = admin_guard(['admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $a = $_POST['action'] ?? '';
    try {
        if ($a === 'minute') admin_flash('Every-minute jobs ran: ' . edu_cron_minute('Manual'));
        elseif ($a === 'daily') admin_flash('Daily maintenance ran: ' . edu_cron_daily('Manual'));
        elseif ($a === 'web_key') { edu_settings_save('cron', ['web_key' => bin2hex(random_bytes(16))]); admin_flash('A new cron key was made. Update the URL in your cron service.'); }
        elseif ($a === 'test') {
            if ((string)es('cron', 'web_key', '') === '') edu_settings_save('cron', ['web_key' => bin2hex(random_bytes(16))]);
            $url = rtrim(SITE_URL, '/') . '/cron?key=' . es('cron', 'web_key', '') . '&test=1';
            $t = microtime(true);
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => true, CURLOPT_USERAGENT => 'EduMint cron test']);
            $body = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            $ms = round((microtime(true) - $t) * 1000);
            $j = is_string($body) ? json_decode($body, true) : null;
            if ($code === 200 && !empty($j['ok'])) admin_flash("Connection OK — the cron URL answered in {$ms} ms (HTTP 200).");
            else admin_flash('Connection failed: ' . ($err ?: "HTTP $code" . ($code === 403 ? ' (wrong key)' : '')) . ". Make sure {$url} is reachable from the internet.", 'error');
        }
        elseif ($a === 'retention') { edu_settings_save('cron', ['visitor_days' => in_array((int)($_POST['days'] ?? 0), [0, 30, 90, 180, 365], true) ? (int)$_POST['days'] : 0]); admin_flash('Retention saved.'); }
    } catch (Throwable $e) { admin_flash('Failed: ' . $e->getMessage(), 'error'); }
    header('Location: /admin/cron-manager.php');
    exit;
}
$s = edu_cron_state();
$runs = json_decode((string)@file_get_contents(edu_private_dir('cron') . '/runs.json'), true) ?: [];
$age = isset($s['minute_at']) ? time() - (int)$s['minute_at'] : null;
$running = $age !== null && $age < 180;
$ago = function (?int $ts) { if (!$ts) return 'never'; $d = time() - $ts; return $d < 60 ? $d . 's ago' : ($d < 3600 ? floor($d / 60) . ' min ago' : ($d < 86400 ? floor($d / 3600) . ' h ago' : floor($d / 86400) . ' days ago')); };
$pendingPush = (int)$pdo->query("SELECT COUNT(*) FROM push_queue q JOIN push_campaigns c ON c.id = q.campaign_id WHERE q.status = 'pending' AND c.created_at >= NOW() - INTERVAL 1 DAY")->fetchColumn();
$days = (int)es('cron', 'visitor_days', 0);
$cronLine = '* * * * * /usr/bin/php ' . ROOT_PATH . '/push_notifications/cron-process-push-queue.php >/dev/null 2>&1';
if ((string)es('cron', 'web_key', '') === '') edu_settings_save('cron', ['web_key' => bin2hex(random_bytes(16))]);
$webUrl = rtrim(SITE_URL, '/') . '/cron?key=' . es('cron', 'web_key', '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title>Cron Manager - <?= htmlspecialchars(edu_site_name()) ?></title>
<?php admin_shell_head(); ?>
<style>.cr-status{display:flex;align-items:center;gap:14px;padding:16px 20px;border-radius:14px;margin:0 0 16px;border:1px solid}.cr-status.ok{background:#f0fdf4;border-color:#bbf7d0;color:#166534}.cr-status.bad{background:#fef2f2;border-color:#fecaca;color:#991b1b}.cr-status i{font-size:1.6rem}.cr-status small{display:block;opacity:.85}.cr-card{background:#fff;border:1px solid var(--gray-200,#e5e7eb);border-radius:14px;padding:18px 20px;margin:0 0 16px}.cr-card h3{margin:0 0 2px;font-size:1.02rem}.cr-card .cr-when{color:var(--gray-500,#6b7280);font-size:.85rem;margin:0 0 10px}.cr-job{display:flex;justify-content:space-between;gap:12px;padding:8px 0;border-bottom:1px solid #f1f5f9;font-size:.9rem}.cr-job span:last-child{color:var(--gray-500,#6b7280);text-align:right}.cr-foot{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-top:10px;font-size:.82rem;color:var(--gray-500,#6b7280)}.cr-table{width:100%;border-collapse:collapse;font-size:.85rem}.cr-table th,.cr-table td{padding:8px 10px;border-bottom:1px solid #f1f5f9;text-align:left;vertical-align:top}.cr-code{display:block;padding:10px 12px;border-radius:8px;background:#0f172a;color:#e2e8f0;font:12px/1.5 ui-monospace,Menlo,monospace;white-space:pre-wrap;word-break:break-all}</style>
</head>
<body>
<?php admin_shell_open('Cron Manager', 'Scheduled jobs that keep the site running'); ?>
<?= admin_flash() ?>
<div class="cr-status <?= $running ? 'ok' : 'bad' ?>"><i class="fas <?= $running ? 'fa-circle-check' : 'fa-triangle-exclamation' ?>"></i><div><strong><?= $running ? 'Scheduler is running' : 'Scheduler has not run recently' ?></strong><small><?= $running ? 'The server runs the jobs every minute. Last heartbeat ' . $ago((int)$s['minute_at']) . '.' : 'Last heartbeat: ' . $ago($s['minute_at'] ?? null) . '. Check the server crontab (line below).' ?></small></div></div>
<div class="cr-card"><h3><i class="fas fa-bolt"></i> Every-minute jobs</h3><p class="cr-when">Every minute</p>
  <div class="cr-job"><span>Deliver push notifications</span><span><?= $pendingPush ? $pendingPush . ' waiting' : 'Nothing waiting' ?></span></div>
  <div class="cr-foot"><span>Last run: <?= $ago($s['minute_at'] ?? null) ?><?= isset($s['minute_ms']) ? ' · ' . (int)$s['minute_ms'] . ' ms' : '' ?> · <?= htmlspecialchars($s['minute_result'] ?? '—') ?></span>
    <form method="POST"><?= csrf_field() ?><input type="hidden" name="action" value="minute"><button class="btn btn-secondary btn-sm"><i class="fas fa-play"></i> Run now</button></form></div>
</div>
<div class="cr-card"><h3><i class="fas fa-broom"></i> Daily maintenance</h3><p class="cr-when">Once a day, after 3:00 AM (India time)</p>
  <div class="cr-job"><span>Remove old push delivery records (30+ days)</span><span>Automatic</span></div>
  <div class="cr-job"><span>Visitor log cleanup</span><span><?= $days ? "Older than $days days" : 'Kept (retention: forever)' ?></span></div>
  <div class="cr-job"><span>Remove unfinished uploads and old exports</span><span>Older than 1 day</span></div>
  <div class="cr-foot"><span>Last run: <?= $ago($s['daily_at'] ?? null) ?><?= isset($s['daily_ms']) ? ' · ' . (int)$s['daily_ms'] . ' ms' : '' ?></span>
    <form method="POST"><?= csrf_field() ?><input type="hidden" name="action" value="daily"><button class="btn btn-secondary btn-sm"><i class="fas fa-play"></i> Run now</button></form></div>
</div>
<form class="cr-card" method="POST"><?= csrf_field() ?><input type="hidden" name="action" value="retention">
  <h3><i class="fas fa-user-clock"></i> Visitor log retention</h3><p class="cr-when">Anonymous per-visit rows used for unique-visitor counts. View totals are kept either way.</p>
  <select name="days" style="padding:8px 10px;border:1px solid #d1d5db;border-radius:8px"><?php foreach ([0 => 'Keep forever', 30 => '30 days', 90 => '90 days', 180 => '180 days', 365 => '1 year'] as $k => $l): ?><option value="<?= $k ?>" <?= $days === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
  <button class="btn btn-primary btn-sm">Save</button>
</form>
<div class="cr-card"><h3><i class="fas fa-clock-rotate-left"></i> Recent runs</h3><p class="cr-when">Runs that did something, plus every manual and daily run (last 30)</p>
  <?php if (!$runs): ?><p style="color:var(--gray-500)">No runs recorded yet.</p><?php else: ?>
  <div style="overflow-x:auto"><table class="cr-table"><thead><tr><th>When</th><th>Job</th><th>Trigger</th><th>Result</th><th>Time</th></tr></thead><tbody>
  <?php foreach ($runs as $r): ?><tr><td style="white-space:nowrap"><?= date('d/m/Y, H:i:s', strtotime($r['at'])) ?></td><td><?= htmlspecialchars($r['job']) ?></td><td><?= htmlspecialchars($r['trigger']) ?></td><td><?= htmlspecialchars($r['result']) ?></td><td><?= (int)$r['ms'] ?> ms</td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</div>
<div class="cr-card"><h3><i class="fas fa-plug"></i> Cron setup</h3><p class="cr-when">Use <b>one</b> of these. The server crontab is best; the URL is for an outside service (cron-job.org, a hosting panel’s “cron jobs” page) when the crontab can’t be used.</p>
  <div class="cr-job" style="display:block;border:0"><strong>1. Server crontab (every minute)</strong><code class="cr-code" style="margin-top:6px"><?= htmlspecialchars($cronLine) ?></code></div>
  <div class="cr-job" style="display:block;border:0"><strong>2. Cron URL (call it every minute)</strong>
    <div style="display:flex;gap:8px;margin-top:6px;flex-wrap:wrap"><input id="cr-url" readonly value="<?= htmlspecialchars($webUrl) ?>" style="flex:1;min-width:260px;padding:8px 10px;border:1px solid #d1d5db;border-radius:8px;font:12.5px ui-monospace,Menlo,monospace"><button type="button" class="btn btn-secondary btn-sm" onclick="var i=document.getElementById('cr-url');i.select();navigator.clipboard&&navigator.clipboard.writeText(i.value);this.textContent='Copied'"><i class="fas fa-copy"></i> Copy</button></div>
    <small style="display:block;color:var(--gray-500);margin-top:6px">Keep this URL private — the key lets anyone start the jobs (they still run at most once a minute).<?= !empty($s['web_at']) ? ' Last called: ' . $ago((int)$s['web_at']) . '.' : '' ?></small></div>
  <div class="cr-foot"><form method="POST" onsubmit="return confirm('Make a new key? The old cron URL stops working.')"><?= csrf_field() ?><input type="hidden" name="action" value="web_key"><button class="btn btn-secondary btn-sm"><i class="fas fa-key"></i> New key</button></form>
    <form method="POST"><?= csrf_field() ?><input type="hidden" name="action" value="test"><button class="btn btn-primary btn-sm"><i class="fas fa-satellite-dish"></i> Test connection</button></form></div>
</div>
<?php admin_shell_close(); ?>
</body>
</html>
