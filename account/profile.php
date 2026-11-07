<?php
// My Profile: change the name (mobile / email are the login and stay fixed here) and the password.
require_once dirname(__DIR__) . '/includes/config.php';
require_once INCLUDES_PATH . '/shop.php';
$pdo = sh_db();
$u = sh_need_login();
$err = ''; $msg = isset($_GET['saved']) ? 'Your name is saved.' : (isset($_GET['pw']) ? 'Your new password is saved.' : '');
$openPw = false;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $a = (string)($_POST['a'] ?? '');
    try {
        if (!sh_csrf_ok()) throw new RuntimeException('This page had expired. Please try again.');
        if ($a === 'name') {
            $name = trim(preg_replace('/\s+/', ' ', (string)($_POST['name'] ?? '')));
            if (mb_strlen($name) < 2) throw new RuntimeException('Please enter your name.');
            $pdo->prepare("UPDATE sh_users SET name = ? WHERE id = ?")->execute([mb_substr($name, 0, 150), $u['id']]);
            sh_mark_cookie(explode(' ', $name)[0]);
            header('Location: /account/profile?saved=1'); exit;
        }
        if ($a === 'password') {
            $openPw = true;
            $new = (string)($_POST['pass'] ?? '');
            if (!password_verify((string)($_POST['cur'] ?? ''), $u['pass'])) throw new RuntimeException('Your current password is not correct.');
            if (strlen($new) < 4) throw new RuntimeException('The new password must have at least 4 characters.');
            if ($new !== (string)($_POST['pass2'] ?? '')) throw new RuntimeException('The two new passwords are not the same.');
            $pdo->prepare("UPDATE sh_users SET pass = ? WHERE id = ?")->execute([password_hash($new, PASSWORD_DEFAULT), $u['id']]);
            session_regenerate_id(true);
            header('Location: /account/profile?pw=1'); exit;
        }
    } catch (RuntimeException $e) { $err = $e->getMessage(); }
}
$stTitle = 'My Profile';
require ROOT_PATH . '/components/store-open.php';
$ico = fn($n) => sh_icon($n);
?>
<div class="ac">
  <?= sh_account_nav($u, 'profile') ?>
  <div class="ac-main">
    <div class="ac-h"><h1>My Profile</h1></div>
    <?php if ($msg): ?><div class="st-msg ok"><?= sh_e($msg) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="st-msg err"><?= sh_e($err) ?></div><?php endif; ?>
    <div class="ac-box">
      <form method="post" class="ac-form"><?= sh_csrf_field() ?><input type="hidden" name="a" value="name">
        <div class="st-f"><label for="pf-n">Full name</label><input id="pf-n" name="name" required maxlength="150" value="<?= sh_e($u['name']) ?>"></div>
        <div class="ac-two">
          <div class="st-f"><label>Mobile number (login)</label><input value="<?= sh_e($u['mobile']) ?>" disabled></div>
          <div class="st-f"><label>Email (login)</label><input value="<?= sh_e($u['email']) ?>" disabled></div>
        </div>
        <p class="ac-hint">Your mobile number and email are your login, so they can't be changed here. Contact us if they are wrong.</p>
        <button class="ac-btn" type="submit">Save name</button>
      </form>
    </div>
    <div class="ac-box">
      <div class="ac-row"><div><b>Password</b><small>Keep your account safe with a password only you know.</small></div>
        <button type="button" class="ac-btn ghost" id="acPwBtn" aria-expanded="<?= $openPw ? 'true' : 'false' ?>" aria-controls="acPw"><?= $ico('lock') ?> Change password</button></div>
      <form method="post" class="ac-form ac-pw" id="acPw"<?= $openPw ? '' : ' hidden' ?>><?= sh_csrf_field() ?><input type="hidden" name="a" value="password">
        <div class="st-f"><label for="pf-c">Current password</label><div class="st-pw"><input id="pf-c" type="password" name="cur" autocomplete="current-password" required><button type="button" aria-label="Show password">Show</button></div></div>
        <div class="ac-two">
          <div class="st-f"><label for="pf-p">New password</label><div class="st-pw"><input id="pf-p" type="password" name="pass" autocomplete="new-password" required minlength="4"><button type="button" aria-label="Show password">Show</button></div><small>At least 4 characters.</small></div>
          <div class="st-f"><label for="pf-p2">New password again</label><input id="pf-p2" type="password" name="pass2" autocomplete="new-password" required minlength="4"></div>
        </div>
        <button class="ac-btn" type="submit">Save new password</button>
      </form>
    </div>
  </div>
</div>
<script>document.getElementById('acPwBtn').addEventListener('click',function(){var f=document.getElementById('acPw'),o=f.hidden;f.hidden=!o;this.setAttribute('aria-expanded',o?'true':'false');if(o)document.getElementById('pf-c').focus();});</script>
<?php require ROOT_PATH . '/components/store-close.php'; ?>
