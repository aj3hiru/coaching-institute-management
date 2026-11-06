<?php
// Buyer account: My Library (courses + notes with progress), Orders, Profile (name, email, password).  /account/?tab=orders|profile
require_once dirname(__DIR__) . '/includes/config.php';
require_once INCLUDES_PATH . '/shop.php';
$pdo = sh_db();
$u = sh_need_login();
$tab = in_array($_GET['tab'] ?? '', ['orders', 'profile'], true) ? $_GET['tab'] : 'library';
$msg = ''; $err = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $tab === 'profile') {
    if (!sh_csrf_ok()) $err = 'This page had expired. Please try again.';
    else {
        $name = trim(preg_replace('/\s+/', ' ', (string)($_POST['name'] ?? '')));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $new = (string)($_POST['pass'] ?? '');
        try {
            if (mb_strlen($name) < 2) throw new RuntimeException('Please enter your name.');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Please enter a valid email address.');
            $q = $pdo->prepare("SELECT COUNT(*) FROM sh_users WHERE email = ? AND id <> ?"); $q->execute([$email, $u['id']]);
            if ((int)$q->fetchColumn()) throw new RuntimeException('Another account already uses this email.');
            if ($new !== '' || $email !== $u['email']) { // changing the password or the email needs the current password
                if (!password_verify((string)($_POST['cur'] ?? ''), $u['pass'])) throw new RuntimeException('Your current password is not correct.');
            }
            if ($new !== '') {
                if (strlen($new) < 6) throw new RuntimeException('The new password must have at least 6 characters.');
                if ($new !== (string)($_POST['pass2'] ?? '')) throw new RuntimeException('The two new passwords are not the same.');
                $pdo->prepare("UPDATE sh_users SET pass = ? WHERE id = ?")->execute([password_hash($new, PASSWORD_DEFAULT), $u['id']]);
            }
            $pdo->prepare("UPDATE sh_users SET name = ?, email = ? WHERE id = ?")->execute([mb_substr($name, 0, 150), $email, $u['id']]);
            header('Location: /account/?tab=profile&saved=1'); exit;
        } catch (RuntimeException $e) { $err = $e->getMessage(); }
    }
}
if (isset($_GET['saved'])) $msg = 'Your details are saved.';

