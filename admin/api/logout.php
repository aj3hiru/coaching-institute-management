<?php
define('DROOT_PATH', $_SERVER['DOCUMENT_ROOT']);
require_once DROOT_PATH . '/includes/config.php';

if (isset($_SESSION['user_id'])) {
    try {
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN';

        $log_sql = "INSERT INTO activity_logs 
            (user_id, action_type, description, ip_address, user_agent) 
            VALUES (?, ?, ?, ?, ?)";

        if (!(function_exists('edu_is_super') && edu_is_super())) $pdo->prepare($log_sql)->execute([ // no log for the superadmin
            $_SESSION['user_id'],
            'logout',
            "User {$_SESSION['username']} logged out successfully",
            $ip_address,
            $user_agent
        ]);
    } catch (PDOException $e) {
        error_log($e->getMessage());
    }
}

if (function_exists('edu_rm_forget')) edu_rm_forget($pdo); // this browser no longer stays signed in
$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();

    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

session_destroy();
setcookie('edu_ab', '', ['expires' => time() - 3600, 'path' => '/']); // no admin bar on the site any more

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Location: /admin-login");
exit;
?>