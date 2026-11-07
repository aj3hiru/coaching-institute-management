<?php
// Buying (JSON). POST a=create&p=ID → a Razorpay order (or free access at once); a=verify → checks the payment signature and gives access.
require_once dirname(__DIR__) . '/includes/config.php';
require_once INCLUDES_PATH . '/shop.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$out = function (array $d, int $code = 200) { http_response_code($code); exit(json_encode($d, JSON_UNESCAPED_UNICODE)); };
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') $out(['error' => 'Invalid request'], 405);
$pdo = sh_db();
$u = sh_user();
if (!$u) $out(['error' => 'Please log in first.', 'login' => true], 401);
if (!sh_csrf_ok()) $out(['error' => 'This page has expired. Please reload it and try again.'], 400);
$a = (string)($_POST['a'] ?? '');
try {
    if ($a === 'create') {
        $p = sh_product($pdo, (int)($_POST['p'] ?? 0));
        if (!$p || !sh_on()) $out(['error' => 'This item is not available.'], 404);
        $go = sh_learn_url($p['slug']);
        if (sh_has_access($pdo, (int)$u['id'], (int)$p['id'])) $out(['ok' => true, 'owned' => true, 'go' => $go]);
        if ((float)$p['price'] <= 0) { // free item: straight to the library
            sh_grant($pdo, (int)$u['id'], (int)$p['id'], 'free');
            $out(['ok' => true, 'free' => true, 'go' => $go]);
        }
        $pdo->prepare("INSERT INTO sh_orders (user_id, product_id, title, amount) VALUES (?, ?, ?, ?)")->execute([$u['id'], $p['id'], $p['title'], $p['price']]);
        $oid = (int)$pdo->lastInsertId();
        if (sh_pay_demo()) { // demo mode: our own practice checkout, no money moves
            $pdo->prepare("UPDATE sh_orders SET rzp_order = ?, method = 'demo' WHERE id = ?")->execute(['demo_' . $oid . '_' . bin2hex(random_bytes(4)), $oid]);
            $out(['ok' => true, 'demo' => true, 'order' => $oid, 'amount' => (int)round((float)$p['price'] * 100), 'title' => $p['title'], 'go' => $go]);
        }
        try {
            $rzp = sh_rzp_order((float)$p['price'], 'DKO-' . $oid, ['order' => (string)$oid, 'product' => (string)$p['id'], 'buyer' => (string)$u['id']]);
        } catch (RuntimeException $e) {
            $pdo->prepare("UPDATE sh_orders SET status = 'failed', error = ? WHERE id = ?")->execute([mb_substr($e->getMessage(), 0, 300), $oid]);
            throw $e;
        }
        $pdo->prepare("UPDATE sh_orders SET rzp_order = ? WHERE id = ?")->execute([$rzp, $oid]);
        $out(['ok' => true, 'order' => $oid, 'rzp_order' => $rzp, 'key' => sh_rzp_keys()['id'], 'amount' => (int)round((float)$p['price'] * 100),
            'title' => $p['title'], 'go' => $go]);
    }
    if ($a === 'verify') {
        $oid = (int)($_POST['order'] ?? 0);
        $rzpOrder = (string)($_POST['razorpay_order_id'] ?? '');
        $rzpPay = (string)($_POST['razorpay_payment_id'] ?? '');
        $sig = (string)($_POST['razorpay_signature'] ?? '');
        $q = $pdo->prepare("SELECT o.*, p.slug FROM sh_orders o JOIN sh_products p ON p.id = o.product_id WHERE o.id = ? AND o.user_id = ?");
        $q->execute([$oid, $u['id']]);
        $o = $q->fetch(PDO::FETCH_ASSOC);
        if (!$o || $o['rzp_order'] !== $rzpOrder || $rzpPay === '' || $sig === '') $out(['error' => 'Payment details did not match. If money was taken, it will show in your library shortly, or contact us.'], 400);
        $k = sh_rzp_keys();
        if (!hash_equals(hash_hmac('sha256', $rzpOrder . '|' . $rzpPay, $k['secret']), $sig)) {
            $pdo->prepare("UPDATE sh_orders SET error = 'Signature mismatch' WHERE id = ?")->execute([$oid]);
            $out(['error' => 'The payment could not be confirmed. If money was taken, contact us with payment id ' . $rzpPay . '.'], 400);
        }
        if (!sh_order_paid($pdo, $oid, $rzpPay)) $out(['error' => 'Payment received, but access could not be given right now. Please contact us with payment id ' . $rzpPay . '.'], 500);
        $out(['ok' => true, 'go' => sh_learn_url($o['slug'], 'new=1')]);
    }
    if ($a === 'demo_pay' || $a === 'demo_fail') { // only while demo mode is on, only for this buyer's own demo order
        if (!sh_pay_demo()) $out(['error' => 'Demo payments are switched off.'], 403);
        $oid = (int)($_POST['order'] ?? 0);
        $q = $pdo->prepare("SELECT o.*, p.slug FROM sh_orders o JOIN sh_products p ON p.id = o.product_id WHERE o.id = ? AND o.user_id = ? AND o.status = 'created' AND o.rzp_order LIKE 'demo\\_%'");
        $q->execute([$oid, $u['id']]);
        $o = $q->fetch(PDO::FETCH_ASSOC);
        if (!$o) $out(['error' => 'This demo order was not found. Please try again.'], 404);
        if ($a === 'demo_fail') {
            $pdo->prepare("UPDATE sh_orders SET status = 'failed', error = 'Demo: payment failed (test)' WHERE id = ?")->execute([$oid]);
            $out(['ok' => true]);
        }
        $m = preg_replace('/[^a-z]/', '', (string)($_POST['via'] ?? 'upi')) ?: 'upi';
        if (!sh_order_paid($pdo, $oid, 'demo_pay_' . bin2hex(random_bytes(6)), 'demo · ' . $m)) $out(['error' => 'Could not finish the demo payment.'], 500);
        $out(['ok' => true, 'go' => sh_learn_url($o['slug'], 'new=1')]);
    }
    if ($a === 'cancel') { // the buyer closed the payment window
        $pdo->prepare("UPDATE sh_orders SET error = ? WHERE id = ? AND user_id = ? AND status = 'created'")->execute([mb_substr((string)($_POST['why'] ?? 'Closed by buyer'), 0, 300), (int)($_POST['order'] ?? 0), $u['id']]);
        $out(['ok' => true]);
    }
    $out(['error' => 'Unknown request'], 400);
} catch (RuntimeException $e) {
    $out(['error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    error_log('store checkout: ' . $e->getMessage());
    $out(['error' => 'Something went wrong. Please try again.'], 500);
}
