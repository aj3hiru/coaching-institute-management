<?php
// Buyer login with mobile number or email + password.  /account/login?next=/courses/…
require_once dirname(__DIR__) . '/includes/config.php';
require_once INCLUDES_PATH . '/shop.php';
$pdo = sh_db();
$next = sh_safe_next((string)($_GET['next'] ?? $_POST['next'] ?? ''));
if (sh_user()) { header('Location: ' . $next); exit; }
$err = ''; $id = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $id = trim((string)($_POST['id'] ?? ''));
    if (!sh_csrf_ok()) $err = 'This page had expired. Please try again.';
    elseif ($id === '' || ($_POST['pass'] ?? '') === '') $err = 'Enter your mobile number or email, and your password.';
    elseif ($u = sh_check_login($pdo, $id, (string)$_POST['pass'])) { sh_login_user($u); header('Location: ' . $next); exit; }
    else $err = 'Wrong mobile/email or password.';
}
$stTitle = 'Log in';
require ROOT_PATH . '/components/store-open.php';
?>
<div class="st-auth">
  <h1>Log in</h1>
  <p class="st-lead">Open your courses and notes.</p>
  <?php if (isset($_GET['reset'])): ?><div class="st-msg ok">Your new password is saved. Please log in.</div><?php endif; ?>
  <?php if ($err): ?><div class="st-msg err"><?= sh_e($err) ?></div><?php endif; ?>
  <form method="post" novalidate><?= sh_csrf_field() ?><input type="hidden" name="next" value="<?= sh_e($next) ?>">
    <div class="st-f"><label for="li-id">Mobile number or email</label><input id="li-id" name="id" autocomplete="username" required value="<?= sh_e($id) ?>" autofocus></div>
    <div class="st-f"><label for="li-pw">Password</label><div class="st-pw"><input id="li-pw" type="password" name="pass" autocomplete="current-password" required><button type="button" aria-label="Show password">Show</button></div></div>
    <p style="text-align:right;margin:-6px 0 14px;font-size:14px"><a href="/account/forgot">Forgot password?</a></p>
    <button class="st-btn block" type="submit">Log in</button>
  </form>
  <p class="alt">New here? <a href="/account/register?next=<?= rawurlencode($next) ?>">Create an account</a></p>
</div>
<?php require ROOT_PATH . '/components/store-close.php'; ?>
