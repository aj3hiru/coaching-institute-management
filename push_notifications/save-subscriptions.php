<?php
// Saves (or refreshes) a browser's push subscription with its device, browser and country.
ini_set('display_errors', '0'); // JSON only; problems go to the error log
header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
require $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once INCLUDES_PATH . '/push.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Invalid request']);
    exit;
}
$data = json_decode((string)file_get_contents('php://input', false, null, 0, 20000), true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid subscription']);
    exit;
}
try {
    $r = edu_push_save_subscription($data, $_SERVER);
} catch (Throwable $e) {
    error_log('push save: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Could not save the subscription']);
    exit;
}
if (!empty($r['error'])) http_response_code(isset($r['key']) ? 409 : 400);
echo json_encode($r);
