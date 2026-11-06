<?php
// Online store → Orders: every purchase attempt with Razorpay payment id, filters and totals.
require_once __DIR__ . '/components/guard.php';
require_once INCLUDES_PATH . '/institute.php';
require_once INCLUDES_PATH . '/shop.php';
$me = ci_page_start($pdo, ['shop']);
sh_tables($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    if (($_POST['action'] ?? '') === 'mark_paid' && $id && $me['role'] === 'admin') { // money reached Razorpay but the order did not update
        $pay = trim((string)($_POST['payment'] ?? ''));
        if (!preg_match('/^pay_[A-Za-z0-9]{6,40}$/', $pay)) admin_flash('Enter the Razorpay payment id (starts with pay_) from your Razorpay dashboard.', 'error');
        elseif (sh_order_paid($pdo, $id, $pay, 'manual')) { admin_log('shop_order', "Marked order #$id paid by hand ($pay)"); admin_flash('Order marked as paid and access given.'); }
        else admin_flash('Could not update the order.', 'error');
    }
    header('Location: /admin/shop-orders.php?' . http_build_query(array_intersect_key($_GET, array_flip(['status', 'from', 'to', 'q', 'product']))));
    exit;
}

$status = in_array($_GET['status'] ?? '', ['paid', 'created', 'failed', 'all'], true) ? $_GET['status'] : 'paid';
$from = ci_clean_date($_GET['from'] ?? '') ?? date('Y-m-01');
$to = ci_clean_date($_GET['to'] ?? '') ?? date('Y-m-d');
$qq = trim((string)($_GET['q'] ?? ''));
$prod = (int)($_GET['product'] ?? 0);
$w = ["DATE(COALESCE(o.paid_at, o.created_at)) BETWEEN ? AND ?"]; $a = [$from, $to];
if ($status !== 'all') { $w[] = 'o.status = ?'; $a[] = $status; }
if ($prod) { $w[] = 'o.product_id = ?'; $a[] = $prod; }
if ($qq !== '') { $w[] = '(u.name LIKE ? OR u.mobile LIKE ? OR u.email LIKE ? OR o.order_no LIKE ? OR o.rzp_payment LIKE ?)'; array_push($a, "%$qq%", "%$qq%", "%$qq%", "%$qq%", "%$qq%"); }
$st = $pdo->prepare("SELECT o.*, u.name, u.mobile, u.email FROM sh_orders o LEFT JOIN sh_users u ON u.id = o.user_id WHERE " . implode(' AND ', $w) . " ORDER BY o.id DESC LIMIT 500");
$st->execute($a);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
$paidRows = array_filter($rows, fn($o) => $o['status'] === 'paid');
$sum = array_sum(array_column($paidRows, 'amount'));
$today = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM sh_orders WHERE status = 'paid' AND DATE(paid_at) = CURDATE()")->fetchColumn();
$month = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM sh_orders WHERE status = 'paid' AND paid_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')")->fetchColumn();
$buyers = (int)$pdo->query("SELECT COUNT(*) FROM sh_users")->fetchColumn();
$products = $pdo->query("SELECT id, title FROM sh_products ORDER BY title")->fetchAll(PDO::FETCH_KEY_PAIR);
$badge = fn($s) => '<span class="ci-badge ' . ['paid' => 'ci-b-green', 'created' => 'ci-b-amber', 'failed' => 'ci-b-red'][$s] . '">' . ['paid' => 'Paid', 'created' => 'Not paid', 'failed' => 'Failed'][$s] . '</span>';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title>Store Orders - <?= ci_e(edu_site_name()) ?></title>
<?php admin_shell_head(); ci_head(); ?>
</head>
<body>
<?php admin_shell_open('Store Orders', 'Online purchases of courses and notes'); ?>
<div class="ci">
<?= admin_flash() ?>
<div class="ci-stats">
  <div class="ci-stat"><i class="fas fa-indian-rupee-sign ci-i-green"></i><div><b><?= ci_money0($today) ?></b><small>Sales today</small></div></div>
  <div class="ci-stat"><i class="fas fa-calendar ci-i-violet"></i><div><b><?= ci_money0($month) ?></b><small>Sales this month</small></div></div>
  <div class="ci-stat"><i class="fas fa-receipt ci-i-blue"></i><div><b><?= ci_money0($sum) ?></b><small><?= count($paidRows) ?> paid in the list below</small></div></div>
  <div class="ci-stat"><i class="fas fa-users ci-i-amber"></i><div><b><?= $buyers ?></b><small>Buyer accounts</small></div></div>
