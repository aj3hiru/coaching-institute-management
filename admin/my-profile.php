<?php
// My Profile — the signed-in user's account (email, password) and their author profile shown on posts.
require_once __DIR__ . '/components/guard.php';
$me = admin_guard(['admin', 'editor', 'author']);
$st = $pdo->prepare("SELECT * FROM authors WHERE user_id = ? ORDER BY id LIMIT 1");
$st->execute([$me['id']]);
$author = $st->fetch(PDO::FETCH_ASSOC) ?: null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $section = $_POST['section'] ?? '';
    if ($section === 'account') {
        $email = filter_var(trim((string)($_POST['email'] ?? '')), FILTER_VALIDATE_EMAIL);
        $new = (string)($_POST['new_password'] ?? '');
        if (!$email) admin_flash('Please enter a valid email address.', 'error');
        elseif ($new !== '' && strlen($new) < 8) admin_flash('The new password must have at least 8 characters.', 'error');
        else {
            $dupe = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND id <> ?");
            $dupe->execute([$email, $me['id']]);
            if ($dupe->fetchColumn()) admin_flash('Another user already uses this email.', 'error');
            else {
                $pdo->prepare("UPDATE users SET email = ? WHERE id = ?")->execute([$email, $me['id']]);
                if ($new !== '') {
                    $newHash = password_hash($new, PASSWORD_BCRYPT);
                    $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$newHash, $me['id']]);
                    session_regenerate_id(true);
                    // other browsers are signed out (their tokens no longer match); this one stays signed in
                    if (function_exists('edu_rm_issue')) { edu_rm_forget($pdo); edu_rm_issue($pdo, (int)$me['id'], $newHash); }
                }
                admin_log('profile_update', 'Updated own account' . ($new !== '' ? ' (password changed)' : ''));
                admin_flash($new !== '' ? 'Account saved and password changed.' : 'Account saved.');
            }
        }
    } elseif ($section === 'author') {
        $s = fn($k, $max = 255) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max);
        $u = fn($k) => ($v = trim((string)($_POST[$k] ?? ''))) !== '' && filter_var($v, FILTER_VALIDATE_URL) ? $v : null;
        $name = $s('name', 100);
        if ($name === '') admin_flash('Please enter your display name.', 'error');
        else {
            $img = ltrim($s('profile_image'), '/');
            $slugIn = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($s('slug', 150))), '-');
            $base = $slugIn !== '' ? $slugIn : (trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-') ?: 'author-' . $me['id']);
            $slug = $base;
            for ($i = 2; ; $i++) { $c = $pdo->prepare("SELECT COUNT(*) FROM authors WHERE slug = ? AND id <> ?"); $c->execute([$slug, (int)($author['id'] ?? 0)]); if (!$c->fetchColumn()) break; $slug = "$base-$i"; }
            $pub = filter_var($s('author_email', 150), FILTER_VALIDATE_EMAIL) ?: null;
            if ($pub) { $c = $pdo->prepare("SELECT COUNT(*) FROM authors WHERE email = ? AND id <> ?"); $c->execute([$pub, (int)($author['id'] ?? 0)]); if ($c->fetchColumn()) $pub = null; }
            $vals = [
                'name' => $name, 'full_name' => $s('full_name', 150) ?: $name, 'slug' => $slug, 'bio' => $s('bio', 5000), 'profile_image' => $img ?: null,
                'designation' => $s('designation', 150), 'qualifications' => $s('qualifications', 1000), 'experience' => $s('experience', 100),
                'certifications' => $s('certifications', 1000), 'languages_known' => $s('languages_known'), 'email' => $pub,
                'mobile_number' => $s('mobile_number', 20), 'address' => $s('address', 1000),
                'instagram' => $u('instagram'), 'threads' => $u('threads'), 'linkedin' => $u('linkedin'), 'facebook' => $u('facebook'), 'twitter' => $u('twitter'),
            ];
            if ($author) {
                $pdo->prepare("UPDATE authors SET " . implode(', ', array_map(fn($k) => "$k = ?", array_keys($vals))) . " WHERE id = ?")->execute([...array_values($vals), $author['id']]);
            } else {
                $vals['user_id'] = $me['id'];
                $vals['status'] = 'active';
                $pdo->prepare("INSERT INTO authors (" . implode(', ', array_keys($vals)) . ") VALUES (" . rtrim(str_repeat('?, ', count($vals)), ', ') . ")")->execute(array_values($vals));
            }
            edu_clear_page_cache();
            admin_log('profile_update', 'Updated own author profile');
            admin_flash('Author profile saved. It shows on your posts.');
        }
    }
    header('Location: /admin/my-profile.php');
    exit;
}
$a = $author ?? [];
$v = fn($k) => htmlspecialchars((string)($a[$k] ?? ''), ENT_QUOTES, 'UTF-8');
$img = !empty($a['profile_image']) ? '/' . ltrim($a['profile_image'], '/') : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title>My Profile - <?= htmlspecialchars(edu_site_name()) ?></title>
<?php admin_shell_head(); ?>
<script src="/assets/js/admin-media.js?v=<?= @filemtime(ROOT_PATH . '/assets/js/admin-media.js') ?>" defer></script>
<style>.mp-head{display:flex;align-items:center;gap:16px;margin:0 0 1.25rem;padding:1.25rem;border:1px solid var(--gray-200,#e5e7eb);border-radius:14px;background:#fff}.mp-avatar{width:72px;height:72px;border-radius:50%;object-fit:cover;background:var(--primary-light,#ede9fe);display:grid;place-items:center;font-size:28px;font-weight:700;color:var(--primary);flex-shrink:0}.mp-head h2{margin:0;font-size:1.25rem}.mp-head p{margin:2px 0 0;color:var(--gray-500,#6b7280);font-size:.9rem}.mp-role{display:inline-block;margin-left:6px;padding:2px 8px;border-radius:99px;background:var(--primary-light,#ede9fe);color:var(--primary);font-size:.72rem;font-weight:700;text-transform:uppercase}</style>
<style>.mp-pw{position:relative;width:100%;max-width:25rem}.mp-pw .wps-input{padding-right:44px;width:100%;max-width:none}.mp-eye{position:absolute;right:4px;top:50%;transform:translateY(-50%);width:36px;height:34px;border:0;background:none;color:#64748b;cursor:pointer;border-radius:6px}.mp-eye:hover{color:var(--primary,#7c3aed);background:#f5f3ff}</style>
</head>
<body>
<?php admin_shell_open('My Profile', 'Your account and the author profile shown on your posts'); ?>
<?= admin_flash() ?>
<div class="mp-head">
  <?php if ($img): ?><img class="mp-avatar" src="<?= htmlspecialchars($img) ?>" alt=""><?php else: ?><span class="mp-avatar"><?= htmlspecialchars(mb_strtoupper(mb_substr($me['username'], 0, 1))) ?></span><?php endif; ?>
  <div><h2><?= htmlspecialchars($a['name'] ?? $me['username']) ?> <span class="mp-role"><?= htmlspecialchars($me['role']) ?></span></h2><p>Signed in as <?= htmlspecialchars($me['username']) ?> · <?= htmlspecialchars($me['email']) ?></p></div>
</div>
<form class="wps-wrap" method="POST">
  <?= csrf_field() ?><input type="hidden" name="section" value="account">
  <section class="wps-section"><h2>Account</h2><p class="wps-section-desc">Your sign-in details.</p>
    <div class="wps-table">
      <div class="wps-row"><div class="wps-th"><span>Username</span></div><div class="wps-td"><input class="wps-input" value="<?= htmlspecialchars($me['username']) ?>" disabled><p class="wps-desc">Usernames can’t be changed.</p></div></div>
      <div class="wps-row"><div class="wps-th"><label for="email">Email</label></div><div class="wps-td"><input id="email" type="email" name="email" class="wps-input" required value="<?= htmlspecialchars($me['email']) ?>"></div></div>
      <div class="wps-row"><div class="wps-th"><label for="np">New Password</label></div><div class="wps-td"><div class="mp-pw"><input id="np" type="password" name="new_password" class="wps-input" autocomplete="new-password" minlength="8" placeholder="Leave empty to keep the current password"><button type="button" class="mp-eye" aria-label="Show password" title="Show password" onclick="var i=document.getElementById('np'),s=i.type==='password';i.type=s?'text':'password';this.setAttribute('aria-label',s?'Hide password':'Show password');this.title=this.getAttribute('aria-label');this.querySelector('i').className='fas '+(s?'fa-eye-slash':'fa-eye')"><i class="fas fa-eye"></i></button></div><p class="wps-desc">At least 8 characters. Other devices are signed out when it changes.</p></div></div>
    </div>
  </section>
  <p class="wps-submit"><button type="submit" class="wps-primary">Save Account</button></p>
</form>
<form class="wps-wrap" method="POST" style="margin-top:1.25rem">
  <?= csrf_field() ?><input type="hidden" name="section" value="author">
  <section class="wps-section"><h2>Author Profile</h2><p class="wps-section-desc">Shown in the author box at the end of your posts and on your author page.<?= $author ? '' : ' You don’t have an author profile yet — saving creates one.' ?></p>
    <div class="wps-table">
      <div class="wps-row"><div class="wps-th"><span>Photo</span></div><div class="wps-td"><div class="wps-icon-row"><div class="wps-icon<?= $img ? '' : ' empty' ?>" id="ph-prev"><?= $img ? '<img src="' . htmlspecialchars($img) . '" alt="">' : '<i class="fas fa-user"></i>' ?></div></div><div class="wps-media-actions"><button type="button" class="wps-secondary" id="ph-pick">Media Library</button><button type="button" class="wps-secondary" id="ph-clear">Remove</button></div><input type="hidden" name="profile_image" id="profile_image" value="<?= htmlspecialchars(ltrim((string)($a['profile_image'] ?? ''), '/')) ?>"><p class="wps-desc">A square photo works best.</p></div></div>
      <div class="wps-row"><div class="wps-th"><label for="name">Display Name</label></div><div class="wps-td"><input id="name" name="name" class="wps-input" required value="<?= $v('name') ?: htmlspecialchars($me['username']) ?>"></div></div>
      <div class="wps-row"><div class="wps-th"><label for="full_name">Full Name</label></div><div class="wps-td"><input id="full_name" name="full_name" class="wps-input" value="<?= $v('full_name') ?>"></div></div>
      <div class="wps-row"><div class="wps-th"><label for="designation">Designation</label></div><div class="wps-td"><input id="designation" name="designation" class="wps-input" placeholder="e.g. Senior Editor" value="<?= $v('designation') ?>"></div></div>
      <div class="wps-row"><div class="wps-th"><label for="bio">Bio</label></div><div class="wps-td"><textarea id="bio" name="bio" class="wps-input large" rows="4"><?= $v('bio') ?></textarea></div></div>
      <div class="wps-row"><div class="wps-th"><label for="slug">Profile Address</label></div><div class="wps-td"><input id="slug" name="slug" class="wps-input" value="<?= $v('slug') ?>" placeholder="Made from your display name"><p class="wps-desc"><?= htmlspecialchars(rtrim(SITE_URL, '/')) ?>/author/<?= $v('slug') ?: '…' ?></p></div></div>
    </div>
  </section>
  <section class="wps-section"><h2>Education &amp; Experience</h2><p class="wps-section-desc">Shown on your author page — it tells readers (and Google) why you can be trusted on these topics.</p>
    <div class="wps-table">
      <div class="wps-row"><div class="wps-th"><label for="qualifications">Qualifications</label></div><div class="wps-td"><input id="qualifications" name="qualifications" class="wps-input" placeholder="e.g. M.A. Political Science, B.Ed" value="<?= $v('qualifications') ?>"></div></div>
      <div class="wps-row"><div class="wps-th"><label for="experience">Experience</label></div><div class="wps-td"><input id="experience" name="experience" class="wps-input" placeholder="e.g. 6 years in education journalism" value="<?= $v('experience') ?>"></div></div>
      <div class="wps-row"><div class="wps-th"><label for="certifications">Certifications</label></div><div class="wps-td"><textarea id="certifications" name="certifications" class="wps-input" rows="2" placeholder="Any notable certifications or awards"><?= $v('certifications') ?></textarea></div></div>
      <div class="wps-row"><div class="wps-th"><label for="languages_known">Languages Known</label></div><div class="wps-td"><input id="languages_known" name="languages_known" class="wps-input" placeholder="Hindi, English" value="<?= $v('languages_known') ?>"></div></div>
    </div>
  </section>
  <section class="wps-section"><h2>Contact <small style="font-weight:400;color:var(--gray-500)">(optional)</small></h2><p class="wps-section-desc">Only filled fields are shown publicly.</p>
    <div class="wps-table">
      <div class="wps-row"><div class="wps-th"><label for="author_email">Public Email</label></div><div class="wps-td"><input id="author_email" type="email" name="author_email" class="wps-input" value="<?= $v('email') ?>" placeholder="name@example.com"></div></div>
      <div class="wps-row"><div class="wps-th"><label for="mobile_number">Mobile</label></div><div class="wps-td"><input id="mobile_number" name="mobile_number" class="wps-input" maxlength="20" value="<?= $v('mobile_number') ?>" placeholder="+91 XXXXX XXXXX"></div></div>
      <div class="wps-row"><div class="wps-th"><label for="address">City / Address</label></div><div class="wps-td"><input id="address" name="address" class="wps-input" value="<?= $v('address') ?>" placeholder="City, State"></div></div>
    </div>
  </section>
  <section class="wps-section"><h2>Social Profiles</h2><p class="wps-section-desc">Shown as icons in your author box.</p>
    <div class="wps-table">
      <?php foreach (['instagram' => 'Instagram', 'threads' => 'Threads', 'linkedin' => 'LinkedIn', 'facebook' => 'Facebook', 'twitter' => 'X (Twitter)'] as $k => $l): ?>
      <div class="wps-row"><div class="wps-th"><label for="s-<?= $k ?>"><?= $l ?></label></div><div class="wps-td"><input id="s-<?= $k ?>" type="url" name="<?= $k ?>" class="wps-input" placeholder="https://" value="<?= $v($k) ?>"></div></div>
      <?php endforeach; ?>
    </div>
  </section>
  <p class="wps-submit"><button type="submit" class="wps-primary">Save Author Profile</button> <?php if (!empty($a['slug'])): ?><a class="btn btn-secondary" href="/author/<?= htmlspecialchars($a['slug']) ?>" target="_blank">View author page</a><?php endif; ?></p>
</form>
<?php admin_shell_close(); ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var f = document.getElementById('profile_image'), p = document.getElementById('ph-prev');
  function set(path) { f.value = path ? path.replace(/^\//, '') : ''; p.classList.toggle('empty', !path); p.innerHTML = path ? '<img src="' + path + '" alt="">' : '<i class="fas fa-user"></i>'; }
  document.getElementById('ph-pick').onclick = function () { EduMedia.pick(function (it) { set(it.path); }); };
  document.getElementById('ph-clear').onclick = function () { set(''); };
});
</script>
</body>
</html>
