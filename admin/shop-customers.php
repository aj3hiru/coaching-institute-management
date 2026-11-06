<?php
// Online store → Customers: buyer accounts (linked to institute students by mobile), what each one can open,
// give / take back access, set a new password, and make an account for an institute student.
require_once __DIR__ . '/components/guard.php';
require_once INCLUDES_PATH . '/institute.php';
require_once INCLUDES_PATH . '/shop.php';
$me = sh_page_start($pdo, ['customers']);
sh_tables($pdo);
$id = (int)($_GET['id'] ?? $_POST['uid'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $a = (string)($_POST['action'] ?? '');
    $back = $id ? '/admin/shop-customers.php?id=' . $id : '/admin/shop-customers.php';
    try {
        if ($a === 'create') {
            $sid = (int)($_POST['student_id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? '')); $mobile = trim((string)($_POST['mobile'] ?? '')); $email = trim((string)($_POST['email'] ?? ''));
            if ($sid) { // from an institute student: use their details where the form is empty
                $s = $pdo->prepare("SELECT name, mobile, email FROM ci_students WHERE id = ?"); $s->execute([$sid]);
                if ($r = $s->fetch(PDO::FETCH_ASSOC)) { $name = $name ?: $r['name']; $mobile = $mobile ?: $r['mobile']; $email = $email ?: (string)$r['email']; }
            }
            $pass = (string)($_POST['pass'] ?? '');
            $made = false;
            if ($pass === '') { $pass = substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(9))), 0, 8); $made = true; }
            $new = sh_register($pdo, $name, $mobile, $email, $pass, (int)$me['id']);
            if ($sid) $pdo->prepare("UPDATE sh_users SET student_id = ? WHERE id = ?")->execute([$sid, $new]);
            admin_log('shop_customer', "Created buyer account #$new for $name");
            admin_flash('Account created.' . ($made ? ' Password: ' . $pass . ' — give it to the student now (it is not shown again). They can change it under Profile.' : ''));
            $back = '/admin/shop-customers.php?id=' . $new;
        } elseif ($id && $a === 'grant') {
            $pid = (int)($_POST['product_id'] ?? 0);
            $days = ($_POST['days'] ?? '') === '' ? null : max(0, (int)$_POST['days']);
            sh_grant($pdo, $id, $pid, 'admin', null, (int)$me['id'], trim((string)($_POST['note'] ?? '')), $days);
            admin_log('shop_access', "Gave product #$pid to buyer #$id");
            admin_flash('Access given.');
        } elseif ($id && $a === 'revoke') {
            $pdo->prepare("UPDATE sh_access SET revoked = 1 WHERE id = ? AND user_id = ?")->execute([(int)$_POST['aid'], $id]);
            admin_log('shop_access', "Took back access #" . (int)$_POST['aid'] . " from buyer #$id");
            admin_flash('Access taken back.');
        } elseif ($id && $a === 'restore') {
            $pdo->prepare("UPDATE sh_access SET revoked = 0 WHERE id = ? AND user_id = ?")->execute([(int)$_POST['aid'], $id]);
            admin_flash('Access given back.');
        } elseif ($id && $a === 'save') {
            $name = trim((string)($_POST['name'] ?? '')); $mobile = sh_clean_mobile($_POST['mobile'] ?? ''); $email = strtolower(trim((string)($_POST['email'] ?? '')));
            if (mb_strlen($name) < 2) throw new RuntimeException('Enter the name.');
            if (!preg_match('/^[6-9]\d{9}$/', $mobile)) throw new RuntimeException('Enter a valid 10-digit mobile number.');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid email.');
            $q = $pdo->prepare("SELECT COUNT(*) FROM sh_users WHERE (mobile = ? OR email = ?) AND id <> ?"); $q->execute([$mobile, $email, $id]);
            if ((int)$q->fetchColumn()) throw new RuntimeException('Another account already uses this mobile or email.');
            $status = ($_POST['status'] ?? '') === 'blocked' ? 'blocked' : 'active';
            $pdo->prepare("UPDATE sh_users SET name = ?, mobile = ?, email = ?, status = ?, student_id = COALESCE(student_id, ?) WHERE id = ?")->execute([mb_substr($name, 0, 150), $mobile, $email, $status, sh_find_student($pdo, $mobile), $id]);
            admin_flash('Saved.');
        } elseif ($id && $a === 'password') {
            $pass = (string)($_POST['pass'] ?? '');
            $made = false;
            if ($pass === '') { $pass = substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(9))), 0, 8); $made = true; }
            if (strlen($pass) < 6) throw new RuntimeException('The password must have at least 6 characters.');
            $pdo->prepare("UPDATE sh_users SET pass = ?, reset_hash = NULL WHERE id = ?")->execute([password_hash($pass, PASSWORD_DEFAULT), $id]);
            admin_log('shop_customer', "Set a new password for buyer #$id");
            admin_flash('New password saved' . ($made ? ': ' . $pass . ' — give it to the student now.' : '.'));
        } elseif ($id && $a === 'unlink') {
            $pdo->prepare("UPDATE sh_users SET student_id = NULL WHERE id = ?")->execute([$id]);
            admin_flash('No longer linked to the institute student.');
        }
    } catch (RuntimeException $e) { admin_flash($e->getMessage(), 'error'); }
    header('Location: ' . $back);
    exit;
}

