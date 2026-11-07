<?php
// Orders: the student's paid purchases only (failed / unfinished payments never show). An order is a fixed record:
// giving or removing course access changes My Courses, not this list.
require_once dirname(__DIR__) . '/includes/config.php';
require_once INCLUDES_PATH . '/shop.php';
$pdo = sh_db();
$u = sh_need_login();
$q = $pdo->prepare("SELECT o.*, p.slug, p.type FROM sh_orders o LEFT JOIN sh_products p ON p.id = o.product_id WHERE o.user_id = ? AND o.status = 'paid' ORDER BY o.paid_at DESC, o.id DESC LIMIT 200");
$q->execute([$u['id']]);
$orders = $q->fetchAll(PDO::FETCH_ASSOC);
$stTitle = 'Orders';
require ROOT_PATH . '/components/store-open.php';
$ico = fn($n) => sh_icon($n);
?>
<div class="ac">
  <?= sh_account_nav($u, 'orders') ?>
  <div class="ac-main">
    <div class="ac-h"><h1>Orders</h1></div>
    <?php if (!$orders): ?>
      <div class="ac-empty"><span class="ac-empty-i"><?= $ico('receipt') ?></span><b>No orders yet</b><p>When you buy a course or notes, the order shows here.</p><a class="ac-btn" href="/store/"><?= $ico('compass') ?> Browse courses</a></div>
    <?php else: ?>
    <div class="ac-orders">
    <?php foreach ($orders as $o): $st = $o['status']; ?>
      <div class="ac-ord">
        <div class="ac-ord-i"><?= $ico($o['type'] === 'notes' ? 'notes' : 'video') ?></div>
        <div class="ac-ord-t"><b><?= sh_e($o['title']) ?></b><small><?= sh_e($o['order_no'] ?: 'Order #' . $o['id']) ?> · <?= date('d M Y, h:i A', strtotime($o['paid_at'] ?: $o['created_at'])) ?><?= $o['rzp_payment'] && !str_starts_with((string)$o['rzp_payment'], 'pay_DEMO') ? ' · ' . sh_e($o['rzp_payment']) : '' ?></small></div>
        <div class="ac-ord-r"><b><?= sh_money($o['amount']) ?></b><span class="st-badge <?= sh_e($st) ?>"><?= $st === 'paid' ? 'Paid' : ($st === 'failed' ? 'Failed' : 'Not paid') ?></span></div>
        <?php if ($o['slug']): ?><a class="ac-ord-go" href="<?= sh_e(sh_url($o)) ?>">View ›</a><?php endif; ?>
      </div>
    <?php endforeach; ?>
    </div>
    <p class="ac-hint" style="margin-top:12px">Money taken but the item is not in My Courses? Use <a href="/account/report">Report us</a> with the payment id from Razorpay's SMS / email.</p>
    <?php endif; ?>
  </div>
</div>
<?php require ROOT_PATH . '/components/store-close.php'; ?>
