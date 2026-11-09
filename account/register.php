<?php
// New buyer account: name, mobile, email, password. An institute student with the same mobile is linked automatically.
require_once dirname(__DIR__) . '/includes/config.php';
require_once INCLUDES_PATH . '/shop.php';
$pdo = sh_db();
$next = sh_safe_next((string)($_GET['next'] ?? $_POST['next'] ?? ''));
if (sh_user()) { header('Location: ' . $next); exit; }
$err = ''; $f = ['name' => '', 'mobile' => '', 'email' => ''];
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    foreach ($f as $k => $_) $f[$k] = trim((string)($_POST[$k] ?? ''));
    $pass = (string)($_POST['pass'] ?? '');
    if (!sh_csrf_ok()) $err = 'This page had expired. Please try again.';
    elseif (!empty($_POST['website'])) $err = 'Please try again.'; // bots fill the hidden field
    elseif ($pass !== (string)($_POST['pass2'] ?? '')) $err = 'The two passwords are not the same.';
    else {
        try {
            $id = sh_register($pdo, $f['name'], $f['mobile'], $f['email'], $pass);
            $q = $pdo->prepare("SELECT * FROM sh_users WHERE id = ?"); $q->execute([$id]);
            sh_login_user($q->fetch(PDO::FETCH_ASSOC));
            header('Location: ' . $next . (str_contains($next, '?') ? '&' : '?') . 'welcome=1'); exit;
        } catch (RuntimeException $e) { $err = $e->getMessage(); }
    }
}
$stTitle = 'Create account';
require ROOT_PATH . '/components/store-open.php';
?>
<div class="au au-one au-wide">
  <?= sh_auth_side('register') ?>
  <section class="au-main">
    <?php if ($err): ?><div class="st-msg err"><?= sh_e($err) ?></div><?php endif; ?>
    <form method="post" novalidate class="au-form"><?= sh_csrf_field() ?><input type="hidden" name="next" value="<?= sh_e($next) ?>">
      <input type="text" name="website" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true">
      <div class="au-f"><label for="rg-n">Full name</label><div class="au-in"><?= sh_auth_icon('user') ?><input id="rg-n" name="name" autocomplete="name" required maxlength="150" value="<?= sh_e($f['name']) ?>" autofocus></div></div>
      <div class="au-two">
        <div class="au-f"><label for="rg-m">Mobile number</label><div class="au-in"><?= sh_auth_icon('device') ?><input id="rg-m" name="mobile" type="tel" inputmode="numeric" autocomplete="tel" required maxlength="14" value="<?= sh_e($f['mobile']) ?>"></div><small>Already our student? Use the same mobile number.</small></div>
        <div class="au-f"><label for="rg-e">Email</label><div class="au-in"><?= sh_auth_icon('mail') ?><input id="rg-e" name="email" type="email" autocomplete="email" required maxlength="190" value="<?= sh_e($f['email']) ?>"></div><small>Used to reset your password.</small></div>
      </div>
      <div class="au-two">
        <div class="au-f"><label for="rg-p">Password</label><div class="au-in st-pw"><?= sh_auth_icon('lock') ?><input id="rg-p" type="password" name="pass" autocomplete="new-password" required minlength="4"><?= sh_eye_btn() ?></div></div>
        <div class="au-f"><label for="rg-p2">Password again</label><div class="au-in"><?= sh_auth_icon('lock') ?><input id="rg-p2" type="password" name="pass2" autocomplete="new-password" required></div></div>
      </div>
      <button class="au-btn" type="submit">Create account <?= sh_icon('arrow') ?></button>
    </form>
    <p class="au-alt">Have an account? <a href="/account/login?next=<?= rawurlencode($next) ?>">Log in</a></p>
  </section>
</div>
<?php require ROOT_PATH . '/components/store-close.php'; ?>
