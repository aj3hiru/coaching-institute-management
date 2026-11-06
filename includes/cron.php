<?php
// Scheduled jobs (run by the server's cron every minute, or by "Run now" in Admin → Tools → Cron Manager).
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/tools.php';

function edu_cron_state(?array $set = null): array
{
    $f = edu_private_dir('cron') . '/state.json';
    $s = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    if ($set !== null) { $s = array_merge($s, $set); file_put_contents($f, json_encode($s)); }
    return $s;
}

function edu_cron_log(string $job, string $trigger, string $result, float $ms): void
{
    $f = edu_private_dir('cron') . '/runs.json';
    $runs = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    array_unshift($runs, ['at' => date('c'), 'job' => $job, 'trigger' => $trigger, 'result' => $result, 'ms' => round($ms)]);
    file_put_contents($f, json_encode(array_slice($runs, 0, 30)));
}

/** Every minute: deliver queued push notifications. */
function edu_cron_minute(string $trigger = 'Automatic'): string
{
    require_once __DIR__ . '/push.php';
    $t = microtime(true);
    $sent = 0; $failed = 0; $end = time() + 45;
    do {
        $r = edu_push_process(null, 1000); // one run at a time, oldest notification first
        if (!empty($r['error']) || !empty($r['busy'])) break;
        $sent += $r['sent']; $failed += $r['failed'];
    } while ($r['campaign'] && time() < $end && ($r['sent'] + $r['failed']) > 0);
    $msg = $sent || $failed ? "Push: $sent delivered, $failed failed" : 'Push: nothing to send';
    // Instant indexing submissions waiting in the queue
    $ix = '';
    try { require_once __DIR__ . '/indexing.php'; $ix = edu_ix_process(edu_db()); } catch (Throwable $e) { $ix = 'indexing error: ' . $e->getMessage(); }
    if ($ix !== '') $msg .= ' · ' . $ix;
    $ms = (microtime(true) - $t) * 1000;
    edu_cron_state(['minute_at' => time(), 'minute_ms' => round($ms), 'minute_result' => $msg]);
    if ($sent || $failed || $ix !== '' || $trigger !== 'Automatic') edu_cron_log('Every minute', $trigger, $msg, $ms);
    return $msg;
}

/** Once a day after 3:00 AM: tidy old data. */
function edu_cron_daily(string $trigger = 'Automatic'): string
{
    $pdo = edu_db();
    $t = microtime(true);
    $parts = [];
    $n = $pdo->exec("DELETE FROM push_queue WHERE status <> 'pending' AND created_at < NOW() - INTERVAL 30 DAY");
    $parts[] = "old push deliveries removed: $n";
    try { $n = $pdo->exec("DELETE FROM seo_index_log WHERE created_at < NOW() - INTERVAL 60 DAY"); $parts[] = "old indexing records removed: $n"; } catch (Throwable $e) {}
    $keep = (int)es('cron', 'visitor_days', 0);
    if ($keep > 0) {
        try { $n = $pdo->exec("DELETE FROM visitor_log WHERE visit_date < CURDATE() - INTERVAL $keep DAY"); $parts[] = "visitor log rows removed: $n"; } catch (Throwable $e) {}
    } else $parts[] = 'visitor log kept (retention: forever)';
    $old = 0;
    foreach (['incoming', 'exports'] as $d) foreach (glob(edu_private_dir($d) . '/*') ?: [] as $f) if (is_file($f) && filemtime($f) < time() - 86400) { @unlink($f); $old++; }
    foreach (glob(edu_private_dir('uploads') . '/*', GLOB_ONLYDIR) ?: [] as $d) if (filemtime($d) < time() - 86400) { exec('rm -rf ' . escapeshellarg($d)); $old++; }
    $parts[] = "unfinished uploads cleaned: $old";
    $msg = implode(' · ', $parts);
    $ms = (microtime(true) - $t) * 1000;
    edu_cron_state(['daily_at' => time(), 'daily_ms' => round($ms), 'daily_result' => $msg]);
    edu_cron_log('Daily', $trigger, $msg, $ms);
    return $msg;
}

/** Called by the cron script every minute. */
function edu_cron_tick(): void
{
    edu_cron_minute();
    $s = edu_cron_state();
    if ((int)date('G') >= 3 && date('Y-m-d', (int)($s['daily_at'] ?? 0)) !== date('Y-m-d')) edu_cron_daily();
}
