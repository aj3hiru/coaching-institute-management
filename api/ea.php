<?php
// Analytics collector for assets/js/ea.js — n=pv (page view) or n=en (time on page). Always answers 204 (no content).
ini_set('display_errors', '0');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit; }
http_response_code(204);
$ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
if (!empty($_COOKIE['edu_ab'])) exit; // the site's own team (admin bar) is not counted
if (($_SERVER['HTTP_SEC_PURPOSE'] ?? $_SERVER['HTTP_PURPOSE'] ?? '') !== '' && stripos((string)($_SERVER['HTTP_SEC_PURPOSE'] ?? $_SERVER['HTTP_PURPOSE']), 'prefetch') !== false) exit;
require_once dirname(__DIR__) . '/includes/ea.php';
if (ea_is_bot($ua)) exit;
require_once dirname(__DIR__) . '/includes/config.php';
$in = $_POST ?: (json_decode((string)file_get_contents('php://input'), true) ?: []);
$url = substr((string)($in['u'] ?? ''), 0, 1000);
if ($url === '') exit;
try {
    if (($in['n'] ?? '') === 'en') ea_record_engagement($pdo, $url, (int)($in['d'] ?? 0), $ua);
    else ea_record_pageview($pdo, $url, substr((string)($in['r'] ?? ''), 0, 1000), (int)($in['w'] ?? 0), (int)($in['p'] ?? 0), $ua);
} catch (Throwable $e) {
    error_log('ea: ' . $e->getMessage());
}
