<?php
// Admin sign-in at /admin-login (same look as the CMS / WordPress login). The old address
// /admin/admin-login-portal.php redirects here.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

require_once __DIR__ . '/includes/config.php';
require_once INCLUDES_PATH . '/settings.php';

$ip_address = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN';

/** Where to go after signing in: an address on this site only, else the dashboard. */
function edu_login_target(string $to): string
{
    $to = trim($to);
    if ($to === '' || $to[0] !== '/' || str_starts_with($to, '//') || str_contains($to, '\\') || preg_match('#^/admin-login#', $to)) return '/admin/dashboard.php';
    return $to;
}
$redirect_to = edu_login_target((string)($_GET['redirect_to'] ?? $_POST['redirect_to'] ?? ''));

// Already signed in
if (isset($_SESSION['user_id'], $_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'editor', 'author'], true)) {
    header('Location: ' . $redirect_to);
    exit;
}

// Brute-force lockout (per session)
const MAX_ATTEMPTS = 5;
const LOCKOUT_SECS = 900;
$_SESSION['login_attempts'] ??= 0;
$_SESSION['lockout_until'] ??= 0;
$locked = $_SESSION['lockout_until'] > time();
$lock_left = $locked ? $_SESSION['lockout_until'] - time() : 0;
$error = '';
$login_value = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$locked) {
    $login_value = trim((string)($_POST['log'] ?? ''));
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string)$_POST['csrf_token'])) {
        $error = 'Your session expired. Please try again.';
    } else {
        $password = (string)($_POST['pwd'] ?? '');
        if ($login_value === '' || $password === '') {
            $error = 'Please enter your username or email address and your password.';
        } else {
            try {
                $stmt = $pdo->prepare("SELECT id, username, email, password_hash, role, status, permissions FROM users
                    WHERE (username = ? OR email = ?) AND role IN ('admin','editor','author') LIMIT 1");
                $stmt->execute([$login_value, $login_value]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                // always verify — no timing difference between unknown users and wrong passwords
                $pass_ok = password_verify($password, $user['password_hash'] ?? '$2y$10$invaliddummyhashXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX');
                $log = fn($uid, $type, $d) => $pdo->prepare('INSERT INTO activity_logs (user_id, action_type, description, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)')->execute([$uid, $type, $d, $ip_address, $user_agent]);

                if ($user && $pass_ok) {
                    $perm = json_decode($user['permissions'] ?? '{}', true) ?: [];
                    if ($user['status'] === 'suspended') {
                        $error = 'Your account has been suspended. Please contact the administrator.';
                        $log($user['id'], 'login_blocked', "Suspended account login attempt: {$user['username']}");
                    } elseif ($user['status'] === 'pending') {
                        $error = 'Your account is waiting for approval by an administrator.';
                        $log($user['id'], 'login_blocked', "Pending account login attempt: {$user['username']}");
                    } elseif ($user['role'] !== 'admin' && empty($perm['dashboard_access'])) {
                        $error = 'Your account does not have access to the dashboard.';
                        $log($user['id'], 'login_denied', "No dashboard_access: {$user['username']}");
                    } else {
                        $_SESSION['login_attempts'] = 0;
                        $_SESSION['lockout_until'] = 0;
                        session_regenerate_id(true);
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['username'] = $user['username'];
                        $_SESSION['email'] = $user['email'];
                        $_SESSION['role'] = $user['role'];
                        $_SESSION['login_time'] = time();
                        $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
                        // "Remember Me": the sign-in cookie survives closing the browser (30 days)
                        if (!empty($_POST['rememberme'])) {
                            $p = session_get_cookie_params();
                            setcookie(session_name(), session_id(), ['expires' => time() + 30 * 86400, 'path' => $p['path'] ?: '/', 'domain' => $p['domain'], 'secure' => $secure || $p['secure'], 'httponly' => true, 'samesite' => 'Lax']);
                        }
                        // tells the (cached) public pages to ask for the admin bar; holds no login data itself
                        setcookie('edu_ab', '1', ['expires' => time() + 30 * 86400, 'path' => '/', 'secure' => $secure, 'samesite' => 'Lax']);
                        // stay signed in until Log out (see includes/remember.php)
                        if (!empty($_POST['rememberme']) && function_exists('edu_rm_issue')) edu_rm_issue($pdo, (int)$user['id'], (string)$user['password_hash']);
                        $log($user['id'], 'login_success', "Logged in as {$user['role']}: {$user['username']}");
                        header('Location: ' . $redirect_to);
                        exit;
                    }
                } else {
                    $_SESSION['login_attempts']++;
                    if ($_SESSION['login_attempts'] >= MAX_ATTEMPTS) {
                        $_SESSION['lockout_until'] = time() + LOCKOUT_SECS;
                        $locked = true;
                        $lock_left = LOCKOUT_SECS;
                    } else {
                        $left = MAX_ATTEMPTS - $_SESSION['login_attempts'];
                        $error = 'The username or password is incorrect. ' . $left . ' attempt' . ($left !== 1 ? 's' : '') . ' left.';
                    }
                    $log(null, 'login_failed', "Failed login for: {$login_value} (attempt {$_SESSION['login_attempts']})");
                }
            } catch (PDOException $e) {
                error_log($e->getMessage());
                $error = 'Something went wrong. Please try again in a moment.';
            }
        }
    }
}

$_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
$csrf_token = $_SESSION['csrf_token'];
$logo = (string)es('general', 'logo', '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow, noarchive">
<title>Log In ‹ <?= htmlspecialchars(edu_site_name()) ?></title>
<?php $__fav = (string)es('general', 'favicon', ''); ?><link rel="icon" href="<?= htmlspecialchars($__fav ?: '/assets/icons/favicon.ico') ?>">
<style>
*{box-sizing:border-box}
html,body{margin:0;min-height:100%}
body{background:#f0f0f1;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen-Sans,Ubuntu,Cantarell,"Helvetica Neue",sans-serif;color:#3c434a;display:flex;align-items:center;justify-content:center;padding:24px 16px;min-height:100vh}
.lg{width:100%;max-width:320px}
.lg-card{background:#fff;border:1px solid #c3c4c7;border-radius:4px;box-shadow:0 1px 3px rgba(0,0,0,.04);padding:24px 24px 26px}
.lg-logo{display:flex;justify-content:center;margin:0 0 20px}
.lg-logo img{max-height:44px;max-width:220px;width:auto;display:block}
.lg-logo span{font-size:22px;font-weight:800;color:#1d2327;letter-spacing:-.01em}
label{display:block;font-size:14px;font-weight:600;color:#1d2327;margin:0 0 6px}
.lg-in{width:100%;height:42px;border:1px solid #8c8f94;border-radius:4px;padding:0 10px;font-size:16px;color:#2c3338;background:#fff;outline:0}
.lg-in:focus{border-color:#2271b1;box-shadow:0 0 0 1px #2271b1}
.lg-row{margin:0 0 16px}
.lg-pw{position:relative}.lg-pw .lg-in{padding-right:42px}
.lg-eye{position:absolute;right:4px;top:4px;width:34px;height:34px;border:0;background:none;color:#50575e;cursor:pointer;display:grid;place-items:center;border-radius:4px}
.lg-eye:hover{color:#2271b1}.lg-eye svg{width:20px;height:20px}
.lg-rem{display:flex;align-items:center;gap:8px;font-weight:400;color:#3c434a;margin:0 0 20px;cursor:pointer}
.lg-rem input{width:16px;height:16px;accent-color:#2271b1;margin:0}
.lg-btn{width:100%;height:42px;border:0;border-radius:4px;background:#2271b1;color:#fff;font-size:15px;font-weight:600;cursor:pointer}
.lg-btn:hover{background:#135e96}.lg-btn:disabled{opacity:.6;cursor:not-allowed}
.lg-err{border-left:4px solid #d63638;background:#fff;box-shadow:0 1px 1px rgba(0,0,0,.04);padding:12px;margin:0 0 16px;font-size:13.5px;line-height:1.5;color:#1d2327}
.lg-back{text-align:center;margin:22px 0 0;font-size:13px}.lg-back a{color:#50575e;text-decoration:none}.lg-back a:hover{color:#2271b1}
</style>
</head>
<body>
<main class="lg">
  <div class="lg-card">
    <div class="lg-logo"><?php if ($logo !== ''): ?><img src="<?= htmlspecialchars($logo) ?>" alt="<?= htmlspecialchars(edu_site_name()) ?>"><?php else: ?><span><?= htmlspecialchars(edu_site_name()) ?></span><?php endif; ?></div>
    <?php if ($locked): ?>
    <div class="lg-err" role="alert"><strong>Too many failed attempts.</strong> Please try again in <span id="lg-left"><?= (int)ceil($lock_left / 60) ?></span> minute<?= ceil($lock_left / 60) == 1 ? '' : 's' ?>.</div>
    <?php elseif ($error !== ''): ?>
    <div class="lg-err" role="alert"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <form method="post" action="/admin-login" autocomplete="on" onsubmit="this.querySelector('button[type=submit]').disabled=true">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
      <input type="hidden" name="redirect_to" value="<?= htmlspecialchars($redirect_to) ?>">
      <div class="lg-row">
        <label for="user_login">Username or Email Address</label>
        <input class="lg-in" type="text" name="log" id="user_login" value="<?= htmlspecialchars($login_value) ?>" autocomplete="username" autocapitalize="off" spellcheck="false" required <?= $locked ? 'disabled' : 'autofocus' ?>>
      </div>
      <div class="lg-row">
        <label for="user_pass">Password</label>
        <div class="lg-pw">
          <input class="lg-in" type="password" name="pwd" id="user_pass" autocomplete="current-password" required <?= $locked ? 'disabled' : '' ?>>
          <button type="button" class="lg-eye" aria-label="Show password" onclick="var i=document.getElementById('user_pass'),s=i.type==='password';i.type=s?'text':'password';this.setAttribute('aria-label',s?'Hide password':'Show password')"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 5C6.5 5 2.7 9.1 1.5 12c1.2 2.9 5 7 10.5 7s9.3-4.1 10.5-7C21.3 9.1 17.5 5 12 5Zm0 11.5a4.5 4.5 0 1 1 0-9 4.5 4.5 0 0 1 0 9Zm0-2.5a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z"/></svg></button>
        </div>
      </div>
      <label class="lg-rem"><input type="checkbox" name="rememberme" value="1" checked> Remember Me</label>
      <button class="lg-btn" type="submit" <?= $locked ? 'disabled' : '' ?>>Log In</button>
    </form>
  </div>
  <p class="lg-back"><a href="/">&larr; Back to <?= htmlspecialchars(edu_site_name()) ?></a></p>
</main>
</body>
</html>
