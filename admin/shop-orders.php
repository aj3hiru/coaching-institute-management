<?php
// Online store → Orders: every purchase (online with Razorpay, or made at the office: cash / UPI / card …), filters and totals.
// "+ New order" (components/sh-enroll.php) records an office payment and gives the course / notes at once.
require_once __DIR__ . '/components/guard.php';
require_once INCLUDES_PATH . '/institute.php';
require_once INCLUDES_PATH . '/shop.php';
$me = sh_page_start($pdo, ['orders']);
sh_tables($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    if (($_POST['action'] ?? '') === 'manual') { // an order made at the office
        $back = (string)($_POST['back'] ?? ''); if (!preg_match('#^/admin/[\w./?=&%-]*$#', $back)) $back = '/admin/shop-orders.php';
        try {
            $w = (string)($_POST['who'] ?? '');
            $who = $w === 'acc' ? ['uid' => (int)($_POST['uid'] ?? 0)] : ($w === 'stu' ? ['sid' => (int)($_POST['sid'] ?? 0)] : ['new' => ['name' => $_POST['n_name'] ?? '', 'mobile' => $_POST['n_mobile'] ?? '', 'email' => $_POST['n_email'] ?? '', 'pass' => $_POST['n_pass'] ?? '']]);
            if (($w === 'acc' && !$who['uid']) || ($w === 'stu' && !$who['sid'])) throw new RuntimeException('Choose the student first.');
            $days = ($_POST['days'] ?? '') === '' ? null : max(0, (int)$_POST['days']);
            $amt = ($_POST['amount'] ?? '') === '' ? null : (float)$_POST['amount'];
            $r = sh_manual_order($pdo, $who, (int)($_POST['product_id'] ?? 0), $amt, (string)($_POST['mode'] ?? ''), ci_clean_date($_POST['paid_on'] ?? ''), trim((string)($_POST['note'] ?? '')), $days, (int)$me['id']);
            admin_log('shop_order', ($r['order_no'] ?: 'Free access') . " — {$r['title']} for {$r['name']} (office)");
            admin_flash(($r['order_no'] ? 'Order ' . $r['order_no'] . ' saved: ' . ci_money0($r['amount']) . ' received' : 'Given free') . ' — ' . $r['name'] . ' can open "' . $r['title'] . '" now.'
                . ($r['password'] ? ' New online account — login: mobile number, password: ' . $r['password'] . ' (tell the student now).' : ''));
        } catch (RuntimeException $e) { admin_flash($e->getMessage(), 'error'); }
        header('Location: ' . $back); exit;
    }
    if (($_POST['action'] ?? '') === 'mark_paid' && $id && sh_perm($me, 'settings')) { // money reached Razorpay but the order did not update
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
$st = $pdo->prepare("SELECT o.*, u.name, u.mobile, u.email, u.student_id, a.revoked AS a_rev, a.blocked AS a_blk, a.expires_at AS a_exp, (a.id IS NOT NULL) AS a_has FROM sh_orders o LEFT JOIN sh_users u ON u.id = o.user_id LEFT JOIN sh_access a ON a.user_id = o.user_id AND a.product_id = o.product_id WHERE " . implode(' AND ', $w) . " ORDER BY o.id DESC LIMIT 500");
$st->execute($a);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
$paidRows = array_filter($rows, fn($o) => $o['status'] === 'paid');
$sum = array_sum(array_column($paidRows, 'amount'));
$today = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM sh_orders WHERE status = 'paid' AND DATE(paid_at) = CURDATE()")->fetchColumn();
$month = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM sh_orders WHERE status = 'paid' AND paid_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')")->fetchColumn();
$buyers = (int)$pdo->query("SELECT COUNT(*) FROM sh_users")->fetchColumn();
$products = $pdo->query("SELECT id, title FROM sh_products ORDER BY title")->fetchAll(PDO::FETCH_KEY_PAIR);
$access = function (array $o): string { // the buyer's access to the item now
    if ($o['status'] !== 'paid') return '<span class="ci-muted">—</span>';
    if (!(int)$o['a_has'] || (int)$o['a_rev'] || (int)$o['a_blk']) return '<span class="ci-badge ci-b-red">' . ((int)$o['a_blk'] ? 'Blocked' : 'Removed') . '</span>';
    if (!$o['a_exp']) return '<span class="ci-badge ci-b-green">Lifetime</span>';
    $t = strtotime($o['a_exp']);
    if ($t <= time()) return '<span class="ci-badge ci-b-amber">Expired ' . date('d M Y', $t) . '</span>';
    $d = (int)ceil(($t - time()) / 86400);
    return '<span class="ci-badge ci-b-blue">Till ' . date('d M Y', $t) . '</span><small class="ci-muted" style="display:block">' . $d . ' day' . ($d === 1 ? '' : 's') . ' left</small>';
};
$badge = fn($s) => '<span class="ci-badge ' . ['paid' => 'ci-b-green', 'created' => 'ci-b-amber', 'failed' => 'ci-b-red'][$s] . '">' . ['paid' => 'Paid', 'created' => 'Not paid', 'failed' => 'Failed'][$s] . '</span>';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title>Store Orders - <?= ci_e(edu_site_name()) ?></title>
<?php admin_shell_head(); ci_head(); ?>
<style>
/* phones: each order is a small card (label : value), no sideways scrolling */
@media (max-width:640px){
  .ci .ci-stats{grid-template-columns:1fr 1fr!important;gap:8px}.ci .ci-stats .ci-stat{padding:10px}.ci .ci-stats .ci-stat b{font-size:16px}
  .or-table thead{display:none}
  .or-table,.or-table tbody{display:block;width:100%}
  .ci-sheet .or-table tr,.or-table tr{display:grid!important;grid-template-columns:1fr 1fr;gap:6px 12px;border:1px solid #e5e7eb!important;margin:0 0 10px;padding:10px 12px;background:#fff}
  .ci-sheet .or-table td,.or-table td{display:block!important;height:auto!important;min-height:0!important;border:0!important;padding:0!important;text-align:left!important;white-space:normal!important;font-size:13px;background:none!important}
  .or-table td::before{content:attr(data-l);display:block;color:#9ca3af;font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.03em;margin-bottom:1px}
  .or-table td[data-l=Buyer],.or-table td[data-l=Item]{grid-column:1/-1}
  .or-table tr{grid-auto-flow:dense}.or-table td[data-l=Order]{grid-column:1}.or-table td[data-l=Date]{grid-column:2;grid-row:1;text-align:right!important}
  .or-table td[data-l=Buyer] small{display:inline!important;margin-left:6px}
  .or-table td[data-l=Payment] small{word-break:break-all}
  .or-table td.ci-empty{grid-column:1/-1;text-align:center!important}.or-table td.ci-empty::before{content:none}
}
</style>
</head>
<body>
<?php admin_shell_open('Store Orders', 'Purchases of courses and notes — online or at the office', sh_perm($me, 'orders') ? '<button type="button" class="ci-btn" data-enroll="New order"><i class="fas fa-plus"></i> New order</button>' : ''); ?>
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
<div class="ci-card"><div class="ci-card-b ci-p0 ci-table-wrap"><table class="ci-table or-table">
<thead><tr><th>Order</th><th>Buyer</th><th>Item</th><th class="ci-num">Amount</th><th>Status</th><th>Access</th><th>Payment</th><th>Date</th></tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="8" class="ci-empty">No orders for these filters.</td></tr><?php endif; ?>
<?php foreach ($rows as $o): ?>
<tr><td class="ci-nw" data-l="Order"><b><?= ci_e($o['order_no'] ?: '#' . $o['id']) ?></b></td>
<td data-l="Buyer"><a href="<?= $o['student_id'] ? '/admin/student-view.php?id=' . (int)$o['student_id'] . '#online' : '/admin/shop-customers.php?id=' . (int)$o['user_id'] ?>"><?= ci_e($o['name'] ?? 'Deleted') ?></a><small class="ci-muted" style="display:block"><?= ci_e($o['mobile'] ?? '') ?></small></td>
<td data-l="Item"><?= ci_e($o['title']) ?></td><td class="ci-num" data-l="Amount"><?= ci_money0($o['amount']) ?></td><td data-l="Status"><?= $badge($o['status']) ?><?= $o['error'] && $o['status'] !== 'paid' ? '<small class="ci-muted" style="display:block">' . ci_e(mb_strimwidth($o['error'], 0, 60, '…')) . '</small>' : '' ?></td>
<td data-l="Access"><?= $access($o) ?></td>
<td class="ci-nw" data-l="Payment"><?= str_starts_with((string)$o['rzp_order'], 'demo_') ? '<span class="ci-badge ci-b-amber">Demo</span> ' : (str_starts_with((string)$o['rzp_order'], 'manual_') ? '<span class="ci-badge ci-b-blue">Office</span> ' : '') ?><small><?= ci_e($o['rzp_payment'] ?: ($o['rzp_order'] ?: '—')) ?></small><?= $o['method'] ? '<small class="ci-muted" style="display:block">' . ci_e($o['method']) . '</small>' : '' ?>
<?php if ($o['status'] !== 'paid' && $o['rzp_order'] && sh_perm($me, 'settings')): ?><details><summary class="ci-muted" style="cursor:pointer;font-size:12px">Paid but not updated?</summary><form method="post" style="display:flex;gap:4px;margin-top:4px" onsubmit="return confirm('Only do this after checking the payment in your Razorpay dashboard. Give access?')"><?= csrf_field() ?><input type="hidden" name="action" value="mark_paid"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><input name="payment" placeholder="pay_…" required style="width:140px;height:30px;padding:0 6px;border:1px solid #d1d5db"><button class="ci-btn ci-sm" type="submit">Mark paid</button></form></details><?php endif; ?></td>
<td class="ci-nw" data-l="Date"><?= date('d M Y, h:i A', strtotime($o['paid_at'] ?: $o['created_at'])) ?></td></tr>
<?php endforeach; ?>
</tbody></table></div></div>
</div>
<?php require __DIR__ . '/components/sh-enroll.php'; ?>
<?php admin_shell_close(); ?>
</body>
</html>
