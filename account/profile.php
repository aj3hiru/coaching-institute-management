<?php
// My Profile: photo, name, email, address, pin code (the mobile number is the login and links the store account to the
// institute record, so it stays fixed) and the password (only the new one is asked).
require_once dirname(__DIR__) . '/includes/config.php';
require_once INCLUDES_PATH . '/shop.php';
$pdo = sh_db();
$u = sh_need_login();
$err = ''; $msg = isset($_GET['saved']) ? 'Your profile is saved.' : (isset($_GET['pw']) ? 'Your new password is saved.' : '');
$openPw = false;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $a = (string)($_POST['a'] ?? '');
    try {
        if (!sh_csrf_ok()) throw new RuntimeException('This page had expired. Please try again.');
        if ($a === 'name' || $a === 'profile') {
            $name = trim(preg_replace('/\s+/', ' ', (string)($_POST['name'] ?? '')));
            $email = strtolower(trim((string)($_POST['email'] ?? '')));
            $addr = trim(preg_replace('/\s+/', ' ', (string)($_POST['address'] ?? '')));
            $pin = preg_replace('/\D/', '', (string)($_POST['pincode'] ?? ''));
            if (mb_strlen($name) < 2) throw new RuntimeException('Please enter your name.');
            if ($email === '' && str_ends_with((string)$u['email'], '@noemail.invalid')) $email = (string)$u['email']; // made at the office without an email
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Please enter a correct email address.');
            $q = $pdo->prepare("SELECT id FROM sh_users WHERE email = ? AND id <> ?"); $q->execute([$email, $u['id']]);
            if ($q->fetchColumn()) throw new RuntimeException('This email is already used by another account.');
            if ($pin !== '' && strlen($pin) !== 6) throw new RuntimeException('The pin code must have 6 digits.');
            $av = (string)($u['avatar'] ?? '');
            $dir = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/uploads/avatars/';
            if (!empty($_FILES['photo']) && ($_FILES['photo']['error'] ?? 4) !== UPLOAD_ERR_NO_FILE) {
                [$newAv, $e] = sh_avatar_save($_FILES['photo'], (int)$u['id']);
                if ($e) throw new RuntimeException($e);
                if ($av !== '' && sh_avatar_url($u) !== '') @unlink($dir . $av);
                $av = $newAv;
            } elseif (!empty($_POST['photo_rm']) && $av !== '') { if (sh_avatar_url($u) !== '') @unlink($dir . $av); $av = ''; }
            $pdo->prepare("UPDATE sh_users SET name = ?, email = ?, address = ?, pincode = ?, avatar = ? WHERE id = ?")
                ->execute([mb_substr($name, 0, 150), mb_substr($email, 0, 190), $addr !== '' ? mb_substr($addr, 0, 300) : null, $pin ?: null, $av ?: null, $u['id']]);
            $__nm = preg_split('/\s+/', trim($name)); sh_mark_cookie($__nm[0], sh_avatar_url(['avatar' => $av]), count($__nm) > 1 ? (string)end($__nm) : '');
            header('Location: /account/profile?saved=1'); exit;
        }
        if ($a === 'password') {
            $openPw = true;
            $new = (string)($_POST['pass'] ?? '');
            if (strlen($new) < 4) throw new RuntimeException('The new password must have at least 4 characters.');
            $pdo->prepare("UPDATE sh_users SET pass = ? WHERE id = ?")->execute([password_hash($new, PASSWORD_DEFAULT), $u['id']]);
            session_regenerate_id(true);
            header('Location: /account/profile?pw=1'); exit;
        }
    } catch (RuntimeException $e) {
        $err = $e->getMessage();
        if ($a === 'profile') foreach (['name', 'email', 'address', 'pincode'] as $k) $u[$k] = (string)($_POST[$k] ?? ''); // keep what was typed
    }
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
      <form method="post" class="ac-form" enctype="multipart/form-data"><?= sh_csrf_field() ?><input type="hidden" name="a" value="profile">
        <?php $avUrl = sh_avatar_url($u); ?>
        <div class="ac-photo">
          <span class="ac-photo-i" id="pfPrev"><?php if ($avUrl): ?><img src="<?= sh_e($avUrl) ?>" alt=""><?php else: ?><?= sh_e(mb_strtoupper(mb_substr($u['name'], 0, 1))) ?><?php endif; ?></span>
          <div><b>Profile photo</b><small>JPG, PNG or WebP. It shows at the top of the site when you are logged in.</small>
            <div class="ac-photo-b"><label class="ac-btn ghost" for="pf-ph"><?= $ico('user') ?> <?= $avUrl ? 'Change photo' : 'Add photo' ?></label><input type="file" id="pf-ph" name="photo" accept="image/jpeg,image/png,image/webp" hidden>
            <?php if ($avUrl): ?><label class="ac-photo-rm"><input type="checkbox" name="photo_rm" value="1"> Remove photo</label><?php endif; ?></div></div>
        </div>
        <div class="ac-two">
          <div class="st-f"><label for="pf-n">Full name</label><input id="pf-n" name="name" required maxlength="150" value="<?= sh_e($u['name']) ?>"></div>
          <div class="st-f"><label for="pf-e">Email</label><?php $noMail = str_ends_with((string)$u['email'], '@noemail.invalid'); ?><input id="pf-e" type="email" name="email" <?= $noMail ? 'placeholder="Add your email (for password reset)"' : 'required' ?> maxlength="190" value="<?= $noMail ? '' : sh_e($u['email']) ?>"></div>
        </div>
        <div class="ac-two">
          <div class="st-f"><label>Mobile number (login)</label><input value="<?= sh_e($u['mobile']) ?>" disabled></div>
          <div class="st-f"><label for="pf-pin">Pin code</label><input id="pf-pin" name="pincode" inputmode="numeric" maxlength="6" pattern="\d{6}" placeholder="6 digits" value="<?= sh_e($u['pincode'] ?? '') ?>"></div>
        </div>
        <div class="st-f"><label for="pf-a">Address</label><textarea id="pf-a" name="address" rows="2" maxlength="300" placeholder="House / street, village or town, district"><?= sh_e($u['address'] ?? '') ?></textarea></div>
        <p class="ac-hint">Your mobile number is your login and links your account with the institute, so it can't be changed here. Contact us if it is wrong.</p>
        <button class="ac-btn" type="submit">Save profile</button>
      </form>
    </div>
    <div class="ac-box">
      <div class="ac-row" id="password"><div><b>Password</b><small>Keep your account safe with a password only you know.</small></div>
        <button type="button" class="ac-btn ghost" id="acPwBtn" aria-expanded="<?= $openPw ? 'true' : 'false' ?>" aria-controls="acPw"><?= $ico('lock') ?> Change password</button></div>
      <form method="post" class="ac-form ac-pw" id="acPw"<?= $openPw ? '' : ' hidden' ?>><?= sh_csrf_field() ?><input type="hidden" name="a" value="password">
        <div class="st-f ac-pw1"><label for="pf-p">New password</label><div class="st-pw"><input id="pf-p" type="password" name="pass" autocomplete="new-password" required minlength="4"><button type="button" aria-label="Show password">Show</button></div><small>At least 4 characters.</small></div>
        <button class="ac-btn" type="submit">Save new password</button>
      </form>
    </div>
  </div>
</div>
<script>(function(){var b=document.getElementById('acPwBtn');b.addEventListener('click',function(){var f=document.getElementById('acPw'),o=f.hidden;f.hidden=!o;b.setAttribute('aria-expanded',o?'true':'false');if(o)document.getElementById('pf-p').focus();});
if(location.hash==='#password'&&document.getElementById('acPw').hidden)b.click();
var ph=document.getElementById('pf-ph'),pv=document.getElementById('pfPrev');ph.addEventListener('change',function(){var f=ph.files[0];if(!f)return;if(f.size>5242880){alert('Please choose a photo smaller than 5 MB.');ph.value='';return;}pv.innerHTML='';var i=new Image();i.src=URL.createObjectURL(f);pv.appendChild(i);});})();</script>
<?php require ROOT_PATH . '/components/store-close.php'; ?>
