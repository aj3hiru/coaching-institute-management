<?php
// Buyer log out (POST from the account page; a plain visit just goes back).
require_once dirname(__DIR__) . '/includes/config.php';
require_once INCLUDES_PATH . '/shop.php';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && sh_csrf_ok()) { unset($_SESSION['sh_uid']); session_regenerate_id(true); sh_mark_cookie(null); }
header('Location: /store');
