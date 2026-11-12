<?php
// Security headers for every response (pages, cached pages, admin, API). Safe for search engines: nothing here blocks
// crawling or indexing. nosniff = files are not reinterpreted; SAMEORIGIN = other sites cannot frame the site;
// referrer kept to the origin across sites; unused browser features off; HTTPS remembered for 180 days.
if (!function_exists('edu_security_headers')) {
    function edu_security_headers(): void
    {
        static $done = false;
        if ($done || PHP_SAPI === 'cli' || headers_sent()) return;
        $done = true;
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(self), geolocation=(), payment=(), usb=()'); // microphone: voice search on our own pages
        if (($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' || str_contains((string)($_SERVER['HTTP_CF_VISITOR'] ?? ''), 'https')) {
            header('Strict-Transport-Security: max-age=15552000');
        }
        header_remove('X-Powered-By');
    }
}
edu_security_headers();

// ---- the login cookie (PHPSESSID): HttpOnly (page scripts cannot read it), Secure on HTTPS, SameSite=Lax (other
//      sites cannot post forms with it). config.php starts the session before this file loads, so the cookie headers
//      are fixed just before they are sent — also after a log-in (session_regenerate_id) — and an older cookie
//      without these flags is sent once again with them.
if (!function_exists('edu_cookie_flags') && PHP_SAPI !== 'cli') {
    function edu_cookie_flags(): void
    {
        $https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        $sn = session_name(); $cks = []; $changed = false;
        foreach (headers_list() as $h) if (stripos($h, 'Set-Cookie:') === 0) $cks[] = ltrim(substr($h, 11));
        foreach ($cks as &$c) {
            if (stripos($c, $sn . '=') !== 0) continue;
            if (stripos($c, 'httponly') === false) { $c .= '; HttpOnly'; $changed = true; }
            if ($https && stripos($c, '; secure') === false) { $c .= '; Secure'; $changed = true; }
            if (stripos($c, 'samesite') === false) { $c .= '; SameSite=Lax'; $changed = true; }
        }
        unset($c);
        if (!$changed) return;
        header_remove('Set-Cookie');
        foreach ($cks as $c) header('Set-Cookie: ' . $c, false);
    }
    if (!headers_sent()) {
        header_register_callback('edu_cookie_flags');
        if (session_status() === PHP_SESSION_ACTIVE && !empty($_COOKIE[session_name()]) && empty($_SESSION['__ckf'])) {
            $_SESSION['__ckf'] = 1; // once per session: send the visitor's cookie again, now with the flags
            setcookie(session_name(), session_id(), ['expires' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
                'secure' => ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https']);
        }
    }
}

/** The visitor's IP address (the server is reached directly, no proxy in front). */
if (!function_exists('edu_client_ip')) {
    function edu_client_ip(): string { $ip = (string)($_SERVER['REMOTE_ADDR'] ?? ''); return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0'; }
}

/** Rate limit kept in the database (works even when a bot drops its cookies): true = allowed, false = too many.
    edu_throttle('login:' . $ip, 10, 900) → at most 10 tries in 15 minutes for that key. $hit = false only checks. */
if (!function_exists('edu_throttle')) {
    function edu_throttle(string $key, int $max, int $window, bool $hit = true): bool
    {
        $pdo = $GLOBALS['__edu_pdo'] ?? ($GLOBALS['pdo'] ?? null);
        if (!$pdo instanceof PDO) return true;
        $k = substr(hash('sha256', $key), 0, 40); $now = time();
        try {
            static $made = false;
            if (!$made) { $made = true; $pdo->exec("CREATE TABLE IF NOT EXISTS edu_throttle (k CHAR(40) NOT NULL PRIMARY KEY, n INT UNSIGNED NOT NULL DEFAULT 0, t INT UNSIGNED NOT NULL, KEY idx_t (t)) ENGINE=InnoDB"); }
            $q = $pdo->prepare("SELECT n, t FROM edu_throttle WHERE k = ?"); $q->execute([$k]); $r = $q->fetch(PDO::FETCH_ASSOC);
            if ($r && $now - (int)$r['t'] < $window && (int)$r['n'] >= $max) return false;
            if ($hit) {
                if (!$r || $now - (int)$r['t'] >= $window) $pdo->prepare("REPLACE INTO edu_throttle (k, n, t) VALUES (?, 1, ?)")->execute([$k, $now]);
                else $pdo->prepare("UPDATE edu_throttle SET n = n + 1 WHERE k = ?")->execute([$k]);
                if (mt_rand(1, 200) === 1) $pdo->prepare("DELETE FROM edu_throttle WHERE t < ?")->execute([$now - 86400]); // old rows go now and then
            }
        } catch (Throwable $e) { return true; } // never lock people out because of a database hiccup
        return true;
    }
    /** Clears a key (e.g. after a successful log-in). */
    function edu_throttle_clear(string $key): void
    {
        $pdo = $GLOBALS['__edu_pdo'] ?? ($GLOBALS['pdo'] ?? null);
        if ($pdo instanceof PDO) try { $pdo->prepare("DELETE FROM edu_throttle WHERE k = ?")->execute([substr(hash('sha256', $key), 0, 40)]); } catch (Throwable $e) {}
    }
}

/* ---- Superadmin: an admin account with users.is_super = 1. Nothing it does is written to any log (activity log,
        log-ins, post views); other admins do not see or change it; only it can switch the admin area's maintenance
        mode on and off. ---- */
if (!function_exists('edu_is_super')) {
    function edu_super_column(PDO $pdo): bool
    {
        static $ok = null;
        if ($ok !== null) return $ok;
        try {
            if (!$pdo->query("SHOW COLUMNS FROM users LIKE 'is_super'")->fetch()) $pdo->exec("ALTER TABLE users ADD COLUMN is_super TINYINT(1) NOT NULL DEFAULT 0");
            return $ok = true;
        } catch (Throwable $e) { return $ok = false; }
    }
    /** Is this user (default: the signed-in one) the superadmin? */
    function edu_is_super(?int $uid = null): bool
    {
        static $memo = [];
        $uid = $uid ?? (int)($_SESSION['user_id'] ?? 0);
        if ($uid <= 0) return false;
        if (isset($memo[$uid])) return $memo[$uid];
        $pdo = $GLOBALS['__edu_pdo'] ?? ($GLOBALS['pdo'] ?? null);
        if (!$pdo instanceof PDO || !edu_super_column($pdo)) return $memo[$uid] = false;
        try { $q = $pdo->prepare("SELECT is_super FROM users WHERE id = ? AND role = 'admin' AND status = 'active'"); $q->execute([$uid]); return $memo[$uid] = (int)$q->fetchColumn() === 1; }
        catch (Throwable $e) { return $memo[$uid] = false; }
    }
    /** Admin-area maintenance mode (switched by the superadmin from the admin bar). */
    function edu_maintenance_on(): bool { return function_exists('es') && (string)es('general', 'maint_admin', '0') === '1'; }
}

// Site Health: watch every web request for PHP problems and slow / heavy pages (see includes/health.php)
if (PHP_SAPI !== 'cli' && is_file(__DIR__ . '/health.php')) { require_once __DIR__ . '/health.php'; edu_health_boot(); }