$products = $pdo->query("SELECT id, title, type, price, validity_days, status FROM sh_products ORDER BY status = 'published' DESC, title")->fetchAll(PDO::FETCH_ASSOC);
$cu = null;
if ($id) {
    $q = $pdo->prepare("SELECT u.*, s.name AS sname, s.reg_no, s.id AS sid FROM sh_users u LEFT JOIN ci_students s ON s.id = u.student_id WHERE u.id = ?");
    try { $q->execute([$id]); $cu = $q->fetch(PDO::FETCH_ASSOC) ?: null; } catch (Throwable) { $q = $pdo->prepare("SELECT * FROM sh_users WHERE id = ?"); $q->execute([$id]); $cu = $q->fetch(PDO::FETCH_ASSOC) ?: null; }
    if (!$cu) { header('Location: /admin/shop-customers.php'); exit; }
    $acc = $pdo->prepare("SELECT a.*, p.title, p.type, u.username AS by_name FROM sh_access a JOIN sh_products p ON p.id = a.product_id LEFT JOIN users u ON u.id = a.granted_by WHERE a.user_id = ? ORDER BY a.revoked, a.granted_at DESC");
    $acc->execute([$id]); $acc = $acc->fetchAll(PDO::FETCH_ASSOC);
    $ord = $pdo->prepare("SELECT * FROM sh_orders WHERE user_id = ? ORDER BY id DESC LIMIT 50"); $ord->execute([$id]); $ord = $ord->fetchAll(PDO::FETCH_ASSOC);
} else {
    $qq = trim((string)($_GET['q'] ?? ''));
    $w = ''; $args = [];
    if ($qq !== '') { $w = 'WHERE u.name LIKE ? OR u.mobile LIKE ? OR u.email LIKE ?'; $args = ["%$qq%", "%$qq%", "%$qq%"]; }
    $st = $pdo->prepare("SELECT u.*, (SELECT COUNT(*) FROM sh_access a WHERE a.user_id = u.id AND a.revoked = 0 AND (a.expires_at IS NULL OR a.expires_at > NOW())) AS items,
        (SELECT COALESCE(SUM(o.amount),0) FROM sh_orders o WHERE o.user_id = u.id AND o.status = 'paid') AS spent FROM sh_users u $w ORDER BY u.id DESC LIMIT 500");
    $st->execute($args); $list = $st->fetchAll(PDO::FETCH_ASSOC);
    $students = [];
    try { $students = $pdo->query("SELECT s.id, s.name, s.reg_no, s.mobile FROM ci_students s WHERE s.status = 'active' AND NOT EXISTS (SELECT 1 FROM sh_users u WHERE u.student_id = s.id OR u.mobile = s.mobile) ORDER BY s.name LIMIT 2000")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable) {}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title>Store Customers - <?= ci_e(edu_site_name()) ?></title>
<?php admin_shell_head(); ci_head(); ?>
</head>
<body>
<?php admin_shell_open($cu ? ci_e($cu['name']) : 'Store Customers', $cu ? 'Buyer account' : 'Online accounts of students and buyers', $cu ? '<a class="ci-btn ci-ghost" href="/admin/shop-customers.php"><i class="fas fa-arrow-left"></i> All customers</a>' : '<a class="ci-btn" href="?new=1#mk"><i class="fas fa-user-plus"></i> Add Student / Customer</a>'); ?>
<div class="ci">
<?= admin_flash() ?>
<?php if ($cu): ?>
<div class="ci-grid2">
  <div class="ci-card"><div class="ci-card-h"><h3>Details</h3><?= $cu['status'] === 'active' ? '<span class="ci-badge ci-b-green">Active</span>' : '<span class="ci-badge ci-b-red">Blocked</span>' ?></div><div class="ci-card-b">
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="uid" value="<?= $id ?>">
    <div class="ci-grid2"><div class="ci-f"><label>Name</label><input name="name" required value="<?= ci_e($cu['name']) ?>"></div>
    <div class="ci-f"><label>Mobile</label><input name="mobile" required inputmode="numeric" value="<?= ci_e($cu['mobile']) ?>"></div>
    <div class="ci-f"><label>Email</label><input name="email" type="email" required value="<?= ci_e($cu['email']) ?>"></div>
    <div class="ci-f"><label>Status</label><select name="status"><option value="active">Active</option><option value="blocked" <?= $cu['status'] === 'blocked' ? 'selected' : '' ?>>Blocked (cannot log in)</option></select></div></div>
    <p class="ci-muted" style="margin:8px 0">Joined <?= date('d M Y', strtotime($cu['created_at'])) ?><?= $cu['last_login'] ? ' · last login ' . date('d M Y, h:i A', strtotime($cu['last_login'])) : ' · never logged in' ?></p>
    <?php if (!empty($cu['sid'])): ?><p style="margin:0 0 10px">Institute student: <a href="/admin/student-view.php?id=<?= (int)$cu['sid'] ?>"><?= ci_e($cu['sname']) ?> (<?= ci_e($cu['reg_no']) ?>)</a></p><?php endif; ?>
    <button class="ci-btn" type="submit">Save</button></form>
    <?php if (!empty($cu['sid'])): ?><form method="post" style="margin-top:8px" onsubmit="return confirm('Unlink this account from the institute student?')"><?= csrf_field() ?><input type="hidden" name="action" value="unlink"><input type="hidden" name="uid" value="<?= $id ?>"><button class="ci-btn ci-ghost ci-sm" type="submit">Unlink student</button></form><?php endif; ?>
  </div></div>
  <div class="ci-card"><div class="ci-card-h"><h3>Password</h3></div><div class="ci-card-b">
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="password"><input type="hidden" name="uid" value="<?= $id ?>">
    <div class="ci-f"><label>New password</label><input name="pass" minlength="6" autocomplete="new-password" placeholder="leave empty to make one"><span class="ci-hint">The student can also reset it by email from the login page.</span></div>
    <button class="ci-btn ci-ghost" type="submit" style="margin-top:8px" onclick="return confirm('Set a new password for this account?')">Set new password</button></form>
  </div></div>
</div>

<div class="ci-card"><div class="ci-card-h"><h3>Can open</h3></div><div class="ci-card-b">
  <form method="post" class="ci-filters" style="margin:0 0 12px"><?= csrf_field() ?><input type="hidden" name="action" value="grant"><input type="hidden" name="uid" value="<?= $id ?>">
    <div class="ci-f" style="min-width:260px"><label>Give a course / notes</label><select name="product_id" required data-search><option value=""></option><?php foreach ($products as $pp): ?><option value="<?= (int)$pp['id'] ?>"><?= ci_e($pp['title'] . ' — ' . ((float)$pp['price'] > 0 ? ci_money0($pp['price']) : 'Free') . ($pp['status'] !== 'published' ? ' (' . $pp['status'] . ')' : '')) ?></option><?php endforeach; ?></select></div>
    <div class="ci-f"><label>For how long</label><select name="days"><option value="">Product's own access period</option><option value="0">Lifetime</option><option value="30">1 month</option><option value="90">3 months</option><option value="180">6 months</option><option value="365">1 year</option></select></div>
    <div class="ci-f"><label>Note</label><input name="note" maxlength="200" placeholder="e.g. Offline batch student, paid cash"></div>
    <div class="ci-f"><label>&nbsp;</label><button class="ci-btn" type="submit"><i class="fas fa-key"></i> Give access</button></div>
  </form>
</div><div class="ci-card-b ci-p0 ci-table-wrap"><table class="ci-table"><thead><tr><th>Item</th><th>How</th><th>Since</th><th>Till</th><th>Action</th></tr></thead><tbody>
<?php if (!$acc): ?><tr><td colspan="5" class="ci-empty">Nothing yet.</td></tr><?php endif; ?>
<?php foreach ($acc as $x): $expired = $x['expires_at'] && strtotime($x['expires_at']) < time(); ?>
<tr<?= $x['revoked'] ? ' style="opacity:.55"' : '' ?>><td><b><?= ci_e($x['title']) ?></b> <small class="ci-muted"><?= $x['type'] === 'notes' ? 'PDF notes' : 'Video course' ?></small></td>
<td><?= ['purchase' => 'Bought', 'admin' => 'Given' . ($x['by_name'] ? ' by ' . ci_e($x['by_name']) : ''), 'free' => 'Free'][$x['source']] ?? ci_e($x['source']) ?><?= $x['note'] ? '<small class="ci-muted" style="display:block">' . ci_e($x['note']) . '</small>' : '' ?></td>
<td class="ci-nw"><?= date('d M Y', strtotime($x['granted_at'])) ?></td>
<td class="ci-nw"><?= $x['revoked'] ? '<span class="ci-badge ci-b-red">Taken back</span>' : ($x['expires_at'] ? ($expired ? '<span class="ci-badge ci-b-amber">Ended ' . date('d M Y', strtotime($x['expires_at'])) . '</span>' : date('d M Y', strtotime($x['expires_at']))) : 'Lifetime') ?></td>
<td><?= $x['revoked'] ? ci_post_ico('check', 'Give back', ['action' => 'restore', 'aid' => $x['id'], 'uid' => $id]) : ci_post_ico('trash', 'Take back access', ['action' => 'revoke', 'aid' => $x['id'], 'uid' => $id], 'Take back access to ' . $x['title'] . '? (A paid purchase is not refunded by this.)') ?></td></tr>
<?php endforeach; ?>
</tbody></table></div></div>

<div class="ci-card"><div class="ci-card-h"><h3>Orders</h3></div><div class="ci-card-b ci-p0 ci-table-wrap"><table class="ci-table"><thead><tr><th>Order</th><th>Item</th><th class="ci-num">Amount</th><th>Status</th><th>Payment id</th><th>Date</th></tr></thead><tbody>
<?php if (!$ord): ?><tr><td colspan="6" class="ci-empty">No orders.</td></tr><?php endif; ?>
<?php foreach ($ord as $o): ?><tr><td><?= ci_e($o['order_no'] ?: '#' . $o['id']) ?></td><td><?= ci_e($o['title']) ?></td><td class="ci-num"><?= ci_money0($o['amount']) ?></td><td><?= ['paid' => '<span class="ci-badge ci-b-green">Paid</span>', 'created' => '<span class="ci-badge ci-b-amber">Not paid</span>', 'failed' => '<span class="ci-badge ci-b-red">Failed</span>'][$o['status']] ?? '' ?></td><td><small><?= ci_e($o['rzp_payment'] ?: '—') ?></small></td><td class="ci-nw"><?= date('d M Y, h:i A', strtotime($o['paid_at'] ?: $o['created_at'])) ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>

<?php else: ?>
<details class="ci-card" id="mk" <?= isset($_GET['new']) ? 'open' : '' ?>><summary class="ci-card-h" style="cursor:pointer"><h3>Add a student / customer account</h3><span class="ci-muted">for an institute student or anyone</span></summary><div class="ci-card-b">
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="create">
<div class="ci-grid3">
  <div class="ci-f" style="grid-column:1/-1"><label>Institute student (optional)</label><select name="student_id" data-search><option value="">— Not an institute student —</option><?php foreach ($students as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (int)($_GET['student'] ?? 0) === (int)$s['id'] ? 'selected' : '' ?>><?= ci_e($s['name'] . ' (' . $s['reg_no'] . ') · ' . $s['mobile']) ?></option><?php endforeach; ?></select><span class="ci-hint">Their name, mobile and email are used if you leave those empty. Students who already have an account are not listed.</span></div>
  <div class="ci-f"><label>Name</label><input name="name" maxlength="150"></div>
  <div class="ci-f"><label>Mobile</label><input name="mobile" inputmode="numeric" maxlength="14"></div>
  <div class="ci-f"><label>Email</label><input name="email" type="email" maxlength="190"><span class="ci-hint">Needed for password reset.</span></div>
  <div class="ci-f"><label>Password</label><input name="pass" minlength="6" autocomplete="new-password" placeholder="leave empty to make one"></div>
</div>
<div style="margin-top:12px"><button class="ci-btn" type="submit"><i class="fas fa-user-plus"></i> Make account</button></div>
</form></div></details>
<form class="ci-filters" method="get"><div class="ci-f" style="min-width:280px"><label>Search</label><input name="q" value="<?= ci_e($qq) ?>" placeholder="Name, mobile or email"></div><div class="ci-f"><label>&nbsp;</label><button class="ci-btn" type="submit"><i class="fas fa-search"></i> Search</button></div></form>
<div class="ci-card"><div class="ci-card-b ci-p0 ci-table-wrap"><table class="ci-table"><thead><tr><th>Name</th><th>Mobile</th><th>Email</th><th>Institute</th><th class="ci-num">Items</th><th class="ci-num">Spent</th><th>Joined</th><th>Action</th></tr></thead><tbody>
<?php if (!$list): ?><tr><td colspan="8" class="ci-empty">No accounts yet.</td></tr><?php endif; ?>
<?php foreach ($list as $r): ?><tr><td><a href="?id=<?= (int)$r['id'] ?>"><b><?= ci_e($r['name']) ?></b></a><?= $r['status'] !== 'active' ? ' <span class="ci-badge ci-b-red">Blocked</span>' : '' ?></td><td><?= ci_e($r['mobile']) ?></td><td><?= ci_e($r['email']) ?></td>
<td><?= $r['student_id'] ? '<a href="/admin/student-view.php?id=' . (int)$r['student_id'] . '">Student</a>' : '—' ?></td><td class="ci-num"><?= (int)$r['items'] ?></td><td class="ci-num"><?= ci_money0($r['spent']) ?></td><td class="ci-nw"><?= date('d M Y', strtotime($r['created_at'])) ?></td>
<td><div class="ci-icos"><?= ci_ico('eye', '?id=' . (int)$r['id'], 'Open') ?></div></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<?php endif; ?>
</div>
<?php admin_shell_close(); ?>
</body>
</html>