</div>
<form class="ci-filters" method="get">
  <div class="ci-f"><label>Search</label><input name="q" value="<?= ci_e($qq) ?>" placeholder="Name, mobile, email, order / payment id"></div>
  <div class="ci-f"><label>Status</label><select name="status"><?php foreach (['paid' => 'Paid', 'created' => 'Not paid', 'failed' => 'Failed', 'all' => 'All'] as $k => $l): ?><option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
  <div class="ci-f"><label>Product</label><select name="product"><option value="0">All products</option><?php foreach ($products as $k => $l): ?><option value="<?= $k ?>" <?= $prod === (int)$k ? 'selected' : '' ?>><?= ci_e($l) ?></option><?php endforeach; ?></select></div>
  <div class="ci-f"><label>From</label><input type="date" name="from" value="<?= ci_e($from) ?>"></div>
  <div class="ci-f"><label>To</label><input type="date" name="to" value="<?= ci_e($to) ?>"></div>
  <div class="ci-f"><label>&nbsp;</label><button class="ci-btn" type="submit"><i class="fas fa-filter"></i> Filter</button></div>
</form>
<div class="ci-card"><div class="ci-card-b ci-p0 ci-table-wrap"><table class="ci-table">
<thead><tr><th>Order</th><th>Buyer</th><th>Item</th><th class="ci-num">Amount</th><th>Status</th><th>Payment</th><th>Date</th></tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="7" class="ci-empty">No orders for these filters.</td></tr><?php endif; ?>
<?php foreach ($rows as $o): ?>
<tr><td class="ci-nw"><b><?= ci_e($o['order_no'] ?: '#' . $o['id']) ?></b></td>
<td><a href="/admin/shop-customers.php?id=<?= (int)$o['user_id'] ?>"><?= ci_e($o['name'] ?? 'Deleted') ?></a><small class="ci-muted" style="display:block"><?= ci_e($o['mobile'] ?? '') ?></small></td>
<td><?= ci_e($o['title']) ?></td><td class="ci-num"><?= ci_money0($o['amount']) ?></td><td><?= $badge($o['status']) ?><?= $o['error'] && $o['status'] !== 'paid' ? '<small class="ci-muted" style="display:block">' . ci_e(mb_strimwidth($o['error'], 0, 60, '…')) . '</small>' : '' ?></td>
<td class="ci-nw"><small><?= ci_e($o['rzp_payment'] ?: ($o['rzp_order'] ?: '—')) ?></small><?= $o['method'] ? '<small class="ci-muted" style="display:block">' . ci_e($o['method']) . '</small>' : '' ?>
<?php if ($o['status'] !== 'paid' && $o['rzp_order'] && $me['role'] === 'admin'): ?><details><summary class="ci-muted" style="cursor:pointer;font-size:12px">Paid but not updated?</summary><form method="post" style="display:flex;gap:4px;margin-top:4px" onsubmit="return confirm('Only do this after checking the payment in your Razorpay dashboard. Give access?')"><?= csrf_field() ?><input type="hidden" name="action" value="mark_paid"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><input name="payment" placeholder="pay_…" required style="width:140px;height:30px;padding:0 6px;border:1px solid #d1d5db"><button class="ci-btn ci-sm" type="submit">Mark paid</button></form></details><?php endif; ?></td>
<td class="ci-nw"><?= date('d M Y, h:i A', strtotime($o['paid_at'] ?: $o['created_at'])) ?></td></tr>
<?php endforeach; ?>
</tbody></table></div></div>
</div>
<?php admin_shell_close(); ?>
</body>
</html>
