<?php
// The sign-in page moved to /admin-login (same look as the CMS). Old links and bookmarks still work.
$to = (string)($_GET['redirect_to'] ?? '');
header('Cache-Control: no-store');
header('Location: /admin-login' . ($to !== '' ? '?redirect_to=' . rawurlencode($to) : ''), true, 301);
exit;
