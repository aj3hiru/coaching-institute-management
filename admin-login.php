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
if (isset($_SESSION['user_id'], $_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'blog_admin', 'editor', 'author', 'teacher', 'accountant'], true)) {
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
                    WHERE (username = ? OR email = ?) AND role IN ('admin','blog_admin','editor','author','teacher','accountant') LIMIT 1");
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
<?php require_once INCLUDES_PATH . '/theme.php'; echo edu_theme_head(); $__brandBg = !function_exists('th_on') || th_on('id_brand_bg'); ?>
<?php $__fav = (string)es('general', 'favicon', ''); ?><link rel="icon" href="<?= htmlspecialchars($__fav ?: '/assets/icons/favicon.ico') ?>">
<style>
/* same card as the site's Login page: brand badge on the top border, field names inside the fields, theme accent colour */
*{box-sizing:border-box}
html,body{margin:0;min-height:100%}
body{background:#f3f4f6;font-family:Poppins,"Poppins Fallback",Arial,sans-serif;color:#1f2937;display:flex;align-items:center;justify-content:center;padding:40px 16px 24px;min-height:100vh}
.lg{width:100%;max-width:420px}
.lg-card{position:relative;background:var(--edu-card-bg,#fff);border:1px solid var(--edu-card-border,#e5e7eb);border-radius:var(--box-radius,5px);padding:0 26px 24px}
.lg-badge{display:flex;justify-content:center;margin:-28px 0 22px}
.lg-badge a{position:relative;isolation:isolate;display:inline-flex;align-items:center;gap:9px;min-height:54px;padding:4px 48px 4px 14px;text-decoration:none;color:inherit;max-width:100%}
.lg-badge a::before{content:"";position:absolute;inset:0 12px 0 0;z-index:-1;background:#f9dbc2;clip-path:polygon(0 0,100% 0,97% 45%,100% 100%,0 100%)}
.lg-badge a::after{content:"";position:absolute;bottom:0;right:7px;width:34px;height:55%;z-index:-1;background:#f02801;clip-path:polygon(50% 0,0 100%,25% 100%)}
.lg-badge.plain a{background:var(--edu-card-bg,#fff);padding:4px 10px}.lg-badge.plain a::before,.lg-badge.plain a::after{display:none}
.lg-badge img{height:46px;width:auto;max-width:90px;object-fit:contain;display:block;flex:0 0 auto}
.lg-badge span{display:flex;flex-direction:column;line-height:1.1;min-width:0}
.lg-badge h1{margin:0;font-size:20px;font-weight:700;color:#9e1b32;white-space:nowrap}
.lg-badge small{margin-top:3px;font-size:14px;font-weight:600;color:#1a1a1a;white-space:nowrap}
.lg-sr{position:absolute!important;width:1px;height:1px;margin:-1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;border:0;padding:0}
.lg-row{margin:0 0 14px}
.lg-f{position:relative;display:flex;align-items:center}
.lg-ic{position:absolute;left:13px;top:50%;transform:translateY(-50%);display:grid;color:#94a3b8;pointer-events:none}.lg-ic svg{width:18px;height:18px}
.lg-f:focus-within .lg-ic{color:var(--gc-accent,#0c6878)}
.lg-in{width:100%;height:46px;border:1px solid #d6dbe3;border-radius:var(--box-radius,5px);padding:0 44px 0 42px;font:inherit;font-size:15px;color:#0f172a;background:#fff;outline:0}
.lg-in::placeholder{color:#6b7280;opacity:1}
.lg-in:focus{border-color:var(--gc-accent,#0c6878)}
.lg-eye{position:absolute;right:6px;top:50%;transform:translateY(-50%);width:36px;height:36px;border:0;background:none;color:#94a3b8;cursor:pointer;display:grid;place-items:center;border-radius:5px}
.lg-eye:hover{background:#eef2f6;color:#334155}.lg-eye svg{width:19px;height:19px}
.lg-rem{display:flex;align-items:center;gap:8px;font-size:14px;color:#374151;margin:2px 0 18px;cursor:pointer}
.lg-rem input{width:16px;height:16px;accent-color:var(--gc-accent,#0c6878);margin:0}
.lg-btn{width:100%;height:46px;border:0;border-radius:var(--box-radius,5px);background:var(--gc-accent,#0c6878);color:#fff;font:inherit;font-size:16px;font-weight:700;cursor:pointer}
.lg-btn:hover{background:color-mix(in srgb,var(--gc-accent,#0c6878) 86%,#000)}.lg-btn:disabled{opacity:.6;cursor:not-allowed}
.lg-err{border:1px solid #fecaca;background:#fef2f2;color:#991b1b;border-radius:var(--box-radius,5px);padding:11px 12px;margin:0 0 16px;font-size:14px;line-height:1.5}
.lg-back{text-align:center;margin:18px 0 0;font-size:14px}.lg-back a{color:#4b5563;text-decoration:none}.lg-back a:hover{color:var(--gc-accent,#0c6878)}
@media (max-width:480px){.lg-card{padding:0 16px 20px}.lg-badge a{padding:4px 40px 4px 12px;gap:8px}.lg-badge img{height:42px}.lg-badge h1{font-size:18px}.lg-badge small{font-size:13px}}
</style>
</head>
<body>
<main class="lg">
  <div class="lg-card">
    <div class="lg-badge<?= $__brandBg ? '' : ' plain' ?>"><a href="/" aria-label="<?= htmlspecialchars(edu_site_name()) ?> home"><?php if ($logo !== ''): ?><img src="<?= htmlspecialchars($logo) ?>" alt="" width="52" height="60"><?php endif; ?><span><h1>Admin Login</h1><small><?= htmlspecialchars(edu_site_name()) ?></small></span></a></div>
    <?php if ($locked): ?>
    <div class="lg-err" role="alert"><strong>Too many failed attempts.</strong> Please try again in <span id="lg-left"><?= (int)ceil($lock_left / 60) ?></span> minute<?= ceil($lock_left / 60) == 1 ? '' : 's' ?>.</div>
    <?php elseif ($error !== ''): ?>
    <div class="lg-err" role="alert"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <form method="post" action="/admin-login" autocomplete="on" onsubmit="this.querySelector('button[type=submit]').disabled=true">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
      <input type="hidden" name="redirect_to" value="<?= htmlspecialchars($redirect_to) ?>">
      <div class="lg-row">
        <label class="lg-sr" for="user_login">Username or Email Address</label>
        <div class="lg-f"><span class="lg-ic" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg></span><input class="lg-in" placeholder="Username or email" type="text" name="log" id="user_login" value="<?= htmlspecialchars($login_value) ?>" autocomplete="username" autocapitalize="off" spellcheck="false" required <?= $locked ? 'disabled' : 'autofocus' ?>></div>
      </div>
      <div class="lg-row">
        <label class="lg-sr" for="user_pass">Password</label>
        <div class="lg-f lg-pw"><span class="lg-ic" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg></span>
          <input class="lg-in" placeholder="Password" type="password" name="pwd" id="user_pass" autocomplete="current-password" required <?= $locked ? 'disabled' : '' ?>>
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
