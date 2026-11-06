<?php
// Razorpay webhook (Dashboard → Webhooks → this address, events: payment.captured, order.paid). Gives access even when
// the buyer's browser closed before returning. Checked with the webhook secret.
require_once dirname(__DIR__) . '/includes/config.php';
require_once INCLUDES_PATH . '/shop.php';
session_write_close();
header('Content-Type: application/json');
$body = (string)file_get_contents('php://input');
$k = sh_rzp_keys();
$sig = (string)($_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '');
if ($k['hook'] === '' || $sig === '' || !hash_equals(hash_hmac('sha256', $body, $k['hook']), $sig)) { http_response_code(400); exit('{"error":"bad signature"}'); }
$d = json_decode($body, true);
$ev = (string)($d['event'] ?? '');
$pay = $d['payload']['payment']['entity'] ?? [];
$rzpOrder = (string)($pay['order_id'] ?? ($d['payload']['order']['entity']['id'] ?? ''));
if (in_array($ev, ['payment.captured', 'order.paid'], true) && $rzpOrder !== '') {
    $pdo = sh_db();
    $q = $pdo->prepare("SELECT id, amount FROM sh_orders WHERE rzp_order = ?");
    $q->execute([$rzpOrder]);
    $o = $q->fetch(PDO::FETCH_ASSOC);
    if ($o && (int)($pay['amount'] ?? round((float)$o['amount'] * 100)) >= (int)round((float)$o['amount'] * 100)) sh_order_paid($pdo, (int)$o['id'], (string)($pay['id'] ?? ''), (string)($pay['method'] ?? ''));
} elseif ($ev === 'payment.failed' && $rzpOrder !== '') {
    sh_db()->prepare("UPDATE sh_orders SET status = IF(status = 'paid', 'paid', 'failed'), error = ? WHERE rzp_order = ?")->execute([mb_substr((string)($pay['error_description'] ?? 'Payment failed'), 0, 300), $rzpOrder]);
}
echo '{"ok":true}';
