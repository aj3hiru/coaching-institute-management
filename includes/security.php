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
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        if (($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' || str_contains((string)($_SERVER['HTTP_CF_VISITOR'] ?? ''), 'https')) {
            header('Strict-Transport-Security: max-age=15552000');
        }
        header_remove('X-Powered-By');
    }
}
edu_security_headers();
