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
<div class="au au-one">
  <?= sh_auth_side('forgot') ?>
  <section class="au-main">
  <?php if ($sent): ?>
    <div class="st-msg ok">If an account uses <b><?= sh_e($email) ?></b>, a link to set a new password has been sent. It works for 1 hour — check your spam folder too.</div>
  <?php else: ?>
    <?php if ($err): ?><div class="st-msg err"><?= sh_e($err) ?></div><?php endif; ?>
    <form method="post" novalidate class="au-form"><?= sh_csrf_field() ?>
      <div class="au-f"><label class="au-sr" for="fg-e">Email</label><div class="au-in"><?= sh_auth_icon('mail') ?><input id="fg-e" placeholder="Email" name="email" type="email" autocomplete="email" required value="<?= sh_e($email) ?>" autofocus></div><small>We will email you a link to set a new password.</small></div>
      <button class="au-btn" type="submit">Send reset link <?= sh_icon('arrow') ?></button>
    </form>
  <?php endif; ?>
    <p class="au-alt"><a href="/account/login">Back to log in</a></p>
  </section>
</div>
<?php require ROOT_PATH . '/components/store-close.php'; ?>
