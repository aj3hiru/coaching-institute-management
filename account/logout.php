<?php
// Student log out. POST from the account pages (with the form token), or a plain link from the site's own menu
// (cached pages can't carry a token — the browser's Sec-Fetch-Site / Referer must show it came from this site).
require_once dirname(__DIR__) . '/includes/config.php';
require_once INCLUDES_PATH . '/shop.php';
$post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && sh_csrf_ok();
$site = (string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '');
$ref = (string)parse_url((string)($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_HOST);
$same = $site === 'same-origin' || ($site === '' && $ref !== '' && strcasecmp($ref, (string)($_SERVER['HTTP_HOST'] ?? '')) === 0);
if ($post || (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && $same)) {
    unset($_SESSION['sh_uid']);
    session_regenerate_id(true);
    sh_mark_cookie(null);
}
header('Location: /store/');
