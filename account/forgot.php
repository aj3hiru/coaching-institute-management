<?php
// Forgot password: a reset link is emailed (valid 1 hour).
require_once dirname(__DIR__) . '/includes/config.php';
require_once INCLUDES_PATH . '/shop.php';
require_once INCLUDES_PATH . '/mailer.php';
$pdo = sh_db();
$sent = false; $err = ''; $email = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    $last = (int)($_SESSION['sh_reset_at'] ?? 0);
    if (!sh_csrf_ok()) $err = 'This page had expired. Please try again.';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $err = 'Please enter the email of your account.';
    elseif (time() - $last < 60) $err = 'Please wait a minute before asking again.';
    elseif (!edu_mail_ready()) $err = 'Email is not set up on this site yet. Please contact us to reset your password.';
    else { $_SESSION['sh_reset_at'] = time(); sh_send_reset($pdo, $email); $sent = true; }
}
$stTitle = 'Forgot password';
require ROOT_PATH . '/components/store-open.php';
?>
<div class="st-auth">
  <h1>Forgot password</h1>
  <?php if ($sent): ?>
    <div class="st-msg ok">If an account uses <b><?= sh_e($email) ?></b>, a link to set a new password has been sent. It works for 1 hour — check your spam folder too.</div>
    <p class="alt"><a href="/account/login">Back to log in</a></p>
  <?php else: ?>
  <p class="st-lead">Enter the email of your account. We will send you a link to set a new password.</p>
  <?php if ($err): ?><div class="st-msg err"><?= sh_e($err) ?></div><?php endif; ?>
  <form method="post" novalidate><?= sh_csrf_field() ?>
    <div class="st-f"><label for="fg-e">Email</label><input id="fg-e" name="email" type="email" autocomplete="email" required value="<?= sh_e($email) ?>" autofocus></div>
    <button class="st-btn block" type="submit">Send reset link</button>
  </form>
  <p class="alt"><a href="/account/login">Back to log in</a></p>
  <?php endif; ?>
</div>
<?php require ROOT_PATH . '/components/store-close.php'; ?>
