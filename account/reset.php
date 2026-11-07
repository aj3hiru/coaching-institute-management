<?php
// Set a new password from the emailed link (?t=token).
require_once dirname(__DIR__) . '/includes/config.php';
require_once INCLUDES_PATH . '/shop.php';
$pdo = sh_db();
$tok = (string)($_GET['t'] ?? $_POST['t'] ?? '');
$u = null;
if (preg_match('/^[a-f0-9]{48}$/', $tok)) {
    $q = $pdo->prepare("SELECT id FROM sh_users WHERE reset_hash = ? AND reset_until > NOW() AND status = 'active'");
    $q->execute([hash('sha256', $tok)]);
    $u = $q->fetch(PDO::FETCH_ASSOC) ?: null;
}
$err = '';
if ($u && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $p1 = (string)($_POST['pass'] ?? '');
    if (!sh_csrf_ok()) $err = 'This page had expired. Please try again.';
    elseif (strlen($p1) < 4) $err = 'The password must have at least 4 characters.';
    elseif ($p1 !== (string)($_POST['pass2'] ?? '')) $err = 'The two passwords are not the same.';
    else {
        $pdo->prepare("UPDATE sh_users SET pass = ?, reset_hash = NULL, reset_until = NULL WHERE id = ?")->execute([password_hash($p1, PASSWORD_DEFAULT), $u['id']]);
        header('Location: /account/login?reset=1'); exit;
    }
}
$stTitle = 'New password';
require ROOT_PATH . '/components/store-open.php';
?>
<div class="st-auth">
  <h1>Set a new password</h1>
  <?php if (!$u): ?>
    <div class="st-msg err">This link is not valid any more (links work for 1 hour, once). Please ask for a new one.</div>
    <p class="alt"><a href="/account/forgot">Send a new link</a></p>
  <?php else: ?>
  <?php if ($err): ?><div class="st-msg err"><?= sh_e($err) ?></div><?php endif; ?>
  <form method="post" novalidate><?= sh_csrf_field() ?><input type="hidden" name="t" value="<?= sh_e($tok) ?>">
    <div class="st-f"><label for="rs-p">New password</label><div class="st-pw"><input id="rs-p" type="password" name="pass" autocomplete="new-password" required minlength="4" autofocus><button type="button" aria-label="Show password">Show</button></div><small>At least 4 characters.</small></div>
    <div class="st-f"><label for="rs-p2">New password again</label><input id="rs-p2" type="password" name="pass2" autocomplete="new-password" required></div>
    <button class="st-btn block" type="submit">Save password</button>
  </form>
  <?php endif; ?>
</div>
<?php require ROOT_PATH . '/components/store-close.php'; ?>
