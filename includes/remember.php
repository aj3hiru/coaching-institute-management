<?php
// "Stay signed in": the server deletes idle sessions after 24 minutes, so a signed-in admin would be logged out.
// At sign-in a random token is given to the browser (only its hash is stored); when the session is gone the
// token signs the user in again. It ends on Log out, or when the password changes.

const EDU_RM_COOKIE = 'edu_rm';
const EDU_RM_DAYS = 365;

function edu_rm_table(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo->exec("CREATE TABLE IF NOT EXISTS edu_login_tokens (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        selector CHAR(24) NOT NULL,
        validator_hash CHAR(64) NOT NULL,
        user_id INT NOT NULL,
        pw_sig CHAR(16) NOT NULL,
        user_agent VARCHAR(255) NOT NULL DEFAULT '',
        expires_at DATETIME NOT NULL,
        last_used DATETIME NULL,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_selector (selector),
        KEY idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function edu_rm_cookie(string $value, int $expires): void
{
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    if (!headers_sent()) setcookie(EDU_RM_COOKIE, $value, ['expires' => $expires, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
}

/** After a successful sign-in: give this browser a sign-in token. */
function edu_rm_issue(PDO $pdo, int $userId, string $passwordHash): void
{
    try {
        edu_rm_table($pdo);
        $sel = bin2hex(random_bytes(12));
        $val = bin2hex(random_bytes(32));
        $exp = time() + EDU_RM_DAYS * 86400;
        $pdo->prepare("INSERT INTO edu_login_tokens (selector, validator_hash, user_id, pw_sig, user_agent, expires_at) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$sel, hash('sha256', $val), $userId, substr(hash('sha256', $passwordHash), 0, 16), mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255), date('Y-m-d H:i:s', $exp)]);
        $pdo->exec("DELETE FROM edu_login_tokens WHERE expires_at < NOW()");
        edu_rm_cookie($sel . ':' . $val, $exp);
    } catch (Throwable $e) {
        error_log('remember issue: ' . $e->getMessage());
    }
}

/** No signed-in session but a token: sign the user in again (same session values as the login page sets). */
function edu_rm_restore(PDO $pdo): void
{
    if (!empty($_SESSION['user_id']) || empty($_COOKIE[EDU_RM_COOKIE]) || session_status() !== PHP_SESSION_ACTIVE) return;
    [$sel, $val] = array_pad(explode(':', (string)$_COOKIE[EDU_RM_COOKIE], 2), 2, '');
    if (!preg_match('/^[a-f0-9]{24}$/', $sel) || !preg_match('/^[a-f0-9]{64}$/', $val)) { edu_rm_cookie('', time() - 3600); return; }
    try {
        edu_rm_table($pdo);
        $st = $pdo->prepare("SELECT t.id, t.validator_hash, t.pw_sig, t.expires_at, u.id AS uid, u.username, u.email, u.role, u.status, u.password_hash, u.permissions
            FROM edu_login_tokens t JOIN users u ON u.id = t.user_id WHERE t.selector = ? LIMIT 1");
        $st->execute([$sel]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        $perm = $r ? (json_decode((string)($r['permissions'] ?? '{}'), true) ?: []) : [];
        $ok = $r && hash_equals($r['validator_hash'], hash('sha256', $val)) && strtotime($r['expires_at']) > time()
            && hash_equals($r['pw_sig'], substr(hash('sha256', (string)$r['password_hash']), 0, 16))
            && $r['status'] === 'active' && in_array($r['role'], ['admin', 'editor', 'author'], true)
            && ($r['role'] === 'admin' || !empty($perm['dashboard_access']));
        if (!$ok) {
            if ($r) $pdo->prepare("DELETE FROM edu_login_tokens WHERE id = ?")->execute([$r['id']]);
            edu_rm_cookie('', time() - 3600);
            return;
        }
        session_regenerate_id(true);
        $_SESSION['user_id'] = $r['uid'];
        $_SESSION['username'] = $r['username'];
        $_SESSION['email'] = $r['email'];
        $_SESSION['role'] = $r['role'];
        $_SESSION['login_time'] = time();
        $exp = time() + EDU_RM_DAYS * 86400;
        $pdo->prepare("UPDATE edu_login_tokens SET last_used = NOW(), expires_at = ? WHERE id = ?")->execute([date('Y-m-d H:i:s', $exp), $r['id']]);
        edu_rm_cookie($sel . ':' . $val, $exp);
        if (!headers_sent()) setcookie('edu_ab', '1', ['expires' => time() + 30 * 86400, 'path' => '/', 'samesite' => 'Lax']); // admin bar on the site
    } catch (Throwable $e) {
        error_log('remember restore: ' . $e->getMessage());
    }
}

/** Log out: this browser's token is deleted. */
function edu_rm_forget(PDO $pdo): void
{
    $sel = explode(':', (string)($_COOKIE[EDU_RM_COOKIE] ?? ''), 2)[0];
    if (preg_match('/^[a-f0-9]{24}$/', $sel)) {
        try { edu_rm_table($pdo); $pdo->prepare("DELETE FROM edu_login_tokens WHERE selector = ?")->execute([$sel]); } catch (Throwable $e) {}
    }
    edu_rm_cookie('', time() - 3600);
}
