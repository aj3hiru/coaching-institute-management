<?php
// Server cron, every minute: delivers queued push notifications and runs the daily clean-up (see includes/cron.php).
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';
require dirname(__DIR__) . '/includes/config.php';
require_once INCLUDES_PATH . '/cron.php';

$lock = fopen(sys_get_temp_dir() . '/edumint-push-cron.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit; // another run is still busy

edu_cron_tick();
