<?php
// Orders: the student's paid purchases (failed / unfinished payments never show) and the free courses / notes they
// joined, newest first, each with the item's picture. A paid order is a fixed record: giving or removing access changes
// My Courses, not this list — but an item whose access has ended (validity over or removed) is shown faded and can't be clicked.
require_once dirname(__DIR__) . '/includes/config.php';
require_once INCLUDES_PATH . '/shop.php';
$pdo = sh_db();
$u = sh_need_login();
$q = $pdo->prepare("SELECT o.*, p.slug, p.type, p.thumb, p.mrp FROM sh_orders o LEFT JOIN sh_products p ON p.id = o.product_id WHERE o.user_id = ? AND o.status = 'paid' ORDER BY o.paid_at DESC, o.id DESC LIMIT 200");
$q->execute([$u['id']]);
$orders = $q->fetchAll(PDO::FETCH_ASSOC);
foreach ($orders as &$__o) $__o['at'] = $__o['paid_at'] ?: $__o['created_at']; unset($__o);
// free items joined (no order is made for them)
$q = $pdo->prepare("SELECT a.granted_at AS at, a.product_id, p.title, p.slug, p.type, p.thumb, p.mrp, p.price FROM sh_access a JOIN sh_products p ON p.id = a.product_id
    WHERE a.user_id = ? AND a.source = 'free' AND a.revoked = 0 ORDER BY a.granted_at DESC LIMIT 200");
$q->execute([$u['id']]);
foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $f) $orders[] = $f + ['free' => 1, 'status' => 'joined'];
usort($orders, fn($a, $b) => strcmp((string)$b['at'], (string)$a['at']));
// access of each item now: [ended?, the date it ended]
$acc = [];
$q = $pdo->prepare("SELECT product_id, revoked, expires_at FROM sh_access WHERE user_id = ?"); $q->execute([$u['id']]);
foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $a) $acc[(int)$a['product_id']] = $a;
$ended = function (array $o) use ($acc): array {
    $a = $acc[(int)($o['product_id'] ?? 0)] ?? null;
    if (!$a) return [true, ''];
    if ((int)$a['revoked']) return [true, ''];
    if ($a['expires_at'] !== null && strtotime($a['expires_at']) <= time()) return [true, date('d M Y', strtotime($a['expires_at']))];
    return [false, ''];
};
/** Access of the item now, as a small label: Lifetime access / Access till … (N days left) / Expired on … / Access removed. */
$accLabel = function (array $o) use ($acc): string {
    $a = $acc[(int)($o['product_id'] ?? 0)] ?? null;
    if (!$a || (int)$a['revoked']) return '<span class="ac-acc ac-acc-off">' . sh_icon('clock') . ' Access removed</span>';
    if ($a['expires_at'] === null) return '<span class="ac-acc ac-acc-life">' . sh_icon('check') . ' Lifetime access</span>';
    $t = strtotime($a['expires_at']);
    if ($t <= time()) return '<span class="ac-acc ac-acc-off">' . sh_icon('clock') . ' Expired on ' . date('d M Y', $t) . '</span>';
    $d = (int)ceil(($t - time()) / 86400);
    return '<span class="ac-acc ac-acc-till' . ($d <= 7 ? ' ac-acc-soon' : '') . '">' . sh_icon('clock') . ' Access till ' . date('d M Y', $t) . ' · ' . $d . ' day' . ($d === 1 ? '' : 's') . ' left</span>';
};
$stTitle = 'Orders';
require ROOT_PATH . '/components/store-open.php';
$ico = fn($n) => sh_icon($n);
?>
<div class="ac">
  <?= sh_account_nav($u, 'orders') ?>
  <div class="ac-main">
    <div class="ac-h"><h1>Orders</h1></div>
    <?php if (!$orders): ?>
      <div class="ac-empty"><span class="ac-empty-i"><?= $ico('receipt') ?></span><b>No orders yet</b><p>When you buy or join a course or notes, it shows here.</p><a class="ac-btn" href="/store/"><?= $ico('compass') ?> Browse courses</a></div>
    <?php else: ?>
    <div class="ac-orders">
    <?php foreach ($orders as $o): $st = $o['status']; ?>
      <?php $free = !empty($o['free']); [$off, $offAt] = $ended($o); $link = $o['slug'] && !$off ? sh_url($o) : ''; ?>
      <div class="ac-ord<?= $off ? ' ac-ord-off' : '' ?>"<?= $off ? ' aria-disabled="true"' : '' ?>>
        <<?= $link ? 'a href="' . sh_e($link) . '"' : 'span' ?> class="ac-ord-th" tabindex="-1" aria-hidden="true"><?php if (!empty($o['thumb'])): ?><img src="<?= sh_e($o['thumb']) ?>" alt="" loading="lazy" width="128" height="72"><?php else: ?><span class="ac-ord-i"><?= $ico($o['type'] === 'notes' ? 'notes' : 'video') ?></span><?php endif; ?><em><?= $o['type'] === 'notes' ? 'PDF notes' : 'Course' ?></em></<?= $link ? 'a' : 'span' ?>>
        <div class="ac-ord-t"><b><?= $link ? '<a href="' . sh_e($link) . '">' . sh_e($o['title']) . '</a>' : sh_e($o['title']) ?></b><small><?= $free ? 'Joined free' : sh_e($o['order_no'] ?: 'Order #' . $o['id']) ?> · <?= date('d M Y, h:i A', strtotime((string)$o['at'])) ?><?= !$free && $o['rzp_payment'] && !str_starts_with((string)$o['rzp_payment'], 'pay_DEMO') ? ' · ' . sh_e($o['rzp_payment']) : '' ?></small><?= $accLabel($o) ?></div>
        <div class="ac-ord-r"><?php if ($free): ?><span class="ac-ord-fp"><?= sh_free_price($o) ?></span><span class="st-badge paid">Joined</span><?php else: ?><b><?= sh_money($o['amount']) ?></b><span class="st-badge <?= sh_e($st) ?>"><?= $st === 'paid' ? 'Paid' : ($st === 'failed' ? 'Failed' : 'Not paid') ?></span><?php endif; ?><?php if ($link): ?><a class="ac-ord-go" href="<?= sh_e($link) ?>">View ›</a><?php endif; ?></div>
      </div>
    <?php endforeach; ?>
    </div>
    <p class="ac-hint" style="margin-top:12px">Money taken but the item is not in My Courses? Use <a href="/account/report">Report us</a> with the payment id from Razorpay's SMS / email.</p>
    <?php endif; ?>
  </div>
</div>
<?php require ROOT_PATH . '/components/store-close.php'; ?>
