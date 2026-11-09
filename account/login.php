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
    elseif (($su = (function () use ($pdo, $id) { $m = sh_clean_mobile($id); $q = $pdo->prepare("SELECT status FROM sh_users WHERE email = ? OR mobile = ? LIMIT 1"); $q->execute([strtolower(trim($id)), $m !== '' ? $m : '-']); return (string)$q->fetchColumn(); })()) !== '' && $su !== 'active') $err = 'This account is suspended. Please contact the institute.'; // only after a wrong-or-blocked try
    else $err = 'Wrong mobile/email or password.';
}
$stTitle = 'Log in';
require ROOT_PATH . '/components/store-open.php';
?>
<div class="au au-one">
  <?= sh_auth_side('login') ?>
  <section class="au-main">
    <div class="au-head"><h1>Log in</h1><p>Log in with your mobile number or email.</p></div>
    <?php if (isset($_GET['reset'])): ?><div class="st-msg ok">Your new password is saved. Please log in.</div><?php endif; ?>
    <?php if ($err): ?><div class="st-msg err"><?= sh_e($err) ?></div><?php endif; ?>
    <form method="post" novalidate class="au-form"><?= sh_csrf_field() ?><input type="hidden" name="next" value="<?= sh_e($next) ?>">
      <div class="au-f"><label for="li-id">Mobile number or email</label><div class="au-in"><?= sh_auth_icon('user') ?><input id="li-id" name="id" autocomplete="username" required value="<?= sh_e($id) ?>" placeholder="98xxxxxxxx or you@email.com" autofocus></div></div>
      <div class="au-f"><div class="au-lrow"><label for="li-pw">Password</label><a href="/account/forgot">Forgot password?</a></div><div class="au-in st-pw"><?= sh_auth_icon('lock') ?><input id="li-pw" type="password" name="pass" autocomplete="current-password" required placeholder="Your password"><?= sh_eye_btn() ?></div></div>
      <button class="au-btn" type="submit">Log in <?= sh_icon('arrow') ?></button>
    </form>
    <p class="au-alt">New here? <a href="/account/register?next=<?= rawurlencode($next) ?>">Create a free account</a></p>
  </section>
</div>
<?php require ROOT_PATH . '/components/store-close.php'; ?>
