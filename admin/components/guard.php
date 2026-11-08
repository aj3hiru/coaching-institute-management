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
    return $u;
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
