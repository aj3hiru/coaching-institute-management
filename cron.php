<?php
// Web cron: https://site/cron?key=… — for an outside service (cron-job.org, hosting panel) when the server crontab
// can't be used. Runs the same jobs as the server cron; ?test=1 only answers (Cron Manager → Test connection).
require_once __DIR__ . '/includes/config.php';
require_once INCLUDES_PATH . '/cron.php';
if (session_status() === PHP_SESSION_ACTIVE) session_abort();
header_remove('Set-Cookie');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

$key = (string)es('cron', 'web_key', '');
if ($key === '' || !hash_equals($key, (string)($_GET['key'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Wrong or missing key']);
    exit;
}
if (isset($_GET['test'])) {
    echo json_encode(['ok' => true, 'test' => true, 'time' => date('c')]);
    exit;
}
// at most one run every 50 seconds, however often the URL is called
$s = edu_cron_state();
if (time() - (int)($s['minute_at'] ?? 0) < 50) {
    echo json_encode(['ok' => true, 'skipped' => 'ran less than a minute ago']);
    exit;
}
ignore_user_abort(true);
set_time_limit(120);
edu_cron_state(['web_at' => time()]);
edu_cron_tick();
echo json_encode(['ok' => true, 'result' => edu_cron_state()['minute_result'] ?? '']);
