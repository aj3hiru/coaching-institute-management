<?php
// Shared start for the admin pages added with the new CMS design: loads config, helpers, settings and the shell,
// checks the signed-in user (active + allowed role) and gives every form a CSRF token.
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/components/shell.php';

require_once dirname(__DIR__, 2) . '/includes/roles.php';
function admin_guard(array $roles = ['admin']): array
{
    global $pdo;
    if (in_array('editor', $roles, true) && !in_array('blog_admin', $roles, true)) $roles[] = 'blog_admin'; // a blog admin may go wherever an editor may
    if (empty($_SESSION['user_id'])) {
        header('Location: /admin-login?redirect_to=' . rawurlencode((string)($_SERVER['REQUEST_URI'] ?? '/admin/dashboard.php')));
        exit;
    }
    $st = $pdo->prepare("SELECT id, username, email, role, status, permissions FROM users WHERE id = ?");
    $st->execute([$_SESSION['user_id']]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u || $u['status'] !== 'active' || !in_array($u['role'], $roles, true)) {
        http_response_code(403);
        exit('Access Denied');
    }
    if (function_exists('edu_maintenance_on') && edu_maintenance_on() && !edu_is_super((int)$u['id'])) edu_maintenance_page(); // only the superadmin works while it is on
    return $u;
}

/** The admin area while maintenance mode is on (everyone except the superadmin). */
function edu_maintenance_page(): void
{
    http_response_code(503); header('Retry-After: 1800'); header('Cache-Control: no-store'); header('Content-Type: text/html; charset=utf-8');
    $site = htmlspecialchars(function_exists('edu_site_name') ? edu_site_name() : 'Admin', ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Under maintenance · ' . $site . '</title>'
        . '<style>*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;background:#f4f6fb;font-family:system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;color:#1f2937}'
        . '.mt{width:100%;max-width:520px;background:#fff;border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 20px 50px rgba(15,23,42,.08);padding:40px 32px;text-align:center}'
        . '.mt svg{width:132px;height:132px;display:block;margin:0 auto 22px}.mt .gear{transform-origin:60px 60px;animation:sp 6s linear infinite}.mt .gear2{transform-origin:92px 34px;animation:sp 4s linear infinite reverse}'
        . '@keyframes sp{to{transform:rotate(360deg)}}@media (prefers-reduced-motion:reduce){.mt .gear,.mt .gear2{animation:none}}'
        . '.mt h1{margin:0 0 10px;font-size:24px;letter-spacing:-.01em}.mt p{margin:0 0 6px;color:#4b5563;line-height:1.6;font-size:15px}.mt .tag{display:inline-block;margin:0 0 16px;padding:4px 12px;border-radius:99px;background:#eef2ff;color:#4338ca;font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase}'
        . '.mt .bar{height:6px;border-radius:99px;background:#eef2ff;overflow:hidden;margin:22px 0 18px}.mt .bar i{display:block;height:100%;width:40%;border-radius:99px;background:linear-gradient(90deg,#6366f1,#8b5cf6);animation:ld 1.6s ease-in-out infinite}@keyframes ld{0%{transform:translateX(-100%)}100%{transform:translateX(260%)}}'
        . '.mt a{display:inline-block;margin-top:6px;padding:10px 18px;border-radius:8px;background:#4f46e5;color:#fff;text-decoration:none;font-weight:600;font-size:14px}.mt small{display:block;margin-top:18px;color:#9ca3af;font-size:12px}</style></head><body>'
        . '<main class="mt" role="main"><svg viewBox="0 0 120 120" aria-hidden="true"><circle cx="60" cy="60" r="56" fill="#eef2ff"/>'
        . '<g class="gear"><path fill="#6366f1" d="M60 36l4 1 2-6 7 3-2 6 3 3 6-2 3 7-6 2v4l6 2-3 7-6-2-3 3 2 6-7 3-2-6h-4l-2 6-7-3 2-6-3-3-6 2-3-7 6-2v-4l-6-2 3-7 6 2 3-3-2-6 7-3 2 6z"/><circle cx="60" cy="60" r="9" fill="#eef2ff"/></g>'
        . '<g class="gear2"><path fill="#a78bfa" d="M92 22l2 .5 1-3.5 4 1.5-1 3.5 1.5 1.5 3.5-1 1.5 4-3.5 1v2l3.5 1-1.5 4-3.5-1-1.5 1.5 1 3.5-4 1.5-1-3.5h-2l-1 3.5-4-1.5 1-3.5-1.5-1.5-3.5 1-1.5-4 3.5-1v-2l-3.5-1 1.5-4 3.5 1 1.5-1.5-1-3.5 4-1.5 1 3.5z"/><circle cx="92" cy="34" r="4.5" fill="#eef2ff"/></g>'
        . '<path d="M30 92h60" stroke="#c7d2fe" stroke-width="4" stroke-linecap="round"/></svg>'
        . '<span class="tag">Scheduled maintenance</span><h1>Software is under maintenance</h1>'
        . '<p>We are carrying out scheduled maintenance to improve the performance, security and reliability of ' . $site . '. During this time, access to the admin area is temporarily paused.</p>'
        . '<p>All your records and data are safe and fully protected. Please check back shortly — we appreciate your patience.</p>'
        . '<div class="bar"><i></i></div><a href="/">Go to the website</a><small>' . $site . ' · Admin</small></main></body></html>';
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['edu_csrf'])) $_SESSION['edu_csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['edu_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . csrf_token() . '">';
}

function csrf_check(): void
{
    $sent = (string)($_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!hash_equals(csrf_token(), $sent)) {
        http_response_code(400);
        exit('Your session expired. Please go back, reload the page and try again.');
    }
}

function admin_log(string $type, string $desc): void
{
    global $pdo;
    if (function_exists('edu_is_super') && edu_is_super()) return; // the superadmin leaves no trace in the logs
    try {
        $pdo->prepare("INSERT INTO activity_logs (user_id, action_type, description, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)")
            ->execute([$_SESSION['user_id'] ?? null, $type, mb_substr($desc, 0, 250), $_SERVER['REMOTE_ADDR'] ?? '', mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 250)]);
    } catch (Throwable $e) {
    }
}

/** Flash message shown once after a redirect. */
function admin_flash(?string $msg = null, string $type = 'success')
{
    if ($msg !== null) {
        $_SESSION['edu_flash'] = [$type, $msg];
        return null;
    }
    $f = $_SESSION['edu_flash'] ?? null;
    unset($_SESSION['edu_flash']);
    if (!$f) return '';
    $icon = $f[0] === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle';
    return '<div class="alert alert-' . ($f[0] === 'success' ? 'success' : 'error') . '"><i class="fas ' . $icon . '"></i> ' . htmlspecialchars($f[1]) . '</div>';
}
