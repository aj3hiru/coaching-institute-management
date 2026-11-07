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
<div class="st-auth">
  <h1>Create your account</h1>
  <p class="st-lead">One account for all your courses and notes. Already a student of our institute? Use the same mobile number.</p>
  <?php if ($err): ?><div class="st-msg err"><?= sh_e($err) ?></div><?php endif; ?>
  <form method="post" novalidate><?= sh_csrf_field() ?><input type="hidden" name="next" value="<?= sh_e($next) ?>">
    <input type="text" name="website" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true">
    <div class="st-f"><label for="rg-n">Full name</label><input id="rg-n" name="name" autocomplete="name" required maxlength="150" value="<?= sh_e($f['name']) ?>" autofocus></div>
    <div class="st-f"><label for="rg-m">Mobile number</label><input id="rg-m" name="mobile" type="tel" inputmode="numeric" autocomplete="tel" required maxlength="14" placeholder="10 digits" value="<?= sh_e($f['mobile']) ?>"></div>
    <div class="st-f"><label for="rg-e">Email</label><input id="rg-e" name="email" type="email" autocomplete="email" required maxlength="190" value="<?= sh_e($f['email']) ?>"><small>Used to reset your password.</small></div>
    <div class="st-f"><label for="rg-p">Password</label><div class="st-pw"><input id="rg-p" type="password" name="pass" autocomplete="new-password" required minlength="4"><button type="button" aria-label="Show password">Show</button></div><small>At least 4 characters.</small></div>
    <div class="st-f"><label for="rg-p2">Password again</label><input id="rg-p2" type="password" name="pass2" autocomplete="new-password" required></div>
    <button class="st-btn block" type="submit">Create account</button>
  </form>
  <p class="alt">Have an account? <a href="/account/login?next=<?= rawurlencode($next) ?>">Log in</a></p>
</div>
<?php require ROOT_PATH . '/components/store-close.php'; ?>