$lib = $pdo->prepare("SELECT p.*, a.expires_at, a.source,
    (SELECT COUNT(*) FROM sh_lessons l WHERE l.product_id = p.id) AS lessons,
    (SELECT COUNT(*) FROM sh_lessons l JOIN sh_progress g ON g.lesson_id = l.id AND g.user_id = a.user_id WHERE l.product_id = p.id) AS done,
    (SELECT COUNT(*) FROM sh_files f WHERE f.product_id = p.id) AS files
    FROM sh_access a JOIN sh_products p ON p.id = a.product_id
    WHERE a.user_id = ? AND a.revoked = 0 AND (a.expires_at IS NULL OR a.expires_at > NOW()) AND p.status <> 'deleted' ORDER BY a.granted_at DESC");
$lib->execute([$u['id']]);
$lib = $lib->fetchAll(PDO::FETCH_ASSOC);
$orders = [];
if ($tab === 'orders') { $q = $pdo->prepare("SELECT * FROM sh_orders WHERE user_id = ? AND (status = 'paid' OR created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)) ORDER BY id DESC LIMIT 100"); $q->execute([$u['id']]); $orders = $q->fetchAll(PDO::FETCH_ASSOC); }
$stTitle = 'My Library';
require ROOT_PATH . '/components/store-open.php';
?>
<?php if (isset($_GET['welcome'])): ?><div class="st-msg ok">Welcome, <?= sh_e($u['name']) ?>! Your account is ready.</div><?php endif; ?>
<div class="st-acc">
  <nav class="st-nav">
    <div class="who"><b><?= sh_e($u['name']) ?></b><small><?= sh_e($u['mobile']) ?></small></div>
    <a href="/account/" class="<?= $tab === 'library' ? 'on' : '' ?>">My Library</a>
    <a href="/account/?tab=orders" class="<?= $tab === 'orders' ? 'on' : '' ?>">Orders</a>
    <a href="/account/?tab=profile" class="<?= $tab === 'profile' ? 'on' : '' ?>">Profile &amp; password</a>
    <a href="/store">Browse store</a>
    <form method="post" action="/account/logout" style="margin:0"><?= sh_csrf_field() ?><button type="submit" style="display:block;width:100%;text-align:left;padding:11px 14px;border:0;border-top:1px solid #f1f2f4;background:none;font:600 14.5px inherit;font-family:inherit;color:#b91c1c;cursor:pointer">Log out</button></form>
  </nav>
  <div>
  <?php if ($tab === 'library'): ?>
    <h1>My Library</h1>
    <?php if (!$lib): ?><div class="st-empty">You have no courses or notes yet. <a href="/store">Browse the store</a></div><?php endif; ?>
    <div class="st-grid">
    <?php foreach ($lib as $p): $pct = (int)$p['lessons'] ? (int)round((int)$p['done'] * 100 / (int)$p['lessons']) : 0; ?>
      <a class="st-card" href="/store/learn?s=<?= rawurlencode($p['slug']) ?>"><?= sh_thumb_html($p) ?>
        <?php if ((int)$p['lessons']): ?><div class="st-prog"><i style="width:<?= $pct ?>%"></i></div><?php endif; ?>
        <div class="st-cb"><h3><?= sh_e($p['title']) ?></h3>
          <span class="st-meta"><?= (int)$p['lessons'] ? (int)$p['done'] . ' of ' . (int)$p['lessons'] . ' videos done' : (int)$p['files'] . ' PDF' . ((int)$p['files'] === 1 ? '' : 's') ?></span>
          <span class="st-meta"><?= $p['expires_at'] ? 'Access till ' . date('d M Y', strtotime($p['expires_at'])) : 'Lifetime access' ?></span>
          <div class="st-price"><span class="st-btn" style="min-height:36px;padding:0 14px;font-size:14px"><?= $p['type'] === 'course' ? ($pct ? 'Continue' : 'Start') : 'Open notes' ?></span></div>
        </div></a>
    <?php endforeach; ?>
    </div>
  <?php elseif ($tab === 'orders'): ?>
    <h1>Orders</h1>
    <?php if (!$orders): ?><div class="st-empty">No orders yet.</div><?php else: ?>
    <div style="overflow-x:auto"><table class="st-table"><thead><tr><th>Order</th><th>Item</th><th>Amount</th><th>Status</th><th>Date</th></tr></thead><tbody>
    <?php foreach ($orders as $o): ?><tr><td><?= sh_e($o['order_no'] ?: '—') ?></td><td><?= sh_e($o['title']) ?></td><td><?= sh_money($o['amount']) ?></td>
      <td><span class="st-badge <?= sh_e($o['status']) ?>"><?= $o['status'] === 'paid' ? 'Paid' : ($o['status'] === 'failed' ? 'Failed' : 'Not paid') ?></span></td>
      <td><?= date('d M Y, h:i A', strtotime($o['paid_at'] ?: $o['created_at'])) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <p class="st-meta" style="margin-top:10px">Money taken but the item is not in your library? Contact us with the payment id from Razorpay's SMS / email.</p>
    <?php endif; ?>
  <?php else: ?>
    <h1>Profile &amp; password</h1>
    <?php if ($msg): ?><div class="st-msg ok"><?= sh_e($msg) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="st-msg err"><?= sh_e($err) ?></div><?php endif; ?>
    <form method="post" class="st-box" novalidate style="max-width:520px"><div class="st-box-b"><?= sh_csrf_field() ?>
      <div class="st-f"><label for="pf-n">Name</label><input id="pf-n" name="name" required maxlength="150" value="<?= sh_e($u['name']) ?>"></div>
      <div class="st-f"><label>Mobile number</label><input value="<?= sh_e($u['mobile']) ?>" disabled><small>To change your mobile number, please contact us.</small></div>
      <div class="st-f"><label for="pf-e">Email</label><input id="pf-e" name="email" type="email" required maxlength="190" value="<?= sh_e($u['email']) ?>"></div>
      <div class="st-f"><label for="pf-p">New password <span style="font-weight:400;color:#6b7280">(leave empty to keep)</span></label><div class="st-pw"><input id="pf-p" type="password" name="pass" autocomplete="new-password"><button type="button" aria-label="Show password">Show</button></div></div>
      <div class="st-f"><label for="pf-p2">New password again</label><input id="pf-p2" type="password" name="pass2" autocomplete="new-password"></div>
      <div class="st-f"><label for="pf-c">Current password <span style="font-weight:400;color:#6b7280">(needed to change email or password)</span></label><input id="pf-c" type="password" name="cur" autocomplete="current-password"></div>
      <button class="st-btn" type="submit">Save</button>
    </div></form>
  <?php endif; ?>
  </div>
</div>
<?php require ROOT_PATH . '/components/store-close.php'; ?>
